-- Rate-limiting voor login-pogingen.
-- Elke login-poging (zowel mislukt als gelukt) krijgt een rij. Vóór elke
-- nieuwe poging telt api/auth.php hoeveel mislukten dit IP in het venster
-- (15 min) heeft gehad; bij ≥10 wordt het IP geweigerd met HTTP 429.
--
-- Rijen ouder dan 1 dag worden bij elke login opgeruimd (huishouding,
-- analoog aan sessions-cleanup). De combi-index op (ip, ts) maakt de
-- count-query snel.

CREATE TABLE IF NOT EXISTS `login_pogingen` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip`        VARCHAR(45)  NOT NULL,                       -- IPv4 of IPv6
    `username`  VARCHAR(100) DEFAULT NULL,                   -- evt. naam die werd geprobeerd
    `ts`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `success`   TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_ip_ts`   (`ip`, `ts`),
    KEY `idx_user_ts` (`username`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email-alerts bij verdachte login-patronen (rate-limit-triggers).
-- Cooldown van 60 min per (reden + ip/username): voorkomt mail-spam bij een
-- bot die de drempel keer op keer raakt. Reden = 'ip_burst' (één IP heeft
-- te veel mislukten op willekeurige users) of 'user_burst' (één username
-- heeft te veel mislukten vanaf willekeurige IPs).
CREATE TABLE IF NOT EXISTS `login_alerts` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reden`    VARCHAR(20)  NOT NULL,                        -- 'ip_burst' | 'user_burst'
    `ip`       VARCHAR(45)  DEFAULT NULL,
    `username` VARCHAR(100) DEFAULT NULL,
    `aantal`   SMALLINT UNSIGNED DEFAULT NULL,               -- # mislukten in venster
    `ts`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reden_ip_ts`   (`reden`, `ip`,       `ts`),
    KEY `idx_reden_user_ts` (`reden`, `username`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
