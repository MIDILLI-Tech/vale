/**
 * Link button group settings dialog.
 *
 * Opens a modal with one tab per button (tab label = the button's inline text).
 * Each tab edits that button's own attrs (href internal/external, colors,
 * border, padding, fullWidth). Also exposes group-level layout/gap and an
 * Add/Delete control. Edits are applied to the actual child nodes via
 * setNodeMarkup at their live positions.
 */
import { createModal } from '../ui/modal.js';
import { renderField, labelEl } from './schema-modal.js';
import { linkField, BORDER_WIDTHS, BORDER_STYLES, BORDER_RADIUSES, PADDING_OPTIONS, DEFAULT_BUTTON_ATTRS } from './link-button-settings.js';

const FULL_WIDTH_OPTIONS = [
  { v: 'false', l: tjs('No') },
  { v: 'true', l: tjs('Yes') },
];

const TARGET_OPTIONS = [
  { v: '_self', l: tjs('Same window') },
  { v: '_blank', l: tjs('New tab') },
];

const LAYOUT_OPTIONS = [
  { v: 'vertical', l: tjs('Stacked (vertical)') },
  { v: 'horizontal', l: tjs('Side by side (horizontal)') },
];

const ALIGNMENT_OPTIONS = [
  { v: 'left', l: tjs('Align left') },
  { v: 'center', l: tjs('Center') },
  { v: 'right', l: tjs('Align right') },
];

function buttonText(child) {
  return (child.textContent || '').trim() || tjs('Button');
}

function resolveChildren(editor, groupPos) {
  const node = editor.state.doc.nodeAt(groupPos);
  if (!node || node.type.name !== 'linkButtonGroup') return [];
  const children = [];
  let childPos = groupPos + 1;
  node.forEach((child, offset, i) => {
    children.push({ pos: childPos, node: child });
    childPos += child.nodeSize;
  });
  return children;
}

/**
 * Index (within the group at groupPos) of the button the caret is currently
 * editing, so the settings dialog opens on that button instead of always the
 * first one. Falls back to 0 when the selection isn't inside this group.
 */
export function activeButtonIndex(editor, groupPos) {
  const sel = editor?.state?.selection;
  const $pos = sel && (sel.$anchor || sel.$from);
  if (!$pos) return 0;
  // Text caret / selection inside a button's content → that button's index.
  // `$pos.index(d)` is the index of the button's own child (its text), so the
  // button's index within the group is read one level up: index(d - 1).
  for (let d = $pos.depth; d > 0; d--) {
    const n = $pos.node(d);
    if (n && n.type.name === 'linkButton') {
      // Only when the button belongs to the group being edited.
      return $pos.before(d - 1) === groupPos ? $pos.index(d - 1) : 0;
    }
  }
  // Caret or node-selection resting right before a button (clicked into an
  // empty button / arrowed onto it) → the next button is the active one.
  const next = $pos.nodeAfter;
  if (next && next.type.name === 'linkButton' && $pos.before($pos.depth) === groupPos) {
    return $pos.index($pos.depth);
  }
  return 0;
}

export function openLinkButtonGroupSettings(editor, groupPos, initialIndex = 0) {
  const m = createModal({ className: 'vl-modal vl-modal-lg', backdropGuardMs: 500, structured: true });

  const head = document.createElement('div');
  head.className = 'vl-editor-modal-head';

  const h3 = document.createElement('h3');
  h3.className = 'vl-editor-modal-title';
  h3.textContent = tjs('Link Buttons');
  head.appendChild(h3);
  m.modal.appendChild(head);

  const body = document.createElement('div');
  body.className = 'vl-editor-modal-body';
  m.modal.appendChild(body);

  const bodyWrap = document.createElement('div');
  bodyWrap.className = 'vl-modal-flex-col pr-1';
  bodyWrap.style.minHeight = '440px';
  body.appendChild(bodyWrap);

  let tabsEl = null;
  let panelsWrap = null;
  let panels = []; // { patch, readers, el }
  let active = Math.max(0, initialIndex | 0);

  function groupAttrs() {
    const node = editor.state.doc.nodeAt(groupPos);
    return node ? { ...node.attrs } : { layout: 'vertical', gap: '12' };
  }

  function buildPanelViews(children) {
    panels = [];
    const panelsFragment = document.createDocumentFragment();

    children.forEach((child, index) => {
      const wrap = document.createElement('div');
      wrap.className = 'vl-group-btn-panel';
      wrap.style.display = index === active ? '' : 'none';

      const patch = { ...child.node.attrs };
      const ctx = {
        editor,
        pos: child.pos,
        node: child.node,
        modal: m.modal,
        close: () => m.close(),
        getValue: k => patch[k],
        setValue: (k, v) => { patch[k] = v; },
      };

      const readers = [];

      // Show which button this tab edits (left-aligned indicator).
      const currentLbl = document.createElement('div');
      currentLbl.className = 'vl-group-current-label';
      currentLbl.appendChild(document.createTextNode(tjs('Current button') + ': '));
      const currentName = document.createElement('b');
      currentName.textContent = buttonText(child.node);
      currentLbl.appendChild(currentName);
      wrap.appendChild(currentLbl);

      // Link (internal/external) — custom renderer
      wrap.appendChild(linkField().render(ctx));

      // Fields that edit attrs
      const fields = [
        { key: 'target', type: 'select', label: tjs('Target'), options: TARGET_OPTIONS },
        { key: 'bgColor', type: 'color', label: tjs('Background Color'), pickerDefault: '#000000', fallbackValue: '#000000' },
        { key: 'textColor', type: 'color', label: tjs('Text Color'), pickerDefault: '#ffffff', fallbackValue: '#ffffff' },
        { key: 'borderWidth', type: 'select', label: tjs('Border Width'), options: BORDER_WIDTHS },
        { key: 'borderStyle', type: 'select', label: tjs('Border Style'), options: BORDER_STYLES },
        { key: 'borderColor', type: 'color', label: tjs('Border Color'), pickerDefault: '#000000', fallbackValue: '#000000' },
        { key: 'borderRadius', type: 'select', label: tjs('Border Radius'), options: BORDER_RADIUSES },
        { key: 'padding', type: 'select', label: tjs('Padding'), options: PADDING_OPTIONS },
        { key: 'alignment', type: 'select', label: tjs('Alignment'), options: ALIGNMENT_OPTIONS },
        { key: 'fullWidth', type: 'select', label: tjs('Full Width'), options: FULL_WIDTH_OPTIONS },
      ];

      const hr = document.createElement('hr');
      hr.className = 'border-t border-gray-200 dark:border-gray-700 my-2';
      wrap.appendChild(hr);

      // Two-column responsive grid — stacks to one column on narrow/mobile widths.
      const grid = document.createElement('div');
      grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;';
      fields.forEach(f => {
        const { el, read } = renderField(editor, f, child.node.attrs[f.key], ctx);
        readers.push(() => { patch[f.key] = read(); });
        el.style.flex = '1 1 calc(50% - 12px)';
        el.style.minWidth = '200px';
        grid.appendChild(el);
      });
      wrap.appendChild(grid);

      // Delete button (keep >= 1)
      const delRow = document.createElement('div');
      delRow.className = 'flex mt-3';
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'vl-btn vl-btn-danger text-xs';
      delBtn.textContent = tjs('Delete this button');
      delBtn.disabled = children.length <= 1;
      delBtn.addEventListener('click', () => {
        if (children.length <= 1) return;
        applyPanels();
        const ch = resolveChildren(editor, groupPos);
        const target = ch[index];
        if (target) {
          editor.chain().focus()
            .deleteRange({ from: target.pos, to: target.pos + target.node.nodeSize }).run();
          if (active >= ch.length - 1) active = ch.length - 2;
          render();
        }
      });
      delRow.appendChild(delBtn);
      wrap.appendChild(delRow);

      panels.push({ patch, readers, el: wrap });
      panelsFragment.appendChild(wrap);
    });

    return panelsFragment;
  }

  function render() {
    bodyWrap.innerHTML = '';

    // Group-level layout controls
    const groupControls = document.createElement('div');
    groupControls.className = 'mb-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg';
    const gAttrs = groupAttrs();

    const layoutLbl = labelEl(tjs('Button Layout'));
    groupControls.appendChild(layoutLbl);
    const layoutSel = document.createElement('select');
    layoutSel.className = 'vl-select w-full mb-2';
    LAYOUT_OPTIONS.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.v; opt.textContent = tjs(o.l);
      if (gAttrs.layout === o.v) opt.selected = true;
      layoutSel.appendChild(opt);
    });
    layoutSel.addEventListener('change', () => {
      const g = editor.state.doc.nodeAt(groupPos);
      if (g) editor.chain().focus().command(({ tr }) => {
        tr.setNodeMarkup(groupPos, undefined, { ...g.attrs, layout: layoutSel.value });
        return true;
      }).run();
    });
    groupControls.appendChild(layoutSel);

    const gapLbl = labelEl(tjs('Gap between buttons (px)'));
    groupControls.appendChild(gapLbl);
    const gapInput = document.createElement('input');
    gapInput.type = 'number';
    gapInput.min = 0;
    gapInput.className = 'vl-input w-full';
    gapInput.value = String(gAttrs.gap ?? '12');
    gapInput.addEventListener('change', () => {
      const g = editor.state.doc.nodeAt(groupPos);
      if (g) editor.chain().focus().command(({ tr }) => {
        tr.setNodeMarkup(groupPos, undefined, { ...g.attrs, gap: String(gapInput.value || '0') });
        return true;
      }).run();
    });
    groupControls.appendChild(gapInput);
    bodyWrap.appendChild(groupControls);

    // Tab bar
    const children = resolveChildren(editor, groupPos);
    if (active >= children.length) active = Math.max(0, children.length - 1);

    tabsEl = document.createElement('div');
    tabsEl.className = 'flex flex-wrap gap-1 mb-3 border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden p-1 items-center';
    // Never let the scrollable flex column crush the tab bar: it has
    // overflow-hidden, so without flex-shrink:0 the bar can collapse to a
    // sliver and clip the tab labels (the old 45px padding masked this).
    tabsEl.style.flexShrink = '0';

    children.forEach((child, index) => {
      const tab = document.createElement('button');
      tab.type = 'button';
      tab.className = 'vl-editor-modal-tab' + (index === active ? ' is-active' : '');
      tab.textContent = buttonText(child.node);
      tab.title = tjs('Edit this button');
      tab.addEventListener('click', () => { active = index; renderBody(); });
      tabsEl.appendChild(tab);
    });

    // "+" add button tab
    const addTab = document.createElement('button');
    addTab.type = 'button';
    addTab.className = 'vl-editor-modal-tab';
    addTab.textContent = '+';
    addTab.title = tjs('Add button');
    addTab.addEventListener('click', () => {
      applyPanels();
      const children2 = resolveChildren(editor, groupPos);
      const end = groupPos + editor.state.doc.nodeAt(groupPos).nodeSize - 1;
      editor.chain().focus()
        .insertContentAt(end, { type: 'linkButton', attrs: { ...DEFAULT_BUTTON_ATTRS }, content: [{ type: 'text', text: tjs('Button Text') }] }).run();
      active = resolveChildren(editor, groupPos).length - 1;
      render();
    });
    tabsEl.appendChild(addTab);
    bodyWrap.appendChild(tabsEl);

    // Panels
    panelsWrap = document.createElement('div');
    panelsWrap.appendChild(buildPanelViews(children));
    bodyWrap.appendChild(panelsWrap);
  }

  function renderBody() {
    if (!panelsWrap) return render();
    panels.forEach((pan, i) => {
      pan.el.style.display = i === active ? '' : 'none';
    });
    // Update tab highlight. The last tab is the "+" add control; only the
    // button tabs (panels.length of them) reflect the active selection.
    const tabBtns = [...tabsEl.querySelectorAll('.vl-editor-modal-tab')];
    tabBtns.forEach((t, ti) => t.classList.toggle('is-active', ti === active && ti < panels.length));
  }

  function applyPanels() {
    const children = resolveChildren(editor, groupPos);
    children.forEach((child, index) => {
      const pan = panels[index];
      if (!pan) return;
      pan.readers.forEach(r => r());
      const current = editor.state.doc.nodeAt(child.pos);
      const base = current ? { ...current.attrs } : { ...child.node.attrs };
      editor.chain().focus().command(({ tr }) => {
        tr.setNodeMarkup(child.pos, undefined, { ...base, ...pan.patch });
        return true;
      }).run();
    });
  }

  render();

  const actionsRow = document.createElement('div');
  actionsRow.className = 'vl-editor-modal-foot';
  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button';
  cancelBtn.className = 'vl-btn vl-btn-secondary';
  cancelBtn.textContent = tjs('Cancel');
  cancelBtn.addEventListener('click', () => m.close());
  const saveBtn = document.createElement('button');
  saveBtn.type = 'button';
  saveBtn.className = 'vl-btn vl-btn-primary';
  saveBtn.textContent = tjs('Save');
  saveBtn.addEventListener('click', () => {
    applyPanels();
    m.close();
  });
  actionsRow.appendChild(cancelBtn);
  actionsRow.appendChild(saveBtn);
  m.modal.appendChild(actionsRow);
}
