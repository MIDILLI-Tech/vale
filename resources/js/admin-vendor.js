import Alpine from 'alpinejs'
import collapse from '@alpinejs/collapse'
import Sortable from 'sortablejs'

// Vendor chunk for the admin panel: third-party libraries only.
// Exposes globals consumed by admin.js (app chunk) and by inline
// page scripts. Loaded BEFORE admin.js in both bundle and CDN modes
// (deferred scripts execute in document order), so either mode gets
// exactly one Alpine / Sortable instance.
window.Alpine = Alpine
window.Sortable = Sortable

Alpine.plugin(collapse)
Alpine.start()
