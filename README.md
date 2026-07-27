# InlineComp

Webgebaseerd wedstrijdbeheersysteem voor inline-skate-/skeelerwedstrijden op KNSB-niveau (baan en weg): van deelnemersimport en loting tot live tijdverwerking, uitslagen, klassementen en informatievoorziening voor jury, coaches, rijders en publiek.

Gebouwd in de vrije tijd, met AI-assistentie, en dit seizoen bij meerdere wedstrijden en kampioenschappen in productie gebruikt.

## Omgevingen

- **Beheer (Admin)** — import (KNSB-feed), tijdschema, loting, live tijdverwerking, uitslagen, klassementen en het wedstrijdprotocol.
- **Jury** (`/jury`) — tablet-app voor de wedstrijdjury: area of call, speaker, scheidsrechter, aankomst en starter (per wedstrijd afgeschermd met een jury-wachtwoord).
- **Public** (`/public`) — openbare, viertalige PWA voor publiek, ouders en rijders (programma, startlijsten, uitslagen, klassementen, live meelezend).
- **Coach** (`/coach`) — een coach volgt een eigen selectie rijders door de hele wedstrijd.
- **CSV-Monitor** — losse Windows-tool (HTA) die MyLaps/Orbit-tijden automatisch en veilig naar de server brengt.

## Techniek

- **PHP** + **MariaDB/MySQL** (PDO met prepared statements)
- **Vanilla JavaScript** (geen framework)
- Meertalig (NL/EN/DE/FR); Public en Coach zijn installeerbaar als PWA
- Draait op standaard (gedeelde) webhosting
- AVG: onomkeerbaar anonimiseren met behoud van wedstrijdhistorie, publieke privacyverklaring, audit-logboek voor anonimisaties en logins

## Configuratie

De databaseconfiguratie en secrets staan **bewust buiten** deze repository, in `config_inlinecomp.php` (één niveau boven de webroot). Dat bestand is niet meegeleverd; de API-endpoints verwachten een geïnitialiseerde `$pdo` (PDO-verbinding) uit die config.

Dit is de daadwerkelijke productiecodebase, geen kant-en-klaar installeerbaar pakket — draaien vergt een eigen database, config en (voor de tijdregistratie) de CSV-Monitor.

## Licentie

Dit project valt onder de **PolyForm Perimeter License 1.0.1** — zie [LICENSE.md](LICENSE.md).

Kort en onjuridisch samengevat: je mag de code bekijken, gebruiken, aanpassen en delen voor vrijwel elk doel — **behalve** om er een product mee te maken dat **concurreert** met InlineComp. Wil je een andere afspraak (bijvoorbeeld een ruimere of commerciële licentie)? Neem dan contact op.

## Bijdragen

Pull requests en ideeën zijn welkom. Door bij te dragen ga je ermee akkoord dat je bijdrage onder dezelfde licentie valt en dat de auteur (Geert de Vries) het recht behoudt het project — inclusief bijdragen — ook onder andere voorwaarden te licentiëren.

## Contact

Geert de Vries — inlinecomp@devriesen.com
