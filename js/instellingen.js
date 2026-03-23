/* InlineComp – Instellingen: organisaties & sponsors */

let orgs         = [];      // geladen organisatielijst
let actieveOrg   = null;    // huidig geselecteerde org
let orgLijstKaart = null;   // actieve kaart in lijst

// ── Initialisatie ──────────────────────────────────────────────────────────────

function initInstellingen() {
    el('btn-nieuw-org').addEventListener('click', () => nieuweOrg());
    el('btn-org-opslaan').addEventListener('click', () => slaOrgOp());
    el('btn-org-verwijderen').addEventListener('click', () => verwijderOrg());
    el('btn-sponsor-add').addEventListener('click', () => voegSponsorRijToe());

    el('org-logo-file').addEventListener('change', e => {
        if (e.target.files[0]) uploadLogo('org', actieveOrg?.id, e.target.files[0]);
    });

    laadOrgs();
}

// ── Organisaties laden ─────────────────────────────────────────────────────────

async function laadOrgs() {
    const lijst = el('org-list');
    try {
        const res  = await fetch('api/organisaties.php');
        orgs       = await res.json();
        renderOrgLijst();
    } catch(e) {
        lijst.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

function renderOrgLijst() {
    const lijst = el('org-list');
    if (!orgs.length) {
        lijst.innerHTML = '<div class="status-msg info">Nog geen organisaties.</div>';
        return;
    }
    lijst.innerHTML = '';
    orgs.forEach(o => {
        const kaart = document.createElement('div');
        kaart.className = 'org-kaart' + (actieveOrg?.id === o.id ? ' active' : '');
        kaart.innerHTML =
            `<div class="org-kaart-naam">${escHtml(o.naam)}</div>` +
            (o.sponsor_count > 0
                ? `<div class="org-kaart-meta">${o.sponsor_count} sponsor${o.sponsor_count !== 1 ? 's' : ''}</div>`
                : '');
        kaart.addEventListener('click', () => selecteerOrg(kaart, o.id));
        lijst.appendChild(kaart);
    });
}

// ── Organisatie selecteren ─────────────────────────────────────────────────────

async function selecteerOrg(kaart, orgId) {
    if (orgLijstKaart) orgLijstKaart.classList.remove('active');
    kaart.classList.add('active');
    orgLijstKaart = kaart;

    try {
        const res = await fetch(`api/organisaties.php?id=${encodeURIComponent(orgId)}`);
        const org = await res.json();
        actieveOrg = org;
        vulOrgFormulier(org);
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

function nieuweOrg() {
    if (orgLijstKaart) orgLijstKaart.classList.remove('active');
    orgLijstKaart = null;
    actieveOrg    = null;
    vulOrgFormulier(null);
}

// ── Formulier vullen ───────────────────────────────────────────────────────────

function vulOrgFormulier(org) {
    el('org-form-panel').style.display = 'block';
    el('org-form-titel').textContent   = org ? org.naam : 'Nieuwe organisatie';
    el('org-naam').value               = org?.naam    ?? '';
    el('org-website').value            = org?.website ?? '';
    el('org-status').innerHTML         = '';
    el('btn-org-verwijderen').style.display = org ? '' : 'none';

    // Logo
    const preview = el('org-logo-preview');
    const geen    = el('org-logo-geen');
    if (org?.logo_path) {
        preview.src           = org.logo_path + '?t=' + Date.now();
        preview.style.display = '';
        geen.style.display    = 'none';
    } else {
        preview.src           = '';
        preview.style.display = 'none';
        geen.style.display    = '';
    }
    el('org-logo-file').value = '';

    // Sponsors
    renderSponsors(org?.sponsors ?? []);
}

// ── Sponsors ──────────────────────────────────────────────────────────────────

function renderSponsors(sponsors) {
    const wrap = el('org-sponsors-list');
    wrap.innerHTML = '';
    sponsors.forEach(s => voegSponsorRijToe(s));
}

function voegSponsorRijToe(sponsor = null) {
    const wrap = el('org-sponsors-list');
    const rij  = document.createElement('div');
    rij.className    = 'sponsor-rij';
    rij.dataset.id   = sponsor?.id   ?? '';
    rij.innerHTML = `
        <div class="sponsor-logo-wrap">
            ${sponsor?.logo_path
                ? `<img class="sponsor-logo-prev" src="${escHtml(sponsor.logo_path)}?t=${Date.now()}" alt="">`
                : '<span class="logo-geen">Geen logo</span>'}
        </div>
        <label class="btn-upload btn-small">&#128247;
            <input type="file" accept="image/*" class="sponsor-logo-file" style="display:none">
        </label>
        <input type="text" class="inp sponsor-naam" placeholder="Naam sponsor"
               value="${escHtml(sponsor?.naam ?? '')}">
        <input type="url"  class="inp sponsor-url"  placeholder="https://…"
               value="${escHtml(sponsor?.url ?? '')}">
        <button class="btn-sponsor-del" title="Verwijderen">&#128465;</button>`;

    rij.querySelector('.sponsor-logo-file').addEventListener('change', e => {
        if (!e.target.files[0]) return;
        const sId = rij.dataset.id;
        if (!sId) {
            el('org-status').innerHTML =
                '<div class="status-msg info">Sla eerst de organisatie op, dan kan je het logo uploaden.</div>';
            return;
        }
        uploadLogo('sponsor', sId, e.target.files[0], rij);
    });

    rij.querySelector('.btn-sponsor-del').addEventListener('click', async () => {
        const sId = rij.dataset.id;
        if (sId) {
            await fetch('api/organisaties.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_sponsor', id: sId }),
            });
        }
        rij.remove();
    });

    wrap.appendChild(rij);
}

// ── Opslaan ───────────────────────────────────────────────────────────────────

async function slaOrgOp() {
    const naam = el('org-naam').value.trim();
    if (!naam) {
        el('org-status').innerHTML = '<div class="status-msg error">Naam is verplicht.</div>';
        return;
    }

    const sponsorRijen = [...el('org-sponsors-list').querySelectorAll('.sponsor-rij')];
    const sponsors = sponsorRijen
        .map(r => ({
            id:   r.dataset.id || null,
            naam: r.querySelector('.sponsor-naam').value.trim(),
            url:  r.querySelector('.sponsor-url').value.trim() || null,
        }))
        .filter(s => s.naam);

    const body = {
        action:   'save',
        id:       actieveOrg?.id ?? null,
        naam,
        website:  el('org-website').value.trim() || null,
        sponsors,
    };

    el('btn-org-opslaan').disabled = true;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        });
        const opgeslagen = await res.json();
        if (opgeslagen.error) throw new Error(opgeslagen.error);

        actieveOrg = opgeslagen;
        await laadOrgs();
        vulOrgFormulier(opgeslagen);
        el('org-status').innerHTML = '<div class="status-msg success">✓ Opgeslagen.</div>';
        setTimeout(() => { el('org-status').innerHTML = ''; }, 3000);
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    } finally {
        el('btn-org-opslaan').disabled = false;
    }
}

// ── Verwijderen ───────────────────────────────────────────────────────────────

async function verwijderOrg() {
    if (!actieveOrg) return;
    if (!confirm(`Organisatie "${actieveOrg.naam}" verwijderen? Dit kan niet ongedaan worden gemaakt.`)) return;

    await fetch('api/organisaties.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'delete', id: actieveOrg.id }),
    });

    actieveOrg  = null;
    orgLijstKaart = null;
    el('org-form-panel').style.display = 'none';
    await laadOrgs();
}

// ── Logo uploaden ──────────────────────────────────────────────────────────────

async function uploadLogo(type, id, file, sponsorRij = null) {
    const statusEl = el('org-status');
    statusEl.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Logo uploaden…</div>';

    const fd = new FormData();
    fd.append('type', type);
    fd.append('id',   id);
    fd.append('logo', file);

    try {
        const res  = await fetch('api/upload.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        if (type === 'org') {
            const prev = el('org-logo-preview');
            prev.src           = data.path + '?t=' + Date.now();
            prev.style.display = '';
            el('org-logo-geen').style.display = 'none';
            if (actieveOrg) actieveOrg.logo_path = data.path;
        } else if (sponsorRij) {
            const wrap = sponsorRij.querySelector('.sponsor-logo-wrap');
            wrap.innerHTML = `<img class="sponsor-logo-prev" src="${escHtml(data.path)}?t=${Date.now()}" alt="">`;
        }

        statusEl.innerHTML = '<div class="status-msg success">✓ Logo opgeslagen.</div>';
        setTimeout(() => { statusEl.innerHTML = ''; }, 3000);
    } catch(e) {
        statusEl.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Initialiseer wanneer pagina actief wordt ───────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const items = document.querySelectorAll('.nav-item[data-page]');
    items.forEach(item => {
        item.addEventListener('click', () => {
            if (item.dataset.page === 'instellingen' && !orgs.length) {
                initInstellingen();
            }
        });
    });
});
