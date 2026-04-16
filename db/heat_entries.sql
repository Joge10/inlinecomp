-- InlineComp – heat_entries (deelnemers per heat / startlijst)

CREATE TABLE IF NOT EXISTS `heat_entries` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `heat_id`         INT UNSIGNED NOT NULL,
    `person_license`  VARCHAR(30)  NOT NULL,
    `categorie`       VARCHAR(20)  DEFAULT NULL,
    `startpositie`    TINYINT UNSIGNED NOT NULL,
    `startnummer`     SMALLINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_he_heat_positie` (`heat_id`, `startpositie`),
    UNIQUE KEY `uq_he_heat_person`  (`heat_id`, `person_license`),
    KEY `idx_he_person` (`person_license`),
    CONSTRAINT `fk_he_heat`
        FOREIGN KEY (`heat_id`) REFERENCES `heats` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_he_person`
        FOREIGN KEY (`person_license`) REFERENCES `persons` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
