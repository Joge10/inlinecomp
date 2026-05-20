-- =============================================================================
--  Migratie: Area of Call (jury-app)
--
--  Doel:
--    Jury kan vóór elke series-heat de aanwezigheid van rijders aftikken.
--    Bij "Baan op gestuurd" worden afwezigen automatisch DNS-gemarkeerd in
--    de live-uitslag (kolom results.sanctie). Live-module kan dat altijd
--    overrulen — AoC schrijft alleen, claimt niet exclusief.
--
--  Twee wijzigingen:
--    1) Nieuwe tabel area_of_call_aanwezigheid: per heat_entry een status
--       (onbekend / aanwezig / afwezig).
--    2) Nieuwe kolom heats.aoc_sent_at: timestamp van laatste "Baan op
--       gestuurd"-actie. NULL = nog niet verzonden.
--
--  Lock-gedrag: als ten minste één rijder in de heat al een finishpositie
--  heeft in `results`, beschouwt de jury-app de heat als locked (aankomst-
--  jury is begonnen). AoC kan dan alleen lezen, niet meer wijzigen.
-- =============================================================================

CREATE TABLE IF NOT EXISTS area_of_call_aanwezigheid (
    heat_entry_id   INT          NOT NULL PRIMARY KEY,
    status          ENUM('onbekend','aanwezig','afwezig') NOT NULL DEFAULT 'onbekend',
    bijgewerkt_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_aoc_heat_entry
        FOREIGN KEY (heat_entry_id) REFERENCES heat_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE heats
    ADD COLUMN aoc_sent_at TIMESTAMP NULL DEFAULT NULL
        AFTER race_type;
