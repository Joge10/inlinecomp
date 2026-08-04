-- InlineComp – competitions (wedstrijden)

CREATE TABLE IF NOT EXISTS `competitions` (
    `id`                 VARCHAR(36)  NOT NULL,          -- KNSB competition UUID
    `name`               VARCHAR(255) NOT NULL,
    `starts`             DATETIME     DEFAULT NULL,
    `ends`               DATETIME     DEFAULT NULL,
    `location`           TEXT         DEFAULT NULL,
    `venue_name`         VARCHAR(255) DEFAULT NULL,
    `venue_city`         VARCHAR(100) DEFAULT NULL,
    `discipline`         VARCHAR(100) DEFAULT NULL,
    `imported_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `organisatie_id`     VARCHAR(36)  DEFAULT NULL,
    `entries_version`    INT          NOT NULL DEFAULT 0,
    `tijdschema_version` INT          NOT NULL DEFAULT 0,
    -- Zichtbaarheid voor /coach + /public. Default 0 = nieuwe wedstrijden
    -- zijn onzichtbaar tot operator expliciet publiceert vanuit Beheer.
    -- Zie 2026-05-12_competitions_public_zichtbaar.sql voor de migratie
    -- die bestaande wedstrijden op 1 zet.
    `public_zichtbaar`   TINYINT(1)   NOT NULL DEFAULT 0,
    -- "Aankondigen" werkt alleen als public_zichtbaar=0. 1 = toon als
    -- disabled "(binnenkort)" in /coach + /public dropdowns; 0 = toon
    -- helemaal niet (stille voorbereiding). Bij public_zichtbaar=1
    -- niet relevant. Default 1 voor backwards-compat met huidig gedrag.
    `public_aankondigen` TINYINT(1)   NOT NULL DEFAULT 1,
    -- Bron van de wedstrijd: NULL/'knsb' = uit KNSB-feed (vergelijk.php + import.php
    -- met KNSB-API-sync), 'handmatig' = via wedstrijd_handmatig.php aangemaakt
    -- (geen KNSB-koppeling; vergelijk.php + import.php skippen dan de KNSB-stappen).
    `bron`               VARCHAR(20)  DEFAULT NULL,
    -- Tijdschema-wizard Deel 1 (DC's samenstellen) minstens één keer voltooid.
    -- 0 = nog niet gedraaid → wizard toont alle categorieën los in de "bak".
    -- 1 = gedraaid → de DC's + merge_group + dc_splits ZIJN de groepen; de
    -- wizard reconstrueert daaruit (bak leeg). Nodig omdat een als-solo-groepen
    -- ingedeelde per-categorie-feed géén merges/splits oplevert en dan niet van
    -- "vers" te onderscheiden is. Zie 2026-08-03_competitions_wizard_dc.sql.
    `wizard_dc_gedaan`   TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migratie voor bestaande installaties (fout negeren als kolom al bestaat):
-- ALTER TABLE competitions
--     ADD COLUMN bron VARCHAR(20) DEFAULT NULL AFTER public_aankondigen;
-- ALTER TABLE competitions
--     ADD COLUMN wizard_dc_gedaan TINYINT(1) NOT NULL DEFAULT 0 AFTER bron;
