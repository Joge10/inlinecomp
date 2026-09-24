-- Migratie 2026-09-24 — persons.birth_year droppen (privacy-opschoning).
--
-- Context: geen enkele ECHTE rijder had een geboortejaar (alleen demo-personen),
-- en de code gebruikte het veld nergens functioneel — de PDF-import- en pending-
-- matching werkt volledig op categorie×jaar-BEREIKEN, niet op de opgeslagen waarde.
-- Alle code-referenties (helpers.php, cluster_check.php, csv_import.php,
-- coach_account.php, _rijderprofiel_data.php, wedstrijd_handmatig.php, jury/index.php,
-- persoon_anonimiseer.php + de bijbehorende JS) zijn verwijderd.
--
-- VOLGORDE (gedeelde test+prod-DB): draai deze migratie PAS NADAT de bijgewerkte
-- code is gedeployed — een oude, nog-live SELECT op birth_year zou anders breken.

ALTER TABLE `persons` DROP COLUMN `birth_year`;
