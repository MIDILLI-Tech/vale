---
name: vale-plugin
description: Builds a Vale plugin (drop-in extension) in content/plugins or a must-use drop-in in content/mu-plugins — manifest, register(), routes, DI, hooks, Twig namespace, CLI activation. USE FOR: "write a plugin", "extend Vale without touching core", "mu-plugin", "site-specific tax calculation", "keep customizations across updates". DO NOT USE FOR: theme work (use vale-theme), core feature development inside src/, payment gateway drivers (use vale-payment-gateway), shortcodes (use vale-shortcode), REST endpoints in core (use vale-api-endpoint).
---

# Vale Plugin (drop-in extensions)

Everything shipped in `src/`, `app/`, `config/`, `bootstrap/` and `routes/` is
core: an update overwrites it. A **plugin** is user-owned code that lives in
`content/` — a path the updater never touches (`UpdateService::PROTECTED`) —
and plugs into core through `register(App $app, ContainerInterface $c)`.

Use a plugin when the requirement is site-specific: a bespoke tax rule, a
supplier sync, an internal dashboard, a legal notice, custom routes. On a
running site this is the only extension surface that survives an update. If the
change belongs in the product for every installation, it belongs in core.

## Context map

| Path | Role |
|---|---|
| `content/plugins/<slug>/plugin.php` | Manifest + `register` closure (required) |
| `content/plugins/<slug>/src/` | PSR-4 classes (`ValeApp\Plugins\<Studly>` by default) |
| `content/plugins/<slug>/templates/` | Twig templates, exposed as `@<slug>` |
| `content/mu-plugins/*.php` | Always-active single-file drop-ins (no activation) |
| `src/Application/Plugin/PluginLoader.php` | Boots mu-plugins + enabled plugins |
| `src/Application/Plugin/PluginManager.php` | Activation list (`enabled_plugins`), failure log |
| `src/Application/Plugin/PluginManifest.php` | Manifest validation, slug rules, version gates, capability claims, update feed URL |
| `src/Application/Plugin/PluginSecurityScanner.php` | Token-level static scan of plugin PHP for undeclared sensitive capabilities |
| `src/Application/Plugin/PluginRelease.php` | `updates.json` parser (version, SHA-256, Vale/PHP/plugin requirements) |
| `src/Application/Plugin/PluginUpdateService.php` | Update check, download, hash/ZIP validation, backup, install, migration |
| `bootstrap/app.php` §4.5 | Loader call — after the container, before routes |
| `app/Controllers/Admin/PluginController.php` | `/admin/plugins` screen + enable/disable action (`system.plugins`) |
| `app/Controllers/Front/PluginAssetController.php` | `/plugin-assets/{slug}/{path}` — serves `assets/` |
| `src/Application/Plugin/PluginAssetResolver.php` | Default-deny extension whitelist + path containment |
| `src/Application/Plugin/PluginMigrator.php` | Plugin migrations (`migrations/`), applied tracking in `plugin_migrations` |
| `app/Commands/Plugin*Command.php` | `php vale plugin:list`, `install`, `check-updates`, `update`, `enable`, `disable`, `migrate` |
| `resources/views/admin/plugins/index.twig` | Admin screen template |
| `app/Middleware/FlashMiddleware.php` | `applyFilter('admin_nav_groups', …)` filter point |

## Interactive phase (ask before writing code)

1. **Which kind?** `content/plugins/<slug>/` (can be switched off) or
   `content/mu-plugins/<name>.php` (always on, one file, no activation)?
2. **What does it attach to?** Routes, DI overrides, an event action, a filter,
   Twig templates, an admin nav entry — or plain PHP inside `register()`.
3. **Does it need its own storage?** A settings key under `plugin:<slug>:*`, or
   its own tables (see *Data* below).
4. **Is it environment-specific** (one client, one server) or intended to be
   distributed? Distributed plugins declare `requires_vale` / `requires_php` /
   `requires_ext` and, when they build on another plugin, `requires_plugin`.
5. **Does it touch sensitive APIs?** Anything beyond pure app-level code
   (env access, shell, code execution, file I/O, raw network) must declare the
   matching `capabilities` entries or enabling/installing fails safety checks.

## Layout

```
content/
├── plugins/
│   └── acme-tax/
│       ├── plugin.php          # manifest + register()
│       ├── src/                # ValeApp\Plugins\AcmeTax\*
│       └── templates/          # @acme-tax/…
└── mu-plugins/
    └── acme-force-https.php    # returns a callable, always runs
```

Directory name = slug. Only `^[a-z0-9][a-z0-9_-]{0,63}$` is accepted; anything
else is ignored by the loader and cannot be activated.

## Manifest (`plugin.php`)

A plain PHP file returning an array — never `eval`, never JSON:

```php
<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\App;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;

return [
    'name'          => 'Acme Tax Rules',
    'version'       => '1.0.0',
    'author'        => 'Acme',
    'description'   => 'One line shown in the plugin list',
    'license'       => 'MIT',
    'homepage'      => 'https://example.com/acme-tax',
    'requires_plugin' => ['acme-core' => '^1.0'], // must be installed + enabled
    'requires_vale' => '^0.77',     // Composer constraint; skipped when unmet
    'requires_php'  => '>=8.3',
    'requires_ext'  => ['curl', 'gd'], // PHP extensions; unmet → skipped
    'capabilities'  => ['filesystem_read'], // sensitive APIs the plugin uses (see below)
    'priority'      => 10,          // lower registers first; slug breaks ties

    'settings'      => '/admin/acme-tax', // optional Settings link on /admin/plugins
    'updates'       => 'https://example.com/acme-tax/updates.json', // optional update feed

    // Optional declarative sidebar entry (no nav-filter code needed):
    'admin'         => [
        'label'      => 'Acme Tax',              // required, passed through __()
        'href'       => '/admin/acme-tax',       // required, must start with /admin
        'group'      => 'group_content',         // optional; unknown → top-level link
        'permission' => 'settings.view',         // optional RBAC slug (null = everyone)
        'icon'       => 'M3 12l2-2m0 0l7-7',     // optional SVG path
    ],
    // 'namespace'  => 'ValeApp\\Plugins\\AcmeTax', // default: derived from slug

    'register' => static function (App $app, ContainerInterface $c): void {
        $events = $c->get(EventDispatcherInterface::class);

        $events->addAction('order.paid', static function ($order) use ($c): void {
            // site-specific side effect
        });

        $app->get('/acme/health', function ($request, $response) {
            $response->getBody()->write('ok');

            return $response->withHeader('Content-Type', 'text/plain');
        });
    },
];
```

Rules:

- `register` is **required** and must be callable; the loader passes the Slim
  app and the PSR-11 container.
- Route and middleware callbacks must be **non-static closures** (or invokable
  classes): Slim binds them to the container, and a static closure cannot be
  bound (it fails with `bindToContainer(): ... null returned`).
- `requires_vale` / `requires_php` accept Composer constraints; `requires_ext`
  names PHP extensions. An unmet value skips the plugin with a recorded reason —
  it never fatals.
- `capabilities` declares the sensitive PHP APIs the plugin's code touches.
  `PluginSecurityScanner` tokenizes every plugin source file (a bundled
  `vendor/` included) and refuses enable/install/update when an **undeclared**
  capability is found: `env` (`getenv`, `putenv`, `$_ENV`, `$_SERVER`),
  `process` (`exec`, `shell_exec`, `system`, `passthru`, `proc_*`, `popen`,
  `pcntl_*`, backticks), `code_execution` (`eval`, `assert`),
  `filesystem_read` (`file_get_contents`, `fopen`, `fread`, `file`, `readfile`,
  `scandir`, `glob`, `is_file`, `is_dir`, `file_exists`, `filesize`,
  `filemtime`, `filectime`, `parse_ini_file`), `filesystem_write`
  (`file_put_contents`, `fwrite`, `fputs`, `unlink`, `rmdir`, `mkdir`,
  `rename`, `copy`, `chmod`, `chown`, `touch`) and `network` (curl,
  `fsockopen`, `pfsockopen`, `stream_socket_client`, DNS lookups). It is a
  static name match, not a sandbox: qualified calls count (`\shell_exec()`,
  `Support\shell_exec()` — the last segment is looked up), while method calls,
  method definitions, `::` references and `new \Class()` are ignored;
  classification follows the function name, so `file_get_contents('https://…')`
  needs `filesystem_read`, not `network`. Calls it cannot resolve —
  `call_user_func()`, variable functions (`$fn('id')`), `use function … as …`
  aliases, `include`/`require` of a non-`.php` file (only `*.php` files are
  scanned), `SplFileObject`, `DirectoryIterator`, `hash_file()`, `stat()` and
  names computed at runtime and DBAL reads are missed; `use function … as`
  imports (group imports too), parenthesized constant calls (`('sys' . 'tem')()`)
  and literal concatenations (`'sys' . 'tem'`) are resolved. `.php`, `.inc`, `.phtml` and
  `php3`–`php8` files are scanned; literal `call_user_func('fn', …)` and
  single-assignment `$fn = 'fn'` calls are resolved; `fopen()`'s mode literal
  decides read/write (`+` or a dynamic mode needs both). Other calls are
  classified by name alone. Declare what the code really
  does and never treat the scan as a guarantee. Extra entries are harmless.
  The gate runs at enable/ZIP-install/update time, not on every request.
- `requires_plugin` (`['acme-core' => '^1.0']`, or a plain list) needs the other
  plugin to be **installed and enabled** and to satisfy the constraint.
  `plugin:enable` refuses otherwise and the loader re-checks against what
  actually loaded. Dependencies are never auto-enabled.
- `description`, `license` and `homepage` are display metadata (`homepage` must
  be absolute http/https and renders both a link and a **Website** button);
  `settings` renders a **Settings** button and makes the plugin name clickable;
  `admin` adds a declarative sidebar entry (below).
- `updates` is an absolute http/https URL (HTTPS is required outside local
dev), pointing to the plugin's `updates.json` feed. Without it the plugin is
  still installed and managed manually.
- The namespace defaults to `ValeApp\Plugins\<Studly>`; `src/` is autoloaded by
  the loader, so **do not** touch `composer.json`.

## Must-use drop-ins (`content/mu-plugins/`)

One file, returns a callable — no manifest, no activation, loaded before every
regular plugin. Display metadata is optional and lives in a WordPress-style
header block; without it the list falls back to the file name and empty fields:

```php
<?php

/**
 * Plugin Name: Demo Must-Use
 * Version: 1.0.0
 * Description: Adds a response header.
 * License: MIT
 * Website: https://example.com
 */

declare(strict_types=1);

return static function (\Slim\App $app, \Psr\Container\ContainerInterface $c): void {
    $app->add(function ($request, $handler) {
        $response = $handler->handle($request);

        return $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
    });
};
```

Use for rules that must never be off: HTTPS, legal headers, tax overrides. These
files have no manifest and no activation state, so they are installed over the
filesystem — the admin ZIP uploader and `plugin:install` accept regular plugins
only. `MuPluginMetadata` reads `Plugin Name`, `Version`, `Description`,
`License` and `Website` / `Plugin URI`; only absolute http(s) website URLs are
accepted.

## Extension points available today

| Need | How |
|---|---|
| Route | `$app->get()/post()/map()` inside `register()` — plugin routes register before the `/{path:.+}` page catch-all |
| DI override | `$c->set(Interface::class, $implementation)` (services resolved afterwards use it) |
| Actions / filters | `EventDispatcherInterface` (`order.paid`, `post.updated`, `form.submitted`, …) |
| Admin nav entry | `addFilter('admin_nav_groups', …)` — entries get the same base-path prefixing as core links |
| Declarative admin link | `'admin' => [...]` in `plugin.php` — injected by `PluginLoader` on load; gone when the plugin is off |
| Twig templates | Put them in `templates/` and include `@<slug>/card.twig` from your own code paths |
| Home page title | `addFilter('front_page_title', fn ($title, $entityTitle, $path) => …)` — `$entityTitle` is null on the home/listing pages; check the path to scope it |
| Settings storage | `SettingsRepositoryInterface` with `plugin:<slug>:<key>` keys |
| Admin routes | Add them under `/admin/…` in `register()` and guard with your own permission check |

Declarative `admin` entries are only injected when the plugin **loads** (enabled,
compatible, `register()` did not throw), so activation drives the sidebar with no
cache to clear. `permission` is enforced by `FlashMiddleware`'s nav filter, and
`href` must be an `/admin` path — external URLs are ignored.

A **top-level** entry (`group` omitted/unknown) is part of the sidebar drag &
drop: its `plugin-<slug>` id is accepted by `/admin/nav-order` while the plugin is
enabled, so its position is saved with the core items. Top-level core and plugin
entries share the same reorder mode. An entry placed inside a group is also
reorderable within its own group — core and plugin children both get a drag
handle, but a child cannot be moved into another group. The Plugins list's
eye action hides/shows the link globally (`plugin_nav_hidden`) without disabling
the plugin. For anything more than a plain link (badges, `label_cpt`), use the
filter directly.

Not available yet (do not document as working): front-end template overrides —
a plugin cannot replace `post.twig`; use a child theme for that.

## Assets

`content/plugins/<slug>/assets/` is served by core at
`/plugin-assets/<slug>/<path>` — no web-server alias, no rewrite rule.

```php
$app->get('/acme/page', function ($request, $response) use ($c) {
    $twig = $c->get(\Slim\Views\Twig::class);

    return $twig->render($response, '@acme/page.twig', [
        'css' => plugin_asset_url('acme-tax', 'css/acme.css'),
    ]);
});
```

`plugin_asset_url($slug, $path)` adds the APP_URL subdirectory (it never adds a
locale prefix — one asset set serves every content language).

Serving rules, enforced by `PluginAssetResolver` — do not try to bypass them:

| Rule | Detail |
|---|---|
| Enabled only | A disabled plugin serves nothing (404), including when `VL_DISABLE_PLUGINS` / `.disabled` is active |
| Whitelist | `css`, `js`, `mjs`, `json`, `map`, `txt`, `vtt`, `svg`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `avif`, `ico`, `woff`, `woff2`, `ttf`, `otf`, `eot`, `mp4`, `webm`, `mp3`, `wasm` |
| No code / markup | `php`, `html`, `htaccess`, … are refused by the whitelist — a plugin must never serve executable or renderable markup from its assets |
| Contained | Traversal (`..`), dotfiles and symlink escapes return 404 |
| Cached | `Cache-Control: public, max-age=3600`; bump a query string (`?v=2`) after changing a file |

## Migrations

Schema changes live in `content/plugins/<slug>/migrations/`, one file per
migration, named `YYYYMMDDHHMMSS_snake_name.php` (the name is the order).
A file returns an `up` callable or the callable itself; adding a `down`
callable next to it makes the migration reversible:

```php
<?php

declare(strict_types=1);

return [
    'up' => static function (\Doctrine\DBAL\Connection $db): void {
        $db->executeStatement(
            'CREATE TABLE ' . VL_DB_PREFIX . 'acme_notes (
                id INTEGER PRIMARY KEY,
                order_id INT NOT NULL,
                note VARCHAR(255) NOT NULL
            )'
        );
    },
];
```

- SQL must work on MySQL, PostgreSQL **and** SQLite (see AGENTS §14): `INT`,
  `VARCHAR`, `CURRENT_TIMESTAMP`, `COALESCE()`, no `||` concatenation, no
  engine-specific clauses. Prefix every table with `VL_DB_PREFIX`.
- Applied file names are stored per plugin in the `plugin_migrations` setting —
  a migration runs once, survives updates, and a renamed file runs again.
- The runner is not transactional (DDL auto-commits on MySQL): write one
  statement group per file and keep migrations idempotent where possible
  (`CREATE TABLE IF NOT EXISTS`, additive `ALTER`).

When they run automatically: **on activation** (`plugin:enable`, the admin
toggle) and in **cron** (`cron:run` / page-load cron), so files added after a
deploy are applied without a request ever running DDL. Manual runs:

```bash
php vale plugin:migrate --dry-run      # list pending files
php vale plugin:migrate                # every enabled plugin
php vale plugin:migrate acme-tax       # one plugin
```

### Rollback

```php
return [
    'up'   => static function (\Doctrine\DBAL\Connection $db): void { $db->executeStatement('CREATE TABLE ...'); },
    'down' => static function (\Doctrine\DBAL\Connection $db): void { $db->executeStatement('DROP TABLE ...'); },
];
```

```bash
php vale plugin:migrate --rollback acme-tax --dry-run   # show what would revert
php vale plugin:migrate --rollback acme-tax             # newest migration only
php vale plugin:migrate --rollback --all acme-tax       # every applied migration, newest first
```

- Rollback is always explicit and per plugin — **never** automatic on deactivate
  or cron. Turning a plugin off keeps its tables and its migration record.
- `down` must undo exactly what `up` did and must be safe to skip (no `DROP` of
  foreign data). A migration without `down` (or one that returns a bare
  callable) aborts the rollback and stays recorded.
- A failing `down` aborts on that file; already reverted files keep their new
  state and are unrecorded.

A failing migration is reported with its file name and is **not** recorded;
plugins activated through the admin panel stay enabled (its `register()` still
runs) so the operator can fix the file and re-run `plugin:migrate`. Mu-plugins
have no directory of their own — put schema work in a regular plugin.

## Admin screen

A **Global switch** card at the top (`plugins_enabled` setting, default on) skips
every plugin and mu-plugin and 404s their assets without changing
`enabled_plugins`. While it is off the sidebar entry is hidden (`FlashMiddleware::hidePluginsNav`),
but the screen stays reachable from Settings → Features and shows the warning.
The operator kill switch (`.disabled` / `VL_DISABLE_PLUGINS`) still overrides it.

Enabling and disabling flush the full-page cache (web toggle and CLI), so a
disabled plugin's cached guest pages disappear immediately.

**Delete** on a row always disables the plugin and removes its directory. Without
**Include settings and data** its `plugin:<slug>:*` settings, failure entry,
update cache and migration bookkeeping stay for a reinstall; with the checkbox,
applied migrations are rolled back first and those records are deleted too. A
failed/missing rollback aborts before files are removed. The pending-migration
count and last result under **Status** come from `PluginMigrator::pending()` and
`lastResult()` — keep both working when you change migration storage. The list's
search and pagination follow `client_side_search_fetch_limit()`
(`CLIENT_SIDE_SEARCH_FETCH_LIMIT`, mirrored as `VL_CLIENT_SEARCH_FETCH_LIMIT`).

`/admin/plugins` (`system.plugins` permission, System group in the sidebar —
one slug covers viewing, toggling and installing; there is no `plugins.use`)
lists every installed plugin with name, description, license (under **Version**),
homepage action, Vale/PHP/extension/plugin requirements, status, pending
**migrations** (count + last result) and the last recorded error, then toggles
activation through the same `PluginManager` the CLI uses. Row actions are
Update, power (Enable/Disable), gear (Settings), eye (Hide/Show in navigation),
globe (Website) and trash (Delete). Unmet plugin dependencies get their own amber
note. Always-on drop-ins appear as **Must-Use** rows in the same table (search
and pagination included), with no checkbox and a disabled **Delete** action. The
page only reads manifests and
mu-plugin headers and never calls a plugin's `register()` hook, so a crashing
`register()` cannot
take the screen down.

**Install plugin** uploads a ZIP through `POST /admin/plugins/install`.
`PluginUpdateService::installFromArchive()` extracts it safely, validates the
manifest, derives the slug from the top-level folder (or the ZIP file name when
`plugin.php` is at the root), installs under `content/plugins/<slug>/` and leaves
the plugin **disabled**. It refuses to overwrite an existing directory. The CLI
twin is `php vale plugin:install <zip> [--dry-run]`; keep the two paths on the
same service. Uploads are **regular plugins only** — a ZIP without `plugin.php`
is rejected, and must-use drop-ins remain filesystem-managed. Do not auto-enable
an uploaded package.

Plugins stay on their own screen. The only link from Settings is the small
Plugins card under **General → Features** (`Manage plugins` → `/admin/plugins`);
do not move activation or plugin settings into a settings tab — that would make a
disabled plugin look like a core feature.

## Updates

An optional `updates.json` feed lets the admin screen check and install a new
version without touching `content/` by hand. The feed is **release metadata**,
not executable code:

```json
{
  "version": "1.2.0",
  "download_url": "https://example.com/acme-tax-1.2.0.zip",
  "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "requires_vale": "^0.77",
  "requires_php": ">=8.3",
  "requires_ext": ["curl"],
  "requires_plugin": {"acme-core": "^1.0"},
  "changelog": ["Fixed the tax threshold"]
}
```

The ZIP contains `plugin.php` either at its root or inside one top-level folder.
The package's manifest is validated again after download, including its own
`requires_*` values and the installed/enabled dependency versions. `sha256` is
mandatory; the download must use HTTPS and is fetched through
`SafeRemoteFetcher` (SSRF/redirect checks).

`/admin/plugins` has **Check for plugin updates** and a per-row **Update** button. The
same operations are available through `php vale plugin:check-updates [slug]`
and `php vale plugin:update <slug>`. The last check result is cached in the
`plugin_update_status` setting; checking does not download the package.
`CronService` also calls `checkAll()` at most once every 24 hours (when
`updates_enabled` and `update_auto_check` are on) so the dashboard can show a
**Plugin updates** notice. Plugin updates are never applied automatically.
Applying an update:

1. downloads to `storage/cache/plugin-updates/` and verifies SHA-256;
2. extracts with per-entry path validation (no traversal/symlinks);
3. backs up the old files to `storage/backups/plugins/plugin-<slug>-…/`;
4. swaps the directory and runs pending migrations when the plugin is enabled;
5. restores the backup automatically if installation or migration fails.

Before step 3, a package that declares capabilities the installed version does
not stops the update: the admin screen renders a confirmation page and the CLI
needs `--acknowledge-capabilities`. The approval is bound to that exact version,
so a package swapped in between asks again — and the stop is not recorded as a
plugin error.

Plugin update **checks** share Vale's software-update schedule, but applying an
update is explicit. Vale core updates still never touch `content/`; a plugin's
own feed decides when a newer version exists.

## Activation and operations

```bash
php vale plugin:install <zip>        # validate, install disabled; --dry-run to preview
php vale plugin:list                 # slug, name, version, requires, status, last error
php vale plugin:check-updates [slug] # refresh update status (no downloads)
php vale plugin:update <slug>        # download, verify, back up and install one update
php vale plugin:enable <slug>        # refuses missing/incompatible plugins
php vale plugin:disable <slug>       # keeps files and settings
```

Activation is a JSON array in the `enabled_plugins` setting; the code stays on
disk. Updates never remove `content/`, so the list and the code both survive.

**Emergency brake** — when a plugin breaks the panel itself:

```bash
touch content/plugins/.disabled      # every plugin off, mu-plugins included
VL_DISABLE_PLUGINS=1 php vale plugin:list
```

or set `VL_DISABLE_PLUGINS=1` in the environment. The marker check happens
before any plugin file is included, so it works even when `register()` is what
crashes.

## Failure handling

Each plugin is isolated and validated before any code runs:

- manifest parse error, bad slug, missing directory, unmet version/extension,
  missing or disabled plugin dependency → skipped with the reason
- `register()` throws → recorded in `plugin_failures` (one write per distinct
  message, never per request) and logged to `errors.log`
- one broken plugin never prevents the others, or the site, from loading

## Data

Small values belong in settings (`plugin:<slug>:*`). Anything structural belongs
in `migrations/` (see *Migrations* above): applied on activation/cron, tracked per
plugin, revertible with a `down` step. Never create tables inside `register()` —
that runs on every request, not once.

## Security rules (non-negotiable)

- **Never** read a slug from a request and `include` it. Slugs come from the
  directory scan or the activation list, and both pass `PluginManifest::isValidSlug`.
- `Register` runs with full application privileges: no unauthenticated trigger,
  no `eval`, no `extract()` on request data.
- Escape output (`{{ }}` in Twig does it; `e()` in PHP helpers).
- All SQL through Doctrine DBAL with bound parameters; `VL_DB_PREFIX` for tables.
- Admin-only screens need `AdminMiddleware` + a permission check, exactly like
  core admin routes.

## Verification checklist

```bash
php -l content/plugins/<slug>/plugin.php
php vale plugin:install <zip> --dry-run    # expects "Would install plugin"
php vale plugin:list                       # status "enabled", no error column
php vale plugin:disable <slug> --dry-run   # expects "Would disable"
composer test:unit -- --filter Plugin      # loader/manager/manifest/asset tests
curl -sI http://localhost/plugin-assets/<slug>/style.css   # 200 + text/css
curl -sI http://localhost/plugin-assets/<slug>/plugin.php  # 404
php vale plugin:disable <slug> && curl -sI http://localhost/plugin-assets/<slug>/style.css  # 404
php vale plugin:migrate --dry-run          # pending migrations listed
php vale plugin:migrate                    # applied once, then "nothing pending"
php vale plugin:migrate --rollback <slug> --dry-run   # preview the down steps
php vale plugin:migrate --rollback <slug>             # revert the newest migration
```

Then load a page that exercises the plugin, and check `storage/logs/errors.log`
plus the `plugin_failures` setting for recorded errors.

## Docker

- **Mutable** flavors bind-mount the project, so `content/` is the host folder —
  edit and reload.
- **Immutable** images ship an empty `content/`; mount the `vale-content` named
  volume (see `docker-compose.prod.yml`) and drop plugins into the container:
  `docker cp ./acme-tax <container>:/var/www/html/content/plugins/`.
- `content/` is excluded from images and git (`.gitkeep` only) — never commit a
  site's plugin code into the Vale repository.
