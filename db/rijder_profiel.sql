-- InlineComp – rijder_profiel
--
-- Persoonlijk rijder-profiel ("Mijn InlineComp") — toegang via naam + PIN.
-- Eén rij per rijder (license_key). De rij ontstaat bij het genereren van een
-- claim-link (beheer) of bij een balie-claim; pin_hash is NULL tot de rijder
-- zelf een PIN heeft ingesteld via de claim-link.
--
-- AVG: bevat GEEN e-mailadres (bewust). Alleen: gehashte PIN + gehashte,
-- verlopende claim-token + lockout-teller. FK op persons → verdwijnt mee als
-- de persoon wordt verwijderd/geanonimiseerd (cascade).

CREATE TABLE IF NOT EXISTS `rijder_profiel` (
    `license_key`       VARCHAR(30)      NOT NULL,
    `pin_hash`          VARCHAR(255)     DEFAULT NULL,   -- bcrypt; NULL = nog niet geclaimd
    `claim_token_hash`  CHAR(64)         DEFAULT NULL,   -- sha256-hex van de eenmalige claim-token
    `claim_expires`     DATETIME         DEFAULT NULL,   -- claim-link vervaltijd
    `claimed_at`        DATETIME         DEFAULT NULL,   -- moment dat de PIN is ingesteld
    `pin_pogingen`      TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- mislukte PIN-pogingen (lockout)
    `lockout_tot`       DATETIME         DEFAULT NULL,   -- tijdelijk geblokkeerd tot dit moment
    `laatste_login`     DATETIME         DEFAULT NULL,
    `aangemaakt_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`license_key`),
    KEY `idx_rp_claimtoken` (`claim_token_hash`),
    CONSTRAINT `fk_rp_person`
        FOREIGN KEY (`license_key`) REFERENCES `persons` (`license_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
