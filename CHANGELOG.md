# Changelog

All notable changes to Vale are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versions follow [Semantic Versioning](https://semver.org/).

## [0.77.3] - 2026-11-12

Initial public pre-release of Vale CMS

### Added
- initial public version

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
