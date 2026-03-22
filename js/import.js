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

// ── Merge-panel bouwen ────────────────────────────────────────────────────────

function bouwMergePanel() {
    const panel = el('merge-panel');
    if (!panel) return;

    const heeftMerges = vergelijkData.some(c => c.merge_group);

    const rows = vergelijkData.map(cat =>
        `<tr>
            <td class="merge-cat-naam">${escHtml(cat.dc_name)}</td>
            <td class="merge-groep-cel">
                <input type="text" class="inp merge-groep-inp"
                       list="merge-groepnamen"
                       data-dc-id="${escHtml(cat.dc_id)}"
                       value="${escHtml(cat.merge_group || '')}"
                       placeholder="eigen groep" maxlength="40">
                <button class="merge-wis-btn" title="Groep verwijderen" tabindex="-1"
                        style="${cat.merge_group ? '' : 'visibility:hidden'}">&#128465;</button>
            </td>
         </tr>`
    ).join('');

    panel.innerHTML = `
        <datalist id="merge-groepnamen"></datalist>
        <div class="merge-kop" id="merge-kop">
            <span>&#8644; Categorieën samenvoegen</span>
            <span class="merge-toggle-icon" id="merge-toggle-icon">${heeftMerges ? '&#9650;' : '&#9660;'}</span>
        </div>
        <div class="merge-body" id="merge-body" style="display:${heeftMerges ? 'block' : 'none'}">
            <p class="merge-uitleg">
                Vul dezelfde groepsnaam in bij categorieën die samen één startlijst krijgen.
                Laat leeg (of maak leeg) voor een eigen aparte startlijst.
            </p>
            <table class="merge-tabel">
                <thead><tr><th>Categorie</th><th>Groepsnaam</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <div class="merge-acties">
                <button class="btn-secondary" id="btn-merge-opslaan">&#10003; Opslaan</button>
                <span class="merge-status" id="merge-status"></span>
            </div>
        </div>`;

    el('merge-kop').addEventListener('click', () => {
        const body = el('merge-body');
        const icon = el('merge-toggle-icon');
        const open = body.style.display !== 'none';
        body.style.display = open ? 'none' : 'block';
        icon.innerHTML     = open ? '&#9660;' : '&#9650;';
    });

    // Datalist live bijwerken: alle unieke niet-lege namen die al ingevuld zijn
    function verversGroepnamen() {
        const namen = [...new Set(
            [...document.querySelectorAll('.merge-groep-inp')]
                .map(i => i.value.trim())
                .filter(Boolean)
        )];
        const dl = document.getElementById('merge-groepnamen');
        if (dl) dl.innerHTML = namen.map(n => `<option value="${escHtml(n)}">`).join('');
    }

    // ×-knop: veld leegmaken en knop verbergen
    panel.addEventListener('click', e => {
        if (!e.target.classList.contains('merge-wis-btn')) return;
        const inp = e.target.previousElementSibling;
        inp.value = '';
        e.target.style.visibility = 'hidden';
        verversGroepnamen();
    });

    // Initieel vullen vanuit opgeslagen waarden
    verversGroepnamen();

    // Live bijwerken terwijl je typt; ×-knop tonen/verbergen
    panel.addEventListener('input', e => {
        if (!e.target.classList.contains('merge-groep-inp')) return;
        const wisBtn = e.target.nextElementSibling;
        if (wisBtn) wisBtn.style.visibility = e.target.value.trim() ? 'visible' : 'hidden';
        verversGroepnamen();
    });

    el('btn-merge-opslaan').addEventListener('click', slaaMergesOp);
}

async function slaaMergesOp() {
    const btn    = el('btn-merge-opslaan');
    const status = el('merge-status');
    btn.disabled = true;
    status.textContent = 'Opslaan…';

    const merges = [...document.querySelectorAll('.merge-groep-inp')].map(inp => ({
        dc_id:       inp.dataset.dcId,
        merge_group: inp.value.trim() || null,
    }));

    try {
        const res  = await fetch('api/samenvoeg.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ competition_id: huidigCompId, merges }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Werk vergelijkData bij zodat startlijsten direct de nieuwe groepen kennen
        merges.forEach(m => {
            const cat = vergelijkData.find(c => c.dc_id === m.dc_id);
            if (cat) cat.merge_group = m.merge_group;
        });

        status.innerHTML = '<span style="color:var(--oranje)">&#10003; Opgeslagen</span>';
        setTimeout(() => { status.textContent = ''; }, 2500);
    } catch(e) {
        status.innerHTML = `<span style="color:#c00">⚠ ${escHtml(e.message)}</span>`;
    } finally {
        btn.disabled = false;
    }
}

// ── Split-panel bouwen ────────────────────────────────────────────────────────

// Rendert de inline afstands-chips voor één groep
function afdInlineHtml(groepNaam, groepAfstanden) {
    if (!groepNaam) return '<span class="afd-geen">—</span>';
    const dists = groepAfstanden[groepNaam] || [];
    const chips = dists.map((d, i) =>
        `<span class="afd-chip" data-idx="${i}" data-afd-id="${escHtml(d.id || '')}">
            <input class="inp afd-chip-naam" value="${escHtml(d.name || '')}"
                   placeholder="naam" title="Naam afstand">
            <input class="inp afd-chip-m" type="number" min="0"
                   value="${escHtml(String(d.value_meters ?? ''))}"
                   placeholder="m" title="Afstand in meters">
            <button class="merge-wis-btn afd-chip-del" title="Verwijder afstand">&#128465;</button>
        </span>`
    ).join('');
    return chips + `<button class="afd-plus-btn" title="Afstand toevoegen">+</button>`;
}

async function bouwSplitPanel() {
    const panel = el('split-panel');
    if (!panel) return;
    panel.innerHTML = '';

    const teSpitsen = vergelijkData.filter(cat => {
        const cats = [...new Set(cat.competitors.map(c => c.knsb?.category).filter(Boolean))];
        return cats.length > 1;
    });
    if (!teSpitsen.length) return;

    // Laad bestaande afstanden voor DCs die al splits hebben
    const distMap = {};
    await Promise.all(
        teSpitsen
            .filter(cat => Object.keys(cat.splits || {}).length > 0)
            .map(async cat => {
                try {
                    const res  = await fetch(`api/distances_db.php?dc_id=${encodeURIComponent(cat.dc_id)}`);
                    const data = await res.json();
                    distMap[cat.dc_id] = Array.isArray(data) ? data : [];
                } catch { distMap[cat.dc_id] = []; }
            })
    );

    teSpitsen.forEach(cat => {
        const catTelling = {};
        cat.competitors.forEach(c => {
            const k = c.knsb?.category;
            if (k) catTelling[k] = (catTelling[k] || 0) + 1;
        });

        const heeftSplits = Object.keys(cat.splits || {}).length > 0;
        const dcSectionId = 'split-body-' + cat.dc_id.replace(/[^a-z0-9]/gi, '');

        // groepAfstanden: gedeelde databron per groep voor dit DC
        // { groepNaam: [{id, number, name, value_meters}, ...] }
        const groepAfstanden = {};
        (distMap[cat.dc_id] || []).forEach(d => {
            const k = d.target_group || '';
            if (!groepAfstanden[k]) groepAfstanden[k] = [];
            groepAfstanden[k].push({ id: d.id || '', number: d.number,
                                     name: d.name, value_meters: d.value_meters });
        });

        const catRows = Object.keys(catTelling).sort().map(k => {
            const groep = (cat.splits || {})[k] || '';
            return `<tr data-cat="${escHtml(k)}">
                <td class="split-cat-naam">${escHtml(k)}</td>
                <td class="split-cat-tel">${catTelling[k]}</td>
                <td class="split-groep-cel">
                    <input type="text" class="inp split-groep-inp"
                           list="split-groepnamen-${escHtml(cat.dc_id)}"
                           data-category="${escHtml(k)}"
                           value="${escHtml(groep)}"
                           placeholder="groepsnaam" maxlength="40">
                    <button class="merge-wis-btn split-groep-wis" tabindex="-1"
                            style="${groep ? '' : 'visibility:hidden'}">&#128465;</button>
                </td>
                <td class="split-afd-cel">${afdInlineHtml(groep, groepAfstanden)}</td>
            </tr>`;
        }).join('');

        const sectie = document.createElement('div');
        sectie.className = 'split-sectie';
        sectie.innerHTML = `
            <datalist id="split-groepnamen-${escHtml(cat.dc_id)}"></datalist>
            <div class="merge-kop split-kop">
                <span>&#9986; Splitsen: <em>${escHtml(cat.dc_name)}</em></span>
                <span class="merge-toggle-icon">${heeftSplits ? '&#9650;' : '&#9660;'}</span>
            </div>
            <div class="merge-body" id="${dcSectionId}"
                 style="display:${heeftSplits ? 'block' : 'none'}">
                <p class="merge-uitleg">
                    Wijs elke categorie een groepsnaam toe. Categorieën met dezelfde naam
                    vormen één startlijst. Voeg per groep afstanden toe via <strong>+</strong>.
                </p>
                <table class="merge-tabel split-tabel">
                    <thead><tr>
                        <th class="split-th-cat">Cat.</th>
                        <th class="split-th-n">#</th>
                        <th class="split-th-groep">Groepsnaam</th>
                        <th>Afstanden</th>
                    </tr></thead>
                    <tbody>${catRows}</tbody>
                </table>
                <div class="merge-acties">
                    <button class="btn-secondary split-opslaan-btn">&#10003; Opslaan</button>
                    <span class="merge-status split-status"></span>
                </div>
            </div>`;

        panel.appendChild(sectie);

        const tbody = sectie.querySelector('tbody');

        // Herrender alle cellen van een groep vanuit groepAfstanden
        function refreshGroepCellen(groepNaam) {
            tbody.querySelectorAll('tr').forEach(row => {
                if (row.querySelector('.split-groep-inp')?.value.trim() === groepNaam) {
                    row.querySelector('.split-afd-cel').innerHTML =
                        afdInlineHtml(groepNaam, groepAfstanden);
                }
            });
        }

        // Sync DOM → groepAfstanden voor één groep (vanuit eerste rij met die groep)
        function syncVanDom(groepNaam) {
            if (!groepNaam) return;
            const rij = [...tbody.querySelectorAll('tr')].find(r =>
                r.querySelector('.split-groep-inp')?.value.trim() === groepNaam
            );
            if (!rij) return;
            groepAfstanden[groepNaam] = [...rij.querySelectorAll('.afd-chip')].map((chip, i) => ({
                id:           chip.dataset.afdId || '',
                number:       i + 1,
                name:         chip.querySelector('.afd-chip-naam')?.value.trim() || '',
                value_meters: parseInt(chip.querySelector('.afd-chip-m')?.value) || null,
            })).filter(d => d.name);
        }

        // Datalist bijhouden
        function verversSplitNamen() {
            const namen = [...new Set(
                [...sectie.querySelectorAll('.split-groep-inp')]
                    .map(i => i.value.trim()).filter(Boolean)
            )];
            const dl = document.getElementById('split-groepnamen-' + cat.dc_id);
            if (dl) dl.innerHTML = namen.map(n => `<option value="${escHtml(n)}">`).join('');
        }
        verversSplitNamen();

        // Toggle
        sectie.querySelector('.split-kop').addEventListener('click', function() {
            const body = el(dcSectionId);
            const icon = this.querySelector('.merge-toggle-icon');
            const open = body.style.display !== 'none';
            body.style.display = open ? 'none' : 'block';
            icon.innerHTML     = open ? '&#9660;' : '&#9650;';
        });

        // Input-events: groepnaam wijzigen / afstand bewerken
        sectie.addEventListener('input', e => {
            if (e.target.classList.contains('split-groep-inp')) {
                const row      = e.target.closest('tr');
                const nieuw    = e.target.value.trim();
                const wisBtn   = e.target.nextElementSibling;
                if (wisBtn) wisBtn.style.visibility = nieuw ? 'visible' : 'hidden';
                row.querySelector('.split-afd-cel').innerHTML =
                    afdInlineHtml(nieuw, groepAfstanden);
                verversSplitNamen();
            }
            if (e.target.classList.contains('afd-chip-naam') ||
                e.target.classList.contains('afd-chip-m')) {
                const groep = e.target.closest('tr')
                              ?.querySelector('.split-groep-inp')?.value.trim();
                if (groep) syncVanDom(groep);
            }
        });

        // Klik-events: wis groep / verwijder afstand / voeg afstand toe
        sectie.addEventListener('click', e => {
            // Wis groepsnaam
            if (e.target.classList.contains('split-groep-wis')) {
                const inp = e.target.previousElementSibling;
                inp.value = '';
                e.target.style.visibility = 'hidden';
                e.target.closest('tr').querySelector('.split-afd-cel').innerHTML =
                    afdInlineHtml('', groepAfstanden);
                verversSplitNamen();
                return;
            }
            // Verwijder afstand-chip
            if (e.target.closest('.afd-chip-del')) {
                const chip  = e.target.closest('.afd-chip');
                const groep = chip.closest('tr')?.querySelector('.split-groep-inp')?.value.trim();
                const idx   = parseInt(chip.dataset.idx);
                if (groep && groepAfstanden[groep]) {
                    groepAfstanden[groep].splice(idx, 1);
                    refreshGroepCellen(groep);
                }
                return;
            }
            // Voeg afstand toe
            if (e.target.classList.contains('afd-plus-btn')) {
                const groep = e.target.closest('tr')
                              ?.querySelector('.split-groep-inp')?.value.trim();
                if (!groep) return;
                syncVanDom(groep);
                if (!groepAfstanden[groep]) groepAfstanden[groep] = [];
                groepAfstanden[groep].push({
                    id: '', number: groepAfstanden[groep].length + 1,
                    name: '', value_meters: null,
                });
                refreshGroepCellen(groep);
                // Focus eerste lege naam-input in huidige rij
                const huidigeRij = e.target.closest('tr');
                const inputs     = huidigeRij.querySelectorAll('.afd-chip-naam');
                if (inputs.length) inputs[inputs.length - 1].focus();
                return;
            }
        });

        // Opslaan
        sectie.querySelector('.split-opslaan-btn').addEventListener('click', () =>
            slaaSplitsEnAfstandenOp(cat.dc_id, sectie, groepAfstanden)
        );
    });
}

async function slaaSplitsEnAfstandenOp(dcId, sectie, groepAfstanden) {
    const btn    = sectie.querySelector('.split-opslaan-btn');
    const status = sectie.querySelector('.split-status');
    btn.disabled = true;
    status.textContent = 'Opslaan…';

    // Sync DOM → groepAfstanden voor alle groepen
    const alleGroepen = [...new Set(
        [...sectie.querySelectorAll('.split-groep-inp')]
            .map(i => i.value.trim()).filter(Boolean)
    )];
    const tbody = sectie.querySelector('tbody');
    alleGroepen.forEach(groep => {
        const rij = [...tbody.querySelectorAll('tr')].find(r =>
            r.querySelector('.split-groep-inp')?.value.trim() === groep
        );
        if (!rij) return;
        groepAfstanden[groep] = [...rij.querySelectorAll('.afd-chip')].map((chip, i) => ({
            id:           chip.dataset.afdId || null,
            number:       i + 1,
            name:         chip.querySelector('.afd-chip-naam')?.value.trim() || '',
            value_meters: parseInt(chip.querySelector('.afd-chip-m')?.value) || null,
        })).filter(d => d.name);
    });

    const splits = [...sectie.querySelectorAll('.split-groep-inp')].map(inp => ({
        category:    inp.dataset.category,
        split_group: inp.value.trim() || null,
    }));

    // Alle afstanden uit alle groepen, met target_group
    const distances = Object.entries(groepAfstanden).flatMap(([groep, dists]) =>
        dists.map((d, i) => ({
            id:           d.id || null,
            number:       d.number || (i + 1),
            name:         d.name,
            value_meters: d.value_meters,
            target_group: groep || null,
        }))
    ).filter(d => d.name);

    try {
        const [r1, r2] = await Promise.all([
            fetch('api/splits.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ competition_id: huidigCompId, dc_id: dcId, splits }),
            }),
            fetch('api/afstanden_beheer.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ dc_id: dcId, distances }),
            }),
        ]);
        const [d1, d2] = await Promise.all([r1.json(), r2.json()]);
        if (d1.error) throw new Error(d1.error);
        if (d2.error) throw new Error(d2.error);

        // vergelijkData bijwerken
        const catObj = vergelijkData.find(c => c.dc_id === dcId);
        if (catObj) {
            catObj.splits       = {};
            catObj.has_distances = (d2.distances || []).length > 0;
            splits.forEach(s => { if (s.split_group) catObj.splits[s.category] = s.split_group; });
        }

        // Server-IDs terugschrijven in groepAfstanden
        (d2.distances || []).forEach(d => {
            const g = d.target_group || '';
            if (!groepAfstanden[g]) return;
            const item = groepAfstanden[g].find(x => x.name === d.name && !x.id);
            if (item) item.id = d.id;
        });

        status.innerHTML = '<span style="color:var(--oranje)">&#10003; Opgeslagen</span>';
        setTimeout(() => { status.textContent = ''; }, 2500);
    } catch(e) {
        status.innerHTML = `<span style="color:#c00">⚠ ${escHtml(e.message)}</span>`;
    } finally {
        btn.disabled = false;
    }
}

// ── Afstanden-panel bouwen ────────────────────────────────────────────────────

async function bouwAfstandenPanel() {
    const panel = el('afstanden-panel');
    if (!panel) return;
    panel.innerHTML = '';
    if (!vergelijkData.length) return;

    // DCs met splits: afstanden worden beheerd in het split-panel
    const dcZonderSplits = vergelijkData.filter(cat =>
        Object.keys(cat.splits || {}).length === 0
    );
    if (!dcZonderSplits.length) { panel.innerHTML = ''; return; }

    // Laad afstanden voor DCs zonder splits parallel
    const distMap = {};
    await Promise.all(dcZonderSplits.map(async cat => {
        try {
            const res  = await fetch(`api/distances_db.php?dc_id=${encodeURIComponent(cat.dc_id)}`);
            const data = await res.json();
            distMap[cat.dc_id] = Array.isArray(data) ? data : [];
        } catch {
            distMap[cat.dc_id] = [];
        }
    }));

    dcZonderSplits.forEach(cat => {
        bouwAfstandenSectie(panel, cat, distMap[cat.dc_id] || []);
    });
}

function bouwAfstandenSectie(panel, cat, afstanden) {
    const sectieId    = 'afd-body-' + cat.dc_id.replace(/[^a-z0-9]/gi, '');
    const open        = afstanden.length === 0;
    // Split-groepen voor deze DC (voor de groep-dropdown)
    const splitGroepen = [...new Set(Object.values(cat.splits || {}))].sort();

    const sectie = document.createElement('div');
    sectie.className = 'afstanden-sectie';
    sectie.innerHTML = `
        <div class="merge-kop afd-kop">
            <span>&#128207; Afstanden: <em>${escHtml(cat.dc_name)}</em></span>
            <span class="merge-toggle-icon">${open ? '&#9650;' : '&#9660;'}</span>
        </div>
        <div class="merge-body afd-body" id="${sectieId}" style="display:${open ? 'block' : 'none'}">
            ${afstandenTabelHtml(afstanden, splitGroepen)}
            <div class="merge-acties">
                <button class="btn-secondary afd-toevoegen-btn">+ Afstand toevoegen</button>
                <button class="btn-secondary afd-opslaan-btn"
                        data-dc-id="${escHtml(cat.dc_id)}">&#10003; Opslaan</button>
                <span class="merge-status afd-status"></span>
            </div>
        </div>`;

    panel.appendChild(sectie);

    // Toggle
    sectie.querySelector('.afd-kop').addEventListener('click', function() {
        const body = el(sectieId);
        const icon = this.querySelector('.merge-toggle-icon');
        const isOpen = body.style.display !== 'none';
        body.style.display = isOpen ? 'none' : 'block';
        icon.innerHTML     = isOpen ? '&#9660;' : '&#9650;';
    });

    // Rij verwijderen
    sectie.addEventListener('click', e => {
        if (e.target.closest('.afd-wis-btn')) e.target.closest('tr').remove();
    });

    // Toevoegen
    sectie.querySelector('.afd-toevoegen-btn').addEventListener('click', () => {
        const tbody  = sectie.querySelector('.afd-tabel tbody');
        const volgNr = tbody.querySelectorAll('tr').length + 1;
        tbody.insertAdjacentHTML('beforeend', afstandRijHtml('', volgNr, '', '', null, splitGroepen));
        tbody.lastElementChild.querySelector('.afd-inp-naam').focus();
    });

    // Opslaan
    sectie.querySelector('.afd-opslaan-btn').addEventListener('click', () =>
        slaAfstandenOp(cat.dc_id, sectie)
    );
}

function afstandenTabelHtml(afstanden, splitGroepen = []) {
    const rows = afstanden.map(a =>
        afstandRijHtml(a.id, a.number, a.name, a.value_meters, a.target_group, splitGroepen)
    ).join('');
    const groepKolom = splitGroepen.length
        ? '<th class="afd-th-groep">Groep</th>' : '';
    return `<table class="merge-tabel afd-tabel">
        <thead><tr>
            <th class="afd-th-nr">#</th>
            <th>Naam</th>
            <th class="afd-th-m">Meters</th>
            ${groepKolom}
            <th></th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
}

function afstandRijHtml(id, num, naam, meters, targetGroup = null, splitGroepen = []) {
    let groepKolom = '';
    if (splitGroepen.length) {
        const opties = `<option value="">— alle groepen —</option>` +
            splitGroepen.map(g =>
                `<option value="${escHtml(g)}"${targetGroup === g ? ' selected' : ''}>${escHtml(g)}</option>`
            ).join('');
        groepKolom = `<td><select class="inp afd-inp-groep">${opties}</select></td>`;
    } else {
        groepKolom = `<input type="hidden" class="afd-inp-groep" value="">`;
    }
    return `<tr data-afd-id="${escHtml(id || '')}">
        <td><input type="number" class="inp afd-inp-num"
                   value="${escHtml(String(num ?? ''))}" min="1" max="99"></td>
        <td><input type="text" class="inp afd-inp-naam"
                   value="${escHtml(naam || '')}" placeholder="bijv. 500m" maxlength="50"></td>
        <td><input type="number" class="inp afd-inp-meters"
                   value="${escHtml(String(meters ?? ''))}" placeholder="—" min="0"></td>
        ${groepKolom}
        <td><button class="merge-wis-btn afd-wis-btn" title="Verwijderen">&#128465;</button></td>
    </tr>`;
}

async function slaAfstandenOp(dcId, sectie) {
    const btn    = sectie.querySelector('.afd-opslaan-btn');
    const status = sectie.querySelector('.afd-status');
    btn.disabled = true;
    status.textContent = 'Opslaan…';

    const distances = [...sectie.querySelectorAll('.afd-tabel tbody tr')].map(tr => ({
        id:           tr.dataset.afdId || null,
        number:       parseInt(tr.querySelector('.afd-inp-num').value)    || null,
        name:         tr.querySelector('.afd-inp-naam').value.trim(),
        value_meters: parseInt(tr.querySelector('.afd-inp-meters').value) || null,
        target_group: (tr.querySelector('.afd-inp-groep')?.value || '') || null,
    })).filter(d => d.name);

    try {
        const res  = await fetch('api/afstanden_beheer.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ dc_id: dcId, distances }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Bijwerken: server-gegenereerde IDs in de DOM zetten
        const rows = [...sectie.querySelectorAll('.afd-tabel tbody tr')];
        data.distances.forEach((d, i) => { if (rows[i]) rows[i].dataset.afdId = d.id; });

        // has_distances in vergelijkData bijwerken
        const cat = vergelijkData.find(c => c.dc_id === dcId);
        if (cat) cat.has_distances = data.distances.length > 0;

        status.innerHTML = '<span style="color:var(--oranje)">&#10003; Opgeslagen</span>';
        setTimeout(() => { status.textContent = ''; }, 2500);
    } catch(e) {
        status.innerHTML = `<span style="color:#c00">⚠ ${escHtml(e.message)}</span>`;
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

    bouwMergePanel();
    bouwSplitPanel();
    bouwAfstandenPanel();   // async, laadt op de achtergrond

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
