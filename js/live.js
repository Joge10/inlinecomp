/* InlineComp – Live verwerking */

// ── Globale state ──────────────────────────────────────────────────────────────

let _liveRitten      = [];      // alle ritten geladen van API
let _liveCatConfigs  = {};      // catConfigs van API
let _liveSysteem     = null;    // tijdschema-systeem ('full-final' | 'internationaal-nieuw' | ...)
let _liveHuidigIdx   = -1;      // huidige carousel-index (-1 = nog niet gezet)
let _liveOngeslagen  = false;   // onopgeslagen wijzigingen
let _liveLeesOnly    = false;   // geen schrijfrechten

// Multi-day filter state (0 = nog niet gezet). Default-dag wordt bij elke
// laad bepaald: cache → vandaag-match → dag 1. Cache verloopt bij comp-wissel
// via _liveActieveDagCompId. _liveTsFetched zorgt dat we het tijdschema (voor
// dcDagMap) maar één keer per comp achter de schermen ophalen.
let _liveActieveDag       = 0;
let _liveActieveDagCompId = null;
let _liveTsFetched        = null;
let _liveDcDagMap         = new Map();   // dc_id → dagNr (gevuld na ts-load)

// Filter voor de heat-dropdown (○ / ◑ / ✓). Standaard alles aan.
let _liveFilter = { geen_lijst: true, geen_resultaat: true, deels: true, compleet: true };

// Afvalkoers-state per rit (key = ritIdx).
//   afgevallen      : [{entry_id, plek, sanctie}] gesetste afvallingen, volgorde van toevoeging.
//   voorlopig_2de   : [entry_id, ...] geselecteerde "2de"-rijders nog niet-geset.
//   voorlopig_1ste  : [entry_id, ...] geselecteerde "1ste"-rijders nog niet-geset.
//   geselecteerd    : [entry_id, ...] huidige startnummer-selectie.
// Correcties: klik op een afgevallen-kaart zet rijder terug in koers.
let _afvalState = {};

// Globale reset-hook: wordt aangeroepen door startlijst-wis zodat de afval-
// state niet stale blijft hangen na het wissen van een loting (anders
// blijven oude afvallers zichtbaar in het paneel tot een hard refresh).
// Reset gericht op de (dc, distance) die gewist is — andere ritten houden
// hun lokale state (operator kan in een andere heat bezig zijn met afvallers).
// Bij volgende module-wissel naar Live wordt de gewiste state opnieuw uit
// verse DB-data opgebouwd via _afvalInitVoorRit.
window.liveAfvalResetVoorDC = function(dcIds, distanceId) {
    if (!Array.isArray(_liveRitten) || !_liveRitten.length) return;
    const dcSet = new Set(
        Array.isArray(dcIds)
            ? dcIds.map(String)
            : String(dcIds || '').split(',').map(s => s.trim()).filter(Boolean)
    );
    const distStr = distanceId == null ? '' : String(distanceId);
    _liveRitten.forEach((rit, idx) => {
        if (!rit || !(idx in _afvalState)) return;
        const ritDc   = String(rit.dc_id || '');
        const ritDist = String(rit.distance_id || '');
        if (!dcSet.has(ritDc)) return;
        if (distStr && ritDist !== distStr) return;
        delete _afvalState[idx];
    });
};

// ── Multi-day helpers ─────────────────────────────────────────────────────────

// Bouw dcDagMap uit huidigTijdschema; lege Map als ts niet (voor deze comp)
// geladen is. Wordt aangeroepen na de fetch en wanneer de ts-data binnenkomt.
function _liveBouwDcDagMap() {
    if (typeof huidigTijdschema === 'undefined' || !huidigTijdschema) return new Map();
    if (huidigTijdschema.competition_id !== huidigCompId)             return new Map();
    if (typeof _tsBouwDcDagMap !== 'function')                        return new Map();
    return _tsBouwDcDagMap(huidigTijdschema);
}

// Welke dag hoort bij deze rit? Combi-ritten: dag van eerste lid. Onbekende
// dc_id → dag 1 (zelfde fallback als tekenlijsten/startlijsten).
function _liveDagVanRit(rit) {
    if (!rit) return 1;
    if (rit.is_combi && rit.combi_leden?.length) {
        return _liveDcDagMap.get(rit.combi_leden[0].dc_id) ?? 1;
    }
    return _liveDcDagMap.get(rit.dc_id) ?? 1;
}

// Achtergrond-fetch van tijdschema als nog niet geladen voor deze comp.
// Eenmalig per comp; bij binnenkomst dcDagMap herbouwen en carousel re-renderen.
async function _liveAchtergrondLaadTijdschema() {
    if (!huidigCompId || _liveTsFetched === huidigCompId) return;
    _liveTsFetched = huidigCompId;
    try {
        const res  = await fetch(`api/tijdschema.php?competition_id=${encodeURIComponent(huidigCompId)}`);
        if (!res.ok) return;
        const data = await res.json();
        if (data?.error || !data) return;
        if (typeof huidigTijdschema === 'undefined' || !huidigTijdschema
            || huidigTijdschema.competition_id !== huidigCompId) {
            huidigTijdschema  = data;
            if (typeof tijdschemaVersion !== 'undefined') {
                tijdschemaVersion = data?.tijdschema_version ?? 0;
            }
        }
        _liveDcDagMap = _liveBouwDcDagMap();
        // Re-render alleen als gebruiker nog op live-pagina is
        const pg = document.getElementById('page-live');
        if (pg && pg.classList.contains('active')) _liveRenderCarousel();
    } catch { /* silent — multi-day is optioneel verbeterend */ }
}

// Heeft deze wedstrijd meerdere dagen? Op basis van het al-of-niet aanwezige
// tijdschema. Bij niet-geladen ts: false (toont geen tabs tot ts binnenkomt).
function _liveDagInfo() {
    if (typeof huidigTijdschema === 'undefined' || !huidigTijdschema)  return null;
    if (huidigTijdschema.competition_id !== huidigCompId)              return null;
    if (typeof _tsBouwDagInfo !== 'function')                          return null;
    return _tsBouwDagInfo(huidigTijdschema?.blokken ?? []);
}

// Zet _liveActieveDag op een zinvolle dag als hij nog 0 (= niet geïnitialiseerd)
// is, of buiten bereik valt. Logica:
//   • match op vandaag-datum → die dag
//   • anders (voor of na het evenement) → dag 1
// Wordt aangeroepen vanuit zowel toonLivePagina (eerste laad) als
// _liveRenderCarousel (re-render na silent tijdschema-fetch).
function _liveInitActieveDagAlsNodig(dagInfo) {
    if (!dagInfo?.isMultiDag) return;
    if (_liveActieveDag >= 1 && _liveActieveDag <= dagInfo.dagLabels.length) return;
    const vandaagStr = new Date().toISOString().substring(0, 10);
    const match      = dagInfo.dagLabels.find(d => d.datum === vandaagStr);
    _liveActieveDag  = match ? match.nr : 1;
}

// Vind de index van de volgende/vorige rit IN DE ACTIEVE DAG. Skipt ritten
// van andere dagen. dir = +1 (volgende) of -1 (vorige). Geeft -1 als geen
// passende rit gevonden.
function _liveZoekIdxOpDag(vanIdx, dir, dag) {
    const n = _liveRitten.length;
    if (n === 0) return -1;
    let i = vanIdx + dir;
    while (i >= 0 && i < n) {
        if (_liveDagVanRit(_liveRitten[i]) === dag) return i;
        i += dir;
    }
    return -1;
}

// Eerste niet-voltooide rit van dag X, of eerste rit van X, of -1.
function _liveEersteIdxOpDag(dag) {
    let eerste = -1;
    for (let i = 0; i < _liveRitten.length; i++) {
        if (_liveDagVanRit(_liveRitten[i]) !== dag) continue;
        if (eerste === -1) eerste = i;
        if (!_liveRitCompleet(_liveRitten[i])) return i;
    }
    return eerste;
}

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

        const rittenRaw      = data.ritten     || [];
        _liveRittenOrigCount = rittenRaw.length;
        _liveRitten          = _liveMergeCombiritten(rittenRaw);
        _liveCatConfigs      = data.catConfigs || {};
        _liveSysteem         = data.systeem    || null;
        _liveOngeslagen      = false;

        // Corrigeer finishposities voor PK-ritten op basis van punten→rondes→tid.
        // DB-waarden kunnen verkeerd zijn als ze met een oudere versie zijn opgeslagen.
        _liveRitten.forEach(_liveHerrekenPKFinishposities);

        // is_photofinish (uit DB) → lokale _wisselt-vlag. Zo overleeft de lock
        // op gewisselde rijders een page-refresh: dropdowns blijven verborgen
        // achter het lock-badge tot de operator de CSV opnieuw importeert.
        _liveRitten.forEach(rit => {
            if (!rit?.rijders) return;
            rit.rijders.forEach(r => {
                if (r.is_photofinish) r._wisselt = true;
            });
        });

        if (_liveRitten.length === 0) {
            container.innerHTML = '<div class="status-msg info">Geen ritten gevonden. Genereer eerst een tijdschema met startlijsten.</div>';
            return;
        }

        // Multi-day: reset dag-cache bij wedstrijd-wissel. Bouw dcDagMap als
        // tijdschema al beschikbaar is; anders silent background fetch en
        // re-render zodra tijdschema binnenkomt.
        if (_liveActieveDagCompId !== huidigCompId) {
            _liveActieveDag       = 0;
            _liveActieveDagCompId = huidigCompId;
        }
        _liveDcDagMap = _liveBouwDcDagMap();
        const dagInfo = _liveDagInfo();
        if (!dagInfo) _liveAchtergrondLaadTijdschema();

        // Bepaal default actieveDag bij multi-day: cached → vandaag → 1
        _liveInitActieveDagAlsNodig(dagInfo);

        // Bewaar huidige positie als die nog geldig is (terugkeer na module-wisseling),
        // anders: eerste onvoltooide rit van actieve dag (multi-day) of overall.
        const eersteOnvolledig = dagInfo?.isMultiDag
            ? _liveEersteIdxOpDag(_liveActieveDag)
            : _liveRitten.findIndex(r => !_liveRitCompleet(r));
        if (_liveHuidigIdx < 0 || _liveHuidigIdx >= _liveRitten.length) {
            _liveHuidigIdx = eersteOnvolledig >= 0 ? eersteOnvolledig : 0;
        } else if (dagInfo?.isMultiDag
                   && _liveDagVanRit(_liveRitten[_liveHuidigIdx]) !== _liveActieveDag) {
            // Cached rit valt niet op actieve dag — spring naar eerste van dag
            _liveHuidigIdx = eersteOnvolledig >= 0 ? eersteOnvolledig : 0;
        }

        _liveRenderCarousel();
        _liveInitKeyboard();

    } catch(e) {
        container.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
        return;
    }
}

// ── Silent reload — geen spinner, behoudt scroll + actieve idx ──────────────
// Wordt aangeroepen bij:
//   • klik op refresh-knop (met visuele draaiende-knop-feedback)
//   • na elke navigatie naar een andere rit (puur achter de schermen, zodat
//     AoC-DNS en andere parallele updates onmiddellijk zichtbaar zijn)
// Skipt onopgeslagen wijzigingen: als _liveOngeslagen=true → niets doen
// (gebruiker is aan het typen; we mogen z'n input niet wegblazen).
async function _liveHerlaadStil(forceerRender = false) {
    if (!huidigCompId || _liveOngeslagen) return false;
    try {
        const res  = await fetch('api/live.php?competition_id=' + encodeURIComponent(huidigCompId));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        const rittenRaw = data.ritten || [];
        _liveRittenOrigCount = rittenRaw.length;
        const nieuweRitten   = _liveMergeCombiritten(rittenRaw);
        _liveCatConfigs      = data.catConfigs || {};
        _liveSysteem         = data.systeem    || null;

        nieuweRitten.forEach(_liveHerrekenPKFinishposities);
        nieuweRitten.forEach(rit => {
            if (!rit?.rijders) return;
            rit.rijders.forEach(r => { if (r.is_photofinish) r._wisselt = true; });
        });

        // Detecteer of er iets gewijzigd is voor de huidige rit (= AoC heeft
        // DNS toegevoegd of een andere jury heeft een tijd ingevuld). Als ja:
        // re-render zodat operator de update meteen ziet.
        const huidigOud = _liveRitten[_liveHuidigIdx];
        const huidigNwId = huidigOud ? (huidigOud.rit_id ?? huidigOud.is_combi ? huidigOud.combi_leden?.[0]?.rit_id : null) : null;
        const huidigNw  = huidigNwId != null
            ? nieuweRitten.find(r => (r.rit_id ?? r.combi_leden?.[0]?.rit_id) === huidigNwId)
            : null;

        _liveRitten   = nieuweRitten;
        _liveDcDagMap = _liveBouwDcDagMap();

        // Bewaar idx als rit nog bestaat; anders val terug op 0
        if (huidigNw) {
            const nieuwIdx = _liveRitten.indexOf(huidigNw);
            if (nieuwIdx >= 0) _liveHuidigIdx = nieuwIdx;
        } else {
            _liveHuidigIdx = Math.min(_liveHuidigIdx, _liveRitten.length - 1);
            if (_liveHuidigIdx < 0) _liveHuidigIdx = 0;
        }

        // Wijziging gedetecteerd voor huidige rit (verschillende rijder-counts,
        // sancties of finishposities) → re-render. forceerRender=true bij
        // refresh-knop overrult de change-detectie.
        const wijz = forceerRender || _liveRitDataGewijzigd(huidigOud, huidigNw);
        if (wijz) _liveRenderCarousel();
        return true;
    } catch (e) {
        console.warn('[live] herlaad stil mislukt:', e);
        return false;
    }
}

// Vergelijk twee rit-snapshots; true als er iets veranderd is dat een re-render
// rechtvaardigt (sanctie of finishpositie). Bewust GEEN diepe vergelijking —
// alleen velden die de UI raken.
function _liveRitDataGewijzigd(oud, nieuw) {
    if (!oud || !nieuw) return true;
    const oudR = oud.rijders || [], nwR = nieuw.rijders || [];
    if (oudR.length !== nwR.length) return true;
    for (let i = 0; i < oudR.length; i++) {
        const a = oudR[i], b = nwR[i];
        if ((a?.sanctie || '') !== (b?.sanctie || ''))             return true;
        if ((a?.finishpositie ?? null) !== (b?.finishpositie ?? null)) return true;
        if ((a?.tijd_ms      ?? null) !== (b?.tijd_ms      ?? null)) return true;
    }
    return false;
}

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

// Origineel aantal ritten uit DB (vóór combi-merge). Wordt gebruikt voor de
// "X / Y" carousel-teller — Y blijft het oorspronkelijke aantal zodat de
// gebruiker volgorde-getallen herkent uit het tijdschema.
let _liveRittenOrigCount = 0;

// Maakt één gecombineerde titel uit een lijst strings door common prefix +
// common suffix te vinden en alleen het verschillende middenstuk te samengevoegen.
//   ["Junioren A Mannen 1km", "Junioren A Vrouwen 1km"]
// → "Junioren A Mannen + Vrouwen 1km"
function _liveCombiKortsteTitel(strs) {
    const arr = strs.filter(Boolean);
    if (arr.length === 0) return '';
    if (arr.length === 1) return arr[0];

    // Common prefix
    let prefixLen = arr[0].length;
    for (const s of arr.slice(1)) {
        let i = 0;
        while (i < prefixLen && i < s.length && arr[0][i] === s[i]) i++;
        prefixLen = i;
    }
    const prefix = arr[0].slice(0, prefixLen);

    // Common suffix (op de strings ná de prefix)
    const restjes = arr.map(s => s.slice(prefixLen));
    let suffixLen = restjes[0].length;
    for (const s of restjes.slice(1)) {
        let i = 0;
        while (i < suffixLen && i < s.length
               && restjes[0][restjes[0].length - 1 - i] === s[s.length - 1 - i]) i++;
        suffixLen = i;
    }
    const suffix = suffixLen > 0 ? restjes[0].slice(restjes[0].length - suffixLen) : '';

    // Middenstukken samenvoegen
    const middens = restjes.map(s => suffixLen > 0 ? s.slice(0, s.length - suffixLen) : s);
    // Strip whitespace tussen prefix en midden, en midden en suffix, om
    // dubbele spaties te voorkomen wanneer prefix/suffix al eindigen/beginnen
    // op een spatie.
    return prefix + middens.map(m => m.trim()).join(' + ') + suffix;
}

// Voegt ritten met hetzelfde combi_group samen tot één virtuele "merged" rit.
// Behoudt apart-rijden ritten ongewijzigd. Op de gemergde rit:
//   is_combi: true
//   combi_leden: [{rit_id, heat_id, dc_id, distance_id, dc_naam, race_type}]
//   rijders: alle leden-rijders + per rijder _combi_rit_id voor save-routing
function _liveMergeCombiritten(rittenRaw) {
    const result = [];
    const groepIdx = new Map(); // combi_group → index in result

    for (const r of rittenRaw) {
        if (r.combi_group == null) {
            result.push(r);
            continue;
        }
        if (!groepIdx.has(r.combi_group)) {
            // Eerste lid van deze groep — start gemergde rit als basis
            const combi = {
                ...r,
                is_combi: true,
                combi_leden: [{
                    rit_id:        r.rit_id,
                    heat_id:       r.heat_id,
                    dc_id:         r.dc_id,
                    dc_naam:       r.dc_naam,
                    distance_id:   r.distance_id,
                    afstand_naam:  r.afstand_naam,
                    race_type:     r.race_type,
                    rit_naam:      r.rit_naam,
                }],
                rijders: r.rijders.map(rij => ({
                    ...rij,
                    _combi_rit_id:  r.rit_id,
                    _combi_heat_id: r.heat_id,
                    _combi_dc_id:   r.dc_id,
                })),
            };
            groepIdx.set(r.combi_group, result.length);
            result.push(combi);
        } else {
            const combi = result[groepIdx.get(r.combi_group)];
            combi.combi_leden.push({
                rit_id:        r.rit_id,
                heat_id:       r.heat_id,
                dc_id:         r.dc_id,
                dc_naam:       r.dc_naam,
                distance_id:   r.distance_id,
                afstand_naam:  r.afstand_naam,
                race_type:     r.race_type,
                rit_naam:      r.rit_naam,
            });
            for (const rij of r.rijders) {
                combi.rijders.push({
                    ...rij,
                    _combi_rit_id:  r.rit_id,
                    _combi_heat_id: r.heat_id,
                    _combi_dc_id:   r.dc_id,
                });
            }
        }
    }

    // Voor elke gemergde rit: gecombineerde titel + dc_naam herbouwen
    for (const r of result) {
        if (r.is_combi) {
            const ritNamen = r.combi_leden.map(l => l.rit_naam);
            const dcNamen  = r.combi_leden.map(l => l.dc_naam);
            r.rit_naam = _liveCombiKortsteTitel(ritNamen);
            r.dc_naam  = _liveCombiKortsteTitel(dcNamen);
        }
    }
    return result;
}

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
    // Bij combi-ritten kijken we of MINSTENS één leden-heat bestaat — een combi
    // met deels-aangemaakte heats (een leden zonder rijders) telt nog wel als heat.
    if (rit.is_combi) {
        const heeftLedenHeat = rit.combi_leden.some(l => l.heat_id !== null);
        return heeftLedenHeat && rit.rijders && rit.rijders.length > 0;
    }
    return rit.heat_id !== null && rit.rijders && rit.rijders.length > 0;
}

// Herbereken finishposities voor een PK-rit: punten DESC → rondes DESC (null=Infinity) → tid ASC.
// Corrigeert evt. foute waarden uit de DB (opgeslagen met oudere versie zonder rondes-stap).
// Bij combi-ritten: per leden apart (elke categorie krijgt eigen 1-N nummering).
function _liveHerrekenPKFinishposities(rit) {
    if (rit.race_type !== 'puntenkoers') return;

    const puntenMap = new Map();
    rit.rijders.forEach(r => { if (r.punten != null) puntenMap.set(r.entry_id, r.punten); });

    const subsets = rit.is_combi
        ? rit.combi_leden.map(l => rit.rijders.filter(r => r._combi_rit_id === l.rit_id))
        : [rit.rijders];

    for (const subset of subsets) {
        const metTijd = subset.filter(r => r.tijd_ms !== null && r.tijd_ms > 0);

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

// Invoer parseren → ms (of null bij ongeldig / leeg)
// Accepteert:
//   "47.321"   → 47321         (SS.mmm — met punt)
//   "47.0"     → 47000         (1-2 decimalen worden recht aangevuld)
//   "1:23.456" → 83456         (M:SS.mmm — met dubbele punt)
//   "0:47.0"   → 47000         (idem)
//   "9567"     → 9567          (4-7 cijfers zonder interpunctie → MMSSmmm:
//                              laatste 3 = mmm, daarvoor 2 = SS, rest = MM)
//   "45632"    → 45632         ("45.632")
//   "1123452"  → 683452        ("11:23.452")
// Bewust GEWEIGERD (return null):
//   "47", "5", "123"           1-3 cijfers zonder interpunctie — dubbelzinnig
//                              (zou 47 sec kunnen zijn of 0.047? Operator moet
//                              expliciet zijn: "47.0", "47000" of "0:47.0").
//   "12345678", "47x"          Te lang of niet-numerieke chars.
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
    } else if (/^\d{4,7}$/.test(s)) {
        // 4-7 cijfers zonder interpunctie: snelle invoer-modus.
        // Indeling: …MM SS mmm — laatste 3 cijfers = ms, daarvoor 2 = sec,
        // rest = min. 4 digits → 1-digit sec, 0 min. 5 → 2-digit sec, 0 min.
        // 6 → 1-digit min. 7 → 2-digit min.
        milliseconden = parseInt(s.slice(-3)) || 0;
        const ssStr   = s.slice(-5, -3);
        seconden      = ssStr ? (parseInt(ssStr) || 0) : 0;
        const mmStr   = s.slice(0, -5);
        minuten       = mmStr ? (parseInt(mmStr) || 0) : 0;
    } else {
        // 1-3 cijfers zonder interpunctie, 8+ cijfers, of rommel: ongeldig.
        // Operator moet expliciet zijn met punt of dubbele punt.
        return null;
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
// Sancties die betekenen "geen geldige finish" — uitval of DQ. FS/RR/W1/W2
// zijn waarschuwingen, die rijders horen normaal mee te tellen voor Q/q-
// kwalificatie. Spiegelt PHP-side $sanctiesUit / isEindSanctie() in
// _uitslag_helper.php zodat de panel-display dezelfde set rijders rangschikt
// als de daadwerkelijke next-round-generation.
const _SANCTIE_GEEN_FINISH = new Set(['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF']);

// ── Multi-sanctie helpers ────────────────────────────────────────────────────
// Rijder kan meerdere codes hebben in 1 heat (comma-separated string als
// W1,W2,DQ-SF). Set.has(string) werkt dan niet meer — gebruik deze helpers.
function _liveSancties(s) {
    if (!s) return [];
    return String(s).split(',').map(x => x.trim()).filter(Boolean);
}
function _liveSanctieHeeft(s, code) {
    if (!s) return false;
    return _liveSancties(s).includes(code);
}
function _liveSanctieHeeftSet(s, set) {
    if (!s || !set) return false;
    return _liveSancties(s).some(c => set.has(c));
}

// Ex-aequo-ranking volgens reglement: gelijke tijden krijgen dezelfde positie
// (1,2,3,3,5). Aansluitend op api/_uitslag_helper.php::berekenExAequoRangs(),
// die de uitslag-laag gebruikt — beide systemen (full-final én internationaal)
// horen reglementair ex-aequo te krijgen bij 100% gelijke tijden.
// De parameter blijft staan voor terugcompat; aanroepers geven 'true' mee.
function _berekenPosities(entries, gebruikGelijkspel = true, isAfvalkoers = false) {
    // Finishers: heeft tijd, niet ranked_last, niet not_ranked (FS wél meenemen op tijd)
    const finishers = entries
        .filter(e => e.tijd_ms > 0 && !_liveSanctieHeeftSet(e.sanctie, _SANCTIE_RANKED_LAST) && !_liveSanctieHeeftSet(e.sanctie, _SANCTIE_NOT_RANKED))
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
    // RANKED_LAST = DNF / DQ-TF / DNS → positie N+1 (gedeeld laatste).
    // Uitzondering: in afvalkoers krijgt DNS GEEN positie — niet gestart =
    // niet in de uitslag. Backend doet hetzelfde (zie api/live.php).
    const rankedLast = entries.filter(e =>
        _liveSanctieHeeftSet(e.sanctie, _SANCTIE_RANKED_LAST)
        && !(isAfvalkoers && _liveSanctieHeeft(e.sanctie, 'DNS'))
    );
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

// Volgorde van rondes voor "direct vorige ronde" detectie. Runner-up zit
// parallel aan HF (zelfde rank) — dat is voldoende voor onze PF-vergelijking.
const _RONDE_RANK = {
    heats:        1,
    kwartfinale:  2,
    halve_finale: 3,
    runner_up:    3,
    finale_b:     4,
    finale_a:     4,
    finale:       4,
};

// Heeft een rijder een photofinish-marker uit zijn DIRECT VOORGAANDE ronde
// in deze dc+afstand? Bedoeld voor het 📷-icoon naast de naam in een opvolgende
// startlijst — zodat iedereen ziet WAAROM iemand er staat. Zodra de rijder
// een ronde verder is gegaan zonder swap (= geen is_photofinish in die
// tussenronde), verdwijnt het icoontje weer.
//
// `rijder`     = de rijder die nu gerenderd wordt
// `currentRit` = de rit waarin hij nu staat (bepaalt ronde-context + dc/dist)
function _liveHeeftPhotofinishVorigeRonde(rijder, currentRit) {
    const lic = rijder?.person_license;
    if (!lic || !currentRit) return false;
    const curRank = _RONDE_RANK[currentRit.ronde_type] ?? 0;
    if (curRank <= 1) return false;  // heats heeft geen voorganger

    // Effectieve dc/distance van deze rijder — combi-rijders dragen de
    // categorie van hun leden, niet die van de geredgrteerde rit.
    const cDc   = rijder._combi_dc_id       ?? currentRit.dc_id;
    const cDist = rijder._combi_distance_id ?? currentRit.distance_id;

    // Zoek meest recente eerdere ronde waarin deze persoon zat (zelfde
    // dc+afstand) en check of die result_photofinish heeft.
    let bestRank = 0;
    let bestPF   = 0;
    for (const rit of _liveRitten) {
        const ritRank = _RONDE_RANK[rit.ronde_type] ?? 0;
        if (ritRank === 0 || ritRank >= curRank) continue;
        if (!rit.rijders) continue;
        for (const r of rit.rijders) {
            if (r.person_license !== lic) continue;
            const rDc   = r._combi_dc_id       ?? rit.dc_id;
            const rDist = r._combi_distance_id ?? rit.distance_id;
            if (rDc !== cDc) continue;
            if (String(rDist ?? '') !== String(cDist ?? '')) continue;
            if (ritRank > bestRank) {
                bestRank = ritRank;
                bestPF   = r.is_photofinish ? 1 : 0;
            } else if (ritRank === bestRank && r.is_photofinish) {
                bestPF = 1;
            }
        }
    }
    return bestPF === 1;
}

// Photofinish-icon HTML. Klein, sanctie-stijl. Tooltip legt uit waarom hij
// er staat — operator hoeft niet te raden welke wedstrijd / wissel.
function _livePhotofinishIcon() {
    return ` <span class="live-pf-badge" title="Photofinish — tijd via jury-wissel aangepast in een eerdere of huidige rit">📷</span>`;
}

// ── Multi-sanctie chip-picker ──────────────────────────────────────────────
// Een rijder kan meerdere sancties in 1 heat krijgen (W1 + W2 + DQ-SF + FS).
// Hidden input `.live-sanctie-sel` bewaart de comma-separated string zodat
// alle bestaande code die sel.value leest blijft werken. Knop ernaast toont
// de actieve codes; click opent een popover met chips waar je toggle't.
//
// Codes in vaste UI-volgorde (= grouping voor jury):
//   waarschuwingen (FS/W1/W2/RR) eerst, dan DQ's, dan DNF/DNS.
const _LIVE_SANCT_CODES = ['FS', 'W1', 'W2', 'RR', 'DQ-TF', 'DQ-SF', 'DQ-DF', 'DNF', 'DNS'];

function _liveBouwSanctieMulti(huidig, disabled) {
    // huidig = string 'W1,W2,DQ-SF' (of leeg/null)
    const codes = (huidig || '').split(',').map(s => s.trim()).filter(Boolean);
    const label = codes.length ? escHtml(codes.join(', ')) : '—';
    return `<div class="live-sanctie-wrap">` +
        `<input type="hidden" class="live-sanctie-sel" value="${escHtml(codes.join(','))}">` +
        `<button type="button" class="live-sanctie-btn ${codes.length ? 'heeft-sanctie' : ''}"` +
        ` ${disabled} title="Klik om sancties te kiezen (meerdere mogelijk)">${label}</button>` +
        `</div>`;
}

// Update de getoonde tekst op een sanctie-knop op basis van de hidden input.
// Wordt aangeroepen na elke value-set (chip-toggle, externe assignment).
function _liveSanctieBtnSync(wrap) {
    const inp = wrap.querySelector('.live-sanctie-sel');
    const btn = wrap.querySelector('.live-sanctie-btn');
    if (!inp || !btn) return;
    const codes = (inp.value || '').split(',').map(s => s.trim()).filter(Boolean);
    btn.textContent = codes.length ? codes.join(', ') : '—';
    btn.classList.toggle('heeft-sanctie', codes.length > 0);
}

// Helper voor externe code die `.live-sanctie-sel` waarde wil zetten + UI
// updaten. Bestaande code die direct `inp.value = X` doet werkt nog steeds,
// maar update de knop-tekst niet — gebruik deze helper voor consistentie.
function _liveSanctieZet(wrapOrSel, nieuweWaarde) {
    const wrap = wrapOrSel.classList?.contains('live-sanctie-wrap')
        ? wrapOrSel
        : wrapOrSel.closest('.live-sanctie-wrap');
    if (!wrap) return;
    const inp = wrap.querySelector('.live-sanctie-sel');
    if (!inp) return;
    inp.value = nieuweWaarde || '';
    _liveSanctieBtnSync(wrap);
}

// Popover voor multi-sanctie-keuze. Eén globaal popover-element wordt
// hergebruikt; click op een chip toggle't en update de hidden input direct.
// Sluiten via Esc, click buiten, of de × in de header.
let _liveSanctiePopover = null;
function _liveSanctiePopoverOpen(wrap) {
    _liveSanctiePopoverSluit();
    const inp = wrap.querySelector('.live-sanctie-sel');
    if (!inp) return;
    const huidig = new Set((inp.value || '').split(',').map(s => s.trim()).filter(Boolean));

    const pop = document.createElement('div');
    pop.className = 'live-sanctie-popover';
    pop.innerHTML =
        `<div class="lsp-kop"><span>Sancties (klik om aan/uit te zetten)</span>` +
        `<button type="button" class="lsp-sluit" title="Sluiten">×</button></div>` +
        `<div class="lsp-chips">` +
        _LIVE_SANCT_CODES.map(c =>
            `<button type="button" class="lsp-chip ${huidig.has(c) ? 'actief' : ''}"` +
            ` data-code="${escHtml(c)}">${escHtml(c)}</button>`
        ).join('') +
        `</div>` +
        `<div class="lsp-voet">` +
        `<button type="button" class="lsp-wis">Alles wissen</button>` +
        `<button type="button" class="lsp-ok">Klaar</button>` +
        `</div>`;
    document.body.appendChild(pop);
    _liveSanctiePopover = { el: pop, wrap };

    // Positioneer onder de knop
    const btn = wrap.querySelector('.live-sanctie-btn');
    const r   = btn.getBoundingClientRect();
    pop.style.position = 'absolute';
    pop.style.top  = (window.scrollY + r.bottom + 4) + 'px';
    pop.style.left = (window.scrollX + r.left)      + 'px';
    pop.style.zIndex = '5000';

    // Toggle chip → herbouw value direct
    pop.querySelectorAll('.lsp-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const code = chip.dataset.code;
            if (huidig.has(code)) huidig.delete(code); else huidig.add(code);
            chip.classList.toggle('actief');
            // Behoud canonieke volgorde voor consistente weergave
            const geordend = _LIVE_SANCT_CODES.filter(c => huidig.has(c));
            inp.value = geordend.join(',');
            _liveSanctieBtnSync(wrap);
            // 'change'-event zodat bestaande change-listeners triggeren
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    pop.querySelector('.lsp-wis').addEventListener('click', () => {
        huidig.clear();
        pop.querySelectorAll('.lsp-chip').forEach(c => c.classList.remove('actief'));
        inp.value = '';
        _liveSanctieBtnSync(wrap);
        inp.dispatchEvent(new Event('change', { bubbles: true }));
    });
    pop.querySelector('.lsp-ok').addEventListener('click', _liveSanctiePopoverSluit);
    pop.querySelector('.lsp-sluit').addEventListener('click', _liveSanctiePopoverSluit);

    // Click buiten sluit
    setTimeout(() => {
        document.addEventListener('click', _liveSanctiePopoverBuitenklik, true);
        document.addEventListener('keydown', _liveSanctiePopoverEsc, true);
    }, 0);
}

function _liveSanctiePopoverSluit() {
    if (_liveSanctiePopover) {
        _liveSanctiePopover.el.remove();
        _liveSanctiePopover = null;
    }
    document.removeEventListener('click', _liveSanctiePopoverBuitenklik, true);
    document.removeEventListener('keydown', _liveSanctiePopoverEsc, true);
}
function _liveSanctiePopoverBuitenklik(e) {
    if (!_liveSanctiePopover) return;
    if (_liveSanctiePopover.el.contains(e.target)) return;
    if (_liveSanctiePopover.wrap.contains(e.target)) return;
    _liveSanctiePopoverSluit();
}
function _liveSanctiePopoverEsc(e) {
    if (e.key === 'Escape') _liveSanctiePopoverSluit();
}

// Globale delegated click-handler: open popover wanneer een sanctie-knop
// wordt geklikt. Dit gebeurt onafhankelijk van wanneer/welke heat-tabel
// gerendered is — werkt ook na re-render.
document.addEventListener('click', e => {
    const btn = e.target.closest('.live-sanctie-btn');
    if (!btn || btn.disabled) return;
    const wrap = btn.closest('.live-sanctie-wrap');
    if (!wrap) return;
    if (_liveSanctiePopover && _liveSanctiePopover.wrap === wrap) {
        _liveSanctiePopoverSluit();
    } else {
        _liveSanctiePopoverOpen(wrap);
    }
});

// Bepaalt of een rit "alles groen" is: elke rijder heeft tijd ÓF sanctie ÓF
// (afvalkoers + afgevallen). Pas dán is de jury aan zet en mag de fin-kolom
// dropdowns tonen — voor die tijd zijn de waarden nog onvolledig en kunnen
// posities verspringen door late tijden, dat zou verwarrend zijn.
//
// Gebruikt DOM-waarden indien beschikbaar (typing wordt direct meegerekend),
// valt anders terug op rit.rijders[].tijd_ms / .sanctie (initiële render).
function _liveAllesCompleet(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit?.rijders?.length) return false;
    const isAfvalkoers = rit.race_type === 'afvalkoers';
    const afvalIds = isAfvalkoers
        ? new Set((_afvalState[ritIdx]?.afgevallen || []).map(a => a.entry_id))
        : null;
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);
    return rit.rijders.every(r => {
        // DOM heeft voorrang — typing-wijzigingen zijn nog niet gesynct met r.*
        let tijdMs = null, sanctie = '';
        const rij = kaart?.querySelector(`[data-entry="${r.entry_id}"]`);
        if (rij) {
            const inp = rij.querySelector('.live-tijd-inp');
            const sel = rij.querySelector('.live-sanctie-sel');
            tijdMs = inp ? _parseTijdInvoer(inp.value) : null;
            sanctie = sel?.value || '';
        } else {
            tijdMs  = r.tijd_ms;
            sanctie = r.sanctie || '';
        }
        if (tijdMs > 0)              return true;
        if (sanctie)                 return true;
        if (afvalIds?.has(r.entry_id)) return true;
        return false;
    });
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
        if (s && s !== document.activeElement && s.value !== sanctieVal) {
            s.value = sanctieVal;
            _liveSanctieBtnSync(s.closest('.live-sanctie-wrap'));
        }
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

    // Bij combi: posities PER LEDEN berekenen (cat A en cat B krijgen elk hun
    // eigen 1-N nummering), bij niet-combi alles in één pass.
    const isAfvalkoers = rit.race_type === 'afvalkoers';
    const posMap = new Map();
    if (rit.is_combi) {
        for (const lid of rit.combi_leden) {
            const subset = entries.filter(e => {
                const rij = rit.rijders.find(rr => rr.entry_id === e.entry_id);
                return rij && rij._combi_rit_id === lid.rit_id;
            });
            _berekenPosities(subset, true, isAfvalkoers).forEach((v, k) => posMap.set(k, v));
        }
    } else {
        _berekenPosities(entries, true, isAfvalkoers).forEach((v, k) => posMap.set(k, v));
    }
    const isPuntenkoers = rit.race_type === 'puntenkoers';
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
        if (_liveSanctieHeeft(sanctieWaarde, 'FS'))   rij.classList.add(ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');
        else if (heeftSanctie)        rij.classList.add('live-rit-status-sanctie');
        else if (ms > 0)              rij.classList.add('live-rit-status-compleet');
        else if (isAfgevallen)        rij.classList.add('live-rit-status-compleet');
        else                          rij.classList.add('live-rit-status-leeg');
    });

    if (isPuntenkoers) _liveUpdatePuntenBadges(ritIdx);

    // Wissel-dropdowns activeren zodra de jury-fase begint: alle rijders zijn
    // verwerkt (tijd / sanctie / afvalling). Tot dat moment blijven badges
    // staan — operator zou anders al kunnen wisselen terwijl er nog rijders
    // ontbreken, wat verwarrend is bij late tijden of laat-toegekende sancties.
    // Voor PK skippen we (PK krijgt dropdowns na save zoals voorheen — punten-
    // gebaseerde finpos vereist een save-roundtrip om correct gesynct te zijn).
    if (!isPuntenkoers && _liveAllesCompleet(ritIdx)) {
        // Eerst r.finishpositie syncen voor rijders die nog geen positie hebben
        // (eerste-keer activatie). Reeds gewisselde rijders niet aanraken — hun
        // r.finishpositie reflecteert de operator-keuze.
        rit.rijders.forEach(r => {
            if (r.finishpositie == null) {
                const p = posMap.get(r.entry_id);
                if (p != null) r.finishpositie = p;
            }
        });
        // Nu kunnen we badges → dropdowns omzetten waar van toepassing.
        // _liveActiveerWisselDropdowns laat bestaande dropdowns staan en
        // werkt alleen badges bij — perfect voor deze idempotente flow.
        _liveActiveerWisselDropdowns(ritIdx);
    }
}

function _ordinaal(n) {
    return n + 'e';
}

// ── Ronde-status berekening (voor volgende-ronde knop) ────────────────────────

function _liveRondeCompleet(dcId, distanceId, rondeType) {
    // Combi-ritten matchen op ELK leden's dc/distance — voor categorie B in een
    // combi-rit moeten we ook checken of die leden compleet is, ook al is dat
    // niet de "primaire" dc op de gemergde rit.
    const ritMatcht = (r) => {
        if (r.ronde_type !== rondeType) return false;
        if (r.is_combi) {
            return r.combi_leden.some(l => l.dc_id === dcId && l.distance_id === distanceId);
        }
        return r.dc_id === dcId && r.distance_id === distanceId;
    };
    const rittenInRonde = _liveRitten.filter(ritMatcht);
    if (rittenInRonde.length === 0) return false;
    // Bij combi-rit: alleen de leden-rijders voor deze dc/distance moeten
    // compleet zijn (niet de andere combi-leden — die horen bij andere keten).
    const ritCompleetVoorDc = (r) => {
        if (!_liveHasHeat(r)) return false;
        if (r.is_combi) {
            const ledenRijders = r.rijders.filter(rij => rij._combi_dc_id === dcId);
            return ledenRijders.length > 0 && ledenRijders.every(_liveRijderAfgehandeld);
        }
        return _liveRitCompleet(r);
    };
    return rittenInRonde.every(ritCompleetVoorDc);
}


// Label voor de hergeneer-knop. Bij naar=finale_a in full-final genereren we
// in één klik óók de B-finales (zie api/live.php). Het label moet dat
// reflecteren: "A- en B-Finales" als er B-finale-ritten in het tijdschema
// staan voor deze dc+distance, anders gewoon "A-Finale".
function _liveHergeneerLabel(dcId, distanceId, naarRondeType) {
    const baseLabel = RONDE_LABEL[naarRondeType] || naarRondeType;
    if (naarRondeType !== 'finale_a') return baseLabel;
    // Match ook combi-ritten waar één van de leden voor deze dc+distance
    // een B-finale heeft — anders zou een combi-B-finale niet meegerekend worden.
    const heeftB = (_liveRitten || []).some(r => {
        if (r.ronde_type !== 'finale_b') return false;
        if (r.is_combi) {
            return r.combi_leden.some(l =>
                l.dc_id === dcId && String(l.distance_id || '') === String(distanceId || '')
            );
        }
        return r.dc_id === dcId
            && String(r.distance_id || '') === String(distanceId || '');
    });
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

    // Combi-badge: alleen tonen wanneer combi-ritten NIET gemerged zijn (legacy
    // pad — bij gemergde combi's geeft de gecombineerde rit_naam de leden al aan).
    let combiInfoHtml = '';
    if (rit.combi_group && !rit.is_combi) {
        const groepLeden = _liveRitten.filter(x => x.combi_group === rit.combi_group)
                                      .sort((a, b) => (a.volgorde || 0) - (b.volgorde || 0));
        const mijnPos = groepLeden.findIndex(x => x.rit_id === rit.rit_id) + 1;
        combiInfoHtml = `<span class="heat-combi-badge" title="Deze rit is gecombineerd met ${groepLeden.length - 1} andere rit(ten) in het programma">🔗 ${mijnPos}/${groepLeden.length}</span>`;
    } else if (rit.is_combi) {
        // Gemergde combi: subtiele badge dat het 1 race is met N categorieën
        combiInfoHtml = `<span class="heat-combi-badge" title="${rit.combi_leden.length} categorieën rijden tegelijk in deze heat">🔗 ${rit.combi_leden.length}×</span>`;
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
        // Ex-aequo rangmap voor initiële render. Voor combi-ritten: bereken
        // PER LEDEN apart zodat elke categorie zijn eigen 1-N nummering krijgt
        // (twee rijders kunnen dus beiden Fin "1" hebben — eentje per categorie).
        const _rangMap = new Map();
        if (rit.is_combi) {
            for (const lid of rit.combi_leden) {
                const subset = rit.rijders.filter(r => r._combi_rit_id === lid.rit_id);
                const ledenMap = _berekenPosities(
                    subset.map(r => ({ entry_id: r.entry_id, tijd_ms: r.tijd_ms, sanctie: r.sanctie, rondes: r.rondes })),
                    true
                );
                ledenMap.forEach((v, k) => _rangMap.set(k, v));
            }
        } else {
            _berekenPosities(
                rit.rijders.map(r => ({ entry_id: r.entry_id, tijd_ms: r.tijd_ms, sanctie: r.sanctie, rondes: r.rondes })),
                true
            ).forEach((v, k) => _rangMap.set(k, v));
        }

        // Alles-compleet detectie: dropdowns alleen tonen als alle rijders een
        // status hebben (tijd, sanctie of afvalling). Op dit moment heeft de
        // DOM nog geen kaart voor deze idx (we bouwen 'm net), dus de helper
        // valt automatisch terug op rit.rijders-state.
        const allesCompleet = _liveAllesCompleet(idx);
        const opts  = { heeftRondes, allesCompleet, currentRit: rit };
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
              `<button type="button" class="live-import-toon-geblok" id="live-import-toon-geblok-${idx}" title="Toon ook geblokkeerde mappen (standaard verborgen)">🔓</button>` +
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

    // Carousel-teller toont volgorde-getal van eerste leden i.p.v. array-index,
    // zodat combi-ritten "ergens" springen (bv. 3, 6, 7 als 3-4-5 een combi is)
    // en de noemer het totaal aantal ritten in DB blijft tonen.
    const totaal = _liveRittenOrigCount || _liveRitten.length;
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

    // Multi-day: dag-tabs boven nav-balk. Verschijnen alleen bij >1 dag.
    // Init _liveActieveDag indien nodig — bij silent ts-fetch re-render kunnen
    // we hier voor de eerste keer ontdekken dat het multi-day is.
    const dagInfo = _liveDagInfo();
    _liveInitActieveDagAlsNodig(dagInfo);
    const dagTabsHtml = dagInfo?.isMultiDag
        ? `<div class="ts-dag-tabs live-dag-tabs" role="tablist" aria-label="Wedstrijddag">` +
              dagInfo.dagLabels.map(d =>
                  `<button class="org-tab-btn ts-dag-tab live-dag-tab${d.nr === _liveActieveDag ? ' active' : ''}"`
                  + ` data-dag="${d.nr}" role="tab"`
                  + ` aria-selected="${d.nr === _liveActieveDag ? 'true' : 'false'}">${escHtml(d.label)}</button>`
              ).join('') +
          `</div>`
        : '';

    const navHtml =
        dagTabsHtml +
        `<div class="live-carousel-nav">` +
        `<div class="live-nav-filter" title="Filter op status">${pilHtml}</div>` +
        `<div class="live-nav-dd" id="live-nav-dd">` +
          `<button type="button" class="live-nav-dropdown" id="live-nav-dd-trigger">${huidigLabel}</button>` +
          `<div class="live-nav-dd-panel" id="live-nav-dd-panel" hidden>${dropdownOpts}</div>` +
        `</div>` +
        `<span class="live-nav-teller">${rit?.volgorde ?? (idx + 1)} / ${totaal}</span>` +
        `<button type="button" class="live-nav-refresh" id="live-btn-refresh"`
        + ` title="Ververs: haal nieuwste data op (bv. DNS-markeringen van AoC)">↻</button>` +
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

    // Pijl-navigatie: bij multi-day skipt binnen actieve dag, anders ±1
    const isMulti = !!dagInfo?.isMultiDag;
    el('live-btn-vorige')?.addEventListener('click',  () => {
        const tgt = isMulti
            ? _liveZoekIdxOpDag(_liveHuidigIdx, -1, _liveActieveDag)
            : _liveHuidigIdx - 1;
        if (tgt >= 0) _liveNavigeer(tgt);
    });
    el('live-btn-volgende')?.addEventListener('click', () => {
        const tgt = isMulti
            ? _liveZoekIdxOpDag(_liveHuidigIdx, +1, _liveActieveDag)
            : _liveHuidigIdx + 1;
        if (tgt >= 0) _liveNavigeer(tgt);
    });
    _liveBindDropdown();

    // Refresh-knop: handmatig de DB opnieuw inlezen (bv. om DNS van AoC te
    // zien). Visuele feedback via .live-refresh-drait class op de knop.
    el('live-btn-refresh')?.addEventListener('click', async () => {
        const btn = el('live-btn-refresh');
        if (!btn || btn.disabled) return;
        if (_liveOngeslagen) {
            const ok = await toonBevestigDialog(
                'Er zijn onopgeslagen tijden — refresh zou ze wissen.\n\nDoorgaan zonder op te slaan?',
                'Onopgeslagen tijden'
            );
            if (!ok) return;
            _liveOngeslagen = false;
        }
        btn.disabled = true;
        btn.classList.add('live-refresh-draait');
        try {
            await _liveHerlaadStil(true);  // forceerRender=true
        } finally {
            btn.disabled = false;
            btn.classList.remove('live-refresh-draait');
        }
    });

    // Dag-tab click: switch dag + spring naar eerste niet-voltooide rit
    // van die dag. Geen-actie als al actief.
    if (isMulti) {
        container.querySelectorAll('.live-dag-tab').forEach(btn => {
            btn.addEventListener('click', async () => {
                const nieuw = parseInt(btn.dataset.dag) || 1;
                if (nieuw === _liveActieveDag) return;
                if (_liveOngeslagen) {
                    const ok = await toonBevestigDialog(
                        'Er zijn onopgeslagen tijden.\nDoorgaan zonder op te slaan?',
                        'Onopgeslagen tijden');
                    if (!ok) return;
                }
                _liveOngeslagen = false;
                _liveActieveDag = nieuw;
                const tgt = _liveEersteIdxOpDag(nieuw);
                if (tgt >= 0) _liveHuidigIdx = tgt;
                _liveRenderCarousel();
            });
        });
    }

    _liveBind(idx);
    _livePanelBind();

    el('live-btn-volgende-ronde')?.addEventListener('click', e => {
        const b = e.currentTarget;
        _liveHergeneerKlik(b);
    });
}

// Hergeneer-klik: bij combi-rit eerst checken of ALLE leden compleet zijn,
// daarna ketenstap voor elke leden draaien (zodat beide categorieën hun
// volgende ronde krijgen). Bij niet-combi: gewoon één ketenstap.
function _liveHergeneerKlik(btn) {
    const dcId       = btn.dataset.dcId;
    const distanceId = btn.dataset.distanceId;
    const van        = btn.dataset.van;
    const naar       = btn.dataset.naar;

    const huidigeRit = _liveRitten[_liveHuidigIdx];
    if (huidigeRit?.is_combi) {
        // Per leden: zelfde "van"-ronde en bereken passende "naar" via _volgendeRondeType.
        // Geef ALTIJD lid.dc_naam mee als splitDcNaam zodat server qualifiers/cleanups
        // op de juiste split focust (bij niet-split DC = no-op want één unieke dc_naam).
        for (const lid of huidigeRit.combi_leden) {
            const ledenNaar = _volgendeRondeType(lid.dc_id, lid.distance_id, van);
            if (!ledenNaar) continue;
            _liveGenereerKetenStap(lid.dc_id, lid.distance_id, van, ledenNaar, true,
                { splitDcNaam: lid.dc_naam || '' })
                .catch(() => {}); // fout op één leden mag niet de andere blokkeren
        }
        return;
    }
    // Niet-combi: één call met dc_naam van de huidige rit. Dat is de juiste
    // split (bij split-DC) of gewoon de DC-naam (bij niet-split — filter is no-op).
    _liveGenereerKetenStap(dcId, distanceId, van, naar, true,
        { splitDcNaam: huidigeRit?.dc_naam || '' });
}

// ── Links panel: alle rijders in categorie+ronde ──────────────────────────────

// Panel-specifieke listeners — het panel is nu volledig read-only, dus er
// hoeven geen listeners meer te worden gekoppeld. De functie blijft bestaan
// als no-op zodat aanroepen elders geen crash geven.
function _liveInitPanelListeners() {
    // geen listeners nodig voor een read-only panel
}

// Verzamelt + sorteert + Q/q-markeert alle rijders voor één categorie+afstand
// in een bepaalde ronde. Geeft een vlakke lijst met `_kwal` per rijder terug.
// Gebruikt door _liveBouwLinksPanel — bij combi-rit roepen we deze helper
// per leden afzonderlijk aan zodat Q/q en sortering binnen de eigen cat
// blijven (niet kruisen tussen Mannen/Vrouwen-leden).
function _liveVerzamelPanelRijders(dcId, distanceId, rondeType) {
    // Filter ritten die voor dit dc/distance/ronde rijden — combi's matchen
    // ook als ÉÉN van hun leden hierop staat.
    const ritten = _liveRitten.filter(r => {
        if (r.ronde_type !== rondeType) return false;
        if (r.is_combi) {
            return r.combi_leden.some(l =>
                l.dc_id === dcId
                && String(l.distance_id ?? '') === String(distanceId ?? '')
            );
        }
        return r.dc_id === dcId
            && String(r.distance_id ?? '') === String(distanceId ?? '');
    });

    // Plat: bij combi-rit alleen de rijders van het matchende leden meenemen.
    const alleRijders = [];
    for (const rit of ritten) {
        if (!_liveHasHeat(rit)) continue;
        const ledenRijders = rit.is_combi
            ? rit.rijders.filter(rij => rij._combi_dc_id === dcId)
            : rit.rijders;
        for (const r of ledenRijders) {
            alleRijders.push({ ...r, rit_id: r._combi_rit_id ?? rit.rit_id, heat_nr: rit.heat_nr });
        }
    }

    const heeftResultaten = alleRijders.some(r => r.finishpositie != null);
    if (heeftResultaten) {
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

        const perHeat = {};
        for (const r of alleRijders) {
            const hk = r.heat_nr ?? r.rit_id;
            if (!perHeat[hk]) perHeat[hk] = [];
            perHeat[hk].push(r);
        }
        for (const hk of Object.keys(perHeat)) {
            perHeat[hk].sort((a, b) => (a.finishpositie ?? 999) - (b.finishpositie ?? 999));
        }

        const qRijders = new Set();
        if (qPerHeat > 0) {
            for (const hk of Object.keys(perHeat)) {
                const heatRijders = perHeat[hk];
                for (let i = 0; i < Math.min(qPerHeat, heatRijders.length); i++) {
                    // Alleen "echte uitvallers" (DNS/DNF/DQ-*) niet meetellen
                    // voor Q-kwalificatie. FS/RR/W1/W2 zijn waarschuwingen —
                    // de rijder heeft normaal gefinisht en moet zijn Q-positie
                    // gewoon krijgen, anders verschuift hij ten onrechte
                    // omlaag in de panel-weergave.
                    const r = heatRijders[i];
                    if (r.finishpositie != null && !_liveSanctieHeeftSet(r.sanctie, _SANCTIE_GEEN_FINISH))
                        qRijders.add(r.entry_id);
                }
            }
        }

        const metTijd = alleRijders
            // Zelfde regel: FS/RR/W1/W2-rijder met tijd hoort gewoon mee te
            // tellen voor de q-pool (tijdkwalificatie naar volgende ronde).
            .filter(r => r.tijd_ms != null
                       && !qRijders.has(r.entry_id)
                       && !_liveSanctieHeeftSet(r.sanctie, _SANCTIE_GEEN_FINISH))
            .sort((a, b) => a.tijd_ms - b.tijd_ms);
        const aantalQ = qRijders.size;
        const aantalq = Math.max(0, totaalDoor - aantalQ);
        const qTijdRijders = new Set();
        for (let i = 0; i < Math.min(aantalq, metTijd.length); i++) {
            qTijdRijders.add(metTijd[i].entry_id);
        }
        if (aantalq > 0 && metTijd[aantalq - 1] && metTijd[aantalq]) {
            const grensTijd = metTijd[aantalq - 1].tijd_ms;
            for (let i = aantalq; i < metTijd.length; i++) {
                if (metTijd[i].tijd_ms === grensTijd) qTijdRijders.add(metTijd[i].entry_id);
                else break;
            }
        }

        for (const r of alleRijders) {
            if (qRijders.has(r.entry_id))      r._kwal = 'Q';
            else if (qTijdRijders.has(r.entry_id)) r._kwal = 'q';
            else                                    r._kwal = '';
        }

        alleRijders.sort((a, b) => {
            const ordA = a._kwal === 'Q' ? 0 : a._kwal === 'q' ? 1 : 2;
            const ordB = b._kwal === 'Q' ? 0 : b._kwal === 'q' ? 1 : 2;
            if (ordA !== ordB) return ordA - ordB;
            if (ordA === 0) return (a.finishpositie ?? 999) - (b.finishpositie ?? 999);
            const tA = a.tijd_ms ?? 999999, tB = b.tijd_ms ?? 999999;
            if (tA !== tB) return tA - tB;
            return (a.startnummer ?? 99999) - (b.startnummer ?? 99999);
        });
    } else {
        alleRijders.sort((a, b) => (a.startnummer ?? 99999) - (b.startnummer ?? 99999));
    }

    return alleRijders;
}

// Bouwt tbody-rijen voor één sectie van het paneel.
// `panelRit` = pseudo-rit met dc_id/distance_id/ronde_type van de sectie,
// nodig voor de PF-icoon-context (alleen tonen bij wissel in vorige ronde).
function _liveBouwPanelTbodyRijen(rijders, heeftRondes, panelRit = null) {
    let html = '';
    for (const r of rijders) {
        const tijdVal   = r.tijd_ms !== null ? _msTijdNaarDisplay(r.tijd_ms) : '—';
        const sanctieUi = r.sanctie || '';
        // Status — zelfde logica als heat-card: FS+tijd telt als compleet
        // (groen), niet als sanctie (rood). FS is een waarschuwing, geen
        // uitval. Andere sancties (DNS/DNF/DQ-*) blijven rood.
        const statusKls = _liveSanctieHeeft(r.sanctie, 'FS')
                        ? (r.tijd_ms !== null ? 'live-rit-status-compleet' : 'live-rit-status-leeg')
                        : r.sanctie ? 'live-rit-status-sanctie'
                        : r.tijd_ms !== null ? 'live-rit-status-compleet'
                        : 'live-rit-status-leeg';
        const rondesTd  = heeftRondes
            ? `<td class="live-col-rondes"><span class="live-panel-rondes-txt">${r.rondes ?? '—'}</span></td>`
            : '';
        const kwalBadge = r._kwal === 'Q' ? '<span style="color:#198754;font-weight:700">Q</span>'
                       : r._kwal === 'q' ? '<span style="color:#0d6efd;font-weight:600">q</span>'
                       : '';
        const pfIcon = _liveHeeftPhotofinishVorigeRonde(r, panelRit) ? _livePhotofinishIcon() : '';
        html +=
            `<tr class="live-panel-rij ${statusKls}" data-panel-entry="${r.entry_id}" data-rit-id="${r.rit_id}" data-rondes="${r.rondes ?? ''}">` +
            `<td>${r.startnummer ?? ''}</td>` +
            `<td>${escHtml(r.full_name || '')}${pfIcon}</td>` +
            `<td style="text-align:center;width:24px">${kwalBadge}</td>` +
            rondesTd +
            `<td class="live-col-tijd"><span class="live-panel-tijd-txt">${escHtml(tijdVal)}</span></td>` +
            `<td class="live-col-sanctie"><span class="live-panel-sanctie-txt">${escHtml(sanctieUi || '—')}</span></td>` +
            `<td class="live-col-finish"><span class="live-finish-badge">${r.finishpositie?_ordinaal(r.finishpositie):'—'}</span></td>` +
            `</tr>`;
    }
    return html;
}

function _liveBouwLinksPanel(dcId, distanceId, rondeType) {
    const rondeNaam = RONDE_LABEL[rondeType] || rondeType;

    // Bij combi-rit (huidige rit): toon ALLE leden van de combi met
    // duidelijke scheidingsregels, elk met eigen Q/q en eigen Fin-nummering.
    const huidigeRit = _liveRitten[_liveHuidigIdx];
    const isCombiContext = huidigeRit?.is_combi
        && huidigeRit.combi_leden.some(l =>
            l.dc_id === dcId && String(l.distance_id ?? '') === String(distanceId ?? '')
        );

    // Verzamel rijders per "sectie": bij combi één per leden, anders één.
    const secties = isCombiContext
        ? huidigeRit.combi_leden.map(lid => ({
            dc_id:        lid.dc_id,
            distance_id:  lid.distance_id,
            label:        lid.dc_naam,
            rijders:      _liveVerzamelPanelRijders(lid.dc_id, lid.distance_id, rondeType),
        }))
        : [{
            dc_id:        dcId,
            distance_id:  distanceId,
            label:        null,
            rijders:      _liveVerzamelPanelRijders(dcId, distanceId, rondeType),
        }];

    // Globale "heeftRondes": als minstens één sectie ronde-data heeft, dan
    // tonen we de Rnd-kolom voor alle secties (zo blijft de tabel uitgelijnd).
    const heeftRondes = secties.some(s =>
        s.rijders.some(r => r.rondes != null && r.rondes !== '' && r.rondes !== 0)
    );

    const colspan = heeftRondes ? 7 : 6;

    let tbody = '';
    let totaalRijders = 0;
    secties.forEach((s, i) => {
        // Sectie-header tussen leden: alleen tonen als er meerdere secties zijn
        // OF als er een label is (combi-modus). Eerste sectie: extra top-margin
        // vermijden via aparte CSS-klasse.
        if (s.label) {
            tbody +=
                `<tr class="live-panel-leden-kop${i === 0 ? ' live-panel-leden-kop-eerste' : ''}">` +
                `<td colspan="${colspan}">🔗 ${escHtml(s.label)}</td>` +
                `</tr>`;
        }
        if (s.rijders.length === 0) {
            tbody += `<tr class="live-panel-rij-leeg"><td colspan="${colspan}">Geen startlijst beschikbaar</td></tr>`;
        } else {
            // Pseudo-rit voor PF-context: dc/distance van deze sectie + de
            // ronde die het panel toont. _liveHeeftPhotofinishVorigeRonde
            // gebruikt dit om alleen wissels uit DIRECT vorige ronde te tonen.
            const panelRit = { dc_id: s.dc_id, distance_id: s.distance_id, ronde_type: rondeType };
            tbody += _liveBouwPanelTbodyRijen(s.rijders, heeftRondes, panelRit);
            totaalRijders += s.rijders.length;
        }
    });
    if (totaalRijders === 0 && !secties.some(s => s.label)) {
        tbody = `<tr class="live-panel-rij-leeg"><td colspan="${colspan}">Geen startlijst beschikbaar</td></tr>`;
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
    // Match ook combi-ritten: zoek leden met deze dc/distance in elke combi-rit.
    const pkRit = _liveRitten.find(r => {
        if (r.race_type !== 'puntenkoers' || r.ronde_type !== rondeType) return false;
        if (r.is_combi) {
            return r.combi_leden.some(l =>
                String(l.dc_id) === dcId
                && String(l.distance_id ?? '') === distanceId
            );
        }
        return String(r.dc_id) === dcId
            && String(r.distance_id ?? '') === distanceId;
    });
    if (pkRit) {
        const pkIdx = _liveRitten.indexOf(pkRit);
        // Rijkleuren bijwerken op basis van tijd
        panel.querySelectorAll('.live-panel-rij[data-panel-entry]').forEach(rij => {
            const inp  = rij.querySelector('.live-tijd-inp');
            const sel  = rij.querySelector('.live-sanctie-sel');
            const ms   = inp ? _parseTijdInvoer(inp.value) : null;
            const sv   = sel?.value || '';
            rij.classList.remove('live-rit-status-compleet', 'live-rit-status-sanctie', 'live-rit-status-leeg');
            if (_liveSanctieHeeft(sv, 'FS'))  rij.classList.add(ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');
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
    const idx     = _liveHuidigIdx;
    const dagInfo = _liveDagInfo();
    const isMulti = !!dagInfo?.isMultiDag;
    const stukken = _liveRitten.map((r, i) => {
        // Multi-day filter: alleen ritten van actieve dag in dropdown.
        // Huidige rit altijd tonen (zodat label van trigger matched).
        if (isMulti && _liveDagVanRit(r) !== _liveActieveDag && i !== idx) return null;
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
        const rondesInp  = rij?.querySelector('.live-rondes-inp');

        tijdInp?.addEventListener('blur', () => {
            const rawInput = tijdInp.value.trim();
            const ms       = _parseTijdInvoer(tijdInp.value);
            const tijdVal  = ms !== null ? _msTijdNaarDisplay(ms) : '';
            tijdInp.value  = tijdVal;
            // Niet-lege ongeldige invoer → toast met uitleg zodat operator
            // weet waarom z'n invoer verdween. Lege input = bewust leeg, geen
            // toast nodig.
            if (ms === null && rawInput) {
                _liveToast(`⚠ Tijd "${rawInput}" niet herkend. Gebruik bv. 47.0, 47000 of 0:47.0`, 'warn', 4000);
            }
            // Alleen sancties die fundamenteel geen tijd hebben (DNS/DNF/DQ-*)
            // worden gewist bij tijdinvoer. FS, RR, W1 en W2 blijven staan.
            // Multi-sanctie: filter alleen de "wist-tijd"-codes weg, behoud de
            // rest (een rijder met W1+W2+DQ-SF die toch een tijd krijgt verliest
            // alleen DQ-SF, niet de W1/W2 — die staan los).
            if (ms !== null && sanctieSel?.value) {
                const codes  = sanctieSel.value.split(',').map(s => s.trim()).filter(Boolean);
                const overig = codes.filter(c => !_SANCTIE_WIST_TIJD.has(c));
                if (overig.length !== codes.length) {
                    sanctieSel.value = overig.join(',');
                    _liveSanctieBtnSync(sanctieSel.closest('.live-sanctie-wrap'));
                }
            }
            const sanctie = sanctieSel?.value || '';
            _liveSyncInvoer(r.entry_id, tijdVal, sanctie);
            _liveOngeslagen = true;
            // Handmatige tijd-correctie heft de eerdere wissel-lock op —
            // operator overschrijft expliciet, dus dezelfde "undo" als CSV-
            // herimport. Lock-badge wordt na _liveHerbereken weer een dropdown.
            if (r._wisselt) delete r._wisselt;
            _liveHerbereken(idx);
        });
        tijdInp?.addEventListener('input', () => { _liveOngeslagen = true; });

        // Rondes-input: bij handmatige correctie ook _liveOngeslagen + (indien
        // aanwezig) de wissel-lock opheffen, analoog aan tijd-input.
        rondesInp?.addEventListener('input', () => {
            _liveOngeslagen = true;
            if (r._wisselt) {
                delete r._wisselt;
                _liveHerbereken(idx);
            }
        });

        // Tab + Enter springen direct naar het volgende tijd-veld in de
        // heat-card (slaan sanctie/Fin-cellen over). Shift+Tab gaat terug.
        // Bij focus-change op de browser-natuurlijke manier triggert blur op
        // het oude veld → bestaande blur-handler doet de parse + format.
        tijdInp?.addEventListener('keydown', (e) => {
            if (e.key !== 'Tab' && e.key !== 'Enter') return;
            e.preventDefault();
            const allInps = Array.from(kaart.querySelectorAll('.live-tijd-inp:not([disabled])'));
            const i = allInps.indexOf(tijdInp);
            if (i < 0) return;
            const dir  = e.shiftKey ? -1 : 1;
            const next = allInps[i + dir];
            if (next) {
                next.focus();
                next.select(); // selecteer alles zodat operator direct kan typen
            } else {
                // Aan het eind: forceer blur zodat de parse op het laatste veld draait
                tijdInp.blur();
            }
        });

        sanctieSel?.addEventListener('change', () => {
            const sanctie = sanctieSel.value;
            // Alleen DNS/DNF/DQ-* wissen de tijd; FS, RR, W1 en W2 houden de
            // tijd (de jury past alleen handmatig de positie aan).
            if (sanctie && _liveSanctieHeeftSet(sanctie, _SANCTIE_WIST_TIJD) && tijdInp?.value.trim()) {
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

        // Swap-lock: elke rijder mag in deze sessie maar in 1 wissel betrokken
        // zijn. Cascading swaps (A↔B, dan B↔C) maken de tijd-state onleesbaar
        // (B's "tijd" is dan A's faked tijd, etc.). Operator moet bij twijfel
        // de CSV opnieuw importeren — dat reset alle wissels.
        if (riderA._wisselt || riderB._wisselt) {
            finSel.value = oudePosA;
            _liveToast('⚠ Deze rijder is al gewisseld. Importeer de CSV opnieuw om wissels te resetten.', 'info');
            return;
        }

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
            if (res.status === 404) {
                // Pre-save wissel: er staan nog geen results-rijen in DB voor
                // (één van) beide rijders. Lokaal is de swap al doorgevoerd
                // (finishpositie/tijd_ms hierboven), dus we markeren de rit
                // als ongesaved zodat de operator op Opslaan klikt — dan
                // schrijft save_rit_results de gewisselde state in één keer
                // naar DB, inclusief is_photofinish=1 (via r._wisselt).
                _liveOngeslagen = true;
            } else if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            } else {
                const data = await res.json();
                if (data.error) throw new Error(data.error);
            }

            // Markeer beide rijders als "in een wissel betrokken" — voorkomt
            // cascading swaps waarbij de tijd-state verworden tot een soep.
            // Reset gebeurt automatisch bij volgende CSV-import (via
            // _liveResetFinishCellen → cel-rebuild met nieuwe data).
            riderA._wisselt = true;
            riderB._wisselt = true;

            // Beide cellen converteren van dropdown naar badge zodat operator
            // visueel ziet dat ze "vergrendeld" zijn. Reëel: hun finpos staat
            // vast tot een re-import.
            _liveLockGewisseldeCel(idx, riderA);
            _liveLockGewisseldeCel(idx, riderB);

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

        // Opslaan in DB. Bij combi-rit: fan-out naar alle leden-heat_ids zodat
        // alle deelnemende heats hetzelfde race-type krijgen (samen rijden = samen scoren).
        const heatIds = rit.is_combi
            ? rit.combi_leden.map(l => l.heat_id).filter(Boolean)
            : [rit.heat_id];
        try {
            const calls = heatIds.map(hid => fetch('api/live.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'set_race_type', heat_id: hid, race_type: nieuweType }),
            }).then(async r => {
                const d = await r.json();
                if (d.error) throw new Error(d.error);
                return d;
            }));
            await Promise.all(calls);
            rit.race_type = nieuweType;
            // Bij combi: ook leden-objecten bijwerken zodat we consistent blijven
            if (rit.is_combi) rit.combi_leden.forEach(l => l.race_type = nieuweType);
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
    el('live-import-toon-geblok-' + idx)?.addEventListener('click', () => _liveImportToggleGeblokkeerd(idx));
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
    // sanctie=null als Set, en alleen DQ-TF als buiten_schema.
    // DNS-rijders worden expliciet GEFILTERD: ze horen niet in de afgevallen-
    // stack thuis (= geen positie). Eventueel verouderde DB-rijen met
    // afval_rang+DNS worden zo bij volgende save automatisch opgeschoond.
    const afgevallen = (rit.rijders || [])
        .filter(r => r.afval_rang != null && r.sanctie !== 'DNS')
        .map(r => ({
            entry_id: r.entry_id,
            plek:     r.afval_rang,
            sanctie:  r.sanctie || null,
            buiten_schema: _liveSanctieHeeft(r.sanctie, 'DQ-TF'),
            batch:    null,
        }))
        .sort((a, b) => b.plek - a.plek);
    // Ex-aequo herstel uit DB: items met identieke plek-waarde krijgen
    // gegenereerde batch-id zodat _afvalHercomputeSetRondes ze als groep
    // ziet en bij plek-herberekening hun gedeelde plek behoudt. Solo items
    // (unieke plek) blijven batch=null.
    const plekToBatch = new Map();
    afgevallen.forEach(a => {
        const groep = afgevallen.filter(x => x.plek === a.plek);
        if (groep.length > 1) {
            if (!plekToBatch.has(a.plek)) {
                plekToBatch.set(a.plek, _afvalNieuweBatchId());
            }
            a.batch = plekToBatch.get(a.plek);
        }
    });

    // DNS-set: rijders die niet zijn gestart. Tellen niet als 'in koers' en
    // krijgen geen positie. Bij DB-init uit r.sanctie; live updates via
    // _afvalSyncSanctie zodat _afvalNogInKoersIds direct correct blijft —
    // anders zou de rondes-teller pas na save+refresh kloppen.
    const dns = (rit.rijders || [])
        .filter(r => _liveSanctieHeeft(r.sanctie, 'DNS'))
        .map(r => r.entry_id);

    _afvalState[ritIdx] = {
        afgevallen,
        voorlopig_2de:  [],
        voorlopig_1ste: [],
        geselecteerd:   [],
        dns,
    };
    return _afvalState[ritIdx];
}

// Geeft entry_ids terug van rijders die NOG IN KOERS zijn:
// niet in afgevallen-stack en niet in voorlopig_2de/voorlopig_1ste.
// DNS-rijders (st.dns) zijn niet gestart en tellen ook niet als 'in
// koers' — zo klopt de Volgende-plek-berekening en de "Nog in koers: X"-
// teller direct na een DNS-keuze, zonder save+refresh te hoeven wachten.
function _afvalNogInKoersIds(ritIdx) {
    const rit = _liveRitten[ritIdx];
    const st  = _afvalState[ritIdx];
    if (!rit || !st) return [];
    const uit = new Set([
        ...st.afgevallen.map(a => a.entry_id),
        ...st.voorlopig_2de,
        ...st.voorlopig_1ste,
        ...(st.dns || []),
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
    const ronde = [];
    // 2de-groep: K rijders, gedeelde plek (ex-aequo). batch=null voor solo.
    const k2 = st.voorlopig_2de.length;
    if (k2 > 0) {
        const plek2 = nogVrij - k2 + 1;
        const batch2 = k2 > 1 ? _afvalNieuweBatchId() : null;
        st.voorlopig_2de.forEach(eid => {
            ronde.push({ entry_id: eid, plek: plek2, sanctie: null, batch: batch2 });
        });
    }
    // 1ste-groep: K1 rijders, gedeelde plek (eigen batch, aparte van 2de-groep)
    const k1 = st.voorlopig_1ste.length;
    if (k1 > 0) {
        const plek1 = (nogVrij - k2) - k1 + 1;
        const batch1 = k1 > 1 ? _afvalNieuweBatchId() : null;
        st.voorlopig_1ste.forEach(eid => {
            ronde.push({ entry_id: eid, plek: plek1, sanctie: null, batch: batch1 });
        });
    }
    // Voeg toe aan afgevallen-stack
    ronde.forEach(item => st.afgevallen.unshift(item));
    st.voorlopig_2de  = [];
    st.voorlopig_1ste = [];
    st.geselecteerd   = [];

    // Auto-rondes toekennen op basis van heat-config (indien ingevuld).
    _afvalAssignRondes(ritIdx, ronde);
}

// Unieke batch-id voor ex-aequo groep (multiple rijders tegelijk uit).
// Items in zelfde batch delen dezelfde 'plek' (= ex-aequo classering).
// Solo afvallers (k=1) krijgen batch=null — geen groepering nodig.
function _afvalNieuweBatchId() {
    return 'b' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
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
    const batch = k > 1 ? _afvalNieuweBatchId() : null;
    const ronde = st.geselecteerd.map(eid => ({
        entry_id: eid, plek, sanctie: 'DQ-TF', buiten_schema: true, batch,
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
    const batch = k > 1 ? _afvalNieuweBatchId() : null;
    const ronde = st.geselecteerd.map(eid => ({
        entry_id: eid, plek, sanctie: null, buiten_schema: true, batch,
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

    // Schema-grootte = VAST (starters - eindsprint). Sinds 2026-05-27:
    // ByFault/ByDecision tellen niet meer af van setCount — ze
    // consumeren een schema-positie op de plek waar ze in de stack
    // zitten. Op dat moment in de race is dat schema-bord 'gepasseerd'.
    const totaal = (rit.rijders || []).length;
    const setCount = Math.max(0, totaal - parseInt(cfg.eindsprint));
    if (setCount === 0) return;

    // ALLE items in volgorde van toevoeging (oudste eerst) krijgen een
    // schema-positie. Stack heeft newest at index 0, dus we lopen van
    // eind naar begin. Items consumeren een schema-positie (= dat moment
    // in de race is gepasseerd), maar krijgen verschillende borden:
    //  - Regulier + ByFault: schema-bord uit hun stack-positie (auto)
    //  - ByDecision: BEHOUDT z'n handmatig opgegeven bord (uit prompt).
    //    Decision-rijder is op een willekeurig moment uit de koers gehaald
    //    (val, opgegeven), niet op een schema-afvalmoment. Z'n 'rondes
    //    gereden' is een eigen getal dat niet uit schema is af te leiden.
    // Detectie ByDecision = a.buiten_schema === true && a.sanctie !== 'DQ-TF'.
    // (Edge case: na DB-refresh wordt buiten_schema niet hersteld voor
    // decisions → die worden als regulier behandeld; acceptable voor MVP).
    //
    // PLEK-herberekening op basis van RONDES GEREDEN. Sinds 2026-05-27:
    // niet meer op stack-positie (= toevoegings-volgorde in admin), want
    // operator kan post-hoc een decision toevoegen voor een eerdere ronde
    // — dan klopt stack-volgorde niet meer met chronologie. Op rondes-
    // sorteren is robuuster: wie langer reed = LATER eruit = BETERE plek
    // (lager cijfer). Items met identieke rondes = ex-aequo, krijgen
    // samen LAAGSTE plek van de groep (best-conventie).
    const totaalStarters = (rit.rijders || []).length;
    const allItems = [];
    for (let i = st.afgevallen.length - 1; i >= 0; i--) {
        allItems.push(st.afgevallen[i]);
    }
    // Pass 1: rondes-toekenning. Schema-bord voor reg + ByFault op basis
    // van stack-positie. ByDecision behoudt handmatig bord uit prompt.
    allItems.forEach((a, idx) => {
        const isDecision = a.buiten_schema === true && a.sanctie !== 'DQ-TF';
        if (isDecision) return;
        const ronde = _afvalRondeVoorPositie(idx + 1, cfg, setCount);
        if (ronde != null) _afvalSchrijfRondes(rit, a.entry_id, ronde);
    });
    // Pass 2: plek-toekenning op basis van rondes gereden.
    // Sorteer items op rondes ASC (laagste = eerst eruit = slechtste plek).
    // Items met null rondes (geen bord toegekend) naar einde, zodat ze
    // beste plekken krijgen — operator merkt het en vult bord in.
    // Ex-aequo: items met identieke rondes krijgen samen de LAAGSTE plek
    // van die groep (best-conventie).
    const itemsMetRondes = allItems.map(a => {
        const r = rit.rijders.find(x => x.entry_id === a.entry_id);
        return { a, rondes: (r && r.rondes != null) ? r.rondes : null };
    });
    itemsMetRondes.sort((x, y) => {
        // null rondes naar einde (= beste plek)
        if (x.rondes == null && y.rondes == null) return 0;
        if (x.rondes == null) return 1;
        if (y.rondes == null) return -1;
        return x.rondes - y.rondes;  // laag → vooraan = slechtste plek
    });
    // Ex-aequo groeperen op rondes-waarde. Conventie: ex-aequo groep krijgt
    // samen de HOOGSTE positie (= LAAGSTE plek-cijfer = best van de groep).
    // Voor 40,29 sorted op idx 0,1 met natural plekken 16,15: ex-aequo
    // wijst BEIDEN plek 15 toe (laagste cijfer = best van die 2). Volgende
    // groep (76 op idx 2) krijgt dan plek 14 = totaal - 2 (volgende idx).
    // Algoritme: vind eind van groep (laatste idx met zelfde rondes), plek
    // voor hele groep = totaalStarters - eind_idx.
    let i = 0;
    while (i < itemsMetRondes.length) {
        let j = i;
        while (j + 1 < itemsMetRondes.length
               && itemsMetRondes[j + 1].rondes === itemsMetRondes[i].rondes) {
            j++;
        }
        const groepPlek = totaalStarters - j;
        for (let k = i; k <= j; k++) {
            const item = itemsMetRondes[k];
            item.a.plek = groepPlek;
            const r = rit.rijders.find(x => x.entry_id === item.a.entry_id);
            if (r) r.afval_rang = groepPlek;
        }
        i = j + 1;
    }
}

// Wordt aangeroepen na _afvalSet — alleen het schema hercomputen.
function _afvalAssignRondes(ritIdx, _items) {
    _afvalHercomputeSetRondes(ritIdx);
}

// Bord-prompt voor ByDecision: jury moet handmatig opgeven hoeveel rondes
// de rijder al had gereden bij de decision (val/dubbel/opgeven). Die info
// is essentieel voor de uitslag-DB — anders weet niemand hoeveel rondes
// de rijder daadwerkelijk reed.
// ByFault: GEEN prompt. Die viel op een reguliere afvalmoment uit (alleen
// werd 'ie eruit gehaald door de jury wegens overtreding), dus z'n bord
// is gewoon het schema-bord van z'n stack-positie. Hercompute regelt dat.
async function _afvalAssignRondesBuitenSchema(ritIdx, items, oorzaak) {
    const rit = _liveRitten[ritIdx];
    const st  = _afvalState[ritIdx];
    if (!rit || !st || !items.length) return;
    const cfg = _afvalCfgGet(rit.heat_id) || {};
    const tr  = parseInt(cfg.totaal_ronden) || 0;

    if (oorzaak === 'decision' && tr) {
        // Default-bord: positie in schema waar deze decision zit (= waar
        // 'ie het schema verbruikt). Operator kan handmatig wijzigen.
        const setCount = _afvalSetTeElimineren(ritIdx);
        // Items zijn net unshifted; hun positie = stack-index + 1 (oudste = end)
        const oudsteItem = items[0];   // alle items in deze batch zelfde positie
        const positie = st.afgevallen.findIndex(a => a === oudsteItem) >= 0
            ? (st.afgevallen.length - st.afgevallen.indexOf(oudsteItem))
            : st.afgevallen.length;
        const schemaRonde = _afvalRondeVoorPositie(positie, cfg, setCount);
        const defaultBord = schemaRonde != null ? (tr - schemaRonde) : '';
        const bord = await _afvalBordPrompt(
            'By Decision — val / dubbel / opgeven', tr, defaultBord);
        if (bord != null) {
            const ronde = tr - bord;
            items.forEach(item => _afvalSchrijfRondes(rit, item.entry_id, ronde));
        }
    }
    // ByFault valt door naar hercompute → krijgt schema-bord automatisch.
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

// Sync een sanctie-wijziging in de heat-tabel met de afval-state.
// DNS = niet gestart → geen positie in afvalkoers. Rijder gaat in st.dns
// (telt niet als 'in koers') en wordt uit alle andere afval-stacks
// verwijderd. De DNS-sanctie zelf blijft zichtbaar in de heat-tabel; bij
// opslag krijgt 'ie finpos=NULL via de back-end (zie api/live.php
// _berekenPosities).
// Andere wist-tijd-sancties (DNF, DQ-*) worden NIET automatisch verwerkt —
// die horen via de By Fault-knop of via expliciete actie te lopen.
function _afvalSyncSanctie(ritIdx, entryId, nieuweSanctie) {
    const st = _afvalState[ritIdx];
    if (!st) return;
    if (!st.dns) st.dns = [];

    if (nieuweSanctie === 'DNS') {
        // Rijder uit alle ranking-stacks halen + in DNS-set zetten
        st.afgevallen     = st.afgevallen.filter(a => a.entry_id !== entryId);
        st.geselecteerd   = st.geselecteerd.filter(id => id !== entryId);
        st.voorlopig_2de  = st.voorlopig_2de.filter(id => id !== entryId);
        st.voorlopig_1ste = st.voorlopig_1ste.filter(id => id !== entryId);
        if (!st.dns.includes(entryId)) st.dns.push(entryId);
        return;
    }

    // Sanctie weggehaald of gewijzigd weg van DNS: rijder uit DNS-set halen
    // zodat 'ie weer als 'in koers' meetelt. Niet automatisch terug naar
    // geselecteerd/afgevallen plaatsen — operator kiest dat zelf.
    st.dns = st.dns.filter(id => id !== entryId);
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
    // Eerste-fase: stop zodra eersteTeElim afvallers gepland zijn —
    // anders krijgen we een extra bord erbij wanneer eersteTeElim=0
    // (= alle afvallers passen in de vaste fase 3,2,1). Bug: bij
    // bv. teElim=3 + vast=3 zou bord 5 anders ten onrechte alsnog
    // worden toegevoegd, met een schema van [bord 5, 3, 2] ipv [3, 2, 1].
    for (const b of eersteBorden) {
        if (arr.length >= eersteTeElim) break;
        const n = (dubbelLeft > 0) ? 2 : 1;
        for (let i = 0; i < n && arr.length < eersteTeElim; i++) {
            arr.push(tr - b);
        }
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
    // ea >= _AFVAL_LAATSTE_VAST i.p.v. > : bord 3 is exact het eerste vaste
    // bord van het schema. Bij weinig te elimineren rijders (zoals 3 op 13
    // starters → eindsprint 10) past alles binnen de vaste fase 3,2,1 en is
    // 'eerste afval = bord 3' een legitieme keuze. ea < 3 blijft fout
    // omdat de vaste fase dan met bord 3 overlapt en voorrang neemt.
    const ok = teElimineren <= capaciteit && dubbel <= eersteAantal && ea >= _AFVAL_LAATSTE_VAST && ea <= tr;
    return { dubbel, afvalrondes, teElimineren, ok, capaciteit, eersteAantal, vastAantal, eersteBorden };
}

// Bereken ronde-volgnummer voor een afval-positie via het schema.
// teElimineren = aantal Set-afvallers nog te plannen (excl. ByFaults).
function _afvalRondeVoorPositie(afvalPositie, cfg, teElimineren) {
    const arr = _afvalSchema(cfg, teElimineren || 0);
    if (afvalPositie < 1 || afvalPositie > arr.length) return null;
    return arr[afvalPositie - 1];
}

// Schema-grootte = VAST aantal afvallers (starters - eindsprint).
// Sinds 2026-05-27: ByFault/ByDecision verkorten het schema NIET meer —
// ze nemen een schema-positie in op het moment in de stack waar ze
// staan. Wijziging tov vorige versie waar buiten_schema werd afgetrokken
// (= incorrect, bord 21 bleef voorspeld voor eerste positie ook na
// decisions die feitelijk al meerdere bord-momenten 'verbruikten').
function _afvalSetTeElimineren(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit) return 0;
    const cfg = _afvalCfgGet(rit.heat_id) || {};
    const es  = parseInt(cfg.eindsprint) || 0;
    if (!es) return 0;
    const totaal = (rit.rijders || []).length;
    return Math.max(0, totaal - es);
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

    // Header met tellers. Volgende afval-positie = totaal stack-length + 1
    // (sinds 2026-05-27: incl. ByFault/ByDecision — die consumeren ook
    // een schema-positie, schema schuift dus mee). Voorheen werd alleen
    // buiten_schema !== true geteld wat fout bleek (volgende bord bleef
    // hetzelfde ook na decisions die schema-momenten verbruikten).
    const totaalDeelnemers = (rit.rijders || []).length;
    const setGedaan = st.afgevallen.length;
    const setTeElim = _afvalSetTeElimineren(idx);
    const autoRonde = _afvalRondeVoorPositie(setGedaan + 1, cfg, setTeElim);
    const trCfg = parseInt(cfg.totaal_ronden) || 0;
    const autoBord = (autoRonde != null && trCfg) ? (trCfg - autoRonde) : null;

    // Dubbel-resterend: hoeveel borden nog 2-tegelijk vallen. Eerste
    // 2*dubbel posities zijn dubbel; resterend bord = ceil((2*dubbel - setGedaan) / 2).
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
            const isFault    = _liveSanctieHeeft(a.sanctie, 'DQ-TF');
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
    overlay.querySelector('#avcfg-wis').addEventListener('click', async () => {
        const ok = await toonBevestigDialog(
            'Heat-configuratie wissen?',
            'Afvalkoers-config', 'Wissen', 'Annuleren'
        );
        if (!ok) return;
        _afvalCfgSet(rit.heat_id, null);
        overlay.remove();
        _afvalRerenderPaneel(ritIdx);
    });
    overlay.querySelector('#avcfg-opslaan').addEventListener('click', async () => {
        const nieuw = {
            totaal_ronden: parseInt(overlay.querySelector('#avcfg-totaal').value)     || null,
            eerste_afval:  parseInt(overlay.querySelector('#avcfg-eerste').value)     || null,
            interval:      parseInt(overlay.querySelector('#avcfg-interval').value)   || 1,
            eindsprint:    parseInt(overlay.querySelector('#avcfg-eindsprint').value) || null,
        };
        if (!nieuw.totaal_ronden || !nieuw.eerste_afval || !nieuw.eindsprint) {
            toonBevestigDialog(
                'Totaal aantal ronden, eerste afval-ronde en eindsprint zijn verplicht.',
                'Afvalkoers-config', 'OK', ''
            );
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
            const ok = await toonBevestigDialog(
                `Let op: cfg klopt niet helemaal.\n${door}\n\nToch opslaan?`,
                'Afvalkoers-config', 'Toch opslaan', 'Annuleren'
            );
            if (!ok) return;
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

    await _liveImportLaadMappen(ritIdx, false);
}

// Haal de mappenlijst op (van API), stash op de select, render dropdown.
// toonGeblokkeerd=true stuurt server om óók geblokkeerde mappen te leveren.
async function _liveImportLaadMappen(ritIdx, toonGeblokkeerd) {
    const mapSel = el('live-import-map-' + ritIdx);
    if (!mapSel) return;

    mapSel.disabled = true;
    mapSel.innerHTML = '<option value="">— laden… —</option>';
    try {
        const res  = await fetch('api/live.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'lijst_mappen', toon_geblokkeerd: toonGeblokkeerd }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Backwards-compat: oude API leverde array van strings, nieuwe levert
        // array van {name, mtime, geblokkeerd?}. Normaliseer naar object.
        const mappenRaw = data.mappen || [];
        const mappen = mappenRaw.map(m =>
            (typeof m === 'string') ? { name: m } : m
        );

        if (mappen.length === 0) {
            mapSel.innerHTML = '<option value="">— geen mappen gevonden —</option>';
        } else {
            // Stash volledige lijst incl. geblokkeerd-flag voor re-render bij filter
            mapSel.dataset.allMappen = JSON.stringify(
                mappen.map(m => ({ name: m.name, geblokkeerd: !!m.geblokkeerd }))
            );

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

// Toggle "ook geblokkeerde mappen tonen" — herladen mappenlijst en
// werk knop-icoon bij (🔓 = alleen niet-geblokkeerd, 🔒 = ook geblokkeerd).
async function _liveImportToggleGeblokkeerd(ritIdx) {
    const btn = el('live-import-toon-geblok-' + ritIdx);
    const mapSel = el('live-import-map-' + ritIdx);
    if (!btn || !mapSel) return;

    const wasActief = btn.classList.contains('actief');
    const nieuwActief = !wasActief;
    btn.classList.toggle('actief', nieuwActief);
    btn.textContent = nieuwActief ? '🔒' : '🔓';
    btn.title = nieuwActief
        ? 'Geblokkeerde mappen worden meegetoond — klik om alleen actieve te tonen'
        : 'Toon ook geblokkeerde mappen (standaard verborgen)';

    // Cache invalideren zodat de volgende _liveImportLaadMappen vers ophaalt
    delete mapSel.dataset.geladen;
    await _liveImportLaadMappen(ritIdx, nieuwActief);
}

// Render de <option>-lijst voor de map-select op basis van de gestashte
// volledige lijst en een optionele filter-string (case-insensitive substring).
// $preselect is optioneel: als die waarde bestaat in de gefilterde lijst,
// wordt die voorgeselecteerd.
function _liveImportRenderMapOpties(ritIdx, filter = '', preselect = '') {
    const mapSel = el('live-import-map-' + ritIdx);
    if (!mapSel) return;
    const allJson = mapSel.dataset.allMappen || '[]';
    let allRaw;
    try { allRaw = JSON.parse(allJson); } catch { allRaw = []; }
    // Backwards-compat: legacy stash was array van strings, nieuwe stash is
    // array van objects {name, geblokkeerd}. Normaliseer naar object-form.
    const all = allRaw.map(n =>
        (typeof n === 'string') ? { name: n, geblokkeerd: false } : n
    );
    const q = (filter || '').trim().toLowerCase();
    const mapped = q
        ? all.filter(o => o.name.toLowerCase().includes(q))
        : all;

    if (mapped.length === 0) {
        mapSel.innerHTML = '<option value="">— geen match —</option>';
        return;
    }
    mapSel.innerHTML =
        '<option value="">— kies een map —</option>' +
        mapped.map(o => {
            const naam   = o.name;
            const blokIc = o.geblokkeerd ? '🔒 ' : '';
            const sel    = naam === preselect ? ' selected' : '';
            return `<option value="${escHtml(naam)}"${sel}>${blokIc}${escHtml(naam)}</option>`;
        }).join('');
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
    // Eliminatie-bescherming: rijders die "uit de race" zijn gezet door de
    // admin (via afval-paneel of een eliminerende sanctie) hebben hun rondes
    // én tijd HANDMATIG bepaald. Een CSV-import zou:
    //   - rondes overschrijven met transponder-rondes (te laag — rijder
    //     was al uit) → verkeerde sortering
    //   - tijd overschrijven met transponder-tijd (geen valide finish) →
    //     breekt ex-aequo binnen dezelfde elimination-ronde, wat juist het
    //     hele punt van de manuele input is
    // Daarom skippen we voor deze rijders BEIDE updates uit de CSV.
    const isAfvalkoers = rit.race_type === 'afvalkoers';
    for (const r of rit.rijders) {
        if (r.startnummer == null) continue;
        const csvRij = csvMap.get(r.startnummer);
        if (!csvRij) continue;
        gevonden++;
        const tijdVal = csvRij.tijd_ms ? _msTijdNaarDisplay(csvRij.tijd_ms) : '';

        // Eliminatie-detectie: afvalkoers met afval_rang OF tijd-wissende
        // sanctie (DNF, DQ-TF, DQ-SF, DQ-DF, DNS — al gedefinieerd in
        // _SANCTIE_WIST_TIJD voor de save-flow).
        const isAfgevallen = (isAfvalkoers && r.afval_rang != null)
                          || _liveSanctieHeeftSet(r.sanctie, _SANCTIE_WIST_TIJD);

        const rondenVal = (!isAfgevallen && csvRij.ronden != null)
            ? String(csvRij.ronden) : null;

        // Lokale state bijwerken (zodat linker panel herbouwt met juiste rondes)
        if (!isAfgevallen && csvRij.ronden != null) r.rondes = csvRij.ronden;

        // Tijd + rondes invullen; sanctie ongemoeid laten.
        // Bij afgevallen rijder: zowel tijd als rondes overslaan.
        [`[data-entry="${r.entry_id}"]`, `[data-panel-entry="${r.entry_id}"]`].forEach(cssSelStr => {
            document.querySelectorAll(cssSelStr).forEach(rij => {
                const t = rij.querySelector('.live-tijd-inp');
                if (t && t !== document.activeElement && !isAfgevallen) t.value = tijdVal;
                if (rondenVal != null) {
                    const rnInp = rij.querySelector('.live-rondes-inp');
                    if (rnInp) rnInp.value = rondenVal;
                }
            });
        });
    }

    _liveHerbereken(ritIdx);
    // Stale wissel-dropdowns van vóór de import bevatten nog oude posities.
    // _liveHerbereken laat die met opzet ongemoeid (om manuele wissel-keuzes
    // te beschermen tijdens typen). Bij CSV-import komen alle tijden vers
    // binnen → dan moet de "Fin." kolom direct kloppen, dus dropdowns +
    // r.finishpositie hier opnieuw bouwen i.p.v. wachten op een save.
    _liveResetFinishCellen(ritIdx);

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

// Bouw alle finish-cellen opnieuw op basis van de huidige DOM-state (tijden +
// sancties + rondes). Bedoeld voor situaties waarin de oude wissel-dropdowns
// stale zijn — typisch ná een CSV-import: _liveHerbereken() werkt alleen de
// .live-finish-badge-elementen bij, dropdowns zou dat manuele wissel-keuzes
// overschrijven. Bij CSV-import bestaan die manuele keuzes echter nog niet
// (alle tijden komen vers binnen) — dan moet de "Fin." kolom direct kloppen,
// anders ziet de operator nepposities tot hij eerst opslaat.
function _liveResetFinishCellen(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit || !rit.rijders) return;
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);
    if (!kaart) return;

    const isPuntenkoers = rit.race_type === 'puntenkoers';

    // 1) DOM → rijder-state synchroniseren (tijd_ms, sanctie, rondes). Nodig
    //    omdat de PK-specifieke positie-berekening via r.* werkt en de save-
    //    payload ook van r.* uitgaat. Bij CSV-import is r.tijd_ms anders nog
    //    de oude DB-waarde terwijl de DOM-input al de nieuwe tijd toont.
    //    Tegelijk: wissel-locks resetten — re-import is bewust de "undo" voor
    //    eerdere swaps, dus de _wisselt-flags moeten weg en de cellen worden
    //    daarna als verse dropdowns/badges opgebouwd.
    rit.rijders.forEach(r => {
        const rij    = kaart.querySelector(`[data-entry="${r.entry_id}"]`);
        if (!rij) return;
        const inp    = rij.querySelector('.live-tijd-inp');
        const sel    = rij.querySelector('.live-sanctie-sel');
        const rnInp  = rij.querySelector('.live-rondes-inp');
        if (inp)  r.tijd_ms = _parseTijdInvoer(inp.value);
        if (sel)  r.sanctie = sel.value || null;
        if (rnInp) r.rondes = rnInp.value !== '' ? (parseInt(rnInp.value) || null) : null;
        delete r._wisselt;
    });

    // 2) Posities berekenen — PK via punten→rondes→tijd, andere races via de
    //    standaard _berekenPosities (combi-aware).
    if (isPuntenkoers) {
        // Werkt rechtstreeks op rit.rijders, schrijft r.finishpositie
        _liveHerrekenPKFinishposities(rit);
    } else {
        const entries = rit.rijders.map(r => ({
            entry_id: r.entry_id,
            tijd_ms:  r.tijd_ms,
            sanctie:  r.sanctie,
            rondes:   r.rondes,
        }));
        const posMap = new Map();
        if (rit.is_combi) {
            for (const lid of rit.combi_leden) {
                const subset = entries.filter(e => {
                    const rij = rit.rijders.find(rr => rr.entry_id === e.entry_id);
                    return rij && rij._combi_rit_id === lid.rit_id;
                });
                _berekenPosities(subset, true).forEach((v, k) => posMap.set(k, v));
            }
        } else {
            _berekenPosities(entries, true).forEach((v, k) => posMap.set(k, v));
        }
        rit.rijders.forEach(r => {
            r.finishpositie = posMap.get(r.entry_id) ?? null;
        });
    }

    // 3) ValidPosities per (combi-)groep — alleen wisselen tussen peers in
    //    dezelfde categorie heeft betekenis.
    const validPositiesVoorRijder = (r) => {
        const peers = rit.is_combi
            ? rit.rijders.filter(rij => rij._combi_rit_id === r._combi_rit_id)
            : rit.rijders;
        return [...new Set(peers.map(rr => rr.finishpositie).filter(Boolean))]
            .sort((a, b) => a - b);
    };

    // 4) Vervang elke .live-col-finish-cel door de juiste markup.
    //    Event-delegation op .live-finish-sel zit op de kaart (zie _liveBind),
    //    dus nieuwe selects krijgen automatisch de change-handler.
    //    Dropdowns alleen als de rit "alles compleet" is — anders zou een
    //    half-geïmporteerde CSV al wisselbare posities tonen, terwijl er nog
    //    rijders zonder tijd zijn. Operator wacht eerst op volledige invoer.
    const allesCompleet = _liveAllesCompleet(ritIdx);
    rit.rijders.forEach(r => {
        const rij = kaart.querySelector(`[data-entry="${r.entry_id}"]`);
        const cel = rij?.querySelector('.live-col-finish');
        if (!cel) return;
        const validPosities = validPositiesVoorRijder(r);
        if (r.finishpositie && validPosities.length > 1 && !_liveLeesOnly && allesCompleet) {
            cel.innerHTML = `<select class="live-finish-sel">` +
                validPosities.map(p =>
                    `<option value="${p}"${p === r.finishpositie ? ' selected' : ''}>${p}</option>`
                ).join('') + `</select>`;
        } else {
            // PK krijgt extra finish-pos-punten klasse zodat de badge dezelfde
            // kleur heeft als die _liveUpdatePuntenBadges normaal toepast.
            const extra  = isPuntenkoers ? ' finish-pos-punten' : '';
            const klasse = r.finishpositie ? ' finish-pos' + extra : '';
            const tekst  = r.finishpositie ? _ordinaal(r.finishpositie) : '—';
            cel.innerHTML = `<span class="live-finish-badge${klasse}">${tekst}</span>`;
        }
    });
}

// Vervang de finish-cel van een gewisselde rijder door een vergrendeld badge
// (klein slotje + huidige positie). Operator ziet dat de rijder al gewisseld
// is en niet opnieuw kan worden geswapt. Re-import van de CSV wist het slot.
function _liveLockGewisseldeCel(ritIdx, r) {
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);
    const cel   = kaart?.querySelector(`[data-entry="${r.entry_id}"] .live-col-finish`);
    if (!cel || !r.finishpositie) return;
    cel.innerHTML = `<span class="live-finish-badge finish-pos finish-pos-gewisselt"
        title="Deze rijder is al gewisseld. Importeer de CSV opnieuw om de wissel ongedaan te maken.">🔒 ${_ordinaal(r.finishpositie)}</span>`;
}

// Vervang finish-badges door wissel-dropdowns na opslaan
function _liveActiveerWisselDropdowns(ritIdx) {
    const rit = _liveRitten[ritIdx];
    if (!rit || _liveLeesOnly) return;
    const kaart = document.querySelector(`.live-carousel-card[data-idx="${ritIdx}"]`);
    if (!kaart) return;

    // Bij combi-rit: validPosities PER LEDEN berekenen — een rijder kan
    // alleen geswapt worden met een andere rijder uit dezelfde categorie,
    // anders zou je zomaar Fin-getallen door elkaar kunnen halen tussen cats.
    const validPositiesVoorRijder = (r) => {
        const peers = rit.is_combi
            ? rit.rijders.filter(rij => rij._combi_rit_id === r._combi_rit_id)
            : rit.rijders;
        return [...new Set(peers.map(rr => rr.finishpositie).filter(Boolean))]
            .sort((a, b) => a - b);
    };

    rit.rijders.forEach(r => {
        if (!r.finishpositie) return;

        // Reeds gewisselde rijders: lock-badge tonen (geen dropdown). Voorkomt
        // dat de operator per ongeluk een tweede swap doet die de tijd-state
        // versmelt. Re-import resetf via _liveResetFinishCellen.
        if (r._wisselt) {
            _liveLockGewisseldeCel(ritIdx, r);
            return;
        }

        const validPosities = validPositiesVoorRijder(r);
        if (validPosities.length < 2) return;

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
// opts.allesCompleet = true → wissel-dropdowns mogen actief zijn (jury-fase)
//                      false → alleen badges (rit nog niet volledig ingevoerd)
function _liveRijRij(r, compact = false, validPosities = [], rangMap = new Map(), opts = {}) {
    const { heeftRondes = false, allesCompleet = false, currentRit = null } = opts;
    const tijdVal = r.tijd_ms !== null ? _msTijdNaarDisplay(r.tijd_ms) : '';

    // DB = UI codes, geen mapping meer nodig
    const sanctieUi = r.sanctie || '';

    const disabled = _liveLeesOnly ? 'disabled' : '';

    // FS = waarschuwing, niet rood; DQ-DF/DNS/DNF/DQ-SF = rood
    // Multi-sanctie: alleen-FS (zonder DQ/DNF/DNS in lijst) telt nog steeds als
    // "compleet"-status. Met DQ/DNF/DNS naast FS = sanctie-status.
    const heeftStopper = _liveSanctieHeeftSet(r.sanctie, _SANCTIE_WIST_TIJD);
    const alleenFS = _liveSanctieHeeft(r.sanctie, 'FS') && !heeftStopper;
    const statusKlasse = alleenFS
        ? (r.tijd_ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg')
        : r.sanctie
            ? 'live-rit-status-sanctie'
            : (r.tijd_ms > 0 ? 'live-rit-status-compleet' : 'live-rit-status-leeg');

    const transponder = escHtml(r.transponder_actief ?? '—');

    // Photofinish-icoon naast de naam: alleen tonen als de rijder in zijn
    // direct voorgaande ronde een jury-wissel had. Verdwijnt zodra hij
    // normaal (zonder swap) een ronde verder gaat.
    const pfIcon = _liveHeeftPhotofinishVorigeRonde(r, currentRit) ? _livePhotofinishIcon() : '';

    return `<tr class="live-rij ${statusKlasse}" data-entry="${r.entry_id}" data-rondes="${r.rondes ?? ''}" data-punten="${r.punten ?? ''}">` +
        `<td class="heat-pos">${r.startpositie}</td>` +
        (!compact ? `<td class="heat-snr">${r.startnummer ?? ''}</td>` : '') +
        `<td class="heat-naam">${escHtml(r.full_name || '')}${pfIcon}</td>` +
        (!compact ? `<td class="heat-tp">${transponder}</td>` : '') +
        (heeftRondes ? `<td class="live-col-rondes"><input type="number" class="live-rondes-inp" value="${r.rondes ?? ''}" min="0" placeholder="—" ${disabled} inputmode="numeric"></td>` : '') +
        `<td class="live-col-tijd">` +
        `<input type="text" class="live-tijd-inp" value="${escHtml(tijdVal)}"` +
        ` placeholder="0:00.000" ${disabled} inputmode="decimal">` +
        `</td>` +
        `<td class="live-col-sanctie">` +
        // Multi-sanctie chip-picker. Hidden input bewaart de huidige
        // comma-separated waarde (= bestaande .value-API blijft werken voor
        // alle externe code). Knop ernaast toont de actieve codes en opent
        // bij click een chip-popover waar de operator codes aan/uit klikt.
        _liveBouwSanctieMulti(sanctieUi, disabled) +
        `</td>` +
        `<td class="live-col-finish">` +
        // Wissel-dropdown alleen als alles-compleet (jury-fase). Anders badge —
        // posities kunnen nog verspringen tijdens invoer en dat zou verwarrend
        // zijn als de operator al ergens een wissel-keuze heeft gemaakt.
        (r.finishpositie && validPosities.length > 1 && !_liveLeesOnly && allesCompleet
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
                        _liveHergeneerKlik(b);
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

    // Auto silent refresh: zodra gebruiker een nieuwe heat opent, even DB
    // checken op verse data (bv. DNS-markeringen door AoC). Async, non-
    // blocking. Skipt zelf bij _liveOngeslagen. forceerRender=false → re-render
    // alleen als er voor de huidige rit echt iets is veranderd, anders blijft
    // de visuele wissel die we net hebben gedaan onverstoord.
    _liveHerlaadStil(false);
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
    // Multi-day: pijl-toetsen skippen ook naar dag-passende ritten
    const dagInfo = _liveDagInfo();
    const isMulti = !!dagInfo?.isMultiDag;
    if (e.key === 'ArrowLeft') {
        const tgt = isMulti
            ? _liveZoekIdxOpDag(_liveHuidigIdx, -1, _liveActieveDag)
            : _liveHuidigIdx - 1;
        if (tgt >= 0) _liveNavigeer(tgt);
    }
    if (e.key === 'ArrowRight') {
        const tgt = isMulti
            ? _liveZoekIdxOpDag(_liveHuidigIdx, +1, _liveActieveDag)
            : _liveHuidigIdx + 1;
        if (tgt >= 0) _liveNavigeer(tgt);
    }
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

        // Bij afvalkoers: afval-state wint van DOM-sanctie/tijd. Voor een
        // afgevallen rijder (by-decision OF by-fault) zetten we tijd_ms op
        // NULL — de transponder heeft mogelijk nog tijden geregistreerd ná
        // het uit-de-race-halen, maar die zijn niet representatief en zouden
        // de ex-aequo binnen dezelfde elimination-ronde breken bij de uitslag-
        // sortering (rondes-DESC → tijd-ASC). Geen tijd = ex-aequo blijft
        // intact zoals admin bedoeld heeft via de handmatige ronde-input.
        const afval = afvalMap.get(r.entry_id);
        if (isAfvalkoers && afval) {
            // Defense-in-depth: DNS-rijders horen geen afval_rang te hebben
            // (= geen positie). _afvalSyncSanctie filtert ze al uit de stack,
            // maar voor stale state houden we hier ook nog een check op de
            // live DOM-sanctie.
            const isDns = _liveSanctieHeeft(afval.sanctie, 'DNS') || _liveSanctieHeeft(sanctieDom, 'DNS');
            return {
                entry_id:       r.entry_id,
                tijd_ms:        null,                    // afgevallen → geen valide finish-tijd
                sanctie:        isDns ? 'DNS' : (afval.sanctie || null),
                rondes,
                afval_rang:     isDns ? null : afval.plek,
                // Photofinish-vlag meesturen — wissel_posities zet deze al
                // direct in DB op 1, maar bij re-import/save zonder _wisselt
                // moet 'ie weer 0 worden zodat oude swaps opgeschoond raken.
                is_photofinish: r._wisselt ? 1 : 0,
            };
        }

        const tijdOpslaan = _liveSanctieHeeftSet(sanctieDom, _SANCTIE_WIST_TIJD) ? null : (tijdMs ?? null);
        return {
            entry_id:       r.entry_id,
            tijd_ms:        tijdOpslaan,
            sanctie:        sanctieDom || null,
            rondes,
            afval_rang:     null, // niet-afvalkoers OF afvalkoers-finishgroep
            is_photofinish: r._wisselt ? 1 : 0,
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
        // Save-fan-out: bij combi-rit één POST per leden, anders één POST.
        // Server slaat nog steeds per heat_entry (entry_id) op — alleen het
        // groeperen per rit_id verschilt. Promise.all faalt zodra één leden
        // mislukt, dat is gewenst: we willen dan niet half-opgeslagen achterlaten.
        const saveCalls = rit.is_combi
            ? rit.combi_leden.map(lid => {
                const ledenResults = results.filter(rs => {
                    const rij = rit.rijders.find(rr => rr.entry_id === rs.entry_id);
                    return rij && rij._combi_rit_id === lid.rit_id;
                });
                return fetch('api/live.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        action:         'save_rit_results',
                        competition_id: huidigCompId,
                        rit_id:         lid.rit_id,
                        results:        ledenResults,
                    }),
                }).then(async res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const d = await res.json();
                    if (d.error) throw new Error(d.error);
                    return d;
                });
            })
            : [(async () => {
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
                const d = await res.json();
                if (d.error) throw new Error(d.error);
                return d;
            })()];

        const responses = await Promise.all(saveCalls);

        _liveOngeslagen = false;

        // Server-finishposities mergen uit alle responses (per leden)
        const serverFp = {};
        for (const d of responses) {
            Object.assign(serverFp, d.finishposities || {});
        }

        // Lokale state bijwerken. Bij combi: bereken fallback PER LEDEN zodat
        // de Fin-getallen ook lokaal per categorie geteld worden, conform de UI.
        // isAfvalkoers doorgeven zodat DNS in afvalkoers geen N+1 krijgt
        // (matcht back-end gedrag).
        const isAfvalkoers = rit.race_type === 'afvalkoers';
        const posMap = new Map();
        if (rit.is_combi) {
            for (const lid of rit.combi_leden) {
                const ledenResults = results.filter(rs => {
                    const rij = rit.rijders.find(rr => rr.entry_id === rs.entry_id);
                    return rij && rij._combi_rit_id === lid.rit_id;
                });
                _berekenPosities(ledenResults, true, isAfvalkoers).forEach((v, k) => posMap.set(k, v));
            }
        } else {
            _berekenPosities(results, true, isAfvalkoers).forEach((v, k) => posMap.set(k, v));
        }
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

    // Na save: volgende ronde bijwerken (buiten try/catch zodat save-feedback niet verstoord wordt).
    // Bij combi-rit: doe dit PER LEDEN — elke categorie heeft zijn eigen
    // ronde-keten (eigen dc/distance), dus we vuren één keten-stap per leden.
    const rit2 = _liveRitten[ritIdx];
    if (rit2 && !_liveLeesOnly) {
        const ketenLeden = rit2.is_combi
            ? rit2.combi_leden.map(l => ({ dc_id: l.dc_id, distance_id: l.distance_id, ronde_type: rit2.ronde_type, dc_naam: l.dc_naam || rit2.dc_naam }))
            : [{ dc_id: rit2.dc_id, distance_id: rit2.distance_id, ronde_type: rit2.ronde_type, dc_naam: rit2.dc_naam }];

        let enigVolgendGevonden = false;
        for (const lid of ketenLeden) {
            const volgende2 = _volgendeRondeType(lid.dc_id, lid.distance_id, lid.ronde_type);
            if (volgende2) {
                enigVolgendGevonden = true;
                const compleet = _liveRondeCompleet(lid.dc_id, lid.distance_id, lid.ronde_type);
                // Geef altijd lid.dc_naam mee als split_dc_naam zodat server
                // qualifiers op deze specifieke split focust. Bij niet-split
                // DC is filter no-op (dc_naam uniek per DC).
                const lidSplitNaam = lid.dc_naam || rit2.dc_naam || '';
                _liveGenereerKetenStap(lid.dc_id, lid.distance_id, lid.ronde_type, volgende2, compleet,
                    { splitDcNaam: lidSplitNaam })
                    .catch(() => {}); // stil falen
            }
        }
        if (!enigVolgendGevonden) {
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
async function _liveGenereerKetenStap(dcId, distanceId, van, naar, compleet = true, ketenOpts = {}) {
    // Zelfde key-conventie als _volgendeRondeType: dcId + '|' + distanceId
    const cc = _liveCatConfigs[dcId + '|' + distanceId];
    const ookRu = !!(cc?.heeft_runner_up && _isEersteRondeKeten(cc, van));

    // splitDcNaam wordt doorgegeven aan _liveGenereerVolgendeRonde via opts —
    // server filtert dan qualifiers/cleanups/doelritten op die ene split.
    const splitDcNaam = ketenOpts.splitDcNaam || '';

    // Beide rondes met onderdrukte toast — de toast obliterates anders de
    // vorige en de gebruiker mist de eerste melding. We bouwen 1 gecombineerde
    // toast aan het einde.
    const r1 = await _liveGenereerVolgendeRonde(dcId, distanceId, van, naar, compleet, { silent: ookRu, splitDcNaam });
    if (!ookRu) return;

    const r2 = await _liveGenereerVolgendeRonde(dcId, distanceId, van, 'runner_up', compleet, { silent: true, splitDcNaam });

    // Beide no-op (ongewijzigd of door operator geannuleerd) → geen toast.
    const isNoop = r => r?.ongewijzigd || r?.geannuleerd;
    if (isNoop(r1) && isNoop(r2)) return;

    // Combined toast — toon ALTIJD beide regels, ook als één leeg is, zodat
    // de gebruiker direct ziet of de runner-up wel/niet ritten heeft gekregen.
    const lijn = (res, label) => res?.geannuleerd
        ? `${label}: niet bijgewerkt (geannuleerd)`
        : res?.ongewijzigd
            ? `${label}: ongewijzigd`
            : res
                ? `${label}: ${res.aantal} rijders`
                : `${label}: niet aangemaakt`;
    const bericht = compleet
        ? `✓ Startlijsten klaar — ${lijn(r1, r1?.label || naar)}; ${lijn(r2, 'Runner-up')}`
        : `📋 Voorlopig bijgewerkt — ${lijn(r1, r1?.label || naar)}; ${lijn(r2, 'Runner-up')}`;
    _liveToast(bericht, compleet ? 'ok' : 'bezig', compleet ? 5000 : 3500);
}

// compleet = alle heats in de ronde zijn klaar (anders is het een voorlopige update)
// opts.silent = true → geen success-toast (voor combined toast door keten-helper)
// opts.force = true → server slaat de bevestigingsvraag over (dialog al ja gedrukt)
// opts.splitDcNaam = ''/string → bij split-DCs filtert server qualifiers/cleanups
//                                /doelritten op deze ene split. Leeg = niet-split.
// Returns: { aantal, label } op succes, null op fout/skip,
//          { ongewijzigd: true } of { geannuleerd: true } bij no-op.
async function _liveGenereerVolgendeRonde(dcId, distanceId, van, naar, compleet = true, opts = {}) {
    if (!huidigCompId || !dcId || !van || !naar) return null; // Guard: ontbrekende params
    const silent       = !!opts.silent;
    const force        = !!opts.force;
    const splitDcNaam  = opts.splitDcNaam || '';
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
                split_dc_naam:   splitDcNaam,
                force:           force,
            }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Server-side optimalisatie: als de qualifying-set onveranderd is,
        // doet de server NIETS (geen DELETE/INSERT) — dan moeten wij ook
        // geen reload triggeren, anders flikkert de UI alsnog onnodig en
        // verlies je de huidige carousel-positie.
        if (data.ongewijzigd) {
            if (btn) {
                btn.disabled    = false;
                btn.textContent = `↻ Hergeneer ${label}`;
            }
            // Geen toast bij silent-mode (keten-helper bouwt zelf een combo).
            return { aantal: 0, label, ongewijzigd: true };
        }

        // Server vraagt bevestiging — er staan al resultaten in de doel-ronde
        // die zouden verdwijnen door de regenerate. Toon in-house dialog met
        // de concrete impact ("KF: 12 resultaten") en laat de operator kiezen.
        if (data.vraag_bevestiging) {
            const ronLabel = (rt) => RONDE_LABEL[rt] || rt;
            const lijst = (data.te_wissen || [])
                .map(t => `<li><b>${escHtml(ronLabel(t.ronde_type))}</b>: ${t.aantal_results} resultaten</li>`)
                .join('');
            const bericht =
                `<p>De qualifying-set is veranderd door je laatste wijziging.</p>` +
                `<p>Bij hergenereren gaan deze reeds-ingevoerde resultaten verloren:</p>` +
                `<ul>${lijst}</ul>` +
                `<p>Doorgaan met hergenereren?</p>`;
            const ok = await toonBevestigDialog(
                bericht,
                'Bestaande resultaten wissen?',
                'Hergenereren',
                'Behoud bestaande',
                { bodyIsHtml: true }
            );
            if (!ok) {
                // Operator kiest "annuleren" — bestaande downstream blijft staan
                // (mogelijk inconsistent met heats, maar dat accepteert operator
                // bewust). Knop terug naar normaal.
                if (btn) {
                    btn.disabled    = false;
                    btn.textContent = `↻ Hergeneer ${label}`;
                }
                if (!silent) {
                    _liveToast(`⚠ ${label} niet bijgewerkt — bestaande resultaten behouden`,
                        'info', 4000);
                }
                return { aantal: 0, label, geannuleerd: true };
            }
            // Bevestigd → opnieuw aanroepen met force=true zodat server
            // de pre-check overslaat en gewoon regenereert.
            return await _liveGenereerVolgendeRonde(dcId, distanceId, van, naar, compleet,
                { ...opts, force: true });
        }

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
