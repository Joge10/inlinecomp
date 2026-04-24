# InlineComp — Handleiding (in opbouw)

> **Status: WIP — alleen sectie "Importeer" en "Login + layout" zijn gecontroleerd tegen de code.** Alle andere modules volgen stapsgewijs. Verwijzingen zoals `[js/import.js:720]` geven aan welke regel ik heb gelezen.

Drie onderdelen:

1. **Admin-applicatie** — `https://inlineresults.devriesen.com/` (login vereist)
2. **Publieke applicatie** — `https://inlineresults.devriesen.com/public/` (geen login)
3. **Coach-applicatie** — `https://inlineresults.devriesen.com/coach/` (geen login)

---

# Deel 1 — Admin-applicatie

## 1.1 Login

**Bestand:** `login.php`
**Endpoint:** `api/auth.php`

### Eerste keer: owner-bootstrap

Als er nog **geen gebruikers** in de database staan (controle gebeurt server-side in `auth/session.php`), toont `login.php` een uitgebreid formulier om het eerste owner-account aan te maken:

- Volledige naam
- Gebruikersnaam
- Wachtwoord (minimum 8 tekens, attribuut `minlength="8"`)
- Wachtwoord herhalen

Bij succesvol aanmaken: de gebruiker krijgt rol `owner` en wordt ingelogd.

### Normaal inloggen

- Gebruikersnaam-veld (input type text)
- Wachtwoord-veld (input type password)
- Knop "Inloggen"
- Onderaan een link naar `privacyverklaring.php`

Wachtwoord wordt server-side geverifieerd met `password_verify()` tegen een bcrypt-hash. Bij succes wordt een sessie-cookie gezet (`api/auth.php`) en doorgestuurd naar `index.php`.

### Rollen

**Gedefinieerd in:** `users.role` (ENUM in `db/users.sql`)

De zes beschikbare rollen:

| Rol | Bevoegdheid (op basis van JS-lookup in `SCHRIJF_ROLLEN`, `index.php` regel ~368) |
|---|---|
| `viewer` | Alleen lezen |
| `timer` | Live-tijdregistratie + uitslag |
| `planner` | Tijdschema + startlijsten |
| `importer` | KNSB-importeren |
| `admin` | Alle modules behalve gebruikersbeheer |
| `owner` | Alles, incl. gebruikersbeheer |

De JS `magSchrijven(module)` functie (in `app.js`) controleert of de huidige gebruiker in de rollen-lijst voor die module staat; zo niet, wordt de module read-only gezet (`pasSchrijfLockToe()`).

### Uitloggen

Knop `#btn-uitloggen` (pijltje ➤) in de header rechtsboven. Roept `api/auth.php?action=logout`.

### Sessie verlopen

Er is een **globale fetch-interceptor** (`app.js`) die 401-responses opvangt en automatisch een mini-login-modal toont met de gebruikersnaam al ingevuld. Na herinloggen gaat de oorspronkelijke request door. Geen werk verloren.

---

## 1.2 Layout

**Bestand:** `index.php`

Het hoofdscherm bestaat uit:

### Header

`<header>`-element met:
- Links: project-naam "InlineComp" + gebruikersnaam
- Rechts: rol-badge + uitlog-knop (pijltje ➤)

### Sidebar (links)

`<nav id="sidebar">` met twee `<ul>`-blokken:

**Boven (nav-menu, dagelijkse modules):**

| Icoon | `data-page` | Label |
|---|---|---|
| ⇓ | `importeer` | Importeer |
| 📅 | `tijdschema` | Tijdschema |
| 📋 | `startlijsten` | Startlijsten |
| ▶ | `live` | Live verwerking |
| 🏆 | `klassementen` | Uitslag |

**Onder (nav-bottom):**

| Icoon | `data-page` | Label | Zichtbaarheid |
|---|---|---|---|
| ⚙ | `instellingen` | Beheer | Altijd |
| 👤 | `gebruikers` | Gebruikers | Alleen owner/admin |
| 👥 | `rijders` | Rijders | Alleen owner/admin |
| ℹ | `info` | Info | Altijd |

De admin-only items staan standaard op `display:none` en worden ge-unhide door `pasRolToe()` in `app.js` op basis van `currentUser.role`.

### Sidebar in-/uitklappen

Knop `#sidebar-toggle` (❮). Voorkeur wordt opgeslagen — [bevestigen: hoe en waar]. Gedrag gecontroleerd in CSS via een class op de sidebar.

### Main content

`<main id="main-content">` bevat één `<div class="page">` per module (bv. `#page-importeer`, `#page-tijdschema`, etc.). Alleen de active heeft ook CSS-class `active`. Wisselen gebeurt in de nav-click-handler (`app.js`).

### Dirty-tracking

Globale `heeftWijzigingen`-variabele. Bij wegnavigeren terwijl deze `true` is:
- Bij klik op ander nav-item: `toonBevestigDialog()` vraagt of gebruiker wil doorgaan zonder opslaan
- Bij tab sluiten: browser-native confirm via `beforeunload`-event (`app.js`)

---

## 1.3 Module: Importeer

**HTML:** `index.php` regel 86-143
**JS:** `js/import.js`, `js/app.js` (wedstrijdlijst)
**API's:** `api/competitions.php`, `api/vergelijk.php`, `api/import.php`, `api/samenvoeg.php`, `api/splits.php`, `api/afstanden_beheer.php`, `api/distances_db.php`

### 1.3.1 Pagina-opzet

`#page-importeer` heeft twee kolommen (`.voorb-layout`):

**Linkerkolom** `.voorb-left`:
- `.section-title` met tekst "Wedstrijden"
- `.date-filter`-blok met 4 filter-velden + 2 knoppen
- `#comp-list` — wordt gevuld door JS

**Rechterkolom** `.voorb-right`:
- `#detail-panel` met `style="display:none"` tot een wedstrijd geselecteerd is
- Daarin: `.detail-header`, `#import-result`, `#beheer-panel`, `.tab-bar#imp-cat-tabs`, `#imp-cat-content`

### 1.3.2 Linkerkolom — wedstrijdenlijst laden

**Functie:** `laadWedstrijden()` in `app.js:233`

- Fetcht `api/competitions.php` met optionele `?van=<datum>`-parameter. De "van"-datum komt uit `#filter-van`.
- Response is een JSON-array. Die komt in globale `allWedstrijden`.
- Bij fout: `statusMsg(list, 'error', ...)` in `#comp-list`.
- Bij lege lijst: `"Geen aankomende inline wedstrijden gevonden."`.

### 1.3.3 Linkerkolom — filters

**Functie:** `renderWedstrijdLijst()` in `app.js:325`

Vier filters (worden gecombineerd met AND):

| Filter | HTML-id | Werking |
|---|---|---|
| Van-datum | `#filter-van` | `c.starts >= van` |
| Tot-datum | `#filter-tot` | `c.starts <= tot + 'T23:59:59'` |
| Locatie | `#filter-locatie` | exacte match op `getLocatie(c)` |
| Organisatie | `#filter-organisatie` | exacte match op email of naam uit `c.settings.contact` |

**Locatie-dropdown** (`vulLocatieDropdown()`): unieke waarden uit de wedstrijden-array, alfabetisch gesorteerd.

**Organisatie-dropdown** (`vulOrganisatieDropdown()`): wedstrijden worden gegroepeerd op e-mail (of naam als fallback). Per groep wordt de **meest voorkomende naam** als label gekozen. Zo worden typo-varianten automatisch samengevat.

**Twee knoppen:**
- `#filter-reset` (label "Wis") — alle filter-waarden terug op leeg
- `#btn-ververs-wedstrijden` — roept `laadWedstrijden()` opnieuw aan. Het ↻-icoon draait tijdens het fetchen (`#ververs-icon` krijgt CSS-animatie).

### 1.3.4 Linkerkolom — wedstrijdkaarten

Elke wedstrijd wordt als een `<div class="comp-card">` gerenderd met:

- `.comp-naam` — `comp.name` of `comp.title`
- `.comp-meta` — `formatDatum(comp.starts)` + optioneel `' · ' + getLocatie(comp)`

**Geen badges** zoals "In DB" of "Nieuw" op de kaart zelf. De enige visuele indicator is `.active` (blauwe rand) voor de geselecteerde wedstrijd.

**Limiet zonder filter:** als er meer wedstrijden zijn dan `MAX_ZONDER_FILTER` (waarde te controleren in `app.js`) én er is géén filter actief, wordt de lijst ingekort met een `+N meer — gebruik filters om te verfijnen`-regel onderaan.

### 1.3.5 Wedstrijd selecteren

**Functie:** `selectWedstrijd(card, comp)` in `app.js:379`

Bij klik op een kaart:

1. Als er onopgeslagen wijzigingen zijn → bevestigingsdialoog (`toonBevestigDialog`).
2. Vorige fetch-request afbreken (`vergelijkAbort.abort()`).
3. Actieve kaart-markering verplaatsen.
4. Globals `huidigCompId` en `huidigComp` updaten.
5. `#detail-panel` zichtbaar maken, titel en meta invullen.
6. Fetch `api/vergelijk.php?id=<id>` — response vult o.a.:
   - `vergelijkData` — array van categorieën (DC's) met deelnemers
   - `huidigOrganisatie` — koppeling aan organisatie
   - `standDatum`, `dbStandDatum` — timestamps voor sync-indicator
   - `_heeftProgramma` — true als er een tijdschema bestaat
   - `_orgTransponders` — inventaris van de organisatie
7. `initEdits()` — lokale edit-state opzetten
8. `bouwVergelijkTabbladen()` — tabs renderen
9. `updateImportBtn()` — importeer-knop state zetten

### 1.3.6 Detail-header

Zodra een wedstrijd is geselecteerd:

- `#detail-title` — naam
- `#detail-meta` — datum + locatie
- `#knsb-sync-info` — wordt gevuld door `zetKnsbTimestamp()` (beschrijft wanneer de KNSB-data laatst werd opgehaald)
- `#btn-import` — klik-handler aan `importeerWedstrijd()` gebonden

### 1.3.7 Categorie-tabbladen

**Functie:** `bouwVergelijkTabbladen()` in `import.js:720`

Per categorie (distance_combination / DC) één tab:

- Labeltekst: `<dc_name> (<totaal-deelnemers>)`
- **Badge `<n>✗`** rood: aantal afgemeld (status 2, 3 of 4)
- **Badge `<n>N`** groen: aantal nieuwe rijders

De eerste tab is standaard actief. Klikken wisselt tab én roept `toonVergelijkTabel(cat)` aan.

### 1.3.8 Vergelijk-tabel

**Functie:** `toonVergelijkTabel(cat)` in `import.js:760`

**Kolommen** (uit de `<thead>`):

| Kolom | Klasse | Inhoud |
|---|---|---|
| Start# | `.th-sn` | Startnummer, bewerkbaar `<input type="number">` |
| Naam | `.th-naam` | Volledige naam, bewerkbaar `<input type="text">` |
| Club | `.th-club` | Clubnaam (alleen-lezen) |
| Transponder | `.th-tp-sel` | Custom dropdown met alle bekende transponders voor deze rijder |
| Status | `.th-status` | Status-badge, klikbaar voor statuswissel |
| Badges | `.th-badges` | Labels: NIEUW, ANON, !, ⓘ |

**Rij-kleuren** (CSS-class op `<tr>`, één tegelijk):

| Class | Wanneer |
|---|---|
| `row-withdrawn` | `entry_status` in {2, 3, 4} (afgemeld/niet getekend) — behalve 5 |
| `row-new` | `item.is_new` — rijder stond nog niet in de DB |
| `row-diff` | `diffs.length` — afwijking tussen KNSB en DB |
| `row-modified` | Lokaal gewijzigd, nog niet opgeslagen |

**Start-nummer-kleur:** als start_number ≥ 1000, class `guest-nr` (toont 'm als gast-nummer).

**Badges in de laatste kolom:**
- `badge-nieuw` ("NIEUW") als `item.is_new`
- `badge-anoniem` ("ANON") als `item.is_anoniem` — rijder zonder licentienummer
- `badge-diff` ("!") als er `diffs` zijn t.o.v. DB
- `badge-meld` (ⓘ) als `_berekenMeldingen()` redenen geeft dat rijder zich persoonlijk moet melden

**Status-labels** (uit `app.js:18`):

| Waarde | Label | CSS-class |
|---|---|---|
| 0 | Niet bevestigd | `status-0` |
| 1 | Bevestigd | `status-1` |
| 2 | Afgemeld | `status-2` |
| 3 | Afgem. bij org. | `status-3` |
| 4 | Niet getekend | `status-4` |
| 5 | Bevestigd bij org. | `status-5` |

Onder de tabel: knop `.btn-deelnemer-add` — opent een modal om handmatig een rijder toe te voegen (`openDeelnemerModal(dcId)`).

### 1.3.9 Velden bewerken

**Change-handler voor `.inp[data-field]`-inputs** (`import.js:867`):
- Bij change: update `personEdits[license_key][field]`
- Markeer de rij als gewijzigd (voegt `row-modified` toe)
- Start-nummer-wijziging triggert ook `_hertekenMeldBadge()`

Waarschuwing in de UI bij afwijking t.o.v. KNSB: onder het veld verschijnt een `.knsb-hint` met de originele KNSB-waarde.

### 1.3.10 Transponder-dropdown

Per rijder een custom dropdown (`maakTpDropdownHtml()`). Standaardwaarde bepaald door `initEdits()`:

1. Is er al een waarde in de DB opgeslagen (`item.db_tp_actief_isset`)? → Die gebruiken (kan ook `null` zijn voor "geen transponder").
2. Anders, fallback-volgorde: T1 (KNSB slot 1) → T2 (KNSB slot 2) → organisatie-toewijzing (match op startnummer + naam) → extras → `null`.

**Kruis-bescherming bij org-transponders** (`import.js:883`): als je een organisatie-transponder aan rijder A toekent die al aan rijder B hangt, wordt de toewijzing bij B automatisch weggehaald (anders zou de server de transponder voor beide rijders krijgen en overschrijven).

### 1.3.11 Importeer-knop

**Functie:** `importeerWedstrijd(compId, compNaam)` in `import.js:2049`

1. Check of er vergelijk-data is.
2. Disable de knop, toon "Importeren…" in `#import-result`.
3. `collectImportData(compId)` bouwt de POST-body.
4. POST naar `api/import.php`.
5. Response-afhandeling:
   - **HTTP 409** of `error: 'conflict'`: waarschuwing dat iemand anders de inschrijvingen gewijzigd heeft. Knop `↺ Herlaad` herstart.
   - **Andere fout:** rode error-melding.
   - **Succes:** uitklap-`<details>` met de import-log (per-rijder wat er gebeurd is). `isGeimporteerd = true`, `heeftWijzigingen = false`, gevolgd door `herlaadVergelijking()` om de vergelijking opnieuw op te halen.

Na 4 seconden sluit het `<details>`-blok automatisch in (de log blijft wel beschikbaar als je 'm opent).

### 1.3.12 Deelnemer handmatig toevoegen

**Modal:** `#modal-deelnemer`, geïnitialiseerd in `initDeelnemerModal()` (`import.js:2123`)

Opent via de `.btn-deelnemer-add`-knop onder elke categorie-tabel.

**Tabs in de modal:**
- "Op relatienummer" (KNSB licentie opzoeken)
- [andere tabs — bevestigen]

[TODO: sub-secties van deze modal grondig beschrijven — persoon-zoek via relatienummer/naam, categorie-waarschuwing, transponder-bevestiging]

### 1.3.13 DC-beheer panel

**Functie:** `bouwBeheerTabel()` in `import.js:104`
**Div:** `#beheer-panel`

Wordt alleen getoond als `isGeimporteerd === true` (d.w.z. de wedstrijd is ten minste één keer geïmporteerd). Voor niet-geïmporteerde wedstrijden blijft het panel leeg.

[TODO: inhoud DC-beheer panel beschrijven. Lijkt te gaan over samenvoegen/splitsen van categorie-combinaties en per-afstand configuratie. Hier moet nog stevig worden gelezen voordat ik er iets over zeg.]

### 1.3.14 Afwezigheden: wat ik nog niet gecontroleerd heb

Voor een complete beschrijving van Importeer moet ik nog onderzoeken:

- [ ] Hoe werkt `maakTpDropdownHtml()` precies — welke opties verschijnen, onder welke sub-groepen?
- [ ] Hoe werkt de deelnemer-modal (relatienummer-zoek flow + knsb-tab + handmatige tab)?
- [ ] Wat rendert `bouwBeheerTabel()` exact — welke velden per rij?
- [ ] Hoe werkt `samenvoegen` en `splitsen` (UI-flow)?
- [ ] `collectImportData()` — welke velden worden server-side meegestuurd?
- [ ] Wat verschijnt er in `#knsb-sync-info` en wanneer?

---

# Deel 2 — Publieke applicatie

Sectie is **nog niet gecontroleerd tegen de code**. Wordt in een volgende sessie grondig gelezen:

- `public/index.php` (2737 regels) — HTML + JS in één bestand
- Relevante API's: `public/` heeft z'n eigen actions in `public/index.php` via `?action=`

[TODO: per functie beschrijven]

---

# Deel 3 — Coach-applicatie

Sectie is **nog niet gecontroleerd tegen de code**. Wordt in een volgende sessie grondig gelezen:

- `coach/index.php` (2359 regels)

[TODO: per functie beschrijven]

---

## Notities voor de schrijver (mezelf / volgende sessie)

- Schrijf **alleen** op wat je letterlijk in de code hebt gezien. Gebruik `[TODO]` of `[BEVESTIGEN]` voor vermoedens.
- Verwijs bij twijfelgevallen naar `bestand:regel`, zodat verificatie snel is.
- Per module eerst een grondige code-read, dan pas schrijven.
- UI-element-beschrijvingen refereren aan HTML-id's of class-names (zoals `#btn-import`, `.vergelijk-tabel`) zodat ze eenduidig zijn.
- Zodra een module compleet is gecontroleerd, markeer hem in de WIP-banner bovenaan.
