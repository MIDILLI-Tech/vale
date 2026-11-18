/**
 * Crash-safe editor drafts (localStorage).
 *
 * Every editor keeps a debounced copy of its latest serialized content in
 * localStorage. On mount, a stored draft that differs from the server-rendered
 * content is loaded into the editor automatically, and a banner above the page
 * reports it with "Show differences" / "Discard draft" actions. The draft is
 * dropped as soon as its content matches the saved version again (a successful
 * save cleans up after itself).
 *
 * Opt out per field with `data-draft-autosave="off"` on the textarea.
 */
import { openDraftDiff } from './draft-diff.js';

const PREFIX = 'vl_editor_draft_v1:';
const MAX_DRAFTS = 20;
const SAVE_DELAY_MS = 900;

function normalize(html) {
  return String(html || '').replace(/\r\n/g, '\n').trim();
}

function storageKey(textarea) {
  const form = textarea.form || textarea.closest('form');
  const action = form ? (form.getAttribute('action') || window.location.pathname) : window.location.pathname;
  const field = textarea.name || textarea.id || 'body';
  return `${PREFIX}${action}|${field}`;
}

function readDraft(key) {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (!data || data.v !== 1 || typeof data.html !== 'string') {
      localStorage.removeItem(key);
      return null;
    }
    return data;
  } catch (_) {
    return null;
  }
}

function pruneDrafts() {
  try {
    const entries = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (!key || !key.startsWith(PREFIX)) continue;
      const data = readDraft(key);
      entries.push({ key, savedAt: data ? data.savedAt : 0 });
    }
    if (entries.length <= MAX_DRAFTS) return;
    entries.sort((a, b) => a.savedAt - b.savedAt);
    entries.slice(0, entries.length - MAX_DRAFTS).forEach(entry => localStorage.removeItem(entry.key));
  } catch (_) { /* storage unavailable */ }
}

function writeDraft(key, html) {
  try {
    localStorage.setItem(key, JSON.stringify({ v: 1, html, savedAt: Date.now() }));
    pruneDrafts();
  } catch (_) { /* quota or private mode — drafts are best effort */ }
}

function removeDraft(key) {
  try { localStorage.removeItem(key); } catch (_) { /* ignore */ }
}

/**
 * Drops drafts from other screens whose content the saved version now
 * matches — e.g. the create-page draft left behind after a successful
 * "create" (the browser lands on a different URL/key with the same body).
 */
function pruneSettledDrafts(currentKey, currentHtml) {
  const target = normalize(currentHtml);
  if (!target) return;
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i);
      if (!key || !key.startsWith(PREFIX) || key === currentKey) continue;
      const data = readDraft(key);
      if (data && normalize(data.html) === target) localStorage.removeItem(key);
    }
  } catch (_) { /* ignore */ }
}

function fieldLabel(textarea) {
  if (textarea.dataset.draftLabel) return textarea.dataset.draftLabel;
  const id = textarea.id;
  if (id) {
    const escaped = window.CSS && CSS.escape ? CSS.escape(id) : id.replace(/"/g, '\\"');
    const label = document.querySelector(`label[for="${escaped}"]`);
    if (label && label.textContent.trim()) return label.textContent.trim();
  }
  return textarea.name || 'Content';
}

function formatSavedAt(timestamp) {
  try {
    return new Date(timestamp).toLocaleString(document.documentElement.lang || undefined);
  } catch (_) {
    return new Date(timestamp).toLocaleString();
  }
}

function bannerContainer(wrapper) {
  return document.querySelector('main') || wrapper.parentNode || document.body;
}

/**
 * Mounts draft persistence + recovery for one editor.
 *
 * @param {{textarea: HTMLTextAreaElement, wrapper: HTMLElement, applyHtml: Function}} opts
 */
export function initEditorDraftRecovery({ textarea, wrapper, applyHtml }) {
  if (textarea.dataset.draftAutosave === 'off') return;

  const key = storageKey(textarea);
  const label = fieldLabel(textarea);
  const savedHtml = textarea.value || '';
  let lastWritten = null;
  let timer = null;
  let banner = null;

  const isDirtyRelativeToSaved = html => normalize(html) !== normalize(savedHtml);

  function flush() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    const html = textarea.value || '';
    if (html === lastWritten) return;
    lastWritten = html;
    if (!isDirtyRelativeToSaved(html)) {
      // Back to the saved version (undo, discard, or a successful save).
      removeDraft(key);
      return;
    }
    writeDraft(key, html);
  }

  function scheduleSave() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(flush, SAVE_DELAY_MS);
  }

  function hideBanner() {
    if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
    banner = null;
  }

  function loadIntoEditor(html, opts) {
    applyHtml(html, opts);
    lastWritten = textarea.value || '';
  }

  function discard() {
    if (!window.confirm(tjs('Discard this draft? This cannot be undone.'))) return;
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    // Throw the stored draft away and put the saved version back on screen.
    loadIntoEditor(savedHtml, { emitInput: false });
    removeDraft(key);
    if (window.vlDirtyGuardApi && typeof window.vlDirtyGuardApi.reset === 'function') {
      window.vlDirtyGuardApi.reset();
    }
    hideBanner();
  }

  function showDiff() {
    const draft = readDraft(key);
    if (!draft) {
      hideBanner();
      return;
    }
    openDraftDiff({
      savedHtml,
      draftHtml: draft.html,
      savedAt: draft.savedAt,
      onDiscard: discard,
    });
  }

  function showBanner(draft) {
    const el = document.createElement('div');
    el.className = 'vl-draft-banner';
    el.setAttribute('role', 'status');
    el.innerHTML = `
      <span class="vl-draft-banner-icon" aria-hidden="true">⚠</span>
      <span class="vl-draft-banner-text">
        <span class="vl-draft-banner-title">${tjs('Unsaved draft found')}</span>
        <span><span class="vl-draft-banner-field"></span> — ${tjs('This content was restored from a draft saved in this browser at :time. Discard it to use the saved version.', { ':time': formatSavedAt(draft.savedAt) })}</span>
      </span>
      <span class="vl-draft-banner-actions">
        <button type="button" class="vl-draft-btn vl-draft-btn--primary" data-draft-diff>${tjs('Show differences')}</button>
        <button type="button" class="vl-draft-btn vl-draft-btn--danger" data-draft-discard>${tjs('Discard draft')}</button>
        <button type="button" class="vl-draft-banner-close" data-draft-dismiss aria-label="${tjs('Dismiss')}" title="${tjs('Dismiss')}">×</button>
      </span>`;

    // The label is a localized template value; set it as text so no markup leaks in.
    const fieldEl = el.querySelector('.vl-draft-banner-field');
    if (fieldEl) fieldEl.textContent = label;

    el.querySelector('[data-draft-diff]')?.addEventListener('click', showDiff);
    el.querySelector('[data-draft-discard]')?.addEventListener('click', discard);
    el.querySelector('[data-draft-dismiss]')?.addEventListener('click', hideBanner);

    const container = bannerContainer(wrapper);
    container.insertBefore(el, container.firstChild);
    banner = el;
  }

  pruneSettledDrafts(key, savedHtml);

  // Recover a draft left behind by a crash, a closed tab or a failed request:
  // load it into the editor and report it above the page. The timeout lets the
  // page's dirty guard arm itself first, so the restore marks the form dirty.
  const existing = readDraft(key);
  if (existing) {
    if (isDirtyRelativeToSaved(existing.html)) {
      setTimeout(() => {
        if (!document.contains(textarea)) return;
        try {
          loadIntoEditor(existing.html);
        } catch (_) { /* keep the banner; the auto-load is best effort */ }
      }, 0);
      showBanner(existing);
    } else {
      removeDraft(key);
    }
  }

  // Every edit path (ProseMirror, the hidden textarea, the HTML-source
  // textarea) fires a bubbling input event inside the wrapper.
  wrapper.addEventListener('input', scheduleSave);
  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
  });
}
