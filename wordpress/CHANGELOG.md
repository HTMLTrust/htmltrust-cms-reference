# Changelog

All notable changes to the Content Signing for WordPress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-28

### Added
- Browser-local Ed25519 signing with non-extractable IndexedDB keys
- Two-pass server-rendered payload preparation and PHP verification
- Public local-key resolution through the WordPress REST API
- Durable queue entries for scheduled and headless posts
- Resolver-compatible SPKI public-key documents and rendered-byte drift checks
- Browser-local author profiles with server ID 0, deterministic local identity,
  and no API key requirement
- One-time prepare tokens and immutable key ID to public key bindings

### Changed
- Publication hooks no longer call the remote trust-server signing endpoint
- WebAuthn is documented as a separate artifact from HTMLTrust payload signatures.
- Only the WordPress post author can prepare or complete browser-local signing.
- Legacy server-side signing and endorsement execution are disabled.

## [1.0.0] - 2025-05-05

### Added
- Initial release of the Content Signing for WordPress plugin
- Server profiles management
- Author profiles management
- Flexible signing options (on publish, update, before/after publish)
- Site-wide endorsements
- Custom claims support
- Signature verification
- Signature embedding in HTML content
- WordPress admin interface
- Post meta box for per-post settings
- PHPUnit testing framework
- Comprehensive documentation

## [0.9.0] - 2025-04-15

### Added
- Beta release for testing
- Core functionality implemented
- Basic admin interface
- Integration with Content Signing API

### Known Issues
- Scheduled signing not fully implemented
- Limited error handling
- No internationalization support

## [0.8.0] - 2025-03-20

### Added
- Alpha release for internal testing
- Database schema implemented
- API client implemented
- Basic signing service implemented

### Known Issues
- Admin interface incomplete
- No test coverage
- Limited documentation
