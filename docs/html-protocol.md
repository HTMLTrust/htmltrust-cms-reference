# HTMLTrust HTML Signature Protocol

This document specifies how content publishers embed cryptographic signatures into HTML so that browsers, extensions, and crawlers can discover and verify them.

## Overview

Signed content uses the `<signed-section>` custom HTML element, as defined in the [HTMLTrust specification](https://github.com/ArcadeLabsInc/htmltrust-spec). This element wraps or accompanies signed content and carries the cryptographic signature as attributes.

## The `<signed-section>` Element

### Required Attributes

| Attribute | Description | Example |
|---|---|---|
| `signature` | Base64-encoded cryptographic signature of the content hash + domain + author ID | `signature="aBcDeF123..."` |
| `keyid` | URL where the author's public key can be fetched, or a DID | `keyid="https://api.example.com/authors/123/public-key"` |
| `algorithm` | Cryptographic algorithm used for the signature | `algorithm="ed25519"` |
| `content-hash` | Hash of the canonicalized content, prefixed with the algorithm | `content-hash="sha256:abc123def456..."` |

### Supported Algorithms

| Value | Description |
|---|---|
| `ed25519` | Ed25519 (recommended) |
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

The `<signed-section>` element can either **wrap** the signed content:

```html
<signed-section
    signature="BASE64_SIG"
    keyid="https://api.example.com/authors/123/public-key"
    algorithm="ed25519"
    content-hash="sha256:abc123def456...">
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

Or appear as a **standalone marker** alongside content (e.g., when added by a CMS after the content):

```html
<article>
  <h1>Verifiable Web Content</h1>
  <p>This content is signed and verifiable.</p>
</article>
<signed-section
    signature="BASE64_SIG"
    keyid="https://api.example.com/authors/123/public-key"
    algorithm="ed25519"
    content-hash="sha256:abc123def456...">
  <meta name="author" content="Alice Example">
  <meta name="signed-at" content="2025-05-01T10:30:00Z">
  <meta name="claim:ContentType" content="Article">
  <meta name="claim:License" content="CC-BY-4.0">
</signed-section>
```

Both forms are valid. Verifying clients should handle either case.

## Content Canonicalization

Before hashing, content MUST be canonicalized:

1. Strip all HTML tags (extract text content only)
2. Collapse all whitespace sequences to a single space
3. Trim leading and trailing whitespace
4. Encode as UTF-8

The resulting string is hashed with SHA-256 and prefixed: `sha256:<hex_digest>`.

## Signature Data Format

The signature binds three values, concatenated with `:` separators:

```
{contentHash}:{domain}:{authorId}
```

For example:
```
sha256:a591a6d40bf420404a...146e:example.com:123e4567-e89b-12d3-a456-426614174000
```

This string is signed with the author's private key using the specified algorithm.

## Verification Flow

A verifying client (browser extension, crawler, etc.) performs these steps:

1. **Discover** `<signed-section>` elements in the page DOM
2. **Read** the `signature`, `keyid`, `algorithm`, and `content-hash` attributes
3. **Fetch** the author's public key from the URL in `keyid`
4. **Canonicalize** the adjacent or wrapped content and compute its SHA-256 hash
5. **Compare** the computed hash with `content-hash` (integrity check)
6. **Verify** the cryptographic signature against the public key (authenticity check)
7. **Optionally** query a trust directory for the author's reputation and endorsements

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
