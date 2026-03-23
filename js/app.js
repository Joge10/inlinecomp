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

const STATUS_LABELS = ['Niet bevestigd', 'Bevestigd', 'Afgemeld'];
const STATUS_CSS    = ['status-0',        'status-1',  'status-2'];

let startlijstCache  = {};    // {cacheKey: {rondenConfig, ronde1, cFinale, bFinale}}
let isGeimporteerd   = false; // ≥1 deelnemer heeft db_entry in DB
let heeftWijzigingen = false; // onopgeslagen bewerkingen in huidige sessie
let gewijzigdeRijen  = new Set(); // license_keys van gewijzigde (nog niet opgeslagen) rijen
let huidigOrganisatie = null; // organisatie-object van huidig geselecteerde wedstrijd

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

function el(id) { return document.getElementById(id); }

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

// ── Wedstrijdenlijst laden ────────────────────────────────────────────────────

async function laadWedstrijden() {
    const list = el('comp-list');
    try {
        const res  = await fetch(BASE + 'api/competitions.php');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        if (data.error) throw new Error(data.error);
        if (!data.length) {
            statusMsg(list, 'info', 'Geen aankomende inline wedstrijden gevonden.');
            return;
        }

        allWedstrijden = data;
        vulLocatieDropdown();
        renderWedstrijdLijst();

    } catch(e) {
        statusMsg(list, 'error', '⚠ Kon wedstrijden niet laden: ' + e.message);
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

function renderWedstrijdLijst() {
    const list = el('comp-list');
    const van  = el('filter-van').value;
    const tot  = el('filter-tot').value;
    const loc  = el('filter-locatie').value;

    let gefilterd = allWedstrijden;
    if (van) gefilterd = gefilterd.filter(c => (c.starts || '') >= van);
    if (tot) gefilterd = gefilterd.filter(c => (c.starts || '') <= tot + 'T23:59:59');
    if (loc) gefilterd = gefilterd.filter(c => getLocatie(c) === loc);

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
        if (activeCard && comp.id === huidigCompId) card.classList.add('active');

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

    el('btn-import').onclick        = () => importeerWedstrijd(comp.id, comp.name || '');
    el('btn-print-tekenlijst').onclick = () => printTekenlijsten();

    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

    try {
        const res = await fetch('api/vergelijk.php?id=' + encodeURIComponent(comp.id));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const vData = await res.json();
        if (vData.error) throw new Error(vData.error);
        vergelijkData    = vData.groepen     ?? vData; // backwards compat
        huidigOrganisatie = vData.organisatie ?? null;

        zetKnsbTimestamp();
        initEdits();
        bouwVergelijkTabbladen();
        updateImportBtn();

    } catch(e) {
        setHTML('imp-cat-content', `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`);
    }
}

// ── Bevestigingsdialoog ───────────────────────────────────────────────────────

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

    el('filter-van').addEventListener('change', renderWedstrijdLijst);
    el('filter-tot').addEventListener('change', renderWedstrijdLijst);
    el('filter-locatie').addEventListener('change', renderWedstrijdLijst);
    el('filter-reset').addEventListener('click', () => {
        el('filter-van').value     = '';
        el('filter-tot').value     = '';
        el('filter-locatie').value = '';
        renderWedstrijdLijst();
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
            document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
            const target = document.getElementById('page-' + page);
            if (target) target.classList.add('active');
            if (page === 'startlijsten') toonStartlijstenPagina();
        });
    });

    window.addEventListener('beforeunload', e => {
        if (heeftWijzigingen) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

// ── Start ─────────────────────────────────────────────────────────────────────

initNav();
laadWedstrijden();
