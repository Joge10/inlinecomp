-- InlineComp – Klassementen (KNSB PDF import voor seeding)
-- Uitvoeren: eenmalig via phpMyAdmin of MySQL CLI

CREATE TABLE IF NOT EXISTS klassementen (
    id              VARCHAR(36)  NOT NULL PRIMARY KEY,
    naam            VARCHAR(255) NOT NULL,
    seizoen         VARCHAR(20)  DEFAULT NULL,
    bron_bestand    VARCHAR(255) DEFAULT NULL,
    categorieen     JSON         DEFAULT NULL,   -- ['HSA','HJA',...]
    totaal_rijders  INT          NOT NULL DEFAULT 0,
    aangemaakt_op   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS klassement_posities (
    id              VARCHAR(16)  NOT NULL PRIMARY KEY,
    klassement_id   VARCHAR(36)  NOT NULL,
    positie         INT          NOT NULL,
    start_number    VARCHAR(20)  DEFAULT NULL,
    naam            VARCHAR(255) NOT NULL,
    categorie       VARCHAR(20)  DEFAULT NULL,
    FOREIGN KEY (klassement_id) REFERENCES klassementen(id) ON DELETE CASCADE,
    INDEX idx_kl_id (klassement_id),
    INDEX idx_kl_cat (klassement_id, categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
