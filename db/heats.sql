-- InlineComp – heats (ritten / races)

CREATE TABLE IF NOT EXISTS `heats` (
    `id`                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `competition_id`          VARCHAR(36)   NOT NULL,
    `distance_combination_id` VARCHAR(36)   NOT NULL,
    `distance_id`             VARCHAR(36)   DEFAULT NULL,
    `split_group`             VARCHAR(50)   DEFAULT NULL,
    `ronde`                   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `tijdschema_rit_id`       INT UNSIGNED  DEFAULT NULL,
    `rit_volgorde`            SMALLINT UNSIGNED DEFAULT NULL,
    `heat_naam`               VARCHAR(100)  NOT NULL,
    `heat_nr`                 TINYINT UNSIGNED NOT NULL,
    `methode`                 VARCHAR(20)   DEFAULT NULL,
    `dc_ids`                  TEXT          DEFAULT NULL,    -- JSON array van DC-IDs
    `gegenereerd_op`          DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `geplande_starttijd`      DATETIME      DEFAULT NULL,
    `race_type`               VARCHAR(20)   NOT NULL DEFAULT 'inline',
    PRIMARY KEY (`id`),
    KEY `idx_heat_comp` (`competition_id`),
    KEY `idx_heat_dc`   (`distance_combination_id`),
    CONSTRAINT `fk_heat_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_heat_dc`
        FOREIGN KEY (`distance_combination_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_heat_rit`
        FOREIGN KEY (`tijdschema_rit_id`) REFERENCES `tijdschema_ritten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
