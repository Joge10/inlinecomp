-- 2026-05-12 – Wedstrijd-zichtbaarheid voor /coach + /public
--
-- Tot nu toe was elke wedstrijd in de DB direct zichtbaar voor /coach en
-- /public. Operator wil voorbereidingsfase kunnen afronden zonder dat
-- onaffe info al naar buiten lekt. Toggle staat in Beheer naast posters
-- en meldingen (instellingen.js per wedstrijd-rij).
--
-- Default 0 = nieuw geïmporteerde wedstrijden zijn onzichtbaar tot operator
-- publiceert. Bestaande wedstrijden behouden huidige zichtbaarheid (= 1).

ALTER TABLE `competitions`
    ADD COLUMN `public_zichtbaar` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tijdschema_version`;

-- Bestaande wedstrijden expliciet op zichtbaar zetten — anders verdwijnen
-- ze ineens uit /coach + /public direct na deploy van deze migratie.
UPDATE competitions SET public_zichtbaar = 1;
