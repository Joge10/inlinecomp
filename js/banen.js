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
        const verNaam = b.vereniging_naam
            ? escHtml(b.vereniging_naam)
            : (b.gedeeld_vereniging_naam
                ? `<span style="opacity:.65;font-style:italic" title="Overgenomen van andere organisatie">${escHtml(b.gedeeld_vereniging_naam)}</span>`
                : '');
        const actief = b.id === bnActieveId ? ' bn-actief' : '';
        return `<tr class="bn-rij${actief}" data-id="${escHtml(b.id)}">
            <td class="bn-logo-cel">${logo}</td>
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
                <th>Logo</th><th>Naam · stad</th><th>Gastheer-vereniging</th>
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
