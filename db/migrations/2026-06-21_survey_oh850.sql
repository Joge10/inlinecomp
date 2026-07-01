-- Migratie 2026-06-21 — anonieme survey OH850
--
-- Maakt twee aparte tabellen aan voor de feedback-survey van Open Heerde 850:
--   - survey_oh850         : anonieme antwoorden (per-app scores + open vragen)
--   - survey_oh850_vragen  : email + vraag (alleen ingevuld als respondent wil
--                            dat Geert reageert). BEWUST losse tabel, zonder
--                            foreign key naar survey_oh850, om koppeling
--                            onmogelijk te maken — backend doet een random
--                            sleep-jitter tussen beide INSERTs zodat ook
--                            timestamp-correlatie afvalt.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS — veilig om meerdere keren te draaien.
--
-- Bron-of-truth voor het volledige schema: db/survey_oh850.sql en
-- db/survey_oh850_vragen.sql. Op verse installaties die files importeren;
-- op bestaande installaties is deze migratie voldoende.

CREATE TABLE IF NOT EXISTS `survey_oh850` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submitted_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lang`                VARCHAR(2)    DEFAULT NULL,
    `used_public`         BOOLEAN       NOT NULL DEFAULT 0,
    `used_coach`          BOOLEAN       NOT NULL DEFAULT 0,
    `used_check`          BOOLEAN       NOT NULL DEFAULT 0,
    `used_geen`           BOOLEAN       NOT NULL DEFAULT 0,
    `used_unaware`        BOOLEAN       NOT NULL DEFAULT 0,
    `score_algemeen`      TINYINT UNSIGNED DEFAULT NULL,
    `score_nps`           TINYINT UNSIGNED DEFAULT NULL,
    `score_public_snelheid`   TINYINT UNSIGNED DEFAULT NULL,
    `score_public_mobiel`     TINYINT UNSIGNED DEFAULT NULL,
    `score_public_uitslagen`  TINYINT UNSIGNED DEFAULT NULL,
    `score_public_programma`  TINYINT UNSIGNED DEFAULT NULL,
    `score_coach_snelheid`    TINYINT UNSIGNED DEFAULT NULL,
    `score_coach_mobiel`      TINYINT UNSIGNED DEFAULT NULL,
    `score_coach_uitslagen`   TINYINT UNSIGNED DEFAULT NULL,
    `score_coach_volgen`      TINYINT UNSIGNED DEFAULT NULL,
    `score_check_snelheid`    TINYINT UNSIGNED DEFAULT NULL,
    `score_check_mobiel`      TINYINT UNSIGNED DEFAULT NULL,
    `score_check_duidelijk`   TINYINT UNSIGNED DEFAULT NULL,
    `kent_sportity`       BOOLEAN       NOT NULL DEFAULT 0,
    `kent_skateresults`   BOOLEAN       NOT NULL DEFAULT 0,
    `kent_combinatie`     BOOLEAN       NOT NULL DEFAULT 0,
    `kent_anders`         BOOLEAN       NOT NULL DEFAULT 0,
    `kent_geen`           BOOLEAN       NOT NULL DEFAULT 0,
    `kent_anders_naam`    VARCHAR(80)   DEFAULT NULL,
    `score_vergelijking`  TINYINT UNSIGNED DEFAULT NULL,
    `tip_open`            TEXT          DEFAULT NULL,
    `miste_open`          TEXT          DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_submitted_at` (`submitted_at`),
    CONSTRAINT `chk_algemeen`         CHECK (`score_algemeen`         IS NULL OR `score_algemeen`         BETWEEN 1 AND 5),
    CONSTRAINT `chk_nps`              CHECK (`score_nps`              IS NULL OR `score_nps`              BETWEEN 1 AND 5),
    CONSTRAINT `chk_public_snelheid`  CHECK (`score_public_snelheid`  IS NULL OR `score_public_snelheid`  BETWEEN 1 AND 5),
    CONSTRAINT `chk_public_mobiel`    CHECK (`score_public_mobiel`    IS NULL OR `score_public_mobiel`    BETWEEN 1 AND 5),
    CONSTRAINT `chk_public_uitslagen` CHECK (`score_public_uitslagen` IS NULL OR `score_public_uitslagen` BETWEEN 1 AND 5),
    CONSTRAINT `chk_public_programma` CHECK (`score_public_programma` IS NULL OR `score_public_programma` BETWEEN 1 AND 5),
    CONSTRAINT `chk_coach_snelheid`   CHECK (`score_coach_snelheid`   IS NULL OR `score_coach_snelheid`   BETWEEN 1 AND 5),
    CONSTRAINT `chk_coach_mobiel`     CHECK (`score_coach_mobiel`     IS NULL OR `score_coach_mobiel`     BETWEEN 1 AND 5),
    CONSTRAINT `chk_coach_uitslagen`  CHECK (`score_coach_uitslagen`  IS NULL OR `score_coach_uitslagen`  BETWEEN 1 AND 5),
    CONSTRAINT `chk_coach_volgen`     CHECK (`score_coach_volgen`     IS NULL OR `score_coach_volgen`     BETWEEN 1 AND 5),
    CONSTRAINT `chk_check_snelheid`   CHECK (`score_check_snelheid`   IS NULL OR `score_check_snelheid`   BETWEEN 1 AND 5),
    CONSTRAINT `chk_check_mobiel`     CHECK (`score_check_mobiel`     IS NULL OR `score_check_mobiel`     BETWEEN 1 AND 5),
    CONSTRAINT `chk_check_duidelijk`  CHECK (`score_check_duidelijk`  IS NULL OR `score_check_duidelijk`  BETWEEN 1 AND 5),
    CONSTRAINT `chk_vergelijking`     CHECK (`score_vergelijking`     IS NULL OR `score_vergelijking`     BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `survey_oh850_vragen` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submitted_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `email`           VARCHAR(255)  NOT NULL,
    `vraag`           TEXT          NOT NULL,
    `afgehandeld_at`  DATETIME      DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_submitted_at` (`submitted_at`),
    KEY `idx_afgehandeld`  (`afgehandeld_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
