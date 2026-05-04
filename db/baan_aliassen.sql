-- InlineComp – baan_aliassen (alternatieve schrijfwijzen voor banen)
--
-- KNSB-feeds bevatten soms inconsistente venue-namen. Per baan-rij kun je
-- extra naam-varianten registreren zodat de import-koppeling op alle
-- varianten matcht.
--
-- Aliassen zijn uniek BINNEN één organisatie (= per banen.organisatie_id):
-- twee organisaties kunnen elk hun eigen baan met dezelfde alias hebben.

CREATE TABLE IF NOT EXISTS `baan_aliassen` (
    `id`         VARCHAR(36)  NOT NULL,
    `baan_id`    VARCHAR(36)  NOT NULL,
    `naam`       VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_baan` (`baan_id`),
    CONSTRAINT `fk_alias_baan`
        FOREIGN KEY (`baan_id`) REFERENCES `banen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
