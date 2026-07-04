-- InlineComp – survey_oh850 (anonieme survey-antwoorden OH850)
--
-- Bewust GEEN koppeling met survey_oh850_vragen: een respondent die
-- z'n email achterlaat voor follow-up-vragen krijgt z'n vraag in een
-- aparte tabel, zonder gedeelde id/foreign-key. Inserts gebeuren via
-- twee losse statements en survey_oh850_vragen gebruikt z'n eigen
-- submitted_at met een random jitter, zodat de timestamps niet exact
-- gelijk vallen en correlatie achteraf onmogelijk is.
--
-- Schaal-vragen 1..5 zijn TINYINT UNSIGNED NULL: NULL = niet beantwoord
-- (respondent mag een vraag overslaan, of de hele app-sectie niet gebruikt).
-- Per-app scoring (score_public_*, score_coach_*, score_check_*) wordt
-- alleen ingevuld als de respondent die app heeft gebruikt.

CREATE TABLE IF NOT EXISTS `survey_oh850` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submitted_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lang`                VARCHAR(2)    DEFAULT NULL,

    -- Welke app heeft de respondent gebruikt? (multi-select)
    `used_public`         BOOLEAN       NOT NULL DEFAULT 0,
    `used_coach`          BOOLEAN       NOT NULL DEFAULT 0,
    `used_check`          BOOLEAN       NOT NULL DEFAULT 0,
    `used_geen`           BOOLEAN       NOT NULL DEFAULT 0,
    `used_unaware`        BOOLEAN       NOT NULL DEFAULT 0,

    -- Bij welke wedstrijd(en) heeft de respondent InlineComp gebruikt?
    -- Komma-gescheiden UUID's — multi-select uit competitions van huidig
    -- en afgelopen seizoen (public_zichtbaar=1). Aparte tabel is overkill
    -- voor deze use-case; blijft parse-baar met SUBSTRING_INDEX in SQL.
    `competition_ids`     TEXT          DEFAULT NULL,

    -- Algemeen (over InlineComp als geheel)
    `score_algemeen`      TINYINT UNSIGNED DEFAULT NULL,
    `score_nps`           TINYINT UNSIGNED DEFAULT NULL,

    -- Per-app scores (alleen ingevuld als used_<app> op true stond)
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

    -- Vergelijking met bestaande tools (multi-select)
    `kent_sportity`       BOOLEAN       NOT NULL DEFAULT 0,
    `kent_skateresults`   BOOLEAN       NOT NULL DEFAULT 0,
    `kent_combinatie`     BOOLEAN       NOT NULL DEFAULT 0,
    `kent_anders`         BOOLEAN       NOT NULL DEFAULT 0,
    `kent_geen`           BOOLEAN       NOT NULL DEFAULT 0,
    `kent_anders_naam`    VARCHAR(80)   DEFAULT NULL,
    `score_vergelijking`  TINYINT UNSIGNED DEFAULT NULL,

    -- Ontwikkelingsrichting sinds vorige keer (5-punts schaal).
    -- ontwikkeling_eerste_keer=1 → schaal niet zinvol (score_ontwikkeling NULL).
    `score_ontwikkeling`       TINYINT UNSIGNED DEFAULT NULL,
    `ontwikkeling_eerste_keer` BOOLEAN          NOT NULL DEFAULT 0,

    -- Open vragen (op het einde, optioneel)
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
