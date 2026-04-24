-- InlineComp – users (InlineComp-beheerders)
--
-- Rollen (oplopend in bevoegdheid):
--   viewer   — alleen-lezen
--   timer    — tijden invoeren tijdens live
--   planner  — startlijsten en tijdschema
--   importer — KNSB-imports draaien
--   admin    — alles behalve gebruikersbeheer (wachtwoorden resetten, nieuwe accounts)
--   owner   — alles
--
-- Wachtwoorden worden opgeslagen als bcrypt-hash via password_hash()/password_verify().

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(50)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `naam`          VARCHAR(100) NOT NULL DEFAULT '',
    `email`         VARCHAR(150) DEFAULT NULL,
    `role`          ENUM('owner','admin','importer','planner','timer','viewer') NOT NULL DEFAULT 'viewer',
    `actief`        TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
