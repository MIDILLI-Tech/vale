/**
 * Vale editor entry point: mounts Tiptap on textarea[data-editor], exposes window.* API.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Editor } from '@tiptap/core';
import { Highlight } from '@tiptap/extension-highlight';
import { Placeholder } from '@tiptap/extension-placeholder';
import { Subscript } from '@tiptap/extension-subscript';
import { Superscript } from '@tiptap/extension-superscript';
import { TableRow } from '@tiptap/extension-table-row';
import { TextAlign } from '@tiptap/extension-text-align';
import { Markdown } from '@tiptap/markdown';
import { StarterKit } from '@tiptap/starter-kit';
import { Suggestion } from '@tiptap/suggestion';
import Link from '@tiptap/extension-link';

const CustomLink = Link.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      // Tiptap's Link defaults target/rel from options.HTMLAttributes
      // (target="_blank") WITHOUT reading them back from the DOM — every
      // HTML round-trip would inject target="_blank" into plain links.
      // Read them from the element instead; render only when set.
      target: {
        default: null,
        parseHTML: element => element.getAttribute('target'),
        renderHTML: attributes => (attributes.target ? { target: attributes.target } : {}),
      },
      rel: {
        default: null,
        parseHTML: element => element.getAttribute('rel'),
        renderHTML: attributes => (attributes.rel ? { rel: attributes.rel } : {}),
      },
      'data-vl-type': {
        default: null,
        parseHTML: element => element.getAttribute('data-vl-type'),
        renderHTML: attributes => {
          if (!attributes['data-vl-type']) {
            return {};
          }
          return {
            'data-vl-type': attributes['data-vl-type'],
          };
        },
      },
      'data-vl-id': {
        default: null,
        parseHTML: element => element.getAttribute('data-vl-id'),
        renderHTML: attributes => {
          if (!attributes['data-vl-id']) {
            return {};
          }
          return {
            'data-vl-id': attributes['data-vl-id'],
          };
        },
      },
    };
  },
});

import { TableBubbleMenu, TextBubbleMenu, buildTableBubbleMenu, buildTextBubbleMenu } from './bubble-menus.js';
import { commentToMore, commentToNextpage, formEmbedToShortcode, htmlToSpacer, htmlToVideoEmbed, moreToComment, nextpageToComment, normalizeImageFigures, resolveFormNames, shortcodeToFormEmbed, shortcodeToVideoEmbed, spacerToHtml, videoEmbedHtmlForUrl, videoEmbedToHtml } from './converters.js';
import { HeroEmbed, HeroHeading, HeroSubtitle } from './embeds/hero-embed.js';
import { CarouselEmbed } from './embeds/carousel-embed.js';
import { CardGroup } from './embeds/card-group.js';
import { CardItem, CardTitle, CardDesc } from './embeds/card-item.js';
import { GenerateCouponEmbed } from './embeds/coupon-embed.js';
import { FormEmbed } from './embeds/form-embed.js';
import { LatestPostsEmbed } from './embeds/latest-posts-embed.js';
import { LinkButton } from './embeds/link-button.js';
import { LinkButtonGroup } from './embeds/link-button-group.js';
import { MediaText } from './embeds/media-text.js';
import { NewsletterEmbed } from './embeds/newsletter-embed.js';
import { OpenPixelEmbed } from './embeds/open-pixel-embed.js';
import { PagesEmbed } from './embeds/pages-embed.js';
import { ProductEmbed } from './embeds/product-embed.js';
import { RedirectEmbed } from './embeds/redirect-embed.js';
import { TocEmbed } from './embeds/toc-embed.js';
import { UnsubscribeEmbed } from './embeds/unsubscribe-embed.js';
import { VideoEmbed } from './embeds/video-embed.js';
import { ImageWithSize } from './extensions/image-with-size.js';
import { TableCellWithStyle, TableHeaderWithStyle } from './extensions/table-cells.js';
import { TableWithStyle } from './extensions/table-with-style.js';
import { TextColor } from './extensions/text-color.js';
import { initImageResize } from './image-resize.js';
import { openExternalMediaDialog, openMediaBrowser } from './media/browse.js';
import { setupEditorDropHandler, triggerFileUpload } from './media/upload.js';
import { MoreNode } from './nodes/more-node.js';
import { NextPageNode } from './nodes/next-page-node.js';
import { SpacerBlock } from './nodes/spacer-block.js';
import { createSlashMenuPlugin } from './slash-menu.js';
import { createShortcodePasteHandler } from './shortcode-paste.js';
import { insertTable } from './table-controls.js';
import { buildToolbar } from './toolbar.js';
import { initWordCounter, toggleFullscreen } from './ui/editor-chrome.js';
import { duplicateSelectedBlock } from './ui/duplicate.js';
import { openFormPicker } from './ui/form-picker.js';
import { openLinkButtonGroupSettings, activeButtonIndex } from './settings/link-button-group-settings.js';
import { openLinkDialog } from './ui/link-dialog.js';
import './styles.js';

/**
 * Vale — Tiptap block editor with slash commands.
 *
 * Replaces all <textarea data-editor> elements with a rich Tiptap editor.
 * The original textarea is kept hidden and synced so the form POST still works.
 *
 * Bundled for local loading; the dev module output is served from /assets/js/editor.js.
 *
 * Slash-command menu (type "/" at line start):
 *   Paragraph, H1, H2, H3, Bold, Italic, Blockquote,
 *   Code Block, Bullet List, Numbered List, Horizontal Rule
 */


function vlMountEditor(textarea) {
  // Guard: don't double-initialise
  if (textarea.dataset.editorMounted) return;
  textarea.dataset.editorMounted = "1";
    const wrapper = document.createElement("div");
    wrapper.className = "vl-editor-wrapper";
  {
    textarea.parentNode.insertBefore(wrapper, textarea);
    wrapper.appendChild(textarea);
    textarea.style.display = 'none';

    const editorEl = document.createElement('div');
    editorEl.className = 'vl-editor-content';
    wrapper.insertBefore(editorEl, textarea);

    // HTML code textarea (hidden by default)
    const htmlArea = document.createElement('textarea');
    htmlArea.className = 'vl-html-editor';
    htmlArea.style.display = 'none';
    wrapper.insertBefore(htmlArea, textarea);

    let isHtmlMode = false;
    // Cursor keeper across HTML-toggle round-trips (HTML offsets don't map
    // to doc positions, so remember the editor selection going out).
    let savedSelection = null;

    // Convert shortcodes to visual blocks before loading into editor.
    // normalizeImageFigures repairs legacy content where a captioned <figure> was
    // nested inside <p> (invalid HTML that made empty <p>s multiply on every save).
    const initialContent = htmlToSpacer(htmlToVideoEmbed(shortcodeToVideoEmbed(shortcodeToFormEmbed(commentToNextpage(commentToMore(normalizeImageFigures(textarea.value || '')))))));

    // Capture pristine content for dirty checking
    let initialRawContent = textarea.value;
    const tableMenu = buildTableBubbleMenu(wrapper);
    const textMenu = buildTextBubbleMenu(wrapper);

    if (window.VL_VERBOSE) console.log('[Vale] Initializing Vale editor.');
    let editorReady = false;
    const editor = new Editor({
      element: editorEl,
      editorProps: {
        // A pasted [form slug="..."], [toc], [product id="5"] … becomes its
        // visual block immediately instead of staying plain text until a
        // save+reload or a source-code round-trip.
        handlePaste: createShortcodePasteHandler(() => editor),
      },
      extensions: [
              StarterKit.configure({
                link: false,
              }),
              CustomLink.configure({
                openOnClick: false,
                // No automatic target/rel: links keep exactly what the DOM
                // (or the link dialog) sets — nothing is injected.
                HTMLAttributes: {
                  target: null,
                  rel: null,
                },
              }),
              TableBubbleMenu.configure({
                pluginKey: 'tableBubbleMenu',
                element: tableMenu.el,
                shouldShow: ({ editor }) => editor.isActive('table'),
                tippyOptions: { duration: 100, placement: 'top-start' },
              }),
              TextBubbleMenu.configure({
                pluginKey: 'textBubbleMenu',
                element: textMenu.el,
                shouldShow: ({ editor, from, to }) => {
                  const sel = editor.state?.selection || null;
                  if (!sel || sel.empty) return false;
                  if (from === to) return false;
                  if (editor.isActive('table')) return false;
                  if (editor.isActive('image')) return false;
                  return true;
                },
                tippyOptions: { duration: 100, placement: 'top' },
              }),
              Highlight.configure({ multicolor: true }),
              Subscript,
              Superscript,
              Placeholder.configure({ placeholder: tjs('Start writing, or type / for commands...') }),
              ImageWithSize.configure({ inline: true, allowBase64: false }),
              TextAlign.configure({ types: ['heading', 'paragraph', 'table', 'heroHeading', 'heroSubtitle'] }),
              TableWithStyle.configure({ resizable: true }),
              TableRow,
              TableHeaderWithStyle,
              TableCellWithStyle,
              FormEmbed,
              TocEmbed,
              PagesEmbed,
              VideoEmbed,
              MediaText,
              NewsletterEmbed,
              CarouselEmbed,
              ProductEmbed,
              GenerateCouponEmbed,
              LatestPostsEmbed,
              UnsubscribeEmbed,
              OpenPixelEmbed,
              RedirectEmbed,
              TextColor,
              LinkButton,
              LinkButtonGroup,
              CardGroup,
              CardItem,
              CardTitle,
              CardDesc,
              HeroEmbed,
              HeroHeading,
              HeroSubtitle,
              MoreNode,
              NextPageNode,
              SpacerBlock,
              Markdown.configure({
                html: true,
                tightLists: true,
                tightListClass: 'tight',
                bulletListMarker: '-',
                linkify: true,
                breaks: false,
              }),
            ],
      content: initialContent,
      onCreate() {
        editorReady = true;
        // Clicking a link should select it (and surface the bubble menu) instead
        // of navigating away. openOnClick is disabled above.
        editor.view.dom.addEventListener('click', (e) => {
          if (isHtmlMode) return;
          const target = e.target;
          if (!(target instanceof Element)) return;
          if (target.closest('[data-media-text]')) return;
          const a = target.closest('a[href]');
          if (!a || !a.closest('.ProseMirror')) return;
          e.preventDefault();
          e.stopPropagation();
          const view = editor.view;
          const start = view.posAtDOM(a, 0);
          if (start == null) return;
          const first = a.firstChild;
          const len = (first && first.nodeType === 3) ? first.textContent.length : 0;
          const end = start + len;
          if (end > start) {
            editor.commands.setTextSelection({ from: start, to: end });
          } else {
            editor.chain().focus();
          }
        });
      },
      onUpdate({ editor }) {
        // Ignore transactions fired during editor creation/normalization —
        // they are not user edits and must not trigger dirty-state guards.
        if (!editorReady || editor.__suppressUpdate) return;
        const html = spacerToHtml(nextpageToComment(moreToComment(normalizeImageFigures(videoEmbedToHtml(formEmbedToShortcode(editor.getHTML()))))));
        if (textarea.value !== html) {
          textarea.value = html;
          // Let dirty-state guards (vlDirtyGuard) know about programmatic changes
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
      },
    });

    tableMenu.render(editor);
    textMenu.render(editor);

    editor.on('selectionUpdate', () => {
      tableMenu.render(editor);
      textMenu.render(editor);
    });

    editor.on('transaction', () => {
      tableMenu.render(editor);
      textMenu.render(editor);
    });

    // Track last-focused editor so lpInsertVar() knows where to insert
    let pinnedToolbar = null;
    let pinnedWrapper = null;
    let pinnedWidth = 0;

    function onScrollPin() {
      if (!pinnedToolbar || !pinnedWrapper) return;
      const wrapRect = pinnedWrapper.getBoundingClientRect();
      const toolbarH = pinnedToolbar.offsetHeight;
      if (wrapRect.top <= 0 && wrapRect.bottom > toolbarH) {
        if (!pinnedToolbar.classList.contains('is-pinned')) {
          pinnedWidth = pinnedWrapper.offsetWidth;
          pinnedToolbar.classList.add('is-pinned');
          pinnedToolbar.style.width = pinnedWidth + 'px';
        }
      } else {
        if (pinnedToolbar.classList.contains('is-pinned')) {
          pinnedToolbar.classList.remove('is-pinned');
          pinnedToolbar.style.width = '';
        }
      }
    }

    editor.on('focus', () => {
      window._lpLastEditor = editor;
      const toolbar = wrapper.querySelector('.vl-editor-toolbar');
      if (toolbar) {
        pinnedToolbar = toolbar;
        pinnedWrapper = wrapper;
        window.addEventListener('scroll', onScrollPin, { passive: true, capture: true });
        onScrollPin();
      }
    });
    editor.on('blur-sm', () => {
      if (pinnedToolbar) {
        pinnedToolbar.classList.remove('is-pinned');
        pinnedToolbar.style.width = '';
      }
      pinnedToolbar = null;
      pinnedWrapper = null;
      window.removeEventListener('scroll', onScrollPin, { capture: true });
    });

    // Ctrl+K → link dialog | Shift+F11 → fullscreen
    editorEl.addEventListener('keydown', e => {
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key === 'k') {
        e.preventDefault();
        openLinkButtonSettingsForSelection(editor);
      } else if (e.shiftKey && !e.ctrlKey && !e.altKey && e.key === 'F11') {
        e.preventDefault();
        toggleFullscreen(wrapper);
      }
    });

    // Additional keyboard shortcuts for toolbar-only actions
    editorEl.addEventListener('keydown', e => {
      const c = (e.ctrlKey || e.metaKey);
      if (!c) return;
      if (e.altKey && e.key === 't') { e.preventDefault(); insertTable(editor); }
      else if (e.altKey && e.key === 'd') { e.preventDefault(); editor.chain().focus().setHorizontalRule().run(); }
      else if (e.altKey && e.key === 'm') { e.preventDefault(); openMediaBrowser(editor); }
      else if (e.altKey && e.key === 'v') { e.preventDefault(); openExternalMediaDialog({ onSelect: (url) => { const isImg = url.match(/\.(jpeg|jpg|gif|png|webp|svg)($|\?)/i); if (isImg) { editor.chain().focus().setImage({ src: url }).run(); } else { editor.chain().focus().insertContent({ type: 'videoEmbed', attrs: { html: videoEmbedHtmlForUrl(url) } }).run(); } } }); }
      else if (e.altKey && e.key === 'f') { e.preventDefault(); openFormPicker(editor); }
      else if (e.shiftKey && e.key === 'U') { e.preventDefault(); triggerFileUpload(editor); }
    });

    // When Ctrl+K is pressed inside a link button, open the button group's
    // settings dialog (with internal/external link per button) instead of
    // inserting a nested <a> link. A button is a single link — no nesting.
    function openLinkButtonSettingsForSelection(ed) {
      const $from = ed.state.selection?.$from;
      let groupPos = -1;
      if ($from) {
        for (let d = $from.depth; d >= 0; d--) {
          const n = $from.node(d);
          if (n.type.name === 'linkButtonGroup') { groupPos = $from.before(d); break; }
        }
      }
      if (groupPos >= 0) {
        // Compute the focused button before the selection changes below.
        const initial = activeButtonIndex(ed, groupPos);
        ed.chain().focus().setNodeSelection(groupPos).run();
        openLinkButtonGroupSettings(ed, groupPos, initial);
        return;
      }
      openLinkDialog(ed);
    }

    // HTML toggle handler — shared with toolbar
    function toggleHtmlMode() {
      isHtmlMode = !isHtmlMode;
      if (isHtmlMode) {
        // Switch to HTML code mode — show shortcode text, not data-form-slug divs
        try {
          const sel = editor.state.selection;
          savedSelection = { from: sel.from, to: sel.to };
        } catch (e) { savedSelection = null; }
        htmlArea.value = nextpageToComment(moreToComment(normalizeImageFigures(videoEmbedToHtml(formEmbedToShortcode(editor.getHTML())))));
        editorEl.style.display = 'none';
        htmlArea.style.display = 'block';
        // The gear + resize corner live inside the image figures, so hiding
        // the editor hides them too — nothing floats over the HTML area.
        htmlArea.focus();
      } else {
        // Switch back to WYSIWYG mode — parse shortcodes into visual blocks
        const content = htmlToVideoEmbed(shortcodeToVideoEmbed(shortcodeToFormEmbed(commentToNextpage(commentToMore(normalizeImageFigures(htmlArea.value))))));
        editor.commands.setContent(content, false);
        textarea.value = spacerToHtml(nextpageToComment(moreToComment(normalizeImageFigures(videoEmbedToHtml(formEmbedToShortcode(editor.getHTML()))))));
        htmlArea.style.display = 'none';
        editorEl.style.display = '';
        try {
          if (savedSelection) {
            const max = editor.state.doc.content.size;
            const from = Math.max(0, Math.min(savedSelection.from, max));
            const to = Math.max(0, Math.min(savedSelection.to, max));
            editor.chain().focus().setTextSelection({ from, to }).run();
          } else {
            editor.commands.focus();
          }
        } catch (e) { editor.commands.focus(); }
      }
      // Update toggle button appearance
      const btn = wrapper.querySelector('.vl-html-toggle-btn');
      if (btn) {
        btn.classList.toggle('is-active', isHtmlMode);
      }
    }

    // Expose toggle for toolbar builder
    editor.__toggleHtml = toggleHtmlMode;
    editor.__isHtmlMode = () => isHtmlMode;

    // Resolve form names for any embedded form blocks
    // Register instance
    if (textarea.id) {
      window.lpEditors[textarea.id] = editor;
    }

    resolveFormNames(editor);

    // Add slash suggestion plugin
    try {
      const slashPlugin = createSlashMenuPlugin(editor);
      editor.registerPlugin(slashPlugin);
    } catch(e) {
      // Suggestion may not be available in all builds — fail gracefully
      console.warn('Slash commands not loaded:', e);
    }

    // Toolbar
    const toolbar = buildToolbar(editor, wrapper);
    wrapper.insertBefore(toolbar, editorEl);

    // Word & character counter
    initWordCounter(editor, wrapper);

    // Image drag resize
    initImageResize(editor);

    // External file drag & drop upload
    setupEditorDropHandler(editor, wrapper);

    // Double-click embed blocks → open settings
    editorEl.addEventListener('dblclick', (e) => {      const target = e.target;
      if (!target || !target.closest('.ProseMirror')) return;
      const embed = target.closest('.vl-newsletter-embed-block, .vl-carousel-embed-block, .vl-product-embed-block, .vl-redirect-embed-block, .vl-link-button-group-block, .vl-card-group-block, .vl-hero-embed-block, .vl-media-text-block, .vl-video-embed-block');
      if (!embed) return;
      e.preventDefault();
      e.stopPropagation();
      // A dblclick leaves a native DOM range inside the block; ProseMirror
      // tries to map it onto the (atom) node and ends up with a selection
      // that no longer points at the current document ("must point at the
      // current document" when the modal steals focus). Clear the native
      // range first, then open settings on the next tick — outside the
      // dblclick's own DOM-mutation handling.
      try {
        const sel = window.getSelection();
        if (sel) sel.removeAllRanges();
      } catch (_) { /* ignore */ }
      const btn = embed.querySelector('button[data-settings]');
      if (!btn) return;
      // If this dblclick immediately followed a settings-modal close, the
      // second click's mousedown already landed on the closing modal — ignore
      // it instead of reopening on a stale selection.
      if (window.__vlSettingsModalClosedAt && (Date.now() - window.__vlSettingsModalClosedAt) < 350) return;
      setTimeout(() => { try { btn.click(); } catch (_) { /* ignore */ } }, 0);
    });

    // Ctrl+Shift+D → duplicate the selected block (or the block under the caret)
    editorEl.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'D' || e.key === 'd')) {
        if (duplicateSelectedBlock(editor)) e.preventDefault();
      }
    });

    // Sync on form submit — reset dirty state after syncing so beforeunload doesn't warn on save/preview
    textarea.closest('form')?.addEventListener('submit', () => {
      if (isHtmlMode) {
        textarea.value = htmlArea.value;
      } else {
        textarea.value = spacerToHtml(nextpageToComment(moreToComment(normalizeImageFigures(videoEmbedToHtml(formEmbedToShortcode(editor.getHTML()))))));
      }
      initialRawContent = textarea.value;
    });

    // Also sync HTML area changes back to hidden textarea
    htmlArea.addEventListener('input', () => {
      textarea.value = htmlArea.value;
    });

    // Untrusted input events (from preview buttons) sync textarea value
    // but must NOT reset dirty state — preview doesn't save anything
    textarea.addEventListener('input', (e) => {
      if (!e.isTrusted) {
        // textarea.value is already updated by the event; do nothing else
      }
    });

    // Warn before navigating away with unsaved content
    window.addEventListener('beforeunload', (e) => {
      if (textarea.value !== initialRawContent) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
  }
}

// Expose globally so dynamic pages (e.g. Alpine x-for) can init editors on demand
window.vlInitEditor = vlMountEditor;

// Registry to track editor instances by their underlying textarea ID
window.lpEditors = window.lpEditors || {};

// Expose shortcode converters so copy-from-original buttons can pre-process content
window.vlSetEditorContent = function(textareaId, body) {
  var editor = window.lpEditors?.[textareaId];
  if (!editor) return false;
  var processed = htmlToVideoEmbed(shortcodeToVideoEmbed(shortcodeToFormEmbed(commentToNextpage(commentToMore(normalizeImageFigures(body || ''))))));
  try {
    editor.commands.setContent(processed, false);
    return true;
  } catch(e) {
    return false;
  }
};

// Fallback: toggle HTML mode to force re-parse (slower but always works)
window.vlSetEditorContentToggle = function(textareaId, body) {
  var editor = window.lpEditors?.[textareaId];
  if (!editor) return false;
  try {
    var wrapper = editor.view?.dom?.closest('.vl-editor-wrapper');
    if (!wrapper) return false;
    var htmlArea = wrapper.querySelector('.vl-html-editor');
    if (!htmlArea) return false;
    var isHtml = editor.__isHtmlMode ? editor.__isHtmlMode() : false;
    if (isHtml) {
      htmlArea.value = body;
      return true;
    }
    htmlArea.value = body;
    editor.__toggleHtml();
    editor.__toggleHtml();
    return true;
  } catch(e) {
    return false;
  }
};


/**
 * Insert text at the cursor of the last-focused Vale editor.
 * Called by template-variable code tags in campaign/automation forms.
 * To add a new variable: add a <code data-tag="[your_tag]"> element in the
 * relevant Twig template — no JS changes needed.
 */
window.lpInsertVar = function(text) {
  if (window._lpLastEditor) {
    window._lpLastEditor.chain().focus().insertContent(text).run();
  }
};

/**
 * Returns true when any modal/overlay is currently visible.
 * Used by layout.twig sidebar ESC handler so it yields to open modals.
 * JS-generated overlays are expected to have z-index ≥ 9000 (inline style).
 * Alpine modals use .fixed.inset-0.z-50 classes + x-show sets display:none when hidden.
 */
window.lpHasModal = function() {
  // JS-generated overlays from editor.js dialogs
  if (document.querySelector('[style*="z-index: 9999"], [style*="z-index:9999"]')) return true;
  // Alpine x-show modals (.fixed.inset-0 without display:none)
  const alpineModals = document.querySelectorAll('.fixed.inset-0');
  for (const el of alpineModals) {
    if (el.style.display !== 'none') return true;
  }
  return false;
};

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('textarea[data-editor]').forEach(vlMountEditor);
});

export { vlMountEditor };
