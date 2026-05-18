-- ============================================================
--  Multi-day support: datum-veld per tijdschema-blok
--
--  Vooral relevant voor wedstrijdstart-blokken bij meerdaagse evenementen
--  (NK over 3 dagen). Dag-naam wordt automatisch "Dag 1 / 2 / 3" op basis
--  van volgorde; datum-veld voegt de echte kalenderdatum eraan toe zodat
--  programma-print en publiek/coach-weergave de juiste dag tonen.
--
--  NULL = geen specifieke datum (= meegerend met competitions.starts)
-- ============================================================

ALTER TABLE tijdschema_blokken
    ADD COLUMN `datum` DATE NULL DEFAULT NULL
    AFTER `tijdstip`;
