/**
 * Caret memory across the save round-trip.
 *
 * Saving a post is a full page POST, so the edit screen reloads at the top of
 * the document and the author has to find their place again. The caret offset
 * is stored in sessionStorage right before the form submits and restored (with
 * a scroll to it) when the editor mounts again seconds later.
 *
 * The editor is created before its content is seeded, so the position can only
 * be applied once the document is no longer empty — restoreCaret() waits for
 * that instead of clamping the caret to the start of a blank document.
 *
 * The record is one-shot and short-lived: a stale entry from an earlier visit
 * must never move the caret on a fresh page.
 */

const KEY_PREFIX = 'vl_editor_caret:';
const MAX_AGE_MS = 30000;
const EMPTY_DOC_SIZE = 2;
const POLL_INTERVAL_MS = 50;
const POLL_ATTEMPTS = 60;

function storageKey(textarea) {
    return textarea && textarea.id ? KEY_PREFIX + textarea.id : '';
}

export function rememberCaret(textarea, editor) {
    const key = storageKey(textarea);
    if (!key || !editor) return;
    try {
        sessionStorage.setItem(key, JSON.stringify({
            pos: editor.state.selection.from,
            at: Date.now(),
        }));
    } catch (error) {
        // Private mode / disabled storage — scroll restoration is a nicety.
    }
}

function scrollCaretIntoView(editor, pos) {
    try {
        const coords = editor.view.coordsAtPos(pos);
        const target = coords.top + window.scrollY - (window.innerHeight / 2);
        window.scrollTo({ top: Math.max(0, target), behavior: 'auto' });
    } catch (error) {
        editor.view.dom.scrollIntoView({ block: 'center' });
    }
}

export function restoreCaret(textarea, editor) {
    const key = storageKey(textarea);
    if (!key || !editor) return false;

    let data = null;
    try {
        const raw = sessionStorage.getItem(key);
        if (!raw) return false;
        data = JSON.parse(raw);
    } catch (error) {
        return false;
    }

    if (!data || Date.now() - Number(data.at || 0) > MAX_AGE_MS) {
        try { sessionStorage.removeItem(key); } catch (error) { /* ignore */ }
        return false;
    }

    let attempts = 0;

    const apply = () => {
        attempts++;

        if (editor.state.doc.content.size <= EMPTY_DOC_SIZE && attempts < POLL_ATTEMPTS) {
            setTimeout(apply, POLL_INTERVAL_MS);
            return;
        }

        try { sessionStorage.removeItem(key); } catch (error) { /* ignore */ }

        const max = editor.state.doc.content.size;
        const pos = Math.max(0, Math.min(Number(data.pos) || 0, max));

        try {
            editor.commands.setTextSelection(pos);
        } catch (error) {
            return;
        }

        requestAnimationFrame(() => scrollCaretIntoView(editor, pos));
    };

    apply();
    return true;
}
