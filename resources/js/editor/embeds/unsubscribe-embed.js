/**
 * Unsubscribe URL Tag visual embed block for email editor.
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { makeDuplicateButton } from '../ui/duplicate.js';

const UnsubscribeEmbed = Node.create({
  name: 'unsubscribeEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  parseHTML() {
    return [{ tag: 'div[data-unsubscribe-url]' }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-unsubscribe-embed-block',
      'data-unsubscribe-url': '1',
      'contenteditable': 'false',
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-product-embed-block vl-unsubscribe-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-unsubscribe-url', '1');

      const header = document.createElement('div');
      header.className = 'vl-product-header';
      header.innerHTML = `<span class="flex-1">🔕 ${tjs('Unsubscribe Link Tag')}</span>`;

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });
      header.appendChild(del);
      header.insertBefore(makeDuplicateButton(editor, getPos), del);

      const body = document.createElement('div');
      body.className = 'vl-product-body';
      body.style.padding = '10px 14px';
      body.innerHTML = `
        <div class="vl-embed-preview-card" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">
          <div class="vl-embed-mono-tag">[unsubscribe_url]</div>
          <span class="vl-embed-sub-text">${tjs('Subscriber unsubscribe URL dynamically injected on send')}</span>
        </div>
      `;

      dom.appendChild(header);
      dom.appendChild(body);

      return {
        dom,
        update(updatedNode) {
          return updatedNode.type.name === 'unsubscribeEmbed';
        },
      };
    };
  },
});

export { UnsubscribeEmbed };
