-- InlineComp – entries (inschrijvingen)

CREATE TABLE IF NOT EXISTS `entries` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `distance_combination_id` VARCHAR(36)  NOT NULL,
    `person_license`          VARCHAR(30)  NOT NULL,
    `knsb_entry_id`           VARCHAR(36)  DEFAULT NULL,
    `status`                  TINYINT UNSIGNED DEFAULT 1,   -- 1=bevestigd, 5=bev.bij org
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_entry` (`distance_combination_id`, `person_license`),
    KEY `idx_entry_person` (`person_license`),
    CONSTRAINT `fk_entry_dc`
        FOREIGN KEY (`distance_combination_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_entry_person`
        FOREIGN KEY (`person_license`) REFERENCES `persons` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
