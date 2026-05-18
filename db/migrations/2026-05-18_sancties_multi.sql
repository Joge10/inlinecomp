-- ============================================================
--  Multi-sancties per rijder per heat
--
--  ENUM(...) → VARCHAR(50) zodat we comma-separated kunnen opslaan.
--  Bestaande enkele waarden (bv. 'DQ-TF') blijven 100% werken als
--  1-item lijst — backwards compatible. Nieuwe invoer kan dus
--  'W1,W2,DQ-SF,FS' zijn, gelezen via sancties_split() helper.
--
--  Validatie van geldige codes verschuift naar applicatie-laag
--  (api/_uitslag_helper.php → sancties_valideer()).
--
--  Volgorde voor schoonheid in display: in de canonieke severity-
--  ranking (DQ-DF, DQ-SF, DQ-TF, DNF, DNS, FS, W2, W1, RR). De
--  applicatie-laag zorgt voor dedup + sort bij opslaan.
-- ============================================================

ALTER TABLE results
    MODIFY `sanctie` VARCHAR(50) NULL DEFAULT NULL;

ALTER TABLE uitslag_afstand
    MODIFY `sanctie` VARCHAR(50) NULL DEFAULT NULL;
