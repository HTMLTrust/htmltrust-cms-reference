# Local Signing Audit

**Status:** proposal for review
**Spec basis:** htmltrust spec, amended 2026-04-10 (§2.2 Identity and Key Resolution; §3.1 Browser Behavior)
**Author:** triggered by P0 cleanup pass; do not implement before Jason approves the rollout.

## TL;DR

The current plugin sends every author's content (hash + claims) to a remote
"trust server" and asks that server to perform the signing operation using a
private key the server holds. The amended spec is explicit: signing is a
**local cryptographic operation**, the trust directory is **optional and
non-privileged**, and **no central authority** ever performs the signing on
behalf of the author. We need to invert the key-custody relationship and
make signing happen client-side (or at least author-side) before anything
touches the network.

This document describes the gap, a proposed end-state, the open questions,
and a phased rollout. The rollout is deliberately incremental so that we
can ship value (and learn) at each phase without committing to the full
end-state up front.

## Current State

### How signing happens today

Today's flow, per `includes/class-content-signing-signing-service.php` ->
`sign_post()`:

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

## Spec Gap

§2.2 (Identity and Key Resolution) makes the trust directory's role purely
optional convenience: `keyid` is pluggable across DIDs, direct URLs, and
trust-directory references; "none" is canonical; verification is local.

§3.1 (Browser Behavior) reinforces that cryptographic verification is a
local operation in the user agent with no required network calls beyond
key resolution. A symmetric reading -- and the design intent -- is that
**signing is also a local operation**, performed by the author (or the
author's tooling) using a key the author controls. Anything else recreates
exactly the central-authority pattern the spec is built to avoid.

The plugin as shipped today fails this test in three ways:

1. **Key custody is wrong.** Authors do not hold their own keys; the trust
   server does.
2. **Signing has a remote-call dependency.** Publishing fails closed if
   the trust server is unreachable, even though the spec contemplates no
   such dependency.
3. **The trust server learns about every publication.** That coupling is
   incompatible with the "MAY submit to one or more trust directories"
   language in §2.4 and the federated-by-design framing throughout.

## Proposed Design

### End-state: the editor signs

Signing happens at publish time, in PHP on the WordPress server, using a
private key that the author (or the site) controls and that the plugin
loads from a configured key store. The trust directory is contacted only
if the site has opted in to publication notification, and only with the
already-signed blob.

### Where the private key lives (three options, listed in deployment
preference order)

**1. WebAuthn / hardware-backed key (best UX-to-security ratio for
single-author sites).** The author registers a hardware key (TouchID,
Yubikey, platform authenticator) via a JS flow in the post editor. The
key never leaves the device. Signing happens in the browser via the
WebAuthn `sign` operation, and the signed blob is POSTed back to the
WordPress REST API as part of the publish payload. PHP server-side never
sees the private key.

Limitations: requires a recent browser; doesn't compose with non-browser
publishing paths (XML-RPC, mobile app, REST clients, scheduled posts,
cron-driven imports). For those, fall back to (2) or (3).

**2. Encrypted key file in `wp-content/uploads/htmltrust/` (server-side
fallback).** The site admin generates an Ed25519 keypair via the plugin
UI or the bundled CLI tool, stores the encrypted private key on disk
(passphrase-derived KEK held in a `wp-config.php` constant, *not* the DB),
and configures author->key mappings in plugin settings. PHP loads the key
on demand for signing.

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
- A JS-side canonicalizer in the editor can compute a *preview* hash for
  WebAuthn-style signing, but the authoritative hash is what PHP computes
  after all filters have run, and that's what must be signed.

For the WebAuthn path specifically, this means signing has to happen in
two passes:

1. PHP server runs filters, computes canonical hash, returns hash to the
   browser.
2. Browser presents the hash to the WebAuthn authenticator, gets a
   signature, posts the signature back.
3. PHP server stores the signature alongside the hash and emits the
   `<signed-section>` wrapper.

This is fiddly but workable; the alternative (signing arbitrary
client-rendered preview) lets a malicious filter or plugin inject
unsigned content.

### Publish-time flow (proposed)

The proposed `wp_insert_post` / `transition_post_status` flow becomes:

1. WordPress applies all `the_content` filters, shortcode expansion, etc.
   (We piggyback on `the_content` to capture the rendered HTML at the
   right moment, not the raw post body.)
2. PHP canonicalizes the rendered content; computes `content-hash`.
3. PHP gathers the claims (default + post-specific, same as today).
4. PHP builds the **claims-binding** structure (the canonicalized
   key-sorted serialization of `{contentHash, domain, claims, signedAt,
   keyid}` per the canonicalization spec).
5. Local signer signs the claims-binding:
   - WebAuthn path: PHP returns the binding bytes to the editor JS, JS
     calls `navigator.credentials.get(...)`, posts signature back.
   - Server-key path: PHP loads encrypted key, decrypts with KEK from
     `wp-config`, signs with `sodium_crypto_sign_detached`.
   - CLI path: blob already signed at draft import time; PHP just stores
     it.
6. PHP stores `{signature, keyid, content-hash, signed-at, claims}` in
   post meta (one row per signature in the existing
   `wp_content_signing_signatures` table; schema is already mostly
   right).
7. The public-facing display layer reads post meta and emits the
   `<signed-section>` wrapper at render time. (This already exists;
   `ContentSigning_Display` just needs to read from a different source.)
8. *Optional*: notify a configured trust directory by POSTing
   `{contentHash, signature, keyid, domain}` to its
   `/api/content/notify` endpoint. The notification is fire-and-forget;
   publish does not block on it.

### What the trust directory still receives

In the proposed design the trust directory is reduced from "co-signer" to
"index" -- and only when the site opts in. It receives:

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

- **Key storage UX is the hardest problem.** Authors don't want to think
  about keys. The CLI escape hatch is for power users; the WebAuthn path
  is for typical authors; the server-key path is for everyone who can't
  use either. We will ship all three but we need a clear default.
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
- **Backup and recovery.** WebAuthn keys can't be exported; if the
  hardware is lost, signed history remains valid but new content can't
  be signed under that key. Server-key path needs documented backup
  procedure (encrypted key file + KEK passphrase recovery sheet).
- **Mobile app authoring (Jetpack, WP mobile app).** These use the REST
  API and don't have access to a local signer. Options: (a) have the
  mobile app implement WebAuthn-equivalent signing; (b) accept that
  mobile-published posts get signed server-side with a per-site key; (c)
  delay signing until the next desktop edit. Probably (b) with explicit
  UI labeling.
- **REST API authoring (headless, Gutenberg over REST).** Same shape as
  mobile. Recommendation: REST publishers must include a `signature`
  field in the publish payload, OR opt in to server-key signing. Reject
  unsigned publishes only if "strict signing" is enabled.
- **What happens on republish / edit?** Re-signs with current timestamp.
  The previous signature row is preserved (we already have a one-row-per-
  attempt schema). UI should expose the signature history.
- **Compatibility with existing installs.** Anyone who already created
  trust-server-side keys via the current plugin will need a migration
  path: (a) generate a new local key, (b) re-sign their existing content
  under the new key, (c) deprecate the old server-side key. Make this a
  one-click flow in the admin UI.

## Suggested Rollout

### Phase 1 — Ship a CLI signer (size: S)

Build a standalone PHP/Node CLI tool (`htmltrust-sign`) that takes a file
or URL, canonicalizes the content, prompts for a passphrase, signs with
a local Ed25519 key, and emits a `<signed-section>` blob. The plugin
gains a "paste signed blob" workflow in the post meta box.

This lets us validate the signed-blob shape end-to-end without touching
the trust-server signing endpoint at all, and gives security-conscious
authors a path that doesn't trust the server with anything.

**Done when:** CLI exists, plugin accepts pasted blobs, e2e test passes
with CLI-signed content.

### Phase 2 — Server-key signing (size: M)

Implement option (2) from "where the private key lives": encrypted key
file in `wp-content/uploads/htmltrust/`, KEK from `wp-config.php`
constant, plugin settings UI for key generation / per-author mapping.

The trust-server signing call becomes opt-in (off by default), and the
plugin signs locally with `sodium_crypto_sign_detached`. Schema changes:
add `key_id` and `key_fingerprint` columns to
`wp_content_signing_authors`; deprecate `author_api_key_encrypted`.

Migration: existing installs get a one-click "switch to local signing"
flow that generates a new key and re-signs the latest revision of each
post.

**Done when:** A site with no trust-server configured can publish signed
content; the trust-server signing endpoint is deprecated in admin UI.

### Phase 3 — WebAuthn-attested keys (size: M)

Editor-side JS integration: WebAuthn registration flow in user profile,
WebAuthn signing flow at publish time. PHP-side: receive
`{publicKeyCredential, signature}` from the editor, verify the signature
against the stored credential, store the result.

This is the best end-state UX for typical authors. It requires the
two-pass canonicalization flow described above.

**Done when:** A user can register a hardware key via the WP admin UI
and publish a post that is signed entirely client-side, with no signing
key ever present on the server.

### Phase 4 — Deprecate the trust-server signing endpoint (size: S)

Remove `Signing_Service::sign_post`'s call to
`api_client->sign_content()`. Keep `api_client->sign_content` as a
client method (it's still useful for the trust server's own admin
tooling) but no plugin code path calls it. Mark
`X-AUTHOR-API-KEY`-bearing flows as deprecated in plugin docs.

Trust-directory notification (`/api/content/notify`-style) replaces it,
and is opt-in per author.

**Done when:** Removing the trust server entirely from a site's config
breaks nothing in the publish path.

## Effort Summary

| Phase | Description                          | Size | Notes                                                  |
| ----- | ------------------------------------ | ---- | ------------------------------------------------------ |
| 1     | CLI signer + paste-blob workflow     | S    | No infra changes; validates the wire format            |
| 2     | Server-key signing (PHP-local)       | M    | Schema migration + UI + crypto; meaningful test surface |
| 3     | WebAuthn editor integration          | M    | New JS surface; two-pass canonicalization              |
| 4     | Deprecate trust-server signing       | S    | Mostly removal + docs                                  |

Total: roughly two-to-three sprints of focused work, gated on Jason's
approval at each phase boundary.

## Recommendation

Approve phases 1 and 2 as a unit (they're a coherent migration and worth
shipping together). Phase 3 is the right end-state but introduces
non-trivial JS-side complexity; defer the go/no-go decision until phase
2 is in production for a few weeks. Phase 4 is mechanical once 1-3 are
in.

Do **not** start work on any phase from this PR. This document exists
specifically so the redesign can be argued about before code moves.
