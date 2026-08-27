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
 *   HTMLTRUST_DOMAIN           - Serialized publication origin for signatures
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
let domain = process.env.HTMLTRUST_DOMAIN || "https://www.htmltrust.org";

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
 * Convert the configured domain value to the serialized Web origin bound into
 * the legacy-named domain field.
 */
function normalizeOrigin(value) {
  const candidate = /^[a-z][a-z0-9+.-]*:/i.test(value)
    ? value
    : `https://${value}`;
  const url = new URL(candidate);
  url.hash = "";
  url.search = "";
  url.pathname = "";
  url.username = "";
  url.password = "";
  return url.origin;
}

/**
 * Extract signed semantic attributes before stripping markup.
 */
function canonicalize(html, baseUrl) {
  const excluded = [
    "script",
    "style",
    "template",
    "noscript",
    "iframe",
    "meta",
  ];
  const blockElements = [
    "address",
    "article",
    "aside",
    "blockquote",
    "details",
    "dialog",
    "div",
    "dl",
    "fieldset",
    "figcaption",
    "figure",
    "footer",
    "form",
    "h1",
    "h2",
    "h3",
    "h4",
    "h5",
    "h6",
    "header",
    "hgroup",
    "hr",
    "li",
    "main",
    "nav",
    "ol",
    "p",
    "pre",
    "section",
    "table",
    "td",
    "th",
    "tr",
    "ul",
  ];

  let canonical = html;
  for (const name of excluded) {
    canonical = canonical.replace(
      new RegExp(`<${name}\\b[^>]*>[\\s\\S]*?<\\/${name}>`, "gi"),
      "",
    );
    canonical = canonical.replace(new RegExp(`<${name}\\b[^>]*>`, "gi"), "");
  }

  canonical = canonical.replace(
    /<([a-zA-Z][a-zA-Z0-9:-]*)(\s[^>]*)?>/g,
    (match, rawName, rawAttrs = "") => {
      const name = rawName.toLowerCase();
      const attrs = parseAttributes(rawAttrs);
      const records = ["href", "src", "alt", "aria-label"]
        .filter((attribute) => Object.prototype.hasOwnProperty.call(attrs, attribute))
        .map((attribute) => {
          const value =
            attribute === "href" || attribute === "src"
              ? normalizeUrlAttribute(attrs[attribute], baseUrl)
              : normalizeText(attrs[attribute]);
          return `@attr:${name}:${attribute}:${value}\n`;
        })
        .join("");
      return `${records}${name === "br" ? "\n" : ""}`;
    },
  );

  canonical = canonical.replace(/<\/([a-zA-Z][a-zA-Z0-9:-]*)>/g, (match, rawName) =>
    blockElements.includes(rawName.toLowerCase()) ? "\n" : "",
  );

  return normalizeCanonical(canonical);
}

/**
 * Parse quoted and unquoted HTML attributes from a start tag.
 */
function parseAttributes(rawAttrs) {
  const attrs = {};
  const attrPattern =
    /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
  let match;
  while ((match = attrPattern.exec(rawAttrs))) {
    attrs[match[1].toLowerCase()] = match[2] ?? match[3] ?? match[4] ?? "";
  }
  return attrs;
}

function normalizeUrlAttribute(value, baseUrl) {
  return new URL(decodeHtml(value.trim()), baseUrl).href.replace(/\n/g, " ");
}

function normalizeText(value) {
  return value.normalize("NFKC").replace(/\s+/g, " ").trim();
}

function normalizeCanonical(value) {
  return decodeHtml(value)
    .split(/(@attr:[^\n]*\n)/g)
    .map((part) => {
      if (part.startsWith("@attr:")) return part;
      return part
        .split(/\n+/)
        .map((line) => normalizeText(line))
        .filter(Boolean)
        .join("\n");
    })
    .join("")
    .replace(/[ \t]+/g, " ")
    .replace(/[ \t]*\n[ \t]*/g, "\n")
    .replace(/\n{2,}/g, "\n")
    .trim();
}

function decodeHtml(value) {
  return value
    .replace(/&nbsp;/gi, " ")
    .replace(/&amp;/gi, "&")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/g, "'");
}

/**
 * Compute SHA-256 hash of canonical content.
 */
function hashContent(text) {
  const hash = createHash("sha256")
    .update(text, "utf8")
    .digest("base64")
    .replace(/=+$/g, "");
  return `sha256:${hash}`;
}

function compareByCodePoint(left, right) {
  const a = Array.from(left);
  const b = Array.from(right);
  const length = Math.min(a.length, b.length);
  for (let i = 0; i < length; i++) {
    const difference = a[i].codePointAt(0) - b[i].codePointAt(0);
    if (difference !== 0) return difference;
  }
  return a.length - b.length;
}

function canonicalizeClaims(claims) {
  return Object.entries(claims)
    .map(([name, value]) => [normalizeText(name), normalizeText(String(value))])
    .sort(([left], [right]) => compareByCodePoint(left, right))
    .map(([name, value]) => `${name}:${value}\n`)
    .join("");
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
 * Extract an existing signed-section wrapper if the Hugo partial already ran.
 */
function extractSignedSection(html) {
  const match = html.match(/<signed-section\b[^>]*>[\s\S]*?<\/signed-section>/i);
  if (!match) return null;

  const wrapper = match[0];
  const openEnd = wrapper.indexOf(">");
  return {
    start: match.index,
    end: match.index + wrapper.length,
    inner: wrapper.substring(openEnd + 1, wrapper.length - "</signed-section>".length),
  };
}

/**
 * Build a direct meta claim map from a signed-section body.
 */
function extractClaims(innerHtml) {
  const claims = {};
  const metaPattern = /<meta\b([^>]*)>/gi;
  let match;

  while ((match = metaPattern.exec(innerHtml))) {
    const attrs = parseAttributes(match[1]);
    if (attrs.name && Object.prototype.hasOwnProperty.call(attrs, "content")) {
      claims[normalizeText(attrs.name)] = normalizeText(attrs.content);
    }
  }

  return claims;
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
        claimsHash: hashContent(canonicalizeClaims(claims)),
        domain,
        signedAt: claims["signed-at"],
        claims,
      }),
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.warn(`  ⚠ API error (${response.status}): ${errorText}`);
      return null;
    }

    const result = await response.json();
    if (!result?.signature || !result?.keyid || !result?.algorithm) {
      console.warn("  ⚠ API response omitted signature, keyid, or algorithm");
      return null;
    }
    return result;
  } catch (err) {
    console.warn(`  ⚠ API request failed: ${err.message}`);
    return null;
  }
}

/**
 * Build the signature HTML block to inject.
 */
function buildSignedSectionOpen(result, contentHash) {
  const keyid =
    result.keyid ||
    result.keyId ||
    result.publicKeyUrl ||
    `${API_URL}/api/authors/${AUTHOR_ID}/public-key`;

  return `<signed-section signature="${escapeAttr(result.signature)}" keyid="${escapeAttr(
    keyid,
  )}" algorithm="${escapeAttr(result.algorithm)}" content-hash="${escapeAttr(contentHash)}">`;
}

function buildUnsignedSectionOpen(contentHash) {
  return `<signed-section content-hash="${escapeAttr(contentHash)}">`;
}

function buildClaimMeta(claims) {
  return Object.entries(claims)
    .map(
      ([name, content]) =>
        `<meta name="${escapeAttr(name)}" content="${escapeAttr(content)}">`,
    )
    .join("");
}

function escapeAttr(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

/**
 * Process a single HTML file.
 */
async function processFile(filePath) {
  const html = readFileSync(filePath, "utf8");
  const existingSection = extractSignedSection(html);
  const articleHtml = existingSection ? existingSection.inner : extractArticle(html);

  if (!articleHtml) return false;

  const relUrl = filePath
    .replace(resolve(outputDir), "")
    .replace(/\\/g, "/")
    .replace(/\/index\.html$/, "/");
  const baseUrl = new URL(relUrl || "/", `${domain}/`).href;
  const canonical = canonicalize(articleHtml, baseUrl);
  if (!canonical || canonical.length < 10) return false; // Skip trivial content

  const contentHash = hashContent(canonical);
  console.log(`  Hash: ${contentHash.substring(0, 20)}...`);

  const claims = existingSection ? extractClaims(existingSection.inner) : {};
  const result = await signWithApi(contentHash, claims);
  const openTag = result
    ? buildSignedSectionOpen(result, contentHash)
    : buildUnsignedSectionOpen(contentHash);

  if (existingSection) {
    const newHtml =
      html.substring(0, existingSection.start) +
      openTag +
      existingSection.inner +
      "</signed-section>" +
      html.substring(existingSection.end);
    writeFileSync(filePath, newHtml, "utf8");
    return true;
  }

  const closeTag = `</${selector}>`;
  const start = html.indexOf(`<${selector}`);
  const insertPos = html.indexOf(closeTag, start);
  if (start !== -1 && insertPos !== -1) {
    const article = html.substring(start, insertPos + closeTag.length);
    const newHtml =
      html.substring(0, start) +
      openTag +
      buildClaimMeta(claims) +
      article +
      "</signed-section>" +
      html.substring(insertPos + closeTag.length);
    writeFileSync(filePath, newHtml, "utf8");
  }

  return true;
}

// Main
async function main() {
  domain = normalizeOrigin(domain);
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
