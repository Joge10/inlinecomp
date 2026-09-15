-- ============================================================
--  Migratie 2026-09-16 — FASE 3d-prep: person_id RESYNC (drift-heal)
--
--  Doel: elke kindtabel-rij z'n person_id ONVOORWAARDELIJK opnieuw afleiden
--  uit de huidige person_license/license_key (via persons). Dit repareert
--  DRIFT die vóór de fase-3d-codefix kon ontstaan: koppelen/samenvoegen
--  (pending_link / pending_pending_link) en de cluster-check-hertoewijzing
--  verplaatsten person_license wél, maar lieten person_id op de OUDE waarde
--  staan. Zulke rijen hebben person_license = nieuw, person_id = oud (stale) —
--  niet NULL — dus de fase-2-backfill (WHERE person_id IS NULL) ving ze niet.
--
--  Anders dan fase 2: GEEN `WHERE person_id IS NULL`. We herschrijven elke rij
--  die een match op persons heeft naar de correcte person_id (idempotent: zet
--  dezelfde waarde als 'ie al klopte). Verweesde rijen (person_license zonder
--  persons-rij) worden door de JOIN overgeslagen en blijven ongemoeid.
--
--  Vereist: fase 1 + fase 2 al gedraaid. DB: MariaDB 10.11.
--  Veilig + herhaalbaar. Draai dit vlak vóór je op person_id-reads gaat
--  vertrouwen (fase 3d) en nogmaals vlak vóór fase 4.
--
--  COLLATION: kale JOIN op `=` tussen kolommen met verschillende collation
--  geeft #1267 → expliciet COLLATE utf8mb4_unicode_ci op de kind-kolom.
-- ============================================================

-- ── Tabellen met kolom `person_license` ─────────────────────────────────────
UPDATE `entries`                   c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `heat_entries`              c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `transponders`              c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `competition_startnummers`  c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `uitslag_afstand`           c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `uitslag_klassement`        c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `coach_athletes`            c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `organisatie_transponders`  c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `push_sub_licenses`         c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;

-- ── Tabellen met kolom `license_key` ────────────────────────────────────────
UPDATE `klassement_posities`       c JOIN `persons` p ON p.`license_key` = c.`license_key`     COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `rijder_profiel`            c JOIN `persons` p ON p.`license_key` = c.`license_key`     COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;
UPDATE `rijder_profiel_aanvraag`   c JOIN `persons` p ON p.`license_key` = c.`license_key`     COLLATE utf8mb4_unicode_ci SET c.`person_id` = p.`person_id`;

-- ── Controle: staat er nog drift? (verwacht 0 op alle regels) ───────────────
-- Rijen waar person_id afwijkt van wat person_license/license_key zou opleveren.
--   SELECT 'entries' t, COUNT(*) n FROM entries c JOIN persons p
--       ON p.license_key = c.person_license COLLATE utf8mb4_unicode_ci
--     WHERE c.person_id <> p.person_id COLLATE utf8mb4_unicode_ci
--   UNION ALL SELECT 'heat_entries', COUNT(*) FROM heat_entries c JOIN persons p
--       ON p.license_key = c.person_license COLLATE utf8mb4_unicode_ci
--     WHERE c.person_id <> p.person_id COLLATE utf8mb4_unicode_ci
--   UNION ALL SELECT 'uitslag_afstand', COUNT(*) FROM uitslag_afstand c JOIN persons p
--       ON p.license_key = c.person_license COLLATE utf8mb4_unicode_ci
--     WHERE c.person_id <> p.person_id COLLATE utf8mb4_unicode_ci;
