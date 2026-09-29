// ============================================================
//  InlineComp Public — public-uitslag.js
//
//  Bevat: renderResultaat + ronde-uitslagen + uitslagen-tab + serie-klassementen + afstand/klassement-tabellen.
//  Geextraheerd uit public/app.js op 2026-09-30 (fase 3 van refactor-plan).
//  Klassieke script-tag: gedeelde global scope met de andere public-*.js modules.
// ============================================================

function renderResultaat(data, snr, prog) {
        let html = '';
        for (const r of data) {
            const p = r.persoon;
            // entry_status kan NULL zijn als de rijder wel bestaat maar niet
            // ingeschreven is voor deze wedstrijd (via naam/license toegevoegd).
            const nietIngeschreven = p.entry_status === null || p.entry_status === undefined;
            const st = nietIngeschreven ? -1 : parseInt(p.entry_status);
            const stLabel = nietIngeschreven ? t('status_niet_ingeschreven') : (st >= 0 && st <= 5 ? getStatusLabel(st) : t('status_onbekend'));

            // Status-badge: één badge als alle DC's dezelfde status hebben (of
            // niet-ingeschreven / geen DC-info). Bij VERSCHILLENDE status per DC
            // (bv. voor één afstand afgemeld) een klikbare "klik voor status"-
            // badge die een modal met afstand + status opent. Kleuren via
            // .persoon-status + .pstat-*-classes.
            const _dcSt  = Array.isArray(p.dc_statussen) ? p.dc_statussen : [];
            const _uniek = [...new Set(_dcSt.map(x => parseInt(x.status)))];
            let statusHtml;
            if (nietIngeschreven || _dcSt.length === 0 || _uniek.length <= 1) {
                statusHtml = `<span class="persoon-status ${_pstatClass(st, nietIngeschreven)}">${esc(stLabel)}</span>`;
            } else {
                _dcStatusMap[p.license_key] = { naam: p.full_name, dc: _dcSt };
                statusHtml = `<span class="persoon-status persoon-status-klik" onclick="toonStatusModal('${esc(p.license_key)}')">&#9432; ${esc(t('status_klik'))}</span>`;
            }

            html += `
            <div style="margin-top:16px">
                <div class="persoon-header">
                    <div><div class="persoon-naam">${esc(p.full_name)}</div>
                         <span class="persoon-snr">${esc(t('snr_label'))} ${esc(p.wedstrijd_snr??p.start_number)}</span>
                         ${statusHtml}</div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="persoon-cat">${esc(p.category)}</span>
                        <span class="auto-stempel" title="${esc(t('auto_stempel_title'))}">${_huidigStempel}</span>
                    </div>
                </div>
                <div class="tabs">
                    <button class="tab-btn active" data-tab="programma">${esc(t('tab_programma'))}</button>
                    <button class="tab-btn" data-tab="heats">${esc(t('tab_heats'))}</button>
                    <button class="tab-btn" data-tab="rondes">${esc(t('tab_rondes'))}</button>
                    <button class="tab-btn" data-tab="uitslagen">${esc(t('tab_uitslagen'))}</button>
                </div>
                <div class="kaart">`;

            // ── TAB: Programma ────────────────────────────────────────
            html += '<div class="tab-content active" data-tab="programma"><div class="kaart-sectie">';
            // (Klap-balk staat nu NA de filter-strook — coach-volgorde;
            //  Alles-uit/in werkt tenslotte alleen op de actuele afstand.
            //  "Wedstrijdprogramma"-titel weg: de tab zelf is al genoeg.)
            if (prog.ritten?.length) {
                // Interleave ritten en niet-ronde blokken (pauze, inrijden,
                // wedstrijdstart, ceremonie, herstart).
                //
                // KRITIEKE FIX (was: lexicale sort-bug): PDO returnt SMALLINT
                // velden als JS-strings ("10", "100", "20"). Zonder parseInt
                // werd `"100" <= "20"` lexicaal vergeleken (true!) waardoor
                // wedstrijdstart-dag-2 (volgorde="100") op verkeerde plek
                // tussen dag-1-ritten verscheen. Admin (tijdschema.js) doet
                // overal parseInt — public/coach hadden die niet.
                const num       = v => parseInt(v) || 0;
                const items     = [];
                const sortedBlk = (prog.blokken || []).slice()
                    .sort((a, b) => num(a.volgorde) - num(b.volgorde));
                let blkIdx = 0;
                for (const r of (prog.ritten || [])) {
                    const rBV = num(r.blok_volgorde);
                    while (blkIdx < sortedBlk.length
                           && num(sortedBlk[blkIdx].volgorde) <= rBV) {
                        items.push({ type:'blok', data: sortedBlk[blkIdx++] });
                    }
                    items.push({ type:'rit', data: r });
                }
                while (blkIdx < sortedBlk.length) {
                    items.push({ type:'blok', data: sortedBlk[blkIdx++] });
                }

                // Multi-day setup: meerdere wedstrijdstart-blokken → toon één
                // header per dag (Dag N — Zaterdag 28 mei) op de plek waar de
                // dag-cluster begint, niet alleen vóór de wedstrijdstart zelf.
                // Reden: inrijden+pauze direct vóór een wedstrijdstart horen
                // BIJ die nieuwe dag (warm-up voor de dag), niet bij de vorige.
                const wsBlokken = (prog.blokken || [])
                    .filter(b => (b.blok_type || '').toLowerCase() === 'wedstrijdstart')
                    .sort((a, b) => num(a.volgorde) - num(b.volgorde));
                const isMultiDag = wsBlokken.length > 1;
                // dagInfoPerNr: dagNr → { datumLbl (lang, voor header), kortLbl (knop) }
                // Beide locale-aware via getLocale() — Engels op een EN-instelling
                // gebruikt "Fri 28/5", Duits "Fr. 28.5.", Frans "ven. 28/5", etc.
                const dagInfoPerNr = new Map();
                const _locale = (typeof getLocale === 'function') ? getLocale() : 'nl-NL';
                wsBlokken.forEach((ws, i) => {
                    let datumLbl = '', kortLbl = '';
                    if (ws.datum) {
                        const d = new Date(ws.datum + 'T00:00:00');
                        if (!isNaN(d)) {
                            datumLbl = d.toLocaleDateString(_locale,
                                {weekday:'long', day:'numeric', month:'long'});
                            kortLbl  = d.toLocaleDateString(_locale,
                                {weekday:'short', day:'numeric', month:'numeric'});
                        }
                    }
                    dagInfoPerNr.set(i + 1, { datumLbl, kortLbl });
                });

                // Dag-toewijzing per item via twee passes:
                //   1. FORWARD: wedstrijdstart-items zetten huidige dag; ritten
                //      erven die. Andere blokken krijgen tentative natural-dag.
                //   2. BACKWARD: inrijd/pauze/etc. die NA hun natural-dag een
                //      opvolgende wedstrijdstart/rit met hogere dag hebben,
                //      claimen die hogere dag (= warm-up voor volgende dag).
                const dagPerItem = new Array(items.length);
                let huidigeDag = 0;
                items.forEach((it, idx) => {
                    if (it.type === 'blok'
                        && (it.data.blok_type || '').toLowerCase() === 'wedstrijdstart') {
                        const wsIdx = wsBlokken.findIndex(w => String(w.id) === String(it.data.id));
                        if (wsIdx >= 0) huidigeDag = wsIdx + 1;
                    }
                    dagPerItem[idx] = huidigeDag || 1; // pre-dag-1 items → tentative 1
                });
                // Alleen inrijden + pauze "horen bij de volgende dag" als ze
                // vóór een wedstrijdstart liggen — warm-up + baan-voorbereiding
                // zijn altijd vóór de start. Ceremonie (= afsluiting) blijft bij
                // de vorige dag, en blokkeert dan ook de claim-keten zodat
                // eerder-gelegen pauzes niet langs de ceremonie heen claimen.
                let komendeDag = null;
                for (let idx = items.length - 1; idx >= 0; idx--) {
                    const it = items[idx];
                    const bt = it.type === 'blok'
                        ? (it.data.blok_type || '').toLowerCase() : '';
                    const isWs = bt === 'wedstrijdstart';
                    if (it.type === 'rit' || isWs) {
                        komendeDag = dagPerItem[idx];
                    } else if (it.type === 'blok') {
                        const isWarmUp = (bt === 'inrijden' || bt === 'pauze');
                        if (isWarmUp && komendeDag
                            && dagPerItem[idx] < komendeDag) {
                            dagPerItem[idx] = komendeDag;
                        } else if (!isWarmUp) {
                            // ceremonie / herstart: keten breken
                            komendeDag = dagPerItem[idx];
                        }
                    }
                }

                const hhmm = v => { if (!v) return ''; const m = String(v).match(/(\d{1,2}:\d{2})/); return m ? m[1] : ''; };
                const blokIcoon = bt => ({pauze:'⏸',inrijden:'🛼',wedstrijdstart:'🏁',ceremonie:'🏆',herstart:'🔄'}[bt] || '🕓');
                const blokLabel = bt => {
                    const keyMap = {pauze:'prog_blok_pauze', inrijden:'prog_blok_inrijden', wedstrijdstart:'prog_blok_wedstrijdstart', ceremonie:'prog_blok_ceremonie', herstart:'prog_blok_herstart'};
                    return keyMap[bt] ? t(keyMap[bt]) : (bt || '').toUpperCase();
                };

                // Nieuwe filter-strook: dag- + afstand-triggers, elk uitklapbaar
                // naar een pill-balk (identieke stijl als de "Alles in / uit / Mijn"
                // balk). Na keuze klapt het paneel weer in. Bij 1 dag verdwijnt
                // dag-trigger, bij 1 unieke afstand verdwijnt afstand-trigger.
                // Verzamel unieke afstanden per dag uit items (heeft distance_naam).
                const _afsPerDag = new Map();  // dag -> Set<afstand>
                const _afsAlle   = new Set();
                items.forEach((it, idx) => {
                    if (it.type !== 'rit' || !it.data.distance_naam) return;
                    const dg = dagPerItem[idx];
                    if (!_afsPerDag.has(dg)) _afsPerDag.set(dg, new Set());
                    _afsPerDag.get(dg).add(it.data.distance_naam);
                    _afsAlle.add(it.data.distance_naam);
                });
                const afsAlleArr = [..._afsAlle].sort((a,b) => a.localeCompare(b, 'nl', {numeric:true}));
                const heeftMeerdereAfs = afsAlleArr.length > 1;
                html += '<div class="prog-sticky-kop">';   // filter + klap-balk blijven boven bij scrollen
                if (isMultiDag || heeftMeerdereAfs) {
                    // JSON van afstanden-per-dag zodat JS bij dag-wissel het paneel kan filteren.
                    const afsPerDagObj = {};
                    for (const [dg, set] of _afsPerDag) afsPerDagObj[dg] = [...set].sort((a,b) => a.localeCompare(b, 'nl', {numeric:true}));
                    html += `<div class="prog-filter-strook" data-actieve-dag="alle" data-actieve-afstand="alle"
                                  data-afs-per-dag='${esc(JSON.stringify(afsPerDagObj))}'>`;
                    if (isMultiDag) {
                        html += `<button class="prog-filter-trigger" type="button" data-filter="dag" onclick="togglePanel(this)">
                            <span class="prog-filter-icon">📅</span>
                            <span class="prog-filter-lbl">${esc(t('prog_filter_alle_dagen'))}</span>
                            <span class="prog-filter-caret">▼</span>
                        </button>
                        <div class="prog-filter-panel verborgen" data-panel="dag">
                            <button class="prog-filter-pill actief" type="button" data-value="alle"
                                    onclick="kiesProgFilter('dag','alle',this)">${esc(t('prog_dag_alle'))}</button>`;
                        for (let dn = 1; dn <= wsBlokken.length; dn++) {
                            const info     = dagInfoPerNr.get(dn);
                            const subDatum = info?.kortLbl
                                ? `<span class="prog-filter-pill-sub">${esc(info.kortLbl)}</span>`
                                : '';
                            html += `<button class="prog-filter-pill" type="button" data-value="${dn}"
                                             onclick="kiesProgFilter('dag','${dn}',this)"
                                             title="${esc(info?.datumLbl || '')}"
                                >${esc(t('prog_dag'))} ${dn}${subDatum}</button>`;
                        }
                        html += `</div>`;
                    }
                    if (heeftMeerdereAfs) {
                        html += `<button class="prog-filter-trigger" type="button" data-filter="afstand" onclick="togglePanel(this)">
                            <span class="prog-filter-icon">🏁</span>
                            <span class="prog-filter-lbl">${esc(t('prog_filter_alle_afstanden'))}</span>
                            <span class="prog-filter-caret">▼</span>
                        </button>
                        <div class="prog-filter-panel verborgen" data-panel="afstand">
                            <button class="prog-filter-pill actief" type="button" data-value="alle"
                                    onclick="kiesProgFilter('afstand','alle',this)">${esc(t('prog_afstand_alle'))}</button>`;
                        for (const afs of afsAlleArr) {
                            html += `<button class="prog-filter-pill" type="button" data-value="${esc(afs)}"
                                             onclick="kiesProgFilter('afstand',this.dataset.value,this)"
                                >${esc(afs)}</button>`;
                        }
                        html += `</div>`;
                    }
                    html += `</div>`;
                }

                // Klap-balk NA de filter-strook (2026-07-06: matcht coach-
                // volgorde). Alles-uit/in/mijn opereert alleen binnen de
                // gekozen dag+afstand, dus logisch dat filter erboven staat.
                html += `<div class="prog-klap-balk" data-actief="in">
                    <button type="button" class="prog-klap-btn actief" data-actie="in"   onclick="klapProgPub(this,'in')">▶ ${esc(t('prog_klap_alles_uit'))}</button>
                    <button type="button" class="prog-klap-btn" data-actie="uit"  onclick="klapProgPub(this,'uit')">▼ ${esc(t('prog_klap_alles_in'))}</button>
                    <button type="button" class="prog-klap-btn" data-actie="mijn" onclick="klapProgPub(this,'mijn')">👤 ${esc(t('prog_klap_mijn'))}</button>
                </div>`;
                html += '</div>';   // /prog-sticky-kop

                // Rit-namen van de ándere gevolgde kinderen — lokaal uit _kinderen
                // (elk kind heeft z'n lookup/heats al opgehaald), dus GÉÉN extra
                // dataverkeer. Alleen hier (Programma-tab) markeren we ze, in een
                // andere kleur dan de geselecteerde rijder.
                const _actLic = r.persoon?.license_key;
                const familieRitNamen = new Set();
                if (typeof _kinderen !== 'undefined' && _kinderen.length > 1) {
                    for (const _k of _kinderen) {
                        const _kp   = _k.data?.[_k.kozen_idx ?? 0];
                        const _kLic = _kp?.persoon?.license_key;
                        if (!_kp || !_kLic || _kLic === _actLic) continue;
                        for (const _h of (_kp.heats || [])) if (_h.rit_naam) familieRitNamen.add(_h.rit_naam);
                    }
                }
                if (familieRitNamen.size) {
                    html += `<div class="prog-legenda">
                        <span class="prog-leg-item"><span class="prog-leg-swatch leg-mijn"></span>${esc(t('prog_leg_mijn'))}</span>
                        <span class="prog-leg-item"><span class="prog-leg-swatch leg-familie"></span>${esc(t('prog_leg_familie'))}</span>
                    </div>`;
                }

                let nr = 0;
                let vorigeDag = null;
                let vorigeCombi = null;
                let vorigeGroepKey = null;
                let combiWrapOpen = false;   // outer wrapper om meerdere cat-groepen die samen rijden
                // Live-tellers voor de huidige groep. Post-render vullen we
                // de markers met mijn-dot en status-icoon aan.
                let huidigeGroepHeeftMijn = false;
                let huidigeGroepHeeftFamilie = false;
                let huidigeGroepAantalRitten = 0;
                let huidigeGroepMetRes = 0;
                let huidigeGroepDefinitief = 0;
                const groepHdrPlaceholders = [];

                // Combi-wrapper open/sluit: outer container die meerdere cat-
                // groepen (Pupil 1 meisjes + Pupil 1 jongens) omhult wanneer ze
                // in dezelfde combi_group zitten. Elke cat behoudt eigen inklap.
                const openCombiWrap = (dag, afstand) => {
                    const afsAttr = afstand ? ` data-afstand-key="${esc(afstand)}"` : '';
                    html += `<div class="prog-combi-wrap" data-dag-nr="${dag}"${afsAttr}>
                        <div class="prog-combi-kop">${esc(t('prog_combi_kop'))}</div>
                        <div class="prog-combi-body">`;
                    combiWrapOpen = true;
                };
                const sluitCombiWrap = () => {
                    if (combiWrapOpen) { html += `</div></div>`; combiWrapOpen = false; }
                    vorigeCombi = null;
                };
                const bepaalStatusPub = () => {
                    if (huidigeGroepAantalRitten === 0) return { icon: '', i18nKey: '' };
                    if (huidigeGroepMetRes === huidigeGroepAantalRitten)  return { icon: '🏁', i18nKey: 'prog_groep_status_klaar' };
                    if (huidigeGroepMetRes > 0)                           return { icon: '◑', i18nKey: 'prog_groep_status_deels' };
                    if (huidigeGroepDefinitief === huidigeGroepAantalRitten) return { icon: '🚩', i18nKey: 'prog_groep_status_geloot' };
                    return { icon: '', i18nKey: '' };
                };
                const sluitGroepPub = () => {
                    if (vorigeGroepKey !== null) {
                        html += `</div></div>`;
                        const st = bepaalStatusPub();
                        groepHdrPlaceholders.push({
                            key: vorigeGroepKey,
                            heeftMijn: huidigeGroepHeeftMijn,
                            heeftFamilie: huidigeGroepHeeftFamilie,
                            statusIcon: st.icon,
                            statusKey: st.i18nKey,
                        });
                        vorigeGroepKey = null;
                        huidigeGroepHeeftMijn = false;
                        huidigeGroepHeeftFamilie = false;
                        huidigeGroepAantalRitten = 0;
                        huidigeGroepMetRes = 0;
                        huidigeGroepDefinitief = 0;
                    }
                };
                // Volledige sluit: eerst groep, dan combi-wrapper.
                const sluitAllesPub = () => { sluitGroepPub(); sluitCombiWrap(); };
                const openGroepPub = (key, rit, dag) => {
                    // Bij eerste render van een rijder-tab: altijd ingeklapt
                    // (default). `_progIngeklaptPub` wordt pas post-render
                    // gevuld — dus bij render-tijd zou alles open lijken.
                    const ingeklapt = _progEersteRenderPub || _progIngeklaptPub.has(key);
                    const rondeLbl  = rit.ronde_type && BADGE[rit.ronde_type]
                        ? `<span class="heat-card-badge ${BADGE[rit.ronde_type]}" style="margin-right:6px">${getRondeLabel(rit.ronde_type)}</span>`
                        : '';
                    const idx = groepHdrPlaceholders.length;
                    const iconMarker = `[[STATUS-ICON-${idx}]]`;
                    // Marker in de class-attribute: bij post-fix vervangen we
                    // deze met " mijn" (oranje strip-left) of "". Inline zodat
                    // multi-kind view geen scope-verwarring krijgt.
                    const mijnMarker = `[[MIJN-CLASS-${idx}]]`;
                    const afsAttr = rit.distance_naam ? ` data-afstand-key="${esc(rit.distance_naam)}"` : '';
                    const rtAttr  = rit.ronde_type ? ` data-ronde-type="${esc(rit.ronde_type)}"` : '';
                    html += `<div class="prog-groep${ingeklapt ? ' ingeklapt' : ''}${mijnMarker}" data-groep-key="${esc(key)}" data-dag-nr="${dag}"${afsAttr}${rtAttr}>
                        <div class="prog-groep-hdr" onclick="klapGroepPub(this)">
                            <span class="prog-groep-chev">▼</span>
                            <span class="prog-groep-status">${iconMarker}</span>
                            <span class="prog-groep-titel">${rondeLbl}${esc(rit.dc_naam ?? '')}</span>
                            [[MULTI-DOT-${idx}]]
                        </div>
                        <div class="prog-groep-body">`;
                    vorigeGroepKey = key;
                    huidigeGroepHeeftMijn = false;
                    huidigeGroepHeeftFamilie = false;
                    huidigeGroepAantalRitten = 0;
                    huidigeGroepMetRes = 0;
                    huidigeGroepDefinitief = 0;
                };

                items.forEach((it, idx) => {
                    const dag = dagPerItem[idx];
                    // Dag-header + sluit alles bij dag-wisseling.
                    if (isMultiDag && dag !== vorigeDag) {
                        sluitAllesPub();
                        const info = dagInfoPerNr.get(dag);
                        const lbl = info?.datumLbl ? `Dag ${dag} — ${info.datumLbl}` : `Dag ${dag}`;
                        html += `<div class="prog-dag-header" data-dag-nr="${dag}">${esc(lbl)}</div>`;
                        vorigeDag = dag;
                    }
                    // Blok = tussen groepen op tijd-plek — sluit ook combi-wrapper.
                    if (it.type === 'blok') {
                        sluitAllesPub();
                        const b = it.data;
                        const bt = (b.blok_type || '').toLowerCase();
                        const tijd = hhmm(b.tijdstip);
                        const tijdHtml = tijd ? `<span class="prog-blok-tijd">🕓 ${esc(tijd)}</span>` : '';
                        const duurHtml = b.duur ? `<span class="prog-blok-duur">${b.duur} ${t('prog_blok_min')}</span>` : '';
                        const opmHtml  = b.opmerking ? `<span class="prog-blok-opm"> — ${esc(b.opmerking)}</span>` : '';
                        const catsHtml = b.inrijd_cat_namen ? `<div class="prog-blok-cats">${esc(b.inrijd_cat_namen)}</div>` : '';
                        html += `<div class="prog-blok-rij prog-blok-${esc(bt)}" data-dag-nr="${dag}">
                            <div class="prog-blok-top">
                                ${tijdHtml}
                                <span class="prog-blok-titel">${blokIcoon(bt)} ${esc(blokLabel(bt))}</span>
                                ${duurHtml}
                                ${opmHtml}
                            </div>
                            ${catsHtml}
                        </div>`;
                        return;
                    }
                    const rit = it.data;
                    nr++;
                    // 1) Combi-wrap: bij combi_group-wissel sluit lopende groep
                    //    + combi-wrapper, en open nieuwe combi-wrapper indien
                    //    de nieuwe rit een combi_group heeft.
                    const combi = rit.combi_group ? parseInt(rit.combi_group) : null;
                    if (combi !== vorigeCombi) {
                        sluitGroepPub();
                        sluitCombiWrap();
                        if (combi !== null) openCombiWrap(dag, rit.distance_naam);
                        vorigeCombi = combi;
                    }
                    // 2) Groep per (dc_naam + ronde_type + dag) — binnen combi-wrap.
                    const grpKey = `${rit.dc_naam || '?'}|${rit.ronde_type || '?'}|${dag}`;
                    if (grpKey !== vorigeGroepKey) {
                        sluitGroepPub();
                        openGroepPub(grpKey, rit, dag);
                    }

                    const isInRit = r.heats.some(h => h.rit_naam === rit.rit_naam);
                    const heeftAnder = familieRitNamen.has(rit.rit_naam);
                    const isFamilie  = !isInRit && heeftAnder;   // alleen ander kind → violet
                    const ookFamilie =  isInRit && heeftAnder;   // geselecteerd kind + ander kind samen
                    if (isInRit) huidigeGroepHeeftMijn = true;
                    if (heeftAnder) huidigeGroepHeeftFamilie = true;
                    huidigeGroepAantalRitten += 1;
                    const gereden = rit.resultaten_count > 0;
                    if (gereden) huidigeGroepMetRes += 1;
                    if (rit.definitief) huidigeGroepDefinitief += 1;

                    const rt = rit.ronde_type ?? 'heats';
                    const statusIcon = gereden ? '🏁'
                                     : rit.definitief ? '🚩'
                                     : '';
                    const opmHtml = rit.rit_opmerking
                        ? `<div class="prog-rit-opm">📝 ${esc(rit.rit_opmerking)}</div>` : '';
                    html += `<div class="prog-rij${isInRit ? ' prog-rij-mijn' : ''}${isFamilie ? ' prog-rij-familie' : ''}"
                                 data-rit-naam="${esc(rit.rit_naam)}" data-dc-naam="${esc(rit.dc_naam)}"
                                 data-dag-nr="${dag}" onclick="toonRitDetail(this)">
                        <span class="prog-nr">${statusIcon} ${nr}</span>
                        <span class="prog-naam">${esc(rit.rit_naam)}${opmHtml}</span>
                        ${ookFamilie ? `<span class="prog-rij-multi" title="${esc(t('prog_multi_title'))}">👥</span>` : ''}
                        <span class="prog-type heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                    </div>`;
                });
                sluitAllesPub();

                // Post-fix: status-icoon + mijn-class inline vervangen.
                // De ` mijn`-class geeft de oranje strip-links (duidelijker
                // bij scrollen dan een kleine stip achter de titel).
                groepHdrPlaceholders.forEach((p, i) => {
                    const iconMarker  = `[[STATUS-ICON-${i}]]`;
                    const klasseMarker = `[[MIJN-CLASS-${i}]]`;
                    const iconHtml = p.statusIcon
                        ? `<span title="${esc(t(p.statusKey))}">${p.statusIcon}</span>`
                        : '';
                    const klasseHtml = p.heeftMijn ? ' mijn' : (p.heeftFamilie ? ' familie' : '');
                    const multiMarker = `[[MULTI-DOT-${i}]]`;
                    const multiHtml = (p.heeftMijn && p.heeftFamilie)
                        ? `<span class="prog-groep-multi-dot" title="${esc(t('prog_multi_title'))}"></span>` : '';
                    html = html.replace(iconMarker, iconHtml).replace(klasseMarker, klasseHtml).replace(multiMarker, multiHtml);
                });
                _progAlleKeysPub = groepHdrPlaceholders.map(p => p.key);
                _progGroepenMetMijnPub.clear();
                groepHdrPlaceholders.forEach(p => {
                    if (p.heeftMijn) _progGroepenMetMijnPub.add(p.key);
                });
                if (_progEersteRenderPub) {
                    _progAlleKeysPub.forEach(k => _progIngeklaptPub.add(k));
                    _progEersteRenderPub = false;
                }
            } else {
                html += `<div class="melding">${esc(t('msg_programma_nb'))}</div>`;
            }
            html += '</div></div>';

            // ── TAB: Heats (heat-cards) ──────────────────────────────
            html += '<div class="tab-content" data-tab="heats">';
            if (r.heats.length) {
                for (const h of r.heats) {
                    const rt = h.ronde_type ?? 'heats';
                    const naam = h.rit_naam ?? h.heat_naam ?? '';

                    // Vorige ronde nog niet compleet → placeholder ipv heat-card.
                    // Backend zet deze flag voor KF/HF/Finale/Runner-up als de
                    // bron-ronde nog niet helemaal verwerkt is.
                    if (h.vorige_niet_compleet) {
                        html += `<div class="heat-card heat-card-pending">
                            <div class="heat-card-titel">
                                <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                                <span class="flex-1">${esc(naam)}</span>
                                <span style="font-size:1rem" title="${esc(t('heat_wachten_vorige'))}">⏳</span>
                            </div>
                            <div style="padding:.6rem .8rem;color:#666;font-style:italic;font-size:.85rem">
                                ${esc(t('msg_vorige_ronde_nb'))}
                            </div>
                        </div>`;
                        continue;
                    }

                    const mijnTijd = h.tijd_ms != null ? msTijd(h.tijd_ms) : '';
                    const mijnPos = h.finishpositie != null ? '#' + h.finishpositie : '';
                    const mijnSanctie = sl(h.sanctie);
                    // Audit-spoor: bruto verschilt van officieel → toon
                    // beide (gemeten + officieel) met 📷 (fotofinish-
                    // wisseling) of ✋ (handmatige correctie door jury).
                    const heeftBrutoAudit = h.bruto_tijd_ms != null
                                         && h.tijd_ms != null
                                         && h.bruto_tijd_ms !== h.tijd_ms;
                    // == 1 (niet truthy-check): PDO/JSON kan is_photofinish als
                    // string "0"/"1" sturen — in JS is "0" truthy, dus de oude
                    // r.is_photofinish ? ... gaf altijd 📷 ipv ✋ voor RR-tijden.
                    const brutoIcon  = h.is_photofinish == 1 ? '📷' : '✋';
                    const brutoTijd  = heeftBrutoAudit ? msTijd(h.bruto_tijd_ms) : '';

                    const extra = heatExtraKolommen(h.rijders ?? [], rt);
                    const rijders = h.rijders ?? [];
                    const heeftResultaten = rijders.some(r => r.finishpositie != null || r.tijd_ms != null);
                    const heeftRijders = rijders.length > 0;
                    const heatIcon = heeftResultaten ? '🏁' : heeftRijders ? '🚩' : '';
                    html += `<div class="heat-card">
                        <div class="heat-card-titel">
                            <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                            <span class="flex-1">${esc(naam)}</span>
                            ${heatIcon ? `<span style="font-size:1rem">${heatIcon}</span>` : ''}
                        </div>
                        <table class="heat-card-tabel">
                        <thead>${heatTabelHeader(extra)}</thead>
                        <tbody>`;

                    // Sorteer heat-rijders — startvolgorde vóór de rit,
                    // finishvolgorde erna (zelfde helper als de rit-detail-
                    // modal in de programma-tab).
                    for (const rr of _sorteerHeatRijders(h.rijders ?? [])) {
                        // Match op license_key (uniek) — fallback op snr voor
                        // backwards-compat met oude payloads.
                        const isIk = (p.license_key && rr.license_key)
                            ? rr.license_key === p.license_key
                            : String(rr.snr) === snr;
                        html += heatTabelRij(rr, isIk, extra);
                    }

                    html += '</tbody></table>';
                    if (mijnTijd || mijnPos || mijnSanctie) {
                        // Format tijd-deel: bij audit-mismatch compact "✋ bruto → officieel",
                        // anders gewoon de officiële tijd. Pijl past op één regel.
                        const tijdHtml = heeftBrutoAudit
                            ? `${brutoIcon} ${esc(brutoTijd)} → ${esc(mijnTijd)}`
                            : (mijnTijd ? esc(mijnTijd) : '');
                        html += `<div class="heat-card-mijn-result">
                            <span>${esc(t('heat_jouw_resultaat'))}</span>
                            <span>${tijdHtml} ${mijnPos ? esc(mijnPos) : ''} ${mijnSanctie ? `<span class="heat-sanctie">${esc(mijnSanctie)}</span>` : ''}</span>
                        </div>`;
                    }
                    html += '</div>';
                }
            } else {
                html += `<div class="kaart-sectie"><div class="melding">${esc(t('msg_nog_geen_heats'))}</div></div>`;
            }
            html += '</div>';

            // ── TAB: Resultaten ───────────────────────────────────────
            // Geert 2026-07-01: vervangt de oude eind-uitslag-per-afstand.
            // Nieuwe layout: per afstand → per ronde de complete uitslag
            // met Q/q (zoals live-verwerking) + eind-uitslag onderaan.
            // Lazy-load: fetch bij eerste tab-activatie via renderRondeUitslagen().
            // Alle unieke DC-IDs waar deze rijder in zit — een rijder kan in
            // meerdere categorie-combinaties meedoen (bv. eigen cat + open cat).
            const _dcIdsRes = [...new Set((r.heats || []).map(h => h.distance_combination_id).filter(Boolean))].join(',');
            const _licRes   = esc(p.license_key ?? '');
            html += `<div class="tab-content" data-tab="rondes">
                <div class="ronde-uitslagen-container"
                     data-dc-ids="${esc(_dcIdsRes)}" data-lic="${_licRes}" data-geladen="0">
                    <div class="melding">${esc(t('msg_laden'))}</div>
                </div>
            </div>`;

            // ── TAB: Uitslagen (volledig overzicht) ──────────────────
            html += `<div class="tab-content" data-tab="uitslagen">
                <div class="kaart-sectie">
                <div class="kaart-sectie-titel">${esc(t('uitsl_titel'))}</div>
                <div class="uitsl-selects">
                    <select class="uitsl-cat-sel"><option value="">${esc(t('msg_laden'))}</option></select>
                    <select class="uitsl-dist-sel" disabled><option value="">${esc(t('uitsl_opt_kies_afstand'))}</option></select>
                </div>
                <div class="uitsl-tabel-wrap"></div>
            </div>
            <div class="kaart-sectie" data-serie-lijst hidden>
                <div class="kaart-sectie-titel">${esc(t('serie_titel'))}</div>
                <div data-serie-selector class="uitsl-selects"></div>
                <div class="serie-klas-tabel-wrap"></div>
            </div></div>`;

            html += '</div></div>'; // kaart + wrapper
        }

        // Schrijf in de kind-content-container (multi-rijder-modus) als die
        // bestaat, anders valt terug op het oude gedrag (divResult direct).
        const target = document.getElementById('kind-content') || divResult;
        target.innerHTML = html;

        // Tab-switching
        target.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const kaart = btn.closest('.tabs').nextElementSibling;
                if (!kaart) return;
                btn.closest('.tabs').querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                kaart.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
                kaart.querySelector(`.tab-content[data-tab="${btn.dataset.tab}"]`)?.classList.add('active');

                // Sub-tab-keuze onthouden per kind (voor multi-rijder-modus)
                if (typeof _kinderen !== 'undefined' && _kinderen[_activeKindIdx]) {
                    _kinderen[_activeKindIdx].sub_tab = btn.dataset.tab;
                }

                // Uitslagen-tab: laad categorieën bij eerste klik
                if (btn.dataset.tab === 'uitslagen') initUitslagenTab(kaart);
                // Rondes-tab (ronde-uitslagen): lazy-load bij eerste opening.
                if (btn.dataset.tab === 'rondes') {
                    const cont = kaart.querySelector('.ronde-uitslagen-container');
                    if (cont && cont.dataset.geladen === '0') renderRondeUitslagen(cont);
                }
            });
        });

}

// ── Resultaten-tab: ronde-uitslagen renderer ─────────────────────────────────
// Vervangt de oude eind-uitslag-per-afstand. Toont per afstand een blok, per
// ronde een sub-blok met de volledige uitslag (Q/q, sancties, ronde-info),
// en onderaan de eind-uitslag uit uitslag_afstand.
async function renderRondeUitslagen(container) {
    const compId = selComp.value;
    const dcIds  = (container.dataset.dcIds || '').split(',').filter(Boolean);
    const lic    = container.dataset.lic;
    if (!compId || !dcIds.length) { container.innerHTML = ''; return; }

    container.dataset.geladen = '1';
    container.innerHTML = `<div class="melding">${esc(t('msg_laden'))}</div>`;

    // Één fetch per DC — parallel voor snelheid. Combineer daarna de
    // distances-lijst; als er meerdere DCs waren staat elke DC z'n
    // afstanden onder elkaar (in DC-volgorde uit dcIds).
    let distances = [];
    try {
        const responses = await Promise.all(dcIds.map(dcId =>
            safeFetch(`?action=ronde_uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&license_key=${encodeURIComponent(lic || '')}`)
              .then(r => r.json())
        ));
        for (const data of responses) {
            if (Array.isArray(data?.distances)) distances = distances.concat(data.distances);
        }
    } catch (e) {
        container.innerHTML = `<div class="melding">${esc(t('err_prefix', {msg: e.message}))}</div>`;
        return;
    }

    if (!distances.length) {
        container.innerHTML = `<div class="melding">${esc(t('msg_nog_geen_resultaten'))}</div>`;
        return;
    }

    let html = '';
    for (const d of distances) {
        html += `<div class="rondeu-afstand">
            <div class="rondeu-afstand-titel">${esc(d.distance_naam)}</div>`;

        if (!d.rondes.length && !d.eind_uitslag.length) {
            html += `<div class="melding">${esc(t('rondeu_nog_niets'))}</div>`;
        }

        // Rondes
        for (const r of d.rondes) {
            html += `<div class="rondeu-ronde ${r.compleet ? '' : 'pending'}">
                <div class="rondeu-ronde-titel">
                    <span class="rondeu-badge badge-${r.ronde_type}">${esc(r.ronde_label)}</span>
                    ${r.compleet ? '' : `<span class="rondeu-pending">${esc(t('rondeu_pending'))}</span>`}
                </div>`;

            if (r.compleet && r.rijders.length) {
                // Kolom-decisies per race-type: bij puntenkoers/afvalkoers/inline
                // zijn rondes en (voor puntenkoers) sprint-punten inhoudelijk
                // veel belangrijker dan de tijd. Fin-kolom (officiële finish-
                // volgorde) tonen we altijd als er data is.
                const isLangeAfstand = ['puntenkoers','afvalkoers','inline'].includes(d.race_type);
                const heeftFin      = r.rijders.some(x => x.finishpositie != null);
                const heeftRondes   = isLangeAfstand && r.rijders.some(x => x.rondes != null);
                const heeftPkPunten = d.race_type === 'puntenkoers' && r.rijders.some(x => x.pk_punten != null);
                const pkMaxR        = heeftPkPunten ? pkMaxRnd(r.rijders) : 0;
                // Sorteer: Q eerst op tijd, dan q op tijd, dan rest op tijd/positie.
                // Voor runner-up: op ru_positie oplopend.
                const rijders = [...r.rijders];
                if (r.ronde_type === 'runner_up') {
                    rijders.sort((a, b) => (a.ru_positie ?? 999) - (b.ru_positie ?? 999));
                } else if (r.ronde_type === 'finale_b') {
                    // B-finales gescheiden per heat: eerst B1 alle rijders fin
                    // 1..n, dan B2 fin 1..n, etc. Anders worden ze door elkaar
                    // getoond (twee "fin 1"'s in verschillende heats).
                    rijders.sort((a, b) => {
                        const ha = a.heat_nr ?? 999, hb = b.heat_nr ?? 999;
                        if (ha !== hb) return ha - hb;
                        const fa = a.finishpositie ?? 999;
                        const fb = b.finishpositie ?? 999;
                        if (fa !== fb) return fa - fb;
                        const ta = a.tijd_ms ?? 999999999;
                        const tb = b.tijd_ms ?? 999999999;
                        return ta - tb;
                    });
                } else {
                    // Volgorde binnen een ronde:
                    //   1. Q's op tijd (snelste eerst)
                    //   2. q's op tijd
                    //   3. Overige rijders — bij doorstroom-rondes
                    //      (heats/KF/HF) puur op tijd (fin uit verschillende
                    //      heats is niet vergelijkbaar); bij finale_a is fin
                    //      de officiële ranking, tijd = tiebreaker.
                    //   4. Uitvallers (DNS/DNF/DQ-*) altijd helemaal onderaan.
                    const _ord = x => x.kwal === 'Q' ? 0 : x.kwal === 'q' ? 1 : 2;
                    const _uitvalCodes = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF'];
                    const _isUit = x => {
                        const s = String(x.sanctie || '').toUpperCase().split(/[,\s]+/);
                        return _uitvalCodes.some(c => s.includes(c));
                    };
                    // A-finale sortering volgt finale_ranking uit admin's
                    // Uitslag-module — ALLEEN voor sprint-afstanden:
                    //   'time'          → puur op tijd (correct bij 200m DTT)
                    //   'position_time' → op finishpositie, tijd tiebreak (default)
                    // Voor lange afstanden (puntenkoers/afvalkoers/inline) is
                    // tijd niet leidend en houdt admin's finishpositie al
                    // rekening met punten en rondes — die altijd volgen,
                    // ongeacht finale_ranking.
                    const _finaleFin = ['finale_a'].includes(r.ronde_type)
                                       && (isLangeAfstand || d.finale_ranking !== 'time');
                    rijders.sort((a, b) => {
                        // Uitvallers altijd naar het einde
                        const ua = _isUit(a), ub = _isUit(b);
                        if (ua !== ub) return ua ? 1 : -1;
                        // Q/q-groep bepaalt de blok-volgorde
                        const oa = _ord(a), ob = _ord(b);
                        if (oa !== ob) return oa - ob;
                        // Binnen de "rest"-groep (oa === 2) bij finale_a
                        // (behalve tijdkoppeling): finishpositie leidend,
                        // tijd tiebreaker.
                        if (oa === 2 && _finaleFin) {
                            const fa = a.finishpositie ?? 999;
                            const fb = b.finishpositie ?? 999;
                            if (fa !== fb) return fa - fb;
                        }
                        // In alle andere gevallen: puur op tijd.
                        const ta = a.tijd_ms ?? 999999999;
                        const tb = b.tijd_ms ?? 999999999;
                        return ta - tb;
                    });
                }

                html += `<table class="rondeu-tabel">
                    <thead><tr>
                        ${r.ronde_type === 'runner_up' ? `<th class="c">${esc(t('rondeu_col_pos'))}</th>` : ''}
                        <th class="c">${esc(t('rondeu_col_snr'))}</th>
                        <th>${esc(t('rondeu_col_naam'))}</th>
                        <th class="c">${esc(t('rondeu_col_kwal'))}</th>
                        ${heeftRondes   ? `<th class="c">${esc(t('rondeu_col_rondes'))}</th>` : ''}
                        ${heeftPkPunten ? `<th class="c">${esc(t('rondeu_col_pkpt'))}</th>`   : ''}
                        <th class="c">${esc(t('rondeu_col_tijd'))}</th>
                        <th class="c">${esc(t('rondeu_col_sanctie'))}</th>
                        ${heeftFin      ? `<th class="c">${esc(t('rondeu_col_fin'))}</th>`    : ''}
                    </tr></thead>
                    <tbody>`;
                let vorigHeatNr = null;
                for (const rr of rijders) {
                    // Sub-header per heat bij finale_b / runner_up: bij wissel
                    // van heat_nr → tussenrij met "B1"/"B2" of "RU1"/"RU2".
                    if ((r.ronde_type === 'finale_b' || r.ronde_type === 'runner_up')
                        && rr.heat_nr !== vorigHeatNr) {
                        const prefix = r.ronde_type === 'finale_b' ? 'B' : 'RU';
                        const nr = rr.heat_nr ?? '?';
                        html += `<tr class="rondeu-heat-sub"><td colspan="99">${esc(prefix + nr)}</td></tr>`;
                        vorigHeatNr = rr.heat_nr;
                    }
                    const isIk = lic && rr.person_license === lic;
                    // Q/q badge + optioneel doorstroom-doelfinale (A / B1 / B2 / …).
                    // Bij full-final krijgt iedereen Q/q, dan geeft de suffix pas
                    // context: "Q→A" vs "Q→B1" is duidelijker dan alleen "Q".
                    const dsSuffix = rr.doorstroom_label
                        ? `<span style="color:#666;font-weight:600">→${esc(rr.doorstroom_label)}</span>` : '';
                    const kwalHtml = rr.kwal === 'Q'
                        ? `<b style="color:#198754">Q</b>${dsSuffix}`
                        : rr.kwal === 'q' ? `<b style="color:#0d6efd">q</b>${dsSuffix}`
                        : dsSuffix;
                    const tijdStr = rr.tijd_ms != null ? msTijd(rr.tijd_ms) : '—';
                    const sanctieStr = rr.sanctie || '';
                    // Non-finisher (DNS/DNF/DQ-*): geen Fin-positie tonen
                    // ook al staat 'ie in DB. Zelfde regel als in de rit-modal.
                    const sanctieCodes = String(sanctieStr).toUpperCase().split(/[,\s]+/);
                    const isNonFin = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF'].some(c => sanctieCodes.includes(c));
                    const finVal = (isNonFin || rr.finishpositie == null) ? '' : rr.finishpositie;
                    html += `<tr${isIk ? ' class="rij-ik"' : ''}>
                        ${r.ronde_type === 'runner_up' ? `<td class="c uitsl-tc-b7-dark">${rr.ru_positie ?? '—'}</td>` : ''}
                        <td class="c uitsl-tc-b6">${esc(rr.snr ?? '')}</td>
                        <td>${esc(rr.full_name)}</td>
                        <td class="c">${kwalHtml}</td>
                        ${heeftRondes   ? `<td class="c">${rr.rondes ?? '—'}</td>` : ''}
                        ${heeftPkPunten ? `<td class="c uitsl-tc-b6">${pkPuntCel(rr, pkMaxR) || '—'}</td>` : ''}
                        <td class="c mono">${esc(tijdStr)}</td>
                        <td class="c" style="color:#c00;font-weight:600">${esc(sanctieStr)}</td>
                        ${heeftFin      ? `<td class="c uitsl-tc-b7-dark">${esc(finVal)}</td>` : ''}
                    </tr>`;
                }
                html += `</tbody></table>`;
            }
            html += `</div>`;
        }

        // Eind-uitslag NIET tonen hier — die staat onder de Uitslagen-tab.
        // Resultaten = alleen de rondes waar deze rijder zelf in zat.

        html += `</div>`;
    }

    container.innerHTML = html;
}

// ── Uitslagen-tab logica ──────────────────────────────────────────────────
// Cat-dropdown werkt op DC-basis: één optie per DC. Solo-DC label = cat-code
// ("DP4"); combi-DC label = cats gesorteerd op leeftijd ("HJA + HSA").
// Gecombineerd rijden = één uitslag; splitsen op individuele cat zou de
// wedstrijdrealiteit verkeerd voorstellen.
let _catCache = null; // cache _rondes_cats-payload per sessie

async function initUitslagenTab(kaart) {
    const catSel  = kaart.querySelector('.uitsl-cat-sel');
    const distSel = kaart.querySelector('.uitsl-dist-sel');
    const wrap    = kaart.querySelector('.uitsl-tabel-wrap');
    if (!catSel || catSel.dataset.loaded) return;
    catSel.dataset.loaded = '1';

    const compId = selComp.value;
    if (!compId) return;

    // ── Serie-klassementen waar deze wedstrijd aan meedoet ──
    initSerieKlassementen(kaart, compId);

    try {
        if (!_catCache || _catCache.compId !== compId) {
            const res = await safeFetch(`?action=rondes_cats&competition_id=${encodeURIComponent(compId)}&_t=${Date.now()}`);
            _catCache = { compId, data: await res.json() };
        }
        const cats = _catCache.data;
        if (cats.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(cats.error)}</div>`; return; }

        catSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_cat'))}</option>`;
        for (const c of cats) {
            const o = document.createElement('option');
            o.value = c.sig;
            o.textContent = c.label;
            o.dataset.json = JSON.stringify(c);
            catSel.appendChild(o);
        }
    } catch (e) {
        wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
    }

    // Cat-change → vul afstand-dropdown. Value-format:
    //   afstand:    "afstand|<dc_id>|<distance_id>"
    //   klassement: "klassement|<dc_id>"
    // dc_id kan per afstand verschillen als meerdere DC's dezelfde cats delen.
    catSel.addEventListener('change', () => {
        wrap.innerHTML = '';
        const opt = catSel.selectedOptions[0];
        if (!opt?.value) { distSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_afstand'))}</option>`; distSel.disabled = true; return; }
        const cat = JSON.parse(opt.dataset.json);
        distSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_afstand'))}</option>`;
        for (const a of cat.afstanden) {
            const o = document.createElement('option');
            o.value = `afstand|${a.dc_id}|${a.distance_id}`;
            o.textContent = a.distance_naam;
            distSel.appendChild(o);
        }
        const klas = cat.klassementen || [];
        for (const k of klas) {
            const o = document.createElement('option');
            o.value = `klassement|${k.dc_id}`;
            o.textContent = klas.length > 1
                ? `${t('uitsl_klassement_opt')} (${k.dc_naam})`
                : t('uitsl_klassement_opt');
            distSel.appendChild(o);
        }
        distSel.disabled = false;
        if (cat.afstanden.length === 1 && !klas.length) {
            distSel.value = `afstand|${cat.afstanden[0].dc_id}|${cat.afstanden[0].distance_id}`;
            distSel.dispatchEvent(new Event('change'));
        }
    });

    // Afstand/klassement-change → fetch + render
    distSel.addEventListener('change', async () => {
        const val = distSel.value;
        if (!val) { wrap.innerHTML = ''; return; }
        const parts = val.split('|');
        const type   = parts[0];                        // 'afstand' | 'klassement'
        const dcId   = parts[1] || '';
        const distId = type === 'afstand' ? (parts[2] || '') : '';
        if (!dcId) { wrap.innerHTML = ''; return; }

        wrap.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_laden')}</div>`;

        try {
            const url = type === 'klassement'
                ? `?action=uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&type=klassement`
                : `?action=uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&type=afstand&distance_id=${encodeURIComponent(distId)}`;
            const res = await safeFetch(url);
            const data = await res.json();
            if (data.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }

            wrap.innerHTML = (type === 'klassement') ? renderKlassementTabel(data) : renderAfstandTabel(data);
        } catch (e) {
            wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
        }
    });

    // Restore vorige keuze (na auto-refresh of na kind-wissel) — voorkomt dat
    // de gebruiker zijn categorie + afstand opnieuw moet kiezen telkens als
    // stilleRefresh() de DOM herbouwt. _bewaarKindUistate() heeft de keuze
    // net daarvoor opgeslagen op het kind-object.
    const saved = _kinderen?.[_activeKindIdx]?._uistate;
    const cats  = _catCache?.data;
    if (saved?.catVal && catSel.querySelector(`option[value="${CSS.escape(saved.catVal)}"]`)) {
        catSel.value = saved.catVal;
        catSel.dispatchEvent(new Event('change'));
        // Daarna ook afstand/klassement herstellen als die nog bestaat
        if (saved.distVal) {
            const distOpt = distSel.querySelector(`option[value="${CSS.escape(saved.distVal)}"]`);
            if (distOpt) {
                distSel.value = saved.distVal;
                distSel.dispatchEvent(new Event('change'));
            }
        }
    } else if (cats?.length === 1) {
        // Auto-selecteer als er maar 1 categorie is (ná binden listeners)
        catSel.value = cats[0].dc_id;
        catSel.dispatchEvent(new Event('change'));
    }
}

// ── Serie-klassementen voor een wedstrijd ──────────────────────────────────
async function initSerieKlassementen(kaart, compId) {
    const box      = kaart.querySelector('[data-serie-lijst]');
    const selector = kaart.querySelector('[data-serie-selector]');
    const wrap     = kaart.querySelector('.serie-klas-tabel-wrap');
    if (!box) return;

    try {
        const res = await safeFetch(`?action=series_voor_comp&competition_id=${encodeURIComponent(compId)}`);
        const series = await res.json();
        if (!Array.isArray(series) || !series.length) { box.hidden = true; return; }

        box.hidden = false;
        // Eén select met alle series + categorieën combineert netjes
        selector.innerHTML = `
            <select class="serie-sel">
                <option value="">${esc(t('serie_opt_kies'))}</option>
                ${series.map(s => `
                    <option value="${esc(s.klassement_id)}">
                        ${esc(s.naam)}${s.seizoen ? t('serie_seizoen_sep') + esc(s.seizoen) : ''}
                        (${esc(t('serie_aantal_rijders', {n: s.totaal_rijders}))})
                    </option>`).join('')}
            </select>
            <select class="serie-cat-sel" disabled><option value="">${esc(t('uitsl_opt_kies_cat'))}</option></select>`;

        const serieSel = selector.querySelector('.serie-sel');
        const catSel   = selector.querySelector('.serie-cat-sel');
        let huidig = null; // cached klassement-response

        serieSel.addEventListener('change', async () => {
            wrap.innerHTML = '';
            catSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_cat'))}</option>`;
            catSel.disabled = true;
            if (!serieSel.value) return;
            wrap.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_laden')}</div>`;
            try {
                const r = await safeFetch(`?action=serie_klassement&klassement_id=${encodeURIComponent(serieSel.value)}`);
                const data = await r.json();
                if (data.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }
                huidig = data;
                const cats = (data.categorieen ?? []).filter(Boolean);
                if (!cats.length) {
                    wrap.innerHTML = renderSerieKlassementTabel(data, null);
                    return;
                }
                catSel.innerHTML = `<option value="">${esc(t('serie_opt_alle_cats'))}</option>` +
                    cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
                catSel.disabled = false;
                // Auto-eerste categorie: als er maar 1 is, selecteer die
                if (cats.length === 1) {
                    catSel.value = cats[0];
                    catSel.dispatchEvent(new Event('change'));
                } else {
                    wrap.innerHTML = `<div class="melding">${esc(t('msg_kies_categorie_klassement'))}</div>`;
                }
            } catch (e) {
                wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
            }
        });

        catSel.addEventListener('change', () => {
            if (!huidig) return;
            wrap.innerHTML = renderSerieKlassementTabel(huidig, catSel.value || null);
        });

        // Restore serie-klassement keuze na auto-refresh / kind-wissel
        // (dezelfde reden als bij Uitslagen — anders verspringt elke 60s).
        const saved = _kinderen?.[_activeKindIdx]?._uistate;
        if (saved?.serieVal && serieSel.querySelector(`option[value="${CSS.escape(saved.serieVal)}"]`)) {
            serieSel.value = saved.serieVal;
            serieSel.dispatchEvent(new Event('change'));
            // serieSel.change is async (fetch + cat-sel populate); restore
            // de cat-keuze pas als cat-sel bevolkt is. We polleren kort —
            // veel simpeler dan de promise-chain doorvlechten.
            if (saved.serieCatVal) {
                const t0 = Date.now();
                const probeer = () => {
                    if (catSel.querySelector(`option[value="${CSS.escape(saved.serieCatVal)}"]`)) {
                        catSel.value = saved.serieCatVal;
                        catSel.dispatchEvent(new Event('change'));
                    } else if (Date.now() - t0 < 4000) {
                        setTimeout(probeer, 80);
                    }
                };
                setTimeout(probeer, 80);
            }
        }
    } catch (e) {
        box.hidden = true;
    }
}

// Render de serie-klassement-tabel (vergelijkbaar met ranking-detail in Beheer,
// maar met highlight voor de eigen rijder uit inpSnr).
function renderSerieKlassementTabel(k, cat) {
    const alle  = k.posities ?? [];
    const rijen = cat ? alle.filter(p => p.categorie === cat) : alle;
    if (!rijen.length) return `<div class="melding">${esc(t('msg_geen_posities'))}</div>`;
    const wMeta = Array.isArray(k.wedstrijden_meta) ? k.wedstrijden_meta : [];
    const toonW = wMeta.length > 0 && rijen.some(p => p.punten_detail && Object.keys(p.punten_detail).length);

    const fmtP = n => {
        if (n == null) return '–';
        const v = +n;
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    };

    // Startnummer van de actief-getoonde rijder. Na btnZoek wordt inpSnr
    // leeggemaakt, dus we lezen uit _kinderen (werkt voor 1 kind én voor de
    // multi-kind-tabs). Fallback op inpSnr voor de eerste render.
    const eigenSnr = String(
        (_kinderen?.[_activeKindIdx]?.snr) ?? inpSnr.value.trim() ?? ''
    ).trim();

    let hdr = `<tr><th class="col-rang">${t('col_rang')}</th><th class="col-snr">${t('col_snr')}</th><th>${t('col_naam')}</th>`;
    if (!cat) hdr += `<th class="col-cat">${t('col_cat')}</th>`;
    if (toonW) {
        hdr += wMeta.map((w, i) =>
            `<th class="col-w" title="${esc(w.naam)}${w.datum ? ' · ' + String(w.datum).substring(0,10) : ''}${w.is_finale ? ' · FINALE' : ''}">
                ${w.is_finale ? 'F' : '#' + (i + 1)}
            </th>`).join('');
        hdr += `<th class="col-tot">${t('col_tot')}</th>`;
    }
    hdr += '</tr>';

    // Kolomtelling voor de scheidingsrij tussen geklasseerd en niet-opgenomen.
    const colspan = 3 + (cat ? 0 : 1) + (toonW ? wMeta.length + 1 : 0);
    let scheidingGedaan = false;
    const rows = rijen.map(p => {
        // positie 0 = 'niet opgenomen in klassement' (onderblok): getoond op
        // puntenvolgorde, maar zonder rangnummer.
        const buiten = !(+p.positie > 0);
        let voor = '';
        if (buiten && !scheidingGedaan) {
            scheidingGedaan = true;
            voor = `<tr class="serie-klas-scheiding"><td colspan="${colspan}">${esc(t('klas_niet_opgenomen'))}</td></tr>`;
        }
        const isIk = eigenSnr && String(p.start_number) === String(eigenSnr);
        const detail = p.punten_detail ?? {};
        const wedstrijdCellen = toonW
            ? wMeta.map(w => {
                const v = detail[w.comp_id];
                return `<td class="col-w">${v != null ? fmtP(v) : '<span class="col-nng">–</span>'}</td>`;
              }).join('')
            : '';
        const totaalCel = toonW ? `<td class="col-tot">${fmtP(p.punten_totaal)}</td>` : '';
        return `${voor}<tr class="${isIk ? 'rij-ik' : ''}${buiten ? ' rij-buiten' : ''}">
            <td class="col-rang">${buiten ? '' : p.positie}</td>
            <td class="col-snr">${esc(p.start_number ?? '–')}</td>
            <td class="col-naam">${esc(p.naam)}</td>
            ${!cat ? `<td class="col-cat">${esc(p.categorie ?? '')}</td>` : ''}
            ${wedstrijdCellen}
            ${totaalCel}
        </tr>`;
    }).join('');

    return `<table class="uitsl-tabel serie-klas-tabel"><thead>${hdr}</thead><tbody>${rows}</tbody></table>`;
}

// JS-equivalent van backend catSortKey — jong → oud, dames → heren.
// Gebruikt om extra cat-kolommen consistent te sorteren met de dropdown.
function _catSortKey(cat) {
    const c = (cat || '').toUpperCase().trim();
    const mMasters = c.match(/^([HD]?)M(\d{2,3})$/);
    if (mMasters) {
        const g = mMasters[1] === 'D' ? 0 : 1;
        const lft = parseInt(mMasters[2], 10);
        if (lft >= 40) return (10 + Math.floor((lft - 40) / 5)) * 10 + g;
    }
    const g = c[0] === 'D' ? 0 : c[0] === 'H' ? 1 : 9;
    const sub = c.slice(1);
    const ageMap = { P4:0, P3:1, P2:2, P1:3, KA:4, JB:5, JA:6, SJ:7, SA:8, SB:9 };
    const a = ageMap[sub] ?? 99;
    return a * 10 + g;
}

// Verzamel unieke cats uit de rijders en bereken per-cat rang. Rijders met
// rang===null (uitvallers) tellen niet mee voor de cat-rang. Toont bij
// gecombineerde DC's welke plek een rijder BINNEN zijn eigen cat pakte.
function _catRanksBerekenen(rijders) {
    const cats = [];
    const catRank = new Map(); // license/idx → { cat, catRang }
    const teller = {};         // cat → running count
    rijders.forEach((r, idx) => {
        const c = r.categorie || '';
        if (!c) { catRank.set(idx, null); return; }
        if (!cats.includes(c)) cats.push(c);
        if (r.rang == null) { catRank.set(idx, null); return; }
        teller[c] = (teller[c] || 0) + 1;
        catRank.set(idx, teller[c]);
    });
    cats.sort((a, b) => _catSortKey(a) - _catSortKey(b));
    return { cats, catRank };
}

function renderAfstandTabel(data) {
    if (!data.rijders?.length) return `<div class="melding">${esc(t('msg_geen_uitslagen'))}</div>`;
    const heeftRnd = data.heeft_rondes;
    const heeftPK  = data.heeft_pk_punten;
    const pkMax    = heeftPK ? pkMaxRnd(data.rijders) : 0;
    // Per-cat kolommen bij gecombineerde DC (meer dan 1 cat in de uitslag).
    const { cats, catRank } = _catRanksBerekenen(data.rijders);
    const toonCatKol = cats.length > 1;

    let hdr = `<th class="col-rang">${t('col_rang')}</th>`;
    if (toonCatKol) for (const c of cats) hdr += `<th class="col-cat-rank" title="${esc(c)}">${esc(c)}</th>`;
    hdr += `<th class="col-snr">${t('col_snr')}</th><th class="col-naam">${t('col_naam')}</th>`;
    if (heeftRnd) hdr += `<th class="col-rnd">${t('col_rnd')}</th>`;
    if (heeftPK)  hdr += `<th class="col-pk">${t('col_pnt')}</th>`;
    hdr += `<th class="col-tijd">${t('col_tijd')}</th>`;

    let rows = '';
    data.rijders.forEach((r, idx) => {
        const sanctie = sl(r.sanctie);
        rows += `<tr>
            <td class="col-rang">${r.rang ?? '—'}</td>`;
        if (toonCatKol) {
            const cr = catRank.get(idx);
            for (const c of cats) {
                rows += `<td class="col-cat-rank">${(c === r.categorie && cr != null) ? cr : ''}</td>`;
            }
        }
        rows += `<td class="col-snr">${esc(r.snr)}</td>
            <td class="col-naam">${esc(r.full_name)}${sanctie ? ` <span class="col-sanctie">${esc(sanctie)}</span>` : ''}</td>`;
        if (heeftRnd) rows += `<td class="col-rnd">${r.rondes ?? ''}</td>`;
        if (heeftPK)  rows += `<td class="col-pk">${pkPuntCel(r, pkMax)}</td>`;
        rows += `<td class="col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : ''}</td>`;
        rows += '</tr>';
    });
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderKlassementTabel(data) {
    if (!data.rijders?.length) return `<div class="melding">${esc(t('msg_geen_klassement'))}</div>`;
    const afstanden = data.afstanden ?? [];
    const { cats, catRank } = _catRanksBerekenen(data.rijders);
    const toonCatKol = cats.length > 1;

    let hdr = `<th class="col-rang">${t('col_rang')}</th>`;
    if (toonCatKol) for (const c of cats) hdr += `<th class="col-cat-rank" title="${esc(c)}">${esc(c)}</th>`;
    hdr += `<th class="col-snr">${t('col_snr')}</th><th class="col-naam">${t('col_naam')}</th>`;
    for (const a of afstanden) {
        const kort = a.length > 6 ? a.substring(0, 5) + '.' : a;
        hdr += `<th class="col-punten" title="${esc(a)}">${esc(kort)}</th>`;
    }
    hdr += `<th class="col-totaal">${t('col_tot')}</th>`;

    let rows = '';
    data.rijders.forEach((r, idx) => {
        const detail = r.punten_detail ?? {};
        rows += `<tr>
            <td class="col-rang">${r.rang ?? '—'}</td>`;
        if (toonCatKol) {
            const cr = catRank.get(idx);
            for (const c of cats) {
                rows += `<td class="col-cat-rank">${(c === r.categorie && cr != null) ? cr : ''}</td>`;
            }
        }
        rows += `<td class="col-snr">${esc(r.snr)}</td>
            <td class="col-naam">${esc(r.full_name)}</td>`;
        for (const a of afstanden) {
            const p = detail[a];
            rows += `<td class="col-punten">${p != null ? parseFloat(p) : '—'}</td>`;
        }
        rows += `<td class="col-totaal">${r.punten_totaal != null ? parseFloat(r.punten_totaal) : '—'}</td>`;
        rows += '</tr>';
    });
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

