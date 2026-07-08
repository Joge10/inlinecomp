/* InlineComp – Tijdschema v2 */

let huidigTijdschema   = null;   // geladen schema of null
let tsAfstandOpen      = null;   // naam van open afstand-panel
let programmaVerouderd = false;  // true als afstand/import gewijzigd na laatste generatie
let tijdschemaVersion  = 0;      // voor optimistic locking bij tijdschema-writes
let _tsPollingInterval = null;   // interval-handle voor auto-poll
let _tsLeesOnly        = false;  // true als huidige gebruiker geen schrijfrechten heeft
// Multi-day: actief dag-tabblad in 'Gegenereerd programma' (1-indexed).
// 0 = nog niet ingesteld → bepaal default uit huidige datum (fallback dag 1).
// Reset bij wissel van competitie/tijdschema; blijft anders staan tussen
// re-renders zodat de operator z'n keuze niet verliest.
let _tsActieveDag      = 0;
// Ingeklapte cat-groepen in de rittenlijst (data-groep-key). Beperkt de UI
// tot kopjes-only zodat groep-verschuiven overzichtelijker is. Blijft tussen
// re-renders staan; reset bij wissel van competitie/tijdschema.
let _tsIngeklapteGroepen = new Set();

// ── Heat-duur hulpfuncties (seconden ↔ "m:ss") ────────────────────────────────

// 150 → "2:30",  120 → "2:00",  null/0 → ''
function secNaarMmSs(sec) {
    const s = parseInt(sec) || 0;
    if (!s) return '';
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${String(r).padStart(2, '0')}`;
}

// "2:30" → 150,  "2" → 120,  "" → null
function mmSsNaarSec(str) {
    const v = String(str ?? '').trim();
    if (!v) return null;
    if (v.includes(':')) {
        const [m, s] = v.split(':').map(n => parseInt(n) || 0);
        return m * 60 + s;
    }
    return parseInt(v) * 60;   // getal zonder ':' = minuten (backwards compat)
}

// ── Labels ────────────────────────────────────────────────────────────────────

const TS_SYSTEEM_LABEL = {
    'full-final':           'Full-Final',
    'internationaal-nieuw': 'Internationaal',
};

const TS_SYSTEEM_INFO = {
    'full-final': {
        samenvatting: 'Iedere rijder rijdt series; indeling in A-, B1-, B2-, Bn-finale op basis van tijd in de series.',
        stappen: [
            'Series (optioneel) — alle rijders rijden (verdeeld over één of meerdere heats) voor plaatsing in een finale.',
            'Finales — de snelste rijders gaan naar de A-finale, de volgende groep naar de B-finale, enzovoort.',
            'Geen uitvalronden — iedere rijder rijdt altijd mee in een finale.',
        ],
        tip: 'Geschikt voor wedstrijden waarbij iedere deelnemer gegarandeerd een finalestart heeft. Voorkeurssysteem voor regionale wedstrijden.',
    },
    'internationaal-nieuw': {
        samenvatting: 'Modern knock-outsysteem: uitval per ronde, geen B-finales maar wel een runner-up optie.',
        stappen: [
            'Series — doorstroom naar volgende ronde: x aantal tijdsnelsten.',
            'Kwartfinale (optioneel) — doorstroom naar halve finale x aantal, verdeling over heat winnaars (Q) en tijdsnelsten (q).',
            'Halve finale (optioneel) — doorstroom naar finale x aantal, verdeling over heat winnaars (Q) en tijdsnelsten (q).',
            'A-finale — met aantal gekwalificeerden uit de voorgaande ronde.',
            'Runner-up (optioneel) — rijders die afvallen na de eerste ronde (series, kwartfinale of halve finale) rijden een aparte runner-up race.',
        ],
        tip: 'Modern knock-outsysteem zonder B-finales. KNSB-format voor de landelijke wedstrijden (met runner-up) en nationale kampioenschappen (zonder runner-up).',
    },
};

const TS_RONDE_LABEL = {
    heats:        'Series',
    kwartfinale:  'Kwartfinale',
    halve_finale: 'Halve finale',
    runner_up:    'Runner-up',
    finale:       'Finale',
    finale_a:     'A-finale',
    finale_b:     'B-finale',
};

const TS_RONDE_KLEUR = {
    heats:        '#0d6efd',
    kwartfinale:  '#6610f2',
    halve_finale: '#fd7e14',
    runner_up:    '#6c757d',
    finale:       '#198754',
    finale_a:     '#198754',
    finale_b:     '#20c997',
};

// ── Runner-up heat verdeling (spiegelt PHP verdeelRunnerUpHeats) ──────────────
// Geeft array van heatgroottes terug [heat1, heat2, …, heatN]
function berekenRunnerUpHeats(uitv, ruMax, ruMin) {
    if (uitv <= 0) return [];
    let n = Math.max(1, Math.ceil(uitv / ruMax));

    // Min-check: merge laatste heat in vorige als die kleiner zou zijn dan ruMin.
    // Alleen actief als ruMin > 0; bij 0 mag de laatste heat klein zijn.
    if (ruMin > 0) {
        while (n > 1) {
            const last = uitv - ruMax * (n - 1);
            if (last < ruMin) { n--; } else { break; }
        }
    }

    if (n === 1) return [uitv];
    // Eerste (n-1) heats krijgen elk PRECIES ruMax (= beste plekken na de
    // gekwalificeerden); laatste heat krijgt de rest.
    const sizes = Array(n - 1).fill(ruMax);
    sizes.push(uitv - ruMax * (n - 1));
    return sizes;
}

// ── Multi-day helper ──────────────────────────────────────────────────────────
// Analyseert de tijdschema-blokken en retourneert dag-informatie voor multi-
// day evenementen (>1 wedstrijdstart-blok). Gebruikt door zowel print-
// functies als renderRittenLijst-loop in de UI.
//
// Retourneert:
//   isMultiDag        : bool
//   dagLabels         : [{ nr, label, datum, volgorde, wsBlokId }, ...]
//                       label format: 'Dag N — vrijdag 7 juni' (lang voor print),
//                       roep call-site .label-truncate aan indien nodig.
//   blokDagMap        : Map<parseInt(blok.id), dagNr>
//   geclaimdVoorWs    : Map<wsBlokId, [blokken in chronologische volgorde]>
//                       blokken die direct vóór een wsstart staan en van type
//                       'inrijden' of 'pauze' zijn. Krijgen tijd ACHTERWAARTS
//                       vanaf wsstart, niet voorwaarts vanaf vorige dag.
//   geclaimdeBlokIds  : Set<parseInt(blok.id)>  voor snelle is-geclaimd-lookup
function _tsBouwDagInfo(blokken) {
    const wsBlokkenSorted = (blokken ?? [])
        .filter(b => b.blok_type === 'wedstrijdstart')
        .sort((a, b) => (parseInt(a.volgorde) || 0) - (parseInt(b.volgorde) || 0));
    const isMultiDag       = wsBlokkenSorted.length > 1;
    const blokDagMap       = new Map();
    const geclaimdVoorWs   = new Map();
    const geclaimdeBlokIds = new Set();

    // dagLabels gebruiken 'long' weekday/month voor print-leesbaarheid
    const dagLabels = wsBlokkenSorted.map((ws, i) => {
        const datum    = ws.datum ? new Date(ws.datum + 'T00:00:00') : null;
        const datumStr = datum
            ? datum.toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long' })
            : '';
        return {
            nr:       i + 1,
            label:    `Dag ${i+1}${datumStr ? ' — ' + datumStr : ''}`,
            datum:    ws.datum || null,
            volgorde: parseInt(ws.volgorde) || 0,
            wsBlokId: parseInt(ws.id),
        };
    });

    if (!isMultiDag) {
        return { isMultiDag, dagLabels, blokDagMap, geclaimdVoorWs, geclaimdeBlokIds };
    }

    // Standaard: dagNr = laatste wsBlok-volgorde <= blok-volgorde
    (blokken ?? []).forEach(b => {
        const vol = parseInt(b.volgorde) || 0;
        let dagNr = 1;
        for (const d of dagLabels) {
            if (d.volgorde <= vol) dagNr = d.nr;
        }
        blokDagMap.set(parseInt(b.id), dagNr);
    });

    // Override: 'inrijden'/'pauze' direct vóór een wsstart horen bij die
    // wsstart-dag (= warm-up). Loop achterwaarts vanaf elke wsstart, stop
    // bij eerste ander blok-type. Verzamel claimed-list voor tijdberekening.
    const blokkenSorted = (blokken ?? []).slice()
        .sort((a, b) => (parseInt(a.volgorde) || 0) - (parseInt(b.volgorde) || 0));
    for (let i = 0; i < blokkenSorted.length; i++) {
        const b = blokkenSorted[i];
        if (b.blok_type !== 'wedstrijdstart') continue;
        const wsId  = parseInt(b.id);
        const dagNr = blokDagMap.get(wsId);
        if (!dagNr) continue;
        const claimedList = [];
        for (let j = i - 1; j >= 0; j--) {
            const vb = blokkenSorted[j];
            if (vb.blok_type === 'inrijden' || vb.blok_type === 'pauze') {
                blokDagMap.set(parseInt(vb.id), dagNr);
                claimedList.unshift(vb); // chronologische volgorde
                geclaimdeBlokIds.add(parseInt(vb.id));
            } else {
                break;
            }
        }
        if (claimedList.length) geclaimdVoorWs.set(wsId, claimedList);
    }

    return { isMultiDag, dagLabels, blokDagMap, geclaimdVoorWs, geclaimdeBlokIds };
}

// Mapping van DC-id naar dag-nummer (eerste dag waarop een rit van die DC
// plaatsvindt). Voor multi-day filtering van teken/deelnemerslijsten.
// Bij single-day krijgen alle DCs dag 1; bij geen tijdschema = lege map.
function _tsBouwDcDagMap(tijdschema) {
    if (!tijdschema) return new Map();
    const blokken = tijdschema.blokken ?? [];
    const ritten  = tijdschema.ritten  ?? [];
    const dagInfo = _tsBouwDagInfo(blokken);
    const dcDagMap = new Map();
    ritten.forEach(r => {
        const dcId = r.dc_id;
        if (!dcId) return;
        const dagNr = dagInfo.isMultiDag
            ? (dagInfo.blokDagMap.get(parseInt(r.blok_id)) ?? 1)
            : 1;
        const huidig = dcDagMap.get(dcId);
        if (huidig === undefined || dagNr < huidig) {
            dcDagMap.set(dcId, dagNr);
        }
    });
    return dcDagMap;
}

// ── Startpunt ─────────────────────────────────────────────────────────────────

function toonTijdschemaPagina() {
    _tsLeesOnly = !magSchrijven('tijdschema');
    const container = el('ts-container');
    if (!huidigCompId) {
        container.innerHTML = '<div class="status-msg info">Selecteer eerst een wedstrijd via <strong>Importeer</strong>.</div>';
        return;
    }
    container.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Tijdschema laden…</div>';
    laadTijdschema();
    // Start auto-poll (elke 30s) voor live-updates van andere gebruikers
    startTsPolling();
}

// ── Laden ─────────────────────────────────────────────────────────────────────

async function laadTijdschema() {
    // Reset multi-day tab-state bij elke laad (= competitie-wissel of refresh).
    // Default-dag wordt opnieuw bepaald op basis van vandaag-datum.
    _tsActieveDag = 0;
    // Ingeklapt-state per groep ook leeg: andere competitie heeft andere groepen.
    _tsIngeklapteGroepen.clear();
    try {
        // Lazy-load vergelijkData als die leeg is — kan voorkomen als operator
        // direct naar Tijdschema-tab navigeert zonder eerst Importeer te bezoeken
        // (bv. na een handmatige aanmaak, of na pagina-refresh).
        if ((!vergelijkData || !vergelijkData.length) && huidigCompId) {
            const isHandmatig = !!(typeof huidigComp !== 'undefined' && huidigComp?.is_handmatig);
            const detailUrl = isHandmatig
                ? 'api/wedstrijd_handmatig.php?action=detail&id=' + encodeURIComponent(huidigCompId)
                : 'api/vergelijk.php?id=' + encodeURIComponent(huidigCompId);
            try {
                const detailRes = await fetch(detailUrl);
                if (detailRes.ok) {
                    const detailData = await detailRes.json();
                    if (!detailData.error) {
                        vergelijkData = detailData.groepen ?? detailData ?? [];
                    }
                }
            } catch (e) { console.warn('vergelijkData lazy-load mislukt', e); }
        }
        const uniekeDcIds = [...new Set((vergelijkData ?? []).map(c => c.dc_id))];
        // Bulk-call voor afstanden ipv N parallelle calls. iFastNet stuurt
        // anders HTTP 508 ('loop detected') zodra er ~6 DCs zijn. Eén
        // gecombineerd request scheelt zowel rate-limit als latency.
        const distancesUrl = uniekeDcIds.length
            ? `api/distances_db.php?dc_ids=${uniekeDcIds.map(encodeURIComponent).join(',')}`
            : null;
        const [schemaRes, distRes] = await Promise.all([
            fetch(`api/tijdschema.php?competition_id=${encodeURIComponent(huidigCompId)}`),
            distancesUrl ? fetch(distancesUrl) : Promise.resolve(null),
        ]);

        const data = await schemaRes.json();
        if (data?.error) throw new Error(data.error);
        huidigTijdschema = data;
        tijdschemaVersion = data?.tijdschema_version ?? 0;

        let distBulk = {};
        if (distRes && distRes.ok) {
            try { distBulk = await distRes.json() || {}; } catch { distBulk = {}; }
        }
        uniekeDcIds.forEach((dcId) => {
            const alle = Array.isArray(distBulk[dcId]) ? distBulk[dcId] : [];
            // Sla ALLE afstanden op inclusief target_group zodat bouwAfstandGroepen()
            // per splitgroep kan filteren. Geen vroeg weggooien van target_group hier.
            const afst = alle.map(d => ({
                id:           d.id,
                name:         d.name,
                value_meters: d.value_meters,
                number:       d.number,
                target_group: d.target_group ?? null,   // ← bewaren voor split-filtering
            }));
            (vergelijkData ?? []).filter(c => c.dc_id === dcId).forEach(c => { c.afstanden = afst; });
        });

        renderTijdschema();
    } catch(e) {
        el('ts-container').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

async function postTs(body) {
    const res  = await fetch('api/tijdschema.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...body, tijdschema_version: tijdschemaVersion }),
    });
    const data = await res.json();
    if (data?.error === 'conflict') {
        toonTsConflictWaarschuwing(data.message);
        throw new Error(data.message || 'conflict');
    }
    if (data?.error) throw new Error(data.error);
    if (data?.tijdschema_version != null) tijdschemaVersion = data.tijdschema_version;
    huidigTijdschema = data;
    renderTijdschema();
    return data;
}

// ── Hoofd-render ──────────────────────────────────────────────────────────────

function renderTijdschema() {
    _tsLeesOnly = !magSchrijven('tijdschema');
    const container = el('ts-container');
    const schema    = huidigTijdschema;
    const comp      = huidigComp;

    let html = `<div class="ts-pagina">
        <div class="ts-top">
            <div>
                <div class="ts-comp-naam">${escHtml(comp?.name || '')}</div>
                <div class="ts-comp-meta">${escHtml(formatDatum(comp?.starts || ''))} · ${escHtml(getLocatie(comp || {}))}</div>
            </div>`;

    if (!schema) {
        html += `</div>
            <div class="ts-leeg">
                <p>Nog geen tijdschema voor deze wedstrijd.</p>
                <button class="btn-primary" id="ts-btn-init">Tijdschema aanmaken</button>
            </div></div>`;
        container.innerHTML = html;
        el('ts-btn-init').addEventListener('click', async () => {
            try { await postTs({ action: 'init', competition_id: huidigCompId }); }
            catch(e) { toonBevestigDialog(e.message, 'Fout'); }
        });
        return;
    }

    html += `</div>`; // ts-top

    const afstandGroepen = bouwAfstandGroepen();

    // ── Sectie 1: Systeem ─────────────────────────────────────────────────────
    html += renderSysteemBalk(schema);

    // ── Sectie 2: Afstandsinstellingen ────────────────────────────────────────
    html += `<div class="ts-sectie">
        <div class="ts-sectie-titel">Afstandsinstellingen</div>`;
    if (schema.heeft_loting) {
        html += `<div class="status-msg warn" style="margin-bottom:.5rem">🔒 Niet bewerkbaar zolang er startlijsten zijn. Wis eerst alle lotingen in de module <strong>Startlijsten</strong>.</div>`;
    }
    if (!afstandGroepen.length) {
        html += `<div class="status-msg info">Geen categorieën geladen. Importeer eerst de wedstrijd.</div>`;
    } else {
        afstandGroepen.forEach(afstand => {
            html += renderAfstandKaart(afstand, schema);
        });
    }
    html += `</div>`;

    // ── Sectie 3: Programma-volgorde (incl. Genereer-knop) ────────────────────
    if (schema.blokken?.length) {
        html += renderBlokken(schema, afstandGroepen);
    }

    // ── Sectie 4: Gegenereerd programma ───────────────────────────────────────
    if (schema.ritten?.length) {
        const gegOp    = schema.gegenereerd_op ? new Date(schema.gegenereerd_op.replace(' ', 'T') + 'Z') : null;
        const gegLabel = gegOp ? gegOp.toLocaleString('nl-NL', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '';
        const oudWarn  = programmaVerouderd
            ? `<span class="ts-prog-oud-warn" title="Afstandsinstellingen zijn gewijzigd – genereer opnieuw">⚠ mogelijk verouderd</span>`
            : '';
        html += `<div class="ts-sectie" id="ts-sectie-ritten">
            <div class="ts-sectie-titel">Gegenereerd programma
                <span class="ts-ritten-teller">${schema.ritten.length} ritten</span>
                ${gegLabel ? `<span class="ts-gegenereerd-op" title="Tijdstip laatste generatie">🕐 ${escHtml(gegLabel)}</span>` : ''}
                ${oudWarn}
            </div>`;
        html += renderRittenLijst(schema.ritten, schema.blokken);
        html += `</div>`;
    }

    html += `</div>`; // ts-pagina
    container.innerHTML = html;

    // Heropen afstand-panel dat open was
    if (tsAfstandOpen) {
        const panel = document.getElementById(`ts-panel-${tsAfstandOpen.replace(/[^a-z0-9]/gi, '_')}`);
        if (panel) panel.style.display = '';
    }

    bindTsEvents(afstandGroepen);

    // Lees-alleen modus: schrijf-elementen disablen na render
    if (_tsLeesOnly) {
        toonLeesAlleenBanner(container);
        pasSchrijfLockToe(container);
    } else if (huidigTijdschema?.heeft_loting) {
        // Alleen de afstandsinstellingen-formulieren vergrendelen
        container.querySelectorAll('.ts-panel-form').forEach(form => pasSchrijfLockToe(form));
    }
}

// ── Systeem-balk ──────────────────────────────────────────────────────────────

function renderSysteemBalk(schema) {
    const sel = Object.entries(TS_SYSTEEM_LABEL).map(([v, l]) =>
        `<option value="${v}" ${schema.systeem === v ? 'selected' : ''}>${l}</option>`
    ).join('');
    return `<div class="ts-sectie ts-systeem-balk">
        <div class="ts-systeem-rij">
            <label class="ts-systeem-lbl">Competitiesysteem
                <select class="inp ts-systeem-sel" id="ts-systeem-sel">${sel}</select>
            </label>
            <button class="btn-secondary ts-btn-sm" id="ts-btn-systeem-save" disabled>Opslaan</button>
            <span class="ts-systeem-actief-badge" id="ts-systeem-actief-badge">
                ✔ Actief: <strong>${escHtml(TS_SYSTEEM_LABEL[schema.systeem] ?? schema.systeem)}</strong>
            </span>
            <input type="hidden" id="ts-schema-id" value="${schema.id}">
        </div>
        <div id="ts-systeem-waarschuwing" class="ts-systeem-waarschuwing" style="display:none">
            <span class="ts-warn-icoon">⚠</span>
            <span class="ts-warn-tekst">Wisselen van systeem verwijdert alle afstandsinstellingen, programma-blokken en het gegenereerde programma. Weet je het zeker?</span>
            <button class="btn-danger ts-btn-sm" id="ts-btn-systeem-bevestig">Ja, wissel systeem</button>
            <button class="ts-btn-annuleer ts-btn-sm" id="ts-btn-systeem-annuleer">Annuleer</button>
        </div>
        <div id="ts-systeem-uitleg">${renderSysteemUitleg(schema.systeem)}</div>
    </div>`;
}

function renderSysteemUitleg(systeem) {
    const info = TS_SYSTEEM_INFO[systeem];
    if (!info) return '';
    const stappen = info.stappen.map(s => `<li>${escHtml(s)}</li>`).join('');
    return `
    <div class="ts-systeem-uitleg-blok">
        <div class="ts-uitleg-samenvatting">${escHtml(info.samenvatting)}</div>
        <ol class="ts-uitleg-stappen">${stappen}</ol>
        <div class="ts-uitleg-tip">💡 ${escHtml(info.tip)}</div>
    </div>`;
}

// ── Afstand-kaart ─────────────────────────────────────────────────────────────

function renderAfstandKaart(afstand, schema) {
    const cfg         = vindAfstandConfig(schema, afstand.naam);
    const catConfigMap = maakCatConfigMap(schema);
    const isOpen      = tsAfstandOpen === afstand.naam;

    const isUuid = s => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s);
    const catNamen = afstand.cats.map(c =>
        isUuid(c.dc_naam) ? `<span class="ts-cat-uuid" title="${escHtml(c.dc_naam)}">[naamloos]</span>` : escHtml(c.dc_naam)
    ).join(' · ');
    const heeftCatConfig = afstand.cats.some(c => catConfigMap[c.dc_id + '|' + (c.distance_id ?? '')]);
    const samenvatting = heeftCatConfig
        ? renderAfstandSamenvatting(afstand.cats, catConfigMap)
        : '<em class="ts-geen-config">Nog niet ingesteld</em>';

    const panelId = `ts-panel-${afstand.naam.replace(/[^a-z0-9]/gi, '_')}`;

    return `<div class="ts-afstand-kaart" data-naam="${escHtml(afstand.naam)}">
        <div class="ts-kaart-header">
            <span class="ts-kaart-naam">${escHtml(afstand.naam)}</span>
            <span class="ts-kaart-cats">${catNamen}</span>
            <span class="ts-kaart-samenvatting">${samenvatting}</span>
            <button class="ts-btn-bewerk" data-naam="${escHtml(afstand.naam)}">${huidigTijdschema?.heeft_loting ? '👁 Bekijken' : '✏ Bewerken'}</button>
        </div>
        <div class="ts-kaart-panel" id="${panelId}" style="${isOpen ? '' : 'display:none'}">
            ${renderAfstandPanel(afstand, cfg, catConfigMap)}
        </div>
    </div>`;
}

function renderAfstandSamenvatting(cats, catConfigMap) {
    // Bepaal welke rondes er zijn over alle categorieën heen
    let heats = false, kwart = false, half = false, runnerUp = false;
    for (const c of cats) {
        const cc = catConfigMap[c.dc_id + '|' + (c.distance_id ?? '')];
        if (!cc) continue;
        if (cc.heeft_heats)        heats    = true;
        if (cc.heeft_kwartfinale)  kwart    = true;
        if (cc.heeft_halve_finale) half     = true;
        if (cc.heeft_runner_up)    runnerUp = true;
    }
    const delen = [];
    if (heats)    delen.push('Series');
    if (kwart)    delen.push('Kwartfinale');
    if (half)     delen.push('Halve finale');
    if (runnerUp) delen.push('Runner-up');
    delen.push('Finale');
    return `<span class="ts-samenvatting-pijlen">${delen.join(' → ')}</span>`;
}

// ── Afstand-configuratiepaneel ────────────────────────────────────────────────

function renderAfstandPanel(afstand, cfg, catConfigMap) {
    const tsId    = huidigTijdschema.id;
    const qD      = cfg?.q_direct            ?? 1;
    const qT      = cfg?.q_tijd              ?? 0;
    const fHg     = cfg?.finale_heat_grootte ?? 6;
    const systeem = huidigTijdschema?.systeem ?? 'full-final';
    const isFF    = systeem === 'full-final';

    // ── Gedeelde instellingen ─────────────────────────────────────────────────
    let html = `<div class="ts-panel-form" data-naam="${escHtml(afstand.naam)}" data-ts-id="${tsId}">
        <div class="ts-gedeeld-sectie">
            <div class="ts-cat-sectie-titel">Instellingen voor alle categorieën – ${escHtml(afstand.naam)}</div>
            <div class="ts-gedeeld-velden">`;

    // Bepaal of runner-up aan staat (kijk naar eerste gevonden cat-config)
    const hRAny = !isFF && afstand.cats.some(c => {
        const cc = catConfigMap[c.dc_id + '|' + (c.distance_id ?? '')];
        return !!cc?.heeft_runner_up;
    });

    if (isFF) {
        // Full-final: finales worden per categorie geconfigureerd (in de tabel hieronder).
        // Alleen defaults als hidden velden voor afstand-config backward compat.
        html += `
                <div class="ts-gedeeld-rij">
                    <span class="ts-veld-hint">
                        De grootte van de A-finale en het aantal B-finales worden per categorie
                        ingesteld in de tabel hieronder — zo kun je ze afstemmen op het aantal
                        deelnemers per categoriegroep.
                    </span>
                </div>
                <input type="hidden" name="finale_heat_grootte" value="${fHg}">
                <input type="hidden" name="finale_b_grootte"    value="${cfg?.finale_b_grootte   ?? 6}">
                <input type="hidden" name="laatste_b_grootste"  value="${cfg?.laatste_b_grootste ?? 1}">
                <input type="hidden" name="q_direct"             value="0">
                <input type="hidden" name="q_tijd"               value="0">
                <input type="hidden" name="heeft_runner_up"      value="0">`;
                // race_type wordt niet meer hier opgeslagen — het is een
                // eigenschap van de afstand zelf (distances.race_type),
                // bewerkbaar via Beheer categorieën & afstanden én via de
                // live-view voor lange-afstand heats.
    } else {
        // Internationaal: doorgang per ronde ingesteld in de categorie-tabel; runner-up optie hier
        html += `
                <input type="hidden" name="q_direct" value="0">
                <input type="hidden" name="q_tijd"   value="0">
                <div class="ts-gedeeld-rij">
                    <span class="ts-gedeeld-lbl">Runner-up</span>
                    <label class="ts-gedeeld-inputs">
                        <input type="checkbox" name="heeft_runner_up" class="ts-cb-runner-up" ${hRAny ? 'checked' : ''}>
                        Niet-gekwalificeerden rijden een runner-up race
                    </label>
                </div>
                <div class="ts-gedeeld-rij">
                    <span class="ts-gedeeld-lbl">Kleine finale</span>
                    <label class="ts-gedeeld-inputs">
                        <input type="checkbox" name="heeft_kleine_finale" ${Number(cfg?.heeft_kleine_finale) ? 'checked' : ''}>
                        Afgevallen rijders voor de finale rijden kleine finale (B-finale)
                    </label>
                </div>
                <div class="ts-gedeeld-rij ts-ru-max-rij" ${hRAny ? '' : 'style="display:none"'}>
                    <span class="ts-gedeeld-lbl">Max. per heat</span>
                    <span class="ts-gedeeld-inputs">
                        <input type="number" name="runner_up_max" value="${cfg?.runner_up_max ?? 0}"
                               min="0" max="30" class="ts-inp-sm">
                        rijders per runner-up heat
                    </span>
                    <span class="ts-veld-hint">Eerste heats krijgen max. rijders, laatste heat de rest</span>
                </div>
                <div class="ts-gedeeld-rij ts-ru-max-rij" ${hRAny ? '' : 'style="display:none"'}>
                    <span class="ts-gedeeld-lbl">Min. per heat</span>
                    <span class="ts-gedeeld-inputs">
                        <input type="number" name="runner_up_min" value="${cfg?.runner_up_min ?? 0}"
                               min="0" max="30" class="ts-inp-sm">
                        rijders (bij ≥2 heats: samenvoegen als laatste heat kleiner is)
                    </span>
                    <span class="ts-veld-hint">0 = geen minimum</span>
                </div>
                <input type="hidden" name="finale_heat_grootte" value="${fHg}">`;
                // Race type wordt hier niet meer ingesteld — dat gebeurt nu op
                // de afstand zelf (Beheer categorieën & afstanden). Het
                // sanctie-gedrag (W1/W2, DNF = reverse withdrawal bij lange
                // afstand) wordt in de backend automatisch afgeleid uit
                // distances.race_type: sprint-afstand → sprint-gedrag, alle
                // andere → long_distance-gedrag.
    }

    html += `
            </div>
        </div>`;

    // ── Per-categorie tabel ───────────────────────────────────────────────────
    html += `<div class="ts-cat-sectie">
        <div class="ts-cat-sectie-titel">Rondes per categorie</div>
        <div class="ts-cat-tabel-wrap">
        <table class="ts-cat-heats-tabel ts-cat-config-tabel">
            <thead>`;

    if (isFF) {
        html += `<tr>
                <th class="ts-th-catnaam" rowspan="2">Categorie</th>
                <th class="ts-th-c ts-th-n" rowspan="2">Deel&shy;nemers</th>
                <th colspan="4" class="ts-th-sectie">Series</th>
                <th colspan="3" class="ts-th-sectie ts-sectie-start">Finales</th>
            </tr><tr>
                <th class="ts-th-c">Rijdt<br>series</th>
                <th class="ts-th-c">Aantal<br>heats</th>
                <th class="ts-th-c" title="Aantal directe kwalificaties per serie naar de A-finale (Q). 0 = puur tijdsnelsten. 1 = winnaar van elke serie krijgt een gegarandeerd A-finale-plek, rest van de A-finale wordt aangevuld met de tijdsnelsten van de overige rijders.">Q per<br>heat</th>
                <th class="ts-th-c" title="Aangevinkt: de serie dient alleen als startvolgorde-bepaling voor de A-finale. De einduitslag komt volledig uit de A-finale (serie-punten tellen niet mee). Alleen beschikbaar bij 1 serie-heat.">Alleen<br>startvolgorde</th>
                <th class="ts-th-c ts-sectie-start" title="Aantal rijders in de A-finale (max = aantal deelnemers)">A-finale<br>rijders</th>
                <th class="ts-th-c" title="Aantal B-finale heats — overige rijders worden gelijk verdeeld">B-finales<br>aantal</th>
                <th class="ts-th-c" title="Aangevinkt: B-laatste krijgt de rest. Uitgevinkt: B1 krijgt de rest.">Laatste B<br>grootste</th>
            </tr>`;
    } else {
        html += `<tr>
                <th class="ts-th-catnaam" rowspan="2">Categorie</th>
                <th class="ts-th-c ts-th-n" rowspan="2">Deel&shy;nemers</th>
                <th colspan="3" class="ts-th-sectie">Series</th>
                <th colspan="4" class="ts-th-sectie ts-sectie-start">Kwartfinale</th>
                <th colspan="4" class="ts-th-sectie ts-sectie-start">Halve finale</th>
                <th colspan="2" class="ts-th-sectie ts-sectie-start">Finale</th>
            </tr><tr>
                <th class="ts-th-c">Rijdt<br>series</th>
                <th class="ts-th-c">Aantal<br>heats</th>
                <th class="ts-th-c">Totaal<br>door →</th>
                <th class="ts-th-c ts-sectie-start">Rijdt<br>kwartfinale</th>
                <th class="ts-th-c">Aantal<br>heats</th>
                <th class="ts-th-c">Totaal<br>door →</th>
                <th class="ts-th-c">Q per<br>heat</th>
                <th class="ts-th-c ts-sectie-start">Rijdt halve<br>finale</th>
                <th class="ts-th-c">Aantal<br>heats</th>
                <th class="ts-th-c">Totaal<br>door →</th>
                <th class="ts-th-c">Q per<br>heat</th>
                <th class="ts-th-c ts-sectie-start">Aantal<br>heats</th>
                <th class="ts-th-c">Seeding</th>
            </tr>`;
            // race_type niet meer hier — zie distances.race_type
    }

    html += `</thead><tbody>`;

    for (const cat of afstand.cats) {
        const catKey = cat.dc_id + '|' + (cat.distance_id ?? '');
        const cc     = catConfigMap[catKey] ?? null;
        const hH  = cc?.heeft_heats        ?? true;
        const hK  = cc?.heeft_kwartfinale  ?? false;
        const hHf = cc?.heeft_halve_finale ?? false;
        const hR  = cc?.heeft_runner_up    ?? false;
        // Standaard 0 als nog nooit opgeslagen; liever leeg dan een berekende gok
        const nH   = cc?.heats_aantal ?? 0;
        const qDef = cc?.heats_q      ?? 0;
        const nKH  = cc?.kwart_heats  ?? 0;
        const nHH  = cc?.half_heats   ?? 0;
        const ph   = cat.n > 0 && nH > 0 ? berekenPerHeat(cat.n, nH) : '—';

        html += `<tr class="ts-cat-rij" data-dc-id="${escHtml(cat.dc_id)}"
                     data-dist-id="${escHtml(cat.distance_id ?? '')}"
                     data-n="${cat.n}">
            <td class="ts-td-catnaam">${escHtml(cat.dc_naam)}</td>
            <td class="ts-td-n">${cat.n}</td>
            <td class="ts-td-c"><input type="checkbox" name="heeft_heats"
                    class="ts-cb-heats" ${hH ? 'checked' : ''}></td>
            <td class="ts-td-c ts-heats-velden" style="${hH ? '' : 'visibility:hidden'}">
                <input type="number" name="heats_aantal" value="${nH}"
                       min="0" max="50" class="ts-inp-sm ts-inp-heats-aantal" data-n="${cat.n}">
                <span class="ts-per-heat-cel">${escHtml(ph)}/h</span>
            </td>`;

        if (!isFF) {
            // Als geen series: input voor kwart/half is het totale startaantal
            const kwartIn = hH ? qDef : cat.n;
            const kDoor   = cc?.kwart_door   ?? 0;
            const kQH     = cc?.kwart_q_heat ?? 0;
            const kQAfl   = Math.max(0, kDoor - kQH * nKH);
            const halfIn  = hK ? kDoor : kwartIn;
            const hDoor   = cc?.half_door    ?? 0;
            const hQH     = cc?.half_q_heat  ?? 0;
            const hQAfl   = Math.max(0, hDoor - hQH * nHH);
            // Per-heat previews
            const phKwart = hK ? escHtml(berekenPerHeat(kwartIn, nKH)) + '/h' : '—';
            const phHalf  = hHf ? escHtml(berekenPerHeat(halfIn, nHH)) + '/h' : '—';

            html += `
            <td class="ts-td-c ts-heats-q-cel" style="${hH ? '' : 'visibility:hidden'}">
                <input type="number" name="heats_q" value="${qDef}"
                       min="0" max="500" class="ts-inp-sm"
                       title="Totaal tijdsnelsten vanuit de series (q)">
                <input type="hidden" name="heats_q_heat" value="0">
            </td>
            <td class="ts-td-c ts-sectie-start">
                <input type="checkbox" name="heeft_kwartfinale"
                       class="ts-cb-kwart" ${hK ? 'checked' : ''}>
            </td>
            <td class="ts-td-c ts-kwart-velden" style="${hK ? '' : 'visibility:hidden'}">
                <input type="number" name="kwart_heats" value="${nKH}"
                       min="0" max="50" class="ts-inp-sm ts-inp-kwart-aantal"
                       data-q="${kwartIn}">
                <span class="ts-per-heat-cel">${phKwart}</span>
            </td>
            <td class="ts-td-c ts-kwart-velden" style="${hK ? '' : 'visibility:hidden'}">
                <input type="number" name="kwart_door" value="${kDoor}"
                       min="0" max="500" class="ts-inp-sm ts-inp-kwart-door"
                       title="Totaal doorstromers kwartfinale → volgende ronde">
            </td>
            <td class="ts-td-c ts-kwart-velden" style="${hK ? '' : 'visibility:hidden'}">
                <input type="number" name="kwart_q_heat" value="${kQH}"
                       min="0" max="20" class="ts-inp-sm ts-inp-kwart-qh"
                       title="Directe kwalificatie per heat (Q)">
                <span class="ts-q-afgeleid">+${kQAfl}q</span>
            </td>
            <td class="ts-td-c ts-sectie-start">
                <input type="checkbox" name="heeft_halve_finale"
                       class="ts-cb-half" ${hHf ? 'checked' : ''}>
            </td>
            <td class="ts-td-c ts-half-velden" style="${hHf ? '' : 'visibility:hidden'}">
                <input type="number" name="half_heats" value="${nHH}"
                       min="0" max="50" class="ts-inp-sm ts-inp-half-aantal"
                       data-in="${halfIn}">
                <span class="ts-per-heat-cel ts-per-heat-half">${phHalf}</span>
            </td>
            <td class="ts-td-c ts-half-velden" style="${hHf ? '' : 'visibility:hidden'}">
                <input type="number" name="half_door" value="${hDoor}"
                       min="0" max="500" class="ts-inp-sm"
                       title="Totaal doorstromers halve finale → finale">
            </td>
            <td class="ts-td-c ts-half-velden" style="${hHf ? '' : 'visibility:hidden'}">
                <input type="number" name="half_q_heat" value="${hQH}"
                       min="0" max="20" class="ts-inp-sm ts-inp-half-qh"
                       title="Directe kwalificatie per heat (Q)">
                <span class="ts-q-afgeleid">+${hQAfl}q</span>
            </td>
            <td class="ts-td-c ts-sectie-start">
                <input type="number" name="finale_heats" value="${cc?.finale_heats ?? 1}"
                       min="1" max="30" class="ts-inp-sm ts-inp-finale-heats"
                       title="Aantal A-finale heats">
            </td>
            <td class="ts-td-c">
                <select name="finale_seeding" class="ts-sel-sm ts-sel-finale-seeding"
                        title="Standaard (slangenpatroon): gelijke sterkte per heat&#10;Tijdkoppeling: langzaamsten in heat 1, snelsten in laatste heat — ZOWEL in series ALS finale (= 200m DTT-format)&#10;Omgekeerd (slangenpatroon): snelste tegen langzaamste rijder in de laatste heat, geldt voor alle rondes (100m sprint 2-lane)">
                    <option value="slang" ${(cfg?.finale_seeding ?? 'slang') === 'slang' ? 'selected' : ''}>Standaard (snake)</option>
                    <option value="tijdkoppeling" ${cfg?.finale_seeding === 'tijdkoppeling' ? 'selected' : ''}>Tijdkoppeling (DTT)</option>
                    <option value="reverse_slang" ${cfg?.finale_seeding === 'reverse_slang' ? 'selected' : ''}>Omgekeerd (snake)</option>
                </select>
            </td>`;
        } else {
            // Full-final: zichtbare finale-kolommen per categorie + hidden placeholders
            // voor de internationaal-velden zodat de save-handler complete rijen ontvangt.
            const afg = parseInt(cc?.finale_a_grootte);
            // Bij cat.n=0 (placeholder-categorie) gebruiken we fHg als default —
            // anders zou aDef = Math.min(0, fHg) = 0 en kan de planner niets invoeren.
            const aDef = Number.isFinite(afg) && afg > 0
                ? afg
                : (cat.n > 0 ? Math.min(cat.n, fHg) : fHg);
            const bhCfg = parseInt(cc?.finale_b_heats);
            // Default B-heats: als cat.n > A-finale, één B-heat; anders 0 (geen B nodig)
            const bhDef = Number.isFinite(bhCfg) && bhCfg >= 0
                ? bhCfg
                : (cat.n > aDef ? 1 : 0);
            // Bij placeholder (n=0) hogere max op finale-inputs zodat de planner
            // op verwacht aantal kan plannen. Bij echte deelnemerstelling blijft
            // de max gewoon cat.n (= klassieke validatie).
            const aMax = cat.n > 0 ? cat.n               : 100;
            const bMax = cat.n > 0 ? Math.max(0, cat.n-1): 100;
            // PDO geeft TINYINT terug als string ("0"/"1") — pure truthy-check
            // op "0" is true → checkbox zou ten onrechte aangevinkt staan.
            // parseInt() nodig om de string-waarde correct te vergelijken.
            const lbgCfg = cc?.laatste_b_grootste;
            const lbgDef = (lbgCfg === null || lbgCfg === undefined)
                ? (parseInt(cfg?.laatste_b_grootste ?? 1) === 1 ? 1 : 0)
                : (parseInt(lbgCfg) === 1 ? 1 : 0);
            // Series-alleen-startvolgorde: alleen zinvol met 1 serie-heat.
            // Gebruik parseInt: PDO levert TINYINT als string ("0"/"1"),
            // dus !!"0" zou ten onrechte true geven.
            const sasChecked = parseInt(cc?.series_alleen_startvolgorde) === 1;
            const sasEnabled = hH && parseInt(nH) === 1;

            html += `
            <td class="ts-td-c ts-heats-velden" style="${hH ? '' : 'visibility:hidden'}">
                <input type="number" name="heats_q_heat" value="${parseInt(cc?.heats_q_heat ?? 0)}"
                       min="0" max="20" class="ts-inp-sm ts-inp-heats-qh"
                       title="Q per heat: aantal rijders dat per serie direct doorgaat naar de A-finale (op basis van heat-positie). Rest van de A-finale wordt aangevuld met de tijdsnelsten van de overige rijders. 0 = puur tijdsnelsten (oude gedrag).">
            </td>
            <td class="ts-td-c">
                <input type="checkbox" name="series_alleen_startvolgorde"
                       class="ts-cb-sas" ${sasChecked && sasEnabled ? 'checked' : ''}
                       ${sasEnabled ? '' : 'disabled'}
                       title="Serie is alleen bepalend voor de startvolgorde in de A-finale; de einduitslag komt volledig uit de A-finale.&#10;Alleen selecteerbaar bij 1 serie-heat.">
            </td>
            <td class="ts-td-c ts-sectie-start">
                <input type="number" name="finale_a_grootte" value="${aDef}"
                       min="1" max="${aMax}" class="ts-inp-sm ts-inp-finale-a"
                       data-n="${cat.n}"
                       title="${cat.n > 0
                           ? `Aantal rijders in de A-finale (max ${cat.n} = aantal deelnemers)`
                           : 'Aantal rijders in de A-finale (placeholder: nog geen deelnemers bekend)'
                       }">
                <span class="ts-per-heat-cel ts-per-heat-finale-a">${berekenFinaleAPreview(cat.n, aDef, bhDef)}</span>
            </td>
            <td class="ts-td-c">
                <input type="number" name="finale_b_heats" value="${bhDef}"
                       min="0" max="${bMax}" class="ts-inp-sm ts-inp-finale-bh"
                       title="Aantal B-finale heats (0 = geen B-finale, alle niet-A-finalisten bij A)">
                <span class="ts-per-heat-cel ts-per-heat-finale-b">${berekenFinaleBPreview(cat.n, aDef, bhDef)}</span>
            </td>
            <td class="ts-td-c">
                <input type="checkbox" name="laatste_b_grootste" class="ts-cb-laatste-b"
                       ${lbgDef ? 'checked' : ''}
                       title="Rest schuift naar B-laatste (aan) of B1 (uit)">
            </td>
            <input type="hidden" name="heats_q"            value="${cat.n}">
            <input type="hidden" name="heeft_kwartfinale"  value="0">
            <input type="hidden" name="kwart_heats"        value="0">
            <input type="hidden" name="kwart_door"         value="0">
            <input type="hidden" name="kwart_q_heat"       value="0">
            <input type="hidden" name="heeft_halve_finale" value="0">
            <input type="hidden" name="half_heats"         value="0">
            <input type="hidden" name="half_door"          value="0">
            <input type="hidden" name="half_q_heat"        value="0">
            <input type="hidden" name="heeft_runner_up"    value="0">
            <input type="hidden" name="finale_heats"       value="1">`;
        }

        html += `</tr>`;
    }

    html += `</tbody></table></div></div>`; // ts-cat-tabel-wrap + ts-cat-sectie

    // Berekend overzicht
    html += `<div class="ts-calc-overzicht">
        <div class="ts-calc-titel">📊 Berekend overzicht</div>
        <div class="ts-calc-inhoud" id="ts-calc-${afstand.naam.replace(/[^a-z0-9]/gi, '_')}">
            ${renderAfstandCalc(afstand, cfg, catConfigMap)}
        </div>
    </div>`;

    html += `<div class="ts-panel-acties">
        <button class="btn-primary ts-btn-afstand-save" data-naam="${escHtml(afstand.naam)}">Opslaan</button>
        <button class="ts-btn-annuleer ts-btn-afstand-cancel" data-naam="${escHtml(afstand.naam)}">Annuleren</button>
    </div>
    </div>`; // ts-panel-form

    return html;
}

// ── Berekend overzicht (per categorie) ───────────────────────────────────────

function renderAfstandCalc(afstand, cfg, catConfigMap) {
    const finaleHg = parseInt(cfg?.finale_heat_grootte) || 6;
    const systeem  = huidigTijdschema?.systeem ?? 'full-final';
    const isFF     = systeem === 'full-final';

    const lines = [];

    for (const cat of afstand.cats) {
        const catKey = cat.dc_id + '|' + (cat.distance_id ?? '');
        const cc     = catConfigMap[catKey] ?? null;
        if (!cc) continue;

        const hH    = !!cc.heeft_heats;
        const hK    = !isFF && !!cc.heeft_kwartfinale;
        const hHf   = !isFF && !!cc.heeft_halve_finale;
        const hR    = !isFF && !!cc.heeft_runner_up;
        const nVH   = parseInt(cc.heats_aantal) || 1;
        const qDoor = isFF ? cat.n : (parseInt(cc.heats_q) || 0);
        const kDoor = parseInt(cc.kwart_door)   || 0;
        const kQH   = parseInt(cc.kwart_q_heat ?? 1);
        const hDoor = parseInt(cc.half_door)    || 0;
        const hQH   = parseInt(cc.half_q_heat  ?? 1);
        const nKH   = parseInt(cc.kwart_heats)  || 1;
        const nHHf  = parseInt(cc.half_heats)   || 1;

        const stappen = [];

        // ── Kwartfinale-input hangt af van of er series zijn ──
        const kwartInSt = hH ? qDoor : cat.n;
        const halfInSt  = hK ? kDoor : kwartInSt;

        if (!hH) {
            // Geen series — controleer of er alsnog kwart/half zijn
            if (hK) {
                const phK   = berekenPerHeat(cat.n, nKH);
                const kQAfl = Math.max(0, kDoor - kQH * nKH);
                stappen.push(`${cat.n} rijders → direct naar kwartfinale: ${nKH} heat${nKH > 1 ? 's' : ''} (${escHtml(phK)}/h, Q=${kQH}/h +${kQAfl}q) → ${kDoor} door`);
                if (hHf) {
                    const phH   = berekenPerHeat(kDoor, nHHf);
                    const hQAfl = Math.max(0, hDoor - hQH * nHHf);
                    stappen.push(`halve finale: ${kDoor} → ${nHHf} heat${nHHf > 1 ? 's' : ''} (${escHtml(phH)}/h, Q=${hQH}/h +${hQAfl}q) → ${hDoor} door`);
                }
            } else if (hHf) {
                const phH   = berekenPerHeat(cat.n, nHHf);
                const hQAfl = Math.max(0, hDoor - hQH * nHHf);
                stappen.push(`${cat.n} rijders → direct naar halve finale: ${nHHf} heat${nHHf > 1 ? 's' : ''} (${escHtml(phH)}/h, Q=${hQH}/h +${hQAfl}q) → ${hDoor} door`);
            } else {
                stappen.push(`${cat.n} rijders → rechtstreeks naar finale`);
            }
        } else {
            // Series (altijd op tijd)
            const ph = berekenPerHeat(cat.n, nVH);
            // Full-final met heats_q_heat ≥ 1: toon Q+q-uitsplitsing zodat de
            // planner ziet hoeveel rijders direct via heat-positie doorgaan.
            // Bij heats_q_heat=0 (klassiek): alleen "Nq" tonen.
            const hQH = isFF ? Math.max(0, parseInt(cc.heats_q_heat) || 0) : 0;
            if (isFF && hQH > 0) {
                const Q = hQH * nVH;
                const q = Math.max(0, cat.n - Q);
                stappen.push(`${cat.n} → ${nVH} series (${escHtml(ph)}/h) → ${Q}Q + ${q}q`);
            } else {
                stappen.push(`${cat.n} → ${nVH} series (${escHtml(ph)}/h) → ${qDoor}q`);
            }

            if (!isFF) {
                // Kwartfinale
                if (hK) {
                    const phK   = berekenPerHeat(qDoor, nKH);
                    const kQAfl = Math.max(0, kDoor - kQH * nKH);
                    stappen.push(`kwartfinale: ${qDoor} → ${nKH} heats (${escHtml(phK)}/h, Q=${kQH}/h +${kQAfl}q) → ${kDoor} door`);
                }
                // Halve finale
                if (hHf) {
                    const phH   = berekenPerHeat(halfInSt, nHHf);
                    const hQAfl = Math.max(0, hDoor - hQH * nHHf);
                    stappen.push(`halve finale: ${halfInSt} → ${nHHf} heats (${escHtml(phH)}/h, Q=${hQH}/h +${hQAfl}q) → ${hDoor} door`);
                }
                // Runner-up (alleen als er series zijn en niet iedereen doorgaat)
                if (hR && qDoor > 0 && qDoor < cat.n) {
                    const uitv = Math.max(0, cat.n - qDoor);
                    if (uitv > 0) {
                        const ruMaxPH = parseInt(cfg?.runner_up_max) || 6;
                        const ruMinPH = parseInt(cfg?.runner_up_min) || 0;
                        const heats   = berekenRunnerUpHeats(uitv, ruMaxPH, ruMinPH);
                        const nRH     = heats.length;
                        const heatStr = nRH > 1
                            ? `[${heats.join(', ')}]`
                            : berekenPerHeat(uitv, 1) + '/h';
                        stappen.push(`runner-up: ${uitv} → ${nRH} heat${nRH > 1 ? 's' : ''} ${escHtml(heatStr)}`);
                    }
                }
            }
        }

        // ── Finale ──────────────────────────────────────────────
        let finR = 0;
        if (isFF) {
            finR = cat.n;
        } else if (!hH) {
            // Geen series: doorstroom via laatste actieve ronde
            if (hHf)      finR = hDoor;
            else if (hK)  finR = kDoor;
            else          finR = cat.n;
        } else if (hHf) {
            finR = hDoor;
        } else if (hK) {
            finR = kDoor;
        } else {
            finR = qDoor;
        }

        if (finR > 0) {
            if (isFF) {
                // Per-cat finale_a_grootte wint; anders afstand-default; anders 6
                const aRaw = parseInt(cc?.finale_a_grootte);
                const aHg  = Number.isFinite(aRaw) && aRaw > 0
                    ? aRaw
                    : Math.max(2, parseInt(cfg?.finale_heat_grootte) || 6);
                const aR   = Math.min(finR, aHg);
                const bR   = Math.max(0, finR - aR);

                // Per-cat aantal B-heats wint; fallback: afgeleid van finale_b_grootte
                const bhRaw = parseInt(cc?.finale_b_heats);
                let nB;
                if (Number.isFinite(bhRaw)) {
                    nB = Math.max(0, Math.min(bhRaw, bR)); // niet meer B-heats dan B-rijders
                } else {
                    const bHgR = Math.max(2, parseInt(cfg?.finale_b_grootte) || 6);
                    nB = bR > 0 ? Math.ceil(bR / Math.max(bHgR, aHg)) : 0;
                }

                // Q+q-uitsplitsing voor finale-labels (alleen bij heats_q_heat ≥ 1):
                // A-finale toont "(2Q + 6q)" — hoeveel direct via heat-positie
                // doorgaan en hoeveel via tijdsnelsten worden aangevuld.
                // B-finales krijgen geen breakdown (consequent over alle B-heats),
                // het totaal-aantal volgt impliciet uit het verschil cat.n − A.
                const hQH2 = hH ? Math.max(0, parseInt(cc.heats_q_heat) || 0) : 0;
                const totQ = hQH2 * nVH;
                const aQ   = Math.min(totQ, aR);              // Q's in A
                const aq   = Math.max(0, aR - aQ);            // q's in A
                const aLabel = (hQH2 > 0)
                    ? `A-finale (${aQ}Q + ${aq}q)`
                    : `A-finale (${aR})`;

                const parts = [];
                if (bR > 0 && nB > 0) {
                    for (let b = nB; b >= 1; b--) parts.push(`B${b}-finale`);
                    parts.push(aLabel);
                } else if (bR > 0 && nB === 0) {
                    // 0 B-heats ingesteld met rest-rijders: ze worden toegevoegd
                    // aan de A-finale (de planner ziet dit en bepaalt zelf of dat mag).
                    parts.push(`A-finale (${aR + bR})`);
                } else {
                    parts.push(aLabel);
                }
                stappen.push(`${finR} → ${parts.join(' + ')}`);
            } else {
                // Internationaal-nieuw: A krijgt alle doorstromers uit vorige
                // ronde. Bij heeft_kleine_finale (per-afstand): rest van de
                // voorgaande ronde (= input − output) rijdt de kleine finale.
                // Number() forceert conversie — MySQL levert tinyint als string.
                const heeftKF = !!Number(cfg?.heeft_kleine_finale);
                // Input van de laatste actieve ronde vóór de finale.
                let totaalInVorige = finR;
                if (hHf) {
                    totaalInVorige = hK ? kDoor : (hH ? qDoor : cat.n);
                } else if (hK) {
                    totaalInVorige = hH ? qDoor : cat.n;
                } else if (hH) {
                    totaalInVorige = cat.n;
                }
                // Kleine finale (internationaal-nieuw) mag nooit meer rijders
                // bevatten dan de A-finale — zie tijdschema.php voor rationale.
                const kfRruw = heeftKF ? Math.max(0, totaalInVorige - finR) : 0;
                const kfR    = Math.min(kfRruw, finR);
                if (kfR > 0) {
                    stappen.push(`A-finale: ${finR} + kleine finale: ${kfR}`);
                } else {
                    stappen.push(`A-finale: ${finR}`);
                }
            }
        }

        if (stappen.length) {
            lines.push(`<div class="ts-calc-rij"><span class="ts-calc-cat">${escHtml(cat.dc_naam)}</span>: ${stappen.join(' → ')}</div>`);
        }
    }

    return lines.length
        ? lines.join('')
        : '<em>Stel rondes per categorie in om de berekening te zien.</em>';
}

function berekenPerHeat(n, nHeats) {
    if (!n || !nHeats) return '—';
    const basis = Math.floor(n / nHeats);
    const extra = n % nHeats;
    if (extra === 0) return String(basis);
    return `${basis}-${basis + 1}`;
}

// Full-final previews voor de A- en B-finale cel ("x/h" naast de input).
// - A-finale heeft altijd 1 heat; met 0 B-heats + rest-rijders schuiven die
//   naar A, dus effectieve A-grootte wordt dan aFin + bR.
// - B-finales: grootste heat = ceil(rest / nBHeats).
function berekenFinaleAPreview(n, aFin, nBHeats) {
    const aR = Math.min(n, Math.max(0, aFin));
    const bR = Math.max(0, n - aR);
    const eff = (nBHeats > 0) ? aR : aR + bR;  // 0 B-heats → rest naar A
    return eff > 0 ? `${eff}/h` : '—';
}
function berekenFinaleBPreview(n, aFin, nBHeats) {
    const aR = Math.min(n, Math.max(0, aFin));
    const bR = Math.max(0, n - aR);
    if (nBHeats <= 0 || bR <= 0) return '—';
    const max = Math.ceil(bR / nBHeats);
    return `max ${max}/h`;
}

// Werk beide previews in één rij bij (aangeroepen bij input-wijzigingen)
function herberekenFinalePreview(tr) {
    if (!tr) return;
    const n    = parseInt(tr.dataset.n) || 0;
    const aInp = tr.querySelector('.ts-inp-finale-a');
    const bInp = tr.querySelector('.ts-inp-finale-bh');
    const aS   = tr.querySelector('.ts-per-heat-finale-a');
    const bS   = tr.querySelector('.ts-per-heat-finale-b');
    const aFin = parseInt(aInp?.value)  || 0;
    const nBH  = parseInt(bInp?.value)  || 0;
    if (aS) aS.textContent = berekenFinaleAPreview(n, aFin, nBH);
    if (bS) bS.textContent = berekenFinaleBPreview(n, aFin, nBH);
}

// ── Programma-volgorde ────────────────────────────────────────────────────────

function renderBlokken(schema, afstandGroepen) {
    const blokken = schema.blokken ?? [];
    const heeftWsStart = blokken.some(b => b.blok_type === 'wedstrijdstart');
    const wsIdx = blokken.findIndex(b => b.blok_type === 'wedstrijdstart');
    // Multi-day: bepaal voor elk wedstrijdstart-blok het dag-nummer (1-indexed
    // volgens volgorde van voorkomen) zodat de UI 'Dag 1 / Dag 2 / Dag N'
    // kan tonen ipv generiek 'WEDSTRIJD START'.
    const wsBlokkenInVolgorde = blokken.filter(b => b.blok_type === 'wedstrijdstart');
    const wsTotaalDagen = wsBlokkenInVolgorde.length;
    const dagNrMap = new Map();
    wsBlokkenInVolgorde.forEach((b, i) => dagNrMap.set(b.id, i + 1));

    // Alle unieke categorieën (voor inrijd-selectie)
    const alleCatsUniek = [];
    const geziendcIds = new Set();
    for (const af of afstandGroepen) {
        for (const c of af.cats) {
            if (!geziendcIds.has(c.dc_id)) {
                geziendcIds.add(c.dc_id);
                alleCatsUniek.push(c);
            }
        }
    }

    const duurInp = (blok) =>
        `<label class="ts-blok-duur-lbl" title="Duur in minuten">
            <input type="number" class="ts-inp-sm ts-inp-duur" data-blok-id="${blok.id}"
                   value="${blok.duur ?? ''}" min="1" max="240" placeholder="min">
            <span class="ts-duur-suffix">min</span>
        </label>`;

    let html = `<div class="ts-sectie">
        <div class="ts-sectie-titel">Programma-volgorde</div>
        <div class="ts-blokken-hint">Gebruik ↑↓ om de volgorde aan te passen. Ronde-blokken kunnen niet vóór de wedstrijdstart geplaatst worden.</div>
        <div class="ts-blokken-lijst" id="ts-blokken-lijst">`;

    blokken.forEach((blok, idx) => {
        // Ronde mag niet voor wedstrijdstart
        const canGoUp = idx > 0 &&
            (blok.blok_type !== 'ronde' || wsIdx === -1 || idx - 1 > wsIdx);
        const navBtns = `<span class="ts-blok-drag-btns">
                    <button class="ts-btn-blok-up"   data-idx="${idx}" ${(!canGoUp) ? 'disabled' : ''}>▲</button>
                    <button class="ts-btn-blok-down" data-idx="${idx}" ${idx===blokken.length-1 ? 'disabled' : ''}>▼</button>
                </span>`;
        const delBtn     = `<button class="btn-del ts-btn-blok-del" data-blok-id="${blok.id}" title="Verwijderen">&#128465;</button>`;
        const dragHandle = `<span class="ts-drag-handle" title="Sleep om te verplaatsen">⠿</span>`;

        if (blok.blok_type === 'pauze') {
            const opmVal = escHtml(blok.opmerking ?? '');
            html += `<div class="ts-blok-item ts-blok-pauze" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-pauze-lbl">── PAUZE ──</span>
                ${duurInp(blok)}
                <label class="ts-blok-duur-lbl ts-blok-opm-lbl">
                    Opmerking:&nbsp;<input type="text" class="ts-inp-opmerking" data-blok-id="${blok.id}"
                                          value="${opmVal}" placeholder="bijv. lunch, uitloop…" maxlength="120">
                </label>
                ${delBtn}
            </div>`;

        } else if (blok.blok_type === 'inrijden') {
            const geselecteerd = (() => { try { return JSON.parse(blok.inrijd_cats || '[]'); } catch(e) { return []; } })();
            const catCbs = alleCatsUniek.map(c =>
                `<label class="ts-inrijd-cat-lbl">
                    <input type="checkbox" class="ts-inrijd-cat-cb" data-blok-id="${blok.id}"
                           value="${escHtml(c.dc_id)}" ${geselecteerd.includes(c.dc_id) ? 'checked' : ''}>
                    ${escHtml(c.dc_naam)}
                </label>`
            ).join('');
            html += `<div class="ts-blok-item ts-blok-inrijd" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-inrijd-lbl">── INRIJDEN ──</span>
                <span class="ts-inrijd-cats">${catCbs}</span>
                ${duurInp(blok)}
                ${delBtn}
            </div>`;

        } else if (blok.blok_type === 'ceremonie') {
            const opmVal = escHtml(blok.opmerking ?? '');
            html += `<div class="ts-blok-item ts-blok-cerem" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-cerem-lbl">── CEREMONIE ──</span>
                ${duurInp(blok)}
                <label class="ts-blok-duur-lbl ts-blok-opm-lbl">
                    Opmerking:&nbsp;<input type="text" class="ts-inp-opmerking" data-blok-id="${blok.id}"
                                          value="${opmVal}" placeholder="bijv. prijsuitreiking, jeugd…" maxlength="120">
                </label>
                ${delBtn}
            </div>`;

        } else if (blok.blok_type === 'wedstrijdstart') {
            const tijdVal  = blok.tijdstip ? blok.tijdstip.substring(0,5) : '';
            const datumVal = blok.datum    ? blok.datum.substring(0,10)   : '';
            // Multi-day support: tel wedstrijdstart-blokken in volgorde voor
            // automatische 'Dag N'-label. Bij 1 wedstrijdstart toon klassieke
            // label, bij meerdere expliciet Dag-nummer.
            const wsDagNr = dagNrMap.get(blok.id) || 1;
            const wsLbl   = wsTotaalDagen > 1
                ? `── DAG ${wsDagNr} START ──`
                : '── WEDSTRIJD START ──';
            html += `<div class="ts-blok-item ts-blok-wsstart" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-wsstart-lbl">${wsLbl}</span>
                <label class="ts-blok-duur-lbl">
                    Aanvang:&nbsp;<input type="time" class="ts-inp-tijdstip" data-blok-id="${blok.id}"
                                        value="${tijdVal}">
                </label>
                <label class="ts-blok-duur-lbl" title="Datum van deze wedstrijddag (optioneel — alleen relevant bij meerdaagse evenementen)">
                    Datum:&nbsp;<input type="date" class="ts-inp-datum" data-blok-id="${blok.id}"
                                        value="${datumVal}">
                </label>
                ${delBtn}
            </div>`;

        } else if (blok.blok_type === 'herstart') {
            const tijdVal = blok.tijdstip ? blok.tijdstip.substring(0,5) : '';
            const opmVal  = escHtml(blok.opmerking ?? '');
            html += `<div class="ts-blok-item ts-blok-herstart" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-herstart-lbl">🔄 HERSTART</span>
                <label class="ts-blok-duur-lbl">
                    Tijd:&nbsp;<input type="time" class="ts-inp-tijdstip" data-blok-id="${blok.id}"
                                     value="${tijdVal}">
                </label>
                <label class="ts-blok-duur-lbl ts-blok-opm-lbl">
                    Opmerking:&nbsp;<input type="text" class="ts-inp-opmerking ts-inp-opmerking-breed" data-blok-id="${blok.id}"
                                          value="${opmVal}" placeholder="bijv. vertraging door…" maxlength="120">
                </label>
                ${delBtn}
            </div>`;

        } else {
            // ronde
            const rLabel = TS_RONDE_LABEL[blok.ronde_type] ?? blok.ronde_type;
            const kleur  = TS_RONDE_KLEUR[blok.ronde_type] ?? '#adb5bd';
            html += `<div class="ts-blok-item ts-blok-ronde" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-badge" style="background:${kleur}">${escHtml(rLabel)}</span>
                <span class="ts-blok-afstand">${escHtml(blok.afstand_naam ?? '')}</span>
                <label class="ts-blok-duur-lbl" title="Duur per heat">
                    <input type="text" class="ts-inp-sm ts-inp-heat-duur" data-blok-id="${blok.id}"
                           value="${secNaarMmSs(blok.heat_duur)}" placeholder="m:ss"
                           pattern="^\\d+:[0-5]\\d$|^\\d+$" title="Bijv. 2:30 of 3">
                    <span class="ts-duur-suffix">/ heat</span>
                </label>
            </div>`;
        }
    });

    html += `</div>
        <div class="ts-blokken-acties">
            <button class="ts-btn-annuleer ts-btn-sm" id="ts-btn-add-pauze">+ Pauze toevoegen</button>
            <button class="ts-btn-annuleer ts-btn-sm" id="ts-btn-add-inrijden">+ Inrijden toevoegen</button>
            <button class="ts-btn-cerem ts-btn-sm" id="ts-btn-add-ceremonie">+ Ceremonie toevoegen</button>
            <button class="ts-btn-wsstart ts-btn-sm" id="ts-btn-add-wsstart"
                title="${heeftWsStart
                    ? 'Voeg een extra wedstrijdstart toe voor een nieuwe wedstrijddag (multi-day NK e.d.)'
                    : 'Plaats het startmoment van de wedstrijd op de tijdlijn'}">+ ${heeftWsStart ? 'Dag toevoegen' : 'Wedstrijd start'}</button>
            <button class="ts-btn-herstart ts-btn-sm" id="ts-btn-add-herstart">🔄 Herstart toevoegen</button>
            <span class="ts-blokken-acties-sep"></span>
            <button class="btn-secondary ts-btn-sm" id="ts-btn-save-blokken">💾 Volgorde opslaan</button>
            <span class="ts-btn-wrap" title="${huidigTijdschema?.heeft_loting
                    ? 'Programma kan niet opnieuw gegenereerd worden — er zijn al startlijsten geloot. Gebruik 🗑 per rit voor mid-wedstrijd-skips, of 🗑 Wis programma als je écht opnieuw wilt beginnen.'
                    : 'Genereer alle ritten op basis van blokken + afstand-instellingen'}"
                ><button class="btn-primary ts-btn-sm" id="ts-btn-genereer"
                    ${huidigTijdschema?.heeft_loting ? 'disabled' : ''}
                >▶ Genereer programma</button></span>
            <button class="btn-del ts-btn-sm" id="ts-btn-wis-programma" ${huidigTijdschema?.ritten?.length ? '' : 'disabled'} title="Verwijder ritten, blokken en cat-config — afstandinstellingen blijven behouden. Daarna Opslaan in Afstandinstellingen genereert de blokken opnieuw">🗑 Wis programma</button>
        </div>
    </div>`;

    return html;
}

// ── Ritten-lijst ──────────────────────────────────────────────────────────────

function renderRittenLijst(ritten, blokken) {
    if (!ritten?.length) return '<div class="status-msg info">Geen ritten gegenereerd.</div>';

    // dc_id → dc_naam opzoektabel (voor inrijd-blokken)
    const dcNaamMap = new Map();
    bouwAfstandGroepen().forEach(af =>
        af.cats.forEach(c => { if (!dcNaamMap.has(c.dc_id)) dcNaamMap.set(c.dc_id, c.dc_naam); })
    );

    // ── Actueel aantal deelnemers per DC (live op basis van entry-status) ────
    // Reserves tellen niet (reserve = optioneel, niet ingezet). Status 2/3
    // (afgemeld) en 4 (niet-getekend) tellen niet — 1 (aanwezig) en 5
    // (bevestigd bij org) wél.
    const actueelPerDc = new Map();
    for (const dc of (vergelijkData ?? [])) {
        const aantal = (dc.competitors ?? []).filter(c => {
            const st = c.entry_status;
            const isReserve = c.reserve_nr != null && parseInt(c.reserve_nr) > 0;
            return (st === 1 || st === 5) && !isReserve;
        }).length;
        actueelPerDc.set(dc.dc_id, aantal);
    }
    // Per DC welke ronde-types bestaan (om "direct A-finale" te detecteren).
    const rondesPerDc = new Map();
    for (const r of ritten) {
        if (!rondesPerDc.has(r.dc_id)) rondesPerDc.set(r.dc_id, new Set());
        rondesPerDc.get(r.dc_id).add(r.ronde_type);
    }
    const isDirectFinaleDc = (dc_id) => {
        const s = rondesPerDc.get(dc_id);
        if (!s) return false;
        return s.has('finale_a')
            && !s.has('heats') && !s.has('kwartfinale') && !s.has('halve_finale');
    };
    // Verdeel actueel-deelnemers over de heats per (dc_id × ronde_type):
    //   floor(actueel / n) per heat, eerste `rest` heats krijgen +1.
    //   Bv. 11 / 6 → [2, 2, 2, 2, 2, 1]. Operator ziet meteen welke heat
    //   kandidaat is om te schrappen.
    // Per rit: Map<rit.id, actueel_in_die_heat>. Per groep-key: totaal.
    const _verdeelKey = (r) => `${r.dc_id}|${r.ronde_type}`;
    const _ritsPerKey = new Map();
    for (const r of ritten) {
        const k = _verdeelKey(r);
        if (!_ritsPerKey.has(k)) _ritsPerKey.set(k, []);
        _ritsPerKey.get(k).push(r);
    }
    // Eerste ronde + doorstroom-ronde per DC. De EERSTE ronde is waar alle
    // entries instromen — kan series (heats) zijn, of (als de operator
    // besluit te skippen) direct KF of HF. De DOORSTROOM-ronde is wat
    // erna komt: het verschil tussen entries en doorstroom-slots = RU.
    //
    // Bv. cascade series (19) → KF (16) → HF (8) → AF (4):
    //     eerste = series, doorstroom = KF, RU = 19 − 16 = 3
    // Cascade KF (12) → HF (8) → AF (4) (geen series):
    //     eerste = KF, doorstroom = HF, RU = 12 − 8 = 4
    const _cascadeOrder = ['heats','kwartfinale','halve_finale','finale_a'];
    const sumVerwacht = (dc_id, type) =>
        ritten.filter(r => r.dc_id === dc_id && r.ronde_type === type)
              .reduce((sum, r) => sum + (parseInt(r.verwacht) || 0), 0);
    const slotsDoorstroom = (dc_id) => {
        const s = rondesPerDc.get(dc_id);
        if (!s) return null;
        // Vind eerste cascade-ronde die bestaat.
        const eersteIdx = _cascadeOrder.findIndex(rt => s.has(rt));
        if (eersteIdx < 0) return null;
        // Doorstroom = eerstvolgende cascade-ronde NA de eerste.
        for (let i = eersteIdx + 1; i < _cascadeOrder.length; i++) {
            if (s.has(_cascadeOrder[i])) return sumVerwacht(dc_id, _cascadeOrder[i]);
        }
        return null;
    };
    const actueelPerRit       = new Map();  // rit.id → aantal
    const actueelTotPerDcRonde = new Map(); // 'dc_id|ronde_type' → totaal actueel

    // Helper: verdeel een totaal over n heats en sla op per rit.
    const _verdeelOpslaan = (rs, totaal, gKey) => {
        actueelTotPerDcRonde.set(gKey, totaal);
        const n    = rs.length;
        const base = Math.floor(totaal / n);
        const rest = totaal - base * n;
        rs.forEach((r, i) => actueelPerRit.set(r.id, i < rest ? base + 1 : base));
    };

    // ── PASS 1: cascade-rondes (heats/KF/HF) + DIRECT A-finale ─────────
    // Verdeel-input volgt de doorstroom-keten uit cat_config:
    //   heats        : cat.n (alle entries stromen in)
    //   kwartfinale  : heats_q (indien series) of cat.n
    //   halve_finale : kwart_door / heats_q / cat.n (afhankelijk van keten)
    //   direct-A     : cat.n
    // Zonder deze correctie zou een halve finale met bv 4 doorstromers
    // over 2 heats verdeeld worden als 7/2 = 4+3 i.p.v. 4/2 = 2+2.
    const _catCfgMap = new Map();
    for (const cc of (huidigTijdschema?.cat_configs ?? [])) {
        _catCfgMap.set(cc.dc_id + '|' + (cc.distance_id ?? ''), cc);
    }
    const _distanceIdVanRit = (r) => r.distance_id ?? '';
    for (const [k, rs] of _ritsPerKey) {
        const dc_id      = rs[0].dc_id;
        const ronde_type = rs[0].ronde_type;
        const isCascadeRonde = ['heats','kwartfinale','halve_finale'].includes(ronde_type);
        const isDirectA      = ronde_type === 'finale_a' && isDirectFinaleDc(dc_id);
        if (!isCascadeRonde && !isDirectA) continue;
        const catN = actueelPerDc.get(dc_id);
        if (catN == null) continue;
        const cc = _catCfgMap.get(dc_id + '|' + _distanceIdVanRit(rs[0]));
        let totaal;
        if (ronde_type === 'kwartfinale') {
            totaal = cc && Number(cc.heeft_heats) ? Math.max(0, parseInt(cc.heats_q) || 0) : catN;
        } else if (ronde_type === 'halve_finale') {
            if (cc && Number(cc.heeft_kwartfinale)) {
                totaal = Math.max(0, parseInt(cc.kwart_door) || 0);
            } else if (cc && Number(cc.heeft_heats)) {
                totaal = Math.max(0, parseInt(cc.heats_q) || 0);
            } else {
                totaal = catN;
            }
        } else {
            // heats of direct-A
            totaal = catN;
        }
        _verdeelOpslaan(rs, totaal, k);
    }

    // ── PASS 2: runner-up = totaal − doorstroom-slots ──────────────────
    for (const [k, rs] of _ritsPerKey) {
        if (rs[0].ronde_type !== 'runner_up') continue;
        const dc_id  = rs[0].dc_id;
        const totaal = actueelPerDc.get(dc_id);
        const slots  = slotsDoorstroom(dc_id);
        if (totaal == null || slots == null) continue;
        const ruActu = Math.max(0, totaal - slots);
        _verdeelOpslaan(rs, ruActu, k);
    }

    // ── Hulpstructuren ───────────────────────────────────────────────────────────
    // Heat-duur per ronde-blok (voor tijdberekening per rit)
    const heatDuurMap = new Map(
        (blokken ?? []).filter(b => b.blok_type === 'ronde')
                       .map(b => [parseInt(b.id), parseInt(b.heat_duur) || 0])
    );
    // Non-ronde blokken gesorteerd op volgorde (pauze, inrijden, wsstart, ceremonie)
    const nonRondeBlokken = (blokken ?? [])
        .filter(b => b.blok_type !== 'ronde')
        .sort((a, b) => (parseInt(a.volgorde) || 0) - (parseInt(b.volgorde) || 0));
    // Volgorde van elk ronde-blok: blok_id → blok.volgorde
    const rondeBlokVolgorde = new Map(
        (blokken ?? []).filter(b => b.blok_type === 'ronde')
                       .map(b => [parseInt(b.id), parseInt(b.volgorde) || 0])
    );

    // ── Bouw rijen: ritten in hun actuele volgorde, non-ronde blokken tussengeschoven ──
    // Ritten komen al gesorteerd op volgorde van de API.
    // Non-ronde blokken worden ingevoegd op basis van hun volgorde t.o.v. de ronde-blokken.
    const rijen = [];
    let nrbIdx = 0;
    for (const r of (ritten ?? [])) {
        const rBV = rondeBlokVolgorde.get(parseInt(r.blok_id)) ?? 0;
        while (nrbIdx < nonRondeBlokken.length &&
               (parseInt(nonRondeBlokken[nrbIdx].volgorde) || 0) <= rBV) {
            const nb = nonRondeBlokken[nrbIdx++];
            rijen.push({ type: nb.blok_type, blok: nb });
        }
        rijen.push({ type: 'rit', rit: r });
    }
    // Resterende non-ronde blokken na de laatste rit (bv. ceremonie aan het einde)
    while (nrbIdx < nonRondeBlokken.length) {
        rijen.push({ type: nonRondeBlokken[nrbIdx].blok_type, blok: nonRondeBlokken[nrbIdx++] });
    }

    // ── Multi-day: bepaal dag-tabs en filter rijen per dag ───────────────────
    // Meerdere wedstrijdstart-blokken = meerdaags evenement. Elk rij krijgt
    // een dagNr toegewezen op basis van de positie t.o.v. wedstrijdstart-
    // blokken. Tabs verschijnen alleen bij multi-day; bij 1 wsstart blijft
    // het gedrag identiek aan voorheen.
    const wsBlokkenSorted = (blokken ?? [])
        .filter(b => b.blok_type === 'wedstrijdstart')
        .sort((a, b) => (parseInt(a.volgorde) || 0) - (parseInt(b.volgorde) || 0));
    const isMultiDag = wsBlokkenSorted.length > 1;
    let dagLabels   = [];
    let actieveDag  = 1;
    const dagPerRij = new Map();
    // Geclaimde inrijd/pauze-blokken vóór een wedstrijdstart (multi-day).
    // Beschikbaar voor de tijdberekening verderop: achterwaarts plaatsen
    // i.p.v. voorwaarts vanaf vorige dag.
    const geclaimdVoorWs   = new Map(); // wsstart-blok-id (int) → [geclaimde blokken, chronologisch]
    const geclaimdeBlokIds = new Set(); // parseInt(blok.id) van alle geclaimde
    if (isMultiDag) {
        dagLabels = wsBlokkenSorted.map((ws, i) => {
            const datum    = ws.datum ? new Date(ws.datum + 'T00:00:00') : null;
            const datumStr = datum
                ? datum.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' })
                : '';
            return {
                nr:       i + 1,
                label:    `Dag ${i+1}${datumStr ? ' — ' + datumStr : ''}`,
                datum:    ws.datum || null,
                volgorde: parseInt(ws.volgorde) || 0,
            };
        });
        // Bepaal default actieveDag: cached → vandaag → dag 1
        if (_tsActieveDag >= 1 && _tsActieveDag <= dagLabels.length) {
            actieveDag = _tsActieveDag;
        } else {
            const vandaagStr = new Date().toISOString().substring(0, 10); // YYYY-MM-DD lokale-tijd-prox
            const match = dagLabels.find(d => d.datum === vandaagStr);
            actieveDag      = match ? match.nr : 1;
            _tsActieveDag   = actieveDag;
        }
        // Bepaal dag-nr per blok-id. Eerst standaard "laatste wsBlok-
        // volgorde <= blok-volgorde", daarna een override-pas: inrijden +
        // pauze direct vóór een wedstrijdstart horen bij de OPVOLGENDE dag
        // (= warm-up voor die wedstrijdstart, niet napauze van vorige dag).
        const blokDagMap = new Map();  // parseInt(blok.id) → dagNr
        (blokken ?? []).forEach(b => {
            const vol = parseInt(b.volgorde) || 0;
            let dagNr = 1;
            for (const d of dagLabels) {
                if (d.volgorde <= vol) dagNr = d.nr;
            }
            blokDagMap.set(parseInt(b.id), dagNr);
        });
        // Override: voor elke wedstrijdstart, claim direct-voorafgaande
        // inrijden/pauze-blokken (in omgekeerde volgorde, stop bij eerste
        // ander blok-type — bv. ronde of ceremonie blijven bij vorige dag).
        // Vul tegelijk geclaimdVoorWs + geclaimdeBlokIds voor de tijdbe-
        // rekening: die plaatst geclaimde blokken achterwaarts vanaf hun
        // wsstart i.p.v. voorwaarts vanaf de vorige dag.
        const blokkenSorted = (blokken ?? []).slice()
            .sort((a, b) => (parseInt(a.volgorde) || 0) - (parseInt(b.volgorde) || 0));
        for (let i = 0; i < blokkenSorted.length; i++) {
            const b = blokkenSorted[i];
            if (b.blok_type !== 'wedstrijdstart') continue;
            const wsId  = parseInt(b.id);
            const dagNr = blokDagMap.get(wsId);
            if (!dagNr) continue;
            const claimedList = [];
            for (let j = i - 1; j >= 0; j--) {
                const vb = blokkenSorted[j];
                if (vb.blok_type === 'inrijden' || vb.blok_type === 'pauze') {
                    blokDagMap.set(parseInt(vb.id), dagNr);
                    claimedList.unshift(vb); // chronologische volgorde
                    geclaimdeBlokIds.add(parseInt(vb.id));
                } else {
                    break;
                }
            }
            if (claimedList.length) geclaimdVoorWs.set(wsId, claimedList);
        }
        // Tag elke rij met haar dagNr via blokDagMap
        rijen.forEach(rij => {
            let dagNr;
            if (rij.type === 'rit') {
                dagNr = blokDagMap.get(parseInt(rij.rit.blok_id)) ?? 1;
            } else {
                dagNr = blokDagMap.get(parseInt(rij.blok?.id)) ?? 1;
            }
            dagPerRij.set(rij, dagNr);
        });
    }

    // ── Bereken fictieve starttijden ────────────────────────────────────────────
    // Tijdberekening in seconden; display afgerond op minuten (HH:MM)
    const secNaarTijd = (sec) => {
        const s0 = Math.round(parseInt(sec) || 0);
        const h  = Math.floor(s0 / 3600) % 24;
        const m  = Math.floor((s0 % 3600) / 60);
        return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
    };

    const startTijdMap   = new Map(); // rit.id  → 'HH:MM'
    const startRawSecMap = new Map(); // rit.id  → seconden (voor rusttijd-berekening)
    const blokTijdMap    = new Map(); // blok.id → 'HH:MM'

    const wsBlok = (blokken ?? []).find(b => b.blok_type === 'wedstrijdstart' && b.tijdstip);
    if (wsBlok) {
        const delen  = wsBlok.tijdstip.split(':').map(Number);
        const wsSec  = (delen[0] || 0) * 3600 + (delen[1] || 0) * 60;

        // ── Voorwaarts: vanaf wedstrijdstart via rijen ────────────────────────
        let cur = wsSec;
        let started = false;
        // Combi: als een rit dezelfde combi_group heeft als de vorige, krijgt 'ie
        // dezelfde starttijd en wordt cur NIET verhoogd (ze rijden tegelijk in
        // één fysieke heat).
        let prevRitCombi = null;
        let prevRitCurSec = null;
        for (const rij of rijen) {
            if (rij.type === 'wedstrijdstart') {
                // Multi-day: elk wedstrijdstart-blok reset de tijdrekening
                // naar het ingestelde tijdstip. Eerste wedstrijdstart heeft
                // wsSec al (zie hierboven); 2e+ pakken hun eigen tijdstip.
                // Geen tijdstip → cur blijft staan (gedrag van vóór multi-day).
                if (rij.blok.tijdstip) {
                    const d = rij.blok.tijdstip.split(':').map(Number);
                    cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                }
                // Multi-day: geclaimde inrijd/pauze direct vóór deze wsstart
                // krijgen tijd ACHTERWAARTS vanaf cur (= wsstart-tijdstip)
                // i.p.v. voorwaarts vanaf vorige dag. Resultaat: bv. 09:30
                // inrijden, 09:50 pauze, 10:00 wsstart.
                const wsId = parseInt(rij.blok.id);
                if (geclaimdVoorWs.has(wsId)) {
                    const claimed = geclaimdVoorWs.get(wsId);
                    let backSec = cur;
                    for (let k = claimed.length - 1; k >= 0; k--) {
                        const cb = claimed[k];
                        backSec -= (parseInt(cb.duur) || 0) * 60;
                        blokTijdMap.set(cb.id, secNaarTijd(backSec));
                    }
                }
                blokTijdMap.set(rij.blok.id, secNaarTijd(cur));
                started = true;
                prevRitCombi = null;
            } else if (started) {
                if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                    // Multi-day: geclaimde blokken hebben hun tijd al
                    // achterwaarts gekregen bij hun wsstart. Sla over in
                    // voorwaartse berekening (cur niet ophogen, tijd niet
                    // overschrijven).
                    if (geclaimdeBlokIds.has(parseInt(rij.blok.id))) {
                        prevRitCombi = null;
                        continue;
                    }
                    blokTijdMap.set(rij.blok.id, secNaarTijd(cur));
                    cur += (parseInt(rij.blok.duur) || 0) * 60;
                    prevRitCombi = null;
                } else if (rij.type === 'herstart') {
                    if (rij.blok.tijdstip) {
                        const d = rij.blok.tijdstip.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                    }
                    blokTijdMap.set(rij.blok.id, secNaarTijd(cur));
                    prevRitCombi = null;
                } else if (rij.type === 'rit') {
                    const combiGrp    = rij.rit.combi_group ? parseInt(rij.rit.combi_group) : null;
                    const zelfdeCombi = combiGrp !== null && combiGrp === prevRitCombi;

                    if (rij.rit.tijdstip_override) {
                        const d = rij.rit.tijdstip_override.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                        startTijdMap.set(rij.rit.id, secNaarTijd(cur));
                        startRawSecMap.set(rij.rit.id, cur);
                        cur += heatDuurMap.get(parseInt(rij.rit.blok_id)) || 0;
                        prevRitCurSec = cur - (heatDuurMap.get(parseInt(rij.rit.blok_id)) || 0);
                    } else if (zelfdeCombi) {
                        // Combi-member: zelfde tijd als leider, cur blijft staan
                        startTijdMap.set(rij.rit.id, secNaarTijd(prevRitCurSec));
                        startRawSecMap.set(rij.rit.id, prevRitCurSec);
                    } else {
                        startTijdMap.set(rij.rit.id, secNaarTijd(cur));
                        startRawSecMap.set(rij.rit.id, cur);
                        prevRitCurSec = cur;
                        cur += heatDuurMap.get(parseInt(rij.rit.blok_id)) || 0;
                    }
                    prevRitCombi = combiGrp;
                }
            }
        }

        // ── Achterwaarts: blokken vóór de wedstrijdstart ──────────────────────
        const wsRijIdx = rijen.findIndex(r => r.type === 'wedstrijdstart');
        let back = wsSec;
        for (let i = wsRijIdx - 1; i >= 0; i--) {
            const rij = rijen[i];
            if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                back -= (parseInt(rij.blok.duur) || 0) * 60;
                blokTijdMap.set(rij.blok.id, secNaarTijd(back));
            }
        }
    }

    const heeftStartTijden = startTijdMap.size > 0 || blokTijdMap.size > 0;

    // ── Rusttijden per categorie (logische ronde-keten) ───────────────────────
    // Keten: heats → kwartfinale → halve_finale → finale(_b)
    //        heats → runner_up
    // Rusttijd = firstSec(volgende ronde) - lastEndSec(vorige ronde)
    const rustTijdMap = new Map(); // groepKey → rusttijd in seconden

    if (startRawSecMap.size > 0) {
        // Rust-calckey: dc_id + distance_id + ronde_type (ZONDER blok_id)
        // - distance_id onderscheidt 500m van 1000m binnen dezelfde categorie (dc_id)
        // - blok_id weglaten zodat cross-blok gesleepte heats toch bij hun ronde horen
        const calcGroepen  = new Map(); // ck → { catKey, rondeType, firstSec, lastEndSec }
        const renderGkNaarCk = new Map(); // renderGroepKey → ck

        for (const rij of rijen) {
            if (rij.type !== 'rit') continue;
            const rit      = rij.rit;
            const sec      = startRawSecMap.get(rit.id);
            if (sec === undefined) continue;

            const catKey   = `${rit.dc_id ?? ''}|${rit.distance_id ?? ''}|${rit.dc_naam ?? ''}`;  // uniek per cat+afstand+split
            const ck       = `${catKey}:${rit.ronde_type}`;
            const renderGk = `${rit.blok_id ?? ''}:${rit.dc_id}:${rit.dc_naam ?? ''}:${rit.ronde_type}`;
            const heatDuur = heatDuurMap.get(parseInt(rit.blok_id)) || 0;
            const endSec   = sec + heatDuur;

            if (!calcGroepen.has(ck)) {
                calcGroepen.set(ck, { catKey, rondeType: rit.ronde_type,
                                      firstSec: sec, lastEndSec: endSec });
            } else {
                const gt = calcGroepen.get(ck);
                if (sec    < gt.firstSec)   gt.firstSec   = sec;
                if (endSec > gt.lastEndSec) gt.lastEndSec = endSec;
            }
            renderGkNaarCk.set(renderGk, ck);
        }

        // catKey → Map(rondeType → ck)
        const catRondeMap = new Map();
        for (const [ck, gt] of calcGroepen) {
            if (!catRondeMap.has(gt.catKey)) catRondeMap.set(gt.catKey, new Map());
            catRondeMap.get(gt.catKey).set(gt.rondeType, ck);
        }

        // Logische voorgangers per ronde_type
        const VOORGANGERS = {
            kwartfinale:  ['heats'],
            halve_finale: ['kwartfinale', 'heats'],
            finale_b:     ['halve_finale', 'kwartfinale', 'heats'],
            finale:       ['halve_finale', 'kwartfinale', 'heats'],
            finale_a:     ['halve_finale', 'kwartfinale', 'heats'],
            runner_up:    ['heats'],
        };

        // Rusttijden berekenen per catKey+ronde, dan vertalen naar renderGroepKey
        const rustTijdByCk = new Map(); // ck → seconden
        for (const [catKey, rondeMap] of catRondeMap) {
            for (const [rondeType, ck] of rondeMap) {
                const voorgangers = VOORGANGERS[rondeType];
                if (!voorgangers) continue;
                for (const vrt of voorgangers) {
                    const vorigeCk = rondeMap.get(vrt);
                    if (!vorigeCk) continue;
                    const prev = calcGroepen.get(vorigeCk);
                    const curr = calcGroepen.get(ck);
                    if (prev && curr) rustTijdByCk.set(ck, curr.firstSec - prev.lastEndSec);
                    break;
                }
            }
        }

        // Vertalen naar rustTijdMap (renderGroepKey → seconden) voor de rendering
        for (const [renderGk, ck] of renderGkNaarCk) {
            if (rustTijdByCk.has(ck)) rustTijdMap.set(renderGk, rustTijdByCk.get(ck));
        }
    }

    const RUST_WARN_SEC = 30 * 60; // waarschuwingsgrens: 30 minuten

    // Bereken aantal heats per groep (blok_id:dc_id:dc_naam:ronde_type)
    const groepGrootte = {};
    rijen.forEach(r => {
        if (r.type === 'rit') {
            const gk = `${r.rit.blok_id ?? ''}:${r.rit.dc_id}:${r.rit.dc_naam ?? ''}:${r.rit.ronde_type}`;
            groepGrootte[gk] = (groepGrootte[gk] ?? 0) + 1;
        }
    });

    // Combineer-toolbar: voor full-final én internationaal, met schrijfrechten.
    // Beide systemen genereren losse finale_a-ritten; combineren is puur visueel.
    const combiSysteemTop = ['full-final', 'internationaal-nieuw'].includes(huidigTijdschema?.systeem ?? '');
    const combineerToolbar = (combiSysteemTop && !_tsLeesOnly)
        ? `<div class="ts-combi-toolbar" id="ts-combi-toolbar">
               <span class="ts-combi-toolbar-hint">🔗 Selecteer 2–4 opeenvolgende A-finale ritten om te combineren in het programma</span>
               <button class="btn-primary ts-btn-combi" id="ts-btn-combi" disabled>Combineer selectie (<span id="ts-combi-count">0</span>)</button>
           </div>`
        : '';

    // Multi-day tabs (alleen bij >1 wedstrijdstart). Click-handler wordt
    // gebonden in bindTsEvents — bij click wordt _tsActieveDag bijgewerkt
    // en het tijdschema opnieuw gerenderd.
    const dagTabsHtml = isMultiDag
        ? `<div class="ts-dag-tabs" role="tablist" aria-label="Wedstrijddag">${
              dagLabels.map(d =>
                  `<button class="org-tab-btn ts-dag-tab${d.nr === actieveDag ? ' active' : ''}"`
                  + ` data-dag="${d.nr}" role="tab"`
                  + ` aria-selected="${d.nr === actieveDag ? 'true' : 'false'}">${escHtml(d.label)}</button>`
              ).join('')
          }</div>`
        : '';

    let html = `<div class="ts-ritten-wrap">
        ${dagTabsHtml}
        <div class="ts-ritten-hint">
            <span>Sleep <span class="ts-drag-handle ts-drag-handle-inline">⠿</span> om een complete categoriegroep te verplaatsen.</span>
            <button type="button" class="ts-btn-sm ts-klap-toggle-all" data-actie="alles-in"  title="Klap alle cat-groepen in (alleen kopjes)">▶ Alles inklappen</button>
            <button type="button" class="ts-btn-sm ts-klap-toggle-all" data-actie="alles-uit" title="Klap alle cat-groepen uit">▼ Alles uitklappen</button>
        </div>
        ${combineerToolbar}
        <table class="ts-ritten-tabel">
            <thead><tr>${heeftStartTijden ? '<th>Tijd</th>' : ''}<th>#</th><th>Rit</th><th>Type</th><th>Verwacht</th></tr></thead>
            <tbody>`;

    let ritNr = 0;

    // restCols = kolommen BEHALVE de tijdkolom (voor colspan in pauze/wsstart/etc.)
    const restCols = 4;
    const tijdCel = (blokId, extraClass = '') =>
        heeftStartTijden
            ? `<td class="ts-rit-startijd${extraClass ? ' ' + extraClass : ''}">${blokTijdMap.get(blokId) ?? '—'}</td>`
            : '';

    let prevGroepKey = null;

    rijen.forEach((rij, idx) => {
        // Multi-day: alleen rijen van de actieve dag tonen. Rij-nummering
        // (ritNr) wordt nog steeds globaal opgehoogd voor non-rit rijen, maar
        // voor ritten alleen geteld als ze ge-renderd worden (zie verderop).
        if (isMultiDag && dagPerRij.get(rij) !== actieveDag) {
            // We slaan wel ritNr op: ritten op andere dagen tellen niet mee
            // voor de nummering binnen de actieve dag. Operator-perceptie:
            // 'Rit #1' op dag 2 is rit 1 van die dag, niet globaal-rit 25.
            return;
        }
        if (rij.type === 'wedstrijdstart') {
            prevGroepKey = null;
            const tijdstip = rij.blok?.tijdstip ? rij.blok.tijdstip.substring(0,5) : '—';
            html += `<tr class="ts-wsstart-rij">
                ${tijdCel(rij.blok.id, 'ts-wsstart-tijd')}
                <td colspan="${restCols}" class="ts-wsstart-cel">🏁 Wedstrijd start — <strong>${escHtml(tijdstip)}</strong></td>
            </tr>`;
        } else if (rij.type === 'pauze') {
            prevGroepKey = null;
            const duurTxt = rij.blok?.duur ? ` – ${rij.blok.duur} min` : '';
            const opmTxt  = rij.blok?.opmerking ? ` — ${escHtml(rij.blok.opmerking)}` : '';
            html += `<tr class="ts-pauze-rij">
                ${tijdCel(rij.blok.id)}
                <td colspan="${restCols}" class="ts-pauze-cel">⏸ Pauze${escHtml(duurTxt)}${opmTxt}</td>
            </tr>`;
        } else if (rij.type === 'inrijden') {
            prevGroepKey = null;
            const duurTxt = rij.blok?.duur ? ` – ${rij.blok.duur} min` : '';
            const cats    = (() => { try { return JSON.parse(rij.blok?.inrijd_cats || '[]'); } catch(e) { return []; } })();
            const catNamen = cats.map(dcId => escHtml(dcNaamMap.get(dcId) ?? dcId));
            const catsTxt = catNamen.length ? ` — ${catNamen.join(', ')}` : '';
            html += `<tr class="ts-inrijd-rij">
                ${tijdCel(rij.blok.id)}
                <td colspan="${restCols}" class="ts-inrijd-cel">🛼 Inrijden${escHtml(duurTxt)}${catsTxt}</td>
            </tr>`;
        } else if (rij.type === 'ceremonie') {
            prevGroepKey = null;
            const duurTxt = rij.blok?.duur ? ` – ${rij.blok.duur} min` : '';
            const opmTxt  = rij.blok?.opmerking ? ` — ${escHtml(rij.blok.opmerking)}` : '';
            html += `<tr class="ts-cerem-rij">
                ${tijdCel(rij.blok.id)}
                <td colspan="${restCols}" class="ts-cerem-cel">🏆 Ceremonie${escHtml(duurTxt)}${opmTxt}</td>
            </tr>`;
        } else if (rij.type === 'herstart') {
            prevGroepKey = null;
            const tijdstip = rij.blok?.tijdstip ? rij.blok.tijdstip.substring(0,5) : '—';
            const opmTxt   = rij.blok?.opmerking ? ` — ${escHtml(rij.blok.opmerking)}` : '';
            html += `<tr class="ts-herstart-rij">
                ${tijdCel(rij.blok.id, 'ts-herstart-tijd')}
                <td colspan="${restCols}" class="ts-herstart-cel">🔄 Herstart — <strong>${escHtml(tijdstip)}</strong>${opmTxt}</td>
            </tr>`;
        } else {
            const rit      = rij.rit;
            const groepKey = `${rit.blok_id ?? ''}:${rit.dc_id}:${rit.dc_naam ?? ''}:${rit.ronde_type}`;
            const kleur    = TS_RONDE_KLEUR[rit.ronde_type] ?? '#adb5bd';
            // B-finales: toon 'B2-finale' i.p.v. 'B-finale B2'
            const label = (rit.ronde_type === 'finale_b' && rit.finale_label)
                ? rit.finale_label + '-finale'
                : (TS_RONDE_LABEL[rit.ronde_type] ?? rit.ronde_type);
            const fin = (rit.ronde_type === 'finale_b')
                ? ''
                : (rit.finale_label ? ` ${rit.finale_label}` : '');

            // ── Groepsheader (één per dc + ronde_type combinatie) ──────────────
            if (groepKey !== prevGroepKey) {
                prevGroepKey = groepKey;
                const n    = groepGrootte[groepKey] ?? 1;
                // Actueel aantal deelnemers voor deze groep (op basis van DC
                // van de eerste rit + ronde-type filter). Voor heats/KF/HF: toon
                // het DC-totaal — daar is afmelding direct relevant voor "kan
                // een rit minder". Voor finales niet (finale-grootte komt uit
                // cat_config en hangt af van cascade, niet 1-op-1 op afmeldingen).
                // Actueel deelnemertotaal: alleen tonen waar we 't betrouwbaar
                // kunnen verdelen — heats/KF/HF + direct A-finale-cats. Voor
                // cascade-finales (na series), B-finales en runner-up niet,
                // want hun aantal hangt af van wie doorstroomt, niet van
                // afmeldingen alleen.
                let deelnTxt = '';
                const _grKey = `${rit.dc_id}|${rit.ronde_type}`;
                const actueelTot = actueelTotPerDcRonde.get(_grKey);
                if (actueelTot != null) {
                    deelnTxt = ` · <span class="ts-groep-deeln">${actueelTot} deelnemers</span>`;
                }
                const nTxt = (n === 1 ? '1 rit' : `${n} heats`) + deelnTxt;
                const aantalCols = heeftStartTijden ? 5 : 4;
                const tijdInhoud = heeftStartTijden
                    ? `<span class="ts-groep-tijd">${startTijdMap.get(rit.id) ?? '—'}</span>`
                    : '';

                // Rusttijd tonen als we tijden hebben én er een vorige ronde is
                let rustHtml = '';
                if (rustTijdMap.has(groepKey)) {
                    const rustSec  = rustTijdMap.get(groepKey);
                    const rustMin  = Math.round(rustSec / 60);
                    const isWarn   = rustSec < RUST_WARN_SEC;
                    const icon     = isWarn ? '⚠\uFE0F' : '✓';
                    rustHtml = `<span class="ts-rust-tijd${isWarn ? ' ts-rust-warn' : ''}"
                                      title="Rusttijd na vorige ronde van deze categorie"
                                >${icon} rust ${rustMin} min</span>`;
                }

                const isInge = _tsIngeklapteGroepen.has(groepKey);
                html += `<tr class="ts-rit-groep-hdr${isInge ? ' ts-groep-ingeklapt' : ''}" draggable="true"
                            data-groep-key="${escHtml(groepKey)}">
                    <td colspan="${aantalCols}" class="ts-groep-hdr-td">
                        <div class="ts-groep-hdr-cel">
                            ${tijdInhoud}
                            <span class="ts-drag-handle">⠿</span>
                            <button type="button" class="ts-groep-toggle" title="Klap heats in/uit" aria-expanded="${isInge ? 'false' : 'true'}">${isInge ? '▶' : '▼'}</button>
                            <span class="ts-type-badge" style="background:${kleur}">${escHtml(label)}${escHtml(fin)}</span>
                            ${escHtml(rit.dc_naam)}
                            <span class="ts-groep-count">(${nTxt})</span>
                            ${rustHtml}
                        </div>
                    </td>
                </tr>`;
            }

            // ── Individuele heat-rij ───────────────────────────────────────────
            ritNr++;

            // Combi-logica: ritten met dezelfde combi_group worden visueel
            // samengevoegd. De eerste (laagste volgorde) is de "leider" en toont
            // het ritnummer; volgende leden verbergen hun nummer.
            const combiGrp   = rit.combi_group ? parseInt(rit.combi_group) : null;
            const prevRit    = idx > 0 ? rijen[idx - 1].rit : null;
            const prevCombi  = prevRit?.combi_group ? parseInt(prevRit.combi_group) : null;
            const nextRij    = rijen[idx + 1];
            const nextRit    = nextRij?.type === 'rit' ? nextRij.rit : null;
            const nextCombi  = nextRit?.combi_group ? parseInt(nextRit.combi_group) : null;
            const isCombi    = combiGrp !== null;
            const isCombiLeider = isCombi && combiGrp !== prevCombi;
            const isCombiEnd    = isCombi && combiGrp !== nextCombi;
            let combiCls = '';
            if (isCombi) {
                combiCls = 'ts-combi';
                if (isCombiLeider) combiCls += ' ts-combi-start';
                if (isCombiEnd)    combiCls += ' ts-combi-end';
                if (!isCombiLeider && !isCombiEnd) combiCls += ' ts-combi-mid';
            }

            const hasOverride  = !!rit.tijdstip_override;
            const ovTijdVal    = hasOverride ? escHtml(rit.tijdstip_override.substring(0, 5)) : '';
            const ovOpmVal     = rit.opmerking ? escHtml(rit.opmerking) : '';
            const startTijdTxt = startTijdMap.get(rit.id) ?? '—';
            let tijdCelHtml = '';
            if (heeftStartTijden) {
                if (!_tsLeesOnly) {
                    tijdCelHtml = `<td class="ts-rit-startijd${hasOverride ? ' ts-rit-startijd-override' : ''} ts-rit-tijd-klik"
                        data-rit-id="${rit.id}" data-override="${ovTijdVal}" data-opm="${ovOpmVal}"
                        title="${hasOverride ? 'Override actief — klik om te wijzigen' : 'Klik om starttijd vast te leggen'}"
                        >${startTijdTxt}${hasOverride ? '&nbsp;📌' : ''}</td>`;
                } else {
                    tijdCelHtml = `<td class="ts-rit-startijd${hasOverride ? ' ts-rit-startijd-override' : ''}">${startTijdTxt}${hasOverride ? '&nbsp;📌' : ''}</td>`;
                }
            }
            const verbergCls = _tsIngeklapteGroepen.has(groepKey) ? ' ts-groep-verborgen' : '';
            if (ovOpmVal) {
                html += `<tr class="ts-rit-opm-rij${verbergCls}" data-groep-key="${escHtml(groepKey)}">
                    ${heeftStartTijden ? '<td></td>' : ''}
                    <td></td>
                    <td colspan="3" class="ts-rit-opm-cel">📝 ${ovOpmVal}</td>
                </tr>`;
            }
            // Combi-eligibility voor UI:
            //   - DIRECT A-finales (cats zonder series/KF/HF) → combineerbaar
            //   - Runner-up                                    → combineerbaar
            //   - Cascade A-finales (na series/KF/HF)          → NIET — die
            //     krijgen hun deelnemers via cascade-doorstroom; combineren
            //     zou uitslag/doorstroom kunnen verstoren.
            // Combi is puur OPTISCH (= "rijden tegelijk"), loting blijft per
            // cat apart.
            const combiSysteem  = ['full-final', 'internationaal-nieuw'].includes(huidigTijdschema?.systeem ?? '');
            const isDirectAFin  = rit.ronde_type === 'finale_a' && isDirectFinaleDc(rit.dc_id);
            const combiEligible = combiSysteem
                                && !_tsLeesOnly
                                && (isDirectAFin || rit.ronde_type === 'runner_up');

            // Ritnummer: leider toont nummer + 🔗, middle/end leden tonen niks
            let nrCelInhoud;
            if (isCombi && !isCombiLeider) {
                nrCelInhoud = '';
            } else if (isCombiLeider) {
                nrCelInhoud = `${ritNr} <button class="ts-combi-unlink" title="Ontkoppel"
                                   data-combi-group="${combiGrp}">🔗</button>`;
            } else if (combiEligible) {
                nrCelInhoud = `<label class="ts-combi-sel-lbl">
                                   <input type="checkbox" class="ts-combi-sel"
                                          data-rit-id="${rit.id}"
                                          data-volgorde="${rit.volgorde}">
                                   <span class="ts-rit-nr-inner">${ritNr}</span>
                               </label>`;
            } else {
                nrCelInhoud = String(ritNr);
            }

            // Prullenbakje + plus-knop: alleen in schrijf-modus. Plus-knop
            // werkt niet voor series-heats (server weigert die ook — zie
            // add_rit_kopie action).
            const verwijderBtn = !_tsLeesOnly
                ? `<button class="ts-rit-verwijder" data-rit-id="${rit.id}"
                            data-rit-naam="${escHtml(rit.rit_naam)}"
                            data-dc-naam="${escHtml(rit.dc_naam)}"
                            data-ronde-label="${escHtml(label)}${escHtml(fin)}"
                            title="Verwijder deze rit (heat + resultaten)">🗑</button>`
                : '';
            const kopieerBtn = !_tsLeesOnly && rit.ronde_type !== 'heats'
                ? `<button class="ts-rit-kopieer" data-rit-id="${rit.id}"
                            data-rit-naam="${escHtml(rit.rit_naam)}"
                            data-dc-naam="${escHtml(rit.dc_naam)}"
                            data-ronde-label="${escHtml(label)}${escHtml(fin)}"
                            title="Voeg een extra heat van dit type toe (cat_config wordt automatisch bijgewerkt)">+</button>`
                : '';
            html += `<tr class="ts-rit-rij ts-rit-sub${verbergCls} ${combiCls}" data-rit-id="${rit.id}"
                        data-groep-key="${escHtml(groepKey)}"
                        data-combi-group="${combiGrp ?? ''}"
                        data-ronde-type="${escHtml(rit.ronde_type)}">
                ${tijdCelHtml}
                <td class="ts-rit-nr">${nrCelInhoud}</td>
                <td class="ts-rit-naam">${escHtml(rit.rit_naam)}</td>
                <td><span class="ts-type-badge ts-type-badge-sm" style="background:${kleur}">${escHtml(label)}${escHtml(fin)}</span></td>
                <td class="ts-rit-verwacht">${actueelPerRit.has(rit.id) ? actueelPerRit.get(rit.id) : (rit.verwacht ?? '?')}${kopieerBtn}${verwijderBtn}</td>
            </tr>`;
        }
    });

    html += `</tbody></table></div>`;
    return html;
}

// ── Rit override opslaan ─────────────────────────────────────────────────────

async function saveRitOverride(ritId, tijdstip, opmerking, tsId) {
    try {
        const data = await postTs({
            action:              'save_rit_override',
            tijdschema_id:       tsId,
            competition_id:      huidigCompId,
            rit_id:              ritId,
            tijdstip_override:   tijdstip,
            opmerking:           opmerking,
            tijdschema_version:  tijdschemaVersion,
        });
        if (data?.tijdschema_version != null) tijdschemaVersion = data.tijdschema_version;
        huidigTijdschema = data;
        renderTijdschema();
    } catch(e) { toonBevestigDialog(e.message, 'Fout'); }
}

// ── Events ────────────────────────────────────────────────────────────────────

function bindTsEvents(afstandGroepen) {
    const container = el('ts-container');
    const tsId = parseInt(el('ts-schema-id')?.value ?? '0');

    // ── Systeem opslaan (met waarschuwing bij wijziging) ───────────────────────
    const slaOpSysteem = async (nieuwSysteem, reset) => {
        try {
            await postTs({ action: 'save_systeem', tijdschema_id: tsId,
                           systeem: nieuwSysteem, reset: reset ? 1 : 0 });
        } catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    };

    const updateSysteemSaveBtn = () => {
        const btn   = el('ts-btn-systeem-save');
        const gewijzigd = el('ts-systeem-sel')?.value !== huidigTijdschema?.systeem;
        if (!btn) return;
        btn.disabled = !gewijzigd;
        btn.classList.toggle('ts-btn-dirty', gewijzigd);
    };

    el('ts-systeem-sel')?.addEventListener('change', () => {
        const uitleg = el('ts-systeem-uitleg');
        if (uitleg) uitleg.innerHTML = renderSysteemUitleg(el('ts-systeem-sel').value);
        updateSysteemSaveBtn();
    });

    el('ts-btn-systeem-save')?.addEventListener('click', () => {
        const nieuw = el('ts-systeem-sel')?.value;
        if (!nieuw || nieuw === huidigTijdschema?.systeem) return;
        const heeftData = (huidigTijdschema?.afstand_configs?.length ?? 0) > 0
                       || (huidigTijdschema?.ritten?.length ?? 0) > 0;
        if (heeftData) {
            const warn = el('ts-systeem-waarschuwing');
            if (warn) { warn.style.display = ''; warn.dataset.systeem = nieuw; }
            return;
        }
        slaOpSysteem(nieuw, false);
    });

    el('ts-btn-systeem-bevestig')?.addEventListener('click', async () => {
        const warn = el('ts-systeem-waarschuwing');
        const nieuw = warn?.dataset.systeem;
        if (warn) warn.style.display = 'none';
        if (nieuw) await slaOpSysteem(nieuw, true);
    });

    el('ts-btn-systeem-annuleer')?.addEventListener('click', () => {
        el('ts-systeem-waarschuwing').style.display = 'none';
        const sel = el('ts-systeem-sel');
        if (sel) sel.value = huidigTijdschema?.systeem ?? '';
        updateSysteemSaveBtn();
    });

    // ── Afstand-panel openen/sluiten ──────────────────────────────────────────
    container.querySelectorAll('.ts-btn-bewerk').forEach(btn => {
        btn.addEventListener('click', () => {
            const naam   = btn.dataset.naam;
            const panelId = `ts-panel-${naam.replace(/[^a-z0-9]/gi, '_')}`;
            const panel  = document.getElementById(panelId);
            if (!panel) return;
            const isOpen = panel.style.display !== 'none';
            // Sluit alle open panels
            container.querySelectorAll('.ts-kaart-panel').forEach(p => { p.style.display = 'none'; });
            if (!isOpen) {
                panel.style.display = '';
                tsAfstandOpen = naam;
            } else {
                tsAfstandOpen = null;
            }
        });
    });

    container.querySelectorAll('.ts-btn-afstand-cancel').forEach(btn => {
        btn.addEventListener('click', () => {
            const panelId = `ts-panel-${btn.dataset.naam.replace(/[^a-z0-9]/gi, '_')}`;
            const panel   = document.getElementById(panelId);
            if (panel) panel.style.display = 'none';
            tsAfstandOpen = null;
        });
    });

    // ── Live: per-categorie heats checkbox ────────────────────────────────────
    // Hulpfunctie: visibility toggle voor tabelcellen (behoudt kolombreedtes)
    const setVis = (el, zichtbaar) => { el.style.visibility = zichtbaar ? '' : 'hidden'; };

    container.querySelectorAll('.ts-cb-heats').forEach(cb => {
        cb.addEventListener('change', () => {
            const tr = cb.closest('tr');
            // Doorgang, kwart-checkbox en half-checkbox: visibility
            tr.querySelectorAll('.ts-heats-velden, .ts-heats-q-cel').forEach(el => setVis(el, cb.checked));
            if (!cb.checked) {
                const kwartCb = tr.querySelector('.ts-cb-kwart');
                const halfCb  = tr.querySelector('.ts-cb-half');
                if (kwartCb) kwartCb.checked = false;
                if (halfCb)  halfCb.checked  = false;
                tr.querySelectorAll('.ts-kwart-velden, .ts-half-velden').forEach(el => setVis(el, false));
            }
            // Full-final: "Serie alleen startvolgorde" uit + disable als geen series
            const sasCb = tr.querySelector('.ts-cb-sas');
            if (sasCb) {
                if (!cb.checked) {
                    sasCb.checked  = false;
                    sasCb.disabled = true;
                } else {
                    const nh = parseInt(tr.querySelector('.ts-inp-heats-aantal')?.value) || 0;
                    sasCb.disabled = (nh !== 1);
                }
            }
            updateCalc(cb.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    container.querySelectorAll('.ts-cb-kwart').forEach(cb => {
        cb.addEventListener('change', () => {
            const tr = cb.closest('tr');
            tr.querySelectorAll('.ts-kwart-velden').forEach(el => setVis(el, cb.checked));
            if (cb.checked) {
                const hasSeries = tr.querySelector('[name="heeft_heats"]')?.checked ?? false;
                // Input kwartfinale: heats_q als er series zijn, anders totaal deelnemers
                const kwartIn = hasSeries
                    ? (parseInt(tr.querySelector('[name="heats_q"]')?.value) || 0)
                    : (parseInt(tr.dataset.n) || 0);
                const nKH   = parseInt(tr.querySelector('[name="kwart_heats"]')?.value) || 1;
                const span  = tr.querySelector('.ts-kwart-velden .ts-per-heat-cel');
                if (span) span.textContent = kwartIn > 0 ? berekenPerHeat(kwartIn, nKH) + '/h' : '—';
                // Sla kwartIn op in data-q zodat kwart-aantal input klopt
                const kwartAantalInp = tr.querySelector('.ts-inp-kwart-aantal');
                if (kwartAantalInp) kwartAantalInp.dataset.q = kwartIn;
            }
            updateCalc(cb.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    container.querySelectorAll('.ts-cb-half').forEach(cb => {
        cb.addEventListener('change', () => {
            const tr   = cb.closest('tr');
            const form = cb.closest('.ts-panel-form');
            tr.querySelectorAll('.ts-half-velden').forEach(el => setVis(el, cb.checked));
            if (cb.checked) {
                const hasSeries = tr.querySelector('[name="heeft_heats"]')?.checked ?? false;
                const hK        = tr.querySelector('[name="heeft_kwartfinale"]')?.checked ?? false;
                const kwartIn   = hasSeries
                    ? (parseInt(tr.querySelector('[name="heats_q"]')?.value) || 0)
                    : (parseInt(tr.dataset.n) || 0);
                const kDoor  = parseInt(tr.querySelector('[name="kwart_door"]')?.value) || 0;
                const nHHf   = parseInt(tr.querySelector('[name="half_heats"]')?.value) || 1;
                const halfIn = hK ? kDoor : kwartIn;
                const span   = tr.querySelector('.ts-half-velden .ts-per-heat-half');
                if (span) span.textContent = halfIn > 0 ? berekenPerHeat(halfIn, nHHf) + '/h' : '—';
                // Sla halfIn op in data-in zodat half-aantal input klopt
                const halfAantalInp = tr.querySelector('.ts-inp-half-aantal');
                if (halfAantalInp) halfAantalInp.dataset.in = halfIn;
            }
            updateCalc(form, afstandGroepen);
        });
    });

    // ── Live: heats-aantal preview per cel ────────────────────────────────────
    container.querySelectorAll('.ts-inp-heats-aantal').forEach(inp => {
        inp.addEventListener('input', () => {
            const span = inp.closest('td')?.querySelector('.ts-per-heat-cel');
            const n    = parseInt(inp.dataset.n) || 0;
            const nh   = parseInt(inp.value) || 0;
            if (span) span.textContent = n > 0 && nh > 0 ? berekenPerHeat(n, nh) + '/h' : '—';

            // Full-final: "Serie alleen startvolgorde" alleen enabled bij 1 heat
            const sasCb = inp.closest('tr')?.querySelector('.ts-cb-sas');
            if (sasCb) {
                if (nh === 1) {
                    sasCb.disabled = false;
                } else {
                    sasCb.checked  = false;
                    sasCb.disabled = true;
                }
            }

            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // ── Live: FF A-finale / B-heats preview per cel ──────────────────────────
    container.querySelectorAll('.ts-inp-finale-a, .ts-inp-finale-bh').forEach(inp => {
        inp.addEventListener('input', () => {
            herberekenFinalePreview(inp.closest('tr'));
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // ── Live: overige invoer → overzicht bijwerken ───────────────────────────
    // Runner-up vinkje: toon/verberg ALLE max/min-per-heat rijen
    container.querySelectorAll('.ts-cb-runner-up').forEach(cb => {
        cb.addEventListener('change', () => {
            const form = cb.closest('.ts-panel-form');
            form?.querySelectorAll('.ts-ru-max-rij').forEach(rij => {
                rij.style.display = cb.checked ? '' : 'none';
                if (!cb.checked) {
                    // Reset waarden naar 0 bij uitvinken
                    rij.querySelectorAll('input[type="number"]').forEach(inp => inp.value = 0);
                }
            });
            updateCalc(form, afstandGroepen);
        });
    });

    // ── Hulp: herbereken +Xq span voor kwartfinale ───────────────────────────
    const updateKwartQSpan = tr => {
        const kDoor  = parseInt(tr?.querySelector('[name="kwart_door"]')?.value)    || 0;
        const kQH    = parseInt(tr?.querySelector('[name="kwart_q_heat"]')?.value)  || 0; // 0 is geldig
        const nKH    = parseInt(tr?.querySelector('[name="kwart_heats"]')?.value)   || 1;
        const qaSpan = tr?.querySelector('.ts-kwart-velden .ts-q-afgeleid');
        if (qaSpan) qaSpan.textContent = '+' + Math.max(0, kDoor - kQH * nKH) + 'q';
    };

    // ── Hulp: herbereken +Xq span en per-heat preview voor halve finale ───────
    const updateHalfSpans = tr => {
        const hDoor  = parseInt(tr?.querySelector('[name="half_door"]')?.value)    || 0;
        const hQH    = parseInt(tr?.querySelector('[name="half_q_heat"]')?.value)  || 0; // 0 is geldig
        const nHHf   = parseInt(tr?.querySelector('[name="half_heats"]')?.value)   || 1;
        const qaSpan = tr?.querySelector('.ts-half-velden .ts-q-afgeleid');
        if (qaSpan) qaSpan.textContent = '+' + Math.max(0, hDoor - hQH * nHHf) + 'q';
        // per-heat preview: input is opgeslagen in data-in op het half-heats invoerveld
        const hInp   = tr?.querySelector('.ts-inp-half-aantal');
        const halfIn = parseInt(hInp?.dataset.in) || 0;
        const hSpan  = tr?.querySelector('.ts-per-heat-half');
        if (hSpan) hSpan.textContent = halfIn > 0 ? berekenPerHeat(halfIn, nHHf) + '/h' : '—';
    };

    // Live: kwart_door → +Xq bijwerken + data-in op half-heats + half preview
    container.querySelectorAll('.ts-inp-kwart-door').forEach(inp => {
        inp.addEventListener('input', () => {
            const tr   = inp.closest('tr');
            const kDoor = parseInt(inp.value) || 0;
            updateKwartQSpan(tr);
            // half-heats data-in = kwart_door (als halve finale actief is)
            const hInp = tr?.querySelector('.ts-inp-half-aantal');
            if (hInp) { hInp.dataset.in = kDoor; }
            updateHalfSpans(tr);
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // Live: kwart_q_heat → +Xq bijwerken
    container.querySelectorAll('.ts-inp-kwart-qh').forEach(inp => {
        inp.addEventListener('input', () => {
            const tr = inp.closest('tr');
            updateKwartQSpan(tr);
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // Live: half_door → +Xq en preview bijwerken
    container.querySelectorAll('[name="half_door"]').forEach(inp => {
        inp.addEventListener('input', () => {
            updateHalfSpans(inp.closest('tr'));
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // Live: half_q_heat → +Xq bijwerken
    container.querySelectorAll('.ts-inp-half-qh').forEach(inp => {
        inp.addEventListener('input', () => {
            updateHalfSpans(inp.closest('tr'));
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // Live preview kwart-heats: aantal heats kwart → per-heat preview + +Xq
    container.querySelectorAll('.ts-inp-kwart-aantal').forEach(inp => {
        inp.addEventListener('input', () => {
            const tr    = inp.closest('tr');
            const span  = inp.closest('td')?.querySelector('.ts-per-heat-cel');
            const qIn   = parseInt(inp.dataset.q) || 0;   // data-q = kwartIn (series_q of cat.n)
            const nKH   = parseInt(inp.value) || 1;
            if (span) span.textContent = qIn > 0 ? berekenPerHeat(qIn, nKH) + '/h' : '—';
            updateKwartQSpan(tr);
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // Live preview half-heats: aantal heats half → per-heat preview + +Xq
    container.querySelectorAll('.ts-inp-half-aantal').forEach(inp => {
        inp.addEventListener('input', () => {
            updateHalfSpans(inp.closest('tr'));
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // Update kwart/half previews ook als heats_q wijzigt
    container.querySelectorAll('[name="heats_q"]').forEach(inp => {
        inp.addEventListener('input', () => {
            const tr   = inp.closest('tr');
            if (!tr) return;
            const qDoor = parseInt(inp.value) || 0;
            const nKH   = parseInt(tr.querySelector('[name="kwart_heats"]')?.value) || 1;
            const spanK = tr.querySelector('.ts-inp-kwart-aantal')?.closest('td')?.querySelector('.ts-per-heat-cel');
            if (spanK) spanK.textContent = qDoor > 0 ? berekenPerHeat(qDoor, nKH) + '/h' : '—';
            tr.querySelector('.ts-inp-kwart-aantal')?.setAttribute('data-q', qDoor);
            updateCalc(inp.closest('.ts-panel-form'), afstandGroepen);
        });
    });

    // Auto-switch finale-seeding bij wijziging finale_heats: 1 → standaard, ≥2 → tijdkoppeling
    container.querySelectorAll('.ts-inp-finale-heats').forEach(inp => {
        inp.addEventListener('change', () => {
            const sel = inp.closest('tr')?.querySelector('.ts-sel-finale-seeding');
            if (!sel) return;
            const n = parseInt(inp.value) || 1;
            if (n < 1) inp.value = 1;
            sel.value = n >= 2 ? 'tijdkoppeling' : 'slang';
        });
    });

    const calcInputs = ['heats_q','heats_q_heat','kwart_heats','kwart_door','kwart_q_heat','half_heats','half_door','half_q_heat','q_direct','q_tijd','finale_heat_grootte','finale_b_grootte','laatste_b_grootste','heeft_runner_up','heeft_kleine_finale','heats_aantal','runner_up_max','runner_up_min','finale_a_grootte','finale_b_heats'];
    calcInputs.forEach(name => {
        container.querySelectorAll(`[name="${name}"]`).forEach(inp => {
            inp.addEventListener('input',  () => updateCalc(inp.closest('.ts-panel-form'), afstandGroepen));
            inp.addEventListener('change', () => updateCalc(inp.closest('.ts-panel-form'), afstandGroepen));
        });
    });

    // ── Afstand opslaan ───────────────────────────────────────────────────────
    container.querySelectorAll('.ts-btn-afstand-save').forEach(btn => {
        btn.addEventListener('click', async () => {
            const naam = btn.dataset.naam;
            const form = btn.closest('.ts-panel-form');
            if (!form) return;

            const num     = n => parseInt(form.querySelector(`[name="${n}"]`)?.value) || 0;
            const heeftRU = form.querySelector('[name="heeft_runner_up"]')?.checked ? 1 : 0;
            const ruMax   = num('runner_up_max');
            const ruMin   = num('runner_up_min');

            // Per-categorie config uitlezen; runner-up komt van gedeeld vinkje
            const catConfigs = [];
            form.querySelectorAll('tbody tr.ts-cat-rij').forEach(tr => {
                const chk = n => tr.querySelector(`[name="${n}"]`)?.checked ?? false;
                const cn  = n => parseInt(tr.querySelector(`[name="${n}"]`)?.value) || 0;

                // Full-final per-cat finale-instellingen (null als niet aanwezig)
                const aInp  = tr.querySelector('[name="finale_a_grootte"]');
                const bhInp = tr.querySelector('[name="finale_b_heats"]');
                const lbgEl = tr.querySelector('[name="laatste_b_grootste"]');
                const sasEl = tr.querySelector('[name="series_alleen_startvolgorde"]');
                const finale_a_grootte   = aInp  ? (parseInt(aInp.value)  || 0) : null;
                const finale_b_heats     = bhInp ? (parseInt(bhInp.value) || 0) : null;
                const laatste_b_grootste = lbgEl ? (lbgEl.checked ? 1 : 0) : null;
                // Alleen 1 als daadwerkelijk aangevinkt én enabled (heats=1 + heeft_heats)
                const series_alleen_startvolgorde = sasEl && sasEl.checked && !sasEl.disabled ? 1 : 0;

                catConfigs.push({
                    dc_id:              tr.dataset.dcId,
                    distance_id:        tr.dataset.distId,
                    heeft_heats:        chk('heeft_heats')        ? 1 : 0,
                    heats_aantal:       cn('heats_aantal') || 1,
                    heats_q:            cn('heats_q'),
                    // Q per heat voor full-final series → A-finale. 0 = puur tijdsnelsten.
                    heats_q_heat:       parseInt(tr.querySelector('[name="heats_q_heat"]')?.value ?? '0') || 0,
                    heeft_kwartfinale:  chk('heeft_kwartfinale')  ? 1 : 0,
                    kwart_heats:        cn('kwart_heats') || 2,
                    kwart_door:         cn('kwart_door'),
                    kwart_q_heat:       parseInt(tr.querySelector('[name="kwart_q_heat"]')?.value ?? '1'),
                    heeft_halve_finale: chk('heeft_halve_finale') ? 1 : 0,
                    half_heats:         cn('half_heats')  || 2,
                    half_door:          cn('half_door'),
                    half_q_heat:        parseInt(tr.querySelector('[name="half_q_heat"]')?.value  ?? '1'),
                    heeft_runner_up:    heeftRU,
                    finale_heats:       cn('finale_heats') || 1,
                    // Per-cat FF-velden (null als niet in deze rij)
                    finale_a_grootte,
                    finale_b_heats,
                    laatste_b_grootste,
                    series_alleen_startvolgorde,
                });
            });

            tsAfstandOpen = naam;
            try {
                await postTs({
                    action:              'save_afstand',
                    tijdschema_id:       tsId,
                    afstand_naam:        naam,
                    q_direct:            num('q_direct'),
                    q_tijd:              num('q_tijd'),
                    finale_heat_grootte: num('finale_heat_grootte') || 6,
                    finale_b_grootte:    num('finale_b_grootte')    || 6,
                    // FF-gedeeld: hidden input met value. INT: bestaat niet.
                    // (Per-cat checkboxes voor laatste_b_grootste worden in catConfigs meegestuurd.)
                    laatste_b_grootste:  (() => {
                        const e = form.querySelector('.ts-gedeeld-velden [name="laatste_b_grootste"]');
                        if (!e) return 1;
                        return e.type === 'checkbox' ? (e.checked ? 1 : 0) : (parseInt(e.value) ? 1 : 0);
                    })(),
                    finale_seeding:      form.querySelector('[name="finale_seeding"]')?.value ?? 'slang',
                    // race_type niet meer meesturen — afgeleid uit distances.race_type
                    heeft_runner_up:     heeftRU,
                    heeft_kleine_finale: form.querySelector('[name="heeft_kleine_finale"]')?.checked ? 1 : 0,
                    runner_up_max:       ruMax,
                    runner_up_min:       ruMin,
                    cat_configs:         catConfigs,
                });
                tsAfstandOpen = null;
                // Markeer programma als mogelijk verouderd als er al ritten zijn
                if (huidigTijdschema?.ritten?.length) programmaVerouderd = true;
            } catch(e) {
                toonBevestigDialog('Fout bij opslaan: ' + e.message, 'Fout');
            }
        });
    });

    // ── Blokken: ↑↓ herordenen ────────────────────────────────────────────────
    container.querySelectorAll('.ts-btn-blok-up, .ts-btn-blok-down').forEach(btn => {
        btn.addEventListener('click', async () => {
            const blokken = [...(huidigTijdschema?.blokken ?? [])];
            const idx     = parseInt(btn.dataset.idx);
            const delta   = btn.classList.contains('ts-btn-blok-up') ? -1 : 1;
            const nieuw   = idx + delta;
            if (nieuw < 0 || nieuw >= blokken.length) return;
            // Ronde mag niet voor wedstrijdstart
            const wsPos = blokken.findIndex(b => b.blok_type === 'wedstrijdstart');
            if (delta === -1 && blokken[idx].blok_type === 'ronde' && wsPos !== -1 && nieuw <= wsPos) return;
            [blokken[idx], blokken[nieuw]] = [blokken[nieuw], blokken[idx]];
            const volgorde = blokken.map((b, i) => ({ id: b.id, volgorde: i }));
            try {
                await postTs({ action: 'save_blokken', tijdschema_id: tsId, volgorde });
            } catch(e) { toonBevestigDialog(e.message, 'Fout'); }
        });
    });

    // Pauze toevoegen
    el('ts-btn-add-pauze')?.addEventListener('click', async () => {
        try { await postTs({ action: 'add_pauze',     tijdschema_id: tsId }); }
        catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    });

    // Inrijden toevoegen
    el('ts-btn-add-inrijden')?.addEventListener('click', async () => {
        try { await postTs({ action: 'add_inrijden',  tijdschema_id: tsId }); }
        catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    });

    // Ceremonie toevoegen
    el('ts-btn-add-ceremonie')?.addEventListener('click', async () => {
        try { await postTs({ action: 'add_ceremonie', tijdschema_id: tsId }); }
        catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    });

    // Wedstrijd start toevoegen
    el('ts-btn-add-wsstart')?.addEventListener('click', async () => {
        try { await postTs({ action: 'add_wedstrijdstart', tijdschema_id: tsId }); }
        catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    });

    // Herstart toevoegen
    el('ts-btn-add-herstart')?.addEventListener('click', async () => {
        try { await postTs({ action: 'add_herstart', tijdschema_id: tsId }); }
        catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    });

    // ── Blok opslaan (pauze / inrijden / ceremonie / wedstrijdstart / herstart / ronde) ──
    const slaBlokOp = async (blokId) => {
        const blokDiv = container.querySelector(`.ts-blok-pauze[data-blok-id="${blokId}"], .ts-blok-inrijd[data-blok-id="${blokId}"], .ts-blok-cerem[data-blok-id="${blokId}"], .ts-blok-wsstart[data-blok-id="${blokId}"], .ts-blok-herstart[data-blok-id="${blokId}"], .ts-blok-ronde[data-blok-id="${blokId}"]`);
        if (!blokDiv) return;
        const blok = (huidigTijdschema?.blokken ?? []).find(b => b.id == blokId);
        if (!blok) return;

        const postBody = { action: 'save_blok', tijdschema_id: tsId, blok_id: parseInt(blokId) };

        if (blok.blok_type === 'pauze' || blok.blok_type === 'inrijden' || blok.blok_type === 'ceremonie') {
            postBody.duur        = parseInt(blokDiv.querySelector('.ts-inp-duur')?.value) || null;
            postBody.inrijd_cats = [...(blokDiv.querySelectorAll('.ts-inrijd-cat-cb') ?? [])]
                .filter(cb => cb.checked).map(cb => cb.value);
            // Pauze (en eventueel andere typen) kunnen nu ook een opmerking dragen
            postBody.opmerking   = blokDiv.querySelector('.ts-inp-opmerking')?.value.trim() || null;
        } else if (blok.blok_type === 'wedstrijdstart') {
            postBody.tijdstip = blokDiv.querySelector('.ts-inp-tijdstip')?.value || null;
            // Multi-day: datum-veld (YYYY-MM-DD). Leeg → server interpret als NULL.
            postBody.datum    = blokDiv.querySelector('.ts-inp-datum')?.value     || null;
        } else if (blok.blok_type === 'herstart') {
            postBody.tijdstip  = blokDiv.querySelector('.ts-inp-tijdstip')?.value  || null;
            postBody.opmerking = blokDiv.querySelector('.ts-inp-opmerking')?.value.trim() || null;
        } else if (blok.blok_type === 'ronde') {
            postBody.heat_duur = mmSsNaarSec(blokDiv.querySelector('.ts-inp-heat-duur')?.value);
        }
        try { await postTs(postBody); }
        catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    };

    container.querySelectorAll('.ts-inp-duur').forEach(inp => {
        inp.addEventListener('change', () => slaBlokOp(inp.dataset.blokId));
    });

    container.querySelectorAll('.ts-inrijd-cat-cb').forEach(cb => {
        cb.addEventListener('change', () => slaBlokOp(cb.dataset.blokId));
    });

    container.querySelectorAll('.ts-inp-tijdstip').forEach(inp => {
        inp.addEventListener('change', () => slaBlokOp(inp.dataset.blokId));
    });

    container.querySelectorAll('.ts-inp-datum').forEach(inp => {
        inp.addEventListener('change', () => slaBlokOp(inp.dataset.blokId));
    });

    container.querySelectorAll('.ts-inp-opmerking').forEach(inp => {
        inp.addEventListener('change', () => slaBlokOp(inp.dataset.blokId));
    });

    container.querySelectorAll('.ts-inp-heat-duur').forEach(inp => {
        inp.addEventListener('change', () => slaBlokOp(inp.dataset.blokId));
    });

    // Pauze verwijderen
    container.querySelectorAll('.ts-btn-blok-del').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await postTs({ action: 'delete_blok', tijdschema_id: tsId, blok_id: parseInt(btn.dataset.blokId) });
            } catch(e) { toonBevestigDialog(e.message, 'Fout'); }
        });
    });

    // Eén rit verwijderen uit het gegenereerd programma. Bedoeld voor
    // mid-wedstrijd-noodgevallen (bv. weer slaat tegen → runner-up
    // skippen voor één cat). De backend gooit ook heat + entries +
    // results weg en update cat_config indien dit de laatste rit van
    // die ronde was.
    container.querySelectorAll('.ts-rit-verwijder').forEach(btn => {
        btn.addEventListener('click', async (ev) => {
            ev.stopPropagation(); // niet de groep-header drag triggeren
            const ritId    = parseInt(btn.dataset.ritId);
            const ritNaam  = btn.dataset.ritNaam || '?';
            const dcNaam   = btn.dataset.dcNaam || '';
            const rondeLbl = btn.dataset.rondeLabel || '';
            const melding =
                `Verwijder rit "${ritNaam}" — ${rondeLbl} ${dcNaam}\n\n` +
                `De heat, alle ingedeelde rijders en eventuele resultaten ` +
                `worden gewist. Dit kan NIET ongedaan gemaakt worden.\n\n` +
                `Doorgaan?`;
            if (!await toonBevestigDialog(melding, 'Rit verwijderen')) return;
            btn.disabled = true;
            try {
                await postTs({
                    action:             'delete_rit',
                    tijdschema_id:      tsId,
                    rit_id:             ritId,
                    tijdschema_version: tijdschemaVersion,
                });
                if (typeof invalideerSlTsCache === 'function') invalideerSlTsCache();
            } catch(e) { toonBevestigDialog(e.message, 'Fout'); btn.disabled = false; }
        });
    });

    // Extra heat toevoegen aan een ronde-groep (zelfde dc/dist/ronde_type).
    // cat_config wordt server-side bijgewerkt zodat doorstroom-regels en
    // aantal-heats blijven kloppen. Niet beschikbaar voor series — daar staat
    // de + knop helemaal niet in de markup.
    container.querySelectorAll('.ts-rit-kopieer').forEach(btn => {
        btn.addEventListener('click', async (ev) => {
            ev.stopPropagation();
            const ritId    = parseInt(btn.dataset.ritId);
            const dcNaam   = btn.dataset.dcNaam || '';
            const rondeLbl = btn.dataset.rondeLabel || '';
            const melding =
                `Voeg een extra ${rondeLbl}-heat toe voor "${dcNaam}".\n\n` +
                `De nieuwe heat komt direct ná de huidige heats. Het aantal ` +
                `heats in afstand-instellingen wordt automatisch met 1 verhoogd; ` +
                `doorstroom-regels (Q per heat + q-tijden) blijven hetzelfde.\n\n` +
                `Doorgaan?`;
            if (!await toonBevestigDialog(melding, 'Heat toevoegen')) return;
            btn.disabled = true;
            try {
                await postTs({
                    action:             'add_rit_kopie',
                    tijdschema_id:      tsId,
                    rit_id:             ritId,
                    tijdschema_version: tijdschemaVersion,
                });
                if (typeof invalideerSlTsCache === 'function') invalideerSlTsCache();
            } catch(e) { toonBevestigDialog(e.message, 'Fout'); btn.disabled = false; }
        });
    });

    // Volgorde expliciet opslaan (visuele bevestiging)
    el('ts-btn-save-blokken')?.addEventListener('click', async () => {
        const blokken  = [...(huidigTijdschema?.blokken ?? [])];
        const volgorde = blokken.map((b, i) => ({ id: b.id, volgorde: i }));
        try {
            await postTs({ action: 'save_blokken', tijdschema_id: tsId, volgorde });
        } catch(e) { toonBevestigDialog(e.message, 'Fout'); }
    });

    // ── Genereer ─────────────────────────────────────────────────────────────
    el('ts-btn-genereer')?.addEventListener('click', async () => {
        if (!await toonBevestigDialog('Bestaand programma overschrijven en opnieuw genereren?', 'Programma genereren')) return;

        const btn = el('ts-btn-genereer');
        const origTxt = btn?.textContent ?? '';
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Bezig…'; }

        try {
            // Controleer of vergelijkData geladen is
            if (!vergelijkData?.length) {
                throw new Error('Wedstrijddata niet geladen. Open eerst het Importeer-tabblad en laad de wedstrijd.');
            }

            // Bouw de effectieve categorielijst (met merges en splits verwerkt)
            const categorieen = [];
            for (const afGroep of bouwAfstandGroepen()) {
                for (const cat of afGroep.cats) {
                    categorieen.push({
                        afstand_naam:    afGroep.naam,
                        dc_id:           cat.dc_id,
                        dc_naam:         cat.dc_naam,
                        distance_id:     cat.distance_id ?? null,
                        n:               cat.n,
                        merged_dc_ids:   cat.merged_dc_ids  ?? null,
                        category_filter: cat.category_filter ?? '',
                    });
                }
            }

            if (categorieen.length === 0) {
                throw new Error(
                    'Geen categorieën gevonden met ingeschreven deelnemers.\n' +
                    'Controleer of de afstanden correct zijn ingesteld in het Importeer-tabblad.'
                );
            }

            const result = await postTs({
                action: 'genereer',
                tijdschema_id:  tsId,
                competition_id: huidigCompId,
                categorieen,
            });

            // Programma is nu actueel
            programmaVerouderd = false;
            if (typeof invalideerSlTsCache === 'function') invalideerSlTsCache();

            // Feedback aan gebruiker
            const nRitten = result?.ritten?.length ?? 0;
            if (nRitten === 0) {
                const isFF = (huidigTijdschema?.systeem ?? '') === 'full-final';
                toonBevestigDialog(
                    'Programma gegenereerd, maar er zijn geen heats aangemaakt. ' +
                    (isFF
                        ? 'Geen categorieën met deelnemers gevonden voor de ingestelde afstanden. ' +
                          'Controleer de afstandsinstellingen in het Importeer-tabblad.'
                        : 'Categorieën nog niet geconfigureerd (klik op ✏ Bewerken per afstand), ' +
                          'of vakje "Rijdt series" staat uit, of er zijn geen rondes-blokken aangemaakt.'),
                    'Geen heats'
                );
            }
        } catch(e) {
            toonBevestigDialog('Fout bij genereren: ' + e.message, 'Fout');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = origTxt; }
        }
    });

    el('ts-btn-wis-programma')?.addEventListener('click', async () => {
        if (!await toonBevestigDialog(
            'De volgende worden verwijderd:\n'
            + '• Ritten + startlijsten\n'
            + '• Blokken (wedstrijdstart/pauze/ceremonie/herstart)\n\n'
            + 'De afstandinstellingen blijven behouden.\n\n'
            + 'Klik daarna op Opslaan in Afstandinstellingen om de blokken '
            + 'opnieuw te genereren.',
            'Programma wissen'
        )) return;

        const btn = el('ts-btn-wis-programma');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Bezig…'; }

        try {
            await postTs({
                action:        'wis_programma',
                tijdschema_id: tsId,
            });
            if (typeof invalideerSlTsCache === 'function') invalideerSlTsCache();
            await laadTijdschema();
        } catch (e) {
            toonBevestigDialog('Fout bij wissen: ' + e.message, 'Fout');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = '🗑 Wis programma'; }
        }
    });

    // (↑↓ knoppen vervangen door groep-drag-and-drop hieronder)

    // ── Drag-drop blokken ─────────────────────────────────────────────────────
    const blokLijst = container.querySelector('#ts-blokken-lijst');
    if (blokLijst) {
        let dragBlok = null;

        const clearDropClasses = () =>
            blokLijst.querySelectorAll('.ts-drop-above,.ts-drop-below')
                .forEach(el => el.classList.remove('ts-drop-above', 'ts-drop-below'));

        blokLijst.querySelectorAll(':scope > .ts-blok-item').forEach(item => {
            item.addEventListener('dragstart', e => {
                // Prevent drag starting on interactive elements
                if (e.target.closest('input,button,label')) { e.preventDefault(); return; }
                dragBlok = item;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', item.dataset.blokId);
                setTimeout(() => item.classList.add('ts-blok-dragging'), 0);
            });

            item.addEventListener('dragend', () => {
                item.classList.remove('ts-blok-dragging');
                clearDropClasses();
                dragBlok = null;
            });

            item.addEventListener('dragover', e => {
                if (!dragBlok || dragBlok === item) return;
                const items  = [...blokLijst.querySelectorAll(':scope > .ts-blok-item')];
                const wsI    = items.findIndex(el => el.classList.contains('ts-blok-wsstart'));
                const targetI = items.indexOf(item);
                const rect   = item.getBoundingClientRect();
                const above  = e.clientY < rect.top + rect.height / 2;
                // Blokkeer ronde vóór wedstrijdstart
                if (dragBlok.classList.contains('ts-blok-ronde') && wsI !== -1) {
                    const effectief = above ? targetI : targetI + 1;
                    if (effectief <= wsI) return;
                }
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                clearDropClasses();
                item.classList.add(above ? 'ts-drop-above' : 'ts-drop-below');
            });

            item.addEventListener('dragleave', e => {
                if (!item.contains(e.relatedTarget))
                    item.classList.remove('ts-drop-above', 'ts-drop-below');
            });

            item.addEventListener('drop', async e => {
                e.preventDefault();
                if (!dragBlok || dragBlok === item) return;
                const above = item.classList.contains('ts-drop-above');
                clearDropClasses();
                above ? blokLijst.insertBefore(dragBlok, item)
                      : item.after(dragBlok);
                const volgorde = [...blokLijst.querySelectorAll(':scope > .ts-blok-item')]
                    .map((el, i) => ({ id: parseInt(el.dataset.blokId), volgorde: i }));
                try { await postTs({ action: 'save_blokken', tijdschema_id: tsId, volgorde }); }
                catch(err) { toonBevestigDialog(err.message, 'Fout'); renderTijdschema(); }
            });
        });
    }

    // ── Drag-drop categoriegroepen (ritten) ───────────────────────────────────
    const rittenTbody = container.querySelector('.ts-ritten-tabel tbody');
    if (rittenTbody) {
        let dragGroepKey  = null;   // groep-key van de groep die gesleept wordt
        let dragGroepRows = [];     // [header-tr, rit-tr, rit-tr, ...]

        // Alle DOM-rijen die bij een groepKey horen (header + sub-rijen)
        const getGroepRows = gk =>
            [...rittenTbody.querySelectorAll(`[data-groep-key="${CSS.escape(gk)}"]`)];

        const clearRitClasses = () =>
            rittenTbody.querySelectorAll('.ts-drop-above,.ts-drop-below')
                .forEach(r => r.classList.remove('ts-drop-above', 'ts-drop-below'));

        // Vind de groepsheader die bij een willekeurige rij in de tabel hoort
        const vindHdr = tr => {
            if (tr.classList.contains('ts-rit-groep-hdr')) return tr;
            const gk = tr.dataset?.groepKey;
            if (!gk) return null;
            return rittenTbody.querySelector(`tr.ts-rit-groep-hdr[data-groep-key="${CSS.escape(gk)}"]`);
        };

        // ── Event delegation op de volledige tbody ────────────────────────────
        let dropAbove = false; // bewaard via dragover, gebruikt in drop

        rittenTbody.addEventListener('dragstart', e => {
            const hdr = e.target.closest('tr.ts-rit-groep-hdr');
            if (!hdr) { e.preventDefault(); return; }
            dragGroepKey  = hdr.dataset.groepKey;
            dragGroepRows = getGroepRows(dragGroepKey);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragGroepKey);
            setTimeout(() => dragGroepRows.forEach(r => r.classList.add('ts-rit-groep-dragging')), 0);
        });

        rittenTbody.addEventListener('dragend', () => {
            dragGroepRows.forEach(r => r.classList.remove('ts-rit-groep-dragging'));
            clearRitClasses();
            dragGroepKey  = null;
            dragGroepRows = [];
        });

        rittenTbody.addEventListener('dragover', e => {
            if (!dragGroepKey) return;
            const tr = e.target.closest('tr');
            if (!tr) return;
            let targetHdr = vindHdr(tr);

            // Speciale rij (herstart / wsstart / pauze / etc.): geen groep-header.
            // Zoek de eerstvolgende ronde-groep ónder deze rij en gebruik die als doel
            // met dropAbove=true, zodat de groep direct ná de speciale rij belandt.
            if (!targetHdr && !tr.dataset.groepKey) {
                let volgende = tr.nextElementSibling;
                while (volgende && !volgende.classList.contains('ts-rit-groep-hdr'))
                    volgende = volgende.nextElementSibling;
                if (!volgende || volgende.dataset.groepKey === dragGroepKey) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                clearRitClasses();
                volgende.classList.add('ts-drop-above');
                dropAbove = true;
                return;
            }

            if (!targetHdr || targetHdr.dataset.groepKey === dragGroepKey) return;

            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            // Bepaal boven/onder op basis van de HELE groep (header t/m laatste rij)
            const tRows   = getGroepRows(targetHdr.dataset.groepKey);
            const topRect = targetHdr.getBoundingClientRect();
            const botRect = tRows[tRows.length - 1].getBoundingClientRect();
            dropAbove = e.clientY < (topRect.top + botRect.bottom) / 2;

            clearRitClasses();
            targetHdr.classList.add(dropAbove ? 'ts-drop-above' : 'ts-drop-below');
        });

        rittenTbody.addEventListener('dragleave', e => {
            if (!rittenTbody.contains(e.relatedTarget)) clearRitClasses();
        });

        rittenTbody.addEventListener('drop', e => {
            e.preventDefault();
            if (!dragGroepKey) return;
            const tr = e.target.closest('tr');
            if (!tr) return;
            let targetHdr = vindHdr(tr);

            // Speciale rij (herstart etc.): zelfde logica als dragover — gebruik volgende groep
            if (!targetHdr && !tr.dataset.groepKey) {
                let volgende = tr.nextElementSibling;
                while (volgende && !volgende.classList.contains('ts-rit-groep-hdr'))
                    volgende = volgende.nextElementSibling;
                if (!volgende || volgende.dataset.groepKey === dragGroepKey) return;
                targetHdr = volgende;
                dropAbove = true; // altijd vóór de volgende groep plaatsen
            }

            if (!targetHdr || targetHdr.dataset.groepKey === dragGroepKey) return;

            const above = dropAbove;
            clearRitClasses();

            // ── Verplaats alle rijen van de groep in de DOM ───────────────────
            const savedRows = [...dragGroepRows]; // kopie vóór dragend het wist
            if (above) {
                savedRows.forEach(r => rittenTbody.insertBefore(r, targetHdr));
            } else {
                const targetRows = getGroepRows(targetHdr.dataset.groepKey);
                let   anker      = targetRows[targetRows.length - 1];
                savedRows.forEach(r => { anker.after(r); anker = r; });
            }

            // ── In-memory update: nieuwe volgorde (multi-dag safe) ───────────
            // BELANGRIJK: de DOM bevat alleen ritten van de ACTIEVE dag
            // (renderRittenLijst filtert andere dagen weg). Als we
            // huidigTijdschema.ritten zouden overschrijven met enkel de
            // DOM-set, zouden andere dagen uit client-state verdwijnen — en
            // sturen we de server een 'volgorde 1..N' die de globale
            // nummering breekt waardoor blok-grenzen (CEREMONIE/HERSTART)
            // op verkeerde posities terechtkomen. Symptoom: dag 1 wordt
            // door elkaar geschud na een drag op dag 3.
            //
            // Aanpak: vervang elke actieve-dag-positie in de globale lijst
            // (in oude volgorde) door de volgende rit-ID uit de nieuwe
            // DOM-volgorde (FIFO). Andere dagen blijven exact op hun plek
            // staan, alleen actieve-dag-ritten worden onderling herordend.
            const ritById = new Map((huidigTijdschema.ritten ?? []).map(r => [parseInt(r.id), r]));
            const nieuweActiefIds = [...rittenTbody.querySelectorAll('tr.ts-rit-rij')]
                .map(row => parseInt(row.dataset.ritId));
            const actiefSet = new Set(nieuweActiefIds);
            const queue     = [...nieuweActiefIds];   // FIFO actieve-dag in nieuwe volgorde

            const nieuweGlobaal = (huidigTijdschema.ritten ?? []).map(r => {
                if (actiefSet.has(parseInt(r.id))) {
                    const id = queue.shift();
                    return ritById.get(id);
                }
                return r;
            }).filter(r => r?.id);

            huidigTijdschema.ritten = nieuweGlobaal;

            // ── Render UITSTELLEN tot na dragend (voorkomt browser snap-back) ─
            setTimeout(renderTijdschema, 0);

            // ── Achtergrond-save: stuur de COMPLETE globale lijst, niet alleen
            // de actieve dag. Anders raken volgorde-nummers van andere dagen
            // out-of-sync met de nieuwe 1..N nummering van de actieve dag.
            const volgorde = nieuweGlobaal.map((r, i) => ({ id: parseInt(r.id), volgorde: i + 1 }));
            fetch('api/tijdschema.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'herorden_ritten', tijdschema_id: tsId,
                                         competition_id: huidigCompId, volgorde,
                                         tijdschema_version: tijdschemaVersion })
            })
            .then(r => r.json())
            .then(data => {
                if (data?.error === 'conflict') { toonTsConflictWaarschuwing(data.message); return; }
                if (data?.error) { toonBevestigDialog('Opslaan mislukt: ' + data.error, 'Fout'); return; }
                if (data?.tijdschema_version != null) tijdschemaVersion = data.tijdschema_version;
                huidigTijdschema = data;
            })
            .catch(err => { toonBevestigDialog('Opslaan mislukt: ' + err.message, 'Fout'); renderTijdschema(); });
        });

        // ── Cat-groep in-/uitklappen ──────────────────────────────────────────
        // Houdt _tsIngeklapteGroepen in sync + past CSS-classes lokaal aan.
        // Geen re-render: behoudt scroll-positie en drag-state.
        const klapZet = (key, inklappen) => {
            if (!key) return;
            if (inklappen) _tsIngeklapteGroepen.add(key);
            else           _tsIngeklapteGroepen.delete(key);
            const sel = `[data-groep-key="${CSS.escape(key)}"]`;
            const hdr = rittenTbody.querySelector(`tr.ts-rit-groep-hdr${sel}`);
            if (hdr) {
                hdr.classList.toggle('ts-groep-ingeklapt', inklappen);
                const btn = hdr.querySelector('.ts-groep-toggle');
                if (btn) {
                    btn.textContent = inklappen ? '▶' : '▼';
                    btn.setAttribute('aria-expanded', inklappen ? 'false' : 'true');
                }
            }
            rittenTbody.querySelectorAll(`tr.ts-rit-sub${sel}, tr.ts-rit-opm-rij${sel}`)
                .forEach(r => r.classList.toggle('ts-groep-verborgen', inklappen));
        };

        rittenTbody.addEventListener('click', e => {
            const btn = e.target.closest('.ts-groep-toggle');
            if (!btn) return;
            e.stopPropagation();
            const hdr = btn.closest('tr.ts-rit-groep-hdr');
            const key = hdr?.dataset.groepKey;
            klapZet(key, !_tsIngeklapteGroepen.has(key));
        });

        container.querySelectorAll('.ts-klap-toggle-all').forEach(btn => {
            btn.addEventListener('click', () => {
                const inklappen = btn.dataset.actie === 'alles-in';
                rittenTbody.querySelectorAll('tr.ts-rit-groep-hdr')
                    .forEach(hdr => klapZet(hdr.dataset.groepKey, inklappen));
            });
        });

        // ── Starttijd-override per heat (klik op tijdcel) ─────────────────────
        rittenTbody.addEventListener('click', e => {
            const td = e.target.closest('.ts-rit-tijd-klik');
            if (!td) return;

            // Sluit evt. al open panel
            document.querySelector('.ts-rit-override-panel')?.remove();

            const ritId    = parseInt(td.dataset.ritId);
            const override = td.dataset.override ?? '';
            const opm      = td.dataset.opm ?? '';

            const panel = document.createElement('div');
            panel.className = 'ts-rit-override-panel';
            panel.innerHTML = `
                <div class="ts-rit-override-kop">📌 Starttijd vastleggen</div>
                <label>Tijdstip<input type="time" class="ts-ovr-tijd" value="${escHtml(override)}"></label>
                <label>Opmerking<input type="text" class="ts-ovr-opm" value="${escHtml(opm)}"
                       placeholder="bijv. vertraging…" maxlength="120" style="width:100%"></label>
                <div class="ts-rit-override-btns">
                    <button class="ts-btn-sm ts-btn-success ts-ovr-sla">Opslaan</button>
                    ${override ? `<button class="ts-btn-sm ts-ovr-wis">Wis override</button>` : ''}
                    <button class="ts-btn-sm ts-ovr-ann">Annuleer</button>
                </div>`;
            document.body.appendChild(panel);

            // Positioneer onder (of boven als te weinig ruimte) de tijdcel
            const rect       = td.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const panelTop   = spaceBelow > 190
                ? rect.bottom + window.scrollY + 4
                : rect.top   + window.scrollY - 195;
            panel.style.top  = `${Math.max(panelTop, window.scrollY + 8)}px`;
            panel.style.left = `${Math.min(rect.left + window.scrollX, window.innerWidth + window.scrollX - 300)}px`;

            panel.querySelector('.ts-ovr-ann').addEventListener('click', () => panel.remove());
            panel.querySelector('.ts-ovr-wis')?.addEventListener('click', async () => {
                panel.remove();
                await saveRitOverride(ritId, '', '', tsId);
            });
            panel.querySelector('.ts-ovr-sla').addEventListener('click', async () => {
                const tijdVal = panel.querySelector('.ts-ovr-tijd').value;
                const opmVal  = panel.querySelector('.ts-ovr-opm').value.trim();
                panel.remove();
                await saveRitOverride(ritId, tijdVal, opmVal, tsId);
            });

            // Enter in tijdveld = sla op
            panel.querySelector('.ts-ovr-opm').addEventListener('keydown', ev => {
                if (ev.key === 'Enter') panel.querySelector('.ts-ovr-sla').click();
                if (ev.key === 'Escape') panel.remove();
            });

            // Sluiten bij klik buiten het panel
            setTimeout(() => {
                const sluit = ev => {
                    if (!panel.contains(ev.target) && ev.target !== td) {
                        panel.remove();
                        document.removeEventListener('click', sluit);
                    }
                };
                document.addEventListener('click', sluit);
            }, 10);
        });

        // ── Ritten combineren: selectie + combineer-knop ──────────────────────
        const combiBtn   = el('ts-btn-combi');
        const combiCount = el('ts-combi-count');

        const updateCombiBtn = () => {
            const sels = container.querySelectorAll('.ts-combi-sel:checked');
            const aantal = sels.length;
            if (combiCount) combiCount.textContent = aantal;
            if (!combiBtn) return;
            combiBtn.disabled = (aantal < 2 || aantal > 4);
        };

        rittenTbody.addEventListener('change', e => {
            if (e.target.classList.contains('ts-combi-sel')) updateCombiBtn();
        });

        combiBtn?.addEventListener('click', async () => {
            const sels = container.querySelectorAll('.ts-combi-sel:checked');
            const selArr = [...sels].map(cb => ({
                id: parseInt(cb.dataset.ritId),
                volgorde: parseInt(cb.dataset.volgorde),
            }));
            if (selArr.length < 2 || selArr.length > 4) return;
            // Sorteer op volgorde en check dat ze opeenvolgend zijn
            selArr.sort((a, b) => a.volgorde - b.volgorde);
            for (let i = 1; i < selArr.length; i++) {
                if (selArr[i].volgorde !== selArr[i - 1].volgorde + 1) {
                    toonBevestigDialog(
                        'Alleen opeenvolgende ritten kunnen gecombineerd worden.',
                        'Combineren'
                    );
                    return;
                }
            }
            combiBtn.disabled = true;
            try {
                const data = await postTs({
                    action:         'set_combi',
                    tijdschema_id:  tsId,
                    competition_id: huidigCompId,
                    rit_ids:        selArr.map(x => x.id),
                });
                if (data?.error) throw new Error(data.error);
                if (data?.tijdschema_version != null) tijdschemaVersion = data.tijdschema_version;
                huidigTijdschema = data;
                renderTijdschema();
            } catch (e) {
                toonBevestigDialog('Combineren mislukt: ' + e.message, 'Fout');
                combiBtn.disabled = false;
            }
        });

        // Ontkoppel-knop op een combi-leider
        rittenTbody.addEventListener('click', async e => {
            const btn = e.target.closest('.ts-combi-unlink');
            if (!btn) return;
            e.stopPropagation();
            const groep = parseInt(btn.dataset.combiGroup);
            if (!groep) return;
            // Verzamel alle rit_ids in deze groep
            const trs = container.querySelectorAll(
                `tr.ts-combi[data-combi-group="${groep}"]`
            );
            const ritIds = [...trs].map(tr => parseInt(tr.dataset.ritId));
            if (!ritIds.length) return;
            btn.disabled = true;
            try {
                const data = await postTs({
                    action:         'clear_combi',
                    tijdschema_id:  tsId,
                    competition_id: huidigCompId,
                    rit_ids:        ritIds,
                });
                if (data?.error) throw new Error(data.error);
                if (data?.tijdschema_version != null) tijdschemaVersion = data.tijdschema_version;
                huidigTijdschema = data;
                renderTijdschema();
            } catch (e) {
                toonBevestigDialog('Ontkoppelen mislukt: ' + e.message, 'Fout');
                btn.disabled = false;
            }
        });
    }

    // ── Multi-day dag-tabs (Gegenereerd Programma) ────────────────────────
    // Click op een dag-tab updatet de module-state en re-rendert. Bij re-
    // render filtert renderRittenLijst de rijen op basis van _tsActieveDag.
    container.querySelectorAll('.ts-dag-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const nieuw = parseInt(btn.dataset.dag) || 1;
            if (nieuw === _tsActieveDag) return; // al actief
            _tsActieveDag = nieuw;
            renderTijdschema();
        });
    });
}

// ── Live update helpers ───────────────────────────────────────────────────────

function updateCalc(form, afstandGroepen) {
    if (!form) return;
    const naam    = form.dataset.naam;
    const afstand = afstandGroepen.find(a => a.naam === naam);
    if (!afstand) return;

    const num = n => parseInt(form.querySelector(`[name="${n}"]`)?.value) || 0;

    // Gedeelde afstand-cfg live uitlezen
    const liveCfg = {
        q_direct:            num('q_direct'),
        q_tijd:              num('q_tijd'),
        finale_heat_grootte: num('finale_heat_grootte') || 6,
        finale_b_grootte:    num('finale_b_grootte')    || 6,
        laatste_b_grootste:  form.querySelector('[name="laatste_b_grootste"]')?.checked ?? true,
        heeft_runner_up:     form.querySelector('[name="heeft_runner_up"]')?.checked ?? false,
        heeft_kleine_finale: form.querySelector('[name="heeft_kleine_finale"]')?.checked ? 1 : 0,
        runner_up_max:       num('runner_up_max'),
        runner_up_min:       num('runner_up_min'),
    };

    // Runner-up is een gedeeld vinkje (niet per rij)
    const heeftRU = form.querySelector('[name="heeft_runner_up"]')?.checked ?? false;

    // Per-cat config live uitlezen uit tabelrijen
    const liveCatMap = {};
    form.querySelectorAll('tbody tr.ts-cat-rij').forEach(tr => {
        const key = tr.dataset.dcId + '|' + tr.dataset.distId;
        const chk = n => tr.querySelector(`[name="${n}"]`)?.checked ?? false;
        const cn  = n => parseInt(tr.querySelector(`[name="${n}"]`)?.value) || 0;

        // Full-final per-cat finale-instellingen (alleen aanwezig in FF-rijen)
        const aInp  = tr.querySelector('[name="finale_a_grootte"]');
        const bhInp = tr.querySelector('[name="finale_b_heats"]');
        const lbgEl = tr.querySelector('[name="laatste_b_grootste"]');
        // null = rij heeft geen FF-velden (INT-mode) → renderAfstandCalc valt terug op liveCfg
        const finale_a_grootte   = aInp  ? (parseInt(aInp.value)  || 0) : null;
        const finale_b_heats     = bhInp ? (parseInt(bhInp.value) || 0) : null;
        const laatste_b_grootste = lbgEl ? (lbgEl.checked ? 1 : 0) : null;

        liveCatMap[key] = {
            heeft_heats:        chk('heeft_heats'),
            heats_aantal:       cn('heats_aantal') || 1,
            heats_q:            cn('heats_q'),
            heeft_kwartfinale:  chk('heeft_kwartfinale'),
            kwart_heats:        cn('kwart_heats') || 1,
            kwart_door:         cn('kwart_door'),
            kwart_q_heat:       cn('kwart_q_heat'),   // 0 is geldig (alles op tijd)
            heeft_halve_finale: chk('heeft_halve_finale'),
            half_heats:         cn('half_heats')  || 1,
            half_door:          cn('half_door'),
            half_q_heat:        cn('half_q_heat'),    // 0 is geldig
            heeft_runner_up:    heeftRU,
            // Per-cat FF-velden (null als niet aanwezig in deze rij)
            finale_a_grootte,
            finale_b_heats,
            laatste_b_grootste,
        };
    });

    const calcId  = `ts-calc-${naam.replace(/[^a-z0-9]/gi, '_')}`;
    const calcDiv = document.getElementById(calcId);
    if (calcDiv) calcDiv.innerHTML = renderAfstandCalc(afstand, liveCfg, liveCatMap);
}

// ── Publiceer tijdschema ──────────────────────────────────────────────────────
// Interne body-bouwer — returns { bodyHtml, cssLinks, extraCss, title }
// of null. Aangeroepen door `bouwProgrammaExternBody()` (voor Print-Center)
// en door `publiceerTijdschema()` (de eigen knop op Tijdschema-pagina).

function _bouwProgrammaExternInternal() {
    const schema = huidigTijdschema;
    const comp   = huidigComp;
    if (!schema) return null;

    // i18n-helper: leest live de actieve Print-Center taal (NL/EN).
    // Fallback: identity-functie zodat builder ook losstaand werkt (tests).
    const T    = window._pcT    || (k => k);
    const LANG = (window._pcLang && window._pcLang()) || 'nl';
    const LOC  = LANG === 'en' ? 'en-GB' : 'nl-NL';

    const ritten  = schema.ritten  ?? [];
    const blokken = schema.blokken ?? [];

    // Seconden → HH:MM
    const mNT = sec => {
        const s = Math.round(parseInt(sec) || 0);
        return `${String(Math.floor(s / 3600) % 24).padStart(2,'0')}:${String(Math.floor((s % 3600) / 60)).padStart(2,'0')}`;
    };

    // ── Hulpstructuren (identiek aan renderRittenLijst) ───────────────────────
    const blokById = new Map((blokken ?? []).map(b => [parseInt(b.id), b]));
    const heatDuurMapP = new Map(
        (blokken ?? []).filter(b => b.blok_type === 'ronde')
                       .map(b => [parseInt(b.id), parseInt(b.heat_duur) || 0])
    );
    const nonRondeBlokkenP = (blokken ?? [])
        .filter(b => b.blok_type !== 'ronde')
        .sort((a, b) => (parseInt(a.volgorde) || 0) - (parseInt(b.volgorde) || 0));
    const rondeBlokVolgordeP = new Map(
        (blokken ?? []).filter(b => b.blok_type === 'ronde')
                       .map(b => [parseInt(b.id), parseInt(b.volgorde) || 0])
    );

    // ── Bouw rijen in actuele volgorde ────────────────────────────────────────
    const rijdenP = [];
    let nrbIdxP = 0;
    for (const r of ritten) {
        const rBV = rondeBlokVolgordeP.get(parseInt(r.blok_id)) ?? 0;
        while (nrbIdxP < nonRondeBlokkenP.length &&
               (parseInt(nonRondeBlokkenP[nrbIdxP].volgorde) || 0) <= rBV) {
            const nb = nonRondeBlokkenP[nrbIdxP++];
            rijdenP.push({ type: nb.blok_type, blok: nb });
        }
        rijdenP.push({ type: 'rit', rit: r });
    }
    while (nrbIdxP < nonRondeBlokkenP.length) {
        rijdenP.push({ type: nonRondeBlokkenP[nrbIdxP].blok_type, blok: nonRondeBlokkenP[nrbIdxP++] });
    }

    // ── Multi-day claim-info (warm-up vóór wsstart bij opvolgende dag) ───────
    const _dagInfoExt = _tsBouwDagInfo(blokken);
    const _geclaimdVoorWsExt   = _dagInfoExt.geclaimdVoorWs;
    const _geclaimdeBlokIdsExt = _dagInfoExt.geclaimdeBlokIds;

    // ── Start-tijden berekenen via rijen ──────────────────────────────────────
    const stMap = new Map();  // rit.id  → 'HH:MM'
    const btMap = new Map();  // blok.id → 'HH:MM'
    const wsBlok = blokken.find(b => b.blok_type === 'wedstrijdstart' && b.tijdstip);
    if (wsBlok) {
        const d = wsBlok.tijdstip.split(':').map(Number);
        const wsSec = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
        let cur = wsSec, gestart = false;
        // Combi: gelijke starttijd, geen dubbele heat-duur optellen
        let prevRitCombi = null;
        let prevRitCurSec = null;
        for (const rij of rijdenP) {
            if (rij.type === 'wedstrijdstart') {
                // Multi-day: elke wsstart reset cur naar eigen tijdstip
                if (rij.blok.tijdstip) {
                    const dd = rij.blok.tijdstip.split(':').map(Number);
                    cur = (dd[0] || 0) * 3600 + (dd[1] || 0) * 60;
                }
                // Multi-day: geclaimde voorgangers achterwaarts plaatsen
                const _wsIdExt = parseInt(rij.blok.id);
                if (_geclaimdVoorWsExt.has(_wsIdExt)) {
                    const _cl = _geclaimdVoorWsExt.get(_wsIdExt);
                    let _back = cur;
                    for (let _k = _cl.length - 1; _k >= 0; _k--) {
                        _back -= (parseInt(_cl[_k].duur) || 0) * 60;
                        btMap.set(_cl[_k].id, mNT(_back));
                    }
                }
                btMap.set(rij.blok.id, mNT(cur));
                gestart = true;
                prevRitCombi = null;
            } else if (gestart) {
                if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                    // Multi-day: sla geclaimde over (tijd al achterwaarts gezet)
                    if (_geclaimdeBlokIdsExt.has(parseInt(rij.blok.id))) {
                        prevRitCombi = null;
                        continue;
                    }
                    btMap.set(rij.blok.id, mNT(cur));
                    cur += (parseInt(rij.blok.duur) || 0) * 60;
                    prevRitCombi = null;
                } else if (rij.type === 'herstart') {
                    if (rij.blok.tijdstip) {
                        const d = rij.blok.tijdstip.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                    }
                    btMap.set(rij.blok.id, mNT(cur));
                    prevRitCombi = null;
                } else if (rij.type === 'rit') {
                    const combiGrp    = rij.rit.combi_group ? parseInt(rij.rit.combi_group) : null;
                    const zelfdeCombi = combiGrp !== null && combiGrp === prevRitCombi;
                    if (rij.rit.tijdstip_override) {
                        const d = rij.rit.tijdstip_override.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                        stMap.set(rij.rit.id, mNT(cur));
                        prevRitCurSec = cur;
                        cur += heatDuurMapP.get(parseInt(rij.rit.blok_id)) || 0;
                    } else if (zelfdeCombi) {
                        stMap.set(rij.rit.id, mNT(prevRitCurSec));
                    } else {
                        stMap.set(rij.rit.id, mNT(cur));
                        prevRitCurSec = cur;
                        cur += heatDuurMapP.get(parseInt(rij.rit.blok_id)) || 0;
                    }
                    prevRitCombi = combiGrp;
                }
            }
        }
        const wsRijIdxP = rijdenP.findIndex(r => r.type === 'wedstrijdstart');
        let back = wsSec;
        for (let i = wsRijIdxP - 1; i >= 0; i--) {
            const rij = rijdenP[i];
            if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                back -= (parseInt(rij.blok.duur) || 0) * 60;
                btMap.set(rij.blok.id, mNT(back));
            }
        }
    }

    // Opzoeklijsten
    const catCfgMap = {};
    (schema.cat_configs ?? []).forEach(cc => { catCfgMap[cc.dc_id + '|' + (cc.distance_id ?? '')] = cc; });
    const dcNaam = new Map();
    bouwAfstandGroepen().forEach(af => af.cats.forEach(c => { if (!dcNaam.has(c.dc_id)) dcNaam.set(c.dc_id, c.dc_naam); }));

    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    // Volgende ronde naam op basis van cat-config
    const volgendeRonde = (rondeType, cc) => {
        if (!cc) return '';
        if (rondeType === 'heats') {
            if (cc.heeft_kwartfinale)  return T('algemeen.kwart_finale');
            if (cc.heeft_halve_finale) return T('algemeen.halve_finale');
            return T('algemeen.finale');
        }
        if (rondeType === 'kwartfinale') {
            return cc.heeft_halve_finale ? T('algemeen.halve_finale') : T('algemeen.finale');
        }
        if (rondeType === 'halve_finale') return T('algemeen.finale');
        return '';
    };

    // Doorstroom-tekst per ronde-type (met → volgende ronde). Voor full-final
    // tonen we hoe rijders in series → A-/B-finale geseed worden zodat publiek
    // en rijders weten hoe te kwalificeren voor de A-finale.
    // Bij gebruik van Q-kwalificatie wordt een ¹-marker toegevoegd die
    // verwijst naar de Q/q-legenda onderaan het programma.
    const isFFschema = schema.systeem === 'full-final';
    const QM = '¹'; // voetnoot-marker (Unicode superscript-1)
    // Kleine-finale-suffix bouwer voor internationaal-nieuw: als deze
    // ronde de LAATSTE afvalronde vóór de A-finale is en de afstand
    // heeft heeft_kleine_finale aan, voeg een tekst toe zoals
    // ", 3 en 4 op tijd Kleine finale" achter " → Finale". Cap: kleine
    // finale mag nooit meer rijders bevatten dan de A-finale (zie
    // rationale in tijdschema.php / commit b166779).
    const bouwKfBereik = (start, aantal) => {
        if (aantal <= 0) return '';
        if (aantal === 1) return `${start}`;
        const nrs = [];
        for (let i = 0; i < aantal; i++) nrs.push(start + i);
        const conj = T('algemeen.en_conj');
        return nrs.slice(0, -1).join(', ') + ` ${conj} ${nrs[nrs.length - 1]}`;
    };
    const kfSuffix = (cc, afCfg, doorstromers, totRj) => {
        if (isFFschema) return '';
        if (!afCfg || !Number(afCfg.heeft_kleine_finale)) return '';
        if (doorstromers <= 0 || totRj <= 0) return '';
        const kfRruw = Math.max(0, totRj - doorstromers);
        const kfR    = Math.min(kfRruw, doorstromers);
        if (kfR <= 0) return '';
        const bereik = bouwKfBereik(doorstromers + 1, kfR);
        return T('prog_extern.kf_suffix', {
            bereik,
            label: T('algemeen.kleine_finale'),
        });
    };
    const doorTxt = (rondeType, cc, nHeats, totRj, afCfg) => {
        if (!cc) return '';
        const vR = volgendeRonde(rondeType, cc);
        const naar = vR ? ` → ${vR}` : '';
        switch (rondeType) {
            case 'heats': {
                if (isFFschema) {
                    // Full-final: heats_q_heat per serie (Q) + tijdsnelsten (q)
                    // → A-finale (finale_a_grootte). Rest → B-finale(s).
                    const Qph = Math.max(0, parseInt(cc.heats_q_heat) || 0);
                    const aFin = parseInt(cc.finale_a_grootte) || 0;
                    const totQ = Qph * nHeats;
                    const aQ   = Math.min(totQ, aFin);
                    const aq   = Math.max(0, aFin - aQ);
                    const bH   = parseInt(cc.finale_b_heats) || 0;
                    const restNaarB = (totRj || 0) > aFin && bH > 0;
                    const aFinaleLabel = T('algemeen.a_finale');
                    const finaleDeel = (Qph > 0)
                        ? T('prog_extern.ff_a_finale_q', { a_finale: aFinaleLabel, aQ, aq })
                        : T('prog_extern.ff_a_finale_n', { a_finale: aFinaleLabel, aFin });
                    const bDeel = restNaarB
                        ? T(bH > 1 ? 'prog_extern.b_finales_suffix' : 'prog_extern.b_finale_suffix',
                            { b_finale: T('algemeen.b_finale') })
                        : '';
                    return (Qph > 0)
                        ? T('prog_extern.ff_q_naar_a', {
                              Qph, extra: Math.max(0, aFin - totQ),
                              m: QM, finaleDeel, bDeel })
                        : T('prog_extern.ff_tijd_naar_a', { aFin, finaleDeel, bDeel });
                }
                const q = parseInt(cc.heats_q) || 0;
                // Kleine-finale-suffix alleen als deze heats de LAATSTE
                // afvalronde vóór A-finale is (geen kwart, geen halve).
                const kfHeats = (!cc.heeft_kwartfinale && !cc.heeft_halve_finale)
                    ? kfSuffix(cc, afCfg, q, totRj) : '';
                return T('prog_extern.top_n_op_tijd', { n: q }) + naar + kfHeats;
            }
            case 'kwartfinale': {
                const kD = parseInt(cc.kwart_door)   || 0;
                const kQ = parseInt(cc.kwart_q_heat) || 0;
                const kq = Math.max(0, kD - kQ * nHeats);
                const m  = (kQ >= 1) ? QM : '';
                // Kleine-finale-suffix alleen als deze kwart de LAATSTE
                // afvalronde vóór A-finale is (geen halve).
                const kfKwart = (!cc.heeft_halve_finale)
                    ? kfSuffix(cc, afCfg, kD, totRj) : '';
                return T('prog_extern.qheat_q_door', { Q: kQ, q: kq, m, d: kD }) + naar + kfKwart;
            }
            case 'halve_finale': {
                const hD = parseInt(cc.half_door)    || 0;
                const hQ = parseInt(cc.half_q_heat)  || 0;
                const hq = Math.max(0, hD - hQ * nHeats);
                const m  = (hQ >= 1) ? QM : '';
                // Halve is per definitie de laatste afvalronde vóór A-finale.
                const kfHalve = kfSuffix(cc, afCfg, hD, totRj);
                return T('prog_extern.qheat_q_door', { Q: hQ, q: hq, m, d: hD }) + naar + kfHalve;
            }
            default: return '';
        }
    };

    // ── Org-logo header + sponsors footer (gedeelde helper) ─────────────────
    const { orgLogoHtml, baanLogoHtml, footerHtml } = bouwOrgHeaderFooter(esc);

    // ── HTML via rijen (volgorde-gebaseerd) ──────────────────────────────────
    let bloHtml = '';
    // Eenmalig per programma de Q/q-voetnoot tonen onder het eerste blok dat
    // Q-kwalificatie gebruikt. Daarna verwijst elke ¹ in de tabel naar deze
    // uitleg zonder herhaling.
    let _qqLegendaGetoond = false;
    // Runner-up legenda — apart van qq. Toont één regel uitleg over het
    // RU-concept wanneer minstens één cat 'heeft_runner_up' aan heeft staan.
    // Plaatsing: bij eerste blok dat een afval-ronde is (heats/kwart/halve),
    // zo verschijnt 'em vlak voor de cat-rij waar de runner-up daadwerkelijk
    // relevant wordt — meestal direct na de qq-voetnoot, samen in dezelfde
    // visuele blok.
    let _ruLegendaGetoond = false;

    // Helper: flush een verzamelde sectie (ritten van één ronde-blok) naar HTML
    const flushSectie = (sectieRitten, blok) => {
        if (!sectieRitten.length || !blok) return;
        // i18n: rondelabel-mapping (Series/Kwart/Halve/Finale/A-F/B-F/RU)
        const _rondeLabelMap = {
            heats:        T('algemeen.serie'),
            kwartfinale:  T('algemeen.kwart_finale'),
            halve_finale: T('algemeen.halve_finale'),
            runner_up:    T('algemeen.runner_up'),
            finale:       T('algemeen.finale'),
            finale_a:     T('algemeen.a_finale'),
            finale_b:     T('algemeen.b_finale'),
        };
        const rondeLabel = _rondeLabelMap[blok.ronde_type] ?? blok.ronde_type;
        const eersteTijd = stMap.get(sectieRitten[0].id) ?? '';
        // Effectief heat-aantal: combi-groepen rijden tegelijk en tellen
        // dus als 1 heat voor totaal-duurberekening. Rij-telling sectie-
        // Ritten.length zou anders bij full-final met meerdere combi's de
        // duur 2x of 3x overdrijven.
        const _combiSeen = new Set();
        let nHeats = 0;
        for (const r of sectieRitten) {
            const cg = r.combi_group ? parseInt(r.combi_group) : null;
            if (cg !== null) {
                if (_combiSeen.has(cg)) continue;
                _combiSeen.add(cg);
            }
            nHeats++;
        }
        const hd         = parseInt(blok.heat_duur) || 0;   // seconden
        const hdTxt      = hd ? secNaarMmSs(hd) : '';
        const totaalMin  = hd ? Math.round(nHeats * hd / 60) : 0;
        const heatsWoord = T(nHeats > 1 ? 'algemeen.heats_n' : 'algemeen.heat_n', { n: nHeats });
        const duurInfo   = hd
            ? T('prog_extern.heats_x_dur', { heats: heatsWoord, dur: hdTxt, tot: totaalMin })
            : heatsWoord;

        // Groepeer per categorie (dc_id + distance_id), volgorde bewaren
        const catMap = new Map();
        sectieRitten.forEach(r => {
            const key = r.dc_id + '|' + (r.distance_id ?? '');
            if (!catMap.has(key)) catMap.set(key, { naam: r.dc_naam, ritten: [], key });
            catMap.get(key).ritten.push(r);
        });

        const isFinale = blok.ronde_type === 'finale';

        // Render-helper voor één cat-rij (eventueel met override-rijen eronder)
        const renderCatRij = ({ naam, ritten: cr, key }) => {
            const cc = catCfgMap[key];
            const nH = cr.length;
            const totRj = cr.reduce((s, r) => s + (parseInt(r.verwacht) || 0), 0);
            const catTijd = stMap.get(cr[0].id) ?? '';
            let detail;
            if (isFinale) {
                const seen = new Map();
                cr.forEach(r => {
                    const fl = r.finale_label ?? (r.ronde_type === 'finale_a' ? 'A' : '?');
                    seen.set(fl, (seen.get(fl) ?? 0) + (parseInt(r.verwacht) || 0));
                });
                detail = [...seen].map(([lbl, n]) =>
                    T('prog_extern.finale_label_n', { lbl: esc(lbl), finale: T('algemeen.finale'), n })
                ).join(' · ');
            } else {
                const afCfg = vindAfstandConfig(schema, blok.afstand_naam);
                const dt = doorTxt(blok.ronde_type, cc, nH, totRj, afCfg);
                const heatsStr = T(nH > 1 ? 'algemeen.heats_n' : 'algemeen.heat_n', { n: nH });
                const rijdersStr = T('algemeen.rijders_n', { n: totRj });
                detail = `${rijdersStr}, ${heatsStr}${dt ? ' · ' + esc(dt) : ''}`;
            }
            // Bouw de override-/opmerking-rijen apart en plaats ze BOVEN de
            // cat-rij — voor extern publiek leest dat natuurlijker: eerst
            // de uitzondering ("Category change for warm-up"), dan de
            // normale cat-regel. Voorheen stonden ze onder de cat-rij.
            let ovrHtml = '';
            cr.forEach((r, i) => {
                // Toon extra-rij voor ritten met EITHER een tijdstip-override
                // OF een opmerking. Voorheen alleen bij override — opmerking-
                // alleen ritten waren onzichtbaar in extern programma.
                if (!r.tijdstip_override && !r.opmerking) return;
                const heeftOv = !!r.tijdstip_override;
                const ovTijd  = heeftOv
                    ? (stMap.get(r.id) ?? r.tijdstip_override.substring(0, 5))
                    : (stMap.get(r.id) ?? '');
                const opmTxt  = r.opmerking ? ` — ${esc(r.opmerking)}` : '';
                const heatDeel = nH > 1 ? ` - heat ${i + 1}` : '';
                const icoon   = heeftOv ? '📌' : '📝';
                ovrHtml += `<div class="cat-ovr-rij">
                    <span class="cat-ovr-tijd">${esc(ovTijd)}</span>
                    <span class="cat-ovr-tekst">${icoon} ${esc(naam)}${esc(heatDeel)}${opmTxt}</span>
                </div>`;
            });
            return ovrHtml + `<div class="cat-rij">
                <span class="cat-tijd">${esc(catTijd)}</span>
                <span class="cat-naam">${esc(naam)}</span>
                <span class="cat-details">${detail}</span>
            </div>`;
        };

        // Verzamel cats met combi-info; groepeer consecutieve cats met dezelfde
        // combi_group in één kader. Zo ontstaan geen per ongeluk aaneengesloten
        // rechthoeken van twee aparte combi-groepen.
        const catList = [...catMap.values()].map(entry => {
            const grps = [...new Set(entry.ritten.map(r => r.combi_group).filter(Boolean))];
            return { ...entry, combiGrp: grps[0] || null };
        });

        let catHtml = '';
        let i = 0;
        while (i < catList.length) {
            const entry = catList[i];
            if (entry.combiGrp) {
                let j = i;
                while (j < catList.length && catList[j].combiGrp === entry.combiGrp) j++;
                // Alle rijen binnen dit combi-kader samen
                const binnen = catList.slice(i, j).map(renderCatRij).join('');
                const aantal = j - i;
                catHtml += `<div class="combi-box">
                    <div class="combi-box-kop">${esc(T('prog_extern.combi_kop', { n: aantal }))}</div>
                    ${binnen}
                </div>`;
                i = j;
            } else {
                catHtml += renderCatRij(entry);
                i++;
            }
        }

        bloHtml += `<div class="blok ronde">
            <div class="blok-kop">
                <span class="blok-tijd">${esc(eersteTijd)}</span>
                <span class="blok-titel">${esc(rondeLabel)} ${esc(blok.afstand_naam ?? '')}</span>
                <span class="blok-info">${esc(duurInfo)}</span>
            </div>
            ${catHtml}
        </div>`;

        // Q/q-voetnoot — direct onder dit blok plaatsen als hier voor het eerst
        // Q-kwalificatie voorkomt. Daarna niet meer (eenmalig per programma).
        if (!_qqLegendaGetoond) {
            // Kwart/halve renderen ALTIJD een tekst met Q- én q-letter
            // ('{Q}Q/heat + {q}q → {d} rijders'), ook als Q per heat 0 is
            // (dan is er alleen tijd-doorstroom). Voetnoot dus triggeren op
            // '_door >= 1' (= er is überhaupt doorstroming) — niet op '_q_heat'
            // want dat mist de veelvoorkomende '0Q + Nq'-situatie.
            // Heats renderen 'top N op tijd' zonder Q/q-letter als heats_q_heat=0;
            // daar blijft de q_heat-check correct.
            const veld = blok.ronde_type === 'heats'        ? 'heats_q_heat'
                      : blok.ronde_type === 'kwartfinale'   ? 'kwart_door'
                      : blok.ronde_type === 'halve_finale'  ? 'half_door'
                      : null;
            if (veld) {
                const heeftQHier = [...catMap.keys()].some(k => {
                    const cc = catCfgMap[k];
                    return cc && (parseInt(cc[veld]) || 0) >= 1;
                });
                if (heeftQHier) {
                    // Korte one-liner voetnoot: past op 1 regel, breekt niet
                    // over pagina-einde, blijft dicht bij de ¹-markeringen.
                    // page-break-inside:avoid als vangnet voor wrap.
                    bloHtml += `<div class="qq-voetnoot" style="margin:4px 0 12px 18px;padding:4px 8px;font-size:9pt;color:#555;page-break-inside:avoid;break-inside:avoid">
  ${T('prog_extern.qq_voetnoot')}
</div>`;
                    _qqLegendaGetoond = true;
                }
            }
        }
        // Runner-up voetnoot — separaat van Q/q. Verschijnt eenmalig, bij het
        // eerste afval-ronde-blok (heats/kwart/halve) wanneer minstens één
        // cat 'heeft_runner_up' aan heeft. Reden voor scheiding: een wedstrijd
        // kan wel runner-ups hebben maar geen Q-systeem (bv. zuiver knock-out
        // tussen heats en finale via runner-up). Andersom kan ook.
        if (!_ruLegendaGetoond) {
            const isAfvalRonde = blok.ronde_type === 'heats'
                              || blok.ronde_type === 'kwartfinale'
                              || blok.ronde_type === 'halve_finale';
            if (isAfvalRonde) {
                const heeftRUHier = [...catMap.keys()].some(k => {
                    const cc = catCfgMap[k];
                    return cc && !!cc.heeft_runner_up;
                });
                if (heeftRUHier) {
                    bloHtml += `<div class="qq-voetnoot" style="margin:4px 0 12px 18px;padding:4px 8px;font-size:9pt;color:#555;page-break-inside:avoid;break-inside:avoid">
  ${T('prog_extern.ru_voetnoot')}
</div>`;
                    _ruLegendaGetoond = true;
                }
            }
        }
    };

    // Itereer rijen; groepeer opeenvolgende ritten van hetzelfde ronde-blok
    let huidigeBlokId = null;
    let huidigeSectie = [];
    // Multi-day: track gerendered dag om dag-headers + page-breaks te injecteren
    let _laatstGerenderdeDagExt = 0;

    for (const rij of rijdenP) {
        // Multi-day: dag-header invoegen bij dag-wissel (en page-break vanaf
        // dag 2). Eerst lopende sectie flushen voor schone overgang.
        if (_dagInfoExt.isMultiDag) {
            let _dagNrRij;
            if (rij.type === 'rit') {
                _dagNrRij = _dagInfoExt.blokDagMap.get(parseInt(rij.rit.blok_id)) ?? 1;
            } else {
                _dagNrRij = _dagInfoExt.blokDagMap.get(parseInt(rij.blok?.id)) ?? 1;
            }
            if (_dagNrRij !== _laatstGerenderdeDagExt) {
                flushSectie(huidigeSectie, blokById.get(huidigeBlokId));
                huidigeBlokId = null;
                huidigeSectie = [];
                const _dl = _dagInfoExt.dagLabels.find(d => d.nr === _dagNrRij);
                const _pbCls = _laatstGerenderdeDagExt > 0 ? ' prog-dag-pagebreak' : '';
                // Niet _dl.label gebruiken — die is vooraf NL-geformatteerd.
                // Herbouw uit nr + datum zodat hij de print-taal volgt.
                const _dagWoord = T('algemeen.dag_n', { nr: _dagNrRij });
                const _dagDatum = _dl?.datum
                    ? new Date(_dl.datum).toLocaleDateString(LOC,
                        { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
                    : '';
                const _dagHeader = _dagDatum ? `${_dagWoord} — ${_dagDatum}` : _dagWoord;
                bloHtml += `<h2 class="prog-dag-header${_pbCls}">${esc(_dagHeader)}</h2>`;
                _laatstGerenderdeDagExt = _dagNrRij;
            }
        }

        if (rij.type === 'rit') {
            const bidInt = parseInt(rij.rit.blok_id);
            if (bidInt !== huidigeBlokId) {
                // Flush vorige sectie voor een nieuw blok begint
                flushSectie(huidigeSectie, blokById.get(huidigeBlokId));
                huidigeBlokId = bidInt;
                huidigeSectie = [];
            }
            huidigeSectie.push(rij.rit);
        } else {
            // Non-ronde blok: flush lopende sectie, render dan het blok
            flushSectie(huidigeSectie, blokById.get(huidigeBlokId));
            huidigeBlokId = null;
            huidigeSectie = [];

            const blok  = rij.blok;
            const bTijd = btMap.get(blok.id) ?? '';

            if (rij.type === 'wedstrijdstart') {
                const ts = blok.tijdstip?.substring(0, 5) ?? '—';
                bloHtml += `<div class="blok wsstart">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd || ts)}</span>
                        <span class="blok-titel">${T('prog_extern.wsstart')}</span>
                    </div></div>`;

            } else if (rij.type === 'pauze') {
                const duur = blok.duur ? T('algemeen.min_unit', { n: blok.duur }) : '';
                const opm  = blok.opmerking ? ` — ${esc(blok.opmerking)}` : '';
                bloHtml += `<div class="blok pauze">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd)}</span>
                        <span class="blok-titel">⏸ ${esc(T('algemeen.pauze'))}${opm}</span>
                        ${duur ? `<span class="blok-info">${esc(duur)}</span>` : ''}
                    </div></div>`;

            } else if (rij.type === 'inrijden') {
                const duur    = blok.duur ? T('algemeen.min_unit', { n: blok.duur }) : '';
                const catIds  = (() => { try { return JSON.parse(blok.inrijd_cats || '[]'); } catch(e) { return []; } })();
                const catNamen = catIds.map(id => esc(dcNaam.get(id) ?? id)).join(', ');
                bloHtml += `<div class="blok inrijd">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd)}</span>
                        <span class="blok-titel">🛼 ${esc(T('algemeen.inrijden'))}</span>
                        ${duur ? `<span class="blok-info">${esc(duur)}</span>` : ''}
                    </div>
                    ${catNamen ? `<div class="blok-cats">${catNamen}</div>` : ''}
                </div>`;

            } else if (rij.type === 'ceremonie') {
                const duur = blok.duur ? T('algemeen.min_unit', { n: blok.duur }) : '';
                const opm  = blok.opmerking ? ` — ${esc(blok.opmerking)}` : '';
                bloHtml += `<div class="blok cerem">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd)}</span>
                        <span class="blok-titel">🏆 ${esc(T('algemeen.ceremonie'))}${opm}</span>
                        ${duur ? `<span class="blok-info">${esc(duur)}</span>` : ''}
                    </div></div>`;

            } else if (rij.type === 'herstart') {
                const ts  = blok.tijdstip?.substring(0, 5) ?? '—';
                const opm = blok.opmerking ? ` — ${esc(blok.opmerking)}` : '';
                bloHtml += `<div class="blok herstart">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd || ts)}</span>
                        <span class="blok-titel">${T('prog_extern.herstart')}${opm}</span>
                    </div></div>`;
            }
        }
    }
    // Flush laatste sectie
    flushSectie(huidigeSectie, blokById.get(huidigeBlokId));

    // Locale-aware wedstrijddatum (formatDatum() is hardcoded nl-NL).
    const datum = comp?.starts
        ? new Date(comp.starts).toLocaleDateString(LOC,
            { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
        : '';
    const locatie = comp ? getLocatie(comp) : '';
    const metaTxt = [datum, locatie].filter(Boolean).map(esc).join(' &nbsp;·&nbsp; ');

    const extraCss = `
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:10.5pt;margin:.6cm 1.2cm 1.2cm;color:#111;line-height:1.5}
.pagina-header{display:flex;flex-wrap:nowrap;align-items:stretch;justify-content:space-between;
               gap:4mm;margin-bottom:0}
.hdr-links{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:flex-end}
.hdr-comp{font-size:16pt;font-weight:700;line-height:1.2;margin-bottom:.5mm}
.hdr-meta{font-size:9.5pt;color:#555}
.hdr-versie{font-size:8pt;color:#999;margin-top:1mm}
.hdr-baan{flex-shrink:0;display:flex;align-items:flex-start}
.hdr-rechts{flex-shrink:0;display:flex;align-items:flex-start}
.hdr-lijn{border:none;border-top:2px solid #1a3a5c;margin:.4cm 0 .5cm 0}
.disclaimer{background:#fffbee;border:1px solid #e6c800;border-left:4px solid #e6c800;
            padding:.3cm .5cm;font-size:9pt;color:#7a5800;margin-bottom:.7cm;border-radius:3px}
/* Multi-day dag-header: alleen bij >1 wedstrijdstart. Dag 2+ krijgt
   page-break-before via .prog-dag-pagebreak zodat elke dag op een eigen
   pagina begint. Header is bewust groot/duidelijk om verwarring tussen
   dagen te voorkomen. */
.prog-dag-header{font-size:15pt;font-weight:700;color:#1a3a5c;
                 margin:0 0 .35cm 0;padding-bottom:.15cm;
                 border-bottom:3px solid #1a3a5c;page-break-after:avoid}
.prog-dag-pagebreak{page-break-before:always}
.blok{margin-bottom:.45cm;page-break-inside:avoid}
.blok-kop{display:flex;align-items:baseline;gap:.5cm;border-bottom:1.5px solid #ddd;
          padding-bottom:.1cm;margin-bottom:.15cm}
.blok-tijd{font-size:11pt;font-weight:700;color:#003366;min-width:1.4cm;flex-shrink:0;
           font-variant-numeric:tabular-nums}
.blok-titel{font-size:11pt;font-weight:700;flex:1}
.blok-info{font-size:9pt;color:#666;white-space:nowrap}
.blok-cats{padding-left:1.9cm;font-size:10pt;color:#444}
.cat-rij{display:flex;gap:.4cm;padding:.04cm 0 .04cm 1.9cm;font-size:10pt;align-items:baseline}
.combi-box{border:2px solid #2E75B6;border-radius:5px;background:#eef4fb;
           margin:.2cm 1cm .2cm .95cm;padding:.1cm 0 .1cm;page-break-inside:avoid}
.combi-box + .combi-box{margin-top:.25cm}
.combi-box-kop{background:#2E75B6;color:#fff;font-size:9pt;font-weight:700;
               padding:2mm 4mm;letter-spacing:.02em;margin:-.1cm 0 .1cm 0;
               border-top-left-radius:3px;border-top-right-radius:3px}
.combi-box .cat-rij{padding-left:.9cm}
.cat-tijd{min-width:1.1cm;flex-shrink:0;font-variant-numeric:tabular-nums;color:#555;font-size:9.5pt}
.cat-naam{font-weight:600;flex-shrink:0}
.cat-details{color:#444;font-size:9.5pt}
.wsstart .blok-kop{border-bottom:2px solid #2e8b2e}
.wsstart .blok-titel,.wsstart .blok-tijd{color:#1e5c1e}
.pauze .blok-kop{border-bottom-color:#f0c040}
.pauze .blok-titel{color:#8a6000}
.inrijd .blok-kop{border-bottom-color:#4a7fd4}
.inrijd .blok-titel{color:#1a3d8a}
.cerem .blok-kop{border-bottom:2px solid #c0392b}
.cerem .blok-titel,.cerem .blok-tijd{color:#8b1a1a}
.herstart .blok-kop{border-bottom:2px solid #c47200}
.herstart .blok-titel,.herstart .blok-tijd{color:#7a4200;font-weight:700}
.cat-ovr-rij{display:flex;align-items:baseline;gap:.4cm;padding:.05cm 0 .05cm 1.9cm;
             border-left:3px solid #c47200;margin:.05cm 0 .05cm .3cm;background:#fff8ee}
.cat-ovr-tijd{min-width:1.1cm;flex-shrink:0;font-variant-numeric:tabular-nums;
              color:#c47200;font-weight:700;font-size:9.5pt}
.cat-ovr-tekst{font-size:9.5pt;color:#7a4200}
@media print{
  body{margin:.5cm 1cm 1cm}
  .blok{page-break-inside:avoid}
  @page{margin:1cm 1.2cm;size:A4 portrait}
  /* Sponsors-footer aan het laatste blok plakken — voorkomt dat alleen
     de footer als laatste op een verder lege pagina belandt. */
  .org-sponsor-footer{
    page-break-before:avoid; break-before:avoid;
    page-break-inside:avoid; break-inside:avoid;
  }
  /* Ceremonie-blok aan de afstand ervoor plakken — voorkomt dat een
     Ceremony als enige item bovenaan een nieuwe pagina belandt terwijl
     het bovenliggende afstand-blok onderaan de vorige zit. Bij conflict
     pakt browser de afstand óók mee naar de nieuwe pagina. */
  .blok.cerem{
    page-break-before:avoid; break-before:avoid;
  }
}
`;
    const _genDatumStr = schema.gegenereerd_op
        ? new Date(schema.gegenereerd_op.replace(' ','T')+'Z').toLocaleString(LOC,
            { day:'2-digit', month:'long', year:'numeric', hour:'2-digit', minute:'2-digit' })
        : '';
    const bodyHtml = `
<div class="pagina-header">
  <div class="hdr-links">
    <div class="hdr-comp">${esc(comp?.name ?? T('prog_extern.titel_default'))}</div>
    ${metaTxt ? `<div class="hdr-meta">${metaTxt}</div>` : ''}
    ${_genDatumStr ? `<div class="hdr-versie">${esc(T('prog_extern.gegen_op_label', { datum: _genDatumStr }))}</div>` : ''}
  </div>
  ${baanLogoHtml ? `<div class="hdr-baan">${baanLogoHtml}</div>` : ''}
  <div class="hdr-rechts">${orgLogoHtml}</div>
</div>
<hr class="hdr-lijn">
<div class="disclaimer">
  ${T('prog_extern.disclaimer')}
</div>
${bloHtml}
${footerHtml}
`;

    return {
        bodyHtml:        bodyHtml,
        cssLinks:        [],
        extraCss:        extraCss,
        pageOrientation: 'portrait',
        title:           T('prog_extern.titel_default') + (comp?.name ? ' — ' + comp.name : ''),
        subType:         T('prog_extern.subtype'),
    };
}

// Publieke body-builder voor Print-Center.
function bouwProgrammaExternBody() {
    if (!huidigTijdschema) return null;
    return _bouwProgrammaExternInternal();
}

// publiceerTijdschema() is verwijderd — printen gebeurt nu via Print-Center
// dat `bouwProgrammaExternBody()` gebruikt.

// ── Publiceer intern tijdschema ───────────────────────────────────────────────
// Interne body-bouwer — returns { bodyHtml, cssLinks, extraCss, title } of
// null. Aangeroepen door `bouwProgrammaInternBody()` (voor Print-Center) en
// door `publiceerTijdschemaIntern()` (de eigen knop op Tijdschema-pagina).

function _bouwProgrammaInternInternal() {
    const schema = huidigTijdschema;
    const comp   = huidigComp;
    if (!schema) return null;

    // i18n-helper voor Print-Center taalkeuze (NL/EN).
    const T    = window._pcT    || (k => k);
    const LANG = (window._pcLang && window._pcLang()) || 'nl';
    const LOC  = LANG === 'en' ? 'en-GB' : 'nl-NL';

    // Ronde-label-map (TS_RONDE_LABEL is hardcoded NL — alleen voor UI elders).
    const _rondeLabelMap = {
        heats:        T('algemeen.serie'),
        kwartfinale:  T('algemeen.kwart_finale'),
        halve_finale: T('algemeen.halve_finale'),
        runner_up:    T('algemeen.runner_up'),
        finale:       T('algemeen.finale'),
        finale_a:     T('algemeen.a_finale'),
        finale_b:     T('algemeen.b_finale'),
    };

    const ritten  = schema.ritten  ?? [];
    const blokken = schema.blokken ?? [];

    const mNT = sec => {
        const s = Math.round(parseInt(sec) || 0);
        return `${String(Math.floor(s / 3600) % 24).padStart(2,'0')}:${String(Math.floor((s % 3600) / 60)).padStart(2,'0')}`;
    };

    // ── Hulpstructuren ────────────────────────────────────────────────────────
    const blokById = new Map((blokken ?? []).map(b => [parseInt(b.id), b]));
    const heatDuurMap = new Map(
        (blokken ?? []).filter(b => b.blok_type === 'ronde')
                       .map(b => [parseInt(b.id), parseInt(b.heat_duur) || 0])
    );
    const nonRondeBlokken = (blokken ?? [])
        .filter(b => b.blok_type !== 'ronde')
        .sort((a, b) => (parseInt(a.volgorde) || 0) - (parseInt(b.volgorde) || 0));
    const rondeBlokVolgorde = new Map(
        (blokken ?? []).filter(b => b.blok_type === 'ronde')
                       .map(b => [parseInt(b.id), parseInt(b.volgorde) || 0])
    );

    // ── Bouw rijen ────────────────────────────────────────────────────────────
    const rijen = [];
    let nrbIdx = 0;
    for (const r of ritten) {
        const rBV = rondeBlokVolgorde.get(parseInt(r.blok_id)) ?? 0;
        while (nrbIdx < nonRondeBlokken.length &&
               (parseInt(nonRondeBlokken[nrbIdx].volgorde) || 0) <= rBV) {
            const nb = nonRondeBlokken[nrbIdx++];
            rijen.push({ type: nb.blok_type, blok: nb });
        }
        rijen.push({ type: 'rit', rit: r });
    }
    while (nrbIdx < nonRondeBlokken.length) {
        rijen.push({ type: nonRondeBlokken[nrbIdx].blok_type, blok: nonRondeBlokken[nrbIdx++] });
    }

    // ── Multi-day claim-info (warm-up vóór wsstart bij opvolgende dag) ───────
    const _dagInfoInt = _tsBouwDagInfo(blokken);
    const _geclaimdVoorWsInt   = _dagInfoInt.geclaimdVoorWs;
    const _geclaimdeBlokIdsInt = _dagInfoInt.geclaimdeBlokIds;

    // ── Starttijden berekenen ─────────────────────────────────────────────────
    const stMap = new Map();
    const btMap = new Map();
    const stRawMap = new Map();
    const wsBlok = blokken.find(b => b.blok_type === 'wedstrijdstart' && b.tijdstip);
    if (wsBlok) {
        const d = wsBlok.tijdstip.split(':').map(Number);
        const wsSec = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
        let cur = wsSec, gestart = false;
        // Combi: gelijke starttijd, geen dubbele heat-duur optellen
        let prevRitCombi = null;
        let prevRitCurSec = null;
        for (const rij of rijen) {
            if (rij.type === 'wedstrijdstart') {
                // Multi-day: elke wsstart reset cur naar eigen tijdstip
                if (rij.blok.tijdstip) {
                    const dd = rij.blok.tijdstip.split(':').map(Number);
                    cur = (dd[0] || 0) * 3600 + (dd[1] || 0) * 60;
                }
                // Multi-day: geclaimde voorgangers achterwaarts plaatsen
                const _wsIdInt = parseInt(rij.blok.id);
                if (_geclaimdVoorWsInt.has(_wsIdInt)) {
                    const _cl = _geclaimdVoorWsInt.get(_wsIdInt);
                    let _back = cur;
                    for (let _k = _cl.length - 1; _k >= 0; _k--) {
                        _back -= (parseInt(_cl[_k].duur) || 0) * 60;
                        btMap.set(_cl[_k].id, mNT(_back));
                    }
                }
                btMap.set(rij.blok.id, mNT(cur));
                gestart = true;
                prevRitCombi = null;
            } else if (gestart) {
                if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                    // Multi-day: sla geclaimde over (tijd al achterwaarts gezet)
                    if (_geclaimdeBlokIdsInt.has(parseInt(rij.blok.id))) {
                        prevRitCombi = null;
                        continue;
                    }
                    btMap.set(rij.blok.id, mNT(cur));
                    cur += (parseInt(rij.blok.duur) || 0) * 60;
                    prevRitCombi = null;
                } else if (rij.type === 'rit') {
                    const combiGrp    = rij.rit.combi_group ? parseInt(rij.rit.combi_group) : null;
                    const zelfdeCombi = combiGrp !== null && combiGrp === prevRitCombi;
                    if (rij.rit.tijdstip_override) {
                        const d = rij.rit.tijdstip_override.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                        stMap.set(rij.rit.id, mNT(cur));
                        stRawMap.set(rij.rit.id, cur);
                        prevRitCurSec = cur;
                        cur += heatDuurMap.get(parseInt(rij.rit.blok_id)) || 0;
                    } else if (zelfdeCombi) {
                        stMap.set(rij.rit.id, mNT(prevRitCurSec));
                        stRawMap.set(rij.rit.id, prevRitCurSec);
                    } else {
                        stMap.set(rij.rit.id, mNT(cur));
                        stRawMap.set(rij.rit.id, cur);
                        prevRitCurSec = cur;
                        cur += heatDuurMap.get(parseInt(rij.rit.blok_id)) || 0;
                    }
                    prevRitCombi = combiGrp;
                }
            }
        }
        const wsIdx = rijen.findIndex(r => r.type === 'wedstrijdstart');
        let back = wsSec;
        for (let i = wsIdx - 1; i >= 0; i--) {
            const rij = rijen[i];
            if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                back -= (parseInt(rij.blok.duur) || 0) * 60;
                btMap.set(rij.blok.id, mNT(back));
            }
        }
    }

    // ── Rusttijden ────────────────────────────────────────────────────────────
    const rustTijdMap = new Map();
    if (stRawMap.size > 0) {
        const calcGroepen = new Map();
        const renderGkNaarCk = new Map();
        const VOORGANGERS = {
            kwartfinale:  ['heats'],
            halve_finale: ['kwartfinale', 'heats'],
            finale_b:     ['halve_finale', 'kwartfinale', 'heats'],
            finale:       ['halve_finale', 'kwartfinale', 'heats'],
            finale_a:     ['halve_finale', 'kwartfinale', 'heats'],
            runner_up:    ['heats'],
        };
        for (const rij of rijen) {
            if (rij.type !== 'rit') continue;
            const rit = rij.rit;
            const sec = stRawMap.get(rit.id);
            if (sec === undefined) continue;
            const catKey   = `${rit.dc_id ?? ''}|${rit.distance_id ?? ''}|${rit.dc_naam ?? ''}`;
            const ck       = `${catKey}:${rit.ronde_type}`;
            const renderGk = `${rit.blok_id ?? ''}:${rit.dc_id}:${rit.dc_naam ?? ''}:${rit.ronde_type}`;
            const heatDuur = heatDuurMap.get(parseInt(rit.blok_id)) || 0;
            const endSec   = sec + heatDuur;
            if (!calcGroepen.has(ck)) {
                calcGroepen.set(ck, { catKey, rondeType: rit.ronde_type, firstSec: sec, lastEndSec: endSec });
            } else {
                const gt = calcGroepen.get(ck);
                if (sec < gt.firstSec)   gt.firstSec   = sec;
                if (endSec > gt.lastEndSec) gt.lastEndSec = endSec;
            }
            renderGkNaarCk.set(renderGk, ck);
        }
        const catRondeMap = new Map();
        for (const [ck, gt] of calcGroepen) {
            if (!catRondeMap.has(gt.catKey)) catRondeMap.set(gt.catKey, new Map());
            catRondeMap.get(gt.catKey).set(gt.rondeType, ck);
        }
        const rustTijdByCk = new Map();
        for (const [catKey, rondeMap] of catRondeMap) {
            for (const [rondeType, ck] of rondeMap) {
                const vgs = VOORGANGERS[rondeType];
                if (!vgs) continue;
                for (const vrt of vgs) {
                    const vorigeCk = rondeMap.get(vrt);
                    if (!vorigeCk) continue;
                    const prev = calcGroepen.get(vorigeCk);
                    const curr = calcGroepen.get(ck);
                    if (prev && curr) rustTijdByCk.set(ck, curr.firstSec - prev.lastEndSec);
                    break;
                }
            }
        }
        for (const [renderGk, ck] of renderGkNaarCk) {
            if (rustTijdByCk.has(ck)) rustTijdMap.set(renderGk, rustTijdByCk.get(ck));
        }
    }

    const heeftTijden = stMap.size > 0 || btMap.size > 0;
    const RUST_WARN_SEC = 30 * 60;

    const groepGrootte = {};
    rijen.forEach(r => {
        if (r.type === 'rit') {
            const gk = `${r.rit.blok_id ?? ''}:${r.rit.dc_id}:${r.rit.dc_naam ?? ''}:${r.rit.ronde_type}`;
            groepGrootte[gk] = (groepGrootte[gk] ?? 0) + 1;
        }
    });

    // ── Org-logo header + sponsors footer (gedeelde helper) ─────────────────
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const { orgLogoHtml, baanLogoHtml, footerHtml } = bouwOrgHeaderFooter(esc);

    // ── DC-namen opzoektabel ──────────────────────────────────────────────────
    const dcNaamMap = new Map();
    bouwAfstandGroepen().forEach(af =>
        af.cats.forEach(c => { if (!dcNaamMap.has(c.dc_id)) dcNaamMap.set(c.dc_id, c.dc_naam); })
    );

    // ── Tabel-rijen genereren ─────────────────────────────────────────────────
    const tijdTh = heeftTijden ? `<th class="ti">${esc(T('algemeen.tijd'))}</th>` : '';
    const restCols = 4;
    let tBody = '';
    let ritNr = 0;
    let prevGroepKey = null;
    // Multi-day: track gerendered dag om dag-header rows + page-breaks in te
    // voegen. Eerste dag-rij krijgt geen page-break; vanaf dag 2 wel.
    let _laatstGerenderdeDagInt = 0;

    rijen.forEach((rij, idx) => {
        const cols = heeftTijden ? restCols + 1 : restCols;
        // Multi-day: dag-header-rij invoegen bij dag-wissel
        if (_dagInfoInt.isMultiDag) {
            let _dagNrRij;
            if (rij.type === 'rit') {
                _dagNrRij = _dagInfoInt.blokDagMap.get(parseInt(rij.rit.blok_id)) ?? 1;
            } else {
                _dagNrRij = _dagInfoInt.blokDagMap.get(parseInt(rij.blok?.id)) ?? 1;
            }
            if (_dagNrRij !== _laatstGerenderdeDagInt) {
                const _dl = _dagInfoInt.dagLabels.find(d => d.nr === _dagNrRij);
                const _pbCls = _laatstGerenderdeDagInt > 0 ? ' prog-dag-pagebreak' : '';
                // Niet _dl.label gebruiken — die is NL-geformatteerd in
                // _tsBouwDagInfo. Herbouw uit nr + datum zodat 'ie de
                // print-taal volgt.
                const _dagWoord = T('algemeen.dag_n', { nr: _dagNrRij });
                const _dagDatum = _dl?.datum
                    ? new Date(_dl.datum).toLocaleDateString(LOC,
                        { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
                    : '';
                const _dagHeader = _dagDatum ? `${_dagWoord} — ${_dagDatum}` : _dagWoord;
                tBody += `<tr class="prog-dag-header-row${_pbCls}"><td colspan="${cols}">
                    <h2 class="prog-dag-header">${esc(_dagHeader)}</h2>
                </td></tr>`;
                _laatstGerenderdeDagInt = _dagNrRij;
                prevGroepKey = null; // schone start na dag-wissel
            }
        }
        if (rij.type === 'wedstrijdstart') {
            prevGroepKey = null;
            const ts = rij.blok?.tijdstip?.substring(0,5) ?? '—';
            const bTijd = btMap.get(rij.blok.id) ?? '';
            tBody += `<tr class="wsstart">
                ${heeftTijden ? `<td class="ti">${esc(bTijd || ts)}</td>` : ''}
                <td colspan="${restCols}" class="special">🏁 ${esc(T('algemeen.wedstrijdstart'))} — <strong>${esc(ts)}</strong></td>
            </tr>`;
        } else if (rij.type === 'pauze') {
            prevGroepKey = null;
            const bTijd = btMap.get(rij.blok.id) ?? '';
            const duurTxt = rij.blok?.duur ? ` – ${T('algemeen.min_unit', { n: rij.blok.duur })}` : '';
            const opmTxt  = rij.blok?.opmerking ? ` — ${esc(rij.blok.opmerking)}` : '';
            tBody += `<tr class="pauze">
                ${heeftTijden ? `<td class="ti">${esc(bTijd)}</td>` : ''}
                <td colspan="${restCols}" class="special">⏸ ${esc(T('algemeen.pauze'))}${esc(duurTxt)}${opmTxt}</td>
            </tr>`;
        } else if (rij.type === 'inrijden') {
            prevGroepKey = null;
            const bTijd = btMap.get(rij.blok.id) ?? '';
            const duurTxt = rij.blok?.duur ? ` – ${T('algemeen.min_unit', { n: rij.blok.duur })}` : '';
            const cats = (() => { try { return JSON.parse(rij.blok?.inrijd_cats || '[]'); } catch(e) { return []; } })();
            const catNamen = cats.map(id => esc(dcNaamMap.get(id) ?? id)).join(', ');
            tBody += `<tr class="inrijd">
                ${heeftTijden ? `<td class="ti">${esc(bTijd)}</td>` : ''}
                <td colspan="${restCols}" class="special">🛼 ${esc(T('algemeen.inrijden'))}${esc(duurTxt)}${catNamen ? ' — ' + catNamen : ''}</td>
            </tr>`;
        } else if (rij.type === 'ceremonie') {
            prevGroepKey = null;
            const bTijd = btMap.get(rij.blok.id) ?? '';
            const duurTxt = rij.blok?.duur ? ` – ${T('algemeen.min_unit', { n: rij.blok.duur })}` : '';
            const opmTxt  = rij.blok?.opmerking ? ` — ${esc(rij.blok.opmerking)}` : '';
            tBody += `<tr class="cerem">
                ${heeftTijden ? `<td class="ti">${esc(bTijd)}</td>` : ''}
                <td colspan="${restCols}" class="special">🏆 ${esc(T('algemeen.ceremonie'))}${esc(duurTxt)}${opmTxt}</td>
            </tr>`;
        } else if (rij.type === 'herstart') {
            prevGroepKey = null;
            const bTijd   = btMap.get(rij.blok.id) ?? '';
            const ts      = rij.blok?.tijdstip?.substring(0,5) ?? '—';
            const opmTxt  = rij.blok?.opmerking ? ` — ${esc(rij.blok.opmerking)}` : '';
            tBody += `<tr class="herstart">
                ${heeftTijden ? `<td class="ti">${esc(bTijd || ts)}</td>` : ''}
                <td colspan="${restCols}" class="special">🔄 ${esc(T('algemeen.herstart'))} — <strong>${esc(ts)}</strong>${opmTxt}</td>
            </tr>`;
        } else {
            const rit      = rij.rit;
            const groepKey = `${rit.blok_id ?? ''}:${rit.dc_id}:${rit.dc_naam ?? ''}:${rit.ronde_type}`;
            const kleur    = TS_RONDE_KLEUR[rit.ronde_type] ?? '#adb5bd';
            // i18n-bewuste label: TS_RONDE_LABEL is NL voor UI elders;
            // hier vertalen we via _rondeLabelMap (sleutel → T()).
            const label    = (rit.ronde_type === 'finale_b' && rit.finale_label)
                ? T('prog_intern.finale_label', { lbl: rit.finale_label, finale: T('algemeen.finale') })
                : (_rondeLabelMap[rit.ronde_type] ?? rit.ronde_type);
            const fin = (rit.ronde_type === 'finale_b') ? '' : (rit.finale_label ? ` ${rit.finale_label}` : '');

            if (groepKey !== prevGroepKey) {
                prevGroepKey = groepKey;
                const n    = groepGrootte[groepKey] ?? 1;
                const nTxt = n === 1
                    ? T('prog_intern.rit_1')
                    : T('algemeen.heats_n', { n });
                const gTijd = heeftTijden ? `<span class="gt">${stMap.get(rit.id) ?? '—'}</span>` : '';
                let rustHtml = '';
                if (rustTijdMap.has(groepKey)) {
                    const rustSec = rustTijdMap.get(groepKey);
                    const rustMin = Math.round(rustSec / 60);
                    const isWarn  = rustSec < RUST_WARN_SEC;
                    rustHtml = `<span class="rust${isWarn ? ' rust-warn' : ''}">${isWarn ? '⚠' : '✓'} ${esc(T('prog_intern.rust_min', { n: rustMin }))}</span>`;
                }
                tBody += `<tr class="groep-hdr">
                    <td colspan="${cols}">
                        ${gTijd}
                        <span class="badge" style="background:${kleur};color:#fff">${esc(label)}${esc(fin)}</span>
                        <strong>${esc(rit.dc_naam)}</strong>
                        <span class="gc">(${nTxt})</span>
                        ${rustHtml}
                    </td>
                </tr>`;
            }

            ritNr++;
            const hd = heatDuurMap.get(parseInt(rit.blok_id)) || 0;
            const hdTxt = hd ? secNaarMmSs(hd) : '';
            const hasOvr = !!rit.tijdstip_override;

            // Combi-logica: ritten met dezelfde combi_group als visuele groep
            const combiGrp    = rit.combi_group ? parseInt(rit.combi_group) : null;
            const prevRitTmp  = idx > 0 && rijen[idx - 1].type === 'rit' ? rijen[idx - 1].rit : null;
            const nextRijTmp  = rijen[idx + 1];
            const nextRitTmp  = nextRijTmp?.type === 'rit' ? nextRijTmp.rit : null;
            const prevCombi   = prevRitTmp?.combi_group ? parseInt(prevRitTmp.combi_group) : null;
            const nextCombi   = nextRitTmp?.combi_group ? parseInt(nextRitTmp.combi_group) : null;
            const isCombi     = combiGrp !== null;
            const isCombiLead = isCombi && combiGrp !== prevCombi;
            const isCombiEnd  = isCombi && combiGrp !== nextCombi;
            let combiCls = '';
            if (isCombi) {
                combiCls = ' combi';
                if (isCombiLead) combiCls += ' combi-start';
                if (isCombiEnd)  combiCls += ' combi-end';
            }
            // Ritnr: leider toont nummer + 🔗, leden tonen leeg
            const nrTxt = (isCombi && !isCombiLead) ? ''
                       : (isCombiLead ? `${ritNr} 🔗` : String(ritNr));

            if (rit.opmerking) {
                tBody += `<tr class="rit-opm">
                    ${heeftTijden ? '<td></td>' : ''}
                    <td></td>
                    <td colspan="3" class="opm">📝 ${esc(rit.opmerking)}</td>
                </tr>`;
            }
            tBody += `<tr class="rit-rij${combiCls}">
                ${heeftTijden ? `<td class="ti${hasOvr ? ' ti-ovr' : ''}">${stMap.get(rit.id) ?? '—'}${hasOvr ? '&nbsp;📌' : ''}</td>` : ''}
                <td class="nr">${nrTxt}</td>
                <td class="naam">${esc(rit.rit_naam)}${hdTxt ? `<span class="hd"> (${esc(hdTxt)})</span>` : ''}</td>
                <td><span class="badge sm" style="background:${kleur};color:#fff">${esc(label)}${esc(fin)}</span></td>
                <td class="vw">${rit.verwacht ?? '?'}</td>
            </tr>`;
        }
    });

    // Locale-aware wedstrijddatum (formatDatum is hardcoded nl-NL).
    const datum = comp?.starts
        ? new Date(comp.starts).toLocaleDateString(LOC,
            { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
        : '';
    const locatie = comp ? getLocatie(comp) : '';
    const metaTxt = [datum, locatie].filter(Boolean).map(esc).join(' &nbsp;·&nbsp; ');

    const extraCss = `
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9.5pt;margin:.5cm 1cm 1cm;color:#111;line-height:1.4}
.pagina-header{display:flex;flex-wrap:nowrap;align-items:stretch;justify-content:space-between;gap:4mm;margin-bottom:0}
.hdr-links{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:flex-end}
.hdr-comp{font-size:15pt;font-weight:700;line-height:1.2;margin-bottom:.5mm}
.hdr-meta{font-size:9pt;color:#555}
.hdr-versie{font-size:7.5pt;color:#999;margin-top:1mm}
.hdr-baan{flex-shrink:0;display:flex;align-items:flex-start}
.hdr-rechts{flex-shrink:0;display:flex;align-items:flex-start}
/* Multi-day dag-header in tabel (intern programma): vlakke <tr> die over
   alle kolommen spant. Dag 2+ krijgt page-break-before. */
tr.prog-dag-header-row td{padding:0!important;background:#fff!important}
tr.prog-dag-pagebreak{page-break-before:always}
h2.prog-dag-header{font-size:14pt;font-weight:700;color:#1a3a5c;
                   margin:.5cm 0 .25cm 0;padding-bottom:.12cm;
                   border-bottom:3px solid #1a3a5c}
.hdr-lijn{border:none;border-top:2px solid #1a3a5c;margin:.4cm 0 .4cm 0}
table{border-collapse:collapse;width:100%;font-size:9.5pt}
th{background:#1a3a5c;color:#fff;text-align:left;padding:3px 6px;font-size:8.5pt}
th.ti{width:1.3cm}
td{padding:2px 6px;vertical-align:middle}
td.ti{font-variant-numeric:tabular-nums;color:#003366;font-weight:700;white-space:nowrap}
td.nr{color:#666;font-size:8.5pt;width:.9cm;text-align:right}
td.naam{font-weight:500}
td.vw{text-align:right;width:1cm;color:#444}
.hd{font-size:8pt;color:#888;font-weight:400}
tr.groep-hdr td{background:#eef2f7;padding:4px 6px;font-size:9pt;border-top:1.5px solid #1a3a5c}
.gt{font-weight:700;color:#003366;margin-right:.4cm;font-variant-numeric:tabular-nums}
.gc{color:#666;font-size:8.5pt}
.badge{display:inline-block;padding:1px 5px;border-radius:3px;font-size:8pt;font-weight:700;margin-right:4px}
.badge.sm{font-size:7.5pt;padding:0 4px}
.rust{font-size:8pt;color:#2e7d32;margin-left:.5cm}
.rust-warn{color:#b26a00}
tr.wsstart td{background:#e8f5e9;color:#1e5c1e;font-weight:700;padding:4px 6px;border-top:1.5px solid #2e8b2e}
tr.herstart td{background:#fff3e0;color:#7a4200;font-weight:700;padding:4px 6px;border-top:1.5px solid #c47200}
tr.pauze td{background:#fffde7;color:#7a5800;padding:4px 6px}
tr.inrijd td{background:#e8eaf6;color:#1a3d8a;padding:4px 6px}
tr.cerem td{background:#fce4ec;color:#8b1a1a;padding:4px 6px}
tr.rit-rij:nth-child(even) td{background:#f9f9f9}
tr.rit-rij td{border-bottom:1px solid #eee}
tr.rit-rij.combi td{background:#eef4fb !important;border-left:3px solid #2E75B6;border-right:3px solid #2E75B6}
tr.rit-rij.combi td:first-child{border-left:3px solid #2E75B6}
tr.rit-rij.combi-start td{border-top:3px solid #2E75B6}
tr.rit-rij.combi-end td{border-bottom:3px solid #2E75B6}
td.ti-ovr{color:#c47200}
tr.rit-opm td{padding-bottom:0!important;border-bottom:none}
td.opm{font-size:8pt;color:#7a4200;font-style:italic;padding-left:12px;padding-top:4px}
@media print{
  body{margin:.4cm .9cm .9cm}
  tr{page-break-inside:avoid}
  tr.groep-hdr{page-break-before:auto}
  @page{margin:1cm 1.2cm;size:A4 portrait}
}
`;
    const _genDatumStr = schema.gegenereerd_op
        ? new Date(schema.gegenereerd_op.replace(' ','T')+'Z').toLocaleString(LOC,
            { day:'2-digit', month:'long', year:'numeric', hour:'2-digit', minute:'2-digit' })
        : '';
    const bodyHtml = `
<div class="pagina-header">
  <div class="hdr-links">
    <div class="hdr-comp">${esc(comp?.name ?? T('prog_extern.titel_default'))}</div>
    ${metaTxt ? `<div class="hdr-meta">${metaTxt}</div>` : ''}
    ${_genDatumStr ? `<div class="hdr-versie">${esc(T('prog_extern.gegen_op_label', { datum: _genDatumStr }))}</div>` : ''}
    <div class="hdr-versie" style="color:#b00">${esc(T('prog_intern.intern_warning'))}</div>
  </div>
  ${baanLogoHtml ? `<div class="hdr-baan">${baanLogoHtml}</div>` : ''}
  <div class="hdr-rechts">${orgLogoHtml}</div>
</div>
<hr class="hdr-lijn">
<table>
  <thead><tr>${tijdTh}<th class="nr">#</th><th>${esc(T('prog_intern.col_rit'))}</th><th>${esc(T('prog_intern.col_type'))}</th><th class="vw">${esc(T('prog_intern.col_verwacht'))}</th></tr></thead>
  <tbody>${tBody}</tbody>
</table>
${footerHtml}
`;

    return {
        bodyHtml:        bodyHtml,
        cssLinks:        [],
        extraCss:        extraCss,
        pageOrientation: 'portrait',
        title:           T('prog_intern.titel') + (comp?.name ? ' — ' + comp.name : ''),
        subType:         T('prog_intern.titel'),
    };
}

// Publieke body-builder voor Print-Center.
function bouwProgrammaInternBody() {
    if (!huidigTijdschema) return null;
    return _bouwProgrammaInternInternal();
}

// publiceerTijdschemaIntern() is verwijderd — printen gebeurt nu via
// Print-Center dat `bouwProgrammaInternBody()` gebruikt.
/* verwijderd:
function publiceerTijdschemaIntern() {
    const data = bouwProgrammaInternBody();
    if (!data) return;
    const win = window.open('', '_blank');
    if (!win) { toonBevestigDialog('Pop-up geblokkeerd — sta pop-ups toe voor deze pagina.', 'Afdrukken'); return; }
    win.document.write(`<!DOCTYPE html><html lang="nl">
<head><meta charset="UTF-8">
<title>${escHtml(data.title)}</title>
<style>${data.extraCss}</style></head>
<body>${data.bodyHtml}</body></html>`);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); win.close(); }, 500);
}
*/

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

function bouwAfstandGroepen() {
    const afstandMap = new Map(); // naam → [{dc_id, dc_naam, distance_id, n, merged_dc_ids?}]

    // Helper: voeg één entry toe aan afstandMap.
    // Ook n=0 wordt toegevoegd: dat is een placeholder-categorie (bv. een
    // afstand zonder vooraf-bevestigde deelnemers; jury stelt op de dag zelf
    // vast wie meedoet). De planner kan het verwachte aantal heats/finales
    // alvast configureren; ritten weggooien is achteraf simpeler dan ritten
    // bijbouwen. Alleen n<0 (onmogelijk in praktijk) blijft uitgesloten.
    const voegToe = (dc_id, dc_naam, distance_id, distNaam, n, merged_dc_ids, category_filter) => {
        if (n < 0) return;
        if (!afstandMap.has(distNaam)) afstandMap.set(distNaam, []);
        const entry = { dc_id, dc_naam, distance_id, n, category_filter: category_filter ?? '' };
        if (merged_dc_ids) entry.merged_dc_ids = merged_dc_ids;
        afstandMap.get(distNaam).push(entry);
    };

    // Helper: verwerk één enkelvoudige categorie (met eventuele splits)
    // "Aanwezig" = entry_status 1 (Bevestigd) of 5 (Bevestigd bij org.) — die
    // laatste is het label voor late toevoegingen via de + Deelnemer-modal.
    // Beide rijden mee, dus beide tellen voor de heat-verdeling.
    const isAanwezig = c => c?.entry_status === 1 || c?.entry_status === 5;
    const verwerkEnkel = (cat, overrideN) => {
        const n          = overrideN ?? (cat.competitors?.filter(isAanwezig).length ?? 0);
        const alleAfstand = cat.afstanden ?? [];
        const splits      = cat.splits || {};
        const splitNamen  = [...new Set(Object.values(splits))];

        if (splitNamen.length > 0) {
            // ── Gesplitste categorie ──────────────────────────────────────────────
            // Per splitgroep: gebruik alleen afstanden met target_group === sn
            // (of basisafstanden zonder target_group als er geen split-specifieke zijn)
            splitNamen.forEach(sn => {
                // codes = de KNSB competitor-codes die tot déze splitgroep behoren
                // (bijv. ["DJAA","DJAB"] voor splitgroep "DJ")
                const codes  = Object.entries(splits).filter(([, v]) => v === sn).map(([k]) => k);
                // Gebruik de KNSB-codes als category_filter zodat sortering (jong→oud) werkt
                const splitFilter = codes.join(',');
                const splitN = cat.competitors?.filter(
                    c => codes.includes(c.knsb?.category) && isAanwezig(c)
                ).length ?? 0;
                // splitN=0 is een placeholder-splitgroep — laten we ook toe (zie voegToe).
                if (splitN < 0) return;

                // Afstanden specifiek voor deze splitgroep, anders basisafstanden
                const specifiek = alleAfstand.filter(d => d.target_group === sn);
                const basis     = alleAfstand.filter(d => !d.target_group);
                const perSplit  = specifiek.length ? specifiek
                                : basis.length     ? basis
                                : [{ id: null, name: '—', target_group: null }];

                perSplit.forEach(dist =>
                    voegToe(cat.dc_id, sn, dist.id, dist.name, splitN, null, splitFilter)
                );
            });
        } else {
            // ── Niet-gesplitste categorie ─────────────────────────────────────────
            // Gebruik alleen basisafstanden (geen target_group); anders alle als er geen basis zijn
            const basis    = alleAfstand.filter(d => !d.target_group);
            const perAfstand = basis.length        ? basis
                             : alleAfstand.length  ? alleAfstand
                             : [{ id: null, name: '—', target_group: null }];
            // Ook n=0 doorgeven: placeholder-categorieën zonder bevestigde
            // deelnemers moeten alsnog in Afstandinstellingen verschijnen
            // (voegToe accepteert n=0).
            perAfstand.forEach(dist =>
                voegToe(cat.dc_id, cat.dc_name, dist.id, dist.name, n)
            );
        }
    };

    // Verdeel vergelijkData in losse en samengevoegde categorieën
    const mergeGroepen = new Map(); // merge_group → [cat]
    for (const cat of vergelijkData ?? []) {
        if (cat.merge_group) {
            if (!mergeGroepen.has(cat.merge_group)) mergeGroepen.set(cat.merge_group, []);
            mergeGroepen.get(cat.merge_group).push(cat);
        } else {
            verwerkEnkel(cat);
        }
    }

    // Verwerk samengestelde groepen
    const isUuidRe = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    for (const [mergeGroup, cats] of mergeGroepen) {
        const totaalN = cats.reduce(
            (s, c) => s + (c.competitors?.filter(isAanwezig).length ?? 0), 0
        );
        // totaalN=0 is een placeholder-mergegroep — laten we ook toe.

        // Sorteer op dc_number dan naam; eerste = primaire dc
        const sorted  = cats.slice().sort(
            (a, b) => (a.dc_number ?? 0) - (b.dc_number ?? 0) || (a.dc_name ?? '').localeCompare(b.dc_name ?? '')
        );
        const primary       = sorted[0];
        const merged_dc_ids = sorted.map(c => c.dc_id);
        const afstand       = primary.afstanden ?? [];
        const perAfstand    = afstand.length ? afstand : [{ id: null, name: '—' }];

        // merge_group is ingesteld als de dc_id van de primaire DC (een UUID).
        // Gebruik de user-defined merge_label als die bestaat, anders de naam
        // van de primaire DC (kort — anders breekt de layout van het schema).
        const nameOf = c => isUuidRe.test(c.dc_name ?? '') ? null : (c.dc_name || null);
        let mergeNaam;
        if (!isUuidRe.test(mergeGroup)) {
            // merge_group is al een leesbare naam (toekomstige opzet)
            mergeNaam = mergeGroup;
        } else if (primary.merge_label) {
            // Gebruiker heeft een eigen label opgegeven via de import-UI
            mergeNaam = primary.merge_label;
        } else {
            // Fallback: naam van de primaire DC (of eerste niet-UUID naam)
            mergeNaam = nameOf(primary)
                ?? sorted.map(nameOf).find(Boolean)
                ?? '[naamloos]';
        }

        // category_filter: combineer de KNSB-codes van alle deelnemers in de groep
        const mergeCodes = [...new Set(
            cats.flatMap(c => c.competitors
                ?.filter(isAanwezig)
                .map(x => x.knsb?.category)
                .filter(Boolean) ?? []
            )
        )];
        const mergeFilter = mergeCodes.join(',');

        perAfstand.forEach(dist =>
            voegToe(primary.dc_id, mergeNaam, dist.id, dist.name, totaalN, merged_dc_ids, mergeFilter)
        );
    }

    // Sorteer afstanden op numerieke waarde, dan alfabetisch
    return [...afstandMap.entries()]
        .sort(([a], [b]) => {
            const na = parseInt(a), nb = parseInt(b);
            if (!isNaN(na) && !isNaN(nb)) return na - nb;
            return a.localeCompare(b);
        })
        .map(([naam, cats]) => ({
            naam,
            cats: cats.slice().sort((a, b) =>
                catSortKeyKnsb(a.category_filter, a.dc_naam)
                    .localeCompare(catSortKeyKnsb(b.category_filter, b.dc_naam))
            ),
        }));
}

// ── Categorie-sortering (jong→oud, vrouwen voor mannen) ───────────────────────

// KNSB leeftijdscode-rang (identiek aan PHP catSorteerSleutel)
const KNSB_AGE_RANK = { P4:0, P3:1, P2:2, P1:3, K:4, JB:5, JA:6, S:7, M:8 };

/**
 * Sorteersleutel die zowel KNSB category-filter codes ('DJA*,DS*')
 * als ruwe competitor-codes ('DJAA','DKA') begrijpt.
 * Valt terug op tekst-gebaseerde catSortKey als geen code herkend wordt.
 */
function catSortKeyKnsb(categoryFilter, dc_naam) {
    const filter = (categoryFilter ?? '').trim();
    if (filter) {
        let maxAge = -1, gk = '1';
        for (const raw of filter.split(/[\s,]+/)) {
            const code = raw.replace(/[* ]/g, '').toUpperCase();
            if (code.length < 2) continue;
            if (code[0] === 'D' || code[0] === 'V') gk = '0';
            // Progressieve afkapping: 'JAA' → 'JA', 'KA' → 'K', enz.
            let ageStr = code.slice(1);
            while (ageStr && !Object.prototype.hasOwnProperty.call(KNSB_AGE_RANK, ageStr))
                ageStr = ageStr.slice(0, -1);
            if (ageStr) maxAge = Math.max(maxAge, KNSB_AGE_RANK[ageStr]);
        }
        if (maxAge >= 0)
            return String(maxAge).padStart(2, '0') + gk + (dc_naam ?? '').toLowerCase();
    }
    return catSortKey(dc_naam);   // tekst-fallback
}

const CAT_LEEFTIJD_VOLGORDE = [
    'pupillen 4', 'pupillen 3', 'pupillen 2', 'pupillen 1',
    'kadetten',
    'junioren b',
    'junioren a',
    'senioren', 'masters',
];
const CAT_VROUW_PATRONEN = ['vrouwen', 'dames', 'meisjes', 'girls', 'women'];

function catSortKey(naam) {
    const n  = (naam ?? '').toLowerCase();
    const li = CAT_LEEFTIJD_VOLGORDE.findIndex(p => n.includes(p));
    const lk = li === -1 ? '99' : String(li).padStart(2, '0');
    const gk = CAT_VROUW_PATRONEN.some(p => n.includes(p)) ? '0' : '1';
    return lk + gk + n;
}

function vindAfstandConfig(schema, afstandNaam) {
    return schema?.afstand_configs?.find(c => c.afstand_naam === afstandNaam) ?? null;
}

function maakCatConfigMap(schema) {
    const map = {};
    (schema?.cat_configs ?? []).forEach(cc => {
        map[cc.dc_id + '|' + (cc.distance_id ?? '')] = cc;
    });
    return map;
}

// ── Conflict-waarschuwing ─────────────────────────────────────────────────────

function toonTsConflictWaarschuwing(msg) {
    const container = el('ts-container');
    const bestaand  = container?.querySelector('.ts-conflict-banner');
    if (bestaand) return; // al zichtbaar
    const div = document.createElement('div');
    div.className = 'status-msg warning ts-conflict-banner';
    div.style.cssText = 'position:sticky;top:0;z-index:10;margin-bottom:.5rem;';
    div.innerHTML = `⚠ <strong>Conflict:</strong> ${escHtml(msg || 'Tijdschema gewijzigd door iemand anders.')}
        <br><small style="opacity:.8">Niet-opgeslagen wijzigingen gaan verloren bij het herladen.</small>
        <button class="btn-secondary" onclick="toonTijdschemaPagina()" style="margin-left:8px">↺ Herlaad</button>`;
    container?.prepend(div);
}

// ── Dirty-check hulpfunctie ───────────────────────────────────────────────────

function tsHeeftWijzigingen() {
    return !!el('ts-systeem-select')?.classList.contains('ts-btn-dirty') ||
           !!document.querySelector('.ts-sectie-afstand .ts-btn-dirty');
}

// ── Entries-ververs indicator ─────────────────────────────────────────────────

function toonEntriesVerversIndicator() {
    const navItem = document.querySelector('.nav-item[data-page="importeer"]');
    if (!navItem || navItem.querySelector('.nav-update-dot')) return;
    const dot = document.createElement('span');
    dot.className = 'nav-update-dot';
    dot.title = 'Inschrijvingen bijgewerkt';
    navItem.appendChild(dot);
}

// ── Auto-polling ──────────────────────────────────────────────────────────────

function startTsPolling() {
    stopTsPolling();
    if (!huidigCompId) return;
    let _tsPollFails = 0;
    _tsPollingInterval = setInterval(async () => {
        if (!huidigCompId) return;
        if (_tsPollFails >= 3) { stopTsPolling(); return; }
        try {
            const res  = await fetch(`api/tijdschema.php?competition_id=${encodeURIComponent(huidigCompId)}&check_version=1`);
            if (!res.ok) { _tsPollFails++; return; }
            _tsPollFails = 0;
            const data = await res.json();
            if (!data || data.error) return;
            // Inschrijvingen bijgewerkt → toon badge bij Importeer nav-item
            if (data.entries_version != null && data.entries_version !== entriesVersion && !heeftWijzigingen) {
                entriesVersion = data.entries_version;
                toonEntriesVerversIndicator();
            }
            // Tijdschema bijgewerkt door iemand anders
            if (data.tijdschema_version != null && data.tijdschema_version !== tijdschemaVersion) {
                if (!tsHeeftWijzigingen()) {
                    // Geen onopgeslagen wijzigingen → stille refresh
                    tijdschemaVersion = data.tijdschema_version;
                    laadTijdschema();
                } else {
                    // Wel wijzigingen → waarschuw
                    toonTsConflictWaarschuwing('Het tijdschema is bijgewerkt door iemand anders.');
                }
            }
        } catch { _tsPollFails++; }
    }, 30000);
}

function stopTsPolling() {
    if (_tsPollingInterval) { clearInterval(_tsPollingInterval); _tsPollingInterval = null; }
}
