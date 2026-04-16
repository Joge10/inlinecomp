-- InlineComp – organisaties, aliassen en sponsors

CREATE TABLE IF NOT EXISTS `organisaties` (
    `id`         VARCHAR(36)  NOT NULL,
    `naam`       VARCHAR(255) NOT NULL,
    `logo_path`  VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `email`      VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_naam` (`naam`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `organisatie_aliassen` (
    `id`             VARCHAR(36)  NOT NULL,
    `organisatie_id` VARCHAR(36)  NOT NULL,
    `naam`           VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_alias_naam` (`naam`),
    KEY `idx_org` (`organisatie_id`),
    CONSTRAINT `organisatie_aliassen_ibfk_1`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `organisatie_sponsors` (
    `id`             VARCHAR(36)  NOT NULL,
    `organisatie_id` VARCHAR(36)  NOT NULL,
    `naam`           VARCHAR(255) NOT NULL,
    `logo_path`      VARCHAR(500) DEFAULT NULL,
    `url`            VARCHAR(500) DEFAULT NULL,
    `volgorde`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_org` (`organisatie_id`),
    CONSTRAINT `organisatie_sponsors_ibfk_1`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
