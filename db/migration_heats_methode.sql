-- Migratie: methode + merged dc_ids opslaan in heats tabel
ALTER TABLE heats
    ADD COLUMN methode   VARCHAR(20)  DEFAULT NULL AFTER heat_nr,
    ADD COLUMN dc_ids    TEXT         DEFAULT NULL AFTER methode,
    ADD COLUMN gegenereerd_op DATETIME DEFAULT CURRENT_TIMESTAMP AFTER dc_ids;
