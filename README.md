# HTMLTrust CMS Reference

Reference WordPress and Hugo integrations for HTMLTrust content signing. They prepare published content for canonical hashing and embed signatures that browsers and crawlers can verify.

This is a companion to the [HTMLTrust specification](https://github.com/HTMLTrust/htmltrust-spec).

## Current status

The WordPress plugin and Hugo build integration are runnable. Drupal, Joomla, and Craft integrations are planned but have no code in this repository yet.

## WordPress prerequisites

- WordPress 5.0+
- PHP 7.2+
- PHP Intl extension
- Composer
- A running [HTMLTrust trust directory server](https://github.com/HTMLTrust/htmltrust-server-reference)

## Quick start

### WordPress

```sh
cd wordpress/
composer install
```

Symlink the `wordpress/` directory into `wp-content/plugins/`, or zip it and install it through the WordPress admin. Configure a server profile, link a WordPress user to a registered author identity, and enable signing for the post types you want to publish.

### Hugo

Copy the partials from `hugo/layouts/partials/` into your Hugo project, then follow the [Hugo integration guide](hugo/README.md) to wrap selected pages and run the optional post-build signer.

## What It Does

When an author publishes content, the plugin:

- **Canonicalizes** rendered content, including signed semantic attributes, and computes a SHA-256 content hash
- **Builds** direct-child claims, computes their canonical claims hash, and binds both hashes to the publication origin and signed-at timestamp
- **Requests** a compatibility signature from the HTMLTrust trust directory using the configured author API credential; the server performs signing for the registered author identity
- **Embeds** the signature, key reference, algorithm, content hash, signed-at claim, and direct-child claims into the published HTML
- **Supports** multiple author profiles, endorser profiles, and claim metadata (content type, license, AI involvement, etc.)
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
2. Add a **Server Profile** pointing to your HTMLTrust trust directory server URL
3. Create **Author Profiles** linking WordPress users to server-side author identities
4. Enable signing for your desired post types
5. Publish a post — it will be automatically signed

### Running Tests

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
The configuration provides PHP 8.3, Composer, Node 22, Go 1.25, and Hugo
Extended 0.161.1. Run the same commands above from `wordpress/` after the
container starts.

### Using the reference server

The WordPress plugin's signing client is compatible with the Node reference
server in `htmltrust-server-reference`. Start that server at
`http://localhost:3000`, then configure the plugin's server profile with that
URL. The plugin uses the server's author API key for `POST /api/content/sign`
and sends the publication origin as the `domain` field. Use an origin such as
`https://example.com`, including the scheme and optional port.

## The HTML Protocol

Signed content is embedded with a `<signed-section>` wrapper around the actual signed content:

```html
<signed-section keyid="did:web:author.example"
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

HTMLTrust is an idea I (Jason Grey) have been chewing on since 2024. I'm not an academic — I'm an engineer with a day job and a family — so the spec, the reference implementations, and most of this prose have been written with significant help from AI tools acting as research assistant, technical writer, and pair programmer. I wrote the original architectural sketches and reviewed every line; the assistants filled in the gaps and saved me from re-typing the same explanation for the hundredth time.

**Contributions are welcome — human or AI-assisted, doesn't matter to me.** What matters is whether the code, the spec text, or the conformance vectors move the project forward. Open a PR.

What this project is **not** a forum for:

- Debates about whether AI should be used to write code or specifications.
- Opinions on who is or isn't trustworthy on the web.
- Politics, religion, professional practice, or personal philosophy.

HTMLTrust is a mechanism — a way for *anyone* to sign content they publish and for *anyone* to decide whom they trust, on their own terms. The project takes no position on what the right answers are; it just provides the tools. If you want to debate the answers, there are entire continents of the internet better suited to it.

If this work is useful to you and you'd like to support it, see [GitHub Sponsors](https://github.com/sponsors/jt55401) or the other channels in [`.github/FUNDING.yml`](.github/FUNDING.yml).
