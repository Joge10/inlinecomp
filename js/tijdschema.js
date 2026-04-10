/* InlineComp – Tijdschema v2 */

let huidigTijdschema   = null;   // geladen schema of null
let tsAfstandOpen      = null;   // naam van open afstand-panel
let programmaVerouderd = false;  // true als afstand/import gewijzigd na laatste generatie
let tijdschemaVersion  = 0;      // voor optimistic locking bij tijdschema-writes
let _tsPollingInterval = null;   // interval-handle voor auto-poll
let _tsLeesOnly        = false;  // true als huidige gebruiker geen schrijfrechten heeft

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
            'Runner-up (optioneel) — rijders die in de series zijn uitgevallen rijden een aparte runner-up race.',
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
    const nHeats0 = Math.max(1, Math.ceil(uitv / ruMax));

    if (!ruMin) {
        // Origineel gedrag: gelijkmatig, laatste is grootste
        const basis = Math.floor(uitv / nHeats0);
        const extra = uitv % nHeats0;
        return Array.from({ length: nHeats0 }, (_, i) =>
            basis + (i >= nHeats0 - extra ? 1 : 0));
    }

    // Min-check: merge laatste heat als die te klein is
    let n = nHeats0;
    while (n > 1) {
        const last = uitv - ruMax * (n - 1);
        if (last < ruMin) { n--; } else { break; }
    }

    if (n === 1) return [uitv];
    const sizes = Array(n - 1).fill(ruMax);
    sizes.push(uitv - ruMax * (n - 1));
    return sizes;
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
    try {
        const uniekeDcIds = [...new Set((vergelijkData ?? []).map(c => c.dc_id))];
        const [schemaRes, ...distResArr] = await Promise.all([
            fetch(`api/tijdschema.php?competition_id=${encodeURIComponent(huidigCompId)}`),
            ...uniekeDcIds.map(dcId =>
                fetch(`api/distances_db.php?dc_id=${encodeURIComponent(dcId)}`)
            ),
        ]);

        const data = await schemaRes.json();
        if (data?.error) throw new Error(data.error);
        huidigTijdschema = data;
        tijdschemaVersion = data?.tijdschema_version ?? 0;


        const distArrays = await Promise.all(distResArr.map(r => r.json()));
        uniekeDcIds.forEach((dcId, i) => {
            const alle = Array.isArray(distArrays[i]) ? distArrays[i] : [];
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
                <button class="btn-secondary ts-btn-sm ts-btn-publiceer" id="ts-btn-publiceer">📄 Publiceer extern</button>
                <button class="btn-secondary ts-btn-sm ts-btn-publiceer" id="ts-btn-publiceer-intern">🗒 Publiceer intern</button>
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
        // Full-final: A-finale + B-finales; iedereen rijdt een finale op basis van tijd
        const fBg          = cfg?.finale_b_grootte   ?? 6;
        const bLaatstGrootst = cfg?.laatste_b_grootste ?? 1;
        html += `
                <div class="ts-gedeeld-rij">
                    <span class="ts-gedeeld-lbl">A-finale</span>
                    <span class="ts-gedeeld-inputs">
                        Max.&nbsp;<input type="number" name="finale_heat_grootte" value="${fHg}"
                               min="2" max="20" class="ts-inp-sm">&nbsp;rijders
                    </span>
                    <span class="ts-veld-hint">Beste rijders. Altijd één A-finale.</span>
                </div>
                <div class="ts-gedeeld-rij">
                    <span class="ts-gedeeld-lbl">B-finales</span>
                    <span class="ts-gedeeld-inputs">
                        Max.&nbsp;<input type="number" name="finale_b_grootte" value="${fBg}"
                               min="2" max="20" class="ts-inp-sm">&nbsp;rijders per B-finale
                    </span>
                    <span class="ts-veld-hint">Mag niet minder zijn dan de A-finale. Overige rijders worden verdeeld over B1, B2 … Bn.</span>
                </div>
                <div class="ts-gedeeld-rij">
                    <span class="ts-gedeeld-lbl">&nbsp;</span>
                    <label class="ts-gedeeld-inputs">
                        <input type="checkbox" name="laatste_b_grootste" ${bLaatstGrootst ? 'checked' : ''}>
                        Laatste B-finale (Bn) is de grootste
                    </label>
                    <span class="ts-veld-hint">Uitgevinkt = B1 heeft de meeste rijders</span>
                </div>
                <input type="hidden" name="q_direct"        value="0">
                <input type="hidden" name="q_tijd"          value="0">
                <input type="hidden" name="heeft_runner_up" value="0">`;
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
                <div class="ts-gedeeld-rij">
                    <span class="ts-gedeeld-lbl">Finale-grootte</span>
                    <span class="ts-gedeeld-inputs">
                        <input type="number" name="finale_heat_grootte" value="${fHg}"
                               min="1" class="ts-inp-sm"> rijders in de finale
                    </span>
                </div>
                <div class="ts-gedeeld-rij">
                    <span class="ts-gedeeld-lbl">Finale-seeding</span>
                    <span class="ts-gedeeld-inputs">
                        <select name="finale_seeding" class="ts-sel-sm">
                            <option value="slang" ${(cfg?.finale_seeding ?? 'slang') === 'slang' ? 'selected' : ''}>Slangenpatroon (standaard)</option>
                            <option value="tijdkoppeling" ${cfg?.finale_seeding === 'tijdkoppeling' ? 'selected' : ''}>Tijdkoppeling (langzaamsten eerst, snelsten laatste heat)</option>
                        </select>
                    </span>
                </div>`;
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
                <th colspan="2" class="ts-th-sectie">Series</th>
            </tr><tr>
                <th class="ts-th-c">Rijdt<br>series</th>
                <th class="ts-th-c">Aantal<br>heats</th>
            </tr>`;
    } else {
        html += `<tr>
                <th class="ts-th-catnaam" rowspan="2">Categorie</th>
                <th class="ts-th-c ts-th-n" rowspan="2">Deel&shy;nemers</th>
                <th colspan="3" class="ts-th-sectie">Series</th>
                <th colspan="4" class="ts-th-sectie ts-sectie-start">Kwartfinale</th>
                <th colspan="4" class="ts-th-sectie ts-sectie-start">Halve finale</th>
                <th colspan="1" class="ts-th-sectie ts-sectie-start">Finale</th>
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
            </tr>`;
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
                       min="1" max="30" class="ts-inp-sm"
                       title="Aantal A-finale heats">
            </td>`;
        } else {
            // Full-final: verborgen velden zodat save-handler waarden heeft
            html += `
            <input type="hidden" name="heats_q"            value="${cat.n}">
            <input type="hidden" name="heats_q_heat"       value="0">
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
            stappen.push(`${cat.n} → ${nVH} series (${escHtml(ph)}/h) → ${qDoor}q`);

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
                const aHg  = Math.max(2, parseInt(cfg?.finale_heat_grootte) || 6);
                const bHgR = Math.max(2, parseInt(cfg?.finale_b_grootte)    || 6);
                const bHg  = Math.max(bHgR, aHg);
                const aR   = Math.min(finR, aHg);
                const bR   = Math.max(0, finR - aR);
                const parts = [];
                if (bR > 0) {
                    const nB = Math.ceil(bR / bHg);
                    // Programma-volgorde: Bn eerst, dan B(n-1)...B1, dan A
                    for (let b = nB; b >= 1; b--) parts.push(`B${b}-finale`);
                }
                parts.push(`A-finale`);
                stappen.push(`${finR} → ${parts.join(' + ')}`);
            } else {
                stappen.push(`A-finale: ${finR}`);
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

// ── Programma-volgorde ────────────────────────────────────────────────────────

function renderBlokken(schema, afstandGroepen) {
    const blokken = schema.blokken ?? [];
    const heeftWsStart = blokken.some(b => b.blok_type === 'wedstrijdstart');
    const wsIdx = blokken.findIndex(b => b.blok_type === 'wedstrijdstart');

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
            html += `<div class="ts-blok-item ts-blok-pauze" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-pauze-lbl">── PAUZE ──</span>
                ${duurInp(blok)}
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
            html += `<div class="ts-blok-item ts-blok-cerem" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-cerem-lbl">── CEREMONIE ──</span>
                ${duurInp(blok)}
                ${delBtn}
            </div>`;

        } else if (blok.blok_type === 'wedstrijdstart') {
            const tijdVal = blok.tijdstip ? blok.tijdstip.substring(0,5) : '';
            html += `<div class="ts-blok-item ts-blok-wsstart" draggable="true" data-blok-id="${blok.id}">
                ${dragHandle}${navBtns}
                <span class="ts-blok-wsstart-lbl">── WEDSTRIJD START ──</span>
                <label class="ts-blok-duur-lbl">
                    Aanvang:&nbsp;<input type="time" class="ts-inp-tijdstip" data-blok-id="${blok.id}"
                                        value="${tijdVal}">
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
                    Opmerking:&nbsp;<input type="text" class="ts-inp-opmerking" data-blok-id="${blok.id}"
                                          value="${opmVal}" placeholder="bijv. vertraging door…" maxlength="120"
                                          style="width:16em">
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
            <button class="ts-btn-wsstart ts-btn-sm" id="ts-btn-add-wsstart" ${heeftWsStart ? 'disabled' : ''}>+ Wedstrijd start</button>
            <button class="ts-btn-herstart ts-btn-sm" id="ts-btn-add-herstart">🔄 Herstart toevoegen</button>
            <span class="ts-blokken-acties-sep"></span>
            <button class="btn-secondary ts-btn-sm" id="ts-btn-save-blokken">💾 Volgorde opslaan</button>
            <button class="btn-primary ts-btn-sm" id="ts-btn-genereer">▶ Genereer programma</button>
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
        for (const rij of rijen) {
            if (rij.type === 'wedstrijdstart') {
                blokTijdMap.set(rij.blok.id, secNaarTijd(cur));
                started = true;
            } else if (started) {
                if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                    blokTijdMap.set(rij.blok.id, secNaarTijd(cur));
                    cur += (parseInt(rij.blok.duur) || 0) * 60;
                } else if (rij.type === 'herstart') {
                    // Reset cursortijd naar de nieuwe starttijd; markeer op de nieuwe tijd
                    if (rij.blok.tijdstip) {
                        const d = rij.blok.tijdstip.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                    }
                    blokTijdMap.set(rij.blok.id, secNaarTijd(cur));
                } else if (rij.type === 'rit') {
                    // Override: pin de cursortijd aan het handmatig ingestelde tijdstip
                    if (rij.rit.tijdstip_override) {
                        const d = rij.rit.tijdstip_override.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                    }
                    startTijdMap.set(rij.rit.id, secNaarTijd(cur));
                    startRawSecMap.set(rij.rit.id, cur);              // raw seconden opslaan
                    cur += heatDuurMap.get(parseInt(rij.rit.blok_id)) || 0;
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

    let html = `<div class="ts-ritten-wrap">
        <div class="ts-ritten-hint">Sleep <span class="ts-drag-handle" style="display:inline-block;vertical-align:middle">⠿</span> om een complete categoriegroep te verplaatsen.</div>
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

    rijen.forEach(rij => {
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
            html += `<tr class="ts-pauze-rij">
                ${tijdCel(rij.blok.id)}
                <td colspan="${restCols}" class="ts-pauze-cel">⏸ Pauze${escHtml(duurTxt)}</td>
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
            html += `<tr class="ts-cerem-rij">
                ${tijdCel(rij.blok.id)}
                <td colspan="${restCols}" class="ts-cerem-cel">🏆 Ceremonie${escHtml(duurTxt)}</td>
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
                const nTxt = n === 1 ? '1 rit' : `${n} heats`;
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

                html += `<tr class="ts-rit-groep-hdr" draggable="true"
                            data-groep-key="${escHtml(groepKey)}">
                    <td colspan="${aantalCols}" class="ts-groep-hdr-td">
                        <div class="ts-groep-hdr-cel">
                            ${tijdInhoud}
                            <span class="ts-drag-handle">⠿</span>
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
            if (ovOpmVal) {
                html += `<tr class="ts-rit-opm-rij" data-groep-key="${escHtml(groepKey)}">
                    ${heeftStartTijden ? '<td></td>' : ''}
                    <td></td>
                    <td colspan="3" class="ts-rit-opm-cel">📝 ${ovOpmVal}</td>
                </tr>`;
            }
            html += `<tr class="ts-rit-rij ts-rit-sub" data-rit-id="${rit.id}"
                        data-groep-key="${escHtml(groepKey)}">
                ${tijdCelHtml}
                <td class="ts-rit-nr">${ritNr}</td>
                <td class="ts-rit-naam">${escHtml(rit.rit_naam)}</td>
                <td><span class="ts-type-badge ts-type-badge-sm" style="background:${kleur}">${escHtml(label)}${escHtml(fin)}</span></td>
                <td class="ts-rit-verwacht">${rit.verwacht ?? '?'}</td>
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

    const calcInputs = ['heats_q','kwart_heats','kwart_door','kwart_q_heat','half_heats','half_door','half_q_heat','q_direct','q_tijd','finale_heat_grootte','finale_b_grootte','laatste_b_grootste','heeft_runner_up','heats_aantal','runner_up_max','runner_up_min'];
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
                catConfigs.push({
                    dc_id:              tr.dataset.dcId,
                    distance_id:        tr.dataset.distId,
                    heeft_heats:        chk('heeft_heats')        ? 1 : 0,
                    heats_aantal:       cn('heats_aantal') || 1,
                    heats_q:            cn('heats_q'),
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
                    laatste_b_grootste:  form.querySelector('[name="laatste_b_grootste"]')?.checked ? 1 : 0,
                    finale_seeding:      form.querySelector('[name="finale_seeding"]')?.value ?? 'slang',
                    heeft_runner_up:     heeftRU,
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
        } else if (blok.blok_type === 'wedstrijdstart') {
            postBody.tijdstip = blokDiv.querySelector('.ts-inp-tijdstip')?.value || null;
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

    el('ts-btn-publiceer')?.addEventListener('click', publiceerTijdschema);
    el('ts-btn-publiceer-intern')?.addEventListener('click', publiceerTijdschemaIntern);

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

            // ── In-memory update: nieuwe volgorde ────────────────────────────
            const ritById = new Map((huidigTijdschema.ritten ?? []).map(r => [parseInt(r.id), r]));
            const nieuweRitIds = [...rittenTbody.querySelectorAll('tr.ts-rit-rij')]
                .map(row => parseInt(row.dataset.ritId));

            huidigTijdschema.ritten = nieuweRitIds.map(id => ritById.get(id)).filter(r => r?.id);

            // ── Render UITSTELLEN tot na dragend (voorkomt browser snap-back) ─
            setTimeout(renderTijdschema, 0);

            // ── Achtergrond-save: volgorde opslaan ───────────────────────────
            const volgorde = nieuweRitIds.map((id, i) => ({ id, volgorde: i + 1 }));
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
    }
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
        };
    });

    const calcId  = `ts-calc-${naam.replace(/[^a-z0-9]/gi, '_')}`;
    const calcDiv = document.getElementById(calcId);
    if (calcDiv) calcDiv.innerHTML = renderAfstandCalc(afstand, liveCfg, liveCatMap);
}

// ── Publiceer tijdschema ──────────────────────────────────────────────────────

function publiceerTijdschema() {
    const schema = huidigTijdschema;
    const comp   = huidigComp;
    if (!schema) return;

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

    // ── Start-tijden berekenen via rijen ──────────────────────────────────────
    const stMap = new Map();  // rit.id  → 'HH:MM'
    const btMap = new Map();  // blok.id → 'HH:MM'
    const wsBlok = blokken.find(b => b.blok_type === 'wedstrijdstart' && b.tijdstip);
    if (wsBlok) {
        const d = wsBlok.tijdstip.split(':').map(Number);
        const wsSec = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
        let cur = wsSec, gestart = false;
        for (const rij of rijdenP) {
            if (rij.type === 'wedstrijdstart') {
                btMap.set(rij.blok.id, mNT(cur)); gestart = true;
            } else if (gestart) {
                if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                    btMap.set(rij.blok.id, mNT(cur));
                    cur += (parseInt(rij.blok.duur) || 0) * 60;   // duur in minuten → seconden
                } else if (rij.type === 'herstart') {
                    // Reset cursortijd naar de nieuwe starttijd; markeer op de nieuwe tijd
                    if (rij.blok.tijdstip) {
                        const d = rij.blok.tijdstip.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                    }
                    btMap.set(rij.blok.id, mNT(cur));
                } else if (rij.type === 'rit') {
                    if (rij.rit.tijdstip_override) {
                        const d = rij.rit.tijdstip_override.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                    }
                    stMap.set(rij.rit.id, mNT(cur));
                    cur += heatDuurMapP.get(parseInt(rij.rit.blok_id)) || 0;  // al in seconden
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
            if (cc.heeft_kwartfinale)  return 'Kwartfinale';
            if (cc.heeft_halve_finale) return 'Halve finale';
            return 'Finale';
        }
        if (rondeType === 'kwartfinale') {
            return cc.heeft_halve_finale ? 'Halve finale' : 'Finale';
        }
        if (rondeType === 'halve_finale') return 'Finale';
        return '';
    };

    // Doorstroom-tekst per ronde-type (met → volgende ronde)
    const doorTxt = (rondeType, cc, nHeats) => {
        if (!cc) return '';
        const vR = volgendeRonde(rondeType, cc);
        const naar = vR ? ` → ${vR}` : '';
        switch (rondeType) {
            case 'heats': {
                const q = parseInt(cc.heats_q) || 0;
                return `top ${q} op tijd${naar}`;
            }
            case 'kwartfinale': {
                const kD = parseInt(cc.kwart_door)   || 0;
                const kQ = parseInt(cc.kwart_q_heat) || 0;
                const kq = Math.max(0, kD - kQ * nHeats);
                return `${kQ}Q/heat + ${kq}q → ${kD} rijders${naar}`;
            }
            case 'halve_finale': {
                const hD = parseInt(cc.half_door)    || 0;
                const hQ = parseInt(cc.half_q_heat)  || 0;
                const hq = Math.max(0, hD - hQ * nHeats);
                return `${hQ}Q/heat + ${hq}q → ${hD} rijders${naar}`;
            }
            default: return '';
        }
    };

    // ── Org-logo header + sponsors footer (gedeelde helper) ─────────────────
    const { orgLogoHtml, footerHtml } = bouwOrgHeaderFooter(esc);

    // ── HTML via rijen (volgorde-gebaseerd) ──────────────────────────────────
    let bloHtml = '';

    // Helper: flush een verzamelde sectie (ritten van één ronde-blok) naar HTML
    const flushSectie = (sectieRitten, blok) => {
        if (!sectieRitten.length || !blok) return;
        const rondeLabel = blok.ronde_type === 'heats' ? 'Series'
                         : (TS_RONDE_LABEL[blok.ronde_type] ?? blok.ronde_type);
        const eersteTijd = stMap.get(sectieRitten[0].id) ?? '';
        const nHeats     = sectieRitten.length;
        const hd         = parseInt(blok.heat_duur) || 0;   // seconden
        const hdTxt      = hd ? secNaarMmSs(hd) : '';
        const totaalMin  = hd ? Math.round(nHeats * hd / 60) : 0;
        const duurInfo   = hd
            ? `${nHeats} heat${nHeats > 1 ? 's' : ''} × ${hdTxt} ≈ ${totaalMin} min`
            : `${nHeats} heat${nHeats > 1 ? 's' : ''}`;

        // Groepeer per categorie (dc_id + distance_id), volgorde bewaren
        const catMap = new Map();
        sectieRitten.forEach(r => {
            const key = r.dc_id + '|' + (r.distance_id ?? '');
            if (!catMap.has(key)) catMap.set(key, { naam: r.dc_naam, ritten: [], key });
            catMap.get(key).ritten.push(r);
        });

        const isFinale = blok.ronde_type === 'finale';
        let catHtml = '';
        catMap.forEach(({ naam, ritten: cr, key }) => {
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
                detail = [...seen].map(([lbl, n]) => `${esc(lbl)}-finale (${n} rijders)`).join(' · ');
            } else {
                const dt = doorTxt(blok.ronde_type, cc, nH);
                detail = `${totRj} rijders, ${nH} heat${nH > 1 ? 's' : ''}${dt ? ' · ' + esc(dt) : ''}`;
            }
            catHtml += `<div class="cat-rij">
                <span class="cat-tijd">${esc(catTijd)}</span>
                <span class="cat-naam">${esc(naam)}</span>
                <span class="cat-details">${detail}</span>
            </div>`;

            // Toon per heat die een override heeft: vastgezette tijd + reden
            cr.forEach((r, i) => {
                if (!r.tijdstip_override) return;
                const ovTijd = stMap.get(r.id) ?? r.tijdstip_override.substring(0, 5);
                const opmTxt  = r.opmerking ? ` — ${esc(r.opmerking)}` : '';
                const heatDeel = nH > 1 ? ` - heat ${i + 1}` : '';
                catHtml += `<div class="cat-ovr-rij">
                    <span class="cat-ovr-tijd">${esc(ovTijd)}</span>
                    <span class="cat-ovr-tekst">📌 ${esc(naam)}${esc(heatDeel)}${opmTxt}</span>
                </div>`;
            });
        });

        bloHtml += `<div class="blok ronde">
            <div class="blok-kop">
                <span class="blok-tijd">${esc(eersteTijd)}</span>
                <span class="blok-titel">${esc(rondeLabel)} ${esc(blok.afstand_naam ?? '')}</span>
                <span class="blok-info">${esc(duurInfo)}</span>
            </div>
            ${catHtml}
        </div>`;
    };

    // Itereer rijen; groepeer opeenvolgende ritten van hetzelfde ronde-blok
    let huidigeBlokId = null;
    let huidigeSectie = [];

    for (const rij of rijdenP) {
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
                        <span class="blok-titel">🏁 WEDSTRIJD START</span>
                    </div></div>`;

            } else if (rij.type === 'pauze') {
                const duur = blok.duur ? `${blok.duur} min` : '';
                bloHtml += `<div class="blok pauze">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd)}</span>
                        <span class="blok-titel">⏸ Pauze</span>
                        ${duur ? `<span class="blok-info">${esc(duur)}</span>` : ''}
                    </div></div>`;

            } else if (rij.type === 'inrijden') {
                const duur    = blok.duur ? `${blok.duur} min` : '';
                const catIds  = (() => { try { return JSON.parse(blok.inrijd_cats || '[]'); } catch(e) { return []; } })();
                const catNamen = catIds.map(id => esc(dcNaam.get(id) ?? id)).join(', ');
                bloHtml += `<div class="blok inrijd">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd)}</span>
                        <span class="blok-titel">🛼 Inrijden</span>
                        ${duur ? `<span class="blok-info">${esc(duur)}</span>` : ''}
                    </div>
                    ${catNamen ? `<div class="blok-cats">${catNamen}</div>` : ''}
                </div>`;

            } else if (rij.type === 'ceremonie') {
                const duur = blok.duur ? `${blok.duur} min` : '';
                bloHtml += `<div class="blok cerem">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd)}</span>
                        <span class="blok-titel">🏆 Ceremonie</span>
                        ${duur ? `<span class="blok-info">${esc(duur)}</span>` : ''}
                    </div></div>`;

            } else if (rij.type === 'herstart') {
                const ts  = blok.tijdstip?.substring(0, 5) ?? '—';
                const opm = blok.opmerking ? ` — ${esc(blok.opmerking)}` : '';
                bloHtml += `<div class="blok herstart">
                    <div class="blok-kop">
                        <span class="blok-tijd">${esc(bTijd || ts)}</span>
                        <span class="blok-titel">🔄 HERSTART${opm}</span>
                    </div></div>`;
            }
        }
    }
    // Flush laatste sectie
    flushSectie(huidigeSectie, blokById.get(huidigeBlokId));

    const datum   = comp?.starts ? formatDatum(comp.starts) : '';
    const locatie = comp ? getLocatie(comp) : '';
    const metaTxt = [datum, locatie].filter(Boolean).map(esc).join(' &nbsp;·&nbsp; ');

    const htmlDoc = `<!DOCTYPE html><html lang="nl">
<head><meta charset="UTF-8">
<title>Wedstrijdprogramma${comp?.name ? ' — ' + esc(comp.name) : ''}</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:10.5pt;margin:.6cm 1.2cm 1.2cm;color:#111;line-height:1.5}
.pagina-header{display:flex;flex-wrap:nowrap;align-items:stretch;justify-content:space-between;
               gap:4mm;margin-bottom:0}
.hdr-links{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:flex-end}
.hdr-comp{font-size:16pt;font-weight:700;line-height:1.2;margin-bottom:.5mm}
.hdr-meta{font-size:9.5pt;color:#555}
.hdr-versie{font-size:8pt;color:#999;margin-top:1mm}
.hdr-rechts{flex-shrink:0;display:flex;align-items:flex-start}
.hdr-lijn{border:none;border-top:2px solid #1a3a5c;margin:.4cm 0 .5cm 0}
.disclaimer{background:#fffbee;border:1px solid #e6c800;border-left:4px solid #e6c800;
            padding:.3cm .5cm;font-size:9pt;color:#7a5800;margin-bottom:.7cm;border-radius:3px}
.blok{margin-bottom:.45cm;page-break-inside:avoid}
.blok-kop{display:flex;align-items:baseline;gap:.5cm;border-bottom:1.5px solid #ddd;
          padding-bottom:.1cm;margin-bottom:.15cm}
.blok-tijd{font-size:11pt;font-weight:700;color:#003366;min-width:1.4cm;flex-shrink:0;
           font-variant-numeric:tabular-nums}
.blok-titel{font-size:11pt;font-weight:700;flex:1}
.blok-info{font-size:9pt;color:#666;white-space:nowrap}
.blok-cats{padding-left:1.9cm;font-size:10pt;color:#444}
.cat-rij{display:flex;gap:.4cm;padding:.04cm 0 .04cm 1.9cm;font-size:10pt;align-items:baseline}
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
}
</style></head>
<body>
<div class="pagina-header">
  <div class="hdr-links">
    <div class="hdr-comp">${esc(comp?.name ?? 'Wedstrijdprogramma')}</div>
    ${metaTxt ? `<div class="hdr-meta">${metaTxt}</div>` : ''}
    ${schema.gegenereerd_op ? `<div class="hdr-versie">Programma gegenereerd op: ${esc(new Date(schema.gegenereerd_op.replace(' ','T')+'Z').toLocaleString('nl-NL',{day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'}))}</div>` : ''}
  </div>
  <div class="hdr-rechts">${orgLogoHtml}</div>
</div>
<hr class="hdr-lijn">
<div class="disclaimer">
  ⚠ De vermelde starttijden zijn <strong>indicatieve richtijden</strong>. De werkelijke uitvoering kan afwijken van dit programma. Houd zelf het verloop van het programma in de gaten — je bent zelf verantwoordelijk voor het op tijd aan de start verschijnen.
</div>
${bloHtml}
${footerHtml}
</body></html>`;

    const win = window.open('', '_blank');
    if (!win) { toonBevestigDialog('Pop-up geblokkeerd — sta pop-ups toe voor deze pagina.', 'Afdrukken'); return; }
    win.document.write(htmlDoc);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 500);
}

// ── Publiceer intern tijdschema ───────────────────────────────────────────────

function publiceerTijdschemaIntern() {
    const schema = huidigTijdschema;
    const comp   = huidigComp;
    if (!schema) return;

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

    // ── Starttijden berekenen ─────────────────────────────────────────────────
    const stMap = new Map();
    const btMap = new Map();
    const stRawMap = new Map();
    const wsBlok = blokken.find(b => b.blok_type === 'wedstrijdstart' && b.tijdstip);
    if (wsBlok) {
        const d = wsBlok.tijdstip.split(':').map(Number);
        const wsSec = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
        let cur = wsSec, gestart = false;
        for (const rij of rijen) {
            if (rij.type === 'wedstrijdstart') {
                btMap.set(rij.blok.id, mNT(cur)); gestart = true;
            } else if (gestart) {
                if (rij.type === 'pauze' || rij.type === 'inrijden' || rij.type === 'ceremonie') {
                    btMap.set(rij.blok.id, mNT(cur));
                    cur += (parseInt(rij.blok.duur) || 0) * 60;
                } else if (rij.type === 'rit') {
                    if (rij.rit.tijdstip_override) {
                        const d = rij.rit.tijdstip_override.split(':').map(Number);
                        cur = (d[0] || 0) * 3600 + (d[1] || 0) * 60;
                    }
                    stMap.set(rij.rit.id, mNT(cur));
                    stRawMap.set(rij.rit.id, cur);
                    cur += heatDuurMap.get(parseInt(rij.rit.blok_id)) || 0;
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
    const { orgLogoHtml, footerHtml } = bouwOrgHeaderFooter(esc);

    // ── DC-namen opzoektabel ──────────────────────────────────────────────────
    const dcNaamMap = new Map();
    bouwAfstandGroepen().forEach(af =>
        af.cats.forEach(c => { if (!dcNaamMap.has(c.dc_id)) dcNaamMap.set(c.dc_id, c.dc_naam); })
    );

    // ── Tabel-rijen genereren ─────────────────────────────────────────────────
    const tijdTh = heeftTijden ? '<th class="ti">Tijd</th>' : '';
    const restCols = 4;
    let tBody = '';
    let ritNr = 0;
    let prevGroepKey = null;

    rijen.forEach(rij => {
        const cols = heeftTijden ? restCols + 1 : restCols;
        if (rij.type === 'wedstrijdstart') {
            prevGroepKey = null;
            const ts = rij.blok?.tijdstip?.substring(0,5) ?? '—';
            const bTijd = btMap.get(rij.blok.id) ?? '';
            tBody += `<tr class="wsstart">
                ${heeftTijden ? `<td class="ti">${esc(bTijd || ts)}</td>` : ''}
                <td colspan="${restCols}" class="special">🏁 Wedstrijd start — <strong>${esc(ts)}</strong></td>
            </tr>`;
        } else if (rij.type === 'pauze') {
            prevGroepKey = null;
            const bTijd = btMap.get(rij.blok.id) ?? '';
            const duurTxt = rij.blok?.duur ? ` – ${rij.blok.duur} min` : '';
            tBody += `<tr class="pauze">
                ${heeftTijden ? `<td class="ti">${esc(bTijd)}</td>` : ''}
                <td colspan="${restCols}" class="special">⏸ Pauze${esc(duurTxt)}</td>
            </tr>`;
        } else if (rij.type === 'inrijden') {
            prevGroepKey = null;
            const bTijd = btMap.get(rij.blok.id) ?? '';
            const duurTxt = rij.blok?.duur ? ` – ${rij.blok.duur} min` : '';
            const cats = (() => { try { return JSON.parse(rij.blok?.inrijd_cats || '[]'); } catch(e) { return []; } })();
            const catNamen = cats.map(id => esc(dcNaamMap.get(id) ?? id)).join(', ');
            tBody += `<tr class="inrijd">
                ${heeftTijden ? `<td class="ti">${esc(bTijd)}</td>` : ''}
                <td colspan="${restCols}" class="special">🛼 Inrijden${esc(duurTxt)}${catNamen ? ' — ' + catNamen : ''}</td>
            </tr>`;
        } else if (rij.type === 'ceremonie') {
            prevGroepKey = null;
            const bTijd = btMap.get(rij.blok.id) ?? '';
            const duurTxt = rij.blok?.duur ? ` – ${rij.blok.duur} min` : '';
            tBody += `<tr class="cerem">
                ${heeftTijden ? `<td class="ti">${esc(bTijd)}</td>` : ''}
                <td colspan="${restCols}" class="special">🏆 Ceremonie${esc(duurTxt)}</td>
            </tr>`;
        } else if (rij.type === 'herstart') {
            prevGroepKey = null;
            const bTijd   = btMap.get(rij.blok.id) ?? '';
            const ts      = rij.blok?.tijdstip?.substring(0,5) ?? '—';
            const opmTxt  = rij.blok?.opmerking ? ` — ${esc(rij.blok.opmerking)}` : '';
            tBody += `<tr class="herstart">
                ${heeftTijden ? `<td class="ti">${esc(bTijd || ts)}</td>` : ''}
                <td colspan="${restCols}" class="special">🔄 Herstart — <strong>${esc(ts)}</strong>${opmTxt}</td>
            </tr>`;
        } else {
            const rit      = rij.rit;
            const groepKey = `${rit.blok_id ?? ''}:${rit.dc_id}:${rit.dc_naam ?? ''}:${rit.ronde_type}`;
            const kleur    = TS_RONDE_KLEUR[rit.ronde_type] ?? '#adb5bd';
            const label    = (rit.ronde_type === 'finale_b' && rit.finale_label)
                ? rit.finale_label + '-finale'
                : (TS_RONDE_LABEL[rit.ronde_type] ?? rit.ronde_type);
            const fin = (rit.ronde_type === 'finale_b') ? '' : (rit.finale_label ? ` ${rit.finale_label}` : '');

            if (groepKey !== prevGroepKey) {
                prevGroepKey = groepKey;
                const n    = groepGrootte[groepKey] ?? 1;
                const nTxt = n === 1 ? '1 rit' : `${n} heats`;
                const gTijd = heeftTijden ? `<span class="gt">${stMap.get(rit.id) ?? '—'}</span>` : '';
                let rustHtml = '';
                if (rustTijdMap.has(groepKey)) {
                    const rustSec = rustTijdMap.get(groepKey);
                    const rustMin = Math.round(rustSec / 60);
                    const isWarn  = rustSec < RUST_WARN_SEC;
                    rustHtml = `<span class="rust${isWarn ? ' rust-warn' : ''}">${isWarn ? '⚠' : '✓'} rust ${rustMin} min</span>`;
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
            if (rit.opmerking) {
                tBody += `<tr class="rit-opm">
                    ${heeftTijden ? '<td></td>' : ''}
                    <td></td>
                    <td colspan="3" class="opm">📝 ${esc(rit.opmerking)}</td>
                </tr>`;
            }
            tBody += `<tr class="rit-rij">
                ${heeftTijden ? `<td class="ti${hasOvr ? ' ti-ovr' : ''}">${stMap.get(rit.id) ?? '—'}${hasOvr ? '&nbsp;📌' : ''}</td>` : ''}
                <td class="nr">${ritNr}</td>
                <td class="naam">${esc(rit.rit_naam)}${hdTxt ? `<span class="hd"> (${esc(hdTxt)})</span>` : ''}</td>
                <td><span class="badge sm" style="background:${kleur};color:#fff">${esc(label)}${esc(fin)}</span></td>
                <td class="vw">${rit.verwacht ?? '?'}</td>
            </tr>`;
        }
    });

    const datum   = comp?.starts ? formatDatum(comp.starts) : '';
    const locatie = comp ? getLocatie(comp) : '';
    const metaTxt = [datum, locatie].filter(Boolean).map(esc).join(' &nbsp;·&nbsp; ');

    const htmlDoc = `<!DOCTYPE html><html lang="nl">
<head><meta charset="UTF-8">
<title>Intern programma${comp?.name ? ' — ' + esc(comp.name) : ''}</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9.5pt;margin:.5cm 1cm 1cm;color:#111;line-height:1.4}
.pagina-header{display:flex;flex-wrap:nowrap;align-items:stretch;justify-content:space-between;gap:4mm;margin-bottom:0}
.hdr-links{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:flex-end}
.hdr-comp{font-size:15pt;font-weight:700;line-height:1.2;margin-bottom:.5mm}
.hdr-meta{font-size:9pt;color:#555}
.hdr-versie{font-size:7.5pt;color:#999;margin-top:1mm}
.hdr-rechts{flex-shrink:0;display:flex;align-items:flex-start}
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
td.ti-ovr{color:#c47200}
tr.rit-opm td{padding-bottom:0!important;border-bottom:none}
td.opm{font-size:8pt;color:#7a4200;font-style:italic;padding-left:12px;padding-top:4px}
@media print{
  body{margin:.4cm .9cm .9cm}
  tr{page-break-inside:avoid}
  tr.groep-hdr{page-break-before:auto}
  @page{margin:1cm 1.2cm;size:A4 portrait}
}
</style></head>
<body>
<div class="pagina-header">
  <div class="hdr-links">
    <div class="hdr-comp">${esc(comp?.name ?? 'Wedstrijdprogramma')}</div>
    ${metaTxt ? `<div class="hdr-meta">${metaTxt}</div>` : ''}
    ${schema.gegenereerd_op ? `<div class="hdr-versie">Programma gegenereerd op: ${esc(new Date(schema.gegenereerd_op.replace(' ','T')+'Z').toLocaleString('nl-NL',{day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'}))}</div>` : ''}
    <div class="hdr-versie" style="color:#b00">Intern gebruik — niet publiceren</div>
  </div>
  <div class="hdr-rechts">${orgLogoHtml}</div>
</div>
<hr class="hdr-lijn">
<table>
  <thead><tr>${tijdTh}<th class="nr">#</th><th>Rit</th><th>Type</th><th class="vw">Verwacht</th></tr></thead>
  <tbody>${tBody}</tbody>
</table>
${footerHtml}
</body></html>`;

    const win = window.open('', '_blank');
    if (!win) { toonBevestigDialog('Pop-up geblokkeerd — sta pop-ups toe voor deze pagina.', 'Afdrukken'); return; }
    win.document.write(htmlDoc);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 500);
}

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

function bouwAfstandGroepen() {
    const afstandMap = new Map(); // naam → [{dc_id, dc_naam, distance_id, n, merged_dc_ids?}]

    // Helper: voeg één entry toe aan afstandMap
    const voegToe = (dc_id, dc_naam, distance_id, distNaam, n, merged_dc_ids, category_filter) => {
        if (n <= 0) return;
        if (!afstandMap.has(distNaam)) afstandMap.set(distNaam, []);
        const entry = { dc_id, dc_naam, distance_id, n, category_filter: category_filter ?? '' };
        if (merged_dc_ids) entry.merged_dc_ids = merged_dc_ids;
        afstandMap.get(distNaam).push(entry);
    };

    // Helper: verwerk één enkelvoudige categorie (met eventuele splits)
    const verwerkEnkel = (cat, overrideN) => {
        const n          = overrideN ?? (cat.competitors?.filter(c => c.entry_status === 1).length ?? 0);
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
                    c => codes.includes(c.knsb?.category) && c.entry_status === 1
                ).length ?? 0;
                if (splitN <= 0) return;

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
            if (n > 0) perAfstand.forEach(dist =>
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
            (s, c) => s + (c.competitors?.filter(x => x.entry_status === 1).length ?? 0), 0
        );
        if (totaalN === 0) continue;

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
                ?.filter(x => x.entry_status === 1)
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
    _tsPollingInterval = setInterval(async () => {
        if (!huidigCompId) return;
        try {
            const res  = await fetch(`api/tijdschema.php?competition_id=${encodeURIComponent(huidigCompId)}&check_version=1`);
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
        } catch { /* stil falen */ }
    }, 30000);
}

function stopTsPolling() {
    if (_tsPollingInterval) { clearInterval(_tsPollingInterval); _tsPollingInterval = null; }
}
