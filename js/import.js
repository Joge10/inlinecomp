/* InlineComp – import & vergelijk */

// ── Edit-staat initialiseren ──────────────────────────────────────────────────
// Effectieve startwaarden: DB heeft voorrang, KNSB is fallback

function initEdits() {
    personEdits      = {};
    entryEdits       = {};
    manualTp         = new Set();
    heeftWijzigingen = false;
    gewijzigdeRijen  = new Set();

    isGeimporteerd = vergelijkData.some(cat =>
        cat.competitors.some(c => c.db_entry !== null)
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

                // Actieve transponder:
                //   - slot 0 bewust opgeslagen in DB → gebruik DB-waarde (null = expliciete "geen")
                //   - nog nooit opgeslagen           → slim default: T1 → T2 → Textra → null
                const defaultTp = item.db_tp_actief_isset
                    ? item.db_tp_actief
                    : (t1 ?? t2 ?? extras[0] ?? null);

                personEdits[lk] = {
                    start_number:       p ? (p.start_number ?? item.knsb.start_number) : item.knsb.start_number,
                    full_name:          p ? (p.full_name    ?? item.knsb.full_name)    : item.knsb.full_name,
                    transponder1:       t1,
                    transponder2:       t2,
                    transponders_extra: extras,
                    transponder_actief: defaultTp,
                    short_name:         item.knsb.short_name,
                    gender:             item.knsb.gender,
                    category:           item.knsb.category,
                    nationality:        item.knsb.nationality,
                    club_code:          item.knsb.club_code,
                    club_short:         item.knsb.club_short,
                    club_full:          item.knsb.club_full,
                    city:               item.knsb.city,
                };
            }

            const ek = cat.dc_id + '_' + lk;
            entryEdits[ek] = {
                entry_status:  item.entry_status,
                reserve:       item.reserve,
                knsb_entry_id: item.knsb_entry_id,
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

    // Laad DB-afstanden (gegroepeerd op target_group); fallback naar KNSB
    const dcDistances = {};
    await Promise.all(vergelijkData.map(async cat => {
        try {
            const res  = await fetch(`api/distances_db.php?dc_id=${encodeURIComponent(cat.dc_id)}`);
            const data = await res.json();
            if (Array.isArray(data) && data.length) {
                // Groepeer op target_group → aparte sleutels per splitgroep
                data.forEach(d => {
                    const k = distKey(cat.dc_id, d.target_group || null);
                    if (!dcDistances[k]) dcDistances[k] = [];
                    dcDistances[k].push({ id: d.id, number: d.number, name: d.name, value_meters: d.value_meters });
                });
                if (!dcDistances[cat.dc_id]) dcDistances[cat.dc_id] = [];
            } else {
                dcDistances[cat.dc_id] =
                    (cat.knsb_distances || []).map(d => ({ id: '', number: d.number, name: d.name, value_meters: d.value_meters }));
            }
        } catch {
            dcDistances[cat.dc_id] =
                (cat.knsb_distances || []).map(d => ({ id: '', number: d.number, name: d.name, value_meters: d.value_meters }));
        }
    }));

    // Standaard ingeklapt bij laden — gebruiker klapt open als aanpassing nodig is
    let beheerIngeklapt = true;

    // Vaste buitenste structuur (nooit overschreven → event-handlers blijven actief)
    panel.innerHTML =
        `<div id="beheer-tabel-wrap"></div>` +
        `<div class="beheer-acties">` +
        `<button class="btn-secondary" id="btn-beheer-opslaan">&#10003; Opslaan</button>` +
        `<span class="beheer-status" id="beheer-status"></span>` +
        `</div>`;

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
            const catInfo = {};   // { catNaam: { dcId, count } }
            dcGroup.forEach(dc => {
                dc.competitors.forEach(c => {
                    const k = c.knsb?.category;
                    if (!k) return;
                    if (!catInfo[k]) catInfo[k] = { dcId: dc.dc_id, count: 0 };
                    catInfo[k].count++;
                });
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
                        ? `<button class="dc-ontkoppel" data-dc-id="${escHtml(dc.dc_id)}" title="Samenvoegen ongedaan maken">&#x2715;</button>`
                        : '') +
                    `</div>`;
            });

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
                          `<button class="merge-wis-btn dc-split-wis" tabindex="-1"` +
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
                return `<td class="dc-afd-kol" data-dist-key="${escHtml(d._key)}"` +
                    ` data-idx="${i}" data-afd-id="${escHtml(d.id || '')}">` +
                    `<div class="dc-afd-kol-inner">` +
                    `<input class="inp dc-afd-naam" value="${escHtml(d.name || '')}" placeholder="naam" title="${escHtml(d.name || '')}">` +
                    `<input class="inp dc-afd-m" type="number" min="0" max="99999"` +
                    ` value="${escHtml(String(d.value_meters ?? ''))}" placeholder="m">` +
                    `<button class="dc-afd-del">&#128465;</button>` +
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
            `</table>`;
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
        }));
    }

    function syncAllesVanDom() {
        syncSplitsVanDom();
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
    });

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
            renderTabel(); return;
        }
        // Split-veld: rij opsplitsen na invullen
        if (e.target.classList.contains('dc-split-inp')) {
            syncAllesVanDom();
            renderTabel(); return;
        }
    });

    panel.addEventListener('click', e => {
        // Thead-rij: in-/uitklappen
        if (e.target.closest('.beheer-thead-toggle')) {
            syncAllesVanDom();
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
            renderTabel(); return;
        }
        // Split-veld wissen
        if (e.target.classList.contains('dc-split-wis')) {
            const inp = e.target.previousElementSibling;
            inp.value = ''; e.target.style.visibility = 'hidden';
            syncAllesVanDom();
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
            renderTabel();
            const nms = panel.querySelectorAll(`td.dc-afd-kol[data-dist-key="${CSS.escape(key)}"] .dc-afd-naam`);
            if (nms.length) nms[nms.length - 1].focus();
            return;
        }
    });

    el('btn-beheer-opslaan').addEventListener('click', () => slaaBeheerOp(panel, dcDistances));
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
        }));
    });

    const merges     = vergelijkData.map(c => ({ dc_id: c.dc_id, merge_group: c.merge_group || null }));
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
    } catch(e) {
        status.innerHTML = `<span style="color:#c00">&#9888; ${escHtml(e.message)}</span>`;
    } finally {
        btn.disabled = false;
    }
}







// ── Categorietabbladen bouwen ─────────────────────────────────────────────────

function bouwVergelijkTabbladen() {
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
        const afgemeld  = cat.competitors.filter(c => c.entry_status === 2).length;
        const nieuw     = cat.competitors.filter(c => c.is_new).length;

        let badge = '';
        if (afgemeld) badge += ` <span class="tab-badge afgemeld">${afgemeld}✗</span>`;
        if (nieuw)    badge += ` <span class="tab-badge nieuw">${nieuw}N</span>`;

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

function toonVergelijkTabel(cat) {
    const content = el('imp-cat-content');

    if (!cat.competitors.length) {
        statusMsg(content, 'info', 'Geen deelnemers in deze categorie.');
        return;
    }

    let html = `
    <div class="vergelijk-wrap">
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
        if      (st === 2)     rowClass = 'row-withdrawn';
        else if (isNew)        rowClass = 'row-new';
        else if (diffs.length) rowClass = 'row-diff';
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
        if (diffs.length)  badgesHtml += '<span class="badge-diff" title="Afwijking t.o.v. database">!</span>';

        html += `
        <tr class="${rowClass}" data-lk="${escHtml(lk)}" data-dc="${escHtml(cat.dc_id)}">
            <td class="td-sn ${isGuest ? 'guest-nr' : ''}">
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
                ${maakTpDropdownHtml(lk, pe.transponder1, pe.transponder2, extras, actief)}
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

    html += '</tbody></table></div>';
    content.innerHTML = html;

    // ── Event listeners ──

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
        });
    });

    // Transponder dropdown: selectie opslaan
    content.querySelectorAll('.tp-sel-drop').forEach(sel => {
        sel.addEventListener('change', () => {
            const lk = sel.dataset.lk;
            if (!personEdits[lk]) personEdits[lk] = {};
            personEdits[lk].transponder_actief = sel.value || null;
            markeerGewijzigd(sel.closest('tr'));
        });
    });

    // Transponder '+' knop: inline invoer
    content.querySelectorAll('.tp-add-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            voegTpToe(btn.dataset.lk, btn, content);
        });
    });

    content.querySelectorAll('.status-badge').forEach(badge => {
        badge.addEventListener('click', () => {
            const lk   = badge.dataset.lk;
            const dcId = badge.dataset.dc;
            const ek   = dcId + '_' + lk;

            const huidig = entryEdits[ek]?.entry_status ?? 1;
            const nieuw  = (huidig + 1) % 3;

            if (!entryEdits[ek]) entryEdits[ek] = {};
            entryEdits[ek].entry_status = nieuw;

            badge.className   = 'status-badge ' + STATUS_CSS[nieuw];
            badge.textContent = STATUS_LABELS[nieuw];

            const row = badge.closest('tr');
            if (row) {
                row.classList.remove('row-withdrawn', 'row-new', 'row-diff');
                if (nieuw === 2) row.classList.add('row-withdrawn');
                else             markeerGewijzigd(row);
            }
        });
    });
}

// ── Importeer-knop status ─────────────────────────────────────────────────────

function updateImportBtn() {
    const btn = el('btn-import');
    if (!btn) return;
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
    setHTML('imp-cat-content',
        '<div class="status-msg loading"><span class="spinner"></span>Synchroniseren met KNSB…</div>'
    );
    try {
        const res = await fetch('api/vergelijk.php?id=' + encodeURIComponent(huidigCompId));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        vergelijkData = await res.json();
        if (vergelijkData.error) throw new Error(vergelijkData.error);
        zetKnsbTimestamp();
        initEdits();
        bouwVergelijkTabbladen();
        updateImportBtn();
    } catch(e) {
        setHTML('imp-cat-content',
            `<div class="status-msg error">⚠ Synchronisatie mislukt: ${escHtml(e.message)}</div>`
        );
    }
}

// ── Transponder helpers ───────────────────────────────────────────────────────

// textraPopup bestaat nog zodat app.js (click-buiten handler) er naar kan verwijzen
let textraPopup = null;
function sluitTextraPopup() {
    if (textraPopup) { textraPopup.remove(); textraPopup = null; }
}

// Bouw de HTML voor de transponder-dropdown + '+' knop
function maakTpDropdownHtml(lk, t1, t2, extras, actief) {
    let opts = `<option value=""${!actief ? ' selected' : ''}>— geen —</option>`;
    if (t1) opts += `<option value="${escHtml(t1)}"${actief === t1 ? ' selected' : ''}>T1 – ${escHtml(t1)}</option>`;
    if (t2) opts += `<option value="${escHtml(t2)}"${actief === t2 ? ' selected' : ''}>T2 – ${escHtml(t2)}</option>`;
    for (const e of (extras || [])) {
        opts += `<option value="${escHtml(e)}"${actief === e ? ' selected' : ''}>Textra – ${escHtml(e)}</option>`;
    }
    return `<div class="tp-sel-wrap">
        <select class="inp tp-sel-drop" data-lk="${escHtml(lk)}">${opts}</select>
        <button class="tp-add-btn" data-lk="${escHtml(lk)}" title="Transponder toevoegen">+</button>
    </div>`;
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
                city:           pe.city              ?? item.knsb.city,
                transponder1:       item.knsb.transponder1,
                transponder2:       item.knsb.transponder2,
                transponders_extra: pe.transponders_extra  ?? [],
                transponder_actief: pe.transponder_actief  ?? null,
            });
        }

        categories.push({ dc_id: cat.dc_id, competitors });
    }

    return { competition_id: compId, categories };
}

// ── Import naar database ──────────────────────────────────────────────────────

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

        if (!res.ok || data.error) {
            resultDiv.innerHTML =
                `<div class="status-msg error">⚠ Import mislukt: ${escHtml(data.error || 'onbekende fout')}</div>`;
            btn.disabled = false;
        } else {
            const logHtml = (data.log || []).map(r => `<li>${escHtml(r)}</li>`).join('');
            resultDiv.innerHTML =
                `<div class="status-msg ok">
                    ✔ <strong>${escHtml(compNaam)}</strong> geïmporteerd
                    <ul class="import-log">${logHtml}</ul>
                 </div>`;
            setTimeout(() => { if (resultDiv) resultDiv.innerHTML = ''; }, 4000);
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
