/**
 * Shared helpers: base path, CSRF token, drag grip, content-type detection.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Image } from '@tiptap/extension-image';

import { FormEmbed } from './embeds/form-embed.js';
import { MoreNode } from './nodes/more-node.js';

function _getBasePath() {
  try { if (window.APP_BASE_PATH) return window.APP_BASE_PATH; } catch {}
  try {
    var u = window.location.pathname;
    var idx = u.indexOf('/admin');
    var bp = idx > 0 ? u.substring(0, idx) : '';
    if (window.VL_VERBOSE) console.log('[editor] _getBasePath: pathname=', u, ' idx=', idx, ' bp=', bp);
    return bp;
  } catch { return ''; }
}

/** Convert a YouTube/Vimeo URL to an embeddable player URL. */
export function toEmbedUrl(url, autoplay = false) {
  if (!url) return url;
  if (url.includes('youtube.com') || url.includes('youtu.be')) {
    const m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([A-Za-z0-9_-]{11})/);
    if (m) {
      let src = 'https://www.youtube.com/embed/' + m[1];
      if (autoplay) src += '?autoplay=1&mute=1';
      return src;
    }
  }
  if (url.includes('vimeo.com')) {
    const m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (m) {
      let src = 'https://player.vimeo.com/video/' + m[1];
      if (autoplay) src += '?autoplay=1&muted=1';
      return src;
    }
  }
  return url;
}

/** Prefix a path with the app base path. */
function bp(path = '') {
  if (!path) return _getBasePath();
  var result = _getBasePath() + (path.startsWith('/') ? path : '/' + path);
  if (window.VL_VERBOSE) console.log('[editor] bp(', path, ') =>', result);
  return result;
}

// ── FormEmbed custom node ────────────────────────────────────────────────────
// Visual block in the editor for [form slug="..."] shortcodes.
// Renders as a styled non-editable pill. Serialises to a <div> with data attrs
// that we convert back to shortcode text before saving.

function createBlockGrip() {
  const grip = document.createElement('span');
  grip.className = 'vl-block-grip';
  grip.setAttribute('aria-hidden', 'true');
  grip.textContent = '⠿';
  return grip;
}

// ── MoreNode — visual <!--more--> divider ──────────────────────────────────
// Renders as a dashed divider in the editor, stores as <!--more--> or <!--more text="..."--> in DB.

function getCsrfToken() {
  const el = document.querySelector('input[name="_csrf_token"]');
  return el ? el.value : '';
}

// ── Image upload helper ───────────────────────────────────────────────────────

function _getEditorContentType() {
  // 1. Try hidden field in form
  const ctInput = document.querySelector('input[name="content_type"]');
  if (ctInput && ctInput.value) return ctInput.value;

  // 2. Try URL patterns
  const path = window.location.pathname;
  if (path.includes('/admin/newsletter') || path.includes('/admin/automations')) return 'newsletter';
  if (path.includes('/admin/pages')) return 'page';
  if (path.includes('/admin/products')) return 'product';
  if (path.includes('/admin/posts')) return 'post';

  return 'post';
}

// ── Media embed base URLs ────────────────────────────────────────────────────
// Embed bases come from the server (media_embeds catalog in
// config/reference-data.php, exposed as window._VL_MEDIA_EMBEDS in the admin
// layout) so the editor and the PHP renderer can never drift apart.

const EMBED_BASE_FALLBACKS = {
  youtube: 'https://www.youtube.com/embed/',
  youtube_nocookie: 'https://www.youtube-nocookie.com/embed/',
  vimeo: 'https://player.vimeo.com/video/',
};

/** Embed base URL (trailing slash included) for a provider: 'youtube' | 'vimeo' | … */
function embedBaseUrl(provider) {
  const fromServer = window._VL_MEDIA_EMBEDS && window._VL_MEDIA_EMBEDS[provider];
  const url = fromServer && fromServer.embed_url ? fromServer.embed_url : '';
  return url || EMBED_BASE_FALLBACKS[provider] || '';
}

// ── Mount all editors ────────────────────────────────────────────────────────

export { _getBasePath, bp, createBlockGrip, getCsrfToken, _getEditorContentType, embedBaseUrl };
