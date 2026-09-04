# Local Signing Audit

**Status:** browser-local v1 implementation and remaining migration plan
**Spec basis:** htmltrust spec, amended 2026-04-10 (§2.2 Identity and Key Resolution; §3.1 Browser Behavior)
**Author:** triggered by P0 cleanup pass. The phase 2 browser-local slice is implemented; rollout and future hardware work remain decisions.

## TL;DR

The WordPress plugin now signs in the author's browser. PHP filters and
canonicalizes the post, builds the frozen v1 RFC 8785 payload, and returns its
exact UTF-8 bytes. The browser signs those bytes with a non-extractable
Ed25519 key in IndexedDB. PHP rebuilds the payload, verifies the signature, and
stores resolver-compatible public-key metadata.

The trust directory is optional for this flow. Scheduled, headless, REST,
XML-RPC, and mobile publication paths remain unsigned and create a durable
queue entry for the author to sign later in the editor.

Create an author profile with **Browser-local only** to use this flow. The
profile uses `server_id=0`, defaults its identity to `local-wp-user-{ID}`, and
stores no API key. Local profiles are excluded from site endorsement settings.

## Current implementation

The browser flow is implemented in `class-content-signing-signing-service.php`,
the post meta box JavaScript, and the local key REST endpoint:

1. PHP applies the content filters and computes content and claims hashes.
2. PHP builds the v1 payload with profile, algorithm, key ID, URL scope,
   derived location, hashes, and timestamp.
3. The browser signs the returned bytes and sends only the public key and
   signature back to PHP.
4. PHP independently rebuilds the v1 bytes, verifies Ed25519, and persists
   the signature.
5. The public endpoint returns the public key as canonical unpadded standard
   Base64 SPKI DER with `publicKeyEncoding: "spki-der"`.

Completion uses a short-lived transient plus a compare-and-set option lock.
The transient is deleted after the signature row is stored. The consume lock
remains through its cleanup window, so a failed transient deletion cannot make
a used token valid again. A persistence failure releases the lock and keeps the
transient for retry. If PHP dies after the row is stored, the transient expires
after five minutes. The completion path checks for a stale lock, and each later
prepare request scans at most 100 consume locks and reclaims locks older than ten minutes. This
bounded crash window cannot authorize a late retry.

The wrapper is registered at `PHP_INT_MAX`. Before emitting a local wrapper,
the display callback canonicalizes the exact filtered content it received and
compares its hash with the stored content hash. A mismatch withholds the
wrapper and leaves the content unsigned.

WordPress does not define ordering between callbacks registered later at the
same priority, so such a callback can still run after the wrapper. The signing
pass and a frontend request can also have different block context or request
state. Those limits remain deployment concerns even with the maximum priority.

### Historical pre-v1 flow

The original flow, retained here for migration context, was:

1. WordPress fires `publish_post` (or `transition_post_status`).
2. `ContentSigning_Hooks::on_publish_post` -> `Signing_Service::process_post`
   -> `Signing_Service::sign_post`.
3. `prepare_content_data()` canonicalizes the signed content, including
   `href`, `src`, `alt`, and `aria-label` records, computes
   `sha256:<unpadded standard Base64>`, serializes `domain` as the Web
   origin, and attaches direct meta claims such as `author`, `signed-at`,
   and `claim:*`.
4. `ContentSigning_API_Client::sign_content()` POSTs `{contentHash, domain,
   claims, signedAt, sourceURL}` to `<trust-server>/api/content/sign` with
   the author's API key in `X-AUTHOR-API-KEY`.
5. The trust server holds the author's signing key, signs server-side, and
   returns `{signature, ...}`.
6. We persist the signature row in `wp_content_signing_signatures` and
   (separately, via the public/display class) emit a `<signed-section>`
   wrapper around the content on render.

### What flows to the trust server

Per request: content hash, domain, claims object, author API key (bearer
auth). The trust server learns:

- That this author is publishing right now.
- The hash (and indirectly the size) of the content being published.
- The claim values for this content.
- Implicitly, the publication cadence and topic distribution of every
  author on every site that uses this plugin.

The trust server signs with a key it owns on behalf of the author. The
author never sees the private key. If the trust server is compromised, an
attacker can forge signatures from any of its authors, and there is no
way for the author to prove they did not author a forged piece.

### Where the keys live

In the trust server's database. The plugin only stores an encrypted *API
key* (`api_key_encrypted` / `author_api_key_encrypted` columns), which is
the bearer token used to authenticate signing requests, not the signing
key itself.

## Resolved protocol gap

§2.2 (Identity and Key Resolution) makes the trust directory's role purely
optional convenience: `keyid` is pluggable across DIDs, direct URLs, and
trust-directory references; "none" is canonical; verification is local.

§3.1 (Browser Behavior) reinforces that cryptographic verification is a
local operation in the user agent with no required network calls beyond
key resolution. The same design intent applies to signing: it is a local
operation performed by the author (or the
author's tooling) using a key the author controls. Anything else recreates
exactly the central-authority pattern the spec is built to avoid.

The pre-v1 plugin failed this test in three ways:

1. **Key custody is wrong.** Authors do not hold their own keys; the trust
   server does.
2. **Signing had a remote-call dependency.** Pre-v1 publication failed if
   the trust server was unreachable, even though the spec contemplates no
   such dependency.
3. **The trust server learns about every publication.** That coupling is
   incompatible with the "MAY submit to one or more trust directories"
   language in §2.4 and the federated-by-design framing throughout.

## Design notes and future options

### Current design: the editor signs

PHP prepares the final filtered HTML and returns the exact HTMLTrust signing
payload to the editor. Browser JavaScript signs those bytes with a Web Crypto
Ed25519 key whose private half is non-extractable and stored in IndexedDB.
PHP verifies the returned signature against the same payload before it stores
anything. The trust directory is optional and receives no signing request.

### Where the private key lives (three options, listed in deployment
preference order)

**1. Browser Web Crypto Ed25519 key (current implementation).** The editor
generates an Ed25519 `CryptoKeyPair` with `extractable=false` directly. The
private key is never exported; the public member remains exportable for key
publication. The pair is stored in IndexedDB, and only the public key and
signature go to PHP. This is the HTMLTrust signature artifact. It is distinct
from a WebAuthn assertion.

Limitations: requires a browser with Ed25519 Web Crypto support and local
storage. It does not sign scheduled, headless, XML-RPC, or mobile publishes.
Those paths remain explicitly unsigned until an author opens the editor.

WebAuthn may protect access to the editor or unlock a future external signer,
but its assertion bytes are not the HTMLTrust payload signature. WebAuthn
assertions bind `clientDataJSON` and authenticator data to a challenge; they
cannot be substituted for `crypto.subtle.sign()` over the HTMLTrust payload.

**2. Encrypted key file in `wp-content/uploads/htmltrust/` (future
headless option).** The site admin generates an Ed25519 keypair via a
separate CLI or deployment process, stores the encrypted private key on disk
(passphrase-derived KEK held in a `wp-config.php` constant, *not* the DB),
and configures author-to-key mappings. This option is not enabled by the
current plugin and must not be silently selected when a browser key is
unavailable.

Limitations: trust boundary is "anyone with filesystem access to
wp-content can attempt offline brute force on the passphrase". Acceptable
for managed VPS / single-tenant; not great for shared hosting. Mitigate
with sodium `crypto_secretbox` + Argon2id, and document that the
passphrase must not live in the database.

**3. CLI / external signer (escape hatch).** Author runs an `htmltrust
sign` CLI tool on their laptop against an exported draft, pastes the
resulting signed blob into the post. No keys on the server at all.
Suitable for highly security-conscious authors and for the initial
phase-1 rollout where the editor integration doesn't exist yet.

### Where canonicalization runs

The PHP canonicalization binding (`@htmltrust/canonicalization` /
`HTMLTrust\Canonicalization\Canonicalize`) already exists and is used in
`Signing_Service::normalize_content`. **Keep it.** PHP-side
canonicalization is the canonical (sic) place to compute the content hash
because:

- WordPress mutates content in non-trivial ways at publish time (shortcode
  expansion, oEmbed substitution, `the_content` filters). The hash must
  match what's actually rendered.
- A JS-side canonicalizer in the editor can compute a *preview* hash, but
  the authoritative hash is what PHP computes
  after all filters have run, and that's what must be signed.

For the browser path specifically, this means signing has to happen in
two passes:

1. PHP runs filters, computes canonical hashes, and returns the exact frozen
   v1 RFC 8785 payload, including profile, key ID, algorithm, scope,
   location, hashes, and timestamp, to the browser.
2. Browser signs those UTF-8 bytes with `crypto.subtle.sign({name:
   "Ed25519"}, privateKey, payload)` and posts the signature and public key
   back.
3. PHP recomputes the hashes, verifies the Ed25519 signature, stores the
   signature alongside the hash, and emits the
   `<signed-section>` wrapper.

This is fiddly but workable; the alternative (signing arbitrary
client-rendered preview) lets a malicious filter or plugin inject
unsigned content.

### Publish-time flow (current)

The `wp_insert_post` / `transition_post_status` flow is:

1. WordPress applies all `the_content` filters, shortcode expansion, etc.
   (We piggyback on `the_content` to capture the rendered HTML at the
   right moment, not the raw post body.)
2. PHP canonicalizes the rendered content; computes `content-hash`.
3. PHP gathers the claims (default + post-specific, same as today).
4. PHP builds the frozen v1 RFC 8785 signing object, including the profile,
   key ID, algorithm, scope, and derived location.
5. The browser signs the binding with Web Crypto Ed25519 and returns the
   signature plus public key. PHP verifies the signature before persistence.
6. PHP stores `{signature, keyid, content-hash, signed-at, claims}` in the
   existing `wp_content_signing_signatures` table, including v1 payload
   metadata and the resolver-compatible public key.
7. The public-facing display layer compares the current filtered bytes with
   the stored content hash, then emits the `<signed-section>` wrapper only
   when they match.
8. *Future optional action*: notify a configured trust directory with the
   already-signed blob. Signing and publication do not depend on that service.

### What the trust directory still receives

In the current design the trust directory is reduced from "co-signer" to
"index", and only when the site opts in. It receives:

- The signed blob (already-signed; the directory cannot forge or alter
  it).
- Optionally: a publication URL so the directory can crawl and verify.
- Optionally: claim values, for indexing/search.

It does **not** receive private keys, API keys with signing authority,
content prior to signing, or anything that would let it impersonate the
author.

The plugin should be able to operate with the trust directory disabled
entirely.

## Risks and Open Questions

- **Key storage UX is the hardest problem.** The current editor generates a
  local key automatically. Device loss requires rotation, and headless
  publishing still needs a separately designed external signer.
- **Multi-author sites: per-author keys vs. site key?** §2.2 implies
  per-author; in practice, many WP sites have one editor publishing on
  behalf of many "authors". Recommendation: support both, with explicit
  UI for the choice. The site-key case is functionally an endorsement
  pattern (site endorses author) -- align with §2.5.
- **Key rotation.** If a key is rotated, all previously-signed content
  remains validly signed under the old key (signatures don't expire just
  because the key did). We need (a) a way to publish key-rotation
  metadata at the keyid resolution endpoint, and (b) UI to make this
  obvious to authors.
- **Backup and recovery.** Browser private keys cannot be exported after
  import. If the browser profile is lost, signed history remains valid but
  new content requires a rotated key. WordPress cannot recover the old key.
- **Mobile app authoring (Jetpack, WP mobile app).** These use the REST
  API and do not have access to the browser's IndexedDB key. The current
  policy is to leave the post unsigned and show it in the local-signing
  queue. A future mobile signer must implement the same Ed25519 payload
  contract.
- **REST API authoring (headless, Gutenberg over REST).** The publisher must
  eventually submit a signature over the PHP-produced payload, or the post
  remains unsigned. This slice does not accept an API key as a substitute
  for an author's private key.
- **What happens on republish / edit?** Re-signs with current timestamp.
  The previous signature row is preserved (we already have a one-row-per-
  attempt schema). UI should expose the signature history.
- **Compatibility with existing installs.** Anyone who already created
  trust-server-side keys via the current plugin will need a migration
  path: (a) generate a new local key, (b) re-sign their existing content
  under the new key, (c) deprecate the old server-side key. Make this a
  one-click flow in the admin UI.

## Suggested Rollout

### Phase 1: Ship a CLI signer (size: S)

Build a standalone PHP/Node CLI tool (`htmltrust-sign`) that takes a file
or URL, canonicalizes the content, prompts for a passphrase, signs with
a local Ed25519 key, and emits a `<signed-section>` blob. The plugin
gains a "paste signed blob" workflow in the post meta box.

This lets us validate the signed-blob shape end-to-end without touching
the trust-server signing endpoint at all, and gives security-conscious
authors a path that doesn't trust the server with anything.

**Done when:** CLI exists, plugin accepts pasted blobs, e2e test passes
with CLI-signed content.

### Phase 2: Browser-local signing (current slice)

The editor uses a two-pass AJAX flow. PHP returns the filtered payload, the
browser signs it with a non-exportable IndexedDB key, and PHP verifies it
with the submitted public key before storing the signature. Existing
remote-signature rows remain readable for migration.

Scheduled and headless publishes create an `awaiting-local-signature` queue
entry. They never call the remote signing endpoint. Opening the post editor
and selecting Sign Now produces the local signature.

**Implemented:** the Docker WordPress suite covers payload mutation rejection,
authorization, resolver-shaped keys, rendered-byte drift, and a static browser
asset assertion that private key material is never sent in an AJAX payload. A
real browser run remains a deployment validation step because Web Crypto and
IndexedDB are not available in PHPUnit.

### Phase 3: Optional hardware-backed signer (future)

WebAuthn can protect editor access or authorize an external signer. It must
not be treated as the HTMLTrust signature itself. A future profile would
define how a WebAuthn assertion authorizes use of the Web Crypto key, or
define a separate WebAuthn signature profile with its own verifier rules.

**Done when:** a separately designed hardware-backed profile has a verifier
and interoperability tests. It must not reinterpret an assertion as an
Ed25519 HTMLTrust signature.

### Phase 4: Legacy remote signing status

The publication service now hard-disables `Signing_Service::sign_post` and has
removed endorsement execution. The API client's read and compatibility methods
remain for migration screens and existing remote records.

Trust-directory notification (`/api/content/notify`-style) can be added as
an opt-in post-signing action.

**Done:** Removing the trust server from a site's config does not invoke it in
the publish path. Real browser and deployment validation remain separate gates.

## Effort Summary

| Phase | Description                          | Size | Notes                                                  |
| ----- | ------------------------------------ | ---- | ------------------------------------------------------ |
| 1     | CLI signer + paste-blob workflow     | S    | No infra changes; validates the wire format            |
| 2     | Browser-local signing                 | M    | Schema migration + UI + crypto; meaningful test surface |
| 3     | Optional hardware-backed signer      | M    | Separate profile and verifier design                   |
| 4     | Legacy remote signing status         | S    | Disabled in the publication service; compatibility reads remain |

Total: roughly two-to-three sprints of focused work, gated on Jason's
approval at each phase boundary.

## Recommendation

The browser-local flow is the correct default for interactive publishing.
Keep server-key signing out of the default path. Decide separately whether
headless publishers should implement the same payload contract or use a
deploy-controlled external signer.

This document describes the current implementation boundary and the gates
that remain before removing the legacy remote method.
