-- InlineComp – klassement_posities (rijen in een klassement)
--
-- Eén rij per rijder per klassement (zowel voor PDF-imports als voor
-- serie-klassementen). Belangrijke kolommen:
--   start_number   — vrij-tekstveld, meestal numeriek, komt zo uit PDF-import.
--   license_key    — voor stabiele rijder-identificatie (startnummer kan per
--                    wedstrijd/seizoen wijzigen).
--   punten_detail  — JSON-object {comp_id: punten, ...} voor serie-klassementen,
--                    NULL voor PDF-imports.
--   punten_totaal  — totaal na toepassing van punten_tabel en eventuele
--                    streepresultaten.

CREATE TABLE IF NOT EXISTS `klassement_posities` (
    `id`             VARCHAR(16)    NOT NULL,
    `klassement_id`  VARCHAR(36)    NOT NULL,
    `positie`        INT            NOT NULL,
    `start_number`   VARCHAR(20)    DEFAULT NULL,
    `license_key`    VARCHAR(30)    DEFAULT NULL,
    `naam`           VARCHAR(255)   NOT NULL,
    `categorie`      VARCHAR(20)    DEFAULT NULL,
    `punten_detail`  LONGTEXT       CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                                    CHECK (json_valid(`punten_detail`)),
    `punten_totaal`  DECIMAL(8,2)   DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_kl_id`      (`klassement_id`),
    KEY `idx_kl_cat`     (`klassement_id`, `categorie`),
    KEY `idx_kp_license` (`license_key`),
    CONSTRAINT `klassement_posities_ibfk_1`
        FOREIGN KEY (`klassement_id`) REFERENCES `klassementen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
