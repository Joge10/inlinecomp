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
    const pad = Math.max(w, h) * 0.08;
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
                class="bn-logo-mini" style="opacity:.65"
                title="Logo overgenomen van een andere organisatie met dezelfde baan-naam">`;
        } else {
            logo = '<span class="bn-geen-logo">—</span>';
        }
        const layoutData = _bnParseLayout(b.layout_data);
        const layoutSvg = layoutData
            ? renderBaanLayoutThumb(layoutData, 40)
            : '<span class="bn-geen-logo">—</span>';
        const verNaam = b.vereniging_naam
            ? escHtml(b.vereniging_naam)
            : (b.gedeeld_vereniging_naam
                ? `<span style="opacity:.65;font-style:italic" title="Overgenomen van andere organisatie">${escHtml(b.gedeeld_vereniging_naam)}</span>`
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

function openBaanForm(id) {
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

    return `<div id="bn-form-wrap" class="bn-form-wrap">
        <h3>${isNieuw ? 'Nieuwe baan' : 'Baan bewerken'}</h3>
        <input type="hidden" id="bn-id" value="${escHtml(b.id ?? '')}">
        <div class="mf-rij mf-2col">
            <label class="mf-lbl"><span>Naam <span class="vereist">*</span></span>
                <input type="text" id="bn-naam" class="inp" value="${escHtml(b.naam)}" placeholder="bv. Sportpark Het Plantsoen">
            </label>
            <label class="mf-lbl"><span>Stad</span>
                <input type="text" id="bn-stad" class="inp" value="${escHtml(b.stad ?? '')}" placeholder="bv. Leiderdorp">
            </label>
        </div>
        <div class="mf-rij mf-2col">
            <label class="mf-lbl"><span>Gastheer-vereniging</span>
                <input type="text" id="bn-ver" class="inp" value="${escHtml(b.vereniging_naam ?? '')}" placeholder="bv. DOST 1925">
            </label>
            <label class="mf-lbl"><span>Logo</span>
                <div class="logo-preview-wrap">
                    <img id="bn-logo-preview" src="${escHtml(logoPreviewSrc)}" alt="" style="${b.logo_path ? '' : 'display:none'}">
                    ${b.logo_path ? '' : '<span class="logo-geen">Geen logo</span>'}
                </div>
                <label class="btn-upload" for="bn-logo-file" id="bn-logo-upload-lbl" ${b.id ? '' : 'style="opacity:.5;pointer-events:none"'}>&#128247; Logo uploaden</label>
                <input type="file" id="bn-logo-file" accept="image/*" style="display:none">
                ${b.id ? '' : '<div class="label-hint">Eerst opslaan, daarna kun je een logo uploaden.</div>'}
            </label>
        </div>

        ${b.id ? `<div class="bn-aliassen-blok">
            <div class="inst-subtitel">Aliassen <span class="inst-subtitel-hint">(alternatieve schrijfwijzen voor venue-naam in KNSB-feed)</span></div>
            <div id="bn-aliassen-list" class="org-aliassen-list">Laden…</div>
            <div class="alias-toevoeg-rij" id="bn-alias-rij">
                <input type="text" id="bn-alias-nieuw" class="inp alias-inp" placeholder="Alternatieve naam…">
                <button class="btn-alias-ok"  id="bn-alias-ok">&#10003; Toevoegen</button>
            </div>
        </div>` : ''}

        ${b.id ? `<div class="bn-layout-blok">
            <div class="inst-subtitel">Baan-layout <span class="inst-subtitel-hint">(piste + wegparcours + infield — getekend via de editor)</span></div>
            <div class="bn-layout-rij">
                <div class="bn-layout-preview" id="bn-layout-preview">
                    ${b.layout_data
                        ? renderBaanLayoutThumb(_bnParseLayout(b.layout_data), 120)
                        : '<span class="bn-layout-leeg">Nog geen layout getekend</span>'}
                </div>
                <div class="bn-layout-acties">
                    <button class="btn-secondary" id="bn-layout-edit" type="button">${b.layout_data ? '✎ Layout bewerken…' : '＋ Layout tekenen…'}</button>
                    ${b.layout_data ? `<button class="btn-del btn-small" id="bn-layout-del" type="button" title="Layout verwijderen">🗑</button>` : ''}
                </div>
            </div>
            <div class="status-msg bn-layout-msg" id="bn-layout-msg" hidden></div>
        </div>` : ''}

        ${b.id ? `<div class="bn-sponsors-blok">
            <div class="inst-subtitel">Sponsors <span class="inst-subtitel-hint">(verschijnen in public/coach-footer en op de poster bij wedstrijden op deze baan)</span></div>
            <div id="bn-sponsors-list" class="bn-sponsors-list">Laden…</div>
            <button class="btn-secondary btn-small" id="bn-sponsor-add">+ Sponsor toevoegen</button>
        </div>` : ''}

        <div class="bn-form-acties">
            <button class="btn-secondary" id="bn-form-annuleer">Annuleren</button>
            <button class="btn-primary"   id="bn-form-opslaan">Opslaan</button>
        </div>
    </div>`;
}

function bindBaanForm() {
    const wrap = document.getElementById('bn-form-wrap');
    if (!wrap) return;

    document.getElementById('bn-form-annuleer')?.addEventListener('click', () => {
        bnActieveId = null;
        renderBanenTabel();
    });
    document.getElementById('bn-form-opslaan')?.addEventListener('click', slaBaanOp);
    document.getElementById('bn-logo-file')?.addEventListener('change', uploadBaanLogo);
    document.getElementById('bn-alias-ok')?.addEventListener('click', voegAliasToe);
    document.getElementById('bn-sponsor-add')?.addEventListener('click', () => voegSponsorRijToeBaan(null));
    document.getElementById('bn-layout-edit')?.addEventListener('click', openBaanLayoutEditor);
    document.getElementById('bn-layout-del')?.addEventListener('click', verwijderBaanLayout);

    if (bnActieveId && bnActieveId !== 'NIEUW') {
        laadAliassen(bnActieveId);
        laadBaanSponsors(bnActieveId);
    }
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
            <input type="file" accept="image/*" class="sponsor-logo-file" style="display:none">
        </label>
        <input type="text" class="inp sponsor-naam" placeholder="Naam sponsor"
               value="${escHtml(sponsor?.naam ?? '')}">
        <input type="url"  class="inp sponsor-url"  placeholder="https://…"
               value="${escHtml(sponsor?.url ?? '')}">
        <button class="btn-del btn-sponsor-del" title="Verwijderen">&#128465;</button>`;

    rij.querySelector('.sponsor-logo-file').addEventListener('change', e => {
        if (!e.target.files[0]) return;
        const sId = rij.dataset.id;
        if (!sId) {
            toonBevestigDialog(
                'Sla eerst de baan + sponsor-naam op (klik op "Opslaan" onderaan), daarna kun je het logo uploaden.',
                'Sponsor-logo', 'OK', '');
            return;
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

async function slaBaanOp() {
    const id   = document.getElementById('bn-id').value;
    const naam = document.getElementById('bn-naam').value.trim();
    const stad = document.getElementById('bn-stad').value.trim();
    const ver  = document.getElementById('bn-ver').value.trim();

    if (!naam) { toonBevestigDialog('Naam is verplicht.', 'Baan opslaan'); return; }

    const fd = new FormData();
    fd.append('action', 'save');
    if (id) fd.append('id', id);
    else if (bnHuidigeOrgId) fd.append('org_id', bnHuidigeOrgId);
    fd.append('naam', naam);
    fd.append('stad', stad);
    fd.append('vereniging_naam', ver);

    try {
        const res = await fetch('api/banen.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok) { toonBevestigDialog(data.error || 'Fout', 'Baan opslaan'); return; }
        bnActieveId = data.id ?? null;

        // Sponsors mee-opslaan via aparte JSON-call (alleen als er een baan-id is)
        const sponsors = leesBaanSponsorsUitForm();
        if (bnActieveId && sponsors.length) {
            try {
                await fetch('api/banen.php?action=save_sponsors', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ baan_id: bnActieveId, sponsors }),
                });
            } catch (e) {
                toonBevestigDialog('Sponsors-opslaan mislukt: ' + e.message, 'Baan opslaan', 'OK', '');
            }
        }

        await laadBanen();
    } catch (e) {
        toonBevestigDialog('Fout: ' + e.message, 'Baan opslaan');
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
    const fd = new FormData();
    fd.append('type', 'baan');
    fd.append('id', id);
    fd.append('logo', file);
    const res = await fetch('api/upload.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error || 'Upload mislukt', 'Logo uploaden'); return; }
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
    window.openBaanlayoutEditorModal({
        baanNaam: b.naam,
        initial:  _bnParseLayout(b.layout_data),
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
