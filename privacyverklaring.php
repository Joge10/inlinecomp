<?php
// Publieke privacyverklaring — geen login vereist.
// Vul de ORG_NAAM / ORG_EMAIL / ORG_ADRES aan met jouw organisatiegegevens
// vóór je dit live zet. De tekst is bedoeld als uitgangspunt; laat 'm bij
// twijfel toetsen door iemand met AVG-ervaring of een jurist.

$ORG_NAAM   = 'InlineComp';                 // TODO: aanpassen naar jouw vereniging / beheerder
$ORG_EMAIL  = 'inlinecomp@devriesen.com';   // TODO: e-mailadres voor verzoeken
$ORG_ADRES  = '';                           // TODO: eventueel postadres
$LAATSTE_UPDATE = '24 april 2026';          // TODO: datum bij elke wijziging bijwerken
?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacyverklaring — <?= htmlspecialchars($ORG_NAAM) ?></title>
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
    </style>
</head>
<body>
<div class="privacy-wrap">

<h1>Privacyverklaring</h1>
<p class="meta">Laatst bijgewerkt: <?= htmlspecialchars($LAATSTE_UPDATE) ?></p>

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

<h2>5. Met wie delen wij de gegevens?</h2>
<ul>
    <li><strong>KNSB</strong>: wij wisselen inschrijf- en uitslagdata uit met
        de KNSB als onderdeel van de bondswedstrijden.</li>
    <li><strong>Publiek (uitslagen)</strong>: namen, verenigingen, startnummers
        en eindtijden worden openbaar gepubliceerd op onze uitslagpagina, zoals
        gangbaar in de sport.</li>
    <li>Wij verkopen géén gegevens en delen ze niet met derden buiten het
        bovenstaande.</li>
</ul>

<h2>6. Waar staan de gegevens?</h2>
<p>De gegevens staan op een webserver binnen de Europese Unie. Toegang is
beperkt tot beheerders van <?= htmlspecialchars($ORG_NAAM) ?> via
wachtwoord-beveiligde accounts.</p>

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
</ul>

<h2>8. Beveiliging</h2>
<ul>
    <li>Verkeer tussen browser en server verloopt via HTTPS.</li>
    <li>Wachtwoorden van beheerders worden versleuteld opgeslagen (bcrypt-hash);
        wij kunnen wachtwoorden niet uitlezen.</li>
    <li>Alleen geautoriseerde beheerders hebben toegang tot de persoonsgegevens.</li>
    <li>Database-verkeer gebruikt prepared statements om SQL-injectie te
        voorkomen.</li>
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
        de verwerking of vragen om tijdelijke beperking.</li>
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

</div>
</body>
</html>
