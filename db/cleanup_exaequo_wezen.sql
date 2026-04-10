-- Eenmalige cleanup: verwijder ALLE verweesd ex-aequo ritten en heats
-- Draai dit na de fix van 2026-04-11

-- 1. Verwijder heats die gekoppeld zijn aan ex-aequo ritten
DELETE h FROM heats h
JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
WHERE r.rit_naam LIKE '%ex-aequo%';

-- 2. Verwijder heats met ex-aequo in de naam (met of zonder rit-koppeling)
DELETE FROM heats WHERE heat_naam LIKE '%ex-aequo%';

-- 3. Verwijder heats met heat_nr <= 0 (extra heats van tijdkoppeling)
DELETE FROM heats WHERE heat_nr <= 0;

-- 4. Verwijder de ex-aequo tijdschema_ritten zelf
DELETE FROM tijdschema_ritten WHERE rit_naam LIKE '%ex-aequo%';

-- 5. Verwijder tijdschema_ritten met heat_nr <= 0
DELETE FROM tijdschema_ritten WHERE heat_nr <= 0;
