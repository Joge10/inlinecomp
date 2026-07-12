-- InlineComp – bonus/afgelaste wedstrijd in serie-klassement (2026-07-12)
--
-- Voegt twee kolommen toe aan klassement_serie_wedstrijden zodat een wedstrijd
-- als "bonus" gemarkeerd kan worden: elke AANWEZIGE rijder (entries.status IN
-- (1,5) = getekend/aanwezig of bevestigd door de organisatie) krijgt dan een
-- vast aantal EXTRA punten, BOVENOP de uitslag. Twee use-cases:
--   • afgelaste wedstrijd (geen uitslag) → wie er was krijgt puur de bonus
--   • zwaardere wedstrijd (finale, lange afstand) → uitslag-punten + bonus
--
--   bonus_modus   0 = normaal (rang → punten), 1 = bonus (extra punten per aanwezige)
--   bonus_punten  extra punten per aanwezige bij bonus_modus = 1 (default 1)

ALTER TABLE `klassement_serie_wedstrijden`
    ADD COLUMN `bonus_modus`  TINYINT(1)   NOT NULL DEFAULT 0 AFTER `is_finale`,
    ADD COLUMN `bonus_punten` DECIMAL(6,2) NOT NULL DEFAULT 1 AFTER `bonus_modus`;
