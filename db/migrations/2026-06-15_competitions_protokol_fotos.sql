-- Wedstrijdprotokol: optionele foto's op voorblad en bij nawoord.
--   protokol_voorblad_foto         → grote foto bovenste helft titelpagina
--   protokol_nawoord_foto          → foto bij het nawoord (pasfoto van auteur,
--                                    of vrije foto)
--   protokol_nawoord_foto_caption  → vrije tekst onder de nawoord-foto
--                                    (bv. naam + functie van de schrijver, of
--                                    omschrijving als de foto niet een persoon is)
-- Allemaal NULL → geen foto/caption renderen.

ALTER TABLE competitions
    ADD COLUMN protokol_voorblad_foto         VARCHAR(255) DEFAULT NULL AFTER protokol_nawoord_en,
    ADD COLUMN protokol_nawoord_foto          VARCHAR(255) DEFAULT NULL AFTER protokol_voorblad_foto,
    ADD COLUMN protokol_nawoord_foto_caption  TEXT         DEFAULT NULL AFTER protokol_nawoord_foto;
