/**
 * Table insertion, table properties and cell properties dialogs.
 *
 * The dialogs only write node attributes — never the DOM. TipTap renders the
 * resulting inline styles into the editor, so what the author sees is exactly
 * the markup that gets saved and served by the four front-end themes.
 */
import { colorSwatchTitle, openColorPicker } from './ui/color-picker.js';
import { createModal } from './ui/modal.js';

const DEFAULT_BORDER_WIDTH = '1px';
const DEFAULT_BORDER_STYLE = 'solid';
const DEFAULT_BORDER_COLOR = '#d1d5db';
const DEFAULT_PADDING = '8px';

function insertTable(editor, rows = 3, cols = 3) {
  editor.chain().focus().insertTable({ rows, cols, withHeaderRow: true }).run();
}

function getSelectedTablePos(editor) {
  const $from = editor.state.selection.$from;
  for (let depth = $from.depth; depth > 0; depth--) {
    const node = $from.node(depth);
    if (node.type.name === 'table') {
      return { node, pos: $from.before(depth) };
    }
  }
  return null;
}

/** Reads a single declaration out of a CSS string (custom properties included). */
function styleValue(style, property) {
  const escaped = property.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = new RegExp(`(?:^|;)\\s*${escaped}\\s*:\\s*([^;]*)`).exec(style || '');
  return match ? match[1].trim() : '';
}

function rgbToHex(color) {
  const match = String(color || '').match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*1(?:\.0+)?\s*)?\)$/i);
  if (!match) return color || '';
  return '#' + [match[1], match[2], match[3]]
    .map(n => parseInt(n, 10).toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Parses a border shorthand. `null` means "not declared" — the table then
 * inherits the theme's default cell border, which is what the dialog shows as
 * an empty field. An explicit `0 solid …` stays explicit so authors can turn
 * the grid off intentionally.
 */
function parseBorder(value) {
  const border = String(value || '').trim();
  if (!border || /^none$/i.test(border)) return null;
  const parts = border.match(
    /^([\d.]+(?:[a-z]+|%))\s+(solid|dashed|dotted|double|groove|ridge|inset|outset)\s+(.+)$/i,
  );
  if (!parts) {
    return { width: '', style: DEFAULT_BORDER_STYLE, color: rgbToHex(border) };
  }
  return { width: parts[1], style: parts[2].toLowerCase(), color: rgbToHex(parts[3].trim()) };
}

function readBorder(style) {
  return parseBorder(styleValue(style, '--vl-cell-border') || styleValue(style, 'border'));
}

function borderToString(border) {
  if (!border) return null;
  return `${border.width || DEFAULT_BORDER_WIDTH} ${border.style || DEFAULT_BORDER_STYLE} ${border.color || DEFAULT_BORDER_COLOR}`;
}

function parseDeclarations(style) {
  const declarations = [];
  for (const part of String(style || '').split(';')) {
    const separator = part.indexOf(':');
    if (separator === -1) continue;
    const prop = part.slice(0, separator).trim();
    const value = part.slice(separator + 1).trim();
    if (prop) declarations.push({ prop, value });
  }
  return declarations;
}

function serializeDeclarations(declarations) {
  if (!declarations.length) return null;
  return declarations.map(({ prop, value }) => `${prop}: ${value}`).join('; ') + ';';
}

function hasDeclaration(declarations, prop) {
  const key = prop.toLowerCase();
  return declarations.some(declaration => declaration.prop.toLowerCase() === key);
}

/** Sets or removes a declaration in place, keeping the author's existing order. */
function setDeclaration(declarations, prop, value) {
  const key = prop.toLowerCase();
  const indexes = [];
  declarations.forEach((declaration, index) => {
    if (declaration.prop.toLowerCase() === key) indexes.push(index);
  });

  if (!value) {
    for (const index of indexes.reverse()) declarations.splice(index, 1);
    return;
  }

  if (indexes.length) {
    declarations[indexes[0]].value = value;
    for (const index of indexes.slice(1).reverse()) declarations.splice(index, 1);
    return;
  }

  declarations.push({ prop, value });
}

/**
 * Applies only the dialog fields the author actually changed to the existing
 * inline style. Declarations the dialog does not own (`min-width`, `max-width`,
 * legacy `text-align`, …) and untouched fields are left exactly as they were —
 * saving alignment must not rewrite the whole table. A no-op save returns the
 * original string untouched.
 */
function buildTableStyle(current, initial, next) {
  const declarations = parseDeclarations(current);
  const same = (a, b) => (a ?? null) === (b ?? null);
  let changed = false;

  if (!same(borderToString(initial.border), borderToString(next.border))) {
    changed = true;
    const border = borderToString(next.border);
    setDeclaration(declarations, '--vl-cell-border', border);
    // Keep a pre-existing outer `border` in sync; never add one the author
    // did not have (the theme draws the frame from the collapsed cell borders).
    if (hasDeclaration(declarations, 'border')) setDeclaration(declarations, 'border', border);
  }

  if (!same(initial.padding, next.padding)) {
    changed = true;
    setDeclaration(declarations, '--vl-table-cell-padding', next.padding);
  }

  if (!same(initial.width, next.width)) {
    changed = true;
    setDeclaration(declarations, 'width', next.width);
  }

  if (!same(initial.align, next.align)) {
    changed = true;
    setDeclaration(declarations, 'margin-left', next.align ? 'auto' : null);
    setDeclaration(declarations, 'margin-right', next.align === 'center' ? 'auto' : (next.align === 'right' ? '0' : null));
    // TipTap rebuilds the style by merging declarations, which resurrects a
    // deleted `text-align`. Neutralise a legacy value instead of removing it,
    // otherwise centring left over from old content survives "Default (left)".
    if (hasDeclaration(declarations, 'text-align')) setDeclaration(declarations, 'text-align', 'initial');
  }

  // Nothing the author changed: keep the author's style byte for byte instead
  // of re-serializing it (reformatting alone is still a diff in the saved post).
  if (!changed) return current || null;
  return serializeDeclarations(declarations);
}

function normalizeWidthInput(value) {
  const width = String(value || '').trim();
  if (!width) return null;
  if (/^\d+$/.test(width)) return `${width}px`;
  return width;
}

function resolveTableAlign(style) {
  const marginLeft = styleValue(style, 'margin-left');
  const marginRight = styleValue(style, 'margin-right');
  if (/auto/.test(marginLeft) && /auto/.test(marginRight)) return 'center';
  if (/auto/.test(marginLeft)) return 'right';
  const legacy = styleValue(style, 'text-align');
  return legacy === 'center' ? 'center' : (legacy === 'right' ? 'right' : '');
}

function openTableProperties(editor) {
  const table = getSelectedTablePos(editor);
  if (!table) return;

  const { node } = table;
  const style = node.attrs?.style || '';
  const border = readBorder(style);
  const padding = styleValue(style, '--vl-table-cell-padding');
  const width = styleValue(style, 'width');
  const align = resolveTableAlign(style);
  // Snapshot of what the dialog opened with: only fields the author changes
  // are written back, so unrelated declarations survive a save untouched.
  const initial = { border, padding: padding || null, width: width || null, align };

  // Mirrors prosemirror-tables: a header row is a row whose cells are ALL
  // header cells, so the checkbox can never disagree with toggleHeaderRow().
  const firstRow = node.firstChild;
  const firstRowCells = firstRow
    ? Array.from({ length: firstRow.childCount }, (_, index) => firstRow.child(index))
    : [];
  const hasHeader = firstRowCells.length > 0 && firstRowCells.every(cell => cell.type.name === 'tableHeader');
  const rowCount = node.childCount;
  const colCount = firstRow ? firstRow.childCount : 0;

  const modal = createModal({ className: 'vl-modal-sm', structured: true });
  modal.modal.innerHTML = `
    <div class="vl-editor-modal-head">
      <h3 class="vl-editor-modal-title">${tjs('Table properties')}</h3>
    </div>
    <div class="vl-editor-modal-body">
      <label class="vl-label">${tjs('Border')}</label>
      <div class="vl-table-border-grid">
        <div class="vl-table-border-field">
          <span class="vl-table-border-label">${tjs('Size')}</span>
          <input type="text" class="vl-input vl-tb-width" value="${border ? border.width : ''}" placeholder="${tjs('Theme default')}">
        </div>
        <div class="vl-table-border-field">
          <span class="vl-table-border-label">${tjs('Style')}</span>
          <select class="vl-select vl-tb-style">
            <option value="solid" ${!border || border.style === 'solid' ? 'selected' : ''}>solid</option>
            <option value="dashed" ${border && border.style === 'dashed' ? 'selected' : ''}>dashed</option>
            <option value="dotted" ${border && border.style === 'dotted' ? 'selected' : ''}>dotted</option>
            <option value="double" ${border && border.style === 'double' ? 'selected' : ''}>double</option>
          </select>
        </div>
        <div class="vl-table-border-field">
          <span class="vl-table-border-label">${tjs('Color')}</span>
          <span class="vl-tb-color-wrap">
            <input type="text" class="vl-input vl-tb-color-hex" value="${border ? border.color : ''}" placeholder="${DEFAULT_BORDER_COLOR}">
            <button type="button" class="vl-tb-color-swatch" title="${colorSwatchTitle()}" style="background:${border ? border.color : 'transparent'}"></button>
          </span>
        </div>
      </div>
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-0">${tjs('Empty fields keep the theme border.')}</p>
      <label class="vl-label mt-3">${tjs('Cell padding')}</label>
      <input type="text" class="vl-input vl-table-padding" value="${padding}" placeholder="${DEFAULT_PADDING}">
      <label class="vl-label mt-3">${tjs('Width')}</label>
      <input type="text" class="vl-input vl-table-width" value="${width}" placeholder="${tjs('e.g. 50%, 400px, auto')}">
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-0">${tjs('Dragging a column handle sets per-column widths; an explicit width here wins over them.')}</p>
      <label class="vl-label mt-3">${tjs('Alignment')}</label>
      <select class="vl-select vl-table-align-select w-full">
        <option value="">${tjs('Default (left)')}</option>
        <option value="center" ${align === 'center' ? 'selected' : ''}>${tjs('Center')}</option>
        <option value="right" ${align === 'right' ? 'selected' : ''}>${tjs('Right')}</option>
      </select>
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 mb-0">${rowCount} × ${colCount}</p>
      <div class="flex items-center gap-2 my-3.5">
        <input type="checkbox" id="vl-table-header-toggle" ${hasHeader ? 'checked' : ''}>
        <label for="vl-table-header-toggle" class="text-xs text-gray-700 dark:text-gray-300">${tjs('Header row')}</label>
      </div>
    </div>
    <div class="vl-editor-modal-foot">
      <button type="button" class="vl-btn vl-btn-secondary vl-table-prop-cancel">${tjs('Cancel')}</button>
      <button type="button" class="vl-btn vl-btn-primary vl-table-prop-save">${tjs('Save')}</button>
    </div>`;

  const bwInput = modal.modal.querySelector('.vl-tb-width');
  const bsSelect = modal.modal.querySelector('.vl-tb-style');
  const bcHex = modal.modal.querySelector('.vl-tb-color-hex');
  const bcSwatch = modal.modal.querySelector('.vl-tb-color-swatch');
  const paddingInput = modal.modal.querySelector('.vl-table-padding');
  const widthInput = modal.modal.querySelector('.vl-table-width');
  const alignSelect = modal.modal.querySelector('.vl-table-align-select');
  const headerToggle = modal.modal.querySelector('#vl-table-header-toggle');

  bcSwatch.addEventListener('click', () => {
    openColorPicker({
      anchor: bcSwatch,
      currentColor: bcHex.value,
      onPreview(hex) { bcHex.value = hex; bcSwatch.style.background = hex; },
      onSelect(hex) { bcHex.value = hex; bcSwatch.style.background = hex; },
    });
  });
  bcHex.addEventListener('input', () => {
    bcSwatch.style.background = bcHex.value || DEFAULT_BORDER_COLOR;
  });

  modal.modal.querySelector('.vl-table-prop-cancel').addEventListener('click', () => modal.close());
  modal.modal.querySelector('.vl-table-prop-save').addEventListener('click', () => {
    const borderWidth = normalizeWidthInput(bwInput.value);
    const borderColor = bcHex.value.trim();
    const nextBorder = (borderWidth || borderColor)
      ? { width: borderWidth || DEFAULT_BORDER_WIDTH, style: bsSelect.value, color: borderColor || DEFAULT_BORDER_COLOR }
      : null;
    const newStyle = buildTableStyle(style, initial, {
      border: nextBorder,
      padding: paddingInput.value.trim() || null,
      width: normalizeWidthInput(widthInput.value),
      align: alignSelect.value,
    });

    let chain = editor.chain().focus().updateAttributes('table', { style: newStyle });
    if (headerToggle.checked !== hasHeader) chain = chain.toggleHeaderRow();
    chain.run();

    modal.close();
  });
}

function openCellProperties(editor) {
  if (!editor.isActive('tableCell') && !editor.isActive('tableHeader')) return;

  const cellType = editor.isActive('tableHeader') ? 'tableHeader' : 'tableCell';
  const attrs = editor.getAttributes(cellType);
  const backgroundColor = rgbToHex(attrs.backgroundColor || '');
  const align = attrs.align || '';
  const verticalAlign = attrs.verticalAlign || '';

  const options = (values, current) => values
    .map(([value, label]) => `<option value="${value}" ${current === value ? 'selected' : ''}>${label}</option>`)
    .join('');

  const modal = createModal({ className: 'vl-modal-sm', structured: true });
  modal.modal.innerHTML = `
    <div class="vl-editor-modal-head">
      <h3 class="vl-editor-modal-title">${tjs('Cell properties')}</h3>
    </div>
    <div class="vl-editor-modal-body">
      <label class="vl-label">${tjs('Background')}</label>
      <div class="vl-tb-color-wrap">
        <input type="text" class="vl-input vl-cell-bg-hex" value="${backgroundColor}" placeholder="#f3f4f6">
        <button type="button" class="vl-tb-color-swatch" title="${colorSwatchTitle(tjs('Background'))}" style="${backgroundColor ? `background:${backgroundColor}` : ''}"></button>
      </div>
      <button type="button" class="vl-cell-bg-clear mt-1 text-xs text-gray-500 dark:text-gray-400 underline">${tjs('Clear')}</button>
      <label class="vl-label mt-3">${tjs('Text alignment')}</label>
      <select class="vl-select vl-cell-align w-full">
        ${options([['', tjs('Default')], ['left', tjs('Left')], ['center', tjs('Center')], ['right', tjs('Right')]], align)}
      </select>
      <label class="vl-label mt-3">${tjs('Vertical alignment')}</label>
      <select class="vl-select vl-cell-valign w-full">
        ${options([['', tjs('Default')], ['top', tjs('Top')], ['middle', tjs('Middle')], ['bottom', tjs('Bottom')]], verticalAlign)}
      </select>
    </div>
    <div class="vl-editor-modal-foot">
      <button type="button" class="vl-btn vl-btn-secondary vl-cell-prop-cancel">${tjs('Cancel')}</button>
      <button type="button" class="vl-btn vl-btn-primary vl-cell-prop-save">${tjs('Save')}</button>
    </div>`;

  const bgHex = modal.modal.querySelector('.vl-cell-bg-hex');
  const bgSwatch = modal.modal.querySelector('.vl-tb-color-swatch');
  const alignSelect = modal.modal.querySelector('.vl-cell-align');
  const valignSelect = modal.modal.querySelector('.vl-cell-valign');

  bgSwatch.addEventListener('click', () => {
    openColorPicker({
      anchor: bgSwatch,
      currentColor: bgHex.value,
      onPreview(hex) { bgHex.value = hex; bgSwatch.style.background = hex; },
      onSelect(hex) { bgHex.value = hex; bgSwatch.style.background = hex; },
    });
  });
  bgHex.addEventListener('input', () => {
    bgSwatch.style.background = bgHex.value;
  });
  modal.modal.querySelector('.vl-cell-bg-clear').addEventListener('click', () => {
    bgHex.value = '';
    bgSwatch.style.background = '';
  });

  modal.modal.querySelector('.vl-cell-prop-cancel').addEventListener('click', () => modal.close());
  modal.modal.querySelector('.vl-cell-prop-save').addEventListener('click', () => {
    const background = bgHex.value.trim() || null;

    editor.chain()
      .focus()
      .setCellAttribute('backgroundColor', background)
      .setCellAttribute('align', alignSelect.value || null)
      .setCellAttribute('verticalAlign', valignSelect.value || null)
      .run();

    modal.close();
  });
}

export { insertTable, openTableProperties, openCellProperties };
