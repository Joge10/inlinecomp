/* InlineComp – Live verwerking */

// ── Globale state ──────────────────────────────────────────────────────────────

let _liveRitten      = [];      // alle ritten geladen van API
let _liveCatConfigs  = {};      // catConfigs van API
let _liveSysteem     = null;    // tijdschema-systeem ('full-final' | 'internationaal-nieuw' | ...)
let _liveHuidigIdx   = -1;      // huidige carousel-index (-1 = nog niet gezet)
let _liveOngeslagen  = false;   // onopgeslagen wijzigingen
let _liveLeesOnly    = false;   // geen schrijfrechten

// Filter voor de heat-dropdown (○ / ◑ / ✓). Standaard alles aan.
let _liveFilter = { geen_lijst: true, geen_resultaat: true, deels: true, compleet: true };

// Afvalkoers-state per rit (key = ritIdx).
//   afgevallen      : [{entry_id, plek, sanctie}] gesetste afvallingen, volgorde van toevoeging.
//   voorlopig_2de   : [entry_id, ...] geselecteerde "2de"-rijders nog niet-geset.
//   voorlopig_1ste  : [entry_id, ...] geselecteerde "1ste"-rijders nog niet-geset.
//   geselecteerd    : [entry_id, ...] huidige startnummer-selectie.
// Correcties: klik op een afgevallen-kaart zet rijder terug in koers.
let _afvalState = {};

// ── Entry point ───────────────────────────────────────────────────────────────

async function toonLivePagina() {
    const container = el('live-inhoud');
    if (!container) return;

    vulPaginaHeader('live-comp-naam', 'live-comp-meta');

    _liveLeesOnly = !magSchrijven('live');

    if (!huidigCompId) {
        container.innerHTML = '<div class="status-msg info">Selecteer eerst een wedstrijd via <strong>Importeer</strong>.</div>';
        return;
    }

    container.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';

    try {
        const res  = await fetch('api/live.php?competition_id=' + encodeURIComponent(huidigCompId));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        _liveRitten     = data.ritten     || [];
        _liveCatConfigs = data.catConfigs || {};
        _liveSysteem    = data.systeem    || null;
        _liveOngeslagen = false;

        // Corrigeer finishposities voor PK-ritten op basis van punten→rondes→tid.
        // DB-waarden kunnen verkeerd zijn als ze met een oudere versie zijn opgeslagen.
        _liveRitten.forEach(_liveHerrekenPKFinishposities);

        if (_liveRitten.length === 0) {
            container.innerHTML = '<div class="status-msg info">Geen ritten gevonden. Genereer eerst een tijdschema met startlijsten.</div>';
            return;
        }

        // Bewaar huidige positie als die nog geldig is (terugkeer na module-wisseling),
        // anders: eerste onvoltooide rit, of rit 0
        const eersteOnvolledig = _liveRitten.findIndex(r => !_liveRitCompleet(r));
        if (_liveHuidigIdx < 0 || _liveHuidigIdx >= _liveRitten.length) {
            _liveHuidigIdx = eersteOnvolledig >= 0 ? eersteOnvolledig : 0;
        }

        _liveRenderCarousel();
        _liveInitKeyboard();

    } catch(e) {
        container.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

// Een rijder is "afgehandeld" als hij een tijd, een sanctie OF — bij afvalkoers —
// een afval_rang heeft (eliminatie zonder eindtijd telt ook als compleet).
function _liveRijderAfgehandeld(r) {
    return r.tijd_ms !== null
        || (r.sanctie && r.sanctie !== '')
        || (r.afval_rang != null);
}

function _liveRitCompleet(rit) {
    if (!rit.rijders || rit.rijders.length === 0) return false;
    return rit.rijders.every(_liveRijderAfgehandeld);
}

function _liveRitDeels(rit) {
    if (!rit.rijders || rit.rijders.length === 0) return false;
    const heeftIets = rit.rijders.some(_liveRijderAfgehandeld);
    return heeftIets && !_liveRitCompleet(rit);
}

function _liveHasHeat(rit) {
    return rit.heat_id !== null && rit.rijders && rit.rijders.length > 0;
}

// Herbereken finishposities voor een PK-rit: punten DESC → rondes DESC (null=Infinity) → tid ASC.
// Corrigeert evt. foute waarden uit de DB (opgeslagen met oudere versie zonder rondes-stap).
function _liveHerrekenPKFinishposities(rit) {
    if (rit.race_type !== 'puntenkoers') return;

    const puntenMap = new Map();
    rit.rijders.forEach(r => { if (r.punten != null) puntenMap.set(r.entry_id, r.punten); });

    // Alleen rijders met een geldige tijd
    const metTijd = rit.rijders.filter(r => r.tijd_ms !== null && r.tijd_ms > 0);

    metTijd.sort((a, b) => {
        const pA = puntenMap.get(a.entry_id) ?? 0;
        const pB = puntenMap.get(b.entry_id) ?? 0;
        if (pA !== pB) return pB - pA;                  // 1. punten DESC
        const rA = a.rondes ?? Infinity;
        const rB = b.rondes ?? Infinity;
        if (rA !== rB) return rB - rA;                   // 2. rondes DESC (null = best)
        return a.tijd_ms - b.tijd_ms;                    // 3. tid ASC
    });

    metTijd.forEach((r, i) => {
        const rider = rit.rijders.find(x => x.entry_id === r.entry_id);
        if (rider) rider.finishpositie = i + 1;
    });
}

// Tijdnotatie: ms → "M:SS.mmm" (bijv. 47321 → "0:47.321")
function _msTijdNaarDisplay(ms) {
    if (!ms) return '';   // null, undefined én 0 → leeg
    const msRest   = ms % 1000;
    const totSec   = Math.floor(ms / 1000);
    const seconden = totSec % 60;
    const minuten  = Math.floor(totSec / 60);
    return `${minuten}:${String(seconden).padStart(2,'0')}.${String(msRest).padStart(3,'0')}`;
}

// Invoer parseren → ms (of null bij lege invoer)
// Accepteert: "47.321" → 47321, "1:23.456" → 83456, "47" → 47000
// Werkt ook met 2 decimalen: "47.32" → 47320
function _parseTijdInvoer(str) {
    const s = str.trim();
    if (!s) return null;
    let minuten = 0, seconden = 0, milliseconden = 0;
    if (s.includes(':')) {
        const [minStr, rest] = s.split(':');
        minuten = parseInt(minStr) || 0;
        const [secStr, msStr] = (rest || '').split('.');
        seconden     = parseInt(secStr) || 0;
        milliseconden = parseInt((msStr || '').padEnd(3,'0').slice(0,3)) || 0;
    } else if (s.includes('.')) {
        const [secStr, msStr] = s.split('.');
        seconden     = parseInt(secStr) || 0;
        milliseconden = parseInt((msStr || '').padEnd(3,'0').slice(0,3)) || 0;
    } else {
        seconden = parseInt(s) || 0;
    }
    const ms = (minuten * 60 + seconden) * 1000 + milliseconden;
    return ms > 0 ? ms : null;
}

// Bereken finishposities voor een set entries
//
//  FS          → gewone positie op basis van tijd (enkel een waarschuwing)
//  DNF / DQ-SF → ex-aequo gedeeld laatste = N + 1
//  DQ-DF / DNS → géén positie, niet in de uitslag
//  Geen tijd en geen sanctie → niet in de map (nog niet ingevuld)
//
// entries: [{ entry_id, tijd_ms, sanctie }]
// Returns: Map  entry_id → positie
const _SANCTIE_RANKED_LAST = new Set(['DNF', 'DQ-TF', 'DNS']);
const _SANCTIE_NOT_RANKED  = new Set(['DQ-SF', 'DQ-DF']);
const _SANCTIE_WIST_TIJD   = new Set(['DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF', 'DNS']); // FS niet!

// Ex-aequo-ranking volgens reglement: gelijke tijden krijgen dezelfde positie
// (1,2,3,3,5). Aansluitend op api/_uitslag_helper.php::berekenExAequoRangs(),
// die de uitslag-laag gebruikt — beide systemen (full-final én internationaal)
// horen reglementair ex-aequo te krijgen bij 100% gelijke tijden.
// De parameter blijft staan voor terugcompat; aanroepers geven 'true' mee.
function _berekenPosities(entries, gebruikGelijkspel = true) {
    // Finishers: heeft tijd, niet ranked_last, niet not_ranked (FS wél meenemen op tijd)
    const finishers = entries
        .filter(e => e.tijd_ms > 0 && !_SANCTIE_RANKED_LAST.has(e.sanctie) && !_SANCTIE_NOT_RANKED.has(e.sanctie))
        .sort((a, b) => {
            // Lange-afstand: rondes DESC (meer ronden = betere positie), dan tijd ASC
            // null rondes = Infinity: rijder zonder geregistreerde rondes staat boven rijder met weinig rondes
            if (a.rondes != null || b.rondes != null) {
                const rA = a.rondes ?? Infinity;
                const rB = b.rondes ?? Infinity;
                if (rA !== rB) return rB - rA;
            }
            return a.tijd_ms - b.tijd_ms;
        });
    const rankedLast = entries.filter(e => _SANCTIE_RANKED_LAST.has(e.sanctie));
    // DQ-SF en DQ-DF worden genegeerd (geen positie)

    const posMap = new Map();
    if (gebruikGelijkspel) {
        // Ex-aequo: gelijke tijden krijgen dezelfde positie; volgende positie slaat over
        // bijv. 1, 2, 3, 4, 5, 5, 7  (twee rijders op positie 5, daarna positie 7)
        let rankPos = 1;
        for (let i = 0; i < finishers.length; ) {
            let j = i;
            while (j < finishers.length && finishers[j].tijd_ms === finishers[i].tijd_ms) j++;
            for (let k = i; k < j; k++) posMap.set(finishers[k].entry_id, rankPos);
            rankPos += (j - i); // sla de gebruikte posities over
            i = j;
        }
    } else {
        finishers.forEach((e, i) => posMap.set(e.entry_id, i + 1));
    }
    if (rankedLast.length > 0) {
        const laatste = finishers.length + 1;
        rankedLast.forEach(e => posMap.set(e.entry_id, laatste));
    }
    return posMap;
}

// Synchroniseer tijd+sanctie naar alle DOM-elementen met hetzelfde entry_id
// (zowel in het linker panel als in de carousel-kaart)
function _liveSyncInvoer(entryId, tijdVal, sanctieVal, rondesVal) {
    // Carousel-kaart: input-velden bijwerken (bewerkbaar).
    document.querySelectorAll(`[data-entry="${entryId}"]`).forEach(rij => {
        const t = rij.querySelector('.live-tijd-inp');
        const s = rij.querySelector('.live-sanctie-sel');
        const rn = rij.querySelector('.live-rondes-inp');
        if (t && t !== document.activeElement && t.value !== tijdVal) t.value = tijdVal;
        if (s && s !== document.activeElement && s.value !== sanctieVal) s.value = sanctieVal;
        if (rn && rn !== document.activeElement && rondesVal !== undefined) {
            const rv = rondesVal ?? '';
            if (rn.value !== String(rv)) rn.value = rv;
        }
    });
    // Panel: read-only tekst-cellen bijwerken.
    document.querySelectorAll(`[data-panel-entry="${entryId}"]`).forEach(rij => {
        const tTxt = rij.querySelector('.live-panel-tijd-txt');
        const sTxt = rij.querySelector('.live-panel-sanctie-txt');
        const rTxt = rij.querySelector('.live-panel-rondes-txt');
        if (tTxt) tTxt.textContent = tijdVal || '—';
        if (sTxt) sTxt.textContent = sanctieVal || '—';
        if (rTxt && rondesVal !== undefined) {
            rTxt.textContent = (rondesVal ?? '') === '' ? '—' : String(rondesVal);
        }
    });
}

// Berekent live finishposities op basis van huidige invoer in de DOM
function _liveHerbereken(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit || !rit.rijders) return;

    // Verzamel entries vanuit DOM (inclusief rondes voor lange-afstand heats)
    const entries = rit.rijders.map(r => {
        const rij    = document.querySelector(`[data-entry="${r.entry_id}"]`);
        const inp    = rij?.querySelector('.live-tijd-inp');
        const sel    = rij?.querySelector('.live-sanctie-sel');
        const rnInp  = rij?.querySelector('.live-rondes-inp');
        const rondes = rnInp ? (rnInp.value !== '' ? (parseInt(rnInp.value) || null) : null) : (r.rondes ?? null);
        return { entry_id: r.entry_id, tijd_ms: inp ? _parseTijdInvoer(inp.value) : null, sanctie: sel?.value || null, rondes };
    });

    const posMap        = _berekenPosities(entries, true);
    const isPuntenkoers = rit.race_type === 'puntenkoers';

    // Voor afvalkoers: ook rijders met afval_rang (via UI gemarkeerd) tellen
    // als 'compleet' voor de rij-kleur, zelfs zonder tijd of sanctie.
    const isAfvalkoers = rit.race_type === 'afvalkoers';
    const afvalIds = isAfvalkoers
        ? new Set((_afvalState[ritIdx]?.afgevallen || []).map(a => a.entry_id))
        : null;

    // Update finish-badges (dropdowns worden NIET overschreven — die zijn via wissel beheerd)
    // Bij puntenkoers: badges worden door _liveUpdatePuntenBadges beheerd, hier alleen rijkleur
    rit.rijders.forEach(r => {
        const rij = document.querySelector(`[data-entry="${r.entry_id}"]`);
        if (!rij) return;
        const badge = rij.querySelector('.live-finish-badge');
        const sel   = rij.querySelector('.live-sanctie-sel');
        const heeftSanctie = sel && sel.value !== '';
        const pos = posMap.get(r.entry_id);

        if (!isPuntenkoers && badge) {
            if (pos !== undefined) {
                badge.textContent = _ordinaal(pos);
                badge.className   = heeftSanctie ? 'live-finish-badge finish-pos-sanctie' : 'live-finish-badge finish-pos';
            } else {
                badge.textContent = '—';
                badge.className   = 'live-finish-badge';
            }
        }

        // Rijkleur — altijd bijwerken, ongeacht badge of dropdown
        const inp = rij.querySelector('.live-tijd-inp');
        const ms  = inp ? _parseTijdInvoer(inp.value) : null;
        const sanctieWaarde = sel?.value || '';
        const isAfgevallen  = afvalIds && afvalIds.has(r.entry_id);
        rij.classList.remove('live-rit-status-compleet', 'live-rit-status-sanctie', 'live-rit-status-leeg');
        if (sanctieWaarde === 'FS')   rij.classList.add(ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');
        else if (heeftSanctie)        rij.classList.add('live-rit-status-sanctie');
        else if (ms > 0)              rij.classList.add('live-rit-status-compleet');
        else if (isAfgevallen)        rij.classList.add('live-rit-status-compleet');
        else                          rij.classList.add('live-rit-status-leeg');
    });

    if (isPuntenkoers) _liveUpdatePuntenBadges(ritIdx);
}

function _ordinaal(n) {
    return n + 'e';
}

// ── Ronde-status berekening (voor volgende-ronde knop) ────────────────────────

function _liveRondeCompleet(dcId, distanceId, rondeType) {
    const rittenInRonde = _liveRitten.filter(r =>
        r.dc_id === dcId &&
        r.distance_id === distanceId &&
        r.ronde_type === rondeType
    );
    if (rittenInRonde.length === 0) return false;
    return rittenInRonde.every(r => _liveHasHeat(r) && _liveRitCompleet(r));
}


// Label voor de hergeneer-knop. Bij naar=finale_a in full-final genereren we
// in één klik óók de B-finales (zie api/live.php). Het label moet dat
// reflecteren: "A- en B-Finales" als er B-finale-ritten in het tijdschema
// staan voor deze dc+distance, anders gewoon "A-Finale".
function _liveHergeneerLabel(dcId, distanceId, naarRondeType) {
    const baseLabel = RONDE_LABEL[naarRondeType] || naarRondeType;
    if (naarRondeType !== 'finale_a') return baseLabel;
    const heeftB = (_liveRitten || []).some(r =>
        r.dc_id === dcId
        && String(r.distance_id || '') === String(distanceId || '')
        && r.ronde_type === 'finale_b'
    );
    return heeftB ? 'A- en B-Finales' : baseLabel;
}

function _volgendeRondeType(dcId, distanceId, vanRondeType) {
    const key = dcId + '|' + distanceId;
    const cc  = _liveCatConfigs[key];
    if (!cc) return null;
    if (vanRondeType === 'heats') {
        if (cc.heeft_kwartfinale) return 'kwartfinale';
        if (cc.heeft_halve_finale) return 'halve_finale';
        return 'finale_a';
    }
    if (vanRondeType === 'kwartfinale') {
        if (cc.heeft_halve_finale) return 'halve_finale';
        return 'finale_a';
    }
    if (vanRondeType === 'halve_finale') return 'finale_a';
    return null;
}

// Is de huidige ronde de eerste van de keten voor deze categorie?
// Eerste ronde = heats (als die er zijn), anders kwart, anders half.
// Alleen ná de eerste ronde komt de runner-up race in beeld: dat zijn
// immers de afvallers van die eerste ronde.
function _isEersteRondeKeten(cc, rondeType) {
    if (!cc) return false;
    if (cc.heeft_heats)         return rondeType === 'heats';
    if (cc.heeft_kwartfinale)   return rondeType === 'kwartfinale';
    if (cc.heeft_halve_finale)  return rondeType === 'halve_finale';
    return false;
}

const RONDE_LABEL = {
    heats:        'Series',
    kwartfinale:  'Kwartfinale',
    halve_finale: 'Halve finale',
    finale_a:     'A-Finale',
    finale_b:     'B-Finale',
    finale:       'Finale',
    runner_up:    'Runner-up',
};

// ── Carousel renderen ──────────────────────────────────────────────────────────

// Bouw HTML voor één carousel-kaart
function _liveBouwKaart(rit, idx, compact = false) {
    const rondeKls = `live-ronde-${(rit.ronde_type || 'heats').replace('_', '_')}`;
    const tijdstipHtml = rit.tijdstip
        ? `<span class="live-rit-tijdstip">${escHtml(rit.tijdstip.substring(0,5))}</span>` : '';
    const rondeBadge = `<span class="live-rit-rondebadge ${rondeKls}">${escHtml(RONDE_LABEL[rit.ronde_type] || rit.ronde_type)}</span>`;
    const aantalRijders = _liveHasHeat(rit) ? rit.rijders.length : (rit.verwacht || 0);

    // Combi-info: is deze rit deel van een combi-groep? Zo ja: positie in groep.
    let combiInfoHtml = '';
    if (rit.combi_group) {
        const groepLeden = _liveRitten.filter(x => x.combi_group === rit.combi_group)
                                      .sort((a, b) => (a.volgorde || 0) - (b.volgorde || 0));
        const mijnPos = groepLeden.findIndex(x => x.rit_id === rit.rit_id) + 1;
        combiInfoHtml = `<span class="heat-combi-badge" title="Deze rit is gecombineerd met ${groepLeden.length - 1} andere rit(ten) in het programma">🔗 ${mijnPos}/${groepLeden.length}</span>`;
    }

    // Titel — dezelfde opbouw als heat-card in startlist.js
    const titelHtml =
        `<div class="heat-titel">` +
        `<span class="heat-ritnr">${rit.volgorde ?? (idx + 1)}</span>` +
        tijdstipHtml +
        escHtml(rit.rit_naam) +
        rondeBadge +
        combiInfoHtml +
        `<span class="heat-count">${aantalRijders}</span>` +
        `</div>`;

    let tabelHtml = '';
    if (_liveHasHeat(rit)) {
        // Kaart met echte startlijst

        // Race-type komt uit distances.race_type (canonieke bron via API).
        // - sprint      → geen rondes, geen punten, geen selector
        // - inline      → rondes + tijd
        // - puntenkoers → rondes + punten + tijd
        // - afvalkoers  → rondes + tijd (eliminatie)
        const raceType       = rit.race_type || 'inline';
        const isSprint       = raceType === 'sprint';
        const isPuntenkoers  = raceType === 'puntenkoers';
        const isAfvalkoers   = raceType === 'afvalkoers';
        // Selector alleen tonen voor niet-sprint-afstanden: voor sprints
        // heeft de user geen keuze (tijd-only).
        const toonRaceTypeSelector = !isSprint;

        // Rondes-kolom voor alle niet-sprint race-types; voor sprint nooit.
        const heeftRondes  = !isSprint;
        const heeftPunten  = isPuntenkoers || rit.rijders.some(r => r.punten != null);
        const toonPkPanel  = isPuntenkoers || heeftPunten;

        const validPosities = [...new Set(rit.rijders.map(r => r.finishpositie).filter(Boolean))].sort((a, b) => a - b);
        // Ex-aequo rangmap voor initiële render
        const _rangMap = _berekenPosities(
            rit.rijders.map(r => ({ entry_id: r.entry_id, tijd_ms: r.tijd_ms, sanctie: r.sanctie, rondes: r.rondes })),
            true
        );

        const opts  = { heeftRondes };
        const rijen = rit.rijders.map(r => _liveRijRij(r, compact, validPosities, _rangMap, opts)).join('');

        const rndCol  = heeftRondes ? `<col class="live-col-rondes">` : '';
        const rndHead = heeftRondes ? `<th class="live-col-rondes" title="Aantal ronden">Rnd</th>` : '';

        // Race-type selector
        const raceTypeSelectorHtml = (toonRaceTypeSelector && !_liveLeesOnly)
            ? `<div class="live-race-type-wrap">` +
              `<label class="live-race-type-lbl">Race-type:</label>` +
              `<select class="live-race-type-sel" id="live-race-type-${idx}">` +
              `<option value="inline"      ${rit.race_type === 'inline'      ? 'selected':''}>Inline (tijd)</option>` +
              `<option value="puntenkoers" ${rit.race_type === 'puntenkoers' ? 'selected':''}>Puntenkoers</option>` +
              `<option value="afvalkoers"  ${rit.race_type === 'afvalkoers'  ? 'selected':''}>Afvalkoers</option>` +
              `</select></div>`
            : '';

        tabelHtml = raceTypeSelectorHtml +
            `<table class="heat-tabel live-heat-tabel">` +
            (compact
                ? `<colgroup><col class="heat-pos"><col class="heat-naam">${rndCol}<col class="live-col-tijd"><col class="live-col-sanctie"><col class="live-col-finish"></colgroup>` +
                  `<thead><tr><th class="heat-pos">#</th><th>Naam</th>${rndHead}<th class="live-col-tijd">Tijd</th><th class="live-col-sanctie">Sanctie</th><th class="live-col-finish">Fin.</th></tr></thead>`
                : `<colgroup><col class="heat-pos"><col class="heat-snr"><col class="heat-naam"><col class="heat-tp">${rndCol}<col class="live-col-tijd"><col class="live-col-sanctie"><col class="live-col-finish"></colgroup>` +
                  `<thead><tr><th class="heat-pos">#</th><th class="heat-snr">Snr</th><th>Naam</th><th class="heat-tp">Transp.</th>${rndHead}<th class="live-col-tijd">Tijd</th><th class="live-col-sanctie">Sanctie</th><th class="live-col-finish">Fin.</th></tr></thead>`
            ) +
            `<tbody>${rijen}</tbody>` +
            `</table>` +
            // ── PK-punten panel ──────────────────────────────────────────────
            (() => {
                const sortedR  = [...rit.rijders].sort((a, b) => (a.startnummer ?? 9999) - (b.startnummer ?? 9999));
                const metPuntenR = sortedR.filter(r => r.punten != null && r.punten > 0);
                const dis = _liveLeesOnly ? ' disabled' : '';

                // ── Bovenste sectie: nummers MÉT punten + +X knoppen ──────────
                const topHtml = _liveLeesOnly ? '' : (
                    `<div class="live-pk-top">` +
                    `<div class="live-pk-met-punten" id="live-pk-met-punten-${idx}">` +
                    (metPuntenR.length
                        ? metPuntenR.map(r =>
                            `<button class="live-pk-snr-btn live-pk-snr-heeft-punten" ` +
                            `data-pk-entry="${r.entry_id}" data-pk-naam="${escHtml(r.full_name||'')}">` +
                            `<span class="live-pk-snr-nr">${escHtml(String(r.startnummer??'?'))}</span>` +
                            `<span class="live-pk-snr-pts">${r.punten}</span>` +
                            `</button>`).join('')
                        : `<span class="live-pk-geen-punten">—</span>`) +
                    `</div>` +
                    `<div class="live-pk-plus-wrap">` +
                    `<button class="live-pk-plus-btn live-pk-plus-3" id="live-pk-plus3-${idx}" disabled>+3</button>` +
                    `<div class="live-pk-plus-rechts">` +
                    `<button class="live-pk-plus-btn live-pk-plus-2" id="live-pk-plus2-${idx}" disabled>+2</button>` +
                    `<button class="live-pk-plus-btn live-pk-plus-1" id="live-pk-plus1-${idx}" disabled>+1</button>` +
                    `</div></div>` +
                    `</div>`
                );

                // ── Onderste sectie: alle nummers + invoerbalk ónder het grid ──
                const bottomHtml =
                    `<div class="live-pk-bottom">` +
                    // Alle nummers als knoppen (geen voorgeselecteerd)
                    `<div class="live-pk-grid" id="live-pk-grid-${idx}">` +
                    sortedR.map(r => {
                        const hp = r.punten != null && r.punten > 0;
                        return `<button class="live-pk-snr-btn${hp?' live-pk-snr-heeft-punten':''}" ` +
                            `data-pk-entry="${r.entry_id}" data-pk-naam="${escHtml(r.full_name||'')}"${dis}>` +
                            `<span class="live-pk-snr-nr">${escHtml(String(r.startnummer??'?'))}</span>` +
                            `<span class="live-pk-snr-pts">${hp?r.punten:''}</span>` +
                            `</button>`;
                    }).join('') +
                    `</div>` +
                    // Permanente invoerbalk — ónder het grid, input disabled tot selectie
                    (!_liveLeesOnly
                        ? `<div class="live-pk-invoer" id="live-pk-invoer-${idx}">` +
                          `<span class="live-pk-invoer-naam" id="live-pk-invoer-naam-${idx}">— selecteer een nummer —</span>` +
                          `<input type="number" class="live-pk-invoer-inp" id="live-pk-invoer-inp-${idx}" ` +
                          `min="0" step="1" placeholder="0" disabled>` +
                          `<button class="live-pk-invoer-ok" id="live-pk-invoer-ok-${idx}" disabled>✓</button>` +
                          `</div>`
                        : '') +
                    // Verborgen inputs = bron voor _livePuntenOpslaan
                    sortedR.map(r =>
                        `<input type="hidden" class="live-punten-inp" data-pk-entry="${r.entry_id}" value="${r.punten??''}">`
                    ).join('') +
                    `</div>`;

                return (
                    `<div class="live-pk-panel" id="live-pk-panel-${idx}"${toonPkPanel ? '' : ' hidden'}>` +
                    `<div class="live-pk-titel">📊 Puntenkoers</div>` +
                    topHtml + bottomHtml +
                    `</div>`
                );
            })() +
            // ── Afvalkoers panel ─────────────────────────────────────────────
            _bouwAfvalPaneel(rit, idx, isAfvalkoers);
    } else {
        // Kaart zonder startlijst — placeholder slots
        const verwacht = rit.verwacht || 0;
        let placeholderRijen = '';
        for (let i = 1; i <= verwacht; i++) {
            placeholderRijen +=
                `<tr class="heat-schema-row">` +
                `<td class="heat-pos">${i}</td>` +
                `<td class="heat-naam heat-schema-slot">Startpositie ${i}</td>` +
                `<td colspan="3" class="live-col-geen-loting"></td>` +
                `</tr>`;
        }
        if (!verwacht) {
            placeholderRijen =
                `<tr><td colspan="5" class="live-geen-loting">Geen startlijst beschikbaar.</td></tr>`;
        }
        tabelHtml =
            `<table class="heat-tabel live-heat-tabel heat-card-schema">` +
            `<colgroup>` +
            `<col class="heat-pos">` +
            `<col class="heat-snr">` +
            `<col class="heat-naam">` +
            `<col class="heat-tp">` +
            `<col class="live-col-tijd">` +
            `<col class="live-col-sanctie">` +
            `<col class="live-col-finish">` +
            `</colgroup>` +
            `<thead><tr>` +
            `<th class="heat-pos">#</th>` +
            `<th class="heat-snr">Snr</th>` +
            `<th>Naam</th>` +
            `<th colspan="4"></th>` +
            `</tr></thead>` +
            `<tbody>${placeholderRijen}</tbody>` +
            `</table>`;
    }

    // Opslaan-knop: alleen voor kaarten met startlijst, wordt later
    // in/uitgeschakeld via _liveUpdateKaartActief
    const opslaanHtml = (_liveHasHeat(rit) && !_liveLeesOnly)
        ? `<div class="live-card-acties" id="live-card-acties-${idx}">` +
          `<button class="live-import-btn" id="live-btn-import-${idx}" title="Importeer tijden uit CSV">&#128229; Import</button>` +
          `<button class="live-opslaan-btn" id="live-btn-opslaan-${idx}">&#128190; Opslaan</button>` +
          `</div>` +
          `<div class="live-import-panel verborgen" id="live-import-panel-${idx}">` +
          `<div class="live-import-row">` +
              `<label class="live-import-label">Map:</label>` +
              `<input type="search" class="live-import-map-filter" id="live-import-mapfilter-${idx}" placeholder="🔍 filter op naam…" autocomplete="off">` +
              `<select class="live-import-map-sel" id="live-import-map-${idx}"><option value="">— laden… —</option></select>` +
          `</div>` +
          `<div class="live-import-row">` +
              `<label class="live-import-label">Bestand:</label>` +
              `<div class="live-import-sort" role="group" aria-label="Sorteervolgorde bestanden">` +
                  `<button type="button" class="live-import-sort-btn" id="live-import-sort-naam-${idx}" data-sort="naam" title="Sorteer op naam">A–Z</button>` +
                  `<button type="button" class="live-import-sort-btn" id="live-import-sort-nieuw-${idx}" data-sort="nieuw" title="Sorteer op nieuwste eerst">Nieuwste</button>` +
              `</div>` +
              `<select class="live-import-sel" id="live-import-sel-${idx}" disabled><option value="">— kies eerst een map —</option></select>` +
          `</div>` +
          `<div class="live-import-preview verborgen" id="live-import-preview-${idx}"></div>` +
          `<div class="live-import-acties verborgen" id="live-import-acties-${idx}">` +
              `<span class="live-import-status" id="live-import-status-${idx}"></span>` +
              `<button class="live-import-laad-btn" id="live-import-laad-${idx}">Overnemen in heat</button>` +
          `</div>` +
          `</div>`
        : `<div class="live-card-acties verborgen" id="live-card-acties-${idx}"></div>`;

    const isSchema = !_liveHasHeat(rit);
    return `<div class="heat-card live-carousel-card${isSchema ? ' heat-card-schema' : ''}" data-idx="${idx}">` +
        titelHtml +
        tabelHtml +
        opslaanHtml +
        `</div>`;
}

function _liveRenderCarousel() {
    const container = el('live-inhoud');
    if (!container) return;

    const totaal = _liveRitten.length;
    const idx    = _liveHuidigIdx;
    const rit    = _liveRitten[idx];

    // Dropdown opties (custom dropdown met filter-respect)
    const dropdownOpts = _liveDdBouwOpties();

    const huidigeRit   = _liveRitten[idx];
    const huidigLabel  = `${_liveRitIcoon(huidigeRit)} ${escHtml(huidigeRit.rit_naam)}`;

    // Filter-pillen: aan/uit per status-icoon (4 stappen van niks naar klaar).
    // Zelfde iconografie als /public (🚩 loting / 🏁 finish).
    const pilHtml = ['geen_lijst', 'geen_resultaat', 'deels', 'compleet'].map(s => {
        const icoon = s === 'compleet'       ? '🏁'
                    : s === 'deels'          ? '◑'
                    : s === 'geen_resultaat' ? '🚩'
                    :                          '○';
        const tip   = s === 'compleet'       ? 'Alle tijden ingevuld'
                    : s === 'deels'          ? 'Deels ingevuld'
                    : s === 'geen_resultaat' ? 'Startlijst klaar, nog geen resultaten'
                    :                          'Geen startlijst';
        const act   = _liveFilter[s] ? ' active' : '';
        return `<button type="button" class="live-nav-pil${act}" data-filter="${s}" title="${tip}">${icoon}</button>`;
    }).join('');

    const navHtml =
        `<div class="live-carousel-nav">` +
        `<div class="live-nav-filter" title="Filter op status">${pilHtml}</div>` +
        `<div class="live-nav-dd" id="live-nav-dd">` +
          `<button type="button" class="live-nav-dropdown" id="live-nav-dd-trigger">${huidigLabel}</button>` +
          `<div class="live-nav-dd-panel" id="live-nav-dd-panel" hidden>${dropdownOpts}</div>` +
        `</div>` +
        `<span class="live-nav-teller">${idx + 1} / ${totaal}</span>` +
        `</div>`;

    const alleKaarten = _liveRitten.map((r, i) => _liveBouwKaart(r, i, false)).join('');

    const carouselHtml =
        `<div class="live-carousel-outer">` +
        `<button class="live-carousel-arrow live-arrow-prev" id="live-btn-vorige" title="Vorige rit (←)">&#8249;</button>` +
        `<div class="live-carousel-viewport" id="live-carousel-viewport">` +
        `<div class="live-carousel-track" id="live-carousel-track">${alleKaarten}</div>` +
        `</div>` +
        `<button class="live-carousel-arrow live-arrow-next" id="live-btn-volgende" title="Volgende rit (→)">&#8250;</button>` +
        `</div>`;

    // Hergeneer-knop: tonen als ronde al compleet is bij render
    let volgendeHtml = '';
    if (rit && !_liveLeesOnly) {
        const volgende = _volgendeRondeType(rit.dc_id, rit.distance_id, rit.ronde_type);
        if (volgende && _liveRondeCompleet(rit.dc_id, rit.distance_id, rit.ronde_type)) {
            const label = _liveHergeneerLabel(rit.dc_id, rit.distance_id, volgende);
            volgendeHtml =
                `<div class="live-ronde-compleet" id="live-ronde-compleet">` +
                `✓ Alle ritten van de ${escHtml(RONDE_LABEL[rit.ronde_type] || rit.ronde_type)} zijn compleet.` +
                `<button class="live-ronde-btn" id="live-btn-volgende-ronde"` +
                ` data-dc-id="${escHtml(rit.dc_id)}"` +
                ` data-distance-id="${escHtml(rit.distance_id || '')}"` +
                ` data-van="${escHtml(rit.ronde_type)}"` +
                ` data-naar="${escHtml(volgende)}">` +
                `&#8635; Hergeneer ${escHtml(label)}` +
                `</button>` +
                `</div>`;
        }
    }

    // Links panel (alle rijders in categorie+ronde van huidige rit)
    const linksPanelHtml = rit
        ? _liveBouwLinksPanel(rit.dc_id, rit.distance_id, rit.ronde_type)
        : `<div class="live-panel-links" id="live-panel-links"><div class="live-panel-leeg">Geen rit geselecteerd.</div></div>`;

    container.innerHTML =
        `<div class="live-layout">` +
        linksPanelHtml +
        `<div class="live-carousel-sectie" id="live-carousel-sectie">` +
        navHtml + carouselHtml +
        `</div>` +
        `</div>` +
        volgendeHtml;

    _liveUpdateKaartActief(idx);
    requestAnimationFrame(() => { _livePositionTrack(false); });

    el('live-btn-vorige')?.addEventListener('click',  () => _liveNavigeer(_liveHuidigIdx - 1));
    el('live-btn-volgende')?.addEventListener('click', () => _liveNavigeer(_liveHuidigIdx + 1));
    _liveBindDropdown();

    _liveBind(idx);
    _livePanelBind();

    el('live-btn-volgende-ronde')?.addEventListener('click', e => {
        const b = e.currentTarget;
        _liveGenereerKetenStap(b.dataset.dcId, b.dataset.distanceId, b.dataset.van, b.dataset.naar);
    });
}

// ── Links panel: alle rijders in categorie+ronde ──────────────────────────────

// Panel-specifieke listeners — het panel is nu volledig read-only, dus er
// hoeven geen listeners meer te worden gekoppeld. De functie blijft bestaan
// als no-op zodat aanroepen elders geen crash geven.
function _liveInitPanelListeners() {
    // geen listeners nodig voor een read-only panel
}

function _liveBouwLinksPanel(dcId, distanceId, rondeType) {
    const ritten = _liveRitten.filter(r =>
        r.dc_id === dcId &&
        String(r.distance_id ?? '') === String(distanceId ?? '') &&
        r.ronde_type === rondeType
    );

    const rondeNaam = RONDE_LABEL[rondeType] || rondeType;

    // Alle rijders uit alle ritten in deze categorie+ronde, plat
    const alleRijders = [];
    for (const rit of ritten) {
        if (_liveHasHeat(rit)) {
            for (const r of rit.rijders) {
                alleRijders.push({ ...r, rit_id: rit.rit_id, heat_nr: rit.heat_nr });
            }
        }
    }

    // Bepaal Q/q kwalificatie per rijder (alleen als er resultaten zijn)
    // Q = positie-kwalificatie (top N per heat), q = tijd-kwalificatie
    const heeftResultaten = alleRijders.some(r => r.finishpositie != null);
    if (heeftResultaten) {
        // Doorstroomregels uit catConfig
        const ccKey = dcId + '|' + (distanceId ?? '');
        const cc = _liveCatConfigs[ccKey] ?? {};
        let qPerHeat = 0, totaalDoor = 0;
        if (rondeType === 'heats') {
            qPerHeat  = parseInt(cc.heats_q_heat ?? 0);
            totaalDoor = parseInt(cc.heats_q ?? 0);
        } else if (rondeType === 'kwartfinale') {
            qPerHeat  = parseInt(cc.kwart_q_heat ?? 1);
            totaalDoor = parseInt(cc.kwart_door ?? 0);
        } else if (rondeType === 'halve_finale') {
            qPerHeat  = parseInt(cc.half_q_heat ?? 1);
            totaalDoor = parseInt(cc.half_door ?? 0);
        }

        // Groepeer per heat, bepaal Q per heat
        const perHeat = {};
        for (const r of alleRijders) {
            const hk = r.heat_nr ?? r.rit_id;
            if (!perHeat[hk]) perHeat[hk] = [];
            perHeat[hk].push(r);
        }
        // Sorteer elke heat op finishpositie
        for (const hk of Object.keys(perHeat)) {
            perHeat[hk].sort((a, b) => (a.finishpositie ?? 999) - (b.finishpositie ?? 999));
        }

        // Markeer Q-rijders (top qPerHeat per heat, als qPerHeat > 0)
        const qRijders = new Set();
        if (qPerHeat > 0) {
            for (const hk of Object.keys(perHeat)) {
                const heatRijders = perHeat[hk];
                for (let i = 0; i < Math.min(qPerHeat, heatRijders.length); i++) {
                    if (heatRijders[i].finishpositie != null && !heatRijders[i].sanctie)
                        qRijders.add(heatRijders[i].entry_id);
                }
            }
        }

        // Alle rijders met tijd gesorteerd, markeer q-rijders (tijdsnelsten)
        // Inclusief ex-aequo: als de laatste q-spot dezelfde tijd heeft als de volgende, gaan die ook mee
        const metTijd = alleRijders
            .filter(r => r.tijd_ms != null && !qRijders.has(r.entry_id) && !r.sanctie)
            .sort((a, b) => a.tijd_ms - b.tijd_ms);
        const aantalQ = qRijders.size;
        const aantalq = Math.max(0, totaalDoor - aantalQ);
        const qTijdRijders = new Set();
        for (let i = 0; i < Math.min(aantalq, metTijd.length); i++) {
            qTijdRijders.add(metTijd[i].entry_id);
        }
        // Ex-aequo: als de laatst-gekwalificeerde q dezelfde tijd heeft als de volgende, ook meenemen
        if (aantalq > 0 && metTijd[aantalq - 1] && metTijd[aantalq]) {
            const grensTijd = metTijd[aantalq - 1].tijd_ms;
            for (let i = aantalq; i < metTijd.length; i++) {
                if (metTijd[i].tijd_ms === grensTijd) qTijdRijders.add(metTijd[i].entry_id);
                else break;
            }
        }

        // Markeer alle rijders
        for (const r of alleRijders) {
            if (qRijders.has(r.entry_id))      r._kwal = 'Q';
            else if (qTijdRijders.has(r.entry_id)) r._kwal = 'q';
            else                                    r._kwal = '';
        }

        // Sorteer: Q eerst (op positie), dan q (op tijd), dan rest (op tijd, daarna startnummer)
        alleRijders.sort((a, b) => {
            const ordA = a._kwal === 'Q' ? 0 : a._kwal === 'q' ? 1 : 2;
            const ordB = b._kwal === 'Q' ? 0 : b._kwal === 'q' ? 1 : 2;
            if (ordA !== ordB) return ordA - ordB;
            if (ordA === 0) return (a.finishpositie ?? 999) - (b.finishpositie ?? 999); // Q: op positie
            // q en rest: op tijd, dan startnummer
            const tA = a.tijd_ms ?? 999999, tB = b.tijd_ms ?? 999999;
            if (tA !== tB) return tA - tB;
            return (a.startnummer ?? 99999) - (b.startnummer ?? 99999);
        });
    } else {
        // Geen resultaten: sorteer op startnummer
        alleRijders.sort((a, b) => (a.startnummer ?? 99999) - (b.startnummer ?? 99999));
    }

    // Panel is read-only: alleen overzicht van Q/q-kwalificatie + gelezen
    // tijden/sancties. Alle wijzigingen gaan via de carousel-kaart.

    // Rondes-kolom tonen als minimaal één rijder ronde-data heeft
    const heeftRondes = alleRijders.some(r => r.rondes != null && r.rondes !== '' && r.rondes !== 0);

    let tbody = '';
    if (alleRijders.length === 0) {
        tbody = `<tr class="live-panel-rij-leeg"><td colspan="${heeftRondes ? 6 : 5}">Geen startlijst beschikbaar</td></tr>`;
    } else {
        for (const r of alleRijders) {
            const tijdVal   = r.tijd_ms !== null ? _msTijdNaarDisplay(r.tijd_ms) : '—';
            const sanctieUi = r.sanctie || '';
            const statusKls = r.sanctie ? 'live-rit-status-sanctie'
                            : r.tijd_ms !== null ? 'live-rit-status-compleet'
                            : 'live-rit-status-leeg';
            const rondesTd  = heeftRondes
                ? `<td class="live-col-rondes"><span class="live-panel-rondes-txt">${r.rondes ?? '—'}</span></td>`
                : '';
            const kwalBadge = r._kwal === 'Q' ? '<span style="color:#198754;font-weight:700">Q</span>'
                           : r._kwal === 'q' ? '<span style="color:#0d6efd;font-weight:600">q</span>'
                           : '';
            tbody +=
                `<tr class="live-panel-rij ${statusKls}" data-panel-entry="${r.entry_id}" data-rit-id="${r.rit_id}" data-rondes="${r.rondes ?? ''}">` +
                `<td>${r.startnummer ?? ''}</td>` +
                `<td>${escHtml(r.full_name || '')}</td>` +
                `<td style="text-align:center;width:24px">${kwalBadge}</td>` +
                rondesTd +
                `<td class="live-col-tijd"><span class="live-panel-tijd-txt">${escHtml(tijdVal)}</span></td>` +
                `<td class="live-col-sanctie"><span class="live-panel-sanctie-txt">${escHtml(sanctieUi || '—')}</span></td>` +
                `<td class="live-col-finish"><span class="live-finish-badge">${r.finishpositie?_ordinaal(r.finishpositie):'—'}</span></td>` +
                `</tr>`;
        }
    }

    // Geen opslaan-knop meer — alle invoer + opslaan via de carousel-kaart.
    const opslaanKnop = '';
    const rndColHtml  = heeftRondes ? `<col class="live-col-rondes">` : '';
    const rndHeadHtml = heeftRondes ? `<th class="live-col-rondes" title="Ronden">Rnd</th>` : '';
    const kwalHead = `<th style="width:24px;text-align:center" title="Q=positie, q=tijd">Q</th>`;

    return `<div class="live-panel-links" id="live-panel-links"` +
        ` data-dc-id="${escHtml(dcId)}"` +
        ` data-distance-id="${escHtml(String(distanceId ?? ''))}"` +
        ` data-ronde-type="${escHtml(rondeType)}">` +
        `<div class="live-panel-header">${escHtml(rondeNaam)}</div>` +
        `<div class="live-panel-scroll">` +
        `<table class="live-panel-tabel">` +
        `<colgroup>` +
        `<col class="live-col-snr"><col><col style="width:24px">${rndColHtml}` +
        `<col class="live-col-tijd"><col class="live-col-sanctie"><col class="live-col-finish">` +
        `</colgroup>` +
        `<thead><tr>` +
        `<th>Snr</th><th>Naam</th>${kwalHead}${rndHeadHtml}` +
        `<th>Tijd</th>` +
        `<th>Sanctie</th><th>Fin.</th>` +
        `</tr></thead>` +
        `<tbody>${tbody}</tbody>` +
        `</table>` +
        `</div>` +
        opslaanKnop +
        `</div>`;
}

function _liveUpdatePanelActiefRit(nieuweIdx) {
    document.querySelectorAll('.live-panel-rit-kop').forEach(tr => {
        const idx = parseInt(tr.dataset.ritIdx ?? '-1');
        tr.classList.toggle('live-panel-rit-actief', idx === nieuweIdx);
        const badge = tr.querySelector('.live-panel-badge-actief');
        if (idx === nieuweIdx) {
            if (!badge) {
                const span = document.createElement('span');
                span.className = 'live-panel-badge-actief';
                span.textContent = '◀ actief';
                tr.querySelector('td')?.appendChild(span);
            }
        } else {
            badge?.remove();
        }
    });
}

function _livePanelBind() {
    const panel = el('live-panel-links');
    if (!panel) return;

    // Rit-kop klikken → navigeer carousel
    panel.querySelectorAll('.live-panel-rit-kop[data-rit-idx]').forEach(tr => {
        tr.addEventListener('click', () => _liveNavigeer(parseInt(tr.dataset.ritIdx)));
    });

    // Panel is read-only: geen input-events meer (tijden, sancties en rondes
    // worden alleen in de carousel-kaart bewerkt en via _liveSyncInvoer naar
    // de read-only tekstcellen in dit panel gesynct).

    _livePanelHerbereken();
}

// Bereken overall rank op basis van tijd/sanctie over alle ritten in het panel
function _livePanelHerbereken() {
    const panel = el('live-panel-links');
    if (!panel) return;

    // Bij puntenkoers: badges worden door _liveUpdatePuntenBadges beheerd
    const dcId       = panel.dataset.dcId;
    const distanceId = panel.dataset.distanceId;
    const rondeType  = panel.dataset.rondeType;
    const pkRit = _liveRitten.find(r =>
        String(r.dc_id) === dcId &&
        String(r.distance_id ?? '') === distanceId &&
        r.ronde_type === rondeType &&
        r.race_type === 'puntenkoers'
    );
    if (pkRit) {
        const pkIdx = _liveRitten.indexOf(pkRit);
        // Rijkleuren bijwerken op basis van tijd
        panel.querySelectorAll('.live-panel-rij[data-panel-entry]').forEach(rij => {
            const inp  = rij.querySelector('.live-tijd-inp');
            const sel  = rij.querySelector('.live-sanctie-sel');
            const ms   = inp ? _parseTijdInvoer(inp.value) : null;
            const sv   = sel?.value || '';
            rij.classList.remove('live-rit-status-compleet', 'live-rit-status-sanctie', 'live-rit-status-leeg');
            if (sv === 'FS')  rij.classList.add(ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');
            else if (sv)      rij.classList.add('live-rit-status-sanctie');
            else if (ms > 0)  rij.classList.add('live-rit-status-compleet');
            else              rij.classList.add('live-rit-status-leeg');
        });
        _liveUpdatePuntenBadges(pkIdx);
        return;
    }

    // Normale modus: verzamel entries
    const entries = [];
    panel.querySelectorAll('.live-panel-rij[data-panel-entry]').forEach(rij => {
        const entryId = parseInt(rij.dataset.panelEntry);
        const inp     = rij.querySelector('.live-tijd-inp');
        const sel     = rij.querySelector('.live-sanctie-sel');
        const rnInp   = rij.querySelector('.live-rondes-inp');
        const rondes  = rnInp ? (rnInp.value !== '' ? (parseInt(rnInp.value) || null) : null) : null;
        entries.push({ entry_id: entryId, tijd_ms: inp ? _parseTijdInvoer(inp.value) : null, sanctie: sel?.value || null, rondes });
    });

    const posMap = _berekenPosities(entries, true);

    // Overschrijf met finishpositie uit lokale state (weerspiegelt wisselaars)
    _liveRitten
        .filter(r =>
            String(r.dc_id) === dcId &&
            String(r.distance_id ?? '') === distanceId &&
            r.ronde_type === rondeType
        )
        .forEach(rit => {
            (rit.rijders ?? []).forEach(r => {
                if (r.finishpositie != null) posMap.set(r.entry_id, r.finishpositie);
            });
        });

    // Update badges en rijkleuren
    panel.querySelectorAll('.live-panel-rij[data-panel-entry]').forEach(rij => {
        const entryId = parseInt(rij.dataset.panelEntry);
        const badge   = rij.querySelector('.live-finish-badge');
        const inp     = rij.querySelector('.live-tijd-inp');
        const sel     = rij.querySelector('.live-sanctie-sel');
        const heeftSanctie = sel && sel.value !== '';
        const ms  = inp ? _parseTijdInvoer(inp.value) : null;
        const pos = posMap.get(entryId);

        if (badge) {
            if (pos !== undefined) {
                badge.textContent = _ordinaal(pos);
                badge.className   = heeftSanctie ? 'live-finish-badge finish-pos-sanctie' : 'live-finish-badge finish-pos';
            } else {
                badge.textContent = '—';
                badge.className   = 'live-finish-badge';
            }
        }

        rij.classList.remove('live-rit-status-compleet', 'live-rit-status-sanctie', 'live-rit-status-leeg');
        const sanctieWaarde = rij.querySelector('.live-sanctie-sel')?.value || '';
        if (sanctieWaarde === 'FS')  rij.classList.add(ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');
        else if (heeftSanctie)       rij.classList.add('live-rit-status-sanctie');
        else if (ms > 0)             rij.classList.add('live-rit-status-compleet');
        else                         rij.classList.add('live-rit-status-leeg');
    });
}

// _liveOpslaanLinksPanel() is verwijderd — het linker paneel is nu read-only.
// Alle opslaan-acties gaan via _liveOpslaanRit() (de carousel-kaart-knop).

// ── Custom dropdown helpers ──────────────────────────────────────────────

// Bepaal de status van een rit voor de filter:
//   geen_lijst     — heat bestaat niet / geen rijders ingedeeld
//   geen_resultaat — rijders ingedeeld maar nog geen tijden/sancties
//   deels          — deel van de rijders heeft tijd/sanctie
//   compleet       — alle rijders hebben tijd of sanctie
function _liveRitStatus(r) {
    if (!_liveHasHeat(r))    return 'geen_lijst';
    if (_liveRitCompleet(r)) return 'compleet';
    if (_liveRitDeels(r))    return 'deels';
    return 'geen_resultaat';
}
// Icon-mapping consistent met /public:
//   ○   niks (geen startlijst)
//   🚩  loting klaar (rijders ingedeeld, nog geen tijden)
//   ◑   deels ingevuld (niet alle tijden binnen)
//   🏁  finish-vlag (alle tijden binnen)
function _liveRitIcoon(r) {
    const s = _liveRitStatus(r);
    return s === 'compleet'       ? '🏁'
         : s === 'deels'          ? '◑'
         : s === 'geen_resultaat' ? '🚩'
         :                          '○';   // geen_lijst
}

// Bouw de opties in het dropdown-paneel; filter rijden die volgens _liveFilter
// verborgen moeten zijn. De huidige rit wordt altijd getoond zodat de
// selected-indicator zichtbaar blijft.
function _liveDdBouwOpties() {
    const idx = _liveHuidigIdx;
    const stukken = _liveRitten.map((r, i) => {
        const status = _liveRitStatus(r);
        if (!_liveFilter[status] && i !== idx) return null;
        const icoon = _liveRitIcoon(r);
        const sel   = i === idx ? ' selected' : '';
        return `<div class="live-nav-dd-option${sel}" data-idx="${i}">${icoon} ${escHtml(r.rit_naam)}</div>`;
    }).filter(Boolean);
    if (!stukken.length) {
        return `<div class="live-nav-dd-leeg">Geen ritten in deze filter</div>`;
    }
    return stukken.join('');
}

function _liveBindDropdown() {
    const dd      = el('live-nav-dd');
    const trigger = el('live-nav-dd-trigger');
    const panel   = el('live-nav-dd-panel');
    if (!dd || !trigger || !panel) return;

    const sluit = () => { dd.classList.remove('open'); panel.hidden = true; };
    const open  = () => {
        dd.classList.add('open');
        panel.hidden = false;
        // scroll selected in view
        const sel = panel.querySelector('.live-nav-dd-option.selected');
        if (sel) sel.scrollIntoView({ block: 'nearest' });
    };

    trigger.addEventListener('click', e => {
        e.stopPropagation();
        if (panel.hidden) open(); else sluit();
    });
    panel.addEventListener('click', e => {
        const opt = e.target.closest('.live-nav-dd-option');
        if (!opt) return;
        const i = parseInt(opt.dataset.idx);
        sluit();
        _liveNavigeer(i);
    });
    // click buiten: sluiten
    document.addEventListener('click', e => {
        if (!panel.hidden && !dd.contains(e.target)) sluit();
    });
    // ESC sluit
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !panel.hidden) sluit();
    });

    // Filter-pillen: klik toggelt status, paneel-opties worden herbouwd
    document.querySelectorAll('.live-nav-pil').forEach(pil => {
        pil.addEventListener('click', e => {
            e.stopPropagation();
            const status = pil.dataset.filter;
            if (!status || !(status in _liveFilter)) return;
            _liveFilter[status] = !_liveFilter[status];
            pil.classList.toggle('active', _liveFilter[status]);
            panel.innerHTML = _liveDdBouwOpties();
            if (!panel.hidden) {
                // Hergebruik: zorg dat geselecteerde optie (indien zichtbaar) in beeld blijft
                const sel = panel.querySelector('.live-nav-dd-option.selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }
        });
    });
}

// Label van de trigger bijwerken (icoon + ritnaam van rit i)
function _liveDdUpdateLabel(i) {
    const trigger = el('live-nav-dd-trigger');
    if (!trigger) return;
    const r = _liveRitten[i];
    if (!r) return;
    trigger.textContent = _liveRitIcoon(r) + ' ' + r.rit_naam;
}

// Tekst van één optie in het paneel bijwerken. Als het status-icoon verandert
// (bv. van ◑ → ✓ na een save) en die status nu gefilterd is, wordt het hele
// paneel opnieuw opgebouwd zodat de filter correct toegepast blijft.
function _liveDdUpdateOptie(i) {
    const panel = el('live-nav-dd-panel');
    if (!panel) return;
    const opt = panel.querySelector(`.live-nav-dd-option[data-idx="${i}"]`);
    const r   = _liveRitten[i];
    if (!r) return;
    const nieuweStatus = _liveRitStatus(r);
    const zichtbaar   = _liveFilter[nieuweStatus] || i === _liveHuidigIdx;

    if (!opt && zichtbaar) {
        // Was verborgen, moet nu zichtbaar worden → volledige rebuild (correcte volgorde)
        panel.innerHTML = _liveDdBouwOpties();
        return;
    }
    if (opt && !zichtbaar) {
        // Was zichtbaar, moet nu verborgen → volledige rebuild
        panel.innerHTML = _liveDdBouwOpties();
        return;
    }
    if (opt) {
        opt.textContent = _liveRitIcoon(r) + ' ' + r.rit_naam;
    }
}

// Geselecteerde optie markeren + trigger-label bijwerken
function _liveDdSetValue(idx) {
    const panel = el('live-nav-dd-panel');
    if (!panel) return;
    panel.querySelectorAll('.live-nav-dd-option').forEach(o => {
        o.classList.toggle('selected', parseInt(o.dataset.idx) === idx);
    });
    _liveDdUpdateLabel(idx);
}

// Markeer de actieve kaart en toon/verberg opslaan-knoppen
function _liveUpdateKaartActief(idx) {
    document.querySelectorAll('.live-carousel-card').forEach((card, i) => {
        const isActief = i === idx;
        card.classList.toggle('live-card-actief', isActief);
        card.classList.toggle('live-card-prev',   i === idx - 1);
        card.classList.toggle('live-card-next',   i === idx + 1);
        // Opslaan-knop: alleen tonen op actieve kaart met startlijst
        const acties = document.getElementById('live-card-acties-' + i);
        if (acties) {
            acties.classList.toggle('verborgen', !isActief);
        }
        // Inputs uitschakelen op niet-actieve kaarten
        card.querySelectorAll('.live-tijd-inp, .live-sanctie-sel').forEach(inp => {
            inp.disabled = !isActief || _liveLeesOnly;
        });
    });
}

// Bereken en pas de transform van de carousel track toe
// Kaartbreedte is volledig via CSS geregeld (220px); JS doet alleen de transform.
function _livePositionTrack(animeren = true) {
    const track = el('live-carousel-track');
    if (!track) return;
    if (!track.children.length) return;

    // Kaartbreedte + gap moeten overeenkomen met CSS (.live-carousel-card: 500px, gap: 16px)
    const cardW = 500;
    const gap   = 16;

    const offset = _liveHuidigIdx * (cardW + gap);
    track.classList.toggle('live-no-transition', !animeren);
    track.style.transform = `translateX(-${offset}px)`;
}

// Bind invoer-events op de kaart met gegeven index (eenmalig via data-bound vlag)
function _liveBind(idx) {
    const rit = _liveRitten[idx];
    if (!rit || !_liveHasHeat(rit)) return;

    const kaart = document.querySelector(`.live-carousel-card[data-idx="${idx}"]`);
    if (!kaart || kaart.dataset.bound === '1') return; // al gebonden
    kaart.dataset.bound = '1';

    rit.rijders.forEach(r => {
        const rij        = kaart.querySelector(`[data-entry="${r.entry_id}"]`);
        const tijdInp    = rij?.querySelector('.live-tijd-inp');
        const sanctieSel = rij?.querySelector('.live-sanctie-sel');

        tijdInp?.addEventListener('blur', () => {
            const ms      = _parseTijdInvoer(tijdInp.value);
            const tijdVal = ms !== null ? _msTijdNaarDisplay(ms) : '';
            tijdInp.value = tijdVal;
            // Alleen sancties die fundamenteel geen tijd hebben (DNS/DNF/DQ-*)
            // worden gewist bij tijdinvoer. FS, RR, W1 en W2 blijven staan.
            if (ms !== null && sanctieSel?.value && _SANCTIE_WIST_TIJD.has(sanctieSel.value)) {
                sanctieSel.value = '';
            }
            const sanctie = sanctieSel?.value || '';
            _liveSyncInvoer(r.entry_id, tijdVal, sanctie);
            _liveOngeslagen = true;
            _liveHerbereken(idx);
        });
        tijdInp?.addEventListener('input', () => { _liveOngeslagen = true; });

        sanctieSel?.addEventListener('change', () => {
            const sanctie = sanctieSel.value;
            // Alleen DNS/DNF/DQ-* wissen de tijd; FS, RR, W1 en W2 houden de
            // tijd (de jury past alleen handmatig de positie aan).
            if (sanctie && _SANCTIE_WIST_TIJD.has(sanctie) && tijdInp?.value.trim()) {
                tijdInp.value = '';
            }
            _liveSyncInvoer(r.entry_id, tijdInp?.value || '', sanctie);
            _liveOngeslagen = true;
            _liveHerbereken(idx);

            // Afvalkoers: DNS = niet gestart = direct geklasseerd op huidige laagste
            // plek. Bij wegnemen van DNS: rijder uit afgevallen-stack halen.
            if (rit.race_type === 'afvalkoers') {
                _afvalSyncSanctie(idx, r.entry_id, sanctie);
                _afvalRerenderPaneel(idx);
            }
        });
    });

    // Event delegation voor wissel-dropdown (werkt ook voor dynamisch toegevoegde dropdowns)
    kaart.addEventListener('change', async function(e) {
        const finSel = e.target.closest('.live-finish-sel');
        if (!finSel) return;

        const rij       = finSel.closest('tr[data-entry]');
        const entryIdA  = parseInt(rij?.dataset.entry);
        const nieuwePosA = parseInt(finSel.value);
        const riderA    = rit.rijders.find(r => r.entry_id === entryIdA);
        if (!riderA) return;
        const oudePosA  = riderA.finishpositie;
        if (nieuwePosA === oudePosA) return;

        // Zoek rijder B (huidig houder van de gewenste positie)
        const riderB = rit.rijders.find(r => r.finishpositie === nieuwePosA && r.entry_id !== entryIdA);
        if (!riderB) { finSel.value = oudePosA; return; }

        // Afvalkoers: swap is alléén toegestaan tussen twee afgevallen rijders
        // (of, in theorie, twee finishgroep-rijders — die hebben geen afval_rang).
        // Een afgevallene wisselen met een sprinter zou de afgevallen-stack
        // inconsistent maken (de "nog-in-koers" zou plots een afval_rang krijgen),
        // dus dat blokkeren we met een silent rollback.
        if (rit.race_type === 'afvalkoers') {
            const st = _afvalState[idx];
            const aIdx = st ? st.afgevallen.findIndex(a => a.entry_id === entryIdA) : -1;
            const bIdx = st ? st.afgevallen.findIndex(a => a.entry_id === riderB.entry_id) : -1;
            const aIsAfgevallen = aIdx !== -1;
            const bIsAfgevallen = bIdx !== -1;
            if (aIsAfgevallen !== bIsAfgevallen) {
                // Eén afgevallene + één sprinter: niet ondersteund. Silent rollback.
                finSel.value = oudePosA;
                return;
            }
            if (aIsAfgevallen && bIsAfgevallen) {
                // Beide afgevallen: wissel ook de plek-waarden in afgevallen-stack,
                // anders zet de eerstvolgende save-payload de oude rang terug.
                const plekA = st.afgevallen[aIdx].plek;
                const plekB = st.afgevallen[bIdx].plek;
                st.afgevallen[aIdx].plek = plekB;
                st.afgevallen[bIdx].plek = plekA;
                // Rijder-objecten ook bijwerken zodat een rerender van het paneel
                // (bv. via _afvalRerenderPaneel) de juiste rang toont.
                riderA.afval_rang = plekB;
                riderB.afval_rang = plekA;
            }
        }

        const oudeRondesA = riderA.rondes;
        const oudeRondesB = riderB.rondes;
        const oudeTijdA   = riderA.tijd_ms;
        const oudeTijdB   = riderB.tijd_ms;

        // Bepaal wie wordt gepromoveerd (betere finishpositie = kleiner getal)
        const promotingA = nieuwePosA < oudePosA;

        // Optimistische update finishpositie (altijd)
        riderA.finishpositie = nieuwePosA;
        riderB.finishpositie = oudePosA;

        // Rondes-correctie bij ongelijke rondes (ook voor PK): geef de VERLIEZER
        // de rondes van de verliezer én diens tijd +10ms, zodat _berekenPosities
        // (en de PK-sort punten→rondes→tijd) na een rebuild de juiste volgorde geeft.
        const isPuntenkoers = rit.race_type === 'puntenkoers';
        const heeftVerschillendeRondes = oudeRondesA != null && oudeRondesB != null
                                      && oudeRondesA !== oudeRondesB;

        if (heeftVerschillendeRondes) {
            if (promotingA) {
                // A = winnaar: ongewijzigd. B = verliezer: krijgt A's rondes + A's tijd + 10ms
                // (verliezer klopt zo qua rondes, winnaar behoudt eigen correcte data)
                riderB.rondes  = oudeRondesA;
                riderB.tijd_ms = (oudeTijdA ?? 0) + 10;
            } else {
                // B = winnaar: ongewijzigd. A = verliezer: krijgt B's rondes + B's tijd + 10ms
                riderA.rondes  = oudeRondesB;
                riderA.tijd_ms = (oudeTijdB ?? 0) + 10;
            }
        } else {
            // Zelfde rondes (sprint of ontbreekt): wissel alleen tijden
            riderA.tijd_ms = oudeTijdB;
            riderB.tijd_ms = oudeTijdA;
        }

        const rijB   = kaart.querySelector(`[data-entry="${riderB.entry_id}"]`);
        const selB   = rijB?.querySelector('.live-finish-sel');
        if (selB) selB.value = oudePosA;

        const tijdInpA = rij?.querySelector('.live-tijd-inp');
        const tijdInpB = rijB?.querySelector('.live-tijd-inp');
        if (tijdInpA) tijdInpA.value = riderA.tijd_ms ? _msTijdNaarDisplay(riderA.tijd_ms) : '';
        if (tijdInpB) tijdInpB.value = riderB.tijd_ms ? _msTijdNaarDisplay(riderB.tijd_ms) : '';

        // Helper: update rondes-cel + data-rondes in heat card én linker panel
        const _updateRondesDOM = (rijEl, entryId, nieuweRondes) => {
            if (!rijEl) return;
            rijEl.dataset.rondes = nieuweRondes ?? '';
            const rondesCol = rijEl.querySelector('.live-col-rondes');
            if (rondesCol) rondesCol.textContent = nieuweRondes != null ? nieuweRondes : '—';
            const panelRij = document.querySelector(`[data-panel-entry="${entryId}"]`);
            if (panelRij) {
                panelRij.dataset.rondes = nieuweRondes ?? '';
                const pr = panelRij.querySelector('.live-col-rondes');
                if (pr) pr.textContent = nieuweRondes != null ? nieuweRondes : '—';
            }
        };
        // Verliezer's rondes zijn gewijzigd → update DOM van de verliezer
        if (heeftVerschillendeRondes) {
            if (promotingA) _updateRondesDOM(rijB, riderB.entry_id, riderB.rondes); // verliezer = B
            else            _updateRondesDOM(rij,  entryIdA,        riderA.rondes); // verliezer = A
        }

        try {
            const res = await fetch('api/live.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    action:          'wissel_posities',
                    competition_id:  huidigCompId,
                    heat_entry_id_1: entryIdA,
                    heat_entry_id_2: riderB.entry_id,
                }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            // Herbereken heat card + linker panel
            _liveSyncInvoer(entryIdA,        tijdInpA?.value || '', rij?.querySelector('.live-sanctie-sel')?.value || '');
            _liveSyncInvoer(riderB.entry_id, tijdInpB?.value || '', rijB?.querySelector('.live-sanctie-sel')?.value || '');
            _liveHerbereken(idx);
            _livePanelHerbereken();

        } catch(err) {
            // Terugdraaien bij fout
            riderA.finishpositie = oudePosA;   riderA.tijd_ms = oudeTijdA; riderA.rondes = oudeRondesA;
            riderB.finishpositie = nieuwePosA; riderB.tijd_ms = oudeTijdB; riderB.rondes = oudeRondesB;
            finSel.value = oudePosA;
            if (selB) selB.value = nieuwePosA;
            if (tijdInpA) tijdInpA.value = oudeTijdA ? _msTijdNaarDisplay(oudeTijdA) : '';
            if (tijdInpB) tijdInpB.value = oudeTijdB ? _msTijdNaarDisplay(oudeTijdB) : '';
            if (heeftVerschillendeRondes) {
                if (promotingA) _updateRondesDOM(rijB, riderB.entry_id, oudeRondesB); // herstel verliezer B
                else            _updateRondesDOM(rij,  entryIdA,        oudeRondesA); // herstel verliezer A
            }
            _liveToast('⚠ Wisselen mislukt: ' + err.message, 'error');
        }
    });

    _liveHerbereken(idx);
    el('live-btn-opslaan-' + idx)?.addEventListener('click', () => _liveOpslaanRit(idx));

    // Afvalkoers-paneel events binden (alleen actief als rit afvalkoers is)
    _afvalBindKnoppen(idx);

    // Race-type selector (lange-afstand heats)
    el('live-race-type-' + idx)?.addEventListener('change', async function () {
        const nieuweType = this.value;
        const rit        = _liveRitten[idx];
        if (!rit?.heat_id) return;

        // UI direct bijwerken
        const pkPanel = el('live-pk-panel-' + idx);
        const avPanel = el('live-av-panel-' + idx);
        if (pkPanel) pkPanel.hidden = nieuweType !== 'puntenkoers';
        if (avPanel) avPanel.hidden = nieuweType !== 'afvalkoers';
        // Bij wisselen NAAR afvalkoers: paneel-inhoud opbouwen + handlers binden.
        // Bij wisselen WEG van afvalkoers: state opschonen zodat eventuele restanten
        // niet onbedoeld worden meegestuurd bij opslaan.
        if (nieuweType === 'afvalkoers') {
            const tijdelijkeRit = { ..._liveRitten[idx], race_type: 'afvalkoers' };
            if (avPanel) {
                avPanel.outerHTML = _bouwAfvalPaneel(tijdelijkeRit, idx, true);
                _afvalBindKnoppen(idx);
            }
        } else {
            delete _afvalState[idx];
        }

        // Opslaan in DB
        try {
            const res = await fetch('api/live.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'set_race_type', heat_id: rit.heat_id, race_type: nieuweType }),
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            rit.race_type = nieuweType;
        } catch (err) {
            _liveToast('⚠ Race-type opslaan mislukt: ' + err.message, 'error');
            // Terugdraaien in selector
            this.value = rit.race_type ?? 'inline';
            if (pkPanel) pkPanel.hidden = (rit.race_type ?? 'inline') !== 'puntenkoers';
            if (avPanel) avPanel.hidden = (rit.race_type ?? 'inline') !== 'afvalkoers';
        }
    });

    // PK: gedeelde helpers
    const _kaartEl   = document.querySelector(`.live-carousel-card[data-idx="${idx}"]`);
    const _pkPanelEl = _kaartEl?.querySelector('.live-pk-panel');

    // Deselect alles + disable invoer + disable +X
    const _pkReset = () => {
        _pkPanelEl?.querySelectorAll('.live-pk-snr-btn').forEach(b => b.classList.remove('live-pk-snr-actief'));
        [1,2,3].forEach(n => { const pb = el(`live-pk-plus${n}-${idx}`); if (pb) pb.disabled = true; });
        const inp = el('live-pk-invoer-inp-' + idx);
        const ok  = el('live-pk-invoer-ok-'  + idx);
        const nm  = el('live-pk-invoer-naam-'+ idx);
        if (inp) { inp.disabled = true; inp.value = ''; }
        if (ok)  ok.disabled = true;
        if (nm)  nm.textContent = '— selecteer een nummer —';
    };

    // Selecteer een knop (uit óf het top-grid óf het ondergrid)
    const _pkSelecteer = (btn) => {
        _pkPanelEl?.querySelectorAll('.live-pk-snr-btn').forEach(b => b.classList.remove('live-pk-snr-actief'));
        btn.classList.add('live-pk-snr-actief');
        [1,2,3].forEach(n => { const pb = el(`live-pk-plus${n}-${idx}`); if (pb) pb.disabled = false; });
        const entryId   = btn.dataset.pkEntry;
        const hiddenInp = _kaartEl?.querySelector(`.live-punten-inp[data-pk-entry="${entryId}"]`);
        const naamEl    = el('live-pk-invoer-naam-' + idx);
        const invoerInp = el('live-pk-invoer-inp-'  + idx);
        const okBtn     = el('live-pk-invoer-ok-'   + idx);
        if (naamEl)    naamEl.textContent            = btn.dataset.pkNaam || '';
        if (invoerInp) { invoerInp.disabled = false; invoerInp.dataset.activeEntry = entryId; invoerInp.value = hiddenInp?.value ?? ''; }
        if (okBtn)     okBtn.disabled = false;
        invoerInp?.focus();
        invoerInp?.select();
    };

    // Event-delegatie op het hele panel — werkt ook voor later dynamisch toegevoegde knoppen
    _pkPanelEl?.addEventListener('click', e => {
        const btn = e.target.closest('.live-pk-snr-btn');
        if (!btn || btn.disabled) return;
        if (btn.classList.contains('live-pk-snr-actief')) _pkReset();
        else _pkSelecteer(btn);
    });

    // +3 / +2 / +1 — werkt op elke actieve selectie (top of onder)
    [3, 2, 1].forEach(extra => {
        el(`live-pk-plus${extra}-${idx}`)?.addEventListener('click', () => {
            const actiefBtn = _pkPanelEl?.querySelector('.live-pk-snr-actief');
            if (!actiefBtn) return;
            const entryId = actiefBtn.dataset.pkEntry;
            const hidden  = _kaartEl?.querySelector(`.live-punten-inp[data-pk-entry="${entryId}"]`);
            const nieuw   = (parseFloat(hidden?.value || '0') || 0) + extra;
            if (hidden) hidden.value = nieuw;
            _pkUpdateBadges(idx, entryId, nieuw);
            _pkReset();   // deselect + disable na invoer
            _liveUpdatePuntenBadges(idx);
            _liveOngeslagen = true;
        });
    });

    // Invoer-balk: ✓ of Enter bevestigt — geen auto-advance, operator selecteert zelf
    el('live-pk-invoer-ok-' + idx)?.addEventListener('click', () => _livePkInvoerBevestig(idx));
    el('live-pk-invoer-inp-' + idx)?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); _livePkInvoerBevestig(idx); }
    });

    // PK-punten worden nu meegenomen in de algemene Opslaan-knop
    // (_liveOpslaanRit). De aparte "Punten opslaan"-knop is verwijderd.

    // Import-knop: toggle panel + vul mappenlijst
    el('live-btn-import-'     + idx)?.addEventListener('click',  () => _liveImportToggle(idx));
    el('live-import-map-'     + idx)?.addEventListener('change', () => _liveImportMapGekozen(idx));
    el('live-import-mapfilter-'+ idx)?.addEventListener('input',  () => _liveImportMapFilter(idx));
    el('live-import-sel-'     + idx)?.addEventListener('change', () => _liveImportPreview(idx));
    el('live-import-sort-naam-' + idx)?.addEventListener('click', () => _liveImportFileSort(idx, 'naam'));
    el('live-import-sort-nieuw-'+ idx)?.addEventListener('click', () => _liveImportFileSort(idx, 'nieuw'));
    el('live-import-laad-'    + idx)?.addEventListener('click',  () => _liveImportLaad(idx));
}

// _livePuntenOpslaan is verwijderd — puntenkoers-punten worden nu meegenomen
// in _liveOpslaanRit samen met tijden en sancties, zodat er één Opslaan-knop is.

// ── Afvalkoers helpers ────────────────────────────────────────────────────────

// Bouwt de afval-state voor een rit. Bij eerste aanroep: reconstrueer afgevallen-stack
// uit rit.rijders[].afval_rang (DB-data). Bij latere aanroepen: bestaande state
// behouden — anders zouden lokale wijzigingen (Set/By Fault/Undo) bij elke rerender
// gewist worden.
function _afvalInitVoorRit(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit) return null;

    if (_afvalState[ritIdx]) {
        // State bestaat al — niet overschrijven met (mogelijk verouderde) DB-data.
        return _afvalState[ritIdx];
    }

    // Eerste init: bouw afgevallen-stack uit DB-data.
    // Sortering: afval_rang DESC (eerste afvaller = hoogste rang, links in de UI-rij).
    // Bij init kunnen we niet onderscheiden tussen Set en By Decision uit DB-
    // data alleen (beide hebben sanctie=null). DQ-TF herkennen we wel als Fault.
    // Voor By Decision is dat helaas een verlies bij refresh — we beschouwen
    // sanctie=null als Set, en alleen DQ-TF/DNS als buiten_schema.
    const afgevallen = (rit.rijders || [])
        .filter(r => r.afval_rang != null)
        .map(r => ({
            entry_id: r.entry_id,
            plek:     r.afval_rang,
            sanctie:  r.sanctie || null,
            buiten_schema: (r.sanctie === 'DQ-TF' || r.sanctie === 'DNS'),
        }))
        .sort((a, b) => b.plek - a.plek);

    _afvalState[ritIdx] = {
        afgevallen,
        voorlopig_2de:  [],
        voorlopig_1ste: [],
        geselecteerd:   [],
    };
    return _afvalState[ritIdx];
}

// Geeft entry_ids terug van rijders die NOG IN KOERS zijn:
// niet in afgevallen-stack en niet in voorlopig_2de/voorlopig_1ste.
function _afvalNogInKoersIds(ritIdx) {
    const rit = _liveRitten[ritIdx];
    const st  = _afvalState[ritIdx];
    if (!rit || !st) return [];
    const uit = new Set([
        ...st.afgevallen.map(a => a.entry_id),
        ...st.voorlopig_2de,
        ...st.voorlopig_1ste,
    ]);
    return (rit.rijders || []).map(r => r.entry_id).filter(id => !uit.has(id));
}

// Volgende beschikbare afval-plek = aantal-nog-in-koers (na voorlopige toewijzing weg).
// Bij ex-aequo K rijders: gedeelde plek = (volgende-vrij) - K + 1, daarna volgende-vrij - K.
function _afvalVolgendePlek(ritIdx) {
    return _afvalNogInKoersIds(ritIdx).length;
}

// Pas voorlopige '2de'-selectie toe: rijders krijgen tijdelijk de huidige laatste
// vrije plekken. Bij hernieuwde aanroep worden vorige voorlopig-rijders weer
// vrijgegeven (overschrijven), zodat een verkeerde keuze eenvoudig hersteld is.
function _afvalKies2de(ritIdx) {
    const st = _afvalState[ritIdx];
    if (!st || st.geselecteerd.length === 0) return;
    st.voorlopig_2de = [...st.geselecteerd];
    st.geselecteerd  = [];
}

function _afvalKies1ste(ritIdx) {
    const st = _afvalState[ritIdx];
    if (!st || st.geselecteerd.length === 0) return;
    st.voorlopig_1ste = [...st.geselecteerd];
    st.geselecteerd   = [];
}

// Set: voorlopige selecties definitief maken (ronde afgesloten).
// Plek-toekenning: 2de-groep krijgt eerst (slechtste plekken), dan 1ste-groep.
function _afvalSet(ritIdx) {
    const st = _afvalState[ritIdx];
    if (!st || (st.voorlopig_2de.length === 0 && st.voorlopig_1ste.length === 0)) return;

    const nogVrij = _afvalNogInKoersIds(ritIdx).length
                  + st.voorlopig_2de.length + st.voorlopig_1ste.length;
    // 2de-groep: K rijders, gedeelde plek = nogVrij - K + 1
    const k2 = st.voorlopig_2de.length;
    const plek2 = nogVrij - k2 + 1;
    const ronde = [];
    st.voorlopig_2de.forEach(eid => {
        ronde.push({ entry_id: eid, plek: plek2, sanctie: null });
    });
    // 1ste-groep: K1 rijders, gedeelde plek = (nogVrij - k2) - K1 + 1
    const k1 = st.voorlopig_1ste.length;
    const plek1 = (nogVrij - k2) - k1 + 1;
    st.voorlopig_1ste.forEach(eid => {
        ronde.push({ entry_id: eid, plek: plek1, sanctie: null });
    });
    // Voeg toe aan afgevallen-stack
    ronde.forEach(item => st.afgevallen.unshift(item));
    st.voorlopig_2de  = [];
    st.voorlopig_1ste = [];
    st.geselecteerd   = [];

    // Auto-rondes toekennen op basis van heat-config (indien ingevuld).
    _afvalAssignRondes(ritIdx, ronde);
}

// By-fault: jury wijst rijder(s) aan voor afvalling met DQ-TF (overtreding),
// krijgen huidige laatste vrije plek (of bij meerdere: ex-aequo gedeelde plek).
// Buiten het schema — verkleint setTeElim. Vraagt rondebord.
function _afvalByFault(ritIdx) {
    const st = _afvalState[ritIdx];
    if (!st || st.geselecteerd.length === 0) return;
    const nogVrij = _afvalNogInKoersIds(ritIdx).length;
    const k = st.geselecteerd.length;
    const plek = nogVrij - k + 1;
    const ronde = st.geselecteerd.map(eid => ({
        entry_id: eid, plek, sanctie: 'DQ-TF', buiten_schema: true,
    }));
    ronde.forEach(item => st.afgevallen.unshift(item));
    st.geselecteerd = [];

    _afvalAssignRondesBuitenSchema(ritIdx, ronde, 'fault');
}

// By Decision: rijder(s) door jury uit koers gehaald zonder overtreding —
// val, gedubbeld worden, opgeven. GEEN sanctie, wél buiten het schema,
// gedeelde plek aan onderkant. Verkleint setTeElim. Vraagt rondebord.
function _afvalByDecision(ritIdx) {
    const st = _afvalState[ritIdx];
    if (!st || st.geselecteerd.length === 0) return;
    const nogVrij = _afvalNogInKoersIds(ritIdx).length;
    const k = st.geselecteerd.length;
    const plek = nogVrij - k + 1;
    const ronde = st.geselecteerd.map(eid => ({
        entry_id: eid, plek, sanctie: null, buiten_schema: true,
    }));
    ronde.forEach(item => st.afgevallen.unshift(item));
    st.geselecteerd = [];

    _afvalAssignRondesBuitenSchema(ritIdx, ronde, 'decision');
}

// Kent automatisch ronde-getallen toe aan zojuist afgevallen rijders, op
// basis van de heat-config. Schrijft naar zowel rit.rijders[].rondes als
// het DOM-input (.live-rondes-inp) zodat opslaan + linker panel correct
// updaten. Doet niets als config niet ingevuld is.
// Schrijft een ronde-getal naar zowel rit.rijders[].rondes als de DOM-input.
function _afvalSchrijfRondes(rit, entryId, ronde) {
    const r = rit.rijders.find(x => x.entry_id === entryId);
    if (r) r.rondes = ronde;
    [`[data-entry="${entryId}"]`, `[data-panel-entry="${entryId}"]`].forEach(sel => {
        document.querySelectorAll(sel).forEach(rij => {
            const inp = rij.querySelector('.live-rondes-inp');
            if (inp) inp.value = String(ronde);
            if (rij.dataset) rij.dataset.rondes = String(ronde);
        });
    });
}

// Hercompute het schema voor alle Set-items in de stack op basis van het
// huidige aantal nog-te-elimineren-via-Set. Doet niets als cfg incompleet.
// Wordt aangeroepen na elke Set én elke ByFault, want bij ByFault verschuift
// het schema (één scheduled-slot minder nodig → dubbel--).
function _afvalHercomputeSetRondes(ritIdx) {
    const rit = _liveRitten[ritIdx];
    const st  = _afvalState[ritIdx];
    if (!rit || !st) return;
    const cfg = _afvalCfgGet(rit.heat_id);
    if (!cfg || !cfg.eerste_afval || !cfg.totaal_ronden || !cfg.eindsprint) return;

    // Aantal Set-afvallers TOTAAL die het schema moet leveren =
    // totaal te elimineren = (starters - eindsprint) - aantal_byfaults.
    // Van die N pakt het schema de eerste N posities (afvalpos 1..N).
    const totaal = (rit.rijders || []).length;
    const buiten = st.afgevallen.filter(a => a.buiten_schema === true).length;
    const setCount = Math.max(0, totaal - parseInt(cfg.eindsprint) - buiten);
    if (setCount === 0) return;

    // Set-items in volgorde van toevoeging (oudste = afvalpos 1).
    // Stack heeft newest at index 0, dus we lopen van eind naar begin.
    const setItems = [];
    for (let i = st.afgevallen.length - 1; i >= 0; i--) {
        const a = st.afgevallen[i];
        if (a.buiten_schema === true) continue;
        setItems.push(a); // oudste eerst
    }
    setItems.forEach((a, idx) => {
        const ronde = _afvalRondeVoorPositie(idx + 1, cfg, setCount);
        if (ronde == null) return;
        _afvalSchrijfRondes(rit, a.entry_id, ronde);
    });
}

// Wordt aangeroepen na _afvalSet — alleen het schema hercomputen.
function _afvalAssignRondes(ritIdx, _items) {
    _afvalHercomputeSetRondes(ritIdx);
}

// Vraagt rondebord van de buiten-schema uitval (in-house pop-up, async),
// schrijft ronde-getal voor de items, en hercomputeert het schema.
async function _afvalAssignRondesBuitenSchema(ritIdx, items, oorzaak) {
    const rit = _liveRitten[ritIdx];
    const st  = _afvalState[ritIdx];
    if (!rit || !st || !items.length) return;
    const cfg = _afvalCfgGet(rit.heat_id) || {};
    const tr  = parseInt(cfg.totaal_ronden) || 0;

    let bord = null;
    if (tr) {
        const setCount = _afvalSetTeElimineren(ritIdx);
        const setGedaan = st.afgevallen.filter(a =>
            a.buiten_schema !== true && !items.includes(a)).length;
        const next = _afvalRondeVoorPositie(setGedaan + 1, cfg, setCount);
        const defaultBord = next ? (tr - next + 1) : '';
        const titel = oorzaak === 'fault'
            ? 'By Fault — overtreding (DQ-TF)'
            : 'By Decision — val / dubbel / opgeven';
        bord = await _afvalBordPrompt(titel, tr, defaultBord);
    }
    if (bord != null) {
        const ronde = tr - bord;
        items.forEach(item => _afvalSchrijfRondes(rit, item.entry_id, ronde));
    }
    _afvalHercomputeSetRondes(ritIdx);
    _afvalRerenderPaneel(ritIdx);
}

// In-house prompt voor rondebord-invoer. Promise → integer 1..max, of null.
// Hergebruikt de modal-* klassen uit toonBevestigDialog voor visuele consistentie.
function _afvalBordPrompt(titel, max, defaultValue) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <span class="modal-icon">🏁</span>
                    <span>${escHtml(titel)}</span>
                </div>
                <div class="modal-body">
                    <label class="live-av-bord-prompt">
                        <span>Wat staat er op het rondebord?</span>
                        <input type="number" id="av-bord-inp" min="1" max="${max}"
                               value="${escHtml(String(defaultValue ?? ''))}"
                               class="inp" autocomplete="off">
                        <small>tussen 1 en ${max}</small>
                    </label>
                </div>
                <div class="modal-knoppen">
                    <button class="modal-btn modal-annuleer">Annuleren</button>
                    <button class="modal-btn modal-doorgaan">OK</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const inp = overlay.querySelector('#av-bord-inp');

        const sluit = (val) => {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            resolve(val);
        };
        const ok = () => {
            const n = parseInt(inp.value);
            if (!isNaN(n) && n >= 1 && n <= max) sluit(n);
            else { inp.classList.add('inp-fout'); inp.focus(); inp.select(); }
        };
        const onKey = e => {
            if (e.key === 'Escape') sluit(null);
            if (e.key === 'Enter')  { e.preventDefault(); ok(); }
        };

        overlay.querySelector('.modal-annuleer').addEventListener('click', () => sluit(null));
        overlay.querySelector('.modal-doorgaan').addEventListener('click', ok);
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(null); });
        document.addEventListener('keydown', onKey);
        setTimeout(() => { inp.focus(); inp.select(); }, 0);
    });
}

// Releast één afgevallen rijder terug naar 'nog in koers'. Hercomputeert
// het Set-schema (de bevrijde positie schuift weer terug). Bedoeld voor het
// corrigeren van een verkeerd aangeklikte rijder bij By Fault/Decision.
function _afvalRelease(ritIdx, entryId) {
    const st  = _afvalState[ritIdx];
    const rit = _liveRitten[ritIdx];
    if (!st || !rit) return;
    const idx = st.afgevallen.findIndex(a => a.entry_id === entryId);
    if (idx < 0) return;
    st.afgevallen.splice(idx, 1);

    // Rondes wissen: rit.rijders + DOM-input
    const r = rit.rijders.find(x => x.entry_id === entryId);
    if (r) r.rondes = null;
    [`[data-entry="${entryId}"]`, `[data-panel-entry="${entryId}"]`].forEach(sel => {
        document.querySelectorAll(sel).forEach(rij => {
            const inp = rij.querySelector('.live-rondes-inp');
            if (inp) inp.value = '';
            if (rij.dataset) rij.dataset.rondes = '';
        });
    });

    // Schema voor resterende Set-items hercomputen.
    _afvalHercomputeSetRondes(ritIdx);
}

// Toggle een rijder in de huidige selectie.
function _afvalToggleSelectie(ritIdx, entryId) {
    const st = _afvalState[ritIdx];
    if (!st) return;
    const idx = st.geselecteerd.indexOf(entryId);
    if (idx >= 0) st.geselecteerd.splice(idx, 1);
    else          st.geselecteerd.push(entryId);
}

// Sync een sanctie-wijziging in de heat-tabel met de afval-state. Specifiek voor
// DNS: rijder is niet gestart, wordt direct geklasseerd op de huidige laagste plek.
// Andere wist-tijd-sancties (DNF, DQ-*) worden NIET automatisch verwerkt — die
// horen via de By Fault-knop of via expliciete actie te lopen.
function _afvalSyncSanctie(ritIdx, entryId, nieuweSanctie) {
    const st = _afvalState[ritIdx];
    if (!st) return;

    const reedsAfgevallen = st.afgevallen.find(a => a.entry_id === entryId);

    if (nieuweSanctie === 'DNS') {
        if (reedsAfgevallen) {
            // Al in stack — alleen sanctie bijwerken
            reedsAfgevallen.sanctie = 'DNS';
            return;
        }
        // Niet meer geselecteerd of voorlopig — opschonen
        st.geselecteerd   = st.geselecteerd.filter(id => id !== entryId);
        st.voorlopig_2de  = st.voorlopig_2de.filter(id => id !== entryId);
        st.voorlopig_1ste = st.voorlopig_1ste.filter(id => id !== entryId);
        // Toevoegen op huidige laagste plek
        const plek = _afvalNogInKoersIds(ritIdx).length;
        st.afgevallen.unshift({ entry_id: entryId, plek, sanctie: 'DNS', buiten_schema: true });
        return;
    }

    // Sanctie weggehaald of gewijzigd weg van DNS → rijder uit afgevallen-stack
    // halen ALLEEN als hij eerder via DNS-flow is toegevoegd (sanctie='DNS' in stack).
    if (reedsAfgevallen && reedsAfgevallen.sanctie === 'DNS') {
        st.afgevallen = st.afgevallen.filter(a => a.entry_id !== entryId);
    }
}

// ── Afvalkoers-config (localStorage per heat-id) ─────────────────────────────
// Heat-config bevat: totaal_ronden, eerste_afval, aantal_dubbel, eindsprint.
// Wordt door admin in de Live-UI ingevuld (na DNS-check, want pas op start-
// moment weten we hoeveel deelnemers werkelijk staan). Niet in DB opgeslagen
// — de afval-rangen + per-rijder-rondes (in `results.rondes`) zijn de echte
// permanente data; deze cfg is alleen UI-help om automatisch ronde-getallen
// voor te stellen bij elke nieuwe afvalling.
function _afvalCfgKey(heatId) { return 'afvalcfg_' + heatId; }

function _afvalCfgGet(heatId) {
    if (!heatId) return null;
    try {
        const raw = localStorage.getItem(_afvalCfgKey(heatId));
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

function _afvalCfgSet(heatId, cfg) {
    if (!heatId) return;
    try {
        if (cfg) localStorage.setItem(_afvalCfgKey(heatId), JSON.stringify(cfg));
        else     localStorage.removeItem(_afvalCfgKey(heatId));
    } catch { /* quotum bereikt — best-effort */ }
}

// Aantal vaste laatste afvalrondes (rondebord 3, 2, 1) — altijd 1 afvaller
// per ronde, ongeacht het globale interval.
const _AFVAL_LAATSTE_VAST = 3;

// cfg.eerste_afval is een RONDEBORD-nummer = 'rondes te gaan' bij eerste
// afvalling. Schema telt af in borden: ea, ea-iv, ea-2*iv, … > 3,
// daarna vaste borden 3, 2, 1. Conversie naar "voltooide rondes":
// rondes = totaal_ronden − bord. Voorbeeld: totaal=25, bord 21 → 4 rondes
// voltooid (rijder valt aan einde van de bord-21-ronde, voor finish-lijn);
// bord 1 → 24 rondes voltooid (laatste afvalling vóór de eindsprint).
function _afvalSchema(cfg, teElimineren) {
    if (!cfg) return [];
    const ea = parseInt(cfg.eerste_afval)  || 0;  // rondebord
    const tr = parseInt(cfg.totaal_ronden) || 0;
    const iv = parseInt(cfg.interval)      || 1;
    if (!ea || !tr || !teElimineren) return [];

    teElimineren = Math.max(0, teElimineren);
    if (teElimineren === 0) return [];

    // Eerste-fase borden: ea, ea-iv, ea-2*iv, … > _AFVAL_LAATSTE_VAST (= > 3)
    const eersteBorden = [];
    for (let b = ea; b > _AFVAL_LAATSTE_VAST; b -= iv) eersteBorden.push(b);
    const eersteAantal = eersteBorden.length;

    // Vaste fase: borden 3, 2, 1
    const vastBorden = [];
    for (let b = _AFVAL_LAATSTE_VAST; b >= 1; b--) vastBorden.push(b);

    // Aantal afvallers per fase
    const vastAantal    = Math.min(vastBorden.length, teElimineren);
    const eersteTeElim  = teElimineren - vastAantal;
    const dubbel        = Math.max(0, eersteTeElim - eersteAantal);

    const arr = [];
    let dubbelLeft = dubbel;
    for (const b of eersteBorden) {
        const n = (dubbelLeft > 0) ? 2 : 1;
        for (let i = 0; i < n; i++) arr.push(tr - b);
        if (dubbelLeft > 0) dubbelLeft--;
    }
    for (const b of vastBorden) {
        if (arr.length >= teElimineren) break;
        arr.push(tr - b);
    }
    return arr;
}

// Overzicht-info voor de afgeleide-balk in de cfg-modal.
function _afvalAfgeleidDubbel(cfg, teElimineren) {
    const leeg = { dubbel: 0, afvalrondes: 0, teElimineren: 0, ok: false,
                   capaciteit: 0, eersteAantal: 0, vastAantal: 0, eersteBorden: [] };
    if (!cfg) return leeg;
    const ea = parseInt(cfg.eerste_afval)  || 0;
    const tr = parseInt(cfg.totaal_ronden) || 0;
    const iv = parseInt(cfg.interval)      || 1;
    if (!ea || !tr || !teElimineren) return leeg;

    teElimineren = Math.max(0, teElimineren);
    const eersteBorden = [];
    for (let b = ea; b > _AFVAL_LAATSTE_VAST; b -= iv) eersteBorden.push(b);
    const eersteAantal = eersteBorden.length;
    const vastAantal   = Math.min(_AFVAL_LAATSTE_VAST, teElimineren);
    const eersteTeElim = teElimineren - vastAantal;
    const dubbel       = Math.max(0, eersteTeElim - eersteAantal);
    const capaciteit   = 2 * eersteAantal + _AFVAL_LAATSTE_VAST;
    const afvalrondes  = eersteAantal + _AFVAL_LAATSTE_VAST;
    const ok = teElimineren <= capaciteit && dubbel <= eersteAantal && ea > _AFVAL_LAATSTE_VAST && ea <= tr;
    return { dubbel, afvalrondes, teElimineren, ok, capaciteit, eersteAantal, vastAantal, eersteBorden };
}

// Bereken ronde-volgnummer voor een afval-positie via het schema.
// teElimineren = aantal Set-afvallers nog te plannen (excl. ByFaults).
function _afvalRondeVoorPositie(afvalPositie, cfg, teElimineren) {
    const arr = _afvalSchema(cfg, teElimineren || 0);
    if (afvalPositie < 1 || afvalPositie > arr.length) return null;
    return arr[afvalPositie - 1];
}

// Helper: aantal scheduled-Set afvallers nog te plannen, gegeven de
// huidige stack. ByFaults (sanctie='DQ-TF') én DNS tellen NIET als
// scheduled — die zijn al "gratis" uit de koers en verkorten het schema.
function _afvalSetTeElimineren(ritIdx) {
    const rit = _liveRitten[ritIdx];
    const st  = _afvalState[ritIdx];
    if (!rit) return 0;
    const cfg = _afvalCfgGet(rit.heat_id) || {};
    const es  = parseInt(cfg.eindsprint) || 0;
    if (!es) return 0;
    const totaal = (rit.rijders || []).length;
    const buitenSchema = (st?.afgevallen || [])
        .filter(a => a.buiten_schema === true).length;
    return Math.max(0, totaal - es - buitenSchema);
}

// Bouwt de HTML voor het afvalkoers-paneel. Wordt aangeroepen vanuit _liveBouwKaart.
// 'tonen' = false → paneel hidden (niet-afvalkoers ritten).
function _bouwAfvalPaneel(rit, idx, tonen) {
    const hiddenAttr = tonen ? '' : ' hidden';
    if (!tonen) {
        return `<div class="live-av-panel" id="live-av-panel-${idx}"${hiddenAttr}></div>`;
    }

    _afvalInitVoorRit(idx);
    const st = _afvalState[idx];
    if (!st) return `<div class="live-av-panel" id="live-av-panel-${idx}"></div>`;

    const rijderMap = new Map((rit.rijders || []).map(r => [r.entry_id, r]));
    const nogIds   = _afvalNogInKoersIds(idx);
    const nogVrij  = nogIds.length;
    const k2       = st.voorlopig_2de.length;
    const k1       = st.voorlopig_1ste.length;
    const plek2    = k2 > 0 ? (nogVrij + k2 + k1) - k2 + 1 : null; // tijdelijke plek voor 2de-groep
    const plek1    = k1 > 0 ? (nogVrij + k1) - k1 + 1 : null;       // tijdelijke plek voor 1ste-groep

    // Heat-config strook bovenin (bewaard in localStorage, default leeg).
    // Toont een 1-regel-samenvatting met ✎-knop voor wijzigen. De cfg helpt
    // om automatisch ronde-getallen voor te stellen bij Set/By-Fault.
    const cfg = _afvalCfgGet(rit.heat_id) || {};
    const cfgIngevuld = !!(cfg.totaal_ronden && cfg.eerste_afval && cfg.eindsprint);
    const _afInfo = cfgIngevuld ? _afvalAfgeleidDubbel(cfg, _afvalSetTeElimineren(idx)) : null;
    const cfgSamenvatting = cfgIngevuld
        ? `${cfg.totaal_ronden} ronden · vanaf bord ${cfg.eerste_afval}`
          + (parseInt(cfg.interval) === 2 ? ' · om-de-ronde' : ' · elke ronde')
          + (_afInfo && _afInfo.dubbel > 0 ? ` · ${_afInfo.dubbel}× dubbel` : '')
          + ` · eindsprint ${cfg.eindsprint}`
        : '<span class="live-av-cfg-onbekend">⚠ niet ingesteld — rondes worden niet automatisch toegekend</span>';
    const cfgHtml = `<div class="live-av-cfg">
        <span class="live-av-cfg-icon">⚙</span>
        <span class="live-av-cfg-tekst">${cfgSamenvatting}</span>
        <button class="live-av-cfg-edit" data-act="cfg" type="button" title="Heat-config wijzigen">✎</button>
    </div>`;

    // Header met tellers
    const totaalDeelnemers = (rit.rijders || []).length;
    // Volgende geplande Set-positie = aantal Set-items reeds afgevallen + 1
    const setGedaan = st.afgevallen.filter(a => a.buiten_schema !== true).length;
    const setTeElim = _afvalSetTeElimineren(idx);
    const autoRonde = _afvalRondeVoorPositie(setGedaan + 1, cfg, setTeElim);
    const trCfg = parseInt(cfg.totaal_ronden) || 0;
    const autoBord = (autoRonde != null && trCfg) ? (trCfg - autoRonde) : null;

    // Dubbel-resterend: hoeveel borden nog 2-tegelijk vallen (op basis van
    // huidige schema). Eerste 2*dubbel posities zijn dubbel; resterend bord
    // = ceil((2*dubbel - setGedaan) / 2).
    let dubbelOver = null;
    if (cfgIngevuld && _afInfo) {
        const dubbelPos = 2 * _afInfo.dubbel;
        const restPos = Math.max(0, dubbelPos - setGedaan);
        dubbelOver = Math.ceil(restPos / 2);
    }

    const hdr = `<div class="live-av-header">
        <span class="live-av-tel"><b>Nog in koers:</b> ${nogVrij}</span>
        <span class="live-av-tel"><b>Volgende plek:</b> ${nogVrij > 0 ? nogVrij : '—'}</span>
        ${autoRonde != null ? `<span class="live-av-tel"><b>Volgende ronde:</b> r.${autoRonde}</span>` : ''}
        ${autoBord != null ? `<span class="live-av-tel"><b>Rondebord:</b> ${autoBord}</span>` : ''}
        ${dubbelOver != null && dubbelOver > 0
            ? `<span class="live-av-tel live-av-tel-dubbel"><b>Dubbel over:</b> ${dubbelOver}×</span>`
            : ''}
    </div>`;

    // Afgevallen-rij (zelf gesetste afval-kaartjes) — toont nu ook het
    // rondes-getal per rijder (uit rit.rijders[].rondes).
    const afgHtml = st.afgevallen.length === 0
        ? `<div class="live-av-leeg">Nog geen afvallingen.</div>`
        : st.afgevallen.map(a => {
            const r = rijderMap.get(a.entry_id);
            const snr = r?.startnummer ?? '?';
            const rondes = r?.rondes;
            const isFault    = a.sanctie === 'DQ-TF';
            const isDecision = a.buiten_schema === true && !isFault;
            const cls = isFault    ? ' live-av-fault'
                      : isDecision ? ' live-av-decision'
                      : '';
            const lbl = isFault    ? `<span class="live-av-faultlbl">DQ-TF</span>`
                      : isDecision ? `<span class="live-av-declbl">uit</span>`
                      : '';
            return `<button class="live-av-kaart-uit${cls}" data-release="${a.entry_id}" `
                + `type="button" title="Klik om terug te zetten in koers">` +
                `<span class="live-av-snr">${escHtml(String(snr))}</span>` +
                `<span class="live-av-plek">#${a.plek}</span>` +
                (rondes != null ? `<span class="live-av-rondes">r.${rondes}</span>` : '') +
                lbl +
                `</button>`;
        }).join('');

    // Knoppen-rij
    const btn2deDis = (st.voorlopig_2de.length > 0 || st.geselecteerd.length === 0) ? ' disabled' : '';
    const btn1stDis = (st.voorlopig_1ste.length > 0 || st.geselecteerd.length === 0) ? ' disabled' : '';
    const btnFaultDis    = st.geselecteerd.length === 0 ? ' disabled' : '';
    const btnDecisionDis = st.geselecteerd.length === 0 ? ' disabled' : '';
    const btnSetDis = (st.voorlopig_2de.length === 0 && st.voorlopig_1ste.length === 0) ? ' disabled' : '';

    const knoppenHtml = `<div class="live-av-knoppen">
        <button class="live-av-btn live-av-btn-fault"    data-act="fault"${btnFaultDis}>🚩 By Fault</button>
        <button class="live-av-btn live-av-btn-decision" data-act="decision"${btnDecisionDis}>⨯ By Decision</button>
        <button class="live-av-btn live-av-btn-set"      data-act="set"${btnSetDis}>✓ Set</button>
        <span class="live-av-spacer"></span>
        <button class="live-av-btn live-av-btn-1ste" data-act="1ste"${btn1stDis}>1ste</button>
        <button class="live-av-btn live-av-btn-2de"  data-act="2de"${btn2deDis}>2de</button>
    </div>`;

    // Helper: format één rijder als "snr — naam"
    const fmtRijder = (eid) => {
        const r = rijderMap.get(eid);
        const snr = r?.startnummer ?? '?';
        const naam = r?.full_name ?? '';
        return naam ? `${snr} — ${escHtml(naam)}` : String(snr);
    };

    // Voorlopige rijen — alleen tonen als er iets in zit
    let voorlopigHtml = '';
    if (st.voorlopig_1ste.length > 0) {
        const items = st.voorlopig_1ste.map(eid =>
            `<div class="live-av-voorlopig-item">${fmtRijder(eid)}</div>`).join('');
        voorlopigHtml += `<div class="live-av-voorlopig-rij">
            <b>Voorlopig 1ste${plek1 != null ? ` (plek ${plek1})` : ''}:</b>
            ${items}
        </div>`;
    }
    if (st.voorlopig_2de.length > 0) {
        const items = st.voorlopig_2de.map(eid =>
            `<div class="live-av-voorlopig-item">${fmtRijder(eid)}</div>`).join('');
        voorlopigHtml += `<div class="live-av-voorlopig-rij">
            <b>Voorlopig 2de${plek2 != null ? ` (plek ${plek2})` : ''}:</b>
            ${items}
        </div>`;
    }

    // Nog-in-koers grid (klikbare startnummer-kaartjes), gesorteerd op
    // startnummer (numeriek oplopend) — eenvoudiger zoeken in een drukke heat.
    const nogIdsSorted = [...nogIds].sort((a, b) => {
        const sa = parseInt(rijderMap.get(a)?.startnummer) || 0;
        const sb = parseInt(rijderMap.get(b)?.startnummer) || 0;
        return sa - sb;
    });
    const nogHtml = nogIdsSorted.length === 0
        ? `<div class="live-av-leeg">Geen rijders meer over.</div>`
        : nogIdsSorted.map(eid => {
            const r = rijderMap.get(eid);
            const snr = r?.startnummer ?? '?';
            const isSel = st.geselecteerd.includes(eid);
            const selCls = isSel ? ' live-av-kaart-sel' : '';
            return `<button class="live-av-kaart${selCls}" data-entry="${eid}" type="button">` +
                `<span class="live-av-snr">${escHtml(String(snr))}</span>` +
                `</button>`;
        }).join('');

    // Geselecteerd-balk — startnummer + naam, één per regel als controle-referentie
    let selHtml;
    if (st.geselecteerd.length === 0) {
        selHtml = `<div class="live-av-sel-balk"><b>Geselecteerd:</b> —</div>`;
    } else {
        const items = st.geselecteerd.map(eid =>
            `<div class="live-av-sel-item">${fmtRijder(eid)}</div>`).join('');
        selHtml = `<div class="live-av-sel-balk"><b>Geselecteerd:</b>${items}</div>`;
    }

    return `<div class="live-av-panel" id="live-av-panel-${idx}">
        <div class="live-av-titel">🚫 Afvalkoers</div>
        ${cfgHtml}
        ${hdr}
        <div class="live-av-afgevallen-rij">${afgHtml}</div>
        ${knoppenHtml}
        ${voorlopigHtml}
        <div class="live-av-grid">${nogHtml}</div>
        ${selHtml}
    </div>`;
}

// Re-render alleen het afval-paneel binnen één heat-card (zonder hele carousel-rebuild).
function _afvalRerenderPaneel(idx) {
    const rit = _liveRitten[idx];
    if (!rit) return;
    const oude = el('live-av-panel-' + idx);
    if (!oude) return;
    const isAfvalkoers = (rit.race_type === 'afvalkoers');
    const nieuwe = _bouwAfvalPaneel(rit, idx, isAfvalkoers);
    oude.outerHTML = nieuwe;
    _afvalBindKnoppen(idx);
    // Heat-card rij-kleuren bijwerken zodat afgevallen rijders ook groen worden
    if (isAfvalkoers) _liveHerbereken(idx);
}

// Bind eventhandlers aan het afval-paneel van rit idx.
function _afvalBindKnoppen(idx) {
    const paneel = el('live-av-panel-' + idx);
    if (!paneel || paneel.hidden) return;

    // Klik op een nog-in-koers startnummer-kaart → toggle selectie
    paneel.querySelectorAll('.live-av-kaart').forEach(btn => {
        btn.addEventListener('click', () => {
            const eid = parseInt(btn.dataset.entry);
            if (!eid) return;
            _afvalToggleSelectie(idx, eid);
            _afvalRerenderPaneel(idx);
        });
    });

    // Klik op een afgevallen-kaart → direct terugzetten naar in-koers
    // (geen bevestiging — bij onterecht terugzetten gewoon opnieuw afvallen).
    paneel.querySelectorAll('.live-av-kaart-uit[data-release]').forEach(btn => {
        btn.addEventListener('click', () => {
            const eid = parseInt(btn.dataset.release);
            if (!eid) return;
            _afvalRelease(idx, eid);
            _liveOngeslagen = true;
            _afvalRerenderPaneel(idx);
        });
    });

    // Klik op actie-knop (1ste/2de/By Fault/Set/Undo)
    paneel.querySelectorAll('.live-av-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const act = btn.dataset.act;
            if (act === '2de')           _afvalKies2de(idx);
            else if (act === '1ste')     _afvalKies1ste(idx);
            else if (act === 'fault')    _afvalByFault(idx);
            else if (act === 'decision') _afvalByDecision(idx);
            else if (act === 'set')      _afvalSet(idx);
            _liveOngeslagen = true;
            _afvalRerenderPaneel(idx);
        });
    });

    // Klik op cfg ✎-knop → open config-modal
    const cfgBtn = paneel.querySelector('.live-av-cfg-edit');
    if (cfgBtn) {
        cfgBtn.addEventListener('click', () => _afvalOpenCfgModal(idx));
    }
}

// Modal voor heat-config (totaal_ronden, eerste_afval, aantal_dubbel, eindsprint).
// Wordt opgeroepen vanuit de ✎-knop in het afval-paneel. Save → localStorage +
// rerender; recompute rondes voor reeds afgevallen rijders die nog géén ronde
// hebben (zodat instellen achteraf alsnog correct rondes invult).
function _afvalOpenCfgModal(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit) return;
    const cfg = _afvalCfgGet(rit.heat_id) || {};
    const totDeeln = (rit.rijders || []).length;

    const overlay = document.createElement('div');
    overlay.className = 'live-av-cfg-overlay';
    overlay.innerHTML = `
        <div class="live-av-cfg-box">
            <h3>⚙ Afvalkoers-configuratie</h3>
            <p class="live-av-cfg-uitleg">
                ${totDeeln} starters in deze heat. Vul in hoe het afval-schema er
                uitziet zodat ronde-getallen automatisch worden toegekend.
            </p>
            <label class="live-av-cfg-veld">
                <span>Totaal aantal ronden</span>
                <input type="number" id="avcfg-totaal" min="1" value="${cfg.totaal_ronden ?? ''}" placeholder="bv. 18">
            </label>
            <label class="live-av-cfg-veld">
                <span>Eerste afval-rondebord</span>
                <input type="number" id="avcfg-eerste" min="4" value="${cfg.eerste_afval ?? ''}" placeholder="bv. 21">
                <small>rondes-te-gaan bij eerste afvalling (rondebord-nummer)</small>
            </label>
            <label class="live-av-cfg-veld">
                <span>Afval-interval</span>
                <select id="avcfg-interval">
                    <option value="1" ${parseInt(cfg.interval) !== 2 ? 'selected' : ''}>Elke ronde</option>
                    <option value="2" ${parseInt(cfg.interval) === 2 ? 'selected' : ''}>Om de ronde</option>
                </select>
            </label>
            <label class="live-av-cfg-veld">
                <span>Eindsprint (aantal rijders)</span>
                <input type="number" id="avcfg-eindsprint" min="0" value="${cfg.eindsprint ?? ''}" placeholder="bv. 4">
                <small>hoeveel rijders strijden om de eindsprint</small>
            </label>
            <div class="live-av-cfg-afgeleid" id="avcfg-afgeleid">
                <!-- live berekend overzicht -->
            </div>
            <div class="live-av-cfg-acties">
                <button class="btn-secondary" id="avcfg-annuleer">Annuleren</button>
                <button class="btn-danger"    id="avcfg-wis">Wissen</button>
                <button class="btn-primary"   id="avcfg-opslaan">Opslaan</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.querySelector('#avcfg-annuleer').addEventListener('click', () => overlay.remove());
    overlay.querySelector('#avcfg-wis').addEventListener('click', () => {
        if (!confirm('Heat-configuratie wissen?')) return;
        _afvalCfgSet(rit.heat_id, null);
        overlay.remove();
        _afvalRerenderPaneel(ritIdx);
    });
    overlay.querySelector('#avcfg-opslaan').addEventListener('click', () => {
        const nieuw = {
            totaal_ronden: parseInt(overlay.querySelector('#avcfg-totaal').value)     || null,
            eerste_afval:  parseInt(overlay.querySelector('#avcfg-eerste').value)     || null,
            interval:      parseInt(overlay.querySelector('#avcfg-interval').value)   || 1,
            eindsprint:    parseInt(overlay.querySelector('#avcfg-eindsprint').value) || null,
        };
        if (!nieuw.totaal_ronden || !nieuw.eerste_afval || !nieuw.eindsprint) {
            alert('Totaal aantal ronden, eerste afval-ronde en eindsprint zijn verplicht.');
            return;
        }
        // aantal_dubbel afleiden + opslaan (als hulpgetal voor de formule).
        // Modal toont ideaal-schema vóór ByFaults — gebruik totale starters.
        const teElimModal = Math.max(0, totDeeln - nieuw.eindsprint);
        const af = _afvalAfgeleidDubbel(nieuw, teElimModal);
        nieuw.aantal_dubbel = Math.max(0, af.dubbel);
        if (!af.ok) {
            const door = af.dubbel < 0
                ? `Te weinig afvalrondes: ${af.afvalrondes} beschikbaar, maar ${af.teElimineren} rijders te elimineren.`
                : `Te veel dubbele rondes nodig (${af.dubbel}) voor ${af.afvalrondes} afvalrondes.`;
            if (!confirm(`Let op: cfg klopt niet helemaal.\n${door}\nToch opslaan?`)) return;
        }
        _afvalCfgSet(rit.heat_id, nieuw);
        overlay.remove();

        // Recompute rondes voor reeds afgevallen rijders zonder ronde-getal
        const st = _afvalState[ritIdx];
        if (st && st.afgevallen.length > 0) {
            const totaalAfgevallen = st.afgevallen.length;
            st.afgevallen.forEach((a, stackIdx) => {
                const r = rit.rijders.find(x => x.entry_id === a.entry_id);
                if (!r || r.rondes != null) return;
                const afvalPos = totaalAfgevallen - stackIdx;
                const ronde = _afvalRondeVoorPositie(afvalPos, nieuw, teElimModal);
                if (ronde == null) return;
                r.rondes = ronde;
                [`[data-entry="${a.entry_id}"]`, `[data-panel-entry="${a.entry_id}"]`]
                    .forEach(sel => {
                        document.querySelectorAll(sel).forEach(rij => {
                            const inp = rij.querySelector('.live-rondes-inp');
                            if (inp) inp.value = String(ronde);
                            if (rij.dataset) rij.dataset.rondes = String(ronde);
                        });
                    });
            });
            _liveOngeslagen = true;
        }
        _afvalRerenderPaneel(ritIdx);
    });

    // Live-update van het afgeleide overzicht (afvalrondes / dubbel)
    const updateAfgeleid = () => {
        const tmp = {
            totaal_ronden: parseInt(overlay.querySelector('#avcfg-totaal').value)     || 0,
            eerste_afval:  parseInt(overlay.querySelector('#avcfg-eerste').value)     || 0,
            interval:      parseInt(overlay.querySelector('#avcfg-interval').value)   || 1,
            eindsprint:    parseInt(overlay.querySelector('#avcfg-eindsprint').value) || 0,
        };
        // Te elimineren = starters min de eindsprint-deelnemers (die rijden
        // de eindsprint en vallen niet af in het schema). Vóórheen werd
        // totDeeln direct doorgegeven → "11 elimineren / capaciteit 9"-fout.
        const teElim = Math.max(0, totDeeln - tmp.eindsprint);
        const af = _afvalAfgeleidDubbel(tmp, teElim);
        const wrap = overlay.querySelector('#avcfg-afgeleid');
        if (!tmp.totaal_ronden || !tmp.eerste_afval || !tmp.eindsprint) {
            wrap.innerHTML = '<i>Vul de velden in voor een berekening.</i>';
            wrap.classList.remove('av-fout', 'av-ok');
            return;
        }
        const ivLabel = tmp.interval === 2 ? 'om-de-ronde' : 'elke ronde';
        const teveelAfval = af.teElimineren > af.capaciteit;
        const dubbelTxt = teveelAfval
            ? `<span class="av-fout-tekst">capaciteit ${af.capaciteit}, tekort van ${af.teElimineren - af.capaciteit}</span>`
            : af.dubbel > 0
                ? `eerste <b>${af.dubbel}</b> bord${af.dubbel > 1 ? 'en' : ''} dubbel (2 afvallers)`
                : 'geen dubbele rondes nodig';
        const allBorden = [...af.eersteBorden, 3, 2, 1];
        wrap.innerHTML =
            `<b>${totDeeln}</b> starters → <b>${af.teElimineren}</b> elimineren · `
            + `${af.eersteAantal} bord${af.eersteAantal !== 1 ? 'en' : ''} (${ivLabel}) `
            + `+ <b>3</b> vaste laatste borden.<br>`
            + `Borden: ${allBorden.join(', ')}.<br>`
            + dubbelTxt + '.';
        wrap.classList.toggle('av-fout', !af.ok);
        wrap.classList.toggle('av-ok',    af.ok);
    };
    ['#avcfg-totaal','#avcfg-eerste','#avcfg-interval','#avcfg-eindsprint']
        .forEach(s => overlay.querySelector(s).addEventListener('input', updateAfgeleid));
    overlay.querySelector('#avcfg-interval').addEventListener('change', updateAfgeleid);
    updateAfgeleid();

    // Focus op eerste leeg veld
    const firstEmpty = ['#avcfg-totaal','#avcfg-eerste','#avcfg-eindsprint']
        .map(s => overlay.querySelector(s)).find(i => !i.value);
    (firstEmpty || overlay.querySelector('#avcfg-totaal')).focus();
}

// Bereken punten-gebaseerde ranking en update finish-badges in heat card én linker panel.
// Leest punten uit hidden inputs (real-time). Berekent tijdpositie dynamisch voor tiebreaking.
// Volgorde: punten DESC → tijdpositie ASC (voor 0-puntenrijders en bij ex-aequo punten).
function _liveUpdatePuntenBadges(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit) return;

    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);

    // 1. Punten: eerst uit rit.rijders (opgeslagen staat), dan hidden inputs overschrijven
    //    (hidden inputs bevatten real-time wijzigingen vóór opslaan)
    const puntenMap = new Map();
    rit.rijders.forEach(r => { if (r.punten != null) puntenMap.set(r.entry_id, r.punten); });
    kaart?.querySelectorAll('.live-punten-inp[data-pk-entry]').forEach(inp => {
        const val = inp.value.trim();
        if (val !== '') puntenMap.set(parseInt(inp.dataset.pkEntry), parseFloat(val));
    });

    // 2. Tiebreaker positie: gebruik finishpositie uit local state als die beschikbaar is
    //    (wordt gezet na opslaan of na wissel). Valt terug op rondes+tijd als die null is.
    const tijdEntries = rit.rijders.map(r => {
        const rij    = document.querySelector(`[data-entry="${r.entry_id}"]`);
        const inp    = rij?.querySelector('.live-tijd-inp');
        const sel    = rij?.querySelector('.live-sanctie-sel');
        const rnInp  = rij?.querySelector('.live-rondes-inp');
        const rondes = rnInp ? (rnInp.value !== '' ? (parseInt(rnInp.value) || null) : null) : (r.rondes ?? null);
        return { entry_id: r.entry_id, tijd_ms: inp ? _parseTijdInvoer(inp.value) : null, sanctie: sel?.value || null, rondes };
    });
    const tijdPosMap = _berekenPosities(tijdEntries, true);

    // 3. Alle rijders samenvoegen en sorteren: punten DESC → sortPos ASC
    //    sortPos = finishpositie (wissel-gecorrigeerd) als beschikbaar, anders rondes+tijd
    const gesorteerd = rit.rijders
        .map(r => ({
            entry_id: r.entry_id,
            punten:   puntenMap.get(r.entry_id) ?? 0,
            sortPos:  r.finishpositie ?? tijdPosMap.get(r.entry_id) ?? 9999,
        }))
        .filter(r => r.punten > 0 || r.sortPos < 9999)
        .sort((a, b) => b.punten - a.punten || a.sortPos - b.sortPos);

    // 4. Badges bijwerken in heat card én linker panel
    const metPositie = new Set();
    gesorteerd.forEach((r, i) => {
        metPositie.add(r.entry_id);
        const rang = _ordinaal(i + 1);
        const cls  = 'live-finish-badge finish-pos finish-pos-punten';
        const badge      = kaart?.querySelector(`[data-entry="${r.entry_id}"] .live-finish-badge`);
        const panelBadge = document.querySelector(`[data-panel-entry="${r.entry_id}"] .live-finish-badge`);
        if (badge)      { badge.textContent      = rang; badge.className      = cls; }
        if (panelBadge) { panelBadge.textContent = rang; panelBadge.className = cls; }
    });

    // Rijders zonder positie: badge leegmaken
    rit.rijders.forEach(r => {
        if (metPositie.has(r.entry_id)) return;
        const badge      = kaart?.querySelector(`[data-entry="${r.entry_id}"] .live-finish-badge`);
        const panelBadge = document.querySelector(`[data-panel-entry="${r.entry_id}"] .live-finish-badge`);
        if (badge)      { badge.textContent      = '—'; badge.className      = 'live-finish-badge'; }
        if (panelBadge) { panelBadge.textContent = '—'; panelBadge.className = 'live-finish-badge'; }
    });
}

// Hulpfunctie: update snr-badges én sync het top-grid na een puntenwijziging
function _pkUpdateBadges(idx, entryId, nieuwVal) {
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${idx}"]`);
    const hp    = nieuwVal != null && parseFloat(nieuwVal) > 0;

    // Alle bestaande knoppen bijwerken (bottom grid + evt. al aanwezig in top grid)
    kaart?.querySelectorAll(`.live-pk-snr-btn[data-pk-entry="${entryId}"]`).forEach(btn => {
        const ptsEl = btn.querySelector('.live-pk-snr-pts');
        if (ptsEl) ptsEl.textContent = hp ? String(nieuwVal) : '';
        btn.classList.toggle('live-pk-snr-heeft-punten', hp);
    });

    // Top-grid synchroon houden
    const topContainer  = kaart?.querySelector(`#live-pk-met-punten-${idx}`);
    if (!topContainer) return;

    const bestaandeTopBtn = topContainer.querySelector(`[data-pk-entry="${entryId}"]`);

    if (hp) {
        if (bestaandeTopBtn) {
            // Alleen pts-badge bijwerken (knop bestaat al)
            const ptsEl = bestaandeTopBtn.querySelector('.live-pk-snr-pts');
            if (ptsEl) ptsEl.textContent = String(nieuwVal);
        } else {
            // Nieuw knopje toevoegen aan top-grid
            topContainer.querySelector('.live-pk-geen-punten')?.remove();

            const botBtn = kaart?.querySelector(`.live-pk-grid .live-pk-snr-btn[data-pk-entry="${entryId}"]`);
            const snrTxt = botBtn?.querySelector('.live-pk-snr-nr')?.textContent || '';
            const naam   = botBtn?.dataset.pkNaam || '';

            const newBtn = document.createElement('button');
            newBtn.className           = 'live-pk-snr-btn live-pk-snr-heeft-punten';
            newBtn.dataset.pkEntry     = entryId;
            newBtn.dataset.pkNaam      = naam;
            newBtn.innerHTML           = `<span class="live-pk-snr-nr">${escHtml(snrTxt)}</span>` +
                                         `<span class="live-pk-snr-pts">${nieuwVal}</span>`;

            // Invoegen op gesorteerde positie (startnummer oplopend)
            const snrNum    = parseInt(snrTxt) || 9999;
            const volgendBtn = [...topContainer.querySelectorAll('.live-pk-snr-btn')]
                .find(b => (parseInt(b.querySelector('.live-pk-snr-nr')?.textContent || '9999')) > snrNum);
            if (volgendBtn) topContainer.insertBefore(newBtn, volgendBtn);
            else            topContainer.appendChild(newBtn);
        }
    } else if (bestaandeTopBtn) {
        // Punten op 0 gezet: verwijder uit top-grid
        bestaandeTopBtn.remove();
        if (!topContainer.querySelector('.live-pk-snr-btn')) {
            const ph = document.createElement('span');
            ph.className   = 'live-pk-geen-punten';
            ph.textContent = '—';
            topContainer.appendChild(ph);
        }
    }
}

// Bevestig handmatig ingevoerde punten vanuit de invoer-balk (overschrijft waarde).
function _livePkInvoerBevestig(idx) {
    const kaart     = document.querySelector(`.live-carousel-card[data-idx="${idx}"]`);
    const invoerInp = el('live-pk-invoer-inp-' + idx);
    const entryId   = invoerInp?.dataset.activeEntry;
    if (!entryId) return;

    const val    = (invoerInp.value ?? '').trim();
    const numVal = val !== '' ? parseFloat(val) : null;

    // Schrijf naar hidden input
    const hiddenInp = kaart?.querySelector(`.live-punten-inp[data-pk-entry="${entryId}"]`);
    if (hiddenInp) hiddenInp.value = val;

    _pkUpdateBadges(idx, entryId, numVal);
    _liveUpdatePuntenBadges(idx);
    _liveOngeslagen = true;

    // Deselect + disable: operator selecteert bewust het volgende nummer zelf
    const pkPanel = document.querySelector(`.live-carousel-card[data-idx="${idx}"] .live-pk-panel`);
    pkPanel?.querySelectorAll('.live-pk-snr-btn').forEach(b => b.classList.remove('live-pk-snr-actief'));
    [1,2,3].forEach(n => { const pb = el(`live-pk-plus${n}-${idx}`); if (pb) pb.disabled = true; });
    const invoerOk = el('live-pk-invoer-ok-' + idx); if (invoerOk) invoerOk.disabled = true;
    const naamEl   = el('live-pk-invoer-naam-' + idx); if (naamEl) naamEl.textContent = '— selecteer een nummer —';
    if (invoerInp) { invoerInp.disabled = true; invoerInp.value = ''; }
}

// ── CSV-import panel ──────────────────────────────────────────────────────────

const _IMPORT_MAP_KEY       = 'liveImportMap';       // localStorage: onthouden map
const _IMPORT_FILE_SORT_KEY = 'liveImportFileSort';  // localStorage: 'naam' | 'nieuw'
const _IMPORT_USED_KEY      = 'liveImportUsed';      // localStorage: {"map|file": mtimeAtUse}
const _importCsvCache  = new Map();        // key: "map|filename" → geparseerde rows
const _IMPORT_POLL_MS  = 4000;             // elke 4s checken op nieuwe CSV's in de gekozen map
const _IMPORT_NEW_TTL_MS = 60000;          // 🆕-label verdwijnt na 60s
const _importPollHandles = new Map();      // ritIdx → intervalHandle (auto-refresh bestandenlijst)

// ── Al-geïmporteerde bestanden bijhouden ────────────────────────────────────
// Na een succesvolle import van "map/file" onthouden we de mtime van dat
// bestand op dat moment. Bij latere renders komt er een ✓-prefix zolang het
// bestand niet is aangepast (mtime gelijk of lager). Wordt de CSV later
// overschreven met nieuwere data? Dan is mtime groter dan wat we opsloegen
// en verdwijnt het ✓ vanzelf — je ziet het weer als "onaangeraakt".
function _liveImportLoadUsed() {
    try { return JSON.parse(localStorage.getItem(_IMPORT_USED_KEY) || '{}'); }
    catch { return {}; }
}
function _liveImportSaveUsed(obj) {
    try { localStorage.setItem(_IMPORT_USED_KEY, JSON.stringify(obj)); }
    catch {} // quota / disabled storage — stil negeren
}
function _liveImportMarkUsed(map, filename, mtime) {
    if (!map || !filename) return;
    const used = _liveImportLoadUsed();
    used[map + '|' + filename] = mtime || 0;
    _liveImportSaveUsed(used);
}
function _liveImportIsUsed(map, filename, mtime, usedCache) {
    const k = map + '|' + filename;
    const usedAtMtime = usedCache[k];
    if (usedAtMtime === undefined) return false;
    // Bestand sindsdien overschreven? → niet meer als "gebruikt" tonen
    return (mtime || 0) <= usedAtMtime;
}

// Toggle import-panel; laad mappenlijst bij eerste opening
async function _liveImportToggle(ritIdx) {
    const panel  = el('live-import-panel-' + ritIdx);
    const mapSel = el('live-import-map-'   + ritIdx);
    if (!panel) return;

    const openend = !panel.classList.contains('verborgen');
    panel.classList.toggle('verborgen', openend);
    if (openend) {
        // Paneel ging net dicht → poll stoppen
        _liveImportStopPoll(ritIdx);
        return;
    }
    if (mapSel.dataset.geladen) {
        // Al eerder geladen → poll (opnieuw) starten als er een map actief is
        if (mapSel.value) _liveImportStartPoll(ritIdx);
        return;
    }

    mapSel.disabled = true;
    mapSel.innerHTML = '<option value="">— laden… —</option>';
    try {
        const res  = await fetch('api/live.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'lijst_mappen' }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Backwards-compat: oude API leverde array van strings, nieuwe levert
        // array van {name, mtime}. Normaliseer naar {name, mtime?}.
        const mappenRaw = data.mappen || [];
        const mappen = mappenRaw.map(m =>
            (typeof m === 'string') ? { name: m } : m
        );

        if (mappen.length === 0) {
            mapSel.innerHTML = '<option value="">— geen mappen gevonden —</option>';
        } else {
            // Stash volledige lijst op het select-element zodat de filter zonder
            // extra server-call kan re-rendereren.
            mapSel.dataset.allMappen = JSON.stringify(mappen.map(m => m.name));

            const onthouden = localStorage.getItem(_IMPORT_MAP_KEY) || '';
            _liveImportRenderMapOpties(ritIdx, '', onthouden);

            // Als onthouden map beschikbaar is: laad direct de bestandenlijst
            if (onthouden && mappen.some(m => m.name === onthouden)) {
                mapSel.dataset.geladen = '1';
                mapSel.disabled = false;
                await _liveImportMapGekozen(ritIdx);
                return;
            }
        }
        mapSel.dataset.geladen = '1';
    } catch(e) {
        mapSel.innerHTML = `<option value="">⚠ ${escHtml(e.message)}</option>`;
    }
    mapSel.disabled = false;
}

// Render de <option>-lijst voor de map-select op basis van de gestashte
// volledige lijst en een optionele filter-string (case-insensitive substring).
// $preselect is optioneel: als die waarde bestaat in de gefilterde lijst,
// wordt die voorgeselecteerd.
function _liveImportRenderMapOpties(ritIdx, filter = '', preselect = '') {
    const mapSel = el('live-import-map-' + ritIdx);
    if (!mapSel) return;
    const allJson = mapSel.dataset.allMappen || '[]';
    let all;
    try { all = JSON.parse(allJson); } catch { all = []; }
    const q = (filter || '').trim().toLowerCase();
    const mapped = q
        ? all.filter(n => n.toLowerCase().includes(q))
        : all;

    if (mapped.length === 0) {
        mapSel.innerHTML = '<option value="">— geen match —</option>';
        return;
    }
    mapSel.innerHTML =
        '<option value="">— kies een map —</option>' +
        mapped.map(n =>
            `<option value="${escHtml(n)}"${n === preselect ? ' selected' : ''}>${escHtml(n)}</option>`
        ).join('');
}

// Gebruiker typt in het filter-veld → opties bijwerken. Huidige selectie
// proberen te behouden als die nog in de gefilterde lijst past.
function _liveImportMapFilter(ritIdx) {
    const mapSel    = el('live-import-map-'       + ritIdx);
    const filterInp = el('live-import-mapfilter-' + ritIdx);
    if (!mapSel || !filterInp) return;
    const huidig = mapSel.value;
    _liveImportRenderMapOpties(ritIdx, filterInp.value, huidig);
}

// ── Bestanden-sortering: 'naam' (A-Z) of 'nieuw' (mtime DESC) ───────────────
function _liveImportGetFileSort() {
    const s = localStorage.getItem(_IMPORT_FILE_SORT_KEY);
    return s === 'nieuw' ? 'nieuw' : 'naam';
}

// Visuele active-state van de sort-knoppen synchroniseren met de voorkeur.
function _liveImportSyncSortButtons(ritIdx) {
    const sort = _liveImportGetFileSort();
    const bN = el('live-import-sort-naam-'  + ritIdx);
    const bNw = el('live-import-sort-nieuw-' + ritIdx);
    bN?.classList.toggle('actief',  sort === 'naam');
    bNw?.classList.toggle('actief', sort === 'nieuw');
}

// Render <option>-lijst voor bestand-select uit de gestashte volledige lijst,
// gesorteerd op de huidige voorkeur.
//
// Prefix-logica (waarde blijft de ruwe bestandsnaam):
//   🆕  nieuw gedetecteerd bestand (nog niet geïmporteerd, binnen TTL)
//   ✓   al geïmporteerd door gebruiker (persist via localStorage) en
//       bestand is sindsdien niet overschreven
//   —   plain / geen prefix
// 🆕 wint van ✓ (importeren wist trouwens de isNew-vlag).
function _liveImportRenderFileOpties(ritIdx, preselect = '') {
    const fileSel = el('live-import-sel-' + ritIdx);
    const mapSel  = el('live-import-map-' + ritIdx);
    if (!fileSel) return;
    let all = [];
    try { all = JSON.parse(fileSel.dataset.allFiles || '[]'); } catch {}
    const mapNaam = mapSel?.value || '';
    const used    = _liveImportLoadUsed();

    const sort = _liveImportGetFileSort();
    const sorted = [...all].sort((a, b) => {
        if (sort === 'nieuw') {
            const mA = a.mtime || 0, mB = b.mtime || 0;
            if (mA !== mB) return mB - mA; // nieuwste eerst
        }
        // fallback / naam-sort: natuurlijke alfabetische volgorde
        return (a.name || '').localeCompare(b.name || '', undefined,
            { numeric: true, sensitivity: 'base' });
    });

    // Behoud huidige selectie als geen preselect meegegeven is
    const curVal = preselect || fileSel.value || '';
    fileSel.innerHTML = sorted.map(f => {
        let prefix = '';
        if (f.isNew) {
            prefix = '🆕 ';
        } else if (_liveImportIsUsed(mapNaam, f.name, f.mtime, used)) {
            prefix = '✓ ';
        }
        const label = prefix + f.name;
        const sel   = (f.name === curVal) ? ' selected' : '';
        return `<option value="${escHtml(f.name)}"${sel}>${escHtml(label)}</option>`;
    }).join('');
}

// Wis het 🆕-label voor één specifiek bestand (bv. na importeren of bij
// het nogmaals openen van het paneel). Past de cache aan en re-rendert.
function _liveImportClearNewFlag(ritIdx, filename) {
    const fileSel = el('live-import-sel-' + ritIdx);
    if (!fileSel) return;
    let all = [];
    try { all = JSON.parse(fileSel.dataset.allFiles || '[]'); } catch { return; }
    let changed = false;
    for (const f of all) {
        if (f.name === filename && f.isNew) {
            f.isNew = false;
            changed = true;
        }
    }
    if (changed) {
        fileSel.dataset.allFiles = JSON.stringify(all);
        _liveImportRenderFileOpties(ritIdx);
    }
}

// Gebruiker klikt op sort-knop.
function _liveImportFileSort(ritIdx, mode) {
    if (mode !== 'naam' && mode !== 'nieuw') return;
    localStorage.setItem(_IMPORT_FILE_SORT_KEY, mode);
    _liveImportSyncSortButtons(ritIdx);
    _liveImportRenderFileOpties(ritIdx);
}

// Gebruiker kiest een map → laad bestandenlijst
async function _liveImportMapGekozen(ritIdx) {
    const mapSel  = el('live-import-map-' + ritIdx);
    const fileSel = el('live-import-sel-' + ritIdx);
    const laadBtn = el('live-import-laad-' + ritIdx);
    const status  = el('live-import-status-' + ritIdx);
    if (!mapSel || !fileSel) return;

    const map = mapSel.value;
    if (!map) {
        fileSel.innerHTML = '<option value="">— kies eerst een map —</option>';
        fileSel.disabled  = true;
        laadBtn.disabled  = true;
        return;
    }

    // Onthoud keuze voor deze sessie
    localStorage.setItem(_IMPORT_MAP_KEY, map);

    fileSel.disabled  = true;
    fileSel.innerHTML = '<option value="">— laden… —</option>';
    if (status) { status.textContent = ''; status.className = 'live-import-status'; }
    // Preview + acties verbergen bij mapwissel
    el('live-import-preview-' + ritIdx)?.classList.add('verborgen');
    el('live-import-acties-'  + ritIdx)?.classList.add('verborgen');

    try {
        const res  = await fetch('api/live.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'lijst_uploads', map }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Backwards-compat: oude API leverde array van strings, nieuwe levert
        // {name, mtime}. Normaliseer naar {name, mtime, isNew?}.
        const files = (data.files || []).map(f =>
            (typeof f === 'string') ? { name: f } : f
        );

        // Volledige lijst stashen voor sort-toggle + poll-merge
        fileSel.dataset.allFiles = JSON.stringify(files);

        if (files.length === 0) {
            fileSel.innerHTML = '<option value="">— geen CSV-bestanden —</option>';
        } else {
            _liveImportRenderFileOpties(ritIdx, data.preselect || '');
            fileSel.disabled = false;
            laadBtn.disabled = false;
            _liveImportSyncSortButtons(ritIdx);

            // Preview direct triggeren voor het geselecteerde bestand.
            // Normaal doet de 'change' listener dit, maar bij slechts 1 file
            // (of een preselect) vuurt er geen change-event en bleef de preview leeg.
            if (fileSel.value) _liveImportPreview(ritIdx);
        }

        // Start auto-refresh: tijdens een wedstrijd wil je dat nieuwe CSV's
        // die in de upload-map verschijnen direct zichtbaar worden zonder
        // handmatig de map te moeten her-selecteren.
        _liveImportStartPoll(ritIdx);
    } catch(e) {
        fileSel.innerHTML = `<option value="">⚠ ${escHtml(e.message)}</option>`;
    }
}

// ── Auto-refresh bestandenlijst ─────────────────────────────────────────────
// Polling (4s) terwijl het import-paneel open is en een map geselecteerd is.
// Nieuwe bestanden worden stilletjes aan de dropdown toegevoegd met een 🆕-prefix.
// De huidige selectie en preview blijven onaangetast.
function _liveImportStartPoll(ritIdx) {
    _liveImportStopPoll(ritIdx); // dubbele intervals voorkomen
    const handle = setInterval(() => _liveImportPollTick(ritIdx), _IMPORT_POLL_MS);
    _importPollHandles.set(ritIdx, handle);
}

function _liveImportStopPoll(ritIdx) {
    const h = _importPollHandles.get(ritIdx);
    if (h) {
        clearInterval(h);
        _importPollHandles.delete(ritIdx);
    }
}

async function _liveImportPollTick(ritIdx) {
    const panel   = el('live-import-panel-' + ritIdx);
    const mapSel  = el('live-import-map-'   + ritIdx);
    const fileSel = el('live-import-sel-'   + ritIdx);
    // DOM verdwenen (carousel rebuild) of paneel dicht → stop
    if (!panel || !mapSel || !fileSel || panel.classList.contains('verborgen')) {
        _liveImportStopPoll(ritIdx);
        return;
    }
    const map = mapSel.value;
    if (!map) return; // geen map geselecteerd — blijf tick'en voor later

    try {
        const res  = await fetch('api/live.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'lijst_uploads', map }),
        });
        const data = await res.json();
        if (data.error) return;
        // Normaliseer naar {name, mtime}
        const nieuwLijst = (data.files || []).map(f =>
            (typeof f === 'string') ? { name: f } : f
        );

        // Bestaande lijst + isNew-flags uit dataset halen
        let huidig = [];
        try { huidig = JSON.parse(fileSel.dataset.allFiles || '[]'); } catch {}
        const bestaandMap = new Map();
        for (const h of huidig) bestaandMap.set(h.name, h);

        // Merge: nieuwe items krijgen isNew=true + timestamp, oude behouden
        // hun flag TENZIJ de TTL verlopen is (dan verdwijnt het 🆕-label).
        const nu = Date.now();
        let aantalNieuw = 0;
        let aantalVerlopen = 0;
        const merged = nieuwLijst.map(f => {
            const prev = bestaandMap.get(f.name);
            if (prev) {
                let isNew = !!prev.isNew;
                if (isNew && prev.newSince && (nu - prev.newSince) > _IMPORT_NEW_TTL_MS) {
                    isNew = false;
                    aantalVerlopen++;
                }
                return { ...f, isNew, newSince: prev.newSince };
            }
            aantalNieuw++;
            return { ...f, isNew: true, newSince: nu };
        });

        fileSel.dataset.allFiles = JSON.stringify(merged);

        if (aantalNieuw > 0 || aantalVerlopen > 0) {
            _liveImportRenderFileOpties(ritIdx);
            if (aantalNieuw > 0) {
                fileSel.disabled = false;
                const laadBtn = el('live-import-laad-' + ritIdx);
                if (laadBtn) laadBtn.disabled = false;
            }
        }
    } catch(_) {
        // Stil falen — volgende tick proberen we het weer
    }
}

// Haal CSV-rijen op (met cache) en toon preview-tabel onder de bestandskeuze
async function _liveImportPreview(ritIdx) {
    const mapSel  = el('live-import-map-'     + ritIdx);
    const fileSel = el('live-import-sel-'     + ritIdx);
    const preview = el('live-import-preview-' + ritIdx);
    const acties  = el('live-import-acties-'  + ritIdx);
    const status  = el('live-import-status-'  + ritIdx);
    if (!mapSel || !fileSel || !preview) return;

    const map      = mapSel.value;
    const filename = fileSel.value;

    // Geen selectie → verberg preview
    if (!map || !filename) {
        preview.innerHTML = '';
        preview.classList.add('verborgen');
        acties?.classList.add('verborgen');
        return;
    }

    preview.innerHTML = '<span class="live-import-bezig">CSV ophalen…</span>';
    preview.classList.remove('verborgen');
    acties?.classList.add('verborgen');
    if (status) { status.textContent = ''; status.className = 'live-import-status'; }

    const cacheKey = map + '|' + filename;
    let rows = _importCsvCache.get(cacheKey);

    if (!rows) {
        try {
            const res  = await fetch('api/live.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'lees_csv', map, filename }),
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            rows = data.rows || [];
            _importCsvCache.set(cacheKey, rows);
        } catch(e) {
            preview.innerHTML = `<span class="live-import-status import-fout">⚠ ${escHtml(e.message)}</span>`;
            return;
        }
    }

    if (rows.length === 0) {
        preview.innerHTML = '<span class="live-import-status import-warn">Geen gegevens gevonden in dit bestand.</span>';
        return;
    }

    // Bouw de previewtabel
    const rijen = rows.map(r => {
        const tijdTxt = r.tijd_ms ? _msTijdNaarDisplay(r.tijd_ms) : '—';
        return `<tr>` +
            `<td class="ipt-pos">${r.pos || '—'}</td>` +
            `<td class="ipt-nr">${r.nr}</td>` +
            `<td class="ipt-naam">${escHtml(r.naam)}</td>` +
            `<td class="ipt-tijd">${tijdTxt}</td>` +
            `</tr>`;
    }).join('');

    preview.innerHTML =
        `<table class="live-import-tabel">` +
        `<thead><tr><th>Pos</th><th>Snr</th><th>Naam</th><th>Tijd</th></tr></thead>` +
        `<tbody>${rijen}</tbody>` +
        `</table>`;

    // Toon de "Overnemen"-balk
    acties?.classList.remove('verborgen');
}

// Neemt de gecachte CSV-data over in de heat-tijdinvoer
async function _liveImportLaad(ritIdx) {
    const rit     = _liveRitten[ritIdx];
    const mapSel  = el('live-import-map-'    + ritIdx);
    const fileSel = el('live-import-sel-'    + ritIdx);
    const status  = el('live-import-status-' + ritIdx);
    const laadBtn = el('live-import-laad-'   + ritIdx);
    if (!rit || !mapSel || !fileSel || !status) return;

    const map      = mapSel.value;
    const filename = fileSel.value;
    if (!map || !filename) return;

    // 🆕-markering weghalen voor dit bestand zodra het geïmporteerd wordt
    _liveImportClearNewFlag(ritIdx, filename);

    // Gebruik cache; haal opnieuw op als cache ontbreekt (edge-case)
    const cacheKey = map + '|' + filename;
    let rows = _importCsvCache.get(cacheKey);
    if (!rows) {
        laadBtn.disabled   = true;
        status.textContent = 'Ophalen…';
        try {
            const res  = await fetch('api/live.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'lees_csv', map, filename }),
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            rows = data.rows || [];
            _importCsvCache.set(cacheKey, rows);
        } catch(e) {
            status.textContent = '⚠ ' + e.message;
            status.className   = 'live-import-status import-fout';
            laadBtn.disabled   = false;
            return;
        }
    }

    // Bouw lookup: startnummer → csvRij
    const csvMap = new Map();
    for (const row of rows) csvMap.set(row.nr, row);

    let gevonden = 0;
    // Afvalkoers-bescherming: voor rijders die via de UI al een afval-rang
    // hebben gekregen (= afgevallen) zijn de rondes handmatig door admin
    // ingevoerd. Die mogen NIET door MyLaps-CSV worden overschreven —
    // ze representeren tot welke ronde de rijder mee heeft gereden, niet
    // de transponder-rondes (die kunnen er minder zijn als de rijder uit
    // koers is gehaald). Tijd mag wel ververst worden voor het archief.
    const isAfvalkoers = rit.race_type === 'afvalkoers';
    for (const r of rit.rijders) {
        if (r.startnummer == null) continue;
        const csvRij = csvMap.get(r.startnummer);
        if (!csvRij) continue;
        gevonden++;
        const tijdVal   = csvRij.tijd_ms ? _msTijdNaarDisplay(csvRij.tijd_ms) : '';

        // Beschermings-conditie: bij afvalkoers-afgevallene → rondes niet
        // overschrijven. Geldt ook als handmatige rondes-input al ingevuld is
        // (admin heeft expliciet iets gezet).
        const beschermRondes = isAfvalkoers && r.afval_rang != null;
        const rondenVal = (!beschermRondes && csvRij.ronden != null)
            ? String(csvRij.ronden) : null;

        // Lokale state bijwerken (zodat linker panel herbouwt met juiste rondes)
        if (!beschermRondes && csvRij.ronden != null) r.rondes = csvRij.ronden;

        // Tijd + rondes invullen; sanctie ongemoeid laten
        [`[data-entry="${r.entry_id}"]`, `[data-panel-entry="${r.entry_id}"]`].forEach(cssSelStr => {
            document.querySelectorAll(cssSelStr).forEach(rij => {
                const t = rij.querySelector('.live-tijd-inp');
                if (t && t !== document.activeElement) t.value = tijdVal;
                if (rondenVal != null) {
                    const rnInp = rij.querySelector('.live-rondes-inp');
                    if (rnInp) rnInp.value = rondenVal;
                }
            });
        });
    }

    _liveHerbereken(ritIdx);

    // Herbouw linker panel zodat rondes-kolom ook daar zichtbaar is
    const panelOud = el('live-panel-links');
    if (panelOud) {
        const tijdelijkDiv = document.createElement('div');
        tijdelijkDiv.innerHTML = _liveBouwLinksPanel(rit.dc_id, rit.distance_id, rit.ronde_type);
        panelOud.replaceWith(tijdelijkDiv.firstElementChild);
        _livePanelBind();
    }
    status.textContent = gevonden > 0
        ? `✓ ${gevonden} rijder${gevonden !== 1 ? 's' : ''} overgenomen`
        : '⚠ Geen overeenkomende rugnummers gevonden';
    status.className = 'live-import-status ' + (gevonden > 0 ? 'import-ok' : 'import-warn');
    _liveOngeslagen  = true;
    laadBtn.disabled = false;

    // Markeer bestand als "gebruikt" (✓-prefix, persistent over refresh heen).
    // Opslag bevat de mtime-op-moment-van-import zodat een latere overschrijving
    // van dezelfde filename automatisch weer plain getoond wordt.
    if (gevonden > 0) {
        let allFiles = [];
        try { allFiles = JSON.parse(fileSel.dataset.allFiles || '[]'); } catch {}
        const fileObj = allFiles.find(f => f.name === filename);
        _liveImportMarkUsed(map, filename, fileObj?.mtime || 0);
        _liveImportRenderFileOpties(ritIdx);
    }
}

// Vervang finish-badges door wissel-dropdowns na opslaan
function _liveActiveerWisselDropdowns(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit || _liveLeesOnly) return;
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);
    if (!kaart) return;

    const validPosities = [...new Set(rit.rijders.map(r => r.finishpositie).filter(Boolean))].sort((a, b) => a - b);
    if (validPosities.length < 2) return;

    rit.rijders.forEach(r => {
        if (!r.finishpositie) return;
        const rij      = kaart.querySelector(`[data-entry="${r.entry_id}"]`);
        const bestaand = rij?.querySelector('.live-finish-sel');
        if (bestaand) {
            // Dropdown bestaat al: update geselecteerde waarde en optie-lijst
            bestaand.innerHTML = '';
            validPosities.forEach(p => {
                const opt = document.createElement('option');
                opt.value       = p;
                opt.textContent = p;
                opt.selected    = (p === r.finishpositie);
                bestaand.appendChild(opt);
            });
            return;
        }
        const badge = rij?.querySelector('.live-finish-badge');
        if (!badge) return;

        const sel = document.createElement('select');
        sel.className = 'live-finish-sel';
        validPosities.forEach(p => {
            const opt = document.createElement('option');
            opt.value       = p;
            opt.textContent = p;
            opt.selected    = (p === r.finishpositie);
            sel.appendChild(opt);
        });
        badge.parentElement.replaceChild(sel, badge);
    });
}

// Bouw een tabelrij voor een rijder (in het heat-card formaat)
// validPosities = array van beschikbare finish-posities voor wissel-dropdown (leeg = badge tonen)
function _liveRijRij(r, compact = false, validPosities = [], rangMap = new Map(), opts = {}) {
    const { heeftRondes = false } = opts;
    const tijdVal = r.tijd_ms !== null ? _msTijdNaarDisplay(r.tijd_ms) : '';

    // DB = UI codes, geen mapping meer nodig
    const sanctieUi = r.sanctie || '';

    const disabled = _liveLeesOnly ? 'disabled' : '';

    // FS = waarschuwing, niet rood; DQ-DF/DNS/DNF/DQ-SF = rood
    const statusKlasse = (r.sanctie === 'FS')
        ? (r.tijd_ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg')
        : r.sanctie
            ? 'live-rit-status-sanctie'
            : (r.tijd_ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');

    const transponder = escHtml(r.transponder_actief ?? '—');

    return `<tr class="live-rij ${statusKlasse}" data-entry="${r.entry_id}" data-rondes="${r.rondes ?? ''}" data-punten="${r.punten ?? ''}">` +
        `<td class="heat-pos">${r.startpositie}</td>` +
        (!compact ? `<td class="heat-snr">${r.startnummer ?? ''}</td>` : '') +
        `<td class="heat-naam">${escHtml(r.full_name || '')}</td>` +
        (!compact ? `<td class="heat-tp">${transponder}</td>` : '') +
        (heeftRondes ? `<td class="live-col-rondes"><input type="number" class="live-rondes-inp" value="${r.rondes ?? ''}" min="0" placeholder="—" ${disabled} inputmode="numeric"></td>` : '') +
        `<td class="live-col-tijd">` +
        `<input type="text" class="live-tijd-inp" value="${escHtml(tijdVal)}"` +
        ` placeholder="0:00.000" ${disabled} inputmode="decimal">` +
        `</td>` +
        `<td class="live-col-sanctie">` +
        `<select class="live-sanctie-sel" ${disabled}>` +
        `<option value="">—</option>` +
        `<option value="DNS"   ${sanctieUi === 'DNS'   ? 'selected' : ''}>DNS</option>` +
        `<option value="DNF"   ${sanctieUi === 'DNF'   ? 'selected' : ''}>DNF</option>` +
        `<option value="FS"    ${sanctieUi === 'FS'    ? 'selected' : ''}>FS</option>` +
        `<option value="DQ-TF" ${sanctieUi === 'DQ-TF' ? 'selected' : ''}>DQ-TF</option>` +
        `<option value="DQ-SF" ${sanctieUi === 'DQ-SF' ? 'selected' : ''}>DQ-SF</option>` +
        `<option value="DQ-DF" ${sanctieUi === 'DQ-DF' ? 'selected' : ''}>DQ-DF</option>` +
        `<option value="W1"    ${sanctieUi === 'W1'    ? 'selected' : ''}>W1</option>` +
        `<option value="W2"    ${sanctieUi === 'W2'    ? 'selected' : ''}>W2</option>` +
        `<option value="RR"    ${sanctieUi === 'RR'    ? 'selected' : ''}>RR</option>` +
        `</select>` +
        `</td>` +
        `<td class="live-col-finish">` +
        (r.finishpositie && validPosities.length > 1 && !_liveLeesOnly
            ? `<select class="live-finish-sel">` +
              validPosities.map(p =>
                  `<option value="${p}"${p === r.finishpositie ? ' selected' : ''}>${p}</option>`
              ).join('') + `</select>`
            : (() => {
                // Ex-aequo: gebruik berekende rang uit rangMap, val terug op finishpositie
                const displayRang = rangMap.get(r.entry_id) ?? r.finishpositie;
                return `<span class="live-finish-badge${displayRang ? ' finish-pos' : ''}">${displayRang ? _ordinaal(displayRang) : '—'}</span>`;
              })()
        ) +
        `</td>` +
        `</tr>`;
}

// ── Navigatie ──────────────────────────────────────────────────────────────────

async function _liveNavigeer(nieuweIdx) {
    if (nieuweIdx < 0 || nieuweIdx >= _liveRitten.length) return;
    if (_liveOngeslagen) {
        const ok = await toonBevestigDialog('Er zijn onopgeslagen tijden.\nDoorgaan zonder op te slaan?', 'Onopgeslagen tijden');
        if (!ok) return;
    }
    _liveHuidigIdx  = nieuweIdx;
    _liveOngeslagen = false;

    // Carousel: update positie zonder volledig herschrijven van DOM
    const track = el('live-carousel-track');
    if (track) {
        // Update dropdown en teller
        _liveDdSetValue(nieuweIdx);
        const teller = document.querySelector('.live-nav-teller');
        if (teller) teller.textContent = (nieuweIdx + 1) + ' / ' + _liveRitten.length;

        // Update actieve kaart styling en inputs
        _liveUpdateKaartActief(nieuweIdx);

        // Schuif de track
        _livePositionTrack(true);

        // Bind events op nieuwe actieve kaart (als die nog niet gebonden zijn)
        _liveBind(nieuweIdx);

        // Herbereken finishposities op nieuwe kaart
        _liveHerbereken(nieuweIdx);

        // Links panel: update actieve rit markering, of herbouw als categorie/ronde veranderd
        const nieuweRit = _liveRitten[nieuweIdx];
        const panelEl   = el('live-panel-links');
        if (nieuweRit && panelEl) {
            const zelfdeRonde = panelEl.dataset.dcId    === nieuweRit.dc_id &&
                                panelEl.dataset.distanceId === String(nieuweRit.distance_id ?? '') &&
                                panelEl.dataset.rondeType  === nieuweRit.ronde_type;
            if (zelfdeRonde) {
                _liveUpdatePanelActiefRit(nieuweIdx);
                _livePanelHerbereken();
            } else {
                panelEl.outerHTML = _liveBouwLinksPanel(nieuweRit.dc_id, nieuweRit.distance_id, nieuweRit.ronde_type);
                _livePanelBind();
            }
        }

        // Toon hergeneer-knop als de huidige ronde al compleet is
        const rit = _liveRitten[nieuweIdx];
        const rondeEl = el('live-ronde-compleet');
        if (rit && !_liveLeesOnly) {
            const volgende = _volgendeRondeType(rit.dc_id, rit.distance_id, rit.ronde_type);
            if (volgende && _liveRondeCompleet(rit.dc_id, rit.distance_id, rit.ronde_type)) {
                if (!rondeEl) {
                    const container = el('live-inhoud');
                    const div = document.createElement('div');
                    div.className = 'live-ronde-compleet';
                    div.id = 'live-ronde-compleet';
                    const label = _liveHergeneerLabel(rit.dc_id, rit.distance_id, volgende);
                    div.innerHTML =
                        `✓ Alle ritten van de ${escHtml(RONDE_LABEL[rit.ronde_type] || rit.ronde_type)} zijn compleet.` +
                        `<button class="live-ronde-btn" id="live-btn-volgende-ronde"` +
                        ` data-dc-id="${escHtml(rit.dc_id)}"` +
                        ` data-distance-id="${escHtml(rit.distance_id || '')}"` +
                        ` data-van="${escHtml(rit.ronde_type)}"` +
                        ` data-naar="${escHtml(volgende)}">` +
                        `&#8635; Hergeneer ${escHtml(label)}` +
                        `</button>`;
                    container?.appendChild(div);
                    div.querySelector('#live-btn-volgende-ronde')?.addEventListener('click', e => {
                        const b = e.currentTarget;
                        _liveGenereerKetenStap(b.dataset.dcId, b.dataset.distanceId, b.dataset.van, b.dataset.naar);
                    });
                }
            } else {
                rondeEl?.remove();
            }
        } else {
            rondeEl?.remove();
        }
    } else {
        // Fallback: volledig herschrijven (zou niet nodig moeten zijn)
        _liveRenderCarousel();
    }
}

// Toetsenbord navigatie
function _liveInitKeyboard() {
    // Eenmalig registreren (verwijder oude listener)
    document.removeEventListener('keydown', _liveKeyHandler);
    document.addEventListener('keydown', _liveKeyHandler);
}

// Waarschuw bij pagina verlaten als er onopgeslagen tijden zijn
window.addEventListener('beforeunload', e => {
    if (_liveOngeslagen) {
        e.preventDefault();
        e.returnValue = '';  // browsers tonen eigen melding
    }
});

function _liveKeyHandler(e) {
    // Niet navigeren als de focus in een invoerveld zit
    const tag = document.activeElement?.tagName;
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
    if (e.key === 'ArrowLeft')  _liveNavigeer(_liveHuidigIdx - 1);
    if (e.key === 'ArrowRight') _liveNavigeer(_liveHuidigIdx + 1);
}

// ── Opslaan ───────────────────────────────────────────────────────────────────

async function _liveOpslaanRit(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit) return;

    const btn = el('live-btn-opslaan-' + ritIdx);
    if (btn) { btn.disabled = true; btn.textContent = 'Bezig…'; }

    // Bij afvalkoers: per rijder de afval_rang (en eventueel sanctie DQ-TF voor by-fault)
    // ophalen uit _afvalState. Voorlopige selecties (niet-gesette 1ste/2de) tellen NIET mee
    // bij opslaan — alleen wat in afgevallen-stack staat is definitief.
    const isAfvalkoers = rit.race_type === 'afvalkoers';
    const afvalMap = new Map(); // entry_id → { plek, sanctie }
    if (isAfvalkoers) {
        const st = _afvalState[ritIdx];
        (st?.afgevallen || []).forEach(a => {
            afvalMap.set(a.entry_id, { plek: a.plek, sanctie: a.sanctie });
        });
    }

    // Verzamel resultaten uit DOM (inclusief rondes)
    const results = rit.rijders.map(r => {
        const rij        = document.querySelector(`[data-entry="${r.entry_id}"]`);
        const tijdInp    = rij?.querySelector('.live-tijd-inp');
        const sanctieSel = rij?.querySelector('.live-sanctie-sel');
        const tijdMs     = tijdInp ? _parseTijdInvoer(tijdInp.value) : null;
        const sanctieDom = sanctieSel ? sanctieSel.value : '';
        const rondesInp  = rij?.querySelector('.live-rondes-inp');
        const rondes     = rondesInp ? (rondesInp.value !== '' ? (parseInt(rondesInp.value) || null) : null) : (r.rondes ?? null);

        // Bij afvalkoers: afval-state wint van DOM-sanctie/tijd (anders zou by-fault DQ-TF
        // door _SANCTIE_WIST_TIJD de tijd wegblazen, terwijl we 'm willen bewaren).
        const afval = afvalMap.get(r.entry_id);
        if (isAfvalkoers && afval) {
            return {
                entry_id:   r.entry_id,
                tijd_ms:    tijdMs ?? null,           // tijd uit CSV mag blijven voor archief
                sanctie:    afval.sanctie || null,    // DQ-TF voor by-fault, anders null
                rondes,
                afval_rang: afval.plek,
            };
        }

        const tijdOpslaan = _SANCTIE_WIST_TIJD.has(sanctieDom) ? null : (tijdMs ?? null);
        return {
            entry_id:   r.entry_id,
            tijd_ms:    tijdOpslaan,
            sanctie:    sanctieDom || null,
            rondes,
            afval_rang: null, // niet-afvalkoers OF afvalkoers-finishgroep
        };
    });

    // Bij puntenkoers: punten meenemen in dezelfde opslag-actie
    const isPuntenkoers = rit.race_type === 'puntenkoers';
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);
    if (isPuntenkoers && kaart) {
        kaart.querySelectorAll('.live-punten-inp[data-pk-entry]').forEach(inp => {
            const entryId = parseInt(inp.dataset.pkEntry);
            const val     = inp.value.trim();
            const result  = results.find(r => r.entry_id === entryId);
            if (result) {
                result.punten = val !== '' ? parseFloat(val) : null;
            }
        });
    }

    try {
        const res = await fetch('api/live.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:         'save_rit_results',
                competition_id: huidigCompId,
                rit_id:         rit.rit_id,
                results,
            }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        _liveOngeslagen = false;

        // Lokale state bijwerken — gebruik finishposities van server (correct voor PK/rondes)
        const serverFp  = data.finishposities ?? {};
        const posMap    = _berekenPosities(results, true); // fallback
        rit.rijders = rit.rijders.map(r => {
            const gesav = results.find(x => x.entry_id === r.entry_id);
            if (!gesav) return r;
            const fp = Object.prototype.hasOwnProperty.call(serverFp, r.entry_id)
                ? (serverFp[r.entry_id] ?? null)
                : (posMap.get(r.entry_id) ?? null);
            return {
                ...r,
                tijd_ms:       gesav.tijd_ms,
                sanctie:       gesav.sanctie,
                finishpositie: fp,
            };
        });

        // Feedback geven op de knop
        if (btn) {
            btn.disabled    = false;
            btn.textContent = '✓ Opgeslagen';
            btn.classList.add('btn-opgeslagen');
            setTimeout(() => {
                if (btn) {
                    btn.textContent = '💾 Opslaan';
                    btn.classList.remove('btn-opgeslagen');
                }
            }, 2000);
        }

        // Update rijklassen in-place (geen volledige herrender nodig voor de carousel)
        _liveHerbereken(ritIdx);
        _liveActiveerWisselDropdowns(ritIdx);

        // Sync opgeslagen waarden naar linker paneel
        rit.rijders.forEach(r => {
            _liveSyncInvoer(r.entry_id, r.tijd_ms ? _msTijdNaarDisplay(r.tijd_ms) : '', r.sanctie || '');
        });
        _livePanelHerbereken();

        // Als de ronde compleet is: herbouw het linkerpaneel zodat Q/q markers verschijnen
        const panelEl = el('live-panel-links');
        if (panelEl) {
            const pDcId     = panelEl.dataset.dcId;
            const pDistId   = panelEl.dataset.distanceId;
            const pRondeType = panelEl.dataset.rondeType;
            if (pDcId && pRondeType) {
                const nieuwePanel = _liveBouwLinksPanel(pDcId, pDistId, pRondeType);
                panelEl.outerHTML = nieuwePanel;
                // Re-attach panel event listeners
                _liveInitPanelListeners();
            }
        }

        // Update dropdown-icoon voor deze rit
        _liveDdUpdateOptie(ritIdx);
        if (ritIdx === _liveHuidigIdx) _liveDdUpdateLabel(ritIdx);

    } catch(e) {
        if (btn) {
            btn.disabled    = false;
            btn.textContent = '💾 Opslaan';
        }
        toonBevestigDialog('Fout bij opslaan: ' + e.message, 'Fout');
    }

    // Na save: volgende ronde bijwerken (buiten try/catch zodat save-feedback niet verstoord wordt)
    const rit2 = _liveRitten[ritIdx];
    if (rit2 && !_liveLeesOnly) {
        const volgende2 = _volgendeRondeType(rit2.dc_id, rit2.distance_id, rit2.ronde_type);
        if (volgende2) {
            const compleet = _liveRondeCompleet(rit2.dc_id, rit2.distance_id, rit2.ronde_type);
            _liveGenereerKetenStap(rit2.dc_id, rit2.distance_id, rit2.ronde_type, volgende2, compleet)
                .catch(() => {}); // stil falen
        } else {
            el('live-ronde-compleet')?.remove();
        }
    }

    // Auto-advance: na succesvol opslaan automatisch naar de volgende rit
    // (alleen als er nog een volgende is en we niet in leesonly-modus zitten)
    if (!_liveLeesOnly && ritIdx === _liveHuidigIdx && ritIdx + 1 < _liveRitten.length) {
        setTimeout(() => {
            // Nog één keer checken: gebruiker kan intussen handmatig genavigeerd zijn
            if (_liveHuidigIdx === ritIdx) {
                _liveNavigeer(ritIdx + 1);
            }
        }, 700);
    }
}

// ── Volgende ronde genereren ───────────────────────────────────────────────────

// Genereert de volgende ronde én — indien van toepassing — de runner-up in
// dezelfde stap. Runner-up moet ALTIJD ná de doorstroom-ronde draaien zodat
// de backend ex-aequo doorstromers correct uit de afvallers-lijst kan filteren.
async function _liveGenereerKetenStap(dcId, distanceId, van, naar, compleet = true) {
    // Zelfde key-conventie als _volgendeRondeType: dcId + '|' + distanceId
    const cc = _liveCatConfigs[dcId + '|' + distanceId];
    const ookRu = !!(cc?.heeft_runner_up && _isEersteRondeKeten(cc, van));

    // Beide rondes met onderdrukte toast — de toast obliterates anders de
    // vorige en de gebruiker mist de eerste melding. We bouwen 1 gecombineerde
    // toast aan het einde.
    const r1 = await _liveGenereerVolgendeRonde(dcId, distanceId, van, naar, compleet, { silent: ookRu });
    if (!ookRu) return;

    const r2 = await _liveGenereerVolgendeRonde(dcId, distanceId, van, 'runner_up', compleet, { silent: true });

    // Combined toast — toon ALTIJD beide regels, ook als één leeg is, zodat
    // de gebruiker direct ziet of de runner-up wel/niet ritten heeft gekregen.
    const lijn = (res, label) => res
        ? `${label}: ${res.aantal} rijders`
        : `${label}: niet aangemaakt`;
    const bericht = compleet
        ? `✓ Startlijsten klaar — ${lijn(r1, r1?.label || naar)}; ${lijn(r2, 'Runner-up')}`
        : `📋 Voorlopig bijgewerkt — ${lijn(r1, r1?.label || naar)}; ${lijn(r2, 'Runner-up')}`;
    _liveToast(bericht, compleet ? 'ok' : 'bezig', compleet ? 5000 : 3500);
}

// compleet = alle heats in de ronde zijn klaar (anders is het een voorlopige update)
// opts.silent = true → geen success-toast (voor combined toast door keten-helper)
// Returns: { aantal, label } op succes, null op fout/skip.
async function _liveGenereerVolgendeRonde(dcId, distanceId, van, naar, compleet = true, opts = {}) {
    if (!huidigCompId || !dcId || !van || !naar) return null; // Guard: ontbrekende params
    const silent = !!opts.silent;
    const btn = el('live-btn-volgende-ronde');
    if (btn) { btn.disabled = true; btn.textContent = 'Bezig…'; }

    const label = RONDE_LABEL[naar] || naar;

    try {
        const res = await fetch('api/live.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:          'genereer_volgende_ronde',
                competition_id:  huidigCompId,
                dc_id:           dcId,
                distance_id:     distanceId,
                van_ronde_type:  van,
                naar_ronde_type: naar,
            }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        const aantalRijders = (data.heats || []).reduce((s, h) => s + h.rijders.length, 0);
        // Voor full-final bevat data.heats zowel A- als B-finale heats;
        // pas het label aan zodat duidelijk is dat alle finales zijn aangemaakt.
        const heefBFinale = (data.heats || []).some(h => (h.heat_naam || '').toLowerCase().includes('b'));
        const toastLabel  = (naar === 'finale_a' && heefBFinale) ? 'Finales' : label;

        // Toast: definitief (groen, lang) of voorlopig (blauw, kort) — alleen
        // als de keten-helper niet zelf een gecombineerde toast bouwt.
        if (!silent) {
            if (compleet) {
                _liveToast(`✓ ${toastLabel} startlijst klaar — ${aantalRijders} rijders verdeeld`, 'ok', 4000);
            } else {
                _liveToast(`📋 ${toastLabel} voorlopig bijgewerkt (${aantalRijders} rijders op basis van huidige tijden)`, 'bezig', 3000);
            }
        }

        // Hergeneer-knop bijwerken
        if (btn) {
            btn.disabled    = false;
            btn.textContent = `↻ Hergeneer ${toastLabel}`;
        }

        // Invalideer startlijst cache zodat startlist.js de nieuwe data laadt
        if (typeof startlijstCache !== 'undefined') startlijstCache = {};

        // Herlaad de hele Live module zodat nieuwe ritten (incl. ex-aequo extra) in de carousel verschijnen
        if (typeof toonLivePagina === 'function') {
            toonLivePagina();
        }

        // Als startlijsten pagina nu actief is: meteen vernieuwen
        if (el('page-startlijsten')?.classList.contains('active') &&
            typeof toonStartlijstenPagina === 'function') {
            toonStartlijstenPagina();
        }

        return { aantal: aantalRijders, label: toastLabel };

    } catch(e) {
        // Errors altijd tonen, ook in silent-mode — gebruiker moet zien dat er iets mis ging.
        _liveToast(`⚠ Fout bij genereren ${label.toLowerCase()}: ${e.message}`, 'error');
        if (btn) { btn.disabled = false; btn.textContent = `↻ Hergeneer ${label}`; }
        return null;
    }
}

// ── Toast notificatie ─────────────────────────────────────────────────────────

function _liveToast(bericht, type = 'ok', duur = 4000) {
    const bestaand = el('live-toast');
    if (bestaand) bestaand.remove();
    const toast = document.createElement('div');
    toast.id        = 'live-toast';
    toast.className = `live-toast live-toast-${type}`;
    toast.textContent = bericht;
    document.body.appendChild(toast);
    if (duur > 0) setTimeout(() => toast.remove(), duur);
}
