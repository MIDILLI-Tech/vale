/**
 * Form embed block.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { makeDuplicateButton } from '../ui/duplicate.js';

const FormEmbed = Node.create({
  name: 'formEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      slug: {
        default: null,
        parseHTML: el => el.getAttribute('data-form-slug'),
        renderHTML: attrs => ({ 'data-form-slug': attrs.slug }),
      },
      formName: {
        default: '',
        parseHTML: el => el.getAttribute('data-form-name'),
        renderHTML: attrs => ({ 'data-form-name': attrs.formName }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-form-slug]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-form-embed-block',
      'contenteditable': 'false',
    }), `📋 ${tjs('Form')}: ${node.attrs.formName || node.attrs.slug}`];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-form-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-form-slug', node.attrs.slug);

      const label = document.createElement('span');
      label.className = 'vl-form-embed-label';
      label.textContent = `📋 ${tjs('Form')}: ${node.attrs.formName || node.attrs.slug}`;

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.title = tjs('Remove form');
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });

      const header = document.createElement('div');
      header.className = 'vl-embed-header-bar';
      header.appendChild(label);
      header.appendChild(makeDuplicateButton(editor, getPos));
      header.appendChild(del);
      dom.appendChild(header);

      return { dom };
    };
  },
});

// ── Table of Contents embed node ────────────────────────────────────────────

export { FormEmbed };
