-- InlineComp – uitslag_klassement (archief: eindklassement per deelnemer per DC)
-- Bewust GEEN CASCADE op competition_id: klassement blijft bewaard na verwijderen wedstrijd.

CREATE TABLE IF NOT EXISTS `uitslag_klassement` (
    `id`                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `competition_id`          VARCHAR(36)     NOT NULL,
    `competition_naam`        VARCHAR(255)    NOT NULL,
    `competition_datum`       DATE            DEFAULT NULL,
    `distance_combination_id` VARCHAR(36)     NOT NULL,
    `dc_naam`                 VARCHAR(255)    NOT NULL,
    -- NOT NULL + DEFAULT '' zodat UNIQUE key betrouwbaar matcht (MySQL
    -- behandelt NULL != NULL in UNIQUE constraints, wat bij NULL-waarden
    -- tot duplicaat-rijen leidt bij ON DUPLICATE KEY UPDATE).
    `split_group`             VARCHAR(50)     NOT NULL DEFAULT '',
    `person_license`          VARCHAR(30)     NOT NULL,
    `categorie`               VARCHAR(20)     DEFAULT NULL,
    `rang`                    SMALLINT UNSIGNED DEFAULT NULL,
    `punten_totaal`           DECIMAL(10,3)   DEFAULT NULL,
    `punten_detail`           JSON            DEFAULT NULL,
    `vastgelegd_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uk_kern` (`competition_id`, `distance_combination_id`, `split_group`, `person_license`),
    KEY `idx_uk_person`      (`person_license`),
    KEY `idx_uk_competition` (`competition_id`),
    CONSTRAINT `fk_uk_person`
        FOREIGN KEY (`person_license`) REFERENCES `persons` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
