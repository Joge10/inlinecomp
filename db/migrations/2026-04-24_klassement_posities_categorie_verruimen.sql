-- Migration: klassement_posities.categorie verruimen VARCHAR(20) -> VARCHAR(100)
--
-- Voor het NK-tussenstand-PDF-formaat zijn de sectie-labels langer dan de
-- oude KNSB-cat-codes: "Mannen Senioren Sprint" (22 tekens) past niet in
-- VARCHAR(20). Gevolg: insert werd stilletjes getruncate/afgewezen en het
-- klassement bleef in de UI leeg (filter matchte niet).
--
-- Uitvoeren na upload van de nieuwe parser. Bestaande rijen blijven geldig;
-- dit breekt niets.

ALTER TABLE `klassement_posities`
    MODIFY COLUMN `categorie` VARCHAR(100) DEFAULT NULL;
