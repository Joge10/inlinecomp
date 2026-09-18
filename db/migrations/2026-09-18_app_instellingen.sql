-- ============================================================
--  Migratie 2026-09-18 — Globale app-instellingen + maintenance mode
--
--  Eén generieke sleutel/waarde-tabel voor systeembrede instellingen (los van
--  competition_instellingen, dat per wedstrijd geldt). Eerste gebruiker:
--  de in-app onderhoudsmodus.
--
--  maintenance_mode   : '0' = uit, '1' = aan (publieke apps tonen dan de
--                       onderhoudspagina; staf met bypass werkt door).
--  maintenance_bypass : 'owner' = alleen owner komt er nog in (voor het echt
--                       risicovolle werk); 'owner_admin' = owner + admin.
--  maintenance_bericht: optioneel bericht (bv. "terug rond 15:00").
--  maintenance_sinds  : DATETIME waarop de modus is ingeschakeld (herinnering).
--
--  DB: MariaDB 10.11. Additief + herhaalbaar (INSERT IGNORE laat bestaande
--  waarden ongemoeid).
-- ============================================================

CREATE TABLE IF NOT EXISTS `app_instellingen` (
    `sleutel` VARCHAR(64)  NOT NULL PRIMARY KEY,
    `waarde`  TEXT         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_instellingen` (`sleutel`, `waarde`) VALUES
    ('maintenance_mode',    '0'),
    ('maintenance_bypass',  'owner_admin'),
    ('maintenance_bericht', ''),
    ('maintenance_sinds',   NULL);
