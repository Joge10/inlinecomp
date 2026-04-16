-- InlineComp – uitslag_afstand (archief: einduitslag per deelnemer per afstand)
-- Bewust GEEN CASCADE op competition_id: uitslag blijft bewaard na verwijderen wedstrijd.

CREATE TABLE IF NOT EXISTS `uitslag_afstand` (
    `id`                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `competition_id`          VARCHAR(36)     NOT NULL,
    `competition_naam`        VARCHAR(255)    NOT NULL,
    `competition_datum`       DATE            DEFAULT NULL,
    `distance_combination_id` VARCHAR(36)     NOT NULL,
    `dc_naam`                 VARCHAR(255)    NOT NULL,
    `split_group`             VARCHAR(50)     DEFAULT NULL,
    `distance_id`             VARCHAR(36)     DEFAULT NULL,
    `distance_naam`           VARCHAR(100)    NOT NULL,
    `distance_meters`         INT UNSIGNED    DEFAULT NULL,
    `person_license`          VARCHAR(30)     NOT NULL,
    `categorie`               VARCHAR(20)     DEFAULT NULL,
    `rang`                    SMALLINT UNSIGNED DEFAULT NULL,
    `finale_positie`          TINYINT UNSIGNED DEFAULT NULL,
    `finale_naam`             VARCHAR(50)     DEFAULT NULL,
    `tijd_ms`                 INT UNSIGNED    DEFAULT NULL,
    `punten`                  DECIMAL(8,3)    DEFAULT NULL,
    `sanctie`                 ENUM('W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF') DEFAULT NULL,
    `vastgelegd_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ua_kern` (`competition_id`, `distance_combination_id`, `distance_id`, `split_group`, `person_license`),
    KEY `idx_ua_person`      (`person_license`),
    KEY `idx_ua_competition` (`competition_id`),
    KEY `idx_ua_dc`          (`distance_combination_id`),
    CONSTRAINT `fk_ua_person`
        FOREIGN KEY (`person_license`) REFERENCES `persons` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
