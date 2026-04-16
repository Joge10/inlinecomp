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

// ── Gedeelde org-logo header + sponsor-footer voor print ─────────────────────
// Gebruikt door: uitslag, tijdschema, tekenlijsten, deelnemerslijst
// Retourneert { orgLogoHtml, footerHtml } — volledig inline-styled,
// geen externe CSS nodig.
function bouwOrgHeaderFooter(esc) {
    const baseUrl = new URL('.', window.location.href).href;
    const org = huidigOrganisatie;

    // Organisatie-logo (rechtsboven in header)
    const orgLogoHtml = org?.logo_path
        ? `<span style="display:block;height:20mm;max-width:50mm;overflow:hidden;line-height:0;text-align:right;">` +
          `<img src="${esc(baseUrl + org.logo_path)}" alt="${esc(org.naam)}" ` +
          `style="height:20mm;width:auto;max-width:50mm;display:inline-block;object-fit:contain;vertical-align:top;"></span>`
        : (org?.naam ? `<span style="font-size:8pt;color:#555;font-style:italic;">${esc(org.naam)}</span>` : '');

    // Sponsor-footer (volledig inline-styled)
    let footerHtml = '';
    if (org?.sponsors?.length) {
        const sponsorItems = org.sponsors.map(s =>
            `<span style="display:inline-flex;align-items:center;">` +
            (s.logo_path
                ? `<span style="display:inline-block;height:10mm;max-width:35mm;overflow:hidden;line-height:0;">` +
                  `<img src="${esc(baseUrl + s.logo_path)}" alt="${esc(s.naam)}" ` +
                  `style="height:10mm;width:auto;max-width:35mm;display:block;object-fit:contain;"></span>`
                : `<span style="font-size:7pt;color:#555;">${esc(s.naam)}</span>`) +
            `</span>`
        ).join('');
        footerHtml = `<div style="margin-top:3mm;border-top:1px solid #ddd;padding-top:2mm;display:flex;align-items:center;justify-content:center;gap:5mm;flex-wrap:wrap;">${sponsorItems}</div>`;
    }

    return { orgLogoHtml, footerHtml };
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
        const res  = await fetch(BASE + 'api/competitions.php' + vanParam);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        if (data.error) throw new Error(data.error);
        if (!data.length) {
            statusMsg(list, 'info', 'Geen aankomende inline wedstrijden gevonden.');
            return;
        }

        allWedstrijden = data;
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
        card.innerHTML =
            `<div class="comp-naam">${escHtml(comp.name || comp.title || '')}</div>` +
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

    const panel = el('detail-panel');
    panel.style.display = 'block';
    el('detail-title').textContent = comp.name || comp.title || '';
    el('detail-meta').textContent  = formatDatum(comp.starts) + ' · ' + getLocatie(comp);
    el('import-result').innerHTML  = '';
    el('imp-cat-tabs').innerHTML   = '';
    startlijstCache = {};
    setHTML('imp-cat-content', '<div class="status-msg loading"><span class="spinner"></span>Vergelijken met database…</div>');

    el('btn-import').onclick           = () => importeerWedstrijd(comp.id, comp.name || '');
    el('btn-print-tekenlijst').onclick = () => printTekenlijsten();
    el('btn-print-deelnemers').onclick = () => printDeelnemerslijst();

    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

    const myAbort = vergelijkAbort;
    try {
        const res = await fetch('api/vergelijk.php?id=' + encodeURIComponent(comp.id),
                                { signal: myAbort.signal });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const vData = await res.json();
        if (vData.error) throw new Error(vData.error);
        vergelijkData     = vData.groepen     ?? vData; // backwards compat
        huidigOrganisatie = vData.organisatie ?? null;
        standDatum        = vData.knsb_stand  ?? '';
        dbStandDatum      = vData.db_stand    ?? '';
        entriesVersion    = vData.entries_version ?? 0;
        _heeftProgramma   = !!(vData.heeft_programma);
        _orgTransponders  = vData.org_transponders ?? [];

        zetKnsbTimestamp();
        initEdits();
        bouwVergelijkTabbladen();
        updateImportBtn();

    } catch(e) {
        if (e.name === 'AbortError') return; // nieuwere klik heeft deze aanvraag afgebroken
        setHTML('imp-cat-content', `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`);
    }
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
    heeftWijzigingen  = false;
    standDatum        = '';
    dbStandDatum      = '';
    huidigOrganisatie = null;
    startlijstCache   = {};

    // Actieve kaart deselecteren
    if (activeCard) { activeCard.classList.remove('active'); activeCard = null; }

    // Detail-panel verbergen en inhoud wissen
    const panel = el('detail-panel');
    if (panel) panel.style.display = 'none';

    const tabs    = el('imp-cat-tabs');
    const content = el('imp-cat-content');
    const result  = el('import-result');
    if (tabs)    tabs.innerHTML    = '';
    if (content) content.innerHTML = '<div class="status-msg info">Selecteer een wedstrijd om te importeren.</div>';
    if (result)  result.innerHTML  = '';

    if (typeof updateImportBtn === 'function') updateImportBtn();
}

function toonBevestigDialog(bericht, titel = 'Onopgeslagen wijzigingen') {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <span class="modal-icon">⚠</span>
                    <span>${escHtml(titel)}</span>
                </div>
                <div class="modal-body">${escHtml(bericht)}</div>
                <div class="modal-knoppen">
                    <button class="modal-btn modal-annuleer">Annuleren</button>
                    <button class="modal-btn modal-doorgaan">Doorgaan</button>
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

        overlay.querySelector('.modal-annuleer').addEventListener('click', () => sluit(false));
        overlay.querySelector('.modal-doorgaan').addEventListener('click', () => sluit(true));
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(false); });
        document.addEventListener('keydown', onKey);
        overlay.querySelector('.modal-annuleer').focus();
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
            if (typeof stopTsPolling === 'function') stopTsPolling();
            if (page === 'importeer') {
                document.querySelector('.nav-update-dot')?.remove();
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
            if (page === 'gebruikers')   toonGebruikersPagina();
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

    // Gebruikers nav-item: alleen owner en admin
    if (['owner','admin'].includes(rol)) {
        document.querySelector('.nav-item-gebruikers')?.style.removeProperty('display');
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
