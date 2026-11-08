---
name: vale-env-check
description: "Verifies the Vale development environment is ready before any other work: PHP 8.3+ and required extensions, Composer, Node/npm, .env, vendor/, node_modules/, APP_KEY, database connectivity, pending migrations, and built assets. Interactive — asks what the user wants to do (run the app, build assets, run tests) and only checks what that goal needs, then reports a pass/fail table with exact fix commands. USE FOR: environment setup check, 'is my dev env ready', before starting development, CI-like local sanity check, troubleshooting 'class not found' or migration errors caused by a broken environment. DO NOT USE FOR: writing features (use vale-* scaffolding skills), deploying to production servers."
license: AGPL-3.0
metadata:
  project: vale/vale
  scope: developer-experience
---

# Vale Environment Check

Goal-driven readiness probe for this repository. Never assume — run commands and verify.

## Phase 1 — Ask (required)

Ask the user what they are about to do (use the question tool):

1. **Run the app locally** (browser / XAMPP / docker)
2. **Build assets** (Tailwind / esbuild / fonts)
3. **Run tests** (PHPUnit)
4. **Everything** (full check)

Then run only the checks for that goal.

## Phase 2 — Checks

Run checks with the shell tool. Collect results as a table: `Check | Status | Fix`.

### Common (always)

| Check | Command | Pass condition |
|---|---|---|
| PHP version | `php -v` | ≥ 8.3 |
| Composer | `composer --version` | Composer 2.x installed |
| `.env` file | `Test-Path .env` (or `ls`) | exists; if not → `Copy-Item .env.example .env` |
| Vendor deps | `Test-Path vendor\autoload.php` | exists; if not → `composer install` |

### Goal 1 — Run the app

| Check | Command | Pass condition | Fix |
|---|---|---|---|
| Required extensions | `php -m` | contains: `pdo_mysql` (or `pdo_pgsql`/`pdo_sqlite` per DB_DRIVER), `gd`, `mbstring`, `json`, `sodium`, `xml`, `intl`, `curl`, `fileinfo`, `zip`, `bcmath` | enable in `php.ini`, restart web server/PHP |
| System health | `php vale doctor` | no FAIL lines | follow doctor's own hints |
| APP_KEY | inspect `.env` for non-empty `APP_KEY` | set | `php vale key:generate` |
| ENCRYPT_PII | inspect `.env` for `ENCRYPT_PII` | present (`true`/`false`) with a comment | add `ENCRYPT_PII=false` (opt-in address encryption) to `.env`; see `developer/security` docs |
| DB connectivity | covered by `php vale doctor` | DB section OK | fix `DB_*` vars in `.env` |
| Pending migrations | `php vale migrate --dry-run` | no pending, or user approves running them | confirm, then `php vale migrate` |
| Built assets exist | `Test-Path public\assets\build\app.css` | exists | see Goal 2 |
| Storage writable | try creating+deleting a temp file under `storage/` | writable | fix permissions (chmod on Linux) |

Note: `php vale doctor` is the authoritative health check — prefer it over ad-hoc probes when it covers the question.

### Goal 2 — Build assets

| Check | Command | Pass condition | Fix |
|---|---|---|---|
| Node | `node -v` | ≥ 20.x | install Node.js LTS |
| npm | `npm -v` | installed with Node | — |
| node_modules | `Test-Path node_modules` | exists | `npm install` |
| RAM headroom | warn only | builds need ~1 GB; low-RAM systems need swap | add swap / close apps |
| Build works | `npm run build` (ask before running — takes minutes) | exits 0, writes `public/assets/build/*` | read the error output |

### Goal 3 — Run tests

| Check | Command | Pass condition | Fix |
|---|---|---|---|
| Dev dependencies | `Test-Path vendor\bin\phpunit` (`.bat` on Windows) | exists | `composer install` (without `--no-dev`) |
| DB driver choice | ask user: MySQL or SQLite? | `.env` `DB_DRIVER` matches intent | switch `DB_DRIVER` in `.env`; SQLite path default `storage/database.sqlite` |
| Test suites | `composer test` — or per suite: `composer test:unit` / `test:integration` / `test:feature` | exit 0 | investigate failures |
| Test namespaces (PSR-4) | `Select-String -Path (Get-ChildItem tests -Recurse -Filter *.php).FullName -Pattern '^namespace Tests\\'` | zero matches (`composer.json` maps `ValeApp\Tests\` to `tests/`) | rename `namespace Tests\...` → `ValeApp\Tests\...` in the flagged files; verify with `composer dump-autoload -o` (see AGENTS.md §11) |

AGENTS.md rule: after changing SQL-touching code, tests should be run with **both** drivers (MySQL and SQLite) before committing.

## Phase 3 — Report

Print a compact results table. For every failed row, show the single fix command. Then ask:

> "Shall I apply the fixes now?"

If yes, run fixes one by one (never batch destructive ones like `db:reset` without explicit confirmation), then re-run the failed checks until green.

## Guardrails

- Read-only diagnostics first; state-changing actions (`npm install`, `composer install`, `php vale migrate`, `key:generate`) require user approval.
- NEVER run `php vale db:reset` as part of an env fix without an explicit "yes, wipe it".
- Do not edit `.env` values silently — propose the diff first.
- On Windows/XAMPP setups, remind the user that enabling extensions means editing the correct `php.ini` (check `php --ini`), then restarting Apache/PHP-FPM.

## Env generator sync

When `.env.example` keys are added, removed, or renamed (or `config/*.php` env defaults change), update the following in the **same change set** so the real env surface stays consistent everywhere:

1. `scripts/environment-generator.html` — the browser-based `.env` generator (add/remove the field, its JS read in `generateEnvContent()`, and the matching `rawEntries.push(...)` line).
2. `public/install.php` — the installer's generated `.env` heredoc template (matches `.env.example` already, but verify the new key appears).
3. Vale docs — `C:\xampp2\htdocs\vale-docs\en\latest\developer\env.md` (the env reference must stay in sync with `.env.example` per its own maintenance note).

Quick cross-check: search for the key across `.env.example`, `scripts/environment-generator.html`, `public/install.php`, and `vale-docs/en/latest/developer/env.md` and confirm it exists (with a meaningful description) in all four.
