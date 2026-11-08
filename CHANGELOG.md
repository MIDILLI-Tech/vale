# Changelog

All notable changes to Vale are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- plugin drop-in system: `content/plugins/<slug>/plugin.php` manifests and always-on `content/mu-plugins/*.php`, loaded after the container and before routes
- `php vale plugin:list`, `plugin:install`, `plugin:check-updates`, `plugin:update`, `plugin:enable` and `plugin:disable` commands
- `/admin/plugins` screen (System → Plugins, `system.plugins`) with activation toggle, version requirements and last recorded error
- global plugin switch on `/admin/plugins` (`plugins_enabled` setting, on by default): off skips every plugin and mu-plugin, 404s their assets, hides the sidebar entry and keeps per-plugin activation state; the screen stays reachable from Settings → Features with an Off badge and warning
- `/plugin-assets/{slug}/{path}` route serving enabled plugins' `assets/` through a default-deny extension whitelist with path containment
- plugin-to-plugin dependencies: `requires_plugin` in `plugin.php` (`['acme-core' => '^1.0']`) — enabling requires the dependency to be installed, enabled and version-compatible, and the loader re-checks it against the plugins it actually loaded
- plugin manifest metadata: `description`, `license` and `homepage` (http/https only, shown under the plugin name) plus `requires_ext` alongside `requires_vale` / `requires_php` — an unmet extension now skips the plugin instead of failing at runtime
- `plugin_asset_url()` helper for plugin-owned styles, scripts and fonts
- plugin-owned migrations (`content/plugins/<slug>/migrations/`) with applied tracking in the `plugin_migrations` setting
- `php vale plugin:migrate [slug] [--dry-run]`
- Plugins card (with a "Manage plugins" link) on Settings → General → Features, shown to users with `system.plugins`
- plugin list shows pending migration count per plugin and the outcome of the last migration run (applied/reverted count or failure message)
- plugin ZIP installation from `/admin/plugins` and `php vale plugin:install`: safe extraction, manifest validation, slug derivation and disabled install; existing plugin directories are never overwritten, and must-use drop-ins are clearly filesystem-only
- plugin update feeds: an optional `updates` URL in `plugin.php` plus `/admin/plugins` **Check for plugin updates** / per-plugin **Update** actions, with SHA-256 verification, Vale/PHP/plugin requirement checks, automatic backup and migration; applying an update is explicit
- automatic plugin update checks through cron (at most once every 24 h, gated by the software-update settings) and a dashboard **Plugin updates** status notice; plugin updates are never auto-applied
- `front_page_title` filter for plugins that change the front-end document title
- optional `'settings' => '/admin/...'` manifest link shown as a Settings button and clickable plugin title on the plugin list, plus a **Website** button when `homepage` is declared
- plugin list search and pagination, following `CLIENT_SIDE_SEARCH_FETCH_LIMIT` for browser-side filtering/paging and falling back to server-side slicing for larger lists
- must-use drop-ins are listed as **Must-Use** rows in the same plugin table (search/pagination included) with a disabled Delete action instead of a separate card
- optional header metadata for must-use drop-ins (`Plugin Name`, `Version`, `Description`, `License`, `Website`) fills the same columns as regular plugins
- license and website labels on the plugin list and a `Vale Version:` badge, with inline CLI examples
- install and last-update dates under the migrations column, recorded in the `plugin_lifecycle` setting and falling back to file timestamps for existing plugins
- column visibility picker on the plugin list, with the plugin name column locked and the bulk-select column hidden
- **Delete** action on the plugin list: removes the plugin files only, or with **Include settings and data** rolls back applied migrations and removes `plugin:<slug>:*` settings, activation, failure, update cache and migration records
- reversible plugin migrations: optional `down` callable plus `php vale plugin:migrate --rollback [--all] <slug>` (newest first, dry-run supported)
- `admin_nav_groups` filter so plugins can add their own sidebar entries
- declarative admin sidebar entry in `plugin.php` (`admin` block: label, href, group, permission, icon), injected automatically while the plugin is enabled; top-level entries are drag-sortable and their position is kept in `admin_nav_order`
- theme template editor warns when a bundled theme (`default`, `dark`, `nova`, `vivid`) is edited, since updates replace its files — use a child theme instead

### Changed
- enabling or disabling a plugin flushes the full-page cache, so guest pages never keep rendering a plugin that was just switched off
- upgrades add the `system.plugins` permission and grant it to `super_admin`/`admin`, so plugin management works on existing installations without a manual role edit
- plugin migrations run automatically on activation (CLI + admin toggle) and on every cron run, so new schema arrives without a request executing DDL
- updates treat `content/` as a protected path; plugin code, activation and migration records survive every release
- Docker images create and mount `content/` (`vale-content` volume) and the installer checks both plugin directories
- bundled themes stay release-owned: user themes are never in the update package, customizations belong in a child theme

### Security
- plugin assets are never served for disabled plugins, blocked by an extension whitelist (no PHP/HTML), and protected against traversal, dotfiles and symlink escapes
- plugin load failures are isolated and rate-limited in the `plugin_failures` setting, with a `.disabled` / `VL_DISABLE_PLUGINS` kill switch checked before any plugin file is included

### Documentation
- developer and admin guides for plugins, the `vale-plugin` agent skill, and `plugin:*` CLI reference

## [0.60.584] - 2026-09-02

### Added
- demo release build entry

## [0.10.4] - 2026-08-21

Updated release notes

### Added
- commit message convention to AGENTS.md and pre-commit hook
- multi-language fallback content notice with Nova theme popup
- multi-language fallback content notice system
- fix card builder layout and responsive multi-column stacking
- add flexible multi-column field layout support to forms and CPTs
- add PostgreSQL support and Docker Compose database profiles
- add PostgreSQL database support and dynamic version requirement checks
- add PostgreSQL database support and version requirement checks
- add PostgreSQL database support
- remove hardcoded fallbacks and enforce strict requirements config
- enhance EOL audit script to check configured DB minimum versions in CI/CD without active connection
- include SQLite in EOL audit tool and support modern database LTS
- update database requirements to MySQL 8.4 and MariaDB 11.8 with validation & EOL audit
- update database requirements to MySQL 8.4 & MariaDB 11.8 with EOL audit tool
- completely unify and secure WordPress, Shopify, Etsy, eBay, and Amazon importers
- secure Shopify, Etsy, eBay, and Amazon product importers
- robust Shopify, Etsy, eBay, and Amazon product importers
- Add multi-language translation and fallback support for Forms
- support importing products from Shopify, Etsy, eBay, and Amazon
- add shopify, etsy, ebay, and amazon product importers
- upgrade system requirements to PHP 8.3 and centralize PHP extensions config
- implement cookies-less bounce/staytime, entry/exit, UTM analytics, and subscription coupons
- implement bounce/staytime analytics and coupons for membership plans
- add WordPress Polylang import support and flexible combinable CLI switches
- add WordPress Polylang import support and modular CLI filters
- add custom Link Button editor block and shortcode rendering
- make feature card title areas fully clickable for expansion
- add light mode toggle and collapsible feature cards in wizard
- move migrate-urls to vale cli and improve logic
- add image carousel to Tiptap editor and shortcode processor
- implement comprehensive multi-language (i18n) support
- finalize Tiptap 3 migration with advanced Table Bubble Menu
- complete Tiptap 3 migration and dark mode UI optimization
- migrate Tiptap to version 3 and implement local build system
- implement dynamic routing prefixes and automatic 301 redirects
- enhance SEO system with global toggle, social previews, and media picker
- add automatic 301 redirects for slug changes
- exhaustive versioned documentation and rebranding
- exhaustive modular documentation system for Vale
- exhaustive and modular versioned documentation system
- add comprehensive and modular documentation system
- comprehensive documentation system for Vale
- complete and modular documentation system for Vale
- finalize comprehensive and modular documentation system
- add modular and highly detailed documentation system
- add comprehensive versioned documentation system
- refine documentation with detailed guides and user-friendly installation
- add versioned, multi-language documentation system
- add versioned documentation system and fix ESM error
- add versioned documentation system using VitePress
- add product sorting and stock filtering to shop storefront

### Fixed
- retain wizard admin account during sample data seeding
- add missing SettingsRepositoryInterface imports
- support reverse proxy https detection in installer
- resolve bubble menu visibility and editor stats dark mode issues
- resolve Tiptap 3 duplicate extensions and dark mode UI issues
- resolve cpt permissions, access control, and ui refinements
- resolve cpt permissions, access control, and ui inconsistencies
- enhance cpt access control and sidebar visibility
- ensure cpt permissions are registered and respected
- correctly detect changed files in GHA pull requests
- improve page cache invalidation for product sold counts
- enable table dragging and resolve CI failures
- enable table dragging by preventing fixed-width overrides

### Security
- harden flash messages, redirects, and password hashing

### Changed
- Reduce vale-env volume size to 100Ki
- Update captain-definition for CapRover persistent volumes
- normalize line endings to LF via .gitattributes
- switch settings to domain interface, add archive noindex

### Added

### Fixed

### Security

### Changed

<!-- manual entries below -->
First automated release
<!-- MANUAL NOTE: This release requires PHP 8.3+ -->
> **Important:** Before updating, please ensure your server meets the minimum PHP 8.3 requirement.
<!-- end manual note -->

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
