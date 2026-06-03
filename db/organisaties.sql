-- InlineComp – organisaties (wedstrijdorganiserende verenigingen)
--
-- Eén rij per organisatie die wedstrijden organiseert. De `naam` is uniek;
-- alternatieve schrijfwijzen/aliassen staan in `organisatie_aliassen`,
-- sponsors in `organisatie_sponsors`.

CREATE TABLE IF NOT EXISTS `organisaties` (
    `id`               VARCHAR(36)  NOT NULL,
    `naam`             VARCHAR(255) NOT NULL,
    `logo_path`        VARCHAR(500) DEFAULT NULL,
    `created_at`       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `email`            VARCHAR(255) DEFAULT NULL,
    `sportity_kanaal`  VARCHAR(50)  DEFAULT NULL,   -- bv. 'ISKREGIO' — verschilt per KNSB-regio
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_naam` (`naam`)
    -- Email is bewust NIET uniek: één Vantage-beheerder kan voor meerdere
    -- KNSB-verenigingen tegelijk werken, dus dezelfde organizer-email
    -- verschijnt legitiem bij verschillende orgs.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
