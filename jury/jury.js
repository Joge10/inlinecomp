/* InlineComp – Jury-app client-side
 *
 * Drie schermen, opvolgend:
 *   1. Wedstrijd-lijst        → klik wedstrijd → login-modal
 *   2. Rolkeuze-scherm (4)    → klik rol → role-skeleton
 *   3. Role-skeleton (per rol) → placeholder met "Nog te implementeren"
 *
 * Sessie-state komt van server (?action=session) — geen client cache,
 * want jury kan tablet doorgeven aan ander persoon (andere wedstrijd).
 */

'use strict';

const elJ = id => document.getElementById(id);
const escHtml = s => String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// ── Rol-definities ──────────────────────────────────────────────────────────
const ROLLEN = [
    { id: 'area_of_call',  naam: 'Area of Call',  icoon: '📋',
      omschrijving: 'Aanwezigheid controleren per heat voordat rijders aan de start verschijnen.' },
    { id: 'aankomst',      naam: 'Aankomst-jury', icoon: '🏁',
      omschrijving: 'Finishvolgorde per heat invoeren.' },
    { id: 'scheidsrechter', naam: 'Scheidsrechter', icoon: '🟨',
      omschrijving: 'Sancties uitdelen per heat (W1, W2, FS, DQ, …).' },
    { id: 'starter',       naam: 'Starter',       icoon: '🔫',
      omschrijving: 'Valse starts en aanverwante meldingen vastleggen.' },
    { id: 'speaker',       naam: 'Speaker',       icoon: '🎤',
      omschrijving: 'Heats omroepen en commentaar geven via geluidsinstallatie.' },
];

// ── Compacte datum-helper ───────────────────────────────────────────────────
function formatDatum(iso) {
    if (!iso) return '';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

// ── Init ────────────────────────────────────────────────────────────────────
async function juryInit() {
    try {
        const res  = await fetch('?action=session', { credentials: 'same-origin' });
        const data = await res.json();
        if (data.ingelogd) {
            zetTopbarComp(data);
            if (data.role) toonRol(data.role);
            else            toonRolkeuze();
        } else {
            wisTopbarComp();
            toonWedstrijdLijst();
        }
    } catch (e) {
        toonFout('Kan sessie-status niet laden: ' + e.message);
    }
}

function zetTopbarComp(s) {
    const info = elJ('jury-comp-info');
    elJ('jury-comp-naam').textContent = s.comp_naam || '';
    elJ('jury-comp-meta').textContent = formatDatum(s.comp_starts);
    info.hidden = false;

    const acties = elJ('jury-topbar-acties');
    acties.innerHTML = `
        <button class="jury-btn jury-btn-link" id="jury-btn-docs"     title="Wedstrijddocumenten (PDF's)">📄 Docs</button>
        <button class="jury-btn jury-btn-link" id="jury-btn-wissel"   title="Andere wedstrijd of rol">↻ Wissel</button>
        <button class="jury-btn jury-btn-link" id="jury-btn-uitloggen">⎋ Uitloggen</button>
    `;
    elJ('jury-btn-uitloggen').addEventListener('click', juryUitloggen);
    elJ('jury-btn-wissel').addEventListener('click', toonRolkeuze);
    elJ('jury-btn-docs').addEventListener('click', toonDocumentenLijst);
}

function wisTopbarComp() {
    elJ('jury-comp-info').hidden = true;
    elJ('jury-topbar-acties').innerHTML = '';
}

// ── Documenten-lade (PDF's uit /wedstrijdData) ─────────────────────────────
// V1: flat lijst, klik = inline iframe-viewer. Tweede modal stacks bovenop.
// Auth wordt server-side gecheckt op session — knop staat alleen in topbar
// na login, dus normaal pad is altijd geauthenticeerd.
async function toonDocumentenLijst() {
    // Voorkom dubbel-openen
    if (document.querySelector('.jury-docs-overlay')) return;

    const overlay = document.createElement('div');
    overlay.className = 'jury-docs-overlay';
    overlay.innerHTML = `
        <div class="jury-docs-modal">
            <div class="jury-docs-kop">
                <span class="jury-docs-titel">📄 Wedstrijddocumenten</span>
                <button class="jury-docs-sluit" type="button" aria-label="Sluiten">&times;</button>
            </div>
            <div class="jury-docs-body" id="jury-docs-body">
                <div class="jury-docs-laden">Laden…</div>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const sluit = () => overlay.remove();
    overlay.querySelector('.jury-docs-sluit').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });

    try {
        const res  = await fetch('?action=list_pdfs', { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) {
            elJ('jury-docs-body').innerHTML =
                `<div class="jury-docs-leeg">Kon lijst niet ophalen: ${escHtml(data?.error ?? res.status)}</div>`;
            return;
        }
        const body = elJ('jury-docs-body');
        if (!data.map_aanwezig) {
            body.innerHTML = `<div class="jury-docs-leeg">
                <b>Geen documenten-map gevonden</b><br>
                <span>De map <code>/wedstrijdData</code> bestaat nog niet op de server.</span>
            </div>`;
            return;
        }
        if (!data.pdfs?.length) {
            body.innerHTML = `<div class="jury-docs-leeg">
                <b>Geen documenten beschikbaar</b><br>
                <span>Upload PDF's naar <code>/wedstrijdData/</code> op je hosting.</span>
            </div>`;
            return;
        }
        // PDF's openen in NIEUWE TAB ipv inline iframe-viewer.
        // Reden: Chrome strips zoom-controls in iframe-PDFs (alleen scroll werkt).
        // In een eigen tab krijgt de speaker de volledige Chrome PDF-toolbar
        // met zoom in/uit, fit-to-width, paginanummer, zoeken en print.
        // Een <a target="_blank"> geeft daarnaast gratis: keyboard-nav, middle-
        // click, right-click "open in nieuwe tab" — alles wat een gewone link
        // kan. rel="noopener noreferrer" voorkomt window.opener-leakage.
        body.innerHTML = `<ul class="jury-docs-lijst">${
            data.pdfs.map(p => `
                <li class="jury-docs-item-wrap">
                    <a class="jury-docs-item" href="${escHtml(p.url)}"
                       target="_blank" rel="noopener noreferrer"
                       title="Open ${escHtml(p.naam)} in nieuwe tab">
                        <span class="jury-docs-icon">📄</span>
                        <span class="jury-docs-naam">${escHtml(p.naam.replace(/\.pdf$/i, ''))}</span>
                        <span class="jury-docs-meta">${p.size_kb} kB · ${escHtml(p.gewijzigd)}</span>
                        <span class="jury-docs-tab-hint" aria-hidden="true">↗</span>
                    </a>
                </li>`).join('')
        }</ul>`;
    } catch (e) {
        elJ('jury-docs-body').innerHTML =
            `<div class="jury-docs-leeg">Fout: ${escHtml(e.message)}</div>`;
    }
}

function toonFout(boodschap) {
    elJ('jury-main').innerHTML = `<div class="jury-fout">⚠ ${escHtml(boodschap)}</div>`;
}

// ── Scherm 1a: organisatie-keuze (alleen bij >1 org) ────────────────────────
let _juryAlleComps = [];  // cache van laatste lijst, voor "terug naar org-keuze"

async function toonWedstrijdLijst() {
    elJ('jury-main').innerHTML = `<div class="jury-laden">Wedstrijden laden…</div>`;
    let comps = [];
    try {
        const res = await fetch('?action=competitions', { credentials: 'same-origin' });
        comps = await res.json();
        if (comps?.error) throw new Error(comps.error);
    } catch (e) {
        toonFout('Wedstrijden laden mislukt: ' + e.message);
        return;
    }
    if (!Array.isArray(comps) || !comps.length) {
        elJ('jury-main').innerHTML = `
            <div class="jury-lege-staat">
                <div class="jury-lege-icoon">🔍</div>
                <h2>Geen wedstrijden beschikbaar</h2>
                <p>Er is nog geen wedstrijd waarvoor de organisator een
                jury-wachtwoord heeft ingesteld.<br>
                Vraag de organisator om er één in te stellen via
                <em>Beheer → Wedstrijden → 🔑</em>.</p>
            </div>`;
        return;
    }
    _juryAlleComps = comps;

    // Tel unieke organisaties. Eén org → direct naar wedstrijdlijst zonder
    // tussenstap. Meerdere orgs → tegels van organisaties als eerste keuze.
    const uniekeOrgs = new Map();   // org_id → { id, naam, logo, aantal }
    for (const c of comps) {
        const key = c.org_id || c.org_naam || '__zonder__';
        if (!uniekeOrgs.has(key)) {
            uniekeOrgs.set(key, {
                id:     c.org_id,
                naam:   c.org_naam || '(Geen organisatie)',
                logo:   c.org_logo || null,
                aantal: 0,
            });
        }
        uniekeOrgs.get(key).aantal++;
    }

    if (uniekeOrgs.size > 1) {
        toonOrganisatieLijst([...uniekeOrgs.values()]);
    } else {
        // 1 org: tussenstap overslaan
        toonWedstrijdenVoorOrg(null, comps);
    }
}

function toonOrganisatieLijst(orgs) {
    // Sorteer alfabetisch op naam — voorspelbaar bij elke render
    orgs.sort((a, b) => a.naam.localeCompare(b.naam, 'nl', { sensitivity: 'base' }));
    const kaarten = orgs.map(o => `
        <button type="button" class="jury-org-kaart" data-org-id="${escHtml(o.id ?? '')}"
                data-org-naam="${escHtml(o.naam)}">
            ${o.logo
                ? `<div class="jury-org-logo"><img src="../${escHtml(o.logo)}" alt=""></div>`
                : `<div class="jury-org-logo jury-org-logo-leeg">🏢</div>`}
            <div class="jury-org-naam">${escHtml(o.naam)}</div>
            <div class="jury-org-aantal">${o.aantal} ${o.aantal === 1 ? 'wedstrijd' : 'wedstrijden'}</div>
        </button>
    `).join('');
    elJ('jury-main').innerHTML = `
        <div class="jury-scherm">
            <h2 class="jury-scherm-titel">Kies organisatie</h2>
            <p class="jury-scherm-hint">Welke organisator hoort bij jouw wedstrijd?</p>
            <div class="jury-org-grid">${kaarten}</div>
        </div>`;
    elJ('jury-main').querySelectorAll('.jury-org-kaart').forEach(btn => {
        btn.addEventListener('click', () => {
            const orgId   = btn.dataset.orgId || null;
            const orgNaam = btn.dataset.orgNaam;
            const subset = _juryAlleComps.filter(c =>
                (c.org_id || c.org_naam || '__zonder__') === (orgId || orgNaam || '__zonder__')
            );
            toonWedstrijdenVoorOrg(orgNaam, subset);
        });
    });
}

// ── Scherm 1b: wedstrijdlijst (eventueel gefilterd op gekozen org) ──────────
function toonWedstrijdenVoorOrg(orgNaamOfNull, comps) {
    const kaarten = comps.map(c => `
        <button type="button" class="jury-comp-kaart" data-comp-id="${escHtml(c.id)}">
            <div class="jury-comp-kaart-naam">${escHtml(c.name)}</div>
            <div class="jury-comp-kaart-meta">${escHtml(formatDatum(c.starts))}</div>
            ${c.baan_naam ? `<div class="jury-comp-kaart-baan">📍 ${escHtml(c.baan_naam)}${c.baan_vereniging ? ' — ' + escHtml(c.baan_vereniging) : ''}</div>` : ''}
        </button>
    `).join('');
    // Terug-knop alleen tonen bij meer dan 1 organisatie in cache (= we kwamen
    // via de organisatie-keuze-stap)
    const meerOrgs = new Set(_juryAlleComps.map(c => c.org_id || c.org_naam)).size > 1;
    const terugKnop = (orgNaamOfNull && meerOrgs)
        ? `<button class="jury-btn jury-btn-secondary jury-terug" id="jury-btn-terug-orgs">← Andere organisatie</button>`
        : '';
    const titelSuffix = orgNaamOfNull ? ` — ${escHtml(orgNaamOfNull)}` : '';
    elJ('jury-main').innerHTML = `
        <div class="jury-scherm">
            ${terugKnop}
            <h2 class="jury-scherm-titel">Kies wedstrijd${titelSuffix}</h2>
            <p class="jury-scherm-hint">Tik op een wedstrijd, voer het jury-wachtwoord in.</p>
            <div class="jury-comp-grid">${kaarten}</div>
        </div>`;
    elJ('jury-main').querySelectorAll('.jury-comp-kaart').forEach(btn => {
        btn.addEventListener('click', () => {
            const comp = comps.find(c => c.id === btn.dataset.compId);
            if (comp) opentLoginModal(comp);
        });
    });
    elJ('jury-btn-terug-orgs')?.addEventListener('click', () => toonWedstrijdLijst());
}

// ── Login modal ─────────────────────────────────────────────────────────────
function opentLoginModal(comp) {
    elJ('jury-login-comp').innerHTML = `
        <div class="jury-login-comp-naam">${escHtml(comp.name)}</div>
        <div class="jury-login-comp-meta">${escHtml(formatDatum(comp.starts))}</div>
    `;
    const fout = elJ('jury-login-fout');
    fout.hidden = true; fout.textContent = '';
    const pwd = elJ('jury-login-pwd');
    pwd.value = '';
    elJ('jury-login-modal').hidden = false;
    setTimeout(() => pwd.focus(), 50);

    const form = elJ('jury-login-form');
    form.onsubmit = async (e) => {
        e.preventDefault();
        const wachtwoord = pwd.value;
        if (!wachtwoord) return;
        const okBtn = elJ('jury-login-ok');
        okBtn.disabled = true;
        try {
            const res = await fetch('?action=login', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ competition_id: comp.id, password: wachtwoord }),
            });
            const data = await res.json();
            if (!res.ok || data?.error) {
                fout.textContent = data?.error || ('HTTP ' + res.status);
                fout.hidden = false;
                pwd.select();
                return;
            }
            sluitLoginModal();
            await juryInit(); // herlaad sessie-state → topbar + rolkeuze
        } catch (e2) {
            fout.textContent = 'Verbinding mislukt: ' + e2.message;
            fout.hidden = false;
        } finally {
            okBtn.disabled = false;
        }
    };
    elJ('jury-login-annuleer').onclick = sluitLoginModal;
}
function sluitLoginModal() { elJ('jury-login-modal').hidden = true; }

// ── Scherm 2: rolkeuze ──────────────────────────────────────────────────────
function toonRolkeuze() {
    const kaarten = ROLLEN.map(r => `
        <button type="button" class="jury-rol-kaart" data-role="${escHtml(r.id)}">
            <div class="jury-rol-icoon">${r.icoon}</div>
            <div class="jury-rol-naam">${escHtml(r.naam)}</div>
            <div class="jury-rol-omschr">${escHtml(r.omschrijving)}</div>
        </button>
    `).join('');
    elJ('jury-main').innerHTML = `
        <div class="jury-scherm">
            <h2 class="jury-scherm-titel">Kies je rol</h2>
            <p class="jury-scherm-hint">Welke jury-functie ga je vandaag invullen?</p>
            <div class="jury-rol-grid">${kaarten}</div>
        </div>`;
    elJ('jury-main').querySelectorAll('.jury-rol-kaart').forEach(btn => {
        btn.addEventListener('click', async () => {
            const role = btn.dataset.role;
            try {
                const res = await fetch('?action=set_role', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ role }),
                });
                const data = await res.json();
                if (!res.ok || data?.error) {
                    toonFout(data?.error || ('HTTP ' + res.status));
                    return;
                }
                toonRol(role);
            } catch (e) {
                toonFout('Rol instellen mislukt: ' + e.message);
            }
        });
    });
}

// ── Scherm 3: role-router (Area of Call heeft een echte UI, rest placeholder) ──
function toonRol(roleId) {
    const r = ROLLEN.find(x => x.id === roleId);
    if (!r) { toonFout('Onbekende rol: ' + roleId); return; }
    if (roleId === 'area_of_call')  { toonAreaOfCall(r);     return; }
    if (roleId === 'speaker')       { toonSpeaker(r);        return; }
    if (roleId === 'scheidsrechter'){ toonScheidsrechter(r); return; }
    // Andere rollen nog skeleton
    elJ('jury-main').innerHTML = `
        <div class="jury-scherm jury-rol-detail">
            <div class="jury-rol-detail-kop">
                <div class="jury-rol-icoon jury-rol-icoon-groot">${r.icoon}</div>
                <div>
                    <h2 class="jury-scherm-titel">${escHtml(r.naam)}</h2>
                    <p class="jury-scherm-hint">${escHtml(r.omschrijving)}</p>
                </div>
            </div>
            <div class="jury-placeholder">
                <p>🚧 Deze functie wordt binnenkort ingebouwd.</p>
                <p class="jury-placeholder-hint">
                    Gebruik bovenin <strong>↻ Wissel</strong> om een andere rol te kiezen
                    of <strong>⎋ Uitloggen</strong> om te switchen naar een andere wedstrijd.
                </p>
            </div>
        </div>`;
}

// ════════════════════════════════════════════════════════════════════════════
//  SCHEIDSRECHTER — reserve-inzet + afmeld-beheer
// ════════════════════════════════════════════════════════════════════════════
// Twee hoofdtabs: "Inzetten reserves" (functioneel) en "Sancties" (proforma).
// Reserves-tab gebruikt de speaker-tab-structuur: cat-tabs (niveau 1) →
// DC-tabs (niveau 2) → teller + reserve-tegels + deelnemer-tegels.
// Deelnemer-tegels hebben hoek-knoppen (Afgemeld / Niet getekend / Terug).
// Alle acties schrijven direct naar de DB via scheids_* endpoints.
const _scheids = {
    struktuur: null,  // { cats: [{cat, dcs:[{dc_id, dc_naam, aantal_deelnemers, aantal_reserves}]}] }
    cat:       null,  // actieve cat
    dcId:      null,  // actieve DC
    tab:       'reserves',  // 'reserves' | 'sancties'
    data:      null,  // laatst geladen scheids_dc payload
};

// Status-labels (subset van app.js STATUS_LABELS, voor de jury-context)
const _SCHEIDS_STATUS = {
    0: { lbl: 'Niet bevestigd', css: 'neutraal' },
    1: { lbl: 'Getekend',       css: 'actief'   },
    2: { lbl: 'Afgemeld (KNSB)',css: 'knsb'     },
    3: { lbl: 'Afgem. bij org.',css: 'afgemeld' },
    4: { lbl: 'Niet getekend',  css: 'afgemeld' },
    5: { lbl: 'Getekend (org.)',css: 'actief'   },
};

function toonScheidsrechter(rolDef) {
    elJ('jury-main').innerHTML = `
        <div class="jury-scherm sr-scherm">
            <div class="sr-tabbalk">
                <button class="sr-tab is-actief" data-tab="reserves">↩ Inzetten reserves</button>
                <button class="sr-tab" data-tab="sancties">🟨 Sancties</button>
            </div>
            <div class="sr-body" id="sr-body"></div>
        </div>`;

    elJ('jury-main').querySelectorAll('.sr-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            _scheids.tab = btn.dataset.tab;
            elJ('jury-main').querySelectorAll('.sr-tab').forEach(b =>
                b.classList.toggle('is-actief', b === btn));
            _scheidsRenderTab();
        });
    });

    _scheidsRenderTab();
}

function _scheidsRenderTab() {
    const body = elJ('sr-body');
    if (!body) return;
    if (_scheids.tab === 'sancties') {
        body.innerHTML = `
            <div class="jury-placeholder sr-placeholder">
                <p>🟨 <strong>Sancties</strong></p>
                <p class="jury-placeholder-hint">
                    Deze functie (W1 · W2 · FS · DQ uitdelen per heat) wordt
                    binnenkort ingebouwd.
                </p>
            </div>`;
        return;
    }
    // Reserves-tab — cat-tabs + dc-tabs + grid (hergebruikt speaker-CSS)
    body.innerHTML = `
        <div class="sr-reserves spk-scherm">
            <div class="spk-tab-balk spk-tab-cats" id="sr-tab-cats"></div>
            <div class="spk-tab-balk spk-tab-dcs"  id="sr-tab-dcs"></div>
            <div class="sr-dc-detail" id="sr-dc-detail"></div>
        </div>`;
    _scheidsLaadStruktuur();
}

async function _scheidsLaadStruktuur() {
    try {
        const res  = await fetch('?action=scheids_struktuur', { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) {
            elJ('sr-dc-detail').innerHTML = `<div class="jury-fout">⚠ ${escHtml(data?.error ?? 'Kon afstanden niet laden')}</div>`;
            return;
        }
        _scheids.struktuur = data;
        if (!data.cats?.length) {
            elJ('sr-dc-detail').innerHTML = `<div class="sr-leeg">Geen afstanden met deelnemers of reserves gevonden.</div>`;
            return;
        }
        // Default: behoud huidige cat/dc indien nog geldig, anders eerste cat
        // + eerste DC mét reserves (handig: scheidsrechter wil meestal reserves).
        if (!_scheids.cat || !data.cats.some(c => c.cat === _scheids.cat)) {
            // Eerste cat die ergens reserves heeft, anders gewoon eerste cat
            const catMetRes = data.cats.find(c => c.dcs.some(d => d.aantal_reserves > 0));
            _scheids.cat  = (catMetRes || data.cats[0]).cat;
            _scheids.dcId = null;
        }
        const catObj = data.cats.find(c => c.cat === _scheids.cat);
        if (!_scheids.dcId || !catObj?.dcs.some(d => d.dc_id === _scheids.dcId)) {
            const dcMetRes = catObj?.dcs.find(d => d.aantal_reserves > 0);
            _scheids.dcId = (dcMetRes || catObj?.dcs[0])?.dc_id ?? null;
        }
        _scheidsRenderCatTabs();
        _scheidsRenderDcTabs();
        _scheidsLaadDc();
    } catch (e) {
        elJ('sr-dc-detail').innerHTML = `<div class="jury-fout">⚠ ${escHtml(e.message)}</div>`;
    }
}

function _scheidsRenderCatTabs() {
    const wrap = elJ('sr-tab-cats');
    if (!wrap || !_scheids.struktuur) return;
    wrap.innerHTML = _scheids.struktuur.cats.map(c => {
        const act = c.cat === _scheids.cat ? 'is-active' : '';
        // Reserve-badge op cat-niveau: som over alle DCs in die cat
        const resTot = c.dcs.reduce((s, d) => s + d.aantal_reserves, 0);
        const resBadge = resTot > 0 ? `<span class="sr-cat-resbadge">R${resTot}</span>` : '';
        return `<button class="spk-tab ${act}" data-cat="${escHtml(c.cat)}">${escHtml(c.cat)}${resBadge}</button>`;
    }).join('');
    wrap.querySelectorAll('.spk-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.dataset.cat === _scheids.cat) return;
            _scheids.cat = btn.dataset.cat;
            const catObj = _scheids.struktuur.cats.find(c => c.cat === _scheids.cat);
            const dcMetRes = catObj?.dcs.find(d => d.aantal_reserves > 0);
            _scheids.dcId = (dcMetRes || catObj?.dcs[0])?.dc_id ?? null;
            _scheidsRenderCatTabs();
            _scheidsRenderDcTabs();
            _scheidsLaadDc();
        });
    });
}

function _scheidsRenderDcTabs() {
    const wrap   = elJ('sr-tab-dcs');
    if (!wrap || !_scheids.struktuur) return;
    const catObj = _scheids.struktuur.cats.find(c => c.cat === _scheids.cat);
    if (!catObj || !catObj.dcs.length) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = catObj.dcs.map(d => {
        const act = d.dc_id === _scheids.dcId ? 'is-active' : '';
        const resBadge = d.aantal_reserves > 0
            ? `<span class="sr-dc-resbadge" title="${d.aantal_reserves} reserve(s)">R${d.aantal_reserves}</span>`
            : '';
        return `<button class="spk-tab spk-tab-dc ${act}" data-dc-id="${escHtml(d.dc_id)}">${escHtml(d.dc_naam)}${resBadge}</button>`;
    }).join('');
    wrap.querySelectorAll('.spk-tab-dc').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.dataset.dcId === _scheids.dcId) return;
            _scheids.dcId = btn.dataset.dcId;
            _scheidsRenderDcTabs();
            _scheidsLaadDc();
        });
    });
}

async function _scheidsLaadDc() {
    const det = elJ('sr-dc-detail');
    if (!det) return;
    if (!_scheids.dcId || !_scheids.cat) {
        det.innerHTML = `<div class="sr-leeg">Geen afstand geselecteerd.</div>`;
        return;
    }
    det.innerHTML = `<div class="jury-laden">Laden…</div>`;
    try {
        const url = '?action=scheids_dc'
                  + '&dc_id=' + encodeURIComponent(_scheids.dcId)
                  + '&cat='   + encodeURIComponent(_scheids.cat);
        const res  = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) {
            det.innerHTML = `<div class="jury-fout">⚠ ${escHtml(data?.error ?? 'Kon data niet laden')}</div>`;
            return;
        }
        _scheids.data = data;
        _scheidsRenderDc();
    } catch (e) {
        det.innerHTML = `<div class="jury-fout">⚠ ${escHtml(e.message)}</div>`;
    }
}

function _scheidsRenderDc() {
    const det = elJ('sr-dc-detail');
    if (!det || !_scheids.data) return;
    const { teller, reserves, deelnemers } = _scheids.data;
    const vrij = teller.vrij;
    // Loting al gedaan? Dan komt een reserve er niet meer via gewone inzet bij,
    // maar neemt 'ie de startplek van een afgemelde over (→ "Reserve invallen").
    const lotingDone = !!_scheids.data.heats_bestaan;
    // Een reserve is alleen inzetbaar als 'ie GETEKEND is (status 1). Reserves
    // die niet bevestigd (0) of afgemeld (2/3/4) zijn in de KNSB-feed, mogen
    // niet ingezet worden.
    const reserveInzetbaar = r => r.entry_status === 1;
    const heeftVrijeReserve = reserves.some(reserveInzetbaar);

    // Teller-strip (+ loting-indicator)
    const tellerHtml = `
        <div class="sr-teller">
            <span class="sr-teller-item"><b>${teller.geloot}</b> geloot</span>
            <span class="sr-teller-sep">/</span>
            <span class="sr-teller-item"><b>${teller.max}</b> max</span>
            <span class="sr-teller-vrij ${vrij > 0 ? 'is-vrij' : 'is-vol'}">
                ${vrij > 0 ? `${vrij} vrij` : 'vol'}
            </span>
            <span class="sr-teller-loting" title="${lotingDone
                ? 'Startlijsten zijn gemaakt — reserve valt in op de plek van een afgemelde'
                : 'Nog geen startlijsten — ingezette reserve krijgt plek bij de loting'}">
                ${lotingDone ? '🎯 loting gedaan' : '📝 nog geen loting'}
            </span>
        </div>`;

    // Reserve-tegel met klikbare knop in BEIDE modi:
    //  - Vóór loting: "Inzet ➜" (zet in de loting, krijgt plek bij seeding)
    //  - Na loting:   "Invallen ➜" (kies wie deze reserve vervangt; reserve
    //                 neemt diens startplek over). Klik opent de omgekeerde
    //                 picker (afgemelde rijders in een niet-gereden heat).
    // Afgemelde reserve (status 2/3/4) → niet inzetbaar, toon reden.
    const reserveTegel = r => {
        const inzetbaar = reserveInzetbaar(r);
        let knop;
        if (!inzetbaar) {
            // Niet-getekend / afgemeld → toon de werkelijke status, niet inzetbaar.
            const lbl = (_SCHEIDS_STATUS[r.entry_status] || {}).lbl || 'niet inzetbaar';
            knop = `<span class="sr-tegel-reservehint"
                          title="Alleen getekende reserves kunnen worden ingezet">${escHtml(lbl)}</span>`;
        } else if (!lotingDone) {
            const kanInzet = vrij > 0;
            const reden = vrij <= 0 ? 'Geen vrije plek (niemand afgemeld)' : 'Zet deze reserve in de loting';
            knop = `<button class="sr-tegel-btn sr-btn-inzet" data-lic="${escHtml(r.license_key)}"
                        ${kanInzet ? '' : 'disabled'} title="${escHtml(reden)}">Inzet ➜</button>`;
        } else {
            knop = `<button class="sr-tegel-btn sr-btn-resinval"
                        data-lic="${escHtml(r.license_key)}" data-naam="${escHtml(r.naam)}"
                        title="Kies welke afgemelde rijder deze reserve vervangt">Invallen ➜</button>`;
        }
        return `<div class="sr-tegel sr-tegel-reserve" data-lic="${escHtml(r.license_key)}">
            <span class="sr-tegel-resnr" title="Reserve-volgnummer">R${r.reserve_nr}</span>
            <span class="sr-tegel-snr">${r.startnummer ?? '—'}</span>
            <span class="sr-tegel-naam">${escHtml(r.naam)}</span>
            ${r.club ? `<span class="sr-tegel-club">${escHtml(r.club)}</span>` : ''}
            <div class="sr-tegel-acties">${knop}</div>
        </div>`;
    };

    // Deelnemer-tegel: startnr + naam + status-badge, met hoek-knoppen onder.
    // - actief: Afgemeld / Niet getekend
    // - afgemeld + in (niet-gereden) heat + loting gedaan + vrije reserve →
    //   ↪ Reserve invallen (neemt exact deze startplek over)
    // - afgemeld zonder heat → ↺ Terug
    // - heat al gereden → geen actie (kan niet meer)
    const deelTegel = d => {
        const st = _SCHEIDS_STATUS[d.entry_status] || _SCHEIDS_STATUS[0];
        const isActief   = d.entry_status === 1 || d.entry_status === 5;
        const isAfgemeld = d.entry_status === 3 || d.entry_status === 4;
        const isKnsb     = d.entry_status === 2;
        const heatInfo = d.in_heat
            ? `<span class="sr-tegel-heat ${d.heat_locked ? 'is-gereden' : ''}"
                     title="${d.heat_locked ? 'Heat al gereden' : 'Startplek in heat'}">🎯 ${escHtml(d.heat_label)}${d.heat_locked ? ' ✓' : ''}</span>`
            : '';
        let acties = '';
        if (isKnsb) {
            acties = `<div class="sr-tegel-acties"><span class="sr-tegel-knsb">KNSB-afmelding</span></div>`;
        } else if (d.heat_locked) {
            // Heat al gereden → niets meer te wijzigen
            acties = `<div class="sr-tegel-acties"><span class="sr-tegel-knsb">Heat gereden</span></div>`;
        } else if (isActief) {
            acties = `<div class="sr-tegel-acties">
                <button class="sr-tegel-btn sr-btn-afm"  data-lic="${escHtml(d.license_key)}" data-status="3"
                        title="Afgemeld bij organisatie — doet niet mee">Afgemeld</button>
                <button class="sr-tegel-btn sr-btn-niet" data-lic="${escHtml(d.license_key)}" data-status="4"
                        title="Niet getekend — doet niet mee">Niet getek.</button>
            </div>`;
        } else if (isAfgemeld) {
            // Afgemeld: terug-knop + (als in heat + loting + vrije reserve) invallen-knop
            const invalKnop = (d.in_heat && lotingDone && heeftVrijeReserve)
                ? `<button class="sr-tegel-btn sr-btn-inval" data-lic="${escHtml(d.license_key)}"
                        data-naam="${escHtml(d.naam)}"
                        title="Reserve neemt de startplek van deze rijder over">↪ Reserve invallen</button>`
                : '';
            acties = `<div class="sr-tegel-acties">
                <button class="sr-tegel-btn sr-btn-terug" data-lic="${escHtml(d.license_key)}" data-status="1"
                        title="Terug in de loting">↺ Terug</button>
                ${invalKnop}
            </div>`;
        }
        return `<div class="sr-tegel sr-tegel-deel sr-status-${st.css}" data-lic="${escHtml(d.license_key)}">
            <span class="sr-tegel-badge sr-status-${st.css}">${escHtml(st.lbl)}</span>
            <span class="sr-tegel-snr">${d.startnummer ?? '—'}</span>
            <span class="sr-tegel-naam">${escHtml(d.naam)}</span>
            ${d.club ? `<span class="sr-tegel-club">${escHtml(d.club)}</span>` : ''}
            ${heatInfo}
            ${acties}
        </div>`;
    };

    const reservesHtml = reserves.length
        ? reserves.map(reserveTegel).join('')
        : `<div class="sr-leeg">Geen reserves voor deze afstand.</div>`;

    det.innerHTML = `
        ${tellerHtml}
        <div class="sr-sectie">
            <h3 class="sr-sectie-titel">Reserves <span class="sr-sectie-n">(${reserves.length})</span></h3>
            <div class="sr-tegel-grid">${reservesHtml}</div>
        </div>
        <div class="sr-sectie">
            <h3 class="sr-sectie-titel">Deelnemers <span class="sr-sectie-n">(${deelnemers.length})</span></h3>
            <div class="sr-tegel-grid">${deelnemers.map(deelTegel).join('')}</div>
        </div>`;

    det.querySelectorAll('.sr-btn-inzet').forEach(btn => {
        btn.addEventListener('click', () => _scheidsInzet(btn.dataset.lic));
    });
    det.querySelectorAll('.sr-btn-afm, .sr-btn-niet, .sr-btn-terug').forEach(btn => {
        btn.addEventListener('click', () =>
            _scheidsZetStatus(btn.dataset.lic, parseInt(btn.dataset.status, 10)));
    });
    // Vanaf afgemelde-tegel: kies een reserve.
    det.querySelectorAll('.sr-btn-inval').forEach(btn => {
        btn.addEventListener('click', () =>
            _scheidsVervangPicker(btn.dataset.lic, btn.dataset.naam));
    });
    // Vanaf reserve-tegel (loting gedaan): kies een afgemelde rijder.
    det.querySelectorAll('.sr-btn-resinval').forEach(btn => {
        btn.addEventListener('click', () =>
            _scheidsInvalReversePicker(btn.dataset.lic, btn.dataset.naam));
    });
}

// Modal: kies welke reserve invalt op de startplek van de afgemelde rijder.
// Alleen GETEKENDE reserves (status 1) zijn inzetbaar.
function _scheidsVervangPicker(uitLic, uitNaam) {
    const reserves = (_scheids.data?.reserves || []).filter(r => r.entry_status === 1);
    if (!reserves.length) return;

    const overlay = document.createElement('div');
    overlay.className = 'sr-inval-overlay';
    overlay.innerHTML = `
        <div class="sr-inval-modal">
            <div class="sr-inval-kop">
                <span>↪ Reserve invallen voor <b>${escHtml(uitNaam)}</b></span>
                <button class="sr-inval-sluit" aria-label="Sluiten">&times;</button>
            </div>
            <p class="sr-inval-hint">De gekozen reserve neemt de exacte startplek (heat + baan) van
               ${escHtml(uitNaam)} over. Er wordt niet opnieuw geloot.</p>
            <ul class="sr-inval-lijst">
                ${reserves.map(r => `
                    <li class="sr-inval-item" data-lic="${escHtml(r.license_key)}">
                        <span class="sr-inval-resnr">R${r.reserve_nr}</span>
                        <span class="sr-inval-snr">${r.startnummer ?? '—'}</span>
                        <span class="sr-inval-naam">${escHtml(r.naam)}${r.club ? ` <span class="sr-tegel-club">${escHtml(r.club)}</span>` : ''}</span>
                        <span class="sr-inval-pijl">➜</span>
                    </li>`).join('')}
            </ul>
        </div>`;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    overlay.querySelector('.sr-inval-sluit').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    overlay.querySelectorAll('.sr-inval-item').forEach(li => {
        li.addEventListener('click', async () => {
            sluit();
            await _scheidsVervangInHeat(uitLic, li.dataset.lic);
        });
    });
}

// Omgekeerde modal: vanaf een reserve → kies WELKE afgemelde rijder (in een
// niet-gereden heat) deze reserve vervangt. Geen afgemelde-in-heat → uitleg.
function _scheidsInvalReversePicker(inLic, inNaam) {
    const kandidaten = (_scheids.data?.deelnemers || []).filter(d =>
        (d.entry_status === 3 || d.entry_status === 4) && d.in_heat && !d.heat_locked);
    if (!kandidaten.length) {
        const msg = 'Er is nog geen afgemelde rijder met een startplek.\n\n'
                  + 'Markeer eerst de rijder die zich afmeldt als "Afgemeld" of '
                  + '"Niet getekend" bij Deelnemers. Daarna neemt deze reserve diens startplek over.';
        (typeof toonBevestigDialog === 'function'
            ? toonBevestigDialog(msg, 'Eerst afmelden', 'OK', null)
            : alert(msg));
        return;
    }

    const overlay = document.createElement('div');
    overlay.className = 'sr-inval-overlay';
    overlay.innerHTML = `
        <div class="sr-inval-modal">
            <div class="sr-inval-kop">
                <span>↪ <b>${escHtml(inNaam)}</b> valt in voor…</span>
                <button class="sr-inval-sluit" aria-label="Sluiten">&times;</button>
            </div>
            <p class="sr-inval-hint">Kies de afgemelde rijder wiens startplek (heat + baan)
               ${escHtml(inNaam)} overneemt. Er wordt niet opnieuw geloot.</p>
            <ul class="sr-inval-lijst">
                ${kandidaten.map(d => `
                    <li class="sr-inval-item" data-lic="${escHtml(d.license_key)}">
                        <span class="sr-inval-snr">${d.startnummer ?? '—'}</span>
                        <span class="sr-inval-naam">${escHtml(d.naam)}
                            <span class="sr-tegel-heat">🎯 ${escHtml(d.heat_label)}</span></span>
                        <span class="sr-inval-pijl">➜</span>
                    </li>`).join('')}
            </ul>
        </div>`;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    overlay.querySelector('.sr-inval-sluit').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    overlay.querySelectorAll('.sr-inval-item').forEach(li => {
        li.addEventListener('click', async () => {
            sluit();
            await _scheidsVervangInHeat(li.dataset.lic, inLic);
        });
    });
}

async function _scheidsVervangInHeat(uitLic, inLic) {
    try {
        const res = await fetch('?action=scheids_vervang_in_heat', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:    JSON.stringify({ dc_id: _scheids.dcId, uit_license: uitLic, in_license: inLic }),
        });
        const data = await res.json();
        if (!res.ok || data?.error) {
            await (typeof toonBevestigDialog === 'function'
                ? toonBevestigDialog(data?.error ?? 'Vervangen mislukt', 'Mislukt', 'OK', null)
                : alert(data?.error ?? 'Vervangen mislukt'));
            return;
        }
        await _scheidsLaadDc();
        _scheidsHerlaadTellingen();
    } catch (e) {
        alert('Fout: ' + e.message);
    }
}

async function _scheidsInzet(lic) {
    try {
        const res = await fetch('?action=scheids_inzet', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:    JSON.stringify({ dc_id: _scheids.dcId, person_license: lic }),
        });
        const data = await res.json();
        if (!res.ok || data?.error) {
            await (typeof toonBevestigDialog === 'function'
                ? toonBevestigDialog(data?.error ?? 'Inzetten mislukt', 'Inzetten mislukt', 'OK', null)
                : alert(data?.error ?? 'Inzetten mislukt'));
            return;
        }
        await _scheidsLaadDc();
        _scheidsHerlaadTellingen();
    } catch (e) {
        alert('Fout: ' + e.message);
    }
}

async function _scheidsZetStatus(lic, status) {
    try {
        const res = await fetch('?action=scheids_status', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:    JSON.stringify({ dc_id: _scheids.dcId, person_license: lic, status }),
        });
        const data = await res.json();
        if (!res.ok || data?.error) {
            await (typeof toonBevestigDialog === 'function'
                ? toonBevestigDialog(data?.error ?? 'Wijzigen mislukt', 'Mislukt', 'OK', null)
                : alert(data?.error ?? 'Wijzigen mislukt'));
            return;
        }
        await _scheidsLaadDc();
        _scheidsHerlaadTellingen();
    } catch (e) {
        alert('Fout: ' + e.message);
    }
}

// Herlaad de struktuur (cat/dc reserve-badges) na inzet/afmeld zonder de
// detail-weergave te resetten — alleen de badge-tellingen worden ververst.
async function _scheidsHerlaadTellingen() {
    try {
        const res  = await fetch('?action=scheids_struktuur', { credentials: 'same-origin' });
        const data = await res.json();
        if (res.ok && !data?.error && data.cats) {
            _scheids.struktuur = data;
            _scheidsRenderCatTabs();
            _scheidsRenderDcTabs();
        }
    } catch { /* stil */ }
}

// ── Area of Call ────────────────────────────────────────────────────────────
// State (module-local). Bij wissel van rol/wedstrijd wordt deze pagina sowieso
// opnieuw opgebouwd → state-reset gebeurt automatisch.
// _userKozeAantal blijft true zodra de gebruiker zelf een 1/2/3 keuze maakt,
// zodat we het slimme race_type-default niet overschrijven bij navigatie.
const _aoc = {
    heats:           [],   // alle heats (alle ronden) van actieve wedstrijd
    actieveIdx:      0,    // eerste van zichtbare batch
    aantalNaast:     2,    // 1, 2 of 3 — initieel default, wordt door init bepaald
    userKozeAantal:  false,
    laden:           false,
};

// Auto-poll interval (ms). 15s = goed voor tablet (responsive genoeg om lock
// uit live-module binnen acceptabele tijd te zien, niet zo frequent dat het
// rate-limits raakt op iFastNet).
const _AOC_POLL_MS = 15000;
let _aocPollHandle = null;

async function toonAreaOfCall(rolDef) {
    elJ('jury-main').innerHTML = `<div class="jury-laden">Heats laden…</div>`;
    try {
        const res  = await fetch('?action=aoc_heats', { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        _aoc.heats = Array.isArray(data.heats) ? data.heats : [];
    } catch (e) {
        toonFout('Heats laden mislukt: ' + e.message);
        return;
    }
    if (!_aoc.heats.length) {
        elJ('jury-main').innerHTML = `
            <div class="jury-scherm jury-rol-detail">
                <div class="jury-rol-detail-kop">
                    <div class="jury-rol-icoon jury-rol-icoon-groot">${rolDef.icoon}</div>
                    <div>
                        <h2 class="jury-scherm-titel">${escHtml(rolDef.naam)}</h2>
                        <p class="jury-scherm-hint">${escHtml(rolDef.omschrijving)}</p>
                    </div>
                </div>
                <div class="jury-placeholder">
                    <p>Nog geen heats voor deze wedstrijd.</p>
                    <p class="jury-placeholder-hint">
                        Vraag de organisator om eerst de loting te doen via <em>Startlijsten</em>.
                    </p>
                </div>
            </div>`;
        return;
    }
    // Start bij eerste niet-verzonden heat, anders rij 0
    const eerstOpen = _aoc.heats.findIndex(h => !h.aoc_sent_at && !h.locked);
    _aoc.actieveIdx = eerstOpen >= 0 ? eerstOpen : 0;

    // Slimme default voor "aantal naast elkaar": sprint=2, lange afstand=1.
    // Alleen als gebruiker nog geen eigen keuze heeft gemaakt deze sessie.
    if (!_aoc.userKozeAantal) {
        _aoc.aantalNaast = _aocDefaultAantal(_aoc.heats[_aoc.actieveIdx]);
    }
    _aocRender();
    _aocStartPolling();
}

// ── AoC polling ─────────────────────────────────────────────────────────────
// Silent fetch elke _AOC_POLL_MS — alleen re-render bij wijziging zodat geen
// flikkering bij elke tick. Stopt zichzelf zodra AoC-scherm verdwijnt (rol-
// wissel, uitloggen). Hartbeat-check: kijk of .aoc-scherm nog in DOM staat.
function _aocStartPolling() {
    if (_aocPollHandle) clearInterval(_aocPollHandle);
    _aocPollHandle = setInterval(_aocPollTick, _AOC_POLL_MS);
}
function _aocStopPolling() {
    if (_aocPollHandle) { clearInterval(_aocPollHandle); _aocPollHandle = null; }
}

async function _aocPollTick() {
    // Hartbeat: AoC-scherm nog actief?
    if (!document.querySelector('.aoc-scherm')) { _aocStopPolling(); return; }
    try {
        const res  = await fetch('?action=aoc_heats', { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) return;
        const nieuweHeats = Array.isArray(data.heats) ? data.heats : [];
        if (_aocHeatsGewijzigd(_aoc.heats, nieuweHeats)) {
            _aoc.heats = nieuweHeats;
            _aocRender();
        }
    } catch { /* silent — netwerk-glitches mogen polling niet stilleggen */ }
}

// Detect of er iets relevants is veranderd: nieuwe heat-set, lock-status,
// verzonden-timestamp, of aanwezigheid (= parallelle jury-leden).
function _aocHeatsGewijzigd(oud, nieuw) {
    if (oud.length !== nieuw.length) return true;
    for (let i = 0; i < nieuw.length; i++) {
        const o = oud[i], n = nieuw[i];
        if (!o || o.heat_id !== n.heat_id)                       return true;
        if (Boolean(o.locked) !== Boolean(n.locked))             return true;
        if ((o.aoc_sent_at ?? null) !== (n.aoc_sent_at ?? null)) return true;
        const oS = (o.rijders || []).map(r => r.aoc_status).join(',');
        const nS = (n.rijders || []).map(r => r.aoc_status).join(',');
        if (oS !== nS) return true;
    }
    return false;
}

// Bepaal default-aantal-heats-naast-elkaar op basis van race_type:
//   sprint                                   → 2 (korte heats, snel achter elkaar)
//   inline / puntenkoers / afvalkoers / null → 1 (langere heats, één tegelijk)
function _aocDefaultAantal(heat) {
    return (heat && heat.race_type === 'sprint') ? 2 : 1;
}

// Status per heat voor het overzicht-vierkantje:
//   onbegonnen → geen rijder heeft een status (alles 'onbekend')
//   bezig      → sommige rijders een status, niet allemaal
//   klaar      → álle rijders aanwezig/afwezig, nog niet verzonden
//   verzonden  → aoc_sent_at is gezet
//   locked     → aankomstjury is bezig met de heat (results bestaan)
function _aocStatusVoorHeat(h) {
    if (h.locked)        return 'locked';
    if (h.aoc_sent_at)   return 'verzonden';
    const rijders = h.rijders || [];
    if (rijders.length === 0) return 'onbegonnen';
    const nMetStatus = rijders.filter(r => r.aoc_status !== 'onbekend').length;
    if (nMetStatus === 0)              return 'onbegonnen';
    if (nMetStatus === rijders.length) return 'klaar';
    return 'bezig';
}

function _aocRender() {
    const heats     = _aoc.heats;
    const idx       = Math.max(0, Math.min(_aoc.actieveIdx, heats.length - 1));
    const batch     = heats.slice(idx, idx + _aoc.aantalNaast);
    // Label gebruikt heat_nr (= heat-volgnr binnen rit) zodat opvolgende heats
    // van dezelfde rit uniek zijn. r.volgorde is voor ALLE heats van één rit
    // identiek en is dus géén goed onderscheid.
    const huidigLab = heats[idx]
        ? `${escHtml(heats[idx].rit_naam)}`
        : '—';

    // Volgende blijft altijd beschikbaar zolang er nog een batch verderop is —
    // jury moet vooruit kunnen werken met aanwezigheids-checks, ook als top-
    // heat nog open is. Het AUTO-doorschuiven na "Baan op gestuurd" houdt
    // wél rekening met de top-status (zie _aocBaanOp).
    const atEnd = idx >= heats.length - _aoc.aantalNaast;

    // Toon-controls: aantal heats naast elkaar
    const aantalKnoppen = [1, 2, 3].map(n =>
        `<button class="aoc-aantal-btn${_aoc.aantalNaast === n ? ' active' : ''}"
                 data-aoc-aantal="${n}">${n}</button>`
    ).join('');

    // Overzicht-ribbons: per unieke categorie (dc+afstand+ronde) die in de
    // zichtbare batch voorkomt → één eigen ribbon met label + vierkantjes
    // van álle heats in die categorie. Bij Toon=3 met heats uit 2 of 3
    // categorieën dus 2 of 3 ribbons. Originele globale idx behouden voor
    // data-aoc-jump zodat navigatie correct werkt.
    const groepKey = (h) =>
        `${h.dc_naam ?? ''}|${h.afstand_naam ?? ''}|${h.ronde_type ?? ''}`;

    // Verzamel unieke groepen in de zichtbare batch — volgorde van verschijning.
    const batchGroepen = [];
    const gezien       = new Set();
    for (let i = idx; i < idx + _aoc.aantalNaast && i < heats.length; i++) {
        const g = groepKey(heats[i]);
        if (!gezien.has(g)) { gezien.add(g); batchGroepen.push(g); }
    }

    const ribbonsHtml = batchGroepen.map(g => {
        const sample = heats.find(h => groepKey(h) === g);
        const label  = `${escHtml(sample.dc_naam ?? '')} — ${escHtml(sample.afstand_naam ?? '')} — ${escHtml(sample.ronde_type ?? '')}`;
        const groepHeats = heats
            .map((h, i) => ({ h, i }))
            .filter(({ h }) => groepKey(h) === g);
        const tilesHtml = groepHeats.map(({ h, i }) => {
            const st       = _aocStatusVoorHeat(h);
            const isActive = i >= idx && i < idx + _aoc.aantalNaast;
            const labelN   = h.heat_nr ?? h.volgorde ?? (i + 1);
            return `<button class="aoc-tile aoc-tile-${st}${isActive ? ' active' : ''}"
                            data-aoc-jump="${i}"
                            title="${escHtml(h.rit_naam)} — ${st}">${labelN}</button>`;
        }).join('');
        return `<div class="aoc-overzicht-groep">
            <div class="aoc-overzicht-titel">${label}</div>
            <div class="aoc-overzicht">${tilesHtml}</div>
        </div>`;
    }).join('');

    const legendaHtml = `
        <div class="aoc-legenda">
            <span class="aoc-legend-item"><span class="aoc-legend-sw aoc-tile-onbegonnen"></span> open</span>
            <span class="aoc-legend-item"><span class="aoc-legend-sw aoc-tile-bezig"></span> bezig</span>
            <span class="aoc-legend-item"><span class="aoc-legend-sw aoc-tile-klaar"></span> klaar</span>
            <span class="aoc-legend-item"><span class="aoc-legend-sw aoc-tile-verzonden"></span> verzonden</span>
            <span class="aoc-legend-item"><span class="aoc-legend-sw aoc-tile-locked"></span> locked&nbsp;🏁</span>
        </div>`;

    elJ('jury-main').innerHTML = `
        <div class="jury-scherm aoc-scherm">
            <div class="aoc-sticky-head">
                <div class="aoc-topbar">
                    <button class="jury-btn jury-btn-secondary aoc-nav"
                            id="aoc-prev" ${idx === 0 ? 'disabled' : ''}>◀ Vorige</button>
                    <div class="aoc-huidig-label">${huidigLab}</div>
                    <button class="jury-btn jury-btn-secondary aoc-nav"
                            id="aoc-next" ${atEnd ? 'disabled' : ''}>Volgende ▶</button>
                    <div class="aoc-aantal">Toon: ${aantalKnoppen}</div>
                </div>
                <div class="aoc-overzicht-wrap">
                    ${ribbonsHtml}
                    ${legendaHtml}
                </div>
            </div>
            <div class="aoc-batch aoc-batch-${_aoc.aantalNaast}">
                ${batch.map(_aocRenderHeat).join('')}
            </div>
        </div>`;

    elJ('aoc-prev')?.addEventListener('click', () => _aocNavigeer(-1));
    elJ('aoc-next')?.addEventListener('click', () => _aocNavigeer(+1));
    document.querySelectorAll('[data-aoc-aantal]').forEach(b => {
        b.addEventListener('click', () => {
            _aoc.aantalNaast    = parseInt(b.dataset.aocAantal);
            _aoc.userKozeAantal = true;  // markeer dat gebruiker zelf koos
            _aocRender();
        });
    });
    document.querySelectorAll('[data-aoc-toggle]').forEach(b => {
        b.addEventListener('click', () => _aocToggleAanwezig(b));
    });
    document.querySelectorAll('[data-aoc-baan-op]').forEach(b => {
        b.addEventListener('click', () => _aocBaanOp(parseInt(b.dataset.aocBaanOp)));
    });
    document.querySelectorAll('[data-aoc-heropen]').forEach(b => {
        b.addEventListener('click', () => _aocHeropen(parseInt(b.dataset.aocHeropen)));
    });
    // Klik op een vierkantje in het overzicht → spring naar die heat. Clamp
    // op maxIdx zodat de batch altijd vol blijft.
    document.querySelectorAll('[data-aoc-jump]').forEach(b => {
        b.addEventListener('click', () => {
            const target = parseInt(b.dataset.aocJump);
            const maxIdx = Math.max(0, _aoc.heats.length - _aoc.aantalNaast);
            _aoc.actieveIdx = Math.max(0, Math.min(target, maxIdx));
            _aocRender();
        });
    });

    // Scroll de eerste actieve tile in beeld (binnen ribbon én viewport).
    // block:'nearest' + inline:'nearest' = browser scrollt alleen als nodig,
    // dus geen ongewenste pagina-jump als alles al zichtbaar is.
    requestAnimationFrame(() => {
        const actief = elJ('jury-main').querySelector('.aoc-tile.active');
        if (actief) actief.scrollIntoView({
            block: 'nearest', inline: 'nearest', behavior: 'smooth',
        });
    });
}

function _aocRenderHeat(h) {
    const verzonden = !!h.aoc_sent_at;
    const locked    = !!h.locked;
    const disabled  = verzonden || locked;
    const rijders = (h.rijders || []).map(r => {
        const isAan = r.aoc_status === 'aanwezig';
        const isAfw = r.aoc_status === 'afwezig';
        const dis   = disabled ? ' disabled' : '';
        return `<tr class="aoc-rij aoc-rij-${r.aoc_status}">
            <td class="aoc-td-sp">${r.startpositie}</td>
            <td class="aoc-td-sn">${r.startnummer ?? '—'}</td>
            <td class="aoc-td-naam">${escHtml(r.naam)}</td>
            <td class="aoc-td-acties">
                <button class="aoc-toggle aoc-aan${isAan ? ' active' : ''}"
                        data-aoc-toggle data-heat-entry-id="${r.heat_entry_id}"
                        data-nieuwe-status="aanwezig" ${dis}
                        title="Aanwezig">✓</button>
                <button class="aoc-toggle aoc-afw${isAfw ? ' active' : ''}"
                        data-aoc-toggle data-heat-entry-id="${r.heat_entry_id}"
                        data-nieuwe-status="afwezig" ${dis}
                        title="Afwezig">✗</button>
            </td>
        </tr>`;
    }).join('');

    const statusBadge = locked
        ? `<span class="aoc-status aoc-status-locked">🔒 Verwerkt in live-module</span>`
        : verzonden
            ? `<span class="aoc-status aoc-status-verzonden">✓ Verzonden ${_aocFmtTijd(h.aoc_sent_at)}</span>`
            : `<span class="aoc-status aoc-status-open">Open</span>`;

    // Totaal = werkelijk aantal ingedeelde rijders in deze heat, NIET de
    // capaciteit (= verwacht_aantal kon "max 2" zijn ook al staat er maar 1).
    const nTotaal   = h.rijders.length;
    const nAanwez   = h.rijders.filter(r => r.aoc_status === 'aanwezig').length;
    const nAfwez    = h.rijders.filter(r => r.aoc_status === 'afwezig').length;
    const nOnbk     = h.rijders.filter(r => r.aoc_status === 'onbekend').length;

    // "Baan op gestuurd" alleen klikbaar als élke rijder een status heeft
    // (aanwezig of afwezig). Status 'onbekend' = nog niet beoordeeld → geen
    // half-leeg "baan op" zonder bewuste keuze.
    const baanOpReady = nOnbk === 0 && h.rijders.length > 0;
    const baanOpTitle = baanOpReady
        ? 'Heat naar de baan sturen (afwezigen → DNS)'
        : `Eerst alle rijders een status geven (${nOnbk} nog open)`;

    const acties = locked
        ? ''
        : verzonden
            ? `<button class="jury-btn jury-btn-secondary aoc-heropen"
                       data-aoc-heropen="${h.heat_id}">↩ Heropenen</button>`
            : `<button class="jury-btn jury-btn-primary aoc-baanop"
                       data-aoc-baan-op="${h.heat_id}"
                       title="${baanOpTitle}"
                       ${baanOpReady ? '' : 'disabled'}>🏁 Baan op gestuurd</button>`;

    // Heat-label: bij meerdere series binnen één rit (sprint!) staat heat_nr
    // op 1,2,3,... — dat is hét onderscheid. r.volgorde is identiek voor al
    // die heats en mag dus niet meer als primair label. Programma-positie
    // wordt klein in de meta-rij getoond.
    const heatLabel = h.heat_nr ? `Heat ${h.heat_nr}` : `Rit ${h.volgorde}`;
    return `
        <div class="aoc-heat-kaart">
            <div class="aoc-heat-kop">
                <div class="aoc-heat-titel">
                    <div class="aoc-heat-nr">${heatLabel} · #${h.heat_id}</div>
                    <div class="aoc-heat-naam">${escHtml(h.rit_naam)}</div>
                    <div class="aoc-heat-meta">
                        Programma-positie ${h.volgorde}
                        ${h.afstand_naam ? ' · ' + escHtml(h.afstand_naam) : ''}
                        ${h.dc_naam ? ' · ' + escHtml(h.dc_naam) : ''}
                    </div>
                </div>
                ${statusBadge}
            </div>
            <table class="aoc-tabel">
                <thead><tr><th>Pos</th><th>#</th><th>Naam</th><th>AoC</th></tr></thead>
                <tbody>${rijders}</tbody>
            </table>
            <div class="aoc-voet">
                <span class="aoc-tellers">
                    Aanwezig <b class="aoc-getal-aan">${nAanwez}</b> /
                    Afwezig <b class="aoc-getal-afw">${nAfwez}</b> /
                    Totaal ${nTotaal}
                </span>
                ${acties}
            </div>
        </div>`;
}

function _aocFmtTijd(iso) {
    if (!iso) return '';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    return d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
}

function _aocNavigeer(richting) {
    // Stap is ALTIJD 1 heat, ongeacht aantalNaast. Bij Toon=2 betekent
    // klikken Volgende: bovenste schuift weg, de heat eronder schuift naar
    // boven, en daaronder verschijnt de volgende. Batch-grootte (aantalNaast)
    // blijft constant. Bij einde: bovengrens = length - aantalNaast zodat
    // de batch altijd vol is.
    const maxIdx = Math.max(0, _aoc.heats.length - _aoc.aantalNaast);
    let nieuw    = _aoc.actieveIdx + richting;
    nieuw        = Math.max(0, Math.min(nieuw, maxIdx));
    if (nieuw === _aoc.actieveIdx) return;
    _aoc.actieveIdx = nieuw;
    _aocRender();
}

async function _aocToggleAanwezig(btn) {
    const heId   = parseInt(btn.dataset.heatEntryId);
    const target = btn.dataset.nieuweStatus;   // 'aanwezig' of 'afwezig'
    // Toggle: als al actief in deze status → terug naar 'onbekend'
    const rij = _aocVindRijder(heId);
    if (!rij) return;
    const nieuw = (rij.aoc_status === target) ? 'onbekend' : target;
    btn.disabled = true;
    try {
        const res = await fetch('?action=aoc_aanwezig', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ heat_entry_id: heId, status: nieuw }),
        });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        rij.aoc_status = nieuw;
        _aocRender();
    } catch (e) {
        toonFout('Aanwezigheid bijwerken mislukt: ' + e.message);
    }
}

function _aocVindRijder(heId) {
    for (const h of _aoc.heats) {
        const r = (h.rijders || []).find(x => x.heat_entry_id === heId);
        if (r) return r;
    }
    return null;
}

async function _aocBaanOp(heatId) {
    const h = _aoc.heats.find(x => x.heat_id === heatId);
    if (!h) return;
    // Geen bevestig-dialog: actie is reversible via 'Heropenen'-knop. Voorkomt
    // klik-friction bij heats achter elkaar afwerken.
    try {
        const res = await fetch('?action=aoc_baan_op', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ heat_id: heatId }),
        });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        // Lokaal bijwerken: aoc_sent_at + DNS-flag op afwezigen
        h.aoc_sent_at = new Date().toISOString();
        for (const r of h.rijders || []) {
            if (r.aoc_status === 'afwezig' && !r.heeft_sanctie) {
                r.heeft_sanctie = true;
                r.sanctie = 'DNS';
            }
        }
        // Auto-doorschuiven naar volgende heat ALLEEN als de TOP-heat van de
        // zichtbare batch is afgehandeld (verzonden of locked). Anders blijft
        // de batch staan zodat een open top-heat niet ongezien wegvliegt.
        const topHeat = _aoc.heats[_aoc.actieveIdx];
        const topAfgehandeld = topHeat && (topHeat.aoc_sent_at || topHeat.locked);
        if (topAfgehandeld) {
            const maxIdx = Math.max(0, _aoc.heats.length - _aoc.aantalNaast);
            _aoc.actieveIdx = Math.min(_aoc.actieveIdx + 1, maxIdx);
        }
        _aocRender();
    } catch (e) {
        toonFout('Baan op gestuurd mislukt: ' + e.message);
    }
}

async function _aocHeropen(heatId) {
    try {
        const res = await fetch('?action=aoc_heropen', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ heat_id: heatId }),
        });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        const h = _aoc.heats.find(x => x.heat_id === heatId);
        if (h) h.aoc_sent_at = null;
        _aocRender();
    } catch (e) {
        toonFout('Heropenen mislukt: ' + e.message);
    }
}

// ── Uitloggen ───────────────────────────────────────────────────────────────
async function juryUitloggen() {
    try {
        await fetch('?action=logout', { method: 'POST', credentials: 'same-origin' });
    } catch { /* ook bij netwerkfout door naar lijst — server-sessie loopt af bij browser-sluit */ }
    wisTopbarComp();
    toonWedstrijdLijst();
}

// ── Modal sluit-handlers ────────────────────────────────────────────────────
elJ('jury-login-modal').addEventListener('click', e => {
    if (e.target === elJ('jury-login-modal')) sluitLoginModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !elJ('jury-login-modal').hidden) sluitLoginModal();
});

// ════════════════════════════════════════════════════════════════════════════
//   SPEAKER-ROL
// ════════════════════════════════════════════════════════════════════════════
// Speaker werkflow:
//   1. Tab-balk niveau 1 = categorieën (DSA/HSA/DKA/etc.) — afgeleid uit
//      de entries van deze wedstrijd. Sorted, click = wisselen.
//   2. Tab-balk niveau 2 = DCs binnen gekozen cat. Click = laad deelnemers.
//   3. Tegelgrid: per deelnemer een even-grote tegel met startnummer +
//      naam. Klik op tegel → modal met alle persoonsgegevens (sponsor,
//      woonplaats, club, geboortejaar, etc.) voor speaker-commentaar.

const _spk = {
    struktuur:  null,  // { dcs: [{dc_id, dc_naam, cats:[...], aantal, afstanden:[{id,naam,value_meters,race_type}]}] }
    dcId:       null,  // huidige dc_id-string (niveau 1)
    afstand:    null,  // huidige afstand-object {id,naam,value_meters,race_type} (niveau 2)
    cat:        null,  // representatieve categorie van de DC (voor record/kans/gender-onderkant)
    deelnemers: [],    // lijst voor huidige DC (alle cats + evt. gecombineerde partner-DC's)
    combiKey:   null,  // combi_group van de A-finale (visueel samengevoegde ritten) of null
    laden:      false,
    laadSeq:    0,     // race-guard tegen snel tab-wisselen tijdens async laden
};
// Zet dcId + eerste afstand + representatieve cat voor een DC uit de struktuur.
function _spkSelectDc(dcId) {
    const dc = _spk.struktuur?.dcs?.find(d => d.dc_id === dcId) || null;
    _spk.dcId    = dc?.dc_id ?? null;
    _spk.afstand = dc?.afstanden?.[0] ?? null;
    _spk.cat     = dc?.cats?.[0] ?? null;
}

async function toonSpeaker(rolDef) {
    elJ('jury-main').innerHTML = `<div class="jury-laden">Deelnemers-structuur laden…</div>`;
    try {
        const res  = await fetch('?action=speaker_struktuur', { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        _spk.struktuur = data;
    } catch (e) {
        toonFout('Kan deelnemers-structuur niet laden: ' + e.message);
        return;
    }
    if (!_spk.struktuur.dcs?.length) {
        elJ('jury-main').innerHTML = `
            <div class="jury-scherm jury-rol-detail">
                <div class="jury-rol-detail-kop">
                    <div class="jury-rol-icoon jury-rol-icoon-groot">${rolDef.icoon}</div>
                    <div>
                        <h2 class="jury-scherm-titel">${escHtml(rolDef.naam)}</h2>
                        <p class="jury-scherm-hint">${escHtml(rolDef.omschrijving)}</p>
                    </div>
                </div>
                <div class="jury-placeholder">
                    <p>Nog geen deelnemers voor deze wedstrijd.</p>
                    <p class="jury-placeholder-hint">
                        Vraag de organisator om de wedstrijd te importeren via <em>Importeer</em>.
                    </p>
                </div>
            </div>`;
        return;
    }
    // Eerste DC (+ eerste afstand + representatieve cat) als default
    _spkSelectDc(_spk.struktuur.dcs[0].dc_id);
    _spkRender(rolDef);
}

function _spkRender(rolDef) {
    const main = elJ('jury-main');
    main.innerHTML = `
        <div class="jury-scherm spk-scherm">
            <div class="spk-tab-balk spk-tab-cats" id="spk-tab-cats"></div>
            <div class="spk-tab-balk spk-tab-dcs"  id="spk-tab-dcs"></div>
            <div class="spk-grid" id="spk-grid"></div>
        </div>
        <div class="spk-bottombar" id="spk-bottombar">
            <!-- Nationaal record voor de actueel geselecteerde DC.
                 Verschijnt boven de "Eerdere uitslag"-cascade als context
                 voor de speaker. Klikbaar → toont alle 4 varianten (jun/sen
                 × M/V) voor diezelfde afstand. Default tonen we matching
                 cat-groep + gender. Backend = speaker_record endpoint. -->
            <div class="spk-bb-nr" id="spk-bb-nr"></div>
            <div class="spk-bb-dropdown-rij">
                <span class="spk-bb-lbl">📚 Eerdere uitslag</span>
                <select class="spk-bb-select spk-bb-sel-wedstrijd" id="spk-bb-sel-wedstrijd" disabled>
                    <option value="">— Laden…</option>
                </select>
                <select class="spk-bb-select spk-bb-sel-cat" id="spk-bb-sel-cat" disabled>
                    <option value="">— Cat —</option>
                </select>
                <select class="spk-bb-select spk-bb-sel-afstand" id="spk-bb-sel-afstand" disabled>
                    <option value="">— Afstand —</option>
                </select>
            </div>
            <div class="spk-bb-top3" id="spk-bb-top3">
                <span class="spk-bb-hint">Kies wedstrijd · cat · afstand om de top-3 te zien.</span>
            </div>
        </div>`;
    _spkRenderCatTabs();
    _spkRenderDcTabs();
    _spkLaadEnRenderDeelnemers();
    _spkLaadEerdereOverzicht();

    // Cascade-handlers — order is nu Wedstrijd → Cat → Afstand
    elJ('spk-bb-sel-wedstrijd').addEventListener('change', _spkOnWedstrijdChange);
    elJ('spk-bb-sel-cat').addEventListener('change',      _spkOnCatChange);
    elJ('spk-bb-sel-afstand').addEventListener('change',  _spkOnAfstandChange);
}

// Niveau 1 = DC-tabs (gecombineerde categorieën samen in één tab; label = DC-naam).
function _spkRenderCatTabs() {
    const wrap = elJ('spk-tab-cats');
    wrap.innerHTML = _spk.struktuur.dcs.map(d => {
        const act = d.dc_id === _spk.dcId ? 'is-active' : '';
        const lbl = (d.cats && d.cats.length) ? d.cats.join(' + ') : (d.dc_naam || '?');
        return `<button class="spk-tab ${act}" data-dc-id="${escHtml(d.dc_id)}" title="${escHtml(d.dc_naam || '')}">${escHtml(lbl)}</button>`;
    }).join('');
    wrap.querySelectorAll('.spk-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.dataset.dcId === _spk.dcId) return;
            _spkSelectDc(btn.dataset.dcId);   // dcId + eerste afstand + representatieve cat
            _spkRenderCatTabs();
            _spkRenderDcTabs();
            _spkLaadEnRenderDeelnemers();
            // Bottom-bar overzicht is cat-onafhankelijk (3 cascade-dropdowns
            // zonder pre-filter) — geen herlaad nodig bij DC-wissel.
        });
    });
}

// Niveau 2 = afstand-tabs binnen de gekozen DC.
function _spkRenderDcTabs() {
    const wrap = elJ('spk-tab-dcs');
    const dc   = _spk.struktuur.dcs.find(d => d.dc_id === _spk.dcId);
    const afs  = dc?.afstanden ?? [];
    if (!afs.length) {
        wrap.innerHTML = '<span class="spk-tab-geen-afst">Geen afstanden</span>';
        return;
    }
    wrap.innerHTML = afs.map(a => {
        const act = (a.id === _spk.afstand?.id) ? 'is-active' : '';
        const lbl = a.naam + (a.value_meters ? ` ${a.value_meters}m` : '');
        return `<button class="spk-tab spk-tab-dc ${act}" data-afst-id="${escHtml(a.id)}">${escHtml(lbl)}</button>`;
    }).join('');
    wrap.querySelectorAll('.spk-tab-dc').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.dataset.afstId === _spk.afstand?.id) return;
            _spk.afstand = afs.find(a => a.id === btn.dataset.afstId) ?? null;
            _spkRenderDcTabs();
            _spkLaadEnRenderDeelnemers();
        });
    });
}

// Label van een DC uit de struktuur (= de cats, net als de DC-tabs). Valt
// terug op de dc_naam uit de feed als de DC niet in de struktuur zit.
function _spkDcLabel(dcId, fallback) {
    const dc = _spk.struktuur?.dcs?.find(d => d.dc_id === dcId);
    if (dc?.cats?.length) return dc.cats.join(' + ');
    return fallback || dcId || '';
}

// Bouw grid-HTML met een tussenkop per gecombineerde partner-DC (nieuwe rij),
// net als bij de info-tegels. Groepsvolgorde uit _spk.deelnemers (eigen DC
// eerst, dan partners); binnen elke groep de meegegeven (bv. op startnummer
// gesorteerde) volgorde. Gebruikt door PK- en AV-scratchpad.
function _spkGroepeerGrid(deelnemersGesorteerd, tegelFn) {
    const volgorde = [];
    _spk.deelnemers.forEach(d => {
        const k = d._grp || '';
        if (!volgorde.includes(k)) volgorde.push(k);
    });
    let html = '';
    volgorde.forEach(k => {
        const leden = deelnemersGesorteerd.filter(d => (d._grp || '') === k);
        if (!leden.length) return;
        if (k) html += `<div class="spk-combi-kop">🔗 samen met ${escHtml(k)}</div>`;
        html += leden.map(tegelFn).join('');
    });
    return html;
}

async function _spkLaadEnRenderDeelnemers() {
    // Trigger NR-banner update naast de deelnemers-load — beide reageren op
    // dezelfde DC/cat-wijziging dus is dit het natuurlijke moment.
    _spkLaadNationaalRecord();
    const grid = elJ('spk-grid');
    if (!_spk.dcId) {
        grid.innerHTML = '<div class="jury-placeholder">Geen DC geselecteerd.</div>';
        return;
    }
    grid.innerHTML = '<div class="jury-laden">Deelnemers laden…</div>';
    _spk.laden = true;
    _spk.combiKey = null;
    // Race-guard: bij snel wisselen van DC/afstand mogen oude fetches het
    // resultaat van de nieuwe niet overschrijven (twee awaits hieronder).
    const mySeq = ++_spk.laadSeq;
    let hoofd = [];
    try {
        // DC-breed: alle categorieën van de DC samen (geen cat-filter).
        const url = '?action=speaker_deelnemers'
                  + '&dc_id=' + encodeURIComponent(_spk.dcId);
        const res  = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        hoofd = Array.isArray(data.deelnemers) ? data.deelnemers : [];
    } catch (e) {
        if (mySeq !== _spk.laadSeq) return;
        grid.innerHTML = `<div class="jury-fout">⚠ ${escHtml(e.message)}</div>`;
        _spk.laden = false;
        return;
    }
    if (mySeq !== _spk.laadSeq) return;   // inmiddels andere tab gekozen

    // Gecombineerde ritten (visueel samengevoegde A-finales) ophalen: partner-
    // DC's + hun rijders komen als extra rijen onder de eigen tegels, zodat het
    // hele veld samen staat en het PK/AV-scratchpad over de combi heen werkt.
    let combiGroepen = [];
    let combiKey = null;
    try {
        const cu = '?action=speaker_combi'
                 + '&dc_id=' + encodeURIComponent(_spk.dcId)
                 + '&distance_id=' + encodeURIComponent(_spk.afstand?.id || '');
        const cres = await fetch(cu, { credentials: 'same-origin' });
        const cdata = await cres.json();
        if (cres.ok && !cdata?.error) {
            combiGroepen = Array.isArray(cdata.groepen) ? cdata.groepen : [];
        }
    } catch { /* combi is best-effort; zonder combi tonen we alleen de eigen DC */ }
    if (mySeq !== _spk.laadSeq) return;

    // Scratchpad-key voor het gecombineerde veld: de gesorteerde set dc_id's
    // (globaal uniek, en identiek vanuit welke combi-tab je ook binnenkomt) —
    // combi_group zelf is per-tijdschema en dus niet uniek in localStorage.
    if (combiGroepen.length) {
        combiKey = [_spk.dcId, ...combiGroepen.map(g => g.dc_id)]
            .filter(Boolean).sort().join('+');
    }

    // Samenvoegen: eigen rijders (_grp=null) + per partner-DC een gelabelde
    // groep. Dedup op license_key zodat niemand dubbel in de tegels/scratchpad
    // belandt (een rijder kan in principe maar in één DC per veld zitten).
    const gezien = new Set();
    const merged = [];
    hoofd.forEach(r => {
        if (gezien.has(r.license_key)) return;
        gezien.add(r.license_key);
        r._grp = null;
        merged.push(r);
    });
    combiGroepen.forEach(g => {
        const label = _spkDcLabel(g.dc_id, g.dc_naam);
        (g.deelnemers || []).forEach(r => {
            if (gezien.has(r.license_key)) return;
            gezien.add(r.license_key);
            r._grp = label;
            merged.push(r);
        });
    });
    _spk.deelnemers = merged;
    _spk.combiKey   = combiKey;
    _spk.laden      = false;

    if (!_spk.deelnemers.length) {
        grid.innerHTML = '<div class="jury-placeholder">Geen deelnemers in deze cat + DC.</div>';
        return;
    }
    // Kans-score laden in parallel (non-blocking) — tegels worden meteen
    // gerendered, kans-badges verschijnen zodra fetch klaar is.
    _spkLaadKans();

    // PK/afval: aparte compact-grid met punten-bijhouden scratchpad.
    // Wordt lokaal opgeslagen (geen DB-impact) — speaker-only nota's.
    const afstandKey = _spkAfstandKey(_spk.afstand?.naam || '');
    if (afstandKey === 'puntenkoers') {
        _spkRenderPK(grid, afstandKey);
        return;
    }
    if (afstandKey === 'afvalkoers') {
        _spkRenderAV(grid);
        return;
    }
    // Info-tegels, met een tussenkop per gecombineerde partner-DC (nieuwe rij).
    let vorigGrp = undefined;
    let html = '';
    _spk.deelnemers.forEach(d => {
        if (d._grp !== vorigGrp) {
            if (d._grp) html += `<div class="spk-combi-kop">🔗 samen met ${escHtml(d._grp)}</div>`;
            vorigGrp = d._grp;
        }
        html += `
            <button class="spk-tegel" data-license="${escHtml(d.license_key)}">
                ${_spkKansBadge(d.license_key)}
                <span class="spk-tegel-snr">${d.startnummer ?? '—'}</span>
                <span class="spk-tegel-naam">${escHtml(d.full_name ?? '(onbekend)')}</span>
            </button>`;
    });
    grid.innerHTML = html;
    grid.querySelectorAll('.spk-tegel').forEach(btn => {
        btn.addEventListener('click', () => {
            const lk = btn.dataset.license;
            const rijder = _spk.deelnemers.find(x => x.license_key === lk);
            if (rijder) _spkToonDetail(rijder);
        });
    });
}

// ── Puntenkoers scratchpad ──────────────────────────────────────────────
// Speaker-only bijhoud-UI voor puntenkoers (en straks afvalkoers): de
// speaker noteert per ronde wie hoeveel punten kreeg, ZONDER deze naar de
// DB te schrijven (jury doet de officiële invoer in 'live verwerking').
//
// Flow (zoals jury live-verwerking):
//   1. Klik op nummer-tegel → wordt 'actief' (visueel highlight)
//   2. Klik op +3 / +2 / +1 in actie-strip → punten optellen bij actieve
//      rijder, actief verspringt naar geen-selectie (klaar voor volgende)
//   3. Rechter-bovenhoek van tegel (klein i-knopje) → opent oude detail-modal
//      met historie + NR (zonder selectie-flow te triggeren)
//
// State per (compId, dcId) in localStorage zodat het na refresh / tab-wissel
// blijft staan. Map<license_key, totaal_punten>. Actief = license_key string.
function _spkPKKey() {
    // dc_id is een UUID/PK uit distance_combinations en uniek over alle
    // wedstrijden — comp_id is dus overbodig in de key. Per-cat scope niet
    // nodig want één PK-DC = één gezamenlijke koers (combi-cats racen
    // samen, zelfde puntenoptelling). Zijn de ritten visueel gecombineerd met
    // andere DC's, dan één gedeelde key (de set dc_id's) zodat het scratchpad
    // over het hele veld werkt, ongeacht via welke tab je binnenkomt.
    if (_spk.combiKey) return `spk_pk_combi_${_spk.combiKey}`;
    return `spk_pk_${_spk.dcId || ''}`;
}
function _spkPKLoad() {
    try {
        const raw = localStorage.getItem(_spkPKKey());
        if (!raw) return { punten: {}, actief: null };
        const obj = JSON.parse(raw);
        return {
            punten: (obj && typeof obj.punten === 'object') ? obj.punten : {},
            actief: obj?.actief || null,
        };
    } catch { return { punten: {}, actief: null }; }
}
function _spkPKSave(state) {
    try { localStorage.setItem(_spkPKKey(), JSON.stringify(state)); } catch {}
}

function _spkRenderPK(grid, afstandKey) {
    const state = _spkPKLoad();
    // Sorteer deelnemers op startnummer voor het grid; top-strip is op punten.
    const deelnemers = [..._spk.deelnemers].sort(
        (a, b) => (a.startnummer ?? 9999) - (b.startnummer ?? 9999)
    );
    const tegelHtml = (d, klein) => {
        const punten = state.punten[d.license_key] || 0;
        const isActief = state.actief === d.license_key;
        // Naam in de tegel — speaker zegt 'm op, jury hoort 'm niet nodig
        // (zij zien snr) maar voor speaker is naam-recall essentieel.
        return `<button class="spk-pk-tegel ${klein ? 'spk-pk-tegel-klein' : ''} ${isActief ? 'is-actief' : ''} ${punten ? 'heeft-punten' : ''}"
                        data-license="${escHtml(d.license_key)}"
                        title="${escHtml(d.full_name ?? '')}">
                    ${_spkKansBadge(d.license_key)}
                    <span class="spk-pk-tegel-snr">${d.startnummer ?? '—'}</span>
                    <span class="spk-pk-tegel-naam">${escHtml(d.full_name ?? '')}</span>
                    ${punten ? `<span class="spk-pk-tegel-pt">${punten} pt</span>` : ''}
                    <span class="spk-pk-tegel-hoek" data-rol="detail" title="Toon detail / historie">ⓘ</span>
                </button>`;
    };

    // Top-strip: alle deelnemers MET punten, gesorteerd punten DESC → snr ASC.
    const top = deelnemers
        .filter(d => (state.punten[d.license_key] || 0) > 0)
        .sort((a, b) => {
            const pA = state.punten[a.license_key] || 0;
            const pB = state.punten[b.license_key] || 0;
            if (pA !== pB) return pB - pA;
            return (a.startnummer ?? 9999) - (b.startnummer ?? 9999);
        });
    const topHtml = top.length
        ? top.map(d => tegelHtml(d, false)).join('')
        : '<div class="spk-pk-leeg">Nog geen punten toegekend.</div>';

    // Actie-strip: +3 links, +2/+1 rechts met spacer ertussen. Pas
    // klikbaar zodra er een actieve rijder is geselecteerd.
    const heeftActief = !!state.actief;
    const actieBtn = n => `<button class="spk-pk-actie" data-punten="${n}" ${heeftActief ? '' : 'disabled'}>+${n}</button>`;

    // Reset-knop staat sinds 2026-05-27 in de header (rechts) ipv in de
    // actie-strip — minder kans op accidentele klik tijdens snelle
    // punten-toekenning, en houdt de actie-strip schoon voor de +N pills.
    const resetHtml = `<button class="spk-pk-reset" type="button" title="Alle punten wissen (lokaal)">⟲ reset</button>`;

    grid.innerHTML = `
        <div class="spk-pk-wrap">
            <div class="spk-pk-kop">
                <div class="spk-pk-titel">📍 Puntenkoers — scratchpad (alleen lokaal)</div>
                ${resetHtml}
            </div>
            <div class="spk-pk-top">${topHtml}</div>
            <div class="spk-pk-acties">
                ${actieBtn(3)}
                <span class="spk-pk-spacer"></span>
                ${actieBtn(2)}
                ${actieBtn(1)}
            </div>
            <div class="spk-pk-grid">
                ${_spkGroepeerGrid(deelnemers, d => tegelHtml(d, true))}
            </div>
        </div>`;

    // Tegel-click: rechterhoek-ⓘ = detail-modal; rest = selecteer als actief.
    grid.querySelectorAll('.spk-pk-tegel').forEach(btn => {
        btn.addEventListener('click', e => {
            const lk = btn.dataset.license;
            const rijder = _spk.deelnemers.find(x => x.license_key === lk);
            if (!rijder) return;
            // Klik op hoek → detail-modal (oude gedrag)
            if (e.target.closest('.spk-pk-tegel-hoek')) {
                _spkToonDetail(rijder);
                return;
            }
            // Klik elders op tegel → toggle actief
            const s = _spkPKLoad();
            s.actief = (s.actief === lk) ? null : lk;
            _spkPKSave(s);
            _spkRenderPK(grid, afstandKey);
        });
    });

    // +N actie: voeg punten toe aan actieve rijder, deselecteer
    grid.querySelectorAll('.spk-pk-actie').forEach(btn => {
        btn.addEventListener('click', () => {
            const n = parseInt(btn.dataset.punten, 10) || 0;
            const s = _spkPKLoad();
            if (!s.actief || !n) return;
            s.punten[s.actief] = (s.punten[s.actief] || 0) + n;
            s.actief = null;   // klaar voor volgende rijder
            _spkPKSave(s);
            _spkRenderPK(grid, afstandKey);
        });
    });

    // Reset-knop
    grid.querySelector('.spk-pk-reset')?.addEventListener('click', async () => {
        const ok = await (typeof toonBevestigDialog === 'function'
            ? toonBevestigDialog('Alle lokale punten wissen?', 'Reset puntenkoers', 'Wissen', 'Annuleren')
            : confirm('Alle lokale punten wissen?'));
        if (!ok) return;
        _spkPKSave({ punten: {}, actief: null });
        _spkRenderPK(grid, afstandKey);
    });
}

// ── Afvalkoers scratchpad ───────────────────────────────────────────────
// Speaker-only bijhoud-UI voor afvalkoers, lokaal opgeslagen (geen DB).
// Veel simpeler dan admin live-verwerking (geen by-fault/by-decision/Set
// flow — die zijn voor de échte jury die rondes-getallen moet vastleggen).
// Speaker wil alleen weten: wie ligt er nog in, wie viel er uit en op
// welke positie (= eindrang voor afvallers, hoog = vroeg uit).
//
// Flow:
//   1. Klik op tegel in "nog in koers" → wordt actief (highlight)
//   2. Klik ❌ Eruit → rijder verhuist naar "afgevallen" met eindrang
//      = (aantal_starters - aantal_afgevallen + 1).
//      Bv. 30 starters → 1e afvaller krijgt rang 30, 2e rang 29 etc.
//      Laatste 3 die nog in koers zijn = eindsprint (rang 1-3, speaker
//      doet dat zelf mentaal).
//   3. Klik op afgevallen-tegel → ⏪ undo (terug naar 'nog in koers').
//      Resterende rangen worden automatisch herberekend.
//
// Settings (⚙): heat-config voor context (totaal_ronden, eerste_afval,
// interval, eindsprint). Geen schema-berekening hier — speaker hoeft niet
// exact te weten welk rondebord, alleen de high-level info ("18 ronden,
// vanaf bord 21, eindsprint met 4 rijders"). Validatie minimaal.
// Bij gecombineerde ritten één gedeelde key over het hele veld (zie _spkPKKey).
function _spkAVKey()    { return _spk.combiKey ? `spk_av_combi_${_spk.combiKey}`    : `spk_av_${_spk.dcId || ''}`; }
function _spkAVCfgKey() { return _spk.combiKey ? `spk_avcfg_combi_${_spk.combiKey}` : `spk_avcfg_${_spk.dcId || ''}`; }
function _spkAVLoad() {
    try {
        const raw = localStorage.getItem(_spkAVKey());
        const obj = raw ? JSON.parse(raw) : null;
        const ruw = Array.isArray(obj?.afgevallen) ? obj.afgevallen : [];
        // Backwards-compat: oude state had array van plain license_keys
        // (strings). Migreer naar {lk, ronde, oorzaak, batch} object-vorm.
        // oorzaak: null = reguliere afval (auto-ronde via schema),
        //          'decision' = jury-beslissing (handmatig opgegeven bord)
        // batch:   null = solo, anders ID voor ex-aequo groep (multi-select
        //          decisions delen zelfde batch → krijgen zelfde plek).
        // LET OP: 'batch' MOET hier worden meegenomen anders gaat ex-aequo
        // info verloren na save+load cycle — alle items lijken dan solo.
        const afgevallen = ruw.map(x => typeof x === 'string'
            ? { lk: x, ronde: null, oorzaak: null, batch: null }
            : { lk: x.lk, ronde: x.ronde ?? null, oorzaak: x.oorzaak ?? null, batch: x.batch ?? null });
        // actief: was string|null, nu array (multi-select voor Decision-knop).
        // Migreer oude vorm naar array.
        let actief;
        if (Array.isArray(obj?.actief))   actief = obj.actief;
        else if (typeof obj?.actief === 'string') actief = [obj.actief];
        else                              actief = [];
        return { afgevallen, actief };
    } catch { return { afgevallen: [], actief: [] }; }
}
function _spkAVSave(state) {
    try { localStorage.setItem(_spkAVKey(), JSON.stringify(state)); } catch {}
}
function _spkAVCfgLoad() {
    try {
        const raw = localStorage.getItem(_spkAVCfgKey());
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}
function _spkAVCfgSave(cfg) {
    try {
        if (cfg) localStorage.setItem(_spkAVCfgKey(), JSON.stringify(cfg));
        else     localStorage.removeItem(_spkAVCfgKey());
    } catch {}
}

// ── Schema-helpers — geport van js/live.js (_afvalSchema, _afvalAfgeleidDubbel,
// _afvalRondeVoorPositie). LET OP: bij wijziging aan dit schema in admin
// (live.js) MOET deze copy ook bijgewerkt worden. Pure-functie duplicatie
// is bewust gekozen omdat speaker (jury/jury.js) geen toegang heeft tot
// live.js, en de logica is dependency-vrij. ───────────────────────────
const _SPK_AFVAL_LAATSTE_VAST = 3;   // borden 3, 2, 1 zijn altijd enkele afvallers

// Schema-array voor een gegeven cfg + aantal afvallers te plannen.
// Returns array van rondebord-nummers (rondes-te-gaan), één per
// afvalpositie (1..N). LET OP — verschilt van admin (_afvalSchema in
// live.js) doordat admin ronde-GEREDEN nummers teruggeeft (tr - bord),
// niet bord-nummers; speaker omroept altijd "bord X" dus we tonen bord.
// Verder logica-identiek aan admin: stopt eerste-fase bij eersteTeElim
// en valt dan terug op vaste fase 3,2,1. Bij weinig te elim past alles
// in de vaste fase en wordt eerste-fase overgeslagen — cruciaal anders
// zou positie 1 altijd 'bord eerste_afval' zijn ook als alle rijders
// al uit decisions zijn.
function _spkAVSchema(cfg, teElimineren) {
    if (!cfg) return [];
    const tot = parseInt(cfg.totaal_ronden) || 0;
    const ea  = parseInt(cfg.eerste_afval)  || 0;
    const iv  = parseInt(cfg.interval)      || 1;
    if (!tot || !ea) return [];
    teElimineren = Math.max(0, teElimineren);
    if (teElimineren === 0) return [];

    const eersteBorden = [];
    for (let b = ea; b > _SPK_AFVAL_LAATSTE_VAST; b -= iv) eersteBorden.push(b);
    const vastBorden = [];
    for (let b = _SPK_AFVAL_LAATSTE_VAST; b >= 1; b--) vastBorden.push(b);

    const vastAantal   = Math.min(vastBorden.length, teElimineren);
    const eersteTeElim = teElimineren - vastAantal;
    const dubbel       = Math.max(0, eersteTeElim - eersteBorden.length);

    const arr = [];
    let dubbelLeft = dubbel;
    // Eerste-fase: stop zodra eersteTeElim afvallers gepland zijn — als
    // alle afvallers in de vaste fase 3,2,1 passen wordt eerste-fase
    // overgeslagen (eersteTeElim=0). Voorkomt dat positie 1 = bord 21
    // toont als er door veel decisions nog maar 1 reguliere afvaller is.
    for (const b of eersteBorden) {
        if (arr.length >= eersteTeElim) break;
        const n = (dubbelLeft > 0) ? 2 : 1;
        for (let i = 0; i < n && arr.length < eersteTeElim; i++) {
            arr.push(b);
        }
        if (dubbelLeft > 0) dubbelLeft--;
    }
    for (const b of vastBorden) {
        if (arr.length >= teElimineren) break;
        arr.push(b);
    }
    return arr;
}

// Afgeleid: dubbel, capaciteit, ok-flag. Voor validatie in settings-modal
// en voor stats-strip ("eerste 5 borden dubbel"). Geport van admin
// _afvalAfgeleidDubbel met identieke formule.
function _spkAVAfgeleidDubbel(cfg, teElimineren) {
    const leeg = { dubbel: 0, afvalrondes: 0, teElimineren: 0, ok: false,
                   capaciteit: 0, eersteAantal: 0, vastAantal: 0, eersteBorden: [] };
    if (!cfg) return leeg;
    const tot = parseInt(cfg.totaal_ronden) || 0;
    const ea  = parseInt(cfg.eerste_afval)  || 0;
    const iv  = parseInt(cfg.interval)      || 1;
    if (!tot || !ea) return leeg;
    teElimineren = Math.max(0, teElimineren);

    const eersteBorden = [];
    for (let b = ea; b > _SPK_AFVAL_LAATSTE_VAST; b -= iv) eersteBorden.push(b);
    const eersteAantal = eersteBorden.length;
    const vastAantal   = Math.min(_SPK_AFVAL_LAATSTE_VAST, teElimineren);
    const eersteTeElim = teElimineren - vastAantal;
    const dubbel       = Math.max(0, eersteTeElim - eersteAantal);
    const capaciteit   = 2 * eersteAantal + _SPK_AFVAL_LAATSTE_VAST;
    const afvalrondes  = eersteAantal + _SPK_AFVAL_LAATSTE_VAST;
    const ok = teElimineren <= capaciteit
            && dubbel <= eersteAantal
            && ea >= _SPK_AFVAL_LAATSTE_VAST
            && ea <= tot;
    return { dubbel, afvalrondes, teElimineren, ok, capaciteit, eersteAantal, vastAantal, eersteBorden };
}

function _spkAVRondeVoorPositie(afvalPositie, cfg, teElimineren) {
    const arr = _spkAVSchema(cfg, teElimineren);
    if (afvalPositie < 1 || afvalPositie > arr.length) return null;
    return arr[afvalPositie - 1];
}

// Hercomputeer rondebord-nummers voor alle REGULIERE afvallers (oorzaak=null).
// Decisions HOUDEN geen bord-getal maar verbruiken WEL een schema-positie:
// de jury haalt ze namelijk op DAT moment uit de koers, dus die positie in
// het schema is voorbij. Speaker-model:
//   - Schema-grootte = totaal - eindsprint (VAST, hangt niet af van decisions)
//   - Positie van een afvaller = z'n index in de stack + 1 (incl decisions)
//   - Reguliere krijgt bord uit schema[positie-1]
//   - Decision krijgt geen bord (was niet op een schema-moment)
// Dit verschilt van admin's _afvalSchema (die krimpt schema bij decisions)
// — bewuste afwijking, gebruiker-geverifieerd. Admin moet nog gefixt
// worden maar dat doet de operator zelf na eigen test.
function _spkAVRecomputeRondes(state, cfg, totaal) {
    if (!cfg || !cfg.eindsprint) {
        state.afgevallen.forEach(a => { if (!a.oorzaak) a.ronde = null; });
        return;
    }
    const setCount = Math.max(0, totaal - parseInt(cfg.eindsprint));
    state.afgevallen.forEach((a, idx) => {
        if (a.oorzaak === 'decision') { a.ronde = null; return; }
        a.ronde = _spkAVRondeVoorPositie(idx + 1, cfg, setCount);
    });
}

// (Bord-prompt helper bewust verwijderd 2026-05-27: Decision-rijders krijgen
// geen rondebord-getal meer — decisions vallen niet op een afvalmoment dus
// het ronde-nummer was zonder waarde. Schema-herberekening voor reguliere
// afvallers gebeurt nog wel automatisch via _spkAVRecomputeRondes.)

function _spkRenderAV(grid) {
    const state = _spkAVLoad();
    const cfg   = _spkAVCfgLoad();
    const totaal = _spk.deelnemers.length;

    // Recompute rondes op basis van huidige cfg + state. Modifies in-place,
    // dus elke render heeft up-to-date ronde-getallen (handig na cfg-edit).
    _spkAVRecomputeRondes(state, cfg, totaal);
    _spkAVSave(state);

    const afgeIds = new Set(state.afgevallen.map(a => a.lk));
    // Filter actief: alleen license_keys die nog in koers zitten
    state.actief = state.actief.filter(lk => !afgeIds.has(lk));
    const actiefSet = new Set(state.actief);
    const nogIn = _spk.deelnemers
        .filter(d => !afgeIds.has(d.license_key))
        .sort((a, b) => (a.startnummer ?? 9999) - (b.startnummer ?? 9999));
    // Afgevallen-objecten met eindrang. Eerste afvaller = hoogste rang (= 'totaal').
    // Ex-aequo: items in zelfde batch (= zelfde Decision-call) krijgen samen
    // de LAAGSTE rang van de groep (best-conventie: ex-aequo deelt 'beste'
    // plek). Bv. 3 dec ex-aequo op stack-posities 1,2,3 met totaal 24:
    // normaal rangen 24,23,22 → ex-aequo allen rang 22 (=laagste cijfer).
    // Solo items (batch=null) krijgen gewoon hun stack-positie rang.
    const batchMinRang = new Map();   // batchId → laagste rang van groep
    state.afgevallen.forEach((a, idx) => {
        if (!a.batch) return;
        const r = totaal - idx;
        const huidig = batchMinRang.get(a.batch);
        if (huidig == null || r < huidig) batchMinRang.set(a.batch, r);
    });
    const afgevallen = state.afgevallen.map((a, idx) => {
        const d = _spk.deelnemers.find(x => x.license_key === a.lk);
        const rang = a.batch ? batchMinRang.get(a.batch) : (totaal - idx);
        return { d, rang, ronde: a.ronde, oorzaak: a.oorzaak, isExAequo: !!a.batch };
    }).filter(a => a.d);

    // Knop-enable logica:
    //   Eruit:    precies 1 geselecteerd (reguliere afval, schema vult ronde)
    //   Decision: ≥ 1 geselecteerd (jury haalt 1 of meerdere rijders eruit
    //             tegelijk, allen krijgen hetzelfde bord)
    const aantalActief = state.actief.length;
    const eruitEnabled    = aantalActief === 1;
    const decisionEnabled = aantalActief >= 1;
    const cfgIngevuld = !!(cfg && cfg.totaal_ronden && cfg.eerste_afval && cfg.eindsprint);

    // Schema-afgeleid voor stats-strip ("volgende bord, dubbel/enkel").
    // Schema-grootte = vast (totaal - eindsprint). Positie van volgende
    // afvaller = aantal totaal afgevallen + 1 (decisions consumeren ook).
    let volgendeBord = null;
    let volgendeIsDubbel = false;
    let af = null;
    if (cfgIngevuld) {
        const setCount = Math.max(0, totaal - cfg.eindsprint);
        af = _spkAVAfgeleidDubbel(cfg, setCount);
        const totaalGedaan = state.afgevallen.length;
        volgendeBord = _spkAVRondeVoorPositie(totaalGedaan + 1, cfg, setCount);
        if (volgendeBord != null && totaalGedaan + 1 <= af.dubbel * 2) {
            volgendeIsDubbel = true;
        }
    }

    // Tegel voor "nog in koers" — klikbaar = toggle actief (multi-select)
    const tegelInKoersHtml = d => {
        const isActief = actiefSet.has(d.license_key);
        return `<button class="spk-pk-tegel spk-av-tegel ${isActief ? 'is-actief' : ''}"
                        data-license="${escHtml(d.license_key)}"
                        data-rol="koers"
                        title="${escHtml(d.full_name ?? '')}">
                    ${_spkKansBadge(d.license_key)}
                    <span class="spk-pk-tegel-snr">${d.startnummer ?? '—'}</span>
                    <span class="spk-pk-tegel-naam">${escHtml(d.full_name ?? '')}</span>
                    <span class="spk-pk-tegel-hoek" data-rol="detail" title="Toon detail / historie">ⓘ</span>
                </button>`;
    };
    // Tegel voor afgevallen — klik = undo, toont rang + (bord) + DEC-badge bij decision.
    // Ex-aequo (= meerdere dec in zelfde batch) toont '=' suffix bij de rang.
    const tegelAfgevallenHtml = ({ d, rang, ronde, oorzaak, isExAequo }) => {
        const rondeBadge = ronde != null
            ? `<span class="spk-av-tegel-bord" title="Rondebord bij afvalling">b${ronde}</span>`
            : '';
        const decBadge = oorzaak === 'decision'
            ? `<span class="spk-av-tegel-dec" title="By Decision — uit koers gehaald">DEC</span>`
            : '';
        const exAequoSuffix = isExAequo ? '<sup class="spk-av-tegel-eq" title="ex aequo">=</sup>' : '';
        return `<button class="spk-pk-tegel spk-av-tegel spk-av-tegel-uit ${oorzaak === 'decision' ? 'is-decision' : ''}"
                data-license="${escHtml(d.license_key)}"
                data-rol="undo"
                title="Klik om terug te zetten: ${escHtml(d.full_name ?? '')}${isExAequo ? ' (ex aequo met andere decision-rijders)' : ''}">
            <span class="spk-pk-tegel-snr">${d.startnummer ?? '—'}</span>
            <span class="spk-pk-tegel-naam">${escHtml(d.full_name ?? '')}</span>
            <span class="spk-av-tegel-rang">${rang}<sup>e</sup>${exAequoSuffix}</span>
            ${rondeBadge}
            ${decBadge}
            <span class="spk-pk-tegel-hoek" data-rol="detail" title="Toon detail / historie">ⓘ</span>
        </button>`;
    };

    // Settings-strip: korte samenvatting + ✎ knop
    const ivLabel = cfg && cfg.interval === 2 ? 'om-de-ronde' : 'elke ronde';
    let cfgSamenvatting;
    if (!cfgIngevuld) {
        cfgSamenvatting = '<i>niet ingesteld — rondes worden niet automatisch toegekend</i>';
    } else if (af && !af.ok) {
        cfgSamenvatting = `${cfg.totaal_ronden} ronden · vanaf bord ${cfg.eerste_afval} (${ivLabel}) · eindsprint ${cfg.eindsprint} `
                        + `<span class="spk-av-cfg-warn">⚠ instellingen kloppen niet</span>`;
    } else {
        const dubbelTxt = af.dubbel > 0
            ? ` · eerste ${af.dubbel} bord${af.dubbel > 1 ? 'en' : ''} dubbel`
            : '';
        cfgSamenvatting = `${cfg.totaal_ronden} ronden · vanaf bord ${cfg.eerste_afval} (${ivLabel}) · eindsprint ${cfg.eindsprint}${dubbelTxt}`;
    }
    const settingsStrip = `
        <div class="spk-av-cfg">
            <span class="spk-av-cfg-info">⚙ ${cfgSamenvatting}</span>
            <button class="spk-av-cfg-btn" type="button" title="Heat-instellingen">✎</button>
        </div>`;

    // Stats + actie-knoppen
    const nogTeElim = cfgIngevuld
        ? Math.max(0, nogIn.length - cfg.eindsprint)
        : null;
    // Volgende-bord + diagnose-tooltip. Schema-grootte = vast (totaal -
    // eindsprint), decisions consumeren posities maar krimpen schema niet.
    let volgendeBordTxt = '';
    if (volgendeBord != null && cfgIngevuld) {
        const setCount = Math.max(0, totaal - cfg.eindsprint);
        const schemaArr = _spkAVSchema(cfg, setCount);
        const totaalGedaan = state.afgevallen.length;
        const komende = schemaArr.slice(totaalGedaan, totaalGedaan + 6);
        const decisions = state.afgevallen.filter(a => a.oorzaak === 'decision').length;
        const tip = `Schema vanaf nu: ${komende.join(' → ') || '(klaar)'}\n`
                  + `Totaal schema (${schemaArr.length}): ${schemaArr.join(',')}\n`
                  + `totaal afgevallen=${totaalGedaan} (waarvan ${decisions} dec) · setCount=${setCount}`;
        volgendeBordTxt = `<span class="spk-av-volgend" title="${escHtml(tip)}"><b>Volgende:</b> bord ${volgendeBord}${volgendeIsDubbel ? ' <em>(dubbel)</em>' : ''}</span>`;
    }
    const statsHtml = `
        <div class="spk-av-stats">
            <span><b>Nog in koers:</b> ${nogIn.length}</span>
            ${cfgIngevuld
                ? `<span><b>Eindsprint:</b> ${cfg.eindsprint}</span>
                   <span><b>Te elim:</b> ${nogTeElim}</span>
                   ${volgendeBordTxt}`
                : ''}
            <span class="spk-pk-spacer"></span>
            <button class="spk-av-decision" type="button" ${decisionEnabled ? '' : 'disabled'}
                    title="By Decision — ${aantalActief > 1 ? aantalActief + ' rijders' : 'rijder'} uit koers (schema wordt herberekend)">⚠ Decision${aantalActief > 1 ? ' (' + aantalActief + ')' : ''}</button>
            <button class="spk-av-eruit" type="button" ${eruitEnabled ? '' : 'disabled'}
                    title="${aantalActief > 1 ? 'Eruit werkt alleen voor één rijder per keer' : 'Reguliere afval volgens schema'}">❌ Eruit</button>
        </div>`;

    grid.innerHTML = `
        <div class="spk-pk-wrap">
            <div class="spk-pk-kop">
                <div class="spk-pk-titel">❌ Afvalkoers — scratchpad (alleen lokaal)</div>
                <button class="spk-pk-reset" type="button" title="Alle afvalmarkeringen wissen (lokaal)">⟲ reset</button>
            </div>
            ${settingsStrip}
            ${afgevallen.length
                ? `<div class="spk-av-uit-strip">${afgevallen.map(tegelAfgevallenHtml).join('')}</div>`
                : '<div class="spk-pk-leeg">Nog niemand afgevallen.</div>'}
            ${statsHtml}
            <div class="spk-pk-grid">
                ${_spkGroepeerGrid(nogIn, tegelInKoersHtml)}
            </div>
        </div>`;

    // Click op tegel: hoek = detail, anders rol-afhankelijk.
    // Multi-select: koers-tegel toggle voegt toe/verwijdert uit actief-array.
    grid.querySelectorAll('.spk-av-tegel').forEach(btn => {
        btn.addEventListener('click', e => {
            const lk = btn.dataset.license;
            const rijder = _spk.deelnemers.find(x => x.license_key === lk);
            if (!rijder) return;
            if (e.target.closest('.spk-pk-tegel-hoek')) {
                _spkToonDetail(rijder);
                return;
            }
            const s = _spkAVLoad();
            const rol = btn.dataset.rol;
            if (rol === 'koers') {
                // Toggle in array
                const i = s.actief.indexOf(lk);
                if (i >= 0) s.actief.splice(i, 1);
                else        s.actief.push(lk);
            } else if (rol === 'undo') {
                s.afgevallen = s.afgevallen.filter(x => x.lk !== lk);
            }
            _spkAVSave(s);
            _spkRenderAV(grid);
        });
    });

    // ❌ Eruit — reguliere afval, krijgt auto-ronde via schema.
    // Alleen bij PRECIES 1 geselecteerd (anders disabled in render).
    // batch=null → solo (geen ex-aequo); rang = stack-positie zelf.
    grid.querySelector('.spk-av-eruit')?.addEventListener('click', () => {
        const s = _spkAVLoad();
        if (s.actief.length !== 1) return;
        const lk = s.actief[0];
        if (!s.afgevallen.some(a => a.lk === lk)) {
            s.afgevallen.push({ lk, ronde: null, oorzaak: null, batch: null });
        }
        s.actief = [];
        _spkAVSave(s);
        _spkRenderAV(grid);
    });

    // ⚠ Decision — 1 of meer rijders uit koers gehaald door jury-beslissing.
    // Multi-select: alle in zelfde call krijgen zelfde batch-id zodat ze
    // EX-AEQUO geklasseerd worden bij rang-toekenning (zelfde plek-getal
    // omdat ze op het zelfde moment uit de koers gehaald zijn). Decisions
    // consumeren samen N posities in het schema en delen de LAAGSTE plek
    // van die groep (best-conventie). Geen bord-getal want decisions
    // vallen niet op een afvalmoment.
    grid.querySelector('.spk-av-decision')?.addEventListener('click', () => {
        const s = _spkAVLoad();
        if (s.actief.length < 1) return;
        const batchId = 'b' + Date.now();   // unieke marker per batch
        for (const lk of s.actief) {
            if (!s.afgevallen.some(a => a.lk === lk)) {
                s.afgevallen.push({ lk, ronde: null, oorzaak: 'decision', batch: batchId });
            }
        }
        s.actief = [];
        _spkAVSave(s);
        _spkRenderAV(grid);
    });

    // Reset-knop — wist ÉN de afvalmarkeringen ÉN de heat-instellingen.
    // Hele scratchpad terug naar nul, klaar voor volgende heat-DC.
    grid.querySelector('.spk-pk-reset')?.addEventListener('click', async () => {
        const ok = await (typeof toonBevestigDialog === 'function'
            ? toonBevestigDialog(
                'Alle afvalmarkeringen ÉN heat-instellingen wissen?',
                'Reset afvalkoers', 'Wissen', 'Annuleren')
            : confirm('Alle afvalmarkeringen én heat-instellingen wissen?'));
        if (!ok) return;
        _spkAVSave({ afgevallen: [], actief: [] });
        _spkAVCfgSave(null);
        _spkRenderAV(grid);
    });

    // Settings-modal
    grid.querySelector('.spk-av-cfg-btn')?.addEventListener('click',
        () => _spkAVOpenCfgModal(grid));
}

// Settings-modal voor heat-config. Lokaal opgeslagen per dc_id.
// Vereenvoudigde versie van admin: alleen velden + simpele validatie,
// geen schema-berekening (speaker hoeft niet exact ronde-getallen te
// weten, alleen context).
function _spkAVOpenCfgModal(grid) {
    const cfg = _spkAVCfgLoad() || {};
    const totDeeln = _spk.deelnemers.length;

    const overlay = document.createElement('div');
    overlay.className = 'spk-detail-overlay';
    overlay.innerHTML = `
        <div class="spk-detail-modal spk-av-cfg-modal" role="dialog">
            <div class="spk-detail-kop">
                <div class="spk-detail-snr">⚙</div>
                <h2 class="spk-detail-naam">Afvalkoers-instellingen</h2>
                <button class="spk-detail-sluit" aria-label="Sluiten">&times;</button>
            </div>
            <div class="spk-detail-body">
                <p class="spk-av-cfg-uitleg">
                    ${totDeeln} starters in deze heat. Vul in voor context-info
                    bovenaan het scratchpad. (Niet verplicht — alleen visueel.)
                </p>
                <label class="spk-av-cfg-veld">
                    <span>Totaal aantal ronden</span>
                    <input type="number" id="avcfg-totaal" min="1" value="${cfg.totaal_ronden ?? ''}" placeholder="bv. 18">
                </label>
                <label class="spk-av-cfg-veld">
                    <span>Eerste afval-rondebord</span>
                    <input type="number" id="avcfg-eerste" min="4" value="${cfg.eerste_afval ?? ''}" placeholder="bv. 21">
                    <small>rondes-te-gaan bij eerste afvalling</small>
                </label>
                <label class="spk-av-cfg-veld">
                    <span>Afval-interval</span>
                    <select id="avcfg-interval">
                        <option value="1" ${parseInt(cfg.interval) !== 2 ? 'selected' : ''}>Elke ronde</option>
                        <option value="2" ${parseInt(cfg.interval) === 2 ? 'selected' : ''}>Om de ronde</option>
                    </select>
                </label>
                <label class="spk-av-cfg-veld">
                    <span>Eindsprint (aantal rijders)</span>
                    <input type="number" id="avcfg-eindsprint" min="0" value="${cfg.eindsprint ?? ''}" placeholder="bv. 4">
                    <small>aantal rijders dat niet meer afvalt</small>
                </label>
                <div class="spk-av-cfg-afgeleid" id="avcfg-afgeleid">
                    <!-- live berekend overzicht (capaciteit / dubbel) -->
                </div>
                <div class="spk-av-cfg-acties">
                    <button class="btn-secondary" id="avcfg-annuleer">Annuleren</button>
                    <button class="btn-danger"    id="avcfg-wis">Wissen</button>
                    <button class="btn-primary"   id="avcfg-opslaan">Opslaan</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const sluit = () => overlay.remove();
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    overlay.querySelector('.spk-detail-sluit').addEventListener('click', sluit);
    overlay.querySelector('#avcfg-annuleer').addEventListener('click', sluit);

    overlay.querySelector('#avcfg-wis').addEventListener('click', async () => {
        const ok = await (typeof toonBevestigDialog === 'function'
            ? toonBevestigDialog('Heat-instellingen wissen?', 'Afvalkoers-config', 'Wissen', 'Annuleren')
            : confirm('Heat-instellingen wissen?'));
        if (!ok) return;
        _spkAVCfgSave(null);
        sluit();
        _spkRenderAV(grid);
    });

    overlay.querySelector('#avcfg-opslaan').addEventListener('click', async () => {
        const nieuw = {
            totaal_ronden: parseInt(overlay.querySelector('#avcfg-totaal').value)     || null,
            eerste_afval:  parseInt(overlay.querySelector('#avcfg-eerste').value)     || null,
            interval:      parseInt(overlay.querySelector('#avcfg-interval').value)   || 1,
            eindsprint:    parseInt(overlay.querySelector('#avcfg-eindsprint').value) || null,
        };
        // Hard fouten (verhinderen save)
        const fouten = [];
        if (!nieuw.totaal_ronden) fouten.push('Totaal aantal ronden is verplicht');
        if (!nieuw.eerste_afval)  fouten.push('Eerste afval-rondebord is verplicht');
        if (!nieuw.eindsprint)    fouten.push('Eindsprint is verplicht');
        if (nieuw.eerste_afval && nieuw.eerste_afval < 4) {
            fouten.push('Eerste afval-rondebord moet ≥ 4 zijn (3-2-1 zijn vaste laatste rondes)');
        }
        if (nieuw.eindsprint && nieuw.eindsprint >= totDeeln) {
            fouten.push(`Eindsprint (${nieuw.eindsprint}) moet kleiner zijn dan starters (${totDeeln})`);
        }
        if (nieuw.totaal_ronden && nieuw.eerste_afval && nieuw.eerste_afval > nieuw.totaal_ronden) {
            fouten.push(`Eerste afval-bord (${nieuw.eerste_afval}) kan niet groter zijn dan totaal-ronden (${nieuw.totaal_ronden})`);
        }
        if (fouten.length) {
            await (typeof toonBevestigDialog === 'function'
                ? toonBevestigDialog(fouten.join('\n'), 'Instellingen kloppen niet', 'OK', '')
                : alert(fouten.join('\n')));
            return;
        }
        // Soft waarschuwing: capaciteit-check. Bij mismatch (te veel dubbel
        // nodig of negatieve capaciteit) toch laten opslaan na bevestiging.
        const teElim = Math.max(0, totDeeln - nieuw.eindsprint);
        const af = _spkAVAfgeleidDubbel(nieuw, teElim);
        if (!af.ok) {
            const door = af.dubbel > af.eersteAantal
                ? `Te weinig afvalrondes: ${af.afvalrondes} beschikbaar, maar ${af.teElimineren} rijders te elimineren `
                  + `(zou ${af.dubbel} dubbele borden nodig hebben, maximum is ${af.eersteAantal}).`
                : `Schema-validatie faalt voor ${af.teElimineren} te elimineren in ${af.afvalrondes} rondes.`;
            const okMsg = await toonBevestigDialog(
                `Let op: ${door}\n\nToch opslaan?`,
                'Afvalkoers-config', 'Toch opslaan', 'Annuleren'
            );
            if (!okMsg) return;
        }
        _spkAVCfgSave(nieuw);
        sluit();
        _spkRenderAV(grid);
    });

    // Live-update van afgeleid overzicht (capaciteit / dubbel / borden)
    const updateAfgeleid = () => {
        const tmp = {
            totaal_ronden: parseInt(overlay.querySelector('#avcfg-totaal').value)     || 0,
            eerste_afval:  parseInt(overlay.querySelector('#avcfg-eerste').value)     || 0,
            interval:      parseInt(overlay.querySelector('#avcfg-interval').value)   || 1,
            eindsprint:    parseInt(overlay.querySelector('#avcfg-eindsprint').value) || 0,
        };
        const teElim = Math.max(0, totDeeln - tmp.eindsprint);
        const af = _spkAVAfgeleidDubbel(tmp, teElim);
        const wrap = overlay.querySelector('#avcfg-afgeleid');
        if (!tmp.totaal_ronden || !tmp.eerste_afval || !tmp.eindsprint) {
            wrap.innerHTML = '<i>Vul de velden in voor een berekening.</i>';
            wrap.classList.remove('av-fout', 'av-ok');
            return;
        }
        const ivLabel = tmp.interval === 2 ? 'om-de-ronde' : 'elke ronde';
        const teveelAfval = !af.ok && af.dubbel > af.eersteAantal;
        const dubbelTxt = teveelAfval
            ? `<span class="av-fout-tekst">capaciteit ${af.capaciteit}, tekort van ${af.teElimineren - af.capaciteit}</span>`
            : af.dubbel > 0
                ? `eerste <b>${af.dubbel}</b> bord${af.dubbel > 1 ? 'en' : ''} dubbel (2 afvallers)`
                : 'geen dubbele rondes nodig';
        const allBorden = [...af.eersteBorden, 3, 2, 1];
        wrap.innerHTML =
            `<b>${totDeeln}</b> starters → <b>${af.teElimineren}</b> elimineren · `
            + `${af.eersteAantal} bord${af.eersteAantal !== 1 ? 'en' : ''} (${ivLabel}) `
            + `+ vast 3,2,1 = <b>${af.afvalrondes}</b> afvalrondes · ${dubbelTxt}`
            + `<div class="spk-av-cfg-borden">Borden: ${allBorden.join(' · ')}</div>`;
        wrap.classList.toggle('av-fout', teveelAfval);
        wrap.classList.toggle('av-ok',  !teveelAfval && af.ok);
    };
    overlay.querySelectorAll('#avcfg-totaal, #avcfg-eerste, #avcfg-interval, #avcfg-eindsprint')
        .forEach(el => el.addEventListener('input', updateAfgeleid));
    updateAfgeleid();
}

// ── Kans-score (1-10) per rijder in huidige DC ─────────────────────────
// Backend speaker_kans berekent op basis van historie in vergelijkbare
// afstand-groep (ultra_sprint/sprint/lang). Score is RELATIEF in DC:
// 10 = top-favoriet binnen deze cat+DC, 1 = outsider. Geen historie → null.
//
// Cache per (dc_id+cat) zodat tab-switch snel is. Async non-blocking:
// tegels worden eerst zonder badge gerendered, badges verschijnen zodra
// data binnen is via re-render-call.
async function _spkLaadKans() {
    if (!_spk.dcId) return;
    const cacheKey = _spk.dcId;   // DC-breed (alle categorieën samen)
    if (_spk.kansCache?.key === cacheKey) return;   // hit, geen reload
    try {
        const url = '?action=speaker_kans'
                  + '&dc_id=' + encodeURIComponent(_spk.dcId);
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) return;   // silent fail — badges blijven leeg
        const map = new Map((data.rijders || []).map(r => [r.license_key, r]));
        _spk.kansCache = { key: cacheKey, map, groepen: data.groepen || [] };
        // Re-render alleen als deze data nog actueel is (gebruiker kan tussentijds
        // van DC gewisseld zijn). Check via cacheKey vs huidig _spk.dcId.
        if (cacheKey === _spk.dcId) {
            // Re-render standaard tegel-grid (PK/AV hebben eigen render-paden;
            // die zou je apart kunnen voorzien — V1 alleen standaard tegels)
            _spkUpdateKansBadges();
        }
    } catch {}
}

// Update kans-badges in alle tegels zonder hele grid te herrenderen.
// Zoekt elke tegel op data-license en injecteert badge-html. Werkt voor:
//   • standaard tegel-grid (.spk-tegel)
//   • PK scratchpad (.spk-pk-tegel)
//   • AV scratchpad nog-in-koers (.spk-pk-tegel.spk-av-tegel)
// NIET voor AV afgevallen-tegels (.spk-av-tegel-uit) — rijder is uit
// koers, voorspelling niet meer relevant en de tegel is al druk met
// rang-cijfer + bord-badge + eventueel DEC-badge.
function _spkUpdateKansBadges() {
    const grid = elJ('spk-grid');
    if (!grid) return;
    const sel = '.spk-tegel, .spk-pk-tegel:not(.spk-av-tegel-uit)';
    grid.querySelectorAll(sel).forEach(btn => {
        const lk = btn.dataset.license;
        // Bestaande badge weghalen (re-render bij data-update)
        btn.querySelectorAll('.spk-kans').forEach(el => el.remove());
        const badgeHtml = _spkKansBadge(lk);
        if (badgeHtml) {
            btn.insertAdjacentHTML('afterbegin', badgeHtml);
        }
    });
}

// Render HTML voor één badge. Geeft '' als geen cache of geen entry.
// Stiermenstadia:
//   🌟 favoriet (7-10) · ⭐ middenmoot (4-6) · ✨ outsider (1-3) · ❔ onbekend
function _spkKansBadge(licenseKey) {
    if (!_spk.kansCache?.map) return '';
    const k = _spk.kansCache.map.get(licenseKey);
    if (!k) return '';
    const s = k.score;
    let emoji, klasse, lbl;
    if (s == null)      { emoji = '❔'; klasse = 'onbekend';   lbl = 'onbekend'; }
    else if (s >= 7)    { emoji = '🌟'; klasse = 'favoriet';   lbl = 'favoriet'; }
    else if (s >= 4)    { emoji = '⭐'; klasse = 'middenmoot'; lbl = 'middenmoot'; }
    else                { emoji = '✨'; klasse = 'outsider';   lbl = 'outsider'; }
    // title-attr blijft als desktop-hover-fallback. Op tablet (geen hover)
    // wordt de popover via tap geactiveerd — zie _spkToggleKansPopover.
    // data-license is ondub van de tegel-license-attr; gebruikt door de
    // popover-handler om de juiste reden uit kansCache op te halen.
    const tip = `${lbl}${s != null ? ' (' + s + '/10)' : ''}\n${k.reden || ''}`;
    return `<span class="spk-kans spk-kans-${klasse}"
                  data-license="${escHtml(licenseKey)}"
                  title="${escHtml(tip)}">${emoji}${s != null ? s : ''}</span>`;
}

// ── Kans-badge popover (tap-to-show, voor tablet) ──────────────────────
// Native HTML title-tooltips werken niet op touch — daarom een custom
// popover: tap badge → toont dark bubble met label + reden-tekst.
// Tweede tap op zelfde badge of klik elders sluit. Werkt op alle tegel-
// types (standaard + PK/AV) via document-level event delegation.
let _spkKansPopover = null;

function _spkToggleKansPopover(badgeEl) {
    // Bestaande popover wegnemen — toggle-gedrag als zelfde badge
    const wasZelfde = _spkKansPopover && _spkKansPopover._badge === badgeEl;
    if (_spkKansPopover) {
        _spkKansPopover.remove();
        _spkKansPopover = null;
        if (wasZelfde) return;   // tweede tap = sluiten, niet heropenen
    }
    const lk = badgeEl.dataset.license;
    if (!lk || !_spk.kansCache?.map) return;
    const k = _spk.kansCache.map.get(lk);
    if (!k) return;

    const s = k.score;
    let lbl, klasse;
    if (s == null)    { lbl = 'Onbekend';   klasse = 'onbekend'; }
    else if (s >= 7)  { lbl = 'Favoriet';   klasse = 'favoriet'; }
    else if (s >= 4)  { lbl = 'Middenmoot'; klasse = 'middenmoot'; }
    else              { lbl = 'Outsider';   klasse = 'outsider'; }

    const pop = document.createElement('div');
    pop.className = `spk-kans-popover spk-kans-popover-${klasse}`;
    pop.innerHTML = `
        <div class="spk-kans-popover-label">${escHtml(lbl)}${s != null ? ' &middot; ' + s + '/10' : ''}</div>
        <div class="spk-kans-popover-reden">${escHtml(k.reden || 'Geen historie beschikbaar.')}</div>
    `;
    document.body.appendChild(pop);

    // Positioneer: vlak onder de badge, clamp binnen viewport
    const r  = badgeEl.getBoundingClientRect();
    const pr = pop.getBoundingClientRect();
    let top  = r.bottom + 6;
    let left = r.left + (r.width / 2) - (pr.width / 2);
    if (left + pr.width > window.innerWidth  - 8) left = window.innerWidth  - pr.width - 8;
    if (left < 8) left = 8;
    if (top  + pr.height > window.innerHeight - 8) top = r.top - pr.height - 6;
    pop.style.top  = top  + 'px';
    pop.style.left = left + 'px';
    pop._badge = badgeEl;
    _spkKansPopover = pop;
}

// 1× registreren: capture-phase document handler. Capture zodat we kunnen
// stopPropagation VOORDAT de tegel-click handler vuurt (anders zou tap op
// badge ook tegel-selectie / detail-modal triggeren).
(function _spkInitKansPopoverHandlers() {
    if (window._spkKansHandlerInit) return;
    window._spkKansHandlerInit = true;
    document.addEventListener('click', e => {
        const badge = e.target.closest('.spk-kans');
        if (badge) {
            e.stopPropagation();
            e.preventDefault();
            _spkToggleKansPopover(badge);
            return;
        }
        // Klik elders → sluit popover als er één open is
        if (_spkKansPopover && !e.target.closest('.spk-kans-popover')) {
            _spkKansPopover.remove();
            _spkKansPopover = null;
        }
    }, true);
    // Scroll/resize → popover meebewegen of (eenvoudiger) sluiten
    window.addEventListener('scroll', () => {
        if (_spkKansPopover) { _spkKansPopover.remove(); _spkKansPopover = null; }
    }, true);
})();

// ── Detail-modal voor één rijder ───────────────────────────────────────────
function _spkToonDetail(r) {
    const overlay = document.createElement('div');
    overlay.className = 'spk-detail-overlay';
    const veld = (label, waarde) => waarde !== null && waarde !== '' && waarde !== undefined
        ? `<div class="spk-detail-rij"><span class="spk-detail-lbl">${escHtml(label)}</span><span class="spk-detail-val">${escHtml(waarde)}</span></div>`
        : '';
    const leeftijd = r.birth_year ? (new Date().getFullYear() - r.birth_year) : '';

    // Pending-rijders: placeholder uit historische PDF-import zonder echte
    // KNSB-licentie. Mist club/jaar/etc. — toon dat expliciet zodat speaker
    // niet denkt dat de data 'leeg' is door een bug.
    const isPending = r.pending_source === 'historie';
    const pendingBanner = isPending
        ? `<div class="spk-detail-pending-banner">
               ⚡ <b>Nog niet gekoppeld aan KNSB-account</b><br>
               <span class="spk-detail-pending-sub">Deze naam komt uit een
               geïmporteerde historie-PDF. Zodra de rijder een KNSB-licentie
               heeft, kan een beheerder hem in Helpers → Pending koppelen.</span>
           </div>`
        : '';

    overlay.innerHTML = `
        <div class="spk-detail-modal${isPending ? ' spk-detail-modal-pending' : ''}" role="dialog" aria-labelledby="spk-detail-titel">
            <div class="spk-detail-kop">
                <div class="spk-detail-snr">${isPending ? '⚡' : (r.startnummer ?? '—')}</div>
                <h2 class="spk-detail-naam" id="spk-detail-titel">${escHtml(r.full_name ?? '')}</h2>
                <button class="spk-detail-sluit" aria-label="Sluiten">&times;</button>
            </div>
            <div class="spk-detail-body">
                ${pendingBanner}
                ${veld('Categorie',     r.category)}
                ${veld('Geboortejaar',  r.birth_year)}
                ${veld('Leeftijd',      leeftijd ? leeftijd + ' jaar' : '')}
                ${veld('Nationaliteit', r.nationality)}
                ${veld('Woonplaats',    r.city)}
                ${veld('Club',          r.club_full)}
                ${veld('Sponsor',       r.sponsor)}
                <!-- Positie(s) in het serie-klassement — asynchroon gevuld;
                     leeg (verborgen) als deze wedstrijd niet in een serie zit. -->
                <div id="spk-serie-klassement"></div>
                <!-- Snelste tijd op de geselecteerde afstand — asynchroon
                     gevuld door _spkVulHistorie zodra de historie binnen is. -->
                <div id="spk-pr-rij"></div>
                <div class="spk-historie-wrap" id="spk-historie-wrap">
                    <div class="spk-historie-titel">📜 Wedstrijd-historie</div>
                    <div class="spk-historie-content jury-laden">Laden…</div>
                </div>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    overlay.querySelector('.spk-detail-sluit').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    const onKey = e => {
        if (e.key === 'Escape') { sluit(); document.removeEventListener('keydown', onKey); }
    };
    document.addEventListener('keydown', onKey);

    // Historie asynchroon ophalen + invullen — modal opent direct met basis-
    // info, historie verschijnt zodra de fetch klaar is.
    _spkVulHistorie(overlay, r.license_key);
    _spkVulSerieKlassement(overlay, r.license_key);
}

// ── Serie-klassement-positie(s) in de modal ─────────────────────────────────
// Toont de OPGESLAGEN positie(s) van de rijder in het serie-klassement waar
// deze wedstrijd deel van uitmaakt. Puur uitlezen (klassement_posities) — geen
// herberekening. Een rijder kan in meerdere secties staan (bv. losse DP3 én
// gecombineerde DP3/HP3); die tonen we allemaal.
async function _spkVulSerieKlassement(overlay, licenseKey) {
    const box = overlay.querySelector('#spk-serie-klassement');
    if (!box || !licenseKey) return;
    try {
        const res  = await fetch('?action=speaker_serieklassement&license_key=' + encodeURIComponent(licenseKey),
                                 { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) return;   // stil: blok blijft leeg/verborgen
        const series = Array.isArray(data.series) ? data.series : [];
        if (!series.length) return;
        const blokken = series.map(s => {
            const rijen = (s.posities || []).map(p => {
                const pnt = (p.punten_totaal != null) ? ` · ${p.punten_totaal} ptn` : '';
                const sec = p.categorie ? escHtml(p.categorie) : '—';
                return `<div class="spk-serie-rij"><span class="spk-serie-pos">${p.positie}e</span>
                        <span class="spk-serie-sec">${sec}</span><span class="spk-serie-ptn">${pnt}</span></div>`;
            }).join('');
            const kop = escHtml(s.serie_naam) + (s.seizoen ? ` <span class="spk-serie-seizoen">${escHtml(String(s.seizoen))}</span>` : '');
            return `<div class="spk-serie-groep"><div class="spk-serie-naam">🏆 ${kop}</div>${rijen}</div>`;
        }).join('');
        box.innerHTML = `<div class="spk-serie-wrap"><div class="spk-serie-titel">Serie-klassement</div>${blokken}</div>`;
    } catch { /* stil */ }
}

// ── Historie ophalen + renderen in modal ───────────────────────────────────
// Fuzzy-key voor afstand: groepeert varianten zoals "200m" / "200 meter" /
// "Puntenkoers 5km" / "5000m punten" naar één gemeen key. Special types
// (punten/afval/marathon/estafette) winnen van pure-distance — anders zou
// 5000m-puntenkoers tellen als generic "5000m". Voor sprint/inline distances
// wordt de afstand-getal in meters de key. Onbekende strings vallen terug
// op de hele tekst (lowercase trimmed).
function _spkAfstandKey(naam) {
    if (!naam) return '';
    const s = String(naam).toLowerCase();
    if (s.includes('punten'))    return 'puntenkoers';
    if (s.includes('afval'))     return 'afvalkoers';
    if (s.includes('marathon'))  return 'marathon';
    if (s.includes('estafette') || s.includes('relay')) return 'estafette';
    // One Lap = benoemde afstand zonder vast metertal (varieert per baan/weg).
    // Net als puntenkoers een special-type-key, anders zou "One Lap" (bare
    // distance_naam) niet matchen met "Jun.B Vrouwen One Lap" (dc_naam met
    // cat-prefix) → filter zou nooit aanslaan. Check vóór de getal-regex zodat
    // "1 ronde" niet als "1m" wordt geïnterpreteerd.
    if (s.includes('one lap') || s.includes('onelap')
        || /\b(?:1|één|eén|een)\s*ronde\b/.test(s)) return 'onelap';
    // Eerste getal in de string → "{n}m"
    const m = s.match(/(\d+)\s*(m|km|meter|kilometer)?/i);
    if (m) {
        let n = parseInt(m[1], 10);
        if (m[2] && /^k/i.test(m[2])) n *= 1000;  // km → m
        if (n > 0) return n + 'm';
    }
    return s.trim();
}
// Human-leesbaar label voor de filter-dropdown
function _spkAfstandLabel(key) {
    if (key === 'puntenkoers') return 'Puntenkoers (alle)';
    if (key === 'afvalkoers')  return 'Afvalkoers (alle)';
    if (key === 'marathon')    return 'Marathon';
    if (key === 'estafette')   return 'Estafette';
    if (key === 'onelap')      return 'One Lap (alle)';
    return key;
}

// Afstand-key van de momenteel geselecteerde afstand (niveau 2), voor het
// filteren van de modal-historie. Leeg → geen filter mogelijk.
function _spkHuidigeAfstandKey() {
    return _spkAfstandKey(_spk.afstand?.naam || '');
}

async function _spkVulHistorie(overlay, licenseKey) {
    const container = overlay.querySelector('.spk-historie-content');
    if (!container || !licenseKey) return;
    try {
        const res  = await fetch('?action=speaker_historie&license_key=' + encodeURIComponent(licenseKey),
                                 { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        const rijen = Array.isArray(data.historie) ? data.historie : [];

        if (!rijen.length) {
            container.classList.remove('jury-laden');
            container.innerHTML = '<div class="spk-historie-leeg">Geen vorige wedstrijden gevonden.</div>';
            return;
        }

        // Bepaal of er een DC-context is (= een geselecteerde DC met een
        // herkenbare afstand). Zo ja, toggle-checkbox 'Alleen [afstand]':
        // default AAN, want speaker bekijkt rijder typisch omdat hij die
        // afstand zo gaat aankondigen.
        const hintKey = _spkHuidigeAfstandKey();
        const heeftMatchInHistorie = hintKey
            && rijen.some(r => _spkAfstandKey(r.distance_naam) === hintKey);

        // ── Snelste tijd ooit op de geselecteerde afstand ────────────────
        // Toon één regel boven de historie: wedstrijdnaam als label,
        // tijd als waarde. Alleen voor afstand-types waar 'snelste tijd'
        // betekenis heeft (sprint/inline distances; voor puntenkoers/
        // afvalkoers/marathon valt 't ook prima — wordt gewoon de snelste
        // klok-tijd). Verbergt zichzelf als geen DC-context of geen tijden.
        if (hintKey) {
            // PDO geeft INT-kolommen vaak als JS-string terug — Number()
            // forceren zodat zowel filter als min-vergelijking numeriek
            // werken (anders zou "100" < "16" true zijn lexicaal).
            const opAfstand = rijen
                .map(r => ({ ...r, _tijdNum: r.tijd_ms != null ? Number(r.tijd_ms) : null }))
                .filter(r => _spkAfstandKey(r.distance_naam) === hintKey
                             && r._tijdNum != null && r._tijdNum > 0);
            if (opAfstand.length) {
                const snelste = opAfstand.reduce((a, b) => (a._tijdNum < b._tijdNum ? a : b));
                const prRij = overlay.querySelector('#spk-pr-rij');
                if (prRij) {
                    // Jaar achter de wedstrijdnaam zodat duidelijk is wanneer
                    // 't PR gereden is — wedstrijdnamen herhalen vaak per jaar.
                    const dStr  = snelste.competition_datum;
                    const jaar  = dStr
                        ? new Date(String(dStr).replace(' ', 'T')).getFullYear()
                        : null;
                    const compLbl = snelste.competition_naam || 'PR';
                    const lbl = jaar && !isNaN(jaar) ? `${compLbl} (${jaar})` : compLbl;
                    prRij.innerHTML = `<div class="spk-detail-rij spk-detail-rij-pr">
                        <span class="spk-detail-lbl">⏱ ${escHtml(lbl)}</span>
                        <span class="spk-detail-val"><b>${escHtml(_spkFmtTijd(snelste._tijdNum))}</b></span>
                    </div>`;
                }
            }
        }
        const toonToggle = hintKey && heeftMatchInHistorie;

        container.classList.remove('jury-laden');
        const filterBar = toonToggle
            ? `<label class="spk-historie-filter">
                  <input type="checkbox" id="spk-historie-filter-check" checked>
                  Alleen <b>${escHtml(_spkAfstandLabel(hintKey))}</b> tonen
               </label>`
            : '';
        container.innerHTML = filterBar + '<div class="spk-historie-wrap-inner"></div>';
        const innerWrap = container.querySelector('.spk-historie-wrap-inner');

        const renderLijst = (filterAan) => {
            const gefilterd = filterAan && hintKey
                ? rijen.filter(r => _spkAfstandKey(r.distance_naam) === hintKey)
                : rijen;
            if (!gefilterd.length) {
                innerWrap.innerHTML = '<div class="spk-historie-leeg">Geen uitslagen voor deze afstand.</div>';
                return;
            }
            const podium  = gefilterd.filter(r => r.rang !== null && r.rang >= 1 && r.rang <= 3);
            const overige = gefilterd.filter(r => !(r.rang !== null && r.rang >= 1 && r.rang <= 3));

            const html = [];
            if (podium.length) {
                html.push('<div class="spk-historie-sectie-titel">🏅 Podium-finishes</div>');
                html.push('<div class="spk-historie-lijst">');
                html.push(podium.map(_spkRenderHistorieRij).join(''));
                html.push('</div>');
            }
            if (overige.length) {
                html.push('<div class="spk-historie-sectie-titel">Overige uitslagen</div>');
                html.push('<div class="spk-historie-lijst">');
                html.push(overige.map(_spkRenderHistorieRij).join(''));
                html.push('</div>');
            }
            innerWrap.innerHTML = html.join('');
        };

        // Default: filter AAN als context beschikbaar én er matches zijn
        renderLijst(toonToggle);

        const cb = container.querySelector('#spk-historie-filter-check');
        if (cb) cb.addEventListener('change', () => renderLijst(cb.checked));
    } catch (e) {
        container.classList.remove('jury-laden');
        container.innerHTML = `<div class="jury-fout">⚠ Historie laden mislukt: ${escHtml(e.message)}</div>`;
    }
}

// ── Bottom-bar: 3 cascade-dropdowns (wedstrijd > afstand > cat) + top-3 ──
// _spk.eerdere = volledig overzicht uit speaker_eerdere_overzicht; eenmalig
// geladen bij scherm-init. Cascade verandert client-side (geen fetch per
// dropdown-keuze) — alleen de top-3-fetch gebeurt server-side.
async function _spkLaadEerdereOverzicht() {
    const wSel = elJ('spk-bb-sel-wedstrijd');
    try {
        const res  = await fetch('?action=speaker_eerdere_overzicht', { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        _spk.eerdere = Array.isArray(data.wedstrijden) ? data.wedstrijden : [];
    } catch (e) {
        wSel.innerHTML = `<option value="">Fout: ${escHtml(e.message)}</option>`;
        wSel.disabled = true;
        return;
    }
    if (!_spk.eerdere.length) {
        wSel.innerHTML = '<option value="">Geen eerdere uitslagen in database</option>';
        wSel.disabled = true;
        return;
    }
    // Vul wedstrijd-dropdown — datum prefix voor leesbaarheid. De huidige
    // wedstrijd (is_huidige) staat bovenaan en wordt gemarkeerd met ★ zodat
    // de speaker de al-verreden afstanden van vandaag makkelijk vindt.
    const opts = ['<option value="">— Kies wedstrijd —</option>'];
    for (const w of _spk.eerdere) {
        let datum = '';
        if (w.comp_starts) {
            const d = new Date(String(w.comp_starts).replace(' ', 'T'));
            if (!isNaN(d.getTime())) {
                datum = d.toLocaleDateString('nl-NL',
                    { day: '2-digit', month: 'short', year: '2-digit' }) + ' · ';
            }
        }
        const label = w.is_huidige
            ? `★ ${datum}${w.comp_naam ?? ''} (deze wedstrijd)`
            : `${datum}${w.comp_naam ?? ''}`;
        opts.push(`<option value="${escHtml(w.comp_id)}">${escHtml(label)}</option>`);
    }
    wSel.innerHTML = opts.join('');
    wSel.disabled = false;
}

// Pretty-label voor afstand-dropdown — voorkom dubbele afstand-naam bij
// single-distance DCs ("Dames Senioren 500m" + "500m" → alleen DC-naam).
function _spkAfstandLabel(dcNaam, distNaam) {
    const d = (dcNaam ?? '').trim();
    const a = (distNaam ?? '').trim();
    if (!a) return d;
    if (d.toLowerCase().endsWith(a.toLowerCase())) return d;
    return `${d} — ${a}`;
}

// Helpers voor cat-set groepering. Cats die in dezelfde DC samen racen
// (bv. DSA+DSJ in één heat) horen als één optie in de dropdown te staan —
// anders zou je per cat hetzelfde race-podium krijgen.
//   key   = stabiele identificatie van een cat-set (sorted, pipe-joined)
//   label = leesbare weergave voor de dropdown ("DSA + DSJ")
function _spkCatSetKey(cats) {
    return [...(cats || [])].sort().join('|');
}
function _spkCatSetLabel(cats) {
    return [...(cats || [])].sort().join(' + ');
}

// 1) Wedstrijd gekozen → cat-dropdown vullen (unieke cat-sets, niet losse cats)
function _spkOnWedstrijdChange(ev) {
    const compId = ev.target.value;
    const cSel = elJ('spk-bb-sel-cat');
    const aSel = elJ('spk-bb-sel-afstand');
    const top3 = elJ('spk-bb-top3');
    top3.innerHTML = '<span class="spk-bb-hint">Kies cat en afstand om de top-3 te zien.</span>';
    aSel.innerHTML = '<option value="">— Afstand —</option>';
    aSel.disabled  = true;

    if (!compId) {
        cSel.innerHTML = '<option value="">— Cat —</option>';
        cSel.disabled  = true;
        return;
    }
    const w = _spk.eerdere.find(x => x.comp_id === compId);
    if (!w) return;

    // Verzamel alle unieke cat-SETs in deze wedstrijd (cats die samen in
    // dezelfde DC zitten = één keuze). Map key→label voor stabiele sortering.
    const sets = new Map();   // key → { label, cats:[] }
    for (const a of w.afstanden) {
        const key = _spkCatSetKey(a.cats);
        if (!key) continue;
        if (!sets.has(key)) sets.set(key, { label: _spkCatSetLabel(a.cats), cats: [...a.cats].sort() });
    }
    const setLijst = [...sets.entries()]
        .map(([key, v]) => ({ key, ...v }))
        .sort((x, y) => x.label.localeCompare(y.label, 'nl', { sensitivity: 'base' }));

    const opts = ['<option value="">— Kies cat —</option>'];
    for (const s of setLijst) {
        opts.push(`<option value="${escHtml(s.key)}">${escHtml(s.label)}</option>`);
    }
    cSel.innerHTML = opts.join('');
    cSel.disabled  = false;

    // Smart preselect — kies de cat-set die de huidige speaker-cat bevat
    if (_spk.cat) {
        const match = setLijst.find(s => s.cats.includes(_spk.cat));
        if (match) {
            cSel.value = match.key;
            _spkOnCatChange({ target: cSel });
        }
    }
}

// 2) Cat-set gekozen → afstand-dropdown vullen (afstanden met exact deze set)
function _spkOnCatChange(ev) {
    const setKey = ev.target.value;
    const aSel = elJ('spk-bb-sel-afstand');
    const top3 = elJ('spk-bb-top3');
    top3.innerHTML = '<span class="spk-bb-hint">Kies afstand om de top-3 te zien.</span>';

    if (!setKey) {
        aSel.innerHTML = '<option value="">— Afstand —</option>';
        aSel.disabled  = true;
        return;
    }
    const compId = elJ('spk-bb-sel-wedstrijd').value;
    const w = _spk.eerdere.find(x => x.comp_id === compId);
    if (!w) return;

    // Alleen afstanden waarvan de cat-set exact matcht (dus DSA-alleen is een
    // andere keuze dan DSA+DSJ samen — verschillende races).
    const relevant = w.afstanden.filter(a => _spkCatSetKey(a.cats) === setKey);
    const opts = ['<option value="">— Kies afstand —</option>'];
    for (const a of relevant) {
        const label = _spkAfstandLabel(a.dc_naam, a.distance_naam);
        // value = dc_id|distance_id
        opts.push(`<option value="${escHtml(a.dc_id)}|${escHtml(a.distance_id ?? '')}">${escHtml(label)}</option>`);
    }
    aSel.innerHTML = opts.join('');
    aSel.disabled  = false;

    // Smart preselect — match op huidige speaker DC-naam (last-word-trick)
    const huidigeDcNaam = (_spk.struktuur?.dcs
        ?.find(d => d.dc_id === _spk.dcId)?.dc_naam) || '';
    if (huidigeDcNaam) {
        const matched = relevant.find(a => {
            const lbl = _spkAfstandLabel(a.dc_naam, a.distance_naam).toLowerCase();
            const tail = huidigeDcNaam.toLowerCase().split(/\s+/).pop();
            return tail && lbl.includes(tail);
        });
        if (matched) {
            aSel.value = matched.dc_id + '|' + (matched.distance_id ?? '');
            _spkOnAfstandChange({ target: aSel });
        }
    }
}

// 3) Afstand gekozen → top-3 ophalen + tonen (cat-set-onafhankelijk: het is
//    altijd het podium van die ÉNE race, ongeacht welke cats erin meededen)
async function _spkOnAfstandChange(ev) {
    const val  = ev.target.value;
    const top3 = elJ('spk-bb-top3');
    if (!val) {
        top3.innerHTML = '<span class="spk-bb-hint">Kies afstand om de top-3 te zien.</span>';
        return;
    }
    const compId = elJ('spk-bb-sel-wedstrijd').value;
    if (!compId) return;
    const [dcId, distId] = val.split('|');
    const setLabel = elJ('spk-bb-sel-cat').selectedOptions[0]?.textContent || '';

    top3.innerHTML = '<span class="spk-bb-hint">Top-3 laden…</span>';
    try {
        // Geen cat-param meer — backend levert het echte race-podium (mix van
        // alle cats die in deze DC samen reden).
        const url = '?action=speaker_eerdere_top3'
                  + '&comp_id='     + encodeURIComponent(compId)
                  + '&dc_id='       + encodeURIComponent(dcId)
                  + '&distance_id=' + encodeURIComponent(distId);
        const res  = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        const lijst = Array.isArray(data.top3) ? data.top3 : [];
        if (!lijst.length) {
            top3.innerHTML = `<span class="spk-bb-hint">Geen podium-uitslag voor ${escHtml(setLabel)} in deze afstand.</span>`;
            return;
        }
        const medailles = { 1: '🥇', 2: '🥈', 3: '🥉' };
        top3.innerHTML = lijst.map(p => {
            const snr = p.startnummer !== null && p.startnummer !== undefined
                ? `<span class="spk-bb-snr">${escHtml(p.startnummer)}</span>` : '';
            // Bij gemixte cat-races (DSA+DSJ) is het nuttig om naast de naam
            // de cat van die rijder te tonen — anders weet de speaker niet of
            // de winnaar een A of J was.
            const cat = p.categorie
                ? `<span class="spk-bb-cat">${escHtml(p.categorie)}</span>` : '';
            // ⚡-badge bij pending: rijder uit historische PDF die nog niet aan
            // een echte KNSB-account gekoppeld is. Detail-modal toont in dat
            // geval minder info (geen club/jaar/etc.) — vandaar zichtbare hint.
            const pendingBadge = p.pending_source
                ? `<span class="spk-bb-pending" title="Nog niet gekoppeld aan KNSB-account">⚡</span>`
                : '';
            // Klikbaar als we een license_key hebben — opent dezelfde detail-
            // modal als een tegel-klik in de deelnemerslijst.
            const lk  = p.person_license || '';
            const tag = lk ? 'button' : 'span';
            const klikbaar = lk ? ' spk-bb-podium-klikbaar' : '';
            const pendingCls = p.pending_source ? ' spk-bb-podium-pending' : '';
            const cls = `spk-bb-podium${klikbaar}${pendingCls}`;
            const dataAttr = lk ? ` data-license="${escHtml(lk)}"` : '';
            return `<${tag} class="${cls}"${dataAttr} type="button">
                        <span class="spk-bb-medaille">${medailles[p.rang] || p.rang}</span>
                        ${snr}
                        ${pendingBadge}
                        <span class="spk-bb-naam">${escHtml(p.naam)}</span>
                        ${cat}
                    </${tag}>`;
        }).join('');
        // Click-handlers koppelen — niet via event-delegation om consistentie
        // met de rest van speaker-handlers te houden.
        top3.querySelectorAll('[data-license]').forEach(btn => {
            btn.addEventListener('click', () => _spkLaadPersoonEnToon(btn.dataset.license));
        });
    } catch (e) {
        top3.innerHTML = `<span class="jury-fout">⚠ ${escHtml(e.message)}</span>`;
    }
}

// Klik op een podium-pill in de bottom-bar → fetch volledige persoonsdata
// en open dezelfde detail-modal als bij tegel-klik in de deelnemerslijst.
// Loaders zijn licht: een korte status in de pill volstaat, modal opent
// pas na succesvolle fetch zodat we geen halflege modal tonen bij fouten.
async function _spkLaadPersoonEnToon(licenseKey) {
    if (!licenseKey) return;
    try {
        const res  = await fetch('?action=speaker_persoon&license_key=' + encodeURIComponent(licenseKey),
                                 { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        if (data.rijder) _spkToonDetail(data.rijder);
    } catch (e) {
        const top3 = elJ('spk-bb-top3');
        if (top3) top3.insertAdjacentHTML('beforeend',
            `<span class="jury-fout">⚠ Rijder laden mislukt: ${escHtml(e.message)}</span>`);
    }
}

function _spkRenderHistorieRij(r) {
    // Datum compact (bv. "23 mei '26"). Bij ontbrekende datum → leeg
    let datumKort = '';
    if (r.competition_datum) {
        const d = new Date(String(r.competition_datum).replace(' ', 'T'));
        if (!isNaN(d.getTime())) {
            datumKort = d.toLocaleDateString('nl-NL',
                { day: '2-digit', month: 'short', year: '2-digit' });
        }
    }
    // Rang-label — speciaal voor podium een medaille
    let rangLbl;
    if (r.rang === 1)       rangLbl = '<span class="spk-hist-rang spk-hist-goud">🥇 1</span>';
    else if (r.rang === 2)  rangLbl = '<span class="spk-hist-rang spk-hist-zilver">🥈 2</span>';
    else if (r.rang === 3)  rangLbl = '<span class="spk-hist-rang spk-hist-brons">🥉 3</span>';
    else if (r.rang !== null) rangLbl = `<span class="spk-hist-rang">${r.rang}</span>`;
    else                    rangLbl = `<span class="spk-hist-rang spk-hist-leeg">—</span>`;

    // Punten bewust weggelaten — speaker leest dit in de overlay aan de baan
    // en heeft niets aan de klassement-punten; alleen rang + wedstrijd-context
    // is relevant voor commentaar.

    // Distance-naam alleen tonen als die er is (historie-import schrijft die
    // wel, klassement-rijen niet — voor klassement zit afstand vaak al in
    // dc_naam zelf bv. "Mannen 1000m"). Voorkomt dubbele info.
    const distSuffix = r.distance_naam
        ? ` · ${escHtml(r.distance_naam)}`
        : '';

    return `<div class="spk-hist-rij">
        ${rangLbl}
        <div class="spk-hist-info">
            <div class="spk-hist-wedstrijd">${escHtml(r.competition_naam ?? '')}</div>
            <div class="spk-hist-meta">${escHtml(datumKort)} · ${escHtml(r.dc_naam ?? '')}${distSuffix}</div>
        </div>
    </div>`;
}

// ════════════════════════════════════════════════════════════════════════════
//   Nationale records — sticky banner boven 'Eerdere uitslag' cascade
// ════════════════════════════════════════════════════════════════════════════

// cat → cat_groep. KNSB: alles t/m JA = junioren, vanaf SJ/SA en master = senioren.
function _spkCatNaarGroep(cat) {
    const c = String(cat || '').toUpperCase();
    const sub = c.slice(1);   // strip H/D-prefix
    if (['P4','P3','P2','P1','KA','JB','JA'].includes(sub)) return 'junioren';
    if (['SJ','SA','SB'].includes(sub)) return 'senioren';
    if (/^[HD]M\d+$/i.test(c)) return 'senioren';   // HM40, DM45 (masters)
    if (/^M\d+$/i.test(c))     return 'senioren';   // M40 (zonder prefix)
    return null;
}
function _spkCatNaarGender(cat) {
    const g = String(cat || '').toUpperCase().charAt(0);
    if (g === 'H' || g === 'M') return 0;   // heren / master (default M)
    if (g === 'D')              return 1;   // dames
    return null;
}

// Tijd-ms → leesbare string (zelfde patroon als historie-rij)
function _spkFmtTijd(ms) {
    if (ms == null) return '—';
    const totSec = Math.floor(ms / 1000);
    const milli  = ms % 1000;
    const h = Math.floor(totSec / 3600);
    const m = Math.floor((totSec % 3600) / 60);
    const s = totSec % 60;
    const ms3 = String(milli).padStart(3, '0');
    if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}.${ms3}`;
    if (m > 0) return `${m}:${String(s).padStart(2,'0')}.${ms3}`;
    return `${s}.${ms3}`;
}

// Datumformatter voor NR-meta: 'D mmm YYYY' (bv "15 jul 2022"). Korter
// dan formatDatum() (die ook weekday geeft) — past in de 1-regel banner.
function _spkFmtRecordDatum(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('nl-NL',
        { day: 'numeric', month: 'short', year: 'numeric' });
}

// Per-afstand type-keuze (baan/weg) — blijft bewaard in localStorage zodat
// een gebruiker bij dezelfde afstand niet steeds opnieuw hoeft te kiezen,
// ook niet als 'ie van cat wisselt. Default 'baan' (meest voorkomend).
function _spkNrType(afstandKey) {
    try { return localStorage.getItem('spk_nr_type_' + afstandKey) || 'baan'; }
    catch { return 'baan'; }
}
function _spkNrTypeZet(afstandKey, type) {
    try { localStorage.setItem('spk_nr_type_' + afstandKey, type); } catch {}
}

async function _spkLaadNationaalRecord() {
    const wrap = elJ('spk-bb-nr');
    if (!wrap) return;
    const dc = _spk.struktuur?.dcs?.find(d => d.dc_id === _spk.dcId);
    if (!dc) { wrap.innerHTML = ''; return; }

    // Record volgt de gekozen afstand (niveau 2) + de representatieve categorie
    // (bij een gemengde DC kan de speaker via de dropdown het andere geslacht kiezen).
    const afstandKey = _spkAfstandKey(_spk.afstand?.naam || '');
    const catGroep   = _spkCatNaarGroep(_spk.cat);
    const gender     = _spkCatNaarGender(_spk.cat);
    if (!afstandKey || !catGroep || gender === null) {
        wrap.innerHTML = '';
        return;
    }
    // Punten/afval hebben geen individuele tijd-records in NL
    if (afstandKey === 'puntenkoers' || afstandKey === 'afvalkoers') {
        wrap.innerHTML = `<div class="spk-bb-nr-leeg">🏅 Geen NR voor ${escHtml(afstandKey)}</div>`;
        return;
    }

    const huidType = _spkNrType(afstandKey);
    const altType  = huidType === 'baan' ? 'weg' : 'baan';

    try {
        const url = '?action=speaker_record'
                  + '&afstand_key=' + encodeURIComponent(afstandKey)
                  + '&cat_groep='   + encodeURIComponent(catGroep)
                  + '&gender='      + encodeURIComponent(gender)
                  + '&type='        + encodeURIComponent(huidType);
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        const records = Array.isArray(data.records) ? data.records : [];

        // Toggle-pill rechts: klik = wissel type voor deze afstand + re-render
        const toggleHtml = `<button type="button" class="spk-bb-nr-toggle"
                                    data-alt="${escHtml(altType)}"
                                    title="Wissel naar ${escHtml(altType)}-record">
                                ${escHtml(huidType.toUpperCase())} ⇄
                            </button>`;
        // Eén-regel layout: titel · tijd · naam · meta (rechts gepushed) · toggle
        let bodyHtml;
        if (!records.length) {
            bodyHtml = `<span class="spk-bb-nr-titel">🏅 NR ${escHtml(catGroep)} ${gender === 0 ? '♂' : '♀'}</span>
                        <span class="spk-bb-nr-leeginline">geen ${escHtml(huidType)}-record bekend</span>`;
        } else {
            // Pak eerste (meestal enige) record voor 1-regel-render.
            const r = records[0];
            const datum = _spkFmtRecordDatum(r.record_datum);
            const meta  = [r.locatie, datum, r.wedstrijd].filter(Boolean).join(' · ');
            bodyHtml = `<span class="spk-bb-nr-titel">🏅 NR ${escHtml(catGroep)} ${gender === 0 ? '♂' : '♀'}</span>
                        <span class="spk-bb-nr-tijd">${escHtml(_spkFmtTijd(r.tijd_ms))}</span>
                        <span class="spk-bb-nr-naam">${escHtml(r.rijder_naam || '')}</span>
                        <span class="spk-bb-nr-meta">${escHtml(meta)}</span>`;
        }
        wrap.innerHTML = `
            <div class="spk-bb-nr-row" title="Klik voor alle 4 varianten">
                ${bodyHtml}
                ${toggleHtml}
            </div>`;

        // Click op de body opent de modal; click op toggle wisselt type.
        // stopPropagation op toggle voorkomt dat 'ie ook de modal opent.
        wrap.querySelector('.spk-bb-nr-row')?.addEventListener('click',
            () => _spkToonNrAlleVarianten(afstandKey, huidType));
        wrap.querySelector('.spk-bb-nr-toggle')?.addEventListener('click', e => {
            e.stopPropagation();
            _spkNrTypeZet(afstandKey, altType);
            _spkLaadNationaalRecord();   // re-render met nieuwe type-keuze
        });
    } catch (e) {
        wrap.innerHTML = `<div class="spk-bb-nr-leeg jury-fout">⚠ ${escHtml(e.message)}</div>`;
    }
}

// Expand-modal: NR-overzicht. Twee filter-modi:
//   - 'afstand' (default): alle 4 varianten (jun/sen × M/V) voor diezelfde
//     afstand als de banner toont — primary use-case van de modal.
//   - 'all': alle records voor de gekozen type (baan/weg). Handig om te zien
//     of de recordhouder van deze afstand ook nog op andere afstanden
//     bovenaan staat — speaker kan dat dan benoemen tijdens commentaar.
// Type (baan/weg) komt mee uit banner-keuze en werkt door in beide filter-modi.
async function _spkToonNrAlleVarianten(afstandKey, type) {
    // Overlay bouwen, daarna fetch/render via _spkNrModalRender. Filter-state
    // op de overlay-DOM zelf opslaan zodat we 'm bij toggle kunnen lezen
    // zonder externe variabele.
    const overlay = document.createElement('div');
    overlay.className = 'spk-detail-overlay';
    overlay.dataset.afstandKey = afstandKey || '';
    overlay.dataset.type       = type       || '';
    overlay.dataset.filter     = 'afstand';   // default: deze afstand
    document.body.appendChild(overlay);

    const sluit = () => overlay.remove();
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    const onKey = e => { if (e.key === 'Escape') { sluit(); document.removeEventListener('keydown', onKey); } };
    document.addEventListener('keydown', onKey);

    // Initial render — als afstandKey leeg is, val terug op 'all' direct.
    if (!afstandKey) overlay.dataset.filter = 'all';
    await _spkNrModalRender(overlay, sluit);
}

// Re-render de inhoud van de open NR-modal (overlay-element). Wordt
// aangeroepen bij eerste open + bij click op de "Afstand/Alle"-toggle.
async function _spkNrModalRender(overlay, sluit) {
    const afstandKey = overlay.dataset.afstandKey;
    const type       = overlay.dataset.type;
    const filter     = overlay.dataset.filter;   // 'afstand' | 'all'

    try {
        const url = '?action=speaker_record'
                  + '&mode=all'
                  + (type ? '&type=' + encodeURIComponent(type) : '')
                  + (filter === 'afstand' && afstandKey
                        ? '&afstand_key=' + encodeURIComponent(afstandKey) : '');
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || data?.error) throw new Error(data?.error || ('HTTP ' + res.status));
        const records = Array.isArray(data.records) ? data.records : [];

        const lblGender = g => g === 0 ? '♂ Heren' : '♀ Dames';
        const lblGroep  = g => g === 'junioren' ? 'Junioren' : 'Senioren';
        // Bij 'all'-mode tonen we extra kolom 'Afstand' zodat duidelijk is
        // welk record bij welke afstand hoort. Bij 'afstand'-mode is dat
        // overbodig (overal dezelfde afstand).
        const toonAfstand = filter === 'all';
        const cols = toonAfstand ? 7 : 6;
        const rijenHtml = records.length
            ? records.map(r => {
                const datum = _spkFmtRecordDatum(r.record_datum);
                const meta  = [r.locatie, datum, r.wedstrijd].filter(Boolean).join(' · ');
                return `<tr>
                    <td>${escHtml(lblGroep(r.cat_groep))}</td>
                    <td>${escHtml(lblGender(r.gender))}</td>
                    ${toonAfstand ? `<td><b>${escHtml(r.afstand_key || '')}</b></td>` : ''}
                    <td>${escHtml(r.type)}</td>
                    <td><b>${escHtml(_spkFmtTijd(r.tijd_ms))}</b></td>
                    <td>${escHtml(r.rijder_naam || '')}</td>
                    <td><small>${escHtml(meta)}</small></td>
                </tr>`;
            }).join('')
            : `<tr><td colspan="${cols}" style="text-align:center;color:#888">Geen records bekend.</td></tr>`;

        // Header: filter-toggle + sluit. Knop label toggelt: bij 'afstand'
        // gefilterd is "Alle afstanden" de actie; bij 'all' is "Deze afstand"
        // de actie (alleen zinvol als we een afstandKey hebben).
        const filterKnopHtml = afstandKey ? `
            <div class="spk-nr-filter">
                <button type="button" class="spk-nr-filter-btn ${filter === 'afstand' ? 'is-active' : ''}"
                        data-filter="afstand">Deze afstand</button>
                <button type="button" class="spk-nr-filter-btn ${filter === 'all' ? 'is-active' : ''}"
                        data-filter="all">Alle afstanden</button>
            </div>` : '';

        const titel = filter === 'all'
            ? `Nationale records ${type ? '(' + escHtml(type) + ')' : ''}`
            : `Nationale records — ${escHtml(afstandKey)}`;

        overlay.innerHTML = `
            <div class="spk-detail-modal spk-nr-modal" role="dialog">
                <div class="spk-detail-kop">
                    <div class="spk-detail-snr">🏅</div>
                    <h2 class="spk-detail-naam">${titel}</h2>
                    <button class="spk-detail-sluit" aria-label="Sluiten">&times;</button>
                </div>
                <div class="spk-detail-body">
                    ${filterKnopHtml}
                    <table class="spk-nr-tabel">
                        <thead><tr>
                            <th>Cat</th><th>Gender</th>
                            ${toonAfstand ? '<th>Afstand</th>' : ''}
                            <th>Type</th><th>Tijd</th><th>Naam</th><th>Locatie / Datum</th>
                        </tr></thead>
                        <tbody>${rijenHtml}</tbody>
                    </table>
                </div>
            </div>`;
        overlay.querySelector('.spk-detail-sluit').addEventListener('click', sluit);
        overlay.querySelectorAll('.spk-nr-filter-btn').forEach(b => {
            b.addEventListener('click', () => {
                overlay.dataset.filter = b.dataset.filter;
                _spkNrModalRender(overlay, sluit);
            });
        });
    } catch (e) {
        overlay.innerHTML = `<div class="spk-detail-modal"><div class="spk-detail-body jury-fout">⚠ ${escHtml(e.message)}</div></div>`;
        console.error('[NR-modal]', e);
    }
}

juryInit();
