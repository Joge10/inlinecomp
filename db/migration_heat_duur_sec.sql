-- Migratie: heat_duur van minuten naar seconden
-- TINYINT (max 255) → SMALLINT (max 65535)
-- Bestaande waarden (in minuten) × 60 omzetten naar seconden

ALTER TABLE tijdschema_blokken
    MODIFY COLUMN heat_duur SMALLINT UNSIGNED DEFAULT NULL;

UPDATE tijdschema_blokken
    SET heat_duur = heat_duur * 60
    WHERE heat_duur IS NOT NULL;
