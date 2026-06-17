/* InlineComp – Instellingen: organisaties & sponsors */

let orgs           = [];         // geladen organisatielijst
let actieveOrg     = null;       // huidig geselecteerde org
let orgLijstKaart  = null;       // actieve kaart in lijst
let actiefTab      = 'gegevens'; // actief tabblad
let _beheerLeesOnly      = false; // true als geen schrijfrechten voor 'lichte' beheer-acties
let _beheerUitgebreidOk  = false; // true bij owner/admin (jury-wachtwoord, delete)

// ── Initialisatie ──────────────────────────────────────────────────────────────

function initInstellingen() {
    // 'beheer_basic' = owner+admin+planner (zichtbaarheid, mededelingen, posters)
    // 'beheer'       = owner+admin (jury-wachtwoord, wedstrijd-verwijderen)
    _beheerLeesOnly     = !magSchrijven('beheer_basic');
    _beheerUitgebreidOk =  magSchrijven('beheer');
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
    // SCOPED queries — alleen tabs binnen #page-instellingen aanraken.
    // Eerder ongescooped: zette ook #sys-tab-helpers / #sys-tab-uploads
    // (dezelfde .org-tab-content class) op display:none, waardoor die
    // tabs leeg leken na een instellingen-actie. Symptoom: blanco
    // Systeem→Helpers-tab.
    document.querySelectorAll('#page-instellingen .org-tab-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('#page-instellingen .org-tab-content').forEach(c =>
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
            <span class="bwl-titel">Acties:</span>
            <span class="bwl-item"><b>🔒/⏳/👁</b> zichtbaarheid <small>(verborgen / binnenkort / live)</small></span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>📢</b> mededeling versturen</span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>📄</b> posters <small>(public/coach/check)</small></span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>⚖/🖨</b> protokol <small>(data / genereren)</small></span>
            <span class="bwl-sep">·</span>
            <span class="bwl-item"><b>🔑</b> jury-wachtwoord</span>
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
                ${inDb ? `<button class="btn-secondary btn-sm beheer-comp-poster beheer-icon-btn" data-id="${escHtml(w.id)}" title="Posters — download QR-poster voor public, coach of check (kies type + taal in dialog)">📄</button>` : ''}
                ${inDb ? `<div class="beheer-rapport-group" role="group" aria-label="Protokol">
                    <button class="btn-secondary btn-sm beheer-comp-protokol beheer-icon-btn" data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" title="Protokol-data — officials + nawoord voor het protokol">⚖</button>
                    <button class="btn-secondary btn-sm beheer-comp-print beheer-icon-btn" data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}" title="Protokol genereren — print of opslaan als PDF (via browser-print)">🖨</button>
                </div>` : ''}
                ${inDb ? `<button class="btn-secondary btn-sm beheer-comp-jurypwd beheer-icon-btn ${Number(dbRow?.jury_password_set) ? 'is-actief' : ''}"
                    data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}"
                    data-set="${Number(dbRow?.jury_password_set) ? '1' : '0'}"
                    ${_beheerUitgebreidOk ? '' : 'disabled'}
                    title="${_beheerUitgebreidOk
                        ? (Number(dbRow?.jury_password_set)
                            ? 'Jury-wachtwoord INGESTELD — klik om te wijzigen of wissen'
                            : 'Jury-wachtwoord NIET ingesteld — klik om in te stellen')
                        : 'Alleen owner/admin mag het jury-wachtwoord wijzigen'}">🔑</button>` : ''}
                ${inDb ? `<button class="btn-del beheer-comp-del"
                    data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}"
                    ${_beheerUitgebreidOk ? '' : 'disabled'}
                    title="${_beheerUitgebreidOk
                        ? 'Wedstrijd verwijderen (vraagt om bevestiging)'
                        : 'Alleen owner/admin mag wedstrijden verwijderen'}">🗑</button>` : ''}
            </div>
        </div>`;
    }).join('');

    lijst.querySelectorAll('.beheer-comp-del').forEach(btn => {
        btn.addEventListener('click', () => verwijderCompetitie(btn.dataset.id, btn.dataset.naam));
    });
    lijst.querySelectorAll('.beheer-comp-poster').forEach(btn => {
        btn.addEventListener('click', () => downloadPoster(btn.dataset.id));
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
    lijst.querySelectorAll('.beheer-comp-print').forEach(btn => {
        btn.addEventListener('click', () => printWedstrijdrapport(btn.dataset.id, btn.dataset.naam));
    });
    lijst.querySelectorAll('.beheer-comp-protokol').forEach(btn => {
        btn.addEventListener('click', () => protokolDataDialog(btn.dataset.id, btn.dataset.naam));
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
    // We slaan de waarde in een buiten-scope-variabele op via de onOpened-hook
    // omdat toonBevestigDialog de overlay verwijdert vóór de promise resolved.
    // Zonder dit zou de input al uit de DOM zijn als we 'm nadien zochten en
    // bleef pwd altijd '' (= wissen — bug die hier het instellen blokkeerde).
    let pwd = '';
    const akkoord = await toonBevestigDialog(
        html, '🔑 Jury-wachtwoord instellen', 'Opslaan', 'Annuleren',
        {
            bodyIsHtml: true,
            onOpened: (overlay) => {
                const inp = overlay.querySelector('#jury-pwd-inp');
                if (!inp) return;
                inp.focus();
                inp.addEventListener('input', () => { pwd = inp.value; });
            },
        }
    );
    if (!akkoord) return;
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
            toonToast(nuSet ? '🔑 Jury-wachtwoord ingesteld' : '🔑 Jury-wachtwoord gewist', 'ok');
        }
    } catch (e) {
        toonBevestigDialog('Opslaan mislukt: ' + e.message, 'Jury-wachtwoord', 'OK', '');
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
        tr.querySelector('.tp-vrijgeven')?.addEventListener('click', async () => {
            const idx = parseInt(tr.dataset.idx);
            if (isNaN(idx) || !_tpAlleData[idx]) return;
            const huidig = _tpAlleData[idx];
            const info = [huidig.toegewezen_snr, huidig.toegewezen_naam, huidig.categorie].filter(Boolean).join(' ');
            const ok = await toonBevestigDialog(
                `Transponder ${huidig.transponder_code || ''} is nu toegewezen aan ${info || '(onbekend)'}.\n\n` +
                `Na vrijgeven komt hij weer beschikbaar; de transponder zelf blijft in de lijst.`,
                'Toewijzing vrijgeven', 'Vrijgeven', 'Annuleren'
            );
            if (!ok) return;
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

// ── Protokol-data: officials (3 categorieën) + nawoord ───────────────────
// OC + Vrijwilligers: alleen namen (textarea, één per regel).
// Jury: dropdown van 7 vaste rollen + naam, +Rij voor extra entries.
// Nawoord: vrije tekst-textarea.
const _JURY_FUNCTIES = [
    'hoofdscheidsrechter',
    'scheidsrechter',
    'tijdwaarneming',
    'video',
    'uitslagverwerking',
    'speaker',
    'algemeen jury lid',
    'stagiair',
];
// Volgorde op de Officials-pagina van het Wedstrijdprotokol. Per kolom
// alfabetisch binnen elke functie. Onbekende functies (legacy / vrije
// invoer) sluiten zich aan onderaan de linkerkolom.
const _JURY_KOLOM_LINKS = [
    'hoofdscheidsrechter', 'scheidsrechter', 'tijdwaarneming',
    'video', 'uitslagverwerking', 'speaker',
];
const _JURY_KOLOM_RECHTS = [
    'algemeen jury lid', 'stagiair',
];

async function protokolDataDialog(compId, compNaam) {
    if (!compId) return;
    let huidig = { leden: [], nawoord: '', voorblad_foto: null, nawoord_foto: null, nawoord_foto_caption: '' };
    try {
        const res = await fetch('api/jury_leden.php?competition_id=' + encodeURIComponent(compId));
        const d = await res.json();
        if (d.error) throw new Error(d.error);
        huidig = {
            leden:                d.leden || [],
            nawoord:              d.nawoord || '',
            voorblad_foto:        d.voorblad_foto || null,
            nawoord_foto:         d.nawoord_foto || null,
            nawoord_foto_caption: d.nawoord_foto_caption || '',
        };
    } catch (e) {
        toonBevestigDialog('Kon protokol-data niet ophalen: ' + (e.message || e), 'Protokol');
        return;
    }

    // Splits huidige data per categorie
    const ocNamen   = huidig.leden.filter(l => l.categorie === 'OC').map(l => l.naam);
    const vrijNamen = huidig.leden.filter(l => l.categorie === 'vrijwilliger').map(l => l.naam);
    // Jury: groepeer per persoon (case-insensitive naam) zodat één rij in de
    // modal alle rollen van die persoon bevat. Volgorde: rollen op de
    // gewenste kolom-volgorde (Hoofdscheidsrechter eerst, Stagiair laatst).
    const juryPerPersoon = new Map();
    for (const l of huidig.leden.filter(l => l.categorie === 'jury')) {
        const naam = String(l.naam || '').trim();
        const fn   = String(l.functie || '').toLowerCase().trim();
        if (!naam) continue;
        const key = naam.toLowerCase();
        const e = juryPerPersoon.get(key) || { naam, rollen: [] };
        if (fn && !e.rollen.includes(fn)) e.rollen.push(fn);
        juryPerPersoon.set(key, e);
    }
    const juryPersonen = [...juryPerPersoon.values()];

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    // Hergebruikt de bestaande modal-dialog/header/body hierarchie (zoals
    // kiesPosterTaal); .pd-* classes in style.css regelen de protokol-
    // specifieke layout (textareas, jury-grid).
    overlay.innerHTML = `
        <div class="modal-dialog pd-dialog">
            <div class="modal-header">
                <span>⚖ Protokol-data — ${escHtml(compNaam || '')}</span>
            </div>
            <div class="modal-body pd-body">
                <div class="pd-uitleg">
                    Drie categorieën officials komen op de Officials-pagina van het
                    wedstrijdrapport. Lege categorieën worden weggelaten.
                </div>

                <div class="pd-sec-titel">
                    Voorblad-foto <small>— grote foto bovenste helft van de titelpagina (optioneel)</small>
                </div>
                <div class="pd-foto-blok" id="pd-foto-voorblad">
                    <div class="pd-foto-preview ${huidig.voorblad_foto ? '' : 'is-leeg'}">
                        ${huidig.voorblad_foto
                            ? `<img src="${escHtml(huidig.voorblad_foto)}" alt="voorblad">`
                            : `<span class="pd-foto-leeg-tekst">Geen foto</span>`}
                    </div>
                    <div class="pd-foto-acties">
                        <label class="btn-secondary pd-foto-upload-lbl">
                            <input type="file" accept="image/*" class="pd-foto-upload" data-field="voorblad" hidden>
                            📷 Foto kiezen…
                        </label>
                        <button class="btn-secondary pd-foto-verwijder" type="button" data-field="voorblad" ${huidig.voorblad_foto ? '' : 'disabled'}>🗑 Verwijderen</button>
                    </div>
                </div>

                <div class="pd-sec-titel pd-sec-titel-na">
                    Organisatie Comité <small>— één naam per regel</small>
                </div>
                <textarea id="pd-oc" class="inp pd-namen-inp" rows="3"
                          placeholder="Bv.&#10;Cor Elsinga&#10;Moniek Holtrop&#10;Marielle Oostra">${escHtml(ocNamen.join('\n'))}</textarea>

                <div class="pd-sec-titel pd-sec-titel-na">
                    Jury <small>— klik op rollen om aan/uit te zetten; meerdere rollen per persoon kan</small>
                </div>
                <div id="pd-jury-lijst"></div>
                <button class="btn-secondary pd-add-rij" id="pd-add-rij" type="button">+ Persoon</button>

                <div class="pd-sec-titel pd-sec-titel-na">
                    Vrijwilligers <small>— één naam per regel</small>
                </div>
                <textarea id="pd-vrij" class="inp pd-namen-inp" rows="3"
                          placeholder="Bv.&#10;Anna Jansen&#10;Piet Klaassen">${escHtml(vrijNamen.join('\n'))}</textarea>

                <div class="pd-sec-titel pd-sec-titel-na">
                    Nawoord <small>— optioneel, verschijnt als pagina 2</small>
                </div>
                <textarea id="pd-nawoord" class="inp pd-nawoord-inp" rows="4">${escHtml(huidig.nawoord)}</textarea>

                <div class="pd-foto-blok" id="pd-foto-nawoord">
                    <div class="pd-foto-preview pd-foto-preview-klein ${huidig.nawoord_foto ? '' : 'is-leeg'}">
                        ${huidig.nawoord_foto
                            ? `<img src="${escHtml(huidig.nawoord_foto)}" alt="nawoord-foto">`
                            : `<span class="pd-foto-leeg-tekst">Geen foto</span>`}
                    </div>
                    <div class="pd-foto-rechts">
                        <div class="pd-foto-acties">
                            <label class="btn-secondary pd-foto-upload-lbl">
                                <input type="file" accept="image/*" class="pd-foto-upload" data-field="nawoord" hidden>
                                📷 Foto kiezen…
                            </label>
                            <button class="btn-secondary pd-foto-verwijder" type="button" data-field="nawoord" ${huidig.nawoord_foto ? '' : 'disabled'}>🗑 Verwijderen</button>
                        </div>
                        <label class="pd-foto-caption-lbl">
                            Onderschrift <small>— bv. naam + functie van de schrijver, of vrije omschrijving</small>
                            <input type="text" id="pd-nawoord-caption" class="inp pd-foto-caption" maxlength="200"
                                   value="${escHtml(huidig.nawoord_foto_caption)}"
                                   placeholder="Bv. Jan de Vries — voorzitter">
                        </label>
                    </div>
                </div>

                <div id="pd-melding" class="status-msg pd-melding"></div>
            </div>
            <div class="modal-knoppen pd-knoppen">
                <button class="modal-btn modal-annuleer" id="pd-annul" type="button">Annuleren</button>
                <button class="modal-btn modal-doorgaan" id="pd-opslaan" type="button">Opslaan</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const lijstDiv = overlay.querySelector('#pd-jury-lijst');
    const voegJuryRijToe = (naam = '', rollen = []) => {
        const rij = document.createElement('div');
        rij.className = 'pd-jury-rij';
        const chips = _JURY_FUNCTIES.map(f =>
            `<span class="pd-rol-chip${rollen.includes(f) ? ' is-actief' : ''}" data-rol="${escHtml(f)}">${escHtml(f)}</span>`
        ).join('');
        rij.innerHTML = `
            <input type="text" class="inp pd-naam" maxlength="150" value="${escHtml(naam)}" placeholder="Naam">
            <div class="pd-rollen-chips">${chips}</div>
            <button class="pd-del" type="button" title="Verwijderen">×</button>`;
        rij.querySelector('.pd-del').addEventListener('click', () => rij.remove());
        rij.querySelectorAll('.pd-rol-chip').forEach(chip => {
            chip.addEventListener('click', () => chip.classList.toggle('is-actief'));
        });
        lijstDiv.appendChild(rij);
        return rij;
    };
    if (juryPersonen.length) {
        juryPersonen.forEach(p => voegJuryRijToe(p.naam, p.rollen));
    } else {
        voegJuryRijToe('', []);
    }
    overlay.querySelector('#pd-add-rij').addEventListener('click', () => voegJuryRijToe('', []));

    const sluit = () => overlay.remove();
    overlay.querySelector('#pd-annul').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });

    // ── Protokol-foto's: upload + verwijder ─────────────────────────
    // Beide foto-blokken (voorblad + nawoord) gebruiken dezelfde upload-
    // flow via api/upload.php. Bij succes wordt de preview live ververst.
    const _updateFotoPreview = (field, url) => {
        const blok = overlay.querySelector(`#pd-foto-${field}`);
        if (!blok) return;
        const prev = blok.querySelector('.pd-foto-preview');
        const del  = blok.querySelector('.pd-foto-verwijder');
        if (url) {
            // Cache-buster ?t=… zodat browser direct nieuwe foto laat zien
            const src = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
            prev.innerHTML = `<img src="${escHtml(src)}" alt="${escHtml(field)}">`;
            prev.classList.remove('is-leeg');
            del.disabled = false;
        } else {
            prev.innerHTML = `<span class="pd-foto-leeg-tekst">Geen foto</span>`;
            prev.classList.add('is-leeg');
            del.disabled = true;
        }
    };

    overlay.querySelectorAll('.pd-foto-upload').forEach(inp => {
        inp.addEventListener('change', async () => {
            const file = inp.files?.[0];
            if (!file) return;
            const field = inp.dataset.field;     // 'voorblad' of 'nawoord'
            const meld  = overlay.querySelector('#pd-melding');
            meld.textContent = `Foto uploaden…`;
            meld.className   = 'status-msg loading';
            try {
                const fd = new FormData();
                fd.append('type', 'protokol_' + field);
                fd.append('id',   compId);
                fd.append('logo', file);
                const r = await fetch('api/upload.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.error) throw new Error(d.error);
                _updateFotoPreview(field, d.path);
                meld.textContent = 'Foto opgeslagen.';
                meld.className   = 'status-msg ok';
            } catch (e) {
                meld.textContent = '⚠ ' + (e.message || e);
                meld.className   = 'status-msg error';
            } finally {
                inp.value = '';   // reset zodat zelfde bestand opnieuw kan worden gekozen
            }
        });
    });

    overlay.querySelectorAll('.pd-foto-verwijder').forEach(btn => {
        btn.addEventListener('click', async () => {
            const field = btn.dataset.field;
            if (!await toonBevestigDialog(
                `Foto verwijderen?`, 'Protokol-foto', 'Verwijderen', 'Annuleren')) return;
            const meld = overlay.querySelector('#pd-melding');
            meld.textContent = 'Verwijderen…';
            meld.className   = 'status-msg loading';
            try {
                const r = await fetch('api/jury_leden.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'verwijder_foto', competition_id: compId, field,
                    }),
                });
                const d = await r.json();
                if (d.error) throw new Error(d.error);
                _updateFotoPreview(field, null);
                meld.textContent = 'Foto verwijderd.';
                meld.className   = 'status-msg ok';
            } catch (e) {
                meld.textContent = '⚠ ' + (e.message || e);
                meld.className   = 'status-msg error';
            }
        });
    });

    overlay.querySelector('#pd-opslaan').addEventListener('click', async () => {
        const meld = overlay.querySelector('#pd-melding');
        const btn  = overlay.querySelector('#pd-opslaan');

        // OC + Vrijwilligers uit textarea (per regel)
        const _parseLines = (s) => s.split('\n').map(x => x.trim()).filter(Boolean);
        const ocLijst   = _parseLines(overlay.querySelector('#pd-oc').value)
            .map(naam => ({ categorie: 'OC', naam }));
        const vrijLijst = _parseLines(overlay.querySelector('#pd-vrij').value)
            .map(naam => ({ categorie: 'vrijwilliger', naam }));

        // Jury uit rijen: één rij = één persoon met N rollen.
        // Naar backend ontvouwen tot 1 jury_leden-entry per (naam, functie).
        // Rijen zonder rollen of zonder naam worden overgeslagen.
        const juryLijst = [];
        for (const r of lijstDiv.querySelectorAll('.pd-jury-rij')) {
            const naam   = r.querySelector('.pd-naam').value.trim();
            if (!naam) continue;
            const rollen = [...r.querySelectorAll('.pd-rol-chip.is-actief')]
                .map(c => c.dataset.rol);
            if (!rollen.length) continue;
            for (const rol of rollen) {
                juryLijst.push({ categorie: 'jury', functie: rol, naam });
            }
        }

        const leden = [...ocLijst, ...juryLijst, ...vrijLijst];
        const tekst = overlay.querySelector('#pd-nawoord').value;
        btn.disabled = true;
        meld.textContent = 'Bezig…';
        meld.className = 'status-msg loading';
        try {
            const r1 = await fetch('api/jury_leden.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'bulk', competition_id: compId, leden }),
            });
            const d1 = await r1.json();
            if (d1.error) throw new Error(d1.error);
            const captionEl = overlay.querySelector('#pd-nawoord-caption');
            const caption   = captionEl ? captionEl.value : '';
            const r2 = await fetch('api/jury_leden.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'nawoord', competition_id: compId,
                    tekst, nawoord_foto_caption: caption,
                }),
            });
            const d2 = await r2.json();
            if (d2.error) throw new Error(d2.error);
            sluit();
        } catch (e) {
            meld.textContent = '⚠ ' + (e.message || e);
            meld.className = 'status-msg error';
            btn.disabled = false;
        }
    });
}

// ── Wedstrijdrapport: print of opslaan als PDF ────────────────────────────
// Triggered vanuit Beheer → Organisaties → tab Wedstrijden, 🖨-knop per rij.
// Haalt alle DC's + distances + uitslagen op via api/wedstrijdrapport.php,
// bouwt een geformatteerde HTML met alle uitslagen onder elkaar en opent
// die in een nieuw venster. Het venster roept automatisch window.print()
// aan zodat de operator direct naar printer of "Opslaan als PDF" kan.
//
// Bewust geen externe lib (html2pdf etc.) — browser-print + @page-CSS
// geeft consistente A4-output en respecteert printer-instellingen van
// de operator (kleur/zwart-wit, marges, paginabereik).
async function printWedstrijdrapport(compId, compNaam) {
    if (!compId) return;
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    // Taalkeuze (hergebruikt poster-modal — zelfde NL/EN-flow).
    const lang = await kiesPosterTaal();
    if (!lang) return;

    let data;
    try {
        const res = await fetch('api/wedstrijdrapport.php?id=' + encodeURIComponent(compId));
        data = await res.json();
        if (data.error) throw new Error(data.error);
    } catch (e) {
        toonBevestigDialog('Kon wedstrijdrapport niet ophalen: ' + (e.message || e), 'Afdrukken');
        return;
    }

    // Bij EN-keuze: nawoord lazy vertalen als cache nog leeg is.
    if (lang === 'en'
        && (data.competition?.protokol_nawoord || '').trim()
        && !(data.competition?.protokol_nawoord_en || '').trim()) {
        try {
            const tres = await fetch('api/jury_leden.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'vertaal_nawoord', competition_id: compId }),
            });
            const td = await tres.json();
            if (!td.error && td.tekst) data.competition.protokol_nawoord_en = td.tekst;
            // Bij vertaal-fout: doorgaan met lege EN-tekst (nawoord-sectie skipt
            // dan vanzelf), en operator een hint geven dat 't niet lukte.
            if (td.error) {
                toonBevestigDialog('Vertaal-API gaf een fout: ' + td.error
                    + '\n\nHet PDF wordt zonder nawoord gegenereerd.', 'Afdrukken — vertaling');
            }
        } catch (e) {
            console.warn('[wedstrijdrapport] vertaal-call faalde:', e);
        }
    }

    // ── i18n strings — overal in deze functie via T(key) ───────────────
    const i18nDicts = {
        nl: {
            doc_titel:        'Wedstrijdrapport',
            sub_protokol:     'Wedstrijdprotokol',
            nawoord:          'Nawoord',
            officials:        'Officials',
            oc:               'Organisatie Comité',
            jury:             'Jury',
            vrijwilligers:    'Vrijwilligers',
            sponsoren:        'Sponsoren',
            deelnemers:       'Deelnemerslijst',
            uitslagen:        'Uitslagen',
            kol_snr:          'Snr',
            kol_nat:          'Nat',
            kol_sponsor:      'Sponsor',
            bedankt:          'Bedankt!',
            tot_volgende:     'Tot een volgende wedstrijd.',
            tagline_footer:   'InlineComp · Van startlijn tot uitslag — live.',
            pagina_label:     'pagina',
            kol_pl:           'Pl', kol_naam: 'Naam', kol_cat: 'Cat', kol_club: 'Club',
            kol_tijd:         'Tijd', kol_punten: 'Punten', kol_opm: 'Opm',
            eindklassement:   'Eindklassement',
            afstanden_n:      n => `${n} afstanden · totaal-puntenklassement`,
            geen_afstanden:   'Geen afstanden gedefinieerd.',
            geen_uitslag:     'Geen uitslag vastgelegd.',
            sectie:           'Sectie',
            afgedrukt:        'Afgedrukt',
            categorien_n:     n => `${n} categorie${n !== 1 ? 'ën' : ''} · InlineComp`,
            jury_functies: {  // exacte NL-rollen (key = lower)
                'hoofdscheidsrechter': 'Hoofdscheidsrechter',
                'scheidsrechter':      'Scheidsrechter',
                'tijdwaarneming':      'Tijdwaarneming',
                'video':               'Video',
                'uitslagverwerking':   'Uitslagverwerking',
                'speaker':             'Speaker',
                'algemeen jury lid':   'Algemeen jurylid',
                'stagiair':            'Stagiair',
            },
        },
        en: {
            doc_titel:        'Race Report',
            sub_protokol:     'Race Protocol',
            nawoord:          'Closing Remarks',
            officials:        'Officials',
            oc:               'Organizing Committee',
            jury:             'Jury',
            vrijwilligers:    'Volunteers',
            sponsoren:        'Sponsors',
            deelnemers:       'Participants',
            uitslagen:        'Results',
            kol_snr:          'Bib',
            kol_nat:          'Nat',
            kol_sponsor:      'Sponsor',
            bedankt:          'Thank you!',
            tot_volgende:     'See you at the next race.',
            tagline_footer:   'InlineComp · From start line to results — live.',
            pagina_label:     'page',
            kol_pl:           'Pos', kol_naam: 'Name', kol_cat: 'Cat', kol_club: 'Club',
            kol_tijd:         'Time', kol_punten: 'Points', kol_opm: 'Note',
            eindklassement:   'Overall Classification',
            afstanden_n:      n => `${n} distances · total points classification`,
            geen_afstanden:   'No distances defined.',
            geen_uitslag:     'No results recorded.',
            sectie:           'Section',
            afgedrukt:        'Printed',
            categorien_n:     n => `${n} categor${n !== 1 ? 'ies' : 'y'} · InlineComp`,
            jury_functies: {
                'hoofdscheidsrechter': 'Chief Referee',
                'scheidsrechter':      'Referee',
                'tijdwaarneming':      'Timekeeping',
                'video':               'Video Referee',
                'uitslagverwerking':   'Results Processing',
                'speaker':             'Announcer',
                'algemeen jury lid':   'General Jury Member',
                'stagiair':            'Trainee',
            },
        },
    };
    const D = i18nDicts[lang] || i18nDicts.nl;
    const T = (key) => D[key] ?? key;
    const Tfn = (key, n) => (typeof D[key] === 'function' ? D[key](n) : D[key]);

    const comp           = data.competition || {};
    const dcs            = Array.isArray(data.dcs)             ? data.dcs             : [];
    const jury           = Array.isArray(data.jury)            ? data.jury            : [];
    const sponsors       = Array.isArray(data.sponsors)        ? data.sponsors        : [];
    const afstandenLijst = Array.isArray(data.afstanden_lijst) ? data.afstanden_lijst : [];
    const deelnemers     = Array.isArray(data.deelnemers)      ? data.deelnemers      : [];
    if (!dcs.length) {
        toonBevestigDialog('Deze wedstrijd heeft geen distance combinations om af te drukken.', 'Afdrukken');
        return;
    }

    // ── Helpers ─────────────────────────────────────────────────────────
    // Datum-bereik formatteren: één dag → "29 mei 2025", meerdere dagen →
    // "29 — 31 mei 2025". Compact want het staat in de header naast naam.
    const _fmtDatum = (iso) => {
        if (!iso) return '';
        const d = new Date(iso.replace(' ', 'T'));
        return d.toLocaleDateString('nl-NL', { day: '2-digit', month: 'long', year: 'numeric' });
    };
    const _fmtKortDatum = (iso) => {
        if (!iso) return '';
        const d = new Date(iso.replace(' ', 'T'));
        return d.toLocaleDateString('nl-NL', { day: '2-digit', month: 'short' });
    };
    const datumBereik = (() => {
        const s = comp.starts ? new Date(comp.starts.replace(' ', 'T')) : null;
        const e = comp.ends   ? new Date(comp.ends.replace(' ', 'T'))   : null;
        if (!s) return '';
        if (!e || s.toDateString() === e.toDateString()) return _fmtDatum(comp.starts);
        // Zelfde maand+jaar → "29 — 31 mei 2025", anders volledig per kant
        if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
            return `${s.getDate()} — ${e.getDate()} ${s.toLocaleDateString('nl-NL', { month: 'long', year: 'numeric' })}`;
        }
        return `${_fmtDatum(comp.starts)} — ${_fmtDatum(comp.ends)}`;
    })();

    // tijd_ms → leesbare string. < 60s → "19.727 s", anders "1:23.456"
    const _fmtTijd = (ms) => {
        if (ms === null || ms === undefined) return '—';
        if (ms < 60000) return (ms / 1000).toFixed(3) + ' s';
        const min = Math.floor(ms / 60000);
        const sec = ((ms % 60000) / 1000).toFixed(3).padStart(6, '0');
        return `${min}:${sec}`;
    };

    const _raceTypeLabel = (rt) => ({
        sprint:      'Sprint / DTT',
        inline:      'Inline (head-to-head)',
        afvalkoers:  'Afvalkoers',
        puntenkoers: 'Puntenkoers',
    }[rt] || rt || '');

    // ── Tabel-builder voor één set rijen (uitslag of klassement) ────────
    // Kolommen dynamisch op basis van data, voorkomt lege "—" kolommen.
    // Bij split_group: rijen per split apart groeperen met een tussenkop.
    const _bouwTabel = (rijen, opts = {}) => {
        if (!rijen?.length) return '';
        const isKlassement = opts.isKlassement === true;
        const heeftTijd    = !isKlassement && rijen.some(r => r.tijd_ms !== null);
        const heeftPunten  = rijen.some(r => (isKlassement ? r.punten_totaal : r.punten) !== null);
        const heeftSanctie = !isKlassement && rijen.some(r => r.sanctie);

        // Splits detecteren — als er meerdere unieke split_groups zijn,
        // tonen we per split een aparte sub-tabel met cat-naam als kop.
        const splits = [...new Set(rijen.map(r => r.split_group || ''))];
        const meerdereSplits = splits.length > 1;

        const headCols = [
            `<th class="c">${esc(T('kol_pl'))}</th>`,
            `<th>${esc(T('kol_naam'))}</th>`,
            `<th class="c">${esc(T('kol_cat'))}</th>`,
            `<th>${esc(T('kol_club'))}</th>`,
            heeftTijd    ? `<th class="c">${esc(T('kol_tijd'))}</th>`   : '',
            heeftPunten  ? `<th class="c">${esc(T('kol_punten'))}</th>` : '',
            heeftSanctie ? `<th class="c">${esc(T('kol_opm'))}</th>`    : '',
        ].filter(Boolean).join('');

        const _bouwRijen = (subset) => subset.map((r, i) => {
            const punten = isKlassement ? r.punten_totaal : r.punten;
            const cells = [
                // NULL-rang (DQ/DNS-rijders zonder positie) → '—' ipv lege
                // cel of de letterlijke string 'null'. Consistent met de
                // punten-kolom hieronder.
                `<td class="c">${r.rang !== null ? esc(r.rang) : '—'}</td>`,
                `<td>${esc(r.full_name)}</td>`,
                `<td class="c">${esc(r.categorie ?? '')}</td>`,
                `<td>${esc(r.club_full ?? '')}</td>`,
                heeftTijd    ? `<td class="c mono">${esc(_fmtTijd(r.tijd_ms))}</td>` : '',
                heeftPunten  ? `<td class="c">${punten !== null ? esc(punten) : '—'}</td>` : '',
                heeftSanctie ? `<td class="c sanctie">${esc(r.sanctie ?? '')}</td>` : '',
            ].filter(Boolean).join('');
            return `<tr class="${i % 2 === 1 ? 'z' : ''}">${cells}</tr>`;
        }).join('');

        if (!meerdereSplits) {
            return `<table>
                <thead><tr>${headCols}</tr></thead>
                <tbody>${_bouwRijen(rijen)}</tbody>
            </table>`;
        }

        // Per split-group een eigen tabel — voorkomt dat HSA-rang-9
        // boven HJA-rang-1 staat bij split-DCs (beide hebben hun eigen
        // ranking 1..N maar dezelfde DC).
        return splits.map(sg => {
            const subset = rijen.filter(r => (r.split_group || '') === sg);
            if (!subset.length) return '';
            const splitTitel = sg
                ? `<div class="split-titel">${esc(T('sectie'))}: ${esc(sg)}</div>`
                : '';
            return splitTitel + `<table>
                <thead><tr>${headCols}</tr></thead>
                <tbody>${_bouwRijen(subset)}</tbody>
            </table>`;
        }).join('');
    };

    // ── Per DC blokken bouwen ───────────────────────────────────────────
    // Hulpfunctie: render één "logische DC" (kan een echte DC zijn, of een
    // virtuele DC voor één split-group binnen een gesplitste DC).
    //
    // Volgorde binnen sectie:
    //   1) Per afstand één uitslag-blok
    //   2) Bij multi-distance: eindklassement-blok eronder
    //      Bij single-distance: GEEN klassement (identiek aan de uitslag)
    const _bouwDcSectie = (label, distances, klassement) => {
        if (!distances.length) {
            return `<section class="dc-block">
                <h2 class="dc-titel">${esc(label)}</h2>
                <div class="dc-leeg">${esc(T('geen_afstanden'))}</div>
            </section>`;
        }

        const blocks = [];

        // 1) Per distance één blok
        for (const dist of distances) {
            const datumKort = dist.starts ? _fmtKortDatum(dist.starts) : '';
            const rtLabel   = _raceTypeLabel(dist.race_type);
            const subMeta   = [datumKort, dist.name, rtLabel].filter(Boolean).join(' · ');
            // Bij multi-distance: afstand-naam in titel om blokken te
            // onderscheiden. Bij single-distance is label alleen genoeg.
            const titel = distances.length > 1 ? `${label} — ${dist.name}` : label;

            if (!dist.uitslag?.length) {
                blocks.push(`<section class="dc-block">
                    <h2 class="dc-titel">${esc(titel)}</h2>
                    <div class="dc-sub">${esc(subMeta)}</div>
                    <div class="dc-leeg">${esc(T('geen_uitslag'))}</div>
                </section>`);
            } else {
                blocks.push(`<section class="dc-block">
                    <h2 class="dc-titel">${esc(titel)}</h2>
                    <div class="dc-sub">${esc(subMeta)}</div>
                    ${_bouwTabel(dist.uitslag)}
                </section>`);
            }
        }

        // 2) Eindklassement onderaan, alleen bij multi-distance DC.
        if (distances.length > 1 && klassement?.length) {
            blocks.push(`<section class="dc-block">
                <h2 class="dc-titel">${esc(label)} — ${esc(T('eindklassement'))}</h2>
                <div class="dc-sub">${esc(Tfn('afstanden_n', distances.length))}</div>
                ${_bouwTabel(klassement, { isKlassement: true })}
            </section>`);
        }

        return blocks.join('');
    };

    // ── Cat-parser voor inline-skating ───────────────────────────────
    // Altijd 3 karakters:
    //   [0] D = Dames, H = Heren
    //   [1] groep:
    //         P = Pupillen        — 3e karakter cijfer; GROOT cijfer = JONGER
    //                                P4 (jongst) → P1 (oudst)
    //         K = Kadetten        — 3e karakter = klasse (vrijwel altijd 'A')
    //         J = Junioren        — 3e karakter letter; LATER alfabet = JONGER
    //                                JB (jonger) → JA (ouder)
    //         S = Senioren        — gek-volgorde: SJ (obsoleet maar jongst) → SA → SB (oudst)
    //         cijfer = Masters    — héél 2-3 is getal 40/45/50/55/60/…
    //                                KLEIN getal = JONGER
    // Sortering: groep × 10000 + sub × 100 + geslacht (D=0, H=1).
    //
    // TODO post-OH850: verhuizen naar js/cat_volgorde.js zodat uitslag.js,
    // live.js en ranking dezelfde parser gebruiken.
    const _catRank = (cat) => {
        const c = String(cat || '').toUpperCase().trim();
        if (!c) return 99999;
        // Fallback voor uitgeschreven varianten ("Dsenioren", "Hsenioren")
        if (/^DSENIOR/.test(c)) return 4 * 10000 + 100;       // = DSA-positie
        if (/^HSENIOR/.test(c)) return 4 * 10000 + 100 + 1;   // = HSA-positie
        if (c.length < 2) return 99999;

        const geslacht = c[0] === 'D' ? 0 : c[0] === 'H' ? 1 : 9;
        const groep    = c[1];
        const sub      = c.slice(2);
        let groepRank, subRank;

        if (groep === 'P') {
            groepRank = 1;
            const n = parseInt(sub, 10);
            subRank = isNaN(n) ? 99 : (10 - n);   // P4→6, P3→7, P2→8, P1→9
        } else if (groep === 'K') {
            groepRank = 2;
            subRank = 0;
        } else if (groep === 'J') {
            groepRank = 3;
            // 'B' (66) is jonger dan 'A' (65) → omgekeerd op de char-code
            subRank = sub[0] ? (90 - sub[0].charCodeAt(0)) : 99;  // B→24, A→25
        } else if (groep === 'S') {
            groepRank = 4;
            // SJ < SA < SB (jong → oud)
            if      (sub[0] === 'J') subRank = 0;
            else if (sub[0] === 'A') subRank = 1;
            else if (sub[0] === 'B') subRank = 2;
            else                     subRank = 99;
        } else if (/^[0-9]/.test(groep)) {
            // Masters: hele suffix is leeftijdsgroep-getal
            groepRank = 5;
            const n = parseInt(c.slice(1), 10);
            subRank = isNaN(n) ? 99 : Math.floor((n - 40) / 5);  // 40→0, 45→1, 50→2…
        } else {
            return 99999;
        }
        return groepRank * 10000 + subRank * 100 + geslacht;
    };
    // DC kan komma-lijst van cats hebben (merge) + split-target_groups.
    // Sort-key = kleinste cat-rank → DC met DP4 erin komt vóór DC met HP1.
    const _dcCats = (dc) => {
        const cats = new Set();
        (dc.category_filter || '').split(',').map(s => s.trim()).filter(Boolean)
            .forEach(c => cats.add(c.toUpperCase()));
        (dc.distances || []).forEach(d => {
            if (d.target_group) cats.add(String(d.target_group).toUpperCase());
        });
        return [...cats];
    };
    const _dcSortKey = (dc) => {
        const cats = _dcCats(dc);
        if (!cats.length) return 999;
        return Math.min(...cats.map(_catRank));
    };

    // Top-level loop: DCs op cat-volgorde (jong → oud, D vóór H).
    // Binnen elke DC blijven de afstanden in programma-volgorde
    // (backend ORDER BY d.number, d.name).
    const dcsGesorteerd = [...dcs].sort((a, b) => {
        const ka = _dcSortKey(a);
        const kb = _dcSortKey(b);
        if (ka !== kb) return ka - kb;
        // Binnen dezelfde cat: programma-volgorde uit tijdschema_ritten
        // (door de backend meegeleverd als prog_volgorde). NULL = nooit
        // in tijdschema — achteraan, alfabetisch onderling.
        const pa = a.prog_volgorde != null ? Number(a.prog_volgorde) : 99999;
        const pb = b.prog_volgorde != null ? Number(b.prog_volgorde) : 99999;
        if (pa !== pb) return pa - pb;
        return String(a.name || '').localeCompare(String(b.name || ''), 'nl');
    });
    const dcBlocks = dcsGesorteerd.flatMap(dc => {
        const distances = dc.distances || [];
        const klassement = dc.klassement || [];
        // Unieke non-empty target_groups in distances = de splits van deze DC
        const splits = [...new Set(distances.map(d => d.target_group).filter(Boolean))];

        if (splits.length === 0) {
            return [_bouwDcSectie(dc.name, distances, klassement)];
        }

        // Splits ook op cat-volgorde (zelfde inline-cat-rangorde als DCs).
        const splitsGesorteerd = [...splits].sort((a, b) => _catRank(a) - _catRank(b));
        return splitsGesorteerd.map(splitLabel => {
            const splitDists = distances.filter(d => d.target_group === splitLabel);
            const splitKlas  = klassement.filter(k => (k.split_group || '') === splitLabel);
            return _bouwDcSectie(splitLabel, splitDists, splitKlas);
        });
    }).join('');

    // ── Header (org-logo rechts, titel+datum links) ─────────────────────
    // Geen bouwOrgHeaderFooter() call — die werkt vanuit `actieveOrg` state,
    // wij hebben hier wedstrijd-specifieke org-info uit de API zelf.
    const orgLogoHtml = comp.organisatie_logo
        ? `<img src="${esc(comp.organisatie_logo)}" alt="logo" class="pr-org-logo">`
        : '';
    // Voorblad: grote logo's. Toon zowel het organisatie-logo als het
    // baan-logo (vereniging die de wedstrijd op de baan draait). Naast
    // elkaar als beide bestaan, anders één gecentreerd.
    const _logoXL = (src, alt) => src
        ? `<img src="${esc(src)}" alt="${esc(alt)}" class="pr-logo-xl">` : '';
    const orgLogoXL  = _logoXL(comp.organisatie_logo, comp.organisatie_naam || 'organisatie');
    const baanLogoXL = _logoXL(comp.baan_logo,        comp.baan_naam        || 'baan');
    const orgNaam   = comp.organisatie_naam ?? '';
    const locatie   = [comp.venue_name, comp.venue_city].filter(Boolean).join(', ') || comp.location || '';
    const afgedrukt = new Date().toLocaleString('nl-NL',
        { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    // ── Protokol-secties: voorblad, nawoord, officials, sponsoren, afsluiting ──
    // Iedere sectie krijgt een eigen page-break. Lege secties worden
    // overgeslagen (geen officials → geen officials-pagina).
    //
    // Voorblad-layout met foto:
    //   - Bovenste helft: grote foto edge-to-edge. Org-logo linksboven,
    //     baan-logo rechtsboven, beide met witte achtergrond zodat ze
    //     leesbaar zijn op elke achtergrond.
    //   - Onderste helft: titel + datum + locatie + voetnoot.
    //
    // Zonder foto: oude layout (logo's bovenaan in flexbox, titel midden).
    const _voorbladFoto = comp.protokol_voorblad_foto || '';
    const _voorbladHtml = _voorbladFoto
        ? `
<section class="protokol-voorblad pv-met-foto">
    <div class="pv-foto-wrap">
        <img src="${esc(_voorbladFoto)}" alt="${esc(comp.name || '')}" class="pv-foto">
        ${orgLogoXL  ? `<div class="pv-logo-hoek pv-logo-links">${orgLogoXL}</div>`  : ''}
        ${baanLogoXL ? `<div class="pv-logo-hoek pv-logo-rechts">${baanLogoXL}</div>` : ''}
    </div>
    <div class="vb-mid">
        <div class="vb-titel">${esc(comp.name || compNaam || '')}</div>
        <div class="vb-sub">${esc(T('sub_protokol'))}</div>
        <div class="vb-meta">${esc(datumBereik)}</div>
        ${locatie ? `<div class="vb-meta">${esc(locatie)}</div>` : ''}
    </div>
    <div class="vb-bot">
        ${esc(T('afgedrukt'))}: ${esc(afgedrukt)} · ${esc(Tfn('categorien_n', dcs.length))}
    </div>
</section>`
        : `
<section class="protokol-voorblad">
    <div class="vb-logos">
        ${orgLogoXL  ? `<div class="vb-logo">${orgLogoXL}</div>`  : ''}
        ${baanLogoXL ? `<div class="vb-logo">${baanLogoXL}</div>` : ''}
    </div>
    <div class="vb-mid">
        <div class="vb-titel">${esc(comp.name || compNaam || '')}</div>
        <div class="vb-sub">${esc(T('sub_protokol'))}</div>
        <div class="vb-meta">${esc(datumBereik)}</div>
        ${locatie ? `<div class="vb-meta">${esc(locatie)}</div>` : ''}
    </div>
    <div class="vb-bot">
        ${esc(T('afgedrukt'))}: ${esc(afgedrukt)} · ${esc(Tfn('categorien_n', dcs.length))}
    </div>
</section>`;

    // Nawoord-tekst: bij EN proberen we eerst de cache, anders de NL-tekst
    // (als de Claude-call faalde laten we 'm gewoon in NL staan ipv lege pagina).
    const _nawoordTekst = lang === 'en'
        ? (comp.protokol_nawoord_en || comp.protokol_nawoord || '').trim()
        : (comp.protokol_nawoord || '').trim();
    // Nawoord-foto (optioneel) + caption (vrij in te vullen, bv. naam +
    // functie schrijver, of omschrijving als 't geen pasfoto is). Foto
    // wordt rechts naast de tekst gefloat zodat de tekst er omheen valt;
    // bij print + lange nawoord-teksten loopt 'm netjes door.
    const _nawoordFoto    = comp.protokol_nawoord_foto || '';
    const _nawoordCaption = (comp.protokol_nawoord_foto_caption || '').trim();
    const _nawoordFotoHtml = _nawoordFoto
        ? `<figure class="pr-nawoord-foto">
                <img src="${esc(_nawoordFoto)}" alt="${esc(_nawoordCaption || 'foto')}">
                ${_nawoordCaption ? `<figcaption>${esc(_nawoordCaption)}</figcaption>` : ''}
            </figure>`
        : '';
    const _nawoordHtml = (_nawoordTekst || _nawoordFoto)
        ? `<section class="protokol-blad">
            <h1 class="pr-blad-titel">${esc(T('nawoord'))}</h1>
            ${_nawoordFotoHtml}
            <div class="pr-nawoord">${esc(_nawoordTekst).replace(/\n/g, '<br>')}</div>
        </section>`
        : '';

    // Officials in drie sub-secties: OC + Jury (functie+naam) + Vrijwilligers.
    // Lege sub-secties worden weggelaten; als alle drie leeg zijn, hele
    // pagina overslaan.
    const _ocLeden    = jury.filter(j => j.categorie === 'OC');
    const _juryLeden  = jury.filter(j => j.categorie === 'jury');
    const _vrijLeden  = jury.filter(j => j.categorie === 'vrijwilliger');

    const _renderNamen = (lijst) => lijst.map(j =>
        `<li>${esc(j.naam || '')}</li>`).join('');
    // Jury-functie via i18n-dict (NL-key → vertaalde label). Onbekende
    // functies (legacy / handmatig) blijven raw zichtbaar.
    const _juryFnLabel = (fn) => {
        const k = String(fn || '').toLowerCase();
        return D.jury_functies[k] || fn || '';
    };
    // Dubbelrol-aanpak: persoon staat onder z'n hoofdrol (primaire functie
    // volgens kolom-volgorde). Eventuele extra rollen krijgen een
    // voetnoot-marker (1), (2)... met onderschrift "(1) tevens algemeen
    // jurylid". Personen met dezelfde extra-rol-combinatie delen één noot
    // → geen herhaling.
    const ALLE_JURY_FUNC = [..._JURY_KOLOM_LINKS, ..._JURY_KOLOM_RECHTS];
    const _funcRank = (fn) => {
        const i = ALLE_JURY_FUNC.indexOf(fn);
        return i === -1 ? 999 : i;
    };
    // "A en B" / "A en B en C" als losse rij, "A, B en C" als 3+ items.
    const _joinAnd = (arr, conj) => {
        if (arr.length <= 1) return arr.join('');
        if (arr.length === 2) return `${arr[0]} ${conj} ${arr[1]}`;
        return `${arr.slice(0, -1).join(', ')} ${conj} ${arr.at(-1)}`;
    };
    const _renderJury = (lijst) => {
        // Groepeer op naam (case-insensitive). Per persoon de set rollen.
        const perPersoon = new Map();
        for (const j of lijst) {
            const naam = String(j.naam || '').trim();
            const fn   = String(j.functie || '').toLowerCase().trim();
            if (!naam || !fn) continue;
            const key = naam.toLowerCase();
            const e = perPersoon.get(key) || { naam, functies: [] };
            if (!e.functies.includes(fn)) e.functies.push(fn);
            perPersoon.set(key, e);
        }
        const personen = [...perPersoon.values()].map(p => {
            p.functies.sort((a, b) => _funcRank(a) - _funcRank(b));
            p.primair = p.functies[0];
            p.extra   = p.functies.slice(1);   // alles behalve hoofdrol
            return p;
        });

        // Voetnoten dedupliceren: zelfde extra-rol-combinatie → één noot.
        const notenMap = new Map();   // joined-key → {idx, tekst}
        const notenLijst = [];
        const _conj = lang === 'en' ? 'and' : 'en';
        const _tevens = lang === 'en' ? 'also' : 'tevens';
        let notenIdx = 0;
        for (const p of personen) {
            if (!p.extra.length) { p.notenIdx = null; continue; }
            const key = p.extra.join('|');
            if (!notenMap.has(key)) {
                notenIdx++;
                const labels = p.extra.map(_juryFnLabel);
                const tekst = `${_tevens} ${_joinAnd(labels, _conj)}`;
                notenMap.set(key, { idx: notenIdx, tekst });
                notenLijst.push({ idx: notenIdx, tekst });
            }
            p.notenIdx = notenMap.get(key).idx;
        }

        // Onbekende functies (legacy): primaire functie niet in lijst.
        // Plaats onderaan linkerkolom.
        const onbekendeFn = [...new Set(personen
            .filter(p => !ALLE_JURY_FUNC.includes(p.primair))
            .map(p => p.primair)
        )];
        const linksFunc = [..._JURY_KOLOM_LINKS, ...onbekendeFn];

        const _kolomRijen = (functies) => {
            let html = '';
            for (const fn of functies) {
                const inFn = personen.filter(p => p.primair === fn)
                    .sort((a, b) => a.naam.localeCompare(b.naam, 'nl'));
                for (const p of inFn) {
                    const noot = p.notenIdx
                        ? ` <sup class="pr-noot-marker">(${p.notenIdx})</sup>`
                        : '';
                    html += `<tr>
                        <th>${esc(_juryFnLabel(p.primair))}</th>
                        <td>${esc(p.naam)}${noot}</td>
                    </tr>`;
                }
            }
            return html;
        };

        const linksRijen  = _kolomRijen(linksFunc);
        const rechtsRijen = _kolomRijen(_JURY_KOLOM_RECHTS);
        const notenHtml = notenLijst.length
            ? `<div class="pr-jury-noten">${notenLijst.map(n =>
                `<div>(${n.idx}) ${esc(n.tekst)}</div>`).join('')}</div>`
            : '';
        return `<div class="pr-jury-2kol">
            ${linksRijen  ? `<table class="pr-officials"><tbody>${linksRijen}</tbody></table>`  : '<div></div>'}
            ${rechtsRijen ? `<table class="pr-officials"><tbody>${rechtsRijen}</tbody></table>` : '<div></div>'}
        </div>${notenHtml}`;
    };

    const _officialsHtml = (_ocLeden.length || _juryLeden.length || _vrijLeden.length)
        ? `<section class="protokol-blad">
            <h1 class="pr-blad-titel">${esc(T('officials'))}</h1>
            ${_ocLeden.length ? `
                <h2 class="pr-sub-titel">${esc(T('oc'))}</h2>
                <ul class="pr-namen-lijst">${_renderNamen(_ocLeden)}</ul>` : ''}
            ${_juryLeden.length ? `
                <h2 class="pr-sub-titel">${esc(T('jury'))}</h2>
                ${_renderJury(_juryLeden)}` : ''}
            ${_vrijLeden.length ? `
                <h2 class="pr-sub-titel">${esc(T('vrijwilligers'))}</h2>
                <ul class="pr-namen-lijst pr-namen-2kol">${_renderNamen(_vrijLeden)}</ul>` : ''}
        </section>`
        : '';

    // Sponsoren in een grid van logo's. URL als optionele href.
    const _sponsorenHtml = sponsors.length
        ? `<section class="protokol-blad">
            <h1 class="pr-blad-titel">${esc(T('sponsoren'))}</h1>
            <div class="pr-sponsors-grid">${sponsors.map(s => {
                const img = s.logo_path
                    ? `<img src="${esc(s.logo_path)}" alt="${esc(s.naam || '')}">`
                    : `<div class="pr-sp-no-logo">${esc(s.naam || '')}</div>`;
                const block = `<div class="pr-sp-card">${img}<div class="pr-sp-naam">${esc(s.naam || '')}</div></div>`;
                return s.url
                    ? `<a class="pr-sp-link" href="${esc(s.url)}" target="_blank" rel="noopener">${block}</a>`
                    : block;
            }).join('')}</div>
        </section>`
        : '';

    // ── Deelnemerslijst ─────────────────────────────────────────
    // Per rijder twee regels: 1) snr, naam, cat, nat + per afstand
    // X (deelgenomen) of - (niet); 2) club lang onder de naam +
    // sponsor onder cat+nat (colspan=2 voor meer ruimte).
    // Snr en afstand-cellen rowspannen over beide regels. Afstand-
    // headers zijn verticaal geroteerd zodat lange namen smal passen.
    const _deelnemersHtml = deelnemers.length
        ? `<section class="protokol-blad">
            <h1 class="pr-blad-titel">${esc(T('deelnemers'))}</h1>
            <table class="pr-deelnemers">
                <colgroup>
                    <col class="dl-c-snr">
                    <col class="dl-c-naam"><col class="dl-c-naam"><col class="dl-c-naam"><col class="dl-c-naam">
                    <col class="dl-c-cat">
                    <col class="dl-c-nat">
                    ${afstandenLijst.map(() => '<col class="dl-c-afst">').join('')}
                </colgroup>
                <thead>
                    <tr>
                        <th rowspan="2" class="dl-snr">${esc(T('kol_snr'))}</th>
                        <th colspan="4" class="dl-naam">${esc(T('kol_naam'))}</th>
                        <th class="dl-cat">${esc(T('kol_cat'))}</th>
                        <th class="dl-nat">${esc(T('kol_nat'))}</th>
                        ${afstandenLijst.map(a =>
                            `<th rowspan="2" class="dl-afst-h">${esc(a.naam)}</th>`
                        ).join('')}
                    </tr>
                    <tr>
                        <th colspan="3" class="dl-club-h">${esc(T('kol_club'))}</th>
                        <th colspan="3" class="dl-sponsor-h">${esc(T('kol_sponsor'))}</th>
                    </tr>
                </thead>
                <tbody>${deelnemers.map(d => {
                    const gereden = new Set(d.gereden || []);
                    const afstCellen = afstandenLijst.map(a =>
                        `<td rowspan="2" class="dl-afst-c">${gereden.has(a.naam) ? 'X' : '·'}</td>`
                    ).join('');
                    return `
                        <tr class="dl-r1">
                            <td rowspan="2" class="dl-snr">${esc(d.start_number ?? '')}</td>
                            <td colspan="4" class="dl-naam">${esc(d.full_name || '')}</td>
                            <td class="dl-cat">${esc(d.category || '')}</td>
                            <td class="dl-nat">${esc(d.nationality || '')}</td>
                            ${afstCellen}
                        </tr>
                        <tr class="dl-r2">
                            <td colspan="3" class="dl-club">${esc(d.club_full || '')}</td>
                            <td colspan="3" class="dl-sponsor">${esc(d.sponsor || '')}</td>
                        </tr>`;
                }).join('')}</tbody>
            </table>
        </section>`
        : '';

    // Tussen-titel boven uitslagen-secties — geen page-break vooraf
    // (de laatste protokol-blad heeft die al; bij geen voorafgaande
    // secties begint uitslagen vanaf het voorblad).
    const _uitslagenIntroHtml = `<section class="pr-uitslagen-intro">
        <h1 class="pr-blad-titel">${esc(T('uitslagen'))}</h1>
    </section>`;

    // Afsluitend blad — kort dankblok + org-logo onderaan.
    // Afsluitings-blok: drie InlineComp-logos naast elkaar (algemeen / P
    // public-app / C coach-app) met webadressen eronder + contact-email.
    // Eigen "trots" — organisatie heeft op voorblad al genoeg eer.
    // Absolute URLs omdat de print-window relatieve paden niet altijd
    // correct resolveert.
    const _origin   = window.location.origin;
    const _hostnaam = _origin.replace(/^https?:\/\//, '');
    const _afsluitingHtml = `
<section class="protokol-afsluiting">
    <div class="af-mid">
        <div class="af-titel">${esc(T('bedankt'))}</div>
        <div class="af-sub">${esc(T('tot_volgende'))}</div>
    </div>
    <div class="af-logos">
        <div class="af-logo-blok">
            <img src="${esc(_origin)}/favicon.svg" alt="InlineComp" class="pr-ic-logo">
            <div class="af-logo-naam">InlineComp</div>
            <div class="af-logo-url">${esc(_hostnaam)}</div>
        </div>
        <div class="af-logo-blok">
            <img src="${esc(_origin)}/public/icon-192-v2.svg" alt="InlineComp Public" class="pr-ic-logo">
            <div class="af-logo-naam">InlineComp P</div>
            <div class="af-logo-url">${esc(_hostnaam)}/public</div>
        </div>
        <div class="af-logo-blok">
            <img src="${esc(_origin)}/coach/icon-192-v2.svg" alt="InlineComp Coach" class="pr-ic-logo">
            <div class="af-logo-naam">InlineComp C</div>
            <div class="af-logo-url">${esc(_hostnaam)}/coach</div>
        </div>
    </div>
    <div class="af-contact">
        Contact: <a href="mailto:inlinecomp@devriesen.com">inlinecomp@devriesen.com</a>
    </div>
</section>`;

    const htmlDoc = `<!DOCTYPE html><html lang="${esc(lang)}">
<head><meta charset="UTF-8">
<title>${esc(comp.name || compNaam || T('doc_titel'))}</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9.5pt;margin:.8cm 1.2cm 1.2cm;color:#111}
header{display:flex;justify-content:space-between;align-items:flex-start;gap:4mm;margin-bottom:3mm}
.hdr-links{flex:1;min-width:0}
.hdr-titel{font-size:14pt;font-weight:700;margin-bottom:1mm}
.hdr-meta{font-size:9pt;color:#555}
.hdr-rechts{flex-shrink:0}
hr.top-rule{border:none;border-top:2px solid #1a3a5c;margin:2mm 0 5mm}
.dc-block{margin-bottom:6mm;page-break-inside:avoid}
.dc-titel{font-size:11pt;font-weight:700;color:#1a3a5c;margin:0 0 1mm;padding-bottom:1mm;border-bottom:1px solid #1a3a5c}
.dc-sub{font-size:8.5pt;color:#666;margin-bottom:2mm;font-style:italic}
.dc-leeg{font-size:9pt;color:#888;font-style:italic;padding:2mm 0}
.split-titel{font-size:9pt;font-weight:600;color:#1a3a5c;margin:3mm 0 1mm;padding-left:1mm;border-left:3px solid #1a3a5c}
.split-titel:first-child{margin-top:0}
table + table{margin-top:2mm}
table{width:100%;border-collapse:collapse;font-size:9pt}
thead tr{background:#1a3a5c;color:#fff}
th{padding:3px 6px;text-align:left;font-size:8.5pt;font-weight:600;letter-spacing:.02em}
td{padding:3px 6px;border-bottom:1px solid #eee;vertical-align:middle}
.c{text-align:center}
.mono{font-family:'Consolas','Courier New',monospace;font-size:8.8pt}
.sanctie{color:#c00;font-weight:700;font-size:8.5pt}
tr.z td{background:#f9f9f9}
footer{margin-top:6mm;border-top:1px solid #ccc;padding-top:2mm;font-size:7.5pt;color:#888;
       display:flex;justify-content:space-between}
/* Standaard-pagina's: footer met tagline + paginanummer via @page
   margin-boxes (Chrome's print engine supports). 1.6cm bottom-margin
   reserveert ruimte voor de footer-content; 1cm aan de zijden + top. */
@page{
    size:A4 portrait;
    margin:1cm 1cm 1.6cm;
    @bottom-left{
        content:"${esc(T('tagline_footer'))}";
        font:8pt Arial,sans-serif;
        color:#1a3a5c;
        padding-top:5mm;
    }
    @bottom-right{
        content:"${esc(T('pagina_label'))} " counter(page) "/" counter(pages);
        font:8pt Arial,sans-serif;
        color:#888;
        padding-top:5mm;
    }
}
/* Voorblad krijgt eigen @page zonder footer-margins en zonder content
   in de margin-boxes — de titelpagina hoort schoon te blijven. */
@page voorblad{
    size:A4 portrait;
    margin:1cm;
    @bottom-left{content:""}
    @bottom-right{content:""}
}
.protokol-voorblad{page:voorblad}
@media print{
  tr{page-break-inside:avoid}
  thead{display:table-header-group}
  .dc-block{page-break-inside:avoid}
}

/* ── Protokol: voorblad ───────────────────────────────────────── */
.protokol-voorblad{
    page-break-after:always;
    height:25cm;
    display:flex;flex-direction:column;
    justify-content:space-between;align-items:center;
    text-align:center;padding:1cm 0;
}
.protokol-voorblad .vb-logos{
    display:flex;gap:2cm;align-items:flex-end;justify-content:center;
    flex-wrap:wrap;min-height:6cm;
}
.protokol-voorblad .vb-logo-blok{
    display:flex;flex-direction:column;align-items:center;gap:5mm;
    max-width:8cm;
}
.protokol-voorblad .vb-logo{display:flex;align-items:center;justify-content:center}
.protokol-voorblad .vb-logo img.pr-logo-xl{
    max-height:6cm;max-width:8cm;object-fit:contain;
}
.protokol-voorblad .vb-logo-naam{font-size:10pt;color:#444;line-height:1.2}
.protokol-voorblad .vb-mid{display:flex;flex-direction:column;align-items:center;gap:4mm}
.protokol-voorblad .vb-titel{font-size:28pt;font-weight:700;color:#1a3a5c;line-height:1.1}
.protokol-voorblad .vb-sub{font-size:14pt;color:#555;letter-spacing:.05em;text-transform:uppercase}
.protokol-voorblad .vb-meta{font-size:12pt;color:#333;margin-top:1mm}
.protokol-voorblad .vb-bot{min-height:1cm;font-size:9pt;color:#888}

/* Voorblad MET foto: foto vult de bovenste helft van de pagina edge-
   to-edge, met daarop de twee logo's in de hoeken met witte achtergrond
   zodat ze leesbaar blijven op elke foto-achtergrond. Onder de foto
   komt de standaard vb-mid + vb-bot structuur. */
.protokol-voorblad.pv-met-foto{justify-content:flex-start;padding-top:0}
.pv-foto-wrap{
    position:relative;width:100%;height:12.5cm;
    margin:0 0 1cm;overflow:hidden;
}
.pv-foto{width:100%;height:100%;object-fit:cover;display:block}
.pv-logo-hoek{
    position:absolute;top:5mm;
    background:#fff;padding:4mm 6mm;border-radius:3px;
    box-shadow:0 1px 4px rgba(0,0,0,.15);
    display:flex;align-items:center;justify-content:center;
}
.pv-logo-links{left:5mm}
.pv-logo-rechts{right:5mm}
.pv-logo-hoek img.pr-logo-xl{max-height:2.4cm;max-width:5cm;object-fit:contain}

/* ── Protokol: midden-bladen (officials, sponsoren, nawoord) ─── */
.protokol-blad{page-break-after:always;min-height:24cm}
.pr-blad-titel{
    font-size:18pt;font-weight:700;color:#1a3a5c;
    border-bottom:1.5px solid #1a3a5c;padding-bottom:2mm;margin:0 0 6mm;
}
.pr-nawoord{font-size:10pt;line-height:1.5;color:#222;white-space:normal;max-width:18cm}
/* Nawoord-foto: gefloat rechts zodat de tekst er omheen valt. Caption
   onder de foto, klein en gedimd. */
.pr-nawoord-foto{
    float:right;margin:0 0 4mm 6mm;
    max-width:5cm;text-align:center;
}
.pr-nawoord-foto img{
    width:100%;height:auto;max-height:7cm;object-fit:cover;
    border-radius:3px;border:1px solid #ddd;
}
.pr-nawoord-foto figcaption{
    font-size:8.5pt;color:#555;margin-top:1.5mm;line-height:1.3;
}
.pr-sub-titel{
    font-size:12pt;font-weight:600;color:#1a3a5c;
    margin:5mm 0 2mm;padding-bottom:1mm;border-bottom:1px solid #ccc;
}
.pr-sub-titel:first-of-type{margin-top:0}
.pr-namen-lijst{
    list-style:none;padding:0;margin:0 0 3mm;font-size:10.5pt;color:#222;
}
.pr-namen-lijst li{padding:1mm 0;border-bottom:1px dotted #eee}
.pr-namen-2kol{
    column-count:2;column-gap:8mm;
}
.pr-namen-2kol li{break-inside:avoid}
/* Jury-sectie in 2 kolommen: links hoofdscheidsrechter t/m speaker,
   rechts algemeen jurylid + stagiair. align-items:start + align-self
   op de kinderen voorkomt dat de kortere kolom wordt uitgerekt of
   verticaal gecentreerd — beide kolommen beginnen bovenaan. */
.pr-jury-2kol{display:grid;grid-template-columns:1fr 1fr;gap:4mm 6mm;align-items:start}
.pr-jury-2kol > *{align-self:start}
/* !important forceert top-align tegen browser-default (middle) op print-
   cellen in. Anders zit "Dionne van Eig" verticaal gecentreerd in een
   cell die hoger is door label-wrap (bv. "General Jury Member"). */
.pr-officials th,.pr-officials td{vertical-align:top!important}
/* Voetnoot-systeem voor dubbelrollen: superschrift naast de naam +
   verklaring onder de tabel. Personen met dezelfde extra-rollen delen
   één noot. */
.pr-noot-marker{font-size:.7em;color:#1565c0;vertical-align:super;margin-left:1px}
.pr-jury-noten{margin-top:4mm;font-size:8.5pt;color:#555;line-height:1.4}
.pr-jury-noten div{margin-bottom:1mm}
.pr-officials{width:100%;border-collapse:collapse;font-size:10.5pt;margin:0 0 3mm}
.pr-officials th{
    background:none;color:#1a3a5c;text-align:right;
    padding:1.5mm 6mm 1.5mm 0;font-weight:600;width:40%;
    border-bottom:1px dotted #ccc;text-transform:capitalize;
}
.pr-officials td{padding:1.5mm 0;border-bottom:1px dotted #ccc;color:#222}

/* Sponsoren-grid: 2 kolommen voor extra ademruimte per sponsor. Logo's
   mogen flink groot. Bij heel veel sponsors wrapt 'ie naar meerdere
   pagina's (.protokol-blad heeft page-break-after). */
.pr-sponsors-grid{
    display:grid;grid-template-columns:repeat(2, 1fr);gap:12mm 10mm;
}
.pr-sp-link{text-decoration:none;color:inherit}
.pr-sp-card{
    border:1px solid #eee;border-radius:4px;padding:8mm 6mm;
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    min-height:60mm;
}
.pr-sp-card img{max-width:100%;max-height:45mm;object-fit:contain;margin-bottom:5mm}
.pr-sp-naam{font-size:11pt;color:#444;text-align:center;line-height:1.3}
.pr-sp-no-logo{
    font-size:16pt;color:#1a3a5c;font-weight:600;text-align:center;
    padding:12mm 0;
}

/* Deelnemerslijst: header zonder background-fill (gelijk aan de jury-
   tabel-stijl, geen donkerblauwe balk die de tekst onleesbaar maakt),
   afstand-headers geroteerd zodat lange namen smal kunnen passen.
   7 sub-kolommen voor het non-afstand-gedeelte (Snr + 4×Naam + Cat
   + Nat) → op rij 2 spant Club 3 en Sponsor 3, beide ruim. */
.pr-deelnemers{
    width:100%;border-collapse:collapse;font-size:9pt;margin-top:2mm;
    table-layout:fixed;
}
/* Overschrijft globale thead tr-styling (donkerblauwe balk voor uitslagen). */
.pr-deelnemers thead tr{background:transparent;color:inherit}
.pr-deelnemers th{
    text-align:left;padding:2mm 1.5mm;
    font-size:8.5pt;font-weight:600;color:#1a3a5c;
    background:transparent;
    border-bottom:2px solid #1a3a5c;
}
.pr-deelnemers .dl-snr,.pr-deelnemers .dl-cat,
.pr-deelnemers .dl-nat,.pr-deelnemers .dl-afst-h{text-align:center}
.pr-deelnemers td{padding:1mm 1.5mm;vertical-align:middle}
.pr-deelnemers tr.dl-r1 td{padding-top:1.8mm}
.pr-deelnemers tr.dl-r2 td{padding-bottom:1.8mm;font-size:8.5pt;color:#555}
.pr-deelnemers tbody tr.dl-r2{border-bottom:1px dotted #c0c0c0}
/* Verticale kolomscheidingen — lichte grijze lijntjes voor alle
   kolommen, plus twee dikke blauwe lijnen na Snr en na Nat (= einde
   van het persoons-blok) die doorlopen tot onderaan de tabel. */
.pr-deelnemers .dl-snr,.pr-deelnemers .dl-naam,.pr-deelnemers .dl-club,
.pr-deelnemers .dl-cat,.pr-deelnemers .dl-nat,.pr-deelnemers .dl-sponsor,
.pr-deelnemers .dl-afst-h,.pr-deelnemers .dl-afst-c{
    border-right:1px solid #e0e0e0;
}
.pr-deelnemers .dl-afst-c:last-child,
.pr-deelnemers .dl-afst-h:last-child{border-right:none}
/* Dikke lijnen: na Snr en na Nat (rij 1) + na Sponsor (rij 2, ligt op
   dezelfde positie als Nat door colspan-layout). Door rowspan resp.
   colspan loopt elke lijn keurig van header tot laatste rij. */
.pr-deelnemers .dl-snr,
.pr-deelnemers .dl-nat,
.pr-deelnemers .dl-sponsor,
.pr-deelnemers .dl-sponsor-h{border-right:2px solid #1a3a5c}

/* Header buitenrand — boven/links/rechts in 2px blauw zelfde kleur als
   onderkant. Onder is al 2px via .pr-deelnemers th border-bottom.
   Tussen rij 1 (Naam/Cat/Nat) en rij 2 (Club/Sponsor) → dunne grijze
   tussenlijn, niet de dikke header-onderkant-lijn (die is alleen onder
   de echte header-bottom, niet tussen header-rijen). */
.pr-deelnemers thead tr:first-child th{border-top:2px solid #1a3a5c}
.pr-deelnemers thead tr:first-child th:not([rowspan]){
    border-bottom:1px solid #e0e0e0;
}

/* Verticale buitenranden doorvoeren over ALLE rijen (header + body).
   dl-snr (rowspan=2 in header, rowspan=2 in body) krijgt left 2px;
   laatste afstand-cel idem rechts. */
.pr-deelnemers .dl-snr{border-left:2px solid #1a3a5c}
.pr-deelnemers .dl-afst-h:last-child,
.pr-deelnemers .dl-afst-c:last-child{border-right:2px solid #1a3a5c}
.pr-deelnemers .dl-snr{font-weight:600;font-variant-numeric:tabular-nums}
.pr-deelnemers .dl-naam{font-weight:500;color:#111}
.pr-deelnemers .dl-club{font-style:italic}
.pr-deelnemers .dl-afst-h{
    font-size:7.5pt;writing-mode:vertical-rl;transform:rotate(180deg);
    padding:2mm 0;line-height:1.1;
}
.pr-deelnemers .dl-afst-c{
    text-align:center;font-family:Arial,sans-serif;font-weight:600;
    color:#1a3a5c;font-size:10pt;
}
.pr-deelnemers .dl-c-snr {width:10mm}
.pr-deelnemers .dl-c-naam{width:auto}
.pr-deelnemers .dl-c-cat {width:14mm}
.pr-deelnemers .dl-c-nat {width:12mm}
.pr-deelnemers .dl-c-afst{width:10mm}

/* Tussen-titel voor uitslagen-secties (begin van het uitslagen-deel) */
.pr-uitslagen-intro{page-break-after:auto;margin:0 0 6mm}

/* Afsluitend blad */
.protokol-afsluiting{
    height:25cm;
    display:flex;flex-direction:column;justify-content:center;align-items:center;
    text-align:center;gap:1cm;page-break-before:always;
}
.protokol-afsluiting .af-titel{font-size:24pt;font-weight:700;color:#1a3a5c}
.protokol-afsluiting .af-sub{font-size:13pt;color:#555;margin-top:2mm}
.protokol-afsluiting .af-logos{
    display:grid;grid-template-columns:repeat(3, 1fr);gap:1.5cm;
    width:100%;max-width:18cm;align-items:start;
}
.protokol-afsluiting .af-logo-blok{
    display:flex;flex-direction:column;align-items:center;gap:3mm;
}
.protokol-afsluiting .af-logo-naam{
    font-size:12pt;font-weight:600;color:#1a3a5c;
}
.protokol-afsluiting .af-logo-url{
    font-size:8.5pt;color:#666;font-family:'Consolas','Courier New',monospace;
    overflow-wrap:anywhere;text-align:center;
}
.protokol-afsluiting .af-contact{
    font-size:10.5pt;color:#555;margin-top:4mm;
}
.protokol-afsluiting .af-contact a{color:#1565c0;text-decoration:none}
.pr-ic-logo{width:30mm;height:30mm;object-fit:contain}

/* Organisatie-logo formaten: klein (header, afsluiting) + groot (voorblad) */
.pr-org-logo{max-height:55px;max-width:180px;object-fit:contain}
.pr-org-logo-groot{max-height:90px;max-width:280px;object-fit:contain}
</style></head>
<body>
${_voorbladHtml}
${_nawoordHtml}
${_officialsHtml}
${_sponsorenHtml}
${_deelnemersHtml}
${_uitslagenIntroHtml}
<header>
  <div class="hdr-links">
    <div class="hdr-titel">${esc(comp.name)}</div>
    <div class="hdr-meta">
        ${esc([datumBereik, locatie, orgNaam].filter(Boolean).join(' · '))}
    </div>
  </div>
  <div class="hdr-rechts">${orgLogoHtml}</div>
</header>
<hr class="top-rule">
${dcBlocks}
${_afsluitingHtml}
<script>window.addEventListener('load', () => { window.focus(); window.print(); });<\/script>
</body></html>`;

    // Geen window.close() in print-script — operator wil mogelijk
    // tussendoor de uitslag controleren of opnieuw printen vanuit
    // hetzelfde venster. Sluiten doet 'ie zelf wel.
    const win = window.open('', '_blank');
    if (!win) {
        toonBevestigDialog('Pop-up geblokkeerd — sta pop-ups toe voor deze site.', 'Afdrukken');
        return;
    }
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
// Modal voor poster-type + taalkeuze. Returnt { lang, type } | null.
// localStorage onthoudt zowel laatste type als laatste taal — zodat een
// operator die series posters print niet steeds opnieuw moet kiezen.
function kiesPosterOpties() {
    const laatsteLang = localStorage.getItem('poster_lang') || 'nl';
    const laatsteType = localStorage.getItem('poster_type') || 'public';
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog modal-dialog--smal">
                <div class="modal-header">
                    <span>Poster genereren</span>
                </div>
                <div class="modal-body">
                    <label for="poster-type-sel" style="display:block;font-weight:bold;margin-bottom:4px;">Welke poster?</label>
                    <select id="poster-type-sel" style="width:100%;padding:6px 8px;font-size:1em;margin-bottom:14px;">
                        <option value="public">Public — voor rijders / ouders</option>
                        <option value="coach">Coach — voor coaches</option>
                        <option value="check">Check — controleer inschrijving</option>
                        <option value="alle">Alle drie (sequentieel)</option>
                    </select>
                    <div style="font-weight:bold;margin-bottom:2px;">Taal:</div>
                </div>
                <div class="modal-knoppen modal-knoppen--gecentreerd">
                    <button class="modal-btn modal-annuleer" data-act="cancel">Annuleer</button>
                    <button class="modal-btn modal-doorgaan" data-act="nl">🇳🇱 Nederlands</button>
                    <button class="modal-btn modal-doorgaan" data-act="en">🇬🇧 English</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const typeSel = overlay.querySelector('#poster-type-sel');
        typeSel.value = laatsteType;
        const sluit = res => { overlay.remove(); resolve(res); };
        overlay.querySelectorAll('[data-act]').forEach(b => {
            b.addEventListener('click', () => {
                const act = b.dataset.act;
                if (act === 'cancel') return sluit(null);
                const type = typeSel.value;
                localStorage.setItem('poster_lang', act);
                localStorage.setItem('poster_type', type);
                sluit({ lang: act, type });
            });
        });
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(null); });
        // Focus op laatst gekozen taal voor snelle Enter-flow
        overlay.querySelector(`[data-act="${laatsteLang}"]`)?.focus();
    });
}

// Download één of meerdere posters voor een wedstrijd (of generiek voor de
// org als compId leeg is). Opties komen uit de kiesPosterOpties-dialog:
// `type` = 'public' | 'coach' | 'check' | 'alle' (download alle drie).
async function downloadPoster(compId = null) {
    if (!actieveOrg?.id) return;

    const opties = await kiesPosterOpties();
    if (!opties) return;
    const { lang, type } = opties;
    const types = type === 'alle' ? ['public', 'coach', 'check'] : [type];

    // Visuele feedback op de knop die is geklikt
    const btn = compId
        ? document.querySelector(`.beheer-comp-poster[data-id="${compId}"]`)
        : el('btn-org-poster');
    const origLabel = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Bezig…'; }

    try {
        for (const appType of types) {
            const params = new URLSearchParams({ org_id: actieveOrg.id, app: appType, lang });
            if (compId) params.set('competition_id', compId);

            const res = await fetch('api/poster.php?' + params.toString());

            // Fout-responses komen als JSON, PDF's als application/pdf
            const ct = res.headers.get('content-type') ?? '';
            if (!res.ok || !ct.startsWith('application/pdf')) {
                const txt = await res.text();
                let msg = 'Poster genereren mislukt.';
                try { msg = (JSON.parse(txt).error) ?? msg; } catch { /* raw text */ }
                throw new Error(`${appType}: ${msg}`);
            }

            const blob = await res.blob();
            const naam = (res.headers.get('content-disposition') ?? '')
                .match(/filename="([^"]+)"/)?.[1] ?? `${appType}-poster.pdf`;

            const url = URL.createObjectURL(blob);
            const a   = document.createElement('a');
            a.href    = url;
            a.download = naam;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }
    } catch (e) {
        toonBevestigDialog('Kon poster niet downloaden:\n\n' + e.message, 'Poster', 'OK', '');
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
