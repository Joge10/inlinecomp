-- Photofinish-marker per result.
-- Wordt gezet door wissel_posities (jury heeft positie aangepast); blijft
-- staan tot de operator de CSV opnieuw importeert + opnieuw saved (dan
-- stuurt JS is_photofinish=0 mee in de payload).
--
-- Geen echte sanctie — beïnvloedt sortering NIET, dient alleen als visueel
-- signaal in opvolgende startlijsten zodat duidelijk is dat de transponder-
-- tijd niet meer overeen komt met de officiële finishvolgorde.
ALTER TABLE `results`
    ADD COLUMN `is_photofinish` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `finishpositie`;
