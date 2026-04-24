# InlineComp — Technische samenvatting

*Laatst bijgewerkt: april 2026*

Een praktische, bestand-voor-bestand beschrijving van het InlineComp-project:
wat er in zit, hoe de onderdelen samenhangen, en welke code welke verantwoordelijkheid heeft.

Gerelateerde docs:

- [`database-schema.md`](database-schema.md) — ER-diagrammen per domein
- [`offline-modus-spec.md`](offline-modus-spec.md) — open vraagstuk voor robuustheid
- `InlineComp_Samenvatting.pdf` — vorige compacte PDF-versie (historisch)

---

## 1. Wat is InlineComp?

Wedstrijdbeheersysteem voor inline-skate-wedstrijden op KNSB-niveau. Loopt van
KNSB-import van inschrijvingen, via tijdschema en startlijst-generatie, naar
live tijdregistratie, uitslag-publicatie en seizoensklassement — met een
publieke kant waar rijders/ouders/coaches live kunnen meekijken.

**Stack**: PHP 8.4 + MariaDB 10.11 op iFastNet shared hosting
(`inlineresults.devriesen.com`). Vanilla JavaScript (geen framework) in de
browser. Progressive web app via een service worker. Gebouwd door Geert de Vries.

## 2. Architectuur op hoofdlijnen

```
┌──────────────────────────────────┐
│  index.php  (SPA-shell, admin)   │
│  login.php  (owner aanmaak)      │
│  public/index.php  (publiek)     │
│  coach/index.php   (coach-view)  │
│  privacyverklaring.php           │
└──────────────────────────────────┘
            │ fetch() / XHR
            ▼
┌──────────────────────────────────┐
│  api/*.php  — 35 endpoints       │
│  + auth/session.php              │
└──────────────────────────────────┘
            │ PDO
            ▼
┌──────────────────────────────────┐
│  MySQL — 37 tabellen             │
│  zie db/*.sql                    │
└──────────────────────────────────┘
```

**Kerngedachten**:

- **Dunne client**: alle state loopt via de server. Browser doet UI +
  validatie + rendering, geen bedrijfslogica.
- **API's vormen contract**: elk endpoint `POST { action: '...', ... }` of
  `GET ?action=...` met JSON-body/response.
- **KNSB is source-of-truth** voor rijders en inschrijvingen. InlineComp
  verrijkt die met wedstrijd-uitvoering, uitslag en archief.
- **Eén wedstrijd = één SPA-sessie**: de bovenste nav-items schakelen tussen
  modules van dezelfde wedstrijd.
- **Archief overleeft verwijderen**: `uitslag_afstand` en `uitslag_klassement`
  zijn bewust gedenormaliseerd (wedstrijd-naam + datum dupliceren) en zonder
  `ON DELETE CASCADE` op `competitions`.

## 3. Authenticatie en rollen

### Bestanden

- `login.php` — login-kaart + owner-bootstrap (eerste keer)
- `auth/session.php` — centrale helpers: `requireAuth($pdo)`,
  `sessieAanmaken()`, `logUit()`, `isAdmin()`
- `api/auth.php` — login/logout/me endpoints
- `api/gebruikers.php` — CRUD op users + reset wachtwoord
- `api/logboek.php` — audit-log raadplegen
- `db/users.sql`, `db/sessions.sql`, `db/login_logs.sql`
- `js/gebruikers.js` — Beheer → Gebruikers UI

### Rollen

| Rol      | Bevoegdheid |
|----------|-------------|
| viewer   | alleen lezen |
| timer    | tijden invoeren tijdens live |
| planner  | startlijsten + tijdschema |
| importer | KNSB-imports draaien |
| admin    | alles behalve gebruikersbeheer |
| owner    | alles |

### Werking

- Bcrypt-hash via `password_hash()` / `password_verify()`.
- Sessie-token (64 hex) in cookie; sessies in `sessions`-tabel met expiry.
- `login_logs` bewaart elke login/logout/failed-attempt met geo-info uit het IP
  (best-effort via publieke IP-geolocatie).
- Verlopen sessies worden opgeruimd in `session.php`.

## 4. Module: Importeer

De ingang van elke wedstrijd: inschrijvingen van KNSB halen, lokaal
controleren en opslaan.

### Bestanden

| Bestand | Rol |
|---|---|
| `api/competitions.php`        | lijst wedstrijden uit KNSB-API (met datum-filter) |
| `api/competition.php`         | details van één wedstrijd |
| `api/competitors.php`         | ingeschrevenen per wedstrijd |
| `api/vergelijk.php`           | diff KNSB ↔ lokale DB, incl. org-transponders |
| `api/import.php`              | uitvoeren van de daadwerkelijke import |
| `api/distances.php`           | afstanden per DC uit KNSB |
| `api/distances_db.php`        | afstanden uit lokale DB |
| `api/afstanden_beheer.php`    | race_type + meters per afstand bewerken |
| `api/samenvoeg.php`           | DC's samenvoegen (bv. HPA + DPA) |
| `api/splits.php`              | DC splitsen in categorieën |
| `js/import.js`                | UI van de importeer-pagina |
| `db/competitions.sql`, `db/distance_combinations.sql`, `db/distances.sql`, `db/entries.sql`, `db/transponders.sql`, `db/dc_splits.sql` | core-tabellen |

### Wat de import doet (stappen)

1. Gebruiker kiest wedstrijd uit KNSB-lijst.
2. `vergelijk.php` toont verschillen: nieuwe rijders, veranderde transponders,
   categorie-wissels, afmeldingen.
3. Optioneel: DC's samenvoegen/splitsen vóór import.
4. `import.php` voert de schrijfactie uit: inserts/updates op `persons`,
   `entries`, `transponders`, `organisatie_transponders`.
5. Sync-check: welke eigen transponders wijken af van de organisatie-inventaris?

### Bijzonderheden

- **Organisatie-koppeling**: zoekt organisatie op via
  `contact.email` → `naam` (exact) → `organisatie_aliassen` voordat het de
  wedstrijd aan een organisatie vasthaakt.
- **Transponder-matching**: prefereert KNSB-licentienummer
  (`person_license`), valt terug op naam+startnummer voor legacy-data.
- **Vrijgeef-logica**: oude toewijzingen aan rijders die de transponder niet
  meer gebruiken worden alleen gewist als de transponder een organisatie-bezit
  is (niet bij eigen transponders).

## 5. Module: Tijdschema

Opzet van welke ronde wanneer gereden wordt, hoe hard de doorstroom is, en
hoe finales geseed worden.

### Bestanden

| Bestand | Rol |
|---|---|
| `api/tijdschema.php`          | CRUD op tijdschema + blokken + ritten + categorie-configs |
| `js/tijdschema.js`            | UI met drag-drop ritten, blokken-editor, afstand-configs |
| `db/competition_tijdschema.sql`, `db/tijdschema_afstand_config.sql`, `db/tijdschema_cat_config.sql`, `db/tijdschema_blokken.sql`, `db/tijdschema_ritten.sql` | schema per-wedstrijd |

### Twee systemen

- **`full-final`** — klassiek Nederlands systeem: series → (KF) → (HF) → finale
- **`internationaal-nieuw`** — World Skate 2026-regels

### Status-flow

`concept` (wordt aan gesleuteld) → `gepubliceerd` (vrijgegeven naar publieke
pagina). Bij elke wijziging wordt `competition_tijdschema.updated_at` gezet en
`tijdschema_version` verhoogd, zodat cliënten kunnen detecteren of hun cache
verouderd is.

### Blok-typen

`tijdschema_blokken.blok_type`: `ronde`, `pauze`, `inrijden`, `wedstrijdstart`,
`ceremonie`, `herstart`. Per blok worden `tijdschema_ritten` gegenereerd (de
concrete heats met tijden).

## 6. Module: Startlijsten

Generatie van de rijder-toewijzing per heat, ronde voor ronde, op basis van
doorstroom-regels en seed-strategie.

### Bestanden

| Bestand | Rol |
|---|---|
| `api/startlijst_genereer.php`   | generator: loting ronde 1, seeding vervolgronden |
| `api/startlijst_laden.php`      | startlijst lezen voor print/UI |
| `api/startlijst_status.php`     | per ronde: klaar/niet-klaar, aantal deelnemers |
| `api/startlijst_rijder_heat.php`| handmatig een rijder verplaatsen |
| `api/startlijst_wis.php`        | ronde verwijderen (bij regeneratie) |
| `api/klassement_import.php`     | KNSB-klassement-PDF importeren voor seeding |
| `api/klassement_punten.php`     | puntentellingen administreren |
| `api/tussenklassement.php`      | tussenstand voor seeding van latere rondes |
| `js/startlist.js`               | UI van de startlijsten-module |
| `js/print_module.js`            | gedeelde print-layout (ook door uitslag) |
| `db/heats.sql`, `db/heat_entries.sql`, `db/klassementen.sql`, `db/klassement_posities.sql` | |

### Loting-bronnen voor ronde 1

- **Startnummer** (vast, voorspelbaar)
- **Alfabetisch** (achternaam)
- **Tussenklassement** (eerder gereden onderdelen van dezelfde wedstrijd)
- **Klassement** (import uit PDF of serie — stabiele, cross-wedstrijd-seeding)

### Seeding volgende rondes

- Series → KF/HF: volgens doorstroom-regels uit `tijdschema_cat_config`
  (bijv. top-4 per serie, plus beste resttijden)
- A-finale seeding: snake-patroon of tijdkoppeling (per afstand instelbaar)
- **Ex-aequo overflow**: als twee rijders niet gesplitst kunnen worden worden
  extra finale-plaatsen toegekend (zonder dat er een andere rijder uit valt)

### Safety nets

- Rijders met `entries.status = 4` (niet-getekend) worden standaard
  overgeslagen, **tenzij** ze al een rij in `uitslag_afstand` hebben — dan
  krijgen ze alsnog een heat-plaats (bescherming tegen per-ongeluk-niet-getekend).

## 7. Module: Live verwerking

De tijdwaarneming tijdens de wedstrijddag.

### Bestanden

| Bestand | Rol |
|---|---|
| `api/live.php`               | heats lezen, tijden+sancties opslaan, volgende ronde genereren |
| `api/upload.php`             | CSV-uploads van timing-apparatuur |
| `js/live.js`                 | UI: carousel met heat-cards + linker panel met ronde-overzicht |
| `db/results.sql`, `db/heats.sql`, `db/heat_entries.sql` | |

### UI-componenten

- **Carousel**: per heat een kaart met 6 banen, tijd-invoer, sanctie-dropdown
- **Linker panel**: alle rijders in de huidige ronde gesorteerd (voor ex-aequo
  check en snelle navigatie)
- **CSV-import**: plak of upload `nr;tijd[;rondes]`-formaat, auto-detect
  scheidingsteken en encoding

### Features

- **Rondes-invoer** (voor lange afstand / puntenkoers) synct tussen heat-card
  en linker panel
- **9 sanctiecodes** (zie §11)
- **Automatische finishpositie-berekening** op basis van tijd + sancties
- **Auto-generate volgende ronde** — bij klikken op "ronde afronden" gaat de
  output direct de seeding-pijplijn in
- **Cleanup per ronde-type** — oude gegenereerde heats kunnen overschreven
  worden bij her-generatie

## 8. Module: Uitslag + Klassement

Na afloop van elke ronde worden per-afstand-uitslagen en per-DC-klassementen
vastgelegd.

### Bestanden

| Bestand | Rol |
|---|---|
| `api/uitslag_afstand.php`      | per-afstand einduitslag berekenen en tonen |
| `api/uitslag_vastleggen.php`   | eindstand per DC vastleggen in archief |
| `api/_uitslag_helper.php`      | gedeelde ranking-functies (sprint/lang/puntenkoers) |
| `api/klassement_live.php`      | live-klassement tijdens de wedstrijddag |
| `js/uitslag.js`                | UI: tabs per DC, per afstand, en eindstand |
| `js/ranking.js`                | rendering-helpers voor rangordes |
| `db/uitslag_afstand.sql`, `db/uitslag_klassement.sql` | archief |

### Sorteerlogica per race-type

| Race type          | Volgorde                                          | Ex-aequo als |
|--------------------|---------------------------------------------------|---|
| Puntenkoers        | pk-punten DESC → rondes DESC → tijd ASC           | alle drie gelijk |
| Lange afstand      | rondes DESC → tijd ASC                             | rondes+tijd gelijk |
| Sprint (pos+tijd)  | positie ASC → tijd ASC                             | positie+tijd gelijk |
| Sprint (tijd)      | tijd ASC                                           | tijd gelijk |

### Finale-opbouw

- **Full-final**: kan als "gecombineerd" (serie-tijden tellen mee in finale-
  ranking) of "normaal" (alleen finale-tijden tellen)
- **Internationaal-nieuw**: cascading elimination — wie uit ronde X valt
  krijgt de beste plek van zijn groep

### Cluster-klassement

Voor **gemengde DC's** (bv. "HPA + DPA samen") wordt het klassement per
`dc_splits.split_group` berekend, zodat heren en dames elk hun eigen rangorde
krijgen binnen dezelfde heats.

## 9. Module: Serie-klassement

Meerjaren-/seizoensklassementen die uitslagen van meerdere wedstrijden
combineren tot één eindstand.

### Bestanden

| Bestand | Rol |
|---|---|
| `api/klassement_serie.php`   | berekent serie-klassement uit uitslag_klassement |
| `api/klassement_preset.php`  | punten-tabel presets per organisatie |
| `js/klassement_serie_ui.js`  | wizard voor serie-opzet + wedstrijd-koppeling |
| `db/klassement_series.sql`, `db/klassement_serie_wedstrijden.sql`, `db/klassement_presets.sql` | |

### Logica

- **Classificeert DC's** in single-cat (bv. "HPA alleen") vs gemengd
  ("HPA+DPA") — per type aparte ranglijst
- **Filter op afstand-type**: alle / alleen sprint / alleen lang / op naam
- **Doorstroom-logica van de finale**: streep, minimum deelnames,
  vereist-finale — allemaal pas actief als er daadwerkelijk een finale
  gereden is (anders blokkeert een niet-gereden finale het hele klassement)
- **Cross-check**: rijders met `uitslag_afstand.punten = 0` worden
  uitgesloten, ook als ze in `uitslag_klassement` voorkomen (stale rows uit
  eerdere importen)
- **Ex-aequo**: in tussenstand géén tie-break, in eindklassement wel

### Koppeling naar bestaande klassement-tabellen

`klassement_series` is een specialisatie van `klassementen` (elke serie-rij
verwijst 1-1 naar een `klassementen`-rij). Zo kan de bestaande PDF-import-flow
en de startlijst-seeding ongewijzigd blijven — alleen de bron van de
klassement-rij is anders.

## 10. Publieke pagina (`/public/`)

### Bestanden

| Bestand | Rol |
|---|---|
| `public/index.php`       | self-contained SPA voor bezoekers |
| `public/sw.js`            | service worker (asset-cache + manifest) |
| `public/manifest.json`    | PWA-manifest |
| `public/icon-*.svg`       | icons voor installable app |
| `api/public_stats.php`    | anonieme bezoeker-telling |
| `db/public_visits.sql`, `db/peak_stats.sql` | |

### Features

- **Smart input** — zoek op startnummer, licentienummer of achternaam; één
  veld voor alle drie
- **Multi-rijder** — kind-tabs (max 4) persisted op `license_key` globaal
  (blijft werken als je wisselt van wedstrijd)
- **Chooser-modal** bij meerdere matches op achternaam
- **Rate limiting + smart refresh** — beperkt server-druk
- **Vier tabs per rijder**: Programma / Heats / Sancties / Uitslag
- **Installable PWA** — via de `(i)`-dialog kan een bezoeker de app naar
  startscherm installeren
- **Privacy-knop** in de `(i)`-dialog leidt naar `privacyverklaring.php`
- **Statistieken**: anonieme sessie-teller (geen IP, geen cookies) + piek
  gelijktijdig (vandaag + all-time)

## 11. Coach-view (`/coach/`)

### Bestanden

| Bestand | Rol |
|---|---|
| `coach/index.php`       | self-contained pagina (HTML + CSS + JS in één) |
| `api/coach_stats.php`   | anonieme bezoeker-telling |
| `db/coach_visits.sql`   | log-tabel |

Gericht op coaches met meerdere rijders tegelijk. Aparte ingang, eigen UX:
selectie per club/sponsor/startnummer, tabs voor Programma / Heats / Sancties /
Uitslagen, auto-refresh (120s), pull-to-refresh, localStorage voor persistentie.

Statistieken zijn gescheiden van `/public/` zodat je per kanaal ziet hoeveel
gebruik er is.

## 12. Beheer-modules

De `/index.php`-SPA heeft "Beheer" als top-level tab met sub-tabbladen per
organisatie + top-level pagina's voor **Gebruikers** en **Rijders** (AVG).

### Organisatie-beheer

| Bestand | Rol |
|---|---|
| `api/organisaties.php`    | CRUD + transponder-save |
| `js/instellingen.js`      | UI voor 4 tabs: Gegevens, Transponders, Wedstrijden, Klassementen |
| `db/organisaties.sql`, `db/organisatie_aliassen.sql`, `db/organisatie_sponsors.sql`, `db/organisatie_transponders.sql` | |

Tab-functionaliteit:

1. **Gegevens** — naam, e-mail, logo, aliassen, sponsors
2. **Transponders** — inventaris met Nr/Snr sort (Excel-stijl iconen in
   header) en filter in de Betaald-kolom (alle/uitgegeven/betaald/niet-betaald).
   Nieuwe kolom `person_license` (KNSB-licentienummer) zodat een wanbetaler
   snel bij de bond opgezocht kan worden.
3. **Wedstrijden** — lijst van wedstrijden die aan deze organisatie gekoppeld zijn
4. **Klassementen** — PDF-imports + serie-klassementen van deze organisatie

### Rijderbeheer (AVG) — `/index.php` → Rijders

| Bestand | Rol |
|---|---|
| `api/persoon_beheer.php`      | gecombineerde zoek (startnr/licentie/naam) + detail |
| `api/persoon_anonimiseer.php` | onomkeerbaar AVG-anonimiseren |
| `api/persoon_zoek.php`        | lookup per license/startnummer (intern) |
| `js/rijders.js`               | zoek-UI + detail-paneel met wedstrijd-historie |
| `db/persons.sql`              | persons-tabel met `anonymized_at` |

Alleen voor owner/admin. Toont volledige persons-velden + transponder-
toewijzingen per organisatie + per-DC-eindklasseringen + (uitklapbaar) per-
afstand-uitslagen. Anonimiseer-knop vereist typen van licentienummer als
dubbele bevestiging; auditlog wordt geschreven.

### Gebruikers-beheer

| Bestand | Rol |
|---|---|
| `api/gebruikers.php` | CRUD + wachtwoord-reset |
| `api/logboek.php`    | audit-log raadplegen (logins, anonimisaties, etc.) |
| `js/gebruikers.js`   | UI in top-level Gebruikers-tab |

## 13. Gedeelde componenten

### `api/_uitslag_helper.php`

Functies die door meerdere uitslag-paden worden gebruikt:

- `rankBySprintTime()` / `rankByPositionTime()` / `rankByLongDistance()` /
  `rankByPuntenkoers()` — consistente ranking over alle modules
- Sanctie-verwerking: toepassen van W1/W2/DQ/DNS/DNF op ranking en punten
- DNS-eerste-ronde-regel (0 punten, art. 144.4 World Skate rulebook)

### `js/print_module.js`

Gedeelde helpers voor PDF-printouts:

- Startlijsten (per heat of hele ronde)
- Uitslag per afstand / per DC
- Deelnemerslijst (met transponders)
- Uitgeleverde transponders (voor balie-archief)

Gebruikt `html2pdf.js` + `jsPDF` CDN's. Consistente header/footer-opmaak per
organisatie (logo + sponsors).

### `js/handleiding.js`

Inline handleiding-popup voor elke module (klik op `?`-knop).

### `js/app.js`

De SPA-shell:

- Nav-routing (welke pagina zichtbaar is)
- Rol-gebaseerde toegang (`pasRolToe()`)
- Schrijf-lock voor alleen-lezen-rollen (`pasSchrijfLockToe()`)
- Fetch-interceptor (401 → login-modal)
- Dirty-tracking (waarschuw bij wegnavigeren met openstaande wijzigingen)
- Globale `toonBevestigDialog()` — vervangt `confirm()` met een nette modal

## 14. Sanctie-systeem (World Skate 2026)

DB-waarden komen 1-op-1 overeen met UI-codes. DNS in de eerste ronde = 0 punten
(art. 144.4).

| Code | Betekenis | Ranking-effect |
|---|---|---|
| FS    | False Start         | Geen — tijd bewaard, normale positie |
| W1, W2 | Warning            | Geen automatisch effect (juridictum) |
| RR    | Reduction in Rank   | Geen automatisch effect, rijder rijdt door |
| DQ-TF | Technical Fault     | Ranked last in round |
| DNS   | Did Not Start       | Ranked last, of 0 punten (eerste ronde) |
| DNF   | Did Not Finish      | Ranked last in round |
| DQ-SF | Sports Fault        | Not ranked, 0 punten |
| DQ-DF | Disciplinary Fault  | Not ranked, 0 punten |

## 15. Databank

37 tabellen, één file per tabel in `db/`. Per-tabel-SQL is leidend voor een
verse installatie. Éénmalige ALTERs voor bestaande installaties staan onder
`db/migrations/`.

Zie [`database-schema.md`](database-schema.md) voor de volledige ER-diagrammen
per domein. Samengevat:

- **Basis**: persons, organisaties (+aliassen/sponsors/transponders), users,
  sessions, login_logs
- **Wedstrijd-setup**: competitions (+instellingen/startnummers/tijdschema),
  distance_combinations, distances, entries, transponders, point_systems, dc_splits
- **Tijdschema**: competition_tijdschema + afstand_config, cat_config, blokken, ritten
- **Heats**: heats, heat_entries, results
- **Archief**: uitslag_afstand, uitslag_klassement
- **Klassementen**: klassementen, klassement_posities, klassement_series,
  klassement_serie_wedstrijden, klassement_presets, series, series_wedstrijden
- **Stats**: public_visits, coach_visits, peak_stats

Cascade-regel: `competitions` → `distance_combinations` → `entries`/`heats` →
`heat_entries` → `results` (alles CASCADE bij delete). Uitslag-archief heeft
**geen** CASCADE op competitions, zodat geschiedenis intact blijft.

## 16. Bestandsstructuur

```
inlinecomp/
├── api/                         35 PHP-endpoints
│   ├── _uitslag_helper.php      gedeelde ranking-functies
│   ├── auth.php / gebruikers.php / logboek.php       auth + beheer
│   ├── competition*.php / import.php / vergelijk.php  KNSB-import
│   ├── startlijst_*.php / tussenklassement.php       startlijsten
│   ├── live.php / upload.php                          live-invoer
│   ├── uitslag_*.php / klassement_*.php              uitslag/klassement
│   ├── persoon_*.php                                  AVG-beheer
│   ├── organisaties.php / afstanden_beheer.php       organisatie + afstand
│   └── public_stats.php / coach_stats.php            anonieme stats
│
├── auth/session.php             auth-helpers (requireAuth, sessions)
│
├── css/style.css                centrale styling + CSS-variabelen
│
├── db/                          37 tabel-SQLs + migrations/
│   └── migrations/              éénmalige ALTERs met README
│
├── docs/                        project-documentatie
│   ├── project-samenvatting.md  (deze file)
│   ├── database-schema.md       ER-diagrammen
│   ├── offline-modus-spec.md    open vraagstuk
│   └── InlineComp_Samenvatting.pdf  historische versie
│
├── js/                          13 frontend-modules
│   ├── app.js                   SPA-shell, routing, rol-gate
│   ├── import.js / startlist.js / tijdschema.js / live.js / uitslag.js  module-UIs
│   ├── instellingen.js          organisatie-beheer
│   ├── gebruikers.js / rijders.js  gebruikers + AVG
│   ├── klassement_serie_ui.js   serie-klassement wizard
│   ├── ranking.js / print_module.js  rendering-helpers
│   └── handleiding.js           per-module inline help
│
├── public/                      publieke pagina + PWA-manifest + SW
├── coach/                       coach-view (self-contained)
│
├── tools/pdf_klassement.py      KNSB-klassement PDF → JSON import helper
├── poster_gen.py                promotie-poster generator (QR → /public)
│
├── index.php                    admin-SPA-shell
├── login.php                    login + owner-bootstrap
├── privacyverklaring.php        publieke AVG-pagina
└── .gitignore                   incl. .claude/, __pycache__, etc.
```

## 17. Infrastructuur

- **Hosting**: iFastNet shared PHP 8.4 + MariaDB 10.11
- **Domein**: `inlineresults.devriesen.com`
- **HTTPS**: via host-provider
- **Config**: `config_inlinecomp.php` — staat **buiten** de webroot, buiten git
  (in `.gitignore`)
- **Deploy**: VS Code SFTP-extensie
- **Versiebeheer**: Git, repo op GitHub (`Joge10/inlinecomp`)

## 18. Bekende zwakten

1. **Internet-afhankelijk** — zonder verbinding staat de wedstrijd stil. Zie
   [`offline-modus-spec.md`](offline-modus-spec.md) voor het open ontwerp-
   vraagstuk.
2. **Geen CSRF-bescherming** — prepared statements beschermen tegen SQL-
   injectie, maar een ingelogde admin die op een kwaadaardige link klikt is
   kwetsbaar. Oplossing: CSRF-token per sessie.
3. **Geen rate-limiting op login** — bruteforce op zwakke wachtwoorden is
   theoretisch mogelijk.
4. **Shared-hosting risico** — buren op dezelfde server zijn een
   onderschatte factor bij serieuze KNSB-data.

Zie de bespreking met Claude over security-audit (april 2026) voor meer
achtergrond.

## 19. Recente feature-hoogtepunten

Chronologisch, meest recente bovenaan:

- **AVG-rijderbeheer** — anonimiseren met behoud van wedstrijdhistorie,
  publieke privacyverklaring (april 2026)
- **Transponder-beheer uitbreidingen** — KNSB-licentienummer als koppel-veld,
  Excel-stijl sort-iconen, filter-knop in Betaald-kolom
- **Serie-klassement** — volwaardige wizard + berekening over meerdere
  wedstrijden, met finale-gating en cluster-klassement
- **Coach-view** — aparte ingang met multi-rijder + pull-to-refresh
- **Publieke multi-rijder** — kind-tabs (max 4) persisted via license_key
- **Race-type op distance-niveau** — sprint/inline/puntenkoers/afvalkoers
  (ENUM op `distances`)
- **Sancties volgens World Skate 2026** — uitgebreid voor internationale
  wedstrijden
- **DB-opschoning** — 37 tabellen, één file per tabel, ER-diagrammen

## 20. Contact

Ontwikkeld door Geert de Vries. Project-vragen:
`inlinecomp@devriesen.com`.
