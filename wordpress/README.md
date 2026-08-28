# HTMLTrust WordPress reference plugin

This plugin signs WordPress content in the author's browser. PHP renders the
filtered post, computes the HTMLTrust hashes, and returns the exact signing
payload. The editor signs that payload with an Ed25519 Web Crypto key stored
in IndexedDB. PHP verifies the signature before saving it.

The current v1 canonicalization dependency requires PHP 8.5 and the sodium,
DOM, intl, mbstring, and OpenSSL extensions.

The private key is non-extractable and is never included in an AJAX request.
The public key is published through the read-only endpoint named by the
signature's `keyid`:

```
/wp-json/htmltrust/v1/keys/{key-id}
```

The endpoint returns `publicKeyEncoding: "spki-der"` and canonical unpadded
standard Base64 for the Ed25519 SubjectPublicKeyInfo bytes. Published v1
sections use `signature-scope="url"` and bind the exact HTTPS document URL.

## Install and test

From this directory:

```sh
composer install
composer test
composer phpcs
```

The integration suite needs a WordPress test installation. Configure the
standard `WP_TESTS_DIR` environment variable and run `composer test`.

## Publish behavior

In **Author Profiles**, select **Browser-local only** for an editor profile
that signs without a trust directory. The local identity defaults to
`local-wp-user-{ID}`. The profile has no API key and cannot be a site endorser.
Remote server and author API key fields remain available for legacy profile
migrations.

Use **Sign Now** in the Content Signing post box. The plugin performs two
requests:

1. PHP returns the filtered content hashes, claims, timestamp, and exact
   frozen v1 RFC 8785 payload, including profile, key ID, algorithm, scope,
   location, hashes, and timestamp.
2. The browser returns the signature and public key. PHP recomputes the
   payload and verifies the Ed25519 signature before persistence.

If the post changes between requests, PHP rejects the signature and asks the
editor to prepare a new one. A trust directory is not needed for signing.

Scheduled, REST, XML-RPC, and mobile publishes do not have a browser key.
They remain unsigned and create an `awaiting-local-signature` queue record.
Open the post in the WordPress editor and select **Sign Now** after the post
is available. There is no server-side signing fallback.

## Key rotation and recovery

Select **Rotate local key** in the post box to generate a fresh key. Existing
signatures remain tied to their previous public key and continue to verify.
The current key is stored only in this browser profile. Clearing site data,
losing the browser profile, or moving to another device requires key rotation;
the old private key cannot be recovered by WordPress.

Back up the published content and its signature history before rotating. A
future external signer can support headless publishing, but it must sign the
same payload and publish a resolvable public key. WebAuthn assertions are a
separate artifact and cannot replace the Ed25519 HTMLTrust payload signature.

See [the local-signing audit](docs/local-signing-audit.md) for the threat
model, migration boundary, and remaining gates.
