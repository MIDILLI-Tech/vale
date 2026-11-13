# Vale — Agent Guidelines

> **Project:** Vale — Lightweight PHP CMS & E-Commerce Platform  
> **PHP:** ^8.3 (strict_types=1)  
> **License:** AGPL 3.0  
> **Architecture:** Clean Architecture / Domain-Driven Design  
> **Framework:** Slim 4 (PSR-7/PSR-15)  
> **Package Name:** `vale/vale`  
> **Root Namespace:** `ValeApp\`

---

## 1. Architectural Layers (Clean Architecture)

```
src/
├── Domain/          # Business rules — zero framework dependencies
├── Application/     # Use cases — orchestrates domain objects
└── Infrastructure/  # Framework adapters, repository implementations

app/
├── Controllers/     # Slim route handlers (Admin, Front, Api)
├── Middleware/       # PSR-15 middleware
├── Commands/        # Symfony Console CLI commands
└── helpers.php      # Global helper functions

bootstrap/app.php    # App bootstrap (env, DI, middleware, routes)
bootstrap/events.php # Domain event listeners (webhooks + page-cache invalidation) shared by web and CLI
routes/              # Route definitions (web.php, admin.php, api.php)
config/              # Config files + services.php (DI container)
resources/views/     # Twig templates
public/              # Web root (only index.php)
database/            # Phinx migrations + seeds
themes/              # Theme files
tests/               # PHPUnit tests
```

### Layer Rules

- **Domain:** Never imports framework, infrastructure, or app-layer code. Only other Domain classes.
- **Application:** Uses Domain only; never calls Infrastructure directly (goes through interfaces).
- **Infrastructure:** Implements Domain interfaces. Framework dependencies are allowed here.
- **Controllers (app/):** Receives HTTP request, delegates to Application, returns response.
- **Middleware (app/):** PSR-15 — processes request/response in layers.

### Important: `app/` Layer is Separate from Domain/Application/Infrastructure

Controllers, Middleware and Commands live directly under `app/`. They depend on Domain, but Domain never depends on them.

---

## 2. Domain Layer Details

### 2.1 Entities (`src/Domain/*/Entity/`)

All entities extend `ValeApp\Domain\Shared\Entity\BaseEntity`:

```php
abstract class BaseEntity
{
    protected ?int $id                       = null;
    protected ?DateTimeImmutable $createdAt  = null;
    protected ?DateTimeImmutable $updatedAt  = null;
}
```

#### Available Entities:
- **Content:** `Post`, `Category`, `Tag`, `ContentType`, `Revision`
- **User:** `User`
- **Roles:** `Role`, `Permission`
- **Shop:** `Product`, `ProductCategory`, `ProductVote`, `Order`, `OrderLog`, `Coupon`, `CountryTaxRate`
- **Media:** `Media`
- **Menu:** `Menu`, `MenuItem`
- **Form:** `Form`, `FormSubmission`
- **Newsletter:** `Subscriber`, `Campaign`, `AutomationEnrollment`, `AutomationSequence`, `AutomationStep`
- **Seo:** `SeoMeta`
- **Membership:** `MembershipPlan`
- **Analytics:** `Pageview`
- **Comment:** `Comment`
- **Shared:** `BaseEntity`, `Webhook`

#### Entity Rules:
- `fromRow()` static factory method constructs entity from DB row
- Use `DateTimeImmutable` for timestamps
- Domain logic inside entity (e.g. `isPublished()`, `publish()`, `trash()`)
- Static "lazy container accessor" pattern set in bootstrap to break circular deps

### 2.2 Value Objects (`src/Domain/Shared/ValueObject/`)

- `Slug` — URL-friendly string with validation
- `Email` — Email validation
- `Pagination` — Pagination value object

### 2.3 Repository Interfaces (`src/Domain/*/Repository/`)

Each domain module has its own repository interface:
`PostRepositoryInterface`, `CategoryRepositoryInterface`, `TagRepositoryInterface`, `ContentTypeRepositoryInterface`, `RevisionRepositoryInterface`, `UserRepositoryInterface`, `RoleRepositoryInterface`, `ProductRepositoryInterface`, `ProductCategoryRepositoryInterface`, `ProductVoteRepositoryInterface`, `OrderRepositoryInterface`, `OrderLogRepositoryInterface`, `CouponRepositoryInterface`, `CountryTaxRateRepositoryInterface`, `DownloadRepositoryInterface`, `MediaRepositoryInterface`, `MenuRepositoryInterface`, `FormRepositoryInterface`, `SubscriberRepositoryInterface`, `CampaignRepositoryInterface`, `AutomationRepositoryInterface`, `SeoMetaRepositoryInterface`, `CommentRepositoryInterface`, `PlanRepositoryInterface`, `PageviewRepositoryInterface`, `WebhookRepositoryInterface`

### 2.4 Event System (`src/Domain/Shared/Event/`)

WordPress-style hook system (actions & filters):

```php
interface EventDispatcherInterface
{
    public function addAction(string $event, callable $listener, int $priority = 10): void;
    public function doAction(string $event, mixed ...$args): void;
    public function addFilter(string $filter, callable $callback, int $priority = 10): void;
    public function applyFilter(string $filter, mixed $value, mixed ...$args): mixed;
    public function hasListeners(string $event): bool;
}
```

- **Actions** — fire-and-forget (e.g. `post.published`, `user.registered`)
- **Filters** — transform a value through a callback chain


---

## 3. Application Layer (Use Cases)

| Module | Classes |
|--------|---------|
| Auth | `LoginUseCase`, `LogoutUseCase`, `RegisterUseCase`, `TwoFactorService` |
| Content | `BlogFeedService`, `SavePostUseCase`, `SaveCategoryUseCase`, `SaveTagUseCase` |
| Menu | `SaveMenuUseCase` |
| Media | `UploadMediaUseCase`, `DeleteMediaUseCase` |
| Mail | `MailService` |
| Shop | `AbandonedCartService`, `ProductFeedService`, `IdealoFeedService`, `ShopifyFeedService`, `YandexMarketFeedService` |
| Newsletter | `NewsletterService`, `AutomationService` |
| Seo | `GenerateSitemapUseCase`, `SaveSeoMetaUseCase`, `RobotsTxtUseCase`, `AiRobotsTxtUseCase`, `SeoState` |
| Update | `UpdateService`, `PreUpdateChecker`, `BackupService`, `SignatureVerifier`, `MaintenanceMode`, `UpdateNotificationService` |
| Webhook | `OutboundWebhookService` |
| Maintenance | `ExportImportService` |
| Membership | `SubscriptionService`, `SubscriptionReconciliationService` |
| Cron | `CronService` |
| Comment | `CommentNotificationService` |

---

## 4. Infrastructure Layer

### 4.1 Persistence (Repository Implementations)

`src/Infrastructure/Persistence/Dbal*Repository.php` — uses Doctrine DBAL.

```php
final class DbalPostRepository implements PostRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}
}
```

DBAL `Connection` is injected via DI. Table names use `VL_DB_PREFIX` constant.

### 4.2 Payment Gateways (`src/Infrastructure/Payment/`)

- `StripeGateway`, `PayPalGateway`, `MollieGateway`, `CheckoutComGateway`, `TwoCheckoutGateway`
- All implement `Shop\Port\PaymentGatewayInterface`

### 4.3 Mail Adapters (`src/Infrastructure/Mail/`)

- `PHPMailerAdapter`, `PhpMailAdapter`, `MailgunAdapter`, `ResendAdapter`, `SendGridAdapter`, `UnosendAdapter`, `LogMailAdapter`
- `FileLoggingMailDecorator` — decorator pattern for logging
- All implement `Mail\Port\MailInterface`

### 4.4 Cache (`src/Infrastructure/Cache/`)

- `FileCache` — PSR-16 file-based cache
- `PageCache` — full-page HTML cache

### 4.5 Security (`src/Infrastructure/Security/`)

- `CsrfManager`, `RateLimiter`, `Encrypter`, `Encryption`, `IpAnonymizer`

### 4.6 Other Infrastructure Services

- `Session\SessionManager`, `Theme\ThemeManager`, `Translation\Translator`
- `Content\SlugService`, `ShortcodeProcessor`, `RedirectService`, `ReadingTimeService`
- `Analytics\GeoIpResolver`, `UserAgentParser`
- `Auth\PasswordHasher`, `Pdf\DompdfGenerator`
- `Media\ImageProcessor`, `MediaUploadService`, `MediaScanService`
- `ExchangeRate\ExchangeRateService`
- `Admin\IconHelper`, `NavOrderService`

---

## 5. Controller Layer (`app/Controllers/`)

### 5.1 Front Controllers (`app/Controllers/Front/`)

`HomeController`, `AuthController`, `PostController`, `PageController`, `ShopController`, `CheckoutController`, `AccountController`, `CommentController`, `FormFrontController`, `NewsletterFrontController`, `SeoController`, `ForgotPasswordController`, `EmergencyResetController`, `CurrencyController`, `DownloadController`, `MembershipCheckoutController`, `WebhookController`

### 5.2 Admin Controllers (`app/Controllers/Admin/`)

`DashboardController`, `PostController`, `PageController`, `CategoryController`, `TagController`, `UserController`, `RoleController`, `ProductController`, `OrderController`, `CouponController`, `ProductCategoryController`, `MediaController`, `MenuController`, `CommentController`, `FormController`, `NewsletterController`, `MailController`, `SettingsController`, `ThemeController`, `AppearanceController`, `ContentTypeController`, `AnalyticsController`, `UpdateController`, `PaymentController`, `ExchangeRateController`, `ApiTokenController`, `AutomationController`, `MembershipController`, `WebhookAdminController`, `NavOrderController`

Traits: `RevisionControllerTrait`, `ContentPaginationTrait`

### 5.3 API Controllers (`app/Controllers/Api/`)

`FeedController`, `PostApiController`, `ProductApiController`

---

## 6. Middleware Layer (`app/Middleware/`)

PSR-15 middleware stack (LIFO — last added runs first):

| Middleware | Responsibility |
|------------|----------------|
| `SecurityHeadersMiddleware` | X-Frame-Options, X-Content-Type-Options, Referrer-Policy |
| `BasePathMiddleware` | Fixes APP_BASE_PATH redirects |
| `PageCacheMiddleware` | Full-page HTML cache for guest visitors |
| `LocaleMiddleware` | Locale detection and localized URL routing |
| `CsrfMiddleware` | CSRF token validation |
| `FlashMiddleware` | Flash messages (session-based) |
| `RedirectMiddleware` | 301 redirects |
| `TrailingSlashMiddleware` | Trailing-slash canonicalization (GET/HEAD 301 to slash-less URL) |
| `MaintenanceMiddleware` | Maintenance mode check |
| `RateLimitMiddleware` | Rate limiting |
| `PseudoCronMiddleware` | Triggers cron tasks per request |
| `PageviewMiddleware` | Page view counter |
| `AuthMiddleware` | Session authentication |
| `AdminMiddleware` | Admin panel access control |
| `AdminCacheBustMiddleware` | Admin cache bypass |
| `ApiAuthMiddleware` | API token authentication |
| `ApiAbilityMiddleware` | API permission control |
| `PermissionMiddleware` | RBAC permission check |
| `TwoFactorForcedMiddleware` | Mandatory 2FA check |

---

## 7. CLI Commands (`app/Commands/`)

Symfony Console based. Run via `php vale`:

All CLI commands are registered in the `vale` file at the project root. For the full command reference, see [Section 21. Quick Commands Reference](#21-quick-commands-reference).

---

## 8. Route Structure

### 8.1 Web Routes (`routes/web.php`)
- Homepage, blog, SEO (sitemap/robots), auth, shop, checkout, account, newsletter, forms, comments, membership
- Custom Post Type routes generated dynamically
- Catch-all page route (`/{path:.+}`) registered last

### 8.2 Admin Routes (`routes/admin.php`)
- Grouped under `/admin` prefix
- Protected by AuthMiddleware + AdminMiddleware
- RBAC via PermissionMiddleware

### 8.3 API Routes (`routes/api.php`)
- Under `/api` prefix
- API key authentication

---

## 9. DI Container (PHP-DI)

`config/services.php` — All DI definitions live here (1500+ lines). Interface → Implementation binding pattern:

```php
PostRepositoryInterface::class => factory(function ($c) {
    return new DbalPostRepository($c->get(DbalConnection::class));
}),
```

Repositories receive DBAL `Connection`. Services use constructor injection.

Key bindings: `DbalConnection`, `Twig`, `EventDispatcherInterface`, `MailInterface`, `PaymentGatewayInterface`, `Psr\SimpleCache\CacheInterface` (FileCache)

---

## 10. Configuration

| File | Contents |
|------|----------|
| `config/app.php` | General app settings |
| `config/auth.php` | Authentication settings |
| `config/database.php` | Database connection settings |
| `config/cache.php` | Cache settings |
| `config/mail.php` | Mail settings |
| `config/media.php` | Media settings |
| `config/shop.php` | E-commerce settings |
| `config/admin-nav.php` | Admin navigation structure |
| `config/reference-data.php` | Reference data (countries, currencies, provider catalogs, third-party service lists, captcha + media-embed CSP `frame-src` origins — `SecurityHeadersMiddleware` merges the `captcha_urls` and `media_embeds` `frame_src` values into the policy). Also the **media file type catalogs**: `media_file_types` (validation — extension + accepted content detections, derived into config/media.php's allowed lists) and `media_serve_types` (Content-Type for served uploads). Never add `text/html` or executable extensions to either; `MediaFileTypes::BLOCKED_EXTENSIONS` is enforced in code regardless |
| `config/services.php` | DI container definitions |

---

## 11. Test Structure (`tests/`)

### Test Suites (phpunit.xml): Unit, Integration, Feature

### Available Test Directory:
```
tests/
├── Admin/           # Admin controller tests
├── Api/             # API controller tests
├── App/Controllers/Front/  # Front controller tests
├── App/Middleware/   # Middleware tests
├── Application/     # Use case tests (Auth, Content, Newsletter)
├── Domain/          # Domain tests (Entity, ValueObject)
└── Infrastructure/  # Infrastructure tests (Cache, Security, Theme, Translation)
```

### Running Tests:
```bash
composer test                    # All tests
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit tests/Domain/Content/Entity/PostTest.php
```

### Test File Namespaces (PSR-4):
- `composer.json` maps `ValeApp\Tests\` to `tests/` — every new test file MUST declare `namespace ValeApp\Tests\...`, never the prefixless `Tests\...`
- A non-compliant file still runs under PHPUnit (files are loaded from the `<directory>` rules in `phpunit.xml`, not via the autoloader), but `composer update` / `composer dump-autoload -o` prints "does not comply with psr-4 autoloading standard" and **skips the class** in the optimized autoloader
- After adding or renaming a test file, verify with `composer dump-autoload -o` — zero PSR-4 warnings means compliant

---

## 12. Code Quality

### Tools:
- **PHP-CS-Fixer** — PSR-12 with custom rules (config: `scripts/lint/.php-cs-fixer.dist.php`)
- **PHP CodeSniffer** — line length (100 soft / 120 hard)
- Always run through composer scripts (`composer lint -- <files>`). Never invoke
  `vendor/bin/php-cs-fixer` directly with multiple paths — it fails with
  "For multiple paths config parameter is required" and would miss the project config.

### Important Rules:
- `declare(strict_types=1);` — required in ALL PHP files
- Minimize comments — prefer self-documenting code
- Descriptive naming for variables, methods, and classes

---

## 13. Constants & Helpers

### Global Constant:
- `VL_DB_PREFIX` — Database table prefix (set in bootstrap, default `vl_`)

### Helper Functions (`app/helpers.php`):
- `env(string $key, $default = null)` — Read environment variable
- `__(string $text, array $replacements = [])` — Translation
- `base_path(string $path = '')` — Project root path
- `storage_path(string $path = '')` — Storage directory path
- `public_path(string $path = '')` — Public directory path
- `config_path(string $path = '')` — Config directory path
- `e(?string $value)` — HTML escape (htmlspecialchars shortcut)
- `site_url(string $path = '', array $queryParams = [])` — Generate localized URL


### Translation in Twig Templates:
```twig
{{ __('Save Changes') }}                        {{ __('Delete :name', {':name': product.name}) }}
<a title="{{ __('Delete')|e('js') }}" ...>       {{ __('%d min read', {'%d': readingTime}) }}
```

- Always use `|e('js')` when passing translated strings to JavaScript attributes.
- Placeholder syntax: `:name` or `%d` — both work, check the translation file to match.
- Do not use fragmented strings which may break grammar in some languages. E.g., ```{{ is_edit ? __('Update') : __('Create') }} {{ __('Form') }} ``` is a bad example. Use placeholders to increase flexibility of the placement of the action.

### JavaScript Translation via `window.VL_I18N` + `tjs()`

The admin layout (`resources/views/admin/layout.twig`) pre-populates a `window.VL_I18N` object with server-side translated strings for the Tiptap editor. The `tjs()` function looks up keys in this object:

```js
// Defined in admin/layout.twig — available globally in admin JS
function tjs(text, replacements = {}) {
    let translated = window.VL_I18N[text] || text;
    for (const [key, value] of Object.entries(replacements)) {
        translated = translated.replace(key, value);
    }
    return translated;
}
```

**Usage in `resources/js/editor.js` (and other admin JS files):**
```js
// Simple lookup
button.textContent = tjs('Save');
title = tjs('Link Button Settings');

// With replacements
label = tjs('Page :current of :total', {':current': page, ':total': total});
```

**Adding new translatable strings for the editor:**

1. Add the English text to `window.VL_I18N` in `resources/views/admin/layout.twig`:
   ```twig
   "Your New String": "{{ __('Your New String')|e('js') }}",
   ```
2. Use `tjs('Your New String')` in `resources/js/editor.js`.
3. Add the translation to `resources/lang/*.json` files.

**Important:** Strings used with `tjs()` MUST be added to `window.VL_I18N` in the admin layout first, otherwise they fall back to the untranslated key.


---

## 14. Important Technical Details

### Database:
- Doctrine DBAL (query builder, NOT an ORM)
- Supports: MySQL 8.4+, MariaDB 11.8+, SQLite, PostgreSQL
- Migration: Phinx (`database/migrations/`)
- Table prefix: `vl_` (configurable, `VL_DB_PREFIX` constant)

#### SQLite, MySQL, and PostgreSQL Compatibility for DB Commands:
All raw SQL in repositories and migrations MUST work on MySQL, PostgreSQL and SQLite:

- **Use `CONCAT(a, b)` instead of `a || b`** — `||` is concat in SQLite but logical OR in MySQL
- **Use `COALESCE(col, default)` for fallback values** — works on both engines
- **Use `DATE(column)` for date extraction** — works on both
- **Use `LOWER()`, `UPPER()` instead of `LCASE()`, `UCASE()`**
- **Avoid `LIMIT ? OFFSET ?` for SQLite** — use Doctrine DBAL `$connection->fetchAllAssociative()` with parameters
- **Avoid `GROUP BY` on non-aggregated columns when only MySQL is used** — SQLite is stricter
- **Use explicit `NOT IN (SELECT ...)` with care** — SQLite handles NULL differently
- **Migration up/down methods** — test both engines via `.env` switch (`DB_DRIVER`)
- **Doctrine DBAL abstraction layer** handles most differences; prefer it over raw SQL
- Consider backticks

Run tests with both drivers before committing:
```bash
# MySQL
composer test:unit

# SQLite (set DB_DRIVER=sqlite in .env first)
composer test:unit
```

### Shop Orders & Tax (VAT-Inclusive Pricing):
- Prices are stored **gross (VAT-inclusive)** everywhere — cart, checkout, order items, product pages.
- Checkout extracts the embedded VAT via `CheckoutController::calculateIncludedTaxBreakdown()`:
  - `vl_orders.tax` = total extracted VAT (product lines + shipping VAT)
  - `vl_order_items.tax_rate` / `tax_amount` = per-line rate + extracted amount (NULL for legacy rows created before this column existed, or when no tax applies)
- **Invariant:** `total = subtotal + shipping − discount` (tax is never added on top — it is already inside the gross prices).
- Admin order detail (`resources/views/admin/orders/show.twig`) renders per-item "Included VAT" as a small line under Price and an order-level "Included VAT" row in tfoot. Legacy orders without stored item tax show `—`.
- Invoice snapshots (`OrderController::buildInvoiceSnapshot()`) resolve the rate from settings at issue time; prefer stored item `tax_rate` for historical display.
- DemoSeeder orders must respect the same semantics (`total = subtotal + shipping − discount`, included-VAT extraction).

### Plugins (content/):
- User-owned drop-in code: `content/plugins/<slug>/plugin.php` (manifest: `name`, `version`, `author`, `description`, `license`, `homepage`, `requires_vale`, `requires_php`, `requires_ext`, `requires_plugin`, `capabilities`, `priority`, `namespace`, optional `admin` block, plus `register(App, Container)`) and `content/mu-plugins/*.php` (always-on file returning a callable). Unmet `requires_*` skips the plugin at enable time and at load; `requires_plugin` additionally requires the dependency to be installed and **enabled** (checked by `PluginManager::dependencyProblem()` and again by the loader against the manifests it actually loaded). Route and middleware callables registered by a plugin must be non-static closures (or invokable classes): Slim binds them to the container, and a static closure fails with `bindToContainer(): ... null returned`.
- `PluginLoader` runs in `bootstrap/app.php` §4.5 — after the container, **before** the route files, so plugin routes beat the `/{path:.+}` catch-all. Each plugin is isolated: invalid manifest, missing directory or unmet `requires_vale` is skipped, a throwing `register()` is logged and recorded once per distinct message in the `plugin_failures` setting.
- `content/` is in `UpdateService::PROTECTED`, git-ignored except `.gitkeep`, excluded from images (`.dockerignore`), created by the Dockerfile/entrypoint, checked by `install.php`; immutable images mount the `vale-content` volume.
- Activation is the `enabled_plugins` setting (JSON slug array) via `php vale plugin:list|enable|disable`. Emergency brake: `content/plugins/.disabled` or `VL_DISABLE_PLUGINS=1` (checked before any plugin file is included). The optional manifest `capabilities` key lets a plugin declare the sensitive PHP APIs it uses (`env`, `process`, `code_execution`, `filesystem_read`, `filesystem_write`, `network`); `PluginSecurityScanner` token-scans every plugin source file and refuses enable, ZIP install and update while an **undeclared** capability is used (`InvalidPluginException` with file/line). `fopen()` is classified by its mode argument: read modes need `filesystem_read`, write modes (`w`, `a`, `x`, `c`, `+`) need `filesystem_write`, and a dynamic mode needs both. `.php`, `.inc`, `.phtml` and `php3`–`php8` files are scanned; literal `call_user_func()` targets, `use function … as` imports (group imports too), parenthesized constant calls, literal string concatenations and single-assignment variable functions (`$fn = 'scandir'; $fn()`) are resolved. The badges on the plugin list and the enable confirm dialog show the claimed capabilities. The gate runs at enable/install/update time only — the runtime `PluginLoader` does not re-scan.
- Admin screen: `/admin/plugins` (`PluginController`, permission `system.plugins`, System group in `config/admin-nav.php`). It only parses manifests and never calls `register()`, so a crashing plugin can still be disabled there. The same slug gates the toggle, install and update routes, the global switch, the sidebar entry and the Settings → Features card; there is no `plugins.use`. **Install plugin** (`POST /admin/plugins/install`) accepts a ZIP, validates its manifest through `PluginUpdateService::installFromArchive()`, derives the slug from the package folder (or ZIP file name when `plugin.php` is at the root) and installs it disabled under `content/plugins/<slug>/`; an existing directory is never overwritten. CLI twins: `php vale plugin:install <zip> [--dry-run]`, `plugin:check-updates [slug]`, `plugin:update <slug>`. ZIP installs are regular plugins only (a package without `plugin.php` is rejected with a must-use hint); mu-plugins are single files placed over the filesystem and have no admin/CLI ZIP install path. Mu-plugins appear as **Must-Use** rows in the same admin table (search/pagination included), without a checkbox or toggle and with a disabled Delete action; `MuPluginMetadata` reads their optional header block (`Plugin Name`, `Version`, `Description`, `License`, `Website` / `Plugin URI`) so the same columns are populated. The `plugins_enabled` setting (default on) is the admin master switch: off skips every plugin and mu-plugin, 404s their assets and hides the sidebar entry (`FlashMiddleware::hidePluginsNav`) without touching `enabled_plugins`; the screen stays reachable from Settings → Features and warns. The operator kill switch still wins. `front_page_title` is the filter for the front-end document title (args: title, entity title, path). An optional manifest `'settings' => '/admin/...'` link is shown on the plugin list, and `homepage` adds a Website button. The list supports search and pagination: up to `client_side_search_fetch_limit()` plugins (env `CLIENT_SIDE_SEARCH_FETCH_LIMIT`, mirrored to JS as `VL_CLIENT_SEARCH_FETCH_LIMIT`) are filtered/paged in the browser, larger lists are filtered and sliced server-side. **Delete** always disables the plugin and removes `content/plugins/<slug>/`; with **Include settings and data** it first rolls back every applied migration, then deletes `plugin:<slug>:*` settings, the failure entry, cached update status and migration bookkeeping. Without it those records stay for a reinstall. A failed/missing rollback aborts before files are removed. Fresh installs seed it for `super_admin`/`admin` (not `editor`), and the upgrade migration grants it to them on existing databases — keep that migration when renaming the permission.
- Plugin migrations: `content/plugins/<slug>/migrations/YYYYMMDDHHMMSS_name.php` returns an `up` callable; `PluginMigrator` applies them on activation, in cron (`CronService`) and via `php vale plugin:migrate [slug] [--dry-run]`, recording applied file names per plugin in the `plugin_migrations` setting. SQL must work on MySQL/PostgreSQL/SQLite and use `VL_DB_PREFIX`. A file may declare a `down` callable; `php vale plugin:migrate --rollback [--all] <slug>` reverts newest-first (never automatic on deactivate or cron).
- Plugin admin menu: the optional `admin` block in `plugin.php` (`label`, `href` — must be an `/admin*` path, `group`, `permission`, `icon`) is turned into a sidebar link by `PluginLoader` through the `admin_nav_groups` filter. Entries are injected only for plugins that loaded successfully, so activation enables/disables the link automatically; an unknown `group` falls back to a top-level link and `permission` is enforced by `FlashMiddleware`. The Plugins row exposes a global **Hide/Show in navigation** action (`plugin_nav_hidden`). Top-level core and plugin entries are drag-sortable; inside a group every child (core or plugin) can be reordered within its own group, but children cannot be moved to another group (`admin_nav_child_order`). **Reset Order** below **Save Order** clears both saved orders.
- Plugin assets: `content/plugins/<slug>/assets/` is served by `PluginAssetController` at `/plugin-assets/{slug}/{path}` through `PluginAssetResolver` (extension whitelist, path containment, enabled plugins only, 1 h cache). Plugins build URLs with the `plugin_asset_url()` helper.
- Plugin updates: an optional manifest `'updates' => 'https://…/updates.json'` feeds `PluginUpdateService` (`PluginRelease` parses the feed). `/admin/plugins` has **Check for plugin updates** (`POST /admin/plugins/check-updates`) and per-plugin **Update** (`POST /admin/plugins/update`); results are cached in `plugin_update_status`. `CronService` calls `checkAll()` at most every 24 h (same `updates_enabled` / `update_auto_check` gates) and the dashboard shows a **Plugin updates** status notice; checks are automatic, applying is always manual. The feed requires `version`, `download_url`, `sha256` and may declare `requires_vale`, `requires_php`, `requires_ext` and `requires_plugin`. The service checks both feed and package manifest, downloads via `SafeRemoteFetcher` (HTTPS only outside local dev), verifies SHA-256, extracts with traversal/symlink protection, backs up to `storage/backups/plugins/plugin-<slug>-…/`, runs pending migrations for enabled plugins and restores the backup on failure. A package that declares capabilities the installed version does not is never applied silently: the admin screen asks for approval (CLI: `--acknowledge-capabilities`) for that exact version.
- Pattern covered end-to-end by the `vale-plugin` skill — update it with any change to the manifest schema, loader, admin screen or asset rules.

### Theme System:
- Directory: `themes/`
- Child theme support
- Managed via admin panel

### Cache:
- File-based (PSR-16)
- Page cache (full-page HTML, guest-only)
- Cache warm command available

### Security:
- CSRF token on every form + AJAX request
- Doctrine DBAL prepared statements (SQL injection prevention)
- Twig auto-escape (XSS prevention)
- File upload MIME + extension whitelist
- Rate limiting (file-based)
- bcrypt cost:12
- OpenSSL AES-256-CBC credential encryption
- Session regeneration on login/logout
- Webhook signature verification
- SSRF: every outbound fetch of a user-supplied URL goes through `PrivateNetworkGuard` (`isPrivateHost`) — for redirecting downloads/imports use `Infrastructure\Http\SafeRemoteFetcher`, which follows redirects manually and re-validates each hop instead of letting `fopen()` follow them internally; its `allowPrivateHosts` waiver is only for operator-supplied sources (e.g. a local WordPress import via `import:wordpress --allow-private-hosts`), never for URLs taken from untrusted content. `HttpTransport` (webhooks, gateways) never follows redirects for the same reason

### Auto-Update:
- Ed25519 signature verification (primary)
- GPG signature verification (alternative)
- Backup/restore capability
- **`themes/` is deliberately NOT in `UpdateService::PROTECTED`**: the bundled themes (`default`, `dark`, `nova`, `vivid`) ship inside the release ZIP and are replaced on update — that is how AGENTS §22 front-end fixes reach existing installations. User-built/uploaded themes are never in the package, so `copyTree()` leaves them (and every file the package does not contain) untouched; customizations belong in a child theme (`"parent"` in `theme.json`). Do not add `themes/` to `PROTECTED` — it would freeze bundled themes at their release-day state. The full backup taken before every update includes `themes/`, so rollback restores them.

### Editor (Block Editor JS):
- Entry: `resources/js/editor/index.js` — 40 ES modules under `resources/js/editor/`, bundled by esbuild to a single file
- Folders: `extensions/` (TipTap extensions), `embeds/` (block nodes), `settings/`, `ui/` (pickers/dialogs/modal), `media/`, `nodes/`, plus `styles.js` (injected CSS), `converters.js` (HTML round-trips), `slash-menu.js`, `toolbar.js`, `table-controls.js`, `image-resize.js`, `bubble-menus.js`, `utils.js`
- Window API contract (keep stable): `vlInitEditor`, `vlSetEditorContent`, `vlSetEditorContentToggle`, `lpInsertVar`, `lpHasModal`, `lpEditors`, `_lpLastEditor`, `getEditorContentLanguage`
- Shared UI primitives: `ui/modal.js` (`createModal`, `openFormModal`), `settings/schema-modal.js` (`openBlockSettings`), `media/browse.js` (all media library dialogs)
- **Editor modal layout:** action-bearing editor dialogs follow the site-wide pattern — `createModal({ structured: true })` (or the `vl-modal--structured` class on self-built overlays) splits the panel into `.vl-editor-modal-head` (sticky title), `.vl-editor-modal-body` (scrolling fields), `.vl-editor-modal-foot` (pinned Cancel/Save row, right-aligned). Padding utilities in the size className are ignored for structured modals. Media pickers (`page-picker`, `product-picker`, `form-picker`, `media/browse.js`) intentionally keep their own header/body layout
- **Editor UI string casing:** slash menu + toolbar "Insert element" titles → Title Case (`Media Library (Ctrl+Alt+M)`, `Link Buttons`); slash menu descriptions, help text, button/checkbox labels, placeholders → sentence case (`Insert image, video or file from library`, `Open in new tab`); dialog/settings titles → Title Case (`Insert File Link`). Full convention with examples: `.agents/skills/vale-editor-block/SKILL.md`
- **Adding a block with settings:** define the TipTap node in `embeds/`, then declare its dialog as a field schema:
  ```js
  openBlockSettings(editor, pos, {
    title: tjs('My Block Settings'),
    fields: [
      { key: 'title', type: 'text', label: tjs('Title') },
      { key: 'bg', type: 'color', label: tjs('Background') },
      { key: 'page', type: 'page', label: tjs('Page') },   // page picker built-in
    ],
  });
  ```
  Field types: `text | number(+suffixSelect) | select | checkbox | color | page | divider | custom`. Only declared keys are written back via `setNodeMarkup`.
- Build: `npm run build:editor` (prod → `public/assets/build/editor.js`), `npm run build:editor:dev` (dev artifact → `public/assets/js/editor.js`), `npm run watch:editor`
- Images inside composite blocks (`[class*="embed-block"]`, `[data-media-text]`) never get resize handles/size dialog — standalone images only
- **Tables (editor ↔ front-end parity):** geometry and colors live only in inline styles, never in editor-only DOM writes.
  - `<table style>` carries the `--vl-cell-border` / `--vl-table-cell-padding` variables, `width` and the alignment margins; `border` is only kept in sync when the author already had one. Absent declarations mean "theme default" — the dialog never writes them.
  - `table-controls.js` merges: it writes only the fields the author changed and leaves every other declaration (and the author's style string) untouched. A no-op save returns the original style verbatim.
  - Cell styling is node state: `backgroundColor`, `verticalAlign` and TipTap's `align` render as inline styles on `<td>/<th>` (`extensions/table-cells.js`). Nothing writes the DOM directly.
  - Column widths are `colwidth` attributes → `<colgroup>`; an explicit table `width` wins over them. `PreservingTableView` re-applies that width and gives width-less columns the same `min-width: Npx` the saved markup has, so the editor DOM equals the saved markup and the columns size identically.
  - Legacy `text-align` on `<table>` is neutralised with `text-align: initial` (TipTap merges style declarations, so deleting it resurrects the old value).
  - The table CSS block must stay identical in `resources/views/layout.twig` and `themes/{dark,nova,vivid}`; `tests/Unit/Theme/TableStyleContractTest.php` fails on drift.
  - The editor preview takes the table palette from the active theme's `theme.json` `tables` block (`border`, `header_background`) → `vl_table_palette` → the `--vl-table-border` / `--vl-table-head-bg` / `--vl-table-head-text` variables emitted by `resources/views/admin/layout.twig` after `editor.css`. Those values must mirror the theme's CSS; the same test enforces it.
  - Exception: in dark admin mode `.dark .vl-editor-content .ProseMirror` re-points those three variables at the editor's own dark tokens (`--vl-editor-border`, `--vl-block-header-bg`, `--vl-editor-text`) — a light theme's header fill must not become a white block on the dark canvas. Author-set cell colors are inline and unaffected.

### Asset Build:
- Tailwind CSS (v4 `--minify` for CSS minification), Alpine.js, Chart.js
- Self-hosted fonts (Inter, Playfair Display)

### Cache:

- If APP_ENV is dev, no cache is created, simply refreshing the page will work.
- Similarly, if APP_DEBUG is true, errors.log is written under storage/log folder.

### Docker:
- `docker-compose.yml` — Apache (8080), Nginx (8081), MySQL (3306)
- `docker-compose.prod.yml` — Production deployment
- Dev: volume mount for live development
- Production (immutable): one parameterized `docker/Dockerfile` — `VARIANT=apache|nginx`, `FLAVOR=immutable|mutable` — multi-stage build
- Four images publish to Docker Hub (`midillitech/vale`) via `scripts/publish-docker.php` (passes the build args per target) + composer aliases (`composer docker:plan|build|test|publish`). Tags: unsuffixed = immutable Apache (`latest`, `apache`, `<ver>`), `-nginx` = immutable Nginx, `mutable-*` = self-managed bind-mount flavor, `edge*` = unreleased master builds. Docs: `vale-docs/en/latest/developer/deployment/docker-registry.md`

### Admin Media Previews:
- Shared partial: `resources/views/admin/partials/_media_preview.twig` (variants: `grid`, `list`, `full`) — every admin surface that renders media tiles (media library grid/list, media edit page, gallery pickers) reuses it instead of ad-hoc emoji fallbacks.
- Behavior engine: `public/assets/js/media-facade.js` (plain script, loaded once from `admin/layout.twig`, no build step). It owns three things for every `[data-vl-media-facade]` tile — including tiles rendered later by Alpine `x-for` or the editor picker (auto-detected via MutationObserver, no per-surface wiring):
  1. **In-view poster** — IntersectionObserver (200px margin) inserts a paused, muted `<video preload="metadata">` so the first frame shows without downloading the whole file; leaving the viewport detaches it (upgraded/playing posters stay).
  2. **Click-to-load player** — clicking the play badge (capture-phase delegated listener + `stopPropagation`) upgrades the poster or creates `<video controls autoplay>`; nothing downloads before that.
  3. **Click isolation** — badge/player clicks never reach the surrounding card handler (select / insert / info overlay).
- Editor picker (`resources/js/editor/media/browse.js`) only renders the markup; the full-featured browser remembers its last type filter in `localStorage` (`vl_media_browser_type`), fixed-filter pickers never touch it.
- Styles: `.vl-media-facade*`, `.vl-media-file-tile`, `.vl-file-icon`, `.vl-media-thumb-badge` in `public/assets/css/admin-style.css` — built on `--vl-*` variables, so any color addition must ship its `.dark` variant (see §16).

### Admin Media Picker Upload (drag-drop):
- Shared engine: `public/assets/js/media-upload.js` (plain script, `defer` from `admin/layout.twig`, no build step). Two wiring styles:
  - **Declarative (Alpine modals):** put `data-vl-media-drop` + optional `data-vl-media-filter` (browse-json `type` value — `image/`, `image/,video/`, `""` = all) on the modal **panel** (never the full-viewport backdrop root — absolute overlay/progress bars would span the screen), and listen with `@vl:media-uploaded="handleDroppedFiles($event.detail)"`. Auto-registered via MutationObserver, so Alpine teleport timing doesn't matter. **Contract:** a declarative zone MUST carry the `@vl:media-uploaded` (or `x-on:vl-media-uploaded`) attribute — the event bubbles, so the handler may live on an ancestor scope, but a zone without any listener uploads files into the void; when `APP_DEBUG` is on, the engine logs a console warning for it. The event is dispatched with `bubbles: true`.
  - **Programmatic (browse.js):** `vlMediaUpload.dropZone(el, { filter, onDone, onError, conflictMode })`. Calling `dropZone()` again on the **same element** only swaps the options (`el.__vlMediaDropOpts`) — the DOM is wired once, and `filter`/`onDone`/`conflictMode` are re-read per batch via `curOpts()`. This is what makes modals that reuse one DOM node across opens safe: each open re-registers with fresh closures (current tab filter, current selection) instead of running against a stale one.
- The helper owns the overlay, progress bar and error strip; it adds `position: relative` **only** to static zones (inline). Never set `position` on these zones in CSS — admin-style.css is unlayered and would silently beat Tailwind's `fixed` utility (see the layer note in that file).
- **Feedback tones** — the strip is `showError(message, tone)`:
  - `tone` omitted / `'error'` → red (`--vl-callout-red-*`) for real failures (no permission, rejected extension, upload failed).
  - `tone === 'info'` → blue (`--vl-callout-blue-*`, dark-aware via the shared tokens) for progress notices — e.g. the "N file(s) queued" message when a drop lands mid-upload. Queuing is expected flow, not an error.
- **Queue:** drops that land while an upload is in flight are queued (`pending` array) and stream through the same progress bar in order — never silently swallowed.
- **Per-file errors:** `upload-json-multi` returns `200` with `uploaded:[…], errors:[…]` for partial success. Rows with `file !== '*'` are surfaced in the strip (`name: reason`, capped at 3 + "N more"); a `file === '*'` row is fatal and shown alone.
- Permission: `window.VL_CAN_UPLOAD_MEDIA` (layout.twig, from `media.upload`) is checked at **drop** time — a user without the permission gets an explicit message, never a silent no-op. The server enforces the same permission on `/admin/media/upload-json*` (routes/admin.php).
- Refresh contract: after upload each surface reloads page 1 and auto-selects — single pickers pick the first uploaded item, multi pickers add all of them to the selection.
- Drop filters mirror what each surface's grid can show/select (featured/OG/logo → images only; product gallery → image+video; download/variation pickers → everything; editor library → the active type tab).
- Reference docs: `vale-docs/en/latest/developer/media.md` (engine contract + subtitle/CC badge transform).

### Documentation:
- VitePress based MD format. The docs are **not** in this repo — they live in the sibling checkout `../vale-docs` (`en/latest/` is the only editable tree; `vX.Y.Z/` snapshots are read-only). Docs-repo agent rules: `../vale-docs/AGENTS.md`; routing/tone: the `vale-docs` skill + `vale-docs/en/latest/developer/documentation.md`.
- `npm run docs:build` (run **in `../vale-docs`**) must pass before committing docs: it chains `check:anchors` + `check:sidebar` + `check:spelling` + the VitePress build. CI (`.github/workflows/docs.yml`) additionally runs `check:external --strict`.
- Spellcheck (cspell) is part of that build and covers every `**/*.md`, inline code spans included. Legitimate product/provider/brand names go into the `words` array in `../vale-docs/cspell.json` (sorted case-insensitively); real typos are fixed in the prose — never add a misspelling, never reword correct text for the checker.
- If new feature or changed feature, ask for possible documentation updates.

---

## 15. Coding Guidelines for New Code

1. **Strict types:** All PHP files must start with `declare(strict_types=1);`
2. **Namespaces:** `ValeApp\Domain\*`, `ValeApp\Application\*`, `ValeApp\Infrastructure\*`, `ValeApp\App\*`
3. **Entities:** Extend `BaseEntity`, implement `fromRow()` factory method
4. **Repository Interfaces:** Define in Domain, implement in Infrastructure
5. **Use Cases:** Place in Application layer, use constructor injection for repository interfaces
6. **Controllers:** Place in `app/Controllers/`, use as Slim route callbacks
7. **Middleware:** PSR-15 standard, placed in `app/Middleware/`
8. **Tests:** Write in the matching test layer, follow existing patterns, and pick test doubles by need — see [§15.1 Test Doubles](#151-test-doubles-phpunit-12) below
9. **SQL:** Never concatenate raw SQL strings — always use prepared statements
10. **Events:** Use `doAction()` for side effects, `applyFilter()` for value transformations

### 15.1 Test Doubles (PHPUnit 12)

Choose the double by what the test needs to assert:

| Need | Use | Why |
|------|-----|-----|
| Return value / callback only — nothing to assert | `createStub()` | Avoids PHPUnit 12's "mock object without expectations" notice |
| Assert how many times a method is called, and with which arguments | `createMock()` + `expects()` | `expects()` is declared **only** on `MockObject`, never on `Stub` |

- **`createMock()` is not deprecated.** Only a mock created *without* `expects()` triggers the "without expectations" notice — fix that with `createStub()`, never by silencing it.
- **Do not add `#[AllowMockObjectsWithoutExpectations]` unless it is truly unavoidable and no alternative exists.** Rewrite the test so the notice cannot arise: use `createStub()` where nothing is asserted, and create a local `createMock()` + `expects()` only in the test that actually needs the expectation. Never put the attribute on `setUp()` or on a whole class to silence a batch of tests.
- **Never call `with()` on a test stub.** It has no effect and is deprecated (removal planned in PHPUnit 13):
  `Using with*() on a test stub has no effect and is deprecated.`
- To verify arguments **without** a mock, record the invocations in `willReturnCallback()` and assert on the recorded values afterwards:

```php
$lookedUp = [];
$posts    = $this->createStub(PostRepositoryInterface::class);
$posts->method('countByPageTemplate')->willReturnCallback(
    static function (string $template) use (&$lookedUp): int {
        $lookedUp[] = $template;
        return 2;
    }
);

// ... exercise the subject ...

self::assertSame(['custom-tpl'], $lookedUp); // argument + call count in one assertion
```

- Catch regressions by running the suite with `--display-phpunit-deprecations --display-notices`.

---

## 16. Admin Dark Mode

### How It Works

- **Tailwind `darkMode: 'class'`** — enabled in both `tailwind.config.js` (local builds) and CDN config (`admin/layout.twig`)
- When `.dark` class is present on `<html>`, Tailwind's `dark:` variants activate
- Toggle is persisted via cookie (`vl_admin_theme=dark`)

### Implementation Pattern (`resources/views/admin/layout.twig`)

```js
// Read cookie on page load (before Alpine/Tailwind init, in <head>)
(function() {
    const theme = document.cookie.match(/vl_admin_theme=([^;]+)/)?.[1] || 'light';
    if (theme === 'dark') document.documentElement.classList.add('dark');
})();
```

### Toggle Button (top bar)

```html
<div x-data="{
    darkMode: document.documentElement.classList.contains('dark'),
    toggleDarkMode() {
        this.darkMode = !this.darkMode;
        if (this.darkMode) {
            document.documentElement.classList.add('dark');
            document.cookie = 'vl_admin_theme=dark; path=/; max-age=31536000';
        } else {
            document.documentElement.classList.remove('dark');
            document.cookie = 'vl_admin_theme=light; path=/; max-age=31536000';
        }
    }
}">
    <button @click="toggleDarkMode" ...>
        <svg x-show="!darkMode" ...>     <!-- moon icon -->
        <svg x-show="darkMode" ...>      <!-- sun icon -->
    </button>
</div>
```

### CSS Overrides (`public/assets/css/admin-style.css`)

The admin-style.css file contains explicit `.dark` overrides for form elements, callout boxes, diff views, scrollbars, and editor components. These override Tailwind defaults where needed:

```css
/* Form inputs — white background fix for dark mode */
.dark input:not([type="checkbox"]):not([type="radio"]):not([type="submit"])...,
.dark select,
.dark textarea {
  background-color: #1f2937 !important;
  color: #f3f4f6 !important;
  border-color: #4b5563 !important;
}

/* Callout boxes */
.dark .bg-yellow-50  { background-color: #451a03 !important; }
.dark .bg-blue-50   { background-color: #1e3a5f !important; }
.dark .bg-green-50  { background-color: #14532d !important; }
```

### Editor Dark Mode (`public/assets/css/editor.css`)

The editor.css file includes `.dark` variants for all editor-specific components:
- `.dark .vl-picker-item:hover { background-color: #374151 !important; }`
- `.dark .vl-editor-modal-item { background: #1f2937; border-color: #374151; }`
- `.dark .vl-carousel-header, .dark .vl-newsletter-header, ...`

### Dark Mode Guidelines for New UI Elements

When adding new text, buttons, or UI components to admin templates:

1. **Use Tailwind `dark:` variants** for colors:
   ```twig
   <div class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100
               border-gray-200 dark:border-gray-700">
   ```

2. **For inline styles** in editor.js (which can't use Tailwind), add `.dark` CSS overrides:
   ```css
   .my-new-element { background: #fff; color: #111; border-color: #e5e7eb; }
   .dark .my-new-element { background: #1f2937; color: #f3f4f6; border-color: #374151; }
   ```

3. **Button colors** — use semantic classes over hard-coded bg colors when possible:
   ```twig
   <button class="bg-gray-900 dark:bg-gray-700 text-white ...">
   ```

4. **Icons/svgs** — ensure they use `currentColor` so they inherit the `color` property:
   ```html
   <svg fill="currentColor" ...>
   ```

5. **Checkbox/radio** — dark mode accent color is set globally:
   ```css
   .dark input[type="checkbox"], .dark input[type="radio"] { accent-color: #3b82f6; }
   ```

6. **Test both modes** — toggle with the moon/sun button in the admin top bar after every UI change

7. **CSS/style changes always require dark variant** — whenever adding or modifying any CSS class (background, text color, border, etc.) on a UI element, always include the corresponding `dark:` variant. Never leave a color without its dark mode counterpart.

---


## 17. Common Patterns

### Repository Pattern:
```php
// Domain/Content/Repository/PostRepositoryInterface.php
interface PostRepositoryInterface {
    public function findById(int $id): ?Post;
    public function findBySlug(Slug $slug, string $contentType = 'post'): ?Post;
    public function findAll(Pagination $pagination, array $filters = []): array;
    public function save(Post $post): Post;
    public function delete(int $id): void;
}

// Infrastructure/Persistence/DbalPostRepository.php
final class DbalPostRepository implements PostRepositoryInterface {
    public function __construct(private readonly Connection $db) {}
    // ...
}
```

### Use Case Pattern:
```php
class SavePostUseCase {
    public function __construct(
        private PostRepositoryInterface $postRepo,
        private EventDispatcherInterface $events,
    ) {}
    public function execute(array $data): Post {
        $post = new Post($data['title'], new Slug($data['slug']), $data['author_id']);
        // business logic...
        $this->postRepo->save($post);
        $this->events->doAction('post.published', $post);
        return $post;
    }
}
```

### DI Definition Pattern:
```php
// config/services.php
PostRepositoryInterface::class => factory(function ($c) {
    return new DbalPostRepository($c->get(DbalConnection::class));
}),
SavePostUseCase::class => factory(function ($c) {
    return new SavePostUseCase(
        $c->get(PostRepositoryInterface::class),
        $c->get(EventDispatcherInterface::class),
    );
}),
```

---

### Shared admin pickers (avoid duplicating search dropdowns)

- `resources/views/admin/components/entity-select.twig` + `Alpine.data('entityPicker')` (layout.twig) — unified searchable record picker. Modes: `async` (server search via `/admin/posts/list-json`) and `static` (preloaded options). Wrappers: `page-async-select.twig`, `translation-select.twig`. Use these instead of hand-rolled dropdown markup.
- `window.vlProductPool(config)` (layout.twig) — batched, cached product pool with client-side search. Spread into an Alpine component: `...vlProductPool({ withVariations: true })`. Provides `allProducts`, `allProductsTotal`, `allProductsLoading`, `allHasMore`, `loadAllProducts()`. Used by coupons and products (upsell/cross-sell).
- `admin/components/coupon-product-list.twig` — coupon allowed/blocked product restriction list.
- `admin/components/product-multi-picker.twig` — chip-based multi product picker (upsell/cross-sell).
- Shared caches: `window.__entityPickerCache` (async picker lists) and `window.__vlProductPoolCache` (product pool). Do not re-fetch manually; call `loadAllProducts()` / `openDropdown()`.

---

## 18. Directory Reference

| Path | Description |
|------|-------------|
| `src/Domain/` | Domain entities, value objects, repository interfaces |
| `src/Application/` | Use cases / application services |
| `src/Infrastructure/` | Framework adapters, repository implementations |
| `app/Controllers/Admin/` | Admin panel controllers |
| `app/Controllers/Front/` | Front-end controllers |
| `app/Controllers/Api/` | API controllers |
| `app/Middleware/` | PSR-15 middleware |
| `app/Commands/` | CLI commands |
| `bootstrap/app.php` | Application bootstrap |
| `bootstrap/events.php` | Domain event listeners (webhook delivery + page-cache invalidation), loaded by both the web bootstrap and the `vale` CLI |
| `config/` | Configuration files |
| `routes/web.php` | Public routes |
| `routes/admin.php` | Admin routes |
| `routes/api.php` | API routes |
| `resources/views/` | Twig templates |
| `public/index.php` | Front controller |
| `database/migrations/` | Phinx migrations |
| `database/seeds/` | Phinx seeders |
| `themes/` | Theme files |
| `storage/` | Logs, cache, uploads, backups |
| `tests/` | PHPUnit tests |
| `scripts/` | Build, lint, release scripts |
| `docker/` | Docker build files |

---

## 19. Git Workflow

- Branch: `master` (main branch)
- Pre-commit hook: secret scan (`.githooks/`)
- CI: GitHub Actions (`.github/workflows/`)
  - `lint-changed.yml` — Lint changed PHP files on push/PR
  - `dependency-scan.yml` — Gitleaks secret scan (incremental, per push/PR range) + `composer audit` + `npm audit` + `composer outdated --strict` (informational, non-blocking on minor/patch) + `npm outdated` + EOL audit
  - `docker-publish.yml` — release images on a `v*` tag (or manual dispatch) to Docker Hub; callable via `workflow_call` from `release-manual.yml`
  - `docker-edge.yml` — edge + mutable images on master pushes that touch runtime code
  - `docker-build.yml` — build-only validation of all four Dockerfiles on PRs (no push, no secrets)
  - `release-manual.yml` — manual release; its "Also publish the Docker images" input (default yes) publishes in the same run
- Release: Prepared via `scripts/prepare-release.php`

## 20. Commit Message Convention

Follow the Conventional Commits specification for all commit messages:

- `fix:` — Bug fixes
- `feat:` — New features
- `add:` — New files, configs, or assets (non-functional additions)
- `chore:` — Maintenance, dependencies, tooling, CI
- `refactor:` — Code changes that neither fix bugs nor add features
- `docs:` — Documentation only
- `test:` — Adding or fixing tests
- `perf:` — Performance improvements
- `security:` — Security fixes or hardening

Format: `<type>: <short description>`
Example: `fix: resolve order total calculation with discounts`

When a commit spans multiple concerns, use the most dominant type.
Squash trivial commits before merging to keep history clean.

---

## 21. Quick Commands Reference

Use this reference when the user gives high-level instructions like "test it", "extract translations", "build assets", etc.

### Dry-Run Support

Most state-changing CLI commands support `--dry-run` for a non-destructive preview: `cache:clear`, `cache:warm`, `migrate`, `migrate:urls`, `db:reset`, `db:optimize`, `db:seed`, `media:scan`, `media:webp-remove`, `backup:create/delete/restore-*`, all `import:*` commands, `export`, `export:products`, `cron:run`, and all `user:*` commands. Commands without it: `key:generate`, `update:check`, `backup:list`, `doctor`.

### Testing

```bash
composer test                # All test suites
composer test:unit           # Unit tests only
composer test:integration    # Integration tests only
composer test:feature        # Feature tests only
composer coverage            # Line coverage report (HTML + terminal summary) — requires PCOV/Xdebug extension
composer coverage:clover     # Clover XML only (for CI / threshold checks)
```

### Lint & Fix

```bash
composer lint                # PHP-CS-Fixer dry-run
composer lint -- <files>     # Dry-run on specific files (multi-path needs composer, not vendor/bin directly)
composer lint:changed        # Lint only changed PHP, Twig and JS files
composer lint:twig           # Twig template syntax check
composer lint:js             # JavaScript syntax check (standalone .js + inline <script> in Twig)
composer fix                 # PHP-CS-Fixer auto-fix
composer cs                  # PHP CodeSniffer
composer cs-fix              # PHPCBF auto-fix
```

### Translations

```bash
composer translations                    # Extract missing keys, report unused
composer translations:prune              # Extract + remove unused keys
composer translations:check              # CI mode: no writes, exit 1 on issues
composer translations:sync               # Sync translations across lang files
composer translations:create             # Create new translation file
```

### Database

```bash
composer migrate             # Run pending Phinx migrations
composer seed                 # Run database seeders
php vale db:optimize         # Optimize database tables
php vale migrate:urls        # Migrate/update site URLs
```

### Assets (Front-end)

```bash
npm run build                # Build all assets (CSS, JS, fonts)
npm run build:css            # Build Tailwind CSS only
npm run build:js             # Bundle app.js
npm run build:admin          # Bundle admin.js
npm run build:editor         # Bundle editor.js
npm run build:charts         # Bundle charts.js
npm run build:fonts          # Copy self-hosted fonts
npm run watch                # Watch CSS + JS for live development
```

### Docker (publish to Docker Hub `midillitech/vale`)

```bash
composer docker:plan         # Show the resolved tag matrix + build commands (dry-run)
composer docker:build        # Build all four images locally (no push)
composer docker:test         # Build + smoke-test the immutable Apache image
composer docker:publish      # Build, smoke-test and push to the registry
php scripts/publish-docker.php --only=immutable-apache --smoke   # One image at a time
php scripts/publish-docker.php --help   # Full flag reference
```

Secrets required in CI: `DOCKERHUB_USERNAME`, `DOCKERHUB_TOKEN` (scoped read/write access token).

### Docs (run in the sibling `../vale-docs` checkout)

```bash
npm run docs:dev            # Local docs server (runs anchors + sidebar + spelling first)
npm run docs:build          # anchors + sidebar + cspell + VitePress build — must pass
npm run check:anchors       # Internal #anchor links
npm run check:sidebar       # Sidebar entries → existing pages
npm run check:spelling      # cspell over **/*.md (dictionary: ../vale-docs/cspell.json)
npm run check:external      # Real external links (periodic; --strict in CI)
npm run docs:version -- --version=0.11.0   # Freeze latest/ into a version snapshot
```

### Cache & App

```bash
php vale cache:clear         # Clear file cache + page cache
php vale cache:warm          # Warm file cache
php vale key:generate        # Generate new APP_KEY
php vale cron:run            # Run scheduled cron tasks
php vale doctor              # System health check
php vale media:scan          # Scan and register media files
php vale media:webp-remove   # Remove auto-generated WebP renditions (when WebP setting is off)
php vale update:check         # Check for Vale updates
```

### Plugins

```bash
php vale plugin:install <zip> [--dry-run] # install a ZIP disabled
php vale plugin:list                    # installed plugins + activation state
php vale plugin:check-updates [slug]    # refresh update status, no downloads
php vale plugin:update <slug>           # download, verify and install one update
php vale plugin:enable <slug>           # activate (runs pending migrations)
php vale plugin:disable <slug>          # deactivate, keeps files + settings
php vale plugin:migrate [slug] [--dry-run]
php vale plugin:migrate --rollback [--all] <slug> [--dry-run]
```

### Users

```bash
php vale user:create         # Create new user (active + email-verified)
php vale user:reset-password # Reset user password
php vale user:delete         # Delete user
php vale user:activate       # Activate/deactivate user
php vale user:set-role       # Assign role to user
php vale role:list           # List all roles (slugs, user/permission counts)
```

### Backup

```bash
php vale backup:create        # Create full backup
php vale backup:list          # List available backups
php vale backup:delete        # Delete a backup
php vale backup:restore-all   # Restore everything (DB + files)
php vale backup:restore-db    # Restore database only
php vale backup:restore-files # Restore files only
```

### Import / Export

```bash
php vale export                    # Full export
php vale import                    # Full import
php vale export:products           # Export products only
php vale import:shopify            # Import from Shopify
php vale import:etsy               # Import from Etsy
php vale import:ebay               # Import from eBay
php vale import:amazon             # Import from Amazon
php vale import:products           # Import products (generic)
```

### Maintenance

```bash
php vale db:reset                 # Reset database (drop + migrate + seed)
composer url:check                # Check for broken URLs
composer url:check:strict         # Strict URL check (fail on warnings)
composer audit:eol                # Audit dependencies for EOL versions
```

---

## 22. Frontend Feature Rule — All Four Themes

**When adding or changing any frontend display feature (e.g. a new meta badge, a toggle-controlled UI element, a template change that affects the front-end), you MUST check and update all four themes:**

| Theme | Location | Notes |
|---|---|---|
| **default** | `resources/views/post.twig` (core) | Also covers **dark** theme (inherits core) |
| **dark** | `themes/dark/templates/` (inherits core) | Only override if the feature needs dark-specific styling; otherwise covered by core |
| **nova** | `themes/nova/templates/post.twig` | Standalone — has its own meta layout (centered hero, dot separators, no SVG icons) |
| **vivid** | `themes/vivid/templates/post.twig` | Standalone — uses SVG icons and opacity-40 separators |

**Common pattern:** Each theme's `post.twig` has its own meta area with different styling. A feature added to only one theme will be invisible in the others. Always add matching markup in all four (or confirm the feature is intentionally theme-specific).

**CPT awareness:** Core and vivid themes define `_ct` and check `type.supports` for per-type feature gating. Nova does NOT define `_ct` — use inline conditionals (`type is defined and type.slug != 'post' and type.slug != 'page'`).

---

## 23. Project Skills (`.agents/skills/`)

Reusable, self-contained agent workflows live in `.agents/skills/<name>/SKILL.md` (one folder per skill, English content). Each skill follows the same shape: frontmatter (`name`, `description`) → context map → **mandatory interactive phase** (ask clarifying questions before writing code) → generation steps with skeleton templates → verification + checklist.

### Available Skills

| Skill | Purpose |
|---|---|
| `vale-env-check` | Goal-driven environment readiness check (PHP/extensions/composer/npm/.env/vendor/assets/DB/migrations) with fix commands |
| `vale-payment-gateway` | Full 9-point payment gateway integration (interface class → DI → admin panel → checkout → webhook route) |
| `vale-theme` | Theme scaffolding (`theme.json`, templates/, color variables, menus, page-templates) |
| `vale-api-endpoint` | New REST `/api/v1` resource (controller + routes + abilities + tests) |
| `vale-cli-command` | New `php vale <cmd>` Symfony Console command: `app/Commands/` skeleton, `vale` loader registration, optional DI, `--dry-run`, dummy-data test with rollback, cli.md docs |
| `vale-webhook` | Consume Vale's outbound webhooks (signature-verified receiver) or add a new outbound event |
| `vale-shortcode` | New `[tag]` shortcode in `ShortcodeProcessor` (regex conventions incl. `&quot;` decoding) |
| `vale-mail-driver` | New mail transport adapter implementing `MailInterface` + factory registration |
| `vale-migration` | Phinx migration skeleton with MySQL/PostgreSQL/SQLite compatibility rules |
| `vale-plugin` | Site-specific drop-in extension in `content/plugins` / `content/mu-plugins` (manifest, `register()`, routes, DI, hooks, Twig namespace, CLI) |
| `vale-translations` | Translation extraction/sync/prune workflow + `__()` / `tjs()` usage rules |
| `vale-docs` | Route docs content to Admin vs Developer guide (audience-first questions, sibling-code verification, tone conventions) |
| `vale-integrate` | External system integration via REST API / Webhooks (code generator + Admin configuration) |

---

## 24. Skill Maintenance Rule (IMPORTANT)

Reusable, self-contained agent workflows live in `.agents/skills/<name>/SKILL.md` (one folder per skill, English content). Each skill follows the same shape: frontmatter (`name`, `description`) → context map → **mandatory interactive phase** (ask clarifying questions before writing code) → generation steps with skeleton templates → verification + checklist.

### Available Skills

| Skill | Purpose |
|---|---|
| `vale-env-check` | Goal-driven environment readiness check (PHP/extensions/composer/npm/.env/vendor/assets/DB/migrations) with fix commands |
| `vale-payment-gateway` | Full 9-point payment gateway integration (interface class → DI → admin panel → checkout → webhook route) |
| `vale-theme` | Theme scaffolding (`theme.json`, templates/, color variables, menus, page-templates) |
| `vale-api-endpoint` | New REST `/api/v1` resource (controller + routes + abilities + tests) |
| `vale-cli-command` | New `php vale <cmd>` Symfony Console command: `app/Commands/` skeleton, `vale` loader registration, optional DI, `--dry-run`, dummy-data test with rollback, cli.md docs |
| `vale-webhook` | Consume Vale's outbound webhooks (signature-verified receiver) or add a new outbound event |
| `vale-shortcode` | New `[tag]` shortcode in `ShortcodeProcessor` (regex conventions incl. `&quot;` decoding) |
| `vale-mail-driver` | New mail transport adapter implementing `MailInterface` + factory registration |
| `vale-migration` | Phinx migration skeleton with MySQL/PostgreSQL/SQLite compatibility rules |
| `vale-plugin` | Site-specific drop-in extension in `content/plugins` / `content/mu-plugins` (manifest, `register()`, routes, DI, hooks, Twig namespace, CLI) |
| `vale-translations` | Translation extraction/sync/prune workflow + `__()` / `tjs()` usage rules |
| `vale-docs` | Route docs content to Admin vs Developer guide (audience-first questions, sibling-code verification, tone conventions) |

### Skill Maintenance Rule (IMPORTANT)

**When a change alters any pattern covered by a skill, update the matching `SKILL.md` in the same change set.**

Concretely — if a commit touches any of the following, review and update the listed skill(s):

| Change area | Skills to review |
|---|---|
| `PaymentGatewayInterface`, checkout flow, gateway registration points | `vale-payment-gateway` |
| `routes/api.php` patterns, API response envelope, ability middleware | `vale-api-endpoint` |
| Theme resolution paths, `theme.json` schema, color variable system | `vale-theme` |
| `OutboundWebhookService`, webhook payload/signature scheme, event catalog | `vale-webhook`, `vale-integrate` |
| `routes/api.php` patterns, API response envelope, ability middleware | `vale-api-endpoint`, `vale-integrate` |
| `ShortcodeProcessor` conventions | `vale-shortcode` |
| `MailInterface`, mail factory match arms, mail settings keys | `vale-mail-driver` |
| Phinx config, cross-DB SQL rules, migration conventions | `vale-migration` |
| Translation tooling commands, lang file structure, `VL_I18N`/`tjs()` flow | `vale-translations` |
| Admin/developer docs content (new feature docs, rewrites, outdated pages) | `vale-docs` |
| Build prerequisites, required extensions, CLI commands | `vale-env-check` |
| `.env.example` keys, `scripts/environment-generator.html` env var list | `vale-env-check` |
| Frontend display changes (meta badges, view counts, etc.) | Check & update **all 4 themes** (default, dark, nova, vivid) — see §22 |
| `PluginLoader`, plugin manifest schema, `content/` layout, `plugin:*` commands | `vale-plugin` |
| Feature toggle gating (disabled feature hides its settings/nav/links) | `vale-settings-add` — see §25 |

Treat skill files like documentation: stale skills are worse than no skills. A skill update is usually a small diff (adjust a path, a setting key, or a template snippet) — do it while context is fresh. When adding a brand-new reusable workflow (e.g. "add SSO provider"), create a new skill folder following the same structure instead of growing AGENTS.md.

**Docs sync:** When a skill is added, renamed, removed, or its purpose changes, also update the developer documentation page (`vale-docs/en/latest/developer/agent-skills.md` — catalog table + sidebar entry in `.vitepress/config.js`) in the same change set.

## 25. Feature-Gated Admin UI (IMPORTANT)

A disabled feature must take its admin UI down with it: **settings, sidebar entries, links and pickers that belong to a switched-off module are hidden — not just its front-end routes.** If the shop is off, the shop settings, the Products/Categories sidebar entries and every link leading to a shop screen must be gone too.

### Source of truth

Toggles live in **Settings → General → Features** (`resources/views/admin/settings/_general_features.twig`) — one card per module, each writing a settings key and, on `@change`, the matching `$store.vlFeatures.<key>` so the current screen reacts instantly (no reload).

| Setting key | `vlFeatures` key | Hides |
|---|---|---|
| `blog_enabled` | `blog` | Content › Blog, `posts` nav entry, taxonomies + comments (when CPT is off too) |
| `pages_enabled` | `pages` | `pages` nav entry and page screens |
| `newsletter_enabled` | `newsletter` | whole `group_newsletter` |
| `form_builder_enabled` | `formBuilder` | whole `group_forms` |
| `custom_content_types_enabled` | `cpt` | Content Types screens/entries + CPT pickers (editor, menus) |
| `multi_language_content` | `mlc` | Content › Multi-Language |
| `advertisement_enabled` | `ads` | Content › Advertisement |
| `shop_enabled` | `shop` | E-Commerce settings tab, `products`/`product_categories` nav entries, shop dashboard cards, shop links in menus/forms |
| `subscriptions_enabled` | `subscriptions` | Membership Plans nav entry + subscription settings |
| `registration_enabled` | `registration` | Registration settings tab + nav entry |
| `seo_enabled` | `seo` | SEO settings tab + nav entry, SEO panels/fields (posts, content types) |
| `analytics_enabled` | `analytics` | Analytics settings tab + nav entry |

### The four places a feature is gated

| # | Layer | Where | How |
|---|---|---|---|
| 1 | Server render | `app/Middleware/FlashMiddleware.php::process()` | reads the `..._enabled` keys once, publishes them to Twig with `setGlobal()`, and filters `$navGroups` (drops whole groups, removes children, renames `group_shop` → "Orders & Membership" when only subscriptions are on) |
| 2 | Twig | any admin template | `{% if shop_enabled %}`, `{% if blog_enabled and custom_types %}`, `settings.seo_enabled` — hide links, `<option>`s, cards, sections, form actions |
| 3 | Live, same screen | `resources/views/admin/settings/_scripts.twig` | `Alpine.store('vlFeatures', …)` inside `alpine:init`, `x-show="$store.vlFeatures.shop" x-cloak` on the tab/sub-tab buttons, `$watch` fallbacks when a toggle is switched off while its tab is open, `activateTab()` skipping hidden buttons (`offsetParent === null`) |
| 4 | Admin JS after render | `resources/views/admin/layout.twig` | `window._VL_SHOP_ENABLED`, `_VL_BLOG_ENABLED`, `_VL_PAGES_ENABLED`, `_VL_NEWSLETTER_ENABLED`, `_VL_FORMS_ENABLED`, `_VL_SUBSCRIPTIONS_ENABLED`, `_VL_MEMBERSHIP_ENABLED`, `_VL_MULTI_LANG_ENABLED` for scripts that build UI after page load |

Route level as the last line of defence: a disabled feature's screen or endpoint must not be reachable by typing its URL — guard the controller like `AnalyticsController::index()` (redirect when analytics is off) or `FeedController` (404 when the shop is off), and reject writes to a disabled settings group.

### Rules for new code

1. **Setting/field inside a module** → wrap it in that module's gate (Twig layer) so it disappears when the module is off. Hiding must never delete or reset the stored value.
2. **New link/button/picker into a module** (dashboard card, menu builder option, editor picker, settings link) → same gate; never leave a clickable path to a disabled feature.
3. **New sidebar entry** → add the removal/rename logic in `FlashMiddleware::process()` next to the existing filters.
4. **UI that must react while the toggle is flipped** (anything on the Settings screen) → `$store.vlFeatures.<key>` + `x-show` + `x-cloak`, plus a `$watch` that moves the user to a visible tab.
5. **New feature** → add its toggle card first (`_general_features.twig` + store key + `FlashMiddleware` filter), then gate everything the toggle controls.
6. Default is hidden. A missing gate is a bug: an off module showing its settings, screens or links is exactly what this rule forbids.

Step-by-step for adding a gated setting or a new toggle: `.agents/skills/vale-settings-add/SKILL.md`.

---

*This document is prepared for AI coding agents. Always follow the rules and patterns above when generating code.*

