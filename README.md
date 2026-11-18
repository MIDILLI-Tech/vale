<p align="center">
  <img src="./public/assets/img/vale-gray.png" alt="Vale" width="400">
</p>

<h1 align="center">Vale</h1>

<p align="center">
  <strong>Lightweight PHP CMS &amp; E-Commerce Platform</strong>
  <br>
  Self-hosted, open source, and bloat-free — clean architecture, security by default, and performance in mind.
  <br><br>
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/MySQL-8.4+-4479A1?logo=mysql&logoColor=white" alt="MySQL 8.4+">
  <img src="https://img.shields.io/badge/PostgreSQL-18.0+-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL 18+">
  <img src="https://img.shields.io/badge/SQLite-003B57?style=flat-square&logo=SQLite&logoColor=white" alt="SQLite">
  <img src="https://img.shields.io/badge/license-AGPL_3.0-green" alt="AGPL License">
  <img src="https://img.shields.io/badge/version-0.78.0-blue" alt="Version 0.78.0">
</p>

<p align="center">
  <a href="https://docs.getvale.org/"><strong>Documentation</strong></a> ·
  <a href="https://getvale.org/try-vale"><strong>Live demo</strong></a> ·
  <a href="https://github.com/MIDILLI-Tech/vale/releases"><strong>Releases</strong></a> ·
  <a href="https://github.com/MIDILLI-Tech/vale/issues"><strong>Issues</strong></a>
</p>

> **No plugins required.** Shop, newsletters, memberships, SEO, and AI-crawler control all ship in core — readable code you can override, with no third-party plugin dependencies.

---

## Why Vale

- **One bundle, no plugin tax.** Blog, shop, newsletter, forms, memberships and SEO are the same codebase. Nothing to license, nothing to update separately, no plugin that breaks on a minor release.
- **Your database, your choice.** The same schema and queries run on MySQL/MariaDB, PostgreSQL and SQLite (Doctrine DBAL, no ORM magic). Start on SQLite, move to MySQL without a rewrite.
- **Updates you can trust.** Releases are signed (Ed25519, GPG as an alternative), verified before unpacking, backed up first, and roll-backable — with a web installer and a `doctor` command for everything else.
- **A plugin model that can't silently own your server.** Drop-in plugins declare the PHP capabilities they use (`env`, `network`, `filesystem_write`, `code_execution`, …); a static scanner refuses to enable anything that hides one.
- **Built for small machines.** Full-page cache, warm-up command, page-cache invalidation on publish, and a ~40-command CLI. Runs comfortably on 512 MB RAM.

---

## What's inside

| Area | Included | Docs |
|---|---|---|
| **Content** | Posts, pages, custom content types, categories, tags, revisions, scheduling, menus | [Content](https://docs.getvale.org/en/latest/admin/content/) |
| **Editor** | Block editor (Tiptap 3) with 40+ blocks, slash menu, tables, media embeds, HTML source round-trip | [Editor](https://docs.getvale.org/en/latest/admin/content/editor/) |
| **Shop** | Simple, variable and digital products, variations, downloads, coupons, carts, orders, refunds, tracking, abandoned carts | [E-Commerce](https://docs.getvale.org/en/latest/admin/ecommerce/) |
| **Payments** | Stripe, PayPal, Mollie, 2Checkout, Checkout.com — redirect and webhook flows | [Gateways](https://docs.getvale.org/en/latest/developer/payment-gateways) |
| **Tax & currency** | VAT-inclusive gross pricing, per-country tax rates, multi-currency with exchange rates | [Shop](https://docs.getvale.org/en/latest/admin/ecommerce/) |
| **Membership** | Plans, recurring subscriptions, access control, payment reconciliation | [Membership](https://docs.getvale.org/en/latest/admin/ecommerce/) |
| **Newsletter** | Campaigns, automation sequences, segmentation, A/B testing, recurring digests, subscribers | [Marketing](https://docs.getvale.org/en/latest/admin/marketing/) |
| **Forms** | Drag-and-drop form builder, conditional fields, submissions, embeddable forms, external POST endpoint | [Forms](https://docs.getvale.org/en/latest/admin/forms) |
| **Media** | Library with drag-and-drop upload, thumbnails, WebP renditions, video posters, scan command | [Media](https://docs.getvale.org/en/latest/developer/media) |
| **SEO** | Meta/OG/Twitter tags, sitemap, redirects, `robots.txt` — plus per-crawler AI bot control at `/ai-robots.txt` | [Settings](https://docs.getvale.org/en/latest/admin/settings) |
| **Users & roles** | Granular RBAC, TOTP two-factor (optionally enforced), SSO with Google, Microsoft, GitHub and Facebook, customer accounts, emergency reset | [Users](https://docs.getvale.org/en/latest/admin/users) · [SSO](https://docs.getvale.org/en/latest/developer/sso) |
| **Themes** | Four bundled themes (default, dark, nova, vivid), child themes, page templates, color variables | [Themes](https://docs.getvale.org/en/latest/developer/themes) |
| **Plugins** | Manifest, declared capabilities + static scanner, migrations, assets route, ZIP install, update feeds | [Plugins](https://docs.getvale.org/en/latest/developer/plugins) |
| **API** | REST API v1 with token abilities, OpenAPI 3.1 at `/api/v1/openapi.json`, health endpoint | [REST API](https://docs.getvale.org/en/latest/developer/api) |
| **Webhooks** | Outbound delivery for orders, posts, products, users, forms and subscribers, HMAC-signed | [Webhooks](https://docs.getvale.org/en/latest/developer/webhooks) |
| **Migration** | Importers for WordPress, Ghost, Shopify, Etsy, eBay and Amazon; full export/import | [Migration](https://docs.getvale.org/en/latest/admin/migration/) |
| **Feeds** | Google product feed, Idealo, Shopify and Yandex Market product feeds | [E-Commerce](https://docs.getvale.org/en/latest/admin/ecommerce/) |
| **Operations** | Page cache + `cache:warm`, cron, backups and restore, signed updater, `doctor`, Docker images | [CLI](https://docs.getvale.org/en/latest/developer/cli) |
| **Security** | CSRF, rate limiting, AES-256-CBC credential encryption, bcrypt cost 12, SSRF guard, security headers, upload hardening | [Security](https://docs.getvale.org/en/latest/developer/security/) |

---

## Quick start

### Latest release (no Composer needed)

**[Download `vale.zip`](https://github.com/MIDILLI-Tech/vale/releases/latest/download/vale.zip)** — the pre-built archive, with `vendor/` already bundled.

```bash
curl -L -o vale.zip https://github.com/MIDILLI-Tech/vale/releases/latest/download/vale.zip
unzip vale.zip -d vale
```

Upload the extracted files, point the document root at `vale/public/`, make `storage/` and `themes/` writable, then open `https://your-domain/install.php` and follow the wizard (database, admin account, optional sample content). Nothing else to build. Full walkthrough: [Installation](https://docs.getvale.org/en/latest/admin/installation).

### Docker

```bash
# 1. Boot the Apache image on SQLite — tables are created on first start
docker run -d --name vale -p 8080:80 \
  -v vale-storage:/var/www/html/storage \
  -v vale-themes:/var/www/html/themes \
  -e APP_URL=http://localhost:8080 \
  -e APP_KEY=$(php -r "echo bin2hex(random_bytes(32));") \
  -e DB_DRIVER=sqlite \
  midillitech/vale:latest

# 2. Create your admin account
docker exec vale vale user:create --name="Admin" --email="admin@example.com" \
  --password="your-secure-password" --role=super_admin

# 3. Verify
curl -s http://localhost:8080/api/v1/health?deep=1   # → {"status":"ok",...}
docker exec vale vale doctor
```

For MySQL/MariaDB or PostgreSQL, drop `DB_DRIVER=sqlite` and pass `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`. The Nginx flavour is `midillitech/vale:nginx`.

Real-time editor collaboration is optional and runs as a separate small Hocuspocus container. Build it from [`docker/collaboration`](docker/collaboration), then enable it under **Settings → Content → Collaboration** and enter the WebSocket URL plus a shared secret ([guide](https://docs.getvale.org/en/latest/developer/collaboration)). It is not part of the released `midillitech/vale` tags — deploy it where you host the site, and publish your own image if you want one.

Let the [environment generator](https://docs.getvale.org/environment-generator.html) build the `-e` flags for you (it runs offline in the browser), and see the [Docker deployment guide](https://docs.getvale.org/en/latest/developer/deployment/docker-registry) for production, pinned digests and updates.

### From source

```bash
# 1. Clone
git clone https://github.com/MIDILLI-Tech/vale.git vale && cd vale

# 2. PHP dependencies
composer install --no-dev

# 3. Configure
cp .env.example .env       # edit DB_*, APP_URL, APP_ENV
php vale key:generate      # writes APP_KEY

# 4. Migrate + seed
php vale migrate
php vale db:seed

# 5. Point your web server's document root to public/
```

Prefer a browser wizard? Open `http://your-domain/install.php` and let the web installer write the configuration for you.

### Web server

- **Apache** — `public/.htaccess` handles rewrites (ensure `AllowOverride All`).
- **Nginx** — use the provided `nginx.conf` template.

> **Security:** always point the document root at `/public`. If your host forces the project root, apply the deny rules from the root `nginx.conf` / `.htaccess` and verify `http://yourdomain.com/.env` returns 403/404.

---

## Requirements

| | Minimum |
|---|---|
| PHP | 8.3+ |
| Database | MySQL 8.4+ / MariaDB 11.8+ / PostgreSQL 18+ / SQLite 3 |
| Composer | 2.x |
| Node.js | 26.x+ (only to rebuild assets) |
| Web server | Apache (mod_rewrite) or Nginx |

PHP extensions: `pdo_mysql`/`pdo_pgsql`/`pdo_sqlite`, `gd`, `mbstring`, `json`, `sodium`, `xml`, `intl`, `curl`, `fileinfo`, `zip`, `bcmath`.

> Vale runs comfortably on a Raspberry Pi or low-end shared hosting (512 MB RAM minimum; use swap when building assets).

---

## Configuration

Key `.env` variables:

| Variable | Description | Default |
|---|---|---|
| `APP_URL` | Application base URL | `http://localhost` |
| `APP_ENV` | `production` or `dev` | `production` |
| `APP_DEBUG` | Enable debug mode | `false` |
| `DB_DRIVER` | `mysql` / `pgsql` / `sqlite` | `mysql` |
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | Database connection | — |
| `CURRENCY` | Shop currency | `USD` |
| `LOCALE` | Admin locale (`en`, `tr`, `de`) | `en` |

---

## Development

```bash
npm install            # front-end toolchain
npm run build          # CSS, JS, editor, charts, fonts
npm run watch          # rebuild on change

php -S localhost:8089 -t public public/router.php   # built-in server (dev only)
php vale doctor                                     # environment health check
```

### Code quality & tests

```bash
composer test              # all PHPUnit suites (Unit, Integration, Feature)
composer test:unit         # unit tests only
composer lint              # PHP-CS-Fixer dry-run
composer fix               # auto-fix style
composer cs                # PHP CodeSniffer
composer lint:changed      # lint only changed files
```

Pre-commit hook (secret scan) — enable once after clone:

```bash
git config core.hooksPath .githooks
```

CI (`.github/workflows/`) runs `php -l`, PHP-CS-Fixer, PHPCS and PHPUnit on every push/PR, plus a dependency scan.

---

## Built with

| Layer | Technology |
|---|---|
| Framework | Slim 4 (PSR-7/PSR-15) |
| Language | PHP 8.3+, strict types |
| Architecture | Clean Architecture / DDD — Domain, Application, Infrastructure |
| Database | Doctrine DBAL — MySQL/MariaDB, PostgreSQL, SQLite |
| Templating | Twig 3 |
| Editor | Tiptap 3 (ProseMirror) |
| CSS / JS | Tailwind CSS 4, Alpine.js, esbuild |
| DI | PHP-DI |
| Migrations | Phinx |
| Payments | Stripe, PayPal, Mollie, 2Checkout, Checkout.com |
| CLI | Symfony Console |
| Tests | PHPUnit 12 |

---

## Contributing

Contributions are licensed under AGPL 3.0 and require a DCO sign-off. Start with [CONTRIBUTING.md](CONTRIBUTING.md) and the [contributing guide](https://docs.getvale.org/en/latest/developer/contributing).

## Security

Found a vulnerability? Please report it privately via [GitHub security advisories](https://github.com/MIDILLI-Tech/vale/security/advisories/new) instead of opening a public issue.

## License

[AGPL 3.0](LICENSE)
