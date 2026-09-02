-- InlineComp – distance_combinations (categorieën per wedstrijd)

CREATE TABLE IF NOT EXISTS `distance_combinations` (
    `id`              VARCHAR(36)  NOT NULL,             -- KNSB DC UUID
    `competition_id`  VARCHAR(36)  NOT NULL,
    `number`          TINYINT UNSIGNED DEFAULT NULL,
    `name`            VARCHAR(255) DEFAULT NULL,
    `category_filter` VARCHAR(20)  DEFAULT NULL,
    `merge_group`     VARCHAR(50)  DEFAULT NULL,
    `merge_label`     VARCHAR(80)  DEFAULT NULL,
    -- Handmatige override voor max rijders in loting. NULL = auto
    -- (= aantal niet-reserves uit KNSB-feed). Gezet = die waarde wint
    -- voor de capaciteit-cap in het reserve-paneel.
    `max_in_loting`   INT UNSIGNED DEFAULT NULL,
    -- 1 zodra de afstanden van deze DC handmatig zijn samengesteld (wizard/beheer).
    -- import.php laat de afstanden dan met rust bij een her-import (geen feed-
    -- afstanden terugzetten die de user had verwijderd/vervangen).
    `afstanden_handmatig` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_dc_competition` (`competition_id`),
    CONSTRAINT `fk_dc_competition`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
