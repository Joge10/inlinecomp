-- InlineComp – organisatie_sponsors (sponsors die bij een organisatie horen)
--
-- Worden onder andere getoond in de publieke footer tijdens wedstrijden
-- van deze organisatie. `volgorde` bepaalt de weergave-volgorde.

CREATE TABLE IF NOT EXISTS `organisatie_sponsors` (
    `id`             VARCHAR(36)      NOT NULL,
    `organisatie_id` VARCHAR(36)      NOT NULL,
    `naam`           VARCHAR(255)     NOT NULL,
    `logo_path`      VARCHAR(500)     DEFAULT NULL,
    `url`            VARCHAR(500)     DEFAULT NULL,
    `volgorde`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_org` (`organisatie_id`),
    CONSTRAINT `organisatie_sponsors_ibfk_1`
        FOREIGN KEY (`organisatie_id`) REFERENCES `organisaties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
