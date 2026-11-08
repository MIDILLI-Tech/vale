---
name: vale-editor-block
description: "Adds a new TipTap editor block node to Vale's block editor by defining the node extension, optional settings dialog, converter (if shortcode-backed), CSS styles, toolbar/slash-menu registration, and admin layout translations. USE FOR: 'add editor block', new embed block, new content block, custom TipTap node, block editor widget. DO NOT USE FOR: admin panel UI changes, PHP shortcode rendering logic (separate from editor), theme template changes, mark extensions (use core TipTap marks directly)."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: vale-docs/en/latest/admin/content/editor.md
---

# Add an Editor Block

The editor lives under `resources/js/editor/`. It is a TipTap 3 instance split across modules: `index.js` (entry point + mount), `embeds/` (rich composite blocks), `nodes/` (simple blocks), `extensions/` (marks + extended core nodes), `settings/` (declarative settings dialogs), `converters.js` (bidirectional shortcode/HTML comment round-trips), `styles.js` (injected CSS with dark-mode variants), `toolbar.js` (formatting bar + insert dropdown), and `slash-menu.js` (the `/` command palette). Existing examples to mimic: `carousel-embed.js`, `newsletter-embed.js`, `product-embed.js`, `spacer-block.js`, `link-button.js` (leaf child), `link-button-group.js` (parent group), `card-group.js` + `card-item.js` (parent group with editable title/desc children).

## Phase 1 — Ask (required before writing any code)

1. **Block name + slug** — machine name used in TipTap (`carouselEmbed`, `countdownBlock`); drives file name, CSS classes, data attributes, and `insertContent({ type: '...' })` calls.
2. **Block type**: `embed` (rich composite, goes in `embeds/`), `node` (simple, `nodes/`), or `extension` (mark/core extension, `extensions/`)?
3. **Attributes** — what data does it store? List each attribute name, type (text/number/boolean/select/color/page), default value, and whether it needs `parseHTML` and/or `renderHTML`.
4. **Settings dialog needed?** — simple inline controls inside `addNodeView` (e.g. TOC level, Spacer size) or full `openBlockSettings` dialog with a field schema?
5. **Storage format** — stores as HTML `data-*` attributes inside a `<div>` (most embeds), as an HTML comment (`<!--more-->`, `<!--nextpage-->`, `<!--spacer ...-->`), or as a shortcode (`[carousel ...]`, `[form ...]`)? If shortcode-backed, a converter pair is required.
6. **Editable inner content?** — does the block contain inner ProseMirror-editable zones (like LinkButton's button text or MediaText's text column)? If yes, the node view must return `contentDOM` and implement `stopEvent()` / `ignoreMutation()`.
7. **Feature flag?** — should the block be hidden unless a module is enabled? Available flags: `_VL_SHOP_ENABLED`, `_VL_NEWSLETTER_ENABLED`, `_VL_FORMS_ENABLED`, `_VL_PAGES_ENABLED`, `_VL_SUBSCRIPTIONS_ENABLED`. If a new flag is needed, it must be declared in `resources/views/admin/layout.twig` and plumbed from PHP.

## Phase 2 — Generate

### Step 1 — Node/Extension file

Place in `resources/js/editor/embeds/<name>-embed.js`, `resources/js/editor/nodes/<name>-node.js`, or `resources/js/editor/extensions/<name>.js` depending on the type chosen in Phase 1.

**Embed block skeleton (`embeds/my-embed.js`):**

```js
import { Node, mergeAttributes } from '@tiptap/core';
import { openMySettings } from '../settings/my-settings.js';

const MyEmbed = Node.create({
  name: 'myEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      myAttr: {
        default: '',
        parseHTML: el => el.getAttribute('data-my-attr') || '',
        renderHTML: attrs => ({ 'data-my-attr': attrs.myAttr }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-my-embed]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-my-embed-block',
      'data-my-embed': '1',
      'contenteditable': 'false',
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-my-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-my-embed', '1');

      const header = document.createElement('div');
      header.className = 'vl-my-embed-header';
      const title = document.createElement('span');
      title.textContent = tjs('My Block');
      header.appendChild(title);

      const settingsBtn = document.createElement('button');
      settingsBtn.innerHTML = '<svg ...>'; // gear icon
      settingsBtn.dataset.settings = '1';
      settingsBtn.title = tjs('Settings');
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (typeof getPos === 'function') openMySettings(editor, node, getPos());
      });
      header.appendChild(settingsBtn);

      const deleteBtn = document.createElement('button');
      deleteBtn.innerHTML = '<svg ...>'; // trash icon
      deleteBtn.title = tjs('Remove');
      deleteBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (typeof getPos === 'function') {
          const pos = getPos();
          editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
        }
      });
      header.appendChild(deleteBtn);

      const body = document.createElement('div');
      body.className = 'vl-my-embed-body';
      body.textContent = tjs('Empty state — click the gear to configure');

      dom.appendChild(header);
      dom.appendChild(body);

      return { dom, update(updatedNode) {
        if (updatedNode.type.name !== 'myEmbed') return false;
        node = updatedNode;
        return true;
      }};
    };
  },
});

export { MyEmbed };
```

Conventions:
- `name` is camelCase machine name; used in `insertContent({ type: '...' })`.
- `group: 'block'` for full-width embeds; omit for inline nodes.
- `atom: true` makes the node a single unit (no inner text cursor).
- `draggable: true` enables drag-to-reorder.
- Always guard `getPos` with `typeof getPos === 'function'` — it can be `undefined` in TipTap internals.
- Use `mergeAttributes(HTMLAttributes, { ... })` in `renderHTML` so user-added classes/styles are preserved.
- The `parseHTML` selector must match the rendered element (e.g. `div[data-my-embed]`).
- Non-editable chrome (headers, buttons, placeholder text, badges) inside a node view with `contentDOM` MUST be marked `contentEditable = 'false'` — otherwise it inherits editability from the editor root and the user can type into it.

### Step 1b — Composite group block (parent + children)

For repeatable item layouts (button groups, card grids), model on `link-button-group.js` + `link-button.js` (or `card-group.js` + `card-item.js`):

- **Parent** (`embeds/my-group.js`): `content: 'myItem+'`, owns the header (grip, `+` add, settings gear, delete), manual drag-to-reorder from the header, and a flex `contentDOM` for layout. No layout toggle unless the design needs one.
- **Child leaf** (`embeds/my-item.js`): per-item attrs + its own mini toolbar (move up/down, delete). Deleting the last child must delete the whole group instead of leaving a schema-invalid empty parent.
- **Editable texts**: if a child needs separately styled editable texts (title + description with toolbar bold/italic), define them as tiny textblock children (`content: 'myTitle myDesc'`, each `content: 'inline*'`), NOT as attrs — attrs cannot carry marks. The child's `contentDOM` holds both; they render from their own `renderHTML` (a `div` with a class).
- **Child reorder**: implement up/down via sibling swap on the transaction (`tr.delete` + `tr.insert` at the computed boundary) — nested children are not natively draggable.
- **Settings**: group-level controls apply immediately via `setNodeMarkup(groupPos, ...)`; per-child tabs collect a `patch` per child and apply all patches on Save (model on `settings/link-button-group-settings.js`, `settings/card-settings.js`). Reuse `openMediaPickerExtended(editor, onPick, 'image/')` from `media/browse.js` for image picking (covers library + external URL). For PDF/ZIP/audio files, `media/browse.js` also exports `openFileLinkDialog(editor, item)` — a structured modal that asks for the link text (prefilled with the file name) and inserts the file as a plain text link (`<a>` mark, optional `target="_blank"`); it is what the Media Library "Files" filter and the direct file upload flow use.
- **Double-click**: the gear button needs `dataset.settings = '1'` so the `index.js` dblclick handler can open settings.

**Simple node skeleton (`nodes/my-node.js`):**

```js
import { Node } from '@tiptap/core';

const MyNode = Node.create({
  name: 'myNode',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() { return { /* ... */ }; },

  parseHTML() { return [{ tag: 'div[data-my-node]' }]; },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-my-node-block',
      'data-my-node': '1',
      'contenteditable': 'false',
    })];
  },
});

export { MyNode };
```

**Extension/mark skeleton (`extensions/my-mark.js`):**

```js
import { Mark } from '@tiptap/core';

const MyMark = Mark.create({
  name: 'myMark',

  addAttributes() { return { /* ... */ }; },

  parseHTML() { return [{ tag: 'span[data-my-mark]' }]; },

  renderHTML({ node, HTMLAttributes }) {
    return ['span', mergeAttributes(HTMLAttributes, { 'data-my-mark': '1' }), 0];
  },
});

export { MyMark };
```

### Step 2 — Settings dialog (if needed)

If the block has more than 1–2 simple controls, use the declarative `openBlockSettings` dialog.

Create `resources/js/editor/settings/my-settings.js`:

```js
import { openBlockSettings } from './schema-modal.js';

export function openMySettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('My Block Settings'),
    size: 'vl-modal-sm',
    scrollBody: false,
    fields: [
      { key: 'myAttr', type: 'text', label: tjs('Label'), placeholder: 'Default' },
      { key: 'count', type: 'number', label: tjs('Count'), min: 1, max: 10, parse: v => parseInt(v, 10) || 1 },
      { type: 'divider' },
      { key: 'mode', type: 'select', label: tjs('Mode'), options: [
        { v: 'a', l: tjs('Option A') },
        { v: 'b', l: tjs('Option B') },
      ]},
      { key: 'color', type: 'color', label: tjs('Color'), pickerDefault: '#f3f4f6' },
      { key: 'enabled', type: 'checkbox', label: tjs('Enabled') },
      { key: 'page', type: 'page', label: tjs('Link to page'), placeholder: tjs('Select a page...') },
    ],
  });
}
```

Field types:

| Type | Schema | Notes |
|---|---|---|
| `text` | `{ key, type:'text', label, placeholder?, help?, parse? }` | Standard text input |
| `number` | `{ key, type:'number', label, min?, max?, step?, parse?, suffixSelect? }` | Number input; `suffixSelect` adds a unit dropdown |
| `select` | `{ key, type:'select', label, options }` | Dropdown; options are strings or `{v, l}` objects |
| `checkbox` | `{ key, type:'checkbox', label }` | Boolean toggle |
| `color` | `{ key, type:'color', label, pickerDefault?, fallbackValue?, btnFallback? }` | Native hex color picker |
| `page` | `{ key, type:'page', label, placeholder? }` | Page slug picker (opens `openPagePicker`) |
| `divider` | `{ type:'divider' }` | Visual `<hr>` separator |
| `custom` | `{ type:'custom', render: ctx => HTMLElement }` | Fully custom HTML rendered by the caller |
| `tags` | `{ key, type:'tags', label, placeholder?, help?, suggestions?, suggestionsUrl? }` | Pill editor with search; static `suggestions` or lazy `suggestionsUrl` (`{data:[{name}], truncated}`); `read()` returns comma-separated string |

Any field accepts `showWhen: { key, value }` to show it only while another attribute equals the value (re-evaluated live on every input/change; model on `settings/newsletter-settings.js` where the honeypot name shows only for `captcha: 'honeypot'`).
**Conditional / multi-attribute fields in `custom`:** `custom` fields are NOT run through a save-time `read()` hook. To persist values, call `ctx.setValue(key, value)` on every change event (and once at render to seed initial state). `setValue` writes into the patch that is merged on Save, and keys you don't set keep their existing node attributes. Use this for a dropdown that toggles between two mutually-exclusive attributes (model on `settings/coupon-settings.js`: a "Discount Type" select swaps the `discountPercent` / `discountFixed` keys, clearing the non-selected one). `ctx.getValue(key)` reads the current/patched attribute. Do not rely on the `key` field for `custom` — give it a descriptive `render` name instead.

Persistence: `openBlockSettings` calls `tr.setNodeMarkup(pos, undefined, { ...base, ...patch })`. Only declared keys in `patch` are written back, preserving concurrent attribute changes. The `parse` function on each field coerces the raw string value before storage.

Conventions:
- Import and call `openBlockSettings` — never build your own modal from scratch.
- `openBlockSettings` renders the site-wide layout automatically (sticky title above, scrolling body, pinned Cancel/Save row below) — do not add your own title element, `mb-4` spacing, or action row inside the fields.
- Use `tjs('...')` for every user-facing string.
- **String casing convention** (matches the existing editor UI):
  - Slash menu + toolbar "Insert element" entry **titles** → **Title Case** (`Media Library`, `Link Buttons`, `Insert Coupon`) — keyboard-shortcut suffixes keep the same casing (`Media Library (Ctrl+Alt+M)`, `Upload (Ctrl+Shift+U)`).
  - Slash menu **descriptions**, help text, button/checkbox labels, placeholders, inline control hints → **sentence case** (`Insert image, video or file from library`, `Open in new tab`, `Description of what this block does`).
  - Dialog / settings titles (including picker titles) → **Title Case** (`Insert File Link`, `Video Settings`, `My Block Settings`).
- Keep field `key` names in camelCase to match attribute names.
- Use `divider` to group related fields visually.

**Inline controls (simple blocks):** For blocks like Spacer or More that have 1–2 controls, render them directly inside `addNodeView`:

```js
const sizeInput = document.createElement('input');
sizeInput.type = 'text';
sizeInput.value = node.attrs.size || '50';
sizeInput.addEventListener('change', () => {
  if (typeof getPos === 'function') {
    const pos = getPos();
    editor.chain().focus().command(({ tr }) => {
      tr.setNodeMarkup(pos, undefined, { ...node.attrs, size: sizeInput.value });
      return true;
    }).run();
  }
});
body.appendChild(sizeInput);
```

### Step 3 — Converter (if shortcode-backed)

If the block stores as a shortcode (or HTML comment), add a bidirectional converter pair in `resources/js/editor/converters.js`.

**Shortcode ↔ div pattern (model on `formEmbedToShortcode` / `shortcodeToFormEmbed`):**

```js
function myEmbedToShortcode(html) {
  html = html.replace(
    /<div[^>]*data-my-embed="1"[^>]*>(.*?)<\/div>/gs,
    (match) => {
      const myAttr = (match.match(/data-my-attr="([^"]*)"/) || [])[1] || '';
      return `[my_embed my_attr="${myAttr}"]`;
    }
  );
  return html;
}

function shortcodeToMyEmbed(html) {
  html = html.replace(
    /<p>\s*\[my_embed(?:\s+([^\]]*))?\](.*?)\[\/my_embed\]\s*<\/p>/s,
    (m, rawAttrs) => {
      const myAttr = (rawAttrs.match(/my_attr="([^"]*)"/) || [])[1] || '';
      return `<div data-my-embed="1" data-my-attr="${myAttr}"></div>`;
    }
  );
  html = html.replace(
    /\[my_embed(?:\s+([^\]]*))?\](.*?)\[\/my_embed\]/s,
    (m, rawAttrs) => {
      const myAttr = (rawAttrs.match(/my_attr="([^"]*)"/) || [])[1] || '';
      return `<div data-my-embed="1" data-my-attr="${myAttr}"></div>`;
    }
  );
  return html;
}
```

**HTML comment pattern (model on `commentToMore` / `moreToComment`):**

```js
function commentToMyNode(html) {
  return html.replace(
    /<!--\s*my_node(?:\s+([^\]]*))?\s*-->/,
    (m, raw) => {
      const size = (raw.match(/size=([0-9]+)/) || [])[1] || '50';
      return `<div data-my-node="1" data-size="${size}"></div>`;
    }
  );
}

function myNodeToComment(html) {
  return html.replace(
    /<div[^>]*data-my-node="1"[^>]*data-size="([^"]*)"[^>]*><\/div>/g,
    (m, size) => `<!-- my_node size=${size} -->`
  );
}
```

Conventions:
- Always provide **two regex variants**: one wrapped in `<p>` (Tiptap may wrap tags) and one bare. This is MANDATORY.
- Normalize `&quot;` to `"` before parsing attribute strings: `rawAttrs.replace(/&quot;/g, '"')`.
- Use non-greedy `.*?` in regexes — greedy `.*` swallows multiple blocks.
- Export the converter functions so `index.js` can import them.
- **Attribute order is not stable**: TipTap `getHTML()` emits `data-*` attributes in `addAttributes` declaration order (yours first, `data-<block>` and `class` last), which differs from hand-written div order. NEVER capture only the substring after your marker attribute — capture the whole opening tag (`/<div([^>]*data-my-embed="1"[^>]*)>/`) and read each `data-*` from that. Reading a suffix silently yields empty strings after a real editor save (silent data loss).
- **Serialize `renderHTML` structure, not nodeView DOM**: `editor.getHTML()` uses `renderHTML` output (no editor chrome like toolbars/badges). Design div→shortcode regexes against `renderHTML` shapes; convert nested child divs (e.g. title/desc) innermost-first, then the parent wrapper.
- For parent+children blocks, replace child divs globally first (their content is inline-only, so no nested-div ambiguity), then the parent (its inner HTML is shortcode-only by then).

### Step 4 — Register converters in `converters.js` + `index.js`

Conversion is centralized: `converters.js` exports `formEmbedToShortcode` (editor HTML → storage) and `shortcodeToFormEmbed` (storage → editor HTML), and `index.js` already wires both into the initial-load chain and the save chain. Add your block's replacements **inside** those two functions (model on the `[link_button_group]` / `[card_group]` sections) — do not create a parallel pipeline.

```js
// Inside formEmbedToShortcode(html) — editor divs → shortcode:
html = html.replace(
  /<div([^>]*data-my-embed="1"[^>]*)>(.*?)<\/div>/gs,
  (match, openTag, inner) => {
    const myAttr = (openTag.match(/data-my-attr="([^"]*)"/) || [])[1] || '';
    return `[my_embed my_attr="${myAttr}"]`;
  }
);

// Inside shortcodeToFormEmbed(html) — shortcode → editor divs:
html = html.replace(
  /(?:<p>\s*)?\[my_embed(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
  (_, attrs) => {
    const myAttr = ((attrs || '').match(/my_attr=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/) || [])[1] || '';
    return `<div data-my-embed="1" data-my-attr="${myAttr}"></div>`;
  }
);
```

Omit default-valued attributes from the shortcode (cleaner storage, backward compatible); the `|| default` fallbacks on parse restore them.

### Step 5 — CSS (`resources/js/editor/styles.js`)

Add block-specific styles inside the injected `<style>` tag in `styles.js`. All color/bg/border properties **must** have a `.dark` counterpart.

```js
/* My Embed block */
.vl-my-embed-block { border: 2px dashed #e5e7eb; border-radius: 8px; background: #f9fafb; margin: 20px 0; }
.vl-my-embed-header { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; font-size: 13px; font-weight: 600; color: #374151; }
.vl-my-embed-body { padding: 16px; }
.dark .vl-my-embed-block { border-color: #374151; background: #111827; }
.dark .vl-my-embed-header { background: #1f2937; border-color: #374151; color: #d1d5db; }
.dark .vl-my-embed-header button:hover { background: #374151; color: #fff; }
```

Conventions:
- Prefix all classes with `vl-` and the block name.
- Use the shared chrome selectors where possible: `[class$="embed-block"]` for the wrapper border/background, `.vl-*-header` for the drag handle bar.
- The shared `.dark [class$="embed-block"]` rule in `styles.js` already handles most wrappers — only add block-specific overrides when needed.

### Step 6 — Register in `index.js`, `toolbar.js`, and `slash-menu.js`

**`index.js`:**

1. Add the import at the top:
   ```js
   import { MyEmbed } from './embeds/my-embed.js';
   ```
2. Add `MyEmbed` to the `extensions` array inside `new Editor({...})`:
   ```js
   extensions: [
     // ... existing ...
     MyEmbed,
   ],
   ```
3. If the block uses a converter, add it to the initial load + onUpdate chains (see Step 4).
4. If the block should open settings on double-click, add its class to the `dblclick` listener:
   ```js
   const embed = target.closest('.vl-newsletter-embed-block, .vl-carousel-embed-block, .vl-product-embed-block, .vl-my-embed-block');
   ```
5. If the block needs a keyboard shortcut, add it to the `keydown` handler:
   ```js
   else if (e.altKey && e.key === 'm') { e.preventDefault(); editor.commands.insertContent({ type: 'myEmbed' }); }
   ```

**`toolbar.js`:**

Add an entry to the "Insert element" dropdown options array (inside the `options: [...]` of the `Element` button):

```js
{ label: '🎯', title: tjs('My Block'), action: () => editor.commands.insertContent({ type: 'myEmbed' }), active: () => false }
```

For feature-flagged blocks, use the spread conditional:
```js
...(window._VL_SHOP_ENABLED === '1' ? [{ label: '🎟', title: tjs('My Block'), action: () => editor.commands.insertContent({ type: 'myEmbed' }), active: () => false }] : []),
```

For content-type-gated blocks, add `visible`:
```js
{ label: '👶', title: tjs('Child Pages'), action: () => editor.commands.insertContent({ type: 'pagesEmbed' }), active: () => false, visible: () => _getEditorContentType() === 'page' },
```

**`slash-menu.js`:**

Add an entry to the `SLASH_ITEMS` array (after the built-in formatting items, before the alignment items):

```js
{
  icon: '🎯',
  title: tjs('My Block'),
  description: tjs('Description of what this block does'),
  visible: () => window._VL_SHOP_ENABLED !== '0', // optional
  command: ({ editor, range }) => {
    editor.chain().focus().deleteRange(range).run();
    editor.commands.insertContent({ type: 'myEmbed' });
  },
},
```

### Step 7 — Translations (`resources/views/admin/layout.twig` + `resources/lang/*.json`)

Every user-facing string in editor JS **must** have a matching key in `window.VL_I18N`. If the block is gated by a feature flag, the flag script must also be added to `layout.twig`.

**In `resources/views/admin/layout.twig`:**

1. Add the feature flag script (if needed) near the top, inside the `<head>`:
   ```html
   <script>window._VL_MY_MODULE_ENABLED = '{{ my_module_enabled|e('js') }}';</script>
   ```
   The corresponding PHP variable must be passed from the controller (model on existing `shop_enabled`, `newsletter_enabled`, etc.).

2. Add strings to `window.VL_I18N`:
   ```js
   window.VL_I18N = {
     // ... existing keys ...
     "My Block": "{{ __('My Block')|e('js') }}",
     "My Block Settings": "{{ __('My Block Settings')|e('js') }}",
     "Description of what this block does": "{{ __('Description of what this block does')|e('js') }}",
     "Settings": "{{ __('Settings')|e('js') }}",
     "Remove": "{{ __('Remove')|e('js') }}",
     "Empty state — click the gear to configure": "{{ __('Empty state — click the gear to configure')|e('js') }}",
     "Label": "{{ __('Label')|e('js') }}",
     "Count": "{{ __('Count')|e('js') }}",
     "Mode": "{{ __('Mode')|e('js') }}",
     "Option A": "{{ __('Option A')|e('js') }}",
     "Option B": "{{ __('Option B')|e('js') }}",
     "Color": "{{ __('Color')|e('js') }}",
     "Enabled": "{{ __('Enabled')|e('js') }}",
     "Link to page": "{{ __('Link to page')|e('js') }}",
     "Select a page...": "{{ __('Select a page...')|e('js') }}",
   };
   ```

3. In all editor JS files, use `tjs('...')` instead of raw strings:
   ```js
   title.textContent = tjs('My Block');
   settingsBtn.title = tjs('Settings');
   ```

4. Add translation keys to `resources/lang/en.json`, `resources/lang/tr.json`, etc.:
   ```json
   {
     "My Block": "My Block",
     "My Block Settings": "My Block Settings",
     ...
   }
   ```

### Step 8 — Admin editor documentation

Update `vale-docs/en/latest/admin/content/editor.md` with a short entry for the new block: name, one-line description, what it does, and which feature flag gates it (if any). Keep it brief — the detailed reference lives in the developer docs.

### Step 9 — Frontend render (shortcode-backed blocks)

The editor only produces storage format; the public-site HTML is rendered by `ShortcodeProcessor::process*()` in `src/Infrastructure/Content/ShortcodeProcessor.php`. Implement that side via the **vale-shortcode** skill in the same change set: whitelist every attribute (colors, URLs, sizes), escape all output with `htmlspecialchars`, prefer inline styles so all four themes work without theme CSS, and add PHPUnit tests mirroring the converter round-trip.

## Phase 3 — Verify

- `npm run build:editor:dev` — bundles without errors and produces `public/assets/js/editor.js`.
- `npm run build:editor` — production bundle to `public/assets/build/editor.js` (if assets are built for deployment).
- Manual smoke test: open Admin → Posts → Edit, find the block in the toolbar "Insert element" dropdown and in the slash menu (`/`), insert it, open settings, change values, save, reload, confirm the block persists with the correct attributes.
- Dark mode test: toggle admin theme to dark, confirm the block chrome is readable.
- Feature flag test: disable the module flag in `.env` or settings, confirm the block is hidden from toolbar and slash menu.
- `php -l resources/views/admin/layout.twig` if Twig was modified.

## Checklist

- [ ] Node file created in correct subfolder (`embeds/`, `nodes/`, or `extensions/`) following `Node.create()` / `Mark.create()` pattern
- [ ] `name`, `group`, `atom`, `draggable` set correctly; `addAttributes` has `default`, `parseHTML`, and `renderHTML` where needed
- [ ] `parseHTML` selector matches `renderHTML` output; `mergeAttributes(HTMLAttributes, ...)` used in `renderHTML`
- [ ] `addNodeView` returns `dom` + `update()`; `getPos` guarded with `typeof getPos === 'function'`
- [ ] If inner editable content: `contentDOM`, `stopEvent()`, and `ignoreMutation()` implemented
- [ ] Registered in `index.js` extensions array + imported at top
- [ ] Settings dialog uses `openBlockSettings` with declared field keys (or inline controls for simple blocks)
- [ ] Converter added if shortcode-backed: bidirectional, both `<p>`-wrapped and bare regex variants, `&quot;` safe, registered in `index.js` load + onUpdate chains
- [ ] CSS added to `styles.js` with `.dark` variants for every color/bg/border property
- [ ] Toolbar entry added in `toolbar.js` "Insert element" dropdown
- [ ] Slash menu entry added in `slash-menu.js` `SLASH_ITEMS` array
- [ ] Double-click handler added in `index.js` if applicable
- [ ] Keyboard shortcut added in `index.js` if applicable
- [ ] Feature flag added to `layout.twig` + PHP controller variable if gated
- [ ] All user-facing strings added to `window.VL_I18N` in `layout.twig`
- [ ] Translation keys added to `resources/lang/en.json` (and other locale files)
- [ ] `npm run build:editor:dev` succeeds
- [ ] Docs: update `vale-docs/en/latest/admin/content/editor.md` with a short block entry (name, description, gating flag)
- [ ] Docs: if shortcode-backed, document the shortcode in `vale-docs/en/latest/developer/shortcodes.md` (only `en/latest`, not versioned snapshots)
