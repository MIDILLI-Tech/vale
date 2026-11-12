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
  const editorAttrs = editor.getAttributes('link') || {};
  let existingHref = editorAttrs.href || '';
  let existingTarget = editorAttrs.target || '';
  let existingType = editorAttrs['data-vl-type'] || '';
  let existingId = editorAttrs['data-vl-id'] || '';
  const { from, to } = editor.state.selection;
  const selectedText = from !== to ? editor.state.doc.textBetween(from, to, '') : '';

  // When the dialog was opened from inside a Media+Text content column the
  // selection is not part of the ProseMirror doc — capture the exact DOM range
  // (and any anchor around it) before the dialog inputs steal focus, and apply
  // the link via execCommand instead.
  const activeEl = document.activeElement;
  const mediaTextCol = (activeEl && activeEl.closest && activeEl.closest('[data-media-text]') && activeEl.isContentEditable)
    ? activeEl
    : null;

  let savedRange = null;
  let mediaAnchor = null;
  let mediaTextSelected = '';
  if (mediaTextCol) {
    const sel = window.getSelection();
    if (sel && sel.rangeCount) {
      savedRange = sel.getRangeAt(0).cloneRange();
      mediaTextSelected = savedRange.toString();
      const node = savedRange.commonAncestorContainer;
      mediaAnchor = (node.nodeType === 1 ? node : node.parentElement)?.closest?.('a') || null;
      if (mediaAnchor) {
        existingHref = mediaAnchor.getAttribute('href') || '';
        existingTarget = mediaAnchor.getAttribute('target') || '';
        existingType = mediaAnchor.getAttribute('data-vl-type') || '';
        existingId = mediaAnchor.getAttribute('data-vl-id') || '';
      }
    }
  }

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
      <input type="text" class="vl-input vl-link-text" placeholder="${tjs('Selected text will appear here')}" value="${(selectedText || (mediaAnchor ? mediaAnchor.textContent.trim() : '') || mediaTextSelected).replace(/"/g, '&quot;')}">
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
      mediaTextCol.focus({ preventScroll: true });
      if (savedRange && savedRange.startContainer.isConnected) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(savedRange);
      }
      document.execCommand('unlink', false, null);
      overlay.remove();
      return;
    }
    editor.chain().focus().unsetLink().run();
    overlay.remove();
  });

  const escapeHtml = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const safeHref = href => href.replace(/"/g, '%22').replace(/</g, '%3E');

  // Enter in the External URL field applies the link. The internal panel's
  // search input keeps its own key handling, and the hidden external input
  // cannot be focused while that tab is active — so the gate is implicit.
  const urlInput = modal.querySelector('.vl-link-url-section input[type="url"]');
  urlInput?.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    modal.querySelector('.vl-link-insert').click();
  });

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
      mediaTextCol.focus({ preventScroll: true });
      // Restore the exact selection captured at dialog open — blur and the
      // dialog inputs may have collapsed it, which would make insertHTML
      // insert the link next to the text instead of replacing it (duplicates).
      if (savedRange && savedRange.startContainer.isConnected) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(savedRange);
      }

      if (mediaAnchor) {
        // Editing an existing link: update the anchor in place instead of
        // inserting a nested <a> inside it.
        mediaAnchor.setAttribute('href', href);
        if (blank) {
          mediaAnchor.setAttribute('target', '_blank');
          mediaAnchor.setAttribute('rel', 'noopener');
        } else {
          mediaAnchor.removeAttribute('target');
          mediaAnchor.removeAttribute('rel');
        }
        if (linkAttrs['data-vl-type'] && linkAttrs['data-vl-id']) {
          mediaAnchor.setAttribute('data-vl-type', linkAttrs['data-vl-type']);
          mediaAnchor.setAttribute('data-vl-id', linkAttrs['data-vl-id']);
        } else {
          mediaAnchor.removeAttribute('data-vl-type');
          mediaAnchor.removeAttribute('data-vl-id');
        }
        const finalText = linkText || mediaAnchor.textContent.trim();
        if (finalText && finalText !== mediaAnchor.textContent) {
          mediaAnchor.textContent = finalText;
        }
        mediaTextCol.dispatchEvent(new Event('input', { bubbles: true }));
        overlay.remove();
        return;
      }

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
