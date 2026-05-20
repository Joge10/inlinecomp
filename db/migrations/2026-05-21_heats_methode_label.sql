-- InlineComp – heats.methode_label
--
-- Snapshot van de loting-methode op moment van generatie. Bevat een
-- mensleesbare beschrijving zoals:
--   "Op startnummer"
--   "Alfabetisch"
--   "Op klassement: NK Baan 2026 — 500m Seeding (2026) · sectie DKA"
--   "Tussenklassement van deze wedstrijd (basis: 200m DTT)"
--
-- Wordt opgeslagen per heat (gedupliceerd binnen één loting) zodat de
-- info ook na refresh of vanuit een andere browser/PC te achterhalen
-- blijft, zonder JOIN naar klassementen-tabel of dynamische bepaling.

ALTER TABLE heats
    ADD COLUMN methode_label VARCHAR(255) NULL AFTER methode;
