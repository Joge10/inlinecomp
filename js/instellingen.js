/* InlineComp – Instellingen: organisaties & sponsors */

let orgs           = [];         // geladen organisatielijst
let actieveOrg     = null;       // huidig geselecteerde org
let orgLijstKaart  = null;       // actieve kaart in lijst
let actiefTab      = 'gegevens'; // actief tabblad
let _beheerLeesOnly = false;     // true als gebruiker geen schrijfrechten heeft voor beheer

// ── Initialisatie ──────────────────────────────────────────────────────────────

function initInstellingen() {
    _beheerLeesOnly = !magSchrijven('beheer');
    el('btn-nieuw-org').addEventListener('click', () => nieuweOrg());
    el('btn-org-opslaan').addEventListener('click', () => slaOrgOp());
    el('btn-org-verwijderen').addEventListener('click', () => verwijderOrg());
    el('btn-sponsor-add').addEventListener('click', () => voegSponsorRijToe());
    el('btn-org-poster')?.addEventListener('click', () => downloadPoster());
    window._tpDirty = false;
    window.markTpDirty = function() {
        window._tpDirty = true;
        const btn = el('btn-tp-opslaan');
        if (btn) btn.classList.add('btn-tp-dirty');
    };
    window.markTpClean = function() {
        window._tpDirty = false;
        const btn = el('btn-tp-opslaan');
        if (btn) btn.classList.remove('btn-tp-dirty');
    };
    window._isTpDirty = () => window._tpDirty;

    el('btn-tp-add')?.addEventListener('click', () => { voegTransponderRijToe(); markTpDirty(); });

    // Paginering
    el('tp-pag-eerste')?.addEventListener('click',   () => { _tpSyncAllePagina(); _tpPagina = 0; _tpToonPagina(); });
    el('tp-pag-vorige')?.addEventListener('click',   () => { _tpSyncAllePagina(); _tpPagina--; _tpToonPagina(); });
    el('tp-pag-volgende')?.addEventListener('click', () => { _tpSyncAllePagina(); _tpPagina++; _tpToonPagina(); });
    el('tp-pag-laatste')?.addEventListener('click',  () => { _tpSyncAllePagina(); _tpPagina = Math.max(0, Math.ceil(_tpGezichtLijst().length / _TP_PER_PAGINA) - 1); _tpToonPagina(); });

    // Sort via klikbare kolom-headers (Excel-stijl): 1e klik = asc, 2e klik = desc.
    // Wissel van kolom resetten we naar asc. Sync eerst zichtbare rijen (om edits
    // niet te verliezen), dan pagina 0 en opnieuw renderen.
    document.querySelectorAll('#org-tp-tabel th.tp-sortable').forEach(th => {
        th.addEventListener('click', () => {
            _tpSyncAllePagina();
            const kol = th.dataset.sort; // 'nr' of 'snr'
            if (_tpSort === kol) {
                _tpSortDir = _tpSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                _tpSort = kol;
                _tpSortDir = 'asc';
            }
            _tpPagina = 0;
            _tpToonPagina();
        });
    });
    // Filter-knop in de "Betaald"-header: klik opent een klein menu.
    // Keuzes: alle / uitgegeven (= toegewezen_snr gevuld) / betaald / niet_betaald.
    const filterBtn  = el('tp-filter-btn');
    const filterMenu = el('tp-filter-menu');
    // Positioneer het menu net onder de knop. position:fixed is gekoppeld aan
    // viewport, dus we hoeven ons niets aan te trekken van overflow-parents.
    const positioneerFilterMenu = () => {
        const r = filterBtn.getBoundingClientRect();
        filterMenu.style.top  = (r.bottom + 2) + 'px';
        // Rechter-uitlijnen met de knop — met minimale marge van 4px vanaf de linkerrand
        const menuBreed = filterMenu.offsetWidth || 140;
        filterMenu.style.left = Math.max(4, r.right - menuBreed) + 'px';
    };
    filterBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = !filterMenu.hidden;
        filterMenu.hidden = open;
        filterBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
        if (!open) positioneerFilterMenu();
    });
    // Herpositioneer bij scroll/resize zolang het menu openstaat
    window.addEventListener('scroll', () => { if (!filterMenu?.hidden) positioneerFilterMenu(); }, true);
    window.addEventListener('resize', () => { if (!filterMenu?.hidden) positioneerFilterMenu(); });
    filterMenu?.querySelectorAll('.tp-filter-opt').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            _tpSyncAllePagina();
            _tpFilter = btn.dataset.val;
            _tpPagina = 0;
            filterMenu.hidden = true;
            filterBtn.setAttribute('aria-expanded', 'false');
            _tpToonPagina();
        });
    });
    // Klik buiten menu → dicht
    document.addEventListener('click', (e) => {
        if (filterMenu && !filterMenu.hidden
            && !filterMenu.contains(e.target)
            && e.target !== filterBtn) {
            filterMenu.hidden = true;
            filterBtn?.setAttribute('aria-expanded', 'false');
        }
    });

    // Dirty bij elke wijziging in het transponders-tab
    el('org-tab-transponders')?.addEventListener('input', () => markTpDirty());
    el('org-tab-transponders')?.addEventListener('change', () => markTpDirty());

    // Print uitgeleverde transponders
    el('btn-tp-print')?.addEventListener('click', () => {
        _tpSyncAllePagina();
        printUitgeleverdeTransponders();
    });

    // Transponders eigen opslaan-knop
    el('btn-tp-opslaan')?.addEventListener('click', async () => {
        const btn    = el('btn-tp-opslaan');
        const status = el('tp-status');
        if (!actieveOrg?.id) return;
        btn.disabled = true;
        if (status) status.textContent = 'Opslaan…';
        try {
            const res = await fetch('api/organisaties.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_transponders',
                    organisatie_id: actieveOrg.id,
                    transponders: verzamelTransponders(),
                }),
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            // Update lokale cache
            actieveOrg.transponders = data.transponders ?? verzamelTransponders();
            markTpClean();
            if (status) { status.innerHTML = '<span class="tp-save-ok">✓ Opgeslagen</span>'; setTimeout(() => { status.textContent = ''; }, 2500); }
        } catch (e) {
            if (status) status.innerHTML = `<span style="color:#c00">⚠ ${escHtml(e.message)}</span>`;
        } finally {
            btn.disabled = false;
        }
    });

    // CSV transponder import — twee-staps: (1) bestand lezen + headers tonen, (2) mapping kiezen + importeren
    let _csvData = null; // {headers: string[], rows: string[][], sep: string}

    el('btn-tp-csv')?.addEventListener('click', () => el('tp-csv-file')?.click());
    el('tp-csv-file')?.addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            const tekst = reader.result;
            const sep = (tekst.split('\n')[0] || '').includes(';') ? ';' : ',';
            const regels = tekst.trim().split('\n').map(r => r.split(sep).map(v => v.trim().replace(/['"]/g, '')));

            // Zoek de header-rij: eerste rij met ≥3 niet-lege cellen
            let headerIdx = 0;
            for (let i = 0; i < Math.min(regels.length, 10); i++) {
                const nietLeeg = regels[i].filter(c => c !== '').length;
                if (nietLeeg >= 3) { headerIdx = i; break; }
            }
            const headers = regels[headerIdx];
            const dataRows = regels.slice(headerIdx + 1).filter(r => r.some(c => c !== ''));

            _csvData = { headers, rows: dataRows };

            // Toon mapping-dialoog
            const dbVelden = [
                { key: '',                label: '— overslaan —' },
                { key: 'intern_nummer',   label: 'Intern nummer (Nr)' },
                { key: 'transponder_code',label: 'Transponder code' },
                { key: 'eigendom',        label: 'Eigendom' },
                { key: 'toegewezen_snr',  label: 'Startnummer (Snr)' },
                { key: 'toegewezen_naam', label: 'Naam' },
                { key: 'categorie',       label: 'Categorie' },
                { key: 'betaald',         label: 'Betaald' },
            ];
            // Auto-detect: probeer headers te matchen
            const autoMap = {};
            const hints = {
                'vakje': 'intern_nummer', 'nr': 'intern_nummer', 'nummer': 'intern_nummer', 'intern_nummer': 'intern_nummer',
                'transponder': 'transponder_code', 'code': 'transponder_code', 'transponder_code': 'transponder_code',
                'eigendom': 'eigendom',
                'beennr': 'toegewezen_snr', 'snr': 'toegewezen_snr', 'startnummer': 'toegewezen_snr',
                'naam': 'toegewezen_naam', 'name': 'toegewezen_naam',
                'categorie': 'categorie', 'cat': 'categorie',
                'betaald': 'betaald',
            };
            const gebruiktVelden = new Set();
            headers.forEach((h, i) => {
                const lc = h.toLowerCase().trim();
                const match = hints[lc];
                if (match && !gebruiktVelden.has(match)) {
                    autoMap[i] = match;
                    gebruiktVelden.add(match);
                }
            });

            let mapHtml = `<div class="tp-csv-mapping" id="tp-csv-mapping">
                <div class="tp-csv-titel">CSV kolommen toewijzen</div>
                <div class="tp-csv-preview">Bestand: <strong>${escHtml(file.name)}</strong> — ${dataRows.length} rijen, ${headers.length} kolommen</div>
                <table class="tp-csv-map-tabel"><thead><tr><th>CSV kolom</th><th>Voorbeeld</th><th>Toewijzen aan</th></tr></thead><tbody>`;
            headers.forEach((h, i) => {
                const voorbeeld = dataRows[0]?.[i] ?? '';
                const opts = dbVelden.map(v =>
                    `<option value="${v.key}"${autoMap[i] === v.key ? ' selected' : ''}>${escHtml(v.label)}</option>`
                ).join('');
                mapHtml += `<tr>
                    <td><strong>${escHtml(h || `Kolom ${i+1}`)}</strong></td>
                    <td class="tp-csv-voorbeeld">${escHtml(voorbeeld)}</td>
                    <td><select class="inp tp-csv-map-sel" data-col="${i}">${opts}</select></td>
                </tr>`;
            });
            mapHtml += `</tbody></table>
                <div class="tp-csv-map-acties">
                    <button class="btn-primary" id="btn-csv-toepassen">✓ Importeer</button>
                    <button class="btn-secondary" id="btn-csv-annuleer">Annuleren</button>
                </div>
            </div>`;

            // Toon boven de tabel
            const wrap = el('org-tab-transponders');
            let bestaand = el('tp-csv-mapping');
            if (bestaand) bestaand.remove();
            wrap.insertAdjacentHTML('afterbegin', mapHtml);

            el('btn-csv-annuleer').addEventListener('click', () => {
                el('tp-csv-mapping')?.remove();
                _csvData = null;
            });

            el('btn-csv-toepassen').addEventListener('click', async () => {
                if (!_csvData) return;
                // Bouw mapping: kolom-index → db-veld
                const mapping = {};
                wrap.querySelectorAll('.tp-csv-map-sel').forEach(sel => {
                    if (sel.value) mapping[parseInt(sel.dataset.col)] = sel.value;
                });
                // Check minimaal intern_nummer + transponder_code
                const heeftNr   = Object.values(mapping).includes('intern_nummer');
                const heeftCode = Object.values(mapping).includes('transponder_code');
                if (!heeftNr || !heeftCode) {
                    toonBevestigDialog('Wijs minimaal "Intern nummer" en "Transponder code" toe.', 'Mapping onvolledig');
                    return;
                }

                // Sync huidige pagina vóór merge
                _tpSyncAllePagina();

                // Bouw lookup van bestaande data op intern_nummer
                const bestaandMap = new Map();
                _tpAlleData.forEach((t, i) => { if (t.intern_nummer) bestaandMap.set(String(t.intern_nummer), i); });

                let nieuw = 0, bijgewerkt = 0, overgeslagen = 0;
                for (const row of _csvData.rows) {
                    const obj = {};
                    for (const [colStr, veld] of Object.entries(mapping)) {
                        obj[veld] = row[parseInt(colStr)] ?? '';
                    }
                    if (!obj.intern_nummer || !obj.transponder_code) continue;

                    const nr = String(obj.intern_nummer);
                    const bestaandIdx = bestaandMap.get(nr);

                    if (bestaandIdx !== undefined) {
                        const bestaand = _tpAlleData[bestaandIdx];
                        if (bestaand.toegewezen_snr) {
                            // Toegewezen → vraag wat te doen
                            const toewijsInfo = [bestaand.toegewezen_snr, bestaand.toegewezen_naam, bestaand.categorie].filter(Boolean).join(' ');
                            const overschrijven = await toonBevestigDialog(
                                `Transponder #${nr} is toegewezen aan ${toewijsInfo || '(onbekend)'}.\nOverschrijven? De toewijzing wordt gewist.`,
                                'Transponder al toegewezen',
                                'Overschrijven', 'Overslaan'
                            );
                            if (!overschrijven) { overgeslagen++; continue; }
                            // Overschrijven: inventaris-velden updaten, toewijzing wissen
                            bestaand.transponder_code = obj.transponder_code;
                            bestaand.eigendom = obj.eigendom || bestaand.eigendom;
                            bestaand.toegewezen_snr = null;
                            bestaand.toegewezen_naam = null;
                            bestaand.person_license = null;
                            bestaand.categorie = null;
                            bestaand.betaald = 0;
                            bestaand.betaald_op = null;
                        } else {
                            // Niet toegewezen → gewoon overschrijven
                            bestaand.transponder_code = obj.transponder_code;
                            bestaand.eigendom = obj.eigendom || bestaand.eigendom;
                        }
                        bijgewerkt++;
                    } else {
                        // Nieuw nummer → toevoegen
                        _tpAlleData.push({
                            intern_nummer:    obj.intern_nummer,
                            transponder_code: obj.transponder_code,
                            eigendom:         obj.eigendom ?? '',
                            toegewezen_snr:   null,
                            toegewezen_naam:  null,
                            person_license:   null,
                            categorie:        null,
                            betaald:          0,
                            betaald_op:       null,
                        });
                        nieuw++;
                    }
                }

                _tpPagina = 0;
                _tpToonPagina();
                markTpDirty();

                el('tp-csv-mapping')?.remove();
                _csvData = null;
                const delen = [];
                if (nieuw) delen.push(`${nieuw} nieuw`);
                if (bijgewerkt) delen.push(`${bijgewerkt} bijgewerkt`);
                if (overgeslagen) delen.push(`${overgeslagen} overgeslagen`);
                const st = el('tp-status');
                if (st) { st.innerHTML = `<span class="tp-save-ok">${delen.join(', ')}. Klik Opslaan.</span>`; setTimeout(() => { st.textContent = ''; }, 5000); }
            });
        };
        reader.readAsText(file);
        e.target.value = '';
    });

    el('org-logo-file').addEventListener('change', e => {
        if (e.target.files[0]) uploadLogo('org', actieveOrg?.id, e.target.files[0]);
    });

    // Alias-knoppen (null-safe: werkt ook als index.php nog niet gesynced is)
    function on(id, evt, fn) {
        const e = el(id);
        if (e) e.addEventListener(evt, fn);
    }

    on('btn-alias-add', 'click', () => {
        el('alias-toevoeg-rij').style.display = '';
        el('btn-alias-add').style.display     = 'none';
        el('alias-nieuw-naam').value          = '';
        el('alias-nieuw-naam').focus();
    });
    on('btn-alias-ann', 'click', () => {
        el('alias-toevoeg-rij').style.display = 'none';
        el('btn-alias-add').style.display     = '';
    });
    on('btn-alias-ok',    'click',   () => voegAliasToe());
    on('alias-nieuw-naam','keydown', e => {
        if (e.key === 'Enter')  voegAliasToe();
        if (e.key === 'Escape') el('btn-alias-ann')?.click();
    });

    // Samenvoeg-knoppen
    on('btn-samenvoeg',     'click', () => toonSamenvoegPanel());
    on('btn-samenvoeg-ann', 'click', () => {
        el('samenvoeg-panel').style.display = 'none';
    });
    on('btn-samenvoeg-ok',  'click', () => voerSamenvoegUit());

    // Tab-navigatie
    document.querySelectorAll('.org-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => schakelTab(btn.dataset.tab));
    });

    laadOrgs();
}

// ── Tab-logica ────────────────────────────────────────────────────────────────

async function schakelTab(tab) {
    // Waarschuwing bij onopgeslagen transponders
    if (window._isTpDirty?.() && actiefTab === 'transponders' && tab !== 'transponders') {
        if (!await toonBevestigDialog('Er zijn onopgeslagen transponder-wijzigingen.\nDoorgaan zonder op te slaan?')) return;
        markTpClean();
    }
    actiefTab = tab;
    document.querySelectorAll('.org-tab-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.org-tab-content').forEach(c =>
        c.style.display = c.id === `org-tab-${tab}` ? '' : 'none');

    if (tab === 'wedstrijden') laadOrgWedstrijden();
    if (tab === 'klassementen') laadOrgKlassementen();
    if (tab === 'transponders') laadOrgTransponders();
    if (tab === 'banen' && typeof laadBanen === 'function') laadBanen();
}

async function laadOrgWedstrijden() {
    const lijst = el('org-wedstrijden-list');
    if (!actieveOrg || !lijst) return;

    lijst.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';

    // Wedstrijden in lokale DB ophalen (inclusief details voor wedstrijden die
    // niet meer in de KNSB-feed staan, zodat ze alsnog verwijderd kunnen worden)
    let dbComps = [];
    try {
        const res = await fetch('api/organisaties.php?action=wedstrijden&id=' + encodeURIComponent(actieveOrg.id));
        const data = await res.json();
        dbComps = Array.isArray(data) ? data : [];
    } catch { /* stil falen */ }
    const dbIds = new Set(dbComps.map(w => w.id));

    // Filter allWedstrijden op naam/email/alias van deze org
    const orgNamen = new Set([
        actieveOrg.naam?.toLowerCase(),
        actieveOrg.email?.toLowerCase(),
        ...(actieveOrg.aliassen ?? []).map(a => a.naam?.toLowerCase()),
    ].filter(Boolean));

    const matches = (allWedstrijden ?? []).filter(w => {
        const email = (w.settings?.contact?.email ?? '').toLowerCase().trim();
        const naam  = (w.settings?.contact?.organizationName
                    ?? w.settings?.contact?.organization ?? '').toLowerCase().trim();
        return orgNamen.has(email) || orgNamen.has(naam);
    });

    // Voeg DB-wedstrijden toe die NIET in de KNSB-feed zitten (oude wedstrijden,
    // of die handmatig aan deze org zijn gekoppeld). Normaliseer naar hetzelfde
    // object-shape als `matches` zodat ze gezamenlijk gerenderd kunnen worden.
    const matchIds   = new Set(matches.map(w => w.id));
    const extraFromDb = dbComps
        .filter(c => !matchIds.has(c.id))
        .map(c => ({
            id:     c.id,
            name:   c.name,
            starts: c.starts,
            _alleenDb: true,  // marker: niet in KNSB-feed
        }));
    const alleItems = [...matches, ...extraFromDb]
        // Sorteer ASC op starts (oudste eerst), wedstrijden zonder datum achteraan
        .sort((a, b) => {
            if (!a.starts && !b.starts) return 0;
            if (!a.starts) return 1;   // a zonder datum → einde
            if (!b.starts) return -1;  // b zonder datum → einde
            return new Date(a.starts).getTime() - new Date(b.starts).getTime();
        });

    if (!alleItems.length) {
        lijst.innerHTML = '<div class="status-msg info">Geen wedstrijden gevonden voor deze organisatie.</div>';
        return;
    }

    // dbComps map houden voor zichtbaarheids-status (komt alleen uit DB-kant)
    const dbCompsMap = new Map(dbComps.map(c => [c.id, c]));

    // Persistent legenda boven de wedstrijden-rijen — iconen-only-knoppen
    // zijn compact maar voor nieuwe gebruikers niet meteen leesbaar.
    // Hover-tooltips blijven werken; deze regel maakt het direct duidelijk
    // zonder dat de rij-knoppen weer breed worden.
    const legenda = `
        <div class="beheer-wedstrijd-legenda">
            <span class="bwl-titel">Acties per wedstrijd:</span>
            <span class="bwl-item"><b>🔒/⏳/👁</b> zichtbaarheid <small>(verborgen / binnenkort / live)</small></span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>📢</b> mededeling versturen</span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>📄</b> public-poster <small>(rijders/ouders)</small></span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>👥</b> coach-poster</span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>⚜</b> jury-wachtwoord</span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>🗑</b> verwijderen</span>
        </div>`;

    lijst.innerHTML = legenda + alleItems.map(w => {
        const inDb   = dbIds.has(w.id);
        const datum  = w.starts ? new Date(w.starts).toLocaleDateString('nl-NL', {day:'2-digit',month:'long',year:'numeric'}) : '—';
        const badge  = inDb && w._alleenDb
            ? '<span class="beheer-wedstrijd-badge badge-alleen-db">Alleen in database</span>'
            : inDb
                ? '<span class="beheer-wedstrijd-badge">In database</span>'
                : '<span class="beheer-wedstrijd-badge badge-extern">inschrijven.schaatsen.nl</span>';
        const dbRow      = dbCompsMap.get(w.id);
        const zicht      = inDb && !!Number(dbRow?.public_zichtbaar);
        const aankondigen = inDb && !!Number(dbRow?.public_aankondigen ?? 1);
        // 3-state zichtbaarheids-status:
        //   verborgen  → niet in /coach + /public dropdowns (stille voorbereiding)
        //   binnenkort → in dropdown als disabled "(binnenkort)"
        //   live       → selecteerbaar in /coach + /public
        const status = zicht ? 'live' : (aankondigen ? 'binnenkort' : 'verborgen');
        // Icon-only segmented control voor zichtbaarheid (tooltip vertelt
        // wat elke status doet). Bespaart horizontale ruimte want dit
        // staat op een rij met 4+ andere actie-knoppen.
        const zichtBtn = inDb
            ? `<div class="beheer-zicht-group" role="group" aria-label="Zichtbaarheid">
                 <button class="btn-sm beheer-zicht-knop ${status==='verborgen' ? 'is-actief beheer-zicht-uit' : ''}"
                         data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" data-status="verborgen"
                         title="Verborgen — wedstrijd verschijnt NIET in /coach + /public dropdowns (stille voorbereiding)">🔒</button>
                 <button class="btn-sm beheer-zicht-knop ${status==='binnenkort' ? 'is-actief beheer-zicht-soon' : ''}"
                         data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" data-status="binnenkort"
                         title="Binnenkort — in dropdown als disabled '(binnenkort)'">⏳</button>
                 <button class="btn-sm beheer-zicht-knop ${status==='live' ? 'is-actief beheer-zicht-aan' : ''}"
                         data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" data-status="live"
                         title="Live — selecteerbaar voor coach + publiek">👁</button>
               </div>`
            : '';
        return `<div class="beheer-wedstrijd-rij ${inDb ? 'in-db' : ''}">
            <div class="beheer-wedstrijd-info">
                <span class="beheer-wedstrijd-naam">${escHtml(w.name ?? w.title ?? w.id)}</span>
                <span class="beheer-wedstrijd-datum">${datum}</span>
                ${badge}
            </div>
            <div class="beheer-wedstrijd-acties">
                ${zichtBtn}
                ${inDb ? `<button class="btn-secondary btn-sm beheer-comp-meld beheer-icon-btn" data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" title="Mededelingen — verstuur push-bericht naar /coach + /public">📢</button>` : ''}
                ${inDb ? `<button class="btn-secondary btn-sm beheer-comp-poster beheer-icon-btn" data-id="${escHtml(w.id)}" data-app="public" title="Public-poster — download QR-poster voor rijders / ouders">📄</button>` : ''}
                ${inDb ? `<button class="btn-secondary btn-sm beheer-comp-poster beheer-icon-btn" data-id="${escHtml(w.id)}" data-app="coach" title="Coach-poster — download QR-poster voor coaches">👥</button>` : ''}
                ${inDb ? `<button class="btn-secondary btn-sm beheer-comp-jurypwd beheer-icon-btn ${Number(dbRow?.jury_password_set) ? 'is-actief' : ''}" data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" data-set="${Number(dbRow?.jury_password_set) ? '1' : '0'}" title="${Number(dbRow?.jury_password_set) ? 'Jury-wachtwoord INGESTELD — klik om te wijzigen of wissen' : 'Jury-wachtwoord NIET ingesteld — klik om in te stellen'}">⚜</button>` : ''}
                ${inDb ? `<button class="btn-del beheer-comp-del" data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" title="Wedstrijd verwijderen (vraagt om bevestiging)">🗑</button>` : ''}
            </div>
        </div>`;
    }).join('');

    lijst.querySelectorAll('.beheer-comp-del').forEach(btn => {
        btn.addEventListener('click', () => verwijderCompetitie(btn.dataset.id, btn.dataset.naam));
    });
    lijst.querySelectorAll('.beheer-comp-poster').forEach(btn => {
        btn.addEventListener('click', () => downloadPoster(btn.dataset.id, btn.dataset.app || 'public'));
    });
    lijst.querySelectorAll('.beheer-comp-meld').forEach(btn => {
        btn.addEventListener('click', () =>
            typeof openMeldingenModal === 'function'
                ? openMeldingenModal(btn.dataset.id, btn.dataset.naam)
                : null);
    });
    lijst.querySelectorAll('.beheer-zicht-knop').forEach(btn => {
        btn.addEventListener('click', () => zetWedstrijdStatus(btn));
    });
    lijst.querySelectorAll('.beheer-comp-jurypwd').forEach(btn => {
        btn.addEventListener('click', () => juryWachtwoordDialog(btn));
    });

    if (_beheerLeesOnly) pasSchrijfLockToe(lijst.closest('.org-tab-content') ?? lijst);
}

// ── Jury-wachtwoord per wedstrijd ─────────────────────────────────────────
// Klik op ⚜-knop → mini-dialog met nieuwe wachtwoord-invoer + status
// (ingesteld/niet ingesteld) + Wis-knop indien al ingesteld.
async function juryWachtwoordDialog(btn) {
    const compId = btn.dataset.id;
    const naam   = btn.dataset.naam;
    const set    = btn.dataset.set === '1';

    // Promptachtige dialoog op basis van toonBevestigDialog met HTML-body.
    // Niet de mooiste UX (geen masked-input) maar wachtwoord is gedeeld en
    // operator typt 'm zelf zonder schouder-mee-kijkers (= meeste gevallen).
    const html =
        `<p>Wedstrijd: <b>${escHtml(naam)}</b></p>` +
        `<p>Status: ${set
            ? '<b style="color:#2e7d32">✓ Wachtwoord ingesteld</b>'
            : '<b style="color:#b71c1c">✗ Geen wachtwoord ingesteld</b>'}</p>` +
        `<p style="margin-top:10px"><b>Nieuw jury-wachtwoord</b> (min. 6 tekens; leeg laten + Opslaan = wissen):</p>` +
        `<input type="text" id="jury-pwd-inp" class="inp" style="width:100%;font-size:1.05rem;padding:6px 10px;letter-spacing:.02em" ` +
        ` placeholder="${set ? '(typ nieuw wachtwoord, of laat leeg om te wissen)' : 'nieuw wachtwoord'}" autocomplete="off">` +
        `<p style="margin-top:8px;font-size:.85em;color:#666">` +
        `Het wachtwoord wordt versleuteld opgeslagen — je kunt het bestaande wachtwoord ` +
        `<em>niet</em> meer opvragen, alleen vervangen of wissen. Geef het zelf door aan de jury-leden.</p>`;
    const akkoord = await toonBevestigDialog(
        html, '⚜ Jury-wachtwoord instellen', 'Opslaan', 'Annuleren', { bodyIsHtml: true }
    );
    if (!akkoord) return;
    const inp = document.getElementById('jury-pwd-inp');
    const pwd = inp ? inp.value : '';
    try {
        const res = await fetch('api/jury_wachtwoord.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ competition_id: compId, password: pwd }),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || 'Fout bij opslaan');
        // UI-bijwerking: data-set + tooltip + actief-class
        const nuSet = !!data.jury_password_set;
        btn.dataset.set = nuSet ? '1' : '0';
        btn.classList.toggle('is-actief', nuSet);
        btn.title = nuSet
            ? 'Jury-wachtwoord INGESTELD — klik om te wijzigen of wissen'
            : 'Jury-wachtwoord NIET ingesteld — klik om in te stellen';
        // Korte feedback-toast als die functie bestaat
        if (typeof toonToast === 'function') {
            toonToast(nuSet ? '⚜ Jury-wachtwoord ingesteld' : '⚜ Jury-wachtwoord gewist', 'ok');
        }
    } catch (e) {
        alert('Opslaan mislukt: ' + e.message);
    }
}

// Zet de zichtbaarheids-status (3-state) van een wedstrijd via
// api/wedstrijd_zichtbaar.php. States:
//   verborgen  → komt NIET in /coach + /public dropdowns
//   binnenkort → in dropdown als disabled "(binnenkort)"
//   live       → selecteerbaar voor coach + publiek
async function zetWedstrijdStatus(btn) {
    const id     = btn.dataset.id;
    const naam   = btn.dataset.naam || '';
    const status = btn.dataset.status;

    // Bevestiging alleen bij Live (= echte publicatie); verbergen of
    // binnenkort-tonen zijn beide laag risico → direct doorvoeren.
    if (status === 'live') {
        if (!await toonBevestigDialog(
            `"${naam}" zichtbaar maken voor /coach + /public?\n\n` +
            'Coaches en publiek kunnen dan de wedstrijd-info, programma en uitslagen bekijken.',
            'Wedstrijd publiceren'
        )) return;
    }

    const origTekst = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Bezig…';
    try {
        const res = await fetch('api/wedstrijd_zichtbaar.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ competition_id: id, status }),
        });
        const data = await res.json();
        if (!data.ok) {
            toonBevestigDialog(data.error ?? 'Fout bij wijzigen zichtbaarheid', 'Fout');
            btn.innerHTML = origTekst;
            btn.disabled = false;
            return;
        }
        // Refresh de lijst zodat de active-states + tooltips kloppen
        await laadOrgWedstrijden();
    } catch (e) {
        toonBevestigDialog(e.message, 'Fout');
        btn.innerHTML = origTekst;
        btn.disabled = false;
    }
}

async function verwijderCompetitie(id, naam) {
    // Stap 1: keuze uitslag bewaren of ook verwijderen
    const uitslagKeuze = await new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <span class="modal-icon">⚠</span>
                    <span>Wedstrijd verwijderen</span>
                </div>
                <div class="modal-body">
                    <strong>${escHtml(naam)}</strong> verwijderen uit de database?<br><br>
                    Deelnemers, afstandsinstellingen en programma worden gewist.<br><br>
                    <strong>Wat moet er met vastgelegde uitslag- en klassementgegevens gebeuren?</strong>
                </div>
                <div class="modal-knoppen">
                    <button class="modal-btn modal-annuleer">Annuleren</button>
                    <button class="modal-btn modal-bewaar">Uitslag bewaren</button>
                    <button class="modal-btn modal-alles btn-danger">Ook uitslag verwijderen</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const sluit = v => { document.body.removeChild(overlay); resolve(v); };
        overlay.querySelector('.modal-annuleer').addEventListener('click', () => sluit(null));
        overlay.querySelector('.modal-bewaar' ).addEventListener('click', () => sluit('bewaar'));
        overlay.querySelector('.modal-alles'  ).addEventListener('click', () => sluit('alles'));
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(null); });
        overlay.querySelector('.modal-bewaar').focus();
    });

    if (!uitslagKeuze) return; // Annuleren

    try {
        const url = `api/import.php?id=${encodeURIComponent(id)}${uitslagKeuze === 'alles' ? '&uitslag=1' : ''}`;
        const res = await fetch(url, { method: 'DELETE' });
        const d   = await res.json();
        if (!res.ok) throw new Error(d.error ?? `HTTP ${res.status}`);
        if (typeof resetImportModule === 'function') resetImportModule(id);
        if (typeof laadWedstrijden === 'function') laadWedstrijden();
        laadOrgWedstrijden();
        laadOrgs();
    } catch(e) {
        toonBevestigDialog(e.message, 'Fout');
    }
}

function laadOrgKlassementen() {
    if (!actieveOrg) return;
    // Geef de actieve org door aan ranking.js en (her)initialiseer
    if (typeof setRankingOrgContext === 'function') {
        setRankingOrgContext(actieveOrg.id, actieveOrg.naam);
    }
}

// ── Organisaties laden ─────────────────────────────────────────────────────────

async function laadOrgs() {
    const lijst = el('org-list');
    try {
        const res  = await fetch('api/organisaties.php');
        const data = await res.json();
        if (data?.error) throw new Error(data.error);
        orgs = Array.isArray(data) ? data : [];
        renderOrgLijst();
        if (_beheerLeesOnly) {
            const btn = el('btn-nieuw-org');
            if (btn) { btn.disabled = true; btn.title = 'Geen schrijfrechten voor beheer'; }
            toonLeesAlleenBanner(el('page-instellingen'));
        }
    } catch(e) {
        lijst.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

function renderOrgLijst() {
    const lijst = el('org-list');
    if (!orgs.length) {
        lijst.innerHTML = '<div class="status-msg info">Nog geen organisaties.</div>';
        return;
    }
    lijst.innerHTML = '';
    orgLijstKaart = null;   // reset — oude DOM-referentie vervalt na herrender
    orgs.forEach(o => {
        const kaart = document.createElement('div');
        const isActief = actieveOrg?.id === o.id;
        kaart.className = 'org-kaart' + (isActief ? ' active' : '');

        const metaDelen = [];
        if (o.comp_count > 0) metaDelen.push(`${o.comp_count} wedstrijd${o.comp_count !== 1 ? 'en' : ''}`);
        if (o.sponsor_count > 0) metaDelen.push(`${o.sponsor_count} sponsor${o.sponsor_count !== 1 ? 's' : ''}`);
        if (o.aliassen?.length) metaDelen.push(`${o.aliassen.length} alias${o.aliassen.length !== 1 ? 'sen' : ''}`);

        kaart.innerHTML =
            `<div class="org-kaart-naam">${escHtml(o.naam)}</div>` +
            (metaDelen.length ? `<div class="org-kaart-meta">${escHtml(metaDelen.join(' · '))}</div>` : '');
        kaart.addEventListener('click', () => selecteerOrg(kaart, o.id));
        lijst.appendChild(kaart);

        // Herverbind pointer zodat selecteerOrg het nieuwe element kent
        if (isActief) orgLijstKaart = kaart;
    });
}

// ── Organisatie selecteren ─────────────────────────────────────────────────────

async function selecteerOrg(kaart, orgId) {
    // Dirty-check transponders
    if (window._isTpDirty?.()) {
        if (!await toonBevestigDialog('Er zijn onopgeslagen transponder-wijzigingen.\nDoorgaan zonder op te slaan?')) return;
        markTpClean();
    }

    if (orgLijstKaart) orgLijstKaart.classList.remove('active');
    kaart.classList.add('active');
    orgLijstKaart = kaart;

    try {
        const res = await fetch(`api/organisaties.php?id=${encodeURIComponent(orgId)}`);
        const org = await res.json();
        actieveOrg = org;
        vulOrgFormulier(org);
        // Als de actieve tab inhoud nodig heeft, laad die opnieuw
        if (actiefTab === 'wedstrijden')   laadOrgWedstrijden();
        if (actiefTab === 'klassementen')  laadOrgKlassementen();
        if (actiefTab === 'transponders')  laadOrgTransponders();
    } catch(e) {
        el('org-status')?.innerHTML && (el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`);
    }
}

function nieuweOrg() {
    if (orgLijstKaart) orgLijstKaart.classList.remove('active');
    orgLijstKaart = null;
    actieveOrg    = null;
    vulOrgFormulier(null);
}

// ── Formulier vullen ───────────────────────────────────────────────────────────

function vulOrgFormulier(org) {
    el('org-geen-selectie').style.display = 'none';
    el('org-tabs-wrap').style.display     = '';
    el('org-form-titel').textContent      = org ? org.naam : 'Nieuwe organisatie';
    // Nieuw → zet terug naar gegevens-tab
    if (!org) schakelTab('gegevens');
    el('org-naam').value               = org?.naam  ?? '';
    el('org-email').value              = org?.email ?? '';
    el('org-sportity').value           = org?.sportity_kanaal ?? '';
    el('org-status').innerHTML         = '';
    const isBestaand = !!org;
    el('btn-org-verwijderen').style.display             = isBestaand ? '' : 'none';
    if (el('samenvoeg-panel'))    el('samenvoeg-panel').style.display    = 'none';
    if (el('alias-toevoeg-rij'))  el('alias-toevoeg-rij').style.display  = 'none';
    if (el('btn-samenvoeg'))      el('btn-samenvoeg').style.display      = isBestaand ? '' : 'none';
    if (el('btn-alias-add'))      el('btn-alias-add').style.display      = isBestaand ? '' : 'none';

    // Logo
    const preview = el('org-logo-preview');
    const geen    = el('org-logo-geen');
    if (org?.logo_path) {
        preview.src           = org.logo_path + '?t=' + Date.now();
        preview.style.display = '';
        geen.style.display    = 'none';
    } else {
        preview.src           = '';
        preview.style.display = 'none';
        geen.style.display    = '';
    }
    el('org-logo-file').value = '';

    // Aliassen
    renderAliassen(org?.aliassen ?? []);

    // Sponsors
    renderSponsors(org?.sponsors ?? []);

    // Lees-alleen modus: schrijf-elementen per tab-inhoud disablen (tabs zelf blijven klikbaar)
    if (_beheerLeesOnly) {
        document.querySelectorAll('.org-tab-content').forEach(tabEl => pasSchrijfLockToe(tabEl));
    }
}

// ── Aliassen ──────────────────────────────────────────────────────────────────

function renderAliassen(aliassen) {
    const wrap = el('org-aliassen-list');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (!aliassen.length) {
        wrap.innerHTML = '<span class="alias-leeg">Geen aliassen — alle wedstrijden verschijnen onder één naam.</span>';
        return;
    }
    aliassen.forEach(a => {
        const tag = document.createElement('span');
        tag.className = 'alias-tag';
        tag.innerHTML =
            `${escHtml(a.naam)}` +
            `<button class="btn-del alias-del" data-id="${escHtml(a.id)}" title="Verwijderen">&#128465;</button>`;
        tag.querySelector('.alias-del').addEventListener('click', () => verwijderAlias(a.id));
        wrap.appendChild(tag);
    });
}

async function voegAliasToe() {
    if (!actieveOrg) return;
    const naam = el('alias-nieuw-naam').value.trim();
    if (!naam) return;

    el('btn-alias-ok').disabled = true;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'alias_toevoegen', org_id: actieveOrg.id, naam }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        actieveOrg = data;
        renderAliassen(data.aliassen ?? []);
        el('alias-toevoeg-rij').style.display = 'none';
        el('btn-alias-add').style.display     = '';
        await laadOrgs();
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    } finally {
        el('btn-alias-ok').disabled = false;
    }
}

async function verwijderAlias(aliasId) {
    if (!actieveOrg) return;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'alias_verwijderen', id: aliasId, org_id: actieveOrg.id }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        actieveOrg = data;
        renderAliassen(data.aliassen ?? []);
        await laadOrgs();
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Samenvoegen ───────────────────────────────────────────────────────────────

function toonSamenvoegPanel() {
    if (!actieveOrg) return;
    const kies = el('samenvoeg-kies');
    kies.innerHTML = '<option value="">— kies organisatie —</option>';
    orgs.filter(o => o.id !== actieveOrg.id).forEach(o => {
        const opt = document.createElement('option');
        opt.value       = o.id;
        opt.textContent = o.naam + (o.aliassen?.length ? ` (${o.aliassen.join(', ')})` : '');
        kies.appendChild(opt);
    });
    el('samenvoeg-naar-naam').textContent = actieveOrg.naam;
    el('samenvoeg-panel').style.display   = '';
}

async function voerSamenvoegUit() {
    if (!actieveOrg) return;
    const vanId = el('samenvoeg-kies').value;
    if (!vanId) {
        el('org-status').innerHTML = '<div class="status-msg error">Kies een organisatie om samen te voegen.</div>';
        return;
    }
    const vanOrg = orgs.find(o => o.id === vanId);
    if (!await toonBevestigDialog(
        `"${vanOrg?.naam}" samenvoegen met "${actieveOrg.naam}"? ` +
        `"${vanOrg?.naam}" verdwijnt en wordt als alias opgeslagen. ` +
        `Wedstrijden en sponsors worden overgenomen. Dit kan niet ongedaan worden gemaakt.`,
        'Organisaties samenvoegen')) return;

    el('btn-samenvoeg-ok').disabled = true;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:   'samenvoegen',
                van_id:   vanId,       // verdwijnt
                naar_id:  actieveOrg.id, // blijft
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        actieveOrg = data;
        el('samenvoeg-panel').style.display = 'none';
        vulOrgFormulier(data);
        await laadOrgs();
        el('org-status').innerHTML = '<div class="status-msg success">✓ Samengevoegd.</div>';
        setTimeout(() => { el('org-status').innerHTML = ''; }, 3000);
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    } finally {
        el('btn-samenvoeg-ok').disabled = false;
    }
}

// ── Sponsors ──────────────────────────────────────────────────────────────────

function renderSponsors(sponsors) {
    const wrap = el('org-sponsors-list');
    wrap.innerHTML = '';
    sponsors.forEach(s => voegSponsorRijToe(s));
}

function voegSponsorRijToe(sponsor = null) {
    const wrap = el('org-sponsors-list');
    const rij  = document.createElement('div');
    rij.className    = 'sponsor-rij';
    rij.dataset.id   = sponsor?.id   ?? '';
    rij.innerHTML = `
        <div class="sponsor-logo-wrap">
            ${sponsor?.logo_path
                ? `<img class="sponsor-logo-prev" src="${escHtml(sponsor.logo_path)}?t=${Date.now()}" alt="">`
                : '<span class="logo-geen">Geen logo</span>'}
        </div>
        <label class="btn-upload btn-small">&#128247;
            <input type="file" accept="image/*" class="sponsor-logo-file" style="display:none">
        </label>
        <input type="text" class="inp sponsor-naam" placeholder="Naam sponsor"
               value="${escHtml(sponsor?.naam ?? '')}">
        <input type="url"  class="inp sponsor-url"  placeholder="https://…"
               value="${escHtml(sponsor?.url ?? '')}">
        <button class="btn-del btn-sponsor-del" title="Verwijderen">&#128465;</button>`;

    rij.querySelector('.sponsor-logo-file').addEventListener('change', e => {
        if (!e.target.files[0]) return;
        const sId = rij.dataset.id;
        if (!sId) {
            el('org-status').innerHTML =
                '<div class="status-msg info">Sla eerst de organisatie op, dan kan je het logo uploaden.</div>';
            return;
        }
        uploadLogo('sponsor', sId, e.target.files[0], rij);
    });

    rij.querySelector('.btn-sponsor-del').addEventListener('click', async () => {
        const sId = rij.dataset.id;
        if (sId) {
            await fetch('api/organisaties.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_sponsor', id: sId }),
            });
        }
        rij.remove();
    });

    wrap.appendChild(rij);
}

// ── Transponders ─────────────────────────────────────────────────────────────

async function laadOrgTransponders() {
    if (!actieveOrg?.id) return;
    try {
        const res = await fetch(`api/organisaties.php?id=${encodeURIComponent(actieveOrg.id)}`);
        const org = await res.json();
        if (org?.transponders) {
            actieveOrg.transponders = org.transponders;
        }
    } catch { /* stil */ }
    renderTransponders(actieveOrg?.transponders ?? []);
}

let _tpAlleData = [];   // volledige dataset in geheugen
let _tpPagina   = 0;
let _tpSort    = 'nr';    // 'nr' of 'snr'
let _tpSortDir = 'asc';   // 'asc' of 'desc'
let _tpFilter  = 'alle';  // 'alle' | 'uitgegeven' | 'betaald' | 'niet_betaald'
const _TP_PER_PAGINA = 20;

// Bouw een afgeleide lijst met originele indices — zodat edits/deletes via
// `tr.dataset.idx` nog steeds de juiste rij in `_tpAlleData` raken, ook na
// filter/sort.
function _tpGezichtLijst() {
    const vorm = _tpAlleData.map((tp, origIdx) => ({ tp, origIdx }));
    // "betaald" impliceert ook "uitgegeven" — een niet-uitgegeven transponder
    // kan niet écht betaald zijn. Als oude data toch betaald=1 zonder snr heeft,
    // filteren we die hier weg (de sync-logica corrigeert het later alsnog).
    const isUitgegeven = t => !!(t.toegewezen_snr || t.toegewezen_naam);
    const isBetaald    = t => isUitgegeven(t) && parseInt(t.betaald) === 1;
    let gefilterd = vorm;
    if (_tpFilter === 'uitgegeven') {
        gefilterd = vorm.filter(x => isUitgegeven(x.tp));
    } else if (_tpFilter === 'betaald') {
        gefilterd = vorm.filter(x => isBetaald(x.tp));
    } else if (_tpFilter === 'niet_betaald') {
        gefilterd = vorm.filter(x => isUitgegeven(x.tp) && !isBetaald(x.tp));
    }
    const asNum = v => {
        const n = parseInt(String(v ?? '').replace(/[^\d-]/g, ''), 10);
        return isNaN(n) ? Number.MAX_SAFE_INTEGER : n;
    };
    gefilterd.sort((a, b) => {
        const va = _tpSort === 'snr' ? asNum(a.tp.toegewezen_snr) : asNum(a.tp.intern_nummer);
        const vb = _tpSort === 'snr' ? asNum(b.tp.toegewezen_snr) : asNum(b.tp.intern_nummer);
        return _tpSortDir === 'desc' ? (vb - va) : (va - vb);
    });
    return gefilterd;
}

function renderTransponders(transponders) {
    _tpAlleData = (transponders || []).map(t => ({ ...t }));
    _tpPagina = 0;
    _tpToonPagina();
}

function _tpToonPagina() {
    const body = el('org-tp-body');
    if (!body) return;
    body.innerHTML = '';

    // Sort-iconen in kolomheaders bijwerken: actieve kolom krijgt ▲/▼,
    // inactieve kolommen krijgen de neutrale ⇅.
    document.querySelectorAll('#org-tp-tabel th.tp-sortable').forEach(th => {
        const ico = th.querySelector('.tp-sort-ico');
        const actief = th.dataset.sort === _tpSort;
        if (ico) ico.textContent = actief ? (_tpSortDir === 'desc' ? '▼' : '▲') : '⇅';
        th.classList.toggle('tp-sort-actief', actief);
    });

    // Filter-knop: actief-indicator + tooltip met huidige keuze, en
    // het geselecteerde menu-item markeren.
    const filterBtn  = el('tp-filter-btn');
    const filterMenu = el('tp-filter-menu');
    if (filterBtn) {
        const labels = { alle: 'Alle', uitgegeven: 'Uitgegeven', betaald: 'Betaald', niet_betaald: 'Niet betaald' };
        filterBtn.classList.toggle('tp-filter-actief', _tpFilter !== 'alle');
        filterBtn.title = 'Filter: ' + (labels[_tpFilter] ?? 'Alle');
    }
    filterMenu?.querySelectorAll('.tp-filter-opt').forEach(btn => {
        btn.classList.toggle('actief', btn.dataset.val === _tpFilter);
    });

    const gezicht = _tpGezichtLijst();              // [{tp, origIdx}]
    const totaal  = gezicht.length;
    const paginas = Math.max(1, Math.ceil(totaal / _TP_PER_PAGINA));
    if (_tpPagina >= paginas) _tpPagina = paginas - 1;
    if (_tpPagina < 0) _tpPagina = 0;

    const start = _tpPagina * _TP_PER_PAGINA;
    const slice = gezicht.slice(start, start + _TP_PER_PAGINA);

    slice.forEach(({ tp, origIdx }) => {
        const idx = origIdx; // index in _tpAlleData (ongeacht sort/filter)
        const tr = document.createElement('tr');
        tr.className = 'org-tp-rij';
        if (parseInt(tp.geblokkeerd) === 1) tr.classList.add('tp-rij-geblokkeerd');
        tr.dataset.idx = idx;
        const isGeblokkeerd = parseInt(tp.geblokkeerd) === 1;
        tr.innerHTML =
            `<td><input type="text" class="inp tp-inp tp-nr" value="${escHtml(tp.intern_nummer ?? '')}" placeholder="#"></td>` +
            `<td><input type="text" class="inp tp-inp tp-code" value="${escHtml(tp.transponder_code ?? '')}" placeholder="KS-..."></td>` +
            `<td><input type="text" class="inp tp-inp tp-eigendom" value="${escHtml(tp.eigendom ?? '')}" placeholder="Org/Huur"></td>` +
            `<td class="tp-ro">${tp.toegewezen_snr ?? '—'}<input type="hidden" class="tp-snr" value="${tp.toegewezen_snr ?? ''}"></td>` +
            `<td class="tp-ro">${escHtml(tp.toegewezen_naam ?? '—')}<input type="hidden" class="tp-naam" value="${escHtml(tp.toegewezen_naam ?? '')}"></td>` +
            `<td class="tp-ro">${escHtml(tp.person_license ?? '—')}<input type="hidden" class="tp-license" value="${escHtml(tp.person_license ?? '')}"></td>` +
            `<td class="tp-ro">${escHtml(tp.categorie ?? '—')}<input type="hidden" class="tp-cat" value="${escHtml(tp.categorie ?? '')}"></td>` +
            // Niet-uitgegeven transponders kunnen niet betaald zijn — forceer
            // leeg vinkje, ongeacht wat er in de data staat.
            `<td class="tp-td-betaald"><input type="checkbox" class="tp-betaald" ${tp.toegewezen_snr && parseInt(tp.betaald) === 1 ? 'checked' : ''} ${tp.toegewezen_snr ? '' : 'disabled'} title="${tp.toegewezen_snr ? '' : 'Eerst toewijzen via Import'}"></td>` +
            `<td class="tp-ro tp-td-datum">${tp.toegewezen_snr && parseInt(tp.betaald) === 1 && tp.betaald_op ? tp.betaald_op : '—'}<input type="hidden" class="tp-betaald-op" value="${escHtml(tp.toegewezen_snr && parseInt(tp.betaald) === 1 && tp.betaald_op ? tp.betaald_op : '')}"></td>` +
            `<td class="tp-td-acties">` +
                `<input type="hidden" class="tp-geblokkeerd" value="${isGeblokkeerd ? 1 : 0}">` +
                `<button class="btn-icon tp-blokkeer ${isGeblokkeerd ? 'tp-blokkeer-actief' : ''}" title="${isGeblokkeerd ? 'Geblokkeerd — klik om weer beschikbaar te maken' : 'Blokkeren — transponder blijft in de lijst maar kan niet meer worden toegewezen (kapot/zoek)'}">${isGeblokkeerd ? '&#128274;' : '&#128275;'}</button>` +
                `<button class="btn-icon tp-vrijgeven" ${(tp.toegewezen_snr || tp.toegewezen_naam) ? '' : 'disabled'} title="Toewijzing vrijgeven (rijder heeft transponder ingeleverd) — transponder blijft in de lijst">&#8630;</button>` +
                `<button class="btn-del tp-del" title="Verwijderen uit lijst (transponder is kwijt/stuk)">&#128465;</button>` +
            `</td>`;
        // Sync wijzigingen terug naar _tpAlleData
        tr.querySelectorAll('input').forEach(inp => {
            inp.addEventListener('change', () => { _tpSyncRij(tr); if (typeof markTpDirty === 'function') markTpDirty(); });
        });
        // Betaald checkbox → auto-datum
        tr.querySelector('.tp-betaald').addEventListener('change', (e) => {
            const datumCel = tr.querySelector('.tp-td-datum');
            const datumInp = tr.querySelector('.tp-betaald-op');
            if (e.target.checked) {
                const vandaag = new Date().toISOString().slice(0, 10);
                datumCel.firstChild.textContent = vandaag;
                datumInp.value = vandaag;
            } else {
                datumCel.firstChild.textContent = '—';
                datumInp.value = '';
            }
            _tpSyncRij(tr);
        });
        tr.querySelector('.tp-del').addEventListener('click', () => {
            _tpAlleData.splice(parseInt(tr.dataset.idx), 1);
            if (typeof markTpDirty === 'function') markTpDirty();
            _tpToonPagina();
        });
        // Blokkeer/deblokkeer toggle. Geblokkeerde transponders blijven in de
        // tabel staan voor administratie, maar worden in vergelijk.php
        // uitgefilterd zodat ze niet meer in dropdowns voor toewijzing
        // verschijnen. Geen confirm — toggle is reversibel.
        tr.querySelector('.tp-blokkeer')?.addEventListener('click', () => {
            const idx = parseInt(tr.dataset.idx);
            if (isNaN(idx) || !_tpAlleData[idx]) return;
            _tpAlleData[idx].geblokkeerd = parseInt(_tpAlleData[idx].geblokkeerd) === 1 ? 0 : 1;
            if (typeof markTpDirty === 'function') markTpDirty();
            _tpToonPagina();
        });
        // Vrijgeven: laat de transponder in de lijst maar wis de toewijzing.
        // Gebruikt als een rijder zijn transponder fysiek heeft ingeleverd en
        // de transponder weer beschikbaar is voor uitgifte aan iemand anders.
        tr.querySelector('.tp-vrijgeven')?.addEventListener('click', () => {
            const idx = parseInt(tr.dataset.idx);
            if (isNaN(idx) || !_tpAlleData[idx]) return;
            const huidig = _tpAlleData[idx];
            const info = [huidig.toegewezen_snr, huidig.toegewezen_naam, huidig.categorie].filter(Boolean).join(' ');
            if (!confirm(`Toewijzing vrijgeven?\n\nTransponder ${huidig.transponder_code || ''} is nu toegewezen aan ${info || '(onbekend)'}.\nNa vrijgeven komt hij weer beschikbaar; de transponder zelf blijft in de lijst.`)) return;
            _tpAlleData[idx] = {
                ...huidig,
                toegewezen_snr:  null,
                toegewezen_naam: null,
                person_license:  null,
                categorie:       null,
                betaald:         0,
                betaald_op:      null,
            };
            if (typeof markTpDirty === 'function') markTpDirty();
            _tpToonPagina();
        });
        body.appendChild(tr);
    });

    // Paginering bijwerken
    const info = el('tp-pag-info');
    if (info) info.textContent = totaal > 0 ? `${start+1}–${Math.min(start+_TP_PER_PAGINA, totaal)} van ${totaal}` : 'Geen transponders';
    const btnE = el('tp-pag-eerste'), btnV = el('tp-pag-vorige');
    const btnN = el('tp-pag-volgende'), btnL = el('tp-pag-laatste');
    if (btnE) btnE.disabled = _tpPagina === 0;
    if (btnV) btnV.disabled = _tpPagina === 0;
    if (btnN) btnN.disabled = _tpPagina >= paginas - 1;
    if (btnL) btnL.disabled = _tpPagina >= paginas - 1;
}

function _tpSyncRij(tr) {
    const idx = parseInt(tr.dataset.idx);
    if (isNaN(idx) || !_tpAlleData[idx]) return;
    const snr = tr.querySelector('.tp-snr')?.value
        ? parseInt(tr.querySelector('.tp-snr').value) : null;
    // Niet-uitgegeven transponders kunnen niet betaald zijn — forceer 0
    // zodat oude/rotte data vanzelf wordt gecorrigeerd bij opslag.
    const betaald = snr && tr.querySelector('.tp-betaald')?.checked ? 1 : 0;
    _tpAlleData[idx] = {
        intern_nummer:    tr.querySelector('.tp-nr')?.value.trim() || '',
        transponder_code: tr.querySelector('.tp-code')?.value.trim() || '',
        eigendom:         tr.querySelector('.tp-eigendom')?.value.trim() || null,
        toegewezen_snr:   snr,
        toegewezen_naam:  tr.querySelector('.tp-naam')?.value.trim() || null,
        person_license:   tr.querySelector('.tp-license')?.value.trim() || null,
        categorie:        tr.querySelector('.tp-cat')?.value.trim() || null,
        betaald:          betaald,
        betaald_op:       betaald ? (tr.querySelector('.tp-betaald-op')?.value || null) : null,
        geblokkeerd:      parseInt(tr.querySelector('.tp-geblokkeerd')?.value || '0') === 1 ? 1 : 0,
    };
}

// Sync alle zichtbare rijen vóór paginawissel of opslaan
function _tpSyncAllePagina() {
    (el('org-tp-body')?.querySelectorAll('.org-tp-rij') ?? []).forEach(tr => _tpSyncRij(tr));
}

// ── Afdruk: uitgeleverde transponders ─────────────────────────────────────
// Print alle transponders waarvan toegewezen_snr gevuld is (= uitgeleverd).
// Gebruikt voor overzicht aan de balie / archief.
function printUitgeleverdeTransponders() {
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const uitgeleverd = (_tpAlleData || []).filter(tp =>
        tp.toegewezen_snr !== null && tp.toegewezen_snr !== undefined && tp.toegewezen_snr !== ''
    );

    if (!uitgeleverd.length) {
        toonBevestigDialog('Geen uitgeleverde transponders om af te drukken.', 'Info');
        return;
    }

    // Sorteer op startnummer (= logisch voor de balie)
    uitgeleverd.sort((a, b) => (Number(a.toegewezen_snr) || 0) - (Number(b.toegewezen_snr) || 0));

    const orgNaam = actieveOrg?.naam ?? '';
    const datum   = new Date().toLocaleString('nl-NL',
        { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    const { orgLogoHtml } = (typeof bouwOrgHeaderFooter === 'function')
        ? bouwOrgHeaderFooter(esc)
        : { orgLogoHtml: '' };

    let rows = '';
    uitgeleverd.forEach((tp, i) => {
        const betaald = parseInt(tp.betaald) === 1;
        const betaaldTxt = betaald ? '✓' : '✗';
        const betaaldCls = betaald ? 'ja' : 'nee';
        const datumTxt = betaald && tp.betaald_op ? tp.betaald_op : '—';
        rows += `<tr class="${i % 2 === 1 ? 'z' : ''}">
            <td class="c">${esc(tp.intern_nummer ?? '')}</td>
            <td>${esc(tp.transponder_code ?? '')}</td>
            <td class="c">${esc(tp.toegewezen_snr ?? '')}</td>
            <td>${esc(tp.toegewezen_naam ?? '')}</td>
            <td class="c">${esc(tp.categorie ?? '')}</td>
            <td>${esc(tp.eigendom ?? '')}</td>
            <td class="c ${betaaldCls}">${betaaldTxt}</td>
            <td class="c">${esc(datumTxt)}</td>
        </tr>`;
    });

    const htmlDoc = `<!DOCTYPE html><html lang="nl">
<head><meta charset="UTF-8">
<title>Uitgeleverde transponders${orgNaam ? ' – ' + esc(orgNaam) : ''}</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9.5pt;margin:.8cm 1.2cm 1.2cm;color:#111}
header{display:flex;justify-content:space-between;align-items:flex-start;gap:4mm;margin-bottom:3mm}
.hdr-links{flex:1;min-width:0}
.hdr-titel{font-size:14pt;font-weight:700;margin-bottom:1mm}
.hdr-meta{font-size:9pt;color:#555}
.hdr-rechts{flex-shrink:0}
hr{border:none;border-top:2px solid #1a3a5c;margin:2mm 0 4mm}
table{width:100%;border-collapse:collapse;font-size:9.5pt}
thead tr{background:#1a3a5c;color:#fff}
th{padding:4px 7px;text-align:left;font-size:8.5pt;font-weight:600;letter-spacing:.02em}
td{padding:4px 7px;border-bottom:1px solid #eee;vertical-align:middle}
.c{text-align:center}
tr.z td{background:#f9f9f9}
td.ja{color:#2a7a2a;font-weight:700}
td.nee{color:#c00;font-weight:700}
footer{margin-top:5mm;border-top:1px solid #ccc;padding-top:2mm;font-size:7.5pt;color:#888;
       display:flex;justify-content:space-between}
@page{size:A4 portrait;margin:1cm}
@media print{
  tr{page-break-inside:avoid}
  thead{display:table-header-group}
}
</style></head>
<body>
<header>
  <div class="hdr-links">
    <div class="hdr-titel">Uitgeleverde transponders</div>
    <div class="hdr-meta">${esc(orgNaam)} · ${uitgeleverd.length} transponder${uitgeleverd.length !== 1 ? 's' : ''} in gebruik</div>
  </div>
  <div class="hdr-rechts">${orgLogoHtml}</div>
</header>
<hr>
<table>
  <thead><tr>
    <th class="c">Nr</th>
    <th>Transponder</th>
    <th class="c">Startnr</th>
    <th>Naam</th>
    <th class="c">Cat</th>
    <th>Eigendom</th>
    <th class="c">Betaald</th>
    <th class="c">Betaald op</th>
  </tr></thead>
  <tbody>${rows}</tbody>
</table>
<footer>
  <span>Afgedrukt: ${esc(datum)}</span>
  <span>Totaal: ${uitgeleverd.length}</span>
</footer>
<script>window.addEventListener('load', () => { window.focus(); window.print(); window.close(); });<\/script>
</body></html>`;

    const win = window.open('', '_blank');
    if (!win) { toonBevestigDialog('Pop-up geblokkeerd — sta pop-ups toe.', 'Afdrukken'); return; }
    win.document.write(htmlDoc);
    win.document.close();
}

function voegTransponderRijToe(tp = null) {
    _tpSyncAllePagina();
    _tpAlleData.push({
        intern_nummer:    tp?.intern_nummer ?? '',
        transponder_code: tp?.transponder_code ?? '',
        eigendom:         tp?.eigendom ?? null,
        toegewezen_snr:   tp?.toegewezen_snr ?? null,
        toegewezen_naam:  tp?.toegewezen_naam ?? null,
        person_license:   tp?.person_license ?? null,
        categorie:        tp?.categorie ?? null,
        betaald:          tp?.betaald ? 1 : 0,
        geblokkeerd:      tp?.geblokkeerd ? 1 : 0,
    });
    // Ga naar laatste pagina waar het nieuwe item staat
    _tpPagina = Math.floor((_tpAlleData.length - 1) / _TP_PER_PAGINA);
    _tpToonPagina();
    // Focus op het nieuwe nr-veld
    const rijen = el('org-tp-body')?.querySelectorAll('.org-tp-rij');
    if (rijen?.length) rijen[rijen.length - 1].querySelector('.tp-nr')?.focus();
}

function verzamelTransponders() {
    _tpSyncAllePagina();
    return _tpAlleData
        .filter(t => t.intern_nummer && t.transponder_code)
        .map(t => ({
            intern_nummer:    t.intern_nummer,
            transponder_code: t.transponder_code,
            eigendom:         t.eigendom || null,
            toegewezen_snr:   t.toegewezen_snr || null,
            toegewezen_naam:  t.toegewezen_naam || null,
            person_license:   t.person_license || null,
            categorie:        t.categorie || null,
            betaald:          t.betaald ? 1 : 0,
            betaald_op:       t.betaald_op || null,
            geblokkeerd:      t.geblokkeerd ? 1 : 0,
        }));
}

// ── Opslaan ───────────────────────────────────────────────────────────────────

async function slaOrgOp() {
    const naam = el('org-naam').value.trim();
    if (!naam) {
        el('org-status').innerHTML = '<div class="status-msg error">Naam is verplicht.</div>';
        return;
    }

    const sponsorRijen = [...el('org-sponsors-list').querySelectorAll('.sponsor-rij')];
    const sponsors = sponsorRijen
        .map(r => ({
            id:   r.dataset.id || null,
            naam: r.querySelector('.sponsor-naam').value.trim(),
            url:  r.querySelector('.sponsor-url').value.trim() || null,
        }))
        .filter(s => s.naam);

    const body = {
        action:   'save',
        id:       actieveOrg?.id ?? null,
        naam,
        email:    el('org-email').value.trim() || null,
        sportity_kanaal: el('org-sportity').value.trim() || null,
        sponsors,
    };

    el('btn-org-opslaan').disabled = true;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        });
        const opgeslagen = await res.json();
        if (opgeslagen.error) throw new Error(opgeslagen.error);

        actieveOrg = opgeslagen;
        await laadOrgs();
        vulOrgFormulier(opgeslagen);
        el('org-status').innerHTML = '<div class="status-msg success">✓ Opgeslagen.</div>';
        setTimeout(() => { el('org-status').innerHTML = ''; }, 3000);
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    } finally {
        el('btn-org-opslaan').disabled = false;
    }
}

// ── Verwijderen ───────────────────────────────────────────────────────────────

async function verwijderOrg() {
    if (!actieveOrg) return;
    if (!await toonBevestigDialog(`Organisatie "${actieveOrg.naam}" verwijderen? Dit kan niet ongedaan worden gemaakt.`, 'Organisatie verwijderen')) return;

    await fetch('api/organisaties.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'delete', id: actieveOrg.id }),
    });

    actieveOrg  = null;
    orgLijstKaart = null;
    el('org-form-panel').style.display = 'none';
    await laadOrgs();
}

// ── Logo uploaden ──────────────────────────────────────────────────────────────

async function uploadLogo(type, id, file, sponsorRij = null) {
    const statusEl = el('org-status');
    statusEl.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Logo uploaden…</div>';

    const fd = new FormData();
    fd.append('type', type);
    fd.append('id',   id);
    fd.append('logo', file);

    try {
        const res  = await fetch('api/upload.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        if (type === 'org') {
            const prev = el('org-logo-preview');
            prev.src           = data.path + '?t=' + Date.now();
            prev.style.display = '';
            el('org-logo-geen').style.display = 'none';
            if (actieveOrg) actieveOrg.logo_path = data.path;
        } else if (sponsorRij) {
            const wrap = sponsorRij.querySelector('.sponsor-logo-wrap');
            wrap.innerHTML = `<img class="sponsor-logo-prev" src="${escHtml(data.path)}?t=${Date.now()}" alt="">`;
        }

        statusEl.innerHTML = '<div class="status-msg success">✓ Logo opgeslagen.</div>';
        setTimeout(() => { statusEl.innerHTML = ''; }, 3000);
    } catch(e) {
        statusEl.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Promotie-poster downloaden ────────────────────────────────────────────────
// Zonder `compId` → generieke org-poster. Met `compId` → poster voor specifieke
// wedstrijd met juiste QR-url en wedstrijd-info. appType bepaalt of de QR
// naar /public/ of /coach/ wijst en de tekst-variant ('public' default,
// 'coach' voor de coach-app-poster). Endpoint is api/poster.php.
async function downloadPoster(compId = null, appType = 'public') {
    if (!actieveOrg?.id) return;

    // Visuele feedback op de knop die is geklikt
    const btn = compId
        ? document.querySelector(`.beheer-comp-poster[data-id="${compId}"][data-app="${appType}"]`)
        : el('btn-org-poster');
    const origLabel = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Bezig…'; }

    try {
        const params = new URLSearchParams({ org_id: actieveOrg.id, app: appType });
        if (compId) params.set('competition_id', compId);

        const res = await fetch('api/poster.php?' + params.toString());

        // Fout-responses komen als JSON, PDF's als application/pdf
        const ct = res.headers.get('content-type') ?? '';
        if (!res.ok || !ct.startsWith('application/pdf')) {
            const txt = await res.text();
            let msg = 'Poster genereren mislukt.';
            try { msg = (JSON.parse(txt).error) ?? msg; } catch { /* raw text */ }
            throw new Error(msg);
        }

        const blob = await res.blob();
        const naam = (res.headers.get('content-disposition') ?? '')
            .match(/filename="([^"]+)"/)?.[1] ?? 'poster.pdf';

        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href    = url;
        a.download = naam;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (e) {
        alert('Kon poster niet downloaden:\n\n' + e.message);
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = origLabel; }
    }
}

// ── Initialiseer wanneer pagina actief wordt ───────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const items = document.querySelectorAll('.nav-item[data-page]');
    items.forEach(item => {
        item.addEventListener('click', () => {
            if (item.dataset.page === 'instellingen' && !orgs.length) {
                initInstellingen();
            }
        });
    });
});
