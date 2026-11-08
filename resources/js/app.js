// App chunk for the storefront: lightbox + visitor timezone only.
// Third-party libraries (Alpine, collapse) live in app-vendor.js, which
// MUST load before this file — in bundle mode and in CDN mode alike —
// so there is only ever one Alpine instance on the page. The vendor
// chunk (or the CDN scripts in CDN mode) owns Alpine.start(); inline
// Alpine.data registrations hook into the alpine:init event.

// Lightbox: clicking an image flagged in the editor opens the enlarged view
// by dispatching the same window event the carousel's overlay listens for.
document.addEventListener('click', (e) => {
  const img = e.target.closest('img[data-lightbox="true"]');
  if (!img) return;
  e.preventDefault();
  const src = img.getAttribute('data-lightbox-src') || img.getAttribute('src');
  window.dispatchEvent(new CustomEvent('lightbox-open', { detail: src }));
});

// Visitor timezone: <time data-vtz datetime="...+02:00"> is server-rendered in
// site time (SEO + no-JS fallback) and rewritten here to the visitor's own
// timezone via Intl. Skipped entirely when the setting is off (no data-vtz).
document.addEventListener('DOMContentLoaded', () => {
  const els = document.querySelectorAll('time[data-vtz][datetime]');
  if (!els.length) return;
  const lang = document.documentElement.lang || 'en';
  els.forEach((el) => {
    const d = new Date(el.getAttribute('datetime'));
    if (Number.isNaN(d.getTime())) return;
    const opts = { dateStyle: el.getAttribute('data-vtz-style') || 'long' };
    if (el.hasAttribute('data-vtz-time')) opts.timeStyle = 'short';
    try {
      el.textContent = new Intl.DateTimeFormat(lang, opts).format(d);
    } catch {
      // Keep the server-rendered site-time text.
    }
  });
});
