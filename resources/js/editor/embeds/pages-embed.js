/**
 * Pages embed block.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { VideoEmbed } from '../embeds/video-embed.js';
import { openPagePicker } from '../ui/page-picker.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

const PAGES_LEVEL_LABELS = {
    1: tjs('1 level'),
    2: tjs('2 levels'),
    3: tjs('3 levels (max)')
};

// Patch form.submit() so programmatic calls (Ctrl+S, preview) sync editors
// and reset dirty state via untrusted input events before the page unloads.
(function() {
  const origSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function() {
    this.querySelectorAll('textarea[data-editor]').forEach(function(ta) {
      ta.dispatchEvent(new Event('input'));
    });
    origSubmit.call(this);
  };
})();

const PagesEmbed = Node.create({
  name: 'pagesEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      maxLevel: {
        default: 1,
        parseHTML: el => parseInt(el.getAttribute('data-pages-max') || '1', 10),
        renderHTML: attrs => ({ 'data-pages-max': String(attrs.maxLevel) }),
      },
      pageParent: {
        default: '',
        parseHTML: el => el.getAttribute('data-pages-parent') || '',
        renderHTML: attrs => attrs.pageParent ? { 'data-pages-parent': attrs.pageParent } : {},
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-pages]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-pages-embed-block',
      'data-pages': '1',
      'contenteditable': 'false',
    }), `📄 ${tjs('Child Pages')} — ${PAGES_LEVEL_LABELS[node.attrs.maxLevel] || 'Direct children only'}` ];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-pages-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-pages', '1');

      const label = document.createElement('span');
      label.className = 'vl-pages-embed-label';
      label.textContent = `📄 ${tjs('Child Pages')}`;
      label.style.flex = '1';

      const sel = document.createElement('select');
      sel.className = 'vl-select text-xs py-1 px-2';
      sel.title = tjs('Select nesting depth');
      [1, 2, 3].forEach(lvl => {
        const opt = document.createElement('option');
        opt.value = String(lvl);
        opt.textContent = PAGES_LEVEL_LABELS[lvl];
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

      // Parent page picker — searchable overlay instead of giant select
      const parentDisplay = document.createElement('span');
      parentDisplay.className = 'vl-pages-parent-display';

      const parentBtn = document.createElement('button');
      parentBtn.type = 'button';
      parentBtn.className = 'vl-pages-parent-btn';
      parentBtn.title = 'Select parent page';

      function updateParentDisplay(slug) {
        slug = slug || node.attrs.pageParent;
        if (slug) {
          parentDisplay.textContent = slug;
          parentBtn.textContent = '✎';
        } else {
          parentDisplay.textContent = `(${tjs('Current page')})`;
          parentBtn.textContent = tjs('Select...');
        }
      }
      updateParentDisplay();

      parentBtn.addEventListener('mousedown', e => e.stopPropagation());
      parentBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openPagePicker(editor, node.attrs.pageParent, (slug) => {
          const pos = typeof getPos === 'function' ? getPos() : null;
          if (pos !== null) {
            editor.chain().focus()
              .setNodeSelection(pos)
              .command(({ tr }) => {
                tr.setNodeMarkup(pos, undefined, {
                  maxLevel: parseInt(sel.value, 10),
                  pageParent: slug || '',
                });
                return true;
              })
              .run();
          }
        });
      });

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.title = tjs('Remove child pages');
      del.style.marginLeft = 'auto';
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + 1 }).run();
      });

      const header = document.createElement('div');
      header.className = 'vl-embed-header-bar';
      header.appendChild(label);
      header.appendChild(sel);
      header.appendChild(parentDisplay);
      header.appendChild(parentBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));
      header.appendChild(del);
      dom.appendChild(header);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'pagesEmbed') return false;
          sel.value = String(updatedNode.attrs.maxLevel);
          updateParentDisplay(updatedNode.attrs.pageParent);
          return true;
        },
      };
    };
  },
});

// ── VideoEmbed custom node ─────────────────────────────────────────────────
// Visual block for <iframe> (YouTube/Vimeo) and <video> elements.
// The raw embed HTML is URL-encoded in a data-video-html attribute for storage.

export { PAGES_LEVEL_LABELS, PagesEmbed };
