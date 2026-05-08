-- 2026-05-09 – Sponsors per baan (vereniging-niveau)
--
-- Tot nu toe konden alleen organisaties sponsors hebben. Maar een baan/
-- vereniging heeft meestal eigen lokale sponsors die op posters, in de
-- public-footer en de coach-banner mee moeten lopen tijdens wedstrijden
-- op die locatie. Met deze tabel kun je per baan een eigen sponsor-lijst
-- bijhouden — onafhankelijk van de organisatie die de wedstrijd organiseert.
--
-- Bij de display in /public en /coach worden organisatie-sponsors en
-- baan-sponsors samengevoegd in de footer/banner.

CREATE TABLE IF NOT EXISTS `baan_sponsors` (
    `id`         VARCHAR(36)      NOT NULL,
    `baan_id`    VARCHAR(36)      NOT NULL,
    `naam`       VARCHAR(255)     NOT NULL,
    `logo_path`  VARCHAR(500)     DEFAULT NULL,
    `url`        VARCHAR(500)     DEFAULT NULL,
    `volgorde`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_baan` (`baan_id`),
    CONSTRAINT `fk_bs_baan`
        FOREIGN KEY (`baan_id`) REFERENCES `banen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
