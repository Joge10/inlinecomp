-- InlineComp – klassementen (KNSB PDF import voor seeding)

CREATE TABLE IF NOT EXISTS `klassementen` (
    `id`              VARCHAR(36)  NOT NULL,
    `naam`            VARCHAR(255) NOT NULL,
    `seizoen`         VARCHAR(20)  DEFAULT NULL,
    `bron_bestand`    VARCHAR(255) DEFAULT NULL,
    `categorieen`     JSON         DEFAULT NULL,
    `totaal_rijders`  INT          NOT NULL DEFAULT 0,
    `aangemaakt_op`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `org_id`          VARCHAR(36)  DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_kl_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `klassement_posities` (
    `id`              VARCHAR(16)  NOT NULL,
    `klassement_id`   VARCHAR(36)  NOT NULL,
    `positie`         INT          NOT NULL,
    `start_number`    VARCHAR(20)  DEFAULT NULL,
    `naam`            VARCHAR(255) NOT NULL,
    `categorie`       VARCHAR(20)  DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_kl_id`  (`klassement_id`),
    KEY `idx_kl_cat` (`klassement_id`, `categorie`),
    CONSTRAINT `klassement_posities_ibfk_1`
        FOREIGN KEY (`klassement_id`) REFERENCES `klassementen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
