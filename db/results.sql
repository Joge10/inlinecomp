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
    `tijd_ms`         INT UNSIGNED DEFAULT NULL,         -- officiële tijd (na evt. jury-aanpassing)
    `bruto_tijd_ms`   INT UNSIGNED DEFAULT NULL,         -- origineel gemeten transponder/MyLaps-tijd.
                                                         -- ÉÉN keer gezet bij eerste save, daarna onaangeraakt
                                                         -- (niet door wisseling, niet door handmatige correctie).
                                                         -- Audit-spoor: bruto != tijd_ms → jury heeft 'm aangepast.
    `sanctie`         ENUM('W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF') DEFAULT NULL,
    `ingevoerd_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `rondes`          SMALLINT UNSIGNED DEFAULT NULL,    -- afgeronde ronden (lange afstand)
    `bruto_rondes`    SMALLINT UNSIGNED DEFAULT NULL,    -- origineel gemeten rondes (zie bruto_tijd_ms)
    `punten`          DECIMAL(6,1) DEFAULT NULL,         -- puntenkoers-punten
    `afval_rang`      TINYINT UNSIGNED DEFAULT NULL,     -- afvalkoers: rang van afgevallen rijder (1..N).
                                                         -- NULL = geen afvalkoers, of finish-groep (positie uit tijd).
    `is_photofinish`  TINYINT(1) NOT NULL DEFAULT 0,     -- jury heeft positie via wissel aangepast → tijd is niet
                                                         -- meer 1:1 de transponder-tijd. Visueel signaal in
                                                         -- opvolgende startlijsten; beïnvloedt sortering NIET.
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_result_entry` (`heat_entry_id`),
    CONSTRAINT `fk_result_entry`
        FOREIGN KEY (`heat_entry_id`) REFERENCES `heat_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
