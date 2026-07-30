-- InlineComp – coach_password_resets (self-service wachtwoord-reset tokens)
--
-- "Wachtwoord vergeten"-flow: de coach vraagt een reset aan → we mailen een
-- link met een token; deze tabel houdt de geldige tokens bij. Dit haalt de
-- owner uit de handmatige-reset-lus.
--
-- Beveiliging:
--   - Token wordt GEHASHT opgeslagen (SHA-256 hex), zodat een DB-lek geen
--     bruikbare reset-links oplevert. De klare token zit alleen in de mail.
--   - Kort geldig (~1u) en eenmalig bruikbaar (`used_at` gezet na gebruik).
--   - Aanvragen rate-limiten in de endpoint; generieke respons ("als dit adres
--     bekend is, sturen we een link") om e-mail-aftasten te voorkomen.

CREATE TABLE IF NOT EXISTS `coach_password_resets` (
    `id`               INT      NOT NULL AUTO_INCREMENT,
    `coach_account_id` INT      NOT NULL,
    `token_hash`       CHAR(64) NOT NULL,                      -- SHA-256 hex van het token
    `expires_at`       DATETIME NOT NULL,                      -- ~1u na aanvraag
    `used_at`          DATETIME DEFAULT NULL,                  -- gezet zodra gebruikt (eenmalig)
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cpr_tokenhash` (`token_hash`),                   -- lookup bij reset-aanvraag
    KEY `idx_cpr_account`   (`coach_account_id`),
    CONSTRAINT `fk_cpr_account`
        FOREIGN KEY (`coach_account_id`) REFERENCES `coach_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
