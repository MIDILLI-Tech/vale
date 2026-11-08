---
name: vale-settings-add
description: >
  Adds a new settings field or section to Vale's admin Settings page by following
  the existing tab/sub-tab/form patterns. USE FOR: 'add settings field', 'add setting to admin',
  'new settings option', 'add toggle to settings', 'extend settings page'.
  DO NOT USE FOR: adding mail templates (separate workflow), adding payment gateways (use vale-payment-gateway),
  adding editor blocks (use vale-editor-block), general admin UI changes.
---

# Vale Settings Add

Add a new settings field or section to Vale's admin Settings page.

## Context Map

Key files and locations:

| What | Where |
|------|-------|
| Settings templates | `resources/views/admin/settings/` |
| Tab container | `_<tabname>.twig` (e.g. `_general.twig`, `_content.twig`) |
| Sub-tab partial | `_<tabname>_<subtab>.twig` |
| Top-level entry | `index.twig` (includes all tab panels) |
| JS wiring | `_scripts.twig` (activateTab, activateSubTab, dirty tracking) |
| Macro for fields | `resources/views/admin/macros/vl-setting.twig` (`vl.attrs()`) |
| Controller | `app/Controllers/Admin/SettingsController.php` (group-agnostic) |
| Translations | `resources/lang/en.json`, `resources/lang/tr.json` |
| Feature gating (nav + Twig globals) | `app/Middleware/FlashMiddleware.php::process()` — reads `..._enabled`, `setGlobal()`s them, filters `$navGroups` |
| Admin nav | `config/admin-nav.php` |
| Feature toggles (source of truth) | `_general_features.twig` (General → Features cards) |
| Feature toggles store | `Alpine.store('vlFeatures', ...)` in `_scripts.twig` |

## Interactive Phase (REQUIRED)

Before writing any code, ask the user:

1. **Where does this setting belong?**
   - Which top-level tab? (General, Content, E-Commerce, Registration, SEO, Mail, Privacy, Analytics, Maintenance, Advanced)
   - Which sub-tab within that tab? (or create a new sub-tab?)
   - Which section heading within the sub-tab?

2. **What type of setting is it?**
   - Simple text/number/select/textarea
   - Toggle/checkbox with dependent fields
   - New feature toggle that controls tab/sub-tab visibility

3. **Which feature does it belong to?**
   - Is it part of an existing toggleable module (`blog_enabled`, `pages_enabled`, `newsletter_enabled`, `form_builder_enabled`, `custom_content_types_enabled`, `multi_language_content`, `advertisement_enabled`, `shop_enabled`, `subscriptions_enabled`, `registration_enabled`, `seo_enabled`, `analytics_enabled`)? → it MUST be hidden when that module is off (§25 in AGENTS.md).
   - Or is it a new toggle that controls a tab/sub-tab? Which `vlFeatures` key will it use?

4. **Is it translatable?**
   - Will it have localized values (like site_name, footer_copyright)?
   - Or is it a single value (like a checkbox)?

5. **Search visibility:**
   - What keywords should it be searchable by?

## Generation Steps

### Step 1: Determine the target file

- **Existing sub-tab**: Add the field directly to the partial (e.g. `_general_site.twig`).
- **New sub-tab**: Create `_<tabname>_<subtab>.twig`, add include in container, wire in `_scripts.twig`.
- **New top-level tab**: Create `_<tabname>.twig`, add include in `index.twig`, wire in `_scripts.twig`.

### Step 2: Add the field using vl.attrs

```twig
<div {{ vl.attrs('<tab>', __('<Label>'), '<setting_key>', '<subtab>', '<Section Heading>', 'keyword1 keyword2') }}>
    <label class="block text-sm font-medium text-gray-700">{{ __('<Label>') }}</label>
    <input type="text" name="setting_key" value="{{ settings.setting_key|default('') }}">
</div>
```

Parameters:
- `tab` — top-level tab name (e.g. `general`, `content`, `ecommerce`)
- `label` — translatable label (required for search)
- `key` — settings storage key (required for search + controller auto-save)
- `subtab` — sub-tab name or empty string `''` if no sub-tabs
- `section` — section heading for search breadcrumbs
- `keywords` — space-separated search keywords (optional but recommended)
- `id` — optional DOM id (rarely needed)

### Step 3: Ensure form structure

Every sub-tab partial must have:
```twig
<div id="<tabname>-tab-<subtab>" class="<tabname>-tab-panel hidden">
    <form method="post" action="{{ url('/admin/settings') }}" class="space-y-5 rounded-xl border dark:border-gray-800 bg-white dark:bg-gray-900 p-6 shadow-xs">
        {{ csrf_field() }}
        <input type="hidden" name="_METHOD" value="PUT">
        <input type="hidden" name="_group" value="<tabname>">
        <input type="hidden" name="_subtab" value="<subtab>">
        {# fields here #}
    </form>
</div>
```

### Step 4: Feature gating (hide for an existing module, or wire a new toggle)

**4a — The setting belongs to an existing module.** Hide the field/section/link so it vanishes when the module is off:

```twig
{% if shop_enabled %}
    {# shop-only field, link, card or <option> #}
{% endif %}
```

- `shop_enabled`, `blog_enabled`, `pages_enabled`, `newsletter_enabled`, `form_builder_enabled`, `subscriptions_enabled`, `membership_enabled`, `seo_enabled` come from
  `FlashMiddleware::process()`; use `settings.<key>` when you need the raw setting.
- Hiding must **never** delete or reset stored values.
- On the Settings screen the module can be flipped live: anything that has to react immediately also needs
  `x-show="$store.vlFeatures.<key>" x-cloak`, plus a `$watch` fallback for a tab that just disappeared.
- New sidebar entry or link into a module → gate it in `FlashMiddleware::process()` as well (that is where nav groups/children are dropped or renamed).

**4b — New feature toggle:**

1. Add the toggle card in `_general_features.twig` (blue theme, matching existing pattern).
2. Add the key to `Alpine.store('vlFeatures', ...)` in `_scripts.twig`.
3. Add `x-show="$store.vlFeatures.<key>"` to the controlled tab button(s) in `index.twig`.
4. Add `x-show` to the controlled panel(s) if needed.
5. Add `$watch` fallback in the container's `x-data` so activating a hidden tab falls back to General.
6. Update `activateTab()` in `_scripts.twig` to skip hidden buttons (`offsetParent === null` check).
7. Update `FlashMiddleware` to hide sidebar nav items when the toggle is off.

### Step 5: Wire sub-tab activation (if new sub-tab)

In `_scripts.twig`:
1. Add `activate<tabname>Tab()` function.
2. Expose on `window.vlSettingsNav`.
3. Add click listeners for `.<tabname>-tab-btn`.
4. Set default activation on init.
5. Add `?subtab=` URL restore branch.
6. Add branch in `subtabLabel()` and `goTo()` for search navigation.
7. Add `.<tabname>-tab-panel:not(.hidden)` to `getSettingsForm()`.

### Step 6: Translations

Extract and add translations:
```bash
composer translations
```

Add keys to `resources/lang/en.json` and `resources/lang/tr.json` alphabetically.

### Step 7: Documentation

Update the relevant docs in `vale-docs/en/latest/admin/settings/`:
- `index.md` — if the tab/sub-tab list changed
- `<tabname>.md` — if the section structure changed
- Cross-references in other docs (advertising.md, media.md, translations.md, etc.)

## Verification Checklist

- [ ] `php vendor/bin/phpunit` passes (if controller/settings logic changed)
- [ ] All modified templates pass Twig parse check (`tlint.php` or equivalent)
- [ ] New field appears in search results with correct breadcrumb
- [ ] Setting/field/link hides when its module is off (Twig gate for the render; `x-show`/`$watch` when the toggle can be flipped on the same screen)
- [ ] No link, nav entry, `<option>` or dashboard card still points into a disabled module (nav gating lives in `FlashMiddleware::process()`)
- [ ] Feature toggle (if any) hides/shows the correct tab/sub-tab instantly
- [ ] Fallback navigation works when active tab is hidden
- [ ] Floating save bar submits the correct form
- [ ] Translations extracted and added for all new strings
- [ ] Docs updated for any navigation path changes

## Anti-Patterns to Avoid

- **Don't** add inline Save buttons — the floating save bar is the only save mechanism for settings forms.
- **Don't** modify `SettingsController::update()` for new fields — it auto-saves all `_group`/`_subtab` form keys by name.
- **Don't** use raw HTML in `vl.attrs` parameters — always use `__('...')` for translatable strings.
- **Don't** forget `x-cloak` on `x-show` elements that depend on feature toggles.
- **Don't** ship a setting, nav entry, link or `<option>` for a toggleable module without gating it — a switched-off module must not show or link to its own UI.
- **Don't** clear or reset stored values while gating a module — hide the UI, keep the data.
- **Don't** hardcode tab/sub-tab names in JS — always use the `activate*Tab` functions from `window.vlSettingsNav`.
