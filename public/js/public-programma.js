// ============================================================
//  InlineComp Public — public-programma.js
//
//  Bevat: heat-tabel helpers + programma-filter (dag/afstand) + heat-sortering + rit-detail overlay.
//  Geextraheerd uit public/app.js op 2026-09-30 (fase 3 van refactor-plan).
//  Klassieke script-tag: gedeelde global scope met de andere public-*.js modules.
// ============================================================

function pkMaxRnd(rijders) {
    let m = 0;
    for (const r of (rijders || [])) {
        const rd = r.rondes;
        if (rd != null && Number(rd) > m) m = Number(rd);
    }
    return m;
}
function pkPuntCel(r, mx) {
    if (r.pk_punten == null) return '';
    const p = parseFloat(r.pk_punten);
    const vervallen = mx > 0 && r.rondes != null && Number(r.rondes) < mx;
    if (!vervallen) return String(p);
    return `<s>${p}</s> <span style="font-size:.72em;color:#b02a37;font-weight:600">${esc(t('pk_vervallen') || 'vervallen')}</span>`;
}
// Detecteer extra kolommen voor heat-rijders
function heatExtraKolommen(rijders, rondeType) {
    const heeftRnd = rijders.some(r => r.rondes != null);
    const heeftPK  = rijders.some(r => r.pk_punten != null);
    const pkMax    = heeftPK ? pkMaxRnd(rijders) : 0;
    return { heeftRnd, heeftPK, pkMax, rondeType: rondeType ?? null };
}
function heatTabelHeader(extra) {
    // Volgorde: pos, snr, FIN (direct na snr, rood weergegeven in CSS),
    // naam, ... De finishpositie was vroeger helemaal rechts en viel
    // weg in het oog; door 'm naast snr te zetten + rood te kleuren is
    // het meteen duidelijk hoe een rijder eindigde in deze heat.
    return `<tr><th class="col-pos">${t('col_pos')}</th><th class="col-snr">${t('col_snr')}</th><th class="col-fin">${t('col_fin')}</th><th class="col-naam">${t('col_naam')}</th>`
        + (extra.heeftRnd ? `<th class="col-rnd">${t('col_rnd')}</th>` : '')
        + (extra.heeftPK  ? `<th class="col-pk">${t('col_pnt')}</th>` : '')
        + `<th class="col-tijd">${t('col_tijd')}</th></tr>`;
}
function heatTabelRij(r, isIk, extra, isFamilie = false) {
    const rTijd = r.tijd_ms != null ? msTijd(r.tijd_ms) : '';
    // Fin-kolom: heat-lokale finishpositie voor finishers. Voor non-finishers
    // (DNF/DNS/DQ-*) leeg, ook als de operator toevallig een finishpositie
    // heeft ingevuld — sanctie wint. Consistent met _sorteerHeatRijders.
    const _sanctieCodes = String(r.sanctie || '').toUpperCase().split(/[,\s]+/);
    const isNonFinisher = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF']
        .some(c => _sanctieCodes.includes(c));
    const rFin = (isNonFinisher || r.finishpositie == null) ? '' : r.finishpositie;
    const rSanctie = sl(r.sanctie);
    // Bruto-audit-icoon (📷 fotofinish-wisseling, ✋ handmatige correctie):
    // toon vóór de tijd zodat de cijfers rechts-uitgelijnd blijven staan.
    // Tooltip bevat de gemeten tijd zodat coach/publiek 'm kan opvragen
    // zonder dat de tabel breder wordt.
    const heeftAudit = r.bruto_tijd_ms != null
                    && r.tijd_ms      != null
                    && r.bruto_tijd_ms !== r.tijd_ms;
    // == 1 noodzakelijk: PDO levert is_photofinish soms als string "0"/"1",
    // en "0" is truthy in JS → ternary zou altijd 📷 kiezen voor handmatige
    // RR-tijden. Loose-equality werkt cross-type ("1"==1 ✓, "0"==1 ✗).
    // Geen title-tooltip: mobiel toont die niet. Het icoontje zelf
    // signaleert dat er een correctie is toegepast; de bruto-tijd zelf
    // staat voor de eigen rijder in "Jouw resultaat" (pijl-notatie).
    const auditIcon = heeftAudit
        ? `<span class="col-tijd-audit">${r.is_photofinish == 1 ? '📷' : '✋'}</span>`
        : '';
    return `<tr class="${isIk ? 'rij-ik' : (isFamilie ? 'rij-familie' : '')}">
        <td class="col-pos">${r.startpositie}</td>
        <td class="col-snr">${esc(r.snr)}</td>
        <td class="col-fin">${esc(rFin)}</td>
        <td class="col-naam">${esc(r.full_name)}${rSanctie ? ` <span class="col-sanctie">${esc(rSanctie)}</span>` : ''}</td>`
        + (extra.heeftRnd ? `<td class="col-rnd">${r.rondes ?? ''}</td>` : '')
        + (extra.heeftPK  ? `<td class="col-pk">${pkPuntCel(r, extra.pkMax)}</td>` : '')
        + `<td class="col-tijd">${auditIcon}${esc(rTijd)}</td>
    </tr>`;
}
function msTijd(ms) {
    // Inline-skeeleren: reglementair duizendsten op alle afstanden.
    if (ms==null) return '';
    const d=ms%1000, s=Math.floor(ms/1000)%60, m=Math.floor(ms/60000);
    return m>0?`${m}:${String(s).padStart(2,'0')}.${String(d).padStart(3,'0')}`:`${s}.${String(d).padStart(3,'0')}`;
}
function sl(s) { return s ?? ''; }

// ── Multi-day programma-filter (Alle / Dag 1 / Dag 2 / …) ─────────────────
// Elk programma-item heeft data-dag-nr; we togglen .verborgen op items met
// een andere dag dan de geselecteerde. CSS verbergt die. Bij "Alle" alle
// .verborgen weghalen. Geen re-render nodig.
// Programma-rit-filter: toggle "alleen mijn ritten" of "alleen nog te
// rijden" via data-attributen op de tab-content. Onafhankelijk van elkaar
// te combineren (beide aan = alleen mijn nog-te-rijden ritten).
function filterProgRit(btn, filter) {
    const tab = btn.closest('.tab-content');
    if (!tab) return;
    const attr = filter === 'mijn' ? 'data-filter-mijn' : 'data-filter-gereden-uit';
    const actief = tab.getAttribute(attr) !== '1';
    tab.setAttribute(attr, actief ? '1' : '0');
    btn.classList.toggle('actief', actief);
    // Touch-fix: na een tap blijft :hover op mobiel hangen tot je ergens
    // anders tikt. Blur direct zodat de knop in z'n juiste rust- of
    // actief-state komt zonder lingering lichtblauwe hover.
    btn.blur();
}

// ── Programma filter-strook (dag + afstand) ────────────────────────────────
// Twee triggers, elk uitklapbaar naar een pill-balk. Na keuze klapt-ie weer
// dicht. Items met [data-dag-nr] + [data-afstand-key] worden verborgen als
// ze niet matchen. Items zonder afstand-key (blokken, dag-headers) blijven
// altijd zichtbaar bij afstand-filter.
function togglePanel(triggerBtn) {
    const strook = triggerBtn.closest('.prog-filter-strook');
    if (!strook) return;
    const key = triggerBtn.dataset.filter;
    const panel = strook.querySelector(`.prog-filter-panel[data-panel="${key}"]`);
    if (!panel) return;
    const nuOpen = !panel.classList.contains('verborgen');
    // Sluit alle andere panelen in dezelfde strook + reset trigger.open
    strook.querySelectorAll('.prog-filter-panel').forEach(p => p.classList.add('verborgen'));
    strook.querySelectorAll('.prog-filter-trigger').forEach(t => t.classList.remove('open'));
    if (!nuOpen) {
        panel.classList.remove('verborgen');
        triggerBtn.classList.add('open');
    }
    triggerBtn.blur();
}
function kiesProgFilter(type, waarde, pillBtn) {
    const strook = pillBtn.closest('.prog-filter-strook');
    if (!strook) return;
    strook.setAttribute('data-actieve-' + type, String(waarde));
    // Update actieve pill in dit paneel
    const panel = strook.querySelector(`.prog-filter-panel[data-panel="${type}"]`);
    panel?.querySelectorAll('.prog-filter-pill').forEach(p =>
        p.classList.toggle('actief', p.dataset.value === String(waarde))
    );
    // Update trigger-label
    const trigger = strook.querySelector(`.prog-filter-trigger[data-filter="${type}"]`);
    const lblEl   = trigger?.querySelector('.prog-filter-lbl');
    if (lblEl) {
        if (waarde === 'alle') {
            lblEl.textContent = type === 'dag' ? t('prog_filter_alle_dagen') : t('prog_filter_alle_afstanden');
        } else if (type === 'dag') {
            // "Dag N" + evt "· 28-5"
            const sub = pillBtn.querySelector('.prog-filter-pill-sub')?.textContent || '';
            lblEl.textContent = `${t('prog_dag')} ${waarde}${sub ? ' · ' + sub : ''}`;
        } else {
            lblEl.textContent = waarde;
        }
    }
    // Klap paneel weer in
    panel?.classList.add('verborgen');
    trigger?.classList.remove('open');
    // Bij dag-wissel: refresh het afstand-panel om alleen afstanden van
    // die dag te tonen. Als de huidige afstand-keuze niet op de nieuwe
    // dag zit → reset naar 'alle'.
    if (type === 'dag') _refreshAfstandPanel(strook);
    applyProgFilter(strook);
    pillBtn.blur();
}
function _refreshAfstandPanel(strook) {
    const afsPanel = strook.querySelector('.prog-filter-panel[data-panel="afstand"]');
    if (!afsPanel) return;
    let perDag = {};
    try { perDag = JSON.parse(strook.dataset.afsPerDag || '{}'); } catch { perDag = {}; }
    const dag = strook.getAttribute('data-actieve-dag') || 'alle';
    const beschikbaar = new Set();
    if (dag === 'alle') {
        for (const arr of Object.values(perDag)) for (const a of arr) beschikbaar.add(a);
    } else {
        for (const a of (perDag[dag] || [])) beschikbaar.add(a);
    }
    let huidigeKeuzeNogGeldig = false;
    const huidigAfs = strook.getAttribute('data-actieve-afstand') || 'alle';
    afsPanel.querySelectorAll('.prog-filter-pill').forEach(p => {
        const v = p.dataset.value;
        if (v === 'alle') { p.classList.remove('verborgen'); return; }
        const zichtbaar = beschikbaar.has(v);
        p.classList.toggle('verborgen', !zichtbaar);
        if (v === huidigAfs && zichtbaar) huidigeKeuzeNogGeldig = true;
    });
    // Reset afstand-keuze als deze niet meer geldig is
    if (huidigAfs !== 'alle' && !huidigeKeuzeNogGeldig) {
        strook.setAttribute('data-actieve-afstand', 'alle');
        const trigger = strook.querySelector('.prog-filter-trigger[data-filter="afstand"]');
        const lblEl   = trigger?.querySelector('.prog-filter-lbl');
        if (lblEl) lblEl.textContent = t('prog_filter_alle_afstanden');
        afsPanel.querySelectorAll('.prog-filter-pill').forEach(p =>
            p.classList.toggle('actief', p.dataset.value === 'alle')
        );
    }
}
function applyProgFilter(strook) {
    const dag = strook.getAttribute('data-actieve-dag') || 'alle';
    const afs = strook.getAttribute('data-actieve-afstand') || 'alle';
    // Container = het element dat ZOWEL de sticky-kop als de rit-blokken bevat.
    // Sinds de sticky-kop-wrapper zit de strook niet meer direct náást de
    // rit-blokken, dus niet strook.parentElement (= de kop) maar de kop z'n ouder.
    const container = strook.closest('.prog-sticky-kop')?.parentElement || strook.parentElement;
    if (!container) return;

    // Reset alle samenvat-modi (was ingeschakeld door vorige filter)
    container.querySelectorAll('.prog-groep.samenvat').forEach(el => {
        el.classList.remove('samenvat');
        el.querySelector('.samenvat-teller')?.remove();
        // Restore originele titel (dc-naam) — was overschreven met afstand
        const titel = el.querySelector('.prog-groep-titel');
        if (titel?.dataset.originalHtml) {
            titel.innerHTML = titel.dataset.originalHtml;
            delete titel.dataset.originalHtml;
        }
    });

    // Bij afstand-filter: voor niet-matchende groepen ÉÉN samenvat-blok
    // per (afstand × ronde-type) tonen op de plek van de eerste vindplaats,
    // de rest van dezelfde combinatie verbergen. Heat-teller = som.
    const eersteVanCombi = new Map();  // "afs|rt" -> {el, heats}

    container.querySelectorAll('[data-dag-nr]').forEach(el => {
        if (el === strook || el.classList.contains('prog-filter-strook')) return;
        const elDag = el.getAttribute('data-dag-nr');
        const elAfs = el.getAttribute('data-afstand-key');
        const elRt  = el.getAttribute('data-ronde-type');
        const dagOk = (dag === 'alle') || (elDag === String(dag));
        if (!dagOk) { el.classList.add('verborgen'); return; }
        // Geen afstand-filter, of item zonder afstand (blokken/headers), of match
        if (afs === 'alle' || !elAfs || elAfs === afs) {
            el.classList.remove('verborgen');
            return;
        }
        // Afstand-mismatch: samenvat-modus alleen op .prog-groep zelf
        // (combi-wraps en andere items met alleen afstand-key verbergen).
        if (!el.classList.contains('prog-groep')) {
            el.classList.add('verborgen');
            return;
        }
        const combiKey = `${elAfs}|${elRt || ''}`;
        const heats = el.querySelectorAll('.prog-rij').length;
        if (eersteVanCombi.has(combiKey)) {
            el.classList.add('verborgen');
            eersteVanCombi.get(combiKey).heats += heats;
        } else {
            el.classList.remove('verborgen');
            el.classList.add('samenvat');
            eersteVanCombi.set(combiKey, {el, heats});
        }
    });
    // Heat-tellers injecteren + titel vervangen door afstand-naam
    // (dc-naam is cat-specifiek, terwijl samenvat een gecombineerde
    // afstand+ronde-view is over alle cats).
    for (const {el, heats} of eersteVanCombi.values()) {
        const hdr = el.querySelector('.prog-groep-hdr');
        if (!hdr) continue;
        hdr.querySelector('.samenvat-teller')?.remove();
        // Vervang titel: behoud badge (ronde-label), vervang dc-naam
        // door de afstand-naam. Bewaar origineel voor restore.
        const titel = hdr.querySelector('.prog-groep-titel');
        const distNaam = el.getAttribute('data-afstand-key');
        if (titel && distNaam) {
            if (!titel.dataset.originalHtml) {
                titel.dataset.originalHtml = titel.innerHTML;
            }
            const badge = titel.querySelector('.heat-card-badge, .badge');
            titel.innerHTML = (badge ? badge.outerHTML : '') + ' ' + distNaam;
        }
        const span = document.createElement('span');
        span.className = 'samenvat-teller';
        const suffix = heats === 1 ? t('prog_samenvat_heat_1') : t('prog_samenvat_heat_n', {n: heats});
        span.textContent = ` · ${suffix}`;
        hdr.appendChild(span);
    }
}
// Legacy: oude onclick="filterDag(...)"-code in andere plekken kan nog naar
// deze naam wijzen. Wrapper om compatibiliteit te houden totdat we die
// oude aanroepen hebben opgeruimd.
function filterDag(btn, dag) {
    const strook = btn.closest('.prog-filter-strook, .prog-dag-filter');
    if (!strook) return;
    strook.setAttribute('data-actieve-dag', String(dag));
    applyProgFilter(strook);
}

// ── Sorteer heat-rijders voor de detail-overlay ───────────────────────────────
// Vóór de rit: startvolgorde tonen (= loting). Na de rit: finishvolgorde.
//
// Voor DEZE heat-modal bewust een simpelere aanpak dan de uitslag-verwerking:
// alle non-finishers (DNF, DNS, DQ-TF, DQ-SF, DQ-DF) worden gelijk behandeld —
// geen rit-rang (Fin-kolom is al leeg voor r.finishpositie == null), onderaan
// gesorteerd op startnummer. Wie de exacte KNSB-rang wil zien kijkt in de
// Uitslag-tab; daar zit de ronde-context (bv. DNS in eerste-of-vervolg-ronde,
// ex-aequo laatste vs out-of-ranking) al netjes in.
function _sorteerHeatRijders(rijders) {
    if (!Array.isArray(rijders) || rijders.length < 2) return rijders;

    const heeftFinishData = rijders.some(r =>
        r.finishpositie != null || r.tijd_ms != null || (r.sanctie || '').trim() !== ''
    );
    if (!heeftFinishData) return rijders;   // loting-modus: laat startvolgorde

    // Finisher = rit gereden zonder DQ/DNF/DNS, én er is een finishpositie
    // of tijd. Warnings-only (W1/W2/RR/FS) blijven finisher.
    const _isFinisher = (r) => {
        const s = String(r.sanctie || '').trim().toUpperCase();
        const heeft = code => s.split(/[,\s]+/).some(x => x === code);
        if (heeft('DNS') || heeft('DNF')
            || heeft('DQ-TF') || heeft('DQ-SF') || heeft('DQ-DF')) return false;
        return r.finishpositie != null || r.tijd_ms != null;
    };

    // Binnen non-finishers oplopende sanctie-ernst — zwaarste straf onderaan.
    // Belangrijk: DNF-slachtoffers van een DQ-SF/DQ-DF-actie horen visueel
    // bóven de dader (die zwaarder gestraft is).
    // KNSB inline volgorde van licht naar zwaar:
    //   DNF   niet gefinished (val, defect, pech)
    //   DQ-TF Technical Foul (false start, lijnsoverschrijding)
    //   DNS   bewust niet gestart
    //   DQ-SF Sporting Foul (licht contact, niet-bewust)
    //   DQ-DF Disciplinary Fault (bewuste onsportiviteit, jury-overtreding)
    const _ernst = (r) => {
        const s = String(r.sanctie || '').toUpperCase();
        const heeft = code => s.split(/[,\s]+/).some(x => x === code);
        if (heeft('DQ-DF')) return 5;
        if (heeft('DQ-SF')) return 4;
        if (heeft('DNS'))   return 3;
        if (heeft('DQ-TF')) return 2;
        if (heeft('DNF'))   return 1;
        return 0;   // onbekend / nog geen data
    };

    return [...rijders].sort((a, b) => {
        const fa = _isFinisher(a);
        const fb = _isFinisher(b);
        if (fa !== fb) return fa ? -1 : 1;   // finishers boven

        if (fa) {
            // Beide finishers: finishpositie → tijd → startvolgorde (tie-break)
            const pa = a.finishpositie ?? Infinity;
            const pb = b.finishpositie ?? Infinity;
            if (pa !== pb) return pa - pb;
            const ta = a.tijd_ms ?? Infinity;
            const tb = b.tijd_ms ?? Infinity;
            if (ta !== tb) return ta - tb;
            return (a.startpositie ?? 999) - (b.startpositie ?? 999);
        }
        // Beide non-finishers: eerst op ernst (minder erg boven), dan snr
        const ea = _ernst(a), eb = _ernst(b);
        if (ea !== eb) return ea - eb;
        const sa = parseInt(a.snr) || 99999;
        const sb = parseInt(b.snr) || 99999;
        return sa - sb;
    });
}

// ── Rit-detail overlay ────────────────────────────────────────────────────────
async function toonRitDetail(el) {
    const ritNaam = el.dataset.ritNaam;
    const dcNaam = el.dataset.dcNaam;
    const compId = selComp.value;
    const snr = inpSnr.value.trim();
    // License_key van het actieve kind/rijder voor row-highlight in heat-tabel.
    // snr alleen zou bij twee rijders met zelfde nr beide rijen highlighten.
    const actiefKind = (typeof _kinderen !== 'undefined') ? _kinderen[_activeKindIdx] : null;
    const actiefLic = actiefKind?.data?.[actiefKind?.kozen_idx ?? 0]?.persoon?.license_key || null;
    // License_keys van de ándere gevolgde kinderen — voor de violette markering
    // in deze startlijst. Lokaal uit _kinderen; de startlijst zelf wordt tóch al
    // opgehaald voor het detail, dus geen extra dataverkeer.
    const familieLics = new Set();
    if (typeof _kinderen !== 'undefined' && _kinderen.length > 1) {
        for (const _k of _kinderen) {
            const _lic = _k.data?.[_k.kozen_idx ?? 0]?.persoon?.license_key;
            if (_lic && _lic !== actiefLic) familieLics.add(_lic);
        }
    }
    if (!ritNaam || !compId) return;

    // Overlay aanmaken
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = `<div class="overlay-box"><div style="padding:24px;text-align:center"><span class="spinner"></span> ${t('msg_laden')}</div></div>`;
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);

    try {
        const res = await safeFetch(`?action=rit_detail&competition_id=${encodeURIComponent(compId)}&rit_naam=${encodeURIComponent(ritNaam)}&dc_naam=${encodeURIComponent(dcNaam)}`);
        const data = await res.json();

        if (!data.heat || !data.heat.rijders?.length) {
            overlay.querySelector('.overlay-box').innerHTML = `
                <div class="heat-card-titel"><button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>${esc(ritNaam)}</div>
                <div style="padding:20px;text-align:center;color:#888">${t('msg_geen_startlijst')}</div>`;
            return;
        }

        const h = data.heat;
        const rt = h.ronde_type ?? 'heats';
        const extra = heatExtraKolommen(h.rijders ?? [], rt);
        // Sorteren: startvolgorde vóór de rit, finishvolgorde erna. Detect
        // op "iemand heeft een finishpositie/tijd of sanctie" — anders zijn
        // we in loting-modus en houden startvolgorde consistent.
        const gesorteerdeRijders = _sorteerHeatRijders(h.rijders ?? []);
        let rows = '';
        for (const r of gesorteerdeRijders) {
            // Match op license_key (uniek), fallback op snr voor backwards-
            // compat met oude cached payloads die nog geen license meegeven.
            const isHuidig = (actiefLic && r.license_key)
                ? r.license_key === actiefLic
                : String(r.snr) === snr;
            const isFamilie = !isHuidig && r.license_key && familieLics.has(r.license_key);
            rows += heatTabelRij(r, isHuidig, extra, isFamilie);
        }

        overlay.querySelector('.overlay-box').innerHTML = `
            <div class="heat-card" style="border:none;border-radius:12px">
                <div class="heat-card-titel" style="border-radius:12px 12px 0 0">
                    <button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>
                    <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                    ${esc(h.rit_naam ?? h.heat_naam)}
                </div>
                <table class="heat-card-tabel">
                <thead>${heatTabelHeader(extra)}</thead>
                <tbody>${rows}</tbody>
                </table>
            </div>`;
    } catch (e) {
        overlay.querySelector('.overlay-box').innerHTML = `<div style="padding:20px;color:#c00">${esc(t('err_prefix', {msg: e.message}))}</div>`;
    }
}

// Wedstrijden laden + filteren
// Drie onafhankelijke filters: Oude · Vandaag · Toekomstige. Wedstrijd
// verschijnt als hij in tenminste één van de aangevinkte categorieën valt.
// Geen filter aangevinkt → lege lijst met helder bericht.
