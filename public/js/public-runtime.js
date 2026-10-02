// ============================================================
//  InlineComp Public — public-runtime.js
//
//  Bevat: auto-refresh + web-push + PWA/SW-registratie + install-prompt + profiel-promo.
//  Geextraheerd uit public/app.js op 2026-09-30 (fase 3 van refactor-plan).
//  Klassieke script-tag: gedeelde global scope met de andere public-*.js modules.
// ============================================================

// ── Auto-refresh ──────────────────────────────────────────────────────────
// Stille refresh van programma + lookup voor alle actieve kinderen, elke
// minuut. Stopt als het tabblad onzichtbaar wordt en hervat zodra het weer
// actief is. Toont een tijdstempel "🔄 HH:MM" naast de wedstrijd-keuze
// zodat duidelijk is wanneer de data voor het laatst is bijgewerkt.
//
// Patroon overgenomen uit coach/index.php (regel 2319-2353) — zelfde gedrag,
// zelfde tab-aware optimalisatie.
// Globale stempel-tekst — gebruikt door zowel de persoon-card-template
// (die bij elke render z'n eigen .auto-stempel-span maakt) als de bestaande
// stempel-spans in de DOM. Update via zetStempel().
let _huidigStempel = '';

(function() {
    // 3 minuten — frequente publish/loting-updates komen toch via meldingen-
    // push naar de gebruiker; de poll dient als vangnet voor "ik kijk al een
    // tijdje". Lagere frequentie scheelt aanzienlijk in serverbelasting bij
    // grote wedstrijden waar tientallen toestellen actief zijn.
    const AUTO_REFRESH_MS = 180_000;
    let autoTick = null;

    const zetStempel = () => {
        const d = new Date();
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        // Twee aparte spans: in stap-1-label staan ze inline naast elkaar,
        // in de persoon-card stapelen ze verticaal (icoon boven, tijd onder)
        // — verschil zit in de CSS-context.
        _huidigStempel = `<span class="aut-icon">🔄</span> <span class="aut-tijd">${hh}:${mm}</span>`;
        document.querySelectorAll('.auto-stempel').forEach(el => {
            el.innerHTML = _huidigStempel;
        });
    };

    const wisStempel = () => {
        _huidigStempel = '';
        document.querySelectorAll('.auto-stempel').forEach(el => { el.innerHTML = ''; });
    };

    // Stille refresh: alle kinderen + gedeeld programma in één parallel-batch
    // (geen loader-flash, geen UI-tussenstaten). Bij faal: stempel niet
    // bijwerken — gebruiker ziet dat de tijd "blijft staan".
    const stilleRefresh = async () => {
        // Scroll-positie bewaren: renderKinderen() vervangt divResult.innerHTML
        // en dat zet scroll naar 0. Zonder deze save/restore springt de pagina
        // elke 60s naar boven — hinderlijk als je aan het lezen bent.
        const _scrollY = window.scrollY || window.pageYOffset || 0;
        const compId = selComp.value;
        // Categorieën-cache wissen: klassement_beschikbaar-vlag verandert
        // bij publish/intrek; zonder reset zou dropdown stale blijven tot
        // hard refresh.
        _catCache = null;

        // Mededelingen-check loopt onafhankelijk van of er een wedstrijd is
        // gekozen of kinderen zijn toegevoegd. Zonder compId krijgen we de
        // globale meldingen; mét compId per-wedstrijd + globaal samen.
        checkMeldingen(compId);

        if (!compId || !_kinderen.length) return;
        try {
            // Géén `_t=` cache-buster: server stuurt correcte Cache-Control +
            // ETag + Last-Modified, browser doet automatische revalidatie en
            // krijgt 304 als er niks veranderd is. Een cache-buster zou de
            // URL uniek maken en de If-None-Match-round-trip juist stukmaken.
            const progRes = await safeFetch(
                `?action=programma&competition_id=${encodeURIComponent(compId)}`
            );
            // Een 304-response geeft een lege body — dan hergebruiken we de
            // vorige programma-data die de browser al in cache heeft (fetch
            // API doet dit transparant voor ons: bij 304 levert response.json()
            // gewoon de eerder-gecachte body). Dus geen speciale afhandeling.
            const prog = await progRes.json();
            // Per kind een lookup; parallel uitvoeren voor lagere latency.
            // BELANGRIJK: lookup op license_key wanneer beschikbaar, NIET op
            // startnummer — anders kan bij dubbele snrs (bv. een HP1 en DP1
            // met hetzelfde nummer) de array-volgorde tussen calls wisselen
            // en springt het scherm naar de andere persoon na een refresh.
            // Fallback-keten:
            //   1. license_key       → 1 hit, geen verwarring mogelijk
            //   2. startnummer + re-locate op license   (rijder kreeg later licentie?)
            //   3. startnummer + re-locate op categorie  (geen licentie, maar
            //                                              snr+cat is uniek
            //                                              binnen een wedstrijd)
            //   4. clamp kozen_idx als allerlaatste vangnet
            const kindRefreshes = _kinderen.map(async k => {
                const eerderePersoon = k?.data?.[k.kozen_idx ?? 0]?.persoon;
                const eerderLic = eerderePersoon?.license_key;
                const eerderCat = eerderePersoon?.category;
                const param = eerderLic
                    ? `license_key=${encodeURIComponent(eerderLic)}`
                    : (k?.snr ? `startnummer=${encodeURIComponent(k.snr)}` : null);
                if (!param) return;
                try {
                    const r = await safeFetch(
                        `?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}&_t=${Date.now()}`
                    );
                    const data = await r.json();
                    if (Array.isArray(data) && data.length) {
                        k.data = data;
                        k.prog = prog;
                        if (eerderLic) {
                            // License-lookup → altijd 1 hit, idx blijft 0
                            k.kozen_idx = 0;
                        } else if (data.length > 1) {
                            // Snr-fallback met meerdere hits: re-locate.
                            // Eerst op license (rijder kan tussentijds een
                            // license toegekend hebben gekregen), dan op cat.
                            let opnieuw = -1;
                            if (eerderLic) {
                                opnieuw = data.findIndex(d => d?.persoon?.license_key === eerderLic);
                            }
                            if (opnieuw < 0 && eerderCat) {
                                opnieuw = data.findIndex(d => d?.persoon?.category === eerderCat);
                            }
                            k.kozen_idx = opnieuw >= 0 ? opnieuw : Math.min(k.kozen_idx ?? 0, data.length - 1);
                        } else {
                            k.kozen_idx = 0;
                        }
                    }
                } catch { /* stil — volgende tick probeert opnieuw */ }
            });
            await Promise.all(kindRefreshes);
            // Globale state synchroniseren met actieve kind
            const actief = _kinderen[_activeKindIdx];
            if (actief) {
                window._lookupData = actief.data;
                window._lookupSnr  = actief.snr;
                window._lookupProg = actief.prog;
                window._gekozenIdx = actief.kozen_idx ?? 0;
            }
            // Bewaar dropdown-keuzes (Uitslagen-tab) van actieve kind vóór
            // re-render — initUitslagenTab() zet ze daarna terug zodat de
            // gebruiker zijn categorie/afstand niet kwijtraakt elke 60s.
            _bewaarKindUistate();
            // Idem voor de programma-tab UI-state (filter, klap-balk,
            // handmatig open/dicht) — renderKinderen() reset die anders.
            const _progUiState = _snapshotProgUiStatePub();
            renderKinderen();
            _restoreProgUiStatePub(_progUiState);
            // Scroll herstellen: renderKinderen() vervangt innerHTML, en
            // sommige sub-tabs (Rondes/Uitslagen) laden hun content pas
            // async in. Tussen leeg-DOM en volledig-gerenderd is body korter,
            // waarna de browser scroll naar 0 clampt. Daarom herstellen we
            // meerdere keren over ~800ms zodat de restore ook grijpt als de
            // async fetch klaar is. Als de gebruiker tussentijds zelf
            // scrolt springt hij één keer terug — kleine quirk, maar veel
            // beter dan altijd naar 0.
            const restoreScroll = () => window.scrollTo(0, _scrollY);
            requestAnimationFrame(restoreScroll);
            setTimeout(restoreScroll, 100);
            setTimeout(restoreScroll, 300);
            setTimeout(restoreScroll, 800);
            zetStempel();
        } catch { /* stil */ }
    };

    // Bereken interval op basis van consecutiveFails: bij fouten progressief
    // langer wachten zodat we de server niet hameren als hij eruit ligt.
    // 0 fouten → 60s, 1 → 60s, 2 → 90s, 3+ → 120s. Bij herstel meteen terug
    // naar 60s (gebeurt automatisch want consecutiveFails reset op succes).
    const _tickInterval = () => {
        const f = _conn.consecutiveFails;
        if (f >= 3) return Math.max(AUTO_REFRESH_MS, 120_000);
        if (f === 2) return Math.max(AUTO_REFRESH_MS, 90_000);
        return AUTO_REFRESH_MS;
    };

    const _scheduleTick = () => {
        stop();
        if (!selComp.value || document.hidden) return;
        autoTick = setTimeout(async () => {
            autoTick = null;
            if (document.hidden || !selComp.value) return _scheduleTick();
            await stilleRefresh();
            _scheduleTick();
        }, _tickInterval());
    };

    const start = () => _scheduleTick();

    const stop = () => {
        if (autoTick) { clearTimeout(autoTick); autoTick = null; }
    };

    // Hook voor _conn: bij online-event direct refresh + scheduling resetten.
    _conn.refreshHook = () => {
        if (selComp.value && !document.hidden) {
            stilleRefresh().finally(_scheduleTick);
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else {
            // Eén-shot check (ook globale meldingen) bij terugkeer naar tab.
            stilleRefresh();
            start();
        }
    });

    selComp.addEventListener('change', () => {
        if (selComp.value) {
            zetStempel();
            start();
            // Direct meldingen ophalen — niet wachten op eerste poll-tick.
            checkMeldingen(selComp.value);
        } else {
            stop();
            wisStempel();
            // Switch terug naar landing → één-malig globale meldingen ophalen.
            checkMeldingen('');
        }
    });

    // Initieel: stempel + auto-tick alleen als wedstrijd voorgeselecteerd.
    // Globale meldingen-check loopt sowieso bij page-open.
    if (selComp.value) { zetStempel(); start(); }
    checkMeldingen(selComp.value || '');

    // ── Pull-to-refresh ────────────────────────────────────────────────────
    // Patroon overgenomen uit coach/index.php: alleen actief boven aan de
    // pagina, met 70 px slepen-drempel. Wikkelt stilleRefresh + zetStempel
    // in een ptrEl-status-flow.
    const ptrEl = document.getElementById('ptr');
    const PTR_DREMPEL = 70;
    const PTR_COOLDOWN_MS = 30_000;  // min tijd tussen 2 PTR-acties
    let ptrStartY = null, ptrDragY = 0, ptrActief = false, ptrBezig = false;
    let ptrLaatste = 0;

    async function ptrHerlaad() {
        if (!selComp.value || ptrBezig) return;
        // Cooldown: bij PTR < 30s na vorige tonen we kort een melding ipv
        // de server opnieuw aanroepen. Voorkomt burst bij ongeduld of
        // per-ongeluk-twee-keer-pullen.
        const sindsLaatste = Date.now() - ptrLaatste;
        if (ptrLaatste && sindsLaatste < PTR_COOLDOWN_MS) {
            const wachten = Math.ceil((PTR_COOLDOWN_MS - sindsLaatste) / 1000);
            ptrEl.classList.add('laadt');
            ptrEl.textContent = t('ptr_wachten', {s: wachten});
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 1200);
            return;
        }
        ptrBezig = true;
        ptrEl.classList.add('laadt');
        ptrEl.textContent = t('ptr_vernieuwen');
        try {
            await stilleRefresh();
            zetStempel();
            ptrLaatste = Date.now();
            ptrEl.textContent = t('ptr_bijgewerkt');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 600);
        } catch {
            ptrEl.textContent = t('ptr_fout');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 1200);
        } finally {
            ptrBezig = false;
        }
    }

    // Blokkeer PTR als de veeg begint in een overlay/modal (position:fixed) of in
    // een eigen scroll-gebied — dan hoort de gesture bij dát element, niet bij de
    // pagina. Generiek: dekt álle (ook toekomstige) modals zonder ze op te sommen.
    function _ptrGeblokkeerd(el) {
        for (let n = el; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
            const s = getComputedStyle(n);
            if (s.position === 'fixed') return true;
            if ((s.overflowY === 'auto' || s.overflowY === 'scroll') && n.scrollHeight > n.clientHeight) return true;
        }
        return false;
    }

    document.addEventListener('touchstart', e => {
        if (window.scrollY > 0 || ptrBezig || !selComp.value) { ptrStartY = null; return; }
        if (e.touches.length !== 1) { ptrStartY = null; return; }
        if (_ptrGeblokkeerd(e.target)) { ptrStartY = null; return; }
        ptrStartY = e.touches[0].clientY;
        ptrDragY = 0;
        ptrActief = false;
    }, { passive: true });

    document.addEventListener('touchmove', e => {
        if (ptrStartY === null) return;
        ptrDragY = e.touches[0].clientY - ptrStartY;
        if (ptrDragY <= 0) {
            if (ptrActief) { ptrEl.classList.remove('zichtbaar'); ptrActief = false; }
            return;
        }
        // Actief naar beneden trekken vanaf scroll-top: blokkeer native
        // browser-PTR door preventDefault() (vereist passive:false).
        if (e.cancelable) e.preventDefault();
        if (ptrDragY > 30 && !ptrActief) { ptrEl.classList.add('zichtbaar'); ptrActief = true; }
        ptrEl.textContent = ptrDragY >= PTR_DREMPEL
            ? t('ptr_laat_los') : t('ptr_trek');
    }, { passive: false });

    document.addEventListener('touchend', () => {
        if (ptrStartY === null) return;
        const was = ptrDragY;
        ptrStartY = null; ptrDragY = 0;
        if (ptrActief && was >= PTR_DREMPEL) {
            ptrHerlaad();
        } else if (ptrActief) {
            ptrEl.classList.remove('zichtbaar'); ptrActief = false;
        }
    });

    // Desktop-fallback: dubbelklik op de header refreshed ook
    document.querySelector('header')?.addEventListener('dblclick', ptrHerlaad);
})();

// ── Web Push (Fase 3): meldingen voor je gevolgde rijders ─────────────────
// Open voor iedereen (beta-gate verwijderd bij uitrol zomer 2026). Het blok
// verschijnt zodra je minstens één rijder volgt. De gevolgde licenties (uit
// localStorage) worden meegestuurd; loting/uitslag/mededeling zijn apart aan/uit
// te zetten. Teksten staan in het centrale T-woordenboek (push_*), via t().
let _ppBusy = false;
const _ppSupported = () =>
    ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);

function _ppB64ToUint8(base64) {
    const pad = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
}
const _ppLics    = () => _loadKidsUitStorage().map(k => k.license_key).filter(Boolean);
const _ppPref    = type => localStorage.getItem('ic_pub_push_' + type) !== '0';   // default aan
const _ppSetPref = (type, aan) => localStorage.setItem('ic_pub_push_' + type, aan ? '1' : '0');

async function _ppHuidigAbo() {
    if (!_ppSupported()) return null;
    const reg = await navigator.serviceWorker.ready;
    return await reg.pushManager.getSubscription();
}

// POST subscribe met huidige licenties + voorkeuren (ook voor re-sync).
async function _ppPostSubscribe(sub) {
    const body = Object.assign({}, sub.toJSON(), {
        scope: 'public',
        lang: (typeof getCurLang === 'function') ? getCurLang() : 'nl',
        licenses: _ppLics(),
        notif_loting:  _ppPref('loting')  ? 1 : 0,
        notif_uitslag: _ppPref('uitslag') ? 1 : 0,
        notif_bericht: _ppPref('bericht') ? 1 : 0,
    });
    return fetch('../api/push_subscribe.php?action=subscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    }).then(r => r.json()).catch(() => ({}));
}

async function _ppAan() {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') return 'geweigerd';
    const reg = await navigator.serviceWorker.ready;
    const kr = await fetch('../api/push_pubkey.php').then(r => r.json()).catch(() => ({}));
    if (!kr.publicKey) return 'fout';
    let sub;
    try {
        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true, applicationServerKey: _ppB64ToUint8(kr.publicKey),
        });
    } catch (e) { return 'fout'; }
    const r = await _ppPostSubscribe(sub);
    if (r && r.ok) { localStorage.setItem('ic_pub_push_optin', '1'); return true; }
    return 'fout';
}

async function _ppUit() {
    localStorage.setItem('ic_pub_push_optin', '0');   // bewust uit → niet auto-herstellen
    const sub = await _ppHuidigAbo();
    if (sub) {
        await fetch('../api/push_subscribe.php?action=unsubscribe', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope: 'public', endpoint: sub.endpoint }),
        }).catch(() => {});
        await sub.unsubscribe().catch(() => {});
    }
}

// Zelfherstel: had de gebruiker 'm aan (opt-in), toestemming nog granted, maar
// abonnement door OS/browser gedropt → stil opnieuw abonneren (geen prompt).
async function _ppHeal() {
    if (localStorage.getItem('ic_pub_push_optin') !== '1') return false;
    if (!_ppSupported() || Notification.permission !== 'granted') return false;
    if (await _ppHuidigAbo()) return false;
    return (await _ppAan()) === true;
}

// Re-sync licenties/voorkeuren naar de server ALS er een abonnement is. Wordt
// aangeroepen zodra je gevolgde rijders wijzigen (add/remove) of een voorkeur.
async function _ppSync() {
    try {
        const sub = await _ppHuidigAbo();
        if (sub && Notification.permission === 'granted') await _ppPostSubscribe(sub);
    } catch (e) {}
}

async function _ppRender() {
    const el = document.getElementById('pub-push');
    if (!el) return;
    // Alleen tonen zodra je minstens één rijder volgt.
    if (!_ppLics().length) { el.innerHTML = ''; return; }
    if (!_ppSupported()) {
        const iOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
        el.innerHTML = `<div class="pub-push-titel">🔔 ${t('push_titel')}</div>
            <div class="pub-push-msg">${t('push_niet')}${iOS ? ' ' + t('push_ios') : ''}</div>`;
        return;
    }
    await _ppHeal();   // door OS gedropt abonnement stil terugzetten
    const sub = await _ppHuidigAbo();
    const aan = !!sub && Notification.permission === 'granted';
    el.innerHTML = `
        <div class="pub-push-kop">
            <span class="pub-push-titel">🔔 ${t('push_titel')}</span>
            <button type="button" class="pub-push-toggle" data-aan="${aan ? '1' : '0'}">${
                aan ? t('push_uit') : t('push_aan')}</button>
        </div>
        <div class="pub-push-uitleg">${t('push_uitleg')}</div>
        <div class="pub-push-opties${aan ? '' : ' uit'}">
            <label class="pub-push-opt"><input type="checkbox" data-type="loting"  ${_ppPref('loting')  ? 'checked' : ''}> 🚩 ${t('push_loting')}</label>
            <label class="pub-push-opt"><input type="checkbox" data-type="uitslag" ${_ppPref('uitslag') ? 'checked' : ''}> 🏁 ${t('push_uitslag')}</label>
            <label class="pub-push-opt"><input type="checkbox" data-type="bericht" ${_ppPref('bericht') ? 'checked' : ''}> 📢 ${t('push_bericht')}</label>
        </div>
        <div class="pub-push-rij"${aan ? '' : ' hidden'}>
            <button type="button" class="pub-push-test">${t('push_test')}</button>
            <span class="pub-push-msg"></span>
        </div>`;

    const msg = el.querySelector('.pub-push-msg');
    el.querySelector('.pub-push-toggle').addEventListener('click', async () => {
        if (_ppBusy) return; _ppBusy = true;
        if (msg) msg.textContent = t('push_bezig');
        try {
            if (aan) {
                await _ppUit();
            } else {
                const res = await _ppAan();
                if (res === 'geweigerd')  { if (msg) msg.textContent = t('push_geweigerd'); }
                else if (res !== true)    { if (msg) msg.textContent = t('push_fout'); }
            }
        } catch (e) { if (msg) msg.textContent = t('push_fout'); }
        _ppBusy = false;
        _ppRender();
    });
    el.querySelectorAll('.pub-push-opt input').forEach(cb => cb.addEventListener('change', () => {
        _ppSetPref(cb.dataset.type, cb.checked);
        _ppSync();   // voorkeur meteen naar de server
    }));
    const testBtn = el.querySelector('.pub-push-test');
    if (testBtn) testBtn.addEventListener('click', async () => {
        if (_ppBusy) return; _ppBusy = true;
        if (msg) msg.textContent = t('push_bezig');
        try {
            const cur = await _ppHuidigAbo();
            const resp = await fetch('../api/push_subscribe.php?action=test', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scope: 'public', endpoint: cur ? cur.endpoint : '' }),
            });
            let r = {}; try { r = JSON.parse(await resp.text()); } catch (e) {}
            if (r.ok && (r.result?.verstuurd > 0)) { if (msg) msg.textContent = t('push_testok'); }
            else { if (msg) msg.textContent = '⚠ ' + (r.reden || t('push_mislukt')); }
        } catch (e) { if (msg) msg.textContent = t('push_fout'); }
        _ppBusy = false;
    });
}

// ── PWA: service worker ───────────────────────────────────────────────────
// Update-flow: SW is network-only met cache-cleanup bij activate (zie sw.js).
// reg.update() bij visibility-change zorgt dat browsers nieuwe SW oppikken,
// maar GEEN automatische window.reload() — die wiste input-velden tijdens
// typen (regressie 2026-05-27: gebruikers konden niet meer op zoeken
// klikken doordat hun startnummer-input mid-typen werd gewist).
// Gevolg: nieuwe versie verschijnt pas bij volgende natuurlijke refresh
// of nav. Voor browser-users zorgen PHP no-cache headers dat dat altijd
// vers is. Voor PWA-users: tab sluiten/openen.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').then(reg => {
        const checkUpdate = () => { try { reg.update(); } catch {} };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') checkUpdate();
        });
        setInterval(checkUpdate, 5 * 60 * 1000);
    }).catch(() => {});
}

let _deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredPrompt = e;
    // Toon banner alleen als gebruiker het niet eerder heeft weggeklikt
    if (!localStorage.getItem('pwa-dismissed')) {
        document.getElementById('pwa-banner').hidden = false;
    }
});

document.getElementById('pwa-install')?.addEventListener('click', async () => {
    if (!_deferredPrompt) return;
    _deferredPrompt.prompt();
    const result = await _deferredPrompt.userChoice;
    if (result.outcome === 'accepted') {
        document.getElementById('pwa-banner').hidden = true;
    }
    _deferredPrompt = null;
});

document.getElementById('pwa-sluit')?.addEventListener('click', () => {
    document.getElementById('pwa-banner').hidden = true;
    localStorage.setItem('pwa-dismissed', '1');
});

// Profiel-promo (Mijn InlineComp): standaard ELKE keer tonen. Sluiten (×) verbergt
// 'm alleen deze keer; pas met het vinkje 'Niet meer tonen' blijft-ie weg.
(function () {
    const el = document.getElementById('profiel-promo');
    if (!el) return;
    // Default hidden in HTML (voorkomt FOUC-ruimte boven hub bij app-start).
    // Tonen als user de banner nog niet permanent heeft weggezet.
    try { if (!localStorage.getItem('profiel-promo-nooit')) el.hidden = false; } catch (e) { el.hidden = false; }
    const onthoudAlsGevinkt = () => {
        const niet = document.getElementById('profiel-promo-niet');
        if (niet && niet.checked) { try { localStorage.setItem('profiel-promo-nooit', '1'); } catch (e) {} }
    };
    document.getElementById('profiel-promo-sluit')?.addEventListener('click', () => {
        el.style.display = 'none';
        onthoudAlsGevinkt();
    });
    // Ook onthouden als ze 'niet meer tonen' aanvinken en op 'Bekijk voorbeeld' klikken.
    el.querySelector('a.btn-install')?.addEventListener('click', onthoudAlsGevinkt);
})();

// Verberg banner als app al geinstalleerd is
window.addEventListener('appinstalled', () => {
    document.getElementById('pwa-banner').hidden = true;
    _deferredPrompt = null;
});
