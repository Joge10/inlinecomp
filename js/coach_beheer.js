// ============================================================
//  js/coach_beheer.js — Beheer → Coach-tab
//  Coach-accounts goedkeuren/afwijzen/(de)activeren/verwijderen.
//  Backend: api/coach_beheer.php (owner/admin).
// ============================================================

async function toonCoachBeheer() {
    const c = document.getElementById('coach-beheer-container');
    if (!c) return;
    c.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';
    try {
        const res   = await fetch('api/coach_beheer.php');
        const lijst = await res.json();
        if (!Array.isArray(lijst)) throw new Error(lijst.error || 'Fout bij laden');
        c.innerHTML = _cbRender(lijst);
        _cbBind();
    } catch (e) {
        c.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

function _cbBadge(status) {
    const s = { padding: '2px 8px', 'border-radius': '6px', 'font-size': '.78rem', 'white-space': 'nowrap' };
    const stijl = (extra) => Object.entries({ ...s, ...extra }).map(([k, v]) => `${k}:${v}`).join(';');
    if (status === 'approved') return `<span style="${stijl({ background: '#e7f5e9', color: '#2e7d32' })}">goedgekeurd</span>`;
    if (status === 'rejected') return `<span style="${stijl({ background: '#fdecea', color: '#b71c1c' })}">afgewezen</span>`;
    return `<span style="${stijl({ background: '#fff6e5', color: '#8a5a00' })}">wacht op goedkeuring</span>`;
}

function _cbRender(lijst) {
    const nPending = lijst.filter(a => a.status === 'pending').length;
    const rows = lijst.map(a => {
        const vanLabel = a.coacht_van_type === 'club' ? 'Club'
                       : a.coacht_van_type === 'team' ? 'Team' : 'Anders';
        const inactief = (+a.actief) ? '' :
            ` <span style="background:#eee;color:#666;padding:2px 7px;border-radius:6px;font-size:.75rem">inactief</span>`;
        const last = a.last_login_at ? new Date(a.last_login_at.replace(' ', 'T') + 'Z')
            .toLocaleDateString('nl-NL', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—';
        // 4 vaste actie-slots (zelfde patroon als Gebruikers): goedkeuren,
        // afwijzen, (de)activeren, verwijderen. Niet-beschikbare slot = lege
        // .gb-btn-leeg zodat de icoonknoppen netjes uitgelijnd blijven.
        const acties = [];
        acties.push(a.status !== 'approved'
            ? `<button class="btn-secondary cb-act" data-act="goedkeuren" data-id="${a.id}" title="Goedkeuren">&#10004;</button>`
            : `<span class="gb-btn-leeg"></span>`);
        acties.push(a.status !== 'rejected'
            ? `<button class="btn-secondary cb-act" data-act="afwijzen" data-id="${a.id}" title="Afwijzen">&#10008;</button>`
            : `<span class="gb-btn-leeg"></span>`);
        acties.push((+a.actief)
            ? `<button class="btn-secondary gb-btn-toggle cb-act" data-act="deactiveren" data-id="${a.id}" title="Account is actief — klik om te deactiveren">&#128275;</button>`
            : `<button class="btn-secondary gb-btn-toggle gb-btn-toggle-actief cb-act" data-act="activeren" data-id="${a.id}" title="Account is gedeactiveerd — klik om te activeren">&#128274;</button>`);
        acties.push(`<button class="btn-del cb-act" data-act="verwijderen" data-id="${a.id}" title="Verwijderen">&#128465;</button>`);
        return `<tr>
            <td>${escHtml(a.naam)}<div style="color:#888;font-size:.8rem">${escHtml(a.email)}</div></td>
            <td>${vanLabel}<div style="color:#888;font-size:.8rem">${escHtml(a.coacht_van)}</div></td>
            <td style="text-align:center">${a.roster_count}</td>
            <td style="text-align:center;color:#888;font-size:.85rem">${last}</td>
            <td>${_cbBadge(a.status)}${inactief}</td>
            <td class="gb-acties">${acties.join('')}</td>
        </tr>`;
    }).join('');

    return `
        <div class="section-title">Coach-accounts${nPending ? ` — <span style="color:#b26a00">${nPending} wacht op goedkeuring</span>` : ''}</div>
        <div class="hp-info">
            Individuele coach-logins. Een coach werkt tot goedkeuring gewoon met de <strong>anonieme lijst</strong>;
            goedkeuring ontgrendelt de persoonlijke roster + auto-highlight. Afwijzen of deactiveren trekt lopende sessies direct in.
        </div>
        <div style="overflow-x:auto">
        <table class="gb-tabel">
            <thead><tr>
                <th>Naam</th><th>Coach van</th><th>Atleten</th><th>Laatste login</th><th>Status</th><th>Acties</th>
            </tr></thead>
            <tbody>${lijst.length ? rows : '<tr><td colspan="6" class="gb-log-laden">Nog geen coach-accounts.</td></tr>'}</tbody>
        </table>
        </div>`;
}

function _cbBind() {
    document.querySelectorAll('#coach-beheer-container .cb-act').forEach(b => {
        b.addEventListener('click', async () => {
            const act = b.dataset.act, id = b.dataset.id;
            if (act === 'verwijderen') {
                const ok = (typeof toonBevestigDialog === 'function')
                    ? await toonBevestigDialog('Dit coach-account definitief verwijderen? De roster gaat mee.', 'Coach-account verwijderen')
                    : confirm('Dit coach-account definitief verwijderen? De roster gaat mee.');
                if (!ok) return;
            }
            b.disabled = true;
            try {
                const res = await fetch('api/coach_beheer.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: act, id }),
                });
                const d = await res.json();
                if (!res.ok || !d.ok) throw new Error(d.error || 'Fout');
                toonCoachBeheer();   // herladen
            } catch (e) {
                b.disabled = false;
                if (typeof toonBevestigDialog === 'function') toonBevestigDialog('Fout: ' + e.message, 'Fout', 'OK', '');
                else alert('Fout: ' + e.message);
            }
        });
    });
}
