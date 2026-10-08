/* InlineComp – banen-beheer per organisatie.
 *
 * Banen zijn per-org: dezelfde fysieke baan kan onder meerdere organisaties
 * apart voorkomen, elk met eigen vereniging-info en logo. De Banen-tab
 * binnen de organisatie-detail toont de banen van de huidige organisatie.
 *
 * Bij KNSB-import wordt automatisch een baan-rij aangemaakt voor de
 * organisatie als de venue_name nog niet bestaat (zie vergelijk.php).
 * De beheerder vult dan alleen het logo en de vereniging-naam aan.
 */

let bnLijst = [];
let bnActieveId = null;          // 'NIEUW' | UUID | null
let bnHuidigeOrgId = null;       // org-context van de getoonde lijst

// ── Layout-data parse + thumbnail-render ────────────────────────────────
// Server geeft layout_data als string (MySQL JSON kolom → string in PDO).
// Parse defensief: null, lege string, invalid JSON → null.
function _bnParseLayout(raw) {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    try { return JSON.parse(raw); } catch { return null; }
}

// Render een kleine SVG-thumbnail uit de layout-state (editor-formaat uit
// de PoC). Alleen de piste-, weg- en infield-paden; auto-fit naar `size`
// pixels. Levert een SVG-string op (direct in innerHTML inzetbaar).
//
// Deze renderer dient 2 plekken: de kleine tabel-thumbnail (size≈40) én
// de preview in het baan-form (size≈120). De echte editor-canvas gebruikt
// een rijkere versie met interactie — hier alleen read-only pad-strokes.
function renderBaanLayoutThumb(layout, size) {
    if (!layout?.layers) return '<span class="bn-geen-logo">—</span>';
    const layers = layout.layers;
    // Bounding-box bepalen over alle punten (freecurve + stadium-A/B).
    let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
    const paden = [];
    const order = ['infield', 'weg', 'piste'];
    for (const lid of order) {
        const L = layers[lid];
        if (!L?.paths) continue;
        for (const P of L.paths) {
            if (P.visible === false) continue;   // laag-/sub-path "uit"
            const d = _bnPadNaarSvgD(P, layers.piste);
            if (!d) continue;
            paden.push({ d, stroke: _bnLaagKleur(lid), width: L.width || 4 });
            // Breidt bounds uit
            if (P.shapeType === 'stadium' && P.stadium) {
                const r = (P.stadium.diameter || 0) / 2;
                [P.stadium.A, P.stadium.B].forEach(pt => {
                    if (!pt) return;
                    minX = Math.min(minX, pt.x - r); maxX = Math.max(maxX, pt.x + r);
                    minY = Math.min(minY, pt.y - r); maxY = Math.max(maxY, pt.y + r);
                });
            } else if (P.points?.length) {
                for (const pt of P.points) {
                    minX = Math.min(minX, pt.x); maxX = Math.max(maxX, pt.x);
                    minY = Math.min(minY, pt.y); maxY = Math.max(maxY, pt.y);
                }
            }
        }
    }
    if (!paden.length || !isFinite(minX)) return '<span class="bn-geen-logo">—</span>';
    const w = (maxX - minX) || 1, h = (maxY - minY) || 1;
    // 8% padding + halve max-stroke zodat de dikke piste-stroke niet
    // afgesneden wordt (bounding-box is op centerlines berekend).
    const maxStroke = paden.reduce((m, p) => Math.max(m, p.width || 0), 0);
    const pad = Math.max(w, h) * 0.08 + maxStroke / 2;
    const vb = `${minX - pad} ${minY - pad} ${w + pad * 2} ${h + pad * 2}`;
    // Stroke-width schalen naar viewBox-units (anders onzichtbaar bij 40px).
    const paths = paden.map(p =>
        `<path d="${p.d}" fill="none" stroke="${p.stroke}" stroke-width="${p.width}" stroke-linecap="round" stroke-linejoin="round"/>`
    ).join('');
    return `<svg class="bn-layout-thumb" width="${size}" height="${size}" viewBox="${vb}" xmlns="http://www.w3.org/2000/svg">${paths}</svg>`;
}

function _bnLaagKleur(id) {
    return id === 'piste'   ? '#555'
         : id === 'weg'     ? '#888'
         : id === 'infield' ? '#d0d0d0'
         : '#aaa';
}

// Minimal SVG-path-string generator (klein subset van de PoC-renderer —
// voldoende voor read-only preview). Stadium = M line L arc + arc.
// Freecurve/follow-piste = M-point + bezier/line per segment.
function _bnPadNaarSvgD(P, pisteLaag) {
    if (!P) return '';
    if (P.shapeType === 'stadium' && P.stadium?.A && P.stadium?.B) {
        return _bnStadiumD(P.stadium.A, P.stadium.B, P.stadium.diameter || 10);
    }
    if (P.shapeType === 'follow-piste') {
        const pisteP = pisteLaag?.paths?.[0];
        if (!pisteP || pisteP.shapeType !== 'stadium') return '';
        // Infield = stadium met kleinere diameter (margin binnenin).
        const margin = P.width ?? 0.5;
        const innerD = Math.max(1, (pisteP.stadium.diameter || 10) - margin * 2);
        return _bnStadiumD(pisteP.stadium.A, pisteP.stadium.B, innerD);
    }
    // freecurve
    if (!P.points?.length) return '';
    const pts = P.points;
    const closed = !!P.closed;
    let d = `M ${pts[0].x} ${pts[0].y}`;
    const n = pts.length;
    const limit = closed ? n : n - 1;
    for (let i = 0; i < limit; i++) {
        const p1 = pts[i];
        const p2 = pts[(i + 1) % n];
        const segType = p2.segType || p1.segType || 'curve';
        if (segType === 'line') {
            d += ` L ${p2.x} ${p2.y}`;
        } else {
            // Catmull-Rom-naar-bezier: c1 = p1 + (p2-p0)/6, c2 = p2 - (p3-p1)/6
            const p0 = pts[(i - 1 + n) % n];
            const p3 = pts[(i + 2) % n];
            const c1x = p1.x + (p2.x - p0.x) / 6;
            const c1y = p1.y + (p2.y - p0.y) / 6;
            const c2x = p2.x - (p3.x - p1.x) / 6;
            const c2y = p2.y - (p3.y - p1.y) / 6;
            d += ` C ${c1x} ${c1y} ${c2x} ${c2y} ${p2.x} ${p2.y}`;
        }
    }
    if (closed) d += ' Z';
    return d;
}

// Stadium = ovaal met 2 centers (A, B) en diameter D. SVG: lijn + arc + lijn + arc.
function _bnStadiumD(A, B, D) {
    const dx = B.x - A.x, dy = B.y - A.y;
    const L = Math.hypot(dx, dy) || 1;
    const ux = dx / L, uy = dy / L;
    const nx = -uy, ny = ux;
    const r = D / 2;
    const Ap = { x: A.x + nx * r, y: A.y + ny * r };
    const Bp = { x: B.x + nx * r, y: B.y + ny * r };
    const Am = { x: A.x - nx * r, y: A.y - ny * r };
    const Bm = { x: B.x - nx * r, y: B.y - ny * r };
    return `M ${Ap.x} ${Ap.y} L ${Bp.x} ${Bp.y} A ${r} ${r} 0 0 0 ${Bm.x} ${Bm.y} L ${Am.x} ${Am.y} A ${r} ${r} 0 0 0 ${Ap.x} ${Ap.y} Z`;
}

async function laadBanen() {
    // Org-context komt uit instellingen.js — we lezen de bestaande globale.
    // Geen actieve org? Dan tabel leeg laten + form sluiten.
    const orgId = (typeof actieveOrg !== 'undefined' && actieveOrg?.id) ? actieveOrg.id : null;
    bnHuidigeOrgId = orgId;
    const container = document.getElementById('banen-container');
    if (!container) return;

    if (!orgId) {
        container.innerHTML = `<div class="bn-leeg">Selecteer eerst een organisatie links om de banen te beheren.</div>`;
        return;
    }

    try {
        const res = await fetch('api/banen.php?org_id=' + encodeURIComponent(orgId));
        if (!res.ok) {
            container.innerHTML = `<div class="status-msg error">Fout bij laden banen (HTTP ${res.status}).</div>`;
            return;
        }
        bnLijst = await res.json();
        renderBanenTabel();
    } catch (e) {
        container.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

function renderBanenTabel() {
    const container = document.getElementById('banen-container');
    if (!container) return;

    // "+ Nieuwe baan"-knop verbergen zolang een baan-form open is —
    // twee banen tegelijk bewerken slaat nergens op (zelfde form-IDs).
    const nieuwBtn = document.getElementById('btn-nieuwe-baan');
    if (nieuwBtn) nieuwBtn.hidden = (bnActieveId !== null);

    const baseUrl = new URL('.', window.location.href).href;
    const formHtml = bnActieveId !== null ? bouwBaanForm(bnActieveId) : '';

    if (!bnLijst.length) {
        container.innerHTML = `<div class="bn-leeg">Nog geen banen voor deze organisatie. Banen worden automatisch aangemaakt bij KNSB-import op basis van het <em>venue</em>-veld; je kunt ze ook handmatig toevoegen via <em>+ Nieuwe baan</em>.</div>${formHtml}`;
        bindBaanForm();
        return;
    }

    const rijen = bnLijst.map(b => {
        const cb = encodeURIComponent(b.logo_updated_at ?? b.updated_at ?? '');
        let logo;
        if (b.logo_path) {
            logo = `<img src="${escHtml(baseUrl + b.logo_path)}?v=${cb}" alt="" class="bn-logo-mini">`;
        } else if (b.gedeeld_logo_path) {
            // Cross-org fallback — toon met badge "gedeeld" zodat duidelijk is
            // dat het logo bij een andere org hoort en automatisch wordt
            // overgenomen. Hier eigen upload kan deze fallback overrulen.
            logo = `<img src="${escHtml(baseUrl + b.gedeeld_logo_path)}" alt=""
                class="bn-logo-mini bn-img-opacity-65"
                title="Logo overgenomen van een andere organisatie met dezelfde baan-naam">`;
        } else {
            logo = '<span class="bn-geen-logo">—</span>';
        }
        // Eigen layout wint; anders fallback naar gedeelde layout van een
        // andere org met dezelfde baan-naam (opacity als visuele hint).
        const eigenLayout   = _bnParseLayout(b.layout_data);
        const gedeeldLayout = eigenLayout ? null : _bnParseLayout(b.gedeeld_layout_data);
        const layoutData    = eigenLayout || gedeeldLayout;
        const layoutSvg = layoutData
            ? (gedeeldLayout
                ? `<span class="bn-gedeeld-img" title="Layout overgenomen van een andere organisatie met dezelfde baan-naam">${renderBaanLayoutThumb(layoutData, 40)}</span>`
                : renderBaanLayoutThumb(layoutData, 40))
            : '<span class="bn-geen-logo">—</span>';
        const verNaam = b.vereniging_naam
            ? escHtml(b.vereniging_naam)
            : (b.gedeeld_vereniging_naam
                ? `<span class="bn-gedeeld-tekst" title="Overgenomen van andere organisatie">${escHtml(b.gedeeld_vereniging_naam)}</span>`
                : '');
        const actief = b.id === bnActieveId ? ' bn-actief' : '';
        return `<tr class="bn-rij${actief}" data-id="${escHtml(b.id)}">
            <td class="bn-logo-cel">${logo}</td>
            <td class="bn-logo-cel bn-layout-cel">${layoutSvg}</td>
            <td class="bn-naam"><b>${escHtml(b.naam)}</b>${b.stad ? `<span class="bn-stad"> · ${escHtml(b.stad)}</span>` : ''}</td>
            <td>${verNaam}</td>
            <td class="tc"><span class="bn-aliasteller">${b.aliassen_aantal ?? 0}</span></td>
            <td class="tc"><span class="bn-comp-teller">${b.comp_aantal ?? 0}</span></td>
            <td class="bn-acties">
                <button class="btn-secondary bn-edit"  data-id="${escHtml(b.id)}" title="Bewerken">&#9998;</button>
                <button class="btn-del bn-del" data-id="${escHtml(b.id)}" title="Verwijderen">&#128465;</button>
            </td>
        </tr>`;
    }).join('');

    container.innerHTML = `
        <table class="bn-tabel">
            <thead><tr>
                <th>Logo</th><th>Layout</th><th>Naam · stad</th><th>Gastheer-vereniging</th>
                <th class="tc">Aliassen</th><th class="tc">Wedstrijden</th><th></th>
            </tr></thead>
            <tbody>${rijen}</tbody>
        </table>
        ${formHtml}
    `;

    container.querySelectorAll('.bn-edit').forEach(btn =>
        btn.addEventListener('click', () => openBaanForm(btn.dataset.id)));
    container.querySelectorAll('.bn-del').forEach(btn =>
        btn.addEventListener('click', () => verwijderBaan(btn.dataset.id)));
    container.querySelectorAll('.bn-rij').forEach(tr =>
        tr.addEventListener('click', e => {
            if (e.target.closest('button')) return;
            openBaanForm(tr.dataset.id);
        }));

    bindBaanForm();
}

async function openBaanForm(id) {
    // Als er pending autosave-edits zijn voor een ANDERE baan: eerst flushen,
    // anders zou de debounced save straks de velden van de nieuwe baan
    // oppakken en de verkeerde rij overschrijven.
    if (bnActieveId && bnActieveId !== id && document.getElementById('bn-naam')) {
        await _bnAutosaveNu().catch(() => {});
    }
    bnActieveId = id;
    renderBanenTabel();
    document.getElementById('bn-form-wrap')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function bouwBaanForm(id) {
    const isNieuw = id === 'NIEUW';
    const b = isNieuw ? { id: '', naam: '', stad: '', vereniging_naam: '', logo_path: '', aliassen_aantal: 0 }
                     : (bnLijst.find(x => x.id === id) ?? null);
    if (!b) return '';

    const baseUrl = new URL('.', window.location.href).href;
    const cb = encodeURIComponent(b.logo_updated_at ?? b.updated_at ?? '');
    const logoPreviewSrc = b.logo_path ? (baseUrl + b.logo_path + '?v=' + cb) : '';
    const overFotoEigen   = b.over_foto    ? (baseUrl + b.over_foto    + '?t=' + Date.now()) : '';
    const overFotoGedeeld = (!b.over_foto && b.gedeeld_over_foto) ? (baseUrl + b.gedeeld_over_foto + '?t=' + Date.now()) : '';
    const overFotoSrc     = overFotoEigen || overFotoGedeeld;
    const overFotoIsGedeeld = !!(overFotoGedeeld && !overFotoEigen);

    // Hint-tekst onder velden met cross-org fallback: als eigen leeg is maar
    // een andere org dezelfde baan al heeft ingevuld, zien bezoekers die
    // gedeelde waarde in de public Vereniging-tab. Beheerder kan hier eigen
    // waarde zetten om de gedeelde te overschrijven voor deze org.
    const _gedeeldHint = (val) => (val && b.id)
        ? `<div class="bn-hint-info">
             Nu getoond via een andere organisatie: ${escHtml((val + '').slice(0, 80))}${(val + '').length > 80 ? '…' : ''}
           </div>`
        : '';

    // Lock-pattern: zolang er geen baan-naam is bestaat er geen DB-rij om
    // andere velden aan te hangen (ook foto-uploads niet). Rest-sectie
    // krijgt dan .bn-form-locked (opacity + pointer-events off) + een
    // uitleg-regel. Zodra naam >=2 tekens is activeert autosave de rest.
    const heeftNaam = !!(b.naam && b.naam.trim().length >= 2);

    // Hergebruikt dezelfde conventies als de org-gegevens-tab:
    // .inst-veld (per veld), .inst-subtitel (sectie-titel met border-bottom),
    // .logo-preview-wrap + .btn-upload, .btn-alias-ok/.btn-sponsor-add.
    // Zo trekken banen-form en organisatie-form visueel gelijk op.
    //
    // Volgorde: hoofd (naam/stad/vereniging/logo + aliassen) →
    // vereniging-info (adres/website/over-tekst/foto + baan-layout) →
    // sponsors. Baan-layout zit in vereniging-info want 't hoort bij
    // de "wat is deze baan"-info die bezoekers zien.
    // Baan-layout-blok apart gerenderd (hergebruik in rechter-kolom).
    const layoutBlokHtml = b.id ? (() => {
        const eigenL   = _bnParseLayout(b.layout_data);
        const gedeeldL = eigenL ? null : _bnParseLayout(b.gedeeld_layout_data);
        const previewL = eigenL || gedeeldL;
        const previewSvg = previewL
            ? renderBaanLayoutThumb(previewL, 120)
            : '<span class="bn-layout-leeg">Nog geen layout getekend</span>';
        const previewClass = gedeeldL ? 'bn-img-opacity-65' : '';
        const gedeeldHint = gedeeldL
            ? '<div class="bn-layout-gedeeld-hint">Overgenomen van andere organisatie met dezelfde baan-naam. Bij "Bewerken" wordt deze als startpunt geladen; opslaan zet een eigen kopie klaar.</div>'
            : '';
        const btnTxt = eigenL
            ? '✎ Layout bewerken…'
            : (gedeeldL ? '✎ Overnemen & bewerken…' : '＋ Layout tekenen…');
        return `<div class="inst-veld">
            <label>Baan-layout <small class="bn-hint-small">(piste + wegparcours + infield — getekend via de editor)</small></label>
            <div class="bn-layout-rij">
                <div class="bn-layout-preview ${previewClass}" id="bn-layout-preview">${previewSvg}</div>
                <div class="bn-layout-acties">
                    <button class="btn-upload" id="bn-layout-edit" type="button">${btnTxt}</button>
                    ${eigenL ? `<button class="btn-del btn-small" id="bn-layout-del" type="button" title="Layout verwijderen">🗑</button>` : ''}
                </div>
            </div>
            ${gedeeldHint}
            <div class="status-msg bn-layout-msg" id="bn-layout-msg" hidden></div>
        </div>`;
    })() : '';

    // 2-kolommen grid: links tekst-velden, rechts media (logo/foto/layout) +
    // aliassen. Hoofd-identiteit (naam/stad/vereniging + logo + aliassen) in
    // de eerste grid; vereniging-info (adres/website/over-tekst + foto +
    // layout) in de tweede. Sponsors full-width onderaan.
    return `<div id="bn-form-wrap" class="bn-form-wrap">
        <div class="bn-form-head">
            <h3>${isNieuw ? 'Nieuwe baan' : 'Baan bewerken'}</h3>
            <span class="bn-save-status" id="bn-save-status" aria-live="polite"></span>
        </div>
        <input type="hidden" id="bn-id" value="${escHtml(b.id ?? '')}">

        <div class="bn-form-grid">
            <div class="bn-form-col">
                <div class="bn-form-subgrid">
                    <div>
                        <div class="inst-veld">
                            <label for="bn-naam">Naam <span class="vereist">*</span></label>
                            <input type="text" id="bn-naam" value="${escHtml(b.naam)}" placeholder="bv. Sportpark Het Plantsoen" autocomplete="off">
                        </div>
                        <div class="inst-veld">
                            <label for="bn-stad">Stad</label>
                            <input type="text" id="bn-stad" value="${escHtml(b.stad ?? '')}" placeholder="bv. Leiderdorp" autocomplete="off">
                        </div>
                        <div class="inst-veld">
                            <label for="bn-ver">Gastheer-vereniging</label>
                            <input type="text" id="bn-ver" value="${escHtml(b.vereniging_naam ?? '')}" placeholder="bv. DOST 1925" autocomplete="off">
                        </div>
                    </div>
                    <div>
                        <div class="inst-veld">
                            <label>Logo</label>
                            <div class="logo-preview-wrap">
                                <img id="bn-logo-preview" src="${escHtml(logoPreviewSrc)}" alt="" ${b.logo_path ? '' : 'hidden'}>
                                ${b.logo_path ? '' : '<span class="logo-geen">Geen logo</span>'}
                            </div>
                            <label class="btn-upload ${b.id ? '' : 'bn-upload-disabled'}" for="bn-logo-file" id="bn-logo-upload-lbl">&#128247; Logo uploaden</label>
                            <input type="file" id="bn-logo-file" accept="image/*" hidden>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bn-form-col">
                ${b.id ? `<div class="inst-subtitel">Naam-varianten <span class="inst-subtitel-hint">(aliassen voor KNSB-feed)</span></div>
                <div id="bn-aliassen-list" class="org-aliassen-list">Laden…</div>
                <div class="alias-toevoeg-rij" id="bn-alias-rij">
                    <input type="text" id="bn-alias-nieuw" class="inp alias-inp" placeholder="Alternatieve naam…" autocomplete="off">
                    <button class="btn-alias-ok" id="bn-alias-ok">&#10003; Toevoegen</button>
                </div>` : ''}
            </div>
        </div>

        ${!heeftNaam ? `<div class="bn-form-lock-hint">
            Vul eerst een <b>naam</b> in — daarna worden logo, aliassen, vereniging-info, baan-layout en sponsors beschikbaar en wordt alles automatisch opgeslagen zodra je een veld verlaat.
        </div>` : ''}

        <div id="bn-form-rest" class="${heeftNaam ? '' : 'bn-form-locked'}">
            <div class="inst-subtitel">Vereniging-info <span class="inst-subtitel-hint">(verschijnt in de Vereniging-tab op /public — cross-org: zelfde baan onder meerdere orgs gebruikt automatisch wat de andere org invult; hier override je dat voor jouw org)</span></div>

            <div class="bn-form-grid">
                <div class="bn-form-col">
                    <div class="inst-veld">
                        <label for="bn-adres">Adres</label>
                        <textarea id="bn-adres" rows="2" placeholder="bv.&#10;Sportpark Het Plantsoen 10&#10;1234 AB Leiderdorp">${escHtml(b.adres ?? '')}</textarea>
                        ${!b.adres ? _gedeeldHint(b.gedeeld_adres) : ''}
                    </div>
                    <div class="inst-veld">
                        <label for="bn-website">Website</label>
                        <input type="url" id="bn-website" value="${escHtml(b.website_url ?? '')}" placeholder="https://…" autocomplete="off">
                        ${!b.website_url ? _gedeeldHint(b.gedeeld_website_url) : ''}
                    </div>
                    <div class="inst-veld">
                        <label for="bn-over-tekst">Over deze vereniging</label>
                        <div class="bn-md-toolbar" data-md-for="bn-over-tekst">
                            <button type="button" class="bn-md-btn" data-md-prefix="# " title="Kop"><b>H</b></button>
                            <button type="button" class="bn-md-btn" data-md-wrap="**" title="Vet"><b>B</b></button>
                            <button type="button" class="bn-md-btn" data-md-wrap="*" title="Cursief"><i>I</i></button>
                            <button type="button" class="bn-md-btn" data-md-wrap="__" title="Onderstreept"><u>U</u></button>
                            <button type="button" class="bn-md-btn" data-md-prefix="- " title="Lijst-item">&bull; Lijst</button>
                            <button type="button" class="bn-md-btn" data-md-para="1" title="Nieuwe alinea">&para; Alinea</button>
                        </div>
                        <textarea id="bn-over-tekst" rows="5" placeholder="Korte intro over de vereniging en de baan — wordt onder de baan-layout getoond op /public.">${escHtml(b.over_tekst ?? '')}</textarea>
                        ${!b.over_tekst ? _gedeeldHint(b.gedeeld_over_tekst) : ''}
                    </div>
                </div>
                <div class="bn-form-col">
                    <div class="inst-veld">
                        <label>Foto bij over-tekst <small class="bn-hint-small">(optioneel; als leeg wordt het logo groot getoond)</small></label>
                        <div class="logo-preview-wrap bn-over-foto-wrap ${overFotoIsGedeeld ? 'bn-img-opacity-65' : ''}">
                            <img id="bn-over-foto-preview" src="${escHtml(overFotoSrc)}" alt="" ${overFotoSrc ? '' : 'hidden'}>
                            ${overFotoSrc ? '' : '<span class="logo-geen">Geen foto</span>'}
                        </div>
                        <label class="btn-upload ${b.id ? '' : 'bn-upload-disabled'}" for="bn-over-foto-file">&#128247; Foto uploaden</label>
                        <input type="file" id="bn-over-foto-file" accept="image/*" hidden>
                        ${b.over_foto ? `<button class="btn-del btn-small bn-foto-verwijder" id="bn-over-foto-del" type="button">🗑 Foto verwijderen</button>` : ''}
                        ${overFotoIsGedeeld
                            ? '<div class="bn-hint-info">Foto overgenomen van een andere organisatie met dezelfde baan-naam. Upload eigen foto om te overschrijven.</div>'
                            : ''}
                    </div>
                    ${layoutBlokHtml}
                </div>
            </div>

            ${b.id ? `<div class="inst-subtitel">Sponsors <span class="inst-subtitel-hint">(verschijnen in de public/coach-footer en op de poster bij wedstrijden op deze baan)</span></div>
                <div id="bn-sponsors-list" class="bn-sponsors-list">Laden…</div>
                <button class="btn-sponsor-add" id="bn-sponsor-add">+ Sponsor toevoegen</button>` : ''}
        </div>

        <div class="bn-form-acties">
            <button class="btn-secondary" id="bn-form-annuleer">Sluiten</button>
        </div>
    </div>`;
}

function bindBaanForm() {
    const wrap = document.getElementById('bn-form-wrap');
    if (!wrap) return;

    document.getElementById('bn-form-annuleer')?.addEventListener('click', _bnSluitForm);
    document.getElementById('bn-logo-file')?.addEventListener('change', uploadBaanLogo);
    document.getElementById('bn-alias-ok')?.addEventListener('click', voegAliasToe);
    document.getElementById('bn-sponsor-add')?.addEventListener('click', () => voegSponsorRijToeBaan(null));
    document.getElementById('bn-layout-edit')?.addEventListener('click', openBaanLayoutEditor);
    document.getElementById('bn-layout-del')?.addEventListener('click', verwijderBaanLayout);
    document.getElementById('bn-over-foto-file')?.addEventListener('change', uploadOverFoto);
    document.getElementById('bn-over-foto-del')?.addEventListener('click', verwijderOverFoto);

    // Markdown-toolbar: 5 knopjes die markers rond de selectie zetten
    // (zelfde subset als de public-render in _renderOverTekst).
    wrap.querySelectorAll('.bn-md-toolbar').forEach(bar => {
        const taId = bar.dataset.mdFor;
        bar.addEventListener('click', e => {
            const btn = e.target.closest('.bn-md-btn');
            if (!btn) return;
            const ta = document.getElementById(taId);
            if (!ta) return;
            if (btn.dataset.mdWrap)   _bnMdWrap(ta, btn.dataset.mdWrap);
            if (btn.dataset.mdPrefix) _bnMdPrefix(ta, btn.dataset.mdPrefix);
            if (btn.dataset.mdPara)   _bnMdPara(ta);
        });
    });

    // Autosave: debounced (1s na laatste edit) + direct bij blur. Vervangt de
    // Opslaan-knop; status rechtsboven in de form-header houdt de user op de
    // hoogte ("Opslaan…", "✓ Opgeslagen", "⚠ Fout").
    const autoFields = ['bn-naam', 'bn-stad', 'bn-ver', 'bn-adres', 'bn-website', 'bn-over-tekst'];
    for (const fid of autoFields) {
        const el = document.getElementById(fid);
        if (!el) continue;
        el.addEventListener('input', () => _bnAutosaveDebounce());
        el.addEventListener('blur',  () => _bnAutosaveNu());
    }

    if (bnActieveId && bnActieveId !== 'NIEUW') {
        laadAliassen(bnActieveId);
        laadBaanSponsors(bnActieveId);
    }
}

// ── Autosave-plumbing ────────────────────────────────────────────────────
// Debounced input-save (1s na laatste keystroke) + direct save-on-blur.
// Als deze baan nog geen DB-rij heeft (nieuwe baan) moet naam ≥ 2 tekens
// zijn vóór we naar de server gaan — anders faalt de save-validatie.
let _bnSaveTimer    = null;
let _bnSaveLopend   = false;
let _bnSaveWachtRij = false;
let _bnStatusTimer  = null;

function _bnAutosaveDebounce() {
    clearTimeout(_bnSaveTimer);
    _bnZetStatus('wachten');
    _bnSaveTimer = setTimeout(_bnAutosaveNu, 1000);
}
async function _bnAutosaveNu() {
    clearTimeout(_bnSaveTimer);
    const naamEl = document.getElementById('bn-naam');
    if (!naamEl) return;
    const naam = naamEl.value.trim();
    // Voorkom spam-saves bij elke blur zonder wijziging — alleen saven als
    // naam ≥ 2 tekens (anders faalt backend-validatie met "Naam verplicht").
    if (naam.length < 2) { _bnZetStatus(''); return; }
    if (_bnSaveLopend) { _bnSaveWachtRij = true; return; }
    _bnSaveLopend = true;
    _bnZetStatus('opslaan');
    try {
        await slaBaanOp({ stilleSave: true });
        _bnZetStatus('ok');
    } catch (e) {
        _bnZetStatus('fout', e?.message || String(e));
    } finally {
        _bnSaveLopend = false;
        if (_bnSaveWachtRij) {
            _bnSaveWachtRij = false;
            setTimeout(_bnAutosaveNu, 50);
        }
    }
}
function _bnZetStatus(toestand, foutTekst) {
    const el = document.getElementById('bn-save-status');
    if (!el) return;
    el.className = 'bn-save-status';
    if (toestand === 'wachten') {
        el.textContent = '…';
    } else if (toestand === 'opslaan') {
        el.classList.add('bn-save-status--opslaan');
        el.textContent = '⌛ Opslaan…';
    } else if (toestand === 'ok') {
        el.classList.add('bn-save-status--ok');
        el.textContent = '✓ Opgeslagen';
        // Na 2.5s weer leegmaken om visuele rust te houden.
        clearTimeout(_bnStatusTimer);
        _bnStatusTimer = setTimeout(() => {
            if (!el.classList.contains('bn-save-status--opslaan')
             && !el.classList.contains('bn-save-status--fout')) {
                el.textContent = '';
                el.className = 'bn-save-status';
            }
        }, 2500);
    } else if (toestand === 'fout') {
        el.classList.add('bn-save-status--fout');
        el.textContent = '⚠ ' + (foutTekst || 'Fout');
    } else {
        el.textContent = '';
    }
}

// Dirty-check-helper: zijn er wijzigingen t.o.v. de serverlaatste snapshot?
// Nu simpel: als nieuwe baan en naam leeg → geen state om te bewaren.
async function _bnSluitForm() {
    const naam = document.getElementById('bn-naam')?.value.trim() ?? '';
    const isNieuw = !document.getElementById('bn-id')?.value;
    // Nog ongesave'de nieuwe baan met naam → vraag bevestiging via onze
    // eigen confirm-functie (geen native confirm).
    if (isNieuw && naam.length >= 2) {
        const ok = await toonBevestigDialog(
            'Je hebt een nieuwe baan "' + naam + '" ingevuld maar nog niet opgeslagen. Alles weggooien?',
            'Baan sluiten', 'Weggooien', 'Terug'
        );
        if (!ok) return;
    }
    bnActieveId = null;
    renderBanenTabel();
}

// ── Markdown-toolbar helpers ─────────────────────────────────────────────
// Wrapt de selectie met marker (bv. "**selectie**"); bij lege selectie
// zet 'em rondom "tekst" zodat de user ziet waar hij moet typen.
function _bnMdWrap(ta, marker) {
    const s = ta.selectionStart, e = ta.selectionEnd;
    const v = ta.value;
    const sel = v.slice(s, e) || 'tekst';
    ta.value = v.slice(0, s) + marker + sel + marker + v.slice(e);
    ta.focus();
    ta.setSelectionRange(s + marker.length, s + marker.length + sel.length);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
}
// Zet prefix voor elke regel in de selectie (of enkel de huidige regel).
function _bnMdPrefix(ta, prefix) {
    const s = ta.selectionStart, e = ta.selectionEnd;
    const v = ta.value;
    const lineStart = v.lastIndexOf('\n', s - 1) + 1;
    const chunk = v.slice(lineStart, e);
    const prefixed = chunk.split('\n').map(l => prefix + l).join('\n');
    ta.value = v.slice(0, lineStart) + prefixed + v.slice(e);
    ta.focus();
    const delta = prefixed.length - chunk.length;
    ta.setSelectionRange(s + prefix.length, e + delta);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
}
// Voegt een lege regel in op cursor-positie → nieuwe alinea.
function _bnMdPara(ta) {
    const s = ta.selectionStart;
    const v = ta.value;
    ta.value = v.slice(0, s) + '\n\n' + v.slice(s);
    ta.focus();
    ta.setSelectionRange(s + 2, s + 2);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
}

// ── Sponsors per baan ─────────────────────────────────────────────────────
async function laadBaanSponsors(baanId) {
    const list = document.getElementById('bn-sponsors-list');
    if (!list) return;
    try {
        const res = await fetch('api/banen.php?action=sponsors&baan_id=' + encodeURIComponent(baanId));
        const sponsors = await res.json();
        list.innerHTML = '';
        if (Array.isArray(sponsors) && sponsors.length) {
            sponsors.forEach(s => voegSponsorRijToeBaan(s));
        } else {
            list.innerHTML = '<div class="alias-leeg">Nog geen sponsors voor deze baan.</div>';
        }
    } catch (e) {
        list.innerHTML = `<div class="status-msg error">${escHtml(e.message)}</div>`;
    }
}

function voegSponsorRijToeBaan(sponsor) {
    const list = document.getElementById('bn-sponsors-list');
    if (!list) return;
    // Eerste rij toevoegen → eerst de "leeg"-melding wissen
    const leegMld = list.querySelector('.alias-leeg');
    if (leegMld) leegMld.remove();

    const rij = document.createElement('div');
    rij.className  = 'sponsor-rij';
    rij.dataset.id = sponsor?.id ?? '';
    const baseUrl  = new URL('.', window.location.href).href;
    const logoSrc  = sponsor?.logo_path ? (baseUrl + sponsor.logo_path + '?t=' + Date.now()) : '';
    rij.innerHTML = `
        <div class="sponsor-logo-wrap">
            ${sponsor?.logo_path
                ? `<img class="sponsor-logo-prev" src="${escHtml(logoSrc)}" alt="">`
                : '<span class="logo-geen">Geen logo</span>'}
        </div>
        <label class="btn-upload btn-small">&#128247;
            <input type="file" accept="image/*" class="sponsor-logo-file" hidden>
        </label>
        <input type="text" class="inp sponsor-naam" placeholder="Naam sponsor"
               value="${escHtml(sponsor?.naam ?? '')}">
        <input type="url"  class="inp sponsor-url"  placeholder="https://…"
               value="${escHtml(sponsor?.url ?? '')}">
        <button class="btn-del btn-sponsor-del" title="Verwijderen">&#128465;</button>`;

    // Blur op naam/url → autosave triggeren zodat de rij een server-id
    // krijgt (nodig voor logo-upload).
    rij.querySelectorAll('.sponsor-naam, .sponsor-url').forEach(inp => {
        inp.addEventListener('blur', () => _bnAutosaveNu());
    });

    rij.querySelector('.sponsor-logo-file').addEventListener('change', async e => {
        if (!e.target.files[0]) return;
        let sId = rij.dataset.id;
        // Nog geen id maar wel naam ingevuld → eerst autosave zodat de
        // rij een server-id krijgt, dán uploaden.
        if (!sId) {
            const naam = rij.querySelector('.sponsor-naam')?.value.trim();
            if (!naam) {
                toonBevestigDialog(
                    'Vul eerst een naam in voor deze sponsor; het logo wordt dan opgeslagen zodra je de naam-regel verlaat.',
                    'Sponsor-logo', 'OK', '');
                return;
            }
            await _bnAutosaveNu().catch(() => {});
            sId = rij.dataset.id;
            if (!sId) {
                toonBevestigDialog(
                    'Kon sponsor niet opslaan — probeer opnieuw.',
                    'Sponsor-logo', 'OK', '');
                return;
            }
        }
        uploadBaanSponsorLogo(sId, e.target.files[0], rij);
    });
    rij.querySelector('.btn-sponsor-del').addEventListener('click', async () => {
        const sId = rij.dataset.id;
        if (sId) {
            const fd = new FormData();
            fd.append('action', 'delete_sponsor');
            fd.append('id', sId);
            await fetch('api/banen.php', { method: 'POST', body: fd });
        }
        rij.remove();
        // Als er geen rijen meer zijn, weer "leeg"-tekst tonen
        if (!list.querySelector('.sponsor-rij')) {
            list.innerHTML = '<div class="alias-leeg">Nog geen sponsors voor deze baan.</div>';
        }
    });
    list.appendChild(rij);
}

async function uploadBaanSponsorLogo(sponsorId, file, rij) {
    const fd = new FormData();
    fd.append('type', 'baan_sponsor');
    fd.append('id',   sponsorId);
    fd.append('logo', file);
    try {
        const res  = await fetch('api/upload.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        const wrap = rij.querySelector('.sponsor-logo-wrap');
        wrap.innerHTML = `<img class="sponsor-logo-prev" src="${escHtml(data.path)}?t=${Date.now()}" alt="">`;
    } catch (e) {
        toonBevestigDialog('Upload mislukt: ' + e.message, 'Sponsor-logo', 'OK', '');
    }
}

// Lees alle sponsor-rijen uit de DOM → array voor save_sponsors API
function leesBaanSponsorsUitForm() {
    const list = document.getElementById('bn-sponsors-list');
    if (!list) return [];
    const rijen = list.querySelectorAll('.sponsor-rij');
    const sponsors = [];
    rijen.forEach((rij, idx) => {
        const naam = rij.querySelector('.sponsor-naam')?.value.trim() || '';
        if (!naam) return; // skip lege rijen
        sponsors.push({
            id:       rij.dataset.id || null,
            naam,
            url:      rij.querySelector('.sponsor-url')?.value.trim() || null,
            volgorde: idx,
        });
    });
    return sponsors;
}

// Opts: { stilleSave: true } → geen error-dialog bij fout (status-indicator
// in form toont dan de fout). Zonder stilleSave: oude gedrag met modals.
// Gooit altijd een error op als save faalt, zodat autosave de status kan
// updaten en de wachtrij kan afhandelen.
async function slaBaanOp(opts = {}) {
    const stille = !!opts.stilleSave;
    const id    = document.getElementById('bn-id').value;
    const naam  = document.getElementById('bn-naam').value.trim();
    const stad  = document.getElementById('bn-stad').value.trim();
    const ver   = document.getElementById('bn-ver').value.trim();
    const adres = document.getElementById('bn-adres')?.value.trim()       ?? '';
    const over  = document.getElementById('bn-over-tekst')?.value.trim()  ?? '';
    const web   = document.getElementById('bn-website')?.value.trim()     ?? '';

    if (!naam) {
        if (!stille) toonBevestigDialog('Naam is verplicht.', 'Baan opslaan');
        throw new Error('Naam is verplicht');
    }

    const fd = new FormData();
    fd.append('action', 'save');
    if (id) fd.append('id', id);
    else if (bnHuidigeOrgId) fd.append('org_id', bnHuidigeOrgId);
    fd.append('naam', naam);
    fd.append('stad', stad);
    fd.append('vereniging_naam', ver);
    fd.append('adres', adres);
    fd.append('over_tekst', over);
    fd.append('website_url', web);

    const res = await fetch('api/banen.php', { method: 'POST', body: fd });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        if (!stille) toonBevestigDialog(data.error || 'Fout', 'Baan opslaan');
        throw new Error(data.error || 'HTTP ' + res.status);
    }
    const wasNieuw = !id;
    bnActieveId = data.id ?? null;

    // Sponsors mee-opslaan via aparte JSON-call. Ook bij stille autosave:
    // zonder dit krijgen nieuwe sponsor-rijen nooit een server-id en kan
    // de user er geen logo bij uploaden.
    const sponsors = leesBaanSponsorsUitForm();
    if (bnActieveId && sponsors.length) {
        try {
            const spRes = await fetch('api/banen.php?action=save_sponsors', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ baan_id: bnActieveId, sponsors }),
            });
            const spData = await spRes.json().catch(() => ({}));
            if (spRes.ok && Array.isArray(spData.sponsors)) {
                // Match DOM-rijen op naam → krijgen hun server-id (zodat
                // logo-upload werkt). Rijen zonder naam skippen we; server
                // skipt die ook. Naam-collisions binnen één baan zijn
                // zeldzaam genoeg om op first-match te gaan.
                const toegewezen = new Set();
                document.querySelectorAll('#bn-sponsors-list .sponsor-rij').forEach(rij => {
                    if (rij.dataset.id) return;
                    const naam = rij.querySelector('.sponsor-naam')?.value.trim();
                    if (!naam) return;
                    const match = spData.sponsors.find(s =>
                        s.naam === naam && !toegewezen.has(s.id)
                    );
                    if (match) {
                        rij.dataset.id = match.id;
                        toegewezen.add(match.id);
                    }
                });
            }
        } catch (e) {
            if (!stille) {
                toonBevestigDialog('Sponsors-opslaan mislukt: ' + e.message, 'Baan opslaan', 'OK', '');
            }
        }
    }

    // Bij een VERSE baan (eerste save) herladen we de lijst zodat het
    // form bijwerkt naar edit-mode met id, upload-knoppen, lock-weg etc.
    // Bij vervolgsaves is lijst-reload niet nodig per keystroke; we
    // updaten de in-memory record lokaal en skipppen de fetch.
    if (wasNieuw) {
        // Focus + cursor-positie onthouden zodat user doorkan typen na
        // de re-render (anders verdwijnt focus midden in 't typen).
        const ae = document.activeElement;
        const focusId = ae?.id;
        const selStart = ae?.selectionStart;
        const selEnd   = ae?.selectionEnd;
        await laadBanen();
        if (focusId) {
            const newEl = document.getElementById(focusId);
            if (newEl) {
                newEl.focus();
                if (selStart != null && typeof newEl.setSelectionRange === 'function') {
                    try { newEl.setSelectionRange(selStart, selEnd); } catch {}
                }
            }
        }
    } else {
        const b = bnLijst.find(x => x.id === bnActieveId);
        if (b) {
            b.naam = naam; b.stad = stad; b.vereniging_naam = ver;
            b.adres = adres; b.over_tekst = over; b.website_url = web;
        }
    }
}

async function verwijderBaan(id) {
    const b = bnLijst.find(x => x.id === id);
    if (!b) return;
    if (!await toonBevestigDialog(
        `Baan "${b.naam}" verwijderen? Aliassen worden ook verwijderd. Gekoppelde wedstrijden behouden hun data, maar verliezen de baan-koppeling.`,
        'Baan verwijderen'
    )) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    const res = await fetch('api/banen.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error || 'Fout', 'Verwijderen'); return; }
    if (bnActieveId === id) bnActieveId = null;
    await laadBanen();
}

async function uploadBaanLogo(e) {
    const file = e.target.files[0];
    const id   = document.getElementById('bn-id').value;
    if (!file || !id) return;
    // Eerst pending autosave flushen zodat typing-state (bv. net ingetikt
    // adres) niet verdwijnt bij de laadBanen-refresh na upload.
    await _bnAutosaveNu().catch(() => {});
    const fd = new FormData();
    fd.append('type', 'baan');
    fd.append('id', id);
    fd.append('logo', file);
    const res = await fetch('api/upload.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error || 'Upload mislukt', 'Logo uploaden'); return; }
    await laadBanen();
}

// Foto bij "Over deze vereniging"-blok — zelfde patroon als logo-upload,
// upload.php type 'baan_over_foto' schrijft naar uploads/banen_over/<id>/.
async function uploadOverFoto(e) {
    const file = e.target.files[0];
    const id   = document.getElementById('bn-id').value;
    if (!file || !id) return;
    await _bnAutosaveNu().catch(() => {});
    const fd = new FormData();
    fd.append('type', 'baan_over_foto');
    fd.append('id', id);
    fd.append('logo', file);
    const res = await fetch('api/upload.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error || 'Upload mislukt', 'Foto uploaden'); return; }
    await laadBanen();
}

async function verwijderOverFoto() {
    const id = document.getElementById('bn-id').value;
    if (!id) return;
    const ok = await toonBevestigDialog('Foto bij "Over deze vereniging" verwijderen?',
        'Foto verwijderen', 'Verwijderen', 'Annuleren');
    if (!ok) return;
    // Via save-action: over_foto moet apart met een nieuwe mini-action.
    // Pragmatisch: we gebruiken een losse endpoint-call die alleen over_foto
    // op null zet. De bestaande save-action herschrijft andere velden niet
    // ongewenst, maar is bedoeld voor volledige save; dus apart endpoint.
    const fd = new FormData();
    fd.append('action', 'wis_over_foto');
    fd.append('id', id);
    const res = await fetch('api/banen.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error || 'Fout', 'Foto verwijderen'); return; }
    await laadBanen();
}

async function laadAliassen(id) {
    const list = document.getElementById('bn-aliassen-list');
    if (!list) return;
    try {
        const res = await fetch('api/banen.php?id=' + encodeURIComponent(id));
        const data = await res.json();
        const aliassen = data.aliassen ?? [];
        if (!aliassen.length) {
            list.innerHTML = '<div class="alias-leeg">Nog geen aliassen.</div>';
            return;
        }
        list.innerHTML = aliassen.map(a =>
            `<div class="alias-rij">
                <span class="alias-naam">${escHtml(a.naam)}</span>
                <button class="btn-alias-del" data-aid="${escHtml(a.id)}" title="Verwijderen">&times;</button>
            </div>`
        ).join('');
        list.querySelectorAll('.btn-alias-del').forEach(btn =>
            btn.addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('action', 'alias_verwijderen');
                fd.append('id', btn.dataset.aid);
                await fetch('api/banen.php', { method: 'POST', body: fd });
                laadAliassen(id);
                laadBanen();
            }));
    } catch (e) {
        list.innerHTML = `<div class="status-msg error">${escHtml(e.message)}</div>`;
    }
}

async function voegAliasToe() {
    const id  = document.getElementById('bn-id').value;
    const inp = document.getElementById('bn-alias-nieuw');
    const naam = inp.value.trim();
    if (!id || !naam) return;
    const fd = new FormData();
    fd.append('action', 'alias_toevoegen');
    fd.append('id', id);
    fd.append('naam', naam);
    const res = await fetch('api/banen.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error || 'Fout', 'Alias toevoegen'); return; }
    inp.value = '';
    laadAliassen(id);
    laadBanen();
}

// Knop-handler op de Banen-tab — werkt ook voor dynamisch ingespoten content
document.addEventListener('click', e => {
    if (e.target?.id === 'btn-nieuwe-baan') {
        bnActieveId = 'NIEUW';
        renderBanenTabel();
    }
});

// ── Baan-layout: editor openen + verwijderen ─────────────────────────────
// De editor-module zit in js/baanlayout_editor.js — PoC-port met piste,
// wegparcours en infield (stadium / freecurve / follow-piste per sub-path).
//
// Cross-org fallback: als deze baan geen eigen layout heeft maar wél een
// gedeelde (andere org met dezelfde naam), start de editor met die layout
// als beginpunt. Bij opslaan wordt de eigen rij gevuld — gedeelde layout
// blijft onaangeraakt.
function openBaanLayoutEditor() {
    const b = bnLijst.find(x => x.id === bnActieveId);
    if (!b) return;
    if (typeof window.openBaanlayoutEditorModal !== 'function') {
        toonBevestigDialog(
            'De baan-layout-editor kon niet geladen worden (js/baanlayout_editor.js ontbreekt of is nog niet gedeployed).',
            'Baan-layout', 'OK', ''
        );
        return;
    }
    const eigen   = _bnParseLayout(b.layout_data);
    const gedeeld = eigen ? null : _bnParseLayout(b.gedeeld_layout_data);
    window.openBaanlayoutEditorModal({
        baanNaam: b.naam,
        initial:  eigen || gedeeld,
        onSave:   (data) => _bnLayoutSave(b.id, data),
    });
}

async function _bnLayoutSave(baanId, layoutData) {
    const meld = document.getElementById('bn-layout-msg');
    if (meld) { meld.hidden = false; meld.className = 'status-msg loading bn-layout-msg'; meld.textContent = 'Opslaan…'; }
    try {
        const r = await fetch('api/banen.php?action=save_layout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ baan_id: baanId, layout_data: layoutData }),
        });
        const d = await r.json();
        if (d.error) throw new Error(d.error);
        // Lokale cache bijwerken en lijst + preview refresh
        const b = bnLijst.find(x => x.id === baanId);
        if (b) b.layout_data = layoutData ? JSON.stringify(layoutData) : null;
        renderBanenTabel();
        if (meld) { meld.className = 'status-msg ok bn-layout-msg'; meld.textContent = 'Opgeslagen.'; }
    } catch (e) {
        if (meld) { meld.className = 'status-msg error bn-layout-msg'; meld.textContent = '⚠ ' + (e.message || e); }
    }
}

async function verwijderBaanLayout() {
    const b = bnLijst.find(x => x.id === bnActieveId);
    if (!b) return;
    const ok = await toonBevestigDialog(
        `Baan-layout van "${b.naam}" verwijderen? De getekende piste, wegparcours en infield verdwijnen; aliassen, sponsors en logo blijven staan.`,
        'Layout verwijderen', 'Verwijderen', 'Annuleren'
    );
    if (!ok) return;
    await _bnLayoutSave(b.id, null);
}
