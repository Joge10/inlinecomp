-- InlineComp – entries.reserve + reserve_handmatig_ingezet
--
-- Reserves (zoals via KNSB-feed gemarkeerd) mogen niet automatisch in
-- startlijsten verschijnen — alleen na expliciete actie van de operator.
--
-- `reserve`:
--   NULL  = gewone rijder (of reserve die door operator is ingezet)
--   1..N  = reserve-volgnummer (1e reserve, 2e reserve, …)
--
-- `reserve_handmatig_ingezet`:
--   0  = standaard; KNSB-sync mag entries.reserve overschrijven
--   1  = operator heeft expliciet ingezet → KNSB-sync raakt reserve niet
--        meer (beschermt de NULL-status tegen resync).

ALTER TABLE entries
    ADD COLUMN reserve                   TINYINT UNSIGNED NULL DEFAULT NULL AFTER status,
    ADD COLUMN reserve_handmatig_ingezet TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reserve;
