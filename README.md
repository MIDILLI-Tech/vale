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
  <img src="https://img.shields.io/badge/version-0.77.3-blue" alt="Version 0.77.3">
</p>

> **No plugins required.** Shop, newsletters, memberships, SEO, and AI-crawler control all ship in core — readable code you can override, with no third-party plugin dependencies.

**Documentation:** <https://docs.getvale.org/> · **Try it online:** <https://getvale.org/try-vale>

---

## Highlights

- **CMS** — Posts, pages, custom content types, revisions, multi-language, SEO (meta/OG/sitemap/redirects), comments
- **Shop** — Products (simple/variable/digital), cart & checkout, 5 payment gateways, orders, coupons, digital downloads, subscriptions, multi-currency
- **Newsletter** — Campaigns, automations, A/B testing, segmentation, recurring digests
- **Forms** — Visual form builder with embeddable forms and external submissions
- **Media** — Library with drag-and-drop upload, thumbnails, WebP conversion
- **Users & Roles** — RBAC, 2FA, SSO (GitHub/Google/etc.), customer account area
- **Appearance** — Themes with inheritance, menus, drag-and-drop ordering
- **Plugins** — Sandboxed drop-ins with declared capabilities and a static security scanner
- **API & Integrations** — REST API v1, webhooks, product feeds, Symfony Console CLI
- **Security** — CSRF, rate limiting, encryption, security headers, file-upload hardening, GDPR tooling

---

## Requirements

| | Minimum |
|---|---|
| PHP | 8.3+ |
| Database | MySQL 8.4+ / MariaDB 11.8+ / PostgreSQL 18+ / SQLite 3 |
| Composer | 2.x |
| Node.js | 26.x+ (for building assets) |
| Web server | Apache (mod_rewrite) or Nginx |

PHP extensions: `pdo_mysql`/`pdo_pgsql`/`pdo_sqlite`, `gd`, `mbstring`, `json`, `sodium`, `xml`, `intl`, `curl`, `fileinfo`, `zip`, `bcmath`.

> Vale runs comfortably on a Raspberry Pi or low-end shared hosting (512 MB RAM minimum; use swap when building assets).

---

## Installation

```bash
# 1. Clone
git clone <repo-url> vale && cd vale

# 2. PHP dependencies
composer install --no-dev

# 3. Configure
cp .env.example .env   # edit DB_*, APP_URL, APP_ENV

# 4. Migrate + seed
php vale migrate
php vale db:seed

# 5. Point your web server's document root to public/
```

Or use the web installer: open `http://your-domain/install.php`.

### Web server

- **Apache** — `public/.htaccess` handles rewrites (ensure `AllowOverride All`).
- **Nginx** — use the provided `nginx.conf` template.

> **Security:** always point the document root at `/public`. If your host forces the project root, apply the deny rules from the root `nginx.conf` / `.htaccess` and verify `http://yourdomain.com/.env` returns 403/404.

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

## Code quality

```bash
composer lint          # PHP-CS-Fixer dry-run
composer fix           # Auto-fix style
composer cs            # PHP CodeSniffer
composer lint:changed  # Lint only changed files
```

Pre-commit hook (secret scan) — enable once after clone:

```bash
git config core.hooksPath .githooks
```

CI (`.github/workflows/lint-changed.yml`) runs `php -l`, PHP-CS-Fixer, and PHPCS on every push/PR.

---

## Built with

| Layer | Technology |
|---|---|
| Framework | Slim 4 |
| Language | PHP 8.3+, domain-driven design |
| Database | Doctrine DBAL — MySQL/MariaDB, PostgreSQL, SQLite |
| Templating | Twig 3 |
| Editor | Tiptap 3 (ProseMirror) |
| CSS / JS | Tailwind CSS 4, Alpine.js |
| DI | PHP-DI |
| Migrations | Phinx |
| Payments | Stripe, PayPal, Mollie, 2Checkout, Checkout.com |
| CLI | Symfony Console |

---

## Contributing

Contributions are licensed under AGPL 3.0 and require a DCO sign-off. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[AGPL 3.0](LICENSE)