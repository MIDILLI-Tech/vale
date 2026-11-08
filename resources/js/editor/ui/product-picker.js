/**
 * Product picker dialog.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { ProductEmbed } from '../embeds/product-embed.js';
import { bp, getCsrfToken } from '../utils.js';

function openProductPicker(editor, onSelect) {
  // When called without callback (slash/menu), insert a ProductEmbed first
  if (typeof onSelect !== 'function') {
    const insertedPos = editor.state.selection.from;
    editor.commands.insertContent({ type: 'productEmbed' });
    onSelect = (product, variationId = 0) => {
      const { doc } = editor.state;
      let foundPos = -1;
      doc.descendants((node, pos) => {
        if (node.type.name === 'productEmbed' && node.attrs.productId === 0 && foundPos < 0) {
          foundPos = pos;
        }
      });
      const pos = foundPos >= 0 ? foundPos : insertedPos;
      editor.chain().focus().command(({ tr }) => {
        tr.setNodeMarkup(pos, undefined, {
          productId: product.id,
          variationId: variationId,
          showImage: true,
          showPrice: true,
          showDescription: true,
        });
        return true;
      }).run();
    };
  }

  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';
  const closeModal = () => { overlay.remove(); document.removeEventListener('keydown', escHandler); };
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) closeModal(); });
  document.addEventListener('keydown', function escHandler(e) { if (e.key === 'Escape') { closeModal(); } });

  const modal = document.createElement('div');
  modal.className = 'vl-modal vl-modal-lg';

  const header = document.createElement('div');
  header.className = 'vl-modal-header';
  header.style.flexWrap = 'wrap';
  header.style.gap = '8px';
  header.innerHTML = `
    <h3 class="vl-modal-title">${tjs('Select Product')}</h3>
    <div class="flex-1"></div>
    <div style="display:flex;align-items:center;gap:6px;">
      <input type="number" class="vl-input vl-id-input" placeholder="ID..." style="width:70px;padding:4px 8px;font-size:13px;" title="${tjs('Direct Product ID')}">
      <input type="search" class="vl-search" placeholder="${tjs('Search products...')}">
    </div>
    <button type="button" class="vl-btn-icon" title="${tjs('Refresh')}"><span class="vl-icon-refresh"></span></button>
    <button type="button" class="vl-btn-icon">✕</button>`;

  const [refreshBtn, closeBtn] = header.querySelectorAll('button');
  const refreshIcon = refreshBtn.querySelector('.vl-icon-refresh');
  closeBtn.addEventListener('click', closeModal);

  const directIdInput = header.querySelector('.vl-id-input');
  directIdInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const pid = parseInt(directIdInput.value || '0', 10);
      if (pid > 0) {
        searchQuery = 'ids:' + pid;
        currentPage = 1;
        loadPage();
      }
    }
  });

  const results = document.createElement('div');
  results.className = 'vl-product-results';

  const footer = document.createElement('div');
  footer.className = 'vl-modal-footer';

  const applyBtn = document.createElement('button');
  applyBtn.type = 'button';
  applyBtn.className = 'vl-btn vl-btn-primary';
  applyBtn.textContent = tjs('Insert Product');
  applyBtn.disabled = true;

  modal.appendChild(header);
  modal.appendChild(results);
  modal.appendChild(footer);
  footer.appendChild(applyBtn);
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  let selectedProduct = null;
  let selectedVariationId = 0;
  let currentPage = 1;
  let totalPages = 1;
  let totalItems = 0;
  let searchQuery = '';
  let productsCache = null;

  function renderProducts(data) {
    results.innerHTML = '';
    const items = data.data || [];
    totalItems = data.total || 0;
    totalPages = Math.ceil(totalItems / 50) || 1;

    if (!items.length) {
      results.innerHTML = `<p class="vl-empty">${tjs('No products found.')}</p>`;
      return;
    }

    items.forEach(item => {
      const card = document.createElement('div');
      card.className = 'vl-product-card' + (selectedProduct && selectedProduct.id === item.id ? ' is-selected' : '');
      card.setAttribute('data-id', item.id);

      const imgHtml = item.featured_image_url
        ? `<div class="vl-product-card-img"><img src="${item.featured_image_url}" alt="${item.name}"></div>`
        : `<div class="vl-product-card-img vl-product-card-img-placeholder"><span>🛒</span></div>`;

      const sym = window.CURRENCY_SYMBOL || '$';
      const priceHtml = item.price ? `<span class="vl-product-card-price">${sym}${parseFloat(item.price).toFixed(2)}</span>` : '';

      const descHtml = item.short_description
        ? `<p class="vl-product-card-desc">${item.short_description}</p>`
        : '';

      card.innerHTML = `${imgHtml}<div class="vl-product-card-info"><strong class="vl-product-card-name">${item.name}</strong>${priceHtml}${descHtml}</div><div class="vl-product-card-check">✓</div>`;
      card.addEventListener('click', () => {
        results.querySelectorAll('.vl-product-card').forEach(c => c.classList.remove('is-selected'));
        card.classList.add('is-selected');
        selectedProduct = item;
        selectedVariationId = 0;
        applyBtn.disabled = false;
        loadVariations(item);
      });
      results.appendChild(card);
    });
  }

  const variationBar = document.createElement('div');
  variationBar.className = 'vl-product-variation-bar';
  variationBar.style.display = 'none';
  variationBar.style.flexWrap = 'wrap';
  variationBar.style.gap = '8px';
  variationBar.style.alignItems = 'center';
  variationBar.style.marginTop = '12px';
  variationBar.style.padding = '8px 12px';
  variationBar.style.background = 'var(--vl-bg-surface, #f9fafb)';
  variationBar.style.borderRadius = '6px';
  variationBar.style.border = '1px solid var(--vl-border-color, #e5e7eb)';
  modal.insertBefore(variationBar, results);

  function loadVariations(product) {
    if (!product || !product.id) {
      variationBar.style.display = 'none';
      return;
    }
    fetch(bp(`/admin/products/${product.id}/json`), { headers: { 'X-CSRF-Token': getCsrfToken() } })
      .then(r => r.json())
      .then(data => {
        const variations = Array.isArray(data.variations) ? data.variations : [];
        if (!variations.length) {
          variationBar.style.display = 'none';
          return;
        }
        variationBar.style.display = 'flex';
        variationBar.innerHTML = '';
        const label = document.createElement('label');
        label.textContent = tjs('Variation');
        label.style.fontSize = '13px';
        label.style.color = '#6b7280';
        label.style.marginRight = '8px';
        label.style.whiteSpace = 'nowrap';
        variationBar.appendChild(label);
        const select = document.createElement('select');
        select.className = 'vl-input';
        select.style.minWidth = '220px';
        select.style.fontSize = '13px';
        const noneOpt = document.createElement('option');
        noneOpt.value = '0';
        noneOpt.textContent = tjs('All variations (price range)');
        select.appendChild(noneOpt);
        variations.forEach(v => {
          const opt = document.createElement('option');
          opt.value = v.id;
          const labelText = Object.values(v.attributes || {}).filter(Boolean).join(' / ') || v.sku || `#${v.id}`;
          const price = parseFloat(v.effective_price ?? v.price ?? 0);
          opt.textContent = `${labelText} — ${price.toFixed(2)}`;
          select.appendChild(opt);
        });
        select.value = String(selectedVariationId || '0');
        select.addEventListener('change', () => {
          selectedVariationId = parseInt(select.value, 10) || 0;
        });
        variationBar.appendChild(select);
      })
      .catch(() => { variationBar.style.display = 'none'; });
  }

  function renderPager() {
    const oldPager = footer.querySelector('.vl-product-pager');
    if (oldPager) oldPager.remove();

    if (totalItems <= 50) return;

    const pager = document.createElement('div');
    pager.className = 'vl-product-pager flex gap-2';
    const prev = document.createElement('button');
    prev.textContent = '←'; prev.disabled = currentPage <= 1;
    prev.className = 'vl-btn vl-btn-sm vl-btn-secondary';
    prev.addEventListener('click', () => { currentPage--; loadPage(); });
    const next = document.createElement('button');
    next.textContent = '→'; next.disabled = currentPage >= totalPages;
    next.className = 'vl-btn vl-btn-sm vl-btn-secondary';
    next.addEventListener('click', () => { currentPage++; loadPage(); });
    pager.appendChild(prev);
    pager.appendChild(next);
    footer.insertBefore(pager, applyBtn);
  }

  function loadPage() {
    const q = searchQuery ? '&q=' + encodeURIComponent(searchQuery) : '';
    const url = bp(`/admin/products/list-json?per_page=50&page=${currentPage}${q}`);
    results.innerHTML = `<div class="vl-picker-status flex items-center justify-center gap-2 p-5 text-gray-500 dark:text-gray-400"><span class="vl-icon-refresh vl-icon-spin w-5 h-5"></span> ${tjs('Loading...')}</div>`;
    if (refreshIcon) refreshIcon.classList.add('vl-icon-spin');
    fetch(url, { headers: { 'X-CSRF-Token': getCsrfToken() } })
      .then(r => r.json())
      .then(data => {
        renderProducts(data);
        renderPager();
      })
      .catch(() => {
        results.innerHTML = `<p class="p-5 text-center text-xs text-red-600 dark:text-red-400">${tjs('Error loading products.')}</p>`;
      })
      .finally(() => { if (refreshIcon) refreshIcon.classList.remove('vl-icon-spin'); });
  }

  const searchInput = header.querySelector('.vl-search');
  let searchT = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchT);
    searchT = setTimeout(() => {
      searchQuery = searchInput.value;
      currentPage = 1;
      loadPage();
    }, 250);
  });

  refreshBtn.addEventListener('click', () => {
    currentPage = 1;
    searchQuery = searchInput.value;
    loadPage();
  });

  applyBtn.addEventListener('click', () => {
    if (selectedProduct) {
      onSelect(selectedProduct, selectedVariationId || 0);
      closeModal();
    }
  });

  loadPage();
}

// ── Link Button custom node ──────────────────────────────────────────────────

export { openProductPicker };
