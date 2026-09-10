-- Migration 2026-09-10 — rijder_profiel (persoonlijk profiel "Mijn InlineComp")
--
-- Nieuwe tabel voor de rijder-profiel-claim + naam/PIN-login. Additief +
-- backwards-compatible (nieuwe tabel, raakt niets bestaands) → veilig op de
-- gedeelde test/prod-DB. Zie db/rijder_profiel.sql voor de toelichting.

CREATE TABLE IF NOT EXISTS `rijder_profiel` (
    `license_key`       VARCHAR(30)      NOT NULL,
    `pin_hash`          VARCHAR(255)     DEFAULT NULL,
    `claim_token_hash`  CHAR(64)         DEFAULT NULL,
    `claim_expires`     DATETIME         DEFAULT NULL,
    `claimed_at`        DATETIME         DEFAULT NULL,
    `pin_pogingen`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `lockout_tot`       DATETIME         DEFAULT NULL,
    `laatste_login`     DATETIME         DEFAULT NULL,
    `aangemaakt_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`license_key`),
    KEY `idx_rp_claimtoken` (`claim_token_hash`),
    CONSTRAINT `fk_rp_person`
        FOREIGN KEY (`license_key`) REFERENCES `persons` (`license_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
