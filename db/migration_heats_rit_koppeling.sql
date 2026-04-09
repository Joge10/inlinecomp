-- Koppel heats aan tijdschema_ritten voor correcte nummering en volgorde
ALTER TABLE heats
    ADD COLUMN tijdschema_rit_id INT UNSIGNED DEFAULT NULL AFTER ronde,
    ADD COLUMN rit_volgorde      SMALLINT UNSIGNED DEFAULT NULL AFTER tijdschema_rit_id;
