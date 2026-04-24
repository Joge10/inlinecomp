-- InlineComp – organisaties (wedstrijdorganiserende verenigingen)
--
-- Eén rij per organisatie die wedstrijden organiseert. De `naam` is uniek;
-- alternatieve schrijfwijzen/aliassen staan in `organisatie_aliassen`,
-- sponsors in `organisatie_sponsors`.

CREATE TABLE IF NOT EXISTS `organisaties` (
    `id`         VARCHAR(36)  NOT NULL,
    `naam`       VARCHAR(255) NOT NULL,
    `logo_path`  VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `email`      VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_naam` (`naam`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
