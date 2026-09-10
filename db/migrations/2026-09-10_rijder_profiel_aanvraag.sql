-- Migratie 2026-09-10 — pending-aanvragen voor het persoonlijke profiel.
-- Zie db/rijder_profiel_aanvraag.sql voor de toelichting. Idempotent
-- (IF NOT EXISTS) zodat 'm nogmaals draaien geen kwaad kan.
CREATE TABLE IF NOT EXISTS `rijder_profiel_aanvraag` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `naam`              VARCHAR(120)  NOT NULL,
    `startnummer`       VARCHAR(20)   DEFAULT NULL,
    `email`             VARCHAR(150)  DEFAULT NULL,
    `gewenste_username` VARCHAR(40)   DEFAULT NULL,
    `opmerking`         VARCHAR(500)  DEFAULT NULL,
    `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `license_key`       VARCHAR(30)   DEFAULT NULL,
    `behandeld_door`    INT           DEFAULT NULL,
    `behandeld_at`      DATETIME      DEFAULT NULL,
    `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rpa_status`  (`status`),
    KEY `idx_rpa_created` (`created_at`),
    KEY `idx_rpa_license` (`license_key`),
    CONSTRAINT `fk_rpa_license`
        FOREIGN KEY (`license_key`) REFERENCES `persons` (`license_key`) ON DELETE SET NULL,
    CONSTRAINT `fk_rpa_behandeld_door`
        FOREIGN KEY (`behandeld_door`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
