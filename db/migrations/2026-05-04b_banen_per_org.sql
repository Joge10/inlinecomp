-- 2026-05-04b – Banen per organisatie (vervolg op 2026-05-04_add_banen.sql)
--
-- Wijziging in het ontwerp: banen zijn niet langer globaal uniek, maar per
-- organisatie. Dezelfde fysieke baan kan onder meerdere orgs als aparte rij
-- voorkomen, elk met eigen logo + gastheer-vereniging.
--
-- Bestaande banen-rijen (zonder organisatie_id) blijven staan met
-- organisatie_id = NULL. Die kun je daarna in de Beheer-UI aan een
-- organisatie koppelen via SQL of de admin re-creëert ze. Zie comment
-- onderaan voor een handmatige opruim-query.

-- 1) Drop oude globale UNIQUE-key op banen.naam (als die bestaat).
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banen' AND INDEX_NAME = 'uq_baan_naam');
SET @sql = IF(@idx_exists > 0, 'ALTER TABLE banen DROP INDEX uq_baan_naam', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Voeg organisatie_id toe aan banen (NULLable; bestaande rijen krijgen NULL).
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banen' AND COLUMN_NAME = 'organisatie_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE banen
        ADD COLUMN organisatie_id VARCHAR(36) DEFAULT NULL AFTER id,
        ADD KEY idx_org (organisatie_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Foreign-key naar organisaties (als nog niet bestaat).
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'banen'
      AND CONSTRAINT_NAME = 'fk_baan_org');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE banen
        ADD CONSTRAINT fk_baan_org
        FOREIGN KEY (organisatie_id) REFERENCES organisaties (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) Nieuwe UNIQUE op (organisatie_id, naam). MySQL behandelt NULL != NULL
--    in UNIQUE-constraints, dus rijen met organisatie_id = NULL botsen niet.
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banen' AND INDEX_NAME = 'uq_org_naam');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE banen ADD UNIQUE KEY uq_org_naam (organisatie_id, naam)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5) Drop oude globale UNIQUE op baan_aliassen.naam (aliassen zijn nu
--    per-baan uniek via FK; geen globale uniqueness meer nodig).
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'baan_aliassen' AND INDEX_NAME = 'uq_alias_naam');
SET @sql = IF(@idx_exists > 0, 'ALTER TABLE baan_aliassen DROP INDEX uq_alias_naam', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─────────────────────────────────────────────────────────────────────────
-- HANDMATIGE OPRUIM voor bestaande banen-rijen zonder organisatie_id:
--
-- Optie A) Hergebruik: koppel ze aan een specifieke organisatie:
--   UPDATE banen SET organisatie_id = '<UUID-van-org>' WHERE organisatie_id IS NULL;
--
-- Optie B) Vergeet de oude data en begin opnieuw via de UI:
--   DELETE FROM banen WHERE organisatie_id IS NULL;
--
-- Daarna eventueel organisatie_id NOT NULL maken (alleen als alle rijen
-- gevuld zijn — anders faalt de ALTER):
--   ALTER TABLE banen MODIFY organisatie_id VARCHAR(36) NOT NULL;
-- ─────────────────────────────────────────────────────────────────────────
