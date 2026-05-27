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
$LAATSTE_UPDATE_NL = '27 mei 2026';
$LAATSTE_UPDATE_EN = '27 May 2026';
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

<p><?= htmlspecialchars($ORG_NAAM) ?> (hierna: “wij”) gebruikt InlineComp, een
digitaal systeem voor de organisatie van inline-skate-wedstrijden. In deze
verklaring leggen we uit welke persoonsgegevens wij verwerken, met welk doel,
op welke grondslag en welke rechten je daarbij hebt. Deze verklaring is
afgestemd op de Algemene Verordening Gegevensbescherming (AVG/GDPR).</p>

<h2>1. Welke gegevens verwerken wij?</h2>
<p>Van elke rijder die deelneemt aan een wedstrijd die wij organiseren
verwerken wij de volgende gegevens, zoals die door de KNSB via hun
inschrijf-API aan ons worden verstrekt:</p>
<ul>
    <li>Naam (volledige naam, eventueel roepnaam)</li>
    <li>Geboortejaar (niet de volledige geboortedatum)</li>
    <li>Geslacht, KNSB-categorie</li>
    <li>KNSB-licentienummer / relatienummer</li>
    <li>Vereniging en verenigingscode</li>
    <li>Optioneel: sponsor, woonplaats, nationaliteit</li>
    <li>Startnummer en, bij gebruik, transponder-code voor tijdregistratie</li>
</ul>
<p>Daarnaast leggen wij per wedstrijd de sportieve resultaten vast (tijden,
sancties, klassering). Deze zijn aan het licentienummer gekoppeld.</p>
<p>Wij verwerken <strong>geen</strong> e-mailadressen, telefoonnummers,
adressen of volledige geboortedata van rijders.</p>

<h2>2. Waarom verwerken wij deze gegevens?</h2>
<ul>
    <li>Het correct organiseren en uitvoeren van wedstrijden (startlijsten,
        tijdregistratie, uitslag, klassement).</li>
    <li>Het voldoen aan verplichtingen en afspraken richting de KNSB
        als overkoepelende bond.</li>
    <li>Het bewaren van een historisch uitslagoverzicht voor deelnemers,
        verenigingen en de bond.</li>
</ul>

<h2>3. Grondslag</h2>
<p>De verwerking vindt plaats op grond van <em>gerechtvaardigd belang</em>
(art. 6 lid 1 sub f AVG): zonder deze gegevens kunnen wij geen eerlijke
wedstrijd organiseren of uitslagen publiceren. Voor KNSB-wedstrijden is er
daarnaast sprake van een <em>overeenkomst</em> tussen deelnemer en bond waar
wij als organisator onderdeel van zijn (art. 6 lid 1 sub b AVG).</p>

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
        Contractual Clauses (SCC's)</em> zoals voorzien onder de AVG.</li>
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

<h2>6. Waar staan de gegevens?</h2>
<p>De gegevens staan op een webserver binnen de Europese Unie. Toegang is
beperkt tot beheerders van <?= htmlspecialchars($ORG_NAAM) ?> via
wachtwoord-beveiligde accounts. AI-verwerking (zie §5b) gebeurt op servers
van Anthropic in de Verenigde Staten.</p>

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
        E-mail: <a href="mailto:<?= htmlspecialchars($ORG_EMAIL) ?>"><?= htmlspecialchars($ORG_EMAIL) ?></a>
        <?php if ($ORG_ADRES): ?><br>Adres: <?= htmlspecialchars($ORG_ADRES) ?><?php endif; ?>
    </p>
    <p>Wij reageren binnen vier weken op jouw verzoek. Om misbruik te voorkomen
        kunnen wij je vragen jouw identiteit aan te tonen (bijvoorbeeld via
        jouw KNSB-licentienummer en geboortejaar).</p>
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

<p><?= htmlspecialchars($ORG_NAAM) ?> (referred to as “we”) uses InlineComp,
a digital system for organising inline-skating competitions. This statement
explains what personal data we process, for what purpose, on what legal
basis, and what rights you have. This statement aligns with the General
Data Protection Regulation (GDPR).</p>

<h2>1. What data do we process?</h2>
<p>For each skater participating in a competition that we organise we
process the following data, as supplied to us by the KNSB (Dutch skating
federation) via their registration API:</p>
<ul>
    <li>Name (full name, optionally nickname)</li>
    <li>Year of birth (not the full date of birth)</li>
    <li>Gender, KNSB category</li>
    <li>KNSB licence/relation number</li>
    <li>Club name and code</li>
    <li>Optional: sponsor, place of residence, nationality</li>
    <li>Start number and, where used, transponder code for timekeeping</li>
</ul>
<p>In addition, for each competition we record sporting results (times,
sanctions, ranking). These are linked to the licence number.</p>
<p>We do <strong>not</strong> process e-mail addresses, phone numbers,
home addresses or full dates of birth of skaters.</p>

<h2>2. Why do we process this data?</h2>
<ul>
    <li>To correctly organise and run competitions (start lists, timekeeping,
        results, standings).</li>
    <li>To meet obligations and agreements with the KNSB as the governing body.</li>
    <li>To maintain a historical results archive for participants, clubs
        and the federation.</li>
</ul>

<h2>3. Legal basis</h2>
<p>Processing takes place on the basis of <em>legitimate interest</em>
(Article 6(1)(f) GDPR): without this data we cannot organise a fair
competition or publish results. For KNSB competitions there is also a
<em>contract</em> between the participant and the federation in which we
are involved as organiser (Article 6(1)(b) GDPR).</p>

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
        as provided for under the GDPR.</li>
    <li>According to Anthropic's privacy policy, API data is <strong>not used
        to train</strong> their AI models. Data may be retained briefly for
        abuse-monitoring purposes (by default up to 30 days).</li>
    <li>We use the AI <strong>exclusively</strong> for the two tasks listed
        above — not for any other processing of personal data.</li>
    <li>The historical import is an administrative action (not automated
        processing); an administrator decides per import whether the text
        is sent to the AI.</li>
</ul>

<h2>6. Where is the data stored?</h2>
<p>The data is stored on a web server within the European Union. Access is
limited to administrators of <?= htmlspecialchars($ORG_NAAM) ?> via
password-protected accounts. AI processing (see §5b) takes place on
Anthropic's servers in the United States.</p>

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
        E-mail: <a href="mailto:<?= htmlspecialchars($ORG_EMAIL) ?>"><?= htmlspecialchars($ORG_EMAIL) ?></a>
        <?php if ($ORG_ADRES): ?><br>Address: <?= htmlspecialchars($ORG_ADRES) ?><?php endif; ?>
    </p>
    <p>We respond to your request within four weeks. To prevent abuse we
        may ask you to prove your identity (for example via your KNSB
        licence number and year of birth).</p>
</div>

<h2>11. Changes to this statement</h2>
<p>This privacy statement may be updated if regulations or our practices
change. The most recent version is always on this page, with the
“last updated” date at the top.</p>

<a href="public/" class="terug" onclick="if(history.length>1){history.back();return false;}">← Back</a>

</div>
</body>
</html>
