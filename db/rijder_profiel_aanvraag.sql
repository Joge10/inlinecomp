-- ============================================================
--  rijder_profiel_aanvraag — openstaande aanvragen voor een
--  persoonlijk "Mijn InlineComp"-profiel.
--
--  Flow (zelfde systematiek als coach-accounts):
--   1. Rijder vult het aanvraagformulier in op /check/profiel.php
--      → hier komt een rij bij met status 'pending'.
--   2. Rijder krijgt een "in afwachting van goedkeuring"-mail
--      (organisatie in Cc, zodat die weet dat er een aanvraag is).
--   3. Beheerder koppelt in Systeem → Rijders de juiste rijder
--      (license_key) + gebruikersnaam en keurt goed → er wordt een
--      claim-link gemaakt (rijder_profiel) en gemaild (organisatie in Cc).
--      Status → 'approved'.  Afwijzen → 'rejected'.
--   4. Na verwerken (goedkeuren óf afwijzen) wordt het e-mailadres
--      GEWIST (op NULL gezet) — AVG-dataminimalisatie.
--
--  De e-mail staat dus alléén tussen aanvraag en verwerking in de DB.
-- ============================================================
CREATE TABLE IF NOT EXISTS `rijder_profiel_aanvraag` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `naam`              VARCHAR(120)  NOT NULL,                 -- door de aanvrager opgegeven
    `startnummer`       VARCHAR(20)   DEFAULT NULL,             -- optioneel, helpt bij koppelen
    `email`             VARCHAR(150)  DEFAULT NULL,             -- TIJDELIJK; NULL na verwerken (AVG)
    `gewenste_username` VARCHAR(40)   DEFAULT NULL,             -- voorkeur; beheerder bevestigt
    `opmerking`         VARCHAR(500)  DEFAULT NULL,
    `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `license_key`       VARCHAR(30)   DEFAULT NULL,             -- door beheerder gekoppeld bij goedkeuring
    `behandeld_door`    INT           DEFAULT NULL,             -- users.id van de beoordelaar
    `behandeld_at`      DATETIME      DEFAULT NULL,
    `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rpa_status`  (`status`),                           -- pending-lijst in Beheer
    KEY `idx_rpa_created` (`created_at`),
    KEY `idx_rpa_license` (`license_key`),
    CONSTRAINT `fk_rpa_license`
        FOREIGN KEY (`license_key`) REFERENCES `persons` (`license_key`) ON DELETE SET NULL,
    CONSTRAINT `fk_rpa_behandeld_door`
        FOREIGN KEY (`behandeld_door`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
