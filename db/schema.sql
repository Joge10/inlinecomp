-- ============================================================
--  InlineComp – Cloud database schema (MySQL)
--  Voer dit script één keer uit via phpMyAdmin of CLI.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Personen  (individuele atleten)
--
--   PK = license_key  → het KNSB licentienummer is de ENIGE
--   stabiele, unieke sleutel over alle wedstrijden heen.
--   De UUID die de KNSB-API per competitor teruggeeft is
--   per competitie anders en is dus ongeschikt als PK.
--   Ook gastrijders krijgen een licentienummer zodat ze bij
--   een volgende wedstrijd herkenbaar blijven.
--
--   start_number: persoonlijk, NIET uniek
--     - zelfde nummers bestaan bij vrouwen én mannen
--     - nummers worden hergebruikt na vervallen licentie
--     - wijzigt maximaal 1× in een carrière
--       (t/m Junioren B houd je je nr., vanaf Junioren A nieuw)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS persons (
    license_key  VARCHAR(30)   NOT NULL,          -- KNSB licentienummer  ← PK
    full_name    VARCHAR(255)  NOT NULL,
    short_name   VARCHAR(100),
    birth_year   SMALLINT      UNSIGNED,
    gender       TINYINT       UNSIGNED,           -- 0=man  1=vrouw  (conform KNSB API)
    category     VARCHAR(20),                      -- DKA, HKA, DJB …
    nationality  VARCHAR(3)    DEFAULT 'NED',
    start_number SMALLINT      UNSIGNED,
    club_code    INT           UNSIGNED,
    club_short   VARCHAR(20),
    club_full    VARCHAR(255),
    city         VARCHAR(100),
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (license_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Wedstrijden
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competitions (
    id           VARCHAR(36)  NOT NULL,            -- KNSB competition UUID  ← PK
    name         VARCHAR(255) NOT NULL,
    starts       DATETIME,
    ends         DATETIME,
    location     TEXT,                             -- ruwe tekst uit API
    venue_name   VARCHAR(255),
    venue_city   VARCHAR(100),
    discipline   VARCHAR(100),
    imported_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Afstandscombinaties  (categorieën per wedstrijd)
--   bijv. "Meisjes Kadetten" in competitie X
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS distance_combinations (
    id              VARCHAR(36)  NOT NULL,         -- KNSB DC UUID  ← PK
    competition_id  VARCHAR(36)  NOT NULL,
    number          TINYINT      UNSIGNED,          -- volgorde binnen wedstrijd
    name            VARCHAR(255),
    category_filter VARCHAR(20),                   -- DKA*, HKA* …
    merge_group     VARCHAR(50)  DEFAULT NULL,      -- samengevoegde startlijstgroep (bv. 'Junior')
    PRIMARY KEY (id),
    KEY idx_dc_competition (competition_id),
    CONSTRAINT fk_dc_competition
        FOREIGN KEY (competition_id)
        REFERENCES competitions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Afstanden per categorie
--   bijv. 500m en Puntenkoers 3km binnen "Meisjes Kadetten"
-- ------------------------------------------------------------
-- BELANGRIJK: de KNSB distance UUID is NIET globaal uniek —
-- dezelfde UUID (bijv. "500 meter") komt voor in meerdere DCs.
-- De PK is daarom samengesteld: (distance_combination_id, id).
CREATE TABLE IF NOT EXISTS distances (
    id                      VARCHAR(36)  NOT NULL, -- KNSB distance UUID (hergebruikt)
    distance_combination_id VARCHAR(36)  NOT NULL,
    number                  TINYINT      UNSIGNED,
    name                    VARCHAR(100),
    value_meters            INT          UNSIGNED, -- 500, 3000 …
    discipline              VARCHAR(100),
    starts                  DATETIME,
    PRIMARY KEY (distance_combination_id, id),
    CONSTRAINT fk_dist_dc
        FOREIGN KEY (distance_combination_id)
        REFERENCES distance_combinations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Inschrijvingen  (persoon in een afstandscombinatie)
--
--   person_license → persons(license_key)
--   knsb_entry_id  → de per-competitie wisselende UUID van de
--                    KNSB API (alleen voor referentie opgeslagen,
--                    nooit als sleutel gebruikt)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entries (
    id                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    distance_combination_id VARCHAR(36)   NOT NULL,
    person_license          VARCHAR(30)   NOT NULL,  -- → persons(license_key)
    knsb_entry_id           VARCHAR(36),             -- tijdelijke UUID uit API
    status                  TINYINT       UNSIGNED DEFAULT 1,  -- 1=confirmed
    PRIMARY KEY (id),
    UNIQUE KEY uq_entry (distance_combination_id, person_license),
    KEY idx_entry_person (person_license),
    CONSTRAINT fk_entry_dc
        FOREIGN KEY (distance_combination_id)
        REFERENCES distance_combinations (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_entry_person
        FOREIGN KEY (person_license)
        REFERENCES persons (license_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Transponders  (per persoon per wedstrijd)
--
--   source = 'knsb'   → ingelezen via API, mag worden overschreven
--   source = 'manual' → handmatig ingevoerd, blijft staan bij herimporten
--
--   person_license → persons(license_key)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transponders (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    person_license  VARCHAR(30)   NOT NULL,          -- → persons(license_key)
    competition_id  VARCHAR(36)   NOT NULL,
    slot            TINYINT       UNSIGNED NOT NULL, -- 1 of 2
    code            VARCHAR(50),                     -- bijv. KS-44038
    source          ENUM('knsb','manual') NOT NULL DEFAULT 'knsb',
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transponder (person_license, competition_id, slot),
    KEY idx_tp_code (code),
    CONSTRAINT fk_tp_person
        FOREIGN KEY (person_license)
        REFERENCES persons (license_key),
    CONSTRAINT fk_tp_competition
        FOREIGN KEY (competition_id)
        REFERENCES competitions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Splits  (één DC opsplitsen in meerdere startlijstgroepen)
--
--   Gebruikt wanneer een organisatie alle categorieën in
--   één distance_combination plaatst.
--   category  = KNSB categorie-code (bijv. DKA, HKA, DJA …)
--   split_group = gewenste groepsnaam in de startlijst
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dc_splits (
    competition_id  VARCHAR(36)  NOT NULL,
    dc_id           VARCHAR(36)  NOT NULL,
    category        VARCHAR(20)  NOT NULL,
    split_group     VARCHAR(50)  NOT NULL,
    PRIMARY KEY (dc_id, category),
    KEY idx_splits_comp (competition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
