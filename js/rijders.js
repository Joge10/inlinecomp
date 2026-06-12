// ==========================================================
//  InlineComp – Rijderbeheer (AVG)
//
//  Zoeken op achternaam / startnummer / licentienummer, tonen
//  van alle persoons-velden, transponder-toewijzingen en
//  wedstrijd-historie, en — op verzoek — anonimiseren (onomkeerbaar).
// ==========================================================

let _rijGeselecteerd = null;   // license_key van de rijder die rechts getoond wordt

function toonRijdersPagina() {
    // Zet event-listeners één keer (idempotent via flag op element)
    const inp = document.getElementById('rij-zoek-inp');
    const btn = document.getElementById('rij-zoek-btn');
    if (inp && !inp.dataset.init) {
        inp.dataset.init = '1';
        // Debounce op typen (350ms); Enter = direct zoeken
        let timer;
        inp.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(rijZoek, 350);
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { clearTimeout(timer); rijZoek(); }
        });
        btn?.addEventListener('click', rijZoek);
        inp.focus();
    } else {
        inp?.focus();
    }
}

async function rijZoek() {
    const q = document.getElementById('rij-zoek-inp').value.trim();
    const container = document.getElementById('rij-zoek-resultaat');
    if (!q || q.length < 2) {
        container.innerHTML = '';
        return;
    }
    container.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Zoeken…</div>';
    try {
        const res = await fetch('api/persoon_beheer.php?action=zoek&q=' + encodeURIComponent(q));
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Fout bij zoeken');
        rijToonResultaten(data.rijders || []);
    } catch (e) {
        container.innerHTML = `<div class="status-msg error">${escHtml(e.message)}</div>`;
    }
}

function rijToonResultaten(rijders) {
    const container = document.getElementById('rij-zoek-resultaat');
    if (!rijders.length) {
        container.innerHTML = '<div class="status-msg" style="color:#666">Geen rijders gevonden.</div>';
        return;
    }
    let html = `<div class="rij-tel">${rijders.length} resultaat${rijders.length !== 1 ? 'en' : ''}${rijders.length === 100 ? ' (max)' : ''}</div>`;
    html += '<ul class="rij-zoek-lijst">';
    rijders.forEach(r => {
        const anoniem = !!r.anonymized_at;
        const actief  = _rijGeselecteerd === r.license_key ? ' actief' : '';
        html += `<li class="rij-zoek-item${actief}${anoniem ? ' rij-anoniem' : ''}" data-lk="${escHtml(r.license_key)}">
            <div class="rij-zoek-naam">${escHtml(r.full_name)}${anoniem ? ' <span class="rij-anoniem-badge">geanonimiseerd</span>' : ''}</div>
            <div class="rij-zoek-meta">
                ${r.start_number ? 'Snr <strong>' + r.start_number + '</strong> · ' : ''}
                ${escHtml(r.category ?? '')}${r.category && r.club_short ? ' · ' : ''}${escHtml(r.club_short ?? '')}
                · <span class="rij-zoek-lk">${escHtml(r.license_key)}</span>
            </div>
        </li>`;
    });
    html += '</ul>';
    container.innerHTML = html;

    // Klik-handlers
    container.querySelectorAll('.rij-zoek-item').forEach(li => {
        li.addEventListener('click', () => rijToonDetail(li.dataset.lk));
    });
}

async function rijToonDetail(licenseKey) {
    _rijGeselecteerd = licenseKey;
    // Highlight in de lijst
    document.querySelectorAll('.rij-zoek-item').forEach(li => {
        li.classList.toggle('actief', li.dataset.lk === licenseKey);
    });
    const panel = document.getElementById('rij-detail');
    panel.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';
    try {
        const res = await fetch('api/persoon_beheer.php?action=detail&license_key=' + encodeURIComponent(licenseKey));
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Fout bij laden');
        rijRenderDetail(data);
    } catch (e) {
        panel.innerHTML = `<div class="status-msg error">${escHtml(e.message)}</div>`;
    }
}

function rijRenderDetail(data) {
    const r = data.rijder;
    const tps = data.transponders || [];
    const bekendeTps = data.bekende_transponders || [];
    const weds = data.wedstrijden || [];
    const afstanden = data.afstanden || [];
    const pdfKls = data.pdf_klassementen || [];
    const anoniem = !!r.anonymized_at;

    // Geslacht: 0=man, 1=vrouw, null=onbekend
    const geslacht = r.gender === 1 || r.gender === '1' ? 'vrouw'
                   : r.gender === 0 || r.gender === '0' ? 'man' : '—';

    // Rijtje met persoonsgegevens (labels + waardes)
    const veld = (label, waarde) => `
        <div class="rij-detail-veld">
            <label>${escHtml(label)}</label>
            <div class="rij-detail-waarde">${waarde === null || waarde === undefined || waarde === '' ? '—' : escHtml(waarde)}</div>
        </div>`;

    // Sinds 2026-06-12: per-veld inline-edit vervangen door één "✎ Bewerken"-
    // modus die alle velden bewerkt (incl. club-dropdown om spelling-drift
    // te voorkomen) en optioneel een DC-verplaats-modus. Zie rijEditOpen* below.

    // Anonimiseer-blok
    let anonBlok;
    if (anoniem) {
        anonBlok = `<div class="rij-avg-anoniem">
            <strong>Geanonimiseerd</strong> op ${escHtml(r.anonymized_at)}.
            Persoonsgegevens zijn onomkeerbaar gewist; alleen het licentienummer
            is nog aan de wedstrijdgeschiedenis gekoppeld.
            <div style="margin-top:.5rem">
                <button class="btn-secondary" id="rij-anon-undo-btn">Anonimisatie-vlag opheffen</button>
                <span class="rij-avg-hint">(gegevens komen pas terug na een nieuwe KNSB-import)</span>
            </div>
        </div>`;
    } else {
        anonBlok = `<div class="rij-avg-actie">
            <button class="btn-danger" id="rij-anon-btn">⚠ Rijder anonimiseren (AVG)</button>
            <div class="rij-avg-hint">Vervangt naam, geboortejaar, woonplaats, sponsor en startnummer door leeg/"Verwijderd". Wedstrijdhistorie blijft behouden. <strong>Onomkeerbaar.</strong></div>
        </div>`;
    }

    // Bekende transponders — alle codes ooit voor deze rijder geregistreerd
    // (slot 0=actief aan balie, 1=T1, 2=T2, 3+=extra). Helpt om snel te zien
    // welke chips bij de persoon horen, ongeacht of ze nu nog uitgegeven zijn.
    const SLOT_LABELS = { 0: 'Actief (balie)', 1: 'T1', 2: 'T2' };
    const slotLabel = s => SLOT_LABELS[s] ?? `Extra ${s}`;
    let bktHtml;
    if (bekendeTps.length) {
        bktHtml = '<table class="rij-detail-tabel"><thead><tr><th>Code</th><th>Gebruikt als</th><th>Bron</th><th>Wedstrijden</th><th>Laatst gezien</th></tr></thead><tbody>';
        bekendeTps.forEach(t => {
            const slots = (t.slots ?? '').split(',').filter(Boolean).map(s => slotLabel(parseInt(s))).join(', ');
            const bron  = (t.sources ?? '').split(',').filter(Boolean)
                              .map(s => s === 'knsb' ? 'KNSB-feed' : 'handmatig')
                              .join(' + ');
            const laatst = t.laatst_gezien ? String(t.laatst_gezien).substring(0, 10) : '—';
            bktHtml += `<tr>
                <td><strong>${escHtml(t.code)}</strong></td>
                <td>${escHtml(slots || '—')}</td>
                <td>${escHtml(bron || '—')}</td>
                <td>${escHtml(t.aantal_wedstrijden ?? 0)}</td>
                <td>${escHtml(laatst)}</td>
            </tr>`;
        });
        bktHtml += '</tbody></table>';
    } else {
        bktHtml = '<div class="rij-leeg">Geen transponders geregistreerd.</div>';
    }

    // Transponders (organisatie-toewijzingen)
    let tpHtml;
    if (tps.length) {
        tpHtml = '<table class="rij-detail-tabel"><thead><tr><th>Organisatie</th><th>Nr</th><th>Code</th><th>Cat</th><th>Betaald</th></tr></thead><tbody>';
        tps.forEach(t => {
            tpHtml += `<tr>
                <td>${escHtml(t.organisatie_naam)}</td>
                <td>${escHtml(t.intern_nummer)}</td>
                <td>${escHtml(t.transponder_code)}</td>
                <td>${escHtml(t.categorie ?? '—')}</td>
                <td>${parseInt(t.betaald) === 1 ? '✓ ' + escHtml(t.betaald_op ?? '') : '✗'}</td>
            </tr>`;
        });
        tpHtml += '</tbody></table>';
    } else {
        tpHtml = '<div class="rij-leeg">Geen transponder-toewijzingen.</div>';
    }

    // Wedstrijden (eindklassementen per DC)
    let wedHtml;
    if (weds.length) {
        wedHtml = '<table class="rij-detail-tabel"><thead><tr><th>Datum</th><th>Wedstrijd</th><th>Categorie</th><th>Positie</th><th>Punten</th></tr></thead><tbody>';
        weds.forEach(w => {
            wedHtml += `<tr>
                <td>${escHtml(w.comp_datum ?? '')}</td>
                <td>${escHtml(w.comp_naam)}</td>
                <td>${escHtml(w.dc_naam)}${w.categorie && w.categorie !== w.dc_naam ? ' <span class="rij-loc">(' + escHtml(w.categorie) + ')</span>' : ''}</td>
                <td>${escHtml(w.positie ?? '')}</td>
                <td>${w.punten !== null && w.punten !== undefined ? escHtml(parseFloat(w.punten).toFixed(2).replace(/\.?0+$/, '')) : ''}</td>
            </tr>`;
        });
        wedHtml += '</tbody></table>';
    } else {
        wedHtml = '<div class="rij-leeg">Geen wedstrijd-eindklasseringen gevonden.</div>';
    }

    // Per-afstand resultaten (optioneel)
    let afHtml = '';
    if (afstanden.length) {
        afHtml = '<details class="rij-detail-details"><summary>Per-afstand-uitslagen (' + afstanden.length + ')</summary>';
        afHtml += '<table class="rij-detail-tabel"><thead><tr><th>Datum</th><th>Wedstrijd</th><th>Categorie</th><th>Afstand</th><th>Finale</th><th>Tijd</th><th>Pos</th><th>Punten</th><th>Sanctie</th></tr></thead><tbody>';
        afstanden.forEach(a => {
            afHtml += `<tr>
                <td>${escHtml(a.comp_datum ?? '')}</td>
                <td>${escHtml(a.comp_naam)}</td>
                <td>${escHtml(a.dc_naam ?? '')}</td>
                <td>${escHtml(a.distance_naam ?? '')}</td>
                <td>${escHtml(a.finale_naam ?? '')}</td>
                <td>${escHtml(a.tijd ?? '')}</td>
                <td>${escHtml(a.positie ?? '')}</td>
                <td>${escHtml(a.punten ?? '')}</td>
                <td>${escHtml(a.sanctie ?? '')}</td>
            </tr>`;
        });
        afHtml += '</tbody></table></details>';
    }

    // Geïmporteerde klassementen (PDF + serie). Bron = klassement_posities
    // gematched op naam. Sneller bereikbaar voor speaker-info: NK-stand
    // van vorig jaar, regionale series, etc. Gegroepeerd per klassement
    // zodat lange lijsten leesbaar blijven.
    let klHtml = '';
    if (pdfKls.length) {
        // Groepeer per klassement_id
        const groepen = new Map();
        for (const k of pdfKls) {
            const key = k.klassement_id;
            if (!groepen.has(key)) {
                groepen.set(key, {
                    naam:    k.klassement_naam,
                    seizoen: k.seizoen,
                    bron:    k.bron_bestand,
                    items:   [],
                });
            }
            groepen.get(key).items.push(k);
        }
        klHtml = '<h3>Geïmporteerde klassementen (PDF/serie)</h3>';
        for (const g of groepen.values()) {
            const titel = escHtml(g.naam) + (g.seizoen ? ` <span class="rij-loc">(${escHtml(g.seizoen)})</span>` : '');
            const bron  = g.bron ? `<div class="rij-leeg" style="margin:.15rem 0 .35rem;font-size:.78rem">Bron: ${escHtml(g.bron)}</div>` : '';
            klHtml += `<details class="rij-detail-details" open>
                <summary>${titel} <span class="rij-loc">· ${g.items.length} positie${g.items.length === 1 ? '' : 's'}</span></summary>
                ${bron}
                <table class="rij-detail-tabel">
                    <thead><tr><th>Categorie</th><th>Positie</th><th>Startnr</th><th>Naam (zoals in klassement)</th></tr></thead>
                    <tbody>`;
            for (const it of g.items) {
                klHtml += `<tr>
                    <td>${escHtml(it.categorie ?? '')}</td>
                    <td>${escHtml(it.positie ?? '')}</td>
                    <td>${escHtml(it.start_number ?? '')}</td>
                    <td>${escHtml(it.rijder_naam ?? '')}</td>
                </tr>`;
            }
            klHtml += '</tbody></table></details>';
        }
    }

    document.getElementById('rij-detail').innerHTML = `
        <div class="rij-detail-header">
            <h2>${escHtml(r.full_name)}${anoniem ? ' <span class="rij-anoniem-badge">geanonimiseerd</span>' : ''}</h2>
            <div class="rij-detail-lk">Licentie: <strong>${escHtml(r.license_key)}</strong></div>
        </div>

        ${anonBlok}

        ${anoniem ? '' : `
        <div class="rij-acties-blok" id="rij-acties-blok">
            <button class="btn-secondary" id="rij-btn-bewerk">✎ Bewerken</button>
            <button class="btn-secondary" id="rij-btn-verplaats">📋 Inschrijvingen verplaatsen</button>
        </div>`}

        <h3>Persoonsgegevens</h3>
        <div class="rij-detail-grid" id="rij-pers-grid">
            ${veld('Volledige naam', r.full_name)}
            ${veld('Achternaam (short_name)', r.short_name)}
            ${veld('Geslacht', geslacht)}
            ${veld('Geboortejaar', r.birth_year)}
            ${veld('KNSB-categorie', r.category)}
            ${veld('Nationaliteit', r.nationality)}
            ${veld('Startnummer', r.start_number)}
            ${veld('Woonplaats', r.city)}
            ${veld('Sponsor', r.sponsor)}
            ${veld('Vereniging', r.club_full)}
            ${veld('Vereniging (kort)', r.club_short)}
            ${veld('KNSB-vereniging-code', r.club_code)}
            ${veld('Aangemaakt', r.created_at)}
            ${veld('Laatst gewijzigd', r.updated_at)}
        </div>
        <div id="rij-edit-container"></div>

        <h3>Bekende transponders</h3>
        ${bktHtml}

        <h3>Transponder-toewijzingen (organisatie-inventaris)</h3>
        ${tpHtml}

        <h3>Wedstrijd-historie (eindklassementen)</h3>
        ${wedHtml}

        ${afHtml}

        ${klHtml}
    `;

    // Anonimiseer-knop
    document.getElementById('rij-anon-btn')?.addEventListener('click', () => rijAnonimiseer(r));
    document.getElementById('rij-anon-undo-btn')?.addEventListener('click', () => rijAnonUndo(r));

    // Bewerken-knoppen (alleen voor niet-geanonimiseerde rijders)
    document.getElementById('rij-btn-bewerk')?.addEventListener('click',
        () => rijEditOpenBewerkmodus(r.license_key));
    document.getElementById('rij-btn-verplaats')?.addEventListener('click',
        () => rijEditOpenVerplaatsmodus(r.license_key));
}

// ── Edit-modi (persoonsdata bewerken / DC-verplaatsen) ────────────────
// Eén centraal edit-formulier met alle persons-velden + club-dropdown
// (anti-spelling-drift). DC-verplaats is een aparte modus met wedstrijd-
// keuze + entries-tabel. Beide gebruiken cluster_check.php endpoints.
//
// Eerder zat dit als "Handmatige rijder-correctie" helper-card in helpers.js;
// verhuisd naar deze pagina (2026-06-12) om dubbele functionaliteit te
// vermijden en omdat persons-edit thuishoort waar persoonsbeheer ook zit.

let _rijEditData  = null;   // {persoon, entries, alle_dcs, clubs, wedstrijd_cats}
let _rijEditComps = [];     // [{competition_id, naam, datum}] — gecached voor verplaats-modus

// Verberg de "Persoonsgegevens"-grid + actie-knoppen tijdens edit/verplaats.
// Zet ze weer aan bij Opslaan/Annuleren.
function _rijEditTogglePers(verberg) {
    const grid = document.getElementById('rij-pers-grid');
    const act  = document.getElementById('rij-acties-blok');
    if (grid) grid.style.display = verberg ? 'none' : '';
    if (act)  act.style.display  = verberg ? 'none' : '';
}

function _rijEditSluit() {
    const cont = document.getElementById('rij-edit-container');
    if (cont) cont.innerHTML = '';
    _rijEditTogglePers(false);
    _rijEditData = null;
}

async function rijEditOpenBewerkmodus(licenseKey) {
    const cont = document.getElementById('rij-edit-container');
    if (!cont) return;
    _rijEditTogglePers(true);
    cont.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';
    try {
        const url = 'api/cluster_check.php?action=persoon_detail&license_key='
                  + encodeURIComponent(licenseKey);
        const res  = await fetch(url);
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        data.competition_id = '';
        _rijEditData = data;
        _rijEditRenderBewerkmodus();
    } catch (e) {
        cont.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

async function rijEditOpenVerplaatsmodus(licenseKey) {
    const cont = document.getElementById('rij-edit-container');
    if (!cont) return;
    _rijEditTogglePers(true);
    cont.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Wedstrijden laden…</div>';
    try {
        const [pRes, cRes] = await Promise.all([
            fetch('api/cluster_check.php?action=persoon_detail&license_key='
                + encodeURIComponent(licenseKey)),
            (_rijEditComps.length
                ? Promise.resolve({json: async () => _rijEditComps})
                : fetch('api/cluster_check.php?action=competities')),
        ]);
        const pData = await pRes.json();
        if (pData.error) throw new Error(pData.error);
        const cData = await cRes.json();
        if (Array.isArray(cData)) _rijEditComps = cData;
        pData.competition_id = '';
        _rijEditData = pData;
        _rijEditRenderVerplaatsmodus();
    } catch (e) {
        cont.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// Edit-formulier: alle persons-velden vooringevuld + club-dropdown
function _rijEditRenderBewerkmodus() {
    const data = _rijEditData;
    if (!data) return;
    const p = data.persoon;
    const huidigCatUpper = (p.category || '').toUpperCase();
    const clubsLijst = data.clubs || [];
    if (p.club_short && !clubsLijst.some(c => c.club_short === p.club_short)) {
        clubsLijst.unshift({
            club_short: p.club_short,
            club_full:  p.club_full,
            club_code:  p.club_code,
        });
    }
    document.getElementById('rij-edit-container').innerHTML = `
        <div class="rij-edit-card">
            <div class="rij-edit-section-titel">
                ✎ Bewerken — persoonsgegevens
                <small>— huidige waarden zijn ingevuld; aanpassen waar nodig</small>
            </div>
            <div class="rij-edit-grid">
                <label class="rij-edit-veld">
                    <span>Volledige naam</span>
                    <input type="text" class="inp" id="rij-edit-fullname"
                           value="${escHtml(p.full_name || '')}">
                </label>
                <label class="rij-edit-veld">
                    <span>Achternaam (kort)</span>
                    <input type="text" class="inp" id="rij-edit-shortname"
                           value="${escHtml(p.short_name || '')}">
                </label>
                <label class="rij-edit-veld">
                    <span>Geboortejaar</span>
                    <input type="number" class="inp" id="rij-edit-birth"
                           value="${p.birth_year ?? ''}"
                           min="1900" max="${new Date().getFullYear()}">
                </label>
                <label class="rij-edit-veld">
                    <span>Nationaliteit</span>
                    <input type="text" class="inp rij-edit-code-inp" id="rij-edit-nat"
                           value="${escHtml(p.nationality || '')}"
                           maxlength="3">
                </label>
                <label class="rij-edit-veld">
                    <span>Gender</span>
                    <select class="inp" id="rij-edit-gender">
                        <option value="0"${(p.gender == 0 || p.gender === '0') ? ' selected' : ''}>M (man)</option>
                        <option value="1"${(p.gender == 1 || p.gender === '1') ? ' selected' : ''}>V (vrouw)</option>
                    </select>
                </label>
                <label class="rij-edit-veld">
                    <span>Categorie</span>
                    <select class="inp" id="rij-edit-cat">
                        ${huidigCatUpper && !(data.wedstrijd_cats || []).includes(huidigCatUpper)
                            ? `<option value="${escHtml(huidigCatUpper)}" selected>${escHtml(huidigCatUpper)}</option>`
                            : ''}
                        ${(data.wedstrijd_cats || []).map(c =>
                            `<option value="${escHtml(c)}"${c === huidigCatUpper ? ' selected' : ''}>${escHtml(c)}</option>`
                        ).join('')}
                    </select>
                </label>
                <label class="rij-edit-veld">
                    <span>Startnummer</span>
                    <input type="number" class="inp" id="rij-edit-snr"
                           value="${p.start_number ?? ''}">
                </label>
                <label class="rij-edit-veld rij-edit-veld-wide">
                    <span>Club</span>
                    <div class="rij-edit-club-row">
                        <select class="inp" id="rij-edit-club">
                            <option value="">— geen club —</option>
                            ${clubsLijst.map(c => {
                                const label = (c.club_full && c.club_full !== c.club_short)
                                    ? `${c.club_short} — ${c.club_full}`
                                    : c.club_short;
                                const val = `${c.club_short}|||${c.club_full || ''}|||${c.club_code ?? ''}`;
                                const sel = c.club_short === (p.club_short || '');
                                return `<option value="${escHtml(val)}"${sel ? ' selected' : ''}>${escHtml(label)}</option>`;
                            }).join('')}
                        </select>
                        <button class="btn-secondary" id="rij-edit-club-nieuw" type="button">+ Nieuwe…</button>
                    </div>
                </label>
                <label class="rij-edit-veld">
                    <span>Sponsor / team</span>
                    <input type="text" class="inp" id="rij-edit-sponsor"
                           value="${escHtml(p.sponsor || '')}">
                </label>
                <label class="rij-edit-veld">
                    <span>Woonplaats</span>
                    <input type="text" class="inp" id="rij-edit-city"
                           value="${escHtml(p.city || '')}">
                </label>
            </div>
            <div class="rij-edit-acties">
                <button class="btn-secondary" id="rij-edit-annuleer">Annuleren</button>
                <button class="btn-primary" id="rij-edit-opslaan">Opslaan</button>
            </div>
            <div id="rij-edit-melding" class="status-msg"></div>
        </div>`;
    document.getElementById('rij-edit-annuleer').addEventListener('click', _rijEditSluit);
    document.getElementById('rij-edit-opslaan').addEventListener('click', _rijEditOpslaanPersons);
    document.getElementById('rij-edit-club-nieuw').addEventListener('click', _rijEditOpenNieuweClub);
}

// Verplaats-formulier: wedstrijd-dropdown + per-entry DC-selecties
function _rijEditRenderVerplaatsmodus() {
    const data = _rijEditData;
    if (!data) return;
    const huidigComp = data.competition_id || '';
    const compOpties = `
        <option value="">— kies wedstrijd —</option>
        ${(_rijEditComps || []).map(c => {
            const dat = c.datum ? new Date(c.datum).toLocaleDateString('nl-NL',
                {day:'2-digit', month:'2-digit', year:'numeric'}) : '?';
            const sel = String(c.competition_id) === String(huidigComp);
            return `<option value="${escHtml(c.competition_id)}"${sel ? ' selected' : ''}>${escHtml(c.naam)} (${escHtml(dat)})</option>`;
        }).join('')}`;
    document.getElementById('rij-edit-container').innerHTML = `
        <div class="rij-edit-card">
            <div class="rij-edit-section-titel">
                📋 Inschrijvingen verplaatsen
                <small>— kies een wedstrijd; daarna kun je per inschrijving een andere DC selecteren</small>
            </div>
            <div class="rij-edit-comp-row">
                <select class="inp" id="rij-edit-comp">${compOpties}</select>
            </div>
            <div id="rij-edit-entries"></div>
            <div class="rij-edit-acties">
                <button class="btn-secondary" id="rij-edit-annuleer">Annuleren</button>
                <button class="btn-primary" id="rij-edit-opslaan">Opslaan</button>
            </div>
            <div id="rij-edit-melding" class="status-msg"></div>
        </div>`;
    document.getElementById('rij-edit-annuleer').addEventListener('click', _rijEditSluit);
    document.getElementById('rij-edit-opslaan').addEventListener('click', _rijEditOpslaanVerplaats);
    document.getElementById('rij-edit-comp').addEventListener('change',
        e => _rijEditLaadWedstrijd(e.target.value));
    _rijEditLaadWedstrijd('');
}

async function _rijEditLaadWedstrijd(compId) {
    const data = _rijEditData;
    if (!data) return;
    const blok = document.getElementById('rij-edit-entries');
    if (!blok) return;
    if (!compId) {
        data.competition_id = '';
        data.entries  = [];
        data.alle_dcs = [];
        blok.innerHTML = `<div class="rij-leeg">Kies eerst een wedstrijd om inschrijvingen te zien.</div>`;
        return;
    }
    blok.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Wedstrijd-data laden…</div>';
    try {
        const url = 'api/cluster_check.php?action=persoon_detail'
                  + '&license_key=' + encodeURIComponent(data.persoon.license_key)
                  + '&competition_id=' + encodeURIComponent(compId);
        const res  = await fetch(url);
        const d    = await res.json();
        if (d.error) throw new Error(d.error);
        data.entries        = d.entries  || [];
        data.alle_dcs       = d.alle_dcs || [];
        data.competition_id = compId;
        _rijEditRenderEntries();
    } catch (e) {
        blok.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

function _rijEditRenderEntries() {
    const data = _rijEditData;
    const blok = document.getElementById('rij-edit-entries');
    if (!data || !blok) return;
    const dcOpties = (huidigDcId) => `
        <option value="">— houden in huidige DC —</option>
        ${(data.alle_dcs || []).filter(d => d.dc_id !== huidigDcId).map(d => `
            <option value="${escHtml(d.dc_id)}">${escHtml(d.dc_naam)}${d.category_filter ? ` (${escHtml(d.category_filter)})` : ''}</option>
        `).join('')}`;
    blok.innerHTML = data.entries.length === 0
        ? `<div class="rij-leeg">Geen inschrijvingen in deze wedstrijd.</div>`
        : data.entries.map(e => `
            <div class="rij-edit-entry">
                <span class="rij-edit-entry-naam"><b>${escHtml(e.dc_naam)}</b></span>
                <select class="inp rij-edit-verpl-sel rij-edit-entry-sel"
                        data-entry="${e.entry_id}" data-huidig="${escHtml(e.dc_id)}">
                    ${dcOpties(e.dc_id)}
                </select>
            </div>`).join('');
}

// "+ Nieuwe club"-modal. Code krijgt automatisch -IC suffix zodat operator
// weet welke clubs niet uit de KNSB-feed komen. Code moet uniek zijn in de
// bestaande club-lijst.
function _rijEditOpenNieuweClub() {
    const data = _rijEditData;
    if (!data) return;
    const bestaand = new Set((data.clubs || []).map(c => (c.club_short || '').toUpperCase()));
    const overlay = document.createElement('div');
    overlay.className = 'rij-edit-nc-overlay';
    overlay.innerHTML = `
        <div class="rij-edit-nc-box">
            <div class="rij-edit-nc-titel">+ Nieuwe club toevoegen</div>
            <div class="rij-edit-nc-uitleg">
                Code krijgt automatisch het achtervoegsel <code>-IC</code> zodat je weet
                dat de club handmatig is toegevoegd (en niet uit de KNSB-feed komt).
            </div>
            <label class="rij-edit-nc-veld">
                Code (kort)
                <input type="text" id="rij-edit-nc-code" class="inp rij-edit-code-inp"
                       placeholder="bv. SVD" maxlength="17">
                <span class="rij-edit-nc-preview">Wordt opgeslagen als <span id="rij-edit-nc-preview">…-IC</span></span>
            </label>
            <label class="rij-edit-nc-veld">
                Volledige naam
                <input type="text" id="rij-edit-nc-naam" class="inp"
                       placeholder="bv. Sport Vereniging Dronten">
            </label>
            <div id="rij-edit-nc-melding" class="rij-edit-nc-melding" hidden></div>
            <div class="rij-edit-nc-knoppen">
                <button class="btn-secondary" id="rij-edit-nc-annul" type="button">Annuleren</button>
                <button class="btn-primary" id="rij-edit-nc-ok" type="button">Toevoegen</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    const codeInp   = overlay.querySelector('#rij-edit-nc-code');
    const naamInp   = overlay.querySelector('#rij-edit-nc-naam');
    const previewEl = overlay.querySelector('#rij-edit-nc-preview');
    const meldingEl = overlay.querySelector('#rij-edit-nc-melding');
    codeInp.addEventListener('input', () => {
        const v = codeInp.value.trim().toUpperCase().replace(/\s+/g, '');
        previewEl.textContent = (v || '…') + '-IC';
    });
    const sluit = () => overlay.remove();
    overlay.querySelector('#rij-edit-nc-annul').onclick = sluit;
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    overlay.querySelector('#rij-edit-nc-ok').onclick = () => {
        const codeRaw = codeInp.value.trim().toUpperCase().replace(/\s+/g, '');
        const naam = naamInp.value.trim();
        meldingEl.hidden = true;
        if (!codeRaw) {
            meldingEl.textContent = 'Vul een code in.'; meldingEl.hidden = false; return;
        }
        if (!naam) {
            meldingEl.textContent = 'Vul de volledige clubnaam in.'; meldingEl.hidden = false; return;
        }
        const codeFinal = codeRaw + '-IC';
        if (bestaand.has(codeFinal.toUpperCase())) {
            meldingEl.textContent = `Code "${codeFinal}" bestaat al — kies een andere afkorting.`;
            meldingEl.hidden = false; return;
        }
        _rijEditData.clubs = (_rijEditData.clubs || []).concat([{
            club_short: codeFinal, club_full: naam, club_code: null,
        }]);
        const sel = document.getElementById('rij-edit-club');
        const opt = document.createElement('option');
        opt.value = codeFinal + '|||' + naam + '|||';
        opt.textContent = `${codeFinal} — ${naam}`;
        sel.appendChild(opt);
        sel.value = opt.value;
        sluit();
    };
    codeInp.focus();
}

// Opslaan: persoons-velden alleen. Stuurt alleen gewijzigde velden;
// '' = SET NULL. Backend: cluster_check.php#corrigeer_persoon.
async function _rijEditOpslaanPersons() {
    const data = _rijEditData;
    if (!data) return;
    const p   = data.persoon;
    const lic = p.license_key;
    const h = {
        gender:       (p.gender === null || p.gender === undefined) ? '' : String(p.gender),
        category:     (p.category   || '').toUpperCase(),
        start_number: p.start_number == null ? '' : String(p.start_number),
        full_name:    p.full_name   || '',
        short_name:   p.short_name  || '',
        birth_year:   p.birth_year  == null ? '' : String(p.birth_year),
        nationality: (p.nationality || '').toUpperCase(),
        club_short:   p.club_short  || '',
        club_full:    p.club_full   || '',
        club_code:    p.club_code   == null ? '' : String(p.club_code),
        sponsor:      p.sponsor     || '',
        city:         p.city        || '',
    };
    const clubSel = document.getElementById('rij-edit-club').value;
    let clubShort = '', clubFull = '', clubCode = '';
    if (clubSel) [clubShort, clubFull, clubCode] = clubSel.split('|||');
    const n = {
        gender:       document.getElementById('rij-edit-gender').value,
        category:     document.getElementById('rij-edit-cat').value.trim().toUpperCase(),
        start_number: document.getElementById('rij-edit-snr').value.trim(),
        full_name:    document.getElementById('rij-edit-fullname').value.trim(),
        short_name:   document.getElementById('rij-edit-shortname').value.trim(),
        birth_year:   document.getElementById('rij-edit-birth').value.trim(),
        nationality:  document.getElementById('rij-edit-nat').value.trim().toUpperCase(),
        club_short:   clubShort,
        club_full:    clubFull,
        club_code:    clubCode,
        sponsor:      document.getElementById('rij-edit-sponsor').value.trim(),
        city:         document.getElementById('rij-edit-city').value.trim(),
    };
    const payload = {
        action:      'corrigeer_persoon',
        license_key: lic,
        verplaatsingen: [],
    };
    const veldNaarPayload = {
        gender:       'nieuwe_gender',
        category:     'nieuwe_category',
        start_number: 'nieuwe_start_number',
        full_name:    'nieuwe_full_name',
        short_name:   'nieuwe_short_name',
        birth_year:   'nieuwe_birth_year',
        nationality:  'nieuwe_nationality',
        club_short:   'nieuwe_club_short',
        club_full:    'nieuwe_club_full',
        club_code:    'nieuwe_club_code',
        sponsor:      'nieuwe_sponsor',
        city:         'nieuwe_city',
    };
    let anyChange = false;
    for (const k of Object.keys(veldNaarPayload)) {
        if (n[k] !== h[k]) {
            payload[veldNaarPayload[k]] = n[k];
            anyChange = true;
        }
    }
    const meld = document.getElementById('rij-edit-melding');
    if (!anyChange) {
        meld.textContent = 'Geen wijzigingen gedetecteerd.';
        meld.className = 'status-msg';
        return;
    }
    await _rijEditPostEnHerlaad(payload, lic, /*verplaatsmodus*/false);
}

// Opslaan: alleen DC-verplaatsingen. Persoons-velden blijven onaangeraakt.
async function _rijEditOpslaanVerplaats() {
    const data = _rijEditData;
    if (!data) return;
    const compEl = document.getElementById('rij-edit-comp');
    const compId = compEl ? compEl.value : '';
    const lic = data.persoon.license_key;
    const meld = document.getElementById('rij-edit-melding');
    if (!compId) {
        meld.textContent = 'Kies eerst een wedstrijd.';
        meld.className = 'status-msg';
        return;
    }
    const verplaatsingen = [];
    document.querySelectorAll('.rij-edit-verpl-sel').forEach(s => {
        if (s.value) {
            verplaatsingen.push({
                entry_id:    parseInt(s.dataset.entry),
                doel_dc_id:  s.value,
            });
        }
    });
    if (!verplaatsingen.length) {
        meld.textContent = 'Geen verplaatsingen geselecteerd.';
        meld.className = 'status-msg';
        return;
    }
    await _rijEditPostEnHerlaad({
        action: 'corrigeer_persoon',
        license_key: lic,
        competition_id: compId,
        verplaatsingen,
    }, lic, /*verplaatsmodus*/true);
}

async function _rijEditPostEnHerlaad(payload, lic, isVerplaats) {
    const meld = document.getElementById('rij-edit-melding');
    const btn  = document.getElementById('rij-edit-opslaan');
    btn.disabled = true;
    meld.textContent = 'Bezig…';
    meld.className = 'status-msg loading';
    try {
        const res = await fetch('api/cluster_check.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const d = await res.json();
        if (d.error) throw new Error(d.error);
        const delen = [];
        if (d.persons_bijgewerkt) delen.push('persons-velden bijgewerkt');
        if (d.verplaatst)        delen.push(`${d.verplaatst} inschrijving${d.verplaatst === 1 ? '' : 'en'} verplaatst`);
        if (d.he_verwijderd)     delen.push(`${d.he_verwijderd} heat-entr${d.he_verwijderd === 1 ? 'y' : 'ies'} opgeschoond`);
        meld.textContent = '✓ ' + (delen.join(', ') || 'niets veranderd') + '.';
        meld.className = 'status-msg success';
        // Refresh detail-paneel: trekt verse persons-data uit DB.
        await rijToonDetail(lic);
        if (typeof rijZoek === 'function') rijZoek();
    } catch (e) {
        meld.textContent = '⚠ ' + e.message;
        meld.className = 'status-msg error';
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function rijAnonimiseer(rijder) {
    // Stap 1: bevestiging via input-modal — operator moet expliciet het
    // licentienummer typen om per-ongeluk-klikken te voorkomen.
    const bericht =
        `Je staat op het punt om ONOMKEERBAAR de persoonsgegevens van\n` +
        `${rijder.full_name} (licentie ${rijder.license_key}) te anonimiseren.\n\n` +
        `Typ het licentienummer ter bevestiging:`;
    const ingetypt = await toonInputDialog({
        titel:        'Rijder anonimiseren',
        bericht:      bericht,
        inputType:    'text',
        placeholder:  rijder.license_key,
        monospace:    true,           // licentienummers in monospace voor leesbaarheid
        labelOk:      'Anonimiseren',
    });
    if (ingetypt === null) return;     // geannuleerd
    if (ingetypt.trim() !== rijder.license_key) {
        toonBevestigDialog(
            'Bevestiging klopt niet — geen actie ondernomen.',
            'Anonimiseren', 'OK', ''
        );
        return;
    }
    // Stap 2: API-call met nette feedback in modal-stijl (geen alert).
    try {
        const res = await fetch('api/persoon_anonimiseer.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'anonimiseer', license_key: rijder.license_key }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Fout bij anonimiseren');
        toonBevestigDialog(
            data.message || 'Rijder geanonimiseerd.',
            'Anonimiseren', 'OK', ''
        );
        rijToonDetail(rijder.license_key);   // herlaad het paneel
        rijZoek();                            // ververs de lijst
    } catch (e) {
        toonBevestigDialog('Fout: ' + e.message, 'Anonimiseren', 'OK', '');
    }
}

async function rijAnonUndo(rijder) {
    const ok = await toonBevestigDialog(
        `Anonimisatie-vlag opheffen voor licentie ${rijder.license_key}? ` +
        `De gegevens zelf blijven leeg; alleen via een nieuwe KNSB-import komen ze terug.`,
        'Anonimisatie opheffen'
    );
    if (!ok) return;
    try {
        const res = await fetch('api/persoon_anonimiseer.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'undo', license_key: rijder.license_key }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Fout bij opheffen');
        toonBevestigDialog(
            data.message || 'Anonimisatie-vlag opgeheven.',
            'Anonimisatie opheffen', 'OK', ''
        );
        rijToonDetail(rijder.license_key);
        rijZoek();
    } catch (e) {
        toonBevestigDialog('Fout: ' + e.message, 'Anonimisatie opheffen', 'OK', '');
    }
}
