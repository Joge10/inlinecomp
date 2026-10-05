// ============================================================
//  InlineComp Public — public-rijder.js
//
//  Bevat: wedstrijd-filter + zoek/chooser + naam-zoek + multi-kind-state + setup-modal + toonRijderData/renderKinderen + status-modal.
//  Geextraheerd uit public/app.js op 2026-09-30 (fase 3 van refactor-plan).
//  Klassieke script-tag: gedeelde global scope met de andere public-*.js modules.
// ============================================================

function filterComps() {
    const nu = new Date();
    const gisteren = new Date(nu); gisteren.setDate(gisteren.getDate() - 1); gisteren.setHours(0,0,0,0);
    const morgen   = new Date(nu); morgen.setDate(morgen.getDate() + 1);   morgen.setHours(23,59,59,999);

    const toonOud      = chkOud.checked;
    const toonVandaag  = chkVandaag.checked;
    const toonToekomst = chkToekomst.checked;
    const vorigeWaarde = selComp.value;

    if (!toonOud && !toonVandaag && !toonToekomst) {
        selComp.innerHTML = `<option value="">${esc(t('opt_kies_filter'))}</option>`;
        return;
    }

    selComp.innerHTML = `<option value="">${esc(t('opt_kies_wedstrijd'))}</option>`;
    for (const c of alleComps) {
        const startDag = safeDatum(c.starts);
        const eindDag  = safeDatum(c.ends) ?? startDag;

        // Categoriseer: een wedstrijd is óf vandaag (overlapt met gisteren-morgen),
        // óf oud (afgelopen vóór gisteren), óf toekomstig (begint ná morgen).
        const isVandaag  = startDag && startDag <= morgen && eindDag >= gisteren;
        const isOud      = !isVandaag && eindDag   && eindDag   < gisteren;
        const isToekomst = !isVandaag && startDag && startDag > morgen;

        // Tonen als bijbehorend filter aan staat
        if (isVandaag  && !toonVandaag)  continue;
        if (isOud      && !toonOud)      continue;
        if (isToekomst && !toonToekomst) continue;

        const d = startDag ? startDag.toLocaleDateString(getLocale(),{day:'numeric',month:'long',year:'numeric'}) : '';
        // Verborgen wedstrijden: tonen als disabled met "(binnenkort)"
        // suffix — bezoeker ziet dat de wedstrijd er aankomt zonder
        // erop te kunnen klikken. Operator publiceert via Beheer.
        const verborgen = !Number(c.public_zichtbaar);
        const o = document.createElement('option');
        o.value = c.id;
        o.textContent = `${c.name} — ${d}${verborgen ? '  ' + t('opt_binnenkort') : ''}`;
        if (verborgen) o.disabled = true;
        o.dataset.datum = d; o.dataset.naam = c.name;
        o.dataset.orgLogo = c.org_logo ?? '';
        o.dataset.orgNaam = c.org_naam ?? '';
        o.dataset.baanLogo = c.baan_logo ?? '';
        o.dataset.baanVereniging = c.baan_vereniging ?? '';
        o.dataset.sponsors = JSON.stringify(c.sponsors ?? []);
        selComp.appendChild(o);
    }

    // Herstel selectie als die nog in de lijst zit en niet (inmiddels) disabled.
    const vorigeOpt = vorigeWaarde
        ? selComp.querySelector(`option[value="${vorigeWaarde}"]`)
        : null;
    if (vorigeOpt && !vorigeOpt.disabled) {
        selComp.value = vorigeWaarde;
    } else {
        // Auto-selecteer als er maar 1 selecteerbare wedstrijd is —
        // disabled ('binnenkort') tellen niet mee, anders zou de
        // gebruiker bij stappen verder pas een 'niet beschikbaar'
        // foutmelding krijgen.
        const opties = selComp.querySelectorAll('option[value]:not([value=""]):not([disabled])');
        if (opties.length === 1) { selComp.value = opties[0].value; selComp.dispatchEvent(new Event('change')); }
    }
}

chkOud.addEventListener('change', filterComps);
chkVandaag.addEventListener('change', filterComps);
chkToekomst.addEventListener('change', filterComps);

// Fragment-reader voor deel-links met een volg-token (plan URL/log-reductie
// 2026-10-02). Een link van de vorm /public/#volg=TOKEN (optioneel samen met
// ?comp=UUID) prefillt het rijder-zoek-veld met het token; browsers sturen
// het #-gedeelte nooit mee naar de server, dus komt het nooit in de access-
// logs voor. Hash wordt daarna gewist zodat 't niet in history/referer blijft
// hangen bij navigatie binnen de app.
let _volgTokenUitHash = null;
(function () {
    const hash = (window.location.hash || '').replace(/^#/, '');
    if (!hash) return;
    const params = new URLSearchParams(hash);
    const tok = params.get('volg');
    if (tok && /^[0-9a-f]{32}$/i.test(tok)) {
        _volgTokenUitHash = tok;
        try { history.replaceState(null, '', window.location.pathname + window.location.search); }
        catch { window.location.hash = ''; }
    }
})();

safeFetch('?action=competitions').then(r=>r.json()).then(comps => {
    alleComps = comps;

    // Directe-link-support: ?comp=<uuid> in de URL selecteert direct die
    // wedstrijd. Gebruikt door de QR-code op de promotie-poster per wedstrijd.
    // Als de wedstrijd buiten het "actieve" venster valt (oud of toekomstig)
    // vinken we automatisch het juiste filter aan zodat de optie zichtbaar is.
    const urlParams = new URLSearchParams(window.location.search);
    const wantedComp = urlParams.get('comp');
    if (wantedComp) {
        const comp = alleComps.find(c => c.id === wantedComp);
        if (comp) {
            const nu = new Date();
            const startDag = comp.starts ? new Date(comp.starts) : null;
            const eindDag  = comp.ends   ? new Date(comp.ends)   : startDag;
            if (eindDag && eindDag < nu)       chkOud.checked = true;
            if (startDag && startDag > nu)     chkToekomst.checked = true;
        }
    }

    filterComps();

    // Na filterComps: selecteer 'm als de optie nu beschikbaar is. Alleen
    // dispatchen als filterComps 'm niet al auto-geselecteerd heeft (bij één optie),
    // anders vuurt de change 2× → dubbele kinderen-load.
    if (wantedComp && selComp.value !== wantedComp
        && selComp.querySelector(`option[value="${wantedComp}"]`)) {
        selComp.value = wantedComp;
        selComp.dispatchEvent(new Event('change'));
    }
    // Bepaal view-state na init, in deze volgorde:
    //  1. Deeplink (?comp=UUID met geldige wedstrijd) wint — binnenkomst via
    //     QR-code of gedeelde link. URL wordt daarna opgeschoond zodat een
    //     refresh (pull-to-refresh of auto) niet opnieuw deze wedstrijd
    //     dwingt wanneer de user intussen naar hub/org is genavigeerd.
    //  2. Sessie-herstel: als de user tijdens deze browser-sessie al ergens
    //     was (hub-tab of wedstrijd), ga daar naartoe — zo verhoudt refresh
    //     zich natuurlijk tot waar je was.
    //  3. Default: hoofdview (hub) met Wedstrijden-tab, inclusief laden van
    //     de kaartlijst zodat de "Laden…"-placeholder niet blijft staan.
    if (wantedComp && selComp.value === wantedComp) {
        _setViewState('wedstrijd');
        try { history.replaceState(null, '', window.location.pathname); }
        catch {}
    } else {
        const saved = _herstelViewState();
        if (saved?.mode === 'wedstrijd' && saved.compId
            && selComp.querySelector(`option[value="${CSS.escape(saved.compId)}"]`)) {
            selComp.value = saved.compId;
            selComp.dispatchEvent(new Event('change'));
            _setViewState('wedstrijd');
        } else if (saved?.mode === 'hub' && saved.tab) {
            toonHubView(saved.tab);
        } else {
            toonHubView('wedstrijden');
        }
    }

    // Volg-token-deeplink (uit #volg=TOKEN): prefill het zoekveld. Als er
    // ook al een wedstrijd actief is (via ?comp=…), open meteen de rijder-
    // zoek-modal en trigger de zoek-flow — anders ziet de user het token
    // vanzelf in het veld zodra 'ie na wedstrijdkeuze de modal opent.
    if (_volgTokenUitHash) {
        if (inpSnr) inpSnr.value = _volgTokenUitHash;
        if (selComp.value) {
            setTimeout(() => {
                if (typeof openSetupModal === 'function') openSetupModal();
                setTimeout(() => btnZoek?.click(), 150);
            }, 100);
        }
        _volgTokenUitHash = null;
    }
}).catch(() => { selComp.innerHTML = `<option value="">${esc(t('opt_fout_laden'))}</option>`; });

selComp.addEventListener('change', async () => {
    const o = selComp.selectedOptions[0];
    if (o?.value) { divInfo.innerHTML = `<strong>${esc(o.dataset.naam)}</strong><div style="color:#555;margin-top:2px">${esc(o.dataset.datum)}</div>`; divInfo.hidden = false; }
    else divInfo.hidden = true;
    btnZoek.disabled = !(selComp.value && inpSnr.value.trim());
    divResult.innerHTML = '';
    updateHeaderLogos(o);
    updateSetupStrip();   // reflecteer nieuwe wedstrijd in de strip bovenaan

    // Multi-rijder-state resetten en vorige kinderen herladen uit globale
    // store (op license_key). Kinderen die niet in deze wedstrijd meedoen
    // worden stil overgeslagen — geen foutmelding.
    _kinderen = [];
    _activeKindIdx = 0;
    if (!selComp.value) return;
    const opgeslagen = _loadKidsUitStorage();
    if (!opgeslagen.length) {
        // Lege volglijst: als deze wedstrijd-change het directe gevolg was
        // van een keuze in de wedstrijd-modal, open meteen de rijder-zoek-
        // modal — anders ziet de user een lege wedstrijd zonder knop om
        // een rijder toe te voegen (de + verschijnt pas bij ≥1 kind).
        if (_wmodalLaatstKozenComp === selComp.value) {
            _wmodalLaatstKozenComp = null;
            setTimeout(() => openSetupModal(), 200);
        }
        return;
    }
    const mySeq = ++_kindLoadSeq;   // deze load claimt de nieuwste beurt
    divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_je_rijders_ophalen')}</div>`;
    let gedeeldeProg = null;
    try {
        const pr = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(selComp.value)}`);
        gedeeldeProg = await pr.json();
    } catch {}
    if (mySeq !== _kindLoadSeq) return;   // nieuwere change gestart → deze afbreken
    // In een lokale array verzamelen en pas op 't eind toewijzen: twee gelijktijdige
    // loads kunnen zo nooit in dezelfde _kinderen interleaven (→ geen dubbele rijders).
    const verzameld = [];
    const anoniemGeworden = [];   // gevolgde rijders die nu anoniem zijn (geen geldig token)
    for (const item of opgeslagen) {
        const k = await _fetchKind({ person_id: item.person_id, license_key: item.license_key, volg: item.volg }, selComp.value, gedeeldeProg);
        if (mySeq !== _kindLoadSeq) return;   // afgebroken door nieuwere load
        if (k && k.__anoniem) { anoniemGeworden.push(item); continue; }
        if (k) verzameld.push(k);
        // k == null → kind doet niet mee aan deze wedstrijd, we slaan 'm stil over.
    }
    _kinderen = verzameld;

    // Opslag bijwerken: (a) rijders die nu anoniem zijn pruimen (chip weg, volgen
    // verbroken — je had geen geldig volg-token), en (b) fase 3c-migratie: passief
    // person_id invullen bij oude items. Rijders die deze wedstrijd niet meedoen
    // blijven staan (die zijn niet gecontroleerd).
    const wegKeys = new Set(anoniemGeworden.map(it => it.person_id || it.volg || it.license_key));
    let basis = opgeslagen.filter(it => !wegKeys.has(it.person_id || it.volg || it.license_key));
    let veranderd = wegKeys.size > 0;
    if (basis.some(it => !it.person_id)) {
        const pidByLic = new Map();
        verzameld.forEach(k => {
            const p = k.data[k.kozen_idx ?? 0]?.persoon;
            if (p?.license_key && p?.person_id) pidByLic.set(p.license_key, p.person_id);
        });
        if (pidByLic.size) {
            basis = basis.map(it =>
                (!it.person_id && it.license_key && pidByLic.has(it.license_key))
                    ? { ...it, person_id: pidByLic.get(it.license_key) }
                    : it);
            veranderd = true;
        }
    }
    if (veranderd) {
        localStorage.setItem(KIDS_LS_KEY, JSON.stringify(basis));
        if (typeof _renderSetupVolglijst === 'function') _renderSetupVolglijst();  // chips meteen bij
        if (wegKeys.size && typeof _ppSync === 'function') _ppSync();               // push-abonnement bij
    }
    if (_kinderen.length) {
        _activeKindIdx = 0;
        renderKinderen();
        divResult.scrollIntoView({ behavior:'smooth', block:'start' });
        _wmodalLaatstKozenComp = null;   // succes → geen auto-prompt
    } else {
        divResult.innerHTML = '';
        // Als deze wedstrijd-change het directe gevolg was van een keuze in
        // de wedstrijd-modal (en de volglijst heeft geen rijders die in déze
        // wedstrijd meedoen), open dan meteen de rijder-zoek-modal — anders
        // zou de gebruiker alleen een lege wedstrijd-view zien zonder knop
        // om een rijder toe te voegen (de + verschijnt pas bij ≥1 kind).
        if (_wmodalLaatstKozenComp === selComp.value) {
            _wmodalLaatstKozenComp = null;
            setTimeout(() => openSetupModal(), 200);
        }
    }
});
inpSnr.addEventListener('input', () => { btnZoek.disabled = !(selComp.value && inpSnr.value.trim()); });
inpSnr.addEventListener('keydown', e => { if (e.key==='Enter' && !btnZoek.disabled) btnZoek.click(); });

// ── Zoek-input: detecteer of de user een startnummer, licentienummer of
//    een achternaam typt. Regel:
//      - alleen cijfers, 1-4 tekens → startnummer
//      - alleen cijfers, 5+ tekens  → licentienummer (KNSB relatienr)
//      - bevat letters              → achternaam-zoek
function _zoekModus(tekst) {
    const t = tekst.trim();
    // Volg-ID (geheim token uit Mijn InlineComp) = 32 hex-tekens → volg-lookup.
    // Dit is de enige manier om een publiek anonieme rijder te volgen (variant B):
    // het token ontsluit ('entitled') de echte naam; person_id doet dat niet.
    if (/^[0-9a-f]{32}$/i.test(t)) return 'volg';
    if (/^\d+$/.test(t)) return t.length <= 4 ? 'snr' : 'license';
    return 'naam';
}

// Toon een zoek-melding op de JUISTE plek: ín de setup-modal als die open staat
// (anders valt de melding achter de modal), anders in het hoofdresultaat-gebied.
function _zoekFeedback(html, isFout = false) {
    const box = `<div class="melding${isFout ? ' melding-fout' : ''}">${html}</div>`;
    const modal = document.getElementById('setup-modal');
    const sm = document.getElementById('setup-melding');
    if (modal && modal.classList.contains('open') && sm) sm.innerHTML = box;
    else divResult.innerHTML = box;
}
function _zoekFeedbackWis() {
    const sm = document.getElementById('setup-melding');
    if (sm) sm.innerHTML = '';
}

btnZoek.addEventListener('click', async () => {
    if (_loadKidsUitStorage().length >= MAX_KINDEREN) return;   // max bereikt — eerst verwijderen
    const compId = selComp.value, tekst = inpSnr.value.trim();
    if (!compId || !tekst) return;
    const modus = _zoekModus(tekst);

    if (modus === 'naam') {
        await zoekOpNaam(compId, tekst);
        return;
    }

    _zoekFeedback(`<span class="spinner"></span> ${esc(t('msg_zoeken'))}`);
    btnZoek.disabled = true;
    try {
        // Startnummer én volg-token via POST (plan URL/log-reductie 2026-10-02).
        // license_key blijft GET; wordt in batch 4 van het plan hernoemd naar
        // person_id (identifier zonder herleidbare waarde).
        const lookupUrl = `?action=lookup&competition_id=${encodeURIComponent(compId)}`;
        const bodyParam = (modus === 'snr')  ? { startnummer: tekst }
                        : (modus === 'volg') ? { volg: tekst }
                        : null;
        const lookupPromise = bodyParam
            ? safeFetch(lookupUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(bodyParam),
              })
            : safeFetch(lookupUrl + `&person_id=${encodeURIComponent(tekst)}`);
        const [lookupRes, progRes] = await Promise.all([
            lookupPromise,
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`)
        ]);
        const data = await lookupRes.json();
        const prog = await progRes.json();

        if (data.error) { _zoekFeedback(esc(data.error), true); return; }
        if (!data.length) { _zoekFeedback(esc(t('msg_geen_resultaten'))); return; }

        // Een anonieme rijder kun je niet op startnummer (of naam) volgen —
        // alleen via het onraadbare volg-ID. Filter anonieme treffers eruit en
        // toon een uitleg als er niets volgbaars overblijft.
        const volgbaar = data.filter(d => !d.persoon?.is_anoniem);
        if (!volgbaar.length) {
            _zoekFeedback(esc(t('msg_rijder_anoniem')));
            return;
        }

        // Meerdere personen met zelfde startnummer (of license) → chooser-modal
        // met checkboxes zodat de user er meerdere tegelijk kan toevoegen.
        if (volgbaar.length > 1) {
            const rijen = volgbaar.map(d => ({
                person_id:    d.persoon.person_id ?? null,
                license_key:  d.persoon.license_key,
                full_name:    d.persoon.full_name,
                wedstrijd_snr: d.persoon.wedstrijd_snr ?? d.persoon.start_number,
                category:     d.persoon.category,
                club_short:   d.persoon.club_short ?? '',
            }));
            _zoekFeedbackWis();
            toonChooserModal(rijen, tekst, compId);
            return;
        }

        // Voor license/snr gebruiken we het startnr uit de response (kan per
        // wedstrijd verschillen); toonRijderData deduped op license_key.
        const huidigSnr = volgbaar[0].persoon.wedstrijd_snr ?? volgbaar[0].persoon.start_number ?? tekst;
        _zoekFeedbackWis();                // modal sluit hierna → geen stale spinner
        toonRijderData([volgbaar[0]], 0, huidigSnr, prog);
        inpSnr.value = '';
        btnZoek.disabled = true;
    } catch (e) {
        _zoekFeedback(esc(t('err_prefix', {msg: e.message})), true);
    } finally { btnZoek.disabled = false; }
});

// ── Herbruikbare multi-select chooser-modal ─────────────────────────────────
// Gebruikt voor zowel naam-zoek als startnummer-match met meerdere hits.
// `rijen` moet items hebben met: {license_key, full_name, wedstrijd_snr,
// category, club_short}. Na "Toevoegen" wordt per gekozen license_key een
// volledige lookup gedaan en aan _kinderen toegevoegd.
function toonChooserModal(rijen, term, compId) {
    // Reeds gevolgd (globale volglijst) → uitschakelen. Ook rijders die je volgt
    // maar die niet in déze wedstrijd meedoen tellen mee, zodat je nooit boven
    // het maximum van de globale volglijst uitkomt.
    // Reeds-gevolgd-set bevat zowel person_id als license_key (fase 3c), zodat
    // een treffer op één van beide als "al in lijst" telt, ongeacht welke sleutel
    // het opgeslagen item draagt.
    const al = new Set();
    _loadKidsUitStorage().forEach(k => { if (k.person_id) al.add(k.person_id); if (k.license_key) al.add(k.license_key); });
    const plaatsVrij = MAX_KINDEREN - _loadKidsUitStorage().length;

    const modal = document.createElement('div');
    modal.className = 'naamzoek-modal';
    modal.innerHTML = `
        <div class="naamzoek-box">
            <div class="naamzoek-hdr">
                <span>${esc(t('chooser_titel', {term}))}</span>
                <button class="naamzoek-sluit" title="${esc(t('chooser_sluit'))}">&times;</button>
            </div>
            <div class="naamzoek-body">
                ${rijen.length === 0
                    ? `<div class="naamzoek-leeg">${esc(t('msg_geen_rijders'))}</div>`
                    : rijen.map(r => {
                        const uit = (r.person_id && al.has(r.person_id)) || al.has(r.license_key);
                        // search_person geeft `in_wedstrijd` (1/0); snr-pad niet,
                        // dan behandelen we als altijd-wel (undefined === wel).
                        const doetMee = r.in_wedstrijd === undefined ? true : !!parseInt(r.in_wedstrijd);
                        const meta = [
                            r.category || '',
                            r.club_short ? esc(r.club_short) : '',
                            uit ? `<span style="color:#999">${esc(t('chooser_al_in_lijst'))}</span>` : '',
                            !doetMee ? `<span style="color:#b71c1c">${esc(t('chooser_doet_niet_mee'))}</span>` : '',
                        ].filter(Boolean).join(' · ');
                        return `<label class="naamzoek-rij${uit ? ' dim' : ''}">
                            <input type="checkbox" data-pid="${esc(r.person_id ?? '')}" data-lic="${esc(r.license_key)}" ${uit ? 'checked disabled' : ''}>
                            <span class="naamzoek-rij-snr">${esc(r.wedstrijd_snr ?? '—')}</span>
                            <div class="naamzoek-rij-naam">
                                ${esc(r.full_name)}
                                <div class="naamzoek-rij-meta">${meta}</div>
                            </div>
                        </label>`;
                    }).join('')}
            </div>
            <div class="naamzoek-voet">
                <span class="aantal">${esc(t('chooser_max', {max: MAX_KINDEREN, vrij: plaatsVrij}))}</span>
                <div>
                    <button class="btn-zoek" style="padding:8px 18px;margin:0" id="naamzoek-ok">${esc(t('chooser_toevoegen'))}</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(modal);
    divResult.innerHTML = '';

    const sluit = () => modal.remove();
    modal.querySelector('.naamzoek-sluit').addEventListener('click', sluit);
    modal.addEventListener('click', e => { if (e.target === modal) sluit(); });

    modal.querySelector('#naamzoek-ok').addEventListener('click', async () => {
        const vinkjes = [...modal.querySelectorAll('input[type=checkbox]:checked:not(:disabled)')];
        if (!vinkjes.length) { sluit(); return; }
        if (vinkjes.length > plaatsVrij) {
            alert(t('alert_max_select', {max: MAX_KINDEREN, vrij: plaatsVrij, n: vinkjes.length}));
            return;
        }
        sluit();
        divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_rijders_ophalen')}</div>`;
        let prog = null;
        try {
            const pr = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`);
            prog = await pr.json();
        } catch {}
        for (const cb of vinkjes) {
            const pid = cb.dataset.pid;
            const lic = cb.dataset.lic;
            if (!pid && !lic) continue;
            // Prefereer de echte person_id (fase 3c); val terug op het
            // legacy-token (ex-license_key) dat server-side ook via
            // resolveNaarPersonId() wordt afgehandeld. Beide als `person_id`-
            // param (plan URL/log-reductie 2026-10-02: parameter-naam
            // `license_key` is sinds de GUID-migratie misleidend).
            const param = `person_id=${encodeURIComponent(pid || lic)}`;
            try {
                const r = await safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}`);
                const d = await r.json();
                if (d && !d.error && d.length) {
                    const huidigSnr = d[0].persoon.wedstrijd_snr ?? d[0].persoon.start_number ?? '';
                    toonRijderData(d, 0, huidigSnr, prog);
                }
            } catch {}
        }
        inpSnr.value = '';
        btnZoek.disabled = true;
    });
}

// ── Naam-zoek: zoek via backend, toon chooser ────────────────────────────────
async function zoekOpNaam(compId, term) {
    _zoekFeedback(`<span class="spinner"></span> ${esc(t('msg_zoeken_op', {term: esc(term)}))}`);
    btnZoek.disabled = true;
    let rijen = [];
    try {
        // Zoekterm via POST-body (plan URL/log-reductie 2026-10-02): houdt
        // achternamen uit de web-server-access-logs. competition_id blijft
        // in de URL (ETag/cache-vriendelijk, geen privacy-issue).
        const res = await safeFetch(`?action=search_person&competition_id=${encodeURIComponent(compId)}`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ q: term }),
        });
        rijen = await res.json();
        if (!Array.isArray(rijen)) rijen = [];
    } catch (e) {
        _zoekFeedback(esc(t('err_zoeken', {msg: e.message})), true);
        btnZoek.disabled = false;
        return;
    } finally { btnZoek.disabled = false; }
    // Geen naam-treffer → of/of-melding (variant B): niet-bevestigend, dekt zowel
    // "doet niet mee" als "koos anoniem". Een anonieme rijder is nooit op naam
    // vindbaar; toevoegen kan dan alleen via het licentie-/ID-nummer.
    if (rijen.length === 0) {
        _zoekFeedback(esc(t('msg_naam_geen_of_of')));
        return;
    }
    _zoekFeedbackWis();
    toonChooserModal(rijen, term, compId);
}

// refreshRijder() is verwijderd — was gekoppeld aan het ↻-knopje dat door
// de auto-refresh + ↻-stempel-indicator overbodig is geworden.

function toonRijder(idx) {
    window._gekozenIdx = idx;
    toonRijderData(window._lookupData, idx, window._lookupSnr, window._lookupProg);
}

// ── Multi-rijder-state (ouders met meerdere kinderen) ────────────────────────
// _kinderen = [{snr, data, prog, sub_tab}] waarbij data = lookup-response
// (array met persoon+heats) en prog = programma-response van de wedstrijd.
// Max 4 kids om de top-tabs leesbaar te houden op een telefoon.
const MAX_KINDEREN = 4;
let _kinderen = [];
let _activeKindIdx = 0;
// Volgnummer per kinderen-load: beschermt tegen twee (bijna) gelijktijdige
// change-handlers die anders tijdens hun await's in dezelfde _kinderen zouden
// pushen → dubbele rijders (bv. QR-open dispatcht change 2×). Alleen de nieuwste
// load mag _kinderen vullen + renderen; oudere breken af.
let _kindLoadSeq = 0;

// ── Programma-tab: inklap-state (public) ─────────────────────────────────────
// _progIngeklaptPub bevat de groep-keys die INGEKLAPT zijn (default = alles
// bij eerste render). _progAlleKeysPub = alle keys van laatste render, nodig
// voor "Alles in/uit". _progGroepenMetMijnPub = keys waar de gekozen rijder
// in zit ("Mijn ritten"-knop). _progEersteRenderPub triggert alleen bij het
// éérste render van deze rijder-tab.
const _progIngeklaptPub = new Set();
let _progAlleKeysPub = [];
const _progGroepenMetMijnPub = new Set();
let _progEersteRenderPub = true;

// Programma-UI-state per rijder — bij wisselen van kind-tab willen we
// dat elke rijder z'n eigen selectie (dag/afstand/klap/open groepen)
// onthoudt. Bij eerste bezoek van een nieuwe rijder proberen we de state
// van de vorige rijder over te nemen; als de bewaarde afstand niet in de
// nieuwe data voorkomt valt _restoreProgUiStatePub automatisch terug op
// "alle" (want de pill-lookup returnt null en de kiesProgFilter-call
// wordt geskipt). Keyed op license_key voor stabiliteit tussen sessies.
const _progUiStatePerKind = new Map();
let _pendingProgRestore = null;
function _kindKey(k) {
    return k?.data?.[k.kozen_idx ?? 0]?.persoon?.license_key || `snr:${k?.snr || ''}`;
}

// Snapshot / restore van de programma-tab UI-state rond een re-render.
// renderKinderen() bouwt de rijder-tab-HTML opnieuw én reset de klap-state
// naar default-collapsed. Bij auto-refresh (stilleRefresh) willen we die
// user-state juist behouden: filter-strook (dag+afstand), klap-balk
// (uit/in/mijn), én welke groepen handmatig open/dicht zijn geklapt.
function _snapshotProgUiStatePub() {
    const tab = document.querySelector('.tab-content[data-tab="programma"]');
    if (!tab) return null;
    const strook = tab.querySelector('.prog-filter-strook');
    const balk   = tab.querySelector('.prog-klap-balk');
    const open   = new Set();
    tab.querySelectorAll('.prog-groep').forEach(g => {
        if (g.classList.contains('samenvat')) return;
        if (!g.classList.contains('ingeklapt')) open.add(g.dataset.groepKey);
    });
    return {
        dag:     strook?.dataset.actieveDag     || 'alle',
        afstand: strook?.dataset.actieveAfstand || 'alle',
        klap:    balk?.dataset.actief           || '',
        open,
    };
}

function _restoreProgUiStatePub(state) {
    if (!state) return;
    const tab = document.querySelector('.tab-content[data-tab="programma"]');
    if (!tab) return;
    const strook = tab.querySelector('.prog-filter-strook');
    // Filter via bestaande handler — die triggert applyProgFilter (samenvat,
    // heat-tellers, verborgen-classes).
    if (strook) {
        if (state.dag && state.dag !== 'alle') {
            const p = strook.querySelector(
                `.prog-filter-panel[data-panel="dag"] .prog-filter-pill[data-value="${CSS.escape(state.dag)}"]`);
            if (p) kiesProgFilter('dag', state.dag, p);
        }
        if (state.afstand && state.afstand !== 'alle') {
            const p = strook.querySelector(
                `.prog-filter-panel[data-panel="afstand"] .prog-filter-pill[data-value="${CSS.escape(state.afstand)}"]`);
            if (p) kiesProgFilter('afstand', state.afstand, p);
        }
    }
    // Klap-balk: preset actief → knop klikken. Anders per-groep restore.
    if (state.klap === 'uit' || state.klap === 'in' || state.klap === 'mijn') {
        const btn = tab.querySelector(`.prog-klap-balk .prog-klap-btn[data-actie="${state.klap}"]`);
        if (btn) btn.click();
    } else if (state.open) {
        tab.querySelectorAll('.prog-groep').forEach(g => {
            if (g.classList.contains('samenvat')) return;
            const key = g.dataset.groepKey;
            const moetOpen = state.open.has(key);
            g.classList.toggle('ingeklapt', !moetOpen);
            if (moetOpen) _progIngeklaptPub.delete(key);
            else          _progIngeklaptPub.add(key);
        });
        const balk = tab.querySelector('.prog-klap-balk');
        if (balk) {
            balk.dataset.actief = '';
            balk.querySelectorAll('.prog-klap-btn').forEach(b => b.classList.remove('actief'));
        }
    }
}

// ── Setup-modal: wedstrijd + rijder-kies overlay ─────────────────────────────
// Vervangt de altijd-zichtbare stap 1 + 2 secties. Opent via de setup-strip
// bovenaan, de "+"-rijder-tab-knop, of automatisch bij eerste bezoek van
// de dag (localStorage-detectie op datum-key).
function openSetupModal() {
    const m = document.getElementById('setup-modal');
    if (m) m.classList.add('open');
    document.body.style.overflow = 'hidden'; // scroll-lock achtergrond
    _zoekFeedbackWis();                       // geen stale melding van vorige keer
    _renderSetupVolglijst();
    _updateSetupModalMax();
}

// ── Hub-view (fase 5a-UX-flat) ───────────────────────────────────────────
// Hoofdview met 3 tabs (Wedstrijden/Organisaties/Instellingen). Was eerst
// een modal (#wedstrijd-modal), is nu een inline top-level view: default
// zichtbaar bij app-start, verborgen zodra een wedstrijd actief is of een
// org-detail-view wordt getoond. Via de ←-knop in de setup-strip of in de
// org-view terug naar hub.
//
// 3 view-states (via _setViewState):
//   - 'hub'       → hub-view zichtbaar, strip + resultaat verborgen
//   - 'wedstrijd' → hub verborgen, strip + resultaat zichtbaar
//   - 'org'       → hub + strip verborgen, resultaat zichtbaar (met org-view)
const _RECENT_WEDSTRIJDEN_KEY    = 'ic_pub_recent_wedstrijden';
const _RECENT_WEDSTRIJDEN_MAX    = 8;
const _RECENT_WEDSTRIJDEN_MAX_MS = 30 * 24 * 60 * 60 * 1000;   // 30 dagen
let _wmodalComps          = null;   // cache van ?action=competitions-response
let _wmodalSeizoen        = null;   // actieve seizoen (kalenderjaar als number)
let _wmodalLaatstKozenComp = null;  // comp-id net gekozen uit hub → auto-prompt rijder-modal als volglijst leeg blijkt

// Centrale view-state helper. 'hub' = hoofdview, 'wedstrijd' = wedstrijd-
// content (strip + chips + programma/heats/…), 'org' = organisatie-detail
// (strip verborgen, alleen org-view in #resultaat).
function _setViewState(mode) {
    const hub   = document.getElementById('hub-view');
    const strip = document.getElementById('setup-strip');
    const res   = document.getElementById('resultaat');
    const foot  = document.getElementById('org-footer');
    if (hub)   hub.hidden   = (mode !== 'hub');
    if (strip) strip.hidden = (mode !== 'wedstrijd');
    if (res)   res.hidden   = (mode === 'hub');
    // Footer-ticker (org-logo + baan-logo + sponsor-marquee) hoort alleen bij
    // de wedstrijd-view. In hub/org/wedstrijdinfo gaat 'ie uit (anders staat
    // de ticker van de laatst-gekozen wedstrijd verwarrend onder een org-
    // view of hub). updateHeaderLogos() in de selComp-change-handler zet 'm
    // weer op display:block zodra je in de wedstrijd-view terugkomt.
    if (foot && mode !== 'wedstrijd') foot.style.display = 'none';
    // 'wedstrijdinfo' en 'org' tonen beide alleen #resultaat (strip + hub
    // verborgen) — _rerenderActiveTab onderscheidt via _aktieveWedstrijdInfo /
    // _wmodalAktieveOrg welke content er hangt.

    // Sessie-herstel: onthoud waar de user is zodat een refresh (pull-to-
    // refresh of auto) terugkomt op dezelfde plek i.p.v. standaard-hub of
    // onverwacht in een wedstrijd-view. 'org'/'wedstrijdinfo' slaan we voor
    // nu niet op (zouden org- of comp-cache nodig hebben om te heropenen) —
    // bij refresh landt een org-user terug in hub, Organisaties-tab.
    if (mode === 'wedstrijd' && selComp?.value) {
        _onthoudViewState({ mode: 'wedstrijd', compId: selComp.value });
    } else if (mode === 'org') {
        _onthoudViewState({ mode: 'hub', tab: 'organisaties' });
    }
}

// Sessie-storage helpers (per browser-tab, verdwijnt bij browser-close).
// localStorage zou over tabs heen leaken; sessionStorage voelt juist voor
// "waar was ik net?"-herstel binnen één app-sessie.
const _VIEW_STATE_KEY = 'ic_pub_view_state';
function _onthoudViewState(st) {
    try { sessionStorage.setItem(_VIEW_STATE_KEY, JSON.stringify(st)); }
    catch (e) { /* private mode / disabled storage — niet erg */ }
}
function _herstelViewState() {
    try {
        const raw = sessionStorage.getItem(_VIEW_STATE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}
// Hub tonen (aangeroepen vanuit setup-strip klik en vanuit org-view-terug).
// Behoudt onclick-naam-contract met index.php (setup-strip onclick).
function toonHubView(tab) {
    const t = tab || 'wedstrijden';
    _setViewState('hub');
    _onthoudViewState({ mode: 'hub', tab: t });
    _invalideerWedstrijdModalCaches();
    switchWedstrijdTab(t);
    if (t === 'wedstrijden') _laadWedstrijdLijst();
}
// Alle caches die aan volglijst/wedstrijd-publicatie hangen invalideren.
// Server-side max-age=30/60 vangt eventuele dubbele requests op bij snel
// hub-open/dicht/open. Ook aan te roepen vanuit andere flows (bv. kinderen-
// wijziging die niet via hub-close loopt — zie _saveKids).
function _invalideerWedstrijdModalCaches() {
    _wmodalComps        = null;
    _wmodalOrgCache     = null;
    _wmodalOrgSig       = null;
    for (const k of Object.keys(_orgWedstrijdenCache)) delete _orgWedstrijdenCache[k];
}
// Backwards-compat alias: nog door andere JS-paden gebruikt (bv. als er in
// de toekomst code is die refereert aan "modal open"). Nu zet 'ie view-state
// op 'hub' zonder verdere caching-reset — gebruikt door openWedstrijdModal()
// als eerste stap.
function openWedstrijdModal() { toonHubView('wedstrijden'); }
function closeWedstrijdModal() { _setViewState('wedstrijd'); }
function switchWedstrijdTab(tabId) {
    document.querySelectorAll('.wmodal-tab').forEach(t => {
        const match = t.dataset.tab === tabId;
        t.classList.toggle('actief', match);
        t.setAttribute('aria-selected', match ? 'true' : 'false');
    });
    document.querySelectorAll('.wmodal-pane').forEach(p => {
        const match = p.id === 'wmodal-pane-' + tabId;
        p.hidden = !match;
        p.classList.toggle('actief', match);
    });
    // Alleen opslaan als de hub ook écht zichtbaar is — switchWedstrijdTab
    // wordt ook stiekem aangeroepen vanuit toonHubView (die zelf al opslaat
    // vóór 't tonen) en bij taalwissel; dubbel-save is onschadelijk, maar
    // verkeerd-save vanuit een niet-hub-context moeten we voorkomen.
    const hub = document.getElementById('hub-view');
    if (hub && !hub.hidden) _onthoudViewState({ mode: 'hub', tab: tabId });
    // Settings-tab: push-blok (her)renderen + fallback-tekst alleen tonen
    // als er niks te doen is (geen rijders én push-blok leeg). Fallback staat
    // los van push-aan-status: een gebruiker die push al aan heeft (controls
    // zichtbaar) hoort geen "nog geen pushmeldingen"-fallback te zien, ook
    // niet als volglijst momenteel leeg is — hij weet wat hij doet.
    if (tabId === 'settings') {
        if (typeof _ppRender === 'function') _ppRender();
        _wmodalSettingsLeegUpdate();
    }
    // Organisaties-tab: lijst laden (alleen orgs waar rijders uit volglijst
    // gereden hebben; AVG-correct, alleen bekende contexten tonen).
    if (tabId === 'organisaties') _laadOrganisatieLijst();
}

// Zichtbaarheid van de "nog geen pushmeldingen"-fallback actualiseren.
// Baseert zich op volglijst-count én push-blok-inhoud; _ppRender() is async
// (checkt subscription), dus we doen een korte setTimeout zodat we na de
// render evalueren. Robuust tegen race-conditions.
function _wmodalSettingsLeegUpdate() {
    const fall = document.getElementById('wmodal-settings-leeg');
    const pp   = document.getElementById('pub-push');
    if (!fall) return;
    const check = () => {
        const geenRijders = _loadKidsUitStorage().length === 0;
        const ppLeeg      = !pp || !pp.innerHTML.trim();
        fall.hidden = !(geenRijders && ppLeeg);
    };
    check();
    setTimeout(check, 200);   // na eventuele async _ppRender()-resolve
}

// Fetch de competitions-lijst (met 30s server-cache), vul seizoen-selector,
// en render de kaart-lijst van het actieve seizoen. Reuses alleComps als die
// al in memory zit; anders aparte fetch zodat we de modal onafhankelijk van
// filterComps() kunnen openen.
// license_key → person_id resolve-map voor legacy volglijst-items. Gevuld
// door _laadWedstrijdLijst na de mijn_wedstrijden-fetch. Zonder dit mist
// _eigenRijdersMap de pid-entries voor legacy items (die alleen een
// license_key hebben), en krijgt de user ⭐-fallback i.p.v. voornaam.
let _wmodalLicenseResolve = {};

async function _laadWedstrijdLijst() {
    const lijst = document.getElementById('wmodal-wedstrijd-lijst');
    if (!lijst) return;
    try {
        if (!_wmodalComps) {
            // Parallel: competitions-lijst én eigen-rijder-markering per
            // wedstrijd (voornaam-pillen op kaarten). mijn_wedstrijden levert
            // {wedstrijden: {comp_id: pids}, licenses: {lkey: person_id}}
            // alleen voor niet-demo wedstrijden; lege shape als volglijst leeg.
            const vl = _volglijstEndpointParams(_loadKidsUitStorage());
            const [resComps, resMijn] = await Promise.all([
                safeFetch('?action=competitions' + (DEMO_MODE ? '&demo=1' : '')),
                vl.empty ? Promise.resolve(null) : safeFetch('?action=mijn_wedstrijden' + vl.qs),
            ]);
            const comps = await resComps.json();
            const mijn  = resMijn ? await resMijn.json() : { wedstrijden: {}, licenses: {} };
            const wmap  = mijn.wedstrijden || {};
            _wmodalLicenseResolve = mijn.licenses || {};
            // Merge eigen_person_ids per wedstrijd (zelfde shape als server-
            // side in public_org_wedstrijden al teruggeeft: comma-string).
            for (const c of comps) c.eigen_person_ids = wmap[c.id] || null;
            _wmodalComps = comps;
        }
        _vulSeizoenSelector(_wmodalComps);
        _renderWedstrijdLijst();
    } catch (e) {
        lijst.innerHTML = `<div class="wmodal-geen-wedstrijden">${esc(t('msg_fout_laden'))}</div>`;
    }
}

function _vulSeizoenSelector(comps) {
    const sel = document.getElementById('wmodal-sel-seizoen');
    if (!sel) return;
    const jaren = new Set();
    const nu    = new Date().getFullYear();
    jaren.add(nu);
    for (const c of comps) {
        const d = safeDatum(c.starts);
        if (d) jaren.add(d.getFullYear());
    }
    const gesort = [...jaren].sort((a, b) => b - a);  // nieuwste eerst
    sel.innerHTML = gesort.map(j =>
        `<option value="${j}">${j}</option>`
    ).join('');
    if (_wmodalSeizoen == null || !jaren.has(_wmodalSeizoen)) _wmodalSeizoen = nu;
    sel.value = _wmodalSeizoen;
    sel.onchange = () => {
        _wmodalSeizoen = parseInt(sel.value, 10) || nu;
        _renderWedstrijdLijst();
    };
}

function _renderWedstrijdLijst() {
    const lijst = document.getElementById('wmodal-wedstrijd-lijst');
    if (!lijst || !_wmodalComps) return;
    // Volglijst → Map(person_id → kid-record) voor pil-naam-resolve in
    // _kaartHtml. Vers maken bij elke render — volglijst kan intussen zijn
    // gewijzigd en de cache-reset in _saveKids triggert deze render alsnog.
    // Ook legacy items (alleen license_key) opnemen via de server-side
    // license→person_id resolve-map; zonder dat zouden pil-pids niet
    // matchen en krijgt user ⭐-fallback voor de legacy-gevolgde rijder.
    _eigenRijdersMap = new Map();
    for (const k of _loadKidsUitStorage()) {
        if (k.person_id) _eigenRijdersMap.set(k.person_id, k);
        else if (k.license_key && _wmodalLicenseResolve[k.license_key]) {
            _eigenRijdersMap.set(_wmodalLicenseResolve[k.license_key], k);
        }
    }
    const nu = new Date();
    const vandaag = new Date(nu.getFullYear(), nu.getMonth(), nu.getDate());
    const overmorgen = new Date(vandaag); overmorgen.setDate(overmorgen.getDate() + 2);
    const eindweek   = new Date(vandaag); eindweek.setDate(eindweek.getDate() + 7);
    const seizoenJaar = _wmodalSeizoen || vandaag.getFullYear();

    // Filter op actief seizoen (kalenderjaar: 1-1 t/m 31-12).
    const inSeizoen = _wmodalComps.filter(c => {
        const d = safeDatum(c.starts);
        return d && d.getFullYear() === seizoenJaar;
    });

    const recentIds = _recentBekekenGet();
    const groepen = { recent: [], vandaagMorgen: [], dezeWeek: [], overige: [] };

    for (const c of inSeizoen) {
        const d = safeDatum(c.starts);
        if (!d) { groepen.overige.push(c); continue; }
        if (d >= vandaag && d < overmorgen)       groepen.vandaagMorgen.push(c);
        else if (d >= overmorgen && d < eindweek) groepen.dezeWeek.push(c);
        else                                       groepen.overige.push(c);
    }
    // Recent-bekeken: alleen tonen voor huidig seizoen (verwarring voorkomen
    // met historische wedstrijden die je ooit opende maar nu buiten scope zijn).
    if (seizoenJaar === vandaag.getFullYear()) {
        for (const id of recentIds) {
            const c = inSeizoen.find(x => x.id === id);
            if (c) groepen.recent.push(c);
        }
    }

    // Vandaag/deze-week: chronologisch oplopend (eerstvolgende eerst).
    // Overige: aflopend — gaat vooral om verleden wedstrijden (afgelopen
    // seizoen), gebruiker wil de meest recente datum bovenaan ("recenste
    // → langer geleden"). Eventuele verre toekomst-wedstrijden komen zo
    // ook bovenaan, wat prima is: hoogste datum eerst.
    const sortChrono = (a, b) => (safeDatum(a.starts)?.getTime() ?? 0) - (safeDatum(b.starts)?.getTime() ?? 0);
    groepen.vandaagMorgen.sort(sortChrono);
    groepen.dezeWeek.sort(sortChrono);
    groepen.overige.sort((a, b) => -sortChrono(a, b));

    const htmlStukken = [];
    if (groepen.vandaagMorgen.length) {
        htmlStukken.push(`<div class="wmodal-periode-hdr">${esc(t('wmodal_vandaag_morgen'))}</div>`);
        htmlStukken.push(...groepen.vandaagMorgen.map(_kaartHtml));
    }
    if (groepen.dezeWeek.length) {
        htmlStukken.push(`<div class="wmodal-periode-hdr">${esc(t('wmodal_deze_week'))}</div>`);
        htmlStukken.push(...groepen.dezeWeek.map(_kaartHtml));
    }
    if (groepen.recent.length) {
        htmlStukken.push(`<div class="wmodal-periode-hdr">${esc(t('wmodal_recent_bekeken'))}</div>`);
        htmlStukken.push(...groepen.recent.map(_kaartHtml));
    }
    if (groepen.overige.length) {
        htmlStukken.push(`<div class="wmodal-periode-hdr">${esc(t('wmodal_overige'))}</div>`);
        htmlStukken.push(...groepen.overige.map(_kaartHtml));
    }
    if (!htmlStukken.length) {
        htmlStukken.push(`<div class="wmodal-geen-wedstrijden">${esc(t('wmodal_geen_wedstrijden'))}</div>`);
    }
    lijst.innerHTML = htmlStukken.join('');

    // Click-handler per kaart (event-delegation).
    lijst.querySelectorAll('.wmodal-kaart').forEach(el => {
        el.addEventListener('click', () => _kiesWedstrijdUitModal(el.dataset.compId));
    });
}

// Pil-HTML met voornaam voor rijders uit de volglijst die aan de wedstrijd
// meededen. Server geeft person_ids (stabiele identiteit, startnrs kunnen per
// wedstrijd verschillen); client matcht tegen naam-hint in localStorage.
// Fallback bij lege/onbekende naam: generieke ⭐. Gebruikt in hub Wedstrijden-
// tab (verhuisd uit org-view-agenda op verzoek fase 5a-UX-flat, waar deze
// pillen te veel ruimte innemen voor nog-te-komen org-tag-labels).
function _eigenRijdersPilHtml(eigenPidsStr) {
    const pids = eigenPidsStr
        ? String(eigenPidsStr).split(',').map(s => s.trim()).filter(Boolean)
        : [];
    if (!pids.length) return '';
    const map = _eigenRijdersMap || new Map();
    return pids.map(pid => {
        const voornaam = (map.get(pid)?.naam_hint || '').split(/\s+/)[0];
        return voornaam
            ? `<span class="org-wed-snr-pil">${esc(voornaam)}</span>`
            : `<span class="org-wed-snr-pil org-wed-snr-pil--onbekend">⭐</span>`;
    }).join('');
}

function _kaartHtml(c) {
    const d = safeDatum(c.starts);
    const dag = d ? d.getDate() : '?';
    const mnd = d ? _mndKort(d) : '';
    const plaatsBits = [];
    if (c.baan_vereniging) plaatsBits.push(esc(c.baan_vereniging));
    if (c.org_naam)        plaatsBits.push(esc(c.org_naam));
    const plaats = plaatsBits.join(' · ');
    const badge  = _wedstrijdBadge(c, d);
    const badgeHtml = badge ? `<span class="wmodal-tag wmodal-tag--${badge.key}">${esc(t(badge.i18n))}</span>` : '';
    const pilHtml = _eigenRijdersPilHtml(c.eigen_person_ids);
    const tagsRij = (badgeHtml || pilHtml) ? `<div class="wmodal-tags">${badgeHtml}${pilHtml}</div>` : '';
    return `
        <div class="wmodal-kaart" data-comp-id="${esc(c.id)}" tabindex="0" role="button">
            <div class="wmodal-datum">
                <div class="wmodal-datum-dag">${dag}</div>
                <div class="wmodal-datum-mnd">${esc(mnd)}</div>
            </div>
            <div class="wmodal-info">
                <div class="wmodal-naam">${esc(c.name)}</div>
                ${plaats ? `<div class="wmodal-plaats">${plaats}</div>` : ''}
                ${tagsRij}
            </div>
        </div>`;
}

// State-afleiding voor de badge. Vereenvoudigde heuristiek voor fase 5a:
// - binnenkort = operator heeft "stille voorbereiding" aan (aankondigen zonder zichtbaar maken)
// - live       = nu tussen starts en ends (precieze heats-loting-check komt in fase 5b)
// - vandaag    = starts == today, nog niet begonnen (loting-check idem)
function _wedstrijdBadge(c, d) {
    if (!d) return null;
    const nu       = new Date();
    const vandaag  = new Date(nu.getFullYear(), nu.getMonth(), nu.getDate());
    const dagStart = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    if (c.public_zichtbaar == 0 && c.public_aankondigen == 1) return { key: 'binnenkort', i18n: 'wmodal_tag_binnenkort' };
    const eindD = safeDatum(c.ends);
    const nuInWedstrijd = d <= nu && (!eindD || nu <= eindD);
    if (nuInWedstrijd && dagStart.getTime() === vandaag.getTime()) return { key: 'live', i18n: 'wmodal_tag_live' };
    if (dagStart.getTime() === vandaag.getTime()) return { key: 'vandaag', i18n: 'wmodal_tag_vandaag' };
    return null;
}

function _mndKort(d) {
    const loc  = (typeof getLocale === 'function') ? getLocale() : 'nl';
    return d.toLocaleString(loc, { month: 'short' }).replace('.', '').toUpperCase();
}

// Shared helper: wedstrijd activeren (zet sel-comp op compId en triggert
// change). Gebruikt door zowel wedstrijd-modal (kaart-klik) als org-view
// (agenda-klik). Zoekt c in zowel wmodal-cache als org-wedstrijden-cache
// zodat een hidden-option toegevoegd kan worden als comp nog niet in
// sel-comp zit (bv. andere seizoen-filter actief, of org-only wedstrijd).
function _activeerWedstrijd(compId) {
    _recentBekekenPush(compId);
    _wmodalLaatstKozenComp = compId;   // signaal naar selComp-change-handler
    _setViewState('wedstrijd');        // hub + org-view uit, strip + resultaat aan
    const selComp = document.getElementById('sel-comp');
    if (!selComp) return;
    if (!selComp.querySelector(`option[value="${CSS.escape(compId)}"]`)) {
        let c = (_wmodalComps || []).find(x => x.id === compId);
        if (!c) {
            // Pak uit org-wedstrijden-cache (over alle orgs heen).
            for (const list of Object.values(_orgWedstrijdenCache || {})) {
                c = (list || []).find(x => x.id === compId);
                if (c) break;
            }
        }
        if (c) {
            const o = document.createElement('option');
            o.value = c.id;
            o.textContent = c.name;
            // Alle dataset-velden spiegelen die filterComps() ook zet — zonder
            // deze mist de setup-strip het baan-logo én de footer-ticker
            // (orglogo, baanlogo, sponsors) blijft leeg bij wedstrijden die via
            // deze fallback-pad (hub-cache of org-cache) in sel-comp belanden.
            o.dataset.naam           = c.name;
            o.dataset.datum          = c.starts || '';
            o.dataset.orgLogo        = c.org_logo ?? '';
            o.dataset.orgNaam        = c.org_naam ?? '';
            o.dataset.baanLogo       = c.baan_logo ?? '';
            o.dataset.baanVereniging = c.baan_vereniging ?? '';
            o.dataset.sponsors       = JSON.stringify(c.sponsors ?? []);
            selComp.appendChild(o);
        }
    }
    selComp.value = compId;
    selComp.dispatchEvent(new Event('change'));
}
function _kiesWedstrijdUitModal(compId) {
    if (!compId) return;
    _activeerWedstrijd(compId);
    closeWedstrijdModal();
}

// Recent-bekeken-cache in localStorage: array van {id, last}-objecten,
// nieuwste eerst, max _RECENT_WEDSTRIJDEN_MAX, en entries ouder dan
// _RECENT_WEDSTRIJDEN_MAX_MS (30 dagen) vallen eruit. Faalt stil in
// private/incognito. Oude plain-string-format (vóór 2026-10-01) wordt
// stil geschrapt — recent-bekeken is niet-kritiek, lijst start dan leeg.
function _recentBekekenGet() {
    try {
        const raw = localStorage.getItem(_RECENT_WEDSTRIJDEN_KEY);
        const arr = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(arr)) return [];
        const cutoff = Date.now() - _RECENT_WEDSTRIJDEN_MAX_MS;
        return arr
            .filter(x => x && typeof x === 'object' && x.id
                      && typeof x.last === 'number' && x.last >= cutoff)
            .map(x => x.id);
    } catch (e) { return []; }
}
function _recentBekekenPush(id) {
    try {
        const raw = localStorage.getItem(_RECENT_WEDSTRIJDEN_KEY);
        let arr = [];
        try { arr = JSON.parse(raw) || []; } catch { arr = []; }
        if (!Array.isArray(arr)) arr = [];
        const cutoff = Date.now() - _RECENT_WEDSTRIJDEN_MAX_MS;
        // Weggooien: oude format, duplicaten, verlopen entries.
        const schoon = arr.filter(x =>
            x && typeof x === 'object' && x.id && x.id !== id
            && typeof x.last === 'number' && x.last >= cutoff);
        schoon.unshift({ id, last: Date.now() });
        schoon.length = Math.min(schoon.length, _RECENT_WEDSTRIJDEN_MAX);
        localStorage.setItem(_RECENT_WEDSTRIJDEN_KEY, JSON.stringify(schoon));
    } catch (e) { /* storage uit — niet erg */ }
}

// ── Organisaties-tab (fase 5a-content) ────────────────────────────────────
// Toont alleen organisaties waar rijders uit de volglijst van de user ooit
// een wedstrijd hebben gereden. AVG-correct: user ziet alleen bekende
// contexten. Backend `?action=organisaties&person_ids=...` filtert serverside.
let _wmodalOrgCache = null;      // laatste fetch-response per volglijst-sig
let _wmodalOrgSig   = null;      // sig = sorted comma-string van person_ids
let _wmodalAktieveOrg = null;    // momenteel actieve organisatie (voor placeholder-view)

// Volglijst → query-params voor organisaties/org_wedstrijden-endpoints.
// Haalt person_ids op voor nieuwe items en license_keys als fallback voor
// legacy pre-GUID-migratie-items (nog niet via wedstrijd-opening passief
// gemigreerd). Returnt {qs: '&person_ids=…&license_keys=…', sig: '…'} —
// sig voor cache-matching.
function _volglijstEndpointParams(kinderen) {
    const pids  = kinderen.map(k => k.person_id).filter(Boolean);
    const lkeys = kinderen.filter(k => !k.person_id && k.license_key)
                          .map(k => k.license_key);
    const parts = [];
    if (pids.length)  parts.push('person_ids='  + encodeURIComponent([...pids].sort().join(',')));
    if (lkeys.length) parts.push('license_keys=' + encodeURIComponent([...lkeys].sort().join(',')));
    return {
        qs:  parts.length ? '&' + parts.join('&') : '',
        sig: 'p:' + [...pids].sort().join(',') + '|l:' + [...lkeys].sort().join(','),
        empty: pids.length === 0 && lkeys.length === 0,
    };
}

async function _laadOrganisatieLijst() {
    const container = document.getElementById('wmodal-organisatie-lijst');
    if (!container) return;
    const kinderen = _loadKidsUitStorage();
    if (!kinderen.length) {
        container.innerHTML = `
            <div class="wmodal-placeholder">
                <div class="wmodal-placeholder-ico">🏛</div>
                <p data-i18n="wmodal_org_geen_rijders_titel">${esc(t('wmodal_org_geen_rijders_titel'))}</p>
                <p class="wmodal-placeholder-sub" data-i18n="wmodal_org_geen_rijders_sub">${esc(t('wmodal_org_geen_rijders_sub'))}</p>
            </div>`;
        return;
    }
    const vl = _volglijstEndpointParams(kinderen);
    if (vl.empty) {
        container.innerHTML = `<div class="wmodal-geen-wedstrijden">${esc(t('wmodal_org_geen_wedstrijden'))}</div>`;
        return;
    }
    if (_wmodalOrgCache && _wmodalOrgSig === vl.sig) {
        _renderOrganisatieLijst(_wmodalOrgCache);
        return;
    }
    try {
        const res  = await safeFetch('?action=organisaties' + vl.qs);
        const data = await res.json();
        // Server gooit 500 met {error:"..."} bij SQL/PHP-fouten — niet als lege lijst behandelen.
        if (!res.ok || !Array.isArray(data)) {
            const msg = (data && data.error) ? data.error : 'HTTP ' + res.status;
            throw new Error(msg);
        }
        _wmodalOrgCache = data;
        _wmodalOrgSig   = vl.sig;
        _renderOrganisatieLijst(data);
    } catch (e) {
        container.innerHTML = `<div class="wmodal-geen-wedstrijden">${esc(t('msg_fout_laden'))}: ${esc(e.message || e)}</div>`;
    }
}

function _renderOrganisatieLijst(orgs) {
    const container = document.getElementById('wmodal-organisatie-lijst');
    if (!container) return;
    if (!orgs.length) {
        container.innerHTML = `<div class="wmodal-geen-wedstrijden">${esc(t('wmodal_org_geen_wedstrijden'))}</div>`;
        return;
    }
    container.innerHTML = orgs.map(_orgKaartHtml).join('');
    container.querySelectorAll('.wmodal-org-kaart').forEach(el => {
        el.addEventListener('click', () => _kiesOrganisatieUitModal(el.dataset.orgId));
    });
}

function _orgKaartHtml(o) {
    const logo = o.logo_path
        ? `<img class="wmodal-org-logo" src="../${esc(o.logo_path)}" alt="${esc(o.naam)}">`
        : `<div class="wmodal-org-logo wmodal-org-logo--letters">${esc(_orgInitialen(o.naam))}</div>`;
    const aantal = Math.max(0, parseInt(o.aantal_wedstrijden, 10) || 0);
    const stat = aantal === 1
        ? t('wmodal_org_1_wedstrijd')
        : t('wmodal_org_n_wedstrijden', { n: aantal });
    return `
        <div class="wmodal-org-kaart" data-org-id="${esc(o.id)}" tabindex="0" role="button">
            ${logo}
            <div class="wmodal-org-info">
                <div class="wmodal-org-naam">${esc(o.naam)}</div>
                <div class="wmodal-org-stat">${esc(stat)}</div>
            </div>
        </div>`;
}

// Simpele initialen-fallback: eerste letter van maximaal 2 "echte" woorden.
// "Skeelerclub Oost-Veluwe" → "SO"; "KNSB" → "KN"; "IJs- en Skeelerclub …" → "IS".
function _orgInitialen(naam) {
    const woorden = String(naam || '').split(/\s+/).filter(w => /[A-Za-zÀ-ÿ]/.test(w)).slice(0, 2);
    return woorden.map(w => w.charAt(0).toUpperCase()).join('') || '?';
}

function _kiesOrganisatieUitModal(orgId) {
    if (!orgId) return;
    const org = (_wmodalOrgCache || []).find(x => x.id === orgId);
    if (!org) return;
    _wmodalAktieveOrg = org;
    closeWedstrijdModal();
    _toonOrganisatieView(org);
}

// Organisatie-detail-view (fase 5a-content): header + 3 tabs (Agenda actief,
// Algemeen en Nieuws als placeholder). Agenda toont alle wedstrijden van de
// org gegroepeerd per periode, met labels (publiek/binnenkort/verborgen) en
// seizoen-filter. Alleen publieke wedstrijden zijn klikbaar.
const _orgWedstrijdenCache = {};   // {orgId: [...wedstrijd-objecten]}
let _orgViewSeizoen = null;        // actief seizoen (kalenderjaar)
let _eigenRijdersMap = null;       // Map(person_id → kid-record) voor pil-naam-resolve

function _toonOrganisatieView(org) {
    if (!divResult) return;
    _setViewState('org');   // verberg hub + setup-strip, laat alleen org-view in #resultaat
    const logo = org.logo_path
        ? `<img class="org-view-logo" src="../${esc(org.logo_path)}" alt="${esc(org.naam)}">`
        : `<div class="org-view-logo org-view-logo--letters">${esc(_orgInitialen(org.naam))}</div>`;
    divResult.innerHTML = `
        <div class="org-view" data-org-id="${esc(org.id)}">
            <div class="org-view-header">
                <button class="org-view-terug" type="button" data-i18n-title="org_view_terug" title="${esc(t('org_view_terug'))}">&lsaquo;</button>
                ${logo}
                <h2 class="org-view-naam">${esc(org.naam)}</h2>
            </div>
            <div class="org-view-tabs" role="tablist">
                <button type="button" class="org-view-tab actief" data-tab="agenda"
                        role="tab" aria-selected="true" onclick="switchOrgTab('agenda')">
                    <span class="org-view-tab-ico">📅</span>
                    <span data-i18n="org_tab_agenda">Agenda</span>
                </button>
                <button type="button" class="org-view-tab" data-tab="algemeen"
                        role="tab" aria-selected="false" onclick="switchOrgTab('algemeen')">
                    <span class="org-view-tab-ico">📁</span>
                    <span data-i18n="org_tab_algemeen">Algemeen</span>
                </button>
                <button type="button" class="org-view-tab" data-tab="nieuws"
                        role="tab" aria-selected="false" onclick="switchOrgTab('nieuws')">
                    <span class="org-view-tab-ico">📰</span>
                    <span data-i18n="org_tab_nieuws">Nieuws</span>
                </button>
            </div>
            <div class="org-view-pane actief" id="org-view-pane-agenda" role="tabpanel">
                <div class="wmodal-seizoen-rij">
                    <label for="org-view-sel-seizoen" class="wmodal-seizoen-label" data-i18n="wmodal_seizoen">Seizoen</label>
                    <select id="org-view-sel-seizoen" class="wmodal-sel-seizoen"></select>
                </div>
                <div id="org-view-agenda-lijst" class="wmodal-wedstrijd-lijst">
                    <div class="wmodal-laden" data-i18n="opt_laden">Laden…</div>
                </div>
            </div>
            <div class="org-view-pane" id="org-view-pane-algemeen" role="tabpanel" hidden>
                <div class="org-view-placeholder">
                    <div class="wmodal-placeholder-ico">📁</div>
                    <p data-i18n="org_tab_algemeen_binnenkort">${esc(t('org_tab_algemeen_binnenkort'))}</p>
                    <p class="wmodal-placeholder-sub" data-i18n="org_tab_algemeen_binnenkort_sub">${esc(t('org_tab_algemeen_binnenkort_sub'))}</p>
                </div>
            </div>
            <div class="org-view-pane" id="org-view-pane-nieuws" role="tabpanel" hidden>
                <div class="org-view-placeholder">
                    <div class="wmodal-placeholder-ico">📰</div>
                    <p data-i18n="org_tab_nieuws_binnenkort">${esc(t('org_tab_nieuws_binnenkort'))}</p>
                    <p class="wmodal-placeholder-sub" data-i18n="org_tab_nieuws_binnenkort_sub">${esc(t('org_tab_nieuws_binnenkort_sub'))}</p>
                </div>
            </div>
        </div>`;
    const terug = divResult.querySelector('.org-view-terug');
    if (terug) terug.addEventListener('click', _terugUitOrganisatieView);
    // De zojuist gebouwde data-i18n-elementen bevatten NL-fallbacktekst in de
    // span (de template is NL-geschreven). applyI18n vervangt die door de
    // actuele taal — nodig als deze view voor het eerst na een taalwissel
    // wordt getoond, want anders staan tab-labels in NL en de rest in EN/etc.
    if (typeof applyI18n === 'function') applyI18n(divResult);
    _laadOrgAgenda(org.id);
    divResult.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function switchOrgTab(tabId) {
    document.querySelectorAll('.org-view-tab').forEach(t => {
        const match = t.dataset.tab === tabId;
        t.classList.toggle('actief', match);
        t.setAttribute('aria-selected', match ? 'true' : 'false');
    });
    document.querySelectorAll('.org-view-pane').forEach(p => {
        const match = p.id === 'org-view-pane-' + tabId;
        p.hidden = !match;
        p.classList.toggle('actief', match);
    });
}

async function _laadOrgAgenda(orgId) {
    const lijst = document.getElementById('org-view-agenda-lijst');
    if (!lijst) return;
    try {
        if (!_orgWedstrijdenCache[orgId]) {
            // Stuur volglijst mee zodat de server per wedstrijd kan markeren
            // of een eigen rijder meedeed (voornaam-pil in UI). Zowel
            // person_ids als license_keys (legacy) via _volglijstEndpointParams.
            // Klikbaarheid hangt NIET af van eigen rijder — dat is door
            // operator geregeld via public_zichtbaar; eigen-rijder is puur
            // een visuele hint "jij hebt hier gereden".
            const vl  = _volglijstEndpointParams(_loadKidsUitStorage());
            const url = '?action=org_wedstrijden&org_id=' + encodeURIComponent(orgId) + vl.qs;
            const res  = await safeFetch(url);
            const data = await res.json();
            if (!res.ok || !Array.isArray(data)) {
                const msg = (data && data.error) ? data.error : 'HTTP ' + res.status;
                throw new Error(msg);
            }
            _orgWedstrijdenCache[orgId] = data;
        }
        _vulOrgSeizoenSelector(_orgWedstrijdenCache[orgId]);
        _renderOrgAgenda(orgId);
    } catch (e) {
        lijst.innerHTML = `<div class="wmodal-geen-wedstrijden">${esc(t('msg_fout_laden'))}: ${esc(e.message || e)}</div>`;
    }
}

function _vulOrgSeizoenSelector(comps) {
    const sel = document.getElementById('org-view-sel-seizoen');
    if (!sel) return;
    const jaren = new Set();
    const nu    = new Date().getFullYear();
    jaren.add(nu);
    for (const c of comps) {
        const d = safeDatum(c.starts);
        if (d) jaren.add(d.getFullYear());
    }
    const gesort = [...jaren].sort((a, b) => b - a);
    sel.innerHTML = gesort.map(j => `<option value="${j}">${j}</option>`).join('');
    if (_orgViewSeizoen == null || !jaren.has(_orgViewSeizoen)) _orgViewSeizoen = nu;
    sel.value = _orgViewSeizoen;
    sel.onchange = () => {
        _orgViewSeizoen = parseInt(sel.value, 10) || nu;
        _renderOrgAgenda(sel.closest('.org-view').dataset.orgId);
    };
}

function _renderOrgAgenda(orgId) {
    const lijst = document.getElementById('org-view-agenda-lijst');
    const comps = _orgWedstrijdenCache[orgId] || [];
    if (!lijst) return;
    // Volglijst → Map(person_id → kid-record) voor pil-naam-resolve in
    // _orgAgendaKaartHtml. Vers maken bij elke render — volglijst kan intussen
    // zijn gewijzigd en de cache-reset in _saveKids triggert deze render alsnog.
    _eigenRijdersMap = new Map(
        _loadKidsUitStorage().filter(k => k.person_id).map(k => [k.person_id, k])
    );
    const nu       = new Date();
    const vandaag  = new Date(nu.getFullYear(), nu.getMonth(), nu.getDate());
    const seizoen  = _orgViewSeizoen || vandaag.getFullYear();
    const recentIds = _recentBekekenGet();

    const inSeizoen = comps.filter(c => {
        const d = safeDatum(c.starts);
        return d && d.getFullYear() === seizoen;
    });

    const groepen = { recent: [], komende: [], verleden: [] };
    for (const c of inSeizoen) {
        const d = safeDatum(c.starts);
        if (!d) { groepen.verleden.push(c); continue; }
        if (d >= vandaag) groepen.komende.push(c);
        else              groepen.verleden.push(c);
    }
    // Recent-bekeken alleen voor huidig seizoen tonen; snijd uit op de ids.
    if (seizoen === vandaag.getFullYear()) {
        for (const id of recentIds) {
            const c = inSeizoen.find(x => x.id === id);
            if (c) groepen.recent.push(c);
        }
    }
    // Komende: chronologisch oplopend. Verleden: nieuwste eerst.
    groepen.komende.sort((a, b) => (safeDatum(a.starts)?.getTime() ?? 0) - (safeDatum(b.starts)?.getTime() ?? 0));
    groepen.verleden.sort((a, b) => (safeDatum(b.starts)?.getTime() ?? 0) - (safeDatum(a.starts)?.getTime() ?? 0));

    const htmlStukken = [];
    const toonGroep = (titelKey, lijstC) => {
        if (!lijstC.length) return;
        htmlStukken.push(`<div class="wmodal-periode-hdr">${esc(t(titelKey))}</div>`);
        htmlStukken.push(...lijstC.map(_orgAgendaKaartHtml));
    };
    toonGroep('wmodal_recent_bekeken', groepen.recent);
    toonGroep('org_agenda_komende',     groepen.komende);
    toonGroep('org_agenda_verleden',    groepen.verleden);
    if (!htmlStukken.length) {
        htmlStukken.push(`<div class="wmodal-geen-wedstrijden">${esc(t('wmodal_geen_wedstrijden'))}</div>`);
    }
    lijst.innerHTML = htmlStukken.join('');
    lijst.querySelectorAll('.wmodal-kaart:not(.disabled)').forEach(el => {
        el.addEventListener('click', () => _kiesWedstrijdUitOrgView(el.dataset.compId));
    });
}

function _orgAgendaKaartHtml(c) {
    const d = safeDatum(c.starts);
    const dag = d ? d.getDate() : '?';
    const mnd = d ? _mndKort(d) : '';
    const plaats = c.baan_vereniging ? esc(c.baan_vereniging) : '';
    const label = _wedstrijdLabel(c);
    // Click-restrictie puur op basis van publieke zichtbaarheid: operator
    // heeft die bewust aan/uit gezet. Eigen-rijder-check doet NIET mee
    // (publieke wedstrijd is ook via de wedstrijd-tab bereikbaar voor
    // iedereen — hier blokkeren zou alleen de UX breken).
    const disabled = label.key !== 'publiek';
    const labelHtml = `<span class="org-wed-tag org-wed-tag--${label.key}">${esc(t(label.i18n))}</span>`;
    // Fase 5a-UX-flat (2026-10-03): voornaam-pillen van gevolgde rijders
    // verhuisd naar de hub Wedstrijden-tab. In de org-agenda houden we
    // ruimte over voor nog-te-komen org-tag-labels.
    return `
        <div class="wmodal-kaart${disabled ? ' disabled' : ''}" data-comp-id="${esc(c.id)}"${disabled ? '' : ' tabindex="0" role="button"'}>
            <div class="wmodal-datum">
                <div class="wmodal-datum-dag">${dag}</div>
                <div class="wmodal-datum-mnd">${esc(mnd)}</div>
            </div>
            <div class="wmodal-info">
                <div class="wmodal-naam">${esc(c.name)}</div>
                ${plaats ? `<div class="wmodal-plaats">${plaats}</div>` : ''}
                <div class="wmodal-tags">${labelHtml}</div>
            </div>
        </div>`;
}

// Label bepalen op basis van publiek_zichtbaar/aankondigen:
// - publiek:     zichtbaar=1 (klikbaar)
// - binnenkort:  zichtbaar=0 en aankondigen=1 (disabled)
// - verborgen:   zichtbaar=0 en aankondigen=0 (disabled)
function _wedstrijdLabel(c) {
    if (c.public_zichtbaar == 1)        return { key: 'publiek',    i18n: 'org_wed_tag_publiek' };
    if (c.public_aankondigen == 1)      return { key: 'binnenkort', i18n: 'org_wed_tag_binnenkort' };
    return                                     { key: 'verborgen',  i18n: 'org_wed_tag_verborgen' };
}

function _kiesWedstrijdUitOrgView(compId) {
    if (!compId) return;
    // Fase 5b-UX: klik vanuit org-agenda gaat niet direct naar programma,
    // maar naar een standalone wedstrijd-info-view met organisatie-
    // specifieke documenten + een "Open wedstrijd"-knop die alsnog naar
    // het programma schakelt. _wmodalAktieveOrg BLIJFT behouden zodat de
    // terug-knop in de info-view ons terugbrengt naar de org-agenda.
    const comps = _orgWedstrijdenCache[_wmodalAktieveOrg?.id] || [];
    const comp  = comps.find(c => c.id === compId);
    if (!comp) return;
    _toonWedstrijdInfoView(comp);
}

// Wedstrijd-info-view (fase 5b-content): standalone view met wedstrijd-
// specifieke documenten (infobulletin, flyer, programma-PDF, startlijst-PDF,
// uitslag-PDF, enz.) + een "Open wedstrijd"-knop die het reguliere
// programma/heats/rondes/uitslagen opent. Documenten komen later via een
// nieuwe DB-tabel; voor nu is dit een skeleton met placeholder.
let _aktieveWedstrijdInfo = null;   // bewaart comp-object voor taalwissel-rerender

function _toonWedstrijdInfoView(comp) {
    if (!divResult || !comp) return;
    _aktieveWedstrijdInfo = comp;
    _setViewState('org');   // zelfde zichtbaarheid als org-view: strip + hub uit
    const d       = safeDatum(comp.starts);
    const datum   = d ? `${d.getDate()} ${_mndKort(d)} ${d.getFullYear()}` : '';
    const plaats  = comp.baan_vereniging ? esc(comp.baan_vereniging) : '';
    divResult.innerHTML = `
        <div class="wi-view" data-comp-id="${esc(comp.id)}">
            <div class="org-view-header">
                <button class="org-view-terug wi-terug" type="button"
                        data-i18n-title="org_view_terug" title="${esc(t('org_view_terug'))}">&lsaquo;</button>
                <div class="wi-datum-blok">
                    <div class="wi-datum-dag">${d ? d.getDate() : '?'}</div>
                    <div class="wi-datum-mnd">${d ? esc(_mndKort(d)) : ''}</div>
                </div>
                <div class="wi-titel-blok">
                    <h2 class="wi-naam">${esc(comp.name)}</h2>
                    ${plaats ? `<div class="wi-plaats">${plaats}</div>` : ''}
                </div>
            </div>
            <div class="org-view-tabs" role="tablist">
                <button type="button" class="org-view-tab actief" data-tab="infobulletin"
                        role="tab" aria-selected="true" onclick="switchWiTab('infobulletin')">
                    <span class="org-view-tab-ico">📄</span>
                    <span data-i18n="wi_tab_infobulletin">Infobulletin</span>
                </button>
                <button type="button" class="org-view-tab" data-tab="flyer"
                        role="tab" aria-selected="false" onclick="switchWiTab('flyer')">
                    <span class="org-view-tab-ico">🖼</span>
                    <span data-i18n="wi_tab_flyer">Flyer</span>
                </button>
                <button type="button" class="org-view-tab" data-tab="vereniging"
                        role="tab" aria-selected="false" onclick="switchWiTab('vereniging')">
                    <span class="org-view-tab-ico">🛡️</span>
                    <span data-i18n="wi_tab_vereniging">Vereniging</span>
                </button>
            </div>
            <div class="org-view-pane actief" id="wi-pane-infobulletin" role="tabpanel">
                <div class="wi-placeholder">
                    <div class="wi-placeholder-ico">📄</div>
                    <p data-i18n="wi_infobulletin_binnenkort">${esc(t('wi_infobulletin_binnenkort'))}</p>
                    <p class="wi-placeholder-sub" data-i18n="wi_infobulletin_binnenkort_sub">${esc(t('wi_infobulletin_binnenkort_sub'))}</p>
                </div>
            </div>
            <div class="org-view-pane" id="wi-pane-flyer" role="tabpanel" hidden>
                <div class="wi-placeholder">
                    <div class="wi-placeholder-ico">🖼</div>
                    <p data-i18n="wi_flyer_binnenkort">${esc(t('wi_flyer_binnenkort'))}</p>
                    <p class="wi-placeholder-sub" data-i18n="wi_flyer_binnenkort_sub">${esc(t('wi_flyer_binnenkort_sub'))}</p>
                </div>
            </div>
            <div class="org-view-pane" id="wi-pane-vereniging" role="tabpanel" hidden>
                <div class="wi-placeholder">
                    <div class="wi-placeholder-ico">🛡️</div>
                    <p data-i18n="wi_vereniging_binnenkort">${esc(t('wi_vereniging_binnenkort'))}</p>
                    <p class="wi-placeholder-sub" data-i18n="wi_vereniging_binnenkort_sub">${esc(t('wi_vereniging_binnenkort_sub'))}</p>
                </div>
            </div>
            <button class="wi-open-wedstrijd" type="button" data-i18n="wi_open_wedstrijd">${esc(t('wi_open_wedstrijd'))}</button>
        </div>`;
    const terug = divResult.querySelector('.wi-terug');
    if (terug) terug.addEventListener('click', _terugUitWedstrijdInfo);
    const open  = divResult.querySelector('.wi-open-wedstrijd');
    if (open)  open.addEventListener('click', () => {
        _wmodalAktieveOrg = null;        // user verlaat org-context bewust
        _aktieveWedstrijdInfo = null;
        _activeerWedstrijd(comp.id);
    });
    if (typeof applyI18n === 'function') applyI18n(divResult);
    divResult.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function switchWiTab(tabId) {
    document.querySelectorAll('.wi-view .org-view-tab').forEach(t => {
        const match = t.dataset.tab === tabId;
        t.classList.toggle('actief', match);
        t.setAttribute('aria-selected', match ? 'true' : 'false');
    });
    document.querySelectorAll('.wi-view .org-view-pane').forEach(p => {
        const match = p.id === 'wi-pane-' + tabId;
        p.hidden = !match;
        p.classList.toggle('actief', match);
    });
}

function _terugUitWedstrijdInfo() {
    _aktieveWedstrijdInfo = null;
    if (_wmodalAktieveOrg) {
        _toonOrganisatieView(_wmodalAktieveOrg);
    } else {
        toonHubView('organisaties');
    }
}

function _terugUitOrganisatieView() {
    _wmodalAktieveOrg = null;
    divResult.innerHTML = '';
    // Terug naar hub (hoofdview) op Organisaties-tab — niet terug naar
    // wedstrijd-view: Geert's UX-flat (fase 5a-UX-flat).
    toonHubView('organisaties');
}

// ── Einde Organisaties-tab ────────────────────────────────────────────────

// Bij het maximum aantal rijders: zoekveld + Zoeken uit + uitleg-hint, zodat
// duidelijk is dat je eerst een rijder moet verwijderen (via de chips hierboven).
function _updateSetupModalMax() {
    const vol = _loadKidsUitStorage().length >= MAX_KINDEREN;   // globale volglijst
    // Bij max: verberg de hele rijder-zoekstap (label + veld) én de Zoeken-knop,
    // en toon de hint op díé plek — i.p.v. een grijs, ogenschijnlijk bruikbaar veld.
    const stap = document.getElementById('stap-rijder');
    if (stap)   stap.style.display = vol ? 'none' : '';
    if (inpSnr) inpSnr.disabled = vol;
    if (btnZoek) { btnZoek.style.display = vol ? 'none' : ''; if (vol) btnZoek.disabled = true; }
    const hint = document.getElementById('setup-max-hint');
    if (hint) {
        hint.hidden = !vol;
        if (vol) hint.textContent = t('zoek_max_hint', { max: MAX_KINDEREN });
    }
}
// "Je gevolgde rijders" in de setup-modal: chips met verwijder-×. Hier gebeurt
// het verwijderen (weggehaald uit de tabs, die waren te krap op smal scherm).
function _renderSetupVolglijst() {
    if (typeof _ppRender === 'function') _ppRender();   // push-blok mee verversen
    const el = document.getElementById('setup-volglijst');
    if (!el) return;
    // Bron = de OPGESLAGEN (globale) volglijst, niet _kinderen. _kinderen is de
    // per-wedstrijd-subset (nog leeg vóór wedstrijdkeuze); de opgeslagen lijst
    // kennen we synchroon uit localStorage, dus ook bij vers openen klopt 't.
    const saved = _loadKidsUitStorage();
    if (!saved.length) { el.innerHTML = ''; return; }
    const chips = saved.map(k => {
        // Live-gegevens (startnummer) als deze rijder in de huidige wedstrijd
        // geladen is; anders de opgeslagen naam-hint.
        // Identiteit prefereert person_id (fase 3c), valt terug op license_key.
        const kid = k.person_id || k.license_key;
        const live = _kinderen.find(x => {
            const xp = x.data?.[x.kozen_idx ?? 0]?.persoon;
            return (k.person_id && xp?.person_id === k.person_id) || (k.license_key && xp?.license_key === k.license_key);
        });
        const p = live?.data?.[live.kozen_idx ?? 0]?.persoon;
        const naam = p?.full_name || k.naam_hint || t('kind_rijder_placeholder');
        const snr = live ? live.snr : '';
        return `<span class="setup-volg-chip">
            ${snr ? `<span class="setup-volg-snr">${esc(snr)}</span>` : ''}
            <span class="setup-volg-naam">${esc(naam)}</span>
            <button type="button" class="setup-volg-x" data-kid="${esc(kid)}" title="${esc(t('kind_tab_verwijder'))}">&times;</button>
        </span>`;
    }).join('');
    el.innerHTML = `<div class="setup-volg-label">${esc(t('setup_volg_label'))}</div>
        <div class="setup-volg-chips">${chips}</div>`;
    el.querySelectorAll('.setup-volg-x').forEach(b => b.addEventListener('click', () => {
        _verwijderGevolgdeRijder(b.dataset.kid);
        _renderSetupVolglijst();   // modal-lijst meteen verversen
        _updateSetupModalMax();    // zoekveld weer aan als onder max
    }));
}
// Verwijder een rijder uit de globale volglijst (localStorage) én uit de live
// per-wedstrijd-lijst als 'ie daar geladen is (dan hoofdweergave verversen).
function _verwijderGevolgdeRijder(kid) {
    // kid = person_id (fase 3c) óf license_key (oude items). Verwijder de rij
    // die op één van beide matcht.
    localStorage.setItem(KIDS_LS_KEY,
        JSON.stringify(_loadKidsUitStorage().filter(k => k.person_id !== kid && k.license_key !== kid)));
    if (typeof _ppSync === 'function') _ppSync();   // server-licenties meelopen

    const idx = _kinderen.findIndex(x => {
        const xp = x.data?.[x.kozen_idx ?? 0]?.persoon;
        return xp?.person_id === kid || xp?.license_key === kid;
    });
    if (idx !== -1) {
        _kinderen.splice(idx, 1);
        if (_activeKindIdx >= _kinderen.length) _activeKindIdx = Math.max(0, _kinderen.length - 1);
        renderKinderen();   // tabs/hoofdweergave bijwerken (schrijft de opgeslagen lijst niet terug)
    }
}
function closeSetupModal() {
    const m = document.getElementById('setup-modal');
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
}
// Update de strook met de huidige wedstrijd-naam + baan-logo (als beschikbaar).
// Styling matcht .org-view-header: logo (optioneel) + naam. Datum en rijder-
// samenvatting zitten niet meer in de strip — die info staat al elders
// (hub-kaart heeft datum, kids-chips hebben de rijdernaam).
function updateSetupStrip() {
    const el   = document.getElementById('setup-strip-tekst');
    const logo = document.getElementById('setup-strip-logo');
    if (!el) return;
    const opt      = selComp.selectedOptions[0];
    const compNaam = opt?.dataset?.naam || '';
    const baanLogo = opt?.dataset?.baanLogo || '';
    if (logo) {
        if (compNaam && baanLogo) {
            logo.src    = '../' + baanLogo;
            logo.alt    = opt?.dataset?.baanVereniging || '';
            logo.hidden = false;
        } else {
            logo.hidden = true;
            logo.removeAttribute('src');
        }
    }
    if (compNaam) {
        el.innerHTML = `<b>${esc(compNaam)}</b>`;
    } else {
        el.innerHTML = `<span class="setup-strip-empty">${esc(t('setup_strip_leeg'))}</span>`;
    }
}
// Escape-key sluit de modal (accessibility + snelheid).
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const m = document.getElementById('setup-modal');
        if (m && m.classList.contains('open')) closeSetupModal();
    }
});
// Fase 5a (2026-10-01): auto-open van modal bij first-of-day verwijderd op
// verzoek. De setup-strip toont bij geen-wedstrijd standaard "Kies je
// wedstrijd…" naast het pennetje — dat is signaal genoeg. Modal opent nu
// alleen op expliciete gebruikers-actie (klik op strip of pennetje).

function klapGroepPub(hdrEl) {
    const groep = hdrEl.closest('.prog-groep');
    if (!groep) return;
    // Samenvat-modus: klikken doet niks (heat-lijst is niet beschikbaar
    // in deze weergave, zie applyProgFilter).
    if (groep.classList.contains('samenvat')) return;
    const key = groep.dataset.groepKey;
    const nuIngeklapt = groep.classList.toggle('ingeklapt');
    if (nuIngeklapt) _progIngeklaptPub.add(key); else _progIngeklaptPub.delete(key);
    // Individuele klik → geen actieve knop in de klap-balk meer (state
    // matcht niet meer bij één van de drie preset-acties).
    const tab = hdrEl.closest('.tab-content');
    if (tab) {
        const balk = tab.querySelector('.prog-klap-balk');
        if (balk) {
            balk.dataset.actief = '';
            balk.querySelectorAll('.prog-klap-btn').forEach(b => b.classList.remove('actief'));
        }
    }
}

function klapProgPub(btnEl, actie) {
    const tab = btnEl.closest('.tab-content');
    if (!tab) return;
    _progIngeklaptPub.clear();
    if (actie === 'in') {
        _progAlleKeysPub.forEach(k => _progIngeklaptPub.add(k));
    } else if (actie === 'mijn') {
        _progAlleKeysPub.forEach(k => {
            if (!_progGroepenMetMijnPub.has(k)) _progIngeklaptPub.add(k);
        });
    }
    tab.querySelectorAll('.prog-groep').forEach(el => {
        el.classList.toggle('ingeklapt', _progIngeklaptPub.has(el.dataset.groepKey));
    });
    // Actieve knop bijwerken zodat je meteen ziet welke actie geldt.
    const balk = btnEl.closest('.prog-klap-balk');
    if (balk) {
        balk.dataset.actief = actie;
        balk.querySelectorAll('.prog-klap-btn').forEach(b =>
            b.classList.toggle('actief', b.dataset.actie === actie));
    }
}

// ── Persistente kind-lijst (GLOBAAL, niet per wedstrijd) ─────────────────────
// We bewaren `license_key` i.p.v. startnummer, zodat een kind dat in een
// volgende wedstrijd een ander startnummer krijgt toch automatisch wordt
// gevonden. Ook kinderen die in een wedstrijd niet meedoen worden stil
// overgeslagen — zonder dat de ouder ze moet afvinken.
const KIDS_LS_KEY = 'public_kinderen_licenses';
function _saveKids() {
    const seen = new Set();
    const items = _kinderen
        .map(k => {
            const p = k.data[k.kozen_idx ?? 0]?.persoon;
            // person_id (interne GUID) is sinds fase 3c de stabiele sleutel.
            // license_key blijft meegeschreven zolang die nog bestaat (fase 4
            // laat 'm vervallen); dedup en lookup prefereren person_id.
            if (!p?.license_key && !p?.person_id && !p?.volg_token) return null;
            // volg = geheim token; nodig om een anonieme rijder bij herladen weer
            // te ontsluiten (person_id ontsluit de naam niet).
            return { person_id: p.person_id ?? null, license_key: p.license_key ?? null,
                     volg: p.volg_token ?? null, naam_hint: p.full_name };
        })
        .filter(Boolean)
        // Dedup op person_id (of license_key als GUID nog ontbreekt): vangnet
        // zodat een (ooit) dubbele _kinderen nooit dubbel in localStorage belandt.
        .filter(it => { const key = it.person_id || it.license_key; return !seen.has(key) && seen.add(key); });
    localStorage.setItem(KIDS_LS_KEY, JSON.stringify(items));
    if (typeof _ppSync === 'function') _ppSync();   // server-licenties meelopen
    // Volglijst gewijzigd → org-caches dumpen zodat de volgende Organisaties-
    // tab/agenda-opening de nieuwe set (en bijbehorende startnummer-pillen)
    // ophaalt. Scheelt het "oude pil blijft hangen"-effect.
    if (typeof _invalideerWedstrijdModalCaches === 'function') _invalideerWedstrijdModalCaches();
}
function _loadKidsUitStorage() {
    try { return JSON.parse(localStorage.getItem(KIDS_LS_KEY) || '[]'); }
    catch { return []; }
}

// Haal lookup op voor een license_key of startnummer. Gebruikt de shared
// programma-respons als die al gefetcht is (scheelt netwerk-calls bij
// meerdere kinderen).
async function _fetchKind({ person_id = null, license_key = null, snr = null, volg = null }, compId, gedeeldeProg = null) {
    if (!person_id && !license_key && !snr && !volg) return null;
    // Volg-token eerst (enige sleutel die een anonieme rijder ontsluit), daarna
    // de stabiele person_id, dan license_key (oude items), tot slot startnummer.
    // Volg-token én startnummer via POST (plan URL/log-reductie 2026-10-02);
    // person_id/license_key blijven GET.
    const lookupUrl = `?action=lookup&competition_id=${encodeURIComponent(compId)}`;
    const bodyParam = volg ? { volg }
                    : (!person_id && !license_key && snr) ? { startnummer: snr }
                    : null;
    const lookupPromise = bodyParam
        ? safeFetch(lookupUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(bodyParam),
          })
        : safeFetch(lookupUrl + `&person_id=${encodeURIComponent(person_id || license_key)}`);
    const [lookupRes, progRes] = await Promise.all([
        lookupPromise,
        gedeeldeProg
            ? Promise.resolve({ json: async () => gedeeldeProg })
            : safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`),
    ]);
    const data = await lookupRes.json();
    const prog = await progRes.json();
    if (data.error || !data.length) {
        // Zochten we via een volg-token en bestaat dat niet (meer)? Dan is het
        // ingetrokken/vernieuwd door de rijder → follow verbroken → pruimen
        // (__anoniem-sentinel). Bij person_id/snr betekent leeg gewoon "doet niet
        // mee aan deze wedstrijd" → behouden (kan een andere wedstrijd rijden).
        return volg ? { __anoniem: true } : null;
    }
    // Rijder is nu anoniem én we hebben geen geldig volg-token (meer) → signaleer
    // dit apart (niet null = "doet niet mee"), zodat de aanroeper 'm uit de
    // opgeslagen volglijst kan pruimen.
    if (data[0]?.persoon?.is_anoniem) return { __anoniem: true };
    // Pak huidige startnr uit de response (kan in nieuwe wedstrijd anders zijn).
    const p = data[0].persoon;
    const huidigSnr = p.wedstrijd_snr ?? p.start_number ?? snr ?? '';
    return { snr: String(huidigSnr), data, prog, sub_tab: 'programma', kozen_idx: 0 };
}

function toonRijderData(data, startIdx, snr, prog) {
    // Dedupeer op person_id (stabiel over wedstrijden, fase 3c), valt terug op
    // license_key. Niet op startnummer (dat wisselt per wedstrijd).
    const nieuweP = data[startIdx]?.persoon;
    const nieuwePid = nieuweP?.person_id;
    const nieuweLic = nieuweP?.license_key;
    const bestaande = (nieuwePid || nieuweLic)
        ? _kinderen.findIndex(k => {
            const kp = k.data[k.kozen_idx ?? 0]?.persoon;
            return (nieuwePid && kp?.person_id === nieuwePid) || (nieuweLic && kp?.license_key === nieuweLic);
          })
        : -1;
    if (bestaande !== -1) {
        _activeKindIdx = bestaande;
        _kinderen[bestaande].data = data;
        _kinderen[bestaande].prog = prog;
        _kinderen[bestaande].kozen_idx = startIdx;
        _kinderen[bestaande].snr = String(snr);
    } else {
        if (_kinderen.length >= MAX_KINDEREN) {
            alert(t('alert_max_bereikt', {max: MAX_KINDEREN}));
            return;
        }
        _kinderen.push({ snr: String(snr), data, prog, sub_tab: 'programma', kozen_idx: startIdx });
        _activeKindIdx = _kinderen.length - 1;
    }
    _saveKids();
    renderKinderen();
}

// Render de complete multi-rijder-weergave: kind-tabs bovenop, met daaronder
// de persoon-kaart van het actieve kind.
function renderKinderen() {
    // Setup-strip volgt de _kinderen-state — ook bij lege lijst updaten.
    updateSetupStrip();
    // Safety-net: als er een actieve wedstrijd + rijders is, moeten we in
    // wedstrijd-view zijn (strip + resultaat zichtbaar). Zonder deze call
    // kan de setup-strip hidden blijven na een race-conditie (bv. een
    // snelle sequence van hub/org-switches gevolgd door een wedstrijd-
    // load) — dan ziet de user content zonder terug-knop.
    if (_kinderen.length && selComp.value) _setViewState('wedstrijd');
    if (!_kinderen.length) { divResult.innerHTML = ''; return; }
    // Bij render van een rijder-tab: reset klap-state naar default-collapsed.
    _progIngeklaptPub.clear();
    _progGroepenMetMijnPub.clear();
    _progEersteRenderPub = true;
    // Na succesvolle rijder-load: modal dicht als 'ie nog openstond.
    // Timeout laat de UI-transitie een tik ademen voor de sluit-animatie.
    setTimeout(() => {
        const m = document.getElementById('setup-modal');
        if (m && m.classList.contains('open')) closeSetupModal();
    }, 50);

    // Top-tabs: één knop per kind + "+ voeg toe" rechts
    const tabsHtml = _kinderen.map((k, idx) => {
        const p = k.data[k.kozen_idx ?? 0]?.persoon;
        const naam = p?.full_name ? p.full_name.split(' ')[0] : ''; // alleen voornaam in tab — kort
        const actief = idx === _activeKindIdx ? ' active' : '';
        // Geen ×-knop meer in de tab — die werd te krap op smalle telefoons bij
        // 3-4 kinderen (× viel weg / actieve tab klapte in). Verwijderen gaat nu
        // via de + / setup-modal onder "Je gevolgde rijders" (zoals de coach-app).
        return `<button class="kind-tab${actief}" data-kind-idx="${idx}">
            <span class="kind-tab-snr" data-len="${String(k.snr ?? '').length}">${esc(k.snr)}</span>
            <span>${esc(naam || t('kind_rijder_placeholder'))}</span>
        </button>`;
    }).join('');
    // Bij 3+ kinderen wordt het tabblad krap op telefoon-breedte. CSS
    // gebruikt data-count om dan compactere stijl toe te passen (voornaam
    // weg, kleinere padding) — de × moet altijd zichtbaar blijven.
    // + altijd klikbaar: opent de modal om rijders te beheren (toevoegen én
    // verwijderen). Bij max kun je zo alsnog iemand verwijderen; de titel legt
    // uit dat toevoegen pas kan na een verwijdering.
    const plusVol = _loadKidsUitStorage().length >= MAX_KINDEREN;
    const plusKnop = `<button class="kind-tab-plus" id="kind-tab-plus" title="${esc(plusVol ? t('kind_plus_max', {max: MAX_KINDEREN}) : t('kind_plus_title'))}">+</button>`;

    divResult.innerHTML = `
        <div class="kind-tabs" data-count="${_kinderen.length}">${tabsHtml}${plusKnop}</div>
        <div id="kind-content"></div>`;

    // Click-handlers op kind-tabs
    divResult.querySelectorAll('.kind-tab').forEach(btn => {
        btn.addEventListener('click', () => wisselKind(parseInt(btn.dataset.kindIdx)));
    });
    const plusEl = document.getElementById('kind-tab-plus');
    if (plusEl) plusEl.addEventListener('click', () => {
        // Setup-modal open. GEEN auto-focus op het zoekveld: op mobiel klapt dan
        // meteen het toetsenbord op en dat duwt de modal (incl. "Je gevolgde
        // rijders") weg. Toetsenbord verschijnt pas als de bediener zelf in het
        // veld tikt.
        inpSnr.value = '';
        btnZoek.disabled = true;
        openSetupModal();
    });

    // Content van actieve kind renderen
    const k = _kinderen[_activeKindIdx];
    if (!k) return;
    const subset = [k.data[k.kozen_idx ?? 0]];
    renderResultaat(subset, k.snr, k.prog);

    // Programma-UI-state herstellen: eigen state bij terugkeer naar deze
    // rijder, of de state van de vorige rijder als "poging" bij eerste
    // bezoek. Gezet door wisselKind() vóór renderKinderen().
    if (_pendingProgRestore) {
        _restoreProgUiStatePub(_pendingProgRestore);
        _pendingProgRestore = null;
    }

    // Onthouden sub-tab herstellen (als niet 'programma')
    if (k.sub_tab && k.sub_tab !== 'programma') {
        const subBtn = document.querySelector(`#kind-content .tab-btn[data-tab="${k.sub_tab}"]`);
        if (subBtn) subBtn.click();
    }
}

// Bewaar UI-state (huidige sub-tab + dropdown-keuzes binnen Uitslagen) van
// het actief getoonde kind. Wordt aangeroepen vóór elk renderKinderen() zodat
// na her-render de juiste keuzes hersteld kunnen worden. Zonder dit verloor
// de gebruiker elke 60s (auto-refresh) zijn categorie/afstand-selectie in de
// Uitslagen-tab — _kaartwissel_ deed hetzelfde. Restore gebeurt in
// initUitslagenTab() / het serie-klassement-blok.
function _bewaarKindUistate() {
    const k = _kinderen[_activeKindIdx];
    if (!k) return;
    const kc = document.getElementById('kind-content');
    if (!kc) return;
    // Sub-tab (welke tab is op dit moment open?)
    const huidigeSub = kc.querySelector('.tab-btn.active')?.dataset.tab;
    if (huidigeSub) k.sub_tab = huidigeSub;
    // Dropdown-keuzes binnen Uitslagen-tab
    const uitslPane = kc.querySelector('.tab-content[data-tab="uitslagen"]');
    if (uitslPane) {
        k._uistate = {
            catVal:      uitslPane.querySelector('.uitsl-cat-sel')?.value      || '',
            distVal:     uitslPane.querySelector('.uitsl-dist-sel')?.value     || '',
            serieVal:    uitslPane.querySelector('.serie-sel')?.value          || '',
            serieCatVal: uitslPane.querySelector('.serie-cat-sel')?.value      || '',
        };
    }
}

function wisselKind(idx) {
    if (idx < 0 || idx >= _kinderen.length) return;
    // Huidige sub-tab + dropdown-keuzes onthouden voordat we wisselen
    _bewaarKindUistate();
    // Programma-UI-state van OUD-actieve kind snapshotten voor terugkeer.
    const oudKey    = _kindKey(_kinderen[_activeKindIdx]);
    const oudeStaat = _snapshotProgUiStatePub();
    if (oudKey && oudeStaat) _progUiStatePerKind.set(oudKey, oudeStaat);
    _activeKindIdx = idx;
    // Restore-doel voor renderKinderen(): eigen state van NIEUW-actieve
    // kind als bekend, anders de state van oud-kind als "poging" (afstand
    // die niet bij nieuw-kind past valt automatisch terug op alle).
    _pendingProgRestore = _progUiStatePerKind.get(_kindKey(_kinderen[idx])) || oudeStaat;
    renderKinderen();
}

function verwijderKind(idx) {
    if (idx < 0 || idx >= _kinderen.length) return;
    // Bewaarde programma-UI-state van weggehaalde kind opruimen.
    _progUiStatePerKind.delete(_kindKey(_kinderen[idx]));
    _kinderen.splice(idx, 1);
    if (_activeKindIdx >= _kinderen.length) _activeKindIdx = Math.max(0, _kinderen.length - 1);
    _saveKids();
    if (_kinderen.length === 0) {
        divResult.innerHTML = '';
    } else {
        renderKinderen();
    }
}

// Status-per-DC voor de klikbare "klik voor status"-badge (alleen gevuld als
// de statussen per DC verschillen). Key = license_key.
let _dcStatusMap = {};
// Status-index → kleur-class (spiegelt STATUS_KLEUR/STATUS_BG). Niet-ingeschreven
// of onbekend → pstat-ni.
function _pstatClass(st, nietIngeschreven) {
    if (nietIngeschreven) return 'pstat-ni';
    const s = parseInt(st);
    return (s >= 0 && s <= 5) ? ('pstat-' + s) : 'pstat-ni';
}
function toonStatusModal(license) {
    const info = _dcStatusMap[license];
    if (!info) return;
    const rows = (info.dc || []).map(x => {
        const s   = parseInt(x.status);
        const lbl = (s >= 0 && s <= 5 ? getStatusLabel(s) : t('status_onbekend'));
        return `<tr><td>${esc(x.dc_naam)}</td><td><span class="persoon-status ${_pstatClass(s, false)}">${esc(lbl)}</span></td></tr>`;
    }).join('');
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = `<div class="overlay-box">
        <div class="heat-card-titel">
            <button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>
            ${esc(info.naam)} &middot; ${esc(t('status_per_afstand'))}
        </div>
        <div class="status-modal-body">
            <table class="status-modal-tabel">${rows}</table>
        </div>
    </div>`;
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
}

