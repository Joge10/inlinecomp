-- InlineComp – jury_leden
--
-- Officials per wedstrijd voor het Wedstrijdprotokol-rapport, opgedeeld
-- in drie categorieën:
--   - 'OC'          → Organisatie Comité (alleen naam, geen sub-functie)
--   - 'jury'        → Jury-leden met vaste rollen (hoofdscheidsrechter,
--                     scheidsrechter, tijdwaarneming, video, uitslag-
--                     verwerking, speaker, algemeen jury lid). 'functie'
--                     bevat de gekozen rol.
--   - 'vrijwilliger'→ Vrijwilligers (alleen naam)
--
-- Volgorde-veld bepaalt weergave-volgorde binnen de categorie; bij OC en
-- vrijwilliger is 'functie' meestal leeg/NULL.

CREATE TABLE IF NOT EXISTS `jury_leden` (
    `id`             VARCHAR(36)      NOT NULL,
    `competition_id` VARCHAR(36)      NOT NULL,
    `categorie`      VARCHAR(20)      NOT NULL DEFAULT 'jury',
    `functie`        VARCHAR(100)     DEFAULT NULL,
    `naam`           VARCHAR(150)     NOT NULL,
    `volgorde`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_comp_cat_volgorde` (`competition_id`, `categorie`, `volgorde`),
    CONSTRAINT `jury_leden_ibfk_comp`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
