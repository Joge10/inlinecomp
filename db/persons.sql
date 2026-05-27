-- InlineComp – persons (individuele atleten)
-- PK = license_key (KNSB licentienummer)

CREATE TABLE IF NOT EXISTS `persons` (
    `license_key`  VARCHAR(30)   NOT NULL,
    `full_name`    VARCHAR(255)  NOT NULL,
    `short_name`   VARCHAR(100)  DEFAULT NULL,
    `birth_year`   SMALLINT UNSIGNED DEFAULT NULL,
    `gender`       TINYINT UNSIGNED DEFAULT NULL,       -- 0=man 1=vrouw
    `category`     VARCHAR(20)   DEFAULT NULL,          -- DKA, HKA, DJB …
    `nationality`  VARCHAR(3)    DEFAULT 'NED',
    `start_number` SMALLINT UNSIGNED DEFAULT NULL,
    `club_code`    INT UNSIGNED  DEFAULT NULL,
    `club_short`   VARCHAR(20)   DEFAULT NULL,
    `club_full`    VARCHAR(255)  DEFAULT NULL,
    `sponsor`      VARCHAR(255)  DEFAULT NULL,         -- persoonlijke sponsor uit KNSB API (optioneel)
    `city`         VARCHAR(100)  DEFAULT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- AVG: rijders kunnen een verwijderverzoek indienen. Om de wedstrijdgeschiedenis
    -- (uitslagen, klassementen) intact te houden anonimiseren we de persoonsgegevens
    -- in plaats van het record te verwijderen. De `license_key` blijft als
    -- pseudonieme FK; naam/geboortejaar/woonplaats worden op 'Verwijderd'/NULL gezet.
    -- Bij een niet-null `anonymized_at` toont de UI "Verwijderd" i.p.v. de naam.
    `anonymized_at` DATETIME     DEFAULT NULL,
    -- Pending-rijders: aangemaakt vanuit historie-import (PDF) als de echte
    -- KNSB-licentie nog niet bekend is. license_key heeft dan het formaat
    -- 'p-{12-char-random}'. Zodra de rijder gekoppeld wordt aan een echte
    -- KNSB-account: alle uitslag_afstand-rijen worden ge-UPDATE naar de
    -- echte license_key en de pending-rij wordt verwijderd.
    -- Mogelijke waarden: NULL (echte persoon, default) | 'historie' (PDF-import)
    `pending_source` VARCHAR(20)  DEFAULT NULL,
    PRIMARY KEY (`license_key`),
    KEY `idx_persons_anon`    (`anonymized_at`),
    KEY `idx_persons_pending` (`pending_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migratie voor bestaande installaties (fout negeren als kolom al bestaat):
-- ALTER TABLE persons
--     ADD COLUMN anonymized_at DATETIME DEFAULT NULL AFTER updated_at,
--     ADD KEY idx_persons_anon (anonymized_at);
--
-- Pending-source migratie (later toegevoegd):
-- ALTER TABLE persons
--     ADD COLUMN pending_source VARCHAR(20) DEFAULT NULL AFTER anonymized_at,
--     ADD KEY idx_persons_pending (pending_source);
