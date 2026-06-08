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

function _csvNaarStap3() {
    const fout = _csvValideerMapping();
    const foutEl = document.getElementById('csv-map-fout');
    if (fout) {
        foutEl.textContent = fout;
        foutEl.style.display = '';
        return;
    }
    // Stap 3 komt in volgende implementatie-ronde
    alert('Stap 3 (DC-toewijzing) komt in de volgende implementatie-ronde.\n\n' +
          'Huidige mapping ziet er goed uit — ' +
          Object.values(_csvImportState.mapping).filter(v => v).length +
          ' kolommen gekoppeld.');
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

    info.innerHTML = `<span class="csv-bestand-naam">${_csvEsc(file.name)}</span>
                      <span class="csv-bestand-grootte">${_csvFormatBytes(file.size)}</span>
                      <span class="csv-status">Bezig…</span>`;
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
