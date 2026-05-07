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
            ${veld('Sponsor', r.sponsor)}
            ${veld('Vereniging', r.club_full)}
            ${veld('Vereniging (kort)', r.club_short)}
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
    `;

    // Anonimiseer-knop
    document.getElementById('rij-anon-btn')?.addEventListener('click', () => rijAnonimiseer(r));
    document.getElementById('rij-anon-undo-btn')?.addEventListener('click', () => rijAnonUndo(r));
}

async function rijAnonimiseer(rijder) {
    const bevestig = prompt(
        `Je staat op het punt om onomkeerbaar de persoonsgegevens van\n\n    ${rijder.full_name}\n    licentie: ${rijder.license_key}\n\nte anonimiseren.\n\nTyp het licentienummer ter bevestiging:`);
    if (bevestig === null) return;
    if (bevestig.trim() !== rijder.license_key) {
        alert('Bevestiging klopt niet. Geen actie ondernomen.');
        return;
    }
    try {
        const res = await fetch('api/persoon_anonimiseer.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'anonimiseer', license_key: rijder.license_key }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Fout bij anonimiseren');
        alert(data.message || 'Rijder geanonimiseerd.');
        rijToonDetail(rijder.license_key);   // herlaad het paneel
        rijZoek();                            // ververs de lijst
    } catch (e) {
        alert('Fout: ' + e.message);
    }
}

async function rijAnonUndo(rijder) {
    if (!confirm(`Anonimisatie-vlag opheffen voor licentie ${rijder.license_key}?\n\nDe gegevens zelf blijven leeg; alleen via een nieuwe KNSB-import komen ze terug.`)) return;
    try {
        const res = await fetch('api/persoon_anonimiseer.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'undo', license_key: rijder.license_key }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Fout bij opheffen');
        alert(data.message || 'Anonimisatie-vlag opgeheven.');
        rijToonDetail(rijder.license_key);
        rijZoek();
    } catch (e) {
        alert('Fout: ' + e.message);
    }
}
