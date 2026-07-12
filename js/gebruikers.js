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

let gbOrgs = [];  // alle organisaties die de huidige user mag tellen

async function herlaadGebruikers() {
    const container = el('gb-container');
    try {
        // Parallel: gebruikers + organisaties (voor de scope-multi-select).
        const [resU, resO] = await Promise.all([
            fetch('api/gebruikers.php'),
            fetch('api/gebruikers.php?action=orgs_list'),
        ]);
        if (!resU.ok) return; // 401 wordt afgevangen door globale interceptor
        gbGebruikers = await resU.json();
        gbOrgs       = resO.ok ? await resO.json() : [];
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

    el('gb-log-opschonen')?.addEventListener('click', async () => {
        // Step 1: vraag aantal dagen via standaard modal-input (geen browser-prompt)
        const dagen = await toonInputDialog({
            titel:        'Logboek opschonen',
            bericht:      'Verwijder vermeldingen ouder dan hoeveel dagen?',
            inputType:    'number',
            defaultValue: '30',
            min:          1,
            max:          3650,
            labelOk:      'Volgende',
        });
        if (dagen === null) return;   // geannuleerd
        const d = parseInt(dagen, 10);
        if (!d || d < 1) {
            toonBevestigDialog('Voer een geldig aantal dagen in.', 'Logboek', 'OK', '');
            return;
        }
        // Step 2: bevestigen dat operator dit echt wil
        const ok = await toonBevestigDialog(
            `Alle vermeldingen ouder dan ${d} dagen verwijderen?`,
            'Logboek opschonen'
        );
        if (ok) opschonenLogboek(d);
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

        // Org-scope-indicator. Owner-rol heeft altijd "alle" scope, ongeacht
        // junction-rijen — hier expliciet zo tonen. Andere rollen: 0 koppelingen
        // = "alle" (backward-compat); ≥1 = "N org(s)" met tooltip-lijst.
        const orgIds = Array.isArray(u.organisatie_ids) ? u.organisatie_ids : [];
        let orgBadge;
        if (u.role === 'owner') {
            orgBadge = `<span class="gb-org-badge gb-org-alle" title="Owner ziet alle wedstrijden">alle</span>`;
        } else if (orgIds.length === 0) {
            orgBadge = `<span class="gb-org-badge gb-org-alle" title="Geen scope ingesteld → ziet alle wedstrijden">alle</span>`;
        } else {
            const namen = orgIds.map(id => {
                const o = gbOrgs.find(x => x.id === id);
                return o ? o.naam : '(onbekend)';
            }).join(', ');
            orgBadge = `<span class="gb-org-badge gb-org-scoped" title="${escHtml(namen)}">${orgIds.length} org${orgIds.length === 1 ? '' : "'s"}</span>`;
        }

        return `<tr class="gb-rij${actief ? '' : ' gb-inactief'}" data-id="${u.id}">
            <td class="gb-naam">${escHtml(u.naam)}<span class="gb-username">@${escHtml(u.username)}</span></td>
            <td><span class="gb-rol-badge gb-rol-${u.role}">${ROL_LABELS[u.role] ?? u.role}</span></td>
            <td>${orgBadge}</td>
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
                    <th title="Welke organisaties ziet deze user? 'alle' = ongelimiteerd (owner of geen scope ingesteld)">Scope</th>
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
                <div class="gb-stat-label">Unieke bezoekers vandaag <span class="gb-stat-hint gb-stat-hint--toggle" id="gb-stat-vandaag-hint"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-uniek">—</div>
                <div class="gb-stat-label">Unieke bezoekers ooit <span class="gb-stat-hint gb-stat-hint--toggle" id="gb-stat-uniek-hint"></span></div>
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
        <div class="gb-stat-hourly" id="gb-stat-hourly"></div>
        <div class="gb-stat-weekly" id="gb-stat-weekly"></div>
        <div class="gb-stat-voetnoot" id="gb-stat-voet">Laatst bijgewerkt: —</div>

        <!-- Coach-pagina statistieken -->
        <div class="section-title gb-stats-blok-volgend">Coach-pagina — bezoekers</div>
        <div class="gb-stats" id="gb-stats-coach">
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-actief">—</div>
                <div class="gb-stat-label">Nu actief <span class="gb-stat-hint">(laatste 5 min)</span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-vandaag">—</div>
                <div class="gb-stat-label">Unieke bezoekers vandaag <span class="gb-stat-hint gb-stat-hint--toggle" id="gb-stat-coach-vandaag-hint"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-coach-uniek">—</div>
                <div class="gb-stat-label">Unieke bezoekers ooit <span class="gb-stat-hint gb-stat-hint--toggle" id="gb-stat-coach-uniek-hint"></span></div>
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
        <div class="gb-stat-hourly" id="gb-stat-coach-hourly"></div>
        <div class="gb-stat-weekly" id="gb-stat-coach-weekly"></div>
        <div class="gb-stat-voetnoot" id="gb-stat-coach-voet">Laatst bijgewerkt: —</div>

        <!-- Check-pagina statistieken -->
        <div class="section-title gb-stats-blok-volgend">Check-pagina — bezoekers</div>
        <div class="gb-stats" id="gb-stats-check">
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-check-actief">—</div>
                <div class="gb-stat-label">Nu actief <span class="gb-stat-hint">(laatste 5 min)</span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-check-vandaag">—</div>
                <div class="gb-stat-label">Unieke bezoekers vandaag <span class="gb-stat-hint gb-stat-hint--toggle" id="gb-stat-check-vandaag-hint"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-check-uniek">—</div>
                <div class="gb-stat-label">Unieke bezoekers ooit <span class="gb-stat-hint gb-stat-hint--toggle" id="gb-stat-check-uniek-hint"></span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-check-hits">—</div>
                <div class="gb-stat-label">Totaal page-views</div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-check-peak-today">—</div>
                <div class="gb-stat-label">Piek gelijktijdig <span class="gb-stat-hint">(vandaag)</span></div>
            </div>
            <div class="gb-stat-kaart">
                <div class="gb-stat-waarde" id="gb-stat-check-peak">—</div>
                <div class="gb-stat-label">Piek gelijktijdig <span class="gb-stat-hint" id="gb-stat-check-peak-at">(ooit)</span></div>
            </div>
        </div>
        <div class="gb-stat-hourly" id="gb-stat-check-hourly"></div>
        <div class="gb-stat-weekly" id="gb-stat-check-weekly"></div>
        <div class="gb-stat-voetnoot" id="gb-stat-check-voet">Laatst bijgewerkt: —</div>`;

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

// Render inline SVG-staafgrafiek voor gelijktijdig-actief per uur vandaag.
// hourly: array van 24 getallen. Y-as met max/mid/0, X-as met uur-labels.
function _renderHourlyChart(containerId, hourly) {
    const c = el(containerId);
    if (!c || !Array.isArray(hourly) || hourly.length !== 24) return;
    const totaal = hourly.reduce((s, n) => s + (Number(n) || 0), 0);
    if (totaal === 0) {
        c.innerHTML = '<div class="gb-stat-hourly-leeg">Nog geen bezoekers vandaag</div>';
        return;
    }
    const max = Math.max(...hourly, 1);
    const mid = Math.round(max / 2);
    const w   = 100 / 24;
    // Bars + horizontale gridlijnen op 0/50/100%
    const grid = [0, 50, 100].map(y =>
        `<line x1="0" x2="100" y1="${y}" y2="${y}" stroke="#e0e6ee"
               stroke-width="0.5" vector-effect="non-scaling-stroke"/>`
    ).join('');
    const bars = hourly.map((n, i) => {
        const h = (n / max) * 100;
        const x = i * w;
        return `<rect x="${x + 0.15}" y="${100 - h}" width="${w - 0.3}" height="${h}"
                      fill="var(--blauw)" opacity="${n > 0 ? 0.85 : 0.15}">
                    <title>${String(i).padStart(2,'0')}:00 — ${n} bezoekers</title>
                </rect>`;
    }).join('');
    // X-as labels in HTML zodat de font niet uitgerekt wordt door SVG viewBox
    const xTicks = [0, 3, 6, 9, 12, 15, 18, 21].map(h => {
        const left = h * w + w/2;
        return `<span class="gb-stat-xtick" style="--x:${left}%">${String(h).padStart(2,'0')}</span>`;
    }).join('');
    c.innerHTML = `<div class="gb-stat-hourly-titel">Gelijktijdig actief per uur (vandaag)</div>
        <div class="gb-stat-hourly-body">
            <div class="gb-stat-hourly-y">
                <span>${max}</span>
                <span>${mid}</span>
                <span>0</span>
            </div>
            <div class="gb-stat-hourly-plot">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="gb-stat-hourly-svg">
                    ${grid}${bars}
                </svg>
                <div class="gb-stat-xas">${xTicks}</div>
            </div>
        </div>`;
}

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
                const toon = ruw != null && echt != null && ruw > echt;
                h.textContent = toon ? `(${ruw} incl. bot/preview)` : '';
                h.classList.toggle('is-zichtbaar', toon);
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
        _renderHourlyChart(`${idPrefix}-hourly`, data.hourly);
        _renderWeeklyChart(`${idPrefix}-weekly`, data.weekly);
    } catch { /* stil */ }
}

// Weekgrafiek: laatste 52 weken. weekly = array van { label, n }.
function _renderWeeklyChart(containerId, weekly) {
    const c = el(containerId);
    if (!c || !Array.isArray(weekly) || !weekly.length) return;
    const totaal = weekly.reduce((s, w) => s + (Number(w.n) || 0), 0);
    if (totaal === 0) {
        c.innerHTML = '<div class="gb-stat-hourly-leeg">Nog geen bezoekers in het afgelopen jaar</div>';
        return;
    }
    const N = weekly.length;
    const max = Math.max(...weekly.map(w => Number(w.n) || 0), 1);
    const mid = Math.round(max / 2);
    const w   = 100 / N;
    const grid = [0, 50, 100].map(y =>
        `<line x1="0" x2="100" y1="${y}" y2="${y}" stroke="#e0e6ee"
               stroke-width="0.5" vector-effect="non-scaling-stroke"/>`
    ).join('');
    const bars = weekly.map((wk, i) => {
        const n = Number(wk.n) || 0;
        const h = (n / max) * 100;
        const x = i * w;
        return `<rect x="${x + 0.1}" y="${100 - h}" width="${w - 0.2}" height="${h}"
                      fill="var(--blauw)" opacity="${n > 0 ? 0.85 : 0.15}">
                    <title>${wk.label} — ${n} bezoekers</title>
                </rect>`;
    }).join('');
    // Tick-labels: 5 stops verdeeld over de 52 weken (bijv. -12m, -9m, -6m, -3m, nu)
    const tickIdx = [0, Math.floor(N*0.25), Math.floor(N*0.5), Math.floor(N*0.75), N-1];
    const xTicks = tickIdx.map(i => {
        const label = weekly[i]?.label ?? '';
        const left = i * w + w/2;
        return `<span class="gb-stat-xtick" style="--x:${left}%">${label}</span>`;
    }).join('');
    c.innerHTML = `<div class="gb-stat-hourly-titel">Bezoekers per week (laatste 52 weken)</div>
        <div class="gb-stat-hourly-body">
            <div class="gb-stat-hourly-y">
                <span>${max}</span>
                <span>${mid}</span>
                <span>0</span>
            </div>
            <div class="gb-stat-hourly-plot">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="gb-stat-hourly-svg">
                    ${grid}${bars}
                </svg>
                <div class="gb-stat-xas">${xTicks}</div>
            </div>
        </div>`;
}

async function laadPublicStats() {
    await _laadStatsBlok('api/public_stats.php', 'gb-stat',       'gb-stat-voet');
    await _laadStatsBlok('api/coach_stats.php',  'gb-stat-coach', 'gb-stat-coach-voet');
    await _laadStatsBlok('api/check_stats.php',  'gb-stat-check', 'gb-stat-check-voet');
}

function startPublicStatsRefresh() {
    if (_gbStatsTimer) clearInterval(_gbStatsTimer);
    laadPublicStats();
    // 6 minuten refresh-interval. Was 30s — die snelheid heeft geen praktische
    // waarde (bezoekers-aantal is geen realtime-kritisch nummer) en genereerde
    // anders ~120 extra requests per uur per admin met de tab open. Combineert
    // met visibilitychange-listener hieronder die polling al pauzeert bij
    // verborgen tab. iFastNet EP-budget bedankt je.
    _gbStatsTimer = setInterval(laadPublicStats, 360_000);
}

// Stop de polling expliciet — geroepen door switchSysteemTab() zodra de
// gebruiker een ANDERE Systeem-tab kiest (Helpers/Rijders/etc). Anders
// blijft de timer doortikken en zie je elke 30s public_stats + coach_stats
// requests in de network-tab terwijl je daar niets mee doet.
function stopPublicStatsRefresh() {
    if (_gbStatsTimer) {
        clearInterval(_gbStatsTimer);
        _gbStatsTimer = null;
    }
}

// Stop het interval als de gebruiker weg navigeert (browser-tab in achtergrond)
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopPublicStatsRefresh();
    } else if (!_gbStatsTimer && el('gb-stats')
               && el('sys-tab-bezoekers')?.style.display !== 'none') {
        // Alleen herstarten als browser zichtbaar is ÉN we daadwerkelijk
        // nog op de Bezoekers-tab zijn (anders zou je 'm onnodig restarten).
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

    // Org-scope-veld. Vier scenario's:
    //   1. Target is OWNER: niet relevant (owner ziet altijd alles).
    //   2. Huidige user is SCOPED + NIEUWE user: auto-inherit eigen scope.
    //   3. Huidige user is SCOPED + EXISTING user: alleen z'n eigen orgs
    //      tonen, vinkjes = target's huidige scope-overlap. Bij target "alle":
    //      alles standaard gevinkt (admin kan deselect om scope te beperken).
    //      Orgs buiten admin's scope worden niet getoond maar wel preserved.
    //   4. Huidige user is OWNER/unscoped: alle orgs tonen, vrij kiezen.
    const huidigeOrgIds   = new Set(user?.organisatie_ids ?? []);
    const huidigUserScoped = currentUser.role !== 'owner'
        && Array.isArray(currentUser.organisatie_ids)
        && currentUser.organisatie_ids.length > 0;
    const targetIsOwner   = user?.role === 'owner';
    const targetIsAlle    = !!user && huidigeOrgIds.size === 0;

    let orgVeldHtml = '';
    if (targetIsOwner) {
        orgVeldHtml = `
            <div class="mf-rij">
                <div class="gb-org-uitleg">
                    Owner-accounts zien altijd alle organisaties — scope is niet van toepassing.
                </div>
            </div>`;
    } else if (gbOrgs.length === 0) {
        orgVeldHtml = `
            <div class="mf-rij">
                <div class="gb-org-uitleg">
                    Geen organisaties beschikbaar in de database. Voeg ze toe via Beheer → Organisaties.
                </div>
            </div>`;
    } else {
        // Bepaal default-checked per org-checkbox:
        //   - Scoped admin + nieuwe user: alles gevinkt + disabled (auto-inherit).
        //   - Scoped admin + existing "alle" user: alles gevinkt, ENABLED.
        //     Admin kan uitvinken → orgs worden van target's "alle"-scope afgenomen.
        //   - Scoped admin + existing scoped user: vinkjes = huidige overlap.
        //   - Owner/unscoped admin: vinkjes = target's huidige scope.
        const items = gbOrgs.map(o => {
            let checked, disabled = '';
            if (huidigUserScoped && !user) {
                checked = true; disabled = ' disabled';   // nieuwe user, auto-inherit
            } else if (huidigUserScoped && targetIsAlle) {
                checked = true;                            // alle user, admin kan uitvinken
            } else {
                checked = huidigeOrgIds.has(o.id);         // anders: target's huidige scope
            }
            return `
                <label class="gb-org-checkbox">
                    <input type="checkbox" value="${escHtml(o.id)}" ${checked ? 'checked' : ''}${disabled}>
                    <span>${escHtml(o.naam)}</span>
                </label>`;
        }).join('');

        let uitleg;
        if (huidigUserScoped && !user) {
            uitleg = `<div class="gb-org-uitleg">⚠ Je bent zelf gescoped — nieuwe gebruiker krijgt automatisch jouw scope (${currentUser.organisatie_ids.length} org${currentUser.organisatie_ids.length === 1 ? '' : "'s"}).</div>`;
        } else if (huidigUserScoped && targetIsAlle) {
            uitleg = `<div class="gb-org-uitleg">⚠ Deze gebruiker heeft geen scope (ziet <b>alle</b> organisaties). Vink uit om jouw organisatie(s) bij hem/haar weg te nemen — andere organisaties blijven behouden. Vinkjes laten staan = niets veranderen.</div>`;
        } else if (huidigUserScoped) {
            const buitenScopeAantal = (user?.organisatie_ids ?? []).filter(id => !currentUser.organisatie_ids.includes(id)).length;
            uitleg = `<div class="gb-org-uitleg">Je bent gescoped — je kunt alleen jouw eigen ${currentUser.organisatie_ids.length} org${currentUser.organisatie_ids.length === 1 ? '' : "'s"} toevoegen/weghalen.${buitenScopeAantal > 0 ? ` Deze gebruiker heeft daarnaast ${buitenScopeAantal} org${buitenScopeAantal === 1 ? '' : "'s"} buiten jouw scope; die blijven onaangeraakt.` : ''}</div>`;
        } else {
            uitleg = `<div class="gb-org-uitleg">Selecteer welke organisaties deze gebruiker mag zien. <b>Geen vinkjes</b> = alle organisaties (geen scope).</div>`;
        }

        orgVeldHtml = `
            <div class="mf-rij">
                <label class="mf-lbl"><span>Scope — toegestane organisaties</span></label>
                ${uitleg}
                <div id="gbf-orgs" class="gb-orgs-lijst">${items}</div>
            </div>`;
    }

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
            ${orgVeldHtml}
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

    // Org-koppelingen verzamelen uit de checkbox-lijst.
    // - Target = owner: orgs niet meesturen (backend negeert sowieso).
    // - Huidige user is scoped: backend forceert eigen scope, frontend stuurt
    //   leeg of niet — beide werken.
    // - Anders: stuur de selectie (lege array = "geen scope" = ziet alle).
    const orgInputs = document.querySelectorAll('#gbf-orgs input[type=checkbox]:not(:disabled)');
    const orgIds = Array.from(orgInputs)
        .filter(c => c.checked)
        .map(c => c.value);

    const payload = {
        action: 'save', naam, username, email, role: rol,
        organisatie_ids: orgIds,
        ...(id ? { id } : { password: pw })
    };
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
    // Number() coercion: PDO geeft u.id als string terug, id is een number
    // uit parseInt(dataset.id) — zonder coercion failt === en wordt user undef.
    const user = gbGebruikers.find(u => Number(u.id) === id);
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
    // Number() coercion: zie openGbForm / openWwForm — anders krijg je
    // "Gebruiker 'undefined' verwijderen?" in de bevestig-dialog.
    const user = gbGebruikers.find(u => Number(u.id) === id);
    if (!await toonBevestigDialog(`Gebruiker "${user?.naam ?? '?'}" verwijderen?`, 'Gebruiker verwijderen')) return;
    const res  = await fetch('api/gebruikers.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id }),
    });
    const data = await res.json();
    if (!res.ok) { toonBevestigDialog(data.error ?? 'Fout', 'Fout'); return; }
    await herlaadGebruikers();
}
