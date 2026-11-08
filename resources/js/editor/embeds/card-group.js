/**
 * Card grid group block.
 *
 * Top-level block containing one or more cardItem children. The group owns the
 * shared header (grip, add card, settings gear, delete) and lays the cards out
 * in an auto-flowing flex grid via a flex contentDOM. The group itself is not
 * natively draggable; reordering is done manually from the header grip, and
 * each card has its own up/down/delete controls.
 */
import { NodeSelection } from '@tiptap/pm/state';
import { Node, mergeAttributes } from '@tiptap/core';

import { openCardSettings, activeCardIndex } from '../settings/card-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';
import { defaultCardContent } from './card-item.js';

const CardGroup = Node.create({
  name: 'cardGroup',
  group: 'block',
  content: 'cardItem+',
  selectable: true,
  draggable: false,

  addAttributes() {
    return {
      gap: {
        default: '12',
        parseHTML: el => el.getAttribute('data-gap') || '12',
        renderHTML: attrs => ({ 'data-gap': attrs.gap }),
      },
      bg: {
        default: '',
        parseHTML: el => el.getAttribute('data-bg') || '',
        renderHTML: attrs => ({ 'data-bg': attrs.bg }),
      },
      radius: {
        default: '12px',
        parseHTML: el => el.getAttribute('data-radius') || '12px',
        renderHTML: attrs => ({ 'data-radius': attrs.radius }),
      },
      align: {
        default: 'center',
        parseHTML: el => el.getAttribute('data-align') || 'center',
        renderHTML: attrs => ({ 'data-align': attrs.align }),
      },
      halign: {
        default: 'center',
        parseHTML: el => el.getAttribute('data-halign') || 'center',
        renderHTML: attrs => ({ 'data-halign': attrs.halign }),
      },
      width: {
        default: 'auto',
        parseHTML: el => el.getAttribute('data-width') || 'auto',
        renderHTML: attrs => ({ 'data-width': attrs.width || 'auto' }),
      },
      lightbox: {
        default: 'false',
        parseHTML: el => el.getAttribute('data-lightbox') === 'true' ? 'true' : 'false',
        renderHTML: attrs => ({ 'data-lightbox': attrs.lightbox === 'true' ? 'true' : 'false' }),
      },
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-card-group]',
      getAttrs(dom) {
        return {
          gap: dom.getAttribute('data-gap') || '12',
          bg: dom.getAttribute('data-bg') || '',
          radius: dom.getAttribute('data-radius') || '12px',
          align: dom.getAttribute('data-align') || 'center',
          halign: dom.getAttribute('data-halign') || 'center',
          width: dom.getAttribute('data-width') || 'auto',
          lightbox: dom.getAttribute('data-lightbox') === 'true' ? 'true' : 'false',
        };
      },
    }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'data-card-group': '1',
      class: 'vl-card-group-block',
    }), 0];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-card-group-block';
      dom.setAttribute('data-card-group', '1');

      const header = document.createElement('div');
      header.className = 'vl-card-group-header';
      header.contentEditable = 'false';
      header.innerHTML = `<span class="flex-1">🃏 ${tjs('Cards')}</span>`;

      // Add card
      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.dataset.add = '1';
      addBtn.textContent = '+';
      addBtn.title = tjs('Add card');
      addBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const pos = getPos();
        const n = editor.state.doc.nodeAt(pos);
        if (!n) return;
        const end = pos + n.nodeSize - 1;
        const scrollY = window.scrollY;
        editor.commands.insertContentAt(end, defaultCardContent());
        editor.view.dom.focus({ preventScroll: true });
        requestAnimationFrame(() => window.scrollTo(0, scrollY));
      });
      header.appendChild(addBtn);

      // Settings gear
      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Card Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();
      });
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const pos = getPos();
        openCardSettings(editor, pos, activeCardIndex(editor, pos));
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));

      // Delete whole group
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'vl-form-embed-delete';
      delBtn.textContent = '✕';
      delBtn.title = tjs('Delete Cards');
      delBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const pos = getPos();
        const scrollY = window.scrollY;
        editor.commands.deleteRange({ from: pos, to: pos + node.nodeSize });
        requestAnimationFrame(() => window.scrollTo(0, scrollY));
      });
      header.appendChild(delBtn);

      const selectGroup = (event) => {
        event.preventDefault();
        event.stopPropagation();
        const pos = getPos();
        if (typeof pos !== 'number') return;
        const { state, dispatch } = editor.view;
        const group = state.doc.nodeAt(pos);
        if (!group || group.type.name !== 'cardGroup') return;
        editor.view.focus();
        dispatch(state.tr.setSelection(NodeSelection.create(state.doc, pos)));
      };
      header.addEventListener('click', selectGroup);

      // Manual drag-to-reorder the whole group from its header.
      header.draggable = true;
      let dragOrigin = null;
      const dragToken = 'text/vale-cardgroup';
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

      // contentDOM — holds the child cardItem blocks
      const listDOM = document.createElement('div');
      listDOM.className = 'vl-card-group-content';

      function applyLayout() {
        const gap = node.attrs.gap || '12';
        // Centered fixed cells (mirrors the frontend render): every row —
        // full or orphan — is centered and all cards share one width.
        listDOM.style.display = 'flex';
        listDOM.style.flexWrap = 'wrap';
        listDOM.style.gridTemplateColumns = '';
        listDOM.style.justifyContent = 'center';
        listDOM.style.rowGap = gap + 'px';
        listDOM.style.columnGap = gap + 'px';
        // Group width (auto = full width). Cards share the row equally and
        // wrap below ~240px, so any card count stays responsive. Narrow
        // groups are positioned via halign (left/center/right).
        const w = (node.attrs.width || 'auto').trim();
        if (/^\d+(\.\d+)?(px|%)$/.test(w) && w !== '100%') {
          dom.style.maxWidth = w;
          const halign = node.attrs.halign || 'center';
          dom.style.marginLeft = halign === 'left' ? '0' : 'auto';
          dom.style.marginRight = halign === 'right' ? '0' : 'auto';
        } else {
          dom.style.maxWidth = '';
          dom.style.marginLeft = '';
          dom.style.marginRight = '';
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
            || Boolean(t?.closest?.('.vl-card-group-header'));
        },
        ignoreMutation() {
          return true;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'cardGroup') return false;
          node = updatedNode;
          applyLayout();
          return true;
        },
      };
    };
  },
});

export { CardGroup, defaultCardContent };
