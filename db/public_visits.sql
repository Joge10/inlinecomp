-- InlineComp – public_visits
--
-- Tracked bezoeken aan /public/ voor dashboard in Beheer → Gebruikers.
-- Eén rij per browsersessie; last_seen wordt op elke pageload bijgewerkt
-- zodat 'actieve bezoekers' = sessies waar last_seen > NOW() - 5 min.
-- Totaal-aantal unieke bezoekers = COUNT(*), totaal-aantal page views = SUM(hits).

CREATE TABLE IF NOT EXISTS `public_visits` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  VARCHAR(64)  NOT NULL,
    `first_seen`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    `hits`        INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session` (`session_id`),
    KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
