-- InlineComp – coach_accounts (individuele coach-logins, los van `users`)
--
-- Coaches zijn externe vrijwilligers, geen KNSB-staf. Ze krijgen daarom een
-- EIGEN accounttabel — bewust NIET de `users`-tabel. Die is voor staf met
-- schrijfrechten + org-scoping; een coach-rol daarin zou via requireAuth()
-- (zonder rol-argument = "elke ingelogde gebruiker") ongewenst toegang geven
-- tot staf-endpoints. Een coach heeft nul schrijfrechten op wedstrijddata;
-- hij beheert alleen zijn eigen atleten-roster (zie coach_athletes).
--
-- Registratie = self-service. status = 'pending' tot een owner/admin goedkeurt.
-- Zolang pending werkt de coach gewoon met de anonieme per-wedstrijd-lijst;
-- goedkeuring ontgrendelt alleen de account-voordelen (persistente roster +
-- auto-highlight). Niemand wordt dus ooit geblokkeerd in zijn werk.
--
-- Wachtwoorden: bcrypt via password_hash()/password_verify() (zelfde als users).
-- Auto-verval: accounts die >= 1 jaar niet inlogden worden opportunistisch
-- opgeruimd (bij login) — `last_login_at` stuurt dat. ON DELETE CASCADE op de
-- gekoppelde tabellen ruimt sessies/roster/resets mee op.

CREATE TABLE IF NOT EXISTS `coach_accounts` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `email`            VARCHAR(150) NOT NULL,                  -- login-identiteit (uniek, case-insensitive)
    `password_hash`    VARCHAR(255) NOT NULL,
    `naam`             VARCHAR(100) NOT NULL,
    `coacht_van_type`  ENUM('club','team','anders') NOT NULL,  -- bron van de "coach van"-keuze
    `coacht_van`       VARCHAR(255) NOT NULL,                  -- clubnaam / teamnaam / vrij ingevuld
    `status`           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `goedgekeurd_door` INT          DEFAULT NULL,              -- users.id van de beoordelaar
    `goedgekeurd_at`   DATETIME     DEFAULT NULL,
    `actief`           TINYINT(1)   NOT NULL DEFAULT 1,        -- 0 = handmatig gedeactiveerd
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_coach_email`    (`email`),
    KEY        `idx_coach_status`   (`status`),                -- pending-lijst in Beheer
    KEY        `idx_coach_lastlogin`(`last_login_at`),         -- 1-jaar-verval cleanup
    CONSTRAINT `fk_coach_goedgekeurd_door`
        FOREIGN KEY (`goedgekeurd_door`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
