-- InlineComp – distances (afstanden per categorie)
--
-- PK is samengesteld: (distance_combination_id, id)
--
-- ⚠️ BELANGRIJK: `id` alléén is NIET uniek in deze tabel. Dezelfde afstand
-- (bv. "500m sprint") komt in meerdere distance_combinations voor met
-- dezelfde `id`, maar telkens als aparte rij per DC (de KNSB API levert
-- dezelfde afstand-UUIDs aan voor elke categorie).
--
-- Elke JOIN naar `distances` MOET daarom BEIDE kolommen in de ON-conditie
-- meenemen, bijvoorbeeld:
--   LEFT JOIN distances d ON d.id = h.distance_id
--                        AND d.distance_combination_id = h.distance_combination_id
-- Alleen op `d.id` joinen levert N× zoveel rijen op als er DC's zijn in de
-- wedstrijd (cartesisch product binnen de afstand-namespace).
--
-- Race-type bepaalt welke velden zinvol zijn én hoe er gesorteerd wordt.
-- Waarden komen overeen met `heats.race_type`:
--   sprint       → alleen tijd (geen rondes, geen punten)
--   inline       → rondes + tijd (rondes DESC, tijd ASC)
--   puntenkoers  → pk-punten + rondes + tijd
--   afvalkoers   → afvalkoers (eliminatie): rondes + tijd
-- Default 'sprint' is veilig: een rijder zonder rondes sorteert correct.

CREATE TABLE IF NOT EXISTS `distances` (
    `id`                      VARCHAR(36)      NOT NULL,
    `distance_combination_id` VARCHAR(36)      NOT NULL,
    `number`                  TINYINT UNSIGNED DEFAULT NULL,
    `name`                    VARCHAR(100)     DEFAULT NULL,
    `target_group`            VARCHAR(50)      DEFAULT NULL,
    `value_meters`            INT UNSIGNED     DEFAULT NULL,
    `discipline`              VARCHAR(100)     DEFAULT NULL,
    `starts`                  DATETIME         DEFAULT NULL,
    `race_type`               ENUM('sprint','inline','puntenkoers','afvalkoers') NOT NULL DEFAULT 'sprint',
    PRIMARY KEY (`distance_combination_id`, `id`),
    CONSTRAINT `fk_dist_dc`
        FOREIGN KEY (`distance_combination_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
