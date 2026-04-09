-- Corrigeer 0-gebaseerde volgorde in tijdschema_ritten naar 1-gebaseerd.
-- Oorzaak: herorden_ritten (drag-and-drop) gebruikte i in plaats van i+1.
-- Alleen tijdschema's waarbij MIN(volgorde) = 0 worden aangepast (= 0-based data).
-- Tijdschema's die al bij 1 beginnen blijven ongewijzigd.

UPDATE tijdschema_ritten r
INNER JOIN (
    SELECT tijdschema_id
    FROM tijdschema_ritten
    GROUP BY tijdschema_id
    HAVING MIN(volgorde) = 0
) AS oude_ts ON r.tijdschema_id = oude_ts.tijdschema_id
SET r.volgorde = r.volgorde + 1;

-- Synchroniseer heats.rit_volgorde met de gecorrigeerde tijdschema_ritten.
-- Heats zonder tijdschema-koppeling blijven ongewijzigd.

UPDATE heats h
INNER JOIN tijdschema_ritten r ON h.tijdschema_rit_id = r.id
SET h.rit_volgorde = r.volgorde
WHERE h.tijdschema_rit_id IS NOT NULL;
