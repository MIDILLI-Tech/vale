---
name: vale-shortcode
description: "Adds a new content shortcode (e.g. [mybox color=\"red\"]) to Vale's ShortcodeProcessor by asking for the tag name, attributes, output type and asset needs, then generating: the regex match block(s) inside ShortcodeProcessor::process() — including the &quot; decoding and <p>-wrapper fallback conventions — plus a private render method with attribute parsing and safe HTML output. USE FOR: 'add shortcode', new [tag] in editor content, custom embed tag for posts/pages/CCT bodies. DO NOT USE FOR: Tiptap editor block nodes (resources/js/editor/embeds/), Twig template functions/filters, theme template partials (use vale-theme)."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: src/Infrastructure/Content/ShortcodeProcessor.php
---

# Add a Shortcode

All shortcodes are processed by `src/Infrastructure/Content\ShortcodeProcessor::process()` — plain `preg_replace_callback` chains over the stored HTML body. Existing examples to mimic: `[form slug="..."]`, `[newsletter_form]`, `[toc]`, `[pages]`, `[link_button]` (single button) and `[link_button_group layout="..." gap="..."]...[/link_button_group]` (block that wraps one or more inner `[link_button]` children).

Note: `[toc]` renders list-item classes relative to the shallowest heading present (`vl-toc-h1` for the topmost level found), so an H2-only list starts at the first column. Keep this normalization when touching `processToc()`.

## Phase 1 — Ask (required before writing any code)

1. Tag name? (lowercase, `[a-z0-9_-]+`, e.g. `mybox`)
2. Attributes? For each: name, required?, default? free-text or enum?
3. Output: static HTML block / data-driven (needs a repository injected?) / interactive (JS needed)?
4. Needs CSS or JS assets on pages where it appears? (if yes → set `$this->hadShortcodes = true` so the layout can load assets conditionally)
5. Should it be insertable from the Tiptap slash menu/editor later? (editor integration is a separate follow-up task)

## Phase 2 — Generate

Edit `src/Infrastructure/Content/ShortcodeProcessor.php`.

### Step 1 — Match blocks inside `process()`

Two variants are MANDATORY per repo convention — Tiptap stores `"` as `&quot;`, and the editor may wrap the tag in `<p>`:

```php
// [mybox color="red"]Title[/mybox] — strip wrapping <p> tag if present
$content = preg_replace_callback(
    '/<p>\s*\[mybox(?:\s+([^\]]*))?\](.*?)\[\/mybox\]\s*<\/p>/s',
    fn ($m) => $this->renderMyBox($this->decodeAttrs($m[1] ?? ''), $m[2] ?? ''),
    $content,
);
// Fallback: shortcode not wrapped in <p>
$content = preg_replace_callback(
    '/\[mybox(?:\s+([^\]]*))?\](.*?)\[\/mybox\]/s',
    fn ($m) => $this->renderMyBox($this->decodeAttrs($m[1] ?? ''), $m[2] ?? ''),
    $content,
);
```

For self-closing tags (`[mytag attr="x"]`) drop the body group and closing part:

```php
'~<p>\s*\[divider(?:\s+([^\]]*))?\]\s*</p>~',
```

Performance convention used in this class: guard cheap `str_contains()` checks before running regexes when the tag has no attributes:

```php
if (str_contains($content, '[divider]') || str_contains($content, '[divider ')) {
    // process...
}
```

**Nested / paired block shortcodes** (a block that contains inner paired children) — order the passes so the outer wrapper runs first and consumes its children before any standalone handling. The `[link_button_group]` → `processLinkButtonGroup()` → inner `processLinkButton()` flow is the precedent: the group regex captures the inner `[link_button]` tags in a `[\s\S]*?` body group and renders each via its own inner `preg_replace_callback`. Legacy standalone children that appear outside a wrapper are handled by a later pass, preserving backward compatibility.

### Step 2 — Render method

```php
/** @param array<string,string> $attrs */
private function renderMyBox(string $rawAttrs, string $inner): string
{
    parse_str(str_replace('&amp;', '&', $rawAttrs), $pairs);
    $color = in_array($pairs['color'] ?? '', ['red', 'green', 'blue'], true)
        ? $pairs['color']
        : 'blue';

    $this->hadShortcodes = true;

    return sprintf(
        '<div class="vl-mybox vl-mybox--%s">%s</div>',
        htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
        $inner, // trusted only if it is rendered body HTML; otherwise escape
    );
}
```

Attribute-parsing notes (match existing helpers):
- Always pass raw attr strings through `$this->decodeAttrs(...)` first (it runs `html_entity_decode` with `ENT_QUOTES | ENT_HTML5`, so both `&quot;` and `&apos;` decode).
- `parseAttributes()` requires the closing quote to match the opening one (backreference), so apostrophes inside double-quoted values (`button="Get to know when it's out"`) are not mistaken for closing delimiters.
- Whitelist/validate every attribute value before echoing; escape dynamic text with `htmlspecialchars`.
- Set `$this->hadShortcodes = true` when the shortcode actually renders something that needs assets.
- If you need repository data (posts, products, settings), constructor-inject the interface into `ShortcodeProcessor` and add it to its DI factory definition in `config/services.php`.

### Step 3 — Assets (only if Phase 1 answer says needed)

Expose styles via the theme CSS or enqueue conditionally where `hadShortcodes()` is consumed. Never inject global `<style>` tags from the processor.

## Phase 3 — Verify

- `php -l src/Infrastructure/Content/ShortcodeProcessor.php`
- `composer lint:changed`
- Unit test if `tests/Infrastructure` covers shortcodes (follow existing test style): assert rendering, attribute defaults, invalid-value fallback, and `<p>`-wrapped vs bare input.
- Manual: create a post containing both `[mybox color="red"]Hi[/mybox]` and the `<p>`-wrapped variant via admin editor; confirm both render identically on frontend.

## Checklist

- [ ] Both `<p>`-wrapped and bare regex variants added
- [ ] Attr strings decoded via decodeAttrs; values whitelisted/escaped
- [ ] hadShortcodes set when assets needed
- [ ] No raw SQL / no unescaped user input in output
- [ ] Tests + manual editor round-trip verified
- [ ] **All 4 themes** (default, dark, nova, vivid) render the shortcode output correctly — check meta areas if the shortcode injects UI elements — see AGENTS.md §22
