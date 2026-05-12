-- 2026-05-09 – Klassement-config per (wedstrijd × DC)
--
-- Eerste use-case: tie-breaker-keuze bij full-final wedstrijden. Sommige
-- clubs hanteren de oude regel "winnaar van afstand X wint bij gelijke
-- punten" i.p.v. onze multi-stap-keten. Operator stelt dit in op /Klassement.
--
-- Deze tabel houdt de keuze persistent op DB-niveau zodat:
--   - Een tweede operator op een andere PC dezelfde stand ziet
--   - Bij vastleggen het archief-klassement matcht met de viewing
--   - localStorage-discrepancies tussen browsers/devices wegvallen
--
-- tiebreaker_dist NULL = 'standaard' (multi-stap-keten — onze default).
-- Een geldige distance_id = "alleen die afstand telt na totaal-punten".

CREATE TABLE IF NOT EXISTS `klassement_config` (
    `competition_id`  VARCHAR(36) NOT NULL,
    `dc_id`           VARCHAR(36) NOT NULL,
    `tiebreaker_dist` VARCHAR(36) DEFAULT NULL,
    -- Klassement-publicatie: NULL = vastgelegd maar niet gepubliceerd
    -- (zichtbaar in admin, niet in coach/public). Filled = gepubliceerd op
    -- die timestamp. Zie 2026-05-12_klassement_publicatie.sql.
    `gepubliceerd_at` TIMESTAMP   NULL DEFAULT NULL,
    `updated_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`competition_id`, `dc_id`),
    CONSTRAINT `fk_kc_comp`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
