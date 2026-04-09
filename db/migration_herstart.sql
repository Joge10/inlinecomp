-- Wedstrijd herstart blok voor tijdschema_blokken
-- Voeg 'herstart' toe aan het blok_type ENUM
-- Voeg opmerking-veld toe (ook bruikbaar voor andere blokken later)

ALTER TABLE tijdschema_blokken
    MODIFY COLUMN blok_type
        ENUM('ronde','pauze','inrijden','wedstrijdstart','ceremonie','herstart')
        NOT NULL DEFAULT 'ronde';

ALTER TABLE tijdschema_blokken
    ADD COLUMN opmerking VARCHAR(255) DEFAULT NULL AFTER tijdstip;
