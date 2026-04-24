-- InlineComp – login_logs (audit van inlog/logout/mislukte pogingen)
--
-- Eén rij per poging, bedoeld voor het log-overzicht in Beheer → Gebruikers.
-- `user_id` wordt NULL bij mislukte logins met een niet-bestaande username
-- en bij ON DELETE van een user (account weg, log blijft voor audit).
-- Geo-velden (`land`/`stad`) worden best-effort bepaald uit het IP.

CREATE TABLE IF NOT EXISTS `login_logs` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `user_id`    INT          DEFAULT NULL,
    `naam`       VARCHAR(100) NOT NULL DEFAULT '',
    `username`   VARCHAR(100) NOT NULL DEFAULT '',
    `actie`      VARCHAR(20)  NOT NULL DEFAULT 'login',
    `ip_adres`   VARCHAR(45)  NOT NULL DEFAULT '',
    `land`       VARCHAR(80)  NOT NULL DEFAULT '',
    `stad`       VARCHAR(80)  NOT NULL DEFAULT '',
    `browser`    VARCHAR(60)  NOT NULL DEFAULT '',
    `os`         VARCHAR(40)  NOT NULL DEFAULT '',
    `user_agent` TEXT         DEFAULT NULL,
    `tijdstip`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tijdstip` (`tijdstip`),
    KEY `idx_user`     (`user_id`),
    CONSTRAINT `fk_ll_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
