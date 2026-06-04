-- ============================================================
--  Migratie: coach_app_settings — globaal coach-app wachtwoord
--
--  Eén drempel-wachtwoord voor de hele coach-app (cross-organisatie),
--  ingesteld door owner aan begin van het seizoen. Komt op de coach-
--  poster. BEWUST geen hash: data is openbaar, wachtwoord is gedeeld
--  via de poster, en owner moet 'm kunnen lezen om de poster te
--  drukken / aan coaches te mailen.
--
--  Singleton-tabel: maximaal 1 rij (id=1, gewaarborgd door PRIMARY KEY
--  + DEFAULT 1 + de INSERT-ON-DUPLICATE hieronder). CHECK-constraint
--  weggelaten omdat oudere MySQL-versies die niet enforce'n + phpMyAdmin
--  parsing erover struikelt.
--
--  password_set_by is INT (geen UNSIGNED) zodat de FK naar users.id
--  (= INT NOT NULL AUTO_INCREMENT) wel klopt qua type.
-- ============================================================

CREATE TABLE IF NOT EXISTS `coach_app_settings` (
    `id`              TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `password`        VARCHAR(100)     DEFAULT NULL,
    `password_set_at` DATETIME         DEFAULT NULL,
    `password_set_by` INT              DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_cas_user`
        FOREIGN KEY (`password_set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `coach_app_settings` (`id`) VALUES (1)
    ON DUPLICATE KEY UPDATE `id` = `id`;
