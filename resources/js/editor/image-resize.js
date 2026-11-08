/**
 * In-canvas image resize handles + size dialog.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Image } from '@tiptap/extension-image';

function initImageResize(editor) {
  const editorEl = editor.view.dom;

  // Resize + size dialog only apply to standalone images. Images inside
  // composite blocks (carousel, media+text, product, video, …) are managed
  // by their own block UI and must never show handles or dialogs.
  // NOTE: [class*=] (contains) on purpose — [class$=] breaks as soon as
  // ProseMirror appends e.g. "ProseMirror-selectednode" to the class list.
  const isStandaloneImage = (img) =>
    !img.closest('[data-media-text], [class*="embed-block"]');

  let startX, startY, startW, startH, aspectRatio, dragActive = false;
  let currentImg = null;

  // The settings gear + resize corner live INSIDE the image figure as
  // absolute children (like the drag grip — see image-with-size.js). They
  // scroll with the image and show/hide via CSS on ProseMirror-selectednode,
  // so no fixed positioning, scroll listeners, or toolbar math is needed.
  // Only responsive (%-width) images need a corner reposition on window resize.
  window.addEventListener('resize', () => {
    document.querySelectorAll('.vl-image-figure.ProseMirror-selectednode').forEach((fig) => {
      const im = fig.querySelector('img');
      const corner = fig.querySelector(':scope > .vl-img-resize-handle');
      if (!im || !corner) return;
      const f = fig.getBoundingClientRect();
      const r = im.getBoundingClientRect();
      corner.style.left = (r.right - f.left - 10) + 'px';
      corner.style.top = (r.bottom - f.top - 10) + 'px';
    });
  });

  editorEl.addEventListener('mousemove', (e) => {
    if (dragActive) return;
    const img = e.target;
    if (!img || img.tagName !== 'IMG' || !img.closest('.ProseMirror')) return;
    if (!isStandaloneImage(img)) return;

    const rect = img.getBoundingClientRect();
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;
    const isOnHandle = offsetX >= rect.width - 20 && offsetY >= rect.height - 20;

    img.style.cursor = isOnHandle ? 'nwse-resize' : 'default';
  });

  editorEl.addEventListener('mousedown', (e) => {
    if (dragActive) return;
    const img = e.target;
    if (!img || img.tagName !== 'IMG' || !img.closest('.ProseMirror')) return;
    if (!isStandaloneImage(img)) return;

    const rect = img.getBoundingClientRect();
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;
    const isOnHandle = offsetX >= rect.width - 20 && offsetY >= rect.height - 20;
    if (!isOnHandle) return;

    e.preventDefault();
    e.stopPropagation();

    currentImg = img;
    startX = e.clientX;
    startY = e.clientY;
    startW = img.width || img.naturalWidth;
    startH = img.height || img.naturalHeight;
    aspectRatio = startW / startH;
    dragActive = true;
    document.body.style.cursor = 'nwse-resize';
    document.body.style.userSelect = 'none';
  });

  editorEl.addEventListener('dragstart', (e) => {
    // Grip-initiated image moves (text/vale-image) must be allowed through;
    // only the browser's native <img> drag is suppressed so resize works.
    if (e.dataTransfer) {
      try {
        if (Array.from(e.dataTransfer.types || []).includes('text/vale-image')) return;
      } catch { /* ignore */ }
    }
    const img = e.target;
    if (img && img.tagName === 'IMG' && img.closest('.ProseMirror')) {
      e.preventDefault();
    }
  });

  function hasImageDragToken(e) {
    if (!e.dataTransfer) return false;
    try {
      return Array.from(e.dataTransfer.types || []).includes('text/vale-image');
    } catch {
      return false;
    }
  }

  function moveImageNode(srcPos, clientX, clientY) {
    if (typeof srcPos !== 'number' || srcPos < 0) return false;
    const { state, view } = editor;
    const node = state.doc.nodeAt(srcPos);
    if (!node || node.type.name !== 'image') return false;
    const coords = view.posAtCoords({ left: clientX, top: clientY });
    if (!coords) return false;
    let target = coords.pos;
    if (target >= srcPos && target <= srcPos + node.nodeSize) return false;
    view.focus();
    if (target > srcPos) target -= node.nodeSize;
    // The drop target can land where an inline node is not allowed (a table
    // cell boundary, a gap cursor…). Build the transaction first so an invalid
    // position is rejected instead of throwing out of the drop handler.
    let tr;
    try {
      tr = state.tr.delete(srcPos, srcPos + node.nodeSize).insert(target, node);
    } catch (_) {
      return false;
    }
    view.dispatch(tr);
    editor.chain().focus().setNodeSelection(target).run();
    return true;
  }

  // Capture phase on purpose: editorEl is the parent of view.dom, so this
  // runs before ProseMirror's own drop handler. stopPropagation() then keeps
  // PM from also inserting the drag payload as text ("image" leak).
  editorEl.addEventListener('dragover', (e) => {
    if (!hasImageDragToken(e)) return;
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'move';
  }, true);

  editorEl.addEventListener('drop', (e) => {
    if (!hasImageDragToken(e)) return;
    e.preventDefault();
    e.stopPropagation();
    const src = editor.__vlImageDragSrc;
    editor.__vlImageDragSrc = null;
    moveImageNode(src, e.clientX, e.clientY);
  }, true);

  editorEl.addEventListener('dragend', () => {
    editor.__vlImageDragSrc = null;
  });

  // Minimal touch fallback: drag the grip with one finger to move the image.
  // Tap (no move) only selects; a >10px move performs the same delete+insert.
  let touchSrc = null;
  let touchStartX = 0;
  let touchStartY = 0;
  let touchMoved = false;
  editorEl.addEventListener('touchstart', (e) => {
    const gripEl = e.target?.closest?.('.vl-img-drag-grip');
    if (!gripEl || !gripEl.closest('.ProseMirror')) return;
    const figure = gripEl.closest('figure');
    const img = figure?.querySelector('img');
    if (!img) return;
    const pos = editor.view.posAtDOM(img, 0);
    if (pos < 0) return;
    const t = e.touches[0];
    touchSrc = pos;
    touchStartX = t.clientX;
    touchStartY = t.clientY;
    touchMoved = false;
  }, { passive: true });
  editorEl.addEventListener('touchmove', (e) => {
    if (touchSrc === null) return;
    const t = e.touches[0];
    if (!touchMoved && Math.hypot(t.clientX - touchStartX, t.clientY - touchStartY) < 10) return;
    touchMoved = true;
    e.preventDefault();
  }, { passive: false });
  editorEl.addEventListener('touchend', (e) => {
    if (touchSrc === null) return;
    const src = touchSrc;
    touchSrc = null;
    if (!touchMoved) return;
    const t = e.changedTouches[0];
    if (!t) return;
    moveImageNode(src, t.clientX, t.clientY);
  });

  editorEl.addEventListener('dblclick', (e) => {
    const img = e.target;
    if (!img || img.tagName !== 'IMG' || !img.closest('.ProseMirror')) return;
    if (!isStandaloneImage(img)) return;
    e.preventDefault();
    showImageSizeDialog(editor, img);
  });

  document.addEventListener('mousemove', (e) => {
    if (!dragActive) return;
    e.preventDefault();
    const dx = e.clientX - startX;
    const dy = e.clientY - startY;

    const pos = editor.view.posAtDOM(currentImg, 0);
    let lock = true;
    if (pos >= 0) {
      const node = editor.state.doc.nodeAt(pos);
      if (node && node.type.name === 'image') {
        lock = node.attrs.lockAspectRatio !== false;
      }
    }

    let newW = Math.max(50, startW + dx);
    let newH;

    if (lock) {
      newH = Math.round(newW / aspectRatio);
    } else {
      newH = Math.max(50, startH + dy);
    }

    currentImg.width = newW;
    currentImg.height = newH;
    currentImg.style.width = newW + 'px';
    currentImg.style.height = newH + 'px';

    // Keep the in-figure resize corner glued to the image corner live
    // during the drag (it is positioned against the img rect, so without
    // this it would sit at the pre-drag spot until mouseup).
    const fig = currentImg.closest('figure');
    const cornerEl = fig?.querySelector(':scope > .vl-img-resize-handle');
    if (fig && cornerEl) {
      const f = fig.getBoundingClientRect();
      const r = currentImg.getBoundingClientRect();
      cornerEl.style.left = (r.right - f.left - 10) + 'px';
      cornerEl.style.top = (r.bottom - f.top - 10) + 'px';
    }
  });

  document.addEventListener('mouseup', (e) => {
    if (!dragActive) return;
    dragActive = false;
    const img = currentImg;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';

    img.setAttribute('width', String(img.width));
    img.setAttribute('height', String(img.height));
    img.style.width = img.width + 'px';
    img.style.height = img.height + 'px';

    const { state, view } = editor;
    const pos = view.posAtDOM(img, 0);
    if (pos >= 0) {
      const node = state.doc.nodeAt(pos);
      if (node && node.type.name === 'image') {
        editor.chain().focus().setNodeSelection(pos).updateAttributes('image', {
          width: String(img.width),
          height: String(img.height),
        }).run();
        // Re-apply inline styles in case the element was re-created
        const updatedImg = editor.view.dom.querySelector('.ProseMirror-selectednode');
        if (updatedImg && updatedImg !== img) {
          updatedImg.style.width = img.width + 'px';
          updatedImg.style.height = img.height + 'px';
        }
      }
    }
    currentImg = null;
  });
}

// Build a thumbnail-size URL from an original src. Thumbnail variants are
// never stored as .webp, so if the original is .webp we fall back to .png.
function buildThumbnailUrl(src, size) {
  const m = (src || '').match(/^(.*\/)(.+?)([-_](?:small|medium|large))?\.(webp|png|jpe?g|gif)(?:\?[^#]*)?$/i);
  if (!m) return '';
  const dir = m[1];
  const base = m[2];
  const ext = m[4].toLowerCase() === 'webp' ? 'png' : m[4].toLowerCase();
  return dir + base + '-' + size + '.' + ext;
}

// URL used to enlarge an image in a lightbox — prefer the largest original
// variant; fall back to whatever src is currently displayed.
function originalImageUrl(node) {
  const t = node.attrs._thumbnails;
  if (t && typeof t === 'object') {
    if (t.webp) return t.webp;
    if (t.large) return t.large;
    if (t.medium) return t.medium;
    if (t.small) return t.small;
  }
  return node.attrs.src || '';
}

// ── Image size dialog (double-click image → popup for px / % sizing) ─────────
function showImageSizeDialog(editor, imgEl) {
  const pos = editor.view.posAtDOM(imgEl, 0);
  let nodeAttrs = {};
  if (pos >= 0) {
    const node = editor.state.doc.nodeAt(pos);
    if (node && node.type.name === 'image') {
      nodeAttrs = node.attrs;
    }
  }

  const curW = nodeAttrs.width || imgEl.getAttribute('width') || imgEl.naturalWidth || '';
  const curH = nodeAttrs.height || imgEl.getAttribute('height') || imgEl.naturalHeight || '';
  // Raw src as stored in the node (may be a relative path) — what the URL
  // field is pre-filled with and compared against on apply.
  const originalSrc = nodeAttrs.src || imgEl.getAttribute('src') || '';
  const escAttr = (v) => String(v ?? '')
    .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
    .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const curUnit = (String(curW)).includes('%') ? '%' : 'px';
  const curLock = nodeAttrs.lockAspectRatio !== false;
  const curCaption = nodeAttrs.caption || '';
  const curThumbSize = nodeAttrs._thumbnailSize || 'full';
  const curLightbox = nodeAttrs.lightbox === true;
  const curRadius = Math.min(50, Math.max(0, parseInt(String(nodeAttrs.borderRadius || ''), 10) || 0));

  // Derive thumbnails from src for images inserted before this feature existed.
  // Only for same-origin (locally uploaded) images — remote/external images have
  // no -small/-medium/-large variants on their server, so deriving URLs for them
  // would produce broken 404 sources.
  let thumbnails = nodeAttrs._thumbnails;
  if (!thumbnails || typeof thumbnails !== 'object') {
    const src = imgEl.getAttribute('src') || '';
    let isLocal = false;
    try {
      isLocal = new URL(src, window.location.href).origin === window.location.origin;
    } catch { isLocal = false; }
    const m = isLocal ? src.match(/^(.*\/)(.+?)([-_](?:small|medium|large))?\.(webp|png|jpe?g|gif)(?:\?[^#]*)?$/i) : null;
    if (m) {
      const qs = m[5] || '';
      const base = m[2];
      // Strip any existing size suffix (small/medium/large) so we don't get
      // image-small-medium.png when deriving from an already-sized thumbnail.
      // Thumbnail variants are never .webp, so fall back to .png if the original is webp.
      const baseName = base.replace(/[-_](small|medium|large)$/i, '');
      const ext = m[4].toLowerCase() === 'webp' ? 'png' : m[4].toLowerCase();
      thumbnails = {};
      for (const size of ['small', 'medium', 'large']) {
        thumbnails[size] = m[1] + baseName + '-' + size + '.' + ext + qs;
      }
    }
  }
  const hasThumbnailSizes = thumbnails && typeof thumbnails === 'object';

  // Only show a size option if that thumbnail file actually exists
  // (the backend skips sizes larger than the source image).
  const THUMB_OPTIONS = [
    { value: 'full', label: 'Original' },
  ];
  if (thumbnails && typeof thumbnails === 'object') {
    if (thumbnails.large)   THUMB_OPTIONS.push({ value: 'large',   label: 'Large (1024×1024)' });
    if (thumbnails.medium)  THUMB_OPTIONS.push({ value: 'medium',  label: 'Medium (300×300)' });
    if (thumbnails.small)   THUMB_OPTIONS.push({ value: 'small',   label: 'Small (150×150)' });
  }

  // Fallback: no usable thumbnails object — offer all sizes.
  if (THUMB_OPTIONS.length === 1) {
    THUMB_OPTIONS.push(
      { value: 'large',  label: 'Large (1024×1024)' },
      { value: 'medium', label: 'Medium (300×300)' },
      { value: 'small',  label: 'Small (150×150)' },
    );
  }

  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';
  function escHandler(e) { if (e.key === 'Escape') closeModal(); }
  // The gear + resize corner live inside the image figure under the overlay,
  // so they are covered automatically — nothing to hide or restore.
  const closeModal = () => {
    overlay.remove();
    document.removeEventListener('keydown', escHandler);
  };

  document.addEventListener('keydown', escHandler);
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) closeModal(); });

  const aspect = imgEl.naturalWidth && imgEl.naturalHeight ? imgEl.naturalWidth / imgEl.naturalHeight : null;

  const modal = document.createElement('div');
  modal.className = 'vl-modal vl-modal-sm vl-modal--structured';
  modal.innerHTML = `
    <div class="vl-editor-modal-head">
      <h3 class="vl-editor-modal-title">Image Size</h3>
    </div>
    <div class="vl-editor-modal-body">
      <div class="mb-4">
        <label class="vl-label">${tjs('Image URL')}</label>
        <input type="text" id="is-url" value="${escAttr(originalSrc)}" class="vl-input w-full" placeholder="${tjs('e.g. /uploads/photo.png or https://…')}">
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">${tjs('Edit the URL if the image is broken (e.g. the file was moved or renamed).')}</p>
      </div>
      <div class="flex gap-3 mb-3">
        <div class="flex-1">
          <label class="vl-label">Width</label>
          <input type="number" id="is-width" value="${parseFloat(curW) || ''}" min="1" class="vl-input" placeholder="Auto">
        </div>
        <div class="flex-1">
          <label class="vl-label">Height</label>
          <input type="number" id="is-height" value="${parseFloat(curH) || ''}" min="1" class="vl-input" placeholder="Auto">
        </div>
      </div>
      <div class="flex items-center gap-2 mb-4">
        <label class="vl-modal-label-check">
          <input type="checkbox" id="is-lock" ${curLock ? 'checked' : ''}> 🔒 Maintain aspect ratio
        </label>
        <div class="ml-auto flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
          <span>Unit:</span>
          <select id="is-unit" class="vl-select min-w-0! py-1! px-2! pr-5!">
            <option value="px" ${curUnit === 'px' ? 'selected' : ''}>px</option>
            <option value="%" ${curUnit === '%' ? 'selected' : ''}>%</option>
          </select>
        </div>
      </div>
      ${hasThumbnailSizes ? `
      <div class="mb-4" id="is-size-block">
        <label class="vl-label">Size</label>
        <select id="is-thumb-size" class="vl-select w-full">
          ${THUMB_OPTIONS.map(o => `<option value="${o.value}" ${curThumbSize === o.value ? 'selected' : ''}>${o.label}</option>`).join('')}
        </select>
      </div>` : ''}
      <div class="mb-4">
        <label class="vl-label">${tjs('Rounded (px)')}</label>
        <input type="number" id="is-radius" value="${curRadius || ''}" min="0" max="50" class="vl-input w-full" placeholder="0">
      </div>
      <div class="mb-4">
        <label class="vl-label">Caption</label>
        <input type="text" id="is-caption" value="${curCaption.replace(/"/g, '&quot;')}" class="vl-input w-full" placeholder="Image caption…">
      </div>
      <div class="mb-4">
        <label class="vl-modal-label-check">
          <input type="checkbox" id="is-lightbox" ${curLightbox ? 'checked' : ''}> 🔍 ${tjs('Use lightbox (click to enlarge)')}
        </label>
      </div>
    </div>
    <div class="vl-editor-modal-foot">
      <button type="button" id="is-cancel" class="vl-btn vl-btn-secondary">Cancel</button>
      <button type="button" id="is-apply" class="vl-btn vl-btn-primary">Apply</button>
    </div>`;

  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  const wInput = modal.querySelector('#is-width');
  const hInput = modal.querySelector('#is-height');
  const unitSel = modal.querySelector('#is-unit');
  const lockChk = modal.querySelector('#is-lock');
  const captionInput = modal.querySelector('#is-caption');
  const urlInput = modal.querySelector('#is-url');
  const thumbSizeSel = hasThumbnailSizes ? modal.querySelector('#is-thumb-size') : null;
  const sizeBlock = modal.querySelector('#is-size-block');

  function getUnit() { return unitSel.value; }

  function applySize() {
    const editedSrc = urlInput.value.trim();
    if (!editedSrc) {
      alert(tjs('Image URL cannot be empty.'));
      return;
    }
    const srcEdited = editedSrc !== originalSrc;

    const u = getUnit();
    const wVal = wInput.value ? wInput.value + u : '';
    const hVal = hInput.value ? hInput.value + u : '';
    const lockVal = lockChk.checked;
    const captionVal = captionInput.value.trim();
    const newThumbSize = thumbSizeSel ? thumbSizeSel.value : curThumbSize;
    const lightboxVal = modal.querySelector('#is-lightbox')?.checked || false;
    const radiusNum = Math.min(50, Math.max(0, parseInt(modal.querySelector('#is-radius')?.value || '0', 10) || 0));
    const radiusVal = radiusNum > 0 ? radiusNum + 'px' : null;

    const deriveOriginalUrl = (thumbs) => {
      if (!thumbs?.medium) return null;
      // Strip any size suffix from the base name (handles image-small-medium.png too)
      return thumbs.medium
        .replace(/[-_](small|medium|large)\.(?=\w+($|\?))/i, '.')
        .replace(/[-_](small|medium|large)(?=\.\w+($|\?))/i, '');
    };
    let newSrc = imgEl.src;
    let newThumbnails = thumbnails;
    if (srcEdited) {
      // Manually replaced URL: the old -small/-medium/-large variants belong
      // to the previous file and would 404 on the new one, so drop them.
      newSrc = editedSrc;
      newThumbnails = null;
    } else if (thumbSizeSel && newThumbSize !== curThumbSize) {
      if (newThumbSize === 'full') {
        newSrc = thumbnails?.webp || deriveOriginalUrl(thumbnails) || imgEl.src;
      } else {
        // Prefer WebP size variants (webp_small/webp_medium/webp_large), then the
        // original-format variant, then derive from the original extension.
        const sized = (thumbnails && (thumbnails['webp_' + newThumbSize] || thumbnails[newThumbSize])) || '';
        if (sized) {
          newSrc = sized;
        } else {
          newSrc = buildThumbnailUrl(deriveOriginalUrl(thumbnails) || imgEl.src, newThumbSize);
        }
      }
    }

    // Capture the image node + position BEFORE mutating the DOM. Mutating
    // imgEl.src/width/height first can fire Tiptap's mutation observer and
    // re-render, invalidating the position so node attrs (e.g.
    // _thumbnailSize) silently fail to update.
    const { state, view } = editor;
    const pos = view.posAtDOM(imgEl, 0);
    let imageNode = null;
    if (pos >= 0) {
      const n = state.doc.nodeAt(pos);
      if (n && n.type.name === 'image') imageNode = n;
    }

    imgEl.src = newSrc;
    // width/height attributes must hold integers (or %) per HTML spec —
    // "50px" is invalid and gets stripped by strict server-side parsers.
    const toAttr = v => (v.endsWith('px') ? String(parseInt(v, 10)) : v);
    if (wVal) { imgEl.setAttribute('width', toAttr(wVal)); imgEl.style.width = wVal; }
    else { imgEl.removeAttribute('width'); imgEl.style.width = ''; }
    if (hVal) { imgEl.setAttribute('height', toAttr(hVal)); imgEl.style.height = hVal; }
    else { imgEl.removeAttribute('height'); imgEl.style.height = ''; }
    if (radiusVal) {
      imgEl.style.borderRadius = radiusVal;
      imgEl.closest('figure')?.style.setProperty('border-radius', radiusVal);
    } else {
      imgEl.style.removeProperty('border-radius');
      imgEl.closest('figure')?.style.removeProperty('border-radius');
    }

    if (imageNode) {
      editor.commands.command(({ tr }) => {
        tr.setNodeMarkup(pos, undefined, {
          ...imageNode.attrs,
          src: newSrc,
          width: wVal ? toAttr(wVal) : null,
          height: hVal ? toAttr(hVal) : null,
          lockAspectRatio: lockVal,
          caption: captionVal,
          _thumbnailSize: srcEdited ? 'full' : newThumbSize,
          _thumbnails: newThumbnails,
          lightbox: lightboxVal,
          borderRadius: radiusVal,
        });
        return true;
      });
    }
    closeModal();
    // Collapse the image (node) selection so the in-figure gear + corner
    // hide after the image is re-rendered at its new dimensions.
    const sel = editor.state.selection;
    if (sel.from !== sel.to) {
      editor.commands.setTextSelection(sel.from);
    }
  }

  modal.querySelector('#is-cancel').addEventListener('click', closeModal);
  modal.querySelector('#is-apply').addEventListener('click', applySize);
  wInput.addEventListener('keydown', e => { if (e.key === 'Enter') applySize(); });
  hInput.addEventListener('keydown', e => { if (e.key === 'Enter') applySize(); });
  urlInput.addEventListener('keydown', e => { if (e.key === 'Enter') applySize(); });

  // Hide the Size selector while the URL differs from the original — its
  // variant URLs belong to the previous file and would 404 on the new one.
  if (sizeBlock) {
    urlInput.addEventListener('input', () => {
      sizeBlock.style.display = urlInput.value.trim() === originalSrc ? '' : 'none';
    });
  }

  // Auto-fill width/height when size changes
  if (thumbSizeSel) {
    thumbSizeSel.addEventListener('change', () => {
      const v = thumbSizeSel.value;
      lockChk.checked = true;
      if (v === 'full') {
        wInput.value = '';
        hInput.value = '';
      } else {
        const dims = { small: 150, medium: 300, large: 1024 };
        const px = dims[v] || '';
        wInput.value = px;
        hInput.value = '';
      }
    });
  }

  if (aspect) {
    wInput.addEventListener('input', () => {
      if (lockChk.checked && wInput.value) hInput.value = Math.round(parseFloat(wInput.value) / aspect);
    });
    hInput.addEventListener('input', () => {
      if (lockChk.checked && hInput.value) wInput.value = Math.round(parseFloat(hInput.value) * aspect);
    });
  }
}

/**
 * Detection of content type for visibility logic.
 */

export { initImageResize, buildThumbnailUrl, originalImageUrl, showImageSizeDialog };
