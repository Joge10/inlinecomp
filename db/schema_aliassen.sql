-- ============================================================
--  InlineComp – Organisatie-aliassen
--  Voer uit na schema_organisaties.sql
-- ============================================================

-- Naam-varianten per organisatie (bijv. "Free-wheel", "Free Wheel Inline Cup", …)
-- naam is globaal uniek: dezelfde naam kan niet bij twee orgs horen
CREATE TABLE IF NOT EXISTS organisatie_aliassen (
    id             VARCHAR(36)  NOT NULL,
    organisatie_id VARCHAR(36)  NOT NULL,
    naam           VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alias_naam (naam),
    KEY idx_org (organisatie_id),
    FOREIGN KEY (organisatie_id) REFERENCES organisaties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
