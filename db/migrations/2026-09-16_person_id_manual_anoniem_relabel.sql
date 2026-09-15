-- ============================================================
--  Migratie 2026-09-16 — person_external_ids: manual_ + …_Anoniem herlabelen
--
--  Vervolg op de demo-relabel. Fase 1c labelde álles zonder x-/p-/demo- prefix
--  als 'knsb'. Daardoor stonden ook twee InlineComp-interne soorten ten onrechte
--  als 'knsb' (= echt KNSB-relatienummer):
--
--    manual_<ts>_<rand>   → handmatig toegevoegde rijder ("deelnemer toevoegen"
--                           in de importmodule, voor iemand die nooit via KNSB-
--                           feed of CSV is binnengekomen). GEEN KNSB-nummer.
--    <startnr>_<cat>_Anoniem → KNSB-feed-rijder die bij de KNSB anoniem wil zijn:
--                           de feed geeft dan 'anoniem' en GEEN licentie, dus
--                           InlineComp maakt een synthetische sleutel. Dit is
--                           GEEN bruikbaar KNSB-nummer (verschillende anonieme
--                           rijders kunnen zelfde startnr+cat hebben) → mag nooit
--                           als 'knsb' matchen in fase 3d.
--
--  PK-veilig (systeem, extern_id): extern_id verandert niet. Idempotent.
--  Fase 1c is bijgewerkt zodat een re-run deze twee soorten meteen goed labelt.
-- ============================================================

UPDATE `person_external_ids` SET `systeem` = 'ic-manual'
  WHERE `systeem` = 'knsb' AND `extern_id` LIKE 'manual\_%';

UPDATE `person_external_ids` SET `systeem` = 'ic-anoniem'
  WHERE `systeem` = 'knsb' AND `extern_id` LIKE '%\_Anoniem';

-- Controle na afloop (verwacht: knsb bevat alleen nog numerieke licenties):
--   SELECT systeem, COUNT(*) FROM person_external_ids GROUP BY systeem ORDER BY n DESC;
--   SELECT COUNT(*) FROM person_external_ids
--     WHERE systeem='knsb' AND extern_id NOT REGEXP '^[0-9]+$';   -- verwacht 0
