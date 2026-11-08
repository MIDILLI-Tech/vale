/**
 * Emoji/highlight popovers.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Highlight } from '@tiptap/extension-highlight';

import { openColorPicker } from '../ui/color-picker.js';

const EMOJI_PRESETS = [
  '😊','😂','❤️','👍','🎉','🔥','⭐','☀️','🔗','📌',
  '💡','⚡','🎯','💪','👏','😎','🤔','🙏','💕','🔄',
  '🎨','📝','🚀','👋','🎈','✨','💯','🏆','✅','🎁',
  '👀','💡','📢','🎵','👑','🌟','💥','🆕','📊','📈',
  '🔒','🔓','💰','📅','📍','🎓','🏠','🌍','📚','📖',
];

// ── Highlight picker helper (for toolbar button) ────────────────────────────
function __openHighlightPicker(editor) {
  const btn = document.querySelector('.vl-editor-toolbar .vl-hl-btn');
  if (!btn) return;
  const color = editor.getAttributes('highlight').color || '#ffff00';
  openColorPicker({
    anchor: btn,
    currentColor: color,
    onSelect(hex) {
      editor.chain().focus().toggleHighlight({ color: hex }).run();
    },
    onRemove() {
      editor.chain().focus().unsetHighlight().run();
    },
  });
}

// ── Emoji picker popover ─────────────────────────────────────────────────────
function openEmojiPicker(editor) {
  const btn = document.querySelector('.vl-editor-toolbar [title="Emoji"]');
  if (!btn) return;
  const existing = btn.parentNode.querySelector('.vl-emoji-picker');
  if (existing) existing.remove();

  const popover = document.createElement('div');
  popover.className = 'vl-emoji-picker';

  const grid = document.createElement('div');
  grid.className = 'vl-emoji-grid';

  EMOJI_PRESETS.forEach(emoji => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'vl-emoji-btn';
    btn.textContent = emoji;
    btn.addEventListener('mousedown', e => {
      e.preventDefault();
      editor.chain().focus().insertContent(emoji).run();
      popover.remove();
    });
    grid.appendChild(btn);
  });

  popover.appendChild(grid);
  document.body.appendChild(popover);

  const ar = btn.getBoundingClientRect();
  popover.style.left = Math.max(4, ar.left - 4) + 'px';
  popover.style.top = (ar.bottom + 4) + 'px';

  setTimeout(() => {
    const close = e => {
      if (!popover.contains(e.target) && e.target !== btn) {
        popover.remove();
        document.removeEventListener('mousedown', close, true);
      }
    };
    document.addEventListener('mousedown', close, true);
  }, 0);
}

// Shared drag-handle grip for draggable divider blocks

export { EMOJI_PRESETS, __openHighlightPicker, openEmojiPicker };
