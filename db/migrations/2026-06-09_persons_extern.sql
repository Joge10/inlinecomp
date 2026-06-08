-- InlineComp – persons.extern + extern_federatie
--
-- Markeer rijders die NIET via de KNSB-feed komen (CSV-import voor club-
-- wedstrijden, buitenlandse gasten, niet-licentiehouders). Deze rijders
-- moeten:
--   1. Niet als "verdwenen" worden gemarkeerd bij KNSB-feed-vergelijking
--      (filter ze uit in api/vergelijk.php + api/import.php)
--   2. Wel volwaardig kunnen deelnemen aan entries/heats/transponders
--   3. Optioneel een buitenlandse federatie hebben (FFRS, DRIV, etc.)
--
-- license_key voor externe rijders krijgt formaat 'x-{12-char-random}'
-- (parallel aan 'p-' voor pending). Geen conflict met KNSB-licenties want
-- die zijn numeriek.

ALTER TABLE persons
    ADD COLUMN `extern` BOOLEAN NOT NULL DEFAULT 0 AFTER `pending_source`,
    ADD COLUMN `extern_federatie` VARCHAR(50) DEFAULT NULL AFTER `extern`,
    ADD KEY `idx_persons_extern` (`extern`);

-- Geen UPDATE nodig: bestaande rijders blijven op extern=0 (KNSB-feed).
-- Nieuwe externe rijders worden door de CSV-import-wizard op extern=1 gezet.
