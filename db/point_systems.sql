-- InlineComp – point_systems (puntensysteem per afstand)

CREATE TABLE IF NOT EXISTS `point_systems` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competition_id`          VARCHAR(36)  NOT NULL,
    `distance_combination_id` VARCHAR(36)  NOT NULL,
    `distance_id`             VARCHAR(36)  NOT NULL,
    `split_group`             VARCHAR(50)  DEFAULT NULL,
    `punten_reeks`            JSON         NOT NULL,
    `dns_dnf_methode`         ENUM('vast_99','laatste_positie') NOT NULL DEFAULT 'vast_99',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ps` (`competition_id`, `distance_combination_id`, `distance_id`, `split_group`),
    KEY `fk_ps_dc` (`distance_combination_id`),
    CONSTRAINT `fk_ps_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ps_dc`
        FOREIGN KEY (`distance_combination_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
