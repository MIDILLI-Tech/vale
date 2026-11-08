/**
 * Next-page marker node.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { createBlockGrip } from '../utils.js';

const NextPageNode = Node.create({
  name: 'nextpage',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      title: {
        default: '',
        parseHTML: el => el.getAttribute('data-title') || '',
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-nextpage]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-nextpage-divider',
      'data-nextpage': '1',
      'data-title': node.attrs.title || '',
      'contenteditable': 'false',
    }), '📄 ' + tjs('NEXT PAGE') + (node.attrs.title ? ': ' + node.attrs.title : '')];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-nextpage-divider';
      dom.contentEditable = 'false';
      dom.setAttribute('data-nextpage', '1');
      if (node.attrs.title) {
        dom.setAttribute('data-title', node.attrs.title);
      }

      const label = document.createElement('span');
      label.className = 'vl-nextpage-label';
      label.textContent = '📄 ' + tjs('NEXT PAGE');

      const titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.className = 'vl-nextpage-title-input';
      titleInput.placeholder = tjs('Page title (optional)...');
      titleInput.value = node.attrs.title || '';
      titleInput.addEventListener('input', (e) => {
        const newTitle = e.target.value;
        dom.setAttribute('data-title', newTitle);
        if (typeof getPos === 'function') {
          const pos = getPos();
          if (pos !== undefined) {
            editor.commands.command(({ tr }) => {
              tr.setNodeMarkup(pos, undefined, { title: newTitle });
              return true;
            });
          }
        }
      });

      const bar = document.createElement('div');
      bar.className = 'vl-nextpage-bar';

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.title = tjs('Remove page break');
      del.addEventListener('click', () => {
        if (typeof getPos === 'function') {
          const pos = getPos();
          editor.chain().focus().deleteRange({ from: pos, to: pos + 1 }).run();
        }
      });

      dom.appendChild(createBlockGrip());
      dom.appendChild(label);
      dom.appendChild(titleInput);
      dom.appendChild(bar);
      dom.appendChild(del);

      return {
        dom,
        stopEvent(event) {
          const t = event.target;
          return t === titleInput || t === del;
        },
        ignoreMutation() {
          return true;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'nextpage') return false;
          node = updatedNode;
          const title = updatedNode.attrs.title || '';
          if (titleInput.value !== title) {
            titleInput.value = title;
          }
          if (title) {
            dom.setAttribute('data-title', title);
          } else {
            dom.removeAttribute('data-title');
          }
          return true;
        },
      };
    };
  },
});

export { NextPageNode };
