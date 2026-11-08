import Alpine from 'alpinejs'
import collapse from '@alpinejs/collapse'

// Vendor chunk for the storefront: third-party libraries only.
// Exposes globals consumed by app.js (app chunk) and by inline page
// scripts. Loaded BEFORE app.js in both bundle and CDN modes
// (deferred scripts execute in document order), so either mode gets
// exactly one Alpine instance.
window.Alpine = Alpine

Alpine.plugin(collapse)
Alpine.start()
