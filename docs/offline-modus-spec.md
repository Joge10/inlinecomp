# InlineComp — Beschrijving van het systeem en een open vraagstuk

**Voor**: wie wil meedenken over hoe we van InlineComp een robuuster systeem
maken.
**Wat**: een beschrijving van wat InlineComp nu is, wat het wél en níet kan,
en wat de grootste zwakte is. Het doel is niet om hier een oplossing voor
te schetsen — dat is precies waar jouw ideeën welkom zijn.

---

## 1. Wat is InlineComp?

InlineComp is een webapplicatie voor het organiseren en publiceren van
inline-skate-wedstrijden. Vanuit de wedstrijddata van de KNSB worden
startlijsten gegenereerd, tijden en sancties vastgelegd, en uitslagen +
klassementen gepubliceerd. Een publieke pagina laat rijders, ouders en
coaches direct meekijken: startnummer invoeren en je eigen heats zien,
starttijden opvragen, uitslag volgen.

Het werkt voor één wedstrijd (JSC-1 — de jeugd-sprintwedstrijd) in de
praktijk goed. Op organisatorisch niveau vervangt het daarmee een stapel
papieren lijstjes en Excel-printouts.

## 2. Hoe is het gebouwd?

- **Server**: PHP + MySQL, gehost op een shared-hosting provider (Byethost).
- **Browser**: vanilla JavaScript, geen framework. Losse JS-modules per
  pagina. Progressive web app met een manifest.
- **Data-uitwisseling**: simpele JSON-API's (`api/*.php`), meestal
  `POST { action: '...', ... }` of `GET ?action=...`.
- **Authenticatie**: login met gebruikersnaam/wachtwoord, rollen (owner,
  admin, planner, timer, importer).
- **KNSB-koppeling**: imports van wedstrijd-inschrijvingen via hun API.

Architectuur in één zin: **alles loopt via de server**, de browser is
dun, er zit geen lokale state op het apparaat.

## 3. Wat kan InlineComp nu?

| Module | Doel | Gebruikt door |
|---|---|---|
| Importeren | KNSB-data ophalen, rijders/transponders synchroniseren | Organisator |
| Beheer | Organisaties, transponders, klassement-presets, rijderbeheer (AVG) | Organisator |
| Tijdschema | Per wedstrijd de rit-structuur opstellen | Organisator |
| Startlijsten | Per ronde genereren (loting, seeding, doorstroom) | Wedstrijdleiding |
| Live verwerking | Tijden + sancties invoeren tijdens de wedstrijd | Tijdwaarnemer |
| Uitslag | Per-afstand en per-DC klassementen doorrekenen | Wedstrijdleiding |
| Klassement (serie) | Seizoensklassement over meerdere wedstrijden | Organisator |
| Publiek (`/public`) | Rijders/ouders/coaches zien live hun heats/uitslagen | Iedereen |
| Coach-view (`/coach`) | Aparte weergave voor coaches met meerdere rijders | Coaches |

## 4. Hoe is de database opgebouwd?

In grote lijnen is de datamodel opgedeeld in vier niveau's:

**Meta-niveau** — vaste data die over meerdere wedstrijden bestaat:

- `organisaties` — wedstrijdorganiserende verenigingen
- `organisatie_transponders` — transponder-inventaris per vereniging
- `persons` — alle rijders (PK = KNSB licentienummer)
- `gebruikers` — accounts van InlineComp-beheerders
- `klassement_series` — seizoens- of meerdaagse klassementen
- `klassement_presets` — punten-tellingen (welk schema voor welke categorie)

**Wedstrijd-niveau** — per wedstrijd:

- `competitions` — de wedstrijd zelf (KNSB UUID, naam, datum, locatie)
- `entries` — inschrijvingen per wedstrijd per rijder
- `transponders` — welke transponder bij welke rijder in welke wedstrijd
- `distance_combinations` — categorie-samenstellingen
  (bv. "H meisjes pupillen A/B samen")
- `distances` — welke afstand binnen een DC
  (bv. "500m sprint" binnen die DC)
- `tijdschema_*` — welke ronde wanneer start, per categorie welk ronde-type

**Wedstrijd-verloop** — muteert tijdens de dag:

- `heats` — concrete races (per ronde, per afstand, per DC)
- `heat_entries` — wie rijdt in welke heat
- `results` — tijden en sancties per heat_entry

**Archief** — ná de wedstrijd:

- `uitslag_afstand` — eindstand per rijder per afstand
- `uitslag_klassement` — eindstand per rijder per DC
- Bewust gedenormaliseerd: de wedstrijd-naam/datum staan dubbel in deze
  tabellen, zodat de uitslag bewaard blijft als de wedstrijd ooit wordt
  verwijderd.

Meer details staan in de `db/*.sql`-bestanden — elk een tabel, met
commentaar bovenaan wat-het-is en waarom-het-zo-is.

## 5. Wat werkt er goed?

- De publieke weergave is een hit. Mensen reageren enthousiast — ouders
  kunnen hun kind volgen, coaches meerdere rijders tegelijk.
- Prepped-data flow werkt: van KNSB-import tot en met startlijst-generatie
  loopt het strak.
- Het AVG-verhaal is op orde: privacyverklaring, anonimiseer-functie met
  behoud van wedstrijdhistorie, auditlog.

## 6. Waar knelt het?

Op **één** fundamenteel punt: InlineComp is volledig internet-afhankelijk.
Zonder verbinding met de server:

- Geen startlijsten tonen
- Geen tijden invoeren
- Geen heats genereren
- Geen klassementen doorrekenen
- Geen publieke weergave voor bezoekers

In de praktijk is dit op een wedstrijddag levensgevaarlijk. Een haperende
4G-router, een wifi die onder de druk bezwijkt, een stroomstoring van twee
minuten — en de wedstrijd kan niet door. Een Excel-met-macro-systeem zoals
dat nu bij KNSB-wedstrijden draait, kent dit probleem simpelweg niet:
Excel werkt, met of zonder internet.

## 7. Waarom dit niet makkelijk op te lossen is

Er zijn allerlei voor-de-hand-liggende oplossingen ("bouw een PWA!",
"gebruik Service Workers!") die in theorie werken, maar in de praktijk
elk hun eigen trade-offs hebben:

- **Wedstrijdlogica is niet triviaal**: loting, seeding, doorstroomregels,
  snake-seeding naar finales, sanctie-verwerking. Dat moet ook offline
  kloppen — niet alleen op het hoofdkantoor.
- **Vertrouwen**: als een systeem bij het kleinste probleem stilvalt,
  gebruikt niemand het tijdens een echte wedstrijd.
- **Wedstrijd-realisme**: ronden kunnen vervallen, rijders kunnen op het
  laatste moment verschoven worden (scheidsrechter-override), transponders
  kunnen haperen. Een rigide systeem overleeft dat niet.

## 8. Wat we vragen

Geen opdracht, wel een uitnodiging: **hoe zou jij dit probleem aanvliegen?**

Alle keuzes zijn aan jou:

- **Architectuur** — offline-modus in de browser? Lokaal desktop-programma?
  Mirror die meeluistert en overneemt bij verlies van verbinding? Een
  hele andere opzet waarin InlineComp en jouw systeem elkaar juist anders
  verhouden? Alles bespreekbaar.
- **Taal/platform** — wat jij het best in kunt bouwen is prima. Alle
  serieuze talen hebben een HTTP-client, JSON-parsing en event-loop, dus
  de keuze is vooral wat jij comfortabel of interessant vindt.
- **Scope** — alles-in-één-klap of stapsgewijs? Als je eerst een beperkt
  probleem wilt oplossen om te verkennen of de aanpak werkt, is dat net
  zo welkom als een masterplan.
- **Integratiepunt** — hoe en wanneer praat jouw oplossing met InlineComp?
  Volledig los, losjes gekoppeld (periodiek), of tight (live)? Dat heeft
  grote gevolgen voor hoe robuust vs. reactief het geheel is.
- **InlineComp zelf** — als je denkt dat een deel van de huidige architectuur
  niet past bij de oplossing die jij ziet, zeg het. De bestaande code is
  niet heilig.

Alle meningen over de huidige opzet zijn welkom. Code is open, database
is open, niks is heilig.

## 9. Praktische randvoorwaarden

Enkele dingen die wel vast staan:

- **KNSB-afhankelijkheid blijft**: inschrijvingen komen van hun API, dat
  gaan we niet nabouwen.
- **Privacy blijft geborgd**: de AVG-tooling (anonimiseer-functie,
  privacyverklaring) blijft zoals ze is, eventuele offline-oplossing moet
  compatibel zijn.
- **Publieke pagina heeft internet nodig**: dat accepteren we. Als er geen
  verbinding is, zien rijders/ouders gewoon geen live-updates. De wedstrijd
  moet wel gewoon kunnen doorlopen.

## 10. Wat er aan info beschikbaar is

Om je een idee te vormen:

- **De code**: PHP/JS/CSS in de repo, open te lezen en te doorzoeken.
- **De database-structuur**: `db/*.sql`, één bestand per tabel met toelichting.
- **Een voorbeeldwedstrijd met echte data**: JSC-1 staat in de database,
  met alle inschrijvingen, heats en uitslagen erbij.
- **De publieke pagina live draaien**: [vul URL in] — zelf uitproberen
  hoe een rijder het ziet.
- **De beheerkant zien**: toegang op aanvraag (account-aanmaak duurt
  2 minuten).

## 11. En dan?

Een paar mogelijke startpunten, puur als gespreksopener — geen verplicht
pad:

1. Een middag de code doorkijken en vertellen wat je opvalt (sterke/zwakke
   kanten), zonder per se iets te bouwen.
2. Een ruwe schets maken van hoe jij het offline-probleem zou aanpakken
   (één pagina is al genoeg), en daar samen over doorpraten.
3. Een proof-of-concept klussen — hoe klein ook — waarmee je laat zien
   dat een bepaalde aanpak werkt of juist niet.

Geen deadline, geen opdracht. De enige voorwaarde is dat we af en toe
praten over waar we mee bezig zijn, zodat onze stukken samen blijven
passen.

---

## Vragen die je misschien hebt

**"Heb je zelf al een oplossing in je hoofd?"** Een paar, eerlijk — maar
stuk voor stuk met trade-offs waar ik niet zeker over ben. Liever jouw
invalshoek eerst dan de mijne opdringen; dat is waarom dit document geen
oplossing voorschrijft.

**"Ik wil dit in [taal X] bouwen, is dat OK?"** Ja. De enige eis is dat
het via een geschikt protocol (HTTP, file-exchange, socket, maakt niet
uit) met InlineComp kan praten. PHP/MySQL aan de server-kant hoef je niet
aan te raken als je daar geen zin in hebt; dat kan ik doen. Of je kunt
zeggen "dit hele stuk PHP moet eruit, ik vervang het door X" — dat is
ook prima, als we het erover eens zijn.

**"Hoe diep moet ik in de bestaande code duiken?"** Zo diep als je voor
je ontwerp nodig hebt. De `db/*.sql`-bestanden + één of twee wedstrijden
bekijken in de publieke pagina geven je waarschijnlijk al 80%. De PHP-code
in `api/` is vooral interessant als je de huidige loting/seeding-regels
in detail wilt doorgronden.

**"Wat verwacht je van mij qua tempo?"** Niks. Als je er een week lang mee
zit en dan concludeert "dit wordt het niet" is dat ook een uitkomst. Een
goed uitgewerkt voorstel op papier heeft meer waarde dan een half-werkende
PoC die niemand durft te gebruiken.

**"Heb je er bezwaar tegen als ik een bestaand stuk van InlineComp wil
herontwerpen?"** Nee. Als jij na doorkijken zegt "de tabel X had ik anders
opgezet" of "deze hele module kan weg als we Y doen" wil ik dat juist
horen. Frisse ogen zien dingen die de schrijver niet meer ziet.
