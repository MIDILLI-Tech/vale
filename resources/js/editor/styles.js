/**
 * Injected editor CSS (spin animations, embed chrome, dark-mode variants). Side-effect module.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
// ── Spin & refresh-icon animation ─────────────────────────────────────────
(function() {
  const style = document.createElement('style');
  style.textContent = `
    @keyframes editorSpin{to{transform:rotate(360deg)}}
    .vl-picker-item:hover { background-color: #f3f4f6 !important; }
    .dark .vl-picker-item:hover { background-color: #374151 !important; }
    .dark .vl-picker-item { border-bottom-color: #374151 !important; }
    .vl-btn-spin{animation:editorSpin .8s linear infinite;display:inline-block;vertical-align:middle;transform-origin:center}
    .vl-icon-spin{animation:editorSpin .8s linear infinite;display:inline-block;vertical-align:middle;transform-origin:center}

    /* Shared block chrome. Contains matching keeps the style active after
       ProseMirror appends its selection class to the wrapper. */
    [class*="embed-block"], .vl-link-button-group-block, .vl-link-button-block, .vl-card-group-block, .vl-more-divider, .vl-nextpage-divider, .vl-spacer-block { cursor: grab; border-radius: 12px; }
    .vl-media-text-block { border-radius: 12px; cursor: default; }
    .vl-media-text-body { cursor: default; }
    .vl-media-text-content { cursor: text; user-select: text; }
    [class*="embed-block"], .vl-media-text-block, .vl-link-button-group-block, .vl-card-group-block { border: 2px dashed #e5e7eb; margin: 20px 0; overflow: hidden; background: #f9fafb; }
    .vl-link-button-block { margin: 4px 0; } /* inner group child — group owns spacing */
    /* Horizontal groups: give each button an equal flex cell (mirror the frontend
       render so per-button alignment / fullWidth are visible in the editor preview). */
    .vl-link-button-group-block[data-layout="horizontal"] .vl-link-button-block { flex: 1 1 0; min-width: 0; }
    .vl-link-button-block .vl-editable-button { cursor: text; user-select: text; }
    .vl-link-button-block .vl-link-button-body { cursor: text; user-select: text; }
    .vl-link-button-content { padding: 12px; }
    .vl-group-current-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
    .vl-group-current-label b { color: #374151; font-weight: 600; }
    .dark .vl-group-current-label { color: #9ca3af; }
    .dark .vl-group-current-label b { color: #e5e7eb; }
    .dark [class*="embed-block"], .dark .vl-media-text-block, .dark .vl-link-button-group-block, .dark .vl-card-group-block { border-color: #374151; background: #111827; }

    /* Card grid preview (mirrors the frontend flex render) */
    .vl-card-group-content { padding: 12px; }
    .vl-card-group-content > .vl-card-item-block { flex: 0 1 280px; min-width: 0; max-width: 100%; }
    .vl-card-item-block { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; background: #fff; margin: 4px 0; cursor: default; }
    .dark .vl-card-item-block { border-color: #374151; background: #1f2937; }
    .vl-card-item-tools { display: flex; gap: 4px; margin-bottom: 8px; }
    .vl-card-item-tools button { background: #f3f4f6; border: none; border-radius: 4px; padding: 2px 8px; cursor: pointer; color: #6b7280; font-size: 12px; }
    .vl-card-del-btn { background: #dc2626 !important; color: #fff !important; border: none; }
    .vl-card-del-btn:hover { background: #b91c1c !important; }
    .dark .vl-card-del-btn { background: #ef4444 !important; color: #fff !important; }
    .dark .vl-card-del-btn:hover { background: #dc2626 !important; }
    .vl-card-item-tools button:hover { background: #e5e7eb; color: #111827; }
    .dark .vl-card-item-tools button { background: #374151; color: #9ca3af; }
    .dark .vl-card-item-tools button:hover { background: #4b5563; color: #fff; }
    .vl-card-image-wrap { position: relative; margin-bottom: 8px; border-radius: 8px; overflow: hidden; background: #f3f4f6; aspect-ratio: 16/9; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 12px; cursor: pointer; }
    .vl-card-image-wrap img { cursor: pointer; }
    .vl-card-item-tools button, .vl-card-remove-img { cursor: pointer; }
    .vl-mt-remove-img { cursor: pointer; }
    .dark .vl-card-image-wrap { background: #111827; }
    .vl-card-image-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .vl-card-image-wrap video { width: 100%; height: 100%; display: block; }
    .vl-card-remove-img { position: absolute; top: 4px; right: 4px; width: 20px; height: 20px; background: rgba(239, 68, 68, 0.9); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
    .vl-card-image-wrap:hover .vl-card-remove-img { opacity: 1; }
    .vl-card-link-badge { font-size: 11px; color: #6b7280; margin-bottom: 8px; word-break: break-all; }
    .dark .vl-card-link-badge { color: #9ca3af; }
    .vl-card-item-body { cursor: text; user-select: text; display: flex; flex-direction: column; }
    .vl-card-title { font-weight: 700; font-size: 15px; margin: 0 0 4px; }
    .vl-card-desc { font-size: 13px; color: #4b5563; margin-top: auto; }
    .dark .vl-card-desc { color: #9ca3af; }
    .vl-card-settings-preview { border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; background: #f3f4f6; min-height: 80px; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 12px; }
    .dark .vl-card-settings-preview { border-color: #374151; background: #111827; }
    .vl-card-settings-preview img { width: 100%; height: auto; display: block; }

    /* Shared embed header chrome */
    .vl-carousel-header::before, .vl-newsletter-header::before, .vl-product-header::before, .vl-media-text-header::before, .vl-link-button-group-header::before, .vl-card-group-header::before { content: '⠿'; margin-right: 6px; opacity: 0.5; cursor: grab; user-select: none; font-size: 12px; shrink: 0; }
    .vl-form-embed-label::before, .vl-toc-embed-label::before, .vl-pages-embed-label::before { content: '⠿'; margin-right: 8px; opacity: 0.5; cursor: grab; user-select: none; font-size: 12px; }

    .vl-carousel-header, .vl-newsletter-header, .vl-product-header, .vl-media-text-header, .vl-link-button-group-header, .vl-card-group-header { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; font-size: 13px; font-weight: 600; color: #374151; cursor: grab; }
    .dark .vl-carousel-header, .dark .vl-newsletter-header, .dark .vl-product-header, .dark .vl-media-text-header, .dark .vl-link-button-group-header, .dark .vl-card-group-header { background: #1f2937; border-color: #374151; color: #d1d5db; }
    .vl-carousel-header button, .vl-newsletter-header button, .vl-product-header button, .vl-media-text-header button, .vl-link-button-group-header button, .vl-card-group-header button { background: none; border: none; padding: 4px; cursor: pointer; color: #6b7280; border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .vl-carousel-header button:hover, .vl-newsletter-header button:hover, .vl-product-header button:hover, .vl-media-text-header button:hover, .vl-link-button-group-header button:hover, .vl-card-group-header button:hover { background: #e5e7eb; color: #111827; }
    .dark .vl-carousel-header button:hover, .dark .vl-newsletter-header button:hover, .dark .vl-product-header button:hover, .dark .vl-media-text-header button:hover, .dark .vl-link-button-group-header button:hover, .dark .vl-card-group-header button:hover { background: #374151; color: #fff; }
    .dark .vl-carousel-header button, .dark .vl-newsletter-header button, .dark .vl-product-header button, .dark .vl-media-text-header button, .dark .vl-link-button-group-header button, .dark .vl-card-group-header button { color: #9ca3af; }
    .vl-carousel-body { padding: 16px; }
    .vl-carousel-empty { padding: 40px; text-align: center; color: #9ca3af; font-size: 14px; cursor: pointer; border: 2px dashed #d1d5db; border-radius: 8px; transition: all 0.2s; }
    .vl-carousel-empty:hover { border-color: #9ca3af; color: #6b7280; background: #f3f4f6; }
    .vl-carousel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 12px; }
    .vl-carousel-item { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; background: #fff; }
    .vl-carousel-item img { width: 100%; height: 100%; object-fit: cover; }
    .vl-carousel-remove-img { position: absolute; top: 4px; right: 4px; width: 20px; height: 20px; background: rgba(239, 68, 68, 0.9); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
    .vl-carousel-item:hover .vl-carousel-remove-img { opacity: 1; }
    .vl-mt-media-bg { background: #f3f4f6; }
    .dark .vl-mt-media-bg { background: #374151; }
    .dark .vl-media-text-body { background: transparent; }
    .dark .vl-media-text-content { color: #d1d5db; }
    .vl-media-text-content ul { list-style: disc; padding-left: 1.5rem; margin: 0.5rem 0; }
    .vl-media-text-content ol { list-style: decimal; padding-left: 1.5rem; margin: 0.5rem 0; }
    .vl-media-text-content li { margin-bottom: 0.25rem; }
    .vl-media-text-content blockquote { border-left: 3px solid #cbd5e1; padding-left: 1rem; margin: 0.75rem 0; color: #64748b; font-style: italic; }
    .dark .vl-media-text-content blockquote { border-color: #475569; color: #94a3b8; }
    .vl-media-text-content h1 { font-size: 1.5rem; font-weight: 700; margin: 0.75rem 0 0.5rem; }
    .vl-media-text-content h2 { font-size: 1.25rem; font-weight: 700; margin: 0.6rem 0 0.4rem; }
    .vl-media-text-content h3 { font-size: 1.1rem; font-weight: 600; margin: 0.5rem 0 0.3rem; }
    .vl-media-text-content p { margin: 0.4rem 0; }
    .vl-media-text-content a { color: #3b82f6; text-decoration: underline; }
    .dark .vl-media-text-image { background: transparent; }
    .vl-mt-remove-img { position: absolute; top: 4px; right: 4px; width: 20px; height: 20px; background: rgba(239, 68, 68, 0.9); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; z-index: 5; }
    .vl-media-text-image:hover .vl-mt-remove-img { opacity: 1; }
    /* Media + Text: stack columns on narrow screens. The row layout is inline,
       so the override needs !important. Structural selector (no classes) also
       covers legacy saved markup. */
    @media (max-width: 640px) {
      div[data-media-text] > div:not(.vl-media-text-header), .vl-media-text-body > div { flex-direction: column !important; align-items: stretch !important; }
      div[data-media-text] [data-mt-col="0"], div[data-media-text] .vl-media-text-image { flex: 0 0 auto !important; width: 100% !important; }
    }
    .vl-carousel-add-btn { aspect-ratio: 1; border: 2px dashed #d1d5db; border-radius: 8px; background: none; color: #9ca3af; font-size: 24px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
    .vl-carousel-add-btn:hover { border-color: #9ca3af; color: #6b7280; background: #f3f4f6; }
    .dark .vl-carousel-item { background: #1f2937; border-color: #374151; }
    .dark .vl-carousel-add-btn:hover { background: #374151; }
    .dark .vl-carousel-empty:hover { background: #374151; }

    /* Redirect block: multi-block warning (only the first block navigates) */
    .vl-redirect-warn { margin: 0 0 8px; padding: 6px 10px; border-radius: 6px; background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; font-size: 12px; }
    .dark .vl-redirect-warn { background: #451a03; border-color: #92400e; color: #fcd34d; }

    /* Newsletter body/meta (header chrome comes from the shared rules above) */    .vl-newsletter-body { padding: 16px; }
    .vl-newsletter-input[disabled],
    .vl-newsletter-btn[disabled] { opacity: 0.6; }
    .dark .vl-newsletter-input[disabled],
    .dark .vl-newsletter-btn[disabled] { opacity: 0.75; }
    .vl-newsletter-meta { font-size: 10px; color: #9ca3af; }
    .dark .vl-newsletter-meta { color: #6b7280; }

    /* Product settings info box + refresh footer */
    .vl-product-settings-info { margin-top: 12px; padding: 8px 10px; background: #f9fafb; border-radius: 6px; font-size: 12px; color: #6b7280; line-height: 1.5; }
    .dark .vl-product-settings-info { background: #111827; color: #9ca3af; }

    /* Media browser multi-select pager */
    .vl-media-pager-info { font-size: 12px; color: #6b7280; }
    .dark .vl-media-pager-info { color: #9ca3af; }

    /* Table properties dialog — border row: [width] [style] [hex + swatch] */
    /* Border row: one labeled column per control (Size / Style / Color). */
    .vl-table-border-grid { display: grid; grid-template-columns: 84px 116px minmax(0, 1fr); gap: 8px; }
    .vl-table-border-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .vl-table-border-field > .vl-input,
    .vl-table-border-field > .vl-select { width: 100%; min-width: 0; box-sizing: border-box; }
    .vl-table-border-label { font-size: 11px; line-height: 1.2; color: #6b7280; }
    .dark .vl-table-border-label { color: #9ca3af; }
    .vl-tb-color-wrap { display: flex; gap: 6px; align-items: center; min-width: 0; }
    .vl-tb-color-wrap .vl-tb-color-hex { width: auto; flex: 1 1 auto; min-width: 0; }
    .vl-tb-color-swatch { width: 36px; height: 36px; flex: 0 0 auto; padding: 0; border: 1px solid var(--vl-input-border, #d1d5db); border-radius: 8px; cursor: pointer; }
    .dark .vl-tb-color-swatch { border-color: #4b5563; }

    /* In-canvas image resize corner (bottom-right of the image) + settings
       gear (top-right). Both live inside the figure (absolute, like the drag
       grip): they scroll with the image and show only on the selected one. */
    .vl-img-resize-handle {
      position: absolute; z-index: 5; width: 14px; height: 14px;
      background: #3b82f6; border: 2px solid #fff; border-radius: 3px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
      cursor: nwse-resize; display: none; pointer-events: none;
      padding: 0;
    }
    .dark .vl-img-resize-handle { border-color: #1f2937; }
    .vl-image-figure.ProseMirror-selectednode .vl-img-resize-handle { display: block; }
    .vl-img-settings-gear {
      position: absolute; top: 6px; right: 6px; z-index: 6;
      display: none; align-items: center; justify-content: center;
      width: 24px; height: 24px; padding: 0;
      background: #3b82f6; color: #fff;
      border: none; border-radius: 2px; cursor: pointer; font-size: 14px; line-height: 1;
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
      user-select: none; -webkit-user-select: none;
    }
    .vl-image-figure.ProseMirror-selectednode .vl-img-settings-gear { display: flex; }

    /* Image drag grip — only on the selected (standalone) image */
    .vl-image-figure { position: relative; }
    .vl-img-drag-grip {
      position: absolute; top: 6px; left: 6px; z-index: 5;
      display: none; align-items: center; justify-content: center;
      width: 26px; height: 26px;
      background: rgba(17, 24, 39, 0.75); color: #fff;
      border-radius: 6px; cursor: grab; font-size: 14px; line-height: 1;
      user-select: none; -webkit-user-select: none; touch-action: none;
    }
    .vl-image-figure.ProseMirror-selectednode .vl-img-drag-grip { display: flex; }
    .vl-image-figure.is-dragging { opacity: 0.5; }
    .vl-img-drag-grip:active { cursor: grabbing; }
    .dark .vl-img-drag-grip { background: rgba(59, 130, 246, 0.9); }

    /* Form/TOC/Pages embeds: dashed block + solid header bar — same pattern
       as carousel/newsletter/media-text so every block looks consistent. */
    .vl-form-embed-block, .vl-toc-embed-block, .vl-pages-embed-block, .vl-video-embed-block { display: block; padding: 0; }
    .vl-video-embed-inner { padding: 12px; }
    .vl-video-embed-inner iframe, .vl-video-embed-inner video { width: 100%; aspect-ratio: 16/9; border: 0; display: block; }
    .vl-embed-header-bar {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 12px;
      background: #f3f4f6; border-bottom: 1px solid #e5e7eb;
      font-size: 13px; font-weight: 600; color: #374151; cursor: grab;
    }
    .vl-embed-header-bar button {
      background: none; border: none; padding: 4px; cursor: pointer; color: #6b7280;
      border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: all 0.2s;
    }
    .vl-embed-header-bar button:hover { background: #e5e7eb; color: #111827; }
    .vl-embed-header-bar select { font-size: 12px; }
    .vl-embed-header-bar .vl-form-embed-label,
    .vl-embed-header-bar .vl-toc-embed-label,
    .vl-embed-header-bar .vl-pages-embed-label { flex: 1; font-size: 13px; font-weight: 600; }
    .dark .vl-embed-header-bar { background: #1f2937; border-color: #374151; color: #d1d5db; }
    .dark .vl-embed-header-bar button { color: #9ca3af; }
    .dark .vl-embed-header-bar button:hover { background: #374151; color: #fff; }

    /* Upload progress toast (image/video/file uploads from the editor) */
    .vl-upload-toast {
      position: fixed; left: 50%; bottom: 24px; transform: translate(-50%, 12px);
      min-width: 260px; max-width: 420px; padding: 10px 14px;
      background: #ffffff; color: #111827;
      border: 1px solid #e5e7eb; border-radius: 10px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
      font-size: 13px; opacity: 0; pointer-events: none;
      transition: opacity 0.2s ease, transform 0.2s ease; z-index: 10001;
    }
    .vl-upload-toast.is-visible { opacity: 1; transform: translate(-50%, 0); }
    .vl-upload-toast.is-error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .vl-upload-toast-row { display: flex; align-items: center; gap: 8px; }
    .vl-upload-label { font-weight: 600; white-space: nowrap; }
    .vl-upload-name {
      flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      color: #6b7280; font-size: 12px; text-align: right;
    }
    .vl-upload-toast.is-error .vl-upload-name { display: none; }
    .vl-upload-bar { margin-top: 8px; height: 4px; border-radius: 2px; background: #e5e7eb; overflow: hidden; }
    .vl-upload-bar-fill { width: 0; height: 100%; background: #2563eb; transition: width 0.15s ease; }
    .vl-upload-toast.is-error .vl-upload-bar-fill { background: #dc2626; }
    .vl-upload-spinner {
      width: 14px; height: 14px; flex: none; border-radius: 50%;
      border: 2px solid #bfdbfe; border-top-color: #2563eb;
      animation: vl-upload-spin 0.8s linear infinite;
    }
    @keyframes vl-upload-spin { to { transform: rotate(360deg); } }
    .dark .vl-upload-toast { background: #1f2937; color: #f3f4f6; border-color: #374151; }
    .dark .vl-upload-toast.is-error { background: #451a03; border-color: #7f1d1d; color: #fecaca; }
    .dark .vl-upload-name { color: #9ca3af; }
    .dark .vl-upload-bar { background: #374151; }
    .dark .vl-upload-bar-fill { background: #3b82f6; }
    .dark .vl-upload-spinner { border-color: #1e3a8a; border-top-color: #60a5fa; }

    /* Media+Text: header must sit flush with the block edges (rounded top
       corners via block overflow) — editor.css gives the block padding. */
    .vl-media-text-block { padding: 0; }

    /* Product picker: status rows (loading / empty / error) span the grid */
    .vl-product-results > .vl-picker-status,
    .vl-product-results > p { grid-column: 1 / -1; }

    /* Product embed preview (image box with centered loading state, info column) */
    .vl-product-preview { display: flex; gap: 12px; align-items: center; padding: 4px 2px; }
    .vl-product-preview-img {
      flex: 0 0 72px; width: 72px; height: 72px; border-radius: 8px;
      background: #e5e7eb; overflow: hidden;
      display: flex; align-items: center; justify-content: center;
    }
    .vl-product-preview-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .vl-product-preview-loading { font-size: 10px; color: #6b7280; display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .vl-product-preview-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .vl-product-preview-price { font-size: 12px; font-weight: 600; color: #10b981; }
    .vl-product-preview-original { font-weight: 400; color: #9ca3af; }
    .vl-product-preview-badge { font-size: 11px; font-weight: 600; color: #b45309; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; padding: 2px 8px; align-self: flex-start; }
    .dark .vl-product-preview-img { background: #374151; }
    .dark .vl-product-preview-loading { color: #9ca3af; }
    .dark .vl-product-preview-original { color: #9ca3af; }
    .dark .vl-product-preview-badge { color: #fcd34d; background: #451a03; border-color: #92400e; }

    .vl-media-card { position: relative; }
    .dark .vl-media-card { background: #1f2937; }
    .dark .vl-media-card:hover { background: #1f2937; }
    .vl-media-card.is-selected { outline: 2px solid #3b82f6; outline-offset: -2px; border-color: #3b82f6; background: #eff6ff; }
    .dark .vl-media-card.is-selected { background: #1f293b; }
    .vl-media-card-check { position: absolute; top: 4px; right: 4px; width: 20px; height: 20px; background: #3b82f6; color: #fff; border-radius: 50%; display: none; align-items: center; justify-content: center; font-size: 12px; z-index: 10; }
    .vl-media-card.is-selected .vl-media-card-check { display: flex; }

    /* Email Embed Blocks Light/Dark Mode Styling */
    .vl-embed-preview-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; color: #334155; }
    .dark .vl-embed-preview-card { background: #1e293b; border-color: #334155; color: #cbd5e1; }

    .vl-coupon-preview-box { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f3f4f6; padding: 10px 14px; border-radius: 6px; border: 1.5px dashed #3b82f6; }
    .dark .vl-coupon-preview-box { background: #111827; border-color: #60a5fa; }
    .vl-coupon-code-title { font-weight: 700; font-size: 15px; color: #1d4ed8; }
    .dark .vl-coupon-code-title { color: #60a5fa; }
    .vl-coupon-meta-text { font-size: 12px; color: #4b5563; margin-top: 2px; }
    .dark .vl-coupon-meta-text { color: #9ca3af; }
    .vl-coupon-badge { font-size: 11px; background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 4px; font-weight: 600; white-space: nowrap; }
    .dark .vl-coupon-badge { background: #1e3a8a; color: #93c5fd; }

    .vl-embed-mono-tag { font-size: 13px; font-family: monospace; color: #334155; font-weight: 600; }
    .dark .vl-embed-mono-tag { color: #93c5fd; }
    .vl-embed-sub-text { font-size: 11px; color: #64748b; }
    .dark .vl-embed-sub-text { color: #9ca3af; }

    /* Coupon Modal Builder Dark Mode */
    .dark .vl-coupon-builder-wrap { background: #111827 !important; border-color: #374151 !important; }
    .dark .vl-coupon-builder-label { color: #9ca3af !important; }

    /* Coupon autocomplete dropdown (order product search style) */
    .vl-coupon-dd { position: absolute; z-index: 60; left: 0; right: 0; top: calc(100% + 4px); max-height: 240px; overflow-y: auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 8px 20px rgba(0,0,0,.12); }
    .dark .vl-coupon-dd { background: #1f2937; border-color: #374151; }
    .vl-coupon-dd-item { display: block; width: 100%; text-align: left; padding: 8px 12px; font-size: 13px; border: none; border-bottom: 1px solid #f3f4f6; background: transparent; color: #111827; cursor: pointer; }
    .vl-coupon-dd-item:last-child { border-bottom: none; }
    .vl-coupon-dd-item:hover { background: #f3f4f6; }
    .dark .vl-coupon-dd-item { color: #f3f4f6; border-color: #374151; }
    .dark .vl-coupon-dd-item:hover { background: #374151; }
    .vl-coupon-dd-empty { padding: 8px 12px; font-size: 13px; color: #9ca3af; }

    /* Fix dropdown select & option dark mode in modals */
    .dark select.vl-input, .dark .vl-modal select { background-color: #1f2937 !important; color: #f9fafb !important; border-color: #374151 !important; }
    .dark select.vl-input option, .dark .vl-modal select option { background-color: #1f2937 !important; color: #f9fafb !important; }

    /* Image & figure alignment in editor */
    .vl-editor-content .ProseMirror [style*="text-align: center"] img,
    .vl-editor-content .ProseMirror [style*="text-align:center"] img,
    .vl-editor-content .ProseMirror [style*="text-align: center"] figure,
    .vl-editor-content .ProseMirror [style*="text-align:center"] figure,
    .vl-editor-content .ProseMirror [style*="text-align: center"] .vl-image-figure,
    .vl-editor-content .ProseMirror [style*="text-align:center"] .vl-image-figure {
      display: inline-block;
      margin-left: auto;
      margin-right: auto;
    }
    .vl-editor-content .ProseMirror [style*="text-align: right"] img,
    .vl-editor-content .ProseMirror [style*="text-align:right"] img,
    .vl-editor-content .ProseMirror [style*="text-align: right"] figure,
    .vl-editor-content .ProseMirror [style*="text-align:right"] figure,
    .vl-editor-content .ProseMirror [style*="text-align: right"] .vl-image-figure,
    .vl-editor-content .ProseMirror [style*="text-align:right"] .vl-image-figure {
      display: inline-block;
      margin-left: auto;
      margin-right: 0;
    }
    .vl-editor-content .ProseMirror [style*="text-align: left"] img,
    .vl-editor-content .ProseMirror [style*="text-align:left"] img,
    .vl-editor-content .ProseMirror [style*="text-align: left"] figure,
    .vl-editor-content .ProseMirror [style*="text-align:left"] figure,
    .vl-editor-content .ProseMirror [style*="text-align: left"] .vl-image-figure,
    .vl-editor-content .ProseMirror [style*="text-align:left"] .vl-image-figure {
      display: inline-block;
      margin-left: 0;
      margin-right: auto;
    }

    /* Fullscreen HTML source editor & stats bar */
    /* Hero section editor chrome */
.vl-hero-embed-block { cursor: grab; border: 2px dashed #e5e7eb; margin: 20px 0; overflow: hidden; background: #f9fafb; border-radius: 12px; }
.dark .vl-hero-embed-block { border-color: #374151; background: #111827; }
.vl-hero-header { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; font-size: 13px; font-weight: 600; color: #374151; cursor: grab; }
.dark .vl-hero-header { background: #1f2937; border-color: #374151; color: #d1d5db; }
.vl-hero-header button { background: none; border: none; padding: 4px; cursor: pointer; color: #6b7280; border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.vl-hero-header button:hover { background: #e5e7eb; color: #111827; }
.dark .vl-hero-header button:hover { background: #374151; color: #fff; }
.dark .vl-hero-header button { color: #9ca3af; }
.vl-hero-body { padding: 16px; }
.vl-hero-row { display: flex; gap: 24px; align-items: stretch; }
.vl-hero-row.vl-hero-row-cta { justify-content: center; }
.vl-hero-text { flex: 1 1 400px; min-width: 0; display: flex; flex-direction: column; }
.vl-hero-text-col { min-height: 60px; cursor: text; user-select: text; padding: 4px 0; }
.vl-hero-text-col:focus { outline: none; }
.vl-hero-buttons { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.vl-hero-btn-preview { display: inline-block; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; }
.vl-hero-btn-preview.vl-hero-btn-primary { background: var(--theme-primary, #1d4ed8); color: #fff; }
.vl-hero-btn-preview.vl-hero-btn-secondary { background: var(--theme-surface, #fff); color: var(--theme-text, #374151); border: 1px solid var(--theme-secondary, #d1d5db); }
.vl-hero-media { position: relative; flex: 0 0 360px; min-height: 180px; display: flex; align-items: center; justify-content: center; border-radius: 12px; overflow: hidden; background: #f3f4f6; }
.vl-hero-media-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.vl-hero-carousel-preview { display: flex; width: 100%; height: 100%; align-items: center; justify-content: center; gap: 6px; overflow: hidden; color: #6b7280; font-size: 13px; }
.vl-hero-carousel-preview img { width: 31%; height: 100%; object-fit: cover; border-radius: 6px; }
.dark .vl-hero-carousel-preview { color: #9ca3af; }
.vl-hero-media-settings { padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
.dark .vl-hero-media-settings { border-color: #374151; }
.vl-hero-lightbox-toggle { padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #f9fafb; color: #374151; }
.dark .vl-hero-lightbox-toggle { border-color: #4b5563; background: #1f2937; color: #e5e7eb; }
.vl-hero-media-ph { color: #9ca3af; font-size: 13px; }
.dark .vl-hero-media { background: #111827; }
.vl-hero-badge { position: absolute; bottom: -10px; right: -10px; background: var(--theme-primary, #4f46e5); color: #fff; padding: 6px 14px; border-radius: 10px; font-size: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.25); }
.vl-hero-heading { font-size: 1.5rem; font-weight: 700; margin: 0 0 8px; line-height: 1.2; color: #111827; }
.vl-hero-subtitle { font-size: 1rem; color: #4b5563; margin: 0 0 4px; line-height: 1.5; }
.dark .vl-hero-heading { color: #f3f4f6; }
.dark .vl-hero-subtitle { color: #9ca3af; }

.vl-modal-subhead { font-size: 13px; font-weight: 600; color: #374151; margin: 14px 0 6px; }
.dark .vl-modal-subhead { color: #e5e7eb; }

    /* Dragover overlay for editor drop upload */
    .vl-editor-wrapper { position: relative; }
    .vl-editor-wrapper.is-dragover {
      outline: 2px dashed #2563eb !important;
      outline-offset: -2px !important;
    }
    .vl-editor-wrapper.is-dragover::after {
      content: '';
      position: absolute;
      inset: 0;
      background: rgba(37, 99, 235, 0.06);
      pointer-events: none;
      z-index: 20;
      border-radius: inherit;
    }
    .dark .vl-editor-wrapper.is-dragover { outline-color: #60a5fa !important; }
    .dark .vl-editor-wrapper.is-dragover::after { background: rgba(96, 165, 250, 0.08); }

/* Fullscreen HTML source editor & stats bar */
.vl-editor-wrapper.is-fullscreen .vl-html-editor {
      flex: 1 1 auto;
      min-height: 0;
      height: 100%;
      resize: none;
      overflow-y: auto;
    }
    .vl-editor-wrapper.is-fullscreen .vl-editor-stats {
      flex: none;
    }
  `;
  document.head.appendChild(style);
})();
