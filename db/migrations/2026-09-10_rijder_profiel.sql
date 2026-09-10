-- Migration 2026-09-10 — rijder_profiel (persoonlijk profiel "Mijn InlineComp")
--
-- Nieuwe tabel voor de rijder-profiel-claim + naam/PIN-login. Additief +
-- backwards-compatible (nieuwe tabel, raakt niets bestaands) → veilig op de
-- gedeelde test/prod-DB. Zie db/rijder_profiel.sql voor de toelichting.

CREATE TABLE IF NOT EXISTS `rijder_profiel` (
    `license_key`       VARCHAR(30)      NOT NULL,
    `username`          VARCHAR(40)      DEFAULT NULL,
    `pin_hash`          VARCHAR(255)     DEFAULT NULL,
    `claim_token_hash`  CHAR(64)         DEFAULT NULL,
    `claim_expires`     DATETIME         DEFAULT NULL,
    `claimed_at`        DATETIME         DEFAULT NULL,
    `pin_pogingen`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `lockout_tot`       DATETIME         DEFAULT NULL,
    `laatste_login`     DATETIME         DEFAULT NULL,
    `aangemaakt_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`license_key`),
    UNIQUE KEY `uq_rp_username` (`username`),
    KEY `idx_rp_claimtoken` (`claim_token_hash`),
    CONSTRAINT `fk_rp_person`
        FOREIGN KEY (`license_key`) REFERENCES `persons` (`license_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Als de tabel al bestond (aangemaakt vóór de username-toevoeging): kolom + index
-- los toevoegen (negeer een 'duplicate column'-fout als je 'm al hebt).
-- ALTER TABLE `rijder_profiel`
--     ADD COLUMN `username` VARCHAR(40) DEFAULT NULL AFTER `license_key`,
--     ADD UNIQUE KEY `uq_rp_username` (`username`);
