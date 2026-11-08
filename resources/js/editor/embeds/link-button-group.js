/**
 * Link button group block.
 *
 * Top-level block containing one or more linkButton children. The group owns
 * the shared header (grip, add button, layout toggle, settings gear, delete)
 * and lays the buttons out vertically or horizontally via a flex contentDOM.
 * The group itself is not natively draggable; reordering is done manually from
 * the header grip.
 */
import { NodeSelection } from '@tiptap/pm/state';
import { Node, mergeAttributes } from '@tiptap/core';

import { openLinkButtonGroupSettings, activeButtonIndex } from '../settings/link-button-group-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';
import { DEFAULT_BUTTON_ATTRS } from '../settings/link-button-settings.js';

const LinkButtonGroup = Node.create({
  name: 'linkButtonGroup',
  group: 'block',
  content: 'linkButton+',
  selectable: true,
  draggable: false,

  addAttributes() {
    return {
      layout: {
        default: 'vertical',
        parseHTML: el => el.getAttribute('data-layout') || 'vertical',
        renderHTML: attrs => ({ 'data-layout': attrs.layout }),
      },
      gap: {
        default: '12',
        parseHTML: el => el.getAttribute('data-gap') || '12',
        renderHTML: attrs => ({ 'data-gap': attrs.gap }),
      },
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-link-button-group]',
      getAttrs(dom) {
        return {
          layout: dom.getAttribute('data-layout') || 'vertical',
          gap: dom.getAttribute('data-gap') || '12',
        };
      }
    }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'data-link-button-group': '1',
      class: 'vl-link-button-group-block',
    }), 0];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-link-button-group-block';
      dom.setAttribute('data-link-button-group', '1');
      // Keep data-layout in sync so styles.js' per-layout rules (e.g. horizontal
      // equal flex cells) apply to the live node view, not just serialized HTML.
      dom.dataset.layout = node.attrs.layout || 'vertical';

      const header = document.createElement('div');
      header.className = 'vl-link-button-group-header';
      header.innerHTML = `<span class="flex-1">🔘 ${tjs('Link Buttons')}</span>`;

      // Layout quick toggle (vertical ↔ horizontal)
      const layoutBtn = document.createElement('button');
      layoutBtn.type = 'button';
      layoutBtn.dataset.layoutToggle = '1';
      function syncLayoutBtn() {
        const horizontal = node.attrs.layout === 'horizontal';
        layoutBtn.textContent = horizontal ? '⇅' : '⇄';
        layoutBtn.title = horizontal
          ? tjs('Switch to stacked layout')
          : tjs('Switch to side-by-side layout');
      }
      layoutBtn.addEventListener('click', () => {
        const pos = getPos();
        const n = editor.state.doc.nodeAt(pos);
        if (!n) return;
        const next = n.attrs.layout === 'horizontal' ? 'vertical' : 'horizontal';
        editor.chain().focus().command(({ tr }) => {
          tr.setNodeMarkup(pos, undefined, { ...n.attrs, layout: next });
          return true;
        }).run();
      });
      syncLayoutBtn();
      header.appendChild(layoutBtn);

      // Add button
      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.dataset.add = '1';
      addBtn.textContent = '+';
      addBtn.title = tjs('Add button');
      addBtn.addEventListener('click', () => {
        const pos = getPos();
        const n = editor.state.doc.nodeAt(pos);
        if (!n) return;
        const end = pos + n.nodeSize - 1;
        editor.chain().focus()
          .insertContentAt(end, { type: 'linkButton', attrs: { ...DEFAULT_BUTTON_ATTRS }, content: [{ type: 'text', text: tjs('Button Text') }] }).run();
      });
      header.appendChild(addBtn);

      // Settings gear
      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Button Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();
      });
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const pos = getPos();
        // Open on the button whose text the caret is in, not always the first.
        openLinkButtonGroupSettings(editor, pos, activeButtonIndex(editor, pos));
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));

      // Delete whole group
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'vl-form-embed-delete';
      delBtn.textContent = '✕';
      delBtn.title = tjs('Delete Button Group');
      delBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });
      header.appendChild(delBtn);

      const selectGroup = (event) => {
        event.preventDefault();
        event.stopPropagation();
        const pos = getPos();
        if (typeof pos !== 'number') return;
        const { state, dispatch } = editor.view;
        const group = state.doc.nodeAt(pos);
        if (!group || group.type.name !== 'linkButtonGroup') return;
        editor.view.focus();
        dispatch(state.tr.setSelection(NodeSelection.create(state.doc, pos)));
      };
      header.addEventListener('click', selectGroup);

      // Manual drag-to-reorder the whole group from its header.
      header.draggable = true;
      let dragOrigin = null;
      const dragToken = 'text/vale-linkbuttongroup';
      header.addEventListener('dragstart', (e) => {
        dragOrigin = getPos();
        e.dataTransfer.setData(dragToken, '1');
        e.dataTransfer.effectAllowed = 'move';
      });
      header.addEventListener('dragend', () => { dragOrigin = null; });
      const viewDom = editor.options.element || editor.view.dom;
      viewDom.addEventListener('dragover', (e) => {
        if (dragOrigin !== null && e.dataTransfer && e.dataTransfer.types.includes(dragToken)) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
        }
      });
      viewDom.addEventListener('drop', (e) => {
        if (dragOrigin === null || !(e.dataTransfer && e.dataTransfer.types.includes(dragToken))) return;
        e.preventDefault();
        e.stopPropagation();
        const coords = editor.view.posAtCoords({ left: e.clientX, top: e.clientY });
        if (coords) {
          const { state, dispatch } = editor.view;
          const src = dragOrigin;
          const n = state.doc.nodeAt(src);
          if (n) {
            let target = coords.pos;
            if (target > src) target = target - n.nodeSize;
            dispatch(state.tr.delete(src, src + n.nodeSize).insert(target, n));
          }
        }
        dragOrigin = null;
      });

      dom.appendChild(header);

      // contentDOM — holds the child linkButton blocks
      const listDOM = document.createElement('div');
      listDOM.className = 'vl-link-button-content';

      function applyLayout() {
        const gap = node.attrs.gap || '12';
        listDOM.style.display = 'flex';
        listDOM.style.rowGap = gap + 'px';
        listDOM.style.columnGap = gap + 'px';
        if (node.attrs.layout === 'horizontal') {
          listDOM.style.flexDirection = 'row';
          listDOM.style.flexWrap = 'wrap';
          listDOM.style.alignItems = 'center';
        } else {
          listDOM.style.flexDirection = 'column';
          listDOM.style.flexWrap = 'nowrap';
          listDOM.style.alignItems = 'stretch';
        }
      }
      applyLayout();

      dom.appendChild(listDOM);

      return {
        dom,
        contentDOM: listDOM,
        stopEvent(event) {
          const t = event.target;
          return t === settingsBtn
            || t === delBtn
            || t === addBtn
            || t === layoutBtn
            || Boolean(t?.closest?.('.vl-link-button-group-header'));
        },
        ignoreMutation() {
          return true;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'linkButtonGroup') return false;
          node = updatedNode;
          dom.dataset.layout = node.attrs.layout || 'vertical';
          applyLayout();
          syncLayoutBtn();
          return true;
        }
      };
    };
  }
});

export { LinkButtonGroup };
