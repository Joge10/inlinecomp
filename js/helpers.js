// ── System → Helpers (admin-tools voor onderhoud / opschonen) ──────────────
//
// Eerste kaart: "Vastgelegde uitslagen opschonen". Detecteert wees-rijen in
// uitslag_afstand / uitslag_klassement (rijen waar geen heats meer onder
// zitten — typisch na wis-loting zonder de archief-uitslag mee te wissen)
// en biedt een knop om ze in één klap weg te halen.
//
// De Helpers-tab kan in de toekomst groeien met meer onderhouds-tools
// (cache-flush, transponder-cleanup, etc.) — elke nieuwe helper is een
// nieuwe kaart binnen #hp-container.

let _hpScanCache = null;

async function toonHelpersPagina() {
    const cont = el('hp-container');
    if (!cont) return;
    cont.innerHTML = `
        <div class="hp-card">
            <h3 class="hp-card-titel">🧹 Vastgelegde wees-uitslagen opschonen</h3>
            <p class="hp-card-uitleg">
                Soms blijven na een <em>Wis loting</em> de vastgelegde uitslagen of
                klassementen staan terwijl de onderliggende heats er niet meer zijn.
                Die "wees-rijen" maken oude wedstrijden inconsistent (uitslag toont
                resultaten waar geen ronde meer bij hoort). Hier kun je ze in één
                klap opruimen.
            </p>
            <div class="hp-card-acties">
                <button class="btn-secondary" id="hp-btn-scan">🔍 Scan</button>
                <span class="hp-status" id="hp-scan-status"></span>
            </div>
            <div class="hp-rapport" id="hp-rapport" style="display:none"></div>
        </div>

        <div class="hp-card" id="hp-csv-card">
            <h3 class="hp-card-titel">📥 CSV-export — eindklassement per DC</h3>
            <p class="hp-card-uitleg">
                Exporteer het vastgelegde dag-klassement van een wedstrijd
                naar CSV (geschikt voor MS Excel-NL met puntkomma). Kies de
                wedstrijd + DC, vink de gewenste kolommen aan en klik
                Exporteren. Per geselecteerde afstand komt er een aparte
                kolom met de punten voor die afstand.
            </p>
            <div class="hp-csv-veld">
                <label>1. Wedstrijd</label>
                <select class="inp" id="hp-csv-comp">
                    <option value="">— laden… —</option>
                </select>
            </div>
            <div class="hp-csv-veld">
                <label>2. Categorie / DC</label>
                <select class="inp" id="hp-csv-dc" disabled>
                    <option value="">— eerst wedstrijd kiezen —</option>
                </select>
            </div>
            <div class="hp-csv-veld">
                <label>3. Kolommen</label>
                <div id="hp-csv-cols" class="hp-csv-cols">
                    <em style="color:#888;font-size:12.5px">— eerst DC kiezen —</em>
                </div>
            </div>
            <div class="hp-card-acties" style="margin-top:10px">
                <button class="btn-primary" id="hp-btn-csv-exp" disabled>📥 Exporteer CSV</button>
                <span class="hp-status" id="hp-csv-status"></span>
            </div>
        </div>
    `;
    el('hp-btn-scan').addEventListener('click', _hpDoeScan);
    _hpCsvInit();
}

// ── CSV-export tool ──────────────────────────────────────────────────────────
// State: huidige wedstrijd-data (alle DCs + rijders + afstanden) wordt na
// wedstrijd-keuze 1× opgehaald en gecached. DC-wissel = client-side filter.
let _hpCsvData = null;       // {dcs: [...]} per wedstrijd
let _hpCsvAfstanden = [];    // afstand-namen van geselecteerde DC

const _HP_CSV_STD_COLS = [
    { id: 'rang',        label: 'Rang',       checked: true  },
    { id: 'startnummer', label: 'Startnummer', checked: true },
    { id: 'naam',        label: 'Naam',       checked: true  },
    { id: 'club',        label: 'Club',       checked: true  },
    { id: 'sponsor',     label: 'Sponsor',    checked: false },
    { id: 'persoon_cat', label: 'Categorie',  checked: false },
    { id: 'split_group', label: 'Splitgroep', checked: false },
    { id: 'punten_totaal', label: 'Totaal punten', checked: true },
    { id: 'licentie',    label: 'Licentie',   checked: false },
];

async function _hpCsvInit() {
    const sel = el('hp-csv-comp');
    sel.innerHTML = '<option value="">— laden… —</option>';
    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'csv_export_competitions' }),
        });
        const comps = await res.json();
        if (comps.error) throw new Error(comps.error);
        if (!Array.isArray(comps) || !comps.length) {
            sel.innerHTML = '<option value="">— geen wedstrijden met klassement —</option>';
            return;
        }
        sel.innerHTML = '<option value="">— kies wedstrijd —</option>' +
            comps.map(c => {
                const dat = c.datum ? new Date(c.datum).toLocaleDateString('nl-NL', {day:'2-digit', month:'2-digit', year:'numeric'}) : '?';
                return `<option value="${escHtml(c.competition_id)}">${escHtml(c.naam)} (${escHtml(dat)})</option>`;
            }).join('');
        sel.addEventListener('change', _hpCsvWedstrijdGekozen);
    } catch (e) {
        sel.innerHTML = `<option value="">⚠ Fout: ${escHtml(e.message)}</option>`;
    }
    el('hp-csv-dc').addEventListener('change', _hpCsvDcGekozen);
    el('hp-btn-csv-exp').addEventListener('click', _hpCsvExporteer);
}

async function _hpCsvWedstrijdGekozen() {
    const compId = el('hp-csv-comp').value;
    const dcSel  = el('hp-csv-dc');
    const cols   = el('hp-csv-cols');
    const btn    = el('hp-btn-csv-exp');
    dcSel.disabled = true;
    btn.disabled = true;
    cols.innerHTML = '<em style="color:#888;font-size:12.5px">— eerst DC kiezen —</em>';
    if (!compId) {
        dcSel.innerHTML = '<option value="">— eerst wedstrijd kiezen —</option>';
        return;
    }
    dcSel.innerHTML = '<option value="">— laden… —</option>';
    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'csv_export_data', competition_id: compId }),
        });
        _hpCsvData = await res.json();
        if (_hpCsvData.error) throw new Error(_hpCsvData.error);
        if (!_hpCsvData.dcs || !_hpCsvData.dcs.length) {
            dcSel.innerHTML = '<option value="">— geen DCs met klassement —</option>';
            return;
        }
        dcSel.innerHTML = '<option value="">— kies DC —</option>' +
            _hpCsvData.dcs.map(d =>
                `<option value="${escHtml(d.dc_id)}">${escHtml(d.dc_naam)} (${d.rijders.length} rijders)</option>`
            ).join('');
        dcSel.disabled = false;
    } catch (e) {
        dcSel.innerHTML = `<option value="">⚠ Fout: ${escHtml(e.message)}</option>`;
    }
}

function _hpCsvDcGekozen() {
    const dcId  = el('hp-csv-dc').value;
    const cols  = el('hp-csv-cols');
    const btn   = el('hp-btn-csv-exp');
    if (!dcId || !_hpCsvData) {
        cols.innerHTML = '<em style="color:#888;font-size:12.5px">— eerst DC kiezen —</em>';
        btn.disabled = true;
        return;
    }
    const dc = _hpCsvData.dcs.find(d => d.dc_id === dcId);
    _hpCsvAfstanden = dc?.afstanden || [];
    // Bouw checkbox-grid: standaard-kolommen + per-afstand-kolommen
    const stdHtml = _HP_CSV_STD_COLS.map(c => `
        <label class="hp-csv-cb">
            <input type="checkbox" class="hp-csv-col-cb" data-kind="std" data-id="${escHtml(c.id)}" ${c.checked ? 'checked' : ''}>
            <span>${escHtml(c.label)}</span>
        </label>`).join('');
    const afstHtml = _hpCsvAfstanden.map(a => `
        <label class="hp-csv-cb">
            <input type="checkbox" class="hp-csv-col-cb" data-kind="afst" data-id="${escHtml(a)}" checked>
            <span>Punten ${escHtml(a)}</span>
        </label>`).join('');
    cols.innerHTML = `
        <div style="display:flex;flex-wrap:wrap;gap:6px 14px;margin-bottom:6px">${stdHtml}</div>
        ${afstHtml ? `<div style="display:flex;flex-wrap:wrap;gap:6px 14px;border-top:1px dashed #ccc;padding-top:6px">${afstHtml}</div>` : ''}
        <div style="font-size:11.5px;color:#666;margin-top:6px">
            ${_hpCsvAfstanden.length
                ? 'Per afstand een eigen kolom met de punten voor die afstand. "Totaal punten" = som over alle afstanden.'
                : 'Geen afstand-data beschikbaar in deze DC.'}
        </div>`;
    btn.disabled = false;
}

function _hpCsvVerzamelKolommen() {
    const out = [];
    document.querySelectorAll('.hp-csv-col-cb:checked').forEach(cb => {
        out.push({ kind: cb.dataset.kind, id: cb.dataset.id });
    });
    return out;
}

function _hpCsvFmtRang(r, cols, dc) {
    // CSV: semicolon-separated, hele getallen ipv 1.000 (Excel-NL ziet
    // dat anders als 1000). Strings met ; of " worden geescaped.
    const esc = v => {
        if (v == null) return '';
        const s = String(v);
        if (/[;"\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
        return s;
    };
    const num = v => {
        if (v == null || v === '') return '';
        const n = Number(v);
        if (Number.isNaN(n)) return esc(v);
        return Number.isInteger(n) ? String(n) : String(n);
    };
    const stdMap = {
        rang:          r.rang ?? '',
        startnummer:   r.startnummer ?? '',
        naam:          r.naam ?? '',
        club:          r.club ?? '',
        sponsor:       r.sponsor ?? '',
        persoon_cat:   r.persoon_cat ?? '',
        split_group:   r.split_group ?? '',
        punten_totaal: r.punten_totaal != null ? num(r.punten_totaal) : '',
        licentie:      r.licentie ?? '',
    };
    return cols.map(c => {
        if (c.kind === 'std') return esc(stdMap[c.id] ?? '');
        // c.kind === 'afst'
        const v = r.punten_per_afstand?.[c.id];
        return v != null ? num(v) : '0';
    }).join(';');
}

function _hpCsvExporteer() {
    const stat = el('hp-csv-status');
    stat.textContent = '';
    const dcId = el('hp-csv-dc').value;
    if (!_hpCsvData || !dcId) { stat.textContent = '⚠ Kies eerst een DC.'; return; }
    const dc = _hpCsvData.dcs.find(d => d.dc_id === dcId);
    if (!dc || !dc.rijders.length) { stat.textContent = '⚠ Geen rijders in deze DC.'; return; }
    const cols = _hpCsvVerzamelKolommen();
    if (!cols.length) { stat.textContent = '⚠ Selecteer minstens één kolom.'; return; }

    // Header-rij: labels in dezelfde volgorde als de checkbox-selectie
    const stdLabelMap = Object.fromEntries(_HP_CSV_STD_COLS.map(c => [c.id, c.label]));
    const headers = cols.map(c =>
        c.kind === 'std' ? stdLabelMap[c.id] : `Punten ${c.id}`
    );
    const escHdr = h => /[;"\n\r]/.test(h) ? '"' + h.replace(/"/g, '""') + '"' : h;
    const csv = [
        headers.map(escHdr).join(';'),
        ...dc.rijders.map(r => _hpCsvFmtRang(r, cols, dc)),
    ].join('\r\n');

    // BOM voor Excel-NL zodat UTF-8 special chars (é, è, ï) goed verschijnen
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    // Bestandsnaam: wedstrijd-naam + dc-naam, sanitized
    const compNaam = el('hp-csv-comp').options[el('hp-csv-comp').selectedIndex]?.textContent || 'wedstrijd';
    const fname = `klassement_${compNaam}_${dc.dc_naam}`
        .replace(/[^a-zA-Z0-9_-]+/g, '_').slice(0, 100) + '.csv';
    a.href = url; a.download = fname;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    stat.textContent = `✓ ${dc.rijders.length} rijders geëxporteerd (${cols.length} kolommen)`;
    stat.classList.remove('hp-status-fout');
    stat.classList.add('hp-status-ok');
}

async function _hpDoeScan() {
    const btn   = el('hp-btn-scan');
    const stat  = el('hp-scan-status');
    const rapp  = el('hp-rapport');
    btn.disabled = true;
    stat.textContent = 'Scannen…';
    rapp.style.display = 'none';

    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'scan_wees_uitslagen' }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        _hpScanCache = data;
        _hpRenderRapport(data);
        stat.textContent = '';
    } catch (e) {
        stat.textContent = '⚠ Fout: ' + e.message;
        stat.classList.add('hp-status-fout');
    } finally {
        btn.disabled = false;
    }
}

function _hpRenderRapport(data) {
    const rapp = el('hp-rapport');
    rapp.style.display = '';

    const totU = data.totaal_uitslag_rij ?? 0;
    const totK = data.totaal_klas_rij ?? 0;
    const wnr  = data.unieke_wedstrijden ?? 0;

    if (totU === 0 && totK === 0) {
        rapp.innerHTML = `<div class="hp-rapport-leeg">✓ Geen wees-uitslagen gevonden — alles consistent.</div>`;
        return;
    }

    // Groepeer per wedstrijd → DC → afstand voor de uitslag-rijen
    // (en per wedstrijd → DC voor klassement)
    const perComp = {};
    const ensureComp = (r) => {
        if (!perComp[r.competition_id]) perComp[r.competition_id] = {
            naam: r.competition_naam, datum: r.competition_datum,
            uitslagPerDC: {}, klasPerDC: {},
        };
        return perComp[r.competition_id];
    };
    for (const r of (data.wees_uitslag ?? [])) {
        const c = ensureComp(r);
        const key = `${r.dc_naam}||${r.distance_naam}||${r.split_group ?? ''}`;
        if (!c.uitslagPerDC[key]) c.uitslagPerDC[key] = {
            dc_naam: r.dc_naam, distance_naam: r.distance_naam,
            split_group: r.split_group, rijders: [],
        };
        c.uitslagPerDC[key].rijders.push(r);
    }
    for (const r of (data.wees_klassement ?? [])) {
        const c = ensureComp(r);
        const key = `${r.dc_naam}||${r.split_group ?? ''}`;
        if (!c.klasPerDC[key]) c.klasPerDC[key] = {
            dc_naam: r.dc_naam, split_group: r.split_group, rijders: [],
        };
        c.klasPerDC[key].rijders.push(r);
    }

    const fmtTijd = (ms) => {
        if (ms == null) return '';
        const d = ms % 1000, s = Math.floor(ms / 1000) % 60, m = Math.floor(ms / 60000);
        return m > 0
            ? `${m}:${String(s).padStart(2,'0')}.${String(d).padStart(3,'0')}`
            : `${s}.${String(d).padStart(3,'0')}`;
    };

    let html = `<div class="hp-rapport-samenvatting">
        Gevonden: <b>${totU}</b> wees-uitslag-rij${totU === 1 ? '' : 'en'} ·
        <b>${totK}</b> wees-klassement-rij${totK === 1 ? '' : 'en'} ·
        verspreid over <b>${wnr}</b> wedstrijd${wnr === 1 ? '' : 'en'}
    </div>`;

    html += '<div class="hp-rapport-comps">';
    for (const compId of Object.keys(perComp)) {
        const c = perComp[compId];
        const datumKort = c.datum
            ? new Date(c.datum).toLocaleDateString('nl-NL', { day:'2-digit', month:'2-digit', year:'numeric' })
            : '?';
        html += `<div class="hp-rapport-comp">
            <div class="hp-rapport-comp-kop">${escHtml(c.naam || '?')} <small>(${escHtml(datumKort)})</small></div>`;

        for (const blok of Object.values(c.uitslagPerDC)) {
            const splitTxt = blok.split_group ? ` [${escHtml(blok.split_group)}]` : '';
            html += `<div class="hp-rapport-blok">
                <div class="hp-rapport-blok-kop">📊 Uitslag — <b>${escHtml(blok.dc_naam)}</b> / ${escHtml(blok.distance_naam)}${splitTxt}
                    <span class="hp-rapport-blok-aantal">${blok.rijders.length} rijder${blok.rijders.length === 1 ? '' : 's'}</span>
                </div>
                <ul class="hp-rapport-rijders">`;
            for (const r of blok.rijders) {
                const tijdTxt    = r.sanctie ? r.sanctie : fmtTijd(r.tijd_ms);
                const rangTxt    = r.rang != null ? `#${r.rang}` : '';
                html += `<li><span class="hp-rij-rang">${escHtml(rangTxt)}</span>
                             <span class="hp-rij-naam">${escHtml(r.naam || r.person_license)}</span>
                             <span class="hp-rij-tijd">${escHtml(tijdTxt)}</span></li>`;
            }
            html += `</ul></div>`;
        }
        for (const blok of Object.values(c.klasPerDC)) {
            const splitTxt = blok.split_group ? ` [${escHtml(blok.split_group)}]` : '';
            html += `<div class="hp-rapport-blok">
                <div class="hp-rapport-blok-kop">🏆 Klassement — <b>${escHtml(blok.dc_naam)}</b>${splitTxt}
                    <span class="hp-rapport-blok-aantal">${blok.rijders.length} rijder${blok.rijders.length === 1 ? '' : 's'}</span>
                </div>
                <ul class="hp-rapport-rijders">`;
            for (const r of blok.rijders) {
                const rangTxt = r.rang != null ? `#${r.rang}` : '';
                const ptnTxt  = r.punten_totaal != null ? `${parseFloat(r.punten_totaal)} pt` : '';
                html += `<li><span class="hp-rij-rang">${escHtml(rangTxt)}</span>
                             <span class="hp-rij-naam">${escHtml(r.naam || r.person_license)}</span>
                             <span class="hp-rij-tijd">${escHtml(ptnTxt)}</span></li>`;
            }
            html += `</ul></div>`;
        }
        html += `</div>`;
    }
    html += '</div>';

    html += `<div class="hp-rapport-acties">
        <button class="btn-danger" id="hp-btn-cleanup">🗑 Verwijder alle ${totU + totK} wees-rijen</button>
        <span class="hp-status" id="hp-cleanup-status"></span>
    </div>`;
    rapp.innerHTML = html;

    el('hp-btn-cleanup').addEventListener('click', _hpDoeCleanup);
}

async function _hpDoeCleanup() {
    const btn  = el('hp-btn-cleanup');
    const stat = el('hp-cleanup-status');
    const totU = _hpScanCache?.totaal_uitslag_rij ?? 0;
    const totK = _hpScanCache?.totaal_klas_rij ?? 0;
    const ok = await toonBevestigDialog(
        `Weet je zeker dat je <b>${totU + totK}</b> wees-rij${totU + totK === 1 ? '' : 'en'} wilt verwijderen?<br>` +
        `Dit verwijdert <b>${totU}</b> uitslag-rij${totU === 1 ? '' : 'en'} en <b>${totK}</b> klassement-rij${totK === 1 ? '' : 'en'}.<br>` +
        `<small>Dit kan niet ongedaan worden gemaakt.</small>`,
        'Wees-rijen verwijderen', 'Verwijderen', 'Annuleer',
        { bodyIsHtml: true }
    );
    if (!ok) return;

    btn.disabled = true;
    stat.textContent = 'Bezig…';
    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cleanup_wees_uitslagen', scope: 'all' }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        stat.textContent = `✓ ${data.uitslag_verwijderd} uitslag-rij${data.uitslag_verwijderd === 1 ? '' : 'en'} en ${data.klas_verwijderd} klassement-rij${data.klas_verwijderd === 1 ? '' : 'en'} verwijderd.`;
        stat.classList.remove('hp-status-fout');
        stat.classList.add('hp-status-ok');
        // Re-scan om te bevestigen dat alles weg is
        setTimeout(() => _hpDoeScan(), 800);
    } catch (e) {
        stat.textContent = '⚠ Fout: ' + e.message;
        stat.classList.add('hp-status-fout');
        btn.disabled = false;
    }
}
