-- InlineComp – tijdschema_cat_config (ronde-instellingen per categorie)

CREATE TABLE IF NOT EXISTS `tijdschema_cat_config` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tijdschema_id`       INT UNSIGNED NOT NULL,
    `dc_id`               VARCHAR(36)  NOT NULL,
    `distance_id`         VARCHAR(36)  NOT NULL,
    `heeft_heats`         TINYINT(1)   NOT NULL DEFAULT 1,
    `heats_aantal`        TINYINT UNSIGNED DEFAULT NULL,
    `heats_q`             SMALLINT UNSIGNED DEFAULT NULL,
    `heats_q_heat`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `heeft_kwartfinale`   TINYINT(1)   NOT NULL DEFAULT 0,
    `kwart_heats`         TINYINT UNSIGNED DEFAULT NULL,
    `kwart_door`          SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    `kwart_q_heat`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `heeft_halve_finale`  TINYINT(1)   NOT NULL DEFAULT 0,
    `half_heats`          TINYINT UNSIGNED DEFAULT NULL,
    `half_door`           SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    `half_q_heat`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `heeft_runner_up`     TINYINT(1)   NOT NULL DEFAULT 0,
    `finale_heats`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tcc` (`tijdschema_id`, `dc_id`, `distance_id`),
    CONSTRAINT `fk_tcc_schema`
        FOREIGN KEY (`tijdschema_id`) REFERENCES `competition_tijdschema` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tcc_dc`
        FOREIGN KEY (`dc_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
