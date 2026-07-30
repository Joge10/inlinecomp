-- InlineComp – coach_sessions (ingelogde coach-sessies, apart van `sessions`)
--
-- Spiegelt de staf-`sessions`-tabel, maar dan voor coach_accounts. Het token
-- staat in de cookie `ic_coach_session` (64 hex tekens, zoals de staf-sessie).
-- Houdbaarheid = 24u vanaf login (bewuste keuze: geen lange sessies). Verlopen
-- rijen worden opportunistisch opgeruimd.
--
-- Let op: goedkeuring van een account raakt de sessie NIET. Een lopende sessie
-- blijft geldig; de account-perks schakelen in zodra status = 'approved' (de
-- coach-view leest de status mee in de live-poll). Zo licht de roster naadloos
-- op als goedkeuring midden in een wedstrijd binnenkomt.

CREATE TABLE IF NOT EXISTS `coach_sessions` (
    `token`            CHAR(64) NOT NULL,                      -- 64 hex, zoals staf-sessies
    `coach_account_id` INT      NOT NULL,
    `expires_at`       DATETIME NOT NULL,                      -- login + 24u
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token`),
    KEY `idx_cs_account` (`coach_account_id`),
    KEY `idx_cs_expires` (`expires_at`),                       -- cleanup verlopen sessies
    CONSTRAINT `fk_cs_account`
        FOREIGN KEY (`coach_account_id`) REFERENCES `coach_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
