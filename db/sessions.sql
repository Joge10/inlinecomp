-- InlineComp – sessions (ingelogde gebruikers)
--
-- Elke succesvolle login krijgt een token van 64 hex-tekens dat als cookie
-- bij de client wordt gezet. Verlopen sessies worden periodiek opgeruimd
-- (auth/session.php). Een logout wist de rij.

CREATE TABLE IF NOT EXISTS `sessions` (
    `token`      CHAR(64) NOT NULL,
    `user_id`    INT      NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token`),
    KEY `idx_user`    (`user_id`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `sessions_ibfk_1`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
