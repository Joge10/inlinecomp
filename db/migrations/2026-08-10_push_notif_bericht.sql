-- ============================================================
--  Push Fase 4: derde meldingtype "bericht" (mededelingen 📢)
-- ============================================================
-- Aparte opt-in naast notif_loting/notif_uitslag. Bestaande abonnementen
-- krijgen default 1 (blijven dus mededelingen ontvangen). Draai NÁ de
-- Fase 3-migratie (2026-08-08_push_fase3.sql).

ALTER TABLE push_subscriptions
    ADD COLUMN notif_bericht TINYINT(1) NOT NULL DEFAULT 1 AFTER notif_uitslag;
