// ============================================================
//  InlineComp Jury — Service Worker (PWA)
//
//  Strategie sinds 2026-05-27: PURE NETWORK-ONLY.
//  Zie coach/sw.js voor uitgebreide uitleg — dezelfde rationale.
//  Voor jury extra belangrijk: stale heat-data of stale jury.js
//  kan tot foute beslissingen leiden ('baan op gestuurd' uit oude
//  cache komt). Altijd vers.
// ============================================================

const SW_VERSIE = 'jury-2026.05.27.001';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(Promise.all([
        self.clients.claim(),
        caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))),
    ]));
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    if (new URL(event.request.url).origin !== self.location.origin) return;
    event.respondWith(fetch(event.request, { cache: 'no-store' }));
});
