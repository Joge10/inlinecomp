-- InlineComp – klassement_presets (herbruikbare puntentabel-regels)
--
-- Per organisatie (of globaal, als `org_id` NULL is) een voorgedefinieerde
-- set regels die als startpunt dient bij het aanmaken van een nieuwe serie.
-- Midden in een seizoen regels aanpassen zonder preset-overschrijving
-- voorkomt dat je later niet meer weet welke regels er golden.

CREATE TABLE IF NOT EXISTS `klassement_presets` (
    `id`            VARCHAR(36)  NOT NULL,
    `org_id`        VARCHAR(36)  DEFAULT NULL,            -- NULL = globale preset
    `naam`          VARCHAR(100) NOT NULL,                -- bv. "KNSB tabel 2026"
    `regels`        LONGTEXT     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                                 CHECK (json_valid(`regels`)),
    `aangemaakt_op` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_preset_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
