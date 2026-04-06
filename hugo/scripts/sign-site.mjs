#!/usr/bin/env node

/**
 * HTMLTrust Hugo post-build signing script.
 *
 * Processes Hugo build output, computes content hashes, calls the
 * HTMLTrust trust directory API to sign them, and injects signature
 * markup into the HTML files.
 *
 * Usage:
 *   node scripts/sign-site.mjs --dir public
 *
 * Environment variables:
 *   HTMLTRUST_API_URL          - Trust directory server URL (default: http://localhost:3000)
 *   HTMLTRUST_AUTHOR_API_KEY   - Author API key for signing
 *   HTMLTRUST_AUTHOR_ID        - Author ID on the trust directory
 *   HTMLTRUST_DOMAIN           - Domain for signatures (default: from baseURL in hugo config)
 */

import { readFileSync, writeFileSync, readdirSync, statSync } from "fs";
import { join, resolve } from "path";
import { createHash } from "crypto";

const API_URL = process.env.HTMLTRUST_API_URL || "http://localhost:3000";
const AUTHOR_API_KEY = process.env.HTMLTRUST_AUTHOR_API_KEY || "";
const AUTHOR_ID = process.env.HTMLTRUST_AUTHOR_ID || "";

// Parse CLI args
const args = process.argv.slice(2);
let outputDir = "public";
let selector = "article";
let domain = process.env.HTMLTRUST_DOMAIN || "www.htmltrust.org";

for (let i = 0; i < args.length; i++) {
  if (args[i] === "--dir" && args[i + 1]) outputDir = args[++i];
  if (args[i] === "--selector" && args[i + 1]) selector = args[++i];
  if (args[i] === "--domain" && args[i + 1]) domain = args[++i];
}

/**
 * Recursively find all HTML files in a directory.
 */
function findHtmlFiles(dir) {
  const results = [];
  for (const entry of readdirSync(dir)) {
    const fullPath = join(dir, entry);
    const stat = statSync(fullPath);
    if (stat.isDirectory()) {
      results.push(...findHtmlFiles(fullPath));
    } else if (entry.endsWith(".html")) {
      results.push(fullPath);
    }
  }
  return results;
}

/**
 * Extract text content from an HTML string by stripping tags.
 * This is a simple implementation — matches the canonicalization
 * used by the WordPress and browser reference implementations.
 */
function canonicalize(html) {
  return html
    .replace(/<[^>]*>/g, "") // Strip HTML tags
    .replace(/\s+/g, " ") // Collapse whitespace
    .trim();
}

/**
 * Compute SHA-256 hash of canonical content.
 */
function hashContent(text) {
  const hash = createHash("sha256").update(text, "utf8").digest("hex");
  return `sha256:${hash}`;
}

/**
 * Extract content between the first matching open and close tags for
 * the configured selector element.
 * Returns null if no matching element is found.
 */
function extractArticle(html) {
  const openTag = `<${selector}`;
  const closeTag = `</${selector}>`;
  const start = html.indexOf(openTag);
  if (start === -1) return null;

  const end = html.indexOf(closeTag, start);
  if (end === -1) return null;

  // Include the closing tag
  return html.substring(start, end + closeTag.length);
}

/**
 * Call the HTMLTrust trust directory API to sign a content hash.
 */
async function signWithApi(contentHash, claims = {}) {
  if (!AUTHOR_API_KEY || !AUTHOR_ID) {
    console.warn(
      "  ⚠ No API key or author ID configured — skipping API signing",
    );
    return null;
  }

  try {
    const response = await fetch(`${API_URL}/api/content/sign`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-AUTHOR-API-KEY": AUTHOR_API_KEY,
      },
      body: JSON.stringify({
        contentHash,
        domain,
        claims,
      }),
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.warn(`  ⚠ API error (${response.status}): ${errorText}`);
      return null;
    }

    return await response.json();
  } catch (err) {
    console.warn(`  ⚠ API request failed: ${err.message}`);
    return null;
  }
}

/**
 * Build the signature HTML block to inject.
 */
function buildSignatureHtml(result, contentHash) {
  const pubKeyUrl = `${API_URL}/api/authors/${AUTHOR_ID}/public-key`;

  let innerMeta = "";

  // Timestamp
  if (result.createdAt) {
    innerMeta += `\n  <meta name="signed-at" content="${result.createdAt}">`;
  }

  // Claims
  if (result.claims && typeof result.claims === "object") {
    for (const [key, value] of Object.entries(result.claims)) {
      innerMeta += `\n  <meta name="claim:${key}" content="${value}">`;
    }
  }

  return `
<signed-section
    signature="${result.signature}"
    keyid="${pubKeyUrl}"
    algorithm="ed25519"
    content-hash="${contentHash}"
    style="display: block;">${innerMeta}
</signed-section>`;
}

/**
 * Process a single HTML file.
 */
async function processFile(filePath) {
  const html = readFileSync(filePath, "utf8");
  const articleHtml = extractArticle(html);

  if (!articleHtml) return false;

  const canonical = canonicalize(articleHtml);
  if (!canonical || canonical.length < 10) return false; // Skip trivial content

  const contentHash = hashContent(canonical);
  console.log(`  Hash: ${contentHash.substring(0, 20)}...`);

  const result = await signWithApi(contentHash);
  if (!result) {
    // Even without API, inject the hash for future signing
    const placeholder = `\n<signed-section content-hash="${contentHash}" style="display: block;"></signed-section>`;
    const closeTag = `</${selector}>`;
    const insertPos = html.indexOf(closeTag, html.indexOf(`<${selector}`));
    if (insertPos !== -1) {
      const newHtml =
        html.substring(0, insertPos + closeTag.length) +
        placeholder +
        html.substring(insertPos + closeTag.length);
      writeFileSync(filePath, newHtml, "utf8");
    }
    return true;
  }

  const signatureHtml = buildSignatureHtml(result, contentHash);
  const closeTag = `</${selector}>`;
  const insertPos = html.indexOf(closeTag, html.indexOf(`<${selector}`));
  if (insertPos !== -1) {
    const newHtml =
      html.substring(0, insertPos + closeTag.length) +
      signatureHtml +
      html.substring(insertPos + closeTag.length);
    writeFileSync(filePath, newHtml, "utf8");
  }

  return true;
}

// Main
async function main() {
  const dir = resolve(outputDir);
  console.log(`\nHTMLTrust Hugo Signing`);
  console.log(`  Output dir: ${dir}`);
  console.log(`  Domain: ${domain}`);
  console.log(`  API: ${API_URL}`);
  console.log(`  Author: ${AUTHOR_ID || "(not configured)"}\n`);

  const files = findHtmlFiles(dir);
  console.log(`Found ${files.length} HTML files\n`);

  let signed = 0;
  for (const file of files) {
    const rel = file.replace(dir + "/", "");
    process.stdout.write(`Processing ${rel}...`);
    const ok = await processFile(file);
    if (ok) {
      signed++;
      console.log(" ✓");
    } else {
      console.log(" (no article content)");
    }
  }

  console.log(`\nDone. Processed ${signed}/${files.length} files.\n`);
}

main().catch((err) => {
  console.error("Fatal error:", err);
  process.exit(1);
});
