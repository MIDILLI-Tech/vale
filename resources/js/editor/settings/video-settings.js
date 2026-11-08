/**
 * Video embed block settings dialog.
 *
 * Allows editing the embed URL with live preview, or pasting raw
 * iframe/video HTML for advanced embeds.
 */
import { openBlockSettings } from './schema-modal.js';
import { setSubtitleTracks, setVideoWidth, subtitleTracksFromHtml, videoWidthFromHtml } from '../converters.js';
import { openSubtitleBrowser } from '../media/browse.js';
import { embedBaseUrl } from '../utils.js';

/**
 * Extract a clean URL from stored HTML for pre-filling the URL field.
 */
const WIDTH_PRESETS = ['100%', '75%', '50%'];
function extractUrlFromHtml(html) {
  if (!html) return '';
  const trimmed = html.trim();
  // Bare URL (from [embed] shortcode conversion)
  if (/^https?:\/\//i.test(trimmed) && !trimmed.includes('<')) return trimmed;
  // <video src="..."> tag
  const vidMatch = trimmed.match(/<video[^>]+src=["']([^"']+)["']/i);
  if (vidMatch) return vidMatch[1];
  // <iframe src="..."> — also handle src='...' and unquoted src=...
  const iframeMatch = trimmed.match(/<iframe[^>]+src=["']([^"']+)["']/i);
  if (iframeMatch) return iframeMatch[1];
  // <source src="..."> inside <video>
  const sourceMatch = trimmed.match(/<source[^>]+src=["']([^"']+)["']/i);
  if (sourceMatch) return sourceMatch[1];
  return '';
}

/**
 * Build HTML from a URL for storage in the videoEmbed node.
 */
function buildHtmlFromUrl(url) {
  const trimmed = (url || '').trim();
  if (!trimmed) return '';
  // YouTube
  const yt = trimmed.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
  if (yt) {
    return `<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:0"><iframe src="${embedBaseUrl('youtube')}${yt[1]}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
  }
  // Vimeo
  const vi = trimmed.match(/vimeo\.com\/(\d+)/);
  if (vi) {
    return `<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:0"><iframe src="${embedBaseUrl('vimeo')}${vi[1]}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
  }
  // Direct video file
  if (trimmed.match(/\.(mp4|webm|ogg|mov|m4v)($|\?)/i)) {
    const esc = trimmed.replace(/&/g, '&').replace(/</g, '<').replace(/>/g, '>').replace(/"/g, '"');
    return `<video src="${esc}" controls style="width:100%"></video>`;
  }
  // Fallback: raw HTML pasted by the user. A bare <iframe> with
  // position:absolute styling would escape to the viewport on the front end,
  // so wrap it in the aspect-ratio container before storing.
  if (/<iframe\b[^>]*>[\s\S]*?<\/iframe>/i.test(trimmed) && !trimmed.includes('vl-video-embed')) {
    const srcMatch = trimmed.match(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/i)[0].match(/\bsrc=(["'])(.*?)\1/i);
    const src = srcMatch ? srcMatch[2].trim() : '';
    if (/^https?:\/\/[^"\s<>]+/i.test(src)) {
      const esc = src.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      return `<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:0"><iframe src="${esc}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
    }
  }
  return trimmed;
}

/**
 * Update the preview element with current content.
 *
 * Mirrors openExternalMediaDialog(): the inner iframe/video/img fills the box
 * directly (w-full h-full). The aspect-ratio wrapper (.vl-video-embed) is NOT
 * used here — it collapses as a flex item / inside the fixed-aspect box and
 * renders nothing.
 */
function updatePreview(previewEl, content) {
  if (!previewEl) return;
  if (!content) {
    previewEl.innerHTML = `<span class="text-xs text-gray-500 dark:text-gray-400">${tjs('Preview will appear here')}</span>`;
    return;
  }
  // Bare URL → generic link placeholder (matches /external dialog)
  if (/^https?:\/\//i.test(content) && !content.includes('<')) {
    previewEl.innerHTML = `<span class="text-blue-500 dark:text-blue-400 text-xs text-center p-5">${tjs('Generic Link')}<br><small class="text-gray-500 dark:text-gray-400">${content}</small></span>`;
    return;
  }
  // Pull out the media element and let it fill the preview box directly.
  const media = content.match(/<iframe[^>]*>[\s\S]*?<\/iframe>/i)
    || content.match(/<video[^>]*?>[\s\S]*?<\/video>/i)
    || content.match(/<img[^>]*>/i);
  if (media) {
    let tag = media[0];
    // The stored iframe is absolutely positioned inside its aspect-ratio
    // wrapper — outside that wrapper it would escape to the viewport and
    // cover the screen. Rewrite it to a plain block that fills the box.
    tag = tag
      .replace(/\sstyle=(["'])[\s\S]*?\1/i, ' style="width:100%;height:100%;border:0"')
      .replace(/(<(?:iframe|video|img))/i, '$1 class="w-full h-full border-0"');
    previewEl.innerHTML = tag;
    return;
  }
  previewEl.innerHTML = content;
}

/**
 * Subtitles editor inside the video settings dialog.
 *
 * Tracks are stored as <track> elements inside the video HTML, so the front
 * end needs no change; this field only manages that set. The picker comes
 * pre-selected with the currently attached tracks (matched by URL).
 */
function buildSubtitleField(ctx, initialTracks) {
  const wrap = document.createElement('div');
  wrap.className = 'mt-4';

  const label = document.createElement('label');
  label.className = 'vl-label';
  label.textContent = tjs('Subtitles');
  wrap.appendChild(label);

  const list = document.createElement('div');
  list.className = 'space-y-2';
  wrap.appendChild(list);

  const addBtn = document.createElement('button');
  addBtn.type = 'button';
  addBtn.className = 'vl-btn vl-btn-secondary vl-btn-sm mt-2';
  addBtn.textContent = '➕ ' + tjs('Add subtitles');
  wrap.appendChild(addBtn);

  const hint = document.createElement('p');
  hint.className = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
  hint.textContent = tjs('WebVTT (.vtt) tracks. SubRip (.srt/.sub) uploads are converted automatically.');
  wrap.appendChild(hint);

  // Custom fields are not seeded from `values` — write the initial set into
  // the patch so an untouched dialog still saves the existing tracks.
  if (!ctx.getValue('_subtitles')) {
    ctx.setValue('_subtitles', (initialTracks || []).slice());
  }

  const getTracks = () => (ctx.getValue('_subtitles') || []).slice();

  function commit(tracks, rerender = true) {
    // Normalize in place — never copy the track objects here. The row inputs
    // close over them, and a copy would detach the closure from the patch, so
    // every keystroke after the first would write to an orphaned object.
    const list = tracks.filter((t) => t && t.url);
    let defaultSeen = false;
    for (const t of list) {
      t.isDefault = !defaultSeen && !!t.isDefault;
      if (t.isDefault) defaultSeen = true;
    }
    ctx.setValue('_subtitles', list);
    // Text edits must NOT re-render: rebuilding the rows would drop the caret
    // (and the keyboard focus) on every keystroke.
    if (rerender) render(list);
  }

  function render(tracks) {
    list.innerHTML = '';
    if (tracks.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'text-xs text-gray-400 dark:text-gray-500';
      empty.textContent = tjs('No subtitles attached yet.');
      list.appendChild(empty);
      return;
    }
    tracks.forEach((track, index) => {
      const row = document.createElement('div');
      row.className = 'flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-gray-700 p-1.5';

      // 1) Language — a select from the full language catalog (config/reference-data.php
      //    → window.VL_LANGUAGES), so an Arabic subtitle works on a Turkish site.
      //    Falls back to a free-text code when the catalog is unavailable.
      const fileName = trackFilename(track.url);
      const langWrap = document.createElement('div');
      langWrap.className = 'w-32 shrink-0';
      const languageOptions = languageCatalog();
      let langInput;
      if (Object.keys(languageOptions).length > 0) {
        const sel = document.createElement('select');
        sel.className = 'vl-input vl-select text-sm';
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = tjs('Language');
        sel.appendChild(empty);
        for (const [code, label] of Object.entries(languageOptions)) {
          const opt = document.createElement('option');
          opt.value = code;
          opt.textContent = label;
          if (code === track.srclang) opt.selected = true;
          sel.appendChild(opt);
        }
        if (track.srclang && !languageOptions[track.srclang]) {
          const extra = document.createElement('option');
          extra.value = track.srclang;
          extra.textContent = track.srclang;
          extra.selected = true;
          sel.appendChild(extra);
        }
        sel.title = tjs('Subtitle language');
        sel.addEventListener('change', () => {
          track.srclang = sel.value;
          commit(getTracks(), false);
        });
        langInput = sel;
      } else {
        langInput = document.createElement('input');
        langInput.type = 'text';
        langInput.className = 'vl-input text-sm';
        langInput.value = track.srclang || '';
        langInput.maxLength = 5;
        langInput.placeholder = tjs('lang');
        langInput.title = tjs('Language code (e.g. tr, en)');
        langInput.addEventListener('input', () => {
          track.srclang = langInput.value.trim().toLowerCase();
          commit(getTracks(), false);
        });
      }
      langWrap.appendChild(langInput);

      // 2) Label — optional. Empty keeps the file name, which is also shown as
      //    the placeholder so the row is never two mystery boxes.
      const labelInput = document.createElement('input');
      labelInput.type = 'text';
      labelInput.className = 'vl-input flex-1 min-w-0 text-sm';
      labelInput.value = track.label || '';
      labelInput.placeholder = fileName;
      labelInput.title = tjs('Subtitle display text — leave default to use file name');
      labelInput.addEventListener('input', () => {
        track.label = labelInput.value.trim();
        commit(getTracks(), false);
      });

      const defaultBtn = document.createElement('button');
      defaultBtn.type = 'button';
      defaultBtn.className = 'vl-btn vl-btn-sm ' + (track.isDefault
        ? 'vl-btn-primary'
        : 'vl-btn-secondary');
      defaultBtn.textContent = track.isDefault ? '★' : '☆';
      defaultBtn.title = tjs('Default subtitle track');
      defaultBtn.addEventListener('click', () => {
        const next = getTracks().map((t, i) => ({ ...t, isDefault: i === index }));
        commit(next);
      });

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'vl-btn vl-btn-sm vl-btn-secondary';
      removeBtn.textContent = '✕';
      removeBtn.title = tjs('Remove');
      removeBtn.addEventListener('click', () => {
        commit(getTracks().filter((_, i) => i !== index));
      });

      row.appendChild(langWrap);
      row.appendChild(labelInput);
      row.appendChild(defaultBtn);
      row.appendChild(removeBtn);
      list.appendChild(row);
    });
  }

  addBtn.addEventListener('click', () => {
    const current = getTracks();
    openSubtitleBrowser(ctx.editor, (urls) => {
      // The picker opens pre-selected, so its result is the full set: keep the
      // metadata of tracks that stayed selected, drop the ones unchecked, and
      // add genuinely new picks.
      const stillSelected = new Set(urls);
      const kept = current.filter((t) => stillSelected.has(t.url));
      const known = new Set(current.map((t) => t.url));
      const added = urls
        .filter((u) => !known.has(u))
        .map((url) => ({ url, label: '', srclang: langFromFilename(url) }));
      commit([...kept, ...added]);
    }, current.map((t) => t.url));
  });

  render(getTracks());
  return wrap;
}

// Full language catalog (config/reference-data.php → window.VL_LANGUAGES),
// rendered with the browser's own Intl.DisplayNames so every admin sees the
// names in their own UI language (an English admin sees "Turkish", a Turkish
// admin "Türkçe"). Independent of the site's content languages: a video may
// carry subtitles in a language the site itself is not written in.
function languageCatalog() {
  const codes = Array.isArray(window.VL_LANGUAGES) ? window.VL_LANGUAGES : [];
  if (codes.length === 0) return {};
  const uiLocale = document.documentElement.lang || 'en';
  let display = null;
  try {
    display = new Intl.DisplayNames([uiLocale], { type: 'language' });
  } catch (_) {
    display = null; // Intl unavailable → fall back to the raw code
  }
  const out = {};
  for (const code of codes) {
    out[code] = display ? (display.of(code) || code) : code;
  }
  return out;
}

// File name of a track URL, used as the label placeholder and as the label
// fallback when the label is left empty.
function trackFilename(url) {
  return decodeURIComponent(String(url || '').split('/').pop() || '') || String(url || '');
}

function langFromFilename(url) {
  const name = decodeURIComponent(String(url).split('/').pop() || '');
  const m = name.match(/[-_]([a-z]{2})(?:[-_][a-z]{2})?\.vtt$/i);
  return m ? m[1].toLowerCase() : '';
}

/**
 * Open the video settings dialog for a videoEmbed node.
 */
export function openVideoSettings(editor, node, getPos) {
  const currentUrl = extractUrlFromHtml(node.attrs.html);
  const isRawHtml = node.attrs.html && !extractUrlFromHtml(node.attrs.html);
  // Stored width may be a preset (50%) or a custom value (400px) — map it back
  // onto the select + its conditional text field.
  const storedWidth = videoWidthFromHtml(node.attrs.html);
  const initialSubtitleTracks = subtitleTracksFromHtml(node.attrs.html);
  const widthIsCustom = !!storedWidth && !WIDTH_PRESETS.includes(storedWidth);
  const resolveWidth = (patch) => {
    const raw = patch._videoWidth === 'custom'
      ? (patch._videoWidthCustom || '').trim()
      : patch._videoWidth;
    return raw === '100%' ? '' : raw;
  };
  let previewTimer = null;

  openBlockSettings(editor, getPos(), {
    title: tjs('Video Settings'),
    values: {
      _videoUrl: currentUrl,
      _videoWidth: widthIsCustom ? 'custom' : (storedWidth || '100%'),
      _videoWidthCustom: widthIsCustom ? storedWidth : '',
      _subtitles: initialSubtitleTracks,
      _videoHtml: isRawHtml ? node.attrs.html : '',
    },
    fields: [
      {
        key: '_videoUrl',
        type: 'text',
        label: tjs('Video URL'),
        placeholder: tjs('YouTube, Vimeo, or direct MP4 link'),
        help: isRawHtml ? tjs('Current embed uses raw HTML. Enter a URL to replace it.') : undefined,
      },
      {
        key: '_videoHtml',
        type: 'text',
        label: tjs('Or paste iframe/video HTML'),
        placeholder: tjs('Advanced: paste raw embed code'),
      },
      {
        key: '_videoWidth',
        type: 'select',
        label: tjs('Width'),
        options: [
          { v: '100%', l: tjs('Full width') },
          { v: '75%', l: '75%' },
          { v: '50%', l: '50%' },
          { v: 'custom', l: tjs('Custom...') },
        ],
      },
      {
        key: '_videoWidthCustom',
        type: 'text',
        label: tjs('Custom width'),
        placeholder: tjs('e.g. 400px or 60%'),
        showWhen: { key: '_videoWidth', value: 'custom' },
      },
      { type: 'custom', render: (c) => buildSubtitleField(c, initialSubtitleTracks) },
    ],
    onInput: (patch) => {
      // Debounce like openExternalMediaDialog() so rapid keystrokes don't
      // thrash the iframe (500ms was already proven in that dialog).
      clearTimeout(previewTimer);
      previewTimer = setTimeout(() => {
        const url = patch._videoUrl || '';
        const html = patch._videoHtml || '';
        const content = setSubtitleTracks(
          setVideoWidth(html || buildHtmlFromUrl(url), resolveWidth(patch)),
          patch._subtitles
        );
        const previewEl = document.querySelector('.vl-video-preview');
        updatePreview(previewEl, content);
      }, 500);
    },
    onReady: (ctx) => {
      // Inject preview area after modal is fully constructed
      const bodyWrap = ctx.modal.querySelector('.vl-modal-flex-col');
      if (!bodyWrap) return;

      const previewWrap = document.createElement('div');
      previewWrap.className = 'mt-4';
      // NOTE: the preview box must NOT be a flex container — the 16:9 wrapper
      // (.vl-video-embed) relies on padding-bottom against its own width, and
      // as a flex item with only absolutely-positioned children it collapses
      // to 0 width/height, rendering an empty box.
      previewWrap.innerHTML = `
        <label class="vl-label">${tjs('Preview')}</label>
        <div class="vl-video-preview w-full aspect-video vl-bg-soft rounded-lg mt-1 overflow-hidden border border-gray-200 dark:border-gray-700"></div>`;
      bodyWrap.appendChild(previewWrap);

      // Show initial preview — render the same HTML the block stores, but if
      // only a bare URL is stored (legacy/shortcode), build the embed.
      const previewEl = previewWrap.querySelector('.vl-video-preview');
      const stored = node.attrs.html || '';
      const initialContent = stored.includes('<') ? stored : buildHtmlFromUrl(stored || currentUrl);
      updatePreview(previewEl, initialContent);

      // The URL field holds a bare URL while preview needs built HTML —
      // refresh once so paste/edit state is in sync from the start.
      if (currentUrl && !stored.includes('<')) {
        updatePreview(previewEl, buildHtmlFromUrl(currentUrl));
      }
    },
    onSave: (readers, patch) => {
      readers.forEach(r => r());
      const urlValue = patch._videoUrl || '';
      const htmlValue = patch._videoHtml || '';
      const finalHtml = setSubtitleTracks(
        setVideoWidth(htmlValue || buildHtmlFromUrl(urlValue), resolveWidth(patch)),
        patch._subtitles
      );
      // The modal stole DOM focus, so editor.view.dispatch(tr) directly would
      // leave ProseMirror with a stale selection pointing at the old document
      // ("Selection passed to setSelection must point at the current
      // document"). Go through the chain with focus() so the selection is
      // re-based before the transaction is dispatched. Re-resolve the node by
      // position AND by content so a stale click-time pos cannot corrupt the
      // document either.
      const clickPos = typeof getPos === 'function' ? getPos() : null;
      // Snapshot the block's identity at save time for the stale-pos fallback.
      let identityHtml = null;
      try {
        const atClick = (typeof clickPos === 'number') ? editor.state.doc.nodeAt(clickPos) : null;
        if (atClick && atClick.type.name === 'videoEmbed') identityHtml = atClick.attrs.html || '';
      } catch (_) { /* ignore */ }
      let ok = false;
      editor.chain().focus().command(({ tr }) => {
        let pos = (typeof clickPos === 'number') ? clickPos : null;
        let current = (pos !== null) ? tr.doc.nodeAt(pos) : null;
        if (!current || current.type.name !== 'videoEmbed') {
          // Stale pos — find the same block by its stored html.
          let foundPos = null;
          let foundNode = null;
          tr.doc.descendants((child, p) => {
            if (foundPos !== null) return false;
            if (child.type.name === 'videoEmbed' && (identityHtml === null || (child.attrs.html || '') === identityHtml)) {
              foundPos = p;
              foundNode = child;
              return false;
            }
            return true;
          });
          if (foundPos === null || !foundNode) return false;
          pos = foundPos;
          current = foundNode;
        }
        tr.setNodeMarkup(pos, undefined, { ...current.attrs, html: finalHtml });
        ok = true;
        return true;
      }).run();
      if (!ok) return false;
    },
  });
}