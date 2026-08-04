-- InlineComp – entries (inschrijvingen per wedstrijd per DC)
--
-- `status` waarden (labels gelijk aan app.js STATUS_LABELS):
--   0  Niet bevestigd
--   1  Bevestigd
--   2  Afgemeld
--   3  Afgemeld bij org.
--   4  Niet getekend
--   5  Bevestigd bij org.

CREATE TABLE IF NOT EXISTS `entries` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `distance_combination_id` VARCHAR(36)  NOT NULL,
    `person_license`          VARCHAR(30)  NOT NULL,
    `knsb_entry_id`           VARCHAR(36)  DEFAULT NULL,
    `status`                  TINYINT UNSIGNED DEFAULT 1,
    -- reserve: NULL = gewone rijder, 1..N = reserve-volgnummer.
    -- reserve_handmatig_ingezet=1: operator heeft via reserve-beheer ingezet;
    -- vanaf dat moment beschermt deze vlag entries.reserve tegen KNSB-resync.
    `reserve`                 TINYINT UNSIGNED NULL DEFAULT NULL,
    `reserve_handmatig_ingezet` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_entry` (`distance_combination_id`, `person_license`),
    KEY `idx_entry_person` (`person_license`),
    CONSTRAINT `fk_entry_dc`
        FOREIGN KEY (`distance_combination_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_entry_person`
        FOREIGN KEY (`person_license`) REFERENCES `persons` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
