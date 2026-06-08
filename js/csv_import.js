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
    document.getElementById('csv-volgende').addEventListener('click', () => {
        // Stap 2 komt later — voor nu placeholder
        alert('Stap 2 (kolom-mapping) komt in de volgende implementatie-ronde.');
    });
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
