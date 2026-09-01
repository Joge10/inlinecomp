-- InlineComp – wedstrijden combineren (multi-bron import onder één wedstrijd)
--
-- KNSB splitst soms één evenement in meerdere "wedstrijden" (zelfde org +
-- locatie, per categorie). Deze tabel koppelt 1..N bron-KNSB-wedstrijden aan
-- één doel-wedstrijd; vergelijk.php/import.php loopen daarna over [doel + bronnen]
-- en schrijven alle DC's/rijders onder het doel-competition_id.
--
-- Bewust GEEN FK op bron_competition_id: dat is een KNSB-UUID zonder eigen
-- competitions-rij (het gaat immers op in de doelwedstrijd), net als bij
-- klassement_serie_wedstrijden. FK op het doel wél (met cascade: verdwijnt de
-- doelwedstrijd, dan de koppelingen ook).
--
-- Draai deze migratie één keer in phpMyAdmin.

CREATE TABLE IF NOT EXISTS `competition_bronnen` (
    `doel_competition_id`  VARCHAR(36) NOT NULL,   -- de gecombineerde (doel-)wedstrijd
    `bron_competition_id`  VARCHAR(36) NOT NULL,   -- KNSB-UUID die erin opgaat
    `toegevoegd_op`        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`doel_competition_id`, `bron_competition_id`),
    KEY `idx_cb_doel` (`doel_competition_id`),
    KEY `idx_cb_bron` (`bron_competition_id`),
    CONSTRAINT `fk_cb_doel`
        FOREIGN KEY (`doel_competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
