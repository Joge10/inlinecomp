-- InlineComp – easter_egg_hits (telt 3×-klik-op-org-logo events in /public)
-- Geen persoonsgegevens, alleen IP voor uniqueness-analyse later.

CREATE TABLE IF NOT EXISTS `easter_egg_hits` (
    `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hit_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip`     VARCHAR(45)  DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_egg_at` (`hit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
