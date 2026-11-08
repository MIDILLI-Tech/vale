/**
 * Shared modal scaffolding: overlay + backdrop-close + ESC handling.
 *
 * Every editor dialog is a `.vl-overlay` covering the viewport with a
 * `.vl-modal` panel inside. Backdrop click and ESC both dismiss it.
 */

/**
 * Creates a bare modal shell and appends it to <body>.
 *
 * With `structured: true` the panel adopts the site-wide modal layout:
 * sticky `.vl-editor-modal-head` on top, scrolling `.vl-editor-modal-body`,
 * and a pinned `.vl-editor-modal-foot` action row at the bottom. Padding
 * utilities in `className` are dropped so the wrappers control spacing.
 *
 * @param {{className?: string, zIndex?: number, backdropGuardMs?: number, onClose?: Function, structured?: boolean}} [opts]
 * @returns {{overlay: HTMLDivElement, modal: HTMLDivElement, close: Function}}
 */
export function createModal({ className = '', zIndex = null, backdropGuardMs = 0, onClose, structured = false } = {}) {
  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';
  if (zIndex) overlay.style.zIndex = String(zIndex);
  let pd = false;
  const openedAt = Date.now();
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => {
    if (e.target === overlay && pd && Date.now() - openedAt >= backdropGuardMs) api.close();
  });
  function escHandler(e) {
    if (e.key === 'Escape') api.close();
  }
  document.addEventListener('keydown', escHandler);

  const modal = document.createElement('div');
  const baseClass = structured
    ? className.replace(/\bp-\d+(\.\d+)?\b/g, '').replace(/\s+/g, ' ').trim()
    : className;
  modal.className = 'vl-modal'
    + (baseClass ? ' ' + baseClass : '')
    + (structured ? ' vl-modal--structured' : '');
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  const api = {
    overlay,
    modal,
    close() {
      document.removeEventListener('keydown', escHandler);
      if (!overlay.parentNode) return;
      overlay.remove();
      // Timestamp the close so a dblclick whose second press landed while the
      // modal was open doesn't instantly reopen settings on a stale selection.
      try {
        window.__vlSettingsModalClosedAt = Date.now();
      } catch (_) { /* ignore */ }
      // Return DOM focus to the editor without forcing a ProseMirror selection
      // sync: focusing via the native DOM API (instead of editor.chain().focus())
      // avoids re-mapping a stale stored selection onto the current document.
      try {
        const pm = document.querySelector('.ProseMirror');
        if (pm && document.activeElement && overlay.contains(document.activeElement)) {
          pm.focus({ preventScroll: true });
        }
      } catch (_) { /* ignore */ }
      if (typeof onClose === 'function') onClose();
    },
  };
  return api;
}

/**
 * Settings-style dialog: sticky title + scrolling body + pinned action row.
 * `onSave` receives the modal element; return `false` to keep the dialog open.
 *
 * @param {{title: string, size?: string, bodyHTML: string, onSave?: Function}} opts
 */
export function openFormModal({ title, size = 'vl-modal-sm', bodyHTML, onSave }) {
  const m = createModal({ className: size, structured: true });
  m.modal.innerHTML = `
    <div class="vl-editor-modal-head">
      <h3 class="vl-editor-modal-title">${title}</h3>
    </div>
    <div class="vl-editor-modal-body">
      ${bodyHTML}
    </div>
    <div class="vl-editor-modal-foot">
      <button type="button" class="vl-btn vl-btn-secondary" data-vl-cancel>${tjs('Cancel')}</button>
      <button type="button" class="vl-btn vl-btn-primary" data-vl-save>${tjs('Save')}</button>
    </div>`;
  m.modal.querySelector('[data-vl-cancel]').addEventListener('click', () => m.close());
  m.modal.querySelector('[data-vl-save]').addEventListener('click', () => {
    if (!onSave || onSave(m.modal) !== false) m.close();
  });
  return m;
}
