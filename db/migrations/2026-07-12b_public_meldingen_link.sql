-- Public-meldingen: optionele call-to-action link/knop per melding (2026-07-12).
--
-- Use-case: een survey-uitnodiging of externe link met een klikbare knop onder
-- het bericht (opent in een nieuw tabblad). De URL is taal-neutraal; het
-- knop-label is 4-talig (net als titel/bericht) met fallback EN -> NL en wordt
-- meevertaald door de bestaande AI-vertaalknop.
--
--   link_url             externe URL (http/https). NULL/leeg = geen knop.
--   link_tekst           knop-label NL (verplicht zodra link_url gezet is)
--   link_tekst_en/de/fr  vertaalde labels (optioneel; fallback EN -> NL)

ALTER TABLE `public_meldingen`
    ADD COLUMN `link_url`      VARCHAR(500) DEFAULT NULL AFTER `bericht_fr`,
    ADD COLUMN `link_tekst`    VARCHAR(120) DEFAULT NULL AFTER `link_url`,
    ADD COLUMN `link_tekst_en` VARCHAR(120) DEFAULT NULL AFTER `link_tekst`,
    ADD COLUMN `link_tekst_de` VARCHAR(120) DEFAULT NULL AFTER `link_tekst_en`,
    ADD COLUMN `link_tekst_fr` VARCHAR(120) DEFAULT NULL AFTER `link_tekst_de`;
