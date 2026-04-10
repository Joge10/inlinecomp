-- Migratie: finale-seeding methode toevoegen
-- Datum: 2026-04-10
-- Reden: ondersteuning voor 200m DTT tijdkoppeling (rulebook Art. 196)

ALTER TABLE tijdschema_afstand_config
    ADD COLUMN finale_seeding ENUM('slang','tijdkoppeling') NOT NULL DEFAULT 'slang'
    AFTER laatste_b_grootste;

ALTER TABLE tijdschema_cat_config
    ADD COLUMN finale_heats TINYINT UNSIGNED NOT NULL DEFAULT 1
    AFTER heeft_runner_up;
