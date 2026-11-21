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

const TOC_ONLY_LEVEL_LABELS = {
    2: tjs('H2 only'),
    3: tjs('H3 only')
};

const tocDepthLabel = attrs => attrs.onlyLevel
  ? (TOC_ONLY_LEVEL_LABELS[attrs.onlyLevel] || 'H2 only')
  : (TOC_LEVEL_LABELS[attrs.maxLevel] || 'H1–H3');

const tocDepthValue = attrs => attrs.onlyLevel
  ? `only-${attrs.onlyLevel}`
  : `range-${attrs.maxLevel}`;

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
      onlyLevel: {
        default: 0,
        parseHTML: el => parseInt(el.getAttribute('data-toc-level') || '0', 10),
        renderHTML: attrs => (attrs.onlyLevel ? { 'data-toc-level': String(attrs.onlyLevel) } : {}),
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
    }), `📑 ${tjs('Table of Contents')} — ${tocDepthLabel(node.attrs)}` ];
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
      [
        ['range-1', TOC_LEVEL_LABELS[1]],
        ['range-2', TOC_LEVEL_LABELS[2]],
        ['range-3', TOC_LEVEL_LABELS[3]],
        ['only-2', TOC_ONLY_LEVEL_LABELS[2]],
        ['only-3', TOC_ONLY_LEVEL_LABELS[3]],
      ].forEach(([value, text]) => {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = text;
        if (value === tocDepthValue(node.attrs)) opt.selected = true;
        sel.appendChild(opt);
      });
      sel.addEventListener('mousedown', e => e.stopPropagation());
      sel.addEventListener('change', () => {
        const pos = typeof getPos === 'function' ? getPos() : null;
        if (pos !== null) {
          const [mode, level] = sel.value.split('-');
          const attrs = mode === 'only'
            ? { maxLevel: 3, onlyLevel: parseInt(level, 10) }
            : { maxLevel: parseInt(level, 10), onlyLevel: 0 };

          editor.chain().focus()
            .setNodeSelection(pos)
            .command(({ tr }) => {
              tr.setNodeMarkup(pos, undefined, attrs);
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
          sel.value = tocDepthValue(updatedNode.attrs);
          return true;
        },
      };
    };
  },
});

// ── Pages embed node ───────────────────────────────────────────────────────

export { TOC_LEVEL_LABELS, TOC_ONLY_LEVEL_LABELS, TocEmbed };
