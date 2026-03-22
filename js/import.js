/* InlineComp – import & vergelijk */

// ── Edit-staat initialiseren ──────────────────────────────────────────────────
// Effectieve startwaarden: DB heeft voorrang, KNSB is fallback

function initEdits() {
    personEdits      = {};
    entryEdits       = {};
    manualTp         = new Set();
    heeftWijzigingen = false;
    gewijzigdeRijen  = new Set();

    isGeimporteerd = vergelijkData.some(cat =>
        cat.competitors.some(c => c.db_entry !== null)
    );

    for (const cat of vergelijkData) {
        for (const item of cat.competitors) {
            const lk = item.license_key;
            if (!lk) continue;

            if (!personEdits[lk]) {
                const p      = item.db_person;
                const t1     = item.knsb.transponder1  || null;
                const t2     = item.knsb.transponder2  || null;
                const extras = [...(item.db_tp_extra   || [])];

                // Actieve transponder:
                //   - slot 0 bewust opgeslagen in DB → gebruik DB-waarde (null = expliciete "geen")
                //   - nog nooit opgeslagen           → slim default: T1 → T2 → Textra → null
                const defaultTp = item.db_tp_actief_isset
                    ? item.db_tp_actief
                    : (t1 ?? t2 ?? extras[0] ?? null);

                personEdits[lk] = {
                    start_number:       p ? (p.start_number ?? item.knsb.start_number) : item.knsb.start_number,
                    full_name:          p ? (p.full_name    ?? item.knsb.full_name)    : item.knsb.full_name,
                    transponder1:       t1,
                    transponder2:       t2,
                    transponders_extra: extras,
                    transponder_actief: defaultTp,
                    short_name:         item.knsb.short_name,
                    gender:             item.knsb.gender,
                    category:           item.knsb.category,
                    nationality:        item.knsb.nationality,
                    club_code:          item.knsb.club_code,
                    club_short:         item.knsb.club_short,
                    club_full:          item.knsb.club_full,
                    city:               item.knsb.city,
                };
            }

            const ek = cat.dc_id + '_' + lk;
            entryEdits[ek] = {
                entry_status:  item.entry_status,
                reserve:       item.reserve,
                knsb_entry_id: item.knsb_entry_id,
            };
        }
    }
}

// ── Categorietabbladen bouwen ─────────────────────────────────────────────────

function bouwVergelijkTabbladen() {
    const tabs    = el('imp-cat-tabs');
    const content = el('imp-cat-content');

    if (!vergelijkData.length) {
        statusMsg(content, 'info', 'Geen deelnemers gevonden.');
        return;
    }

    tabs.innerHTML = '';
    vergelijkData.forEach((cat, i) => {
        const totaal    = cat.competitors.length;
        const afgemeld  = cat.competitors.filter(c => c.entry_status === 2).length;
        const nieuw     = cat.competitors.filter(c => c.is_new).length;

        let badge = '';
        if (afgemeld) badge += ` <span class="tab-badge afgemeld">${afgemeld}✗</span>`;
        if (nieuw)    badge += ` <span class="tab-badge nieuw">${nieuw}N</span>`;

        const btn = document.createElement('button');
        btn.className = 'tab-btn' + (i === 0 ? ' active' : '');
        btn.innerHTML = escHtml(cat.dc_name) + ' (' + totaal + ')' + badge;
        btn.addEventListener('click', () => {
            tabs.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeCat = cat;
            toonVergelijkTabel(cat);
        });
        tabs.appendChild(btn);
    });

    activeCat = vergelijkData[0];
    toonVergelijkTabel(vergelijkData[0]);
}

// ── Vergelijktabel tonen ──────────────────────────────────────────────────────

function toonVergelijkTabel(cat) {
    const content = el('imp-cat-content');

    if (!cat.competitors.length) {
        statusMsg(content, 'info', 'Geen deelnemers in deze categorie.');
        return;
    }

    let html = `
    <div class="vergelijk-wrap">
    <table class="vergelijk-tabel">
    <thead><tr>
        <th class="th-sn">Start#</th>
        <th class="th-naam">Naam</th>
        <th class="th-club">Club</th>
        <th class="th-tp-sel">Transponder</th>
        <th class="th-status">Status</th>
        <th class="th-badges"></th>
    </tr></thead>
    <tbody>`;

    for (const item of cat.competitors) {
        const lk    = item.license_key;
        const ek    = cat.dc_id + '_' + lk;
        const pe    = personEdits[lk]  || {};
        const ee    = entryEdits[ek]   || {};
        const st    = ee.entry_status  ?? 1;
        const sn    = pe.start_number  ?? '';
        const isNew = item.is_new;
        const diffs = item.diffs || [];

        let rowClass = '';
        if      (st === 2)     rowClass = 'row-withdrawn';
        else if (isNew)        rowClass = 'row-new';
        else if (diffs.length) rowClass = 'row-diff';
        if (gewijzigdeRijen.has(lk)) rowClass += ' row-modified';

        const isGuest = sn !== '' && sn !== null && Number(sn) >= 1000;

        const snDiff   = diffs.includes('start_number');
        const naamDiff = diffs.includes('full_name');
        const knsbSn   = item.knsb.start_number  ?? '';
        const knsbNaam = item.knsb.full_name      ?? '';
        const extras   = pe.transponders_extra    || [];
        const actief   = pe.transponder_actief;   // null = geen, string = code

        const reserveBadge = ee.reserve
            ? `<span class="badge-reserve">R${ee.reserve}</span>`
            : '';

        let badgesHtml = '';
        if (isNew)         badgesHtml += '<span class="badge-nieuw">NIEUW</span>';
        if (diffs.length)  badgesHtml += '<span class="badge-diff" title="Afwijking t.o.v. database">!</span>';

        html += `
        <tr class="${rowClass}" data-lk="${escHtml(lk)}" data-dc="${escHtml(cat.dc_id)}">
            <td class="td-sn ${isGuest ? 'guest-nr' : ''}">
                <input type="number" class="inp inp-sn" value="${escHtml(String(sn))}"
                       data-field="start_number" data-lk="${escHtml(lk)}">
                ${snDiff ? `<div class="knsb-hint">KNSB: ${escHtml(String(knsbSn))}</div>` : ''}
            </td>
            <td class="td-naam ${naamDiff ? 'cell-diff' : ''}">
                <input type="text" class="inp inp-naam" value="${escHtml(pe.full_name ?? '')}"
                       data-field="full_name" data-lk="${escHtml(lk)}">
                ${naamDiff ? `<div class="knsb-hint">KNSB: ${escHtml(knsbNaam)}</div>` : ''}
            </td>
            <td class="td-club">${escHtml(pe.club_full ?? '')}</td>
            <td class="td-tp-sel">
                ${maakTpDropdownHtml(lk, pe.transponder1, pe.transponder2, extras, actief)}
            </td>
            <td class="td-status">
                <span class="status-badge ${STATUS_CSS[st]}"
                      data-lk="${escHtml(lk)}" data-dc="${escHtml(cat.dc_id)}">
                    ${STATUS_LABELS[st]}
                </span>
                ${reserveBadge}
            </td>
            <td class="td-badges">${badgesHtml}</td>
        </tr>`;
    }

    html += '</tbody></table></div>';
    content.innerHTML = html;

    // ── Event listeners ──

    content.querySelectorAll('.inp[data-field]').forEach(inp => {
        inp.addEventListener('change', () => {
            const field = inp.dataset.field;
            const lk    = inp.dataset.lk;
            if (!lk || !field) return;
            if (!personEdits[lk]) personEdits[lk] = {};
            personEdits[lk][field] = (field === 'start_number')
                ? (parseInt(inp.value) || null)
                : (inp.value.trim() || null);
            markeerGewijzigd(inp.closest('tr'));
        });
    });

    // Transponder dropdown: selectie opslaan
    content.querySelectorAll('.tp-sel-drop').forEach(sel => {
        sel.addEventListener('change', () => {
            const lk = sel.dataset.lk;
            if (!personEdits[lk]) personEdits[lk] = {};
            personEdits[lk].transponder_actief = sel.value || null;
            markeerGewijzigd(sel.closest('tr'));
        });
    });

    // Transponder '+' knop: inline invoer
    content.querySelectorAll('.tp-add-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            voegTpToe(btn.dataset.lk, btn, content);
        });
    });

    content.querySelectorAll('.status-badge').forEach(badge => {
        badge.addEventListener('click', () => {
            const lk   = badge.dataset.lk;
            const dcId = badge.dataset.dc;
            const ek   = dcId + '_' + lk;

            const huidig = entryEdits[ek]?.entry_status ?? 1;
            const nieuw  = (huidig + 1) % 3;

            if (!entryEdits[ek]) entryEdits[ek] = {};
            entryEdits[ek].entry_status = nieuw;

            badge.className   = 'status-badge ' + STATUS_CSS[nieuw];
            badge.textContent = STATUS_LABELS[nieuw];

            const row = badge.closest('tr');
            if (row) {
                row.classList.remove('row-withdrawn', 'row-new', 'row-diff');
                if (nieuw === 2) row.classList.add('row-withdrawn');
                else             markeerGewijzigd(row);
            }
        });
    });
}

// ── Importeer-knop status ─────────────────────────────────────────────────────

function updateImportBtn() {
    const btn = el('btn-import');
    if (!btn) return;
    // Zijn er deelnemers die nog niet in de DB staan?
    const heeftNieuwe = vergelijkData.some(cat =>
        cat.competitors.some(c => c.db_entry === null)
    );
    const moetImporteren = !isGeimporteerd || heeftWijzigingen || heeftNieuwe;
    btn.disabled = !moetImporteren;
    btn.title = moetImporteren
        ? (heeftWijzigingen
            ? 'Wijzigingen opslaan in database'
            : heeftNieuwe
                ? 'Nieuwe inschrijvingen opslaan'
                : 'Wedstrijd importeren in database')
        : 'Alles is opgeslagen — geen wijzigingen';
}

// ── Tijdstempel ───────────────────────────────────────────────────────────────

function zetKnsbTimestamp() {
    const ts = el('knsb-sync-info');
    if (!ts) return;
    const nu = new Date().toLocaleString('nl-NL', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
    ts.innerHTML = `<span class="knsb-ts">&#128260; KNSB: ${nu}</span>`;
}

// ── Rij markeren als gewijzigd ────────────────────────────────────────────────

function markeerGewijzigd(row) {
    heeftWijzigingen = true;
    updateImportBtn();
    if (!row) return;
    row.classList.add('row-modified');
    if (row.dataset.lk) gewijzigdeRijen.add(row.dataset.lk);
}

// ── Herlaad vergelijking na import ───────────────────────────────────────────

async function herlaadVergelijking() {
    setHTML('imp-cat-content',
        '<div class="status-msg loading"><span class="spinner"></span>Synchroniseren met KNSB…</div>'
    );
    try {
        const res = await fetch('api/vergelijk.php?id=' + encodeURIComponent(huidigCompId));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        vergelijkData = await res.json();
        if (vergelijkData.error) throw new Error(vergelijkData.error);
        zetKnsbTimestamp();
        initEdits();
        bouwVergelijkTabbladen();
        updateImportBtn();
    } catch(e) {
        setHTML('imp-cat-content',
            `<div class="status-msg error">⚠ Synchronisatie mislukt: ${escHtml(e.message)}</div>`
        );
    }
}

// ── Transponder helpers ───────────────────────────────────────────────────────

// textraPopup bestaat nog zodat app.js (click-buiten handler) er naar kan verwijzen
let textraPopup = null;
function sluitTextraPopup() {
    if (textraPopup) { textraPopup.remove(); textraPopup = null; }
}

// Bouw de HTML voor de transponder-dropdown + '+' knop
function maakTpDropdownHtml(lk, t1, t2, extras, actief) {
    let opts = `<option value=""${!actief ? ' selected' : ''}>— geen —</option>`;
    if (t1) opts += `<option value="${escHtml(t1)}"${actief === t1 ? ' selected' : ''}>T1 – ${escHtml(t1)}</option>`;
    if (t2) opts += `<option value="${escHtml(t2)}"${actief === t2 ? ' selected' : ''}>T2 – ${escHtml(t2)}</option>`;
    for (const e of (extras || [])) {
        opts += `<option value="${escHtml(e)}"${actief === e ? ' selected' : ''}>Textra – ${escHtml(e)}</option>`;
    }
    return `<div class="tp-sel-wrap">
        <select class="inp tp-sel-drop" data-lk="${escHtml(lk)}">${opts}</select>
        <button class="tp-add-btn" data-lk="${escHtml(lk)}" title="Transponder toevoegen">+</button>
    </div>`;
}

// Bouw de opties van een bestaande <select> opnieuw op
function hertekenTpDropdown(sel, t1, t2, extras, actief) {
    let opts = `<option value=""${!actief ? ' selected' : ''}>— geen —</option>`;
    if (t1) opts += `<option value="${escHtml(t1)}"${actief === t1 ? ' selected' : ''}>T1 – ${escHtml(t1)}</option>`;
    if (t2) opts += `<option value="${escHtml(t2)}"${actief === t2 ? ' selected' : ''}>T2 – ${escHtml(t2)}</option>`;
    for (const e of (extras || [])) {
        opts += `<option value="${escHtml(e)}"${actief === e ? ' selected' : ''}>Textra – ${escHtml(e)}</option>`;
    }
    sel.innerHTML = opts;
}

// Inline invoer voor nieuwe transponder via '+' knop
function voegTpToe(lk, btn, content) {
    if (btn.nextElementSibling?.classList.contains('tp-nieuw-inp')) return;

    const inp = document.createElement('input');
    inp.type        = 'text';
    inp.className   = 'inp tp-nieuw-inp';
    inp.placeholder = 'Code…';
    inp.maxLength   = 20;
    btn.after(inp);
    inp.focus();

    const commit = () => {
        const val = inp.value.trim().toUpperCase();
        inp.remove();
        if (!val) return;

        if (!personEdits[lk]) personEdits[lk] = {};
        if (!personEdits[lk].transponders_extra) personEdits[lk].transponders_extra = [];
        if (!personEdits[lk].transponders_extra.includes(val)) {
            personEdits[lk].transponders_extra.push(val);
        }
        personEdits[lk].transponder_actief = val;
        markeerGewijzigd(btn.closest('tr'));

        // Dropdown opnieuw opbouwen en nieuwe waarde selecteren
        const sel = content.querySelector(`.tp-sel-drop[data-lk="${CSS.escape(lk)}"]`);
        if (sel) {
            const pe = personEdits[lk];
            hertekenTpDropdown(sel, pe.transponder1, pe.transponder2, pe.transponders_extra, val);
        }
    };

    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') commit();
        if (e.key === 'Escape') inp.remove();
    });
    inp.addEventListener('blur', () => setTimeout(() => inp.remove(), 200));
}

// ── Importdata verzamelen ─────────────────────────────────────────────────────

function collectImportData(compId) {
    const categories = [];

    for (const cat of vergelijkData) {
        const competitors = [];

        for (const item of cat.competitors) {
            const lk = item.license_key;
            if (!lk) continue;

            const pe = personEdits[lk]              || {};
            const ek = cat.dc_id + '_' + lk;
            const ee = entryEdits[ek]               || {};

            competitors.push({
                license_key:    lk,
                knsb_entry_id:  item.knsb_entry_id  ?? null,
                entry_status:   ee.entry_status      ?? 1,
                reserve:        ee.reserve           ?? null,
                start_number:   pe.start_number      ?? item.knsb.start_number,
                full_name:      pe.full_name         ?? item.knsb.full_name,
                short_name:     pe.short_name        ?? item.knsb.short_name,
                gender:         pe.gender            ?? item.knsb.gender,
                category:       pe.category         ?? item.knsb.category,
                nationality:    pe.nationality       ?? item.knsb.nationality,
                club_code:      pe.club_code         ?? item.knsb.club_code,
                club_short:     pe.club_short        ?? item.knsb.club_short,
                club_full:      pe.club_full         ?? item.knsb.club_full,
                city:           pe.city              ?? item.knsb.city,
                transponder1:       item.knsb.transponder1,
                transponder2:       item.knsb.transponder2,
                transponders_extra: pe.transponders_extra  ?? [],
                transponder_actief: pe.transponder_actief  ?? null,
            });
        }

        categories.push({ dc_id: cat.dc_id, competitors });
    }

    return { competition_id: compId, categories };
}

// ── Import naar database ──────────────────────────────────────────────────────

async function importeerWedstrijd(compId, compNaam) {
    const resultDiv = el('import-result');
    const btn       = el('btn-import');

    if (!vergelijkData || !vergelijkData.length) {
        resultDiv.innerHTML = '<div class="status-msg error">⚠ Laad eerst een wedstrijd</div>';
        return;
    }

    btn.disabled = true;
    resultDiv.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Importeren…</div>';

    try {
        const payload = collectImportData(compId);
        const res     = await fetch('api/import.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await res.json();

        if (!res.ok || data.error) {
            resultDiv.innerHTML =
                `<div class="status-msg error">⚠ Import mislukt: ${escHtml(data.error || 'onbekende fout')}</div>`;
            btn.disabled = false;
        } else {
            const logHtml = (data.log || []).map(r => `<li>${escHtml(r)}</li>`).join('');
            resultDiv.innerHTML =
                `<div class="status-msg ok">
                    ✔ <strong>${escHtml(compNaam)}</strong> geïmporteerd
                    <ul class="import-log">${logHtml}</ul>
                 </div>`;
            isGeimporteerd   = true;
            heeftWijzigingen = false;
            // Automatisch resync met KNSB — toont nieuwe inschrijvingen en bijgewerkte diffs
            await herlaadVergelijking();
        }
    } catch(e) {
        resultDiv.innerHTML =
            `<div class="status-msg error">⚠ Verbindingsfout: ${escHtml(e.message)}</div>`;
        btn.disabled = false;
    }
}
