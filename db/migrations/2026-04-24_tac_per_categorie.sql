-- Migration: tijdschema_afstand_config per-categorie maken
--
-- Ranking-instellingen moeten per categorie (DC) kunnen verschillen. Tot nu
-- toe had tijdschema_afstand_config een UNIQUE KEY op (tijdschema_id,
-- afstand_naam) — dus één rij per afstand voor ALLE cats samen. Gevolg:
-- wijziging van ranking bij 'Meisjes Kadetten' overschreef 'Jongens Kadetten'.
--
-- Fix: dc_id-kolom toevoegen (NULL toegestaan = globale default), en de
-- unique key uitbreiden zodat per DC een eigen rij kan bestaan. De bestaande
-- rijen blijven staan met dc_id = NULL; de lees-logica gebruikt ze als
-- fallback als er nog geen DC-specifieke rij is.

ALTER TABLE `tijdschema_afstand_config`
    ADD COLUMN `dc_id` VARCHAR(36) DEFAULT NULL AFTER `tijdschema_id`,
    DROP INDEX `uq_tac`,
    ADD UNIQUE KEY `uq_tac` (`tijdschema_id`, `dc_id`, `afstand_naam`),
    ADD KEY `idx_tac_lookup` (`tijdschema_id`, `afstand_naam`, `dc_id`);
