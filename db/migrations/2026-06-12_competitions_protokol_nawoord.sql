-- Wedstrijdprotokol: optioneel nawoord-tekst per wedstrijd. Wordt op
-- pagina 2 van het Wedstrijdprotokol-PDF gerenderd (na het voorblad).
-- TEXT NULL → operator kan 'm leeg laten; dan wordt de nawoord-pagina
-- helemaal weggelaten uit het PDF.

ALTER TABLE competitions
    ADD COLUMN protokol_nawoord TEXT DEFAULT NULL AFTER bron;
