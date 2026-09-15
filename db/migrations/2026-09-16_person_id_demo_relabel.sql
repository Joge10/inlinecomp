-- ============================================================
--  Migratie 2026-09-16 — person_external_ids: demo-rijders herlabelen
--
--  Fase 1c (15-09) deelde person_external_ids.systeem in op prefix:
--    'x-%' → ic-extern, 'p-%' → ic-pending, ELSE → knsb.
--  Demo-rijders hebben een 'demo-…' licentie en vielen dus in de ELSE-tak →
--  ze stonden ten onrechte als 'knsb'. Dat zou de 3d-ii import-matching laten
--  denken dat een demo-rijder een echt KNSB-account is (en vervuilt de
--  knsb-telling). We geven ze een eigen label 'ic-demo'.
--
--  Veilig: PK is (systeem, extern_id); extern_id blijft 'demo-…' (uniek), dus
--  het wisselen van systeem geeft geen PK-botsing. Herhaalbaar (idempotent).
--  Fase 1c is bijgewerkt zodat een re-run demo meteen als 'ic-demo' labelt.
-- ============================================================

UPDATE `person_external_ids`
SET `systeem` = 'ic-demo'
WHERE `systeem` = 'knsb' AND `extern_id` LIKE 'demo-%';

-- Controle (verwacht: knsb bevat alleen nog numerieke licenties):
--   SELECT systeem, COUNT(*) FROM person_external_ids GROUP BY systeem;
--   SELECT COUNT(*) FROM person_external_ids WHERE systeem='knsb' AND extern_id LIKE 'demo-%';  -- 0
