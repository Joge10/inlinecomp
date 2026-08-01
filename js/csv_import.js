// ============================================================
//  InlineComp – CSV-import wizard voor handmatige wedstrijden
//
//  4-staps wizard die via api/csv_import.php draait:
//
//    [1] Upload CSV → preview
//    [2] Kolom-mapping (CSV-kolom → persons.veld, multi-target mogelijk)
//    [3] DC-toewijzing (cat-code + afstand → DC in deze wedstrijd)
//    [4] Persoon-match review → import
//
//  Alleen actief bij handmatige wedstrijden (huidigComp.is_handmatig).
//  De knop "📥 CSV Importeren" zichtbaarheid wordt geregeld vanuit
//  import.js bij wedstrijd-selectie.
//
//  Modal wordt dynamisch per stap opgebouwd zodat de wizard-state in één
//  closure blijft en eenvoudig terug kan navigeren.
// ============================================================

// Wizard-state. Wordt gereset bij elke openWizard()-aanroep.
let _csvImportState = null;

// Public entry-point — wordt aangeroepen vanuit import.js bij knop-klik.
function csvImportOpenWizard() {
    if (!huidigCompId) {
        alert('Selecteer eerst een wedstrijd.');
        return;
    }
    _csvImportState = {
        compId:    huidigCompId,
        bestand:   null,
        headers:   [],
        preview:   [],
        rows:      [],   // alle data-rijen (wordt later via 2e parse-call gevuld als nodig)
        total:     0,
        delimiter: '',
        encoding:  '',
        mapping:   {},   // CSV-kolom-index → { target: '...', combine: [...] }
        dcMapping: {},   // 'HP1|200m' → dc_id
        matches:   [],   // per rij: { action: 'link'|'new'|'skip', personId, ... }
    };
    _csvOpenStap1();
}
window.csvImportOpenWizard = csvImportOpenWizard;

// ── Stap 1: Upload + preview ────────────────────────────────────────────────

function _csvOpenStap1() {
    const html = `
        <div class="modal-overlay" id="csv-modal" data-stap="1">
            <div class="modal-dialog csv-modal-dialog">
                <div class="modal-header">
                    <h3>📥 CSV Importeren — Stap 1 van 4: Bestand uploaden</h3>
                    <button class="modal-sluit" id="csv-sluit" title="Sluiten">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="csv-uitleg">
                        Upload een CSV-bestand met de deelnemers voor deze wedstrijd.
                        Het bestand moet kolomkoppen op de eerste rij hebben.
                        Excel kan een sheet opslaan als CSV via <strong>Bestand → Opslaan als → CSV UTF-8</strong>.
                    </p>
                    <div class="csv-upload-zone">
                        <input type="file" id="csv-file-input" accept=".csv,.txt,.tsv">
                        <label for="csv-file-input" class="csv-upload-label">
                            <span class="csv-upload-icon">📂</span>
                            <span class="csv-upload-tekst">Kies CSV-bestand…</span>
                        </label>
                        <div id="csv-bestand-info" class="csv-bestand-info"></div>
                    </div>
                    <div id="csv-preview-blok" class="csv-preview-blok" style="display:none;">
                        <h4>Voorbeeld (eerste 5 rijen)</h4>
                        <div id="csv-preview-tabel-wrap"></div>
                        <div class="csv-meta-info" id="csv-meta-info"></div>
                    </div>
                    <div id="csv-fout" class="csv-fout" style="display:none;"></div>
                </div>
                <div class="modal-knoppen">
                    <button class="btn-secondary" id="csv-annuleer">Annuleren</button>
                    <button class="btn-primary" id="csv-volgende" disabled>Volgende →</button>
                </div>
            </div>
        </div>`;

    // Eventueel bestaande modal weghalen
    document.getElementById('csv-modal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);

    document.getElementById('csv-sluit').addEventListener('click', _csvSluit);
    document.getElementById('csv-annuleer').addEventListener('click', _csvSluit);
    document.getElementById('csv-file-input').addEventListener('change', _csvBestandGekozen);
    document.getElementById('csv-volgende').addEventListener('click', _csvOpenStap2);
}

// ── Stap 2: Kolom-mapping ───────────────────────────────────────────────────
// Per CSV-kolom kiest operator een target. Bij speciale targets (DC-marker
// of cat-groep) verschijnt extra config in stap 3.

// Beschikbare targets in de dropdown per CSV-kolom.
const _CSV_TARGETS = [
    { val: '',                label: '— Negeren —',                  groep: '' },
    { val: 'name_full',       label: 'Volledige naam',               groep: 'Naam' },
    { val: 'name_first',      label: 'Voornaam-deel',                groep: 'Naam' },
    { val: 'name_tussen',     label: 'Tussenvoegsel-deel',           groep: 'Naam' },
    { val: 'name_last',       label: 'Achternaam-deel',              groep: 'Naam' },
    { val: 'gender',          label: 'Geslacht (M/W of M/V)',        groep: 'Persoonlijk' },
    { val: 'nationality',     label: 'Nationaliteit (NLD, GER, …)',  groep: 'Persoonlijk' },
    { val: 'birth_year',      label: 'Geboortejaar',                 groep: 'Persoonlijk' },
    { val: 'start_number',    label: 'Startnummer (KNSB)',           groep: 'Persoonlijk' },
    { val: 'cat_groep',       label: 'Categorie-groep (Pupil/Cadet/…)', groep: 'Categorie' },
    { val: 'club_short',      label: 'Club (kort)',                  groep: 'Club' },
    { val: 'club_full',       label: 'Club (volledig)',              groep: 'Club' },
    { val: 'sponsor',         label: 'Sponsor',                      groep: 'Club' },
    { val: 'club_of_sponsor', label: 'Club ÉN sponsor (mixed-kolom)', groep: 'Club' },
    { val: 'dc_marker',       label: 'DC-markering (x = doet mee)',  groep: 'Afstand' },
];

function _csvOpenStap2() {
    if (!_csvImportState?.headers?.length) return;

    // Initialize mapping als nog leeg + slim default raden op header-naam
    if (Object.keys(_csvImportState.mapping).length === 0) {
        _csvImportState.headers.forEach((h, i) => {
            _csvImportState.mapping[i] = _csvRaadTarget(h);
        });
    }

    const rijenHtml = _csvImportState.headers.map((header, i) => {
        const sample = _csvBesteSample(i);
        const huidigeTarget = _csvImportState.mapping[i] || '';
        return `
            <tr>
                <td class="csv-map-nr">${i + 1}</td>
                <td class="csv-map-header">${_csvEsc(header || '<i>(geen kop)</i>')}</td>
                <td class="csv-map-sample" title="${_csvEsc(sample)}">${_csvEsc(sample)}</td>
                <td class="csv-map-target">
                    <select class="csv-map-sel" data-kol="${i}">
                        ${_csvTargetsAsOptions(huidigeTarget)}
                    </select>
                </td>
            </tr>`;
    }).join('');

    const html = `
        <div class="modal-overlay" id="csv-modal" data-stap="2">
            <div class="modal-dialog csv-modal-dialog">
                <div class="modal-header">
                    <h3>📥 CSV Importeren — Stap 2 van 4: Kolom-mapping</h3>
                    <button class="modal-sluit" id="csv-sluit" title="Sluiten">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="csv-uitleg">
                        Koppel elke CSV-kolom aan een veld in de database. Wat je niet
                        nodig hebt zet je op <strong>Negeren</strong>. Voor de naam kun je
                        ofwel één 'Volledige naam'-kolom kiezen, ofwel voornaam +
                        tussenvoegsel + achternaam apart — die worden dan automatisch
                        samengevoegd.
                    </p>
                    <table class="csv-map-tabel">
                        <thead>
                            <tr>
                                <th class="csv-map-nr">#</th>
                                <th class="csv-map-header">CSV-kolom</th>
                                <th class="csv-map-sample">Voorbeeldwaarde</th>
                                <th class="csv-map-target">Koppel aan</th>
                            </tr>
                        </thead>
                        <tbody>${rijenHtml}</tbody>
                    </table>
                    <div id="csv-map-fout" class="csv-fout" style="display:none;"></div>
                </div>
                <div class="modal-knoppen">
                    <button class="btn-secondary" id="csv-terug">← Vorige</button>
                    <button class="btn-secondary" id="csv-annuleer">Annuleren</button>
                    <button class="btn-primary" id="csv-volgende">Volgende →</button>
                </div>
            </div>
        </div>`;

    document.getElementById('csv-modal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);

    document.getElementById('csv-sluit').addEventListener('click', _csvSluit);
    document.getElementById('csv-annuleer').addEventListener('click', _csvSluit);
    document.getElementById('csv-terug').addEventListener('click', _csvOpenStap1);
    document.getElementById('csv-volgende').addEventListener('click', _csvNaarStap3);

    // Mapping bijwerken bij elke wijziging
    document.querySelectorAll('.csv-map-sel').forEach(sel => {
        sel.addEventListener('change', () => {
            const kol = parseInt(sel.dataset.kol);
            _csvImportState.mapping[kol] = sel.value;
        });
    });
}

async function _csvNaarStap3() {
    const fout = _csvValideerMapping();
    const foutEl = document.getElementById('csv-map-fout');
    if (fout) {
        foutEl.textContent = fout;
        foutEl.style.display = '';
        return;
    }
    foutEl.style.display = 'none';

    // DCs ophalen van de wedstrijd (laad ze elke keer vers — wedstrijd-data
    // kan tussentijds gewijzigd zijn door een andere operator)
    try {
        const res = await fetch('api/csv_import.php?action=dcs&competition_id=' +
                                encodeURIComponent(_csvImportState.compId));
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);
        _csvImportState.dcs            = data.dcs;
        _csvImportState.uniekAfstanden = data.unieke_afstanden;
    } catch (err) {
        foutEl.textContent   = 'Fout bij ophalen DCs: ' + err.message;
        foutEl.style.display = '';
        return;
    }

    if (!_csvImportState.dcs.length) {
        foutEl.textContent   = '⚠ Geen DCs gevonden voor deze wedstrijd. ' +
            'Voeg eerst categorieën + afstanden toe via de "+ Wedstrijd"-modal.';
        foutEl.style.display = '';
        return;
    }

    _csvOpenStap3();
}

// ── Stap 3: DC-toewijzing ───────────────────────────────────────────────────
// Drie secties:
//   A. Afstand-naming: per DC-marker-kolom kiest operator welke afstand
//      dat is (bv. CSV-kolom "200" → "Flying lap" of "Sprint 500m" uit DC)
//   B. Cat-mapping: cat-groep (Pupil/Cadet/...) × geslacht → KNSB-code
//      (HP1/DP1/HKA/...) — defaults op basis van standaard-mapping
//   C. DC-toewijzing: matrix [cat-code × afstand-naam] → DC

function _csvOpenStap3() {
    // Default afstand-mapping per DC-marker-kolom (slim raden uit header)
    const dcMarkerKols = Object.entries(_csvImportState.mapping)
        .filter(([, t]) => t === 'dc_marker')
        .map(([k]) => parseInt(k));
    if (!_csvImportState.afstandPerKol) _csvImportState.afstandPerKol = {};
    dcMarkerKols.forEach(k => {
        if (_csvImportState.afstandPerKol[k] === undefined) {
            _csvImportState.afstandPerKol[k] =
                _csvRaadAfstand(_csvImportState.headers[k]) || '';
        }
    });

    // Default cat-mapping (Pupil M → HP1 etc.). Operator kan overrullen.
    if (!_csvImportState.catMapping) {
        _csvImportState.catMapping = _csvDefaultCatMapping();
    }

    // Welke cat-groepen komen voor in de data (uniek, gefilterd)
    const catKol = _csvKolIndex('cat_groep');
    const gKol   = _csvKolIndex('gender');
    const cats   = new Set();
    if (catKol != null && gKol != null) {
        _csvImportState.rows.forEach(r => {
            const c = _csvNormCat(r[catKol]);
            const g = _csvNormGender(r[gKol]);
            if (c && g) cats.add(c + '|' + g);
        });
    }
    _csvImportState.aanwezigeCats = [...cats].sort();

    // Default DC-toewijzing leeg (operator kiest)
    if (!_csvImportState.dcToewijzing) _csvImportState.dcToewijzing = {};

    const html = `
        <div class="modal-overlay" id="csv-modal" data-stap="3">
            <div class="modal-dialog csv-modal-dialog csv-modal-dialog-wide">
                <div class="modal-header">
                    <h3>📥 CSV Importeren — Stap 3 van 4: DC-toewijzing</h3>
                    <button class="modal-sluit" id="csv-sluit" title="Sluiten">&times;</button>
                </div>
                <div class="modal-body csv-modal-body-scroll">
                    ${_csvSectieA(dcMarkerKols)}
                    ${_csvSectieB()}
                    ${_csvSectieC()}
                    <div id="csv-dc-fout" class="csv-fout" style="display:none;"></div>
                </div>
                <div class="modal-knoppen">
                    <button class="btn-secondary" id="csv-terug">← Vorige</button>
                    <button class="btn-secondary" id="csv-annuleer">Annuleren</button>
                    <button class="btn-primary" id="csv-volgende">Volgende →</button>
                </div>
            </div>
        </div>`;

    document.getElementById('csv-modal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);

    document.getElementById('csv-sluit').addEventListener('click', _csvSluit);
    document.getElementById('csv-annuleer').addEventListener('click', _csvSluit);
    document.getElementById('csv-terug').addEventListener('click', _csvOpenStap2);
    document.getElementById('csv-volgende').addEventListener('click', _csvNaarStap4);

    // Event listeners voor de drie secties
    document.querySelectorAll('.csv-afstand-sel').forEach(sel => {
        sel.addEventListener('change', () => {
            _csvImportState.afstandPerKol[parseInt(sel.dataset.kol)] = sel.value;
            _csvRerenderSectieC();
        });
    });
    document.querySelectorAll('.csv-cat-map-sel').forEach(sel => {
        sel.addEventListener('change', () => {
            _csvImportState.catMapping[sel.dataset.key] = sel.value;
            _csvRerenderSectieC();
        });
    });
    _csvBindSectieCListeners();
}

// Sectie A: per dc_marker-kolom → welke afstand?
function _csvSectieA(dcMarkerKols) {
    const opts = _csvImportState.uniekAfstanden.map(a =>
        `<option value="${_csvEsc(a.name)}">${_csvEsc(a.name)}${a.value_meters ? ` (${a.value_meters}m)` : ''}</option>`
    ).join('');
    const rijenHtml = dcMarkerKols.map(k => {
        const huidig = _csvImportState.afstandPerKol[k] || '';
        return `
            <tr>
                <td class="csv-afst-header">${_csvEsc(_csvImportState.headers[k])}</td>
                <td>
                    <select class="csv-afstand-sel" data-kol="${k}">
                        <option value="">— Kies afstand —</option>
                        ${opts.replace(`value="${_csvEsc(huidig)}"`, `value="${_csvEsc(huidig)}" selected`)}
                    </select>
                </td>
            </tr>`;
    }).join('');
    return `
        <div class="csv-sectie">
            <h4>A. Welke afstand hoort bij elke DC-markering-kolom?</h4>
            <p class="csv-uitleg-klein">
                In de CSV staan kolommen als <code>200</code>, <code>1000</code> of <code>afvalkoers</code>.
                Koppel ze aan de afstanden die in deze wedstrijd bestaan.
            </p>
            <table class="csv-afst-tabel">
                <thead><tr><th>CSV-kolom</th><th>Afstand in wedstrijd</th></tr></thead>
                <tbody>${rijenHtml}</tbody>
            </table>
        </div>`;
}

// Sectie B: cat-groep × geslacht → KNSB-code
function _csvSectieB() {
    const groepen  = ['Pupil', 'Cadet', 'Junior', 'Youth', 'Senior'];
    const geslachten = [['M', 'Heren'], ['W', 'Dames']];
    const rijenHtml = groepen.flatMap(g =>
        geslachten.map(([gCode, gLabel]) => {
            const key    = g + '|' + gCode;
            const huidig = _csvImportState.catMapping[key] || '';
            return `
                <tr>
                    <td>${_csvEsc(g)}</td>
                    <td>${_csvEsc(gLabel)} (${_csvEsc(gCode)})</td>
                    <td>
                        <input type="text" class="csv-cat-map-sel" data-key="${_csvEsc(key)}"
                               value="${_csvEsc(huidig)}" maxlength="10" placeholder="bv. HP1"
                               style="width:80px;text-transform:uppercase;">
                    </td>
                </tr>`;
        })
    ).join('');
    return `
        <div class="csv-sectie">
            <h4>B. Categorie-mapping: groep + geslacht → KNSB-code</h4>
            <p class="csv-uitleg-klein">
                Pas aan als je organisatie een afwijkende code gebruikt. Default voor
                deze wedstrijd: Pupil→P1, Cadet→KA, Junior→JA, Youth→JB, Senior→SA.
            </p>
            <table class="csv-cat-tabel">
                <thead><tr><th>Groep</th><th>Geslacht</th><th>KNSB-code</th></tr></thead>
                <tbody>${rijenHtml}</tbody>
            </table>
        </div>`;
}

// Sectie C: matrix [cat-code × afstand-naam] → DC dropdown
function _csvSectieC() {
    return `<div class="csv-sectie" id="csv-sectie-c">
        <h4>C. Welke DC voor welke combinatie?</h4>
        <p class="csv-uitleg-klein">
            Voor elke gevonden categorie × afstand: kies de DC in deze wedstrijd.
            Bij "Negeren" wordt die combinatie overgeslagen (rijders ervan komen
            in een waarschuwingslijst).
        </p>
        ${_csvSectieCBody()}
    </div>`;
}

function _csvSectieCBody() {
    const aanwezig = _csvImportState.aanwezigeCats || [];
    if (!aanwezig.length) {
        return `<div class="csv-uitleg-klein"><i>(Geen cat × geslacht combinaties gevonden — controleer kolom-mapping in stap 2.)</i></div>`;
    }
    // Lijst van unieke afstand-namen die operator heeft toegewezen aan dc_marker-kolommen
    const gebruiktAfst = [...new Set(Object.values(_csvImportState.afstandPerKol || {}).filter(Boolean))];
    if (!gebruiktAfst.length) {
        return `<div class="csv-uitleg-klein"><i>(Eerst afstanden kiezen in sectie A.)</i></div>`;
    }
    const dcOpts = _csvImportState.dcs.map(dc =>
        `<option value="${_csvEsc(dc.id)}">${_csvEsc(dc.display_name)}</option>`
    ).join('');
    const headerHtml = `<tr>
        <th>Cat-code</th>
        ${gebruiktAfst.map(a => `<th>${_csvEsc(a)}</th>`).join('')}
    </tr>`;
    const rijenHtml = aanwezig.map(catGeslacht => {
        const [cat, geslacht] = catGeslacht.split('|');
        const code = _csvImportState.catMapping[cat + '|' + geslacht] || '?';
        const cels = gebruiktAfst.map(afst => {
            const key    = code + '|' + afst;
            const huidig = _csvImportState.dcToewijzing[key] || '';
            return `<td>
                <select class="csv-dc-sel" data-key="${_csvEsc(key)}">
                    <option value="">— Negeren —</option>
                    ${dcOpts.replace(`value="${_csvEsc(huidig)}"`, `value="${_csvEsc(huidig)}" selected`)}
                </select>
            </td>`;
        }).join('');
        return `<tr>
            <td><strong>${_csvEsc(code)}</strong><br><small>${_csvEsc(cat)} ${_csvEsc(geslacht)}</small></td>
            ${cels}
        </tr>`;
    }).join('');
    return `<table class="csv-dc-tabel">
        <thead>${headerHtml}</thead>
        <tbody>${rijenHtml}</tbody>
    </table>`;
}

// Sectie C herrenderen (bij wijziging in A of B die het matrix raakt)
function _csvRerenderSectieC() {
    const sectie = document.getElementById('csv-sectie-c');
    if (!sectie) return;
    // Re-build inhoud onder de h4
    const h4 = sectie.querySelector('h4');
    const uitleg = sectie.querySelector('.csv-uitleg-klein');
    sectie.innerHTML = h4.outerHTML + uitleg.outerHTML + _csvSectieCBody();
    _csvBindSectieCListeners();
}

function _csvBindSectieCListeners() {
    document.querySelectorAll('.csv-dc-sel').forEach(sel => {
        sel.addEventListener('change', () => {
            _csvImportState.dcToewijzing[sel.dataset.key] = sel.value;
        });
    });
}

async function _csvNaarStap4() {
    // Validatie: alle aanwezigeCats × gebruiktAfst combinaties moeten een DC
    // hebben (of expliciet op Negeren staan). Voor nu: warning als er nog
    // lege cellen zijn, maar geen harde block — operator beslist.
    const foutEl = document.getElementById('csv-dc-fout');
    const aanwezig = _csvImportState.aanwezigeCats || [];
    const gebruiktAfst = [...new Set(Object.values(_csvImportState.afstandPerKol || {}).filter(Boolean))];
    const ontbreekt = [];
    for (const cg of aanwezig) {
        const [cat, gesl] = cg.split('|');
        const code = _csvImportState.catMapping[cat + '|' + gesl] || '?';
        for (const a of gebruiktAfst) {
            const key = code + '|' + a;
            if (!_csvImportState.dcToewijzing[key]) {
                ontbreekt.push(`${code} × ${a}`);
            }
        }
    }
    if (ontbreekt.length) {
        const lijst = ontbreekt.slice(0, 5).join(', ') + (ontbreekt.length > 5 ? ` (+${ontbreekt.length - 5} meer)` : '');
        const ok = confirm(
            `Er zijn ${ontbreekt.length} cat × afstand-combinaties zonder DC: ${lijst}\n\n` +
            `Rijders met die combinaties worden overgeslagen tijdens import.\n\n` +
            `Doorgaan?`
        );
        if (!ok) return;
    }

    // Match-preview ophalen
    if (foutEl) foutEl.style.display = 'none';
    try {
        const res = await fetch('api/csv_import.php?action=match_preview', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                competition_id: _csvImportState.compId,
                mapping:        _csvImportState.mapping,
                rows:           _csvImportState.rows,
                cat_mapping:    _csvImportState.catMapping,
            }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);
        _csvImportState.matches      = data.matches;
        // Per rij: opslaan welke actie de operator kiest (= recommended default).
        // 'link' + license_key, '__new__', of '__skip__'.
        _csvImportState.matchActies  = {};
        data.matches.forEach(m => {
            _csvImportState.matchActies[m.row_idx] = m.recommended;
        });
    } catch (err) {
        if (foutEl) {
            foutEl.textContent   = 'Fout bij match-preview: ' + err.message;
            foutEl.style.display = '';
        }
        return;
    }

    _csvOpenStap4();
}

// ── Stap 4: Persoon-match review ────────────────────────────────────────────

function _csvOpenStap4() {
    const matches = _csvImportState.matches || [];
    const stats   = _csvMatchStats(matches);

    const rijenHtml = matches.map(m => _csvMatchRijHtml(m)).join('');

    const html = `
        <div class="modal-overlay" id="csv-modal" data-stap="4">
            <div class="modal-dialog csv-modal-dialog csv-modal-dialog-wide">
                <div class="modal-header">
                    <h3>📥 CSV Importeren — Stap 4 van 4: Persoon-match review</h3>
                    <button class="modal-sluit" id="csv-sluit" title="Sluiten">&times;</button>
                </div>
                <div class="modal-body csv-modal-body-scroll">
                    <p class="csv-uitleg">
                        Per CSV-rij is gezocht naar een bestaande persoon in de database.
                        Controleer de match-status en pas indien nodig aan via de dropdown.
                    </p>
                    <div class="csv-match-stats">
                        <span class="csv-stat csv-stat-link">🟢 Auto-link: <strong>${stats.tier12}</strong></span>
                        <span class="csv-stat csv-stat-kies">🟡 Meerdere kandidaten: <strong>${stats.tier3}</strong></span>
                        <span class="csv-stat csv-stat-nieuw">🔴 Nieuw extern: <strong>${stats.tier4}</strong></span>
                        <span class="csv-stat">Totaal: <strong>${matches.length}</strong></span>
                    </div>
                    <table class="csv-match-tabel">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Naam uit CSV</th>
                                <th>Club</th>
                                <th>Startnr</th>
                                <th>Status</th>
                                <th>Actie</th>
                            </tr>
                        </thead>
                        <tbody id="csv-match-tbody">${rijenHtml}</tbody>
                    </table>
                    <div id="csv-commit-fout" class="csv-fout" style="display:none;"></div>
                </div>
                <div class="modal-knoppen">
                    <button class="btn-secondary" id="csv-terug">← Vorige</button>
                    <button class="btn-secondary" id="csv-annuleer">Annuleren</button>
                    <button class="btn-primary" id="csv-importeer">✓ Importeer</button>
                </div>
            </div>
        </div>`;

    document.getElementById('csv-modal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);

    document.getElementById('csv-sluit').addEventListener('click', _csvSluit);
    document.getElementById('csv-annuleer').addEventListener('click', _csvSluit);
    document.getElementById('csv-terug').addEventListener('click', _csvOpenStap3);
    document.getElementById('csv-importeer').addEventListener('click', _csvDoeImport);

    // Bind dropdown-listeners per rij
    document.querySelectorAll('.csv-match-actie').forEach(sel => {
        sel.addEventListener('change', () => {
            _csvImportState.matchActies[parseInt(sel.dataset.rowIdx)] = sel.value;
        });
    });

    // 🔍 zoek-knop per rij: paneel toggelen
    document.querySelectorAll('.csv-match-zoek-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const rowIdx = parseInt(btn.dataset.rowIdx);
            const panel  = document.getElementById('csv-zoek-panel-' + rowIdx);
            const open   = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : '';
            if (!open) {
                // Open: focus input + reset
                const inp = panel.querySelector('.csv-match-zoek-input');
                inp.value = '';
                document.getElementById('csv-zoek-resultaten-' + rowIdx).innerHTML = '';
                // Pre-fill met naam uit de rij voor sneller zoeken
                const m = _csvImportState.matches.find(x => x.row_idx === rowIdx);
                if (m?.naam) {
                    inp.value = m.naam;
                    _csvDoeZoek(rowIdx, m.naam);
                }
                setTimeout(() => { inp.focus(); inp.select(); }, 50);
            }
        });
    });

    // Zoek-input per rij: debounced search
    document.querySelectorAll('.csv-match-zoek-input').forEach(inp => {
        let timer = null;
        inp.addEventListener('input', () => {
            const rowIdx = parseInt(inp.dataset.rowIdx);
            const q      = inp.value.trim();
            clearTimeout(timer);
            timer = setTimeout(() => _csvDoeZoek(rowIdx, q), 300);
        });
    });
}

// Voer zoekopdracht uit en render resultaten in het zoek-paneel van een rij.
async function _csvDoeZoek(rowIdx, query) {
    const resEl = document.getElementById('csv-zoek-resultaten-' + rowIdx);
    if (!resEl) return;
    if (query.length < 2) {
        resEl.innerHTML = '<div class="csv-zoek-leeg">Typ minstens 2 tekens…</div>';
        return;
    }
    resEl.innerHTML = '<div class="csv-zoek-leeg">Zoeken…</div>';
    try {
        const r = await fetch('api/csv_import.php?action=zoek_personen&q=' +
                              encodeURIComponent(query));
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.error || 'HTTP ' + r.status);
        if (!d.results.length) {
            resEl.innerHTML = '<div class="csv-zoek-leeg">Geen resultaten gevonden.</div>';
            return;
        }
        resEl.innerHTML = d.results.map(p => {
            // metaParts = PURE tekst (brondata uit persons; ongesaneerd opgeslagen).
            // Eén keer escapen; de vaste "extern"-badge apart als hardcoded markup
            // erachter — nooit user-data ongeëscaped in de zichtbare render.
            const metaParts = [
                p.birth_year ? `geb. ${p.birth_year}` : null,
                p.club || null,
                p.start_number ? `snr ${p.start_number}` : null,
            ].filter(Boolean).join(' · ');
            const metaHtml = _csvEsc(metaParts)
                + (p.extern ? (metaParts ? ' · ' : '') + '<span class="csv-zoek-extern">extern</span>' : '');
            // data-meta = platte tekst (incl. "extern"-woord zoals voorheen; wordt
            // teruggelezen in het koppel-dropdown-label via textContent).
            const metaText = metaParts + (p.extern ? (metaParts ? ' · ' : '') + 'extern' : '');
            return `<button class="csv-zoek-resultaat" data-row-idx="${rowIdx}" data-license="${_csvEsc(p.license_key)}" data-fullname="${_csvEsc(p.full_name)}" data-meta="${_csvEsc(metaText)}">
                <strong>${_csvEsc(p.full_name)}</strong>
                <small>${metaHtml}</small>
            </button>`;
        }).join('');
        // Bind click op resultaten
        resEl.querySelectorAll('.csv-zoek-resultaat').forEach(btn => {
            btn.addEventListener('click', () => {
                _csvKoppelHandmatig(
                    parseInt(btn.dataset.rowIdx),
                    btn.dataset.license,
                    btn.dataset.fullname,
                    btn.dataset.meta,
                );
            });
        });
    } catch (err) {
        resEl.innerHTML = '<div class="csv-zoek-fout">⚠ ' + _csvEsc(err.message) + '</div>';
    }
}

// Een gekozen zoek-resultaat aan een rij koppelen. We voegen 'em toe als
// expliciete optie in de dropdown (zodat hij geselecteerd blijft) en
// updaten de state.
function _csvKoppelHandmatig(rowIdx, licenseKey, fullName, meta) {
    _csvImportState.matchActies[rowIdx] = licenseKey;

    // Update de dropdown: voeg de nieuwe optie toe (of update bestaande) en
    // selecteer 'em. Verwijder eerst eventueel een vroegere handmatige optie.
    const sel = document.querySelector(`select.csv-match-actie[data-row-idx="${rowIdx}"]`);
    if (sel) {
        // Bestaat de license al als optie? Selecteer 'em.
        let opt = sel.querySelector(`option[value="${CSS.escape(licenseKey)}"]`);
        if (!opt) {
            opt = document.createElement('option');
            opt.value = licenseKey;
            opt.textContent = `Koppel: ${fullName}${meta ? ' (' + meta + ')' : ''}`;
            // Plaats vooraan
            sel.insertBefore(opt, sel.firstChild);
        }
        sel.value = licenseKey;
    }

    // Rij-status visueel updaten naar 'ok'
    const tr = document.querySelector(`tr[data-row-idx="${rowIdx}"]`);
    if (tr) {
        tr.classList.remove('csv-match-rij-keuze', 'csv-match-rij-nieuw');
        tr.classList.add('csv-match-rij-ok');
        // Status-cel updaten
        const statusCel = tr.querySelectorAll('td')[4];
        if (statusCel) statusCel.innerHTML = '🟢 Handmatig gekoppeld';
    }

    // Zoek-paneel sluiten
    const panel = document.getElementById('csv-zoek-panel-' + rowIdx);
    if (panel) panel.style.display = 'none';
}

function _csvMatchStats(matches) {
    const stats = { tier12: 0, tier3: 0, tier4: 0 };
    matches.forEach(m => {
        if (m.tier <= 2)       stats.tier12++;
        else if (m.tier === 3) stats.tier3++;
        else                   stats.tier4++;
    });
    return stats;
}

function _csvMatchRijHtml(m) {
    const tierLabel = {
        1: '🟢 KNSB-nr match',
        2: '🟢 Naam+club match',
        3: '🟡 Meerdere kandidaten',
        4: '🔴 Geen match → nieuw',
    }[m.tier];
    const rijKlasse = m.tier <= 2 ? 'csv-match-rij-ok'
                    : m.tier === 3 ? 'csv-match-rij-keuze'
                    : 'csv-match-rij-nieuw';
    const huidigeKeuze = _csvImportState.matchActies[m.row_idx] || m.recommended;
    // Bouw dropdown-opties
    const opts = [
        ...m.candidates.map(c => {
            const sel  = c.license_key === huidigeKeuze ? ' selected' : '';
            const meta = [
                c.birth_year ? `geb. ${c.birth_year}` : null,
                c.club || null,
                c.extern ? 'extern' : null,
            ].filter(Boolean).join(', ');
            return `<option value="${_csvEsc(c.license_key)}"${sel}>` +
                   `Koppel: ${_csvEsc(c.full_name)} (${_csvEsc(meta || '—')})` +
                   `</option>`;
        }),
        `<option value="__new__"${huidigeKeuze === '__new__' ? ' selected' : ''}>` +
        `Nieuw extern persoon aanmaken</option>`,
        `<option value="__skip__"${huidigeKeuze === '__skip__' ? ' selected' : ''}>` +
        `⊘ Skip deze rij</option>`,
    ].join('');
    return `<tr class="${rijKlasse}" data-row-idx="${m.row_idx}">
        <td>${m.row_idx + 1}</td>
        <td>${_csvEsc(m.naam)}</td>
        <td>${_csvEsc(m.club)}</td>
        <td>${_csvEsc(m.startnr)}</td>
        <td>${tierLabel}</td>
        <td class="csv-match-actie-cel">
            <div class="csv-match-actie-row">
                <select class="csv-match-actie" data-row-idx="${m.row_idx}">
                    ${opts}
                </select>
                <button class="csv-match-zoek-btn" data-row-idx="${m.row_idx}" title="Zoek bestaand persoon">🔍</button>
            </div>
            <div class="csv-match-zoek-panel" id="csv-zoek-panel-${m.row_idx}" style="display:none;">
                <input type="text" class="csv-match-zoek-input" placeholder="Zoek op naam (min. 2 tekens)…" data-row-idx="${m.row_idx}">
                <div class="csv-match-zoek-resultaten" id="csv-zoek-resultaten-${m.row_idx}"></div>
            </div>
        </td>
    </tr>`;
}

async function _csvDoeImport() {
    const foutEl = document.getElementById('csv-commit-fout');
    const importBtn = document.getElementById('csv-importeer');
    foutEl.style.display = 'none';
    importBtn.disabled  = true;
    importBtn.textContent = '⏳ Bezig met importeren…';

    try {
        const res = await fetch('api/csv_import.php?action=commit', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                competition_id:  _csvImportState.compId,
                mapping:         _csvImportState.mapping,
                rows:            _csvImportState.rows,
                cat_mapping:     _csvImportState.catMapping,
                afstand_per_kol: _csvImportState.afstandPerKol,
                dc_toewijzing:   _csvImportState.dcToewijzing,
                match_acties:    _csvImportState.matchActies,
            }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);

        _csvToonResultaat(data.stats);
    } catch (err) {
        foutEl.textContent   = '⚠ Import mislukt: ' + err.message;
        foutEl.style.display = '';
        importBtn.disabled   = false;
        importBtn.textContent = '✓ Importeer';
    }
}

// Resultaat-scherm na succesvolle import. Operator klikt op "Klaar" en de
// wizard sluit + de Importeer-tab wordt vers geladen zodat de nieuwe rijders
// zichtbaar zijn.
function _csvToonResultaat(stats) {
    const errs = stats.errors || [];
    const zonderDc = stats.rijen_zonder_dc || [];

    const errBlokHtml = errs.length
        ? `<div class="csv-result-errs">
              <h4>⚠ ${errs.length} foutmelding${errs.length !== 1 ? 'en' : ''}:</h4>
              <ul>${errs.slice(0, 10).map(e => `<li>${_csvEsc(e)}</li>`).join('')}</ul>
              ${errs.length > 10 ? `<p><em>(+ ${errs.length - 10} meer in serverlog)</em></p>` : ''}
          </div>`
        : '';

    const zonderDcHtml = zonderDc.length
        ? `<div class="csv-result-warn">
              ⚠ ${zonderDc.length} rij${zonderDc.length !== 1 ? 'en' : ''} hadden combinaties (cat × afstand)
              waar geen DC aan was toegewezen — die entries zijn overgeslagen.
              Rijders zijn wel aangemaakt/gelinked voor combinaties die wél een DC hadden.
          </div>`
        : '';

    const html = `
        <div class="modal-overlay" id="csv-modal" data-stap="klaar">
            <div class="modal-dialog csv-modal-dialog">
                <div class="modal-header">
                    <h3>${errs.length ? '⚠' : '✅'} Import voltooid</h3>
                    <button class="modal-sluit" id="csv-sluit" title="Sluiten">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="csv-result-stats">
                        <div class="csv-result-row">
                            <span class="csv-result-label">🆕 Nieuwe personen aangemaakt:</span>
                            <span class="csv-result-val">${stats.nieuw}</span>
                        </div>
                        <div class="csv-result-row">
                            <span class="csv-result-label">🔗 Gekoppeld aan bestaande:</span>
                            <span class="csv-result-val">${stats.gelinked}</span>
                        </div>
                        <div class="csv-result-row">
                            <span class="csv-result-label">⊘ Overgeslagen:</span>
                            <span class="csv-result-val">${stats.skipped}</span>
                        </div>
                        <div class="csv-result-row csv-result-divider">
                            <span class="csv-result-label">➕ Nieuwe entries:</span>
                            <span class="csv-result-val">${stats.entries_nieuw}</span>
                        </div>
                        <div class="csv-result-row">
                            <span class="csv-result-label">↻ Bijgewerkte entries:</span>
                            <span class="csv-result-val">${stats.entries_upgedate}</span>
                        </div>
                    </div>
                    ${zonderDcHtml}
                    ${errBlokHtml}
                </div>
                <div class="modal-knoppen">
                    <button class="btn-primary" id="csv-klaar">Klaar — vernieuw lijst</button>
                </div>
            </div>
        </div>`;

    document.getElementById('csv-modal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('csv-sluit').addEventListener('click', _csvSluit);
    document.getElementById('csv-klaar').addEventListener('click', () => {
        _csvSluit();
        // Importeer-tab vers laden — nieuwe rijders verschijnen in beheer
        if (typeof herlaadVergelijking === 'function') {
            herlaadVergelijking();
        }
    });
}

// ── Helpers voor stap 3 ─────────────────────────────────────────────────────

function _csvDefaultCatMapping() {
    return {
        'Pupil|M':  'HP1', 'Pupil|W':  'DP1',
        'Cadet|M':  'HKA', 'Cadet|W':  'DKA',
        'Junior|M': 'HJA', 'Junior|W': 'DJA',
        'Youth|M':  'HJB', 'Youth|W':  'DJB',
        'Senior|M': 'HSA', 'Senior|W': 'DSA',
    };
}

function _csvKolIndex(targetType) {
    for (const [k, t] of Object.entries(_csvImportState.mapping)) {
        if (t === targetType) return parseInt(k);
    }
    return null;
}

function _csvNormCat(v) {
    const s = String(v || '').trim().toLowerCase();
    if (s.startsWith('pupil'))  return 'Pupil';
    if (s.startsWith('cadet'))  return 'Cadet';
    if (s.startsWith('junior')) return 'Junior';
    if (s.startsWith('youth'))  return 'Youth';
    if (s.startsWith('senior')) return 'Senior';
    return null;
}

function _csvNormGender(v) {
    const s = String(v || '').trim().toUpperCase();
    if (s === 'M' || s === 'H') return 'M';
    if (s === 'W' || s === 'V' || s === 'F') return 'W';
    return null;
}

function _csvRaadAfstand(header) {
    const h = String(header || '').toLowerCase().trim();
    if (!h) return '';
    // Zoek in unieke_afstanden naar een match op naam of meters
    for (const a of (_csvImportState.uniekAfstanden || [])) {
        const an = a.name.toLowerCase();
        if (an === h)                   return a.name;
        if (h.includes(an))             return a.name;
        if (an.includes(h))             return a.name;
        if (a.value_meters && h.match(new RegExp('\\b' + a.value_meters + '\\b'))) return a.name;
    }
    return '';
}

// Valideer dat de mapping minimaal genoeg info bevat om verder te gaan.
// Returns null bij OK, of een foutmelding-string anders.
function _csvValideerMapping() {
    const targets = Object.values(_csvImportState.mapping);
    const heeftNaam = targets.includes('name_full') ||
                      (targets.includes('name_first') && targets.includes('name_last'));
    if (!heeftNaam) {
        return '⚠ Kies ofwel een "Volledige naam"-kolom, of zowel "Voornaam-deel" als "Achternaam-deel".';
    }
    if (!targets.includes('gender')) {
        return '⚠ Geen geslacht-kolom gekozen. Vereist voor categorie-bepaling (HP1 vs DP1, etc.).';
    }
    if (!targets.includes('cat_groep')) {
        return '⚠ Geen categorie-groep kolom gekozen (Pupil/Cadet/Junior/Youth/Senior).';
    }
    const dcCount = targets.filter(t => t === 'dc_marker').length;
    if (dcCount === 0) {
        return '⚠ Tenminste 1 DC-markering kolom nodig (x = doet mee aan deze afstand).';
    }
    return null;
}

// Slim raden welke target bij een CSV-header hoort op basis van keywords.
// De operator kan altijd handmatig overrulen. Geen match → leeg (Negeren).
function _csvRaadTarget(header) {
    const h = String(header || '').toLowerCase().trim();
    if (!h) return '';
    if (/^voornaam|^first|^given/.test(h))                  return 'name_first';
    if (/^tussenvoegsel|^infix|^middle/.test(h))            return 'name_tussen';
    if (/^achternaam|^last|^family|^sur/.test(h))           return 'name_last';
    if (/^(volledige.?naam|naam$|full.?name|name)$/.test(h))return 'name_full';
    if (/geslacht|sex|gender/.test(h))                      return 'gender';
    if (/land|nation|country/.test(h))                      return 'nationality';
    if (/geboorte|birth/.test(h))                           return 'birth_year';
    if (/(start.?(nr|nummer|number)|^nr$|^bib|rugnummer)/.test(h)) return 'start_number';
    if (/^cat$|categorie|category/.test(h))                 return 'cat_groep';
    if (/sponsor/.test(h))                                  return 'sponsor';
    if (/club|team|vereniging/.test(h))                     return 'club_short';
    // Korte numerieke headers (200, 1000) of bekende race-types als DC-marker
    if (/^\d{2,4}(m|m?)?$/.test(h) ||
        /punten|afval|sprint|flying|tijdrit|lange/.test(h)) return 'dc_marker';
    return '';
}

// Bouw <option>-tags voor de target-dropdown, met selectie op huidige waarde.
function _csvTargetsAsOptions(huidig) {
    let html = '';
    let huidigeGroep = '';
    _CSV_TARGETS.forEach(t => {
        if (t.groep !== huidigeGroep) {
            if (huidigeGroep) html += '</optgroup>';
            if (t.groep) html += `<optgroup label="${_csvEsc(t.groep)}">`;
            huidigeGroep = t.groep;
        }
        const sel = t.val === huidig ? ' selected' : '';
        html += `<option value="${_csvEsc(t.val)}"${sel}>${_csvEsc(t.label)}</option>`;
    });
    if (huidigeGroep) html += '</optgroup>';
    return html;
}

// Pak een sample-waarde uit de preview voor deze kolom. Eerste niet-lege
// waarde uit de eerste 5 rijen, of leeg als geen enkele rij waarde heeft.
function _csvBesteSample(kolIdx) {
    const preview = _csvImportState.preview || [];
    for (const rij of preview) {
        const v = rij[kolIdx];
        if (v != null && String(v).trim() !== '') return String(v);
    }
    return '';
}

// File-input handler — upload bestand naar parse-endpoint
async function _csvBestandGekozen(ev) {
    const file = ev.target.files[0];
    if (!file) return;

    const info       = document.getElementById('csv-bestand-info');
    const previewBlk = document.getElementById('csv-preview-blok');
    const foutEl     = document.getElementById('csv-fout');
    const volgendeBtn = document.getElementById('csv-volgende');

    info.innerHTML = `<span class="csv-bestand-naam"></span>
                      <span class="csv-bestand-grootte"></span>
                      <span class="csv-status">Bezig…</span>`;
    // Naam én grootte via textContent (niet innerHTML): beide zijn DOM-bronnen
    // (van de file-input) → inherent veilig, geen HTML-interpretatie (CodeQL
    // js/xss-through-dom). Alleen de vaste "Bezig…"-tekst blijft in de innerHTML.
    info.querySelector('.csv-bestand-naam').textContent = file.name;
    info.querySelector('.csv-bestand-grootte').textContent = _csvFormatBytes(file.size);
    previewBlk.style.display = 'none';
    foutEl.style.display     = 'none';
    volgendeBtn.disabled     = true;

    try {
        const fd = new FormData();
        fd.append('csv', file);
        const res = await fetch('api/csv_import.php?action=parse', {
            method: 'POST',
            body:   fd,
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);

        _csvImportState.bestand   = file;
        _csvImportState.headers   = data.headers;
        _csvImportState.preview   = data.preview;
        _csvImportState.rows      = data.rows || [];
        _csvImportState.total     = data.total;
        _csvImportState.delimiter = data.delimiter;
        _csvImportState.encoding  = data.encoding;

        // Preview-tabel bouwen
        const tableHtml = `
            <table class="csv-preview-tabel">
                <thead><tr>${data.headers.map(h => `<th>${_csvEsc(h)}</th>`).join('')}</tr></thead>
                <tbody>${data.preview.map(rij =>
                    `<tr>${rij.map(v => `<td>${_csvEsc(v)}</td>`).join('')}</tr>`
                ).join('')}</tbody>
            </table>`;
        document.getElementById('csv-preview-tabel-wrap').innerHTML = tableHtml;

        const delimNaam = data.delimiter === 'tab' ? 'TAB' : `"${data.delimiter}"`;
        document.getElementById('csv-meta-info').innerHTML = `
            <span><strong>${data.total}</strong> data-rijen</span>
            <span><strong>${data.headers.length}</strong> kolommen</span>
            <span>Scheidingsteken: <strong>${delimNaam}</strong></span>
            <span>Codering: <strong>${_csvEsc(data.encoding)}</strong></span>`;

        previewBlk.style.display = '';
        info.querySelector('.csv-status').textContent = '✓ Bestand gelezen';
        info.querySelector('.csv-status').classList.add('csv-status-ok');
        volgendeBtn.disabled = false;
    } catch (err) {
        info.querySelector('.csv-status').textContent = '⚠ Fout';
        info.querySelector('.csv-status').classList.add('csv-status-fout');
        foutEl.textContent   = err.message;
        foutEl.style.display = '';
    }
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function _csvSluit() {
    document.getElementById('csv-modal')?.remove();
    _csvImportState = null;
}

function _csvEsc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
                          .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _csvFormatBytes(n) {
    if (n < 1024)        return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
}
