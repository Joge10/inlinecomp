/* InlineComp – Instellingen: organisaties & sponsors */

let orgs           = [];         // geladen organisatielijst
let actieveOrg     = null;       // huidig geselecteerde org
let orgLijstKaart  = null;       // actieve kaart in lijst
let actiefTab      = 'gegevens'; // actief tabblad
let _beheerLeesOnly = false;     // true als gebruiker geen schrijfrechten heeft voor beheer

// ── Initialisatie ──────────────────────────────────────────────────────────────

function initInstellingen() {
    _beheerLeesOnly = !magSchrijven('beheer');
    el('btn-nieuw-org').addEventListener('click', () => nieuweOrg());
    el('btn-org-opslaan').addEventListener('click', () => slaOrgOp());
    el('btn-org-verwijderen').addEventListener('click', () => verwijderOrg());
    el('btn-sponsor-add').addEventListener('click', () => voegSponsorRijToe());
    el('btn-tp-add')?.addEventListener('click', () => voegTransponderRijToe());

    // CSV transponder import
    el('btn-tp-csv')?.addEventListener('click', () => el('tp-csv-file')?.click());
    el('tp-csv-file')?.addEventListener('change', e => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            const tekst = reader.result;
            const sep = (tekst.split('\n')[0] || '').includes(';') ? ';' : ',';
            const regels = tekst.trim().split('\n');
            if (regels.length < 2) return;
            const header = regels[0].split(sep).map(h => h.trim().toLowerCase().replace(/['"]/g, ''));
            const body = el('org-tp-body');
            if (body) body.innerHTML = '';
            for (let i = 1; i < regels.length; i++) {
                const vals = regels[i].split(sep).map(v => v.trim().replace(/['"]/g, ''));
                const row = {};
                header.forEach((h, j) => { row[h] = vals[j] ?? ''; });
                voegTransponderRijToe({
                    intern_nummer:    row['intern_nummer'] ?? row['nr'] ?? row['nummer'] ?? '',
                    transponder_code: row['transponder_code'] ?? row['transponder'] ?? row['code'] ?? '',
                    eigendom:         row['eigendom'] ?? '',
                    toegewezen_snr:   row['toegewezen_snr'] ?? row['snr'] ?? row['startnummer'] ?? null,
                    toegewezen_naam:  row['toegewezen_naam'] ?? row['naam'] ?? '',
                    categorie:        row['categorie'] ?? row['cat'] ?? '',
                    betaald:          ['1','ja','yes','true'].includes((row['betaald'] ?? '').toLowerCase()),
                });
            }
            el('org-status').innerHTML = `<div class="status-msg info">${regels.length - 1} transponders geïmporteerd. Klik Opslaan om te bewaren.</div>`;
        };
        reader.readAsText(file);
        e.target.value = '';
    });

    el('org-logo-file').addEventListener('change', e => {
        if (e.target.files[0]) uploadLogo('org', actieveOrg?.id, e.target.files[0]);
    });

    // Alias-knoppen (null-safe: werkt ook als index.php nog niet gesynced is)
    function on(id, evt, fn) {
        const e = el(id);
        if (e) e.addEventListener(evt, fn);
    }

    on('btn-alias-add', 'click', () => {
        el('alias-toevoeg-rij').style.display = '';
        el('btn-alias-add').style.display     = 'none';
        el('alias-nieuw-naam').value          = '';
        el('alias-nieuw-naam').focus();
    });
    on('btn-alias-ann', 'click', () => {
        el('alias-toevoeg-rij').style.display = 'none';
        el('btn-alias-add').style.display     = '';
    });
    on('btn-alias-ok',    'click',   () => voegAliasToe());
    on('alias-nieuw-naam','keydown', e => {
        if (e.key === 'Enter')  voegAliasToe();
        if (e.key === 'Escape') el('btn-alias-ann')?.click();
    });

    // Samenvoeg-knoppen
    on('btn-samenvoeg',     'click', () => toonSamenvoegPanel());
    on('btn-samenvoeg-ann', 'click', () => {
        el('samenvoeg-panel').style.display = 'none';
    });
    on('btn-samenvoeg-ok',  'click', () => voerSamenvoegUit());

    // Tab-navigatie
    document.querySelectorAll('.org-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => schakelTab(btn.dataset.tab));
    });

    laadOrgs();
}

// ── Tab-logica ────────────────────────────────────────────────────────────────

function schakelTab(tab) {
    actiefTab = tab;
    document.querySelectorAll('.org-tab-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.org-tab-content').forEach(c =>
        c.style.display = c.id === `org-tab-${tab}` ? '' : 'none');

    if (tab === 'wedstrijden') laadOrgWedstrijden();
    if (tab === 'klassementen') laadOrgKlassementen();
}

async function laadOrgWedstrijden() {
    const lijst = el('org-wedstrijden-list');
    if (!actieveOrg || !lijst) return;

    lijst.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';

    // Wedstrijden in lokale DB ophalen
    let dbIds = new Set();
    try {
        const res = await fetch('api/organisaties.php?action=wedstrijden&id=' + encodeURIComponent(actieveOrg.id));
        const data = await res.json();
        dbIds = new Set((data ?? []).map(w => w.id));
    } catch { /* stil falen */ }

    // Filter allWedstrijden op naam/email/alias van deze org
    const orgNamen = new Set([
        actieveOrg.naam?.toLowerCase(),
        actieveOrg.email?.toLowerCase(),
        ...(actieveOrg.aliassen ?? []).map(a => a.naam?.toLowerCase()),
    ].filter(Boolean));

    const matches = (allWedstrijden ?? []).filter(w => {
        const email = (w.settings?.contact?.email ?? '').toLowerCase().trim();
        const naam  = (w.settings?.contact?.organizationName
                    ?? w.settings?.contact?.organization ?? '').toLowerCase().trim();
        return orgNamen.has(email) || orgNamen.has(naam);
    });

    if (!matches.length && !dbIds.size) {
        lijst.innerHTML = '<div class="status-msg info">Geen wedstrijden gevonden voor deze organisatie.</div>';
        return;
    }

    lijst.innerHTML = matches.map(w => {
        const inDb   = dbIds.has(w.id);
        const datum  = w.starts ? new Date(w.starts).toLocaleDateString('nl-NL', {day:'2-digit',month:'long',year:'numeric'}) : '—';
        return `<div class="beheer-wedstrijd-rij ${inDb ? 'in-db' : ''}">
            <div class="beheer-wedstrijd-info">
                <span class="beheer-wedstrijd-naam">${escHtml(w.name ?? w.title ?? w.id)}</span>
                <span class="beheer-wedstrijd-datum">${datum}</span>
                ${inDb ? '<span class="beheer-wedstrijd-badge">In database</span>' : '<span class="beheer-wedstrijd-badge badge-extern">inschrijven.schaatsen.nl</span>'}
            </div>
            ${inDb ? `<button class="btn-danger btn-sm beheer-comp-del" data-id="${escHtml(w.id)}" data-naam="${escHtml(w.name ?? w.id)}">Verwijderen</button>` : ''}
        </div>`;
    }).join('');

    lijst.querySelectorAll('.beheer-comp-del').forEach(btn => {
        btn.addEventListener('click', () => verwijderCompetitie(btn.dataset.id, btn.dataset.naam));
    });

    if (_beheerLeesOnly) pasSchrijfLockToe(lijst.closest('.org-tab-content') ?? lijst);
}

async function verwijderCompetitie(id, naam) {
    // Stap 1: keuze uitslag bewaren of ook verwijderen
    const uitslagKeuze = await new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <span class="modal-icon">⚠</span>
                    <span>Wedstrijd verwijderen</span>
                </div>
                <div class="modal-body">
                    <strong>${escHtml(naam)}</strong> verwijderen uit de database?<br><br>
                    Deelnemers, afstandsinstellingen en programma worden gewist.<br><br>
                    <strong>Wat moet er met vastgelegde uitslag- en klassementgegevens gebeuren?</strong>
                </div>
                <div class="modal-knoppen">
                    <button class="modal-btn modal-annuleer">Annuleren</button>
                    <button class="modal-btn modal-bewaar">Uitslag bewaren</button>
                    <button class="modal-btn modal-alles btn-danger">Ook uitslag verwijderen</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const sluit = v => { document.body.removeChild(overlay); resolve(v); };
        overlay.querySelector('.modal-annuleer').addEventListener('click', () => sluit(null));
        overlay.querySelector('.modal-bewaar' ).addEventListener('click', () => sluit('bewaar'));
        overlay.querySelector('.modal-alles'  ).addEventListener('click', () => sluit('alles'));
        overlay.addEventListener('click', e => { if (e.target === overlay) sluit(null); });
        overlay.querySelector('.modal-bewaar').focus();
    });

    if (!uitslagKeuze) return; // Annuleren

    try {
        const url = `api/import.php?id=${encodeURIComponent(id)}${uitslagKeuze === 'alles' ? '&uitslag=1' : ''}`;
        const res = await fetch(url, { method: 'DELETE' });
        const d   = await res.json();
        if (!res.ok) throw new Error(d.error ?? `HTTP ${res.status}`);
        if (typeof resetImportModule === 'function') resetImportModule(id);
        if (typeof laadWedstrijden === 'function') laadWedstrijden();
        laadOrgWedstrijden();
        laadOrgs();
    } catch(e) {
        toonBevestigDialog(e.message, 'Fout');
    }
}

function laadOrgKlassementen() {
    if (!actieveOrg) return;
    // Geef de actieve org door aan ranking.js en (her)initialiseer
    if (typeof setRankingOrgContext === 'function') {
        setRankingOrgContext(actieveOrg.id, actieveOrg.naam);
    }
}

// ── Organisaties laden ─────────────────────────────────────────────────────────

async function laadOrgs() {
    const lijst = el('org-list');
    try {
        const res  = await fetch('api/organisaties.php');
        const data = await res.json();
        if (data?.error) throw new Error(data.error);
        orgs = Array.isArray(data) ? data : [];
        renderOrgLijst();
        if (_beheerLeesOnly) {
            const btn = el('btn-nieuw-org');
            if (btn) { btn.disabled = true; btn.title = 'Geen schrijfrechten voor beheer'; }
            toonLeesAlleenBanner(el('page-instellingen'));
        }
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
    orgLijstKaart = null;   // reset — oude DOM-referentie vervalt na herrender
    orgs.forEach(o => {
        const kaart = document.createElement('div');
        const isActief = actieveOrg?.id === o.id;
        kaart.className = 'org-kaart' + (isActief ? ' active' : '');

        const metaDelen = [];
        if (o.comp_count > 0) metaDelen.push(`${o.comp_count} wedstrijd${o.comp_count !== 1 ? 'en' : ''}`);
        if (o.sponsor_count > 0) metaDelen.push(`${o.sponsor_count} sponsor${o.sponsor_count !== 1 ? 's' : ''}`);
        if (o.aliassen?.length) metaDelen.push(`${o.aliassen.length} alias${o.aliassen.length !== 1 ? 'sen' : ''}`);

        kaart.innerHTML =
            `<div class="org-kaart-naam">${escHtml(o.naam)}</div>` +
            (metaDelen.length ? `<div class="org-kaart-meta">${escHtml(metaDelen.join(' · '))}</div>` : '');
        kaart.addEventListener('click', () => selecteerOrg(kaart, o.id));
        lijst.appendChild(kaart);

        // Herverbind pointer zodat selecteerOrg het nieuwe element kent
        if (isActief) orgLijstKaart = kaart;
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
        // Als de actieve tab inhoud nodig heeft, laad die opnieuw
        if (actiefTab === 'wedstrijden')   laadOrgWedstrijden();
        if (actiefTab === 'klassementen')  laadOrgKlassementen();
    } catch(e) {
        el('org-status')?.innerHTML && (el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`);
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
    el('org-geen-selectie').style.display = 'none';
    el('org-tabs-wrap').style.display     = '';
    el('org-form-titel').textContent      = org ? org.naam : 'Nieuwe organisatie';
    // Nieuw → zet terug naar gegevens-tab
    if (!org) schakelTab('gegevens');
    el('org-naam').value               = org?.naam  ?? '';
    el('org-email').value              = org?.email ?? '';
    el('org-status').innerHTML         = '';
    const isBestaand = !!org;
    el('btn-org-verwijderen').style.display             = isBestaand ? '' : 'none';
    if (el('samenvoeg-panel'))    el('samenvoeg-panel').style.display    = 'none';
    if (el('alias-toevoeg-rij'))  el('alias-toevoeg-rij').style.display  = 'none';
    if (el('btn-samenvoeg'))      el('btn-samenvoeg').style.display      = isBestaand ? '' : 'none';
    if (el('btn-alias-add'))      el('btn-alias-add').style.display      = isBestaand ? '' : 'none';

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

    // Aliassen
    renderAliassen(org?.aliassen ?? []);

    // Sponsors
    renderSponsors(org?.sponsors ?? []);

    // Transponders
    renderTransponders(org?.transponders ?? []);
    const tpWrap = el('org-transponders-wrap');
    const tpCsv  = el('btn-tp-csv');
    if (tpWrap) tpWrap.style.display = isBestaand ? '' : 'none';
    if (tpCsv)  tpCsv.style.display  = isBestaand ? '' : 'none';

    // Lees-alleen modus: schrijf-elementen per tab-inhoud disablen (tabs zelf blijven klikbaar)
    if (_beheerLeesOnly) {
        document.querySelectorAll('.org-tab-content').forEach(tabEl => pasSchrijfLockToe(tabEl));
    }
}

// ── Aliassen ──────────────────────────────────────────────────────────────────

function renderAliassen(aliassen) {
    const wrap = el('org-aliassen-list');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (!aliassen.length) {
        wrap.innerHTML = '<span class="alias-leeg">Geen aliassen — alle wedstrijden verschijnen onder één naam.</span>';
        return;
    }
    aliassen.forEach(a => {
        const tag = document.createElement('span');
        tag.className = 'alias-tag';
        tag.innerHTML =
            `${escHtml(a.naam)}` +
            `<button class="btn-del alias-del" data-id="${escHtml(a.id)}" title="Verwijderen">&#128465;</button>`;
        tag.querySelector('.alias-del').addEventListener('click', () => verwijderAlias(a.id));
        wrap.appendChild(tag);
    });
}

async function voegAliasToe() {
    if (!actieveOrg) return;
    const naam = el('alias-nieuw-naam').value.trim();
    if (!naam) return;

    el('btn-alias-ok').disabled = true;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'alias_toevoegen', org_id: actieveOrg.id, naam }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        actieveOrg = data;
        renderAliassen(data.aliassen ?? []);
        el('alias-toevoeg-rij').style.display = 'none';
        el('btn-alias-add').style.display     = '';
        await laadOrgs();
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    } finally {
        el('btn-alias-ok').disabled = false;
    }
}

async function verwijderAlias(aliasId) {
    if (!actieveOrg) return;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'alias_verwijderen', id: aliasId, org_id: actieveOrg.id }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        actieveOrg = data;
        renderAliassen(data.aliassen ?? []);
        await laadOrgs();
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

// ── Samenvoegen ───────────────────────────────────────────────────────────────

function toonSamenvoegPanel() {
    if (!actieveOrg) return;
    const kies = el('samenvoeg-kies');
    kies.innerHTML = '<option value="">— kies organisatie —</option>';
    orgs.filter(o => o.id !== actieveOrg.id).forEach(o => {
        const opt = document.createElement('option');
        opt.value       = o.id;
        opt.textContent = o.naam + (o.aliassen?.length ? ` (${o.aliassen.join(', ')})` : '');
        kies.appendChild(opt);
    });
    el('samenvoeg-naar-naam').textContent = actieveOrg.naam;
    el('samenvoeg-panel').style.display   = '';
}

async function voerSamenvoegUit() {
    if (!actieveOrg) return;
    const vanId = el('samenvoeg-kies').value;
    if (!vanId) {
        el('org-status').innerHTML = '<div class="status-msg error">Kies een organisatie om samen te voegen.</div>';
        return;
    }
    const vanOrg = orgs.find(o => o.id === vanId);
    if (!await toonBevestigDialog(
        `"${vanOrg?.naam}" samenvoegen met "${actieveOrg.naam}"? ` +
        `"${vanOrg?.naam}" verdwijnt en wordt als alias opgeslagen. ` +
        `Wedstrijden en sponsors worden overgenomen. Dit kan niet ongedaan worden gemaakt.`,
        'Organisaties samenvoegen')) return;

    el('btn-samenvoeg-ok').disabled = true;
    try {
        const res = await fetch('api/organisaties.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:   'samenvoegen',
                van_id:   vanId,       // verdwijnt
                naar_id:  actieveOrg.id, // blijft
            }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        actieveOrg = data;
        el('samenvoeg-panel').style.display = 'none';
        vulOrgFormulier(data);
        await laadOrgs();
        el('org-status').innerHTML = '<div class="status-msg success">✓ Samengevoegd.</div>';
        setTimeout(() => { el('org-status').innerHTML = ''; }, 3000);
    } catch(e) {
        el('org-status').innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    } finally {
        el('btn-samenvoeg-ok').disabled = false;
    }
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
        <button class="btn-del btn-sponsor-del" title="Verwijderen">&#128465;</button>`;

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

// ── Transponders ─────────────────────────────────────────────────────────────

function renderTransponders(transponders) {
    const body = el('org-tp-body');
    if (!body) return;
    body.innerHTML = '';
    (transponders || []).forEach(t => voegTransponderRijToe(t));
}

function voegTransponderRijToe(tp = null) {
    const body = el('org-tp-body');
    if (!body) return;
    const tr = document.createElement('tr');
    tr.className = 'org-tp-rij';
    tr.innerHTML =
        `<td><input type="text" class="inp tp-inp tp-nr" value="${escHtml(tp?.intern_nummer ?? '')}" placeholder="#"></td>` +
        `<td><input type="text" class="inp tp-inp tp-code" value="${escHtml(tp?.transponder_code ?? '')}" placeholder="KS-..."></td>` +
        `<td><input type="text" class="inp tp-inp tp-eigendom" value="${escHtml(tp?.eigendom ?? '')}" placeholder="Org/Huur"></td>` +
        `<td><input type="number" class="inp tp-inp tp-snr" value="${tp?.toegewezen_snr ?? ''}" placeholder="—" min="0"></td>` +
        `<td><input type="text" class="inp tp-inp tp-naam" value="${escHtml(tp?.toegewezen_naam ?? '')}" placeholder="—"></td>` +
        `<td><input type="text" class="inp tp-inp tp-cat" value="${escHtml(tp?.categorie ?? '')}" placeholder="—"></td>` +
        `<td class="tp-td-betaald"><input type="checkbox" class="tp-betaald" ${tp?.betaald ? 'checked' : ''}></td>` +
        `<td><button class="btn-del tp-del" title="Verwijderen">&#128465;</button></td>`;
    tr.querySelector('.tp-del').addEventListener('click', () => tr.remove());
    body.appendChild(tr);
}

function verzamelTransponders() {
    return [...(el('org-tp-body')?.querySelectorAll('.org-tp-rij') ?? [])].map(tr => ({
        intern_nummer:    tr.querySelector('.tp-nr')?.value.trim() || null,
        transponder_code: tr.querySelector('.tp-code')?.value.trim() || null,
        eigendom:         tr.querySelector('.tp-eigendom')?.value.trim() || null,
        toegewezen_snr:   tr.querySelector('.tp-snr')?.value ? parseInt(tr.querySelector('.tp-snr').value) : null,
        toegewezen_naam:  tr.querySelector('.tp-naam')?.value.trim() || null,
        categorie:        tr.querySelector('.tp-cat')?.value.trim() || null,
        betaald:          tr.querySelector('.tp-betaald')?.checked ? 1 : 0,
    })).filter(t => t.intern_nummer && t.transponder_code);
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

    const transponders = verzamelTransponders();

    const body = {
        action:   'save',
        id:       actieveOrg?.id ?? null,
        naam,
        email:    el('org-email').value.trim() || null,
        sponsors,
        transponders,
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
    if (!await toonBevestigDialog(`Organisatie "${actieveOrg.naam}" verwijderen? Dit kan niet ongedaan worden gemaakt.`, 'Organisatie verwijderen')) return;

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
