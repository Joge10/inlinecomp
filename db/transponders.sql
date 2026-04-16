-- InlineComp – transponders (per persoon per wedstrijd)

CREATE TABLE IF NOT EXISTS `transponders` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `person_license`  VARCHAR(30)  NOT NULL,
    `competition_id`  VARCHAR(36)  NOT NULL,
    `slot`            TINYINT UNSIGNED NOT NULL,
    `code`            VARCHAR(50)  DEFAULT NULL,
    `source`          ENUM('knsb','manual') NOT NULL DEFAULT 'knsb',
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_transponder` (`person_license`, `competition_id`, `slot`),
    KEY `idx_tp_code` (`code`),
    KEY `fk_tp_competition` (`competition_id`),
    CONSTRAINT `fk_tp_person`
        FOREIGN KEY (`person_license`) REFERENCES `persons` (`license_key`),
    CONSTRAINT `fk_tp_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
