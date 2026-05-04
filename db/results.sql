-- InlineComp – results (resultaten per heat-entry)
--
-- Sanctie-logica:
--   DQ-TF, DNS, DNF → ranked last in round
--   DQ-SF, DQ-DF   → not ranked, no points
--   W1, W2, FS, RR → geen automatisch effect (jury past manueel aan)

CREATE TABLE IF NOT EXISTS `results` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `heat_entry_id`   INT UNSIGNED NOT NULL,
    `finishpositie`   TINYINT UNSIGNED DEFAULT NULL,
    `tijd_ms`         INT UNSIGNED DEFAULT NULL,
    `sanctie`         ENUM('W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF') DEFAULT NULL,
    `ingevoerd_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `rondes`          SMALLINT UNSIGNED DEFAULT NULL,    -- afgeronde ronden (lange afstand)
    `punten`          DECIMAL(6,1) DEFAULT NULL,         -- puntenkoers-punten
    `afval_rang`      TINYINT UNSIGNED DEFAULT NULL,     -- afvalkoers: rang van afgevallen rijder (1..N).
                                                         -- NULL = geen afvalkoers, of finish-groep (positie uit tijd).
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_result_entry` (`heat_entry_id`),
    CONSTRAINT `fk_result_entry`
        FOREIGN KEY (`heat_entry_id`) REFERENCES `heat_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
