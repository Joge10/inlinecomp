// ============================================================
//  InlineComp – Serie-klassement wizard UI
//
//  Entry point: openSerieWizard({ orgId, serieId? })
//  - serieId ontbreekt → nieuwe serie aanmaken
//  - serieId aanwezig  → bestaande serie bewerken
// ============================================================

const SERIE_DEFAULT_REGELS = {
    type: 'gecombineerd',
    afstand_filter: 'alle',
    afstand_namen: [],
    categorie_filter: [],       // [] = alle cats. Bv. ['HKA','DKA','HJB','DJB']
    punten_tabel: [50.1,47,45,43,41,39,37,35,33,31,30,29,28,27,26,25,24,23,22,21,20,19,18,17,16,15,14,13,12,11,10,9,8,7,6,5,4,3,2,1],
    min_punten_bij_deelname: 1,
    tie_break: 'beste_resultaten_dan_laatste',
    vereist_finale: false,
    streepresultaten: 0,
    streep_direct: false,
    min_deelnames: 0,
    non_deelname_punten: false,
};

async function openSerieWizard({ orgId = '', serieId = null } = {}) {
    // Huidige state in het wizard-modaal
    const state = {
        stap: 1,
        orgId,
        serieId,
        naam: '',
        seizoen: '',
        wedstrijden: [],    // [{competition_id, name, starts, telt_mee, is_finale, volgorde, _checked}]
        regels: { ...SERIE_DEFAULT_REGELS },
        presets: [],        // lijst van beschikbare presets voor deze org
    };

    // Bij bewerken: laad bestaande serie
    if (serieId) {
        try {
            const s = await rkGet(`api/klassement_serie.php?action=get&id=${encodeURIComponent(serieId)}`);
            state.naam    = s.naam ?? '';
            state.seizoen = s.seizoen ?? '';
            state.orgId   = s.org_id ?? orgId;
            state.regels  = { ...SERIE_DEFAULT_REGELS, ...(s.regels ?? {}) };
            state.wedstrijden = (s.wedstrijden ?? []).map(w => ({
                competition_id: w.competition_id,
                name:           w.name,
                starts:         w.starts,
                telt_mee:       !!+w.telt_mee,
                is_finale:      !!+w.is_finale,
                volgorde:       +w.volgorde || 0,
                _checked:       true,
                _geimporteerd:  !!+w.geimporteerd,
            }));
        } catch (e) {
            alert('Fout bij laden: ' + e.message);
            return;
        }
    }

    // Laad wedstrijden van deze organisatie (als nieuw) + merge met bestaande
    await _laadWedstrijdenVoorOrg(state);

    // Laad presets (org-specifiek + globaal)
    await _laadPresets(state);

    _renderWizard(state);
}

async function _laadWedstrijdenVoorOrg(state) {
    if (!state.orgId) return;
    const bestaande = new Set(state.wedstrijden.map(w => w.competition_id));
    try {
        // Server filtert zelf op org-email + aliassen en voegt DB-wedstrijden
        // én KNSB-toekomstige wedstrijden samen. Eén call, één filter.
        const lijst = await rkGet(
            `api/klassement_serie.php?action=kandidaat_wedstrijden&org_id=${encodeURIComponent(state.orgId)}`
        );
        for (const c of (Array.isArray(lijst) ? lijst : [])) {
            if (bestaande.has(c.competition_id)) continue;
            state.wedstrijden.push({
                competition_id: c.competition_id,
                name:           c.name,
                starts:         c.starts,
                telt_mee:       true,
                is_finale:      false,
                volgorde:       state.wedstrijden.length,
                _checked:       false,
                _geimporteerd:  !!c.geimporteerd,
            });
            bestaande.add(c.competition_id);
        }
        state.wedstrijden.sort((a,b) => String(a.starts ?? '').localeCompare(String(b.starts ?? '')));
    } catch { /* empty-state */ }
}

async function _laadPresets(state) {
    try {
        const url = state.orgId
            ? `api/klassement_preset.php?action=list&org_id=${encodeURIComponent(state.orgId)}`
            : 'api/klassement_preset.php?action=list';
        state.presets = await rkGet(url);
    } catch { state.presets = []; }
}

// ── Modal-skelet ─────────────────────────────────────────────────────────────
function _renderWizard(state) {
    // Verwijder eventueel bestaand wizard-modal
    document.getElementById('ks-wizard')?.remove();

    const overlay = document.createElement('div');
    overlay.id = 'ks-wizard';
    overlay.className = 'ks-overlay';
    overlay.innerHTML = `
        <div class="ks-box">
            <div class="ks-hdr">
                <span>${state.serieId ? 'Serie-klassement bewerken' : 'Nieuw serie-klassement'} — stap ${state.stap}/3</span>
                <button class="ks-sluit" title="Sluiten">&times;</button>
            </div>
            <div class="ks-body" id="ks-body"></div>
            <div class="ks-voet">
                <button class="btn-secondary" id="ks-prev" ${state.stap === 1 ? 'disabled' : ''}>← Vorige</button>
                <span class="ks-stappen">
                    ${[1,2,3].map(s => `<span class="ks-stap-dot ${s === state.stap ? 'actief' : ''} ${s < state.stap ? 'klaar' : ''}"></span>`).join('')}
                </span>
                ${state.stap < 3
                    ? `<button class="btn-primary" id="ks-next">Volgende →</button>`
                    : `<button class="btn-primary" id="ks-opslaan">${state.serieId ? 'Opslaan & herberekenen' : 'Aanmaken & berekenen'}</button>`
                }
            </div>
        </div>`;
    document.body.appendChild(overlay);

    overlay.querySelector('.ks-sluit').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.querySelector('#ks-prev')?.addEventListener('click', () => { state.stap--; _renderWizard(state); });
    overlay.querySelector('#ks-next')?.addEventListener('click', () => {
        if (_validateStap(state)) { state.stap++; _renderWizard(state); }
    });
    overlay.querySelector('#ks-opslaan')?.addEventListener('click', () => _opslaan(state));

    _renderStap(state);
}

function _validateStap(state) {
    if (state.stap === 1) {
        if (!state.naam?.trim()) { alert('Geef het klassement een naam.'); return false; }
    }
    if (state.stap === 2) {
        const gekozen = state.wedstrijden.filter(w => w._checked);
        if (!gekozen.length) { alert('Selecteer minstens één wedstrijd.'); return false; }
        const finales = gekozen.filter(w => w.is_finale);
        if (finales.length > 1) { alert('Er kan slechts één wedstrijd als finale aangevinkt zijn.'); return false; }
    }
    return true;
}

function _renderStap(state) {
    const body = document.getElementById('ks-body');
    if (!body) return;
    if      (state.stap === 1) _renderStap1(state, body);
    else if (state.stap === 2) _renderStap2(state, body);
    else if (state.stap === 3) _renderStap3(state, body);
}

// ── Stap 1: basis ────────────────────────────────────────────────────────────
function _renderStap1(state, body) {
    body.innerHTML = `
        <div class="ks-veld">
            <label>Naam klassement</label>
            <input type="text" class="inp" id="ks-naam" value="${rkEsc(state.naam)}" placeholder="Bijv. Regio Allround 2026">
        </div>
        <div class="ks-veld">
            <label>Seizoen (optioneel)</label>
            <input type="text" class="inp" id="ks-seizoen" value="${rkEsc(state.seizoen)}" placeholder="Bijv. 2025-2026">
        </div>
        <div class="ks-hint">
            <b>Hoe werkt dit?</b><br>
            Je kiest straks welke wedstrijden in dit klassement meetellen en welke regels gelden
            (puntentabel, streepresultaten, tie-break, etc.). Het klassement wordt dan automatisch
            berekend uit de uitslagen en verschijnt in de klassementen-lijst — precies zoals een
            geïmporteerd PDF-klassement. Je kunt het gebruiken als seeding voor nieuwe wedstrijden.
        </div>`;
    body.querySelector('#ks-naam').addEventListener('input', e => state.naam = e.target.value);
    body.querySelector('#ks-seizoen').addEventListener('input', e => state.seizoen = e.target.value);
}

// ── Stap 2: wedstrijden ─────────────────────────────────────────────────────
function _renderStap2(state, body) {
    if (!state.wedstrijden.length) {
        body.innerHTML = `<div class="ks-leeg">Geen wedstrijden gevonden voor deze organisatie.</div>`;
        return;
    }

    const renderRijen = (filter = '') => {
        const f = filter.trim().toLowerCase();
        return state.wedstrijden.map((w, i) => {
            if (f && !((w.name || '').toLowerCase().includes(f))) return '';
            const dt = w.starts ? new Date(String(w.starts).replace(' ','T')).toLocaleDateString('nl-NL', {day:'numeric', month:'short', year:'numeric'}) : '';
            const badge = (w._geimporteerd === false)
                ? ' <span class="ks-w-stub" title="Zit nog niet in de eigen DB — wordt als stub aangemaakt bij opslaan">📅 nog te importeren</span>'
                : '';
            return `<tr data-idx="${i}">
                <td><input type="checkbox" class="ks-w-check" ${w._checked ? 'checked' : ''}></td>
                <td><input type="checkbox" class="ks-w-telt"  ${w.telt_mee  ? 'checked' : ''} ${w._checked ? '' : 'disabled'} title="Telt mee (uit zetten = opgenomen maar niet meetellend)"></td>
                <td><input type="radio" name="ks-w-finale" class="ks-w-finale" ${w.is_finale ? 'checked' : ''} ${w._checked ? '' : 'disabled'}></td>
                <td class="ks-w-naam">${rkEsc(w.name)}${badge}</td>
                <td class="ks-w-datum">${rkEsc(dt)}</td>
            </tr>`;
        }).join('');
    };

    body.innerHTML = `
        <div class="ks-hint">
            Selecteer de wedstrijden die bij deze serie horen. Wedstrijden uit de KNSB-API worden
            getoond als ze qua email of naam overeenkomen met deze organisatie — gebruik het
            zoekveld hieronder als je iets mist.<br>
            <b>Telt mee</b> kun je uitzetten om een wedstrijd wel in de lijst te houden maar niet mee te rekenen
            (bv. oefenwedstrijd of achteraf afgelast). <b>Finale</b> markeert de afsluitende wedstrijd — wordt
            gebruikt voor tie-break en streepresultaten-gating.
        </div>
        <div class="ks-veld">
            <input type="text" class="inp" id="ks-w-zoek" placeholder="🔎 Filter op naam…">
        </div>
        <table class="ks-w-tabel">
            <thead><tr>
                <th>Mee</th><th>Telt</th><th>Finale</th><th>Naam</th><th>Datum</th>
            </tr></thead>
            <tbody id="ks-w-tbody">${renderRijen('')}</tbody>
        </table>
        <div style="text-align:right;font-size:.8rem;color:#666;margin-top:6px">
            ${state.wedstrijden.length} wedstrijden in lijst
        </div>`;

    const wireRijen = () => {
        body.querySelectorAll('tr[data-idx]').forEach(tr => {
            const i = +tr.dataset.idx;
            const w = state.wedstrijden[i];
            tr.querySelector('.ks-w-check').addEventListener('change', e => {
                w._checked = e.target.checked;
                tr.querySelector('.ks-w-telt').disabled = !w._checked;
                tr.querySelector('.ks-w-finale').disabled = !w._checked;
                if (!w._checked) { w.is_finale = false; tr.querySelector('.ks-w-finale').checked = false; }
            });
            tr.querySelector('.ks-w-telt').addEventListener('change', e => w.telt_mee = e.target.checked);
            tr.querySelector('.ks-w-finale').addEventListener('change', () => {
                // Radio-gedrag: de andere is_finale's uitzetten (in state + DOM)
                state.wedstrijden.forEach(ww => ww.is_finale = false);
                w.is_finale = true;
            });
        });
    };

    // Filter-input re-rendert alleen de tbody (state blijft intact)
    body.querySelector('#ks-w-zoek').addEventListener('input', e => {
        body.querySelector('#ks-w-tbody').innerHTML = renderRijen(e.target.value);
        wireRijen();
    });
    wireRijen();
}

// ── Stap 3: regels + presets ────────────────────────────────────────────────
function _renderStap3(state, body) {
    const r = state.regels;
    const presetOpties = state.presets.map(p =>
        `<option value="${rkEsc(p.id)}">${rkEsc(p.naam)}${p.org_id ? '' : ' (globaal)'}</option>`).join('');

    body.innerHTML = `
        ${state.presets.length ? `
            <div class="ks-veld">
                <label>Preset toepassen</label>
                <select class="inp" id="ks-preset">
                    <option value="">— kies preset (optioneel) —</option>
                    ${presetOpties}
                </select>
                <div class="ks-hint">Kies een opgeslagen regel-set om velden hieronder in te vullen.</div>
            </div>` : ''}

        <div class="ks-veld">
            <label>Type klassement</label>
            <div style="display:flex;flex-direction:column;gap:6px;font-size:12.5px">
                <label><input type="radio" name="ks-type" value="custom"
                    ${r.type !== 'gecombineerd' ? 'checked' : ''}>
                    <b>Per afstand</b> — kies hieronder welke afstanden meedoen; punten per afstand worden opgeteld</label>
                <label><input type="radio" name="ks-type" value="gecombineerd"
                    ${r.type === 'gecombineerd' ? 'checked' : ''}>
                    <b>Gecombineerd (DC-eindklassement)</b> — pakt het DC-eindklassement (combinatie van alle afstanden binnen een categorie, bv. 500m + 1000m samen) i.p.v. afzonderlijke afstanden</label>
            </div>
        </div>

        <!-- Afstanden-multi-select: alleen zichtbaar bij type='custom' (per-afstand). -->
        <div class="ks-veld" id="ks-afst-veld" style="${r.type === 'gecombineerd' ? 'display:none' : ''}">
            <label>Afstanden voor dit klassement <span style="color:#666;font-weight:400;font-size:11.5px">(niets aangevinkt = niets telt mee)</span></label>
            <div id="ks-afst-filter-wrap" style="border:1px solid var(--border);background:#fafbfc;border-radius:4px;padding:6px;min-height:36px">
                <em style="color:#888;font-size:11.5px">Afstanden laden uit geselecteerde wedstrijden…</em>
            </div>
            <div style="font-size:11.5px;color:#666;margin:4px 0 0 2px">
                Vink aan welke afstanden meetellen. De punten per geselecteerde afstand
                worden per rijder opgeteld tot een serie-totaal.<br>
                Voorbeeld <b>1000m-serie</b>: alleen "1000 meter" aankruisen.<br>
                Voorbeeld <b>sprint-serie</b>: 100m / 200m / 300m / 500m aankruisen.
            </div>
        </div>

        <div class="ks-veld">
            <label>Punten-tabel (komma-gescheiden, index 0 = 1e plek)</label>
            <textarea class="inp" id="ks-tabel" rows="2">${rkEsc((r.punten_tabel ?? []).join(', '))}</textarea>
            <div class="ks-hint">Rangen buiten de tabel krijgen "min. punten bij deelname".</div>
        </div>

        <div class="ks-rij2">
            <div class="ks-veld">
                <label>Min. punten bij deelname</label>
                <input type="number" class="inp" id="ks-min-pnt" step="0.1" value="${r.min_punten_bij_deelname}">
            </div>
            <div class="ks-veld">
                <label>Streepresultaten (slechtste N wegstrepen)</label>
                <input type="number" class="inp" id="ks-streep" min="0" value="${r.streepresultaten}">
                <label style="display:block;margin-top:6px;font-size:11.5px"><input type="checkbox" id="ks-streep-direct" ${r.streep_direct?'checked':''}> Direct toepassen (ook in tussenstand)</label>
            </div>
        </div>

        <div class="ks-rij2">
            <div class="ks-veld">
                <label title="Bij gelijke totalen: hoe wordt de tie-break bepaald?&#10;'Laatste wedstrijd' valt terug: bij gelijk → voorlaatste → daarvoor → enz.">Tie-break</label>
                <select class="inp" id="ks-tie">
                    <option value="beste_resultaten_dan_laatste" ${r.tie_break==='beste_resultaten_dan_laatste'?'selected':''}>Beste resultaten → dan laatste wedstrijd (terugvallend)</option>
                    <option value="beste_resultaten"             ${r.tie_break==='beste_resultaten'?'selected':''}>Alleen beste resultaten</option>
                    <option value="laatste"                      ${r.tie_break==='laatste'?'selected':''}>Alleen laatste wedstrijd (terugvallend)</option>
                    <option value="geen"                         ${r.tie_break==='geen'?'selected':''}>Geen (ex aequo toegestaan)</option>
                </select>
                <div class="ks-hint">
                    "Laatste wedstrijd" valt bij gelijk terug op voorlaatste, daarvoor, enz.
                </div>
            </div>
            <div class="ks-veld">
                <label>Min. deelnames</label>
                <input type="number" class="inp" id="ks-min-d" min="0" value="${r.min_deelnames}">
            </div>
        </div>

        <div class="ks-veld">
            <label>Categorie-filter <span style="color:#666;font-weight:400;font-size:11.5px">(niets aangevinkt = alle categorieën)</span></label>
            <div id="ks-cat-filter-wrap" style="border:1px solid var(--border);background:#fafbfc;border-radius:4px;padding:6px;min-height:36px">
                <em style="color:#888;font-size:11.5px">Categorieën laden uit geselecteerde wedstrijden…</em>
            </div>
            <div style="font-size:11.5px;color:#666;margin:4px 0 0 2px">
                Vink aan welke categorieën meedoen in dit klassement. Voorbeelden:<br>
                <b>Combi-klassement</b> alleen voor Kadetten + Junioren B → kruis HKA, DKA, HKB, DKB, HJB, DJB aan.<br>
                <b>Sprint/lang-klassement</b> alleen voor senioren → kruis HSA, DSA, HSB, DSB aan.<br>
                Niets aangevinkt = geen filter, alle categorieën doen mee.
            </div>
        </div>

        <div class="ks-veld">
            <label><input type="checkbox" id="ks-vereist-finale" ${r.vereist_finale?'checked':''}> Finale-aanwezigheid verplicht voor klassering</label>
        </div>

        <div class="ks-veld">
            <label><input type="checkbox" id="ks-non-deelname" ${r.non_deelname_punten?'checked':''}> Punten voor niet-deelname (rang laatste + 1)</label>
            <div style="font-size:11.5px;color:#666;margin:4px 0 0 22px">
                Rijders die wél elders in deze serie scoren maar in een specifieke wedstrijd
                ontbraken (of punten = 0 hadden), krijgen voor die wedstrijd de punten op
                rang "laatste deelnemer + 1" uit de tabel. Bij meerdere afwezigen krijgen
                ze allemaal dezelfde rang.
            </div>
        </div>

        <div class="ks-veld" style="border-top:1px solid #eee;padding-top:12px;margin-top:12px">
            <label><input type="checkbox" id="ks-save-preset"> Regels opslaan als preset voor deze organisatie</label>
            <input type="text" class="inp" id="ks-preset-naam" placeholder="Preset-naam (bv. 'KNSB tabel 2026')" style="display:none">
        </div>`;

    // ── Event wiring ─────────
    const get = id => body.querySelector(id);

    // Type-radio's: schakelt tussen Per-afstand (custom + per_naam-filter)
    // en Gecombineerd (= DC-eindklassement-pad). Toont/verbergt het
    // afstanden-multi-select-veld.
    body.querySelectorAll('input[name="ks-type"]').forEach(rb => {
        rb.addEventListener('change', e => {
            r.type = e.target.value;
            if (r.type === 'gecombineerd') {
                r.afstand_filter = 'alle';
                r.afstand_namen = [];
                body.querySelector('#ks-afst-veld').style.display = 'none';
            } else {
                r.type = 'custom';
                r.afstand_filter = 'per_naam';
                // afstand_namen blijft leeg tot operator iets aanvinkt;
                // multi-select verschijnt direct.
                body.querySelector('#ks-afst-veld').style.display = '';
            }
        });
    });
    get('#ks-tabel').addEventListener('input', e => {
        r.punten_tabel = e.target.value.split(',').map(s => parseFloat(s.trim())).filter(n => !isNaN(n));
    });
    get('#ks-min-pnt').addEventListener('input', e => r.min_punten_bij_deelname = parseFloat(e.target.value) || 0);

    // ── Categorie-filter: ophalen + checkbox-grid bouwen ─────────────
    // Cats komen uit de daadwerkelijk-aangevinkte wedstrijden zodat de
    // operator alleen kan kiezen uit wat in deze serie voorkomt. Geen
    // typefouten meer, geen vergeten cats.
    (async () => {
        const wrap = get('#ks-cat-filter-wrap');
        if (!wrap) return;
        const compIds = (state.wedstrijden || [])
            .filter(w => w.telt_mee !== false)
            .map(w => w.competition_id)
            .filter(Boolean);
        if (!compIds.length) {
            wrap.innerHTML = '<em style="color:#888;font-size:11.5px">Selecteer eerst wedstrijden in stap 2.</em>';
            return;
        }
        try {
            const res = await fetch(`api/klassement_serie.php?action=categorieen_van_wedstrijden&comp_ids=${encodeURIComponent(compIds.join(','))}`);
            const cats = await res.json();
            if (!Array.isArray(cats) || !cats.length) {
                wrap.innerHTML = '<em style="color:#888;font-size:11.5px">Geen categorieën gevonden in de geselecteerde wedstrijden.</em>';
                return;
            }
            const aangevinkt = new Set((r.categorie_filter ?? []).map(c => String(c).toUpperCase()));
            // Render als grid van checkbox-knopjes — compact en aanklikbaar
            wrap.innerHTML = `
                <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:4px">
                    ${cats.map(c => `
                        <label style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border:1px solid var(--border);border-radius:12px;background:${aangevinkt.has(c) ? '#dceaf5' : '#fff'};font-size:11.5px;cursor:pointer">
                            <input type="checkbox" class="ks-cat-cb" data-cat="${rkEsc(c)}" ${aangevinkt.has(c) ? 'checked' : ''} style="margin:0">
                            <span>${rkEsc(c)}</span>
                        </label>`).join('')}
                </div>
                <div style="display:flex;gap:8px;font-size:11px">
                    <button type="button" id="ks-cat-allemaal" class="btn-secondary" style="font-size:11px;padding:2px 8px">Alle aanvinken</button>
                    <button type="button" id="ks-cat-geen"     class="btn-secondary" style="font-size:11px;padding:2px 8px">Niets aanvinken</button>
                    <span id="ks-cat-aantal" style="margin-left:auto;color:#666;font-style:italic">${aangevinkt.size} van ${cats.length} aangevinkt</span>
                </div>`;
            const updateState = () => {
                const aan = Array.from(wrap.querySelectorAll('.ks-cat-cb:checked')).map(cb => cb.dataset.cat);
                r.categorie_filter = aan;
                wrap.querySelectorAll('.ks-cat-cb').forEach(cb => {
                    cb.closest('label').style.background = cb.checked ? '#dceaf5' : '#fff';
                });
                const teller = wrap.querySelector('#ks-cat-aantal');
                if (teller) teller.textContent = `${aan.length} van ${cats.length} aangevinkt`;
            };
            wrap.querySelectorAll('.ks-cat-cb').forEach(cb => cb.addEventListener('change', updateState));
            wrap.querySelector('#ks-cat-allemaal')?.addEventListener('click', () => {
                wrap.querySelectorAll('.ks-cat-cb').forEach(cb => cb.checked = true);
                updateState();
            });
            wrap.querySelector('#ks-cat-geen')?.addEventListener('click', () => {
                wrap.querySelectorAll('.ks-cat-cb').forEach(cb => cb.checked = false);
                updateState();
            });
        } catch (e) {
            wrap.innerHTML = `<em style="color:#b71c1c;font-size:11.5px">⚠ Categorieën laden mislukt: ${rkEsc(e.message)}</em>`;
        }
    })();

    // ── Afstanden-multi-select: zelfde patroon als categorie-filter ──
    // Lijst komt uit de daadwerkelijk-geselecteerde wedstrijden zodat
    // operator alleen kan kiezen uit wat in deze serie voorkomt. Past
    // automatisch aan als de wedstrijd-set in stap 2 wijzigt (door bij
    // open van stap 3 opnieuw op te halen).
    (async () => {
        const wrap = get('#ks-afst-filter-wrap');
        if (!wrap) return;
        const compIds = (state.wedstrijden || [])
            .filter(w => w.telt_mee !== false)
            .map(w => w.competition_id)
            .filter(Boolean);
        if (!compIds.length) {
            wrap.innerHTML = '<em style="color:#888;font-size:11.5px">Selecteer eerst wedstrijden in stap 2.</em>';
            return;
        }
        try {
            const res = await fetch(`api/klassement_serie.php?action=afstanden_van_wedstrijden&comp_ids=${encodeURIComponent(compIds.join(','))}`);
            const afstanden = await res.json();
            if (!Array.isArray(afstanden) || !afstanden.length) {
                wrap.innerHTML = '<em style="color:#888;font-size:11.5px">Geen afstanden gevonden in de geselecteerde wedstrijden.</em>';
                return;
            }
            // Backwards-compat: oude series met type='sprint'/'lang' hebben
            // geen afstand_namen — vink dan de juiste afstanden voor (sprint
            // = race_type='sprint', lang = race_type<>'sprint'). Bij opslaan
            // worden ze vanaf nu altijd als 'per_naam' opgeslagen.
            let aangevinktSet;
            if (r.afstand_filter === 'per_naam' && Array.isArray(r.afstand_namen)) {
                aangevinktSet = new Set(r.afstand_namen);
            } else if (r.afstand_filter === 'sprint') {
                aangevinktSet = new Set(afstanden.filter(a => a.race_type === 'sprint').map(a => a.naam));
            } else if (r.afstand_filter === 'lang') {
                aangevinktSet = new Set(afstanden.filter(a => a.race_type !== 'sprint').map(a => a.naam));
            } else {
                aangevinktSet = new Set();
            }
            // Render checkbox-grid
            wrap.innerHTML = `
                <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:4px">
                    ${afstanden.map(a => {
                        const aan = aangevinktSet.has(a.naam);
                        const rtBadge = a.race_type === 'sprint' ? ' <small style="color:#888">sprint</small>' : '';
                        return `<label style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border:1px solid var(--border);border-radius:12px;background:${aan ? '#dceaf5' : '#fff'};font-size:11.5px;cursor:pointer">
                            <input type="checkbox" class="ks-afst-cb" data-naam="${rkEsc(a.naam)}" ${aan ? 'checked' : ''} style="margin:0">
                            <span>${rkEsc(a.naam)}${rtBadge}</span>
                        </label>`;
                    }).join('')}
                </div>
                <div style="display:flex;gap:8px;font-size:11px">
                    <button type="button" id="ks-afst-allemaal" class="btn-secondary" style="font-size:11px;padding:2px 8px">Alle aanvinken</button>
                    <button type="button" id="ks-afst-sprint"   class="btn-secondary" style="font-size:11px;padding:2px 8px">Alleen sprint</button>
                    <button type="button" id="ks-afst-lang"     class="btn-secondary" style="font-size:11px;padding:2px 8px">Alleen lange afstand</button>
                    <button type="button" id="ks-afst-geen"     class="btn-secondary" style="font-size:11px;padding:2px 8px">Niets aanvinken</button>
                    <span id="ks-afst-aantal" style="margin-left:auto;color:#666;font-style:italic">${aangevinktSet.size} van ${afstanden.length} aangevinkt</span>
                </div>`;
            const updateState = () => {
                const aan = Array.from(wrap.querySelectorAll('.ks-afst-cb:checked')).map(cb => cb.dataset.naam);
                r.afstand_namen = aan;
                // Forceer per_naam-mode zodra operator hier dingen aanvinkt;
                // overschrijft eventuele legacy 'sprint'/'lang' filter.
                r.afstand_filter = 'per_naam';
                r.type = 'custom';
                wrap.querySelectorAll('.ks-afst-cb').forEach(cb => {
                    cb.closest('label').style.background = cb.checked ? '#dceaf5' : '#fff';
                });
                const teller = wrap.querySelector('#ks-afst-aantal');
                if (teller) teller.textContent = `${aan.length} van ${afstanden.length} aangevinkt`;
            };
            // Persist initial state (in case it kwam uit sprint/lang back-compat)
            updateState();
            wrap.querySelectorAll('.ks-afst-cb').forEach(cb => cb.addEventListener('change', updateState));
            wrap.querySelector('#ks-afst-allemaal')?.addEventListener('click', () => {
                wrap.querySelectorAll('.ks-afst-cb').forEach(cb => cb.checked = true);
                updateState();
            });
            wrap.querySelector('#ks-afst-sprint')?.addEventListener('click', () => {
                wrap.querySelectorAll('.ks-afst-cb').forEach(cb => {
                    const naam = cb.dataset.naam;
                    const isSprint = afstanden.find(a => a.naam === naam)?.race_type === 'sprint';
                    cb.checked = !!isSprint;
                });
                updateState();
            });
            wrap.querySelector('#ks-afst-lang')?.addEventListener('click', () => {
                wrap.querySelectorAll('.ks-afst-cb').forEach(cb => {
                    const naam = cb.dataset.naam;
                    const isSprint = afstanden.find(a => a.naam === naam)?.race_type === 'sprint';
                    cb.checked = !isSprint;
                });
                updateState();
            });
            wrap.querySelector('#ks-afst-geen')?.addEventListener('click', () => {
                wrap.querySelectorAll('.ks-afst-cb').forEach(cb => cb.checked = false);
                updateState();
            });
        } catch (e) {
            wrap.innerHTML = `<em style="color:#b71c1c;font-size:11.5px">⚠ Afstanden laden mislukt: ${rkEsc(e.message)}</em>`;
        }
    })();

    get('#ks-streep').addEventListener('input',  e => r.streepresultaten        = Math.max(0, parseInt(e.target.value) || 0));
    get('#ks-streep-direct').addEventListener('change', e => r.streep_direct    = e.target.checked);
    get('#ks-tie').addEventListener('change',    e => r.tie_break               = e.target.value);
    get('#ks-min-d').addEventListener('input',   e => r.min_deelnames           = Math.max(0, parseInt(e.target.value) || 0));
    get('#ks-vereist-finale').addEventListener('change', e => r.vereist_finale  = e.target.checked);
    get('#ks-non-deelname').addEventListener('change',   e => r.non_deelname_punten = e.target.checked);

    get('#ks-save-preset')?.addEventListener('change', e => {
        get('#ks-preset-naam').style.display = e.target.checked ? '' : 'none';
    });

    get('#ks-preset')?.addEventListener('change', e => {
        const p = state.presets.find(x => x.id === e.target.value);
        if (!p) return;
        state.regels = { ...SERIE_DEFAULT_REGELS, ...(p.regels ?? {}) };
        _renderStap3(state, body); // her-render met nieuwe waarden
    });
}

// ── Opslaan + eventueel preset bewaren ──────────────────────────────────────
async function _opslaan(state) {
    if (!_validateStap(state)) return;
    const btn = document.getElementById('ks-opslaan');
    if (btn) { btn.disabled = true; btn.textContent = 'Bezig…'; }

    const payload = {
        naam:        state.naam.trim(),
        seizoen:     state.seizoen.trim() || null,
        org_id:      state.orgId || null,
        regels:      state.regels,
        wedstrijden: state.wedstrijden.filter(w => w._checked).map((w, i) => ({
            competition_id: w.competition_id,
            telt_mee:       w.telt_mee ? 1 : 0,
            is_finale:      w.is_finale ? 1 : 0,
            volgorde:       i,
            // Voor nog-niet-geïmporteerde wedstrijden: naam + datum
            // bijhouden op de koppelrij (geen shadow in `competitions`).
            comp_naam:      w._geimporteerd === false ? (w.name  ?? '') : '',
            comp_datum:     w._geimporteerd === false ? (w.starts ?? '') : '',
        })),
    };

    try {
        const url = state.serieId
            ? `api/klassement_serie.php?action=update&id=${encodeURIComponent(state.serieId)}`
            : 'api/klassement_serie.php?action=create';
        const resp = await rkPost(url, JSON.stringify(payload), 'application/json');
        if (resp.error) throw new Error(resp.error);

        // Eventueel preset opslaan
        const savePreset = document.getElementById('ks-save-preset')?.checked;
        if (savePreset) {
            const pnaam = document.getElementById('ks-preset-naam')?.value?.trim();
            if (pnaam) {
                await rkPost('api/klassement_preset.php?action=create',
                    JSON.stringify({ naam: pnaam, org_id: state.orgId || null, regels: state.regels }),
                    'application/json'
                );
            }
        }

        document.getElementById('ks-wizard')?.remove();
        await laadLijst();
        // Open het nieuwe/bijgewerkte klassement in de detail-view
        const klId = resp.klassement_id ?? (await rkGet(
            `api/klassement_serie.php?action=get&id=${encodeURIComponent(state.serieId ?? resp.id)}`
        )).klassement_id;
        if (klId) await openKlassement(klId);
    } catch (e) {
        alert('Fout bij opslaan: ' + e.message);
        if (btn) { btn.disabled = false; btn.textContent = state.serieId ? 'Opslaan & herberekenen' : 'Aanmaken & berekenen'; }
    }
}

// ── Herbereken (knop in detail-view) ────────────────────────────────────────
async function herbereken(serieId) {
    try {
        const r = await rkPost(`api/klassement_serie.php?action=berekenen&id=${encodeURIComponent(serieId)}`, '', 'application/json');
        if (r.error) throw new Error(r.error);
        // Huidig klassement opnieuw laden
        if (rkHuidig?.id) await openKlassement(rkHuidig.id);
        await laadLijst();
    } catch (e) { alert('Fout: ' + e.message); }
}

// ── Diagnose (knop in detail-view) ──────────────────────────────────────────
async function diagnoseSerieer(serieId) {
    try {
        const resp = await rkGet(`api/klassement_serie.php?action=diag&id=${encodeURIComponent(serieId)}`);
        // Nieuw response-format: { wedstrijden, regels, deep } — fallback naar oud array
        const rows  = Array.isArray(resp) ? resp : (resp.wedstrijden || []);
        const deep  = Array.isArray(resp) ? []   : (resp.deep || []);
        const regels = Array.isArray(resp) ? {}  : (resp.regels || {});
        const rijen = rows.map(r => {
            const dt = r.starts ? new Date(String(r.starts).replace(' ','T')).toLocaleDateString('nl-NL',
                { day:'numeric', month:'short', year:'numeric' }) : '—';
            const imp   = +r.geimporteerd ? '✅' : '⚠️';
            const telt  = +r.telt_mee  ? '✅' : '—';
            const fin   = +r.is_finale ? '🏁' : '';
            const uk    = +r.uk_rijen;
            const ua    = +r.ua_rijen;
            const status = uk > 0
                ? `✅ ${uk} rijen`
                : (ua > 0
                    ? `⚠️ 0 klassement-rijen — wel ${ua} afstand-rijen (nog bevestigen?)`
                    : `❌ geen uitslagen`);
            return `<tr>
                <td>${rkEsc(r.name)}</td>
                <td>${rkEsc(dt)}</td>
                <td style="text-align:center">${imp}</td>
                <td style="text-align:center">${telt}</td>
                <td style="text-align:center">${fin}</td>
                <td>${status}</td>
            </tr>`;
        }).join('');
        // Deep dive per wedstrijd → per DC
        const deepHtml = deep.filter(d => !d.skip).map(d => {
            const dcs = (d.dcs || []).map(dc =>
                `<tr>
                    <td>${rkEsc(dc.dc_naam)}</td>
                    <td style="text-align:center">${dc.n_afstanden}</td>
                    <td>${rkEsc(dc.dc_type)}</td>
                    <td style="text-align:center">${dc.passes_filter ? '✅' : '❌'}</td>
                    <td style="text-align:right">${dc.uk_rijen}</td>
                </tr>`
            ).join('') || '<tr><td colspan="5" class="ks-leeg">Geen DCs in deze wedstrijd.</td></tr>';
            return `<h4 style="margin-top:14px;color:var(--blauw);font-size:.95rem">Wedstrijd-detail · ${rkEsc(d.comp_id)}</h4>
                <table class="ks-w-tabel">
                    <thead><tr>
                        <th>DC</th><th># afst.</th><th>Type</th><th>Filter</th><th>UK-rijen</th>
                    </tr></thead>
                    <tbody>${dcs}</tbody>
                </table>`;
        }).join('');

        const finaleG = !Array.isArray(resp) && resp.finale_gereden;
        const streepDirect = !Array.isArray(resp) && resp.regels?.streep_direct;
        const finaleStatus = !Array.isArray(resp)
            ? (finaleG
                ? '<span style="color:#2e7d32">✅ finale is gereden</span>'
                : streepDirect
                    ? '<span style="color:#b71c1c">⏳ finale nog niet gereden — <b>min_deelnames en vereist_finale worden tijdelijk NIET toegepast</b> (streepresultaten staat op "direct toepassen" en is wél actief)</span>'
                    : '<span style="color:#b71c1c">⏳ finale nog niet gereden — <b>streepresultaten, min_deelnames en vereist_finale worden tijdelijk NIET toegepast</b></span>')
            : '';
        const catFilterTxt = (regels.categorie_filter ?? []).length
            ? ` · cats = <b>${rkEsc((regels.categorie_filter || []).join(', '))}</b>`
            : '';
        // Filter-tekst: bij per_naam ook de geselecteerde afstanden tonen
        // (vroeger 'sprint'/'lang' was rijk genoeg, per_naam is informatief
        // alleen als je weet welke afstanden meegerekend zijn).
        const afstFilterTxt = regels.afstand_filter === 'per_naam'
            ? ` (${(regels.afstand_namen ?? []).join(', ') || 'geen afstanden geselecteerd'})`
            : '';
        const regelsSamenv = regels.type
            ? `<div class="ks-hint">
                <b>Actieve regels:</b>
                type = <b>${rkEsc(regels.type)}</b> ·
                filter = <b>${rkEsc(regels.afstand_filter)}</b>${rkEsc(afstFilterTxt)}${catFilterTxt} ·
                min_deelnames = ${regels.min_deelnames ?? 0} ·
                streep = ${regels.streepresultaten ?? 0} ·
                vereist_finale = ${regels.vereist_finale ? 'ja' : 'nee'}
                <br>${finaleStatus}
               </div>`
            : '';

        // Pipeline-telling: waar sneuvelen rijen in de berekening?
        const pl = Array.isArray(resp) ? null : resp.pipeline;
        const pipelineHtml = pl
            ? `<h4 style="margin-top:14px;color:var(--blauw);font-size:.95rem">Pipeline-telling</h4>
               <table class="ks-w-tabel">
                 <tbody>
                   <tr><td>Uit uitslag_klassement</td><td style="text-align:right">${pl.uit_uk}</td></tr>
                   <tr><td>Na DC-filter (op type)</td><td style="text-align:right">${pl.na_dc_filter}</td></tr>
                   <tr><td>Na punten_totaal > 0 filter</td><td style="text-align:right">${pl.na_punten_filter}</td></tr>
                   <tr><td>Na rang ≠ NULL filter</td><td style="text-align:right">${pl.na_rang_filter}</td></tr>
                   <tr><td>Unieke rijders</td><td style="text-align:right"><b>${pl.rijders_uniek}</b></td></tr>
                 </tbody>
               </table>
               ${pl.voorbeelden_weg?.length
                   ? '<div class="ks-hint"><b>Voorbeelden weggefilterde rijen:</b><br>' + pl.voorbeelden_weg.map(rkEsc).join('<br>') + '</div>'
                   : ''}`
            : '';

        const html = `
            <div class="ks-overlay" id="ks-diag">
                <div class="ks-box">
                    <div class="ks-hdr">
                        <span>Diagnose</span>
                        <button class="ks-sluit" onclick="this.closest('.ks-overlay').remove()">&times;</button>
                    </div>
                    <div class="ks-body">
                        <div class="ks-hint">
                            Check per wedstrijd of de uitslagen compleet zijn.
                            Serie-klassement leest uit <b>uitslag_klassement</b> — dat wordt gevuld bij
                            <b>"Uitslag bevestigen"</b> per categorie (DC) in de uitslag-verwerking.
                        </div>
                        ${regelsSamenv}
                        <table class="ks-w-tabel">
                            <thead><tr>
                                <th>Naam</th><th>Datum</th><th>In DB</th>
                                <th>Telt mee</th><th>Finale</th><th>Uitslag</th>
                            </tr></thead>
                            <tbody>${rijen || '<tr><td colspan="6" class="ks-leeg">Geen wedstrijden in serie.</td></tr>'}</tbody>
                        </table>
                        ${pipelineHtml}
                        ${deepHtml}
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        document.getElementById('ks-diag').addEventListener('click', e => {
            if (e.target.id === 'ks-diag') e.currentTarget.remove();
        });
    } catch (e) { alert('Fout bij diagnose: ' + e.message); }
}

// rkPost in ranking.js is uitgebreid om zowel FormData als JSON te slikken —
// de wizard stuurt JSON.
