# HTMLTrust Hugo Integration

This directory contains tools for integrating HTMLTrust content signing with [Hugo](https://gohugo.io/) static sites.

## How It Works

A Hugo partial computes SHA-256 content hashes and outputs `<signed-section>` elements during the normal `hugo build` — no post-processing or external tools required for content hashing.

For full cryptographic signing (binding content to an author's private key via a trust directory), an optional post-build script can fill in `signature` and `keyid` attributes by calling the API.

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

That's it. Every page with `htmltrust.sign: true` will have its content wrapped in a `<signed-section>` element with:
- `content-hash` — SHA-256 hash of the canonicalized content
- Inner `<meta>` tags for author, timestamp, and claims
- The actual page content

## What Gets Generated

```html
<signed-section content-hash="sha256:abc123..." style="display: block;">
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

To add full cryptographic signatures (the `signature`, `keyid`, and `algorithm` attributes), use the post-build script after `hugo build`:

```sh
hugo --minify
node scripts/sign-site.mjs --dir public
```

This requires a running [HTMLTrust trust directory server](https://github.com/ArcadeLabsInc/htmltrust-server-reference) and these environment variables:

```sh
export HTMLTRUST_API_URL=http://localhost:3000
export HTMLTRUST_AUTHOR_API_KEY=your_author_api_key
export HTMLTRUST_AUTHOR_ID=your_author_id
export HTMLTRUST_DOMAIN=yourdomain.com
```

The script finds existing `<signed-section>` elements (already wrapping the content from the Hugo build) and adds the missing `signature`, `keyid`, and `algorithm` attributes.

## Files

```
hugo/
├── layouts/partials/
│   ├── htmltrust-signed-section.html   # Core: computes hash, outputs <signed-section>
│   └── htmltrust-meta.html             # Optional: emits <meta> tags in <head>
├── scripts/
│   └── sign-site.mjs                   # Optional: post-build API signing
└── README.md
```

## Canonicalization

The partial canonicalizes content by:
1. Stripping all HTML tags (Hugo's `plainify`)
2. Collapsing all whitespace to single spaces (`replaceRE`)
3. Trimming leading/trailing whitespace (`strings.TrimSpace`)
4. Computing SHA-256 hash (Hugo's `sha256`)

This matches the canonicalization used by the WordPress plugin and browser extension.