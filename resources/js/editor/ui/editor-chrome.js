/**
 * Fullscreen toggle + word counter chrome.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
/** Escape listeners, one per wrapper, so repeated toggles cannot stack them. */
const escHandlers = new WeakMap();

/**
 * Fullscreen fixes the wrapper to the viewport, which changes what "the top of
 * the screen" means for the pinned toolbar and the presence strip. Both listen
 * for this event instead of waiting for the next scroll or editor click.
 */
function setFullscreen(wrapper, on) {
  wrapper.classList.toggle('is-fullscreen', on);
  document.body.style.overflow = on ? 'hidden' : '';
  wrapper.dispatchEvent(new CustomEvent('vl:fullscreen', { bubbles: true, detail: { fullscreen: on } }));
}

function toggleFullscreen(wrapper) {
  const on = !wrapper.classList.contains('is-fullscreen');
  setFullscreen(wrapper, on);

  const previous = escHandlers.get(wrapper);
  if (previous) {
    document.removeEventListener('keydown', previous);
    escHandlers.delete(wrapper);
  }
  if (!on) return;

  const escHandler = (e) => {
    if (e.key !== 'Escape') return;
    escHandlers.delete(wrapper);
    document.removeEventListener('keydown', escHandler);
    setFullscreen(wrapper, false);
  };
  escHandlers.set(wrapper, escHandler);
  document.addEventListener('keydown', escHandler);
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

export { toggleFullscreen, initWordCounter };
