-- ============================================================
--  Migratie 2026-09-16 — person_id collation uitlijnen
--
--  Fase 3d-iii zet alle JOINs om van person_license/license_key naar person_id.
--  Die worden dan CHAR(36) = CHAR(36) joins. 13 van de 14 person_id-kolommen
--  (incl. persons.person_id) zijn utf8mb4_general_ci; alleen
--  push_sub_licenses.person_id was utf8mb4_unicode_ci (erfde de tabel-collation).
--  Een kale join tussen die twee geeft #1267 "Illegal mix of collations".
--
--  Oplossing: lijn de ene uitzondering uit naar general_ci → alle person_id-
--  kolommen gelijk → joins nergens een COLLATE nodig (schoner + index-vriendelijk).
--
--  Veilig: person_id bevat alleen UUID-tekens (ASCII hex + '-'), dus de collation-
--  wissel raakt geen enkele waarde/vergelijking. Index idx_psl_person_id wordt
--  herbouwd. Herhaalbaar (MODIFY is idempotent qua eindresultaat).
-- ============================================================

ALTER TABLE `push_sub_licenses`
  MODIFY `person_id` CHAR(36) COLLATE utf8mb4_general_ci DEFAULT NULL;

-- Controle (verwacht: nu 1 regel, alle person_id-kolommen general_ci):
--   SELECT COLLATION_NAME, COUNT(*) FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'person_id'
--   GROUP BY COLLATION_NAME;
