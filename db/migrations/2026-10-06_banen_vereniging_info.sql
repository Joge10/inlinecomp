-- Fase A-blok 3b: baan-/vereniging-info-velden voor de Vereniging-tab in
-- de wedstrijd-info-view.
--
-- Vier nieuwe velden op `banen`:
--  - adres:       adres waar de baan ligt (vrije tekst, meerdere regels)
--  - over_tekst:  korte tekst over de vereniging / baan
--  - over_foto:   pad naar foto bij de over-tekst (optioneel; als leeg wordt
--                 het logo groot getoond ipv een inline-foto)
--  - website_url: externe URL naar de vereniging-website
--
-- Allemaal nullable; cross-org fallback (zelfde pattern als logo_path en
-- layout_data) wordt in de endpoints met COALESCE geregeld, zodat 1× invullen
-- volstaat voor alle organisaties die dezelfde baan delen.

ALTER TABLE banen
    ADD COLUMN adres       TEXT         NULL DEFAULT NULL AFTER vereniging_naam,
    ADD COLUMN over_tekst  TEXT         NULL DEFAULT NULL AFTER adres,
    ADD COLUMN over_foto   VARCHAR(255) NULL DEFAULT NULL AFTER over_tekst,
    ADD COLUMN website_url VARCHAR(500) NULL DEFAULT NULL AFTER over_foto;
