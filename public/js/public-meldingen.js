// ============================================================
//  InlineComp Public — public-meldingen.js
//
//  Bevat: meldingen: check-poll + badge + overzicht + volgende + fullscreen popup.
//  Geextraheerd uit public/app.js op 2026-09-30 (fase 3 van refactor-plan).
//  Klassieke script-tag: gedeelde global scope met de andere public-*.js modules.
// ============================================================

// ── Mededelingen (pop-ups bij belangrijke aankondigingen) ────────────────
const _MELDING_PRIO = {
    info:   { kleur: '#1a3a5c', bg: '#e8f0f7', icoon: 'ℹ️' },
    warn:   { kleur: '#7a5800', bg: '#fff8d6', icoon: '⚠️' },
    urgent: { kleur: '#a00',    bg: '#ffe5e5', icoon: '🚨' },
};
// Sleutel per melding-scope: globaal (geen competition_id) krijgt eigen
// localStorage-bucket zodat 'gezien' niet wisselt als je van wedstrijd switcht.
const _meldingScope = (m) => m?.competition_id ? m.competition_id : 'global';
const _meldingenLsKey = (scope) => `meldingen_gezien_${scope}`;
const _gezienSet = (scope) => {
    try { return new Set(JSON.parse(localStorage.getItem(_meldingenLsKey(scope)) || '[]')); }
    catch { return new Set(); }
};
const _markGezien = (scope, id) => {
    const set = _gezienSet(scope);
    set.add(id);
    localStorage.setItem(_meldingenLsKey(scope), JSON.stringify([...set]));
};
// Cache van actieve meldingen-lijst (laatste API-response). Gebruikt om
// na het sluiten van één pop-up direct de volgende ongeziene te tonen,
// zonder op de volgende poll-tick te wachten.
let _meldingLijst = [];
let _meldingActief = false;     // staat er al een pop-up open?
// Welke melding staat op dit moment in de interrupt-popup? Nodig zodat we
// 'm opnieuw kunnen renderen (in de nieuwe taal) als de gebruiker
// midden-popup naar EN/NL switcht. null = geen popup open.
let _huidigeMelding = null;

async function checkMeldingen(compId) {
    // compId leeg → fetch alleen globale meldingen (landing-pagina).
    // compId gevuld → fetch wedstrijd-specifiek + globaal samen (één call).
    try {
        const url = compId
            ? '../api/meldingen.php?comp_id=' + encodeURIComponent(compId) + '&_t=' + Date.now()
            : '../api/meldingen.php?global=1&_t=' + Date.now();
        const res = await safeFetch(url);
        const lijst = await res.json();
        if (!Array.isArray(lijst)) return;
        // Defensieve client-side filter: alleen actieve meldingen tonen.
        // De API filtert al, maar bij clock-drift, caching of een service
        // worker-replay kan een net-verlopen melding doorglippen — die
        // willen we ook hier nog wegfilteren.
        const nu = Date.now();
        _meldingLijst = lijst.filter(m => {
            const van = m.geldig_van ? Date.parse(m.geldig_van.replace(' ', 'T')) : 0;
            const tot = m.geldig_tot ? Date.parse(m.geldig_tot.replace(' ', 'T')) : null;
            if (van && van > nu)        return false;       // nog niet begonnen
            if (tot !== null && tot < nu) return false;     // verlopen
            return true;
        });
        // Badge bijwerken (totaal aantal actieve meldingen, ongeacht gezien)
        updateMeldingenBadge();
        // Pop-up alleen als er nog geen open staat (avoid stacken)
        if (!_meldingActief) toonVolgendeMelding(compId);
    } catch { /* stil */ }
}

function updateMeldingenBadge() {
    const btn = document.getElementById('btn-meldingen-overzicht');
    const badge = document.getElementById('meldingen-badge');
    if (!btn || !badge) return;
    if (_meldingLijst.length === 0) {
        btn.style.display = 'none';
        badge.hidden = true;
        return;
    }
    // Badge toont ALTIJD het totaal aantal meldingen zodat je ziet dat ze er
    // zijn. Als er nog ONGELEZEN zijn: rood + uitroepteken (= "kijk even");
    // als alles is gezien: grijs zonder uitroepteken (= "alleen FYI"). Een
    // melding telt als gelezen zodra de fullscreen-pop-up met OK is wegge-
    // klikt (zie _markGezien in de OK-handler hieronder).
    btn.style.display = '';
    const aantalOngelezen = _meldingLijst.filter(m =>
        !_gezienSet(_meldingScope(m)).has(m.id)
    ).length;
    badge.textContent = aantalOngelezen > 0
        ? `${_meldingLijst.length}!`
        : String(_meldingLijst.length);
    badge.classList.toggle('gezien', aantalOngelezen === 0);
    badge.hidden = false;
}

// Klik op 📢-knop: toont een lijst van alle nu-actieve meldingen
// (chronologisch). Geen "begrepen"-knop — dit is een lookup-paneel,
// niet een interrupterende pop-up. Eerder geziene meldingen blijven
// hier zichtbaar zodat je ze terug kan vinden.
function toonMeldingenOverzicht() {
    if (!_meldingLijst.length) return;
    const overlay = document.createElement('div');
    // data-attribute zodat _rerenderActiveTab 'm kan vinden bij taalwissel
    overlay.dataset.meldOverlay = 'overzicht';
    overlay.className = 'meld-overlay meld-overlay--overz';
    const _loc = getLocale();
    const items = _meldingLijst.map(m => {
        const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
        const tijd = m.geldig_van
            ? new Date(m.geldig_van.replace(' ', 'T')).toLocaleString(_loc,
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        const tot = m.geldig_tot
            ? t('meld_tot') + new Date(m.geldig_tot.replace(' ', 'T')).toLocaleString(_loc,
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        // Pak de vertaling voor de huidige taal als beschikbaar. Fallback-keten:
        // huidige taal → EN → NL (= origineel). Geldt voor alle ondersteunde
        // talen (NL/EN/DE/FR).
        const titelToon   = _meldingTekst(m, 'titel');
        const berichtToon = _meldingTekst(m, 'bericht');
        const bijlHtml = m.bijlage_path
            ? `<a class="meld-item-bijl" href="../${esc(m.bijlage_path)}" target="_blank" rel="noopener"
                   download="${esc(m.bijlage_naam || 'bijlage')}">
                   📎 <span class="txt-ellipsis">${esc(m.bijlage_naam || 'bijlage')}</span>
                </a>`
            : '';
        const linkHtml = m.link_url
            ? `<a class="meld-item-link" href="${esc(m.link_url)}" target="_blank" rel="noopener">
                   🔗 <span class="txt-ellipsis">${esc(_meldingTekst(m, 'link_tekst'))}</span>
                </a>`
            : '';
        return `<div class="meld-item" style="--kleur:${stijl.kleur};--bg:${stijl.bg}">
            <div class="meld-item-hdr">
                <span class="meld-item-icoon">${stijl.icoon}</span>
                <strong class="meld-item-titel">${esc(titelToon)}</strong>
            </div>
            <div class="meld-item-body">${esc(berichtToon)}</div>
            ${bijlHtml}
            ${linkHtml}
            <div class="meld-item-tijd">${esc(tijd)}${esc(tot)}</div>
        </div>`;
    }).join('');
    overlay.innerHTML = `
        <div class="meld-overz-box">
            <div class="meld-overz-hdr">
                <h3 class="meld-overz-titel">${esc(t('meld_kop'))}</h3>
                <button class="meld-overz-sluit">&times;</button>
            </div>
            <div class="meld-overz-body">${items}</div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-overz-sluit').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

// Knop wiring (één keer bij script-load)
document.getElementById('btn-meldingen-overzicht')?.addEventListener('click', toonMeldingenOverzicht);
// Pak de eerstvolgende niet-geziene melding uit de gecachte lijst en toon 'm.
// Wordt aangeroepen door checkMeldingen (na poll) en door de Begrepen-knop
// (na sluiten — direct doorrollen, geen wachttijd).
function toonVolgendeMelding(compId) {
    if (_meldingActief) return;
    // Per melding gezien-set ophalen (afhankelijk van scope = global of compId).
    for (const m of _meldingLijst) {
        const scope = _meldingScope(m);
        if (!_gezienSet(scope).has(m.id)) {
            toonMelding(m, compId);
            return;
        }
    }
}
function toonMelding(m, compId) {
    if (_meldingActief) return;     // double-click guard
    _meldingActief = true;
    _huidigeMelding = m;            // onthouden voor taalwissel-rerender
    const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
    const overlay = document.createElement('div');
    // data-attribute zodat _rerenderActiveTab 'm kan vinden bij taalwissel
    overlay.dataset.meldOverlay = 'popup';
    // Overlay scrolt zelf óók (overflow-y:auto) als achterval voor heel kleine
    // schermen waar zelfs de inner-box met max-height: 90vh nog te hoog is.
    overlay.className = 'meld-overlay meld-overlay--popup';
    // Inner-box als flex-column: header + scrollable bericht + knop. Bericht-
    // div krijgt overflow-y:auto + min-height:0 (cruciaal voor flex-children),
    // knop heeft flex-shrink:0 zodat 'ie altijd onderaan zichtbaar blijft.
    const titelToon   = _meldingTekst(m, 'titel');
    const berichtToon = _meldingTekst(m, 'bericht');
    overlay.innerHTML = `
        <div class="meld-modal" style="--kleur:${stijl.kleur};--bg:${stijl.bg}">
            <div class="meld-modal-hdr">
                <span class="meld-modal-icoon">${stijl.icoon}</span>
                <h2 class="meld-modal-titel">${esc(titelToon)}</h2>
            </div>
            <div class="meld-modal-body">${esc(berichtToon)}</div>
            ${m.bijlage_path ? `
            <div class="meld-modal-wrap">
                <a class="meld-modal-bijl" href="../${esc(m.bijlage_path)}" target="_blank" rel="noopener"
                   download="${esc(m.bijlage_naam || 'bijlage')}">
                    <span class="meld-modal-bijl-icoon">📎</span>
                    <span class="txt-ellipsis flex-1">${esc(m.bijlage_naam || 'Download bijlage')}</span>
                    <span class="meld-modal-bijl-arrow">⬇</span>
                </a>
            </div>` : ''}
            ${m.link_url ? `
            <div class="meld-modal-wrap">
                <a class="meld-modal-link" href="${esc(m.link_url)}" target="_blank" rel="noopener">
                    <span class="meld-modal-link-icoon">🔗</span>
                    <span class="txt-ellipsis">${esc(_meldingTekst(m, 'link_tekst'))}</span>
                </a>
            </div>` : ''}
            <div class="meld-modal-wrap meld-modal-wrap--ok">
                <button class="meld-ok">
                    ${esc(t('meld_begrepen'))}
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-ok').addEventListener('click', () => {
        _markGezien(_meldingScope(m), m.id);
        updateMeldingenBadge();
        overlay.remove();
        _meldingActief = false;
        _huidigeMelding = null;
        // Direct doorrollen naar volgende ongeziene melding (geen poll-wait).
        // Werkt ook zonder geselecteerde wedstrijd — globale melding-keten
        // mag altijd doorscrollen.
        toonVolgendeMelding(selComp.value);
    });
}
// keyframe voor pop-up animatie
(() => {
    const style = document.createElement('style');
    style.textContent = '@keyframes meldingPop { from {opacity:0;transform:scale(.85)} to {opacity:1;transform:scale(1)} }';
    document.head.appendChild(style);
})();

