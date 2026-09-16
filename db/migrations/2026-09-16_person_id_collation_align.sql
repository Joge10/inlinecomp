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
--  We zetten de HELE tabel op general_ci (table-wide CONVERT) i.p.v. alleen de
--  person_id-kolom, zodat push_sub_licenses intern eenduidig is. Veilig: de
--  kolommen zijn al utf8mb4 (geen her-codering), alleen de collation wijzigt →
--  geen dataverlies. Het raakt ook person_license (VARCHAR(32)), maar dat breekt
--  de fase-2-backfill-joins niet: die dragen een EXPLICIETE COLLATE (wint van de
--  kolom-collation). Herhaalbaar.
--
--  NB: upload_map_blokkades is ook unicode_ci maar heeft geen person_id → irrelevant.
--  De DB-default (latin1_swedish_ci) volledig uniform maken is een aparte, grotere
--  opruimklus die losstaat van deze migratie.
-- ============================================================

ALTER TABLE `push_sub_licenses`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Controle (verwacht: nu 1 regel, alle person_id-kolommen general_ci):
--   SELECT COLLATION_NAME, COUNT(*) FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'person_id'
--   GROUP BY COLLATION_NAME;
