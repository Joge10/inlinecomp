-- 2026-07-30 – login_logs: kolom `bron` om staf- van coach-logins te scheiden
--
-- Coach-accounts (coach_accounts) loggen hun login/logout/mislukt óók in
-- login_logs, maar met user_id = NULL (de bestaande FK verwijst naar `users`;
-- coaches staan daar niet in — dat blijft dus geldig). De nieuwe `bron`-kolom
-- onderscheidt de twee, zodat het logboek in Beheer op staf/coach kan filteren.
-- Bestaande rijen krijgen 'staff' (default).
--
-- Idempotent: draait veilig meerdere keren (voegt de kolom alleen toe als 'ie
-- nog niet bestaat).

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'login_logs'
      AND COLUMN_NAME  = 'bron');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE login_logs ADD COLUMN bron VARCHAR(10) NOT NULL DEFAULT 'staff' AFTER actie",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
