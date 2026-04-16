-- InlineComp – persons (individuele atleten)
-- PK = license_key (KNSB licentienummer)

CREATE TABLE IF NOT EXISTS `persons` (
    `license_key`  VARCHAR(30)   NOT NULL,
    `full_name`    VARCHAR(255)  NOT NULL,
    `short_name`   VARCHAR(100)  DEFAULT NULL,
    `birth_year`   SMALLINT UNSIGNED DEFAULT NULL,
    `gender`       TINYINT UNSIGNED DEFAULT NULL,       -- 0=man 1=vrouw
    `category`     VARCHAR(20)   DEFAULT NULL,          -- DKA, HKA, DJB …
    `nationality`  VARCHAR(3)    DEFAULT 'NED',
    `start_number` SMALLINT UNSIGNED DEFAULT NULL,
    `club_code`    INT UNSIGNED  DEFAULT NULL,
    `club_short`   VARCHAR(20)   DEFAULT NULL,
    `club_full`    VARCHAR(255)  DEFAULT NULL,
    `city`         VARCHAR(100)  DEFAULT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
