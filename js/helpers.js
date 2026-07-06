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
    // Defensief: forceer container + parent tab-content visible. Hoort niet
    // nodig te zijn (switchSysteemTab regelt dit), maar voorkomt een silent
    // blank-tab als ergens anders code per ongeluk display:none zet.
    cont.style.display = '';
    const parentTab = cont.closest('.org-tab-content');
    if (parentTab) parentTab.style.display = '';

    try {
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

        <div class="hp-card" id="hp-hist-card">
            <h3 class="hp-card-titel">📚 Historie-uitslagen importeren (PDF-tekst)</h3>
            <p class="hp-card-uitleg">
                Voor oude NK's of andere historische wedstrijden waar je geen
                live-loting + uitslag-archief van hebt: kies de wedstrijd (maak
                vooraf de competition + DCs aan), plak de PDF-tekst van één
                afstand in het venster, en laat de AI de uitslag eruit halen.
                Je krijgt een preview waar je nog kunt corrigeren voordat het
                in de database komt.
            </p>
            <div class="hp-hist-veld">
                <label>1. Wedstrijd</label>
                <div class="hp-hist-comp-rij">
                    <select class="inp" id="hp-hist-comp">
                        <option value="">— laden… —</option>
                    </select>
                    <button class="btn-secondary" id="hp-hist-btn-nieuw" type="button"
                            title="Maak een nieuwe wedstrijd aan voor deze historie-import">
                        ➕ Nieuwe wedstrijd
                    </button>
                </div>
            </div>
            <div class="hp-hist-veld">
                <label>2. Plak PDF-tekst (één afstand per keer; meerdere
                       categorieën per PDF mag)</label>
                <textarea class="inp" id="hp-hist-tekst" rows="8"
                          placeholder="Open de PDF op skating.nl → Ctrl+A → Ctrl+C → plak hier…"></textarea>
            </div>
            <div class="hp-card-acties">
                <button class="btn-primary" id="hp-hist-btn-extract" disabled>🤖 Analyseer met AI</button>
                <span class="hp-status" id="hp-hist-status"></span>
            </div>
            <div id="hp-hist-preview" style="display:none"></div>
        </div>

        ${currentUser?.role === 'owner' ? `
        <div class="hp-card" id="hp-coach-auth-card">
            <h3 class="hp-card-titel">🔐 Coach-app toegangswachtwoord</h3>
            <p class="hp-card-uitleg">
                Eén globaal wachtwoord voor de hele Coach-app (cross-organisatie).
                Voorkomt dat publiek massaal Coach gebruikt om hele clubs te
                monitoren (DB-load). <b>Geen security</b> — de data is openbaar —
                maar wel een drempel. Wordt automatisch op de Coach-poster gezet
                zodat coaches het bij hand hebben.
                <br><br>
                Leeg laten = Coach-app open voor iedereen (default).
                Wijziging dwingt alle bestaande coach-sessies om opnieuw in te
                voeren bij hun volgende API-call.
            </p>
            <div id="hp-ca-status" class="hp-status" style="margin-bottom:8px">Laden…</div>
            <div class="hp-ca-rij" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:6px">
                    Wachtwoord:
                    <input type="text" id="hp-ca-input" class="inp"
                           placeholder="bv. coach2026" maxlength="100"
                           style="width:200px;font-family:monospace">
                </label>
                <button class="btn-primary" id="hp-ca-btn-save">Opslaan</button>
                <button class="btn-secondary" id="hp-ca-btn-clear">🗑 Wissen (open)</button>
            </div>
            <div id="hp-ca-melding" style="margin-top:8px"></div>
        </div>` : ''}

        <div class="hp-card" id="hp-pending-card">
            <h3 class="hp-card-titel">🔗 Wacht-op-KNSB rijders koppelen</h3>
            <p class="hp-card-uitleg">
                Rijders zonder echte KNSB-licentie in je DB staan hier — twee soorten:
                <b>📜 pending</b> (uit historie-import) en <b>🌍 extern</b> (uit CSV-import).
                Beide wachten op de KNSB-feed om gekoppeld te worden aan hun echte
                licentie. Tot die tijd kun je hier:
                <br>•&nbsp;duplicaten <em>tussen</em> de twee categorieën samenvoegen
                (bv. een externe Noa die al als pending bestond)
                <br>•&nbsp;ze koppelen aan een bestaand KNSB-account als die ondertussen
                via de feed is binnengekomen
            </p>
            <div class="hp-card-acties">
                <button class="btn-secondary" id="hp-pending-btn-laad">🔍 Laad pending rijders</button>
                <span class="hp-status" id="hp-pending-status"></span>
            </div>
            <div id="hp-pending-lijst" style="display:none"></div>
        </div>

        <div class="hp-card" id="hp-cc-card">
            <h3 class="hp-card-titel">🔍 Koppel-controle (cluster-check)</h3>
            <p class="hp-card-uitleg">
                Scant <b>inschrijvingen</b> (entries) per DC en detecteert
                rijders waarvan het cluster (gender + jeugd/oud) niet matcht
                met de meerderheid in die DC — symptoom van de oude
                CSV-import-bug waar startnummer 26 in cluster Dames-jeugd
                gekoppeld werd aan een persoon met nummer 26 uit een ander
                cluster. Per probleem: zoek de juiste persoon (dominante cat
                + startnummer = uniek per KNSB-belofte) en vervang in één klik.
                Bestaande heat_entries (loting) bewegen automatisch mee.
            </p>
            <div class="hp-card-acties">
                <select class="inp" id="hp-cc-comp" style="min-width:280px">
                    <option value="">— laden… —</option>
                </select>
                <button class="btn-secondary" id="hp-cc-scan-btn" disabled>🔍 Scan</button>
                <label style="font-size:.85em;color:#555;display:inline-flex;align-items:center;gap:.3rem">
                    <input type="checkbox" id="hp-cc-debug"> 🔬 debug
                </label>
                <span class="hp-status" id="hp-cc-status"></span>
            </div>
            <div id="hp-cc-lijst" style="display:none;margin-top:.8rem"></div>
        </div>

        <div class="hp-card" id="hp-pr-card">
            <h3 class="hp-card-titel">🏃 PR-check rapport</h3>
            <p class="hp-card-uitleg">
                Per rijder vergelijking met diens <b>persoonlijk record</b> (PR)
                op dezelfde afstand. PR-bron: vastgelegde uitslagen van eerdere
                wedstrijden (uitslag_afstand). Δ &lt; 0 = <b>nieuwe PR 🏆</b>;
                Δ &gt; 0 = langzamer dan PR. Rijders zonder eerdere vastgelegde
                tijd krijgen "geen historie". Open in nieuw tabblad, Ctrl+P → PDF.
            </p>
            <div class="hp-card-acties">
                <a class="btn-primary" href="rapport_pr_kies.php"
                   target="_blank" rel="noopener">🏃 Kies wedstrijd</a>
            </div>
        </div>

        <div class="hp-card" id="hp-rec-card">
            <h3 class="hp-card-titel">🏆 Records-check rapport</h3>
            <p class="hp-card-uitleg">
                Per <em>(afstand × categorie)</em> de snelste gereden tijd binnen
                een wedstrijd vergeleken met het huidige Nederlands baan- of
                weg-record. Toont per groep recordhouder, record-tijd, snelste
                rijder in de wedstrijd + ronde/heat waar geklokt, en Δ-tijd
                (langzamer in rood, sneller-dan-record in groen + 🏆).
                Rijen met audit-mismatch (bruto-tijd ≠ officiële tijd, bv. door
                fotofinish-wisseling of handmatige RR-correctie) krijgen een
                voetnoot met beide tijden. Open in nieuw tabblad, daarna
                Ctrl+P → opslaan als PDF.
            </p>
            <div class="hp-card-acties">
                <a class="btn-primary" href="rapport_records_kies.php"
                   target="_blank" rel="noopener">📊 Kies wedstrijd</a>
            </div>
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
        _hpHistInit();
        _hpPendingInit();
        _hpClusterCheckInit();
        if (currentUser?.role === 'owner') _hpCoachAuthInit();
    } catch (e) {
        // Vangnet: render-fout mag geen lege witte tab opleveren — toon
        // expliciete foutboodschap zodat user iets ziet ipv blanco doos.
        console.error('[Helpers] render-fout:', e);
        cont.innerHTML = `
            <div class="hp-card" style="border-color:#e6b9b9;background:#fdf4f4">
                <h3 class="hp-card-titel" style="color:#b71c1c">⚠ Helpers-tab kon niet renderen</h3>
                <p class="hp-card-uitleg">${escHtml(e?.message || String(e))}</p>
                <p class="hp-card-uitleg" style="color:#666;font-size:12px">
                    Zie de browser-console (F12 → Console) voor de volledige stack-trace.
                </p>
            </div>`;
    }
}

// ════════════════════════════════════════════════════════════════════════════
//   Historie-import (PDF-tekst → uitslag_afstand via Claude AI)
// ════════════════════════════════════════════════════════════════════════════

let _hpHistComps = [];      // van /api/helpers.php?action=historie_competitions
let _hpHistResult = null;   // van /api/helpers.php?action=historie_extract

async function _hpHistInit() {
    const sel = el('hp-hist-comp');
    sel.innerHTML = '<option value="">— laden… —</option>';
    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'historie_competitions' }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        _hpHistComps = data.wedstrijden || [];

        const opts = ['<option value="">— kies wedstrijd —</option>'];
        for (const w of _hpHistComps) {
            const dat = w.competition_datum
                ? new Date(w.competition_datum).toLocaleDateString('nl-NL',
                    { day:'2-digit', month:'short', year:'2-digit' })
                : '?';
            opts.push(`<option value="${escHtml(w.competition_id)}">${escHtml(w.competition_naam)} (${escHtml(dat)}) — ${w.dcs.length} DC's</option>`);
        }
        sel.innerHTML = opts.join('');
    } catch (e) {
        sel.innerHTML = `<option value="">⚠ Fout: ${escHtml(e.message)}</option>`;
    }
    sel.addEventListener('change', _hpHistCompChange);
    el('hp-hist-tekst').addEventListener('input', _hpHistTekstChange);
    el('hp-hist-btn-extract').addEventListener('click', _hpHistExtract);
    el('hp-hist-btn-nieuw').addEventListener('click', _hpHistNieuweWedstrijd);
}

// Banen ophalen voor de baan-dropdown. Toont ALLE banen (cross-org) zodat
// een historie-wedstrijd ook gekoppeld kan worden aan een baan van een
// andere vereniging (bv. NK op gastbaan). Groepering via <optgroup>:
//   - "Eigen organisatie" (banen van de gekozen org) bovenaan
//   - "Andere organisaties" (per org gegroepeerd) eronder
// Backend valideert nu alleen dat baan bestaat, niet org-match.
async function _hpHistLaadBanenVoorOrg(orgId) {
    const baanSel = el('hp-hist-nw-baan');
    if (!baanSel) return;
    baanSel.innerHTML = '<option value="">— banen laden… —</option>';
    baanSel.disabled = true;
    try {
        const res = await fetch('api/banen.php?action=lijst_alle_met_org');
        const banen = await res.json();
        if (!Array.isArray(banen) || !banen.length) {
            baanSel.innerHTML = '<option value="">— geen banen beschikbaar —</option>';
            baanSel.disabled = true;
            return;
        }
        // Groepeer per org-id. Eigen org (orgId) krijgt eigen optgroup
        // bovenaan; rest alfabetisch op org-naam daaronder.
        const perOrg = new Map();   // org_id → { naam, banen: [] }
        for (const b of banen) {
            const oid = b.organisatie_id || '';
            if (!perOrg.has(oid)) perOrg.set(oid, { naam: b.org_naam || '(geen organisatie)', banen: [] });
            perOrg.get(oid).banen.push(b);
        }

        const opts = ['<option value="">— geen baan —</option>'];
        const baanOptie = b => {
            const stad = b.stad ? ` (${b.stad})` : '';
            return `<option value="${escHtml(b.id)}">${escHtml(b.naam + stad)}</option>`;
        };

        // Eigen org eerst (als gekozen) — geen optgroup nodig, gewoon
        // direct na de placeholder voor snelle toegang.
        if (orgId && perOrg.has(orgId)) {
            const eigen = perOrg.get(orgId);
            opts.push(`<optgroup label="Eigen organisatie — ${escHtml(eigen.naam)}">`);
            for (const b of eigen.banen) opts.push(baanOptie(b));
            opts.push('</optgroup>');
            perOrg.delete(orgId);
        }

        // Andere orgs — alfabetisch op org-naam
        const andere = [...perOrg.values()]
            .sort((a, b) => a.naam.localeCompare(b.naam, 'nl', { sensitivity: 'base' }));
        if (andere.length) {
            opts.push('<optgroup label="── Andere organisaties ──">');
            for (const o of andere) {
                for (const b of o.banen) {
                    const stad = b.stad ? ` (${b.stad})` : '';
                    opts.push(`<option value="${escHtml(b.id)}">${escHtml(b.naam + stad)} · ${escHtml(o.naam)}</option>`);
                }
            }
            opts.push('</optgroup>');
        }

        baanSel.innerHTML = opts.join('');
        baanSel.disabled = false;
        // Auto-select bij 1 baan in eigen org (typisch single-club setup).
        const eigenBanen = orgId && banen.filter(b => b.organisatie_id === orgId);
        if (eigenBanen && eigenBanen.length === 1) baanSel.value = eigenBanen[0].id;
    } catch (e) {
        baanSel.innerHTML = `<option value="">⚠ Fout: ${escHtml(e.message)}</option>`;
        baanSel.disabled = true;
    }
}

// ── Nieuwe wedstrijd aanmaken via inline-modal ──────────────────────────────
async function _hpHistNieuweWedstrijd() {
    // Eerst orgs laden (parallel met modal-opbouw zodat de UI niet wacht).
    // /api/organisaties.php geeft een array van {id, naam, ...}. Bij 1 org
    // selecteren we die automatisch — typisch geval voor een single-club
    // installatie waar alles van dezelfde org is.
    const orgsPromise = fetch('api/organisaties.php').then(r => r.json()).catch(() => []);

    // Gebruikt de standaard app-modal-structuur (.modal-overlay > .modal-dialog
     // > .modal-header / .modal-body / .modal-knoppen). Eigen scoped class
     // .hp-hist-modal voor breedte + formuliervakken.
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal-dialog hp-hist-modal" role="dialog" aria-labelledby="hp-hist-nw-titel">
            <div class="modal-header">
                <span class="modal-icon">➕</span>
                <span id="hp-hist-nw-titel">Nieuwe historie-wedstrijd</span>
            </div>
            <div class="modal-body">
                <p class="hp-hist-modal-uitleg">
                    Maak een wedstrijd-record aan voor PDF-import. Categorieën
                    (DCs) worden automatisch toegevoegd zodra je een PDF analyseert.
                </p>
                <label class="hp-hist-modal-label">Naam</label>
                <input type="text" class="modal-input" id="hp-hist-nw-naam"
                       placeholder="bv. NK Inlineskaten Baan 2024" autocomplete="off">

                <label class="hp-hist-modal-label">Organisatie
                    <small>(wie was de organisator?)</small>
                </label>
                <select class="modal-input" id="hp-hist-nw-org">
                    <option value="">— organisaties laden… —</option>
                </select>

                <label class="hp-hist-modal-label">Baan
                    <small>(optioneel — eigen org bovenaan, andere orgs eronder)</small>
                </label>
                <select class="modal-input" id="hp-hist-nw-baan">
                    <option value="">— banen laden… —</option>
                </select>

                <label class="hp-hist-modal-label">Startdatum</label>
                <input type="date" class="modal-input" id="hp-hist-nw-starts">

                <label class="hp-hist-modal-label">Einddatum
                    <small>(optioneel, bij meerdaagse)</small>
                </label>
                <input type="date" class="modal-input" id="hp-hist-nw-ends">

                <label class="hp-hist-modal-label">Locatie-veld
                    <small>(optioneel — alleen handig als geen baan gekozen is)</small>
                </label>
                <input type="text" class="modal-input" id="hp-hist-nw-venue"
                       placeholder="bv. Heerde" autocomplete="off">

                <div class="hp-hist-modal-fout" id="hp-hist-nw-fout" hidden></div>
            </div>
            <div class="modal-knoppen">
                <button class="modal-btn modal-annuleer" id="hp-hist-nw-annuleer">Annuleer</button>
                <button class="modal-btn modal-doorgaan" id="hp-hist-nw-opslaan">Aanmaken</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    el('hp-hist-nw-annuleer').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    setTimeout(() => el('hp-hist-nw-naam').focus(), 50);

    // Org-dropdown vullen zodra de fetch klaar is. Bij 1 org: auto-select +
    // direct ook de baan-dropdown laden.
    orgsPromise.then(orgs => {
        const orgSel = el('hp-hist-nw-org');
        if (!orgSel) return;  // modal kan inmiddels gesloten zijn
        if (!Array.isArray(orgs) || !orgs.length) {
            orgSel.innerHTML = '<option value="">— geen organisaties in DB —</option>';
            return;
        }
        // Sorteer alfabetisch — voorspelbaar
        orgs.sort((a, b) => (a.naam || '').localeCompare(b.naam || '', 'nl', { sensitivity: 'base' }));
        const opts = ['<option value="">— geen organisatie —</option>'];
        for (const o of orgs) {
            opts.push(`<option value="${escHtml(o.id)}">${escHtml(o.naam || '(naamloos)')}</option>`);
        }
        orgSel.innerHTML = opts.join('');
        // Bij 1 org: auto-select (typisch single-club installatie)
        if (orgs.length === 1) orgSel.value = orgs[0].id;
        // Laad banen ongeacht of er een org gekozen is — alle banen komen
        // in de dropdown, gegroepeerd. Bij org-wissel sorteert hij eigen
        // org bovenaan; zonder org gewoon alle orgs alfabetisch.
        _hpHistLaadBanenVoorOrg(orgSel.value);
    });

    // Bij elke org-wisseling: baan-dropdown re-groeperen zodat de nieuwe
    // 'eigen org' bovenaan komt. (Backend laadt alle banen ongeacht org.)
    el('hp-hist-nw-org').addEventListener('change', e => {
        _hpHistLaadBanenVoorOrg(e.target.value);
    });

    el('hp-hist-nw-opslaan').addEventListener('click', async () => {
        const naam   = el('hp-hist-nw-naam').value.trim();
        const orgId  = el('hp-hist-nw-org').value;   // mag leeg
        const baanId = el('hp-hist-nw-baan').value;  // mag leeg
        const starts = el('hp-hist-nw-starts').value.trim();
        const ends   = el('hp-hist-nw-ends').value.trim();
        const venue  = el('hp-hist-nw-venue').value.trim();
        const fout   = el('hp-hist-nw-fout');
        fout.hidden = true; fout.textContent = '';
        if (!naam || !starts) {
            fout.textContent = 'Naam en startdatum zijn verplicht.';
            fout.hidden = false;
            return;
        }
        const btn = el('hp-hist-nw-opslaan');
        btn.disabled = true;
        try {
            const res = await fetch('api/helpers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'historie_create_comp',
                    naam, starts, ends, venue,
                    organisatie_id: orgId,
                    baan_id: baanId,
                }),
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            // Voeg toe aan cache en selecteer
            _hpHistComps.unshift({
                competition_id: data.competition_id,
                competition_naam: data.competition_naam,
                competition_datum: data.competition_datum,
                dcs: [],
            });
            // Herrender dropdown + selecteer nieuw
            const sel = el('hp-hist-comp');
            const dat = new Date(data.competition_datum).toLocaleDateString('nl-NL',
                { day:'2-digit', month:'short', year:'2-digit' });
            const opt = document.createElement('option');
            opt.value = data.competition_id;
            opt.textContent = `${data.competition_naam} (${dat}) — 0 DC's`;
            sel.insertBefore(opt, sel.options[1] || null);  // direct na placeholder
            sel.value = data.competition_id;
            _hpHistMaybeEnable();
            sluit();
        } catch (e) {
            fout.textContent = '⚠ ' + e.message;
            fout.hidden = false;
            btn.disabled = false;
        }
    });
}

function _hpHistCompChange() { _hpHistMaybeEnable(); }
function _hpHistTekstChange() { _hpHistMaybeEnable(); }
function _hpHistMaybeEnable() {
    const compOk = !!el('hp-hist-comp').value;
    const tekstOk = el('hp-hist-tekst').value.trim().length >= 50;
    el('hp-hist-btn-extract').disabled = !(compOk && tekstOk);
}

async function _hpHistExtract() {
    const btn  = el('hp-hist-btn-extract');
    const stat = el('hp-hist-status');
    const pdfText = el('hp-hist-tekst').value.trim();
    const compId  = el('hp-hist-comp').value;
    stat.textContent = 'AI analyseert tekst… (kan 10-30 sec duren)';
    stat.className = 'hp-status';
    btn.disabled = true;
    el('hp-hist-preview').style.display = 'none';
    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'historie_extract',
                pdf_text: pdfText,
                // Comp-id meegeven zodat backend het wedstrijd-jaar weet en
                // (cat + jaar) kan gebruiken voor plausibel geboortejaar-bereik.
                competition_id: compId,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        _hpHistResult = data;
        _hpHistRenderPreview(data);
        // Kosten van deze call + cumulatieve totaal (sinds 1e gebruik) tonen.
        // Totaal slaan we op in localStorage zodat het over page-refreshes
        // behouden blijft — handig om over een werk-sessie te zien wat de
        // batch in totaal heeft gekost.
        let kostenTxt = '';
        if (data.usage && data.usage.kosten_usd !== undefined) {
            const k = data.usage.kosten_usd;
            const totaalEerder = parseFloat(localStorage.getItem('hp-hist-kosten-totaal') || '0');
            const totaalNu     = totaalEerder + k;
            localStorage.setItem('hp-hist-kosten-totaal', String(totaalNu));
            kostenTxt = ` · 💰 $${k.toFixed(4)} (totaal $${totaalNu.toFixed(3)})`;
        }
        stat.textContent = `✓ ${data.rijders.length} rijders geëxtraheerd uit ${data.afstand_naam || '?'}${kostenTxt}`;
        stat.classList.add('hp-status-ok');
    } catch (e) {
        stat.textContent = '⚠ ' + e.message;
        stat.classList.add('hp-status-fout');
    } finally {
        btn.disabled = false;
    }
}

// Preview-tabel met per-row controls: DC-keuze (auto-detect op cat) + persoon-
// matching + check-box om rij te excluderen.
function _hpHistRenderPreview(data) {
    const wrap = el('hp-hist-preview');
    const compId = el('hp-hist-comp').value;
    const comp = _hpHistComps.find(w => w.competition_id === compId);
    if (!comp) { wrap.innerHTML = ''; return; }

    // Detecteer welke cats in PDF voorkomen maar geen DC hebben in deze
    // wedstrijd. category_filter is een komma-list ('DSA,DSJ' voor combo
    // DCs) — daarom splitsen we per cat.
    const aanwezigeCats = new Set();
    for (const d of comp.dcs) {
        if (!d.cat) continue;
        for (const c of d.cat.split(',').map(s => s.trim())) {
            if (c) aanwezigeCats.add(c);
        }
    }
    // Cat-options voor per-rij dropdown: alle cats die in deze wedstrijd's
    // DCs voorkomen (gesorteerd, dedup). Plus eventuele AI-detected cats
    // die nog geen DC hebben (anders kun je 'm later niet meer "terug-
    // zetten" naar de oorspronkelijke AI-keuze).
    const alleCatOpties = new Set(aanwezigeCats);
    for (const r of data.rijders) {
        if (r.categorie) alleCatOpties.add(r.categorie);
    }
    const catOptiesSorted = [...alleCatOpties].sort();
    const pdfCats = new Set(data.rijders.map(r => r.categorie).filter(Boolean));
    const missendeCats = [...pdfCats].filter(c => !aanwezigeCats.has(c)).sort();

    // Bij 1 missende cat → alleen "apart" knop (geen combineren mogelijk).
    // Bij 2 → twee knoppen: aparte DCs ÓF één combinatie-DC.
    // Bij 3+ → drie knoppen: aparte DCs / één combo / "aangepast" (modal
    // waar operator per cat een groep kan aanwijzen — bv. DJA solo,
    // DSA+DSJ samen = 2 DCs).
    let banner = '';
    if (missendeCats.length) {
        const catsJson = escHtml(JSON.stringify(missendeCats));
        const aparteKnop = `
            <button class="btn-secondary hp-hist-banner-knop"
                    id="hp-hist-btn-create-dcs-apart" data-cats="${catsJson}">
                ➕ ${missendeCats.length} aparte DC${missendeCats.length === 1 ? '' : "'s"}
            </button>`;
        const combiKnop = missendeCats.length >= 2 ? `
            <button class="${missendeCats.length === 2 ? 'btn-primary' : 'btn-secondary'} hp-hist-banner-knop"
                    id="hp-hist-btn-create-dcs-samen" data-cats="${catsJson}">
                ➕ 1 gecombineerde DC (${escHtml(missendeCats.join(' + '))})
            </button>` : '';
        const aangepastKnop = missendeCats.length >= 3 ? `
            <button class="btn-primary hp-hist-banner-knop"
                    id="hp-hist-btn-create-dcs-aangepast" data-cats="${catsJson}">
                ✏️ Aangepast…
            </button>` : '';
        // Altijd: knop voor handmatige DC met vrij in te voeren cats. Nuttig
        // voor PDF's die geen cat per rij geven (NK 2020 200m series: header
        // "DJA + Senioren" maar rijen tonen alleen nr + naam → AI markeert
        // alles als DJA → user heeft een extra DC nodig met cat-combo
        // 'DJA,DSA,DSJ' om als combo-klassement te tonen).
        const handmatigKnop = `
            <button class="btn-secondary hp-hist-banner-knop"
                    id="hp-hist-btn-create-dc-handmatig">
                ✋ Handmatig DC…
            </button>`;
        banner = `
            <div class="hp-hist-banner-missing">
                <span>⚠ Geen DC in deze wedstrijd voor: <b>${missendeCats.map(escHtml).join(', ')}</b></span>
                <div class="hp-hist-banner-knoppen">
                    ${aparteKnop}
                    ${combiKnop}
                    ${aangepastKnop}
                    ${handmatigKnop}
                </div>
            </div>`;
    } else {
        // Geen missende cats, maar operator wil mogelijk tóch een extra DC
        // aanmaken (bv. subset-klassement of combo dat AI niet detecteerde).
        banner = `
            <div class="hp-hist-banner-missing hp-hist-banner-optioneel">
                <span>Alle gedetecteerde cats hebben al een DC. Toch nog eentje toevoegen?</span>
                <div class="hp-hist-banner-knoppen">
                    <button class="btn-secondary hp-hist-banner-knop"
                            id="hp-hist-btn-create-dc-handmatig">
                        ✋ Handmatig DC…
                    </button>
                </div>
            </div>`;
    }

    // DC-dropdown options per rij — alleen DCs tonen die qua category_filter
    // bij de rijder z'n cat passen (combo-DC "HSA,HSJ" matcht een HSA én een
    // HSJ rijder). Houdt de dropdown overzichtelijk: 1-2 keuzes ipv álle 8
    // DCs van de wedstrijd. Edge cases:
    //   - Geen enkele DC matcht deze cat → fallback alle DCs (zodat operator
    //     hand-pick kan doen, bv. voor een onverwachte cat).
    //   - selectedDcId past niet in filter → toch toevoegen, anders raakt
    //     een eerder bewuste keuze verloren.
    const dcOpts = (selectedDcId, rijderCat) => {
        let pool = comp.dcs;
        if (rijderCat) {
            const matching = comp.dcs.filter(d => {
                if (!d.cat) return false;
                return d.cat.split(',').map(s => s.trim()).includes(rijderCat);
            });
            if (matching.length) {
                pool = matching;
                // Behoud bestaande keuze als die niet (meer) in filter zit
                if (selectedDcId && !pool.some(d => d.dc_id === selectedDcId)) {
                    const extra = comp.dcs.find(d => d.dc_id === selectedDcId);
                    if (extra) pool = [...pool, extra];
                }
            }
        }
        const opts = ['<option value="">— kies DC —</option>'];
        for (const d of pool) {
            const sel = d.dc_id === selectedDcId ? ' selected' : '';
            const catTag = d.cat ? ` [${escHtml(d.cat)}]` : '';
            opts.push(`<option value="${escHtml(d.dc_id)}"${sel}>${escHtml(d.dc_naam)}${catTag}</option>`);
        }
        return opts.join('');
    };

    // Rijen voorbereiden — bepaal default DC + status-icoon. Bij ambigu of
    // birth_year-warning krijgt de operator een dropdown met kandidaten
    // zodat hij de juiste persoon kan kiezen (cruciaal want PDFs zonder
    // licentie kunnen naam-collisions hebben). DC-match: category_filter
    // kan komma-list zijn ('DSA,DSJ') voor gecombineerde DCs — split en
    // check of de rijder z'n cat in de set zit.
    const dcMatchVoorCat = (cat) => comp.dcs.find(d => {
        if (!d.cat) return false;
        const cats = d.cat.split(',').map(s => s.trim());
        return cats.includes(cat);
    });
    const rijen = data.rijders.map((r, i) => {
        const dc = dcMatchVoorCat(r.categorie);
        const dcId = dc ? dc.dc_id : '';
        const matchOk = !!r.person_license;
        const heeftWaarschuwing = !!r.match_warning;

        // Bouw label voor de match-status — toont WIE er gematcht is
        // (full_name + birth_year + cat) zodat operator visueel kan checken.
        let statusHtml;
        let statusCls;
        if (r.match_reden === 'ambigu') {
            statusCls = 'hp-hist-st-warn';
            statusHtml = '⚠ kies persoon ↓';
        } else if (r.match_reden === 'fuzzy-suggesties') {
            statusCls = 'hp-hist-st-warn';
            statusHtml = '💡 lijkt op… ↓';
        } else if (!matchOk) {
            statusCls = 'hp-hist-st-err';
            statusHtml = '✗ onbekend';
        } else {
            statusCls = heeftWaarschuwing ? 'hp-hist-st-warn' : 'hp-hist-st-ok';
            const icon = heeftWaarschuwing ? '⚠' : '✓';
            const p = r.match_person || {};
            const jaar = p.birth_year ? ` ${p.birth_year}` : '';
            const cat  = p.category   ? ` ${p.category}`   : '';
            statusHtml = `${icon} ${escHtml(p.full_name || '?')}<small>${escHtml(jaar + cat)}</small>`;
            if (heeftWaarschuwing) {
                statusHtml += `<small class="hp-hist-warn-txt">${escHtml(r.match_warning)}</small>`;
            }
        }

        // Default checked: alle rijen met geldige match+DC, mismatch uit.
        // Ambigu = niet vooraf aangevinkt — operator moet eerst kiezen.
        const checked = (matchOk && dcId && !heeftWaarschuwing) ? 'checked' : '';
        return { r, i, dcId, statusHtml, statusCls, checked };
    });

    // Tijd-formatter (ms → mm:ss.mmm)
    const fmtTijd = ms => {
        if (ms == null) return '';
        const m = Math.floor(ms / 60000), s = Math.floor((ms % 60000) / 1000), d = ms % 1000;
        return m > 0
            ? `${m}:${String(s).padStart(2,'0')}.${String(d).padStart(3,'0')}`
            : `${s}.${String(d).padStart(3,'0')}`;
    };

    // Detecteer of AI de afstand / race-type kon bepalen. Zo niet:
    // banner met inline form waar operator zelf moet aanvullen voordat
    // er ingevoegd kan worden. Voorkomt "Onbekend"-import.
    const geldigeRaceTypes = ['sprint', 'inline', 'puntenkoers', 'afvalkoers'];
    const afstandOnbekend = !data.afstand_naam
        || /^(onbekend|unknown|\?|—|-)$/i.test(String(data.afstand_naam).trim());
    const raceTypeOnbekend = !data.race_type
        || !geldigeRaceTypes.includes(String(data.race_type).toLowerCase());
    const moetAanvullen = afstandOnbekend || raceTypeOnbekend;

    const aanvulBanner = moetAanvullen ? `
        <div class="hp-hist-banner-aanvul">
            <div class="hp-hist-banner-titel">
                ⚠ AI kon niet alles uit de PDF halen — vul de ontbrekende velden aan:
            </div>
            <div class="hp-hist-banner-rij">
                <label>Afstand-naam
                    <input type="text" class="inp" id="hp-hist-aanvul-naam"
                           value="${escHtml(afstandOnbekend ? '' : data.afstand_naam)}"
                           placeholder="bv. 200m, 1000m, 5000m punten">
                </label>
                <label>Meters
                    <input type="number" class="inp" id="hp-hist-aanvul-meters"
                           value="${data.afstand_meters ?? ''}" min="50" max="50000">
                </label>
                <label>Race-type
                    <select class="inp" id="hp-hist-aanvul-rt">
                        <option value="">— kies —</option>
                        <option value="sprint" ${data.race_type === 'sprint' ? 'selected' : ''}>sprint</option>
                        <option value="inline" ${data.race_type === 'inline' ? 'selected' : ''}>inline</option>
                        <option value="puntenkoers" ${data.race_type === 'puntenkoers' ? 'selected' : ''}>puntenkoers</option>
                        <option value="afvalkoers" ${data.race_type === 'afvalkoers' ? 'selected' : ''}>afvalkoers</option>
                    </select>
                </label>
            </div>
        </div>` : '';

    const head = `
        <div class="hp-hist-preview-kop">
            <b>${escHtml(data.afstand_naam || '— afstand onbekend —')}</b>
            ${data.afstand_meters ? ` (${data.afstand_meters}m)` : ''}
            ${data.race_type && geldigeRaceTypes.includes(data.race_type)
                ? ` · ${escHtml(data.race_type)}` : ''}
            · ${rijen.length} rijders
        </div>
        <div class="hp-hist-uitleg">
            Per rij heb je 3 keuzes:
            <b>✓ aanvinken + persoon kiezen</b> → gekoppeld aan KNSB-account ·
            <b>✓ aanvinken + ⚡ pending laten</b> → uitslag bewaard onder
            placeholder die je later kunt koppelen ·
            <b>✗ uitvinken</b> → rij wordt niet geïmporteerd
        </div>`;

    // Per-rij persoon-picker: bij ambigu (en als operator wil corrigeren bij
    // single match) een dropdown met alle kandidaten + "Andere persoon (skip)".
    // value = license_key. data-default-lic = oorspronkelijke server-keuze.
    const persoonPicker = (r) => {
        // Welke kandidaten? Bij ambigu de lijst, anders alleen de huidige match.
        const kandidaten = r.match_kandidaten
            ? r.match_kandidaten.slice()
            : (r.match_person ? [r.match_person] : []);
        if (!kandidaten.length) return '';
        // Default-optie = pending aanmaken. Vroeger heette deze 'skip/handmatig'
        // maar sinds de pending-feature wordt elke lege-license rij omgezet in
        // een pending-rijder. Echt skippen doe je door de rij-checkbox uit te
        // vinken (kolom helemaal links).
        const opts = ['<option value="">— ⚡ pending aanmaken —</option>'];
        for (const k of kandidaten) {
            const sel = k.license_key === r.person_license ? ' selected' : '';
            const extra = [k.birth_year, k.category, k.club_short].filter(Boolean).join(' · ');
            const lbl = `${k.full_name}${extra ? ' (' + extra + ')' : ''}`;
            opts.push(`<option value="${escHtml(k.license_key)}"${sel}>${escHtml(lbl)}</option>`);
        }
        return `<select class="inp hp-hist-pers-sel"
                        data-default-lic="${escHtml(r.person_license || '')}">${opts.join('')}</select>`;
    };

    // Bulk-acties: zet cat of DC ineens voor alle aangevinkte rijen. Bespaart
    // bij combo-PDFs (HJA + HSA samen, AI markeert alles HJA) tig clicks:
    // vink de 12 HSA-rijders aan → kies HSA in bulk → toepassen.
    // DC-dropdown toont ALLE DCs van de wedstrijd (geen cat-filter), zodat
    // operator ook een DC kan kiezen die qua filter niet bij de huidige cat
    // matcht — handig om in 1 klik alle rijden naar de combo-DC te sturen.
    const bulkCatOpts = catOptiesSorted.map(c =>
        `<option value="${escHtml(c)}">${escHtml(c)}</option>`).join('');
    const bulkDcOpts = comp.dcs.map(d => {
        const catTag = d.cat ? ` [${escHtml(d.cat)}]` : '';
        return `<option value="${escHtml(d.dc_id)}">${escHtml(d.dc_naam)}${catTag}</option>`;
    }).join('');
    const bulkBar = `
        <div class="hp-hist-bulk">
            <span class="hp-hist-bulk-lbl">Bulk op aangevinkte rijen:</span>
            <span class="hp-hist-bulk-groep">
                Cat
                <select class="inp" id="hp-hist-bulk-cat-sel">${bulkCatOpts}</select>
                <button class="btn-secondary hp-hist-bulk-knop" id="hp-hist-bulk-cat-knop">Toepassen</button>
            </span>
            <span class="hp-hist-bulk-groep">
                DC
                <select class="inp" id="hp-hist-bulk-dc-sel">${bulkDcOpts}</select>
                <button class="btn-secondary hp-hist-bulk-knop" id="hp-hist-bulk-dc-knop">Toepassen</button>
            </span>
            <span class="hp-status" id="hp-hist-bulk-status"></span>
        </div>`;

    const tabel = bulkBar + `
        <table class="hp-hist-tabel">
            <thead><tr>
                <th><input type="checkbox" id="hp-hist-checkall" checked></th>
                <th>Rang</th><th>Snr</th><th>Naam (PDF)</th><th>Cat</th>
                <th>DC (klassement)</th>
                <th>Tijd</th><th>Sanc</th><th>Match (DB)</th>
            </tr></thead>
            <tbody>
                ${rijen.map(({r, i, dcId, statusHtml, statusCls, checked}) => {
                    // Bij ambigu / warning / fuzzy-suggesties tonen we de
                    // picker ipv plain status, zodat operator direct kan
                    // kiezen welke persoon hier hoort.
                    const moetKiezen = r.match_reden === 'ambigu'
                                    || r.match_reden === 'fuzzy-suggesties'
                                    || r.match_warning;
                    const matchCel = moetKiezen
                        ? persoonPicker(r)
                        : `<span class="${statusCls}">${statusHtml}</span>`;
                    return `
                    <tr data-i="${i}" class="${checked ? '' : 'hp-hist-rij-uit'}">
                        <td><input type="checkbox" class="hp-hist-cb" ${checked}></td>
                        <td class="hp-hist-rang-cel" data-orig="${r.rang ?? ''}">${r.rang ?? '—'}</td>
                        <td>${r.startnummer != null ? escHtml(r.startnummer) : ''}</td>
                        <td>${escHtml(r.naam || '')}</td>
                        <td><select class="inp hp-hist-cat-sel"
                                    title="Overschrijf cat als AI 'm verkeerd raadde (bv. bij combo-PDF's: zet rijders die feitelijk HSA waren over)">
                            ${catOptiesSorted.map(c =>
                                `<option value="${escHtml(c)}"${c === (r.categorie || '') ? ' selected' : ''}>${escHtml(c)}</option>`
                            ).join('')}
                        </select></td>
                        <td><select class="inp hp-hist-dc-sel">${dcOpts(dcId, r.categorie)}</select></td>
                        <td>${escHtml(fmtTijd(r.tijd_ms))}</td>
                        <td>${escHtml(r.sanctie || '')}</td>
                        <td>${matchCel}</td>
                    </tr>`;
                }).join('')}
            </tbody>
        </table>`;

    // Importeer-knop disabled als afstand/race-type nog niet ingevuld zijn.
    // Wordt enabled door _hpHistAanvulCheck() zodra alle velden gevuld zijn.
    // Vervang-checkbox: standaard UIT = veilig (INSERT IGNORE, bestaande blijft).
    // AAN = ON DUPLICATE KEY UPDATE: overschrijft rang/tijd/sanctie van bestaande
    // rijen (zelfde comp+DC+dist+split+persoon). Gebruik dit voor herstel van
    // een botched import.
    const acties = `
        <div class="hp-card-acties" style="margin-top:10px">
            <button class="btn-primary" id="hp-hist-btn-insert" ${moetAanvullen ? 'disabled' : ''}>
                📥 Importeer geselecteerde rijen
            </button>
            <label class="hp-hist-vervang-lbl" title="AAN: bestaande rijen worden overschreven met de nieuwe waardes (rang, tijd, sanctie). UIT: bestaande rijen blijven onaangetast, alleen nieuwe worden toegevoegd.">
                <input type="checkbox" id="hp-hist-vervang">
                Bestaande rijen overschrijven
            </label>
            <span class="hp-status" id="hp-hist-insert-status">
                ${moetAanvullen ? '⚠ Vul eerst de ontbrekende afstand-info aan.' : ''}
            </span>
        </div>`;

    wrap.innerHTML = aanvulBanner + banner + head + tabel + acties;
    wrap.style.display = '';

    // Check-all toggle
    el('hp-hist-checkall').addEventListener('change', e => {
        const on = e.target.checked;
        wrap.querySelectorAll('.hp-hist-cb').forEach(cb => {
            cb.checked = on;
            cb.closest('tr').classList.toggle('hp-hist-rij-uit', !on);
        });
    });
    // Live rang-preview: bereken per DC welke rang elke rij KRIJGT na de
    // subset-recalc (zelfde logica als _hpHistInsert). Toont in de Rang-cel:
    //   - originele PDF-rang als die niet verandert
    //   - "5 → 1" als de subset-recalc 'em wijzigt
    // Trigger na elke cat- of DC-wijziging zodat operator weet wat 'em straks
    // krijgt zonder eerst te importeren en in de printout te controleren.
    const updateRangPreview = () => {
        // Per DC die in gebruik is: map(rij-INDEX → nieuwe rang).
        // Index-based ipv object-reference: robuuster, geen gedoe met
        // Map-identity bij filter/sort.
        const dcRangMap = {};   // dcId → { rijIndex: nieuwerang }
        const trList = wrap.querySelectorAll('tbody tr');
        const dcsInGebruik = new Set();
        trList.forEach(tr => {
            const dcSel = tr.querySelector('.hp-hist-dc-sel');
            if (dcSel && dcSel.value) dcsInGebruik.add(dcSel.value);
        });
        for (const dcId of dcsInGebruik) {
            const dc = comp.dcs.find(d => d.dc_id === dcId);
            if (!dc || !dc.cat) continue;
            const dcCats = dc.cat.split(',').map(s => s.trim().toUpperCase()).filter(Boolean);
            // Combo-DC (cat_filter heeft >1 cats) → BEHOUD originele PDF-rang.
            // Subset-recalc gebeurt alleen bij single-cat-DC (bv DJA-only uit
            // een combo-race). Voor combo-DCs is de PDF-rang per definitie de
            // juiste combo-rang.
            if (dcCats.length !== 1) continue;
            // Verzamel {idx, rang} voor alle PDF-rijders met cat in deze DC.
            // Number(r.rang) gehard zodat sort numeriek werkt (rang kan string
            // zijn uit JSON-extract).
            const subset = [];
            _hpHistResult.rijders.forEach((r, idx) => {
                if (!r.categorie || r.rang == null) return;
                const cat = String(r.categorie).trim().toUpperCase();
                if (!dcCats.includes(cat)) return;
                subset.push({ idx, rang: Number(r.rang) });
            });
            subset.sort((a, b) => a.rang - b.rang);
            // Dense ranking met ex-aequo behoud
            const map = {};
            let nieuweRang = 0, vorigeOud = -Infinity;
            for (let i = 0; i < subset.length; i++) {
                if (subset[i].rang !== vorigeOud) nieuweRang = i + 1;
                map[subset[i].idx] = nieuweRang;
                vorigeOud = subset[i].rang;
            }
            dcRangMap[dcId] = map;
        }
        // DOM updaten — voor elke rij: lookup via index
        trList.forEach(tr => {
            const i = parseInt(tr.dataset.i);
            const rij = _hpHistResult.rijders[i];
            const rangCel = tr.querySelector('.hp-hist-rang-cel');
            if (!rangCel) return;
            const origRang = rij.rang != null ? rij.rang : '—';
            const dcSel = tr.querySelector('.hp-hist-dc-sel');
            const dcId = dcSel?.value;
            const map = dcId ? dcRangMap[dcId] : null;
            const nieuw = map ? map[i] : undefined;
            if (nieuw === undefined || Number(nieuw) === Number(rij.rang)) {
                rangCel.textContent = origRang;
            } else {
                rangCel.innerHTML =
                    `<span class="hp-hist-rang-orig">${origRang}</span> → ` +
                    `<b class="hp-hist-rang-nieuw">${nieuw}</b>`;
            }
        });
    };

    // Bulk-acties: pas cat of DC ineens toe op alle aangevinkte rijen.
    // Cat-bulk: triggert per-rij change-event zodat de DC-dropdown ook
    // her-filtert (existing logic). DC-bulk: zet direct + voeg de DC als
    // optie toe als de rij's cat-filter 'em zou uitsluiten.
    const bulkFlash = (tekst) => {
        const s = el('hp-hist-bulk-status');
        if (!s) return;
        s.textContent = tekst;
        s.className = 'hp-status hp-status-ok';
        clearTimeout(s._t);
        s._t = setTimeout(() => { s.textContent = ''; s.className = 'hp-status'; }, 2500);
    };
    el('hp-hist-bulk-cat-knop')?.addEventListener('click', () => {
        const nieuweCat = el('hp-hist-bulk-cat-sel').value;
        if (!nieuweCat) return;
        let count = 0;
        wrap.querySelectorAll('.hp-hist-cb:checked').forEach(cb => {
            const tr = cb.closest('tr');
            const sel = tr.querySelector('.hp-hist-cat-sel');
            if (sel && sel.value !== nieuweCat) {
                sel.value = nieuweCat;
                sel.dispatchEvent(new Event('change'));
                count++;
            }
        });
        bulkFlash(count
            ? `✓ ${count} rij(en) bijgewerkt naar cat ${nieuweCat}`
            : '⚠ Geen aangevinkte rijen (of allemaal al deze cat)');
        updateRangPreview();
    });
    el('hp-hist-bulk-dc-knop')?.addEventListener('click', () => {
        const nieuweDcId = el('hp-hist-bulk-dc-sel').value;
        if (!nieuweDcId) return;
        const dc = comp.dcs.find(d => d.dc_id === nieuweDcId);
        let count = 0;
        wrap.querySelectorAll('.hp-hist-cb:checked').forEach(cb => {
            const tr = cb.closest('tr');
            const sel = tr.querySelector('.hp-hist-dc-sel');
            if (!sel) return;
            // Optie toevoegen als 'em ontbreekt (cat-filter excludeert dc)
            if (![...sel.options].some(o => o.value === nieuweDcId) && dc) {
                const opt = document.createElement('option');
                opt.value = dc.dc_id;
                opt.textContent = `${dc.dc_naam}${dc.cat ? ' [' + dc.cat + ']' : ''}`;
                sel.appendChild(opt);
            }
            if (sel.value !== nieuweDcId) {
                sel.value = nieuweDcId;
                count++;
            }
        });
        bulkFlash(count
            ? `✓ ${count} rij(en) verplaatst naar ${dc?.dc_naam || nieuweDcId}`
            : '⚠ Geen aangevinkte rijen (of allemaal al deze DC)');
        updateRangPreview();
    });

    // Per-rij cat-dropdown: bij wijziging de cat in _hpHistResult bijwerken
    // EN de DC-dropdown opnieuw filteren (een HSA-rijder moet niet meer in
    // een HJA-solo DC kunnen). Plus rang-preview updaten.
    wrap.querySelectorAll('.hp-hist-cat-sel').forEach(sel => {
        sel.addEventListener('change', () => {
            const tr = sel.closest('tr');
            const i  = parseInt(tr.dataset.i);
            const nieuweCat = sel.value;
            _hpHistResult.rijders[i].categorie = nieuweCat;
            // Her-bouw DC-dropdown met nieuwe cat → matches kunnen wijzigen
            const dcSel = tr.querySelector('.hp-hist-dc-sel');
            const huidigeDcId = dcSel ? dcSel.value : '';
            if (dcSel) dcSel.innerHTML = dcOpts(huidigeDcId, nieuweCat);
            updateRangPreview();
        });
    });
    // Per-rij DC-dropdown: bij wijziging rang-preview updaten zodat operator
    // direct ziet wat de subset-rang wordt in de nieuwe DC.
    wrap.querySelectorAll('.hp-hist-dc-sel').forEach(sel => {
        sel.addEventListener('change', updateRangPreview);
    });
    // Initial preview: zodra de tabel gerenderd is, alvast tonen of er
    // rangen veranderen door de DC-keuze (default DC = AI-suggestie).
    updateRangPreview();
    // Per-rij checkbox togglet rij-styling
    wrap.querySelectorAll('.hp-hist-cb').forEach(cb => {
        cb.addEventListener('change', e =>
            e.target.closest('tr').classList.toggle('hp-hist-rij-uit', !e.target.checked)
        );
    });
    el('hp-hist-btn-insert').addEventListener('click', _hpHistInsert);
    // Banner-knoppen — twee varianten: aparte DCs of één gecombineerde DC.
    el('hp-hist-btn-create-dcs-apart')?.addEventListener('click', ev =>
        _hpHistMaakDcs(ev, 'apart'));
    el('hp-hist-btn-create-dcs-samen')?.addEventListener('click', ev =>
        _hpHistMaakDcs(ev, 'samen'));
    el('hp-hist-btn-create-dcs-aangepast')?.addEventListener('click', ev =>
        _hpHistAangepasteGroepenModal(JSON.parse(ev.currentTarget.dataset.cats)));
    // Handmatige DC: voor PDF's zonder cat-info per rij (combo-headers waar AI
    // alle rijders één cat toekent) → operator typt zelf de gewenste cat-list.
    el('hp-hist-btn-create-dc-handmatig')?.addEventListener('click',
        _hpHistHandmatigeDcModal);
    // Aanvul-banner (alleen aanwezig als AI iets niet kon bepalen):
    // sync velden → _hpHistResult, en enable de Importeer-knop pas zodra
    // alle 3 de velden geldig zijn.
    const aanvulNaam   = el('hp-hist-aanvul-naam');
    const aanvulMeters = el('hp-hist-aanvul-meters');
    const aanvulRt     = el('hp-hist-aanvul-rt');
    if (aanvulNaam) {
        const sync = () => {
            const naam   = aanvulNaam.value.trim();
            const meters = parseInt(aanvulMeters.value, 10);
            const rt     = aanvulRt.value;
            _hpHistResult.afstand_naam   = naam;
            _hpHistResult.afstand_meters = Number.isFinite(meters) ? meters : null;
            _hpHistResult.race_type      = rt;
            const ok = naam.length > 0 && rt.length > 0;
            const btn  = el('hp-hist-btn-insert');
            const stat = el('hp-hist-insert-status');
            btn.disabled = !ok;
            stat.textContent = ok ? '' : '⚠ Vul eerst de ontbrekende afstand-info aan.';
        };
        aanvulNaam.addEventListener('input', sync);
        aanvulMeters.addEventListener('input', sync);
        aanvulRt.addEventListener('change', sync);
    }
    // Persoon-picker (alleen aanwezig bij ambigu/warning rijen) — bij wissel
    // automatisch checkbox aanvinken zodat operator niet vergeet de rij te
    // includeren. Bij "skip" (lege value): checkbox uit.
    wrap.querySelectorAll('.hp-hist-pers-sel').forEach(sel => {
        sel.addEventListener('change', e => {
            const tr = e.target.closest('tr');
            const cb = tr.querySelector('.hp-hist-cb');
            const heeftKeuze = !!e.target.value;
            cb.checked = heeftKeuze;
            tr.classList.toggle('hp-hist-rij-uit', !heeftKeuze);
        });
    });
}

// One-click DC-aanmaak voor de cats die in de PDF voorkomen maar nog geen
// DC in de wedstrijd hebben. Na succes: refetch comp-DCs en re-render preview
// zodat de nieuwe DCs auto-geselecteerd staan.
//
// modus:
//   'apart' → één DC per cat (default voor jonge cats die altijd apart racen)
//   'samen' → één DC voor ALLE cats gecombineerd (DSA+DSJ kleinere wedstrijd)
async function _hpHistMaakDcs(ev, modus) {
    const btn = ev.currentTarget;
    const cats = JSON.parse(btn.dataset.cats);
    const compId = el('hp-hist-comp').value;
    btn.disabled = true;
    btn.textContent = 'Bezig…';
    try {
        // 'apart' → groepen = [[cat1], [cat2], ...]
        // 'samen' → groepen = [[cat1, cat2, ...]]  (één combo)
        const groepen = modus === 'samen'
            ? [cats]
            : cats.map(c => [c]);
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'historie_create_dcs',
                competition_id: compId,
                groepen,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        // Voeg de nieuwe DCs toe aan de lokale cache + her-render
        const comp = _hpHistComps.find(w => w.competition_id === compId);
        if (comp) comp.dcs.push(...data.aangemaakt);
        // Update ook het dropdown-label (X DC's → X+N DC's)
        const sel = el('hp-hist-comp');
        const opt = Array.from(sel.options).find(o => o.value === compId);
        if (opt && comp) {
            const dat = new Date(comp.competition_datum).toLocaleDateString('nl-NL',
                { day:'2-digit', month:'short', year:'2-digit' });
            opt.textContent = `${comp.competition_naam} (${dat}) — ${comp.dcs.length} DC's`;
        }
        _hpHistRenderPreview(_hpHistResult);
    } catch (e) {
        btn.disabled = false;
        btn.textContent = '⚠ ' + e.message;
    }
}

// Aangepaste DC-groepering: bij 3+ missende cats wil operator soms een
// tussenvariant, bv. "DJA solo + DSA/DSJ samen = 2 DCs". Modal toont per
// cat een groepsnummer-keuze; cats met zelfde nummer komen in 1 DC.
function _hpHistAangepasteGroepenModal(cats) {
    const compId = el('hp-hist-comp').value;
    if (!compId) return;
    // Default: elk in eigen groep (= zelfde gedrag als 'apart')
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    const rijenHtml = cats.map((cat, i) => `
        <div class="hp-hist-groep-rij">
            <span class="hp-hist-groep-cat">${escHtml(cat)}</span>
            <span class="hp-hist-groep-label">in groep(en):</span>
            <input type="text" class="modal-input hp-hist-groep-nr"
                   data-cat="${escHtml(cat)}"
                   value="${i + 1}" placeholder="bv. 1 of 1,2"
                   style="width:90px">
        </div>
    `).join('');
    overlay.innerHTML = `
        <div class="modal-dialog hp-hist-modal" role="dialog">
            <div class="modal-header">
                <span class="modal-icon">✏️</span>
                <span>Aangepaste DC-groepering</span>
            </div>
            <div class="modal-body">
                <p class="hp-hist-modal-uitleg">
                    Geef per cat één of meerdere groepsnummers (komma-gescheiden).
                    Cats met hetzelfde nummer komen samen in één DC. Een cat in
                    <b>meerdere</b> groepen → komt in meerdere DCs (bv. dubbel
                    klassement zoals NK 2022). Voorbeelden:<br>
                    • DJA=<b>1</b>, DSA=<b>2</b>, DSJ=<b>2</b> → 2 DCs: DJA solo + DSA+DSJ samen<br>
                    • DJA=<b>1,2</b>, DSA=<b>1</b>, DSJ=<b>1</b> → 2 DCs: DJA+DSA+DSJ samen + DJA solo
                </p>
                <p class="hp-hist-modal-uitleg" style="background:#fff4d6;border:1px solid #f0c674;padding:8px 10px;border-radius:5px;color:#6e4d00">
                    <b>Multi-DC import-tip:</b> als je een cat in meerdere DCs
                    zet, moet je per DC apart importeren — analyseer de PDF
                    nogmaals en kies in de DC-dropdown handmatig de tweede
                    DC voor die rijders. Beide klassementen worden dan los
                    opgeslagen.
                </p>
                <div class="hp-hist-groep-lijst">${rijenHtml}</div>
                <div class="hp-hist-groep-preview" id="hp-hist-groep-preview"></div>
            </div>
            <div class="modal-knoppen">
                <button class="modal-btn modal-annuleer" id="hp-hist-groep-annuleer">Annuleer</button>
                <button class="modal-btn modal-doorgaan" id="hp-hist-groep-ok">DCs aanmaken</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    el('hp-hist-groep-annuleer').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });

    // Live preview: bouw groepen uit ingevulde nummers + toon ze
    const renderPreview = () => {
        const groepen = _hpHistGroepenUitInput(overlay);
        const preview = el('hp-hist-groep-preview');
        if (!groepen.length) {
            preview.innerHTML = '<em>Vul eerst groepsnummers in.</em>';
            return;
        }
        preview.innerHTML = '<b>Wordt aangemaakt:</b> ' + groepen
            .map(g => `<span class="hp-hist-groep-chip">${escHtml(g.join(' + '))}</span>`)
            .join(' ');
    };
    overlay.querySelectorAll('.hp-hist-groep-nr').forEach(inp => {
        inp.addEventListener('input', renderPreview);
    });
    renderPreview();

    el('hp-hist-groep-ok').addEventListener('click', async () => {
        const groepen = _hpHistGroepenUitInput(overlay);
        if (!groepen.length) return;
        const btn = el('hp-hist-groep-ok');
        btn.disabled = true; btn.textContent = 'Bezig…';
        try {
            const res = await fetch('api/helpers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'historie_create_dcs',
                    competition_id: compId,
                    groepen,
                }),
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            const comp = _hpHistComps.find(w => w.competition_id === compId);
            if (comp) comp.dcs.push(...data.aangemaakt);
            const sel = el('hp-hist-comp');
            const opt = Array.from(sel.options).find(o => o.value === compId);
            if (opt && comp) {
                const dat = new Date(comp.competition_datum).toLocaleDateString('nl-NL',
                    { day:'2-digit', month:'short', year:'2-digit' });
                opt.textContent = `${comp.competition_naam} (${dat}) — ${comp.dcs.length} DC's`;
            }
            sluit();
            _hpHistRenderPreview(_hpHistResult);
        } catch (e) {
            btn.disabled = false;
            btn.textContent = '⚠ ' + e.message;
        }
    });
}

// Verzamel cats per groepsnummer uit de modal-input. Een cat kan in
// MEERDERE groepen zitten (input "1,2" → cat in groep 1 én groep 2).
// Use case: NK 2022 had DJA + DSA + DSJ samen in 1 race, maar 2
// klassementen (DJA-rijders kwamen in beide DCs voor).
// Returns: [[cat,cat], [cat], ...] gesorteerd op groep-nr.
function _hpHistGroepenUitInput(overlay) {
    const perNr = new Map();   // nr → [cat,cat,...]
    overlay.querySelectorAll('.hp-hist-groep-nr').forEach(inp => {
        const cat = inp.dataset.cat;
        // Parse comma-list: "1,2" → [1, 2], "  3 " → [3], "" → []
        const nrs = String(inp.value)
            .split(',')
            .map(s => parseInt(s.trim()))
            .filter(n => Number.isFinite(n) && n >= 1);
        for (const nr of nrs) {
            if (!perNr.has(nr)) perNr.set(nr, []);
            if (!perNr.get(nr).includes(cat)) perNr.get(nr).push(cat);
        }
    });
    return [...perNr.entries()]
        .sort((a, b) => a[0] - b[0])
        .map(([, cats]) => cats);
}

// Handmatige DC-aanmaak: voor PDF's zonder cat per rij waar AI niet weet
// welke cats er waren. Operator typt zelf de cat-list, optioneel een naam,
// en wij sturen 'em direct naar historie_create_dcs (zelfde endpoint dat
// de andere DC-knoppen ook gebruiken). Een DC tegelijk; klik 'em meerdere
// keren als je meer wilt.
function _hpHistHandmatigeDcModal() {
    const compId = el('hp-hist-comp').value;
    if (!compId) return;
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal-dialog hp-hist-modal" role="dialog">
            <div class="modal-header">
                <span class="modal-icon">✋</span>
                <span>Handmatig DC aanmaken</span>
            </div>
            <div class="modal-body">
                <p class="hp-hist-modal-uitleg">
                    Maak een DC met cat(s) die je zelf invoert. Nuttig bij PDF's
                    die geen cat per rij geven (bv. NK 2020 "Junioren A + Senioren":
                    AI markeert alle rijders DJA → om de combo-uitslag te kunnen
                    importeren maak je hier een DC <b>DJA + DSA + DSJ</b> aan.
                    Daarna kies je in de preview-tabel per rij welke DC.
                </p>
                <label class="hp-hist-veld">
                    <span>Cat(s) — komma-gescheiden</span>
                    <input type="text" class="modal-input inp" id="hp-hist-hdc-cats"
                           placeholder="bv. DJA,DSA,DSJ"
                           autocomplete="off" autofocus>
                </label>
                <label class="hp-hist-veld">
                    <span>DC-naam (optioneel — leeg = auto)</span>
                    <input type="text" class="modal-input inp" id="hp-hist-hdc-naam"
                           placeholder="leeg = automatisch op basis van cats"
                           autocomplete="off">
                </label>
                <div class="hp-hist-groep-preview" id="hp-hist-hdc-preview"></div>
            </div>
            <div class="modal-knoppen">
                <button class="modal-btn modal-annuleer" id="hp-hist-hdc-annuleer">Annuleer</button>
                <button class="modal-btn modal-doorgaan" id="hp-hist-hdc-ok" disabled>DC aanmaken</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    el('hp-hist-hdc-annuleer').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });

    const catsInp = el('hp-hist-hdc-cats');
    const okBtn   = el('hp-hist-hdc-ok');
    const preview = el('hp-hist-hdc-preview');

    // Parse cat-string → schoonmaak (uppercase, trim, dedup, geldige tekens).
    const parseCats = () => String(catsInp.value)
        .toUpperCase()
        .split(',')
        .map(s => s.replace(/[^A-Z0-9]/g, '').trim())
        .filter((c, i, arr) => c && arr.indexOf(c) === i);

    const updatePreview = () => {
        const cats = parseCats();
        if (!cats.length) {
            preview.innerHTML = '<em>Vul minstens één cat in.</em>';
            okBtn.disabled = true;
            return;
        }
        preview.innerHTML = '<b>Wordt aangemaakt:</b> '
            + `<span class="hp-hist-groep-chip">${escHtml(cats.join(' + '))}</span>`;
        okBtn.disabled = false;
    };
    catsInp.addEventListener('input', updatePreview);
    updatePreview();
    catsInp.focus();

    el('hp-hist-hdc-ok').addEventListener('click', async () => {
        const cats = parseCats();
        if (!cats.length) return;
        okBtn.disabled = true;
        okBtn.textContent = 'Bezig…';
        try {
            const body = {
                action: 'historie_create_dcs',
                competition_id: compId,
                // Eén groep met alle ingevoerde cats → 1 DC
                groepen: [cats],
            };
            // Optionele eigen naam meesturen — backend gebruikt 'em als gegeven
            const naam = el('hp-hist-hdc-naam').value.trim();
            if (naam) body.namen = [naam];
            const res = await fetch('api/helpers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            // Voeg toe aan lokale cache + her-render preview-tabel
            const comp = _hpHistComps.find(w => w.competition_id === compId);
            if (comp) comp.dcs.push(...data.aangemaakt);
            const sel = el('hp-hist-comp');
            const opt = Array.from(sel.options).find(o => o.value === compId);
            if (opt && comp) {
                const dat = new Date(comp.competition_datum).toLocaleDateString('nl-NL',
                    { day:'2-digit', month:'short', year:'2-digit' });
                opt.textContent = `${comp.competition_naam} (${dat}) — ${comp.dcs.length} DC's`;
            }
            sluit();
            _hpHistRenderPreview(_hpHistResult);
        } catch (e) {
            okBtn.disabled = false;
            okBtn.textContent = '⚠ ' + e.message;
        }
    });
}

async function _hpHistInsert() {
    const compId = el('hp-hist-comp').value;
    const btn = el('hp-hist-btn-insert');
    const stat = el('hp-hist-insert-status');
    if (!_hpHistResult || !compId) return;

    // Comp ophalen (gebruikt voor birth_year_hint in rijen + rang-recalc verderop)
    const comp = _hpHistComps.find(w => w.competition_id === compId);

    // Verzamel geselecteerde rijen + hun gekozen DC + (eventueel) handmatige
    // persoon-keuze uit de picker. Bij een persoon-picker is die de bron van
    // waarheid; anders valt 'em terug op de server-side match.
    const rijen = [];
    document.querySelectorAll('#hp-hist-preview tbody tr').forEach(tr => {
        const cb = tr.querySelector('.hp-hist-cb');
        if (!cb.checked) return;
        const i = parseInt(tr.dataset.i);
        const r = _hpHistResult.rijders[i];
        const dcId = tr.querySelector('.hp-hist-dc-sel').value;
        if (!dcId) return;  // skip rijen zonder DC-keuze

        // Persoon-keuze: picker wint over server-match.
        // Géén license? Geen probleem meer — backend maakt automatisch een
        // 'pending' persons-rij aan (license_key = 'p-{12char}', pending_source=
        // 'historie'). Later via Helpers → Pending koppelen te mergen met de
        // echte KNSB-account zodra die in de DB zit. Zo gaan historische
        // uitslagen niet meer verloren bij rijders die nu niet meer actief zijn.
        const picker = tr.querySelector('.hp-hist-pers-sel');
        const lic = picker ? picker.value : r.person_license;

        rijen.push({
            distance_combination_id: dcId,
            person_license: lic || null,    // null = backend maakt pending-persoon
            categorie: r.categorie,
            rang: r.rang,
            tijd_ms: r.tijd_ms,
            sanctie: r.sanctie,
            startnummer: r.startnummer ?? null,
            naam: r.naam,
            // birth_year_hint bewust niet meegestuurd: een gok op basis van
            // cat-bereik zou latere fuzzy-matching kunnen misleiden. Naam + cat
            // is voldoende voor de koppel-tool om de juiste KNSB-account
            // te vinden.
        });
    });

    if (!rijen.length) {
        stat.textContent = '⚠ Geen geldige rijen geselecteerd (DC + persoon-match vereist).';
        stat.classList.add('hp-status-fout');
        return;
    }

    // Rang HERBEREKENEN per DC: bij een subset-DC (bv. HJA-only uit een
    // combo-race HJA+HSA+HSJ) moet de positie tellen vanaf 1 binnen die
    // cat-subset, niet de overall-positie blijven. Belangrijk: tel ALLE
    // PDF-rijders met die cat mee, ook de niet-aangevinkte (die niet in
    // de DB staan). Joes 11e overall met 2 HJA's erboven → wordt 3e in
    // de HJA-only klassement.
    if (comp) {
        // Per DC: object met rijIndex → nieuwe rang. Index-based ipv
        // object-reference: voorkomt subtiele Map-identity issues door
        // sort/filter (zelfde fix als in updateRangPreview).
        const nieuweRangenPerDc = {};
        const uniekeDcs = [...new Set(rijen.map(r => r.distance_combination_id))];
        for (const dcId of uniekeDcs) {
            const dc = comp.dcs.find(d => d.dc_id === dcId);
            if (!dc || !dc.cat) continue;
            const dcCats = dc.cat.split(',').map(s => s.trim().toUpperCase()).filter(Boolean);
            // Combo-DC → geen recalc, behoud PDF-rang (zie updateRangPreview).
            if (dcCats.length !== 1) continue;
            // Alle PDF-rijders met cat in deze DC (ook niet-aangevinkte!)
            const subset = [];
            _hpHistResult.rijders.forEach((r, idx) => {
                if (!r.categorie || r.rang == null) return;
                const cat = String(r.categorie).trim().toUpperCase();
                if (!dcCats.includes(cat)) return;
                subset.push({ idx, rang: Number(r.rang), naam: r.naam });
            });
            subset.sort((a, b) => a.rang - b.rang);
            const mapVoorDc = {};
            let nieuweRang = 0, vorigeOud = -Infinity;
            for (let i = 0; i < subset.length; i++) {
                if (subset[i].rang !== vorigeOud) nieuweRang = i + 1;
                mapVoorDc[subset[i].idx] = nieuweRang;
                vorigeOud = subset[i].rang;
            }
            nieuweRangenPerDc[dcId] = mapVoorDc;
        }
        // Pas hertoekende rang toe per rij. Match op naam+rang in
        // _hpHistResult.rijders → vind index → lookup nieuwe rang.
        for (const rij of rijen) {
            const map = nieuweRangenPerDc[rij.distance_combination_id];
            if (!map) continue;
            const origIdx = _hpHistResult.rijders.findIndex(
                r => r.naam === rij.naam && Number(r.rang) === Number(rij.rang)
            );
            if (origIdx >= 0 && map[origIdx] !== undefined) {
                rij.rang = map[origIdx];
            }
        }
    }

    const vervang = el('hp-hist-vervang')?.checked || false;

    btn.disabled = true;
    stat.textContent = 'Bezig…';
    stat.className = 'hp-status';
    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'historie_insert',
                competition_id: compId,
                afstand_naam: _hpHistResult.afstand_naam,
                afstand_meters: _hpHistResult.afstand_meters,
                race_type: _hpHistResult.race_type,
                vervang_bestaand: vervang,
                rijen,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        // Bij vervang-mode is "bijgewerkt" interessanter dan "duplicaten"
        const tail = vervang
            ? `✓ ${data.ingevoegd} nieuw, ${data.bijgewerkt ?? 0} overschreven, ${data.ongewijzigd ?? 0} ongewijzigd`
            : `✓ ${data.ingevoegd} nieuw, ${data.duplicaten} al aanwezig`;
        // Pending-feedback: zowel nieuw-aangemaakte als hergebruikte (= cross-jaar
        // dedupe: dezelfde naam komt al voor met een andere cat, en de leeftijds-
        // bereiken overlappen → uitslag-rijen gaan naar bestaande pending-license).
        const pendingDelen = [];
        if (data.pending_aangemaakt) {
            pendingDelen.push(`${data.pending_aangemaakt} aangemaakt`);
        }
        if (data.pending_hergebruikt) {
            pendingDelen.push(`${data.pending_hergebruikt} hergebruikt (cross-jaar match)`);
        }
        const pending = pendingDelen.length
            ? ` · ⚡ Pending: ${pendingDelen.join(', ')} (zie Helpers → Pending koppelen)`
            : '';
        stat.textContent = tail + (data.skip?.length ? `, ${data.skip.length} overgeslagen` : '') + pending;
        stat.classList.add('hp-status-ok');
        // Clear voor volgende PDF — wedstrijd-keuze blijft staan
        setTimeout(() => {
            el('hp-hist-tekst').value = '';
            el('hp-hist-preview').style.display = 'none';
            _hpHistMaybeEnable();
        }, 1500);
    } catch (e) {
        stat.textContent = '⚠ ' + e.message;
        stat.classList.add('hp-status-fout');
    } finally {
        btn.disabled = false;
    }
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

// ════════════════════════════════════════════════════════════════════════════
//   Pending rijders koppelen — verhuist historische uitslagen van een pending
//   placeholder-persoon (license_key 'p-…') naar een echte KNSB-account.
// ════════════════════════════════════════════════════════════════════════════

// Eenvoudige bevestig-modal — promise resolved met true (OK) / false (annuleer).
// Gebruikt dezelfde modal-overlay/dialog CSS-conventies als de rest van de app.
function _hpBevestigModal({ titel, bericht, bevestigLabel = 'OK', annuleerLabel = 'Annuleer' }) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog">
                <div class="modal-header">
                    <span class="modal-icon">❓</span>
                    <span>${escHtml(titel)}</span>
                </div>
                <div class="modal-body">${bericht}</div>
                <div class="modal-knoppen">
                    <button class="modal-btn modal-annuleer" data-act="0">${escHtml(annuleerLabel)}</button>
                    <button class="modal-btn modal-doorgaan" data-act="1">${escHtml(bevestigLabel)}</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const sluit = (waarde) => { overlay.remove(); resolve(waarde); };
        overlay.querySelector('[data-act="0"]').addEventListener('click', () => sluit(false));
        overlay.querySelector('[data-act="1"]').addEventListener('click', () => sluit(true));
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(false); });
    });
}

let _hpPendingData = null;

function _hpPendingInit() {
    el('hp-pending-btn-laad').addEventListener('click', _hpPendingLaad);
}

// ── Cluster-check helper (foute KNSB-koppelingen opsporen + fixen) ────────────
//
// Detecteert heat_entries waar de gekoppelde persons-rij in het verkeerde
// KNSB-cluster zit voor de bedoelde categorie (gevolg van de oude tier-1
// startnr-only match in csv_import.php). Per probleem-persoon: knop om
// kandidaten op te zoeken (cat + startnr = uniek volgens KNSB) en in één
// klik te vervangen voor ALLE entries van die persoon in deze wedstrijd.

async function _hpClusterCheckInit() {
    const sel = el('hp-cc-comp');
    const btn = el('hp-cc-scan-btn');
    sel.innerHTML = '<option value="">— laden… —</option>';
    try {
        const res  = await fetch('api/cluster_check.php?action=competities');
        const lijst = await res.json();
        if (lijst.error) throw new Error(lijst.error);
        if (!Array.isArray(lijst) || !lijst.length) {
            sel.innerHTML = '<option value="">— geen wedstrijden met deelnemers —</option>';
            return;
        }
        sel.innerHTML = '<option value="">— kies wedstrijd —</option>' +
            lijst.map(c => {
                const dat = c.datum ? new Date(c.datum).toLocaleDateString('nl-NL',
                    {day:'2-digit', month:'2-digit', year:'numeric'}) : '?';
                return `<option value="${escHtml(c.competition_id)}">${escHtml(c.naam)} (${escHtml(dat)})</option>`;
            }).join('');
    } catch (e) {
        sel.innerHTML = `<option value="">⚠ Fout: ${escHtml(e.message)}</option>`;
        return;
    }
    sel.addEventListener('change', () => { btn.disabled = !sel.value; });
    btn.addEventListener('click', _hpClusterCheckScan);
}

async function _hpClusterCheckScan() {
    const sel  = el('hp-cc-comp');
    const btn  = el('hp-cc-scan-btn');
    const stat = el('hp-cc-status');
    const lijst = el('hp-cc-lijst');
    const compId = sel.value;
    if (!compId) return;
    btn.disabled = true;
    stat.textContent = 'Bezig…';
    stat.className = 'hp-status';
    lijst.style.display = 'none';
    lijst.innerHTML = '';
    const debug = el('hp-cc-debug')?.checked ? '&debug=1' : '';
    try {
        const res  = await fetch('api/cluster_check.php?action=scan&competition_id='
                                 + encodeURIComponent(compId) + debug);
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        if (data.leeg) {
            stat.textContent = 'ℹ ' + data.leeg_reden;
            stat.className = 'hp-status';
        } else {
            stat.textContent = data.totaal === 0
                ? '✓ Geen mismatches gevonden.'
                : `${data.totaal} persoon${data.totaal === 1 ? '' : 'en'} met mogelijke fout.`;
            stat.className = 'hp-status' + (data.totaal === 0 ? ' hp-status-ok' : ' hp-status-warn');
        }
        let html = '';
        if (data.totaal > 0) {
            html += _hpClusterCheckRender(data.problemen, compId);
        }
        if (data.debug) {
            html += _hpClusterCheckRenderDebug(data.debug, data.debug_meta);
        }
        if (html) {
            lijst.style.display = '';
            lijst.innerHTML = html;
            // Re-bind fix-knoppen na concat
            document.querySelectorAll('#hp-cc-lijst .hp-cc-fix-btn').forEach(btn => {
                btn.addEventListener('click', () => _hpClusterCheckZoekFix(
                    data.problemen[+btn.dataset.ccIdx], +btn.dataset.ccIdx, compId
                ));
            });
        }
    } catch (e) {
        stat.textContent = '⚠ ' + e.message;
        stat.className = 'hp-status hp-status-fout';
    } finally {
        btn.disabled = !sel.value;
    }
}

function _hpClusterCheckRender(problemen, compId) {
    const rows = problemen.map((p, idx) => {
        const persG = p.persoon.gender || '?';
        const persC = p.persoon.category || '?';
        const wantG = p.verwacht_gender || '?';
        const wantJ = p.verwacht_jong === true ? 't/m JB'
                    : p.verwacht_jong === false ? 'vanaf JA' : '?';
        const mismatches = [];
        if (p.mismatch_gender)  mismatches.push(`gender (heeft ${escHtml(persG)}, verwacht ${escHtml(wantG)})`);
        if (p.mismatch_cluster) mismatches.push(`cluster (heeft ${escHtml(persC)}, verwacht ${escHtml(wantJ)})`);
        return `
            <tr data-cc-idx="${idx}">
                <td><b>${escHtml(p.persoon.full_name)}</b><br>
                    <span style="font-size:.85em;color:#555">
                        ${escHtml(persC)} · #${p.persoon.start_number ?? '?'} ·
                        ${escHtml(p.persoon.club || '—')}
                    </span></td>
                <td>${escHtml(p.dc_naam || '?')}<br>
                    <span style="font-size:.85em;color:#555">${escHtml(p.verwacht_label || '?')} · #${p.entry_snr ?? '?'}</span></td>
                <td style="color:#b85a00">${mismatches.join('<br>')}</td>
                <td><button class="btn-secondary hp-cc-fix-btn"
                            data-cc-idx="${idx}">🔍 Zoek juiste</button></td>
            </tr>
            <tr data-cc-fix="${idx}" style="display:none">
                <td colspan="4" style="background:#f7f9fc;padding:.6rem .8rem">
                    <span class="hp-cc-fix-status">⏳ Laden…</span>
                </td>
            </tr>`;
    }).join('');
    const html = `
        <table class="hp-cc-tabel">
            <thead><tr>
                <th>Gekoppelde persoon (fout?)</th>
                <th>DC verwacht</th>
                <th>Mismatch</th>
                <th></th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
    // Bindings worden gezet door _hpClusterCheckScan na innerHTML
    return html;
}

function _hpClusterCheckRenderDebug(debugInfo, meta) {
    const metaBlok = meta ? `
        <div style="font-size:.82em;color:#555;margin-bottom:.5rem;padding:.4rem .6rem;background:#fff;border-radius:3px">
            <b>${meta.totaal_dcs_in_wedstrijd}</b> DC${meta.totaal_dcs_in_wedstrijd === 1 ? '' : '\'s'} in wedstrijd ·
            <b>${meta.dcs_met_heat_entries}</b> met heat_entries (gescand).
            <br>${escHtml(meta.verschil_uitleg || '')}
        </div>` : '';
    if (!Array.isArray(debugInfo) || !debugInfo.length) {
        return `<div style="margin-top:.8rem;padding:.6rem;background:#fef9e7;border:1px solid #e0a800;border-radius:4px">
            🔬 Debug: geen DC-data ontvangen.
            ${metaBlok}
        </div>`;
    }
    const fmtMap = obj => {
        if (!obj || typeof obj !== 'object') return '—';
        return Object.entries(obj).map(([k, v]) =>
            `<code>${escHtml(k)}</code>=${v}`).join(' · ');
    };
    const rows = debugInfo.map(d => {
        let dom;
        if (typeof d.dominant === 'object' && d.dominant !== null) {
            dom = `<b style="color:#0a7a3a">${escHtml(d.dominant.category)}</b>
                   · cluster ${escHtml(d.dominant.cluster)}
                   · ${d.dominant.top_pct}% van ${d.dominant.aantal}`;
        } else {
            dom = `<span style="color:#b85a00">${escHtml(String(d.dominant))}</span>`;
        }
        return `<tr>
            <td><b>${escHtml(d.dc_naam || '?')}</b></td>
            <td>${d.totaal} (+${d.extern_pending_geskipt} extern/pending)</td>
            <td>${fmtMap(d.gender_telling)}</td>
            <td>${fmtMap(d.cat_telling)}</td>
            <td>${fmtMap(d.cluster_telling)}</td>
            <td>${dom}</td>
        </tr>`;
    }).join('');
    return `
        <div style="margin-top:1rem;padding:.6rem .8rem;background:#f5f9fc;border:1px solid #c0d8ec;border-radius:4px">
            <div style="font-weight:600;color:#1565c0;margin-bottom:.4rem">🔬 Debug per DC</div>
            ${metaBlok}
            <div style="font-size:.82em;color:#555;margin-bottom:.4rem">
                Per DC: hoe wordt de dominante cluster bepaald. Drempel = 60%.
                Cluster <code>V_J</code> = vrouw t/m JB · <code>M_O</code> = man vanaf JA.
            </div>
            <table class="hp-cc-tabel" style="font-size:.78rem">
                <thead><tr>
                    <th>DC</th><th>Totaal</th><th>Gender</th>
                    <th>Categorie</th><th>Cluster</th><th>Dominant</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
}

async function _hpClusterCheckZoekFix(prob, idx, compId) {
    const fixRow = document.querySelector(`#hp-cc-lijst tr[data-cc-fix="${idx}"]`);
    if (!fixRow) return;
    fixRow.style.display = '';
    const cell = fixRow.querySelector('td');
    cell.innerHTML = '<span>⏳ Kandidaten zoeken…</span>';
    try {
        // Pak eerste entry voor de zoek-actie — alle entries hebben dezelfde
        // categorie+startnummer (van dezelfde foute persoon).
        const entryId = prob.entries[0].entry_id;
        const res = await fetch('api/cluster_check.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'zoek_kandidaten', entry_id: entryId }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Drie soorten oplossingen die we tonen wanneer relevant:
        //
        // A) Vervang persoon — voor "Sophie/Lars": foute persoon hangt aan
        //    Sophie's inschrijving. Toon kandidaten (dominante cat + snr).
        //
        // B) Verplaats naar passende DC — voor "Roan Vos": persoon klopt,
        //    DC niet. Toon DC's in deze comp waar diens cluster dominant is.
        //
        // C) Verwijder uit deze DC — fallback. Operator regelt zelf de rest.
        const entryIds = prob.entries.map(e => e.entry_id);

        // ── A) Kandidaten voor vervang
        let html = '';
        if (data.kandidaten?.length) {
            const opties = data.kandidaten.map(k => `
                <div class="hp-cc-kand">
                    <span>
                        <b>${escHtml(k.full_name)}</b>
                        ${k.birth_year ? `(${k.birth_year})` : ''}
                        <span style="font-size:.85em;color:#555">
                            · ${escHtml(k.category ?? '?')} · #${k.start_number ?? '?'}
                            · ${escHtml(k.club || '—')}
                        </span>
                    </span>
                    <button class="btn-primary hp-cc-verv-btn"
                            data-lic="${escHtml(k.license_key)}"
                            data-naam="${escHtml(k.full_name)}">
                        ↪ Vervang persoon (${entryIds.length})
                    </button>
                </div>`).join('');
            html += `
                <div style="margin-bottom:.3rem;font-size:.9em;color:#1565c0">
                    💡 Andere persoon met <b>${escHtml(data.gezocht_cat)}</b> #${data.gezocht_snr}:
                </div>
                ${opties}`;
        }

        // ── B) Verplaats naar passende DC
        if (data.doel_dcs?.length) {
            const dcOpties = data.doel_dcs.map(dc => `
                <div class="hp-cc-kand">
                    <span>
                        <b>${escHtml(dc.dc_naam)}</b>
                        ${dc.exact_match
                            ? `<span style="color:#0a7a3a;font-size:.85em">· exacte cat-match ${escHtml(dc.dominant_cat)}</span>`
                            : `<span style="font-size:.85em;color:#555">· dominant ${escHtml(dc.dominant_cat)} (${dc.aantal})</span>`}
                    </span>
                    <button class="btn-primary hp-cc-verpl-btn"
                            data-dc="${escHtml(dc.dc_id)}"
                            data-naam="${escHtml(dc.dc_naam)}">
                        ↪ Verplaats hierheen
                    </button>
                </div>`).join('');
            html += `
                <div style="margin-top:.5rem;margin-bottom:.3rem;font-size:.9em;color:#1565c0">
                    💡 Persoon (<b>${escHtml(data.persoon_cat ?? '?')}</b>) past beter in:
                </div>
                ${dcOpties}`;
        }

        // ── C) Verwijder fallback (altijd)
        html += `
            <div class="hp-cc-kand" style="margin-top:.5rem;border-top:1px dashed #c0c0c0;padding-top:.5rem">
                <span style="font-size:.85em;color:#555">
                    Geen passende oplossing? Verwijder alleen — schrijf zelf in via Importeer → beheer.
                </span>
                <button class="btn-secondary hp-cc-del-btn">
                    🗑 Alleen verwijderen
                </button>
            </div>`;

        // Helemaal niets bruikbaars? Extra uitleg bovenaan.
        if (!data.kandidaten?.length && !data.doel_dcs?.length) {
            html = `
                <div style="margin-bottom:.4rem">
                    <span style="color:#b71c1c">
                        Geen kandidaat-persoon én geen passende doel-DC gevonden.
                    </span>
                    <br><span style="font-size:.85em;color:#555">
                        Mogelijk staat de juiste rijder nog niet in de DB
                        (importeer eerst diens KNSB-licentie) of bestaat de
                        juiste DC niet in deze wedstrijd.
                    </span>
                </div>` + html;
        }
        cell.innerHTML = html;

        cell.querySelectorAll('.hp-cc-verv-btn').forEach(b => {
            b.addEventListener('click', () => _hpClusterCheckVervang(
                entryIds, b.dataset.lic, b.dataset.naam, idx
            ));
        });
        cell.querySelectorAll('.hp-cc-verpl-btn').forEach(b => {
            b.addEventListener('click', () => _hpClusterCheckVerplaats(
                entryIds, b.dataset.dc, b.dataset.naam, prob.persoon.full_name, idx
            ));
        });
        cell.querySelector('.hp-cc-del-btn')?.addEventListener('click', () => {
            _hpClusterCheckVerwijder(entryIds, prob.persoon.full_name, idx);
        });
    } catch (e) {
        cell.innerHTML = `<span style="color:#b71c1c">⚠ ${escHtml(e.message)}</span>`;
    }
}

async function _hpClusterCheckVerplaats(entryIds, doelDcId, doelDcNaam, persoonNaam, idx) {
    const row = document.querySelector(`#hp-cc-lijst tr[data-cc-idx="${idx}"]`);
    const fixRow = document.querySelector(`#hp-cc-lijst tr[data-cc-fix="${idx}"]`);
    if (!await toonBevestigDialog(
        `<b>${escHtml(persoonNaam)}</b> verplaatsen naar <b>${escHtml(doelDcNaam)}</b>?<br>` +
        `Inschrijving in huidige DC wordt verwijderd, nieuwe inschrijving in doel-DC aangemaakt. ` +
        `Bijbehorende heat_entries (loting van huidige DC) worden opgeschoond — ` +
        `loting voor doel-DC moet opnieuw gegenereerd worden.`,
        'Verplaatsen', 'Verplaatsen', 'Annuleren', { bodyIsHtml: true }
    )) return;
    try {
        const res = await fetch('api/cluster_check.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'verplaats', entry_ids: entryIds, doel_dc_id: doelDcId,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        row.style.opacity = '.5';
        row.style.textDecoration = 'line-through';
        fixRow.style.display = '';
        const delen = [];
        if (data.verplaatst)   delen.push(`${data.verplaatst} inschrijving${data.verplaatst === 1 ? '' : 'en'} verplaatst naar <b>${escHtml(data.doel_dc_naam)}</b>`);
        if (data.al_aanwezig)  delen.push(`${data.al_aanwezig} stond al in doel-DC (huidige weggehaald)`);
        if (data.he_verwijderd) delen.push(`${data.he_verwijderd} heat-entr${data.he_verwijderd === 1 ? 'y' : 'ies'} opgeschoond`);
        fixRow.querySelector('td').innerHTML =
            `<span style="color:#0a7a3a">✓ ${delen.join(', ') || 'niets te doen'}.</span>`;
    } catch (e) {
        await toonBevestigDialog('Fout: ' + e.message, 'Fout', 'OK', null);
    }
}

async function _hpClusterCheckVerwijder(entryIds, persoonNaam, idx) {
    const row = document.querySelector(`#hp-cc-lijst tr[data-cc-idx="${idx}"]`);
    const fixRow = document.querySelector(`#hp-cc-lijst tr[data-cc-fix="${idx}"]`);
    if (!await toonBevestigDialog(
        `<b>${escHtml(persoonNaam)}</b> verwijderen uit deze DC ` +
        `(${entryIds.length} inschrijving${entryIds.length === 1 ? '' : 'en'})? ` +
        `De persoon zelf en zijn inschrijvingen in andere DCs blijven staan. ` +
        `Bijbehorende heat_entries (loting) worden ook opgeschoond.`,
        'Verwijderen uit DC', 'Verwijderen', 'Annuleren', { bodyIsHtml: true }
    )) return;
    try {
        const res = await fetch('api/cluster_check.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verwijder', entry_ids: entryIds }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        row.style.opacity = '.5';
        row.style.textDecoration = 'line-through';
        fixRow.style.display = '';
        const delen = [`${data.verwijderd} inschrijving${data.verwijderd === 1 ? '' : 'en'} verwijderd`];
        if (data.he_verwijderd) delen.push(`${data.he_verwijderd} heat-entr${data.he_verwijderd === 1 ? 'y' : 'ies'} opgeschoond`);
        fixRow.querySelector('td').innerHTML =
            `<span style="color:#0a7a3a">✓ ${delen.join(', ')}.</span>`;
    } catch (e) {
        await toonBevestigDialog('Fout: ' + e.message, 'Fout', 'OK', null);
    }
}

async function _hpClusterCheckVervang(entryIds, nieuweLic, nieuwNaam, idx) {
    const row = document.querySelector(`#hp-cc-lijst tr[data-cc-idx="${idx}"]`);
    const fixRow = document.querySelector(`#hp-cc-lijst tr[data-cc-fix="${idx}"]`);
    if (!await toonBevestigDialog(
        `${entryIds.length} fout-gekoppelde entr${entryIds.length === 1 ? 'y' : 'ies'} vervangen door <b>${escHtml(nieuwNaam)}</b>?`,
        'Persoon vervangen', 'Vervangen', 'Annuleren', { bodyIsHtml: true }
    )) return;
    try {
        const res = await fetch('api/cluster_check.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'vervang', entry_ids: entryIds, nieuwe_license: nieuweLic,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        // Visueel "afgehandeld" markeren — strikethrough + uitleg in fix-rij
        row.style.opacity = '.5';
        row.style.textDecoration = 'line-through';
        fixRow.style.display = '';
        const delen = [];
        if (data.bijgewerkt)   delen.push(`${data.bijgewerkt} inschrijving${data.bijgewerkt === 1 ? '' : 'en'} gewijzigd`);
        if (data.verwijderd)   delen.push(`${data.verwijderd} verwijderd (juiste persoon stond al ingeschreven)`);
        if (data.he_bijgewerkt) delen.push(`${data.he_bijgewerkt} heat-entr${data.he_bijgewerkt === 1 ? 'y' : 'ies'} mee-bijgewerkt`);
        if (data.he_verwijderd) delen.push(`${data.he_verwijderd} heat-entr${data.he_verwijderd === 1 ? 'y' : 'ies'} verwijderd`);
        let msg = `✓ Naar <b>${escHtml(data.nieuw_naam)}</b>: ` + (delen.length ? delen.join(', ') + '.' : 'niets te doen.');
        if (data.geskipped?.length) {
            msg += ` ${data.geskipped.length} overgeslagen (was al goed).`;
        }
        fixRow.querySelector('td').innerHTML =
            `<span style="color:#0a7a3a">${msg}</span>`;
    } catch (e) {
        await toonBevestigDialog('Fout: ' + e.message, 'Fout', 'OK', null);
    }
}

async function _hpPendingLaad() {
    const stat = el('hp-pending-status');
    const btn  = el('hp-pending-btn-laad');
    const lijst = el('hp-pending-lijst');
    btn.disabled = true;
    stat.textContent = 'Bezig…';
    stat.className = 'hp-status';
    try {
        const res = await fetch('api/helpers.php?action=pending_lijst');
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        _hpPendingData = data.pendings || [];
        stat.textContent = `✓ ${_hpPendingData.length} pending rijder(s) gevonden`;
        stat.classList.add('hp-status-ok');
        _hpPendingRender();
        lijst.style.display = '';
    } catch (e) {
        stat.textContent = '⚠ ' + e.message;
        stat.classList.add('hp-status-fout');
    } finally {
        btn.disabled = false;
    }
}

function _hpPendingRender() {
    const lijst = el('hp-pending-lijst');
    if (!_hpPendingData || !_hpPendingData.length) {
        lijst.innerHTML = `<p style="color:#666;font-style:italic">
            Geen pending rijders — alle historische uitslagen zijn gekoppeld
            aan een bestaand account. 🎉</p>`;
        return;
    }
    const rij = (p) => {
        // Type-badge: pending (📜 uit historie-import) of extern (🌍 uit
        // CSV-import). Beide soorten staan in dezelfde lijst zodat operator
        // duplicaten tussen de twee categorieën kan opmerken — bv. een CSV-
        // import die per ongeluk een nieuwe externe maakte terwijl er al een
        // pending bestond met dezelfde naam.
        const typeBadge = p.match_reden === 'zelfde_naam_cat'
            ? '<span class="hp-pending-type hp-pending-type-zelfde" title="Naamgenoot van een echte KNSB-licentie in de DB (waarschijnlijk dagvergunning of dubbele import)">🔀 naamgenoot</span>'
            : p.is_extern
            ? '<span class="hp-pending-type hp-pending-type-extern" title="Externe rijder uit CSV-import">🌍 extern</span>'
            : '<span class="hp-pending-type hp-pending-type-pending" title="Pending uit uitslag-historie">📜 pending</span>';

        // Cat-evolutie label: "DJB-20 → DJB-21 → DJA-23" — toont werkelijke
        // cat-progressie uit uitslag-rijen, niet de (mogelijk verouderde)
        // pending.category. Plus afgeleid geboortejaar (intersect van alle
        // cat × jaar bereiken) zodat operator direct ziet welke leeftijd
        // de leeftijdscheck als plausibel ziet.
        const catTag = p.cat_evolutie
            ? `<span class="hp-pending-cat" title="Cat-progressie door de jaren (oudst → recentst)">${escHtml(p.cat_evolutie)}</span>`
            : (p.category
                ? `<span class="hp-pending-cat">${escHtml(p.category)}${p.pdf_jaar ? ' (' + p.pdf_jaar + ')' : ''}</span>`
                : '');
        const bornTag = p.birth_label && p.birth_label !== '?'
            ? `<span class="hp-pending-born" title="Afgeleid geboortejaar uit (cat × jaar)-intersectie">born ${escHtml(p.birth_label)}</span>`
            : '';
        // Counts: pendings hebben alleen uitslagen, externen vooral entries
        // en eventueel transponders. Toon alleen wat > 0 is.
        const countTags = [];
        if (p.aantal_uitslagen > 0) {
            countTags.push(`<span class="hp-pending-uit">${p.aantal_uitslagen}× uitslag</span>`);
        }
        if (p.aantal_entries > 0) {
            countTags.push(`<span class="hp-pending-uit">${p.aantal_entries}× entry</span>`);
        }
        if (p.aantal_transponders > 0) {
            countTags.push(`<span class="hp-pending-uit">${p.aantal_transponders}× transponder</span>`);
        }
        const countsBlok = countTags.join(' ');
        // Dubbele-pending banner: andere pending/externe rijen met dezelfde
        // naam + compatibele cat-evolutie. Operator kan met één klik samen-
        // voegen. Reden van bestaan: voor de auto-dedupe-fix konden meerdere
        // pending-rijen voor dezelfde persoon ontstaan (DJA 2022 + DSJ 2024 =
        // 2 rijen ondanks zelfde persoon); sinds externen in dezelfde lijst
        // staan vangt deze sectie ook pending↔extern duplicaten.
        //
        // Dedupe: als een dup-kandidaat ÓÓK al in 'suggesties' staat met een
        // expliciete leeftijds-reden, verberg 'em hier — anders krijg je 2
        // knoppen die exact dezelfde actie uitvoeren.
        const suggLicenses = new Set((p.suggesties || []).map(s => s.license_key));
        const dubbeleFiltered = (p.dubbele_pendings || [])
            .filter(d => !suggLicenses.has(d.license_key));
        const dupBlok = dubbeleFiltered.length
            ? `<div class="hp-pending-dup">
                  <span class="hp-pending-dup-titel">↪ Mogelijk dezelfde rijder als:</span>
                  ${dubbeleFiltered.map(d => {
                      const naamLabel = d.full_name
                          ? `<b>${escHtml(d.full_name)}</b>`
                          : '<b>?</b>';
                      const fuzzyTag = d.naam_match === 'fuzzy'
                          ? `<span class="hp-pending-dup-fuzzy" title="Naam licht anders gespeld (overlap ${d.naam_score})">≈</span>`
                          : '';
                      // Cat-evolutie + born uit backend (zelfde info als in
                      // pending-header) zodat operator snel kan zien of dit
                      // qua leeftijd en cat-progressie plausibel is.
                      const catInfo = d.cat_evolutie
                          ? escHtml(d.cat_evolutie)
                          : (escHtml(d.category || '?') + (d.pdf_jaar ? ' (' + d.pdf_jaar + ')' : ''));
                      const bornInfo = d.birth_label && d.birth_label !== '?'
                          ? ` · born ${escHtml(d.birth_label)}`
                          : '';
                      // Type-icoon: dup-suggestie kan een andere pending OF
                      // externe rij zijn (sinds beide in dezelfde lijst staan).
                      const dupIcoon = d.is_extern ? '🌍' : '📜';
                      return `
                          <button class="hp-pending-dup-btn"
                                  data-source="${escHtml(d.license_key)}"
                                  data-target="${escHtml(p.license_key)}"
                                  title="Samenvoegen met ${escHtml(p.full_name)}">
                              <span class="hp-pending-sugg-icoon">${dupIcoon}</span>
                              ${fuzzyTag}${naamLabel}
                              <span class="hp-pending-dup-meta">
                                  ${catInfo}${bornInfo} · samenvoegen ↪
                              </span>
                          </button>`;
                  }).join('')}
              </div>`
            : '';
        // Suggestie-button: naast naam ook de matching-reden tonen (leeftijd
        // ✓ of ?), zodat operator een snel oordeel kan vellen zonder elke
        // suggestie te moeten verifiëren.
        const suggBlok = (p.suggesties && p.suggesties.length)
            ? `<div class="hp-pending-sugg">
                  <div class="hp-pending-sugg-titel">Suggesties uit DB:</div>
                  ${p.suggesties.map(s => {
                      const meta = [s.birth_year, s.category, s.club_short].filter(Boolean).join(' · ');
                      const redenCls = s.reden && s.reden.startsWith('✓') ? 'hp-pending-reden-ok'
                                     : s.reden && s.reden.startsWith('?') ? 'hp-pending-reden-onbekend'
                                     : '';
                      const redenBlok = s.reden
                          ? `<span class="hp-pending-reden ${redenCls}">${escHtml(s.reden)}</span>`
                          : '';
                      // Doel-type-icoon: 📜 pending, 🌍 extern, 🏆 KNSB-account.
                      // Maakt direct duidelijk wat voor account je koppelt aan.
                      const tgtIcoon = s.is_pending ? '📜' : (s.is_extern ? '🌍' : '🏆');
                      const tgtTitel = s.is_pending ? 'Andere pending uit historie'
                                     : s.is_extern ? 'Externe rijder uit CSV-import'
                                     : 'Bestaand KNSB-account';
                      // Kleur-modifier: paars wanneer target ook pending/extern is
                      // (onderling samenvoegen — bron-data 'dubieus'). Blauw blijft
                      // voor KNSB-feed (vertrouwde data).
                      const isOnderling = s.is_pending || s.is_extern;
                      const btnCls = isOnderling
                          ? 'hp-pending-sugg-btn hp-pending-sugg-btn-onderling'
                          : 'hp-pending-sugg-btn';
                      return `
                          <button class="${btnCls}"
                                  data-pending="${escHtml(p.license_key)}"
                                  data-target="${escHtml(s.license_key)}"
                                  title="Score: ${s.score} — ${tgtTitel}">
                              <span class="hp-pending-sugg-icoon">${tgtIcoon}</span>
                              ${escHtml(s.full_name)}
                              <span class="hp-pending-sugg-meta">${escHtml(meta)}</span>
                              ${redenBlok}
                          </button>`;
                  }).join('')}
              </div>`
            : `<div class="hp-pending-sugg-leeg">
                  Geen suggesties die op naam + leeftijd plausibel zijn.
                  Gebruik handmatig zoeken hieronder.
              </div>`;
        return `
            <div class="hp-pending-rij" data-lic="${escHtml(p.license_key)}" data-type="${p.is_extern ? 'extern' : 'pending'}">
                <div class="hp-pending-hoofd">
                    <div class="hp-pending-naam">
                        ${typeBadge}
                        <b>${escHtml(p.full_name)}</b>
                        ${catTag}
                        ${bornTag}
                        ${countsBlok}
                    </div>
                    <div class="hp-pending-acties">
                        <input type="text" class="inp hp-pending-zoek"
                               placeholder="Zoek rijder op naam of licentie…">
                        <button class="btn-danger hp-pending-del">🗑 Verwijder</button>
                    </div>
                </div>
                ${dupBlok}
                ${suggBlok}
                <div class="hp-pending-zoekres" style="display:none"></div>
            </div>`;
    };
    lijst.innerHTML = `<div class="hp-pending-wrap">${_hpPendingData.map(rij).join('')}</div>`;

    // Event-handlers
    lijst.querySelectorAll('.hp-pending-sugg-btn').forEach(btn => {
        btn.addEventListener('click', () => _hpPendingKoppel(btn.dataset.pending, btn.dataset.target));
    });
    lijst.querySelectorAll('.hp-pending-del').forEach(btn => {
        btn.addEventListener('click', () => {
            const rijEl = btn.closest('.hp-pending-rij');
            _hpPendingVerwijder(rijEl.dataset.lic);
        });
    });
    lijst.querySelectorAll('.hp-pending-zoek').forEach(inp => {
        let timer = null;
        inp.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => _hpPendingZoek(inp), 300);
        });
    });
    // Merge-knoppen voor cross-jaar duplicaten
    lijst.querySelectorAll('.hp-pending-dup-btn').forEach(btn => {
        btn.addEventListener('click', () =>
            _hpPendingMerge(btn.dataset.source, btn.dataset.target));
    });
}

async function _hpPendingMerge(sourceLic, targetLic) {
    const source = _hpPendingData.find(p => p.license_key === sourceLic);
    const target = _hpPendingData.find(p => p.license_key === targetLic);
    if (!source || !target) return;
    // Type-labels en icons — source/target kunnen pending of extern zijn.
    const srcIcoon = source.is_extern ? '🌍' : '📜';
    const tgtIcoon = target.is_extern ? '🌍' : '📜';
    const srcType  = source.is_extern ? 'externe' : 'pending';
    const tgtType  = target.is_extern ? 'externe' : 'pending';
    // Target wint — zijn type blijft staan. Operator kiest welke rij hij
    // wil behouden door welke richting hij kiest.
    const eindType = target.is_extern ? 'externe rij' : 'pending-rij (wacht op KNSB-feed)';
    // Beschrijf wat er verhuist: uitslagen + entries + transponders, alleen
    // wat > 0 is — netter dan harde "X uitslagen" als het feitelijk om
    // entries gaat (typisch geval bij externe rij).
    const onderdelen = [];
    if (source.aantal_uitslagen > 0)    onderdelen.push(`<b>${source.aantal_uitslagen}</b> uitslag${source.aantal_uitslagen === 1 ? '' : 'en'}`);
    if (source.aantal_entries > 0)      onderdelen.push(`<b>${source.aantal_entries}</b> inschrijving${source.aantal_entries === 1 ? '' : 'en'}`);
    if (source.aantal_transponders > 0) onderdelen.push(`<b>${source.aantal_transponders}</b> transponder${source.aantal_transponders === 1 ? '' : 's'}`);
    const verhuistTxt = onderdelen.length ? onderdelen.join(' + ') : '<i>(geen gekoppelde data)</i>';
    const ok = await _hpBevestigModal({
        titel: 'Samenvoegen — dezelfde persoon?',
        bericht: `<p>${verhuistTxt} van <b>${escHtml(source.full_name)}</b>
                  verhuizen naar de ${tgtIcoon} ${tgtType}-rij
                  <b>${escHtml(target.full_name)}</b>
                  <span style="color:#888">(${escHtml(target.category || '?')}${target.pdf_jaar ? ' ' + target.pdf_jaar : ''})</span>.</p>
                  <p style="margin:.4em 0">▸ De ${srcIcoon} ${srcType}-rij
                  <b>${escHtml(source.full_name)}</b>
                  <span style="color:#888">(${escHtml(source.category || '?')}${source.pdf_jaar ? ' ' + source.pdf_jaar : ''})</span>
                  wordt <b>verwijderd</b>.</p>
                  <p style="margin-top:.6em"><b>Eindresultaat:</b> één ${tgtIcoon} ${eindType}.</p>
                  <p style="color:#888;font-size:.85em">Deze actie kan niet ongedaan gemaakt worden.</p>`,
        bevestigLabel: 'Ja, samenvoegen',
        annuleerLabel: 'Annuleer',
    });
    if (!ok) return;
    try {
        // Gebruik pending_link i.p.v. de oude pending_merge — werkt voor álle
        // combinaties (pending→pending, pending→extern, extern→pending,
        // extern→extern, en met KNSB-accounts).
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'pending_link',
                pending_license: sourceLic,
                target_license:  targetLic,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        // Status-feedback: combineer counts uit pending_link-response
        const verhuisdParts = [];
        if (data.verhuisd > 0)         verhuisdParts.push(`${data.verhuisd} uitslagen`);
        if (data.entries_verhuisd > 0) verhuisdParts.push(`${data.entries_verhuisd} entries`);
        if (data.tp_verhuisd > 0)      verhuisdParts.push(`${data.tp_verhuisd} transponders`);
        const conflictTotal = (data.conflict_skip || 0) + (data.entries_conflict || 0) + (data.tp_conflict || 0);
        const conflictTxt = conflictTotal > 0 ? ` (${conflictTotal} dubbel-conflict overgeslagen)` : '';
        const stat = el('hp-pending-status');
        stat.textContent = `✓ Samengevoegd: ${verhuisdParts.join(' + ') || 'geen data'}${conflictTxt}`;
        stat.className = 'hp-status hp-status-ok';
        await _hpPendingLaad();
    } catch (e) {
        const stat = el('hp-pending-status');
        stat.textContent = '⚠ ' + e.message;
        stat.className = 'hp-status hp-status-fout';
    }
}

async function _hpPendingZoek(inp) {
    const rijEl  = inp.closest('.hp-pending-rij');
    const resEl  = rijEl.querySelector('.hp-pending-zoekres');
    const lic    = rijEl.dataset.lic;
    const q      = inp.value.trim();
    if (q.length < 2) { resEl.style.display = 'none'; resEl.innerHTML = ''; return; }
    try {
        const r = await fetch(`api/helpers.php?action=pending_zoek_echte&q=${encodeURIComponent(q)}`);
        const data = await r.json();
        if (data.error) throw new Error(data.error);
        // Zelf eruit filteren — server geeft alles inclusief de huidige rij
        const treffers = (data.results || []).filter(p => p.license_key !== lic);
        if (!treffers.length) {
            resEl.innerHTML = '<em style="color:#888">Geen treffers</em>';
        } else {
            resEl.innerHTML = treffers.map(p => {
                // Type-icoon zoals bij auto-suggesties
                const tgtIcoon = p.is_pending ? '📜' : (p.is_extern ? '🌍' : '🏆');
                const tgtTitel = p.is_pending ? 'Andere pending uit historie'
                               : p.is_extern ? 'Externe rijder uit CSV-import'
                               : 'Bestaand KNSB-account';
                // Paarse modifier voor onderling samenvoegen (target is pending/extern)
                const isOnderling = p.is_pending || p.is_extern;
                const btnCls = isOnderling
                    ? 'hp-pending-sugg-btn hp-pending-sugg-btn-onderling'
                    : 'hp-pending-sugg-btn';
                return `
                <button class="${btnCls}"
                        data-pending="${escHtml(lic)}"
                        data-target="${escHtml(p.license_key)}"
                        title="${tgtTitel}">
                    <span class="hp-pending-sugg-icoon">${tgtIcoon}</span>
                    ${escHtml(p.full_name)}
                    <span class="hp-pending-sugg-meta">
                        ${[p.birth_year, p.category, p.club_short, p.license_key].filter(Boolean).join(' · ')}
                    </span>
                </button>`;
            }).join('');
            resEl.querySelectorAll('.hp-pending-sugg-btn').forEach(b => {
                b.addEventListener('click', () => _hpPendingKoppel(b.dataset.pending, b.dataset.target));
            });
        }
        resEl.style.display = '';
    } catch (e) {
        resEl.innerHTML = `<em style="color:#b71c1c">⚠ ${escHtml(e.message)}</em>`;
        resEl.style.display = '';
    }
}

async function _hpPendingKoppel(pendingLic, targetLic) {
    // Vraag bevestiging
    const source = _hpPendingData.find(p => p.license_key === pendingLic);
    if (!source) return;
    // Bron- en doeltype bepalen — beide kunnen pending OF extern zijn sinds
    // de helper-uitbreiding. KNSB-target = noch p-… noch x-….
    const tStr = String(targetLic || '');
    const targetIsPending = tStr.startsWith('p-');
    const targetIsExtern  = tStr.startsWith('x-');
    const targetIsKnsb    = !targetIsPending && !targetIsExtern;
    const sourceIsExtern  =  source.is_extern;
    const sourceTypeLabel = sourceIsExtern ? 'externe' : 'pending';
    const sourceIcoon     = sourceIsExtern ? '🌍' : '📜';
    const targetIcoon     = targetIsKnsb ? '🏆' : (targetIsExtern ? '🌍' : '📜');
    const targetTypeLabel = targetIsKnsb ? 'KNSB-rij'
                          : targetIsExtern ? 'externe rij'
                          : 'pending-rij';
    // Target's type blijft zoals 't is — operator's klik bepaalt de richting.
    // Vanuit pending → extern? Dan blijft de externe. Andersom blijft de pending.
    const eindStatusLabel = targetIsKnsb  ? 'KNSB-account'
                          : targetIsExtern ? 'externe rij'
                          : 'pending-rij (wacht op KNSB-feed)';
    // Beschrijf wat er verhuist — combinatie van uitslagen, entries en transponders
    const onderdelen = [];
    if (source.aantal_uitslagen > 0) onderdelen.push(`<b>${source.aantal_uitslagen}</b> historische uitslag${source.aantal_uitslagen === 1 ? '' : 'en'}`);
    if (source.aantal_entries > 0)   onderdelen.push(`<b>${source.aantal_entries}</b> inschrijving${source.aantal_entries === 1 ? '' : 'en'}`);
    if (source.aantal_transponders > 0) onderdelen.push(`<b>${source.aantal_transponders}</b> transponder-registratie${source.aantal_transponders === 1 ? '' : 's'}`);
    const verhuistBeschrijving = onderdelen.length
        ? onderdelen.join(' + ')
        : '<i>(geen gekoppelde data)</i>';
    const ok = await _hpBevestigModal({
        titel: 'Samenvoegen — dezelfde persoon?',
        bericht: `<p>${verhuistBeschrijving} van <b>${escHtml(source.full_name)}</b>
                  verhuizen naar de ${targetIcoon} ${targetTypeLabel}
                  <code>${escHtml(targetLic)}</code>.</p>
                  <p style="margin:.4em 0">▸ De ${sourceIcoon} ${sourceTypeLabel}-rij
                  <code>${escHtml(pendingLic)}</code> wordt <b>verwijderd</b>.</p>
                  <p style="margin-top:.6em"><b>Eindresultaat:</b> één ${targetIcoon} ${eindStatusLabel}.</p>
                  <p style="color:#888;font-size:.85em">Deze actie kan niet
                  ongedaan gemaakt worden (alleen via her-import).</p>`,
        bevestigLabel: 'Ja, samenvoegen',
        annuleerLabel: 'Annuleer',
    });
    if (!ok) return;

    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'pending_link',
                pending_license: pendingLic,
                target_license: targetLic,
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        const stat = el('hp-pending-status');
        const conflictTxt = data.conflict_skip > 0
            ? ` (${data.conflict_skip} dubbel-conflict overgeslagen — doel had die rij al)`
            : '';
        stat.textContent = `✓ ${data.verhuisd} uitslagen verhuisd: ${data.pending_naam} → ${data.target_naam}${conflictTxt}`;
        stat.className = 'hp-status hp-status-ok';
        await _hpPendingLaad();
    } catch (e) {
        const stat = el('hp-pending-status');
        stat.textContent = '⚠ ' + e.message;
        stat.className = 'hp-status hp-status-fout';
    }
}

async function _hpPendingVerwijder(lic) {
    const source = _hpPendingData.find(p => p.license_key === lic);
    if (!source) return;
    // Wat wordt er allemaal verwijderd? — afhankelijk van het type. Pendings
    // hebben typisch alleen uitslagen, externen vooral entries + transponders.
    const onderdelen = [];
    if (source.aantal_uitslagen > 0)    onderdelen.push(`<b>${source.aantal_uitslagen}</b> historische uitslag${source.aantal_uitslagen === 1 ? '' : 'en'}`);
    if (source.aantal_entries > 0)      onderdelen.push(`<b>${source.aantal_entries}</b> inschrijving${source.aantal_entries === 1 ? '' : 'en'}`);
    if (source.aantal_transponders > 0) onderdelen.push(`<b>${source.aantal_transponders}</b> transponder-registratie${source.aantal_transponders === 1 ? '' : 's'}`);
    const wegBeschrijving = onderdelen.length ? onderdelen.join(' + ') : 'de rij';
    const typeLabel = source.is_extern ? 'externe' : 'pending';
    // Externen kunnen entries in een lopende wedstrijd hebben → extra waarschuwing
    const extraWaarschuwing = source.is_extern && source.aantal_entries > 0
        ? `<p style="color:#b71c1c;font-size:.9em">⚠ Deze rijder staat ingeschreven voor een wedstrijd.
           Bij verwijderen verdwijnt de inschrijving inclusief eventuele transponders.</p>`
        : '';
    const ok = await _hpBevestigModal({
        titel: `${source.is_extern ? 'Externe' : 'Pending'} verwijderen?`,
        bericht: `<p><b>Let op</b>: dit verwijdert ${wegBeschrijving}
                  van <b>${escHtml(source.full_name)}</b> permanent.</p>
                  ${extraWaarschuwing}
                  <p>Doe dit alleen als deze ${typeLabel}-rij per ongeluk is
                  aangemaakt. Anders: koppel hem aan een andere rij.</p>`,
        bevestigLabel: 'Ja, verwijder',
        annuleerLabel: 'Annuleer',
    });
    if (!ok) return;
    try {
        const res = await fetch('api/helpers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'pending_delete', license_key: lic }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        // Status-bericht samenstellen uit wat er werkelijk weg ging
        const wegParts = [];
        if (data.uitslagen_verwijderd > 0)    wegParts.push(`${data.uitslagen_verwijderd} uitslagen`);
        if (data.entries_verwijderd > 0)      wegParts.push(`${data.entries_verwijderd} entries`);
        if (data.transponders_verwijderd > 0) wegParts.push(`${data.transponders_verwijderd} transponders`);
        const stat = el('hp-pending-status');
        stat.textContent = `✓ ${typeLabel}-rij verwijderd${wegParts.length ? ' (incl. ' + wegParts.join(' + ') + ')' : ''}`;
        stat.className = 'hp-status hp-status-ok';
        await _hpPendingLaad();
    } catch (e) {
        const stat = el('hp-pending-status');
        stat.textContent = '⚠ ' + e.message;
        stat.className = 'hp-status hp-status-fout';
    }
}

// ── Coach-app wachtwoord (owner-only sectie in Helpers) ──────────────────────
async function _hpCoachAuthInit() {
    const stat   = el('hp-ca-status');
    const input  = el('hp-ca-input');
    const btnSet = el('hp-ca-btn-save');
    const btnClr = el('hp-ca-btn-clear');
    const meld   = el('hp-ca-melding');

    const laad = async () => {
        stat.textContent = 'Laden…';
        stat.className = 'hp-status';
        try {
            const r = await fetch('api/coach_auth.php?action=get');
            const d = await r.json();
            if (!r.ok || d.error) throw new Error(d.error || 'HTTP ' + r.status);
            if (d.password) {
                input.value = d.password;
                const datum = d.set_at ? new Date(d.set_at).toLocaleString('nl-NL') : '?';
                const door  = d.set_by_naam ? ` door ${escHtml(d.set_by_naam)}` : '';
                stat.innerHTML = `✓ Wachtwoord ingesteld${door} op ${escHtml(datum)}`;
                stat.className = 'hp-status hp-status-ok';
            } else {
                input.value = '';
                stat.innerHTML = '⚠ Geen wachtwoord — Coach-app is voor iedereen open';
                stat.className = 'hp-status hp-status-warn';
            }
        } catch (e) {
            stat.textContent = '⚠ Kon status niet ophalen: ' + e.message;
            stat.className = 'hp-status hp-status-fout';
        }
    };

    const tonen = (msg, soort) => {
        meld.innerHTML = `<div class="hp-status hp-status-${soort}">${escHtml(msg)}</div>`;
        setTimeout(() => { meld.innerHTML = ''; }, 4000);
    };

    btnSet.addEventListener('click', async () => {
        const pw = input.value.trim();
        if (!pw) {
            tonen('Wachtwoord is leeg — klik "Wissen" als je dat bedoelt', 'fout');
            return;
        }
        btnSet.disabled = true;
        try {
            const r = await fetch('api/coach_auth.php?action=set', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: pw }),
            });
            const d = await r.json();
            if (!r.ok || d.error) throw new Error(d.error || 'HTTP ' + r.status);
            tonen('✓ Wachtwoord opgeslagen', 'ok');
            await laad();
        } catch (e) {
            tonen('⚠ ' + e.message, 'fout');
        } finally {
            btnSet.disabled = false;
        }
    });

    btnClr.addEventListener('click', async () => {
        if (!await toonBevestigDialog(
            'Coach-wachtwoord wissen?\n\nDe Coach-app wordt dan weer open voor iedereen.',
            'Wachtwoord wissen', 'Wis', 'Annuleer'
        )) return;
        btnClr.disabled = true;
        try {
            const r = await fetch('api/coach_auth.php?action=set', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: null }),
            });
            const d = await r.json();
            if (!r.ok || d.error) throw new Error(d.error || 'HTTP ' + r.status);
            tonen('✓ Wachtwoord gewist — Coach-app is nu open', 'ok');
            await laad();
        } catch (e) {
            tonen('⚠ ' + e.message, 'fout');
        } finally {
            btnClr.disabled = false;
        }
    });

    await laad();
}
