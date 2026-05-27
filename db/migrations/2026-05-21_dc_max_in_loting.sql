-- ============================================================
--  Migratie: handmatige max-in-loting override per DC
--
--  Achtergrond:
--    Het reserve-paneel toont 'In loting: X van max Y' waarbij Y het
--    aantal niet-reserves uit de KNSB-feed is. In de praktijk wijkt dat
--    soms af — organisatie laat bv. 22 rijders toe ipv 20, of de baan-
--    capaciteit verschilt van wat KNSB doorgeeft. Met deze kolom kan
--    de operator een handmatige max instellen die de auto-berekening
--    overschrijft (alleen voor de capaciteit-cap; reserve-inzet en
--    vrije-slot-telling gebruiken dan deze waarde).
--
--  NULL  = auto (gebruik aantal niet-reserves uit KNSB-feed)
--  N>=0  = handmatige override
-- ============================================================

ALTER TABLE distance_combinations
    ADD COLUMN max_in_loting INT UNSIGNED DEFAULT NULL
    COMMENT 'Handmatige override voor max rijders in loting (NULL = auto)';
