-- 2026-05-04 – Banen + baan_aliassen + competitions.baan_id (org-scoped)
--
-- Per organisatie kan een lijst banen worden bijgehouden, met per baan een
-- gastheer-vereniging + logo. Dezelfde fysieke baan kan onder meerdere
-- organisaties apart voorkomen. Auto-koppeling + auto-aanmaak gebeurt bij
-- import (vergelijk.php / import.php) op basis van competitions.venue_name
-- + baan_aliassen.

CREATE TABLE IF NOT EXISTS `banen` (
    `id`                VARCHAR(36)  NOT NULL,
    `organisatie_id`    VARCHAR(36)  NOT NULL,
    `naam`              VARCHAR(255) NOT NULL,
    `stad`              VARCHAR(100) DEFAULT NULL,
    `vereniging_naam`   VARCHAR(255) DEFAULT NULL,
    `logo_path`         VARCHAR(500) DEFAULT NULL,
    `logo_updated_at`   TIMESTAMP    NULL DEFAULT NULL,
    `created_at`        TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_org_naam` (`organisatie_id`, `naam`),
    KEY `idx_org` (`organisatie_id`),
    CONSTRAINT `fk_baan_org`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `baan_aliassen` (
    `id`         VARCHAR(36)  NOT NULL,
    `baan_id`    VARCHAR(36)  NOT NULL,
    `naam`       VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_baan` (`baan_id`),
    CONSTRAINT `fk_alias_baan`
        FOREIGN KEY (`baan_id`) REFERENCES `banen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- competitions.baan_id — nullable, ON DELETE SET NULL.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'competitions'
      AND COLUMN_NAME  = 'baan_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE competitions
        ADD COLUMN baan_id VARCHAR(36) DEFAULT NULL AFTER organisatie_id,
        ADD KEY idx_comp_baan (baan_id),
        ADD CONSTRAINT fk_comp_baan
            FOREIGN KEY (baan_id) REFERENCES banen (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
