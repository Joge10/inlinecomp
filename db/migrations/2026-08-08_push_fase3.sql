-- ============================================================
--  Fase 3 push: per-type opt-in (loting/uitslag apart) + public-scope
-- ============================================================
-- Idempotent-vriendelijk: draai deze migratie één keer. Bestaande
-- coach-abonnementen krijgen defaults 1/1 (blijven dus beide typen
-- ontvangen, net als voor Fase 3).

-- 1) Losse aan/uit per meldingtype op het abonnement.
ALTER TABLE push_subscriptions
    ADD COLUMN notif_loting  TINYINT(1) NOT NULL DEFAULT 1 AFTER auth,
    ADD COLUMN notif_uitslag TINYINT(1) NOT NULL DEFAULT 1 AFTER notif_loting;

-- 2) Gevolgde rijders per PUBLIC-abonnement.
--    (Coach-volgers komen uit coach_athletes; public heeft geen roster,
--     dus de gevolgde licenties uit localStorage worden hier gespiegeld.)
CREATE TABLE IF NOT EXISTS push_sub_licenses (
    subscription_id INT UNSIGNED NOT NULL,
    person_license  VARCHAR(32)  NOT NULL,
    PRIMARY KEY (subscription_id, person_license),
    KEY idx_psl_license (person_license),
    CONSTRAINT fk_psl_sub FOREIGN KEY (subscription_id)
        REFERENCES push_subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Event-type op de outbox (bepaalt welke opt-in-voorkeur telt).
ALTER TABLE push_outbox
    ADD COLUMN type VARCHAR(10) NOT NULL DEFAULT 'loting' AFTER scope;
