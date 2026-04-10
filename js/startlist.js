/* InlineComp – startlijsten */

let _slLeesOnly = false;  // true als huidige gebruiker geen schrijfrechten heeft
let _slGroepen  = [];     // alle opgebouwde groepen (voor tab-kleur refresh)

// ── Loting-status cache (voor tab-kleuren) ────────────────────────────────────
let _slStatusCache = null; // { competition_id, geloot: Set<string> }
let _slDistCache   = {};   // 'dc_id|splitKey' → [afstanden]  (afstandencache per groep)

async function laadSlStatus() {
    if (_slStatusCache?.competition_id === huidigCompId) return _slStatusCache.geloot;
    _slStatusCache = null;
    if (!huidigCompId) return new Set();
    try {
        const res  = await fetch(`api/startlijst_status.php?competition_id=${encodeURIComponent(huidigCompId)}`);
        const data = await res.json();
        const geloot   = new Set();
        const rondeMap = new Map();   // statusKey → max_ronde
        if (Array.isArray(data)) {
            for (const r of data) {
                const key = `${r.distance_combination_id}||${r.distance_id}||${r.split_group}`;
                geloot.add(key);
                rondeMap.set(key, parseInt(r.max_ronde) || 1);
            }
        }
        _slStatusCache = { competition_id: huidigCompId, geloot, rondeMap };
        return geloot;
    } catch { return new Set(); }
}

function invalideerSlStatus() { _slStatusCache = null; _slDistCache = {}; }

// Haal afstanden op voor een groep, met cache
async function laadGroepAfstanden(groep) {
    const cKey = groep.dc_id + (groep.is_split ? '|' + groep.dc_name : '');
    if (_slDistCache[cKey]) return _slDistCache[cKey];
    try {
        const splitParam = groep.is_split ? `&split_group=${encodeURIComponent(groep.dc_name)}` : '';
        const res = await fetch(`api/distances_db.php?dc_id=${encodeURIComponent(groep.dc_id)}${splitParam}`);
        const d   = await res.json();
        const afs = Array.isArray(d) ? d.filter(a => !a.error) : [];
        _slDistCache[cKey] = afs;
        return afs;
    } catch { return []; }
}

// Kleur de dist-tab voor de actieve afstand (groen = geloot, default = niet)
function zetDistTabKleur(distId, heeftLoting) {
    const distTabsEl = el('sl-dist-tabs');
    if (!distTabsEl) return;
    const btn = distTabsEl.querySelector(`[data-dist-id="${String(distId ?? '').replace(/"/g, '\\"')}"]`);
    if (!btn) return;
    btn.classList.toggle('tab-gereed', !!heeftLoting);
}

// Kleur alle cat-tabs op basis van loting-status (achtergrond, niet-blokkerend)
async function kleurAlleTabsAsync(groepen, catTabsEl) {
    if (!huidigCompId || !groepen?.length) return;
    try {
        const geloot  = await laadSlStatus();
        const tabBtns = Array.from(catTabsEl.querySelectorAll('.org-tab-btn'));

        await Promise.all(groepen.map(async (groep, i) => {
            const btn = tabBtns[i];
            if (!btn) return;

            const dcId       = groep.dc_id;
            const splitGroup = groep.is_split ? groep.dc_name : '';

            // Gebruik gecachede afstanden (voorkomt dubbele fetches)
            const afstanden = await laadGroepAfstanden(groep);

            // Bouw sleutels: één per afstand (of één zonder afstand)
            const keys = afstanden.length > 0
                ? afstanden.map(a => `${dcId}||${String(a.id ?? '')}||${splitGroup}`)
                : [`${dcId}||||${splitGroup}`];

            const nGeloot = keys.filter(k => geloot.has(k)).length;
            const nTotaal = keys.length;

            btn.classList.remove('tab-gereed', 'tab-deels');
            if      (nGeloot > 0 && nGeloot >= nTotaal) btn.classList.add('tab-gereed');
            else if (nGeloot > 0)                        btn.classList.add('tab-deels');
        }));
    } catch { /* silent – kleuren zijn niet-kritiek */ }
}

// Gecachete klassementen-lijst voor loting-UI (lazy geladen)
let slKlassementen = null;
async function laadSlKlassementen() {
    if (slKlassementen) return slKlassementen;
    try {
        const r = await fetch('api/klassement_import.php?action=list');
        const d = await r.json();
        slKlassementen = Array.isArray(d) ? d : [];
    } catch { slKlassementen = []; }
    return slKlassementen;
}

// ── Tijdschema-cache voor startlijsten (per competition_id) ───────────────────

let _slTsCache = null;   // { competition_id, schema }

async function laadSlTijdschema() {
    if (_slTsCache?.competition_id === huidigCompId) return _slTsCache.schema;
    _slTsCache = null;
    if (!huidigCompId) return null;
    try {
        const res  = await fetch(`api/tijdschema.php?competition_id=${encodeURIComponent(huidigCompId)}`);
        const data = await res.json();
        if (data?.error || !data?.id) return null;
        _slTsCache = { competition_id: huidigCompId, schema: data };
        return data;
    } catch { return null; }
}

// Zoek cat_config voor dc_id + distance_id (distance_id kan null/leeg zijn)
function slVindCatCfg(schema, dcId, distId) {
    if (!schema?.cat_configs) return null;
    const key = String(distId ?? '');
    return schema.cat_configs.find(c =>
        c.dc_id === dcId && String(c.distance_id ?? '') === key
    ) ?? null;
}

// Bouw ronde-flow op basis van cat_config + systeem
// Geeft array van { sleutel, naam } terug
function bouwSlFlow(catCfg, systeem) {
    const KLEUREN = typeof TS_RONDE_KLEUR !== 'undefined' ? TS_RONDE_KLEUR : {};
    if (!catCfg || !systeem) return [{ sleutel: 'heats', naam: 'Series', kleur: KLEUREN.heats || '#0d6efd' }];
    const flow = [];
    if (catCfg.heeft_heats && catCfg.heeft_heats !== '0')
        flow.push({ sleutel: 'heats',        naam: 'Series',       kleur: KLEUREN.heats        || '#0d6efd' });
    if (catCfg.heeft_kwartfinale)
        flow.push({ sleutel: 'kwartfinale',  naam: 'Kwartfinale',  kleur: KLEUREN.kwartfinale  || '#6610f2' });
    if (catCfg.heeft_halve_finale)
        flow.push({ sleutel: 'halve_finale', naam: 'Halve finale', kleur: KLEUREN.halve_finale || '#fd7e14' });
    if (catCfg.heeft_runner_up)
        flow.push({ sleutel: 'runner_up',    naam: 'Runner-up',    kleur: KLEUREN.runner_up    || '#6c757d' });
    if (systeem === 'full-final') {
        flow.push({ sleutel: 'finale_a', naam: 'A-finale', kleur: KLEUREN.finale_a || '#198754' });
        flow.push({ sleutel: 'finale_b', naam: 'B-finale(s)', kleur: KLEUREN.finale_b || '#20c997' });
    } else {
        flow.push({ sleutel: 'finale_a', naam: 'A-finale', kleur: KLEUREN.finale_a || '#198754' });
    }
    return flow.length ? flow : [{ sleutel: 'heats', naam: 'Series', kleur: '#0d6efd' }];
}

// ── Startlijst-groepen bouwen (merge + split + normaal) ───────────────────────

function bouwStartlijstGroepen() {
    const groepen = [];
    const gezien  = new Set();

    for (const cat of vergelijkData) {
        if (gezien.has(cat.dc_id)) continue;

        // ── Merge: meerdere DCs samenvoegen ──────────────────────────────────
        const mg = cat.merge_group;
        if (mg) {
            const merged = vergelijkData.filter(c => c.merge_group === mg);
            merged.forEach(c => gezien.add(c.dc_id));
            groepen.push({
                dc_id:        merged[0].dc_id,
                dc_ids:       merged.map(c => c.dc_id),
                dc_name:      merged.map(c => c.dc_name).join(' + '),
                merge_label:  merged.find(c => c.merge_label)?.merge_label ?? null,
                merge_group:  mg,
                has_distances: merged[0].has_distances,
                competitors:  merged.flatMap(c => c.competitors),
            });
            continue;
        }

        gezien.add(cat.dc_id);

        // ── Split: één DC opsplitsen per categorie ────────────────────────────
        const splits = cat.splits || {};
        if (Object.keys(splits).length > 0) {
            // Groepeer split-namen → welke categorieën horen bij elke naam
            const splitNamen = {};
            Object.entries(splits).forEach(([catCode, naam]) => {
                if (!splitNamen[naam]) splitNamen[naam] = [];
                splitNamen[naam].push(catCode);
            });

            // Categorieën zonder split-toewijzing → eigen groep per categorie
            const toegewezen = new Set(Object.keys(splits));
            const alleCategorieen = [...new Set(
                cat.competitors.map(c => c.knsb?.category).filter(Boolean)
            )];
            alleCategorieen.filter(c => !toegewezen.has(c)).forEach(c => {
                splitNamen[c] = [c];
            });

            Object.entries(splitNamen).forEach(([naam, catCodes]) => {
                const catFilter = catCodes;
                const deelnemers = cat.competitors.filter(c =>
                    catFilter.includes(c.knsb?.category)
                );
                groepen.push({
                    dc_id:           cat.dc_id,
                    dc_ids:          [cat.dc_id],
                    dc_name:         naam,
                    category_filter: catFilter,
                    has_distances:   cat.has_distances,
                    is_split:        true,
                    competitors:     deelnemers,
                });
            });
            continue;
        }

        // ── Normaal: één op één ───────────────────────────────────────────────
        groepen.push({ ...cat, dc_ids: [cat.dc_id] });
    }
    return groepen;
}

// ── Print-selects vullen met voltooide lotingen ───────────────────────────────
// Structuur: Map< dcName, Map< distId, { distNaam, ronden: [{label, sleutel, optData}] } > >
let _slPrintOpties = new Map();

// Ronde-labels passend bij ronde_type / flow-sleutel
const SL_RONDE_LABEL = {
    heats:        'Series',
    kwartfinale:  'Kwartfinale',
    halve_finale: 'Halve finale',
    runner_up:    'Runner-up',
    finale:       'Finale',
    finale_a:     'A-Finale',
    finale_b:     'B-Finale',
};

async function vulPrintSelect() {
    const catSel   = el('sl-print-cat-sel');
    const distSel  = el('sl-print-dist-sel');
    const rondeSel = el('sl-print-ronde-sel');
    const btn      = el('sl-btn-print');
    if (!catSel) return;

    // Volledig reset
    _slPrintOpties = new Map();
    catSel.innerHTML   = '<option value="">— Categorie —</option>';
    if (distSel)  { distSel.innerHTML  = '<option value="">— Afstand —</option>';  distSel.disabled  = true; }
    if (rondeSel) { rondeSel.innerHTML = '<option value="">— Ronde —</option>';    rondeSel.disabled = true; }
    if (btn) btn.disabled = true;

    // Altijd verse status ophalen: nieuwe rondes (gegenereerd in live verwerking)
    // moeten hier direct zichtbaar zijn zonder dat een page refresh nodig is.
    invalideerSlStatus();

    const [geloot, schema] = await Promise.all([laadSlStatus(), laadSlTijdschema()]);
    if (!geloot.size) return;

    for (const groep of _slGroepen) {
        const afstanden    = await laadGroepAfstanden(groep);
        const cf           = Array.isArray(groep.category_filter) ? groep.category_filter : [];
        const splitSleutel = groep.is_split ? cf.join(',') : '';
        const cfKey        = cf.slice().sort().join('+');

        const verzamel = (distId, distNaam) => {
            const statusKey = `${groep.dc_id}||${distId ?? ''}||${splitSleutel}`;
            if (!geloot.has(statusKey)) return;

            const cacheKey = `${groep.dc_id}_${distId ?? ''}${cfKey ? '_' + cfKey : ''}`;
            const baseOpt  = {
                cacheKey, dcId: groep.dc_id,
                dcIds:  groep.dc_ids ?? [groep.dc_id],
                dcName: groep.dc_name,
                distId: distId ?? '', distNaam: distNaam ?? '',
                categoryFilter: cf,
            };

            // Bepaal flow direct vanuit tijdschema (schema is hier al geladen),
            // zodat de correcte eerste ronde wordt getoond ook als de cache nog
            // niet gevuld is (bijv. tab nog niet aangeklikt na page refresh).
            const catCfg = schema ? slVindCatCfg(schema, groep.dc_id, distId ?? '') : null;
            const flow   = catCfg
                ? bouwSlFlow(catCfg, schema?.systeem ?? null)
                : (startlijstCache[cacheKey]?.flow ?? [{ sleutel: 'heats', naam: 'Series' }]);
            // Ronde 1 is altijd beschikbaar als er een loting is
            const ronden = [{ label: SL_RONDE_LABEL[flow[0]?.sleutel] ?? flow[0]?.naam ?? 'Series',
                               sleutel: flow[0]?.sleutel ?? 'heats',
                               optData: { ...baseOpt, rondeSleutel: flow[0]?.sleutel ?? 'heats',
                                          rondeLabel: SL_RONDE_LABEL[flow[0]?.sleutel] ?? flow[0]?.naam ?? 'Series' } }];

            // Voeg volgende rondes toe als die al gegenereerd zijn (max_ronde > 1)
            const maxRonde     = (_slStatusCache?.rondeMap?.get(statusKey)) ?? 1;
            const isFullFinal  = (schema?.systeem === 'full-final');
            let ffFinalesAdded = false; // voorkom dubbele Finales-optie bij full-final

            for (let ri = 1; ri < flow.length; ri++) {
                const fr = flow[ri];
                // Ronde-nummers: heats=1, kwartfinale=2, halve_finale=3, finale=4
                const rondeNrMap = { heats:1, kwartfinale:2, halve_finale:3, finale:4, runner_up:4, finale_a:4, finale_b:4 };
                const frNr = rondeNrMap[fr.sleutel] ?? (ri + 1);
                if (frNr <= maxRonde) {
                    // Full-final: finale_a + finale_b samenvoegen tot één "Finales"-optie
                    if (isFullFinal && (fr.sleutel === 'finale_a' || fr.sleutel === 'finale_b')) {
                        if (!ffFinalesAdded) {
                            ronden.push({
                                label:   'Finales',
                                sleutel: 'full_final_finales',
                                optData: { ...baseOpt, rondeSleutel: 'full_final_finales',
                                           rondeLabel: 'Finales', rondeNr: 4 },
                            });
                            ffFinalesAdded = true;
                        }
                    } else {
                        ronden.push({
                            label:   SL_RONDE_LABEL[fr.sleutel] ?? fr.naam ?? fr.sleutel,
                            sleutel: fr.sleutel,
                            optData: { ...baseOpt, rondeSleutel: fr.sleutel,
                                       rondeLabel: SL_RONDE_LABEL[fr.sleutel] ?? fr.naam ?? fr.sleutel,
                                       rondeNr: frNr },
                        });
                    }
                }
            }

            // Niveau-1: displayNaam (merge_label als die bestaat, anders dc_name)
            const displayNaam = groep.merge_label || groep.dc_name;
            if (!_slPrintOpties.has(displayNaam))
                _slPrintOpties.set(displayNaam, new Map());
            // Niveau-2: distId
            const distMap = _slPrintOpties.get(displayNaam);
            if (!distMap.has(distId ?? ''))
                distMap.set(distId ?? '', { distNaam: distNaam ?? '', ronden });
            else
                // Meerdere rondes voor dezelfde afstand samenvoegen (toekomst)
                distMap.get(distId ?? '').ronden.push(...ronden.filter(
                    r => !distMap.get(distId ?? '').ronden.some(x => x.sleutel === r.sleutel)
                ));
        };

        if (afstanden.length)
            for (const af of afstanden)
                verzamel(af.id ?? '', af.name ?? String(af.value_meters ?? af.id ?? ''));
        else
            verzamel('', '');
    }

    // Eerste select (categorie) vullen
    for (const naam of _slPrintOpties.keys()) {
        const opt = document.createElement('option');
        opt.value = naam;
        opt.textContent = naam;
        catSel.appendChild(opt);
    }

    // Auto-select bij één categorie
    if (_slPrintOpties.size === 1) {
        catSel.selectedIndex = 1;
        catSel.dispatchEvent(new Event('change'));
    }
}

// ── Startlijst afdrukken ──────────────────────────────────────────────────────

async function drukStartlijstAf(optData) {
    const { cacheKey, dcIds, dcName, distId, distNaam, categoryFilter,
            rondeSleutel = 'heats', rondeLabel = 'Series' } = optData;

    // Data uit cache of vers van API laden
    let data = startlijstCache[cacheKey]?.resultaat;
    if (!data) {
        try {
            const cf  = Array.isArray(categoryFilter) ? categoryFilter : [];
            const url = `api/startlijst_laden.php`
                      + `?competition_id=${encodeURIComponent(huidigCompId)}`
                      + `&dc_ids=${encodeURIComponent((dcIds ?? [optData.dcId]).join(','))}`
                      + `&distance_id=${encodeURIComponent(distId ?? '')}`
                      + (cf.length ? `&category_filter=${encodeURIComponent(cf.join(','))}` : '');
            const res = await fetch(url);
            data = await res.json();
            if (!data?.exists) { toonBevestigDialog('Geen loting gevonden.', 'Laden'); return; }
        } catch (e) { toonBevestigDialog('Fout bij laden: ' + e.message, 'Fout'); return; }
    }

    // Rit-lookup voor rit-nummer badges (uit tijdschema)
    const schema = _slTsCache?.competition_id === huidigCompId ? _slTsCache.schema : null;
    const rl     = bouwRitLookup(schema, optData.dcId, distId, rondeSleutel);

    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const comp = huidigComp;
    const datum   = comp?.starts ? formatDatum(comp.starts) : '';
    const locatie = comp ? getLocatie(comp) : '';
    const metaTxt = [datum, locatie].filter(Boolean).join(' · ');
    const distLabel = distNaam ? ` – ${distNaam}` : '';

    // Seeding-methode label
    const METHODE_LABEL = {
        willekeurig:     'Willekeurig geloot',
        startnummer:     'Op startnummer',
        alfabetisch:     'Alfabetisch',
        klassement:      'Op klassement',
        tussenklassement:'Op tussenklassement',
    };
    // Bepaal de methode van de af te drukken ronde zelf
    // (niet van de series, want bijv. KF kan alfabetisch geloot zijn)
    const isSeries = !optData.rondeNr || optData.rondeNr <= 1 || rondeSleutel === 'heats';
    const rondeMethode = isSeries
        ? (data.methode ?? '')
        : ((data.volgende_rondes ?? []).find(vr => vr.ronde_type === rondeSleutel)?.methode ?? 'kwalificatie');

    let methodeLabel = METHODE_LABEL[rondeMethode] ?? '';
    if (rondeMethode === 'kwalificatie') {
        methodeLabel = 'Op kwalificatievolgorde';
    } else if (rondeMethode === 'klassement' && isSeries) {
        const cache = startlijstCache[cacheKey];
        if (cache?.klassementId) {
            const klLijst = await laadSlKlassementen();
            const kl      = klLijst.find(k => k.id === cache.klassementId);
            if (kl) {
                const orgNaam  = (typeof rkOrgs !== 'undefined')
                    ? (rkOrgs?.find?.(o => o.id === kl.org_id)?.naam ?? '')
                    : '';
                const klNaam   = kl.naam + (kl.seizoen ? ` (${kl.seizoen})` : '');
                const delen    = [orgNaam, klNaam, cache.klassementSectie].filter(Boolean);
                methodeLabel   = 'Op klassement: ' + delen.join(' · ');
            } else if (cache.klassementSectie) {
                methodeLabel += ': ' + cache.klassementSectie;
            }
        } else if (startlijstCache[cacheKey]?.klassementSectie) {
            methodeLabel += ': ' + startlijstCache[cacheKey].klassementSectie;
        }
    }

    // ── Bepaal de te printen heats ───────────────────────────────────────────────
    // Full-final "Finales": B-finales (Bn → B1) gevolgd door A-finale (altijd links)
    // Overig: series of één volgende ronde
    let afdrukHeats;           // [{ ...heat, _finaleType? }]
    let isFullFinalPrint = false;

    if (rondeSleutel === 'full_final_finales') {
        isFullFinalPrint = true;
        const bRonde = (data.volgende_rondes ?? []).find(vr => vr.ronde_type === 'finale_b');
        const aRonde = (data.volgende_rondes ?? []).find(vr => vr.ronde_type === 'finale_a');
        // B-finales: Bn eerst (traagste), B1 als laatste vóór de A-finale
        const bHeats = [...(bRonde?.heats ?? [])].sort((a, b) => b.nummer - a.nummer)
                          .map(h => ({ ...h, _finaleType: 'b' }));
        const aHeats = (aRonde?.heats ?? []).map(h => ({ ...h, _finaleType: 'a' }));
        afdrukHeats = [...bHeats, ...aHeats];
    } else {
        afdrukHeats = (!optData.rondeNr || optData.rondeNr <= 1 || rondeSleutel === 'heats')
            ? (data.heats ?? [])
            : ((data.volgende_rondes ?? []).find(vr => vr.ronde_type === rondeSleutel)?.heats ?? []);
    }

    // Rit-lookups voor badge-nummers (full-final heeft twee ronde-types)
    const rlB = isFullFinalPrint
        ? bouwRitLookup(schema, optData.dcId, distId, 'finale_b') : null;
    const rlA = isFullFinalPrint
        ? bouwRitLookup(schema, optData.dcId, distId, 'finale_a') : null;

    // Portrait als de grootste individuele heat meer dan 20 deelnemers heeft
    const maxHeatGrootte = afdrukHeats.reduce(
        (max, h) => Math.max(max, h.rijders?.length ?? 0), 0);
    const isPortrait = maxHeatGrootte > 20;
    const gridCols   = isPortrait ? 2 : 3;
    const pageSize   = isPortrait ? 'A4 portrait' : 'A4 landscape';

    // Helper: bouw één heat-card
    const maakCard = (heat, lookup, extraClass = '') => {
        const rit      = lookup?.[heat.nummer];
        const naam     = heat.heat_naam || rit?.rit_naam || `Heat ${heat.nummer}`;
        const volg     = heat.rit_volgorde ?? rit?.volgorde ?? null;
        const ritBadge = volg != null ? `<span class="pr-ritnr">${volg}</span>` : '';
        let rows = '';
        (heat.rijders ?? []).forEach((r, i) => {
            const opm = r.vorige_sancties ?? '';
            rows += `<tr>
                <td class="pr-pos">${i + 1}</td>
                <td class="pr-snr">${esc(r.start_number ?? '')}</td>
                <td class="pr-cat">${esc(r.categorie ?? r.category ?? '')}</td>
                <td class="pr-naam">${esc(r.full_name ?? '')}</td>
                <td class="pr-opm">${esc(opm)}</td>
            </tr>`;
        });
        return `<div class="pr-card${extraClass ? ' ' + extraClass : ''}">
            <div class="pr-titel">${ritBadge}${esc(naam)}<span class="pr-count">${heat.rijders?.length ?? 0}</span></div>
            <table class="pr-tabel">
                <colgroup>
                    <col class="pr-col-pos"><col class="pr-col-snr"><col class="pr-col-cat">
                    <col class="pr-col-naam"><col class="pr-col-opm">
                </colgroup>
                <thead><tr><th>#</th><th>Snr</th><th>Cat</th><th>Naam</th><th>Opm.</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    };

    let cardsHtml = '';
    if (isFullFinalPrint) {
        // B-finales sectie
        const bHeats = afdrukHeats.filter(h => h._finaleType === 'b');
        const aHeats = afdrukHeats.filter(h => h._finaleType === 'a');
        if (bHeats.length) {
            cardsHtml += `<div class="pr-sectie-kop pr-sectie-b">B-Finales</div>`;
            for (const heat of bHeats)
                cardsHtml += maakCard(heat, rlB);
        }
        if (aHeats.length) {
            // A-finale sectie: kop spant alle kolommen; eerste kaart altijd aan de linkerkantlijn
            cardsHtml += `<div class="pr-sectie-kop pr-sectie-a">A-Finale</div>`;
            let first = true;
            for (const heat of aHeats) {
                cardsHtml += maakCard(heat, rlA, first ? 'pr-card-links' : '');
                first = false;
            }
        }
    } else {
        for (const heat of afdrukHeats)
            cardsHtml += maakCard(heat, rl);
    }

    const htmlDoc = `<!DOCTYPE html><html lang="nl">
<head><meta charset="UTF-8">
<title>Startlijst – ${esc(dcName)}${esc(distLabel)}</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;margin:.6cm 1cm;color:#111;line-height:1.35}
.pr-header{display:flex;justify-content:space-between;align-items:flex-end;
           border-bottom:2px solid #1a3a5c;padding-bottom:.3cm;margin-bottom:.4cm}
.pr-comp{font-size:13pt;font-weight:700}
.pr-meta{font-size:8.5pt;color:#000;margin-top:1mm}
.pr-ronde{font-size:10pt;font-weight:700;color:#000}
.pr-methode{font-size:8pt;color:#000;margin-top:1mm;font-style:italic}
.pr-grid{display:grid;grid-template-columns:repeat(${gridCols},1fr);gap:.5cm}
.pr-card{border:1px solid #bbb;border-radius:5px;overflow:hidden;break-inside:avoid}
.pr-titel{background:#1a3a5c;color:#fff;padding:5px 8px;font-weight:700;font-size:8.5pt;
          display:flex;align-items:center;gap:.35cm}
.pr-ritnr{background:rgba(0,0,0,.3);border-radius:3px;padding:1px 5px;font-size:7.5pt;
          font-weight:700;flex-shrink:0}
.pr-count{margin-left:auto;background:rgba(255,255,255,.2);border-radius:8px;
          padding:0 5px;font-size:7.5pt;font-weight:400}
.pr-tabel{width:100%;border-collapse:collapse;font-size:9.5pt;table-layout:fixed}
.pr-tabel th{background:#dce6f0;color:#1a3a5c;padding:2px 4px;font-size:7.5pt;
             text-align:left;font-weight:600;border-bottom:1px solid #bbb}
.pr-tabel td{padding:3px 4px;border-bottom:1px solid #eee}
.pr-tabel tr:last-child td{border-bottom:none}
col.pr-col-pos{width:16px}
col.pr-col-snr{width:28px}
col.pr-col-cat{width:26px}
col.pr-col-naam{}
col.pr-col-opm{width:60px}
.pr-pos{color:#aaa;text-align:center;font-size:7.5pt}
.pr-snr{text-align:right;font-weight:600;color:#1a3a5c}
.pr-cat{font-size:7.5pt;color:#666}
.pr-naam{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.pr-opm{border-left:1px solid #ddd!important}
/* Full-final: sectie-koppen (B-Finales / A-Finale) */
.pr-sectie-kop{grid-column:1/-1;font-weight:700;font-size:9pt;letter-spacing:.03em;
               padding:3px 0 2px;margin-top:.3cm;border-bottom:2px solid currentColor}
.pr-sectie-b{color:#20c997}
.pr-sectie-a{color:#198754}
/* A-finale altijd aan de linkerkantlijn (grid-kolom 1) */
.pr-card-links{grid-column-start:1}
@page{size:${pageSize};margin:.8cm 1cm}
@media print{
  body{margin:.5cm .8cm}
  .pr-card{break-inside:avoid}
  .pr-titel{background:#e8ecf0!important;color:#000!important;border-bottom:2px solid #000}
  .pr-ritnr{background:#000!important;color:#fff!important}
  .pr-count{background:#ccc!important;color:#000!important;font-weight:700}
  .pr-tabel th{background:#eee!important;color:#000!important}
}
</style></head>
<body>
<div class="pr-header">
  <div>
    <div class="pr-comp">${esc(comp?.name ?? '')}</div>
    <div class="pr-meta">${esc(metaTxt)}</div>
  </div>
  <div style="text-align:right">
    <div class="pr-ronde">${esc(dcName)}${esc(distLabel)}&nbsp;–&nbsp;${esc(rondeLabel)}</div>
    ${methodeLabel ? `<div class="pr-methode">${esc(methodeLabel)}</div>` : ''}
  </div>
</div>
<div class="pr-grid">${cardsHtml}</div>
</body></html>`;

    const win = window.open('', '_blank');
    if (!win) { toonBevestigDialog('Pop-up geblokkeerd — sta pop-ups toe voor deze pagina.', 'Afdrukken'); return; }
    win.document.write(htmlDoc);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 400);
}

// ── Startlijst pagina tonen ───────────────────────────────────────────────────

function toonStartlijstenPagina() {
    _slLeesOnly = !magSchrijven('startlijsten');
    const header  = el('sl-page-header');
    const catTabs = el('sl-cat-tabs');
    const content = el('sl-cat-content');

    const distTabs = el('sl-dist-tabs');

    if (!huidigCompId || !isGeimporteerd) {
        catTabs.innerHTML  = '';
        distTabs.innerHTML = '';
        distTabs.style.display = 'none';
        content.innerHTML  = `<div class="status-msg info">
            Selecteer en importeer eerst een wedstrijd via <strong>Importeer</strong>.
        </div>`;
        if (header) header.textContent = '';
        return;
    }

    if (header) header.innerHTML = `
        <div class="ts-top">
            <div>
                <div class="ts-comp-naam">${escHtml(huidigComp?.name || '')}</div>
                <div class="ts-comp-meta">${escHtml(formatDatum(huidigComp?.starts || ''))} · ${escHtml(getLocatie(huidigComp || {}))}</div>
            </div>
            <div class="sl-print-bar">
                <select id="sl-print-cat-sel" class="inp sl-inp sl-print-sel">
                    <option value="">— Categorie —</option>
                </select>
                <select id="sl-print-dist-sel" class="inp sl-inp sl-print-sel" disabled>
                    <option value="">— Afstand —</option>
                </select>
                <select id="sl-print-ronde-sel" class="inp sl-inp sl-print-sel" disabled>
                    <option value="">— Ronde —</option>
                </select>
                <button id="sl-btn-print" class="btn-secondary" disabled>🖨 Druk af</button>
            </div>
        </div>`;

    const groepen = bouwStartlijstGroepen();
    _slGroepen = groepen;

    catTabs.innerHTML = '';
    groepen.forEach((groep, i) => {
        const btn = document.createElement('button');
        btn.className = 'org-tab-btn' + (i === 0 ? ' active' : '');
        const totaal  = groep.competitors.length;
        const label   = groep.dc_ids.length > 1
            ? `${escHtml(groep.dc_name)} <span class="tab-badge-merged" title="Samengevoegde categorieën">${groep.dc_ids.length}</span>`
            : escHtml(groep.dc_name);
        btn.innerHTML = label + ` (${totaal})`;
        btn.addEventListener('click', () => {
            catTabs.querySelectorAll('.org-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeCat = groep;
            toonStartlijstConfig(groep);
        });
        catTabs.appendChild(btn);
    });

    activeCat = groepen[0];
    toonStartlijstConfig(groepen[0]);

    // Tab-kleuren + print-select in achtergrond bepalen (niet-blokkerend)
    kleurAlleTabsAsync(groepen, catTabs);
    vulPrintSelect();

    // Categorie → vul afstanden
    el('sl-print-cat-sel')?.addEventListener('change', () => {
        const catSel   = el('sl-print-cat-sel');
        const distSel  = el('sl-print-dist-sel');
        const rondeSel = el('sl-print-ronde-sel');
        const btn      = el('sl-btn-print');
        const distMap  = _slPrintOpties.get(catSel.value);
        distSel.innerHTML  = '<option value="">— Afstand —</option>';
        rondeSel.innerHTML = '<option value="">— Ronde —</option>';
        rondeSel.disabled  = true;
        if (btn) btn.disabled = true;
        if (!distMap?.size) { distSel.disabled = true; return; }
        distMap.forEach(({ distNaam }, distId) => {
            const opt = document.createElement('option');
            opt.value = distId;
            opt.textContent = distNaam || '—';
            distSel.appendChild(opt);
        });
        distSel.disabled = false;
        if (distMap.size === 1) {
            distSel.selectedIndex = 1;
            distSel.dispatchEvent(new Event('change'));
        }
    });

    // Afstand → vul rondes
    el('sl-print-dist-sel')?.addEventListener('change', () => {
        const catSel   = el('sl-print-cat-sel');
        const distSel  = el('sl-print-dist-sel');
        const rondeSel = el('sl-print-ronde-sel');
        const btn      = el('sl-btn-print');
        const ronden   = _slPrintOpties.get(catSel.value)?.get(distSel.value)?.ronden ?? [];
        rondeSel.innerHTML = '<option value="">— Ronde —</option>';
        if (btn) btn.disabled = true;
        if (!ronden.length) { rondeSel.disabled = true; return; }
        ronden.forEach(r => {
            const opt = document.createElement('option');
            opt.value = JSON.stringify(r.optData);
            opt.textContent = r.label;
            rondeSel.appendChild(opt);
        });
        rondeSel.disabled = false;
        if (ronden.length === 1) {
            rondeSel.selectedIndex = 1;
            if (btn) btn.disabled = false;
        }
    });

    // Ronde → activeer print-knop (alleen als vorige ronde compleet)
    el('sl-print-ronde-sel')?.addEventListener('change', async () => {
        const btn      = el('sl-btn-print');
        const rondeSel = el('sl-print-ronde-sel');
        if (!rondeSel.value) { if (btn) btn.disabled = true; return; }

        const optData = JSON.parse(rondeSel.value);

        // Ronde 1 (series): altijd printbaar
        if (!optData.rondeNr || optData.rondeNr <= 1) {
            if (btn) btn.disabled = false;
            // Verwijder eventuele waarschuwing
            rondeSel.closest('.sl-print-bar')?.querySelector('.sl-print-waarschuwing')?.remove();
            return;
        }

        // Volgende ronde: controleer of vorige ronde compleet is via cache of API
        if (btn) btn.disabled = true;
        const cf      = Array.isArray(optData.categoryFilter) ? optData.categoryFilter : [];
        const laadUrl = `api/startlijst_laden.php`
                      + `?competition_id=${encodeURIComponent(huidigCompId)}`
                      + `&dc_ids=${encodeURIComponent((optData.dcIds ?? [optData.dcId]).join(','))}`
                      + `&distance_id=${encodeURIComponent(optData.distId ?? '')}`
                      + (cf.length ? `&category_filter=${encodeURIComponent(cf.join(','))}` : '');

        try {
            const res  = await fetch(laadUrl);
            const data = await res.json();
            const bar  = rondeSel.closest('.sl-print-bar');
            bar?.querySelector('.sl-print-waarschuwing')?.remove();

            // Zoek de bijbehorende volgende ronde in de data.
            // Full-final "Finales" is geen echte ronde_type: check beide A- en B-finale.
            let compleet;
            if (optData.rondeSleutel === 'full_final_finales') {
                const vrA = (data.volgende_rondes ?? []).find(r => r.ronde_type === 'finale_a');
                const vrB = (data.volgende_rondes ?? []).find(r => r.ronde_type === 'finale_b');
                // Printbaar als minstens één finale gegenereerd is;
                // "vorige ronde compleet" geldt voor beide want het is dezelfde bronronde.
                const heeftFinales = !!(vrA?.heats?.length || vrB?.heats?.length);
                compleet = heeftFinales &&
                    !!(vrA?.vorige_ronde_compleet || vrB?.vorige_ronde_compleet);
                // Als de finales wél gegenereerd zijn maar de series nog niet formeel
                // compleet, toon dan een zachte waarschuwing maar blokkeer niet.
                if (heeftFinales && !compleet) {
                    if (bar) {
                        const warn = document.createElement('span');
                        warn.className = 'sl-print-waarschuwing';
                        warn.textContent = '⚠ Voorlopige indeling – nog niet alle serieresultaten ingevoerd';
                        bar.appendChild(warn);
                    }
                    if (btn) btn.disabled = false; // toch printbaar
                    compleet = true; // skip dubbele waarschuwing hieronder
                } else if (!heeftFinales) {
                    compleet = false; // geen finales gegenereerd → blokkeren
                }
            } else {
                const vr = (data.volgende_rondes ?? []).find(r => r.ronde_type === optData.rondeSleutel);
                compleet = vr ? vr.vorige_ronde_compleet : false;
            }

            if (compleet) {
                if (btn) btn.disabled = false;
            } else {
                // Toon waarschuwing, knop blijft geblokkeerd
                if (bar) {
                    const warn = document.createElement('span');
                    warn.className = 'sl-print-waarschuwing';
                    warn.textContent = '⏳ Niet alle resultaten van de vorige ronde zijn ingevoerd';
                    bar.appendChild(warn);
                }
            }
        } catch {
            if (btn) btn.disabled = false; // bij fout: toch toestaan
        }
    });

    el('sl-btn-print')?.addEventListener('click', () => {
        const val = el('sl-print-ronde-sel')?.value;
        if (!val) return;
        drukStartlijstAf(JSON.parse(val));
    });
}

// ── Startlijst – configuratie tonen (per afstand) ────────────────────────────

async function toonStartlijstConfig(groep) {
    const content  = el('sl-cat-content');
    const distTabs = el('sl-dist-tabs');

    content.innerHTML  = '<div class="status-msg loading"><span class="spinner"></span>Afstanden laden…</div>';
    distTabs.innerHTML = '';
    distTabs.style.display = 'none';

    let afstanden;
    try {
        // Gebruik afstandencache (ook nuttig voor kleurAlleTabsAsync)
        afstanden = await laadGroepAfstanden(groep);
        if (afstanden.error) throw new Error(afstanden.error);
    } catch(e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
        return;
    }

    // Info-label: samengevoegde of gesplitste groep (boven de heat-config)
    const infoLabelHtml = groep.dc_ids?.length > 1
        ? `<div class="sl-merge-label">&#8644; Samengevoegd: <strong>${escHtml(groep.dc_name)}</strong></div>`
        : groep.is_split
            ? `<div class="sl-merge-label">&#9986; Gesplitst uit: <strong>${escHtml(
                  vergelijkData.find(c => c.dc_id === groep.dc_id)?.dc_name || groep.dc_id
              )}</strong></div>`
            : '';

    // Geen afstanden → direct naar heat-config, met melding
    if (!afstanden.length) {
        content.innerHTML = `
            ${infoLabelHtml}
            <div class="sl-no-dist-info">
                &#8505; Geen afstanden bekend — voeg ze toe via <strong>Importeer</strong>.
                Startlijst wordt gegenereerd voor alle bevestigde deelnemers.
            </div>
            <div id="sl-dist-content"></div>`;
        toonAfstandConfig(groep, '_geen_', 'Alle deelnemers');
        return;
    }

    // ── Rij 2: afstand-tabs in de vaste sl-dist-tabs balk ────────────────────
    afstanden.forEach((a, i) => {
        const btn = document.createElement('button');
        btn.className = 'org-tab-btn sl-dist-tab' + (i === 0 ? ' active' : '');
        btn.dataset.distId   = a.id;
        btn.dataset.distNaam = a.name;
        btn.textContent      = a.name;
        btn.addEventListener('click', () => {
            distTabs.querySelectorAll('.sl-dist-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            toonAfstandConfig(groep, a.id, a.name);
        });
        distTabs.appendChild(btn);
    });
    distTabs.style.display = '';

    // ── Kleur dist-tabs direct op basis van loting-status ────────────────────
    const dcId       = groep.dc_id;
    const splitGroup = groep.is_split
        ? (Array.isArray(groep.category_filter) ? groep.category_filter.join(',') : groep.dc_name)
        : '';
    laadSlStatus().then(geloot => {
        distTabs.querySelectorAll('.sl-dist-tab').forEach(btn => {
            const key = `${dcId}||${btn.dataset.distId ?? ''}||${splitGroup}`;
            btn.classList.toggle('tab-gereed', geloot.has(key));
        });
    }).catch(() => {});

    // ── Inhoud: only sl-dist-content placeholder + optionele info-label ──────
    content.innerHTML = `${infoLabelHtml}<div id="sl-dist-content"></div>`;

    toonAfstandConfig(groep, afstanden[0].id, afstanden[0].name);
}

// ── Startlijst – client-side slangenpatroon ──────────────────────────────────

function slangenpatroon(rijders, maxPerHeat) {
    const n = rijders.length;
    if (!n) return [];
    const aantalHeats = Math.ceil(n / maxPerHeat);
    const basis  = Math.floor(n / aantalHeats);
    const extras = n % aantalHeats;
    const heats  = Array.from({length: aantalHeats}, (_, i) => ({
        nummer:     i + 1,
        capaciteit: i < extras ? basis + 1 : basis,
        rijders:    [],
    }));
    let ri = 0;
    while (ri < n) {
        for (let h = 0; h < aantalHeats && ri < n; h++)
            if (heats[h].rijders.length < heats[h].capaciteit) heats[h].rijders.push(rijders[ri++]);
        if (ri >= n) break;
        for (let h = aantalHeats - 1; h >= 0 && ri < n; h--)
            if (heats[h].rijders.length < heats[h].capaciteit) heats[h].rijders.push(rijders[ri++]);
    }
    return heats;
}

// ── Tussenklassement preview ──────────────────────────────────────────────────
// Haalt de actuele tussenklassement-ranking op en toont de heat-indeling.
// Als er al uitslag-data is worden echte namen getoond; anders generieke slots.

async function vulTussenklPreview(container, nRijders, nHeats, schema, groep, distId, flow) {
    container.innerHTML = '<span class="sl-tk-laden">⏳ Tussenstand laden…</span>';
    if (!nRijders || !nHeats) { container.innerHTML = ''; return; }
    nHeats = Math.min(nHeats, nRijders);

    const ritLookup = bouwRitLookup(schema, groep?.dc_id, distId, flow?.[0]?.sleutel ?? 'heats');

    // Probeer werkelijke tussenklassement te laden
    let ranking = [];
    let afstanden = [];
    try {
        const dcId = groep?.dc_id ?? '';
        let url = `api/tussenklassement.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_id=${encodeURIComponent(dcId)}`;
        if (distId) url += `&distance_id=${encodeURIComponent(distId)}`;
        const res  = await fetch(url);
        const data = await res.json();
        if (data.heeft_data && data.ranking?.length) {
            ranking   = data.ranking;
            afstanden = data.afstanden ?? [];
        }
    } catch { /* geen data → generieke preview */ }

    container.innerHTML = '';

    if (ranking.length) {
        // Toon welke afstanden meegeteld zijn
        const info = document.createElement('div');
        info.className = 'sl-tk-afstanden';
        info.textContent = `Gebaseerd op: ${afstanden.join(', ')}`;
        container.appendChild(info);

        // Bouw slots met rijdersnamen (slangenpatroon)
        const ranked   = ranking.map(r => r.full_name);
        const ongerank = [];  // rijders zonder uitslag → achteraan (onbekend hier)
        const slots    = ranked;
        const heats    = nHeats === 1 ? [{ nummer: 1, slots }] : snakeVerdeelSlots(slots, nHeats);
        container.appendChild(maakSchemaHeatGrid(heats, ritLookup));
    } else {
        // Geen data: generieke slots
        const info = document.createElement('div');
        info.className = 'sl-tk-afstanden sl-tk-geen-data';
        info.textContent = 'Nog geen uitslagen beschikbaar voor deze DC. Indeling gebaseerd op tussenstand zodra resultaten zijn ingevoerd.';
        container.appendChild(info);

        const slots = Array.from({ length: nRijders }, (_, i) => `${i + 1}e tussenklassement`);
        const heats = nHeats === 1 ? [{ nummer: 1, slots }] : snakeVerdeelSlots(slots, nHeats);
        container.appendChild(maakSchemaHeatGrid(heats, ritLookup));
    }
}

// ── Startlijst – configuratie per afstand ────────────────────────────────────

async function toonAfstandConfig(groep, distId, distNaam) {
    // CacheKey: dc_id + distance + eventuele category_filter (voor splits)
    const cfArr    = Array.isArray(groep.category_filter) ? groep.category_filter : [];
    const cfKey    = cfArr.slice().sort().join('+');
    const cacheKey = `${groep.dc_id}_${distId}${cfKey ? '_' + cfKey : ''}`;

    if (!startlijstCache[cacheKey]) {
        startlijstCache[cacheKey] = {
            methode:          'startnummer',
            heatsAantal:      1,
            klassementId:     null,
            klassementSectie: null,
            resultaat:        null,
            flow:             null,
        };
    }
    const cache = startlijstCache[cacheKey];
    cache.aantalRijders = groep.competitors.length;
    cache._groep    = groep;
    cache._distId   = distId;
    cache._distNaam = distNaam;

    const slDist = el('sl-dist-content');
    if (!slDist) { console.error('sl-dist-content niet gevonden'); return; }
    slDist.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';

    try {
    // Tijdschema ophalen voor ronde-flow
    const schema = await laadSlTijdschema();
    if (!slDist.isConnected) return;

    // Geen tijdschema → melding
    if (!schema) {
        slDist.innerHTML =
            `<div class="status-msg warn">
                ⚠ Genereer eerst het tijdschema voordat je een startlijst kunt maken.
            </div>`;
        return;
    }

    const catCfg      = slVindCatCfg(schema, groep.dc_id, distId);
    const flow        = bouwSlFlow(catCfg, schema?.systeem ?? null);
    cache.flow        = flow;
    const _eersteSleutel = flow[0]?.sleutel;
    // Gebruik het werkelijke aantal ritten uit het tijdschema als die er zijn,
    // zodat startlijst het gegenereerde programma volgt (i.p.v. catCfg.heats_aantal
    // dat out-of-sync kan zijn als het programma niet is hergenereert na een wijziging).
    const _eersteRitLookup = bouwRitLookup(schema, groep.dc_id, distId, _eersteSleutel ?? 'heats');
    const _eersteRitCount  = Object.keys(_eersteRitLookup).length;
    cache.heatsAantal = _eersteSleutel === 'halve_finale' ? (parseInt(catCfg?.half_heats   ?? 1) || 1)
                      : _eersteSleutel === 'kwartfinale'  ? (parseInt(catCfg?.kwart_heats  ?? 1) || 1)
                      : _eersteRitCount > 0               ? _eersteRitCount
                      :                                     (parseInt(catCfg?.heats_aantal  ?? 1) || 1);
    cache._catCfg     = catCfg;
    cache._afstandCfg = (schema?.afstand_configs ?? []).find(ac => ac.afstand_naam === distNaam) ?? null;
    cache._systeem    = schema?.systeem ?? null;

    // ── Check bestaande loting in DB ─────────────────────────────────────────
    const dcIds    = (groep.dc_ids || [groep.dc_id]).join(',');
    const cf       = Array.isArray(groep.category_filter) ? groep.category_filter : [];
    const laadUrl  = `api/startlijst_laden.php`
                   + `?competition_id=${encodeURIComponent(huidigCompId)}`
                   + `&dc_ids=${encodeURIComponent(dcIds)}`
                   + `&distance_id=${encodeURIComponent(distId ?? '')}`
                   + (cf.length ? `&category_filter=${encodeURIComponent(cf.join(','))}` : '')
                   + `&_t=${Date.now()}`;

    const laadRes  = await fetch(laadUrl, { cache: 'no-store' });
    if (!slDist.isConnected) return;
    const laadData = await laadRes.json();

    if (laadData.exists) {
        // Bestaande loting tonen (vergrendeld)
        cache.resultaat = laadData;
        cache.methode   = laadData.methode;
        zetDistTabKleur(distId, true);
        // Herkleur alle cat-tabs (gecached, snel na eerste keer)
        kleurAlleTabsAsync(_slGroepen, el('sl-cat-tabs'));
        toonSlResultaten(cacheKey, true);
        return;
    }
    zetDistTabKleur(distId, false);
    kleurAlleTabsAsync(_slGroepen, el('sl-cat-tabs'));

    const eersteRonde = flow[0];
    const methode     = cache.methode || 'startnummer';

    // ── Flow-pills HTML (vermijd triple-geneste backticks) ─────────────────
    let flowHtml = '';
    if (flow.length > 1) {
        const stappen = flow.map((r, i) => {
            const stijl = i === 0
                ? ` style="border-color:${r.kleur};color:${r.kleur}"`
                : '';
            const pijl  = i < flow.length - 1 ? '<span class="sl-flow-pijl">→</span>' : '';
            return `<span class="sl-flow-stap${i === 0 ? ' sl-flow-actief' : ''}"${stijl}>${escHtml(r.naam)}</span>${pijl}`;
        }).join('');
        flowHtml = `<div class="sl-flow"><span class="sl-flow-lbl">Programmaflow:</span>
            <div class="sl-flow-stappen">${stappen}</div></div>`;
    }

    // ── Render ───────────────────────────────────────────────────────────────
    slDist.innerHTML =
        flowHtml +
        `<div class="sl-vooraf">
            <div class="sl-seeding-lbl">Seeding <strong>${escHtml(eersteRonde.naam)}</strong></div>
            <div class="sl-meth-knoppen">
                <button class="sl-meth-btn${methode === 'startnummer' ? ' actief' : ''}" data-methode="startnummer">
                    🔢 Op startnummer
                </button>
                <button class="sl-meth-btn${methode === 'alfabetisch' ? ' actief' : ''}" data-methode="alfabetisch">
                    🔤 Alfabetisch
                </button>
                <button class="sl-meth-btn${methode === 'klassement' ? ' actief' : ''}" data-methode="klassement">
                    🏆 Op klassement
                </button>
                <button class="sl-meth-btn${methode === 'tussenklassement' ? ' actief' : ''}" data-methode="tussenklassement">
                    🏁 Op tussenklassement
                </button>
            </div>
            <div class="sl-klassement-kiezer" id="sl-kl-kiezer"
                 style="${methode === 'klassement' ? '' : 'display:none'}">
                <select class="inp sl-inp" id="sl-kl-sel-kl">
                    <option value="">— kies klassement —</option>
                </select>
                <select class="inp sl-inp" id="sl-kl-sel-sec" ${cache.klassementId ? '' : 'disabled'}>
                    <option value="">— kies sectie —</option>
                </select>
            </div>
            <div class="sl-tussenkl-kiezer" id="sl-tk-kiezer"
                 style="${methode === 'tussenklassement' ? '' : 'display:none'}">
                <div class="sl-tk-uitleg">
                    Heats worden gevuld op volgorde van het tussenklassement (slangenpatroon).<br>
                    Onderstaande indeling is een voorbereiding — genereer zodra de resultaten beschikbaar zijn.
                </div>
                <div id="sl-tk-preview"></div>
            </div>
            <div class="sl-max-heat-rij">
                Aantal heats (tijdschema):
                <strong>${cache.heatsAantal}</strong>
                <span class="sl-flow-ph-info">${groep.competitors.length} deelnemers</span>
            </div>
            <button id="sl-genereer" class="btn-genereer">&#9654; Genereer ${escHtml(eersteRonde.naam)}</button>
        </div>
        <div id="sl-resultaten"></div>`;

    // ── Tussenklassement preview direct vullen indien al geselecteerd ─────────
    if (methode === 'tussenklassement')
        await vulTussenklPreview(el('sl-tk-preview'), groep.competitors.length, cache.heatsAantal, schema, groep, distId, flow);

    // ── Methode knoppen ───────────────────────────────────────────────────────
    slDist.querySelectorAll('.sl-meth-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            cache.methode = btn.dataset.methode;
            slDist.querySelectorAll('.sl-meth-btn').forEach(b => b.classList.remove('actief'));
            btn.classList.add('actief');
            el('sl-kl-kiezer').style.display = cache.methode === 'klassement'       ? '' : 'none';
            el('sl-tk-kiezer').style.display  = cache.methode === 'tussenklassement' ? '' : 'none';
            if (cache.methode === 'tussenklassement')
                await vulTussenklPreview(el('sl-tk-preview'), groep.competitors.length, cache.heatsAantal, schema, groep, distId, flow);
        });
    });

    // ── Klassement dropdown ───────────────────────────────────────────────────
    const klSelKl  = el('sl-kl-sel-kl');
    const klSelSec = el('sl-kl-sel-sec');

    laadSlKlassementen().then(lijst => {
        const orgId = huidigOrganisatie?.id ?? null;
        let geselecteerdId = cache.klassementId;
        if (!geselecteerdId && orgId) {
            const suggestie = lijst.find(k => k.org_id === orgId);
            if (suggestie) geselecteerdId = suggestie.id;
        }

        klSelKl.innerHTML = '<option value="">— kies klassement —</option>'
            + lijst.map(k => {
                const orgNaam = k.org_id
                    ? (typeof rkOrgs !== 'undefined' ? (rkOrgs?.find?.(o => o.id === k.org_id)?.naam ?? '') : '')
                    : '';
                const label = k.naam
                    + (k.seizoen  ? ` (${k.seizoen})` : '')
                    + (orgNaam    ? ` · ${orgNaam}`   : '');
                return `<option value="${escHtml(k.id)}" ${geselecteerdId === k.id ? 'selected' : ''}>${escHtml(label)}</option>`;
            }).join('');

        const laadSecties = (id) => {
            const ges  = lijst.find(k => k.id === id);
            const cats = ges?.categorieen ?? [];
            if (cats.length) {
                klSelSec.innerHTML = cats.map(s =>
                    `<option value="${escHtml(s.label)}" ${cache.klassementSectie === s.label ? 'selected' : ''}>${escHtml(s.label)}</option>`
                ).join('');
                klSelSec.disabled = false;
                if (!cache.klassementSectie) cache.klassementSectie = cats[0].label;
            } else {
                klSelSec.innerHTML = '<option value="">— kies sectie —</option>';
                klSelSec.disabled = true;
            }
        };

        if (geselecteerdId) {
            cache.klassementId = geselecteerdId;
            laadSecties(geselecteerdId);
        }

        klSelKl.addEventListener('change', () => {
            cache.klassementId     = klSelKl.value || null;
            cache.klassementSectie = null;
            laadSecties(klSelKl.value);
        });
        klSelSec.addEventListener('change', () => {
            cache.klassementSectie = klSelSec.value || null;
        });
    });

    // ── Genereer knop ─────────────────────────────────────────────────────────
    el('sl-genereer').addEventListener('click', () => genereerRonde1(cacheKey));

    // ── Cached resultaat direct tonen ─────────────────────────────────────────
    if (cache.resultaat) toonSlResultaten(cacheKey);

    if (_slLeesOnly) { toonLeesAlleenBanner(slDist); pasSchrijfLockToe(slDist); }

    } catch(err) {
        console.error('toonAfstandConfig fout:', err);
        const d = el('sl-dist-content');
        if (d) d.innerHTML = `<div class="status-msg error">⚠ ${escHtml(err.message)}</div>`;
    }
}


// ── Startlijst – ronde 1 genereren ────────────────────────────────────────────

async function genereerRonde1(cacheKey) {
    const cache     = startlijstCache[cacheKey];
    const resultDiv = el('sl-resultaten');
    if (!resultDiv) return;
    resultDiv.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Genereren…</div>';

    const groep  = cache._groep;
    const distId = cache._distId;

    try {
        const dcIds = (groep.dc_ids || [groep.dc_id]).join(',');
        const eersteRondeType = cache.flow?.[0]?.sleutel ?? 'heats';
        let url = `api/startlijst_genereer.php`
                + `?competition_id=${encodeURIComponent(huidigCompId)}`
                + `&dc_ids=${encodeURIComponent(dcIds)}`
                + `&distance_id=${encodeURIComponent(distId ?? '')}`
                + `&heats_aantal=${cache.heatsAantal || 1}`
                + `&methode=${encodeURIComponent(cache.methode || 'startnummer')}`
                + `&ronde_type=${encodeURIComponent(eersteRondeType)}`;
        const cf = Array.isArray(groep.category_filter) ? groep.category_filter : [];
        if (cf.length)
            url += `&category_filter=${encodeURIComponent(cf.join(','))}`;
        if (cache.methode === 'klassement' && cache.klassementId && cache.klassementSectie)
            url += `&klassement_id=${encodeURIComponent(cache.klassementId)}`
                 + `&klassement_sectie=${encodeURIComponent(cache.klassementSectie)}`;

        // Stuur rit_namen mee vanuit tijdschema (voor heat_naam in DB)
        const schema     = _slTsCache?.competition_id === huidigCompId ? _slTsCache.schema : null;
        const ritLookup  = bouwRitLookup(schema, groep.dc_id, distId, cache.flow?.[0]?.sleutel ?? 'heats');
        const heatNamen  = {};
        for (const [nr, rit] of Object.entries(ritLookup)) heatNamen[nr] = rit.rit_naam;
        if (Object.keys(heatNamen).length)
            url += `&heat_namen=${encodeURIComponent(JSON.stringify(heatNamen))}`;

        const res  = await fetch(url);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        cache.resultaat = data;
        // Zet het werkelijke ronde_type zodat het deelnemerspaneel de juiste kolom activeert
        cache.resultaat.ronde_1_ronde_type = cache.flow?.[0]?.sleutel ?? 'heats';
        // Na opslaan: toon vergrendelde weergave + refresh tab-kleuren + print-select
        invalideerSlStatus();
        zetDistTabKleur(distId, true);
        kleurAlleTabsAsync(_slGroepen, el('sl-cat-tabs'));
        vulPrintSelect();
        toonSlResultaten(cacheKey, true);

    } catch(e) {
        resultDiv.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}


// ── Schema-helpers: bracket-slots voor vervolgronden ─────────────────────────

// Genereer geordende slot-labels met Q/q scheiding.
// Q-slots (positiewinnaars) gaan altijd voor q-slots (tijdsnelsten).
// qPerHeat = aantal Q per heat (winnaar/2e/...), 0 = alles op tijd.
function bouwSchemaSlots(prevNaam, prevNHeats, nSlots, qPerHeat) {
    const nQ  = Math.min(nSlots, (qPerHeat ?? 1) * prevNHeats);
    const nq  = Math.max(0, nSlots - nQ);
    const slots = [];

    // Q-slots: "Winnaar X" (1e) of "2e/3e X" (hogere rangen)
    for (let rank = 1; slots.length < nQ; rank++) {
        for (let h = 1; h <= prevNHeats && slots.length < nQ; h++) {
            slots.push(rank === 1 ? `Winnaar ${prevNaam} ${h}` : `${rank}e ${prevNaam} ${h}`);
        }
    }
    // q-slots: "1e tijdsnelste", "2e tijdsnelste", ...
    for (let t = 1; t <= nq; t++) {
        slots.push(`${t}e tijdsnelste`);
    }
    return slots;
}

// Verdeel slot-labels over heats via slangenpatroon
function snakeVerdeelSlots(slots, nHeats) {
    const heats = Array.from({ length: nHeats }, (_, i) => ({ nummer: i + 1, slots: [] }));
    let i = 0;
    while (i < slots.length) {
        for (let h = 0; h < nHeats && i < slots.length; h++)
            heats[h].slots.push(slots[i++]);
        if (i >= slots.length) break;
        for (let h = nHeats - 1; h >= 0 && i < slots.length; h--)
            heats[h].slots.push(slots[i++]);
    }
    return heats;
}

// Full-Final B-finale verdeling (JavaScript-versie van verdeelBFinales in live.php)
// Zelfde regels:
//   · Alle heats krijgen max bFinaleHg rijders.
//   · Laatste heat die te klein zou worden (<bFinaleHg) wordt samengevoegd met aangrenzende.
//   · bLaatstGrootst=true  → laatste B-finale is de grootste.
//   · bLaatstGrootst=false → eerste B-finale is de grootste.
function verdeelBFinalesJS(n, bFinaleHg, bLaatstGrootst) {
    if (n <= 0 || bFinaleHg <= 0) return [];
    let nHeats = Math.ceil(n / bFinaleHg);
    if (nHeats > 1) {
        const remainder = n - (nHeats - 1) * bFinaleHg;
        if (remainder < bFinaleHg) nHeats--;
    }
    if (nHeats <= 1) return [n];
    const special = n - (nHeats - 1) * bFinaleHg;
    const result  = [];
    if (bLaatstGrootst) {
        for (let i = 0; i < nHeats - 1; i++) result.push(bFinaleHg);
        result.push(special);
    } else {
        result.push(special);
        for (let i = 1; i < nHeats; i++) result.push(bFinaleHg);
    }
    return result;
}

// Zoek ritten op voor een specifieke ronde → { heat_nr: { volgorde, rit_naam, verwacht } }
function bouwRitLookup(schema, dcId, distId, rondeType) {
    if (!schema?.ritten) return {};
    const dStr = String(distId ?? '');
    const lookup = {};
    for (const rit of schema.ritten) {
        if (rit.dc_id === dcId &&
            String(rit.distance_id ?? '') === dStr &&
            rit.ronde_type === rondeType) {
            lookup[parseInt(rit.heat_nr)] = {
                volgorde:  parseInt(rit.volgorde),
                rit_naam:  rit.rit_naam,
                verwacht:  parseInt(rit.verwacht ?? 0) || 0,
            };
        }
    }
    return lookup;
}

// Maak bracket-heat grid (placeholder slots, geen echte rijders)
function maakSchemaHeatGrid(heats, ritLookup) {
    const wrapper = document.createElement('div');
    const grid    = document.createElement('div');
    grid.className = 'heat-grid';
    for (const heat of heats) {
        const rit   = ritLookup?.[heat.nummer];
        const naam  = rit?.rit_naam  || `Heat ${heat.nummer}`;
        const volg  = rit?.volgorde  ?? null;
        const titel = escHtml(naam);
        const ritNr = volg != null ? `<span class="heat-ritnr">${volg}</span>` : '';
        const card  = document.createElement('div');
        card.className = 'heat-card heat-card-schema';
        let rows = '';
        heat.slots.forEach((slot, i) => {
            rows += `<tr><td class="heat-pos">${i + 1}</td>` +
                    `<td class="heat-naam heat-schema-slot">${escHtml(slot)}</td></tr>`;
        });
        card.innerHTML =
            `<div class="heat-titel">${ritNr}${titel}` +
            `<span class="heat-count">${heat.slots.length}</span></div>` +
            `<table class="heat-tabel">` +
            `<colgroup><col class="heat-pos"><col class="heat-naam"></colgroup>` +
            `<thead><tr><th>#</th><th>Deelnemer</th></tr></thead>` +
            `<tbody>${rows}</tbody></table>`;
        grid.appendChild(card);
    }
    wrapper.appendChild(grid);
    return wrapper;
}

// Bereken schema-heats voor een vervolgronde op basis van catCfg + flow-stap
// ritLookup: optioneel, voor runner_up om nHeats en volgorde op te halen
// systeem/afstandCfg: optioneel, nodig voor full-final A- en B-finale placeholders
function berekenSchemaHeats(r, catCfg, totaalRijders, ritLookup = null, systeem = null, afstandCfg = null) {
    if (!catCfg) return null;
    const int = v => parseInt(v ?? 0) || 0;

    // ── Full-final speciale afhandeling ───────────────────────────────────────
    if (systeem === 'full-final' && afstandCfg) {
        const finaleHg = Math.max(2, int(afstandCfg.finale_heat_grootte ?? 6));
        if (r.sleutel === 'finale_a') {
            // Full-Final: altijd 1 A-finale heat (B-finales regelen de verdeling)
            const prevNH = int(catCfg.heats_aantal) || 1;
            const slots  = bouwSchemaSlots('serie', prevNH, finaleHg, 0);
            return [{ nummer: 1, slots }];
        }
        if (r.sleutel === 'finale_b') {
            // Placeholder: dynamische verdeling via verdeelBFinalesJS()
            // Zelfde logica als in live.php (verdeelBFinales).
            const bRijders = Math.max(0, totaalRijders - finaleHg);
            if (bRijders <= 0) return null;

            const bFinaleHgRaw  = Math.max(2, int(afstandCfg?.finale_b_grootte ?? 6));
            const bFinaleHg     = Math.max(finaleHg, bFinaleHgRaw);
            const bLaatstGrootst = !!(afstandCfg?.laatste_b_grootste ?? 1);

            const bAantallen = verdeelBFinalesJS(bRijders, bFinaleHg, bLaatstGrootst);
            if (!bAantallen.length) return null;

            let rank = finaleHg + 1;
            return bAantallen.map((aantalInHeat, i) => {
                const slots = [];
                for (let j = 0; j < aantalInHeat && rank <= totaalRijders; j++, rank++) {
                    slots.push(`${rank}e tijdsnelste`);
                }
                return { nummer: i + 1, slots };
            }).filter(h => h.slots.length > 0);
        }
    }

    let nSlots = 0, nHeats = 1, prevNHeats = 0, prevNaam = '';

    let qPerHeat = 1;

    switch (r.sleutel) {
        case 'kwartfinale':
            nSlots     = int(catCfg.heats_q);
            nHeats     = int(catCfg.kwart_heats) || 1;
            prevNHeats = int(catCfg.heats_aantal) || 1;
            prevNaam   = 'serie';
            qPerHeat   = 0;  // series zijn altijd q (tijd), nooit Q (positie)
            break;
        case 'halve_finale':
            if (catCfg.heeft_kwartfinale) {
                nSlots     = int(catCfg.kwart_door);
                nHeats     = int(catCfg.half_heats) || 1;
                prevNHeats = int(catCfg.kwart_heats) || 1;
                prevNaam   = 'KF';
                qPerHeat   = int(catCfg.kwart_q_heat ?? 1);
            } else {
                nSlots     = int(catCfg.heats_q);
                nHeats     = int(catCfg.half_heats) || 1;
                prevNHeats = int(catCfg.heats_aantal) || 1;
                prevNaam   = 'serie';
                qPerHeat   = 0;  // series altijd q
            }
            break;
        case 'runner_up': {
            // Plek-ranges staan al correct in de rit_naam (bijv. "plek 17-20").
            // Geen snake: elke heat krijgt een aaneengesloten blok tijdsnelsten.
            if (!ritLookup || !Object.keys(ritLookup).length) return null;
            const ruHeats = [];
            for (const [heatNrStr, rit] of Object.entries(ritLookup)) {
                const heatNr = parseInt(heatNrStr);
                const match  = (rit.rit_naam || '').match(/\(plek\s+(\d+)(?:-(\d+))?\)/);
                const slots  = [];
                if (match) {
                    const van = parseInt(match[1]);
                    const tot = match[2] ? parseInt(match[2]) : van;
                    for (let p = van; p <= tot; p++)
                        slots.push(`${p}e tijdsnelste`);
                }
                ruHeats.push({ nummer: heatNr, slots });
            }
            return ruHeats.length ? ruHeats : null;
        }
        case 'finale_a':
        case 'finale':
            if (catCfg.heeft_halve_finale) {
                nSlots     = int(catCfg.half_door);
                prevNHeats = int(catCfg.half_heats) || 1;
                prevNaam   = 'HF';
                qPerHeat   = int(catCfg.half_q_heat ?? 1);
            } else if (catCfg.heeft_kwartfinale) {
                nSlots     = int(catCfg.kwart_door);
                prevNHeats = int(catCfg.kwart_heats) || 1;
                prevNaam   = 'KF';
                qPerHeat   = int(catCfg.kwart_q_heat ?? 1);
            } else {
                nSlots     = int(catCfg.heats_q);
                prevNHeats = int(catCfg.heats_aantal) || 1;
                prevNaam   = 'serie';
                qPerHeat   = int(catCfg.heats_q_heat ?? 1);
            }
            nHeats = Math.max(1, int(catCfg.finale_heats ?? 1));
            break;
        case 'finale_b':
            return null;
        default:
            return null;
    }

    if (nSlots <= 0 || prevNHeats <= 0) return null;
    const slots = bouwSchemaSlots(prevNaam, prevNHeats, nSlots, qPerHeat);
    if (nHeats === 1) return [{ nummer: 1, slots }];

    // Tijdkoppeling: paren van achteren, langzaamsten in heat 1, snelsten in laatste heat
    if (afstandCfg?.finale_seeding === 'tijdkoppeling' && r.sleutel === 'finale_a') {
        const heats = [];
        const reversed = [...slots].reverse(); // langzaamste eerst
        const perHeat = Math.max(1, Math.ceil(reversed.length / nHeats));
        for (let h = 0; h < nHeats; h++) {
            const chunk = reversed.slice(h * perHeat, (h + 1) * perHeat);
            // Binnen elk paar: snelste eerst (= laatste element van de chunk, want reversed)
            chunk.reverse();
            heats.push({ nummer: h + 1, slots: chunk });
        }
        return heats;
    }

    return snakeVerdeelSlots(slots, nHeats);
}

// ── Resultaten weergeven ──────────────────────────────────────────────────────

function toonSlResultaten(cacheKey, vergrendeld = false) {
    const cache     = startlijstCache[cacheKey];
    if (!cache?.resultaat) return;

    const slDist = el('sl-dist-content');
    const resultDiv = vergrendeld ? slDist : el('sl-resultaten');
    if (!resultDiv) return;
    if (vergrendeld) resultDiv.innerHTML = '';

    const flow       = cache.flow || [{ sleutel: 'heats', naam: 'Series', kleur: '#0d6efd' }];
    const eersteNaam = flow[0]?.naam ?? 'Series';
    const schema     = _slTsCache?.competition_id === huidigCompId ? _slTsCache.schema : null;
    const groep      = cache._groep;
    const distId     = cache._distId;
    const dcIds      = (groep?.dc_ids || [groep?.dc_id]).join(',');
    const cf         = Array.isArray(groep?.category_filter) ? groep.category_filter : [];

    // ── Vergrendeld: flex layout met deelnemers-paneel rechts ─────────────────
    let blokkenDiv = resultDiv;
    if (vergrendeld) {
        const layout   = document.createElement('div');
        layout.className = 'sl-loting-layout';
        blokkenDiv     = document.createElement('div');
        blokkenDiv.className = 'sl-loting-links';
        const panelDiv = document.createElement('div');
        panelDiv.className = 'sl-deelnemers-panel';
        layout.append(panelDiv, blokkenDiv);   // paneel links, heats rechts
        resultDiv.appendChild(layout);
        maakDeelnemersPaneel(panelDiv, cache, cacheKey, flow, groep, distId);
    }

    // ── Ronde 1: echte heats ──────────────────────────────────────────────────
    const ritLookup1 = bouwRitLookup(schema, groep?.dc_id, distId, flow[0]?.sleutel ?? 'heats');
    const blok1 = document.createElement('div');
    blok1.className = 'ronde-blok';

    const gegOp = cache.resultaat.gegenereerd_op
        ? ` · geloot op ${cache.resultaat.gegenereerd_op.slice(0,16).replace('T',' ')}`
        : '';

    blok1.innerHTML =
        `<div class="ronde-kop">` +
        `<span class="ronde-titel">${escHtml(eersteNaam)}</span>` +
        `<span class="sl-lock-info">🔒 Loting vastgelegd${escHtml(gegOp)}</span>` +
        `<button class="btn-danger-outline sl-btn-wis" id="sl-btn-wis">🗑 Wis loting</button>` +
        `</div>`;

    blok1.appendChild(maakHeatGrid(cache.resultaat, cache.methode || 'startnummer', ritLookup1));
    blokkenDiv.appendChild(blok1);

    const wisBtn = blok1.querySelector('#sl-btn-wis');
    if (wisBtn && !_slLeesOnly) {
        wisBtn.addEventListener('click', async () => {
            if (!await toonBevestigDialog('Loting verwijderen? Dit kan niet ongedaan worden gemaakt.', 'Loting wissen')) return;
            wisBtn.disabled = true;
            wisBtn.textContent = 'Verwijderen…';
            try {
                await fetch('api/startlijst_wis.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        competition_id:  huidigCompId,
                        dc_ids:          dcIds,
                        distance_id:     distId ?? '',
                        category_filter: cf.join(','),
                    }),
                });
                // Cache leegmaken, tab-kleuren + print-select refreshen, seeding-UI opnieuw tonen
                delete startlijstCache[cacheKey];
                invalideerSlStatus();
                zetDistTabKleur(distId, false);
                kleurAlleTabsAsync(_slGroepen, el('sl-cat-tabs'));
                vulPrintSelect();
                toonAfstandConfig(groep, distId, cache._distNaam ?? '');
            } catch (e) {
                wisBtn.disabled = false;
                wisBtn.textContent = '🗑 Wis loting';
                toonBevestigDialog('Fout bij verwijderen: ' + e.message, 'Fout');
            }
        });
    } else if (wisBtn) {
        wisBtn.remove();
    }

    // ── Volgende rondes: echte indeling (indien gegenereerd) of bracket-schema ─
    const totaalRijders  = cache.resultaat?.totaalRijders ?? 0;
    const volgendeRondes = cache.resultaat?.volgende_rondes ?? [];

    for (let i = 1; i < flow.length; i++) {
        const r          = flow[i];
        const ritLookupR = bouwRitLookup(schema, groep?.dc_id, distId, r.sleutel);

        // Zoek echte heats in DB voor deze ronde_type
        const echteRonde = volgendeRondes.find(vr => vr.ronde_type === r.sleutel);

        const div = document.createElement('div');
        div.className = 'ronde-blok';

        if (echteRonde) {
            // Echte riders beschikbaar uit DB
            const voorlopigBadge = echteRonde.vorige_ronde_compleet
                ? ''
                : `<span class="sl-ronde-voorlopig" title="Nog niet alle resultaten van de vorige ronde zijn ingevoerd">⏳ Voorlopige indeling</span>`;
            div.innerHTML =
                `<div class="ronde-kop">` +
                `<span class="ronde-titel" style="color:${r.kleur}">${escHtml(r.naam)}</span>` +
                voorlopigBadge +
                `</div>`;
            const gridData = {
                aantalHeats:   echteRonde.aantalHeats,
                totaalRijders: echteRonde.totaalRijders,
                heats:         echteRonde.heats,
            };
            div.appendChild(maakHeatGrid(gridData, 'kwalificatie', ritLookupR));
        } else {
            // Geen echte heats: schema placeholder
            const schemaHeats = berekenSchemaHeats(r, cache._catCfg, totaalRijders, ritLookupR, cache._systeem, cache._afstandCfg);
            // Niets te tonen (bijv. te weinig rijders voor B-finales) → blok weglaten
            if (!schemaHeats) continue;
            if (ritLookupR) {
                schemaHeats.sort((a, b) =>
                    (ritLookupR[a.nummer]?.volgorde ?? 9999) - (ritLookupR[b.nummer]?.volgorde ?? 9999));
            }
            div.innerHTML =
                `<div class="ronde-kop">` +
                `<span class="ronde-titel" style="color:${r.kleur}">${escHtml(r.naam)}</span>` +
                `<span class="sl-flow-ph-info">Schema op basis van tijdschema · nog in te vullen na rijtijden</span>` +
                `</div>`;
            div.appendChild(maakSchemaHeatGrid(schemaHeats, ritLookupR));
        }
        blokkenDiv.appendChild(div);
    }

}

// ── Deelnemers-paneel (rechts naast heatgrid, bij vergrendelde loting) ────────

function maakDeelnemersPaneel(container, cache, cacheKey, flow, groep, distId) {
    const RONDEAFK = {
        heats: 'S', kwartfinale: 'KF', halve_finale: 'HF',
        runner_up: 'RU', finale_a: 'A-fin', finale_b: 'B-fin', finale: 'Fin',
    };

    // Ronde-sleutel → rondenummer (zelfde als live.js)
    const RONDE_NR = {
        heats: 1, kwartfinale: 2, halve_finale: 3,
        runner_up: 3, finale_a: 4, finale_b: 4, finale: 4,
    };

    // Hulpfunctie: normaliseer competitor-object naar plat formaat
    const normaliseer = c => ({
        license_key:  c.license_key,
        start_number: c.db_person?.start_number ?? c.knsb?.start_number ?? c.start_number ?? null,
        full_name:    c.db_person?.full_name    ?? c.knsb?.full_name    ?? c.full_name    ?? '',
        short_name:   c.db_person?.short_name   ?? c.knsb?.short_name   ?? c.short_name   ?? '',
    });

    // Werkelijk ronde_type van de ronde-1 heats (uit API; bijv. 'finale_a' bij geen series)
    const ronde1Type = cache.resultaat?.ronde_1_ronde_type ?? 'heats';

    // Heat-toewijzing per ronde: sleutel → { license_key → heat_nr }
    const heatMapPerRonde = {};

    // Ronde 1: gebruik het werkelijke ronde_type als sleutel
    heatMapPerRonde[ronde1Type] = {};
    for (const heat of (cache.resultaat?.heats || []))
        for (const r of (heat.rijders || []))
            heatMapPerRonde[ronde1Type][r.license_key] = heat.nummer;

    // Volgende rondes: uit cache.resultaat.volgende_rondes
    // Skip ronde_type die al door ronde-1 is gevuld (voorkomt overschrijven bij ghost heats)
    const volgendeRondes = cache.resultaat?.volgende_rondes ?? [];
    for (const vr of volgendeRondes) {
        if (vr.ronde_type === ronde1Type) continue;  // ronde-1 data heeft voorrang
        heatMapPerRonde[vr.ronde_type] = {};
        for (const heat of (vr.heats || []))
            for (const r of (heat.rijders || []))
                heatMapPerRonde[vr.ronde_type][r.license_key] = heat.nummer;
    }

    // Welke rondes zijn al gegenereerd in de DB
    const gegenereerdeRonden = new Set([ronde1Type]);
    for (const vr of volgendeRondes) gegenereerdeRonden.add(vr.ronde_type);



    // Alle rijders: geregistreerd + uit alle rondes (voor rijders die evt. niet in competitors staan)
    const rijderMap = {};
    for (const c of (groep?.competitors || []))
        rijderMap[c.license_key] = normaliseer(c);
    for (const sleutel of Object.keys(heatMapPerRonde)) {
        // Ronde-1 data zit in cache.resultaat.heats (ook als sleutel bijv. 'finale_a' is)
        const heatsArr = sleutel === ronde1Type
            ? (cache.resultaat?.heats || [])
            : (volgendeRondes.find(vr => vr.ronde_type === sleutel)?.heats || []);
        for (const heat of heatsArr)
            for (const r of (heat.rijders || []))
                if (!rijderMap[r.license_key]) rijderMap[r.license_key] = normaliseer(r);
    }

    const rijders = Object.values(rijderMap).sort(
        (a, b) => (parseInt(a.start_number) || 9999) - (parseInt(b.start_number) || 9999)
    );

    const cf         = Array.isArray(groep?.category_filter) ? groep.category_filter : [];
    const splitGroup = groep?.is_split ? (cf.join(',') || groep.dc_name) : '';
    // B-finale rondes apart behandelen: één kolom per B-heat (B1, B2, B3 …)
    // Alleen tonen als de B-finales al gegenereerd zijn in de DB.
    const bFinaleRonde  = volgendeRondes.find(vr => vr.ronde_type === 'finale_b');
    const bHeatCount    = bFinaleRonde?.heats?.length || 0;
    const bFinaleActief = bHeatCount > 0 && !_slLeesOnly;

    // Normale rondes (alles behalve finale_b – die krijgen eigen kolommen)
    const rondes = flow.filter(r => r.sleutel !== 'finale_b');

    // ── Header-kolommen ───────────────────────────────────────────────────────
    let thRondes = '';
    for (const r of rondes)
        thRondes += `<th title="${escHtml(r.naam)}">${RONDEAFK[r.sleutel] ?? r.naam.slice(0,3)}</th>`;
    // B-finale kolommen: één per B-heat
    for (let b = 1; b <= bHeatCount; b++)
        thRondes += `<th class="sl-dp-bfin-th" title="B${b}-finale">B${b}</th>`;

    // ── Tabelrijen ────────────────────────────────────────────────────────────
    let tbody = '';
    for (const rijder of rijders) {
        const lk   = rijder.license_key;
        const snr  = rijder.start_number || '–';
        const naam = escHtml(rijder.full_name || rijder.short_name || '?');

        let tdRondes = '';

        // Normale rondes (series, kwartfinale, halve finale, A-finale)
        for (const ronde of rondes) {
            const sleutel  = ronde.sleutel;
            const isActief = gegenereerdeRonden.has(sleutel) && !_slLeesOnly;
            const hNr      = heatMapPerRonde[sleutel]?.[lk];
            const inHeat   = hNr !== undefined;
            if (isActief) {
                tdRondes += `<td class="sl-dp-heat${inHeat ? '' : ' sl-dp-geen-heat'}">` +
                    `<input type="number" min="1" value="${inHeat ? hNr : ''}" ` +
                    `class="sl-heat-input" ` +
                    `data-license="${escHtml(lk)}" ` +
                    `data-ronde-sleutel="${escHtml(sleutel)}"></td>`;
            } else {
                tdRondes += `<td class="sl-dp-heat sl-dp-schema">–</td>`;
            }
        }

        // B-finale kolommen: checkbox per B-heat (radio-gedrag: max 1 aangevinkt)
        const rijderBHeat = heatMapPerRonde['finale_b']?.[lk]; // heat_nr waar rijder nu in zit
        for (let b = 1; b <= bHeatCount; b++) {
            const isInDitHeat = rijderBHeat === b;
            if (bFinaleActief) {
                tdRondes += `<td class="sl-dp-heat sl-dp-bfin-cel">` +
                    `<input type="checkbox" class="sl-bfin-check"` +
                    ` data-license="${escHtml(lk)}"` +
                    ` data-heat-nr="${b}"` +
                    (isInDitHeat ? ' checked' : '') + `></td>`;
            } else {
                tdRondes += `<td class="sl-dp-heat sl-dp-schema">${isInDitHeat ? '✓' : '–'}</td>`;
            }
        }

        tbody += `<tr><td class="sl-dp-snr">${escHtml(String(snr))}</td>` +
            `<td class="sl-dp-naam" title="${naam}">${naam}</td>${tdRondes}</tr>`;
    }

    container.innerHTML =
        `<div class="sl-dp-header">Deelnemers` +
        `<span class="sl-dp-count">${rijders.length}</span></div>` +
        `<div class="sl-dp-tabel-wrap">` +
        `<table class="sl-dp-tabel">` +
        `<thead><tr><th>Snr</th><th>Naam</th>${thRondes}</tr></thead>` +
        `<tbody>${tbody}</tbody></table></div>`;

    // ── Event-listeners: normale rondes (number-input) ────────────────────────
    container.querySelectorAll('.sl-heat-input').forEach(input => {
        let origVal = input.value;
        input.addEventListener('focus', () => { origVal = input.value; });
        input.addEventListener('change', async () => {
            const license      = input.dataset.license;
            const rondeSleutel = input.dataset.rondeSleutel || 'heats';
            // Als dit het ronde-1 type is (bijv. 'finale_a' zonder series),
            // dan zit de heat in ronde=1 in de DB, niet op het standaard rondenummer.
            const rondeNr = (rondeSleutel === ronde1Type)
                ? 1
                : (RONDE_NR[rondeSleutel] ?? 1);
            const heatNr       = input.value.trim() ? parseInt(input.value) : null;
            if (String(heatNr ?? '') === origVal) return;

            input.disabled = true;
            input.classList.add('sl-heat-input-saving');
            try {
                const res  = await fetch('api/startlijst_rijder_heat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        competition_id: huidigCompId,
                        dc_id:          groep.dc_id,
                        distance_id:    distId ?? '',
                        split_group:    splitGroup,
                        ronde:          rondeNr,
                        ronde_type:     rondeSleutel,
                        person_license: license,
                        heat_nr:        heatNr,
                    }),
                });
                const data = await res.json();
                if (data.error) {
                    toonBevestigDialog(data.error, 'Fout');
                    input.value = origVal;
                    input.disabled = false;
                    input.classList.remove('sl-heat-input-saving');
                } else {
                    delete startlijstCache[cacheKey];
                    toonAfstandConfig(groep, distId, cache._distNaam ?? '');
                }
            } catch (e) {
                toonBevestigDialog('Netwerkfout: ' + e.message, 'Fout');
                input.value = origVal;
                input.disabled = false;
                input.classList.remove('sl-heat-input-saving');
            }
        });
    });

    // ── Event-listeners: B-finale checkboxen ─────────────────────────────────
    // Radio-gedrag: aanvinken van B2 vinkt B1 automatisch uit (rijder kan maar in 1 B-finale).
    // Uitvinken verwijdert de rijder uit de B-finale.
    container.querySelectorAll('.sl-bfin-check').forEach(cb => {
        cb.addEventListener('change', async () => {
            const license = cb.dataset.license;
            const heatNr  = cb.checked ? parseInt(cb.dataset.heatNr) : null;

            // Radio-gedrag: alle andere B-finale checkboxen voor deze rijder uitvinken
            if (cb.checked) {
                container.querySelectorAll(`.sl-bfin-check[data-license="${CSS.escape(license)}"]`)
                    .forEach(other => { if (other !== cb) other.checked = false; });
            }

            cb.disabled = true;
            try {
                const res  = await fetch('api/startlijst_rijder_heat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        competition_id: huidigCompId,
                        dc_id:          groep.dc_id,
                        distance_id:    distId ?? '',
                        split_group:    splitGroup,
                        ronde:          4,
                        ronde_type:     'finale_b',
                        person_license: license,
                        heat_nr:        heatNr,
                    }),
                });
                const data = await res.json();
                if (data.error) {
                    toonBevestigDialog(data.error, 'Fout');
                    cb.checked = !cb.checked; // terugdraaien
                    cb.disabled = false;
                } else {
                    delete startlijstCache[cacheKey];
                    toonAfstandConfig(groep, distId, cache._distNaam ?? '');
                }
            } catch (e) {
                toonBevestigDialog('Netwerkfout: ' + e.message, 'Fout');
                cb.checked = !cb.checked;
                cb.disabled = false;
            }
        });
    });
}

// ── Heat grid als DOM-element ──────────────────────────────────────────────────

function maakHeatGrid(data, methode, ritLookup) {
    const methodeLabel = {
        willekeurig:  'Willekeurig geloot',
        startnummer:  'Op startnummer',
        alfabetisch:  'Alfabetisch',
        klassement:   'Op klassement',
    }[methode] || methode;

    const wrapper = document.createElement('div');
    wrapper.innerHTML =
        `<div class="sl-info">${data.totaalRijders} rijders &nbsp;·&nbsp; ` +
        `${data.aantalHeats} heats &nbsp;·&nbsp; <em>${escHtml(methodeLabel)}</em></div>`;

    const grid = document.createElement('div');
    grid.className = 'heat-grid';
    for (const heat of data.heats) {
        const card = document.createElement('div');
        card.className = 'heat-card';
        let rows = '';

        // Bouw positie-map: startpositie → rijder
        const posMap = {};
        let maxPos   = 0;
        let heeftPos = false;
        for (const r of heat.rijders) {
            const pos = parseInt(r.startpositie) || 0;
            if (pos > 0) { posMap[pos] = r; maxPos = Math.max(maxPos, pos); heeftPos = true; }
        }

        if (heeftPos) {
            // Itereer van 1 t/m maxPos; lege posities tonen als placeholder
            for (let pos = 1; pos <= maxPos; pos++) {
                const r = posMap[pos];
                if (r) {
                    const lk       = r.license_key;
                    const tpActief = personEdits[lk]?.transponder_actief;
                    const tp = tpActief !== undefined
                        ? (tpActief || '—')
                        : (r.transponder_actief || r.transponders_extra?.[0] || r.transponder1 || r.transponder2 || '—');
                    const sanctieBadge = r.vorige_sancties
                        ? `<span class="heat-sanctie-badge" title="${escHtml(r.vorige_sancties)}">${escHtml(r.vorige_sancties)}</span>`
                        : '';
                    rows += `<tr>` +
                            `<td class="heat-pos">${pos}</td>` +
                            `<td class="heat-snr">${r.start_number || ''}</td>` +
                            `<td class="heat-cat">${escHtml(r.category || '')}</td>` +
                            `<td class="heat-naam">${escHtml(r.full_name)}${sanctieBadge}</td>` +
                            `<td class="heat-tp">${escHtml(tp)}</td></tr>`;
                } else {
                    rows += `<tr class="heat-row-pm">` +
                            `<td class="heat-pos">${pos}</td>` +
                            `<td class="heat-snr"></td>` +
                            `<td class="heat-cat"></td>` +
                            `<td class="heat-naam heat-naam-pm">— p.m. —</td>` +
                            `<td class="heat-tp"></td></tr>`;
                }
            }
        } else {
            // Fallback (geen startpositie in data): gewone volgorde
            heat.rijders.forEach((r, i) => {
                const lk       = r.license_key;
                const tpActief = personEdits[lk]?.transponder_actief;
                const tp = tpActief !== undefined
                    ? (tpActief || '—')
                    : (r.transponder_actief || r.transponders_extra?.[0] || r.transponder1 || r.transponder2 || '—');
                const sanctieBadge = r.vorige_sancties
                    ? `<span class="heat-sanctie-badge" title="${escHtml(r.vorige_sancties)}">${escHtml(r.vorige_sancties)}</span>`
                    : '';
                rows += `<tr>` +
                        `<td class="heat-pos">${i + 1}</td>` +
                        `<td class="heat-snr">${r.start_number || ''}</td>` +
                        `<td class="heat-cat">${escHtml(r.category || '')}</td>` +
                        `<td class="heat-naam">${escHtml(r.full_name)}${sanctieBadge}</td>` +
                        `<td class="heat-tp">${escHtml(tp)}</td></tr>`;
            });
        }
        // Gebruik opgeslagen heat_naam/rit_volgorde (DB) of tijdschema-lookup als fallback
        const rit    = ritLookup?.[heat.nummer];
        const naam   = heat.heat_naam || (rit?.rit_naam)  || `Heat ${heat.nummer}`;
        const volg   = heat.rit_volgorde ?? rit?.volgorde ?? null;
        const titel  = escHtml(naam);
        const ritNr  = volg != null ? `<span class="heat-ritnr">${volg}</span>` : '';
        card.innerHTML =
            `<div class="heat-titel">${ritNr}${titel}` +
            `<span class="heat-count">${heat.rijders.length}</span></div>` +
            `<table class="heat-tabel">` +
            `<colgroup>` +
            `<col class="heat-pos"><col class="heat-snr"><col class="heat-cat">` +
            `<col class="heat-naam"><col class="heat-tp">` +
            `</colgroup>` +
            `<thead><tr><th>#</th><th>Snr</th><th>Cat</th><th>Naam</th><th>Transp.</th></tr></thead>` +
            `<tbody>${rows}</tbody></table>`;
        grid.appendChild(card);
    }
    wrapper.appendChild(grid);
    return wrapper;
}
