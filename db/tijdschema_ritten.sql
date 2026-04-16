-- InlineComp – tijdschema_ritten (gegenereerd programma)

CREATE TABLE IF NOT EXISTS `tijdschema_ritten` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tijdschema_id`     INT UNSIGNED NOT NULL,
    `blok_id`           INT UNSIGNED DEFAULT NULL,
    `volgorde`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `tijdstip_override` TIME         DEFAULT NULL,
    `opmerking`         VARCHAR(255) DEFAULT NULL,
    `dc_id`             VARCHAR(36)  NOT NULL,
    `distance_id`       VARCHAR(36)  DEFAULT NULL,
    `afstand_naam`      VARCHAR(100) DEFAULT NULL,
    `ronde_type`        ENUM('heats','kwartfinale','halve_finale','runner_up','finale_a','finale_b') NOT NULL,
    `finale_label`      VARCHAR(5)   DEFAULT NULL,
    `heat_nr`           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `rit_naam`          VARCHAR(150) NOT NULL,
    `dc_naam`           VARCHAR(255) NOT NULL,
    `verwacht`          TINYINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_tr_schema`   (`tijdschema_id`),
    KEY `idx_tr_volgorde` (`tijdschema_id`, `volgorde`),
    CONSTRAINT `fk_tr_schema`
        FOREIGN KEY (`tijdschema_id`) REFERENCES `competition_tijdschema` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tr_blok`
        FOREIGN KEY (`blok_id`) REFERENCES `tijdschema_blokken` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tr_dc`
        FOREIGN KEY (`dc_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
