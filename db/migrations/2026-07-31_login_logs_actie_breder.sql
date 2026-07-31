-- 2026-07-31 – login_logs: kolom `actie` verbreed van VARCHAR(20) → VARCHAR(60)
--
-- De log kreeg rijkere actie-namen: coach-acties met 'coach-'-prefix
-- (coach-login, coach-logout, coach-login-mislukt, coach-register,
-- coach-reset-aangevraagd, coach-reset) en de beheerders-update-mail
-- (bv. "update-mail H984.31.07"). Die passen niet in 20 tekens.
--
-- MODIFY is veilig meermaals te draaien (idempotent genoeg): het zet de kolom
-- simpelweg (opnieuw) op VARCHAR(60). Geen dataverlies bij verbreden.

ALTER TABLE `login_logs`
    MODIFY COLUMN `actie` VARCHAR(60) NOT NULL DEFAULT 'login';
