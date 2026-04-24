/* InlineComp – Klassementen (KNSB PDF import + seeding) */

/* globals baseUrl */
'use strict';

// ── Helpers ──────────────────────────────────────────────────────────────────
const rkEl  = id => document.getElementById(id);
const rkEsc = s  => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

async function rkPost(url, body, contentType) {
    // Ondersteunt zowel FormData (legacy PDF-upload) als JSON (serie-wizard).
    const opts = { method: 'POST' };
    if (body instanceof FormData) {
        opts.body = body;
    } else {
        opts.headers = { 'Content-Type': contentType || 'application/json' };
        opts.body = typeof body === 'string' ? body : JSON.stringify(body);
    }
    const r = await fetch(url, opts);
    // 400/500 kunnen JSON-foutmelding bevatten — laten we die doorlezen
    if (!r.ok && r.status !== 400 && r.status !== 500) throw new Error(`HTTP ${r.status}`);
    return r.json();
}
async function rkGet(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
}

// ── State ────────────────────────────────────────────────────────────────────
let rkLijst       = [];   // alle opgeslagen klassementen
let rkHuidig      = null; // huidig geopend klassement-object (met posities)
let rkFilterCat   = '';   // actieve categorie-filter

// ── Initialisatie ─────────────────────────────────────────────────────────────
let rkGeinitialiseerd = false;

async function toonRankingPagina() {
    if (!rkGeinitialiseerd) {
        rkGeinitialiseerd = true;
        await initRanking();
    }
}

let rkOrgs         = []; // [{id, naam}]
let rkActieveOrgId = null;

// Aangeroepen vanuit instellingen.js als de klassementen-tab wordt geopend
async function setRankingOrgContext(orgId, orgNaam) {
    rkActieveOrgId = orgId;
    const container = rkEl('ranking-container');
    if (!container) return;
    // Altijd (her)initialiseren zodat de lijst gefilterd is op deze org
    rkGeinitialiseerd = false;
    rkLijst   = [];
    rkHuidig  = null;
    await initRanking();
}

async function initRanking() {
    const container = rkEl('ranking-container');
    if (!container) return;
    container.innerHTML = renderShell();
    bindEvents();
    // Organisaties laden voor dropdown
    try {
        const orgs = await rkGet('api/organisaties.php?action=list');
        rkOrgs = orgs ?? [];
        const sel = rkEl('rk-org-sel');
        if (sel) {
            rkOrgs.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id;
                opt.textContent = o.naam;
                sel.appendChild(opt);
            });
            // Pre-selecteer de actieve org als context bekend is
            if (rkActieveOrgId) sel.value = rkActieveOrgId;
        }
    } catch { /* org dropdown niet kritiek */ }
    await laadLijst();

    // Lees-alleen: upload-sectie disablen als gebruiker geen schrijfrechten heeft
    if (typeof magSchrijven === 'function' && !magSchrijven('beheer')) {
        const uploadWrap = rkEl('rk-dropzone');
        if (uploadWrap) pasSchrijfLockToe(uploadWrap);
    }
}

// ── HTML skelet ───────────────────────────────────────────────────────────────
function renderShell() {
    return `
<div class="rk-layout">

  <!-- Linkerpaneel: opgeslagen klassementen + upload -->
  <div class="rk-sidebar">
    <h2 class="rk-titel">Klassementen</h2>

    <!-- Upload -->
    <div class="rk-upload-box" id="rk-dropzone">
      <label class="rk-dropzone-label" for="rk-file-input">
        <div class="rk-upload-icoon">📄</div>
        <div class="rk-upload-tekst">Sleep een KNSB-klassement PDF<br>hier naartoe of klik om te kiezen</div>
      </label>
      <input type="file" id="rk-file-input" accept="application/pdf" style="display:none">
      <div class="rk-upload-veld" id="rk-upload-veld" style="display:none">
        <input type="text" class="inp" id="rk-naam-input" placeholder="Naam (bijv. Mannen Lange afstand)">
        <input type="text" class="inp" id="rk-seizoen-input" placeholder="Seizoen (bijv. 2024-2025)">
        <select class="inp" id="rk-org-sel" style="display:none">
          <option value="">— Organisatie (optioneel) —</option>
        </select>
        <button class="btn-primary" id="rk-btn-upload">Importeer PDF</button>
      </div>
      <div id="rk-upload-status" class="rk-upload-status" style="display:none"></div>
    </div>

    <!-- Genereer uit wedstrijden (serie-klassement) -->
    <button class="btn-primary rk-serie-btn" id="rk-btn-serie-nieuw" title="Maak een klassement op basis van eigen wedstrijd-uitslagen">
      📊 Bereken uit wedstrijden
    </button>

    <!-- Lijst van opgeslagen klassementen -->
    <div id="rk-lijstbox">
      <div class="rk-loading">Laden…</div>
    </div>
  </div>

  <!-- Rechterpaneel: detail van geselecteerd klassement -->
  <div class="rk-detail" id="rk-detail">
    <div class="rk-detail-leeg">
      <span class="rk-detail-icoon">🏆</span>
      <p>Selecteer een klassement uit de lijst<br>of importeer een nieuw PDF-bestand.</p>
    </div>
  </div>

</div>`;
}

// ── Events ────────────────────────────────────────────────────────────────────
function bindEvents() {
    // Dropzone — klik via <label for="rk-file-input">, geen aparte handler nodig
    const dropzone = rkEl('rk-dropzone');
    const fileInput = rkEl('rk-file-input');

    // Drag & drop
    dropzone?.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('rk-dragover'); });
    dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('rk-dragover'));
    dropzone?.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('rk-dragover');
        const file = e.dataTransfer?.files[0];
        if (file && file.type === 'application/pdf') zetBestand(file);
    });

    fileInput?.addEventListener('change', () => {
        if (fileInput.files[0]) zetBestand(fileInput.files[0]);
    });

    rkEl('rk-btn-upload')?.addEventListener('click', uploadPdf);

    // Nieuw serie-klassement
    rkEl('rk-btn-serie-nieuw')?.addEventListener('click', () => {
        if (typeof openSerieWizard !== 'function') {
            alert('Serie-wizard module is niet geladen.'); return;
        }
        openSerieWizard({ orgId: rkActieveOrgId || '' });
    });
}

let huidigBestand = null;

function zetBestand(file) {
    huidigBestand = file;
    const naamInp = rkEl('rk-naam-input');
    // Stel naam automatisch in op bestandsnaam (zonder extensie en UUID-prefix)
    if (naamInp && !naamInp.value) {
        let n = file.name.replace(/\.pdf$/i, '').replace(/^[0-9a-f\-]{30,}_\d+_/, '');
        naamInp.value = n.replace(/_/g, ' ');
    }
    const dropzone = rkEl('rk-dropzone');
    if (dropzone) {
        const bestaand = dropzone.querySelector('.rk-bestand-naam');
        if (bestaand) bestaand.remove();
        const span = document.createElement('div');
        span.className = 'rk-bestand-naam';
        span.textContent = `📎 ${file.name}`;
        dropzone.insertBefore(span, dropzone.querySelector('.rk-upload-veld'));
    }
    // Toon het formulier-gedeelte zodra een bestand geselecteerd is
    const veld = rkEl('rk-upload-veld');
    if (veld) veld.style.display = '';
    // Org-selector alleen tonen als er geen org-context is (zelfstandig gebruik)
    const orgSel = rkEl('rk-org-sel');
    if (orgSel) orgSel.style.display = rkActieveOrgId ? 'none' : '';
    rkEl('rk-btn-upload').disabled = false;
}

async function uploadPdf() {
    if (!huidigBestand) return;

    const btn    = rkEl('rk-btn-upload');
    const status = rkEl('rk-upload-status');
    btn.disabled = true;
    btn.textContent = 'Bezig…';
    status.style.display = '';
    status.className = 'rk-upload-status rk-info';
    status.textContent = 'PDF verwerken…';

    try {
        const fd = new FormData();
        fd.append('pdf',     huidigBestand);
        fd.append('naam',    rkEl('rk-naam-input')?.value ?? '');
        fd.append('seizoen', rkEl('rk-seizoen-input')?.value ?? '');
        fd.append('org_id',  rkEl('rk-org-sel')?.value ?? '');

        const result = await rkPost('api/klassement_import.php', fd);

        if (result.error) throw new Error(result.error);

        const sectieLabels = (result.secties ?? []).map(s => s.label ?? s).join(', ') || result.naam || '—';
        status.className = 'rk-upload-status rk-ok';
        status.textContent = `✔ ${result.totaal} rijders geïmporteerd · ${sectieLabels}`;
        setTimeout(() => { status.style.display = 'none'; }, 4000);

        // Reset formulier
        huidigBestand = null;
        if (rkEl('rk-file-input'))    rkEl('rk-file-input').value = '';
        if (rkEl('rk-naam-input'))    rkEl('rk-naam-input').value = '';
        if (rkEl('rk-seizoen-input')) rkEl('rk-seizoen-input').value = '';
        if (rkEl('rk-org-sel'))       rkEl('rk-org-sel').value = '';
        rkEl('rk-dropzone')?.querySelector('.rk-bestand-naam')?.remove();
        if (rkEl('rk-upload-veld'))   rkEl('rk-upload-veld').style.display = 'none';

        // Knop herstellen
        btn.disabled = false;
        btn.textContent = 'Importeer PDF';

        await laadLijst();
        await openKlassement(result.id);

    } catch (e) {
        status.className = 'rk-upload-status rk-fout';
        status.textContent = '✖ ' + e.message;
        btn.disabled = false;
        btn.textContent = 'Importeer PDF';
    }
}

// ── Lijst laden ───────────────────────────────────────────────────────────────
async function laadLijst() {
    const box = rkEl('rk-lijstbox');
    if (!box) return;
    try {
        const url = rkActieveOrgId
            ? `api/klassement_import.php?action=list&org_id=${encodeURIComponent(rkActieveOrgId)}`
            : 'api/klassement_import.php?action=list';
        rkLijst = await rkGet(url);
        box.innerHTML = renderLijst(rkLijst);
        box.querySelectorAll('.rk-item').forEach(item => {
            item.addEventListener('click', () => openKlassement(item.dataset.id));
        });
        box.querySelectorAll('.rk-btn-verwijder').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                verwijderKlassement(btn.dataset.id, btn.closest('.rk-item')?.querySelector('.rk-item-naam')?.textContent);
            });
        });

        // Lees-alleen: verwijder-knoppen verbergen (btn-del is uitgezonderd van pasSchrijfLockToe)
        if (typeof magSchrijven === 'function' && !magSchrijven('beheer')) {
            box.querySelectorAll('.rk-btn-verwijder').forEach(btn => {
                btn.disabled = true;
                btn.style.visibility = 'hidden';
            });
        }
    } catch(e) {
        box.innerHTML = `<div class="rk-fout-tekst">Fout bij laden: ${rkEsc(e.message)}</div>`;
    }
}

function renderLijst(lijst) {
    if (!lijst.length) return `<div class="rk-leeg-tekst">Nog geen klassementen geïmporteerd of berekend.</div>`;
    return lijst.map(k => {
        const isSerie = k.bron_bestand === '(serie-berekening)';
        const badge = isSerie
            ? '<span class="rk-type-badge rk-badge-serie" title="Berekend uit wedstrijd-uitslagen">📊</span>'
            : '<span class="rk-type-badge rk-badge-pdf"   title="Geïmporteerd uit PDF">📄</span>';
        return `
        <div class="rk-item ${rkHuidig?.id === k.id ? 'rk-item-actief' : ''}" data-id="${rkEsc(k.id)}">
            <div class="rk-item-naam">${badge} ${rkEsc(k.naam)}</div>
            <div class="rk-item-meta">
                ${k.seizoen ? `<span>${rkEsc(k.seizoen)}</span> · ` : ''}
                <span>${k.totaal_rijders} rijders</span>
                ${k.org_id ? ` · <span class="rk-org-label">${rkEsc(rkOrgs.find(o => o.id === k.org_id)?.naam ?? '…')}</span>` : ''}
                ${k.categorieen?.length ? ` · <span>${k.categorieen.map(c => c.label ?? c).join(', ')}</span>` : ''}
            </div>
            <button class="btn-del rk-btn-verwijder" data-id="${rkEsc(k.id)}" title="Verwijder klassement">&#128465;</button>
        </div>`;
    }).join('');
}

// ── Klassement openen ─────────────────────────────────────────────────────────
async function openKlassement(id) {
    const detail = rkEl('rk-detail');
    if (!detail) return;
    detail.innerHTML = `<div class="rk-loading">Laden…</div>`;
    try {
        rkHuidig    = await rkGet(`api/klassement_import.php?action=get&id=${encodeURIComponent(id)}`);
        rkFilterCat = '';
        detail.innerHTML = renderDetail(rkHuidig);
        bindDetailEvents(detail);
        // Actief item markeren in sidebar
        rkEl('rk-lijstbox')?.querySelectorAll('.rk-item').forEach(el => {
            el.classList.toggle('rk-item-actief', el.dataset.id === id);
        });
    } catch(e) {
        detail.innerHTML = `<div class="rk-fout-tekst">Fout: ${rkEsc(e.message)}</div>`;
    }
}

function renderDetail(k) {
    const cats = k.categorieen ?? [];
    const datum = k.aangemaakt_op
        ? new Date(k.aangemaakt_op.replace(' ','T')+'Z').toLocaleString('nl-NL',{day:'2-digit',month:'long',year:'numeric'})
        : '';

    // cats = array van {label, cat_codes, sectie, totaal} objecten of strings (oud formaat)
    const catLabels = cats.map(c => c.label ?? c);

    // Standaard: eerste sectie geselecteerd (geen "Alle")
    const activeCat = rkFilterCat || catLabels[0] || '';

    const filterTabs = catLabels.length > 1
        ? `<div class="rk-cat-tabs">
            ${catLabels.map(l => `<button class="rk-cat-tab ${activeCat===l ? 'rk-cat-actief' : ''}" data-cat="${rkEsc(l)}">${rkEsc(l)}</button>`).join('')}
           </div>`
        : '';

    const allePosities = k.posities ?? [];
    const gefilterd    = allePosities.filter(p => p.categorie === activeCat);

    // Cat-code kolom tonen als sectie meerdere cat-codes heeft
    const toonCatCol = cats.find(c => (c.label ?? c) === activeCat)?.cat_codes?.length > 1;

    // Serie-kolommen: per wedstrijd een kolom met de punten-bijdrage, plus
    // een totaal-kolom. Alleen tonen bij serie-klassementen (wedstrijden_meta
    // is niet-leeg) én als er daadwerkelijk detail-data is.
    const wMeta = Array.isArray(k.wedstrijden_meta) ? k.wedstrijden_meta : [];
    const toonWedstrijden = wMeta.length > 0
        && gefilterd.some(p => p.punten_detail && Object.keys(p.punten_detail).length);
    // Format-helper: 50.1 → "50.1", 10 → "10" (geen .0 achter geheel getal).
    const fmtP = n => {
        if (n == null) return '–';
        const v = +n;
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    };

    const rijen = gefilterd.map(p => {
        const detail = p.punten_detail ?? {};
        const wedstrijdCellen = toonWedstrijden
            ? wMeta.map(w => {
                const waarde = detail[w.comp_id];
                return `<td class="tc rk-w">${waarde != null ? fmtP(waarde) : '<span class="rk-nng">–</span>'}</td>`;
              }).join('')
            : '';
        const totaalCel = toonWedstrijden
            ? `<td class="tc rk-totaal">${fmtP(p.punten_totaal)}</td>`
            : '';
        return `<tr>
            <td class="tc rk-pos">${p.positie}</td>
            <td class="tc rk-nr">${rkEsc(p.start_number ?? '–')}</td>
            <td class="rk-naam">${rkEsc(p.naam)}</td>
            ${toonCatCol ? `<td class="tc rk-cat">${rkEsc(p.categorie ?? '')}</td>` : ''}
            ${wedstrijdCellen}
            ${totaalCel}
        </tr>`;
    }).join('');

    const isSerie = k.bron_bestand === '(serie-berekening)';
    const serieActies = isSerie
        ? `<div class="rk-detail-acties">
             <button class="btn-primary rk-detail-btn" data-serie-act="herbereken">🔄 Herbereken</button>
             <button class="btn-secondary rk-detail-btn" data-serie-act="bewerken">✏️ Bewerken</button>
             <button class="btn-secondary rk-detail-btn" data-serie-act="diag">🔍 Diagnose</button>
           </div>`
        : '';
    const bronLabel = isSerie
        ? '📊 Berekend uit wedstrijden'
        : (k.bron_bestand ? `📄 ${rkEsc(k.bron_bestand)}` : '');

    return `
<div class="rk-detail-header">
    <div>
        <h2 class="rk-detail-naam">${rkEsc(k.naam)}</h2>
        <div class="rk-detail-meta">
            ${k.seizoen ? `<span>${rkEsc(k.seizoen)}</span> · ` : ''}
            <span>${k.totaal_rijders} rijders</span>
            ${k.org_id ? ` · <span class="rk-org-label">🏢 ${rkEsc(rkOrgs.find(o => o.id === k.org_id)?.naam ?? '…')}</span>` : ''}
            ${datum ? ` · <span>${isSerie ? 'berekend' : 'geïmporteerd'} ${datum}</span>` : ''}
            ${bronLabel ? ` · <span class="rk-bronbestand">${bronLabel}</span>` : ''}
        </div>
    </div>
    ${serieActies}
</div>

${filterTabs}

<div class="rk-tabel-wrap">
<table class="rk-tabel">
    <thead>
        ${toonWedstrijden && wMeta.length > 1 ? `
        <tr class="rk-sub-hdr">
            <th class="tc" colspan="${3 + (toonCatCol ? 1 : 0)}"></th>
            <th class="tc rk-wedstrijden-kop" colspan="${wMeta.length}">Wedstrijden</th>
            <th class="tc"></th>
        </tr>` : ''}
        <tr>
            <th class="tc">Pos.</th>
            <th class="tc">Start#</th>
            <th>Naam</th>
            ${toonCatCol ? `<th class="tc">Cat.</th>` : ''}
            ${toonWedstrijden ? wMeta.map((w, i) =>
                `<th class="tc rk-w" title="${rkEsc(w.naam)}${w.datum ? ' · ' + String(w.datum).substring(0,10) : ''}${w.is_finale ? ' · FINALE' : ''}">
                    ${w.is_finale ? 'F' : '#' + (i + 1)}
                </th>`).join('') : ''}
            ${toonWedstrijden ? `<th class="tc rk-totaal">Totaal</th>` : ''}
        </tr>
    </thead>
    <tbody>${rijen}</tbody>
</table>
</div>

<div class="rk-detail-info">
    <span class="rk-info-icoon">💡</span>
    Dit klassement is beschikbaar als seeding bij het aanmaken van startlijsten.
    Rijders die ontbreken worden op startnummer achteraan toegevoegd.
</div>`;
}

function bindDetailEvents(container) {
    container.querySelectorAll('.rk-cat-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            rkFilterCat = tab.dataset.cat || null;
            rkEl('rk-detail').innerHTML = renderDetail(rkHuidig);
            bindDetailEvents(rkEl('rk-detail'));
        });
    });

    // Serie-acties (alleen aanwezig bij serie-klassementen)
    container.querySelectorAll('[data-serie-act]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const act = btn.dataset.serieAct;
            // Zoek de serie-id via het klassement-id
            let serieId = null;
            try {
                const rows = await rkGet(
                    `api/klassement_serie.php?action=list${rkActieveOrgId ? '&org_id=' + encodeURIComponent(rkActieveOrgId) : ''}`
                );
                serieId = rows.find(r => r.klassement_id === rkHuidig.id)?.id ?? null;
            } catch {}
            if (!serieId) { alert('Serie-definitie niet gevonden.'); return; }

            if (act === 'herbereken') {
                btn.disabled = true; btn.textContent = 'Bezig…';
                await herbereken(serieId);
            } else if (act === 'bewerken') {
                openSerieWizard({ orgId: rkActieveOrgId || rkHuidig.org_id || '', serieId });
            } else if (act === 'diag') {
                await diagnoseSerieer(serieId);
            }
        });
    });
}

// ── Verwijderen ───────────────────────────────────────────────────────────────
async function verwijderKlassement(id, naam) {
    if (!await toonBevestigDialog(`Klassement "${naam ?? id}" verwijderen?`, 'Klassement verwijderen')) return;
    try {
        const fd = new FormData();
        await rkPost(`api/klassement_import.php?action=delete&id=${encodeURIComponent(id)}`, fd);
        if (rkHuidig?.id === id) {
            rkHuidig = null;
            rkEl('rk-detail').innerHTML = `<div class="rk-detail-leeg">
                <span class="rk-detail-icoon">🏆</span>
                <p>Selecteer een klassement of importeer een nieuw PDF-bestand.</p>
            </div>`;
        }
        await laadLijst();
    } catch(e) {
        toonBevestigDialog('Fout bij verwijderen: ' + e.message, 'Fout');
    }
}

// ── Publieke API voor seeding (gebruikt door startlist.js) ────────────────────

/**
 * Geeft de seedingvolgorde voor een categorie terug als array van start_numbers.
 * Rijders die niet in het klassement staan worden NIET opgenomen (startlist.js voegt ze toe).
 * @param {string} klassementId  UUID van het klassement
 * @param {string|null} categorie  Bijv. 'HSA' — null = alle categorieën gecombineerd
 * @returns {Promise<string[]>}  Geordende array van start_numbers
 */
async function getRankingSeeding(klassementId, categorie) {
    const k = await rkGet(`api/klassement_import.php?action=get&id=${encodeURIComponent(klassementId)}`);
    return (k.posities ?? [])
        .filter(p => !categorie || p.categorie === categorie)
        .sort((a, b) => a.positie - b.positie)
        .map(p => String(p.start_number));
}

/**
 * Geeft alle opgeslagen klassementen terug (voor dropdown in startlist.js).
 * @returns {Promise<Array>}
 */
async function getKlassementen() {
    return rkGet('api/klassement_import.php?action=list');
}
