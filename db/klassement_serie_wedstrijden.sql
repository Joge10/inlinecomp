-- InlineComp – klassement_serie_wedstrijden (welke wedstrijden in een serie)
--
-- Kolommen:
--   telt_mee   — 0/1, markeert een wedstrijd als "oefenwedstrijd" of "afgelast"
--                zonder hem uit de serie te halen.
--   is_finale  — 0/1, markeert dé finale-wedstrijd van de serie. Maximaal één
--                per serie; zo geen = chronologisch laatste wedstrijd.
--   comp_naam / comp_datum — fallback-velden voor wedstrijden die (nog) niet
--                in `competitions` zitten (bv. toekomstige wedstrijden die
--                al wel in de serie-planning staan maar nog niet geïmporteerd
--                zijn). Als de wedstrijd wél in competitions zit, zijn deze
--                velden optioneel.
--
-- Géén FK naar competitions: zo kunnen we ook KNSB-UUIDs opslaan zonder dat
-- er een shadow-rij in competitions gemaakt hoeft te worden.

CREATE TABLE IF NOT EXISTS `klassement_serie_wedstrijden` (
    `serie_id`       VARCHAR(36)  NOT NULL,
    `competition_id` VARCHAR(36)  NOT NULL,
    `telt_mee`       TINYINT(1)   NOT NULL DEFAULT 1,
    `is_finale`      TINYINT(1)   NOT NULL DEFAULT 0,
    `volgorde`       SMALLINT     NOT NULL DEFAULT 0,
    `comp_naam`      VARCHAR(255) DEFAULT NULL,
    `comp_datum`     DATETIME     DEFAULT NULL,
    PRIMARY KEY (`serie_id`, `competition_id`),
    KEY `idx_ksw_serie` (`serie_id`),
    KEY `idx_ksw_comp`  (`competition_id`),
    CONSTRAINT `fk_ksw_serie`
        FOREIGN KEY (`serie_id`) REFERENCES `klassement_series` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
