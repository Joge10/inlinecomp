-- InlineComp – afhankelijke lotingen
--
-- Legt vast dat de loting van een DOEL-DC automatisch gegenereerd wordt op
-- basis van de uitslag van een BRON-DC, zodra die bron-uitslag wordt
-- bevestigd (api/uitslag_vastleggen.php → trigger in js/uitslag.js).
--
-- Voorbeeld (afstandskampioenschap, 3 losse DC's):
--   DC1 (500m)      → loting op startnummer (geen rij hier nodig)
--   DC2 (punten)    → afhankelijke_loting: doel=DC2, bron=DC1
--   DC3 (afval)     → afhankelijke_loting: doel=DC3, bron=DC2
-- Bevestig je DC1's uitslag → DC2 wordt geloot; bevestig DC2 → DC3.
--
-- MVP-scope: methode = 'afstand_uitslag' (seed doel-DC op eindstand bron-DC,
-- hergebruikt de bestaande seeding-methode in api/startlijst_genereer.php).
-- Kolom `methode` is bewust vrij zodat later bv. 'tussenklassement' kan.
--
-- LET OP: handmatig draaien in phpMyAdmin — de app-DB-user heeft geen
-- DDL-rechten. distance_id-velden zijn NOT NULL DEFAULT '' (net als
-- uitslag_afstand) zodat de UNIQUE-key betrouwbaar matcht (MySQL behandelt
-- NULL != NULL in UNIQUE-constraints).

CREATE TABLE IF NOT EXISTS `afhankelijke_loting` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competition_id`   VARCHAR(36)  NOT NULL,
    `doel_dc_id`       VARCHAR(36)  NOT NULL,                 -- de te loten DC
    `doel_distance_id` VARCHAR(36)  NOT NULL DEFAULT '',      -- '' = hele DC
    `methode`          VARCHAR(30)  NOT NULL DEFAULT 'afstand_uitslag',
    `bron_dc_id`       VARCHAR(36)  NOT NULL,                 -- DC met de bron-uitslag
    `bron_distance_id` VARCHAR(36)  NOT NULL DEFAULT '',      -- '' = hele bron-DC
    `max_per_heat`     TINYINT UNSIGNED DEFAULT NULL,         -- NULL = default (6)
    `aangemaakt_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_doel`  (`competition_id`, `doel_dc_id`, `doel_distance_id`),
    KEY       `idx_bron` (`competition_id`, `bron_dc_id`),
    CONSTRAINT `fk_al_doel_dc`
        FOREIGN KEY (`doel_dc_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_al_bron_dc`
        FOREIGN KEY (`bron_dc_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
