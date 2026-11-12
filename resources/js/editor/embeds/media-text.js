import { Node } from '@tiptap/core';
import tippy from 'tippy.js';

import { openMediaPickerExtended } from '../media/browse.js';
import { toEmbedUrl } from '../utils.js';
import { openLinkDialog } from '../ui/link-dialog.js';
import { openMediaTextSettings } from '../settings/media-text-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

const MediaText = Node.create({
  name: 'mediaText',
  group: 'block',
  atom: true,
  draggable: false,

  addAttributes() {
    return {
      imageSrc: { default: '' },
      imageAlt: { default: '' },
      autoplay: { default: 'false' },
      lightbox: { default: false },
      content: { default: '' },
      layout: { default: 'left' },
      verticalAlign: { default: 'center' },
      mediaWidth: { default: '40%' },
      width: { default: '' },
      isExternal: { default: false },
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-media-text]',
      getAttrs(dom) {
        const layout = dom.getAttribute('data-layout') || 'left';
        const verticalAlign = dom.getAttribute('data-vertical-align') || 'center';
        const mediaWidth = dom.getAttribute('data-media-width') || '40%';
        const width = dom.getAttribute('data-width') || '';
        const img = dom.querySelector('img');
        const vid = dom.querySelector('video');
        const mediaEl = img || vid;
        const cols = dom.querySelectorAll('[data-mt-col]');
        return {
          imageSrc: mediaEl ? mediaEl.getAttribute('src') : '',
          imageAlt: img ? img.getAttribute('alt') : '',
          autoplay: dom.getAttribute('data-autoplay') || 'false',
lightbox: img ? img.getAttribute('data-lightbox') === 'true' : false,
          content: cols.length >= 2 ? cols[1].innerHTML : '',
          layout,
          verticalAlign,
          mediaWidth,
          width,
        };
      },
    }];
  },

  renderHTML({ node }) {
    const layout = node.attrs.layout || 'left';
    const verticalAlign = node.attrs.verticalAlign || 'center';
    const mediaWidth = node.attrs.mediaWidth || '40%';
    const width = (node.attrs.width || '').trim();
    const dir = layout === 'right' ? 'row-reverse' : 'row';

    const alignMap = {
      top: 'flex-start',
      center: 'center',
      bottom: 'flex-end',
    };
    const alignItems = alignMap[verticalAlign] || 'center';

    const root = document.createElement('div');
    root.setAttribute('data-media-text', '1');
    root.setAttribute('data-layout', layout);
    root.setAttribute('data-vertical-align', verticalAlign);
    root.setAttribute('data-media-width', mediaWidth);
    root.setAttribute('data-autoplay', node.attrs.autoplay || 'false');
    if (width) {
      root.setAttribute('data-width', width);
      if (width.toLowerCase() !== 'auto') {
        root.style.maxWidth = width;
        root.style.marginLeft = 'auto';
        root.style.marginRight = 'auto';
      }
    }

    const wrap = document.createElement('div');
    wrap.style.cssText = `display:flex;gap:1.5rem;align-items:${alignItems};flex-direction:${dir}`;

    const imgCol = document.createElement('div');
    imgCol.setAttribute('data-mt-col', '0');
    imgCol.style.cssText = `flex:0 0 ${mediaWidth};display:flex;align-items:center;justify-content:center`;
    if (node.attrs.imageSrc) {
      const src = node.attrs.imageSrc;
      const isVid = /\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(src) || src.includes('youtube.com') || src.includes('youtu.be') || src.includes('vimeo.com');
      if (isVid && !src.includes('youtube.com') && !src.includes('youtu.be') && !src.includes('vimeo.com')) {
        const vid = document.createElement('video');
        vid.src = src;
        vid.style.cssText = 'width:100%;height:auto;display:block;border-radius:8px';
        vid.muted = true;
        vid.playsInline = true;
        if (node.attrs.autoplay === true || node.attrs.autoplay === 'true') { vid.autoplay = true; } else { vid.controls = true; }
        imgCol.appendChild(vid);
      } else if (isVid) {
        const iframe = document.createElement('iframe');
        iframe.src = src;
        iframe.style.cssText = 'width:100%;aspect-ratio:16/9;border:none;border-radius:8px';
        iframe.allow = 'autoplay; fullscreen';
        imgCol.appendChild(iframe);
      } else {
        const img = document.createElement('img');
        img.src = src;
        img.alt = node.attrs.imageAlt || '';
        img.style.cssText = 'width:100%;height:auto;display:block;border-radius:8px';
        if (node.attrs.lightbox) {
          img.setAttribute('data-lightbox', 'true');
          img.setAttribute('data-lightbox-src', src);
          img.style.cursor = 'zoom-in';
          img.title = tjs('Click to enlarge');
        }
        imgCol.appendChild(img);
      }
    }

    const textCol = document.createElement('div');
    textCol.setAttribute('data-mt-col', '1');
    textCol.style.cssText = 'flex:1;min-width:0';
    textCol.innerHTML = node.attrs.content || '';

    wrap.appendChild(imgCol);
    wrap.appendChild(textCol);
    root.appendChild(wrap);

    return root;
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-media-text-block';
      dom.setAttribute('data-media-text', '1');

      const alignMap = {
        top: 'flex-start',
        center: 'center',
        bottom: 'flex-end',
      };
      const alignLabels = {
        top: tjs('Align Top'),
        center: tjs('Align Middle'),
        bottom: tjs('Align Bottom'),
      };
      const header = document.createElement('div');
      header.className = 'vl-media-text-header';
      header.innerHTML = `<span class="flex-1">${tjs('Media + Text')}</span>`;

      // Vertical alignment cycle button (Center -> Top -> Bottom -> Center)
      const vAlignBtn = document.createElement('button');
      vAlignBtn.type = 'button';
      vAlignBtn.innerHTML = '↕';
      function updateVAlignBtn(vAlign) {
        vAlignBtn.title = `${tjs('Vertical alignment')}: ${alignLabels[vAlign] || tjs('Align Middle')}`;
      }
      updateVAlignBtn(node.attrs.verticalAlign || 'center');
      vAlignBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const current = node.attrs.verticalAlign || 'center';
        const nextMap = { center: 'top', top: 'bottom', bottom: 'center' };
        const next = nextMap[current] || 'center';
        updateNode({ verticalAlign: next });
      });
      header.appendChild(vAlignBtn);

      const swapBtn = document.createElement('button');
      swapBtn.type = 'button';
      swapBtn.innerHTML = '⇄';
      swapBtn.title = tjs('Swap sides');
      swapBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const newLayout = node.attrs.layout === 'left' ? 'right' : 'left';
        updateNode({ layout: newLayout });
      });
      header.appendChild(swapBtn);

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Media + Text Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openMediaTextSettings(editor, node, getPos());
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });
      header.appendChild(del);

      const body = document.createElement('div');
      body.className = 'vl-media-text-body';
      body.style.padding = '16px';

      const wrap = document.createElement('div');

      // Media column
      const mediaCol = document.createElement('div');
      mediaCol.className = 'vl-media-text-image';
      mediaCol.style.cursor = 'pointer';
      mediaCol.style.position = 'relative';

      const rmMediaBtn = document.createElement('button');
      rmMediaBtn.type = 'button';
      rmMediaBtn.className = 'vl-mt-remove-img';
      rmMediaBtn.textContent = '✕';
      rmMediaBtn.title = tjs('Remove media');
      rmMediaBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        updateNode({ imageSrc: '', imageAlt: '' });
      });
      mediaCol.appendChild(rmMediaBtn);

      function setLayout(layout, vAlign, mediaWidth = '40%') {
        const alignItems = alignMap[vAlign || 'center'] || 'center';
        wrap.style.cssText = `display:flex;gap:1.5rem;align-items:${alignItems};flex-direction:${layout === 'right' ? 'row-reverse' : 'row'}`;
        mediaCol.style.flex = `0 0 ${mediaWidth}`;
      }
      setLayout(node.attrs.layout, node.attrs.verticalAlign, node.attrs.mediaWidth);

      function applyWidth(value) {
        const w = (value || '').trim();
        if (w && w.toLowerCase() !== 'auto') {
          dom.style.maxWidth = w;
          dom.style.marginLeft = 'auto';
          dom.style.marginRight = 'auto';
        } else {
          dom.style.maxWidth = '';
          dom.style.marginLeft = '';
          dom.style.marginRight = '';
        }
      }
      applyWidth(node.attrs.width);

      function renderMedia() {
        const src = node.attrs.imageSrc;
        const alt = node.attrs.imageAlt;
        // Clear rendered media only — the remove button persists.
        mediaCol.querySelectorAll('img, video, iframe, div').forEach(el => el.remove());
        if (src) {
          const isVid = /\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(src) || src.includes('youtube.com') || src.includes('youtu.be') || src.includes('vimeo.com');
          if (isVid && !src.includes('youtube.com') && !src.includes('youtu.be') && !src.includes('vimeo.com')) {
            const vid = document.createElement('video');
            vid.src = src;
            vid.className = 'w-full h-auto rounded-lg block';
            vid.muted = true;
            vid.playsInline = true;
            if (node.attrs.autoplay === true || node.attrs.autoplay === 'true') { vid.autoplay = true; } else { vid.controls = true; }
            vid.textContent = '🎬';
            mediaCol.appendChild(vid);
          } else if (isVid) {
            const iframe = document.createElement('iframe');
            iframe.src = toEmbedUrl(src, node.attrs.autoplay === true || node.attrs.autoplay === 'true');
            iframe.className = 'w-full aspect-video rounded-lg block';
            iframe.allow = 'autoplay; fullscreen';
            mediaCol.appendChild(iframe);
          } else {
            const img = document.createElement('img');
            img.src = src;
            img.alt = alt || '';
            img.className = 'w-full h-auto rounded-lg block';
            if (node.attrs.lightbox) {
              img.setAttribute('data-lightbox', 'true');
              img.setAttribute('data-lightbox-src', src);
              img.style.cursor = 'zoom-in';
              img.title = tjs('Click to enlarge');
            }
            mediaCol.appendChild(img);
          }
          rmMediaBtn.style.display = '';
        } else {
          const ph = document.createElement('div');
          ph.className = 'vl-mt-media-bg w-full aspect-square flex items-center justify-center text-gray-400 dark:text-gray-500 rounded-lg text-2xl';
          ph.textContent = '🖼';
          mediaCol.appendChild(ph);
          rmMediaBtn.style.display = 'none';
        }
      }
      renderMedia();

      mediaCol.addEventListener('click', (e) => {
        e.stopPropagation();
        openMediaPickerExtended(editor, (url, alt) => {
          updateNode({ imageSrc: url, imageAlt: alt || '' });
        }, 'image/', node.attrs.imageSrc || '');
      });

      // Text column
      const textCol = document.createElement('div');
      textCol.className = 'vl-media-text-content';
      textCol.contentEditable = 'true';
      textCol.style.flex = '1';
      textCol.style.minWidth = '0';
      textCol.style.background = 'transparent';
      textCol.style.minHeight = '100px';
      textCol.style.color = 'inherit';
      textCol.style.cursor = 'text';
      textCol.style.userSelect = 'text';

      function syncPlaceholder() {
        const empty = !textCol.textContent.trim();
        textCol.classList.toggle('vl-mt-empty', empty);
        if (empty && textCol.innerHTML !== '') textCol.innerHTML = '';
      }

      textCol.innerHTML = node.attrs.content || '';
      syncPlaceholder();

      let isEditing = false;
      function syncContentToNode() {
        const newContent = textCol.innerHTML;
        node.attrs.content = newContent;
        syncPlaceholder();
        const pos = getPos();
        if (typeof pos === 'number') {
          editor.commands.command(({ tr }) => {
            const currentNode = tr.doc.nodeAt(pos);
            if (currentNode && currentNode.type.name === 'mediaText') {
              tr.setNodeMarkup(pos, undefined, { ...currentNode.attrs, content: newContent });
            }
            return true;
          });
        }
      }

      textCol.addEventListener('focus', () => { isEditing = true; textCol.classList.remove('vl-mt-empty'); });
      textCol.addEventListener('input', () => {
        node.attrs.content = textCol.innerHTML;
        syncPlaceholder();
        syncContentToNode();
      });
      textCol.addEventListener('blur', () => {
        isEditing = false;
        syncContentToNode();
      });

      // Floating selection bubble menu for Media+Text
      let bubbleTippy = null;
      const bubbleMenu = document.createElement('div');
      bubbleMenu.className = 'vl-bubble-menu vl-text-bubble-menu';

      const renderBubble = () => {
        bubbleMenu.innerHTML = '';
        // queryCommandState('createLink') is unreliable in Chrome (returns
        // false for selections inside <a>), so detect the enclosing anchor
        // straight from the DOM selection.
        const selectionInLink = () => {
          const sel = window.getSelection();
          if (!sel || !sel.rangeCount) return false;
          const node = sel.getRangeAt(0).commonAncestorContainer;
          const el = node.nodeType === 1 ? node : node.parentElement;
          return !!(el && el.closest && el.closest('a'));
        };
        const items = [
          { icon: 'B', title: tjs('Bold'), action: () => document.execCommand('bold', false, null), active: () => document.queryCommandState('bold') },
          { icon: 'I', title: tjs('Italic'), action: () => document.execCommand('italic', false, null), active: () => document.queryCommandState('italic') },
          { icon: 'U', title: tjs('Underline'), action: () => document.execCommand('underline', false, null), active: () => document.queryCommandState('underline') },
          { icon: '•', title: tjs('Bullet List'), action: () => document.execCommand('insertUnorderedList', false, null), active: () => document.queryCommandState('insertUnorderedList') },
          { icon: '1.', title: tjs('Numbered List'), action: () => document.execCommand('insertOrderedList', false, null), active: () => document.queryCommandState('insertOrderedList') },
          { icon: '🔗', title: tjs('Link'), action: () => openLinkDialog(editor), active: () => selectionInLink() },
        ];
        items.forEach(item => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'vl-bubble-menu-btn' + (item.active() ? ' is-active' : '');
          btn.innerHTML = `<span class="vl-bubble-icon">${item.icon}</span>`;
          btn.title = item.title;
          btn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            item.action();
            renderBubble();
            syncContentToNode();
          });
          bubbleMenu.appendChild(btn);
        });
      };

      function updateBubbleMenu() {
        const sel = window.getSelection();
        if (!sel || sel.isCollapsed || !sel.rangeCount) {
          if (bubbleTippy) bubbleTippy.hide();
          return;
        }
        const range = sel.getRangeAt(0);
        if (!textCol.contains(range.commonAncestorContainer)) {
          if (bubbleTippy) bubbleTippy.hide();
          return;
        }
        const text = sel.toString();
        if (!text || !text.trim()) {
          if (bubbleTippy) bubbleTippy.hide();
          return;
        }
        renderBubble();
        if (!bubbleTippy) {
          bubbleTippy = tippy(textCol, {
            content: bubbleMenu,
            placement: 'top',
            interactive: true,
            trigger: 'manual',
            animation: false,
            getReferenceClientRect: () => {
              const currentSel = window.getSelection();
              if (currentSel && currentSel.rangeCount > 0) {
                return currentSel.getRangeAt(0).getBoundingClientRect();
              }
              return textCol.getBoundingClientRect();
            },
          });
        }
        bubbleTippy.show();
      }

      function onSelectionChange() {
        if (document.activeElement === textCol || textCol.contains(document.activeElement)) {
          updateBubbleMenu();
        } else if (bubbleTippy) {
          bubbleTippy.hide();
        }
      }
      document.addEventListener('selectionchange', onSelectionChange);

      // Rich keydown handling for lists, indentation, and formatting inside text column
      textCol.addEventListener('keydown', (e) => {
        // Tab / Shift+Tab indent/outdent
        if (e.key === 'Tab') {
          e.preventDefault();
          document.execCommand(e.shiftKey ? 'outdent' : 'indent', false, null);
          return;
        }

        // Standard shortcuts
        if ((e.ctrlKey || e.metaKey) && e.shiftKey) {
          if (e.key === '8' || e.key === '*' || e.code === 'Digit8') {
            e.preventDefault();
            document.execCommand('insertUnorderedList', false, null);
            return;
          }
          if (e.key === '7' || e.code === 'Digit7') {
            e.preventDefault();
            document.execCommand('insertOrderedList', false, null);
            return;
          }
          if (e.key === 'X' || e.key === 'x') {
            e.preventDefault();
            document.execCommand('strikeThrough', false, null);
            return;
          }
          if (e.key === 'L' || e.key === 'l') {
            e.preventDefault();
            document.execCommand('justifyLeft', false, null);
            return;
          }
          if (e.key === 'E' || e.key === 'e') {
            e.preventDefault();
            document.execCommand('justifyCenter', false, null);
            return;
          }
          if (e.key === 'R' || e.key === 'r') {
            e.preventDefault();
            document.execCommand('justifyRight', false, null);
            return;
          }
        }

        if ((e.ctrlKey || e.metaKey) && e.altKey) {
          if (e.key === '1') {
            e.preventDefault();
            document.execCommand('formatBlock', false, '<h1>');
            return;
          }
          if (e.key === '2') {
            e.preventDefault();
            document.execCommand('formatBlock', false, '<h2>');
            return;
          }
          if (e.key === '3') {
            e.preventDefault();
            document.execCommand('formatBlock', false, '<h3>');
            return;
          }
          if (e.key === '0') {
            e.preventDefault();
            document.execCommand('formatBlock', false, '<p>');
            return;
          }
        }

        // Markdown-style triggers on Space ("- ", "* ", "1. ")
        if (e.key === ' ' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
          const sel = window.getSelection();
          if (sel && sel.isCollapsed && sel.anchorNode) {
            const anchor = sel.anchorNode;
            if (anchor.nodeType === 3) {
              const text = anchor.textContent || '';
              const offset = sel.anchorOffset;
              const before = text.slice(0, offset);
              if (before === '-' || before === '*') {
                e.preventDefault();
                anchor.textContent = text.slice(offset);
                document.execCommand('insertUnorderedList', false, null);
                return;
              }
              if (before === '1.' || before === '1') {
                e.preventDefault();
                anchor.textContent = text.slice(offset);
                document.execCommand('insertOrderedList', false, null);
                return;
              }
            }
          }
        }
      });

      function updateNode(attrs) {
        const pos = getPos();
        const scrollY = window.scrollY;
        editor.chain().focus().command(({ tr }) => {
          tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...attrs });
          return true;
        }).run();
        editor.view.dom.focus({ preventScroll: true });
        requestAnimationFrame(() => window.scrollTo(0, scrollY));
      }

      wrap.appendChild(mediaCol);
      wrap.appendChild(textCol);
      body.appendChild(wrap);
      dom.appendChild(header);
      dom.appendChild(body);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'mediaText') return false;

          const oldSrc = node.attrs.imageSrc || '';
          const oldLightbox = node.attrs.lightbox;
          node = updatedNode;

          setLayout(updatedNode.attrs.layout || 'left', updatedNode.attrs.verticalAlign || 'center', updatedNode.attrs.mediaWidth || '40%');
          updateVAlignBtn(updatedNode.attrs.verticalAlign || 'center');
          applyWidth(updatedNode.attrs.width);

          const newSrc = updatedNode.attrs.imageSrc || '';
          const newLightbox = updatedNode.attrs.lightbox;
          if (newSrc !== oldSrc || newLightbox !== oldLightbox) {
            renderMedia();
          }

          if (!isEditing) {
            const newContent = updatedNode.attrs.content || '';
            if (textCol.innerHTML !== newContent) {
              textCol.innerHTML = newContent;
              syncPlaceholder();
            }
          }

          return true;
        },
        destroy() {
          document.removeEventListener('selectionchange', onSelectionChange);
          if (bubbleTippy) {
            bubbleTippy.destroy();
            bubbleTippy = null;
          }
        },
        ignoreMutation(mutation) {
          if (textCol.contains(mutation.target)) return true;
          return false;
        },
        stopEvent(event) {
          if (textCol.contains(event.target)) return true;
          return false;
        },
      };
    };
  },
});

export { MediaText };
