/**
 * Latest Posts Feed visual embed block for email editor.
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { openLatestPostsSettings } from '../settings/latest-posts-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

const LatestPostsEmbed = Node.create({
  name: 'latestPostsEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      count: { default: '5' },
      contentType: { default: 'post' },
      category: { default: '' },
      tag: { default: '' },
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-latest-posts]',
      getAttrs(dom) {
        return {
          count: dom.getAttribute('data-count') || '5',
          contentType: dom.getAttribute('data-content-type') || 'post',
          category: dom.getAttribute('data-category') || '',
          tag: dom.getAttribute('data-tag') || '',
        };
      },
    }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-latest-posts-embed-block',
      'data-latest-posts': '1',
      'data-count': node.attrs.count || '5',
      'data-content-type': node.attrs.contentType || 'post',
      'data-category': node.attrs.category || '',
      'data-tag': node.attrs.tag || '',
      'contenteditable': 'false',
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-product-embed-block vl-latest-posts-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-latest-posts', '1');

      const header = document.createElement('div');
      header.className = 'vl-product-header';
      header.innerHTML = `<span class="flex-1">📰 ${tjs('Latest Posts Feed')}</span>`;

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Latest Posts Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openLatestPostsSettings(editor, node, getPos());
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

      const body = document.createElement('div');
      body.className = 'vl-product-body';
      body.style.padding = '12px 16px';

      function renderPreview() {
        body.innerHTML = '';
        const attrs = node.attrs;

        let meta = `${tjs('Count')}: <strong>${attrs.count}</strong> | ${tjs('Type')}: <strong>${attrs.contentType}</strong>`;
        if (attrs.category) meta += ` | ${tjs('Category')}: <strong>${attrs.category}</strong>`;
        if (attrs.tag) meta += ` | ${tjs('Tag')}: <strong>${attrs.tag}</strong>`;

        body.innerHTML = `
          <div class="vl-embed-preview-card">
            <div style="font-size:13px;margin-bottom:6px;">${meta}</div>
            <div class="vl-embed-sub-text" style="font-style:italic;">⚡ ${tjs('Latest posts with images and excerpts will be dynamically injected here when sending email.')}</div>
          </div>
        `;
      }

      renderPreview();
      dom.appendChild(header);
      dom.appendChild(body);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'latestPostsEmbed') return false;
          node = updatedNode;
          renderPreview();
          return true;
        },
      };
    };
  },
});

export { LatestPostsEmbed };
