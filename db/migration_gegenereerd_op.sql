-- Migratie: tijdstip laatste programmageneratie bijhouden
ALTER TABLE competition_tijdschema
    ADD COLUMN gegenereerd_op DATETIME DEFAULT NULL;
