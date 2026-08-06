/* ============================================================
 *  InlineComp – Tijdschema-wizard  (Deel 1: DC's samenstellen)
 *
 *  Bouwsteen = CATEGORIE (persons.category). 1a = categorie-kaarten die je in
 *  groepen sleept. Pool ("bak") = nog niet ingedeeld. Elke categorie moet in
 *  een groep vóór opslaan (solo = groep van 1), ook 0-deelnemers — zodat een
 *  late aanmelder altijd een startlijst heeft.
 *
 *  Laden / re-run:
 *    - split_group  → groep (per DC)
 *    - merge_group  → groep
 *    - anders + vlag wizard_dc_gedaan gezet → de DC is een groep
 *    - anders → los in de bak
 *  Feed-gecombineerde categorieën (multi-code DC) staan los in de bak, met een
 *  stippellijn eromheen als feed-hint.
 *
 *  Grendel: loting → alles read-only; cat_config/programma → structureel op slot.
 *  Opslaan → groepen naar merge_group + dc_splits, afstanden naar afstanden_beheer,
 *  vlag wizard_dc_gedaan zetten. Kan alleen als !locked én de bak leeg is.
 * ============================================================ */
(function () {
    'use strict';

    let compId  = null;
    let overlay = null;
    let wzData  = null;
    let catMap  = {};     // catId (dcId|code) → {...}
    let state   = null;   // { pool:[catId], groepen:[{label, auto, leden:[catId]}] }
    let locked  = null;   // 'loting' | 'structureel' | null
    let dragId  = null;   // 1a: catId dat gesleept wordt
    let dragAfstand = null; // 1b: afstand-id die gesleept wordt
    let afstandSeq = 0;     // teller voor lokale afstand-id's (stabiel bij hernoemen)
    let editTarget = null;  // 1b: welke afstand-pil staat open in bewerk-modus
    let dragFromGi = null;  // 1b: sleep-bron — null = uit de lijst, anders groep-index
    let stap    = 1;        // 1 = DC's (subtabs 1a/1b) · 2 = afstand-instellingen
    let subtab  = '1a';     // actieve subtab binnen stap 1
    let d2Sys   = 'full-final';   // Deel 2 vraag 1: systeem
    let d2Par   = {};       // Deel 2 params per afstand-index: {format, hG, mS, fA, minB, q, laatsteB, startModus, ov:{gi:{A,bAantal}}}
    let d2Edit  = null;     // Deel 2: welke groep-regel staat open in bewerk-modus {ai, gi}
    let d2Locked = false;   // Deel 2: er staat al config in de DB → bulk op slot, alleen ✎
    let d2Dirty = new Set();// Deel 2: groepen (ai|gi) met onopgeslagen wijzigingen (badge "gewijzigd")
    let d1Snapshot = null;  // vingerafdruk van de indeling bij openen/opslaan (Deel 1 dirty-check)
    let d2Changed = false;  // Deel 2/3 gewijzigd sinds openen/opslaan
    let d2WijzigStap = null; // stap waar de laatste onopgeslagen wijziging is gemaakt
    let d3Dur   = {};       // Deel 3: heat-duur override per blok (afstand|meters|ronde → sec)
    let d3Staggered = {};     // Deel 3: staggered start per afstand (afstand|meters → bool)
    let d3Start = { datum: null, tijd: null };   // Deel 3: startmoment (anker voor de klok)
    let d3Opts = null;      // Deel 3: vragen-vooraf (inrijden/pauze/ceremonie)
    let d3InrijdCluster = {};   // Deel 3: groep-index → inrijd-cluster (1 of 2) bij 'geclusterd'
    let d3Manueel = null;   // Deel 3: handmatige blok-volgorde (array items) — null = auto-afgeleid
    let d4Schema = null;    // Deel 4: geladen tijdschema-schema (ritten) — null = (her)laden
    let d4Sel = new Set();  // Deel 4: geselecteerde rit-ids om te combineren
    let d4Max = 12;         // Deel 4: max gecombineerde grootte (rijders)
    let d4Open = new Set(); // Deel 4: uitgeklapte blok-ids (zoom)
    const WZ_TITELS = { 1: "Deel 1 · DC's samenstellen", 2: 'Deel 2 · Afstand-instellingen', 3: 'Deel 3 · Programma', 4: 'Deel 4 · A-finales combineren' };
    // Markeer een onopgeslagen wijziging + onthoud de stap (voor de footer-melding).
    function d2MarkWijziging() { d2Changed = true; d2WijzigStap = stap; }

    function wizardResetVoorWedstrijd(cid) {
        compId = cid || null;
        wizardUpdateKnop();
    }
    window.wizardResetVoorWedstrijd = wizardResetVoorWedstrijd;

    // Knop alleen aan als er een wedstrijd is ÉN de import actueel is. Zolang
    // #btn-import klikbaar is (niks geïmporteerd / wijzigingen / nieuwe entries)
    // kloppen de aantallen niet → wizard blokkeren met uitleg in de tooltip.
    // Wordt aangeroepen vanuit app.js (wedstrijd-select) en import.js
    // (updateImportBtn, na elke import-status-wijziging).
    function wizardUpdateKnop() {
        const btn = document.getElementById('btn-wizard');
        if (!btn) return;
        if (!compId) { btn.disabled = true; btn.title = 'Selecteer eerst een wedstrijd (in Importeer)'; return; }
        const imp = document.getElementById('btn-import');
        const importNodig = imp && !imp.disabled;
        btn.disabled = importNodig;
        btn.title = importNodig
            ? 'Eerst importeren in Importeer — de aantallen zijn nog niet actueel'
            : 'Tijdschema-wizard openen';
    }
    window.wizardUpdateKnop = wizardUpdateKnop;

    // ── Open / sluit ─────────────────────────────────────────────────────────
    async function openWizard() {
        if (!compId) return;
        const imp = document.getElementById('btn-import');
        if (imp && !imp.disabled) return; // import niet actueel — knop hoort disabled te zijn
        if (!overlay) { overlay = bouwOverlay(); document.body.appendChild(overlay); }
        stap = 1; subtab = '1a';
        zetTab('1a');
        overlay.querySelector('#wz-3').classList.add('wz-hidden');
        overlay.querySelector('#wz-2').classList.add('wz-hidden');
        overlay.querySelector('#wz-4').classList.add('wz-hidden');
        overlay.querySelector('#wz-4').innerHTML = '';
        { const _t = overlay.querySelector('#wz-titel small'); if (_t) _t.textContent = WZ_TITELS[1]; }
        overlay.querySelector('.wz-subtabs').style.display = '';
        overlay.querySelectorAll('.wz-step').forEach(el => el.classList.toggle('act', el.dataset.step === '1'));
        toonLaden();
        overlay.classList.add('wz-open');
        try {
            const res = await fetch('api/wizard_dc.php?competition_id=' + encodeURIComponent(compId));
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'laadfout');
            wzData = data;
            d2Sys = data.systeem || 'full-final';
            d2Par = {};
            d2Edit = null;
            d2Locked = false;
            d2Dirty = new Set();
            d2Changed = false;
            d3Dur = {};
            d3Staggered = {};
            d3Start = { datum: null, tijd: null };
            d3Opts = { inrijden: true, inrijdenMode: 'gezamenlijk', inrijdenDuur: 15, voorbereidenDuur: 5, pauzeKolom: true, pauzeDuur: 10,
                       lunch: true, lunchTijd: '12:30', lunchDuur: 30, ceremonie: true, ceremonieDuur: 20 };
            d3InrijdCluster = {};
            d3Manueel = null;
            d4Schema = null; d4Sel = new Set(); d4Open = new Set();
            {
                const m = String(data.comp_starts || '').match(/(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/);
                if (m) { d3Start.datum = m[1]; d3Start.tijd = m[2] || '10:00'; }
            }
            bouwState(data);
            reconstrueerDeel2(data);
            reconstrueerDeel3(data);   // opgeslagen programma terughalen (indien aanwezig)
            d1Snapshot = d1Vinger();   // baseline voor de dirty-check
            render();
        } catch (e) {
            toonFout(e.message || 'Kon de wedstrijd-gegevens niet laden.');
        }
    }
    // "Vingerafdruk" van de Deel-1-indeling om onopgeslagen wijzigingen te zien.
    function d1Vinger() {
        if (!state) return '';
        return JSON.stringify({
            pool: state.pool,
            g: state.groepen.map(x => ({ l: x.leden, lb: x.label, a: x.afstanden })),
            c: state.catalog,
        });
    }
    function heeftWijzigingen() {
        return (d1Snapshot != null && d1Vinger() !== d1Snapshot) || d2Changed;
    }
    async function sluitWizard() {
        if (!overlay) return;
        if (heeftWijzigingen()) {
            const ok = await toonBevestigDialog(
                'Er zijn niet-opgeslagen wijzigingen in de wizard. Sluiten en die wijzigingen weggooien?',
                'Onopgeslagen wijzigingen', 'Sluiten', 'Terug');
            if (!ok) return;
        }
        overlay.classList.remove('wz-open');
    }

    function catId(c) { return c.dc_id + '|' + c.code; }

    function bouwState(data) {
        catMap = {};
        (data.categorien || []).forEach(c => {
            catMap[catId(c)] = {
                code: c.code, dcId: c.dc_id, dcName: c.dc_name || '',
                feedCombined: !!c.feed_combined,
                mergeGroup: c.merge_group, mergeLabel: c.merge_label, splitGroup: c.split_group,
                n: c.deelnemers || 0, res: c.reserves || 0, na: c.niet_actief || 0, nb: c.niet_bevestigd || 0,
            };
        });

        // Reconstrueer groepen + bak
        const groepMap = new Map(); // key → { label, leden:[] }
        const pool = [];
        (data.categorien || []).forEach(c => {
            const id = catId(c);
            let key = null, label = null;
            if (c.split_group)      { key = 'split:' + c.dc_id + '|' + c.split_group; label = c.split_group; }
            else if (c.merge_group) { key = 'merge:' + c.merge_group;                 label = c.merge_label || null; }
            else                    { key = 'dc:' + c.dc_id;                  label = c.feed_combined ? null : c.dc_name; }
            if (key) {
                if (!groepMap.has(key)) groepMap.set(key, { leden: [], label });
                groepMap.get(key).leden.push(id);
            } else {
                pool.push(id);
            }
        });
        const groepen = Array.from(groepMap.values()).map(g => ({
            leden: g.leden, auto: true, label: g.label || joinLabel(g.leden),
        }));
        state = { pool, groepen };

        // 1b: catalogus (distinct namen) + per groep de afstanden van z'n startlijst.
        // Gesplitst → afstanden met target_group = split_group. Niet-gesplitst → de
        // BASIS-afstanden (target_group null) van de PRIMAIRE DC van de groep
        // (laagste dc_number) — per-DC, niet globaal, anders lek je afstanden tussen
        // DC's naar elkaar.
        afstandSeq = 0;
        const dcNummer = {};
        (data.categorien || []).forEach(c => { if (dcNummer[c.dc_id] == null) dcNummer[c.dc_id] = c.dc_number || 0; });
        // Afstand-identiteit = naam + meters + type (NIET naam alleen). Binnen een
        // wedstrijd mag dezelfde naam in verschillende DC's/groepen voorkomen, en
        // "Sprint" 300m ≠ "Sprint" 500m. Op naam alleen ontdubbelen liet de 500m
        // samenvallen met de 300m bij herladen.
        const afKey = d => `${d.name}${d.value_meters ?? ''}${d.race_type ?? ''}`;
        const cat = new Map();     // afKey → {name, race_type, value_meters}
        const bySplit = {};        // split_group → [{name, number, value_meters, race_type}]
        const baseByDc = {};       // dc_id → [...]  (target_group null)
        Object.keys(data.distances_per_dc || {}).forEach(dcId => (data.distances_per_dc[dcId] || []).forEach(d => {
            const k = afKey(d);
            if (!cat.has(k)) cat.set(k, { name: d.name, race_type: d.race_type, value_meters: d.value_meters });
            const item = { name: d.name, number: d.number || 0, value_meters: d.value_meters, race_type: d.race_type };
            if (d.target_group) {
                bySplit[d.target_group] = bySplit[d.target_group] || [];
                if (!bySplit[d.target_group].some(x => afKey(x) === k)) bySplit[d.target_group].push(item);
            } else {
                baseByDc[dcId] = baseByDc[dcId] || [];
                if (!baseByDc[dcId].some(x => afKey(x) === k)) baseByDc[dcId].push(item);
            }
        }));
        state.catalog = Array.from(cat.values(), v => ({ id: ++afstandSeq, name: v.name, race_type: v.race_type, value_meters: v.value_meters }));
        const idByKey = {};
        state.catalog.forEach(d => { idByKey[afKey(d)] = d.id; });
        state.groepen.forEach(g => {
            if (!g.leden.length) { g.afstanden = []; return; }
            const sg = catMap[g.leden[0]].splitGroup;
            let lijst;
            if (sg) {
                lijst = bySplit[sg] || [];
            } else {
                const dcs = [...new Set(g.leden.map(id => catMap[id].dcId))];
                const primDc = dcs.sort((a, b) => (dcNummer[a] || 0) - (dcNummer[b] || 0))[0];
                lijst = baseByDc[primDc] || [];
            }
            g.afstanden = lijst.slice().sort((a, b) => (a.number || 0) - (b.number || 0)).map(x => idByKey[afKey(x)]).filter(Boolean);
        });

        if (data.heeft_loting)      locked = 'loting';
        else if (data.heeft_cat_config || data.heeft_programma) locked = 'structureel';
        else                        locked = null;
    }

    // Groepsnaam. Eén lid → categorie-naam (of code bij feed-combinatie).
    // Meerdere → categorie-codes ("DKA* + HKA*").
    function joinLabel(leden) {
        if (!leden.length) return 'Nieuwe groep';
        if (leden.length === 1) { const c = catMap[leden[0]]; return c.feedCombined ? c.code : (c.dcName || c.code); }
        return leden.map(id => catMap[id].code).join(' + ');
    }
    function herlabel() { state.groepen.forEach(g => { if (g.auto) g.label = joinLabel(g.leden); }); }

    function bouwOverlay() {
        const d = document.createElement('div');
        d.id = 'wz-overlay';
        d.className = 'wz-overlay';
        d.innerHTML = `
          <div class="wz-dialog" role="dialog" aria-labelledby="wz-titel">
            <div class="wz-kop">
              <h2 id="wz-titel">🪄 Tijdschema-wizard <small>Deel 1 · DC's samenstellen</small></h2>
              <button class="wz-sluit" id="wz-sluit" title="Sluiten">×</button>
            </div>
            <div class="wz-body">
              <div id="wz-status"></div>
              <div class="wz-steps">
                <div class="wz-step act wz-klik" data-step="1"><span class="wz-num">1</span><span class="wz-lbl">DC's samenstellen<small>groepen + afstanden</small></span></div>
                <div class="wz-step wz-klik" data-step="2"><span class="wz-num">2</span><span class="wz-lbl">Afstand-instellingen<small>series, A-grootte</small></span></div>
                <div class="wz-step wz-klik" data-step="3"><span class="wz-num">3</span><span class="wz-lbl">Programma<small>blok-volgorde</small></span></div>
                <div class="wz-step wz-klik" data-step="4"><span class="wz-num">4</span><span class="wz-lbl">A-finales combineren<small>optioneel</small></span></div>
              </div>
              <div class="wz-subtabs">
                <button id="wz-tab-1a" class="act">1a · Groepen vormen</button>
                <button id="wz-tab-1b">1b · Afstanden per groep</button>
              </div>
              <div id="wz-1a"></div>
              <div id="wz-1b" class="wz-hidden"></div>
              <div id="wz-2" class="wz-hidden"></div>
              <div id="wz-3" class="wz-hidden"></div>
              <div id="wz-4" class="wz-hidden"></div>
            </div>
            <div class="wz-foot" id="wz-foot"></div>
          </div>`;
        d.querySelector('#wz-sluit').addEventListener('click', sluitWizard);
        d.addEventListener('click', e => { if (e.target === d) sluitWizard(); });
        d.querySelector('#wz-tab-1a').addEventListener('click', () => zetTab('1a'));
        d.querySelector('#wz-tab-1b').addEventListener('click', () => zetTab('1b'));
        d.querySelectorAll('.wz-step.wz-klik').forEach(el =>
            el.addEventListener('click', () => zetStap(+el.dataset.step)));
        return d;
    }

    // Footer past zich aan de stap aan.
    //   Stap 1: Annuleren · Opslaan en sluiten · Opslaan en verder → (primair)
    //   Stap 2: Annuleren · ← Terug naar stap 1
    function renderFooter() {
        const f = overlay && overlay.querySelector('#wz-foot');
        if (!f) return;
        // Melding links in de footer: er is een onopgeslagen wijziging (+ in welke stap).
        const wijzigNote = d2Changed
            ? `<span class="wz-foot-note" title="Ga via de stappenbalk bovenin naar die stap om de wijziging aan te passen of ongedaan te maken">● niet-opgeslagen wijziging${d2WijzigStap ? ' in stap ' + d2WijzigStap : ''}</span>`
            : '';
        if (stap === 4) {
            f.innerHTML =
                `<button class="wz-btn" id="wz-annuleer">Annuleren</button>
                 <button class="wz-btn" id="wz-terug">← Stap 3</button>
                 <button class="wz-btn wz-btn-primair" id="wz-d4-sluit">Klaar</button>`;
            f.querySelector('#wz-annuleer').addEventListener('click', sluitWizard);
            f.querySelector('#wz-terug').addEventListener('click', () => zetStap(3));
            f.querySelector('#wz-d4-sluit').addEventListener('click', sluitWizard);
            return;
        }
        if (stap === 3) {
            const kanD3 = locked !== 'loting';
            const tip = locked === 'loting' ? 'Er is al geloot — het programma staat vast.' : '';
            // Opslaan nodig? Bij onopgeslagen wijzigingen JA; ook als er nog géén
            // programma is. Anders (programma bestaat, niets gewijzigd) → alleen door.
            const moetOpslaan = kanD3 && (d2Changed || !(wzData && wzData.heeft_programma));
            const sluitLabel  = moetOpslaan ? 'Opslaan en sluiten' : 'Sluiten';
            const verderLabel = moetOpslaan ? 'Opslaan en verder → stap 4' : 'Verder → stap 4';
            f.innerHTML = wijzigNote +
                `<button class="wz-btn" id="wz-annuleer">Annuleren</button>
                 <button class="wz-btn" id="wz-terug">← Stap 2</button>
                 <button class="wz-btn" id="wz-d3-opslaan-sluit" title="${esc(tip)}">${sluitLabel}</button>
                 <button class="wz-btn wz-btn-primair" id="wz-d3-opslaan" title="${esc(tip)}">${verderLabel}</button>`;
            f.querySelector('#wz-annuleer').addEventListener('click', sluitWizard);
            f.querySelector('#wz-terug').addEventListener('click', () => zetStap(2));
            const s = f.querySelector('#wz-d3-opslaan-sluit'); if (s) s.addEventListener('click', () => opslaanDeel3('sluit'));
            const o = f.querySelector('#wz-d3-opslaan');       if (o) o.addEventListener('click', () => opslaanDeel3('stap4'));
            return;
        }
        if (stap === 2) {
            const kanD2 = locked !== 'loting';
            const tip = locked === 'loting' ? 'Er is al geloot — instellingen staan vast.' : '';
            // Opslaan nodig? Bij onopgeslagen wijzigingen JA; ook bij een verse
            // wedstrijd waar de config nog nooit is opgeslagen (defaults vastleggen).
            // Anders (config bestaat al, niets gewijzigd) → alleen "Verder", het
            // bestaande programma blijft dan intact.
            const moetOpslaan = kanD2 && (d2Changed || !(wzData && wzData.heeft_cat_config));
            const sluitLabel  = moetOpslaan ? 'Opslaan en sluiten' : 'Sluiten';
            const verderLabel = moetOpslaan ? 'Opslaan en verder → stap 3' : 'Verder → stap 3';
            f.innerHTML = wijzigNote +
                `<button class="wz-btn" id="wz-annuleer">Annuleren</button>
                 <button class="wz-btn" id="wz-terug">← Stap 1</button>
                 <button class="wz-btn" id="wz-d2-opslaan-sluit" title="${esc(tip)}">${sluitLabel}</button>
                 <button class="wz-btn wz-btn-primair" id="wz-d2-opslaan" title="${esc(tip)}">${verderLabel}</button>`;
            f.querySelector('#wz-annuleer').addEventListener('click', sluitWizard);
            f.querySelector('#wz-terug').addEventListener('click', () => zetStap(1));
            const s = f.querySelector('#wz-d2-opslaan-sluit'); if (s) s.addEventListener('click', () => opslaanDeel2('sluit'));
            const o = f.querySelector('#wz-d2-opslaan');       if (o) o.addEventListener('click', () => opslaanDeel2('stap3'));
            return;
        }
        // Vergrendeld: stap 1 is alleen-lezen, dus geen opslaan — wel gewoon door
        // naar stap 2 (afstand-instellingen bekijken/aanpassen).
        if (locked) {
            f.innerHTML =
                `<button class="wz-btn" id="wz-annuleer">Annuleren</button>
                 <button class="wz-btn wz-btn-primair" id="wz-verder">Verder → stap 2</button>`;
            f.querySelector('#wz-annuleer').addEventListener('click', sluitWizard);
            f.querySelector('#wz-verder').addEventListener('click', () => zetStap(2));
            return;
        }
        const inBak = state ? state.pool.length : 0;
        const kan = state && inBak === 0;
        const tip = inBak > 0 ? `De bak is nog niet leeg — sleep de laatste ${inBak} categorie${inBak === 1 ? '' : 'ën'} in een groep` : '';
        f.innerHTML =
            `<button class="wz-btn" id="wz-annuleer">Annuleren</button>
             <button class="wz-btn" id="wz-opslaan-sluit" ${kan ? '' : 'disabled'} title="${esc(tip)}">Opslaan en sluiten</button>
             <button class="wz-btn wz-btn-primair" id="wz-opslaan-verder" ${kan ? '' : 'disabled'} title="${esc(tip || 'Opslaan en door naar afstand-instellingen')}">Opslaan en verder →</button>`;
        f.querySelector('#wz-annuleer').addEventListener('click', sluitWizard);
        f.querySelector('#wz-opslaan-sluit').addEventListener('click', () => opslaan('sluit'));
        f.querySelector('#wz-opslaan-verder').addEventListener('click', () => opslaan('stap2'));
    }

    // Stap 1 (DC's, subtabs 1a/1b) ↔ 2 (afstand-instellingen) ↔ 3 (programma).
    function zetStap(n) {
        if (!overlay) return;
        if (n === 2 && (!state || !state.groepen.length)) return;   // niks in te stellen
        stap = n;
        const _titel = overlay.querySelector('#wz-titel small');
        if (_titel) _titel.textContent = WZ_TITELS[n] || '';
        overlay.querySelector('#wz-status').innerHTML = statusBanner();   // banner is stap-afhankelijk
        overlay.querySelectorAll('.wz-step').forEach(el =>
            el.classList.toggle('act', +el.dataset.step === n));
        overlay.querySelector('.wz-subtabs').style.display = n === 1 ? '' : 'none';
        overlay.querySelector('#wz-2').classList.toggle('wz-hidden', n !== 2);
        overlay.querySelector('#wz-3').classList.toggle('wz-hidden', n !== 3);
        overlay.querySelector('#wz-4').classList.toggle('wz-hidden', n !== 4);
        if (n === 1) {
            zetTab(subtab);
        } else {
            overlay.querySelector('#wz-1a').classList.add('wz-hidden');
            overlay.querySelector('#wz-1b').classList.add('wz-hidden');
            if (n === 2) renderDeel2();
            else if (n === 3) renderStap3();
            else if (n === 4) { d4Schema = null; d4Sel = new Set(); renderStap4(); }  // vers laden
        }
        updateOpslaanKnop();
    }
    // Deel 3: leid de ronde-blokken af uit de Deel-2-stand. Standaardvolgorde:
    // per afstand-KOLOM (positie) eerst álle series-blokken, dan álle finale-
    // blokken; kolom voor kolom. Binnen een kolom staan de afstanden al in
    // groep-volgorde (jong→oud, dames eerst) omdat d2Afstanden kolom-gewijs
    // itereert. Aantal heats per blok = som over de groepen.
    function d3Blokken() {
        const afs = d2Afstanden();
        const info = afs.map((af, i) => {
            const p = d2GetPar(af, i);
            const series = p.format === 'series';
            let heats = 0, finaleHeats = 0, totalN = 0;
            af.groepen.forEach(gr => {
                const u = d2Uitkomst(gr, p, series);
                if (u.leeg || u.geenHg || u.onoplosbaar) return;
                totalN += gr.N;                              // rijders in deze afstand
                if (u.direct) { finaleHeats += 1; return; }
                heats += (u.series || []).length;
                finaleHeats += 1 + (u.B || []).length;      // 1 A-finale + de B-finales
            });
            return { af, series, heats, finaleHeats, totalN, pos: af.pos ?? i, hG: p.hG };
        });
        const mk = (x, ronde, heats) => ({ afstand: x.af.naam, meters: x.af.value_meters, race_type: x.af.race_type, ronde, heats, totalN: x.totalN, pos: x.pos });
        const blokken = [];
        [...new Set(info.map(x => x.pos))].sort((a, b) => a - b).forEach(pos => {
            const kolom = info.filter(x => x.pos === pos);
            kolom.forEach(x => { if (x.series && x.heats > 0) blokken.push(mk(x, 'heats', x.heats)); });   // eerst alle series
            kolom.forEach(x => { if (x.finaleHeats > 0) blokken.push(mk(x, 'finale', x.finaleHeats)); });  // dan alle finales
        });
        return blokken;
    }

    // Voorstel heat-duur (sec) uit Geert's model: rijtijd bij 30 km/h (8,33 m/s)
    // + klaarzetten/opstel/marge naar start-model. Aan de ruime kant. Aanpasbaar.
    function d3Rond30(sec) { return Math.ceil(Math.max(0, sec) / 30) * 30; }   // altijd naar boven op 30s
    // Staggered start (rijders met interval na elkaar, ~10s apart) — niet
    // betrouwbaar uit de naam: DTT/dual is NIET staggered, tijdrit/slalom WEL.
    function d3StaggeredDefault(b) { const n = (b.afstand || '').toLowerCase(); return /slalom|tijdrit/.test(n) && !/dtt|dual/.test(n); }
    function d3IsStaggered(b) { const k = b.afstand + '|' + b.meters; return d3Staggered[k] != null ? d3Staggered[k] : d3StaggeredDefault(b); }
    function d3HeatDuur(b) {
        const rij = b.meters ? Math.round(b.meters / 8.33) : 0;   // rijtijd bij 30 km/h
        const N = b.heats ? Math.max(1, Math.round((b.totalN || 0) / b.heats)) : Math.max(1, b.totalN || 1);  // rijders per heat
        let sec;
        if (d3IsStaggered(b))                sec = 30 + rij + Math.max(0, N - 1) * 10 + 15;   // staggered, 10s/rijder
        else if (b.race_type === 'sprint') sec = 45 + rij + (b.ronde === 'finale' ? 30 : 0);
        else                               sec = rij + (180 + 5 * N) + 90;                  // pack: rijtijd + opstel(3min+5s/rijder) + jury
        return d3Rond30(sec);
    }
    function d3MmSs(sec) { sec = Math.max(0, Math.round(sec)); return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0'); }
    function d3ParseMmSs(str) {
        str = (str || '').trim(); if (str === '') return null;
        if (str.includes(':')) { const [m, s] = str.split(':'); return (parseInt(m, 10) || 0) * 60 + (parseInt(s, 10) || 0); }
        return parseInt(str, 10) || 0;
    }
    function d3DurVoor(b) { const k = b.afstand + '|' + b.meters + '|' + b.ronde; return d3Dur[k] != null ? d3Dur[k] : d3HeatDuur(b); }
    function d3StartSec() { return d3TijdSec(d3Start.tijd); }
    function d3SecNaarTijd(sec) { sec = Math.round(sec); const h = Math.floor(sec / 3600) % 24, m = Math.floor((sec % 3600) / 60); return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0'); }

    // Concept-programma, gesplitst in PRE (inrijden + voorbereiden — vóór het
    // wedstrijdstart-anker, achterwaarts gerekend) en POST (rondes + pauzes +
    // ceremonie — vanaf het anker). durKey = welke d3Opts-duur het blok stuurt.
    // Inrijd-cluster (1 of 2) van een groep; default: niet-lege groepen jong→oud
    // in tweeën gesplitst (eerste helft = 1).
    function d3Cluster(gi) {
        if (d3InrijdCluster[gi]) return d3InrijdCluster[gi];
        const nietLeeg = state.groepen.map((g, i) => i).filter(i => state.groepen[i].leden.length);
        return nietLeeg.indexOf(gi) < Math.ceil(nietLeeg.length / 2) ? 1 : 2;
    }
    function d3Programma() {
        const pre = [], post = [];
        if (d3Opts.inrijden) {
            // dc-id's per inrijd-blok (primaire dc per groep) → tijdschema
            // vinkt de juiste categorieën aan. Zelfde primair-dc als groepDoelen.
            const doelen = groepDoelen();
            const dcVoorGi = gi => (doelen[gi] ? doelen[gi].dc_id : null);
            const inr = (label, dcs) => pre.push({ type: 'inrijden', duur: d3Opts.inrijdenDuur, durKey: 'inrijdenDuur', label, dcs: [...new Set((dcs || []).filter(Boolean))] });
            if (d3Opts.inrijdenMode === 'groepen') {
                state.groepen.forEach((g, gi) => { if (g.leden.length) inr('Inrijden — ' + g.label, [dcVoorGi(gi)]); });
            } else if (d3Opts.inrijdenMode === 'geclusterd') {
                [1, 2].forEach(c => {
                    const gis = state.groepen.map((g, gi) => gi).filter(gi => state.groepen[gi].leden.length && d3Cluster(gi) === c);
                    if (gis.length) inr('Inrijden cluster ' + c, gis.map(dcVoorGi));
                });
            } else {
                const gis = state.groepen.map((g, gi) => gi).filter(gi => state.groepen[gi].leden.length);
                inr('Inrijden (gezamenlijk)', gis.map(dcVoorGi));
            }
            pre.push({ type: 'pauze', duur: d3Opts.voorbereidenDuur, durKey: 'voorbereidenDuur', label: 'Baan voorbereiden' });
        }
        let lastPos = null;
        d3Blokken().forEach(b => {
            if (d3Opts.pauzeKolom && lastPos != null && b.pos !== lastPos) post.push({ type: 'pauze', duur: d3Opts.pauzeDuur, durKey: 'pauzeDuur', label: 'Pauze' });
            post.push({ type: 'ronde', b });
            lastPos = b.pos;
        });
        if (d3Opts.ceremonie) post.push({ type: 'ceremonie', duur: d3Opts.ceremonieDuur, durKey: 'ceremonieDuur', label: 'Ceremonie' });
        return { pre, post };
    }
    function d3ItemDuur(item) { return item.type === 'ronde' ? d3DurVoor(item.b) * item.b.heats : (item.duur || 0) * 60; }
    const D3_ICON = { inrijden: '🛼', pauze: '☕', ceremonie: '🏅', wedstrijdstart: '🏁' };
    function d3TijdSec(str) { if (!str) return null; const [h, m] = String(str).split(':'); return (parseInt(h, 10) || 0) * 3600 + (parseInt(m, 10) || 0) * 60; }

    // Voeg pauzes samen die < 30 min racing uit elkaar liggen (twee pauzes vlak
    // bij elkaar = onzin). Langste duur wint; lunch-identiteit blijft behouden.
    function d3MergePauzes(items) {
        const DREMPEL = 30 * 60, out = [];
        let lastPauze = -1, racing = 0;
        items.forEach(it => {
            if (it.type === 'pauze') {
                if (lastPauze >= 0 && racing < DREMPEL) {
                    const prev = out[lastPauze], lunch = prev.lunch || it.lunch;
                    out[lastPauze] = {
                        type: 'pauze', duur: Math.max(prev.duur, it.duur), lunch,
                        durKey: lunch ? 'lunchDuur' : prev.durKey,
                        label: lunch ? 'Lunchpauze' : prev.label,
                    };
                    racing = 0; return;   // deze pauze valt samen met de vorige
                }
                out.push({ ...it }); lastPauze = out.length - 1; racing = 0;
            } else { out.push(it); racing += d3ItemDuur(it); }
        });
        return out;
    }

    // Rijen mét begintijden: PRE achterwaarts vanaf het anker, wedstrijdstart-
    // markering op het anker, POST voorwaarts (lunch ingeschoven op tijd-basis,
    // daarna pauzes samengevoegd).
    function d3ProgrammaMetTijden(anchor) {
        const { pre, post } = d3Programma();
        let postItems = post.slice();
        if (d3Opts.lunch && anchor != null) {
            const lunchSec = d3TijdSec(d3Opts.lunchTijd);
            let cur = anchor, idx = -1;
            for (let i = 0; i < postItems.length; i++) {
                if (postItems[i].type === 'ronde' && cur >= lunchSec) { idx = i; break; }
                cur += d3ItemDuur(postItems[i]);
            }
            if (idx >= 0) postItems.splice(idx, 0, { type: 'pauze', duur: d3Opts.lunchDuur, durKey: 'lunchDuur', label: 'Lunchpauze', lunch: true });
        }
        postItems = d3MergePauzes(postItems);

        const rows = [];
        const preTot = pre.reduce((s, it) => s + d3ItemDuur(it), 0);
        let t = anchor != null ? anchor - preTot : null;
        pre.forEach(it => { rows.push({ it, begin: t }); if (t != null) t += d3ItemDuur(it); });
        rows.push({ it: { type: 'wedstrijdstart', label: 'Wedstrijdstart' }, begin: anchor });
        let cur = anchor;
        postItems.forEach(it => { rows.push({ it, begin: cur }); if (cur != null) cur += d3ItemDuur(it); });
        return { rows, eind: cur };
    }

    // Bevries de auto-volgorde tot een bewerkbare lijst (voor slepen/toevoegen).
    function d3Materialiseer() {
        if (!d3Manueel) d3Manueel = d3ProgrammaMetTijden(d3StartSec()).rows.map(r => ({ ...r.it }));
    }
    // Huidige rijen mét tijden: handmatige lijst (PRE vóór, POST na de
    // wedstrijdstart-markering) of anders de auto-afleiding.
    function d3RijenHuidig() {
        const anchor = d3StartSec();
        if (!d3Manueel) return d3ProgrammaMetTijden(anchor);
        const items = d3Manueel;
        let ws = items.findIndex(it => it.type === 'wedstrijdstart'); if (ws < 0) ws = 0;
        const rows = [];
        const pre = items.slice(0, ws);
        const preTot = pre.reduce((s, it) => s + d3ItemDuur(it), 0);
        let t = anchor != null ? anchor - preTot : null;
        pre.forEach(it => { rows.push({ it, begin: t }); if (t != null) t += d3ItemDuur(it); });
        let cur = anchor;
        items.slice(ws).forEach(it => { rows.push({ it, begin: cur }); if (cur != null && it.type !== 'wedstrijdstart') cur += d3ItemDuur(it); });
        return { rows, eind: cur };
    }
    // Valideer een volgorde: races na de wedstrijdstart, finale na z'n series.
    function d3VolgordeOk(items) {
        const ws = items.findIndex(it => it.type === 'wedstrijdstart');
        for (let i = 0; i < items.length; i++)
            if (items[i].type === 'ronde' && ws >= 0 && i < ws) return 'races moeten ná de wedstrijdstart staan';
        const heatsIdx = {};
        items.forEach((it, i) => { if (it.type === 'ronde' && it.b.ronde === 'heats') heatsIdx[it.b.afstand + '|' + it.b.meters] = i; });
        for (let i = 0; i < items.length; i++) {
            const it = items[i];
            if (it.type === 'ronde' && it.b.ronde === 'finale') {
                const hi = heatsIdx[it.b.afstand + '|' + it.b.meters];
                if (hi != null && hi > i) return 'een finale mag niet vóór z\'n series staan';
            }
        }
        return null;
    }

    function renderStap3() {
        const el = overlay.querySelector('#wz-3');
        if (!d3Blokken().length) {
            el.innerHTML = `<p class="wz-hint">Nog geen afstanden met instellingen — vul eerst stap 2 in.</p>`;
            return;
        }
        const rondeLbl = r => r === 'heats' ? 'Series' : 'Finales';
        const o = d3Opts;
        const manueel = !!d3Manueel;
        const optsBar =
            `<div class="wz-d3-opts">
               <label class="wz-d3-opt"><input type="checkbox" ${o.inrijden ? 'checked' : ''} data-opt="inrijden"> Inrijden</label>
               <select data-opt="inrijdenMode" ${o.inrijden ? '' : 'disabled'}>
                 <option value="gezamenlijk" ${o.inrijdenMode === 'gezamenlijk' ? 'selected' : ''}>gezamenlijk</option>
                 <option value="groepen" ${o.inrijdenMode === 'groepen' ? 'selected' : ''}>groepen apart</option>
                 <option value="geclusterd" ${o.inrijdenMode === 'geclusterd' ? 'selected' : ''}>geclusterd (2)</option>
               </select>
               <input type="number" min="0" value="${o.inrijdenDuur}" data-opt="inrijdenDuur" ${o.inrijden ? '' : 'disabled'}><span class="wz-d3-optm">min</span>
               <span class="wz-d3-optsep"></span>
               <label class="wz-d3-opt"><input type="checkbox" ${o.pauzeKolom ? 'checked' : ''} data-opt="pauzeKolom"> Pauze tussen afstand-blokken</label>
               <input type="number" min="0" value="${o.pauzeDuur}" data-opt="pauzeDuur" ${o.pauzeKolom ? '' : 'disabled'}><span class="wz-d3-optm">min</span>
               <span class="wz-d3-optsep"></span>
               <label class="wz-d3-opt"><input type="checkbox" ${o.lunch ? 'checked' : ''} data-opt="lunch"> Lunchpauze om</label>
               <input type="time" value="${esc(o.lunchTijd)}" data-opt="lunchTijd" ${o.lunch ? '' : 'disabled'}>
               <input type="number" min="0" value="${o.lunchDuur}" data-opt="lunchDuur" ${o.lunch ? '' : 'disabled'}><span class="wz-d3-optm">min</span>
               <span class="wz-d3-optsep"></span>
               <label class="wz-d3-opt"><input type="checkbox" ${o.ceremonie ? 'checked' : ''} data-opt="ceremonie"> Ceremonie</label>
               <input type="number" min="0" value="${o.ceremonieDuur}" data-opt="ceremonieDuur" ${o.ceremonie ? '' : 'disabled'}><span class="wz-d3-optm">min</span>
             </div>`;
        const manueelBar =
            `<div class="wz-d3-mbar"><span>🔧 Handmatige volgorde — sleep blokken om te herordenen.</span>
               <button class="wz-d3-mbtn" id="wz-d3-addpauze">+ Pauze</button>
               <button class="wz-d3-mbtn" id="wz-d3-regen">↻ Opnieuw genereren</button></div>`;
        const startBar =
            `<div class="wz-d3-start">
               <label>Startdatum <input type="date" value="${esc(d3Start.datum || '')}" id="wz-d3-datum"></label>
               <label>Starttijd <input type="time" value="${esc(d3Start.tijd || '')}" id="wz-d3-tijd"></label>
             </div>
             ${manueel ? manueelBar : optsBar + `<div class="wz-d3-mbar"><span>Programma naar wens? Voeg desgewenst een pauze in — het schema wordt dan handmatig bewerkbaar.</span><button class="wz-d3-mbtn" id="wz-d3-addpauze">+ Pauze</button></div>`}`;
        const grN = gi => state.groepen[gi].leden.reduce((s, id) => s + (catMap[id].n || 0), 0);
        const clusterPanel = (!manueel && o.inrijden && o.inrijdenMode === 'geclusterd')
            ? `<div class="wz-d3-clusters">${[1, 2].map(c => {
                const gis = state.groepen.map((g, gi) => gi).filter(gi => state.groepen[gi].leden.length && d3Cluster(gi) === c);
                const tot = gis.reduce((s, gi) => s + grN(gi), 0);
                const chips = gis.map(gi => `<button class="wz-d3-chip" data-cluster-gi="${gi}" title="Klik: naar het andere cluster">${esc(state.groepen[gi].label)} <small>${grN(gi)}</small></button>`).join('') || '<span class="wz-d3-cluster-leeg">leeg</span>';
                return `<div class="wz-d3-cluster"><div class="wz-d3-cluster-kop">Inrijd-cluster ${c} <small>${tot} rijders</small></div><div class="wz-d3-cluster-chips">${chips}</div></div>`;
            }).join('')}</div>`
            : '';
        const startSec = d3StartSec();
        const { rows, eind: eindSec } = d3RijenHuidig();
        // Rust per GROEP tussen opeenvolgende races (>30 min gewenst) — dekt
        // series→finale én afstand→afstand. CRUCIAAL: een groep rijdt maar een
        // DEEL van een blok. Binnen een blok rijden de groepen op categorie-
        // volgorde (jong→oud), dus reken het werkelijke race-venster per groep.
        const groepBlok = {};   // afKey → [{gi, series, finale}] in groep-volgorde
        d2Afstanden().forEach((af, i) => {
            const p = d2GetPar(af, i), series = p.format === 'series';
            groepBlok[af.naam + '|' + (af.value_meters ?? '')] = af.groepen.slice().sort((a, b) => a.idx - b.idx).map(gr => {
                const u = d2Uitkomst(gr, p, series);
                if (u.leeg || u.geenHg || u.onoplosbaar) return null;
                return { gi: gr.idx, series: u.direct ? 0 : (u.series || []).length, finale: u.direct ? 1 : (1 + (u.B || []).length) };
            }).filter(Boolean);
        });
        const vensters = {};   // gi → [{start, eind, ri}] werkelijke race-vensters
        rows.forEach((r, ri) => {
            if (r.it.type !== 'ronde' || r.begin == null) return;
            const b = r.it.b, heatDur = d3DurVoor(b);
            let offset = 0;
            (groepBlok[b.afstand + '|' + (b.meters ?? '')] || []).forEach(gb => {
                const aantal = b.ronde === 'heats' ? gb.series : gb.finale;
                if (aantal <= 0) return;
                const start = r.begin + offset * heatDur;
                (vensters[gb.gi] = vensters[gb.gi] || []).push({ start, eind: start + aantal * heatDur, ri });
                offset += aantal;
            });
        });
        const rustWarn = {};   // row-index → [{label, min}] met < 30 min rust
        Object.keys(vensters).forEach(gi => {
            // Sorteer op werkelijke starttijd — na handmatig verschuiven kan de
            // rij-volgorde afwijken van de tijd-volgorde, dus niet op push-volgorde
            // vertrouwen. De ⚠ hangt aan de rij van het láátste (latere) venster.
            const w = vensters[gi].slice().sort((a, b) => a.start - b.start);
            const label = state.groepen[gi].label;
            for (let j = 1; j < w.length; j++) {
                const rust = w[j].start - w[j - 1].eind;
                if (rust < 1800) (rustWarn[w[j].ri] = rustWarn[w[j].ri] || []).push({ label, min: Math.round(rust / 60) });
            }
        });
        const rijen = rows.map((r, i) => {
            const it = r.it;
            const dur = d3ItemDuur(it);
            const begin = r.begin != null ? d3SecNaarTijd(r.begin) : '—';
            if (it.type === 'wedstrijdstart') {
                return `
                <div class="wz-d3-blok wz-d3-rij-start">
                  <span class="wz-d3-nr"></span>
                  <span class="wz-d3-tijd">${begin}</span>
                  <span class="wz-d3-nietronde"><b>${D3_ICON.wedstrijdstart} Wedstrijdstart</b> — eerste race</span>
                </div>`;
            }
            if (it.type !== 'ronde') {
                const dinp = manueel
                    ? `<input type="number" min="0" value="${it.duur}" data-d3item="${i}"> min`
                    : (it.durKey ? `<input type="number" min="0" value="${it.duur}" data-d3duur="${it.durKey}"> min` : `${it.duur} min`);
                const del = manueel ? `<button class="wz-d3-del" data-d3del="${i}" title="Verwijderen">×</button>` : '';
                return `
                <div class="wz-d3-blok wz-d3-rij-${it.type}${it.lunch ? ' wz-d3-rij-lunch' : ''} wz-d3-drag" draggable="true" data-ri="${i}">
                  <span class="wz-d3-nr">⠿</span>
                  <span class="wz-d3-tijd">${begin}</span>
                  <span class="wz-d3-nietronde">${D3_ICON[it.type]} ${esc(it.label)}</span>
                  <label class="wz-d3-nrduur">${dinp}${del}</label>
                </div>`;
            }
            const b = it.b;
            const k = esc(b.afstand + '|' + b.meters + '|' + b.ronde);
            const ak = esc(b.afstand + '|' + b.meters);
            let warn = '';
            if (rustWarn[i]) {
                const txt = rustWarn[i].map(x => `${x.label} ${x.min} min`).join(', ');
                warn = ` <span class="wz-d3-warn" title="Minder dan 30 min rust sinds de vorige race">⚠ rusttijd: ${esc(txt)}</span>`;
            }
            return `
            <div class="wz-d3-blok wz-d3-drag" draggable="true" data-ri="${i}">
              <span class="wz-d3-nr">⠿</span>
              <span class="wz-d3-tijd">${begin}</span>
              <span class="wz-d3-af">${esc(b.afstand)}${b.meters ? ` <small>${b.meters}m</small>` : ''}${warn}</span>
              <span class="wz-d3-ronde wz-d3-ronde-${b.ronde}">${rondeLbl(b.ronde)}</span>
              <span class="wz-d3-heats">${b.heats}×</span>
              <label class="wz-d3-stag" title="Staggered start: rijders met interval na elkaar (tijdrit/slalom)"><input type="checkbox" ${d3IsStaggered(b) ? 'checked' : ''} data-d3stag="${ak}"> staggered</label>
              <label class="wz-d3-hd">heat <input type="text" value="${d3MmSs(d3DurVoor(b))}" data-d3key="${k}" size="4"></label>
              <span class="wz-d3-blokduur">${d3MmSs(dur)}</span>
            </div>`;
        }).join('');
        const eind = eindSec != null ? d3SecNaarTijd(eindSec) : '—';
        el.innerHTML =
            `<p class="wz-hint">Concept-programma. Pas heat-duren en instellingen aan; de begintijden schuiven mee. Sleep een blok om te herordenen (finale kan niet vóór z'n series, races niet vóór de start).</p>
             ${startBar}
             ${clusterPanel}
             <div class="wz-d3-lijst">${rijen}</div>
             <div class="wz-d3-totaal">${startSec != null ? `Klaar rond <b>${eind}</b>` : 'Vul een starttijd in voor de tijden'}</div>`;
        // Slepen (herordenen) — materialiseert de lijst; valideert de nieuwe volgorde.
        let dragRi = null;
        el.querySelectorAll('.wz-d3-drag').forEach(row => {
            row.addEventListener('dragstart', () => { dragRi = +row.dataset.ri; });
            row.addEventListener('dragover', e => { e.preventDefault(); row.classList.add('wz-d3-drop'); });
            row.addEventListener('dragleave', () => row.classList.remove('wz-d3-drop'));
            row.addEventListener('drop', e => {
                e.preventDefault(); row.classList.remove('wz-d3-drop');
                const tgt = +row.dataset.ri;
                if (dragRi == null || tgt === dragRi) return;
                d3Materialiseer();
                const arr = d3Manueel.slice();
                const [moved] = arr.splice(dragRi, 1);
                arr.splice(dragRi < tgt ? tgt - 1 : tgt, 0, moved);
                const fout = d3VolgordeOk(arr);
                if (fout) { toonBevestigDialog('Dat kan niet: ' + fout + '.', 'Volgorde', 'OK', ''); return; }
                d3Manueel = arr; d2MarkWijziging(); renderStap3();
            });
        });
        el.querySelectorAll('[data-d3del]').forEach(b =>
            b.addEventListener('click', () => { d3Materialiseer(); d3Manueel.splice(+b.dataset.d3del, 1); d2MarkWijziging(); renderStap3(); }));
        el.querySelectorAll('input[data-d3item]').forEach(inp =>
            inp.addEventListener('change', () => { d3Materialiseer(); const it = d3Manueel[+inp.dataset.d3item]; if (it) it.duur = Math.max(0, parseInt(inp.value, 10) || 0); d2MarkWijziging(); renderStap3(); }));
        const addP = el.querySelector('#wz-d3-addpauze');
        if (addP) addP.addEventListener('click', () => {
            d3Materialiseer();
            // Bovenaan invoegen (i.p.v. onderaan) — meteen zichtbaar, geen scrollen
            // bij lange lijsten. Sleep 'm daarna naar de gewenste plek.
            d3Manueel.unshift({ type: 'pauze', duur: d3Opts.pauzeDuur, label: 'Pauze' });
            d2MarkWijziging(); renderStap3();
        });
        const regen = el.querySelector('#wz-d3-regen');
        if (regen) regen.addEventListener('click', () => { d3Manueel = null; d2MarkWijziging(); renderStap3(); });
        el.querySelectorAll('[data-cluster-gi]').forEach(b =>
            b.addEventListener('click', () => { const gi = +b.dataset.clusterGi; d3InrijdCluster[gi] = d3Cluster(gi) === 1 ? 2 : 1; d2MarkWijziging(); renderStap3(); }));
        el.querySelector('#wz-d3-datum').addEventListener('change', e => { d3Start.datum = e.target.value || null; d2MarkWijziging(); updateOpslaanKnop(); });
        el.querySelector('#wz-d3-tijd').addEventListener('change', e => { d3Start.tijd = e.target.value || null; d2MarkWijziging(); renderStap3(); });
        el.querySelectorAll('[data-opt]').forEach(inp =>
            inp.addEventListener('change', () => {
                const key = inp.dataset.opt;
                if (inp.type === 'checkbox') d3Opts[key] = inp.checked;
                else if (key === 'inrijdenMode') d3Opts[key] = inp.value;
                else if (key === 'lunchTijd') d3Opts[key] = inp.value;
                else d3Opts[key] = Math.max(0, parseInt(inp.value, 10) || 0);
                d2MarkWijziging(); renderStap3();
            }));
        el.querySelectorAll('input[data-d3duur]').forEach(inp =>
            inp.addEventListener('change', () => { d3Opts[inp.dataset.d3duur] = Math.max(0, parseInt(inp.value, 10) || 0); d2MarkWijziging(); renderStap3(); }));
        el.querySelectorAll('input[data-d3key]').forEach(inp =>
            inp.addEventListener('change', () => {
                const v = d3ParseMmSs(inp.value);
                if (v == null) delete d3Dur[inp.dataset.d3key]; else d3Dur[inp.dataset.d3key] = d3Rond30(v);
                d2MarkWijziging(); renderStap3();
            }));
        el.querySelectorAll('input[data-d3stag]').forEach(inp =>
            inp.addEventListener('change', () => { d3Staggered[inp.dataset.d3stag] = inp.checked; d2MarkWijziging(); renderStap3(); }));
        if (stap === 3) updateOpslaanKnop();   // footer live: Verder → vs Opslaan en verder →
    }

    function zetTab(t) {
        if (!overlay) return;
        subtab = t;
        overlay.querySelector('#wz-tab-1a').classList.toggle('act', t === '1a');
        overlay.querySelector('#wz-tab-1b').classList.toggle('act', t === '1b');
        overlay.querySelector('#wz-1a').classList.toggle('wz-hidden', t !== '1a');
        overlay.querySelector('#wz-1b').classList.toggle('wz-hidden', t !== '1b');
    }
    function toonLaden() {
        overlay.querySelector('#wz-status').innerHTML = '';
        overlay.querySelector('#wz-1a').innerHTML = '<div style="padding:30px;text-align:center;color:#607089">⏳ Laden…</div>';
        overlay.querySelector('#wz-1b').innerHTML = '';
    }
    function toonFout(msg) {
        overlay.querySelector('#wz-1a').innerHTML = `<div style="padding:24px;text-align:center;color:#b71c1c">${esc(msg)}</div>`;
    }

    function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

    function statusBanner() {
        if (locked === 'loting') {
            return `<div class="wz-band" style="background:#fce4e4;border-color:#f5b5b5;color:#b71c1c">🔒 Er is al geloot — <b>alleen-lezen</b>.</div>`;
        }
        if (locked === 'structureel') {
            const heeftProg = wzData && wzData.heeft_programma;
            // Stap 2: waarschuw vooraf dat opslaan het bestaande programma opnieuw
            // genereert (combinaties + handmatige rit-volgorde gaan verloren).
            if (stap === 2) {
                return heeftProg
                    ? `<div class="wz-band" style="background:#fdf1dd;border-color:#e8c98a">⚠ Er is al een programma. Als je hier iets wijzigt en opslaat, wordt het programma opnieuw gegenereerd — A-finale-combinaties en handmatige rit-volgorde gaan verloren.</div>`
                    : '';
            }
            if (stap === 3) {
                return heeftProg
                    ? `<div class="wz-band" style="background:#fdf1dd;border-color:#e8c98a">⚠ Er is al een programma. Wijzig je hier iets (blok verschuiven, duur, pauze) en sla je op, dan wordt het opnieuw gegenereerd — A-finale-combinaties en handmatige rit-volgorde gaan verloren.</div>`
                    : '';
            }
            // Stap 1: indeling (groepen + afstanden) ligt vast.
            return heeftProg
                ? `<div class="wz-band">⚠ De indeling (groepen + afstanden) ligt vast — er is al een programma. Wil je de indeling wijzigen, wis het programma dan eerst in het Tijdschema.</div>`
                : `<div class="wz-band">⚠ De indeling (groepen + afstanden) ligt vast — er zijn al afstand-instellingen gemaakt. Wil je de indeling wijzigen, verwijder die dan eerst in het Tijdschema.</div>`;
        }
        // Geen bak-melding: of de bak leeg moet, zegt de Opslaan-knop zelf
        // (disabled + tooltip). De uitleg over de bak staat al in de 1a-hint.
        return '';
    }

    // ── Kaarten ──────────────────────────────────────────────────────────────
    function telLabels(c, kort) {
        let out = '';
        if (c.res > 0) out += `<span class="wz-res" title="reserves (status 1/5 met reserve-nr)">+${c.res}${kort ? '' : ' res'}</span>`;
        if (c.na  > 0) out += `<span class="wz-na"  title="afgemeld/afwezig (status 2, 3, 4)">${c.na}${kort ? '' : ' n.a.'}</span>`;
        if (c.nb  > 0) out += `<span class="wz-nb"  title="niet bevestigd (status 0)">${c.nb}${kort ? '' : ' nb'}</span>`;
        return out;
    }
    function catNaam(c) { return c.feedCombined ? c.code : (c.dcName || c.code); }
    function catCard(id) {
        const c = catMap[id]; const leeg = c.n === 0 ? ' wz-leeg' : '';
        const chip = c.feedCombined ? '' : `<span class="wz-code">${esc(c.code)}</span>`;
        return `<div class="wz-cat${locked ? ' wz-vast' : ''}" ${locked ? '' : 'draggable="true"'} data-id="${esc(id)}">
            <div class="wz-n${leeg}">${c.n}</div>
            <div class="wz-meta"><div class="wz-nm">${esc(catNaam(c))}</div>${chip}${telLabels(c, false)}</div>
          </div>`;
    }
    function lidRow(id) {
        const c = catMap[id]; const leeg = c.n === 0 ? ' wz-leeg' : '';
        return `<div class="wz-lid${locked ? ' wz-vast' : ''}" ${locked ? '' : 'draggable="true"'} data-id="${esc(id)}">
            <div class="wz-ln${leeg}">${c.n}</div><div class="wz-lnm">${esc(catNaam(c))}</div><span class="wz-lcode">${esc(c.code)}</span>${telLabels(c, true)}
          </div>`;
    }
    function groepCard(g, idx) {
        const rij = g.leden.reduce((s, id) => s + catMap[id].n, 0);
        const res = g.leden.reduce((s, id) => s + catMap[id].res, 0);
        const totLbl = `${rij} <small>rijders${res ? ` +${res} res` : ''}</small>`;
        const leden = g.leden.map(lidRow).join('') || '<div style="color:#93a1b3;font-size:.8rem;padding:6px">sleep hier…</div>';
        const ontbind = locked ? '' : `<div class="wz-verwijder"><button data-ontbind="${idx}">groep ontbinden ✕</button></div>`;
        return `<div class="wz-groep" data-idx="${idx}">
            <div class="wz-groep-head"><input value="${esc(g.label)}" data-idx="${idx}" ${locked === 'loting' ? 'disabled' : ''}><span class="wz-tot">${totLbl}</span></div>
            <div class="wz-leden">${leden}</div>
            ${ontbind}
          </div>`;
    }

    // Pool: feed-gecombineerde categorieën per DC in een stippellijn-kader.
    // Rendert de bak in de (gesorteerde) pool-volgorde. Opeenvolgende categorieën
    // uit dezelfde feed-DC krijgen samen een stippellijn-kader — op hun plek in
    // de sortering, niet apart onderaan.
    function poolHtml() {
        if (!state.pool.length) return '<div style="color:#93a1b3;font-size:.8rem;padding:10px">bak is leeg</div>';
        let html = '', i = 0;
        while (i < state.pool.length) {
            const c = catMap[state.pool[i]];
            if (c.feedCombined) {
                const dcId = c.dcId; const run = [];
                while (i < state.pool.length && catMap[state.pool[i]].feedCombined && catMap[state.pool[i]].dcId === dcId) {
                    run.push(state.pool[i]); i++;
                }
                html += `<div class="wz-feedgroep"><div class="wz-feedgroep-lbl">feed-combinatie: ${esc(catMap[run[0]].dcName)}</div>${run.map(catCard).join('')}</div>`;
            } else {
                html += catCard(state.pool[i]); i++;
            }
        }
        return html;
    }

    function render() {
        if (!overlay) return;
        overlay.querySelector('#wz-status').innerHTML = statusBanner();
        const nieuwDrop = locked ? '' : `<div class="wz-leeg-drop" data-nieuw="1">＋ Nieuwe groep</div>`;
        const groepenHtml = state.groepen.map(groepCard).join('') + nieuwDrop;

        // De wizard toont de indeling zoals in de database (elke DC een groep, mét
        // afstanden). Bij eerste opening even benoemen dat dit uit de import komt;
        // de "Groepen verwijderen"-knop blijft altijd staan zodat een bediener
        // vanaf 0 kan opbouwen.
        const eersteOpen = !locked && wzData && !wzData.wizard_dc_gedaan && state.groepen.length;
        const infoRegel = eersteOpen
            ? `<p class="wz-info">ℹ️ Dit is de indeling uit de import. Pas aan wat nodig is, of gebruik <b>Groepen verwijderen</b> om zelf op te bouwen.</p>`
            : '';
        const leegKnop = (!locked && state.groepen.length)
            ? `<button type="button" id="wz-leeg" class="wz-btn wz-btn-leeg">Groepen verwijderen</button>`
            : '';

        overlay.querySelector('#wz-1a').innerHTML = `
          ${infoRegel}
          <div class="wz-1a-kop">
            <p class="wz-hint">↔ Stel een DC samen: schuif één of meer categorieën in een groep.</p>
            ${leegKnop}
          </div>
          <div class="wz-grid1a">
            <div class="wz-paneel">
              <h3>Bak · nog niet ingedeeld</h3>
              <div class="wz-sub">Getal = deelnemers (bevestigd, geen reserve)</div>
              <div class="wz-pool" data-drop="pool">${poolHtml()}</div>
            </div>
            <div><div class="wz-groepen">${groepenHtml}</div></div>
          </div>`;

        // Alle groepen leegmaken → categorieën terug in de bak, groepen weg. De
        // afstand-toewijzingen zitten per groep, dus die verdwijnen mee (opnieuw
        // vanaf 0). De catalogus (state.catalog) blijft, zodat 1b ze weer kent.
        overlay.querySelector('#wz-leeg')?.addEventListener('click', () => {
            state.pool = state.pool.concat(state.groepen.flatMap(g => g.leden));
            state.groepen = [];
            render();
        });

        renderB();
        if (!locked) wireDnd();
        else wireLabels();
        updateOpslaanKnop();
    }

    // ── 1b ───────────────────────────────────────────────────────────────────
    function typeKlasse(rt) {
        if (rt === 'inline' || rt === 'afvalkoers' || rt === 'puntenkoers') return 'pack';
        return 'sprint';
    }
    // Labels gelijk aan de Import DC-editor (js/import.js) voor consistentie.
    function typeLabel(rt) {
        return ({ sprint: 'Sprint', inline: 'Inline', puntenkoers: 'Puntenkoers', afvalkoers: 'Afvalkoers' })[rt] || rt;
    }
    function afstandById(id) { return state.catalog.find(d => d.id === id); }

    // Guard: binnen één groep moet elke afstand-NAAM uniek zijn — downstream
    // koppelt op (dc_id, afstand_naam). Dezelfde naam met andere meters mag dus
    // NIET samen in één groep (bv. "Sprint" 300m + "Sprint" 500m). Over groepen
    // heen mag dezelfde naam wél.
    function afNaamBotstInGroep(g, aid) {
        const nieuw = afstandById(aid); if (!nieuw) return null;
        const conflict = (g.afstanden || []).some(x => x !== aid && (afstandById(x) || {}).name === nieuw.name);
        return conflict ? nieuw.name : null;
    }
    function meldNaamBotsing(naam) {
        toonOpslaanMelding(`Deze groep heeft al een afstand "${naam}". Binnen één groep moet elke afstand een unieke naam hebben (geef bv. de meters mee: "${naam} 300m").`, false);
    }

    // Zoek een afstand met exact deze naam+meters+type, of maak 'm aan (zodat
    // hij ook in de lijst/bak verschijnt). Geeft het id terug.
    function findOrCreateAfstand(naam, metersRaw, type) {
        naam = (naam || '').trim();
        const v = (metersRaw == null ? '' : String(metersRaw)).trim();
        const m = v === '' ? null : parseInt(v, 10);
        const mNorm = (m == null || isNaN(m)) ? null : m;
        let d = state.catalog.find(x => (x.name || '') === naam && x.value_meters === mNorm && x.race_type === type);
        if (!d) { d = { id: ++afstandSeq, name: naam, race_type: type, value_meters: mNorm }; state.catalog.push(d); }
        return d.id;
    }

    function isEditing(gi, id) {
        if (!editTarget) return false;
        return gi == null
            ? (editTarget.mode === 'bak' && editTarget.id === id)
            : (editTarget.mode === 'groep' && editTarget.gi === gi && editTarget.aid === id);
    }
    // Compacte pil. In de bak is de hele pil sleepbaar. ✎ = bewerken, 🗑/✕ = weg.
    function afstandPil(d, gi, pos) {
        const inGroep = gi != null;
        const posB = (inGroep && pos != null) ? `<span class="wz-pos" title="afstand ${pos + 1}">${pos + 1}</span>` : '';
        const m = d.value_meters != null ? ` <span class="wz-m">${esc(d.value_meters)} m</span>` : '';
        const badge = `<span class="wz-type ${typeKlasse(d.race_type)}">${esc(typeLabel(d.race_type))}</span>`;
        if (locked) return `<span class="wz-achip">${posB}${badge} ${esc(d.name || '(naamloos)')}${m}</span>`;
        const pen = `<button class="wz-pil-btn wz-pen" title="bewerken" draggable="false">✎</button>`;
        const actie = inGroep
            ? `<button class="wz-pil-btn wz-del-groep" title="uit groep halen" draggable="false">✕</button>`
            : `<button class="wz-pil-btn wz-del-cat" title="verwijderen uit lijst" draggable="false">🗑</button>`;
        const dataAttr = inGroep ? `data-gi="${gi}" data-aid="${d.id}"` : `data-id="${d.id}"`;
        return `<span class="wz-achip wz-adrag" draggable="true" ${dataAttr}>${posB}${badge} ${esc(d.name || '(naamloos)')}${m} ${pen}${actie}</span>`;
    }
    // Bewerk-formulier (na ✎). Sluit bij focus-weg of ✓.
    function afstandEdit(d, gi) {
        const opts = ['sprint', 'inline', 'puntenkoers', 'afvalkoers']
            .map(rt => `<option value="${rt}"${d.race_type === rt ? ' selected' : ''}>${esc(typeLabel(rt))}</option>`).join('');
        const dataAttr = gi != null ? `data-gi="${gi}" data-aid="${d.id}"` : `data-id="${d.id}"`;
        return `<div class="wz-af-kaart wz-af-editing" ${dataAttr}>
            <div class="wz-af-top">
              <input class="wz-af-naam" value="${esc(d.name || '')}" placeholder="naam (bv. Sprint 500m)" maxlength="100">
              <button class="wz-af-klaar" title="klaar">✓</button>
            </div>
            <div class="wz-af-bot">
              <input type="number" class="wz-af-m" value="${d.value_meters != null ? esc(d.value_meters) : ''}" placeholder="meters" min="0">
              <select class="wz-af-type">${opts}</select>
            </div>
          </div>`;
    }
    function afstandKaart(d, gi, pos) {
        return isEditing(gi, d.id) ? afstandEdit(d, gi) : afstandPil(d, gi, pos);
    }
    function groepenHtml1b() {
        return state.groepen.map((g, gi) => {
            const rij = g.leden.reduce((s, id) => s + catMap[id].n, 0);
            const items = (g.afstanden || []).map((aid, i) => { const d = afstandById(aid); return d ? afstandKaart(d, gi, i) : ''; }).join('')
                || `<span style="color:#93a1b3;font-size:.8rem">${locked ? 'geen afstanden' : 'sleep afstanden hierheen'}</span>`;
            return `<div class="wz-groep" data-bgi="${gi}">
                <div class="wz-groep-head"><span class="wz-bkop">${esc(g.label)}</span><span class="wz-tot">${rij} <small>rijders</small></span></div>
                <div class="wz-bafstanden">${items}</div>
              </div>`;
        }).join('') || '<div style="color:#93a1b3;font-size:.85rem;padding:10px">Nog geen groepen — deel eerst in bij 1a.</div>';
    }
    function renderB() {
        const editorHtml = state.catalog.map(d => afstandKaart(d, null)).join('')
            || '<div style="color:#93a1b3;font-size:.8rem;padding:6px">Nog geen afstanden</div>';
        const addBtn = locked ? '' : `<button class="wz-btn wz-af-add" id="wz-af-add" style="margin-top:10px">＋ Nieuwe afstand</button>`;
        overlay.querySelector('#wz-1b').innerHTML = `
          <p class="wz-hint">↔ Sleep afstanden naar een groep. Een afstand in een groep aanpassen maakt een nieuwe variant (andere groepen houden de originele).</p>
          <div class="wz-grid1a">
            <div class="wz-paneel">
              <h3>Afstanden</h3>
              <div class="wz-afstand-editor">${editorHtml}</div>
              ${addBtn}
            </div>
            <div><div class="wz-groepen">${groepenHtml1b()}</div></div>
          </div>`;
        wireB();
    }
    function renderGroepen1b() {
        const cont = overlay && overlay.querySelector('#wz-1b .wz-groepen');
        if (!cont) return;
        cont.innerHTML = groepenHtml1b();
        wireGroepen1b(cont);
    }
    // Bewerk-kaart afsluiten (bak: in-place; groep: find-or-create) + opruimen leeg.
    function commitEdit(card) {
        const naam = card.querySelector('.wz-af-naam').value;
        const mtr  = card.querySelector('.wz-af-m').value;
        const type = card.querySelector('.wz-af-type').value;
        editTarget = null;
        if (card.hasAttribute('data-id')) {
            const id = +card.getAttribute('data-id'); const d = afstandById(id);
            if (d) {
                const v = (mtr || '').trim();
                d.name = (naam || '').trim(); d.value_meters = v === '' ? null : parseInt(v, 10); d.race_type = type;
                if (d.name === '' && d.value_meters == null) { // leeg → opruimen
                    state.catalog = state.catalog.filter(x => x.id !== id);
                    state.groepen.forEach(g => g.afstanden = (g.afstanden || []).filter(a => a !== id));
                }
            }
        } else {
            const gi = +card.getAttribute('data-gi'); const oldId = +card.getAttribute('data-aid');
            if ((naam || '').trim() !== '' || (mtr || '').trim() !== '') {
                const newId = findOrCreateAfstand(naam, mtr, type);
                if (newId !== oldId) {
                    const g = state.groepen[gi]; const idx = g.afstanden.indexOf(oldId);
                    if (idx >= 0) { if (g.afstanden.includes(newId)) g.afstanden.splice(idx, 1); else g.afstanden[idx] = newId; }
                }
            }
        }
        renderB();
    }
    function wireEdit(card) {
        card.addEventListener('focusout', e => { if (!card.contains(e.relatedTarget)) commitEdit(card); });
        const klaar = card.querySelector('.wz-af-klaar');
        if (klaar) klaar.addEventListener('click', () => commitEdit(card));
    }
    function startEdit(target) {
        editTarget = target;
        renderB();
        const inp = overlay.querySelector('.wz-af-editing .wz-af-naam');
        if (inp) inp.focus();
    }
    function wireGroepen1b(scope) {
        if (locked) return;
        scope.querySelectorAll('.wz-af-editing[data-gi]').forEach(wireEdit);
        scope.querySelectorAll('.wz-achip[data-gi] .wz-pen').forEach(b => b.addEventListener('click', ev => {
            ev.stopPropagation();
            const p = b.closest('[data-gi]');
            startEdit({ mode: 'groep', gi: +p.getAttribute('data-gi'), aid: +p.getAttribute('data-aid') });
        }));
        scope.querySelectorAll('.wz-del-groep').forEach(b => b.addEventListener('click', ev => {
            ev.stopPropagation();
            const p = b.closest('[data-gi]'); const gi = +p.getAttribute('data-gi'); const aid = +p.getAttribute('data-aid');
            state.groepen[gi].afstanden = (state.groepen[gi].afstanden || []).filter(a => a !== aid);
            renderGroepen1b();
        }));
        // Groep-pillen sleepbaar: naar andere groep = verplaatsen, naar de lijst = eruit,
        // op een andere pil in dezelfde groep = volgorde wijzigen (ervoor invoegen).
        scope.querySelectorAll('.wz-adrag[data-gi]').forEach(el => {
            el.addEventListener('dragstart', e => { dragAfstand = +el.getAttribute('data-aid'); dragFromGi = +el.getAttribute('data-gi'); e.dataTransfer.effectAllowed = 'move'; setTimeout(() => el.classList.add('wz-drag'), 0); });
            el.addEventListener('dragend', () => el.classList.remove('wz-drag'));
            el.addEventListener('dragover', e => { e.preventDefault(); e.stopPropagation(); el.classList.add('wz-drop-voor'); });
            el.addEventListener('dragleave', () => el.classList.remove('wz-drop-voor'));
            el.addEventListener('drop', e => {
                e.preventDefault(); e.stopPropagation(); el.classList.remove('wz-drop-voor');
                if (dragAfstand == null) return;
                const gi = +el.getAttribute('data-gi'); const targetId = +el.getAttribute('data-aid');
                if (targetId === dragAfstand) { dragAfstand = null; dragFromGi = null; return; }
                const g = state.groepen[gi]; g.afstanden = g.afstanden || [];
                const bots1 = afNaamBotstInGroep(g, dragAfstand);
                if (bots1) { meldNaamBotsing(bots1); dragAfstand = null; dragFromGi = null; return; }
                if (dragFromGi != null && dragFromGi !== gi) state.groepen[dragFromGi].afstanden = (state.groepen[dragFromGi].afstanden || []).filter(a => a !== dragAfstand);
                const cur = g.afstanden.indexOf(dragAfstand); if (cur >= 0) g.afstanden.splice(cur, 1);
                const idx = g.afstanden.indexOf(targetId);
                g.afstanden.splice(idx < 0 ? g.afstanden.length : idx, 0, dragAfstand);
                dragAfstand = null; dragFromGi = null; renderGroepen1b();
            });
        });
        scope.querySelectorAll('[data-bgi]').forEach(z => {
            z.addEventListener('dragover', e => { e.preventDefault(); z.classList.add('wz-over'); });
            z.addEventListener('dragleave', () => z.classList.remove('wz-over'));
            z.addEventListener('drop', e => {
                e.preventDefault(); z.classList.remove('wz-over');
                if (dragAfstand == null) return;
                const doel = +z.getAttribute('data-bgi');
                const g = state.groepen[doel]; g.afstanden = g.afstanden || [];
                const bots2 = afNaamBotstInGroep(g, dragAfstand);
                if (bots2) { meldNaamBotsing(bots2); dragAfstand = null; dragFromGi = null; return; }
                if (!g.afstanden.includes(dragAfstand)) g.afstanden.push(dragAfstand);
                if (dragFromGi != null && dragFromGi !== doel) {
                    state.groepen[dragFromGi].afstanden = (state.groepen[dragFromGi].afstanden || []).filter(a => a !== dragAfstand);
                }
                dragAfstand = null; dragFromGi = null; renderGroepen1b();
            });
        });
    }
    function wireB() {
        if (!overlay || locked) return;
        const root = overlay.querySelector('#wz-1b');
        root.querySelectorAll('.wz-af-editing[data-id]').forEach(wireEdit);
        root.querySelectorAll('.wz-adrag[data-id] .wz-pen').forEach(b => b.addEventListener('click', ev => {
            ev.stopPropagation();
            startEdit({ mode: 'bak', id: +b.closest('[data-id]').getAttribute('data-id') });
        }));
        root.querySelectorAll('.wz-del-cat').forEach(b => b.addEventListener('click', ev => {
            ev.stopPropagation();
            const id = +b.closest('[data-id]').getAttribute('data-id');
            state.catalog = state.catalog.filter(d => d.id !== id);
            state.groepen.forEach(g => g.afstanden = (g.afstanden || []).filter(a => a !== id));
            renderB();
        }));
        const addBtn = root.querySelector('#wz-af-add');
        if (addBtn) addBtn.addEventListener('click', () => {
            const d = { id: ++afstandSeq, name: '', race_type: 'sprint', value_meters: null };
            state.catalog.push(d);
            startEdit({ mode: 'bak', id: d.id });
        });
        root.querySelectorAll('.wz-adrag[data-id]').forEach(el => {
            el.addEventListener('dragstart', e => { dragAfstand = +el.getAttribute('data-id'); dragFromGi = null; e.dataTransfer.effectAllowed = 'copy'; setTimeout(() => el.classList.add('wz-drag'), 0); });
            el.addEventListener('dragend', () => el.classList.remove('wz-drag'));
        });
        // De afstand-lijst is ook een dropzone: een groep-pil hierheen slepen haalt
        // 'm uit die groep (de afstand zelf blijft in de lijst bestaan).
        const bak = root.querySelector('.wz-afstand-editor');
        if (bak) {
            bak.addEventListener('dragover', e => { if (dragFromGi != null) { e.preventDefault(); bak.classList.add('wz-over'); } });
            bak.addEventListener('dragleave', () => bak.classList.remove('wz-over'));
            bak.addEventListener('drop', e => {
                e.preventDefault(); bak.classList.remove('wz-over');
                if (dragAfstand != null && dragFromGi != null) {
                    state.groepen[dragFromGi].afstanden = (state.groepen[dragFromGi].afstanden || []).filter(a => a !== dragAfstand);
                }
                dragAfstand = null; dragFromGi = null; renderGroepen1b();
            });
        }
        wireGroepen1b(root);
    }

    // ── Drag & drop ──────────────────────────────────────────────────────────
    function wireLabels() {
        overlay.querySelectorAll('#wz-1a .wz-groep-head input').forEach(inp => {
            inp.addEventListener('change', () => {
                const g = state.groepen[+inp.getAttribute('data-idx')];
                g.label = inp.value; g.auto = false; renderB();
            });
        });
    }
    function wireDnd() {
        const root = overlay.querySelector('#wz-1a');
        root.querySelectorAll('[data-id]').forEach(el => {
            el.addEventListener('dragstart', e => { dragId = el.getAttribute('data-id'); e.dataTransfer.effectAllowed = 'move'; setTimeout(() => el.classList.add('wz-drag'), 0); });
            el.addEventListener('dragend', () => el.classList.remove('wz-drag'));
        });
        const zones = [];
        root.querySelectorAll('.wz-pool').forEach(z => zones.push([z, 'pool']));
        root.querySelectorAll('.wz-groep').forEach(z => zones.push([z, 'groep:' + z.getAttribute('data-idx')]));
        root.querySelectorAll('.wz-leeg-drop').forEach(z => zones.push([z, 'nieuw']));
        zones.forEach(([z, doel]) => {
            z.addEventListener('dragover', e => { e.preventDefault(); z.classList.add('wz-over'); });
            z.addEventListener('dragleave', () => z.classList.remove('wz-over'));
            z.addEventListener('drop', e => { e.preventDefault(); z.classList.remove('wz-over'); dropNaar(doel); });
        });
        wireLabels();
        root.querySelectorAll('[data-ontbind]').forEach(b => b.addEventListener('click', () => ontbind(+b.getAttribute('data-ontbind'))));
    }
    function verwijderUit(id) {
        state.pool = state.pool.filter(x => x !== id);
        state.groepen.forEach(g => g.leden = g.leden.filter(x => x !== id));
    }
    function dropNaar(doel) {
        if (!dragId) return;
        verwijderUit(dragId);
        if (doel === 'pool') state.pool.push(dragId);
        else if (doel === 'nieuw') {
            const dcId = catMap[dragId].dcId;
            const namen = [...new Set(((wzData.distances_per_dc || {})[dcId] || []).map(d => d.name))];
            const ids = namen.map(nm => (state.catalog.find(d => d.name === nm) || {}).id).filter(Boolean);
            state.groepen.push({ label: joinLabel([dragId]), leden: [dragId], auto: true, afstanden: ids });
        }
        else if (doel.startsWith('groep:')) state.groepen[+doel.slice(6)].leden.push(dragId);
        state.groepen = state.groepen.filter(g => g.leden.length);
        herlabel();
        dragId = null;
        render();
    }
    function ontbind(idx) {
        state.pool.push(...state.groepen[idx].leden);
        state.groepen.splice(idx, 1);
        render();
    }

    // ── Opslaan (increment 3) ────────────────────────────────────────────────
    // ── Deel 2 · afstand-instellingen ────────────────────────────────────────
    // Afleiding series + A/B-finales per groep (full-final). Zie ook de
    // ontwerp-notities: parameters staan per afstand, de aantallen volgen per
    // groep uit het deelnemersaantal.
    // Series-aantal volgt uit MAX-PER-SERIE: ceil(N / mS), daarna zo evenwichtig
    // mogelijk verdeeld (grootste eerst). Bv. 33 bij max 5 → 7 series [5,5,5,5,5,4,4].
    function d2VerdeelSeries(N, mS) {
        if (N <= 0 || !mS) return [];
        const nS = Math.ceil(N / mS);
        const base = Math.floor(N / nS), extra = N - base * nS;
        return Array.from({ length: nS }, (_, i) => base + (i < extra ? 1 : 0));
    }
    function d2VerdeelB(rest, hG, fA, minB, laatsteB) {
        if (rest === 0) return [];
        if (rest < minB) return null;                        // eigen finale te klein
        const nBfull = Math.floor(rest / hG);
        const L = rest - nBfull * hG;
        const B = Array(nBfull).fill(hG);
        if (L === 0) return B;
        if (L >= minB) { laatsteB ? B.push(L) : B.unshift(L); return B; }        // L = eigen (kleinste) finale
        if (L <= fA && nBfull >= 1) { laatsteB ? (B[B.length - 1] += L) : (B[0] += L); return B; }  // rest bijmengen
        return null;
    }
    function d2Los(N, p, lb) {
        const hG = p.hG, fA = p.fA || 0, minB = p.minB || 1;
        if (lb == null) lb = p.laatsteB;
        const maxHeat = hG + fA;
        if (N <= 0) return { leeg: true };
        if (N <= maxHeat) return { A: N, B: [], alleenStart: true, standaard: N <= hG };
        for (let A = hG; A <= hG + fA; A++) {                // standaard-A eerst, dan oprekken
            const B = d2VerdeelB(N - A, hG, fA, minB, lb);
            if (B) return { A, B, alleenStart: false, standaard: A === hG };
        }
        return { onoplosbaar: true };
    }

    // Unieke afstand (naam+meters+type) → { naam, race_type, value_meters,
    // groepen:[{idx, label, N}] }. Op naam+meters, zodat "Sprint" 300m en 500m
    // twee losse afstand-kaarten met eigen instellingen zijn.
    // Volgorde = KOLOM-gewijs: eerst alle 1e afstanden van de groepen (in
    // categorie-/groep-volgorde), dan alle 2e, dan alle 3e… i.p.v. per groep.
    function d2Afstanden() {
        const byId = {};
        (state.catalog || []).forEach(d => { byId[d.id] = d; });
        const key = d => `${d.name}${d.value_meters ?? ''}${d.race_type ?? ''}`;
        const groepN = state.groepen.map(g => g.leden.reduce((s, id) => s + (catMap[id].n || 0), 0));
        const map = new Map();
        const maxLen = state.groepen.reduce((m, g) => Math.max(m, (g.afstanden || []).length), 0);
        for (let pos = 0; pos < maxLen; pos++) {
            state.groepen.forEach((g, idx) => {
                const aid = (g.afstanden || [])[pos];
                if (aid == null) return;
                const d = byId[aid]; if (!d) return;
                const k = key(d);
                if (!map.has(k)) map.set(k, { naam: d.name, race_type: d.race_type, value_meters: d.value_meters, pos, groepen: [] });
                map.get(k).groepen.push({ idx, label: g.label, N: groepN[idx] });
            });
        }
        return Array.from(map.values());
    }
    // Heat-grootte-default is afstand-afhankelijk (200m/100m→2, 500m→4, 1000m→8);
    // buiten die bekende afstanden bewust LEEG (null) — dan moet de operator zelf
    // invullen i.p.v. een fout getal te erven.
    function d2Defaults(af) {
        const m = af.value_meters;
        let hG = null;
        if (m === 100 || m === 200) hG = 2;
        else if (m === 500)         hG = 4;
        else if (m === 1000)        hG = 8;
        // startModus: bij 1 serie + alleen A-finale — 'a-finale' = A-finale is
        // eindstand (series_alleen_startvolgorde=1); 'optellen' = serie + A-finale
        // plek-punten opgeteld, minste wint, A-finale = tie-break (=0).
        return { format: 'series', hG, mS: hG ? hG + 1 : null, fA: 1, minB: 3, q: 0, laatsteB: true, startModus: 'a-finale' };
    }
    // Params per afstand-index (niet op naam — twee afstanden mogen dezelfde naam
    // hebben, bv. "Sprint" 300m/500m). De index is stabiel binnen een Deel 2-sessie.
    function d2GetPar(af, i) { if (!d2Par[i]) d2Par[i] = d2Defaults(af); return d2Par[i]; }

    // Reconstrueer de opgeslagen Deel-2-stand uit de DB (vergrendel-modus). De
    // rekenknoppen (max-per-serie/afwijking/min-B) zijn NIET opgeslagen — die
    // blijven default en doen alleen mee bij "Opnieuw afleiden". Per groep zetten
    // we de opgeslagen A/B/series als override, per afstand hG + laatste-B.
    function reconstrueerDeel2(data) {
        d2Locked = !!data.heeft_cat_config;
        if (!d2Locked) return;
        // Key op (dc, naam, meters) zodat "Sprint" 300m/500m los blijven;
        // afCfgNaam is de naam-only fallback (oude config zonder value_meters).
        const afCfg = {}, afCfgNaam = {}, catCfg = {};
        (data.d2_afstand_config || []).forEach(a => {
            afCfg[a.dc_id + '|' + a.afstand_naam + '|' + (a.value_meters ?? '')] = a;
            const nk = a.dc_id + '|' + a.afstand_naam;
            if (!(nk in afCfgNaam)) afCfgNaam[nk] = a;
        });
        (data.d2_cat_config || []).forEach(c => { catCfg[c.dc_id + '|' + c.distance_id] = c; });
        const doelen = groepDoelen();
        d2Afstanden().forEach((af, i) => {
            const p = d2GetPar(af, i);   // startModus-default 'a-finale' uit d2Defaults blijft
            p.unlocked = false;
            p.ov = {};
            let anySeries = false;
            af.groepen.forEach(gr => {
                const doel = doelen[gr.idx]; if (!doel) return;
                const ac = afCfg[doel.dc_id + '|' + af.naam + '|' + (af.value_meters ?? '')]
                         ?? afCfgNaam[doel.dc_id + '|' + af.naam];
                if (ac) { p.hG = ac.finale_heat_grootte; p.laatsteB = !!ac.laatste_b_grootste; }
                const distId = d2DistanceId(doel.dc_id, doel.split_group, af.naam, af.value_meters, af.race_type);
                const cc = distId ? catCfg[doel.dc_id + '|' + distId] : null;
                if (!cc) return;
                if (cc.heeft_heats) {
                    anySeries = true;
                    const ovObj = {
                        A: cc.finale_a_grootte, bAantal: cc.finale_b_heats, heats: cc.heats_aantal,
                        q: cc.heats_q_heat || 0,
                    };
                    if (cc.laatste_b_grootste != null) ovObj.laatsteB = !!cc.laatste_b_grootste;
                    // startModus alleen zinvol/onthouden bij 1 serie; anders default
                    // (a-finale) laten gelden i.p.v. een betekenisloze 0 vast te leggen.
                    if (cc.heats_aantal === 1) ovObj.startModus = cc.series_alleen_startvolgorde ? 'a-finale' : 'optellen';
                    p.ov[gr.idx] = ovObj;
                }
            });
            p.format = anySeries ? 'series' : 'direct';
        });
    }

    function renderDeel2() {
        const el = overlay.querySelector('#wz-2');
        const afs = d2Afstanden();
        let html = `
          <div class="wz-d2-sys">
            <span class="wz-d2-syslbl">Systeem</span>
            <label class="wz-d2-radio"><input type="radio" name="wz-sys" value="full-final" ${d2Sys === 'full-final' ? 'checked' : ''}> Full-final <small>series → A-finale + B-finales</small></label>
            <label class="wz-d2-radio wz-dis"><input type="radio" name="wz-sys" value="internationaal-nieuw" disabled ${d2Sys === 'internationaal-nieuw' ? 'checked' : ''}> Internationaal <small>kwart-/halve finale — binnenkort</small></label>
          </div>`;
        if (!afs.length) {
            html += `<p class="wz-hint">Nog geen afstanden — stel eerst in Deel 1 (tab 1b) per groep de afstanden samen.</p>`;
            el.innerHTML = html; return;
        }
        html += afs.map((af, i) => d2Kaart(af, i)).join('');
        el.innerHTML = html;
        wireDeel2();
        if (stap === 2) updateOpslaanKnop();   // footer live: Opslaan vs Verder
    }

    function d2Kaart(af, i) {
        const p = d2GetPar(af, i);
        const series = p.format === 'series';
        const grendel = d2Locked && !p.unlocked;      // bulk op slot, alleen ✎
        const meters = af.value_meters ? `<span class="wz-d2-m">${af.value_meters}m</span>` : '';
        const rijen = af.groepen.map(gr => d2Rij(gr, p, series, i, grendel)).join('');
        const dis = grendel ? 'disabled' : '';
        const rechts = grendel
            ? `<button class="wz-d2-herleid" data-ai="${i}" title="Alles voor deze afstand opnieuw afleiden uit de instellingen">↻ Opnieuw afleiden</button>`
            : `<div class="wz-d2-fmt">
                 <button class="wz-seg ${series ? '' : 'act'}" data-fmt="direct" data-naam="${i}">Direct A-finale</button>
                 <button class="wz-seg ${series ? 'act' : ''}" data-fmt="series" data-naam="${i}">Series + finales</button>
               </div>`;
        return `
          <div class="wz-d2-af${grendel ? ' wz-d2-grendel' : ''}">
            <div class="wz-d2-afkop">
              <span class="wz-d2-afnaam">${esc(af.naam)}</span>${meters}
              <span class="wz-d2-rt wz-${typeKlasse(af.race_type)}">${esc(typeLabel(af.race_type))}</span>
              ${rechts}
            </div>
            ${series ? d2ParRij(i, p, dis) : ''}
            <div class="wz-d2-preview">${rijen}</div>
          </div>`;
    }

    function d2ParRij(naam, p, dis) {
        const n = esc(naam);
        dis = dis || '';
        const veld = (lbl, par, val, min) => `<label class="wz-d2-veld">${lbl}<input type="number" min="${min}" value="${val ?? ''}" placeholder="—" data-naam="${n}" data-par="${par}" ${dis}></label>`;
        return `<div class="wz-d2-par">
            <div class="wz-d2-pargrp">
              <span class="wz-d2-parlbl">1 · Series</span>
              <div class="wz-d2-parvelden">
                ${veld('Max rijders per serie', 'mS', p.mS, 2)}
                ${veld('Q per heat', 'q', p.q, 0)}
              </div>
            </div>
            <div class="wz-d2-pargrp">
              <span class="wz-d2-parlbl">2 · Finales</span>
              <div class="wz-d2-parvelden">
                ${veld('Rijders per finale (A/B)', 'hG', p.hG, 2)}
                ${veld('Max afwijking', 'fA', p.fA, 0)}
                ${veld('Min. B-finale', 'minB', p.minB, 1)}
                <label class="wz-d2-veld">Laatste B grootste
                  <span class="wz-d2-chkbox"><input type="checkbox" ${p.laatsteB ? 'checked' : ''} data-naam="${n}" data-par="laatsteB" ${dis}></span>
                </label>
                <label class="wz-d2-veld wz-d2-breed">Bij 1 serie
                  <select data-naam="${n}" data-par="startModus" ${dis}>
                    <option value="a-finale" ${p.startModus === 'a-finale' ? 'selected' : ''}>A-finale = eindstand</option>
                    <option value="optellen" ${p.startModus === 'optellen' ? 'selected' : ''}>Serie + A opgeteld</option>
                  </select>
                </label>
              </div>
            </div>
          </div>`;
    }

    // Verdeel 'total' over n finales, zo gelijk mogelijk. Rest naar laatste
    // (laatsteGrootste) of eerste. Voor handmatig gezet aantal B-finales.
    function d2VerdeelGelijk(total, n, laatsteGrootste) {
        if (n <= 0 || total <= 0) return [];
        const base = Math.floor(total / n), extra = total - base * n;
        const arr = Array.from({ length: n }, () => base);
        for (let i = 0; i < extra; i++) arr[laatsteGrootste ? n - 1 - i : i] += 1;
        return arr;
    }

    // Uitkomst per groep: override (p.ov[gi]) heeft voorrang op de afleiding.
    function d2Uitkomst(gr, p, series) {
        const N = gr.N;
        if (N <= 0) return { leeg: true };
        if (!series) return { direct: true, A: N, series: [] };
        if (!p.hG) return { geenHg: true };
        const ov = (p.ov || {})[gr.idx];
        // Effectieve laatste-B-grootste: per-groep override boven de afstand-default.
        const lb = (ov && ov.laatsteB != null) ? ov.laatsteB : p.laatsteB;
        // Opgeslagen serie-aantal (ov.heats) heeft voorrang; anders afleiden uit max-per-serie.
        const s = (ov && ov.heats) ? d2VerdeelGelijk(N, ov.heats, false) : d2VerdeelSeries(N, p.mS || p.hG);
        if (ov && ov.A != null) {
            const A = Math.max(1, Math.min(ov.A, N));
            const rest = N - A;
            let B;
            if (rest <= 0) B = [];
            else if (ov.bAantal != null && ov.bAantal > 0) B = d2VerdeelGelijk(rest, ov.bAantal, lb);
            else B = d2VerdeelB(rest, p.hG, p.fA || 0, p.minB || 1, lb) || [rest];
            return { A, B, series: s, aangepast: true, alleenStart: B.length === 0 && s.length <= 1 };
        }
        const f = d2Los(N, p, lb);
        if (f.onoplosbaar) return { onoplosbaar: true, series: s };
        const ovExtra = ov && (ov.heats != null || ov.laatsteB != null || ov.q != null);   // afwijkend zonder A te zetten
        return { A: f.A, B: f.B, series: s, alleenStart: f.alleenStart, standaard: f.standaard && !ovExtra, aangepast: !!ovExtra };
    }

    function d2SchemaPillen(u) {
        const s = u.series || [];
        const serieTxt = `<span class="wz-pil">${s.length} serie${s.length === 1 ? '' : 's'} · ${s.join('·')}</span>`;
        const aTxt = `<span class="wz-pil wz-pil-a">A ${u.A}</span>`;
        const bTxt = (u.B || []).map((b, i) => `<span class="wz-pil">B${i + 1} ${b}</span>`).join('');
        return `${serieTxt}<span class="wz-arrow">→</span>${aTxt}${bTxt}`;
    }

    function d2Rij(gr, p, series, ai, grendel) {
        const N = gr.N;
        const kop = `<span class="wz-d2-grp">${esc(gr.label)}</span><span class="wz-d2-cnt">${N > 0 ? N : 0}</span>`;
        if (N <= 0) return `<div class="wz-d2-rij wz-d2-leeg">${kop}<span class="wz-d2-uit">geen deelnemers</span></div>`;

        const bewerkbaar = series && p.hG;
        if (d2Edit && d2Edit.ai === ai && d2Edit.gi === gr.idx && bewerkbaar) return d2RijEdit(gr, p, ai);

        const u = d2Uitkomst(gr, p, series);
        if (u.direct) {
            const uit = `<span class="wz-pil wz-pil-mut">direct</span><span class="wz-arrow">→</span><span class="wz-pil wz-pil-a">A ${N}</span>`;
            return `<div class="wz-d2-rij">${kop}<span class="wz-d2-uit">${uit}</span><span class="wz-d2-acties"><span class="wz-badge wz-badge-ok">standaard</span></span></div>`;
        }
        if (u.geenHg) return `<div class="wz-d2-rij">${kop}<span class="wz-d2-uit"><span class="wz-d2-note">vul eerst de heat-grootte in</span></span></div>`;

        const pen = `<button class="wz-d2-pen" data-ai="${ai}" data-gi="${gr.idx}" title="Deze groep aanpassen" aria-label="Deze groep aanpassen">✎</button>`;
        let uit, badge;
        if (u.onoplosbaar) {
            uit = `<span class="wz-d2-err">⚠ kan niet oplossen — pas de instelling aan of klik ✎ om handmatig te zetten</span>`;
            badge = `<span class="wz-badge wz-badge-err">kan niet</span>`;
        } else {
            const smEff = ((p.ov || {})[gr.idx] || {}).startModus || p.startModus;
            const note = u.alleenStart
                ? (smEff === 'optellen'
                    ? `<span class="wz-d2-note">serie + A-finale opgeteld · A = tie-break</span>`
                    : `<span class="wz-d2-note">A-finale = eindstand</span>`)
                : '';
            uit = `${d2SchemaPillen(u)}${note}`;
            badge = grendel
                ? (d2Dirty.has(ai + '|' + gr.idx)
                    ? `<span class="wz-badge wz-badge-warn">gewijzigd</span>`
                    : `<span class="wz-badge wz-badge-opg">opgeslagen</span>`)
                : (u.aangepast || !u.standaard
                    ? `<span class="wz-badge wz-badge-warn">aangepast</span>`
                    : `<span class="wz-badge wz-badge-ok">standaard</span>`);
        }
        return `<div class="wz-d2-rij">${kop}<span class="wz-d2-uit">${uit}</span><span class="wz-d2-acties">${pen}${badge}</span></div>`;
    }

    // Inline-editor voor één groep: aantal series + A-finale + aantal B-finales.
    function d2RijEdit(gr, p, ai) {
        const N = gr.N;
        const ov = (p.ov || {})[gr.idx] || {};
        const f = d2Los(N, p);
        const derivedA = f.onoplosbaar ? '' : f.A;
        const derivedS = d2VerdeelSeries(N, p.mS || p.hG).length || 1;
        const sVal = ov.heats != null ? ov.heats : derivedS;
        const aVal = ov.A != null ? ov.A : derivedA;
        const bVal = ov.bAantal != null ? ov.bAantal : '';
        const u = d2Uitkomst(gr, p, true);
        const res = u.onoplosbaar
            ? `<span class="wz-d2-err">vul een A-finale-grootte in</span>`
            : d2SchemaPillen(u);
        const qVal = ov.q != null ? ov.q : (p.q || 0);
        const lbVal = ov.laatsteB != null ? ov.laatsteB : p.laatsteB;
        // Laatste-B alleen relevant als er B-finales zijn; Uitslag alleen bij 1 serie.
        const heeftB = !u.onoplosbaar && (u.B || []).length >= 1;
        const eenSerie = (u.series || []).length === 1;
        const smVal = ov.startModus || p.startModus;
        const laatsteB = heeftB
            ? `<label>Laatste B grootste
                 <span class="wz-d2-chkbox"><input type="checkbox" ${lbVal ? 'checked' : ''} data-ov="laatsteB" data-ai="${ai}" data-gi="${gr.idx}"></span>
               </label>`
            : '';
        const uitslag = eenSerie
            ? `<label class="wz-d2-breed">Uitslag
                 <select data-ov="startModus" data-ai="${ai}" data-gi="${gr.idx}">
                   <option value="a-finale" ${smVal !== 'optellen' ? 'selected' : ''}>A-finale = eindstand</option>
                   <option value="optellen" ${smVal === 'optellen' ? 'selected' : ''}>Serie + A opgeteld</option>
                 </select>
               </label>`
            : '';
        return `<div class="wz-d2-rij wz-d2-editing">
            <span class="wz-d2-grp">${esc(gr.label)}</span><span class="wz-d2-cnt">${N}</span>
            <div class="wz-d2-editvelden">
              <label>Aantal series<input type="number" min="1" max="${N}" value="${sVal}" data-ov="heats" data-ai="${ai}" data-gi="${gr.idx}"></label>
              <label>Q per heat<input type="number" min="0" value="${qVal}" data-ov="q" data-ai="${ai}" data-gi="${gr.idx}"></label>
              <label>A-finale<input type="number" min="1" max="${N}" value="${aVal}" data-ov="A" data-ai="${ai}" data-gi="${gr.idx}"></label>
              <label>Aantal B-finales<input type="number" min="0" placeholder="auto" value="${bVal}" data-ov="bAantal" data-ai="${ai}" data-gi="${gr.idx}"></label>
              ${laatsteB}
              ${uitslag}
              <span class="wz-d2-editres">${res}</span>
              <button class="wz-d2-auto" data-ai="${ai}" data-gi="${gr.idx}" title="Deze groep terug naar de afgeleide standaard-waardes">Standaard</button>
              <button class="wz-d2-klaar wz-btn-primair">Klaar</button>
            </div>
          </div>`;
    }

    function wireDeel2() {
        const el = overlay.querySelector('#wz-2');
        el.querySelectorAll('input[name="wz-sys"]').forEach(r =>
            r.addEventListener('change', () => { if (!r.disabled) { d2Sys = r.value; d2MarkWijziging(); updateOpslaanKnop(); } }));
        el.querySelectorAll('.wz-seg').forEach(b =>
            b.addEventListener('click', () => {
                const p = d2Par[b.dataset.naam]; if (!p) return;
                if (d2Locked && !p.unlocked) return;   // bulk op slot
                p.format = b.dataset.fmt; d2MarkWijziging(); renderDeel2();
            }));
        el.querySelectorAll('.wz-d2-herleid').forEach(b =>
            b.addEventListener('click', async () => {
                const p = d2Par[b.dataset.ai]; if (!p) return;
                const heeftProg = wzData && wzData.heeft_programma;
                const ok = await toonBevestigDialog(
                    'Opnieuw afleiden overschrijft de handmatige instellingen van deze afstand.'
                    + (heeftProg ? ' Er is al een programma: als je deze wijziging straks opslaat, wordt het programma opnieuw gegenereerd — A-finale-combinaties en handmatige rit-volgorde gaan verloren.' : '')
                    + ' Doorgaan?',
                    'Opnieuw afleiden', 'Opnieuw afleiden', 'Annuleren');
                if (!ok) return;
                p.unlocked = true; p.ov = {}; d2MarkWijziging();
                renderDeel2();
            }));
        el.querySelectorAll('[data-par]').forEach(inp =>
            inp.addEventListener('change', () => {
                const p = d2Par[inp.dataset.naam]; if (!p) return;
                const par = inp.dataset.par;
                if (par === 'laatsteB') p[par] = inp.checked;
                else if (par === 'startModus') p[par] = inp.value;
                else { const v = inp.value.trim(); p[par] = v === '' ? null : Math.max(0, parseInt(v, 10) || 0); }
                d2MarkWijziging();
                renderDeel2();
            }));

        // Per-groep overrulen (potlood)
        el.querySelectorAll('.wz-d2-pen').forEach(b =>
            b.addEventListener('click', () => { d2Edit = { ai: +b.dataset.ai, gi: +b.dataset.gi }; renderDeel2(); }));
        el.querySelectorAll('[data-ov]').forEach(inp =>
            inp.addEventListener('change', () => {
                const p = d2Par[inp.dataset.ai]; if (!p) return;
                p.ov = p.ov || {};
                const gi = +inp.dataset.gi;
                const cur = p.ov[gi] || {};
                if (inp.dataset.ov === 'startModus') {
                    cur.startModus = inp.value;
                } else if (inp.type === 'checkbox') {
                    cur[inp.dataset.ov] = inp.checked;
                } else {
                    const v = inp.value.trim();
                    cur[inp.dataset.ov] = v === '' ? null : Math.max(0, parseInt(v, 10) || 0);
                }
                const leeg = cur.A == null && cur.bAantal == null && cur.heats == null
                    && !cur.startModus && cur.q == null && cur.laatsteB == null;
                if (leeg) delete p.ov[gi]; else p.ov[gi] = cur;
                d2Dirty.add(inp.dataset.ai + '|' + gi); d2MarkWijziging();
                renderDeel2();
            }));
        el.querySelectorAll('.wz-d2-auto').forEach(b =>
            b.addEventListener('click', () => {
                const p = d2Par[b.dataset.ai]; if (p && p.ov) delete p.ov[+b.dataset.gi];
                d2Dirty.add(b.dataset.ai + '|' + b.dataset.gi); d2MarkWijziging();
                renderDeel2();
            }));
        el.querySelectorAll('.wz-d2-klaar').forEach(b =>
            b.addEventListener('click', () => { d2Edit = null; renderDeel2(); }));
    }

    function updateOpslaanKnop() { renderFooter(); }

    async function postJson(url, body) {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const data = await res.json().catch(() => ({}));
        if (res.status === 409) throw new Error(data.message || data.error || 'Er is al een programma of loting — wis dat eerst in het Tijdschema.');
        if (!res.ok || data.error) throw new Error(data.error || ('Fout (' + res.status + ')'));
        return data;
    }

    // ── Deel 2 opslaan ────────────────────────────────────────────────────────
    // Per groep: de primaire DC + split_group (zelfde union-find als Deel 1's
    // opslaan). Standalone zodat bouwOpslaanPayload ongemoeid blijft.
    function groepDoelen() {
        const cats = wzData.categorien || [];
        const allDcs = [...new Set(cats.map(c => c.dc_id))];
        const dcNummer = {};
        cats.forEach(c => { if (dcNummer[c.dc_id] == null) dcNummer[c.dc_id] = c.dc_number || 0; });
        const parent = {}; allDcs.forEach(dc => parent[dc] = dc);
        const find = x => { while (parent[x] !== x) { parent[x] = parent[parent[x]]; x = parent[x]; } return x; };
        state.groepen.forEach(g => {
            const dcs = [...new Set(g.leden.map(c => c.split('|')[0]))];
            for (let i = 1; i < dcs.length; i++) parent[find(dcs[0])] = find(dcs[i]);
        });
        const cluster = {};
        allDcs.forEach(dc => { const r = find(dc); (cluster[r] = cluster[r] || { dcs: new Set(), groepen: new Set() }).dcs.add(dc); });
        state.groepen.forEach((g, gi) => g.leden.forEach(c => cluster[find(c.split('|')[0])].groepen.add(gi)));
        const primair = {};
        Object.keys(cluster).forEach(r => { primair[r] = [...cluster[r].dcs].sort((a, b) => (dcNummer[a] || 0) - (dcNummer[b] || 0))[0]; });
        const sgNaam = gi => state.groepen[gi].label || ('groep-' + gi);
        return state.groepen.map((g, gi) => {
            if (!g.leden.length) return null;
            const r = find(g.leden[0].split('|')[0]);
            return { dc_id: primair[r], split_group: cluster[r].groepen.size > 1 ? sgNaam(gi) : null };
        });
    }

    // Zoek de DB-distance_id voor (dc, split_group, naam+meters+type).
    function d2DistanceId(dcId, splitGroup, naam, meters, rt) {
        const lst = (wzData.distances_per_dc || {})[dcId] || [];
        const tg = splitGroup || null;
        const d = lst.find(x => x.name === naam
            && (x.value_meters ?? null) === (meters ?? null)
            && (x.target_group || null) === tg);
        return d ? d.id : null;
    }

    function bouwDeel2Payload() {
        const doelen = groepDoelen();
        const afs = d2Afstanden();
        const problemen = [];
        const afMap = new Map();   // dc_id|naam → afstand_config
        const catConfigs = [];
        afs.forEach((af, i) => {
            const p = d2GetPar(af, i);
            const series = p.format === 'series';
            if (series && !p.hG) { problemen.push(`"${af.naam}": vul de heat-grootte in`); return; }
            af.groepen.forEach(gr => {
                const doel = doelen[gr.idx]; if (!doel) return;
                const distId = d2DistanceId(doel.dc_id, doel.split_group, af.naam, af.value_meters, af.race_type);
                if (!distId) return;   // afstand niet in DB — overslaan
                const u = d2Uitkomst(gr, p, series);
                if (u.leeg) return;    // 0 deelnemers
                let cc;
                if (u.direct) {
                    cc = { dc_id: doel.dc_id, distance_id: distId, heeft_heats: 0, heats_aantal: null, heats_q_heat: 0,
                           finale_a_grootte: gr.N, finale_b_heats: 0, laatste_b_grootste: p.laatsteB ? 1 : 0, series_alleen_startvolgorde: 0 };
                } else if (u.onoplosbaar) {
                    problemen.push(`"${af.naam}" · ${gr.label}: kan niet oplossen — zet handmatig met ✎`);
                    return;
                } else {
                    const ovg = (p.ov || {})[gr.idx] || {};
                    const smEff = ovg.startModus || p.startModus;
                    const qEff  = ovg.q != null ? ovg.q : (p.q || 0);
                    const lbEff = ovg.laatsteB != null ? ovg.laatsteB : p.laatsteB;
                    const sas = (u.alleenStart && smEff === 'a-finale') ? 1 : 0;
                    cc = { dc_id: doel.dc_id, distance_id: distId, heeft_heats: 1,
                           heats_aantal: (u.series || []).length || 1, heats_q_heat: qEff,
                           finale_a_grootte: u.A, finale_b_heats: (u.B || []).length,
                           laatste_b_grootste: lbEff ? 1 : 0, series_alleen_startvolgorde: sas };
                }
                catConfigs.push(cc);
                const key = doel.dc_id + '|' + af.naam + '|' + (af.value_meters ?? '');
                if (!afMap.has(key)) afMap.set(key, {
                    dc_id: doel.dc_id, afstand_naam: af.naam, value_meters: af.value_meters ?? null,
                    finale_heat_grootte: p.hG || 6, finale_b_grootte: p.hG || 6,
                    laatste_b_grootste: p.laatsteB ? 1 : 0, seeding: 'slang', race_type: af.race_type,
                });
            });
        });
        if (problemen.length) return { error: 'Nog niet compleet:\n• ' + problemen.slice(0, 6).join('\n• ') };
        return { systeem: d2Sys, afstand_configs: [...afMap.values()], cat_configs: catConfigs };
    }

    // daarna: 'sluit' = wizard dicht · 'stap3' = door naar programma (stap 3)
    async function opslaanDeel2(daarna) {
        if (locked === 'loting') return;
        // Niets gewijzigd én config bestaat al → NIET opnieuw opslaan (zo blijft
        // een bestaand programma intact), gewoon door/sluiten. Bij een verse
        // wedstrijd (nog geen config) slaan we de defaults juist wél op.
        if (!d2Changed && wzData && wzData.heeft_cat_config) {
            if (daarna === 'sluit') { sluitWizard(); return; }
            zetStap(3);
            return;
        }
        const payload = bouwDeel2Payload();
        if (payload.error) { toonOpslaanMelding(payload.error, false); return; }
        // Er is al een programma én de instellingen zijn gewijzigd → dat programma
        // wordt opnieuw voorgesteld (handmatige volgorde/pauzes gaan verloren).
        if (wzData && wzData.heeft_programma) {
            const ok = await toonBevestigDialog(
                'Er is al een programma. Door de gewijzigde afstand-instellingen op te slaan wordt het programma opnieuw voorgesteld — je handmatige volgorde en ingevoegde pauzes gaan verloren. Doorgaan?',
                'Programma wordt opnieuw voorgesteld', 'Ja, opslaan', 'Annuleren');
            if (!ok) return;
        }
        const btns = overlay.querySelectorAll('#wz-d2-opslaan, #wz-d2-opslaan-sluit');
        btns.forEach(b => b.disabled = true);
        toonOpslaanMelding('Opslaan…', true);
        try {
            await postJson('api/wizard_deel2.php', { competition_id: compId, ...payload });
            if (typeof herlaadVergelijking === 'function' && compId) { try { herlaadVergelijking(); } catch (e) { /* geen blocker */ } }
            d2Changed = false; d2Dirty = new Set(); d1Snapshot = d1Vinger();   // opgeslagen — geen dirty-warning
            if (daarna === 'sluit') { sluitWizard(); return; }
            // Herlaad de opgeslagen stand (reconstructie == DB) en ga naar stap 3.
            await openWizard();
            // Deel 2 is zojuist (her)opgeslagen → toon een VERS auto-programma
            // i.p.v. een mogelijk verouderde reconstructie van oude blokken.
            d3Manueel = null;
            zetStap(3);
        } catch (e) {
            btns.forEach(b => b.disabled = false);
            toonOpslaanMelding('Opslaan mislukt: ' + (e.message || ''), false);
        }
    }

    // ── Deel 3 opslaan (increment 4a: programma-blokken) ──────────────────────
    // Bouw de geordende blokkenlijst uit de huidige rijen (auto-afleiding óf
    // handmatige volgorde). Elke rij → één tijdschema_blokken-rij.
    function bouwDeel3Payload() {
        const { rows } = d3RijenHuidig();
        const blokken = rows.map(r => {
            const it = r.it;
            if (it.type === 'wedstrijdstart') {
                return { blok_type: 'wedstrijdstart', tijdstip: d3Start.tijd || null, datum: d3Start.datum || null };
            }
            if (it.type === 'ronde') {
                const b = it.b;
                return {
                    blok_type:    'ronde',
                    afstand_naam: b.afstand,
                    value_meters: b.meters ?? null,
                    ronde_type:   b.ronde,          // 'heats' | 'finale'
                    heat_duur:    d3DurVoor(b),     // seconden
                };
            }
            // inrijden → dc-id's meesturen zodat tijdschema de juiste categorieën
            // aanvinkt. pauze/ceremonie → alleen duur; geen auto-opmerking (die
            // vul je desgewenst zelf in de main in).
            if (it.type === 'inrijden') {
                return { blok_type: 'inrijden', duur: it.duur || 0, inrijd_cats: it.dcs || [] };
            }
            return { blok_type: it.type, duur: it.duur || 0 };
        });
        return { blokken, datum: d3Start.datum || null, tijd: d3Start.tijd || null };
    }

    // Herstel Deel 3 uit de opgeslagen programma-blokken (bij heropenen), zodat
    // handmatige volgorde, ingevoegde pauzes, heat-duren, startmoment en de
    // inrijd-clusters bewaard blijven. Zonder opgeslagen blokken → auto-afleiding.
    function reconstrueerDeel3(data) {
        const blokken = data && data.d3_blokken;
        if (!Array.isArray(blokken) || !blokken.length) return;   // geen programma → auto

        // tijdschema_blokken bevat afstand+meters+ronde+heat_duur, maar niet de
        // afgeleide info (heats/totalN/pos/race_type). Match op de auto-blokken.
        const bLookup = {};
        d3Blokken().forEach(b => { bLookup[b.afstand + '|' + (b.meters ?? '') + '|' + b.ronde] = b; });

        const items = [];
        let heeftRonde = false;
        blokken.forEach(bl => {
            const t = bl.blok_type;
            if (t === 'ronde') {
                const ronde = bl.ronde_type === 'heats' ? 'heats' : 'finale';
                const b = bLookup[(bl.afstand_naam || '') + '|' + (bl.value_meters ?? '') + '|' + ronde];
                if (!b) return;   // afstand niet meer in Deel 2 → overslaan
                items.push({ type: 'ronde', b });
                heeftRonde = true;
                if (bl.heat_duur != null) d3Dur[b.afstand + '|' + b.meters + '|' + b.ronde] = parseInt(bl.heat_duur, 10) || 0;
            } else if (t === 'wedstrijdstart') {
                items.push({ type: 'wedstrijdstart', label: 'Wedstrijdstart' });
                if (bl.tijdstip) d3Start.tijd = String(bl.tijdstip).slice(0, 5);
                if (bl.datum)    d3Start.datum = bl.datum;
            } else if (t === 'inrijden') {
                let dcs = [];
                try { dcs = JSON.parse(bl.inrijd_cats || '[]') || []; } catch (e) { dcs = []; }
                items.push({ type: 'inrijden', duur: parseInt(bl.duur, 10) || 0, label: 'Inrijden', dcs });
            } else if (t === 'pauze') {
                items.push({ type: 'pauze', duur: parseInt(bl.duur, 10) || 0, label: 'Pauze' });
            } else if (t === 'ceremonie') {
                items.push({ type: 'ceremonie', duur: parseInt(bl.duur, 10) || 0, label: 'Ceremonie' });
            }
            // 'herstart' produceert de wizard niet → overslaan
        });

        // Alleen laden als er echt een programma is (≥ 1 ronde-blok).
        if (heeftRonde) d3Manueel = items;
    }

    // Merge/split-bewuste categorielijst (catVanJS) voor genereerRitten: per
    // (afstand, wizard-groep) één entry met dc_id (primair), distance_id, aantal
    // en gecombineerde KNSB-codes voor de jong→oud-sortering — net als de
    // tijdschema-pagina zelf doet (bouwAfstandGroepen).
    function bouwCategorieen() {
        const doelen = groepDoelen();
        const cats = [];
        d2Afstanden().forEach(af => {
            af.groepen.forEach(gr => {
                const doel = doelen[gr.idx]; if (!doel) return;
                const distId = d2DistanceId(doel.dc_id, doel.split_group, af.naam, af.value_meters, af.race_type);
                if (!distId) return;   // afstand niet in DB → overslaan
                const codes = [...new Set((state.groepen[gr.idx].leden || [])
                    .map(id => catMap[id] && catMap[id].code).filter(Boolean))];
                cats.push({
                    afstand_naam:    af.naam,
                    value_meters:    af.value_meters ?? null,
                    dc_id:           doel.dc_id,
                    dc_naam:         gr.label,
                    distance_id:     distId,
                    n:               gr.N,
                    category_filter: codes.join(','),
                });
            });
        });
        return cats;
    }

    async function opslaanDeel3(daarna) {
        if (locked === 'loting') {
            if (daarna === 'stap4') zetStap(4);   // vergrendeld: alleen bekijken
            return;
        }
        if (!d3Blokken().length) {
            toonOpslaanMelding('Nog geen afstanden met instellingen — vul eerst stap 2 in.', false);
            return;
        }
        // Niets gewijzigd én programma bestaat al → NIET opnieuw opslaan/genereren
        // (zo blijven rit-volgorde én combinaties intact); gewoon door/sluiten.
        if (!d2Changed && wzData && wzData.heeft_programma) {
            if (daarna === 'sluit') { sluitWizard(); return; }
            if (daarna === 'stap4') { zetStap(4); return; }
            return;
        }
        // Er is al een programma → opnieuw opslaan regenereert de ritten; een
        // handmatige rit-volgorde/combinatie uit het Tijdschema gaat daarbij
        // verloren. De wizard werkt op blok-niveau en ziet die niet.
        if (wzData && wzData.heeft_programma) {
            const ok = await toonBevestigDialog(
                'Er is al een programma. Opnieuw opslaan regenereert de ritten — een handmatige rit-volgorde (bijv. betere rustverdeling) die je in het Tijdschema hebt gemaakt, gaat daarbij verloren. Doorgaan?',
                'Programma opnieuw genereren', 'Ja, opslaan', 'Annuleren');
            if (!ok) return;
        }
        const payload = bouwDeel3Payload();
        const btns = overlay.querySelectorAll('#wz-d3-opslaan, #wz-d3-opslaan-sluit');
        btns.forEach(b => b.disabled = true);
        toonOpslaanMelding('Opslaan…', true);
        try {
            const res = await postJson('api/wizard_deel3.php', { competition_id: compId, ...payload });
            // Ritten genereren uit de zojuist opgeslagen blokken + Deel 2-config.
            // De genereer-actie is merge/split-bewust via de catVanJS-payload.
            await postJson('api/tijdschema.php', {
                action:         'genereer',
                tijdschema_id:  res.tijdschema_id,
                competition_id: compId,
                categorieen:    bouwCategorieen(),
            });
            d2Changed = false;
            if (daarna === 'sluit') { sluitWizard(); return; }
            if (daarna === 'stap4') { zetStap(4); return; }
            btns.forEach(b => b.disabled = false);
            toonOpslaanMelding('Programma opgeslagen.', true);
        } catch (e) {
            btns.forEach(b => b.disabled = false);
            toonOpslaanMelding('Opslaan mislukt: ' + (e.message || ''), false);
        }
    }

    // ── Deel 4: eindresultaat + A-finales combineren ──────────────────────────
    const D4_RONDE = { heats:'Serie', kwartfinale:'KF', halve_finale:'HF', runner_up:'Runner-up', finale_a:'A-finale', finale_b:'B-finale' };
    const d4Label  = (naam, m) => (naam || '') + (m != null && m !== '' ? ' ' + m + 'm' : '');
    const d4Verw   = r => parseInt(r.verwacht, 10) || 0;
    const d4TijdSec = t => { if (!t) return null; const p = String(t).split(':'); return (parseInt(p[0], 10) || 0) * 3600 + (parseInt(p[1], 10) || 0) * 60; };
    const d4SecTijd = sec => { if (sec == null) return '—'; sec = Math.round(sec); const h = Math.floor(sec / 3600) % 24, m = Math.floor((sec % 3600) / 60); return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0'); };

    // Laad het actuele tijdschema (ritten) uit de DB — vers zodat combineren op
    // de echte stand werkt (ook bij heropenen zonder opnieuw genereren).
    async function laadDeel4() {
        try {
            const res = await fetch('api/tijdschema.php?competition_id=' + encodeURIComponent(compId));
            d4Schema = await res.json().catch(() => ({}));
        } catch (e) { d4Schema = { error: e.message || 'laadfout' }; }
        if (stap === 4) renderStap4();
    }

    // Eligibility per (dc,distance): heeft finale_a, géén series/KF/HF én geen B-finale.
    function d4EligPredicate(ritten) {
        const per = new Map();
        ritten.forEach(r => {
            const k = r.dc_id + '|' + (r.distance_id ?? '');
            if (!per.has(k)) per.set(k, new Set());
            per.get(k).add(r.ronde_type);
        });
        return (dc_id, distance_id) => {
            const s = per.get(dc_id + '|' + (distance_id ?? ''));
            return !!s && s.has('finale_a')
                && !s.has('heats') && !s.has('kwartfinale') && !s.has('halve_finale') && !s.has('finale_b');
        };
    }

    function renderStap4() {
        const el = overlay.querySelector('#wz-4');
        if (!d4Schema) { el.innerHTML = '<p class="wz-hint">Laden…</p>'; laadDeel4(); return; }
        if (d4Schema.error) { el.innerHTML = `<p class="wz-hint" style="color:#b71c1c">Kon het programma niet laden: ${esc(d4Schema.error)}</p>`; return; }
        const ritten  = d4Schema.ritten || [];
        if (!ritten.length) { el.innerHTML = '<p class="wz-hint">Nog geen programma — ga naar stap 3 en sla het programma op.</p>'; return; }

        const blokken = (d4Schema.blokken || []).slice().sort((a, b) => (a.volgorde - b.volgorde) || (a.id - b.id));
        const isElig  = d4EligPredicate(ritten);
        const perBlok = new Map();
        ritten.slice().sort((a, b) => (a.volgorde - b.volgorde) || (a.id - b.id))
            .forEach(r => { (perBlok.get(r.blok_id) || perBlok.set(r.blok_id, []).get(r.blok_id)).push(r); });

        // Klok: begintijd per blok. Anker = wedstrijdstart-tijdstip; pre-blokken
        // achterwaarts, rest voorwaarts. Gecombineerde ritten tellen als ÉÉN
        // fysieke heat voor de duur (heat_duur × effectief aantal heats).
        const effHeats = blokId => {
            const rs = perBlok.get(blokId) || [];
            const g = new Set(); let solo = 0;
            rs.forEach(r => { const cg = r.combi_group ? parseInt(r.combi_group, 10) : null; if (cg) g.add(cg); else solo++; });
            return solo + g.size;
        };
        const blokDuur = b => b.blok_type === 'ronde' ? (parseInt(b.heat_duur, 10) || 0) * effHeats(b.id)
                            : b.blok_type === 'wedstrijdstart' ? 0
                            : (parseInt(b.duur, 10) || 0) * 60;
        const beginMap = {}; let eindSec = null;
        {
            const ws = blokken.find(b => b.blok_type === 'wedstrijdstart');
            const anchor = ws ? d4TijdSec(ws.tijdstip) : null;
            if (anchor != null) {
                const wsIdx = blokken.indexOf(ws);
                let t = anchor;
                for (let i = wsIdx - 1; i >= 0; i--) { t -= blokDuur(blokken[i]); beginMap[blokken[i].id] = t; }
                let cur = anchor;
                for (let i = wsIdx; i < blokken.length; i++) { beginMap[blokken[i].id] = cur; cur += blokDuur(blokken[i]); }
                eindSec = cur;
            }
        }

        // Selectie-stand: alles moet uit hetzelfde blok (= zelfde afstand).
        const selRit  = ritten.filter(r => d4Sel.has(r.id));
        const selSom  = selRit.reduce((s, r) => s + d4Verw(r), 0);
        const selBlok = selRit.length ? selRit[0].blok_id : null;
        const rest    = Math.max(0, d4Max - selSom);
        const leesOnly = (locked === 'loting');   // na loting: alleen bekijken

        let html = (leesOnly ? '' : `<div class="wz-d4-top">
            <label class="wz-d4-max">Max. gecombineerde grootte
              <input type="number" min="2" value="${d4Max}" id="wz-d4-max"> rijders</label>
            ${d4Sel.size ? `<span class="wz-d4-rest">resterend: <b>${rest}</b></span>` : ''}
        </div>`) + `<div class="wz-d4-lijst">`;

        for (const blok of blokken) {
            if (blok.blok_type !== 'ronde') {
                // niet-ronde: compacte context-regel (geen ritten)
                const icoon = { pauze:'☕', inrijden:'🛼', ceremonie:'🏅', wedstrijdstart:'🏁', herstart:'🔁' }[blok.blok_type] || '•';
                html += `<div class="wz-d4-blk niet"><div class="wz-d4-row">
                    <span class="wz-d4-chev"></span>
                    <span class="wz-d4-tijd">${d4SecTijd(beginMap[blok.id])}</span>
                    <span class="wz-d4-af">${icoon} ${esc({pauze:'Pauze',inrijden:'Inrijden',ceremonie:'Ceremonie',wedstrijdstart:'Wedstrijdstart',herstart:'Herstart'}[blok.blok_type] || blok.blok_type)}</span>
                    <span class="wz-d4-meta"></span></div></div>`;
                continue;
            }
            const brit = perBlok.get(blok.id) || [];
            if (!brit.length) continue;
            const rondeLbl = blok.ronde_type === 'heats' ? 'Series' : 'Finale';
            const kleur    = blok.ronde_type === 'heats' ? 'wz-d4-b-ser' : 'wz-d4-b-fin';
            const nRij     = brit.reduce((s, r) => s + d4Verw(r), 0);
            const open     = d4Open.has(blok.id);
            // combineerbaar = minstens 2 kandidaten (finale_a, direct-A, >0 rijders);
            // met 1 (of alleen lege 0-rijder-finales) valt er niets te combineren.
            const eligCount = brit.filter(r => r.ronde_type === 'finale_a' && d4Verw(r) > 0 && !r.combi_group && isElig(r.dc_id, r.distance_id)).length;
            const combineerbaar = blok.ronde_type === 'finale' && eligCount >= 2;
            const hasCombi = brit.some(r => r.combi_group);

            html += `<div class="wz-d4-blk">
                <div class="wz-d4-row" data-toggle="${blok.id}">
                  <span class="wz-d4-chev">${open ? '▾' : '▸'}</span>
                  <span class="wz-d4-tijd">${d4SecTijd(beginMap[blok.id])}</span>
                  <span class="wz-d4-af">${esc(d4Label(blok.afstand_naam, blok.value_meters))} <span class="wz-d4-badge ${kleur}">${rondeLbl}</span></span>
                  <span class="wz-d4-meta">${brit.length} heats · ${nRij} rijders${hasCombi ? ' · <span class="wz-d4-combi-mark">🔗 gecombineerd</span>' : ''}${combineerbaar && !leesOnly ? ' · <span class="wz-d4-kan">combineerbaar</span>' : ''}</span>
                </div>`;
            if (open) {
                html += '<div class="wz-d4-kids">';
                const gezienCg = new Set();
                for (const r of brit) {
                    const cg = r.combi_group ? parseInt(r.combi_group, 10) : null;
                    if (cg) {
                        if (gezienCg.has(cg)) continue;
                        gezienCg.add(cg);
                        const leden = brit.filter(x => (x.combi_group ? parseInt(x.combi_group, 10) : null) === cg);
                        const som = leden.reduce((s, x) => s + d4Verw(x), 0);
                        html += `<div class="wz-d4-combi">
                            <div class="wz-d4-combi-kop"><span>🔗 Gecombineerd — ${som} rijders</span>
                              ${leesOnly ? '' : `<button class="wz-d4-unlink" data-cg="${cg}">ontkoppel</button>`}</div>
                            ${leden.map(x => `<div class="wz-d4-fin plain"><span class="wz-d4-cat">${esc(x.dc_naam)}</span><span class="wz-d4-tag">A-finale</span><span class="wz-d4-n">${d4Verw(x)}</span></div>`).join('')}
                        </div>`;
                        continue;
                    }
                    const n = d4Verw(r);
                    // 0-rijder-finales (placeholder-categorie) zijn geen kandidaat.
                    const elig = r.ronde_type === 'finale_a' && n > 0 && isElig(r.dc_id, r.distance_id);
                    if (!elig) {
                        html += `<div class="wz-d4-fin noelig plain"><span class="wz-d4-cat">${esc(r.dc_naam)}</span><span class="wz-d4-tag">${D4_RONDE[r.ronde_type] || r.ronde_type}</span><span class="wz-d4-n">${n}</span></div>`;
                        continue;
                    }
                    if (leesOnly) {
                        html += `<div class="wz-d4-fin plain"><span class="wz-d4-cat">${esc(r.dc_naam)}</span><span class="wz-d4-tag">A-finale</span><span class="wz-d4-n">${n}</span></div>`;
                        continue;
                    }
                    const sel = d4Sel.has(r.id);
                    let dis = false, reden = 'A-finale';
                    if (!sel && d4Sel.size) {
                        if (selBlok !== null && r.blok_id !== selBlok) { dis = true; reden = 'andere afstand'; }
                        else if (d4Sel.size >= 4)                     { dis = true; reden = 'max 4'; }
                        else if (n > rest)                            { dis = true; reden = 'past niet (&gt;' + rest + ')'; }
                    }
                    html += `<label class="wz-d4-fin ${sel ? 'selected' : 'elig'}${dis ? ' dis' : ''}">
                        <input type="checkbox" class="wz-d4-sel" data-rit="${r.id}" ${sel ? 'checked' : ''} ${dis ? 'disabled' : ''}>
                        <span class="wz-d4-cat">${esc(r.dc_naam)}</span><span class="wz-d4-tag">${reden}</span><span class="wz-d4-n">${n}</span>
                    </label>`;
                }
                html += '</div>';
            }
            // Combineer-balk direct onder het blok waarin je iets hebt geselecteerd.
            if (!leesOnly && d4Sel.size >= 1 && blok.id === selBlok) {
                const ok = d4Sel.size >= 2 && d4Sel.size <= 4 && selSom <= d4Max;
                html += `<div class="wz-d4-actbar">
                    <span class="wz-d4-sum">Selectie: <b class="${ok ? 'ok' : 'bad'}">${selSom} / ${d4Max} rijders · ${d4Sel.size} ${d4Sel.size === 1 ? 'rit' : 'ritten'}</b></span>
                    <button class="wz-d4-clear" id="wz-d4-clear">Wis</button>
                    <button class="btn-primary wz-d4-combineer" id="wz-d4-combineer" ${ok ? '' : 'disabled'}>🔗 Combineer selectie</button>
                </div>`;
            }
            html += '</div>';
        }
        html += '</div>';
        if (eindSec != null) html += `<div class="wz-d4-eind">Klaar rond <b>${d4SecTijd(eindSec)}</b></div>`;

        el.innerHTML = html;
        el.querySelectorAll('[data-toggle]').forEach(h => h.addEventListener('click', () => {
            const id = +h.dataset.toggle;
            if (d4Open.has(id)) d4Open.delete(id); else d4Open.add(id);
            renderStap4();
        }));
        el.querySelectorAll('.wz-d4-sel').forEach(cb => cb.addEventListener('change', () => {
            const id = +cb.dataset.rit;
            if (cb.checked) d4Sel.add(id); else d4Sel.delete(id);
            renderStap4();
        }));
        el.querySelector('#wz-d4-max')?.addEventListener('change', e => {
            d4Max = Math.max(2, parseInt(e.target.value, 10) || 2);
            renderStap4();
        });
        el.querySelector('#wz-d4-clear')?.addEventListener('click', () => { d4Sel = new Set(); renderStap4(); });
        el.querySelector('#wz-d4-combineer')?.addEventListener('click', d4Combineer);
        el.querySelectorAll('.wz-d4-unlink').forEach(b => b.addEventListener('click', () => d4Ontkoppel(+b.dataset.cg)));
    }

    // Combineer de selectie: maak ze eerst opeenvolgend (herorden) indien nodig,
    // dan set_combi. Adjacency wordt zo geregeld, niet als selectie-eis.
    async function d4Combineer() {
        const ids = [...d4Sel];
        if (ids.length < 2) return;
        const tsId = d4Schema.id;
        const seq = (d4Schema.ritten || []).slice().sort((a, b) => (a.volgorde - b.volgorde) || (a.id - b.id));
        const selSet = new Set(ids);
        const idx = seq.map((r, i) => selSet.has(r.id) ? i : -1).filter(i => i >= 0);
        const aaneengesloten = idx.length > 0 && (idx[idx.length - 1] - idx[0] === idx.length - 1);
        const combineerBtn = overlay.querySelector('#wz-d4-combineer');
        if (combineerBtn) combineerBtn.disabled = true;
        try {
            if (!aaneengesloten) {
                // Bundel de geselecteerde ritten op de plek van de EERSTE selectie.
                const sel  = seq.filter(r => selSet.has(r.id));
                const rest = seq.filter(r => !selSet.has(r.id));
                const nBefore = seq.slice(0, idx[0]).filter(r => !selSet.has(r.id)).length;
                const nieuw = [...rest.slice(0, nBefore), ...sel, ...rest.slice(nBefore)];
                const volgorde = nieuw.map((r, i) => ({ id: r.id, volgorde: i + 1 }));
                await postJson('api/tijdschema.php', { action: 'herorden_ritten', tijdschema_id: tsId, competition_id: compId, volgorde });
            }
            const res = await postJson('api/tijdschema.php', { action: 'set_combi', tijdschema_id: tsId, competition_id: compId, rit_ids: ids });
            d4Schema = res;   // set_combi geeft fetchSchema terug
            d4Sel = new Set();
            renderStap4();
        } catch (e) {
            if (combineerBtn) combineerBtn.disabled = false;
            toonOpslaanMelding('Combineren mislukt: ' + (e.message || ''), false);
        }
    }

    async function d4Ontkoppel(cg) {
        const ids = (d4Schema.ritten || [])
            .filter(r => (r.combi_group ? parseInt(r.combi_group, 10) : null) === cg)
            .map(r => r.id);
        if (!ids.length) return;
        try {
            const res = await postJson('api/tijdschema.php', { action: 'clear_combi', tijdschema_id: d4Schema.id, competition_id: compId, rit_ids: ids });
            d4Schema = res;
            renderStap4();
        } catch (e) {
            toonOpslaanMelding('Ontkoppelen mislukt: ' + (e.message || ''), false);
        }
    }

    // Vertaal de wizard-indeling naar merge_group + dc_splits + distances-doelen.
    // Ondersteunt ook het gemengde geval (DC's samenvoegen én daarbinnen splitsen),
    // net als de Import-opslag: split-afstanden onder de PRIMAIRE DC (laagste
    // dc_number) van de merge-cluster, gekeyd op split_group; merge_group = die
    // primaire dc-id.
    function bouwOpslaanPayload() {
        const cats = wzData.categorien || [];
        // Backstop: binnen één groep moet elke afstand-naam uniek zijn (ook na
        // hernoemen via het potlood). Downstream koppelt op (dc_id, afstand_naam).
        for (const g of state.groepen) {
            const namen = (g.afstanden || []).map(a => (afstandById(a) || {}).name).filter(Boolean);
            const dup = namen.find((n, i) => namen.indexOf(n) !== i);
            if (dup) return { error: `Groep "${g.label}" heeft twee afstanden met de naam "${dup}". Geef ze een unieke naam (bv. met de meters erin).` };
        }
        const allDcs = [...new Set(cats.map(c => c.dc_id))];
        const dcNummer = {};
        cats.forEach(c => { if (dcNummer[c.dc_id] == null) dcNummer[c.dc_id] = c.dc_number || 0; });
        const sgNaam = gi => state.groepen[gi].label || ('groep-' + gi);

        // Union-find: DC's die samen in één wizard-groep zitten → één merge-cluster.
        const parent = {}; allDcs.forEach(dc => parent[dc] = dc);
        const find = x => { while (parent[x] !== x) { parent[x] = parent[parent[x]]; x = parent[x]; } return x; };
        state.groepen.forEach(g => {
            const dcs = [...new Set(g.leden.map(c => c.split('|')[0]))];
            for (let i = 1; i < dcs.length; i++) parent[find(dcs[0])] = find(dcs[i]);
        });

        // Cluster-info: welke DC's + welke wizard-groepen.
        const cluster = {}; // root → { dcs:Set, groepen:Set }
        allDcs.forEach(dc => { const r = find(dc); (cluster[r] = cluster[r] || { dcs: new Set(), groepen: new Set() }).dcs.add(dc); });
        state.groepen.forEach((g, gi) => g.leden.forEach(c => cluster[find(c.split('|')[0])].groepen.add(gi)));

        // Primaire DC per cluster = laagste dc_number (zoals Import).
        const primair = {};
        Object.keys(cluster).forEach(r => { primair[r] = [...cluster[r].dcs].sort((a, b) => (dcNummer[a] || 0) - (dcNummer[b] || 0))[0]; });

        // Merges: cluster met >1 DC → merge_group = primaire dc-id; overige → un-merge.
        const merges = allDcs.map(dc => {
            const cl = cluster[find(dc)];
            if (cl.dcs.size > 1) {
                const label = cl.groepen.size === 1 ? state.groepen[[...cl.groepen][0]].label : null;
                return { dc_id: dc, merge_group: primair[find(dc)], merge_label: label };
            }
            return { dc_id: dc, merge_group: null, merge_label: null };
        });

        // Splits: cluster die >1 wizard-groep beslaat → elke categorie naar z'n groep-label.
        const catGroep = {}; // catId → gi
        state.groepen.forEach((g, gi) => g.leden.forEach(c => { catGroep[c] = gi; }));
        const splitsByDc = {}; allDcs.forEach(dc => splitsByDc[dc] = []);
        Object.keys(cluster).forEach(r => {
            if (cluster[r].groepen.size <= 1) return; // niet gesplitst
            cluster[r].dcs.forEach(dc => cats.filter(c => c.dc_id === dc).forEach(c => {
                const gi = catGroep[c.dc_id + '|' + c.code];
                if (gi != null) splitsByDc[dc].push({ category: c.code, split_group: sgNaam(gi) });
            }));
        });

        // Afstand-doelen: per wizard-groep = één startlijst → (primaire dc, split_group).
        const distTargets = [];
        state.groepen.forEach((g, gi) => {
            if (!g.leden.length) return;
            const r = find(g.leden[0].split('|')[0]);
            const split_group = cluster[r].groepen.size > 1 ? sgNaam(gi) : null;
            const distances = (g.afstanden || []).map((aid, i) => {
                const d = afstandById(aid);
                return d ? { id: null, number: i + 1, name: d.name, value_meters: d.value_meters, race_type: d.race_type } : null;
            }).filter(Boolean);
            distTargets.push({ dc_id: primair[r], split_group, distances });
        });

        return { merges, splitsByDc, distTargets };
    }

    // daarna: 'sluit' = wizard dicht · 'stap2' = door naar afstand-instellingen
    async function opslaan(daarna) {
        if (locked || !state || state.pool.length) return;
        const payload = bouwOpslaanPayload();
        if (payload.error) { toonOpslaanMelding(payload.error, false); return; }
        const saveBtns = overlay.querySelectorAll('#wz-opslaan-sluit, #wz-opslaan-verder');
        saveBtns.forEach(b => b.disabled = true);
        toonOpslaanMelding('Opslaan…', true);
        try {
            await postJson('api/samenvoeg.php', { competition_id: compId, merges: payload.merges });
            for (const dcId of Object.keys(payload.splitsByDc)) {
                await postJson('api/splits.php', { competition_id: compId, dc_id: dcId, splits: payload.splitsByDc[dcId] });
            }
            for (const t of payload.distTargets) {
                const body = { dc_id: t.dc_id, split_group: t.split_group, distances: t.distances };
                const verwacht = t.distances.filter(d => (d.name || '').trim() !== '').length;
                // Vangnet tegen een stille server-hiccup die één insert laat vallen:
                // controleer of alles is teruggemeld; zo niet → één keer opnieuw;
                // anders harde fout i.p.v. stille data-loss.
                let res = await postJson('api/afstanden_beheer.php', body);
                if ((res.distances || []).length < verwacht) {
                    res = await postJson('api/afstanden_beheer.php', body);
                }
                if ((res.distances || []).length < verwacht) {
                    throw new Error(`Niet alle afstanden opgeslagen voor "${t.split_group || 'basis'}" (verstuurd ${verwacht}, opgeslagen ${(res.distances || []).length}). Klik nogmaals Opslaan.`);
                }
            }
            await postJson('api/wizard_dc.php', { action: 'mark_done', competition_id: compId });
            // Import-DC-panel (indien open achter de wizard) verversen zodat je daar
            // meteen de nieuwe waardes ziet i.p.v. de oude cache. Op de achtergrond.
            if (typeof herlaadVergelijking === 'function' && compId) {
                try { herlaadVergelijking(); } catch (e) { /* geen blocker */ }
            }
            d1Snapshot = d1Vinger();   // indeling is nu opgeslagen — geen dirty-warning
            if (daarna === 'sluit') { sluitWizard(); return; }
            await openWizard();  // herlaad met de opgeslagen stand (vlag gezet) — reset naar stap 1
            if (daarna === 'stap2') zetStap(2);
            toonOpslaanMelding('✔ Opgeslagen.', true);
        } catch (e) {
            saveBtns.forEach(b => b.disabled = false);
            toonOpslaanMelding('Opslaan mislukt: ' + (e.message || ''), false);
        }
    }

    function toonOpslaanMelding(tekst, ok) {
        const st = overlay && overlay.querySelector('#wz-status');
        if (!st) return;
        const stijl = ok
            ? 'background:#e8f5e9;border-color:#b6dbb9;color:#2e7d32'
            : 'background:#fce4e4;border-color:#f5b5b5;color:#b71c1c';
        st.insertAdjacentHTML('afterbegin', `<div class="wz-band" style="${stijl}">${esc(tekst)}</div>`);
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    const btn = document.getElementById('btn-wizard');
    if (btn) btn.addEventListener('click', openWizard);
})();
