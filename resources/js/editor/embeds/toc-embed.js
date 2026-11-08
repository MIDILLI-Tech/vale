/**
 * Table-of-contents embed block.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { makeDuplicateButton } from '../ui/duplicate.js';

const TOC_LEVEL_LABELS = {
    1: tjs('H1 only'),
    2: tjs('H1–H2'),
    3: tjs('H1–H3 (all)')
};

const TocEmbed = Node.create({
  name: 'tocEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      maxLevel: {
        default: 3,
        parseHTML: el => parseInt(el.getAttribute('data-toc-max') || '3', 10),
        renderHTML: attrs => ({ 'data-toc-max': String(attrs.maxLevel) }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-toc]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-toc-embed-block',
      'data-toc': '1',
      'contenteditable': 'false',
    }), `📑 ${tjs('Table of Contents')} — ${TOC_LEVEL_LABELS[node.attrs.maxLevel] || 'H1–H3'}` ];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-toc-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-toc', '1');

      const label = document.createElement('span');
      label.className = 'vl-toc-embed-label';
      label.textContent = `📑 ${tjs('Table of Contents')}`;
      label.style.flex = '1';

      // Level picker
      const sel = document.createElement('select');
      sel.className = 'vl-select text-xs py-1 px-2';
      sel.title = tjs('Select heading depth');
      [1, 2, 3].forEach(lvl => {
        const opt = document.createElement('option');
        opt.value = String(lvl);
        opt.textContent = TOC_LEVEL_LABELS[lvl];
        if (lvl === node.attrs.maxLevel) opt.selected = true;
        sel.appendChild(opt);
      });
      sel.addEventListener('mousedown', e => e.stopPropagation());
      sel.addEventListener('change', () => {
        const pos = typeof getPos === 'function' ? getPos() : null;
        if (pos !== null) {
          editor.chain().focus()
            .setNodeSelection(pos)
            .command(({ tr, state }) => {
              tr.setNodeMarkup(pos, undefined, { maxLevel: parseInt(sel.value, 10) });
              return true;
            })
            .run();
        }
      });

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.title = tjs('Remove table of contents');
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + 1 }).run();
      });

      const header = document.createElement('div');
      header.className = 'vl-embed-header-bar';
      header.appendChild(label);
      header.appendChild(sel);
      header.appendChild(makeDuplicateButton(editor, getPos));
      header.appendChild(del);
      dom.appendChild(header);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'tocEmbed') return false;
          sel.value = String(updatedNode.attrs.maxLevel);
          return true;
        },
      };
    };
  },
});

// ── Pages embed node ───────────────────────────────────────────────────────

export { TOC_LEVEL_LABELS, TocEmbed };
