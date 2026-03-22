-- ============================================================
--  InlineComp – Archief: einduitslag & klassementen
--  Voer uit nádat schema.sql en schema_heats.sql zijn uitgevoerd.
--
--  Filosofie:
--    Operationele tabellen (heats, heat_entries, results, entries,
--    transponders, competition_startnummers) mogen na afsluiting
--    van een wedstrijd worden verwijderd om ruimte te sparen.
--
--    Deze twee tabellen blijven ALTIJD bewaard en zijn voldoende
--    voor:
--      • terugzoeken resultaten per persoon (speaker)
--      • competitie-klassement over meerdere wedstrijden
--      • historisch overzicht
--
--    Namen worden gedenormaliseerd opgeslagen zodat records
--    leesbaar blijven ook als distance_combinations / distances
--    later worden opgeschoond.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Einduitslag per deelnemer per afstand
--
--   Eén record per (wedstrijd × afstand × rijder).
--   Bij split-groepen: split_group gevuld (bijv. 'DS Sprint').
--
--   rang              : overall positie in deze afstand
--                       A-finale 1e = rang 1
--                       B-finale 1e = rang (grootte_A + 1), etc.
--   finale_positie    : positie in de finale zelf (1, 2, 3 …)
--   finale_naam       : 'A-finale', 'B-finale', 'Tijdrit' …
--   tijd_ms           : snelste / finale tijd in ms, NULL als geen timing
--   punten            : toegekende klassementspunten (DECIMAL voor 0.9-reeksen)
--   sanctie           : DC of DSQ-SF als van toepassing
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uitslag_afstand (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Wedstrijd (blijft bewaard)
    competition_id          VARCHAR(36)     NOT NULL,
    competition_naam        VARCHAR(255)    NOT NULL,   -- gedenorm.
    competition_datum       DATE            DEFAULT NULL,

    -- Distance combination (gedenormaliseerd voor archivering)
    distance_combination_id VARCHAR(36)     NOT NULL,
    dc_naam                 VARCHAR(255)    NOT NULL,   -- gedenorm.
    split_group             VARCHAR(50)     DEFAULT NULL,

    -- Afstand (gedenormaliseerd)
    distance_id             VARCHAR(36)     DEFAULT NULL,
    distance_naam           VARCHAR(100)    NOT NULL,   -- gedenorm.
    distance_meters         INT UNSIGNED    DEFAULT NULL,

    -- Rijder
    person_license          VARCHAR(30)     NOT NULL,
    categorie               VARCHAR(20)     DEFAULT NULL,

    -- Resultaat
    rang                    SMALLINT UNSIGNED DEFAULT NULL,  -- overall positie in deze afstand
    finale_positie          TINYINT UNSIGNED  DEFAULT NULL,  -- positie in finale
    finale_naam             VARCHAR(50)     DEFAULT NULL,    -- 'A-finale', 'Tijdrit' …
    tijd_ms                 INT UNSIGNED    DEFAULT NULL,
    punten                  DECIMAL(8,3)    DEFAULT NULL,
    sanctie                 ENUM('DC','DSQ-SF','DNS','DNF') DEFAULT NULL,

    vastgelegd_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_ua_kern (
        competition_id, distance_combination_id,
        distance_id, split_group, person_license
    ),
    KEY idx_ua_person       (person_license),
    KEY idx_ua_competition  (competition_id),
    KEY idx_ua_dc           (distance_combination_id),

    -- FK naar blijvende tabellen
    CONSTRAINT fk_ua_competition
        FOREIGN KEY (competition_id)  REFERENCES competitions (id),
    CONSTRAINT fk_ua_person
        FOREIGN KEY (person_license)  REFERENCES persons (license_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Eindklassement per deelnemer per distance combination
--
--   Eén record per (wedstrijd × DC × rijder).
--   Bevat het gecombineerde resultaat over alle afstanden binnen
--   die DC, inclusief de tiebreaker-informatie.
--
--   punten_totaal     : som van punten over alle afstanden
--   punten_detail     : JSON { "500 meter": 1.0, "Puntenkoers 3km": 3.0 }
--                       bewaard voor transparantie en tiebreaker-display
--   rang              : eindpositie in het klassement
--
--   Tiebreaker-volgorde (conform reglement):
--     1. laagste punten_totaal
--     2. beste individuele afstandsrang  (min van punten_detail.values)
--     3. resultaat laatste afstand
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uitslag_klassement (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Wedstrijd
    competition_id          VARCHAR(36)     NOT NULL,
    competition_naam        VARCHAR(255)    NOT NULL,
    competition_datum       DATE            DEFAULT NULL,

    -- DC (gedenormaliseerd)
    distance_combination_id VARCHAR(36)     NOT NULL,
    dc_naam                 VARCHAR(255)    NOT NULL,
    split_group             VARCHAR(50)     DEFAULT NULL,

    -- Rijder
    person_license          VARCHAR(30)     NOT NULL,
    categorie               VARCHAR(20)     DEFAULT NULL,

    -- Klassement
    rang                    SMALLINT UNSIGNED DEFAULT NULL,
    punten_totaal           DECIMAL(10,3)   DEFAULT NULL,
    punten_detail           JSON            DEFAULT NULL,  -- per afstand, voor display & tiebreaker

    vastgelegd_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_uk_kern (
        competition_id, distance_combination_id, split_group, person_license
    ),
    KEY idx_uk_person       (person_license),
    KEY idx_uk_competition  (competition_id),

    CONSTRAINT fk_uk_competition
        FOREIGN KEY (competition_id)  REFERENCES competitions (id),
    CONSTRAINT fk_uk_person
        FOREIGN KEY (person_license)  REFERENCES persons (license_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Competitiereeksen  (meerdere wedstrijden die samen een serie vormen)
--   Toekomst: seizoensklassement, regiocompetitie, etc.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS series (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    naam        VARCHAR(255)    NOT NULL,
    seizoen     VARCHAR(10)     DEFAULT NULL,  -- bijv. '2025-2026'
    discipline  VARCHAR(100)    DEFAULT NULL,
    omschrijving TEXT           DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS series_wedstrijden (
    series_id       INT UNSIGNED    NOT NULL,
    competition_id  VARCHAR(36)     NOT NULL,
    volgorde        TINYINT UNSIGNED DEFAULT NULL,  -- wedstrijd 1, 2, 3 … in de serie
    PRIMARY KEY (series_id, competition_id),
    CONSTRAINT fk_sw_series
        FOREIGN KEY (series_id)       REFERENCES series (id) ON DELETE CASCADE,
    CONSTRAINT fk_sw_competition
        FOREIGN KEY (competition_id)  REFERENCES competitions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  Opschonen na afsluiten wedstrijd
--  Voer onderstaand blok uit als alle uitslag_* tabellen gevuld zijn
--  en de wedstrijd definitief is afgesloten.
--
--  Vervang {COMP_ID} door het UUID van de wedstrijd.
-- ============================================================
/*
DELETE FROM results              WHERE heat_entry_id IN
    (SELECT he.id FROM heat_entries he JOIN heats h ON h.id = he.heat_id
     WHERE h.competition_id = '{COMP_ID}');
DELETE FROM heat_entries         WHERE heat_id IN
    (SELECT id FROM heats WHERE competition_id = '{COMP_ID}');
DELETE FROM heats                WHERE competition_id = '{COMP_ID}';
DELETE FROM entries              WHERE distance_combination_id IN
    (SELECT id FROM distance_combinations WHERE competition_id = '{COMP_ID}');
DELETE FROM transponders         WHERE competition_id = '{COMP_ID}';
DELETE FROM competition_startnummers WHERE competition_id = '{COMP_ID}';
DELETE FROM point_systems        WHERE competition_id = '{COMP_ID}';
DELETE FROM competition_instellingen WHERE competition_id = '{COMP_ID}';
*/
