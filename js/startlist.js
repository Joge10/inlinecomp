/* InlineComp – startlijsten */

// ── Startlijst pagina tonen ───────────────────────────────────────────────────

function toonStartlijstenPagina() {
    const header  = el('sl-page-header');
    const catTabs = el('sl-cat-tabs');
    const content = el('sl-cat-content');

    if (!huidigCompId || !isGeimporteerd) {
        catTabs.innerHTML = '';
        content.innerHTML = `<div class="status-msg info">
            Selecteer en importeer eerst een wedstrijd via <strong>Importeer</strong>.
        </div>`;
        if (header) header.textContent = '';
        return;
    }

    if (header) header.innerHTML =
        `<h2 class="sl-page-titel">${escHtml(huidigComp?.name || '')}</h2>`;

    catTabs.innerHTML = '';
    vergelijkData.forEach((cat, i) => {
        const btn = document.createElement('button');
        btn.className = 'tab-btn' + (i === 0 ? ' active' : '');
        btn.textContent = cat.dc_name + ' (' + cat.competitors.length + ')';
        btn.addEventListener('click', () => {
            catTabs.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeCat = cat;
            toonStartlijstConfig(cat);
        });
        catTabs.appendChild(btn);
    });

    activeCat = vergelijkData[0];
    toonStartlijstConfig(vergelijkData[0]);
}

// ── Startlijst – configuratie tonen (per afstand) ────────────────────────────

async function toonStartlijstConfig(cat) {
    const content = el('sl-cat-content');
    content.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Afstanden laden…</div>';

    let afstanden;
    try {
        const res = await fetch(`api/distances_db.php?dc_id=${encodeURIComponent(cat.dc_id)}`);
        afstanden = await res.json();
        if (afstanden.error) throw new Error(afstanden.error);
        if (!afstanden.length) throw new Error('Geen afstanden gevonden voor deze categorie');
    } catch(e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
        return;
    }

    const eersteActief = afstanden[0];
    const tabsHtml = afstanden.map((a, i) =>
        `<button class="tab-btn sl-dist-tab${i === 0 ? ' active' : ''}"
                 data-dist-id="${escHtml(a.id)}"
                 data-dist-naam="${escHtml(a.name)}">
             ${escHtml(a.name)}
         </button>`
    ).join('');

    content.innerHTML = `
        <div class="tab-bar sl-dist-tabs">${tabsHtml}</div>
        <div id="sl-dist-content"></div>`;

    content.querySelectorAll('.sl-dist-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            content.querySelectorAll('.sl-dist-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            toonAfstandConfig(cat, btn.dataset.distId, btn.dataset.distNaam);
        });
    });

    toonAfstandConfig(cat, eersteActief.id, eersteActief.name);
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

function standaardRondNamen(n) {
    const tabel = {
        1: ['Heats'],
        2: ['Heats', 'Finale'],
        3: ['Heats', 'Halve finales', 'Finale'],
        4: ['Heats', 'Kwartfinales', 'Halve finales', 'Finale'],
    };
    return tabel[n] || Array.from({length: n}, (_, i) =>
        i === 0 ? 'Heats' : i === n - 1 ? 'Finale' : `Ronde ${i + 1}`
    );
}

function posLabel(p) {
    return p === 1 ? 'Winnaar' : `${p}e`;
}

// Genereer placeholder-deelnemers voor de volgende ronde
//
// Universele aanpak (werkt voor alle gevallen):
//   Naturelijke volgorde: per positie alle heats (W.H1, W.H2, … W.Hn),
//   dan 2e-plaatsen, …, dan tijdsnelsten.
//   Het slangenpatroon zorgt daarna voor de juiste verdeling:
//
//   3 Heats → 3 HF (w=1, t=15):
//     [W.H1, W.H2, W.H3, Ts1…Ts15] →snake→
//     HF1=[W.H1, Ts3, Ts4, …], HF2=[W.H2, Ts2, Ts5, …], HF3=[W.H3, Ts1, Ts6, …]
//
//   4 QF → 2 SF (w=2, t=0):
//     [W.H1,W.H2,W.H3,W.H4, 2e.H1,2e.H2,2e.H3,2e.H4] →snake→
//     SF1=[W.H1,W.H4,2e.H1,2e.H4], SF2=[W.H2,W.H3,2e.H2,2e.H3]
function genereerPlaceholders(heats, doorstroom) {
    if (doorstroom.type === 'tijdsnelsten') {
        const n = doorstroom.aantal || 8;
        return Array.from({length: n}, (_, i) => ({ label: `Tijdsnelste ${i + 1}`, isPlaceholder: true }));
    }

    // winnaars_plus_tijd — per positie alle heats, daarna tijdsnelsten
    const w        = doorstroom.winnaarsPerHeat   || 1;
    const totaal   = doorstroom.totaalDoorstromers || (heats.length * w);
    const t        = Math.max(0, totaal - heats.length * w);   // tijdsnelsten = totaal - winnaars
    const lijst    = [];

    for (let p = 1; p <= w; p++)
        for (const heat of heats)
            lijst.push({ label: `${posLabel(p)} Heat ${heat.nummer}`, isPlaceholder: true });

    for (let i = 1; i <= t; i++)
        lijst.push({ label: `Tijdsnelste ${i}`, isPlaceholder: true });

    return lijst;
}

// ── Startlijst – standaard doorstroomregel per ronde ─────────────────────────

function defaultDoorstroom(vanIdx) {
    // Van ronde 0 (heats) → volgende: tijdsnelsten is de standaard in inline skating
    // Van ronde 1+ (QF/SF) → volgende: winnaars per heat
    return vanIdx === 0
        ? { type: 'tijdsnelsten', aantal: 16 }
        : { type: 'winnaars_plus_tijd', winnaarsPerHeat: 1, totaalDoorstromers: 8 };
}

// ── Startlijst – aantal rondes instellen ─────────────────────────────────────

function zetAantalRondes(cacheKey, n) {
    const cache  = startlijstCache[cacheKey];
    const huidig = cache.rondenConfig || [];
    const namen  = standaardRondNamen(n);
    const nieuw  = [];
    for (let i = 0; i < n; i++) {
        const oud = huidig[i] || {};
        nieuw.push({
            naam:       oud.naam       || namen[i],
            maxPerHeat: oud.maxPerHeat || (i === 0 ? 6 : 4),
            methode:    oud.methode    || 'willekeurig',
            ...(i < n - 1 ? { doorstroom: oud.doorstroom || defaultDoorstroom(i) } : {}),
        });
    }
    cache.rondenConfig = nieuw;
    cache.ronde1 = null;
}

// ── Startlijst – configuratie per afstand ────────────────────────────────────

function toonAfstandConfig(cat, distId, distNaam) {
    const cacheKey = `${cat.dc_id}_${distId}`;

    if (!startlijstCache[cacheKey]) {
        startlijstCache[cacheKey] = {
            rondenConfig: null, ronde1: null,
            cFinale: { enabled: false, maxPerHeat: 5 },
            bFinale: { enabled: false },
        };
        zetAantalRondes(cacheKey, 1);
    }

    const slDist = el('sl-dist-content');
    slDist.innerHTML = `
        <div class="sl-vooraf" id="sl-vooraf">
            <div class="rondes-kiezer">
                <span class="rondes-kiezer-label">Aantal rondes:</span>
                <div class="rondes-kiezer-btns" id="rondes-kiezer-btns"></div>
            </div>
            <div id="sl-ronden-cfg" class="sl-ronden-cfg"></div>
            <div id="sl-extra-finales"></div>
            <button id="sl-genereer" class="btn-genereer">&#9654; Genereer startlijst</button>
        </div>
        <div id="sl-resultaten"></div>`;

    hertekenRondesKiezer(cacheKey);
    hertekenRondenCfg(cacheKey);

    if (startlijstCache[cacheKey].ronde1) toonAlleResultaten(cacheKey);

    el('sl-genereer').addEventListener('click', async () => {
        syncRondenCfg(cacheKey);
        await genereerAllesInEenKeer(cacheKey, cat, distId);
    });
}

// ── Knoppen 1–4 tekenen en koppelen ──────────────────────────────────────────

function hertekenRondesKiezer(cacheKey) {
    const wrap = el('rondes-kiezer-btns');
    if (!wrap) return;
    const huidig = startlijstCache[cacheKey]?.rondenConfig?.length || 1;
    wrap.innerHTML = [1, 2, 3, 4].map(k =>
        `<button class="btn-ronde-n${k === huidig ? ' actief' : ''}" data-n="${k}">${k}</button>`
    ).join('');
    wrap.querySelectorAll('[data-n]').forEach(btn =>
        btn.addEventListener('click', () => {
            zetAantalRondes(cacheKey, parseInt(btn.dataset.n));
            hertekenRondesKiezer(cacheKey);
            hertekenRondenCfg(cacheKey);
        })
    );
}

// ── Config-rijen tekenen voor alle rondes ─────────────────────────────────────

function hertekenRondenCfg(cacheKey) {
    const wrap = el('sl-ronden-cfg');
    if (!wrap) return;
    const rc = startlijstCache[cacheKey]?.rondenConfig || [];
    const n  = rc.length;
    wrap.innerHTML = '';

    rc.forEach((ronde, idx) => {
        const isLaatste = idx === n - 1;
        const ds   = ronde.doorstroom || defaultDoorstroom(idx);
        const isTs = ds.type === 'tijdsnelsten';

        const blok = document.createElement('div');
        blok.className   = 'ronde-cfg-blok';
        blok.dataset.idx = idx;

        blok.innerHTML = `
            <div class="ronde-cfg-kop">
                <span class="ronde-cfg-nr">Ronde ${idx + 1}</span>
                <input type="text" class="inp ronde-naam-inp" value="${escHtml(ronde.naam)}"
                       placeholder="Naam" data-idx="${idx}">
                <label class="ronde-max-label">Max/heat:&nbsp;
                    <input type="number" class="sl-inp ronde-max-inp" min="2" max="20"
                           value="${ronde.maxPerHeat || 6}" data-idx="${idx}">
                </label>
                ${idx === 0 ? `
                <fieldset class="sl-methode">
                    <legend>Loting</legend>
                    <label><input type="radio" name="sl-m" value="willekeurig"
                        ${(ronde.methode || 'willekeurig') === 'willekeurig' ? 'checked' : ''}> Willekeurig</label>
                    <label><input type="radio" name="sl-m" value="startnummer"
                        ${ronde.methode === 'startnummer' ? 'checked' : ''}> Op startnummer</label>
                    <label class="sl-disabled" title="Vereist klassementsdata">
                        <input type="radio" disabled> Op klassement</label>
                </fieldset>` : ''}
            </div>
            ${!isLaatste ? `
            <fieldset class="doorstroom-veld">
                <legend>Doorstroom naar ronde ${idx + 2}</legend>
                <div class="ds-opties">
                    <label class="ds-radio-label">
                        <input type="radio" name="ds-type-${idx}" value="tijdsnelsten" ${isTs ? 'checked' : ''}>
                        X tijdsnelsten
                        <span class="ds-sub">Aantal:&nbsp;<input type="number" class="sl-inp ds-ts-inp"
                            min="1" max="99" value="${ds.aantal || 16}" data-idx="${idx}"></span>
                    </label>
                    <label class="ds-radio-label">
                        <input type="radio" name="ds-type-${idx}" value="winnaars_plus_tijd" ${!isTs ? 'checked' : ''}>
                        X winnaars per heat
                        <span class="ds-sub">Winnaars/heat:&nbsp;<input type="number" class="sl-inp ds-w-inp"
                            min="1" max="10" value="${ds.winnaarsPerHeat || 2}" data-idx="${idx}"></span>
                        <span class="ds-sub">Totaal doorstromers:&nbsp;<input type="number" class="sl-inp ds-tot-inp"
                            min="1" max="99" value="${ds.totaalDoorstromers || 8}" data-idx="${idx}"></span>
                    </label>
                </div>
            </fieldset>` : ''}`;

        wrap.appendChild(blok);
    });

    wrap.querySelectorAll('[data-idx], input[name="sl-m"]').forEach(inp =>
        inp.addEventListener('change', () => syncRondenCfg(cacheKey))
    );

    hertekenExtraFinales(cacheKey);
}

// ── B/C-finale selectievakjes tekenen ────────────────────────────────────────

function hertekenExtraFinales(cacheKey) {
    const wrap = el('sl-extra-finales');
    if (!wrap) return;
    const cache = startlijstCache[cacheKey];
    const rc    = cache?.rondenConfig || [];

    if (rc.length < 2) { wrap.innerHTML = ''; return; }

    const cF  = cache.cFinale || { enabled: false, maxPerHeat: 5 };
    const bF  = cache.bFinale || { enabled: false };
    const sfNaam = rc.length >= 3 ? rc[rc.length - 2].naam : '';

    wrap.innerHTML = `
        <div class="extra-finales-blok">
            <span class="extra-finales-titel">Extra finales</span>
            <label class="extra-finale-rij">
                <input type="checkbox" id="c-finale-check" ${cF.enabled ? 'checked' : ''}>
                <span class="extra-finale-naam">C-finales</span>
                <span class="extra-finale-info">Niet-geplaatsten na ${escHtml(rc[0].naam)}</span>
                <label class="ronde-max-label">Max/heat:&nbsp;
                    <input type="number" class="sl-inp" id="c-finale-max"
                           min="2" max="30" value="${cF.maxPerHeat || 5}">
                </label>
            </label>
            ${rc.length >= 3 ? `
            <label class="extra-finale-rij">
                <input type="checkbox" id="b-finale-check" ${bF.enabled ? 'checked' : ''}>
                <span class="extra-finale-naam">B-finale(s)</span>
                <span class="extra-finale-info">Verliezers uit de ${escHtml(sfNaam)}</span>
                <label class="ronde-max-label">Max/heat:&nbsp;
                    <input type="number" class="sl-inp" id="b-finale-max"
                           min="2" max="30" value="${bF.maxPerHeat || 6}">
                </label>
            </label>` : ''}
        </div>`;

    wrap.querySelectorAll('input').forEach(inp =>
        inp.addEventListener('change', () => syncRondenCfg(cacheKey))
    );
}

// ── DOM → cache synchroniseren ───────────────────────────────────────────────

function syncRondenCfg(cacheKey) {
    const wrap = el('sl-ronden-cfg');
    if (!wrap) return;
    const rc = startlijstCache[cacheKey]?.rondenConfig;
    if (!rc) return;

    rc.forEach((ronde, idx) => {
        const naamInp = wrap.querySelector(`.ronde-naam-inp[data-idx="${idx}"]`);
        if (naamInp) ronde.naam = naamInp.value.trim();
        const maxInp  = wrap.querySelector(`.ronde-max-inp[data-idx="${idx}"]`);
        if (maxInp)  ronde.maxPerHeat = parseInt(maxInp.value) || 4;
        if (idx === 0) {
            const mInp = wrap.querySelector('input[name="sl-m"]:checked');
            if (mInp) ronde.methode = mInp.value;
        }
        if (idx < rc.length - 1) {
            if (!ronde.doorstroom) ronde.doorstroom = {};
            const dsType = wrap.querySelector(`input[name="ds-type-${idx}"]:checked`)?.value;
            if (dsType) ronde.doorstroom.type = dsType;
            const tsInp = wrap.querySelector(`.ds-ts-inp[data-idx="${idx}"]`);
            if (tsInp) ronde.doorstroom.aantal          = parseInt(tsInp.value) || 16;
            const wInp  = wrap.querySelector(`.ds-w-inp[data-idx="${idx}"]`);
            if (wInp)  ronde.doorstroom.winnaarsPerHeat = parseInt(wInp.value)  || 2;
            const totInp = wrap.querySelector(`.ds-tot-inp[data-idx="${idx}"]`);
            if (totInp) ronde.doorstroom.totaalDoorstromers = parseInt(totInp.value) || 8;
        }
    });

    const cache = startlijstCache[cacheKey];
    const cChk = el('c-finale-check'), cMax = el('c-finale-max');
    if (cChk) cache.cFinale = { enabled: cChk.checked, maxPerHeat: parseInt(cMax?.value) || 5 };
    const bChk = el('b-finale-check'), bMax = el('b-finale-max');
    if (bChk) cache.bFinale = { enabled: bChk.checked, maxPerHeat: parseInt(bMax?.value) || 6 };
}

// ── Startlijst – alles in één keer genereren ─────────────────────────────────

async function genereerAllesInEenKeer(cacheKey, cat, distId) {
    const cache     = startlijstCache[cacheKey];
    const resultDiv = el('sl-resultaten');
    resultDiv.innerHTML =
        '<div class="status-msg loading"><span class="spinner"></span>Genereren…</div>';

    const ronde0 = cache.rondenConfig[0];
    try {
        const url = `api/startlijst_genereer.php`
                  + `?competition_id=${encodeURIComponent(huidigCompId)}`
                  + `&dc_id=${encodeURIComponent(cat.dc_id)}`
                  + `&distance_id=${encodeURIComponent(distId)}`
                  + `&max_per_heat=${ronde0.maxPerHeat}`
                  + `&methode=${encodeURIComponent(ronde0.methode || 'willekeurig')}`;

        const res  = await fetch(url);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        cache.ronde1 = data;
        toonAlleResultaten(cacheKey);

    } catch(e) {
        resultDiv.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Helpers voor B/C finales ─────────────────────────────────────────────────

function berekenDoorstroom(doorstroom, nHeats) {
    if (!doorstroom) return 0;
    if (doorstroom.type === 'tijdsnelsten') return doorstroom.aantal || 0;
    return doorstroom.totaalDoorstromers || (nHeats * (doorstroom.winnaarsPerHeat || 1));
}

function maakPlaceholderGrid(heats) {
    const grid = document.createElement('div');
    grid.className = 'heat-grid';
    for (const heat of heats) {
        const card = document.createElement('div');
        card.className = 'heat-card heat-card-placeholder';
        let rows = '';
        heat.rijders.forEach((r, i) => {
            rows += `<tr><td class="heat-pos">${i + 1}</td>` +
                    `<td class="heat-naam heat-placeholder-naam">${escHtml(r.label)}</td></tr>`;
        });
        card.innerHTML =
            `<div class="heat-titel heat-titel-ph">${escHtml(heat.naam)}` +
            `<span class="heat-count">${heat.rijders.length}</span></div>` +
            `<table class="heat-tabel"><thead><tr><th>#</th><th>Deelnemer</th></tr></thead>` +
            `<tbody>${rows}</tbody></table>`;
        grid.appendChild(card);
    }
    return grid;
}

function verdeelConsolatieHeats(rijders, max, naam) {
    const n      = rijders.length;
    const nHeats = Math.max(1, Math.floor(n / max));
    const heats  = [];
    for (let h = 0; h < nHeats; h++) {
        const start = h * max;
        const end   = h === nHeats - 1 ? n : (h + 1) * max;
        heats.push({ naam: `${naam} ${h + 1}`, nummer: h + 1, rijders: rijders.slice(start, end) });
    }
    return heats;
}

// ── Alle rondes weergeven ─────────────────────────────────────────────────────

function toonAlleResultaten(cacheKey) {
    const cache     = startlijstCache[cacheKey];
    if (!cache?.ronde1) return;
    const resultDiv = el('sl-resultaten');
    if (!resultDiv) return;
    resultDiv.innerHTML = '';

    const rc         = cache.rondenConfig;
    const rondeHeats = [cache.ronde1.heats];

    const blok1 = document.createElement('div');
    blok1.className = 'ronde-blok';
    blok1.innerHTML =
        `<div class="ronde-kop"><span class="ronde-nr">Ronde 1</span>` +
        `<span class="ronde-titel">${escHtml(rc[0].naam)}</span></div>`;
    blok1.appendChild(maakHeatGrid(cache.ronde1, rc[0].methode || 'willekeurig'));
    resultDiv.appendChild(blok1);

    let vorigeHeats = cache.ronde1.heats;
    for (let idx = 1; idx < rc.length; idx++) {
        const ronde        = rc[idx];
        const doorstroom   = rc[idx - 1].doorstroom || defaultDoorstroom(idx - 1);
        const placeholders = genereerPlaceholders(vorigeHeats, doorstroom);

        let nieuweHeats;
        if (idx < rc.length - 1) {
            nieuweHeats = slangenpatroon(placeholders, ronde.maxPerHeat);
        } else {
            nieuweHeats = [];
            const cap = ronde.maxPerHeat;
            const nH  = Math.ceil(placeholders.length / cap);
            for (let h = 0; h < nH; h++)
                nieuweHeats.push({ nummer: h + 1, rijders: placeholders.slice(h * cap, (h + 1) * cap) });
        }

        rondeHeats.push(nieuweHeats);

        const rondeDiv = document.createElement('div');
        rondeDiv.className = 'ronde-blok ronde-blok-placeholder';
        rondeDiv.innerHTML =
            `<div class="ronde-kop"><span class="ronde-nr">Ronde ${idx + 1}</span>` +
            `<span class="ronde-titel">${escHtml(ronde.naam)}</span></div>`;

        const grid = document.createElement('div');
        grid.className = 'heat-grid';
        for (const heat of nieuweHeats) {
            const card = document.createElement('div');
            card.className = 'heat-card heat-card-placeholder';
            let rows = '';
            heat.rijders.forEach((r, i) => {
                rows += `<tr><td class="heat-pos">${i + 1}</td>` +
                        `<td class="heat-naam heat-placeholder-naam">${escHtml(r.label)}</td></tr>`;
            });
            card.innerHTML =
                `<div class="heat-titel heat-titel-ph">${escHtml(ronde.naam)} ${heat.nummer}` +
                `<span class="heat-count">${heat.rijders.length}</span></div>` +
                `<table class="heat-tabel"><thead><tr><th>#</th><th>Deelnemer</th></tr></thead>` +
                `<tbody>${rows}</tbody></table>`;
            grid.appendChild(card);
        }
        rondeDiv.appendChild(grid);
        resultDiv.appendChild(rondeDiv);
        vorigeHeats = nieuweHeats;
    }

    // ── C-finales ──────────────────────────────────────────────────────────────
    if (cache.cFinale?.enabled && rc.length >= 2 && rc[0].doorstroom) {
        const kw     = berekenDoorstroom(rc[0].doorstroom, cache.ronde1.aantalHeats);
        const totaal = cache.ronde1.totaalRijders;
        const nOver  = totaal - kw;

        if (nOver > 0) {
            const cRijders = Array.from({length: nOver}, (_, i) => ({
                label: `${kw + i + 1}e (${escHtml(rc[0].naam)})`,
                isPlaceholder: true,
            }));
            const cHeats = verdeelConsolatieHeats(cRijders, cache.cFinale.maxPerHeat || 5, 'C-finale');

            const cDiv = document.createElement('div');
            cDiv.className = 'ronde-blok ronde-blok-placeholder ronde-blok-cfinale';
            cDiv.innerHTML =
                `<div class="ronde-kop">` +
                `<span class="ronde-nr ronde-nr-badge ronde-nr-c">C</span>` +
                `<span class="ronde-titel">C-finale${cHeats.length > 1 ? 's' : ''}</span>` +
                `<span class="ronde-nr-info">${nOver} rijders · ${cHeats.length} heat${cHeats.length > 1 ? 's' : ''}</span>` +
                `</div>`;
            cDiv.appendChild(maakPlaceholderGrid(cHeats));
            resultDiv.appendChild(cDiv);
        }
    }

    // ── B-finales ──────────────────────────────────────────────────────────────
    if (cache.bFinale?.enabled && rc.length >= 3) {
        const sfIdx        = rc.length - 2;
        const sfNaam       = rc[sfIdx].naam;
        const sfDoorstroom = rc[sfIdx].doorstroom;
        const sfHeats      = rondeHeats[sfIdx];

        if (sfHeats && sfDoorstroom) {
            const w = sfDoorstroom.type === 'winnaars_plus_tijd'
                ? (sfDoorstroom.winnaarsPerHeat || 1)
                : 0;

            const bRijders = [];
            const maxPos = Math.max(...sfHeats.map(h => h.rijders.length));
            for (let p = w + 1; p <= maxPos; p++)
                for (const sfHeat of sfHeats)
                    if (p <= sfHeat.rijders.length)
                        bRijders.push({ label: `${posLabel(p)} ${sfNaam} ${sfHeat.nummer}`, isPlaceholder: true });

            if (bRijders.length > 0) {
                const bMax   = cache.bFinale.maxPerHeat || bRijders.length;
                const bHeats = verdeelConsolatieHeats(bRijders, bMax, 'B-finale');

                const bDiv = document.createElement('div');
                bDiv.className = 'ronde-blok ronde-blok-placeholder ronde-blok-bfinale';
                bDiv.innerHTML =
                    `<div class="ronde-kop">` +
                    `<span class="ronde-nr ronde-nr-badge ronde-nr-b">B</span>` +
                    `<span class="ronde-titel">B-finale${bHeats.length > 1 ? 's' : ''}</span>` +
                    `<span class="ronde-nr-info">${bRijders.length} rijders · ${bHeats.length} heat${bHeats.length > 1 ? 's' : ''}</span>` +
                    `</div>`;
                bDiv.appendChild(maakPlaceholderGrid(bHeats));
                resultDiv.appendChild(bDiv);
            }
        }
    }
}

// ── Heat grid als DOM-element ──────────────────────────────────────────────────

function maakHeatGrid(data, methode) {
    const methodeLabel = {
        willekeurig: 'Willekeurig geloot',
        startnummer:  'Op startnummer',
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
        heat.rijders.forEach((r, i) => {
            // Prioriteit: sessie-selectie (personEdits) → DB-waarde uit API (slot 0) → fallback
            const lk       = r.license_key;
            const tpActief = personEdits[lk]?.transponder_actief;
            const tp = tpActief !== undefined
                ? (tpActief || '—')                                                             // sessie: null = geen
                : (r.transponder_actief || r.transponders_extra?.[0] || r.transponder1 || r.transponder2 || '—');  // DB of fallback
            const sn = r.start_number ? `<span class="heat-sn">${r.start_number}</span>` : '';
            rows += `<tr><td class="heat-pos">${i + 1}</td>` +
                    `<td class="heat-naam">${sn}${escHtml(r.full_name)}</td>` +
                    `<td class="heat-tp">${escHtml(tp)}</td></tr>`;
        });
        card.innerHTML =
            `<div class="heat-titel">Heat ${heat.nummer}` +
            `<span class="heat-count">${heat.rijders.length}</span></div>` +
            `<table class="heat-tabel"><thead><tr><th>#</th><th>Naam</th><th>Transponder</th></tr></thead>` +
            `<tbody>${rows}</tbody></table>`;
        grid.appendChild(card);
    }
    wrapper.appendChild(grid);
    return wrapper;
}
