// ============================================================
//  InlineComp Public — public-modals.js
//
//  Bevat: footer/logo/sponsor-marquee + easter egg + header-logos + info-modal + help-modal.
//  Geextraheerd uit public/app.js op 2026-09-30 (fase 3 van refactor-plan).
//  Klassieke script-tag: gedeelde global scope met de andere public-*.js modules.
// ============================================================

// ── Help overlay ──────────────────────────────────────────────────────────
// ── Footer: org logo + sponsor-ticker ─────────────────────────────────────

// 🥚 Easter egg: 3× snel klikken op org-logo opent blobart.devriesen.com
// EN toont een succesmelding met "je bent de N-e die 'm gevonden heeft".
// Dedup: per-browser localStorage-token — dezelfde browser telt maar 1x,
// ook al klikt-ie 100x. Server geeft `positie` terug (volgnummer van
// deze browser), niet het totaal aantal hits.
// Counter + timer op module-niveau zodat ze tussen renderings behouden blijven.
let _eggCount = 0;
let _eggTimer = null;
function _eggGetToken() {
    let t = localStorage.getItem('egg-token');
    if (!t) {
        // crypto.randomUUID vereist HTTPS/secure context; fallback voor lokaal.
        t = (crypto.randomUUID && crypto.randomUUID()) ||
            ('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                const r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            }));
        localStorage.setItem('egg-token', t);
    }
    return t;
}
function _eggHandler() {
    _eggCount++;
    clearTimeout(_eggTimer);
    if (_eggCount >= 3) {
        _eggCount = 0;
        // Token meesturen zodat server dedupt op browser-niveau. Faalt de
        // call, tonen we nog steeds een generieke felicitatie.
        const body = new URLSearchParams({ token: _eggGetToken() });
        fetch('../api/easter_egg.php', { method: 'POST', body })
            .then(r => r.ok ? r.json() : null)
            .then(j => _eggToonMelding(j?.positie ?? null))
            .catch(() => _eggToonMelding(null));
        window.open('https://blobart.devriesen.com', '_blank', 'noopener');
    } else {
        _eggTimer = setTimeout(() => { _eggCount = 0; }, 2000);
    }
}
// TODO 100ste: bij positie === 100 modaal met e-mail-input tonen zodat de
// vinder een prijsje kan krijgen. Nu alleen gewone felicitatie.
function _eggToonMelding(positie) {
    const nr = Number.isInteger(positie) && positie > 0 ? positie : null;
    const regel = nr
        ? `Je bent de <strong>${nr}<sup>e</sup></strong> die 'm gevonden heeft! 🎉`
        : `Leuk dat je 'm gevonden hebt! 🎉`;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9600;'
        + 'display:flex;align-items:center;justify-content:center;padding:1rem;';
    overlay.innerHTML = `
        <div style="background:#fff8e1;border:3px solid #f9a825;border-radius:10px;
                    max-width:360px;width:100%;padding:1.4rem 1.5rem;text-align:center;
                    box-shadow:0 10px 40px rgba(0,0,0,.4);animation:meldingPop .3s ease-out;">
            <div style="font-size:2.4rem;margin-bottom:.4rem;">🥚</div>
            <h2 style="margin:0 0 .5rem;color:#f57f17;font-size:1.15rem;">
                Easter egg gevonden!
            </h2>
            <p style="margin:0 0 1.1rem;color:#333;line-height:1.5;font-size:.95rem;">
                ${regel}
            </p>
            <button class="egg-ok" style="background:#f9a825;color:#fff;border:none;
                    padding:.55rem 1.4rem;border-radius:6px;font-size:1rem;
                    font-weight:600;cursor:pointer;width:100%;">
                Leuk!
            </button>
        </div>`;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    overlay.querySelector('.egg-ok').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
}

// Logo dat — bij height 50px — breder zou worden dan deze ratio, past niet
// netjes in de vaste footer-positie en duwt de sponsor-marquee weg. We
// verplaatsen 'm dan naar de marquee zodat 'ie wel op volle hoogte leesbaar
// blijft (lichtkrant rolt 'm langs).
const _FOOTER_LOGO_MAX_RATIO = 150 / 50;   // = 3.0 : 1
                                           // (incl. Warmondse 250×71 ≈ 3.52
                                           // → naar marquee i.p.v. footer)

// Async helper — preload image, lever ratio terug. Bij fout: 1 (= niet te breed).
function _logoRatio(src) {
    return new Promise(resolve => {
        const img = new Image();
        img.onload  = () => resolve(img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1);
        img.onerror = () => resolve(1);
        img.src = src;
    });
}

async function updateHeaderLogos(opt) {
    const footer   = document.getElementById('org-footer');
    const logoEl   = document.getElementById('footer-org-logo');
    const naamEl   = document.getElementById('footer-org-naam');
    const sponsEl  = document.getElementById('footer-sponsors');
    const baanEl   = document.getElementById('footer-baan-logo');

    if (!opt?.value) {
        footer.style.display = 'none';
        return;
    }

    const orgLogo   = opt.dataset.orgLogo;
    const orgNaam   = opt.dataset.orgNaam ?? '';
    const baanLogo  = opt.dataset.baanLogo ?? '';
    const baanVer   = opt.dataset.baanVereniging ?? '';
    const sponsors  = JSON.parse(opt.dataset.sponsors || '[]');

    // Niets te tonen? Footer verbergen (incl. check op baan-logo).
    if (!orgLogo && !sponsors.length && !baanLogo && !baanVer) {
        footer.style.display = 'none';
        return;
    }

    // Cache-buster zodat een vers geüpload logo niet uit de browser-cache blijft.
    // Gebruikt het huidige uur als bust-waarde: stabiel genoeg voor normale navigatie
    // maar een upload is uiterlijk binnen het uur zichtbaar.
    const cb = `?v=${Math.floor(Date.now() / 3600000)}`;

    // Te brede logo's (bv. landscape-vereniging-logo) passen niet in de
    // vaste footer-positie. We preloaden ze, meten de aspect-ratio en
    // verhuizen ze naar de marquee als ze te breed zijn — dan hebben ze
    // wel ruimte om op volle hoogte langs te rollen.
    const orgRatio  = orgLogo  ? await _logoRatio(`../${esc(orgLogo)}${cb}`)  : 0;
    const baanRatio = baanLogo ? await _logoRatio(`../${esc(baanLogo)}${cb}`) : 0;
    const orgInFooter  = orgLogo  && orgRatio  <= _FOOTER_LOGO_MAX_RATIO;
    const baanInFooter = baanLogo && baanRatio <= _FOOTER_LOGO_MAX_RATIO;

    // Organisatie-logo links in footer. Bij te-breed logo: vaste positie
    // leeg laten — logo gaat naar marquee.
    logoEl.innerHTML = orgInFooter ? `<img class="org-footer-logo" src="../${esc(orgLogo)}${cb}" alt="">` : '';
    // Naam-fallback ALLEEN als er helemaal geen logo is (niet als 't te breed
    // is — dan rolt het logo zelf langs in de marquee, naam-tekst overbodig).
    naamEl.textContent = !orgLogo ? orgNaam : '';
    // Easter egg: 3× klikken op het org-logo binnen 2 sec opent blobart.
    // Geheim — cursor blijft default zodat het niet verraadt klikbaar te zijn.
    const _eggImg = logoEl.querySelector('img');
    if (_eggImg) _eggImg.addEventListener('click', _eggHandler);

    // Gastheer-vereniging rechts in footer. Bij te-breed logo: vaste
    // positie leeg, logo naar marquee. Naam-fallback alleen bij ECHT
    // geen logo (geen visuele verdubbeling met de marquee-versie).
    if (baanInFooter) {
        baanEl.innerHTML = `<img class="org-footer-logo" src="../${esc(baanLogo)}${cb}" alt="">`;
    } else if (baanVer && !baanLogo) {
        baanEl.innerHTML = `<span class="org-footer-naam">${esc(baanVer)}</span>`;
    } else {
        baanEl.innerHTML = '';
    }
    // Lege wrapper inklappen zodat de marquee de hele rechter-ruimte vult
    // (anders blijft 't <div> leeg layout-ruimte innemen ondanks empty html).
    baanEl.style.display = baanEl.innerHTML ? '' : 'none';

    // Sponsors (lichtkrant-ticker) + eventueel te-brede org/baan-logo's.
    // Altijd marquee, ook bij 1 item — anders 'hangt' de enige logo
    // statisch. Min-duur 8s zodat 1 logo niet onhandig snel langs schiet.
    let imgs = '';
    const _liggendImg = (src, naam) =>
        `<img src="../${esc(src)}${cb}" alt="${esc(naam)}" title="${esc(naam)}" style="height:50px;width:auto;object-fit:contain">`;
    if (orgLogo  && !orgInFooter)  imgs += _liggendImg(orgLogo,  orgNaam);
    if (baanLogo && !baanInFooter) imgs += _liggendImg(baanLogo, baanVer || '');
    for (const s of sponsors) {
        const img = _liggendImg(s.logo, s.naam);
        imgs += s.url ? `<a href="${esc(s.url)}" target="_blank" rel="noopener">${img}</a>` : img;
    }
    if (imgs) {
        const aantal = sponsors.length
                     + (orgLogo  && !orgInFooter  ? 1 : 0)
                     + (baanLogo && !baanInFooter ? 1 : 0);
        const duur = Math.max(8, aantal * 3);
        sponsEl.innerHTML = `<div class="sponsor-marquee"><div class="sponsor-marquee-inner" style="animation-duration:${duur}s">${imgs}${imgs}</div></div>`;
    } else {
        sponsEl.innerHTML = '';
    }

    footer.style.display = 'block';
}

function toonInfo() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>${esc(t('info_titel'))}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">
            <h3>${esc(t('info_h1'))}</h3>
            <p>${esc(t('info_p1'))}</p>
            <p>${t('info_p2_html')}</p>

            <h3>${esc(t('info_h2'))}</h3>
            <p>${esc(t('info_p3'))}</p>

            <h3>${t('info_h3_html')}</h3>
            <p>${esc(t('info_p4'))}</p>
            <p class="info-cta-wrap">
                <a class="info-cta" style="--cta-bg:var(--oranje)" href="mailto:inlinecomp@devriesen.com">inlinecomp@devriesen.com</a>
            </p>

            <h3>${esc(t('info_h4'))}</h3>
            <p style="font-size:.85rem;color:#555">${t('info_p5_html')}</p>

            <h3>${t('info_h5_html')}</h3>
            <p>${esc(t('info_p6'))}</p>
            <p class="info-cta-wrap">
                <a class="info-cta" style="--cta-bg:var(--blauw,#1a3a5c)" href="../privacyverklaring.php">${esc(t('info_btn_privacy'))}</a>
            </p>

            <p style="font-size:.8rem;color:#999;text-align:center;margin-top:16px">${t('info_copyright', {jaar: new Date().getFullYear()})}</p>
            <p style="font-size:.75rem;color:#aaa;text-align:center;margin-top:4px">
                ${esc(t('info_versie'))} <strong>${esc(APP_VERSIE)}</strong>
            </p>
        </div>
    </div>`;
    document.body.appendChild(overlay);
}

function toonHelp() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>${esc(t('help_titel'))}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">

            <button type="button" class="btn-nieuw-jump"
                    onclick="this.closest('.help-body').querySelector('#wat-is-nieuw').scrollIntoView({behavior:'smooth',block:'start'})">
                ✨ ${esc(t('nieuw_jump'))}
            </button>

            <h3>${esc(t('help_h1'))}</h3>
            <div class="help-stap">
                <span class="help-stap-nr">1</span>
                <span>${t('help_stap1_html')}</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">2</span>
                <span>${t('help_stap2_html')}</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">3</span>
                <span>${t('help_stap3_html')}</span>
            </div>

            <!-- Mockup: zoekscherm — toont actuele filter-chips + 3-knoppen-rij -->
            <div class="mock">
                <div class="mock-hdr">InlineComp – Public</div>
                <div class="mock-body">
                    <div class="mock-stap">
                        <span class="mock-stap-nr">1</span>
                        ${esc(t('help_mock_kies_w'))}
                    </div>
                    <div class="mock-chip-rij">
                        <span class="mock-chip">${esc(t('filter_eerder'))}</span>
                        <span class="mock-chip mock-chip--active">${esc(t('filter_vandaag'))}</span>
                        <span class="mock-chip">${esc(t('filter_later'))}</span>
                    </div>
                    <div class="mock-select">${esc(t('help_mock_voorbeeld'))}</div>
                    <div class="mock-stap mock-stap--mt">
                        <span class="mock-stap-nr">2</span>
                        ${esc(t('help_mock_snr_lic'))}
                    </div>
                    <div class="mock-select">${esc(t('help_mock_snr'))}</div>
                    <div class="mock-zoek-btn">${esc(t('btn_zoeken'))}</div>
                </div>
            </div>

            <h3>${esc(t('help_h_tabs'))}</h3>
            <p>${t('help_p_tabs_html')}</p>

            <p>${t('help_p_prog_html')}</p>

            <!-- Mockup: programma (filter-strook + segment-control Inklappen / Uitklappen / Mijn) -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab active">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-filter-strook">
                    <div class="mock-filter-strook-rij">
                        <span>🏁</span><span class="flex-1">${esc(t('prog_filter_alle_afstanden'))}</span><span class="mock-chev">▼</span>
                    </div>
                </div>
                <div class="mock-seg-rij">
                    <span class="mock-seg mock-seg--active">▶ ${esc(t('prog_klap_alles_uit'))}</span>
                    <span class="mock-seg">▼ ${esc(t('prog_klap_alles_in'))}</span>
                    <span class="mock-seg">👤 ${esc(t('prog_klap_mijn'))}</span>
                </div>
                <div class="mock-body mock-body--p4">
                    <div class="mock-row"><span class="mock-nr-dim">1</span> <span class="mock-naam">500m ${esc(t('ronde_serie'))} Heat 1</span> <span class="mock-badge-serie">${esc(t('ronde_serie'))}</span></div>
                    <div class="mock-row mock-hl"><span class="mock-nr-dim">2</span> <span class="mock-naam">500m ${esc(t('ronde_serie'))} Heat 2</span> <span class="mock-badge-serie">${esc(t('ronde_serie'))}</span></div>
                    <div class="mock-row"><span class="mock-nr-dim">3</span> <span class="mock-naam">500m A-${esc(t('ronde_finale'))}</span> <span class="mock-badge-finale">${esc(t('ronde_finale'))}</span></div>
                </div>
            </div>

            <p>${t('help_p_heats_html')}</p>

            <!-- Mockup: heat -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-heat-hdr">
                    <span class="mock-heat-hdr-tag">${esc(t('ronde_finale'))}</span> 500m A-${esc(t('ronde_finale'))}
                </div>
                <div class="mock-body mock-body--p4">
                    <div class="mock-row mock-cols-hdr"><span class="mock-c18">${esc(t('col_pos'))}</span><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-tijd">${esc(t('col_tijd'))}</span><span class="mock-c20c">${esc(t('col_fin'))}</span></div>
                    <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-tijd">45.30</span><span class="mock-c20c mock-v">2</span></div>
                    <div class="mock-row mock-hl"><span class="mock-rang">2</span><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-tijd">45.12</span><span class="mock-c20c mock-v mock-cB">1</span></div>
                    <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-tijd">46.01</span><span class="mock-c20c mock-v">3</span></div>
                </div>
            </div>

            <p>${t('help_p_res_html')}</p>

            <!-- Mockup: rondes-tab (per-ronde uitslag + doorstroom Q→A / q→B) -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-body mock-body--p6">
                    <div class="mock-afstand">100 meter</div>

                    <div class="mock-badge-block mock-badge-block--serie">${esc(t('ronde_serie'))}</div>
                    <div class="mock-row mock-cols-hdr"><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-c36c">Kwal</span><span class="mock-tijd">${esc(t('col_tijd'))}</span></div>
                    <div class="mock-row"><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-c36c mock-v7 mock-cG">Q→A</span><span class="mock-tijd">10.42</span></div>
                    <div class="mock-row mock-hl"><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-c36c mock-v7 mock-cG">Q→A</span><span class="mock-tijd">10.58</span></div>
                    <div class="mock-row"><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-c36c mock-v7 mock-cBl">q→B</span><span class="mock-tijd">10.71</span></div>

                    <div class="mock-badge-block mock-badge-block--finale">${esc(t('ronde_finale'))} A</div>
                    <div class="mock-row mock-cols-hdr"><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-tijd">${esc(t('col_tijd'))}</span><span class="mock-c20c">${esc(t('col_fin'))}</span></div>
                    <div class="mock-row mock-hl"><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-tijd">10.35</span><span class="mock-c20c mock-v7 mock-cB">1</span></div>
                    <div class="mock-row"><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-tijd">10.41</span><span class="mock-c20c mock-v">2</span></div>
                </div>
            </div>

            <p>${t('help_p_uitsl_html')}</p>

            <!-- Mockup: uitslagen -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-body mock-body--p6">
                    <div class="mock-select">DJB/A + HJB/A</div>
                    <div class="mock-select">${esc(t('uitsl_klassement_opt').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-mt6">
                        <div class="mock-row mock-uitsl-hdr"><span class="mock-c18">${esc(t('col_rang'))}</span><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-c30c">Spr</span><span class="mock-c30c">L.A.</span><span class="mock-c30c mock-cO">${esc(t('col_tot'))}</span></div>
                        <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-c30c">4</span><span class="mock-c30c">1</span><span class="mock-c30c mock-v7 mock-cO">8</span></div>
                        <div class="mock-row"><span class="mock-rang">2</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-c30c">5</span><span class="mock-c30c">3</span><span class="mock-c30c mock-v7 mock-cO">11</span></div>
                        <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-c30c">5</span><span class="mock-c30c">6</span><span class="mock-c30c mock-v7 mock-cO">12</span></div>
                    </div>
                </div>
            </div>

            <h3>${esc(t('help_h_auto'))}</h3>
            <p>${t('help_p_auto_html')}</p>

            <h3>${esc(t('help_h_meld'))}</h3>
            <p>${t('help_p_meld_html')}</p>

            <h3>${esc(t('help_h_push'))}</h3>
            <p>${t('help_p_push_html')}</p>

            <!-- Mockup: push-meldingen-blok (🔔 + 3 losse type-toggles) -->
            <div class="mock">
                <div class="mock-hdr">🔔 ${esc(t('push_titel'))}</div>
                <div class="mock-body">
                    <div class="mock-push-align"><span class="mock-push-pill">${esc(t('push_aan'))}</span></div>
                    <div class="mock-row mock-row--flat"><span>☑</span><span class="mock-naam">🚩 ${esc(t('push_loting'))}</span></div>
                    <div class="mock-row mock-row--flat"><span>☑</span><span class="mock-naam">🏁 ${esc(t('push_uitslag'))}</span></div>
                    <div class="mock-row mock-row--flat"><span>☑</span><span class="mock-naam">📢 ${esc(t('push_bericht'))}</span></div>
                </div>
            </div>

            <h3>${esc(t('help_h_tip'))}</h3>
            <p>${esc(t('help_p_tip'))}</p>

            <!-- ── Wat is nieuw (changelog per versie) ── -->
            <h3 id="wat-is-nieuw" class="nieuw-titel">
                ✨ ${esc(t('nieuw_h'))}
            </h3>
            <p class="nieuw-intro">${esc(t('nieuw_intro'))}</p>

            ${renderChangelog(CHANGELOG)}

        </div>
    </div>`;
    document.body.appendChild(overlay);
}

