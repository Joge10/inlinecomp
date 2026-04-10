-- Migratie: verwijder 'internationaal-oud' als competitiesysteem
-- Datum: 2026-04-10
-- Reden: systeem was niet meer selecteerbaar in de UI, dode code opgeruimd

ALTER TABLE competition_tijdschema
    MODIFY COLUMN systeem ENUM('full-final','internationaal-nieuw')
    NOT NULL DEFAULT 'full-final';
