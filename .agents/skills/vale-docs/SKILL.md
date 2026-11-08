---
name: vale-docs
description: "Routes documentation content to the right guide (Admin vs Developer) by asking what changed and who needs it, verifying claims against the sibling Vale codebase, then writing task-oriented admin docs or technical developer docs following the repo conventions. USE FOR: 'update the docs', admin guide rewrite, developer guide update, where should this be documented, docs for a new feature, fix outdated docs. DO NOT USE FOR: UI string translations (use vale-translations), code changes themselves, release notes/changelog writing."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: vale-docs/en/latest/developer/documentation.md
---

# Route & Write Documentation

Vale has two guides with different audiences. Putting content in the wrong one is the most common docs mistake — this skill forces the routing decision **before** writing.

- `vale-docs/en/latest/admin/` — **site managers** (non-technical). Task-oriented, minimally technical.
- `vale-docs/en/latest/developer/` — **theme developers, integrators, core contributors**. Technical detail lives here.

## Context map

- Vale source (sibling checkout): `../vale/` — verify every behavioral claim here (controllers in `app/Controllers/`, services in `src/`, seeders in `database/seeds/`, templates in `resources/views/`, routes in `routes/`).
- Docs root: `vale-docs/en/latest/` — new/edited pages go under `latest/`, never in versioned `v*` snapshots (read-only).
- Tone rules: `vale-docs/en/latest/developer/documentation.md` → "Admin Docs Tone".
- Placeholders `@phpVersion@` etc. resolve at build time from `.vitepress/config.js` `VARIANTS` — never hardcode versions.
- Checks: `node scripts/check-anchors.js`, `node scripts/check-sidebar.js`, `npm run docs:build`.

## Phase 1 — Ask (required before writing any docs)

1. What changed in the product? (new feature / behavior fix / new setting / new CLI command / new API)
2. Who needs to read this? (site admin doing a task / sysadmin with terminal / theme developer / integrator / core contributor)
3. Does it need a terminal, `.env`, or code? (yes → sysadmin box or developer page, never plain admin prose)
4. Which sibling code paths prove the behavior? (controller/service/template/route file + line — read them, don't trust memory)
5. Does a page for this already exist? (extend it; one workflow = one source page, others link to it)

## Phase 2 — Generate

Apply the routing rules:

- **Admin page**: what to click + what happens. No permission slugs (link `admin/users#roles-permissions`), no table/column names, no JSON value examples, no verification algorithms, no exact shortcode/placeholder formats (link `developer/shortcodes`). Searchable option lists (crawler names, payment methods, ad placements) are kept — they are lookup keys.
- **Sysadmin content inside admin pages**: wrap in `::: tip FOR SYSADMINS` with a "forward this to your host" line. Never delete terminal/`.env` material; separate the audience instead.
- **Developer page**: full technical detail (slugs, storage layout, signatures, payload shapes, code skeletons). Link back to the admin workflow page.
- **Single source**: e.g. 2FA workflow lives in `admin/users`; `settings.md` and `registration.md` link there.
- **Consistency**: feed URLs must match `routes/api.php` (`/api/v1/feed/...`); role tables must match `database/seeds/InitialSeeder.php`; 2FA field labels must match `resources/views/admin/users/form.twig`; feature lists (themes, fonts, export types) must match code, not memory.
- **Placeholders stay**: `@key@` tokens and placeholder screenshots are replaced at build/release — never "fix" them into literals.

## Phase 3 — Verify

- `npm run docs:build` — must pass; it chains `check:anchors` + `check:sidebar` + `check:spelling` + the VitePress dead-link build.
- Spelling (cspell) fails the build on any unknown word, and it checks inline code spans too. Legitimate product/provider/brand names (payment-method values, crawler names, brand names) go into the `words` array in `../vale-docs/cspell.json`, sorted case-insensitively; real typos are fixed in the page. Never reword correct text to silence the checker.
- `node scripts/check-anchors.js` and `node scripts/check-sidebar.js` individually when you need the detail — fix every hit (note: `import:wordpress`-style anchors slugify `:` to `-`).
- `npm run check:external` for real external links — periodic, not on every change (`--strict` in CI).
- If UI strings were touched: `composer translations:check` in `../vale/`.
- If a new reusable pattern emerged, update this skill in the same change set (stale skills are worse than none).

## Checklist

- [ ] Audience decided before writing (admin / sysadmin box / developer)
- [ ] Every behavioral claim verified against `../vale/` code
- [ ] No slugs/storage/algorithms leaked into admin pages
- [ ] Terminal material boxed, not deleted
- [ ] Cross-links point at existing anchors (build proves it)
- [ ] `npm run docs:build` green — anchors + sidebar + cspell + VitePress
- [ ] Placeholders untouched, feed URLs / role tables / labels match code
