/**
 * Generate Coupon visual embed block for email editor.
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { openCouponSettings } from '../settings/coupon-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

const GenerateCouponEmbed = Node.create({
  name: 'generateCouponEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      discountPercent: { default: '' },
      discountFixed: { default: '' },
      prefix: { default: 'SALE' },
      expiresDays: { default: '30' },
      maxUsesPerEmail: { default: '1' },
      startsAt: { default: '' },
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-generate-coupon]',
      getAttrs(dom) {
        return {
          discountPercent: dom.getAttribute('data-discount-percent') || '',
          discountFixed: dom.getAttribute('data-discount-fixed') || '',
          prefix: dom.getAttribute('data-prefix') || 'SALE',
          expiresDays: dom.getAttribute('data-expires-days') || '30',
          maxUsesPerEmail: dom.getAttribute('data-max-uses-per-email') || '1',
          startsAt: dom.getAttribute('data-starts-at') || '',
        };
      },
    }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-coupon-embed-block',
      'data-generate-coupon': '1',
      'data-discount-percent': node.attrs.discountPercent || '',
      'data-discount-fixed': node.attrs.discountFixed || '',
      'data-prefix': node.attrs.prefix || 'SALE',
      'data-expires-days': node.attrs.expiresDays || '30',
      'data-max-uses-per-email': node.attrs.maxUsesPerEmail || '1',
      'data-starts-at': node.attrs.startsAt || '',
      'contenteditable': 'false',
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-product-embed-block vl-coupon-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-generate-coupon', '1');

      const header = document.createElement('div');
      header.className = 'vl-product-header';
      header.innerHTML = `<span class="flex-1">🎟 ${tjs('Dynamic Coupon Generator')}</span>`;

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Coupon Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openCouponSettings(editor, node, getPos());
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
        const sym = window.CURRENCY_SYMBOL || '$';
        const discountText = attrs.discountPercent
          ? `${attrs.discountPercent}% OFF`
          : `${sym}${attrs.discountFixed || '0'} OFF`;

        const codePreview = `${attrs.prefix || 'CAMP'}XXXXXXXX`;

        body.innerHTML = `
          <div class="vl-coupon-preview-box">
            <div>
              <div class="vl-coupon-code-title">${codePreview}</div>
              <div class="vl-coupon-meta-text">
                ${tjs('Discount')}: <strong>${discountText}</strong> | ${tjs('Expires')}: <strong>${attrs.expiresDays || '30'} ${tjs('days')}</strong> | ${tjs('Max per email')}: <strong>${attrs.maxUsesPerEmail || '1'}</strong>
              </div>
            </div>
            <span class="vl-coupon-badge">${tjs('Generated on send')}</span>
          </div>
        `;
      }

      renderPreview();
      dom.appendChild(header);
      dom.appendChild(body);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'generateCouponEmbed') return false;
          node = updatedNode;
          renderPreview();
          return true;
        },
      };
    };
  },
});

export { GenerateCouponEmbed };
