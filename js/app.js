/* InlineComp – globals, hulpfuncties, wedstrijdenlijst, navigatie */

const BASE = '';   // zelfde origin, geen absolute URL nodig

let allWedstrijden  = [];
let activeCard      = null;
let activeCat       = null;
const MAX_ZONDER_FILTER = 5;

// Vergelijkingsdata (gevuld door vergelijk.php)
let vergelijkData = [];         // [{dc_id, dc_name, dc_number, competitors:[…]}]
let personEdits   = {};         // {license_key: {start_number, full_name, transponder1, transponder2, …}}
let entryEdits    = {};         // {"dcId_lk": {entry_status, reserve, knsb_entry_id}}
let manualTp      = new Set();  // "lk_1" / "lk_2" — handmatig gewijzigde transponders
let huidigCompId  = null;       // competition id van de geopende wedstrijd
let huidigComp    = null;       // volledig comp-object van de geopende wedstrijd

const STATUS_LABELS = ['Niet bevestigd', 'Bevestigd', 'Afgemeld', 'Afgem. bij org.', 'Niet getekend', 'Bevestigd bij org.'];
const STATUS_CSS    = ['status-0',        'status-1',  'status-2', 'status-3',        'status-4',      'status-5'];

let startlijstCache  = {};    // {cacheKey: {rondenConfig, ronde1, cFinale, bFinale}}
let isGeimporteerd   = false; // ≥1 deelnemer heeft db_entry in DB
let heeftWijzigingen = false; // onopgeslagen bewerkingen in huidige sessie
let entriesVersion   = null;  // voor optimistic locking bij import
let gewijzigdeRijen  = new Set(); // license_keys van gewijzigde (nog niet opgeslagen) rijen
let huidigOrganisatie = null; // organisatie-object van huidig geselecteerde wedstrijd
let huidigBaan        = null; // baan-object (gastheer-vereniging + logo) van huidige wedstrijd
let huidigImported    = false; // is de huidige wedstrijd al geïmporteerd? (DB-row aanwezig)
let dcDistances       = {};   // {dc_id: [{id, number, name, value_meters}]} – KNSB afstanden per DC
let standDatum        = '';   // tijdstip KNSB-ophaling voor tekenlijst (dd-mm-yyyy HH:mm)
let dbStandDatum      = '';   // tijdstip laatste DB-import voor tekenlijst (dd-mm-yyyy HH:mm)
let vergelijkAbort    = null; // AbortController voor lopende vergelijk.php-fetch

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

function el(id) { return document.getElementById(id); }

// ── Globale 401-interceptor: sessie verlopen → login-modal ───────────────────
(function() {
    const _origFetch = window.fetch;
    let _loginPromise = null;  // gedeelde promise waar alle 401-requests op wachten

    window.fetch = async function(...args) {
        const res = await _origFetch.apply(this, args);
        if (res.status === 401) {
            // Voorkom dat auth-endpoint zelf de modal triggert
            const url = String(args[0]?.url ?? args[0] ?? '');
            if (url.includes('api/auth.php')) return res;

            // Eerste 401 opent de modal; volgende wachten op dezelfde promise
            if (!_loginPromise) {
                _loginPromise = _toonLoginModal().finally(() => { _loginPromise = null; });
            }
            await _loginPromise;

            // Na succesvol inloggen: origineel request opnieuw uitvoeren
            return _origFetch.apply(this, args);
        }
        return res;
    };

    function _toonLoginModal() {
        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.innerHTML = `
                <div class="modal-dialog" role="dialog" aria-modal="true" style="max-width:360px">
                    <div class="modal-header">
                        <span class="modal-icon">🔒</span>
                        <span>Sessie verlopen</span>
                    </div>
                    <div class="modal-body">
                        <p style="margin-bottom:12px">Je sessie is verlopen. Log opnieuw in om verder te gaan.</p>
                        <label style="display:block;margin-bottom:8px">
                            <span style="font-size:.85rem;font-weight:600">Gebruikersnaam</span>
                            <input type="text" id="relogin-user" class="inp" style="width:100%;margin-top:3px" autocomplete="username">
                        </label>
                        <label style="display:block;margin-bottom:4px">
                            <span style="font-size:.85rem;font-weight:600">Wachtwoord</span>
                            <input type="password" id="relogin-pass" class="inp" style="width:100%;margin-top:3px" autocomplete="current-password">
                        </label>
                        <div id="relogin-fout" style="color:#c00;font-size:.82rem;min-height:1.2em;margin-top:6px"></div>
                    </div>
                    <div class="modal-knoppen">
                        <button class="modal-btn modal-doorgaan" id="relogin-btn">Inloggen</button>
                    </div>
                </div>`;

            document.body.appendChild(overlay);

            const userInp = overlay.querySelector('#relogin-user');
            const passInp = overlay.querySelector('#relogin-pass');
            const btn     = overlay.querySelector('#relogin-btn');
            const foutEl  = overlay.querySelector('#relogin-fout');

            // Prefill gebruikersnaam als die bekend is
            if (typeof currentUser !== 'undefined' && currentUser?.username)
                userInp.value = currentUser.username;

            const doeLogin = async () => {
                foutEl.textContent = '';
                btn.disabled = true;
                btn.textContent = 'Bezig…';
                try {
                    const r = await _origFetch('api/auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action:   'login',
                            username: userInp.value.trim(),
                            password: passInp.value,
                        }),
                    });
                    const d = await r.json();
                    if (!r.ok) {
                        foutEl.textContent = d.error ?? 'Inloggen mislukt';
                        btn.disabled = false;
                        btn.textContent = 'Inloggen';
                        passInp.value = '';
                        passInp.focus();
                        return;
                    }
                    overlay.remove();
                    resolve();
                } catch (e) {
                    foutEl.textContent = 'Netwerkfout: ' + e.message;
                    btn.disabled = false;
                    btn.textContent = 'Inloggen';
                }
            };

            btn.addEventListener('click', doeLogin);
            passInp.addEventListener('keydown', e => { if (e.key === 'Enter') doeLogin(); });
            userInp.addEventListener('keydown', e => { if (e.key === 'Enter') passInp.focus(); });

            setTimeout(() => (userInp.value ? passInp : userInp).focus(), 100);
        });
    }
})();

function setHTML(id, html) { el(id).innerHTML = html; }

function statusMsg(container, type, tekst) {
    container.innerHTML = `<div class="status-msg ${type}">${tekst}</div>`;
}

function getLocatie(comp) {
    const v = comp.venue;
    if (v && (v.address?.city || v.name)) {
        const delen = [v.address?.city, v.name].filter(Boolean);
        return delen.join(' \u2013 ');
    }
    return (comp.location || '').split('\n')[0].trim();
}

function getOrganisatieEmail(comp) {
    return (comp.settings?.contact?.email ?? '').toLowerCase().trim();
}

function getOrganisatieNaam(comp) {
    return (comp.settings?.contact?.organizationName
         ?? comp.organizer?.name
         ?? comp.organiser?.name
         ?? '').trim();
}

// Multi-tenant: filtert de KNSB-feed op naam-match met user's scope.
// currentUser.organisatie_namen = lowercased canonieke + alias-namen. Leeg
// (unscoped) → return alle wedstrijden ongewijzigd. Een wedstrijd matcht als
// haar organizer-naam (lowercased) voorkomt in de scope-set.
function filterWedstrijdenOpScope(comps) {
    const scope = (currentUser?.organisatie_namen) || [];
    if (scope.length === 0) return comps;   // unscoped → geen filter
    const set = new Set(scope);
    return comps.filter(c => {
        const naam = getOrganisatieNaam(c).toLowerCase().trim();
        return naam !== '' && set.has(naam);
    });
}

function formatDatum(str) {
    if (!str) return '';
    const d = new Date(str);
    return d.toLocaleDateString('nl-NL', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}


// ── Gedeelde org-logo + baan-logo header + sponsor-footer voor print ─────────
// Gebruikt door: uitslag, tijdschema, tekenlijsten, deelnemerslijst, ranking,
// print-center shared-header.
// Retourneert { orgLogoHtml, baanLogoHtml, footerHtml } — volledig inline-styled,
// geen externe CSS nodig. Plaats baanLogoHtml links in de header (= gastheer-
// vereniging) en orgLogoHtml rechts (= hoofdorganisator).
// ── Systeem-pagina ───────────────────────────────────────────────────────────
// Bundelt 5 admin-functies in één pagina met sub-tabs: Gebruikers, Bezoekers,
// Logboek, Rijders, Uploads. Tabs zijn lazy: een tab-init functie wordt pas
// aangeroepen wanneer de tab voor het eerst geopend wordt.
let _sysTabsGeinit = false;
const _sysTabGeladen = new Set();

function toonSysteemPagina() {
    if (!['owner','admin'].includes(currentUser.role)) return;

    if (!_sysTabsGeinit) {
        _sysTabsGeinit = true;
        document.querySelectorAll('#sys-tabs-nav .org-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => switchSysteemTab(btn.dataset.tab));
        });
    }

    // Eerste keer: open Gebruikers-tab; vervolgens onthoud-actief-tab
    const actief = document.querySelector('#sys-tabs-nav .org-tab-btn.active')?.dataset.tab || 'gebruikers';
    switchSysteemTab(actief);
}

function switchSysteemTab(tab) {
    document.querySelectorAll('#sys-tabs-nav .org-tab-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('#page-systeem .org-tab-content').forEach(c => {
        c.style.display = (c.id === 'sys-tab-' + tab) ? '' : 'none';
    });

    // Polling-cleanup: als we Bezoekers verlaten, stop de stats-refresh
    // (anders blijft die elke 30s public_stats + coach_stats fetchen,
    // vervuilt de network-tab en verbruikt onnodig server-cycles).
    if (tab !== 'bezoekers' && typeof stopPublicStatsRefresh === 'function') {
        stopPublicStatsRefresh();
    }

    // Lazy init per tab. Belangrijke veiligheidsklep: ook re-rendere als
    // de cache zegt "al geladen" maar de container in werkelijkheid leeg
    // of nog op de Laden…-placeholder staat. Dat kon eerder gebeuren bij
    // een silent fout in een loader, of als een eerdere navigatie de DOM
    // had hersteld zonder de cache te invalideren → tab bleef dan
    // visueel leeg ondanks klikken.
    const containerMap = {
        gebruikers: 'gb-container',
        bezoekers:  'gb-bezoekers-container',
        logboek:    'gb-logboek-container',
        rijders:    'rij-detail',
        uploads:    'up-container',
        helpers:    'hp-container',
    };
    const cont = document.getElementById(containerMap[tab]);
    // "Echt geladen" = container bestaat én heeft content die niet meer
    // de initiële .loading-placeholder is.
    const echtGeladen = cont
        && cont.children.length > 0
        && !cont.querySelector(':scope > .status-msg.loading');

    if (!_sysTabGeladen.has(tab) || !echtGeladen) {
        _sysTabGeladen.add(tab);
        if (tab === 'gebruikers' || tab === 'bezoekers' || tab === 'logboek') toonGebruikersPagina();
        if (tab === 'rijders')  toonRijdersPagina();
        if (tab === 'uploads')  toonUploadsPagina();
        if (tab === 'helpers')  toonHelpersPagina();
    }
}

// ── Info-pagina vullen ────────────────────────────────────────────────────────
function toonInfoPagina() {
    const ROL_LABELS = {
        owner: 'Owner', admin: 'Admin', importer: 'Importer',
        planner: 'Planner', timer: 'Timer', viewer: 'Viewer',
    };
    const userEl = el('info-user');
    const rolEl  = el('info-rol');
    if (userEl) userEl.textContent = currentUser?.naam || currentUser?.username || '—';
    if (rolEl)  rolEl.textContent  = ROL_LABELS[currentUser?.role] || currentUser?.role || '—';

    const brEl = el('info-browser');
    if (brEl) {
        const ua  = navigator.userAgent;
        const m   = /(Edg|Chrome|Firefox|Safari)\/(\d+)/.exec(ua);
        brEl.textContent = m ? `${m[1]} ${m[2]}` : ua.substring(0, 80);
    }
    const onEl = el('info-online');
    if (onEl) onEl.textContent = navigator.onLine ? 'online' : 'offline';
}

function bouwOrgHeaderFooter(esc) {
    const baseUrl = new URL('.', window.location.href).href;
    const org = huidigOrganisatie;
    const baan = (typeof huidigBaan !== 'undefined') ? huidigBaan : null;
    // Cache-buster zodat een nieuw geüpload logo niet uit de browser-cache blijft
    // hangen in prints. Gebruikt updated_at indien aanwezig (stabiel = cache-vriendelijk
    // als er niks verandert), anders Date.now() als veilige fallback.
    const cb = encodeURIComponent(
        org?.updated_at ?? org?.logo_updated_at ?? String(Date.now())
    );
    const baanCb = encodeURIComponent(
        baan?.logo_updated_at ?? baan?.updated_at ?? String(Date.now())
    );

    // Organisatie-logo (rechtsboven in header)
    const orgLogoHtml = org?.logo_path
        ? `<span style="display:block;height:20mm;max-width:50mm;overflow:hidden;line-height:0;text-align:right;">` +
          `<img src="${esc(baseUrl + org.logo_path)}?v=${cb}" alt="${esc(org.naam)}" ` +
          `style="height:20mm;width:auto;max-width:50mm;display:inline-block;object-fit:contain;vertical-align:top;"></span>`
        : (org?.naam ? `<span style="font-size:8pt;color:#555;font-style:italic;">${esc(org.naam)}</span>` : '');

    // Baan-logo (linksboven in header — gastheer-vereniging). Alleen het logo
    // zelf, geen bijschrift; print blijft zo schoner. Heeft de baan geen logo
    // maar wel een vereniging-naam? Dan tonen we die als kleine tekst zodat
    // duidelijk is welke club gastheer is.
    let baanLogoHtml = '';
    if (baan?.logo_path) {
        baanLogoHtml = `<span style="display:block;height:20mm;max-width:50mm;overflow:hidden;line-height:0;text-align:left;">` +
            `<img src="${esc(baseUrl + baan.logo_path)}?v=${baanCb}" alt="${esc(baan.vereniging_naam ?? baan.naam ?? '')}" ` +
            `style="height:20mm;width:auto;max-width:50mm;display:inline-block;object-fit:contain;vertical-align:top;"></span>`;
    } else if (baan?.vereniging_naam) {
        baanLogoHtml = `<span style="display:block;font-size:9pt;font-weight:600;color:#1a3a5c;line-height:1.2;">${esc(baan.vereniging_naam)}</span>`;
    }

    // Sponsor-footer: org-sponsors + baan-sponsors samenvoegen (org eerst,
    // dan baan-sponsors achteraan — zelfde volgorde als public/coach footer).
    let footerHtml = '';
    const alleSponsors = [
        ...(org?.sponsors  ?? []),
        ...(baan?.sponsors ?? []),
    ];
    if (alleSponsors.length) {
        const sponsorItems = alleSponsors.map(s => {
            const sCb = encodeURIComponent(
                s?.updated_at ?? s?.logo_updated_at ?? cb
            );
            return `<span style="display:inline-flex;align-items:center;">` +
            (s.logo_path
                ? `<span style="display:inline-block;height:10mm;max-width:35mm;overflow:hidden;line-height:0;">` +
                  `<img src="${esc(baseUrl + s.logo_path)}?v=${sCb}" alt="${esc(s.naam)}" ` +
                  `style="height:10mm;width:auto;max-width:35mm;display:block;object-fit:contain;"></span>`
                : `<span style="font-size:7pt;color:#555;">${esc(s.naam)}</span>`) +
            `</span>`;
        }).join('');
        footerHtml = `<div class="org-sponsor-footer" style="margin-top:3mm;border-top:1px solid #ddd;padding-top:2mm;display:flex;align-items:center;justify-content:center;gap:5mm;flex-wrap:wrap;">${sponsorItems}</div>`;
    }

    return { orgLogoHtml, baanLogoHtml, footerHtml };
}

// Vult de ts-comp-naam / ts-comp-meta header op een pagina met de huidige wedstrijd
function vulPaginaHeader(naamId, metaId) {
    const naamEl = el(naamId);
    const metaEl = el(metaId);
    if (naamEl) naamEl.textContent = huidigComp?.name || '';
    if (metaEl) metaEl.textContent = huidigComp
        ? formatDatum(huidigComp.starts || '') + ' · ' + getLocatie(huidigComp)
        : '';
}

// Discreet label in de mainbar (naast KNSB-badge) met de huidige wedstrijd-
// naam. Verschijnt vanaf het moment dat er een wedstrijd in Importeer is
// gekozen; verdwijnt bij reset (lege textContent → :empty hide via CSS).
function _setHeaderWedstrijd(comp) {
    // LET OP: niet 'el' noemen — shadowt de file-scope `function el(id)`
    // (DOM-helper op regel 36). Werkt nu OK omdat er geen el()-aanroep
    // vóór deze const staat, maar future-proof: als iemand boven deze
    // regel iets als `el('iets')?` toevoegt, krijg je een TDZ-crash.
    const target = document.getElementById('header-wedstrijd');
    if (!target) return;
    target.textContent = comp?.name || '';
}

// ── Wedstrijdenlijst laden ────────────────────────────────────────────────────

async function laadWedstrijden() {
    const list = el('comp-list');
    const btn  = el('btn-ververs-wedstrijden');
    const icon = el('ververs-icon');
    if (btn)  btn.disabled = true;
    if (icon) icon.style.animation = 'spin 0.8s linear infinite';
    try {
        const vanDatum = el('filter-van')?.value || '';
        const vanParam = vanDatum ? `?van=${encodeURIComponent(vanDatum)}` : '';
        // Parallel: KNSB-feed + eigen handmatige wedstrijden uit DB. Mergen
        // in één lijst zodat operator beide vanuit hetzelfde scherm kan
        // selecteren. Handmatige wedstrijden zijn al server-side scope-gefilterd
        // (via wedstrijd_handmatig.php?action=lijst).
        const [resKnsb, resHand] = await Promise.all([
            fetch(BASE + 'api/competitions.php' + vanParam),
            fetch(BASE + 'api/wedstrijd_handmatig.php?action=lijst'),
        ]);
        if (!resKnsb.ok) throw new Error('KNSB-feed HTTP ' + resKnsb.status);
        const dataKnsb = await resKnsb.json();
        // Handmatige wedstrijden mogen falen zonder de hele lijst te breken
        // (bv. nog niet gemigreerd). Logging blijft, lijst blijft bruikbaar.
        let dataHand = [];
        if (resHand.ok) {
            try { dataHand = await resHand.json(); }
            catch (e) { console.warn('Handmatige wedstrijden parse-fout', e); }
        } else {
            console.warn('Handmatige wedstrijden HTTP ' + resHand.status);
        }

        if (dataKnsb.error) throw new Error(dataKnsb.error);
        if (!dataKnsb.length && !dataHand.length) {
            statusMsg(list, 'info', 'Geen aankomende inline wedstrijden gevonden.');
            return;
        }

        // Multi-tenant: KNSB-feed wedstrijden krijgen scope-filter op naam-match.
        // Handmatige wedstrijden zijn al server-side scope-gefilterd en
        // worden hier ONGEFILTERD toegevoegd (naam-match zou ze ten onrechte
        // wegfilteren als de canonieke org-naam in de DB iets afwijkt).
        // Mergen en sorteren op startdatum ASC — zelfde volgorde als de
        // KNSB-feed voorheen (oudste eerst, "eerstkomende" bovenaan).
        const gescoptKnsb = filterWedstrijdenOpScope(dataKnsb);
        allWedstrijden = [...gescoptKnsb, ...dataHand]
            .sort((a, b) => (a.starts || '').localeCompare(b.starts || ''));
        if (!allWedstrijden.length) {
            statusMsg(list, 'info', 'Geen wedstrijden van jouw organisatie(s) gevonden.');
            return;
        }
        vulLocatieDropdown();
        vulOrganisatieDropdown();
        renderWedstrijdLijst();

        // Als er al een wedstrijd geselecteerd is: update huidigComp met verse KNSB-data
        // en herlaad de vergelijking zodat nieuwe/gewijzigde inschrijvingen zichtbaar worden
        if (huidigCompId) {
            const vernieuwd = allWedstrijden.find(c => c.id === huidigCompId);
            if (vernieuwd) huidigComp = vernieuwd;
            herlaadVergelijking();
        }

    } catch(e) {
        statusMsg(list, 'error', '⚠ Kon wedstrijden niet laden: ' + e.message);
    } finally {
        if (btn)  btn.disabled = false;
        if (icon) icon.style.animation = '';
    }
}

function vulLocatieDropdown() {
    const sel    = el('filter-locatie');
    const uniek  = [...new Set(allWedstrijden.map(getLocatie).filter(Boolean))].sort();
    const huidig = sel.value;
    sel.innerHTML = '<option value="">— alle —</option>';
    uniek.forEach(loc => {
        const opt = document.createElement('option');
        opt.value = loc;
        opt.textContent = loc;
        if (loc === huidig) opt.selected = true;
        sel.appendChild(opt);
    });
}

function vulOrganisatieDropdown() {
    const sel    = el('filter-organisatie');
    const huidig = sel.value;

    // Groepeer op email (of naam als fallback) → tel per naam hoe vaak die voorkomt
    const groepen = new Map();  // key (email|naam) → Map(naam → count)
    for (const comp of allWedstrijden) {
        const email = getOrganisatieEmail(comp);
        const naam  = getOrganisatieNaam(comp);
        if (!email && !naam) continue;
        const key = email || naam;
        if (!groepen.has(key)) groepen.set(key, new Map());
        if (naam) {
            const tellers = groepen.get(key);
            tellers.set(naam, (tellers.get(naam) ?? 0) + 1);
        }
    }

    // Canonieke naam per groep = meest voorkomende naam
    const opties = [];
    for (const [key, namenMap] of groepen) {
        if (!namenMap.size) continue;
        const canoniek = [...namenMap.entries()]
            .sort((a, b) => b[1] - a[1])[0][0];
        opties.push({ key, label: canoniek });
    }
    opties.sort((a, b) => a.label.localeCompare(b.label, 'nl'));

    sel.innerHTML = '<option value="">— alle —</option>';
    for (const { key, label } of opties) {
        const opt = document.createElement('option');
        opt.value       = key;
        opt.textContent = label;
        if (key === huidig) opt.selected = true;
        sel.appendChild(opt);
    }
}

function renderWedstrijdLijst() {
    const list = el('comp-list');
    const van  = el('filter-van').value;
    const tot  = el('filter-tot').value;
    const loc  = el('filter-locatie').value;
    const org  = el('filter-organisatie').value;

    let gefilterd = allWedstrijden;
    if (van) gefilterd = gefilterd.filter(c => (c.starts || '') >= van);
    if (tot) gefilterd = gefilterd.filter(c => (c.starts || '') <= tot + 'T23:59:59');
    if (loc) gefilterd = gefilterd.filter(c => getLocatie(c) === loc);
    if (org) gefilterd = gefilterd.filter(c => {
        const email = getOrganisatieEmail(c);
        const naam  = getOrganisatieNaam(c);
        return (email || naam) === org;
    });

    if (!gefilterd.length) {
        statusMsg(list, 'info', 'Geen wedstrijden gevonden met deze filters.');
        return;
    }

    const toonAlles = van || tot || loc || gefilterd.length <= MAX_ZONDER_FILTER;
    const zichtbaar = toonAlles ? gefilterd : gefilterd.slice(0, MAX_ZONDER_FILTER);

    list.innerHTML = '';
    zichtbaar.forEach(comp => {
        const card = document.createElement('div');
        card.className = 'comp-card';
        if (activeCard && comp.id === huidigCompId) {
            card.classList.add('active');
            activeCard = card;   // synchroon houden met opnieuw-gerenderd DOM-element
        }

        const loc  = getLocatie(comp);
        const datum = formatDatum(comp.starts);
        // Badge voor handmatig aangemaakte wedstrijden (niet uit KNSB-feed).
        // Helpt operator direct te zien welke flow erbij hoort (geen KNSB-sync).
        const handBadge = comp.is_handmatig
            ? ' <span class="comp-bron-badge" title="Handmatig aangemaakt — geen KNSB-feed-koppeling">🔧 handmatig</span>'
            : '';
        card.innerHTML =
            `<div class="comp-naam">${escHtml(comp.name || comp.title || '')}${handBadge}</div>` +
            `<div class="comp-meta">${escHtml(datum)}${loc ? ' · ' + escHtml(loc) : ''}</div>`;

        card.addEventListener('click', () => selectWedstrijd(card, comp));
        list.appendChild(card);
    });

    if (!toonAlles) {
        const meer = document.createElement('div');
        meer.className = 'comp-meer';
        meer.textContent = `+ ${gefilterd.length - MAX_ZONDER_FILTER} meer — gebruik filters om te verfijnen`;
        list.appendChild(meer);
    }
}

// ── Wedstrijd selecteren ──────────────────────────────────────────────────────

async function selectWedstrijd(card, comp) {
    if (heeftWijzigingen) {
        if (!await toonBevestigDialog('Er zijn onopgeslagen wijzigingen.\nDoorgaan zonder op te slaan?')) return;
    }

    // Annuleer eventueel lopende vergelijk.php-aanvraag om 503 door server-overload te voorkomen
    if (vergelijkAbort) vergelijkAbort.abort();
    vergelijkAbort = new AbortController();

    if (activeCard) activeCard.classList.remove('active');
    card.classList.add('active');
    activeCard   = card;
    huidigCompId = comp.id;
    huidigComp   = comp;

    // Header-label bijwerken zodat operator zonder naar Importeer te wisselen
    // ziet welke wedstrijd er nu actief is in elke module.
    _setHeaderWedstrijd(comp);

    // Print-Center state resetten bij (andere) wedstrijd — header-knop enablen
    window.printCenterResetVoorWedstrijd?.(comp.id);

    const panel = el('detail-panel');
    panel.style.display = 'block';
    el('detail-title').textContent = comp.name || comp.title || '';
    el('detail-meta').textContent  = formatDatum(comp.starts) + ' · ' + getLocatie(comp);
    el('import-result').innerHTML  = '';
    el('imp-cat-tabs').innerHTML   = '';
    startlijstCache = {};
    setHTML('imp-cat-content', '<div class="status-msg loading"><span class="spinner"></span>Vergelijken met database…</div>');

    el('btn-import').onclick = () => importeerWedstrijd(comp.id, comp.name || '');
    el('btn-export').onclick = () => exporteerWedstrijdCsv(comp.id, comp.name || '');
    el('btn-csv-import').onclick = () => {
        if (typeof csvImportOpenWizard === 'function') csvImportOpenWizard();
    };

    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Handmatige wedstrijd: vergelijk.php fetched KNSB-data en faalt voor deze
    // bron. We gebruiken het detail-endpoint dat een vergelijk-compatibele
    // shape geeft uit eigen DB (lege competitors per cat, DC's, organisatie).
    // Importeer/Export-knoppen worden verborgen — geen KNSB-roundtrip mogelijk.
    // Andersom: CSV-Importeer is juist alléén voor handmatige wedstrijden
    // (alternatief voor de KNSB-feed-flow).
    if (comp.is_handmatig) {
        el('btn-import').style.display     = 'none';
        el('btn-export').style.display     = 'none';
        el('btn-csv-import').style.display = '';
    } else {
        el('btn-import').style.display     = '';
        el('btn-export').style.display     = '';
        el('btn-csv-import').style.display = 'none';
    }

    const myAbort = vergelijkAbort;
    const endpoint = comp.is_handmatig
        ? 'api/wedstrijd_handmatig.php?action=detail&id=' + encodeURIComponent(comp.id)
        : 'api/vergelijk.php?id=' + encodeURIComponent(comp.id);
    try {
        const res = await fetch(endpoint, { signal: myAbort.signal });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const vData = await res.json();
        if (vData.error) throw new Error(vData.error);
        vergelijkData     = vData.groepen     ?? vData; // backwards compat
        huidigOrganisatie = vData.organisatie ?? null;
        huidigBaan        = vData.baan        ?? null;
        huidigImported    = !!vData.imported;
        standDatum        = vData.knsb_stand  ?? '';
        dbStandDatum      = vData.db_stand    ?? '';
        entriesVersion    = vData.entries_version ?? 0;
        _heeftProgramma   = !!(vData.heeft_programma);
        _orgTransponders  = vData.org_transponders ?? [];

        zetKnsbTimestamp();
        initEdits();
        bouwVergelijkTabbladen();
        updateImportBtn();
        renderBaanRij();

    } catch(e) {
        if (e.name === 'AbortError') return; // nieuwere klik heeft deze aanvraag afgebroken
        setHTML('imp-cat-content', `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`);
    }
}

// ── Baan-rij in detail-header ────────────────────────────────────────────────
//
// Toont de huidig gekoppelde baan onder de wedstrijd-meta, met een ✎-knop om
// een andere baan te kiezen (of een baan toe te wijzen aan een wedstrijd die
// nog géén baan heeft — bv. oude imports zonder venue_name). De dropdown bevat
// alle unieke banen over alle organisaties heen.

function renderBaanRij() {
    const rij = el('detail-baan-rij');
    const txt = el('detail-baan');
    if (!rij || !txt || !huidigCompId) return;

    // Niet-geïmporteerde wedstrijd: geen baan-koppeling mogelijk (er is nog
    // geen competition-rij in de DB). Toon hint i.p.v. "geen baan toegewezen".
    if (!huidigImported) {
        txt.innerHTML = `<b>Baan:</b> <span class="baan-leeg">— eerst importeren —</span>`;
        rij.style.display = '';
        return;
    }

    const baanLabel = huidigBaan
        ? `<span class="baan-naam">${escHtml(huidigBaan.naam || '—')}</span>`
          + (huidigBaan.stad ? ` <span class="baan-stad">(${escHtml(huidigBaan.stad)})</span>` : '')
        : '<span class="baan-leeg">geen baan toegewezen</span>';

    txt.innerHTML = `<b>Baan:</b> ${baanLabel} `
        + `<button type="button" class="baan-edit-btn" id="btn-baan-edit" title="Baan wijzigen">✎</button>`;
    rij.style.display = '';

    el('btn-baan-edit')?.addEventListener('click', openBaanModal);
}

async function openBaanModal() {
    if (!huidigCompId) return;
    let banen = [];
    try {
        const res = await fetch('api/banen.php?action=alle');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        banen = await res.json();
    } catch (e) {
        toonBevestigDialog('Banen laden mislukt: ' + e.message, 'Fout');
        return;
    }

    // Match op naam: de dropdown is gededupliceerd op naam (MIN(id)), dus de
    // id in de lijst kan een andere zijn dan huidigBaan.id (dezelfde fysieke
    // baan kan onder meerdere orgs als aparte rij voorkomen). Naam is uniek
    // per fysieke baan, dus dat is de stabiele match-key.
    const huidigNaam = huidigBaan?.naam ?? '';
    // Voor de zekerheid: als huidige baan-naam toch niet in de lijst zit,
    // voeg hem alsnog vooraan toe zodat de gebruiker hem geselecteerd ziet.
    const heeftHuidig = huidigNaam && banen.some(b => b.naam === huidigNaam);
    const lijst = heeftHuidig
        ? banen
        : (huidigBaan ? [{ id: huidigBaan.id, naam: huidigBaan.naam, stad: huidigBaan.stad }, ...banen] : banen);

    const opties = ['<option value="">— geen baan —</option>']
        .concat(lijst.map(b => {
            const label = b.naam + (b.stad ? ` (${b.stad})` : '');
            const sel   = (b.naam === huidigNaam) ? ' selected' : '';
            return `<option value="${escHtml(b.id)}"${sel}>${escHtml(label)}</option>`;
        }))
        .join('');

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal-dialog" role="dialog" aria-modal="true">
            <div class="modal-header">
                <span class="modal-icon">📍</span>
                <span>Baan toewijzen</span>
            </div>
            <div class="modal-body">
                <label class="baan-modal-veld">
                    <span>Kies een baan voor deze wedstrijd:</span>
                    <select id="baan-modal-sel" class="inp">${opties}</select>
                    <small>alle unieke banen, ongeacht organisatie</small>
                </label>
            </div>
            <div class="modal-knoppen">
                <button class="modal-btn modal-annuleer">Annuleren</button>
                <button class="modal-btn modal-doorgaan">Opslaan</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const sluit = () => overlay.remove();
    overlay.querySelector('.modal-annuleer').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    document.addEventListener('keydown', function onKey(e) {
        if (e.key === 'Escape') { sluit(); document.removeEventListener('keydown', onKey); }
    });

    overlay.querySelector('.modal-doorgaan').addEventListener('click', async () => {
        const baanId = overlay.querySelector('#baan-modal-sel').value;
        const fd = new FormData();
        fd.append('action', 'koppel_wedstrijd');
        fd.append('competition_id', huidigCompId);
        fd.append('baan_id', baanId);
        try {
            const res = await fetch('api/banen.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!res.ok) {
                toonBevestigDialog(data.error || 'Fout bij opslaan', 'Fout');
                return;
            }
            sluit();
            // Vergelijk-data herladen om de nieuwe baan-info (logo, vereniging) op te halen
            herlaadVergelijking();
        } catch (e) {
            toonBevestigDialog('Fout: ' + e.message, 'Fout');
        }
    });

    setTimeout(() => overlay.querySelector('#baan-modal-sel')?.focus(), 0);
}

// ── Bevestigingsdialoog ───────────────────────────────────────────────────────

// Wis de import-module volledig na verwijderen van de actieve wedstrijd
function resetImportModule(verwijderdId) {
    // Geen actie als het een andere wedstrijd is
    if (verwijderdId && huidigCompId !== verwijderdId) return;

    // Globals wissen
    huidigCompId      = null;
    huidigComp        = null;
    vergelijkData     = [];
    personEdits       = {};
    entryEdits        = {};
    manualTp          = new Set();
    window.printCenterResetVoorWedstrijd?.(null);
    _setHeaderWedstrijd(null);
    heeftWijzigingen  = false;
    standDatum        = '';
    dbStandDatum      = '';
    huidigOrganisatie = null;
    huidigBaan        = null;
    huidigImported    = false;
    startlijstCache   = {};

    // Actieve kaart deselecteren
    if (activeCard) { activeCard.classList.remove('active'); activeCard = null; }

    // Detail-panel verbergen en inhoud wissen
    const panel = el('detail-panel');
    if (panel) panel.style.display = 'none';
    const baanRij = el('detail-baan-rij');
    if (baanRij) baanRij.style.display = 'none';

    const tabs    = el('imp-cat-tabs');
    const content = el('imp-cat-content');
    const result  = el('import-result');
    if (tabs)    tabs.innerHTML    = '';
    if (content) content.innerHTML = '<div class="status-msg info">Selecteer een wedstrijd om te importeren.</div>';
    if (result)  result.innerHTML  = '';

    if (typeof updateImportBtn === 'function') updateImportBtn();
}

// ── Input-dialog (vervanging voor browser-prompt) ────────────────────────
// Standaard modal-styling, met een input-veld in de body. Returns een
// Promise die resolved met de ingetypte string, of null bij annuleren /
// Escape / klik buiten de modal.
//
// Voorbeeld:
//   const naam = await toonInputDialog({
//       titel:    'Categorie hernoemen',
//       bericht:  'Nieuwe naam:',
//       defaultValue: oudeNaam,
//       labelOk:  'Hernoemen',
//   });
//   if (naam === null) return;        // geannuleerd
//   if (naam.trim() === '') ...       // leeg ingevuld
//
// opts: {
//   titel, bericht, labelOk, labelAnnuleer,
//   inputType = 'text', placeholder, defaultValue,
//   min, max,                         // alleen relevant voor type=number
//   monospace = false,                // monospace font in input (bv. licenties)
// }
async function toonInputDialog(opts = {}) {
    let inputEl    = null;
    const inputId  = 'mdl-inp-' + Math.random().toString(36).slice(2, 9);
    const attrs = [
        `id="${inputId}"`,
        `type="${opts.inputType || 'text'}"`,
        `class="modal-input${opts.monospace ? ' modal-input-mono' : ''}"`,
        opts.placeholder ? `placeholder="${escHtml(opts.placeholder)}"` : '',
        opts.min !== undefined && opts.min !== null ? `min="${escHtml(String(opts.min))}"` : '',
        opts.max !== undefined && opts.max !== null ? `max="${escHtml(String(opts.max))}"` : '',
        `value="${escHtml(opts.defaultValue ?? '')}"`,
    ].filter(Boolean).join(' ');
    const bodyHtml = (opts.bericht ? `<p>${escHtml(opts.bericht)}</p>` : '')
                   + `<input ${attrs}>`;
    const ok = await toonBevestigDialog(
        bodyHtml,
        opts.titel || 'Invoer',
        opts.labelOk || 'OK',
        opts.labelAnnuleer ?? 'Annuleren',
        {
            bodyIsHtml: true,
            onOpened: (overlay) => {
                inputEl = overlay.querySelector('#' + inputId);
                if (inputEl) { inputEl.focus(); inputEl.select(); }
            },
        }
    );
    if (!ok) return null;
    return inputEl ? inputEl.value : null;
}

function toonBevestigDialog(bericht, titel = 'Onopgeslagen wijzigingen', labelOk = 'Doorgaan', labelAnnuleer = 'Annuleren', opts = {}) {
    return new Promise(resolve => {
        // Als labelAnnuleer leeg is, tonen we alleen de OK-knop (= pure melding,
        // geen keuze). Klikken buiten of Escape = gewoon sluiten.
        const toonAnnuleer = !!labelAnnuleer;
        // opts.bodyIsHtml=true → bericht wordt als raw HTML ingevoegd (voor lijsten,
        // formatting). Default false = string wordt geëscaped + newlines worden
        // omgezet naar <br>. Dat laatste zorgt dat alert-style multi-line teksten
        // (bv. "Toewijzing vrijgeven?\n\nTransponder X aan Y") netjes worden
        // weergegeven zonder dat elke caller bodyIsHtml hoeft te gebruiken.
        const bodyHtml = opts.bodyIsHtml
            ? bericht
            : escHtml(bericht).replace(/\n/g, '<br>');
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <span class="modal-icon">⚠</span>
                    <span>${escHtml(titel)}</span>
                </div>
                <div class="modal-body">${bodyHtml}</div>
                <div class="modal-knoppen">
                    ${toonAnnuleer ? `<button class="modal-btn modal-annuleer">${escHtml(labelAnnuleer)}</button>` : ''}
                    <button class="modal-btn modal-doorgaan">${escHtml(labelOk)}</button>
                </div>
            </div>`;

        document.body.appendChild(overlay);

        const sluit = (resultaat) => {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            resolve(resultaat);
        };

        const onKey = e => {
            if (e.key === 'Escape') sluit(false);
            if (e.key === 'Enter')  sluit(true);
        };

        if (toonAnnuleer) {
            overlay.querySelector('.modal-annuleer').addEventListener('click', () => sluit(false));
        }
        overlay.querySelector('.modal-doorgaan').addEventListener('click', () => sluit(true));
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(false); });
        document.addEventListener('keydown', onKey);
        overlay.querySelector(toonAnnuleer ? '.modal-annuleer' : '.modal-doorgaan').focus();

        // opts.onOpened(overlay) — hook voor dialogs met interactieve body
        // (bv. input-velden waarvan we de waarde willen meelezen). Wordt
        // aangeroepen na append zodat caller eigen listeners kan binden +
        // values kan opslaan in eigen scope. De overlay wordt na resolve
        // verwijderd; binnen onOpened blijft 'ie wel bereikbaar.
        if (typeof opts.onOpened === 'function') {
            try { opts.onOpened(overlay); } catch (e) { console.warn('[modal] onOpened-fout:', e); }
        }
    });
}

// ── Navigatie ─────────────────────────────────────────────────────────────────

function initNav() {
    el('sidebar-toggle').addEventListener('click', () => {
        const sidebar = el('sidebar');
        const btn     = el('sidebar-toggle');
        sidebar.classList.toggle('collapsed');
        btn.innerHTML = sidebar.classList.contains('collapsed') ? '&#10095;' : '&#10094;';
    });

    el('filter-van').addEventListener('change', () => { laadWedstrijden(); });
    el('filter-tot').addEventListener('change', renderWedstrijdLijst);
    el('filter-locatie').addEventListener('change', renderWedstrijdLijst);
    el('filter-organisatie').addEventListener('change', renderWedstrijdLijst);
    el('filter-reset').addEventListener('click', () => {
        el('filter-van').value          = '';
        el('filter-tot').value          = '';
        el('filter-locatie').value      = '';
        el('filter-organisatie').value  = '';
        laadWedstrijden();
    });

    document.addEventListener('click', e => {
        if (textraPopup && !textraPopup.contains(e.target)) sluitTextraPopup();
    });

    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', async () => {
            const page = item.dataset.page;
            if (heeftWijzigingen && page !== 'importeer') {
                if (!await toonBevestigDialog('Er zijn onopgeslagen wijzigingen.\nDoorgaan zonder op te slaan?')) return;
            }
            // Check onopgeslagen DC-beheer wijzigingen
            const beheerPanel = el('beheer-panel');
            if (beheerPanel?._isBeheerDirty?.() && page !== 'importeer') {
                if (!await toonBevestigDialog('Er zijn onopgeslagen combination-aanpassingen.\nDoorgaan zonder op te slaan?')) return;
            }
            // Check onopgeslagen transponder-wijzigingen
            if (window._isTpDirty?.() && page !== 'instellingen') {
                if (!await toonBevestigDialog('Er zijn onopgeslagen transponder-wijzigingen.\nDoorgaan zonder op te slaan?')) return;
                markTpClean();
            }
            // Check onopgeslagen wijzigingen in Live verwerking (tijden, sancties, rondes, punten)
            if (typeof _liveOngeslagen !== 'undefined' && _liveOngeslagen && page !== 'live') {
                if (!await toonBevestigDialog('Er zijn onopgeslagen wijzigingen in Live verwerking.\nDoorgaan zonder op te slaan?')) return;
                _liveOngeslagen = false;
            }
            if (typeof stopTsPolling === 'function') stopTsPolling();
            // Stats-polling stoppen zodra we weg-navigeren van Systeem.
            // De Bezoekers-tab start hem opnieuw als je daar weer terugkomt.
            if (page !== 'systeem' && typeof stopPublicStatsRefresh === 'function') {
                stopPublicStatsRefresh();
            }
            if (page === 'importeer') {
                document.querySelector('.nav-update-dot')?.remove();
                // Herlaad vergelijkdata (transponders kunnen gewijzigd zijn in beheer)
                if (huidigCompId && typeof herlaadVergelijking === 'function') herlaadVergelijking();
            }
            document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
            const target = document.getElementById('page-' + page);
            if (target) target.classList.add('active');
            if (page === 'startlijsten') toonStartlijstenPagina();
            if (page === 'tijdschema')   toonTijdschemaPagina();
            if (page === 'klassementen') toonUitslagPagina();
            if (page === 'live')         { vulPaginaHeader('live-comp-naam', 'live-comp-meta'); toonLivePagina(); }
            if (page === 'systeem')      toonSysteemPagina();
            if (page === 'info')         toonInfoPagina();
        });
    });

    window.addEventListener('beforeunload', e => {
        if (heeftWijzigingen) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

// ── Uitloggen ─────────────────────────────────────────────────────────────────

el('btn-uitloggen')?.addEventListener('click', async () => {
    await fetch('api/auth.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'logout' }),
    });
    window.location.href = 'login.php';
});

// ── Rol-gebaseerde toegang ─────────────────────────────────────────────────────

function pasRolToe() {
    const rol = currentUser.role;

    // Systeem-menu (Gebruikers + Bezoekers + Logboek + Rijders + Uploads):
    // alleen voor owner en admin. Alle 5 tabs vallen onder dezelfde rol-check.
    if (['owner','admin'].includes(rol)) {
        document.querySelector('.nav-item-systeem')?.style.removeProperty('display');
    }

    // Schrijf-lock: alle schrijf-elementen op readonly pagina's disablen
    // Dit wordt per pagina aangeroepen in toonXxxPagina() via checkSchrijfRechten()
}

// Disable alle schrijf-elementen in een container; tab-knoppen blijven klikbaar
function pasSchrijfLockToe(containerEl) {
    if (!containerEl) return;
    // Navigatie-tabs (org-tab-btn, imp-cat-tab, modal-ztab) uitzonderen
    containerEl.querySelectorAll(
        'button:not(.btn-del):not([data-altijd-actief]):not(.org-tab-btn):not(.tab-btn):not(.imp-cat-tab):not(.modal-ztab):not(.ts-blok-tab), input, select, textarea'
    ).forEach(e => {
        e.disabled = true;
        e.title = e.title || 'Geen schrijfrechten voor deze module';
    });
    containerEl.classList.add('readonly-modus');
}

// Toont een lees-alleen banner bovenaan een container (eenmalig)
function toonLeesAlleenBanner(containerEl) {
    if (!containerEl || containerEl.querySelector('.leesalleen-banner')) return;
    const div = document.createElement('div');
    div.className = 'leesalleen-banner status-msg info';
    div.textContent = '👁 Lees-alleen — uw rol heeft geen schrijfrechten voor deze module.';
    containerEl.prepend(div);
}

// ── Start ─────────────────────────────────────────────────────────────────────

pasRolToe();
initNav();
laadWedstrijden();
el('btn-ververs-wedstrijden')?.addEventListener('click', laadWedstrijden);
