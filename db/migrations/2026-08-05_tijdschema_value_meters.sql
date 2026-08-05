-- ============================================================================
--  Afstand-identiteit voor het tijdschema: NAAM + METERS i.p.v. naam alleen.
--
--  Waarom: tijdschema_blokken, tijdschema_afstand_config en de ritten-generatie
--  koppelen afstanden op afstand_naam ZONDER meters. Daardoor vallen twee
--  gelijk-genoemde afstanden met verschillende lengtes samen — bv. "Sprint"
--  300m/500m, of "Afvalkoers" 1200/2400/4000/5000. Dat is een latente bug:
--  het worden dan één blok i.p.v. twee, en genereerRitten matcht op álle
--  distances met die naam. Deze migratie voegt value_meters toe zodat de code
--  op (naam + meters) kan koppelen.
--
--  BACKWARD-COMPATIBLE: value_meters mag NULL blijven. De code valt bij NULL
--  terug op naam-only matching, dus bestaande wedstrijden gedragen zich exact
--  als voorheen. De backfill vult value_meters alleen waar de naam binnen die
--  wedstrijd EENDUIDIG is (precies één meters-waarde); bij dubbelzinnige namen
--  blijft NULL staan tot het tijdschema opnieuw wordt gegenereerd.
--
--  Handmatig draaien (de app-DB-user heeft geen DDL-rechten).
-- ============================================================================

ALTER TABLE `tijdschema_blokken`
    ADD COLUMN `value_meters` INT DEFAULT NULL AFTER `afstand_naam`;

ALTER TABLE `tijdschema_afstand_config`
    ADD COLUMN `value_meters` INT DEFAULT NULL AFTER `afstand_naam`;

-- UNIQUE-sleutel van afstand_config uitbreiden met value_meters, zodat
-- "Sprint" 300m en 500m twee losse config-rijen kunnen zijn.
ALTER TABLE `tijdschema_afstand_config`
    DROP INDEX `uq_tac`,
    ADD UNIQUE KEY `uq_tac` (`tijdschema_id`, `dc_id`, `afstand_naam`, `value_meters`);

-- ── Backfill (alleen eenduidige namen) ──────────────────────────────────────
-- Per wedstrijd de afstand-namen die precies één meters-waarde hebben.
UPDATE `tijdschema_blokken` b
JOIN `competition_tijdschema` ct ON ct.id = b.tijdschema_id
JOIN (
    SELECT dc.competition_id, d.name,
           MIN(d.value_meters)                        AS meters,
           COUNT(DISTINCT COALESCE(d.value_meters,-1)) AS n
    FROM distances d
    JOIN distance_combinations dc ON dc.id = d.distance_combination_id
    GROUP BY dc.competition_id, d.name
    HAVING n = 1
) u ON u.competition_id = ct.competition_id AND u.name = b.afstand_naam
SET b.value_meters = u.meters
WHERE b.blok_type = 'ronde' AND b.value_meters IS NULL;

UPDATE `tijdschema_afstand_config` ac
JOIN `competition_tijdschema` ct ON ct.id = ac.tijdschema_id
JOIN (
    SELECT dc.competition_id, d.name,
           MIN(d.value_meters)                        AS meters,
           COUNT(DISTINCT COALESCE(d.value_meters,-1)) AS n
    FROM distances d
    JOIN distance_combinations dc ON dc.id = d.distance_combination_id
    GROUP BY dc.competition_id, d.name
    HAVING n = 1
) u ON u.competition_id = ct.competition_id AND u.name = ac.afstand_naam
SET ac.value_meters = u.meters
WHERE ac.value_meters IS NULL;
