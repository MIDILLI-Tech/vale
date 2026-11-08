/**
 * Table cell / header extensions with cell-level styling attributes.
 *
 * Cell styles live in node attributes and are serialized as inline styles on
 * the <td>/<th> element, so the editor renders exactly the markup the front
 * end receives. Nothing here depends on editor-only CSS variables.
 */
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';

/**
 * Reads a cell's column widths back from the saved HTML.
 *
 * TipTap serializes widths into the <colgroup> as `col style="width: Npx"`,
 * while its default parser only looks at a `width` attribute — so widths of
 * tables authored outside the editor (or exported) were dropped on load.
 */
function parseColwidth(element) {
  const attribute = element.getAttribute('colwidth');
  if (attribute) return attribute.split(',').map(value => parseInt(value, 10));

  const row = element.parentElement;
  const table = element.closest('table');
  if (!row || !table) return null;

  let columnIndex = 0;
  for (const cell of Array.from(row.children)) {
    if (cell === element) break;
    columnIndex += parseInt(cell.getAttribute('colspan') || '1', 10);
  }

  const span = parseInt(element.getAttribute('colspan') || '1', 10);
  const cols = Array.from(table.querySelectorAll('colgroup > col')).slice(columnIndex, columnIndex + span);
  if (!cols.length) return null;

  const widths = cols.map(col => parseInt(col.getAttribute('width') || col.style?.width || '', 10));
  return widths.every(Number.isFinite) ? widths : null;
}

const colwidthAttribute = {
  default: null,
  parseHTML: parseColwidth,
};

const cellStyleAttributes = {
  backgroundColor: {
    default: null,
    parseHTML: element => element.style?.backgroundColor || element.getAttribute('bgcolor') || null,
    renderHTML: attributes =>
      (attributes.backgroundColor ? { style: `background-color: ${attributes.backgroundColor}` } : {}),
  },
  verticalAlign: {
    default: null,
    parseHTML: element => element.style?.verticalAlign || element.getAttribute('valign') || null,
    renderHTML: attributes =>
      (attributes.verticalAlign ? { style: `vertical-align: ${attributes.verticalAlign}` } : {}),
  },
};

const TableCellWithStyle = TableCell.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      colwidth: colwidthAttribute,
      ...cellStyleAttributes,
    };
  },
});

const TableHeaderWithStyle = TableHeader.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      colwidth: colwidthAttribute,
      ...cellStyleAttributes,
    };
  },
});

export { TableCellWithStyle, TableHeaderWithStyle };
