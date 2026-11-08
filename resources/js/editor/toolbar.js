/**
 * Main editor toolbar builder.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { openExternalMediaDialog, openMediaBrowser } from './media/browse.js';
import { videoEmbedHtmlForUrl } from './converters.js';
import { triggerFileUpload } from './media/upload.js';
import { openCouponPicker, openRedirectSettingsForLastBlock } from './slash-menu.js';
import { insertTable } from './table-controls.js';
import { __openTextColorPicker, colorSwatchTitle } from './ui/color-picker.js';
import { toggleFullscreen } from './ui/editor-chrome.js';
import { __openHighlightPicker, openEmojiPicker } from './ui/emoji-picker.js';
import { openFormPicker } from './ui/form-picker.js';
import { openLinkDialog } from './ui/link-dialog.js';
import { openProductPicker } from './ui/product-picker.js';
import { _getEditorContentType } from './utils.js';
import { insertHero } from './embeds/hero-embed.js';

function buildToolbar(editor, wrapper) {
  const toolbar = document.createElement('div');
  toolbar.className = 'vl-editor-toolbar';
  let openDropdown = null;
  let closeDropdownTimer = null;

  function closeDropdowns() {
    if (openDropdown !== null) {
      openDropdown = null;
      render();
    }
  }

  function scheduleCloseDropdowns() {
    closeDropdownTimer = setTimeout(() => {
      closeDropdownTimer = null;
      closeDropdowns();
    }, 120);
  }

  function cancelCloseDropdowns() {
    if (closeDropdownTimer !== null) {
      clearTimeout(closeDropdownTimer);
      closeDropdownTimer = null;
    }
  }

  document.addEventListener('mousedown', (event) => {
    if (!toolbar.contains(event.target)) {
      closeDropdowns();
    }
  });

  function isInMediaText(el) {
    return el && el.closest && el.closest('[data-media-text]') !== null;
  }
  function execOnMediaText(cmd, val) {
    if (isInMediaText(document.activeElement) && document.activeElement.isContentEditable) {
      document.execCommand(cmd, false, val);
      return true;
    }
    return false;
  }
  function setLinkButtonAlignment(align) {
    const { state } = editor;
    const $anchor = state.selection.$anchor;
    for (let d = $anchor.depth; d >= 0; d--) {
      const n = $anchor.node(d);
      if (n.type.name === 'linkButton') {
        const pos = $anchor.before(d);
        editor.chain().focus().command(({ tr }) => {
          tr.setNodeMarkup(pos, undefined, { ...n.attrs, alignment: align });
          return true;
        }).run();
        return true;
      }
    }
    return false;
  }
  function fmtActive(fmt) {
    return () => {
      if (isInMediaText(document.activeElement) && document.activeElement.isContentEditable) {
        return document.queryCommandState(fmt);
      }
      return editor.isActive(fmt);
    };
  }
  function fmtAction(fmt, execCmd) {
    return () => {
      if (execCmd && execOnMediaText(execCmd)) return;
      editor.chain().toggleMark(fmt).run();
    };
  }

  const buttons = [
    { label: '↩',  title: tjs('Undo (Ctrl+Z)'),             action: () => editor.chain().undo().run(), active: () => false },
    { label: '↪',  title: tjs('Redo (Ctrl+Y)'),             action: () => editor.chain().redo().run(), active: () => false },
    null,
    { label: 'B',  title: tjs('Bold (Ctrl+B)'),          action: fmtAction('bold', 'bold'),         active: fmtActive('bold') },
    { label: 'I',  title: tjs('Italic (Ctrl+I)'),        action: fmtAction('italic', 'italic'),     active: fmtActive('italic') },
    { label: 'U',  title: tjs('Underline (Ctrl+U)'),     action: fmtAction('underline', 'underline'),  active: fmtActive('underline') },
    null,
    {
      label: 'Fmt',
      title: tjs('Formatting Options'),
      dropdown: true,
      dropdownName: 'format',
      active: () => editor.isActive('strike') || editor.isActive('code') || editor.isActive('subscript') || editor.isActive('superscript') || editor.isActive('bulletList') || editor.isActive('orderedList') || editor.isActive('blockquote'),
      options: [
        { label: 'S', title: tjs('Strike (Ctrl+Shift+X)'), action: fmtAction('strike', 'strikeThrough'), active: fmtActive('strike') },
        { label: '<>', title: tjs('Inline Code'), action: fmtAction('code', 'code'), active: fmtActive('code') },
        { label: 'X₂', title: tjs('Subscript'), action: fmtAction('subscript', 'subscript'), active: fmtActive('subscript') },
        { label: 'X²', title: tjs('Superscript'), action: fmtAction('superscript', 'superscript'), active: fmtActive('superscript') },
        { label: '•', title: tjs('Bullet List (Ctrl+Shift+8)'), action: () => { if (execOnMediaText('insertUnorderedList')) return; editor.chain().toggleBulletList().run(); }, active: () => editor.isActive('bulletList') },
        { label: '1.', title: tjs('Ordered List (Ctrl+Shift+7)'), action: () => { if (execOnMediaText('insertOrderedList')) return; editor.chain().toggleOrderedList().run(); }, active: () => editor.isActive('orderedList') },
        { label: '❝', title: tjs('Quote (Ctrl+Shift+B)'), action: () => { if (execOnMediaText('formatBlock','<blockquote>')) return; editor.chain().toggleBlockquote().run(); }, active: () => editor.isActive('blockquote') },
      ]
    },
    null,
    {
      label: () => {
        const color = editor.getAttributes('textColor').color || '#ef4444';
        return `<span class="vl-color-letter" style="color:${color}">A</span>`;
      },
      className: 'vl-tc-btn',
      title: colorSwatchTitle(tjs('Text Color')),
      action: () => __openTextColorPicker(editor),
      active: () => editor.isActive('textColor'),
    },
    {
      label: () => {
        const color = editor.getAttributes('highlight').color || '#fde68a';
        return `<span class="vl-color-letter" style="background:${color}; color:#1f2937;height:18px;width:16px">A</span>`;
      },
      className: 'vl-hl-btn',
      title: colorSwatchTitle(tjs('Highlight')),
      action: () => __openHighlightPicker(editor),
      active: () => editor.isActive('highlight'),
    },
    null,
    {
      label: 'H',
      title: tjs('Headings'),
      dropdown: true,
      dropdownName: 'headings',
      active: () => editor.isActive('heading',{level:1}) || editor.isActive('heading',{level:2}) || editor.isActive('heading',{level:3}),
      options: [
        { label: 'H1', title: tjs('Heading 1 (Ctrl+Alt+1)'), action: () => { if (execOnMediaText('formatBlock','<h1>')) return; editor.isActive('heading',{level:1}) ? editor.chain().setParagraph().run() : editor.chain().setHeading({level:1}).run(); }, active: () => editor.isActive('heading',{level:1}) },
        { label: 'H2', title: tjs('Heading 2 (Ctrl+Alt+2)'), action: () => { if (execOnMediaText('formatBlock','<h2>')) return; editor.isActive('heading',{level:2}) ? editor.chain().setParagraph().run() : editor.chain().setHeading({level:2}).run(); }, active: () => editor.isActive('heading',{level:2}) },
        { label: 'H3', title: tjs('Heading 3 (Ctrl+Alt+3)'), action: () => { if (execOnMediaText('formatBlock','<h3>')) return; editor.isActive('heading',{level:3}) ? editor.chain().setParagraph().run() : editor.chain().setHeading({level:3}).run(); }, active: () => editor.isActive('heading',{level:3}) },
      ]
    },
    null,
    {
      label: '<span class="text-xs leading-none">Align</span>',
      title: tjs('Text Alignment'),
      dropdown: true,
      dropdownName: 'align',
      active: () => editor.isActive({ textAlign: 'left' }) || editor.isActive({ textAlign: 'center' }) || editor.isActive({ textAlign: 'right' }),
      options: [
        { label: '<svg viewBox="0 0 20 16" width="18" height="14" fill="currentColor"><rect x="0" y="0" width="16" height="3" rx="1.5"/><rect x="0" y="6" width="12" height="3" rx="1.5"/><rect x="0" y="12" width="14" height="3" rx="1.5"/></svg>', title: tjs('Align Left (Ctrl+Shift+L)'), action: () => { if (setLinkButtonAlignment('left')) return; if (execOnMediaText('justifyLeft')) return; editor.chain().setTextAlign('left').run(); }, active: () => editor.isActive({ textAlign: 'left' }) },
        { label: '<svg viewBox="0 0 20 16" width="18" height="14" fill="currentColor"><rect x="2" y="0" width="16" height="3" rx="1.5"/><rect x="4" y="6" width="12" height="3" rx="1.5"/><rect x="3" y="12" width="14" height="3" rx="1.5"/></svg>', title: tjs('Align Center (Ctrl+Shift+E)'), action: () => { if (setLinkButtonAlignment('center')) return; if (execOnMediaText('justifyCenter')) return; editor.chain().setTextAlign('center').run(); }, active: () => editor.isActive({ textAlign: 'center' }) },
        { label: '<svg viewBox="0 0 20 16" width="18" height="14" fill="currentColor"><rect x="4" y="0" width="16" height="3" rx="1.5"/><rect x="8" y="6" width="12" height="3" rx="1.5"/><rect x="6" y="12" width="14" height="3" rx="1.5"/></svg>', title: tjs('Align Right (Ctrl+Shift+R)'), action: () => { if (setLinkButtonAlignment('right')) return; if (execOnMediaText('justifyRight')) return; editor.chain().setTextAlign('right').run(); }, active: () => editor.isActive({ textAlign: 'right' }) },
      ]
    },
    null,
    {
      label: 'Element',
      title: tjs('Insert Element'),
      dropdown: true,
      dropdownName: 'element',
      active: () => false,
      options: [
        { label: '🖼', title: tjs('Upload (Ctrl+Shift+U)'), action: () => triggerFileUpload(editor), active: () => false },
        { label: '📁', title: tjs('Media Library (Ctrl+Alt+M)'), action: () => openMediaBrowser(editor), active: () => false },
        { label: '🔗', title: tjs('External Media (Ctrl+Alt+E)'), action: () => {
          openExternalMediaDialog({
            onSelect: (url) => {
              const isImg = url.match(/\.(jpeg|jpg|gif|png|webp|svg)($|\?)/i);
              if (isImg) {
                editor.chain().focus().setImage({ src: url }).run();
              } else {
                editor.chain().focus().insertContent({ type: 'videoEmbed', attrs: { html: videoEmbedHtmlForUrl(url) } }).run();
              }
            }
          });
        }, active: () => false },
        { label: '🔘', title: tjs('Link Buttons'), action: () => editor.commands.insertContent({ type: 'linkButtonGroup', content: [{ type: 'linkButton', content: [{ type: 'text', text: tjs('Button Text') }] }] }), active: () => false },
        { label: '🃏', title: tjs('Cards'), action: () => editor.commands.insertContent({ type: 'cardGroup', content: [{ type: 'cardItem', content: [{ type: 'cardTitle', content: [{ type: 'text', text: tjs('Card Title') }] }, { type: 'cardDesc', content: [{ type: 'text', text: tjs('Card description') }] }] }] }), active: () => false },
        { label: '🏁', title: tjs('Hero'), action: () => insertHero(editor), active: () => false },
        ...(window._VL_NEWSLETTER_ENABLED !== '0' ? [{ label: '📧', title: tjs('Newsletter Form'), action: () => editor.commands.insertContent({ type: 'newsletterEmbed' }), active: () => false }] : []),
        ...(window._VL_NEWSLETTER_ENABLED !== '0' && _getEditorContentType() === 'newsletter' ? [{ label: '📰', title: tjs('Latest Posts Feed'), action: () => editor.commands.insertContent({ type: 'latestPostsEmbed' }), active: () => false }] : []),
        ...(window._VL_NEWSLETTER_ENABLED !== '0' && _getEditorContentType() === 'newsletter' ? [{ label: '🔕', title: tjs('Unsubscribe Link'), action: () => editor.commands.insertContent({ type: 'unsubscribeEmbed' }), active: () => false }] : []),
        ...(window._VL_NEWSLETTER_ENABLED !== '0' && _getEditorContentType() === 'newsletter' ? [{ label: '👁', title: tjs('Open Track Tag'), action: () => editor.commands.insertContent({ type: 'openPixelEmbed' }), active: () => false }] : []),
        { label: '🎠', title: tjs('Carousel'), action: () => editor.commands.insertContent({ type: 'carouselEmbed' }), active: () => false },
        ...(window._VL_FORMS_ENABLED !== '0' ? [{ label: '📋', title: tjs('Insert Form (Ctrl+Alt+F)'), action: () => openFormPicker(editor), active: () => false }] : []),
        ...((window._VL_SHOP_ENABLED === '1' || window._VL_MEMBERSHIP_ENABLED === '1') ? [{ label: '🎟', title: tjs('Insert Coupon'), action: () => openCouponPicker(editor, { allowGenerator: _getEditorContentType() === 'newsletter' }), active: () => false }] : []),
        ...(window._VL_SHOP_ENABLED === '1' ? [{ label: '🛒', title: tjs('Insert Product'), action: () => openProductPicker(editor), active: () => false }] : []),
        { label: '📑', title: tjs('Table of Contents'), action: () => editor.commands.insertContent({ type: 'tocEmbed' }), active: () => false },
        { label: '↗', title: tjs('Redirect'), action: () => { editor.commands.insertContent({ type: 'redirectEmbed' }); openRedirectSettingsForLastBlock(editor); }, active: () => false },
        { label: '⊡', title: tjs('More Block'), action: () => editor.chain().focus().insertContent({ type: 'more' }).run(), active: () => false, visible: () => { const ct = _getEditorContentType(); return ct !== 'page' && ct !== 'product'; } },
        { label: '📄', title: tjs('Next Page Block'), action: () => editor.chain().focus().insertContent({ type: 'nextpage' }).run(), active: () => false },
        { label: '👶', title: tjs('Child Pages'), action: () => editor.commands.insertContent({ type: 'pagesEmbed' }), active: () => false, visible: () => _getEditorContentType() === 'page' },
      ]
    },
    { label: '🔗', title: tjs('Insert Link (Ctrl+K)'), action: () => openLinkDialog(editor),                      active: () => editor.isActive('link') },
    null,
    { label: 'Tbl', title: tjs('Insert Table (Ctrl+Alt+T)'), action: () => insertTable(editor), active: () => editor.isActive('table') },
    { label: '⏎',  title: tjs('Hard Break (Shift+Enter)'),    action: () => { if (execOnMediaText('insertLineBreak')) return; editor.chain().setHardBreak().run(); },        active: () => false },
    { label: '—',  title: tjs('Divider (Ctrl+Alt+D)'),       action: () => { if (execOnMediaText('insertHorizontalRule')) return; editor.chain().setHorizontalRule().run(); },   active: () => false },
    { label: '{}', title: tjs('Code Block (Ctrl+Alt+C)'), action: () => editor.chain().focus().toggleCodeBlock().run(), active: () => editor.isActive('codeBlock') },
    null,
    { label: '😊', title: tjs('Emoji'),                    action: () => openEmojiPicker(editor),                 active: () => false },
    { label: '⛶', title: tjs('Fullscreen (Shift+F11)'),               action: () => toggleFullscreen(wrapper),               active: () => false },
    null,
    { label: '&lt;/&gt;', title: tjs('HTML Code'), action: '__HTML_TOGGLE__', active: () => false, isHtmlToggle: true },
  ];

  function render() {
    toolbar.innerHTML = '';
    buttons.forEach(btn => {
      if (btn === null) {
        const sep = document.createElement('span');
        sep.className = 'vl-toolbar-sep';
        toolbar.appendChild(sep);
        return;
      }
      if (btn.dropdown) {
        const wrapper = document.createElement('div');
        wrapper.className = 'vl-toolbar-dropdown';
        wrapper.addEventListener('mouseenter', () => {
          cancelCloseDropdowns();
          if (openDropdown !== btn.dropdownName) {
            openDropdown = btn.dropdownName;
            render();
          }
        });
        wrapper.addEventListener('mouseleave', () => {
          if (openDropdown === btn.dropdownName) {
            scheduleCloseDropdowns();
          }
        });

        const b = document.createElement('button');
        b.type = 'button';
        b.tabIndex = -1;
        b.title = btn.title;
        const label = typeof btn.label === 'function' ? btn.label() : btn.label;
        b.innerHTML = label;
        b.className = 'vl-toolbar-btn' + (btn.className ? ' ' + btn.className : '') + (btn.active() ? ' is-active' : '');
        b.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          openDropdown = openDropdown === btn.dropdownName ? null : btn.dropdownName;
          render();
        });

        const menu = document.createElement('div');
        menu.className = 'vl-toolbar-dropdown-menu';
        menu.style.display = openDropdown === btn.dropdownName ? 'grid' : 'none';
        btn.options.forEach(option => {
          if (option.visible && !option.visible()) return;
          const item = document.createElement('button');
          item.type = 'button';
          item.tabIndex = -1;
          item.className = 'vl-toolbar-btn vl-toolbar-dropdown-item' + (option.active() ? ' is-active' : '');
          item.title = option.title;
          const userLabel = String(option.title).replace(/\s*\(.*\)$/, '');
          item.innerHTML = `<span class="vl-toolbar-dropdown-icon">${option.label}</span><span class="vl-toolbar-dropdown-label">${userLabel}</span>`;
          item.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            openDropdown = null;
            if (!isInMediaText(document.activeElement)) {
              editor.chain().focus();
            }
            option.action();
            render();
          });
          menu.appendChild(item);
        });

        wrapper.appendChild(b);
        wrapper.appendChild(menu);
        toolbar.appendChild(wrapper);
        return;
      }
      const b = document.createElement('button');
      b.type = 'button';
      b.tabIndex = -1;
      b.title = btn.title;
      const label = typeof btn.label === 'function' ? btn.label() : btn.label;
      b.innerHTML = label;
      if (btn.isHtmlToggle) {
        b.className = 'vl-toolbar-btn vl-html-toggle-btn' + (editor.__isHtmlMode?.() ? ' is-active' : '');
        b.addEventListener('mousedown', (e) => {
          e.preventDefault();
          editor.__toggleHtml?.();
        });
      } else {
        b.className = 'vl-toolbar-btn' + (btn.className ? ' ' + btn.className : '') + (btn.active() ? ' is-active' : '');
        b.addEventListener('mousedown', (e) => {
          e.preventDefault();
          btn.action();
        });
      }
      toolbar.appendChild(b);
    });
  }

  editor.on('selectionUpdate', render);
  editor.on('transaction', render);
  render();

  return toolbar;
}

export { buildToolbar };
