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
        <button class="jury-btn jury-btn-link" id="jury-btn-wissel" title="Andere wedstrijd of rol">↻ Wissel</button>
        <button class="jury-btn jury-btn-link" id="jury-btn-uitloggen">⎋ Uitloggen</button>
    `;
    elJ('jury-btn-uitloggen').addEventListener('click', juryUitloggen);
    elJ('jury-btn-wissel').addEventListener('click', toonRolkeuze);
}

function wisTopbarComp() {
    elJ('jury-comp-info').hidden = true;
    elJ('jury-topbar-acties').innerHTML = '';
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
    if (roleId === 'area_of_call') { toonAreaOfCall(r); return; }
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

juryInit();
