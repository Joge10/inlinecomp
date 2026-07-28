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
            toonBevestigDialog('Serie-wizard module is niet geladen.', 'Klassement-serie', 'OK', '');
            return;
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

// Is de finale-wedstrijd al verreden? Afgeleid uit de posities: heeft minstens
// één rijder punten in de finale-kolom? Zo niet (of geen finale aangewezen) →
// tussenstand, waarin de reglementaire regels nog niet gelden.
function rkFinaleGereden(k) {
    const wm  = Array.isArray(k.wedstrijden_meta) ? k.wedstrijden_meta : [];
    const fin = wm.find(w => w.is_finale);
    if (!fin) return true;
    const key = fin.key ?? fin.comp_id;
    return (k.posities ?? []).some(p => p.punten_detail && p.punten_detail[key] != null);
}

// Bouwt de losse regel-teksten (zonder emoji) — gedeeld door de UI-samenvatting
// en de print-uitleg.
function rkRegelItems(k) {
    const r = k.regels;
    if (!r) return [];
    const items = [];
    const f = r.afstand_filter || 'alle';
    if (f === 'alle')          items.push('DC-eindklassement — alle afstanden per categorie samen');
    else if (f === 'sprint')   items.push('Per afstand — alleen sprintafstanden');
    else if (f === 'lang')     items.push('Per afstand — alleen lange afstanden');
    else if (f === 'per_naam') items.push('Per afstand — alleen: ' + rkEsc((r.afstand_namen || []).join(', ') || '—'));
    const tab = Array.isArray(r.punten_tabel) ? r.punten_tabel : [];
    if (tab.length) items.push('Punten per plek: ' + rkEsc(tab.slice(0, 3).join(', ')) + (tab.length > 3 ? ', …' : '')
        + '; buiten de tabel ' + (+r.min_punten_bij_deelname || 0) + ' punt(en)');
    if (+r.streepresultaten > 0) items.push('De ' + (+r.streepresultaten) + ' slechtste score(s) tellen niet mee (streepresultaten)'
        + (r.streep_direct ? ' — ook in de tussenstand' : ''));
    if (+r.min_deelnames > 0) items.push('Minimaal ' + (+r.min_deelnames) + ' wedstrijden nodig om opgenomen te worden');
    if (r.vereist_finale) items.push('De finale moet gereden zijn om opgenomen te worden');
    const tbMap = {
        geen: 'geen — gelijke punten blijven een gedeelde plaats',
        laatste: 'de laatste wedstrijd (bij gelijk: de voorlaatste, enz.)',
        beste_resultaten: 'de beste losse resultaten',
        beste_resultaten_dan_laatste: 'de beste losse resultaten, dan de laatste wedstrijd',
    };
    items.push('Bij gelijke punten beslist: ' + (tbMap[r.tie_break] || rkEsc(r.tie_break || '—')));
    if (r.non_deelname_punten) items.push('Afwezigen krijgen "laatste + 1" punten voor die wedstrijd');
    const bonus = (Array.isArray(k.wedstrijden_meta) ? k.wedstrijden_meta : []).filter(w => w.bonus_modus);
    if (bonus.length) items.push('Bonuswedstrijd(en): '
        + bonus.map(w => rkEsc(w.naam) + ' (+' + (+w.bonus_punten || 1) + ' per aanwezige)').join(', '));
    if (Array.isArray(r.categorie_filter) && r.categorie_filter.length)
        items.push('Alleen categorieën: ' + rkEsc(r.categorie_filter.join(', ')));
    return items;
}

// Leesbare samenvatting van de gebruikte klassement-regels (alleen serie, UI), met
// onderscheid eindstand (regels toegepast) vs tussenstand (nog niet toegepast).
function rkRegelsSamenvatting(k) {
    const items = rkRegelItems(k);
    if (!items.length) return '';
    const gereden  = rkFinaleGereden(k);
    const rand     = gereden ? '#d9e2ec' : '#f0c98a';
    const achtergr = gereden ? '#f4f7fb' : '#fdf6ec';
    const titelKl  = gereden ? '#1F4E79' : '#9a6516';
    const titel    = gereden
        ? '✅ Eindberekening — de finale is verreden; deze regels zijn toegepast:'
        : '⏳ Tussenstand — de finale is nog niet verreden';
    const tussenNoot = gereden ? '' :
        `<div style="font-size:.82rem;color:#7a5410;margin:2px 0 6px;line-height:1.45">Let op: dit is een <b>tussenstand</b>. De reglementaire regels hieronder — o.a. minimaal aantal deelnames, finale-plicht, wegstrepen en de tie-break bij gelijke stand — worden <b>pas ná de finale</b> toegepast. Nu wordt puur op puntentotaal gerangschikt en delen gelijke totalen een plaats.</div>`;
    return `<div style="background:${achtergr};border:1px solid ${rand};border-radius:8px;padding:9px 14px;margin:10px 0 2px">
        <div style="font-weight:700;color:${titelKl};font-size:.88rem;margin-bottom:4px">${titel}</div>
        ${tussenNoot}
        <ul style="margin:0;padding-left:18px;font-size:.83rem;color:#33404f;line-height:1.5">${items.map(x => `<li>${x}</li>`).join('')}</ul>
    </div>`;
}

// Print-versie van de regels-uitleg — professioneel, zonder emoji, voor deelnemers.
function rkRegelsPrintBlok(k) {
    const items = rkRegelItems(k);
    if (!items.length) return '';
    const gereden = rkFinaleGereden(k);
    const kop = gereden
        ? 'Toegepaste klassementsregels'
        : 'Tussenstand — reglementaire regels nog niet toegepast';
    const tussen = gereden ? '' :
        `<div class="pk-regels-tussen">Dit is een tussenstand: de finale is nog niet verreden. De onderstaande reglementaire regels — waaronder het minimaal aantal deelnames, de finale-plicht, het wegstrepen van resultaten en de tie-break bij gelijke stand — worden pas ná de finale toegepast. In deze tussenstand wordt uitsluitend op puntentotaal gerangschikt en delen gelijke totalen een plaats.</div>`;
    return `<div class="pk-regels">
        <div class="pk-regels-kop">${kop}</div>
        ${tussen}
        <ul class="pk-regels-lijst">${items.map(x => `<li>${x}</li>`).join('')}</ul>
    </div>`;
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

    // Kolomtelling voor de scheidingsrij tussen geklasseerd en niet-opgenomen.
    const colspanRk = 3 + (toonCatCol ? 1 : 0) + (toonWedstrijden ? wMeta.length + 1 : 0);
    let rkScheiding = false;
    const rijen = gefilterd.map(p => {
        // positie 0 = 'niet opgenomen in klassement' (onderblok): op puntenvolgorde,
        // zonder rangnummer.
        const buiten = !(+p.positie > 0);
        let voor = '';
        if (buiten && !rkScheiding) {
            rkScheiding = true;
            voor = `<tr class="rk-scheiding"><td colspan="${colspanRk}" style="background:#eef1f5;color:#5a6472;font-weight:700;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;padding:6px 8px">Niet opgenomen in klassement</td></tr>`;
        }
        const detail = p.punten_detail ?? {};
        // _gestreept = array van pwKeys (= comp_id of comp_id|distance_id)
        // die bij streepresultaten zijn weggehaald. Tonen we doorgehaald +
        // gedimd, zodat de gebruiker ziet welke score wegvalt.
        const gestreeptSet = new Set(detail._gestreept ?? []);
        // Sleutel-helper: bij nieuwe data is w.key altijd gevuld; oude
        // klassementen vóór de per-afstand-fix hebben alleen w.comp_id.
        const colKey = w => w.key ?? w.comp_id;
        const wedstrijdCellen = toonWedstrijden
            ? wMeta.map(w => {
                const k = colKey(w);
                const waarde = detail[k];
                if (waarde == null) {
                    return `<td class="tc rk-w"><span class="rk-nng">–</span></td>`;
                }
                if (gestreeptSet.has(k)) {
                    return `<td class="tc rk-w rk-w-streep" title="Weggestreept resultaat (telt niet mee in totaal)">${fmtP(waarde)}</td>`;
                }
                return `<td class="tc rk-w">${fmtP(waarde)}</td>`;
              }).join('')
            : '';
        const totaalCel = toonWedstrijden
            ? `<td class="tc rk-totaal">${fmtP(p.punten_totaal)}</td>`
            : '';
        return `${voor}<tr class="${buiten ? 'rk-buiten' : ''}">
            <td class="tc rk-pos">${buiten ? '' : p.positie}</td>
            <td class="tc rk-nr">${rkEsc(p.start_number ?? '–')}</td>
            <td class="rk-naam">${rkEsc(p.naam)}</td>
            ${toonCatCol ? `<td class="tc rk-cat">${rkEsc(p.categorie ?? '')}</td>` : ''}
            ${wedstrijdCellen}
            ${totaalCel}
        </tr>`;
    }).join('');

    const isSerie = k.bron_bestand === '(serie-berekening)';
    // Publicatie-status (alleen relevant voor serie-klassementen).
    // serie_gepubliceerd_at NULL = niet zichtbaar in /public + /coach.
    const isGepubliceerd = isSerie && !!k.serie_gepubliceerd_at;
    const publBtn = isSerie
        ? (isGepubliceerd
            ? `<button class="rk-detail-btn rk-publ-trek" data-serie-act="trek_in" title="Maak deze serie weer onzichtbaar in /public + /coach">↻ Trek publicatie in</button>`
            : `<button class="btn-primary rk-detail-btn" data-serie-act="publiceer" title="Maak deze serie zichtbaar in /public + /coach">📢 Publiceer</button>`)
        : '';
    const serieActies = isSerie
        ? `<div class="rk-detail-acties">
             <button class="btn-primary rk-detail-btn" data-serie-act="herbereken">🔄 Herbereken</button>
             ${publBtn}
             <button class="btn-secondary rk-detail-btn" data-serie-act="print">🖨 Print</button>
             <button class="btn-secondary rk-detail-btn" data-serie-act="bewerken">✏️ Bewerken</button>
             <button class="btn-secondary rk-detail-btn" data-serie-act="diag">🔍 Diagnose</button>
           </div>`
        : '';
    const bronLabel = isSerie
        ? '📊 Berekend uit wedstrijden'
        : (k.bron_bestand ? `📄 ${rkEsc(k.bron_bestand)}` : '');
    // Publicatie-badge in de meta-regel zodat in 1 oogopslag duidelijk is
    // of /public deze serie ziet of niet.
    const publBadge = isSerie
        ? (isGepubliceerd
            ? `<span class="rk-publ-status rk-publ-on" title="Zichtbaar in /public + /coach sinds ${rkEsc(String(k.serie_gepubliceerd_at).replace('T',' '))}">📢 Gepubliceerd</span>`
            : `<span class="rk-publ-status rk-publ-off" title="Onzichtbaar in /public + /coach — klik 📢 Publiceer om vrij te geven">🔒 Niet gepubliceerd</span>`)
        : '';

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
            ${publBadge ? ` · ${publBadge}` : ''}
        </div>
    </div>
    ${serieActies}
</div>

${isSerie ? rkRegelsSamenvatting(k) : ''}

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
            ${toonWedstrijden ? wMeta.map((w, i) => {
                // Bij per-afstand-mode tonen we de distance_naam onder het
                // F/#-label zodat de operator direct ziet welke afstand
                // welke kolom is. Volle wedstrijd-naam blijft in tooltip.
                const titel = `${rkEsc(w.naam)}${w.distance_naam ? ' · ' + rkEsc(w.distance_naam) : ''}${w.datum ? ' · ' + String(w.datum).substring(0,10) : ''}${w.is_finale ? ' · FINALE' : ''}${w.bonus_modus ? ' · BONUS +' + (+w.bonus_punten || 1) + ' p. aanwezige' : ''}`;
                const top = w.is_finale ? 'F' : '#' + (i + 1);
                const sub = w.distance_naam ? `<div class="rk-w-sub">${rkEsc(w.distance_naam)}</div>` : '';
                return `<th class="tc rk-w" title="${titel}">${top}${sub}</th>`;
            }).join('') : ''}
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
            // Print heeft geen serie-id nodig — direct uit klassement printen.
            if (act === 'print') {
                await printSerieKlassement(rkHuidig);
                return;
            }
            // Zoek de serie-id via het klassement-id (nodig voor herbereken/bewerken/diag)
            let serieId = null;
            try {
                const rows = await rkGet(
                    `api/klassement_serie.php?action=list${rkActieveOrgId ? '&org_id=' + encodeURIComponent(rkActieveOrgId) : ''}`
                );
                serieId = rows.find(r => r.klassement_id === rkHuidig.id)?.id ?? null;
            } catch {}
            if (!serieId) {
                toonBevestigDialog('Serie-definitie niet gevonden.', 'Klassement', 'OK', '');
                return;
            }

            if (act === 'herbereken') {
                btn.disabled = true; btn.textContent = 'Bezig…';
                await herbereken(serieId);
            } else if (act === 'bewerken') {
                openSerieWizard({ orgId: rkActieveOrgId || rkHuidig.org_id || '', serieId });
            } else if (act === 'diag') {
                await diagnoseSerieer(serieId);
            } else if (act === 'publiceer' || act === 'trek_in') {
                // Publiceer / trek-in: zelfde patroon als wedstrijd-klassement.
                const verb = act === 'publiceer' ? 'publiceren' : 'intrekken';
                if (act === 'trek_in' && !await toonBevestigDialog(
                    `Publicatie intrekken? "${rkEsc(rkHuidig.naam)}" wordt onmiddellijk verborgen in /public + /coach.`,
                    'Publicatie intrekken')) return;
                btn.disabled = true; btn.textContent = 'Bezig…';
                try {
                    await rkPost(
                        `api/klassement_serie.php?action=${act}&id=${encodeURIComponent(serieId)}`,
                        new FormData()
                    );
                    // Detail opnieuw laden zodat badge + knop-label kloppen
                    rkHuidig = await rkGet(
                        `api/klassement_import.php?action=get&id=${encodeURIComponent(rkHuidig.id)}`
                    );
                    rkEl('rk-detail').innerHTML = renderDetail(rkHuidig);
                    bindDetailEvents(rkEl('rk-detail'));
                } catch (e) {
                    toonBevestigDialog(`Fout bij ${verb}: ${e.message}`, 'Fout');
                    btn.disabled = false;
                }
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
        // positie 0 = 'niet opgenomen in klassement' (onderblok) → niet seeden;
        // startlist.js voegt deze rijders zelf achteraan toe.
        .filter(p => (!categorie || p.categorie === categorie) && +p.positie > 0)
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

// Cat-volgorde voor print + keuze-modal: jong → oud, D vóór H.
// Kopie van _catRank uit js/instellingen.js — verhuizen naar gedeelde helper
// staat op de post-OH850-lijst, voor nu lokaal om scope-creep te vermijden.
function _rkCatRank(cat) {
    const c = String(cat || '').toUpperCase().trim();
    if (!c) return 99999;
    if (/^DSENIOR/.test(c)) return 4 * 10000 + 100;
    if (/^HSENIOR/.test(c)) return 4 * 10000 + 100 + 1;
    if (c.length < 2) return 99999;
    const geslacht = c[0] === 'D' ? 0 : c[0] === 'H' ? 1 : 9;
    const groep    = c[1];
    const sub      = c.slice(2);
    let groepRank, subRank;
    if (groep === 'P') {
        groepRank = 1;
        const n = parseInt(sub, 10);
        subRank = isNaN(n) ? 99 : (10 - n);
    } else if (groep === 'K') {
        groepRank = 2; subRank = 0;
    } else if (groep === 'J') {
        groepRank = 3;
        subRank = sub[0] ? (90 - sub[0].charCodeAt(0)) : 99;
    } else if (groep === 'S') {
        groepRank = 4;
        if      (sub[0] === 'J') subRank = 0;
        else if (sub[0] === 'A') subRank = 1;
        else if (sub[0] === 'B') subRank = 2;
        else                     subRank = 99;
    } else if (/^[0-9]/.test(groep)) {
        groepRank = 5;
        const n = parseInt(c.slice(1), 10);
        subRank = isNaN(n) ? 99 : Math.floor((n - 40) / 5);
    } else {
        return 99999;
    }
    return groepRank * 10000 + subRank * 100 + geslacht;
}

// ── Print: cat-keuze-modal ────────────────────────────────────────────────────
//
// Toont een modal met een chip per categorie. Operator kan kiezen welke cats
// hij wil printen. Resolved met Set van gekozen cats, of null bij annuleren.
function _kiesCatsVoorPrint(catLabels) {
    return new Promise(resolve => {
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
        const chips = catLabels.map(c => `
            <label class="wh-cat-chip">
                <input type="checkbox" class="rk-print-cat" data-cat="${esc(c)}" checked>
                <span>${esc(c)}</span>
            </label>`).join('');
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <span class="modal-icon">🖨</span>
                    <span>Welke categorieën printen?</span>
                </div>
                <div class="modal-body">
                    <p class="rk-print-uitleg">Vink aan welke categorieën in de print moeten verschijnen.</p>
                    <div class="wh-dc-cats" id="rk-print-cats">${chips}</div>
                    <div class="rk-print-quick">
                        <button type="button" class="modal-btn modal-annuleer rk-print-quick-btn" id="rk-print-allemaal">Alle aanvinken</button>
                        <button type="button" class="modal-btn modal-annuleer rk-print-quick-btn" id="rk-print-geen">Niets aanvinken</button>
                    </div>
                </div>
                <div class="modal-knoppen">
                    <button class="modal-btn modal-annuleer" id="rk-print-cancel">Annuleren</button>
                    <button class="modal-btn modal-doorgaan" id="rk-print-ok">Printen</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        const sluit = res => {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            resolve(res);
        };
        const onKey = e => {
            if (e.key === 'Escape') sluit(null);
            if (e.key === 'Enter')  sluit(geselecteerd());
        };
        const geselecteerd = () => new Set(
            Array.from(overlay.querySelectorAll('.rk-print-cat:checked')).map(cb => cb.dataset.cat)
        );

        overlay.querySelector('#rk-print-allemaal').addEventListener('click', () => {
            overlay.querySelectorAll('.rk-print-cat').forEach(cb => cb.checked = true);
        });
        overlay.querySelector('#rk-print-geen').addEventListener('click', () => {
            overlay.querySelectorAll('.rk-print-cat').forEach(cb => cb.checked = false);
        });
        overlay.querySelector('#rk-print-cancel').addEventListener('click', () => sluit(null));
        overlay.querySelector('#rk-print-ok').addEventListener('click', () => sluit(geselecteerd()));
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(null); });
        document.addEventListener('keydown', onKey);
    });
}

// ── Print: serie-klassement (alle categorieën) ────────────────────────────────
//
// Opent een nieuw venster met een schoon HTML-document — per categorie een
// tabel met posities, wedstrijdkolommen en totaal. Weggestreepte resultaten
// (streepresultaten-regel) worden doorgehaald + gedimd weergegeven, met een
// voetnoot eronder. De window-print() wordt automatisch getriggerd.
async function printSerieKlassement(k) {
    if (!k) return;

    // Vroege validatie + cat-keuze: doe dit vóór we globals (huidigBaan /
    // huidigOrganisatie) aanraken, zodat een annulering geen restore vraagt.
    const _allePosVoor = k.posities ?? [];
    if (!_allePosVoor.length) {
        toonBevestigDialog('Geen posities om te printen.', 'Klassement printen', 'OK', '');
        return;
    }
    const _catsVoor = k.categorieen ?? [];
    const _catLabelsAlle = (_catsVoor.length
        ? _catsVoor.map(c => c.label ?? c)
        : [...new Set(_allePosVoor.map(p => p.categorie))])
        .slice()
        .sort((a, b) => _rkCatRank(a) - _rkCatRank(b));
    const gekozenCats = await _kiesCatsVoorPrint(_catLabelsAlle);
    if (!gekozenCats) return;
    if (!gekozenCats.size) {
        toonBevestigDialog('Geen categorieën geselecteerd.', 'Klassement printen', 'OK', '');
        return;
    }

    // Org-data ophalen voor logo + sponsors. `bouwOrgHeaderFooter()` leest
    // uit het globale `huidigOrganisatie`, dat op de klassement-pagina niet
    // gevuld is — we zetten 'm hier tijdelijk en herstellen na de render.
    let _origOrg = (typeof huidigOrganisatie !== 'undefined') ? huidigOrganisatie : null;
    // Een serie kan wedstrijden op meerdere banen omvatten — er is geen
    // enkele "gastheer-baan". `huidigBaan` kan nog gevuld zijn van een eerder
    // bekeken wedstrijd; dat zou een willekeurig baan-logo in de print zetten.
    // Tijdelijk leegmaken en achteraf herstellen.
    let _origBaan = (typeof huidigBaan !== 'undefined') ? huidigBaan : null;
    if (typeof huidigBaan !== 'undefined') {
        // eslint-disable-next-line no-global-assign
        huidigBaan = null;
    }
    if (k.org_id) {
        try {
            const r = await fetch('api/organisaties.php?id=' + encodeURIComponent(k.org_id));
            if (r.ok) {
                const orgData = await r.json();
                if (orgData && !orgData.error && typeof huidigOrganisatie !== 'undefined') {
                    // eslint-disable-next-line no-global-assign
                    huidigOrganisatie = orgData;
                }
            }
        } catch (e) { /* stil — print gaat door zonder logo */ }
    }
    const wMeta = Array.isArray(k.wedstrijden_meta) ? k.wedstrijden_meta : [];
    const allePos = _allePosVoor;
    // Behoud dezelfde volgorde als de tabbladen op de detail-pagina, maar
    // filter op de operator-keuze uit de print-modal hierboven.
    const catLabels = _catLabelsAlle.filter(c => gekozenCats.has(c));

    const fmtP = n => {
        if (n == null) return '–';
        const v = +n;
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    };
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));

    // Per categorie een blok bouwen
    const heeftWedstrijden = wMeta.length > 0
        && allePos.some(p => p.punten_detail && Object.keys(p.punten_detail).length);

    const blokkenHtml = catLabels.map(cat => {
        const rijen = allePos.filter(p => p.categorie === cat);
        if (!rijen.length) return '';
        const colKey = w => w.key ?? w.comp_id;  // back-compat
        const wedstrijdHdr = heeftWedstrijden
            ? wMeta.map((w, i) => {
                const tip = esc(w.naam || '') + (w.distance_naam ? ' · ' + esc(w.distance_naam) : '') + (w.datum ? ' · ' + String(w.datum).substring(0, 10) : '') + (w.bonus_modus ? ' · BONUS +' + (+w.bonus_punten || 1) : '');
                const top = w.is_finale ? 'F' : '#' + (i + 1);
                const sub = w.distance_naam ? `<div style="font-size:.62rem;font-weight:400;color:#666;line-height:1.1">${esc(w.distance_naam)}</div>` : '';
                return `<th class="pk-w" title="${tip}">${top}${sub}</th>`;
            }).join('')
            : '';
        const pkColspan = 3 + (heeftWedstrijden ? wMeta.length + 1 : 0);
        let pkScheiding = false;
        const rijenHtml = rijen.map(p => {
            // positie 0 = 'niet opgenomen in klassement' (onderblok).
            const buiten = !(+p.positie > 0);
            let voor = '';
            if (buiten && !pkScheiding) {
                pkScheiding = true;
                voor = `<tr class="pk-scheiding"><td colspan="${pkColspan}" style="background:#eef1f5;color:#5a6472;font-weight:700;font-size:.72rem;text-transform:uppercase;padding:4px 6px">Niet opgenomen in klassement</td></tr>`;
            }
            const detail = p.punten_detail ?? {};
            const gestreept = new Set(detail._gestreept ?? []);
            const wCellen = heeftWedstrijden
                ? wMeta.map(w => {
                    const k = colKey(w);
                    const v = detail[k];
                    if (v == null) return `<td class="pk-w pk-nng">–</td>`;
                    const cls = gestreept.has(k) ? ' pk-streep' : '';
                    return `<td class="pk-w${cls}">${fmtP(v)}</td>`;
                }).join('')
                : '';
            const tot = heeftWedstrijden
                ? `<td class="pk-tot">${fmtP(p.punten_totaal)}</td>`
                : '';
            return `${voor}<tr>
                <td class="pk-pos">${buiten ? '' : esc(p.positie)}</td>
                <td class="pk-snr">${esc(p.start_number ?? '–')}</td>
                <td class="pk-naam">${esc(p.naam)}</td>
                ${wCellen}
                ${tot}
            </tr>`;
        }).join('');
        // Wedstrijd-legenda onder de tabel
        const legendaRijen = heeftWedstrijden
            ? wMeta.map((w, i) => {
                const bp = +w.bonus_punten || 1;
                const suffix = (w.is_finale ? ' · finale' : '')
                    + (w.bonus_modus ? ` · <b>bonus</b> (+${bp} per aanwezige)` : '');
                return `<li><b>${w.is_finale ? 'F' : '#' + (i + 1)}</b> — ${esc(w.naam || '')}${w.datum ? ' (' + String(w.datum).substring(0, 10) + ')' : ''}${suffix}</li>`;
            }).join('')
            : '';

        return `<section class="pk-cat-blok">
            <h2 class="pk-cat-titel">${esc(cat)}<span class="pk-cat-tel">(${rijen.length} rijders)</span></h2>
            <table class="pk-tabel">
                <thead>
                    <tr>
                        <th class="pk-pos">Pos.</th>
                        <th class="pk-snr">Start#</th>
                        <th>Naam</th>
                        ${wedstrijdHdr}
                        ${heeftWedstrijden ? '<th class="pk-tot">Totaal</th>' : ''}
                    </tr>
                </thead>
                <tbody>${rijenHtml}</tbody>
            </table>
            ${legendaRijen ? `<ul class="pk-legenda">${legendaRijen}</ul>` : ''}
        </section>`;
    }).join('');

    const datumNu = new Date().toLocaleString('nl-NL', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
    const titel = esc(k.naam || 'Serie-klassement');
    const subtitel = [
        k.seizoen,
        k.totaal_rijders ? k.totaal_rijders + ' rijders' : '',
        'afgedrukt ' + datumNu,
    ].filter(Boolean).map(esc).join(' · ');

    const heeftStreep = allePos.some(p => (p.punten_detail?._gestreept ?? []).length > 0);

    // Org-logo + baan-logo + sponsor-footer ophalen via de gedeelde helper.
    let orgLogoHtml = '';
    let baanLogoHtml = '';
    let footerHtml = '';
    if (typeof bouwOrgHeaderFooter === 'function') {
        const h = bouwOrgHeaderFooter(esc);
        orgLogoHtml  = h?.orgLogoHtml  ?? '';
        baanLogoHtml = h?.baanLogoHtml ?? '';
        footerHtml   = h?.footerHtml   ?? '';
    }

    const w = window.open('', '_blank');
    if (!w) {
        toonBevestigDialog('Pop-up geblokkeerd. Sta pop-ups toe voor deze site.', 'Afdrukken', 'OK', '');
        return;
    }
    w.document.write(`<!DOCTYPE html>
<html lang="nl"><head>
<meta charset="utf-8">
<title>${titel}</title>
<style>
@page { size: A4 landscape; margin: 8mm 10mm; }
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 9pt; color: #000; margin: 0; padding: 0; }

/* Header: titel/subtitel links, org-logo rechts. NIET page-break-inside-avoid
   — anders kan een grote eerste categorie-blok hem op een eigen lege pagina
   duwen. De header is klein genoeg om altijd op pagina 1 te passen. */
.pk-header   { display: flex; justify-content: space-between; align-items: flex-start;
               border-bottom: 2px solid #1a3a5c; padding-bottom: 2mm; margin-bottom: 4mm;
               gap: 6mm; }
.pk-titel    { font-size: 14pt; font-weight: 700; color: #1a3a5c; margin: 0; line-height: 1.2; }
.pk-subtitel { font-size: 8.5pt; color: #555; margin-top: 1mm; }
.pk-orglogo  { flex-shrink: 0; }
.pk-baan     { flex-shrink: 0; }

/* Categorie-blokken: probeer ze bij elkaar te houden. Past het blok niet op
   één pagina (echt grote cats), dan negeren browsers break-inside: avoid en
   breekt het blok alsnog — precies wat we willen. Page-break-after-avoid op
   de categorie-titel houdt de titel bij de eerste rij. */
.pk-cat-blok    { margin-bottom: 6mm;
                  page-break-inside: avoid; break-inside: avoid; }
.pk-cat-titel   { font-size: 11pt; font-weight: 700; color: #1a3a5c;
                  margin: 0 0 1.5mm 0; line-height: 1.2;
                  page-break-after: avoid; break-after: avoid; }
.pk-cat-tel     { font-size: 8pt; font-weight: normal; color: #666; margin-left: 6px; }

.pk-tabel       { width: 100%; border-collapse: collapse; table-layout: auto; }
.pk-tabel th    { background: #dce6f0; text-align: left; padding: 1.2mm 2mm;
                  font-size: 8.5pt; border-bottom: 1px solid #1a3a5c; line-height: 1.2; }
.pk-tabel td    { padding: 0.8mm 2mm; font-size: 9pt; border-bottom: 1px solid #ddd;
                  vertical-align: middle; line-height: 1.3; }
.pk-tabel tr:nth-child(even) td { background: #f7f9fc; }

.pk-pos    { width: 12mm; text-align: center; font-weight: 700; }
.pk-snr    { width: 14mm; text-align: center; }
.pk-naam   { width: auto; }
.pk-w      { width: 14mm; text-align: center; font-variant-numeric: tabular-nums; }
.pk-tot    { width: 18mm; text-align: center; font-weight: 700; color: #1a3a5c;
             background: #eef4f9 !important; }

.pk-nng    { color: #bbb; }
.pk-streep { text-decoration: line-through; color: #999; }

.pk-legenda { font-size: 7.5pt; color: #555; list-style: none; padding-left: 0;
              margin: 1mm 0 0 0; columns: 2; column-gap: 6mm; }
.pk-legenda li { padding: 0.3mm 0; break-inside: avoid; }

.pk-streep-noot { font-size: 7.5pt; color: #777; margin-top: 3mm;
                  border-top: 1px dotted #ccc; padding-top: 1.5mm; font-style: italic; }
.pk-regels        { font-size: 8pt; color: #333; margin: 0 0 4mm 0; padding: 2mm 3mm;
                    border: 1px solid #c9d3df; border-radius: 1.5mm; background: #f7f9fc;
                    page-break-inside: avoid; break-inside: avoid; }
.pk-regels-kop    { font-weight: 700; color: #1a3a5c; font-size: 8.5pt; margin-bottom: 1mm; }
.pk-regels-tussen { font-size: 7.8pt; color: #6b4a12; margin-bottom: 1.5mm; line-height: 1.35; }
.pk-regels-lijst  { margin: 0; padding-left: 4.5mm; line-height: 1.4; }
.pk-regels-lijst li { padding: 0.2mm 0; break-inside: avoid; }
</style></head>
<body>
<div class="pk-header">
    <div>
        <h1 class="pk-titel">${titel}</h1>
        <div class="pk-subtitel">${subtitel}</div>
    </div>
    ${baanLogoHtml ? `<div class="pk-baan">${baanLogoHtml}</div>` : ''}
    ${orgLogoHtml ? `<div class="pk-orglogo">${orgLogoHtml}</div>` : ''}
</div>
${rkRegelsPrintBlok(k)}
${blokkenHtml}
${heeftStreep ? `<div class="pk-streep-noot">Doorgehaalde scores zijn weggestreept (streepresultaten-regel) en tellen niet mee in het totaal.</div>` : ''}
${footerHtml}
<script>
window.addEventListener('load', function(){
    setTimeout(function(){ window.focus(); window.print(); }, 200);
});
window.addEventListener('afterprint', function(){
    setTimeout(function(){ window.close(); }, 100);
});
<\/script>
</body></html>`);
    w.document.close();

    // Globale state herstellen
    if (typeof huidigOrganisatie !== 'undefined') {
        // eslint-disable-next-line no-global-assign
        huidigOrganisatie = _origOrg;
    }
    if (typeof huidigBaan !== 'undefined') {
        // eslint-disable-next-line no-global-assign
        huidigBaan = _origBaan;
    }
}
