import { GridLayoutManager } from './grid-layout.js'
import { initTwigEditor } from './twig-editor.js'
import { initPanelReorder, PanelReorderManager } from './panel-reorder.js'
import { initRightRail, vlPublishNow } from './right-rail.js'

// App chunk for the admin panel. Third-party libraries (Alpine,
// collapse, Sortable) live in admin-vendor.js, which MUST load before
// this file — in bundle mode and in CDN mode alike — so there is only
// ever one Alpine / Sortable instance on the page. The vendor chunk
// (or the CDN scripts in CDN mode) owns Alpine.start(); inline
// Alpine.data registrations hook into the alpine:init event.

window.GridLayoutManager = GridLayoutManager
window.initTwigEditor = initTwigEditor
window.PanelReorderManager = PanelReorderManager
window.initPanelReorder = initPanelReorder
window.initRightRail = initRightRail
window.vlPublishNow = vlPublishNow

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { initPanelReorder(); initRightRail(); });
} else {
    initPanelReorder();
    initRightRail();
}
