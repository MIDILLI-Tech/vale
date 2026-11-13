# Changelog

All notable changes to Vale are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versions follow [Semantic Versioning](https://semver.org/).

## [0.77.4] - 2026-11-13

A maintenance release which fixes some caching issues with minor improvements.

### Added
- invalidate page cache and fire webhooks when cron publishes content
- shop categories are now listed in menus for selection
- press enter to quickly accept the entered link in link modal

### Fixed
- shop categories not showing in the menus
- drop the page cache when content is deleted, commented or re-taxonomised
- menu changes not clearing cache for non-logged users
- attach imported media to an existing user instead of user id 1
- etsy importer failes to import some variations
- if homepage is shop controller do not use home controller
- importer causing warnings for multiple image variations
- missing tables fails import completely on WordpressImporter
- media+link block does not remember link text
- spacing before and after images and inline code ticks

### Changed
- sort email forms
- bump version

### Documentation
- link the documentation site and online demo in the README

## [0.10.2] - 2026-05-17

### Security
- Hardened path traversal protection in static file serving (`index.php`)
- Fixed PHP object injection vulnerability in `FileCache` — `unserialize` now uses `allowed_classes: false`
- Upload MIME type is now verified via `finfo_open` instead of trusting the client-supplied `$_FILES['type']`
- SVG uploads: content sniffed to confirm real SVG before accepting `image/svg+xml`

### Added
- Auto-update pipeline: `UpdateService`, `BackupService`, `MaintenanceMode`, `SignatureVerifier`, `PreUpdateChecker`
- Cron-based update checks via `CronService`
- Settings UI: auto-check / auto-apply controls with dependency warning
- Release signing with Ed25519 via `scripts/prepare-release.php --generate-key`
- Pre-commit hook to block accidental secret commits (`.githooks/pre-commit`)
- Manual release workflow (`release-manual.yml`) with `workflow_dispatch`
