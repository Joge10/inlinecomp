# InlineComp — Database-schema

Grafisch overzicht van alle 37 tabellen en hun onderlinge relaties, opgesplitst
per logisch domein zodat het leesbaar blijft. De diagrammen zijn geschreven in
[Mermaid](https://mermaid.js.org/) en renderen automatisch in:

- GitHub / GitLab (gewoon in de browser openen)
- VS Code met de **Markdown Preview Mermaid Support**-extensie (`Ctrl+Shift+V`)
- Pandoc → HTML/PDF met de juiste filter

Zie `db/*.sql` voor de complete `CREATE TABLE`-statements per tabel.

---

## 0. Overzicht op hoofdlijnen

Zes logische domeinen, met de belangrijkste kruisverbanden:

```mermaid
flowchart LR
    subgraph META [Meta en personen]
        persons
        organisaties
        users
    end

    subgraph WED [Wedstrijd-setup]
        competitions
        distance_combinations
        distances
        entries
    end

    subgraph TS [Tijdschema]
        competition_tijdschema
        tijdschema_ritten
    end

    subgraph LIVE [Wedstrijd-verloop]
        heats
        heat_entries
        results
    end

    subgraph ARCH [Archief]
        uitslag_afstand
        uitslag_klassement
    end

    subgraph KL [Klassementen]
        klassementen
        klassement_series
    end

    WED --> TS
    WED --> LIVE
    LIVE --> ARCH
    ARCH --> KL
    META -.-> WED
    META -.-> LIVE
```

---

## 1. Meta en personen

Stabiele data die over meerdere wedstrijden relevant is: rijders, organisaties,
gebruikers van InlineComp.

```mermaid
erDiagram
    persons {
        varchar license_key PK "KNSB licentienummer"
        varchar full_name
        varchar short_name
        smallint birth_year
        tinyint gender
        varchar category
        varchar club_short
        varchar club_full
        smallint start_number
        datetime anonymized_at "AVG: indien gezet -> rijder gewist"
    }

    organisaties {
        varchar id PK
        varchar naam UK
        varchar logo_path
        varchar email
    }

    organisatie_aliassen {
        varchar id PK
        varchar organisatie_id FK
        varchar naam UK "Alternatieve schrijfwijzen voor matching"
    }

    organisatie_sponsors {
        varchar id PK
        varchar organisatie_id FK
        varchar naam
        varchar logo_path
        varchar url
        tinyint volgorde
    }

    organisatie_transponders {
        int id PK
        varchar organisatie_id FK
        varchar intern_nummer
        varchar transponder_code
        varchar person_license "Toegewezen aan rijder (KNSB-licentie)"
        smallint toegewezen_snr
        varchar toegewezen_naam
        tinyint betaald
        date betaald_op
    }

    users {
        int id PK
        varchar username UK
        varchar password_hash "bcrypt"
        enum role "owner/admin/importer/planner/timer/viewer"
        tinyint actief
    }

    sessions {
        char token PK "64 hex tekens"
        int user_id FK
        datetime expires_at
    }

    login_logs {
        int id PK
        int user_id FK "NULL na user-delete"
        varchar username
        varchar actie "login/logout/failed"
        varchar ip_adres
        varchar land
        varchar stad
        datetime tijdstip
    }

    organisaties ||--o{ organisatie_aliassen    : heeft
    organisaties ||--o{ organisatie_sponsors    : heeft
    organisaties ||--o{ organisatie_transponders: bezit
    users        ||--o{ sessions                : heeft
    users        ||--o{ login_logs              : logt
```

---

## 2. Wedstrijd-setup

Alles dat vóór de wedstrijddag vastligt: de wedstrijd zelf, welke categoriën,
welke afstanden, wie is ingeschreven.

```mermaid
erDiagram
    competitions {
        varchar id PK "KNSB competition UUID"
        varchar name
        datetime starts
        datetime ends
        varchar location
        varchar venue_name
        varchar organisatie_id "soft FK -- null toegestaan"
        int entries_version
        int tijdschema_version
    }

    competition_instellingen {
        varchar competition_id PK "FK"
        enum dns_dnf_methode "vast_99 of laatste_positie"
    }

    competition_startnummers {
        int id PK
        varchar competition_id FK
        varchar person_license FK
        smallint startnummer "Override op persons.start_number"
    }

    distance_combinations {
        varchar id PK "KNSB DC UUID"
        varchar competition_id FK
        varchar name "bv. HPA + DPA samen"
        varchar merge_group
        varchar merge_label
    }

    dc_splits {
        varchar competition_id
        varchar dc_id PK "FK"
        varchar category PK
        varchar split_group "voor klassement-opsplitsing binnen gemengde DC"
    }

    distances {
        varchar id PK "composite PK: dc+id"
        varchar distance_combination_id PK "FK"
        varchar name "bv. 500m sprint"
        int value_meters
        enum race_type "sprint/inline/puntenkoers/afvalkoers"
    }

    entries {
        int id PK
        varchar distance_combination_id FK
        varchar person_license FK
        tinyint status "1=getekend, 2=aangemeld, 3=afgemeld, 4=niet getekend"
    }

    transponders {
        int id PK
        varchar person_license FK
        varchar competition_id FK
        tinyint slot "0=actief, 1/2=KNSB, 3+=extra"
        varchar code
        enum source "knsb of manual"
    }

    point_systems {
        int id PK
        varchar competition_id FK
        varchar distance_combination_id FK
        varchar distance_id
        json punten_reeks
        enum dns_dnf_methode
    }

    competitions ||--o| competition_instellingen : heeft
    competitions ||--o{ competition_startnummers : override
    competitions ||--o{ distance_combinations    : bevat
    competitions ||--o{ transponders             : registreert
    competitions ||--o{ point_systems            : definieert
    distance_combinations ||--o{ distances       : bevat
    distance_combinations ||--o{ dc_splits       : splits
    distance_combinations ||--o{ entries         : ontvangt
    distance_combinations ||--o{ point_systems   : definieert
```

---

## 3. Tijdschema en programma

Het tijdschema per wedstrijd met blokken (programma-onderdelen) en ritten
(concrete heats met hun starttijd). Dit wordt ruim vóór de wedstrijd opgesteld.

```mermaid
erDiagram
    competition_tijdschema {
        int id PK
        varchar competition_id FK UK
        enum systeem "full-final / internationaal-nieuw"
        enum status "concept / gepubliceerd"
        datetime gegenereerd_op
    }

    tijdschema_blokken {
        int id PK
        int tijdschema_id FK
        smallint volgorde
        enum blok_type "ronde/pauze/inrijden/wedstrijdstart/ceremonie/herstart"
        varchar afstand_naam
        enum ronde_type "heats/kwartfinale/halve_finale/runner_up/finale"
        smallint duur
        time tijdstip
    }

    tijdschema_afstand_config {
        int id PK
        int tijdschema_id FK
        varchar afstand_naam UK
        tinyint finale_heat_grootte
        tinyint finale_b_grootte
        enum finale_seeding "slang / tijdkoppeling"
        enum race_type "sprint / long_distance"
        enum heats_ranking
        enum kwart_ranking
        enum half_ranking
        enum finale_ranking
    }

    tijdschema_cat_config {
        int id PK
        int tijdschema_id FK
        varchar dc_id FK
        varchar distance_id
        tinyint heeft_heats
        tinyint heats_aantal
        smallint heats_q
        tinyint heeft_kwartfinale
        tinyint heeft_halve_finale
        tinyint finale_heats
        tinyint finale_a_grootte
    }

    tijdschema_ritten {
        int id PK
        int tijdschema_id FK
        int blok_id FK
        smallint volgorde
        time tijdstip_override
        varchar dc_id FK
        varchar distance_id
        enum ronde_type
        tinyint heat_nr
        varchar rit_naam
        tinyint verwacht
    }

    competition_tijdschema ||--o{ tijdschema_blokken         : programma
    competition_tijdschema ||--o{ tijdschema_afstand_config  : per-afstand
    competition_tijdschema ||--o{ tijdschema_cat_config      : per-categorie
    competition_tijdschema ||--o{ tijdschema_ritten          : ritten
    tijdschema_blokken     ||--o{ tijdschema_ritten          : bevat
```

---

## 4. Wedstrijd-verloop (live)

Wordt ingevuld op de wedstrijddag zelf. `heats` ontstaat ronde-voor-ronde,
gekoppeld aan een vooraf gedefinieerde `tijdschema_ritten`-slot.

```mermaid
erDiagram
    heats {
        int id PK
        varchar competition_id FK
        varchar distance_combination_id FK
        varchar distance_id
        varchar split_group
        tinyint ronde "1=series, 2=KF, 3=HF, 4=finale"
        int tijdschema_rit_id FK "via welk rit-slot"
        smallint rit_volgorde
        varchar heat_naam
        tinyint heat_nr
        datetime geplande_starttijd
        varchar race_type
    }

    heat_entries {
        int id PK
        int heat_id FK
        varchar person_license FK
        varchar categorie
        tinyint startpositie UK "per heat"
        smallint startnummer
    }

    results {
        int id PK
        int heat_entry_id FK UK
        tinyint finishpositie
        int tijd_ms "in ms"
        enum sanctie "W1/W2/FS/RR/DQ-TF/DQ-SF/DQ-DF/DNS/DNF"
        smallint rondes "voor lange afstand/puntenkoers"
        decimal punten
        varchar notitie
    }

    heats        ||--o{ heat_entries : startlijst
    heat_entries ||--o| results      : uitslag
```

Externe verbanden (FK's naar andere domeinen):
- `heats.competition_id` → `competitions`
- `heats.distance_combination_id` → `distance_combinations`
- `heats.tijdschema_rit_id` → `tijdschema_ritten`
- `heat_entries.person_license` → `persons`

---

## 5. Archief (uitslagen)

Gedenormaliseerd, zodat een wedstrijd verwijderd kan worden zonder de
historische uitslagen te verliezen. **Bewust géén FK naar competitions.**

```mermaid
erDiagram
    uitslag_afstand {
        int id PK
        varchar competition_id "soft FK (geen CASCADE)"
        varchar competition_naam "gedenormaliseerd voor archief"
        date competition_datum
        varchar distance_combination_id
        varchar dc_naam
        varchar split_group
        varchar distance_id
        varchar distance_naam
        int distance_meters
        varchar person_license FK
        varchar categorie
        smallint rang
        tinyint finale_positie
        varchar finale_naam "A / B / ..."
        int tijd_ms
        decimal punten
        enum sanctie
    }

    uitslag_klassement {
        int id PK
        varchar competition_id "soft FK (geen CASCADE)"
        varchar competition_naam "gedenormaliseerd"
        date competition_datum
        varchar distance_combination_id
        varchar dc_naam
        varchar split_group
        varchar person_license FK
        varchar categorie
        smallint rang
        decimal punten_totaal
        json punten_detail
    }

    persons ||--o{ uitslag_afstand    : historie
    persons ||--o{ uitslag_klassement : historie
```

---

## 6. Klassementen en series

Meerdaagse of seizoensklassementen. `klassementen` is de "container" (zowel
voor PDF-imports als voor serie-klassementen). Voor series bouwt
`klassement_series` daarop voort.

```mermaid
erDiagram
    klassementen {
        varchar id PK
        varchar naam
        varchar seizoen
        varchar bron_bestand "PDF-bestandsnaam bij import"
        json categorieen
        json wedstrijden_meta
        int totaal_rijders
        varchar org_id "soft FK"
    }

    klassement_posities {
        varchar id PK
        varchar klassement_id FK
        int positie
        varchar start_number
        varchar license_key "soft link naar persons"
        varchar naam
        varchar categorie
        json punten_detail "per-wedstrijd punten"
        decimal punten_totaal
    }

    klassement_series {
        varchar id PK
        varchar klassement_id FK UK "1-1 met klassementen"
        varchar naam
        varchar seizoen
        varchar org_id "soft FK"
        json regels "type, afstand_filter, punten_tabel, ..."
        datetime herberekend_op
    }

    klassement_serie_wedstrijden {
        varchar serie_id PK "FK"
        varchar competition_id PK "GEEN FK -- KNSB-UUID kan zonder import"
        tinyint telt_mee
        tinyint is_finale
        smallint volgorde
        varchar comp_naam "fallback als comp niet geimporteerd"
        datetime comp_datum
    }

    klassement_presets {
        varchar id PK
        varchar org_id "NULL = globale preset"
        varchar naam "bv. KNSB tabel 2026"
        json regels
    }

    series {
        int id PK
        varchar naam
        varchar seizoen
        varchar discipline
    }

    series_wedstrijden {
        int series_id PK "FK"
        varchar competition_id PK "FK"
        tinyint volgorde
    }

    klassementen      ||--o{ klassement_posities          : posities
    klassementen      ||--|| klassement_series            : "als serie"
    klassement_series ||--o{ klassement_serie_wedstrijden : wedstrijden
    series            ||--o{ series_wedstrijden           : koppelt
```

---

## 7. Bezoek-statistieken

Anonieme telling voor het dashboard in Beheer → Gebruikers. Géén IP-opslag,
géén tracking-cookies — alleen geaggregeerde sessie-teltekens.

```mermaid
erDiagram
    public_visits {
        int id PK
        varchar session_id UK "random cookie-waarde"
        datetime first_seen
        datetime last_seen
        int hits
    }

    coach_visits {
        int id PK
        varchar session_id UK
        datetime first_seen
        datetime last_seen
        int hits
    }

    peak_stats {
        varchar scope PK "public of coach"
        int peak_today
        date peak_today_date
        int peak_all_time
        datetime peak_all_time_at
    }
```

Geen onderlinge FK's; `peak_stats` wordt afgeleid uit de twee andere tabellen
door periodiek een query te draaien.

---

## Legenda

- **FK** — foreign key (harde verwijzing, meestal met `ON DELETE CASCADE`)
- **UK** — unique key
- **PK** — primary key (of onderdeel van een composite PK)
- **soft FK** — verwijzing die geen database-constraint heeft (bewust, bv. om
  KNSB-UUIDs op te slaan zonder een rij in `competitions` te vereisen, of om
  archieven te behouden na wedstrijd-verwijdering)

## Renderen naar een plaatje

Voor in een presentatie o.i.d. een PNG/SVG genereren van dit bestand:

```bash
# Installeer éénmalig mermaid-cli (heb je Node al)
npm install -g @mermaid-js/mermaid-cli

# Genereer een SVG van één diagram (via een klein PS/Bash-knipsel)
mmdc -i docs/database-schema.md -o docs/database-schema.png
```

Of bekijk het bestand in GitHub / VS Code — daar renderen ze direct.
