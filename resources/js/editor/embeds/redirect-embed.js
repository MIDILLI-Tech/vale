/**
 * Timed redirect embed block.
 *
 * Stores [redirect url="..." seconds="N"]; the frontend navigates to the
 * URL after the configured delay once the page loads.
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { openRedirectSettings } from '../settings/redirect-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

function escHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function delayLabel(seconds) {
  const n = parseInt(seconds, 10);
  if (!Number.isFinite(n) || n <= 0) return tjs('Instant (302 Redirect)');
  return tjs('Redirect after :count s', { ':count': n });
}

const RedirectEmbed = Node.create({
  name: 'redirectEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      url: {
        default: '',
        parseHTML: el => el.getAttribute('data-url') || '',
      },
      seconds: {
        default: 5,
        parseHTML: el => {
          const n = parseInt(el.getAttribute('data-seconds') || '5', 10);
          return Number.isFinite(n) ? Math.max(0, Math.min(300, n)) : 5;
        },
        renderHTML: attrs => ({ 'data-seconds': String(attrs.seconds ?? 5) }),
      },
      internalType: {
        default: '',
        parseHTML: el => el.getAttribute('data-internal-type') || '',
        renderHTML: attrs => (attrs.internalType ? { 'data-internal-type': attrs.internalType } : {}),
      },
      internalId: {
        default: '',
        parseHTML: el => el.getAttribute('data-internal-id') || '',
        renderHTML: attrs => (attrs.internalId ? { 'data-internal-id': attrs.internalId } : {}),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-redirect]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    const attrs = {
      'class': 'vl-redirect-embed-block',
      'data-redirect': '1',
      'data-url': node.attrs.url || '',
      'contenteditable': 'false',
    };
    if (node.attrs.internalType) attrs['data-internal-type'] = node.attrs.internalType;
    if (node.attrs.internalId) attrs['data-internal-id'] = node.attrs.internalId;
    return ['div', mergeAttributes(HTMLAttributes, attrs)];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-product-embed-block vl-redirect-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-redirect', '1');

      const header = document.createElement('div');
      header.className = 'vl-product-header';
      header.innerHTML = `<span class="flex-1">↗ ${tjs('Redirect')}</span>`;

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Redirect Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openRedirectSettings(editor, node, getPos());
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.title = tjs('Remove redirect');
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });
      header.appendChild(del);

      const body = document.createElement('div');
      body.className = 'vl-product-body';
      body.style.padding = '10px 14px';

      const warn = document.createElement('p');
      warn.className = 'vl-redirect-warn';
      body.appendChild(warn);

      // When several redirect blocks share a page only the first one
      // navigates — warn on every one so the setup is never a surprise.
      function syncMultiWarning() {
        let count = 0;
        editor.state.doc.descendants(n => {
          if (n.type.name === 'redirectEmbed') count++;
        });
        if (count > 1) {
          warn.textContent = '⚠ ' + tjs('This page has :count redirect blocks — only the first one navigates.', { ':count': count });
          warn.style.display = '';
        } else {
          warn.style.display = 'none';
        }
      }

      function renderPreview() {
        const url = (node.attrs.url || '').trim();
        const card = document.createElement('div');
        card.className = 'vl-embed-preview-card';
        card.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 12px;';
        card.innerHTML = `
          <div class="vl-embed-mono-tag vl-redirect-url">${url ? escHtml(url) : tjs('No URL set — click the gear to configure.')}</div>
          <span class="vl-embed-sub-text">${escHtml(delayLabel(node.attrs.seconds))}</span>
        `;
        body.querySelectorAll('.vl-embed-preview-card').forEach(el => el.remove());
        body.appendChild(card);
      }

      renderPreview();
      syncMultiWarning();
      dom.appendChild(header);
      dom.appendChild(body);

      // Keep the warning in sync with doc changes (add/remove/duplicate).
      const onTransaction = () => syncMultiWarning();
      editor.on('transaction', onTransaction);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'redirectEmbed') return false;
          node = updatedNode;
          renderPreview();
          syncMultiWarning();
          return true;
        },
        destroy() {
          editor.off('transaction', onTransaction);
        },
      };
    };
  },
});

export { RedirectEmbed };
