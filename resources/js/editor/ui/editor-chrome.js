/**
 * Fullscreen toggle + word counter chrome.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
function toggleFullscreen(wrapper) {
  const isFs = wrapper.classList.toggle('is-fullscreen');
  document.body.style.overflow = isFs ? 'hidden' : '';
  if (!isFs) return;
  const escHandler = (e) => {
    if (e.key === 'Escape') {
      wrapper.classList.remove('is-fullscreen');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', escHandler);
    }
  };
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
