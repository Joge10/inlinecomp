-- /public wedstrijd-info-view fase 5b-content: Infobulletin + Flyer per wedstrijd.
--
-- Drie velden op `competitions` voor documenten die bij de wedstrijd horen:
--  - infobulletin_url:  externe URL naar PDF (bv. Google Drive).
--                       Primair bij renderen; fallback-link bij Drive-DENY.
--  - infobulletin_file: relpath (bv. `uploads/wedstrijd_docs/<comp_id>/info_<ts>.pdf`)
--                       van een zelf-gehoste PDF (fallback als URL leeg is).
--  - flyer_file:        relpath (bv. `uploads/wedstrijd_docs/<comp_id>/flyer_<ts>.jpg`)
--                       van de promo-flyer (altijd upload, geen externe URL).
--
-- Alle drie optioneel; corresponderende tab wordt verborgen als zowel URL
-- als file leeg zijn (vereniging-tab blijft altijd staan).

ALTER TABLE competitions
    ADD COLUMN infobulletin_url  VARCHAR(500) NULL DEFAULT NULL AFTER protokol_nawoord_foto_caption,
    ADD COLUMN infobulletin_file VARCHAR(255) NULL DEFAULT NULL AFTER infobulletin_url,
    ADD COLUMN flyer_file        VARCHAR(255) NULL DEFAULT NULL AFTER infobulletin_file;
