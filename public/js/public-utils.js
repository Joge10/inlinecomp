// ============================================================
//  InlineComp Public — public-utils.js
//
//  Bevat: globals (alleComps/STATUS_KLEUR/BADGE) + esc + safeDatum + verbinding-status-banner + safeFetch.
//  Geextraheerd uit public/app.js op 2026-09-30 (fase 3 van refactor-plan).
//  Klassieke script-tag: gedeelde global scope met de andere public-*.js modules.
// ============================================================

let alleComps = [];

const STATUS_KLEUR = ['#e65100','#2e7d32','#b71c1c','#6a1b9a','#283593','#006064'];
const STATUS_BG    = ['#fff3e0','#e8f5e9','#fce4e4','#f3e5f5','#e8eaf6','#e0f7fa'];
const BADGE = { heats:'badge-serie', kwartfinale:'badge-kf', halve_finale:'badge-hf',
                finale_a:'badge-finale', finale_b:'badge-finale', runner_up:'badge-ru' };
// Ronde-labels worden runtime vertaald via getRondeLabel(rt).
function getRondeLabel(rt) {
    const map = {
        heats: 'ronde_serie',
        kwartfinale: 'ronde_kf',
        halve_finale: 'ronde_hf',
        finale_a: 'ronde_finale',
        finale_b: 'ronde_b_finale',
        runner_up: 'ronde_runner_up',
    };
    return map[rt] ? t(map[rt]) : (rt || '');
}

// Escape HTML meta-chars — ook " en ' zodat het veilig is in attribute-context
// (title="${esc(x)}") én tekst-context. Voorkomt XSS bij namen/titels met " erin.
// Matcht PHP's htmlspecialchars($x, ENT_QUOTES). CodeQL js/incomplete-html-attribute-sanitization.
function esc(s) {
    return String(s??'')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
// Safari kan geen "2026-04-19 10:00:00" parsen, wel "2026-04-19T10:00:00"
function safeDatum(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }

// ── Verbinding-status: detecteert offline / server-down en toont banner ────
// Wordt door safeFetch hieronder bijgewerkt: succes → groen/verborgen,
// fout → banner met passende tekst. window 'online'-event triggert direct
// een refresh; visibilitychange (visible) triggert ook een refresh.
const _conn = {
    online: navigator.onLine,         // browser-niveau
    serverOk: true,                   // laatste API-call succesvol?
    lastSuccess: null,                // Date van laatste OK-fetch
    consecutiveFails: 0,              // voor exponentiële backoff
    refreshHook: null,                // zetten door refresh-functie
};

function _connBannerEl() {
    let el = document.getElementById('conn-banner');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'conn-banner';
    el.className = 'conn-banner';
    el.style.display = 'none';
    document.body.insertBefore(el, document.body.firstChild);
    return el;
}

function _connUpdateBanner() {
    const el = _connBannerEl();
    let bericht = '';
    if (!_conn.online) {
        bericht = t('conn_geen_internet');
    } else if (!_conn.serverOk) {
        bericht = t('conn_server_down');
    }
    if (bericht) {
        const tijd = _conn.lastSuccess
            ? ` <small style="opacity:.85">(${t('conn_laatste_update', {tijd: _conn.lastSuccess.toLocaleTimeString(getLocale(), {hour:'2-digit', minute:'2-digit'})})})</small>`
            : '';
        el.innerHTML = bericht + tijd;
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

// Grace-periode: na een fout blijft de banner ten minste deze tijd staan,
// ook als andere fetches in de tussentijd slagen. Voorkomt geflikker bij
// gemengde fouten (één endpoint down, ander werkt).
const _CONN_GRACE_MS = 10_000;

function _connOk() {
    const wasFout = !_conn.serverOk || !_conn.online;
    _conn.lastSuccess = new Date();
    _conn.consecutiveFails = 0;
    // Binnen grace-periode na een fout: server-vlag pas herstellen als de
    // grace voorbij is. navigator-online vlag (echte OS-status) volgt wél
    // direct het laatste signaal.
    const inGrace = _conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) < _CONN_GRACE_MS;
    _conn.online = true;
    if (!inGrace) _conn.serverOk = true;
    if (wasFout && !inGrace) _connUpdateBanner();
}

function _connFail(reden) {
    if (reden === 'network') _conn.online = false;
    else                     _conn.serverOk = false;
    _conn.lastFailureMs = Date.now();
    _conn.consecutiveFails++;
    _connUpdateBanner();
    // Plan een banner-recheck na de grace-periode zodat 'ie automatisch
    // verdwijnt als er ondertussen geen nieuwe fouten meer komen.
    setTimeout(() => {
        if (_conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) >= _CONN_GRACE_MS) {
            // Geen recente fouten meer — verifieer met een laatste lookup
            _conn.serverOk = true;
            _connUpdateBanner();
        }
    }, _CONN_GRACE_MS + 100);
}

// Triggers voor automatisch herstel
window.addEventListener('online', () => {
    _conn.online = true;
    _connUpdateBanner();
    if (typeof _conn.refreshHook === 'function') _conn.refreshHook();
});
window.addEventListener('offline', () => {
    _conn.online = false;
    _connUpdateBanner();
});

// Fetch met retry bij 429 + verbinding-status-tracking. Bij netwerkfout
// (TypeError 'Failed to fetch') of 5xx: banner aan + niet retry'en
// (auto-refresh-tick probeert vanzelf opnieuw met exponentiële backoff).
// Retry-strategie: max 1× opnieuw bij 429 met random jitter (2-5 s) zodat
// honderden publieke bezoekers niet synchroon weer aankloppen en de
// rate-limit nog erger maken.
// Demo-modus: staat er ?demo in de pagina-URL, dan hangt safeFetch aan elke
// API-call &demo=1 zodat de backend alleen demo-wedstrijden toont/toelaat.
const DEMO_MODE = new URLSearchParams(location.search).has('demo');
async function safeFetch(url, optsOrRetries = 1, maxRetriesArg) {
    // Backward-compat: oude signature safeFetch(url, maxRetries). Nieuwe
    // signature: safeFetch(url, fetchOpts, maxRetries). Als 2e arg een getal
    // is, oude vorm; anders options-object (voor POST, headers, body).
    const opts       = (typeof optsOrRetries === 'object' && optsOrRetries) ? optsOrRetries : undefined;
    const maxRetries = (typeof optsOrRetries === 'number') ? optsOrRetries : (maxRetriesArg ?? 1);
    if (DEMO_MODE) url += (url.includes('?') ? '&' : '?') + 'demo=1';
    try {
        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            const res = await fetch(url, opts);
            if (res.status === 429 && attempt < maxRetries) {
                const wait = 2000 + Math.random() * 3000;
                await new Promise(r => setTimeout(r, wait));
                continue;
            }
            if (res.status >= 500) {
                _connFail('server');
                return res;
            }
            if (res.status === 429) {
                _connFail('server');
                return res;
            }
            _connOk();
            return res;
        }
        return new Response(null, { status: 504 });
    } catch (e) {
        // TypeError = netwerkfout (geen verbinding, DNS, etc.)
        _connFail('network');
        throw e;
    }
}
// Puntenkoers: punten van gelapte rijders (minder ronden dan de leider) zijn
// vervallen voor de klassering (server rekent dit door in de rang). Toon de
// behaalde punten wél, doorgestreept + note. Inline-styled zodat het los van
// het stylesheet werkt.
