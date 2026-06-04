-- ============================================================
--  Migratie: competitions.bron — onderscheid KNSB-feed vs handmatig
--
--  Voor wedstrijden die buiten de KNSB-feed om in InlineComp komen
--  (organisaties zonder Vantage-aansluiting, internationaal toernooi,
--  etc.). Zonder deze kolom kan de Importeer-lijst geen onderscheid
--  maken tussen "via Vantage gedownload" en "handmatig aangemaakt".
--
--  Default 'knsb' voor bestaande rijen → backward-compat: alle huidige
--  wedstrijden blijven gedragen als KNSB-feed-imports.
--
--  Optioneel later: 'historie' voor PDF-historie-imports
--  (helpers.php → 'hist-{...}'-prefix in id).
-- ============================================================

ALTER TABLE `competitions`
    ADD COLUMN `bron` ENUM('knsb','handmatig','historie')
        NOT NULL DEFAULT 'knsb'
        AFTER `discipline`;

-- Historie-import-wedstrijden retroactief markeren (id-prefix 'hist-').
UPDATE `competitions`
    SET `bron` = 'historie'
    WHERE `id` LIKE 'hist-%';
