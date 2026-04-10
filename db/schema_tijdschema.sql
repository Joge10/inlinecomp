-- ============================================================
--  InlineComp – Tijdschema v2
--
--  Vereenvoudigd model:
--   • Systeem (full-final / int-oud / int-nieuw) = wedstrijd-breed
--   • Instellingen per afstandsnaam (geldt voor alle categorieën)
--   • Heats-aantallen per categorie (want deelnemers verschillen)
--   • Programma-volgorde via sleepbare blokken
--
--  Voer uit nádat schema.sql is uitgevoerd.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS tijdschema_cat_config;
DROP TABLE IF EXISTS tijdschema_cat_heats;
DROP TABLE IF EXISTS tijdschema_blokken;
DROP TABLE IF EXISTS tijdschema_ritten;
DROP TABLE IF EXISTS tijdschema_ronde_config;
DROP TABLE IF EXISTS tijdschema_afstand_config;
DROP TABLE IF EXISTS competition_tijdschema;

-- ------------------------------------------------------------
-- competition_tijdschema
--   Één per wedstrijd. Bevat het wedstrijd-brede systeem.
-- ------------------------------------------------------------
CREATE TABLE competition_tijdschema (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    competition_id  VARCHAR(36)   NOT NULL,
    systeem         ENUM('full-final','internationaal-nieuw')
                                  NOT NULL DEFAULT 'full-final',
    status          ENUM('concept','gepubliceerd') NOT NULL DEFAULT 'concept',
    aangemaakt_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ts_competition (competition_id),
    CONSTRAINT fk_ts_competition
        FOREIGN KEY (competition_id) REFERENCES competitions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- tijdschema_afstand_config
--   Gedeelde instellingen per afstandsnaam:
--   Q/q (doorgang kwartfinale + halve finale) en heatgrootte finale.
--   De aan/uit-keuze voor rondes zit per categorie in cat_config.
-- ------------------------------------------------------------
CREATE TABLE tijdschema_afstand_config (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tijdschema_id       INT UNSIGNED  NOT NULL,
    afstand_naam        VARCHAR(100)  NOT NULL,
    q_direct            TINYINT UNSIGNED DEFAULT 1,
    q_tijd              TINYINT UNSIGNED DEFAULT 0,
    finale_heat_grootte TINYINT UNSIGNED NOT NULL DEFAULT 6,
    finale_b_grootte    TINYINT UNSIGNED NOT NULL DEFAULT 6,
    laatste_b_grootste  TINYINT(1)       NOT NULL DEFAULT 1,
    finale_seeding      ENUM('slang','tijdkoppeling') NOT NULL DEFAULT 'slang',
    heeft_runner_up     TINYINT(1)    NOT NULL DEFAULT 0,
    runner_up_max       TINYINT UNSIGNED NOT NULL DEFAULT 6,
    runner_up_min       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tac (tijdschema_id, afstand_naam),
    CONSTRAINT fk_tac_schema
        FOREIGN KEY (tijdschema_id) REFERENCES competition_tijdschema (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- tijdschema_cat_config
--   Ronde-instellingen per categorie (dc + distance).
--   Elke categorie heeft eigen rondes + heats-aantallen.
--
--   heats_q = aantal tijdsnelsten dat doorgaat vanuit heats
--             (naar kwart, of naar half, of naar finale)
-- ------------------------------------------------------------
CREATE TABLE tijdschema_cat_config (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tijdschema_id       INT UNSIGNED  NOT NULL,
    dc_id               VARCHAR(36)   NOT NULL,
    distance_id         VARCHAR(36)   NOT NULL,
    heeft_heats         TINYINT(1)    NOT NULL DEFAULT 1,
    heats_aantal        TINYINT UNSIGNED DEFAULT NULL,
    heats_q             SMALLINT UNSIGNED DEFAULT NULL,
    heats_q_heat        TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    heeft_kwartfinale   TINYINT(1)    NOT NULL DEFAULT 0,
    kwart_heats         TINYINT UNSIGNED DEFAULT NULL,
    kwart_door          SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    kwart_q_heat        TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    heeft_halve_finale  TINYINT(1)    NOT NULL DEFAULT 0,
    half_heats          TINYINT UNSIGNED DEFAULT NULL,
    half_door           SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    half_q_heat         TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    heeft_runner_up     TINYINT(1)    NOT NULL DEFAULT 0,
    finale_heats        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tcc (tijdschema_id, dc_id, distance_id),
    CONSTRAINT fk_tcc_schema
        FOREIGN KEY (tijdschema_id) REFERENCES competition_tijdschema (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- tijdschema_blokken
--   Programma-volgorde: geordende lijst van ronde-blokken en pauzes.
--   Blokken worden auto-aangemaakt bij save_afstand en kunnen
--   daarna door de wedstrijdleider worden herordend.
-- ------------------------------------------------------------
CREATE TABLE tijdschema_blokken (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tijdschema_id   INT UNSIGNED  NOT NULL,
    volgorde        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    blok_type       ENUM('ronde','pauze','inrijden','wedstrijdstart','ceremonie') NOT NULL DEFAULT 'ronde',
    afstand_naam    VARCHAR(100)  DEFAULT NULL,
    ronde_type      ENUM('heats','kwartfinale','halve_finale','runner_up','finale')
                                  DEFAULT NULL,
    duur            SMALLINT UNSIGNED DEFAULT NULL,
    inrijd_cats     TEXT             DEFAULT NULL,
    tijdstip        TIME             DEFAULT NULL,
    heat_duur       TINYINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_tb_schema
        FOREIGN KEY (tijdschema_id) REFERENCES competition_tijdschema (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- tijdschema_ritten
--   Gegenereerde en geordende lijst van alle individuele ritten.
-- ------------------------------------------------------------
CREATE TABLE tijdschema_ritten (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tijdschema_id   INT UNSIGNED  NOT NULL,
    blok_id         INT UNSIGNED  DEFAULT NULL,
    volgorde        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    dc_id           VARCHAR(36)   NOT NULL,
    distance_id     VARCHAR(36)   DEFAULT NULL,
    afstand_naam    VARCHAR(100)  DEFAULT NULL,
    ronde_type      ENUM('heats','kwartfinale','halve_finale',
                         'runner_up','finale_a','finale_b') NOT NULL,
    finale_label    VARCHAR(5)    DEFAULT NULL,
    heat_nr         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    rit_naam        VARCHAR(150)  NOT NULL,
    dc_naam         VARCHAR(255)  NOT NULL,
    verwacht        TINYINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_tr_schema   (tijdschema_id),
    KEY idx_tr_volgorde (tijdschema_id, volgorde),
    CONSTRAINT fk_tr_schema
        FOREIGN KEY (tijdschema_id) REFERENCES competition_tijdschema (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Migratie: ceremonie blok_type toevoegen ───────────────────────────────────
ALTER TABLE tijdschema_blokken
    MODIFY COLUMN blok_type ENUM('ronde','pauze','inrijden','wedstrijdstart','ceremonie') NOT NULL DEFAULT 'ronde';
