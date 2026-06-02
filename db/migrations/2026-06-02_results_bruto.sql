-- InlineComp — bruto-tijd kolommen op results
--
-- Doel: bewaar de oorspronkelijk-gemeten transponder/MyLaps-tijd, ook nadat
-- jury 'm heeft aangepast (fotofinish-swap, RR-sanctie met handmatige tijd).
--
-- Semantiek:
--   tijd_ms       = officiële tijd (wat in uitslag/seeding telt)
--   bruto_tijd_ms = origineel gemeten transponder-tijd
--   bruto_rondes  = origineel gemeten rondes
--
-- bruto_* wordt ÉÉN keer gezet (bij eerste save voor deze heat_entry). Daarna
-- onaangeraakt — niet door wisseling, niet door handmatige aanpassing.
--
-- Display-logica:
--   bruto_tijd_ms IS NULL of = tijd_ms → normaal, geen indicator.
--   bruto_tijd_ms != tijd_ms          → audit-pill (📷 fotofinish bij
--                                       is_photofinish=1, ✋ handmatig anders).

ALTER TABLE `results`
    ADD COLUMN `bruto_tijd_ms` INT UNSIGNED      DEFAULT NULL AFTER `tijd_ms`,
    ADD COLUMN `bruto_rondes`  SMALLINT UNSIGNED DEFAULT NULL AFTER `rondes`;

-- Backfill: voor bestaande rijen nemen we de huidige tijd_ms/rondes als bruto.
-- Historische wisselingen verliezen daarmee hun audit-spoor (de "echte"
-- originele waarde is niet meer te achterhalen) — maar dat is OK, vanaf nu
-- houden we 'm wel correct bij.
UPDATE `results`
   SET `bruto_tijd_ms` = `tijd_ms`,
       `bruto_rondes`  = `rondes`
 WHERE `bruto_tijd_ms` IS NULL;
