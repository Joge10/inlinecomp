-- InlineComp – distances (afstanden per categorie)
-- PK is samengesteld: (distance_combination_id, id)

CREATE TABLE IF NOT EXISTS `distances` (
    `id`                      VARCHAR(36)  NOT NULL,
    `distance_combination_id` VARCHAR(36)  NOT NULL,
    `number`                  TINYINT UNSIGNED DEFAULT NULL,
    `name`                    VARCHAR(100) DEFAULT NULL,
    `target_group`            VARCHAR(50)  DEFAULT NULL,
    `value_meters`            INT UNSIGNED DEFAULT NULL,
    `discipline`              VARCHAR(100) DEFAULT NULL,
    `starts`                  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`distance_combination_id`, `id`),
    CONSTRAINT `fk_dist_dc`
        FOREIGN KEY (`distance_combination_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
