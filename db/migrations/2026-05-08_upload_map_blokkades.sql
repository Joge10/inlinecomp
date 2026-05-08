-- 2026-05-08 – Upload-mappen blokkeren (verbergen in live-import-dropdown).
--
-- De uploader/-folder vult zich met submappen per wedstrijd (bv.
-- "Rotterdam_2_5_NK"). Tijdens een wedstrijd staan er soms 5-10 oude
-- mappen tussen, en moet je in de import-dropdown door te scrollen.
--
-- Met deze tabel kan een admin oude mappen "blokkeren" → ze blijven op
-- disk staan (geen onomkeerbare verwijderactie), maar verdwijnen uit
-- de live-import-dropdown. In Beheer → Systeem → Uploads kun je elke
-- map per knop blokkeren/deblokkeren. Een toggle in de import-dropdown
-- laat geblokkeerde mappen alsnog tonen wanneer je ze nodig hebt.
--
-- naam = mapnaam (filesystem-naam binnen uploader/), unique key.

CREATE TABLE IF NOT EXISTS `upload_map_blokkades` (
    `naam` VARCHAR(255) NOT NULL,
    `geblokkeerd_op` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `geblokkeerd_door` INT NULL,
    PRIMARY KEY (`naam`),
    KEY `idx_geblokkeerd_op` (`geblokkeerd_op`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
