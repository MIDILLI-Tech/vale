/**
 * media-upload.js — shared drag-drop upload for every admin media picker
 * (featured image, product gallery, editor media library, download-file
 * and variation-file pickers).
 *
 * Two wiring styles, both race-free (no load-order dependency):
 *
 *  1. Declarative (Alpine templates): the drop zone element carries
 *       data-vl-media-drop
 *       data-vl-media-filter="image/"        (optional; empty = all files)
 *       @vl:media-uploaded="handleDroppedFiles($event.detail)"
 *     The helper auto-registers every such element (initial scan +
 *     MutationObserver) and dispatches a `vl:media-uploaded` CustomEvent
 *     with the parsed upload-json-multi result on success.
 *
 *  2. Programmatic (browse.js): vlMediaUpload.dropZone(el, { filter, onDone })
 *
 * The helper owns everything visual — drag overlay, progress bar and error
 * strip — and checks the upload permission at DROP time (never at wiring
 * time), so a user without media.upload gets an explicit message instead of
 * a silent no-op. The server enforces the same permission on
 * /admin/media/upload-json* regardless.
 *
 * No build step: plain ES5-compatible script loaded with `defer` from
 * admin/layout.twig.
 */
(function () {
    if (window.vlMediaUpload) return;

    var IMAGE_EXTS    = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'];
    var VIDEO_EXTS    = ['mp4', 'm4v', 'webm', 'ogv', 'mov', 'mkv'];
    var SUBTITLE_EXTS = ['vtt', 'srt', 'sub'];

    function fileExt(name) {
        var parts = String(name || '').split('.');
        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    function fileKind(file) {
        var type = file.type || '';
        var ext  = fileExt(file.name);
        if (type.indexOf('image/') === 0 || IMAGE_EXTS.indexOf(ext) !== -1) return 'image';
        if (type.indexOf('video/') === 0 || VIDEO_EXTS.indexOf(ext) !== -1) return 'video';
        if (type === 'text/vtt' || type === 'application/x-subrip' || SUBTITLE_EXTS.indexOf(ext) !== -1) return 'subtitle';
        return 'file';
    }

    /**
     * Does a dropped file satisfy a picker's active type filter?
     * filter values mirror the browse-json `type` parameter ("image/",
     * "video/", "application/,audio/,text/", "text/vtt,application/x-subrip",
     * "" = everything).
     */
    function matchesFilter(file, filter) {
        if (!filter) return true;
        var kind     = fileKind(file);
        var prefixes = filter.split(',').map(function (p) { return p.trim(); }).filter(Boolean);
        return prefixes.some(function (p) {
            if (p === 'image/') return kind === 'image';
            if (p === 'video/') return kind === 'video';
            if (p.indexOf('text/vtt') === 0 || p.indexOf('application/x-subrip') === 0) return kind === 'subtitle';
            if (p.indexOf('application/') === 0 || p.indexOf('audio/') === 0 || p.indexOf('text/') === 0) {
                return kind === 'file' || kind === 'subtitle';
            }
            return true; // unknown prefix — let the server decide
        });
    }

    function tjs(text, replacements) {
        return typeof window.tjs === 'function' ? window.tjs(text, replacements) : text;
    }

    function canUpload() {
        // Undefined (flag script missing) must not block a normal admin —
        // only an explicit false hides the feature.
        return window.VL_CAN_UPLOAD_MEDIA !== false;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Defensive JSON parsing: stray output printed before the payload
     * (a leaked PHP notice/deprecation) breaks the raw parse — retry with
     * the first { … last } window before giving up.
     */
    function parseServerResponse(text) {
        if (!text) return null;
        try { return JSON.parse(text); } catch (e) { /* stray output before the JSON */ }
        var start = text.indexOf('{');
        var end   = text.lastIndexOf('}');
        if (start !== -1 && end > start) {
            try { return JSON.parse(text.slice(start, end + 1)); } catch (e) { /* body is not JSON at all */ }
        }
        return null;
    }

    /**
     * Last-resort reason for a response that never was valid JSON: HTTP
     * status plus the head of the body, so the user sees the actual cause
     * instead of a silent generic failure.
     */
    function responseErrorMessage(xhr) {
        var body = String(xhr.responseText || '').trim().slice(0, 200);
        return tjs('Upload failed.') + ' (HTTP ' + xhr.status + (body ? ': ' + body : '') + ')';
    }

    /* ── transport ─────────────────────────────────────────────────────── */

    function upload(files, opts) {
        opts = opts || {};
        return new Promise(function (resolve, reject) {
            var form = new FormData();
            files.forEach(function (f) { form.append('files[]', f); });
            var csrf = csrfToken();
            form.append('_csrf_token', csrf);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', (window.APP_BASE_PATH || '') + '/admin/media/upload-json-multi');
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            if (opts.conflictMode) form.append('conflict_mode', opts.conflictMode);

            xhr.upload.addEventListener('progress', function (ev) {
                if (ev.lengthComputable && typeof opts.onProgress === 'function') {
                    opts.onProgress(ev.loaded / ev.total, ev.loaded, ev.total);
                }
            });
            xhr.addEventListener('load', function () {
                var parsed = parseServerResponse(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && parsed) {
                    resolve(parsed);
                } else {
                    var fatal = parsed && parsed.errors && parsed.errors[0];
                    reject(new Error((fatal && fatal.error) || responseErrorMessage(xhr)));
                }
            });
            xhr.addEventListener('error', function () { reject(new Error(tjs('Upload failed.'))); });
            xhr.addEventListener('abort', function () { reject(new Error(tjs('Upload failed.'))); });
            xhr.send(form);
        });
    }

    /* ── drop zone UI ──────────────────────────────────────────────────── */

    function ensureUi(zone) {
        if (zone.querySelector(':scope > .vl-drop-ui')) return;
        var ui = document.createElement('div');
        ui.className = 'vl-drop-ui';
        ui.innerHTML =
            '<div class="vl-drop-overlay">' + tjs('Drop files to upload') + '</div>' +
            '<div class="vl-drop-progress-wrap">' +
            '  <div class="vl-drop-progress"><div class="vl-drop-progress-fill"></div></div>' +
            '  <span class="vl-drop-progress-label"></span>' +
            '</div>' +
            '<div class="vl-drop-error"></div>';
        zone.appendChild(ui);
    }

    function activate(el, opts) {
        if (!el) return;
        // (3) Browsers that reuse one modal element re-register on every open
        // with fresh closures (current tab filter, current selection). The
        // first call wires the DOM once; later calls only swap the options so
        // drops never run against a stale tab's filter or selection.
        if (el.__vlMediaDrop) {
            if (opts) el.__vlMediaDropOpts = opts;
            return;
        }
        el.__vlMediaDrop = true;
        el.__vlMediaDropOpts = opts || null;

        function curOpts() { return el.__vlMediaDropOpts || {}; }

        // (4) Declarative contract: a zone carrying data-vl-media-drop must also
        // declare @vl:media-uploaded so a scope actually receives the result.
        // A zone without the attribute would upload files into the void (they
        // land in the library, but nothing selects/refreshes). Programmatic
        // zones pass an onDone callback and are checked differently below.
        if (!opts || typeof opts.onDone !== 'function') {
            var hasAlpineListener = el.hasAttribute('@vl:media-uploaded')
                || el.hasAttribute('x-on:vl-media-uploaded');
            if (!hasAlpineListener) {
                // console-only at wiring time — the drop itself is still wired
                // so a future attribute added later (Alpine teleport) works.
                if (window.APP_DEBUG) {
                    console.warn(
                        '[media-upload] drop zone has data-vl-media-drop but no ' +
                        '@vl:media-uploaded listener. Dropped files will upload but ' +
                        'nothing will pick them up.',
                        el,
                    );
                }
            }
        }

        el.classList.add('vl-media-dropzone');
        // Only give the zone a positioning context when it doesn't already
        // have one. Setting `position` in CSS would be wrong: this file's
        // styles are unlayered and would beat Tailwind's `fixed` utility on
        // modal roots (unlayered > @layer utilities, specificity aside),
        // which would tear the modal out of its overlay. Inline style has
        // the same weight, so it is applied ONLY for static elements.
        if (window.getComputedStyle(el).position === 'static') {
            el.style.position = 'relative';
        }
        ensureUi(el);

        var ui       = el.querySelector(':scope > .vl-drop-ui');
        var barWrap  = ui.querySelector('.vl-drop-progress-wrap');
        var barFill  = ui.querySelector('.vl-drop-progress-fill');
        var barLabel = ui.querySelector('.vl-drop-progress-label');
        var errStrip = ui.querySelector('.vl-drop-error');
        var errTimer = null;

        // (3) Filter: explicit option wins (refreshed on every open via
        // __vlMediaDropOpts), otherwise the declarative attribute.
        function currentFilter() {
            var o = curOpts();
            if (typeof o.filter === 'function') return o.filter;
            return function (f) { return matchesFilter(f, el.getAttribute('data-vl-media-filter') || ''); };
        }

        // tone: undefined/'error' → red failure strip; 'info' → blue progress
        // notice (e.g. "N file(s) queued"). Same element, different tone.
        function showError(message, tone) {
            var o = curOpts();
            if (typeof o.onError === 'function') { o.onError(message); return; }
            errStrip.classList.toggle('is-info', tone === 'info');
            errStrip.textContent = message;
            errStrip.classList.add('is-active');
            clearTimeout(errTimer);
            errTimer = setTimeout(function () {
                errStrip.classList.remove('is-active');
                errStrip.classList.remove('is-info');
            }, 6000);
        }

        var depth = 0;
        var uploading = false;
        // (5) Batches dropped while an upload is in flight wait here and stream
        // through the same progress UI in order.
        var pending = [];

        // (1) Per-file server errors (rejected rows inside a 200 response) must
        // be visible: "3 uploaded, 1 rejected" — never silently swallowed.
        function perFileErrors(result) {
            return (result && result.errors ? result.errors : []).filter(function (e) {
                return e && e.file && e.file !== '*';
            });
        }

        function showPerFileErrors(errors) {
            var shown = errors.slice(0, 3).map(function (e) {
                return e.file + ': ' + e.error;
            }).join(' ');
            if (errors.length > 3) {
                shown += ' ' + tjs(':count more file(s) failed.', { ':count': String(errors.length - 3) });
            }
            showError(shown);
        }

        function dispatchDone(result) {
            var o = curOpts();
            if (typeof o.onDone === 'function') {
                return o.onDone(result);
            }
            // Declarative wiring: let the Alpine scope refresh + auto-select
            // through its own handler. bubbles:true so the handler also fires
            // when the @vl:media-uploaded expression lives on an ancestor
            // scope rather than the drop panel itself (e.g. a wrapper that
            // owns handleDroppedFiles while the panel only carries the
            // data-vl-media-drop attribute).
            var evt;
            try {
                evt = new CustomEvent('vl:media-uploaded', { detail: result, bubbles: true });
            } catch (e) {
                evt = document.createEvent('CustomEvent');
                evt.initCustomEvent('vl:media-uploaded', true, false, result);
            }
            el.dispatchEvent(evt);
            return Promise.resolve();
        }

        el.addEventListener('dragenter', function (ev) {
            ev.preventDefault();
            depth++;
            el.classList.add('is-over');
        });
        el.addEventListener('dragover', function (ev) { ev.preventDefault(); });
        el.addEventListener('dragleave', function (ev) {
            ev.preventDefault();
            depth = Math.max(0, depth - 1);
            if (depth === 0) el.classList.remove('is-over');
        });
        el.addEventListener('drop', async function (ev) {
            ev.preventDefault();
            depth = 0;
            el.classList.remove('is-over');

            // Permission check at drop time — explicit feedback, never a
            // silent no-op. (Server enforces the same permission anyway.)
            if (!canUpload()) {
                showError(tjs("You don't have permission to upload files."));
                return;
            }

            var files = Array.prototype.slice.call((ev.dataTransfer && ev.dataTransfer.files) || []);
            if (files.length === 0) return;

            var filter = currentFilter();
            var accepted = files.filter(function (f) { return filter(f); });
            var rejected = files.length - accepted.length;
            if (accepted.length === 0) {
                showError(tjs(':count file(s) not allowed here.', { ':count': String(rejected) }));
                return;
            }

            // (5) Queue drops that land while an upload is in flight instead of
            // silently swallowing them. Only the first batch uploads; the rest
            // wait their turn and stream through the same progress UI.
            pending.push({ files: accepted, rejected: rejected });
            if (uploading) {
                // Blue info notice, not a red error — queuing is expected flow.
                showError(tjs(':count file(s) queued.', { ':count': String(pending.length) }), 'info');
                return;
            }
            uploading = true;
            try {
                while (pending.length > 0) {
                    var batch = pending.shift();
                    await runBatch(batch.files, batch.rejected);
                }
            } finally {
                uploading = false;
                barWrap.classList.remove('is-active');
                barFill.style.width = '0%';
                barLabel.textContent = '';
            }
        });

        // (1)+(5) One batch through transport → per-file errors → done dispatch.
        // curOpts() is re-read per batch so a queued batch uses the newest
        // onDone/conflictMode — a queued drop belongs to the latest open.
        async function runBatch(accepted, rejected) {
            var o = curOpts();
            errStrip.classList.remove('is-active');
            barWrap.classList.add('is-active');
            barFill.style.width = '0%';
            barLabel.textContent = tjs('Uploading...') + ' 0%';

            try {
                var result = await upload(accepted, {
                    conflictMode: o.conflictMode,
                    onProgress: function (ratio) {
                        var pct = Math.round(ratio * 100);
                        barFill.style.width = pct + '%';
                        barLabel.textContent = tjs('Uploading...') + ' ' + pct + '%';
                    }
                });
                var fatal = (result.errors || []).find(function (e) { return e.file === '*'; });
                if (fatal) {
                    showError(fatal.error);
                } else {
                    // (1) Partial success: some rows uploaded, some rejected
                    // (bad extension, oversized…). Surface the rejected ones —
                    // a 200 with uploaded:[…], errors:[…] is the normal shape.
                    var rowErrors = perFileErrors(result);
                    if ((result.uploaded || []).length > 0 || (result.overwritten || []).length > 0) {
                        barLabel.textContent = tjs('Processing...');
                        await dispatchDone(result);
                    }
                    if (rowErrors.length > 0) {
                        showPerFileErrors(rowErrors);
                    }
                }
                if (rejected > 0) {
                    showError(tjs(':count file(s) not allowed here.', { ':count': String(rejected) }));
                }
            } catch (err) {
                showError(err && err.message ? err.message : tjs('Upload failed.'));
            }
        }

        // (8) Safety net: if the drag is cancelled (Esc, browser drop outside
        // the window, dragend without a drop) the dragleave pair may never
        // balance — reset the highlight so no overlay is left stuck on.
        el.addEventListener('dragend', function () {
            depth = 0;
            el.classList.remove('is-over');
        });
    }

    /* ── auto-registration (declarative zones) ─────────────────────────── */

    function scan(root) {
        var scope = root || document;
        if (!scope.querySelectorAll) return;
        var zones = scope.querySelectorAll('[data-vl-media-drop]');
        for (var i = 0; i < zones.length; i++) activate(zones[i], null);
    }

    var bodyObserver = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var added = mutations[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                var node = added[j];
                if (node.nodeType !== 1) continue;
                if (node.matches && node.matches('[data-vl-media-drop]')) activate(node, null);
                scan(node);
            }
        }
    });

    function init() {
        scan();
        if (document.body) {
            bodyObserver.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Stop the browser from navigating away when a file is dropped outside
    // a drop zone (same guard as the media page's own drop handling).
    document.addEventListener('dragover', function (ev) { ev.preventDefault(); });
    document.addEventListener('drop', function (ev) { ev.preventDefault(); });

    window.vlMediaUpload = {
        upload: upload,
        dropZone: activate,
        register: activate,
        fileKind: fileKind,
        matchesFilter: matchesFilter
    };
})();
