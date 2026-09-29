// ============================================================
//  InlineComp Public — hoofd-JS-app
//
//  Geextraheerd uit public/index.php op 2026-09-30 (fase 2 van
//  refactor-plan). PHP-injecties (APP_VERSIE, CHANGELOG) vervangen
//  door window.APP_CONFIG dat de shell inline vooraf zet.
//  i18n.js wordt door de shell als aparte <script src> geladen.
// ============================================================

// i18n-helpers (getT/getCurLang/etc.) worden vooraf geladen via een aparte
// <script src="../js/i18n.js"> tag in de shell (public/index.php).
// APP_VERSIE + CHANGELOG worden door de shell vooraf gezet als window.APP_CONFIG
// (zie public/index.php) — één bron van waarheid: inc/versie.php + inc/changelog.php.
const APP_VERSIE = window.APP_CONFIG.versie;
const CHANGELOG  = window.APP_CONFIG.changelog;

// Rendert de changelog: groepeert opeenvolgende entries per versie+datum en
// kiest per regel de tekst in de actieve taal (fallback en → nl). De tekst is
// bewust HTML (bevat <b>/<i>) en wordt niet ge-escaped — net als voorheen.
function renderChangelog(entries) {
    const lang = (typeof getCurLang === 'function') ? getCurLang() : 'nl';
    const groepen = [];
    for (const e of entries) {
        const laatste = groepen[groepen.length - 1];
        if (laatste && laatste.versie === e.versie && laatste.datum === e.datum) {
            laatste.items.push(e);
        } else {
            groepen.push({ versie: e.versie, datum: e.datum, items: [e] });
        }
    }
    return groepen.map(g => `
        <div class="changelog-versie">
            <div class="changelog-kop">
                <span class="changelog-vnr">${g.versie}</span>
                <span class="changelog-datum">${g.datum}</span>
            </div>
            <ul class="changelog-lijst">
                ${g.items.map(it => `<li>${it.tekst[lang] || it.tekst.en || it.tekst.nl}</li>`).join('')}
            </ul>
        </div>`).join('');
}

// ── App-specifiek vertaal-woordenboek (NL + EN + DE + FR) ──────────────────
// Toggle via vlag-knop in header. Persisteert in localStorage onder 'ic_lang'.
// Dynamische content (rendered via JS) gebruikt t('key'); statische HTML
// gebruikt data-i18n* attributen die applyI18n() bij init en bij toggle leest.
const T = {
    nl: {
        // ── Document ──
        page_title: 'InlineComp – Mijn wedstrijd',
        // ── Header / static ──
        ptr_trek: '↓ Trek verder om te vernieuwen',
        hdr_meldingen_title: 'Mededelingen voor deze wedstrijd',
        hdr_info_title: 'Over InlineComp',
        hdr_help_title: 'Hoe werkt het?',
        hdr_sub: 'Zoek je heats, starttijden en resultaten',
        pwa_installeer_titel: 'Installeer InlineComp',
        pwa_installeer_uitleg: 'Voeg toe aan je startscherm voor snelle toegang',
        pwa_btn_install: 'Installeer',
        pwa_btn_sluit: 'Sluiten',
        profiel_promo_titel: 'Nieuw: Mijn InlineComp',
        profiel_promo_uitleg: 'Je persoonlijke profiel met records & progressie',
        profiel_promo_demo: 'Bekijk voorbeeld',
        profiel_promo_niet: 'Niet meer tonen',
        stap1_label: 'Kies je wedstrijd',
        stap2_label: 'Startnummer, licentie of achternaam',
        setup_strip_leeg: 'Kies je wedstrijd…',
        setup_strip_edit_title: 'Wedstrijd of rijder wijzigen',
        setup_modal_titel: 'Wedstrijd & rijder',
        setup_strip_rijders: 'rijders',
        rondeu_pending: 'Nog niet compleet',
        rondeu_nog_niets: 'Nog geen resultaten voor deze afstand.',
        rondeu_eind_titel: 'Eindstand',
        rondeu_col_pos: '#',
        rondeu_col_rang: 'Pl',
        rondeu_col_snr: 'Snr',
        rondeu_col_naam: 'Naam',
        rondeu_col_kwal: 'Q',
        rondeu_col_tijd: 'Tijd',
        rondeu_col_sanctie: 'Sanctie',
        rondeu_col_note: 'Note',
        rondeu_col_fin: 'Fin',
        rondeu_col_rondes: 'Rnd',
        rondeu_col_pkpt: 'Pnt',
        filter_eerder: 'Eerder',
        filter_eerder_title: 'Eerdere wedstrijden',
        filter_vandaag: 'Vandaag',
        filter_later: 'Later',
        filter_later_title: 'Toekomstige wedstrijden',
        opt_laden: 'Laden…',
        zoek_placeholder: 'Startnummer, licentienr of achternaam…',
        zoek_max_hint: 'Je volgt al het maximum van {max} rijders. Verwijder er eerst één hierboven om een andere toe te voegen.',
        btn_zoeken: 'Zoeken',
        // ── Connection banner ──
        conn_geen_internet: '📡 Geen internet — ververst zodra de verbinding terug is',
        conn_server_down: '⚠ Server niet bereikbaar — opnieuw proberen…',
        conn_laatste_update: 'laatste update {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Kies tenminste één filter hierboven —',
        opt_kies_wedstrijd: '— Kies een wedstrijd —',
        opt_binnenkort: '(binnenkort)',
        opt_fout_laden: 'Fout bij laden',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Laden…',
        msg_zoeken: 'Zoeken…',
        msg_zoeken_op: 'Zoeken op "{term}"…',
        msg_rijders_ophalen: 'Rijders ophalen…',
        msg_je_rijders_ophalen: 'Je rijders ophalen…',
        msg_geen_resultaten: 'Geen resultaten gevonden.',
        msg_geen_rijders: 'Geen rijders gevonden.',
        msg_naam_geen_of_of: 'Geen rijder op deze naam gevonden. Mogelijk doet deze rijder niet mee aan deze wedstrijd, óf koos hij/zij ervoor anoniem te blijven — voeg de rijder in dat geval toe via het licentie- of ID-nummer in het zoekveld hierboven.',
        msg_rijder_anoniem: 'Deze rijder is anoniem. Volgen kan alleen met het persoonlijke volg-ID dat de rijder zelf deelt (via ‘Mijn InlineComp’ of de organisatie).',
        msg_geen_startlijst: 'Geen startlijst beschikbaar voor deze rit.',
        msg_geen_klassement: 'Geen klassement beschikbaar.',
        msg_geen_uitslagen: 'Geen uitslagen beschikbaar.',
        msg_geen_posities: 'Geen posities in deze categorie.',
        klas_niet_opgenomen: 'Niet opgenomen in klassement',
        msg_kies_categorie_klassement: 'Kies een categorie om het klassement te zien.',
        msg_programma_nb: 'Programma niet beschikbaar.',
        msg_nog_geen_heats: 'Nog geen heats beschikbaar.',
        msg_nog_geen_resultaten: 'Nog geen resultaten beschikbaar.',
        msg_vorige_ronde_nb: 'Vorige ronde nog niet compleet — startlijst verschijnt zodra alle resultaten daar binnen zijn.',
        chooser_titel: 'Zoekresultaten voor "{term}"',
        chooser_sluit: 'Sluiten',
        chooser_al_in_lijst: 'al in lijst',
        chooser_doet_niet_mee: 'doet niet mee in deze wedstrijd',
        chooser_max: 'Max {max} rijders · {vrij} plek(ken) vrij',
        setup_volg_label: 'Je gevolgde rijders',
        chooser_toevoegen: 'Toevoegen',
        alert_max_bereikt: 'Maximum van {max} rijders bereikt. Verwijder eerst iemand om een nieuwe toe te voegen.',
        alert_max_select: 'Maximum {max} — er is nog plek voor {vrij}. Je hebt er {n} aangevinkt.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(rijder)',
        kind_tab_verwijder: 'Verwijder deze rijder',
        kind_plus_title: 'Voeg broertje/zusje toe',
        kind_plus_max: 'Maximum {max} rijders',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Niet ingeschreven',
        status_0: 'Niet bevestigd',
        status_1: 'Bevestigd',
        status_2: 'Afgemeld',
        status_3: 'Afgem. bij org.',
        status_klik: 'klik voor status',
        status_per_afstand: 'Status per afstand',
        status_4: 'Niet getekend',
        status_5: 'Bev. bij org.',
        status_onbekend: '?',
        snr_label: 'Snr',
        auto_stempel_title: 'Tijdstip laatste auto-refresh',
        // ── Tabs ──
        tab_programma: '📅\nProgramma',
        tab_heats: '🏃\nHeats',
        tab_rondes: '📈\nRondes',
        tab_uitslagen: '📊\nUitslagen',
        // ── Programma ──
        prog_titel: 'Wedstrijdprogramma',
        prog_combi_kop: '🔗 Gecombineerde rit — rijden tegelijk',
        prog_blok_pauze: 'Pauze',
        prog_blok_inrijden: 'Inrijden',
        prog_blok_wedstrijdstart: 'Wedstrijd start',
        prog_blok_ceremonie: 'Ceremonie',
        prog_blok_herstart: 'Herstart',
        prog_blok_min: 'min',
        // Multi-day filter
        prog_dag_alle: 'Alle',
        prog_dag: 'Dag',
        prog_afstand_alle: 'Alle',
        prog_filter_alle_dagen: 'Alle dagen',
        prog_filter_alle_afstanden: 'Alle afstanden',
        prog_samenvat_heat_1: '1 heat',
        prog_samenvat_heat_n: '{n} heats',
        // Programma-filter pills (alleen-mijn / alleen-nog-te-rijden)
        prog_filter_mijn: '👤 Mijn ritten',
        prog_filter_te_rijden: '⏳ Nog te rijden',
        prog_klap_alles_uit:  'Inklappen',
        prog_klap_alles_in:   'Uitklappen',
        prog_klap_mijn:       'Mijn ritten',
        prog_leg_mijn:        'Geselecteerde rijder',
        prog_leg_familie:     'Ander gevolgd kind',
        prog_multi_title:     'Ook een ander gevolgd kind rijdt in deze rit',
        prog_klap_mijn_tooltip_pub: 'Jij zit in deze groep',
        prog_groep_status_klaar:  'Alle ritten in deze groep zijn verreden',
        prog_groep_status_deels:  'Uitslagverwerking bezig — deels verreden',
        prog_groep_status_geloot: 'Loting bekend voor alle ritten',
        // ── Heats ──
        heat_wachten_vorige: 'Wachten op vorige ronde',
        heat_jouw_resultaat: 'Jij:',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Snr',
        col_naam: 'Naam',
        col_rnd: 'Rnd',
        col_pnt: 'Pnt',
        pk_vervallen: 'vervallen',
        col_tijd: 'Tijd',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Cat',
        col_tot: 'Tot',
        // ── Rondes ──
        ronde_serie: 'Serie',
        ronde_kf: 'KF',
        ronde_hf: 'HF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Runner-up',
        // ── Resultaten ──
        res_uitslagen_titel: 'Uitslagen per afstand',
        res_pt: 'pt',
        res_klassement: 'Klassement {dc}',
        res_punten: '{n} punten',
        // ── Uitslagen tab ──
        uitsl_titel: 'Volledige uitslagen van deze wedstrijd',
        uitsl_opt_kies_cat: '— Kies categorie —',
        uitsl_opt_kies_afstand: '— Kies afstand —',
        uitsl_klassement_opt: '🏆 Klassement',
        // ── Serie-klassement ──
        serie_titel: '🏆 Serie-klassement',
        serie_opt_kies: '— Kies een serie-klassement —',
        serie_opt_alle_cats: '— Alle categorieën —',
        serie_aantal_rijders: '{n} rijders',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Fout: {msg}',
        err_zoeken: 'Fout bij zoeken: {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Laat los om te vernieuwen',
        ptr_vernieuwen: '⟳ Vernieuwen…',
        ptr_bijgewerkt: '✓ Bijgewerkt',
        ptr_fout: '⚠ Fout bij vernieuwen',
        ptr_wachten: '⏳ Even wachten ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Mededelingen',
        meld_tot: ' tot ',
        meld_begrepen: '✓ Begrepen',
        // ── Info modal ──
        info_titel: 'Over InlineComp',
        info_h1: 'Wat is InlineComp?',
        info_p1: 'InlineComp is een wedstrijdbeheersysteem voor inline skaten, ontwikkeld om wedstrijdorganisaties te ondersteunen bij het beheren van startlijsten, live tijdwaarneming en het publiceren van uitslagen.',
        info_p2_html: 'Deze publieke pagina is bedoeld voor <b>rijders en toeschouwers</b>: zoek je startnummer op en bekijk direct je heats, starttijden en resultaten.',
        info_h2: 'In ontwikkeling',
        info_p3: 'InlineComp wordt actief doorontwikkeld. Functies kunnen veranderen en er kunnen nog fouten in zitten. Feedback is welkom.',
        info_h3_html: 'Contact &amp; feedback',
        info_p4: 'Heb je een vraag, suggestie of bug gevonden? Laat het weten:',
        info_h4: 'Anonieme bezoek-statistieken',
        info_p5_html: 'We tellen anoniem aantal bezoekers, actieve sessies en piek gelijktijdig online — puur om te zien hoe veel de app wordt gebruikt en om de hosting stabiel te houden. Er worden <b>geen IP-adressen of persoonsgegevens</b> opgeslagen en er zijn <b>geen derde partijen</b> betrokken.',
        info_h5_html: 'Privacy &amp; persoonsgegevens',
        info_p6: 'Deze app toont wedstrijdgegevens die door de KNSB of andere wedstrijdorganisaties aan ons worden geleverd (o.a. namen, startnummers, vereniging). In de privacyverklaring lees je welke gegevens wij verwerken, op welke grondslag en hoe je een verwijderverzoek kunt indienen.',
        info_btn_privacy: '📄 Bekijk privacyverklaring',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        info_versie: 'Versie',
        // "Wat is nieuw"-sectie in help + jump-knop bovenin
        nieuw_jump: 'Direct naar Wat is nieuw ↓',
        nieuw_h: 'Wat is nieuw?',
        nieuw_intro: 'Kort overzicht van recente wijzigingen. Voor terugkerende gebruikers een compacte samenvatting van de aanpassingen.',
        // ── Help modal ──
        help_titel: 'Hoe werkt InlineComp?',
        help_h1: 'Aan de slag',
        help_stap1_html: 'Kies je <b>wedstrijd</b> uit de lijst. Met de drie filter-knoppen — <i>Eerder</i>, <i>Vandaag</i> en <i>Later</i> — bepaal je welke wedstrijden je ziet. Standaard staat alleen <i>Vandaag</i> aan; klik een knop aan/uit om het bereik aan te passen.',
        help_stap2_html: 'Vul je <b>startnummer, licentie of achternaam</b> in en klik op <b>Zoeken</b> — je persoonlijke overzicht verschijnt.',
        help_stap3_html: 'Wil je meerdere rijders volgen (bv. broer, zus of een teamgenoot)? Klik op de <b>+</b>-knop bovenin. Je kunt tot <b>4 rijders</b> tegelijk volgen — wissel van rijder via de tabs bovenaan met hun startnummers.',
        help_mock_kies_w: 'Kies je wedstrijd',
        help_mock_voorbeeld: 'Voorbeeldwedstrijd — 19 april 2026',
        help_mock_snr_lic: 'Startnummer, licentie of achternaam',
        help_mock_snr: 'Startnummer: 86',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'Na het zoeken zie je <b>4 tabs</b>:',
        help_p_prog_html: '<b>Programma</b> — alle ritten van de wedstrijd. Jouw ritten zijn gemarkeerd. Tik op een rit om de startlijst te bekijken. Bovenaan filter je op afstand; met de balk daaronder klap je binnen die afstand groepen in of uit.',
        help_p_heats_html: '<b>Heats</b> — jouw heats met alle rijders. Je eigen rij is gemarkeerd. Na de finish worden de rijders op finish-volgorde weergegeven, met tijden en posities.',
        help_p_res_html: '<b>Rondes</b> — jouw persoonlijke uitslag per ronde (series, kwart, halve, A-finale, kleine finale). Zichtbaar is welke plek per ronde is behaald en of er is doorgestroomd naar de volgende ronde.',
        help_p_uitsl_html: '<b>Uitslagen</b> — de volledige uitslag van alle rijders. Kies een categorie en afstand, of bekijk het klassement.',
        help_mock_jouw_naam: 'Jouw naam',
        help_h_auto: 'Automatisch bijgewerkt',
        help_p_auto_html: 'De pagina ververst zichzelf elke 3 minuten zolang het tabblad zichtbaar is. Naast de wedstrijdnaam zie je <b>🔄 HH:MM</b> — dat is het tijdstip van de laatste verversing.',
        help_h_meld: 'Mededelingen',
        help_p_meld_html: 'Bovenaan staat een <b>📢-knop</b> (zichtbaar zodra er een mededeling actief is). Belangrijke aankondigingen van de organisatie verschijnen automatisch als pop-up en blijven daarna onder deze knop bereikbaar — bv. "Programma loopt 15 min uit".',
        help_h_tip: 'Tip',
        help_p_tip: 'Geen resultaten? De uitslag verschijnt zodra de jury de resultaten heeft bevestigd.',
        // ── Push-meldingen ──
        push_titel: 'Pushmeldingen op je telefoon',
        push_uitleg: 'Een pushmelding op je telefoon zodra er geloot is of een uitslag binnenkomt van je rijders — ook als de app dicht is.',
        push_niet: 'Niet ondersteund in deze browser.',
        push_ios: 'Op iPhone: voeg de app eerst toe aan je beginscherm.',
        push_uit: 'Uitzetten',
        push_aan: 'Aanzetten',
        push_loting: 'Loting bekend',
        push_uitslag: 'Uitslag verwerkt',
        push_bericht: 'Mededelingen',
        push_test: 'Stuur test',
        push_bezig: 'Bezig…',
        push_geweigerd: 'Meldingen geweigerd in je browser.',
        push_fout: 'Er ging iets mis.',
        push_testok: 'Test verstuurd ✓',
        push_mislukt: 'mislukt',
        help_h_push: 'Meldingen op je telefoon',
        help_p_push_html: 'Volg je een rijder, dan kun je <b>pushmeldingen</b> aanzetten via het ✎-strookje bovenaan (het <b>🔔-blok</b>). Je krijgt dan een seintje op je telefoon zodra er voor jouw rijder is <b>geloot 🚩</b> of een <b>uitslag 🏁</b> binnen is, en bij <b>mededelingen 📢</b> van de organisatie — óók als de app dicht is. Elk type is apart aan/uit te zetten. Op de iPhone werkt het alleen als de app op je beginscherm staat.',
    },
    en: {
        // ── Document ──
        page_title: 'InlineComp – My race',
        // ── Header / static ──
        ptr_trek: '↓ Pull further to refresh',
        hdr_meldingen_title: 'Announcements for this race',
        hdr_info_title: 'About InlineComp',
        hdr_help_title: 'How does it work?',
        hdr_sub: 'Find your heats, start times and results',
        pwa_installeer_titel: 'Install InlineComp',
        pwa_installeer_uitleg: 'Add to your home screen for quick access',
        pwa_btn_install: 'Install',
        pwa_btn_sluit: 'Close',
        profiel_promo_titel: 'New: My InlineComp',
        profiel_promo_uitleg: 'Your personal profile with records & progress',
        profiel_promo_demo: 'See example',
        profiel_promo_niet: 'Don\'t show again',
        stap1_label: 'Choose your race',
        setup_strip_leeg: 'Choose your race…',
        setup_strip_edit_title: 'Change race or skater',
        setup_modal_titel: 'Race & skater',
        setup_strip_rijders: 'skaters',
        rondeu_pending: 'Not yet complete',
        rondeu_nog_niets: 'No results yet for this distance.',
        rondeu_eind_titel: 'Final result',
        rondeu_col_pos: '#',
        rondeu_col_rang: 'Pl',
        rondeu_col_snr: 'Bib',
        rondeu_col_naam: 'Name',
        rondeu_col_kwal: 'Q',
        rondeu_col_tijd: 'Time',
        rondeu_col_sanctie: 'Penalty',
        rondeu_col_note: 'Note',
        rondeu_col_fin: 'Fin',
        rondeu_col_rondes: 'Lap',
        rondeu_col_pkpt: 'Pts',
        stap2_label: 'Start number, license or last name',
        filter_eerder: 'Earlier',
        filter_eerder_title: 'Earlier races',
        filter_vandaag: 'Today',
        filter_later: 'Later',
        filter_later_title: 'Upcoming races',
        opt_laden: 'Loading…',
        zoek_placeholder: 'Start number, license nr or last name…',
        zoek_max_hint: 'You already follow the maximum of {max} skaters. Remove one above first to add another.',
        btn_zoeken: 'Search',
        // ── Connection banner ──
        conn_geen_internet: '📡 No internet — will refresh when the connection returns',
        conn_server_down: '⚠ Server unreachable — retrying…',
        conn_laatste_update: 'last update {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Select at least one filter above —',
        opt_kies_wedstrijd: '— Choose a race —',
        opt_binnenkort: '(coming soon)',
        opt_fout_laden: 'Loading failed',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Loading…',
        msg_zoeken: 'Searching…',
        msg_zoeken_op: 'Searching for "{term}"…',
        msg_rijders_ophalen: 'Fetching skaters…',
        msg_je_rijders_ophalen: 'Fetching your skaters…',
        msg_geen_resultaten: 'No results found.',
        msg_geen_rijders: 'No skaters found.',
        msg_naam_geen_of_of: 'No skater found by that name. They may not be entered in this competition, or chose to stay anonymous — in that case add them using their licence or ID number in the search field above.',
        msg_rijder_anoniem: 'This skater is anonymous. You can only follow them with the personal follow-ID they share themselves (via ‘My InlineComp’ or the organisation).',
        msg_geen_startlijst: 'No start list available for this race.',
        msg_geen_klassement: 'No standings available.',
        msg_geen_uitslagen: 'No results available.',
        msg_geen_posities: 'No positions in this category.',
        klas_niet_opgenomen: 'Not included in classification',
        msg_kies_categorie_klassement: 'Choose a category to view the standings.',
        msg_programma_nb: 'Program not available.',
        msg_nog_geen_heats: 'No heats available yet.',
        msg_nog_geen_resultaten: 'No results available yet.',
        msg_vorige_ronde_nb: 'Previous round not complete yet — start list appears as soon as all results have been entered.',
        chooser_titel: 'Search results for "{term}"',
        chooser_sluit: 'Close',
        chooser_al_in_lijst: 'already in list',
        chooser_doet_niet_mee: 'not participating in this race',
        chooser_max: 'Max {max} skaters · {vrij} spot(s) free',
        setup_volg_label: 'Skaters you follow',
        chooser_toevoegen: 'Add',
        alert_max_bereikt: 'Maximum of {max} skaters reached. Remove someone first to add a new one.',
        alert_max_select: 'Maximum {max} — there is room for {vrij}. You selected {n}.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(skater)',
        kind_tab_verwijder: 'Remove this skater',
        kind_plus_title: 'Add brother/sister',
        kind_plus_max: 'Maximum {max} skaters',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Not registered',
        status_0: 'Not confirmed',
        status_1: 'Confirmed',
        status_2: 'Withdrawn',
        status_3: 'Withdrawn by org.',
        status_klik: 'tap for status',
        status_per_afstand: 'Status per distance',
        status_4: 'Not signed in',
        status_5: 'Confirmed by org.',
        status_onbekend: '?',
        snr_label: 'Nr',
        auto_stempel_title: 'Time of last auto-refresh',
        // ── Tabs ──
        tab_programma: '📅\nProgram',
        tab_heats: '🏃\nHeats',
        tab_rondes: '📈\nRounds',
        tab_uitslagen: '📊\nResults',
        // ── Programma ──
        prog_titel: 'Race program',
        prog_combi_kop: '🔗 Combined race — skating together',
        prog_blok_pauze: 'Break',
        prog_blok_inrijden: 'Warm-up',
        prog_blok_wedstrijdstart: 'Race start',
        prog_blok_ceremonie: 'Ceremony',
        prog_blok_herstart: 'Restart',
        prog_blok_min: 'min',
        // Programma-filter pills (alleen-mijn / alleen-nog-te-rijden)
        prog_filter_mijn: '👤 My races',
        prog_filter_te_rijden: '⏳ Upcoming',
        prog_klap_alles_uit:  'Collapse',
        prog_klap_alles_in:   'Expand',
        prog_klap_mijn:       'My races',
        prog_leg_mijn:        'Selected skater',
        prog_leg_familie:     'Other followed skater',
        prog_multi_title:     'Another followed skater also races in this race',
        prog_klap_mijn_tooltip_pub: 'You are in this group',
        prog_groep_status_klaar:  'All races in this group have been raced',
        prog_groep_status_deels:  'Result processing ongoing — partially raced',
        prog_groep_status_geloot: 'Draw complete for all races',
        // Multi-day filter
        prog_dag_alle: 'All',
        prog_dag: 'Day',
        prog_afstand_alle: 'All',
        prog_filter_alle_dagen: 'All days',
        prog_filter_alle_afstanden: 'All distances',
        prog_samenvat_heat_1: '1 heat',
        prog_samenvat_heat_n: '{n} heats',
        // ── Heats ──
        heat_wachten_vorige: 'Waiting for previous round',
        heat_jouw_resultaat: 'You:',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Nr',
        col_naam: 'Name',
        col_rnd: 'Lap',
        col_pnt: 'Pts',
        pk_vervallen: 'void',
        col_tijd: 'Time',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Cat',
        col_tot: 'Tot',
        // ── Rondes ──
        ronde_serie: 'Series',
        ronde_kf: 'QF',
        ronde_hf: 'SF',
        ronde_finale: 'Final',
        ronde_b_finale: 'B-Final',
        ronde_runner_up: 'Runner-up',
        // ── Resultaten ──
        res_uitslagen_titel: 'Results per distance',
        res_pt: 'pt',
        res_klassement: 'Standings {dc}',
        res_punten: '{n} points',
        // ── Uitslagen tab ──
        uitsl_titel: 'Full results of this race',
        uitsl_opt_kies_cat: '— Choose category —',
        uitsl_opt_kies_afstand: '— Choose distance —',
        uitsl_klassement_opt: '🏆 Standings',
        // ── Serie-klassement ──
        serie_titel: '🏆 Series standings',
        serie_opt_kies: '— Choose a series standings —',
        serie_opt_alle_cats: '— All categories —',
        serie_aantal_rijders: '{n} skaters',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Error: {msg}',
        err_zoeken: 'Search error: {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Release to refresh',
        ptr_vernieuwen: '⟳ Refreshing…',
        ptr_bijgewerkt: '✓ Updated',
        ptr_fout: '⚠ Refresh error',
        ptr_wachten: '⏳ Please wait ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Announcements',
        meld_tot: ' until ',
        meld_begrepen: '✓ Understood',
        // ── Info modal ──
        info_titel: 'About InlineComp',
        info_h1: 'What is InlineComp?',
        info_p1: 'InlineComp is a race management system for inline speed skating, developed to support race organizations in managing start lists, live timekeeping and publishing results.',
        info_p2_html: 'This public page is intended for <b>skaters and spectators</b>: look up your start number and view your heats, start times and results directly.',
        info_h2: 'In development',
        info_p3: 'InlineComp is actively being developed. Features may change and bugs may still occur. Feedback is welcome.',
        info_h3_html: 'Contact &amp; feedback',
        info_p4: 'Have a question, suggestion or found a bug? Let us know:',
        info_h4: 'Anonymous visit statistics',
        info_p5_html: 'We anonymously count visitor numbers, active sessions and peak concurrent users — purely to see how much the app is used and to keep hosting stable. <b>No IP addresses or personal data</b> are stored and <b>no third parties</b> are involved.',
        info_h5_html: 'Privacy &amp; personal data',
        info_p6: 'This app shows race data provided by the KNSB or other race organisations (incl. names, start numbers, club). The privacy statement details which data we process, on what basis and how to submit a removal request.',
        info_btn_privacy: '📄 View privacy statement',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        info_versie: 'Version',
        nieuw_jump: 'Jump to What\'s new ↓',
        nieuw_h: 'What\'s new?',
        nieuw_intro: 'Short overview of recent changes. A compact summary of what has been adjusted, aimed at returning users.',
        // ── Help modal ──
        help_titel: 'How does InlineComp work?',
        help_h1: 'Getting started',
        help_stap1_html: 'Choose your <b>race</b> from the list. With the three filter buttons — <i>Earlier</i>, <i>Today</i> and <i>Later</i> — you decide which races you see. By default only <i>Today</i> is on; click a button on/off to adjust the range.',
        help_stap2_html: 'Enter your <b>start number, license or last name</b> and click <b>Search</b> — your personal overview appears.',
        help_stap3_html: 'Want to follow multiple skaters (e.g. brother, sister or a teammate)? Click the <b>+</b> button at the top. You can follow up to <b>4 skaters</b> at once — switch via the tabs at the top with their start numbers.',
        help_mock_kies_w: 'Choose your race',
        help_mock_voorbeeld: 'Sample race — 19 April 2026',
        help_mock_snr_lic: 'Start number, license or last name',
        help_mock_snr: 'Start number: 86',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'After searching you see <b>4 tabs</b>:',
        help_p_prog_html: '<b>Program</b> — all races of the meet. Your races are highlighted. Tap a race to view the start list. Filter by distance at the top; use the bar below to collapse or expand groups within that distance.',
        help_p_heats_html: '<b>Heats</b> — your heats with all skaters. Your own row is highlighted. After the finish, skaters are shown in finish order, with times and positions.',
        help_p_res_html: '<b>Rounds</b> — your personal result per round (heats, quarter, semi, A-final, small final). Shows the position achieved in each round and whether you progressed to the next round.',
        help_p_uitsl_html: '<b>All results</b> — the full results of all skaters. Choose a category and distance, or view the standings.',
        help_mock_jouw_naam: 'Your name',
        help_h_auto: 'Automatically updated',
        help_p_auto_html: 'The page refreshes itself every 3 minutes as long as the tab is visible. Next to the race name you see <b>🔄 HH:MM</b> — that is the time of the last refresh.',
        help_h_meld: 'Announcements',
        help_p_meld_html: 'At the top is a <b>📢 button</b> (visible as soon as there is an active announcement). Important announcements from the organization appear automatically as a pop-up and remain accessible under this button afterwards — e.g. "Program is running 15 min behind".',
        help_h_tip: 'Tip',
        help_p_tip: 'No results yet? The result appears as soon as the jury has confirmed it.',
        // ── Push notifications ──
        push_titel: 'Push notifications on your phone',
        push_uitleg: 'A push notification on your phone as soon as a draw is made or a result comes in for your riders — even when the app is closed.',
        push_niet: 'Not supported in this browser.',
        push_ios: 'On iPhone: add the app to your home screen first.',
        push_uit: 'Turn off',
        push_aan: 'Turn on',
        push_loting: 'Draw ready',
        push_uitslag: 'Result processed',
        push_bericht: 'Announcements',
        push_test: 'Send test',
        push_bezig: 'Working…',
        push_geweigerd: 'Notifications blocked in your browser.',
        push_fout: 'Something went wrong.',
        push_testok: 'Test sent ✓',
        push_mislukt: 'failed',
        help_h_push: 'Notifications on your phone',
        help_p_push_html: 'When you follow a skater you can turn on <b>push notifications</b> via the ✎ strip at the top (the <b>🔔 block</b>). You then get an alert on your phone as soon as your skater has been <b>drawn 🚩</b> or a <b>result 🏁</b> comes in, and for <b>announcements 📢</b> from the organisation — even when the app is closed. Each type can be switched on/off separately. On iPhone it only works if the app is on your home screen.',
    },
    de: {
        // ── Document ──
        page_title: 'InlineComp – Mein Rennen',
        // ── Header / static ──
        ptr_trek: '↓ Weiter ziehen zum Aktualisieren',
        hdr_meldingen_title: 'Bekanntmachungen zu diesem Rennen',
        hdr_info_title: 'Über InlineComp',
        hdr_help_title: 'Wie funktioniert es?',
        hdr_sub: 'Finde deine Heats, Startzeiten und Ergebnisse',
        pwa_installeer_titel: 'InlineComp installieren',
        pwa_installeer_uitleg: 'Zum Startbildschirm hinzufügen für schnellen Zugriff',
        pwa_btn_install: 'Installieren',
        pwa_btn_sluit: 'Schließen',
        profiel_promo_titel: 'Neu: Mein InlineComp',
        profiel_promo_uitleg: 'Dein persönliches Profil mit Rekorden & Fortschritt',
        profiel_promo_demo: 'Beispiel ansehen',
        profiel_promo_niet: 'Nicht mehr anzeigen',
        stap1_label: 'Wähle dein Rennen',
        setup_strip_leeg: 'Wähle dein Rennen…',
        setup_strip_edit_title: 'Rennen oder Sportler ändern',
        setup_modal_titel: 'Rennen & Sportler',
        setup_strip_rijders: 'Sportler',
        rondeu_pending: 'Noch nicht vollständig',
        rondeu_nog_niets: 'Noch keine Ergebnisse für diese Distanz.',
        rondeu_eind_titel: 'Endergebnis',
        rondeu_col_pos: '#',
        rondeu_col_rang: 'Pl',
        rondeu_col_snr: 'Nr',
        rondeu_col_naam: 'Name',
        rondeu_col_kwal: 'Q',
        rondeu_col_tijd: 'Zeit',
        rondeu_col_sanctie: 'Strafe',
        rondeu_col_note: 'Notiz',
        rondeu_col_fin: 'Fin',
        rondeu_col_rondes: 'Rd',
        rondeu_col_pkpt: 'Pkt',
        stap2_label: 'Startnummer, Lizenz oder Nachname',
        filter_eerder: 'Früher',
        filter_eerder_title: 'Frühere Rennen',
        filter_vandaag: 'Heute',
        filter_later: 'Später',
        filter_later_title: 'Kommende Rennen',
        opt_laden: 'Lädt…',
        zoek_placeholder: 'Startnummer, Lizenznr. oder Nachname…',
        zoek_max_hint: 'Du verfolgst bereits das Maximum von {max} Läufern. Entferne zuerst einen oben, um einen anderen hinzuzufügen.',
        btn_zoeken: 'Suchen',
        // ── Connection banner ──
        conn_geen_internet: '📡 Kein Internet — wird bei wiederhergestellter Verbindung aktualisiert',
        conn_server_down: '⚠ Server nicht erreichbar — Neuversuch…',
        conn_laatste_update: 'letzte Aktualisierung {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Wähle mindestens einen Filter oben —',
        opt_kies_wedstrijd: '— Rennen wählen —',
        opt_binnenkort: '(in Kürze)',
        opt_fout_laden: 'Laden fehlgeschlagen',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Lädt…',
        msg_zoeken: 'Suche…',
        msg_zoeken_op: 'Suche nach "{term}"…',
        msg_rijders_ophalen: 'Skater abrufen…',
        msg_je_rijders_ophalen: 'Deine Skater abrufen…',
        msg_geen_resultaten: 'Keine Ergebnisse gefunden.',
        msg_geen_rijders: 'Keine Skater gefunden.',
        msg_naam_geen_of_of: 'Kein Skater mit diesem Namen gefunden. Möglicherweise nimmt er/sie nicht an diesem Wettkampf teil oder hat sich für Anonymität entschieden — füge ihn/sie in dem Fall über die Lizenz- oder ID-Nummer im Suchfeld oben hinzu.',
        msg_rijder_anoniem: 'Dieser Skater ist anonym. Folgen ist nur mit der persönlichen Folge-ID möglich, die der Skater selbst teilt (über ‚Mein InlineComp‘ oder die Organisation).',
        msg_geen_startlijst: 'Keine Startliste für dieses Rennen verfügbar.',
        msg_geen_klassement: 'Keine Wertung verfügbar.',
        msg_geen_uitslagen: 'Keine Ergebnisse verfügbar.',
        msg_geen_posities: 'Keine Positionen in dieser Kategorie.',
        klas_niet_opgenomen: 'Nicht in der Wertung',
        msg_kies_categorie_klassement: 'Wähle eine Kategorie, um die Wertung anzuzeigen.',
        msg_programma_nb: 'Programm nicht verfügbar.',
        msg_nog_geen_heats: 'Noch keine Heats verfügbar.',
        msg_nog_geen_resultaten: 'Noch keine Ergebnisse verfügbar.',
        msg_vorige_ronde_nb: 'Vorherige Runde noch nicht abgeschlossen — Startliste erscheint, sobald alle Ergebnisse eingetragen sind.',
        chooser_titel: 'Suchergebnisse für "{term}"',
        chooser_sluit: 'Schließen',
        chooser_al_in_lijst: 'bereits in Liste',
        chooser_doet_niet_mee: 'nimmt nicht an diesem Rennen teil',
        chooser_max: 'Max. {max} Skater · {vrij} Platz(e) frei',
        setup_volg_label: 'Deine verfolgten Läufer',
        chooser_toevoegen: 'Hinzufügen',
        alert_max_bereikt: 'Maximum von {max} Skatern erreicht. Entferne zuerst jemanden, um einen neuen hinzuzufügen.',
        alert_max_select: 'Maximum {max} — es ist Platz für {vrij}. Du hast {n} ausgewählt.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(Skater)',
        kind_tab_verwijder: 'Diesen Skater entfernen',
        kind_plus_title: 'Bruder/Schwester hinzufügen',
        kind_plus_max: 'Maximum {max} Skater',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Nicht angemeldet',
        status_0: 'Nicht bestätigt',
        status_1: 'Bestätigt',
        status_2: 'Abgemeldet',
        status_3: 'Abgem. bei Org.',
        status_klik: 'für Status tippen',
        status_per_afstand: 'Status pro Distanz',
        status_4: 'Nicht unterschrieben',
        status_5: 'Best. bei Org.',
        status_onbekend: '?',
        snr_label: 'Nr.',
        auto_stempel_title: 'Zeitpunkt der letzten automatischen Aktualisierung',
        // ── Tabs ──
        tab_programma: '📅\nProgramm',
        tab_heats: '🏃\nHeats',
        tab_rondes: '📈\nRunden',
        tab_uitslagen: '📊\nErgebnisse',
        // ── Programma ──
        prog_titel: 'Rennprogramm',
        prog_combi_kop: '🔗 Kombiniertes Rennen — gleichzeitig laufen',
        prog_blok_pauze: 'Pause',
        prog_blok_inrijden: 'Einlaufen',
        prog_blok_wedstrijdstart: 'Rennbeginn',
        prog_blok_ceremonie: 'Zeremonie',
        prog_blok_herstart: 'Neustart',
        prog_blok_min: 'Min.',
        // Multi-day filter
        prog_dag_alle: 'Alle',
        prog_dag: 'Tag',
        prog_afstand_alle: 'Alle',
        prog_filter_alle_dagen: 'Alle Tage',
        prog_filter_alle_afstanden: 'Alle Distanzen',
        prog_samenvat_heat_1: '1 Heat',
        prog_samenvat_heat_n: '{n} Heats',
        // Programma-filter pills
        prog_filter_mijn: '👤 Meine Rennen',
        prog_filter_te_rijden: '⏳ Kommende',
        prog_klap_alles_uit:  'Einklappen',
        prog_klap_alles_in:   'Ausklappen',
        prog_klap_mijn:       'Meine Rennen',
        prog_leg_mijn:        'Ausgewählter Läufer',
        prog_leg_familie:     'Anderer verfolgter Läufer',
        prog_multi_title:     'Auch ein anderer verfolgter Läufer startet in diesem Lauf',
        prog_klap_mijn_tooltip_pub: 'Du bist in dieser Gruppe',
        prog_groep_status_klaar:  'Alle Rennen dieser Gruppe wurden gefahren',
        prog_groep_status_deels:  'Ergebnisverarbeitung läuft — teilweise gefahren',
        prog_groep_status_geloot: 'Auslosung für alle Rennen bekannt',
        // ── Heats ──
        heat_wachten_vorige: 'Warte auf vorherige Runde',
        heat_jouw_resultaat: 'Du:',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Nr.',
        col_naam: 'Name',
        col_rnd: 'Runde',
        col_pnt: 'Pkt.',
        pk_vervallen: 'verfallen',
        col_tijd: 'Zeit',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Kat',
        col_tot: 'Ges.',
        // ── Rondes ──
        ronde_serie: 'Serie',
        ronde_kf: 'VF',
        ronde_hf: 'HF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Runner-up',
        // ── Resultaten ──
        res_uitslagen_titel: 'Ergebnisse pro Distanz',
        res_pt: 'Pkt',
        res_klassement: 'Wertung {dc}',
        res_punten: '{n} Punkte',
        // ── Uitslagen tab ──
        uitsl_titel: 'Vollständige Ergebnisse dieses Rennens',
        uitsl_opt_kies_cat: '— Kategorie wählen —',
        uitsl_opt_kies_afstand: '— Distanz wählen —',
        uitsl_klassement_opt: '🏆 Wertung',
        // ── Serie-klassement ──
        serie_titel: '🏆 Serien-Wertung',
        serie_opt_kies: '— Serien-Wertung wählen —',
        serie_opt_alle_cats: '— Alle Kategorien —',
        serie_aantal_rijders: '{n} Skater',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Fehler: {msg}',
        err_zoeken: 'Suchfehler: {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Loslassen zum Aktualisieren',
        ptr_vernieuwen: '⟳ Aktualisiere…',
        ptr_bijgewerkt: '✓ Aktualisiert',
        ptr_fout: '⚠ Aktualisierungsfehler',
        ptr_wachten: '⏳ Bitte warten ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Bekanntmachungen',
        meld_tot: ' bis ',
        meld_begrepen: '✓ Verstanden',
        // ── Info modal ──
        info_titel: 'Über InlineComp',
        info_h1: 'Was ist InlineComp?',
        info_p1: 'InlineComp ist ein Wettkampfverwaltungssystem für Inline-Speedskating, entwickelt um Rennorganisationen bei Startlisten, Live-Zeitmessung und Ergebnisveröffentlichung zu unterstützen.',
        info_p2_html: 'Diese öffentliche Seite ist für <b>Skater und Zuschauer</b> gedacht: suche deine Startnummer und sieh direkt deine Heats, Startzeiten und Ergebnisse.',
        info_h2: 'In Entwicklung',
        info_p3: 'InlineComp wird aktiv weiterentwickelt. Funktionen können sich ändern und es können noch Fehler vorkommen. Feedback ist willkommen.',
        info_h3_html: 'Kontakt &amp; Feedback',
        info_p4: 'Hast du eine Frage, einen Vorschlag oder einen Bug gefunden? Lass es uns wissen:',
        info_h4: 'Anonyme Besuchsstatistiken',
        info_p5_html: 'Wir zählen anonym Besucherzahlen, aktive Sitzungen und Spitzenwerte gleichzeitiger Nutzer — nur um zu sehen wie viel die App genutzt wird und das Hosting stabil zu halten. Es werden <b>keine IP-Adressen oder persönlichen Daten</b> gespeichert und <b>keine Dritten</b> sind beteiligt.',
        info_h5_html: 'Privatsphäre &amp; persönliche Daten',
        info_p6: 'Diese App zeigt Wettkampfdaten, die uns vom KNSB oder anderen Wettkampforganisationen geliefert werden (u.a. Namen, Startnummern, Verein). In der Datenschutzerklärung steht welche Daten wir verarbeiten, auf welcher Grundlage und wie du einen Löschantrag einreichen kannst.',
        info_btn_privacy: '📄 Datenschutzerklärung ansehen',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        info_versie: 'Version',
        nieuw_jump: 'Direkt zu Was ist neu ↓',
        nieuw_h: 'Was ist neu?',
        nieuw_intro: 'Kurze Übersicht der jüngsten Änderungen. Für wiederkehrende Nutzer eine kompakte Zusammenfassung der Anpassungen.',
        // ── Help modal ──
        help_titel: 'Wie funktioniert InlineComp?',
        help_h1: 'Loslegen',
        help_stap1_html: 'Wähle dein <b>Rennen</b> aus der Liste. Mit den drei Filter-Buttons — <i>Früher</i>, <i>Heute</i> und <i>Später</i> — entscheidest du welche Rennen du siehst. Standardmäßig ist nur <i>Heute</i> aktiv; klicke einen Button an/aus, um den Bereich anzupassen.',
        help_stap2_html: 'Gib deine <b>Startnummer, Lizenz oder deinen Nachnamen</b> ein und klicke auf <b>Suchen</b> — deine persönliche Übersicht erscheint.',
        help_stap3_html: 'Möchtest du mehrere Skater verfolgen (z.B. Bruder, Schwester oder Teamkollege)? Klicke auf den <b>+</b>-Button oben. Du kannst bis zu <b>4 Skater</b> gleichzeitig verfolgen — wechsle über die Tabs oben mit ihren Startnummern.',
        help_mock_kies_w: 'Wähle dein Rennen',
        help_mock_voorbeeld: 'Beispielrennen — 19. April 2026',
        help_mock_snr_lic: 'Startnummer, Lizenz oder Nachname',
        help_mock_snr: 'Startnummer: 86',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'Nach dem Suchen siehst du <b>4 Tabs</b>:',
        help_p_prog_html: '<b>Programm</b> — alle Rennen der Veranstaltung. Deine Rennen sind markiert. Tippe auf ein Rennen für die Startliste. Oben kannst du nach Distanz filtern; mit der Leiste darunter klappst du Gruppen innerhalb dieser Distanz ein oder aus.',
        help_p_heats_html: '<b>Heats</b> — deine Heats mit allen Skatern. Deine eigene Zeile ist markiert. Nach dem Zieleinlauf werden die Läufer in Zieleinlaufreihenfolge angezeigt, mit Zeiten und Positionen.',
        help_p_res_html: '<b>Runden</b> — dein persönliches Ergebnis pro Runde (Vorläufe, Viertel, Halbfinale, A-Finale, kleines Finale). Zeigt die in jeder Runde erreichte Platzierung und ob ein Weiterkommen in die nächste Runde erfolgt ist.',
        help_p_uitsl_html: '<b>Ergebnisse</b> — die vollständigen Ergebnisse aller Skater. Wähle eine Kategorie und Distanz, oder sieh die Wertung.',
        help_mock_jouw_naam: 'Dein Name',
        help_h_auto: 'Automatisch aktualisiert',
        help_p_auto_html: 'Die Seite aktualisiert sich alle 3 Minuten solange der Tab sichtbar ist. Neben dem Rennnamen siehst du <b>🔄 HH:MM</b> — das ist der Zeitpunkt der letzten Aktualisierung.',
        help_h_meld: 'Bekanntmachungen',
        help_p_meld_html: 'Oben befindet sich ein <b>📢-Button</b> (sichtbar sobald eine aktive Bekanntmachung vorhanden ist). Wichtige Ankündigungen der Organisation erscheinen automatisch als Pop-up und bleiben danach unter diesem Button erreichbar — z.B. "Programm läuft 15 Min hinterher".',
        help_h_tip: 'Tipp',
        help_p_tip: 'Noch keine Ergebnisse? Das Ergebnis erscheint, sobald die Jury es bestätigt hat.',
        // ── Push-Benachrichtigungen ──
        push_titel: 'Push-Benachrichtigungen auf dein Handy',
        push_uitleg: 'Eine Push-Benachrichtigung auf dein Handy, sobald eine Auslosung erfolgt oder ein Ergebnis deiner Fahrer eintrifft — auch wenn die App geschlossen ist.',
        push_niet: 'In diesem Browser nicht unterstützt.',
        push_ios: 'Auf dem iPhone: füge die App zuerst zum Startbildschirm hinzu.',
        push_uit: 'Ausschalten',
        push_aan: 'Einschalten',
        push_loting: 'Auslosung bekannt',
        push_uitslag: 'Ergebnis verarbeitet',
        push_bericht: 'Mitteilungen',
        push_test: 'Test senden',
        push_bezig: 'Läuft…',
        push_geweigerd: 'Benachrichtigungen im Browser blockiert.',
        push_fout: 'Etwas ist schiefgelaufen.',
        push_testok: 'Test gesendet ✓',
        push_mislukt: 'fehlgeschlagen',
        help_h_push: 'Benachrichtigungen auf deinem Handy',
        help_p_push_html: 'Wenn du einen Fahrer verfolgst, kannst du <b>Push-Benachrichtigungen</b> über die ✎-Leiste oben aktivieren (der <b>🔔-Block</b>). Du bekommst dann eine Meldung auf dein Handy, sobald dein Fahrer <b>ausgelost 🚩</b> wurde oder ein <b>Ergebnis 🏁</b> vorliegt, sowie bei <b>Mitteilungen 📢</b> der Organisation — auch wenn die App geschlossen ist. Jeder Typ lässt sich einzeln ein-/ausschalten. Auf dem iPhone funktioniert es nur, wenn die App auf dem Startbildschirm liegt.',
    },
    fr: {
        // ── Document ──
        page_title: 'InlineComp – Ma course',
        // ── Header / static ──
        ptr_trek: '↓ Tirer plus loin pour actualiser',
        hdr_meldingen_title: 'Annonces pour cette course',
        hdr_info_title: 'À propos d\'InlineComp',
        hdr_help_title: 'Comment ça marche ?',
        hdr_sub: 'Trouve tes séries, horaires et résultats',
        pwa_installeer_titel: 'Installer InlineComp',
        pwa_installeer_uitleg: 'Ajoute à ton écran d\'accueil pour un accès rapide',
        pwa_btn_install: 'Installer',
        pwa_btn_sluit: 'Fermer',
        profiel_promo_titel: 'Nouveau : Mon InlineComp',
        profiel_promo_uitleg: 'Ton profil personnel avec records & progression',
        profiel_promo_demo: 'Voir l’exemple',
        profiel_promo_niet: 'Ne plus afficher',
        stap1_label: 'Choisis ta course',
        setup_strip_leeg: 'Choisis ta course…',
        setup_strip_edit_title: 'Modifier la course ou le coureur',
        setup_modal_titel: 'Course & coureur',
        setup_strip_rijders: 'coureurs',
        rondeu_pending: 'Pas encore complet',
        rondeu_nog_niets: 'Aucun résultat pour cette distance.',
        rondeu_eind_titel: 'Classement final',
        rondeu_col_pos: '#',
        rondeu_col_rang: 'Pl',
        rondeu_col_snr: 'Dos',
        rondeu_col_naam: 'Nom',
        rondeu_col_kwal: 'Q',
        rondeu_col_tijd: 'Temps',
        rondeu_col_sanctie: 'Sanction',
        rondeu_col_note: 'Note',
        rondeu_col_fin: 'Fin',
        rondeu_col_rondes: 'Tr',
        rondeu_col_pkpt: 'Pts',
        stap2_label: 'Numéro de dossard, licence ou nom de famille',
        filter_eerder: 'Avant',
        filter_eerder_title: 'Courses précédentes',
        filter_vandaag: 'Aujourd\'hui',
        filter_later: 'Plus tard',
        filter_later_title: 'Courses à venir',
        opt_laden: 'Chargement…',
        zoek_placeholder: 'Numéro de dossard, nº de licence ou nom…',
        zoek_max_hint: 'Tu suis déjà le maximum de {max} skateurs. Supprime-en un ci-dessus pour en ajouter un autre.',
        btn_zoeken: 'Rechercher',
        // ── Connection banner ──
        conn_geen_internet: '📡 Pas d\'internet — actualisation dès le retour de la connexion',
        conn_server_down: '⚠ Serveur inaccessible — nouvel essai…',
        conn_laatste_update: 'dernière mise à jour {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Sélectionne au moins un filtre ci-dessus —',
        opt_kies_wedstrijd: '— Choisis une course —',
        opt_binnenkort: '(bientôt)',
        opt_fout_laden: 'Échec du chargement',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Chargement…',
        msg_zoeken: 'Recherche…',
        msg_zoeken_op: 'Recherche pour "{term}"…',
        msg_rijders_ophalen: 'Récupération des skateurs…',
        msg_je_rijders_ophalen: 'Récupération de tes skateurs…',
        msg_geen_resultaten: 'Aucun résultat trouvé.',
        msg_geen_rijders: 'Aucun skateur trouvé.',
        msg_naam_geen_of_of: 'Aucun skateur trouvé sous ce nom. Il/elle ne participe peut-être pas à cette compétition, ou a choisi de rester anonyme — dans ce cas, ajoutez-le/la via son numéro de licence ou d\'ID dans le champ de recherche ci-dessus.',
        msg_rijder_anoniem: 'Ce skateur est anonyme. Le suivi n\'est possible qu\'avec l\'ID de suivi personnel que le skateur partage lui-même (via « Mon InlineComp » ou l\'organisation).',
        msg_geen_startlijst: 'Aucune liste de départ disponible pour cette course.',
        msg_geen_klassement: 'Aucun classement disponible.',
        msg_geen_uitslagen: 'Aucun résultat disponible.',
        msg_geen_posities: 'Aucune position dans cette catégorie.',
        klas_niet_opgenomen: 'Non inclus au classement',
        msg_kies_categorie_klassement: 'Choisis une catégorie pour voir le classement.',
        msg_programma_nb: 'Programme non disponible.',
        msg_nog_geen_heats: 'Aucune série disponible pour le moment.',
        msg_nog_geen_resultaten: 'Aucun résultat disponible pour le moment.',
        msg_vorige_ronde_nb: 'Tour précédent pas encore terminé — la liste de départ apparaît dès que tous les résultats sont entrés.',
        chooser_titel: 'Résultats de recherche pour "{term}"',
        chooser_sluit: 'Fermer',
        chooser_al_in_lijst: 'déjà dans la liste',
        chooser_doet_niet_mee: 'ne participe pas à cette course',
        chooser_max: 'Max. {max} skateurs · {vrij} place(s) libre(s)',
        setup_volg_label: 'Skateurs que tu suis',
        chooser_toevoegen: 'Ajouter',
        alert_max_bereikt: 'Maximum de {max} skateurs atteint. Retire d\'abord quelqu\'un pour en ajouter un nouveau.',
        alert_max_select: 'Maximum {max} — il reste de la place pour {vrij}. Tu en as sélectionné {n}.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(skateur)',
        kind_tab_verwijder: 'Retirer ce skateur',
        kind_plus_title: 'Ajouter frère/sœur',
        kind_plus_max: 'Maximum {max} skateurs',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Non inscrit',
        status_0: 'Non confirmé',
        status_1: 'Confirmé',
        status_2: 'Désinscrit',
        status_3: 'Désinsc. à l\'org.',
        status_klik: 'voir le statut',
        status_per_afstand: 'Statut par distance',
        status_4: 'Non signé',
        status_5: 'Conf. à l\'org.',
        status_onbekend: '?',
        snr_label: 'Nº',
        auto_stempel_title: 'Heure de la dernière actualisation automatique',
        // ── Tabs ──
        tab_programma: '📅\nProgramme',
        tab_heats: '🏃\nSéries',
        tab_rondes: '📈\nRondes',
        tab_uitslagen: '📊\nRésultats',
        // ── Programma ──
        prog_titel: 'Programme de course',
        prog_combi_kop: '🔗 Course combinée — patinage simultané',
        prog_blok_pauze: 'Pause',
        prog_blok_inrijden: 'Échauffement',
        prog_blok_wedstrijdstart: 'Départ de course',
        prog_blok_ceremonie: 'Cérémonie',
        prog_blok_herstart: 'Redémarrage',
        prog_blok_min: 'min',
        // Multi-day filter
        prog_dag_alle: 'Tous',
        prog_dag: 'Jour',
        prog_afstand_alle: 'Toutes',
        prog_filter_alle_dagen: 'Tous les jours',
        prog_filter_alle_afstanden: 'Toutes les distances',
        prog_samenvat_heat_1: '1 série',
        prog_samenvat_heat_n: '{n} séries',
        // Programma-filter pills
        prog_filter_mijn: '👤 Mes courses',
        prog_filter_te_rijden: '⏳ À venir',
        prog_klap_alles_uit:  'Réduire',
        prog_klap_alles_in:   'Développer',
        prog_klap_mijn:       'Mes courses',
        prog_leg_mijn:        'Skateur sélectionné',
        prog_leg_familie:     'Autre skateur suivi',
        prog_multi_title:     'Un autre skateur suivi court aussi dans cette course',
        prog_klap_mijn_tooltip_pub: 'Tu es dans ce groupe',
        prog_groep_status_klaar:  'Toutes les courses de ce groupe sont terminées',
        prog_groep_status_deels:  'Traitement des résultats en cours — partiel',
        prog_groep_status_geloot: 'Tirage effectué pour toutes les courses',
        // ── Heats ──
        heat_wachten_vorige: 'En attente du tour précédent',
        heat_jouw_resultaat: 'Toi :',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Nº',
        col_naam: 'Nom',
        col_rnd: 'Tour',
        col_pnt: 'Pts',
        pk_vervallen: 'annulés',
        col_tijd: 'Temps',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Cat',
        col_tot: 'Tot',
        // ── Rondes ──
        ronde_serie: 'Série',
        ronde_kf: 'QF',
        ronde_hf: 'DF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Repêchage',
        // ── Resultaten ──
        res_uitslagen_titel: 'Résultats par distance',
        res_pt: 'pt',
        res_klassement: 'Classement {dc}',
        res_punten: '{n} points',
        // ── Uitslagen tab ──
        uitsl_titel: 'Résultats complets de cette course',
        uitsl_opt_kies_cat: '— Choisir catégorie —',
        uitsl_opt_kies_afstand: '— Choisir distance —',
        uitsl_klassement_opt: '🏆 Classement',
        // ── Serie-klassement ──
        serie_titel: '🏆 Classement de série',
        serie_opt_kies: '— Choisir un classement de série —',
        serie_opt_alle_cats: '— Toutes catégories —',
        serie_aantal_rijders: '{n} skateurs',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Erreur : {msg}',
        err_zoeken: 'Erreur de recherche : {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Relâche pour actualiser',
        ptr_vernieuwen: '⟳ Actualisation…',
        ptr_bijgewerkt: '✓ Mis à jour',
        ptr_fout: '⚠ Erreur d\'actualisation',
        ptr_wachten: '⏳ Patiente ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Annonces',
        meld_tot: ' jusqu\'à ',
        meld_begrepen: '✓ Compris',
        // ── Info modal ──
        info_titel: 'À propos d\'InlineComp',
        info_h1: 'Qu\'est-ce qu\'InlineComp ?',
        info_p1: 'InlineComp est un système de gestion de course pour le patinage de vitesse en ligne, développé pour aider les organisations à gérer les listes de départ, le chronométrage en direct et la publication des résultats.',
        info_p2_html: 'Cette page publique est destinée aux <b>skateurs et spectateurs</b> : cherche ton numéro de dossard et consulte directement tes séries, horaires et résultats.',
        info_h2: 'En développement',
        info_p3: 'InlineComp est en développement actif. Les fonctions peuvent changer et des bugs peuvent encore exister. Les commentaires sont bienvenus.',
        info_h3_html: 'Contact &amp; commentaires',
        info_p4: 'Une question, suggestion ou bug trouvé ? Fais-le nous savoir :',
        info_h4: 'Statistiques de visite anonymes',
        info_p5_html: 'Nous comptons anonymement le nombre de visiteurs, sessions actives et pics simultanés — uniquement pour voir l\'utilisation de l\'app et garder l\'hébergement stable. <b>Aucune adresse IP ni donnée personnelle</b> n\'est stockée et <b>aucun tiers</b> n\'est impliqué.',
        info_h5_html: 'Vie privée &amp; données personnelles',
        info_p6: 'Cette app affiche des données de course fournies par la KNSB ou d\'autres organisations de course (noms, dossards, club). La déclaration de confidentialité détaille quelles données nous traitons, sur quelle base et comment soumettre une demande de suppression.',
        info_btn_privacy: '📄 Voir la déclaration de confidentialité',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        info_versie: 'Version',
        nieuw_jump: 'Aller à Quoi de neuf ↓',
        nieuw_h: 'Quoi de neuf ?',
        nieuw_intro: 'Bref aperçu des changements récents. Un résumé compact des ajustements, destiné aux utilisateurs habitués.',
        // ── Help modal ──
        help_titel: 'Comment fonctionne InlineComp ?',
        help_h1: 'Démarrer',
        help_stap1_html: 'Choisis ta <b>course</b> dans la liste. Avec les trois boutons de filtre — <i>Avant</i>, <i>Aujourd\'hui</i> et <i>Plus tard</i> — tu décides quelles courses voir. Par défaut seul <i>Aujourd\'hui</i> est actif ; clique un bouton pour ajuster la plage.',
        help_stap2_html: 'Entre ton <b>numéro de dossard, licence ou nom de famille</b> et clique sur <b>Rechercher</b> — ton aperçu personnel apparaît.',
        help_stap3_html: 'Tu veux suivre plusieurs skateurs (par ex. frère, sœur ou coéquipier) ? Clique sur le bouton <b>+</b> en haut. Tu peux suivre jusqu\'à <b>4 skateurs</b> en même temps — change via les onglets en haut avec leurs dossards.',
        help_mock_kies_w: 'Choisis ta course',
        help_mock_voorbeeld: 'Course exemple — 19 avril 2026',
        help_mock_snr_lic: 'Numéro de dossard, licence ou nom',
        help_mock_snr: 'Dossard : 86',
        help_h_tabs: 'Onglets',
        help_p_tabs_html: 'Après la recherche tu vois <b>4 onglets</b> :',
        help_p_prog_html: '<b>Programme</b> — toutes les courses de la rencontre. Tes courses sont surlignées. Tape sur une course pour voir la liste de départ. Filtre par distance en haut; utilise la barre en dessous pour replier ou déplier les groupes dans cette distance.',
        help_p_heats_html: '<b>Séries</b> — tes séries avec tous les skateurs. Ta propre ligne est surlignée. Après l\'arrivée, les skateurs sont affichés dans l\'ordre d\'arrivée, avec les temps et positions.',
        help_p_res_html: '<b>Rondes</b> — ton résultat personnel par tour (séries, quart, demi, finale A, petite finale). Montre la place obtenue à chaque tour et si tu es passé au tour suivant.',
        help_p_uitsl_html: '<b>Tous résultats</b> — les résultats complets de tous les skateurs. Choisis une catégorie et distance, ou consulte le classement.',
        help_mock_jouw_naam: 'Ton nom',
        help_h_auto: 'Mis à jour automatiquement',
        help_p_auto_html: 'La page s\'actualise toutes les 3 minutes tant que l\'onglet est visible. À côté du nom de la course tu vois <b>🔄 HH:MM</b> — c\'est l\'heure de la dernière actualisation.',
        help_h_meld: 'Annonces',
        help_p_meld_html: 'En haut se trouve un <b>bouton 📢</b> (visible dès qu\'une annonce est active). Les annonces importantes de l\'organisation apparaissent automatiquement en pop-up et restent accessibles sous ce bouton — par ex. "Programme avec 15 min de retard".',
        help_h_tip: 'Astuce',
        help_p_tip: 'Pas encore de résultats ? Le résultat apparaît dès que le jury l\'a confirmé.',
        // ── Notifications push ──
        push_titel: 'Notifications push sur votre téléphone',
        push_uitleg: 'Une notification push sur votre téléphone dès qu\'un tirage est fait ou qu\'un résultat arrive pour vos patineurs — même quand l\'app est fermée.',
        push_niet: 'Non pris en charge dans ce navigateur.',
        push_ios: 'Sur iPhone : ajoutez d\'abord l\'app à l\'écran d\'accueil.',
        push_uit: 'Désactiver',
        push_aan: 'Activer',
        push_loting: 'Tirage prêt',
        push_uitslag: 'Résultat traité',
        push_bericht: 'Annonces',
        push_test: 'Envoyer un test',
        push_bezig: 'En cours…',
        push_geweigerd: 'Notifications bloquées dans votre navigateur.',
        push_fout: 'Une erreur s\'est produite.',
        push_testok: 'Test envoyé ✓',
        push_mislukt: 'échoué',
        help_h_push: 'Notifications sur votre téléphone',
        help_p_push_html: 'Lorsque vous suivez un patineur, vous pouvez activer les <b>notifications push</b> via le bandeau ✎ en haut (le <b>bloc 🔔</b>). Vous recevez alors une alerte sur votre téléphone dès que votre patineur est <b>tiré au sort 🚩</b> ou qu\'un <b>résultat 🏁</b> arrive, ainsi que pour les <b>annonces 📢</b> de l\'organisation — même quand l\'app est fermée. Chaque type s\'active ou se désactive séparément. Sur iPhone, cela ne fonctionne que si l\'app est sur votre écran d\'accueil.',
    }
};
// Shared i18n-helpers (t, applyI18n, toggleLang, getCurLang, getLocale)
// zijn hierboven al ingeladen via readfile(js/i18n.js). Hier alleen
// app-specifieke wrappers + init.
function getStatusLabel(i) { return t('status_' + i); }

// Helper voor meldingen: pak veld (titel/bericht) in huidige taal, met
// fallback-keten: huidige taal → EN → NL (= origineel). Vertaalde velden
// (titel_en, titel_de, titel_fr) worden door backend gevuld via Claude AI
// bij save. NL-veld (titel, bericht) is altijd verplicht; vertalingen
// optioneel. Voor talen waar de vertaling ontbreekt → val terug op EN
// (de meest universele) en uiteindelijk op NL.
function _meldingTekst(m, veld) {
    const lang = getCurLang();
    const sufLang = veld + '_' + lang;
    const sufEn   = veld + '_en';
    if (lang !== 'nl' && m[sufLang]) return m[sufLang];
    if (lang !== 'nl' && m[sufEn])   return m[sufEn];
    return m[veld] || '';
}

function _rerenderActiveTab() {
    // Comps-dropdown opnieuw vullen (textContent gebruikt vertaalde labels)
    if (typeof filterComps === 'function' && alleComps?.length) filterComps();
    // Multi-rijder view opnieuw renderen
    if (typeof renderKinderen === 'function' && _kinderen?.length) {
        _bewaarKindUistate?.();
        renderKinderen();
    }
    // Connection banner updaten
    if (typeof _connUpdateBanner === 'function') _connUpdateBanner();
    // Stempel-tekst opnieuw zetten (title-attribute)
    document.querySelectorAll('.auto-stempel').forEach(el => {
        el.title = t('auto_stempel_title');
    });
    // Meldingen-badge: alleen een getal, geen vertaling nodig.
    // Maar openstaande melding-overlays bevatten al-gerenderde titel/bericht
    // in de oude taal — die moeten we opnieuw bouwen, anders zie je na
    // NL→EN nog steeds de Nederlandse tekst staan (vooral merkbaar bij
    // globale meldingen die direct bij landing openstaan).
    const popup = document.querySelector('[data-meld-overlay="popup"]');
    if (popup && _huidigeMelding) {
        const m = _huidigeMelding;
        popup.remove();
        _meldingActief = false;
        _huidigeMelding = null;
        toonMelding(m, selComp?.value || '');
    }
    const overz = document.querySelector('[data-meld-overlay="overzicht"]');
    if (overz) {
        overz.remove();
        toonMeldingenOverzicht();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initI18n({ dict: T, onChange: () => {
        _rerenderActiveTab();
        if (typeof _ppSync === 'function') _ppSync();   // push-taal meteen meeschakelen
    } });
});

const selComp = document.getElementById('sel-comp');
const inpSnr  = document.getElementById('inp-snr');
const btnZoek = document.getElementById('btn-zoek');
const divResult = document.getElementById('resultaat');
const divInfo   = document.getElementById('comp-info');
const chkOud     = document.getElementById('chk-oud');
const chkVandaag = document.getElementById('chk-vandaag');
const chkToekomst = document.getElementById('chk-toekomst');
let alleComps = [];

const STATUS_KLEUR = ['#e65100','#2e7d32','#b71c1c','#6a1b9a','#283593','#006064'];
const STATUS_BG    = ['#fff3e0','#e8f5e9','#fce4e4','#f3e5f5','#e8eaf6','#e0f7fa'];
const BADGE = { heats:'badge-serie', kwartfinale:'badge-kf', halve_finale:'badge-hf',
                finale_a:'badge-finale', finale_b:'badge-finale', runner_up:'badge-ru' };
// Ronde-labels worden runtime vertaald via getRondeLabel(rt).
function getRondeLabel(rt) {
    const map = {
        heats: 'ronde_serie',
        kwartfinale: 'ronde_kf',
        halve_finale: 'ronde_hf',
        finale_a: 'ronde_finale',
        finale_b: 'ronde_b_finale',
        runner_up: 'ronde_runner_up',
    };
    return map[rt] ? t(map[rt]) : (rt || '');
}

function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
// Safari kan geen "2026-04-19 10:00:00" parsen, wel "2026-04-19T10:00:00"
function safeDatum(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }

// ── Verbinding-status: detecteert offline / server-down en toont banner ────
// Wordt door safeFetch hieronder bijgewerkt: succes → groen/verborgen,
// fout → banner met passende tekst. window 'online'-event triggert direct
// een refresh; visibilitychange (visible) triggert ook een refresh.
const _conn = {
    online: navigator.onLine,         // browser-niveau
    serverOk: true,                   // laatste API-call succesvol?
    lastSuccess: null,                // Date van laatste OK-fetch
    consecutiveFails: 0,              // voor exponentiële backoff
    refreshHook: null,                // zetten door refresh-functie
};

function _connBannerEl() {
    let el = document.getElementById('conn-banner');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'conn-banner';
    el.className = 'conn-banner';
    el.style.display = 'none';
    document.body.insertBefore(el, document.body.firstChild);
    return el;
}

function _connUpdateBanner() {
    const el = _connBannerEl();
    let bericht = '';
    if (!_conn.online) {
        bericht = t('conn_geen_internet');
    } else if (!_conn.serverOk) {
        bericht = t('conn_server_down');
    }
    if (bericht) {
        const tijd = _conn.lastSuccess
            ? ` <small style="opacity:.85">(${t('conn_laatste_update', {tijd: _conn.lastSuccess.toLocaleTimeString(getLocale(), {hour:'2-digit', minute:'2-digit'})})})</small>`
            : '';
        el.innerHTML = bericht + tijd;
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

// Grace-periode: na een fout blijft de banner ten minste deze tijd staan,
// ook als andere fetches in de tussentijd slagen. Voorkomt geflikker bij
// gemengde fouten (één endpoint down, ander werkt).
const _CONN_GRACE_MS = 10_000;

function _connOk() {
    const wasFout = !_conn.serverOk || !_conn.online;
    _conn.lastSuccess = new Date();
    _conn.consecutiveFails = 0;
    // Binnen grace-periode na een fout: server-vlag pas herstellen als de
    // grace voorbij is. navigator-online vlag (echte OS-status) volgt wél
    // direct het laatste signaal.
    const inGrace = _conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) < _CONN_GRACE_MS;
    _conn.online = true;
    if (!inGrace) _conn.serverOk = true;
    if (wasFout && !inGrace) _connUpdateBanner();
}

function _connFail(reden) {
    if (reden === 'network') _conn.online = false;
    else                     _conn.serverOk = false;
    _conn.lastFailureMs = Date.now();
    _conn.consecutiveFails++;
    _connUpdateBanner();
    // Plan een banner-recheck na de grace-periode zodat 'ie automatisch
    // verdwijnt als er ondertussen geen nieuwe fouten meer komen.
    setTimeout(() => {
        if (_conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) >= _CONN_GRACE_MS) {
            // Geen recente fouten meer — verifieer met een laatste lookup
            _conn.serverOk = true;
            _connUpdateBanner();
        }
    }, _CONN_GRACE_MS + 100);
}

// Triggers voor automatisch herstel
window.addEventListener('online', () => {
    _conn.online = true;
    _connUpdateBanner();
    if (typeof _conn.refreshHook === 'function') _conn.refreshHook();
});
window.addEventListener('offline', () => {
    _conn.online = false;
    _connUpdateBanner();
});

// Fetch met retry bij 429 + verbinding-status-tracking. Bij netwerkfout
// (TypeError 'Failed to fetch') of 5xx: banner aan + niet retry'en
// (auto-refresh-tick probeert vanzelf opnieuw met exponentiële backoff).
// Retry-strategie: max 1× opnieuw bij 429 met random jitter (2-5 s) zodat
// honderden publieke bezoekers niet synchroon weer aankloppen en de
// rate-limit nog erger maken.
// Demo-modus: staat er ?demo in de pagina-URL, dan hangt safeFetch aan elke
// API-call &demo=1 zodat de backend alleen demo-wedstrijden toont/toelaat.
const DEMO_MODE = new URLSearchParams(location.search).has('demo');
async function safeFetch(url, maxRetries = 1) {
    if (DEMO_MODE) url += (url.includes('?') ? '&' : '?') + 'demo=1';
    try {
        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            const res = await fetch(url);
            if (res.status === 429 && attempt < maxRetries) {
                const wait = 2000 + Math.random() * 3000;
                await new Promise(r => setTimeout(r, wait));
                continue;
            }
            if (res.status >= 500) {
                _connFail('server');
                return res;
            }
            if (res.status === 429) {
                _connFail('server');
                return res;
            }
            _connOk();
            return res;
        }
        return new Response(null, { status: 504 });
    } catch (e) {
        // TypeError = netwerkfout (geen verbinding, DNS, etc.)
        _connFail('network');
        throw e;
    }
}
// Puntenkoers: punten van gelapte rijders (minder ronden dan de leider) zijn
// vervallen voor de klassering (server rekent dit door in de rang). Toon de
// behaalde punten wél, doorgestreept + note. Inline-styled zodat het los van
// het stylesheet werkt.
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
function filterComps() {
    const nu = new Date();
    const gisteren = new Date(nu); gisteren.setDate(gisteren.getDate() - 1); gisteren.setHours(0,0,0,0);
    const morgen   = new Date(nu); morgen.setDate(morgen.getDate() + 1);   morgen.setHours(23,59,59,999);

    const toonOud      = chkOud.checked;
    const toonVandaag  = chkVandaag.checked;
    const toonToekomst = chkToekomst.checked;
    const vorigeWaarde = selComp.value;

    if (!toonOud && !toonVandaag && !toonToekomst) {
        selComp.innerHTML = `<option value="">${esc(t('opt_kies_filter'))}</option>`;
        return;
    }

    selComp.innerHTML = `<option value="">${esc(t('opt_kies_wedstrijd'))}</option>`;
    for (const c of alleComps) {
        const startDag = safeDatum(c.starts);
        const eindDag  = safeDatum(c.ends) ?? startDag;

        // Categoriseer: een wedstrijd is óf vandaag (overlapt met gisteren-morgen),
        // óf oud (afgelopen vóór gisteren), óf toekomstig (begint ná morgen).
        const isVandaag  = startDag && startDag <= morgen && eindDag >= gisteren;
        const isOud      = !isVandaag && eindDag   && eindDag   < gisteren;
        const isToekomst = !isVandaag && startDag && startDag > morgen;

        // Tonen als bijbehorend filter aan staat
        if (isVandaag  && !toonVandaag)  continue;
        if (isOud      && !toonOud)      continue;
        if (isToekomst && !toonToekomst) continue;

        const d = startDag ? startDag.toLocaleDateString(getLocale(),{day:'numeric',month:'long',year:'numeric'}) : '';
        // Verborgen wedstrijden: tonen als disabled met "(binnenkort)"
        // suffix — bezoeker ziet dat de wedstrijd er aankomt zonder
        // erop te kunnen klikken. Operator publiceert via Beheer.
        const verborgen = !Number(c.public_zichtbaar);
        const o = document.createElement('option');
        o.value = c.id;
        o.textContent = `${c.name} — ${d}${verborgen ? '  ' + t('opt_binnenkort') : ''}`;
        if (verborgen) o.disabled = true;
        o.dataset.datum = d; o.dataset.naam = c.name;
        o.dataset.orgLogo = c.org_logo ?? '';
        o.dataset.orgNaam = c.org_naam ?? '';
        o.dataset.baanLogo = c.baan_logo ?? '';
        o.dataset.baanVereniging = c.baan_vereniging ?? '';
        o.dataset.sponsors = JSON.stringify(c.sponsors ?? []);
        selComp.appendChild(o);
    }

    // Herstel selectie als die nog in de lijst zit en niet (inmiddels) disabled.
    const vorigeOpt = vorigeWaarde
        ? selComp.querySelector(`option[value="${vorigeWaarde}"]`)
        : null;
    if (vorigeOpt && !vorigeOpt.disabled) {
        selComp.value = vorigeWaarde;
    } else {
        // Auto-selecteer als er maar 1 selecteerbare wedstrijd is —
        // disabled ('binnenkort') tellen niet mee, anders zou de
        // gebruiker bij stappen verder pas een 'niet beschikbaar'
        // foutmelding krijgen.
        const opties = selComp.querySelectorAll('option[value]:not([value=""]):not([disabled])');
        if (opties.length === 1) { selComp.value = opties[0].value; selComp.dispatchEvent(new Event('change')); }
    }
}

chkOud.addEventListener('change', filterComps);
chkVandaag.addEventListener('change', filterComps);
chkToekomst.addEventListener('change', filterComps);

safeFetch('?action=competitions').then(r=>r.json()).then(comps => {
    alleComps = comps;

    // Directe-link-support: ?comp=<uuid> in de URL selecteert direct die
    // wedstrijd. Gebruikt door de QR-code op de promotie-poster per wedstrijd.
    // Als de wedstrijd buiten het "actieve" venster valt (oud of toekomstig)
    // vinken we automatisch het juiste filter aan zodat de optie zichtbaar is.
    const urlParams = new URLSearchParams(window.location.search);
    const wantedComp = urlParams.get('comp');
    if (wantedComp) {
        const comp = alleComps.find(c => c.id === wantedComp);
        if (comp) {
            const nu = new Date();
            const startDag = comp.starts ? new Date(comp.starts) : null;
            const eindDag  = comp.ends   ? new Date(comp.ends)   : startDag;
            if (eindDag && eindDag < nu)       chkOud.checked = true;
            if (startDag && startDag > nu)     chkToekomst.checked = true;
        }
    }

    filterComps();

    // Na filterComps: selecteer 'm als de optie nu beschikbaar is. Alleen
    // dispatchen als filterComps 'm niet al auto-geselecteerd heeft (bij één optie),
    // anders vuurt de change 2× → dubbele kinderen-load.
    if (wantedComp && selComp.value !== wantedComp
        && selComp.querySelector(`option[value="${wantedComp}"]`)) {
        selComp.value = wantedComp;
        selComp.dispatchEvent(new Event('change'));
    }
}).catch(() => { selComp.innerHTML = `<option value="">${esc(t('opt_fout_laden'))}</option>`; });

selComp.addEventListener('change', async () => {
    const o = selComp.selectedOptions[0];
    if (o?.value) { divInfo.innerHTML = `<strong>${esc(o.dataset.naam)}</strong><div style="color:#555;margin-top:2px">${esc(o.dataset.datum)}</div>`; divInfo.hidden = false; }
    else divInfo.hidden = true;
    btnZoek.disabled = !(selComp.value && inpSnr.value.trim());
    divResult.innerHTML = '';
    updateHeaderLogos(o);
    updateSetupStrip();   // reflecteer nieuwe wedstrijd in de strip bovenaan

    // Multi-rijder-state resetten en vorige kinderen herladen uit globale
    // store (op license_key). Kinderen die niet in deze wedstrijd meedoen
    // worden stil overgeslagen — geen foutmelding.
    _kinderen = [];
    _activeKindIdx = 0;
    if (!selComp.value) return;
    const opgeslagen = _loadKidsUitStorage();
    if (!opgeslagen.length) return;
    const mySeq = ++_kindLoadSeq;   // deze load claimt de nieuwste beurt
    divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_je_rijders_ophalen')}</div>`;
    let gedeeldeProg = null;
    try {
        const pr = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(selComp.value)}`);
        gedeeldeProg = await pr.json();
    } catch {}
    if (mySeq !== _kindLoadSeq) return;   // nieuwere change gestart → deze afbreken
    // In een lokale array verzamelen en pas op 't eind toewijzen: twee gelijktijdige
    // loads kunnen zo nooit in dezelfde _kinderen interleaven (→ geen dubbele rijders).
    const verzameld = [];
    const anoniemGeworden = [];   // gevolgde rijders die nu anoniem zijn (geen geldig token)
    for (const item of opgeslagen) {
        const k = await _fetchKind({ person_id: item.person_id, license_key: item.license_key, volg: item.volg }, selComp.value, gedeeldeProg);
        if (mySeq !== _kindLoadSeq) return;   // afgebroken door nieuwere load
        if (k && k.__anoniem) { anoniemGeworden.push(item); continue; }
        if (k) verzameld.push(k);
        // k == null → kind doet niet mee aan deze wedstrijd, we slaan 'm stil over.
    }
    _kinderen = verzameld;

    // Opslag bijwerken: (a) rijders die nu anoniem zijn pruimen (chip weg, volgen
    // verbroken — je had geen geldig volg-token), en (b) fase 3c-migratie: passief
    // person_id invullen bij oude items. Rijders die deze wedstrijd niet meedoen
    // blijven staan (die zijn niet gecontroleerd).
    const wegKeys = new Set(anoniemGeworden.map(it => it.person_id || it.volg || it.license_key));
    let basis = opgeslagen.filter(it => !wegKeys.has(it.person_id || it.volg || it.license_key));
    let veranderd = wegKeys.size > 0;
    if (basis.some(it => !it.person_id)) {
        const pidByLic = new Map();
        verzameld.forEach(k => {
            const p = k.data[k.kozen_idx ?? 0]?.persoon;
            if (p?.license_key && p?.person_id) pidByLic.set(p.license_key, p.person_id);
        });
        if (pidByLic.size) {
            basis = basis.map(it =>
                (!it.person_id && it.license_key && pidByLic.has(it.license_key))
                    ? { ...it, person_id: pidByLic.get(it.license_key) }
                    : it);
            veranderd = true;
        }
    }
    if (veranderd) {
        localStorage.setItem(KIDS_LS_KEY, JSON.stringify(basis));
        if (typeof _renderSetupVolglijst === 'function') _renderSetupVolglijst();  // chips meteen bij
        if (wegKeys.size && typeof _ppSync === 'function') _ppSync();               // push-abonnement bij
    }
    if (_kinderen.length) {
        _activeKindIdx = 0;
        renderKinderen();
        divResult.scrollIntoView({ behavior:'smooth', block:'start' });
    } else {
        divResult.innerHTML = '';
    }
});
inpSnr.addEventListener('input', () => { btnZoek.disabled = !(selComp.value && inpSnr.value.trim()); });
inpSnr.addEventListener('keydown', e => { if (e.key==='Enter' && !btnZoek.disabled) btnZoek.click(); });

// ── Zoek-input: detecteer of de user een startnummer, licentienummer of
//    een achternaam typt. Regel:
//      - alleen cijfers, 1-4 tekens → startnummer
//      - alleen cijfers, 5+ tekens  → licentienummer (KNSB relatienr)
//      - bevat letters              → achternaam-zoek
function _zoekModus(tekst) {
    const t = tekst.trim();
    // Volg-ID (geheim token uit Mijn InlineComp) = 32 hex-tekens → volg-lookup.
    // Dit is de enige manier om een publiek anonieme rijder te volgen (variant B):
    // het token ontsluit ('entitled') de echte naam; person_id doet dat niet.
    if (/^[0-9a-f]{32}$/i.test(t)) return 'volg';
    if (/^\d+$/.test(t)) return t.length <= 4 ? 'snr' : 'license';
    return 'naam';
}

// Toon een zoek-melding op de JUISTE plek: ín de setup-modal als die open staat
// (anders valt de melding achter de modal), anders in het hoofdresultaat-gebied.
function _zoekFeedback(html, isFout = false) {
    const box = `<div class="melding${isFout ? ' melding-fout' : ''}">${html}</div>`;
    const modal = document.getElementById('setup-modal');
    const sm = document.getElementById('setup-melding');
    if (modal && modal.classList.contains('open') && sm) sm.innerHTML = box;
    else divResult.innerHTML = box;
}
function _zoekFeedbackWis() {
    const sm = document.getElementById('setup-melding');
    if (sm) sm.innerHTML = '';
}

btnZoek.addEventListener('click', async () => {
    if (_loadKidsUitStorage().length >= MAX_KINDEREN) return;   // max bereikt — eerst verwijderen
    const compId = selComp.value, tekst = inpSnr.value.trim();
    if (!compId || !tekst) return;
    const modus = _zoekModus(tekst);

    if (modus === 'naam') {
        await zoekOpNaam(compId, tekst);
        return;
    }

    _zoekFeedback(`<span class="spinner"></span> ${esc(t('msg_zoeken'))}`);
    btnZoek.disabled = true;
    try {
        const param = modus === 'volg'
            ? `volg=${encodeURIComponent(tekst)}`
            : modus === 'license'
                ? `license_key=${encodeURIComponent(tekst)}`
                : `startnummer=${encodeURIComponent(tekst)}`;
        const [lookupRes, progRes] = await Promise.all([
            safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}`),
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`)
        ]);
        const data = await lookupRes.json();
        const prog = await progRes.json();

        if (data.error) { _zoekFeedback(esc(data.error), true); return; }
        if (!data.length) { _zoekFeedback(esc(t('msg_geen_resultaten'))); return; }

        // Een anonieme rijder kun je niet op startnummer (of naam) volgen —
        // alleen via het onraadbare volg-ID. Filter anonieme treffers eruit en
        // toon een uitleg als er niets volgbaars overblijft.
        const volgbaar = data.filter(d => !d.persoon?.is_anoniem);
        if (!volgbaar.length) {
            _zoekFeedback(esc(t('msg_rijder_anoniem')));
            return;
        }

        // Meerdere personen met zelfde startnummer (of license) → chooser-modal
        // met checkboxes zodat de user er meerdere tegelijk kan toevoegen.
        if (volgbaar.length > 1) {
            const rijen = volgbaar.map(d => ({
                person_id:    d.persoon.person_id ?? null,
                license_key:  d.persoon.license_key,
                full_name:    d.persoon.full_name,
                wedstrijd_snr: d.persoon.wedstrijd_snr ?? d.persoon.start_number,
                category:     d.persoon.category,
                club_short:   d.persoon.club_short ?? '',
            }));
            _zoekFeedbackWis();
            toonChooserModal(rijen, tekst, compId);
            return;
        }

        // Voor license/snr gebruiken we het startnr uit de response (kan per
        // wedstrijd verschillen); toonRijderData deduped op license_key.
        const huidigSnr = volgbaar[0].persoon.wedstrijd_snr ?? volgbaar[0].persoon.start_number ?? tekst;
        _zoekFeedbackWis();                // modal sluit hierna → geen stale spinner
        toonRijderData([volgbaar[0]], 0, huidigSnr, prog);
        inpSnr.value = '';
        btnZoek.disabled = true;
    } catch (e) {
        _zoekFeedback(esc(t('err_prefix', {msg: e.message})), true);
    } finally { btnZoek.disabled = false; }
});

// ── Herbruikbare multi-select chooser-modal ─────────────────────────────────
// Gebruikt voor zowel naam-zoek als startnummer-match met meerdere hits.
// `rijen` moet items hebben met: {license_key, full_name, wedstrijd_snr,
// category, club_short}. Na "Toevoegen" wordt per gekozen license_key een
// volledige lookup gedaan en aan _kinderen toegevoegd.
function toonChooserModal(rijen, term, compId) {
    // Reeds gevolgd (globale volglijst) → uitschakelen. Ook rijders die je volgt
    // maar die niet in déze wedstrijd meedoen tellen mee, zodat je nooit boven
    // het maximum van de globale volglijst uitkomt.
    // Reeds-gevolgd-set bevat zowel person_id als license_key (fase 3c), zodat
    // een treffer op één van beide als "al in lijst" telt, ongeacht welke sleutel
    // het opgeslagen item draagt.
    const al = new Set();
    _loadKidsUitStorage().forEach(k => { if (k.person_id) al.add(k.person_id); if (k.license_key) al.add(k.license_key); });
    const plaatsVrij = MAX_KINDEREN - _loadKidsUitStorage().length;

    const modal = document.createElement('div');
    modal.className = 'naamzoek-modal';
    modal.innerHTML = `
        <div class="naamzoek-box">
            <div class="naamzoek-hdr">
                <span>${esc(t('chooser_titel', {term}))}</span>
                <button class="naamzoek-sluit" title="${esc(t('chooser_sluit'))}">&times;</button>
            </div>
            <div class="naamzoek-body">
                ${rijen.length === 0
                    ? `<div class="naamzoek-leeg">${esc(t('msg_geen_rijders'))}</div>`
                    : rijen.map(r => {
                        const uit = (r.person_id && al.has(r.person_id)) || al.has(r.license_key);
                        // search_person geeft `in_wedstrijd` (1/0); snr-pad niet,
                        // dan behandelen we als altijd-wel (undefined === wel).
                        const doetMee = r.in_wedstrijd === undefined ? true : !!parseInt(r.in_wedstrijd);
                        const meta = [
                            r.category || '',
                            r.club_short ? esc(r.club_short) : '',
                            uit ? `<span style="color:#999">${esc(t('chooser_al_in_lijst'))}</span>` : '',
                            !doetMee ? `<span style="color:#b71c1c">${esc(t('chooser_doet_niet_mee'))}</span>` : '',
                        ].filter(Boolean).join(' · ');
                        return `<label class="naamzoek-rij${uit ? ' dim' : ''}">
                            <input type="checkbox" data-pid="${esc(r.person_id ?? '')}" data-lic="${esc(r.license_key)}" ${uit ? 'checked disabled' : ''}>
                            <span class="naamzoek-rij-snr">${esc(r.wedstrijd_snr ?? '—')}</span>
                            <div class="naamzoek-rij-naam">
                                ${esc(r.full_name)}
                                <div class="naamzoek-rij-meta">${meta}</div>
                            </div>
                        </label>`;
                    }).join('')}
            </div>
            <div class="naamzoek-voet">
                <span class="aantal">${esc(t('chooser_max', {max: MAX_KINDEREN, vrij: plaatsVrij}))}</span>
                <div>
                    <button class="btn-zoek" style="padding:8px 18px;margin:0" id="naamzoek-ok">${esc(t('chooser_toevoegen'))}</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(modal);
    divResult.innerHTML = '';

    const sluit = () => modal.remove();
    modal.querySelector('.naamzoek-sluit').addEventListener('click', sluit);
    modal.addEventListener('click', e => { if (e.target === modal) sluit(); });

    modal.querySelector('#naamzoek-ok').addEventListener('click', async () => {
        const vinkjes = [...modal.querySelectorAll('input[type=checkbox]:checked:not(:disabled)')];
        if (!vinkjes.length) { sluit(); return; }
        if (vinkjes.length > plaatsVrij) {
            alert(t('alert_max_select', {max: MAX_KINDEREN, vrij: plaatsVrij, n: vinkjes.length}));
            return;
        }
        sluit();
        divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_rijders_ophalen')}</div>`;
        let prog = null;
        try {
            const pr = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`);
            prog = await pr.json();
        } catch {}
        for (const cb of vinkjes) {
            const pid = cb.dataset.pid;
            const lic = cb.dataset.lic;
            if (!pid && !lic) continue;
            // Prefereer person_id (fase 3c); val terug op license_key.
            const param = pid
                ? `person_id=${encodeURIComponent(pid)}`
                : `license_key=${encodeURIComponent(lic)}`;
            try {
                const r = await safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}`);
                const d = await r.json();
                if (d && !d.error && d.length) {
                    const huidigSnr = d[0].persoon.wedstrijd_snr ?? d[0].persoon.start_number ?? '';
                    toonRijderData(d, 0, huidigSnr, prog);
                }
            } catch {}
        }
        inpSnr.value = '';
        btnZoek.disabled = true;
    });
}

// ── Naam-zoek: zoek via backend, toon chooser ────────────────────────────────
async function zoekOpNaam(compId, term) {
    _zoekFeedback(`<span class="spinner"></span> ${esc(t('msg_zoeken_op', {term: esc(term)}))}`);
    btnZoek.disabled = true;
    let rijen = [];
    try {
        const res = await safeFetch(`?action=search_person&competition_id=${encodeURIComponent(compId)}&q=${encodeURIComponent(term)}`);
        rijen = await res.json();
        if (!Array.isArray(rijen)) rijen = [];
    } catch (e) {
        _zoekFeedback(esc(t('err_zoeken', {msg: e.message})), true);
        btnZoek.disabled = false;
        return;
    } finally { btnZoek.disabled = false; }
    // Geen naam-treffer → of/of-melding (variant B): niet-bevestigend, dekt zowel
    // "doet niet mee" als "koos anoniem". Een anonieme rijder is nooit op naam
    // vindbaar; toevoegen kan dan alleen via het licentie-/ID-nummer.
    if (rijen.length === 0) {
        _zoekFeedback(esc(t('msg_naam_geen_of_of')));
        return;
    }
    _zoekFeedbackWis();
    toonChooserModal(rijen, term, compId);
}

// refreshRijder() is verwijderd — was gekoppeld aan het ↻-knopje dat door
// de auto-refresh + ↻-stempel-indicator overbodig is geworden.

function toonRijder(idx) {
    window._gekozenIdx = idx;
    toonRijderData(window._lookupData, idx, window._lookupSnr, window._lookupProg);
}

// ── Multi-rijder-state (ouders met meerdere kinderen) ────────────────────────
// _kinderen = [{snr, data, prog, sub_tab}] waarbij data = lookup-response
// (array met persoon+heats) en prog = programma-response van de wedstrijd.
// Max 4 kids om de top-tabs leesbaar te houden op een telefoon.
const MAX_KINDEREN = 4;
let _kinderen = [];
let _activeKindIdx = 0;
// Volgnummer per kinderen-load: beschermt tegen twee (bijna) gelijktijdige
// change-handlers die anders tijdens hun await's in dezelfde _kinderen zouden
// pushen → dubbele rijders (bv. QR-open dispatcht change 2×). Alleen de nieuwste
// load mag _kinderen vullen + renderen; oudere breken af.
let _kindLoadSeq = 0;

// ── Programma-tab: inklap-state (public) ─────────────────────────────────────
// _progIngeklaptPub bevat de groep-keys die INGEKLAPT zijn (default = alles
// bij eerste render). _progAlleKeysPub = alle keys van laatste render, nodig
// voor "Alles in/uit". _progGroepenMetMijnPub = keys waar de gekozen rijder
// in zit ("Mijn ritten"-knop). _progEersteRenderPub triggert alleen bij het
// éérste render van deze rijder-tab.
const _progIngeklaptPub = new Set();
let _progAlleKeysPub = [];
const _progGroepenMetMijnPub = new Set();
let _progEersteRenderPub = true;

// Programma-UI-state per rijder — bij wisselen van kind-tab willen we
// dat elke rijder z'n eigen selectie (dag/afstand/klap/open groepen)
// onthoudt. Bij eerste bezoek van een nieuwe rijder proberen we de state
// van de vorige rijder over te nemen; als de bewaarde afstand niet in de
// nieuwe data voorkomt valt _restoreProgUiStatePub automatisch terug op
// "alle" (want de pill-lookup returnt null en de kiesProgFilter-call
// wordt geskipt). Keyed op license_key voor stabiliteit tussen sessies.
const _progUiStatePerKind = new Map();
let _pendingProgRestore = null;
function _kindKey(k) {
    return k?.data?.[k.kozen_idx ?? 0]?.persoon?.license_key || `snr:${k?.snr || ''}`;
}

// Snapshot / restore van de programma-tab UI-state rond een re-render.
// renderKinderen() bouwt de rijder-tab-HTML opnieuw én reset de klap-state
// naar default-collapsed. Bij auto-refresh (stilleRefresh) willen we die
// user-state juist behouden: filter-strook (dag+afstand), klap-balk
// (uit/in/mijn), én welke groepen handmatig open/dicht zijn geklapt.
function _snapshotProgUiStatePub() {
    const tab = document.querySelector('.tab-content[data-tab="programma"]');
    if (!tab) return null;
    const strook = tab.querySelector('.prog-filter-strook');
    const balk   = tab.querySelector('.prog-klap-balk');
    const open   = new Set();
    tab.querySelectorAll('.prog-groep').forEach(g => {
        if (g.classList.contains('samenvat')) return;
        if (!g.classList.contains('ingeklapt')) open.add(g.dataset.groepKey);
    });
    return {
        dag:     strook?.dataset.actieveDag     || 'alle',
        afstand: strook?.dataset.actieveAfstand || 'alle',
        klap:    balk?.dataset.actief           || '',
        open,
    };
}

function _restoreProgUiStatePub(state) {
    if (!state) return;
    const tab = document.querySelector('.tab-content[data-tab="programma"]');
    if (!tab) return;
    const strook = tab.querySelector('.prog-filter-strook');
    // Filter via bestaande handler — die triggert applyProgFilter (samenvat,
    // heat-tellers, verborgen-classes).
    if (strook) {
        if (state.dag && state.dag !== 'alle') {
            const p = strook.querySelector(
                `.prog-filter-panel[data-panel="dag"] .prog-filter-pill[data-value="${CSS.escape(state.dag)}"]`);
            if (p) kiesProgFilter('dag', state.dag, p);
        }
        if (state.afstand && state.afstand !== 'alle') {
            const p = strook.querySelector(
                `.prog-filter-panel[data-panel="afstand"] .prog-filter-pill[data-value="${CSS.escape(state.afstand)}"]`);
            if (p) kiesProgFilter('afstand', state.afstand, p);
        }
    }
    // Klap-balk: preset actief → knop klikken. Anders per-groep restore.
    if (state.klap === 'uit' || state.klap === 'in' || state.klap === 'mijn') {
        const btn = tab.querySelector(`.prog-klap-balk .prog-klap-btn[data-actie="${state.klap}"]`);
        if (btn) btn.click();
    } else if (state.open) {
        tab.querySelectorAll('.prog-groep').forEach(g => {
            if (g.classList.contains('samenvat')) return;
            const key = g.dataset.groepKey;
            const moetOpen = state.open.has(key);
            g.classList.toggle('ingeklapt', !moetOpen);
            if (moetOpen) _progIngeklaptPub.delete(key);
            else          _progIngeklaptPub.add(key);
        });
        const balk = tab.querySelector('.prog-klap-balk');
        if (balk) {
            balk.dataset.actief = '';
            balk.querySelectorAll('.prog-klap-btn').forEach(b => b.classList.remove('actief'));
        }
    }
}

// ── Setup-modal: wedstrijd + rijder-kies overlay ─────────────────────────────
// Vervangt de altijd-zichtbare stap 1 + 2 secties. Opent via de setup-strip
// bovenaan, de "+"-rijder-tab-knop, of automatisch bij eerste bezoek van
// de dag (localStorage-detectie op datum-key).
function openSetupModal() {
    const m = document.getElementById('setup-modal');
    if (m) m.classList.add('open');
    document.body.style.overflow = 'hidden'; // scroll-lock achtergrond
    _zoekFeedbackWis();                       // geen stale melding van vorige keer
    _renderSetupVolglijst();
    _updateSetupModalMax();
}
// Bij het maximum aantal rijders: zoekveld + Zoeken uit + uitleg-hint, zodat
// duidelijk is dat je eerst een rijder moet verwijderen (via de chips hierboven).
function _updateSetupModalMax() {
    const vol = _loadKidsUitStorage().length >= MAX_KINDEREN;   // globale volglijst
    // Bij max: verberg de hele rijder-zoekstap (label + veld) én de Zoeken-knop,
    // en toon de hint op díé plek — i.p.v. een grijs, ogenschijnlijk bruikbaar veld.
    const stap = document.getElementById('stap-rijder');
    if (stap)   stap.style.display = vol ? 'none' : '';
    if (inpSnr) inpSnr.disabled = vol;
    if (btnZoek) { btnZoek.style.display = vol ? 'none' : ''; if (vol) btnZoek.disabled = true; }
    const hint = document.getElementById('setup-max-hint');
    if (hint) {
        hint.hidden = !vol;
        if (vol) hint.textContent = t('zoek_max_hint', { max: MAX_KINDEREN });
    }
}
// "Je gevolgde rijders" in de setup-modal: chips met verwijder-×. Hier gebeurt
// het verwijderen (weggehaald uit de tabs, die waren te krap op smal scherm).
function _renderSetupVolglijst() {
    if (typeof _ppRender === 'function') _ppRender();   // push-blok mee verversen
    const el = document.getElementById('setup-volglijst');
    if (!el) return;
    // Bron = de OPGESLAGEN (globale) volglijst, niet _kinderen. _kinderen is de
    // per-wedstrijd-subset (nog leeg vóór wedstrijdkeuze); de opgeslagen lijst
    // kennen we synchroon uit localStorage, dus ook bij vers openen klopt 't.
    const saved = _loadKidsUitStorage();
    if (!saved.length) { el.innerHTML = ''; return; }
    const chips = saved.map(k => {
        // Live-gegevens (startnummer) als deze rijder in de huidige wedstrijd
        // geladen is; anders de opgeslagen naam-hint.
        // Identiteit prefereert person_id (fase 3c), valt terug op license_key.
        const kid = k.person_id || k.license_key;
        const live = _kinderen.find(x => {
            const xp = x.data?.[x.kozen_idx ?? 0]?.persoon;
            return (k.person_id && xp?.person_id === k.person_id) || (k.license_key && xp?.license_key === k.license_key);
        });
        const p = live?.data?.[live.kozen_idx ?? 0]?.persoon;
        const naam = p?.full_name || k.naam_hint || t('kind_rijder_placeholder');
        const snr = live ? live.snr : '';
        return `<span class="setup-volg-chip">
            ${snr ? `<span class="setup-volg-snr">${esc(snr)}</span>` : ''}
            <span class="setup-volg-naam">${esc(naam)}</span>
            <button type="button" class="setup-volg-x" data-kid="${esc(kid)}" title="${esc(t('kind_tab_verwijder'))}">&times;</button>
        </span>`;
    }).join('');
    el.innerHTML = `<div class="setup-volg-label">${esc(t('setup_volg_label'))}</div>
        <div class="setup-volg-chips">${chips}</div>`;
    el.querySelectorAll('.setup-volg-x').forEach(b => b.addEventListener('click', () => {
        _verwijderGevolgdeRijder(b.dataset.kid);
        _renderSetupVolglijst();   // modal-lijst meteen verversen
        _updateSetupModalMax();    // zoekveld weer aan als onder max
    }));
}
// Verwijder een rijder uit de globale volglijst (localStorage) én uit de live
// per-wedstrijd-lijst als 'ie daar geladen is (dan hoofdweergave verversen).
function _verwijderGevolgdeRijder(kid) {
    // kid = person_id (fase 3c) óf license_key (oude items). Verwijder de rij
    // die op één van beide matcht.
    localStorage.setItem(KIDS_LS_KEY,
        JSON.stringify(_loadKidsUitStorage().filter(k => k.person_id !== kid && k.license_key !== kid)));
    if (typeof _ppSync === 'function') _ppSync();   // server-licenties meelopen

    const idx = _kinderen.findIndex(x => {
        const xp = x.data?.[x.kozen_idx ?? 0]?.persoon;
        return xp?.person_id === kid || xp?.license_key === kid;
    });
    if (idx !== -1) {
        _kinderen.splice(idx, 1);
        if (_activeKindIdx >= _kinderen.length) _activeKindIdx = Math.max(0, _kinderen.length - 1);
        renderKinderen();   // tabs/hoofdweergave bijwerken (schrijft de opgeslagen lijst niet terug)
    }
}
function closeSetupModal() {
    const m = document.getElementById('setup-modal');
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
}
// Update de strook met de huidige wedstrijd-naam + rijder(s). Wordt
// aangeroepen bij wedstrijd-wissel, kind-add/remove, en na init.
function updateSetupStrip() {
    const el = document.getElementById('setup-strip-tekst');
    if (!el) return;
    const compNaam = selComp.selectedOptions[0]?.dataset?.naam || '';
    const compDatum = selComp.selectedOptions[0]?.dataset?.datum || '';
    // Rijder-samenvatting: bij 0 = niets, 1 = naam, 2+ = "N rijders".
    let rijderStr = '';
    if (_kinderen.length === 1) {
        const p = _kinderen[0].data?.[_kinderen[0].kozen_idx ?? 0]?.persoon;
        const nm = p?.full_name || _kinderen[0].snr;
        rijderStr = `<small>${esc(nm)}</small>`;
    } else if (_kinderen.length > 1) {
        rijderStr = `<small>${_kinderen.length} ${esc(t('setup_strip_rijders'))}</small>`;
    }
    if (compNaam) {
        el.innerHTML = `<b>${esc(compNaam)}</b>${compDatum ? ` <small style="display:inline;color:#666">· ${esc(compDatum)}</small>` : ''}${rijderStr}`;
    } else {
        el.innerHTML = `<span class="setup-strip-empty">${esc(t('setup_strip_leeg'))}</span>`;
    }
}
// Escape-key sluit de modal (accessibility + snelheid).
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const m = document.getElementById('setup-modal');
        if (m && m.classList.contains('open')) closeSetupModal();
    }
});
// Eerste-bezoek-per-dag: modal automatisch openen zodat gebruikers die de
// PWA vaker per dag openen niet elke keer de modal krijgen, maar bij een
// nieuwe dag wel gestuurd worden naar wedstrijd-keuze (want het is bijna
// altijd een andere wedstrijd).
(function autoOpenFirstOfDay() {
    const vandaag = new Date().toISOString().slice(0, 10);
    const laatstGezien = localStorage.getItem('ic_pub_setup_dag') || '';
    // Openen als: vandaag nog niet gezien, OF nog niks gekozen. selComp
    // is bij deze code al gerenderd (script staat na de HTML), maar de
    // dropdown-opties zijn asynchroon geladen — check op value.
    const nogNiksGekozen = !selComp || !selComp.value;
    if (laatstGezien !== vandaag || nogNiksGekozen) {
        // Kleine timeout om te wachten op eerste applyI18n() zodat de
        // modal-tekst in de juiste taal staat.
        setTimeout(() => {
            openSetupModal();
            localStorage.setItem('ic_pub_setup_dag', vandaag);
        }, 100);
    }
})();

function klapGroepPub(hdrEl) {
    const groep = hdrEl.closest('.prog-groep');
    if (!groep) return;
    // Samenvat-modus: klikken doet niks (heat-lijst is niet beschikbaar
    // in deze weergave, zie applyProgFilter).
    if (groep.classList.contains('samenvat')) return;
    const key = groep.dataset.groepKey;
    const nuIngeklapt = groep.classList.toggle('ingeklapt');
    if (nuIngeklapt) _progIngeklaptPub.add(key); else _progIngeklaptPub.delete(key);
    // Individuele klik → geen actieve knop in de klap-balk meer (state
    // matcht niet meer bij één van de drie preset-acties).
    const tab = hdrEl.closest('.tab-content');
    if (tab) {
        const balk = tab.querySelector('.prog-klap-balk');
        if (balk) {
            balk.dataset.actief = '';
            balk.querySelectorAll('.prog-klap-btn').forEach(b => b.classList.remove('actief'));
        }
    }
}

function klapProgPub(btnEl, actie) {
    const tab = btnEl.closest('.tab-content');
    if (!tab) return;
    _progIngeklaptPub.clear();
    if (actie === 'in') {
        _progAlleKeysPub.forEach(k => _progIngeklaptPub.add(k));
    } else if (actie === 'mijn') {
        _progAlleKeysPub.forEach(k => {
            if (!_progGroepenMetMijnPub.has(k)) _progIngeklaptPub.add(k);
        });
    }
    tab.querySelectorAll('.prog-groep').forEach(el => {
        el.classList.toggle('ingeklapt', _progIngeklaptPub.has(el.dataset.groepKey));
    });
    // Actieve knop bijwerken zodat je meteen ziet welke actie geldt.
    const balk = btnEl.closest('.prog-klap-balk');
    if (balk) {
        balk.dataset.actief = actie;
        balk.querySelectorAll('.prog-klap-btn').forEach(b =>
            b.classList.toggle('actief', b.dataset.actie === actie));
    }
}

// ── Persistente kind-lijst (GLOBAAL, niet per wedstrijd) ─────────────────────
// We bewaren `license_key` i.p.v. startnummer, zodat een kind dat in een
// volgende wedstrijd een ander startnummer krijgt toch automatisch wordt
// gevonden. Ook kinderen die in een wedstrijd niet meedoen worden stil
// overgeslagen — zonder dat de ouder ze moet afvinken.
const KIDS_LS_KEY = 'public_kinderen_licenses';
function _saveKids() {
    const seen = new Set();
    const items = _kinderen
        .map(k => {
            const p = k.data[k.kozen_idx ?? 0]?.persoon;
            // person_id (interne GUID) is sinds fase 3c de stabiele sleutel.
            // license_key blijft meegeschreven zolang die nog bestaat (fase 4
            // laat 'm vervallen); dedup en lookup prefereren person_id.
            if (!p?.license_key && !p?.person_id && !p?.volg_token) return null;
            // volg = geheim token; nodig om een anonieme rijder bij herladen weer
            // te ontsluiten (person_id ontsluit de naam niet).
            return { person_id: p.person_id ?? null, license_key: p.license_key ?? null,
                     volg: p.volg_token ?? null, naam_hint: p.full_name };
        })
        .filter(Boolean)
        // Dedup op person_id (of license_key als GUID nog ontbreekt): vangnet
        // zodat een (ooit) dubbele _kinderen nooit dubbel in localStorage belandt.
        .filter(it => { const key = it.person_id || it.license_key; return !seen.has(key) && seen.add(key); });
    localStorage.setItem(KIDS_LS_KEY, JSON.stringify(items));
    if (typeof _ppSync === 'function') _ppSync();   // server-licenties meelopen
}
function _loadKidsUitStorage() {
    try { return JSON.parse(localStorage.getItem(KIDS_LS_KEY) || '[]'); }
    catch { return []; }
}

// Haal lookup op voor een license_key of startnummer. Gebruikt de shared
// programma-respons als die al gefetcht is (scheelt netwerk-calls bij
// meerdere kinderen).
async function _fetchKind({ person_id = null, license_key = null, snr = null, volg = null }, compId, gedeeldeProg = null) {
    if (!person_id && !license_key && !snr && !volg) return null;
    // Volg-token eerst (enige sleutel die een anonieme rijder ontsluit), daarna
    // de stabiele person_id, dan license_key (oude items), tot slot startnummer.
    const param = volg
        ? `volg=${encodeURIComponent(volg)}`
        : person_id
            ? `person_id=${encodeURIComponent(person_id)}`
            : license_key
                ? `license_key=${encodeURIComponent(license_key)}`
                : `startnummer=${encodeURIComponent(snr)}`;
    const [lookupRes, progRes] = await Promise.all([
        safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}`),
        gedeeldeProg
            ? Promise.resolve({ json: async () => gedeeldeProg })
            : safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`),
    ]);
    const data = await lookupRes.json();
    const prog = await progRes.json();
    if (data.error || !data.length) {
        // Zochten we via een volg-token en bestaat dat niet (meer)? Dan is het
        // ingetrokken/vernieuwd door de rijder → follow verbroken → pruimen
        // (__anoniem-sentinel). Bij person_id/snr betekent leeg gewoon "doet niet
        // mee aan deze wedstrijd" → behouden (kan een andere wedstrijd rijden).
        return volg ? { __anoniem: true } : null;
    }
    // Rijder is nu anoniem én we hebben geen geldig volg-token (meer) → signaleer
    // dit apart (niet null = "doet niet mee"), zodat de aanroeper 'm uit de
    // opgeslagen volglijst kan pruimen.
    if (data[0]?.persoon?.is_anoniem) return { __anoniem: true };
    // Pak huidige startnr uit de response (kan in nieuwe wedstrijd anders zijn).
    const p = data[0].persoon;
    const huidigSnr = p.wedstrijd_snr ?? p.start_number ?? snr ?? '';
    return { snr: String(huidigSnr), data, prog, sub_tab: 'programma', kozen_idx: 0 };
}

function toonRijderData(data, startIdx, snr, prog) {
    // Dedupeer op person_id (stabiel over wedstrijden, fase 3c), valt terug op
    // license_key. Niet op startnummer (dat wisselt per wedstrijd).
    const nieuweP = data[startIdx]?.persoon;
    const nieuwePid = nieuweP?.person_id;
    const nieuweLic = nieuweP?.license_key;
    const bestaande = (nieuwePid || nieuweLic)
        ? _kinderen.findIndex(k => {
            const kp = k.data[k.kozen_idx ?? 0]?.persoon;
            return (nieuwePid && kp?.person_id === nieuwePid) || (nieuweLic && kp?.license_key === nieuweLic);
          })
        : -1;
    if (bestaande !== -1) {
        _activeKindIdx = bestaande;
        _kinderen[bestaande].data = data;
        _kinderen[bestaande].prog = prog;
        _kinderen[bestaande].kozen_idx = startIdx;
        _kinderen[bestaande].snr = String(snr);
    } else {
        if (_kinderen.length >= MAX_KINDEREN) {
            alert(t('alert_max_bereikt', {max: MAX_KINDEREN}));
            return;
        }
        _kinderen.push({ snr: String(snr), data, prog, sub_tab: 'programma', kozen_idx: startIdx });
        _activeKindIdx = _kinderen.length - 1;
    }
    _saveKids();
    renderKinderen();
}

// Render de complete multi-rijder-weergave: kind-tabs bovenop, met daaronder
// de persoon-kaart van het actieve kind.
function renderKinderen() {
    // Setup-strip volgt de _kinderen-state — ook bij lege lijst updaten.
    updateSetupStrip();
    if (!_kinderen.length) { divResult.innerHTML = ''; return; }
    // Bij render van een rijder-tab: reset klap-state naar default-collapsed.
    _progIngeklaptPub.clear();
    _progGroepenMetMijnPub.clear();
    _progEersteRenderPub = true;
    // Na succesvolle rijder-load: modal dicht als 'ie nog openstond.
    // Timeout laat de UI-transitie een tik ademen voor de sluit-animatie.
    setTimeout(() => {
        const m = document.getElementById('setup-modal');
        if (m && m.classList.contains('open')) closeSetupModal();
    }, 50);

    // Top-tabs: één knop per kind + "+ voeg toe" rechts
    const tabsHtml = _kinderen.map((k, idx) => {
        const p = k.data[k.kozen_idx ?? 0]?.persoon;
        const naam = p?.full_name ? p.full_name.split(' ')[0] : ''; // alleen voornaam in tab — kort
        const actief = idx === _activeKindIdx ? ' active' : '';
        // Geen ×-knop meer in de tab — die werd te krap op smalle telefoons bij
        // 3-4 kinderen (× viel weg / actieve tab klapte in). Verwijderen gaat nu
        // via de + / setup-modal onder "Je gevolgde rijders" (zoals de coach-app).
        return `<button class="kind-tab${actief}" data-kind-idx="${idx}">
            <span class="kind-tab-snr" data-len="${String(k.snr ?? '').length}">${esc(k.snr)}</span>
            <span>${esc(naam || t('kind_rijder_placeholder'))}</span>
        </button>`;
    }).join('');
    // Bij 3+ kinderen wordt het tabblad krap op telefoon-breedte. CSS
    // gebruikt data-count om dan compactere stijl toe te passen (voornaam
    // weg, kleinere padding) — de × moet altijd zichtbaar blijven.
    // + altijd klikbaar: opent de modal om rijders te beheren (toevoegen én
    // verwijderen). Bij max kun je zo alsnog iemand verwijderen; de titel legt
    // uit dat toevoegen pas kan na een verwijdering.
    const plusVol = _loadKidsUitStorage().length >= MAX_KINDEREN;
    const plusKnop = `<button class="kind-tab-plus" id="kind-tab-plus" title="${esc(plusVol ? t('kind_plus_max', {max: MAX_KINDEREN}) : t('kind_plus_title'))}">+</button>`;

    divResult.innerHTML = `
        <div class="kind-tabs" data-count="${_kinderen.length}">${tabsHtml}${plusKnop}</div>
        <div id="kind-content"></div>`;

    // Click-handlers op kind-tabs
    divResult.querySelectorAll('.kind-tab').forEach(btn => {
        btn.addEventListener('click', () => wisselKind(parseInt(btn.dataset.kindIdx)));
    });
    const plusEl = document.getElementById('kind-tab-plus');
    if (plusEl) plusEl.addEventListener('click', () => {
        // Setup-modal open. GEEN auto-focus op het zoekveld: op mobiel klapt dan
        // meteen het toetsenbord op en dat duwt de modal (incl. "Je gevolgde
        // rijders") weg. Toetsenbord verschijnt pas als de bediener zelf in het
        // veld tikt.
        inpSnr.value = '';
        btnZoek.disabled = true;
        openSetupModal();
    });

    // Content van actieve kind renderen
    const k = _kinderen[_activeKindIdx];
    if (!k) return;
    const subset = [k.data[k.kozen_idx ?? 0]];
    renderResultaat(subset, k.snr, k.prog);

    // Programma-UI-state herstellen: eigen state bij terugkeer naar deze
    // rijder, of de state van de vorige rijder als "poging" bij eerste
    // bezoek. Gezet door wisselKind() vóór renderKinderen().
    if (_pendingProgRestore) {
        _restoreProgUiStatePub(_pendingProgRestore);
        _pendingProgRestore = null;
    }

    // Onthouden sub-tab herstellen (als niet 'programma')
    if (k.sub_tab && k.sub_tab !== 'programma') {
        const subBtn = document.querySelector(`#kind-content .tab-btn[data-tab="${k.sub_tab}"]`);
        if (subBtn) subBtn.click();
    }
}

// Bewaar UI-state (huidige sub-tab + dropdown-keuzes binnen Uitslagen) van
// het actief getoonde kind. Wordt aangeroepen vóór elk renderKinderen() zodat
// na her-render de juiste keuzes hersteld kunnen worden. Zonder dit verloor
// de gebruiker elke 60s (auto-refresh) zijn categorie/afstand-selectie in de
// Uitslagen-tab — _kaartwissel_ deed hetzelfde. Restore gebeurt in
// initUitslagenTab() / het serie-klassement-blok.
function _bewaarKindUistate() {
    const k = _kinderen[_activeKindIdx];
    if (!k) return;
    const kc = document.getElementById('kind-content');
    if (!kc) return;
    // Sub-tab (welke tab is op dit moment open?)
    const huidigeSub = kc.querySelector('.tab-btn.active')?.dataset.tab;
    if (huidigeSub) k.sub_tab = huidigeSub;
    // Dropdown-keuzes binnen Uitslagen-tab
    const uitslPane = kc.querySelector('.tab-content[data-tab="uitslagen"]');
    if (uitslPane) {
        k._uistate = {
            catVal:      uitslPane.querySelector('.uitsl-cat-sel')?.value      || '',
            distVal:     uitslPane.querySelector('.uitsl-dist-sel')?.value     || '',
            serieVal:    uitslPane.querySelector('.serie-sel')?.value          || '',
            serieCatVal: uitslPane.querySelector('.serie-cat-sel')?.value      || '',
        };
    }
}

function wisselKind(idx) {
    if (idx < 0 || idx >= _kinderen.length) return;
    // Huidige sub-tab + dropdown-keuzes onthouden voordat we wisselen
    _bewaarKindUistate();
    // Programma-UI-state van OUD-actieve kind snapshotten voor terugkeer.
    const oudKey    = _kindKey(_kinderen[_activeKindIdx]);
    const oudeStaat = _snapshotProgUiStatePub();
    if (oudKey && oudeStaat) _progUiStatePerKind.set(oudKey, oudeStaat);
    _activeKindIdx = idx;
    // Restore-doel voor renderKinderen(): eigen state van NIEUW-actieve
    // kind als bekend, anders de state van oud-kind als "poging" (afstand
    // die niet bij nieuw-kind past valt automatisch terug op alle).
    _pendingProgRestore = _progUiStatePerKind.get(_kindKey(_kinderen[idx])) || oudeStaat;
    renderKinderen();
}

function verwijderKind(idx) {
    if (idx < 0 || idx >= _kinderen.length) return;
    // Bewaarde programma-UI-state van weggehaalde kind opruimen.
    _progUiStatePerKind.delete(_kindKey(_kinderen[idx]));
    _kinderen.splice(idx, 1);
    if (_activeKindIdx >= _kinderen.length) _activeKindIdx = Math.max(0, _kinderen.length - 1);
    _saveKids();
    if (_kinderen.length === 0) {
        divResult.innerHTML = '';
    } else {
        renderKinderen();
    }
}

// Status-per-DC voor de klikbare "klik voor status"-badge (alleen gevuld als
// de statussen per DC verschillen). Key = license_key.
let _dcStatusMap = {};
// Status-index → kleur-class (spiegelt STATUS_KLEUR/STATUS_BG). Niet-ingeschreven
// of onbekend → pstat-ni.
function _pstatClass(st, nietIngeschreven) {
    if (nietIngeschreven) return 'pstat-ni';
    const s = parseInt(st);
    return (s >= 0 && s <= 5) ? ('pstat-' + s) : 'pstat-ni';
}
function toonStatusModal(license) {
    const info = _dcStatusMap[license];
    if (!info) return;
    const rows = (info.dc || []).map(x => {
        const s   = parseInt(x.status);
        const lbl = (s >= 0 && s <= 5 ? getStatusLabel(s) : t('status_onbekend'));
        return `<tr><td>${esc(x.dc_naam)}</td><td><span class="persoon-status ${_pstatClass(s, false)}">${esc(lbl)}</span></td></tr>`;
    }).join('');
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = `<div class="overlay-box">
        <div class="heat-card-titel">
            <button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>
            ${esc(info.naam)} &middot; ${esc(t('status_per_afstand'))}
        </div>
        <div class="status-modal-body">
            <table class="status-modal-tabel">${rows}</table>
        </div>
    </div>`;
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
}

function renderResultaat(data, snr, prog) {
        let html = '';
        for (const r of data) {
            const p = r.persoon;
            // entry_status kan NULL zijn als de rijder wel bestaat maar niet
            // ingeschreven is voor deze wedstrijd (via naam/license toegevoegd).
            const nietIngeschreven = p.entry_status === null || p.entry_status === undefined;
            const st = nietIngeschreven ? -1 : parseInt(p.entry_status);
            const stLabel = nietIngeschreven ? t('status_niet_ingeschreven') : (st >= 0 && st <= 5 ? getStatusLabel(st) : t('status_onbekend'));

            // Status-badge: één badge als alle DC's dezelfde status hebben (of
            // niet-ingeschreven / geen DC-info). Bij VERSCHILLENDE status per DC
            // (bv. voor één afstand afgemeld) een klikbare "klik voor status"-
            // badge die een modal met afstand + status opent. Kleuren via
            // .persoon-status + .pstat-*-classes.
            const _dcSt  = Array.isArray(p.dc_statussen) ? p.dc_statussen : [];
            const _uniek = [...new Set(_dcSt.map(x => parseInt(x.status)))];
            let statusHtml;
            if (nietIngeschreven || _dcSt.length === 0 || _uniek.length <= 1) {
                statusHtml = `<span class="persoon-status ${_pstatClass(st, nietIngeschreven)}">${esc(stLabel)}</span>`;
            } else {
                _dcStatusMap[p.license_key] = { naam: p.full_name, dc: _dcSt };
                statusHtml = `<span class="persoon-status persoon-status-klik" onclick="toonStatusModal('${esc(p.license_key)}')">&#9432; ${esc(t('status_klik'))}</span>`;
            }

            html += `
            <div style="margin-top:16px">
                <div class="persoon-header">
                    <div><div class="persoon-naam">${esc(p.full_name)}</div>
                         <span class="persoon-snr">${esc(t('snr_label'))} ${esc(p.wedstrijd_snr??p.start_number)}</span>
                         ${statusHtml}</div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="persoon-cat">${esc(p.category)}</span>
                        <span class="auto-stempel" title="${esc(t('auto_stempel_title'))}">${_huidigStempel}</span>
                    </div>
                </div>
                <div class="tabs">
                    <button class="tab-btn active" data-tab="programma">${esc(t('tab_programma'))}</button>
                    <button class="tab-btn" data-tab="heats">${esc(t('tab_heats'))}</button>
                    <button class="tab-btn" data-tab="rondes">${esc(t('tab_rondes'))}</button>
                    <button class="tab-btn" data-tab="uitslagen">${esc(t('tab_uitslagen'))}</button>
                </div>
                <div class="kaart">`;

            // ── TAB: Programma ────────────────────────────────────────
            html += '<div class="tab-content active" data-tab="programma"><div class="kaart-sectie">';
            // (Klap-balk staat nu NA de filter-strook — coach-volgorde;
            //  Alles-uit/in werkt tenslotte alleen op de actuele afstand.
            //  "Wedstrijdprogramma"-titel weg: de tab zelf is al genoeg.)
            if (prog.ritten?.length) {
                // Interleave ritten en niet-ronde blokken (pauze, inrijden,
                // wedstrijdstart, ceremonie, herstart).
                //
                // KRITIEKE FIX (was: lexicale sort-bug): PDO returnt SMALLINT
                // velden als JS-strings ("10", "100", "20"). Zonder parseInt
                // werd `"100" <= "20"` lexicaal vergeleken (true!) waardoor
                // wedstrijdstart-dag-2 (volgorde="100") op verkeerde plek
                // tussen dag-1-ritten verscheen. Admin (tijdschema.js) doet
                // overal parseInt — public/coach hadden die niet.
                const num       = v => parseInt(v) || 0;
                const items     = [];
                const sortedBlk = (prog.blokken || []).slice()
                    .sort((a, b) => num(a.volgorde) - num(b.volgorde));
                let blkIdx = 0;
                for (const r of (prog.ritten || [])) {
                    const rBV = num(r.blok_volgorde);
                    while (blkIdx < sortedBlk.length
                           && num(sortedBlk[blkIdx].volgorde) <= rBV) {
                        items.push({ type:'blok', data: sortedBlk[blkIdx++] });
                    }
                    items.push({ type:'rit', data: r });
                }
                while (blkIdx < sortedBlk.length) {
                    items.push({ type:'blok', data: sortedBlk[blkIdx++] });
                }

                // Multi-day setup: meerdere wedstrijdstart-blokken → toon één
                // header per dag (Dag N — Zaterdag 28 mei) op de plek waar de
                // dag-cluster begint, niet alleen vóór de wedstrijdstart zelf.
                // Reden: inrijden+pauze direct vóór een wedstrijdstart horen
                // BIJ die nieuwe dag (warm-up voor de dag), niet bij de vorige.
                const wsBlokken = (prog.blokken || [])
                    .filter(b => (b.blok_type || '').toLowerCase() === 'wedstrijdstart')
                    .sort((a, b) => num(a.volgorde) - num(b.volgorde));
                const isMultiDag = wsBlokken.length > 1;
                // dagInfoPerNr: dagNr → { datumLbl (lang, voor header), kortLbl (knop) }
                // Beide locale-aware via getLocale() — Engels op een EN-instelling
                // gebruikt "Fri 28/5", Duits "Fr. 28.5.", Frans "ven. 28/5", etc.
                const dagInfoPerNr = new Map();
                const _locale = (typeof getLocale === 'function') ? getLocale() : 'nl-NL';
                wsBlokken.forEach((ws, i) => {
                    let datumLbl = '', kortLbl = '';
                    if (ws.datum) {
                        const d = new Date(ws.datum + 'T00:00:00');
                        if (!isNaN(d)) {
                            datumLbl = d.toLocaleDateString(_locale,
                                {weekday:'long', day:'numeric', month:'long'});
                            kortLbl  = d.toLocaleDateString(_locale,
                                {weekday:'short', day:'numeric', month:'numeric'});
                        }
                    }
                    dagInfoPerNr.set(i + 1, { datumLbl, kortLbl });
                });

                // Dag-toewijzing per item via twee passes:
                //   1. FORWARD: wedstrijdstart-items zetten huidige dag; ritten
                //      erven die. Andere blokken krijgen tentative natural-dag.
                //   2. BACKWARD: inrijd/pauze/etc. die NA hun natural-dag een
                //      opvolgende wedstrijdstart/rit met hogere dag hebben,
                //      claimen die hogere dag (= warm-up voor volgende dag).
                const dagPerItem = new Array(items.length);
                let huidigeDag = 0;
                items.forEach((it, idx) => {
                    if (it.type === 'blok'
                        && (it.data.blok_type || '').toLowerCase() === 'wedstrijdstart') {
                        const wsIdx = wsBlokken.findIndex(w => String(w.id) === String(it.data.id));
                        if (wsIdx >= 0) huidigeDag = wsIdx + 1;
                    }
                    dagPerItem[idx] = huidigeDag || 1; // pre-dag-1 items → tentative 1
                });
                // Alleen inrijden + pauze "horen bij de volgende dag" als ze
                // vóór een wedstrijdstart liggen — warm-up + baan-voorbereiding
                // zijn altijd vóór de start. Ceremonie (= afsluiting) blijft bij
                // de vorige dag, en blokkeert dan ook de claim-keten zodat
                // eerder-gelegen pauzes niet langs de ceremonie heen claimen.
                let komendeDag = null;
                for (let idx = items.length - 1; idx >= 0; idx--) {
                    const it = items[idx];
                    const bt = it.type === 'blok'
                        ? (it.data.blok_type || '').toLowerCase() : '';
                    const isWs = bt === 'wedstrijdstart';
                    if (it.type === 'rit' || isWs) {
                        komendeDag = dagPerItem[idx];
                    } else if (it.type === 'blok') {
                        const isWarmUp = (bt === 'inrijden' || bt === 'pauze');
                        if (isWarmUp && komendeDag
                            && dagPerItem[idx] < komendeDag) {
                            dagPerItem[idx] = komendeDag;
                        } else if (!isWarmUp) {
                            // ceremonie / herstart: keten breken
                            komendeDag = dagPerItem[idx];
                        }
                    }
                }

                const hhmm = v => { if (!v) return ''; const m = String(v).match(/(\d{1,2}:\d{2})/); return m ? m[1] : ''; };
                const blokIcoon = bt => ({pauze:'⏸',inrijden:'🛼',wedstrijdstart:'🏁',ceremonie:'🏆',herstart:'🔄'}[bt] || '🕓');
                const blokLabel = bt => {
                    const keyMap = {pauze:'prog_blok_pauze', inrijden:'prog_blok_inrijden', wedstrijdstart:'prog_blok_wedstrijdstart', ceremonie:'prog_blok_ceremonie', herstart:'prog_blok_herstart'};
                    return keyMap[bt] ? t(keyMap[bt]) : (bt || '').toUpperCase();
                };

                // Nieuwe filter-strook: dag- + afstand-triggers, elk uitklapbaar
                // naar een pill-balk (identieke stijl als de "Alles in / uit / Mijn"
                // balk). Na keuze klapt het paneel weer in. Bij 1 dag verdwijnt
                // dag-trigger, bij 1 unieke afstand verdwijnt afstand-trigger.
                // Verzamel unieke afstanden per dag uit items (heeft distance_naam).
                const _afsPerDag = new Map();  // dag -> Set<afstand>
                const _afsAlle   = new Set();
                items.forEach((it, idx) => {
                    if (it.type !== 'rit' || !it.data.distance_naam) return;
                    const dg = dagPerItem[idx];
                    if (!_afsPerDag.has(dg)) _afsPerDag.set(dg, new Set());
                    _afsPerDag.get(dg).add(it.data.distance_naam);
                    _afsAlle.add(it.data.distance_naam);
                });
                const afsAlleArr = [..._afsAlle].sort((a,b) => a.localeCompare(b, 'nl', {numeric:true}));
                const heeftMeerdereAfs = afsAlleArr.length > 1;
                html += '<div class="prog-sticky-kop">';   // filter + klap-balk blijven boven bij scrollen
                if (isMultiDag || heeftMeerdereAfs) {
                    // JSON van afstanden-per-dag zodat JS bij dag-wissel het paneel kan filteren.
                    const afsPerDagObj = {};
                    for (const [dg, set] of _afsPerDag) afsPerDagObj[dg] = [...set].sort((a,b) => a.localeCompare(b, 'nl', {numeric:true}));
                    html += `<div class="prog-filter-strook" data-actieve-dag="alle" data-actieve-afstand="alle"
                                  data-afs-per-dag='${esc(JSON.stringify(afsPerDagObj))}'>`;
                    if (isMultiDag) {
                        html += `<button class="prog-filter-trigger" type="button" data-filter="dag" onclick="togglePanel(this)">
                            <span class="prog-filter-icon">📅</span>
                            <span class="prog-filter-lbl">${esc(t('prog_filter_alle_dagen'))}</span>
                            <span class="prog-filter-caret">▼</span>
                        </button>
                        <div class="prog-filter-panel verborgen" data-panel="dag">
                            <button class="prog-filter-pill actief" type="button" data-value="alle"
                                    onclick="kiesProgFilter('dag','alle',this)">${esc(t('prog_dag_alle'))}</button>`;
                        for (let dn = 1; dn <= wsBlokken.length; dn++) {
                            const info     = dagInfoPerNr.get(dn);
                            const subDatum = info?.kortLbl
                                ? `<span class="prog-filter-pill-sub">${esc(info.kortLbl)}</span>`
                                : '';
                            html += `<button class="prog-filter-pill" type="button" data-value="${dn}"
                                             onclick="kiesProgFilter('dag','${dn}',this)"
                                             title="${esc(info?.datumLbl || '')}"
                                >${esc(t('prog_dag'))} ${dn}${subDatum}</button>`;
                        }
                        html += `</div>`;
                    }
                    if (heeftMeerdereAfs) {
                        html += `<button class="prog-filter-trigger" type="button" data-filter="afstand" onclick="togglePanel(this)">
                            <span class="prog-filter-icon">🏁</span>
                            <span class="prog-filter-lbl">${esc(t('prog_filter_alle_afstanden'))}</span>
                            <span class="prog-filter-caret">▼</span>
                        </button>
                        <div class="prog-filter-panel verborgen" data-panel="afstand">
                            <button class="prog-filter-pill actief" type="button" data-value="alle"
                                    onclick="kiesProgFilter('afstand','alle',this)">${esc(t('prog_afstand_alle'))}</button>`;
                        for (const afs of afsAlleArr) {
                            html += `<button class="prog-filter-pill" type="button" data-value="${esc(afs)}"
                                             onclick="kiesProgFilter('afstand',this.dataset.value,this)"
                                >${esc(afs)}</button>`;
                        }
                        html += `</div>`;
                    }
                    html += `</div>`;
                }

                // Klap-balk NA de filter-strook (2026-07-06: matcht coach-
                // volgorde). Alles-uit/in/mijn opereert alleen binnen de
                // gekozen dag+afstand, dus logisch dat filter erboven staat.
                html += `<div class="prog-klap-balk" data-actief="in">
                    <button type="button" class="prog-klap-btn actief" data-actie="in"   onclick="klapProgPub(this,'in')">▶ ${esc(t('prog_klap_alles_uit'))}</button>
                    <button type="button" class="prog-klap-btn" data-actie="uit"  onclick="klapProgPub(this,'uit')">▼ ${esc(t('prog_klap_alles_in'))}</button>
                    <button type="button" class="prog-klap-btn" data-actie="mijn" onclick="klapProgPub(this,'mijn')">👤 ${esc(t('prog_klap_mijn'))}</button>
                </div>`;
                html += '</div>';   // /prog-sticky-kop

                // Rit-namen van de ándere gevolgde kinderen — lokaal uit _kinderen
                // (elk kind heeft z'n lookup/heats al opgehaald), dus GÉÉN extra
                // dataverkeer. Alleen hier (Programma-tab) markeren we ze, in een
                // andere kleur dan de geselecteerde rijder.
                const _actLic = r.persoon?.license_key;
                const familieRitNamen = new Set();
                if (typeof _kinderen !== 'undefined' && _kinderen.length > 1) {
                    for (const _k of _kinderen) {
                        const _kp   = _k.data?.[_k.kozen_idx ?? 0];
                        const _kLic = _kp?.persoon?.license_key;
                        if (!_kp || !_kLic || _kLic === _actLic) continue;
                        for (const _h of (_kp.heats || [])) if (_h.rit_naam) familieRitNamen.add(_h.rit_naam);
                    }
                }
                if (familieRitNamen.size) {
                    html += `<div class="prog-legenda">
                        <span class="prog-leg-item"><span class="prog-leg-swatch leg-mijn"></span>${esc(t('prog_leg_mijn'))}</span>
                        <span class="prog-leg-item"><span class="prog-leg-swatch leg-familie"></span>${esc(t('prog_leg_familie'))}</span>
                    </div>`;
                }

                let nr = 0;
                let vorigeDag = null;
                let vorigeCombi = null;
                let vorigeGroepKey = null;
                let combiWrapOpen = false;   // outer wrapper om meerdere cat-groepen die samen rijden
                // Live-tellers voor de huidige groep. Post-render vullen we
                // de markers met mijn-dot en status-icoon aan.
                let huidigeGroepHeeftMijn = false;
                let huidigeGroepHeeftFamilie = false;
                let huidigeGroepAantalRitten = 0;
                let huidigeGroepMetRes = 0;
                let huidigeGroepDefinitief = 0;
                const groepHdrPlaceholders = [];

                // Combi-wrapper open/sluit: outer container die meerdere cat-
                // groepen (Pupil 1 meisjes + Pupil 1 jongens) omhult wanneer ze
                // in dezelfde combi_group zitten. Elke cat behoudt eigen inklap.
                const openCombiWrap = (dag, afstand) => {
                    const afsAttr = afstand ? ` data-afstand-key="${esc(afstand)}"` : '';
                    html += `<div class="prog-combi-wrap" data-dag-nr="${dag}"${afsAttr}>
                        <div class="prog-combi-kop">${esc(t('prog_combi_kop'))}</div>
                        <div class="prog-combi-body">`;
                    combiWrapOpen = true;
                };
                const sluitCombiWrap = () => {
                    if (combiWrapOpen) { html += `</div></div>`; combiWrapOpen = false; }
                    vorigeCombi = null;
                };
                const bepaalStatusPub = () => {
                    if (huidigeGroepAantalRitten === 0) return { icon: '', i18nKey: '' };
                    if (huidigeGroepMetRes === huidigeGroepAantalRitten)  return { icon: '🏁', i18nKey: 'prog_groep_status_klaar' };
                    if (huidigeGroepMetRes > 0)                           return { icon: '◑', i18nKey: 'prog_groep_status_deels' };
                    if (huidigeGroepDefinitief === huidigeGroepAantalRitten) return { icon: '🚩', i18nKey: 'prog_groep_status_geloot' };
                    return { icon: '', i18nKey: '' };
                };
                const sluitGroepPub = () => {
                    if (vorigeGroepKey !== null) {
                        html += `</div></div>`;
                        const st = bepaalStatusPub();
                        groepHdrPlaceholders.push({
                            key: vorigeGroepKey,
                            heeftMijn: huidigeGroepHeeftMijn,
                            heeftFamilie: huidigeGroepHeeftFamilie,
                            statusIcon: st.icon,
                            statusKey: st.i18nKey,
                        });
                        vorigeGroepKey = null;
                        huidigeGroepHeeftMijn = false;
                        huidigeGroepHeeftFamilie = false;
                        huidigeGroepAantalRitten = 0;
                        huidigeGroepMetRes = 0;
                        huidigeGroepDefinitief = 0;
                    }
                };
                // Volledige sluit: eerst groep, dan combi-wrapper.
                const sluitAllesPub = () => { sluitGroepPub(); sluitCombiWrap(); };
                const openGroepPub = (key, rit, dag) => {
                    // Bij eerste render van een rijder-tab: altijd ingeklapt
                    // (default). `_progIngeklaptPub` wordt pas post-render
                    // gevuld — dus bij render-tijd zou alles open lijken.
                    const ingeklapt = _progEersteRenderPub || _progIngeklaptPub.has(key);
                    const rondeLbl  = rit.ronde_type && BADGE[rit.ronde_type]
                        ? `<span class="heat-card-badge ${BADGE[rit.ronde_type]}" style="margin-right:6px">${getRondeLabel(rit.ronde_type)}</span>`
                        : '';
                    const idx = groepHdrPlaceholders.length;
                    const iconMarker = `[[STATUS-ICON-${idx}]]`;
                    // Marker in de class-attribute: bij post-fix vervangen we
                    // deze met " mijn" (oranje strip-left) of "". Inline zodat
                    // multi-kind view geen scope-verwarring krijgt.
                    const mijnMarker = `[[MIJN-CLASS-${idx}]]`;
                    const afsAttr = rit.distance_naam ? ` data-afstand-key="${esc(rit.distance_naam)}"` : '';
                    const rtAttr  = rit.ronde_type ? ` data-ronde-type="${esc(rit.ronde_type)}"` : '';
                    html += `<div class="prog-groep${ingeklapt ? ' ingeklapt' : ''}${mijnMarker}" data-groep-key="${esc(key)}" data-dag-nr="${dag}"${afsAttr}${rtAttr}>
                        <div class="prog-groep-hdr" onclick="klapGroepPub(this)">
                            <span class="prog-groep-chev">▼</span>
                            <span class="prog-groep-status">${iconMarker}</span>
                            <span class="prog-groep-titel">${rondeLbl}${esc(rit.dc_naam ?? '')}</span>
                            [[MULTI-DOT-${idx}]]
                        </div>
                        <div class="prog-groep-body">`;
                    vorigeGroepKey = key;
                    huidigeGroepHeeftMijn = false;
                    huidigeGroepHeeftFamilie = false;
                    huidigeGroepAantalRitten = 0;
                    huidigeGroepMetRes = 0;
                    huidigeGroepDefinitief = 0;
                };

                items.forEach((it, idx) => {
                    const dag = dagPerItem[idx];
                    // Dag-header + sluit alles bij dag-wisseling.
                    if (isMultiDag && dag !== vorigeDag) {
                        sluitAllesPub();
                        const info = dagInfoPerNr.get(dag);
                        const lbl = info?.datumLbl ? `Dag ${dag} — ${info.datumLbl}` : `Dag ${dag}`;
                        html += `<div class="prog-dag-header" data-dag-nr="${dag}">${esc(lbl)}</div>`;
                        vorigeDag = dag;
                    }
                    // Blok = tussen groepen op tijd-plek — sluit ook combi-wrapper.
                    if (it.type === 'blok') {
                        sluitAllesPub();
                        const b = it.data;
                        const bt = (b.blok_type || '').toLowerCase();
                        const tijd = hhmm(b.tijdstip);
                        const tijdHtml = tijd ? `<span class="prog-blok-tijd">🕓 ${esc(tijd)}</span>` : '';
                        const duurHtml = b.duur ? `<span class="prog-blok-duur">${b.duur} ${t('prog_blok_min')}</span>` : '';
                        const opmHtml  = b.opmerking ? `<span class="prog-blok-opm"> — ${esc(b.opmerking)}</span>` : '';
                        const catsHtml = b.inrijd_cat_namen ? `<div class="prog-blok-cats">${esc(b.inrijd_cat_namen)}</div>` : '';
                        html += `<div class="prog-blok-rij prog-blok-${esc(bt)}" data-dag-nr="${dag}">
                            <div class="prog-blok-top">
                                ${tijdHtml}
                                <span class="prog-blok-titel">${blokIcoon(bt)} ${esc(blokLabel(bt))}</span>
                                ${duurHtml}
                                ${opmHtml}
                            </div>
                            ${catsHtml}
                        </div>`;
                        return;
                    }
                    const rit = it.data;
                    nr++;
                    // 1) Combi-wrap: bij combi_group-wissel sluit lopende groep
                    //    + combi-wrapper, en open nieuwe combi-wrapper indien
                    //    de nieuwe rit een combi_group heeft.
                    const combi = rit.combi_group ? parseInt(rit.combi_group) : null;
                    if (combi !== vorigeCombi) {
                        sluitGroepPub();
                        sluitCombiWrap();
                        if (combi !== null) openCombiWrap(dag, rit.distance_naam);
                        vorigeCombi = combi;
                    }
                    // 2) Groep per (dc_naam + ronde_type + dag) — binnen combi-wrap.
                    const grpKey = `${rit.dc_naam || '?'}|${rit.ronde_type || '?'}|${dag}`;
                    if (grpKey !== vorigeGroepKey) {
                        sluitGroepPub();
                        openGroepPub(grpKey, rit, dag);
                    }

                    const isInRit = r.heats.some(h => h.rit_naam === rit.rit_naam);
                    const heeftAnder = familieRitNamen.has(rit.rit_naam);
                    const isFamilie  = !isInRit && heeftAnder;   // alleen ander kind → violet
                    const ookFamilie =  isInRit && heeftAnder;   // geselecteerd kind + ander kind samen
                    if (isInRit) huidigeGroepHeeftMijn = true;
                    if (heeftAnder) huidigeGroepHeeftFamilie = true;
                    huidigeGroepAantalRitten += 1;
                    const gereden = rit.resultaten_count > 0;
                    if (gereden) huidigeGroepMetRes += 1;
                    if (rit.definitief) huidigeGroepDefinitief += 1;

                    const rt = rit.ronde_type ?? 'heats';
                    const statusIcon = gereden ? '🏁'
                                     : rit.definitief ? '🚩'
                                     : '';
                    const opmHtml = rit.rit_opmerking
                        ? `<div class="prog-rit-opm">📝 ${esc(rit.rit_opmerking)}</div>` : '';
                    html += `<div class="prog-rij${isInRit ? ' prog-rij-mijn' : ''}${isFamilie ? ' prog-rij-familie' : ''}"
                                 data-rit-naam="${esc(rit.rit_naam)}" data-dc-naam="${esc(rit.dc_naam)}"
                                 data-dag-nr="${dag}" onclick="toonRitDetail(this)">
                        <span class="prog-nr">${statusIcon} ${nr}</span>
                        <span class="prog-naam">${esc(rit.rit_naam)}${opmHtml}</span>
                        ${ookFamilie ? `<span class="prog-rij-multi" title="${esc(t('prog_multi_title'))}">👥</span>` : ''}
                        <span class="prog-type heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                    </div>`;
                });
                sluitAllesPub();

                // Post-fix: status-icoon + mijn-class inline vervangen.
                // De ` mijn`-class geeft de oranje strip-links (duidelijker
                // bij scrollen dan een kleine stip achter de titel).
                groepHdrPlaceholders.forEach((p, i) => {
                    const iconMarker  = `[[STATUS-ICON-${i}]]`;
                    const klasseMarker = `[[MIJN-CLASS-${i}]]`;
                    const iconHtml = p.statusIcon
                        ? `<span title="${esc(t(p.statusKey))}">${p.statusIcon}</span>`
                        : '';
                    const klasseHtml = p.heeftMijn ? ' mijn' : (p.heeftFamilie ? ' familie' : '');
                    const multiMarker = `[[MULTI-DOT-${i}]]`;
                    const multiHtml = (p.heeftMijn && p.heeftFamilie)
                        ? `<span class="prog-groep-multi-dot" title="${esc(t('prog_multi_title'))}"></span>` : '';
                    html = html.replace(iconMarker, iconHtml).replace(klasseMarker, klasseHtml).replace(multiMarker, multiHtml);
                });
                _progAlleKeysPub = groepHdrPlaceholders.map(p => p.key);
                _progGroepenMetMijnPub.clear();
                groepHdrPlaceholders.forEach(p => {
                    if (p.heeftMijn) _progGroepenMetMijnPub.add(p.key);
                });
                if (_progEersteRenderPub) {
                    _progAlleKeysPub.forEach(k => _progIngeklaptPub.add(k));
                    _progEersteRenderPub = false;
                }
            } else {
                html += `<div class="melding">${esc(t('msg_programma_nb'))}</div>`;
            }
            html += '</div></div>';

            // ── TAB: Heats (heat-cards) ──────────────────────────────
            html += '<div class="tab-content" data-tab="heats">';
            if (r.heats.length) {
                for (const h of r.heats) {
                    const rt = h.ronde_type ?? 'heats';
                    const naam = h.rit_naam ?? h.heat_naam ?? '';

                    // Vorige ronde nog niet compleet → placeholder ipv heat-card.
                    // Backend zet deze flag voor KF/HF/Finale/Runner-up als de
                    // bron-ronde nog niet helemaal verwerkt is.
                    if (h.vorige_niet_compleet) {
                        html += `<div class="heat-card heat-card-pending">
                            <div class="heat-card-titel">
                                <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                                <span class="flex-1">${esc(naam)}</span>
                                <span style="font-size:1rem" title="${esc(t('heat_wachten_vorige'))}">⏳</span>
                            </div>
                            <div style="padding:.6rem .8rem;color:#666;font-style:italic;font-size:.85rem">
                                ${esc(t('msg_vorige_ronde_nb'))}
                            </div>
                        </div>`;
                        continue;
                    }

                    const mijnTijd = h.tijd_ms != null ? msTijd(h.tijd_ms) : '';
                    const mijnPos = h.finishpositie != null ? '#' + h.finishpositie : '';
                    const mijnSanctie = sl(h.sanctie);
                    // Audit-spoor: bruto verschilt van officieel → toon
                    // beide (gemeten + officieel) met 📷 (fotofinish-
                    // wisseling) of ✋ (handmatige correctie door jury).
                    const heeftBrutoAudit = h.bruto_tijd_ms != null
                                         && h.tijd_ms != null
                                         && h.bruto_tijd_ms !== h.tijd_ms;
                    // == 1 (niet truthy-check): PDO/JSON kan is_photofinish als
                    // string "0"/"1" sturen — in JS is "0" truthy, dus de oude
                    // r.is_photofinish ? ... gaf altijd 📷 ipv ✋ voor RR-tijden.
                    const brutoIcon  = h.is_photofinish == 1 ? '📷' : '✋';
                    const brutoTijd  = heeftBrutoAudit ? msTijd(h.bruto_tijd_ms) : '';

                    const extra = heatExtraKolommen(h.rijders ?? [], rt);
                    const rijders = h.rijders ?? [];
                    const heeftResultaten = rijders.some(r => r.finishpositie != null || r.tijd_ms != null);
                    const heeftRijders = rijders.length > 0;
                    const heatIcon = heeftResultaten ? '🏁' : heeftRijders ? '🚩' : '';
                    html += `<div class="heat-card">
                        <div class="heat-card-titel">
                            <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                            <span class="flex-1">${esc(naam)}</span>
                            ${heatIcon ? `<span style="font-size:1rem">${heatIcon}</span>` : ''}
                        </div>
                        <table class="heat-card-tabel">
                        <thead>${heatTabelHeader(extra)}</thead>
                        <tbody>`;

                    // Sorteer heat-rijders — startvolgorde vóór de rit,
                    // finishvolgorde erna (zelfde helper als de rit-detail-
                    // modal in de programma-tab).
                    for (const rr of _sorteerHeatRijders(h.rijders ?? [])) {
                        // Match op license_key (uniek) — fallback op snr voor
                        // backwards-compat met oude payloads.
                        const isIk = (p.license_key && rr.license_key)
                            ? rr.license_key === p.license_key
                            : String(rr.snr) === snr;
                        html += heatTabelRij(rr, isIk, extra);
                    }

                    html += '</tbody></table>';
                    if (mijnTijd || mijnPos || mijnSanctie) {
                        // Format tijd-deel: bij audit-mismatch compact "✋ bruto → officieel",
                        // anders gewoon de officiële tijd. Pijl past op één regel.
                        const tijdHtml = heeftBrutoAudit
                            ? `${brutoIcon} ${esc(brutoTijd)} → ${esc(mijnTijd)}`
                            : (mijnTijd ? esc(mijnTijd) : '');
                        html += `<div class="heat-card-mijn-result">
                            <span>${esc(t('heat_jouw_resultaat'))}</span>
                            <span>${tijdHtml} ${mijnPos ? esc(mijnPos) : ''} ${mijnSanctie ? `<span class="heat-sanctie">${esc(mijnSanctie)}</span>` : ''}</span>
                        </div>`;
                    }
                    html += '</div>';
                }
            } else {
                html += `<div class="kaart-sectie"><div class="melding">${esc(t('msg_nog_geen_heats'))}</div></div>`;
            }
            html += '</div>';

            // ── TAB: Resultaten ───────────────────────────────────────
            // Geert 2026-07-01: vervangt de oude eind-uitslag-per-afstand.
            // Nieuwe layout: per afstand → per ronde de complete uitslag
            // met Q/q (zoals live-verwerking) + eind-uitslag onderaan.
            // Lazy-load: fetch bij eerste tab-activatie via renderRondeUitslagen().
            // Alle unieke DC-IDs waar deze rijder in zit — een rijder kan in
            // meerdere categorie-combinaties meedoen (bv. eigen cat + open cat).
            const _dcIdsRes = [...new Set((r.heats || []).map(h => h.distance_combination_id).filter(Boolean))].join(',');
            const _licRes   = esc(p.license_key ?? '');
            html += `<div class="tab-content" data-tab="rondes">
                <div class="ronde-uitslagen-container"
                     data-dc-ids="${esc(_dcIdsRes)}" data-lic="${_licRes}" data-geladen="0">
                    <div class="melding">${esc(t('msg_laden'))}</div>
                </div>
            </div>`;

            // ── TAB: Uitslagen (volledig overzicht) ──────────────────
            html += `<div class="tab-content" data-tab="uitslagen">
                <div class="kaart-sectie">
                <div class="kaart-sectie-titel">${esc(t('uitsl_titel'))}</div>
                <div class="uitsl-selects">
                    <select class="uitsl-cat-sel"><option value="">${esc(t('msg_laden'))}</option></select>
                    <select class="uitsl-dist-sel" disabled><option value="">${esc(t('uitsl_opt_kies_afstand'))}</option></select>
                </div>
                <div class="uitsl-tabel-wrap"></div>
            </div>
            <div class="kaart-sectie" data-serie-lijst hidden>
                <div class="kaart-sectie-titel">${esc(t('serie_titel'))}</div>
                <div data-serie-selector class="uitsl-selects"></div>
                <div class="serie-klas-tabel-wrap"></div>
            </div></div>`;

            html += '</div></div>'; // kaart + wrapper
        }

        // Schrijf in de kind-content-container (multi-rijder-modus) als die
        // bestaat, anders valt terug op het oude gedrag (divResult direct).
        const target = document.getElementById('kind-content') || divResult;
        target.innerHTML = html;

        // Tab-switching
        target.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const kaart = btn.closest('.tabs').nextElementSibling;
                if (!kaart) return;
                btn.closest('.tabs').querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                kaart.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
                kaart.querySelector(`.tab-content[data-tab="${btn.dataset.tab}"]`)?.classList.add('active');

                // Sub-tab-keuze onthouden per kind (voor multi-rijder-modus)
                if (typeof _kinderen !== 'undefined' && _kinderen[_activeKindIdx]) {
                    _kinderen[_activeKindIdx].sub_tab = btn.dataset.tab;
                }

                // Uitslagen-tab: laad categorieën bij eerste klik
                if (btn.dataset.tab === 'uitslagen') initUitslagenTab(kaart);
                // Rondes-tab (ronde-uitslagen): lazy-load bij eerste opening.
                if (btn.dataset.tab === 'rondes') {
                    const cont = kaart.querySelector('.ronde-uitslagen-container');
                    if (cont && cont.dataset.geladen === '0') renderRondeUitslagen(cont);
                }
            });
        });

}

// ── Resultaten-tab: ronde-uitslagen renderer ─────────────────────────────────
// Vervangt de oude eind-uitslag-per-afstand. Toont per afstand een blok, per
// ronde een sub-blok met de volledige uitslag (Q/q, sancties, ronde-info),
// en onderaan de eind-uitslag uit uitslag_afstand.
async function renderRondeUitslagen(container) {
    const compId = selComp.value;
    const dcIds  = (container.dataset.dcIds || '').split(',').filter(Boolean);
    const lic    = container.dataset.lic;
    if (!compId || !dcIds.length) { container.innerHTML = ''; return; }

    container.dataset.geladen = '1';
    container.innerHTML = `<div class="melding">${esc(t('msg_laden'))}</div>`;

    // Één fetch per DC — parallel voor snelheid. Combineer daarna de
    // distances-lijst; als er meerdere DCs waren staat elke DC z'n
    // afstanden onder elkaar (in DC-volgorde uit dcIds).
    let distances = [];
    try {
        const responses = await Promise.all(dcIds.map(dcId =>
            safeFetch(`?action=ronde_uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&license_key=${encodeURIComponent(lic || '')}`)
              .then(r => r.json())
        ));
        for (const data of responses) {
            if (Array.isArray(data?.distances)) distances = distances.concat(data.distances);
        }
    } catch (e) {
        container.innerHTML = `<div class="melding">${esc(t('err_prefix', {msg: e.message}))}</div>`;
        return;
    }

    if (!distances.length) {
        container.innerHTML = `<div class="melding">${esc(t('msg_nog_geen_resultaten'))}</div>`;
        return;
    }

    let html = '';
    for (const d of distances) {
        html += `<div class="rondeu-afstand">
            <div class="rondeu-afstand-titel">${esc(d.distance_naam)}</div>`;

        if (!d.rondes.length && !d.eind_uitslag.length) {
            html += `<div class="melding">${esc(t('rondeu_nog_niets'))}</div>`;
        }

        // Rondes
        for (const r of d.rondes) {
            html += `<div class="rondeu-ronde ${r.compleet ? '' : 'pending'}">
                <div class="rondeu-ronde-titel">
                    <span class="rondeu-badge badge-${r.ronde_type}">${esc(r.ronde_label)}</span>
                    ${r.compleet ? '' : `<span class="rondeu-pending">${esc(t('rondeu_pending'))}</span>`}
                </div>`;

            if (r.compleet && r.rijders.length) {
                // Kolom-decisies per race-type: bij puntenkoers/afvalkoers/inline
                // zijn rondes en (voor puntenkoers) sprint-punten inhoudelijk
                // veel belangrijker dan de tijd. Fin-kolom (officiële finish-
                // volgorde) tonen we altijd als er data is.
                const isLangeAfstand = ['puntenkoers','afvalkoers','inline'].includes(d.race_type);
                const heeftFin      = r.rijders.some(x => x.finishpositie != null);
                const heeftRondes   = isLangeAfstand && r.rijders.some(x => x.rondes != null);
                const heeftPkPunten = d.race_type === 'puntenkoers' && r.rijders.some(x => x.pk_punten != null);
                const pkMaxR        = heeftPkPunten ? pkMaxRnd(r.rijders) : 0;
                // Sorteer: Q eerst op tijd, dan q op tijd, dan rest op tijd/positie.
                // Voor runner-up: op ru_positie oplopend.
                const rijders = [...r.rijders];
                if (r.ronde_type === 'runner_up') {
                    rijders.sort((a, b) => (a.ru_positie ?? 999) - (b.ru_positie ?? 999));
                } else if (r.ronde_type === 'finale_b') {
                    // B-finales gescheiden per heat: eerst B1 alle rijders fin
                    // 1..n, dan B2 fin 1..n, etc. Anders worden ze door elkaar
                    // getoond (twee "fin 1"'s in verschillende heats).
                    rijders.sort((a, b) => {
                        const ha = a.heat_nr ?? 999, hb = b.heat_nr ?? 999;
                        if (ha !== hb) return ha - hb;
                        const fa = a.finishpositie ?? 999;
                        const fb = b.finishpositie ?? 999;
                        if (fa !== fb) return fa - fb;
                        const ta = a.tijd_ms ?? 999999999;
                        const tb = b.tijd_ms ?? 999999999;
                        return ta - tb;
                    });
                } else {
                    // Volgorde binnen een ronde:
                    //   1. Q's op tijd (snelste eerst)
                    //   2. q's op tijd
                    //   3. Overige rijders — bij doorstroom-rondes
                    //      (heats/KF/HF) puur op tijd (fin uit verschillende
                    //      heats is niet vergelijkbaar); bij finale_a is fin
                    //      de officiële ranking, tijd = tiebreaker.
                    //   4. Uitvallers (DNS/DNF/DQ-*) altijd helemaal onderaan.
                    const _ord = x => x.kwal === 'Q' ? 0 : x.kwal === 'q' ? 1 : 2;
                    const _uitvalCodes = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF'];
                    const _isUit = x => {
                        const s = String(x.sanctie || '').toUpperCase().split(/[,\s]+/);
                        return _uitvalCodes.some(c => s.includes(c));
                    };
                    // A-finale sortering volgt finale_ranking uit admin's
                    // Uitslag-module — ALLEEN voor sprint-afstanden:
                    //   'time'          → puur op tijd (correct bij 200m DTT)
                    //   'position_time' → op finishpositie, tijd tiebreak (default)
                    // Voor lange afstanden (puntenkoers/afvalkoers/inline) is
                    // tijd niet leidend en houdt admin's finishpositie al
                    // rekening met punten en rondes — die altijd volgen,
                    // ongeacht finale_ranking.
                    const _finaleFin = ['finale_a'].includes(r.ronde_type)
                                       && (isLangeAfstand || d.finale_ranking !== 'time');
                    rijders.sort((a, b) => {
                        // Uitvallers altijd naar het einde
                        const ua = _isUit(a), ub = _isUit(b);
                        if (ua !== ub) return ua ? 1 : -1;
                        // Q/q-groep bepaalt de blok-volgorde
                        const oa = _ord(a), ob = _ord(b);
                        if (oa !== ob) return oa - ob;
                        // Binnen de "rest"-groep (oa === 2) bij finale_a
                        // (behalve tijdkoppeling): finishpositie leidend,
                        // tijd tiebreaker.
                        if (oa === 2 && _finaleFin) {
                            const fa = a.finishpositie ?? 999;
                            const fb = b.finishpositie ?? 999;
                            if (fa !== fb) return fa - fb;
                        }
                        // In alle andere gevallen: puur op tijd.
                        const ta = a.tijd_ms ?? 999999999;
                        const tb = b.tijd_ms ?? 999999999;
                        return ta - tb;
                    });
                }

                html += `<table class="rondeu-tabel">
                    <thead><tr>
                        ${r.ronde_type === 'runner_up' ? `<th class="c">${esc(t('rondeu_col_pos'))}</th>` : ''}
                        <th class="c">${esc(t('rondeu_col_snr'))}</th>
                        <th>${esc(t('rondeu_col_naam'))}</th>
                        <th class="c">${esc(t('rondeu_col_kwal'))}</th>
                        ${heeftRondes   ? `<th class="c">${esc(t('rondeu_col_rondes'))}</th>` : ''}
                        ${heeftPkPunten ? `<th class="c">${esc(t('rondeu_col_pkpt'))}</th>`   : ''}
                        <th class="c">${esc(t('rondeu_col_tijd'))}</th>
                        <th class="c">${esc(t('rondeu_col_sanctie'))}</th>
                        ${heeftFin      ? `<th class="c">${esc(t('rondeu_col_fin'))}</th>`    : ''}
                    </tr></thead>
                    <tbody>`;
                let vorigHeatNr = null;
                for (const rr of rijders) {
                    // Sub-header per heat bij finale_b / runner_up: bij wissel
                    // van heat_nr → tussenrij met "B1"/"B2" of "RU1"/"RU2".
                    if ((r.ronde_type === 'finale_b' || r.ronde_type === 'runner_up')
                        && rr.heat_nr !== vorigHeatNr) {
                        const prefix = r.ronde_type === 'finale_b' ? 'B' : 'RU';
                        const nr = rr.heat_nr ?? '?';
                        html += `<tr class="rondeu-heat-sub"><td colspan="99">${esc(prefix + nr)}</td></tr>`;
                        vorigHeatNr = rr.heat_nr;
                    }
                    const isIk = lic && rr.person_license === lic;
                    // Q/q badge + optioneel doorstroom-doelfinale (A / B1 / B2 / …).
                    // Bij full-final krijgt iedereen Q/q, dan geeft de suffix pas
                    // context: "Q→A" vs "Q→B1" is duidelijker dan alleen "Q".
                    const dsSuffix = rr.doorstroom_label
                        ? `<span style="color:#666;font-weight:600">→${esc(rr.doorstroom_label)}</span>` : '';
                    const kwalHtml = rr.kwal === 'Q'
                        ? `<b style="color:#198754">Q</b>${dsSuffix}`
                        : rr.kwal === 'q' ? `<b style="color:#0d6efd">q</b>${dsSuffix}`
                        : dsSuffix;
                    const tijdStr = rr.tijd_ms != null ? msTijd(rr.tijd_ms) : '—';
                    const sanctieStr = rr.sanctie || '';
                    // Non-finisher (DNS/DNF/DQ-*): geen Fin-positie tonen
                    // ook al staat 'ie in DB. Zelfde regel als in de rit-modal.
                    const sanctieCodes = String(sanctieStr).toUpperCase().split(/[,\s]+/);
                    const isNonFin = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF'].some(c => sanctieCodes.includes(c));
                    const finVal = (isNonFin || rr.finishpositie == null) ? '' : rr.finishpositie;
                    html += `<tr${isIk ? ' class="rij-ik"' : ''}>
                        ${r.ronde_type === 'runner_up' ? `<td class="c uitsl-tc-b7-dark">${rr.ru_positie ?? '—'}</td>` : ''}
                        <td class="c uitsl-tc-b6">${esc(rr.snr ?? '')}</td>
                        <td>${esc(rr.full_name)}</td>
                        <td class="c">${kwalHtml}</td>
                        ${heeftRondes   ? `<td class="c">${rr.rondes ?? '—'}</td>` : ''}
                        ${heeftPkPunten ? `<td class="c uitsl-tc-b6">${pkPuntCel(rr, pkMaxR) || '—'}</td>` : ''}
                        <td class="c mono">${esc(tijdStr)}</td>
                        <td class="c" style="color:#c00;font-weight:600">${esc(sanctieStr)}</td>
                        ${heeftFin      ? `<td class="c uitsl-tc-b7-dark">${esc(finVal)}</td>` : ''}
                    </tr>`;
                }
                html += `</tbody></table>`;
            }
            html += `</div>`;
        }

        // Eind-uitslag NIET tonen hier — die staat onder de Uitslagen-tab.
        // Resultaten = alleen de rondes waar deze rijder zelf in zat.

        html += `</div>`;
    }

    container.innerHTML = html;
}

// ── Uitslagen-tab logica ──────────────────────────────────────────────────
// Cat-dropdown werkt op DC-basis: één optie per DC. Solo-DC label = cat-code
// ("DP4"); combi-DC label = cats gesorteerd op leeftijd ("HJA + HSA").
// Gecombineerd rijden = één uitslag; splitsen op individuele cat zou de
// wedstrijdrealiteit verkeerd voorstellen.
let _catCache = null; // cache _rondes_cats-payload per sessie

async function initUitslagenTab(kaart) {
    const catSel  = kaart.querySelector('.uitsl-cat-sel');
    const distSel = kaart.querySelector('.uitsl-dist-sel');
    const wrap    = kaart.querySelector('.uitsl-tabel-wrap');
    if (!catSel || catSel.dataset.loaded) return;
    catSel.dataset.loaded = '1';

    const compId = selComp.value;
    if (!compId) return;

    // ── Serie-klassementen waar deze wedstrijd aan meedoet ──
    initSerieKlassementen(kaart, compId);

    try {
        if (!_catCache || _catCache.compId !== compId) {
            const res = await safeFetch(`?action=rondes_cats&competition_id=${encodeURIComponent(compId)}&_t=${Date.now()}`);
            _catCache = { compId, data: await res.json() };
        }
        const cats = _catCache.data;
        if (cats.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(cats.error)}</div>`; return; }

        catSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_cat'))}</option>`;
        for (const c of cats) {
            const o = document.createElement('option');
            o.value = c.sig;
            o.textContent = c.label;
            o.dataset.json = JSON.stringify(c);
            catSel.appendChild(o);
        }
    } catch (e) {
        wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
    }

    // Cat-change → vul afstand-dropdown. Value-format:
    //   afstand:    "afstand|<dc_id>|<distance_id>"
    //   klassement: "klassement|<dc_id>"
    // dc_id kan per afstand verschillen als meerdere DC's dezelfde cats delen.
    catSel.addEventListener('change', () => {
        wrap.innerHTML = '';
        const opt = catSel.selectedOptions[0];
        if (!opt?.value) { distSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_afstand'))}</option>`; distSel.disabled = true; return; }
        const cat = JSON.parse(opt.dataset.json);
        distSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_afstand'))}</option>`;
        for (const a of cat.afstanden) {
            const o = document.createElement('option');
            o.value = `afstand|${a.dc_id}|${a.distance_id}`;
            o.textContent = a.distance_naam;
            distSel.appendChild(o);
        }
        const klas = cat.klassementen || [];
        for (const k of klas) {
            const o = document.createElement('option');
            o.value = `klassement|${k.dc_id}`;
            o.textContent = klas.length > 1
                ? `${t('uitsl_klassement_opt')} (${k.dc_naam})`
                : t('uitsl_klassement_opt');
            distSel.appendChild(o);
        }
        distSel.disabled = false;
        if (cat.afstanden.length === 1 && !klas.length) {
            distSel.value = `afstand|${cat.afstanden[0].dc_id}|${cat.afstanden[0].distance_id}`;
            distSel.dispatchEvent(new Event('change'));
        }
    });

    // Afstand/klassement-change → fetch + render
    distSel.addEventListener('change', async () => {
        const val = distSel.value;
        if (!val) { wrap.innerHTML = ''; return; }
        const parts = val.split('|');
        const type   = parts[0];                        // 'afstand' | 'klassement'
        const dcId   = parts[1] || '';
        const distId = type === 'afstand' ? (parts[2] || '') : '';
        if (!dcId) { wrap.innerHTML = ''; return; }

        wrap.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_laden')}</div>`;

        try {
            const url = type === 'klassement'
                ? `?action=uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&type=klassement`
                : `?action=uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&type=afstand&distance_id=${encodeURIComponent(distId)}`;
            const res = await safeFetch(url);
            const data = await res.json();
            if (data.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }

            wrap.innerHTML = (type === 'klassement') ? renderKlassementTabel(data) : renderAfstandTabel(data);
        } catch (e) {
            wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
        }
    });

    // Restore vorige keuze (na auto-refresh of na kind-wissel) — voorkomt dat
    // de gebruiker zijn categorie + afstand opnieuw moet kiezen telkens als
    // stilleRefresh() de DOM herbouwt. _bewaarKindUistate() heeft de keuze
    // net daarvoor opgeslagen op het kind-object.
    const saved = _kinderen?.[_activeKindIdx]?._uistate;
    const cats  = _catCache?.data;
    if (saved?.catVal && catSel.querySelector(`option[value="${CSS.escape(saved.catVal)}"]`)) {
        catSel.value = saved.catVal;
        catSel.dispatchEvent(new Event('change'));
        // Daarna ook afstand/klassement herstellen als die nog bestaat
        if (saved.distVal) {
            const distOpt = distSel.querySelector(`option[value="${CSS.escape(saved.distVal)}"]`);
            if (distOpt) {
                distSel.value = saved.distVal;
                distSel.dispatchEvent(new Event('change'));
            }
        }
    } else if (cats?.length === 1) {
        // Auto-selecteer als er maar 1 categorie is (ná binden listeners)
        catSel.value = cats[0].dc_id;
        catSel.dispatchEvent(new Event('change'));
    }
}

// ── Serie-klassementen voor een wedstrijd ──────────────────────────────────
async function initSerieKlassementen(kaart, compId) {
    const box      = kaart.querySelector('[data-serie-lijst]');
    const selector = kaart.querySelector('[data-serie-selector]');
    const wrap     = kaart.querySelector('.serie-klas-tabel-wrap');
    if (!box) return;

    try {
        const res = await safeFetch(`?action=series_voor_comp&competition_id=${encodeURIComponent(compId)}`);
        const series = await res.json();
        if (!Array.isArray(series) || !series.length) { box.hidden = true; return; }

        box.hidden = false;
        // Eén select met alle series + categorieën combineert netjes
        selector.innerHTML = `
            <select class="serie-sel">
                <option value="">${esc(t('serie_opt_kies'))}</option>
                ${series.map(s => `
                    <option value="${esc(s.klassement_id)}">
                        ${esc(s.naam)}${s.seizoen ? t('serie_seizoen_sep') + esc(s.seizoen) : ''}
                        (${esc(t('serie_aantal_rijders', {n: s.totaal_rijders}))})
                    </option>`).join('')}
            </select>
            <select class="serie-cat-sel" disabled><option value="">${esc(t('uitsl_opt_kies_cat'))}</option></select>`;

        const serieSel = selector.querySelector('.serie-sel');
        const catSel   = selector.querySelector('.serie-cat-sel');
        let huidig = null; // cached klassement-response

        serieSel.addEventListener('change', async () => {
            wrap.innerHTML = '';
            catSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_cat'))}</option>`;
            catSel.disabled = true;
            if (!serieSel.value) return;
            wrap.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_laden')}</div>`;
            try {
                const r = await safeFetch(`?action=serie_klassement&klassement_id=${encodeURIComponent(serieSel.value)}`);
                const data = await r.json();
                if (data.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }
                huidig = data;
                const cats = (data.categorieen ?? []).filter(Boolean);
                if (!cats.length) {
                    wrap.innerHTML = renderSerieKlassementTabel(data, null);
                    return;
                }
                catSel.innerHTML = `<option value="">${esc(t('serie_opt_alle_cats'))}</option>` +
                    cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
                catSel.disabled = false;
                // Auto-eerste categorie: als er maar 1 is, selecteer die
                if (cats.length === 1) {
                    catSel.value = cats[0];
                    catSel.dispatchEvent(new Event('change'));
                } else {
                    wrap.innerHTML = `<div class="melding">${esc(t('msg_kies_categorie_klassement'))}</div>`;
                }
            } catch (e) {
                wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
            }
        });

        catSel.addEventListener('change', () => {
            if (!huidig) return;
            wrap.innerHTML = renderSerieKlassementTabel(huidig, catSel.value || null);
        });

        // Restore serie-klassement keuze na auto-refresh / kind-wissel
        // (dezelfde reden als bij Uitslagen — anders verspringt elke 60s).
        const saved = _kinderen?.[_activeKindIdx]?._uistate;
        if (saved?.serieVal && serieSel.querySelector(`option[value="${CSS.escape(saved.serieVal)}"]`)) {
            serieSel.value = saved.serieVal;
            serieSel.dispatchEvent(new Event('change'));
            // serieSel.change is async (fetch + cat-sel populate); restore
            // de cat-keuze pas als cat-sel bevolkt is. We polleren kort —
            // veel simpeler dan de promise-chain doorvlechten.
            if (saved.serieCatVal) {
                const t0 = Date.now();
                const probeer = () => {
                    if (catSel.querySelector(`option[value="${CSS.escape(saved.serieCatVal)}"]`)) {
                        catSel.value = saved.serieCatVal;
                        catSel.dispatchEvent(new Event('change'));
                    } else if (Date.now() - t0 < 4000) {
                        setTimeout(probeer, 80);
                    }
                };
                setTimeout(probeer, 80);
            }
        }
    } catch (e) {
        box.hidden = true;
    }
}

// Render de serie-klassement-tabel (vergelijkbaar met ranking-detail in Beheer,
// maar met highlight voor de eigen rijder uit inpSnr).
function renderSerieKlassementTabel(k, cat) {
    const alle  = k.posities ?? [];
    const rijen = cat ? alle.filter(p => p.categorie === cat) : alle;
    if (!rijen.length) return `<div class="melding">${esc(t('msg_geen_posities'))}</div>`;
    const wMeta = Array.isArray(k.wedstrijden_meta) ? k.wedstrijden_meta : [];
    const toonW = wMeta.length > 0 && rijen.some(p => p.punten_detail && Object.keys(p.punten_detail).length);

    const fmtP = n => {
        if (n == null) return '–';
        const v = +n;
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    };

    // Startnummer van de actief-getoonde rijder. Na btnZoek wordt inpSnr
    // leeggemaakt, dus we lezen uit _kinderen (werkt voor 1 kind én voor de
    // multi-kind-tabs). Fallback op inpSnr voor de eerste render.
    const eigenSnr = String(
        (_kinderen?.[_activeKindIdx]?.snr) ?? inpSnr.value.trim() ?? ''
    ).trim();

    let hdr = `<tr><th class="col-rang">${t('col_rang')}</th><th class="col-snr">${t('col_snr')}</th><th>${t('col_naam')}</th>`;
    if (!cat) hdr += `<th class="col-cat">${t('col_cat')}</th>`;
    if (toonW) {
        hdr += wMeta.map((w, i) =>
            `<th class="col-w" title="${esc(w.naam)}${w.datum ? ' · ' + String(w.datum).substring(0,10) : ''}${w.is_finale ? ' · FINALE' : ''}">
                ${w.is_finale ? 'F' : '#' + (i + 1)}
            </th>`).join('');
        hdr += `<th class="col-tot">${t('col_tot')}</th>`;
    }
    hdr += '</tr>';

    // Kolomtelling voor de scheidingsrij tussen geklasseerd en niet-opgenomen.
    const colspan = 3 + (cat ? 0 : 1) + (toonW ? wMeta.length + 1 : 0);
    let scheidingGedaan = false;
    const rows = rijen.map(p => {
        // positie 0 = 'niet opgenomen in klassement' (onderblok): getoond op
        // puntenvolgorde, maar zonder rangnummer.
        const buiten = !(+p.positie > 0);
        let voor = '';
        if (buiten && !scheidingGedaan) {
            scheidingGedaan = true;
            voor = `<tr class="serie-klas-scheiding"><td colspan="${colspan}">${esc(t('klas_niet_opgenomen'))}</td></tr>`;
        }
        const isIk = eigenSnr && String(p.start_number) === String(eigenSnr);
        const detail = p.punten_detail ?? {};
        const wedstrijdCellen = toonW
            ? wMeta.map(w => {
                const v = detail[w.comp_id];
                return `<td class="col-w">${v != null ? fmtP(v) : '<span class="col-nng">–</span>'}</td>`;
              }).join('')
            : '';
        const totaalCel = toonW ? `<td class="col-tot">${fmtP(p.punten_totaal)}</td>` : '';
        return `${voor}<tr class="${isIk ? 'rij-ik' : ''}${buiten ? ' rij-buiten' : ''}">
            <td class="col-rang">${buiten ? '' : p.positie}</td>
            <td class="col-snr">${esc(p.start_number ?? '–')}</td>
            <td class="col-naam">${esc(p.naam)}</td>
            ${!cat ? `<td class="col-cat">${esc(p.categorie ?? '')}</td>` : ''}
            ${wedstrijdCellen}
            ${totaalCel}
        </tr>`;
    }).join('');

    return `<table class="uitsl-tabel serie-klas-tabel"><thead>${hdr}</thead><tbody>${rows}</tbody></table>`;
}

// JS-equivalent van backend catSortKey — jong → oud, dames → heren.
// Gebruikt om extra cat-kolommen consistent te sorteren met de dropdown.
function _catSortKey(cat) {
    const c = (cat || '').toUpperCase().trim();
    const mMasters = c.match(/^([HD]?)M(\d{2,3})$/);
    if (mMasters) {
        const g = mMasters[1] === 'D' ? 0 : 1;
        const lft = parseInt(mMasters[2], 10);
        if (lft >= 40) return (10 + Math.floor((lft - 40) / 5)) * 10 + g;
    }
    const g = c[0] === 'D' ? 0 : c[0] === 'H' ? 1 : 9;
    const sub = c.slice(1);
    const ageMap = { P4:0, P3:1, P2:2, P1:3, KA:4, JB:5, JA:6, SJ:7, SA:8, SB:9 };
    const a = ageMap[sub] ?? 99;
    return a * 10 + g;
}

// Verzamel unieke cats uit de rijders en bereken per-cat rang. Rijders met
// rang===null (uitvallers) tellen niet mee voor de cat-rang. Toont bij
// gecombineerde DC's welke plek een rijder BINNEN zijn eigen cat pakte.
function _catRanksBerekenen(rijders) {
    const cats = [];
    const catRank = new Map(); // license/idx → { cat, catRang }
    const teller = {};         // cat → running count
    rijders.forEach((r, idx) => {
        const c = r.categorie || '';
        if (!c) { catRank.set(idx, null); return; }
        if (!cats.includes(c)) cats.push(c);
        if (r.rang == null) { catRank.set(idx, null); return; }
        teller[c] = (teller[c] || 0) + 1;
        catRank.set(idx, teller[c]);
    });
    cats.sort((a, b) => _catSortKey(a) - _catSortKey(b));
    return { cats, catRank };
}

function renderAfstandTabel(data) {
    if (!data.rijders?.length) return `<div class="melding">${esc(t('msg_geen_uitslagen'))}</div>`;
    const heeftRnd = data.heeft_rondes;
    const heeftPK  = data.heeft_pk_punten;
    const pkMax    = heeftPK ? pkMaxRnd(data.rijders) : 0;
    // Per-cat kolommen bij gecombineerde DC (meer dan 1 cat in de uitslag).
    const { cats, catRank } = _catRanksBerekenen(data.rijders);
    const toonCatKol = cats.length > 1;

    let hdr = `<th class="col-rang">${t('col_rang')}</th>`;
    if (toonCatKol) for (const c of cats) hdr += `<th class="col-cat-rank" title="${esc(c)}">${esc(c)}</th>`;
    hdr += `<th class="col-snr">${t('col_snr')}</th><th class="col-naam">${t('col_naam')}</th>`;
    if (heeftRnd) hdr += `<th class="col-rnd">${t('col_rnd')}</th>`;
    if (heeftPK)  hdr += `<th class="col-pk">${t('col_pnt')}</th>`;
    hdr += `<th class="col-tijd">${t('col_tijd')}</th>`;

    let rows = '';
    data.rijders.forEach((r, idx) => {
        const sanctie = sl(r.sanctie);
        rows += `<tr>
            <td class="col-rang">${r.rang ?? '—'}</td>`;
        if (toonCatKol) {
            const cr = catRank.get(idx);
            for (const c of cats) {
                rows += `<td class="col-cat-rank">${(c === r.categorie && cr != null) ? cr : ''}</td>`;
            }
        }
        rows += `<td class="col-snr">${esc(r.snr)}</td>
            <td class="col-naam">${esc(r.full_name)}${sanctie ? ` <span class="col-sanctie">${esc(sanctie)}</span>` : ''}</td>`;
        if (heeftRnd) rows += `<td class="col-rnd">${r.rondes ?? ''}</td>`;
        if (heeftPK)  rows += `<td class="col-pk">${pkPuntCel(r, pkMax)}</td>`;
        rows += `<td class="col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : ''}</td>`;
        rows += '</tr>';
    });
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderKlassementTabel(data) {
    if (!data.rijders?.length) return `<div class="melding">${esc(t('msg_geen_klassement'))}</div>`;
    const afstanden = data.afstanden ?? [];
    const { cats, catRank } = _catRanksBerekenen(data.rijders);
    const toonCatKol = cats.length > 1;

    let hdr = `<th class="col-rang">${t('col_rang')}</th>`;
    if (toonCatKol) for (const c of cats) hdr += `<th class="col-cat-rank" title="${esc(c)}">${esc(c)}</th>`;
    hdr += `<th class="col-snr">${t('col_snr')}</th><th class="col-naam">${t('col_naam')}</th>`;
    for (const a of afstanden) {
        const kort = a.length > 6 ? a.substring(0, 5) + '.' : a;
        hdr += `<th class="col-punten" title="${esc(a)}">${esc(kort)}</th>`;
    }
    hdr += `<th class="col-totaal">${t('col_tot')}</th>`;

    let rows = '';
    data.rijders.forEach((r, idx) => {
        const detail = r.punten_detail ?? {};
        rows += `<tr>
            <td class="col-rang">${r.rang ?? '—'}</td>`;
        if (toonCatKol) {
            const cr = catRank.get(idx);
            for (const c of cats) {
                rows += `<td class="col-cat-rank">${(c === r.categorie && cr != null) ? cr : ''}</td>`;
            }
        }
        rows += `<td class="col-snr">${esc(r.snr)}</td>
            <td class="col-naam">${esc(r.full_name)}</td>`;
        for (const a of afstanden) {
            const p = detail[a];
            rows += `<td class="col-punten">${p != null ? parseFloat(p) : '—'}</td>`;
        }
        rows += `<td class="col-totaal">${r.punten_totaal != null ? parseFloat(r.punten_totaal) : '—'}</td>`;
        rows += '</tr>';
    });
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

// ── Help overlay ──────────────────────────────────────────────────────────
// ── Footer: org logo + sponsor-ticker ─────────────────────────────────────

// 🥚 Easter egg: 3× snel klikken op org-logo opent blobart.devriesen.com
// EN toont een succesmelding met "je bent de N-e die 'm gevonden heeft".
// Dedup: per-browser localStorage-token — dezelfde browser telt maar 1x,
// ook al klikt-ie 100x. Server geeft `positie` terug (volgnummer van
// deze browser), niet het totaal aantal hits.
// Counter + timer op module-niveau zodat ze tussen renderings behouden blijven.
let _eggCount = 0;
let _eggTimer = null;
function _eggGetToken() {
    let t = localStorage.getItem('egg-token');
    if (!t) {
        // crypto.randomUUID vereist HTTPS/secure context; fallback voor lokaal.
        t = (crypto.randomUUID && crypto.randomUUID()) ||
            ('xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                const r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            }));
        localStorage.setItem('egg-token', t);
    }
    return t;
}
function _eggHandler() {
    _eggCount++;
    clearTimeout(_eggTimer);
    if (_eggCount >= 3) {
        _eggCount = 0;
        // Token meesturen zodat server dedupt op browser-niveau. Faalt de
        // call, tonen we nog steeds een generieke felicitatie.
        const body = new URLSearchParams({ token: _eggGetToken() });
        fetch('../api/easter_egg.php', { method: 'POST', body })
            .then(r => r.ok ? r.json() : null)
            .then(j => _eggToonMelding(j?.positie ?? null))
            .catch(() => _eggToonMelding(null));
        window.open('https://blobart.devriesen.com', '_blank', 'noopener');
    } else {
        _eggTimer = setTimeout(() => { _eggCount = 0; }, 2000);
    }
}
// TODO 100ste: bij positie === 100 modaal met e-mail-input tonen zodat de
// vinder een prijsje kan krijgen. Nu alleen gewone felicitatie.
function _eggToonMelding(positie) {
    const nr = Number.isInteger(positie) && positie > 0 ? positie : null;
    const regel = nr
        ? `Je bent de <strong>${nr}<sup>e</sup></strong> die 'm gevonden heeft! 🎉`
        : `Leuk dat je 'm gevonden hebt! 🎉`;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9600;'
        + 'display:flex;align-items:center;justify-content:center;padding:1rem;';
    overlay.innerHTML = `
        <div style="background:#fff8e1;border:3px solid #f9a825;border-radius:10px;
                    max-width:360px;width:100%;padding:1.4rem 1.5rem;text-align:center;
                    box-shadow:0 10px 40px rgba(0,0,0,.4);animation:meldingPop .3s ease-out;">
            <div style="font-size:2.4rem;margin-bottom:.4rem;">🥚</div>
            <h2 style="margin:0 0 .5rem;color:#f57f17;font-size:1.15rem;">
                Easter egg gevonden!
            </h2>
            <p style="margin:0 0 1.1rem;color:#333;line-height:1.5;font-size:.95rem;">
                ${regel}
            </p>
            <button class="egg-ok" style="background:#f9a825;color:#fff;border:none;
                    padding:.55rem 1.4rem;border-radius:6px;font-size:1rem;
                    font-weight:600;cursor:pointer;width:100%;">
                Leuk!
            </button>
        </div>`;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    overlay.querySelector('.egg-ok').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
}

// Logo dat — bij height 50px — breder zou worden dan deze ratio, past niet
// netjes in de vaste footer-positie en duwt de sponsor-marquee weg. We
// verplaatsen 'm dan naar de marquee zodat 'ie wel op volle hoogte leesbaar
// blijft (lichtkrant rolt 'm langs).
const _FOOTER_LOGO_MAX_RATIO = 150 / 50;   // = 3.0 : 1
                                           // (incl. Warmondse 250×71 ≈ 3.52
                                           // → naar marquee i.p.v. footer)

// Async helper — preload image, lever ratio terug. Bij fout: 1 (= niet te breed).
function _logoRatio(src) {
    return new Promise(resolve => {
        const img = new Image();
        img.onload  = () => resolve(img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1);
        img.onerror = () => resolve(1);
        img.src = src;
    });
}

async function updateHeaderLogos(opt) {
    const footer   = document.getElementById('org-footer');
    const logoEl   = document.getElementById('footer-org-logo');
    const naamEl   = document.getElementById('footer-org-naam');
    const sponsEl  = document.getElementById('footer-sponsors');
    const baanEl   = document.getElementById('footer-baan-logo');

    if (!opt?.value) {
        footer.style.display = 'none';
        return;
    }

    const orgLogo   = opt.dataset.orgLogo;
    const orgNaam   = opt.dataset.orgNaam ?? '';
    const baanLogo  = opt.dataset.baanLogo ?? '';
    const baanVer   = opt.dataset.baanVereniging ?? '';
    const sponsors  = JSON.parse(opt.dataset.sponsors || '[]');

    // Niets te tonen? Footer verbergen (incl. check op baan-logo).
    if (!orgLogo && !sponsors.length && !baanLogo && !baanVer) {
        footer.style.display = 'none';
        return;
    }

    // Cache-buster zodat een vers geüpload logo niet uit de browser-cache blijft.
    // Gebruikt het huidige uur als bust-waarde: stabiel genoeg voor normale navigatie
    // maar een upload is uiterlijk binnen het uur zichtbaar.
    const cb = `?v=${Math.floor(Date.now() / 3600000)}`;

    // Te brede logo's (bv. landscape-vereniging-logo) passen niet in de
    // vaste footer-positie. We preloaden ze, meten de aspect-ratio en
    // verhuizen ze naar de marquee als ze te breed zijn — dan hebben ze
    // wel ruimte om op volle hoogte langs te rollen.
    const orgRatio  = orgLogo  ? await _logoRatio(`../${esc(orgLogo)}${cb}`)  : 0;
    const baanRatio = baanLogo ? await _logoRatio(`../${esc(baanLogo)}${cb}`) : 0;
    const orgInFooter  = orgLogo  && orgRatio  <= _FOOTER_LOGO_MAX_RATIO;
    const baanInFooter = baanLogo && baanRatio <= _FOOTER_LOGO_MAX_RATIO;

    // Organisatie-logo links in footer. Bij te-breed logo: vaste positie
    // leeg laten — logo gaat naar marquee.
    logoEl.innerHTML = orgInFooter ? `<img class="org-footer-logo" src="../${esc(orgLogo)}${cb}" alt="">` : '';
    // Naam-fallback ALLEEN als er helemaal geen logo is (niet als 't te breed
    // is — dan rolt het logo zelf langs in de marquee, naam-tekst overbodig).
    naamEl.textContent = !orgLogo ? orgNaam : '';
    // Easter egg: 3× klikken op het org-logo binnen 2 sec opent blobart.
    // Geheim — cursor blijft default zodat het niet verraadt klikbaar te zijn.
    const _eggImg = logoEl.querySelector('img');
    if (_eggImg) _eggImg.addEventListener('click', _eggHandler);

    // Gastheer-vereniging rechts in footer. Bij te-breed logo: vaste
    // positie leeg, logo naar marquee. Naam-fallback alleen bij ECHT
    // geen logo (geen visuele verdubbeling met de marquee-versie).
    if (baanInFooter) {
        baanEl.innerHTML = `<img class="org-footer-logo" src="../${esc(baanLogo)}${cb}" alt="">`;
    } else if (baanVer && !baanLogo) {
        baanEl.innerHTML = `<span class="org-footer-naam">${esc(baanVer)}</span>`;
    } else {
        baanEl.innerHTML = '';
    }
    // Lege wrapper inklappen zodat de marquee de hele rechter-ruimte vult
    // (anders blijft 't <div> leeg layout-ruimte innemen ondanks empty html).
    baanEl.style.display = baanEl.innerHTML ? '' : 'none';

    // Sponsors (lichtkrant-ticker) + eventueel te-brede org/baan-logo's.
    // Altijd marquee, ook bij 1 item — anders 'hangt' de enige logo
    // statisch. Min-duur 8s zodat 1 logo niet onhandig snel langs schiet.
    let imgs = '';
    const _liggendImg = (src, naam) =>
        `<img src="../${esc(src)}${cb}" alt="${esc(naam)}" title="${esc(naam)}" style="height:50px;width:auto;object-fit:contain">`;
    if (orgLogo  && !orgInFooter)  imgs += _liggendImg(orgLogo,  orgNaam);
    if (baanLogo && !baanInFooter) imgs += _liggendImg(baanLogo, baanVer || '');
    for (const s of sponsors) {
        const img = _liggendImg(s.logo, s.naam);
        imgs += s.url ? `<a href="${esc(s.url)}" target="_blank" rel="noopener">${img}</a>` : img;
    }
    if (imgs) {
        const aantal = sponsors.length
                     + (orgLogo  && !orgInFooter  ? 1 : 0)
                     + (baanLogo && !baanInFooter ? 1 : 0);
        const duur = Math.max(8, aantal * 3);
        sponsEl.innerHTML = `<div class="sponsor-marquee"><div class="sponsor-marquee-inner" style="animation-duration:${duur}s">${imgs}${imgs}</div></div>`;
    } else {
        sponsEl.innerHTML = '';
    }

    footer.style.display = 'block';
}

function toonInfo() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>${esc(t('info_titel'))}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">
            <h3>${esc(t('info_h1'))}</h3>
            <p>${esc(t('info_p1'))}</p>
            <p>${t('info_p2_html')}</p>

            <h3>${esc(t('info_h2'))}</h3>
            <p>${esc(t('info_p3'))}</p>

            <h3>${t('info_h3_html')}</h3>
            <p>${esc(t('info_p4'))}</p>
            <p class="info-cta-wrap">
                <a class="info-cta" style="--cta-bg:var(--oranje)" href="mailto:inlinecomp@devriesen.com">inlinecomp@devriesen.com</a>
            </p>

            <h3>${esc(t('info_h4'))}</h3>
            <p style="font-size:.85rem;color:#555">${t('info_p5_html')}</p>

            <h3>${t('info_h5_html')}</h3>
            <p>${esc(t('info_p6'))}</p>
            <p class="info-cta-wrap">
                <a class="info-cta" style="--cta-bg:var(--blauw,#1a3a5c)" href="../privacyverklaring.php">${esc(t('info_btn_privacy'))}</a>
            </p>

            <p style="font-size:.8rem;color:#999;text-align:center;margin-top:16px">${t('info_copyright', {jaar: new Date().getFullYear()})}</p>
            <p style="font-size:.75rem;color:#aaa;text-align:center;margin-top:4px">
                ${esc(t('info_versie'))} <strong>${esc(APP_VERSIE)}</strong>
            </p>
        </div>
    </div>`;
    document.body.appendChild(overlay);
}

function toonHelp() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>${esc(t('help_titel'))}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">

            <button type="button" class="btn-nieuw-jump"
                    onclick="this.closest('.help-body').querySelector('#wat-is-nieuw').scrollIntoView({behavior:'smooth',block:'start'})">
                ✨ ${esc(t('nieuw_jump'))}
            </button>

            <h3>${esc(t('help_h1'))}</h3>
            <div class="help-stap">
                <span class="help-stap-nr">1</span>
                <span>${t('help_stap1_html')}</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">2</span>
                <span>${t('help_stap2_html')}</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">3</span>
                <span>${t('help_stap3_html')}</span>
            </div>

            <!-- Mockup: zoekscherm — toont actuele filter-chips + 3-knoppen-rij -->
            <div class="mock">
                <div class="mock-hdr">InlineComp – Public</div>
                <div class="mock-body">
                    <div class="mock-stap">
                        <span class="mock-stap-nr">1</span>
                        ${esc(t('help_mock_kies_w'))}
                    </div>
                    <div class="mock-chip-rij">
                        <span class="mock-chip">${esc(t('filter_eerder'))}</span>
                        <span class="mock-chip mock-chip--active">${esc(t('filter_vandaag'))}</span>
                        <span class="mock-chip">${esc(t('filter_later'))}</span>
                    </div>
                    <div class="mock-select">${esc(t('help_mock_voorbeeld'))}</div>
                    <div class="mock-stap mock-stap--mt">
                        <span class="mock-stap-nr">2</span>
                        ${esc(t('help_mock_snr_lic'))}
                    </div>
                    <div class="mock-select">${esc(t('help_mock_snr'))}</div>
                    <div class="mock-zoek-btn">${esc(t('btn_zoeken'))}</div>
                </div>
            </div>

            <h3>${esc(t('help_h_tabs'))}</h3>
            <p>${t('help_p_tabs_html')}</p>

            <p>${t('help_p_prog_html')}</p>

            <!-- Mockup: programma (filter-strook + segment-control Inklappen / Uitklappen / Mijn) -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab active">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-filter-strook">
                    <div class="mock-filter-strook-rij">
                        <span>🏁</span><span class="flex-1">${esc(t('prog_filter_alle_afstanden'))}</span><span class="mock-chev">▼</span>
                    </div>
                </div>
                <div class="mock-seg-rij">
                    <span class="mock-seg mock-seg--active">▶ ${esc(t('prog_klap_alles_uit'))}</span>
                    <span class="mock-seg">▼ ${esc(t('prog_klap_alles_in'))}</span>
                    <span class="mock-seg">👤 ${esc(t('prog_klap_mijn'))}</span>
                </div>
                <div class="mock-body mock-body--p4">
                    <div class="mock-row"><span class="mock-nr-dim">1</span> <span class="mock-naam">500m ${esc(t('ronde_serie'))} Heat 1</span> <span class="mock-badge-serie">${esc(t('ronde_serie'))}</span></div>
                    <div class="mock-row mock-hl"><span class="mock-nr-dim">2</span> <span class="mock-naam">500m ${esc(t('ronde_serie'))} Heat 2</span> <span class="mock-badge-serie">${esc(t('ronde_serie'))}</span></div>
                    <div class="mock-row"><span class="mock-nr-dim">3</span> <span class="mock-naam">500m A-${esc(t('ronde_finale'))}</span> <span class="mock-badge-finale">${esc(t('ronde_finale'))}</span></div>
                </div>
            </div>

            <p>${t('help_p_heats_html')}</p>

            <!-- Mockup: heat -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-heat-hdr">
                    <span class="mock-heat-hdr-tag">${esc(t('ronde_finale'))}</span> 500m A-${esc(t('ronde_finale'))}
                </div>
                <div class="mock-body mock-body--p4">
                    <div class="mock-row mock-cols-hdr"><span class="mock-c18">${esc(t('col_pos'))}</span><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-tijd">${esc(t('col_tijd'))}</span><span class="mock-c20c">${esc(t('col_fin'))}</span></div>
                    <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-tijd">45.30</span><span class="mock-c20c mock-v">2</span></div>
                    <div class="mock-row mock-hl"><span class="mock-rang">2</span><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-tijd">45.12</span><span class="mock-c20c mock-v mock-cB">1</span></div>
                    <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-tijd">46.01</span><span class="mock-c20c mock-v">3</span></div>
                </div>
            </div>

            <p>${t('help_p_res_html')}</p>

            <!-- Mockup: rondes-tab (per-ronde uitslag + doorstroom Q→A / q→B) -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-body mock-body--p6">
                    <div class="mock-afstand">100 meter</div>

                    <div class="mock-badge-block mock-badge-block--serie">${esc(t('ronde_serie'))}</div>
                    <div class="mock-row mock-cols-hdr"><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-c36c">Kwal</span><span class="mock-tijd">${esc(t('col_tijd'))}</span></div>
                    <div class="mock-row"><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-c36c mock-v7 mock-cG">Q→A</span><span class="mock-tijd">10.42</span></div>
                    <div class="mock-row mock-hl"><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-c36c mock-v7 mock-cG">Q→A</span><span class="mock-tijd">10.58</span></div>
                    <div class="mock-row"><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-c36c mock-v7 mock-cBl">q→B</span><span class="mock-tijd">10.71</span></div>

                    <div class="mock-badge-block mock-badge-block--finale">${esc(t('ronde_finale'))} A</div>
                    <div class="mock-row mock-cols-hdr"><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-tijd">${esc(t('col_tijd'))}</span><span class="mock-c20c">${esc(t('col_fin'))}</span></div>
                    <div class="mock-row mock-hl"><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-tijd">10.35</span><span class="mock-c20c mock-v7 mock-cB">1</span></div>
                    <div class="mock-row"><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-tijd">10.41</span><span class="mock-c20c mock-v">2</span></div>
                </div>
            </div>

            <p>${t('help_p_uitsl_html')}</p>

            <!-- Mockup: uitslagen -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_rondes').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-body mock-body--p6">
                    <div class="mock-select">DJB/A + HJB/A</div>
                    <div class="mock-select">${esc(t('uitsl_klassement_opt').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-mt6">
                        <div class="mock-row mock-uitsl-hdr"><span class="mock-c18">${esc(t('col_rang'))}</span><span class="mock-c24">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-c30c">Spr</span><span class="mock-c30c">L.A.</span><span class="mock-c30c mock-cO">${esc(t('col_tot'))}</span></div>
                        <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-c30c">4</span><span class="mock-c30c">1</span><span class="mock-c30c mock-v7 mock-cO">8</span></div>
                        <div class="mock-row"><span class="mock-rang">2</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-c30c">5</span><span class="mock-c30c">3</span><span class="mock-c30c mock-v7 mock-cO">11</span></div>
                        <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-c30c">5</span><span class="mock-c30c">6</span><span class="mock-c30c mock-v7 mock-cO">12</span></div>
                    </div>
                </div>
            </div>

            <h3>${esc(t('help_h_auto'))}</h3>
            <p>${t('help_p_auto_html')}</p>

            <h3>${esc(t('help_h_meld'))}</h3>
            <p>${t('help_p_meld_html')}</p>

            <h3>${esc(t('help_h_push'))}</h3>
            <p>${t('help_p_push_html')}</p>

            <!-- Mockup: push-meldingen-blok (🔔 + 3 losse type-toggles) -->
            <div class="mock">
                <div class="mock-hdr">🔔 ${esc(t('push_titel'))}</div>
                <div class="mock-body">
                    <div class="mock-push-align"><span class="mock-push-pill">${esc(t('push_aan'))}</span></div>
                    <div class="mock-row mock-row--flat"><span>☑</span><span class="mock-naam">🚩 ${esc(t('push_loting'))}</span></div>
                    <div class="mock-row mock-row--flat"><span>☑</span><span class="mock-naam">🏁 ${esc(t('push_uitslag'))}</span></div>
                    <div class="mock-row mock-row--flat"><span>☑</span><span class="mock-naam">📢 ${esc(t('push_bericht'))}</span></div>
                </div>
            </div>

            <h3>${esc(t('help_h_tip'))}</h3>
            <p>${esc(t('help_p_tip'))}</p>

            <!-- ── Wat is nieuw (changelog per versie) ── -->
            <h3 id="wat-is-nieuw" class="nieuw-titel">
                ✨ ${esc(t('nieuw_h'))}
            </h3>
            <p class="nieuw-intro">${esc(t('nieuw_intro'))}</p>

            ${renderChangelog(CHANGELOG)}

        </div>
    </div>`;
    document.body.appendChild(overlay);
}

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

// ── Auto-refresh ──────────────────────────────────────────────────────────
// Stille refresh van programma + lookup voor alle actieve kinderen, elke
// minuut. Stopt als het tabblad onzichtbaar wordt en hervat zodra het weer
// actief is. Toont een tijdstempel "🔄 HH:MM" naast de wedstrijd-keuze
// zodat duidelijk is wanneer de data voor het laatst is bijgewerkt.
//
// Patroon overgenomen uit coach/index.php (regel 2319-2353) — zelfde gedrag,
// zelfde tab-aware optimalisatie.
// Globale stempel-tekst — gebruikt door zowel de persoon-card-template
// (die bij elke render z'n eigen .auto-stempel-span maakt) als de bestaande
// stempel-spans in de DOM. Update via zetStempel().
let _huidigStempel = '';

(function() {
    // 3 minuten — frequente publish/loting-updates komen toch via meldingen-
    // push naar de gebruiker; de poll dient als vangnet voor "ik kijk al een
    // tijdje". Lagere frequentie scheelt aanzienlijk in serverbelasting bij
    // grote wedstrijden waar tientallen toestellen actief zijn.
    const AUTO_REFRESH_MS = 180_000;
    let autoTick = null;

    const zetStempel = () => {
        const d = new Date();
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        // Twee aparte spans: in stap-1-label staan ze inline naast elkaar,
        // in de persoon-card stapelen ze verticaal (icoon boven, tijd onder)
        // — verschil zit in de CSS-context.
        _huidigStempel = `<span class="aut-icon">🔄</span> <span class="aut-tijd">${hh}:${mm}</span>`;
        document.querySelectorAll('.auto-stempel').forEach(el => {
            el.innerHTML = _huidigStempel;
        });
    };

    const wisStempel = () => {
        _huidigStempel = '';
        document.querySelectorAll('.auto-stempel').forEach(el => { el.innerHTML = ''; });
    };

    // Stille refresh: alle kinderen + gedeeld programma in één parallel-batch
    // (geen loader-flash, geen UI-tussenstaten). Bij faal: stempel niet
    // bijwerken — gebruiker ziet dat de tijd "blijft staan".
    const stilleRefresh = async () => {
        // Scroll-positie bewaren: renderKinderen() vervangt divResult.innerHTML
        // en dat zet scroll naar 0. Zonder deze save/restore springt de pagina
        // elke 60s naar boven — hinderlijk als je aan het lezen bent.
        const _scrollY = window.scrollY || window.pageYOffset || 0;
        const compId = selComp.value;
        // Categorieën-cache wissen: klassement_beschikbaar-vlag verandert
        // bij publish/intrek; zonder reset zou dropdown stale blijven tot
        // hard refresh.
        _catCache = null;

        // Mededelingen-check loopt onafhankelijk van of er een wedstrijd is
        // gekozen of kinderen zijn toegevoegd. Zonder compId krijgen we de
        // globale meldingen; mét compId per-wedstrijd + globaal samen.
        checkMeldingen(compId);

        if (!compId || !_kinderen.length) return;
        try {
            // Géén `_t=` cache-buster: server stuurt correcte Cache-Control +
            // ETag + Last-Modified, browser doet automatische revalidatie en
            // krijgt 304 als er niks veranderd is. Een cache-buster zou de
            // URL uniek maken en de If-None-Match-round-trip juist stukmaken.
            const progRes = await safeFetch(
                `?action=programma&competition_id=${encodeURIComponent(compId)}`
            );
            // Een 304-response geeft een lege body — dan hergebruiken we de
            // vorige programma-data die de browser al in cache heeft (fetch
            // API doet dit transparant voor ons: bij 304 levert response.json()
            // gewoon de eerder-gecachte body). Dus geen speciale afhandeling.
            const prog = await progRes.json();
            // Per kind een lookup; parallel uitvoeren voor lagere latency.
            // BELANGRIJK: lookup op license_key wanneer beschikbaar, NIET op
            // startnummer — anders kan bij dubbele snrs (bv. een HP1 en DP1
            // met hetzelfde nummer) de array-volgorde tussen calls wisselen
            // en springt het scherm naar de andere persoon na een refresh.
            // Fallback-keten:
            //   1. license_key       → 1 hit, geen verwarring mogelijk
            //   2. startnummer + re-locate op license   (rijder kreeg later licentie?)
            //   3. startnummer + re-locate op categorie  (geen licentie, maar
            //                                              snr+cat is uniek
            //                                              binnen een wedstrijd)
            //   4. clamp kozen_idx als allerlaatste vangnet
            const kindRefreshes = _kinderen.map(async k => {
                const eerderePersoon = k?.data?.[k.kozen_idx ?? 0]?.persoon;
                const eerderLic = eerderePersoon?.license_key;
                const eerderCat = eerderePersoon?.category;
                const param = eerderLic
                    ? `license_key=${encodeURIComponent(eerderLic)}`
                    : (k?.snr ? `startnummer=${encodeURIComponent(k.snr)}` : null);
                if (!param) return;
                try {
                    const r = await safeFetch(
                        `?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}&_t=${Date.now()}`
                    );
                    const data = await r.json();
                    if (Array.isArray(data) && data.length) {
                        k.data = data;
                        k.prog = prog;
                        if (eerderLic) {
                            // License-lookup → altijd 1 hit, idx blijft 0
                            k.kozen_idx = 0;
                        } else if (data.length > 1) {
                            // Snr-fallback met meerdere hits: re-locate.
                            // Eerst op license (rijder kan tussentijds een
                            // license toegekend hebben gekregen), dan op cat.
                            let opnieuw = -1;
                            if (eerderLic) {
                                opnieuw = data.findIndex(d => d?.persoon?.license_key === eerderLic);
                            }
                            if (opnieuw < 0 && eerderCat) {
                                opnieuw = data.findIndex(d => d?.persoon?.category === eerderCat);
                            }
                            k.kozen_idx = opnieuw >= 0 ? opnieuw : Math.min(k.kozen_idx ?? 0, data.length - 1);
                        } else {
                            k.kozen_idx = 0;
                        }
                    }
                } catch { /* stil — volgende tick probeert opnieuw */ }
            });
            await Promise.all(kindRefreshes);
            // Globale state synchroniseren met actieve kind
            const actief = _kinderen[_activeKindIdx];
            if (actief) {
                window._lookupData = actief.data;
                window._lookupSnr  = actief.snr;
                window._lookupProg = actief.prog;
                window._gekozenIdx = actief.kozen_idx ?? 0;
            }
            // Bewaar dropdown-keuzes (Uitslagen-tab) van actieve kind vóór
            // re-render — initUitslagenTab() zet ze daarna terug zodat de
            // gebruiker zijn categorie/afstand niet kwijtraakt elke 60s.
            _bewaarKindUistate();
            // Idem voor de programma-tab UI-state (filter, klap-balk,
            // handmatig open/dicht) — renderKinderen() reset die anders.
            const _progUiState = _snapshotProgUiStatePub();
            renderKinderen();
            _restoreProgUiStatePub(_progUiState);
            // Scroll herstellen: renderKinderen() vervangt innerHTML, en
            // sommige sub-tabs (Rondes/Uitslagen) laden hun content pas
            // async in. Tussen leeg-DOM en volledig-gerenderd is body korter,
            // waarna de browser scroll naar 0 clampt. Daarom herstellen we
            // meerdere keren over ~800ms zodat de restore ook grijpt als de
            // async fetch klaar is. Als de gebruiker tussentijds zelf
            // scrolt springt hij één keer terug — kleine quirk, maar veel
            // beter dan altijd naar 0.
            const restoreScroll = () => window.scrollTo(0, _scrollY);
            requestAnimationFrame(restoreScroll);
            setTimeout(restoreScroll, 100);
            setTimeout(restoreScroll, 300);
            setTimeout(restoreScroll, 800);
            zetStempel();
        } catch { /* stil */ }
    };

    // Bereken interval op basis van consecutiveFails: bij fouten progressief
    // langer wachten zodat we de server niet hameren als hij eruit ligt.
    // 0 fouten → 60s, 1 → 60s, 2 → 90s, 3+ → 120s. Bij herstel meteen terug
    // naar 60s (gebeurt automatisch want consecutiveFails reset op succes).
    const _tickInterval = () => {
        const f = _conn.consecutiveFails;
        if (f >= 3) return Math.max(AUTO_REFRESH_MS, 120_000);
        if (f === 2) return Math.max(AUTO_REFRESH_MS, 90_000);
        return AUTO_REFRESH_MS;
    };

    const _scheduleTick = () => {
        stop();
        if (!selComp.value || document.hidden) return;
        autoTick = setTimeout(async () => {
            autoTick = null;
            if (document.hidden || !selComp.value) return _scheduleTick();
            await stilleRefresh();
            _scheduleTick();
        }, _tickInterval());
    };

    const start = () => _scheduleTick();

    const stop = () => {
        if (autoTick) { clearTimeout(autoTick); autoTick = null; }
    };

    // Hook voor _conn: bij online-event direct refresh + scheduling resetten.
    _conn.refreshHook = () => {
        if (selComp.value && !document.hidden) {
            stilleRefresh().finally(_scheduleTick);
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else {
            // Eén-shot check (ook globale meldingen) bij terugkeer naar tab.
            stilleRefresh();
            start();
        }
    });

    selComp.addEventListener('change', () => {
        if (selComp.value) {
            zetStempel();
            start();
            // Direct meldingen ophalen — niet wachten op eerste poll-tick.
            checkMeldingen(selComp.value);
        } else {
            stop();
            wisStempel();
            // Switch terug naar landing → één-malig globale meldingen ophalen.
            checkMeldingen('');
        }
    });

    // Initieel: stempel + auto-tick alleen als wedstrijd voorgeselecteerd.
    // Globale meldingen-check loopt sowieso bij page-open.
    if (selComp.value) { zetStempel(); start(); }
    checkMeldingen(selComp.value || '');

    // ── Pull-to-refresh ────────────────────────────────────────────────────
    // Patroon overgenomen uit coach/index.php: alleen actief boven aan de
    // pagina, met 70 px slepen-drempel. Wikkelt stilleRefresh + zetStempel
    // in een ptrEl-status-flow.
    const ptrEl = document.getElementById('ptr');
    const PTR_DREMPEL = 70;
    const PTR_COOLDOWN_MS = 30_000;  // min tijd tussen 2 PTR-acties
    let ptrStartY = null, ptrDragY = 0, ptrActief = false, ptrBezig = false;
    let ptrLaatste = 0;

    async function ptrHerlaad() {
        if (!selComp.value || ptrBezig) return;
        // Cooldown: bij PTR < 30s na vorige tonen we kort een melding ipv
        // de server opnieuw aanroepen. Voorkomt burst bij ongeduld of
        // per-ongeluk-twee-keer-pullen.
        const sindsLaatste = Date.now() - ptrLaatste;
        if (ptrLaatste && sindsLaatste < PTR_COOLDOWN_MS) {
            const wachten = Math.ceil((PTR_COOLDOWN_MS - sindsLaatste) / 1000);
            ptrEl.classList.add('laadt');
            ptrEl.textContent = t('ptr_wachten', {s: wachten});
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 1200);
            return;
        }
        ptrBezig = true;
        ptrEl.classList.add('laadt');
        ptrEl.textContent = t('ptr_vernieuwen');
        try {
            await stilleRefresh();
            zetStempel();
            ptrLaatste = Date.now();
            ptrEl.textContent = t('ptr_bijgewerkt');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 600);
        } catch {
            ptrEl.textContent = t('ptr_fout');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 1200);
        } finally {
            ptrBezig = false;
        }
    }

    // Blokkeer PTR als de veeg begint in een overlay/modal (position:fixed) of in
    // een eigen scroll-gebied — dan hoort de gesture bij dát element, niet bij de
    // pagina. Generiek: dekt álle (ook toekomstige) modals zonder ze op te sommen.
    function _ptrGeblokkeerd(el) {
        for (let n = el; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
            const s = getComputedStyle(n);
            if (s.position === 'fixed') return true;
            if ((s.overflowY === 'auto' || s.overflowY === 'scroll') && n.scrollHeight > n.clientHeight) return true;
        }
        return false;
    }

    document.addEventListener('touchstart', e => {
        if (window.scrollY > 0 || ptrBezig || !selComp.value) { ptrStartY = null; return; }
        if (e.touches.length !== 1) { ptrStartY = null; return; }
        if (_ptrGeblokkeerd(e.target)) { ptrStartY = null; return; }
        ptrStartY = e.touches[0].clientY;
        ptrDragY = 0;
        ptrActief = false;
    }, { passive: true });

    document.addEventListener('touchmove', e => {
        if (ptrStartY === null) return;
        ptrDragY = e.touches[0].clientY - ptrStartY;
        if (ptrDragY <= 0) {
            if (ptrActief) { ptrEl.classList.remove('zichtbaar'); ptrActief = false; }
            return;
        }
        // Actief naar beneden trekken vanaf scroll-top: blokkeer native
        // browser-PTR door preventDefault() (vereist passive:false).
        if (e.cancelable) e.preventDefault();
        if (ptrDragY > 30 && !ptrActief) { ptrEl.classList.add('zichtbaar'); ptrActief = true; }
        ptrEl.textContent = ptrDragY >= PTR_DREMPEL
            ? t('ptr_laat_los') : t('ptr_trek');
    }, { passive: false });

    document.addEventListener('touchend', () => {
        if (ptrStartY === null) return;
        const was = ptrDragY;
        ptrStartY = null; ptrDragY = 0;
        if (ptrActief && was >= PTR_DREMPEL) {
            ptrHerlaad();
        } else if (ptrActief) {
            ptrEl.classList.remove('zichtbaar'); ptrActief = false;
        }
    });

    // Desktop-fallback: dubbelklik op de header refreshed ook
    document.querySelector('header')?.addEventListener('dblclick', ptrHerlaad);
})();

// ── Web Push (Fase 3): meldingen voor je gevolgde rijders ─────────────────
// Open voor iedereen (beta-gate verwijderd bij uitrol zomer 2026). Het blok
// verschijnt zodra je minstens één rijder volgt. De gevolgde licenties (uit
// localStorage) worden meegestuurd; loting/uitslag/mededeling zijn apart aan/uit
// te zetten. Teksten staan in het centrale T-woordenboek (push_*), via t().
let _ppBusy = false;
const _ppSupported = () =>
    ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);

function _ppB64ToUint8(base64) {
    const pad = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
}
const _ppLics    = () => _loadKidsUitStorage().map(k => k.license_key).filter(Boolean);
const _ppPref    = type => localStorage.getItem('ic_pub_push_' + type) !== '0';   // default aan
const _ppSetPref = (type, aan) => localStorage.setItem('ic_pub_push_' + type, aan ? '1' : '0');

async function _ppHuidigAbo() {
    if (!_ppSupported()) return null;
    const reg = await navigator.serviceWorker.ready;
    return await reg.pushManager.getSubscription();
}

// POST subscribe met huidige licenties + voorkeuren (ook voor re-sync).
async function _ppPostSubscribe(sub) {
    const body = Object.assign({}, sub.toJSON(), {
        scope: 'public',
        lang: (typeof getCurLang === 'function') ? getCurLang() : 'nl',
        licenses: _ppLics(),
        notif_loting:  _ppPref('loting')  ? 1 : 0,
        notif_uitslag: _ppPref('uitslag') ? 1 : 0,
        notif_bericht: _ppPref('bericht') ? 1 : 0,
    });
    return fetch('../api/push_subscribe.php?action=subscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    }).then(r => r.json()).catch(() => ({}));
}

async function _ppAan() {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') return 'geweigerd';
    const reg = await navigator.serviceWorker.ready;
    const kr = await fetch('../api/push_pubkey.php').then(r => r.json()).catch(() => ({}));
    if (!kr.publicKey) return 'fout';
    let sub;
    try {
        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true, applicationServerKey: _ppB64ToUint8(kr.publicKey),
        });
    } catch (e) { return 'fout'; }
    const r = await _ppPostSubscribe(sub);
    if (r && r.ok) { localStorage.setItem('ic_pub_push_optin', '1'); return true; }
    return 'fout';
}

async function _ppUit() {
    localStorage.setItem('ic_pub_push_optin', '0');   // bewust uit → niet auto-herstellen
    const sub = await _ppHuidigAbo();
    if (sub) {
        await fetch('../api/push_subscribe.php?action=unsubscribe', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope: 'public', endpoint: sub.endpoint }),
        }).catch(() => {});
        await sub.unsubscribe().catch(() => {});
    }
}

// Zelfherstel: had de gebruiker 'm aan (opt-in), toestemming nog granted, maar
// abonnement door OS/browser gedropt → stil opnieuw abonneren (geen prompt).
async function _ppHeal() {
    if (localStorage.getItem('ic_pub_push_optin') !== '1') return false;
    if (!_ppSupported() || Notification.permission !== 'granted') return false;
    if (await _ppHuidigAbo()) return false;
    return (await _ppAan()) === true;
}

// Re-sync licenties/voorkeuren naar de server ALS er een abonnement is. Wordt
// aangeroepen zodra je gevolgde rijders wijzigen (add/remove) of een voorkeur.
async function _ppSync() {
    try {
        const sub = await _ppHuidigAbo();
        if (sub && Notification.permission === 'granted') await _ppPostSubscribe(sub);
    } catch (e) {}
}

async function _ppRender() {
    const el = document.getElementById('pub-push');
    if (!el) return;
    // Alleen tonen zodra je minstens één rijder volgt.
    if (!_ppLics().length) { el.innerHTML = ''; return; }
    if (!_ppSupported()) {
        const iOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
        el.innerHTML = `<div class="pub-push-titel">🔔 ${t('push_titel')}</div>
            <div class="pub-push-msg">${t('push_niet')}${iOS ? ' ' + t('push_ios') : ''}</div>`;
        return;
    }
    await _ppHeal();   // door OS gedropt abonnement stil terugzetten
    const sub = await _ppHuidigAbo();
    const aan = !!sub && Notification.permission === 'granted';
    el.innerHTML = `
        <div class="pub-push-kop">
            <span class="pub-push-titel">🔔 ${t('push_titel')}</span>
            <button type="button" class="pub-push-toggle" data-aan="${aan ? '1' : '0'}">${
                aan ? t('push_uit') : t('push_aan')}</button>
        </div>
        <div class="pub-push-uitleg">${t('push_uitleg')}</div>
        <div class="pub-push-opties${aan ? '' : ' uit'}">
            <label class="pub-push-opt"><input type="checkbox" data-type="loting"  ${_ppPref('loting')  ? 'checked' : ''}> 🚩 ${t('push_loting')}</label>
            <label class="pub-push-opt"><input type="checkbox" data-type="uitslag" ${_ppPref('uitslag') ? 'checked' : ''}> 🏁 ${t('push_uitslag')}</label>
            <label class="pub-push-opt"><input type="checkbox" data-type="bericht" ${_ppPref('bericht') ? 'checked' : ''}> 📢 ${t('push_bericht')}</label>
        </div>
        <div class="pub-push-rij"${aan ? '' : ' hidden'}>
            <button type="button" class="pub-push-test">${t('push_test')}</button>
            <span class="pub-push-msg"></span>
        </div>`;

    const msg = el.querySelector('.pub-push-msg');
    el.querySelector('.pub-push-toggle').addEventListener('click', async () => {
        if (_ppBusy) return; _ppBusy = true;
        if (msg) msg.textContent = t('push_bezig');
        try {
            if (aan) {
                await _ppUit();
            } else {
                const res = await _ppAan();
                if (res === 'geweigerd')  { if (msg) msg.textContent = t('push_geweigerd'); }
                else if (res !== true)    { if (msg) msg.textContent = t('push_fout'); }
            }
        } catch (e) { if (msg) msg.textContent = t('push_fout'); }
        _ppBusy = false;
        _ppRender();
    });
    el.querySelectorAll('.pub-push-opt input').forEach(cb => cb.addEventListener('change', () => {
        _ppSetPref(cb.dataset.type, cb.checked);
        _ppSync();   // voorkeur meteen naar de server
    }));
    const testBtn = el.querySelector('.pub-push-test');
    if (testBtn) testBtn.addEventListener('click', async () => {
        if (_ppBusy) return; _ppBusy = true;
        if (msg) msg.textContent = t('push_bezig');
        try {
            const cur = await _ppHuidigAbo();
            const resp = await fetch('../api/push_subscribe.php?action=test', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scope: 'public', endpoint: cur ? cur.endpoint : '' }),
            });
            let r = {}; try { r = JSON.parse(await resp.text()); } catch (e) {}
            if (r.ok && (r.result?.verstuurd > 0)) { if (msg) msg.textContent = t('push_testok'); }
            else { if (msg) msg.textContent = '⚠ ' + (r.reden || t('push_mislukt')); }
        } catch (e) { if (msg) msg.textContent = t('push_fout'); }
        _ppBusy = false;
    });
}

// ── PWA: service worker ───────────────────────────────────────────────────
// Update-flow: SW is network-only met cache-cleanup bij activate (zie sw.js).
// reg.update() bij visibility-change zorgt dat browsers nieuwe SW oppikken,
// maar GEEN automatische window.reload() — die wiste input-velden tijdens
// typen (regressie 2026-05-27: gebruikers konden niet meer op zoeken
// klikken doordat hun startnummer-input mid-typen werd gewist).
// Gevolg: nieuwe versie verschijnt pas bij volgende natuurlijke refresh
// of nav. Voor browser-users zorgen PHP no-cache headers dat dat altijd
// vers is. Voor PWA-users: tab sluiten/openen.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').then(reg => {
        const checkUpdate = () => { try { reg.update(); } catch {} };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') checkUpdate();
        });
        setInterval(checkUpdate, 5 * 60 * 1000);
    }).catch(() => {});
}

let _deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredPrompt = e;
    // Toon banner alleen als gebruiker het niet eerder heeft weggeklikt
    if (!localStorage.getItem('pwa-dismissed')) {
        document.getElementById('pwa-banner').hidden = false;
    }
});

document.getElementById('pwa-install')?.addEventListener('click', async () => {
    if (!_deferredPrompt) return;
    _deferredPrompt.prompt();
    const result = await _deferredPrompt.userChoice;
    if (result.outcome === 'accepted') {
        document.getElementById('pwa-banner').hidden = true;
    }
    _deferredPrompt = null;
});

document.getElementById('pwa-sluit')?.addEventListener('click', () => {
    document.getElementById('pwa-banner').hidden = true;
    localStorage.setItem('pwa-dismissed', '1');
});

// Profiel-promo (Mijn InlineComp): standaard ELKE keer tonen. Sluiten (×) verbergt
// 'm alleen deze keer; pas met het vinkje 'Niet meer tonen' blijft-ie weg.
(function () {
    const el = document.getElementById('profiel-promo');
    if (!el) return;
    try { if (localStorage.getItem('profiel-promo-nooit')) el.style.display = 'none'; } catch (e) {}
    const onthoudAlsGevinkt = () => {
        const niet = document.getElementById('profiel-promo-niet');
        if (niet && niet.checked) { try { localStorage.setItem('profiel-promo-nooit', '1'); } catch (e) {} }
    };
    document.getElementById('profiel-promo-sluit')?.addEventListener('click', () => {
        el.style.display = 'none';
        onthoudAlsGevinkt();
    });
    // Ook onthouden als ze 'niet meer tonen' aanvinken en op 'Bekijk voorbeeld' klikken.
    el.querySelector('a.btn-install')?.addEventListener('click', onthoudAlsGevinkt);
})();

// Verberg banner als app al geinstalleerd is
window.addEventListener('appinstalled', () => {
    document.getElementById('pwa-banner').hidden = true;
    _deferredPrompt = null;
});
