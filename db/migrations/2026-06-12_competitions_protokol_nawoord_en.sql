-- Wedstrijdprotokol: EN-vertaling cache voor het nawoord.
-- Operator typt NL; bij eerste print in EN wordt automatisch via Claude
-- vertaald en het resultaat hier gecached. Bij wijzigen NL-tekst wordt
-- de EN-cache gewist (handled in api/jury_leden.php) zodat de volgende
-- EN-print opnieuw vertaalt.

ALTER TABLE competitions
    ADD COLUMN protokol_nawoord_en TEXT DEFAULT NULL AFTER protokol_nawoord;
