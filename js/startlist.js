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

// Gecachete klassementen-lijst voor loting-UI. Per competition gecacht
// want de seeding-dropdown toont alleen voor deze wedstrijd relevante
// klassementen (serie-klassementen waarvan deze wedstrijd deel is +
// PDF-klassementen van dezelfde organisatie).
let slKlassementen = null;
let slKlassementenCompId = null;
function invalideerSlKlassementen() { slKlassementen = null; slKlassementenCompId = null; }
async function laadSlKlassementen() {
    if (slKlassementen && slKlassementenCompId === huidigCompId) return slKlassementen;
    try {
        const url = huidigCompId
            ? `api/klassement_import.php?action=list_for_seeding&competition_id=${encodeURIComponent(huidigCompId)}`
            : 'api/klassement_import.php?action=list';
        const r = await fetch(url);
        const d = await r.json();
        slKlassementen = Array.isArray(d) ? d : [];
        slKlassementenCompId = huidigCompId;
    } catch { slKlassementen = []; slKlassementenCompId = huidigCompId; }
    return slKlassementen;
}

// ── Tijdschema-cache voor startlijsten (per competition_id) ───────────────────

let _slTsCache = null;   // { competition_id, schema }

function invalideerSlTsCache() { _slTsCache = null; }

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
    // Runner-up draait PARALLEL uit eerste-ronde-uitvallers — heeft alleen
    // zin als er een eerste deelnemende ronde IS (heats / KF / HF). Voor
    // cats die direct in een A-finale starten (te weinig deelnemers, alle
    // andere rondes uitgevinkt) zou een runner-up alleen na de finale
    // betekenis hebben, en dan zijn alle rijders al gefinisht. In zo'n
    // geval slaan we runner-up over — matcht ook het programma-tijdschema,
    // dat 'm ook niet als rit aanmaakt.
    const heeftEersteRonde = (catCfg.heeft_heats && catCfg.heeft_heats !== '0')
                          || catCfg.heeft_kwartfinale
                          || catCfg.heeft_halve_finale;
    if (catCfg.heeft_runner_up && heeftEersteRonde)
        flow.push({ sleutel: 'runner_up',    naam: 'Runner-up',    kleur: KLEUREN.runner_up    || '#6c757d' });
    if (systeem === 'full-final') {
        flow.push({ sleutel: 'finale_a', naam: 'A-finale', kleur: KLEUREN.finale_a || '#198754' });
        // B-finale alleen toevoegen als de planner hem daadwerkelijk wil.
        // Per-cat finale_b_heats === 0 (expliciet) → géén B-finale in de flow,
        // zodat er ook geen "pro forma" placeholder-kaart verschijnt.
        const catBH = catCfg.finale_b_heats;
        const heeftGeenB = catBH !== null && catBH !== undefined && catBH !== ''
                        && parseInt(catBH) === 0;
        if (!heeftGeenB) {
            flow.push({ sleutel: 'finale_b', naam: 'B-finale(s)', kleur: KLEUREN.finale_b || '#20c997' });
        }
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
    // DOM-elementen optioneel: Print-Center roept deze functie ook aan
    // zónder dat de Startlijsten-pagina gerenderd is. In dat geval vullen
    // we alleen _slPrintOpties en slaan we DOM-manipulatie over.

    // Volledig reset
    _slPrintOpties = new Map();
    if (catSel)   catSel.innerHTML   = '<option value="">— Categorie —</option>';
    if (distSel)  { distSel.innerHTML  = '<option value="">— Afstand —</option>';  distSel.disabled  = true; }
    if (rondeSel) { rondeSel.innerHTML = '<option value="">— Ronde —</option>';    rondeSel.disabled = true; }
    if (btn) btn.disabled = true;

    // Zorg dat _slGroepen gevuld is (normaal gebeurt dat in toonStartlijstenPagina;
    // voor Print-Center doen we het hier als dat nog niet gebeurd is).
    if (!_slGroepen?.length && typeof bouwStartlijstGroepen === 'function') {
        _slGroepen = bouwStartlijstGroepen();
    }

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

    // Eerste select (categorie) vullen — alleen als DOM er is
    if (catSel) {
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
}

// ── Startlijst body-bouwer ────────────────────────────────────────────────────
// Returns { bodyHtml, cssLinks, extraCss, pageOrientation, title, subType }
// of null. Combi-detectie en dynamische portrait/landscape zitten hierin.
// Wordt aangeroepen door `bouwStartlijstBody()` voor Print-Center. Er is
// geen losse "Druk af"-knop meer in de UI — alles via Print-Center.
async function _bouwStartlijstDrukInternal(optData) {
    const { cacheKey, dcIds, dcName, distId, distNaam, categoryFilter,
            rondeSleutel = 'heats', rondeLabel = 'Series' } = optData;

    // ── Combi-detectie ─────────────────────────────────────────────────────
    // Als de geselecteerde rit(ten) een combi_group hebben, print ALLE heats
    // van de hele combi (over dc's heen) op één landscape-pagina.
    const schemaVoorCombi = _slTsCache?.competition_id === huidigCompId ? _slTsCache.schema : null;
    let combiGroup       = null;
    let combiDcIds       = null;     // alle dc's in de combi
    let combiRittenMap   = null;     // heat_nr → { dc_id, distance_id, rit_naam, volgorde, combi_group }

    if (schemaVoorCombi?.ritten && rondeSleutel === 'finale_a') {
        const myDcIds = dcIds ?? [optData.dcId];
        const mijnRit = schemaVoorCombi.ritten.find(r =>
            myDcIds.includes(r.dc_id) &&
            String(r.distance_id ?? '') === String(distId ?? '') &&
            r.ronde_type === 'finale_a' && r.combi_group
        );
        if (mijnRit?.combi_group) {
            combiGroup = parseInt(mijnRit.combi_group);
            const combiRitten = schemaVoorCombi.ritten.filter(r =>
                r.ronde_type === 'finale_a' &&
                parseInt(r.combi_group) === combiGroup
            ).sort((a, b) => (a.volgorde ?? 0) - (b.volgorde ?? 0));
            combiDcIds     = [...new Set(combiRitten.map(r => r.dc_id))];
            combiRittenMap = combiRitten;
        }
    }

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
            if (!data?.exists) { console.warn('[Startlijst] Geen loting:', optData); return null; }
        } catch (e) { console.warn('[Startlijst] Laad-fout:', e); return null; }
    }

    // ── Combi: haal startlijst op voor ELKE dc in de combi (niet alleen geselecteerde)
    //    en voeg ze samen tot één heat-lijst voor de print.
    let combiHeatsSamengevoegd = null;  // [{heat, dc_id, dc_name, rit}]
    if (combiGroup && combiDcIds?.length > 1) {
        try {
            const fetches = await Promise.all(combiDcIds.map(async dcId => {
                const url = `api/startlijst_laden.php`
                          + `?competition_id=${encodeURIComponent(huidigCompId)}`
                          + `&dc_ids=${encodeURIComponent(dcId)}`
                          + `&distance_id=${encodeURIComponent(distId ?? '')}`;
                const r = await fetch(url);
                return { dcId, json: await r.json() };
            }));
            combiHeatsSamengevoegd = [];
            const ontbrekendeRitten = []; // rit_naam van ritten zonder loting
            for (const rit of combiRittenMap) {
                const pack = fetches.find(f => f.dcId === rit.dc_id);
                if (!pack?.json?.exists) {
                    ontbrekendeRitten.push(rit.rit_naam || rit.dc_naam || rit.dc_id);
                    continue;
                }
                // Zoek de finale_a heats (via volgende_rondes) of heats voor de ronde
                const aRonde = (pack.json.volgende_rondes ?? [])
                    .find(vr => vr.ronde_type === 'finale_a');
                const heats = aRonde?.heats ?? pack.json.heats ?? [];
                const h = heats.find(x => parseInt(x.nummer) === parseInt(rit.heat_nr))
                      ?? heats[0];
                if (h) {
                    combiHeatsSamengevoegd.push({
                        heat:    h,
                        dc_id:   rit.dc_id,
                        dc_name: rit.dc_naam || pack.json.dc_name || '',
                        rit,
                    });
                } else {
                    ontbrekendeRitten.push(rit.rit_naam || rit.dc_naam || rit.dc_id);
                }
            }

            // Waarschuwing als er ritten zonder loting in de combi zitten
            if (ontbrekendeRitten.length) {
                const lijst = ontbrekendeRitten.map(n => '• ' + n).join('\n');
                const msg = `Deze gecombineerde startlijst is niet compleet — voor `
                          + `${ontbrekendeRitten.length} rit${ontbrekendeRitten.length !== 1 ? 'ten' : ''} `
                          + `is er nog geen loting gemaakt:\n\n${lijst}\n\n`
                          + `Wil je toch alleen de beschikbare ritten afdrukken?`;
                const doorgaan = await toonBevestigDialog(
                    msg, 'Onvolledige combi-startlijst',
                    'Toch afdrukken', 'Annuleer'
                );
                if (!doorgaan) return null;
            }
        } catch (e) {
            // Val terug op normaal printen
            combiHeatsSamengevoegd = null;
        }
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
        startnummer:     'Op startnummer',
        alfabetisch:     'Alfabetisch',
        tussenklassement:'Tussenklassement (deze wedstrijd)',
        klassement:      'Klassement (serie)',
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
    // Bij combi-print ALTIJD landscape (alle kolommen moeten op 1 pagina)
    const isCombiMode = !!(combiHeatsSamengevoegd && combiHeatsSamengevoegd.length);
    const isPortrait = !isCombiMode && maxHeatGrootte > 20;
    const gridCols   = isPortrait ? 2 : 3;
    const pageSize   = isPortrait ? 'A4 portrait' : 'A4 landscape';

    // Helper: bouw één heat-card (totaalHeats = totaal in deze sectie, voor "Heat x/n")
    const maakCard = (heat, lookup, extraClass = '', totaalHeats = 0) => {
        const rit      = lookup?.[heat.nummer];
        // Naam aanpassen: "Heat 1" → "Heat 1/N" als er meerdere heats zijn
        let naam = heat.heat_naam || rit?.rit_naam || `Heat ${heat.nummer}`;
        if (totaalHeats > 1) {
            naam = naam.replace(/\bHeat\s+(\d+)\b/i, `Heat $1/${totaalHeats}`);
        }
        const volg     = heat.rit_volgorde ?? rit?.volgorde ?? null;
        const ritBadge = volg != null ? `<span class="pr-ritnr">${volg}</span>` : '';
        // Combi-marker als deze rit deel van een combi-groep is
        const combiGrp = rit?.combi_group ? parseInt(rit.combi_group) : null;
        const combiBadge = combiGrp
            ? `<span class="pr-combi-badge" title="Gecombineerd met andere ritten in het programma">🔗 combi</span>`
            : '';
        const extraCls = combiGrp ? (extraClass + ' pr-card-combi').trim() : extraClass;
        let rows = '';
        (heat.rijders ?? []).forEach((r, i) => {
            const opm   = r.vorige_sancties ?? '';
            // Photofinish-marker uit eerdere ronde: visueel signaal dat de
            // tijd van deze rijder ergens via jury-wissel is aangepast — bij
            // q-kwalificatie kan de transponder-tijd misleiden.
            const pfIco = r.vorige_photofinish
                ? `<span class="pr-pf-icon" title="Photofinish — tijd via jury-wissel aangepast in een eerdere ronde">📷</span>`
                : '';
            const opmCel = (opm ? esc(opm) : '') + (pfIco && opm ? ' ' : '') + pfIco;
            rows += `<tr>
                <td class="pr-pos">${i + 1}</td>
                <td class="pr-snr">${esc(r.start_number ?? '')}</td>
                <td class="pr-cat">${esc(r.categorie ?? r.category ?? '')}</td>
                <td class="pr-naam">${esc(r.full_name ?? '')}</td>
                <td class="pr-opm">${opmCel}</td>
            </tr>`;
        });
        return `<div class="pr-card${extraCls ? ' ' + extraCls : ''}">
            <div class="pr-titel">${ritBadge}${esc(naam)}${combiBadge}</div>
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

    // ── Combi-modus: alle combi-heats op 1 landscape pagina in kolommen ──────
    let isCombiPrint = false;
    let cardsHtml = '';
    if (combiHeatsSamengevoegd && combiHeatsSamengevoegd.length) {
        isCombiPrint = true;
        // Bouw één combi-frame met kolommen per heat
        const kolommen = combiHeatsSamengevoegd.map(({ heat, dc_name, rit }) => {
            const naam = rit?.rit_naam ?? heat.heat_naam ?? dc_name ?? '';
            const volg = heat.rit_volgorde ?? rit?.volgorde ?? null;
            const ritBadge = volg != null ? `<span class="pr-ritnr">${volg}</span>` : '';
            let rows = '';
            (heat.rijders ?? []).forEach((r, i) => {
                const opm   = r.vorige_sancties ?? '';
                const pfIco = r.vorige_photofinish
                    ? `<span class="pr-pf-icon" title="Photofinish — tijd via jury-wissel aangepast in een eerdere ronde">📷</span>`
                    : '';
                const opmCel = (opm ? esc(opm) : '') + (pfIco && opm ? ' ' : '') + pfIco;
                rows += `<tr>
                    <td class="pr-pos">${i + 1}</td>
                    <td class="pr-snr">${esc(r.start_number ?? '')}</td>
                    <td class="pr-cat">${esc(r.categorie ?? r.category ?? '')}</td>
                    <td class="pr-naam">${esc(r.full_name ?? '')}</td>
                    <td class="pr-opm">${opmCel}</td>
                </tr>`;
            });
            return `<div class="pr-combi-kolom">
                <div class="pr-titel pr-combi-kolom-titel">${ritBadge}<span class="pr-combi-naam">${esc(naam)}</span></div>
                <table class="pr-tabel pr-combi-tabel">
                    <colgroup>
                        <col class="pr-col-pos"><col class="pr-col-snr"><col class="pr-col-cat"><col class="pr-col-naam"><col class="pr-col-opm">
                    </colgroup>
                    <thead><tr><th>#</th><th>Snr</th><th>Cat</th><th>Naam</th><th>Opm.</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
        }).join('');
        cardsHtml = `<div class="pr-combi-frame">
            <div class="pr-combi-header">🔗 Gecombineerde startlijst — ${combiHeatsSamengevoegd.length} ritten</div>
            <div class="pr-combi-kolommen">${kolommen}</div>
        </div>`;
    } else if (isFullFinalPrint) {
        // B-finales sectie
        const bHeats = afdrukHeats.filter(h => h._finaleType === 'b');
        const aHeats = afdrukHeats.filter(h => h._finaleType === 'a');
        if (bHeats.length) {
            cardsHtml += `<div class="pr-sectie-kop pr-sectie-b">B-Finales</div>`;
            for (const heat of bHeats)
                cardsHtml += maakCard(heat, rlB, '', bHeats.length);
        }
        if (aHeats.length) {
            cardsHtml += `<div class="pr-sectie-kop pr-sectie-a">A-Finale</div>`;
            let first = true;
            for (const heat of aHeats) {
                cardsHtml += maakCard(heat, rlA, first ? 'pr-card-links' : '', aHeats.length);
                first = false;
            }
        }
    } else {
        for (const heat of afdrukHeats)
            cardsHtml += maakCard(heat, rl, '', afdrukHeats.length);
    }

    const titleStr = `Startlijst – ${dcName}${distLabel}`;
    const extraCss = `
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;margin:.6cm 1cm;color:#111;line-height:1.35}
.pr-comp{font-size:13pt;font-weight:700}
.pr-meta{font-size:8.5pt;color:#000;margin-top:1mm}
.pr-ronde{font-size:10pt;font-weight:700;color:#000}
.pr-methode{font-size:8pt;color:#000;margin-top:1mm;font-style:italic}
/* grid-template-columns wordt per element inline gezet, zodat twee
   gecombineerde startlijsten met andere kolomeisen niet botsen. */
.pr-grid{display:grid;gap:.5cm}
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
col.pr-col-snr{width:36px}
col.pr-col-cat{width:30px}
col.pr-col-naam{}
col.pr-col-opm{width:60px}
.pr-pos{color:#aaa;text-align:center;font-size:7.5pt}
/* Hogere specificiteit (td.klasse) nodig om het shorthand
   .pr-tabel td{padding:3px 4px} te overrulen; zonder dit plakken
   het startnummer en de categorie tegen elkaar in smalle kolommen. */
.pr-tabel td.pr-snr{text-align:right;font-weight:600;color:#1a3a5c;padding-right:10px}
.pr-tabel td.pr-cat{font-size:7.5pt;color:#666;padding-left:2px}
.pr-naam{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.pr-opm{border-left:1px solid #ddd!important}
/* Full-final: sectie-koppen (B-Finales / A-Finale) */
.pr-sectie-kop{grid-column:1/-1;font-weight:700;font-size:9pt;letter-spacing:.03em;
               padding:3px 0 2px;margin-top:.3cm;border-bottom:2px solid currentColor;
               /* Houd de sectie-kop bij de eerstvolgende heat-card; geen
                  page-break direct ná deze regel. */
               page-break-after:avoid;break-after:avoid}
.pr-sectie-b{color:#20c997}
.pr-sectie-a{color:#198754}
/* Combi-marker: accent-rand op kaarten van gecombineerde ritten + badge */
.pr-card-combi{border:2px solid #2E75B6;box-shadow:inset 0 0 0 1px rgba(46,117,182,.15)}
.pr-combi-badge{margin-left:auto;background:#2E75B6;color:#fff;font-size:7pt;font-weight:600;
                padding:1px 7px;border-radius:8px;letter-spacing:.03em}
/* ── Combi startlijst: alle ritten op 1 landscape pagina in kolommen ── */
/* grid-column 1/-1 zorgt dat het frame de volledige grid-breedte pakt,
   ongeacht gridCols. */
.pr-combi-frame{grid-column:1/-1;width:100%;border:2px solid #2E75B6;border-radius:5px;
                overflow:hidden;break-inside:avoid;page-break-inside:avoid}
.pr-combi-header{background:#2E75B6;color:#fff;padding:5px 10px;font-weight:700;font-size:9pt;
                 letter-spacing:.02em}
.pr-combi-kolommen{display:flex;flex-direction:row;align-items:stretch;gap:0;width:100%}
.pr-combi-kolom{flex:1 1 0;min-width:0;border-right:1px solid #2E75B6;display:flex;flex-direction:column}
.pr-combi-kolom:last-child{border-right:none}
.pr-combi-kolom-titel{background:#1a3a5c;color:#fff;padding:4px 7px;font-size:9pt;
                      display:flex;align-items:center;gap:.25cm}
.pr-combi-naam{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
/* Ruimere tabel-lay-out: naam-kolom pakt alle resterende ruimte */
.pr-combi-tabel{font-size:9.5pt;width:100%;table-layout:auto}
.pr-combi-tabel td{padding:3px 5px}
.pr-combi-tabel th{font-size:7.5pt;padding:2px 5px}
.pr-combi-tabel .pr-naam{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;width:100%}
.pr-combi-tabel col.pr-col-pos{width:22px}
.pr-combi-tabel col.pr-col-snr{width:36px}
.pr-combi-tabel col.pr-col-cat{width:34px}
.pr-combi-tabel col.pr-col-naam{width:auto}
/* A-finale altijd aan de linkerkantlijn (grid-kolom 1) */
.pr-card-links{grid-column-start:1}
/* Photofinish-icoon in Opm.-kolom — puur sec het 📷-emoji. */
.pr-pf-icon{margin-left:3px}
@media print{
  body{margin:.5cm .8cm}
  .pr-card{break-inside:avoid}
  .pr-titel{background:#e8ecf0!important;color:#000!important;border-bottom:2px solid #000}
  .pr-ritnr{background:#000!important;color:#fff!important}
  .pr-tabel th{background:#eee!important;color:#000!important}
}
/* Wrapper-tabel: thead herhaalt automatisch op elke pagina bij print */
.pr-wrap{width:100%;border-collapse:collapse}
.pr-wrap thead td{padding:0}
.pr-wrap .pr-hdr-row td{padding-bottom:.2cm;border-bottom:2px solid #1a3a5c}
.pr-wrap .pr-hdr-spacer td{height:0.3cm}
.pr-wrap tbody td{padding:0}
.pr-hdr-inner{display:flex;justify-content:space-between;align-items:flex-end}
`;
    const bodyHtml = `
<table class="pr-wrap">
  <thead>
    <tr class="pr-hdr-row"><td>
      <div class="pr-hdr-inner">
        <div>
          <div class="pr-comp">${esc(comp?.name ?? '')}</div>
          <div class="pr-meta">${esc(metaTxt)}</div>
        </div>
        <div style="text-align:right">
          <div class="pr-ronde">${isCombiPrint
              ? '🔗 Gecombineerde startlijst – ' + combiHeatsSamengevoegd.map(x => esc(x.dc_name || '')).filter(Boolean).join(' · ') + esc(distLabel) + '&nbsp;–&nbsp;' + esc(rondeLabel)
              : esc(dcName) + esc(distLabel) + '&nbsp;–&nbsp;' + esc(rondeLabel)
          }</div>
          ${methodeLabel ? `<div class="pr-methode">${esc(methodeLabel)}</div>` : ''}
        </div>
      </div>
    </td></tr>
    <tr class="pr-hdr-spacer"><td></td></tr>
  </thead>
  <tbody><tr><td>
    <div class="pr-grid" style="grid-template-columns:repeat(${gridCols},1fr)">${cardsHtml}</div>
  </td></tr></tbody>
</table>
`;

    return {
        bodyHtml,
        cssLinks:        [],
        extraCss,
        pageOrientation: isPortrait ? 'portrait' : 'landscape',
        title:           titleStr,
        subType:         'Startlijst ' + [distNaam, rondeLabel].filter(Boolean).join(' — '),
    };
}

// ── Publieke body-builder voor Print-Center ─────────────────────────────────
// Gebruikt dezelfde hoogwaardige layout als de voormalige "Druk af"-knop
// (combi-detectie + dynamische portrait/landscape).
async function bouwStartlijstBody(optData) {
    return await _bouwStartlijstDrukInternal(optData);
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

    // Tab-kleuren + print-opties in achtergrond opbouwen (niet-blokkerend).
    // `vulPrintSelect()` vult `_slPrintOpties` ten behoeve van Print-Center.
    kleurAlleTabsAsync(groepen, catTabs);
    vulPrintSelect();
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

// Retourneert true als er echte data is, false als niet
async function vulTussenklPreview(container, nRijders, nHeats, schema, groep, distId, flow) {
    container.innerHTML = '<span class="sl-tk-laden">⏳ Tussenstand laden…</span>';
    if (!nRijders || !nHeats) { container.innerHTML = ''; return false; }
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
        return true;
    } else {
        // Geen data: generieke slots
        const info = document.createElement('div');
        info.className = 'sl-tk-afstanden sl-tk-geen-data';
        info.textContent = 'Nog geen uitslagen beschikbaar voor deze DC. Indeling gebaseerd op tussenstand zodra resultaten zijn ingevoerd.';
        container.appendChild(info);

        const slots = Array.from({ length: nRijders }, (_, i) => `${i + 1}e tussenklassement`);
        const heats = nHeats === 1 ? [{ nummer: 1, slots }] : snakeVerdeelSlots(slots, nHeats);
        container.appendChild(maakSchemaHeatGrid(heats, ritLookup));
        return false;
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
                ⚠ Maak eerst een tijdschema aan voordat je een startlijst kunt maken.
            </div>`;
        return;
    }
    // Wel tijdschema maar geen programma gegenereerd → waarschuwing
    if (!(schema.ritten?.length)) {
        slDist.innerHTML =
            `<div class="status-msg warn">
                ⚠ Genereer eerst het programma in het Tijdschema voordat je een startlijst kunt maken.
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
                <button class="sl-meth-btn${methode === 'tussenklassement' ? ' actief' : ''}" data-methode="tussenklassement">
                    🏁 Tussenklassement (deze wedstrijd)
                </button>
                <button class="sl-meth-btn${methode === 'klassement' ? ' actief' : ''}" data-methode="klassement">
                    🏆 Klassement (serie)
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
    if (methode === 'tussenklassement') {
        const heeftData = await vulTussenklPreview(el('sl-tk-preview'), groep.competitors.length, cache.heatsAantal, schema, groep, distId, flow);
        const genBtn = el('sl-genereer');
        if (genBtn) genBtn.disabled = !heeftData;
    }

    // ── Methode knoppen ───────────────────────────────────────────────────────
    slDist.querySelectorAll('.sl-meth-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            cache.methode = btn.dataset.methode;
            slDist.querySelectorAll('.sl-meth-btn').forEach(b => b.classList.remove('actief'));
            btn.classList.add('actief');
            el('sl-kl-kiezer').style.display = cache.methode === 'klassement'       ? '' : 'none';
            el('sl-tk-kiezer').style.display  = cache.methode === 'tussenklassement' ? '' : 'none';
            const genBtn = el('sl-genereer');
            if (cache.methode === 'tussenklassement') {
                if (genBtn) genBtn.disabled = true; // disabled totdat preview geladen is
                const heeftData = await vulTussenklPreview(el('sl-tk-preview'), groep.competitors.length, cache.heatsAantal, schema, groep, distId, flow);
                if (genBtn) genBtn.disabled = !heeftData;
            } else {
                if (genBtn) genBtn.disabled = false;
            }
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
            // Categorieën komen in twee vormen:
            //   PDF-klassementen  → [{ label, cat_codes, … }]
            //   Serie-klassementen → ['DJB', 'DKA', …]  (gewone strings)
            // Normaliseer eerst naar {label}-objecten zodat de rest werkt.
            const norm = cats
                .map(s => typeof s === 'string' ? { label: s } : s)
                .filter(s => s && s.label);
            if (norm.length) {
                klSelSec.innerHTML = norm.map(s =>
                    `<option value="${escHtml(s.label)}" ${cache.klassementSectie === s.label ? 'selected' : ''}>${escHtml(s.label)}</option>`
                ).join('');
                klSelSec.disabled = false;
                if (!cache.klassementSectie) cache.klassementSectie = norm[0].label;
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
        // Print-Center cache invalideren — anders zou een open of opnieuw-
        // geopende modal nog de oude lijst zonder deze loting tonen.
        if (typeof window.printCenterInvalideerStartlijsten === 'function') {
            window.printCenterInvalideerStartlijsten();
        }
        toonSlResultaten(cacheKey, true);

    } catch(e) {
        resultDiv.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}


// ── Schema-helpers: bracket-slots voor vervolgronden ─────────────────────────

// Genereer geordende slot-labels met Q/q scheiding.
// Q-slots (positie-kwalificatie, top-N per heat) gaan altijd voor q-slots
// (tijds-kwalificatie, snelste tijden van de overige rijders).
//
// stijl='tijd' (full-final): tier+time → "Q 1e tijdsnelste", "Q 2e tijdsnelste",
//                            "q 1e tijdsnelste", ... (reflecteert tier-then-time
//                            seeding waarbij snelste rank-1 op plek 1 komt).
//                            Bij qPerHeat ≥ 2: "Q1 ...", "Q2 ..." per rang.
// stijl='bracket' (internationaal, default): heat-positioneel → "Winnaar KF 1",
//                            "Winnaar KF 2", "2e KF 1", "Xe tijdsnelste".
//                            Past bij One Lap/500m fixed-bracket én bij overige
//                            afstanden waar tier+time geldt — beide blijven
//                            plausibel leesbaar.
function bouwSchemaSlots(prevNaam, prevNHeats, nSlots, qPerHeat, stijl = 'bracket') {
    const qph = Math.max(0, qPerHeat ?? 0);
    const nQ  = Math.min(nSlots, qph * prevNHeats);
    const nq  = Math.max(0, nSlots - nQ);
    const slots = [];

    if (stijl === 'tijd') {
        // Full-final: alle Q's op tijd binnen tier
        const enkelvoudigQ = qph === 1;
        for (let rank = 1; rank <= qph && slots.length < nQ; rank++) {
            const prefix = enkelvoudigQ ? 'Q' : `Q${rank}`;
            for (let t = 1; t <= prevNHeats && slots.length < nQ; t++) {
                slots.push(`${prefix} ${t}e tijdsnelste`);
            }
        }
        for (let t = 1; t <= nq; t++) {
            slots.push(`q ${t}e tijdsnelste`);
        }
    } else {
        // Internationaal: heat-positionele labels (bracket-friendly)
        for (let rank = 1; slots.length < nQ; rank++) {
            for (let h = 1; h <= prevNHeats && slots.length < nQ; h++) {
                slots.push(rank === 1 ? `Winnaar ${prevNaam} ${h}` : `${rank}e ${prevNaam} ${h}`);
            }
        }
        for (let t = 1; t <= nq; t++) {
            slots.push(`${t}e tijdsnelste`);
        }
    }
    return slots;
}

// Custom dialog voor "Wis loting" met optionele cascade-checkboxes voor
// vastgelegde uitslag en/of klassement van de bijbehorende DC.
// Resolves naar:
//   null            → user annuleerde
//   { wis_uitslag, wis_klassement } → bevestigd, met user-keuzes
function _slToonWisDialog(info) {
    return new Promise(resolve => {
        const heeftResults    = (info.results_count    ?? 0) > 0;
        const heeftUitslag    = (info.uitslag_count    ?? 0) > 0;
        const heeftKlassement = (info.klassement_count ?? 0) > 0;

        // Body bouwen — dynamisch op basis van wat er bestaat
        const lines = ['<p>Loting verwijderen? <b>Dit kan niet ongedaan worden gemaakt.</b></p>'];
        if (heeftResults) {
            lines.push(`<p class="modal-warn">⚠ Er ${info.results_count === 1 ? 'is' : 'zijn'} al <b>${info.results_count}</b> tijd${info.results_count === 1 ? '' : 'en'}/positie${info.results_count === 1 ? '' : 's'} ingevoerd. Die ${info.results_count === 1 ? 'gaat' : 'gaan'} óók verloren.</p>`);
        }
        const checkboxes = [];
        if (heeftUitslag) {
            checkboxes.push(`
                <label class="modal-check">
                    <input type="checkbox" id="sl-wis-uitslag" checked>
                    <span>Vastgelegde <b>uitslag voor deze afstand</b> ook verwijderen
                          <small>(${info.uitslag_count} rijder${info.uitslag_count === 1 ? '' : 's'} in archief)</small>
                    </span>
                </label>`);
        }
        if (heeftKlassement) {
            checkboxes.push(`
                <label class="modal-check">
                    <input type="checkbox" id="sl-wis-klassement" checked>
                    <span>Vastgelegd <b>klassement van deze categorie-groep</b> ook verwijderen
                          <small>(${info.klassement_count} rijder${info.klassement_count === 1 ? '' : 's'} in archief — geldt voor alle afstanden in deze DC)</small>
                    </span>
                </label>`);
        }
        if (checkboxes.length) {
            lines.push('<div class="modal-checks">' + checkboxes.join('') + '</div>');
        }

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <span class="modal-icon">⚠</span>
                    <span>Loting wissen</span>
                </div>
                <div class="modal-body">${lines.join('')}</div>
                <div class="modal-knoppen">
                    <button class="modal-btn modal-annuleer">Annuleer</button>
                    <button class="modal-btn modal-doorgaan">Verwijderen</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        const sluit = (result) => {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            resolve(result);
        };
        const lees = () => ({
            wis_uitslag:    overlay.querySelector('#sl-wis-uitslag')?.checked    ?? false,
            wis_klassement: overlay.querySelector('#sl-wis-klassement')?.checked ?? false,
        });
        const onKey = e => {
            if (e.key === 'Escape') sluit(null);
            if (e.key === 'Enter')  sluit(lees());
        };
        overlay.querySelector('.modal-annuleer').addEventListener('click', () => sluit(null));
        overlay.querySelector('.modal-doorgaan').addEventListener('click', () => sluit(lees()));
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(null); });
        document.addEventListener('keydown', onKey);
        overlay.querySelector('.modal-annuleer').focus();
    });
}

// Verdeel slot-labels over heats via slangenpatroon
function snakeVerdeelSlots(slots, nHeats) {
    const heats = Array.from({ length: nHeats }, (_, i) => ({ nummer: i + 1, slots: [] }));
    snakeAppendSlots(slots, heats);
    return heats;
}

// Append labels naar bestaande heats via snake-pattern (mutating).
// Wordt gebruikt voor twee-pass-snake (Q's eerst, dan q's apart).
function snakeAppendSlots(labels, heats) {
    const nHeats = heats.length;
    if (nHeats === 0) return;
    let i = 0;
    while (i < labels.length) {
        for (let h = 0; h < nHeats && i < labels.length; h++)
            heats[h].slots.push(labels[i++]);
        if (i >= labels.length) break;
        for (let h = nHeats - 1; h >= 0 && i < labels.length; h--)
            heats[h].slots.push(labels[i++]);
    }
}

// Bracket-pattern verdeling: bron-heats gepaard op heat-positie {1, last},
// {2, last-1}, etc. Binnen elke destination-heat: rank-1 van eerste-bron,
// rank-1 van tweede-bron, daarna rank-2 van eerste-bron, rank-2 van tweede,
// enz. Gebruikt voor "alleen Q" internationaal (One Lap/500m-conventie).
function bracketVerdeelLabels(prevNaam, prevNHeats, qPerHeat, nHeats) {
    const bronGroups = {}; // destIdx → [bronHeatNr, ...]
    for (let i = 0; i < prevNHeats; i++) {
        const destIdx = Math.min(i, prevNHeats - 1 - i);
        if (!bronGroups[destIdx]) bronGroups[destIdx] = [];
        bronGroups[destIdx].push(i + 1);
    }
    const heats = [];
    Object.keys(bronGroups).map(Number).sort((a, b) => a - b).forEach(destIdx => {
        if (destIdx >= nHeats) return;
        const bronHeats = bronGroups[destIdx].slice().sort((a, b) => a - b);
        const slots = [];
        for (let rank = 1; rank <= qPerHeat; rank++) {
            for (const bronHeatNr of bronHeats) {
                slots.push(rank === 1
                    ? `Winnaar ${prevNaam} ${bronHeatNr}`
                    : `${rank}e ${prevNaam} ${bronHeatNr}`);
            }
        }
        heats.push({ nummer: destIdx + 1, slots });
    });
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
                volgorde:    parseInt(rit.volgorde),
                rit_naam:    rit.rit_naam,
                verwacht:    parseInt(rit.verwacht ?? 0) || 0,
                combi_group: rit.combi_group ? parseInt(rit.combi_group) : null,
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
        // Per-cat wint over afstand-defaults
        const finaleHgAfstand = Math.max(2, int(afstandCfg.finale_heat_grootte ?? 6));
        const catAG           = parseInt(catCfg.finale_a_grootte);
        const effA            = Number.isFinite(catAG) && catAG > 0 ? catAG : finaleHgAfstand;

        // Per-cat aantal B-heats (null = legacy/afstand-based afleiding)
        const catBHRaw = catCfg.finale_b_heats;
        const hasCatBH = catBHRaw !== null && catBHRaw !== undefined && catBHRaw !== '';
        const catBH    = hasCatBH ? (parseInt(catBHRaw) || 0) : null;

        const bRijders = Math.max(0, totaalRijders - effA);

        if (r.sleutel === 'finale_a') {
            // A-finale = 1 heat. Als per-cat 0 B-heats is ingesteld met rest-rijders,
            // schuiven die naar de A-finale zodat géén "pro forma" B getoond wordt.
            const aEff   = (catBH === 0 && bRijders > 0) ? effA + bRijders : effA;
            const prevNH = int(catCfg.heats_aantal) || 1;
            // heats_q_heat = aantal Q-rijders per serie die DIRECT naar A-finale
            // gaan (positie-kwalificatie). 0 = puur op tijd (klassiek).
            // ≥1 = de eerste N slots krijgen "Q Xe tijdsnelste"-labels (tier+time),
            // de rest "q Xe tijdsnelste".
            const qPerHeat = int(catCfg.heats_q_heat ?? 0);
            // Full-final = tier+time-stijl ("Q 1e tijdsnelste"). Internationaal
            // gebruikt buiten dit blok de bracket-stijl ("Winnaar KF 1") als
            // default, want daar kan One Lap/500m bracket-pattern gebruiken.
            const slots    = bouwSchemaSlots('serie', prevNH, aEff, qPerHeat, 'tijd');
            return [{ nummer: 1, slots }];
        }
        if (r.sleutel === 'finale_b') {
            // Geen rest-rijders → geen B-finale.
            if (bRijders <= 0) return null;
            // Planner heeft expliciet 0 B-heats ingesteld → géén B-finale tonen
            // (rest-rijders zijn al verwerkt in A-finale hierboven).
            if (catBH === 0) return null;

            let bAantallen;
            const lbgRaw = catCfg.laatste_b_grootste;
            const bLaatstGrootst = (lbgRaw !== null && lbgRaw !== undefined)
                ? !!parseInt(lbgRaw)
                : !!(afstandCfg?.laatste_b_grootste ?? 1);

            if (catBH !== null && catBH > 0) {
                // Per-cat aantal B-heats: verdeel gelijk; rest naar B1 of B-laatste
                const nBH   = Math.min(catBH, bRijders);
                const basis = Math.floor(bRijders / nBH);
                const extra = bRijders % nBH;
                bAantallen = [];
                for (let i = 0; i < nBH; i++) {
                    bAantallen.push(basis + (bLaatstGrootst
                        ? (i >= nBH - extra ? 1 : 0)
                        : (i < extra ? 1 : 0)));
                }
            } else {
                // Legacy / afstand-based: afleiden uit max-per-heat
                const bFinaleHgRaw = Math.max(2, int(afstandCfg?.finale_b_grootte ?? 6));
                const bFinaleHg    = Math.max(finaleHgAfstand, bFinaleHgRaw);
                bAantallen = verdeelBFinalesJS(bRijders, bFinaleHg, bLaatstGrootst);
            }
            if (!bAantallen.length) return null;

            let rank = effA + 1;
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

    // Tijdkoppeling: paren van achteren, langzaamsten in heat 1, snelsten in laatste heat.
    // Bestaande logic — bouwSchemaSlots geeft de Q+q labels, daarna pair-distributie.
    if (afstandCfg?.finale_seeding === 'tijdkoppeling' && r.sleutel === 'finale_a') {
        const slots = bouwSchemaSlots(prevNaam, prevNHeats, nSlots, qPerHeat);
        if (nHeats === 1) return [{ nummer: 1, slots }];
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

    // Case-detectie voor internationaal-systeem (de drie standaard-modi):
    //   1) Alleen Q (geen q)  → bracket-pattern (One Lap/500m-conventie)
    //   2) Alleen q (geen Q)  → snake-on-time
    //   3) Q + q              → twee-pass snake: eerst Q's snake, dan q's snake
    // Bij 1 destination-heat is verdeling triviaal: alle slots in 1 heat.
    const qph = Math.max(0, qPerHeat || 0);
    const nQTotaal = Math.min(nSlots, qph * prevNHeats);
    const nqTotaal = Math.max(0, nSlots - nQTotaal);
    const caseAlleenQ = qph > 0 && nqTotaal === 0;
    const caseQEnQ    = qph > 0 && nqTotaal > 0;

    // CASE 1: alleen Q → bracket (heat-paren {1,last}, {2,last-1}, ...)
    if (caseAlleenQ && nHeats > 1) {
        return bracketVerdeelLabels(prevNaam, prevNHeats, qph, nHeats);
    }

    // CASE 3: Q + q → twee-pass snake met tier+time-labels
    // Werkt ook bij 1 destination-heat: dan staan Q's en q's gewoon op
    // tijd-volgorde onder elkaar — geen verdere snake-actie maar wel
    // tijdsnelste-labels (i.p.v. de misleidende "Winnaar HF 1"-labels
    // die de bracket-stijl zou opleveren).
    if (caseQEnQ) {
        const enkelvoudigQ = qph === 1;
        const qLabels = [];
        for (let rank = 1; rank <= qph; rank++) {
            const prefix = enkelvoudigQ ? 'Q' : `Q${rank}`;
            for (let t = 1; t <= prevNHeats; t++) qLabels.push(`${prefix} ${t}e tijdsnelste`);
        }
        const qqLabels = [];
        for (let t = 1; t <= nqTotaal; t++) qqLabels.push(`q ${t}e tijdsnelste`);

        if (nHeats === 1) {
            return [{ nummer: 1, slots: [...qLabels, ...qqLabels] }];
        }
        const heats = Array.from({ length: nHeats }, (_, i) => ({ nummer: i + 1, slots: [] }));
        snakeAppendSlots(qLabels, heats);
        snakeAppendSlots(qqLabels, heats);
        return heats;
    }

    // CASE 2 / fallback: alleen q óf 1 destination-heat — gewone snake.
    // bouwSchemaSlots-default 'bracket'-stijl geeft "Winnaar X" voor Q en
    // "Xe tijdsnelste" voor q (voor 1-heat finale: Q's eerst, dan q's).
    const slots = bouwSchemaSlots(prevNaam, prevNHeats, nSlots, qph);
    if (nHeats === 1) return [{ nummer: 1, slots }];
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
            // Stap 1: check welke side-effects de wis zou hebben.
            // dcIds is hier al een comma-separated string (zie regel ~1823:
            // `const dcIds = (groep?.dc_ids || [groep?.dc_id]).join(',')`),
            // dus géén extra .join() — die zou een TypeError geven op string.
            const baseBody = {
                competition_id:  huidigCompId,
                dc_ids:          dcIds,
                distance_id:     distId ?? '',
                category_filter: cf.join(','),
            };
            let info;
            try {
                const res = await fetch('api/startlijst_wis.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ...baseBody, mode: 'check' }),
                });
                info = await res.json();
                if (info.error) throw new Error(info.error);
            } catch (e) {
                toonBevestigDialog('Kon impact niet bepalen: ' + e.message, 'Fout');
                return;
            }

            // Stap 2: enriched dialog tonen met counts + checkboxes
            const keuzes = await _slToonWisDialog(info);
            if (!keuzes) return;  // user heeft geannuleerd

            // Stap 3: daadwerkelijk wissen
            wisBtn.disabled = true;
            wisBtn.textContent = 'Verwijderen…';
            try {
                await fetch('api/startlijst_wis.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        ...baseBody,
                        mode:           'delete',
                        wis_uitslag:    keuzes.wis_uitslag,
                        wis_klassement: keuzes.wis_klassement,
                    }),
                });
                // Cache leegmaken, tab-kleuren + print-select refreshen, seeding-UI opnieuw tonen
                delete startlijstCache[cacheKey];
                invalideerSlStatus();
                zetDistTabKleur(distId, false);
                kleurAlleTabsAsync(_slGroepen, el('sl-cat-tabs'));
                vulPrintSelect();
                // Live-module afvalkoers-state resetten — anders blijven
                // afgevallen-nummers zichtbaar in het paneel tot een hard
                // refresh. Alleen de gewiste (dc, distance) — andere
                // ritten houden hun lokale state.
                if (typeof window.liveAfvalResetVoorDC === 'function') {
                    window.liveAfvalResetVoorDC(dcIds, distId);
                }
                // Print-Center cache invalideren — wis kan ook uitslagen + klassement
                // hebben opgeruimd (cascade-keuzes), dus beide invalideren.
                if (typeof window.printCenterInvalideerStartlijsten === 'function') {
                    window.printCenterInvalideerStartlijsten();
                }
                if (keuzes.wis_uitslag || keuzes.wis_klassement) {
                    if (typeof window.printCenterInvalideerUitslagen === 'function') {
                        window.printCenterInvalideerUitslagen();
                    }
                }
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

    // Ronde-sleutel → rondenummer (zelfde als live.js + api/live.php).
    // runner_up = ronde 4 (chronologisch vlak vóór de finale gereden,
    // dezelfde DB-ronde als finale_a/finale_b).
    const RONDE_NR = {
        heats: 1, kwartfinale: 2, halve_finale: 3,
        runner_up: 4, finale_a: 4, finale_b: 4, finale: 4,
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
        startnummer:     'Op startnummer',
        alfabetisch:     'Alfabetisch',
        tussenklassement:'Tussenklassement (deze wedstrijd)',
        klassement:      'Klassement (serie)',
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
                    const pfBadge = r.vorige_photofinish
                        ? `<span class="heat-pf-badge" title="Photofinish — tijd via jury-wissel aangepast in een eerdere ronde">📷</span>`
                        : '';
                    rows += `<tr>` +
                            `<td class="heat-pos">${pos}</td>` +
                            `<td class="heat-snr">${r.start_number || ''}</td>` +
                            `<td class="heat-cat">${escHtml(r.category || '')}</td>` +
                            `<td class="heat-naam">${escHtml(r.full_name)}${sanctieBadge}${pfBadge}</td>` +
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
                const pfBadge = r.vorige_photofinish
                    ? `<span class="heat-pf-badge" title="Photofinish — tijd via jury-wissel aangepast in een eerdere ronde">📷</span>`
                    : '';
                rows += `<tr>` +
                        `<td class="heat-pos">${i + 1}</td>` +
                        `<td class="heat-snr">${r.start_number || ''}</td>` +
                        `<td class="heat-cat">${escHtml(r.category || '')}</td>` +
                        `<td class="heat-naam">${escHtml(r.full_name)}${sanctieBadge}${pfBadge}</td>` +
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
