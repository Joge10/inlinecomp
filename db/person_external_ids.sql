-- ============================================================
--  person_external_ids — externe rijder-id's gekoppeld aan de interne person_id
--
--  Eén interne GUID (persons.person_id) ↔ meerdere externe referenties. De
--  KNSB-licentie is er daar één van (systeem 'knsb'), naast toekomstige bronnen
--  (skateresults.app, wskate.net, ISU). Zo is de identiteit KNSB-onafhankelijk
--  en uitbreidbaar zónder schemawijziging.
--
--  systeem-waarden:
--    'knsb'         — KNSB-licentienummer (numeriek)
--    'ic-extern'    — InlineComp-eigen id voor externe/CSV/buitenlandse rijders ('x-…')
--    'ic-pending'   — InlineComp-eigen id voor historie-import-pending ('p-…')
--    later: 'skateresults' | 'wskate' | 'isu' | …
--
--  UNIQUE(systeem, extern_id): een extern nummer wijst naar precies één persoon.
--  Aangemaakt bij fase 1 van de person_id-migratie (2026-09-15).
--  Zie docs_internal/plan-guid-migratie.md.
-- ============================================================
CREATE TABLE IF NOT EXISTS `person_external_ids` (
    `person_id` CHAR(36)    NOT NULL,
    `systeem`   VARCHAR(20) NOT NULL,
    `extern_id` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`systeem`, `extern_id`),
    KEY `idx_pei_person` (`person_id`),
    CONSTRAINT `fk_pei_person`
        FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
