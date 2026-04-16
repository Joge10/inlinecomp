-- InlineComp – competition_tijdschema (één per wedstrijd)

CREATE TABLE IF NOT EXISTS `competition_tijdschema` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competition_id`  VARCHAR(36)  NOT NULL,
    `systeem`         ENUM('full-final','internationaal-nieuw') NOT NULL DEFAULT 'full-final',
    `status`          ENUM('concept','gepubliceerd') NOT NULL DEFAULT 'concept',
    `aangemaakt_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `gegenereerd_op`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ts_competition` (`competition_id`),
    CONSTRAINT `fk_ts_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
