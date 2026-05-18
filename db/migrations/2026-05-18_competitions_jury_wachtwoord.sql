-- ============================================================
--  Jury-wachtwoord per wedstrijd
--
--  VARCHAR(255) is genoeg voor PHP password_hash() output (bcrypt = 60
--  tekens; ruim voor toekomstige stronger hashes). NULL = geen wachtwoord
--  ingesteld → jury-app weigert toegang tot deze wedstrijd.
-- ============================================================

ALTER TABLE competitions
    ADD COLUMN `jury_password` VARCHAR(255) NULL DEFAULT NULL
    AFTER `public_aankondigen`;
