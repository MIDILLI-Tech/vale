/**
 * "Read more" marker node.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { createBlockGrip } from '../utils.js';

const MoreNode = Node.create({
  name: 'more',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      text: {
        default: '',
        parseHTML: el => el.getAttribute('data-text') || '',
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-more]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-more-divider',
      'data-more': '1',
      'data-text': node.attrs.text || '',
      'contenteditable': 'false',
    }), '⊡ ' + tjs('MORE')];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-more-divider';
      dom.contentEditable = 'false';
      dom.setAttribute('data-more', '1');
      if (node.attrs.text) {
        dom.setAttribute('data-text', node.attrs.text);
      }

      const label = document.createElement('span');
      label.className = 'vl-more-label';
      label.textContent = '⊡ ' + tjs('MORE');

      const textInput = document.createElement('input');
      textInput.type = 'text';
      textInput.className = 'vl-more-text-input';
      textInput.placeholder = tjs('Read more text (optional)...');
      textInput.value = node.attrs.text || '';
      textInput.addEventListener('input', (e) => {
        const newText = e.target.value;
        if (newText) {
          dom.setAttribute('data-text', newText);
        } else {
          dom.removeAttribute('data-text');
        }
        if (typeof getPos === 'function') {
          const pos = getPos();
          if (pos !== undefined) {
            editor.commands.command(({ tr }) => {
              tr.setNodeMarkup(pos, undefined, { text: newText });
              return true;
            });
          }
        }
      });

      const bar = document.createElement('div');
      bar.className = 'vl-more-bar';

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.title = tjs('Remove more break');
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + 1 }).run();
      });

      dom.appendChild(createBlockGrip());
      dom.appendChild(label);
      dom.appendChild(textInput);
      dom.appendChild(bar);
      dom.appendChild(del);

      return {
        dom,
        stopEvent(event) {
          const t = event.target;
          return t === textInput || t === del;
        },
        ignoreMutation() {
          return true;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'more') return false;
          node = updatedNode;
          const text = updatedNode.attrs.text || '';
          if (textInput.value !== text) {
            textInput.value = text;
          }
          if (text) {
            dom.setAttribute('data-text', text);
          } else {
            dom.removeAttribute('data-text');
          }
          return true;
        },
      };
    };
  },
});

// Convert editor HTML to storage format: more divs → <!--more--> or <!--more text="..."-->

export { MoreNode };
