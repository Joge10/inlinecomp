// ============================================================
//  Print-Center — centrale print-dispatcher
//  Fase 1: statische items (tekenlijst, deelnemerslijst, programma)
//  Fase 2: dynamische startlijsten + uitslagen (volgt)
//  Fase 3: boom-saver layout-optimalisatie (volgt)
//
//  Aanroep: vanuit header-knop in een wedstrijd-context
// ============================================================

// ── Sessie-state ────────────────────────────────────────────────────────────
// Reset bij nieuwe wedstrijd-selectie (via import-module).
// `lang` wordt NIET gereset bij wedstrijd-wissel — operator's keuze blijft
// voor de hele sessie staan (en wordt persistent opgeslagen in localStorage
// zodat-ie ook na hard-refresh terugkomt).
let _pcState = {
    compId:     null,   // competition_id waar de huidige state bij hoort
    geselecteerd: new Set(),  // string-IDs van aangevinkte opties
    boomSaver:  false,
    lang:       (typeof localStorage !== 'undefined'
                 && localStorage.getItem('printcenter_lang') === 'en') ? 'en' : 'nl',
    tijdschemaBeschikbaar: null,  // null=onbekend, true/false na check
    startlijstenLaad: null,       // null=onbekend, true tijdens laden, false=klaar
    uitslagenLaad:    null,
    // Globale multi-day filter ('alles' | dagNr) die op ALLE secties tegelijk
    // werkt. Default 'alles'. Een gekozen dag toont items met dag===N +
    // cross-day items (dag==='all' of dag===undefined). Verschijnt alleen bij
    // multi-day events (>1 wedstrijdstart-blok).
    dagFilter: 'alles',
};

// ── i18n voor print-templates ───────────────────────────────────────────────
// Centrale dict van alle strings die in print-rapporten verschijnen. Per
// template-categorie een sub-key zodat het overzichtelijk blijft. Vertalingen
// per taal — EN voor internationale wedstrijden.
//
// Gebruik in template-bouwers:
//   const T = window._pcT || (k => k);
//   const titel = T('prog_extern.titel', { naam: comp.name });
//
// Placeholder-substitutie: {key} in de string wordt vervangen door vars[key].
const _PC_I18N = {
    nl: {
        // ── Algemene labels (kop, voetnoten) ──────────────────────────────
        'algemeen.wedstrijd':            'Wedstrijd',
        'algemeen.datum':                'Datum',
        'algemeen.locatie':              'Locatie',
        'algemeen.organisatie':          'Organisatie',
        'algemeen.afstand':              'Afstand',
        'algemeen.categorie':            'Categorie',
        'algemeen.aantal_deelnemers':    'Aantal deelnemers',
        'algemeen.heat':                 'Heat',
        'algemeen.heat_nr':              'Heat {nr}',
        'algemeen.serie':                'Serie',
        'algemeen.kwart_finale':         'Kwartfinale',
        'algemeen.halve_finale':         'Halve finale',
        'algemeen.a_finale':             'A-finale',
        'algemeen.b_finale':             'B-finale',
        'algemeen.runner_up':            'Runner-up',
        'algemeen.pauze':                'Pauze',
        'algemeen.inrijden':             'Inrijden',
        'algemeen.ceremonie':            'Ceremonie',
        'algemeen.wedstrijdstart':       'Wedstrijd start',
        'algemeen.herstart':             'Herstart',
        'algemeen.start':                'Start',
        'algemeen.tijd':                 'Tijd',
        'algemeen.startnummer':          'Startnummer',
        'algemeen.naam':                 'Naam',
        'algemeen.club':                 'Club',
        'algemeen.transponder':          'Transponder',
        'algemeen.handtekening':         'Handtekening',
        'algemeen.dag':                  'Dag',
        'algemeen.dag_n':                'Dag {nr}',
        'algemeen.finale':               'Finale',
        'algemeen.rijders_n':            '{n} rijders',
        'algemeen.heat_n':               '{n} heat',
        'algemeen.heats_n':              '{n} heats',
        'algemeen.min_unit':             '{n} min',
        'algemeen.deelnemer_1':          '{n} deelnemer',
        'algemeen.deelnemers_n':         '{n} deelnemers',
        'algemeen.stand_op':             'Stand: {datum}',
        'algemeen.pagina_x_van_y':       'pagina {x} van {y}',
        'algemeen.vervolg':              'vervolg',
        'algemeen.overig':               'overig',
        'algemeen.geen_split':           '(geen split)',
        'algemeen.afgedrukt':            'afgedrukt: {datum}',
        // ── Programma extern (deelnemers/publiek) ─────────────────────────
        'prog_extern.titel':             'Wedstrijdprogramma',
        'prog_extern.gegen_op':          'Gegenereerd op {datum}',
        'prog_extern.subtype':           'Wedstrijdprogramma',
        'prog_extern.titel_default':     'Wedstrijdprogramma',
        'prog_extern.gegen_op_label':    'Programma gegenereerd op: {datum}',
        'prog_extern.disclaimer':        '⚠ De vermelde starttijden zijn <strong>indicatieve richtijden</strong>. De werkelijke uitvoering kan afwijken van dit programma. Houd zelf het verloop van het programma in de gaten — je bent zelf verantwoordelijk voor het op tijd aan de start verschijnen.',
        'prog_extern.combi_kop':         '🔗 Gecombineerde rit — {n} categorieën rijden samen',
        'prog_extern.qq_voetnoot':       '<b>¹</b> <b>Q</b> = directe kwalificatie via heat-positie · <b>q</b> = aanvulling op tijd (snelste tijden over alle heats)',
        'prog_extern.heats_x_dur':       '{heats} × {dur} ≈ {tot} min',
        'prog_extern.top_n_op_tijd':     'top {n} op tijd',
        'prog_extern.qheat_q_door':      '{Q}Q/heat + {q}q{m} → {d} rijders',
        'prog_extern.ff_q_naar_a':       '{Qph}Q/heat + {extra}q{m} → {finaleDeel}{bDeel}',
        'prog_extern.ff_tijd_naar_a':    'top {aFin} op tijd → {finaleDeel}{bDeel}',
        'prog_extern.ff_a_finale_q':     '{a_finale} ({aQ}Q + {aq}q)',
        'prog_extern.ff_a_finale_n':     '{a_finale} ({aFin})',
        'prog_extern.b_finale_suffix':   ' + {b_finale}',
        'prog_extern.b_finales_suffix':  ' + {b_finale}s',
        'prog_extern.finale_label_n':    '{lbl}-{finale} ({n} rijders)',
        'prog_extern.wsstart':           '🏁 WEDSTRIJD START',
        'prog_extern.herstart':          '🔄 HERSTART',
        // ── Programma intern (organisatie) ────────────────────────────────
        'prog_intern.titel':             'Intern programma',
        // ── Tekenlijsten ──────────────────────────────────────────────────
        'tekenlijst.titel':              'Tekenlijst',
        'tekenlijst.titel_meervoud':     'Tekenlijsten',
        'tekenlijst.col_startnr':        'Start#',
        'tekenlijst.col_correctie':      'Correctie',
        'tekenlijst.geen_tp':            'Geen transponder',
        'tekenlijst.startnr_n':          'Startnr. {nr}',
        'tekenlijst.tp_niet_betaald':    'Transponder niet betaald',
        'tekenlijst.melding':            'melding',
        'tekenlijst.persoonlijk_melden': 'persoonlijk melden',
        // ── Deelnemerslijsten ─────────────────────────────────────────────
        'deelnemers.titel':              'Deelnemerslijst',
        'deelnemers.titel_meervoud':     'Deelnemerslijst',
        'deelnemers.col_cat_kort':       'Cat',
        'deelnemers.col_transp_kort':    'Transp',
        'deelnemers.col_transp_invul':   'Transponder (invullen)',
        'deelnemers.col_org_nr':         'Org #',
        'deelnemers.col_betaald':        'Betaald',
        'deelnemers.col_groep':          'Groep',
        'deelnemers.col_afstanden':      'Afstanden',
        'deelnemers.col_status':         'Status',
        'deelnemers.col_tp_knsb':        'Transponder KNSB',
        'deelnemers.col_tp_gebruikt':    'Transponder gebruikt',
        'deelnemers.col_knsb':           'KNSB',
        'deelnemers.col_gebruikt':       'Gebruikt',
        'deelnemers.col_race_groep':     'Race-groep',
        'deelnemers.col_aantal':         'Aantal',
        'deelnemers.sec_geen_tp':        'Geen transponder geregistreerd',
        'deelnemers.sec_org_tp':         'Met organisatie-transponder',
        'deelnemers.sec_org_added':      'Door organisatie toegevoegd',
        'deelnemers.sec_afwezig':        'Niet aanwezig / afgemeld',
        'deelnemers.sec_tp_wijz':        'Transponder aanpassingen tov KNSB',
        'deelnemers.sec_sn_wijz':        'Startnummer aanpassingen tov KNSB',
        'deelnemers.sec_overzicht':      'Race-groepen overzicht',
        'deelnemers.teller_groepen':     '{n} groepen',
        'deelnemers.status_niet_bev':    'Niet bevestigd',
        'deelnemers.status_afgemeld':    'Afgemeld',
        'deelnemers.status_afg_org':     'Afgem. bij org.',
        'deelnemers.status_niet_get':    'Niet getekend',
        // ── Speakerlijsten ────────────────────────────────────────────────
        'speaker.titel':                 'Speakerlijst',
        'speaker.titel_meervoud':        'Speakerlijsten',
        'speaker.col_sponsor':           'Sponsor',
        'speaker.col_notities':          'Notities',
        'speaker.geen_dcs':              'Geen niet-sprint DCs met actieve deelnemers gevonden (sprint-DCs worden overgeslagen).',
        // ── Uitslagen ─────────────────────────────────────────────────────
        'uitslag.titel':                 'Uitslag',
        'uitslag.rang':                  'Rang',
        'uitslag.positie':               'Positie',
        'uitslag.eindtijd':              'Eindtijd',
        'uitslag.punten':                'Punten',
        'uitslag.sanctie':               'Sanctie',
        // ── Klassementen ──────────────────────────────────────────────────
        'klassement.titel':              'Klassement',
        'klassement.tussen':             'Tussenklassement',
        'klassement.eind':               'Eindklassement',
    },
    en: {
        'algemeen.wedstrijd':            'Race',
        'algemeen.datum':                'Date',
        'algemeen.locatie':              'Venue',
        'algemeen.organisatie':          'Organisation',
        'algemeen.afstand':              'Distance',
        'algemeen.categorie':            'Category',
        'algemeen.aantal_deelnemers':    'Number of skaters',
        'algemeen.heat':                 'Heat',
        'algemeen.heat_nr':              'Heat {nr}',
        'algemeen.serie':                'Series',
        'algemeen.kwart_finale':         'Quarter-final',
        'algemeen.halve_finale':         'Semi-final',
        'algemeen.a_finale':             'A-final',
        'algemeen.b_finale':             'B-final',
        'algemeen.runner_up':            'Runner-up',
        'algemeen.pauze':                'Break',
        'algemeen.inrijden':             'Warm-up',
        'algemeen.ceremonie':            'Ceremony',
        'algemeen.wedstrijdstart':       'Race start',
        'algemeen.herstart':             'Restart',
        'algemeen.start':                'Start',
        'algemeen.tijd':                 'Time',
        'algemeen.startnummer':          'Bib',
        'algemeen.naam':                 'Name',
        'algemeen.club':                 'Club',
        'algemeen.transponder':          'Transponder',
        'algemeen.handtekening':         'Signature',
        'algemeen.dag':                  'Day',
        'algemeen.dag_n':                'Day {nr}',
        'algemeen.finale':               'Final',
        'algemeen.rijders_n':            '{n} skaters',
        'algemeen.heat_n':               '{n} heat',
        'algemeen.heats_n':              '{n} heats',
        'algemeen.min_unit':             '{n} min',
        'algemeen.deelnemer_1':          '{n} skater',
        'algemeen.deelnemers_n':         '{n} skaters',
        'algemeen.stand_op':             'As of: {datum}',
        'algemeen.pagina_x_van_y':       'page {x} of {y}',
        'algemeen.vervolg':              'continued',
        'algemeen.overig':               'other',
        'algemeen.geen_split':           '(no split)',
        'algemeen.afgedrukt':            'printed: {datum}',
        'prog_extern.titel':             'Race programme',
        'prog_extern.gegen_op':          'Generated on {datum}',
        'prog_extern.subtype':           'Race programme',
        'prog_extern.titel_default':     'Race programme',
        'prog_extern.gegen_op_label':    'Programme generated on: {datum}',
        'prog_extern.disclaimer':        '⚠ The published start times are <strong>indicative only</strong>. Actual execution may differ from this programme. Follow the running of the programme yourself — you are responsible for being at the start on time.',
        'prog_extern.combi_kop':         '🔗 Combined race — {n} categories racing together',
        'prog_extern.qq_voetnoot':       '<b>¹</b> <b>Q</b> = direct qualification via heat position · <b>q</b> = additional on time (fastest times across all heats)',
        'prog_extern.heats_x_dur':       '{heats} × {dur} ≈ {tot} min',
        'prog_extern.heats_simpel':      '{n} {heats}',
        'prog_extern.top_n_op_tijd':     'top {n} on time',
        'prog_extern.qheat_q_door':      '{Q}Q/heat + {q}q{m} → {d} skaters',
        'prog_extern.ff_q_naar_a':       '{Qph}Q/heat + {extra}q{m} → {finaleDeel}{bDeel}',
        'prog_extern.ff_tijd_naar_a':    'top {aFin} on time → {finaleDeel}{bDeel}',
        'prog_extern.ff_a_finale_q':     '{a_finale} ({aQ}Q + {aq}q)',
        'prog_extern.ff_a_finale_n':     '{a_finale} ({aFin})',
        'prog_extern.b_finale_suffix':   ' + {b_finale}',
        'prog_extern.b_finales_suffix':  ' + {b_finale}s',
        'prog_extern.finale_label_n':    '{lbl}-{finale} ({n} skaters)',
        'prog_extern.wsstart':           '🏁 RACE START',
        'prog_extern.herstart':          '🔄 RESTART',
        'prog_intern.titel':             'Internal programme',
        'tekenlijst.titel':              'Sign-in list',
        'tekenlijst.titel_meervoud':     'Sign-in lists',
        'tekenlijst.col_startnr':        'Bib',
        'tekenlijst.col_correctie':      'Correction',
        'tekenlijst.geen_tp':            'No transponder',
        'tekenlijst.startnr_n':          'Bib {nr}',
        'tekenlijst.tp_niet_betaald':    'Transponder unpaid',
        'tekenlijst.melding':            'report',
        'tekenlijst.persoonlijk_melden': 'report in person',
        'deelnemers.titel':              'Skater list',
        'deelnemers.titel_meervoud':     'Skater list',
        'deelnemers.col_cat_kort':       'Cat',
        'deelnemers.col_transp_kort':    'Transp',
        'deelnemers.col_transp_invul':   'Transponder (fill in)',
        'deelnemers.col_org_nr':         'Org #',
        'deelnemers.col_betaald':        'Paid',
        'deelnemers.col_groep':          'Group',
        'deelnemers.col_afstanden':      'Distances',
        'deelnemers.col_status':         'Status',
        'deelnemers.col_tp_knsb':        'KNSB transponder',
        'deelnemers.col_tp_gebruikt':    'Transponder used',
        'deelnemers.col_knsb':           'KNSB',
        'deelnemers.col_gebruikt':       'Used',
        'deelnemers.col_race_groep':     'Race group',
        'deelnemers.col_aantal':         'Count',
        'deelnemers.sec_geen_tp':        'No transponder registered',
        'deelnemers.sec_org_tp':         'With organisation transponder',
        'deelnemers.sec_org_added':      'Added by organisation',
        'deelnemers.sec_afwezig':        'Absent / withdrawn',
        'deelnemers.sec_tp_wijz':        'Transponder changes vs KNSB',
        'deelnemers.sec_sn_wijz':        'Bib changes vs KNSB',
        'deelnemers.sec_overzicht':      'Race groups overview',
        'deelnemers.teller_groepen':     '{n} groups',
        'deelnemers.status_niet_bev':    'Not confirmed',
        'deelnemers.status_afgemeld':    'Withdrawn',
        'deelnemers.status_afg_org':     'Withdrawn at org.',
        'deelnemers.status_niet_get':    'Not signed in',
        'speaker.titel':                 'Speaker list',
        'speaker.titel_meervoud':        'Speaker lists',
        'speaker.col_sponsor':           'Sponsor',
        'speaker.col_notities':          'Notes',
        'speaker.geen_dcs':              'No non-sprint DCs with active skaters (sprint DCs are skipped).',
        'uitslag.titel':                 'Results',
        'uitslag.rang':                  'Rank',
        'uitslag.positie':               'Position',
        'uitslag.eindtijd':              'Final time',
        'uitslag.punten':                'Points',
        'uitslag.sanctie':               'Penalty',
        'klassement.titel':              'Standings',
        'klassement.tussen':             'Intermediate standings',
        'klassement.eind':               'Final standings',
    },
};

// I18n-helper voor print-templates. Gebruik via window._pcT zodat templates
// in andere modules (tijdschema.js, uitslag.js, startlist.js) erbij kunnen.
// Fallback naar NL als sleutel ontbreekt in EN, of naar de sleutel zelf als
// die ook in NL ontbreekt (developer-fout maar geen crash).
function _pcT(key, vars = null) {
    const lang = _pcState?.lang || 'nl';
    let s = _PC_I18N[lang]?.[key] ?? _PC_I18N.nl[key] ?? key;
    if (vars) {
        for (const [k, v] of Object.entries(vars)) {
            s = s.replace(new RegExp(`\\{${k}\\}`, 'g'), v);
        }
    }
    return s;
}
window._pcT = _pcT;
window._pcLang = () => _pcState?.lang || 'nl';

// Cache: ontkoppelde optData die we moeten kunnen terugvinden bij print-tijd.
// Key = item.id (stable string), value = optData-object dat naar de body-builder
// gaat. Nieuw opgebouwd bij elke re-populatie.
const _pcItemData = new Map();

// Wordt door import.js aangeroepen bij selectie van (andere) wedstrijd.
function printCenterResetVoorWedstrijd(compId) {
    // Behoud lang-voorkeur over wedstrijd-wissel heen — fallback naar
    // localStorage als _pcState nog niet bestaat (eerste sessie).
    const _bewaardeLang = (_pcState && _pcState.lang)
        || (typeof localStorage !== 'undefined'
            && localStorage.getItem('printcenter_lang') === 'en' ? 'en' : 'nl');
    _pcState = {
        compId:        compId ?? null,
        geselecteerd:  new Set(),
        boomSaver:     false,
        lang:          _bewaardeLang,
        tijdschemaBeschikbaar: null,
        startlijstenLaad:      null,
        uitslagenLaad:         null,
        dagFilter:             'alles',
    };
    _pcItemData.clear();
    _pcCatCodeMap = null;

    // Externe caches opruimen zodat vulPrintSelect / vulUitslagPrintSelect
    // verse data laden voor de nieuwe wedstrijd. Zonder deze reset zouden
    // _slGroepen / _uGroepen / huidigTijdschema van de vorige wedstrijd
    // blijven hangen en kreeg je bij Print-Center stale data te zien.
    try {
        if (typeof huidigTijdschema  !== 'undefined') huidigTijdschema  = null;
        if (typeof _slGroepen        !== 'undefined') _slGroepen        = [];
        if (typeof _uGroepen         !== 'undefined') _uGroepen         = [];
        if (typeof _slPrintOpties    !== 'undefined') _slPrintOpties    = new Map();
        if (typeof _uPrintOpties     !== 'undefined') _uPrintOpties     = new Map();
        // Startlijst-module cachet ook de loting-status van de API
        if (typeof invalideerSlStatus === 'function') invalideerSlStatus();
    } catch (e) { console.warn('[PC] cache-reset:', e); }

    const btn = document.getElementById('btn-printcenter');
    if (btn) btn.disabled = !compId;
}
// Maak globaal bereikbaar voor import.js
window.printCenterResetVoorWedstrijd = printCenterResetVoorWedstrijd;

// Granulaire invalidatie — aan te roepen vanuit andere modules zodra ze de
// onderliggende data muteren. Voorkomt dat het Print-Center stale opties
// blijft tonen tot de operator de pagina handmatig refresht.
//
// Roep deze aan na: uitslag vastleggen, klassement vastleggen, klassement
// terugtrekken — alles wat de "wat is printbaar?"-set verandert.
function printCenterInvalideerUitslagen() {
    _pcState.uitslagenLaad = null;
    try {
        if (typeof _uPrintOpties !== 'undefined') _uPrintOpties = new Map();
    } catch { /* uitslag.js niet geladen — geen probleem */ }
    // Items die al getoond worden in een open modal moeten ook opnieuw — als
    // de modal nu open is, herbouwen we 'm direct.
    const modal = document.getElementById('pc-modal');
    if (modal && modal.classList.contains('pc-open')) {
        // Re-trigger het laad-pad door openPrintCenter opnieuw aan te roepen.
        // openPrintCenter() ziet uitslagenLaad === null en doet de fetch +
        // re-render. Modal blijft visueel zichtbaar, geen flikker.
        if (typeof openPrintCenter === 'function') openPrintCenter();
    }
}
window.printCenterInvalideerUitslagen = printCenterInvalideerUitslagen;

// Spiegel-functie voor startlijsten — aanroepen na loting-genereren/wissen.
function printCenterInvalideerStartlijsten() {
    _pcState.startlijstenLaad = null;
    try {
        if (typeof _slPrintOpties !== 'undefined') _slPrintOpties = new Map();
        if (typeof invalideerSlStatus === 'function') invalideerSlStatus();
    } catch { /* startlist.js niet geladen */ }
    const modal = document.getElementById('pc-modal');
    if (modal && modal.classList.contains('pc-open')) {
        if (typeof openPrintCenter === 'function') openPrintCenter();
    }
}
window.printCenterInvalideerStartlijsten = printCenterInvalideerStartlijsten;

// ── Modal openen ────────────────────────────────────────────────────────────
async function openPrintCenter() {
    if (!_pcState.compId) return;
    let modal = document.getElementById('pc-modal');
    if (!modal) {
        modal = _pcBouwModal();
        document.body.appendChild(modal);
    }
    _pcRenderInhoud();   // eerste render (met "laden…" voor delen die nog niet bekend zijn)
    modal.classList.add('pc-open');

    // Parallel: laad tijdschema, startlijst-opties en uitslag-opties op de
    // achtergrond. Re-render na elke afgeronde laad-actie.
    const tasks = [];

    if (_pcState.tijdschemaBeschikbaar === null) {
        tasks.push((async () => {
            await _pcZorgTijdschemaGeladen();
            _pcState.tijdschemaBeschikbaar =
                typeof huidigTijdschema !== 'undefined' && !!huidigTijdschema;
            _pcRenderInhoud();
        })());
    }

    if (_pcState.startlijstenLaad === null) {
        _pcState.startlijstenLaad = true;
        tasks.push((async () => {
            try {
                if (typeof vulPrintSelect === 'function') await vulPrintSelect();
            } catch (e) { console.warn('[PC] Startlijsten laden mislukt:', e); }
            _pcState.startlijstenLaad = false;
            _pcRenderInhoud();
        })());
    }

    if (_pcState.uitslagenLaad === null) {
        _pcState.uitslagenLaad = true;
        tasks.push((async () => {
            try {
                if (typeof vulUitslagPrintSelect === 'function') await vulUitslagPrintSelect();
            } catch (e) { console.warn('[PC] Uitslagen laden mislukt:', e); }
            _pcState.uitslagenLaad = false;
            _pcRenderInhoud();
        })());
    }

    // Laat tasks op achtergrond lopen; openPrintCenter wacht er niet op
    // (de render doet het werk asynchroon)
    void tasks;
}

function sluitPrintCenter() {
    document.getElementById('pc-modal')?.classList.remove('pc-open');
}

function _pcBouwModal() {
    const d = document.createElement('div');
    d.id = 'pc-modal';
    d.className = 'pc-overlay';
    d.innerHTML = `
        <div class="pc-dialog" role="dialog" aria-labelledby="pc-titel">
            <div class="pc-kop">
                <h2 id="pc-titel">🖨 Print-Center</h2>
                <button class="pc-sluit" id="pc-btn-sluit" title="Sluiten">×</button>
            </div>
            <div class="pc-toolbar">
                <label class="pc-check" title="Prints combineren op minder papier">
                    <input type="checkbox" id="pc-boomsaver">
                    <span>🌳 Boom-saver</span>
                </label>
                <div class="pc-lang" title="Taal van de geprinte rapporten — keuze blijft bewaard ook na hard refresh">
                    <span>Taal:</span>
                    <label><input type="checkbox" data-pc-lang="nl" id="pc-lang-nl"> NL</label>
                    <label><input type="checkbox" data-pc-lang="en" id="pc-lang-en"> GB</label>
                </div>
                <div class="pc-toolbar-rechts">
                    <button class="btn-secondary" id="pc-btn-reset">Alles uit</button>
                    <button class="btn-primary"   id="pc-btn-print" disabled>🖨 Print selectie</button>
                </div>
            </div>
            <div class="pc-inhoud" id="pc-inhoud">
                <div class="pc-laden">Laden…</div>
            </div>
        </div>`;
    // Event listeners
    d.querySelector('#pc-btn-sluit').addEventListener('click', sluitPrintCenter);
    d.addEventListener('click', e => { if (e.target === d) sluitPrintCenter(); });
    d.querySelector('#pc-boomsaver').addEventListener('change', e => {
        _pcState.boomSaver = e.target.checked;
    });
    // Initialiseer checkboxes op huidige _pcState.lang (uit localStorage).
    // Twee checkboxes met mutual-exclusion-JS — visueel vierkant (zoals
    // boom-saver) ipv ronde radio-buttons. Eén kan altijd actief; klikken
    // op de andere wisselt.
    d.querySelector(`#pc-lang-${_pcState.lang}`).checked = true;
    d.querySelectorAll('input[data-pc-lang]').forEach(cb => {
        cb.addEventListener('change', e => {
            const gekozen = e.target.dataset.pcLang;
            if (e.target.checked) {
                // Andere uitvinken
                d.querySelectorAll('input[data-pc-lang]').forEach(other => {
                    if (other !== e.target) other.checked = false;
                });
                _pcState.lang = gekozen;
                try { localStorage.setItem('printcenter_lang', _pcState.lang); }
                catch (err) { /* private browsing → stil falen */ }
            } else {
                // Niet uitvinken — minimaal één moet aan staan
                e.target.checked = true;
            }
        });
    });
    d.querySelector('#pc-btn-reset').addEventListener('click', () => {
        _pcState.geselecteerd.clear();
        _pcRenderInhoud();
    });
    d.querySelector('#pc-btn-print').addEventListener('click', _pcStartPrint);
    return d;
}

// ── Definitie van beschikbare print-opties (Fase 1) ─────────────────────────
// Elke optie heeft:
//   id        : unieke string (wordt in state bewaard)
//   label     : tekst voor de checkbox
//   sectie    : groep waarin de optie valt
//   builder   : async functie die {bodyHtml, headExtra, title} teruggeeft,
//               of null → dan valt-ie terug op direct-print-via-callback
//   direct    : optionele functie (map compId) die zelf een print-venster
//               opent (tijdelijk t.b.v. fase 1 vóór extractie is gedaan)
// In Fase 1 gebruiken we `direct` voor terugval; zodra body-builders klaar
// zijn vervangen we dit door `builder`.
function _pcOpties() {
    // Multi-day detectie via tijdschema (als geladen). Bij >1 wedstrijdstart
    // tonen we per dag een extra tekenlijst + deelnemerslijst item zodat
    // operator op de wedstrijddag een gerichte print kan maken.
    const _ts        = typeof huidigTijdschema !== 'undefined' ? huidigTijdschema : null;
    const _dagInfo   = (_ts && typeof _tsBouwDagInfo === 'function') ? _tsBouwDagInfo(_ts.blokken ?? []) : null;
    const _isMulti   = !!_dagInfo?.isMultiDag;
    const _dagLabels = _dagInfo?.dagLabels ?? [];

    // Tekenlijst- en deelnemerslijst-items per dag bij multi-day. Bij single-
    // day wordt deze array leeg en blijven alleen de standaard 'alle dagen'-
    // items zichtbaar.
    const dagTekenItems = _isMulti
        ? _dagLabels.map(d => ({
              id:     `tekenlijsten-dag-${d.nr}`,
              label:  `Tekenlijsten — ${d.label}`,
              dag:    d.nr,
              beschikbaar:          _pcImportKlaar(),
              redenNietBeschikbaar: _pcImportReden(),
              build: () => (typeof bouwTekenlijstenBody === 'function'
                            ? bouwTekenlijstenBody({ boomSaver: !!_pcState.boomSaver, dagFilter: d.nr })
                            : null),
          }))
        : [];
    const dagDeelnItems = _isMulti
        ? _dagLabels.map(d => ({
              id:     `deelnemerslijst-dag-${d.nr}`,
              label:  `Deelnemerslijst — ${d.label}`,
              dag:    d.nr,
              beschikbaar:          _pcImportKlaar(),
              redenNietBeschikbaar: _pcImportReden(),
              build: () => (typeof bouwDeelnemerslijstBody === 'function'
                            ? bouwDeelnemerslijstBody({ dagFilter: d.nr })
                            : null),
          }))
        : [];

    const opties = [
        {
            sectie: 'Voorbereiding',
            items: [
                {
                    id:     'tekenlijsten',
                    label:  _isMulti
                        ? 'Tekenlijsten — alle dagen'
                        : 'Tekenlijsten (per categorie)',
                    dag:    _isMulti ? 'all' : undefined,
                    beschikbaar:          _pcImportKlaar(),
                    redenNietBeschikbaar: _pcImportReden(),
                    build: () => (typeof bouwTekenlijstenBody === 'function'
                                  ? bouwTekenlijstenBody({ boomSaver: !!_pcState.boomSaver })
                                  : null),
                },
                ...dagTekenItems,
                {
                    id:     'deelnemerslijst',
                    label:  _isMulti
                        ? 'Deelnemerslijst — alle dagen'
                        : 'Deelnemerslijst (compleet overzicht)',
                    dag:    _isMulti ? 'all' : undefined,
                    beschikbaar:          _pcImportKlaar(),
                    redenNietBeschikbaar: _pcImportReden(),
                    build: () => (typeof bouwDeelnemerslijstBody === 'function' ? bouwDeelnemerslijstBody() : null),
                },
                ...dagDeelnItems,
                {
                    id:     'speakerlijsten',
                    label:  'Speakerlijsten',
                    // Speakerlijsten zijn cross-day (alle DCs in één document).
                    // Blijven bij dag-filter zichtbaar als 'all'.
                    dag:    _isMulti ? 'all' : undefined,
                    beschikbaar:          _pcImportKlaar(),
                    redenNietBeschikbaar: _pcImportReden(),
                    build: () => (typeof bouwSpeakerlijstenBody === 'function' ? bouwSpeakerlijstenBody() : null),
                },
            ],
        },
        {
            sectie: 'Planning',
            items: [
                {
                    id:     'programma-extern',
                    label:  'Programma extern (deelnemers/publiek)',
                    // Programma's bevatten alle dagen in één document met
                    // page-break per dag — cross-day item.
                    dag:    _isMulti ? 'all' : undefined,
                    beschikbaar: _pcState.tijdschemaBeschikbaar,
                    redenNietBeschikbaar: 'Nog geen tijdschema gemaakt',
                    build: async () => {
                        await _pcZorgTijdschemaGeladen();
                        return (typeof bouwProgrammaExternBody === 'function'
                            ? bouwProgrammaExternBody() : null);
                    },
                },
                {
                    id:     'programma-intern',
                    label:  'Programma intern (organisatie)',
                    dag:    _isMulti ? 'all' : undefined,
                    beschikbaar: _pcState.tijdschemaBeschikbaar,
                    redenNietBeschikbaar: 'Nog geen tijdschema gemaakt',
                    build: async () => {
                        await _pcZorgTijdschemaGeladen();
                        return (typeof bouwProgrammaInternBody === 'function'
                            ? bouwProgrammaInternBody() : null);
                    },
                },
            ],
        },
        _pcBouwStartlijstenSectie(),
        _pcBouwUitslagenSectie(),
    ];
    return opties;
}

// Sort-volgorde van ronde-types binnen een afstand (programma-volgorde).
const _PC_RONDE_VOLGORDE = {
    heats: 1, kwartfinale: 2, halve_finale: 3,
    finale_a: 4, finale_b: 5, full_final_finales: 4, runner_up: 4.5,
};

// Natuurlijke sorteer-vergelijking (zodat "500m" < "1000m" correct gaat)
function _pcNatCmp(a, b) {
    return String(a ?? '').localeCompare(String(b ?? ''), undefined,
        { numeric: true, sensitivity: 'base' });
}

// Categorie-sortering op basis van KNSB-codes (DP3, HP3, DKA, HJB, DJA, ...).
// De DC-naam is vrije tekst (bv. "Pupil 3/4 Meisjes/Jongens") en onbetrouwbaar
// voor sort; de codes zijn voorspelbaar: [D/H][P/K/J/S/M][sub].
//
//   1e letter = geslacht:   D=Dames(0), H=Heren(1)
//   2e letter = groep:      P=Pupil(1), K=Kadet(2), J=Junior(3), S=Senior(4), M=Master(5)
//   3e+       = sub:        cijfer (Pupil 1-4) of letter (Junior A/B)
// Jongst → oudst wordt zo:
//   Pupil 4 → Pupil 3 → ... → Pupil 1 → Kadet → Junior B → Junior A → Senior → Master
//   Binnen gelijke groep: Dames (D) vóór Heren (H)
//
// Voor combi-DC's met meerdere codes nemen we de MINIMALE sort-tuple over
// alle codes — dat komt neer op "sorteer deze combi als zijn jongste
// categorie" (DP4 wint van HP4 wint van DP3 etc.).
function _pcCodeSortTuple(code) {
    const c = String(code || '').toUpperCase().trim();
    if (c.length < 2) return [99, 0, 2];
    const geslachtCh = c[0];
    const groepCh    = c[1];
    const sub        = c.slice(2);
    const geslacht = geslachtCh === 'D' ? 0 : geslachtCh === 'H' ? 1 : 2;
    let primary   = 99, secondary = 0;
    switch (groepCh) {
        case 'P': primary = 1;
            const n = parseInt(sub);
            if (!isNaN(n)) secondary = -n;          // P4 → -4 (eerst), P1 → -1 (laatst)
            break;
        case 'K': primary = 2; break;
        case 'J': primary = 3;
            if (sub) secondary = -sub.charCodeAt(0); // B(66) → -66 eerst, A(65) → -65
            break;
        case 'S': primary = 4; break;
        case 'M': primary = 5; break;
    }
    return [primary, secondary, geslacht];
}

// Vergelijk twee tuples lexicografisch
function _pcTupleCmp(a, b) {
    for (let i = 0; i < 3; i++) if (a[i] !== b[i]) return a[i] - b[i];
    return 0;
}

// Pak uit een set codes de tuple die het laagst (= eerst) sorteert.
function _pcMinTuple(codes) {
    let best = [99, 0, 2];
    let eerst = true;
    for (const c of codes) {
        const t = _pcCodeSortTuple(c);
        if (eerst || _pcTupleCmp(t, best) < 0) { best = t; eerst = false; }
    }
    return best;
}

// Lookup: catNaam (dc_name / merge_label) → Set van KNSB-codes erin.
// Gebouwd op basis van _slGroepen (startlijst-module), fallback vergelijkData.
function _pcBouwCatCodeMap() {
    const map = new Map();  // catNaam → Set<code>
    const groepen = (typeof _slGroepen !== 'undefined' && _slGroepen) ? _slGroepen
                  : (typeof _uGroepen  !== 'undefined' && _uGroepen)  ? _uGroepen : [];
    for (const g of groepen) {
        const naam = g.merge_label || g.dc_name;
        if (!naam) continue;
        if (!map.has(naam)) map.set(naam, new Set());
        const set = map.get(naam);
        for (const c of g.competitors ?? []) {
            const code = c.knsb?.category || c.category;
            if (code) set.add(code);
        }
    }
    return map;
}

// Cache per render-tick (reset in openPrintCenter)
let _pcCatCodeMap = null;
function _pcGetCatCodeMap() {
    if (!_pcCatCodeMap) _pcCatCodeMap = _pcBouwCatCodeMap();
    return _pcCatCodeMap;
}

// Bepaal sort-tuple voor een item-label. Ondersteunt zowel losse DC-namen
// als combi-labels ("🔗 A + B + C") — verzamelt codes over alle DC's heen.
function _pcCatSortTupleVoorLabel(naam) {
    let n = String(naam || '').trim();
    // Strip combi-prefix (🔗 + whitespace/bullets)
    n = n.replace(/^[\u{1F517}\s·\-+]+/u, '').trim();
    const codeMap = _pcGetCatCodeMap();
    // Splits combi-label op "+" / "·" / "/" om aparte DC-namen te zoeken
    const dcNamen = n.split(/\s*[+·]\s*/).map(s => s.trim()).filter(Boolean);
    const codes = new Set();
    for (const dcNaam of dcNamen) {
        const codeSet = codeMap.get(dcNaam);
        if (codeSet) for (const c of codeSet) codes.add(c);
    }
    // Fallback: als we niks konden matchen via DC-naam, probeer de ruwe tekst
    if (codes.size === 0 && codeMap.has(naam)) {
        for (const c of codeMap.get(naam)) codes.add(c);
    }
    if (codes.size === 0) return [99, 0, 2];
    return _pcMinTuple(codes);
}

function _pcCatCmp(a, b) {
    const ta = _pcCatSortTupleVoorLabel(a);
    const tb = _pcCatSortTupleVoorLabel(b);
    const c = _pcTupleCmp(ta, tb);
    if (c !== 0) return c;
    // Gelijke sort-tuple → alfabetisch als tiebreaker
    return _pcNatCmp(a, b);
}

// Helper: DC-id → dag-nummer via shared tijdschema-helper. Lege Map als
// tijdschema niet geladen is — items krijgen dan dag=undefined (= altijd
// zichtbaar in mijn filter, wat OK is want zonder ts ook geen dag-filter).
function _pcDcDagMap() {
    if (typeof huidigTijdschema === 'undefined' || !huidigTijdschema) return new Map();
    if (typeof _tsBouwDcDagMap !== 'function')                        return new Map();
    return _tsBouwDcDagMap(huidigTijdschema);
}

// Bepaal dag voor een item uit één of meer dc_ids. Eerste match in dcDagMap.
// Geeft undefined als geen match (mag — filter behandelt undefined als altijd-
// zichtbaar). Bij multi-day moet dcDagMap een match hebben (anders heeft
// genereer-programma geen rit voor die DC gemaakt, en zou er ook geen sl/uitslag
// voor zijn). Defensieve fallback: 1.
function _pcDagVoorDcIds(dcDagMap, dcIds, isMulti) {
    if (!isMulti) return undefined;
    if (!Array.isArray(dcIds)) dcIds = [dcIds];
    for (const id of dcIds) {
        const d = dcDagMap.get(id);
        if (d !== undefined) return d;
    }
    return 1;
}

// ── Startlijsten sectie dynamisch opbouwen ──────────────────────────────────
// Geneste structuur:  Afstand → Ronde → [Categorieën als items]
function _pcBouwStartlijstenSectie() {
    const sec = { sectie: 'Startlijsten', items: [], groups: [] };

    if (_pcState.startlijstenLaad === true) {
        sec.placeholder = 'Startlijsten ophalen…';
        return sec;
    }
    if (typeof _slPrintOpties === 'undefined') {
        sec.placeholder = 'Niet beschikbaar';
        return sec;
    }
    if (!_slPrintOpties?.size) {
        sec.placeholder = 'Nog geen loting beschikbaar';
        return sec;
    }

    // Combi-detectie: gecombineerde A-finales (meerdere categorieën rijden
    // samen) moeten als één item verschijnen — niet per categorie. De
    // onderliggende body-builder (_bouwStartlijstDrukInternal) print de hele
    // combi al in één document, dus in Print-Center dedupliceren we op
    // combi_group en gebruiken de eerste catNaam die we tegenkomen als
    // "eigenaar" van dat gedeelde item.
    const ritten = (typeof huidigTijdschema !== 'undefined' && huidigTijdschema?.ritten) || [];
    const combiLookup = new Map(); // `${distId}|finale_a|${dc_id}` → combi_group
    const combiDcsPerGroep = new Map(); // combi_group → [dc_id,...] in volgorde
    for (const r of ritten) {
        if (r.ronde_type !== 'finale_a' || !r.combi_group) continue;
        const key = `${r.distance_id ?? ''}|finale_a|${r.dc_id}`;
        combiLookup.set(key, parseInt(r.combi_group));
        const gid = parseInt(r.combi_group);
        if (!combiDcsPerGroep.has(gid)) combiDcsPerGroep.set(gid, []);
        const arr = combiDcsPerGroep.get(gid);
        if (!arr.includes(r.dc_id)) arr.push(r.dc_id);
    }
    // Lookup: dc_id → displayNaam (uit _slGroepen)
    const dcIdNaam = new Map();
    if (typeof _slGroepen !== 'undefined' && _slGroepen) {
        for (const g of _slGroepen) {
            const naam = g.merge_label || g.dc_name;
            if (g.dc_id) dcIdNaam.set(g.dc_id, naam);
            for (const id of (g.dc_ids ?? [])) dcIdNaam.set(id, naam);
        }
    }

    // Multi-day tagging: items krijgen .dag = dagNr van hun DC (eerste lid bij combi).
    // Bij single-day blijft dag=undefined → filter heeft toch geen effect.
    const dcDagMap   = _pcDcDagMap();
    const _dagInfoSL = (typeof huidigTijdschema !== 'undefined' && huidigTijdschema
                        && typeof _tsBouwDagInfo === 'function')
                       ? _tsBouwDagInfo(huidigTijdschema?.blokken ?? [])
                       : null;
    const isMultiSL  = !!_dagInfoSL?.isMultiDag;

    // `distNaamVolgorde` bewaart de volgorde waarin afstanden voor het eerst
    // verschijnen = KNSB-programma-volgorde (distances_db op `number`).
    const perAfst = new Map();  // distNaam → Map(rondeLabel → {sleutel, items[]})
    const distNaamVolgorde = new Map();
    const combiGezien = new Set();   // "distId:combi_group" → al toegevoegd

    for (const [catNaam, distMap] of _slPrintOpties) {
        for (const [distId, distInfo] of distMap) {
            const distNaam = distInfo.distNaam || '—';
            if (!distNaamVolgorde.has(distNaam)) distNaamVolgorde.set(distNaam, distNaamVolgorde.size);
            if (!perAfst.has(distNaam)) perAfst.set(distNaam, new Map());
            const rondeMap = perAfst.get(distNaam);
            for (const ronde of distInfo.ronden ?? []) {
                // Check op combi voor finale_a
                let itemLabel = catNaam;
                let itemId    = `sl|${distId}|${ronde.sleutel}|${catNaam}`;
                if (ronde.sleutel === 'finale_a') {
                    const od = ronde.optData;
                    const dcIds = od.dcIds ?? [od.dcId];
                    // Zoek een combi_group voor een van deze dc_ids
                    let cg = null;
                    for (const did of dcIds) {
                        const g = combiLookup.get(`${distId}|finale_a|${did}`);
                        if (g) { cg = g; break; }
                    }
                    if (cg != null) {
                        const combiKey = `${distId}::${cg}`;
                        if (combiGezien.has(combiKey)) continue; // al toegevoegd
                        combiGezien.add(combiKey);
                        // Verzamel alle dc-namen in de combi-groep
                        const combiDcIds = combiDcsPerGroep.get(cg) ?? [];
                        const namen = [...new Set(
                            combiDcIds.map(id => dcIdNaam.get(id) || id)
                        )];
                        itemLabel = '🔗 ' + namen.join(' + ');
                        itemId    = `sl|${distId}|finale_a|combi-${cg}`;
                    }
                }

                const key = ronde.label || ronde.sleutel;
                if (!rondeMap.has(key)) {
                    rondeMap.set(key, { sleutel: ronde.sleutel, label: key, items: [] });
                }
                _pcItemData.set(itemId, ronde.optData);
                // Multi-day tag: dag uit dcIds via dcDagMap. Bij combi-finale
                // bevat optData.dcIds al alle deelnemende DCs; eerste match wint.
                const _itemDag = _pcDagVoorDcIds(
                    dcDagMap,
                    ronde.optData.dcIds ?? [ronde.optData.dcId],
                    isMultiSL,
                );
                rondeMap.get(key).items.push({
                    id:          itemId,
                    label:       itemLabel,
                    dag:         _itemDag,
                    beschikbaar: true,
                    build: async () => {
                        const od = _pcItemData.get(itemId);
                        if (!od || typeof bouwStartlijstBody !== 'function') return null;
                        return await bouwStartlijstBody(od);
                    },
                });
            }
        }
    }

    // Sorteer afstanden op KNSB-programma-volgorde (insertion order)
    const sortedAfst = [...perAfst.entries()].sort((a, b) =>
        (distNaamVolgorde.get(a[0]) ?? 99) - (distNaamVolgorde.get(b[0]) ?? 99)
    );
    for (const [distNaam, rondeMap] of sortedAfst) {
        // Sorteer rondes op programma-volgorde (series → finale)
        const sortedRondes = [...rondeMap.values()].sort((a, b) =>
            (_PC_RONDE_VOLGORDE[a.sleutel] ?? 99) - (_PC_RONDE_VOLGORDE[b.sleutel] ?? 99)
        );
        const subgroups = sortedRondes.map(r => {
            // Categorieën: jongst → oudst (Pupil → Kadet → Junior → Senior)
            r.items.sort((a, b) => _pcCatCmp(a.label, b.label));
            return { label: r.label, items: r.items };
        });
        sec.groups.push({ label: distNaam, subgroups });
    }
    return sec;
}

// ── Uitslagen sectie dynamisch opbouwen ─────────────────────────────────────
// Geneste structuur: Uitslagen per afstand → per afstand [categorieën] + aparte
// klassementen-groep onderaan voor Tussenklassement en Eindklassement.
function _pcBouwUitslagenSectie() {
    const sec = { sectie: 'Uitslagen', items: [], groups: [] };

    if (_pcState.uitslagenLaad === true) {
        sec.placeholder = 'Uitslagen ophalen…';
        return sec;
    }
    if (typeof _uPrintOpties === 'undefined') {
        sec.placeholder = 'Niet beschikbaar';
        return sec;
    }
    if (!_uPrintOpties?.size) {
        sec.placeholder = 'Nog geen uitslagen beschikbaar';
        return sec;
    }

    // Multi-day tagging: zelfde aanpak als startlijsten — items krijgen
    // .dag = dagNr van hun DC. Klassementen (tussen/eind) zijn cross-day
    // omdat ze over alle afstanden gaan → 'all' (altijd zichtbaar).
    const dcDagMap   = _pcDcDagMap();
    const _dagInfoU  = (typeof huidigTijdschema !== 'undefined' && huidigTijdschema
                        && typeof _tsBouwDagInfo === 'function')
                       ? _tsBouwDagInfo(huidigTijdschema?.blokken ?? [])
                       : null;
    const isMultiU   = !!_dagInfoU?.isMultiDag;

    // _uPrintOpties: Map<catNaam, [{label, sleutel, dcId, dcIds, dcName, distId?, distNaam?}]>
    const perAfst = new Map();
    const distNaamVolgorde = new Map();  // programma-volgorde voor afstanden
    const klassementen = { tussenklassement: [], eindklassement: [] };

    for (const [catNaam, opties] of _uPrintOpties) {
        for (const opt of opties) {
            const id = `u|${opt.sleutel}|${opt.distId ?? ''}|${catNaam}`;
            _pcItemData.set(id, opt);
            // Afstand-uitslag: dag uit dcIds via dcDagMap.
            // Klassementen: cross-day → 'all'.
            const _itemDag = opt.sleutel === 'afstand'
                ? _pcDagVoorDcIds(dcDagMap, opt.dcIds ?? [opt.dcId], isMultiU)
                : (isMultiU ? 'all' : undefined);
            const item = {
                id,
                label:       catNaam,
                dag:         _itemDag,
                beschikbaar: true,
                build: async () => {
                    const od = _pcItemData.get(id);
                    if (!od) return null;
                    if (od.sleutel === 'afstand') {
                        return typeof bouwUitslagAfstandBody === 'function'
                            ? await bouwUitslagAfstandBody(od) : null;
                    }
                    return typeof bouwKlassementBody === 'function'
                        ? await bouwKlassementBody(od) : null;
                },
            };
            if (opt.sleutel === 'afstand') {
                const distNaam = opt.distNaam || '—';
                if (!distNaamVolgorde.has(distNaam)) distNaamVolgorde.set(distNaam, distNaamVolgorde.size);
                if (!perAfst.has(distNaam)) perAfst.set(distNaam, []);
                perAfst.get(distNaam).push(item);
            } else if (klassementen[opt.sleutel]) {
                klassementen[opt.sleutel].push(item);
            }
        }
    }

    // Per-afstand-uitslagen: afstanden op KNSB-programma-volgorde, categorieën
    // op leeftijdsgroep (jongst eerst)
    const sortedAfst = [...perAfst.entries()].sort((a, b) =>
        (distNaamVolgorde.get(a[0]) ?? 99) - (distNaamVolgorde.get(b[0]) ?? 99)
    );
    for (const [distNaam, items] of sortedAfst) {
        items.sort((a, b) => _pcCatCmp(a.label, b.label));
        sec.groups.push({ label: distNaam, items });
    }

    // Klassementen-groep (tussen + eind) onderaan
    const klassementSubgroups = [];
    if (klassementen.tussenklassement.length) {
        klassementen.tussenklassement.sort((a, b) => _pcCatCmp(a.label, b.label));
        klassementSubgroups.push({ label: 'Tussenklassement', items: klassementen.tussenklassement });
    }
    if (klassementen.eindklassement.length) {
        klassementen.eindklassement.sort((a, b) => _pcCatCmp(a.label, b.label));
        klassementSubgroups.push({ label: 'Eindklassement',  items: klassementen.eindklassement });
    }
    if (klassementSubgroups.length) {
        sec.groups.push({ label: 'Klassementen', subgroups: klassementSubgroups });
    }
    return sec;
}

// ── Inhoud renderen ─────────────────────────────────────────────────────────
// Regel: je kunt items uit slechts ÉÉN sectie tegelijk selecteren. Zodra in
// een sectie iets is aangevinkt, worden de andere secties dim/disabled. Dit
// vermijdt CSS-conflicten tussen verschillende printtypes en voorkomt
// onduidelijk mengsel in 1 print-job. Binnen de actieve sectie kan wél
// multiselect.
// Verzamel alle items uit een sectie (flat items + items in groups/subgroups).
function _pcSectieItems(sec) {
    const acc = [];
    if (Array.isArray(sec.items)) acc.push(...sec.items);
    for (const g of sec.groups ?? []) {
        if (Array.isArray(g.items)) acc.push(...g.items);
        for (const sg of g.subgroups ?? []) {
            if (Array.isArray(sg.items)) acc.push(...sg.items);
        }
    }
    return acc;
}

function _pcActieveSectie() {
    const opties = _pcOpties();
    for (const sec of opties) {
        const items = _pcSectieItems(sec);
        if (items.some(it => _pcState.geselecteerd.has(it.id))) {
            return sec.sectie;
        }
    }
    return null;
}

function _pcRenderItem(it, isVergrendeld) {
    const laden  = (it.beschikbaar === null);
    const nietOk = (it.beschikbaar === false);
    if (nietOk) _pcState.geselecteerd.delete(it.id);
    const checked  = _pcState.geselecteerd.has(it.id) ? ' checked' : '';
    const disabled = (laden || nietOk || isVergrendeld) ? ' disabled' : '';
    const cls = nietOk ? ' pc-item-disabled'
              : (laden ? ' pc-item-laden'
              : (isVergrendeld ? ' pc-item-disabled' : ''));
    const suffix = nietOk
        ? ` <span class="pc-item-reden">— ${_escHtml(it.redenNietBeschikbaar ?? 'niet beschikbaar')}</span>`
        : (laden ? ` <span class="pc-item-reden">— laden…</span>` : '');
    return `<label class="pc-item${cls}">
        <input type="checkbox" data-pc-id="${it.id}"${checked}${disabled}>
        <span>${_escHtml(it.label)}${suffix}</span>
    </label>`;
}

function _pcRenderGroup(grp, isVergrendeld, groupKey) {
    const subgroups = grp.subgroups ?? [];
    const ownItems  = grp.items ?? [];
    let body = '';
    if (ownItems.length) {
        body += `<div class="pc-items-rij">${ownItems.map(it => _pcRenderItem(it, isVergrendeld)).join('')}</div>`;
    }
    for (let i = 0; i < subgroups.length; i++) {
        const sg = subgroups[i];
        const sgKey = `${groupKey}::sg${i}`;
        const sgKnop = !isVergrendeld && sg.items?.length
            ? `<button class="pc-alles-btn pc-mini-btn" data-pc-groepkey="${sgKey}">alles aan/uit</button>`
            : '';
        body += `<div class="pc-subgroep">
            <div class="pc-subgroep-kop"><span>${_escHtml(sg.label)}</span>${sgKnop}</div>
            <div class="pc-items-rij">${(sg.items ?? []).map(it => _pcRenderItem(it, isVergrendeld)).join('')}</div>
        </div>`;
    }
    const groupKnop = !isVergrendeld
        ? `<button class="pc-alles-btn pc-mini-btn" data-pc-groepkey="${groupKey}">alles aan/uit</button>`
        : '';
    return `<div class="pc-groep">
        <div class="pc-groep-kop"><span>${_escHtml(grp.label)}</span>${groupKnop}</div>
        <div class="pc-groep-body">${body}</div>
    </div>`;
}

// Vind een group of subgroup in een sectie aan de hand van een group-key
// ("g<i>" voor groep, "g<i>::sg<j>" voor subgroep). Retourneert de items-array.
function _pcItemsVoorGroepKey(sec, groupKey) {
    const m = groupKey.match(/^g(\d+)(?:::sg(\d+))?$/);
    if (!m) return [];
    const gIdx  = parseInt(m[1]);
    const sgIdx = m[2] != null ? parseInt(m[2]) : null;
    const group = (sec.groups ?? [])[gIdx];
    if (!group) return [];
    if (sgIdx === null) {
        // Hele groep: eigen items + items in alle subgroups
        const out = [...(group.items ?? [])];
        for (const sg of group.subgroups ?? []) out.push(...(sg.items ?? []));
        return out;
    }
    return (group.subgroups ?? [])[sgIdx]?.items ?? [];
}

// ── Multi-day filter helpers ────────────────────────────────────────────────
// Itereert over alle (flat + geneste) items in een sectie. Gebruikt om te
// detecteren of een sectie chip-filters nodig heeft, of om items te filteren.
function _pcSectieAlleItemsRaw(sec) {
    const out = [...(sec.items ?? [])];
    for (const grp of sec.groups ?? []) {
        out.push(...(grp.items ?? []));
        for (const sg of grp.subgroups ?? []) out.push(...(sg.items ?? []));
    }
    return out;
}

// Welke unieke dag-nummers staan in deze sectie? (Negeert 'all' en undefined.)
function _pcSectieDagen(sec) {
    const dagen = new Set();
    for (const it of _pcSectieAlleItemsRaw(sec)) {
        if (typeof it.dag === 'number') dagen.add(it.dag);
    }
    return [...dagen].sort((a, b) => a - b);
}

// Filterregel:
//   'alles'           → alle items zichtbaar
//   N (1, 2, ...)     → items met dag===N OF dag==='all' OF dag===undefined
// Cross-day items ('all'/undefined) blijven bij specifieke-dag-filter altijd
// zichtbaar zodat de operator ze niet kwijt is bij het filteren.
function _pcItemPasstFilter(item, filter) {
    if (filter === 'alles' || filter == null) return true;
    if (item.dag === filter) return true;
    if (item.dag === 'all') return true;
    if (item.dag === undefined) return true;
    return false;
}

// Filter een sectie-structuur: maakt een ondiepe kopie met alleen passende
// items. Behoudt groups/subgroups (kunnen leeg worden — in dat geval skip
// in render).
function _pcSectieFilteren(sec, filter) {
    if (filter === 'alles' || filter == null) return sec;
    const items = (sec.items ?? []).filter(it => _pcItemPasstFilter(it, filter));
    const groups = (sec.groups ?? []).map(grp => ({
        ...grp,
        items:     (grp.items ?? []).filter(it => _pcItemPasstFilter(it, filter)),
        subgroups: (grp.subgroups ?? []).map(sg => ({
            ...sg,
            items: (sg.items ?? []).filter(it => _pcItemPasstFilter(it, filter)),
        })).filter(sg => (sg.items ?? []).length > 0),
    })).filter(grp => (grp.items?.length ?? 0) > 0 || (grp.subgroups?.length ?? 0) > 0);
    return { ...sec, items, groups };
}

function _pcRenderInhoud() {
    const wrap = document.getElementById('pc-inhoud');
    if (!wrap) return;

    const optiesRaw   = _pcOpties();
    const actieveSec  = _pcActieveSectie();

    // Globale dag-filter: één filter die op ALLE secties tegelijk werkt.
    // Verschijnt alleen als ten minste één sectie items met een dag-nummer
    // heeft (= multi-day event met dag-specifieke items). Default 'alles'.
    const alleDagen = new Set();
    for (const s of optiesRaw) {
        for (const n of _pcSectieDagen(s)) alleDagen.add(n);
    }
    const dagenSorted = [...alleDagen].sort((a, b) => a - b);
    const heeftDagFilter = dagenSorted.length > 0;
    if (!heeftDagFilter) _pcState.dagFilter = 'alles';
    const huidigeF = _pcState.dagFilter;

    // Globale chip-rij bovenaan. Niet per sectie meer.
    let html = '';
    if (heeftDagFilter) {
        const chipBtn = (val, label) => {
            const actief = (huidigeF === val);
            return `<button class="pc-dag-chip${actief ? ' active' : ''}"`
                + ` data-pc-dag-val="${val}"`
                + ` aria-pressed="${actief ? 'true' : 'false'}">${_escHtml(label)}</button>`;
        };
        html += `<div class="pc-dag-filter-balk" role="group" aria-label="Dag-filter">
            <span class="pc-dag-filter-label">Toon:</span>
            <div class="pc-dag-chips">
                ${chipBtn('alles', 'alles')}
                ${dagenSorted.map(n => chipBtn(n, `dag ${n}`)).join('')}
            </div>
        </div>`;
    }

    for (const secRaw of optiesRaw) {
        // Pas globale filter toe op items (alleen voor render — onderliggende
        // _pcOpties()-data blijft compleet).
        const sec       = _pcSectieFilteren(secRaw, huidigeF);

        const isVergrendeld = actieveSec !== null && sec.sectie !== actieveSec;
        const flatItems = sec.items ?? [];
        const groups    = sec.groups ?? [];
        const hasContent = flatItems.length > 0 || groups.length > 0;

        let inhoudHtml = '';
        if (!hasContent) {
            // Bij actief dag-filter en lege sectie: melding "Geen items op dag N"
            // i.p.v. de generieke placeholder.
            const leegMsg = huidigeF !== 'alles'
                ? `Geen items voor deze dag in '${_escHtml(sec.sectie)}'.`
                : _escHtml(sec.placeholder ?? 'Nog niet beschikbaar');
            inhoudHtml = `<div class="pc-placeholder">${leegMsg}</div>`;
        } else {
            if (flatItems.length) {
                inhoudHtml += `<div class="pc-items-rij">${flatItems.map(it => _pcRenderItem(it, isVergrendeld)).join('')}</div>`;
            }
            groups.forEach((grp, gi) => {
                inhoudHtml += _pcRenderGroup(grp, isVergrendeld, `g${gi}`);
            });
        }

        const knopAanUit = (hasContent && !isVergrendeld)
            ? `<button class="pc-alles-btn" data-pc-sectie="${sec.sectie}">alles aan/uit</button>`
            : '';
        const vergrendelNotitie = isVergrendeld
            ? `<span class="pc-sectie-slot">🔒 andere sectie actief</span>`
            : '';
        const secCls = isVergrendeld ? ' pc-sectie-vergrendeld' : '';

        html += `<div class="pc-sectie${secCls}" data-pc-sectie-wrap="${sec.sectie}">
            <div class="pc-sectie-kop">
                <h3>${_escHtml(sec.sectie)}</h3>
                ${vergrendelNotitie}
                ${knopAanUit}
            </div>
            <div class="pc-sectie-items">${inhoudHtml}</div>
        </div>`;
    }
    wrap.innerHTML = html;

    // Checkbox-listeners
    wrap.querySelectorAll('input[data-pc-id]').forEach(inp => {
        inp.addEventListener('change', () => {
            if (inp.checked) _pcState.geselecteerd.add(inp.dataset.pcId);
            else              _pcState.geselecteerd.delete(inp.dataset.pcId);
            _pcRenderInhoud();
        });
    });

    // "Alles aan/uit" voor hele sectie. Respecteert actief globaal dag-filter:
    // selecteert alleen items die nu daadwerkelijk zichtbaar zijn.
    wrap.querySelectorAll('button[data-pc-sectie]').forEach(btn => {
        btn.addEventListener('click', () => {
            const sectieNaam = btn.dataset.pcSectie;
            const secRaw = optiesRaw.find(s => s.sectie === sectieNaam);
            if (!secRaw) return;
            const sec      = _pcSectieFilteren(secRaw, _pcState.dagFilter);
            const alleItems = _pcSectieItems(sec).filter(it => it.beschikbaar !== false);
            const allesAan = alleItems.length > 0
                && alleItems.every(it => _pcState.geselecteerd.has(it.id));
            for (const it of alleItems) {
                if (allesAan) _pcState.geselecteerd.delete(it.id);
                else          _pcState.geselecteerd.add(it.id);
            }
            _pcRenderInhoud();
        });
    });

    // "Alles aan/uit" voor groep of subgroep
    wrap.querySelectorAll('button[data-pc-groepkey]').forEach(btn => {
        btn.addEventListener('click', ev => {
            ev.stopPropagation();
            const key = btn.dataset.pcGroepkey;
            // Zoek de sectie waarin deze groep-knop zit
            const secWrap = btn.closest('[data-pc-sectie-wrap]');
            const secNaam = secWrap?.dataset.pcSectieWrap;
            const secRaw = optiesRaw.find(s => s.sectie === secNaam);
            if (!secRaw) return;
            const sec      = _pcSectieFilteren(secRaw, _pcState.dagFilter);
            const items = _pcItemsVoorGroepKey(sec, key).filter(it => it.beschikbaar !== false);
            const allesAan = items.length > 0
                && items.every(it => _pcState.geselecteerd.has(it.id));
            for (const it of items) {
                if (allesAan) _pcState.geselecteerd.delete(it.id);
                else          _pcState.geselecteerd.add(it.id);
            }
            _pcRenderInhoud();
        });
    });

    // Globale dag-filter chips: één click switcht alle secties tegelijk.
    wrap.querySelectorAll('button[data-pc-dag-val]').forEach(btn => {
        btn.addEventListener('click', () => {
            const val   = btn.dataset.pcDagVal;
            const nieuw = val === 'alles' ? 'alles' : (parseInt(val) || 'alles');
            if (_pcState.dagFilter === nieuw) return;
            _pcState.dagFilter = nieuw;
            _pcRenderInhoud();
        });
    });

    // Boom-saver checkbox state
    const bs = document.getElementById('pc-boomsaver');
    if (bs) bs.checked = _pcState.boomSaver;

    _pcBijwerkenKnopStatus();
}

function _pcBijwerkenKnopStatus() {
    const btn = document.getElementById('pc-btn-print');
    if (btn) btn.disabled = _pcState.geselecteerd.size === 0;
}

// ── Start de print ──────────────────────────────────────────────────────────
// Combineer alle geselecteerde prints in één venster met page-break ertussen.
// Dit levert één print-dialog op ongeacht hoeveel items zijn aangevinkt —
// geen popup-blocker, geen background-tabs, geen heen-en-weer-geklik.
async function _pcStartPrint() {
    // Verzamel alle items uit alle secties — ook genesteld onder groups/subgroups
    const alleItems = _pcOpties().flatMap(s => _pcSectieItems(s));
    const geselecteerd = alleItems.filter(it => _pcState.geselecteerd.has(it.id));
    if (geselecteerd.length === 0) return;

    sluitPrintCenter();

    // 1) Verzamel alle body-definities (bouw-functies kunnen async zijn)
    const bodies = [];
    for (const it of geselecteerd) {
        if (typeof it.build !== 'function') continue;
        let data = null;
        try { data = await it.build(); }
        catch (e) { console.warn('Build mislukt voor', it.id, e); }
        if (data?.bodyHtml) bodies.push({ it, data });
    }
    if (bodies.length === 0) {
        if (typeof toonBevestigDialog === 'function') {
            await toonBevestigDialog(
                'Geen van de geselecteerde items kon worden opgebouwd. ' +
                'Zijn de gegevens wel beschikbaar?',
                'Print-Center', 'OK', ''
            );
        }
        openPrintCenter();
        return;
    }

    // 2) Bouw combined document
    //    - Unieke CSS-links combineren
    //    - Per-sectie inline-CSS achter elkaar plakken
    //    - Per sectie een named @page-regel voor juiste orientation
    //    - Bodies scheiden met page-break-before (logica hieronder)
    //
    // Boom-saver:
    //   uit  → elke body op nieuwe pagina (page-break-before: always)
    //   aan  → alleen nieuwe pagina bij orientation-wissel. Binnen dezelfde
    //          orientation plakken bodies aan elkaar, met een compacte
    //          scheidslijn + titel zodat duidelijk blijft waar de ene stopt
    //          en de volgende begint.
    const boomSaver = !!_pcState.boomSaver;
    const cssLinkSet = new Set();
    const inlineCssParts = [];
    const bodyParts = [];
    let vorigeOrient = null;

    // Slechts 2 named pages definiëren (landscape + portrait). Als we per
    // sectie een unieke naam zouden gebruiken, forceert de CSS Paged Media-
    // spec altijd een page-break tussen opeenvolgende secties (zelfs met
    // dezelfde size) — dat maakt boom-saver nutteloos. Door dezelfde naam te
    // hergebruiken voor alle landscape-secties (en idem voor portrait) mag
    // de browser bodies doorlopen zonder verplichte page-break.
    // Marge-strategie: 3mm als ondergrens. Randloze printers krijgen
    // dan exact 3mm wit (tekst zou anders aan de papierrand vastplakken).
    // Printers met grotere fysieke minimum-marge clippen het 3-Xmm gebied
    // weg en gebruiken effectief hun eigen minimum. Resultaat: maximaal
    // papier-oppervlak met veilige 3mm bodem voor randloze printers.
    const pageRules = [
        `@page pc-p-landscape { size: A4 landscape; margin: 3mm; }`,
        `@page pc-p-portrait  { size: A4 portrait;  margin: 3mm; }`,
    ];

    bodies.forEach(({ data }, i) => {
        (data.cssLinks ?? []).forEach(l => cssLinkSet.add(l));
        if (data.extraCss) inlineCssParts.push(data.extraCss);

        const orient  = (data.pageOrientation === 'landscape') ? 'landscape' : 'portrait';
        const pageNm  = orient === 'landscape' ? 'pc-p-landscape' : 'pc-p-portrait';

        // Bepaal of er een forced page-break vóór deze sectie moet komen
        let forceBreak;
        if (i === 0)                      forceBreak = false;
        else if (!boomSaver)              forceBreak = true;   // default-gedrag
        else if (vorigeOrient !== orient) forceBreak = true;   // orientation-wissel (onvermijdelijk)
        else                              forceBreak = false;  // boom-saver: plakken

        // Boom-saver: module-headers zijn gehide (CSS). De sub-type-label
        // (Eindklassement / Tussenklassement / etc) verhuist naar de gedeelde
        // header bovenaan — één keer i.p.v. per sectie (minder papier).
        const breakCls = forceBreak ? ' pc-break' : '';
        // Klassementen markeren zodat ze bij boom-saver niet halverwege de
        // pagina afgebroken worden (page-break-inside: avoid via CSS).
        const klasCls = (data.subType && /klassement/i.test(data.subType))
            ? ' pc-section-klassement' : '';
        const style    = `page: ${pageNm};`;
        bodyParts.push(
            `<section class="pc-section${breakCls}${klasCls}" style="${style}">${data.bodyHtml}</section>`
        );
        vorigeOrient = orient;
    });

    // Bij boom-saver injecteren we één gedeelde header bovenaan: comp-naam,
    // datum, locatie, eventueel "Stand: <tijdstip>" en de gecombineerde
    // sub-types ("Tekenlijsten · Klassement"). De individuele body-headers
    // worden via CSS verborgen — zo zie je niet drie keer dezelfde wedstrijd-
    // info als je drie prints combineert.
    if (boomSaver) {
        let compHeaderHtml = '';
        try {
            // Locale-aware datum + standTxt: shared header volgt nu de
            // Print-Center taalkeuze (NL/EN).
            const _LOC = (_pcState?.lang === 'en') ? 'en-GB' : 'nl-NL';
            const compNaam = (typeof huidigComp !== 'undefined' && huidigComp?.name) || '';
            const datum    = (typeof huidigComp !== 'undefined' && huidigComp?.starts)
                ? new Date(huidigComp.starts).toLocaleDateString(_LOC,
                    { weekday:'long', year:'numeric', month:'long', day:'numeric' })
                : '';
            const locatie  = (typeof huidigComp !== 'undefined' && huidigComp
                              && typeof getLocatie === 'function')
                ? getLocatie(huidigComp) : '';
            const metaTxt  = [datum, locatie].filter(Boolean).join(' · ');
            // Stand-info: optioneel, alleen als er een actieve standDatum is
            // (uit Importeer/Tijdschema-state). Globale vars zijn niet altijd
            // gedefinieerd in elke module — vandaar de typeof-check.
            const stand    = (typeof standDatum   !== 'undefined' && standDatum)   ? standDatum
                           : (typeof dbStandDatum !== 'undefined' && dbStandDatum) ? dbStandDatum
                           : '';
            const standTxt = stand ? _pcT('algemeen.stand_op', { datum: stand }) : '';
            let orgLogoHtml = '';
            let baanLogoHtml = '';
            if (typeof bouwOrgHeaderFooter === 'function') {
                const h = bouwOrgHeaderFooter(_escHtml);
                orgLogoHtml  = h?.orgLogoHtml  ?? '';
                baanLogoHtml = h?.baanLogoHtml ?? '';
            }
            const subTypes = [...new Set(bodies.map(({ data }) => data.subType).filter(Boolean))];
            const subTypeLabel = subTypes.join(' · ');

            compHeaderHtml = `
<div class="pc-shared-header">
  <div class="pc-shared-info">
    <div class="pc-shared-naam">${_escHtml(compNaam)}</div>
    ${metaTxt ? `<div class="pc-shared-meta">${_escHtml(metaTxt)}</div>` : ''}
    ${standTxt ? `<div class="pc-shared-stand">${_escHtml(standTxt)}</div>` : ''}
    ${subTypeLabel ? `<div class="pc-shared-type">${_escHtml(subTypeLabel)}</div>` : ''}
  </div>
  ${baanLogoHtml ? `<div class="pc-shared-baan">${baanLogoHtml}</div>` : ''}
  ${orgLogoHtml ? `<div class="pc-shared-logo">${orgLogoHtml}</div>` : ''}
</div>`;
        } catch (e) { console.warn('[PC] Shared header mislukt:', e); }
        if (compHeaderHtml && bodyParts.length > 0) {
            bodyParts[0] = bodyParts[0].replace(/(<section\b[^>]*>)/, `$1${compHeaderHtml}`);
        }
    }

    const cssLinksHtml = [...cssLinkSet].map(l => {
        const url = new URL(l + '?v=' + Date.now(), window.location.href).href;
        return `<link rel="stylesheet" href="${url}">`;
    }).join('\n');

    const combinedTitle = bodies.length === 1
        ? (bodies[0].data.title || 'Print')
        : `Print-Center (${bodies.length} prints)`;

    // 2b) Safari/WebKit detecteren bij mixed orientation. Safari ondersteunt
    // named @page rules + per-element `page:` property heel slecht: alle
    // pagina's krijgen dezelfde orientation (typisch portrait), waardoor
    // landscape-secties rechts worden afgekapt. Werkt prima in Chrome,
    // Firefox, Edge — alleen Safari Mac/iOS heeft dit probleem. Bij
    // gedetecteerd mixed-orientation + Safari: waarschuw operator vóór
    // het printen, zodat ze kunnen kiezen voor Chrome of de print
    // handmatig in 2 jobs splitsen.
    const orientUsed = new Set(bodies.map(({ data }) =>
        data.pageOrientation === 'landscape' ? 'landscape' : 'portrait'
    ));
    const heeftMixedOrient = orientUsed.size > 1;
    const isSafari = /^((?!chrome|android|crios|fxios).)*safari/i.test(navigator.userAgent)
                     && /apple/i.test(navigator.vendor || '');
    if (heeftMixedOrient && isSafari && typeof toonBevestigDialog === 'function') {
        // Verzamel titels per orientation zodat operator direct ziet welke
        // secties opgesplitst moeten worden. Fallback op subType of generieke
        // label als title leeg is (zou niet mogen, maar defensief).
        const labelVan = (data) => {
            const t = (data.title || '').trim();
            if (t) return t;
            const s = (data.subType || '').trim();
            return s || '(onbekende sectie)';
        };
        const escape = (s) => String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const portraitTitels  = bodies.filter(({ data }) => data.pageOrientation !== 'landscape')
                                      .map(({ data }) => escape(labelVan(data)));
        const landscapeTitels = bodies.filter(({ data }) => data.pageOrientation === 'landscape')
                                      .map(({ data }) => escape(labelVan(data)));
        const lijstHtml = (titels) => titels.length
            ? `<ul style="margin:2px 0 6px 22px;padding:0">${titels.map(t => `<li>${t}</li>`).join('')}</ul>`
            : '<p style="margin:2px 0 6px 22px;color:#888"><i>geen</i></p>';
        const html =
            `<p><b>Safari op Mac/iOS</b> ondersteunt geen automatische orientation-wissel ` +
            `binnen één print-job. Alles wordt in één richting geprint — landscape-content ` +
            `wordt dan mogelijk afgekapt aan de rand.</p>` +
            `<p style="margin-bottom:2px"><b>Portrait (${portraitTitels.length}):</b></p>` +
            lijstHtml(portraitTitels) +
            `<p style="margin-bottom:2px"><b>Landscape (${landscapeTitels.length}):</b></p>` +
            lijstHtml(landscapeTitels) +
            `<p><b>Twee oplossingen:</b></p>` +
            `<ol style="margin:6px 0 10px 22px;padding:0">` +
            `<li>Open InlineComp in <b>Google Chrome</b> of <b>Firefox</b> op deze Mac — ` +
            `daar werkt het wel automatisch</li>` +
            `<li>Print 2 keer apart: deselecteer eerst alle landscape-secties en print de ` +
            `portrait-set, daarna omgekeerd</li>` +
            `</ol>` +
            `<p style="font-size:.92em;color:#666">Klik <b>Doorgaan</b> om toch te printen ` +
            `(kan afgekapt resultaat geven), of <b>Annuleren</b> om terug te gaan en te splitsen.</p>`;
        const doorgaan = await toonBevestigDialog(
            html, 'Safari orientation-beperking', 'Doorgaan', 'Annuleren', { bodyIsHtml: true }
        );
        if (!doorgaan) {
            openPrintCenter();
            return;
        }
    }

    // 3) Open één venster — binnen user-gesture dus geen popup-blocker
    const w = window.open('', '_blank');
    if (!w) {
        if (typeof toonBevestigDialog === 'function') {
            await toonBevestigDialog(
                'Pop-up geblokkeerd — sta pop-ups toe voor deze site en probeer opnieuw.',
                'Print-Center', 'OK', ''
            );
        }
        openPrintCenter();
        return;
    }

    w.document.write(`<!DOCTYPE html>
<html lang="nl"><head>
<meta charset="utf-8">
<title>${_escHtml(combinedTitle)}</title>
${cssLinksHtml}
<style>
/* Named @page-regels per orientation. */
${pageRules.join('\n')}
/* Print-Center layout: scheid secties met een page-break wanneer nodig. */
.pc-section { }
.pc-break { page-break-before: always; }
/* Boom-saver: alle module-headers verbergen (comp-naam, datum, locatie,
   ronde-titel, disclaimer). De gedeelde shared-header bovenaan vervangt
   ze; voor sub-info (zoals heat-naam of categorie) hebben de bodies hun
   eigen interne titels die wel zichtbaar blijven. */
body.pc-boomsaver .pr-hdr-row,
body.pc-boomsaver .pr-hdr-spacer,
body.pc-boomsaver .pr-header,
body.pc-boomsaver .pagina-header,
body.pc-boomsaver .doc-header,
body.pc-boomsaver .hdr-lijn,
body.pc-boomsaver .disclaimer {
    display: none !important;
}
/* Ruimte tussen opeenvolgende content-blokken bij boom-saver */
body.pc-boomsaver .pc-section + .pc-section {
    margin-top: 5mm;
}
/* Boom-saver: footer (sponsor-logos, afgedrukt-tijdstip) alleen onderaan de
   laatste sectie tonen. Alle voorgaande footers verstoppen. */
body.pc-boomsaver .pc-section:not(:last-of-type) .org-sponsor-footer,
body.pc-boomsaver .pc-section:not(:last-of-type) .doc-footer {
    display: none !important;
}
/* Boom-saver: klassementen als geheel blok behandelen. Wanneer een klassement
   niet meer op de huidige pagina past, verhuist het als geheel naar een nieuwe
   pagina i.p.v. halverwege afgebroken te worden. */
body.pc-boomsaver .pc-section-klassement {
    page-break-inside: avoid;
    break-inside: avoid;
}
/* Gedeelde comp-header bovenaan het document bij boom-saver. */
.pc-shared-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 8mm;
    padding: 3mm 0 3mm 0;
    border-bottom: 2px solid #1a3a5c;
    margin-bottom: 4mm;
}
.pc-shared-info  { flex: 1 1 auto; min-width: 0; }
.pc-shared-naam  { font-size: 13pt; font-weight: 700; color: #111; line-height: 1.2; }
.pc-shared-meta  { font-size: 8.5pt; color: #555; margin-top: 1mm; }
.pc-shared-stand { font-size: 8pt; color: #777; margin-top: 0.8mm; font-style: italic; }
.pc-shared-type  { font-size: 10pt; font-weight: 700; color: #1a3a5c; margin-top: 2mm; }
.pc-shared-logo  { flex: 0 0 auto; }
.pc-shared-baan  { flex: 0 0 auto; }
/* Per-sectie CSS (uit de individuele body-builders). */
${inlineCssParts.join('\n\n/* --- volgende sectie --- */\n\n')}
</style>
</head>
<body${boomSaver ? ' class="pc-boomsaver"' : ''}>
${bodyParts.join('\n')}
<script>
window.addEventListener('load', function(){
    // Geef de browser even de tijd om CSS + fonts te laden, dan print
    setTimeout(function(){ window.focus(); window.print(); }, 300);
});
// Sluit de tab automatisch nadat de print-dialoog is afgehandeld
// (of geannuleerd). Browsers firen 'afterprint' zowel na afdrukken als
// na annuleren. We doen dit in een setTimeout zodat close niet de print
// zelf verstoort.
window.addEventListener('afterprint', function(){
    setTimeout(function(){ window.close(); }, 100);
});
<\/script>
</body></html>`);
    w.document.close();
}

// Zorg dat huidigTijdschema geladen is, ook als de Tijdschema-pagina nog
// niet bezocht werd. We doen hier een LICHTE fetch (alleen de data binnenhalen)
// in plaats van `laadTijdschema()` — die ook renderTijdschema() aanroept en
// DOM-elementen op de Tijdschema-pagina verwacht. Die pagina is mogelijk nog
// niet gerenderd vanuit Print-Center.
async function _pcZorgTijdschemaGeladen() {
    if (typeof huidigTijdschema !== 'undefined' && huidigTijdschema) return;
    if (typeof huidigCompId === 'undefined' || !huidigCompId) return;
    try {
        const res  = await fetch(
            'api/tijdschema.php?competition_id=' + encodeURIComponent(huidigCompId)
        );
        const data = await res.json();
        if (!data || data.error) return;

        // Zet globale state — publiceerTijdschema() leest hieruit
        huidigTijdschema  = data;
        if (typeof tijdschemaVersion !== 'undefined') {
            tijdschemaVersion = data?.tijdschema_version ?? 0;
        }

        // publiceerTijdschema() heeft óók afstanden-per-DC nodig voor volledige
        // naam-labels. Die hangen bij vergelijkData[].afstanden. Vul ze aan
        // via ÉÉN bulk-call (?dc_ids=...) ipv N parallelle single-calls —
        // anders schiet 1 Print-Center-load 50+ PHP-processen tegelijk af
        // op iFastNet shared hosting, wat de entry-process-limiet raakt.
        // Bulk-response shape: { dcId: [distances, ...] }
        const uniekeDcIds = [...new Set((vergelijkData ?? []).map(c => c.dc_id))];
        let distMap = {};
        if (uniekeDcIds.length > 0) {
            try {
                const r = await fetch(
                    'api/distances_db.php?dc_ids=' +
                    uniekeDcIds.map(encodeURIComponent).join(',')
                );
                distMap = await r.json() || {};
            } catch { distMap = {}; }
        }
        uniekeDcIds.forEach(dcId => {
            const alle = Array.isArray(distMap[dcId]) ? distMap[dcId] : [];
            const afst = alle.map(d => ({
                id:           d.id,
                name:         d.name,
                value_meters: d.value_meters,
                number:       d.number,
                target_group: d.target_group ?? null,
            }));
            (vergelijkData ?? []).filter(c => c.dc_id === dcId)
                .forEach(c => { c.afstanden = afst; });
        });
    } catch (e) {
        console.warn('[Print-Center] Tijdschema laden mislukt:', e);
    }
}

// ── Import-status check ─────────────────────────────────────────────────────
// Tekenlijsten + Deelnemerslijst hangen af van een volledig afgeronde
// import in de Import-module. De import-module beheert zelf al de
// `disabled`-state van zijn eigen print-knoppen; we volgen die als
// source of truth zodat er nooit ruzie tussen beide kan ontstaan.
function _pcImportKlaar() {
    // Geen data → niks te printen
    if (typeof vergelijkData === 'undefined' || !vergelijkData?.length) return false;
    // Globals niet beschikbaar: val veilig terug op "niet klaar"
    if (typeof isGeimporteerd === 'undefined') return false;
    if (!isGeimporteerd) return false;
    if (typeof heeftWijzigingen !== 'undefined' && heeftWijzigingen) return false;
    // DOM source-of-truth: als de import-knop in die module bestaat én
    // disabled is (na import-ronde), dan is alles up-to-date.
    const btn = document.getElementById('btn-print-tekenlijst');
    if (btn && btn.disabled) return false;
    return true;
}
function _pcImportReden() {
    if (typeof vergelijkData === 'undefined' || !vergelijkData?.length) {
        return 'Importeer eerst de wedstrijd';
    }
    if (typeof isGeimporteerd !== 'undefined' && !isGeimporteerd) {
        return 'Wedstrijd nog niet geïmporteerd in database';
    }
    if (typeof heeftWijzigingen !== 'undefined' && heeftWijzigingen) {
        return 'Onopgeslagen wijzigingen in Import';
    }
    return 'Importeer eerst de wedstrijd';
}

// ── Helper ──────────────────────────────────────────────────────────────────
function _escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

// ── Header-knop koppelen ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btn-printcenter');
    if (btn) btn.addEventListener('click', openPrintCenter);
});
