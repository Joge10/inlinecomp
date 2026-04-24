-- Migration: voeg race_type toe aan distances.
-- Draaien op bestaande database; in distances.sql staat de kolom al in de
-- CREATE TABLE voor verse installaties.

ALTER TABLE `distances`
    ADD COLUMN `race_type`
        ENUM('sprint','inline','puntenkoers','afvalkoers')
        NOT NULL DEFAULT 'sprint'
        AFTER `starts`;

-- Bestaande rijen zinvol initialiseren:
--   - naam bevat "lange afstand" of "puntenkoers" of "afvalkoers" → inline
--     (user kan later per afstand naar punten/afval zetten)
--   - value_meters > 1000 → inline
--   - rest blijft sprint (default)
UPDATE `distances`
   SET `race_type` = 'puntenkoers'
 WHERE `name` LIKE '%puntenkoers%'
    OR `name` LIKE '%punten koers%';

UPDATE `distances`
   SET `race_type` = 'afvalkoers'
 WHERE `name` LIKE '%afvalkoers%'
    OR `name` LIKE '%afval koers%';

UPDATE `distances`
   SET `race_type` = 'inline'
 WHERE `race_type` = 'sprint'
   AND (`name` LIKE '%lange afstand%' OR `value_meters` > 1000);

-- (Eenmalige opschoning van oude rondes/punten-vervuiling op sprint-rijen
--  is hier uitgecommentarieerd; de user draaide deze al handmatig via een
--  variant met `d.value_meters <= 1000 AND d.name NOT LIKE '%lange afstand%'`.
--  Voor een nieuwe installatie of als je deze stap alsnog wilt doen:
--
-- UPDATE `results` r
-- JOIN `heat_entries` he ON he.id = r.heat_entry_id
-- JOIN `heats`        h  ON h.id  = he.heat_id
-- JOIN `distances`    d  ON d.id  = h.distance_id
--                       AND d.distance_combination_id = h.distance_combination_id
--    SET r.rondes = NULL, r.punten = NULL
--  WHERE d.race_type = 'sprint';
-- )
