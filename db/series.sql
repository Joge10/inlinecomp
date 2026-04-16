-- InlineComp – series (competitiereeksen)

CREATE TABLE IF NOT EXISTS `series` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `naam`         VARCHAR(255) NOT NULL,
    `seizoen`      VARCHAR(10)  DEFAULT NULL,
    `discipline`   VARCHAR(100) DEFAULT NULL,
    `omschrijving` TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `series_wedstrijden` (
    `series_id`       INT UNSIGNED NOT NULL,
    `competition_id`  VARCHAR(36)  NOT NULL,
    `volgorde`        TINYINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`series_id`, `competition_id`),
    KEY `fk_sw_competition` (`competition_id`),
    CONSTRAINT `fk_sw_series`
        FOREIGN KEY (`series_id`) REFERENCES `series` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sw_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
