-- Fase A — baan-layout-editor (fundament).
-- Nieuwe kolom `layout_data` op `banen` om de editor-state (lagen + sub-paths +
-- stadium-/freecurve-params) op te slaan. JSON is self-contained: één rij per
-- baan, geen join-tabel nodig.
--
-- Semantiek: null = geen layout gezet (default); object = de PoC-structuur
-- { layers: { piste|weg|infield: { width, paths: [...] }, ... } }.
-- De editor en de client-side thumbnail-renderer gaan hiermee om; de server
-- bewaart het blob verder zonder parsing (geen SQL-queries op layout_data).

ALTER TABLE banen
    ADD COLUMN layout_data JSON NULL DEFAULT NULL AFTER vereniging_naam;
