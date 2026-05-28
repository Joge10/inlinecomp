// ============================================================
//  InlineComp – Uitslag module
//  Toont per categorie/afstand de uitslag en klassementen.
//  Opbouw spiegelt de startlijsten-module.
// ============================================================

'use strict';

// ── Module-state ──────────────────────────────────────────────────────────────
let _uGroepen      = [];       // groepen (zoals bouwStartlijstGroepen)
let _uActieveCat   = null;     // geselecteerde categorie-groep
let _uActieveDist  = null;     // geselecteerde afstand { id, name }
let _uDistCache    = {};       // dc_id → afstanden[]  (eigen cache naast startlijst)
let _uPrintOpties  = new Map();// Map< catLabel, Map< distId, { distNaam, opties[] } > >

// Multi-day filter state (0 = nog niet gezet). Default-dag bij elke render:
// cache → vandaag-match → dag 1. Cache verloopt bij comp-wissel via
// _uActieveDagCompId. _uTsFetched zorgt dat we het tijdschema (voor
// dcDagMap) maar één keer per comp achter de schermen ophalen.
let _uActieveDag       = 0;
let _uActieveDagCompId = null;
let _uTsFetched        = null;

// DB = UI codes, geen mapping meer nodig
function sanctieLabel(s) { return s ?? ''; }

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

async function uLaadAfstanden(groep) {
    const cKey = groep.dc_id + (groep.is_split ? '|' + groep.dc_name : '');
    if (_uDistCache[cKey]) return _uDistCache[cKey];
    try {
        const splitParam = groep.is_split ? `&split_group=${encodeURIComponent(groep.dc_name)}` : '';
        const res  = await fetch(`api/distances_db.php?dc_id=${encodeURIComponent(groep.dc_id)}${splitParam}`);
        const data = await res.json();
        const afs  = Array.isArray(data) ? data.filter(a => !a.error) : [];
        _uDistCache[cKey] = afs;
        return afs;
    } catch { return []; }
}

// Bulk pre-fetch — vermindert N parallelle distances_db calls bij vulUitslag-
// PrintSelect en kleurAlleTabsAsync tot 1 bulk-call met ?dc_ids=. Split-groep-
// filter wordt client-side nagebootst (zelfde logica als _slBulkLaadAfstanden
// in startlist.js).
async function _uBulkLaadAfstanden(groepen) {
    if (!groepen?.length) return;
    const teLaden = new Set();
    for (const g of groepen) {
        if (!g.dc_id) continue;
        const cKey = g.dc_id + (g.is_split ? '|' + g.dc_name : '');
        if (_uDistCache[cKey]) continue;
        teLaden.add(g.dc_id);
    }
    if (teLaden.size === 0) return;
    try {
        const url = `api/distances_db.php?dc_ids=${[...teLaden].map(encodeURIComponent).join(',')}`;
        const res = await fetch(url);
        if (!res.ok) return;
        const data = await res.json();
        if (!data || typeof data !== 'object' || data.error) return;
        for (const g of groepen) {
            if (!g.dc_id) continue;
            const cKey = g.dc_id + (g.is_split ? '|' + g.dc_name : '');
            if (_uDistCache[cKey]) continue;
            const alle = Array.isArray(data[g.dc_id]) ? data[g.dc_id] : [];
            let afs = alle;
            if (g.is_split) {
                const splitSpec = alle.filter(d => d.target_group === g.dc_name);
                afs = splitSpec.length > 0 ? splitSpec : alle.filter(d => !d.target_group);
            }
            _uDistCache[cKey] = afs;
        }
    } catch { /* silent — fallback naar individuele calls via uLaadAfstanden */ }
}

function uBouwGroepen() {
    // Hergebruik dezelfde logica als startlijsten (vergelijkData is globaal)
    return bouwStartlijstGroepen();
}

// ── Print-select vullen ───────────────────────────────────────────────────────

async function vulUitslagPrintSelect() {
    const catSel  = el('u-print-cat-sel');
    const klasSel = el('u-print-klas-sel');
    const btn     = el('u-btn-print');
    // DOM optioneel: Print-Center gebruikt deze functie ook om _uPrintOpties
    // te vullen zónder Uitslag-pagina te openen.

    _uPrintOpties = new Map();
    if (catSel)  catSel.innerHTML  = '<option value="">— Categorie —</option>';
    if (klasSel) { klasSel.innerHTML = '<option value="">— Kies uitslag —</option>'; klasSel.disabled = true; }
    if (btn) btn.disabled = true;

    // Zorg dat _uGroepen gevuld is (normaal in toonUitslagPagina).
    if (!_uGroepen?.length && typeof uBouwGroepen === 'function') {
        _uGroepen = uBouwGroepen();
    }

    // Bulk pre-fetch zodat de loop hieronder alleen cache-lookups doet.
    await _uBulkLaadAfstanden(_uGroepen);

    // ── Bulk klassement-status (V2) ─────────────────────────────────────
    // V1 deed in de loop hieronder per groep een fetch naar klassement_live.php.
    // Bij grote wedstrijden (NK met 48 groepen) gaf dat 48 PHP-processen tegelijk
    // → iFastNet entry-process-limit. V2: één call naar klassement_status_bulk.php
    // die alleen de status-velden (compleet/vastgelegd per afstand + klassement_
    // vastgelegd per groep) retourneert. Map key = displayNaam (= dc_name of
    // merge_label) zodat we 'm met O(1) kunnen lookupen in de loop.
    let _statusMap = {};
    try {
        const groepenPayload = _uGroepen.map(g => ({
            key:         g.merge_label || g.dc_name,
            dc_ids:      g.dc_ids || [g.dc_id],
            split_group: g.is_split && g.dc_name ? g.dc_name : '',
        }));
        const sRes = await fetch('api/klassement_status_bulk.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ competition_id: huidigCompId, groepen: groepenPayload }),
        });
        if (sRes.ok) _statusMap = await sRes.json() || {};
    } catch { /* stil — loop hieronder valt terug op lege status */ }

    for (const groep of _uGroepen) {
        const afstanden = await uLaadAfstanden(groep);
        const displayNaam = groep.merge_label || groep.dc_name;

        // Status uit bulk-map ophalen (was: per-groep fetch).
        let afstandStatus = {};  // distId → { compleet, vastgelegd }
        let heeftKlassement = false;
        const gStat = _statusMap[displayNaam];
        if (gStat) {
            for (const a of (gStat.afstanden || [])) {
                afstandStatus[a.id] = { compleet: a.compleet, vastgelegd: a.vastgelegd };
            }
            heeftKlassement = !!gStat.klassement_vastgelegd;
        }

        const opties = [];
        // Split-info doorgeven zodat _bouwAfstandPrint/_bouwKlassementInternal
        // klassement_live/uitslag_afstand met split_group/dc_naam kunnen aanroepen.
        const splitInfo = groep.is_split ? { isSplit: true, splitName: groep.dc_name } : {};

        // Per afstand: alleen tonen als er resultaten zijn (compleet of vastgelegd)
        for (const a of afstanden) {
            const st = afstandStatus[a.id];
            if (!st || (!st.compleet && !st.vastgelegd)) continue;
            opties.push({ label: a.name, sleutel: 'afstand',
                          dcId: groep.dc_id, dcIds: groep.dc_ids,
                          dcName: displayNaam, distId: a.id, distNaam: a.name,
                          ...splitInfo });
        }

        // Tussenklassement: alleen als er ≥1 afstand met resultaten is en >1 afstand totaal
        if (opties.length >= 1 && afstanden.length > 1) {
            opties.push({ label: 'Tussenklassement', sleutel: 'tussenklassement',
                          dcId: groep.dc_id, dcIds: groep.dc_ids, dcName: displayNaam,
                          ...splitInfo });
        }
        // Eindklassement: alleen als er resultaten zijn
        if (heeftKlassement) {
            opties.push({ label: 'Eindklassement', sleutel: 'eindklassement',
                          dcId: groep.dc_id, dcIds: groep.dc_ids, dcName: displayNaam,
                          ...splitInfo });
        }

        if (opties.length && !_uPrintOpties.has(displayNaam))
            _uPrintOpties.set(displayNaam, opties);
    }

    if (catSel) {
        for (const naam of _uPrintOpties.keys()) {
            const opt = document.createElement('option');
            opt.value = naam;
            opt.textContent = naam;
            catSel.appendChild(opt);
        }
        if (_uPrintOpties.size === 1) {
            catSel.selectedIndex = 1;
            catSel.dispatchEvent(new Event('change'));
        }
    }
}

// ── Hoofd pagina ──────────────────────────────────────────────────────────────

function toonUitslagPagina() {
    _uDistCache = {};   // cache resetten bij nieuwe pagina-activering

    const header   = el('u-page-header');
    const catTabs  = el('u-cat-tabs');
    const distTabs = el('u-dist-tabs');
    const content  = el('u-cat-content');

    if (!huidigCompId || !isGeimporteerd) {
        if (catTabs)  catTabs.innerHTML  = '';
        if (distTabs) { distTabs.innerHTML = ''; distTabs.style.display = 'none'; }
        _uVerwijderDagTabs();
        if (content)  content.innerHTML  =
            '<div class="status-msg info">Selecteer en importeer eerst een wedstrijd via <strong>Importeer</strong>.</div>';
        if (header)   header.innerHTML   = '';
        return;
    }

    // ── Header ────────────────────────────────────────────────────────────────
    if (header) header.innerHTML = `
        <div class="ts-top">
            <div>
                <div class="ts-comp-naam">${escHtml(huidigComp?.name || '')}</div>
                <div class="ts-comp-meta">${escHtml(formatDatum(huidigComp?.starts || ''))} · ${escHtml(getLocatie(huidigComp || {}))}</div>
            </div>
        </div>`;

    // Reset multi-day state bij wedstrijd-wissel (zelfde patroon als
    // startlijsten). Cached dag blijft binnen één wedstrijd geldig.
    if (_uActieveDagCompId !== huidigCompId) {
        _uActieveDag       = 0;
        _uActieveDagCompId = huidigCompId;
    }

    // Tijdschema is nodig voor multi-day detectie. Als nog niet geladen
    // (gebruiker ging direct naar Uitslag), trigger eenmalig een
    // achtergrond-fetch en re-render zodra hij binnenkomt.
    const tsKlaar = (typeof huidigTijdschema !== 'undefined')
                    && huidigTijdschema?.competition_id === huidigCompId;
    if (!tsKlaar) _uAchtergrondLaadTijdschema();

    // Multi-day analyse via gedeelde tijdschema-helpers (zie tijdschema.js).
    const dagInfo  = tsKlaar ? _tsBouwDagInfo(huidigTijdschema?.blokken ?? []) : null;
    const dcDagMap = tsKlaar ? _tsBouwDcDagMap(huidigTijdschema)               : new Map();

    // Bepaal actieveDag: gecached → vandaag-match → dag 1
    let actieveDag = 1;
    if (dagInfo?.isMultiDag) {
        if (_uActieveDag >= 1 && _uActieveDag <= dagInfo.dagLabels.length) {
            actieveDag = _uActieveDag;
        } else {
            const vandaagStr = new Date().toISOString().substring(0, 10);
            const match      = dagInfo.dagLabels.find(d => d.datum === vandaagStr);
            actieveDag       = match ? match.nr : 1;
            _uActieveDag     = actieveDag;
        }
    }

    // ── Categorie-tabs (rij 1) ─────────────────────────────────────────────
    const groepenAll = uBouwGroepen();
    _uGroepen = groepenAll;   // ongefilterd voor Print-Center (alle dagen)
    const groepen = dagInfo?.isMultiDag
        ? groepenAll.filter(g => {
              const dcIds = g.dc_ids?.length ? g.dc_ids : [g.dc_id];
              return dcIds.some(id => (dcDagMap.get(id) ?? 1) === actieveDag);
          })
        : groepenAll;

    // Multi-day dag-tabs renderen / opruimen vóór de cat-tabs.
    _uRenderDagTabs(dagInfo, actieveDag);

    catTabs.innerHTML = '';
    groepen.forEach((groep, i) => {
        const btn = document.createElement('button');
        btn.className = 'org-tab-btn' + (i === 0 ? ' active' : '');
        const displayNaam = groep.merge_label || groep.dc_name;
        const totaal  = groep.competitors.length;
        const label   = groep.dc_ids.length > 1
            ? `${escHtml(displayNaam)} <span class="tab-badge-merged" title="Samengevoegde categorieën">${groep.dc_ids.length}</span>`
            : escHtml(displayNaam);
        btn.innerHTML = label + ` (${totaal})`;
        btn.addEventListener('click', () => {
            catTabs.querySelectorAll('.org-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _uActieveCat = groep;
            toonUitslagAfstandConfig(groep);
        });
        catTabs.appendChild(btn);
    });

    if (groepen.length) {
        _uActieveCat = groepen[0];
        toonUitslagAfstandConfig(groepen[0]);
    } else {
        // Geen categorieën op deze dag — vriendelijke melding
        _uActieveCat = null;
        distTabs.innerHTML = '';
        distTabs.style.display = 'none';
        content.innerHTML = `<div class="status-msg info">
            Geen categorieën gepland op deze dag.
        </div>`;
    }

    // Vul `_uPrintOpties` op de achtergrond — die wordt gebruikt door
    // Print-Center om de beschikbare uitslagen/klassementen te tonen.
    // Gebruikt _uGroepen (ongefilterd) zodat alle dagen in Print-Center
    // beschikbaar blijven.
    vulUitslagPrintSelect();
}

// ── Multi-day helpers ─────────────────────────────────────────────────────────

// Silent fetch van tijdschema indien nog niet geladen voor deze comp. Wordt
// eenmalig per comp uitgevoerd; bij binnenkomst re-rendert de uitslag-pagina
// zodat dag-tabs verschijnen.
async function _uAchtergrondLaadTijdschema() {
    if (!huidigCompId || _uTsFetched === huidigCompId) return;
    _uTsFetched = huidigCompId;
    try {
        const res  = await fetch(`api/tijdschema.php?competition_id=${encodeURIComponent(huidigCompId)}`);
        if (!res.ok) return;
        const data = await res.json();
        if (data?.error || !data) return;
        if (typeof huidigTijdschema === 'undefined' || !huidigTijdschema
            || huidigTijdschema.competition_id !== huidigCompId) {
            huidigTijdschema  = data;
            if (typeof tijdschemaVersion !== 'undefined') {
                tijdschemaVersion = data?.tijdschema_version ?? 0;
            }
        }
        const pg = document.getElementById('page-uitslag');
        if (pg && pg.classList.contains('active')) toonUitslagPagina();
    } catch { /* silent — multi-day filter is optioneel verbeterend */ }
}

function _uVerwijderDagTabs() {
    document.getElementById('u-dag-tabs')?.remove();
}

function _uRenderDagTabs(dagInfo, actieveDag) {
    _uVerwijderDagTabs();
    if (!dagInfo?.isMultiDag) return;
    const catTabs = el('u-cat-tabs');
    if (!catTabs?.parentNode) return;
    const wrap = document.createElement('div');
    wrap.id        = 'u-dag-tabs';
    wrap.className = 'ts-dag-tabs u-dag-tabs';
    wrap.setAttribute('role', 'tablist');
    wrap.setAttribute('aria-label', 'Wedstrijddag');
    wrap.innerHTML = dagInfo.dagLabels.map(d =>
        `<button class="org-tab-btn ts-dag-tab u-dag-tab${d.nr === actieveDag ? ' active' : ''}"`
        + ` data-dag="${d.nr}" role="tab"`
        + ` aria-selected="${d.nr === actieveDag ? 'true' : 'false'}">${escHtml(d.label)}</button>`
    ).join('');
    catTabs.parentNode.insertBefore(wrap, catTabs);
    wrap.querySelectorAll('.u-dag-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const nieuw = parseInt(btn.dataset.dag) || 1;
            if (nieuw === _uActieveDag) return;
            _uActieveDag = nieuw;
            toonUitslagPagina();
        });
    });
}

// ── Tab-kleuren op basis van status ──────────────────────────────────────────
// Kleuren: standaard → geel (uitslag beschikbaar, niet vastgelegd) → groen (vastgelegd)
async function _uKleurTabs(groep) {
    try {
        const dcParam = groep.dc_ids.map(encodeURIComponent).join(',');
        const splitParam = groep.is_split && groep.dc_name
            ? `&split_group=${encodeURIComponent(groep.dc_name)}` : '';
        const res = await fetch(
            `api/klassement_live.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_ids=${dcParam}${splitParam}&_t=${Date.now()}`
        );
        const data = await res.json();
        if (!data.afstanden) return;

        const distTabs = el('u-dist-tabs');
        if (!distTabs) return;

        // Afstand-tabs kleuren (hergebruik startlijst classes)
        for (const a of data.afstanden) {
            const tab = distTabs.querySelector(`.u-dist-tab[data-dist-id="${a.id}"]`);
            if (!tab) continue;
            tab.classList.remove('tab-deels', 'tab-gereed');
            if (a.vastgelegd)     tab.classList.add('tab-gereed');
            else if (a.compleet) tab.classList.add('tab-deels');
        }

        // Klassement-tab kleuren
        const klasTab = distTabs.querySelector('.u-klas-tab');
        if (klasTab) {
            klasTab.classList.remove('tab-deels', 'tab-gereed');
            if (data.klassement_vastgelegd)            klasTab.classList.add('tab-gereed');
            else if (data.afstanden.some(a => a.vastgelegd)) klasTab.classList.add('tab-deels');
        }
    } catch { /* stil falen — tabs blijven standaard kleur */ }
}

// ── Afstand-tabs per categorie (rij 2) ────────────────────────────────────────

async function toonUitslagAfstandConfig(groep) {
    const distTabs = el('u-dist-tabs');
    const content  = el('u-cat-content');

    content.innerHTML  = '<div class="status-msg loading"><span class="spinner"></span>Afstanden laden…</div>';
    distTabs.innerHTML = '';
    distTabs.style.display = 'none';

    let afstanden;
    try {
        afstanden = await uLaadAfstanden(groep);
    } catch(e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
        return;
    }

    // Info-label bij samenvoegingen
    const displayNaam    = groep.merge_label || groep.dc_name;
    const infoLabelHtml  = groep.dc_ids?.length > 1
        ? `<div class="sl-merge-label">&#8644; Samengevoegd: <strong>${escHtml(groep.dc_name)}</strong></div>`
        : groep.is_split
            ? `<div class="sl-merge-label">&#9986; Gesplitst uit: <strong>${escHtml(
                  vergelijkData.find(c => c.dc_id === groep.dc_id)?.dc_name || groep.dc_id
              )}</strong></div>`
            : '';

    // ── Afstand-tabs ──────────────────────────────────────────────────────────
    afstanden.forEach((a, i) => {
        const btn = document.createElement('button');
        btn.className = 'org-tab-btn u-dist-tab' + (i === 0 ? ' active' : '');
        btn.dataset.distId   = a.id ?? '';
        btn.dataset.distNaam = a.name ?? '';
        btn.textContent      = a.name ?? '—';
        btn.addEventListener('click', () => {
            distTabs.querySelectorAll('.u-dist-tab, .u-klas-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _uActieveDist = a;
            toonUitslagVoorAfstand(groep, a);
        });
        distTabs.appendChild(btn);
    });

    // ── Extra tab: Klassement (altijd helemaal rechts) ────────────────────────
    const klasBtn = document.createElement('button');
    klasBtn.className = 'org-tab-btn u-klas-tab u-klas-tab-rechts';
    klasBtn.textContent = '🏆 Klassement';
    klasBtn.addEventListener('click', () => {
        distTabs.querySelectorAll('.u-dist-tab, .u-klas-tab').forEach(b => b.classList.remove('active'));
        klasBtn.classList.add('active');
        _uActieveDist = null;
        toonUitslagKlassement(groep);
    });
    distTabs.appendChild(klasBtn);

    distTabs.style.display = '';

    // ── Tab-kleuren op basis van status (async) ──────────────────────────────
    _uKleurTabs(groep);

    // ── Inhoud ────────────────────────────────────────────────────────────────
    content.innerHTML = `${infoLabelHtml}<div id="u-dist-content"></div>`;

    if (afstanden.length) {
        _uActieveDist = afstanden[0];
        toonUitslagVoorAfstand(groep, afstanden[0]);
    } else {
        // Geen afstanden → direct naar klassement
        distTabs.querySelectorAll('.u-klas-tab').forEach(b => b.classList.add('active'));
        toonUitslagKlassement(groep);
    }
}

// ── Inhoud: uitslag per afstand ───────────────────────────────────────────────

// Serie-alleen-startvolgorde: staat in tijdschema_cat_config.
// De checkbox in de uitslag-module schrijft rechtstreeks naar die kolom zodat
// de keuze gedeeld is over laptops/gebruikers (geen localStorage nodig).
async function _uSasSet(dcIds, distId, distNaam, value) {
    const res = await fetch('api/uitslag_afstand.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            action:         'set_sas',
            competition_id: huidigCompId,
            dc_ids:         Array.isArray(dcIds) ? dcIds : [dcIds],
            distance_id:    distId  || '',
            distance_naam:  distNaam || '',  // fallback-match als distance_id niet overeenkomt
            value:          value ? 1 : 0,
        }),
    });
    // Probeer altijd eerst de body als JSON te lezen zodat we de server-melding
    // kunnen meenemen bij een foutstatus.
    let data = null;
    try { data = await res.json(); } catch (e) { /* niet-JSON respons */ }

    if (!res.ok) {
        const msg = data?.error
            ? `${res.status}: ${data.error}`
            : `HTTP ${res.status}`;
        throw new Error(msg);
    }
    if (data?.error) throw new Error(data.error);
    return data;
}

async function toonUitslagVoorAfstand(groep, afstand) {
    const content = el('u-dist-content');
    if (!content) return;

    content.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Uitslag laden…</div>';

    try {
        const dcParam    = groep.dc_ids.map(encodeURIComponent).join(',');
        const distParam  = afstand.id   ? `&distance_id=${encodeURIComponent(afstand.id)}`     : '';
        const naamParam  = afstand.name ? `&distance_naam=${encodeURIComponent(afstand.name)}` : '';
        // Bij split-DC: split-naam (= groep.dc_name) meesturen zodat backend
        // alleRondesCompleet PER split kan checken (anders blokkeert een
        // andere onafgeronde split deze bevestig-knop).
        const splitParam = groep.is_split && groep.dc_name
            ? `&dc_naam=${encodeURIComponent(groep.dc_name)}` : '';
        const res  = await fetch(
            `api/uitslag_afstand.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_ids=${dcParam}${distParam}${naamParam}${splitParam}`
        );
        const data = await res.json();

        if (data.error) {
            content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(data.error)}</div>`;
            return;
        }

        // ── Internationaal systeem: cascading elimination ranking ─────────────
        if (data.modus === 'internationaal') {
            // ── Ranking instellingen per ronde ───────────────────────────────
            const rondeLabels = {heats:'Serie', kwartfinale:'Kwartfinale', halve_finale:'Halve finale', finale_a:'Finale'};
            const rondeKeys   = {heats:'heats', kwartfinale:'kwart', halve_finale:'half', finale_a:'finale'};
            const ranking     = data.ranking ?? {};
            const rondes      = data.rondes ?? ['heats', 'finale_a'];
            const afNaam      = data.afstand_naam ?? afstand.name ?? '';
            // Per-categorie ranking: we bewaren de geselecteerde DC via data-dc-id
            // zodat de save naar tijdschema_afstand_config met juiste key gaat.
            const primaryDcId = (groep.dc_ids ?? [])[0] ?? '';
            // Bij lange-afstand-A-finale wordt de sortering door race_type-regels
            // bepaald (rondes/tijd voor inline+afvalkoers, punten/rondes/tijd voor
            // puntenkoers). Geen keuze nodig — toon statisch label i.p.v. dropdown.
            const isLongDistance = data.race_type === 'long_distance';
            // Lange afstanden (inline, puntenkoers, afvalkoers): geen ranking-
            // keuze — niet-doorgestroomde series-rijders worden ex-aequo op
            // heat-positie geklasseerd, finale wordt automatisch op
            // rondes/tijd (of punten/rondes/tijd bij PK) gerankt.
            const isLangeAfstand = ['inline', 'puntenkoers', 'afvalkoers']
                .includes(data.race_subtype);
            let rankHtml = `<div class="u-ranking-details">
                <div class="u-ranking-rij">
                    <span class="u-ranking-afstand">Ranking:</span>`;
            for (const rt of rondes) {
                const key = rondeKeys[rt] ?? rt;
                if (isLangeAfstand) {
                    const auto = (rt === 'finale_a' || rt === 'runner_up')
                        ? (data.race_subtype === 'puntenkoers'
                            ? 'automatisch (punten/rondes/tijd)'
                            : 'automatisch (rondes/tijd)')
                        : 'automatisch (positie ex-aequo)';
                    rankHtml += `<span class="u-ranking-info">${rondeLabels[rt] ?? rt}: <em>${auto}</em></span>`;
                    continue;
                }
                if (isLongDistance && rt === 'finale_a') {
                    rankHtml += `<span class="u-ranking-info">${rondeLabels[rt] ?? rt}: <em>automatisch (rondes/tijd)</em></span>`;
                    continue;
                }
                const val = ranking[key] ?? 'time';
                rankHtml += `<label class="u-ranking-sel-wrap">
                    ${rondeLabels[rt] ?? rt}:
                    <select class="u-ranking-sel"
                            data-afstand="${escHtml(afNaam)}"
                            data-dc-id="${escHtml(primaryDcId)}"
                            data-ronde="${escHtml(key)}">
                        <option value="time" ${val === 'time' ? 'selected' : ''}>Op tijd</option>
                        <option value="position_time" ${val === 'position_time' ? 'selected' : ''}>Positie + tijd</option>
                    </select>
                </label>`;
            }
            rankHtml += `</div></div>`;

            if (!data.resultaat || data.resultaat.length === 0) {
                content.innerHTML = rankHtml + `<div class="status-msg info">Nog geen uitslag beschikbaar voor <strong>${escHtml(afstand.name ?? '—')}</strong>.</div>`;
            } else {
                let html = rankHtml + `<div class="u-afstand-blokken">
                    <div class="u-finale-blok ${data.has_results ? 'u-finale-compleet' : 'u-finale-onvolledig'}">
                        <div class="u-finale-titel">Uitslag${data.has_results ? '' : ' <span class="u-onvolledig-badge">onvolledig</span>'}</div>
                        <table class="u-uitslag-tabel">
                            <thead><tr>
                                <th class="u-col-rang">#</th>
                                <th class="u-col-naam">Naam</th>
                                <th class="u-col-startnr">Nr</th>
                                <th class="u-col-cat">Cat</th>
                                <th class="u-col-ronde">Ronde</th>
                                ${data.heeft_rondes ? '<th class="u-col-rondes">Rnd</th>' : ''}
                                ${data.heeft_pk_punten ? '<th class="u-col-pkpunten">Pnt</th>' : ''}
                                <th class="u-col-tijd">Tijd</th>
                                <th class="u-col-sanctie">Sanctie</th>
                            </tr></thead>
                            <tbody>`;

                for (const r of data.resultaat) {
                    const rangTxt    = r.rang    != null ? r.rang    : '—';
                    const tijdTxt    = r.tijd_ms != null ? msTijd(r.tijd_ms) : '—';
                    const sanctieTxt = sanctieLabel(r.sanctie);
                    const rowClass   = r.rang == null ? 'u-rij-sanctie' : '';
                    html += `<tr class="${rowClass}">
                        <td class="u-col-rang">${rangTxt}</td>
                        <td class="u-col-naam">${escHtml(r.full_name ?? '')}</td>
                        <td class="u-col-startnr">${escHtml(String(r.start_number ?? ''))}</td>
                        <td class="u-col-cat">${escHtml(r.categorie ?? '')}</td>
                        <td class="u-col-ronde">${escHtml(r.ronde_label ?? '')}</td>
                        ${data.heeft_rondes ? `<td class="u-col-rondes">${r.rondes ?? '—'}</td>` : ''}
                        ${data.heeft_pk_punten ? `<td class="u-col-pkpunten">${r.pk_punten ?? '—'}</td>` : ''}
                        <td class="u-col-tijd">${tijdTxt}</td>
                        <td class="u-col-sanctie">${escHtml(sanctieTxt)}</td>
                    </tr>`;
                }

                html += `</tbody></table></div></div>`;
                content.innerHTML = html;
            }

            if (data.has_results) {
                const rankingInfo = {
                    rondes:         data.rondes,
                    current:        data.ranking,
                    defaults:       data.ranking_default,
                    afstandMeters:  data.afstand_meters,
                    afstandNaam:    data.afstand_naam ?? afstand?.name,
                    raceSubtype:    data.race_subtype,
                    isLongDistance: data.race_type === 'long_distance',
                    modus:          data.modus,
                };
                content.prepend(_uMaakAfstandBevestigKnop(groep, afstand, !!data.vastgelegd, data.rondes_compleet !== false, data.rondes_reden || '', rankingInfo));
            }

            // ── Ranking select handlers ──────────────────────────────────────
            content.querySelectorAll('.u-ranking-sel').forEach(sel => {
                sel.addEventListener('change', async () => {
                    try {
                        const res = await fetch(BASE + 'api/tijdschema.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action:         'save_ranking',
                                competition_id: huidigCompId,
                                afstand_naam:   sel.dataset.afstand,
                                dc_id:          sel.dataset.dcId || null,
                                [`${sel.dataset.ronde}_ranking`]: sel.value,
                            }),
                        });
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        // Herlaad uitslag met nieuwe ranking
                        toonUitslagVoorAfstand(groep, afstand);
                    } catch (e) {
                        toonBevestigDialog('Fout bij opslaan ranking: ' + e.message, 'Fout');
                    }
                });
            });
            return;
        }

        // ── Gecombineerde modus: 1 serie + alleen A-finale ────────────────────
        if (data.modus === 'gecombineerd') {
            const sasActief = !!data.serie_alleen_startvolgorde;
            const titelBase = sasActief
                ? 'Uitslag (alleen A-finale telt — serie bepaalt startvolgorde)'
                : 'Gecombineerde uitslag (serie + finale)';

            const sasToggleHtml = `
                <div class="u-sas-toggle">
                    <label>
                        <input type="checkbox" class="u-sas-cb" ${sasActief ? 'checked' : ''}>
                        Serie alleen voor startvolgorde (alleen A-finale telt)
                    </label>
                    <span class="u-sas-hint">Wijziging werkt door in de tijdschema-instelling - Afstandinstellingen - Series -> Alleen startvolgorde</span>
                </div>`;

            if (!data.gecombineerd || data.gecombineerd.length === 0) {
                content.innerHTML = sasToggleHtml + `<div class="status-msg info">Nog geen uitslag beschikbaar voor <strong>${escHtml(afstand.name ?? '—')}</strong>.</div>`;
            } else {
                // Kolomopbouw verschilt per variant:
                //  - sasActief (alleen A-finale telt): toon alleen tijden, geen punten
                //  - normaal: serie-punten + finale-punten + totaal
                const headerCols = sasActief
                    ? `<th class="u-col-serie">Serie-tijd</th>
                       <th class="u-col-finale">Finale-tijd</th>`
                    : `<th class="u-col-serie">Serie</th>
                       <th class="u-col-finale">Finale</th>
                       <th class="u-col-totaal">Totaal</th>`;

                let html = sasToggleHtml + `<div class="u-afstand-blokken">
                    <div class="u-finale-blok u-finale-gecombineerd ${data.has_results ? 'u-finale-compleet' : 'u-finale-onvolledig'}">
                        <div class="u-finale-titel">${titelBase}${data.has_results ? '' : ' <span class="u-onvolledig-badge">onvolledig</span>'}</div>
                        <table class="u-uitslag-tabel">
                            <thead>
                                <tr>
                                    <th class="u-col-rang">#</th>
                                    <th class="u-col-naam">Naam</th>
                                    <th class="u-col-startnr">Nr</th>
                                    <th class="u-col-cat">Cat</th>
                                    ${headerCols}
                                </tr>
                            </thead>
                            <tbody>`;

                for (const r of data.gecombineerd) {
                    const rangTxt      = r.rang           != null ? r.rang           : '—';
                    // alle_sancties bevat per rijder alle sancties uit serie + finale
                    // (bv. serie-DNF + finale-DNS). We tonen ze in de juiste kolom
                    // zodat de UI consistent is met de print-out.
                    const serieSanc  = (r.alle_sancties ?? []).find(s => s.ronde === 'Serie');
                    const finaleSanc = (r.alle_sancties ?? []).find(s => s.ronde === 'Finale');
                    // Cursief/licht alleen bij rijders die NIET in de uitslag staan
                    // (rang ontbreekt). DQ-TF / FS / W1 / W2 / RR met rang zijn
                    // gewone finishers — sanctie wordt elders al getoond.
                    const rowClass = (r.rang == null) ? 'u-rij-sanctie' : '';

                    let dataCols;
                    if (sasActief) {
                        // Alleen tijden — de serie bepaalt slechts startvolgorde
                        const sTijd = r.serie_tijd_ms  != null
                            ? msTijd(r.serie_tijd_ms)
                            : (serieSanc ? escHtml(sanctieLabel(serieSanc.sanctie)) : '—');
                        const fTijd = r.finale_tijd_ms != null ? msTijd(r.finale_tijd_ms) : '—';
                        const sCel  = r.finale_tijd_ms != null
                            ? fTijd
                            : (finaleSanc
                                ? escHtml(sanctieLabel(finaleSanc.sanctie))
                                : (r.sanctie ? escHtml(sanctieLabel(r.sanctie)) : fTijd));
                        dataCols = `
                            <td class="u-col-serie">${sTijd}</td>
                            <td class="u-col-finale"><strong>${sCel}</strong></td>`;
                    } else {
                        const serieTxt = r.serie_rang  != null
                            ? `${r.serie_rang} pt${r.serie_tijd_ms != null ? ' (' + msTijd(r.serie_tijd_ms) + ')' : ''}${serieSanc ? ' · ' + escHtml(sanctieLabel(serieSanc.sanctie)) : ''}`
                            : (serieSanc
                                ? escHtml(sanctieLabel(serieSanc.sanctie))
                                : (r.sanctie ? escHtml(sanctieLabel(r.sanctie)) : '—'));
                        const finaleTxt = r.finale_rang != null
                            ? `${r.finale_rang} pt${r.finale_tijd_ms != null ? ' (' + msTijd(r.finale_tijd_ms) + ')' : ''}${finaleSanc ? ' · ' + escHtml(sanctieLabel(finaleSanc.sanctie)) : ''}`
                            : (finaleSanc
                                ? escHtml(sanctieLabel(finaleSanc.sanctie))
                                : (r.sanctie ? escHtml(sanctieLabel(r.sanctie)) : '—'));
                        const totaalTxt = (r.totaal_punten != null && r.totaal_punten < Number.MAX_SAFE_INTEGER)
                            ? r.totaal_punten
                            : '—';
                        dataCols = `
                            <td class="u-col-serie">${serieTxt}</td>
                            <td class="u-col-finale">${finaleTxt}</td>
                            <td class="u-col-totaal"><strong>${totaalTxt}</strong></td>`;
                    }

                    html += `<tr class="${rowClass}">
                        <td class="u-col-rang">${rangTxt}</td>
                        <td class="u-col-naam">${escHtml(r.full_name ?? '')}</td>
                        <td class="u-col-startnr">${escHtml(String(r.start_number ?? ''))}</td>
                        <td class="u-col-cat">${escHtml(r.categorie ?? '')}</td>
                        ${dataCols}
                    </tr>`;
                }

                html += `</tbody></table></div></div>`;
                content.innerHTML = html;
            }

            if (data.has_results) {
                content.prepend(_uMaakAfstandBevestigKnop(groep, afstand, !!data.vastgelegd, data.rondes_compleet !== false, data.rondes_reden || ''));
            }

            // ── Serie-alleen-startvolgorde toggle ────────────────────────
            const sasCb = content.querySelector('.u-sas-cb');
            if (sasCb) {
                sasCb.addEventListener('change', async () => {
                    sasCb.disabled = true;
                    try {
                        // Hele dc_ids array + afstand_naam: bij samengevoegde
                        // combos krijgen alle betrokken dc's dezelfde instelling;
                        // de afstand_naam dient als fallback-match als distance_id
                        // niet 1-op-1 overeenkomt (bv. bij split-groepen).
                        await _uSasSet(
                            groep.dc_ids,
                            afstand.id   || '',
                            afstand.name || '',
                            sasCb.checked,
                        );
                        toonUitslagVoorAfstand(groep, afstand);
                    } catch (e) {
                        toonBevestigDialog('Fout bij opslaan: ' + e.message, 'Fout');
                        sasCb.checked  = !sasCb.checked;  // rollback UI
                        sasCb.disabled = false;
                    }
                });
            }
            return;
        }

        // ── Normaal: alle finales in één tabel ───────────────────────────────────
        if (!data.finales || data.finales.length === 0) {
            content.innerHTML = `<div class="status-msg info">Nog geen finales gevonden voor <strong>${escHtml(afstand.name ?? '—')}</strong>.</div>`;
            return;
        }

        const alleCompleet = data.finales.every(f => f.compleet);
        const somOnvolledig = data.finales.some(f => !f.compleet);
        const blokClass = alleCompleet ? 'u-finale-compleet' : 'u-finale-onvolledig';

        let html = `<div class="u-afstand-blokken"><div class="u-finale-blok ${blokClass}">`;
        if (somOnvolledig) {
            const onvolledigeLabels = data.finales.filter(f => !f.compleet).map(f => escHtml(f.label)).join(', ');
            html += `<div class="u-finale-titel"><span class="u-onvolledig-badge">onvolledig</span> ${onvolledigeLabels}</div>`;
        }

        html += `<table class="u-uitslag-tabel">
            <thead><tr>
                <th class="u-col-rang">#</th>
                <th class="u-col-naam">Naam</th>
                <th class="u-col-startnr">Nr</th>
                <th class="u-col-cat">Cat</th>
                <th class="u-col-finale-label">Finale</th>
                ${data.heeft_rondes ? '<th class="u-col-rondes">Rnd</th>' : ''}
                ${data.heeft_pk_punten ? '<th class="u-col-pkpunten">Pnt</th>' : ''}
                <th class="u-col-tijd">Tijd</th>
                <th class="u-col-sanctie">Sanctie</th>
            </tr></thead>
            <tbody>`;

        for (const finale of data.finales) {
            for (const r of finale.rijders) {
                const rangTxt    = r.rang    != null ? r.rang    : '—';
                const tijdTxt    = r.tijd_ms != null ? msTijd(r.tijd_ms) : '—';
                const sanctieTxt = sanctieLabel(r.sanctie);
                // Cursief alleen voor rijders zonder rang (= niet in uitslag).
                const rowClass   = (r.rang == null) ? 'u-rij-sanctie' : '';
                html += `<tr class="${rowClass}">
                    <td class="u-col-rang">${rangTxt}</td>
                    <td class="u-col-naam">${escHtml(r.full_name ?? '')}</td>
                    <td class="u-col-startnr">${escHtml(String(r.start_number ?? ''))}</td>
                    <td class="u-col-cat">${escHtml(r.categorie ?? '')}</td>
                    <td class="u-col-finale-label">${escHtml(finale.label)}</td>
                    ${data.heeft_rondes ? `<td class="u-col-rondes">${r.rondes ?? '—'}</td>` : ''}
                    ${data.heeft_pk_punten ? `<td class="u-col-pkpunten">${r.pk_punten ?? '—'}</td>` : ''}
                    <td class="u-col-tijd">${tijdTxt}</td>
                    <td class="u-col-sanctie">${escHtml(sanctieTxt)}</td>
                </tr>`;
            }
        }

        html += `</tbody></table></div></div>`;
        content.innerHTML = html;

        // ── Vastleggen-knop (alleen als uitslag compleet is) ───────────────
        if (data.has_results) {
            content.prepend(_uMaakAfstandBevestigKnop(groep, afstand, !!data.vastgelegd, data.rondes_compleet !== false, data.rondes_reden || ''));
        }

    } catch (e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Vastleggen helper ─────────────────────────────────────────────────────────

// Helper: bouw de DOM voor de "Uitslag bevestigen"-knop, met juiste text/
// stijl/onderschrift afhankelijk van of de afstand al vastgelegd is. Wordt
// gebruikt door alle 3 modi (internationaal / gecombineerd / full-final-B).
// Returns een wrap-div die je met content.prepend() kunt neerzetten.
function _uMaakAfstandBevestigKnop(groep, afstand, alVastgelegd, rondesCompleet = true, rondesReden = '', rankingInfo = null) {
    const wrap = document.createElement('div');
    wrap.className = 'u-vastleg-wrap';
    const btn = document.createElement('button');
    // Niet-compleet wint van vastgelegd: een al vastgelegde uitslag mag
    // pas opnieuw bevestigd worden als ALLE rondes inmiddels compleet
    // zijn (bv. na correctie in Live verwerking is dat altijd zo).
    const blokkeer = !rondesCompleet;
    btn.className = 'btn-primary u-vastleg-btn'
                  + (blokkeer ? ' u-vastleg-btn-blocked' : '')
                  + (!blokkeer && alVastgelegd ? ' u-vastleg-btn-already' : '');
    btn.disabled  = blokkeer;
    btn.innerHTML = blokkeer
        ? '⛔ Wedstrijd nog niet compleet'
        : (alVastgelegd ? '↻ Uitslag opnieuw bevestigen' : '✓ Uitslag bevestigen');
    btn.title     = blokkeer
        ? rondesReden || 'Niet alle heats voor deze afstand zijn klaar'
        : (alVastgelegd
            ? 'Uitslag is al bevestigd — opnieuw bevestigen na correctie in Live verwerking'
            : 'Sla de officiële uitslag van deze afstand op');
    if (!blokkeer) btn.addEventListener('click', () => _uVastleggen(groep, afstand, btn, rankingInfo));
    const desc = document.createElement('div');
    desc.className = 'u-vastleg-beschrijving';
    desc.textContent = blokkeer
        ? `⏳ ${rondesReden || 'Niet alle rondes zijn gereden'} — vastleggen kan zodra de wedstrijd compleet is.`
        : (alVastgelegd
            ? '✓ Uitslag is bevestigd — telt mee voor klassement + zichtbaar in Print-Center'
            : 'Sla de officiële uitslag van deze afstand op');
    wrap.append(btn, desc);
    return wrap;
}

// Bouwt de samenvattings-HTML voor de bevestig-dialog. Toont per ronde de
// gekozen ranking-methode en markeert afwijkingen van de standaard-default
// voor deze afstand. Returns null als er geen keuze-mogelijkheden zijn
// (long-distance / afvalkoers / puntenkoers / full-final / runner_up) —
// dan slaan we de dialog over en gaan we direct door.
function _uBouwRankingSamenvatting(info) {
    if (!info) return null;
    // Geen keuze-mogelijkheden = geen dialog
    if (info.modus === 'full-final') return null;
    if (info.isLongDistance) return null;
    if (['inline', 'puntenkoers', 'afvalkoers'].includes(info.raceSubtype)) return null;
    if (!Array.isArray(info.rondes) || !info.rondes.length) return null;

    const rondeLabels = { heats: 'Series', kwartfinale: 'Kwartfinale', halve_finale: 'Halve finale', finale_a: 'Finale' };
    const rondeKeys   = { heats: 'heats', kwartfinale: 'kwart', halve_finale: 'half', finale_a: 'finale' };
    const valLabel = v => v === 'time' ? 'Op tijd' : 'Positie + tijd';

    let rijen = '';
    let afwijkingen = 0;
    let heeftKeuze = false;
    for (const rt of info.rondes) {
        // runner_up niet hier — heeft eigen vaste afloop (zelfde reden waarom
        // het in de UI ook niet in de ranking-rij staat)
        if (rt === 'runner_up') continue;
        const k = rondeKeys[rt];
        if (!k) continue;
        heeftKeuze = true;
        const cur = info.current?.[k] ?? 'time';
        const def = info.defaults?.[k] ?? 'time';
        const isAfwijking = cur !== def;
        if (isAfwijking) afwijkingen++;
        const markering = isAfwijking
            ? `<span style="color:#b71c1c">⚠ standaard: ${escHtml(valLabel(def))}</span>`
            : `<span style="color:#2a7a2a">✓ standaard</span>`;
        rijen += `<tr>
            <td style="padding:2px 8px"><b>${escHtml(rondeLabels[rt] ?? rt)}</b></td>
            <td style="padding:2px 8px">${escHtml(valLabel(cur))}</td>
            <td style="padding:2px 8px">${markering}</td>
        </tr>`;
    }
    if (!heeftKeuze) return null;

    const afstandTxt = escHtml(info.afstandNaam || '')
        + (info.afstandMeters ? ` (${info.afstandMeters}m)` : '');
    const titel = `Ranking-instellingen — ${afstandTxt}`;
    const waarschuwing = afwijkingen > 0
        ? `<div style="margin-top:10px;padding:8px 10px;background:#fce4e4;border-left:3px solid #b71c1c;border-radius:0 4px 4px 0">
              <b>⚠ ${afwijkingen} afwijking${afwijkingen > 1 ? 'en' : ''}</b> van de standaard voor deze afstand.<br>
              <small>Controleer of dat klopt voordat je bevestigt. Anders: klik Annuleren en pas de ranking-dropdowns aan.</small>
           </div>`
        : `<div style="margin-top:10px;padding:6px 10px;background:#eef7ee;border-left:3px solid #2a7a2a;border-radius:0 4px 4px 0;font-size:.9em;color:#2a7a2a">
              ✓ Alle ranking-keuzes komen overeen met de standaard voor deze afstand.
           </div>`;
    const html = `
        <p style="margin:0 0 8px 0">Controleer de ranking-instellingen voor <b>${afstandTxt}</b>:</p>
        <table style="border-collapse:collapse;margin:6px 0;font-size:.92em">
            <thead><tr style="border-bottom:1px solid #ccc">
                <th style="padding:2px 8px;text-align:left">Ronde</th>
                <th style="padding:2px 8px;text-align:left">Gekozen</th>
                <th style="padding:2px 8px;text-align:left"></th>
            </tr></thead>
            <tbody>${rijen}</tbody>
        </table>
        ${waarschuwing}`;
    return { titel, html, heeftAfwijking: afwijkingen > 0 };
}

// Publiceer/intrek klassement naar /coach + /public. Backend zet
// klassement_config.gepubliceerd_at; coach + public klassement-endpoints
// filteren daarop. Aparte stap zodat operator eerst kan controleren
// voordat het naar de externe apps gaat.
async function _uKlassementPubliceer(groep, btnEl, doPubliceer) {
    const origTekst = btnEl?.innerHTML ?? '';
    if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '⏳ Bezig…'; }
    try {
        const res = await fetch('api/klassement_publiceer.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:         doPubliceer ? 'publiceer' : 'trek_in',
                competition_id: huidigCompId,
                dc_ids:         groep.dc_ids,
            }),
        });
        const data = await res.json();
        if (data.ok) {
            if (btnEl) {
                btnEl.innerHTML = doPubliceer ? '✓ Gepubliceerd' : '✓ Ingetrokken';
                btnEl.classList.add('u-vastleg-btn-ok');
            }
            // Refresh ALTIJD ook als de knop verdween — zo weet UI dat
            // er een nieuwe staat is. Korte vertraging zodat de "✓"-feedback
            // nog even leesbaar is.
            setTimeout(() => toonUitslagKlassement(groep), 1200);
            // Print-Center invalideren (uitslag-dropdown filtert ook op gepubliceerd)
            if (typeof vulUitslagPrintSelect === 'function') vulUitslagPrintSelect();
            if (typeof window.printCenterInvalideerUitslagen === 'function') {
                window.printCenterInvalideerUitslagen();
            }
        } else {
            toonBevestigDialog(data.error ?? data.message ?? 'Fout bij publiceren', 'Fout');
            if (btnEl) { btnEl.innerHTML = origTekst; btnEl.disabled = false; }
        }
    } catch (e) {
        toonBevestigDialog(e.message, 'Fout');
        if (btnEl) { btnEl.innerHTML = origTekst; btnEl.disabled = false; }
    }
}

async function _uVastleggen(groep, afstand, btnEl, rankingInfo = null) {
    // Samenvatting + waarschuwing tonen vóór vastleggen, zodat de operator
    // expliciet ziet welke ranking-keuzes gemaakt zijn en eventuele
    // afwijkingen t.o.v. de standaard-default herkent. Bij geen
    // keuze-mogelijkheden (long-distance / full-final / etc.) is samenv null
    // en gaan we direct door.
    const samenv = _uBouwRankingSamenvatting(rankingInfo);
    if (samenv) {
        const ok = await toonBevestigDialog(
            samenv.html,
            samenv.titel,
            'Bevestigen',
            'Annuleren',
            { bodyIsHtml: true }
        );
        if (!ok) return;
    }

    const origTekst = btnEl?.innerHTML ?? '';
    if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '⏳ Bezig…'; }

    try {
        // Tie-breaker-keuze leeft in klassement_config-tabel; backend leest 'm
        // daar bij vastleggen. Frontend hoeft niets mee te sturen.
        const res = await fetch('api/uitslag_vastleggen.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                competition_id: huidigCompId,
                dc_ids:         groep.dc_ids,
                dc_naam:        groep.merge_label || groep.dc_name,
                distance_id:    afstand?.id ?? '',   // leeg = alle afstanden
            }),
        });
        const data = await res.json();
        if (data.ok) {
            if (btnEl) {
                btnEl.innerHTML = '✓ Bevestigd';
                btnEl.classList.add('u-vastleg-btn-ok');
                // Tab-kleuren bijwerken
                if (_uActieveCat) _uKleurTabs(_uActieveCat);
                setTimeout(() => {
                    btnEl.innerHTML = origTekst;
                    btnEl.classList.remove('u-vastleg-btn-ok');
                    btnEl.disabled = false;
                    // Refresh de juiste view zodat de knop "↻ Opnieuw …"
                    // toont en de operator ziet dat het echt vastgelegd is.
                    // We doen dit pas ná de 3s feedback om die niet meteen
                    // te overschrijven.
                    if (afstand === null && typeof toonUitslagKlassement === 'function') {
                        toonUitslagKlassement(groep);
                    } else if (afstand !== null && typeof toonUitslagVoorAfstand === 'function') {
                        toonUitslagVoorAfstand(groep, afstand);
                    }
                }, 3000);
            }
            // Print-Center cache invalideren + lokale uitslag-dropdown
            // verversen: zojuist vastgelegde uitslag moet zonder pagina-
            // refresh meteen printbaar zijn (zowel vanuit deze pagina als
            // vanuit het Print-Center). vulUitslagPrintSelect rebuilt
            // _uPrintOpties + DOM-dropdown; PC-invalidatie zorgt dat een
            // (heropende) PC-modal de verse opties oppakt.
            if (typeof vulUitslagPrintSelect === 'function') {
                vulUitslagPrintSelect();   // async, geen await nodig
            }
            if (typeof window.printCenterInvalideerUitslagen === 'function') {
                window.printCenterInvalideerUitslagen();
            }
        } else {
            toonBevestigDialog(data.error ?? data.melding ?? 'Fout bij vastleggen', 'Fout');
            if (btnEl) { btnEl.innerHTML = origTekst; btnEl.disabled = false; }
        }
    } catch (e) {
        toonBevestigDialog(e.message, 'Fout');
        if (btnEl) { btnEl.innerHTML = origTekst; btnEl.disabled = false; }
    }
}

// ── Hulp: milliseconden → m:ss.mmm ────────────────────────────────────────────
// Inline-skeeleren hanteert reglementair duizendsten op alle afstanden
// (in tegenstelling tot schaatsen dat met honderdsten werkt).
function msTijd(ms) {
    if (ms == null) return '—';
    const duizendsten = ms % 1000;
    const seconden    = Math.floor(ms / 1000) % 60;
    const minuten     = Math.floor(ms / 60000);
    const s = String(seconden).padStart(2, '0');
    const d = String(duizendsten).padStart(3, '0');
    return minuten > 0 ? `${minuten}:${s}.${d}` : `${s}.${d}`;
}

// ── Inhoud: klassement ───────────────────────────────────────────────────────

// Tie-breaker-keuze wordt opgeslagen in DB (klassement_config), niet meer
// in localStorage — zodat álle operators op verschillende PC's dezelfde
// stand zien en de archief-vastlegging dezelfde regel volgt.
async function _klasTbSchrijf(compId, dcId, val) {
    await fetch('api/klassement_live.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            action:          'set_tiebreaker',
            competition_id:  compId,
            dc_id:           dcId,
            tiebreaker_dist: val,
        }),
    });
}

async function toonUitslagKlassement(groep) {
    const content = el('u-dist-content');
    if (!content) return;

    content.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Klassement laden…</div>';

    try {
        const dcParam = groep.dc_ids.map(encodeURIComponent).join(',');
        const splitParam = groep.is_split && groep.dc_name
            ? `&split_group=${encodeURIComponent(groep.dc_name)}` : '';
        const res  = await fetch(
            `api/klassement_live.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_ids=${dcParam}${splitParam}`
        );
        const data = await res.json();

        if (data.error) {
            content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(data.error)}</div>`;
            return;
        }
        // Print ondersteunt full-final en internationaal systeem
        if (!data.has_results || !data.klassement?.length) {
            content.innerHTML = `<div class="status-msg info">Nog geen resultaten beschikbaar voor het klassement.</div>`;
            return;
        }

        const afstanden   = data.afstanden ?? [];
        const klassement  = data.klassement;
        const displayNaam = groep.merge_label || groep.dc_name;
        const dcNaam      = displayNaam;

        // ── Tabel-header (kleur bij status) ──────────────────────────────
        const alleVastgelegd = afstanden.every(a => a.vastgelegd);
        let thAfst = '';
        for (const a of afstanden) {
            const klsCls = a.vastgelegd ? ' u-klas-dist-vastgelegd'
                         : a.compleet  ? ' u-klas-dist-beschikbaar'
                         :               ' u-klas-dist-onvolledig';
            const statusTxt = a.vastgelegd ? ' (bevestigd)' : a.compleet ? ' (beschikbaar)' : ' (onvolledig)';
            thAfst += `<th class="u-klas-col-punten${klsCls}" title="${escHtml(a.name)}${statusTxt}">${escHtml(a.name)}</th>`;
        }

        // ── Rijen ─────────────────────────────────────────────────────────────
        let tbody = '';
        let uitgeslTussenKop = false;
        for (const r of klassement) {
            // Tussenrij voor uitgesloten rijders
            if (r.uitgesloten && !uitgeslTussenKop) {
                uitgeslTussenKop = true;
                const cols = 4 + afstanden.length + 1;
                tbody += `<tr class="u-klas-uitgesloten-kop"><td colspan="${cols}">Uitgesloten (sanctie / 0 punten)</td></tr>`;
            }
            const rowClass = r.uitgesloten ? 'u-klas-rij-uitgesloten'
                : Object.values(r.afstanden ?? {}).some(d => d.bewerkbaar) ? 'u-klas-rij-sanctie' : '';

            let tdAfst = '';
            for (const a of afstanden) {
                const dp = r.afstanden?.[a.id];
                if (!dp) {
                    tdAfst += `<td class="u-klas-col-punten">—</td>`;
                    continue;
                }
                if (dp.bewerkbaar) {
                    const sanctieLbl = dp.sanctie ? sanctieLabel(dp.sanctie) : '';
                    tdAfst += `<td class="u-klas-col-punten u-klas-bewerkbaar">` +
                        `<input type="number" class="u-klas-punten-inp" ` +
                        `  min="0" step="1" value="${dp.punten || ''}" ` +
                        `  data-lic="${escHtml(r.person_license)}" ` +
                        `  data-dist-id="${escHtml(a.id)}" ` +
                        `  data-dist-naam="${escHtml(a.name)}" ` +
                        `  title="Sanctie (${sanctieLbl}) – punten aanpassen">` +
                        (sanctieLbl ? `<span class="u-klas-sanctie-hint">${escHtml(sanctieLbl)}</span>` : '') +
                        (dp.override ? `<span class="u-klas-override-badge" title="Aangepast">✎</span>` : '') +
                        `</td>`;
                } else {
                    const puntTxt = dp.punten == 0 ? '\u2014' : (dp.punten % 1 === 0 ? dp.punten : dp.punten.toFixed(1));
                    const sanctieInfo = dp.sanctie
                        ? ` <span class="u-klas-sanctie-hint" title="${escHtml(sanctieLabel(dp.sanctie))}">(${escHtml(sanctieLabel(dp.sanctie))})</span>`
                        : '';
                    tdAfst += `<td class="u-klas-col-punten">${puntTxt}${sanctieInfo}</td>`;
                }
            }

            tbody += `<tr class="${rowClass}" data-lic="${escHtml(r.person_license)}">
                <td class="u-klas-col-rang">${r.rang ?? '—'}</td>
                <td class="u-klas-col-naam">${escHtml(r.full_name ?? '')}</td>
                <td class="u-klas-col-snr">${escHtml(String(r.start_number ?? ''))}</td>
                <td class="u-klas-col-cat">${escHtml(r.categorie ?? '')}</td>
                ${tdAfst}
                <td class="u-klas-col-totaal">${r.totaal_punten % 1 === 0 ? r.totaal_punten : r.totaal_punten.toFixed(1)}</td>
            </tr>`;
        }

        // ── Opslaan-balk (alleen als er bewerkbare cellen zijn) ───────────────
        const heeftBewerkbaar = klassement.some(r =>
            Object.values(r.afstanden ?? {}).some(d => d.bewerkbaar)
        );
        const nietVastgelegd = afstanden.filter(a => !a.vastgelegd).map(a => a.name);
        const klasDisabled  = !alleVastgelegd;
        const alVastgelegd  = !!data.klassement_vastgelegd;  // klassement is reeds officieel
        const klasTooltip   = klasDisabled
            ? `Nog niet bevestigd: ${nietVastgelegd.join(', ')}`
            : alVastgelegd
                ? 'Klassement is al vastgelegd — klik om opnieuw vast te leggen (bv. na correctie)'
                : 'Leg het definitieve klassement vast';
        const klasKnopLabel = alVastgelegd ? '↻ Klassement opnieuw vastleggen' : '🏆 Klassement vastleggen';
        const klasKnopExtraCls = alVastgelegd ? ' u-vastleg-btn-already' : '';
        const klasOnderschrift = klasDisabled
            ? `Bevestig eerst: ${escHtml(nietVastgelegd.join(', '))}`
            : alVastgelegd
                ? '✓ Klassement is vastgelegd. Publiceer expliciet om het naar /Coach + /Public te sturen.'
                : 'Alle afstanden zijn bevestigd — klaar om vast te leggen';

        // Publicatie-knop: aparte stap ná vastleggen. Pas zichtbaar
        // wanneer er werkelijk iets te publiceren valt; bij niet-
        // gepubliceerd staat is het de primaire actie (groen), bij
        // gepubliceerd staat een subtiele "trek in" knop.
        const gepubliceerdAt = data.klassement_gepubliceerd_at || null;
        let publiceerBlok = '';
        if (alVastgelegd) {
            if (gepubliceerdAt) {
                // Backend stuurt ISO 8601 met Z (UTC); Date() parseert
                // dat correct, toLocaleString rendert in lokale tijd.
                const _d = new Date(gepubliceerdAt);
                const dt = !isNaN(_d.getTime())
                    ? _d.toLocaleString('nl-NL', { day:'2-digit', month:'2-digit', year:'numeric',
                                                    hour:'2-digit', minute:'2-digit' })
                    : String(gepubliceerdAt).replace('T',' ').substring(0,16);
                publiceerBlok = `<div class="u-klas-publiceer-blok">
                    <button class="btn-secondary u-vastleg-btn u-publiceer-ingetrokken"
                            id="u-klas-btn-publiceer-trek-in"
                            title="Verberg het klassement weer voor /Coach en /Public (bv. om te corrigeren)">↻ Trek publicatie in</button>
                    <div class="u-vastleg-beschrijving">📢 Gepubliceerd op ${escHtml(dt)} — zichtbaar in /Coach + /Public</div>
                </div>`;
            } else {
                publiceerBlok = `<div class="u-klas-publiceer-blok">
                    <button class="btn-primary u-vastleg-btn u-publiceer-knop"
                            id="u-klas-btn-publiceer"
                            title="Maak het klassement zichtbaar in /Coach en /Public">📢 Publiceer naar coach/public</button>
                    <div class="u-vastleg-beschrijving">⚠ Nog niet gepubliceerd — klassement is alleen zichtbaar in deze admin-omgeving</div>
                </div>`;
            }
        }

        const opslaanBalk = `<div class="u-klas-opslaan-balk">
            ${heeftBewerkbaar
                ? `<div class="u-klas-correctie-blok">
                       <button class="btn-secondary" id="u-klas-btn-opslaan">💾 Correcties opslaan</button>
                       <span class="u-klas-opslaan-status" id="u-klas-opslaan-status"></span>
                       <div class="u-vastleg-beschrijving">Sla aangepaste punten op voor gesanctioneerde rijders</div>
                   </div>`
                : ''}
            <div class="u-klas-vastleg-blok">
                <button class="btn-primary u-vastleg-btn${klasKnopExtraCls}" id="u-klas-btn-vastleggen"
                        title="${escHtml(klasTooltip)}" ${klasDisabled ? 'disabled' : ''}>
                    ${klasKnopLabel}
                </button>
                <div class="u-vastleg-beschrijving">${klasOnderschrift}</div>
            </div>
            ${publiceerBlok}
        </div>`;

        // ── Tie-breaker dropdown (alleen bij full-final + ≥2 afstanden) ────
        // Bij internationaal volgen we altijd het reglement. Bij FF kan de
        // operator kiezen tussen de standaard-keten en "alleen afstand X".
        const heeftTbKeuze = data.systeem === 'full-final' && afstanden.length >= 2;
        let tbBalk = '';
        if (heeftTbKeuze) {
            const opts = afstanden.map(a =>
                `<option value="${escHtml(a.id)}"${data.tiebreaker_dist === a.id ? ' selected' : ''}>op basis van: ${escHtml(a.name)}</option>`
            ).join('');
            const stdSel = (data.tiebreaker_dist === 'standaard' || !data.tiebreaker_dist) ? ' selected' : '';
            tbBalk = `<div class="u-klas-tb-balk">
                <label class="u-klas-tb-lbl">Tie-breaker bij gelijke punten:
                    <select id="u-klas-tb-sel" class="inp-sm">
                        <option value="standaard"${stdSel}>Standaard (beste resultaten → laatste afstand, terugvallend)</option>
                        ${opts}
                    </select>
                </label>
                <span class="u-klas-tb-hint" title="Standaard: kijkt eerst naar het beste afzonderlijke resultaat. Bij gelijk → laatste afstand (op planning-volgorde); bij gelijk → voorlaatste; daarvoor; enz. tot een verschil gevonden. Bij 'op basis van': alleen de gekozen afstand telt na totaal-punten — bij gelijk daarop blijven rijders ex-aequo (geen verzonnen uitslag). Kies dit als de planning-volgorde tijdens de wedstrijd is afgeweken van het schema.">ℹ</span>
            </div>`;
        }

        content.innerHTML = `
            <div class="u-klas-wrap">
                <div class="u-klas-titel">${escHtml(dcNaam)} – Klassement</div>
                ${tbBalk}
                ${opslaanBalk}
                <div class="u-klas-tabel-wrap">
                <table class="u-klas-tabel">
                    <thead>
                        <tr>
                            <th class="u-klas-col-rang">#</th>
                            <th class="u-klas-col-naam">Naam</th>
                            <th class="u-klas-col-snr">Snr</th>
                            <th class="u-klas-col-cat">Cat</th>
                            ${thAfst}
                            <th class="u-klas-col-totaal">Totaal</th>
                        </tr>
                    </thead>
                    <tbody>${tbody}</tbody>
                </table>
                </div>
            </div>`;

        // ── Tie-breaker dropdown handler ────────────────────────────────────
        // DB-update + re-render. dc_id van de primary DC (eerste in dc_ids);
        // bij merged-DC zou je strict per primary opslaan — bestaande gedrag
        // van klassement_live.php (die ook altijd primary gebruikt).
        el('u-klas-tb-sel')?.addEventListener('change', async e => {
            const sel = el('u-klas-tb-sel');
            if (sel) sel.disabled = true;
            try {
                await _klasTbSchrijf(huidigCompId, groep.dc_ids[0], e.target.value);
            } catch (err) {
                toonBevestigDialog('Tie-breaker opslaan mislukt: ' + err.message, 'Fout');
            }
            toonUitslagKlassement(groep);
        });

        // ── Live totaal herberekenen + dirty-tracking bij puntenwijziging ─────
        let _klasDirty = false;
        const klasBtn = el('u-klas-btn-vastleggen');

        const updateKlasBtn = () => {
            if (!klasBtn) return;
            if (_klasDirty) {
                klasBtn.disabled = true;
                klasBtn.title = 'Sla eerst de puntencorrecties op';
            } else if (!alleVastgelegd) {
                klasBtn.disabled = true;
                klasBtn.title = `Nog niet bevestigd: ${nietVastgelegd.join(', ')}`;
            } else {
                klasBtn.disabled = false;
                klasBtn.title = 'Leg het definitieve klassement vast';
            }
        };

        content.querySelectorAll('.u-klas-punten-inp').forEach(inp => {
            const origVal = inp.value;
            inp.addEventListener('input', () => {
                // Dirty-tracking
                const wasDirty = _klasDirty;
                _klasDirty = [...content.querySelectorAll('.u-klas-punten-inp')].some(
                    i => i.value !== i.dataset.origVal
                );
                if (_klasDirty !== wasDirty) updateKlasBtn();

                // Live totaal herberekenen
                const rij = inp.closest('tr');
                if (!rij) return;
                let totaal = 0;
                rij.querySelectorAll('.u-klas-punten-inp').forEach(i => {
                    totaal += parseFloat(i.value) || 0;
                });
                rij.querySelectorAll('.u-klas-col-punten:not(.u-klas-bewerkbaar)').forEach(td => {
                    const v = parseFloat(td.textContent);
                    if (!isNaN(v)) totaal += v;
                });
                const totaalTd = rij.querySelector('.u-klas-col-totaal');
                if (totaalTd) totaalTd.textContent = totaal % 1 === 0 ? totaal : totaal.toFixed(1);
            });
            inp.dataset.origVal = origVal;
        });

        // ── Alles vastleggen knop ─────────────────────────────────────────────
        el('u-klas-btn-vastleggen')?.addEventListener('click', async (e) => {
            await _uVastleggen(groep, null, e.currentTarget);
        });

        // ── Publiceer naar /coach + /public ───────────────────────────────────
        // BELANGRIJK: e.currentTarget is null ná een await in een async event
        // handler — daarom de DOM-knop expliciet via id() ophalen.
        el('u-klas-btn-publiceer')?.addEventListener('click', async () => {
            await _uKlassementPubliceer(groep, el('u-klas-btn-publiceer'), true);
        });
        el('u-klas-btn-publiceer-trek-in')?.addEventListener('click', async () => {
            if (!await toonBevestigDialog(
                'Klassement-publicatie intrekken? Het klassement verdwijnt uit /Coach en /Public tot je opnieuw publiceert.',
                'Publicatie intrekken'
            )) return;
            await _uKlassementPubliceer(groep, el('u-klas-btn-publiceer-trek-in'), false);
        });

        // ── Opslaan handler ───────────────────────────────────────────────────
        el('u-klas-btn-opslaan')?.addEventListener('click', async () => {
            const statusEl = el('u-klas-opslaan-status');
            const aanpassingen = [];
            content.querySelectorAll('.u-klas-punten-inp').forEach(inp => {
                aanpassingen.push({
                    person_license: inp.dataset.lic,
                    distance_id:    inp.dataset.distId,
                    distance_naam:  inp.dataset.distNaam,
                    punten:         parseFloat(inp.value) || 0,
                });
            });
            if (!aanpassingen.length) return;

            if (statusEl) statusEl.textContent = '⏳ Opslaan…';
            try {
                const r = await fetch('api/klassement_punten.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        competition_id: huidigCompId,
                        dc_id:          groep.dc_ids[0],
                        dc_naam:        dcNaam,
                        aanpassingen,
                    }),
                });
                const d = await r.json();
                if (d.ok) {
                    // Herlaad het klassement zodat totalen, rangorde en badges kloppen
                    toonUitslagKlassement(groep);
                } else {
                    if (statusEl) statusEl.textContent = `⚠ ${d.error ?? 'Fout'}`;
                }
            } catch (e) {
                if (statusEl) statusEl.textContent = `⚠ ${e.message}`;
            }
        });

    } catch (e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Body-bouwers voor Print-Center ───────────────────────────────────────────
// De directe "Druk af"-knoppen zijn weg; Print-Center gebruikt rechtstreeks
// `bouwKlassementBody()` / `bouwUitslagAfstandBody()` hieronder.

// ── Klassement afdrukken ─────────────────────────────────────────────────────

// Interne body-bouwer voor klassement-print. Returns
// { bodyHtml, cssLinks, extraCss, pageOrientation, title } of null.
// Print-Center gebruikt deze via `bouwKlassementBody()`; de bestaande
// "Druk af"-knop op de uitslag-pagina via `_drukKlassement()`.
async function _bouwKlassementInternal(optData) {
    const esc  = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const comp = huidigComp;
    const datum   = comp?.starts ? formatDatum(comp.starts) : '';
    const locatie = comp ? getLocatie(comp) : '';
    const metaTxt = [datum, locatie].filter(Boolean).join(' \u00b7 ');
    const dcIds   = optData.dcIds ?? [optData.dcId];
    const { orgLogoHtml, baanLogoHtml, footerHtml } = bouwOrgHeaderFooter(esc);

    let data;
    try {
        const splitParam = optData.isSplit && optData.splitName
            ? `&split_group=${encodeURIComponent(optData.splitName)}` : '';
        const res = await fetch(
            `api/klassement_live.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_ids=${dcIds.map(encodeURIComponent).join(',')}${splitParam}`
        );
        data = await res.json();
    } catch (e) { console.warn('[Klassement] Laad-fout:', e); return null; }

    if (data.error) { console.warn('[Klassement] API-error:', data.error); return null; }
    // Klassement ondersteunt alle systemen
    if (!data.has_results || !data.klassement?.length) return null;

    const afstanden  = data.afstanden ?? [];
    const klassement = data.klassement;
    const heeftOnvolledige = afstanden.some(a => !a.compleet);
    const typeLabel  = optData.sleutel === 'tussenklassement' ? 'Tussenklassement' : 'Eindklassement';

    // Kolomheaders voor afstanden
    let thAfst = '';
    for (const a of afstanden) {
        const kls = a.compleet ? '' : ' pr-dist-onvolledig';
        thAfst += `<th class="pr-col-punten${kls}">${esc(a.name)}${a.compleet ? '' : '*'}</th>`;
    }

    // Sub-rang per categorie: bij ≥2 categorieën een eigen kolom per
    // categorie (#DJA, #DJB, ...) met alleen de rang in die kolom.
    const uniekeCats = [...new Set(klassement.map(r => r.categorie).filter(Boolean))].sort();
    const toonCatRang = uniekeCats.length > 1;
    const catTeller = {};

    // Rijen
    // Sanctie-voetnoten: gebruik alle_sancties (alle rondes + afstanden)
    const sanctieNoten = []; // [{ naam, items: [{ronde, afstand, sanctie}] }]
    let tbody = '';
    for (const r of klassement) {
        const rijderSancties = (r.alle_sancties ?? []).map(s => ({
            afstand: s.afstand ?? '', ronde: s.ronde ?? '', sanctie: sanctieLabel(s.sanctie)
        }));
        const heeftSanctie = rijderSancties.length > 0;
        let nootNr = 0;
        if (heeftSanctie) {
            sanctieNoten.push({ naam: r.full_name, items: rijderSancties });
            nootNr = sanctieNoten.length;
        }
        // Cursief/licht alleen bij rijders die NIET in het eindklassement zijn
        // opgenomen (geen rang). Voetnoot (heeftSanctie hierboven) blijft ook
        // bij gewone finishers met sanctie — die info is nuttig voor de jury.
        const rowCls = (r.rang == null) ? ' class="pr-rij-sanctie"' : '';

        let catCellen = '';
        if (toonCatRang) {
            for (const cat of uniekeCats) {
                let txt = '';
                if (r.rang != null && r.categorie === cat) {
                    catTeller[cat] = (catTeller[cat] ?? 0) + 1;
                    txt = String(catTeller[cat]);
                }
                catCellen += `<td class="pr-col-catrang">${txt}</td>`;
            }
        }

        let tdAfst = '';
        for (const a of afstanden) {
            const dp = r.afstanden?.[a.id];
            if (!dp) { tdAfst += `<td class="pr-col-punten">\u2014</td>`; continue; }
            const val = dp.punten == 0 ? '\u2014' : (dp.punten % 1 === 0 ? dp.punten : dp.punten.toFixed(1));
            tdAfst += `<td class="pr-col-punten${dp.sanctie ? ' pr-cel-sanctie' : ''}">${val}</td>`;
        }

        const totVal = r.totaal_punten % 1 === 0 ? r.totaal_punten : r.totaal_punten.toFixed(1);
        const nootMark = nootNr ? ` <sup class="pr-noot-ref">(${nootNr})</sup>` : '';
        // Club: gebruik korte naam indien aanwezig, anders volledig
        const clubTxt = r.club_short || r.club_full || '';
        // Sponsor: alleen tonen als het daadwerkelijk gevuld is
        const sponsorTxt = r.sponsor || '';
        tbody += `<tr${rowCls}>
            <td class="pr-col-rang">${r.rang ?? '\u2014'}</td>
            ${catCellen}
            <td class="pr-col-naam">${esc(r.full_name ?? '')}${nootMark}</td>
            <td class="pr-col-snr">${esc(String(r.start_number ?? ''))}</td>
            <td class="pr-col-cat">${esc(r.categorie ?? '')}</td>
            <td class="pr-col-club">${esc(clubTxt)}</td>
            <td class="pr-col-sponsor">${esc(sponsorTxt)}</td>
            ${tdAfst}
            <td class="pr-col-totaal">${totVal}</td>
        </tr>`;
    }

    // Voetnoten opbouwen
    let voetnotenHtml = '';
    if (sanctieNoten.length) {
        const items = sanctieNoten.map((n, i) =>
            `<div class="pr-noot-item"><strong>(${i + 1})</strong> ${esc(n.naam)}: ${
                n.items.map(s => {
                    const loc = [s.afstand, s.ronde].filter(Boolean).join(' ');
                    return loc ? esc(loc) + ': ' + esc(s.sanctie) : esc(s.sanctie);
                }).join(', ')
            }</div>`
        ).join('');
        voetnotenHtml = `<div class="pr-noten">${items}</div>`;
    }

    const voetnoot = (heeftOnvolledige
        ? `<div class="pr-voetnoot">* ${esc(typeLabel)} \u2013 niet alle afstanden zijn voltooid</div>` : '')
        + voetnotenHtml;

    const extraCss = `
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;margin:.6cm 1cm;color:#111;line-height:1.35}
.pr-header{display:flex;justify-content:space-between;align-items:stretch;
           border-bottom:2px solid #1a3a5c;padding-bottom:.3cm;margin-bottom:.4cm}
.pr-comp{font-size:13pt;font-weight:700}
.pr-meta{font-size:8.5pt;color:#555;margin-top:1mm}
.pr-type{font-size:10pt;font-weight:700;color:#1a3a5c}
table{width:100%;border-collapse:collapse;font-size:8.5pt}
thead{display:table-header-group}
th{background:#dce6f0;color:#1a3a5c;padding:3px 6px;font-size:7.5pt;
   text-align:left;font-weight:600;border-bottom:1px solid #bbb}
td{padding:3px 6px;border-bottom:1px solid #eee}
tr:nth-child(even) td{background:#f8fafc}
.pr-col-rang{width:22px;text-align:center}
.pr-col-catrang{width:26px;text-align:center;font-size:7.5pt;color:#1a3a5c;font-weight:600}
.pr-col-naam{}
.pr-col-snr{width:32px;text-align:right;font-weight:600;color:#1a3a5c}
.pr-col-cat{width:30px;font-size:7.5pt;color:#666}
.pr-col-club{width:40mm;min-width:40mm;max-width:40mm;font-size:7.5pt;color:#456;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pr-col-sponsor{width:60mm;min-width:60mm;max-width:60mm;font-size:7.5pt;color:#666;font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pr-col-punten{width:70px;text-align:center;white-space:nowrap}
.pr-col-totaal{width:50px;text-align:center;font-weight:700}
.pr-dist-onvolledig{font-style:italic;color:#999}
.pr-cel-sanctie{color:#999;font-style:italic}
.pr-sanctie-mark{font-size:6pt;color:#b00}
.pr-rij-sanctie td{color:#888}
.pr-voetnoot{font-size:7.5pt;color:#888;font-style:italic;margin-top:.4cm;
             border-top:1px solid #ddd;padding-top:.2cm}
.pr-noot-ref{font-size:7pt;color:#b00;font-weight:700}
.pr-noten{font-size:7.5pt;color:#555;margin-top:.3cm;border-top:1px solid #ddd;padding-top:.2cm}
.pr-noot-item{margin-bottom:1px}
@media print{body{margin:.5cm .8cm}}
`;
    const bodyHtml = `
<div class="pr-header">
  <div style="flex:1;min-width:0;">
    <div class="pr-comp">${esc(comp?.name ?? '')}</div>
    <div class="pr-meta">${esc(metaTxt)}</div>
    <div class="pr-type" style="margin-top:2mm;">${esc(optData.dcName)} \u2013 ${esc(typeLabel)}</div>
  </div>
  ${baanLogoHtml ? `<div style="flex-shrink:0;">${baanLogoHtml}</div>` : ''}
  ${orgLogoHtml ? `<div style="flex-shrink:0;">${orgLogoHtml}</div>` : ''}
</div>
<table>
  <thead><tr>
    <th class="pr-col-rang">#</th>
    ${toonCatRang ? uniekeCats.map(c => `<th class="pr-col-catrang">#${esc(c)}</th>`).join('') : ''}
    <th class="pr-col-naam">Naam</th>
    <th class="pr-col-snr">Snr</th>
    <th class="pr-col-cat">Cat</th>
    <th class="pr-col-club">Club</th>
    <th class="pr-col-sponsor">Sponsor</th>
    ${thAfst}
    <th class="pr-col-totaal">Totaal</th>
  </tr></thead>
  <tbody>${tbody}</tbody>
</table>
${voetnoot}
${footerHtml}
`;

    return {
        bodyHtml,
        cssLinks:        [],
        extraCss,
        pageOrientation: 'landscape',
        title:           typeLabel + ' – ' + (optData.dcName ?? ''),
        subType:         typeLabel,   // "Tussenklassement" of "Eindklassement"
    };
}

// Publieke body-builder voor Print-Center (wrapper rond _bouwKlassementInternal).
async function bouwKlassementBody(optData) {
    return await _bouwKlassementInternal(optData);
}

// ── Per-afstand uitslag ──────────────────────────────────────────────────────
// Publieke body-builder voor Print-Center — wraps _bouwUitslagAfstandInternal.
async function bouwUitslagAfstandBody(optData) {
    return await _bouwUitslagAfstandInternal(optData);
}

// _drukKlassement en _drukAfstandUitslag (directe print-wrappers) zijn weg;
// Print-Center gebruikt de bouwXxxBody()-functies rechtstreeks.
/* verwijderde directe-print-wrapper voor uitslag per afstand:
async function _drukAfstandUitslag(optData) {
    const data = await _bouwUitslagAfstandInternal(optData);
    if (!data) {
        toonBevestigDialog('Nog geen uitslag beschikbaar.', 'Info');
        return;
    }
    const win = window.open('', '_blank');
    if (!win) { toonBevestigDialog('Pop-up geblokkeerd \u2013 sta pop-ups toe voor deze pagina.', 'Afdrukken'); return; }
    win.document.write(`<!DOCTYPE html><html lang="nl">
<head><meta charset="UTF-8">
<title>${escHtml(data.title)}</title>
<style>@page{size:A4 ${data.pageOrientation};margin:.8cm 1cm}
${data.extraCss}</style></head>
<body>${data.bodyHtml}</body></html>`);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); win.close(); }, 400);
}
*/

// Interne body-bouwer voor uitslag-per-afstand. Ondersteunt de 3 modi:
// internationaal, gecombineerd, en normaal (met finales).
// Returns { bodyHtml, cssLinks, extraCss, pageOrientation, title } of null.
async function _bouwUitslagAfstandInternal(optData) {
    const esc  = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const comp = huidigComp;
    const datum   = comp?.starts ? formatDatum(comp.starts) : '';
    const locatie = comp ? getLocatie(comp) : '';
    const metaTxt = [datum, locatie].filter(Boolean).join(' \u00b7 ');
    const dcIds   = optData.dcIds ?? [optData.dcId];
    // Defaults op `true` zodat Print-Center-aanroepen (zonder deze flags)
    // automatisch rondes/tijden tonen. Expliciete `false` respecteren we.
    const toonRonde = optData.toonRonde ?? true;
    const toonTijd  = optData.toonTijd  ?? true;
    const { orgLogoHtml, baanLogoHtml, footerHtml } = bouwOrgHeaderFooter(esc);

    let data;
    try {
        const dcParam   = dcIds.map(encodeURIComponent).join(',');
        const distParam = optData.distId ? `&distance_id=${encodeURIComponent(optData.distId)}` : '';
        // Bij split-DC dc_naam meesturen — uitslag_afstand.php gebruikt dit
        // voor split-aware alleRondesCompleet en distances-fallback.
        const splitParam = optData.isSplit && optData.splitName
            ? `&dc_naam=${encodeURIComponent(optData.splitName)}` : '';
        const res = await fetch(
            `api/uitslag_afstand.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_ids=${dcParam}${distParam}${splitParam}`
        );
        data = await res.json();
    } catch (e) { console.warn('[Uitslag] Laad-fout:', e); return null; }

    if (data.error) { console.warn('[Uitslag] API-error:', data.error); return null; }

    const baseTitle = 'Uitslag – ' + [optData.dcName, optData.distNaam].filter(Boolean).join(' – ');
    // Vastleggen/klassement ondersteunt alle systemen

    // ── Internationaal systeem ──────────────────────────────────────────────
    if (data.modus === 'internationaal') {
        if (!data.resultaat?.length) return null;

        // Sub-rang per categorie: bij >1 categorie in de tabel een eigen
        // kolom per categorie (#DJA, #DJB, ...). Elke rijder krijgt zijn
        // nummer alleen in de kolom van zijn eigen categorie.
        const uniekeCats = [...new Set(data.resultaat.map(r => r.categorie).filter(Boolean))].sort();
        const toonCatRang = uniekeCats.length > 1;
        const catTeller = {};

        let thExtra = '';
        if (toonRonde) thExtra += '<th class="pr-col-ronde">Ronde</th>';
        if (data.heeft_rondes)    thExtra += '<th class="pr-col-rondes">Rnd</th>';
        if (data.heeft_pk_punten) thExtra += '<th class="pr-col-pkpunten">Pnt</th>';
        if (toonTijd)  thExtra += '<th class="pr-col-tijd">Tijd</th>';

        let tbody = '';
        for (const r of data.resultaat) {
            const alleSancties = (r.alle_sancties ?? [])
                .map(s => `${esc(s.ronde)}:${esc(sanctieLabel(s.sanctie))}`)
                .join(', ');
            // Cursief/licht alleen bij rijders die NIET in de uitslag staan
            // (geen rang). DQ-TF/FS-rijders met rang zijn gewone finishers —
            // de sanctie wordt in de Sanctie-kolom getoond, maar hoeft de
            // rij niet visueel weg te zetten.
            const rowCls = (r.rang == null) ? ' class="pr-rij-sanctie"' : '';
            // Cat-rang per categorie-kolom: alleen de kolom van deze
            // rijder z'n eigen categorie wordt ingevuld, rest blijft leeg.
            let catCellen = '';
            if (toonCatRang) {
                for (const cat of uniekeCats) {
                    let txt = '';
                    if (r.rang != null && r.categorie === cat) {
                        catTeller[cat] = (catTeller[cat] ?? 0) + 1;
                        txt = String(catTeller[cat]);
                    }
                    catCellen += `<td class="pr-col-catrang">${txt}</td>`;
                }
            }
            let tdExtra = '';
            if (toonRonde) tdExtra += `<td class="pr-col-ronde">${esc(r.ronde_label ?? '')}</td>`;
            if (data.heeft_rondes)    tdExtra += `<td class="pr-col-rondes">${r.rondes ?? '\u2014'}</td>`;
            if (data.heeft_pk_punten) tdExtra += `<td class="pr-col-pkpunten">${r.pk_punten ?? '\u2014'}</td>`;
            if (toonTijd)  tdExtra += `<td class="pr-col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : '\u2014'}</td>`;
            tbody += `<tr${rowCls}>
                <td class="pr-col-rang">${r.rang ?? '\u2014'}</td>
                ${catCellen}
                <td class="pr-col-naam">${esc(r.full_name ?? '')}</td>
                <td class="pr-col-snr">${esc(String(r.start_number ?? ''))}</td>
                <td class="pr-col-cat">${esc(r.categorie ?? '')}</td>
                ${tdExtra}
                <td class="pr-col-sanctie">${alleSancties || ''}</td>
            </tr>`;
        }

        const catRangHeaders = toonCatRang
            ? uniekeCats.map(c => `<th class="pr-col-catrang">#${esc(c)}</th>`).join('')
            : '';

        const bodyHtml = _bouwAfstandBody(esc, comp, metaTxt, optData,
            `<th class="pr-col-rang">#</th>
             ${catRangHeaders}
             <th class="pr-col-naam">Naam</th>
             <th class="pr-col-snr">Snr</th>
             <th class="pr-col-cat">Cat</th>
             ${thExtra}
             <th class="pr-col-sanctie">Sanctie</th>`,
            tbody, 'Uitslag', orgLogoHtml, footerHtml, baanLogoHtml);
        return {
            bodyHtml,
            cssLinks:        [],
            extraCss:        _bouwAfstandExtraCss(),
            pageOrientation: 'portrait',
            title:           baseTitle,
            subType:         'Uitslag ' + (optData.distNaam ?? ''),
        };
    }

    // ── Gecombineerde modus ──────────────────────────────────────────────────
    if (data.modus === 'gecombineerd') {
        if (!data.gecombineerd?.length) return null;

        const sasActief = !!data.serie_alleen_startvolgorde;

        // Sub-rang per categorie (zie internationaal-blok voor uitleg)
        const uniekeCats = [...new Set(data.gecombineerd.map(r => r.categorie).filter(Boolean))].sort();
        const toonCatRang = uniekeCats.length > 1;
        const catTeller = {};

        let thExtra = '', thTotaal = '';
        if (sasActief) {
            // Serie alleen startvolgorde: geen punten, alleen tijden (serie + finale)
            thExtra = '<th class="pr-col-tijd">Serie-tijd</th><th class="pr-col-tijd">Finale-tijd</th>';
            thTotaal = ''; // Geen totaal-kolom
        } else {
            if (toonRonde) thExtra += '<th class="pr-col-serie">Serie</th><th class="pr-col-finale">Finale</th>';
            if (toonTijd)  thExtra += '<th class="pr-col-tijd">Tijd serie</th><th class="pr-col-tijd">Tijd finale</th>';
            thTotaal = '<th class="pr-col-totaal">Totaal</th>';
        }

        let tbody = '';
        for (const r of data.gecombineerd) {
            const alleSancties = (r.alle_sancties ?? [])
                .map(s => `${esc(s.ronde)}:${esc(sanctieLabel(s.sanctie))}`)
                .join(', ');
            // Alleen rijders zonder rang (= niet in uitslag) krijgen licht-cursief.
            const rowCls = (r.rang == null) ? ' class="pr-rij-sanctie"' : '';
            let tdExtra = '', tdTotaal = '';

            if (sasActief) {
                const stTxt = r.serie_tijd_ms  != null ? msTijd(r.serie_tijd_ms)  : '\u2014';
                const ftTxt = r.finale_tijd_ms != null ? msTijd(r.finale_tijd_ms)
                           : (r.sanctie ? esc(sanctieLabel(r.sanctie)) : '\u2014');
                tdExtra = `<td class="pr-col-tijd">${stTxt}</td><td class="pr-col-tijd"><strong>${ftTxt}</strong></td>`;
            } else {
                if (toonRonde) {
                    const serieTxt  = r.serie_rang  != null ? `${r.serie_rang} pt`
                                                            : (r.sanctie ? esc(sanctieLabel(r.sanctie)) : '\u2014');
                    const finaleTxt = r.finale_rang != null ? `${r.finale_rang} pt`
                                                            : (r.sanctie ? esc(sanctieLabel(r.sanctie)) : '\u2014');
                    tdExtra += `<td class="pr-col-serie">${serieTxt}</td><td class="pr-col-finale">${finaleTxt}</td>`;
                }
                if (toonTijd) {
                    const stTxt = r.serie_tijd_ms  != null ? msTijd(r.serie_tijd_ms)  : '\u2014';
                    const ftTxt = r.finale_tijd_ms != null ? msTijd(r.finale_tijd_ms) : '\u2014';
                    tdExtra += `<td class="pr-col-tijd">${stTxt}</td><td class="pr-col-tijd">${ftTxt}</td>`;
                }
                const totaalTxt = r.totaal_punten != null ? r.totaal_punten : '\u2014';
                tdTotaal = `<td class="pr-col-totaal">${totaalTxt}</td>`;
            }

            let catCellen = '';
            if (toonCatRang) {
                for (const cat of uniekeCats) {
                    let txt = '';
                    if (r.rang != null && r.categorie === cat) {
                        catTeller[cat] = (catTeller[cat] ?? 0) + 1;
                        txt = String(catTeller[cat]);
                    }
                    catCellen += `<td class="pr-col-catrang">${txt}</td>`;
                }
            }
            tbody += `<tr${rowCls}>
                <td class="pr-col-rang">${r.rang ?? '\u2014'}</td>
                ${catCellen}
                <td class="pr-col-naam">${esc(r.full_name ?? '')}</td>
                <td class="pr-col-snr">${esc(String(r.start_number ?? ''))}</td>
                <td class="pr-col-cat">${esc(r.categorie ?? '')}</td>
                ${tdExtra}
                ${tdTotaal}
                <td class="pr-col-sanctie">${alleSancties || ''}</td>
            </tr>`;
        }

        const pageOrient = (!sasActief && toonRonde && toonTijd) ? 'landscape' : 'portrait';
        const titel = sasActief
            ? 'Uitslag (A-finale bepalend, serie = startvolgorde)'
            : 'Gecombineerd (serie + finale)';
        const catRangHeaders = toonCatRang
            ? uniekeCats.map(c => `<th class="pr-col-catrang">#${esc(c)}</th>`).join('')
            : '';
        const bodyHtml = _bouwAfstandBody(esc, comp, metaTxt, optData,
            `<th class="pr-col-rang">#</th>
             ${catRangHeaders}
             <th class="pr-col-naam">Naam</th>
             <th class="pr-col-snr">Snr</th>
             <th class="pr-col-cat">Cat</th>
             ${thExtra}
             ${thTotaal}
             <th class="pr-col-sanctie">Sanctie</th>`,
            tbody, titel, orgLogoHtml, footerHtml, baanLogoHtml);
        return {
            bodyHtml,
            cssLinks:        [],
            extraCss:        _bouwAfstandExtraCss(),
            pageOrientation: pageOrient,
            title:           baseTitle,
            subType:         'Uitslag ' + (optData.distNaam ?? ''),
        };
    }

    // ── Normaal: finales ─────────────────────────────────────────────────────
    if (!data.finales?.length) return null;

    // Sub-rang per categorie (zie internationaal-blok voor uitleg)
    const alleRijdersNormaal = data.finales.flatMap(f => f.rijders ?? []);
    const uniekeCats = [...new Set(alleRijdersNormaal.map(r => r.categorie).filter(Boolean))].sort();
    const toonCatRang = uniekeCats.length > 1;
    const catTeller = {};

    let thExtra = '';
    if (toonRonde) thExtra += '<th class="pr-col-finale">Finale</th>';
    if (data.heeft_rondes)    thExtra += '<th class="pr-col-rondes">Rnd</th>';
    if (data.heeft_pk_punten) thExtra += '<th class="pr-col-pkpunten">Pnt</th>';
    if (toonTijd)  thExtra += '<th class="pr-col-tijd">Tijd</th>';

    let tbody = '';
    for (const finale of data.finales) {
        for (const r of finale.rijders) {
            // Alle sancties van deze rijder voor deze afstand (serie + finale)
            const alleSancties = (r.alle_sancties ?? [])
                .map(s => `${esc(s.ronde)}:${esc(sanctieLabel(s.sanctie))}`)
                .join(', ');
            // Alleen rijders zonder rang (= niet in uitslag) krijgen licht-cursief.
            const rowCls = (r.rang == null) ? ' class="pr-rij-sanctie"' : '';
            let catCellen = '';
            if (toonCatRang) {
                for (const cat of uniekeCats) {
                    let txt = '';
                    if (r.rang != null && r.categorie === cat) {
                        catTeller[cat] = (catTeller[cat] ?? 0) + 1;
                        txt = String(catTeller[cat]);
                    }
                    catCellen += `<td class="pr-col-catrang">${txt}</td>`;
                }
            }
            let tdExtra = '';
            if (toonRonde) tdExtra += `<td class="pr-col-finale">${esc(finale.label)}</td>`;
            if (data.heeft_rondes)    tdExtra += `<td class="pr-col-rondes">${r.rondes ?? '\u2014'}</td>`;
            if (data.heeft_pk_punten) tdExtra += `<td class="pr-col-pkpunten">${r.pk_punten ?? '\u2014'}</td>`;
            if (toonTijd)  tdExtra += `<td class="pr-col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : '\u2014'}</td>`;
            tbody += `<tr${rowCls}>
                <td class="pr-col-rang">${r.rang ?? '\u2014'}</td>
                ${catCellen}
                <td class="pr-col-naam">${esc(r.full_name ?? '')}</td>
                <td class="pr-col-snr">${esc(String(r.start_number ?? ''))}</td>
                <td class="pr-col-cat">${esc(r.categorie ?? '')}</td>
                ${tdExtra}
                <td class="pr-col-sanctie">${alleSancties || ''}</td>
            </tr>`;
        }
    }

    const pageOrient = (toonRonde && toonTijd) ? 'landscape' : 'portrait';
    const catRangHeaders = toonCatRang
        ? uniekeCats.map(c => `<th class="pr-col-catrang">#${esc(c)}</th>`).join('')
        : '';
    const bodyHtml = _bouwAfstandBody(esc, comp, metaTxt, optData,
        `<th class="pr-col-rang">#</th>
         ${catRangHeaders}
         <th class="pr-col-naam">Naam</th>
         <th class="pr-col-snr">Snr</th>
         <th class="pr-col-cat">Cat</th>
         ${thExtra}
         <th class="pr-col-sanctie">Sanctie</th>`,
        tbody, '', orgLogoHtml, footerHtml, baanLogoHtml);
    return {
        bodyHtml,
        cssLinks:        [],
        extraCss:        _bouwAfstandExtraCss(),
        pageOrientation: pageOrient,
        title:           baseTitle,
        subType:         'Uitslag ' + (optData.distNaam ?? ''),
    };
}

// ── HTML-bouwer voor per-afstand print ───────────────────────────────────────

function _bouwAfstandHtml(esc, comp, metaTxt, optData, pageSize, theadHtml, tbodyHtml, subtitel, orgLogoHtml, footerHtml, baanLogoHtml) {
    return `<!DOCTYPE html><html lang="nl">
<head><meta charset="UTF-8">
<title>Uitslag \u2013 ${esc(optData.dcName)} \u2013 ${esc(optData.distNaam)}</title>
<style>${_bouwAfstandExtraCss()}
@page{size:${pageSize};margin:.8cm 1cm}</style></head>
<body>${_bouwAfstandBody(esc, comp, metaTxt, optData, theadHtml, tbodyHtml, subtitel, orgLogoHtml, footerHtml, baanLogoHtml)}
</body></html>`;
}

// Gesplitste body-variant voor Print-Center gebruik.
function _bouwAfstandBody(esc, comp, metaTxt, optData, theadHtml, tbodyHtml, subtitel, orgLogoHtml, footerHtml, baanLogoHtml) {
    return `
<div class="pr-header">
  <div style="flex:1;min-width:0;">
    <div class="pr-comp">${esc(comp?.name ?? '')}</div>
    <div class="pr-meta">${esc(metaTxt)}</div>
    <div class="pr-type" style="margin-top:2mm;">${esc(optData.dcName)} \u2013 ${esc(optData.distNaam)}</div>
    ${subtitel ? `<div class="pr-subtitel">${esc(subtitel)}</div>` : ''}
  </div>
  ${baanLogoHtml ? `<div style="flex-shrink:0;display:flex;align-items:flex-start;">${baanLogoHtml}</div>` : ''}
  ${orgLogoHtml ? `<div style="flex-shrink:0;display:flex;align-items:flex-start;">${orgLogoHtml}</div>` : ''}
</div>
<table>
  <thead><tr>${theadHtml}</tr></thead>
  <tbody>${tbodyHtml}</tbody>
</table>
${footerHtml || ''}
`;
}

function _bouwAfstandExtraCss() {
    return `
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;margin:.6cm 1cm;color:#111;line-height:1.35}
.pr-header{display:flex;justify-content:space-between;align-items:stretch;
           border-bottom:2px solid #1a3a5c;padding-bottom:.3cm;margin-bottom:.4cm}
.pr-comp{font-size:13pt;font-weight:700}
.pr-meta{font-size:8.5pt;color:#555;margin-top:1mm}
.pr-type{font-size:10pt;font-weight:700;color:#1a3a5c}
.pr-subtitel{font-size:8pt;color:#555;font-style:italic;margin-top:1mm}
table{width:100%;border-collapse:collapse;font-size:8.5pt;table-layout:auto}
thead{display:table-header-group}
th{background:#dce6f0;color:#1a3a5c;padding:3px 6px;font-size:7.5pt;
   text-align:left;font-weight:600;border-bottom:1px solid #bbb;white-space:nowrap}
td{padding:3px 6px;border-bottom:1px solid #eee;white-space:nowrap}
td.pr-col-naam{white-space:normal}
td.pr-col-sanctie{white-space:normal}
tr:nth-child(even) td{background:#f8fafc}
.pr-col-rang{text-align:center}
.pr-col-naam{width:auto}
.pr-col-snr{text-align:right;font-weight:600;color:#1a3a5c}
.pr-col-cat{font-size:7.5pt;color:#666}
.pr-col-catrang{width:28px;text-align:center;font-size:7.5pt;color:#1a3a5c;font-weight:600}
.pr-col-serie,.pr-col-finale{text-align:center}
.pr-col-tijd{text-align:right;font-family:monospace;font-size:8pt}
.pr-col-totaal{text-align:center;font-weight:700}
.pr-col-sanctie{font-size:7.5pt;color:#b00}
.pr-rij-sanctie td{color:#888}
@media print{body{margin:.5cm .8cm}}
`;
}
