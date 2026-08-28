# HTMLTrust CMS Reference

Reference WordPress and Hugo integrations for HTMLTrust content signing. They prepare published content for canonical hashing and embed signatures that browsers and crawlers can verify.

This is a companion to the [HTMLTrust specification](https://github.com/HTMLTrust/htmltrust-spec).

## Current status

The WordPress plugin and Hugo build integration are runnable. Drupal, Joomla, and Craft integrations are planned but have no code in this repository yet.

## WordPress prerequisites

- WordPress 5.0+
- PHP 8.5+
- PHP sodium, DOM, Intl, mbstring, and OpenSSL extensions
- Composer
- HTTPS for published URLs

The plugin uses the v1 `htmltrust/canonicalization` dependency from its Git
repository until the v1 package is released. Browser-local signing does not
need a trust directory during publication.

## Quick start

### WordPress

```sh
cd wordpress/
composer install
```

Symlink the `wordpress/` directory into `wp-content/plugins/`, or zip it and install it through the WordPress admin. Link the post author to a signing profile and enable signing for the post types you want to publish.

### Hugo

Copy the partials from `hugo/layouts/partials/` into your Hugo project, then follow the [Hugo integration guide](hugo/README.md) to wrap selected pages and run the optional post-build signer.

## What It Does

When an author publishes content, the plugin:

- **Canonicalizes** rendered content, including signed semantic attributes, and computes a SHA-256 content hash
- **Builds** direct-child claims, computes their canonical claims hash, and builds the frozen v1 RFC 8785 signing payload with profile, algorithm, key ID, scope, location, hashes, and timestamp
- **Queues** headless and scheduled publications for a later author browser session because those contexts have no local private key
- **Signs** in the author's browser with a non-extractable IndexedDB Ed25519 key, then verifies the returned signature in PHP before persistence
- **Embeds** the signature, key reference, algorithm, content hash, signed-at claim, and direct-child claims into the published HTML
- **Retains** legacy remote author and endorser records for migration, alongside claim metadata (content type, license, AI involvement, etc.)
- **Displays** signature status on the frontend with verification controls

## Architecture

The repo is structured for **multiple CMS implementations** sharing common documentation and API contracts:

```
htmltrust-cms-reference/
├── README.md
├── docs/                     # Shared across all CMS implementations
│   ├── developer-guide.md    # Integration guide for building new CMS plugins
│   ├── user-guide.md         # End-user documentation
│   └── html-protocol.md      # The sig-* HTML attribute protocol specification
├── shared/
│   └── openapi.yaml          # API contract that all CMS plugins implement against
├── wordpress/                # WordPress plugin implementation
│   ├── content-signing.php   # Plugin entry point
│   ├── admin/                # WP admin pages (settings, profiles, meta boxes)
│   ├── includes/             # Core logic (API client, signing service, DB, hooks)
│   ├── public/               # Frontend display and verification UI
│   ├── tests/                # PHPUnit test suite
│   ├── languages/            # i18n translation templates
│   └── bin/                  # Test environment setup scripts
└── (future: drupal/, joomla/, craft/, etc.)
```

### Adding a New CMS Plugin

1. Create a new directory at the root (e.g., `drupal/`)
2. Implement against the API contract in `shared/openapi.yaml`
3. Follow the HTML protocol in `docs/html-protocol.md` for embedding signatures
4. Refer to `docs/developer-guide.md` for integration patterns

## WordPress Plugin

### Installation

```sh
cd wordpress/
composer install    # Install dev dependencies (PHPUnit, PHPCS)
```

Then either:
- **Symlink** the `wordpress/` directory into your WP `wp-content/plugins/` folder, or
- **Zip** the `wordpress/` directory and install via the WordPress admin

### Configuration

1. Navigate to **Settings → Content Signing** in the WordPress admin
2. Add an **Author Profile** for each post author. Choose **Browser-local only** for editor signing without a trust directory. It uses `local-wp-user-{ID}` and needs no API key.
3. Add a **Server Profile** only for legacy remote identities. Remote profiles keep their API key workflow and cannot be used by the local browser path.
4. Enable signing for your desired post types
5. Publish a post, then open it as its author and select **Sign Now**. Browser-local profiles cannot be site endorsers.

### Running Tests

The reproducible test path needs Docker and Docker Compose v2. From the
repository root, run:

```sh
./wordpress/bin/test-docker.sh
```

This builds a PHP 8.5 test image, starts MariaDB 11.8.2, waits for its health
check, installs the exact Composer lock file, downloads the WordPress 6.9.4
core and test suite into Docker-managed volumes, then runs PHPUnit.
The image and database tags are pinned by digest. Generated WordPress assets
and Composer dependencies stay in Docker volumes, so the command does not
write build artifacts to `/tmp` or require a host PHP installation.

Run the coding-standard check separately, or remove the cached test assets:

```sh
./wordpress/bin/test-docker.sh --lint
./wordpress/bin/test-docker.sh --clean
```

The lock file resolves the v1 API from the `htmltrust/canonicalization` Git
repository. Pin a released v1 package before distributing the plugin outside
this reference repository.

The current checkout contains existing WordPress Coding Standards violations,
so `--lint` reports a nonzero result after PHPUnit completes. Keeping that check
explicit makes the default test command a reliable pass/fail signal for the
current PHPUnit suite, which contains 80 tests in this checkout.

### Manual test setup

```sh
cd wordpress/
export TEST_TMP_DIR="${HOME}/tmp/htmltrust-cms-tests"
mkdir -p "$TEST_TMP_DIR"
TMPDIR="$TEST_TMP_DIR" \
WP_TESTS_DIR="$TEST_TMP_DIR/wordpress-tests-lib" \
WP_CORE_DIR="$TEST_TMP_DIR/wordpress" \
bin/install-wp-tests.sh wordpress_test root '' localhost 6.9.4
TMPDIR="$TEST_TMP_DIR" \
WP_TESTS_DIR="$TEST_TMP_DIR/wordpress-tests-lib" \
WP_CORE_DIR="$TEST_TMP_DIR/wordpress" \
composer test
```

The installer downloads WordPress and the WordPress test library into the
disposable directory under `$HOME/tmp`. Set `DB_HOST` in the installer command
when MySQL or MariaDB is running in a container. The test suite expects the
database named by the first argument to exist or for the database user to be
allowed to create it.

For a development container, open this repository in VS Code Dev Containers.
The configuration provides PHP 8.5, Composer, Node 22, Go 1.25, and Hugo
Extended 0.161.1. Run the same commands above from `wordpress/` after the
container starts.

### Legacy compatibility

Existing remote signatures remain readable during migration. The publication
path does not call the remote signing endpoint. A future headless signer must
sign the same v1 payload and publish a resolver-compatible public key.

## The HTML Protocol

Signed content is embedded with a `<signed-section>` wrapper around the actual signed content:

```html
<signed-section profile="htmltrust-signature-v1"
    signature-scope="url" location="https://example.com/articles/engines"
    keyid="did:web:author.example"
    signature="BASE64_SIG" algorithm="ed25519"
    content-hash="sha256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU">
  <meta name="author" content="Alice Example">
  <meta name="signed-at" content="2026-05-01T10:30:00Z">
  <meta name="claim:ContentType" content="Article">
  <article>
    <h1>Verifiable Web Content</h1>
    <p>Content should be provable...</p>
  </article>
</signed-section>
```

See `docs/html-protocol.md` for the full specification.

## Companion Repositories

| Repository | Description |
|---|---|
| [htmltrust-spec](https://github.com/HTMLTrust/htmltrust-spec) | The HTMLTrust specification and paper |
| [htmltrust-server-reference](https://github.com/HTMLTrust/htmltrust-server-reference) | Reference trust directory API server |
| [htmltrust-browser-reference](https://github.com/HTMLTrust/htmltrust-browser-reference) | Reference browser extension for signature validation |
| [htmltrust-website](https://github.com/HTMLTrust/htmltrust-website) | Project website |

## License


This project is licensed under the [PolyForm Noncommercial License 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0). You may use, modify, and share the software for any noncommercial purpose with attribution. Commercial use requires a separate agreement with the licensor.

## Origin & Contributions

HTMLTrust is an idea I (Jason Grey) have been chewing on since 2024. I'm not an academic. I'm an engineer with a day job and a family, so the spec, the reference implementations, and most of this prose have been written with significant help from AI tools acting as research assistant, technical writer, and pair programmer. I wrote the original architectural sketches and reviewed every line; the assistants filled in the gaps and saved me from re-typing the same explanation for the hundredth time.

**Contributions are welcome, whether human or AI-assisted.** What matters is whether the code, the spec text, or the conformance vectors move the project forward. Open a PR.

What this project is **not** a forum for:

- Debates about whether AI should be used to write code or specifications.
- Opinions on who is or isn't trustworthy on the web.
- Politics, religion, professional practice, or personal philosophy.

HTMLTrust is a mechanism, a way for *anyone* to sign content they publish and for *anyone* to decide whom they trust on their own terms. The project takes no position on what the right answers are; it provides the tools. If you want to debate the answers, there are entire continents of the internet better suited to it.

If this work is useful to you and you'd like to support it, see [GitHub Sponsors](https://github.com/sponsors/jt55401) or the other channels in [`.github/FUNDING.yml`](.github/FUNDING.yml).
