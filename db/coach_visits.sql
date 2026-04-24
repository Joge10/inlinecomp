-- InlineComp – coach_visits
--
-- Tracked bezoeken aan /coach/ voor dashboard in Beheer → Gebruikers.
-- Structuur en semantiek identiek aan public_visits (aparte tabel zodat
-- de statistieken van de coach-view los te bekijken zijn).
-- Eén rij per browsersessie; last_seen wordt op elke pageload bijgewerkt
-- zodat 'actieve bezoekers' = sessies waar last_seen > NOW() - 5 min.

CREATE TABLE IF NOT EXISTS `coach_visits` (
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
