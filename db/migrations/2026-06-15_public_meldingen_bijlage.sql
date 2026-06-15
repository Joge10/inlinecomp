-- Public-meldingen: optionele bijlage per melding (PDF, programma-poster,
-- briefing, sponsor-folder, etc.). Eén bestand per melding — voor meerdere
-- bestanden maakt de operator gewoon meerdere meldingen aan.
--
--   bijlage_path  → relatief pad onder uploads/meldingen/{id}/...
--   bijlage_naam  → originele bestandsnaam, gebruikt als download-naam
--   bijlage_mime  → content-type voor de download-headers
--
-- Alle drie NULL = geen bijlage; download-knop wordt in /public weggelaten.

ALTER TABLE public_meldingen
    ADD COLUMN bijlage_path VARCHAR(255) DEFAULT NULL,
    ADD COLUMN bijlage_naam VARCHAR(255) DEFAULT NULL,
    ADD COLUMN bijlage_mime VARCHAR(100) DEFAULT NULL;
