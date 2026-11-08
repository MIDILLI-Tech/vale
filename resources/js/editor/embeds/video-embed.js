/**
 * Raw HTML video embed block.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { makeDuplicateButton } from '../ui/duplicate.js';
import { subtitleTracksFromHtml } from '../converters.js';
import { openVideoSettings } from '../settings/video-settings.js';

const VideoEmbed = Node.create({
  name: 'videoEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      html: {
        default: '',
        parseHTML: el => {
          const enc = el.getAttribute('data-video-html');
          if (enc) return decodeURIComponent(enc);
          // Pasted raw <iframe>/<video> becomes a video block directly.
          if (el.tagName === 'IFRAME' || el.tagName === 'VIDEO') return el.outerHTML;
          return '';
        },
        renderHTML: attrs => ({ 'data-video-html': encodeURIComponent(attrs.html) }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-video-html]' }, { tag: 'iframe' }, { tag: 'video' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-video-embed-block',
      'contenteditable': 'false',
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-video-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-video-html', encodeURIComponent(node.attrs.html));

      const header = document.createElement('div');
      header.className = 'vl-embed-header-bar';

      const label = document.createElement('span');
      label.className = 'vl-form-embed-label vl-video-embed-label';
      label.textContent = videoHeaderLabel(node.attrs.html);

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.title = tjs('Remove video');
      del.addEventListener('click', () => {
        const pos = typeof getPos === 'function' ? getPos() : null;
        if (pos !== null) {
          editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
        }
      });
      header.appendChild(label);
      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Video Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        // Clear any native DOM range first: a text selection inside/around the
        // block (single click text cursor, dblclick word range) would otherwise
        // be mapped by ProseMirror onto a stale document position when the
        // modal steals focus ("must point at the current document").
        try {
          const sel = window.getSelection();
          if (sel) sel.removeAllRanges();
        } catch (_) { /* ignore */ }
        // Resolve the position fresh at click time: the getPos closure captured
        // at nodeView creation can go stale after document changes (undo,
        // drag, other edits), and passing a wrong pos into the settings dialog
        // corrupts the editor selection ("must point at the current document").
        let pos = null;
        try {
          pos = typeof getPos === 'function' ? getPos() : null;
        } catch (_) {
          pos = null;
        }
        let liveNode = node;
        if (typeof pos !== 'number') {
          // Fallback: locate the block by its stored html.
          const found = findVideoBlockPos(editor, node.attrs.html);
          if (!found) return;
          pos = found.pos;
          liveNode = found.node;
        } else {
          const atPos = editor.state.doc.nodeAt(pos);
          if (!atPos || atPos.type.name !== 'videoEmbed') {
            const found = findVideoBlockPos(editor, node.attrs.html);
            if (!found) return;
            pos = found.pos;
            liveNode = found.node;
          } else {
            liveNode = atPos;
          }
        }
        // Blur the editor BEFORE opening the modal so ProseMirror drops its
        // DOM selection while the document is still current. The modal steals
        // focus anyway; doing it here (instead of lazily on save) keeps the
        // stored selection from going stale.
        try {
          if (editor.view.hasFocus && editor.view.hasFocus()) {
            editor.view.dom.blur();
          }
        } catch (_) { /* non-fatal */ }
        openVideoSettings(editor, liveNode, () => pos);
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));
      header.appendChild(del);
      dom.appendChild(header);

      const inner = document.createElement('div');
      inner.className = 'vl-video-embed-inner';
      inner.innerHTML = constrainVideoHtml(node.attrs.html);
      dom.appendChild(inner);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'videoEmbed') return false;
          node = updatedNode;
          dom.setAttribute('data-video-html', encodeURIComponent(updatedNode.attrs.html));
          inner.innerHTML = constrainVideoHtml(updatedNode.attrs.html);
          label.textContent = videoHeaderLabel(updatedNode.attrs.html);
          return true;
        },
      };
    };
  },
});

// Display-only: wrap a bare <iframe>/<video> in the 16:9 box so inline
// height:100% styles cannot blow up the editor canvas. Storage untouched.
function constrainVideoHtml(html) {
  html = html || '';
  if (html && !html.includes('vl-video-embed') && /<(iframe|video)[\s>]/i.test(html)) {
    return `<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:0">${html}</div>`;
  }
  return html;
}

// Locate a videoEmbed block by its stored html. Used as a fallback when the
// nodeView's getPos() closure is stale (throws or points elsewhere after
// undo/drag/other transactions).
function findVideoBlockPos(editor, html) {
  let found = null;
  try {
    editor.state.doc.descendants((child, pos) => {
      if (found) return false;
      if (child.type.name === 'videoEmbed' && (child.attrs.html || '') === (html || '')) {
        found = { node: child, pos };
        return false;
      }
      return true;
    });
  } catch (_) {
    return null;
  }
  return found;
}

// Editor header label: "🎬 Video" plus an attached-subtitle count, so a video
// with tracks is recognisable without opening its settings.
function videoHeaderLabel(html) {
  const count = subtitleTracksFromHtml(html).length;
  return count > 0
    ? `🎬 ${tjs('Video')} · ${count} ${tjs('subtitles')}`
    : `🎬 ${tjs('Video')}`;
}

// ── Newsletter custom node ───────────────────────────────────────────────────

export { VideoEmbed };
