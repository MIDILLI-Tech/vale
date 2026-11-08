/**
 * TextColor mark extension.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Mark } from '@tiptap/core';

const TextColor = Mark.create({
  name: 'textColor',
  addAttributes() {
    return { color: { default: null } };
  },
  parseHTML() {
    return [{
      style: 'color',
      getAttrs(value) { return { color: value }; },
    }];
  },
  renderHTML({ mark }) {
    const { color } = mark.attrs;
    if (!color) return ['span', {}, 0];
    return ['span', { style: `color:${color}` }, 0];
  },
});

// ── Common color presets ────────────────────────────────────────────────────

export { TextColor };
