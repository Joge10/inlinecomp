-- InlineComp – tijdschema_blokken (programma-volgorde)

CREATE TABLE IF NOT EXISTS `tijdschema_blokken` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tijdschema_id` INT UNSIGNED NOT NULL,
    `volgorde`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `blok_type`     ENUM('ronde','pauze','inrijden','wedstrijdstart','ceremonie','herstart')
                                 NOT NULL DEFAULT 'ronde',
    `afstand_naam`  VARCHAR(100) DEFAULT NULL,
    `ronde_type`    ENUM('heats','kwartfinale','halve_finale','runner_up','finale') DEFAULT NULL,
    `duur`          SMALLINT UNSIGNED DEFAULT NULL,
    `inrijd_cats`   TEXT         DEFAULT NULL,
    `tijdstip`      TIME         DEFAULT NULL,
    `opmerking`     VARCHAR(255) DEFAULT NULL,
    `heat_duur`     SMALLINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_tb_schema` (`tijdschema_id`),
    CONSTRAINT `fk_tb_schema`
        FOREIGN KEY (`tijdschema_id`) REFERENCES `competition_tijdschema` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
