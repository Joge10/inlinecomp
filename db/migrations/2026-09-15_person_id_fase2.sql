-- ============================================================
--  Migratie 2026-09-15 — FASE 2 van de person_id GUID-migratie
--
--  Voegt `person_id` toe aan alle kindtabellen die nu op person_license /
--  license_key naar een rijder verwijzen, en vult 'm via een JOIN op persons.
--  NOG STEEDS ADDITIEF: de kolom is nullable, er zit geen FK/unique op, en de
--  app-code blijft ongewijzigd op person_license/license_key werken. person_id
--  wordt in fase 3 (code) canoniek; FK's/keys omzetten + person_license droppen
--  gebeurt pas in fase 4. Zie docs_internal/plan-guid-migratie.md.
--
--  Vereist: FASE 1 (persons.person_id) al gedraaid.
--  DB: MariaDB 10.11. Eenmalig; herhaalbaar (IF NOT EXISTS + WHERE person_id IS NULL).
--
--  LET OP — gaten opvangen: rijen die ná deze migratie maar vóór fase-3-deploy
--  worden aangemaakt (imports/loting) krijgen person_id = NULL. De UPDATE's
--  hieronder zijn re-runnable (WHERE person_id IS NULL) → draai ze nogmaals
--  vlak vóór fase 3/4 om die gaten te vullen.
-- ============================================================

-- ── Tabellen met kolom `person_license` ─────────────────────────────────────

-- entries
ALTER TABLE `entries` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `entries` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `entries` ADD KEY IF NOT EXISTS `idx_entries_person_id` (`person_id`);

-- heat_entries
ALTER TABLE `heat_entries` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `heat_entries` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `heat_entries` ADD KEY IF NOT EXISTS `idx_heat_entries_person_id` (`person_id`);

-- transponders
ALTER TABLE `transponders` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `transponders` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `transponders` ADD KEY IF NOT EXISTS `idx_transponders_person_id` (`person_id`);

-- competition_startnummers
ALTER TABLE `competition_startnummers` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `competition_startnummers` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `competition_startnummers` ADD KEY IF NOT EXISTS `idx_csn_person_id` (`person_id`);

-- uitslag_afstand
ALTER TABLE `uitslag_afstand` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `uitslag_afstand` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `uitslag_afstand` ADD KEY IF NOT EXISTS `idx_ua_person_id` (`person_id`);

-- uitslag_klassement
ALTER TABLE `uitslag_klassement` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `uitslag_klassement` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `uitslag_klassement` ADD KEY IF NOT EXISTS `idx_uk_person_id` (`person_id`);

-- coach_athletes
ALTER TABLE `coach_athletes` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `coach_athletes` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `coach_athletes` ADD KEY IF NOT EXISTS `idx_ca_person_id` (`person_id`);

-- organisatie_transponders (person_license nullable, geen FK)
ALTER TABLE `organisatie_transponders` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `organisatie_transponders` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `organisatie_transponders` ADD KEY IF NOT EXISTS `idx_ot_person_id` (`person_id`);

-- push_sub_licenses (person_license VARCHAR(32); stale 32-char waarden matchen
-- niet op persons.license_key VARCHAR(30) → blijven NULL = verouderde subs, ok)
ALTER TABLE `push_sub_licenses` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `person_license`;
UPDATE `push_sub_licenses` c JOIN `persons` p ON p.`license_key` = c.`person_license` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `push_sub_licenses` ADD KEY IF NOT EXISTS `idx_psl_person_id` (`person_id`);

-- ── Tabellen met kolom `license_key` ────────────────────────────────────────

-- klassement_posities (license_key nullable)
ALTER TABLE `klassement_posities` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `license_key`;
UPDATE `klassement_posities` c JOIN `persons` p ON p.`license_key` = c.`license_key` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `klassement_posities` ADD KEY IF NOT EXISTS `idx_kp_person_id` (`person_id`);

-- rijder_profiel (license_key = PK)
ALTER TABLE `rijder_profiel` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `license_key`;
UPDATE `rijder_profiel` c JOIN `persons` p ON p.`license_key` = c.`license_key` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `rijder_profiel` ADD KEY IF NOT EXISTS `idx_rp_person_id` (`person_id`);

-- rijder_profiel_aanvraag (license_key nullable — alleen gezet ná koppelen)
ALTER TABLE `rijder_profiel_aanvraag` ADD COLUMN IF NOT EXISTS `person_id` CHAR(36) DEFAULT NULL AFTER `license_key`;
UPDATE `rijder_profiel_aanvraag` c JOIN `persons` p ON p.`license_key` = c.`license_key` SET c.`person_id` = p.`person_id` WHERE c.`person_id` IS NULL;
ALTER TABLE `rijder_profiel_aanvraag` ADD KEY IF NOT EXISTS `idx_rpa_person_id` (`person_id`);

-- ── NIET in fase 2 (geen kolom): push_subscriptions.licenses /
--    push_outbox.licenses zijn JSON-lijsten van license_keys → worden in fase 3
--    (code) via person_external_ids geresolved; push_outbox is transient.

-- Controle na afloop — per tabel: hoeveel rijen (met license) nog zonder person_id?
-- (verwacht 0, m.u.v. stale/verweesde rijen zoals oude push_sub_licenses)
--   SELECT 'entries' t, COUNT(*) n FROM entries WHERE person_id IS NULL AND person_license IS NOT NULL
--   UNION ALL SELECT 'uitslag_afstand', COUNT(*) FROM uitslag_afstand WHERE person_id IS NULL
--   UNION ALL SELECT 'coach_athletes',  COUNT(*) FROM coach_athletes  WHERE person_id IS NULL;
