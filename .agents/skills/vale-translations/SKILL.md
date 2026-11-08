---
name: vale-translations
description: "Runs Vale's translation workflow safely: extracts missing keys after UI changes (composer translations), prunes unused ones, syncs en/tr/fr language files, creates new locale files, and validates with the CI-mode check before commit. Also guides correct usage of __() in Twig/PHP (with :name and %d placeholders), |e('js') for JS attributes, and the window.VL_I18N + tjs() flow for editor JavaScript strings. USE FOR: 'extract translations', 'sync lang files', missing/unused key report, adding translatable strings, pre-commit i18n check. DO NOT USE FOR: translating content stored in the database (posts/pages are per-locale records), locale routing/middleware configuration."
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

## Phase 3 — Verify

- `composer translations:check` exits 0
- Diff review: no accidental mass-deletion of keys (especially after prune); JSON still valid
- Spot-check one changed page in `tr` locale renders translated output
- If new admin/editor strings were added: confirm both `VL_I18N` entries and lang-file entries exist together

## Checklist

- [ ] Extraction run; new keys present in all target locales
- [ ] Untranslated values default to English source (no empty values)
- [ ] `translations:check` green
- [ ] New JS strings wired through VL_I18N + tjs()
- [ ] No hardcoded user-visible strings left in touched templates
