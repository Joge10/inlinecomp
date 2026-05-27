// ============================================================
//  InlineComp Coach — Service Worker (PWA)
//
//  Strategie sinds 2026-05-27: PURE NETWORK-ONLY.
//  Eerdere versie (v2) had network-first met cache.put-fallback maar
//  cached daarmee onbedoeld oude HTML/JS-versies — wedstrijdmanagers
//  en coaches klaagden dat updates aan de app niet doorkwamen ook na
//  refresh ("zie de nieuwe knop niet"). Voor een real-time wedstrijd-
//  app heeft offline-modus toch geen waarde: zonder server zie je
//  sowieso geen verse heat-data.
//
//  Wat deze SW nu doet:
//    - install: skipWaiting() → nieuwe SW direct actief
//    - activate: claim() + wis ALLE caches (ruim oude shell-caches op)
//    - fetch: network-only voor same-origin GET, met cache:'no-store'
//      zodat ook de browser-HTTP-cache wordt omzeild. POST/PUT en
//      cross-origin gaan default route.
//
//  Bij update (nieuwe sw.js): browser detecteert wijziging → install
//  → skipWaiting → activate → controllerchange-event in de page →
//  page reloadt zichzelf (handler in index.php). Gebruiker ziet
//  vanzelf de nieuwe versie zonder hard-refresh of app-herinstall.
//
//  Bump SW_VERSIE bij wijziging van deze file om browsers te dwingen
//  een nieuwe install-fase te triggeren (byte-diff is meestal genoeg
//  maar versie-string maakt 't expliciet + diagnose makkelijk).
// ============================================================

const SW_VERSIE = 'coach-2026.05.27.001';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(Promise.all([
        self.clients.claim(),
        // Alle oude caches wissen — naam-agnostisch zodat ook caches
        // van eerdere SW-versies (inlinecomp-coach-v1/v2) worden
        // meegenomen. Voor een pure-network SW hebben we niets
        // nodig in caches.
        caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))),
    ]));
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    if (new URL(event.request.url).origin !== self.location.origin) return;
    // cache:'no-store' omzeilt ook de browser-HTTP-cache (Cache-Control
    // headers van de server). Combineert met PHP no-cache headers voor
    // dubbele zekerheid: shell-HTML altijd vers.
    event.respondWith(fetch(event.request, { cache: 'no-store' }));
});
