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
    -- Full-final per-cat instellingen (NULL = val terug op afstand-config defaults):
    --   finale_a_grootte : max rijders in de A-finale voor deze categorie (cap = aantal rijders in cat)
    --   finale_b_heats   : aantal B-finale heats; overige rijders worden gelijk verdeeld,
    --                      de "rest" schuift naar B1 of B-laatste afhankelijk van laatste_b_grootste
    --   laatste_b_grootste : 1 = laatste B-finale krijgt de rest; 0 = B1 krijgt de rest
    `finale_a_grootte`    TINYINT UNSIGNED DEFAULT NULL,
    `finale_b_heats`      TINYINT UNSIGNED DEFAULT NULL,
    `laatste_b_grootste`  TINYINT(1)        DEFAULT NULL,
    -- Full-final variant: series dienen alleen als startvolgorde-bepaling voor
    -- de A-finale. Het eindresultaat wordt dan uitsluitend door de A-finale
    -- bepaald (serie-punten worden genegeerd in de uitslag).
    -- Alleen geldig bij 1 serie-heat.
    `series_alleen_startvolgorde` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tcc` (`tijdschema_id`, `dc_id`, `distance_id`),
    CONSTRAINT `fk_tcc_schema`
        FOREIGN KEY (`tijdschema_id`) REFERENCES `competition_tijdschema` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tcc_dc`
        FOREIGN KEY (`dc_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────
-- Migratie voor bestaande installaties (één keer handmatig draaien):
-- ─────────────────────────────────────────────────────────────────────
-- ALTER TABLE `tijdschema_cat_config`
--     ADD COLUMN `finale_a_grootte`   TINYINT UNSIGNED DEFAULT NULL AFTER `finale_heats`,
--     ADD COLUMN `finale_b_heats`     TINYINT UNSIGNED DEFAULT NULL AFTER `finale_a_grootte`,
--     ADD COLUMN `laatste_b_grootste` TINYINT(1)        DEFAULT NULL AFTER `finale_b_heats`,
--     ADD COLUMN `series_alleen_startvolgorde` TINYINT(1) NOT NULL DEFAULT 0 AFTER `laatste_b_grootste`;
