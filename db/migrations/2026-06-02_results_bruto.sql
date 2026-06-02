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

-- Backfill — ALLEEN voor niet-gewisselde rijen (is_photofinish = 0).
--
-- Voor wisseld-rijen (is_photofinish = 1) is de huidige tijd_ms al de
-- POST-swap waarde. Daar geldt: we weten niet meer wat de pre-swap meting
-- was, dus bruto blijft NULL. Display-logica behandelt NULL als "geen
-- audit-spoor beschikbaar" en toont gewoon geen pill — eerlijker dan de
-- swap-waarde als "gemeten" tonen.
--
-- Wil je voor specifieke heats alsnog correct bruto opbouwen, dan:
--   1. Verwijder de results-rijen van die heat (zie diagnostic onder).
--   2. Re-importeer de MyLaps-CSV en doe de wisselingen opnieuw.
--      De nieuwe logica vangt dan bruto via _bruto_hint_* op vóór de swap.
--
-- Voor heats zonder wisseling klopt bruto = tijd_ms gewoon (geen jury-actie
-- = officiële tijd is de gemeten tijd). Geen actie nodig.
UPDATE `results`
   SET `bruto_tijd_ms` = `tijd_ms`,
       `bruto_rondes`  = `rondes`
 WHERE `bruto_tijd_ms` IS NULL
   AND `is_photofinish` = 0;

-- ─────────────────────────────────────────────────────────────────────────
-- Diagnostic (READ-ONLY): vind heats met wisselingen na migratie.
-- Run handmatig, niet onderdeel van de migratie. Lijst toont per heat het
-- aantal wisselingen — zodat je per heat kunt beslissen of je 'm wil
-- her-importeren (voor correct bruto-spoor) of accepteren (geen audit-pill,
-- maar officiële tijden blijven correct).
-- ─────────────────────────────────────────────────────────────────────────
-- SELECT DISTINCT
--     h.id                            AS heat_id,
--     h.heat_naam,
--     h.ronde,
--     h.heat_nr,
--     dc.name                          AS dc_naam,
--     COUNT(DISTINCT CASE WHEN res.is_photofinish = 1 THEN res.id END)
--                                     AS aantal_wisselingen
-- FROM heats h
-- JOIN heat_entries he             ON he.heat_id        = h.id
-- JOIN results res                 ON res.heat_entry_id = he.id
-- JOIN distance_combinations dc    ON dc.id             = h.distance_combination_id
-- WHERE res.is_photofinish = 1
-- GROUP BY h.id, h.heat_naam, h.ronde, h.heat_nr, dc.name
-- ORDER BY dc.name, h.ronde, h.heat_nr;
