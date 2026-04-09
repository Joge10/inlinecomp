-- Voeg heats_q_heat toe aan tijdschema_cat_config
-- Analoog aan kwart_q_heat en half_q_heat:
-- hoeveel rijders per serie-heat gaan door op positie (Q, directe winnaar)
-- De rest vult aan op tijd (q = heats_q - heats_q_heat * heats_aantal)
-- Default 1 = 1 winnaar per heat

-- Kolom bestaat al, alleen default en bestaande waarden corrigeren naar 0
-- (series zijn altijd q/tijd, nooit Q/positie)
ALTER TABLE tijdschema_cat_config
    ALTER COLUMN heats_q_heat SET DEFAULT 0;

UPDATE tijdschema_cat_config SET heats_q_heat = 0 WHERE heats_q_heat = 1;
