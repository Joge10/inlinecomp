-- ============================================================
--  Migratie 2026-09-15 — FASE 1 van de person_id GUID-migratie
--
--  Additief fundament, VOLLEDIG backwards-compatible: elke rijder krijgt een
--  eigen onraadbare GUID (`persons.person_id`) + de externe-id-koppeltabel
--  `person_external_ids`. NIKS in de app leest dit nog → de draaiende code
--  blijft ongewijzigd op `license_key` werken.
--
--  Fundament voor: (a) KNSB-onafhankelijke identiteit (variant A — license_key
--  gaat later helemaal weg), (b) publieke anonimiteit variant B (onraadbaar ID).
--  Zie docs_internal/plan-guid-migratie.md.
--
--  DB: MariaDB 10.11 (gebruikt IF NOT EXISTS + functional DEFAULT (UUID())).
--  Eenmalig draaien; herhaalbaar dankzij IF NOT EXISTS / ON DUPLICATE KEY.
-- ============================================================

-- ── 1a. person_id op persons ────────────────────────────────────────────────
ALTER TABLE `persons`
    ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `license_key`;

-- Verse GUID voor elke bestaande rijder. UUID() wordt PER RIJ geëvalueerd,
-- dus iedereen krijgt een eigen unieke waarde.
UPDATE `persons` SET `person_id` = UUID() WHERE `person_id` IS NULL OR `person_id` = '';

-- Verplicht + DB-default: nieuwe rijders (ook die tijdens fase 1-2 via import
-- binnenkomen, vóór de code person_id schrijft) krijgen automatisch een GUID.
ALTER TABLE `persons` MODIFY `person_id` CHAR(36) NOT NULL DEFAULT (UUID());
ALTER TABLE `persons` ADD UNIQUE KEY IF NOT EXISTS `uq_persons_person_id` (`person_id`);

-- ── 1b. externe-id-koppeltabel ──────────────────────────────────────────────
--  Eén person_id ↔ meerdere externe id's (KNSB-licentie, later skateresults/
--  wskate/ISU). UNIQUE(systeem, extern_id): een extern nummer wijst naar 1 persoon.
CREATE TABLE IF NOT EXISTS `person_external_ids` (
    `person_id` CHAR(36)    NOT NULL,
    `systeem`   VARCHAR(20) NOT NULL,   -- 'knsb' | 'ic-extern' | 'ic-pending' | later: 'skateresults','wskate','isu'
    `extern_id` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`systeem`, `extern_id`),
    KEY `idx_pei_person` (`person_id`),
    CONSTRAINT `fk_pei_person`
        FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 1c. huidige license_key spiegelen naar person_external_ids ──────────────
--  Zo resolveert elk (oud) opgeslagen license_key straks naar person_id — nodig
--  voor de import-matching (KNSB-licentie = extra sleutel) én de client-side
--  volglijst-migratie (public/coach). Prefix bepaalt het systeem:
--    'x-…' = extern (CSV/buitenland), 'p-…' = pending-historie, rest = KNSB.
INSERT INTO `person_external_ids` (`person_id`, `systeem`, `extern_id`)
SELECT `person_id`,
       CASE WHEN `license_key` LIKE 'x-%'    THEN 'ic-extern'
            WHEN `license_key` LIKE 'p-%'    THEN 'ic-pending'
            WHEN `license_key` LIKE 'demo-%' THEN 'ic-demo'
            ELSE 'knsb' END,
       `license_key`
FROM `persons`
ON DUPLICATE KEY UPDATE `person_id` = VALUES(`person_id`);
-- NB: 'demo-%' → 'ic-demo' is later toegevoegd (16-09). De eerste run (15-09)
-- had demo-rijders per abuis als 'knsb' gelabeld; migratie
-- 2026-09-16_person_id_demo_relabel.sql herstelt bestaande data.

-- Controle na afloop (optioneel, verwacht 0):
--   SELECT COUNT(*) FROM persons WHERE person_id IS NULL;
--   SELECT COUNT(*) FROM persons p LEFT JOIN person_external_ids e ON e.person_id = p.person_id WHERE e.person_id IS NULL;
