---
name: vale-translations
description: "Runs Vale's translation workflow safely: extracts missing keys after UI changes (composer translations), prunes unused ones, syncs en/tr/fr language files, creates new locale files, and validates with the CI-mode check before commit. Also guides correct usage of __() in Twig/PHP (with :name and %d placeholders), |e('js') for JS attributes, and the window.VL_I18N + tjs() flow for editor JavaScript strings (scripts/check-editor-i18n.php keeps the two in sync). USE FOR: 'extract translations', 'sync lang files', missing/unused key report, adding translatable strings, pre-commit i18n check. DO NOT USE FOR: translating content stored in the database (posts/pages are per-locale records), locale routing/middleware configuration."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: resources/lang/*.json
---

# Translation Workflow

Language files: `resources/lang/en.json`, `tr.json`, `fr.json` — flat `"source string": "translation"` maps keyed by the **English source text**. Tooling (see `composer.json` scripts):

| Command | What it does |
|---|---|
| `composer translations` | Extracts missing keys from source; writes new keys into lang files; reports unused |
| `composer translations:prune` | Extract + remove unused keys |
| `composer translations:check` | CI mode — no writes, exit 1 on issues |
| `composer translations:sync` | Syncs key sets across all lang files (missing → falls back to source) |
| `composer translations:create` | Creates a new locale file |

## Phase 1 — Ask

1. Goal: extract new strings after a change / prune unused / sync locales / just validate?
2. Which locales to update? (`tr`, `fr`, or a new one via `translations:create`)
3. Should untranslated keys be filled with the English source or left empty? (project convention: keep English source as value until translated)
4. If pruning: confirm it's OK to delete keys not found in source scans.

## Phase 2 — Run

Typical flows:

```bash
# After adding new UI strings
composer translations          # writes missing keys, lists unused
# Fill in tr.json/fr.json values for the newly added keys
composer translations:check    # must pass before commit
```

```bash
# Cleanup drift between locales
composer translations:sync
composer translations:prune    # ask before running — destructive for unused keys
```

When filling values manually, edit only the value side; keys are canonical English strings and renaming them means touching every usage site first.

## Usage rules to verify while reviewing code

### Twig templates

```twig
{{ __('Save Changes') }}                              {{ __('Delete :name', {':name': product.name}) }}
{{ __('%d min read', {'%d': readingTime}) }}
<a title="{{ __('Delete')|e('js') }}" ...>
```

- Placeholders: `:name` style or `%d` style — both supported; match whichever the existing key uses.
- Any translated string placed inside a JavaScript-facing attribute gets `|e('js')`.

### Editor/admin JavaScript (`resources/js/editor/*` and other admin JS)

Strings must be pre-populated server-side via `window.VL_I18N` in `resources/views/admin/layout.twig`, then read with the global `tjs()` helper:

```twig
"Your New String": "{{ __('Your New String')|e('js') }}",
```

```js
button.textContent = tjs('Save');
label = tjs('Page :current of :total', {':current': page, ':total': total});
```

A `tjs()` string that is missing from `VL_I18N` silently falls back to the raw key — when adding editor strings, all three steps are required: layout entry → tjs() call → lang file entries.

The dictionary is rendered into the HTML, not bundled into `editor.js`. Two consequences: rebuilding the bundle does not change any translation, and a tab holding old HTML keeps showing English even after a rebuild until it is reloaded.

## Dynamic strings the extractor cannot see

`composer translations` only finds literal `__('…')` calls. Every call site below resolves its key at runtime and **must** be listed in `scripts/translation-preserve.php`, otherwise the extractor reports it as unused and a prune deletes it:

| Pattern | Example | What to do |
|---|---|---|
| Database value rendered through `__()` | `{{ __(role.name) }}`, `{{ __(perm.name) }}` | list the seeded English values (roles, descriptions, permission names) |
| Label built in PHP | `$item['label'] = 'Comments & Reviews'` (FlashMiddleware), `__('CPT: :name', …)` (RoleController) | list the literal the panel renders |
| Macro argument | `table.th('Name', 'name')`, `table.search('Search roles…')` | the macro runs `__(label)` server-side, so list the labels and placeholders |
| Label passed to a JS helper | `createLabel('Category (optional)')`, `buildButtonGroup(…, 'Primary Button')` | list the label; the helper calls `tjs()` |

`composer translations:prune` rewrites every language file and is destructive for these keys — prefer `composer translations` and a targeted edit of the value side unless the whole catalog is being rebuilt.

## Keeping `VL_I18N` in sync

A `tjs('…')` call whose key is missing from the layout dictionary is indistinguishable from a hardcoded English string, and that is how whole editor dialogs stayed English. Run the checker whenever editor strings change — it lists every `tjs()` key without a `VL_I18N` entry or a catalog row and exits 1:

```bash
php scripts/check-editor-i18n.php
```

So a new editor string is a three-part change: the `"Key": "{{ __('Key')|e('js') }}"` line in `resources/views/admin/layout.twig`, the `tjs('Key')` call, and the key in `en.json` plus each target locale. After editing editor JS, rebuild both artifacts (`npm run build:editor`, `npm run build:editor:dev`); translations themselves still come from the HTML.

## Composing strings

Never concatenate translated fragments — each language orders words differently, so fragments produce phrases like "Düzenle Sayfa":

```twig
{{ __('Edit') }} {{ __('Page') }}                        {# wrong #}
{{ __('Edit') ~ ' ' ~ __('Page') }}                      {# wrong #}
{{ __('Edit :type', {':type': __('Page')}) }}           {# right #}
{{ __('Up to :count files, max :size MB each', …) }}    {# right: one sentence per key #}
```

The same rule applies to numbers and units in JS (`'Showing ' + from + ' of ' + total`): translate the whole sentence with placeholders and fill them at runtime.

## Phase 3 — Verify

- `composer translations:check` exits 0
- Diff review: no accidental mass-deletion of keys (especially after prune); JSON still valid
- Spot-check one changed page in `tr` locale renders translated output
- If new admin/editor strings were added: run `php scripts/check-editor-i18n.php` (must exit 0) and confirm both `VL_I18N` entries and lang-file entries exist together
- If templates, PHP labels or table macros changed: confirm every runtime-resolved key is in `scripts/translation-preserve.php`

## Checklist

- [ ] Extraction run; new keys present in all target locales
- [ ] Untranslated values default to English source (no empty values)
- [ ] `translations:check` green
- [ ] New JS strings wired through VL_I18N + tjs()
- [ ] `php scripts/check-editor-i18n.php` exits 0 (no unregistered editor key)
- [ ] Runtime-resolved keys (DB rows, macro args, PHP-built labels) added to `scripts/translation-preserve.php`
- [ ] No hardcoded user-visible strings left in touched templates
- [ ] No translated string built by concatenating fragments (use one key with placeholders)
