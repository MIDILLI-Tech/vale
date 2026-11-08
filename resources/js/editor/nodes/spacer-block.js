/**
 * Vertical spacer block.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { openSpacerSettings } from '../settings/spacer-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

const SpacerBlock = Node.create({
  name: 'spacerBlock',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      size: {
        default: 50,
        parseHTML: el => parseInt(el.getAttribute('data-spacer-size'), 10) || 50,
      },
      unit: {
        default: 'px',
        parseHTML: el => el.getAttribute('data-spacer-unit') || 'px',
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-spacer]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-spacer-block',
      'data-spacer': '1',
      'data-spacer-size': node.attrs.size,
      'data-spacer-unit': node.attrs.unit,
      'contenteditable': 'false',
      'style': `height:${node.attrs.size}${node.attrs.unit}`,
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-spacer-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-spacer', '1');

      const header = document.createElement('div');
      header.className = 'vl-spacer-header';

      const label = document.createElement('span');
      label.className = 'vl-spacer-label';
      label.textContent = '↕ ' + tjs('Spacer');
      label.style.flex = '1';
      header.appendChild(label);

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Spacer Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openSpacerSettings(editor, node, getPos());
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });
      header.appendChild(del);

      const preview = document.createElement('div');
      preview.className = 'vl-spacer-preview';

      function renderPreview() {
        preview.style.height = node.attrs.size + node.attrs.unit;
      }

      renderPreview();
      dom.appendChild(header);
      dom.appendChild(preview);

      dom.addEventListener('dblclick', (e) => {
        e.preventDefault();
        openSpacerSettings(editor, node, getPos());
      });

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'spacerBlock') return false;
          node = updatedNode;
          renderPreview();
          return true;
        },
      };
    };
  },
});

export { SpacerBlock };
