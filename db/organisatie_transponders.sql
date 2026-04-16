-- InlineComp – organisatie_transponders (transponder-inventaris per organisatie)

CREATE TABLE IF NOT EXISTS `organisatie_transponders` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organisatie_id`   VARCHAR(36)  NOT NULL,
    `intern_nummer`    VARCHAR(20)  NOT NULL,
    `transponder_code` VARCHAR(50)  NOT NULL,
    `eigendom`         VARCHAR(100) DEFAULT NULL,
    `toegewezen_snr`   SMALLINT UNSIGNED DEFAULT NULL,
    `toegewezen_naam`  VARCHAR(255) DEFAULT NULL,
    `categorie`        VARCHAR(20)  DEFAULT NULL,
    `betaald`          TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ot_nr` (`organisatie_id`, `intern_nummer`),
    CONSTRAINT `fk_ot_org`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
