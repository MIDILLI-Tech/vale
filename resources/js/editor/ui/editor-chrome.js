/**
 * Fullscreen toggle + word counter chrome.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
/** Editor fullscreen is a browser preference, so a save reload keeps it. */
const FULLSCREEN_KEY = 'vl_editor_fullscreen';

function rememberFullscreen(on) {
  try {
    localStorage.setItem(FULLSCREEN_KEY, on ? '1' : '0');
  } catch (_) {
    /* storage unavailable (private mode, quota) — the toggle still works */
  }
}

function fullscreenPreferred() {
  try {
    return localStorage.getItem(FULLSCREEN_KEY) === '1';
  } catch (_) {
    return false;
  }
}

/**
 * Fullscreen fixes the wrapper to the viewport, which changes what "the top of
 * the screen" means for the pinned toolbar. Listeners use this event instead of
 * waiting for the next scroll or editor click.
 *
 * Only the toolbar button and Shift+F11 toggle it: Escape belongs to dialogs,
 * dropdowns and the browser, so it must not end fullscreen behind their back.
 */
function setFullscreen(wrapper, on, remember = true) {
  wrapper.classList.toggle('is-fullscreen', on);
  document.body.style.overflow = on ? 'hidden' : '';
  if (remember) rememberFullscreen(on);
  wrapper.dispatchEvent(new CustomEvent('vl:fullscreen', { bubbles: true, detail: { fullscreen: on } }));
}

/** Re-applies the remembered fullscreen after a save reload. */
function restoreFullscreen(wrapper) {
  if (fullscreenPreferred()) setFullscreen(wrapper, true, false);
}

function toggleFullscreen(wrapper) {
  setFullscreen(wrapper, !wrapper.classList.contains('is-fullscreen'));
}

// ── Word & character counter ─────────────────────────────────────────────────
function initWordCounter(editor, wrapper) {
  const statsEl = document.createElement('div');
  statsEl.className = 'vl-editor-stats';
  wrapper.appendChild(statsEl);

  const textarea = wrapper.querySelector('textarea[data-editor]');
  const showReadingTime = textarea && textarea.dataset.showReadingTime === '1';
  const wpm = showReadingTime && textarea ? parseInt(textarea.dataset.readingTimeWpm || '200', 10) : 0;

  function updateStats() {
    const text = editor.state.doc.textContent;
    const words = text.trim() ? text.trim().split(/\s+/).length : 0;
    let statsText = `${words} ${tjs('words')} · ${text.length} ${tjs('characters')}`;

    if (showReadingTime && wpm > 0 && words > 0) {
      const minutes = Math.ceil(words / wpm);
      if (minutes <= 1) {
        statsText += ` · ${tjs('Less than a minute read')}`;
      } else {
        statsText += ` · ${tjs('%d min read', {'%d': minutes})}`;
      }
    }

    statsEl.textContent = statsText;
  }

  editor.on('transaction', updateStats);
  updateStats();
}

// ── Form picker (for toolbar "Add Form" button) ─────────────────────────────

export { toggleFullscreen, restoreFullscreen, initWordCounter };
