-- ============================================================
--  InlineComp – Heats, resultaten & klassementen (migratie)
--  Voer uit nádat schema.sql al is uitgevoerd.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Wedstrijd-specifieke startnummers
--   Overschrijft het KNSB-nummer voor deze wedstrijd.
--   Regionale wedstrijden gebruiken eigen nummerreeksen.
--   Zelfde nummer mag voorkomen bij man én vrouw.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competition_startnummers (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    competition_id  VARCHAR(36)   NOT NULL,
    person_license  VARCHAR(30)   NOT NULL,
    startnummer     SMALLINT      UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_csn_comp_person (competition_id, person_license),
    KEY idx_csn_comp (competition_id),
    CONSTRAINT fk_csn_competition
        FOREIGN KEY (competition_id) REFERENCES competitions (id) ON DELETE CASCADE,
    CONSTRAINT fk_csn_person
        FOREIGN KEY (person_license) REFERENCES persons (license_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Heats (ritten)
--   Eén record per rit, bijv.:
--     ronde=1 heat_naam='Tijdrit'        heat_nr=1
--     ronde=1 heat_naam='Heat 2'         heat_nr=2
--     ronde=2 heat_naam='A-finale'       heat_nr=1
--     ronde=2 heat_naam='B-finale'       heat_nr=2
--
--   distance_id: welke afstand wordt gereden (FK naar distances)
--   split_group: NULL = hele DC, anders naam van de splitgroep
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS heats (
    id                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    competition_id          VARCHAR(36)   NOT NULL,
    distance_combination_id VARCHAR(36)   NOT NULL,
    distance_id             VARCHAR(36)   DEFAULT NULL,   -- NULL = afstandsloze rit (b.v. puntenkoers)
    split_group             VARCHAR(50)   DEFAULT NULL,   -- NULL = hele DC
    ronde                   TINYINT       UNSIGNED NOT NULL DEFAULT 1,
    heat_naam               VARCHAR(100)  NOT NULL,       -- 'Tijdrit', 'Heat 3', 'A-finale' …
    heat_nr                 TINYINT       UNSIGNED NOT NULL,
    geplande_starttijd      DATETIME      DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_heat_comp (competition_id),
    KEY idx_heat_dc   (distance_combination_id),
    CONSTRAINT fk_heat_competition
        FOREIGN KEY (competition_id)
        REFERENCES competitions (id) ON DELETE CASCADE,
    CONSTRAINT fk_heat_dc
        FOREIGN KEY (distance_combination_id)
        REFERENCES distance_combinations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Deelnemers per heat  (de eigenlijke startlijst)
--   startpositie : volgorde binnen de heat (1 = eerst aan de start)
--   startnummer  : nummer dat de rijder draagt; wordt gevuld vanuit
--                  competition_startnummers of persons.start_number
--   categorie    : KNSB-categoriecode (DKA, HJB …)
--                  Bij merged DC's zitten meerdere categorieën in 1 heat
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS heat_entries (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    heat_id         INT UNSIGNED  NOT NULL,
    person_license  VARCHAR(30)   NOT NULL,
    categorie       VARCHAR(20)   DEFAULT NULL,
    startpositie    TINYINT       UNSIGNED NOT NULL,
    startnummer     SMALLINT      UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_he_heat_positie (heat_id, startpositie),
    UNIQUE KEY uq_he_heat_person  (heat_id, person_license),
    KEY idx_he_person (person_license),
    CONSTRAINT fk_he_heat
        FOREIGN KEY (heat_id)
        REFERENCES heats (id) ON DELETE CASCADE,
    CONSTRAINT fk_he_person
        FOREIGN KEY (person_license)
        REFERENCES persons (license_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Resultaten per heat-entry
--
--   finishpositie : NULL bij DNS / DNF / DSQ-SF
--   tijd_ms       : gemeten tijd in milliseconden, NULL als geen timing
--   sanctie       : code van de scheidsrechter of jury; NULL = schoon
--
--   Sanctie-logica:
--     DC      → systeem plaatst rijder laatste in overall klassement
--     DSQ-SF  → systeem sluit rijder uit klassement (geen punten)
--     overige → jury past finishpositie handmatig aan; code = notitie
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS results (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    heat_entry_id   INT UNSIGNED  NOT NULL,
    finishpositie   TINYINT       UNSIGNED DEFAULT NULL,
    tijd_ms         INT           UNSIGNED DEFAULT NULL,
    sanctie         ENUM('W1','W2','FS1','DC','RR','DSQ-TF','DSQ-SF','DNS','DNF')
                                  DEFAULT NULL,
    notitie         VARCHAR(255)  DEFAULT NULL,
    ingevoerd_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_result_entry (heat_entry_id),
    CONSTRAINT fk_result_entry
        FOREIGN KEY (heat_entry_id)
        REFERENCES heat_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Puntensysteem per afstand per wedstrijd
--
--   punten_reeks  : JSON-array van positie 1, 2, 3 … tot N
--                   Standaard: [1, 2, 3, 4, 5, …]
--                   Variant:   [0.9, 1.8, 2.7, …]
--                   Fibonacci: [1, 2, 3, 5, 8, 13, …]
--
--   dns_dnf_methode:
--     'vast_99'        → DNS/DNF krijgt altijd 99 punten
--     'laatste_positie'→ DNS/DNF krijgt punten van de laatste startplek
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS point_systems (
    id                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    competition_id          VARCHAR(36)   NOT NULL,
    distance_combination_id VARCHAR(36)   NOT NULL,
    distance_id             VARCHAR(36)   NOT NULL,
    split_group             VARCHAR(50)   DEFAULT NULL,
    punten_reeks            JSON          NOT NULL,
    dns_dnf_methode         ENUM('vast_99','laatste_positie')
                                          NOT NULL DEFAULT 'vast_99',
    PRIMARY KEY (id),
    UNIQUE KEY uq_ps (competition_id, distance_combination_id, distance_id, split_group),
    CONSTRAINT fk_ps_competition
        FOREIGN KEY (competition_id)
        REFERENCES competitions (id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_dc
        FOREIGN KEY (distance_combination_id)
        REFERENCES distance_combinations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Wedstrijd-instellingen  (algemeen, uitbreidbaar)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competition_instellingen (
    competition_id      VARCHAR(36)  NOT NULL,
    dns_dnf_methode     ENUM('vast_99','laatste_positie')
                                     NOT NULL DEFAULT 'vast_99',
    PRIMARY KEY (competition_id),
    CONSTRAINT fk_ci_competition
        FOREIGN KEY (competition_id)
        REFERENCES competitions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
