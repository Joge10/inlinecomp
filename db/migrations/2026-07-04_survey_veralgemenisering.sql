-- Survey veralgemeniseren: bruikbaar na elke wedstrijd. URL blijft
-- /survey/oh850/ (extern gedeeld). Drie nieuwe kolommen:
--
-- competition_ids: komma-gescheiden UUID's van wedstrijden waarop de
--                  respondent InlineComp heeft gebruikt (multi-select uit
--                  competitions WHERE public_zichtbaar=1 van huidig +
--                  afgelopen seizoen).
--
-- score_ontwikkeling: 1..5 schaal — hoe vindt de respondent dat InlineComp
--                     zich ontwikkelt sinds vorige keer (1 = slechte kant,
--                     5 = goede kant).
--
-- ontwikkeling_eerste_keer: als 1, is score_ontwikkeling niet zinvol
--                           (respondent gebruikt de app voor het eerst).
--                           Front-end verbergt dan de schaal.
ALTER TABLE `survey_oh850`
    ADD COLUMN `competition_ids`          TEXT             DEFAULT NULL         AFTER `used_unaware`,
    ADD COLUMN `score_ontwikkeling`       TINYINT UNSIGNED DEFAULT NULL         AFTER `score_vergelijking`,
    ADD COLUMN `ontwikkeling_eerste_keer` BOOLEAN          NOT NULL DEFAULT 0   AFTER `score_ontwikkeling`,
    ADD CONSTRAINT `chk_ontwikkeling` CHECK
        (`score_ontwikkeling` IS NULL OR `score_ontwikkeling` BETWEEN 1 AND 5);
