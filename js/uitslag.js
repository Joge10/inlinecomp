// ============================================================
//  InlineComp – Uitslag module
//  Toont per categorie/afstand de uitslag en klassementen.
//  Opbouw spiegelt de startlijsten-module.
// ============================================================

'use strict';

// ── Module-state ──────────────────────────────────────────────────────────────
let _uGroepen      = [];       // groepen (zoals bouwStartlijstGroepen)
let _uActieveCat   = null;     // geselecteerde categorie-groep
let _uActieveDist  = null;     // geselecteerde afstand { id, name }
let _uDistCache    = {};       // dc_id → afstanden[]  (eigen cache naast startlijst)
let _uPrintOpties  = new Map();// Map< catLabel, Map< distId, { distNaam, opties[] } > >

// ── Hulpfuncties ──────────────────────────────────────────────────────────────

async function uLaadAfstanden(groep) {
    const cKey = groep.dc_id + (groep.is_split ? '|' + groep.dc_name : '');
    if (_uDistCache[cKey]) return _uDistCache[cKey];
    try {
        const splitParam = groep.is_split ? `&split_group=${encodeURIComponent(groep.dc_name)}` : '';
        const res  = await fetch(`api/distances_db.php?dc_id=${encodeURIComponent(groep.dc_id)}${splitParam}`);
        const data = await res.json();
        const afs  = Array.isArray(data) ? data.filter(a => !a.error) : [];
        _uDistCache[cKey] = afs;
        return afs;
    } catch { return []; }
}

function uBouwGroepen() {
    // Hergebruik dezelfde logica als startlijsten (vergelijkData is globaal)
    return bouwStartlijstGroepen();
}

// ── Print-select vullen ───────────────────────────────────────────────────────

async function vulUitslagPrintSelect() {
    const catSel  = el('u-print-cat-sel');
    const klasSel = el('u-print-klas-sel');
    const btn     = el('u-btn-print');
    if (!catSel) return;

    _uPrintOpties = new Map();
    catSel.innerHTML  = '<option value="">— Categorie —</option>';
    if (klasSel) { klasSel.innerHTML = '<option value="">— Klassement —</option>'; klasSel.disabled = true; }
    if (btn) btn.disabled = true;

    for (const groep of _uGroepen) {
        const afstanden = await uLaadAfstanden(groep);
        const displayNaam = groep.merge_label || groep.dc_name;

        const opties = [];

        // Tussenklassement: beschikbaar als er uitslag_afstand records zijn voor deze DC
        // (lichte check: we voegen hem altijd toe, beschikbaarheid blijkt bij laden)
        if (afstanden.length > 1) {
            opties.push({ label: 'Tussenklassement', sleutel: 'tussenklassement',
                          dcId: groep.dc_id, dcName: displayNaam });
        }
        // Eindklassement: beschikbaar als er uitslag_klassement records zijn
        opties.push({ label: 'Eindklassement', sleutel: 'eindklassement',
                      dcId: groep.dc_id, dcName: displayNaam });

        if (!_uPrintOpties.has(displayNaam))
            _uPrintOpties.set(displayNaam, opties);
    }

    for (const naam of _uPrintOpties.keys()) {
        const opt = document.createElement('option');
        opt.value = naam;
        opt.textContent = naam;
        catSel.appendChild(opt);
    }

    if (_uPrintOpties.size === 1) {
        catSel.selectedIndex = 1;
        catSel.dispatchEvent(new Event('change'));
    }
}

// ── Hoofd pagina ──────────────────────────────────────────────────────────────

function toonUitslagPagina() {
    _uDistCache = {};   // cache resetten bij nieuwe pagina-activering

    const header   = el('u-page-header');
    const catTabs  = el('u-cat-tabs');
    const distTabs = el('u-dist-tabs');
    const content  = el('u-cat-content');

    if (!huidigCompId || !isGeimporteerd) {
        if (catTabs)  catTabs.innerHTML  = '';
        if (distTabs) { distTabs.innerHTML = ''; distTabs.style.display = 'none'; }
        if (content)  content.innerHTML  =
            '<div class="status-msg info">Selecteer en importeer eerst een wedstrijd via <strong>Importeer</strong>.</div>';
        if (header)   header.innerHTML   = '';
        return;
    }

    // ── Header ────────────────────────────────────────────────────────────────
    if (header) header.innerHTML = `
        <div class="ts-top">
            <div>
                <div class="ts-comp-naam">${escHtml(huidigComp?.name || '')}</div>
                <div class="ts-comp-meta">${escHtml(formatDatum(huidigComp?.starts || ''))} · ${escHtml(getLocatie(huidigComp || {}))}</div>
            </div>
            <div class="sl-print-bar">
                <select id="u-print-cat-sel" class="inp sl-inp sl-print-sel">
                    <option value="">— Categorie —</option>
                </select>
                <select id="u-print-klas-sel" class="inp sl-inp sl-print-sel" disabled>
                    <option value="">— Klassement —</option>
                </select>
                <button id="u-btn-print" class="btn-secondary" disabled>🖨 Druk af</button>
            </div>
        </div>`;

    // ── Categorie-tabs (rij 1) ─────────────────────────────────────────────
    const groepen = uBouwGroepen();
    _uGroepen = groepen;

    catTabs.innerHTML = '';
    groepen.forEach((groep, i) => {
        const btn = document.createElement('button');
        btn.className = 'org-tab-btn' + (i === 0 ? ' active' : '');
        const displayNaam = groep.merge_label || groep.dc_name;
        const totaal  = groep.competitors.length;
        const label   = groep.dc_ids.length > 1
            ? `${escHtml(displayNaam)} <span class="tab-badge-merged" title="Samengevoegde categorieën">${groep.dc_ids.length}</span>`
            : escHtml(displayNaam);
        btn.innerHTML = label + ` (${totaal})`;
        btn.addEventListener('click', () => {
            catTabs.querySelectorAll('.org-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _uActieveCat = groep;
            toonUitslagAfstandConfig(groep);
        });
        catTabs.appendChild(btn);
    });

    _uActieveCat = groepen[0];
    toonUitslagAfstandConfig(groepen[0]);

    // Print-select in achtergrond vullen
    vulUitslagPrintSelect().then(() => {
        // Categorie → klassement-opties
        el('u-print-cat-sel')?.addEventListener('change', () => {
            const catSel  = el('u-print-cat-sel');
            const klasSel = el('u-print-klas-sel');
            const btn     = el('u-btn-print');
            const opties  = _uPrintOpties.get(catSel.value) ?? [];
            klasSel.innerHTML = '<option value="">— Klassement —</option>';
            if (btn) btn.disabled = true;
            if (!opties.length) { klasSel.disabled = true; return; }
            opties.forEach(o => {
                const opt = document.createElement('option');
                opt.value = JSON.stringify(o);
                opt.textContent = o.label;
                klasSel.appendChild(opt);
            });
            klasSel.disabled = false;
            if (opties.length === 1) {
                klasSel.selectedIndex = 1;
                klasSel.dispatchEvent(new Event('change'));
            }
        });

        el('u-print-klas-sel')?.addEventListener('change', () => {
            const klasSel = el('u-print-klas-sel');
            const btn     = el('u-btn-print');
            if (btn) btn.disabled = !klasSel.value;
        });

        el('u-btn-print')?.addEventListener('click', () => {
            const klasSel = el('u-print-klas-sel');
            if (!klasSel?.value) return;
            const optData = JSON.parse(klasSel.value);
            drukUitslagAf(optData);
        });
    });
}

// ── Afstand-tabs per categorie (rij 2) ────────────────────────────────────────

async function toonUitslagAfstandConfig(groep) {
    const distTabs = el('u-dist-tabs');
    const content  = el('u-cat-content');

    content.innerHTML  = '<div class="status-msg loading"><span class="spinner"></span>Afstanden laden…</div>';
    distTabs.innerHTML = '';
    distTabs.style.display = 'none';

    let afstanden;
    try {
        afstanden = await uLaadAfstanden(groep);
    } catch(e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
        return;
    }

    // Info-label bij samenvoegingen
    const displayNaam    = groep.merge_label || groep.dc_name;
    const infoLabelHtml  = groep.dc_ids?.length > 1
        ? `<div class="sl-merge-label">&#8644; Samengevoegd: <strong>${escHtml(groep.dc_name)}</strong></div>`
        : groep.is_split
            ? `<div class="sl-merge-label">&#9986; Gesplitst uit: <strong>${escHtml(
                  vergelijkData.find(c => c.dc_id === groep.dc_id)?.dc_name || groep.dc_id
              )}</strong></div>`
            : '';

    // ── Afstand-tabs ──────────────────────────────────────────────────────────
    afstanden.forEach((a, i) => {
        const btn = document.createElement('button');
        btn.className = 'org-tab-btn u-dist-tab' + (i === 0 ? ' active' : '');
        btn.dataset.distId   = a.id ?? '';
        btn.dataset.distNaam = a.name ?? '';
        btn.textContent      = a.name ?? '—';
        btn.addEventListener('click', () => {
            distTabs.querySelectorAll('.u-dist-tab, .u-klas-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _uActieveDist = a;
            toonUitslagVoorAfstand(groep, a);
        });
        distTabs.appendChild(btn);
    });

    // ── Extra tab: Klassement (altijd helemaal rechts) ────────────────────────
    const klasBtn = document.createElement('button');
    klasBtn.className = 'org-tab-btn u-klas-tab u-klas-tab-rechts';
    klasBtn.textContent = '🏆 Klassement';
    klasBtn.addEventListener('click', () => {
        distTabs.querySelectorAll('.u-dist-tab, .u-klas-tab').forEach(b => b.classList.remove('active'));
        klasBtn.classList.add('active');
        _uActieveDist = null;
        toonUitslagKlassement(groep);
    });
    distTabs.appendChild(klasBtn);

    distTabs.style.display = '';

    // ── Inhoud ────────────────────────────────────────────────────────────────
    content.innerHTML = `${infoLabelHtml}<div id="u-dist-content"></div>`;

    if (afstanden.length) {
        _uActieveDist = afstanden[0];
        toonUitslagVoorAfstand(groep, afstanden[0]);
    } else {
        // Geen afstanden → direct naar klassement
        distTabs.querySelectorAll('.u-klas-tab').forEach(b => b.classList.add('active'));
        toonUitslagKlassement(groep);
    }
}

// ── Inhoud: uitslag per afstand ───────────────────────────────────────────────

async function toonUitslagVoorAfstand(groep, afstand) {
    const content = el('u-dist-content');
    if (!content) return;

    content.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Uitslag laden…</div>';

    try {
        const dcParam   = groep.dc_ids.map(encodeURIComponent).join(',');
        const distParam = afstand.id ? `&distance_id=${encodeURIComponent(afstand.id)}` : '';
        const res  = await fetch(
            `api/uitslag_afstand.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_ids=${dcParam}${distParam}`
        );
        const data = await res.json();

        if (data.error) {
            content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(data.error)}</div>`;
            return;
        }

        if (data.systeem !== 'full-final') {
            content.innerHTML = `<div class="status-msg info">${escHtml(data.melding ?? 'Systeem nog niet beschikbaar.')}</div>`;
            return;
        }

        // ── Gecombineerde modus: 1 serie + alleen A-finale ────────────────────
        if (data.modus === 'gecombineerd') {
            if (!data.gecombineerd || data.gecombineerd.length === 0) {
                content.innerHTML = `<div class="status-msg info">Nog geen uitslag beschikbaar voor <strong>${escHtml(afstand.name ?? '—')}</strong>.</div>`;
            } else {
                let html = `<div class="u-afstand-blokken">
                    <div class="u-finale-blok u-finale-gecombineerd ${data.has_results ? 'u-finale-compleet' : 'u-finale-onvolledig'}">
                        <div class="u-finale-titel">Gecombineerde uitslag (serie + finale)${data.has_results ? '' : ' <span class="u-onvolledig-badge">onvolledig</span>'}</div>
                        <table class="u-uitslag-tabel">
                            <thead>
                                <tr>
                                    <th class="u-col-rang">#</th>
                                    <th class="u-col-naam">Naam</th>
                                    <th class="u-col-startnr">Nr</th>
                                    <th class="u-col-cat">Cat</th>
                                    <th class="u-col-serie">Serie</th>
                                    <th class="u-col-finale">Finale</th>
                                    <th class="u-col-totaal">Totaal</th>
                                </tr>
                            </thead>
                            <tbody>`;

                for (const r of data.gecombineerd) {
                    const rangTxt      = r.rang           != null ? r.rang           : '—';
                    const serieTxt     = r.serie_rang     != null
                        ? `${r.serie_rang} pt${r.serie_tijd_ms != null ? ' (' + msTijd(r.serie_tijd_ms) + ')' : ''}`
                        : (r.sanctie ? escHtml(r.sanctie) : '—');
                    const finaleTxt    = r.finale_rang    != null
                        ? `${r.finale_rang} pt${r.finale_tijd_ms != null ? ' (' + msTijd(r.finale_tijd_ms) + ')' : ''}`
                        : (r.sanctie ? escHtml(r.sanctie) : '—');
                    const totaalTxt    = r.totaal_punten  != null ? r.totaal_punten  : '—';
                    const rowClass     = r.sanctie ? 'u-rij-sanctie' : '';
                    html += `<tr class="${rowClass}">
                        <td class="u-col-rang">${rangTxt}</td>
                        <td class="u-col-naam">${escHtml(r.full_name ?? '')}</td>
                        <td class="u-col-startnr">${escHtml(String(r.start_number ?? ''))}</td>
                        <td class="u-col-cat">${escHtml(r.categorie ?? '')}</td>
                        <td class="u-col-serie">${serieTxt}</td>
                        <td class="u-col-finale">${finaleTxt}</td>
                        <td class="u-col-totaal"><strong>${totaalTxt}</strong></td>
                    </tr>`;
                }

                html += `</tbody></table></div></div>`;
                content.innerHTML = html;
            }

            if (data.has_results) {
                const vastlegBtn = document.createElement('button');
                vastlegBtn.className = 'btn-secondary u-vastleg-btn';
                vastlegBtn.innerHTML = '💾 Uitslag vastleggen';
                vastlegBtn.title = 'Sla de officiële uitslag op in de database (uitslag_afstand + klassement)';
                vastlegBtn.addEventListener('click', () =>
                    _uVastleggen(groep, afstand, vastlegBtn));
                content.prepend(vastlegBtn);
            }
            return;
        }

        // ── Normaal: alle finales in één tabel ───────────────────────────────────
        if (!data.finales || data.finales.length === 0) {
            content.innerHTML = `<div class="status-msg info">Nog geen finales gevonden voor <strong>${escHtml(afstand.name ?? '—')}</strong>.</div>`;
            return;
        }

        const alleCompleet = data.finales.every(f => f.compleet);
        const somOnvolledig = data.finales.some(f => !f.compleet);
        const blokClass = alleCompleet ? 'u-finale-compleet' : 'u-finale-onvolledig';

        let html = `<div class="u-afstand-blokken"><div class="u-finale-blok ${blokClass}">`;
        if (somOnvolledig) {
            const onvolledigeLabels = data.finales.filter(f => !f.compleet).map(f => escHtml(f.label)).join(', ');
            html += `<div class="u-finale-titel"><span class="u-onvolledig-badge">onvolledig</span> ${onvolledigeLabels}</div>`;
        }

        html += `<table class="u-uitslag-tabel">
            <thead><tr>
                <th class="u-col-rang">#</th>
                <th class="u-col-naam">Naam</th>
                <th class="u-col-startnr">Nr</th>
                <th class="u-col-cat">Cat</th>
                <th class="u-col-finale-label">Finale</th>
                <th class="u-col-tijd">Tijd</th>
                <th class="u-col-sanctie">Sanctie</th>
            </tr></thead>
            <tbody>`;

        for (const finale of data.finales) {
            for (const r of finale.rijders) {
                const rangTxt    = r.rang    != null ? r.rang    : '—';
                const tijdTxt    = r.tijd_ms != null ? msTijd(r.tijd_ms) : '—';
                const sanctieTxt = r.sanctie ?? '';
                const rowClass   = sanctieTxt ? 'u-rij-sanctie' : '';
                html += `<tr class="${rowClass}">
                    <td class="u-col-rang">${rangTxt}</td>
                    <td class="u-col-naam">${escHtml(r.full_name ?? '')}</td>
                    <td class="u-col-startnr">${escHtml(String(r.start_number ?? ''))}</td>
                    <td class="u-col-cat">${escHtml(r.categorie ?? '')}</td>
                    <td class="u-col-finale-label">${escHtml(finale.label)}</td>
                    <td class="u-col-tijd">${tijdTxt}</td>
                    <td class="u-col-sanctie">${escHtml(sanctieTxt)}</td>
                </tr>`;
            }
        }

        html += `</tbody></table></div></div>`;
        content.innerHTML = html;

        // ── Vastleggen-knop (alleen als uitslag compleet is) ───────────────
        if (data.has_results) {
            const vastlegBtn = document.createElement('button');
            vastlegBtn.className = 'btn-secondary u-vastleg-btn';
            vastlegBtn.innerHTML = '💾 Uitslag vastleggen';
            vastlegBtn.title = 'Sla de officiële uitslag op in de database (uitslag_afstand + klassement)';
            vastlegBtn.addEventListener('click', () =>
                _uVastleggen(groep, afstand, vastlegBtn));
            content.prepend(vastlegBtn);
        }

    } catch (e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Vastleggen helper ─────────────────────────────────────────────────────────

async function _uVastleggen(groep, afstand, btnEl) {
    const origTekst = btnEl?.innerHTML ?? '';
    if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '⏳ Bezig…'; }

    try {
        const res = await fetch('api/uitslag_vastleggen.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                competition_id: huidigCompId,
                dc_ids:         groep.dc_ids,
                dc_naam:        groep.merge_label || groep.dc_name,
                distance_id:    afstand?.id ?? '',   // leeg = alle afstanden
            }),
        });
        const data = await res.json();
        if (data.ok) {
            if (btnEl) {
                btnEl.innerHTML = '✓ Vastgelegd';
                btnEl.classList.add('u-vastleg-btn-ok');
                setTimeout(() => {
                    btnEl.innerHTML = origTekst;
                    btnEl.classList.remove('u-vastleg-btn-ok');
                    btnEl.disabled = false;
                }, 3000);
            }
        } else {
            alert(data.error ?? data.melding ?? 'Fout bij vastleggen');
            if (btnEl) { btnEl.innerHTML = origTekst; btnEl.disabled = false; }
        }
    } catch (e) {
        alert(`Fout: ${e.message}`);
        if (btnEl) { btnEl.innerHTML = origTekst; btnEl.disabled = false; }
    }
}

// ── Hulp: milliseconden → m:ss.hh ─────────────────────────────────────────────
function msTijd(ms) {
    if (ms == null) return '—';
    const honderdsten = Math.floor((ms % 1000) / 10);
    const seconden    = Math.floor(ms / 1000) % 60;
    const minuten     = Math.floor(ms / 60000);
    const s = String(seconden).padStart(2, '0');
    const h = String(honderdsten).padStart(2, '0');
    return minuten > 0 ? `${minuten}:${s}.${h}` : `${s}.${h}`;
}

// ── Inhoud: klassement ───────────────────────────────────────────────────────

async function toonUitslagKlassement(groep) {
    const content = el('u-dist-content');
    if (!content) return;

    content.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Klassement laden…</div>';

    try {
        const dcParam = groep.dc_ids.map(encodeURIComponent).join(',');
        const res  = await fetch(
            `api/klassement_live.php?competition_id=${encodeURIComponent(huidigCompId)}&dc_ids=${dcParam}`
        );
        const data = await res.json();

        if (data.error) {
            content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(data.error)}</div>`;
            return;
        }
        if (data.systeem !== 'full-final') {
            content.innerHTML = `<div class="status-msg info">${escHtml(data.melding ?? 'Systeem nog niet beschikbaar.')}</div>`;
            return;
        }
        if (!data.has_results || !data.klassement?.length) {
            content.innerHTML = `<div class="status-msg info">Nog geen resultaten beschikbaar voor het klassement.</div>`;
            return;
        }

        const afstanden   = data.afstanden ?? [];
        const klassement  = data.klassement;
        const displayNaam = groep.merge_label || groep.dc_name;
        const dcNaam      = displayNaam;

        // ── Tabel-header ──────────────────────────────────────────────────────
        let thAfst = '';
        for (const a of afstanden) {
            const kls = a.compleet ? '' : ' u-klas-dist-onvolledig';
            thAfst += `<th class="u-klas-col-punten${kls}" title="${escHtml(a.name)}">${escHtml(a.name)}</th>`;
        }

        // ── Rijen ─────────────────────────────────────────────────────────────
        let tbody = '';
        for (const r of klassement) {
            const rowClass = Object.values(r.afstanden ?? {}).some(d => d.bewerkbaar)
                ? 'u-klas-rij-sanctie' : '';

            let tdAfst = '';
            for (const a of afstanden) {
                const dp = r.afstanden?.[a.id];
                if (!dp) {
                    tdAfst += `<td class="u-klas-col-punten">—</td>`;
                    continue;
                }
                if (dp.bewerkbaar) {
                    const sanctieTxt = dp.sanctie ? ` (${escHtml(dp.sanctie)})` : '';
                    tdAfst += `<td class="u-klas-col-punten u-klas-bewerkbaar">` +
                        `<input type="number" class="u-klas-punten-inp" ` +
                        `  min="0" step="1" value="${dp.punten}" ` +
                        `  data-lic="${escHtml(r.person_license)}" ` +
                        `  data-dist-id="${escHtml(a.id)}" ` +
                        `  data-dist-naam="${escHtml(a.name)}" ` +
                        `  title="Sanctie${sanctieTxt} – punten aanpassen">` +
                        (dp.override ? `<span class="u-klas-override-badge" title="Aangepast">✎</span>` : '') +
                        `</td>`;
                } else {
                    tdAfst += `<td class="u-klas-col-punten">${dp.punten % 1 === 0 ? dp.punten : dp.punten.toFixed(1)}</td>`;
                }
            }

            tbody += `<tr class="${rowClass}" data-lic="${escHtml(r.person_license)}">
                <td class="u-klas-col-rang">${r.rang ?? '—'}</td>
                <td class="u-klas-col-naam">${escHtml(r.full_name ?? '')}</td>
                <td class="u-klas-col-snr">${escHtml(String(r.start_number ?? ''))}</td>
                <td class="u-klas-col-cat">${escHtml(r.categorie ?? '')}</td>
                ${tdAfst}
                <td class="u-klas-col-totaal">${r.totaal_punten % 1 === 0 ? r.totaal_punten : r.totaal_punten.toFixed(1)}</td>
            </tr>`;
        }

        // ── Opslaan-balk (alleen als er bewerkbare cellen zijn) ───────────────
        const heeftBewerkbaar = klassement.some(r =>
            Object.values(r.afstanden ?? {}).some(d => d.bewerkbaar)
        );
        const opslaanBalk = `<div class="u-klas-opslaan-balk">
            ${heeftBewerkbaar
                ? `<button class="btn-primary" id="u-klas-btn-opslaan">💾 Puntenaanpassingen opslaan</button>
                   <span class="u-klas-opslaan-status" id="u-klas-opslaan-status"></span>`
                : ''}
            <button class="btn-secondary u-vastleg-btn" id="u-klas-btn-vastleggen"
                    title="Sla alle complete afstanden + klassement definitief op">
                📥 Alles vastleggen
            </button>
        </div>`;

        content.innerHTML = `
            <div class="u-klas-wrap">
                <div class="u-klas-titel">${escHtml(dcNaam)} – Klassement</div>
                ${opslaanBalk}
                <div class="u-klas-tabel-wrap">
                <table class="u-klas-tabel">
                    <thead>
                        <tr>
                            <th class="u-klas-col-rang">#</th>
                            <th class="u-klas-col-naam">Naam</th>
                            <th class="u-klas-col-snr">Snr</th>
                            <th class="u-klas-col-cat">Cat</th>
                            ${thAfst}
                            <th class="u-klas-col-totaal">Totaal</th>
                        </tr>
                    </thead>
                    <tbody>${tbody}</tbody>
                </table>
                </div>
            </div>`;

        // ── Alles vastleggen knop ─────────────────────────────────────────────
        el('u-klas-btn-vastleggen')?.addEventListener('click', async (e) => {
            await _uVastleggen(groep, null, e.currentTarget);
        });

        // ── Opslaan handler ───────────────────────────────────────────────────
        el('u-klas-btn-opslaan')?.addEventListener('click', async () => {
            const statusEl = el('u-klas-opslaan-status');
            const aanpassingen = [];
            content.querySelectorAll('.u-klas-punten-inp').forEach(inp => {
                aanpassingen.push({
                    person_license: inp.dataset.lic,
                    distance_id:    inp.dataset.distId,
                    distance_naam:  inp.dataset.distNaam,
                    punten:         parseFloat(inp.value) || 0,
                });
            });
            if (!aanpassingen.length) return;

            if (statusEl) statusEl.textContent = '⏳ Opslaan…';
            try {
                const r = await fetch('api/klassement_punten.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        competition_id: huidigCompId,
                        dc_id:          groep.dc_ids[0],
                        dc_naam:        dcNaam,
                        aanpassingen,
                    }),
                });
                const d = await r.json();
                if (d.ok) {
                    if (statusEl) {
                        statusEl.textContent = `✓ ${d.opgeslagen} aanpassing(en) opgeslagen`;
                        setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3000);
                    }
                    // Badge bijwerken
                    content.querySelectorAll('.u-klas-punten-inp').forEach(inp => {
                        let badge = inp.nextElementSibling;
                        if (!badge || !badge.classList.contains('u-klas-override-badge')) {
                            badge = document.createElement('span');
                            badge.className = 'u-klas-override-badge';
                            badge.title = 'Aangepast';
                            badge.textContent = '✎';
                            inp.after(badge);
                        }
                    });
                } else {
                    if (statusEl) statusEl.textContent = `⚠ ${d.error ?? 'Fout'}`;
                }
            } catch (e) {
                if (statusEl) statusEl.textContent = `⚠ ${e.message}`;
            }
        });

    } catch (e) {
        content.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Afdrukken ─────────────────────────────────────────────────────────────────

function drukUitslagAf(optData) {
    // Placeholder – implementatie volgt
    alert(`Afdrukken: ${optData.label} voor ${optData.dcName} – nog niet beschikbaar.`);
}
