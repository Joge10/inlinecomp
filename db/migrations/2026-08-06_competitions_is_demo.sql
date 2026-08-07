-- InlineComp – competitions.is_demo
--
-- Markeert demo/test-wedstrijden. Een demo-wedstrijd is onzichtbaar voor
-- gewone /public- en /coach-bezoekers (net als public_zichtbaar=0), maar wordt
-- WEL getoond wanneer de pagina met de demo-URL (?demo) wordt geopend — dan
-- toont /public + /coach juist ALLEEN de demo-wedstrijden en verbergt de echte.
--
-- Zie public/index.php + coach/index.php: de competitions-list-action en de
-- per-wedstrijd zichtbaarheidsgate lezen $_GET['demo'] en schakelen hierop.

ALTER TABLE competitions
    ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER wizard_dc_gedaan;
