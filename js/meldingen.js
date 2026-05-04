/* InlineComp – meldingen.
 *
 * Twee functies in één bestand:
 *   1. openMeldingenModal(compId, compNaam)
 *      Admin-modal om mededelingen te bewerken voor een wedstrijd. Beschikbaar
 *      vanuit Beheer → Wedstrijden-tab. Lijst + form, save/delete.
 *
 *   2. (intern) helper-state om in de publieke + coach app de pop-ups te
 *      tonen. Niet rechtstreeks gebruikt — die apps hebben hun eigen
 *      inline-implementatie.
 *
 * Pop-up-beleid:
 *   - Per device wordt in localStorage 'meldingen_gezien_<compId>' bijgehouden
 *     welke melding-id's al getoond zijn.
 *   - Pas zodra een onbekende id binnenkomt, verschijnt een nieuwe pop-up.
 *   - Sluitknop schrijft de id naar localStorage.
 */

const MELDING_PRIO = {
    info:   { kleur: '#1a3a5c', bg: '#e8f0f7', icoon: 'ℹ️' },
    warn:   { kleur: '#7a5800', bg: '#fff8d6', icoon: '⚠️' },
    urgent: { kleur: '#a00',    bg: '#ffe5e5', icoon: '🚨' },
};

// ── Admin-modal ───────────────────────────────────────────────────────────
async function openMeldingenModal(compId, compNaam) {
    if (!compId) return;

    // Modal-overlay opbouwen
    const overlay = document.createElement('div');
    overlay.className = 'mld-overlay';
    overlay.innerHTML = `
        <div class="mld-box">
            <div class="mld-kop">
                <h3>📢 Mededelingen — ${escHtml(compNaam || 'Wedstrijd')}</h3>
                <button class="mld-sluit" title="Sluiten">&times;</button>
            </div>
            <div class="mld-body">
                <div class="mld-uitleg">
                    Mededelingen verschijnen automatisch als pop-up bij iedereen die
                    op dit moment de publieke pagina of coach-app voor deze wedstrijd
                    open heeft staan (binnen één refresh-cyclus, dus &lt; 1 minuut).
                    Elke kijker ziet de pop-up één keer; daarna staat de melding nog
                    in de programma-pagina.
                </div>
                <div id="mld-lijst" class="mld-lijst">
                    <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                </div>
                <button class="btn-primary" id="mld-nieuw">+ Nieuwe mededeling</button>
                <div id="mld-form-wrap" style="display:none"></div>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    overlay.querySelector('.mld-sluit').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.querySelector('#mld-nieuw').addEventListener('click', () => mldOpenForm(compId, null));

    // Laad bestaande meldingen
    await mldRefreshLijst(compId);
}

async function mldRefreshLijst(compId) {
    const wrap = document.getElementById('mld-lijst');
    if (!wrap) return;
    try {
        const res = await fetch('api/meldingen.php?action=lijst&comp_id=' + encodeURIComponent(compId));
        const data = await res.json();
        if (!Array.isArray(data) || !data.length) {
            wrap.innerHTML = '<div class="mld-leeg">Nog geen mededelingen voor deze wedstrijd.</div>';
            return;
        }
        const nu = new Date();
        wrap.innerHTML = data.map(m => {
            const van = m.geldig_van ? new Date(m.geldig_van.replace(' ', 'T')) : null;
            const tot = m.geldig_tot ? new Date(m.geldig_tot.replace(' ', 'T')) : null;
            const actief = van && van <= nu && (!tot || tot >= nu);
            const verlopen = tot && tot < nu;
            const status = actief ? '<span class="mld-st mld-st-actief">● actief</span>'
                          : verlopen ? '<span class="mld-st mld-st-verlopen">● verlopen</span>'
                          : '<span class="mld-st mld-st-toekomst">● gepland</span>';
            const prioStyle = MELDING_PRIO[m.prio] ?? MELDING_PRIO.info;
            return `<div class="mld-rij" style="border-left:4px solid ${prioStyle.kleur}">
                <div class="mld-rij-kop">
                    <span class="mld-rij-icoon">${prioStyle.icoon}</span>
                    <span class="mld-rij-titel">${escHtml(m.titel)}</span>
                    ${status}
                </div>
                <div class="mld-rij-bericht">${escHtml(m.bericht)}</div>
                <div class="mld-rij-meta">
                    Geldig: ${van ? van.toLocaleString('nl-NL', {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}) : '—'}
                    ${tot ? ' tot ' + tot.toLocaleString('nl-NL', {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}) : ' (onbeperkt)'}
                </div>
                <div class="mld-rij-acties">
                    <button class="btn-secondary btn-sm mld-edit" data-id="${escHtml(m.id)}">✎ Bewerken</button>
                    <button class="btn-danger btn-sm mld-del" data-id="${escHtml(m.id)}">🗑 Verwijderen</button>
                </div>
            </div>`;
        }).join('');

        wrap.querySelectorAll('.mld-edit').forEach(btn =>
            btn.addEventListener('click', () => mldOpenForm(compId, btn.dataset.id, data)));
        wrap.querySelectorAll('.mld-del').forEach(btn =>
            btn.addEventListener('click', () => mldVerwijder(btn.dataset.id, compId)));
    } catch (e) {
        wrap.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
    }
}

function mldOpenForm(compId, id, alleMeldingen = []) {
    const wrap = document.getElementById('mld-form-wrap');
    if (!wrap) return;
    const m = id ? alleMeldingen.find(x => x.id === id) : null;
    const tsLocal = (s) => s ? s.replace(' ', 'T').substring(0, 16) : '';

    // Default-einde voor NIEUWE meldingen: einde van de huidige dag (23:59).
    // Past bij de typische gebruikspraktijk — een melding gaat over wat er
    // op de wedstrijddag gebeurt en is na middernacht niet meer relevant.
    // Voor kortere meldingen kan de gebruiker handmatig een eerder tijdstip
    // kiezen; voor langlopende info (bv. wifi-wachtwoord die over meerdere
    // dagen geldig is) kan het veld leeggemaakt worden.
    const defaultEinde = () => {
        const d = new Date();
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T23:59`;
    };
    const totDefault = m ? tsLocal(m.geldig_tot) : defaultEinde();
    wrap.style.display = '';
    wrap.innerHTML = `
        <div class="mld-form">
            <h4>${id ? 'Mededeling bewerken' : 'Nieuwe mededeling'}</h4>
            <input type="hidden" id="mld-id" value="${escHtml(m?.id ?? '')}">
            <label class="mld-veld">
                <span>Titel <span class="vereist">*</span></span>
                <input type="text" id="mld-titel" class="inp" maxlength="255" required
                       value="${escHtml(m?.titel ?? '')}" placeholder="bv. Programma loopt 15 min uit">
            </label>
            <label class="mld-veld">
                <span>Bericht <span class="vereist">*</span></span>
                <textarea id="mld-bericht" class="inp" rows="3" required
                          placeholder="Korte uitleg — wordt in een pop-up getoond">${escHtml(m?.bericht ?? '')}</textarea>
            </label>
            <div class="mld-rij-veld">
                <label class="mld-veld">
                    <span>Prioriteit</span>
                    <select id="mld-prio" class="inp">
                        <option value="info"   ${m?.prio === 'info'   || !m ? 'selected' : ''}>ℹ️ Info (blauw)</option>
                        <option value="warn"   ${m?.prio === 'warn'   ? 'selected' : ''}>⚠️ Waarschuwing (geel)</option>
                        <option value="urgent" ${m?.prio === 'urgent' ? 'selected' : ''}>🚨 Urgent (rood)</option>
                    </select>
                </label>
                <label class="mld-veld">
                    <span>Geldig van</span>
                    <input type="datetime-local" id="mld-van" class="inp" value="${tsLocal(m?.geldig_van)}">
                    <span class="label-hint">leeg = direct (nu)</span>
                </label>
                <label class="mld-veld">
                    <span>Geldig tot</span>
                    <input type="datetime-local" id="mld-tot" class="inp" value="${totDefault}">
                    <span class="label-hint">default: einde van vandaag · leeg = onbeperkt (voor langlopende info)</span>
                </label>
            </div>
            <div class="mld-form-acties">
                <button class="btn-secondary" id="mld-form-annuleer">Annuleren</button>
                <button class="btn-primary"   id="mld-form-opslaan">Opslaan + versturen</button>
            </div>
        </div>`;
    document.getElementById('mld-form-annuleer').addEventListener('click', () => {
        wrap.style.display = 'none'; wrap.innerHTML = '';
    });
    document.getElementById('mld-form-opslaan').addEventListener('click', () => mldOpslaan(compId));
}

async function mldOpslaan(compId) {
    const id      = document.getElementById('mld-id').value;
    const titel   = document.getElementById('mld-titel').value.trim();
    const bericht = document.getElementById('mld-bericht').value.trim();
    const prio    = document.getElementById('mld-prio').value;
    const van     = document.getElementById('mld-van').value;
    const tot     = document.getElementById('mld-tot').value;

    if (!titel || !bericht) {
        alert('Titel en bericht zijn verplicht.');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'save');
    if (id) fd.append('id', id);
    fd.append('comp_id', compId);
    fd.append('titel', titel);
    fd.append('bericht', bericht);
    fd.append('prio', prio);
    if (van) fd.append('geldig_van', van.replace('T', ' '));
    if (tot) fd.append('geldig_tot', tot.replace('T', ' '));

    try {
        const res = await fetch('api/meldingen.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok) { alert(data.error || 'Fout bij opslaan'); return; }
        document.getElementById('mld-form-wrap').style.display = 'none';
        document.getElementById('mld-form-wrap').innerHTML = '';
        mldRefreshLijst(compId);
    } catch (e) {
        alert('Fout: ' + e.message);
    }
}

async function mldVerwijder(id, compId) {
    if (!confirm('Mededeling verwijderen?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    try {
        const res = await fetch('api/meldingen.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok) { alert(data.error || 'Fout'); return; }
        mldRefreshLijst(compId);
    } catch (e) { alert('Fout: ' + e.message); }
}
