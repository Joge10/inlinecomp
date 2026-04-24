-- Migration: voeg sportity_kanaal toe aan organisaties
--
-- Elke KNSB-regio-organisatie heeft z'n eigen Sportity-kanaal
-- (bv. ISKREGIO voor KNSB ZH Inline). Dit veld wordt gebruikt in
-- de promotie-poster zodat de disclaimer het juiste kanaal noemt,
-- en kan later ook elders worden ingezet.
--
-- Op bestaande installaties draaien; foutmelding negeren als kolom al bestaat.

ALTER TABLE `organisaties`
    ADD COLUMN `sportity_kanaal` VARCHAR(50) DEFAULT NULL AFTER `email`;
