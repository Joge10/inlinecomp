-- 2026-05-06 – Public meldingen kunnen ook globaal zijn (zonder wedstrijd).
--
-- Tot nu toe was elke melding gekoppeld aan een specifieke wedstrijd. Met
-- competition_id NULL = "globaal" zien alle bezoekers van public/coach 'm,
-- ook bij de landing-pagina vóór ze een wedstrijd hebben gekozen.
--
-- Op bestaande installaties draaien; foutmeldingen negeren als de FK al
-- weg is of de kolom al nullable.
--
-- Stap 1: drop bestaande FK (die was NOT NULL gebaseerd)
ALTER TABLE `public_meldingen` DROP FOREIGN KEY `fk_meld_comp`;

-- Stap 2: maak kolom nullable
ALTER TABLE `public_meldingen`
    MODIFY `competition_id` VARCHAR(36) NULL DEFAULT NULL;

-- Stap 3: re-add FK (NULL waarden worden niet ge-FK't, dat is SQL-standaard)
ALTER TABLE `public_meldingen`
    ADD CONSTRAINT `fk_meld_comp`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`)
        ON DELETE CASCADE;
