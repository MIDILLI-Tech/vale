/**
 * media-facade.js — shared media tile behavior for every admin surface
 * (media library grid/list, media edit page pickers, Alpine-driven
 * gallery pickers, editor media browsers).
 *
 * A "facade" tile is a static placeholder for a video/audio file:
 *
 *   <div class="vl-media-facade"
 *        data-vl-media-facade
 *        data-vl-media-src="/uploads/….mp4"
 *        data-vl-media-mime="video/mp4">
 *       <button type="button" class="vl-media-facade-play" …>badge</button>
 *   </div>
 *
 * Behavior:
 *  - When a video tile scrolls into view (200px margin), a PAUSED poster
 *    <video preload="metadata" muted> is inserted so the first frame shows
 *    without downloading the whole file. Leaving the viewport detaches it.
 *  - Clicking the play badge loads the real player in place (controls,
 *    unmuted, play()). Nothing downloads before that.
 *  - The badge/player clicks stop propagation, so the surrounding card
 *    click (select / insert / info overlay) keeps working everywhere else.
 *  - Dynamically added tiles (Alpine x-for, picker dialogs) are picked up
 *    by a MutationObserver — no per-surface wiring needed.
 *
 * No build step: plain ES5-compatible script loaded with `defer` from
 * admin/layout.twig.
 */
(function () {
    if (window.vlMediaFacade) return;

    var registered = new WeakSet();
    var playingTiles = new WeakSet();
    var posterObserver = null;

    function hasIntersectionObserver() {
        return typeof window.IntersectionObserver === 'function';
    }

    function isVideoMime(mime) {
        return (mime || '').indexOf('video/') === 0;
    }

    function isAudioMime(mime) {
        return (mime || '').indexOf('audio/') === 0;
    }

    /* ── poster (first frame) ─────────────────────────────────────────── */

    function attachPoster(tile) {
        if (tile.getAttribute('data-vl-poster') === '1') return;
        var mime = tile.getAttribute('data-vl-media-mime') || '';
        if (!isVideoMime(mime)) return; // audio has no frame to show
        var src = tile.getAttribute('data-vl-media-src') || '';
        if (!src) return;
        tile.setAttribute('data-vl-poster', '1');
        var video = document.createElement('video');
        video.className = 'vl-media-facade-poster';
        video.preload = 'metadata';
        video.muted = true;
        video.playsInline = true;
        video.setAttribute('aria-hidden', 'true');
        video.src = src;
        tile.insertBefore(video, tile.firstChild);
    }

    function detachPoster(tile) {
        var poster = tile.querySelector('.vl-media-facade-poster');
        if (!poster) return;
        tile.removeAttribute('data-vl-poster');
        poster.removeAttribute('src'); // abort the in-flight metadata fetch
        try { poster.load(); } catch (e) { /* older browsers */ }
        poster.remove();
    }

    function ensurePosterObserver() {
        if (posterObserver) return posterObserver;
        posterObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var tile = entry.target;
                if (entry.isIntersecting) {
                    attachPoster(tile);
                } else if (!playingTiles.has(tile)) {
                    detachPoster(tile);
                }
            });
        }, { root: null, rootMargin: '200px' });
        return posterObserver;
    }

    /* ── player (play badge) ──────────────────────────────────────────── */

    function hideBadge(tile) {
        var badge = tile.querySelector('.vl-media-facade-play');
        if (badge) badge.style.display = 'none';
    }

    function upgradePoster(tile) {
        var video = tile.querySelector('.vl-media-facade-poster');
        if (!video) return false;
        video.classList.remove('vl-media-facade-poster');
        video.classList.add('vl-media-facade-player');
        video.removeAttribute('aria-hidden');
        video.muted = false;
        video.preload = 'auto';
        video.controls = true;
        playingTiles.add(tile);
        tile.classList.add('is-playing');
        return true;
    }

    function createPlayer(tile) {
        var src = tile.getAttribute('data-vl-media-src') || '';
        var mime = tile.getAttribute('data-vl-media-mime') || '';
        var isAudio = isAudioMime(mime);
        var player = document.createElement(isAudio ? 'audio' : 'video');
        player.src = src;
        player.controls = true;
        player.autoplay = true;
        player.preload = 'none';
        player.className = 'vl-media-facade-player';
        if (!isAudio) player.playsInline = true;
        tile.appendChild(player);
        playingTiles.add(tile);
        tile.classList.add('is-playing');
        return player;
    }

    function startPlayer(tile) {
        if (playingTiles.has(tile)) return;
        playingTiles.add(tile); // guard against double activation before media loads
        hideBadge(tile);
        var player = upgradePoster(tile) || createPlayer(tile);
        // Interacting with the player must not trigger the card's own click
        // (select / insert / info overlay) underneath it.
        player.addEventListener('click', function (ev) { ev.stopPropagation(); });
        var promise = player.play();
        if (promise && typeof promise.catch === 'function') {
            promise.catch(function () { /* autoplay blocked — controls remain */ });
        }
    }

    /* ── registration (static + dynamic tiles) ────────────────────────── */

    function register(tile) {
        if (registered.has(tile)) return;
        registered.add(tile);
        if (hasIntersectionObserver()) {
            ensurePosterObserver().observe(tile);
        }
    }

    function scan(root) {
        var scope = root || document;
        if (!scope.querySelectorAll) return;
        var tiles = scope.querySelectorAll('[data-vl-media-facade]');
        for (var i = 0; i < tiles.length; i++) register(tiles[i]);
    }

    // Delegate the play badge click in the capture phase so the surrounding
    // card handlers (Alpine @click etc.) never see it.
    document.addEventListener('click', function (ev) {
        var target = ev.target;
        if (!target || !target.closest) return;
        var badge = target.closest('[data-vl-media-facade] .vl-media-facade-play');
        if (!badge) return;
        var tile = badge.closest('[data-vl-media-facade]');
        if (!tile) return;
        ev.preventDefault();
        ev.stopPropagation();
        startPlayer(tile);
    }, true);

    // Tiles rendered by Alpine (x-for) or the editor picker appear after the
    // initial scan — watch for them instead of wiring each surface.
    var bodyObserver = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var added = mutations[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                var node = added[j];
                if (node.nodeType !== 1) continue;
                if (node.matches && node.matches('[data-vl-media-facade]')) {
                    register(node);
                }
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

    window.vlMediaFacade = { scan: scan, register: register, startPlayer: startPlayer };
})();
