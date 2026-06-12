-- InlineComp – peak_stats
--
-- Houdt per scope ('public' / 'coach' / 'check') bij hoeveel sessies er tegelijk
-- actief zijn (piek-meting). Wordt bij iedere HTML-pageload bijgewerkt:
--   * peak_today wordt gereset bij een nieuwe dag en opgehoogd als de
--     huidige actieve sessies hoger zijn dan de piek van vandaag
--   * peak_all_time onthoudt de hoogste waarde ooit + wanneer die bereikt is
--
-- Schaalt zonder onderhoud: 2 rijen, 1 kleine UPDATE per pageload.

CREATE TABLE IF NOT EXISTS `peak_stats` (
    `scope`            VARCHAR(10)      NOT NULL PRIMARY KEY,
    `peak_today`       INT UNSIGNED     NOT NULL DEFAULT 0,
    `peak_today_date`  DATE             NULL,
    `peak_all_time`    INT UNSIGNED     NOT NULL DEFAULT 0,
    `peak_all_time_at` DATETIME         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rijen aanmaken als ze nog niet bestaan
INSERT IGNORE INTO `peak_stats` (`scope`) VALUES ('public'), ('coach'), ('check');
