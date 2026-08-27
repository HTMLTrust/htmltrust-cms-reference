# HTMLTrust HTML Signature Protocol

This document specifies how content publishers embed cryptographic signatures into HTML so that browsers, extensions, and crawlers can discover and verify them.

## Overview

Signed content uses the `<signed-section>` custom HTML element, as defined in the [HTMLTrust specification](https://github.com/HTMLTrust/htmltrust-spec). This element wraps the signed content and carries the cryptographic signature as attributes.

## The `<signed-section>` Element

### Required Attributes

Per spec §2.1, the wrapper element carries exactly four required attributes:

| Attribute | Description | Example |
|---|---|---|
| `keyid` | Identifies the signer; resolved per the rules in **Identity and Key Resolution** below. May be a DID, a direct URL to a public key document, or a trust-directory reference. | `keyid="did:web:author.example"` |
| `signature` | Base64-encoded (unpadded) cryptographic signature over the canonical binding string defined in **Signature Data Format** | `signature="aBcDeF123..."` |
| `content-hash` | Hash of the canonicalized content, prefixed with the hash algorithm and encoded as unpadded standard Base64 | `content-hash="sha256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU"` |
| `algorithm` | Signature algorithm. Required by the spec; implementations MAY default to `ed25519` when the attribute is omitted, but producers SHOULD always emit it explicitly. | `algorithm="ed25519"` |

### Optional Attributes

There are **no** optional attributes on the `<signed-section>` wrapper itself in this revision. All claim and contextual metadata (author name, signed-at timestamp, license, content type, AI assistance, etc.) belongs in inner `<meta>` elements as documented under **Inner Metadata** below. This keeps the wrapper's attribute surface narrow and easy to validate.

Presentational attributes such as `style` and `class` SHOULD NOT be set inline on `<signed-section>`. Styling is the user agent's responsibility (see the **CSS** section at the bottom of this document); inline presentational attributes mix concerns and are unnecessary for protocol conformance.

### Supported Algorithms

| Value | Description |
|---|---|
| `ed25519` | Ed25519 (recommended; the default if the `algorithm` attribute is omitted) |
| `rsa` | RSA with SHA-256 |
| `ecdsa` | ECDSA with secp256k1 |

## Inner Metadata

The `<signed-section>` element MAY contain `<meta>` tags that describe the signature's claims and context. This makes signatures self-describing — a crawler or verifier can read the claims directly from the HTML without calling the trust directory API.

### Standard Meta Names

| Name | Description | Example |
|---|---|---|
| `author` | Display name of the content author | `<meta name="author" content="Alice Example">` |
| `signed-at` | ISO 8601 timestamp of when the content was signed | `<meta name="signed-at" content="2025-05-01T10:30:00Z">` |

### Claim Meta Names

Claims use the prefix `claim:` followed by the claim type name:

| Name | Description | Example |
|---|---|---|
| `claim:ContentType` | Type of content | `Article`, `Opinion`, `Research`, `News` |
| `claim:License` | Content license | `CC-BY-4.0`, `Public Domain`, `All Rights Reserved` |
| `claim:AIAssistance` | Level of AI involvement | `None`, `Human+AI`, `AI+Human`, `AI-only` |
| `claim:LLMTraining` | AI training preference | `Allowed`, `NotAllowed` |

Custom claim types are permitted. The claim vocabulary is extensible.

## HTML Structure

The `<signed-section>` element wraps the signed content:

```html
<signed-section
    signature="BASE64_SIG"
    keyid="https://api.example.com/authors/123/public-key"
    algorithm="ed25519"
    content-hash="sha256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU">
  <meta name="author" content="Alice Example">
  <meta name="signed-at" content="2025-05-01T10:30:00Z">
  <meta name="claim:ContentType" content="Article">
  <meta name="claim:License" content="CC-BY-4.0">
  <meta name="claim:AIAssistance" content="None">
  <article>
    <h1>Verifiable Web Content</h1>
    <p>This content is signed and verifiable.</p>
  </article>
</signed-section>
```

CMS integrations should not emit a detached `<signed-section>` containing only metadata. Compatibility UI such as badges and verification buttons should live outside the signed section.

## Identity and Key Resolution

The `keyid` attribute identifies the signer but the resolution mechanism is deliberately **pluggable** (spec §2.2). Implementations MUST accept multiple resolution methods and SHOULD treat none as canonical or privileged. Three forms are defined:

| Form | Example | How it resolves |
|---|---|---|
| **Decentralized Identifier (DID)** | `did:web:author.example` | The user agent fetches the DID document at the author's origin (`https://author.example/.well-known/did.json` for `did:web`) and extracts the public key. Places no dependency on any third party. |
| **Direct URL to a public key** | `https://author.example/key.json` | The user agent fetches the URL and parses the response as either a JSON `{ publicKey, algorithm }` object or raw PEM. Simple to host as a static file with no extra tooling. |
| **Trust directory reference** | `https://directory.example/keys/abc123` | The user agent fetches the URL from a federated trust directory acting as a convenience key registry. Useful for less-technical authors who prefer a sign-up workflow over self-hosted identity publication. |

**No resolver is privileged by the protocol.** Authors freely choose a resolution mechanism, and verifiers freely choose which methods they accept. Verifiers typically compose the three resolvers as a fallback chain in whatever order suits their threat model. The `keyid` is opaque to the signature protocol itself; only the resolved public key matters for cryptographic verification, and that verification is a local operation in the user agent that never requires contacting a directory.

User agents MAY cache resolved keys (with appropriate freshness and revocation handling) so that signature verification scales to pages with many signed sections without repeated network calls.

## Canonical Content Extraction

The hash that the signature covers is taken from the canonicalized signed region after extraction and normalization. The canonical content includes normalized text plus the signed semantic attributes `href`, `src`, `alt`, and `aria-label` when present on included elements.

### Stage 1: HTML extraction

Given the inner contents of a `<signed-section>` element:

1. **Strip excluded elements** entirely, including their text content: `<script>`, `<style>`, `<meta>`, `<link>`, `<head>`, `<noscript>`. (`<meta>` is excluded because, inside a signed-section, it carries claim metadata rather than signed content. Claim metadata is hashed separately into the `claims-hash` field.)
2. **Emit a line feed after every boundary-producing element** (`<p>`, `<div>`, `<article>`, `<section>`, `<h1>`-`<h6>`, `<li>`, `<ul>`, `<ol>`, `<table>`, `<tr>`, `<td>`, `<th>`, `<header>`, `<footer>`, `<nav>`, `<main>`, `<aside>`, etc.) so that `<p>A</p><p>B</p>` extracts to `A\nB` and not `AB`. The `br` element emits a line feed at its position. Inline elements (`<em>`, `<strong>`, `<a>`, `<span>`, etc.) do **not** introduce separators.
3. **Include signed semantic attributes** on included elements in this order: `href`, `src`, `alt`, `aria-label`. `href` and `src` are resolved against the signed document base URL and serialized as URLs; `alt` and `aria-label` use text normalization.
4. **Strip all remaining markup**, preserving text content and the signed attribute records.
5. **Decode HTML entities** (`&amp;`, `&lt;`, `&gt;`, named entities, numeric `&#nnn;` and `&#xhhhh;` entities).
6. Pass text and claim values to text normalization.

### Stage 2: Text normalization

The HTMLTrust canonicalization library applies, in order:

1. **Unicode NFKC** normalization (handles ligatures, fullwidth/halfwidth, presentation forms, superscripts, CJK compatibility, Jamo composition).
2. **Strip invisible/formatting characters** (soft hyphen, ZWSP, BOM, bidi controls, variation selectors, Arabic tatweel, etc.). ZWNJ and ZWJ are deliberately **preserved** because they are semantic in Persian, Indic, and emoji.
3. **Collapse all Unicode whitespace** to a single ASCII space; collapse runs of spaces.
4. **Normalize quotation marks**: curly singles → `'`, curly doubles → `"`, guillemets → `"`, CJK corner brackets → `"`.
5. **Normalize dashes** (en dash, em dash, minus sign, etc.) → ASCII hyphen-minus `-`.
6. **Normalize ellipsis** `…` → three ASCII periods `...`.

The output is a UTF-8 string. Hashing produces `sha256:<base64>` where `<base64>` is the unpadded Base64 encoding of the 32-byte SHA-256 digest.

**What is NOT covered by the hash.** Full markup, element types, layout attributes, classes, inline styles, and most ARIA attributes are not part of the canonical content. The current signed attribute set is deliberately small and covers `href`, `src`, `alt`, and `aria-label`.

The reference implementation lives in the `@htmltrust/canonicalization` library, with byte-identical bindings for JavaScript, Go, PHP, Python, and Rust.

### Signed-content scope

The canonicalization hashes normalized text plus the small signed semantic attribute set, not the full HTML structure. This means an adversary with possession of signed content MAY:

- Rewrap the text in misleading block elements (e.g., change an `<h1>` to a `<del>` strikethrough)
- Introduce, remove, or swap images and other media around the signed text

These are **semantic integrity concerns**, not cryptographic ones. HTMLTrust addresses them through a layered design:

1. **Domain binding** (see Signature Data Format below): signatures bind the content to a specific publication origin. A reader or crawler encountering signed content at an unexpected origin is alerted by signature check failure.
2. **Research and reputation path**: crawlers and researchers can trace signed content back to its canonical publication origin through the trust directory, flag imposter copies, and mark manipulated surrounding context. Over time the directory's reputation and reports surface altered copies to any consumer whose trust policy considers them.

The layered design keeps cryptographic verification simple and portable across language implementations, while delegating semantic-integrity detection to the research ecosystem where it can evolve without breaking existing signatures.

Future revisions MAY extend the signed attribute list. Verifiers for this revision use exactly `href`, `src`, `alt`, and `aria-label`.

## Signature Data Format

The signature binds four values, concatenated with `:` separators:

```
{content-hash}:{claims-hash}:{domain}:{signed-at}
```

- `content-hash` — hash of the canonicalized text content (see above)
- `claims-hash` — SHA-256 hash of the canonical serialization of all inner `<meta>` claim elements, ordered lexically by name (ensures tamper-evident claim metadata)
- `domain` — the serialized Web origin where the content is authoritatively published, using the legacy field name retained by the protocol
- `signed-at` — the ISO-8601 timestamp from the `<meta name="signed-at">` element

For example:
```
sha256:RAyBCvKT...:sha256:eFgHiJkL...:https://example.com:2025-05-01T10:30:00Z
```

The author's identity is **not** included in the binding because it is implicit in the keyid resolution step: any attempt to claim a signature under a different identity would resolve to a different public key and fail verification. This string is signed with the author's private key using the algorithm declared in the `algorithm` attribute.

Hashes and signatures are encoded as canonical unpadded standard Base64. This is not hex and not base64url.

## Verification Flow

HTMLTrust separates verification into two distinct layers, per the specification:

### Layer 1: Cryptographic verification (local, deterministic)

A verifying client (browser extension, crawler, library) performs these steps **locally**, with no network calls beyond the key resolution step:

1. **Discover** `<signed-section>` elements in the page DOM
2. **Read** the `signature`, `keyid`, `algorithm`, and `content-hash` attributes
3. **Resolve** the `keyid` to a public key. The `keyid` may be a DID (e.g., `did:web:author.example`), a direct URL to a public key JSON document, or a trust directory reference. Implementations MUST accept multiple resolution methods.
4. **Canonicalize** the inner text content per the rules above and compute its hash
5. **Compare** the computed hash with the `content-hash` attribute (content integrity check)
6. **Compute** the `claims-hash` from the canonical serialization of inner `<meta>` claim elements
7. **Construct** the binding string `{content-hash}:{claims-hash}:{domain}:{signed-at}`
8. **Verify** the cryptographic signature over the binding string using the resolved public key and the declared `algorithm`

This layer produces a deterministic yes/no result: either the signature is cryptographically valid or it is not. No server or directory is required for this step beyond whatever key resolution demands.

### Layer 2: Trust decision (client policy)

Given a cryptographically valid signature, the client then applies the **user's trust policy** to decide how to present the content. This layer is entirely client-side and may draw on:

- A personal list of trusted keyids (option A)
- Trusted origin domains (option B)
- Endorsements from designated third parties (fetched from trust directories and independently verified)
- Reputation scores from one or more user-selected trust directories
- Local or cached revocation state
- Any combination of the above, weighted as the user configures

The output is a trust score or ranking, **not** a binary verdict. User interfaces SHOULD present the outcome as a graduated signal (for example a red/yellow/green score) with hover or detail views exposing which inputs contributed to the final score.

### Optional directory queries

In addition to the two layers above, a client MAY query one or more trust directories for:

- Author reputation (signer-level trust, ongoing curatorial opinion)
- Content endorsements (point-in-time attestations from third parties)
- Key revocation and reports

These queries enrich the trust decision but are never required for signature verification itself.

## Multiple Signatures

A single page MAY contain multiple `<signed-section>` elements (e.g., a forum with posts from different authors). Each is verified independently.

## Backwards Compatibility

`<signed-section>` is a custom HTML element. Browsers that don't recognize it treat it as an unknown inline element and render its children normally. Adding `signed-section { display: block; }` in CSS ensures consistent block-level rendering. The content remains fully readable and functional.

## CSS

Implementations should include:

```css
signed-section {
  display: block;
}
```
