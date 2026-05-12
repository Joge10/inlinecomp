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
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
