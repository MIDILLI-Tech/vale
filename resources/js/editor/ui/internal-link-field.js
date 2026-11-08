/**
 * Shared internal-link field: External URL / Internal Content tabs.
 *
 * The internal panel searches posts, pages, products, active custom content
 * types and the static site links (homepage, shop, cart, checkout,
 * membership) via /admin/posts/list-json. It first serves results from a
 * module-level cache (shared by every instance, survives dialog close) and
 * falls back to a server request using VL_CLIENT_SEARCH_FETCH_LIMIT.
 *
 * Extracted from settings/link-button-settings.js so the link buttons, the
 * redirect block settings (and any future consumer) share one implementation.
 */
import { bp, getCsrfToken } from '../utils.js';

const _internalLinkCache = new Map();

function internalLinkField({ label = tjs('Link'), value = '', onChange = null } = {}) {
  const currentHref = value || '';

  const wrap = document.createElement('div');

  const lbl = document.createElement('label');
  lbl.className = 'vl-label';
  lbl.textContent = label;
  wrap.appendChild(lbl);

  const tabs = document.createElement('div');
  tabs.className = 'flex gap-1 mb-2 border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden';
  tabs.innerHTML = `
    <button type="button" data-tab="external" class="vl-editor-modal-tab">${tjs('External URL')}</button>
    <button type="button" data-tab="internal" class="vl-editor-modal-tab">${tjs('Internal Content')}</button>`;
  wrap.appendChild(tabs);

  const extPanel = document.createElement('div');
  extPanel.setAttribute('data-panel', 'external');
  const extInput = document.createElement('input');
  extInput.type = 'url';
  extInput.className = 'vl-input w-full';
  extInput.placeholder = 'https://example.com';
  extInput.value = currentHref;
  const emitExternal = () => onChange && onChange(extInput.value.trim(), null);
  extInput.addEventListener('input', emitExternal);
  extPanel.appendChild(extInput);
  wrap.appendChild(extPanel);

  const intPanel = document.createElement('div');
  intPanel.setAttribute('data-panel', 'internal');
  const searchRow = document.createElement('div');
  searchRow.className = 'flex gap-1 mb-2';
  const searchInput = document.createElement('input');
  searchInput.type = 'search';
  searchInput.className = 'vl-input flex-1';
  searchInput.placeholder = tjs('Type to search…');
  const refreshBtn = document.createElement('button');
  refreshBtn.type = 'button';
  refreshBtn.className = 'vl-btn-icon';
  refreshBtn.title = tjs('Refresh');
  refreshBtn.innerHTML = '<span class="vl-icon-refresh"></span>';
  const refreshIcon = refreshBtn.querySelector('.vl-icon-refresh');
  searchRow.appendChild(searchInput);
  searchRow.appendChild(refreshBtn);
  intPanel.appendChild(searchRow);
  const results = document.createElement('div');
  results.className = 'vl-link-results vl-editor-modal-results';
  intPanel.appendChild(results);
  wrap.appendChild(intPanel);

  // Relative path (/blog/xyz or /#anchor) → internal; explicit scheme / protocol-relative → external.
  const isInternalHref = (currentHref.startsWith('/') && !currentHref.startsWith('//')) || currentHref.startsWith('#');
  let activeTab = isInternalHref ? 'internal' : 'external';
  let selectedInternalUrl = isInternalHref ? currentHref : '';

  // Apply the resolved tab on open (not just on click).
  tabs.querySelectorAll('[data-tab]').forEach(t => t.classList.toggle('is-active', t.dataset.tab === activeTab));
  [extPanel, intPanel].forEach(p => { p.style.display = p.dataset.panel === activeTab ? '' : 'none'; });

  tabs.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-tab]');
    if (!tab) return;
    activeTab = tab.dataset.tab;
    tabs.querySelectorAll('[data-tab]').forEach(t => t.classList.toggle('is-active', t.dataset.tab === activeTab));
    [extPanel, intPanel].forEach(p => { p.style.display = p.dataset.panel === activeTab ? '' : 'none'; });
    if (activeTab === 'internal') {
      searchInput.focus();
      // First visit with an empty list — fetch so the user sees
      // progress instead of a blank panel.
      if (!cachedItems.length) loadInternal(searchInput.value.trim());
    }
  });

  let cachedItems = [];
  let searchT = null;
  let internalPage = 1;
  const PER_PAGE = 20;

  function renderPageItems(items) {
    results.innerHTML = '';
    if (!items.length) { results.innerHTML = `<p class="p-2.5 text-xs text-gray-400">${tjs('No results.')}</p>`; return; }
    items.forEach(item => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'vl-editor-modal-item vl-link-pick-row border-0! rounded-none! border-b border-gray-100 dark:border-gray-800';
      row.style.cssText = 'display:flex;align-items:center;gap:8px;text-align:left;width:100%;';
      const isMatch = selectedInternalUrl && item.url === selectedInternalUrl;
      if (isMatch) {
        row.classList.add('is-selected');
      }
      row.innerHTML = `<span class="vl-link-check">✓</span><span class="text-[10px] bg-gray-200 dark:bg-gray-700 rounded px-1.5 py-0.5 text-gray-600 dark:text-gray-300 uppercase shrink-0">${item.type}</span><span>${item.title}</span>`;
      row.addEventListener('click', () => {
        selectedInternalUrl = item.url;
        extInput.value = item.url;
        tabs.querySelectorAll('[data-tab]').forEach(t => t.classList.toggle('is-active', t.dataset.tab === 'external'));
        [extPanel, intPanel].forEach(p => { p.style.display = p.dataset.panel === 'external' ? '' : 'none'; });
        activeTab = 'external';
        onChange && onChange(item.url, item);
        results.querySelectorAll('button').forEach(b => b.classList.remove('is-selected'));
        row.classList.add('is-selected');
      });
      results.appendChild(row);
    });
  }

  function renderPagination() {
    const old = results.parentNode.querySelector('.vl-internal-pager');
    if (old) old.remove();
    const totalItems = cachedItems.length;
    const totalPages = Math.ceil(totalItems / PER_PAGE) || 1;
    if (totalItems <= PER_PAGE && totalItems > 0) return;
    const pager = document.createElement('div');
    pager.className = 'vl-internal-pager';
    pager.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:6px 2px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb';
    const info = document.createElement('span');
    info.textContent = totalItems > 0 ? `Page ${internalPage} of ${totalPages} (${totalItems})` : '';
    const btnWrap = document.createElement('div');
    btnWrap.style.cssText = 'display:flex;gap:4px';
    const prev = document.createElement('button');
    prev.type = 'button'; prev.textContent = '←'; prev.title = 'Previous page';
    prev.className = 'vl-btn vl-btn-xs vl-btn-secondary';
    prev.style.padding = '2px 8px'; prev.style.fontSize = '12px';
    prev.disabled = internalPage <= 1;
    prev.addEventListener('click', () => { if (internalPage > 1) { internalPage--; applyPagination(); } });
    const next = document.createElement('button');
    next.type = 'button'; next.textContent = '→'; next.title = 'Next page';
    next.className = 'vl-btn vl-btn-xs vl-btn-secondary';
    next.style.padding = '2px 8px'; next.style.fontSize = '12px';
    next.disabled = internalPage >= totalPages;
    next.addEventListener('click', () => { if (internalPage < totalPages) { internalPage++; applyPagination(); } });
    btnWrap.appendChild(prev);
    btnWrap.appendChild(next);
    pager.appendChild(info);
    pager.appendChild(btnWrap);
    results.parentNode.insertBefore(pager, results.nextSibling);
  }

  function applyPagination() {
    const start = (internalPage - 1) * PER_PAGE;
    renderPageItems(cachedItems.slice(start, start + PER_PAGE));
    renderPagination();
  }

  function pageOfSelected() {
    if (!selectedInternalUrl) return 1;
    const idx = cachedItems.findIndex(item => item.url === selectedInternalUrl);
    return idx >= 0 ? Math.floor(idx / PER_PAGE) + 1 : 1;
  }

  function clientFilter(items, q) {
    if (!q) return items;
    const lq = q.toLowerCase();
    return items.filter(item =>
      item.title.toLowerCase().includes(lq) ||
      item.type.toLowerCase().includes(lq)
    );
  }

  function loadInternal(q = '') {
    if (cachedItems.length > 0) {
      const filtered = clientFilter(cachedItems, q);
      if (filtered.length > 0 || !q) {
        cachedItems = filtered;
        internalPage = pageOfSelected();
        applyPagination();
        return Promise.resolve();
      }
    }
    const url = bp('/admin/posts/list-json?per_page=' + (window.VL_CLIENT_SEARCH_FETCH_LIMIT || 200) + (q ? '&q=' + encodeURIComponent(q) : ''));
    if (_internalLinkCache.has(url)) {
      const data = _internalLinkCache.get(url);
      cachedItems = Array.isArray(data) ? data : (data.items || []);
      internalPage = pageOfSelected();
      applyPagination();
      return Promise.resolve();
    }
    refreshIcon.classList.add('vl-icon-spin');
    results.innerHTML = `<div class="flex items-center justify-center gap-2 p-5 text-gray-500 dark:text-gray-400"><span class="vl-icon-refresh vl-icon-spin w-5 h-5"></span> ${tjs('Loading...')}</div>`;
    return fetch(url, { headers: { 'X-CSRF-Token': getCsrfToken() } })
      .then(r => r.json())
      .then(data => {
        const items = Array.isArray(data) ? data : (data.items || []);
        _internalLinkCache.set(url, items);
        cachedItems = items;
        internalPage = pageOfSelected();
        applyPagination();
      })
      .catch(() => { results.innerHTML = `<p class="p-2.5 text-xs text-red-500">${tjs('Error loading pages.')}</p>`; })
      .finally(() => refreshIcon.classList.remove('vl-icon-spin'));
  }

  searchInput.addEventListener('input', () => {
    clearTimeout(searchT);
    const q = searchInput.value;
    if (cachedItems.length > 0) {
      const filtered = clientFilter(cachedItems, q);
      if (filtered.length > 0 || !q) {
        cachedItems = filtered;
        internalPage = pageOfSelected();
        applyPagination();
        return;
      }
    }
    searchT = setTimeout(() => loadInternal(q), 250);
  });
  refreshBtn.addEventListener('click', () => { _internalLinkCache.clear(); cachedItems = []; loadInternal(searchInput.value); });
  if (isInternalHref) loadInternal();

  return wrap;
}

export { _internalLinkCache, internalLinkField };
