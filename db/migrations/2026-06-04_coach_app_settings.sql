-- ============================================================
--  Migratie: coach_app_settings — globaal coach-app wachtwoord
--
--  Eén drempel-wachtwoord voor de hele coach-app (cross-organisatie),
--  ingesteld door owner aan begin van het seizoen. Komt op de coach-
--  poster. BEWUST geen hash: data is openbaar, wachtwoord is gedeeld
--  via de poster, en owner moet het kunnen lezen om de poster te
--  drukken / aan coaches te mailen.
--
--  Singleton-tabel: maximaal 1 rij (id=1). Default leeg = coach-app
--  geopend voor iedereen (backward-compat tot operator er een zet).
-- ============================================================

CREATE TABLE IF NOT EXISTS `coach_app_settings` (
    `id`              TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `password`        VARCHAR(100)     DEFAULT NULL,
    `password_set_at` DATETIME         DEFAULT NULL,
    `password_set_by` INT UNSIGNED     DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_cas_singleton` CHECK (`id` = 1),
    CONSTRAINT `fk_cas_user`
        FOREIGN KEY (`password_set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Singleton-rij aanmaken (zonder wachtwoord = coach-app blijft open
-- totdat operator één instelt via Systeem).
INSERT INTO `coach_app_settings` (`id`) VALUES (1)
    ON DUPLICATE KEY UPDATE `id` = `id`;
