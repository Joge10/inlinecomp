-- InlineComp – coach_athletes (de roster: welke rijders volgt een coach)
--
-- Server-side vervanging van de vroegere apparaat-gebonden localStorage-lijst,
-- zodat een coach zijn atleten éénmalig instelt en ze op elk apparaat +
-- automatisch in het wedstrijdoverzicht (.mijn-highlight) verschijnen.
--
-- Eén rij per (coach × rijder). `person_license` verwijst naar persons; elke
-- rijder daarin heeft minstens één wedstrijd gereden, dus de "add-bron" is de
-- hele rijders-DB (al semi-openbaar via startlijsten/uitslagen).
--
-- AVG: deze lijst kan minderjarige atleten bevatten. Opt-in + doelbinding
-- (coach-gemak) + verwijderrecht + 1-jaar-verval dekken dat. CASCADE zorgt dat
-- de roster meeverdwijnt als het account (of een rijder) wordt verwijderd.

CREATE TABLE IF NOT EXISTS `coach_athletes` (
    `coach_account_id` INT         NOT NULL,
    `person_license`   VARCHAR(30) NOT NULL,
    `added_at`         DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`coach_account_id`, `person_license`),
    KEY `idx_ca_person` (`person_license`),
    CONSTRAINT `fk_ca_account`
        FOREIGN KEY (`coach_account_id`) REFERENCES `coach_accounts` (`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_ca_person`
        FOREIGN KEY (`person_license`)   REFERENCES `persons` (`license_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
