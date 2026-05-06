// ── Uploads-pagina (Orbits/MyLaps CSV-archief beheer) ────────────────────────
// Alleen voor owner/admin. Toont submappen onder uploader/ met grootte +
// leeftijd, en biedt per-rij verwijder-knop. Geen auto-cleanup — bewuste
// keuze om te voorkomen dat een nog-relevante map per ongeluk weg is.

let _upMappen = [];
let _upFilterAgeDays = 0;

async function toonUploadsPagina() {
    const cont = el('up-container');
    if (!cont) return;
    cont.innerHTML = '<div class="status-msg loading"><span class="spinner"></span>Laden…</div>';

    try {
        const res  = await fetch('api/uploader_beheer.php?action=list');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        _upMappen = data.mappen || [];
    } catch (e) {
        cont.innerHTML = `<div class="status-msg error">⚠ ${escHtml(e.message)}</div>`;
        return;
    }

    _upRender();

    // Filter en refresh éénmalig binden — ze hangen aan vaste DOM-elementen
    // (boven de tabel) die niet door _upRender opnieuw worden opgebouwd.
    const filterSel = el('up-filter-age');
    if (filterSel && !filterSel.dataset.bound) {
        filterSel.dataset.bound = '1';
        filterSel.addEventListener('change', e => {
            _upFilterAgeDays = parseInt(e.target.value) || 0;
            _upRender();
        });
    }
    const refreshBtn = el('up-btn-refresh');
    if (refreshBtn && !refreshBtn.dataset.bound) {
        refreshBtn.dataset.bound = '1';
        refreshBtn.addEventListener('click', () => toonUploadsPagina());
    }
}

function _upRender() {
    const cont = el('up-container');
    if (!cont) return;

    const zichtbaar = _upFilterAgeDays > 0
        ? _upMappen.filter(m => (m.age_days ?? 0) >= _upFilterAgeDays)
        : _upMappen;

    if (!zichtbaar.length) {
        cont.innerHTML = '<div class="status-msg info">'
                       + (_upMappen.length === 0
                           ? 'Geen mappen gevonden in uploader/.'
                           : 'Geen mappen ouder dan ' + _upFilterAgeDays + ' dagen.')
                       + '</div>';
        return;
    }

    const totaalGr = zichtbaar.reduce((s, m) => s + (m.total_size || 0), 0);
    const samenvatting = `${zichtbaar.length} mappen · ${_upFmtBytes(totaalGr)} totaal`;

    const rijen = zichtbaar.map(m => {
        const dateStr = m.latest_mtime
            ? new Date(m.latest_mtime * 1000).toLocaleString('nl-NL',
                { day: '2-digit', month: '2-digit', year: 'numeric',
                  hour: '2-digit', minute: '2-digit' })
            : '—';
        const ageStr = m.age_days != null ? `${m.age_days} dgn` : '—';
        const ageCls = m.age_days != null && m.age_days >= 90 ? 'up-oud' : '';
        return `<tr>
            <td class="up-naam">${escHtml(m.name)}</td>
            <td class="tc">${m.file_count}</td>
            <td class="tr">${_upFmtBytes(m.total_size)}</td>
            <td>${escHtml(dateStr)}</td>
            <td class="tc ${ageCls}">${ageStr}</td>
            <td class="tc">
                <button class="btn-del up-btn-del" data-naam="${escHtml(m.name)}"
                        title="Verwijder map ${escHtml(m.name)}">🗑️ Verwijder</button>
            </td>
        </tr>`;
    }).join('');

    cont.innerHTML = `
        <div class="up-samenvatting">${escHtml(samenvatting)}</div>
        <table class="up-tabel">
            <thead><tr>
                <th>Map</th>
                <th class="tc">Bestanden</th>
                <th class="tr">Grootte</th>
                <th>Laatst gewijzigd</th>
                <th class="tc">Leeftijd</th>
                <th class="tc">Actie</th>
            </tr></thead>
            <tbody>${rijen}</tbody>
        </table>`;

    cont.querySelectorAll('.up-btn-del').forEach(btn => {
        btn.addEventListener('click', () => _upVerwijder(btn.dataset.naam));
    });
}

async function _upVerwijder(naam) {
    if (!naam) return;
    let ok;
    if (typeof toonBevestigDialog === 'function') {
        ok = await toonBevestigDialog(
            `Weet je zeker dat je map "${naam}" definitief wilt verwijderen? Dit is onomkeerbaar.`,
            'Map verwijderen', 'Verwijder', 'Annuleer');
    } else {
        ok = confirm(`Weet je zeker dat je map "${naam}" definitief wilt verwijderen?`);
    }
    if (!ok) return;

    try {
        const res = await fetch('api/uploader_beheer.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'delete', name: naam }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'HTTP ' + res.status);
        // Optimistisch verwijderen + re-render zonder server-roundtrip
        _upMappen = _upMappen.filter(m => m.name !== naam);
        _upRender();
    } catch (e) {
        alert('Fout bij verwijderen: ' + e.message);
    }
}

function _upFmtBytes(b) {
    if (!b || b < 1024) return `${b || 0} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} kB`;
    if (b < 1024 * 1024 * 1024) return `${(b / 1024 / 1024).toFixed(1)} MB`;
    return `${(b / 1024 / 1024 / 1024).toFixed(2)} GB`;
}
