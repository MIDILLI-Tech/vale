/**
 * Image extension: resize handles, captions, lightbox, thumbnail sizes.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { mergeAttributes } from '@tiptap/core';
import { Image } from '@tiptap/extension-image';
import { updateColumnsOnResize } from '@tiptap/pm/tables';

import { originalImageUrl, showImageSizeDialog } from '../image-resize.js';

const ImageWithSize = Image.extend({
  parseHTML() {
    // Shared attr parser so both the <figure> and the bare <img> rule
    // preserve thumbnail data. Without it, ProseMirror's mutation
    // observer would re-parse a directly-mutated <img> via the
    // bare rule and reconcile the node back to defaults
    // (_thumbnails: null, _thumbnailSize: 'full'), wiping updates.
    const parseImgAttrs = (img) => {
      if (!img) return false;
      const figcaption = img.closest('figure')?.querySelector('figcaption');
      const thumbnailsAttr = img.getAttribute('data-thumbnails');
      let borderRadius = null;
      try {
        const m = String(img.style?.borderRadius || '').match(/(\d+)/);
        if (m) borderRadius = Math.min(50, parseInt(m[1], 10)) + 'px';
      } catch { /* style API unavailable — treat as square */ }
      return {
        src: img.getAttribute('src'),
        alt: img.getAttribute('alt'),
        title: img.getAttribute('title'),
        width: img.getAttribute('width'),
        height: img.getAttribute('height'),
        caption: figcaption ? figcaption.textContent.trim() : '',
        _thumbnailSize: img.getAttribute('data-thumbnail-size') || 'full',
        _thumbnails: thumbnailsAttr ? JSON.parse(thumbnailsAttr) : null,
        lightbox: img.getAttribute('data-lightbox') === 'true',
        borderRadius,
      };
    };
    return [
      {
        tag: 'figure.vl-image-figure',
        getAttrs: (el) => parseImgAttrs(el.querySelector('img')),
      },
      {
        tag: 'img[src]',
        getAttrs: (el) => parseImgAttrs(el),
      },
    ];
  },
  addAttributes() {
    return {
      src: { default: null },
      alt: { default: null },
      title: { default: null },
      width: { default: null },
      height: { default: null },
      lockAspectRatio: { default: true, rendered: false },
      caption: { default: '', rendered: false },
      _thumbnailSize: { default: 'full', rendered: false },
      _thumbnails: { default: null, rendered: false },
      lightbox: { default: false, rendered: false },
      borderRadius: { default: null, rendered: false },
    };
  },
  renderHTML({ node, HTMLAttributes }) {
    const { width, height } = HTMLAttributes;
    const styles = [];
    if (width != null) {
      const w = String(width);
      styles.push(`width:${w.includes('px') || w.includes('%') ? w : w + 'px'}`);
    }
    if (height != null) {
      const h = String(height);
      styles.push(`height:${h.includes('px') || h.includes('%') ? h : h + 'px'}`);
    }
    if (node.attrs.borderRadius) styles.push(`border-radius:${node.attrs.borderRadius}`);
    if (node.attrs.lightbox) styles.push('cursor:pointer');
    const style = styles.length ? styles.join(';') : undefined;
    const dataAttrs = {};
    if (node.attrs._thumbnails) dataAttrs['data-thumbnails'] = JSON.stringify(node.attrs._thumbnails);
    if (node.attrs._thumbnailSize) dataAttrs['data-thumbnail-size'] = node.attrs._thumbnailSize;
    if (node.attrs.lightbox) {
      dataAttrs['data-lightbox'] = 'true';
      dataAttrs['data-lightbox-src'] = originalImageUrl(node);
    }
    const img = ['img', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, { style }, dataAttrs)];
    const caption = node.attrs.caption;
    if (caption) {
      return ['figure', { class: 'vl-image-figure' }, img, ['figcaption', { class: 'vl-image-caption' }, caption]];
    }
    return img;
  },
  addNodeView() {
    return ({ node, getPos, editor }) => {
      const attrs = node.attrs;
      const { width, height, src, alt, caption, borderRadius } = attrs;

      const styles = [];
      if (width != null) {
        const w = String(width);
        styles.push(`width:${w.includes('px') || w.includes('%') ? w : w + 'px'}`);
      }
      if (height != null) {
        const h = String(height);
        styles.push(`height:${h.includes('px') || h.includes('%') ? h : h + 'px'}`);
      }
      if (borderRadius) styles.push(`border-radius:${borderRadius}`);

      const figure = document.createElement('figure');
      figure.className = 'vl-image-figure';
      // The selection halo (box-shadow) lives on the figure, so it needs the
      // same radius — otherwise a rounded image keeps a square halo.
      if (borderRadius) figure.style.borderRadius = borderRadius;

      const img = document.createElement('img');
      img.src = src || '';
      if (alt) img.alt = alt;
      if (styles.length) img.style.cssText = styles.join(';');
      if (width != null) img.setAttribute('width', String(width));
      if (height != null) img.setAttribute('height', String(height));
      figure.appendChild(img);

      // Drag grip — visible only on the selected image (see styles.js).
      // Starts a manual move (metin taşıma gibi); the drop itself is
      // handled centrally in image-resize.js so cross-paragraph moves work.
      const grip = document.createElement('span');
      grip.className = 'vl-img-drag-grip';
      grip.textContent = '⠿';
      grip.title = tjs('Drag to move');
      grip.draggable = true;
      grip.contentEditable = 'false';
      grip.setAttribute('aria-hidden', 'true');
      grip.addEventListener('mousedown', (e) => {
        e.stopPropagation();
      });
      grip.addEventListener('dragstart', (e) => {
        e.stopPropagation();
        const pos = typeof getPos === 'function' ? getPos() : null;
        if (pos == null || typeof pos !== 'number' || pos < 0) {
          e.preventDefault();
          return;
        }
        editor.__vlImageDragSrc = pos;
        // NOTE: only the custom token — never text/plain. ProseMirror's own
        // drop handler inserts text/plain as content, which is what leaked
        // the stray "image" text into the document.
        try {
          e.dataTransfer.setData('text/vale-image', '1');
        } catch { /* clipboard API unavailable — move still works via __vlImageDragSrc */ }
        e.dataTransfer.effectAllowed = 'move';
        figure.classList.add('is-dragging');
      });
      grip.addEventListener('dragend', (e) => {
        e.stopPropagation();
        figure.classList.remove('is-dragging');
      });
      figure.appendChild(grip);

      // Settings gear — top-right corner, absolute like the grip so it
      // scrolls with the image. Shown only on the selected image via CSS.
      const gear = document.createElement('button');
      gear.type = 'button';
      gear.className = 'vl-img-settings-gear';
      gear.textContent = '⚙';
      gear.title = tjs('Image Settings');
      gear.contentEditable = 'false';
      gear.tabIndex = -1;
      gear.setAttribute('aria-hidden', 'true');
      gear.addEventListener('mousedown', (e) => {
        e.stopPropagation();
      });
      gear.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        showImageSizeDialog(editor, img);
      });
      figure.appendChild(gear);

      // Resize corner — bottom-right of the image, visual only (the drag
      // itself is detected via the corner zone in image-resize.js).
      // Positioned against the img rect (not the figure) so %-width images
      // get the corner on the image, not on the full-width figure.
      const corner = document.createElement('span');
      corner.className = 'vl-img-resize-handle';
      corner.contentEditable = 'false';
      corner.setAttribute('aria-hidden', 'true');
      figure.appendChild(corner);

      const positionCorner = () => {
        const f = figure.getBoundingClientRect();
        const r = img.getBoundingClientRect();
        if (!r.width && !r.height) return;
        corner.style.left = (r.right - f.left - 10) + 'px';
        corner.style.top = (r.bottom - f.top - 10) + 'px';
      };
      positionCorner();
      img.addEventListener('load', positionCorner);

      let figcaption = null;
      if (caption) {
        figcaption = document.createElement('figcaption');
        figcaption.className = 'vl-image-caption';
        figcaption.textContent = caption;
        figure.appendChild(figcaption);
      }

      return {
        dom: figure,
        stopEvent(event) {
          const t = event.target;
          if (t === grip || t === gear) return true;
          if (t?.closest && (t.closest('.vl-img-drag-grip') === grip || t.closest('.vl-img-settings-gear') === gear)) return true;
          return false;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'image') return false;
          const na = updatedNode.attrs;
          if (na.src !== attrs.src) img.src = na.src || '';
          if (na.alt !== attrs.alt) img.alt = na.alt || '';
          if (na.width !== attrs.width || na.height !== attrs.height || na.borderRadius !== attrs.borderRadius) {
            const newStyles = [];
            if (na.width != null) {
              const w = String(na.width);
              newStyles.push(`width:${w.includes('px') || w.includes('%') ? w : w + 'px'}`);
              img.setAttribute('width', w);
            } else { img.removeAttribute('width'); }
            if (na.height != null) {
              const h = String(na.height);
              newStyles.push(`height:${h.includes('px') || h.includes('%') ? h : h + 'px'}`);
              img.setAttribute('height', h);
            } else { img.removeAttribute('height'); }
            if (na.borderRadius) {
              newStyles.push(`border-radius:${na.borderRadius}`);
              figure.style.borderRadius = na.borderRadius;
            } else {
              figure.style.removeProperty('border-radius');
            }
            img.style.cssText = newStyles.join(';');
            positionCorner();
          }
          if (na.caption !== attrs.caption) {
            if (na.caption) {
              if (figcaption) {
                figcaption.textContent = na.caption;
              } else {
                figcaption = document.createElement('figcaption');
                figcaption.className = 'vl-image-caption';
                figcaption.textContent = na.caption;
                figure.appendChild(figcaption);
              }
            } else {
              if (figcaption) { figcaption.remove(); figcaption = null; }
            }
          }
          Object.assign(attrs, na);
          return true;
        },
      };
    };
  },
});

// ── Table with style attribute support ───────────────────────────────────────────
// Custom NodeView that keeps the editor table DOM identical to the markup that
// gets saved. updateColumnsOnResize() writes width/min-width from the column
// widths; the user's own width must win so the editor and the front end agree.
class PreservingTableView {
  constructor(node, cellMinWidth) {
    this.node = node;
    this.defaultCellMinWidth = cellMinWidth;
    this.dom = document.createElement('div');
    this.dom.className = 'tableWrapper';
    this.table = this.dom.appendChild(document.createElement('table'));
    this.colgroup = this.table.appendChild(document.createElement('colgroup'));
    this._render();
    this.contentDOM = this.table.appendChild(document.createElement('tbody'));
  }
  _render() {
    const userStyle = this.node.attrs.style || '';
    // setAttribute (not style.cssText) so removed properties actually disappear
    this.table.setAttribute('style', userStyle);
    this.table.style.setProperty('--default-cell-min-width', `${this.defaultCellMinWidth}px`);
    updateColumnsOnResize(this.node, this.colgroup, this.table, this.defaultCellMinWidth);
    // updateColumnsOnResize leaves a column without an explicit width with an
    // empty style, while the saved markup gives it `min-width: Npx`. Mirroring
    // that here keeps the column widths (and therefore the table width) equal
    // in the editor and on the site.
    for (const col of Array.from(this.colgroup.children)) {
      col.style.minWidth = col.style.width ? '' : `${this.defaultCellMinWidth}px`;
    }

    const widthMatch = userStyle.match(/(?:^|;)\s*width\s*:\s*([^;]+)/);
    if (widthMatch) {
      // A declared table width (px or %) is authoritative, exactly like the
      // inline style served to visitors. Drop the column-derived min-width so
      // both surfaces lay out from the same three inputs: width + colgroup.
      this.table.style.width = widthMatch[1].trim();
      this.table.style.minWidth = '';
    }

    // No derived cell border here: the saved markup is what the theme renders,
    // so anything the editor adds on its own would break parity.
  }
  update(node) {
    if (node.type !== this.node.type) return false;
    this.node = node;
    this._render();
    return true;
  }
  ignoreMutation(mutation) {
    return mutation.type === 'attributes' && (mutation.target === this.table || this.colgroup.contains(mutation.target));
  }
}

export { ImageWithSize, PreservingTableView };
