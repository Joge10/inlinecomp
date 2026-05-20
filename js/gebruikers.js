/* InlineComp – gebruikersbeheer */

const ROL_LABELS = {
    owner:    'Owner',
    admin:    'Admin',
    importer: 'Importer',
    planner:  'Planner',
    timer:    'Timer',
    viewer:   'Viewer',
};
const ROL_VOLGORDE = ['owner','admin','importer','planner','timer','viewer'];

let gbGebruikers = [];
let gbActiefId   = null;

// ── Pagina tonen ──────────────────────────────────────────────────────────────

async function toonGebruikersPagina() {
    const container = el('gb-container');
    if (!container) return;

    if (!['owner','admin'].includes(currentUser.role)) {
        container.innerHTML = '<div class="status-msg info">Geen toegang tot gebruikersbeheer.</div>';
        return;
    }

    await herlaadGebruikers();
}

async function herlaadGebruikers() {
    const container = el('gb-container');
    try {
        const res = await fetch('api/gebruikers.php');
        if (!res.ok) return; // 401 wordt afgevangen door globale interceptor
        gbGebruikers = await res.json();
        renderGebruikers();
    } catch(e) {
        container.innerHTML = `<div class="status-msg error">⚠ ${e.message}</div>`;
    }
}

// ── Logboek ───────────────────────────────────────────────────────────────────

let gbLogboekOpen = false;

async function laadLogboek(filterValue = '') {
    const tbody   = el('gb-log-tbody');
    const statusEl = el('gb-log-status');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="gb-log-laden">Laden…</td></tr>';
    if (statusEl) statusEl.textContent = '';
    try {
        // filterValue: '' (alles), '__jury__' (alleen jury-app), '__org__'
        // (alleen organisator), of een gebruiker-ID (alleen die persoon).
        let qs = '';
        if (filterValue === '__jury__')        qs = '?type=jury';
        else if (filterValue === '__org__')    qs = '?type=organisator';
        else if (filterValue)                  qs = '?user_id=' + encodeURIComponent(filterValue);
        const url = 'api/logboek.php' + qs;
        const res  = await fetch(url);
        const logs = await res.json();
        if (!Array.isArray(logs)) throw new Error(logs.error ?? 'Fout');

        if (!logs.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="gb-log-laden">Geen vermeldingen.</td></tr>';
            return;
        }

        const ACTIE_BADGE = {
            login:                       '<span class="gb-log-badge gb-log-in">ingelogd</span>',
            logout:                      '<span class="gb-log-badge gb-log-out">uitgelogd</span>',
            login_mislukt:               '<span class="gb-log-badge gb-log-fout">mislukt</span>',
            // Jury-app: hergebruik bestaande kleuren (groen/rood/grijs) zodat
            // de badges meteen herkenbaar zijn met dezelfde semantiek.
            'jury-login':                '<span class="gb-log-badge gb-log-in">jury-login</span>',
            'jury-login-fail':           '<span class="gb-log-badge gb-log-fout">jury-login mislukt</span>',
            'jury-login-fail-noaccess':  '<span class="gb-log-badge gb-log-fout">jury-login geweigerd</span>',
            'jury-logout':               '<span class="gb-log-badge gb-log-out">jury-uitgelogd</span>',
        };
        // Voor jury-rol-* (jury-rol-area_of_call, jury-rol-starter, ...) een
        // lichtblauwe badge met de rol-naam zonder prefix.
        function badgeVoorActie(actie) {
            if (ACTIE_BADGE[actie]) return ACTIE_BADGE[actie];
            if (typeof actie === 'string' && actie.startsWith('jury-rol-')) {
                const rol = actie.slice('jury-rol-'.length);
                return `<span class="gb-log-badge gb-log-jury-rol">rol: ${escHtml(rol)}</span>`;
            }
            return `<span class="gb-log-badge">${escHtml(actie)}</span>`;
        }

        tbody.innerHTML = logs.map(r => {
            const dt  = new Date(r.tijdstip.replace(' ', 'T') + 'Z');
            const ts  = dt.toLocaleString('nl-NL', {
                day:'2-digit', month:'2-digit', year:'numeric',
                hour:'2-digit', minute:'2-digit', second:'2-digit'
            });
            const badge   = badgeVoorActie(r.actie);
            const browser = [r.browser, r.os].filter(Boolean).join(' / ');
            const locatie = r.land
                ? escHtml(r.land) + (r.stad ? `<span class="gb-log-stad">, ${escHtml(r.stad)}</span>` : '')
                : '—';
            return `<tr class="${r.actie === 'login_mislukt' ? 'gb-log-rij-fout' : ''}">
                <td class="gb-log-ts">${ts}</td>
                <td>${escHtml(r.naam)}<span class="gb-username"> @${escHtml(r.username)}</span></td>
                <td>${badge}</td>
                <td class="gb-log-ip">${escHtml(r.ip_adres)}</td>
                <td class="gb-log-loc">${locatie}</td>
                <td class="gb-log-br">${escHtml(browser)}</td>
            </tr>`;
        }).join('');
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="6" class="status-msg error">⚠ ${escHtml(e.message)}</td></tr>`;
    }
}

async function opschonenLogboek(dagen) {
    const statusEl = el('gb-log-status');
    try {
        const res  = await fetch('api/logboek.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'opschonen', dagen }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error ?? 'Fout');
        if (statusEl) statusEl.textContent = `${data.verwijderd} vermelding(en) verwijderd.`;
        laadLogboek(el('gb-log-filter')?.value ?? '');
    } catch(e) {
        if (statusEl) statusEl.textContent = `Fout: ${e.message}`;
    }
}

function renderLogboekSectie() {
    const gebruikerOpties = gbGebruikers.map(u =>
        `<option value="${u.id}">${escHtml(u.naam)} (@${escHtml(u.username)})</option>`
    ).join('');

    return `
    <div class="gb-logboek-sectie" id="gb-logboek-sectie">
        <div class="gb-kop gb-logboek-kop">
            <div class="section-title">Login-logboek</div>
            <div class="gb-log-acties">
                <select id="gb-log-filter" class="inp gb-log-filter-sel">
                    <option value="">— Alles —</option>
                    <option value="__jury__">⚖ Alleen jury-app</option>
                    <option value="__org__">👤 Alleen organisator-logins</option>
                    <option disabled>──────────────</option>
                    ${gebruikerOpties}
                </select>
                <button class="btn-secondary" id="gb-log-vernieuwen">↻ Vernieuwen</button>
                <button class="btn-secondary" id="gb-log-opschonen">🗑 Opschonen…</button>
            </div>
        </div>
        <div id="gb-log-status" class="gb-log-status"></div>
        <table class="gb-tabel gb-log-tabel">
            <thead><tr>
                <th>Tijdstip</th>
                <th>Gebruiker</th>
                <th>Actie</th>
                <th>IP-adres</th>
                <th>Locatie</th>
                <th>Browser / OS</th>
            </tr></thead>
            <tbody id="gb-log-tbody">
                <tr><td colspan="6" class="gb-log-laden">Laden…</td></tr>
            </tbody>
        </table>
    </div>`;
}

function bindLogboekEvents() {
    el('gb-log-filter')?.addEventListener('change', () =>
        laadLogboek(el('gb-log-filter').value));

    el('gb-log-vernieuwen')?.addEventListener('click', () =>
        laadLogboek(el('gb-log-filter')?.value ?? ''));

    el('gb-log-opschonen')?.addEventListener('click', () => {
        const dagen = prompt('Verwijder vermeldingen ouder dan hoeveel dagen?', '30');
        if (dagen === null) return;
        const d = parseInt(dagen);
        if (!d || d < 1) { toonBevestigDialog('Voer een geldig aantal dagen in.', 'Logboek'); return; }
        toonBevestigDialog(`Alle vermeldingen ouder dan ${d} dagen verwijderen?`, 'Logboek opschonen')
            .then(ok => { if (ok) opschonenLogboek(d); });
    });
}

// Render alle drie de Systeem-tab inhouden — gebruikers-tabel in #gb-container,
// bezoekers-stats in #gb-bezoekers-container, logboek in #gb-logboek-container.
// Elk staat in een eigen sub-tab van de Systeem-pagina.
function renderGebruikers() {
    renderGebruikersTabel();
    renderBezoekersBlok();
    renderLogboekBlok();
}

function renderGebruikersTabel() {
    const container = el('gb-container');

    const rijen = gbGebruikers.map(u => {
        const ikZelf    = u.id === currentUser.id;
        const isOwner   = u.role === 'owner';
        const magWijzig = currentUser.role === 'owner'
                       || (currentUser.role === 'admin' && !['owner','admin'].includes(u.role) && !ikZelf);
        const magWW     = ikZelf || magWijzig;
        const magDel    = !ikZelf && !isOwner
                       && (currentUser.role === 'owner' || (currentUser.role === 'admin' && u.role !== 'admin'));
        const actief    = u.actief == 1;

        return `<tr class="gb-rij${actief ? '' : ' gb-inactief'}" data-id="${u.id}">
            <td class="gb-naam">${escHtml(u.naam)}<span class="gb-username">@${escHtml(u.username)}</span></td>
            <td><span class="gb-rol-badge gb-rol-${u.role}">${ROL_LABELS[u.role] ?? u.role}</span></td>
            <td>${escHtml(u.email ?? '—')}</td>
            <td class="gb-acties">
                ${magWijzig
                    ? `<button class="btn-secondary gb-btn-edit" data-id="${u.id}" title="Bewerken">&#9998;</button>`
                    : `<span class="gb-btn-leeg"></span>`}
                ${magWW
                    ? `<button class="btn-secondary gb-btn-ww" data-id="${u.id}" title="Wachtwoord wijzigen">&#128273;</button>`
                    : `<span class="gb-btn-leeg"></span>`}
                ${magDel && !isOwner && actief
                    ? `<button class="btn-secondary gb-btn-toggle" data-id="${u.id}" title="Account is actief — klik om te deactiveren">&#128275;</button>`
                    : magDel && !actief
                        ? `<button class="btn-secondary gb-btn-toggle gb-btn-toggle-actief" data-id="${u.id}" title="Account is gedeactiveerd — klik om te activeren">&#128274;</button>`
                        : `<span class="gb-btn-leeg"></span>`}
                ${magDel
                    ? `<button class="btn-del gb-btn-del" data-id="${u.id}" title="Verwijderen">&#128465;</button>`
                    : `<span class="gb-btn-leeg"></span>`}
            </td>
        </tr>`;
    }).join('');

    container.innerHTML = `
        <div class="gb-kop">
            <div class="section-title">Gebruikers</div>
            <button class="btn-primary" id="gb-btn-nieuw">+ Nieuwe gebruiker</button>
        </div>
        <table class="gb-tabel">
            <thead>
                <tr>
                    <th>Naam / gebruikersnaam</th>
                    <th>Rol</th>
                    <th>E-mail</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>${rijen}</tbody>
        </table>
        <div id="gb-form-wrap" style="display:none"></div>`;

    // Events
    el('gb-btn-nieuw').addEventListener('click', () => openGbForm(null));

    container.querySelectorAll('.gb-btn-edit').forEach(btn =>
        btn.addEventListener('click', () => openGbForm(parseInt(btn.dataset.id))));

    container.querySelectorAll('.gb-btn-ww').forEach(btn =>
        btn.addEventListener('click', () => openWwForm(parseInt(btn.dataset.id))));

    container.querySelectorAll('.gb-btn-toggle').forEach(btn =>
        btn.addEventListener('click', () => toggleActief(parseInt(btn.dataset.id))));

    container.querySelectorAll('.gb-btn-del').forEach(btn =>
        btn.addEventListener('click', () => verwijderGebruiker(parseInt(btn.dataset.id))));
}

function renderBezoekersBlok() {
    const c = el('gb-bezoekers-container');
    if (!c) return;
    c.innerHTML = `
        <!-- Publieke-pagina statistieken -->
        <div class="section-title">Publieke pagina — bezoekers</div>
        <div class="gb-stats" id="gb-stats">
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-actief">—</div>
                <div class="gb-stat-label">Nu actief <span class="gb-stat-hint">(laatste 5 min)</span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-vandaag">—</div>
                <div class="gb-stat-label">Unieke bezoekers vandaag <span class="gb-stat-hint" id="gb-stat-vandaag-hint" style="display:none"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-uniek">—</div>
                <div class="gb-stat-label">Unieke bezoekers ooit <span class="gb-stat-hint" id="gb-stat-uniek-hint" style="display:none"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-hits">—</div>
                <div class="gb-stat-label">Totaal page-views</div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-peak-today">—</div>
                <div class="gb-stat-label">Piek gelijktijdig <span class="gb-stat-hint">(vandaag)</span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-peak">—</div>
                <div class="gb-stat-label">Piek gelijktijdig <span class="gb-stat-hint" id="gb-stat-peak-at">(ooit)</span></div>
            </div>
        </div>
        <div class="gb-stat-voetnoot" id="gb-stat-voet">Laatst bijgewerkt: —</div>

        <!-- Coach-pagina statistieken -->
        <div class="section-title" style="margin-top:1.5rem">Coach-pagina — bezoekers</div>
        <div class="gb-stats" id="gb-stats-coach">
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-actief">—</div>
                <div class="gb-stat-label">Nu actief <span class="gb-stat-hint">(laatste 5 min)</span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-vandaag">—</div>
                <div class="gb-stat-label">Unieke bezoekers vandaag <span class="gb-stat-hint" id="gb-stat-coach-vandaag-hint" style="display:none"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-uniek">—</div>
                <div class="gb-stat-label">Unieke bezoekers ooit <span class="gb-stat-hint" id="gb-stat-coach-uniek-hint" style="display:none"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-hits">—</div>
                <div class="gb-stat-label">Totaal page-views</div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-peak-today">—</div>
                <div class="gb-stat-label">Piek gelijktijdig <span class="gb-stat-hint">(vandaag)</span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-peak">—</div>
                <div class="gb-stat-label">Piek gelijktijdig <span class="gb-stat-hint" id="gb-stat-coach-peak-at">(ooit)</span></div>
            </div>
        </div>
        <div class="gb-stat-voetnoot" id="gb-stat-coach-voet">Laatst bijgewerkt: —</div>`;

    startPublicStatsRefresh();
}

function renderLogboekBlok() {
    const c = el('gb-logboek-container');
    if (!c) return;
    c.innerHTML = renderLogboekSectie();
    bindLogboekEvents();
    laadLogboek();
}

// ── Publieke-pagina bezoekersstatistiek ───────────────────────────────────
let _gbStatsTimer = null;

async function _laadStatsBlok(endpoint, idPrefix, voetId) {
    try {
        const res = await fetch(endpoint);
        if (!res.ok) return;
        const data = await res.json();
        const set = (id, val) => { const e = el(id); if (e) e.textContent = val; };
        set(`${idPrefix}-actief`,      data.actief         ?? 0);
        set(`${idPrefix}-hits`,        data.totaal_hits    ?? 0);
        set(`${idPrefix}-peak-today`,  data.peak_today     ?? 0);
        set(`${idPrefix}-peak`,        data.peak_all_time  ?? 0);

        // "Echte" (gefilterde) telling als primaire waarde tonen, en als
        // de ruwe telling significant hoger is een hint met "(X incl. bot)"
        // zodat duidelijk is dat bots/previews niet meegerekend zijn.
        const setMet = (id, hintId, ruw, echt) => {
            const v = echt ?? ruw ?? 0;
            set(id, v);
            const h = el(hintId);
            if (h) {
                if (ruw != null && echt != null && ruw > echt) {
                    h.textContent = `(${ruw} incl. bot/preview)`;
                    h.style.display = '';
                } else {
                    h.textContent = '';
                    h.style.display = 'none';
                }
            }
        };
        setMet(`${idPrefix}-vandaag`, `${idPrefix}-vandaag-hint`,
            data.actief_vandaag, data.actief_vandaag_echt);
        setMet(`${idPrefix}-uniek`,   `${idPrefix}-uniek-hint`,
            data.totaal_uniek, data.totaal_uniek_echt);
        // Timestamp van de all-time-piek in de hint van die card plaatsen
        const hintEl = el(`${idPrefix}-peak-at`);
        if (hintEl) {
            if (data.peak_at) {
                const d = new Date(String(data.peak_at).replace(' ', 'T'));
                const dt = d.toLocaleString('nl-NL', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
                hintEl.textContent = `(ooit, ${dt})`;
            } else {
                hintEl.textContent = '(ooit)';
            }
        }
        const v = el(voetId);
        if (v) {
            const t = new Date().toLocaleTimeString('nl-NL', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
            v.textContent = 'Laatst bijgewerkt: ' + t;
        }
    } catch { /* stil */ }
}

async function laadPublicStats() {
    await _laadStatsBlok('api/public_stats.php', 'gb-stat',       'gb-stat-voet');
    await _laadStatsBlok('api/coach_stats.php',  'gb-stat-coach', 'gb-stat-coach-voet');
}

function startPublicStatsRefresh() {
    if (_gbStatsTimer) clearInterval(_gbStatsTimer);
    laadPublicStats();
    // Elke 30 sec refreshen zodat "nu actief" up-to-date blijft
    _gbStatsTimer = setInterval(laadPublicStats, 30_000);
}

// Stop het interval als de gebruiker weg navigeert
document.addEventListener('visibilitychange', () => {
    if (document.hidden && _gbStatsTimer) {
        clearInterval(_gbStatsTimer);
        _gbStatsTimer = null;
    } else if (!document.hidden && !_gbStatsTimer && el('gb-stats')) {
        startPublicStatsRefresh();
    }
});

// ── Gebruiker bewerken / aanmaken ─────────────────────────────────────────────

function openGbForm(id) {
    const wrap = el('gb-form-wrap');
    const user = id ? gbGebruikers.find(u => Number(u.id) === id) : null;

    const geldigeRollen = currentUser.role === 'owner'
        ? ROL_VOLGORDE
        : ROL_VOLGORDE.filter(r => !['owner','admin'].includes(r));

    const rolOpties = geldigeRollen.map(r =>
        `<option value="${r}"${user?.role === r ? ' selected' : ''}>${ROL_LABELS[r]}</option>`
    ).join('');

    wrap.style.display = '';
    wrap.innerHTML = `
        <div class="gb-form">
            <div class="gb-form-titel">${id ? 'Gebruiker bewerken' : 'Nieuwe gebruiker'}</div>
            <div class="mf-rij mf-2col">
                <label class="mf-lbl"><span>Naam <span class="vereist">*</span></span>
                    <input type="text" id="gbf-naam" class="inp" value="${escHtml(user?.naam ?? '')}" required>
                </label>
                <label class="mf-lbl"><span>Gebruikersnaam <span class="vereist">*</span></span>
                    <input type="text" id="gbf-username" class="inp" value="${escHtml(user?.username ?? '')}" required>
                </label>
            </div>
            <div class="mf-rij mf-2col">
                <label class="mf-lbl"><span>E-mail</span>
                    <input type="email" id="gbf-email" class="inp" value="${escHtml(user?.email ?? '')}">
                </label>
                <label class="mf-lbl"><span>Rol <span class="vereist">*</span></span>
                    <select id="gbf-rol" class="inp">${rolOpties}</select>
                </label>
            </div>
            ${!id ? `
            <div class="mf-rij mf-2col">
                <label class="mf-lbl"><span>Wachtwoord <span class="vereist">*</span></span>
                    <input type="password" id="gbf-pw" class="inp" required minlength="8">
                </label>
                <label class="mf-lbl"><span>Herhalen <span class="vereist">*</span></span>
                    <input type="password" id="gbf-pw2" class="inp" required>
                </label>
            </div>` : ''}
            <div id="gbf-fout" class="status-msg error" style="display:none;margin:.5rem 0"></div>
            <div class="gb-form-acties">
                <button class="btn-secondary" id="gbf-annuleer">Annuleren</button>
                <button class="btn-primary"   id="gbf-opslaan">Opslaan</button>
            </div>
        </div>`;

    el('gbf-annuleer').addEventListener('click', () => { wrap.style.display = 'none'; });
    el('gbf-opslaan') .addEventListener('click', () => slaGebruikerOp(id));
    wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function slaGebruikerOp(id) {
    const naam     = el('gbf-naam').value.trim();
    const username = el('gbf-username').value.trim();
    const email    = el('gbf-email').value.trim() || null;
    const rol      = el('gbf-rol').value;
    const pw       = el('gbf-pw')?.value ?? null;
    const pw2      = el('gbf-pw2')?.value ?? null;
    const foutEl   = el('gbf-fout');

    foutEl.style.display = 'none';

    if (!naam || !username) { toonGbFout('Naam en gebruikersnaam zijn verplicht.'); return; }
    if (!id && (!pw || pw.length < 8)) { toonGbFout('Wachtwoord min. 8 tekens.'); return; }
    if (!id && pw !== pw2) { toonGbFout('Wachtwoorden komen niet overeen.'); return; }

    const payload = { action: 'save', naam, username, email, role: rol, ...(id ? { id } : { password: pw }) };
    const res  = await fetch('api/gebruikers.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) { toonGbFout(data.error ?? 'Fout bij opslaan.'); return; }

    el('gb-form-wrap').style.display = 'none';
    await herlaadGebruikers();
}

function toonGbFout(tekst) {
    const e = el('gbf-fout');
    if (e) { e.textContent = tekst; e.style.display = ''; }
}

// ── Wachtwoord wijzigen ───────────────────────────────────────────────────────

function openWwForm(id) {
    const wrap = el('gb-form-wrap');
    const user = gbGebruikers.find(u => u.id === id);
    wrap.style.display = '';
    wrap.innerHTML = `
        <div class="gb-form">
            <div class="gb-form-titel">Wachtwoord wijzigen — ${escHtml(user?.naam ?? '')}</div>
            <div class="mf-rij mf-2col">
                <label class="mf-lbl"><span>Nieuw wachtwoord <span class="vereist">*</span></span>
                    <input type="password" id="gbw-pw" class="inp" required minlength="8">
                </label>
                <label class="mf-lbl"><span>Herhalen <span class="vereist">*</span></span>
                    <input type="password" id="gbw-pw2" class="inp" required>
                </label>
            </div>
            <div id="gbw-fout" class="status-msg error" style="display:none;margin:.5rem 0"></div>
            <div class="gb-form-acties">
                <button class="btn-secondary" id="gbw-annuleer">Annuleren</button>
                <button class="btn-primary"   id="gbw-opslaan">Opslaan</button>
            </div>
        </div>`;

    el('gbw-annuleer').addEventListener('click', () => { wrap.style.display = 'none'; });
    el('gbw-opslaan') .addEventListener('click', async () => {
        const pw  = el('gbw-pw').value;
        const pw2 = el('gbw-pw2').value;
        if (pw.length < 8) { toonWwFout('Min. 8 tekens.'); return; }
        if (pw !== pw2)    { toonWwFout('Wachtwoorden komen niet overeen.'); return; }
        const res  = await fetch('api/gebruikers.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_password', id, password: pw }),
        });
        const data = await res.json();
        if (!res.ok) { toonWwFout(data.error ?? 'Fout.'); return; }
        wrap.style.display = 'none';
    });
}

function toonWwFout(tekst) {
    const e = el('gbw-fout');
    if (e) { e.textContent = tekst; e.style.display = ''; }
}

// ── Acties ────────────────────────────────────────────────────────────────────

async function toggleActief(id) {
    const res  = await fetch('api/gebruikers.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'toggle_actief', id }),
    });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error ?? 'Fout', 'Fout'); return; }
    await herlaadGebruikers();
}

async function verwijderGebruiker(id) {
    const user = gbGebruikers.find(u => u.id === id);
    if (!await toonBevestigDialog(`Gebruiker "${user?.naam}" verwijderen?`, 'Gebruiker verwijderen')) return;
    const res  = await fetch('api/gebruikers.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id }),
    });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error ?? 'Fout', 'Fout'); return; }
    await herlaadGebruikers();
}
