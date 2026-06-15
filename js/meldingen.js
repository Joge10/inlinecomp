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
    // 'globaal' is geen echte prio in de DB — competition_id IS NULL is de
    // bron van waarheid. Maar in de admin-UI tonen we 'm als 4e dropdown-
    // optie + eigen kleur (paars), zodat 't visueel meteen onderscheidend is.
    globaal:{ kleur: '#6610f2', bg: '#efe4fb', icoon: '🌐' },
};

// ── Admin-modal ───────────────────────────────────────────────────────────
// compId: string  → wedstrijd-specifieke mededelingen + globale (samen in lijst)
// compId: null/''  → alleen globale mededelingen
async function openMeldingenModal(compId, compNaam) {
    const isGlobalOnly = !compId;
    const titelTxt = isGlobalOnly
        ? '🌐 Globale mededelingen'
        : `📢 Mededelingen — ${escHtml(compNaam || 'Wedstrijd')}`;
    const uitlegTxt = isGlobalOnly
        ? `Globale mededelingen verschijnen bij <strong>iedereen</strong> die public of
           coach opent — ook vóór ze een wedstrijd kiezen.`
        : `Mededelingen verschijnen automatisch als pop-up bij iedereen die
           op dit moment de publieke pagina of coach-app voor deze wedstrijd
           open heeft staan (binnen één refresh-cyclus, dus &lt; 1 minuut).
           Elke kijker ziet de pop-up één keer; daarna staat de melding nog
           in de programma-pagina.<br><strong>Tip:</strong> kies prio
           "🌐 Globaal" om een mededeling cross-wedstrijd te maken — die
           verschijnt dan in de mededelingen-modal van élke wedstrijd.`;

    // Modal-overlay opbouwen
    const overlay = document.createElement('div');
    overlay.className = 'mld-overlay';
    overlay.innerHTML = `
        <div class="mld-box">
            <div class="mld-kop">
                <h3>${titelTxt}</h3>
                <button class="mld-sluit" title="Sluiten">&times;</button>
            </div>
            <div class="mld-body">
                <div class="mld-uitleg">${uitlegTxt}</div>
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
    const isGlobal = !compId;
    try {
        const url = isGlobal
            ? 'api/meldingen.php?action=lijst&global=1'
            : 'api/meldingen.php?action=lijst&comp_id=' + encodeURIComponent(compId);
        const res = await fetch(url);
        const data = await res.json();
        if (!Array.isArray(data) || !data.length) {
            wrap.innerHTML = `<div class="mld-leeg">Nog geen ${isGlobal ? 'globale' : ''} mededelingen.</div>`;
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
            // Globale melding krijgt eigen 'globaal'-styling i.p.v. prio-styling.
            const isGlob = m.competition_id === null || m.competition_id === undefined;
            const styleSleutel = isGlob ? 'globaal' : (m.prio || 'info');
            const prioStyle = MELDING_PRIO[styleSleutel] ?? MELDING_PRIO.info;
            const globBadge = isGlob
                ? '<span class="mld-st mld-st-globaal" title="Verschijnt bij alle wedstrijden">🌐 globaal</span>'
                : '';
            return `<div class="mld-rij" style="border-left:4px solid ${prioStyle.kleur}">
                <div class="mld-rij-kop">
                    <span class="mld-rij-icoon">${prioStyle.icoon}</span>
                    <span class="mld-rij-titel">${escHtml(m.titel)}</span>
                    ${globBadge}
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
    // Bij bestaande melding: globaal = competition_id is null. Anders: gewone prio.
    const isExistingGlobal = m && (m.competition_id === null || m.competition_id === undefined);
    const huidigePrio = isExistingGlobal ? 'globaal' : (m?.prio ?? 'info');
    wrap.style.display = '';
    wrap.innerHTML = `
        <div class="mld-form">
            <h4>${id ? 'Mededeling bewerken' : 'Nieuwe mededeling'}</h4>
            <input type="hidden" id="mld-id" value="${escHtml(m?.id ?? '')}">
            <label class="mld-veld">
                <span>🇳🇱 Titel <span class="vereist">*</span></span>
                <input type="text" id="mld-titel" class="inp" maxlength="255" required
                       value="${escHtml(m?.titel ?? '')}" placeholder="bv. Programma loopt 15 min uit">
            </label>
            <label class="mld-veld">
                <span>🇳🇱 Bericht <span class="vereist">*</span></span>
                <textarea id="mld-bericht" class="inp" rows="3" required
                          placeholder="Korte uitleg — wordt in een pop-up getoond">${escHtml(m?.bericht ?? '')}</textarea>
            </label>
            <div class="mld-vertaal-acties">
                <button type="button" class="btn-primary" id="mld-vertaal-nl-all"
                        title="Vertaal NL → EN, DE en FR met Claude AI (één call)">
                    🤖 NL → EN/DE/FR
                </button>
                <button type="button" class="btn-secondary" id="mld-vertaal-en-all"
                        title="Vertaal EN → NL, DE en FR met Claude AI (één call)">
                    🤖 EN → NL/DE/FR
                </button>
                <span class="mld-vertaal-hint">vertaling is daarna handmatig aanpasbaar · public-app is 4-talig (NL/EN/DE/FR)</span>
            </div>
            <label class="mld-veld">
                <span>🇬🇧 Title <span class="label-hint">(leeg = fallback naar NL)</span></span>
                <input type="text" id="mld-titel-en" class="inp" maxlength="255"
                       value="${escHtml(m?.titel_en ?? '')}" placeholder="e.g. Schedule running 15 min late">
            </label>
            <label class="mld-veld">
                <span>🇬🇧 Message</span>
                <textarea id="mld-bericht-en" class="inp" rows="3"
                          placeholder="Short explanation — shown in pop-up">${escHtml(m?.bericht_en ?? '')}</textarea>
            </label>
            <label class="mld-veld">
                <span>🇩🇪 Titel <span class="label-hint">(leer = Fallback auf EN/NL)</span></span>
                <input type="text" id="mld-titel-de" class="inp" maxlength="255"
                       value="${escHtml(m?.titel_de ?? '')}" placeholder="z.B. Zeitplan 15 Min verspätet">
            </label>
            <label class="mld-veld">
                <span>🇩🇪 Nachricht</span>
                <textarea id="mld-bericht-de" class="inp" rows="3"
                          placeholder="Kurze Erläuterung — wird im Pop-up angezeigt">${escHtml(m?.bericht_de ?? '')}</textarea>
            </label>
            <label class="mld-veld">
                <span>🇫🇷 Titre <span class="label-hint">(vide = repli sur EN/NL)</span></span>
                <input type="text" id="mld-titel-fr" class="inp" maxlength="255"
                       value="${escHtml(m?.titel_fr ?? '')}" placeholder="ex. Programme retardé de 15 min">
            </label>
            <label class="mld-veld">
                <span>🇫🇷 Message</span>
                <textarea id="mld-bericht-fr" class="inp" rows="3"
                          placeholder="Courte explication — affichée en pop-up">${escHtml(m?.bericht_fr ?? '')}</textarea>
            </label>
            <div class="mld-veld mld-bijlage-blok" id="mld-bijlage-blok">
                <span>📎 Bijlage <span class="label-hint">— optioneel, PDF/Word/Excel/afbeelding; één bestand per melding</span></span>
                <div class="mld-bijlage-huidig" id="mld-bijlage-huidig">
                    ${m?.bijlage_path
                        ? `<a href="${escHtml(m.bijlage_path)}" target="_blank" rel="noopener" class="mld-bijlage-link">📄 ${escHtml(m.bijlage_naam || 'bijlage')}</a>
                           <button type="button" class="btn-danger btn-sm" id="mld-bijlage-verwijder">🗑 Bijlage verwijderen</button>`
                        : `<span class="mld-bijlage-leeg">Nog geen bijlage</span>`}
                </div>
                <div class="mld-bijlage-nieuw">
                    <label class="btn-secondary mld-bijlage-upload-lbl">
                        <input type="file" id="mld-bijlage-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,image/*" hidden>
                        📎 Bestand kiezen…
                    </label>
                    <span class="mld-bijlage-naam" id="mld-bijlage-naam-preview"></span>
                </div>
            </div>
            <div class="mld-rij-veld">
                <label class="mld-veld">
                    <span>Prioriteit</span>
                    <select id="mld-prio" class="inp">
                        <option value="info"    ${huidigePrio === 'info'    ? 'selected' : ''}>ℹ️ Info (blauw)</option>
                        <option value="warn"    ${huidigePrio === 'warn'    ? 'selected' : ''}>⚠️ Waarschuwing (geel)</option>
                        <option value="urgent"  ${huidigePrio === 'urgent'  ? 'selected' : ''}>🚨 Urgent (rood)</option>
                        <option value="globaal" ${huidigePrio === 'globaal' ? 'selected' : ''}>🌐 Globaal — voor alle wedstrijden</option>
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
    document.getElementById('mld-vertaal-nl-all')?.addEventListener('click', () => mldVertaalBulk('nl'));
    document.getElementById('mld-vertaal-en-all')?.addEventListener('click', () => mldVertaalBulk('en'));

    // ── Bijlage: bestand kiezen + bestaande verwijderen ─────────────────
    // De daadwerkelijke upload gebeurt PAS bij Opslaan (we hebben dan een
    // melding-id; voor nieuwe meldingen bestaat die nog niet). Hier alleen
    // preview van de gekozen bestandsnaam.
    document.getElementById('mld-bijlage-input').addEventListener('change', e => {
        const f = e.target.files?.[0];
        document.getElementById('mld-bijlage-naam-preview').textContent =
            f ? `📎 ${f.name}` : '';
    });
    document.getElementById('mld-bijlage-verwijder')?.addEventListener('click', async () => {
        if (!id) return; // alleen op bestaande melding
        const ok = await toonBevestigDialog(
            'Bijlage verwijderen?', 'Bijlage', 'Verwijderen', 'Annuleren'
        );
        if (!ok) return;
        try {
            const fd = new FormData();
            fd.append('action', 'verwijder_bijlage');
            fd.append('id', id);
            const res  = await fetch('api/meldingen.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'Verwijderen mislukt');
            document.getElementById('mld-bijlage-huidig').innerHTML =
                `<span class="mld-bijlage-leeg">Nog geen bijlage</span>`;
        } catch (err) {
            toonBevestigDialog('Fout: ' + err.message, 'Bijlage', 'OK', '');
        }
    });
}

// Helper — geeft input-elements voor (titel, bericht) per taal terug.
function _mldVeldenVoor(taal) {
    const suf = taal === 'nl' ? '' : '-' + taal;
    return {
        titel:   document.getElementById('mld-titel'   + suf),
        bericht: document.getElementById('mld-bericht' + suf),
    };
}

// Bulk-vertaling via api/vertaal_melding.php — 1 call vertaalt naar alle 3
// andere talen tegelijk. Vult de doel-velden; operator kan vertaling daarna
// nog handmatig bijwerken. Knop toont een 'Bezig…'-status zodat duidelijk
// is dat de API-call loopt (typisch 2-4s voor 3 talen).
//
// `from`: brontaal ('nl' of 'en'); rest van I18N_LANGS wordt doel.
async function mldVertaalBulk(from) {
    const TALEN     = ['nl', 'en', 'de', 'fr'];
    const TAAL_NAAM = { nl: 'NL', en: 'EN', de: 'DE', fr: 'FR' };
    const doelen    = TALEN.filter(t => t !== from);

    const src = _mldVeldenVoor(from);
    if (!src.titel || !src.bericht) return;
    const titel   = src.titel.value.trim();
    const bericht = src.bericht.value.trim();
    if (!titel && !bericht) {
        toonBevestigDialog(
            `Vul eerst de ${TAAL_NAAM[from]}-velden in voor je laat vertalen.`,
            'Vertalen', 'OK', ''
        );
        return;
    }

    // Welke doel-velden bevatten al tekst? Vraag bevestiging als minstens
    // één doel al gevuld is — voorkomt per ongeluk overschrijven.
    const gevuld = doelen.filter(l => {
        const v = _mldVeldenVoor(l);
        return (v.titel?.value.trim() || v.bericht?.value.trim());
    });
    if (gevuld.length) {
        const lijst = gevuld.map(l => TAAL_NAAM[l]).join(', ');
        const ok = await toonBevestigDialog(
            `Doel-velden bevatten al tekst voor: ${lijst}. Overschrijven met automatische vertaling?`,
            'Vertalen', 'Overschrijven', 'Annuleren'
        );
        if (!ok) return;
    }

    const knoppen = document.querySelectorAll('#mld-vertaal-nl-all, #mld-vertaal-en-all');
    knoppen.forEach(b => b.disabled = true);
    const klikKnop = document.getElementById(`mld-vertaal-${from}-all`);
    const originalText = klikKnop?.textContent;
    if (klikKnop) klikKnop.textContent = '⏳ Bezig…';

    try {
        const res = await fetch('api/vertaal_melding.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            // `to` als array → backend gaat in bulk-mode en returnt
            // { translations: { en:{...}, de:{...}, fr:{...} } }
            body:    JSON.stringify({ titel, bericht, from, to: doelen }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        if (!data.translations) throw new Error('Geen translations in response');

        for (const l of doelen) {
            const tr = data.translations[l];
            if (!tr) continue;
            const dst = _mldVeldenVoor(l);
            if (dst.titel)   dst.titel.value   = tr.titel   ?? '';
            if (dst.bericht) dst.bericht.value = tr.bericht ?? '';
        }
    } catch (e) {
        toonBevestigDialog('Vertaal-fout: ' + e.message, 'Vertalen', 'OK', '');
    } finally {
        knoppen.forEach(b => b.disabled = false);
        if (klikKnop && originalText) klikKnop.textContent = originalText;
    }
}

async function mldOpslaan(compId) {
    const id        = document.getElementById('mld-id').value;
    const titel     = document.getElementById('mld-titel').value.trim();
    const bericht   = document.getElementById('mld-bericht').value.trim();
    const titelEn   = document.getElementById('mld-titel-en')?.value.trim()   ?? '';
    const berichtEn = document.getElementById('mld-bericht-en')?.value.trim() ?? '';
    const titelDe   = document.getElementById('mld-titel-de')?.value.trim()   ?? '';
    const berichtDe = document.getElementById('mld-bericht-de')?.value.trim() ?? '';
    const titelFr   = document.getElementById('mld-titel-fr')?.value.trim()   ?? '';
    const berichtFr = document.getElementById('mld-bericht-fr')?.value.trim() ?? '';
    const prio      = document.getElementById('mld-prio').value;
    const van       = document.getElementById('mld-van').value;
    const tot       = document.getElementById('mld-tot').value;

    if (!titel || !bericht) {
        toonBevestigDialog(
            'Titel en bericht zijn verplicht (NL is brontaal).',
            'Mededeling opslaan', 'OK', ''
        );
        return;
    }
    // 'globaal' is geen DB-prio (enum kent 'm niet) maar een scope-keuze:
    // → competition_id wordt NULL via global=1, prio valt terug op 'info'.
    const isGlobaal = prio === 'globaal';
    const dbPrio = isGlobaal ? 'info' : prio;
    const fd = new FormData();
    fd.append('action', 'save');
    if (id) fd.append('id', id);
    if (isGlobaal || !compId) {
        fd.append('global', '1');
    } else {
        fd.append('comp_id', compId);
    }
    fd.append('titel', titel);
    fd.append('bericht', bericht);
    if (titelEn)   fd.append('titel_en',   titelEn);
    if (berichtEn) fd.append('bericht_en', berichtEn);
    if (titelDe)   fd.append('titel_de',   titelDe);
    if (berichtDe) fd.append('bericht_de', berichtDe);
    if (titelFr)   fd.append('titel_fr',   titelFr);
    if (berichtFr) fd.append('bericht_fr', berichtFr);
    fd.append('prio', dbPrio);
    if (van) fd.append('geldig_van', van.replace('T', ' '));
    if (tot) fd.append('geldig_tot', tot.replace('T', ' '));

    try {
        const res = await fetch('api/meldingen.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok) {
            toonBevestigDialog(data.error || 'Fout bij opslaan', 'Mededeling opslaan', 'OK', '');
            return;
        }
        // Bijlage uploaden (indien gekozen). Pas hier mogelijk omdat we voor
        // een nieuwe melding pas na save 'n id hebben. data.id is de
        // canonieke id uit de backend (zowel bij insert als update).
        const meldId   = data.id || id;
        const bijlInp  = document.getElementById('mld-bijlage-input');
        const bijlFile = bijlInp?.files?.[0];
        if (meldId && bijlFile) {
            try {
                const upFd = new FormData();
                upFd.append('type', 'melding');
                upFd.append('id',   meldId);
                upFd.append('logo', bijlFile);
                const upRes = await fetch('api/upload.php', { method: 'POST', body: upFd });
                const upDat = await upRes.json();
                if (upDat.error) throw new Error(upDat.error);
            } catch (e2) {
                toonBevestigDialog(
                    'Mededeling opgeslagen, maar bijlage uploaden mislukt: ' + e2.message,
                    'Bijlage', 'OK', ''
                );
                // Niet returnen — melding zelf is wél opgeslagen.
            }
        }
        document.getElementById('mld-form-wrap').style.display = 'none';
        document.getElementById('mld-form-wrap').innerHTML = '';
        mldRefreshLijst(compId);
    } catch (e) {
        toonBevestigDialog('Fout: ' + e.message, 'Mededeling opslaan', 'OK', '');
    }
}

async function mldVerwijder(id, compId) {
    const ok = await toonBevestigDialog(
        'Mededeling verwijderen?',
        'Mededeling verwijderen', 'Verwijderen', 'Annuleren'
    );
    if (!ok) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    try {
        const res = await fetch('api/meldingen.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok) {
            toonBevestigDialog(data.error || 'Fout', 'Mededeling verwijderen', 'OK', '');
            return;
        }
        mldRefreshLijst(compId);
    } catch (e) {
        toonBevestigDialog('Fout: ' + e.message, 'Mededeling verwijderen', 'OK', '');
    }
}
