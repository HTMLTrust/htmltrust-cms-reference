# HTMLTrust Hugo Integration

This directory contains tools for integrating HTMLTrust content signing with [Hugo](https://gohugo.io/) static sites.

## How It Works

A Hugo partial wraps page content in `<signed-section>` and emits direct child claim metadata during the normal `hugo build`.

For content hashing and full cryptographic signing (binding content to an author's private key via a trust directory), run the post-build script. The script computes the spec wire hash as `sha256:<unpadded standard Base64>`, fills in `signature` and `keyid`, and preserves the signed wrapper.

## Quick Start

### 1. Copy the partial into your Hugo project

Copy `layouts/partials/htmltrust-signed-section.html` and `layouts/partials/htmltrust-meta.html` into your Hugo project's `layouts/partials/` directory.

### 2. Include the partial in your templates

In your content template (e.g., `layouts/_default/single.html`), **replace** `{{ .Content }}` with the partial (it wraps the content inside a `<signed-section>` element):

```html
{{ partial "htmltrust-signed-section.html" . }}
```

The partial outputs `{{ .Content }}` internally, wrapped in the `<signed-section>` element. If signing is not enabled for a page, it falls through to plain `{{ .Content }}`.

Optionally, include the meta partial in your `<head>` (e.g., via a `head-additions.html` override):

```html
{{ partial "htmltrust-meta.html" . }}
<style>signed-section { display: block; }</style>
```

### 3. Add signing metadata to content frontmatter

```yaml
---
title: "My Blog Post"
htmltrust:
  sign: true
  claims:
    ContentType: "Article"
    License: "CC-BY-4.0"
    AIAssistance: "None"
---
```

### 4. Build

```sh
hugo --minify
```

Every page with `htmltrust.sign: true` will have its content wrapped in a `<signed-section>` element with:
- Inner `<meta>` tags for author, timestamp, and claims
- The actual page content

Run the post-build script to add the required `content-hash`, `signature`, `keyid`, and `algorithm` attributes.

## What Gets Generated

```html
<signed-section>
  <meta name="author" content="Jason Grey">
  <meta name="signed-at" content="2025-05-12T10:30:00Z">
  <meta name="claim:ContentType" content="Article">
  <meta name="claim:License" content="CC-BY-4.0">
  <meta name="claim:AIAssistance" content="None">
  <h1>My Blog Post</h1>
  <p>The actual page content goes here, wrapped by the signed-section...</p>
</signed-section>
```

## Optional: API-Based Cryptographic Signing

To add spec-conformant content hashes and full cryptographic signatures, use the post-build script after `hugo build`:

Copy `scripts/sign-site.mjs` from this integration into your Hugo project's `scripts/` directory first. The script uses only Node.js built-ins and needs no package install.

```sh
hugo --minify
node scripts/sign-site.mjs --dir public
```

This requires a running [HTMLTrust trust directory server](https://github.com/HTMLTrust/htmltrust-server-reference) and these environment variables:

```sh
export HTMLTRUST_API_URL=http://localhost:3000
export HTMLTRUST_AUTHOR_API_KEY=your_author_api_key
export HTMLTRUST_AUTHOR_ID=your_author_id
export HTMLTRUST_DOMAIN=https://yourdomain.com
```

The script finds existing `<signed-section>` elements (already wrapping the content from the Hugo build) and adds or replaces the `content-hash`, `signature`, `keyid`, and `algorithm` attributes. If a page was not built with the partial, the script wraps the selected element instead of appending a detached marker.

## Files

```
hugo/
├── layouts/partials/
│   ├── htmltrust-signed-section.html   # Wraps selected content in <signed-section>
│   └── htmltrust-meta.html             # Optional: emits <meta> tags in <head>
├── scripts/
│   └── sign-site.mjs                   # Computes hashes and requests API signatures
└── README.md
```

## Canonicalization

The post-build script canonicalizes content by:
1. Excluding claim and executable elements such as `meta`, `script`, `style`, and `iframe`
2. Including signed semantic attributes: `href`, `src`, `alt`, and `aria-label`
3. Collapsing whitespace and computing a SHA-256 digest
4. Encoding the digest as canonical unpadded standard Base64
