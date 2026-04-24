-- InlineComp – organisatie_transponders (transponder-inventaris per organisatie)

CREATE TABLE IF NOT EXISTS `organisatie_transponders` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organisatie_id`   VARCHAR(36)  NOT NULL,
    `intern_nummer`    VARCHAR(20)  NOT NULL,
    `transponder_code` VARCHAR(50)  NOT NULL,
    `eigendom`         VARCHAR(100) DEFAULT NULL,
    `person_license`   VARCHAR(30)  DEFAULT NULL,
    `toegewezen_snr`   SMALLINT UNSIGNED DEFAULT NULL,
    `toegewezen_naam`  VARCHAR(255) DEFAULT NULL,
    `categorie`        VARCHAR(20)  DEFAULT NULL,
    `betaald`          TINYINT(1)   NOT NULL DEFAULT 0,
    `betaald_op`       DATE         DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ot_nr` (`organisatie_id`, `intern_nummer`),
    KEY `idx_ot_license` (`person_license`),
    CONSTRAINT `fk_ot_org`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migratie voor bestaande installaties (fout negeren als kolom al bestaat):
-- ALTER TABLE organisatie_transponders
--     ADD COLUMN person_license VARCHAR(30) DEFAULT NULL AFTER toegewezen_naam;
-- ALTER TABLE organisatie_transponders
--     ADD KEY idx_ot_license (person_license);
--
-- Bestaande toewijzingen koppelen aan licentienummer op basis van naam+snr:
-- UPDATE organisatie_transponders ot
-- JOIN persons p ON p.full_name = ot.toegewezen_naam
--                AND p.start_number = ot.toegewezen_snr
-- SET ot.person_license = p.license_key
-- WHERE ot.person_license IS NULL
--   AND ot.toegewezen_naam IS NOT NULL;
