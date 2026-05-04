-- 2026-05-04c – Public meldingen (pop-up mededelingen tijdens wedstrijd)

CREATE TABLE IF NOT EXISTS `public_meldingen` (
    `id`               VARCHAR(36)  NOT NULL,
    `competition_id`   VARCHAR(36)  NOT NULL,
    `titel`            VARCHAR(255) NOT NULL,
    `bericht`          TEXT         NOT NULL,
    `prio`             ENUM('info','warn','urgent') NOT NULL DEFAULT 'info',
    `geldig_van`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `geldig_tot`       DATETIME     NULL DEFAULT NULL,
    `aangemaakt_door`  VARCHAR(36)  DEFAULT NULL,
    `aangemaakt_op`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_comp_geldig` (`competition_id`, `geldig_van`),
    CONSTRAINT `fk_meld_comp`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
