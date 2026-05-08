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
    `;
    el('hp-btn-scan').addEventListener('click', _hpDoeScan);
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

    // Groepeer per wedstrijd voor leesbaarheid
    const perComp = {};  // comp_id → { naam, datum, uitslag: [], klas: [] }
    const ensure = (r) => {
        if (!perComp[r.competition_id]) perComp[r.competition_id] = {
            naam: r.competition_naam, datum: r.competition_datum, uitslag: [], klas: [],
        };
        return perComp[r.competition_id];
    };
    for (const r of (data.wees_uitslag    ?? [])) ensure(r).uitslag.push(r);
    for (const r of (data.wees_klassement ?? [])) ensure(r).klas.push(r);

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
            <div class="hp-rapport-comp-kop">${escHtml(c.naam || '?')} <small>(${escHtml(datumKort)})</small></div>
            <ul class="hp-rapport-list">`;
        for (const u of c.uitslag) {
            const splitTxt = u.split_group ? ` [${escHtml(u.split_group)}]` : '';
            html += `<li>📊 Uitslag — <b>${escHtml(u.dc_naam)}</b> / ${escHtml(u.distance_naam)}${splitTxt} — ${u.rijders} rijder${u.rijders === 1 ? '' : 's'}</li>`;
        }
        for (const k of c.klas) {
            const splitTxt = k.split_group ? ` [${escHtml(k.split_group)}]` : '';
            html += `<li>🏆 Klassement — <b>${escHtml(k.dc_naam)}</b>${splitTxt} — ${k.rijders} rijder${k.rijders === 1 ? '' : 's'}</li>`;
        }
        html += `</ul></div>`;
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
