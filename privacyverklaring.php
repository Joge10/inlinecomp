<?php
// Publieke privacyverklaring — geen login vereist.
// Vul de ORG_NAAM / ORG_EMAIL / ORG_ADRES aan met jouw organisatiegegevens
// vóór je dit live zet. De tekst is bedoeld als uitgangspunt; laat 'm bij
// twijfel toetsen door iemand met AVG-ervaring of een jurist.
//
// Twee talen: NL bovenaan, EN eronder. Switcher in de header om snel
// te kunnen springen. Geen DE/FR — privacyverklaring is een zelden-
// geraadpleegd document, NL+EN dekt 99% van de gebruikers van deze
// app en houdt onderhoud bij wijzigingen behapbaar.

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$ORG_NAAM   = 'InlineComp';                 // TODO: aanpassen naar jouw vereniging / beheerder
$ORG_EMAIL  = 'inlinecomp@devriesen.com';   // TODO: e-mailadres voor verzoeken
$ORG_ADRES  = '';                           // TODO: eventueel postadres
$LAATSTE_UPDATE_NL = '24 september 2026';
$LAATSTE_UPDATE_EN = '24 September 2026';
?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacyverklaring / Privacy Statement — <?= htmlspecialchars($ORG_NAAM) ?></title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .privacy-wrap { max-width: 780px; margin: 2rem auto; padding: 0 1rem;
                        font-family: Arial, sans-serif; line-height: 1.55; color: #222; }
        .privacy-wrap h1 { color: #1a3a5c; margin-bottom: .3rem; }
        .privacy-wrap h2 { color: #1a3a5c; margin-top: 1.8rem; border-bottom: 2px solid #e1e8ef;
                           padding-bottom: .2rem; }
        .privacy-wrap .meta { color: #666; font-size: .9rem; margin-bottom: 2rem; }
        .privacy-wrap ul { padding-left: 1.3rem; }
        .privacy-wrap li { margin: .3rem 0; }
        .privacy-wrap .contact { background: #f4f8fb; border-left: 4px solid #1a3a5c;
                                 padding: .8rem 1rem; margin: 1rem 0; }
        .privacy-wrap .terug { display: inline-block; margin-top: 2rem; color: #1a3a5c;
                               text-decoration: none; }
        .privacy-wrap .terug:hover { text-decoration: underline; }
        .lang-switcher { background: #f4f8fb; border-left: 4px solid #1a3a5c;
                         padding: .6rem 1rem; margin: 0 0 1.5rem; font-size: .92rem; }
        .lang-switcher a { color: #1a3a5c; font-weight: 600; }
        .privacy-divider { margin: 4rem 0 2rem; border-top: 3px double #1a3a5c; }
    </style>
</head>
<body>
<div class="privacy-wrap">

<!-- ── Nederlandse versie ──────────────────────────────────────────────── -->
<a id="nl"></a>
<h1>Privacyverklaring</h1>
<p class="meta">Laatst bijgewerkt: <?= htmlspecialchars($LAATSTE_UPDATE_NL) ?></p>

<div class="lang-switcher">
    🇬🇧 An <strong>English version</strong> of this privacy statement is available
    <a href="#en">below</a> (or scroll down).
</div>

<p>InlineComp is een digitaal systeem voor het beheer van inline-skate-wedstrijden,
ontwikkeld en beheerd door Geert de Vries als vrijwilliger. In deze verklaring leggen
we uit welke persoonsgegevens wij verwerken, met welk doel, op welke grondslag en welke
rechten je daarbij hebt. Deze verklaring is afgestemd op de Algemene Verordening
Gegevensbescherming (AVG/GDPR).</p>

<div style="background:#f4f8fb;border-left:4px solid #1a3a5c;padding:.8rem 1rem;margin:1.5rem 0;">
<h2 style="margin-top:0;border:0;padding-bottom:0;">In het kort</h2>
<p><em>Dit is een samenvatting in gewone taal. De volledige, juridisch precieze tekst
staat hieronder — bij twijfel geldt die volledige tekst.</em></p>
<ul>
    <li>We verwerken alleen wat nodig is om wedstrijden te organiseren: je naam,
        KNSB-licentienummer, vereniging, startnummer en je resultaten. Deze gegevens
        krijgen we meestal van de KNSB, soms van de organiserende vereniging, en
        alleen bij optionele verzoeken (profiel, anoniem) rechtstreeks van jou.</li>
    <li>We slaan <strong>geen</strong> e-mailadres, telefoonnummer, adres of
        geboortedatum van rijders op.</li>
    <li>Veel rijders zijn <strong>minderjarig</strong>; van hen verwerken we dezelfde
        beperkte gegevens. Marketing of profilering doen we van niemand. Optionele keuzes
        (profiel, anoniem, meldingen) maakt de ouder/verzorger (zie §1f).</li>
    <li>Uitslagen worden openbaar gepubliceerd, zoals gebruikelijk in de sport.</li>
    <li>Als coach kun je vrijwillig een account maken; als rijder of coach kun je
        vrijwillig pushmeldingen aanzetten. Beide zijn optioneel en je kunt ze zelf
        weer uitzetten.</li>
    <li>Als rijder kun je vrijwillig een persoonlijk profiel ("Mijn InlineComp")
        aanvragen om je eigen resultaten privé terug te zien. We bewaren daarvoor
        alleen een gebruikersnaam en een versleutelde pincode, geen e-mailadres; het
        profiel is niet openbaar.</li>
    <li>Als rijder kun je ervoor kiezen <strong>publiek anoniem</strong> te zijn: je
        naam, vereniging, woonplaats én startnummer worden op de openbare pagina's
        vervangen door “Anoniem”. Rond de wedstrijddag zelf zijn je naam en startnummer
        wél zichtbaar voor de start-indeling. Wie jou tóch wil volgen, kan dat met een
        persoonlijk volg-ID dat je zelf deelt.</li>
    <li>Voor het digitaliseren van oude papieren uitslagen en voor het vertalen van
        mededelingen gebruiken we soms AI (Anthropic Claude, een Amerikaans bedrijf)
        — met de wettelijk vereiste waarborgen.</li>
    <li>Onze website draait bij een hostingpartij in het Verenigd Koninkrijk;
        technische bezoekgegevens (zoals IP-adres) worden daar kort bewaard voor
        beveiliging.</li>
    <li>Je kunt altijd opvragen welke gegevens we van je hebben, ze laten corrigeren,
        of vragen om verwijdering (waarbij we je naam vervangen door “Verwijderd” zodat
        de wedstrijdhistorie blijft kloppen).</li>
</ul>
<p>Vragen? Mail naar <a href="mailto:<?= htmlspecialchars($ORG_EMAIL) ?>"><?= htmlspecialchars($ORG_EMAIL) ?></a>.</p>
</div>

<h2>0. Wie is verantwoordelijk?</h2>
<p>InlineComp is een wedstrijdmanagementsysteem, beheerd door Geert de Vries als
vrijwilliger, zonder dat hier (nog) een bedrijf of rechtspersoon achter staat. De
wedstrijden zelf worden georganiseerd door verenigingen en organisaties; InlineComp
organiseert geen wedstrijden.</p>
<p>Voor de verwerking van persoonsgegevens in InlineComp — het verwerken van
wedstrijdgegevens, het historisch uitslagarchief en de wedstrijdoverstijgende klassementen,
de openbare uitslagpagina's, persoonlijke profielen, publiek anoniem, coach-accounts,
pushmeldingen, geaggregeerde statistiek en het login-logboek — is <strong>InlineComp
verwerkingsverantwoordelijke</strong>. Het beheer van InlineComp bepaalt de doelen en de
middelen van deze verwerking (hoe het systeem werkt, wat er bewaard en gepubliceerd wordt).
De organiserende vereniging gebruikt InlineComp als hulpmiddel, maar geeft geen instructies
over de wijze van verwerken.</p>
<p>Zolang er geen rechtspersoon achter InlineComp staat, treedt Geert de Vries op als de
verantwoordelijke natuurlijke persoon. Met vragen of verzoeken over privacy kun je bij ons
terecht (§10).</p>

<h2>1. Welke gegevens verwerken wij?</h2>
<p>Van elke rijder die deelneemt aan een wedstrijd die in InlineComp wordt verwerkt,
verwerken wij de volgende gegevens. Welke bron deze gegevens aanlevert, staat in §4.</p>
<ul>
    <li>Naam (volledige naam, eventueel roepnaam)</li>
    <li>Geslacht, KNSB-categorie</li>
    <li>KNSB-licentienummer / relatienummer</li>
    <li>Vereniging en verenigingscode</li>
    <li>Optioneel: sponsor, woonplaats, nationaliteit</li>
    <li>Startnummer en, bij gebruik, transponder-code voor tijdregistratie</li>
</ul>
<p>Daarnaast leggen wij per wedstrijd de sportieve resultaten vast (tijden,
sancties, klassering). Deze koppelen wij aan de rijder via een intern ID dat wij
zelf toekennen.</p>
<p>Wij verwerken <strong>geen</strong> e-mailadressen, telefoonnummers, adressen,
geboortedatum of geboortejaar van rijders. Wél bewaren wij de KNSB-<strong>categorie</strong>.
Die hoort bij een leeftijdsgroep, waaruit een leeftijdsindicatie — en over meerdere
seizoenen, via de jaarlijkse categorie-doorschuiving, een geschat geboortejaar-bereik —
is af te leiden. Wij gebruiken deze categorie-indicatie om te controleren of een
nieuwe inschrijving bij een rijder hoort die al in ons systeem staat (plausibiliteit en
juiste klassementen). Deze indicatie berekenen wij per controle opnieuw en slaan wij
niet op.</p>

<h2>1b. Coach-accounts (optioneel)</h2>
<p>Coaches kunnen — geheel vrijwillig — een persoonlijk account aanmaken in de
coach-app. Zonder account is het gebruik anoniem; met een account verwerken wij:</p>
<ul>
    <li>je <strong>naam</strong> en <strong>e-mailadres</strong> (als inlog- en herkenningsgegeven, en om je account-berichten te sturen — bijvoorbeeld goedkeuring, afwijzing of een wachtwoord-reset);</li>
    <li>de <strong>club of het team</strong> waarvoor je coacht (ter beoordeling van je aanvraag);</li>
    <li>je zelf samengestelde <strong>lijst van rijders die je wilt volgen</strong> (opgeslagen met een intern ID per rijder).</li>
</ul>
<p>De grondslag is jouw <strong>toestemming</strong> — je maakt het account zelf aan.
Het doel is uitsluitend je gemak als coach: je atleten één keer instellen en ze
automatisch terugzien. Een account wordt pas actief na goedkeuring door de beheerder.
Je kunt je account en atletenlijst op elk moment zelf verwijderen; daarnaast vervalt
een account automatisch na één jaar zonder inloggen. Het wachtwoord bewaren wij
uitsluitend versleuteld (bcrypt-hash).</p>
<p>Bij het in- en uitloggen leggen wij, net als bij beheerders en jury, een
beveiligingsregel vast in ons login-logboek (zie §5d).</p>

<h2>1c. Pushmeldingen (optioneel)</h2>
<p>In de coach- en publieke app kun je — geheel vrijwillig — <strong>pushmeldingen</strong>
aanzetten voor een seintje op je telefoon bij loting, uitslag of een mededeling van de
organisatie. Zet je dit aan, dan verwerken wij per apparaat:</p>
<ul>
    <li>een <strong>push-abonnement</strong> van je browser (een technisch adres — het
        'endpoint' — plus versleutel-sleutels) om de melding aan jouw apparaat te bezorgen;</li>
    <li>welke <strong>rijders je volgt</strong> (via een intern ID, zodat we
        alleen relevante meldingen sturen), je gekozen <strong>taal</strong> en welke meldingtypen
        je aan hebt staan;</li>
    <li>een korte <strong>browser-/apparaataanduiding</strong> (user-agent) voor beheer en opschoning.</li>
</ul>
<p>De grondslag is jouw <strong>toestemming</strong> — je zet de meldingen zelf aan en kunt
ze op elk moment weer uitzetten, waarna het abonnement wordt verwijderd. In de publieke app
worden je gevolgde rijders normaal alléén lokaal op je toestel bewaard; <strong>alleen</strong>
wanneer je pushmeldingen aanzet, worden die interne ID's naar onze server
gestuurd om de meldingen te kunnen richten. Aan een publiek push-abonnement is <strong>geen naam of
e-mailadres</strong> gekoppeld. Verlopen of ingetrokken abonnementen worden automatisch verwijderd.</p>
<p><strong>Bezorging via je browser-push-dienst:</strong> om de melding op je toestel te
krijgen, loopt deze via de push-dienst van je browser-leverancier — Google (Android/Chrome),
Mozilla (Firefox) of Apple (Safari/iPhone). Zij ontvangen het technische endpoint en de
(versleutelde) melding om deze te bezorgen; wij delen hierbij <strong>geen namen of
rijdersgegevens</strong>, en de inhoud is versleuteld tussen ons en jouw apparaat.</p>

<h2>1d. Persoonlijk profiel — "Mijn InlineComp" (optioneel)</h2>
<p>Een rijder (of, bij jeugd, de ouder/verzorger) kan — geheel vrijwillig — een
<strong>persoonlijk profiel</strong> aanvragen om de <strong>eigen</strong> wedstrijdgegevens
overzichtelijk op één plek terug te zien: persoonlijke records, resultaten en een
voortgangsgrafiek. Het profiel toont uitsluitend gegevens die al bij ons aanwezig zijn omdat
de rijder aan wedstrijden heeft deelgenomen — er komt <strong>geen nieuwe informatie</strong> bij.</p>
<p>Voor een profiel bewaren wij per rijder alleen:</p>
<ul>
    <li>een <strong>zelfgekozen gebruikersnaam</strong> om mee in te loggen;</li>
    <li>een <strong>versleutelde pincode</strong> (bcrypt-hash) — wij kunnen de pincode
        niet uitlezen;</li>
    <li>de koppeling aan het <strong>interne rijder-ID</strong> waaronder de rijder bij ons
        bekend is (zie §1) — niet aan het KNSB-licentienummer.</li>
</ul>
<p>Wij bewaren <strong>geen e-mailadres</strong> bij het profiel. Een aanvraag verloopt via een
formulier aan ons. Je e-mailadres bewaren wij daarbij alleen <strong>tijdelijk</strong> —
tot de aanvraag is goedgekeurd of afgewezen — om je een ontvangstbevestiging en (bij goedkeuring)
de aanmaaklink te sturen; daarna verwijderen wij het e-mailadres. Het wordt niet aan het profiel
zelf gekoppeld. Het profiel is <strong>privé</strong>:
het is alleen zichtbaar na inloggen met gebruikersnaam en pincode, wordt niet door zoekmachines
geïndexeerd en de gegevens worden <strong>niet openbaar gedeeld</strong> — het is een privé-inzage
van je eigen, reeds verwerkte resultaten. De grondslag is <strong>gerechtvaardigd belang</strong>
(art. 6 lid 1 sub f AVG): het gaat om een besloten weergave van gegevens die de rijder zelf
betreffen en die al onderdeel zijn van het wedstrijdarchief. Omdat er niets openbaar wordt
gemaakt of extern wordt gedeeld, is hiervoor <strong>geen aparte toestemming</strong> nodig, ook
niet bij jeugdrijders. Je kunt het profiel op elk moment laten verwijderen door ons;
de onderliggende wedstrijduitslagen blijven dan bestaan als onderdeel van het sporthistorisch
archief (zie §7).</p>

<h2>1e. Publiek anoniem tonen (optioneel)</h2>
<p>Een rijder kan ervoor kiezen <strong>publiek anoniem</strong> te zijn; bij jeugd regelt
de ouder/verzorger dat. Wij tonen dan op de openbare pagina's <strong>“Anoniem”</strong> in
plaats van je naam, vereniging en woonplaats, en we verbergen ook je <strong>startnummer</strong>
(dat is in Nederland meerjarig vast, dus zichtbaar laten zou je alsnog herleidbaar maken). De
gegevens zelf blijven bij ons bewaard — het is uitsluitend een <strong>weergave-keuze</strong> en
volledig omkeerbaar.</p>
<ul>
    <li><strong>Rond de wedstrijddag</strong> (van de dag ervoor tot en met de dag erna) tonen
        wij de naam <strong>en het startnummer</strong> wél, omdat de start- en heat-indeling dan
        operationeel nodig is. Daarbuiten, en in het permanente uitslag- en serie-klassement-archief,
        blijf je anoniem. Je bent ook <strong>niet op naam vindbaar</strong> in de publieke zoek.</li>
    <li><strong>Zelf instellen</strong>: via je persoonlijke profiel “Mijn InlineComp” (§1d), of
        — zonder profiel — door ons te mailen; wij zetten de keuze voor je
        (en kunnen 'm op jouw verzoek weer opheffen).</li>
    <li><strong>Gericht laten volgen</strong>: wie jou tóch wil volgen (bijvoorbeeld een ouder of
        coach) kan dat met een <strong>persoonlijk, geheim volg-ID</strong> dat je zelf deelt;
        alleen wie dat ID heeft, ziet je naam. Je kunt dit volg-ID op elk moment vernieuwen,
        waarna eerdere volgers geen toegang meer hebben.</li>
    <li><strong>Import</strong>: geeft een gegevensbron (bijvoorbeeld de KNSB) aan dat een rijder
        anoniem wil zijn, dan nemen wij die keuze over bij het inlezen.</li>
</ul>
<p>Dit is een <strong>beperking van de openbaarmaking</strong>: je maakt bezwaar (art. 21
AVG) tegen het openbaar tonen van je naam, en wij honoreren dat door je publiek af te
schermen, terwijl de sportieve uitslag intact blijft. Deze keuze staat los van de
onomkeerbare verwijdering uit §9: bij “publiek anoniem” blijven je gegevens behouden en
kun je de keuze weer terugdraaien.</p>

<h2>1f. Minderjarige rijders</h2>
<p>Het merendeel van de deelnemers is <strong>minderjarig</strong>. De AVG kent kinderen extra
bescherming toe (overweging 38 AVG). Wij houden daar op de volgende manier rekening mee:</p>
<ul>
    <li>Voor minderjarigen verwerken wij <strong>dezelfde beperkte, sport-functionele gegevens</strong>
        als voor volwassenen (zie §1) — niet meer. Wij doen <strong>geen marketing, profilering
        of tracking</strong> — van niemand, dus ook niet van kinderen.</li>
    <li>De gegevens ontvangen wij hoofdzakelijk van de <strong>KNSB</strong> (zie §4); daar ligt de
        lidmaatschapsrelatie, die bij inschrijving door het lid of diens ouder/verzorger wordt
        aangegaan. Wij gebruiken de gegevens uitsluitend voor de doelen in §2.</li>
    <li>In de belangenafweging voor onze grondslag (gerechtvaardigd belang, §3) wegen wij expliciet
        mee dat veel betrokkenen kind zijn: we houden de verwerking minimaal en bieden de mogelijkheid
        om de naam publiek af te schermen (<strong>publiek anoniem</strong>, §1e).</li>
    <li>De <strong>optionele</strong> keuzes — een persoonlijk profiel (§1d), publiek
        anoniem (§1e) en pushmeldingen (§1c) — horen voor een minderjarige
        <strong>door de ouder/verzorger</strong> gemaakt en beheerd te worden. Voor
        pushmeldingen, die op toestemming rusten, geeft bij rijders onder de 16 jaar de
        ouder/verzorger die toestemming (art. 5 UAVG).</li>
    <li>De <strong>rechten</strong> uit §9 (inzage, correctie, verwijdering/anonimisering, bezwaar)
        kunnen namens een minderjarige door de ouder/verzorger worden uitgeoefend.</li>
</ul>

<h2>2. Waarom verwerken wij deze gegevens?</h2>
<ul>
    <li>Het ondersteunen van verenigingen en organisatoren bij het correct organiseren en
        uitvoeren van wedstrijden (startlijsten, tijdregistratie, uitslag, klassement).</li>
    <li>Het bijdragen aan de wedstrijdorganisatie binnen de context van de KNSB
        als overkoepelende bond.</li>
    <li>Het bewaren van een historisch uitslagoverzicht voor deelnemers,
        verenigingen en de bond.</li>
    <li>Het uitvoeren van <strong>geaggregeerde statistiek en trend-/kwaliteitsanalyse</strong>
        voor de sport en de wedstrijdorganisatie (bijvoorbeeld deelname- en verloopcijfers
        over meerdere jaren). Dit gebeurt op basis van gegevens die wij al voor bovenstaande
        doelen verwerken — wij verzamelen hiervoor niets extra — en de uitkomsten zijn
        geanonimiseerd/geaggregeerd, zodat individuele rijders daaruit niet herleidbaar zijn.</li>
</ul>

<h2>3. Grondslag</h2>
<p>De verwerking vindt plaats op grond van <strong>gerechtvaardigd belang</strong>
(art. 6 lid 1 sub f AVG): zonder deze gegevens kan geen eerlijke wedstrijd worden
georganiseerd, kunnen uitslagen niet worden gepubliceerd en kan de sportieve geschiedenis
niet worden bewaard en ontsloten voor rijders, verenigingen en de sport. Het gaat om
beperkte, sport-functionele gegevens die in deze sport gebruikelijk openbaar zijn, en dit
belang is niet onevenredig ten opzichte van de privacy van deelnemers. In deze afweging
houden wij er in het bijzonder rekening mee dat veel deelnemers minderjarig zijn (zie §1f),
en bieden wij de mogelijkheid om de naam publiek af te schermen (§1e).</p>
<p>Voor coach-accounts en pushmeldingen geldt daarnaast <strong>toestemming</strong>
(art. 6 lid 1 sub a AVG) als grondslag — zie §1b en §1c.</p>

<h2>4. Bron van de gegevens</h2>
<p>Wij ontvangen persoonsgegevens uit de volgende bronnen:</p>
<ul>
    <li><strong>KNSB</strong>: via het officiële inschrijfsysteem (API) van de KNSB, op
        het moment dat een rijder zich voor een wedstrijd inschrijft. Dit is de hoofdbron.</li>
    <li><strong>Organiserende vereniging of organisatie</strong>: voor wedstrijden die
        niet (volledig) via de KNSB lopen, kan de organisator de deelnemerslijst als
        CSV-bestand aanleveren. Wij nemen daaruit alleen de gegevens over die in §1 zijn
        genoemd; overige kolommen worden niet ingelezen.</li>
    <li><strong>Historische uitslagen</strong>: voor seizoens- of meerjarenklassementen
        kunnen wij oude papieren of pdf-uitslagen inlezen via een import-tool, in sommige
        gevallen met hulp van een AI-dienst voor tekstherkenning (zie §5b).</li>
    <li><strong>De rijder of ouder/verzorger zelf</strong>: uitsluitend bij optionele
        verzoeken, zoals het aanvragen van een persoonlijk profiel (§1d) of publiek anoniem
        (§1e), en bij het aanmaken van een coach-account (§1b) of het aanzetten van
        pushmeldingen (§1c).</li>
</ul>
<p>Buiten deze bronnen verzamelen wij geen gegevens over rijders.</p>

<h2>5. Met wie delen wij de gegevens?</h2>
<ul>
    <li><strong>KNSB</strong>: wij wisselen inschrijf- en uitslagdata uit met de KNSB als
        onderdeel van de bondswedstrijden.</li>
    <li><strong>Publiek (uitslagen)</strong>: namen, verenigingen, startnummers
        en eindtijden worden openbaar gepubliceerd op onze uitslagpagina, zoals
        gangbaar in de sport — behalve van rijders die voor <strong>publiek
        anoniem</strong> hebben gekozen; die tonen wij als “Anoniem” (zie §1e).</li>
    <li><strong>AI-dienstverlener (Anthropic)</strong>: zie §5b voor uitleg.</li>
    <li><strong>Push-diensten (Google/Mozilla/Apple)</strong>: uitsluitend voor het
        bezorgen van pushmeldingen die je zelf hebt aangezet — zie §1c.</li>
    <li><strong>Hostingprovider (iFastNet Ltd)</strong>: zie §5c voor uitleg.</li>
    <li><strong>Geo-IP-dienst (ip-api.com – Artia International S.R.L., Roemenië)</strong>:
        uitsluitend om bij een login het IP-adres om te zetten naar een globale locatie
        (land/stad) voor het login-logboek — zie §5d.</li>
    <li>Wij verkopen géén gegevens en delen ze niet met derden buiten het
        bovenstaande.</li>
</ul>

<h2>5b. Gebruik van AI-diensten (Anthropic Claude)</h2>
<p>Voor twee specifieke beheer-taken roepen wij de AI-dienst <strong>Anthropic
Claude</strong> aan via hun API:</p>
<ul>
    <li><strong>Historie-import van oude uitslagen</strong>: bij het inlezen
        van geprinte of PDF-uitslagen van voorgaande seizoenen helpt Claude
        bij het herkennen en structureren van namen, tijden, categorieën en
        finishposities. Tijdens deze bewerking worden de relevante tekstdelen
        (namen, tijden, etc.) tijdelijk naar Anthropic verstuurd voor
        herkenning.</li>
    <li><strong>Vertalen van mededelingen</strong>: titels en berichten van
        publieke mededelingen (bv. “Programma loopt 15 min uit”) worden door
        Claude vertaald naar Engels, Duits en Frans. Deze teksten bevatten
        doorgaans geen persoonsgegevens.</li>
</ul>
<p><strong>Belangrijke kanttekeningen:</strong></p>
<ul>
    <li>Anthropic is een Amerikaans bedrijf, gevestigd in San Francisco (VS).
        De doorgifte naar de VS vindt plaats op basis van <em>Standard
        Contractual Clauses (SCC's)</em> zoals voorzien onder de AVG, aangevuld
        met de verwerkersovereenkomst (Data Processing Addendum) die Anthropic
        voor haar zakelijke/API-klanten aanbiedt.</li>
    <li>Volgens het privacy-beleid van Anthropic worden API-data <strong>niet
        gebruikt voor het trainen</strong> van hun AI-modellen. Data kan
        beperkt bewaard worden voor het detecteren van misbruik (standaard
        maximaal 30 dagen).</li>
    <li>Wij gebruiken de AI <strong>uitsluitend</strong> voor de twee
        bovengenoemde taken — niet voor andere verwerkingen van
        persoonsgegevens.</li>
    <li>De Historie-import-functie is een beheer-actie (geen automatische
        verwerking); een beheerder besluit per import of de tekst naar de
        AI wordt gestuurd.</li>
</ul>

<h2>5c. Serverlogbestanden &amp; Hosting</h2>
<p>Onze website wordt gehost door <strong>iFastNet Ltd</strong> (Verenigd Koninkrijk).
Wanneer je onze website bezoekt, slaat de webserver automatisch technische informatie op
in serverlogbestanden (Raw Access Logs). Dit omvat onder andere je IP-adres, browsertype,
de opgevraagde pagina en de datum/tijd van het bezoek.</p>
<ul>
    <li><strong>Grondslag &amp; doel</strong>: deze verwerking gebeurt op basis van ons
        gerechtvaardigd belang (art. 6 lid 1 sub f AVG) om de website technisch te
        beveiligen, fouten op te sporen en misbruik of cyberaanvallen tegen te gaan.</li>
    <li><strong>Doorgifte buiten de EU</strong>: omdat onze hostingprovider in het Verenigd
        Koninkrijk is gevestigd, vindt hiervoor een doorgifte plaats zonder aanvullende
        waarborgen (zoals SCC's), op basis van het adequaatheidsbesluit van de Europese
        Commissie voor het Verenigd Koninkrijk (laatst verlengd tot december 2031). De
        VK-locatie is geverifieerd via het RIPE-netwerkregister.</li>
    <li><strong>Bewaartermijn</strong>: deze technische serverlogs worden via het
        cPanel-systeem automatisch binnen 24 uur tot maximaal 30 dagen overschreven of
        verwijderd, tenzij ze langer nodig zijn voor een specifiek beveiligingsonderzoek.</li>
    <li>Deze logs worden niet gekoppeld aan een gebruikersaccount en niet gebruikt voor
        tracking — zie ook de “Anonieme bezoek-statistieken” op de publieke pagina, die
        los hiervan géén IP-adressen bewaren.</li>
</ul>
<p><strong>Anonieme bezoek-statistieken (publieke pagina):</strong> op de publieke pagina's
houden wij bij hoeveel bezoekers er tegelijk actief zijn, zodat wij de serverbelasting
kunnen bewaken en zo nodig kunnen ingrijpen om de wedstrijd te laten doordraaien. Hiervoor bewaren
wij per bezoeksessie alleen een technisch sessie-ID, een browser-/apparaataanduiding
(user-agent) en tijdstempels — <strong>geen IP-adres en geen naam of andere
persoonsgegevens</strong>. Om bezoeken binnen één sessie niet dubbel te tellen plaatsen wij
hierbij een klein sessie-cookie (<code>ICPUB</code>) op je apparaat; dit verdwijnt zodra je de
browser sluit en wordt niet gebruikt om je gedrag over websites heen te volgen.</p>

<h2>5d. Login-logboek &amp; locatiebepaling</h2>
<p>Voor de beveiliging houden wij een login-logboek bij van in- en uitlogpogingen van
<strong>beheerders, coaches en jury</strong> (niet van gewone bezoekers). Per gebeurtenis
leggen wij vast: tijdstip, IP-adres, een globale locatie (land en stad) en een korte
browser-/apparaataanduiding. Ook mislukte inlogpogingen worden vastgelegd, om misbruik en
brute-force-aanvallen te detecteren.</p>
<ul>
    <li><strong>Grondslag &amp; doel</strong>: gerechtvaardigd belang (art. 6 lid 1 sub f
        AVG) — beveiliging en misbruikdetectie.</li>
    <li><strong>Locatiebepaling</strong>: om het IP-adres om te zetten naar land/stad
        gebruiken wij de geo-IP-dienst <strong>ip-api.com</strong>, geleverd door
        <strong>Artia International S.R.L. (Boekarest, Roemenië)</strong>. Deze verwerker is
        in de EU gevestigd en valt onder de AVG; er vindt géén doorgifte buiten de EU plaats.
        Wij sturen uitsluitend het IP-adres (geen naam of rijdersgegevens) en bewaren zelf
        alleen de afgeleide land/stad.</li>
    <li><strong>Bewaartermijn</strong>: login-logboekregels worden na 30 dagen automatisch
        verwijderd.</li>
</ul>

<h2>6. Waar staan de gegevens?</h2>
<p>Al onze wedstrijd-, account- en technische gegevens (§1, §1b, §1c, §5c) staan op
dezelfde webserver bij onze hostingprovider <strong>iFastNet Ltd in het Verenigd
Koninkrijk</strong>. Toegang is beperkt tot beheerders van
<?= htmlspecialchars($ORG_NAAM) ?> via wachtwoord-beveiligde accounts.</p>
<p>Eén uitzondering: <strong>AI-verwerking</strong> (zie §5b) gebeurt op servers van
Anthropic in de Verenigde Staten.</p>

<h2>7. Bewaartermijn</h2>
<p>Wij bewaren persoonsgegevens zolang dat nodig is voor het doel waarvoor
ze zijn verzameld:</p>
<ul>
    <li><strong>Actieve wedstrijdgegevens</strong>: gedurende het lopende
        seizoen en twee kalenderjaren daarna, t.b.v. seizoens- en
        meerjarenklassement.</li>
    <li><strong>Historische uitslagen</strong>: uitslagen en klasseringen
        bewaren wij onbeperkt als onderdeel van het sporthistorisch archief,
        gekoppeld via een intern ID. Op verzoek verwijderen wij de naam en
        overige persoonsgegevens (inclusief de licentie-koppeling), zodat alleen
        het naamloze resultaat onder dat interne ID overblijft, dat alleen in ons
        eigen systeem betekenis heeft (zie §9).</li>
    <li><strong>Login-gegevens van beheerders</strong>: zo lang het account
        actief is; uiterlijk 12 maanden na laatste login worden inactieve
        accounts verwijderd.</li>
    <li><strong>AI-verwerking</strong>: zie §5b — data die naar Anthropic
        wordt gestuurd valt onder hun retentiebeleid (standaard maximaal
        30 dagen voor abuse-monitoring, niet gebruikt voor training).</li>
    <li><strong>Push-abonnementen</strong>: zolang je de meldingen aan hebt
        staan; ze worden verwijderd zodra je ze uitzet of het abonnement verloopt.</li>
    <li><strong>Serverlogbestanden</strong>: 24 uur tot maximaal 30 dagen, tenzij langer
        nodig voor beveiligingsonderzoek — zie §5c.</li>
    <li><strong>Login-logboek (beheer/coach/jury)</strong>: 30 dagen — zie §5d.</li>
    <li><strong>Coach-accounts</strong>: zolang het account bestaat; je kunt het zelf
        verwijderen en het vervalt automatisch na één jaar zonder inloggen — zie §1b.</li>
    <li><strong>Persoonlijk profiel ("Mijn InlineComp")</strong>: gebruikersnaam en
        versleutelde pincode blijven bewaard zolang het profiel bestaat; het wordt op verzoek
        van de rijder of door ons verwijderd. De onderliggende wedstrijduitslagen
        blijven bestaan als onderdeel van het historisch archief — zie §1d.</li>
    <li><strong>Publiek-anoniem-keuze</strong>: de voorkeur (en het bijbehorende volg-ID)
        blijft bewaard zolang die geldt; je kunt 'm zelf of via ons weer opheffen — zie §1e.</li>
</ul>

<h2>8. Beveiliging</h2>
<ul>
    <li>Verkeer tussen browser en server verloopt via HTTPS.</li>
    <li>Wachtwoorden van beheerders worden versleuteld opgeslagen (bcrypt-hash);
        wij kunnen wachtwoorden niet uitlezen.</li>
    <li>Alleen geautoriseerde beheerders hebben toegang tot de persoonsgegevens.</li>
    <li>Database-verkeer gebruikt prepared statements om SQL-injectie te
        voorkomen.</li>
    <li>De API-sleutel voor de AI-dienst (Anthropic) is opgeslagen in een
        server-config bestand buiten de webroot en niet toegankelijk voor
        derden.</li>
</ul>

<h2>9. Jouw rechten</h2>
<p>Op grond van de AVG heb je de volgende rechten:</p>
<ul>
    <li><strong>Inzage</strong> — je kunt opvragen welke gegevens wij van
        jou verwerken.</li>
    <li><strong>Rectificatie</strong> — onjuiste gegevens laten corrigeren.
        Let op: basisgegevens (naam, licentienummer, vereniging) komen van
        de KNSB; een correctie doen wij graag, maar je wordt gevraagd die
        óók bij de KNSB door te voeren zodat het gegeven bij volgende
        inschrijvingen niet opnieuw verkeerd binnenkomt.</li>
    <li><strong>Verwijdering / anonimisering</strong> — je kunt vragen om
        verwijdering. Om de sportieve geschiedenis en klassementen intact te
        houden vervangen wij jouw naam door “Verwijderd” en <strong>wissen wij
        je overige persoonsgegevens</strong> — woonplaats, nationaliteit,
        club/team, sponsor en startnummer — <strong>plus al je externe
        koppelingen</strong> (waaronder je KNSB-licentie). Wat overblijft is de
        naamloze wedstrijduitslag (categorie en tijden), gekoppeld via een
        intern ID dat alleen in ons eigen systeem betekenis heeft. Dit is strikt
        genomen pseudonimisering: wij zorgen ervoor dat de resterende
        sporttechnische gegevens redelijkerwijs niet meer tot jou als persoon
        herleidbaar zijn, ook niet in combinatie met een externe
        ledendatabase.</li>
    <li><strong>Bezwaar en beperking</strong> — je kunt bezwaar maken tegen
        de verwerking (inclusief de AI-verwerking uit §5b) of vragen om
        tijdelijke beperking. Wil je niet uit de uitslagen verdwijnen maar wél
        je naam publiek afschermen, dan kun je kiezen voor <strong>publiek
        anoniem</strong> (zie §1e).</li>
    <li><strong>Dataportabiliteit</strong> — je kunt een export van jouw
        gegevens in een gangbaar formaat opvragen.</li>
    <li><strong>Klacht indienen</strong> — je hebt het recht een klacht in
        te dienen bij de Autoriteit Persoonsgegevens
        (<a href="https://autoriteitpersoonsgegevens.nl" target="_blank"
            rel="noopener">autoriteitpersoonsgegevens.nl</a>).</li>
</ul>

<h2>10. Contact</h2>
<div class="contact">
    <p>Vragen of verzoeken over privacy stuur je naar:<br>
        <strong><?= htmlspecialchars($ORG_NAAM) ?></strong><br>
        Beheerd door Geert de Vries (vrijwilliger)<br>
        E-mail: <a href="mailto:<?= htmlspecialchars($ORG_EMAIL) ?>"><?= htmlspecialchars($ORG_EMAIL) ?></a>
        <?php if ($ORG_ADRES): ?><br>Adres: <?= htmlspecialchars($ORG_ADRES) ?><?php endif; ?>
    </p>
    <p>Wij reageren binnen vier weken op jouw verzoek. Om misbruik te voorkomen
        kunnen wij je vragen je identiteit aan te tonen, bijvoorbeeld door in te loggen
        op je persoonlijk profiel of via bevestiging door je vereniging.</p>
</div>

<h2>11. Wijzigingen in deze verklaring</h2>
<p>Deze privacyverklaring kan worden bijgewerkt als regelgeving of onze
werkwijze verandert. De meest recente versie staat altijd op deze pagina
met de datum “laatst bijgewerkt” bovenaan.</p>

<a href="public/" class="terug" onclick="if(history.length>1){history.back();return false;}">← Terug</a>


<!-- ── English version ──────────────────────────────────────────────────── -->
<div class="privacy-divider"></div>
<a id="en"></a>
<h1>Privacy Statement</h1>
<p class="meta">Last updated: <?= htmlspecialchars($LAATSTE_UPDATE_EN) ?></p>

<div class="lang-switcher">
    🇳🇱 Een <strong>Nederlandse versie</strong> van deze privacyverklaring
    is <a href="#nl">bovenaan</a> te vinden (or scroll up).
</div>

<p>InlineComp is a digital system for managing inline-skating competitions, developed
and run by Geert de Vries as a volunteer. This statement explains what personal data we
process, for what purpose, on what legal basis, and what rights you have. This statement
aligns with the General Data Protection Regulation (GDPR).</p>

<div style="background:#f4f8fb;border-left:4px solid #1a3a5c;padding:.8rem 1rem;margin:1.5rem 0;">
<h2 style="margin-top:0;border:0;padding-bottom:0;">In short</h2>
<p><em>This is a plain-language summary. The full, legally precise text is below — in
case of doubt, that full text applies.</em></p>
<ul>
    <li>We only process what's needed to organise competitions: your name, KNSB licence
        number, club, start number and your results. We usually receive this data from
        the KNSB, sometimes from the organising club, and only for optional requests
        (profile, anonymity) directly from you.</li>
    <li>We do <strong>not</strong> store skaters' e-mail address, phone number, home
        address or date of birth.</li>
    <li>Many skaters are <strong>minors</strong>; for them we process the same limited data.
        We do no marketing or profiling of anyone. Optional choices (profile, anonymous, notifications)
        should be made by the parent/guardian (see §1f).</li>
    <li>Results are published publicly, as is customary in the sport.</li>
    <li>As a coach you can voluntarily create an account; as a skater or coach you can
        voluntarily enable push notifications. Both are optional and you can turn them off
        yourself.</li>
    <li>As a skater you can voluntarily request a personal profile ("My InlineComp") to
        review your own results privately. For this we store only a username and an
        encrypted PIN, no e-mail address; the profile is not public.</li>
    <li>As a skater you can choose to be <strong>publicly anonymous</strong>: your name,
        club, place of residence and start number are replaced by “Anonymous” on the public
        pages. Around the competition day itself your name and start number are shown for the
        start list. Someone who still wants to follow you can do so with a personal
        follow-ID that you share yourself.</li>
    <li>For digitising old paper results and translating announcements, we sometimes use
        AI (Anthropic Claude, a US company) — with the legally required safeguards in place.</li>
    <li>Our website runs with a hosting provider in the United Kingdom; technical visit
        data (such as IP address) is briefly retained there for security purposes.</li>
    <li>You can always ask what data we hold about you, have it corrected, or request
        deletion (in which case we replace your name with “Removed” so the competition
        history stays intact).</li>
</ul>
<p>Questions? Email <a href="mailto:<?= htmlspecialchars($ORG_EMAIL) ?>"><?= htmlspecialchars($ORG_EMAIL) ?></a>.</p>
</div>

<h2>0. Who is responsible?</h2>
<p>InlineComp is a competition management system run by Geert de Vries as a volunteer,
without (yet) any company or legal entity behind it. The competitions themselves are
organised by clubs and organisations; InlineComp does not organise competitions.</p>
<p>For the processing of personal data in InlineComp — processing competition data, the
historical results archive and cross-competition standings, the public results pages,
personal profiles, public anonymity, coach accounts, push notifications, aggregated
statistics and the login log — <strong>InlineComp is the controller</strong>. The
management of InlineComp determines the purposes and means of this processing (how the
system works, what is retained and published). The organising club uses InlineComp as a
tool but does not give instructions on how the data is processed.</p>
<p>As long as there is no legal entity behind InlineComp, Geert de Vries acts as the
responsible natural person. You can contact us with any privacy questions or requests
(section 10).</p>

<h2>1. What data do we process?</h2>
<p>For each skater taking part in a competition processed in InlineComp, we process the
following data. Which source supplies this data is described in section 4.</p>
<ul>
    <li>Name (full name, optionally nickname)</li>
    <li>Gender, KNSB category</li>
    <li>KNSB licence/relation number</li>
    <li>Club name and code</li>
    <li>Optional: sponsor, place of residence, nationality</li>
    <li>Start number and, where used, transponder code for timekeeping</li>
</ul>
<p>In addition, for each competition we record sporting results (times,
sanctions, ranking). We link these to the skater via an internal ID that
we assign ourselves.</p>
<p>We do <strong>not</strong> process e-mail addresses, phone numbers, home addresses,
date of birth or year of birth of skaters. We do store the KNSB <strong>category</strong>.
A category corresponds to an age group, from which an age indication — and across multiple
seasons, via the annual category progression, an approximate year-of-birth range — can be
derived. We use this category indication to check whether a new registration belongs to a skater
already in our system (plausibility and correct standings). This indication is calculated
anew for each check and is not stored.</p>

<h2>1b. Coach accounts (optional)</h2>
<p>Coaches may — entirely voluntarily — create a personal account in the coach app.
Without an account, use is anonymous; with an account we process:</p>
<ul>
    <li>your <strong>name</strong> and <strong>e-mail address</strong> (as login and identification, and to send you account-related messages — for example approval, rejection or a password reset);</li>
    <li>the <strong>club or team</strong> you coach for (to assess your request);</li>
    <li>your self-curated <strong>list of skaters you wish to follow</strong> (stored with an internal ID per skater).</li>
</ul>
<p>The legal basis is your <strong>consent</strong> — you create the account yourself.
Its sole purpose is coach convenience: set up your athletes once and see them
automatically. An account becomes active only after approval by the administrator.
You can delete your account and athlete list yourself at any time; in addition, an
account expires automatically after one year without login. Passwords are stored only
in encrypted form (bcrypt hash).</p>
<p>When you sign in and out we record a security entry in our login log, as we do for
administrators and jury (see section 5d).</p>

<h2>1c. Push notifications (optional)</h2>
<p>In the coach and public apps you can — entirely voluntarily — turn on
<strong>push notifications</strong> to get an alert on your phone for a draw, a
result or an announcement from the organisation. If you enable this, we process
per device:</p>
<ul>
    <li>a <strong>push subscription</strong> from your browser (a technical address —
        the 'endpoint' — plus encryption keys) to deliver the notification to your device;</li>
    <li>which <strong>skaters you follow</strong> (via an internal ID, so
        we only send relevant notifications), your chosen <strong>language</strong> and which
        notification types you have enabled;</li>
    <li>a short <strong>browser/device identifier</strong> (user agent) for management and cleanup.</li>
</ul>
<p>The legal basis is your <strong>consent</strong> — you turn the notifications on
yourself and can turn them off again at any time, after which the subscription is
deleted. In the public app the skaters you follow are normally kept <strong>only
locally</strong> on your device; <strong>only</strong> when you enable push notifications
are those internal IDs sent to our server so notifications can be targeted. A public
push subscription has <strong>no name or e-mail address</strong> attached to it. Expired
or revoked subscriptions are deleted automatically.</p>
<p><strong>Delivery via your browser's push service:</strong> to reach your device, a
notification is routed through the push service of your browser vendor — Google
(Android/Chrome), Mozilla (Firefox) or Apple (Safari/iPhone). They receive the technical
endpoint and the (encrypted) notification in order to deliver it; we share <strong>no names
or skater data</strong> with them, and the content is encrypted between us and your device.</p>

<h2>1d. Personal profile — "My InlineComp" (optional)</h2>
<p>A skater (or, for youth, a parent/guardian) can — entirely voluntarily — request a
<strong>personal profile</strong> to review their <strong>own</strong> competition data in one
place: personal records, results and a progress chart. The profile shows only data we already
hold because the skater took part in competitions — <strong>no new information</strong> is added.</p>
<p>For a profile we store per skater only:</p>
<ul>
    <li>a <strong>self-chosen username</strong> to log in with;</li>
    <li>an <strong>encrypted PIN</strong> (bcrypt hash) — we cannot read the PIN;</li>
    <li>the link to the <strong>internal rider ID</strong> under which the skater is known in
        our system (see §1) — not to the KNSB licence number.</li>
</ul>
<p>We store <strong>no e-mail address</strong> with the profile. A request is made via a form to
us. We keep your e-mail address only <strong>temporarily</strong> for this — until
the request is approved or rejected — to send you an acknowledgement and (on approval) the
activation link; after that we delete the e-mail address. It is not linked to the profile itself. The profile is <strong>private</strong>: it is visible only
after logging in with a username and PIN, is not indexed by search engines, and the data is
<strong>not shared publicly</strong> — it is a private view of your own, already-processed
results. The legal basis is <strong>legitimate interest</strong> (Article 6(1)(f) GDPR): it is a
closed view of data concerning the skater themselves that is already part of the competition
archive. Because nothing is made public or shared externally, <strong>no separate consent</strong>
is required, including for youth skaters. You can have the profile deleted at any time by
us; the underlying competition results remain as part of the sport-historical archive
(see §7).</p>

<h2>1e. Showing as publicly anonymous (optional)</h2>
<p>A skater can choose to be <strong>publicly anonymous</strong>; for youth, the
parent/guardian arranges this. On the public pages we then show <strong>“Anonymous”</strong>
instead of the name, club and place of residence, and we also hide the <strong>start number</strong>
(in the Netherlands it stays the same for years, so leaving it visible would still make you
identifiable). The data itself is retained — it is purely a <strong>display choice</strong> and
fully reversible.</p>
<ul>
    <li><strong>Around the competition day</strong> (from the day before through the day after)
        we do show the name <strong>and start number</strong>, because the start and heat line-up is
        operationally needed then. Outside that window, and in the permanent results and
        series-standings archive, you remain anonymous. You are also <strong>not findable by
        name</strong> in the public search.</li>
    <li><strong>Setting it yourself</strong>: via your personal “My InlineComp” profile (§1d), or
        — without a profile — by e-mailing us, and we set the choice for you
        (and can lift it again at your request).</li>
    <li><strong>Letting specific people follow you</strong>: someone who still wants to follow you
        (for example a parent or coach) can do so with a <strong>personal, secret follow-ID</strong>
        that you share yourself; only someone with that ID sees your name. You can renew this
        follow-ID at any time, after which earlier followers no longer have access.</li>
    <li><strong>Import</strong>: if a data source (for example the KNSB) indicates that a skater
        wishes to be anonymous, we adopt that choice on import.</li>
</ul>
<p>This is a <strong>restriction of publication</strong>: you object (Article 21 GDPR) to
your name being shown publicly, and we honour that by shielding you publicly while the
sporting result stays intact. This choice is separate from the irreversible erasure in
section 9: with “publicly anonymous” your data is retained and you can reverse the choice.</p>

<h2>1f. Minor (under-age) skaters</h2>
<p>The majority of participants are <strong>minors</strong>. The GDPR grants children specific
protection (recital 38 GDPR). We take this into account as follows:</p>
<ul>
    <li>For minors we process the <strong>same limited, sport-functional data</strong> as for
        adults (see §1) — no more. We do <strong>no marketing, profiling or tracking</strong>
        — of anyone, so not of children either.</li>
    <li>We mainly receive the data from the <strong>KNSB</strong> (see §4); that is where the
        membership relationship lies, entered into at registration by the member or their
        parent/guardian. We use the data solely for the purposes in §2.</li>
    <li>In the balancing test for our legal basis (legitimate interest, §3) we explicitly weigh that
        many data subjects are children: we keep processing minimal and offer the option to shield the
        name publicly (<strong>publicly anonymous</strong>, §1e).</li>
    <li>The <strong>optional</strong> choices — a personal profile (§1d), publicly anonymous
        (§1e) and push notifications (§1c) — should for a minor be made and managed
        <strong>by the parent/guardian</strong>. For push notifications, which are based on
        consent, the parent/guardian gives that consent for skaters under 16 (Article 5 of the
        Dutch GDPR Implementation Act).</li>
    <li>The <strong>rights</strong> in §9 (access, correction, erasure/anonymisation, objection) may
        be exercised on a minor's behalf by the parent/guardian.</li>
</ul>

<h2>2. Why do we process this data?</h2>
<ul>
    <li>Supporting clubs and organisers in correctly organising and running competitions
        (start lists, timekeeping, results, standings).</li>
    <li>To contribute to competition organisation within the context of the KNSB as governing body.</li>
    <li>To maintain a historical results archive for participants, clubs
        and the federation.</li>
    <li>To perform <strong>aggregated statistics and trend/quality analysis</strong> for the
        sport and the competition organisation (for example participation and retention figures
        across several years). This uses data we already process for the purposes above — we do
        not collect anything extra for it — and the outcomes are anonymised/aggregated so that
        individual skaters cannot be identified from them.</li>
</ul>

<h2>3. Legal basis</h2>
<p>Processing takes place on the basis of <strong>legitimate interest</strong>
(Article 6(1)(f) GDPR): without this data no fair competition can be organised, results
cannot be published, and the sporting history cannot be preserved and made available for
skaters, clubs and the sport. This concerns limited, sport-functional data that is
customarily public in this sport, and this interest is not disproportionate to participants'
privacy. In this balancing we take particular account of the fact that many participants are
minors (see section 1f), and we offer the option to shield the name publicly (section 1e).</p>
<p>For coach accounts and push notifications, <strong>consent</strong> (Article 6(1)(a)
GDPR) additionally applies as the legal basis — see sections 1b and 1c.</p>

<h2>4. Source of the data</h2>
<p>We receive personal data from the following sources:</p>
<ul>
    <li><strong>KNSB</strong>: via the KNSB's official registration system (API), when a
        skater registers for a competition. This is the main source.</li>
    <li><strong>Organising club or organisation</strong>: for competitions not (fully) run
        through the KNSB, the organiser may supply the participant list as a CSV file. We only
        take over the data listed in section 1; other columns are not imported.</li>
    <li><strong>Historical results</strong>: for season or multi-year standings we may import
        old paper or PDF result sheets via an import tool, in some cases assisted by an AI
        service for text recognition (see §5b).</li>
    <li><strong>The skater or parent/guardian</strong>: only for optional requests, such as
        requesting a personal profile (§1d) or public anonymity (§1e), and when creating a
        coach account (§1b) or enabling push notifications (§1c).</li>
</ul>
<p>We do not collect data about skaters from any other source.</p>

<h2>5. With whom do we share data?</h2>
<ul>
    <li><strong>KNSB</strong>: we exchange registration and results data with the KNSB as
        part of federation competitions.</li>
    <li><strong>The public (results)</strong>: names, clubs, start numbers
        and finishing times are published on our public results page, as is
        customary in the sport — except for skaters who have chosen to be
        <strong>publicly anonymous</strong>, who are shown as “Anonymous” (see §1e).</li>
    <li><strong>AI provider (Anthropic)</strong>: see §5b for details.</li>
    <li><strong>Push services (Google/Mozilla/Apple)</strong>: solely to deliver
        push notifications you enabled yourself — see §1c.</li>
    <li><strong>Hosting provider (iFastNet Ltd)</strong>: see §5c for details.</li>
    <li><strong>Geo-IP service (ip-api.com – Artia International S.R.L., Romania)</strong>:
        solely to convert an IP address into an approximate location (country/city) for the
        login log at sign-in — see §5d.</li>
    <li>We do <strong>not</strong> sell data and do not share it with third
        parties beyond the above.</li>
</ul>

<h2>5b. Use of AI services (Anthropic Claude)</h2>
<p>For two specific administrative tasks we call the AI service
<strong>Anthropic Claude</strong> via their API:</p>
<ul>
    <li><strong>Historical results import</strong>: when importing printed
        or PDF result sheets from previous seasons, Claude helps recognise
        and structure names, times, categories and finishing positions.
        During this process the relevant text fragments (names, times, etc.)
        are temporarily sent to Anthropic for recognition.</li>
    <li><strong>Translation of announcements</strong>: titles and bodies of
        public announcements (e.g. “Schedule running 15 min late”) are
        translated by Claude into English, German and French. These texts
        generally contain no personal data.</li>
</ul>
<p><strong>Important notes:</strong></p>
<ul>
    <li>Anthropic is a US company based in San Francisco (USA). The transfer
        to the US is based on <em>Standard Contractual Clauses (SCCs)</em>
        as provided for under the GDPR, supplemented by the Data Processing
        Addendum Anthropic offers its business/API customers.</li>
    <li>According to Anthropic's privacy policy, API data is <strong>not used
        to train</strong> their AI models. Data may be retained briefly for
        abuse-monitoring purposes (by default up to 30 days).</li>
    <li>We use the AI <strong>exclusively</strong> for the two tasks listed
        above — not for any other processing of personal data.</li>
    <li>The historical import is an administrative action (not automated
        processing); an administrator decides per import whether the text
        is sent to the AI.</li>
</ul>

<h2>5c. Server logs &amp; Hosting</h2>
<p>Our website is hosted by <strong>iFastNet Ltd</strong> (United Kingdom). When you visit
our website, the web server automatically stores technical information in server log files
(Raw Access Logs). This includes your IP address, browser type, the page requested, and the
date/time of the visit.</p>
<ul>
    <li><strong>Legal basis &amp; purpose</strong>: this processing is based on our legitimate
        interest (Article 6(1)(f) GDPR) to technically secure the website, detect errors, and
        counter abuse or cyberattacks.</li>
    <li><strong>Transfer outside the EU</strong>: because our hosting provider is based in the
        United Kingdom, this transfer takes place without additional safeguards (such as SCCs),
        on the basis of the European Commission's adequacy decision for the United Kingdom (last
        renewed until December 2031). The UK location has been verified via the RIPE network
        registry.</li>
    <li><strong>Retention period</strong>: these technical server logs are automatically
        overwritten or deleted via the cPanel system within 24 hours to a maximum of 30 days,
        unless needed longer for a specific security investigation.</li>
    <li>These logs are not linked to a user account and are not used for tracking — see also the
        “anonymous visit statistics” on the public page, which separately store no IP addresses.</li>
</ul>
<p><strong>Anonymous visit statistics (public page):</strong> on the public pages we count how
many visitors are active at the same time, so we can monitor server load and step in if
needed to keep the competition running. For this we store per visit session only
a technical session ID, a browser/device identifier (user agent) and timestamps —
<strong>no IP address and no name or other personal data</strong>. To avoid counting visits
within one session twice, we place a small session cookie (<code>ICPUB</code>) on your device;
it disappears when you close the browser and is not used to track your behaviour across websites.</p>

<h2>5d. Login log &amp; location lookup</h2>
<p>For security we keep a login log of sign-in and sign-out events by <strong>administrators,
coaches and jury</strong> (not ordinary visitors). Per event we record: timestamp, IP address,
an approximate location (country and city) and a short browser/device identifier. Failed
sign-in attempts are also logged, to detect abuse and brute-force attacks.</p>
<ul>
    <li><strong>Legal basis &amp; purpose</strong>: legitimate interest (Article 6(1)(f) GDPR)
        — security and abuse detection.</li>
    <li><strong>Location lookup</strong>: to convert the IP address into country/city we use the
        geo-IP service <strong>ip-api.com</strong>, provided by <strong>Artia International
        S.R.L. (Bucharest, Romania)</strong>. This processor is EU-based and subject to the GDPR;
        no transfer outside the EU takes place. We send only the IP address (no name or skater
        data) and store only the derived country/city.</li>
    <li><strong>Retention</strong>: login-log entries are automatically deleted after 30 days.</li>
</ul>

<h2>6. Where is the data stored?</h2>
<p>All our competition, account and technical data (sections 1, 1b, 1c, 5c) is stored on the
same web server at our hosting provider, <strong>iFastNet Ltd in the United Kingdom</strong>.
Access is limited to administrators of <?= htmlspecialchars($ORG_NAAM) ?> via
password-protected accounts.</p>
<p>One exception: <strong>AI processing</strong> (see §5b) takes place on Anthropic's servers
in the United States.</p>

<h2>7. Retention period</h2>
<p>We retain personal data for as long as is necessary for the purpose
for which it was collected:</p>
<ul>
    <li><strong>Active competition data</strong>: during the current season
        and two calendar years thereafter, for season and multi-year standings.</li>
    <li><strong>Historical results</strong>: results and rankings are
        retained indefinitely as part of the sport-historical archive,
        linked via an internal ID. On request we remove the name and other
        personal data (including the licence link) so that only the nameless
        result under that internal ID remains, which only has meaning within our
        own system (see §9).</li>
    <li><strong>Administrator login data</strong>: as long as the account is
        active; inactive accounts are removed no later than 12 months after
        last login.</li>
    <li><strong>AI processing</strong>: see §5b — data sent to Anthropic
        falls under their retention policy (by default up to 30 days for
        abuse-monitoring, not used for training).</li>
    <li><strong>Push subscriptions</strong>: as long as you keep notifications
        enabled; they are deleted as soon as you turn them off or the subscription expires.</li>
    <li><strong>Server log files</strong>: 24 hours to a maximum of 30 days, unless needed
        longer for a security investigation — see section 5c.</li>
    <li><strong>Login log (admin/coach/jury)</strong>: 30 days — see section 5d.</li>
    <li><strong>Coach accounts</strong>: for as long as the account exists; you can delete it
        yourself and it expires automatically after one year without login — see section 1b.</li>
    <li><strong>Personal profile ("My InlineComp")</strong>: the username and encrypted PIN are
        kept for as long as the profile exists; it is deleted at the skater's request or by
        us. The underlying competition results remain as part of the historical archive
        — see section 1d.</li>
    <li><strong>Publicly-anonymous choice</strong>: the preference (and its follow-ID) is kept for
        as long as it applies; you can lift it yourself or via us — see section 1e.</li>
</ul>

<h2>8. Security</h2>
<ul>
    <li>Traffic between browser and server is over HTTPS.</li>
    <li>Administrator passwords are stored encrypted (bcrypt hash); we
        cannot read passwords.</li>
    <li>Only authorised administrators have access to personal data.</li>
    <li>Database traffic uses prepared statements to prevent SQL injection.</li>
    <li>The API key for the AI service (Anthropic) is stored in a
        server-config file outside the web root and is not accessible to
        third parties.</li>
</ul>

<h2>9. Your rights</h2>
<p>Under the GDPR you have the following rights:</p>
<ul>
    <li><strong>Access</strong> — you may request what data we process about
        you.</li>
    <li><strong>Rectification</strong> — correction of inaccurate data.
        Please note: basic data (name, licence number, club) comes from the
        KNSB; we are happy to correct it, but you are also asked to update
        it at the KNSB so it does not come in incorrectly again on future
        registrations.</li>
    <li><strong>Erasure / anonymisation</strong> — you may request erasure.
        To keep the sporting history and standings intact, we replace your
        name with “Removed” and <strong>erase your other personal data</strong>
        — place of residence, nationality, club/team, sponsor and start
        number — <strong>plus all your external links</strong> (including your
        KNSB licence). What remains is the nameless competition result (category
        and times), linked via an internal ID that only has meaning within our
        own system. Strictly speaking this is pseudonymisation: we ensure that
        the remaining sport-technical data can no longer reasonably be traced
        back to you as an individual, not even in combination with an external
        membership database.</li>
    <li><strong>Objection and restriction</strong> — you may object to the
        processing (including the AI processing in §5b) or request
        temporary restriction. If you don't want to disappear from the results
        but do want to shield your name publicly, you can choose
        <strong>publicly anonymous</strong> (see §1e).</li>
    <li><strong>Data portability</strong> — you may request an export of
        your data in a common format.</li>
    <li><strong>Lodging a complaint</strong> — you have the right to lodge
        a complaint with the Dutch Data Protection Authority
        (<a href="https://autoriteitpersoonsgegevens.nl" target="_blank"
            rel="noopener">autoriteitpersoonsgegevens.nl</a>) or your
        national supervisory authority.</li>
</ul>

<h2>10. Contact</h2>
<div class="contact">
    <p>Privacy questions or requests can be sent to:<br>
        <strong><?= htmlspecialchars($ORG_NAAM) ?></strong><br>
        Run by Geert de Vries (volunteer)<br>
        E-mail: <a href="mailto:<?= htmlspecialchars($ORG_EMAIL) ?>"><?= htmlspecialchars($ORG_EMAIL) ?></a>
        <?php if ($ORG_ADRES): ?><br>Address: <?= htmlspecialchars($ORG_ADRES) ?><?php endif; ?>
    </p>
    <p>We respond to your request within four weeks. To prevent abuse we
        may ask you to prove your identity, for example by logging in to your personal
        profile or through confirmation by your club.</p>
</div>

<h2>11. Changes to this statement</h2>
<p>This privacy statement may be updated if regulations or our practices
change. The most recent version is always on this page, with the
“last updated” date at the top.</p>

<a href="public/" class="terug" onclick="if(history.length>1){history.back();return false;}">← Back</a>

</div>
</body>
</html>
