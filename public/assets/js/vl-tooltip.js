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
 *
 * Force a side with `data-vl-tooltip-position="top|bottom|left|right"`. Without
 * it the bubble sits above the host and flips below when there is no room; a
 * requested side that would leave the viewport falls back to that auto rule.
 *
 * Touch and pen have no hover: a tap opens the bubble immediately and a second
 * tap on the same control closes it. Tapping anywhere else, scrolling, pressing
 * Escape or losing focus closes it. The tap itself is never cancelled, so links
 * and buttons keep working.
 */
(function () {
    'use strict';

    var SKIP_TAGS = {
        IFRAME: 1, ABBR: 1, OPTION: 1, OPTGROUP: 1,
        OBJECT: 1, EMBED: 1, VIDEO: 1, AUDIO: 1, CANVAS: 1
    };
    var SKIP_SCOPE = '.ProseMirror, [contenteditable="true"], .vl-editor-content, .CodeMirror, .cm-editor, .prose, [data-vl-tooltip-skip]';
    var POSITIONS = { top: 1, bottom: 1, left: 1, right: 1 };
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

    function preferredPosition(el) {
        var value = (el.getAttribute('data-vl-tooltip-position') || '').toLowerCase();
        return POSITIONS[value] ? value : 'auto';
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(value, max));
    }

    function position(host) {
        var el = bubbleNode();
        var rect = host.el.getBoundingClientRect();
        var viewportWidth = document.documentElement.clientWidth;
        var viewportHeight = document.documentElement.clientHeight;

        el.style.left = '0px';
        el.style.top = '0px';
        var box = el.getBoundingClientRect();

        var placement = preferredPosition(host.el);
        if (placement === 'left' && rect.left - box.width - GAP < EDGE) placement = 'auto';
        if (placement === 'right' && rect.right + box.width + GAP > viewportWidth - EDGE) placement = 'auto';
        if (placement === 'top' && rect.top - box.height - GAP < EDGE) placement = 'auto';
        if (placement === 'bottom' && rect.bottom + box.height + GAP > viewportHeight - EDGE) placement = 'auto';
        if (placement === 'auto') {
            placement = rect.top - box.height - GAP < EDGE ? 'bottom' : 'top';
        }

        var left;
        var top;

        if (placement === 'left' || placement === 'right') {
            top = Math.round(clamp(
                rect.top + rect.height / 2 - box.height / 2,
                EDGE,
                viewportHeight - box.height - EDGE
            ));
            left = placement === 'left' ? rect.left - box.width - GAP : rect.right + GAP;
            left = Math.round(clamp(left, EDGE, viewportWidth - box.width - EDGE));
            el.style.setProperty('--vl-tooltip-arrow-y', Math.round(clamp(
                rect.top + rect.height / 2 - top,
                8,
                box.height - 8
            )) + 'px');
        } else {
            top = Math.round(clamp(
                placement === 'bottom' ? rect.bottom + GAP : rect.top - box.height - GAP,
                EDGE,
                viewportHeight - box.height - EDGE
            ));
            left = Math.round(clamp(
                rect.left + rect.width / 2 - box.width / 2,
                EDGE,
                viewportWidth - box.width - EDGE
            ));
            el.style.setProperty('--vl-tooltip-arrow-x', Math.round(clamp(
                rect.left + rect.width / 2 - left,
                8,
                box.width - 8
            )) + 'px');
        }

        el.style.left = left + 'px';
        el.style.top = top + 'px';
        el.classList.toggle('is-below', placement === 'bottom');
        el.classList.toggle('is-left', placement === 'left');
        el.classList.toggle('is-right', placement === 'right');
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
        host.tap = false;
        active = host;
        timer = window.setTimeout(function () {
            show(host);
        }, SHOW_DELAY);
    }

    function isTouchLike(event) {
        return !!event.pointerType && event.pointerType !== 'mouse';
    }

    function tap(host) {
        if (active && active.el === host.el) {
            hide();
            return;
        }
        hide();
        host.tap = true;
        active = host;
        show(host);
    }

    document.addEventListener('pointerover', function (event) {
        var host = resolveHost(event.target);
        if (host) request(host);
    }, true);

    document.addEventListener('pointerout', function (event) {
        var host = resolveHost(event.target);
        if (!host) return;
        if (active && active.el === host.el && active.tap) return;
        var next = event.relatedTarget;
        if (next && host.el.contains(next)) return;
        hide();
    }, true);

    document.addEventListener('focusin', function (event) {
        var host = resolveHost(event.target);
        if (host && host.el.matches(':focus-visible')) request(host);
    }, true);

    document.addEventListener('focusout', hide, true);
    document.addEventListener('pointerdown', function (event) {
        var host = resolveHost(event.target);
        if (!isTouchLike(event)) {
            hide();
            return;
        }
        if (host) {
            tap(host);
            return;
        }
        hide();
    }, true);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') hide();
    }, true);
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
