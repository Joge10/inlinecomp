/* InlineComp – Live verwerking */

// ── Globale state ──────────────────────────────────────────────────────────────

let _liveRitten      = [];      // alle ritten geladen van API
let _liveCatConfigs  = {};      // catConfigs van API
let _liveSysteem     = null;    // tijdschema-systeem ('full-final' | 'internationaal-nieuw' | ...)
let _liveHuidigIdx   = -1;      // huidige carousel-index (-1 = nog niet gezet)
let _liveOngeslagen  = false;   // onopgeslagen wijzigingen
let _liveLeesOnly    = false;   // geen schrijfrechten

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
const _SANCTIE_GEDEELD_LAATSTE = new Set(['DNF', 'DQ-SF']);
const _SANCTIE_GEEN_UITSLAG    = new Set(['DQ-DF', 'DNS']);
const _SANCTIE_WIST_TIJD       = new Set(['DNF', 'DQ-SF', 'DQ-DF', 'DNS']); // FS niet!

// gebruikGelijkspel=true: ex-aequo-ranking (1,2,3,4,5,5,7) voor full-final series.
// gebruikGelijkspel=false (default): opeenvolgende posities zoals internationaal.
function _berekenPosities(entries, gebruikGelijkspel = false) {
    // Finishers: heeft tijd, niet DQ-DF/DNS, niet DNF/DQ-SF (FS wél meenemen op tijd)
    const finishers = entries
        .filter(e => e.tijd_ms > 0 && !_SANCTIE_GEDEELD_LAATSTE.has(e.sanctie) && !_SANCTIE_GEEN_UITSLAG.has(e.sanctie))
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
    const gedeeldLaatste = entries.filter(e => _SANCTIE_GEDEELD_LAATSTE.has(e.sanctie));
    // DQ-DF en DNS worden genegeerd (geen positie)

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
    if (gedeeldLaatste.length > 0) {
        const laatste = finishers.length + 1;
        gedeeldLaatste.forEach(e => posMap.set(e.entry_id, laatste));
    }
    return posMap;
}

// Synchroniseer tijd+sanctie naar alle DOM-elementen met hetzelfde entry_id
// (zowel in het linker panel als in de carousel-kaart)
function _liveSyncInvoer(entryId, tijdVal, sanctieVal) {
    [`[data-entry="${entryId}"]`, `[data-panel-entry="${entryId}"]`].forEach(sel => {
        document.querySelectorAll(sel).forEach(rij => {
            const t = rij.querySelector('.live-tijd-inp');
            const s = rij.querySelector('.live-sanctie-sel');
            if (t && t !== document.activeElement && t.value !== tijdVal) t.value = tijdVal;
            if (s && s !== document.activeElement && s.value !== sanctieVal) s.value = sanctieVal;
        });
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
        const rondes = rij?.dataset.rondes ? (parseInt(rij.dataset.rondes) || null) : (r.rondes ?? null);
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

    // Titel — dezelfde opbouw als heat-card in startlist.js
    const titelHtml =
        `<div class="heat-titel">` +
        `<span class="heat-ritnr">${rit.volgorde ?? (idx + 1)}</span>` +
        tijdstipHtml +
        escHtml(rit.rit_naam) +
        rondeBadge +
        `<span class="heat-count">${aantalRijders}</span>` +
        `</div>`;

    let tabelHtml = '';
    if (_liveHasHeat(rit)) {
        // Kaart met echte startlijst

        // Eerst detecteren of dit een lange-afstand heat is (voor rondes-kolom + selector)
        const isLangeAfstand = (rit.distance_meters ?? 0) > 1000;
        const isPuntenkoers  = rit.race_type === 'puntenkoers';
        const isAfvalkoers   = rit.race_type === 'afvalkoers';
        const _naamLower     = (rit.afstand_naam || '').toLowerCase();
        const isLangeNaam    = /inline.*(lang|afstand)|lange?\s+afstand|puntenkoers|afvalkoers|point.?races?|eliminat/.test(_naamLower);
        const toonRaceTypeSelector = isLangeAfstand || isLangeNaam || (rit.race_type && rit.race_type !== 'inline');

        // Rondes-kolom tonen als er al data is, OF als het een lange-afstand heat is
        // (dan alvast de kolom reserveren zodat CSV-import de cellen direct kan vullen)
        const heeftRondes  = rit.rijders.some(r => r.rondes != null) || toonRaceTypeSelector;
        const heeftPunten  = rit.rijders.some(r => r.punten != null);
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
                    (!_liveLeesOnly ? `<button class="live-punten-btn" id="live-btn-punten-${idx}">💾 Punten opslaan</button>` : '') +
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
          `<label class="live-import-label">Map:</label>` +
          `<select class="live-import-map-sel" id="live-import-map-${idx}"><option value="">— laden… —</option></select>` +
          `<label class="live-import-label">Bestand:</label>` +
          `<select class="live-import-sel" id="live-import-sel-${idx}" disabled><option value="">— kies eerst een map —</option></select>` +
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

    // Dropdown opties
    const dropdownOpts = _liveRitten.map((r, i) => {
        const icoon = !_liveHasHeat(r) ? '○' : _liveRitCompleet(r) ? '✓' : _liveRitDeels(r) ? '◑' : '○';
        return `<option value="${i}" ${i === idx ? 'selected' : ''}>${icoon} ${escHtml(r.rit_naam)}</option>`;
    }).join('');

    const navHtml =
        `<div class="live-carousel-nav">` +
        `<select class="live-nav-dropdown" id="live-nav-dropdown">${dropdownOpts}</select>` +
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
    el('live-nav-dropdown')?.addEventListener('change', e => _liveNavigeer(+e.target.value));

    _liveBind(idx);
    _livePanelBind();

    el('live-btn-volgende-ronde')?.addEventListener('click', e => {
        const b = e.currentTarget;
        _liveGenereerVolgendeRonde(b.dataset.dcId, b.dataset.distanceId, b.dataset.van, b.dataset.naar);
    });
}

// ── Links panel: alle rijders in categorie+ronde ──────────────────────────────

function _liveBouwLinksPanel(dcId, distanceId, rondeType) {
    const ritten = _liveRitten.filter(r =>
        r.dc_id === dcId &&
        String(r.distance_id ?? '') === String(distanceId ?? '') &&
        r.ronde_type === rondeType
    );

    const rondeNaam = RONDE_LABEL[rondeType] || rondeType;

    // Alle rijders uit alle ritten in deze categorie+ronde, plat, gesorteerd op startnummer
    const alleRijders = [];
    for (const rit of ritten) {
        if (_liveHasHeat(rit)) {
            for (const r of rit.rijders) {
                alleRijders.push({ ...r, rit_id: rit.rit_id });
            }
        }
    }
    alleRijders.sort((a, b) => {
        const sa = a.startnummer ?? 99999;
        const sb = b.startnummer ?? 99999;
        return sa - sb;
    });

    const sanctieUiMap = { 'DNS':'DNS','DNF':'DNF','DSQ-SF':'DQ-SF','DSQ-TF':'DQ-DF','FS1':'FS' };
    const disabled = _liveLeesOnly ? 'disabled' : '';

    // Rondes-kolom tonen als minimaal één rijder ronde-data heeft
    const heeftRondes = alleRijders.some(r => r.rondes != null && r.rondes !== '' && r.rondes !== 0);

    let tbody = '';
    if (alleRijders.length === 0) {
        tbody = `<tr class="live-panel-rij-leeg"><td colspan="${heeftRondes ? 6 : 5}">Geen startlijst beschikbaar</td></tr>`;
    } else {
        for (const r of alleRijders) {
            const tijdVal   = r.tijd_ms !== null ? _msTijdNaarDisplay(r.tijd_ms) : '';
            const sanctieUi = sanctieUiMap[r.sanctie] || r.sanctie || '';
            const statusKls = r.sanctie ? 'live-rit-status-sanctie'
                            : r.tijd_ms !== null ? 'live-rit-status-compleet'
                            : 'live-rit-status-leeg';
            const rondesTd  = heeftRondes
                ? `<td class="live-col-rondes">${r.rondes != null ? escHtml(String(r.rondes)) : ''}</td>`
                : '';
            tbody +=
                `<tr class="live-panel-rij ${statusKls}" data-panel-entry="${r.entry_id}" data-rit-id="${r.rit_id}" data-rondes="${r.rondes ?? ''}">` +
                `<td>${r.startnummer ?? ''}</td>` +
                `<td>${escHtml(r.full_name || '')}</td>` +
                rondesTd +
                `<td class="live-col-tijd">` +
                `<input type="text" class="live-tijd-inp" value="${escHtml(tijdVal)}" placeholder="0:00.000" ${disabled} inputmode="decimal">` +
                `</td>` +
                `<td class="live-col-sanctie">` +
                `<select class="live-sanctie-sel" ${disabled}>` +
                `<option value="">—</option>` +
                `<option value="DNS" ${sanctieUi==='DNS'?'selected':''}>DNS</option>` +
                `<option value="DNF" ${sanctieUi==='DNF'?'selected':''}>DNF</option>` +
                `<option value="DQ-SF" ${sanctieUi==='DQ-SF'?'selected':''}>DQ-SF</option>` +
                `<option value="DQ-DF" ${sanctieUi==='DQ-DF'?'selected':''}>DQ-DF</option>` +
                `<option value="FS" ${sanctieUi==='FS'?'selected':''}>FS</option>` +
                `</select>` +
                `</td>` +
                `<td class="live-col-finish"><span class="live-finish-badge">${r.finishpositie?_ordinaal(r.finishpositie):'—'}</span></td>` +
                `</tr>`;
        }
    }

    const heeftRijders = alleRijders.length > 0;
    const opslaanKnop  = (heeftRijders && !_liveLeesOnly)
        ? `<div class="live-panel-footer"><button class="live-opslaan-btn" id="live-btn-opslaan-panel">💾 Opslaan</button></div>`
        : '';
    const rndColHtml  = heeftRondes ? `<col class="live-col-rondes">` : '';
    const rndHeadHtml = heeftRondes ? `<th class="live-col-rondes" title="Ronden">Rnd</th>` : '';

    return `<div class="live-panel-links" id="live-panel-links"` +
        ` data-dc-id="${escHtml(dcId)}"` +
        ` data-distance-id="${escHtml(String(distanceId ?? ''))}"` +
        ` data-ronde-type="${escHtml(rondeType)}">` +
        `<div class="live-panel-header">${escHtml(rondeNaam)}</div>` +
        `<div class="live-panel-scroll">` +
        `<table class="live-panel-tabel">` +
        `<colgroup>` +
        `<col class="live-col-snr"><col>${rndColHtml}` +
        `<col class="live-col-tijd"><col class="live-col-sanctie"><col class="live-col-finish">` +
        `</colgroup>` +
        `<thead><tr>` +
        `<th>Snr</th><th>Naam</th>${rndHeadHtml}` +
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

    // Invoer events
    panel.querySelectorAll('.live-panel-rij').forEach(rij => {
        const entryId    = parseInt(rij.dataset.panelEntry);
        const tijdInp    = rij.querySelector('.live-tijd-inp');
        const sanctieSel = rij.querySelector('.live-sanctie-sel');

        tijdInp?.addEventListener('blur', () => {
            const ms      = _parseTijdInvoer(tijdInp.value);
            const tijdVal = ms !== null ? _msTijdNaarDisplay(ms) : '';
            tijdInp.value = tijdVal;
            // FS + tijd mogen samen; andere sancties wissen de tijd
            if (ms !== null && sanctieSel?.value && sanctieSel.value !== 'FS') sanctieSel.value = '';
            const sanctie = sanctieSel?.value || '';
            _liveSyncInvoer(entryId, tijdVal, sanctie);
            _liveOngeslagen = true;
            _livePanelHerbereken();
        });
        tijdInp?.addEventListener('input', () => { _liveOngeslagen = true; });

        sanctieSel?.addEventListener('change', () => {
            const sanctie = sanctieSel.value;
            // FS wist de tijd niet; andere sancties wissen de tijd
            if (sanctie && sanctie !== 'FS' && tijdInp?.value.trim()) tijdInp.value = '';
            _liveSyncInvoer(entryId, tijdInp?.value || '', sanctie);
            _liveOngeslagen = true;
            _livePanelHerbereken();
        });
    });

    el('live-btn-opslaan-panel')?.addEventListener('click', () => _liveOpslaanLinksPanel());
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
        const rondes  = rij.dataset.rondes ? (parseInt(rij.dataset.rondes) || null) : null;
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

async function _liveOpslaanLinksPanel() {
    const panel = el('live-panel-links');
    const btn   = el('live-btn-opslaan-panel');
    if (!panel || !btn) return;

    btn.disabled    = true;
    btn.textContent = 'Bezig…';

    // Groepeer entries per rit_id
    const ritMap = new Map(); // rit_id → [{ entry_id, tijd_ms, sanctie }]
    panel.querySelectorAll('.live-panel-rij[data-panel-entry]').forEach(rij => {
        const entryId = parseInt(rij.dataset.panelEntry);
        const ritId   = parseInt(rij.dataset.ritId);
        const inp     = rij.querySelector('.live-tijd-inp');
        const sel     = rij.querySelector('.live-sanctie-sel');
        const sanctie = sel?.value || '';
        // FS: tijd bewaren; overige sancties: tijd = null
        const tijdMs  = (!sanctie || sanctie === 'FS') ? _parseTijdInvoer(inp?.value ?? '') : null;

        if (!ritMap.has(ritId)) ritMap.set(ritId, []);
        ritMap.get(ritId).push({ entry_id: entryId, tijd_ms: tijdMs, sanctie: sanctie || null, notitie: '' });
    });

    let fouten = 0;
    for (const [ritId, results] of ritMap) {
        const rit = _liveRitten.find(r => r.rit_id === ritId);
        if (!rit) continue;
        try {
            const res = await fetch('api/live.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:         'save_rit_results',
                    competition_id: huidigCompId,
                    rit_id:         ritId,
                    results,
                }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            // Update lokale state — gebruik finishposities van server (correct voor PK/rondes)
            const serverFpP = data.finishposities ?? {};
            const pm        = _berekenPosities(results, _liveSysteem === 'full-final'); // fallback
            rit.rijders = rit.rijders.map(r => {
                const g = results.find(x => x.entry_id === r.entry_id);
                if (!g) return r;
                const fp = Object.prototype.hasOwnProperty.call(serverFpP, r.entry_id)
                    ? (serverFpP[r.entry_id] ?? null)
                    : (pm.get(r.entry_id) ?? null);
                return { ...r, tijd_ms: g.tijd_ms, sanctie: g.sanctie, finishpositie: fp };
            });
        } catch { fouten++; }
    }

    _liveOngeslagen = false;

    if (btn) {
        btn.disabled    = false;
        btn.textContent = fouten ? `⚠ ${fouten} fout(en)` : '✓ Opgeslagen';
        btn.classList.toggle('btn-opgeslagen', !fouten);
        setTimeout(() => {
            if (btn) { btn.textContent = '💾 Opslaan alle ritten'; btn.classList.remove('btn-opgeslagen'); }
        }, 2500);
    }

    // Update carousel-kaarten + dropdown-iconen voor alle opgeslagen ritten
    const dropdown = el('live-nav-dropdown');
    const geziendeRonden = new Set(); // voorkom dubbele volgende-ronde triggers

    ritMap.forEach((_, ritId) => {
        const i = _liveRitten.findIndex(r => r.rit_id === ritId);
        if (i < 0) return;

        _liveHerbereken(i);
        _liveActiveerWisselDropdowns(i);

        if (dropdown?.options[i]) {
            const r     = _liveRitten[i];
            const icoon = _liveRitCompleet(r) ? '✓' : _liveRitDeels(r) ? '◑' : '○';
            dropdown.options[i].text = icoon + ' ' + r.rit_naam;
        }

        // Volgende ronde seeden (zelfde logica als _liveOpslaanRit)
        if (!_liveLeesOnly) {
            const rit = _liveRitten[i];
            const rondeKey = `${rit.dc_id}|${rit.distance_id}|${rit.ronde_type}`;
            if (!geziendeRonden.has(rondeKey)) {
                geziendeRonden.add(rondeKey);
                const volgende = _volgendeRondeType(rit.dc_id, rit.distance_id, rit.ronde_type);
                if (volgende) {
                    const compleet = _liveRondeCompleet(rit.dc_id, rit.distance_id, rit.ronde_type);
                    _liveGenereerVolgendeRonde(rit.dc_id, rit.distance_id, rit.ronde_type, volgende, compleet);
                } else {
                    el('live-ronde-compleet')?.remove();
                }
            }
        }
    });
}

// Markeer de actieve kaart en toon/verberg opslaan-knoppen
function _liveUpdateKaartActief(idx) {
    document.querySelectorAll('.live-carousel-card').forEach((card, i) => {
        const isActief = i === idx;
        card.classList.toggle('live-card-actief', isActief);
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
            // Sanctie wissen bij tijdinvoer, TENZIJ het een FS is (FS + tijd mogen samen)
            if (ms !== null && sanctieSel?.value && sanctieSel.value !== 'FS') sanctieSel.value = '';
            const sanctie = sanctieSel?.value || '';
            _liveSyncInvoer(r.entry_id, tijdVal, sanctie);
            _liveOngeslagen = true;
            _liveHerbereken(idx);
        });
        tijdInp?.addEventListener('input', () => { _liveOngeslagen = true; });

        sanctieSel?.addEventListener('change', () => {
            const sanctie = sanctieSel.value;
            // Tijd wissen bij sanctie, TENZIJ het een FS is
            if (sanctie && sanctie !== 'FS' && tijdInp?.value.trim()) tijdInp.value = '';
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

    // PK-punten opslaan
    el('live-btn-punten-' + idx)?.addEventListener('click', () => _livePuntenOpslaan(idx));

    // Import-knop: toggle panel + vul mappenlijst
    el('live-btn-import-'  + idx)?.addEventListener('click',  () => _liveImportToggle(idx));
    el('live-import-map-'  + idx)?.addEventListener('change', () => _liveImportMapGekozen(idx));
    el('live-import-sel-'  + idx)?.addEventListener('change', () => _liveImportPreview(idx));
    el('live-import-laad-' + idx)?.addEventListener('click',  () => _liveImportLaad(idx));
}

// ── Puntenkoers: punten opslaan + badges bijwerken ────────────────────────────

async function _livePuntenOpslaan(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit?.heat_id) { alert('Geen heat gevonden voor deze rit.'); return; }

    const btn = el('live-btn-punten-' + ritIdx);
    if (btn) { btn.disabled = true; btn.textContent = 'Bezig…'; }

    // Verzamel punten uit verborgen data-inputs
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);
    const aanpassingen = [];
    kaart?.querySelectorAll('.live-punten-inp[data-pk-entry]').forEach(inp => {
        const entryId = parseInt(inp.dataset.pkEntry);
        const val     = inp.value.trim();
        aanpassingen.push({
            entry_id: entryId,
            punten:   val !== '' ? parseFloat(val) : null,
        });
    });

    try {
        const res = await fetch('api/live.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'sla_punten_op', heat_id: rit.heat_id, aanpassingen }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Lokale state bijwerken
        aanpassingen.forEach(a => {
            const r = rit.rijders.find(x => x.entry_id === a.entry_id);
            if (r) r.punten = a.punten;
        });

        // Finish-badges bijwerken op punten-ranking
        _liveUpdatePuntenBadges(ritIdx);

        if (btn) {
            btn.disabled  = false;
            btn.textContent = '✓ Opgeslagen';
            btn.classList.add('btn-opgeslagen');
            setTimeout(() => { if (btn) { btn.textContent = '💾 Punten opslaan'; btn.classList.remove('btn-opgeslagen'); } }, 2500);
        }
    } catch (e) {
        alert('Fout bij opslaan punten: ' + e.message);
        if (btn) { btn.disabled = false; btn.textContent = '💾 Punten opslaan'; }
    }
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
        const rondes = rij?.dataset.rondes ? (parseInt(rij.dataset.rondes) || null) : (r.rondes ?? null);
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

const _IMPORT_MAP_KEY  = 'liveImportMap';  // localStorage sleutel voor onthouden map
const _importCsvCache  = new Map();        // key: "map|filename" → geparseerde rows

// Toggle import-panel; laad mappenlijst bij eerste opening
async function _liveImportToggle(ritIdx) {
    const panel  = el('live-import-panel-' + ritIdx);
    const mapSel = el('live-import-map-'   + ritIdx);
    if (!panel) return;

    const openend = !panel.classList.contains('verborgen');
    panel.classList.toggle('verborgen', openend);
    if (openend || mapSel.dataset.geladen) return;   // sluiten of al geladen

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

        const mappen = data.mappen || [];
        if (mappen.length === 0) {
            mapSel.innerHTML = '<option value="">— geen mappen gevonden —</option>';
        } else {
            const onthouden = localStorage.getItem(_IMPORT_MAP_KEY) || '';
            mapSel.innerHTML =
                '<option value="">— kies een map —</option>' +
                mappen.map(m =>
                    `<option value="${escHtml(m)}"${m === onthouden ? ' selected' : ''}>${escHtml(m)}</option>`
                ).join('');
            // Als onthouden map beschikbaar is: laad direct de bestandenlijst
            if (onthouden && mappen.includes(onthouden)) {
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

        const files = data.files || [];
        if (files.length === 0) {
            fileSel.innerHTML = '<option value="">— geen CSV-bestanden —</option>';
        } else {
            fileSel.innerHTML = files.map(f =>
                `<option value="${escHtml(f)}"${f === data.preselect ? ' selected' : ''}>${escHtml(f)}</option>`
            ).join('');
            fileSel.disabled = false;
            laadBtn.disabled = false;
        }
    } catch(e) {
        fileSel.innerHTML = `<option value="">⚠ ${escHtml(e.message)}</option>`;
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

        // Tijd invullen + rondes zetten als data-attribuut; sanctie ongemoeid laten
        [`[data-entry="${r.entry_id}"]`, `[data-panel-entry="${r.entry_id}"]`].forEach(cssSelStr => {
            document.querySelectorAll(cssSelStr).forEach(rij => {
                const t = rij.querySelector('.live-tijd-inp');
                if (t && t !== document.activeElement) t.value = tijdVal;
                if (rondenVal != null) {
                    rij.dataset.rondes = rondenVal;
                    // Bijwerk de rondes-cel (bestaat altijd voor lange-afstand heats)
                    const rndCel = rij.querySelector('.live-col-rondes');
                    if (rndCel) rndCel.textContent = rondenVal;
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

    // Map DB-sanctie terug naar UI-waarde voor weergave in dropdown
    const sanctieUiMap = {
        'DNS':    'DNS',
        'DNF':    'DNF',
        'DSQ-SF': 'DQ-SF',
        'DSQ-TF': 'DQ-DF',
        'FS1':    'FS',
    };
    const sanctieUi = sanctieUiMap[r.sanctie] || r.sanctie || '';

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
        (heeftRondes ? `<td class="live-col-rondes">${r.rondes != null ? r.rondes : '—'}</td>` : '') +
        `<td class="live-col-tijd">` +
        `<input type="text" class="live-tijd-inp" value="${escHtml(tijdVal)}"` +
        ` placeholder="0:00.000" ${disabled} inputmode="decimal">` +
        `</td>` +
        `<td class="live-col-sanctie">` +
        `<select class="live-sanctie-sel" ${disabled}>` +
        `<option value="">—</option>` +
        `<option value="DNS"   ${sanctieUi === 'DNS'   ? 'selected' : ''}>DNS</option>` +
        `<option value="DNF"   ${sanctieUi === 'DNF'   ? 'selected' : ''}>DNF</option>` +
        `<option value="DQ-SF" ${sanctieUi === 'DQ-SF' ? 'selected' : ''}>DQ-SF</option>` +
        `<option value="DQ-DF" ${sanctieUi === 'DQ-DF' ? 'selected' : ''}>DQ-DF</option>` +
        `<option value="FS"    ${sanctieUi === 'FS'    ? 'selected' : ''}>FS</option>` +
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
        const dropdown = el('live-nav-dropdown');
        if (dropdown) dropdown.value = nieuweIdx;
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
        const rondes      = rij?.dataset.rondes ? (parseInt(rij.dataset.rondes) || null) : (r.rondes ?? null);
        return {
            entry_id: r.entry_id,
            tijd_ms:  tijdOpslaan,
            sanctie:  sanctie || null,
            notitie:  r.notitie || '',
            rondes,
        };
    });

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

        // Update dropdown-icoon voor deze rit
        const dropdown = el('live-nav-dropdown');
        if (dropdown) {
            const opt = dropdown.options[ritIdx];
            if (opt) {
                const r    = _liveRitten[ritIdx];
                const icoon = _liveRitCompleet(r) ? '✓' : _liveRitDeels(r) ? '◑' : '○';
                opt.text   = icoon + ' ' + r.rit_naam;
            }
        }

        // Na elke save: startlijst volgende ronde bijwerken (als die nog niet bezig is)
        const rit2 = _liveRitten[ritIdx];
        if (rit2 && !_liveLeesOnly) {
            const volgende2 = _volgendeRondeType(rit2.dc_id, rit2.distance_id, rit2.ronde_type);
            if (volgende2) {
                const compleet = _liveRondeCompleet(rit2.dc_id, rit2.distance_id, rit2.ronde_type);
                _liveGenereerVolgendeRonde(rit2.dc_id, rit2.distance_id, rit2.ronde_type, volgende2, compleet);
            } else {
                el('live-ronde-compleet')?.remove();
            }
        }

    } catch(e) {
        if (btn) {
            btn.disabled    = false;
            btn.textContent = '💾 Opslaan';
        }
        alert('Fout bij opslaan: ' + e.message);
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

        // Herlaad ritten van API — GEEN navigatie naar nieuwe ronde
        const herlaadRes = await fetch('api/live.php?competition_id=' + encodeURIComponent(huidigCompId));
        if (herlaadRes.ok) {
            const herlaadData = await herlaadRes.json();
            if (!herlaadData.error) {
                _liveRitten     = herlaadData.ritten     || [];
                _liveCatConfigs = herlaadData.catConfigs || {};
                _liveSysteem    = herlaadData.systeem    ?? _liveSysteem;
                // Herbereken huidige kaart, maar NIET navigeren
                _liveHerbereken(_liveHuidigIdx);
            }
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
