-- Tijdschema-wizard Deel 1: vlag "DC's samenstellen voltooid".
--
-- 0 = nog niet gedraaid → de wizard toont alle categorieën los in de "bak"
--     (verse wedstrijd). Feed-gecombineerde categorieën worden getoond met een
--     stippellijntje, maar staan los.
-- 1 = gedraaid → de huidige DC's + merge_group + dc_splits ZIJN de groepen; de
--     wizard reconstrueert de indeling daaruit en de bak is leeg.
--
-- Waarom een vlag i.p.v. reconstructie-uit-data alleen: een per-categorie-feed
-- die als allemaal solo-groepen wordt ingedeeld levert geen merge_group en geen
-- dc_splits op → dan is "al ingedeeld" niet aan de data te zien. De vlag maakt
-- dat onderscheid. Regel in de wizard: je kunt pas opslaan als élke categorie
-- (ook 0-deelnemers) in een groep zit, zodat een late aanmelder op de dag
-- altijd een startlijst heeft om in te vallen.

ALTER TABLE `competitions`
    ADD COLUMN `wizard_dc_gedaan` TINYINT(1) NOT NULL DEFAULT 0 AFTER `bron`;
