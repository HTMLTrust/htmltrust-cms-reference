# HTMLTrust CMS Reference

Reference CMS plugin for server-side content signing with HTMLTrust. Embeds cryptographic signatures into published content so that browsers and crawlers can verify authorship and integrity.

This is a companion to the [HTMLTrust specification](https://github.com/HTMLTrust/htmltrust-spec).

## What It Does

When an author publishes content, the plugin:

- **Normalizes** the content (strips markup, collapses whitespace) and computes a SHA-256 content hash
- **Signs** the hash via the HTMLTrust trust directory API using the author's private key
- **Embeds** the signature, author public key reference, and content hash into the published HTML
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

### Prerequisites

- WordPress 5.0+
- PHP 7.0+
- A running [HTMLTrust trust directory server](https://github.com/HTMLTrust/htmltrust-server-reference)

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
bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer test
```

## The HTML Protocol

Signed content is embedded using data attributes that the browser extension recognizes:

```html
<signed-section keyid="did:web:author.example"
    signature="BASE64_SIG" algorithm="ed25519"
    content-hash="sha256:abc123...">
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
