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
    let d2Changed = false;  // Deel 2 gewijzigd sinds openen/opslaan

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
            bouwState(data);
            reconstrueerDeel2(data);
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
                <div class="wz-step" data-step="4"><span class="wz-num">4</span><span class="wz-lbl">A-finales combineren<small>optioneel</small></span></div>
              </div>
              <div class="wz-subtabs">
                <button id="wz-tab-1a" class="act">1a · Groepen vormen</button>
                <button id="wz-tab-1b">1b · Afstanden per groep</button>
              </div>
              <div id="wz-1a"></div>
              <div id="wz-1b" class="wz-hidden"></div>
              <div id="wz-2" class="wz-hidden"></div>
              <div id="wz-3" class="wz-hidden"></div>
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
        if (stap === 3) {
            f.innerHTML =
                `<button class="wz-btn" id="wz-annuleer">Annuleren</button>
                 <button class="wz-btn" id="wz-terug">← Stap 2</button>`;
            f.querySelector('#wz-annuleer').addEventListener('click', sluitWizard);
            f.querySelector('#wz-terug').addEventListener('click', () => zetStap(2));
            return;
        }
        if (stap === 2) {
            const kanD2 = locked !== 'loting';
            const tip = locked === 'loting' ? 'Er is al geloot — instellingen staan vast.' : '';
            f.innerHTML =
                `<button class="wz-btn" id="wz-annuleer">Annuleren</button>
                 <button class="wz-btn" id="wz-terug">← Stap 1</button>
                 <button class="wz-btn" id="wz-d2-opslaan-sluit" ${kanD2 ? '' : 'disabled'} title="${esc(tip)}">Opslaan en sluiten</button>
                 <button class="wz-btn wz-btn-primair" id="wz-d2-opslaan" ${kanD2 ? '' : 'disabled'} title="${esc(tip)}">Opslaan en verder →</button>`;
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
        overlay.querySelector('#wz-status').innerHTML = statusBanner();   // banner is stap-afhankelijk
        overlay.querySelectorAll('.wz-step').forEach(el =>
            el.classList.toggle('act', +el.dataset.step === n));
        overlay.querySelector('.wz-subtabs').style.display = n === 1 ? '' : 'none';
        overlay.querySelector('#wz-2').classList.toggle('wz-hidden', n !== 2);
        overlay.querySelector('#wz-3').classList.toggle('wz-hidden', n !== 3);
        if (n === 1) {
            zetTab(subtab);
        } else {
            overlay.querySelector('#wz-1a').classList.add('wz-hidden');
            overlay.querySelector('#wz-1b').classList.add('wz-hidden');
            if (n === 2) renderDeel2();
            else if (n === 3) renderStap3();
        }
        updateOpslaanKnop();
    }
    function renderStap3() {
        overlay.querySelector('#wz-3').innerHTML =
            `<div class="wz-d3-soon"><h3>Deel 3 · Programma</h3>
             <p>Blok-volgorde, pauzes en tijden — komt binnenkort.</p></div>`;
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
            // Deze melding gaat over stap 1 (indeling vast) → op stap 2 zelf niet
            // tonen; daar spreken de "opgeslagen"-badges + "Opnieuw afleiden".
            if (stap === 2) return '';
            // Onderscheid: een echt programma (Deel 3) vs. alleen afstand-
            // instellingen (Deel 2). Beide zetten de indeling (stap 1) vast.
            return (wzData && wzData.heeft_programma)
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
        return `<div class="wz-cat" ${locked ? '' : 'draggable="true"'} data-id="${esc(id)}">
            <div class="wz-n${leeg}">${c.n}</div>
            <div class="wz-meta"><div class="wz-nm">${esc(catNaam(c))}</div>${chip}${telLabels(c, false)}</div>
          </div>`;
    }
    function lidRow(id) {
        const c = catMap[id]; const leeg = c.n === 0 ? ' wz-leeg' : '';
        return `<div class="wz-lid" ${locked ? '' : 'draggable="true"'} data-id="${esc(id)}">
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
                if (!map.has(k)) map.set(k, { naam: d.name, race_type: d.race_type, value_meters: d.value_meters, groepen: [] });
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
        const afCfg = {}, catCfg = {};
        (data.d2_afstand_config || []).forEach(a => { afCfg[a.dc_id + '|' + a.afstand_naam] = a; });
        (data.d2_cat_config || []).forEach(c => { catCfg[c.dc_id + '|' + c.distance_id] = c; });
        const doelen = groepDoelen();
        d2Afstanden().forEach((af, i) => {
            const p = d2GetPar(af, i);   // startModus-default 'a-finale' uit d2Defaults blijft
            p.unlocked = false;
            p.ov = {};
            let anySeries = false;
            af.groepen.forEach(gr => {
                const doel = doelen[gr.idx]; if (!doel) return;
                const ac = afCfg[doel.dc_id + '|' + af.naam];
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
            r.addEventListener('change', () => { if (!r.disabled) { d2Sys = r.value; d2Changed = true; } }));
        el.querySelectorAll('.wz-seg').forEach(b =>
            b.addEventListener('click', () => {
                const p = d2Par[b.dataset.naam]; if (!p) return;
                if (d2Locked && !p.unlocked) return;   // bulk op slot
                p.format = b.dataset.fmt; d2Changed = true; renderDeel2();
            }));
        el.querySelectorAll('.wz-d2-herleid').forEach(b =>
            b.addEventListener('click', async () => {
                const p = d2Par[b.dataset.ai]; if (!p) return;
                const ok = await toonBevestigDialog(
                    'Opnieuw afleiden overschrijft de handmatige instellingen van deze afstand. Doorgaan?',
                    'Opnieuw afleiden', 'Opnieuw afleiden', 'Annuleren');
                if (!ok) return;
                p.unlocked = true; p.ov = {}; d2Changed = true;
                renderDeel2();
            }));
        el.querySelectorAll('[data-par]').forEach(inp =>
            inp.addEventListener('change', () => {
                const p = d2Par[inp.dataset.naam]; if (!p) return;
                const par = inp.dataset.par;
                if (par === 'laatsteB') p[par] = inp.checked;
                else if (par === 'startModus') p[par] = inp.value;
                else { const v = inp.value.trim(); p[par] = v === '' ? null : Math.max(0, parseInt(v, 10) || 0); }
                d2Changed = true;
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
                d2Dirty.add(inp.dataset.ai + '|' + gi); d2Changed = true;
                renderDeel2();
            }));
        el.querySelectorAll('.wz-d2-auto').forEach(b =>
            b.addEventListener('click', () => {
                const p = d2Par[b.dataset.ai]; if (p && p.ov) delete p.ov[+b.dataset.gi];
                d2Dirty.add(b.dataset.ai + '|' + b.dataset.gi); d2Changed = true;
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
                const key = doel.dc_id + '|' + af.naam;
                if (!afMap.has(key)) afMap.set(key, {
                    dc_id: doel.dc_id, afstand_naam: af.naam,
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
        const payload = bouwDeel2Payload();
        if (payload.error) { toonOpslaanMelding(payload.error, false); return; }
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
            zetStap(3);
        } catch (e) {
            btns.forEach(b => b.disabled = false);
            toonOpslaanMelding('Opslaan mislukt: ' + (e.message || ''), false);
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
