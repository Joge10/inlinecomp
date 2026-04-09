-- ============================================================
--  Verwijder FK constraints op uitslag_afstand en uitslag_klassement
--  naar competitions.
--
--  Reden: uitslag-records worden bewaard ook nadat een wedstrijd
--  uit de database is verwijderd (historische inzage, competitie-
--  klassement). competition_naam en competition_datum zijn al
--  gedenormaliseerd opgeslagen in de uitslag-tabellen, dus de FK
--  is niet nodig voor datakwaliteit.
-- ============================================================

ALTER TABLE uitslag_afstand
    DROP FOREIGN KEY fk_ua_competition;

ALTER TABLE uitslag_klassement
    DROP FOREIGN KEY fk_uk_competition;
