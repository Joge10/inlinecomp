-- ============================================================
--  Migratie 2026-09-17 — Publieke anonimiteit (variant B)
--
--  Voegt de OMKEERBARE display-maskeer-vlag toe. Een rijder (of ouder) kan
--  publiek anoniem zijn: naam/club/woonplaats worden in de PUBLIEKE laag
--  gemaskeerd (startnummer blijft), maar de data blijft volledig behouden en
--  de interne koppeling (person_id) blijft intact. Volledig backwards-
--  compatible: niets leest de vlag tot de bijbehorende code live is.
--
--  ONDERSCHEID met persons.anonymized_at:
--    anonymized_at   = ONOMKEERBARE AVG-wis ("Verwijderd", data echt weg).
--    publiek_anoniem = OMKEERBARE display-maskering (data blijft), aparte vlag.
--
--  DATETIME (niet bool): legt vast wannéér de vlag is gezet (audit), en NULL =
--  niet anoniem. Zie docs_internal/plan-anonimiteit.md.
--
--  DB: MariaDB 10.11. Additief + herhaalbaar (IF NOT EXISTS).
-- ============================================================

ALTER TABLE `persons`
    ADD COLUMN IF NOT EXISTS `publiek_anoniem` DATETIME DEFAULT NULL AFTER `anonymized_at`;

-- Optioneel index: de leeskant filtert per-rij (niet op deze kolom in WHERE),
-- dus geen index nodig. Toevoegen kan later als profiling erom vraagt.
