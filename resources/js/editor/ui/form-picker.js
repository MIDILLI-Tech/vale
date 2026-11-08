/**
 * Form picker dialog.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { bp, getCsrfToken } from '../utils.js';

const _formCache = new Map();

function openFormPicker(editor) {
  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';
  const closeModal = () => overlay.remove();
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) closeModal(); });
  document.addEventListener('keydown', function escHandler(e) {
    if (e.key === 'Escape') { closeModal(); document.removeEventListener('keydown', escHandler); }
  });

  const modal = document.createElement('div');
  modal.className = 'vl-modal';
  modal.style.maxHeight = '60vh';

  const header = document.createElement('div');
  header.className = 'vl-editor-modal-header';
  header.innerHTML = `<h3 class="vl-modal-title">${tjs('Insert Form')}</h3><div class="flex gap-1"><button type="button" class="vl-btn-icon" title="${tjs('Refresh')}"><span class="vl-icon-refresh"></span></button><button type="button" class="vl-btn-icon text-lg">✕</button></div>`;

  const closeBtn = header.querySelector('button:last-child');
  const refreshBtn = header.querySelector('.vl-btn-icon');
  const refreshIcon = refreshBtn.querySelector('.vl-icon-refresh');
  closeBtn.addEventListener('click', () => overlay.remove());

  const list = document.createElement('div');
  list.className = 'vl-editor-modal-list';

  function renderForms(forms) {
    list.innerHTML = '';
    if (!forms.length) {
      list.innerHTML = `<p class="vl-empty">${tjs('No forms yet.')} <a href="${bp('/admin/forms/create')}" class="text-emerald-600 dark:text-emerald-400">${tjs('Create one')}</a></p>`;
      return;
    }
    forms.forEach(f => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'vl-editor-modal-item';
      row.innerHTML = `<span class="text-xl">📋</span><span><strong class="text-sm block">${f.name}</strong><small class="vl-modal-subtext">[form slug="${f.slug}"]</small></span>`;
      row.addEventListener('click', () => {
        editor.chain().focus().insertContent({
          type: 'formEmbed',
          attrs: { slug: f.slug, formName: f.name },
        }).run();
        overlay.remove();
      });
      list.appendChild(row);
    });
  }

  function loadForms() {
    const url = bp('/admin/forms/list-json');
    if (_formCache.has(url)) { renderForms(_formCache.get(url)); return; }
    refreshIcon.classList.add('vl-icon-spin');
    list.innerHTML = `<p class="vl-empty">${tjs('Loading forms...')}</p>`;
    fetch(url, { headers: { 'X-CSRF-Token': getCsrfToken() } })
      .then(r => r.json())
      .then(forms => { _formCache.set(url, forms); renderForms(forms); })
      .catch(() => { list.innerHTML = `<p class="vl-empty text-red-500">${tjs('Failed to load forms.')}</p>`; })
      .finally(() => refreshIcon.classList.remove('vl-icon-spin'));
  }

  refreshBtn.addEventListener('click', () => {
    _formCache.clear();
    refreshIcon.classList.add('vl-icon-spin');
    loadForms();
  });

  modal.appendChild(header);
  modal.appendChild(list);
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  loadForms();
}

// ── Slash command items ──────────────────────────────────────────────────────

export { _formCache, openFormPicker };
