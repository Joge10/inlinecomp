-- ============================================================
--  Push 4-talig: taal per abonnement onthouden
-- ============================================================
-- De trigger bouwt de meldingtekst in alle 4 talen (nl/en/de/fr); de verzender
-- kiest per abonnement de juiste. Bestaande abonnementen krijgen default 'nl'.

ALTER TABLE push_subscriptions
    ADD COLUMN lang VARCHAR(5) NOT NULL DEFAULT 'nl' AFTER notif_bericht;
