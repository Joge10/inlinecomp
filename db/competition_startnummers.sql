-- InlineComp – competition_startnummers (override op persons.start_number)

CREATE TABLE IF NOT EXISTS `competition_startnummers` (
    `id`              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `competition_id`  VARCHAR(36)    NOT NULL,
    `person_license`  VARCHAR(30)    NOT NULL,
    `startnummer`     SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_csn_comp_person` (`competition_id`, `person_license`),
    KEY `idx_csn_comp` (`competition_id`),
    KEY `fk_csn_person` (`person_license`),
    CONSTRAINT `fk_csn_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_csn_person`
        FOREIGN KEY (`person_license`) REFERENCES `persons` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
