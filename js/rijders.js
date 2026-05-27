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

    // Inline-bewerkbaar veld: click-to-edit. Bedoeld voor velden waar de
    // KNSB-feed soms verkeerde/lege waardes geeft (club, sponsor). Edit
    // overschrijft persons-tabel via api/persoon_update.php; KNSB-sync
    // respecteert dit door COALESCE(NULLIF(...), ...) in import.php.
    // Gebruik: <div ...><label> Sponsor <button data-edit-veld="sponsor">✎</button></label> <waarde>
    const veldEdit = (label, waarde, naam) => {
        const display = (waarde === null || waarde === undefined || waarde === '')
            ? '—'
            : escHtml(waarde);
        return `
            <div class="rij-detail-veld" data-rij-edit-rij="${escHtml(naam)}">
                <label>
                    ${escHtml(label)}
                    <button class="rij-edit-btn" data-rij-edit-veld="${escHtml(naam)}"
                            title="Wijzigen — overschrijft KNSB-waarde, blijft bewaard bij volgende import (mits KNSB leeg laat)">✎</button>
                </label>
                <div class="rij-detail-waarde" data-rij-edit-waarde="${escHtml(naam)}">${display}</div>
            </div>`;
    };

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

        <h3>Persoonsgegevens</h3>
        <div class="rij-detail-grid">
            ${veld('Volledige naam', r.full_name)}
            ${veld('Achternaam (short_name)', r.short_name)}
            ${veld('Geslacht', geslacht)}
            ${veld('Geboortejaar', r.birth_year)}
            ${veld('KNSB-categorie', r.category)}
            ${veld('Nationaliteit', r.nationality)}
            ${veld('Startnummer', r.start_number)}
            ${veld('Woonplaats', r.city)}
            ${veldEdit('Sponsor', r.sponsor, 'sponsor')}
            ${veldEdit('Vereniging', r.club_full, 'club_full')}
            ${veldEdit('Vereniging (kort)', r.club_short, 'club_short')}
            ${veld('KNSB-vereniging-code', r.club_code)}
            ${veld('Aangemaakt', r.created_at)}
            ${veld('Laatst gewijzigd', r.updated_at)}
        </div>

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

    // Edit-knoppen voor sponsor / club_full / club_short. Click op ✎ →
    // inline input verschijnt, Enter slaat op, Escape annuleert. Bij blur
    // alleen opslaan als er gewijzigd is — voorkomt onbedoelde wijzigingen
    // wanneer operator buiten het veld klikt.
    document.querySelectorAll('.rij-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => rijVeldBewerken(r, btn.dataset.rijEditVeld));
    });
}

// Inline edit van één veld op de persons-record. veldNaam = sponsor /
// club_full / club_short.
function rijVeldBewerken(rijder, veldNaam) {
    const wrap = document.querySelector(`[data-rij-edit-rij="${CSS.escape(veldNaam)}"]`);
    if (!wrap) return;
    const waardeDiv = wrap.querySelector(`[data-rij-edit-waarde="${CSS.escape(veldNaam)}"]`);
    const knop      = wrap.querySelector(`.rij-edit-btn[data-rij-edit-veld="${CSS.escape(veldNaam)}"]`);
    if (!waardeDiv || !knop) return;

    const huidig = rijder[veldNaam] ?? '';
    // Maak input
    const inp = document.createElement('input');
    inp.type      = 'text';
    inp.className = 'rij-edit-inp';
    inp.value     = huidig;
    inp.maxLength = 255;
    // Vervang display
    const origineelHtml = waardeDiv.innerHTML;
    waardeDiv.innerHTML = '';
    waardeDiv.appendChild(inp);
    inp.focus();
    inp.select();
    knop.disabled = true;

    let klaar = false;
    const annuleer = () => {
        if (klaar) return;
        klaar = true;
        waardeDiv.innerHTML = origineelHtml;
        knop.disabled = false;
    };
    const opslaan = async () => {
        if (klaar) return;
        const nieuw = inp.value.trim();
        const oudTrim = (huidig ?? '').toString().trim();
        if (nieuw === oudTrim) { annuleer(); return; }
        klaar = true;
        inp.disabled = true;
        try {
            const res = await fetch('api/persoon_update.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    license_key: rijder.license_key,
                    [veldNaam]:  nieuw, // lege string = wissen (NULL in DB)
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'Fout bij opslaan');
            // Update local rijder + display
            rijder[veldNaam] = data.persoon?.[veldNaam] ?? null;
            const tonen = rijder[veldNaam] == null || rijder[veldNaam] === ''
                ? '—' : escHtml(rijder[veldNaam]);
            waardeDiv.innerHTML = tonen;
            knop.disabled = false;
            // Ververs de zoeklijst zodat club/sponsor-wijzigingen daar ook
            // doorkomen — alleen als er een actieve zoekopdracht is.
            if (typeof rijZoek === 'function') rijZoek();
        } catch (e) {
            toonBevestigDialog('Opslaan mislukt: ' + e.message, 'Rijder bewerken', 'OK', '');
            waardeDiv.innerHTML = origineelHtml;
            knop.disabled = false;
        }
    };
    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter')  { e.preventDefault(); opslaan(); }
        if (e.key === 'Escape') { e.preventDefault(); annuleer(); }
    });
    inp.addEventListener('blur', opslaan);
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
