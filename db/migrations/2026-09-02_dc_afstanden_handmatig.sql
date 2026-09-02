-- InlineComp – per-DC vlag: afstanden handmatig samengesteld
--
-- Zodra de afstanden van een DC via afstanden_beheer.php (wizard deel 1b óf de
-- losse beheer-tabel) zijn bewerkt, is de DC "handmatig". import.php laat de
-- afstanden van zo'n DC dan met rust: een her-import (bv. voor een late
-- inschrijving) mag verwijderde/vervangen afstanden niet opnieuw uit de
-- KNSB-feed terugzetten.
--
-- Additief + default 0 → backwards-compatible; veilig op de gedeelde test/prod-DB.

ALTER TABLE `distance_combinations`
    ADD COLUMN IF NOT EXISTS `afstanden_handmatig` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `max_in_loting`;
