-- InlineComp – Engelse vertaling voor publieke mededelingen
--
-- public_meldingen krijgt twee extra velden: titel_en + bericht_en. NL
-- blijft de brontaal (verplicht). EN is optioneel — als leeg laat
-- frontend automatisch fallback naar NL. Operator kan in beheer-UI op
-- 'Vertaal automatisch' klikken → backend roept Claude API aan en vult
-- de EN-velden. Vertaling is daarna nog handmatig aanpasbaar.

ALTER TABLE public_meldingen
    ADD COLUMN titel_en   VARCHAR(255) NULL DEFAULT NULL AFTER bericht,
    ADD COLUMN bericht_en TEXT         NULL DEFAULT NULL AFTER titel_en;
