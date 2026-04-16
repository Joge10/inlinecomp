-- InlineComp – competition_instellingen

CREATE TABLE IF NOT EXISTS `competition_instellingen` (
    `competition_id`   VARCHAR(36)  NOT NULL,
    `dns_dnf_methode`  ENUM('vast_99','laatste_positie') NOT NULL DEFAULT 'vast_99',
    PRIMARY KEY (`competition_id`),
    CONSTRAINT `fk_ci_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
