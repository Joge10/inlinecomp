-- InlineComp – banen (sport-locaties / accommodaties per organisatie)
--
-- Eén rij per (organisatie × fysieke baan). Een organisatie houdt zijn eigen
-- lijst van banen bij, met per baan een gastheer-vereniging en logo. Dezelfde
-- fysieke baan kan onder meerdere organisaties als aparte rij voorkomen —
-- elk met eigen vereniging-koppeling en logo.
--
-- Auto-koppeling bij import: wanneer een wedstrijd via vergelijk.php aan een
-- organisatie wordt gekoppeld én de KNSB-feed levert een venue_name, kijken
-- we of er voor díé organisatie al een baan met die naam (of alias) bestaat.
-- Zo ja: koppelen. Zo nee: nieuwe baan aanmaken met de basis-info uit de
-- feed (naam + stad), zodat de beheerder later alleen het logo + de
-- vereniging-naam hoeft aan te vullen.

CREATE TABLE IF NOT EXISTS `banen` (
    `id`                VARCHAR(36)  NOT NULL,
    `organisatie_id`    VARCHAR(36)  NOT NULL,
    `naam`              VARCHAR(255) NOT NULL,
    `stad`              VARCHAR(100) DEFAULT NULL,
    `vereniging_naam`   VARCHAR(255) DEFAULT NULL,   -- bv. "DOST 1925"
    `logo_path`         VARCHAR(500) DEFAULT NULL,
    `logo_updated_at`   TIMESTAMP    NULL DEFAULT NULL,
    `created_at`        TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_org_naam` (`organisatie_id`, `naam`),
    KEY `idx_org` (`organisatie_id`),
    CONSTRAINT `fk_baan_org`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
