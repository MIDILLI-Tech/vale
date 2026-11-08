/**
 * Product embed block.
 *
 * Id-only storage: the node keeps productId (+ optional variationId) and
 * display flags. Name, price, image and description are always fetched live
 * from the server, so a price change is visible in the editor without
 * re-picking the product.
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { openProductSettings } from '../settings/product-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';
import { fetchProduct, findVariation, getCachedProduct, isPubliclyVisible, variationLabel, variationPriceRange } from '../ui/product-data.js';
import { openProductPicker } from '../ui/product-picker.js';

// Shared per-page cache lives in product-data.js so the settings dialog
// can reuse it without a circular import.

function parseBoolAttr(dom, name) {
  const v = dom.getAttribute(name);
  if (v === null || v === '') return true;
  return v === 'true' || v === '1';
}

const ProductEmbed = Node.create({
  name: 'productEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      productId: { default: 0 },
      variationId: { default: 0 },
      showImage: { default: true },
      showPrice: { default: true },
      showDescription: { default: true },
      bg: { default: '' },
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-product]',
      getAttrs(dom) {
        return {
          productId: parseInt(dom.getAttribute('data-product-id') || '0', 10),
          variationId: parseInt(dom.getAttribute('data-variation-id') || '0', 10),
          showImage: parseBoolAttr(dom, 'data-show-image'),
          showPrice: parseBoolAttr(dom, 'data-show-price'),
          showDescription: parseBoolAttr(dom, 'data-show-desc'),
          bg: dom.getAttribute('data-product-bg') || '',
        };
      },
    }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-product-embed-block',
      'data-product': '1',
      'data-product-id': node.attrs.productId,
      'data-variation-id': node.attrs.variationId || '',
      'data-show-image': node.attrs.showImage ? 'true' : 'false',
      'data-show-price': node.attrs.showPrice ? 'true' : 'false',
      'data-show-desc': node.attrs.showDescription ? 'true' : 'false',
      'data-product-bg': node.attrs.bg || '',
      'contenteditable': 'false',
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-product-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-product', '1');

      const header = document.createElement('div');
      header.className = 'vl-product-header';
      header.innerHTML = `<span class="flex-1">🛒 ${tjs('Product')}</span>`;

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Product Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openProductSettings(editor, node, getPos());
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

      function renderEmpty() {
        body.innerHTML = '';
        const empty = document.createElement('div');
        empty.className = 'vl-product-empty';
        empty.textContent = tjs('No product selected. Click to pick.');
        empty.addEventListener('click', () => openProductPicker(editor, (product, variationId) => {
          const pos = getPos();
          editor.chain().focus().command(({ tr }) => {
            tr.setNodeMarkup(pos, undefined, {
              ...node.attrs,
              productId: product.id,
              variationId: variationId || 0,
            });
            return true;
          }).run();
        }));
        body.appendChild(empty);
      }

      function renderLoading() {
        body.innerHTML = '';
        const loading = document.createElement('div');
        loading.className = 'vl-product-empty';
        loading.innerHTML = `<span class="vl-icon-refresh vl-icon-spin"></span> ${tjs('Loading...')}`;
        body.appendChild(loading);
      }

      function renderError() {
        body.innerHTML = '';
        const err = document.createElement('div');
        err.className = 'vl-product-empty';
        err.textContent = tjs('Product not found. It may have been deleted.');
        body.appendChild(err);
      }

      function renderProduct(data) {
        body.innerHTML = '';
        const attrs = node.attrs;
        const sym = window.CURRENCY_SYMBOL || '$';
        const wrap = document.createElement('div');
        wrap.className = 'vl-product-preview';
        if (attrs.bg) {
          wrap.style.background = attrs.bg;
        }

        if (!isPubliclyVisible(data)) {
          const badge = document.createElement('div');
          badge.className = 'vl-product-preview-badge';
          badge.textContent = tjs('Not publicly visible');
          wrap.appendChild(badge);
        }

        const variation = findVariation(data, attrs.variationId);
        const displayName = variation ? `${data.name || ''} — ${variationLabel(variation)}` : (data.name || '');
        const imageUrl = (variation && variation.image_url) || data.featured_image_url || '';
        if (attrs.showImage && imageUrl) {
          const imgWrap = document.createElement('div');
          imgWrap.className = 'vl-product-preview-img';
          const loading = document.createElement('span');
          loading.className = 'vl-product-preview-loading';
          loading.innerHTML = `<span class="vl-icon-refresh vl-icon-spin"></span> ${tjs('Loading...')}`;
          imgWrap.appendChild(loading);
          const img = document.createElement('img');
          img.src = imageUrl;
          img.alt = data.name || '';
          img.style.display = 'none';
          img.addEventListener('load', () => {
            loading.remove();
            img.style.display = 'block';
          });
          img.addEventListener('error', () => {
            loading.textContent = '🖼';
            img.remove();
          });
          imgWrap.appendChild(img);
          wrap.appendChild(imgWrap);
        }

        const info = document.createElement('div');
        info.className = 'vl-product-preview-info';

        const nameEl = document.createElement('strong');
        nameEl.className = 'vl-product-preview-name';
        nameEl.textContent = displayName;
        info.appendChild(nameEl);

        if (attrs.showPrice) {
          const priceEl = document.createElement('span');
          priceEl.className = 'vl-product-preview-price';
          if (variation) {
            const eff = parseFloat(variation.effective_price ?? variation.price ?? 0);
            const orig = parseFloat(variation.price ?? 0);
            priceEl.textContent = sym + eff.toFixed(2);
            if (orig > eff) {
              const struck = document.createElement('s');
              struck.className = 'vl-product-preview-original';
              struck.textContent = sym + orig.toFixed(2);
              priceEl.appendChild(document.createTextNode(' '));
              priceEl.appendChild(struck);
            }
          } else if (Array.isArray(data.variations) && data.variations.length) {
            const range = variationPriceRange(data);
            priceEl.textContent = range && range.min !== range.max
              ? `${sym}${range.min.toFixed(2)} – ${sym}${range.max.toFixed(2)}`
              : sym + parseFloat(data.effective_price ?? data.price ?? 0).toFixed(2);
          } else {
            const eff = parseFloat(data.effective_price ?? data.price ?? 0);
            const orig = parseFloat(data.price ?? 0);
            priceEl.textContent = sym + eff.toFixed(2);
            if (data.on_sale && orig > eff) {
              const struck = document.createElement('s');
              struck.className = 'vl-product-preview-original';
              struck.textContent = sym + orig.toFixed(2);
              priceEl.appendChild(document.createTextNode(' '));
              priceEl.appendChild(struck);
            }
          }
          info.appendChild(priceEl);
        }

        if (attrs.showDescription && data.short_description) {
          const descEl = document.createElement('p');
          descEl.className = 'vl-product-preview-desc';
          descEl.textContent = data.short_description;
          info.appendChild(descEl);
        }

        wrap.appendChild(info);
        body.appendChild(wrap);
      }

      function renderPreview() {
        const attrs = node.attrs;
        if (!attrs.productId) {
          renderEmpty();
          return;
        }
        const cached = getCachedProduct(attrs.productId);
        if (cached && cached.state === 'loaded') {
          renderProduct(cached.data);
          return;
        }
        if (cached && cached.state === 'error') {
          renderError();
          return;
        }
        renderLoading();
        const requestedId = attrs.productId;
        const pos = getPos();
        fetchProduct(requestedId).then((data) => {
          let current = null;
          try {
            current = editor.state.doc.nodeAt(pos);
          } catch (e) {
            current = null;
          }
          if (!current || current.type.name !== 'productEmbed' || current.attrs.productId !== requestedId) return;
          if (data) {
            renderProduct(data);
          } else {
            renderError();
          }
        });
      }

      renderPreview();

      dom.appendChild(header);
      dom.appendChild(body);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'productEmbed') return false;
          node = updatedNode;
          renderPreview();
          return true;
        },
      };
    };
  },
});

export { ProductEmbed };
