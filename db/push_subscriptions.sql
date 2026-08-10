-- InlineComp – push_subscriptions (Web Push-abonnementen)
--
-- Eén rij per browser/apparaat-abonnement. scope onderscheidt coach vs public.
--   coach  → coach_account_id gevuld; targeting via coach_athletes-roster.
--   public → coach_account_id NULL, licenses = JSON van gevolgde license_keys
--            (public volgt anoniem, client-side; de subscription stuurt de lijst mee).
--
-- endpoint kan lang zijn (FCM) → in TEXT; uniciteit via endpoint_hash (sha256).
-- Verlopen abonnementen (404/410 bij versturen) worden door de sender opgeruimd.

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope`            VARCHAR(10)  NOT NULL,               -- 'coach' | 'public'
    `coach_account_id` INT          DEFAULT NULL,           -- bij scope='coach'
    `endpoint`         TEXT         NOT NULL,
    `endpoint_hash`    CHAR(64)     NOT NULL,               -- sha256(endpoint)
    `p256dh`           VARCHAR(255) NOT NULL,
    `auth`             VARCHAR(255) NOT NULL,
    `notif_loting`     TINYINT(1)   NOT NULL DEFAULT 1,     -- opt-in per type (Fase 3)
    `notif_uitslag`    TINYINT(1)   NOT NULL DEFAULT 1,
    `licenses`         TEXT         DEFAULT NULL,           -- bij scope='public': JSON-lijst license_keys (mirror; targeting via push_sub_licenses)
    `user_agent`       VARCHAR(255) DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_endpoint` (`endpoint_hash`),
    KEY `idx_coach` (`coach_account_id`),
    KEY `idx_scope` (`scope`),
    CONSTRAINT `fk_push_coach`
        FOREIGN KEY (`coach_account_id`) REFERENCES `coach_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
