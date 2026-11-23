/* Vale PWA service worker. Served at /sw.js with the placeholders below
   replaced by PwaController::serviceWorker(). */
const PWA_VERSION = __PWA_VERSION__;
const PWA_BASE = __PWA_BASE__;
const PWA_SCOPE = __PWA_SCOPE__;
const OFFLINE_URL = __PWA_OFFLINE_URL__;
const MANIFEST_URL = __PWA_MANIFEST_URL__;
const PRECACHE = 'vale-pwa-precache-' + PWA_VERSION;
const RUNTIME = 'vale-pwa-runtime-' + PWA_VERSION;

const STATIC_PREFIXES = [
    PWA_BASE + '/assets/',
    PWA_BASE + '/themes/',
    PWA_BASE + '/uploads/',
    PWA_BASE + '/plugin-assets/'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(PRECACHE).then(function (cache) {
            return cache.addAll([OFFLINE_URL, MANIFEST_URL]).catch(function () {
                return undefined;
            });
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                if (key.indexOf('vale-pwa-') === 0 && key !== PRECACHE && key !== RUNTIME) {
                    return caches.delete(key);
                }
                return undefined;
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

function isStaticAsset(pathname) {
    return STATIC_PREFIXES.some(function (prefix) {
        return pathname.indexOf(prefix) === 0;
    });
}

function cacheFirst(request) {
    return caches.match(request).then(function (cached) {
        if (cached) {
            return cached;
        }
        return fetch(request).then(function (response) {
            if (response && response.ok && response.type === 'basic') {
                const copy = response.clone();
                caches.open(RUNTIME).then(function (cache) {
                    return cache.put(request, copy);
                });
            }
            return response;
        });
    });
}

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (/^\/(admin|api|pay|webhooks|cron)(\/|$)/.test(url.pathname)) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(function () {
            return caches.match(OFFLINE_URL).then(function (cached) {
                return cached || new Response('Offline', {
                    status: 503,
                    headers: { 'Content-Type': 'text/plain; charset=UTF-8' }
                });
            });
        }));
        return;
    }

    if (isStaticAsset(url.pathname)) {
        event.respondWith(cacheFirst(request));
    }
});
