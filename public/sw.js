// InlineComp – Service Worker (PWA)
// Strategie: network-first met offline fallback.
// API-calls altijd via netwerk (verse data), statische shell gecacht.

const CACHE_NAME = 'inlinecomp-v1';
const SHELL_URLS = [
    './index.php',
    '../favicon.svg'
];

// Installatie: cache de app-shell
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(SHELL_URLS))
            .then(() => self.skipWaiting())
    );
});

// Activatie: verwijder oude caches
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys
                .filter(k => k !== CACHE_NAME)
                .map(k => caches.delete(k))
            )
        ).then(() => self.clients.claim())
    );
});

// Fetch: network-first voor alles
// - API-calls (?action=...): altijd netwerk, nooit cachen
// - HTML/CSS/JS (de pagina zelf): netwerk eerst, cache als fallback
self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);

    // API-calls: altijd netwerk
    if (url.searchParams.has('action')) {
        return; // laat de browser het afhandelen
    }

    // Overige requests: network-first met cache fallback
    e.respondWith(
        fetch(e.request)
            .then(res => {
                // Succesvolle response → update cache
                if (res.ok) {
                    const clone = res.clone();
                    caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
                }
                return res;
            })
            .catch(() => caches.match(e.request))
    );
});
