-- InlineComp – users, sessions en login_logs

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(50)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `naam`          VARCHAR(100) NOT NULL DEFAULT '',
    `email`         VARCHAR(150) DEFAULT NULL,
    `role`          ENUM('owner','admin','importer','planner','timer','viewer') NOT NULL DEFAULT 'viewer',
    `actief`        TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sessions` (
    `token`       CHAR(64)    NOT NULL,
    `user_id`     INT         NOT NULL,
    `expires_at`  DATETIME    NOT NULL,
    `created_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token`),
    KEY `idx_user`    (`user_id`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `sessions_ibfk_1`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_logs` (
    `id`          INT         NOT NULL AUTO_INCREMENT,
    `user_id`     INT         DEFAULT NULL,
    `naam`        VARCHAR(100) NOT NULL DEFAULT '',
    `username`    VARCHAR(100) NOT NULL DEFAULT '',
    `actie`       VARCHAR(20)  NOT NULL DEFAULT 'login',
    `ip_adres`    VARCHAR(45)  NOT NULL DEFAULT '',
    `land`        VARCHAR(80)  NOT NULL DEFAULT '',
    `stad`        VARCHAR(80)  NOT NULL DEFAULT '',
    `browser`     VARCHAR(60)  NOT NULL DEFAULT '',
    `os`          VARCHAR(40)  NOT NULL DEFAULT '',
    `user_agent`  TEXT         DEFAULT NULL,
    `tijdstip`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tijdstip` (`tijdstip`),
    KEY `idx_user`     (`user_id`),
    CONSTRAINT `fk_ll_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
