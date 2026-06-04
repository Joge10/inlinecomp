// ============================================================
//  InlineComp – Handmatige wedstrijd-aanmaak (UI)
//
//  Modal-flow voor wedstrijden buiten de KNSB-feed om. Operator vult
//  organisatie + basis-info + categorieën in. Backend: api/wedstrijd_handmatig.php
//
//  Na succes: modal sluit, lijst ververst, nieuwe wedstrijd auto-geselecteerd.
//  Afstanden voegt de operator daarna toe via Beheer → Afstanden (bestaande
//  flow, geen duplicatie hier).
// ============================================================

(function () {
    // KNSB-categorie-codes voor de category_filter multi-select. Volgorde
    // matcht het PR-rapport (jong → oud, D vóór H per cat). Bij intern
    // gebruik op niet-NL-wedstrijden mag de operator dit veld ook leeglaten
    // → category_filter = NULL → "alle categorieën", consistent met KNSB-feed.
    const KNSB_CATS = [
        'DP4','HP4', 'DP3','HP3', 'DP2','HP2', 'DP1','HP1',
        'DKA','HKA',
        'DJB','HJB', 'DJA','HJA',
        'DSJ','HSJ', 'DSA','HSA',
    ];

    let _orgs = []; // gefetched in initWh()
    // DC-state leeft direct in de DOM — geen aparte array nodig. Bij delete
    // tellen we DOM-rijen, bij submit lezen we per DOM-rij naam + checked cats.
    // Cleaner dan closure-tracked idx (die schuift na delete en breekt).

    // ── Init: knop tonen alleen voor importeer-rollen, orgs prefetchen ────
    // currentUser is een globale const uit index.php (NIET window.currentUser).
    // magSchrijven('importeer') geeft true voor owner/admin/importer — zelfde
    // gate als de bestaande Import-knop.
    async function initWh() {
        if (typeof currentUser === 'undefined') return;
        if (typeof magSchrijven === 'function' && !magSchrijven('importeer')) return;

        // Knop tonen + listener
        const wrap = document.getElementById('wh-knop-wrap');
        if (wrap) wrap.style.display = '';
        document.getElementById('wh-btn-open')?.addEventListener('click', openModal);

        // Modal close listeners
        document.getElementById('wh-btn-sluit')?.addEventListener('click', closeModal);
        document.getElementById('wh-btn-annuleer')?.addEventListener('click', closeModal);
        document.getElementById('wh-modal-overlay')?.addEventListener('click', e => {
            if (e.target.id === 'wh-modal-overlay') closeModal();
        });

        document.getElementById('wh-btn-add-dc')?.addEventListener('click', () => addDcRow());
        document.getElementById('wh-btn-create')?.addEventListener('click', submitWedstrijd);

        // Orgs prefetchen — bij scoped admin krijgen we alleen z'n eigen orgs.
        try {
            const r = await fetch('api/wedstrijd_handmatig.php?action=orgs_voor_create');
            _orgs = await r.json();
        } catch (e) {
            console.error('Kon orgs niet ophalen', e);
            _orgs = [];
        }
    }

    // ── Modal openen: vul org-dropdown + reset form + start met 1 lege DC ─
    function openModal() {
        // Org-dropdown vullen
        const orgSel = document.getElementById('wh-org');
        orgSel.innerHTML = '<option value="">— kies organisatie —</option>'
            + _orgs.map(o => `<option value="${esc(o.id)}">${esc(o.naam)}</option>`).join('');

        // Pre-select als gebruiker maar 1 org heeft (scoped admin met 1 org)
        if (_orgs.length === 1) orgSel.value = _orgs[0].id;

        // Form fields resetten
        document.getElementById('wh-naam').value = '';
        document.getElementById('wh-start').value = '';
        document.getElementById('wh-eind').value = '';
        document.getElementById('wh-locatie').value = '';
        document.getElementById('wh-venue').value = '';
        document.getElementById('wh-fout').style.display = 'none';

        // DC-lijst resetten — start met 1 lege rij zodat operator meteen kan typen
        document.getElementById('wh-dc-lijst').innerHTML = '';
        addDcRow();

        document.getElementById('wh-modal-overlay').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('wh-modal-overlay').style.display = 'none';
    }

    // ── DC-rij toevoegen aan de lijst ─────────────────────────────────────
    // Render-strategie: bij elke toevoeging één nieuwe rij appenden. Geen
    // separate state-array — bij submit lezen we name + checked cats direct
    // uit de DOM. Bij delete: minimaal 1 rij moet er overblijven.
    function addDcRow() {
        const lijst = document.getElementById('wh-dc-lijst');
        const rij = document.createElement('div');
        rij.className = 'wh-dc-rij';
        rij.innerHTML = `
            <div class="wh-dc-rij-top">
                <input type="text" class="modal-input wh-dc-naam"
                       placeholder="Naam (bv. Senioren Mannen)"
                       maxlength="100">
                <button class="wh-btn-del-dc" title="Verwijder deze categorie">×</button>
            </div>
            <div class="wh-dc-cats" title="Welke KNSB-categorie-codes komen in deze DC uit?">
                ${KNSB_CATS.map(c => `
                    <label class="wh-cat-chip">
                        <input type="checkbox" value="${c}">
                        <span>${c}</span>
                    </label>
                `).join('')}
            </div>
        `;

        rij.querySelector('.wh-btn-del-dc').addEventListener('click', () => {
            const rijen = document.querySelectorAll('.wh-dc-rij');
            if (rijen.length <= 1) {
                showFout('Minimaal 1 categorie verplicht.');
                return;
            }
            rij.remove();
        });

        lijst.appendChild(rij);
    }

    // ── Submit naar backend ───────────────────────────────────────────────
    async function submitWedstrijd() {
        const orgId    = document.getElementById('wh-org').value;
        const naam     = document.getElementById('wh-naam').value.trim();
        const starts   = document.getElementById('wh-start').value;
        const ends     = document.getElementById('wh-eind').value;
        const locatie  = document.getElementById('wh-locatie').value.trim();
        const venue    = document.getElementById('wh-venue').value.trim();

        if (!orgId)  return showFout('Kies een organisatie.');
        if (!naam)   return showFout('Wedstrijdnaam is verplicht.');
        if (!starts) return showFout('Startdatum is verplicht.');

        // DCs uit DOM lezen: per rij naam + checked cat-codes
        const dcs = Array.from(document.querySelectorAll('.wh-dc-rij')).map(rij => {
            const naam = rij.querySelector('.wh-dc-naam').value.trim();
            const cats = Array.from(rij.querySelectorAll('.wh-cat-chip input:checked'))
                .map(cb => cb.value);
            return { name: naam, category_filter: cats.join(',') };
        }).filter(d => d.name !== '');

        if (!dcs.length) return showFout('Minimaal 1 categorie met naam verplicht.');

        const btn = document.getElementById('wh-btn-create');
        btn.disabled = true;
        btn.textContent = 'Bezig…';

        try {
            const r = await fetch('api/wedstrijd_handmatig.php?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    organisatie_id: orgId,
                    naam, starts,
                    ends: ends || '',
                    location: locatie,
                    venue_name: venue,
                    dcs,
                }),
            });
            const data = await r.json();
            if (!r.ok || data.error) {
                showFout(data.error || `Fout (HTTP ${r.status})`);
                return;
            }

            // Succes — modal dicht, lijst ververs, nieuwe wedstrijd selecteren
            closeModal();
            if (typeof window.renderWedstrijdLijst === 'function') {
                // app.js heeft het renderen — herlaad de hele lijst
                if (typeof window.laadWedstrijden === 'function') {
                    await window.laadWedstrijden();
                }
                window.renderWedstrijdLijst();
            }
            // Toast / status-melding (gebruik bestaande als die er is)
            if (typeof window.toast === 'function') {
                window.toast(`✓ Wedstrijd "${naam}" aangemaakt (${data.aantal_dcs} cat.)`);
            } else {
                alert(`Wedstrijd "${naam}" aangemaakt met ${data.aantal_dcs} categorieën.\n\nVoeg nu afstanden toe via Beheer → Afstanden.`);
            }
        } catch (e) {
            showFout('Netwerkfout: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Wedstrijd aanmaken';
        }
    }

    function showFout(msg) {
        const el = document.getElementById('wh-fout');
        el.textContent = msg;
        el.style.display = '';
    }

    function esc(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Init bij DOMContentLoaded (en niet eerder — currentUser kan dan nog ontbreken).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWh);
    } else {
        initWh();
    }
})();
