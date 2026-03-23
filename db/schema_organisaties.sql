-- ============================================================
--  InlineComp – Organisaties & Sponsors
--  Voer uit na schema.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS organisaties (
    id         VARCHAR(36)  NOT NULL,
    naam       VARCHAR(255) NOT NULL,
    website    VARCHAR(500) DEFAULT NULL,
    logo_path  VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_naam (naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organisatie_sponsors (
    id             VARCHAR(36)       NOT NULL,
    organisatie_id VARCHAR(36)       NOT NULL,
    naam           VARCHAR(255)      NOT NULL,
    logo_path      VARCHAR(500)      DEFAULT NULL,
    url            VARCHAR(500)      DEFAULT NULL,
    volgorde       TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_org (organisatie_id),
    FOREIGN KEY (organisatie_id) REFERENCES organisaties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Koppel competitie aan organisatie
ALTER TABLE competitions
    ADD COLUMN IF NOT EXISTS organisatie_id VARCHAR(36) DEFAULT NULL;
