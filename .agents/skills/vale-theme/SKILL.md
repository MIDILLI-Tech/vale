---
name: vale-theme
description: "Scaffolds a new Vale CMS theme by asking questions (name/slug/author, inherit from a parent theme or standalone, blog-only or blog+shop scope, menu locations, color variables, page templates, language switcher) and generating themes/<slug>/ with theme.json, templates/ Twig files (layout, home/blog, post, page, shop), optional page-templates/, and CSS wired to --theme-* color variables. USE FOR: create theme, theme skeleton, start theme development, override core templates in a child theme. DO NOT USE FOR: admin panel UI changes, editor/block development, building project assets (npm run build covers themes automatically)."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: vale-docs/en/latest/developer/themes.md
---

# Create a Vale Theme

Template resolution order (Twig loader paths): **active theme `templates/` → parent theme `templates/` → core `resources/views/`**. A theme overrides any core view by creating the same relative path under `themes/<slug>/templates/`. Core top-level views you can override: `layout.twig`, `layout_bare.twig`, `home.twig`, `blog.twig`, `post.twig`, `page.twig`; plus nested ones like `shop/index.twig`, `shop/product.twig`.

**Updates replace shipped theme files.** `themes/default`, `dark`, `nova` and `vivid` ship inside every release ZIP and their shipped templates are overwritten by an update (`themes/` is intentionally not in `UpdateService::PROTECTED`). A custom theme with its own slug, and files the package does not contain, are not deleted by the current copy-only updater. The editor warns only when a shipped system template is selected. So site-specific changes belong in a **child theme** (`"parent": "dark"` in `theme.json`) — never as edits to a shipped template, which the next update silently reverts.

**Template cache invalidation is targeted.** Saving, creating or deleting a template through the admin editor calls `ThemeController::invalidateTemplateCache()`. It resolves the logical name against the active Twig loader and removes only that template's compiled class (`Environment::removeCache()`); other templates stay warm, and data cache is untouched. The admin POST middleware still flushes the full page cache because cached URLs are not mapped to individual templates. Direct file edits over FTP/SSH bypass the editor, so run `php vale cache:clear views` and, when needed, `php vale cache:clear pages`.

## Phase 1 — Ask (required before writing any code)

1. Theme display name + slug (folder name) + author + license?
2. **Parent inheritance** (`"parent": "default"` — only ship the files you change) or standalone full copy?
3. Scope: **blog only** (home/blog/post/page/layout) or **blog + shop** (+ `shop/index.twig`, `shop/product.twig`)?
4. Menu locations? (e.g. primary, footer)
5. Color variables: default 10-variable schema or custom set?
6. Custom page template(s) for the admin dropdown?
7. Language switcher partial needed (multi-language sites)?

## Phase 2 — Generate

Directory layout to create:

```
themes/<slug>/
├── theme.json
├── theme.png            # optional thumbnail (admin grid)
├── assets/
│   └── css/theme.css    # consumed via asset() helper
├── page-templates/      # optional
└── templates/
    ├── layout.twig
    ├── home.twig
    ├── blog.twig
    ├── post.twig
    └── page.twig        # + shop/index.twig, shop/product.twig if requested
```

### `themes/<slug>/theme.json`

```json
{
    "name": "<display name>",
    "version": "1.0.0",
    "description": "<one-liner>",
    "author": "<author>",
    "license": "MIT",
    "parent": null,
    "menus": {
        "primary": "Primary Navigation",
        "footer": "Footer Links"
    },
    "colors": {
        "header": "gray-50"
    },
    "color_variables": {
        "--theme-primary":       { "label": "Primary Color",  "default": "#111827" },
        "--theme-bg":            { "label": "Background",     "default": "#f9fafb" },
        "--theme-surface":       { "label": "Card Surface",   "default": "#ffffff" },
        "--theme-text":          { "label": "Text",           "default": "#111827" },
        "--theme-text-muted":    { "label": "Muted Text",     "default": "#4b5563" },
        "--theme-border":        { "label": "Border",         "default": "#e5e7eb" }
    },
    "assets": { "css": [], "js": [] }
}
```

Notes: drop `"parent"` (or omit) for standalone. If no `color_variables` is declared Vale falls back to its built-in schema. Gradient defaults belong directly in `"default"` (e.g. `"linear-gradient(135deg, #0f172a 0%, #1e293b 100%)"`) and must be bound with the `background` shorthand, not `background-color`.

### `templates/layout.twig` (skeleton)

```twig
<!DOCTYPE html>
<html lang="{{ current_locale|default('en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}{{ site_title|default('Vale') }}{% endblock %}</title>
    {{ theme_hook('head') }}
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}">
</head>
<body class="bg-[--theme-bg]">
{% include 'partials/_language_switcher_js.twig' %}

<header style="background: var(--theme-header-bg, #fff);" x-data="{ mobileOpen: false }">
    <nav class="hidden md:flex">
        {% for item in menu_primary %}
            {% if not item.parentId %}
                {% set children = menu_primary|filter(v => v.parentId == item.id) %}
                {% if children|length > 0 %}
                    <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                        <a href="{{ item.url }}" target="{{ item.target }}">{{ item.label }}</a>
                        <div x-show="open" x-cloak class="absolute left-0 top-full z-[60]">
                            {% for child in children %}
                                <a href="{{ child.url }}" target="{{ child.target }}">{{ child.label }}</a>
                            {% endfor %}
                        </div>
                    </div>
                {% else %}
                    <a href="{{ item.url }}" target="{{ item.target }}">{{ item.label }}</a>
                {% endif %}
            {% endif %}
        {% endfor %}
    </nav>
    <button type="button" @click="mobileOpen = !mobileOpen" class="md:hidden p-2.5 min-h-[44px] min-w-[44px] flex items-center justify-center" aria-label="{{ __('Toggle navigation') }}" :aria-expanded="mobileOpen ? 'true' : 'false'">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div x-show="mobileOpen" x-cloak class="md:hidden">
        {% for item in menu_primary %}
            {% if not item.parentId %}
                {% set children = menu_primary|filter(v => v.parentId == item.id) %}
                {% if children|length > 0 %}
                    <div x-data="{ sub: false }">
                        {% if item.url and item.url != '#' and item.url != 'javascript:void(0)' and item.url != 'javascript:;' %}
                        <div class="flex items-center justify-between">
                            <a href="{{ item.url }}" target="{{ item.target }}" class="flex-1 px-3 py-2.5 min-h-[44px] inline-flex items-center">{{ item.label }}</a>
                            <button type="button" @click="sub = !sub" class="p-2.5 min-h-[44px] min-w-[44px] flex items-center justify-center" aria-label="{{ __('Toggle submenu') }}">
                                <svg class="h-4 w-4 transition-transform" :class="sub && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                        </div>
                        {% else %}
                        <button type="button" @click="sub = !sub" class="flex w-full items-center justify-between px-3 py-2.5 min-h-[44px]">
                            <span>{{ item.label }}</span>
                            <svg class="h-4 w-4 transition-transform" :class="sub && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        {% endif %}
                        <div x-show="sub" x-cloak class="ml-4">
                            {% for child in children %}
                                <a href="{{ child.url }}" target="{{ child.target }}" class="block px-3 py-2 min-h-[44px] flex items-center">{{ child.label }}</a>
                            {% endfor %}
                        </div>
                    </div>
                {% else %}
                    <a href="{{ item.url }}" target="{{ item.target }}" class="block px-3 py-2.5 min-h-[44px] flex items-center">{{ item.label }}</a>
                {% endif %}
            {% endif %}
        {% endfor %}
    </div>
</header>

<main class="max-w-4xl mx-auto px-4 py-8">
    {% block content %}{% endblock %}
</main>

<footer style="background: var(--theme-footer-bg, #fff);">
    {% for item in menu_footer %}
        <a href="{{ item.url }}">{{ item.label }}</a>
    {% endfor %}
</footer>
</body>
</html>
```

Only include the language-switcher partial when requested; remove `menu_primary`/`menu_footer` loops for undeclared locations.

### Content templates

- `home.twig` / `blog.twig`: `{% extends "layout.twig" %}`, loop over posts global, link with each post's URL, show title/excerpt/date.
- `post.twig`: render `{{ post.title }}` and body via `{{ post.body|shortcodes(post)|raw }}`.
- `page.twig`: same pattern; respect an optional selected page template.
- `shop/index.twig` / `shop/product.twig` (if requested): model after core `resources/views/shop/*.twig` — copy and restyle rather than inventing variable names.

Rules: keep `{{ theme_hook('head') }}` in `<head>` (SEO meta + saved colors inject there, AFTER your own styles — saved values win). Escape everything by default; `|raw` only for trusted rendered bodies. Use `{{ __('...') }}` for user-facing strings.

### `assets/css/theme.css`

```css
:root {
    --theme-primary: #111827;
    --theme-bg: #f9fafb;
    --theme-surface: #ffffff;
    --theme-text: #111827;
    --theme-text-muted: #4b5563;
    --theme-border: #e5e7eb;
}

main .bg-white   { background-color: var(--theme-surface) !important; }
main .text-gray-900 { color: var(--theme-text) !important; }
.btn-primary { background-color: var(--theme-primary); color: #fff; }
```

Map generic Tailwind utilities inside `<main>` so page background and card surfaces stay independently stylable while header/footer remain under theme control.

### Mobile Responsiveness & Accessibility (a11y)

1. **Clickable Parent Links in Mobile Submenus:** Parent menu items with valid URLs (e.g. `item.url` is not `'#'` or empty) must remain clickable on mobile via a dedicated `<a>` tag, paired with a separate `<button @click="sub = !sub">` accordion trigger for toggling sub-items.
2. **Touch Targets:** Ensure interactive elements (links, toggle buttons, menu triggers) meet mobile touch target guidelines (minimum 44x44px target area or adequate padding such as `px-3 py-2.5 min-h-[44px]`).
3. **Accessibility Attributes:** Include ARIA attributes (`aria-expanded`, `aria-label`, `aria-controls`, `aria-hidden="true"` on decorative SVGs) and ensure keyboard focus states are visible.

### Dark themes — never skip the dark side

If the user asks for a **dark** theme (or light/dark toggle support), handle it explicitly:

1. **Dark-by-default theme:** put the dark palette directly into `theme.json` → `color_variables[*].default` (e.g. `--theme-bg: "#0b1220"`, `--theme-text: "#e5e7eb"`) and set `colors.header` to a dark class (e.g. `gray-900`). Admin color overrides then adjust an already-dark baseline.
2. **Toggleable themes:** declare both scopes in your own CSS — `:root { ... }` for light and `html.dark { ... }` for dark. Vale injects saved admin colors into a `<style>` targeting `:root, html.dark`, so saved values override BOTH modes consistently.
3. Every hard-coded color needs its dark counterpart: when you must use a raw value instead of a variable, add the `html.dark` variant right next to it (same pattern as AGENTS.md §16 for admin UI). Prefer variables over raw values so this problem disappears.
4. Check contrast in both modes: muted text on surface, borders against bg, primary buttons in dark mode. A dark theme with light-only assumptions is incomplete — list "dark mode verified" in the completion report.

### Page template (optional): `page-templates/full-width.twig`

First line MUST be the description comment:

```twig
{# DESCRIPTION: A full-width layout without a sidebar. #}
<div class="max-w-full px-4">
    <h1>{{ page.title }}</h1>
    <div class="prose">{{ page.body|shortcodes(page)|raw }}</div>
</div>
```

## Phase 3 — Verify

- Validate `theme.json` parses as JSON
- Confirm slug matches folder name
- Twig syntax check — register stub callbacks first or every `asset()`/`__()`/`shortcodes`/`compact_count` fails as "unknown" during compile (Note: `CacheWarmCommand` automatically uses `registerUndefinedFunctionCallback` and `registerUndefinedFilterCallback` to handle all custom/undefined functions and filters during `php vale cache:warm`):

```php
require 'vendor/autoload.php';
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;

$twig = new Environment(new ArrayLoader(['_' => '']));
$twig->registerUndefinedFunctionCallback(fn (string $n) => new Twig\TwigFunction($n, fn () => ''));
$twig->registerUndefinedFilterCallback(fn (string $n) => new Twig\TwigFilter($n, fn () => ''));

foreach (glob('themes/<slug>/templates/*.twig') ?: [] as $f) {
    $twig->parse($twig->tokenize(new Source(file_get_contents($f), $f))); // throws on syntax error
}
```

- Activate flow (manual, ask user): Admin → Appearance → Themes → activate; check homepage renders without Twig errors; toggle a color in Theme Colors editor and confirm it overrides defaults
- Multi-language site: verify switcher appears and `current_locale` block works

## Checklist

- [ ] `theme.json` valid, slug === folder name, colors for preview in the listing
- [ ] `templates/` files only for chosen scope
- [ ] `theme_hook('head')` present in layout
- [ ] Menus declared in theme.json match loops used
- [ ] CSS consumes `--theme-*` vars; gradient defaults bound via `background`
- [ ] Strings wrapped in `__()`
- [ ] Mobile navigation supports clickable parent links and distinct accordion toggles
- [ ] Touch targets meet minimum height/width (44x44px) and ARIA accessibility labels are set
- [ ] Dark mode handled (dark defaults declared, or `html.dark` scope defined; contrast checked)
- [ ] Thumbnail image present (optional)
- [ ] **All 4 themes** (default, dark, nova, vivid) updated if adding a new display feature — see AGENTS.md §22
