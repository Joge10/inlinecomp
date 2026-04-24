-- InlineComp – klassement_series
--
-- Een "serie" is een verzameling wedstrijden waarvan de uitslagen samen
-- één of meer klassementen vormen. Elke `klassement_series`-rij verwijst
-- naar een bestaande `klassementen`-rij (zelfde model als een PDF-import) —
-- zo hoeft de seeding-integratie in startlijst_genereer.php niet te
-- veranderen.
--
-- Regels (JSON):
--   type                    : 'gecombineerd' | 'sprint' | 'lang' | 'custom'
--   afstand_filter          : 'alle' | 'sprint' | 'lang' | 'per_naam'
--   afstand_namen           : [] (indien per_naam)
--   punten_tabel            : [50.1, 47, 45, ..., 1] (index 0 = 1e plek)
--   min_punten_bij_deelname : 1 (default) — rang buiten de tabel valt hierop terug
--   streepresultaten        : 0 (fase 2)
--   min_deelnames           : 0 (fase 2)

CREATE TABLE IF NOT EXISTS `klassement_series` (
    `id`            VARCHAR(36)  NOT NULL,
    `klassement_id` VARCHAR(36)  NOT NULL,              -- fk → klassementen.id
    `naam`          VARCHAR(255) NOT NULL,
    `seizoen`       VARCHAR(20)  DEFAULT NULL,
    `org_id`        VARCHAR(36)  DEFAULT NULL,
    `regels`        JSON         NOT NULL,              -- zie header
    `aangemaakt_op` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `herberekend_op` DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_series_klassement` (`klassement_id`),
    KEY `idx_series_org` (`org_id`),
    CONSTRAINT `fk_series_klassement`
        FOREIGN KEY (`klassement_id`) REFERENCES `klassementen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Welke wedstrijden zitten in de serie.
--   telt_mee   = 0/1, klapper voor "oefenwedstrijd" of "afgelast"
--   is_finale  = 0/1, markeert de finale-wedstrijd van de serie (slechts één
--                per serie moet aanvinkt zijn; als geen is aangevinkt valt het
--                systeem terug op "de chronologisch laatste" wedstrijd)
--   comp_naam / comp_datum: fallback-velden voor wedstrijden die (nog) niet in
--                de `competitions`-tabel zitten (bv. toekomstige wedstrijden
--                die wel al in de serie-planning staan maar nog niet
--                geïmporteerd zijn). Als de wedstrijd wél in competitions zit
--                zijn deze velden optioneel.
-- GEEN FK naar competitions: zo kunnen we ook KNSB-UUIDs opslaan zonder dat er
-- een shadow-rij in competitions gemaakt hoeft te worden.
CREATE TABLE IF NOT EXISTS `klassement_serie_wedstrijden` (
    `serie_id`       VARCHAR(36)  NOT NULL,
    `competition_id` VARCHAR(36)  NOT NULL,
    `telt_mee`       TINYINT(1)   NOT NULL DEFAULT 1,
    `is_finale`      TINYINT(1)   NOT NULL DEFAULT 0,
    `volgorde`       SMALLINT     NOT NULL DEFAULT 0,
    `comp_naam`      VARCHAR(255) DEFAULT NULL,
    `comp_datum`     DATETIME     DEFAULT NULL,
    PRIMARY KEY (`serie_id`, `competition_id`),
    KEY `idx_ksw_serie` (`serie_id`),
    KEY `idx_ksw_comp`  (`competition_id`),
    CONSTRAINT `fk_ksw_serie`
        FOREIGN KEY (`serie_id`)       REFERENCES `klassement_series` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migratie-hulp voor bestaande installaties:
--   1) FK naar competitions verwijderen (mocht die er staan):
--      ALTER TABLE klassement_serie_wedstrijden DROP FOREIGN KEY fk_ksw_comp;
--   2) Nieuwe kolommen:
--      ALTER TABLE klassement_serie_wedstrijden
--          ADD COLUMN comp_naam  VARCHAR(255) DEFAULT NULL,
--          ADD COLUMN comp_datum DATETIME     DEFAULT NULL;

-- Presets per organisatie: een organisatie legt zijn eigen puntentabel +
-- regels hier vast. Bij het aanmaken van een nieuwe serie kan de user een
-- preset pakken als startpunt. Regels kunnen later wijzigen mid-seizoen;
-- opslaan als nieuwe preset voorkomt dat je ze opnieuw moet intypen.
CREATE TABLE IF NOT EXISTS `klassement_presets` (
    `id`            VARCHAR(36)  NOT NULL,
    `org_id`        VARCHAR(36)  DEFAULT NULL,             -- NULL = globaal
    `naam`          VARCHAR(100) NOT NULL,                 -- bv. "KNSB tabel 2026"
    `regels`        JSON         NOT NULL,
    `aangemaakt_op` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_preset_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Voor serie-klassementen willen we weten dat een klassement-posities rij
-- "niet uit een PDF komt". We hergebruiken `klassement_posities.start_number`
-- als string; daarnaast voegen we een licentie-kolom toe zodat rijders
-- stabiel geïdentificeerd kunnen worden (ook als hun startnr per wedstrijd
-- wisselt).
-- Als de kolom al bestaat (bv. na herhaalde migratie) geeft MySQL een
-- foutmelding die je veilig kunt negeren.
ALTER TABLE `klassement_posities`
    ADD COLUMN `license_key` VARCHAR(30) DEFAULT NULL AFTER `start_number`;
ALTER TABLE `klassement_posities`
    ADD KEY `idx_kp_license` (`license_key`);

-- Per rijder: punten-bijdrage per wedstrijd uit de serie, plus totaal en
-- eventuele streepresultaten-info. JSON: { comp_id: punten, ... }
ALTER TABLE `klassement_posities`
    ADD COLUMN `punten_detail` JSON DEFAULT NULL AFTER `categorie`;
ALTER TABLE `klassement_posities`
    ADD COLUMN `punten_totaal` DECIMAL(8,2) DEFAULT NULL AFTER `punten_detail`;

-- Op klassement-niveau: welke wedstrijden zitten er in de serie (voor
-- kolom-kop in de UI + sortering). JSON-array van objects:
--   [{ comp_id, naam, datum, is_finale, volgorde }]
ALTER TABLE `klassementen`
    ADD COLUMN `wedstrijden_meta` JSON DEFAULT NULL AFTER `totaal_rijders`;
