-- Migratie: min. rijders per runner-up heat
ALTER TABLE tijdschema_afstand_config
    ADD COLUMN runner_up_min TINYINT UNSIGNED NOT NULL DEFAULT 0
        AFTER runner_up_max;
