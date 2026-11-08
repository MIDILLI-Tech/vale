/**
 * Page picker dialog.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { bp, getCsrfToken } from '../utils.js';

function openPagePicker(editor, currentSlug, onSelect, title = tjs('Select page')) {
  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;';
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) overlay.remove(); });
  document.addEventListener('keydown', function escHandler(e) { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); } });
  const modal = document.createElement('div');
  modal.className = 'vl-modal vl-modal-sm p-5';
  modal.innerHTML = `
    <h3 class="vl-editor-modal-title mb-3">${title}</h3>
    <input type="search" placeholder="${tjs('Search...')}" autofocus class="vl-input w-full mb-2">
    <div class="vl-editor-modal-results max-h-56"></div>
    <div class="vl-modal-actions-right mt-3">
      <button type="button" class="vl-btn vl-btn-secondary vl-picker-cancel">${tjs('Cancel')}</button>
      <button type="button" class="vl-btn vl-btn-secondary vl-picker-clear">${tjs('Current page')}</button>
    </div>`;

  const searchInput = modal.querySelector('input');
  const results = modal.querySelector('.vl-editor-modal-results');
  let cachedPages = [];
  let pickerPage = 1;
  const PICKER_PER_PAGE = 20;

  function renderPickerItems(items) {
    results.innerHTML = '';
    if (!items.length) { results.innerHTML = `<p class="p-2.5 text-xs text-gray-400 text-center">${tjs('No pages found.')}</p>`; return; }
    items.forEach(item => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'vl-editor-modal-item border-0! rounded-none! border-b border-gray-100 dark:border-gray-800 text-left text-xs';
      row.textContent = item.title;

      const isSelected = item.slug === currentSlug;
      if (isSelected) {
        row.classList.add('is-selected');
        row.style.backgroundColor = 'rgba(99, 102, 241, 0.2)';
      }

      row.addEventListener('click', () => { onSelect(item.slug); overlay.remove(); });
      results.appendChild(row);
    });
  }

  function renderPickerPager() {
    const old = results.parentNode.querySelector('.vl-picker-pager');
    if (old) old.remove();
    const totalItems = cachedPages.length;
    const totalPages = Math.ceil(totalItems / PICKER_PER_PAGE) || 1;
    if (totalItems <= PICKER_PER_PAGE && totalItems > 0) return;
    const pager = document.createElement('div');
    pager.className = 'vl-picker-pager';
    pager.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:6px 2px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb';
    const info = document.createElement('span');
    info.textContent = totalItems > 0 ? `Page ${pickerPage} of ${totalPages}` : '';
    const btnWrap = document.createElement('div');
    btnWrap.style.cssText = 'display:flex;gap:4px';
    const prev = document.createElement('button');
    prev.type = 'button'; prev.textContent = '←'; prev.title = 'Previous page';
    prev.className = 'vl-btn vl-btn-xs vl-btn-secondary';
    prev.style.padding = '2px 8px'; prev.style.fontSize = '12px';
    prev.disabled = pickerPage <= 1;
    prev.addEventListener('click', () => { if (pickerPage > 1) { pickerPage--; applyPickerPagination(); } });
    const next = document.createElement('button');
    next.type = 'button'; next.textContent = '→'; next.title = 'Next page';
    next.className = 'vl-btn vl-btn-xs vl-btn-secondary';
    next.style.padding = '2px 8px'; next.style.fontSize = '12px';
    next.disabled = pickerPage >= totalPages;
    next.addEventListener('click', () => { if (pickerPage < totalPages) { pickerPage++; applyPickerPagination(); } });
    btnWrap.appendChild(prev);
    btnWrap.appendChild(next);
    pager.appendChild(info);
    pager.appendChild(btnWrap);
    results.parentNode.insertBefore(pager, results.nextSibling);
  }

  function applyPickerPagination() {
    const start = (pickerPage - 1) * PICKER_PER_PAGE;
    const pageItems = cachedPages.slice(start, start + PICKER_PER_PAGE);
    renderPickerItems(pageItems);
    renderPickerPager();
  }

  function clientFilterPages(items, q) {
    if (!q) return items;
    const lq = q.toLowerCase();
    return items.filter(item => item.title && item.title.toLowerCase().includes(lq));
  }

  function loadPages(q) {
    // Try client-side filter first
    if (cachedPages.length > 0) {
      const filtered = clientFilterPages(cachedPages, q);
      if (filtered.length > 0 || !q) {
        cachedPages = filtered;
        pickerPage = 1;
        applyPickerPagination();
        return;
      }
    }
    // Fall back to server
    results.innerHTML = `<p class="p-2.5 text-xs text-gray-400 text-center">${tjs('Searching…')}</p>`;
    const url = bp('/admin/posts/list-json?type=page&per_page=' + (window.VL_CLIENT_SEARCH_FETCH_LIMIT || 200) + (q ? '&q=' + encodeURIComponent(q) : ''));
    fetch(url, { headers: { 'X-CSRF-Token': getCsrfToken() } })
      .then(r => r.json())
      .then(data => {
        const items = Array.isArray(data) ? data : (data.items || []);
        cachedPages = items;
        pickerPage = 1;
        applyPickerPagination();
      })
      .catch(() => { results.innerHTML = `<p class="p-2.5 text-xs text-red-500 text-center">${tjs('Error loading pages.')}</p>`; });
  }

  loadPages('');

  let searchTimer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = searchInput.value;
    if (cachedPages.length > 0) {
      const filtered = clientFilterPages(cachedPages, q);
      if (filtered.length > 0 || !q) {
        cachedPages = filtered;
        pickerPage = 1;
        applyPickerPagination();
        return;
      }
    }
    searchTimer = setTimeout(() => loadPages(q), 200);
  });

  modal.querySelector('.vl-picker-cancel').addEventListener('click', () => overlay.remove());
  modal.querySelector('.vl-picker-clear').addEventListener('click', () => { onSelect(''); overlay.remove(); });

  overlay.appendChild(modal);
  document.body.appendChild(overlay);
  setTimeout(() => searchInput.focus(), 50);
}

export { openPagePicker };
