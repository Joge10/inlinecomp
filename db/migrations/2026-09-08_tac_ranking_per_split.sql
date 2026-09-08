-- Migration: tijdschema_afstand_config per-split (gesplitste DC) maken
--
-- Ranking-instellingen (heats/kwart/half/finale_ranking) leven ALLEEN in
-- tijdschema_afstand_config, gekeyed op (tijdschema_id, dc_id, afstand_naam,
-- value_meters). De structuur (heeft_heats/…/Q's) staat wél per split in
-- tijdschema_cat_config (keyed op distance_id), maar de ranking-methode niet.
--
-- Gevolg bij een gesplitste DC (bv. "Meisjes en Jongens Pupil 1" → DP1/HP1):
-- beide splits delen dezelfde dc_id + afstand_naam + value_meters, dus ze
-- lezen/schrijven exact dezelfde ranking-rij. Wijzig je de dropdown bij HP1,
-- dan verandert DP1 mee (en andersom). Dat is een echt conflict: als DP1 series
-- heeft en HP1 niet, is de halve finale bij HP1 de EERSTE ronde (→ op tijd) maar
-- bij DP1 een tussenronde (→ positie + tijd). Eén gedeelde rij kan dat niet.
--
-- Fix (zelfde patroon als de 2026-04-24 dc_id-migratie): target_group-kolom
-- toevoegen (NULL = niet-gesplitst / legacy) en de unique key uitbreiden zodat
-- elke split zijn eigen ranking-rij kan hebben. Bestaande rijen houden
-- target_group = NULL. De leeskant (api/uitslag_afstand.php) filtert voor een
-- split STRIKT op zijn eigen target_group; niet-splits blijven op de NULL-rij.

ALTER TABLE `tijdschema_afstand_config`
    ADD COLUMN `target_group` VARCHAR(50) DEFAULT NULL AFTER `dc_id`,
    DROP INDEX `uq_tac`,
    ADD UNIQUE KEY `uq_tac` (`tijdschema_id`, `dc_id`, `target_group`, `afstand_naam`, `value_meters`);
