/**
 * Link insert/edit dialog with internal page search.
 *
 * The URL section (External URL / Internal Content tabs) is the shared
 * internalLinkField — same implementation (and cache) as link buttons and
 * the redirect block settings.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { internalLinkField } from './internal-link-field.js';

function openLinkDialog(editor) {
  const existingAttrs = editor.getAttributes('link') || {};
  const existingHref = existingAttrs.href || '';
  const existingTarget = existingAttrs.target || '';
  const existingType = existingAttrs['data-vl-type'] || '';
  const existingId = existingAttrs['data-vl-id'] || '';
  const { from, to } = editor.state.selection;
  const selectedText = from !== to ? editor.state.doc.textBetween(from, to, '') : '';

  // When the dialog was opened from inside a Media+Text content column the
  // selection is not part of the ProseMirror doc — capture it before the
  // dialog inputs steal focus, and apply the link via execCommand instead.
  const activeEl = document.activeElement;
  const mediaTextCol = (activeEl && activeEl.closest && activeEl.closest('[data-media-text]') && activeEl.isContentEditable)
    ? activeEl
    : null;
  const mediaTextSelected = mediaTextCol && from === to ? String(window.getSelection() || '') : '';

  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) overlay.remove(); });
  document.addEventListener('keydown', function escHandler(e) { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); } });

  const modal = document.createElement('div');
  modal.className = 'vl-modal vl-modal-md vl-modal--structured';
  modal.innerHTML = `
    <div class="vl-editor-modal-head">
      <h3 class="vl-editor-modal-title">${tjs('Insert Link')}</h3>
    </div>
    <div class="vl-editor-modal-body">
      <div class="vl-link-url-section"></div>
      <label class="vl-label mt-3">${tjs('Link text')}</label>
      <input type="text" class="vl-input vl-link-text" placeholder="${tjs('Selected text will appear here')}" value="${(selectedText || mediaTextSelected).replace(/"/g, '&quot;')}">
      <label class="vl-link-blank-label flex items-center gap-1.5 text-xs mt-3 cursor-pointer">
        <input type="checkbox" class="vl-link-blank"${existingTarget === '_blank' ? ' checked' : ''}> ${tjs('Open in new tab')}
      </label>
    </div>
    <div class="vl-editor-modal-foot">
      ${existingHref ? `<button type="button" class="vl-link-remove vl-btn mr-auto border-red-300 text-red-600 dark:border-red-800 dark:text-red-400">${tjs('Remove link')}</button>` : ''}
      <button type="button" class="vl-link-cancel vl-btn vl-btn-secondary">${tjs('Cancel')}</button>
      <button type="button" class="vl-link-insert vl-btn vl-btn-primary">${tjs('Apply')}</button>
    </div>`;

  // Shared URL section — keeps the last resolved value for Apply.
  let resolvedHref = existingHref;
  let resolvedItem = (existingType && existingId) ? { type: existingType, id: existingId } : null;
  const urlSection = modal.querySelector('.vl-link-url-section');
  urlSection.appendChild(internalLinkField({
    label: tjs('URL'),
    value: existingHref,
    onChange: (href, item) => {
      resolvedHref = href;
      resolvedItem = item || null;
    },
  }));

  modal.querySelector('.vl-link-cancel').addEventListener('click', () => overlay.remove());
  modal.querySelector('.vl-link-remove')?.addEventListener('click', () => {
    if (mediaTextCol) {
      mediaTextCol.focus();
      document.execCommand('unlink', false, null);
      overlay.remove();
      return;
    }
    editor.chain().focus().unsetLink().run();
    overlay.remove();
  });

  const escapeHtml = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const safeHref = href => href.replace(/"/g, '%22').replace(/</g, '%3E');

  modal.querySelector('.vl-link-insert').addEventListener('click', () => {
    const blank = modal.querySelector('.vl-link-blank').checked;
    const linkText = modal.querySelector('.vl-link-text').value.trim();
    const href = resolvedHref.trim();
    if (!href) { alert(tjs('Please enter or select a URL.')); return; }

    const linkAttrs = {
      href,
      target: blank ? '_blank' : null,
      'data-vl-type': (resolvedItem && resolvedItem.type && resolvedItem.id) ? resolvedItem.type : null,
      'data-vl-id': (resolvedItem && resolvedItem.type && resolvedItem.id) ? String(resolvedItem.id) : null,
    };

    if (mediaTextCol) {
      mediaTextCol.focus();
      const text = escapeHtml(linkText || mediaTextSelected || href);
      let attrsHtml = `href="${safeHref(href)}"${blank ? ' target="_blank" rel="noopener"' : ''}`;
      if (linkAttrs['data-vl-type'] && linkAttrs['data-vl-id']) {
        attrsHtml += ` data-vl-type="${escapeHtml(linkAttrs['data-vl-type'])}" data-vl-id="${escapeHtml(linkAttrs['data-vl-id'])}"`;
      }
      document.execCommand('insertHTML', false, `<a ${attrsHtml}>${text}</a>`);
      overlay.remove();
      return;
    }

    if (linkText) {
      editor.chain().focus().deleteSelection().insertContent({
        type: 'text',
        text: linkText,
        marks: [{ type: 'link', attrs: linkAttrs }]
      }).run();
    } else {
      editor.chain().focus().setLink(linkAttrs).run();
    }
    overlay.remove();
  });

  overlay.appendChild(modal);
  document.body.appendChild(overlay);
  requestAnimationFrame(() => { const first = modal.querySelector('.vl-link-url-section input'); if (first) first.focus(); });
}

// ── Page picker overlay (for PagesEmbed parent selection) ──────────────────

export { openLinkDialog };
