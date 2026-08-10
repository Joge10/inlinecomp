// ============================================================
//  InlineComp Public — Service Worker (PWA)
//
//  Strategie sinds 2026-05-27: PURE NETWORK-ONLY.
//  Zie coach/sw.js voor uitgebreide uitleg — dezelfde rationale.
//  Korte versie: eerdere network-first+cache.put gaf cached oude HTML
//  na app-updates; voor een real-time wedstrijd-app is offline-modus
//  toch waardeloos.
// ============================================================

const SW_VERSIE = 'public-2026.08.08.001';

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

// ── Web Push (Fase 3) ────────────────────────────────────────────────
// push: toon de melding (werkt ook als de app dicht is).
// notificationclick: focus een bestaand public-venster of open er een.
self.addEventListener('push', event => {
    let d = {};
    try { d = event.data ? event.data.json() : {}; } catch (e) { d = {}; }
    const title = d.title || 'InlineComp';
    event.waitUntil(self.registration.showNotification(title, {
        body:     d.body || '',
        icon:     'icon-192-v2.svg',
        badge:    'icon-192-v2.svg',
        tag:      d.tag || undefined,
        renotify: !!d.tag,
        data:     { url: d.url || './' },
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || './';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
        for (const c of list) {
            if (c.url.includes('/public') && 'focus' in c) { c.navigate(url); return c.focus(); }
        }
        if (self.clients.openWindow) return self.clients.openWindow(url);
    }));
});
