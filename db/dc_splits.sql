-- InlineComp – dc_splits (categorie-opsplitsing per DC)

CREATE TABLE IF NOT EXISTS `dc_splits` (
    `competition_id`  VARCHAR(36)  NOT NULL,
    `dc_id`           VARCHAR(36)  NOT NULL,
    `category`        VARCHAR(20)  NOT NULL,
    `split_group`     VARCHAR(50)  NOT NULL,
    PRIMARY KEY (`dc_id`, `category`),
    KEY `idx_splits_comp` (`competition_id`),
    CONSTRAINT `fk_splits_dc`
        FOREIGN KEY (`dc_id`) REFERENCES `distance_combinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
