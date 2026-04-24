-- InlineComp – organisatie_aliassen (alternatieve namen per organisatie)
--
-- Matcht KNSB-inschrijvingsnamen aan een bestaande organisatie wanneer
-- de wedstrijd onder een net andere naam is gepubliceerd (typefouten,
-- oude/nieuwe namen). Eén alias-naam is uniek over alle organisaties.

CREATE TABLE IF NOT EXISTS `organisatie_aliassen` (
    `id`             VARCHAR(36)  NOT NULL,
    `organisatie_id` VARCHAR(36)  NOT NULL,
    `naam`           VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_alias_naam` (`naam`),
    KEY `idx_org` (`organisatie_id`),
    CONSTRAINT `organisatie_aliassen_ibfk_1`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
