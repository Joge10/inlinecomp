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

function _liveRitCompleet(rit) {
    if (!rit.rijders || rit.rijders.length === 0) return false;
    return rit.rijders.every(r => r.tijd_ms !== null || (r.sanctie && r.sanctie !== ''));
}

function _liveRitDeels(rit) {
    if (!rit.rijders || rit.rijders.length === 0) return false;
    const heeftIets = rit.rijders.some(r => r.tijd_ms !== null || (r.sanctie && r.sanctie !== ''));
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

// gebruikGelijkspel=true: ex-aequo-ranking (1,2,3,4,5,5,7) voor full-final series.
// gebruikGelijkspel=false (default): opeenvolgende posities zoals internationaal.
function _berekenPosities(entries, gebruikGelijkspel = false) {
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

    const posMap        = _berekenPosities(entries, _liveSysteem === 'full-final');
    const isPuntenkoers = rit.race_type === 'puntenkoers';

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
        rij.classList.remove('live-rit-status-compleet', 'live-rit-status-sanctie', 'live-rit-status-leeg');
        if (sanctieWaarde === 'FS') rij.classList.add(ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');
        else if (heeftSanctie)      rij.classList.add('live-rit-status-sanctie');
        else if (ms > 0)            rij.classList.add('live-rit-status-compleet');
        else                        rij.classList.add('live-rit-status-leeg');
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
            // ── Afvalkoers panel (placeholder) ───────────────────────────────
            `<div class="live-av-panel" id="live-av-panel-${idx}"${isAfvalkoers ? '' : ' hidden'}>` +
            `<div class="live-av-titel">🚫 Afvalkoers</div>` +
            `<p class="live-av-info">Afvalkoers-invoer is in ontwikkeling.</p>` +
            `</div>`;
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
            volgendeHtml =
                `<div class="live-ronde-compleet" id="live-ronde-compleet">` +
                `✓ Alle ritten van de ${escHtml(RONDE_LABEL[rit.ronde_type] || rit.ronde_type)} zijn compleet.` +
                `<button class="live-ronde-btn" id="live-btn-volgende-ronde"` +
                ` data-dc-id="${escHtml(rit.dc_id)}"` +
                ` data-distance-id="${escHtml(rit.distance_id || '')}"` +
                ` data-van="${escHtml(rit.ronde_type)}"` +
                ` data-naar="${escHtml(volgende)}">` +
                `&#8635; Hergeneer ${escHtml(RONDE_LABEL[volgende] || volgende)}` +
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
        _liveGenereerVolgendeRonde(b.dataset.dcId, b.dataset.distanceId, b.dataset.van, b.dataset.naar);
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

    const posMap = _berekenPosities(entries, _liveSysteem === 'full-final');

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
    const tijdPosMap = _berekenPosities(tijdEntries, _liveSysteem === 'full-final');

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
    for (const r of rit.rijders) {
        if (r.startnummer == null) continue;
        const csvRij = csvMap.get(r.startnummer);
        if (!csvRij) continue;
        gevonden++;
        const tijdVal   = csvRij.tijd_ms ? _msTijdNaarDisplay(csvRij.tijd_ms) : '';
        const rondenVal = csvRij.ronden != null ? String(csvRij.ronden) : null;

        // Lokale state bijwerken (zodat linker panel herbouwt met juiste rondes)
        if (csvRij.ronden != null) r.rondes = csvRij.ronden;

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
                    div.innerHTML =
                        `✓ Alle ritten van de ${escHtml(RONDE_LABEL[rit.ronde_type] || rit.ronde_type)} zijn compleet.` +
                        `<button class="live-ronde-btn" id="live-btn-volgende-ronde"` +
                        ` data-dc-id="${escHtml(rit.dc_id)}"` +
                        ` data-distance-id="${escHtml(rit.distance_id || '')}"` +
                        ` data-van="${escHtml(rit.ronde_type)}"` +
                        ` data-naar="${escHtml(volgende)}">` +
                        `&#8635; Hergeneer ${escHtml(RONDE_LABEL[volgende] || volgende)}` +
                        `</button>`;
                    container?.appendChild(div);
                    div.querySelector('#live-btn-volgende-ronde')?.addEventListener('click', e => {
                        const b = e.currentTarget;
                        _liveGenereerVolgendeRonde(b.dataset.dcId, b.dataset.distanceId, b.dataset.van, b.dataset.naar);
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

    // Verzamel resultaten uit DOM (inclusief rondes)
    const results = rit.rijders.map(r => {
        const rij        = document.querySelector(`[data-entry="${r.entry_id}"]`);
        const tijdInp    = rij?.querySelector('.live-tijd-inp');
        const sanctieSel = rij?.querySelector('.live-sanctie-sel');
        const tijdMs     = tijdInp ? _parseTijdInvoer(tijdInp.value) : null;
        const sanctie    = sanctieSel ? sanctieSel.value : '';
        const tijdOpslaan = _SANCTIE_WIST_TIJD.has(sanctie) ? null : (tijdMs ?? null);
        const rondesInp   = rij?.querySelector('.live-rondes-inp');
        const rondes      = rondesInp ? (rondesInp.value !== '' ? (parseInt(rondesInp.value) || null) : null) : (r.rondes ?? null);
        return {
            entry_id: r.entry_id,
            tijd_ms:  tijdOpslaan,
            sanctie:  sanctie || null,
            notitie:  r.notitie || '',
            rondes,
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
        const posMap    = _berekenPosities(results, _liveSysteem === 'full-final'); // fallback
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
            _liveGenereerVolgendeRonde(rit2.dc_id, rit2.distance_id, rit2.ronde_type, volgende2, compleet)
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

// compleet = alle heats in de ronde zijn klaar (anders is het een voorlopige update)
async function _liveGenereerVolgendeRonde(dcId, distanceId, van, naar, compleet = true) {
    if (!huidigCompId || !dcId || !van || !naar) return; // Guard: ontbrekende params
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

        // Toast: definitief (groen, lang) of voorlopig (blauw, kort)
        if (compleet) {
            _liveToast(`✓ ${toastLabel} startlijst klaar — ${aantalRijders} rijders verdeeld`, 'ok', 4000);
        } else {
            _liveToast(`📋 ${toastLabel} voorlopig bijgewerkt (${aantalRijders} rijders op basis van huidige tijden)`, 'bezig', 3000);
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

    } catch(e) {
        _liveToast(`⚠ Fout bij genereren: ${e.message}`, 'error');
        if (btn) { btn.disabled = false; btn.textContent = `↻ Hergeneer ${label}`; }
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
