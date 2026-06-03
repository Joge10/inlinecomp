-- InlineComp – multi-tenant scoping voor non-owner users
--
-- Junction-tabel: koppelt een users-account aan één of meer organisaties.
-- Semantiek voor scoping in API-endpoints (zie auth/session.php helper
-- `gebruikerOrgScope`):
--
--   owner                    → altijd unscoped (ziet alle wedstrijden).
--   andere rol + 0 entries   → unscoped (backward-compat: huidige users
--                              blijven werken zonder aanpassing).
--   andere rol + ≥1 entries  → scoped: alleen wedstrijden van die orgs
--                              + wedstrijden zonder organisatie_id (legacy,
--                              indien aanwezig — keuze per endpoint).
--
-- ON DELETE CASCADE op beide kanten: user delete of organisatie delete
-- ruimt de junction automatisch op.

CREATE TABLE IF NOT EXISTS `user_organisaties` (
    `user_id`         INT          NOT NULL,
    `organisatie_id`  VARCHAR(36)  NOT NULL,
    `toegevoegd_op`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `toegevoegd_door` INT          DEFAULT NULL,   -- user_id van owner die 'm zette (audit)
    PRIMARY KEY (`user_id`, `organisatie_id`),
    KEY `idx_user`         (`user_id`),
    KEY `idx_organisatie`  (`organisatie_id`),
    CONSTRAINT `fk_uo_user`
        FOREIGN KEY (`user_id`)        REFERENCES `users`        (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uo_organisatie`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uo_toegev_door`
        FOREIGN KEY (`toegevoegd_door`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
