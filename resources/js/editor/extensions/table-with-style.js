/**
 * Table extension preserving the user's `style` attribute + column resizing.
 *
 * The inline style is the single source of truth for the table's border,
 * width, padding variables and alignment. It is rendered verbatim by TipTap
 * (see Table::renderHTML → getTableStyle), so the editor DOM and the saved
 * markup carry the exact same style string.
 */
import { Table } from '@tiptap/extension-table';
import { columnResizing, tableEditing } from '@tiptap/pm/tables';

import { PreservingTableView } from '../extensions/image-with-size.js';

const TableWithStyle = Table.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      style: {
        default: null,
        parseHTML: element => element.getAttribute('style'),
      },
    };
  },
  addProseMirrorPlugins() {
    if (!this.options.resizable || !this.editor.isEditable) {
      return [tableEditing({ allowTableNodeSelection: false })];
    }
    return [
      columnResizing({
        handleWidth: this.options.handleWidth,
        cellMinWidth: this.options.cellMinWidth,
        defaultCellMinWidth: this.options.cellMinWidth,
        View: PreservingTableView,
        lastColumnResizable: this.options.lastColumnResizable,
      }),
      tableEditing({ allowTableNodeSelection: false }),
    ];
  },
});

export { TableWithStyle };
