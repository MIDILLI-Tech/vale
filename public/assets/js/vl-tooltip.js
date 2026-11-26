/**
 * Admin tooltips — one styled bubble for every `title` in the admin UI.
 *
 * Loaded with `defer` through `partials/_tooltip_assets.twig` from the admin
 * layout and every front-end theme (plain script, no build step). Delegated
 * listeners adopt a host lazily on first hover/focus: the `title` attribute
 * moves to `data-vl-tooltip` (so the browser's own bubble never shows) and is
 * mirrored to `aria-label` when the element has no other accessible name.
 *
 * Opt out with `data-vl-tooltip-skip`. Content never gets a bubble: the admin
 * block editor (ProseMirror, composed blocks) and article bodies (`.prose`)
 * keep their authored `title` attributes — those are part of the document,
 * not UI hints.
 */
(function () {
    'use strict';

    var SKIP_TAGS = {
        IFRAME: 1, ABBR: 1, OPTION: 1, OPTGROUP: 1,
        OBJECT: 1, EMBED: 1, VIDEO: 1, AUDIO: 1, CANVAS: 1
    };
    var SKIP_SCOPE = '.ProseMirror, [contenteditable="true"], .vl-editor-content, .CodeMirror, .cm-editor, .prose, [data-vl-tooltip-skip]';
    var SHOW_DELAY = 140;
    var GAP = 8;
    var EDGE = 8;

    var bubble = null;
    var active = null;
    var timer = null;

    function bubbleNode() {
        if (!bubble) {
            bubble = document.createElement('div');
            bubble.className = 'vl-tooltip';
            bubble.setAttribute('role', 'tooltip');
            bubble.setAttribute('aria-hidden', 'true');
            document.body.appendChild(bubble);
        }
        return bubble;
    }

    function resolveHost(target) {
        if (!target || target.nodeType !== 1) return null;

        var el = target.closest('[data-vl-tooltip], [title]');
        if (!el || SKIP_TAGS[el.tagName] || el.closest(SKIP_SCOPE)) return null;

        var text;
        if (el.hasAttribute('title')) {
            text = (el.getAttribute('title') || '').trim();
            el.setAttribute('data-vl-tooltip', text);
            el.removeAttribute('title');
            if (text && !el.hasAttribute('aria-label') && !(el.textContent || '').trim()) {
                el.setAttribute('aria-label', text);
            }
        } else {
            text = (el.getAttribute('data-vl-tooltip') || '').trim();
        }

        return text ? { el: el, text: text } : null;
    }

    function position(host) {
        var el = bubbleNode();
        var rect = host.el.getBoundingClientRect();
        var viewportWidth = document.documentElement.clientWidth;
        var viewportHeight = document.documentElement.clientHeight;

        el.style.left = '0px';
        el.style.top = '0px';
        var box = el.getBoundingClientRect();

        var below = rect.top - box.height - GAP < EDGE;
        var top = below ? rect.bottom + GAP : rect.top - box.height - GAP;
        if (top + box.height > viewportHeight - EDGE) {
            top = Math.max(EDGE, viewportHeight - box.height - EDGE);
        }

        var left = rect.left + rect.width / 2 - box.width / 2;
        left = Math.max(EDGE, Math.min(left, viewportWidth - box.width - EDGE));

        el.style.left = Math.round(left) + 'px';
        el.style.top = Math.round(top) + 'px';
        el.style.setProperty('--vl-tooltip-arrow-x', Math.round(rect.left + rect.width / 2 - left) + 'px');
        el.classList.toggle('is-below', below);
    }

    function show(host) {
        var el = bubbleNode();
        el.textContent = host.text;
        el.classList.remove('is-visible');
        position(host);
        window.requestAnimationFrame(function () {
            if (active === host) el.classList.add('is-visible');
        });
    }

    function hide() {
        window.clearTimeout(timer);
        timer = null;
        active = null;
        if (bubble) bubble.classList.remove('is-visible');
    }

    function request(host) {
        if (active && active.el === host.el) return;
        hide();
        active = host;
        timer = window.setTimeout(function () {
            show(host);
        }, SHOW_DELAY);
    }

    document.addEventListener('pointerover', function (event) {
        var host = resolveHost(event.target);
        if (host) request(host);
    }, true);

    document.addEventListener('pointerout', function (event) {
        var host = resolveHost(event.target);
        if (!host) return;
        var next = event.relatedTarget;
        if (next && host.el.contains(next)) return;
        hide();
    }, true);

    document.addEventListener('focusin', function (event) {
        var host = resolveHost(event.target);
        if (host && host.el.matches(':focus-visible')) request(host);
    }, true);

    document.addEventListener('focusout', hide, true);
    document.addEventListener('pointerdown', hide, true);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') hide();
    }, true);
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
