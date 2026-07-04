-- InlineComp – tijdschema_afstand_config (instellingen per afstand, optioneel per DC)
--
-- De rankings (heats_ranking / kwart_ranking / half_ranking / finale_ranking)
-- kunnen per categorie (dc_id) afwijken. Voor bv. finale_seeding en
-- finale_heat_grootte geldt in de praktijk meestal één waarde per afstand voor
-- alle categoriën — daarvoor gebruik je een rij met dc_id IS NULL als globale
-- default. De lees-query in api/uitslag_afstand.php kiest eerst een
-- dc-specifieke rij; bestaat die niet, fallback op de NULL-rij.

CREATE TABLE IF NOT EXISTS `tijdschema_afstand_config` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tijdschema_id`       INT UNSIGNED NOT NULL,
    `dc_id`               VARCHAR(36)  DEFAULT NULL,   -- NULL = globaal voor deze afstand
    `afstand_naam`        VARCHAR(100) NOT NULL,
    `q_direct`            TINYINT UNSIGNED DEFAULT 1,
    `q_tijd`              TINYINT UNSIGNED DEFAULT 0,
    `finale_heat_grootte` TINYINT UNSIGNED NOT NULL DEFAULT 6,
    `finale_b_grootte`    TINYINT UNSIGNED NOT NULL DEFAULT 6,
    `laatste_b_grootste`  TINYINT(1)   NOT NULL DEFAULT 1,
    -- 'slang'          : klassiek slangenpatroon, snelste paar in H1
    -- 'tijdkoppeling'  : langzaamsten in H1, snelsten in laatste heat (200m DTT)
    -- 'reverse_slang'  : snake-pairs, maar heat-nummering omgekeerd zodat
    --                    snelsten in laatste heat rijden (100m sprint 2-lane,
    --                    Art. 114.10-114.13 WorldSkate Rulebook 2026)
    `finale_seeding`      ENUM('slang','tijdkoppeling','reverse_slang') NOT NULL DEFAULT 'slang',
    `race_type`           ENUM('sprint','long_distance') NOT NULL DEFAULT 'sprint',
    `heats_ranking`       ENUM('time','position_time') NOT NULL DEFAULT 'time',
    `kwart_ranking`       ENUM('time','position_time') NOT NULL DEFAULT 'time',
    `half_ranking`        ENUM('time','position_time') NOT NULL DEFAULT 'time',
    `finale_ranking`      ENUM('time','position_time') NOT NULL DEFAULT 'time',
    `heeft_runner_up`     TINYINT(1)   NOT NULL DEFAULT 0,
    -- Kleine finale (per-afstand, alle categorieën). Alleen zinvol in
    -- internationaal-nieuw systeem: verliezers uit de laatste ronde vóór de
    -- A-finale rijden een aparte race om de plek na de A-finale (bv 3-4).
    -- Ronde-type in tijdschema_ritten blijft 'finale_b' — betekenis is
    -- systeem-afhankelijk (full-final: klassieke B; internationaal: kleine).
    `heeft_kleine_finale` TINYINT(1)   NOT NULL DEFAULT 0,
    `runner_up_max`       TINYINT UNSIGNED NOT NULL DEFAULT 6,
    `runner_up_min`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tac` (`tijdschema_id`, `dc_id`, `afstand_naam`),
    KEY `idx_tac_lookup` (`tijdschema_id`, `afstand_naam`, `dc_id`),
    CONSTRAINT `fk_tac_schema`
        FOREIGN KEY (`tijdschema_id`) REFERENCES `competition_tijdschema` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
