/* InlineComp – import & vergelijk */

let _importLeesOnly = false;  // true als huidige gebruiker geen schrijfrechten heeft
let _heeftProgramma = false;  // true als er een tijdschema/programma is → DC-beheer readonly
let _orgTransponders = [];    // [{intern_nummer, transponder_code, toegewezen_snr, betaald}]

// ── Diff-classificatie ────────────────────────────────────────────────────
// Twee soorten diffs uit vergelijk.php:
//  - ACTIE-diffs (status, reserve) → vragen een Importeer-klik. KNSB heeft
//    iets gepushed dat de DB moet overnemen.
//  - INFO-diffs (naam, startnummer) → meestal BEWUSTE DB-correcties op
//    KNSB-feed-fouten die niet meer in de feed te corrigeren zijn. Operator
//    wil de visuele indicator (badge, geel rijtje, KNSB-hint per cel)
//    behouden om in oogopslag te zien dat er verschil is, maar wil NIET
//    eeuwig om een import gevraagd worden.
//
// Concreet:
//  - heeftWijzigingen / Importeer-knop → alleen actie-diffs
//  - cat-tab '!'-teller / per-rij '!'-badge / row-diff styling → alle diffs
const IMPORT_DIFF_VELDEN = new Set(['status', 'reserve']);
const _telImportDiffs = (c) =>
    Array.isArray(c?.diffs) ? c.diffs.filter(d => IMPORT_DIFF_VELDEN.has(d)).length : 0;

// ── Edit-staat initialiseren ──────────────────────────────────────────────────
// Effectieve startwaarden: DB heeft voorrang, KNSB is fallback

function initEdits() {
    personEdits      = {};
    entryEdits       = {};
    manualTp         = new Set();
    gewijzigdeRijen  = new Set();

    isGeimporteerd = vergelijkData.some(cat =>
        cat.competitors.some(c => c.db_entry !== null)
    );

    // Server-side diffs splitsen we in twee soorten:
    //  - actie-diffs (status, reserve): KNSB-feed-wijzigingen die om
    //    een Importeer-klik vragen — DB loopt achter op feed.
    //  - info-diffs (naam, startnummer): meestal BEWUSTE DB-correcties
    //    op een KNSB-feed-spelfout, geen actie nodig. Worden alleen
    //    visueel getoond als 'KNSB: ...' hint per cel.
    // Alleen actie-diffs triggeren de Importeer-knop, cat-tab-teller
    // en de per-rij '!'-badge.
    heeftWijzigingen = vergelijkData.some(cat =>
        cat.competitors.some(c => _telImportDiffs(c) > 0)
    );

    for (const cat of vergelijkData) {
        for (const item of cat.competitors) {
            const lk = item.license_key;
            if (!lk) continue;

            if (!personEdits[lk]) {
                const p      = item.db_person;
                const t1     = item.knsb.transponder1  || null;
                const t2     = item.knsb.transponder2  || null;
                const extras = [...(item.db_tp_extra   || [])];

                // Org-transponder: heeft deze rijder al een seizoens-toewijzing
                // in de organisatie-inventaris? Match op (toegewezen_snr +
                // toegewezen_naam) tegen _orgTransponders. Zo ja → die code
                // gebruiken als default voor slot 0. Dit zorgt dat rijders
                // die van de organisatie een transponder hebben gekregen, die
                // automatisch in de dropdown staan bij een nieuwe wedstrijd.
                const persoonSnr  = String(p ? (p.start_number ?? item.knsb.start_number) : item.knsb.start_number ?? '');
                const persoonNaam = String(p ? (p.full_name    ?? item.knsb.full_name)    : item.knsb.full_name    ?? '').trim();
                const orgMatch = _orgTransponders.find(ot =>
                    String(ot.toegewezen_snr  ?? '') === persoonSnr &&
                    String(ot.toegewezen_naam ?? '').trim() === persoonNaam &&
                    persoonSnr !== '' && persoonNaam !== ''
                );
                const orgTpCode = orgMatch?.transponder_code || null;

                // Actieve transponder:
                //   - slot 0 bewust opgeslagen in DB → gebruik DB-waarde (null = expliciete "geen")
                //   - nog nooit opgeslagen           → slim default:
                //       T1 (eigen KNSB)  → T2 → org-toewijzing → Textra → null
                //   Eigen transponders gaan voor op de org-uitleen: een rijder
                //   die een eigen T1 heeft gekocht/opgegeven in KNSB, gebruikt
                //   die standaard. De org-transponder is dan een reservedie
                //   je handmatig kunt kiezen als het nodig is.
                const defaultTp = item.db_tp_actief_isset
                    ? item.db_tp_actief
                    : (t1 ?? t2 ?? orgTpCode ?? extras[0] ?? null);

                personEdits[lk] = {
                    start_number:       p ? (p.start_number ?? item.knsb.start_number) : item.knsb.start_number,
                    full_name:          p ? (p.full_name    ?? item.knsb.full_name)    : item.knsb.full_name,
                    transponder1:       t1,
                    transponder2:       t2,
                    transponders_extra: extras,
                    transponder_actief: defaultTp,
                    short_name:         item.knsb.short_name,
                    gender:             item.knsb.gender,
                    // Categorie expliciet uit KNSB zodat de jaarlijkse
                    // age-up cyclus automatisch doorkomt (zie ook
                    // import.php). Dispensatie hoort op entry-niveau, niet op
                    // persons.
                    category:           item.knsb.category,
                    nationality:        item.knsb.nationality,
                    // Club + sponsor: DB-correctie wint (operator heeft via
                    // Systeem → Rijders mogelijk handmatig gecorrigeerd na
                    // verkeerde KNSB-data). Als de DB-waarde leeg is, valt
                    // het terug op KNSB. Bij Importeer wordt dit weer terug-
                    // gestuurd zodat het via import.php in persons komt.
                    club_code:          p?.club_code  ?? item.knsb.club_code,
                    club_short:         p?.club_short ?? item.knsb.club_short,
                    club_full:          p?.club_full  ?? item.knsb.club_full,
                    sponsor:            p?.sponsor    ?? item.knsb.sponsor,
                    city:               p?.city       ?? item.knsb.city,
                };
            }

            const ek = cat.dc_id + '_' + lk;
            entryEdits[ek] = {
                entry_status:    item.entry_status,
                knsb_status:     item.knsb_status ?? item.entry_status,  // altijd originele KNSB API-status (0/1/2)
                reserve:         item.reserve,
                knsb_reserve:    item.knsb_reserve ?? null,   // origineel KNSB-volgnummer
                reserve_ingezet: item.reserve_ingezet ?? 0,
                knsb_entry_id:   item.knsb_entry_id,
            };
        }
    }
}

// ── Beheer-tabel ──────────────────────────────────────────────────────────────
// Toont effectieve rijen: merged DCs = 1 rij, gesplitste DC = meerdere rijen.
// Kolommen: Distance Combination | Categorieën | Afstand 1 | Afstand 2 | … | +

// Module-niveau helpers — ook nodig in slaaBeheerOp (buiten bouwBeheerTabel scope)
function distKey(dcId, splitGroup) {
    return splitGroup ? `${dcId}::${splitGroup}` : dcId;
}
function parseDistKey(key) {
    const sep = key.indexOf('::');
    return sep === -1
        ? { dcId: key, splitGroup: null }
        : { dcId: key.substring(0, sep), splitGroup: key.substring(sep + 2) };
}

async function bouwBeheerTabel() {
    const panel = el('beheer-panel');
    if (!panel || !vergelijkData.length) return;

    // Niet tonen als de wedstrijd nog niet geïmporteerd is. Voor handmatige
    // wedstrijden zijn er nog geen competitors (rijder-import komt in Fase 2)
    // maar de wedstrijd staat wel in DB — huidigImported is dan ook true,
    // zodat het beheer-panel (DC's splitsen/combineren + afstanden) gewoon
    // bruikbaar is.
    if (!isGeimporteerd && !huidigImported) {
        panel.innerHTML = '';
        return;
    }

    // Verwijder event listeners van een vorige competitie
    if (panel._beheerAbort) panel._beheerAbort.abort();
    const beheerAbort  = new AbortController();
    panel._beheerAbort = beheerAbort;
    const { signal }   = beheerAbort;

    // Laad DB-afstanden (gegroepeerd op target_group); fallback naar KNSB.
    // Globale dcDistances zodat printDeelnemerslijst er ook bij kan.
    // BULK-CALL: 1 request met alle DC-ids ipv N parallelle requests. Oude
    // implementatie deed Promise.all over N DCs — bij 6+ DCs trekt iFastNet
    // dat als 'loop' en stuurt HTTP 508 op één of meerdere requests, waarna
    // de beheer-tabel halfvol bleef. Bulk-endpoint lost dat in 1 keer op.
    dcDistances = {};
    const dcIds = vergelijkData.map(c => c.dc_id).filter(Boolean);
    let bulk = {};
    if (dcIds.length) {
        try {
            const url = 'api/distances_db.php?dc_ids='
                + dcIds.map(encodeURIComponent).join(',');
            const res = await fetch(url);
            if (res.ok) bulk = await res.json();
        } catch { /* stil falen — fallback hieronder */ }
    }
    vergelijkData.forEach(cat => {
        const data = Array.isArray(bulk[cat.dc_id]) ? bulk[cat.dc_id] : null;
        if (data && data.length) {
            // Groepeer op target_group → aparte sleutels per splitgroep
            data.forEach(d => {
                const k = distKey(cat.dc_id, d.target_group || null);
                if (!dcDistances[k]) dcDistances[k] = [];
                dcDistances[k].push({ id: d.id, number: d.number, name: d.name,
                                      value_meters: d.value_meters, race_type: d.race_type });
            });
            if (!dcDistances[cat.dc_id]) dcDistances[cat.dc_id] = [];
        } else {
            // Geen DB-afstanden of bulk-call mislukt → KNSB-fallback
            dcDistances[cat.dc_id] =
                (cat.knsb_distances || []).map(d => ({ id: '', number: d.number, name: d.name,
                                                       value_meters: d.value_meters, race_type: d.race_type }));
        }
    });

    // Standaard ingeklapt bij laden — gebruiker klapt open als aanpassing nodig is
    let beheerIngeklapt = true;
    let _beheerDirty = false;

    function markBeheerDirty() {
        _beheerDirty = true;
        const btn = el('btn-beheer-opslaan');
        if (btn) {
            btn.disabled = false;
            btn.classList.add('btn-beheer-dirty');
        }
    }
    function markBeheerClean() {
        _beheerDirty = false;
        const btn = el('btn-beheer-opslaan');
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('btn-beheer-dirty');
        }
    }
    // Exporteer dirty-check zodat navigatie-guards erbij kunnen
    panel._isBeheerDirty = () => _beheerDirty;
    panel._markBeheerClean = markBeheerClean;

    // DC-beheer: volledig readonly of alleen structureel geblokkeerd
    const beheerReadonly = _importLeesOnly;
    const beheerStructuurLock = _heeftProgramma && !_importLeesOnly;

    // Vaste buitenste structuur (nooit overschreven → event-handlers blijven actief)
    panel.innerHTML =
        (beheerReadonly
            ? `<div class="beheer-readonly-melding">Geen schrijfrechten.</div>`
            : '') +
        `<div id="beheer-tabel-wrap"></div>` +
        `<div class="beheer-acties"${beheerReadonly ? ' style="display:none"' : ''}>` +
        `<button class="btn-secondary btn-beheer-dirty-btn" id="btn-beheer-opslaan" disabled>&#10003; Opslaan</button>` +
        `<span class="beheer-status" id="beheer-status"></span>` +
        `</div>`;
    panel.dataset.readonly = beheerReadonly ? '1' : '';
    panel.dataset.structuurLock = beheerStructuurLock ? '1' : '';

    // ── Effectieve rijen berekenen ─────────────────────────────────────────────
    // Elke rij = één toekomstige startlijst
    function computeRows() {
        const usedIds = new Set();
        const rows    = [];

        vergelijkData.forEach(cat => {
            if (usedIds.has(cat.dc_id)) return;
            usedIds.add(cat.dc_id);

            // Alle DCs in dezelfde merge-groep samenpakken
            let dcGroup = [cat];
            if (cat.merge_group) {
                vergelijkData.forEach(c => {
                    if (!usedIds.has(c.dc_id) && c.merge_group === cat.merge_group) {
                        usedIds.add(c.dc_id);
                        dcGroup.push(c);
                    }
                });
            }

            // Gecombineerde categorieën (met bijhouden welke DC elke cat bezit)
            // Bron 1: rijders (KNSB-flow) — cats die in inschrijvingen voorkomen.
            // Bron 2: category_filter (handmatige flow + KNSB-fallback) — de
            // operator-aangewezen cats. Count blijft op rijder-aantal zodat
            // de UI niet "0 rijder" toont voor KNSB-flow met data, maar zonder
            // rijders (handmatig) zijn de cats wél zichtbaar als chips.
            const catInfo = {};   // { catNaam: { dcId, count } }
            dcGroup.forEach(dc => {
                dc.competitors.forEach(c => {
                    const k = c.knsb?.category;
                    if (!k) return;
                    if (!catInfo[k]) catInfo[k] = { dcId: dc.dc_id, count: 0 };
                    catInfo[k].count++;
                });
                // category_filter is "HSA,HSJ" CSV — voeg cats toe die nog
                // niet uit rijders kwamen (count blijft 0 voor die).
                if (dc.category_filter) {
                    dc.category_filter.split(',').forEach(raw => {
                        const k = raw.trim();
                        if (!k) return;
                        if (!catInfo[k]) catInfo[k] = { dcId: dc.dc_id, count: 0 };
                    });
                }
            });

            // Gecombineerde split-configuratie
            const allSplits = {};   // { catNaam: splitgroep }
            dcGroup.forEach(dc => {
                Object.entries(dc.splits || {}).forEach(([k, v]) => { if (v) allSplits[k] = v; });
            });

            const allCats   = Object.keys(catInfo).sort();
            const splitGrps = [...new Set(Object.values(allSplits))].sort();
            const meerdere  = allCats.length > 1;

            if (splitGrps.length) {
                // Elke splitgroep → eigen rij
                splitGrps.forEach(sg => {
                    const sgCats = allCats.filter(k => allSplits[k] === sg);
                    if (!sgCats.length) return;
                    const sgInfo = {};
                    sgCats.forEach(k => { sgInfo[k] = catInfo[k]; });
                    rows.push({ type: 'split', displayName: sg, primaryDcId: cat.dc_id,
                                dcGroup, catInfo: sgInfo, allCatInfo: catInfo, allSplits, splitGroup: sg, meerdere });
                });
                // Niet-toegewezen categorieën → resterende rij
                const unsplit = allCats.filter(k => !allSplits[k]);
                if (unsplit.length) {
                    const uInfo = {};
                    unsplit.forEach(k => { uInfo[k] = catInfo[k]; });
                    rows.push({ type: 'rest', displayName: cat.dc_name, primaryDcId: cat.dc_id,
                                dcGroup, catInfo: uInfo, allCatInfo: catInfo, allSplits, meerdere });
                }
            } else {
                rows.push({ type: dcGroup.length > 1 ? 'merged' : 'normal',
                            displayName: cat.dc_name, primaryDcId: cat.dc_id,
                            dcGroup, catInfo, allCatInfo: catInfo, allSplits, meerdere });
            }
        });
        return rows;
    }

    // ── Tabel renderen ─────────────────────────────────────────────────────────
    function renderTabel() {
        const ro       = panel.dataset.readonly === '1';
        const sl       = panel.dataset.structuurLock === '1';  // structureel geblokkeerd
        const rows     = computeRows();
        // maxDists over alle sleutels (inclusief split-groep sleutels)
        const maxDists = Math.max(0, ...Object.values(dcDistances).map(a => a.length));
        const pijl    = beheerIngeklapt ? '&#9654;' : '&#9660;';
        const distThs = beheerIngeklapt ? '' : Array.from({ length: maxDists }, (_, i) =>
            `<th class="dc-th-afd">Afstand ${i + 1}</th>`).join('');

        const rowsHtml = rows.map(row => {
            const { type, displayName, primaryDcId, dcGroup, catInfo, allCatInfo, allSplits, meerdere } = row;
            const cats    = Object.keys(catInfo).sort();
            const allCats = Object.keys(allCatInfo).sort();

            // ── Kolom 1: DC-namen + samenvoeg-control ──────────────────────────
            let naamCel = '';
            dcGroup.forEach(dc => {
                naamCel += `<div class="dc-groep-naam">` +
                    `<span class="dc-groep-lbl">${escHtml(dc.dc_name)}</span>` +
                    (dcGroup.length > 1
                        ? `<button class="btn-del dc-ontkoppel" data-dc-id="${escHtml(dc.dc_id)}" title="Samenvoegen ongedaan maken">&#128465;</button>`
                        : '') +
                    `</div>`;
            });

            // Label-input bij samengevoegde groep (zodat de naam in het schema leesbaar blijft)
            if (type === 'merged') {
                const huidigLabel = (dcGroup.find(d => d.merge_label) ?? {}).merge_label ?? '';
                naamCel += `<div class="dc-merge-label-rij">` +
                    `<span class="dc-merge-lbl" title="Naam in tijdschema">✏</span>` +
                    `<input type="text" class="inp dc-merge-label-inp"` +
                    ` data-primary-dc-id="${escHtml(primaryDcId)}"` +
                    ` value="${escHtml(huidigLabel)}" placeholder="Naam in tijdschema…" maxlength="80">` +
                    `</div>`;
            }

            // Merge-select: toon alleen als dit geen split-rij is
            if (type === 'normal' || type === 'merged') {
                const uitsluitIds = new Set(dcGroup.map(d => d.dc_id));
                const vrijeDCsLijst = vergelijkData.filter(c => !uitsluitIds.has(c.dc_id) && !c.merge_group);
                if (vrijeDCsLijst.length) {
                    const opties = vrijeDCsLijst.map(c =>
                        `<option value="${escHtml(c.dc_id)}">${escHtml(c.dc_name)}</option>`).join('');
                    naamCel += `<div class="dc-merge-rij">` +
                        `<span class="dc-merge-lbl">&#8644;</span>` +
                        `<select class="inp dc-merge-sel" data-primary-dc-id="${escHtml(primaryDcId)}">` +
                        `<option value="">— samenvoegen met… —</option>` + opties +
                        `</select></div>`;
                }
            }

            // ── Kolom 2: Categorieën + split-inputs ────────────────────────────
            const catCel = cats.length
                ? cats.map(k => {
                    const info     = catInfo[k];
                    const splitVal = allSplits[k] || '';
                    const splitDeel = meerdere
                        ? ` <span class="dc-pijl">&#8594;</span>` +
                          `<input type="text" class="inp dc-split-inp"` +
                          ` data-cat-dc-id="${escHtml(info.dcId)}"` +
                          ` data-category="${escHtml(k)}"` +
                          ` value="${escHtml(splitVal)}" placeholder="splitgroep…" maxlength="40">` +
                          `<button class="btn-del merge-wis-btn dc-split-wis" tabindex="-1"` +
                          ` style="${splitVal ? '' : 'visibility:hidden'}">&#128465;</button>`
                        : '';
                    return `<div class="dc-cat-rij">` +
                           `<span class="dc-cat-badge">${escHtml(k)}</span>` +
                           `<span class="dc-cat-tel">${info.count}</span>` +
                           splitDeel + `</div>`;
                }).join('')
                : `<span class="dc-geen">—</span>`;

            // ── Afstandscellen: elke rij heeft zijn eigen sleutel ───────────────
            // Split-rijen: sleutel = dc_id::splitGroep (eigen afstanden per groep)
            // Normal/merged/rest: sleutel = dc_id (of per DC binnen merged groep)
            const rowSplitGroup = row.splitGroup || null;
            const rowDistKey    = distKey(primaryDcId, rowSplitGroup);

            if (rowSplitGroup && !dcDistances[rowDistKey]) {
                // Nieuwe splitgroep: initialiseer als kopie van basis-afstanden
                dcDistances[rowDistKey] = (dcDistances[primaryDcId] || [])
                    .map(d => ({ ...d, id: '' }));  // id='' → nieuwe DB-rijen
            }

            const allDists = [];
            if (rowSplitGroup) {
                // Splitrij: eigen afstanden
                (dcDistances[rowDistKey] || []).forEach((d, i) =>
                    allDists.push({ ...d, _dcId: primaryDcId, _key: rowDistKey }));
            } else {
                // Normal/merged/rest: combineer alle DCs in de groep (dedup op naam)
                const seenNames = new Set();
                dcGroup.forEach(dc => {
                    (dcDistances[dc.dc_id] || []).forEach(d => {
                        if (d.name && seenNames.has(d.name)) return;
                        if (d.name) seenNames.add(d.name);
                        allDists.push({ ...d, _dcId: dc.dc_id, _key: dc.dc_id });
                    });
                });
            }

            const distCells = Array.from({ length: maxDists }, (_, i) => {
                const d = allDists[i];
                if (!d) return `<td class="dc-afd-leeg"></td>`;
                // race_type-default voor nieuwe afstanden: sprint als <=1000m,
                // anders inline. Een expliciet opgeslagen race_type wint altijd.
                const _defaultRt = ((d.value_meters ?? 0) > 1000) ? 'inline' : 'sprint';
                const _rt = d.race_type || _defaultRt;
                const _opt = (val, lbl) =>
                    `<option value="${val}"${_rt === val ? ' selected' : ''}>${lbl}</option>`;
                return `<td class="dc-afd-kol" data-dist-key="${escHtml(d._key)}"` +
                    ` data-idx="${i}" data-afd-id="${escHtml(d.id || '')}">` +
                    `<div class="dc-afd-kol-inner">` +
                    `<input class="inp dc-afd-naam" value="${escHtml(d.name || '')}" placeholder="naam" title="${escHtml(d.name || '')}">` +
                    `<input class="inp dc-afd-m" type="number" min="0" max="99999"` +
                    ` value="${escHtml(String(d.value_meters ?? ''))}" placeholder="m">` +
                    `<select class="inp dc-afd-race-type" title="Race-type">` +
                        _opt('sprint',      'Sprint') +
                        _opt('inline',      'Inline') +
                        _opt('puntenkoers', 'Puntenkoers') +
                        _opt('afvalkoers',  'Afvalkoers') +
                    `</select>` +
                    `<button class="btn-del dc-afd-del">&#128465;</button>` +
                    `</div></td>`;
            }).join('');

            const plusCel = `<td class="dc-afd-plus-cel">` +
                `<button class="afd-plus-btn" data-dist-key="${escHtml(rowDistKey)}">+</button>` +
                `</td>`;

            return `<tr data-primary-dc-id="${escHtml(primaryDcId)}">` +
                `<td class="dc-naam-cel">${naamCel}</td>` +
                `<td class="dc-cats-cel">${catCel}</td>` +
                distCells +
                plusCel + `</tr>`;
        }).join('');

        // Beheer-acties (opslaan-knop) verbergen als ingeklapt
        const actiesEl = panel.querySelector('.beheer-acties');
        if (actiesEl) actiesEl.style.display = beheerIngeklapt ? 'none' : '';

        const slMelding = (!beheerIngeklapt && sl)
            ? `<div class="beheer-readonly-melding">Er is een programma aangemaakt — alleen namen/labels zijn bewerkbaar. Structurele wijzigingen (samenvoegen, splitsen, afstanden) vereisen eerst "Wis programma" in het Tijdschema.</div>`
            : '';

        el('beheer-tabel-wrap').innerHTML =
            `<table class="beheer-tabel">` +
            `<thead class="beheer-thead-toggle">` +
            `<tr>` +
            `<th class="dc-th-naam">Distance Combinations <span class="beheer-pijl">${pijl}</span></th>` +
            (beheerIngeklapt ? '' :
                `<th class="dc-th-cats">Categorieën</th>` +
                distThs +
                `<th class="dc-th-plus"></th>`) +
            `</tr>` +
            `</thead>` +
            (beheerIngeklapt ? '' : `<tbody>${rowsHtml}</tbody>`) +
            `</table>` +
            slMelding;

        // Readonly: alle interactieve elementen disablen
        if (ro) {
            const wrap = el('beheer-tabel-wrap');
            wrap.querySelectorAll('input, select, button').forEach(e => { e.disabled = true; });
            wrap.querySelectorAll('.dc-ontkoppel, .dc-split-wis, .dc-afd-del, .afd-plus-btn').forEach(e => { e.style.display = 'none'; });
        }
        // Structuur-lock: ALLE afstand-velden + structurele controls disablen.
        // Afstand-naam/meters/race-type vormen een triple die samen klopt;
        // een naam-only edit ná genereren maakt rit_naam inconsistent met
        // de werkelijke meters/race-type (records-rapport + klassementen
        // rekenen op de cijfers, niet op de label). Operator gebruikt
        // 'Wis programma' om consistent te wijzigen.
        // Alleen DC-naam + merge-label (puur display-labels zonder rekening-
        // houden) blijven bewerkbaar bij lock — die zijn losgekoppeld van
        // alle berekeningen.
        if (sl && !ro) {
            const wrap = el('beheer-tabel-wrap');
            wrap.querySelectorAll('.dc-merge-sel, .dc-split-inp, .dc-afd-naam, .dc-afd-m, .dc-afd-race-type').forEach(e => { e.disabled = true; });
            wrap.querySelectorAll('.dc-ontkoppel, .dc-split-wis, .dc-afd-del, .afd-plus-btn').forEach(e => { e.style.display = 'none'; });
        }
    }

    renderTabel();

    // ── Sync-hulpfuncties ──────────────────────────────────────────────────────

    function syncSplitsVanDom() {
        // Reset alle splits
        vergelijkData.forEach(c => { c.splits = {}; });
        panel.querySelectorAll('.dc-split-inp').forEach(inp => {
            const dcId = inp.dataset.catDcId;
            const cat  = vergelijkData.find(c => c.dc_id === dcId);
            if (!cat) return;
            const val = inp.value.trim();
            if (val) cat.splits[inp.dataset.category] = val;
        });
    }

    function syncDistVanDom(key) {
        const cels = [...panel.querySelectorAll(`td.dc-afd-kol[data-dist-key="${CSS.escape(key)}"]`)];
        if (!cels.length) return;
        dcDistances[key] = cels.map((cel, i) => ({
            id:           cel.dataset.afdId || '',
            number:       i + 1,
            name:         cel.querySelector('.dc-afd-naam')?.value.trim() || '',
            value_meters: parseInt(cel.querySelector('.dc-afd-m')?.value) || null,
            race_type:    cel.querySelector('.dc-afd-race-type')?.value || 'sprint',
        }));
    }

    function syncMergeLabelsVanDom() {
        panel.querySelectorAll('.dc-merge-label-inp').forEach(inp => {
            const primaryId = inp.dataset.primaryDcId;
            const label     = inp.value.trim() || null;
            const primary   = vergelijkData.find(c => c.dc_id === primaryId);
            const mergeKey  = primary?.merge_group;
            if (!mergeKey) return;
            vergelijkData.filter(c => c.merge_group === mergeKey)
                         .forEach(c => { c.merge_label = label; });
        });
    }

    function syncAllesVanDom() {
        syncSplitsVanDom();
        syncMergeLabelsVanDom();
        // Vind alle unieke dist-keys in het DOM en sync elk één keer
        const keys = new Set();
        panel.querySelectorAll('td.dc-afd-kol[data-dist-key]').forEach(cel => keys.add(cel.dataset.distKey));
        keys.forEach(key => syncDistVanDom(key));
    }

    function cleanupMergeGroups() {
        // Als een merge-groep maar 1 DC over heeft, wis die merge_group
        const counts = {};
        vergelijkData.forEach(c => { if (c.merge_group) counts[c.merge_group] = (counts[c.merge_group] || 0) + 1; });
        vergelijkData.forEach(c => { if (c.merge_group && counts[c.merge_group] < 2) c.merge_group = null; });
    }

    // ── Event delegation ───────────────────────────────────────────────────────

    panel.addEventListener('input', e => {
        if (e.target.classList.contains('dc-split-inp')) {
            const wis = e.target.nextElementSibling;
            if (wis?.classList.contains('dc-split-wis'))
                wis.style.visibility = e.target.value.trim() ? 'visible' : 'hidden';
        }
    }, { signal });

    panel.addEventListener('change', e => {
        // Samenvoegen: selecteer een andere DC
        if (e.target.classList.contains('dc-merge-sel')) {
            const targetDcId  = e.target.value;
            if (!targetDcId) return;
            const primaryDcId = e.target.dataset.primaryDcId;
            const primary     = vergelijkData.find(c => c.dc_id === primaryDcId);
            const target      = vergelijkData.find(c => c.dc_id === targetDcId);
            if (!primary || !target) return;
            const mergeKey = primaryDcId;   // gebruik dc_id als unieke merge-sleutel
            primary.merge_group = mergeKey;
            target.merge_group  = mergeKey;
            syncAllesVanDom();
            markBeheerDirty();
            renderTabel(); return;
        }
        // Split-veld: rij opsplitsen na invullen
        if (e.target.classList.contains('dc-split-inp')) {
            syncAllesVanDom();
            markBeheerDirty();
            renderTabel(); return;
        }
        // Alle overige inputs in het beheer-panel (merge-label, afstand-naam, meters)
        if (e.target.closest('#beheer-tabel-wrap')) {
            markBeheerDirty();
        }
    }, { signal });

    panel.addEventListener('click', e => {
        // Thead-rij: in-/uitklappen
        if (e.target.closest('.beheer-thead-toggle')) {
            if (!beheerIngeklapt) syncAllesVanDom(); // alleen synchen als tabel open is (inputs bestaan)
            beheerIngeklapt = !beheerIngeklapt;
            renderTabel(); return;
        }
        // Samenvoegen ongedaan maken
        if (e.target.classList.contains('dc-ontkoppel')) {
            const dcId = e.target.dataset.dcId;
            const cat  = vergelijkData.find(c => c.dc_id === dcId);
            if (cat) cat.merge_group = null;
            cleanupMergeGroups();
            syncAllesVanDom();
            markBeheerDirty();
            renderTabel(); return;
        }
        // Split-veld wissen
        if (e.target.classList.contains('dc-split-wis')) {
            const inp = e.target.previousElementSibling;
            inp.value = ''; e.target.style.visibility = 'hidden';
            syncAllesVanDom();
            markBeheerDirty();
            renderTabel(); return;
        }
        // Afstand verwijderen
        if (e.target.classList.contains('dc-afd-del')) {
            const kol = e.target.closest('.dc-afd-kol');
            const key = kol?.dataset?.distKey;
            const idx = parseInt(kol?.dataset?.idx ?? '-1');
            if (!key || idx < 0) return;
            syncAllesVanDom();
            if (dcDistances[key]) {
                dcDistances[key].splice(idx, 1);
                dcDistances[key] = dcDistances[key].filter(d => d.name || d.value_meters);
            }
            markBeheerDirty();
            renderTabel(); return;
        }
        // Afstand toevoegen
        if (e.target.classList.contains('afd-plus-btn')) {
            const key = e.target.dataset?.distKey;
            if (!key) return;
            syncAllesVanDom();
            if (!dcDistances[key]) dcDistances[key] = [];
            dcDistances[key] = dcDistances[key].filter(d => d.name || d.value_meters);
            dcDistances[key].push({ id: '', number: dcDistances[key].length + 1, name: '', value_meters: null });
            markBeheerDirty();
            renderTabel();
            const nms = panel.querySelectorAll(`td.dc-afd-kol[data-dist-key="${CSS.escape(key)}"] .dc-afd-naam`);
            if (nms.length) nms[nms.length - 1].focus();
            return;
        }
    }, { signal });

    el('btn-beheer-opslaan').addEventListener('click', () => slaaBeheerOp(panel, dcDistances), { signal });
}

async function slaaBeheerOp(panel, dcDistances) {
    const btn    = el('btn-beheer-opslaan');
    const status = el('beheer-status');
    btn.disabled = true;
    status.textContent = 'Opslaan…';

    // Sync alles uit DOM vóór opslaan (inline: slaaBeheerOp staat buiten bouwBeheerTabel)
    vergelijkData.forEach(c => { c.splits = {}; });
    panel.querySelectorAll('.dc-split-inp').forEach(inp => {
        const cat = vergelijkData.find(c => c.dc_id === inp.dataset.catDcId);
        if (!cat) return;
        const val = inp.value.trim();
        if (val) cat.splits[inp.dataset.category] = val;
    });
    const distKeys = new Set();
    panel.querySelectorAll('td.dc-afd-kol[data-dist-key]').forEach(cel => distKeys.add(cel.dataset.distKey));
    distKeys.forEach(key => {
        const cels = [...panel.querySelectorAll(`td.dc-afd-kol[data-dist-key="${CSS.escape(key)}"]`)];
        dcDistances[key] = cels.map((cel, i) => ({
            id:           cel.dataset.afdId || '',
            number:       i + 1,
            name:         cel.querySelector('.dc-afd-naam')?.value.trim() || '',
            value_meters: parseInt(cel.querySelector('.dc-afd-m')?.value) || null,
            race_type:    cel.querySelector('.dc-afd-race-type')?.value || 'sprint',
        }));
    });

    // Sync merge_label vanuit DOM (inline — syncMergeLabelsVanDom is lokaal in bouwBeheerTabel)
    panel.querySelectorAll('.dc-merge-label-inp').forEach(inp => {
        const primaryId = inp.dataset.primaryDcId;
        const label     = inp.value.trim() || null;
        const primary   = vergelijkData.find(c => c.dc_id === primaryId);
        const mergeKey  = primary?.merge_group;
        if (!mergeKey) return;
        vergelijkData.filter(c => c.merge_group === mergeKey)
                     .forEach(c => { c.merge_label = label; });
    });

    const merges = vergelijkData.map(c => ({
        dc_id:       c.dc_id,
        merge_group: c.merge_group  || null,
        merge_label: c.merge_label  ?? null,
    }));
    const splitsByDc = {};
    vergelijkData.forEach(c => {
        if (Object.keys(c.splits || {}).length)
            splitsByDc[c.dc_id] = Object.entries(c.splits).map(([cat, sg]) => ({ category: cat, split_group: sg }));
    });
    // Zorg dat DCs zonder splits ook een lege splits-lijst krijgen (verwijdert oude splits)
    vergelijkData.forEach(c => {
        if (!splitsByDc[c.dc_id]) splitsByDc[c.dc_id] = [];
    });

    try {
        const basisRes = await Promise.all([
            fetch('api/samenvoeg.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ competition_id: huidigCompId, merges }),
            }).then(r => r.json()),
            ...Object.entries(splitsByDc).map(([dcId, splits]) =>
                fetch('api/splits.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ competition_id: huidigCompId, dc_id: dcId, splits }),
                }).then(r => r.json())
            ),
        ]);
        // Verwijder afstanden van vervallen splitgroepen (sleutels die nog in dcDistances
        // zitten maar niet meer actief zijn in de DOM → splits zijn verwijderd).
        const staleKeys = Object.keys(dcDistances).filter(k => k.includes('::') && !distKeys.has(k));
        for (const key of staleKeys) {
            const { dcId, splitGroup } = parseDistKey(key);
            await fetch('api/afstanden_beheer.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ dc_id: dcId, split_group: splitGroup, distances: [] }),
            });
            delete dcDistances[key];
        }

        // Sequentieel i.p.v. Promise.all: voorkomt gelijktijdige transacties
        // op de distances-tabel (InnoDB gap-locks → deadlock bij parallel).
        // Alleen actieve sleutels opslaan (distKeys = wat momenteel zichtbaar is in DOM).
        const afdRes = [];
        for (const [key, dists] of Object.entries(dcDistances)) {
            if (!distKeys.has(key)) continue;   // sla nooit inactieve sleutels op
            const { dcId, splitGroup } = parseDistKey(key);
            const data = await fetch('api/afstanden_beheer.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    dc_id:       dcId,
                    split_group: splitGroup,
                    distances: dists.filter(d => d.name).map((d, i) => ({
                        id: d.id || null, number: i + 1,
                        name: d.name, value_meters: d.value_meters,
                        race_type: d.race_type || 'sprint',
                    })),
                }),
            }).then(r => r.json()).then(res => ({ key, dcId, ...res }));
            afdRes.push(data);
        }

        const fouten = [...basisRes, ...afdRes].filter(r => r.error);
        if (fouten.length) throw new Error(fouten.map(r => r.error).join('; '));

        // Server-IDs terugschrijven
        afdRes.forEach(r => {
            if (!r.distances || !r.key || !dcDistances[r.key]) return;
            r.distances.forEach(d => {
                const item = dcDistances[r.key].find(x => x.name === d.name && !x.id);
                if (item) item.id = d.id;
            });
        });

        status.innerHTML = '<span style="color:var(--oranje)">&#10003; Opgeslagen</span>';
        setTimeout(() => { status.textContent = ''; }, 2500);
        // Reset dirty flag + knop na succesvol opslaan
        if (panel._markBeheerClean) panel._markBeheerClean();
        btn.textContent = '✓ Opslaan';
    } catch(e) {
        status.innerHTML = `<span style="color:#c00">&#9888; ${escHtml(e.message)}</span>`;
        btn.disabled = false;
        btn.textContent = '✓ Opslaan';
    }
}







// ── Categorietabbladen bouwen ─────────────────────────────────────────────────

function bouwVergelijkTabbladen() {
    _importLeesOnly = !magSchrijven('importeer');
    const tabs    = el('imp-cat-tabs');
    const content = el('imp-cat-content');

    if (!vergelijkData.length) {
        statusMsg(content, 'info', 'Geen deelnemers gevonden.');
        return;
    }

    bouwBeheerTabel();

    tabs.innerHTML = '';
    vergelijkData.forEach((cat, i) => {
        const totaal    = cat.competitors.length;
        const afgemeld  = cat.competitors.filter(c => c.entry_status >= 2 && c.entry_status !== 5).length;
        const nieuw     = cat.competitors.filter(c => c.is_new).length;
        // Diff-teller: hoeveel rijders hebben ÉÉN of meer KNSB-feed-
        // verschillen (status/reserve/naam/startnummer). Visuele info
        // voor de operator zodat 'ie de juiste cat vindt — de Importeer-
        // knop wordt apart bepaald (alleen status/reserve telt daar).
        const diff = cat.competitors.filter(
            c => Array.isArray(c.diffs) && c.diffs.length > 0
        ).length;

        let badge = '';
        if (afgemeld) badge += ` <span class="tab-badge afgemeld">${afgemeld}✗</span>`;
        if (nieuw)    badge += ` <span class="tab-badge nieuw">${nieuw}N</span>`;
        if (diff)     badge += ` <span class="tab-badge diff" title="${diff} rijder${diff>1?'s':''} met feed-wijziging">${diff}!</span>`;

        const btn = document.createElement('button');
        btn.className = 'tab-btn' + (i === 0 ? ' active' : '');
        btn.innerHTML = escHtml(cat.dc_name) + ' (' + totaal + ')' + badge;
        btn.addEventListener('click', () => {
            tabs.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeCat = cat;
            toonVergelijkTabel(cat);
        });
        tabs.appendChild(btn);
    });

    activeCat = vergelijkData[0];
    toonVergelijkTabel(vergelijkData[0]);
}

// ── Vergelijktabel tonen ──────────────────────────────────────────────────────

// Eén-keer-per-cat-per-sessie: voorkomt dubbele sync-calls bij snel klikken.
const _reservesSyncedDcs = new Set();

// Bulk-sync entries.reserve naar de DB op basis van de huidige KNSB-feed
// (item.reserve in vergelijkData). Beschermt operator-NULL (reserve_handmatig
// _ingezet=1). Zonder deze sync zou startlijst_genereer reserves niet kunnen
// filteren omdat entries.reserve op default-NULL blijft staan na de migratie.
async function _syncReservesNaarDB(cat) {
    if (_reservesSyncedDcs.has(cat.dc_id)) return;
    _reservesSyncedDcs.add(cat.dc_id);

    const reserves     = [];
    const nietReserves = [];
    for (const item of cat.competitors) {
        const lk = item.license_key;
        if (!lk) continue;
        // item.reserve uit vergelijkData = effectieve waarde (DB met
        // NULL-bescherming, anders KNSB-feed). Bij ingezette reserves =
        // null (en reserve_handmatig_ingezet=1 in DB → endpoint laat 'm
        // sowieso met rust).
        if (item.reserve != null && item.reserve > 0) {
            reserves.push({ person_license: lk, reserve_nr: item.reserve });
        } else {
            nietReserves.push(lk);
        }
    }
    if (!reserves.length && !nietReserves.length) return;
    try {
        await fetch('api/reserves_sync.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                competition_id: huidigCompId,
                dc_id:          cat.dc_id,
                reserves, niet_reserves: nietReserves,
            }),
        });
    } catch { /* sync mag stilzwijgend falen — geen blocker voor UI */ }
}

function toonVergelijkTabel(cat) {
    const content = el('imp-cat-content');

    // Sync entries.reserve met huidige KNSB-feed-state. Async, niet awaiten
    // — het is een achtergrond-update die alleen impact heeft op latere
    // startlijst_genereer-aanroepen, niet op de huidige render.
    // Skip voor handmatige wedstrijden: er IS geen KNSB-feed om mee te
    // syncen + reserves_sync.php is alleen relevant voor KNSB-flow.
    if (!(typeof huidigComp !== 'undefined' && huidigComp?.is_handmatig)) {
        _syncReservesNaarDB(cat);
    }

    if (!cat.competitors.length) {
        content.innerHTML =
            `<div class="vergelijk-wrap">
                <div class="status-msg info">Geen deelnemers in deze categorie.</div>
                <button class="btn-deelnemer-add" data-dc-id="${escHtml(cat.dc_id)}">+ Deelnemer toevoegen</button>
             </div>`;
        content.querySelector('.btn-deelnemer-add')
            ?.addEventListener('click', () => openDeelnemerModal(cat.dc_id));
        return;
    }

    // ── Reserve-beheer-paneel boven de vergelijk-tabel ───────────────────────
    // Tellingen voor in de paneel-header. Belangrijk: "origineel reserve" =
    // (reserve != null) OF (reserve_ingezet === 1). Een ingezette reserve was
    // dus ooit reserve, telt NIET mee in "niet-reserves" — anders zou het
    // niet-reserves-getal magisch oplopen bij elke inzet.
    //
    // Tellingen:
    //   - nietReservesTotaal:    origineel geen reserve (ongeacht status)
    //   - nietReservesBevestigd: idem, met status=1
    //   - reservesTotaal:        origineel wel reserve (ingezet of niet)
    //   - reservesBevestigd:     reserves die NIET ingezet zijn, met status=1
    //                            (= kandidaten voor inzet-knop)
    //   - reservesIngezetN:      reserves die nu ingezet zijn
    //   - totaalInLoting:        rijders die in de startlijst-loting komen =
    //                            server-filter: status IN (1,5) AND reserve IS NULL
    let nietReservesTotaal      = 0;
    let nietReservesBevestigd   = 0;
    let reservesTotaal          = 0;
    let reservesBevestigd       = 0;
    let reservesIngezetN        = 0;
    let totaalInLoting          = 0;
    const reserveRows = [];   // niet-ingezet — krijgen "Inzetten"-knop
    const ingezetRows = [];   // wel ingezet  — krijgen "Terug"-knop
    for (const item of cat.competitors) {
        const lk = item.license_key;
        const ek = cat.dc_id + '_' + lk;
        const ee = entryEdits[ek] || {};
        const st = ee.entry_status ?? 1;
        const heeftReserveNr = ee.reserve != null;
        const isIngezet      = ee.reserve_ingezet === 1;
        const wasOrigineelReserve = heeftReserveNr || isIngezet;

        if (wasOrigineelReserve) {
            reservesTotaal++;
            const pe = personEdits[lk] || {};
            const baseRow = {
                lk, ek,
                entry_status: st,
                full_name:    pe.full_name    ?? '',
                club_short:   pe.club_short   ?? '',
                category:     pe.category     ?? '',
            };
            if (isIngezet) {
                reservesIngezetN++;
                ingezetRows.push({
                    ...baseRow,
                    // KNSB-volgnummer nodig om terug-actie te doen.
                    // Fallback 1 als knsb_reserve niet bekend — voor anonieme
                    // rijders of legacy-imports.
                    knsb_reserve_nr: ee.knsb_reserve ?? 1,
                });
            } else {
                if (st === 1) reservesBevestigd++;
                reserveRows.push({
                    ...baseRow,
                    reserve_nr: ee.reserve,
                });
            }
        } else {
            nietReservesTotaal++;
            if (st === 1) nietReservesBevestigd++;
        }

        // In-loting telt iedereen die straks in de startlijst belandt — dat
        // zijn alle entries zonder reserve-nummer met status 1 of 5
        // (inclusief ingezette ex-reserves).
        if (!heeftReserveNr && (st === 1 || st === 5)) {
            totaalInLoting++;
        }
    }
    reserveRows.sort((a, b) => a.reserve_nr - b.reserve_nr);
    ingezetRows.sort((a, b) => (a.knsb_reserve_nr ?? 99) - (b.knsb_reserve_nr ?? 99));

    // Capaciteit-cap: het totaal-in-loting mag het max-aantal niet overstijgen.
    // Bron-volgorde:
    //   - cat.max_in_loting (DB-override) → handmatig gezet door operator
    //   - anders: nietReservesTotaal (= aantal niet-reserves uit KNSB-feed,
    //     de auto-berekening die in de meeste gevallen klopt).
    // Reserves mogen alleen ingezet worden ter vervanging van iemand die
    // afgemeld / niet-getekend / niet-bevestigd staat. "Vrij" = aantal lege
    // slots dat nog ingevuld kan worden door een reserve.
    const maxOverride  = cat.max_in_loting;   // null of int
    const maxInLoting  = maxOverride !== null && maxOverride !== undefined
        ? maxOverride : nietReservesTotaal;
    const isOverride   = maxOverride !== null && maxOverride !== undefined;
    const vrijeSlots   = Math.max(0, maxInLoting - totaalInLoting);

    // Toon paneel ook als er geen reserves zijn maar wel ingezetten — operator
    // wil zien dat de telling klopt. Maar dat is randgeval; voor nu: tonen
    // zodra er reserves OF ingezette ex-reserves zijn.
    const toonPaneel = reservesTotaal > 0 || reservesIngezetN > 0;
    const reservePaneelHtml = toonPaneel ? `
        <details class="reserve-paneel" data-dc-id="${escHtml(cat.dc_id)}" open>
            <summary>
                <span class="reserve-paneel-titel">Reserves &amp; deelnemers-telling</span>
                <span class="reserve-paneel-stats">
                    <span class="rp-stat" title="Niet-reserves totaal (alle statussen)">
                        <span class="rp-lbl">Niet-reserves:</span>
                        <strong>${nietReservesTotaal}</strong>
                        <span class="rp-sub">waarvan bevestigd: <strong>${nietReservesBevestigd}</strong></span>
                    </span>
                    <span class="rp-stat" title="Reserves totaal (volgens KNSB-feed)">
                        <span class="rp-lbl">Reserves:</span>
                        <strong>${reservesTotaal}</strong>
                        <span class="rp-sub">bevestigd: <strong>${reservesBevestigd}</strong></span>
                        <span class="rp-sub">ingezet: <strong>${reservesIngezetN}</strong></span>
                    </span>
                    <span class="rp-stat rp-stat-totaal" title="Rijders die in de startlijst-loting komen (bevestigde niet-reserves + ingezette reserves).">
                        <span class="rp-lbl">In loting:</span>
                        <strong>${totaalInLoting}</strong>
                        <span class="rp-sub">van max
                            <strong class="rp-max ${isOverride ? 'rp-max-override' : ''}"
                                    title="${isOverride
                                        ? 'Handmatig ingesteld (afwijkend van KNSB-feed: ' + nietReservesTotaal + '). Klik op ✏ om aan te passen.'
                                        : 'Auto-berekend uit KNSB-feed. Klik op ✏ om handmatig te overschrijven.'}">${maxInLoting}</strong>
                            <button type="button" class="rp-max-edit"
                                    data-dc-id="${escHtml(cat.dc_id)}"
                                    data-cur-max="${escHtml(String(maxInLoting))}"
                                    data-auto-max="${escHtml(String(nietReservesTotaal))}"
                                    data-is-override="${isOverride ? '1' : '0'}"
                                    title="Max-in-loting aanpassen">✏</button>
                        </span>
                        <span class="rp-sub rp-vrij ${vrijeSlots === 0 ? 'rp-vrij-vol' : ''}"
                              title="Lege slots die nog door een reserve ingevuld kunnen worden">
                            vrij: <strong>${vrijeSlots}</strong>
                        </span>
                    </span>
                </span>
            </summary>
            ${reserveRows.length === 0 ? `
            <div class="reserve-paneel-leeg">
                Alle reserves zijn ingezet — geen acties beschikbaar.
            </div>` : `
            <table class="reserve-paneel-tabel">
                <thead><tr>
                    <th class="th-reserve-nr">#</th>
                    <th class="th-reserve-naam">Naam</th>
                    <th class="th-reserve-club">Club</th>
                    <th class="th-reserve-cat">Cat.</th>
                    <th class="th-reserve-status">Status</th>
                    <th class="th-reserve-actie"></th>
                </tr></thead>
                <tbody>
                ${reserveRows.map(r => {
                    // Voorwaarden voor inzet:
                    //   1. status = 1 (getekend aan de balie)
                    //   2. er moet nog een vrije plek zijn (capaciteit-cap)
                    const statusOk   = r.entry_status === 1;
                    const slotOk     = vrijeSlots > 0;
                    const kanInzet   = statusOk && slotOk;
                    const titel      = !statusOk
                        ? 'Inzetten kan alleen als status = Bevestigd (getekend aan de balie)'
                        : !slotOk
                            ? 'Geen vrije plekken meer — alle slots zijn al gevuld (max bereikt)'
                            : 'Reserve inzetten: status → Bevestigd bij org., reserve-nummer vervalt';
                    return `
                    <tr data-lk="${escHtml(r.lk)}" data-reserve-nr="${r.reserve_nr}">
                        <td class="td-reserve-nr">R${r.reserve_nr}</td>
                        <td class="td-reserve-naam">${escHtml(r.full_name)}</td>
                        <td class="td-reserve-club">${escHtml(r.club_short)}</td>
                        <td class="td-reserve-cat">${escHtml(r.category)}</td>
                        <td class="td-reserve-status">
                            <span class="status-badge ${STATUS_CSS[r.entry_status]}">
                                ${STATUS_LABELS[r.entry_status]}
                            </span>
                        </td>
                        <td class="td-reserve-actie">
                            <button class="btn-reserve-inzet"
                                    data-lk="${escHtml(r.lk)}"
                                    data-reserve-nr="${r.reserve_nr}"
                                    ${kanInzet ? '' : 'disabled'}
                                    title="${escHtml(titel)}">Inzetten</button>
                        </td>
                    </tr>`;
                }).join('')}
                </tbody>
            </table>`}
            ${ingezetRows.length ? `
            <div class="reserve-paneel-subkop">Reeds ingezet</div>
            <table class="reserve-paneel-tabel reserve-paneel-tabel-ingezet">
                <thead><tr>
                    <th class="th-reserve-nr">orig.</th>
                    <th class="th-reserve-naam">Naam</th>
                    <th class="th-reserve-club">Club</th>
                    <th class="th-reserve-cat">Cat.</th>
                    <th class="th-reserve-status">Status</th>
                    <th class="th-reserve-actie"></th>
                </tr></thead>
                <tbody>
                ${ingezetRows.map(r => `
                    <tr data-lk="${escHtml(r.lk)}">
                        <td class="td-reserve-nr">R${r.knsb_reserve_nr}</td>
                        <td class="td-reserve-naam">${escHtml(r.full_name)}</td>
                        <td class="td-reserve-club">${escHtml(r.club_short)}</td>
                        <td class="td-reserve-cat">${escHtml(r.category)}</td>
                        <td class="td-reserve-status">
                            <span class="status-badge ${STATUS_CSS[r.entry_status]}">
                                ${STATUS_LABELS[r.entry_status]}
                            </span>
                        </td>
                        <td class="td-reserve-actie">
                            <button class="btn-reserve-terug"
                                    data-lk="${escHtml(r.lk)}"
                                    data-reserve-nr="${r.knsb_reserve_nr}"
                                    title="Inzet terugdraaien: rijder gaat weer terug naar reserve R${r.knsb_reserve_nr}">Terug</button>
                        </td>
                    </tr>`).join('')}
                </tbody>
            </table>` : ''}
        </details>` : '';

    let html = `
    <div class="vergelijk-wrap">
    ${reservePaneelHtml}
    <table class="vergelijk-tabel">
    <thead><tr>
        <th class="th-sn">Start#</th>
        <th class="th-naam">Naam</th>
        <th class="th-club">Club</th>
        <th class="th-tp-sel">Transponder</th>
        <th class="th-status">Status</th>
        <th class="th-badges"></th>
    </tr></thead>
    <tbody>`;

    for (const item of cat.competitors) {
        const lk    = item.license_key;
        const ek    = cat.dc_id + '_' + lk;
        const pe    = personEdits[lk]  || {};
        const ee    = entryEdits[ek]   || {};
        const st    = ee.entry_status  ?? 1;
        const sn    = pe.start_number  ?? '';
        const isNew = item.is_new;
        const diffs = item.diffs || [];

        let rowClass = '';
        if      (st >= 2 && st !== 5) rowClass = 'row-withdrawn';   // 5=Bevestigd bij org. → actief
        else if (isNew)               rowClass = 'row-new';
        else if (diffs.length)        rowClass = 'row-diff';
        if (gewijzigdeRijen.has(lk)) rowClass += ' row-modified';

        const isGuest = sn !== '' && sn !== null && Number(sn) >= 1000;

        const snDiff   = diffs.includes('start_number');
        const naamDiff = diffs.includes('full_name');
        const knsbSn   = item.knsb.start_number  ?? '';
        const knsbNaam = item.knsb.full_name      ?? '';
        const extras   = pe.transponders_extra    || [];
        const actief   = pe.transponder_actief;   // null = geen, string = code

        const reserveBadge = ee.reserve
            ? `<span class="badge-reserve">R${ee.reserve}</span>`
            : '';

        const isAnoniem = !!item.is_anoniem;

        let badgesHtml = '';
        if (isNew)         badgesHtml += '<span class="badge-nieuw">NIEUW</span>';
        if (isAnoniem)     badgesHtml += '<span class="badge-anoniem" title="Anonieme rijder — licentienummer onbekend">ANON</span>';
        if (diffs.length) {
            // Tooltip toont alle verschillen, maar maakt onderscheid tussen
            // actie-diffs (importeer-actie nodig) en info-diffs (bewuste
            // DB-correctie — operator hoeft niets te doen).
            const labels = { start_number: 'startnummer', full_name: 'naam',
                             status: 'status', reserve: 'reserve-volgnummer' };
            const actie = diffs.filter(d => IMPORT_DIFF_VELDEN.has(d))
                               .map(d => labels[d] || d);
            const info  = diffs.filter(d => !IMPORT_DIFF_VELDEN.has(d))
                               .map(d => labels[d] || d);
            const parts = [];
            if (actie.length) parts.push(`Importeren overneemt: ${actie.join(', ')}`);
            if (info.length)  parts.push(`Alleen ter info (DB blijft): ${info.join(', ')}`);
            const tooltip = `KNSB-feed wijkt af van database.\n${parts.join('\n')}`;
            badgesHtml += `<span class="badge-diff" title="${escHtml(tooltip)}">!</span>`;
        }

        // Persoonlijk-melden info-badge: zelfde redenen als op de tekenlijst,
        // zodat de persoon achter de tekenbalie in 1 oogopslag ziet waarom
        // deze rijder zich persoonlijk moet melden.
        const meldingen = _berekenMeldingen(st, actief, sn);
        if (meldingen.length) {
            const tooltip = 'Graag persoonlijk melden:\n• ' + meldingen.join('\n• ');
            badgesHtml += `<span class="badge-meld" title="${escHtml(tooltip)}">ⓘ</span>`;
        }

        html += `
        <tr class="${rowClass}" data-lk="${escHtml(lk)}" data-dc="${escHtml(cat.dc_id)}">
            <td class="td-sn ${isGuest ? 'guest-nr' : ''} ${snDiff ? 'cell-diff' : ''}">
                <input type="number" class="inp inp-sn" value="${escHtml(String(sn))}"
                       data-field="start_number" data-lk="${escHtml(lk)}">
                ${snDiff ? `<div class="knsb-hint">KNSB: ${escHtml(String(knsbSn))}</div>` : ''}
            </td>
            <td class="td-naam ${naamDiff ? 'cell-diff' : ''}">
                <input type="text" class="inp inp-naam" value="${escHtml(pe.full_name ?? '')}"
                       data-field="full_name" data-lk="${escHtml(lk)}"
                       ${isAnoniem ? 'placeholder="Vul echte naam in voor startlijst"' : ''}>
                ${naamDiff ? `<div class="knsb-hint">KNSB: ${escHtml(knsbNaam)}</div>` : ''}
            </td>
            <td class="td-club">${escHtml(pe.club_full ?? '')}</td>
            <td class="td-tp-sel">
                ${maakTpDropdownHtml(lk, pe.transponder1, pe.transponder2, extras, actief, sn)}
            </td>
            <td class="td-status">
                <span class="status-badge ${STATUS_CSS[st]}"
                      data-lk="${escHtml(lk)}" data-dc="${escHtml(cat.dc_id)}">
                    ${STATUS_LABELS[st]}
                </span>
                ${reserveBadge}
            </td>
            <td class="td-badges">${badgesHtml}</td>
        </tr>`;
    }

    html += `</tbody></table>
    <button class="btn-deelnemer-add" data-dc-id="${escHtml(cat.dc_id)}">+ Deelnemer toevoegen</button>
    </div>`;
    content.innerHTML = html;

    // ── Event listeners ──

    // Reserve-paneel: Inzetten-knop. Direct API-call (geen pending edit-state):
    // operator klikt → DB-mutatie → lokale state updaten → herrender tabel zodat
    // de rijder uit het reserve-paneel verdwijnt en in de reguliere tabel met
    // status 'Bevestigd bij org.' verschijnt.
    // Reserve-paneel: Terug-knop. Draait een inzet terug — rijder gaat weer
    // in het reserves-overzicht staan met z'n originele KNSB-volgnummer.
    // Status blijft staan zoals 'ie was (= 5 'Bevestigd bij org.'), tenzij
    // operator handmatig naar lagere status zet. Reserves met status=5
    // tellen niet als 'bevestigd' in de paneel-header (alleen status=1 telt).
    // ── Max-in-loting handmatig aanpassen (inline edit) ──────────────────────
    // Klik op ✏-knop → de '<strong>'-waarde wordt vervangen door een input
    // die ter plekke editbaar is. Enter / blur slaat op. Escape annuleert.
    // Leeg laten = terug naar auto-berekening (NULL in DB).
    // Geen browser-prompt — past beter bij de rest van de UI.
    content.querySelectorAll('.rp-max-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            const dcId       = btn.dataset.dcId;
            const autoMax    = btn.dataset.autoMax;
            const isOverride = btn.dataset.isOverride === '1';
            const wrap       = btn.parentElement;
            const strong     = wrap.querySelector('.rp-max');
            if (!strong) return;

            const huidig = strong.textContent.trim();
            const inp    = document.createElement('input');
            inp.type        = 'number';
            inp.className   = 'rp-max-input';
            inp.min         = '0';
            inp.max         = '200';
            // Leeg laten als huidige waarde = auto (geen override). Operator
            // ziet de placeholder met het auto-getal en kan ofwel een nieuwe
            // waarde intypen, ofwel leeg-laten om bij auto te blijven.
            inp.value       = isOverride ? huidig : '';
            inp.placeholder = `auto (${autoMax})`;
            inp.title       = 'Enter = opslaan · Esc = annuleren · leeg = terug naar auto';

            // Replace strong + btn met de input. Bewaar refs zodat we ze
            // kunnen herstellen bij Escape of bij een API-fout.
            strong.style.display = 'none';
            btn.style.display    = 'none';
            wrap.insertBefore(inp, strong);
            inp.focus();
            inp.select();

            let _bezig = false;   // guard tegen dubbel-fire (blur na Enter)

            const annuleer = () => {
                if (_bezig) return;
                _bezig = true;
                inp.remove();
                strong.style.display = '';
                btn.style.display    = '';
            };

            const opslaan = async () => {
                if (_bezig) return;
                _bezig = true;

                // Normaliseer invoer
                let payload;
                const trimmed = inp.value.trim();
                if (trimmed === '') {
                    payload = null;   // terug naar auto
                } else {
                    const n = parseInt(trimmed, 10);
                    if (isNaN(n) || n < 0 || n > 200) {
                        toonBevestigDialog(
                            'Vul een geheel getal tussen 0 en 200 in, of laat leeg voor auto.',
                            'Ongeldige waarde'
                        );
                        // Niet annuleren — laat de operator opnieuw typen.
                        // Reset _bezig zodat hij gewoon door kan.
                        _bezig = false;
                        inp.focus();
                        inp.select();
                        return;
                    }
                    payload = n;
                }

                try {
                    const res = await fetch('api/dc_max_loting.php', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ dc_id: dcId, max_in_loting: payload }),
                    });
                    const data = await res.json();
                    if (data.error) throw new Error(data.error);

                    // Lokale state updaten + hertekenen
                    const cat = vergelijkData.find(c => c.dc_id === dcId);
                    if (cat) cat.max_in_loting = data.max_in_loting;
                    if (activeCat?.dc_id === dcId) toonVergelijkTabel(cat);
                } catch (e) {
                    toonBevestigDialog('Kon max niet opslaan: ' + (e.message || e), 'Fout');
                    _bezig = false;
                    annuleer();
                }
            };

            inp.addEventListener('keydown', (e) => {
                if (e.key === 'Enter')  { e.preventDefault(); opslaan(); }
                if (e.key === 'Escape') { e.preventDefault(); annuleer(); }
            });
            // Blur slaat ook op (klikken buiten input = bevestigen). De
            // _bezig-guard voorkomt dat blur-na-Enter twee keer fires.
            inp.addEventListener('blur', () => opslaan());
        });
    });

    content.querySelectorAll('.btn-reserve-terug').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.disabled) return;
            const lk        = btn.dataset.lk;
            const reserveNr = parseInt(btn.dataset.reserveNr, 10) || 1;
            if (!lk) return;

            const akkoord = await toonBevestigDialog(
                `Inzet van deze rijder terugdraaien? Hij/zij wordt weer R${reserveNr} `
                + `en valt uit de startlijst-loting.`,
                'Inzet terugdraaien', 'Terugdraaien', 'Annuleren'
            );
            if (!akkoord) return;

            btn.disabled = true;
            btn.textContent = 'Bezig…';
            try {
                const res = await fetch('api/reserve_inzet.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        competition_id: huidigCompId,
                        dc_id:          cat.dc_id,
                        person_license: lk,
                        actie:          'terug',
                        reserve_nr:     reserveNr,
                    }),
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || `HTTP ${res.status}`);
                }
                // Lokale state: rijder is weer reserve.
                const ek = cat.dc_id + '_' + lk;
                if (entryEdits[ek]) {
                    entryEdits[ek].reserve         = reserveNr;
                    entryEdits[ek].reserve_ingezet = 0;
                    // status blijft staan
                }
                const comp = cat.competitors.find(c => c.license_key === lk);
                if (comp) {
                    comp.reserve         = reserveNr;
                    comp.reserve_ingezet = 0;
                }
                toonVergelijkTabel(cat);
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Terug';
                await toonBevestigDialog(
                    'Terugdraaien mislukt: ' + e.message, 'Fout', 'OK', null
                );
            }
        });
    });

    content.querySelectorAll('.btn-reserve-inzet').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.disabled) return;
            const lk        = btn.dataset.lk;
            const reserveNr = parseInt(btn.dataset.reserveNr, 10);
            if (!lk) return;

            const akkoord = await toonBevestigDialog(
                `Reserve R${reserveNr} inzetten voor ${escHtml(cat.dc_name)}? `
                + `Status wordt 'Bevestigd bij org.' en de rijder doet vanaf nu mee in de loting.`,
                'Reserve inzetten', 'Inzetten', 'Annuleren'
            );
            if (!akkoord) return;

            btn.disabled = true;
            btn.textContent = 'Bezig…';
            try {
                const res = await fetch('api/reserve_inzet.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        competition_id: huidigCompId,
                        dc_id:          cat.dc_id,
                        person_license: lk,
                        actie:          'inzet',
                    }),
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || `HTTP ${res.status}`);
                }
                // Lokale state bijwerken zodat herrender direct klopt zonder
                // volledige vergelijkData-refetch.
                const ek = cat.dc_id + '_' + lk;
                if (entryEdits[ek]) {
                    entryEdits[ek].reserve         = null;
                    entryEdits[ek].entry_status    = 5;
                    entryEdits[ek].reserve_ingezet = 1;
                }
                // Ook vergelijkData.competitor's reserve-veld + entry_status updaten
                // zodat herrender op basis van de cat-data correct werkt.
                const comp = cat.competitors.find(c => c.license_key === lk);
                if (comp) {
                    comp.reserve         = null;
                    comp.entry_status    = 5;
                    comp.reserve_ingezet = 1;
                }
                toonVergelijkTabel(cat);
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Inzetten';
                await toonBevestigDialog(
                    'Inzetten mislukt: ' + e.message, 'Fout', 'OK', null, { toonAnnuleer: false }
                );
            }
        });
    });

    content.querySelectorAll('.inp[data-field]').forEach(inp => {
        inp.addEventListener('change', () => {
            const field = inp.dataset.field;
            const lk    = inp.dataset.lk;
            if (!lk || !field) return;
            if (!personEdits[lk]) personEdits[lk] = {};
            personEdits[lk][field] = (field === 'start_number')
                ? (parseInt(inp.value) || null)
                : (inp.value.trim() || null);
            markeerGewijzigd(inp.closest('tr'));
            // Startnummer beïnvloedt de gast-melding
            if (field === 'start_number') _hertekenMeldBadge(inp.closest('tr'));
        });
    });

    // Transponder dropdown: selectie opslaan
    content.querySelectorAll('.tp-sel-drop').forEach(sel => {
        sel.addEventListener('change', async () => {
            const lk           = sel.dataset.lk;
            const oudeWaarde   = sel.dataset.prev || null;
            const nieuweWaarde = sel.value || null;

            if (!personEdits[lk]) personEdits[lk] = {};
            personEdits[lk].transponder_actief = nieuweWaarde;
            markeerGewijzigd(sel.closest('tr'));

            // Belangrijk: als we een org-transponder aan deze rijder toewijzen,
            // moeten we 'm weghalen bij een eventuele VORIGE eigenaar in
            // personEdits. initEdits vult personEdits[lk].transponder_actief
            // vanuit DB bij load, dus dat is de gezaghebbende bron.
            // Zonder deze wissing stuurt collectImportData de transponder voor
            // BEIDE rijders door en overschrijft de laatste de eerste op de server.
            if (nieuweWaarde) {
                const isOrgTp = _orgTransponders.some(ot => ot.transponder_code === nieuweWaarde);
                if (isOrgTp) {
                    for (const [otherLk, otherPe] of Object.entries(personEdits || {})) {
                        if (otherLk === lk) continue;
                        if (otherPe?.transponder_actief !== nieuweWaarde) continue;

                        // Wis bij de vorige eigenaar
                        personEdits[otherLk].transponder_actief = null;
                        // Update eventueel zichtbare dropdown in DOM
                        content.querySelectorAll(
                            `.tp-sel-drop[data-lk="${CSS.escape(otherLk)}"]`
                        ).forEach(os => {
                            if (os.value === nieuweWaarde) {
                                os.value = '';
                                os.dataset.prev = '';
                            }
                        });
                        // Markeer die rij als gewijzigd
                        content.querySelectorAll(
                            `tr[data-lk="${CSS.escape(otherLk)}"]`
                        ).forEach(tr => markeerGewijzigd(tr));
                    }
                }
            }

            // Haal startnr + naam uit de rij (voor lokale _orgTransponders-sync)
            const row     = sel.closest('tr');
            const snrInp  = row?.querySelector('input[data-field="start_number"]');
            const naamInp = row?.querySelector('input[data-field="full_name"]');
            const startnr = snrInp  ? (parseInt(snrInp.value) || null) : null;
            const naam    = naamInp ? naamInp.value : '';

            // Oude org-transponder (indien aanwezig) weer vrijgeven in de lokale cache
            if (oudeWaarde && oudeWaarde !== nieuweWaarde) {
                const oudOt = _orgTransponders.find(ot => ot.transponder_code === oudeWaarde);
                if (oudOt) {
                    oudOt.toegewezen_snr  = null;
                    oudOt.toegewezen_naam = null;
                }
            }

            // Nieuwe org-transponder toewijzen in de lokale cache + betaald-vraag
            const orgTp = nieuweWaarde
                ? _orgTransponders.find(ot => ot.transponder_code === nieuweWaarde)
                : null;
            if (orgTp && nieuweWaarde !== oudeWaarde) {
                orgTp.toegewezen_snr  = startnr;
                orgTp.toegewezen_naam = naam;

                const betaald = await toonBevestigDialog(
                    `Transponder #${orgTp.intern_nummer} toewijzen.\nIs de borg/huur betaald?`,
                    'Transponder betaald?',
                    'Ja, betaald', 'Nee'
                );
                orgTp.betaald = betaald ? 1 : 0;
                personEdits[lk].tp_betaald = betaald ? 1 : 0;
            }

            // Onthoud de nieuwe waarde voor de volgende wijziging
            sel.dataset.prev = nieuweWaarde || '';

            // Meld-badge van deze rij opnieuw berekenen (transponder/betaald veranderd)
            _hertekenMeldBadge(sel.closest('tr'));

            // Ververs org-transponder opties in alle andere dropdowns in deze tab
            if (_orgTransponders.length) _vervrisOrgTpOpties(content);
        });
    });

    // Transponder '+' knop: inline invoer
    content.querySelectorAll('.tp-add-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            voegTpToe(btn.dataset.lk, btn, content);
        });
    });

    // Org transponder opzoek: typ intern nummer → selecteer in dropdown
    content.querySelectorAll('.tp-org-nr').forEach(inp => {
        inp.addEventListener('change', () => {
            const nr = inp.value.trim();
            if (!nr) return;
            const ot = _orgTransponders.find(t => t.intern_nummer === nr);
            if (!ot) { inp.style.borderColor = '#c00'; return; }
            inp.style.borderColor = '';
            const sel = inp.closest('.tp-sel-wrap')?.querySelector('.tp-sel-drop');
            if (sel) {
                sel.value = ot.transponder_code;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            inp.value = '';
        });
    });

    // "+ Deelnemer toevoegen" knop
    content.querySelector('.btn-deelnemer-add')
        ?.addEventListener('click', () => openDeelnemerModal(cat.dc_id));

    content.querySelectorAll('.status-badge').forEach(badge => {
        badge.addEventListener('click', () => {
            const lk   = badge.dataset.lk;
            const dcId = badge.dataset.dc;
            const ek   = dcId + '_' + lk;

            if (!entryEdits[ek]) entryEdits[ek] = {};

            const huidig     = entryEdits[ek].entry_status ?? 1;
            const knsbStatus = entryEdits[ek].knsb_status  ?? huidig;

            // Status 2 (afgemeld bij KNSB) is niet wijzigbaar — alleen KNSB kan dat terugdraaien.
            if (knsbStatus === 2) return;

            // Cyclus afhankelijk van KNSB-status:
            //   KNSB=1 (bevestigd):            1 → 3 → 4 → 1
            //   KNSB=0 / org-toegevoegd (5):   5 → 3 → 4 → 5
            //                                  0 → 5 → 3 → 4 → 5
            // Vanuit 4 terug naar 1 alleen als knsb_status écht bevestigd (1) is;
            // anders altijd terug naar 5 (Bevestigd bij org.) zodat org-status niet verloren gaat.
            let nieuw;
            if      (huidig === 5)                          nieuw = 3;
            else if (huidig === 3)                          nieuw = 4;
            else if (huidig === 4)                          nieuw = (knsbStatus === 1) ? 1 : 5;
            else if (knsbStatus === 0 && huidig === 0)      nieuw = 5;   // niet-bevestigd → Bevestigd bij org.
            else                                            nieuw = 3;   // bevestigd (1) → Afgem. bij org.

            entryEdits[ek].entry_status = nieuw;
            heeftWijzigingen = true;

            badge.className   = 'status-badge ' + STATUS_CSS[nieuw];
            badge.textContent = STATUS_LABELS[nieuw];

            const row = badge.closest('tr');
            if (row) {
                row.classList.remove('row-withdrawn', 'row-new', 'row-diff');
                // Status 5 (Bevestigd bij org.) = actief → geen row-withdrawn
                if (nieuw >= 2 && nieuw !== 5) row.classList.add('row-withdrawn');
                else                           markeerGewijzigd(row);
                _hertekenMeldBadge(row);
            }
            updateImportBtn();
        });
    });

    // Lees-alleen modus: schrijf-elementen disablen na render
    if (_importLeesOnly) {
        toonLeesAlleenBanner(content);
        pasSchrijfLockToe(content);
    }
}

// ── Importeer-knop status ─────────────────────────────────────────────────────

function updateImportBtn() {
    const btn = el('btn-import');
    if (!btn) return;
    if (_importLeesOnly) {
        btn.disabled = true;
        btn.title = 'Geen schrijfrechten voor importeer';
        updateExportBtn();
        return;
    }
    // Zijn er deelnemers die nog niet in de DB staan?
    const heeftNieuwe = vergelijkData.some(cat =>
        cat.competitors.some(c => c.db_entry === null)
    );
    const moetImporteren = !isGeimporteerd || heeftWijzigingen || heeftNieuwe;
    btn.disabled = !moetImporteren;
    btn.title = moetImporteren
        ? (heeftWijzigingen
            ? 'Wijzigingen opslaan in database'
            : heeftNieuwe
                ? 'Nieuwe inschrijvingen opslaan'
                : 'Wedstrijd importeren in database')
        : 'Alles is opgeslagen — geen wijzigingen';
    updateExportBtn();
}

// ── Exporteer-knop status ────────────────────────────────────────────────────
// De export werkt op DB-data, dus blokkeren als er nog onopgeslagen wijzigingen
// of nog-niet-geïmporteerde deelnemers in de UI staan. Anders zou je een CSV
// downloaden die niet matcht met wat de Live-app / startlijsten gebruiken.
function updateExportBtn() {
    const btn = el('btn-export');
    if (!btn) return;
    if (!isGeimporteerd) {
        btn.disabled = true;
        btn.title = 'Importeer de wedstrijd eerst voordat je kunt exporteren';
        return;
    }
    if (heeftWijzigingen) {
        btn.disabled = true;
        btn.title = 'Eerst opslaan via Importeer — anders mismatch tussen export en database';
        return;
    }
    const heeftNieuwe = vergelijkData.some(cat =>
        cat.competitors.some(c => c.db_entry === null)
    );
    if (heeftNieuwe) {
        btn.disabled = true;
        btn.title = 'Er staan nog niet-geïmporteerde deelnemers — eerst Importeer klikken';
        return;
    }
    btn.disabled = false;
    btn.title = 'Deelnemers exporteren als KNSB-CSV';
}

// ── Tekenlijsten afdrukken ────────────────────────────────────────────────────

function groepeerVoorPrint() {
    const usedIds = new Set();
    const groepen = [];

    vergelijkData.forEach(cat => {
        if (usedIds.has(cat.dc_id)) return;
        usedIds.add(cat.dc_id);

        let dcGroup = [cat];
        if (cat.merge_group) {
            vergelijkData.forEach(c => {
                if (!usedIds.has(c.dc_id) && c.merge_group === cat.merge_group) {
                    usedIds.add(c.dc_id);
                    dcGroup.push(c);
                }
            });
        }

        const allComps = [];
        dcGroup.forEach(dc => {
            dc.competitors.forEach(c => {
                const status = c.entry_status ?? 1;
                if (status === 2 || status === 3 || status === 4) return;  // niet op tekenlijst (5=Bevestigd bij org. wél)
                const pe = personEdits[c.license_key] || {};
                // pe.transponder_actief = null betekent EXPLICIET "geen actieve"
                // (operator heeft op "— geen —" gezet of DB zegt slot 0 = null).
                // ?? zou daar door-vallen op de KNSB-default; gebruik 'in' om
                // alleen te fallback'en als de key nog nooit gezet is.
                const tpActief = ('transponder_actief' in pe)
                    ? (pe.transponder_actief ?? '')
                    : (pe.transponder1 ?? c.knsb?.transponder1 ?? '');
                // Check of deze transponder in de org-lijst staat en niet betaald is
                const orgTp = _orgTransponders.find(ot => ot.transponder_code === tpActief);
                const tpBetaald = orgTp ? (parseInt(orgTp.betaald) === 1) : null; // null=geen org-tp
                const tpOrgNr   = orgTp ? String(orgTp.intern_nummer ?? '') : '';
                // Reserve-nummer ophalen uit entryEdits (KNSB-feed levert dit
                // mee bij rijders die als reserve op de deelnemerslijst staan).
                // null/undefined = geen reserve = reguliere rijder.
                const ek = c.license_key ? (dc.dc_id + '_' + c.license_key) : null;
                const reserveNr = ek && entryEdits[ek]
                    ? (entryEdits[ek].reserve ?? null)
                    : null;

                allComps.push({
                    start_number:  pe.start_number      ?? c.knsb?.start_number ?? '',
                    full_name:     pe.full_name          ?? c.knsb?.full_name    ?? '',
                    category:      pe.category           ?? c.knsb?.category     ?? '',
                    transponder:   tpActief,
                    tp_org_nr:     tpOrgNr,
                    entry_status:  status,
                    tp_betaald:    tpBetaald,
                    reserve:       reserveNr,
                });
            });
        });

        // Sortering: reguliere rijders eerst op startnummer, daarna reserves
        // op reserve-nummer (R1, R2, ...). KNSB-PDF doet hetzelfde — voorkomt
        // verwarring tussen vol-toegelaten en reserve-rijders op de tekenlijst.
        const sorteer = arr => arr.sort((a, b) => {
            const aR = a.reserve;
            const bR = b.reserve;
            if (aR && !bR)  return  1;  // a reserve, b niet → a achteraan
            if (!aR && bR)  return -1;
            if (aR && bR)   return aR - bR;
            return (Number(a.start_number) || 9999) - (Number(b.start_number) || 9999);
        });

        const allSplits = {};
        dcGroup.forEach(dc => {
            Object.entries(dc.splits || {}).forEach(([k, v]) => { if (v) allSplits[k] = v; });
        });
        const splitGrps = [...new Set(Object.values(allSplits))].sort();
        const basisNaam = dcGroup.map(d => d.dc_name).filter(Boolean).join(' + ');

        // dcIds: alle DC-ids in deze groep (1 voor losse DCs, meerdere bij
        // merge-groups). Voor multi-day filtering: een groep is op een dag
        // actief als minstens één van z'n dc_ids op die dag een rit heeft.
        const dcIds = dcGroup.map(d => d.dc_id).filter(Boolean);
        if (splitGrps.length) {
            splitGrps.forEach(sg => {
                const sgCats  = Object.keys(allSplits).filter(k => allSplits[k] === sg);
                const sgComps = allComps.filter(c => sgCats.includes(c.category));
                groepen.push({ naam: `${basisNaam} — ${sg}`, deelnemers: sorteer(sgComps), dcIds });
            });
            const restComps = allComps.filter(c => !Object.keys(allSplits).includes(c.category));
            if (restComps.length)
                groepen.push({ naam: `${basisNaam} — overig`, deelnemers: sorteer(restComps), dcIds });
        } else {
            groepen.push({ naam: basisNaam, deelnemers: sorteer(allComps), dcIds });
        }
    });
    return groepen;
}

// ── Compacte cat-groepering voor de boomSaver-tekenlijst ─────────────────────
// Groepeert rijders PER CATEGORIE (HSA, DJB, etc.) ipv per DC. Levert per cat:
//   - Volgorde van DC's (op tijdschema-volgorde — eerst gereden = afstand 1)
//   - Subgroepen op afstand-combinatie:
//       1. Alle afstanden (meest voorkomend, eerst)
//       2. 2-combinaties (1+2, 2+3, 1+3 enz.)
//       3. Solo's (alleen 1, alleen 2, alleen 3)
//     Lege subgroepen worden weggelaten.
// Output: [{ cat, dcs: [{dcId, naam}], subgroepen: [{ label, idxs, rijders }] }]
function _bouwCompactCatClusters() {
    // 1. Per cat: verzamel DC-volgorde + map rijder → set van dcIds waarin ie zit.
    const catMap = new Map();
    for (const dc of vergelijkData) {
        const dcId   = dc.dc_id;
        const dcNaam = dc.distance_naam || dc.dc_name || '';
        for (const c of (dc.competitors || [])) {
            const status = c.entry_status ?? 1;
            if (status === 2 || status === 3 || status === 4) continue; // niet op tekenlijst
            const pe  = personEdits[c.license_key] || {};
            const cat = pe.category ?? c.knsb?.category;
            if (!cat) continue;
            // Sleutel: bij KNSB-rijder = license_key; bij handmatig zonder licentie = start_number
            const lic = c.license_key || ('snr_' + (pe.start_number ?? c.knsb?.start_number ?? ''));
            if (!catMap.has(cat)) catMap.set(cat, { dcOrder: [], rijders: new Map() });
            const ce = catMap.get(cat);
            if (!ce.dcOrder.find(d => d.dcId === dcId)) ce.dcOrder.push({ dcId, naam: dcNaam });
            if (!ce.rijders.has(lic)) {
                // Bouw rijder-record (analoog aan groepeerVoorPrint).
                const tpActief = ('transponder_actief' in pe)
                    ? (pe.transponder_actief ?? '')
                    : (pe.transponder1 ?? c.knsb?.transponder1 ?? '');
                const orgTp    = _orgTransponders.find(ot => ot.transponder_code === tpActief);
                const tpBetaald = orgTp ? (parseInt(orgTp.betaald) === 1) : null;
                const tpOrgNr   = orgTp ? String(orgTp.intern_nummer ?? '') : '';
                const ek = c.license_key ? (dcId + '_' + c.license_key) : null;
                const reserveNr = ek && entryEdits[ek] ? (entryEdits[ek].reserve ?? null) : null;
                ce.rijders.set(lic, {
                    rijderData: {
                        start_number: pe.start_number ?? c.knsb?.start_number ?? '',
                        full_name:    pe.full_name    ?? c.knsb?.full_name    ?? '',
                        category:     cat,
                        transponder:  tpActief,
                        tp_org_nr:    tpOrgNr,
                        entry_status: status,
                        tp_betaald:   tpBetaald,
                        reserve:      reserveNr,
                    },
                    inDcIds: new Set(),
                });
            }
            ce.rijders.get(lic).inDcIds.add(dcId);
        }
    }

    // 2. Sorteer DC's binnen elke cat op tijdschema-volgorde (eerst gereden = 1).
    const ritten = (typeof huidigTijdschema !== 'undefined' && huidigTijdschema?.ritten) || [];
    const dcEersteVolg = new Map();
    for (const r of ritten) {
        const v = parseInt(r.volgorde) || 0;
        if (!dcEersteVolg.has(r.dc_id) || v < dcEersteVolg.get(r.dc_id)) {
            dcEersteVolg.set(r.dc_id, v);
        }
    }
    for (const ce of catMap.values()) {
        ce.dcOrder.sort((a, b) =>
            (dcEersteVolg.get(a.dcId) ?? 9999) - (dcEersteVolg.get(b.dcId) ?? 9999));
    }

    // 3. Per cat → groepeer op mask (welke dc-indexen) en sorteer subgroepen.
    const clusters = [];
    for (const [cat, ce] of catMap) {
        const dcIdToIdx = new Map(ce.dcOrder.map((d, i) => [d.dcId, i]));
        const subMap   = new Map(); // mask-string → [rijders]
        for (const r of ce.rijders.values()) {
            const idxs = [...r.inDcIds].map(id => dcIdToIdx.get(id))
                .filter(i => i !== undefined).sort((a, b) => a - b);
            const mask = idxs.join(',');
            if (!subMap.has(mask)) subMap.set(mask, []);
            subMap.get(mask).push({ ...r.rijderData, _idxs: idxs });
        }
        // Sort masks: grootste eerst (alle-3 bovenaan), dan lexicografisch op idx.
        const masks = [...subMap.keys()].sort((a, b) => {
            const aa = a.split(',').map(Number);
            const bb = b.split(',').map(Number);
            if (aa.length !== bb.length) return bb.length - aa.length;
            for (let i = 0; i < aa.length; i++) {
                if (aa[i] !== bb[i]) return aa[i] - bb[i];
            }
            return 0;
        });
        const subgroepen = masks.map(mask => {
            const idxs    = mask.split(',').map(Number);
            const rijders = subMap.get(mask);
            // Sortering binnen subgroep: reguliere op startnr, reserves achteraan.
            rijders.sort((a, b) => {
                const aR = a.reserve, bR = b.reserve;
                if (aR && !bR) return  1;
                if (!aR && bR) return -1;
                if (aR && bR)  return aR - bR;
                return (Number(a.start_number) || 9999) - (Number(b.start_number) || 9999);
            });
            // Label wordt in de render-fase opgebouwd via T() — daar weten we
            // de taal en het korte afstand-label.
            return { idxs, rijders };
        });
        clusters.push({ cat, dcs: ce.dcOrder, subgroepen });
    }

    // Sorteer clusters op logische cat-volgorde:
    //   leeftijdsgroep (pupillen → kinderen → jeugd → senioren → masters)
    //   ↳ binnen leeftijd: dames vóór heren
    //   ↳ binnen geslacht: jongste sub-code eerst (DP4 > DP3 > DP1, DJB > DJA).
    // Cat-codes hebben vorm "DP4", "DJB", "HJA", "Dsenioren" etc.
    //   pos 0 = D/H (geslacht), pos 1 = P/K/J/S/M (leeftijdsgroep),
    //   pos 2+ = subcode. Onbekende leeftijdsgroepen sorteren achteraan.
    const _catSortKey = cat => {
        const c = String(cat || '').toUpperCase();
        const lgMap = { P: 0, K: 1, J: 2, S: 3, M: 4 };
        return {
            lg:   lgMap[c.charAt(1)] ?? 9,
            gesl: c.charAt(0) === 'D' ? 0 : 1,
            sub:  c.slice(2),
        };
    };
    clusters.sort((a, b) => {
        const ka = _catSortKey(a.cat), kb = _catSortKey(b.cat);
        if (ka.lg   !== kb.lg)   return ka.lg   - kb.lg;
        if (ka.gesl !== kb.gesl) return ka.gesl - kb.gesl;
        // Sub-code descending: 4 vóór 3, B vóór A (jongste eerst).
        if (ka.sub < kb.sub) return  1;
        if (ka.sub > kb.sub) return -1;
        return 0;
    });
    return clusters;
}

// Body-builder: levert alleen de HTML-inhoud + css-links zonder een eigen
// window te openen. Gebruikt door Print-Center om meerdere prints in één
// venster te combineren. Returns: { bodyHtml, cssLinks, title } of null.
function bouwTekenlijstenBody(opts = {}) {
    if (!vergelijkData?.length || !huidigComp) return null;
    return _bouwTekenlijstenInternal(opts);
}

// Interne body-bouwer (het oorspronkelijke werk van printTekenlijsten).
// Wordt aangeroepen door `bouwTekenlijstenBody()` hierboven, dat weer door
// Print-Center wordt gebruikt. Er is geen directe print-knop meer in de UI.
// Returns { bodyHtml, cssLinks, title } voor zowel directe print als combined.
//
// `opts.boomSaver` (default false): wanneer true worden meerdere kleine
// categorieën op één pagina gepakt (greedy first-fit). De wedstrijd-header
// verschijnt alleen op pagina 1; vervolgcategorieën op dezelfde pagina krijgen
// een compacte titel. Categorieën die niet op één pagina passen worden via
// dezelfde chunk-logica als zonder boom-saver opgeknipt.
function _bouwTekenlijstenInternal(opts = {}) {
    const boomSaver = !!opts.boomSaver;
    // i18n-helper voor Print-Center taalkeuze (NL/EN). Fallback = identity.
    const T    = window._pcT    || (k => k);
    const LANG = (window._pcLang && window._pcLang()) || 'nl';
    const LOC  = LANG === 'en' ? 'en-GB' : 'nl-NL';
    // Multi-day filter: alleen DCs die op deze dag een rit hebben.
    // 0 of niet-opgegeven = alle dagen (default-gedrag).
    const dagFilter = parseInt(opts.dagFilter) || 0;
    let groepen = groepeerVoorPrint();
    if (dagFilter > 0 && typeof _tsBouwDcDagMap === 'function') {
        const dcDagMap = _tsBouwDcDagMap(typeof huidigTijdschema !== 'undefined' ? huidigTijdschema : null);
        groepen = groepen.filter(g =>
            (g.dcIds || []).some(id => dcDagMap.get(id) === dagFilter)
        );
        if (!groepen.length) return null; // geen DCs op deze dag → niets te printen
    }
    const compNaam = escHtml(huidigComp.name || huidigComp.title || '');
    // Locale-aware wedstrijddatum (formatDatum is hardcoded nl-NL).
    const _datumStr = huidigComp.starts
        ? new Date(huidigComp.starts).toLocaleDateString(LOC,
            { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
        : '';
    const compMeta = escHtml([_datumStr, getLocatie(huidigComp)].filter(Boolean).join(' · '));
    const _stand   = standDatum || dbStandDatum || '';
    const standTxt = _stand ? T('algemeen.stand_op', { datum: _stand }) : '';

    // ── Paginaberekening ──────────────────────────────────────────────────────
    // A4 landscape, 8mm marge boven/onder → 194mm bruikbare hoogte
    // Rijhoogte: tp-box is 6mm + td-padding 0.6mm + border + regelafstand ≈ 8mm
    // Sponsorfooter ≈ 18mm (alleen op de laatste pagina van een groep)
    //
    // Pagina 1 (met volledige header):
    //   overhead: logo 25mm + scheiding 2mm + groepnaam 6mm + thead 6mm + padding 2mm + marges 9mm ≈ 50mm
    //   → 18 rijen normaal, 15 rijen als er footer bij moet
    //
    // Vervolgpagina's (zonder header, alleen groepnaam + tabel):
    //   overhead: groepnaam 8mm + thead 6mm + padding 2mm + marges 4mm ≈ 20mm
    //   → 21 rijen normaal, 18 rijen als er footer bij moet (alleen laatste pag. van groep)
    const PAGINA_H        = 194;
    const RIJ_H           = 8;
    const FOOTER_H        = 18;
    const OVERHEAD_EERSTE = 50;
    const OVERHEAD_VERVOLG = 20;
    const MAX_EERSTE       = Math.floor((PAGINA_H - OVERHEAD_EERSTE)  / RIJ_H); // 18
    const MAX_VERVOLG      = Math.floor((PAGINA_H - OVERHEAD_VERVOLG) / RIJ_H); // 21
    const MAX_EERSTE_LAST  = Math.floor((PAGINA_H - OVERHEAD_EERSTE  - FOOTER_H) / RIJ_H); // 15
    const MAX_VERVOLG_LAST = Math.floor((PAGINA_H - OVERHEAD_VERVOLG - FOOTER_H) / RIJ_H); // 22→18 rij

    // Verdeelt 'totaal' rijen over pagina's zodat:
    //  • pagina 1 ≤ MAX_EERSTE rijen
    //  • vervolgpagina's ≤ MAX_VERVOLG rijen
    //  • de laatste pagina ≤ MAX_*_LAST rijen (ruimte voor footer)
    //  • de laatste pagina altijd ≥ 1 rij
    function berekenChunks(totaal, heeftFooter) {
        if (totaal === 0) return [];
        const maxEerste  = heeftFooter && totaal <= MAX_EERSTE_LAST  ? MAX_EERSTE_LAST  : MAX_EERSTE;
        const maxVervolg = heeftFooter ? MAX_VERVOLG_LAST : MAX_VERVOLG;

        // Eerste pagina
        if (totaal <= maxEerste) return [totaal];

        const chunks = [maxEerste];
        let rest = totaal - maxEerste;

        while (rest > maxVervolg) {
            const neem = Math.min(MAX_VERVOLG, rest - 1); // laat altijd ≥1 over voor laatste pagina
            chunks.push(neem);
            rest -= neem;
        }
        chunks.push(rest);
        return chunks;
    }

    // Org-logo header + sponsors footer + baan-logo (gedeelde helper)
    const { orgLogoHtml, baanLogoHtml, footerHtml } = bouwOrgHeaderFooter(escHtml);

    const thead = `<thead><tr>
                    <th class="td-nr">#</th>
                    <th class="td-sn">${escHtml(T('tekenlijst.col_startnr'))}</th>
                    <th class="td-naam">${escHtml(T('algemeen.naam'))}</th>
                    <th class="td-cat">${escHtml(T('algemeen.categorie'))}</th>
                    <th class="td-tp">${escHtml(T('algemeen.transponder'))}</th>
                    <th class="td-tp-cor">${escHtml(T('tekenlijst.col_correctie'))}</th>
                    <th class="td-hand">${escHtml(T('algemeen.handtekening'))}</th>
                </tr></thead>`;

    // ── Helper: render rijen voor één stuk-deelnemers ─────────────────────────
    const renderRijen = (chunk, chunkStart) => chunk.map((d, i) => {
        const sn        = Number(d.start_number);
        const isReserve = d.reserve != null && d.reserve > 0;
        const meldingen = [];
        if (d.entry_status === 0)   meldingen.push(T('tekenlijst.melding'));
        if (!d.transponder)         meldingen.push(T('tekenlijst.geen_tp'));
        if (sn >= 1000)             meldingen.push(T('tekenlijst.startnr_n', { nr: sn }));
        if (d.tp_betaald === false) meldingen.push(T('tekenlijst.tp_niet_betaald'));

        const handCel = meldingen.length
            ? `<div class="meld-attentie">
                   <span class="meld-uitroep">⚠️</span>
                   <span class="meld-tekst">${escHtml(T('tekenlijst.persoonlijk_melden'))}</span>
                   <span class="meld-uitroep">⚠️</span>
               </div>`
            : '';

        // Org-internummer (#EJ43) vet rood zodat de jury direct ziet dat
        // dit een transponder van de organisatie is (in-/uit-name). De
        // transponder-code zelf in normale tekst ernaast.
        const tpHtml = d.tp_org_nr
            ? `<span class="tp-orgnr">#${escHtml(String(d.tp_org_nr))}</span> ${escHtml(d.transponder ?? '')}`
            : escHtml(String(d.transponder ?? ''));

        // Reserve-rijders: vervang volgnummer door 'R1' / 'R2' / etc.
        // Klasse 'rij-reserve' voor lichte visuele afwijking. Startnummer-kolom
        // blijft het echte startnummer tonen (jury moet rugnummer kunnen
        // identificeren als reserve alsnog mee mag rijden).
        const numCel  = isReserve ? `R${d.reserve}` : String(chunkStart + i + 1);
        const rowCls  = isReserve ? ' rij-reserve' : '';

        return `<tr class="${rowCls.trim()}">
            <td class="td-nr">${escHtml(numCel)}</td>
            <td class="td-sn">${escHtml(String(d.start_number))}</td>
            <td class="td-naam">${escHtml(d.full_name)}</td>
            <td class="td-cat">${escHtml(d.category)}</td>
            <td class="td-tp">${tpHtml}</td>
            <td class="td-tp-cor"><div class="tp-boxes"><span class="tp-box"></span><span class="tp-box"></span><span class="tp-sep">-</span><span class="tp-box"></span><span class="tp-box"></span><span class="tp-box"></span><span class="tp-box"></span><span class="tp-box"></span></div></td>
            <td class="td-hand">${handCel}</td>
        </tr>`;
    }).join('');

    // ── Helper: full pagina-header (logo + comp-info) — alleen pagina 1 ──────
    // Layout: [comp-info links] [baan-logo + org-logo rechts]
    // De categorie-titel-regel (groepNaamRegelHtml) wordt NA het flex-block
    // gerenderd zodat 'ie full-width onder de header staat en netjes
    // uitgelijnd is met de tabel-eerste-kolom.
    const compHeaderBlok = (groepNaamRegelHtml) => `
        <div style="display:flex;flex-wrap:nowrap;align-items:flex-start;justify-content:space-between;gap:4mm;min-height:20mm;">
            <div style="flex:1;min-width:0;">
                <div class="hdr-comp">${compNaam}</div>
                <div class="hdr-meta">${compMeta}</div>
                ${standTxt ? `<div class="hdr-stand">${escHtml(standTxt)}</div>` : ''}
            </div>
            ${baanLogoHtml ? `<div style="flex-shrink:0;display:flex;align-items:flex-start;">${baanLogoHtml}</div>` : ''}
            <div style="flex-shrink:0;display:flex;align-items:flex-start;">${orgLogoHtml}</div>
        </div>
        <div style="margin-top:2mm;">${groepNaamRegelHtml}</div>`;

    // ── Bouw alle "stukken" — elke stuk is één groep + slice die op één
    //    pagina past (volgens de bestaande chunk-logica). ────────────────────
    const stukken = groepen.flatMap(g => {
        const totaal     = g.deelnemers.length;
        const chunks     = berekenChunks(totaal, !!footerHtml);
        const totaalPag  = chunks.length;
        let offset = 0;
        return chunks.map((aantal, idx) => {
            const stuk = {
                groep:       g,
                slice:       g.deelnemers.slice(offset, offset + aantal),
                chunkStart:  offset,
                aantalRijen: aantal,
                isVervolg:   idx > 0,
                isLaatste:   idx === totaalPag - 1,
                pLabel:      totaalPag > 1 ? ' — ' + T('algemeen.pagina_x_van_y', { x: idx + 1, y: totaalPag }) : '',
                totaal,
            };
            offset += aantal;
            return stuk;
        });
    });

    let paginaHtml;

    if (!boomSaver) {
        // Default: elke stuk een eigen pagina (oorspronkelijk gedrag).
        // Footer (sponsorbalk) staat op de laatste chunk van elke groep —
        // ongewijzigd t.o.v. de oude implementatie.
        paginaHtml = stukken.map(st => {
            const g = st.groep;
            const isEerstePag = !st.isVervolg;
            const _deelnTxt = T(st.totaal === 1 ? 'algemeen.deelnemer_1' : 'algemeen.deelnemers_n', { n: st.totaal });
            const groepRegel = `<div style="font-size:10pt;font-weight:bold;line-height:1.2;">${escHtml(g.naam)}
                <span style="font-size:8pt;font-weight:normal;color:#555;">(${escHtml(_deelnTxt + st.pLabel)})</span>
            </div>`;
            const paginaHeader = isEerstePag
                ? compHeaderBlok(groepRegel)
                : `<div style="font-size:10pt;font-weight:bold;line-height:1.2;padding-bottom:1mm;">
                       ${escHtml(g.naam)}
                       <span style="font-size:8pt;font-weight:normal;color:#555;">(${escHtml(T('algemeen.vervolg') + st.pLabel)})</span>
                   </div>`;
            return `<div class="pagina">
                ${paginaHeader}
                <div style="border-bottom:2px solid #1a3a5c;margin:0 0 1.5mm 0;"></div>
                <table>${thead}<tbody>${renderRijen(st.slice, st.chunkStart)}</tbody></table>
                ${st.isLaatste ? footerHtml : ''}
            </div>`;
        }).join('');
    } else {
        // Compact tekenlijst: één tabel per categorie. Rijders die meerdere
        // afstanden in dezelfde cat rijden komen 1× voor in plaats van 3×.
        // Subgroepen gegroepeerd op afstand-combinatie (alle-3 → 2-combos →
        // solos), met per-rijder vinkjes per afstand. Vervangt de oude
        // greedy-fit boom-saver — die spaarde papier per kleine cat, maar deze
        // mode spaart radicaler: 3 lijsten → 1 lijst per cat.
        const clusters = _bouwCompactCatClusters();
        const _deelnTxt = n => T(n === 1 ? 'algemeen.deelnemer_1' : 'algemeen.deelnemers_n', { n });
        // Korte afstand-naam voor de vertikale kolom-header: strip de
        // cat-prefix uit distance_naam zodat "Junior Ladies 200m DTT" →
        // "200m DTT". Match vanaf eerste afstand-kenmerk. Werkt voor
        // EN/NL/DE/FR distance_namen mits ze eindigen op een meting of
        // bekend race-type.
        const _kortAfstand = naam => {
            const s = String(naam || '');
            const m = s.match(/(\d+\s*m\b.*|Points?rac.*|Punten.*|Afval.*|Knock.*|Marathon.*|Sprint.*|DTT.*|Mass.*)$/i);
            return (m ? m[1] : s).trim();
        };

        const blokken = clusters.map(cluster => {
            const { cat, dcs, subgroepen } = cluster;
            const aantalRijders = subgroepen.reduce((s, sg) => s + sg.rijders.length, 0);
            // Cat-titel = NL-cat-code (kort) + volledige distance-namen onder
            // (universeel begrijpelijk). Dat dekt zowel de operator (kent DJA)
            // als de buitenlandse rijder (leest "Junior Ladies 200m DTT").
            const volledigeNamen = dcs.map(d => d.naam).filter(Boolean).join(' · ');
            const distHeads = dcs.map(d =>
                `<th class="td-dist">${escHtml(_kortAfstand(d.naam))}</th>`).join('');
            const ncols = 6 + dcs.length;
            const compactThead = `<thead><tr>
                <th class="td-nr">#</th>
                <th class="td-sn">${escHtml(T('tekenlijst.col_startnr'))}</th>
                <th class="td-naam">${escHtml(T('algemeen.naam'))}</th>
                <th class="td-tp">${escHtml(T('algemeen.transponder'))}</th>
                <th class="td-tp-cor">${escHtml(T('tekenlijst.col_correctie'))}</th>
                ${distHeads}
                <th class="td-hand">${escHtml(T('algemeen.handtekening'))}</th>
            </tr></thead>`;
            let rowNum = 0;
            const tbodyHtml = subgroepen.map(sg => {
                // Label opbouwen — i18n via T(). Mogelijk:
                //   • "Alle N afstanden" / "All N distances" — als alle DC's
                //   • "200m DTT only" — solo (1 idx)
                //   • "200m DTT + 1000m" — 2-combinatie (idxs > 1, < alle)
                const korteAfstanden = dcs.map(d => _kortAfstand(d.naam));
                let sgLabel;
                if (sg.idxs.length === dcs.length && dcs.length > 1) {
                    sgLabel = T('tekenlijst.sub_alle_n', { n: dcs.length });
                } else if (sg.idxs.length === 1) {
                    sgLabel = T('tekenlijst.sub_alleen', { naam: korteAfstanden[sg.idxs[0]] });
                } else {
                    sgLabel = sg.idxs.map(i => korteAfstanden[i]).join(' + ');
                }
                const sgHead = `<tr class="sub-head"><td colspan="${ncols}">
                    <strong>${escHtml(sgLabel)}</strong>
                    <span class="sub-tel">— ${escHtml(_deelnTxt(sg.rijders.length))}</span>
                </td></tr>`;
                const rijenHtml = sg.rijders.map(d => {
                    rowNum++;
                    const sn        = Number(d.start_number);
                    const isReserve = d.reserve != null && d.reserve > 0;
                    const meldingen = [];
                    if (d.entry_status === 0)   meldingen.push(T('tekenlijst.melding'));
                    if (!d.transponder)         meldingen.push(T('tekenlijst.geen_tp'));
                    if (sn >= 1000)             meldingen.push(T('tekenlijst.startnr_n', { nr: sn }));
                    if (d.tp_betaald === false) meldingen.push(T('tekenlijst.tp_niet_betaald'));
                    const handCel = meldingen.length
                        ? `<div class="meld-attentie">
                               <span class="meld-uitroep">⚠️</span>
                               <span class="meld-tekst">${escHtml(T('tekenlijst.persoonlijk_melden'))}</span>
                               <span class="meld-uitroep">⚠️</span>
                           </div>`
                        : '';
                    const tpHtml = d.tp_org_nr
                        ? `<span class="tp-orgnr">#${escHtml(String(d.tp_org_nr))}</span> ${escHtml(d.transponder ?? '')}`
                        : escHtml(String(d.transponder ?? ''));
                    const numCel = isReserve ? `R${d.reserve}` : String(rowNum);
                    const rowCls = isReserve ? 'rij-reserve' : '';
                    const idxSet = new Set(d._idxs || []);
                    const distCellen = dcs.map((_, i) =>
                        idxSet.has(i)
                            ? `<td class="td-dist td-dist-aan">✓</td>`
                            : `<td class="td-dist td-dist-uit"></td>`
                    ).join('');
                    return `<tr class="${rowCls}">
                        <td class="td-nr">${escHtml(numCel)}</td>
                        <td class="td-sn">${escHtml(String(d.start_number))}</td>
                        <td class="td-naam">${escHtml(d.full_name)}</td>
                        <td class="td-tp">${tpHtml}</td>
                        <td class="td-tp-cor"><div class="tp-boxes"><span class="tp-box"></span><span class="tp-box"></span><span class="tp-sep">-</span><span class="tp-box"></span><span class="tp-box"></span><span class="tp-box"></span><span class="tp-box"></span><span class="tp-box"></span></div></td>
                        ${distCellen}
                        <td class="td-hand">${handCel}</td>
                    </tr>`;
                }).join('');
                return sgHead + rijenHtml;
            }).join('');
            // Cat-titel-regel voor de comp-header: NL-cat-code groot, daaronder
            // de volledige distance-namen (universeel begrijpelijk).
            const groepRegel = `<div style="font-size:11pt;font-weight:bold;line-height:1.2;">
                ${escHtml(cat)}
                <span style="font-size:8pt;font-weight:normal;color:#555;">(${escHtml(_deelnTxt(aantalRijders))})</span>
                ${volledigeNamen ? `<div style="font-size:9pt;font-weight:normal;color:#1a3a5c;line-height:1.2;margin-top:0.5mm;">${escHtml(volledigeNamen)}</div>` : ''}
            </div>`;
            // Elke cat = eigen pagina met volledige comp-header (logos +
            // wedstrijdnaam + datum + cat-titel) en eigen sponsor-footer.
            // Eenduidiger dan een gedeelde header op alleen pagina 1.
            // De Print-Center shared header wordt door tekenlijst.css verborgen
            // (zie `body.pc-boomsaver .pc-shared-header` regel).
            return `<div class="pagina">
                ${compHeaderBlok(groepRegel)}
                <div style="border-bottom:2px solid #1a3a5c;margin:0 0 1.5mm 0;"></div>
                <table class="tekenlijst-compact">${compactThead}<tbody>${tbodyHtml}</tbody></table>
                ${footerHtml || ''}
            </div>`;
        }).join('');
        paginaHtml = blokken;
    }

    return {
        bodyHtml:        paginaHtml,
        cssLinks:        ['css/tekenlijst.css'],
        pageOrientation: 'landscape',
        title:           T('tekenlijst.titel_meervoud') + ' – ' + (huidigComp.name || huidigComp.title || ''),
        subType:         T('tekenlijst.titel_meervoud'),
    };
}

// ── Definitieve deelnemerslijst ───────────────────────────────────────────────

function bouwDeelnemerslijstBody(opts = {}) {
    if (!vergelijkData?.length || !huidigComp) return null;
    return _bouwDeelnemerslijstInternal(opts);
}

// Interne body-bouwer — aangeroepen door `bouwDeelnemerslijstBody()` voor
// Print-Center. Er is geen directe print-knop meer in de UI.
//
// `opts.dagFilter` (default 0/alle): bij multi-day filter op DCs die op de
// gegeven dag actief zijn. Werkt op dezelfde `vergelijkData` array maar
// reduceert die naar alleen relevante DCs vóór de rest van de logica draait.
function _bouwDeelnemerslijstInternal(opts = {}) {
    // Multi-day filter: alleen DCs die op deze dag een rit hebben.
    // Wijzigt LOKALE referentie naar vergelijkData (subset). Verderop wordt
    // 'vergelijkData' direct gebruikt — we shadowen 'm in deze functie-scope
    // via een let-binding zodat de filter consistent doorwerkt.
    const dagFilter = parseInt(opts.dagFilter) || 0;
    let _vergelijkData = vergelijkData;
    if (dagFilter > 0 && typeof _tsBouwDcDagMap === 'function') {
        const dcDagMap = _tsBouwDcDagMap(typeof huidigTijdschema !== 'undefined' ? huidigTijdschema : null);
        _vergelijkData = vergelijkData.filter(c => dcDagMap.get(c.dc_id) === dagFilter);
        if (!_vergelijkData.length) return null;
        // Tijdelijk vergelijkData overschrijven (en aan einde herstellen) zodat
        // de bestaande logica de filtered subset gebruikt. Geen mooie pattern
        // maar minst invasief: anders moeten alle interne ref's geparameter-
        // iseerd worden.
        var _vdSave = vergelijkData;
        vergelijkData  = _vergelijkData;
    }
    try {
    // i18n-helper voor Print-Center taalkeuze (NL/EN). Fallback = identity.
    const T    = window._pcT    || (k => k);
    const LANG = (window._pcLang && window._pcLang()) || 'nl';
    const LOC  = LANG === 'en' ? 'en-GB' : 'nl-NL';

    const compNaam = escHtml(huidigComp.name || huidigComp.title || '');
    // Locale-aware wedstrijddatum (formatDatum is hardcoded nl-NL).
    const _datumStr = huidigComp.starts
        ? new Date(huidigComp.starts).toLocaleDateString(LOC,
            { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
        : '';
    const compMeta = escHtml([_datumStr, getLocatie(huidigComp)].filter(Boolean).join(' · '));
    const _stand   = standDatum || dbStandDatum || '';
    const standTxt = _stand ? T('algemeen.stand_op', { datum: _stand }) : '';
    const baseUrl  = new URL('.', window.location.href).href;

    // Locale-aware status-label (STATUS_LABELS is hardcoded NL).
    // Status 0 wordt visueel als 'Niet getekend' (4) getoond — zelfde
    // afspraak als de oorspronkelijke code.
    const statusLabel = (s) => {
        const idx = s === 0 ? 4 : s;
        switch (idx) {
            case 0: return T('deelnemers.status_niet_bev');
            case 2: return T('deelnemers.status_afgemeld');
            case 3: return T('deelnemers.status_afg_org');
            case 4: return T('deelnemers.status_niet_get');
            default: return `status ${s}`;
        }
    };

    // ── 1. Alle unieke afstanden verzamelen als kolom-headers ─────────────────
    // Gebruik de globale dcDistances (gevuld door bouwBeheerTabel):
    //   dcDistances[dc_id]               = basis-afstanden (KNSB, zonder splits)
    //   dcDistances[dc_id::splitgroep]   = afstanden voor een specifieke splitgroep
    // Fallback: knsb_distances rechtstreeks van vergelijkData-object.
    // We verzamelen UNIEKE afstand-namen over alle keys + fallback zodat het
    // kolom-overzicht volledig is bij gesplitste DCs.
    const afstandMap = new Map(); // name → value_meters
    vergelijkData.forEach(dc => {
        const prefix = dc.dc_id + '::';
        // Verzamel: basis-key dc_id + alle split-keys dc_id::*
        Object.keys(dcDistances).forEach(k => {
            if (k === dc.dc_id || k.startsWith(prefix)) {
                (dcDistances[k] || []).forEach(d => {
                    if (d.name && !afstandMap.has(d.name)) {
                        afstandMap.set(d.name, d.value_meters ?? 0);
                    }
                });
            }
        });
        // KNSB-feed fallback (als er helemaal geen DB-afstanden zijn)
        (dc.knsb_distances || []).forEach(d => {
            if (d.name && !afstandMap.has(d.name))
                afstandMap.set(d.name, d.value_meters ?? 0);
        });
    });
    // Sorteer: kortste afstand eerst; bij gelijk getal: alfabetisch
    const afstandKols = [...afstandMap.entries()]
        .sort((a, b) => (a[1] - b[1]) || a[0].localeCompare(b[0], 'nl'))
        .map(([name]) => name);

    // ── 2. Per rijder alle DC-participaties samenvoegen ───────────────────────
    // Deduplicatie-sleutel: license_key indien aanwezig, anders dc_id + volgnummer
    // (zelfde aanpak als groepeerVoorPrint: geen harde eis op license_key)
    const rijdersMap = new Map();

    vergelijkData.forEach(dc => {
        dc.competitors.forEach((c, idx) => {
            // Status: gebruik entryEdits als die bijgewerkt zijn, anders direct van object
            const lk     = c.license_key || null;
            const ek     = lk ? (dc.dc_id + '_' + lk) : null;
            const ee     = (ek && entryEdits[ek]) || {};
            const status = Number(ee.entry_status ?? c.entry_status ?? 1);
            const pe     = lk ? (personEdits[lk] || {}) : {};

            // Per rijder de juiste afstanden bepalen op basis van z'n cat-
            // splitgroep. Lookup-volgorde:
            //   1. dcDistances[dc_id::splitgroep_van_deze_cat]
            //   2. dcDistances[dc_id]
            //   3. dc.knsb_distances
            const rijderCat   = pe.category ?? c.knsb?.category ?? '';
            const splitGroep  = (dc.splits && rijderCat) ? dc.splits[rijderCat] : null;
            const splitKey    = splitGroep ? `${dc.dc_id}::${splitGroep}` : null;
            const bronAfst    = (splitKey && dcDistances[splitKey]?.length)
                ? dcDistances[splitKey]
                : (dcDistances[dc.dc_id]?.length
                    ? dcDistances[dc.dc_id]
                    : (dc.knsb_distances || []));
            const dcAfstanden = bronAfst.map(d => d.name);

            // Kaartsleutel: license_key heeft voorkeur (rijder deelt naam over DCs),
            // anders uniek per DC-entry zodat rijder toch verschijnt
            const kaartSleutel = lk ?? `${dc.dc_id}::${idx}`;

            if (!rijdersMap.has(kaartSleutel)) {
                rijdersMap.set(kaartSleutel, {
                    start_number:       pe.start_number      ?? c.knsb?.start_number ?? '',
                    knsb_start_number:  c.knsb?.start_number  ?? '',
                    full_name:          pe.full_name          ?? c.knsb?.full_name    ?? '',
                    category:           pe.category           ?? c.knsb?.category     ?? '',
                    // pe.transponder_actief = null → operator-bevestigde "geen
                    // actieve" → niet door-vallen naar KNSB-default (anders zou
                    // de deelnemerslijst een transponder tonen die niet wordt
                    // gebruikt; zie ook groepeerVoorPrint()).
                    transponder_actief: ('transponder_actief' in pe)
                        ? (pe.transponder_actief ?? '')
                        : (pe.transponder1 ?? c.knsb?.transponder1 ?? ''),
                    knsb_transponder:   c.knsb?.transponder1  ?? '',
                    knsb_transponder2:  c.knsb?.transponder2  ?? '',
                    is_actief:          false,
                    is_org_toegevoegd:  false,
                    afstanden_actief:   new Set(),
                    afstanden_afwezig:  new Set(),
                    statussen:          [],
                });
            }

            const r = rijdersMap.get(kaartSleutel);
            r.statussen.push({ dc_naam: dc.dc_name, status });

            // Status 1 (bevestigd) of 5 (bevestigd bij org.) → actief, X op deelnemerslijst
            // Status 0/2/3/4 → geen X, wél opnemen in wijzigingen/afwezig lijst
            if (status === 1 || status === 5) {
                r.is_actief = true;
                dcAfstanden.forEach(n => r.afstanden_actief.add(n));
            } else {
                dcAfstanden.forEach(n => r.afstanden_afwezig.add(n));
            }
            if (status === 5) r.is_org_toegevoegd = true;
        });
    });

    // ── 3. Lijsten opbouwen ───────────────────────────────────────────────────
    const sortSn = arr => arr.sort((a, b) =>
        (Number(a.start_number) || 9999) - (Number(b.start_number) || 9999));

    const alleRijders      = sortSn([...rijdersMap.values()]);
    const actieveRijders   = alleRijders.filter(r => r.is_actief);
    // Door organisatie toegevoegd (status 5)
    const orgRijders       = alleRijders.filter(r => r.is_org_toegevoegd);
    // Afwezig = heeft minstens één afwezige afstand
    const afwezigRijders   = alleRijders.filter(r => r.afstanden_afwezig.size > 0);

    // Startnummer-wijzigingen: actief startnummer wijkt af van het KNSB-startnummer.
    // Door org toegevoegden (status 5) hebben geen KNSB-nummer en horen hier niet —
    // die staan al in "Door organisatie toegevoegd".
    const snWijzigingen = actieveRijders.filter(r => {
        const cur  = String(r.start_number      ?? '').trim();
        const knsb = String(r.knsb_start_number ?? '').trim();
        if (knsb === '') return false; // geen KNSB-waarde om mee te vergelijken
        return cur !== knsb;
    });

    // Transponder-wijzigingen: alleen als de actieve transponder afwijkt van
    // ZOWEL T1 als T2. T1 en T2 staan normaal al in Orbits/MyLaps, dus als
    // actief = T1 óf actief = T2 is er niks te melden.
    // Rijders zonder enige KNSB-transponder (T1 én T2 leeg) horen hier ook
    // niet — die staan al in "Met organisatie-transponder" of
    // "Geen transponder geregistreerd".
    const tpWijzigingen = actieveRijders.filter(r => {
        const actief = String(r.transponder_actief ?? '').trim();
        const t1     = String(r.knsb_transponder   ?? '').trim();
        const t2     = String(r.knsb_transponder2  ?? '').trim();
        if (actief === '') return false;
        if (t1 === '' && t2 === '') return false;
        return actief !== t1 && actief !== t2;
    });

    // Geen transponder: actieve deelnemers zonder ingestelde transponder
    const geenTpRijders = actieveRijders.filter(r =>
        !String(r.transponder_actief ?? '').trim()
    );

    // Met organisatie-transponder: actieve deelnemers wiens transponder-code
    // voorkomt in _orgTransponders (de transponder-inventaris van de eigen org)
    const orgTpMap = new Map((_orgTransponders || []).map(ot => [ot.transponder_code, ot]));
    const orgTpRijders = actieveRijders
        .map(r => {
            const code = String(r.transponder_actief ?? '').trim();
            if (!code) return null;
            const ot = orgTpMap.get(code);
            return ot ? { ...r, _ot: ot } : null;
        })
        .filter(Boolean);

    // ── 4. Org-logo + baan-logo (alleen header, geen footer — intern document) ─
    const { orgLogoHtml, baanLogoHtml } = bouwOrgHeaderFooter(escHtml);

    const printDatum = new Date().toLocaleString(LOC,
        { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    // ── 5. Tabel-helpers ──────────────────────────────────────────────────────
    // Deelnemerstabel: kolommen start# | naam | cat | transponder | afstand...
    const afstColW  = afstandKols.length ? Math.max(13, Math.floor(40 / afstandKols.length)) : 14;
    const colgrpDl  = `<colgroup>
        <col style="width:12mm">
        <col style="width:auto">
        <col style="width:16mm">
        <col style="width:22mm">
        ${afstandKols.map(() => `<col style="width:${afstColW}mm">`).join('')}
    </colgroup>`;

    const theadDl = `<thead><tr>
        <th class="tc">#</th>
        <th>${escHtml(T('algemeen.naam'))}</th>
        <th>${escHtml(T('deelnemers.col_cat_kort'))}</th>
        <th>${escHtml(T('deelnemers.col_transp_kort'))}</th>
        ${afstandKols.map(n => `<th class="tc">${escHtml(n)}</th>`).join('')}
    </tr></thead>`;

    function rijDl(r, i) {
        const afstCellen = afstandKols.map(n =>
            `<td class="tc">${r.afstanden_actief.has(n) ? '✕' : ''}</td>`
        ).join('');
        // Transponder-kolom: toon actuele code (eigen KNSB of org-uitleen).
        // Bij leeg → dash.
        const tpCode = String(r.transponder_actief ?? '').trim();
        return `<tr class="${i % 2 === 1 ? 'z' : ''}">
            <td class="tc">${escHtml(String(r.start_number))}</td>
            <td>${escHtml(r.full_name)}</td>
            <td class="sm">${escHtml(r.category)}</td>
            <td class="sm">${tpCode ? escHtml(tpCode) : '<span class="grijs">—</span>'}</td>
            ${afstCellen}
        </tr>`;
    }

    // ── 6. Sectie-HTML bouwen ─────────────────────────────────────────────────
    const heeftGeenTp  = geenTpRijders.length  > 0;
    const heeftOrgTp   = orgTpRijders.length   > 0;
    const heeftOrg     = orgRijders.length     > 0;
    const heeftAfwezig = afwezigRijders.length > 0;
    const heeftTpWijz  = tpWijzigingen.length  > 0;
    const heeftSnWijz  = snWijzigingen.length  > 0;

    // --- Sectie GT: Geen transponder geregistreerd ---
    let sectieGT = '';
    if (heeftGeenTp) {
        const rijen = geenTpRijders.map((r, i) =>
            `<tr class="${i % 2 === 1 ? 'z-rood' : ''}">
                <td class="tc">${escHtml(String(r.start_number))}</td>
                <td>${escHtml(r.full_name)}</td>
                <td class="sm">${escHtml(r.category)}</td>
                <td class="tp-invul"></td>
            </tr>`
        ).join('');
        sectieGT = `<h2 class="sectie-titel sectie-oranje">${escHtml(T('deelnemers.sec_geen_tp'))} &nbsp;<span class="teller">${geenTpRijders.length}</span></h2>
        <table><colgroup>
            <col style="width:12mm"><col style="width:auto"><col style="width:16mm">
            <col style="width:55mm">
        </colgroup>
        <thead><tr>
            <th class="tc">#</th><th>${escHtml(T('algemeen.naam'))}</th><th>${escHtml(T('deelnemers.col_cat_kort'))}</th>
            <th>${escHtml(T('deelnemers.col_transp_invul'))}</th>
        </tr></thead>
        <tbody>${rijen}</tbody></table>`;
    }

    // --- Sectie OT: Met organisatie transponder ---
    let sectieOT = '';
    if (heeftOrgTp) {
        const rijen = orgTpRijders.map((r, i) => {
            const betaald    = parseInt(r._ot.betaald) === 1;
            const betaaldTxt = betaald ? '✓' : '✗';
            const betaaldCls = betaald ? 'groen' : 'rood';
            return `<tr class="${i % 2 === 1 ? 'z-groen' : ''}">
                <td class="tc">${escHtml(String(r.start_number))}</td>
                <td>${escHtml(r.full_name)}</td>
                <td class="sm">${escHtml(r.category)}</td>
                <td class="tc sm">${escHtml(String(r._ot.intern_nummer))}</td>
                <td class="sm">${escHtml(String(r._ot.transponder_code))}</td>
                <td class="tc sm ${betaaldCls} vet">${betaaldTxt}</td>
            </tr>`;
        }).join('');
        sectieOT = `<h2 class="sectie-titel sectie-groen">${escHtml(T('deelnemers.sec_org_tp'))} &nbsp;<span class="teller">${orgTpRijders.length}</span></h2>
        <table><colgroup>
            <col style="width:12mm"><col style="width:auto"><col style="width:16mm">
            <col style="width:14mm"><col style="width:36mm"><col style="width:20mm">
        </colgroup>
        <thead><tr>
            <th class="tc">#</th><th>${escHtml(T('algemeen.naam'))}</th><th>${escHtml(T('deelnemers.col_cat_kort'))}</th>
            <th class="tc">${escHtml(T('deelnemers.col_org_nr'))}</th><th>${escHtml(T('algemeen.transponder'))}</th><th class="tc">${escHtml(T('deelnemers.col_betaald'))}</th>
        </tr></thead>
        <tbody>${rijen}</tbody></table>`;
    }

    // --- Sectie 0: Door organisatie toegevoegd ---
    let sectie0 = '';
    if (heeftOrg) {
        const rijen = orgRijders.map((r, i) => {
            const dcNamen = r.statussen
                .filter(s => s.status === 5)
                .map(s => s.dc_naam)
                .filter((v, j, a) => a.indexOf(v) === j)
                .join(', ');
            return `<tr class="${i % 2 === 1 ? 'z-blauw' : ''}">
                <td class="tc">${escHtml(String(r.start_number))}</td>
                <td>${escHtml(r.full_name)}</td>
                <td class="sm">${escHtml(r.category)}</td>
                <td class="sm">${escHtml(r.transponder_actief)}</td>
                <td class="sm blauw">${escHtml(dcNamen)}</td>
            </tr>`;
        }).join('');
        sectie0 = `<h2 class="sectie-titel sectie-blauw">${escHtml(T('deelnemers.sec_org_added'))} &nbsp;<span class="teller">${orgRijders.length}</span></h2>
        <table><colgroup>
            <col style="width:12mm"><col style="width:auto"><col style="width:16mm">
            <col style="width:36mm"><col style="width:auto">
        </colgroup>
        <thead><tr>
            <th class="tc">#</th><th>${escHtml(T('algemeen.naam'))}</th><th>${escHtml(T('deelnemers.col_cat_kort'))}</th>
            <th>${escHtml(T('algemeen.transponder'))}</th><th>${escHtml(T('deelnemers.col_groep'))}</th>
        </tr></thead>
        <tbody>${rijen}</tbody></table>`;
    }

    // --- Sectie 1: Niet aanwezig / afgemeld ---
    let sectie1 = '';
    if (heeftAfwezig) {
        const rijen = afwezigRijders.map((r, i) => {
            // Alleen echte afwezig-statussen meenemen: 0=niet bevestigd,
            // 2=afgemeld via KNSB, 3=afgemeld bij org, 4=niet getekend.
            // Status 5 (bevestigd bij org = wél aanwezig) hoort hier niet
            // bij — die lekte voorheen door als rauwe "status 5"-tekst.
            const statusTxt = r.statussen
                .filter(s => [0, 2, 3, 4].includes(s.status))
                .map(s => statusLabel(s.status))
                .filter((v, j, a) => a.indexOf(v) === j)
                .join(', ');
            const afstTxt = [...r.afstanden_afwezig].join(', ');
            return `<tr class="${i % 2 === 1 ? 'z-rood' : ''}">
                <td class="tc">${escHtml(String(r.start_number))}</td>
                <td>${escHtml(r.full_name)}</td>
                <td class="sm">${escHtml(r.category)}</td>
                <td class="sm">${escHtml(afstTxt)}</td>
                <td class="sm rood">${escHtml(statusTxt)}</td>
            </tr>`;
        }).join('');
        sectie1 = `<h2 class="sectie-titel">${escHtml(T('deelnemers.sec_afwezig'))} &nbsp;<span class="teller">${afwezigRijders.length}</span></h2>
        <table><colgroup>
            <col style="width:12mm"><col style="width:auto"><col style="width:16mm">
            <col style="width:auto"><col style="width:32mm">
        </colgroup>
        <thead><tr>
            <th class="tc">#</th><th>${escHtml(T('algemeen.naam'))}</th><th>${escHtml(T('deelnemers.col_cat_kort'))}</th>
            <th>${escHtml(T('deelnemers.col_afstanden'))}</th><th>${escHtml(T('deelnemers.col_status'))}</th>
        </tr></thead>
        <tbody>${rijen}</tbody></table>`;
    }

    // --- Sectie 2: Transponder aanpassingen ---
    let sectie2 = '';
    if (heeftTpWijz) {
        const rijen = tpWijzigingen.map((r, i) => {
            const t1 = String(r.knsb_transponder  ?? '').trim();
            const t2 = String(r.knsb_transponder2 ?? '').trim();
            const knsbTxt = [t1, t2].filter(Boolean).join(' · ');
            return `<tr class="${i % 2 === 1 ? 'z' : ''}">
                <td class="tc">${escHtml(String(r.start_number))}</td>
                <td>${escHtml(r.full_name)}</td>
                <td class="sm">${escHtml(r.category)}</td>
                <td class="sm grijs">${escHtml(knsbTxt)}</td>
                <td class="sm vet">${escHtml(String(r.transponder_actief))}</td>
            </tr>`;
        }).join('');
        sectie2 = `<h2 class="sectie-titel">${escHtml(T('deelnemers.sec_tp_wijz'))} &nbsp;<span class="teller">${tpWijzigingen.length}</span></h2>
        <table><colgroup>
            <col style="width:12mm"><col style="width:auto"><col style="width:16mm">
            <col style="width:42mm"><col style="width:42mm">
        </colgroup>
        <thead><tr>
            <th class="tc">#</th><th>${escHtml(T('algemeen.naam'))}</th><th>${escHtml(T('deelnemers.col_cat_kort'))}</th>
            <th>${escHtml(T('deelnemers.col_tp_knsb'))}</th><th>${escHtml(T('deelnemers.col_tp_gebruikt'))}</th>
        </tr></thead>
        <tbody>${rijen}</tbody></table>`;
    }

    // --- Sectie SN: Startnummer aanpassingen ---
    let sectieSN = '';
    if (heeftSnWijz) {
        const rijen = snWijzigingen.map((r, i) =>
            `<tr class="${i % 2 === 1 ? 'z' : ''}">
                <td class="tc grijs">${escHtml(String(r.knsb_start_number))}</td>
                <td class="tc vet">${escHtml(String(r.start_number))}</td>
                <td>${escHtml(r.full_name)}</td>
                <td class="sm">${escHtml(r.category)}</td>
            </tr>`
        ).join('');
        sectieSN = `<h2 class="sectie-titel">${escHtml(T('deelnemers.sec_sn_wijz'))} &nbsp;<span class="teller">${snWijzigingen.length}</span></h2>
        <table><colgroup>
            <col style="width:18mm"><col style="width:18mm"><col style="width:auto"><col style="width:16mm">
        </colgroup>
        <thead><tr>
            <th class="tc">${escHtml(T('deelnemers.col_knsb'))}</th><th class="tc">${escHtml(T('deelnemers.col_gebruikt'))}</th><th>${escHtml(T('algemeen.naam'))}</th><th>${escHtml(T('deelnemers.col_cat_kort'))}</th>
        </tr></thead>
        <tbody>${rijen}</tbody></table>`;
    }

    // --- Sectie OV: Overzicht race-groepen (DC × afstanden × categorieën) ---
    // Toont per "race-groep" — na eventueel mergen/splitsen van DCs — welke
    // categorieën er rijden, hoeveel deelnemers per categorie, en welke
    // afstanden de groep rijdt. Helpt jurytafel/speaker om in één oogopslag
    // de structuur van de wedstrijd te zien.
    //
    // Multi-day: respecteer dagFilter expliciet (extra safety net t.o.v.
    // het globale vergelijkData-shadow — zo zien we alleen race-groepen
    // van DCs die op de gekozen dag actief zijn).
    const _vdRaceOverzicht = (dagFilter > 0 && typeof _tsBouwDcDagMap === 'function')
        ? (() => {
              const m = _tsBouwDcDagMap(typeof huidigTijdschema !== 'undefined' ? huidigTijdschema : null);
              return vergelijkData.filter(c => m.get(c.dc_id) === dagFilter);
          })()
        : vergelijkData;
    const overzichtGroepen = [];
    {
        const usedIds = new Set();
        _vdRaceOverzicht.forEach(cat => {
            if (usedIds.has(cat.dc_id)) return;
            usedIds.add(cat.dc_id);

            // Verzamel alle DCs in dezelfde merge-cluster (alleen binnen
            // dezelfde dag — wat ook altijd zo zou moeten zijn in praktijk)
            const dcGroup = [cat];
            if (cat.merge_group) {
                _vdRaceOverzicht.forEach(c => {
                    if (!usedIds.has(c.dc_id) && c.merge_group === cat.merge_group) {
                        usedIds.add(c.dc_id);
                        dcGroup.push(c);
                    }
                });
            }

            // Basis-naam (merge_label of dc_name'en + ' + ')
            const basisNaam = (dcGroup.find(d => d.merge_label) ?? {}).merge_label
                           || dcGroup.map(d => d.dc_name).filter(Boolean).join(' + ');

            // Helper: bouw afstand-lijst voor een specifieke splitgroep (of
            // null = basis/non-split). Lookup-volgorde:
            //   1. dcDistances[dcId::splitgroep] — eigen afstanden voor split
            //   2. dcDistances[dcId]              — cluster-default
            //   3. dc.knsb_distances              — KNSB-feed fallback
            // Voorheen werd alleen (2) en (3) gebruikt — dat verklaart waarom
            // gesplitste DCs een lege afstand-kolom kregen wanneer afstanden
            // alleen onder de split-specifieke sleutel waren opgeslagen.
            const bouwAfstanden = (splitGroep) => {
                const afsMap = new Map(); // name → meters
                dcGroup.forEach(dc => {
                    const splitKey = splitGroep ? `${dc.dc_id}::${splitGroep}` : null;
                    let bron = (splitKey && dcDistances[splitKey]?.length)
                        ? dcDistances[splitKey]
                        : (dcDistances[dc.dc_id]?.length
                            ? dcDistances[dc.dc_id]
                            : (dc.knsb_distances || []));
                    bron.forEach(d => {
                        if (!afsMap.has(d.name)) afsMap.set(d.name, d.value_meters ?? 0);
                    });
                });
                return [...afsMap.entries()]
                    .sort((a, b) => (a[1] - b[1]) || a[0].localeCompare(b[0], 'nl'));
            };

            // Actieve deelnemers per categorie (status 1 of 5 = aanwezig)
            const perCat = new Map(); // cat → aantal
            dcGroup.forEach(dc => {
                dc.competitors.forEach(c => {
                    const lk = c.license_key || null;
                    const ek = lk ? (dc.dc_id + '_' + lk) : null;
                    const ee = (ek && entryEdits[ek]) || {};
                    const st = Number(ee.entry_status ?? c.entry_status ?? 1);
                    if (st !== 1 && st !== 5) return;
                    const pe = lk ? (personEdits[lk] || {}) : {};
                    const cats = pe.category ?? c.knsb?.category ?? '?';
                    perCat.set(cats, (perCat.get(cats) || 0) + 1);
                });
            });

            // Splits: als er splits zijn, splitsen we de groep in sub-groepen
            const allSplits = {};
            dcGroup.forEach(dc => {
                Object.entries(dc.splits || {}).forEach(([k, v]) => { if (v) allSplits[k] = v; });
            });
            const splitGrps = [...new Set(Object.values(allSplits))].sort();

            if (splitGrps.length) {
                splitGrps.forEach(sg => {
                    const sgCats = Object.keys(allSplits).filter(k => allSplits[k] === sg);
                    const sgPerCat = new Map();
                    sgCats.forEach(c => { if (perCat.has(c)) sgPerCat.set(c, perCat.get(c)); });
                    if (sgPerCat.size === 0) return;
                    overzichtGroepen.push({
                        naam: `${basisNaam} — ${sg}`,
                        afstanden: bouwAfstanden(sg),   // per-splitgroep lookup
                        perCat: sgPerCat,
                    });
                });
                // Restcategorieën (niet in splits) als aparte groep — basis-afstanden
                const restCats = [...perCat.keys()].filter(c => !Object.keys(allSplits).includes(c));
                if (restCats.length) {
                    const restPerCat = new Map();
                    restCats.forEach(c => restPerCat.set(c, perCat.get(c)));
                    overzichtGroepen.push({
                        naam: `${basisNaam} — ${T('algemeen.overig')}`,
                        afstanden: bouwAfstanden(null),
                        perCat: restPerCat,
                    });
                }
            } else {
                if (perCat.size > 0) {
                    overzichtGroepen.push({ naam: basisNaam, afstanden: bouwAfstanden(null), perCat });
                }
            }
        });
    }

    let sectieOV = '';
    if (overzichtGroepen.length) {
        const ovRijen = overzichtGroepen.map((g, i) => {
            const totaal = [...g.perCat.values()].reduce((a, b) => a + b, 0);
            const afsTxt = g.afstanden
                .map(([n, m]) => `${escHtml(n)}${m ? ` <span class="grijs">(${m}m)</span>` : ''}`)
                .join(' &nbsp;·&nbsp; ');
            return `<tr class="${i % 2 === 1 ? 'z' : ''}">
                <td class="vet">${escHtml(g.naam)}</td>
                <td class="tc vet">${totaal}</td>
                <td class="sm">${afsTxt}</td>
            </tr>`;
        }).join('');
        // Wrap in een blok met page-break-inside: avoid zodat het overzicht
        // niet halverwege over een pagina-grens breekt. Bij heel veel groepen
        // (50+) kan het wel splitten — dan accepteren we de break liever dan
        // dat het overzicht in een lege witruimte op de vorige pagina verdwijnt.
        sectieOV = `<div class="sectie-overzicht-wrap"><h2 class="sectie-titel">${escHtml(T('deelnemers.sec_overzicht'))} &nbsp;<span class="teller">${escHtml(T('deelnemers.teller_groepen', { n: overzichtGroepen.length }))}</span></h2>
        <table><colgroup>
            <col style="width:auto"><col style="width:18mm"><col style="width:auto">
        </colgroup>
        <thead><tr>
            <th>${escHtml(T('deelnemers.col_race_groep'))}</th><th class="tc">${escHtml(T('deelnemers.col_aantal'))}</th><th>${escHtml(T('deelnemers.col_afstanden'))}</th>
        </tr></thead>
        <tbody>${ovRijen}</tbody></table></div>`;
    }

    // --- Sectie 3: Volledige deelnemerslijst ---
    const _deelnAantalTxt = T(actieveRijders.length === 1 ? 'algemeen.deelnemer_1' : 'algemeen.deelnemers_n', { n: actieveRijders.length });
    const sectie3 = `<h2 class="sectie-titel">${escHtml(T('deelnemers.titel_meervoud'))} &nbsp;<span class="teller">${escHtml(_deelnAantalTxt)}</span></h2>
    <table>${colgrpDl}${theadDl}
    <tbody>${actieveRijders.map((r, i) => rijDl(r, i)).join('')}</tbody></table>`;

    // ── 7. Volledig document ──────────────────────────────────────────────────
    // Volgorde: niet aanwezig → org-toegevoegd → geen transponder → tp-aanpassingen → met org-transponder → deelnemers
    // Eén header bovenaan, één footer onderaan, tabelkoppen herhalen via CSS thead.
    const bodyHtml = `
    <header class="doc-header">
        <div class="hdr-links">
            <div class="hdr-comp">${compNaam}</div>
            <div class="hdr-meta">${compMeta}</div>
            ${standTxt ? `<div class="hdr-stand">${escHtml(standTxt)}</div>` : ''}
        </div>
        ${baanLogoHtml ? `<div class="hdr-baan">${baanLogoHtml}</div>` : ''}
        <div class="hdr-logo">${orgLogoHtml}</div>
    </header>
    <div class="hdr-lijn"></div>
    ${sectie1}
    ${sectie0}
    ${sectieGT}
    ${sectie2}
    ${sectieSN}
    ${sectieOT}
    ${sectieOV}
    ${sectie3}
    <footer class="doc-footer">${escHtml(T('algemeen.afgedrukt', { datum: printDatum }))}</footer>`;

    const extraCss = `
@page { size: A4 portrait; margin: 10mm 12mm 12mm 12mm; }
body  { font-family: Arial, sans-serif; font-size: 8.5pt; margin: 0; color: #111; }

/* Document-header: alleen bovenaan pagina 1 */
.doc-header { display:flex; justify-content:space-between; align-items:flex-start;
              gap:4mm; margin-bottom:1.5mm; }
.hdr-baan   { flex-shrink:0; }
.hdr-links  { flex:1; }
.hdr-comp   { font-size:12pt; font-weight:bold; line-height:1.2; }
.hdr-meta   { font-size:8pt; color:#555; }
.hdr-stand  { font-size:7.5pt; color:#888; font-style:italic; }
.hdr-logo   { flex-shrink:0; }
.hdr-lijn   { border-bottom:2.5px solid #1a3a5c; margin-bottom:3mm; }

/* Sectie-titels */
.sectie-titel { font-size:10pt; font-weight:bold; margin:4mm 0 1mm 0;
                page-break-after:avoid; border-bottom:1px solid #bbb; padding-bottom:0.5mm; }
/* Race-groepen overzicht als één blok behandelen: bij voorkeur in z'n geheel
   op één pagina. Bij heel grote lijsten breekt het toch maar dat is OK. */
.sectie-overzicht-wrap { page-break-inside:avoid; break-inside:avoid; }
.sectie-titel .teller { font-size:8.5pt; font-weight:normal; color:#555; }

/* Tabellen: header herhaalt automatisch op elke nieuwe pagina */
table  { border-collapse:collapse; width:100%; margin-bottom:2mm; }
thead  { display:table-header-group; }   /* herhaal op elke pagina */
th     { background:#dce6f0; padding:0.7mm 2mm; font-size:7.5pt;
         border-bottom:1.5px solid #1a3a5c; text-align:left; line-height:1.2; }
td     { padding:0.35mm 2mm; font-size:8pt; border-bottom:1px solid #e0e0e0;
         vertical-align:middle; line-height:1.3; }
tr     { page-break-inside:avoid; }

/* Hulpklassen */
.tc    { text-align:center; }
.sm    { font-size:7.5pt; }
.rood  { color:#c00; }
.blauw { color:#1a3a5c; font-weight:500; }
.groen { color:#2a7a2a; }
.grijs { color:#888; }
.vet   { font-weight:bold; }
.z       { background:#f7f9fc; }
.z-rood  { background:#fff3f3; }
.z-blauw { background:#eef4fb; }
.z-groen { background:#eef7ee; }
.sectie-blauw   { border-bottom-color:#1a3a5c; color:#1a3a5c; }
.sectie-oranje  { border-bottom-color:#c06000; color:#c06000; }
.sectie-groen   { border-bottom-color:#2a7a2a; color:#2a7a2a; }
.tp-invul { border-bottom:1px solid #aaa !important; min-height:5mm; }

/* Document-footer: strikt onderaan, vloeit mee met content */
.doc-footer { margin-top:4mm; border-top:1px solid #ccc;
              padding-top:1.5mm; font-size:7pt; color:#888; }
`;
    return {
        bodyHtml:        bodyHtml,
        cssLinks:        ['css/tekenlijst.css'],
        extraCss:        extraCss,
        pageOrientation: 'portrait',
        title:           T('deelnemers.titel_meervoud') + ' – ' + (huidigComp.name || huidigComp.title || ''),
        subType:         T('deelnemers.titel_meervoud'),
    };
    } finally {
        // Multi-day filter: vergelijkData herstellen als die tijdelijk was
        // overschreven (zie begin van functie).
        if (typeof _vdSave !== 'undefined') vergelijkData = _vdSave;
    }
}

// ── Speakerlijsten — minimalistisch, per DC op nieuwe pagina ──────────────────
// Per Distance Combination één pagina, deelnemers gesorteerd op startnummer.
// Kolommen: #, Naam, Club, Sponsor, ruime notitie-kolom rechts.
// Bedoeld voor de speaker / aankondiger: zoveel mogelijk rijders op één
// pagina; sponsor truncate-t bij overflow. Sprint-DCs worden overgeslagen
// op basis van race_type (alle afstanden van de DC zijn 'sprint') — een
// speakerlijst wordt bij sprint niet gebruikt.

function bouwSpeakerlijstenBody() {
    if (!vergelijkData?.length || !huidigComp) return null;
    return _bouwSpeakerlijstenInternal();
}

function _bouwSpeakerlijstenInternal() {
    // i18n-helper voor Print-Center taalkeuze (NL/EN).
    const T = window._pcT || (k => k);
    const compNaam = escHtml(huidigComp.name || huidigComp.title || '');

    // ── DC-groepen verzamelen (zelfde merge-logica als groepeerVoorPrint) ─────
    // Filter: skip DCs waarvan alle afstanden race_type 'sprint' hebben.
    // Inline / afvalkoers / puntenkoers blijven.
    const usedIds = new Set();
    const dcGroepen = [];

    vergelijkData.forEach(cat => {
        if (usedIds.has(cat.dc_id)) return;
        usedIds.add(cat.dc_id);

        let dcGroup = [cat];
        if (cat.merge_group) {
            vergelijkData.forEach(c => {
                if (!usedIds.has(c.dc_id) && c.merge_group === cat.merge_group) {
                    usedIds.add(c.dc_id);
                    dcGroup.push(c);
                }
            });
        }

        // Sprint-filter via race_type. Skip DC als ALLE afstanden 'sprint' zijn;
        // inline, afvalkoers en puntenkoers blijven erin.
        //
        // LET OP voor split-DCs: dcDistances[dc.dc_id] is leeg, de echte
        // afstanden zitten onder dcDistances[dc.dc_id::splitgroep]. Dus we
        // verzamelen alle keys die met dc.dc_id beginnen. KNSB-fallback heeft
        // geen race_type en zou anders alles als 'sprint' tellen → DC ten
        // onrechte geskipt.
        const heeftNietSprint = dcGroup.some(dc => {
            const prefix = dc.dc_id + '::';
            const splitAfs = Object.keys(dcDistances)
                .filter(k => k.startsWith(prefix))
                .flatMap(k => dcDistances[k] || []);
            const bron = dcDistances[dc.dc_id]?.length
                ? dcDistances[dc.dc_id]
                : splitAfs.length
                    ? splitAfs
                    : (dc.knsb_distances || []);
            return bron.some(d => (d.race_type || 'sprint') !== 'sprint');
        });
        if (!heeftNietSprint) return;

        const basisNaam = (dcGroup.find(d => d.merge_label) ?? {}).merge_label
                       || dcGroup.map(d => d.dc_name).filter(Boolean).join(' + ');

        // Splits-mapping over hele merge-groep verzamelen: { categorie: split_group }
        // Bij gesplitste DCs krijgt elke split-groep een eigen speakerlijst —
        // anders heeft de speaker alle rijders van beide splits door elkaar.
        const allSplits = {};
        dcGroup.forEach(dc => {
            Object.entries(dc.splits || {}).forEach(([k, v]) => { if (v) allSplits[k] = v; });
        });
        const heeftSplits = Object.keys(allSplits).length > 0;

        // Per splitgroep een deelnemers-array. Bij geen splits: alles in '_alle'.
        const perSplit = {};
        const seenLk = new Set();
        dcGroup.forEach(dc => {
            dc.competitors.forEach((c, idx) => {
                const lk = c.license_key || null;
                const ek = lk ? (dc.dc_id + '_' + lk) : null;
                const ee = (ek && entryEdits[ek]) || {};
                const st = Number(ee.entry_status ?? c.entry_status ?? 1);
                if (st !== 1 && st !== 5) return;

                // Dedupe over merge-DCs (rijder kan in beide DCs staan)
                const sleutel = lk ?? `${dc.dc_id}::${idx}`;
                if (seenLk.has(sleutel)) return;
                seenLk.add(sleutel);

                const pe = lk ? (personEdits[lk] || {}) : {};
                const rijderCat = pe.category ?? c.knsb?.category ?? '';
                // Bepaal splitgroep: lookup categorie in allSplits. Rijders
                // waarvan de cat NIET in splits voorkomt → '_geen' (krijgen
                // eigen sectie zodat ze niet onzichtbaar worden).
                const sg = heeftSplits
                    ? (allSplits[rijderCat] || '_geen')
                    : '_alle';
                if (!perSplit[sg]) perSplit[sg] = [];
                // Club: gebruik club_full voor de speaker (uitspreken!), val
                // terug op club_short als full leeg is.
                const clubFull  = pe.club_full  ?? c.knsb?.club_full  ?? '';
                const clubShort = pe.club_short ?? c.knsb?.club_short ?? '';
                perSplit[sg].push({
                    start_number: pe.start_number ?? c.knsb?.start_number ?? '',
                    full_name:    pe.full_name    ?? c.knsb?.full_name    ?? '',
                    nationality:  pe.nationality  ?? c.knsb?.nationality  ?? '',
                    club:         clubFull || clubShort,
                    sponsor:      pe.sponsor      ?? c.knsb?.sponsor      ?? '',
                });
            });
        });

        const sortSn = arr => arr.sort((a, b) =>
            (Number(a.start_number) || 9999) - (Number(b.start_number) || 9999));

        if (heeftSplits) {
            // Per splitgroep een eigen pagina. Splits zijn altijd per
            // categorie — volgorde moet de natuurlijke KNSB-cat-volgorde
            // volgen: jongst → oud per leeftijdsgroep (P/A/J/S/M), dames vóór
            // heren binnen dezelfde leeftijdsklasse.
            //
            // Sorteer-key per cat = [leeftijdsgroep, leeftijdsklasse, geslacht]
            //   1e letter: D=0, H=1, N=2 → dames eerst
            //   2e letter: P=0, A=1, J=2, S=3, M=4 → pupil eerst
            //   3e letter: A=0, B=1, C=2 → A eerst binnen leeftijdsgroep
            // Tussen leeftijdsklasse en geslacht zorgt de volgorde-prioriteit
            // dat we eerst DPA → HPA → DPB → HPB krijgen (= "per cat dames
            // vóór heren") in plaats van DPA → DPB → DPC → HPA → HPB → HPC.
            const catKey = (cat) => {
                if (!cat) return [99, 99, 99];
                const c = String(cat).toUpperCase();
                const geslacht = { D: 0, H: 1, N: 2 }[c[0]] ?? 9;
                const leeftijd = { P: 0, A: 1, J: 2, S: 3, M: 4 }[c[1]] ?? 9;
                const klasse   = c[2] ? c.charCodeAt(2) - 65 : 0;
                return [leeftijd, klasse, geslacht];
            };
            // Voor elke splitgroep: pak de cat(s) die erin zitten, neem de
            // sortKey van de eerste/jongste. Per splitgroep is dat meestal
            // 1 cat; bij meerdere wint de jongste.
            const catsPerSplit = new Map(); // sg -> [cat, cat, ...]
            Object.entries(allSplits).forEach(([cat, sg]) => {
                if (!catsPerSplit.has(sg)) catsPerSplit.set(sg, []);
                catsPerSplit.get(sg).push(cat);
            });
            const sgVergelijk = (a, b) => {
                const ka = (catsPerSplit.get(a) || ['']).map(catKey).sort()[0] || [99,99,99];
                const kb = (catsPerSplit.get(b) || ['']).map(catKey).sort()[0] || [99,99,99];
                return (ka[0] - kb[0]) || (ka[1] - kb[1]) || (ka[2] - kb[2])
                    || String(a).localeCompare(String(b), 'nl');
            };
            const splitNamen = [...new Set(Object.values(allSplits))].sort(sgVergelijk);
            splitNamen.forEach(sg => {
                const ds = sortSn(perSplit[sg] || []);
                if (ds.length === 0) return;
                dcGroepen.push({ naam: `${basisNaam} — ${sg}`, deelnemers: ds });
            });
            if (perSplit['_geen']?.length) {
                dcGroepen.push({
                    naam: `${basisNaam} — ${T('algemeen.geen_split')}`,
                    deelnemers: sortSn(perSplit['_geen']),
                });
            }
        } else {
            const ds = sortSn(perSplit['_alle'] || []);
            if (ds.length === 0) return;
            dcGroepen.push({ naam: basisNaam, deelnemers: ds });
        }
    });

    // ── HTML opbouwen — één .sp-pagina per DC ────────────────────────────────
    const paginas = dcGroepen.map(g => {
        const rijen = g.deelnemers.map(d => `
            <tr>
                <td class="sn">${escHtml(String(d.start_number))}</td>
                <td class="nm">${escHtml(d.full_name)}</td>
                <td class="nt-l">${escHtml(d.nationality)}</td>
                <td class="cl">${escHtml(d.club)}</td>
                <td class="sp">${escHtml(d.sponsor)}</td>
                <td class="nt"></td>
            </tr>`).join('');

        const _aantalTxt = T(g.deelnemers.length === 1 ? 'algemeen.deelnemer_1' : 'algemeen.deelnemers_n', { n: g.deelnemers.length });
        return `<div class="sp-pagina">
            <div class="sp-dc-titel">
                ${escHtml(g.naam)}
                <span class="sub">— ${escHtml(_aantalTxt)} · ${compNaam}</span>
            </div>
            <table class="sp-table">
                <colgroup>
                    <col style="width:10mm">
                    <col style="width:60mm">
                    <col style="width:12mm">
                    <col style="width:65mm">
                    <col style="width:65mm">
                    <col style="width:auto">
                </colgroup>
                <thead><tr>
                    <th class="sn">#</th>
                    <th>${escHtml(T('algemeen.naam'))}</th>
                    <th>${escHtml(T('algemeen.nationaliteit'))}</th>
                    <th>${escHtml(T('algemeen.club'))}</th>
                    <th>${escHtml(T('speaker.col_sponsor'))}</th>
                    <th>${escHtml(T('speaker.col_notities'))}</th>
                </tr></thead>
                <tbody>${rijen}</tbody>
            </table>
        </div>`;
    }).join('');

    const bodyHtml = paginas || `<div class="sp-pagina"><p style="color:#888;font-style:italic">${escHtml(T('speaker.geen_dcs'))}</p></div>`;

    const extraCss = `
@page { size: A4 landscape; margin: 6mm 8mm; }
body  { font-family: Arial, sans-serif; font-size: 11pt; margin: 0; color: #111; }

/* Iedere DC op een eigen pagina */
.sp-pagina { page-break-after: always; }
.sp-pagina:last-of-type { page-break-after: auto; }

/* DC-titelbalk: zo compact mogelijk om hoogte vrij te spelen voor rijen */
.sp-dc-titel {
    font-size: 12pt; font-weight: bold; color: #1a3a5c;
    border-bottom: 1.2px solid #1a3a5c;
    padding-bottom: 0.3mm; margin-bottom: 1mm;
}
.sp-dc-titel .sub {
    font-size: 9pt; font-weight: normal; color: #555;
    margin-left: 2mm;
}

/* Tabel: compact — zoveel mogelijk rijders op één pagina. */
.sp-table {
    width: 100%; border-collapse: collapse;
    table-layout: fixed;  /* nodig voor ellipsis op sponsor-kol */
}
.sp-table thead { display: table-header-group; }
.sp-table th {
    background: #dce6f0; color: #1a3a5c;
    font-size: 8.5pt; font-weight: 600; text-align: left;
    padding: 0.4mm 1.5mm;
    border-bottom: 1.2px solid #1a3a5c;
}
.sp-table td {
    padding: 0.9mm 1.5mm;
    border-bottom: 1px solid #d8d8d8;
    font-size: 11pt;
    vertical-align: middle;
    line-height: 1.1;
}
.sp-table tr { page-break-inside: avoid; }

/* Kolom-stijlen */
.sp-table .sn { text-align: center; font-weight: bold; }
.sp-table .nm {
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sp-table .nt-l {
    text-align: center; font-size: 10pt; color: #555;
    font-variant-numeric: tabular-nums;
}
.sp-table .cl {
    font-size: 10pt; color: #555;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sp-table .sp {
    font-size: 10pt; color: #555;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    /* Verticale lijn op grens sponsor → notities */
    border-right: 1px solid #888;
}
.sp-table th:nth-child(5) {
    /* Header-cell voor sponsor: zelfde grens-lijn doortrekken naar header */
    border-right: 1px solid #888;
}
.sp-table .nt { /* notitie-kolom: leeg, voor handschrift */ }
`;

    return {
        bodyHtml:        bodyHtml,
        cssLinks:        [],
        extraCss:        extraCss,
        pageOrientation: 'landscape',
        title:           T('speaker.titel_meervoud') + ' – ' + (huidigComp.name || huidigComp.title || ''),
        subType:         T('speaker.titel_meervoud'),
    };
}

// ── Tijdstempel ───────────────────────────────────────────────────────────────

function zetKnsbTimestamp() {
    const ts = el('knsb-sync-info');
    if (!ts) return;
    const nu = new Date().toLocaleString('nl-NL', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
    ts.innerHTML = `<span class="knsb-ts">&#128260; KNSB: ${nu}</span>`;
}

// ── Rij markeren als gewijzigd ────────────────────────────────────────────────

function markeerGewijzigd(row) {
    heeftWijzigingen = true;
    updateImportBtn();
    if (!row) return;
    row.classList.add('row-modified');
    if (row.dataset.lk) gewijzigdeRijen.add(row.dataset.lk);
}

// ── Herlaad vergelijking na import ───────────────────────────────────────────

async function herlaadVergelijking() {
    // Handmatige wedstrijden: gebruik detail-endpoint (geen KNSB-feed).
    // huidigComp.is_handmatig wordt in app.js gezet bij selectie.
    const isHandmatig = !!(typeof huidigComp !== 'undefined' && huidigComp?.is_handmatig);
    setHTML('imp-cat-content',
        `<div class="status-msg loading"><span class="spinner"></span>${
            isHandmatig ? 'Categorieën laden…' : 'Synchroniseren met KNSB…'
        }</div>`
    );
    try {
        const endpoint = isHandmatig
            ? 'api/wedstrijd_handmatig.php?action=detail&id=' + encodeURIComponent(huidigCompId)
            : 'api/vergelijk.php?id=' + encodeURIComponent(huidigCompId);
        const res   = await fetch(endpoint);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const vData = await res.json();
        if (vData.error) throw new Error(vData.error);
        vergelijkData     = vData.groepen     ?? vData;
        huidigOrganisatie = vData.organisatie ?? huidigOrganisatie;
        huidigBaan        = vData.baan        ?? null;
        huidigImported    = !!vData.imported;
        entriesVersion    = vData.entries_version ?? 0;
        _heeftProgramma   = !!(vData.heeft_programma);
        _orgTransponders  = vData.org_transponders ?? [];
        // knsb_stand: server stuurt null → genereer lokale browsertijd
        standDatum   = new Date().toLocaleString('nl-NL', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
        // db_stand: server stuurt UTC datetime → parseer met 'Z' suffix naar lokale tijd
        if (vData.db_stand) {
            const utc = new Date(vData.db_stand.replace(' ', 'T') + 'Z');
            dbStandDatum = utc.toLocaleString('nl-NL', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit',
            });
        }
        zetKnsbTimestamp();
        initEdits();
        bouwVergelijkTabbladen();
        updateImportBtn();
        if (typeof renderBaanRij === 'function') renderBaanRij();
    } catch(e) {
        setHTML('imp-cat-content',
            `<div class="status-msg error">⚠ Synchronisatie mislukt: ${escHtml(e.message)}</div>`
        );
    }
}

function herlaadVergelijk() {
    // Wijzigingen zijn door het conflict verloren — heeftWijzigingen resetten
    // zodat de "onopgeslagen wijzigingen" popup niet verschijnt (die geeft valse hoop)
    heeftWijzigingen = false;
    if (huidigComp) selectWedstrijd(activeCard, huidigComp);
}

// ── Meld-badge helpers ────────────────────────────────────────────────────────
// Retourneert een array met redenen waarom deze rijder zich persoonlijk moet
// melden (zelfde logica als de ⚠️ op de tekenlijst). Leeg = geen melding.
function _berekenMeldingen(status, transponderActief, startnr) {
    const meldingen = [];
    const sn = Number(startnr);

    if (status === 0) meldingen.push('Status: nog niet bevestigd');
    if (!transponderActief) meldingen.push('Geen transponder');
    if (sn && sn >= 1000) meldingen.push(`Gast-startnummer (${sn})`);

    if (transponderActief) {
        const orgTp = (_orgTransponders || []).find(
            ot => ot.transponder_code === transponderActief
        );
        if (orgTp && parseInt(orgTp.betaald) !== 1) {
            meldingen.push('Organisatie-transponder nog niet betaald');
        }
    }
    return meldingen;
}

// Herbereken en werk de meld-badge van een enkele rij bij (na dropdown-wijziging,
// status-wissel, startnr-wissel, etc.)
function _hertekenMeldBadge(tr) {
    if (!tr) return;
    const badgesTd = tr.querySelector('.td-badges');
    if (!badgesTd) return;

    const lk       = tr.dataset.lk;
    const pe       = personEdits[lk] || {};
    const snInp    = tr.querySelector('input[data-field="start_number"]');
    const sn       = snInp ? snInp.value : (pe.start_number ?? '');
    const actief   = pe.transponder_actief;
    const statusEl = tr.querySelector('.status-badge');
    const stCls    = statusEl ? statusEl.className : '';
    // Status uit classname matchen (STATUS_CSS heeft sleutels 0..5)
    let st = 1;
    for (const [k, v] of Object.entries(STATUS_CSS)) {
        if (stCls.includes(v)) { st = Number(k); break; }
    }

    const meldingen = _berekenMeldingen(st, actief, sn);
    const oude      = badgesTd.querySelector('.badge-meld');
    if (meldingen.length) {
        const tooltip = 'Graag persoonlijk melden:\n• ' + meldingen.join('\n• ');
        if (oude) {
            oude.title = tooltip;
        } else {
            const span = document.createElement('span');
            span.className = 'badge-meld';
            span.title     = tooltip;
            span.textContent = 'ⓘ';
            badgesTd.appendChild(span);
        }
    } else if (oude) {
        oude.remove();
    }
}

// ── Transponder helpers ───────────────────────────────────────────────────────

// textraPopup bestaat nog zodat app.js (click-buiten handler) er naar kan verwijzen
let textraPopup = null;
function sluitTextraPopup() {
    if (textraPopup) { textraPopup.remove(); textraPopup = null; }
}

// Bouw de HTML voor de transponder-dropdown + '+' knop + org-opzoek
function maakTpDropdownHtml(lk, t1, t2, extras, actief, startnr) {
    let opts = `<option value=""${!actief ? ' selected' : ''}>— geen —</option>`;
    if (t1) opts += `<option value="${escHtml(t1)}"${actief === t1 ? ' selected' : ''}>T1 – ${escHtml(t1)}</option>`;
    if (t2) opts += `<option value="${escHtml(t2)}"${actief === t2 ? ' selected' : ''}>T2 – ${escHtml(t2)}</option>`;
    for (const e of (extras || [])) {
        opts += `<option value="${escHtml(e)}"${actief === e ? ' selected' : ''}>Textra – ${escHtml(e)}</option>`;
    }
    // Org-transponders: alleen vrije (niet toegewezen aan andere rijder) + eigen.
    // Een transponder is "in gebruik" als er OFWEL een startnr OFWEL een naam
    // aan hangt. Op die manier blijft 'ie ook terecht geblokkeerd als startnr
    // null of 0 is maar er wel een naam bekend is.
    const _isToegewezen = ot => {
        const s = ot.toegewezen_snr;
        const n = (ot.toegewezen_naam ?? '').toString().trim();
        return (s !== null && s !== undefined && s !== '') || n !== '';
    };
    const alleOpties = new Set([t1, t2, ...(extras || [])].filter(Boolean));
    if (_orgTransponders.length) {
        const vrije = _orgTransponders.filter(ot =>
            !_isToegewezen(ot)
            || (startnr && ot.toegewezen_snr == startnr)
            || ot.transponder_code === actief
        );
        if (vrije.length) {
            opts += `<optgroup label="Org transponders">`;
            for (const ot of vrije) {
                if (alleOpties.has(ot.transponder_code)) continue; // al als T1/T2/Textra getoond
                const lbl = `#${ot.intern_nummer} – ${ot.transponder_code}`;
                opts += `<option value="${escHtml(ot.transponder_code)}"${actief === ot.transponder_code ? ' selected' : ''}>${escHtml(lbl)}</option>`;
                alleOpties.add(ot.transponder_code);
            }
            opts += `</optgroup>`;
        }
    }
    // Vangnet: als actief een waarde heeft die nergens in de dropdown zit, toch tonen
    if (actief && !alleOpties.has(actief)) {
        opts += `<option value="${escHtml(actief)}" selected>${escHtml(actief)}</option>`;
    }
    // Tooltip-samenvatting: wat heeft deze rijder aan transponders beschikbaar?
    // Helpt de voorbereider in één oogopslag zien dat bv. zowel eigen T1 als
    // een club-transponder bestaan, ook als er nu maar één is geselecteerd.
    const tipRegels = [];
    if (t1)         tipRegels.push(`Eigen T1: ${t1}`);
    if (t2)         tipRegels.push(`Eigen T2: ${t2}`);
    if (extras?.length) tipRegels.push(`Extra: ${extras.join(', ')}`);
    const orgToew = _orgTransponders.find(ot =>
        String(ot.toegewezen_snr ?? '') === String(startnr ?? '') &&
        (ot.toegewezen_naam ?? '').trim()
    );
    if (orgToew) tipRegels.push(`Club-transponder #${orgToew.intern_nummer}: ${orgToew.transponder_code}`);
    const tipTitel = tipRegels.length
        ? 'Transponders van deze rijder:\n• ' + tipRegels.join('\n• ')
        : 'Geen transponders bekend bij deze rijder';
    return `<div class="tp-sel-wrap">
        <select class="inp tp-sel-drop" data-lk="${escHtml(lk)}" data-prev="${escHtml(actief || '')}"
                title="${escHtml(tipTitel)}">${opts}</select>
        <button class="tp-add-btn" data-lk="${escHtml(lk)}" title="Transponder toevoegen">+</button>
    </div>`;
}

// Ververs org-transponder optgroups in alle dropdowns na selectie.
// Gebruikt _orgTransponders[i].toegewezen_snr (live bijgewerkt bij dropdown-change)
// zodat toewijzingen in andere categorie-tabs ook hier in de filter worden meegenomen.
function _vervrisOrgTpOpties(content) {
    if (!_orgTransponders.length) return;
    content.querySelectorAll('.tp-sel-drop').forEach(sel => {
        const huidigeWaarde = sel.value;
        const row           = sel.closest('tr');
        const snrInp        = row?.querySelector('input[data-field="start_number"]');
        const eigenSn       = snrInp ? (parseInt(snrInp.value) || null) : null;

        // Verwijder bestaande optgroup
        sel.querySelector('optgroup')?.remove();

        // Vrij = niet toegewezen (GEEN snr EN GEEN naam), OF aan deze rijder,
        // OF de eigen huidige selectie (zodat die altijd in de lijst staat).
        const vrije = _orgTransponders.filter(ot => {
            const s = ot.toegewezen_snr;
            const n = (ot.toegewezen_naam ?? '').toString().trim();
            const toegewezen = (s !== null && s !== undefined && s !== '') || n !== '';
            return !toegewezen
                || (eigenSn !== null && s == eigenSn)
                || ot.transponder_code === huidigeWaarde;
        });
        if (vrije.length) {
            const grp = document.createElement('optgroup');
            grp.label = 'Org transponders';
            for (const ot of vrije) {
                const opt = document.createElement('option');
                opt.value = ot.transponder_code;
                opt.textContent = `#${ot.intern_nummer} – ${ot.transponder_code}`;
                if (ot.transponder_code === huidigeWaarde) opt.selected = true;
                grp.appendChild(opt);
            }
            sel.appendChild(grp);
        }
    });
}

// Bouw de opties van een bestaande <select> opnieuw op
function hertekenTpDropdown(sel, t1, t2, extras, actief) {
    let opts = `<option value=""${!actief ? ' selected' : ''}>— geen —</option>`;
    if (t1) opts += `<option value="${escHtml(t1)}"${actief === t1 ? ' selected' : ''}>T1 – ${escHtml(t1)}</option>`;
    if (t2) opts += `<option value="${escHtml(t2)}"${actief === t2 ? ' selected' : ''}>T2 – ${escHtml(t2)}</option>`;
    for (const e of (extras || [])) {
        opts += `<option value="${escHtml(e)}"${actief === e ? ' selected' : ''}>Textra – ${escHtml(e)}</option>`;
    }
    sel.innerHTML = opts;
}

// Inline invoer voor nieuwe transponder via '+' knop
function voegTpToe(lk, btn, content) {
    if (btn.nextElementSibling?.classList.contains('tp-nieuw-inp')) return;

    const inp = document.createElement('input');
    inp.type        = 'text';
    inp.className   = 'inp tp-nieuw-inp';
    inp.placeholder = 'Code…';
    inp.maxLength   = 20;
    btn.after(inp);
    inp.focus();

    const commit = () => {
        const val = inp.value.trim().toUpperCase();
        inp.remove();
        if (!val) return;

        if (!personEdits[lk]) personEdits[lk] = {};
        if (!personEdits[lk].transponders_extra) personEdits[lk].transponders_extra = [];
        if (!personEdits[lk].transponders_extra.includes(val)) {
            personEdits[lk].transponders_extra.push(val);
        }
        personEdits[lk].transponder_actief = val;
        markeerGewijzigd(btn.closest('tr'));

        // Dropdown opnieuw opbouwen en nieuwe waarde selecteren
        const sel = content.querySelector(`.tp-sel-drop[data-lk="${CSS.escape(lk)}"]`);
        if (sel) {
            const pe = personEdits[lk];
            hertekenTpDropdown(sel, pe.transponder1, pe.transponder2, pe.transponders_extra, val);
            sel.dataset.prev = val;  // synchroon houden voor de volgende change
        }
    };

    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') commit();
        if (e.key === 'Escape') inp.remove();
    });
    inp.addEventListener('blur', () => setTimeout(() => inp.remove(), 200));
}

// ── Importdata verzamelen ─────────────────────────────────────────────────────

function collectImportData(compId) {
    const categories = [];

    for (const cat of vergelijkData) {
        const competitors = [];

        for (const item of cat.competitors) {
            const lk = item.license_key;
            if (!lk) continue;

            const pe = personEdits[lk]              || {};
            const ek = cat.dc_id + '_' + lk;
            const ee = entryEdits[ek]               || {};

            competitors.push({
                license_key:    lk,
                knsb_entry_id:  item.knsb_entry_id  ?? null,
                entry_status:   ee.entry_status      ?? 1,
                reserve:        ee.reserve           ?? null,
                start_number:   pe.start_number      ?? item.knsb.start_number,
                full_name:      pe.full_name         ?? item.knsb.full_name,
                short_name:     pe.short_name        ?? item.knsb.short_name,
                gender:         pe.gender            ?? item.knsb.gender,
                category:       pe.category         ?? item.knsb.category,
                nationality:    pe.nationality       ?? item.knsb.nationality,
                club_code:      pe.club_code         ?? item.knsb.club_code,
                club_short:     pe.club_short        ?? item.knsb.club_short,
                club_full:      pe.club_full         ?? item.knsb.club_full,
                sponsor:        pe.sponsor           ?? item.knsb.sponsor,
                city:           pe.city              ?? item.knsb.city,
                transponder1:       item.knsb.transponder1,
                transponder2:       item.knsb.transponder2,
                transponders_extra: pe.transponders_extra  ?? [],
                transponder_actief: pe.transponder_actief  ?? null,
                tp_betaald:         pe.tp_betaald          ?? null,
            });
        }

        categories.push({ dc_id: cat.dc_id, competitors });
    }

    return { competition_id: compId, categories, entries_version: entriesVersion ?? 0 };
}

// ── Import naar database ──────────────────────────────────────────────────────

// ── Exporteer wedstrijd-deelnemers als KNSB-CSV ──────────────────────────────
// De export werkt op DB-data, niet op de frontend-state — daarom is de knop
// in updateExportBtn() geblokkeerd zolang er onopgeslagen wijzigingen zijn.
// Zo voorkomen we dat een export niet matcht met wat in de DB / Live-app staat.
//
// Vóór de download wordt vergelijkData gecontroleerd op aanwezige rijders
// zonder transponder (DB-state); appConfirm-dialog als die er zijn.
async function exporteerWedstrijdCsv(compId, compNaam) {
    if (!compId) return;

    // Aanwezig = entry_status NOT IN (3=afgemeld, 4=niet getekend). Default 1.
    // Dedupe op license_key. Transponder uit DB-state (db_tp_actief), niet uit
    // KNSB-feed of frontend-edits — dat matcht wat de export uit de DB haalt.
    const zonderTp = new Map();
    (vergelijkData || []).forEach(cat => {
        (cat.competitors || []).forEach(c => {
            const status = c.entry_status ?? 1;
            if (status === 3 || status === 4) return;
            const tp = (c.db_tp_actief ?? '').toString().trim();
            if (tp !== '') return;
            const lic = c.license_key || '';
            if (!zonderTp.has(lic)) {
                zonderTp.set(lic, (c.db_person?.full_name) || c.knsb?.full_name || lic);
            }
        });
    });

    if (zonderTp.size > 0) {
        const namen = Array.from(zonderTp.values()).sort((a, b) => a.localeCompare(b));
        const max   = 15;
        const itemsHtml = namen.slice(0, max)
            .map(n => `<li>${escHtml(n)}</li>`)
            .join('');
        const restHtml = namen.length > max
            ? `<div class="lijst-rest">… en nog ${namen.length - max} andere(n)</div>`
            : '';
        const aantalTxt = `${zonderTp.size} aanwezige rijder${zonderTp.size === 1 ? '' : 's'} ` +
                          `${zonderTp.size === 1 ? 'heeft' : 'hebben'} geen transponder toegewezen`;
        const body = `
            <p><strong>${escHtml(aantalTxt)}:</strong></p>
            <ul>${itemsHtml}</ul>${restHtml}
            <p>Toch exporteren? Voor deze rijders blijft Transponder1/Transponder2 leeg in de CSV.</p>`;

        const ok = await toonBevestigDialog(
            body,
            'Rijders zonder transponder',
            'Toch exporteren',
            'Annuleren',
            { bodyIsHtml: true }
        );
        if (!ok) return;
    }

    // Bestandsnaam: <wedstrijd>_YYYY-MM-DD_HHhMM.csv — tijdstempel zodat
    // duidelijk is wanneer de export gedraaid is. We sturen de browser-
    // lokale tijd mee als ?t= zodat de server de identieke filename gebruikt
    // in Content-Disposition (anders zou daar UTC of een andere server-TZ
    // verschijnen).
    const d  = new Date();
    const pad = n => String(n).padStart(2, '0');
    const tijdstempel = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}_` +
                        `${pad(d.getHours())}h${pad(d.getMinutes())}`;
    const safeName = (compNaam || 'wedstrijd').replace(/[^A-Za-z0-9_\- ]/g, '_');
    const url = 'api/import.php?action=export_knsb_csv' +
                '&competition_id=' + encodeURIComponent(compId) +
                '&t=' + encodeURIComponent(tijdstempel);
    const a = document.createElement('a');
    a.href     = url;
    a.download = `${safeName}_${tijdstempel}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

async function importeerWedstrijd(compId, compNaam) {
    const resultDiv = el('import-result');
    const btn       = el('btn-import');

    if (!vergelijkData || !vergelijkData.length) {
        resultDiv.innerHTML = '<div class="status-msg error">⚠ Laad eerst een wedstrijd</div>';
        return;
    }

    btn.disabled = true;
    resultDiv.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Importeren…</div>';

    try {
        const payload = collectImportData(compId);
        const res     = await fetch('api/import.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await res.json();

        if (res.status === 409 || data.error === 'conflict') {
            resultDiv.innerHTML =
                `<div class="status-msg warning">
                    ⚠ <strong>Conflict:</strong> ${escHtml(data.message || 'Inschrijvingen gewijzigd door iemand anders.')}
                    <br><small style="opacity:.8">Jouw niet-opgeslagen wijzigingen gaan verloren bij het herladen.</small>
                    <button class="btn-secondary" onclick="herlaadVergelijk()" style="margin-left:8px">↺ Herlaad</button>
                 </div>`;
            btn.disabled = false;
        } else if (!res.ok || data.error) {
            resultDiv.innerHTML =
                `<div class="status-msg error">⚠ Import mislukt: ${escHtml(data.error || 'onbekende fout')}</div>`;
            btn.disabled = false;
        } else {
            if (data.entries_version != null) entriesVersion = data.entries_version;
            const logHtml = (data.log || []).map(r => `<li>${escHtml(r)}</li>`).join('');
            // Details openklappen → automatisch inklappen na 4s, maar de
            // volledige log blijft onder het summary-knopje beschikbaar
            // zodat de user 'm later nog kan terugzien.
            resultDiv.innerHTML =
                `<details class="status-msg ok import-log-details" open>
                    <summary>
                        ✔ <strong>${escHtml(compNaam)}</strong> geïmporteerd
                        <span class="import-log-hint">· klik voor details</span>
                    </summary>
                    <ul class="import-log">${logHtml}</ul>
                 </details>`;
            setTimeout(() => {
                const det = resultDiv?.querySelector('details.import-log-details');
                if (det) det.open = false;
            }, 4000);
            isGeimporteerd   = true;
            heeftWijzigingen = false;
            // Automatisch resync met KNSB — toont nieuwe inschrijvingen en bijgewerkte diffs
            await herlaadVergelijking();
        }
    } catch(e) {
        resultDiv.innerHTML =
            `<div class="status-msg error">⚠ Verbindingsfout: ${escHtml(e.message)}</div>`;
        btn.disabled = false;
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// ── Deelnemer handmatig toevoegen ─────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════════════════════

let _modalDcId        = null;   // actief DC-id terwijl modal open is
let _clubsCache       = null;   // [{club_full, club_short}] eenmalig geladen
let _catOverride      = false;  // true = gebruiker heeft categorie-waarschuwing bevestigd
let _tpBevestigd      = false;  // true = gebruiker heeft onbekende transponder bevestigd
let _personZoekResult = null;   // laatste zoekresultaat (bevat bekende transponders)

function initDeelnemerModal() {
    const div = document.createElement('div');
    div.innerHTML = `
<div class="modal-overlay" id="modal-deelnemer" style="display:none">
  <div class="modal-box">
    <div class="modal-kop">
      <div>
        <h3>Deelnemer toevoegen</h3>
        <div class="modal-subtitel" id="modal-dc-naam-lbl"></div>
      </div>
      <button class="btn-del" id="modal-sluiten" title="Sluiten">&times;</button>
    </div>

    <div class="modal-zoek-sectie">
      <div class="modal-ztabs">
        <button class="modal-ztab active" data-ztab="relatie">Op relatienummer</button>
        <button class="modal-ztab"        data-ztab="startnr">Op startnr + categorie</button>
      </div>
      <div id="mz-relatie" class="mz-invoer">
        <input type="text"   id="mz-lk"  class="inp" placeholder="Relatienummer…" style="flex:1">
        <button class="btn-secondary" id="mz-lk-btn">Zoeken</button>
      </div>
      <div id="mz-startnr" class="mz-invoer" style="display:none">
        <input type="number" id="mz-sn"  class="inp" placeholder="Startnr" style="width:80px;flex:0">
        <input type="text"   id="mz-cat" class="inp" placeholder="Categorie (bijv. DKA)" style="flex:1">
        <button class="btn-secondary" id="mz-sn-btn">Zoeken</button>
      </div>
      <div id="mz-status" class="mz-status"></div>
    </div>

    <div class="modal-form-sectie">
      <div class="mf-rij mf-2col">
        <label class="mf-lbl"><span>Relatienummer</span>
          <input type="text" id="f-dt-lk" class="inp" placeholder="leeg = geen KNSB-lid">
        </label>
        <label class="mf-lbl"><span>Startnummer <span class="vereist">*</span></span>
          <input type="number" id="f-dt-sn" class="inp" required min="1">
          <span class="mf-hint">💡 Gebruik <strong>1001+</strong> voor gastrijders zonder KNSB-nummer. Organisaties die hun eigen G/0-prefix willen tonen op prints kunnen dat in een komende versie per-org instellen.</span>
        </label>
      </div>
      <div class="mf-rij mf-2col">
        <label class="mf-lbl"><span>Voornaam <span class="vereist">*</span></span>
          <input type="text" id="f-dt-voornaam" class="inp" required>
        </label>
        <label class="mf-lbl"><span>Achternaam <span class="vereist">*</span></span>
          <input type="text" id="f-dt-kort" class="inp" required>
        </label>
      </div>
      <div class="mf-rij" style="display:none">
        <input type="hidden" id="f-dt-naam">
      </div>
      <div class="mf-rij mf-2col">
        <label class="mf-lbl"><span>Categorie <span class="vereist">*</span></span>
          <input type="text" id="f-dt-cat" class="inp" required placeholder="bijv. DKA">
        </label>
      </div>
      <div class="mf-rij mf-2col">
        <label class="mf-lbl"><span>Nationaliteit <span class="vereist">*</span></span>
          <input type="text" id="f-dt-nat" class="inp" value="NED" required maxlength="3">
        </label>
        <label class="mf-lbl"><span>Geslacht <span class="vereist">*</span></span>
          <select id="f-dt-gender" class="inp" required>
            <option value="">— kies —</option>
            <option value="0">Man</option>
            <option value="1">Vrouw</option>
          </select>
        </label>
      </div>
      <div class="mf-rij mf-2col">
        <label class="mf-lbl"><span>Club (volledig)</span>
          <input type="text" id="f-dt-club" class="inp" list="modal-clubs-dl" placeholder="optioneel">
        </label>
        <label class="mf-lbl"><span>Club (kort)</span>
          <input type="text" id="f-dt-club-kort" class="inp" placeholder="optioneel" maxlength="20">
        </label>
      </div>
      <div class="mf-rij">
        <label class="mf-lbl"><span>Transponder</span>
          <input type="text" id="f-dt-tp" class="inp" placeholder="optioneel">
        </label>
      </div>
      <div id="f-dt-tp-check" class="status-msg warning" style="display:none;margin-top:.3rem">
        <div id="f-dt-tp-check-tekst"></div>
        <div style="display:flex;gap:6px;margin-top:6px">
          <button class="btn-primary"   id="f-dt-tp-ja">Ja, klopt</button>
          <button class="btn-secondary" id="f-dt-tp-nee">Nee, corrigeren</button>
        </div>
      </div>
      <datalist id="modal-clubs-dl"></datalist>
      <div id="modal-waarsch" class="status-msg warning" style="display:none;margin-top:.5rem;"></div>
    </div>

    <div class="modal-footer">
      <button class="btn-secondary" id="modal-dt-annuleer">Annuleren</button>
      <button class="btn-primary"   id="modal-dt-bevestig">Toevoegen</button>
    </div>
  </div>
</div>`;
    document.body.appendChild(div.firstElementChild);

    // Zoek-tab wisselen
    document.querySelectorAll('.modal-ztab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.modal-ztab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.ztab;
            el('mz-relatie').style.display = tab === 'relatie' ? '' : 'none';
            el('mz-startnr').style.display = tab === 'startnr' ? '' : 'none';
        });
    });

    el('mz-lk-btn').addEventListener('click', () => zoekPersoon('relatie'));
    el('mz-sn-btn').addEventListener('click', () => zoekPersoon('startnr'));
    el('mz-lk') .addEventListener('keydown', e => { if (e.key === 'Enter') zoekPersoon('relatie'); });
    el('mz-sn') .addEventListener('keydown', e => { if (e.key === 'Enter') zoekPersoon('startnr'); });
    el('mz-cat').addEventListener('keydown', e => { if (e.key === 'Enter') zoekPersoon('startnr'); });

    el('modal-sluiten')     .addEventListener('click', sluitDeelnemerModal);
    el('modal-dt-annuleer') .addEventListener('click', sluitDeelnemerModal);
    el('modal-dt-bevestig') .addEventListener('click', bevestigDeelnemer);

    // ESC sluit modal
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && el('modal-deelnemer')?.style.display !== 'none')
            sluitDeelnemerModal();
    });
    // Klik op overlay sluit modal
    el('modal-deelnemer').addEventListener('click', e => {
        if (e.target === el('modal-deelnemer')) sluitDeelnemerModal();
    });

    // Transponder-bevestiging: "Ja, klopt" → doorgaan; "Nee" → terug naar invoer
    el('f-dt-tp-ja').addEventListener('click', () => {
        _tpBevestigd = true;
        el('f-dt-tp-check').style.display = 'none';
        bevestigDeelnemer();
    });
    el('f-dt-tp-nee').addEventListener('click', () => {
        _tpBevestigd = false;
        el('f-dt-tp-check').style.display = 'none';
        el('f-dt-tp').focus();
    });
    // Transponder-veld wijzigen → bevestiging verbergen
    el('f-dt-tp').addEventListener('input', () => {
        _tpBevestigd = false;
        el('f-dt-tp-check').style.display = 'none';
    });

    // Club autocomplete: vul club-kort automatisch in
    el('f-dt-club').addEventListener('change', () => {
        if (!_clubsCache) return;
        const val  = el('f-dt-club').value.trim();
        const club = _clubsCache.find(c => c.club_full === val);
        if (club?.club_short && !el('f-dt-club-kort').value.trim())
            el('f-dt-club-kort').value = club.club_short;
    });

    // Categorie-wijziging reset waarschuwing
    el('f-dt-cat').addEventListener('input', () => {
        _catOverride = false;
        el('modal-waarsch').style.display = 'none';
        el('modal-dt-bevestig').textContent = 'Toevoegen';
    });
}

function openDeelnemerModal(dcId) {
    if (!el('modal-deelnemer')) initDeelnemerModal();

    _modalDcId   = dcId;
    _catOverride = false;

    const dc = vergelijkData.find(d => d.dc_id === dcId);
    el('modal-dc-naam-lbl').textContent = dc?.dc_name ?? '';

    // Formulier resetten
    ['f-dt-lk','f-dt-sn','f-dt-voornaam','f-dt-naam','f-dt-kort','f-dt-cat',
     'f-dt-club','f-dt-club-kort','f-dt-tp'].forEach(id => {
        const inp = el(id); if (inp) inp.value = '';
    });
    el('f-dt-nat').value    = 'NED';
    el('f-dt-gender').value = '';
    el('mz-lk').value  = '';
    el('mz-sn').value  = '';
    el('mz-cat').value = '';
    el('mz-status').textContent          = '';
    el('modal-waarsch').style.display    = 'none';
    el('f-dt-tp-check').style.display    = 'none';
    el('modal-dt-bevestig').textContent  = 'Toevoegen';
    _tpBevestigd      = false;
    _personZoekResult = null;

    // Zoektab resetten naar 'relatie'
    document.querySelectorAll('.modal-ztab').forEach((b, i) => b.classList.toggle('active', i === 0));
    el('mz-relatie').style.display = '';
    el('mz-startnr').style.display = 'none';

    el('modal-deelnemer').style.display = 'flex';
    el('mz-lk').focus();

    if (!_clubsCache) laadClubsLijst();
}

function sluitDeelnemerModal() {
    const m = el('modal-deelnemer');
    if (m) m.style.display = 'none';
    _modalDcId = null;
}

async function laadClubsLijst() {
    try {
        const res   = await fetch('api/persoon_zoek.php?action=clubs');
        _clubsCache = await res.json();
        const dl    = el('modal-clubs-dl');
        if (!dl || !Array.isArray(_clubsCache)) return;
        dl.innerHTML = _clubsCache
            .map(c => `<option value="${escHtml(c.club_full ?? '')}"></option>`)
            .join('');
    } catch { /* stil falen */ }
}

async function zoekPersoon(type) {
    const statusEl = el('mz-status');
    statusEl.innerHTML = '<span class="spinner"></span> Zoeken…';

    let url;
    if (type === 'relatie') {
        const lk = el('mz-lk').value.trim();
        if (!lk) { statusEl.textContent = 'Vul een relatienummer in.'; return; }
        url = `api/persoon_zoek.php?license_key=${encodeURIComponent(lk)}`;
    } else {
        const sn  = el('mz-sn').value.trim();
        const cat = el('mz-cat').value.trim();
        if (!sn) { statusEl.textContent = 'Vul een startnummer in.'; return; }
        url = `api/persoon_zoek.php?start_number=${encodeURIComponent(sn)}`
            + (cat ? `&category=${encodeURIComponent(cat)}` : '');
    }

    try {
        const res     = await fetch(url);
        const data    = await res.json();
        const personen = Array.isArray(data) ? data : (data ? [data] : []);

        if (!personen.length) {
            statusEl.textContent = 'Geen rijder gevonden — vul gegevens handmatig in.';
            return;
        }
        if (personen.length === 1) {
            vulModalFormulier(personen[0]);
            statusEl.innerHTML = `<span style="color:green">✓ Gevonden: ${escHtml(personen[0].full_name ?? '')}</span>`;
            return;
        }
        // Meerdere matches → laat operator zelf kiezen. Niets automatisch
        // invullen want de "eerste" is willekeurig (server-sorteer-volgorde).
        statusEl.innerHTML = `
            <div class="mz-keuze-kop">${personen.length} rijders gevonden — kies welke:</div>
            <div class="mz-keuze-lijst">
                ${personen.map((p, i) => `
                    <button type="button" class="mz-keuze-knop" data-idx="${i}">
                        <span class="mz-keuze-naam">${escHtml(p.full_name || '?')}</span>
                        <span class="mz-keuze-meta">${escHtml(p.category || '')}${p.club_full || p.club_short ? ' · ' + escHtml(p.club_full || p.club_short) : ''}${p.start_number != null ? ' · #' + escHtml(String(p.start_number)) : ''}</span>
                    </button>`).join('')}
            </div>`;
        statusEl.querySelectorAll('.mz-keuze-knop').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = personen[parseInt(btn.dataset.idx)];
                vulModalFormulier(p);
                statusEl.innerHTML = `<span style="color:green">✓ Gekozen: ${escHtml(p.full_name ?? '')}</span>`;
            });
        });
    } catch(e) {
        statusEl.textContent = '⚠ Fout bij zoeken: ' + e.message;
    }
}

function vulModalFormulier(p) {
    if (p.license_key  != null) el('f-dt-lk').value          = p.license_key;
    if (p.start_number != null) el('f-dt-sn').value           = p.start_number;
    if (p.full_name) {
        el('f-dt-naam').value = p.full_name;
        // Splits full_name in voornaam + achternaam (achternaam = short_name of laatste deel)
        const achternaam = p.short_name || '';
        const voornaam   = achternaam && p.full_name.endsWith(achternaam)
            ? p.full_name.slice(0, -achternaam.length).trim()
            : p.full_name.split(' ').slice(0, -1).join(' ');
        el('f-dt-voornaam').value = voornaam;
    }
    if (p.short_name)            el('f-dt-kort').value         = p.short_name;
    if (p.category)              el('f-dt-cat').value          = p.category;
    if (p.nationality)           el('f-dt-nat').value          = p.nationality;
    if (p.gender != null)        el('f-dt-gender').value       = String(p.gender);
    if (p.club_full)             el('f-dt-club').value         = p.club_full;
    if (p.club_short)            el('f-dt-club-kort').value    = p.club_short;
    // Meest recente bekende transponder als standaard tonen
    el('f-dt-tp').value = p.transponder1 || p.transponder2 || '';
    // Sla zoekresultaat op zodat bevestigDeelnemer bekende transponders kan vergelijken
    _personZoekResult = p;
    // Verberg eventuele eerdere tp-check
    el('f-dt-tp-check').style.display = 'none';
    _tpBevestigd = false;
}

function bevestigDeelnemer() {
    const dc = vergelijkData.find(d => d.dc_id === _modalDcId);
    if (!dc) return;

    const lkInput  = el('f-dt-lk').value.trim();
    const sn       = parseInt(el('f-dt-sn').value)  || null;
    const voornaam = el('f-dt-voornaam').value.trim();
    const kort     = el('f-dt-kort').value.trim();
    const naam     = (voornaam && kort) ? voornaam + ' ' + kort : (voornaam || kort);
    const cat      = el('f-dt-cat').value.trim().toUpperCase();
    const nat      = (el('f-dt-nat').value.trim().toUpperCase() || 'NED').slice(0, 3);
    const gender   = el('f-dt-gender').value !== '' ? Number(el('f-dt-gender').value) : null;
    const clubFull   = el('f-dt-club').value.trim()      || null;
    const clubKort   = el('f-dt-club-kort').value.trim() || null;
    const tp         = el('f-dt-tp').value.trim()        || null;

    // Verplichte velden
    if (!sn)            { el('f-dt-sn').focus();       return; }
    if (!voornaam)      { el('f-dt-voornaam').focus(); return; }
    if (!kort)          { el('f-dt-kort').focus();     return; }
    if (!cat)           { el('f-dt-cat').focus();    return; }
    if (gender === null){ el('f-dt-gender').focus(); return; }

    // Categorie-check tov DC (tenzij al overruled door gebruiker)
    if (!_catOverride) {
        const catFilter = dc.category_filter
            ? String(dc.category_filter).split(',').map(c => c.trim()).filter(Boolean)
            : [];
        // Wildcard-matching: "DKA*" matcht "DKA", "DKA1", etc.
        const catPast = !catFilter.length || catFilter.some(patroon => {
            if (patroon.includes('*') || patroon.includes('?')) {
                const re = new RegExp(
                    '^' + patroon.replace(/[.+^${}()|[\]\\]/g, '\\$&')
                                 .replace(/\*/g, '.*')
                                 .replace(/\?/g, '.') + '$', 'i'
                );
                return re.test(cat);
            }
            return patroon.toUpperCase() === cat;
        });
        if (!catPast) {
            const w = el('modal-waarsch');
            w.textContent = `Categorie "${cat}" past mogelijk niet in "${dc.dc_name}" `
                          + `(verwacht: ${catFilter.join(', ')}). Klik nogmaals om toch toe te voegen.`;
            w.style.display = '';
            _catOverride = true;
            el('modal-dt-bevestig').textContent = 'Toch toevoegen';
            return;
        }
    }

    // Transponder-check: vergelijk ingevoerde transponder met alle bekende transponders
    // (KNSB T1, T2 én lokale extras uit de transponders-tabel)
    if (tp && !_tpBevestigd && _personZoekResult) {
        const bekendeTs = [
            _personZoekResult.transponder1,
            _personZoekResult.transponder2,
            ...(_personZoekResult.transponders_extra ?? []),
        ].filter(Boolean);
        if (bekendeTs.length && !bekendeTs.includes(tp)) {
            el('f-dt-tp-check-tekst').textContent =
                `Is transponder "${tp}" de juiste voor deze rijder?`
                + ` (Bekende transponders: ${bekendeTs.join(', ')})`;
            el('f-dt-tp-check').style.display = '';
            el('f-dt-tp-check').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
    }

    // Dubbele inschrijving voorkomen (alleen als relatienummer ingevuld)
    const lk = lkInput || `manual_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    if (lkInput && dc.competitors.find(c => c.license_key === lk)) {
        el('mz-status').innerHTML =
            `<span style="color:#c00">Rijder met relatienummer "${escHtml(lk)}" staat al in deze groep.</span>`;
        return;
    }

    // Maak competitor-object aan (zelfde structuur als vergelijkData)
    const newComp = {
        license_key:         lk,
        is_anoniem:          false,
        knsb_entry_id:       null,
        knsb_status:         0,
        entry_status:        5,       // Bevestigd bij org.
        reserve:             null,
        is_new:              true,
        diffs:               [],
        is_manual:           true,
        knsb: {
            start_number: sn,
            full_name:    naam,
            short_name:   kort,
            gender,
            category:     cat,
            nationality:  nat,
            club_code:    null,
            club_short:   clubKort,
            club_full:    clubFull,
            city:         null,
            transponder1: _personZoekResult?.transponder1 || tp,
            transponder2: _personZoekResult?.transponder2 || null,
        },
        db_person: null, db_entry: null,
        db_tp1: null, db_tp2: null, db_tp_extra: [],
        db_tp_actief: null, db_tp_actief_isset: false,
    };

    dc.competitors.push(newComp);

    // T1 en T2 komen van KNSB (via zoekresultaat) en zijn onveranderlijk.
    // Het ingevoerde 'tp' is de actieve transponder voor de race:
    //   - matcht T1 of T2 → gewoon tpActief zetten, geen extra opslaan
    //   - onbekend + bevestigd (_tpBevestigd) → als extra opslaan
    //   - geen zoekresultaat (handmatig) → tp is de primaire transponder
    const zoekTp1 = _personZoekResult?.transponder1 || null;
    const zoekTp2 = _personZoekResult?.transponder2 || null;

    personEdits[lk] = {
        start_number:       sn,
        full_name:          naam,
        short_name:         kort,
        category:           cat,
        nationality:        nat,
        gender,
        club_full:          clubFull,
        club_short:         clubKort,
        transponder1:       zoekTp1 || tp,   // KNSB T1 heeft prioriteit; bij nieuw = ingevoerde tp
        transponder2:       zoekTp2,         // KNSB T2 ongewijzigd
        // Bevestigde onbekende transponder toevoegen aan bestaande extras (dedupliceren)
        transponders_extra: _tpBevestigd && tp
            ? [...new Set([...(_personZoekResult?.transponders_extra ?? []), tp])]
            : (_personZoekResult?.transponders_extra ?? []),
        transponder_actief: tp,              // welke transponder wordt gebruikt op de baan
    };

    // Registreer in entryEdits
    const ek = dc.dc_id + '_' + lk;
    entryEdits[ek] = {
        entry_status:  5,
        knsb_status:   0,
        reserve:       null,
        knsb_entry_id: null,
        is_manual:     true,
    };

    heeftWijzigingen = true;
    sluitDeelnemerModal();
    toonVergelijkTabel(dc);
    updateImportBtn();

    // Update tab-badge teller
    const tabBtn = document.querySelector(`.imp-cat-tab[data-dc-id="${CSS.escape(dc.dc_id)}"]`);
    if (tabBtn) {
        const totaal = dc.competitors.length;
        const nieuw  = dc.competitors.filter(c => c.is_new).length;
        const badge  = nieuw ? `<span class="tab-badge">${totaal}</span><span class="tab-badge-new">+${nieuw}</span>`
                             : `<span class="tab-badge">${totaal}</span>`;
        tabBtn.innerHTML = `${escHtml(dc.dc_name)} ${badge}`;
    }
}
