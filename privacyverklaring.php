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
$LAATSTE_UPDATE_NL = '25 augustus 2026';
$LAATSTE_UPDATE_EN = '25 August 2026';
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

<p><?= htmlspecialchars($ORG_NAAM) ?> (hierna: “wij”) is een vrijwilligersinitiatief
van Geert de Vries en gebruikt InlineComp, een digitaal systeem voor de organisatie
van inline-skate-wedstrijden. In deze verklaring leggen we uit welke persoonsgegevens
wij verwerken, met welk doel, op welke grondslag en welke rechten je daarbij hebt.
Deze verklaring is afgestemd op de Algemene Verordening Gegevensbescherming (AVG/GDPR).</p>

<div style="background:#f4f8fb;border-left:4px solid #1a3a5c;padding:.8rem 1rem;margin:1.5rem 0;">
<h2 style="margin-top:0;border:0;padding-bottom:0;">In het kort</h2>
<p><em>Dit is een samenvatting in gewone taal. De volledige, juridisch precieze tekst
staat hieronder — bij twijfel geldt die volledige tekst.</em></p>
<ul>
    <li>We verwerken alleen wat nodig is om wedstrijden te organiseren: je naam,
        KNSB-licentienummer, vereniging, startnummer en je resultaten. Deze gegevens
        krijgen we van de KNSB zelf, niet van jou.</li>
    <li>We slaan <strong>geen</strong> e-mailadres, telefoonnummer, adres of
        geboortedatum van rijders op.</li>
    <li>Uitslagen worden openbaar gepubliceerd, zoals gebruikelijk in de sport.</li>
    <li>Als coach kun je vrijwillig een account maken; als rijder of coach kun je
        vrijwillig pushmeldingen aanzetten. Beide zijn optioneel en je kunt ze zelf
        weer uitzetten.</li>
    <li>Voor het digitaliseren van oude papieren uitslagen en voor het vertalen van
        mededelingen gebruiken we soms AI (Anthropic Claude, een Amerikaans bedrijf)
        — met de wettelijk vereiste waarborgen.</li>
    <li>Onze website draait bij een hostingpartij in het Verenigd Koninkrijk;
        technische bezoekgegevens (zoals IP-adres) worden daar kort bewaard voor
        beveiliging.</li>
    <li>Je kunt altijd opvragen welke gegevens we van je hebben, ze laten corrigeren,
        of vragen om verwijdering (waarbij we je naam vervangen door “Verwijderd” zodat
        de wedstrijdhistorie klopt blijft).</li>
</ul>
<p>Vragen? Mail naar <a href="mailto:<?= htmlspecialchars($ORG_EMAIL) ?>"><?= htmlspecialchars($ORG_EMAIL) ?></a>.</p>
</div>

<h2>0. Wie is verantwoordelijk voor deze verwerking?</h2>
<p>InlineComp wordt beheerd door Geert de Vries, als vrijwilliger en zonder dat hier
een bedrijf of rechtspersoon achter staat. Voor vragen over privacy kun je terecht bij
de contactgegevens in §10.</p>

<h2>1. Welke gegevens verwerken wij?</h2>
<p>Van elke rijder die deelneemt aan een wedstrijd die wij organiseren
verwerken wij de volgende gegevens, zoals die door de KNSB via hun
inschrijf-API aan ons worden verstrekt:</p>
<ul>
    <li>Naam (volledige naam, eventueel roepnaam)</li>
    <li>Geslacht, KNSB-categorie</li>
    <li>KNSB-licentienummer / relatienummer</li>
    <li>Vereniging en verenigingscode</li>
    <li>Optioneel: sponsor, woonplaats, nationaliteit</li>
    <li>Startnummer en, bij gebruik, transponder-code voor tijdregistratie</li>
</ul>
<p>Daarnaast leggen wij per wedstrijd de sportieve resultaten vast (tijden,
sancties, klassering). Deze zijn aan het licentienummer gekoppeld.</p>
<p>Wij verwerken <strong>geen</strong> e-mailadressen, telefoonnummers, adressen,
geboortedatum of geboortejaar van rijders. Wél bewaren wij de KNSB-<strong>categorie</strong>.
Die hoort bij een leeftijdsgroep, waaruit een leeftijdsindicatie — en over meerdere
seizoenen, via de jaarlijkse categorie-doorschuiving, een geschat geboortejaar-bereik —
is af te leiden. Wij gebruiken deze categorie-indicatie om te controleren of een
licentienummer over seizoenen heen bij dezelfde rijder hoort (plausibiliteit en juiste
klassementen).</p>

<h2>1b. Coach-accounts (optioneel)</h2>
<p>Coaches kunnen — geheel vrijwillig — een persoonlijk account aanmaken in de
coach-app. Zonder account is het gebruik anoniem; met een account verwerken wij:</p>
<ul>
    <li>je <strong>naam</strong> en <strong>e-mailadres</strong> (als inlog- en herkenningsgegeven, en om je account-berichten te sturen — bijvoorbeeld goedkeuring, afwijzing of een wachtwoord-reset);</li>
    <li>de <strong>club of het team</strong> waarvoor je coacht (ter beoordeling van je aanvraag);</li>
    <li>je zelf samengestelde <strong>atletenlijst</strong> (licentienummers van rijders die je wilt volgen).</li>
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
    <li>de <strong>licentienummers van de rijders die je volgt</strong> (zodat we alleen
        relevante meldingen sturen), je gekozen <strong>taal</strong> en welke meldingtypen
        je aan hebt staan;</li>
    <li>een korte <strong>browser-/apparaataanduiding</strong> (user-agent) voor beheer en opschoning.</li>
</ul>
<p>De grondslag is jouw <strong>toestemming</strong> — je zet de meldingen zelf aan en kunt
ze op elk moment weer uitzetten, waarna het abonnement wordt verwijderd. In de publieke app
worden je gevolgde rijders normaal alléén lokaal op je toestel bewaard; <strong>alleen</strong>
wanneer je pushmeldingen aanzet, worden die licentienummers naar onze server gestuurd om de
meldingen te kunnen richten. Aan een publiek push-abonnement is <strong>geen naam of
e-mailadres</strong> gekoppeld. Verlopen of ingetrokken abonnementen worden automatisch verwijderd.</p>
<p><strong>Bezorging via je browser-push-dienst:</strong> om de melding op je toestel te
krijgen, loopt deze via de push-dienst van je browser-leverancier — Google (Android/Chrome),
Mozilla (Firefox) of Apple (Safari/iPhone). Zij ontvangen het technische endpoint en de
(versleutelde) melding om deze te bezorgen; wij delen hierbij <strong>geen namen of
rijdersgegevens</strong>, en de inhoud is versleuteld tussen ons en jouw apparaat.</p>

<h2>2. Waarom verwerken wij deze gegevens?</h2>
<ul>
    <li>Het correct organiseren en uitvoeren van wedstrijden (startlijsten,
        tijdregistratie, uitslag, klassement).</li>
    <li>Het bijdragen aan de wedstrijdorganisatie binnen de context van de KNSB
        als overkoepelende bond.</li>
    <li>Het bewaren van een historisch uitslagoverzicht voor deelnemers,
        verenigingen en de bond.</li>
</ul>

<h2>3. Grondslag</h2>
<p>De verwerking vindt plaats op grond van <strong>gerechtvaardigd belang</strong>
(art. 6 lid 1 sub f AVG): zonder deze gegevens kunnen wij geen eerlijke wedstrijd
organiseren of uitslagen publiceren, en dit belang is niet onevenredig ten opzichte
van de privacy van deelnemers — het gaat om beperkte, sport-functionele gegevens die
in deze sport gebruikelijk openbaar worden gemaakt.</p>
<p>Voor de coach-accounts en pushmeldingen geldt daarnaast <strong>toestemming</strong>
(art. 6 lid 1 sub a AVG) als grondslag — zie §1b en §1c.</p>
<p><em>Toelichting: er bestaat geen formele overeenkomst of opdracht tussen ons en de
KNSB die onze verwerking regelt. Wij zijn een zelfstandig, vrijwillig opererende
wedstrijdorganisator die gegevens van de KNSB ontvangt om wedstrijden te faciliteren.
Om die reden baseren wij ons niet op “uitvoering van een overeenkomst” maar uitsluitend
op gerechtvaardigd belang en toestemming.</em></p>

<h2>4. Bron van de gegevens</h2>
<p>De persoonsgegevens ontvangen wij rechtstreeks van de KNSB via hun
officiële inschrijf-systeem op het moment dat een rijder zich voor onze
wedstrijd inschrijft. Wij verzamelen zelf géén gegevens van rijders.</p>
<p>Voor het reconstrueren van historische uitslagen (voor seizoens- of
meerjarenklassementen) kunnen wij oude papieren of PDF-uitslagen handmatig
inlezen via een import-tool. Hierbij gebruiken wij in sommige gevallen
een AI-dienst om tekstherkenning te helpen — zie §5b hieronder.</p>

<h2>5. Met wie delen wij de gegevens?</h2>
<ul>
    <li><strong>KNSB</strong>: wij wisselen inschrijf- en uitslagdata uit met
        de KNSB als onderdeel van de bondswedstrijden.</li>
    <li><strong>Publiek (uitslagen)</strong>: namen, verenigingen, startnummers
        en eindtijden worden openbaar gepubliceerd op onze uitslagpagina, zoals
        gangbaar in de sport.</li>
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
Koninkrijk</strong>. Toegang is beperkt tot beheerders van <?= htmlspecialchars($ORG_NAAM) ?>
via wachtwoord-beveiligde accounts.</p>
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
        gekoppeld aan het licentienummer. Op verzoek anonimiseren wij de naam
        en overige persoonsgegevens zodat alleen het licentienummer overblijft
        (zie §9).</li>
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
        óók bij de KNSB door te voeren zodat hij bij volgende inschrijvingen
        niet opnieuw verkeerd binnenkomt.</li>
    <li><strong>Verwijdering / anonimisering</strong> — je kunt vragen om
        verwijdering. Om de sportieve geschiedenis en klassementen intact te
        houden vervangen wij jouw naam en overige persoonsgegevens door
        “Verwijderd”; jouw licentienummer blijft als pseudonieme sleutel
        aan de historische uitslagen gekoppeld. Zonder toegang tot de
        KNSB-ledendatabase is het licentienummer alléén niet herleidbaar
        naar jou.</li>
    <li><strong>Bezwaar en beperking</strong> — je kunt bezwaar maken tegen
        de verwerking (inclusief de AI-verwerking uit §5b) of vragen om
        tijdelijke beperking.</li>
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
        kunnen wij je vragen jouw identiteit aan te tonen (bijvoorbeeld via
        jouw KNSB-licentienummer).</p>
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

<p><?= htmlspecialchars($ORG_NAAM) ?> (referred to as “we”) is a volunteer initiative
run by Geert de Vries, using InlineComp, a digital system for organising inline-skating
competitions. This statement explains what personal data we process, for what purpose,
on what legal basis, and what rights you have. This statement aligns with the General
Data Protection Regulation (GDPR).</p>

<div style="background:#f4f8fb;border-left:4px solid #1a3a5c;padding:.8rem 1rem;margin:1.5rem 0;">
<h2 style="margin-top:0;border:0;padding-bottom:0;">In short</h2>
<p><em>This is a plain-language summary. The full, legally precise text is below — in
case of doubt, that full text applies.</em></p>
<ul>
    <li>We only process what's needed to organise competitions: your name, KNSB licence
        number, club, start number and your results. We get this data from the KNSB
        itself, not from you.</li>
    <li>We do <strong>not</strong> store skaters' e-mail address, phone number, home
        address or date of birth.</li>
    <li>Results are published publicly, as is customary in the sport.</li>
    <li>As a coach you can voluntarily create an account; as a skater or coach you can
        voluntarily enable push notifications. Both are optional and you can turn them off
        yourself.</li>
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

<h2>0. Who is responsible for this processing?</h2>
<p>InlineComp is run by Geert de Vries, as a volunteer and without any company or legal
entity behind it. For privacy questions, see the contact details in section 10.</p>

<h2>1. What data do we process?</h2>
<p>For each skater participating in a competition that we organise we
process the following data, as supplied to us by the KNSB (Dutch skating
federation) via their registration API:</p>
<ul>
    <li>Name (full name, optionally nickname)</li>
    <li>Gender, KNSB category</li>
    <li>KNSB licence/relation number</li>
    <li>Club name and code</li>
    <li>Optional: sponsor, place of residence, nationality</li>
    <li>Start number and, where used, transponder code for timekeeping</li>
</ul>
<p>In addition, for each competition we record sporting results (times,
sanctions, ranking). These are linked to the licence number.</p>
<p>We do <strong>not</strong> process e-mail addresses, phone numbers, home addresses,
date of birth or year of birth of skaters. We do store the KNSB <strong>category</strong>.
A category corresponds to an age group, from which an age indication — and across multiple
seasons, via the annual category progression, an approximate year-of-birth range — can be
derived. We use this category indication to check whether a licence number belongs to the
same skater across seasons (plausibility and correct standings).</p>

<h2>1b. Coach accounts (optional)</h2>
<p>Coaches may — entirely voluntarily — create a personal account in the coach app.
Without an account, use is anonymous; with an account we process:</p>
<ul>
    <li>your <strong>name</strong> and <strong>e-mail address</strong> (as login and identification, and to send you account-related messages — for example approval, rejection or a password reset);</li>
    <li>the <strong>club or team</strong> you coach for (to assess your request);</li>
    <li>your self-curated <strong>athlete list</strong> (licence numbers of skaters you wish to follow).</li>
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
    <li>the <strong>licence numbers of the skaters you follow</strong> (so we only send
        relevant notifications), your chosen <strong>language</strong> and which
        notification types you have enabled;</li>
    <li>a short <strong>browser/device identifier</strong> (user agent) for management and cleanup.</li>
</ul>
<p>The legal basis is your <strong>consent</strong> — you turn the notifications on
yourself and can turn them off again at any time, after which the subscription is
deleted. In the public app the skaters you follow are normally kept <strong>only
locally</strong> on your device; <strong>only</strong> when you enable push notifications
are those licence numbers sent to our server so notifications can be targeted. A public
push subscription has <strong>no name or e-mail address</strong> attached to it. Expired
or revoked subscriptions are deleted automatically.</p>
<p><strong>Delivery via your browser's push service:</strong> to reach your device, a
notification is routed through the push service of your browser vendor — Google
(Android/Chrome), Mozilla (Firefox) or Apple (Safari/iPhone). They receive the technical
endpoint and the (encrypted) notification in order to deliver it; we share <strong>no names
or skater data</strong> with them, and the content is encrypted between us and your device.</p>

<h2>2. Why do we process this data?</h2>
<ul>
    <li>To correctly organise and run competitions (start lists, timekeeping,
        results, standings).</li>
    <li>To contribute to competition organisation within the context of the KNSB as governing body.</li>
    <li>To maintain a historical results archive for participants, clubs
        and the federation.</li>
</ul>

<h2>3. Legal basis</h2>
<p>Processing takes place on the basis of <strong>legitimate interest</strong>
(Article 6(1)(f) GDPR): without this data we cannot organise a fair competition or publish
results, and this interest is not disproportionate to participants' privacy — it concerns
limited, sport-functional data that is customarily made public in this sport.</p>
<p>For coach accounts and push notifications, <strong>consent</strong> (Article 6(1)(a)
GDPR) additionally applies as the legal basis — see sections 1b and 1c.</p>
<p><em>Note: there is no formal agreement or mandate between us and the KNSB governing our
processing. We are an independent, volunteer-run competition organiser that receives data
from the KNSB to facilitate competitions. For this reason we do not rely on “performance of
a contract” but solely on legitimate interest and consent.</em></p>

<h2>4. Source of the data</h2>
<p>We receive personal data directly from the KNSB via their official
registration system when a skater registers for our competition. We do not
collect data from skaters ourselves.</p>
<p>To reconstruct historical results (for season or multi-year standings)
we may manually import old paper or PDF result sheets via an import tool.
In some cases this uses an AI service to assist with text recognition —
see §5b below.</p>

<h2>5. With whom do we share data?</h2>
<ul>
    <li><strong>KNSB</strong>: we exchange registration and results data with
        the KNSB as part of federation competitions.</li>
    <li><strong>The public (results)</strong>: names, clubs, start numbers
        and finishing times are published on our public results page, as is
        customary in the sport.</li>
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
        linked to the licence number. On request we anonymise the name and
        other personal data so that only the licence number remains
        (see §9).</li>
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
        name and other personal data with “Removed”; your licence number
        remains as a pseudonymous key linked to the historical results.
        Without access to the KNSB membership database, the licence number
        alone is not traceable to you.</li>
    <li><strong>Objection and restriction</strong> — you may object to the
        processing (including the AI processing in §5b) or request
        temporary restriction.</li>
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
        may ask you to prove your identity (for example via your KNSB
        licence number).</p>
</div>

<h2>11. Changes to this statement</h2>
<p>This privacy statement may be updated if regulations or our practices
change. The most recent version is always on this page, with the
“last updated” date at the top.</p>

<a href="public/" class="terug" onclick="if(history.length>1){history.back();return false;}">← Back</a>

</div>
</body>
</html>
