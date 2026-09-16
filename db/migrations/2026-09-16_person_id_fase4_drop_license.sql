-- ============================================================
--  Migratie 2026-09-16 — FASE 4 van de person_id GUID-migratie
--                        (variant A: license_key gaat WEG)
--
--  ⚠️  ONOMKEERBAAR. MAAK EERST EEN VOLLEDIGE DB-BACKUP.  ⚠️
--  ⚠️  DEPLOY EERST DE FASE-4 CODE (zie onderaan) — DEZE MIGRATIE MOET
--      IN LOCKSTEP MET DIE CODE. Draait test+prod op dezelfde DB, dus na
--      deze migratie werkt oude code (die person_license/license_key nog
--      schrijft of leest) NIET meer. Doe dit in een onderhoudsvenster.
--
--  Wat gebeurt hier:
--    persons.person_id (CHAR(36) GUID) wordt de canonieke PRIMARY KEY.
--    De kolom persons.license_key verdwijnt — de KNSB-licentie (en alle
--    andere externe sleutels) leven vanaf nu ALLEEN nog in
--    person_external_ids(person_id, systeem, extern_id).
--    Alle kindtabellen verliezen hun person_license / license_key-kolom;
--    hun unieke sleutels, indexen en foreign keys draaien naar person_id.
--
--  Vereist (in deze volgorde al gedraaid):
--    fase 1  (persons.person_id + person_external_ids + 1c-mirror)
--    fase 2  (person_id op alle kindtabellen, backfilled)
--    fase 3  (alle app-code leest/schrijft person_id; dual-write actief)
--    2026-09-16_person_id_collation_align.sql  (alle person_id = general_ci)
--    2026-09-16_person_id_*_relabel.sql        (external-id-labels schoon)
--    2026-09-16_person_id_fase3d_resync.sql    (drift geheeld)
--
--  DB: MariaDB 10.11.
--  NIET herhaalbaar: na afloop bestaan de license-kolommen niet meer, dus
--  een tweede run faalt op de eerste ALTER (dat is OK — teken dat 'ie al liep).
-- ============================================================


-- ════════════════════════════════════════════════════════════════════════
--  STAP 0 — PRE-FLIGHT GUARDS (handmatig draaien; ALLES moet 0 zijn)
-- ════════════════════════════════════════════════════════════════════════
--  Als één van deze > 0 is: NIET verder. Eerst oplossen (resync draaien of
--  de wees-rij handmatig koppelen/verwijderen), anders faalt de NOT NULL /
--  FK-stap of verlies je een rij.
--
--  (a) Elke persoon heeft z'n externe-id-mapping (KNSB-licentie geborgd):
--      SELECT COUNT(*) FROM persons p
--        LEFT JOIN person_external_ids e ON e.person_id = p.person_id
--       WHERE e.person_id IS NULL;                              -- verwacht 0
--
--  (b) Geen kind-rij met een person_license/license_key MAAR zonder person_id
--      (= wees; zou na fase-3d-resync 0 moeten zijn). Voorbeeld entries:
--      SELECT 'entries' t, COUNT(*) n FROM entries
--        WHERE person_id IS NULL AND person_license IS NOT NULL
--      UNION ALL SELECT 'heat_entries', COUNT(*) FROM heat_entries
--        WHERE person_id IS NULL AND person_license IS NOT NULL
--      UNION ALL SELECT 'transponders', COUNT(*) FROM transponders
--        WHERE person_id IS NULL AND person_license IS NOT NULL
--      UNION ALL SELECT 'competition_startnummers', COUNT(*) FROM competition_startnummers
--        WHERE person_id IS NULL AND person_license IS NOT NULL
--      UNION ALL SELECT 'uitslag_afstand', COUNT(*) FROM uitslag_afstand
--        WHERE person_id IS NULL AND person_license IS NOT NULL
--      UNION ALL SELECT 'uitslag_klassement', COUNT(*) FROM uitslag_klassement
--        WHERE person_id IS NULL AND person_license IS NOT NULL
--      UNION ALL SELECT 'coach_athletes', COUNT(*) FROM coach_athletes
--        WHERE person_id IS NULL AND person_license IS NOT NULL
--      UNION ALL SELECT 'rijder_profiel', COUNT(*) FROM rijder_profiel
--        WHERE person_id IS NULL AND license_key IS NOT NULL;    -- alle 0
--
--  push_sub_licenses: stale subs (person_license zonder persons-match) mogen
--  person_id NULL houden — die worden in STAP 5 verwijderd (verouderde push).


-- ════════════════════════════════════════════════════════════════════════
--  STAP 1 — VEILIGHEIDSNET: mirror + resync nog één keer
-- ════════════════════════════════════════════════════════════════════════
--  Borg elke (nog) niet-gemapte license in person_external_ids, en her-leid
--  alle kind-person_id's uit de huidige license (idempotent). Zo gaat er bij
--  het droppen van license_key gegarandeerd geen externe sleutel verloren en
--  is er geen drift meer.

INSERT INTO `person_external_ids` (`person_id`, `systeem`, `extern_id`)
SELECT `person_id`,
       CASE WHEN `license_key` LIKE 'x-%'        THEN 'ic-extern'
            WHEN `license_key` LIKE 'p-%'        THEN 'ic-pending'
            WHEN `license_key` LIKE 'demo-%'     THEN 'ic-demo'
            WHEN `license_key` LIKE 'manual\_%'  THEN 'ic-manual'
            WHEN `license_key` LIKE '%\_Anoniem' THEN 'ic-anoniem'
            ELSE 'knsb' END,
       `license_key`
FROM `persons`
ON DUPLICATE KEY UPDATE `person_id` = VALUES(`person_id`);

UPDATE `entries`                  c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `heat_entries`             c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `transponders`             c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `competition_startnummers` c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `uitslag_afstand`          c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `uitslag_klassement`       c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `coach_athletes`           c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `organisatie_transponders` c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `push_sub_licenses`        c JOIN `persons` p ON p.`license_key` = c.`person_license` COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `klassement_posities`      c JOIN `persons` p ON p.`license_key` = c.`license_key`    COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `rijder_profiel`           c JOIN `persons` p ON p.`license_key` = c.`license_key`    COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;
UPDATE `rijder_profiel_aanvraag`  c JOIN `persons` p ON p.`license_key` = c.`license_key`    COLLATE utf8mb4_general_ci SET c.`person_id` = p.`person_id`;


-- ════════════════════════════════════════════════════════════════════════
--  STAP 2 — DROP alle FOREIGN KEYS die naar persons(license_key) wijzen
-- ════════════════════════════════════════════════════════════════════════
--  Moet vóór het wisselen van de persons-PK én vóór het droppen van de
--  license-kolommen. (MariaDB: DROP FOREIGN KEY IF EXISTS bestaat.)

ALTER TABLE `entries`                  DROP FOREIGN KEY IF EXISTS `fk_entry_person`;
ALTER TABLE `heat_entries`             DROP FOREIGN KEY IF EXISTS `fk_he_person`;
ALTER TABLE `transponders`             DROP FOREIGN KEY IF EXISTS `fk_tp_person`;
ALTER TABLE `competition_startnummers` DROP FOREIGN KEY IF EXISTS `fk_csn_person`;
ALTER TABLE `uitslag_afstand`          DROP FOREIGN KEY IF EXISTS `fk_ua_person`;
ALTER TABLE `uitslag_klassement`       DROP FOREIGN KEY IF EXISTS `fk_uk_person`;
ALTER TABLE `coach_athletes`           DROP FOREIGN KEY IF EXISTS `fk_ca_person`;
ALTER TABLE `rijder_profiel`           DROP FOREIGN KEY IF EXISTS `fk_rp_person`;
ALTER TABLE `rijder_profiel_aanvraag`  DROP FOREIGN KEY IF EXISTS `fk_rpa_license`;


-- ════════════════════════════════════════════════════════════════════════
--  STAP 3 — persons: person_id wordt PRIMARY KEY, license_key verdwijnt
-- ════════════════════════════════════════════════════════════════════════
--  1) collation vastzetten (moet gelijk zijn aan de kind-person_id's voor de
--     nieuwe FK's) + PK omzetten. uq_persons_person_id wordt overbodig zodra
--     person_id PK is → weg.
ALTER TABLE `persons`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT (UUID()),
    DROP PRIMARY KEY,
    DROP KEY `uq_persons_person_id`,
    ADD PRIMARY KEY (`person_id`);

--  2) license_key-kolom weg. De KNSB-licentie zit veilig in
--     person_external_ids(systeem='knsb'); pending/extern/demo/manual/anoniem
--     idem onder hun ic-*-label.
ALTER TABLE `persons` DROP COLUMN `license_key`;


-- ════════════════════════════════════════════════════════════════════════
--  STAP 4 — kindtabellen MET person_license: keys→person_id, kolom weg, FK terug
-- ════════════════════════════════════════════════════════════════════════
--  Patroon per tabel:
--    a) person_id NOT NULL + general_ci (waar de kolom verplicht is)
--    b) oude UNIQUE/PK op person_license droppen
--    c) person_license-kolom droppen (single-col index idx_*_person valt mee weg)
--    d) nieuwe UNIQUE/PK op person_id
--    e) FK (person_id) → persons(person_id), met hetzelfde ON DELETE-gedrag

-- entries  (uq_entry, idx_entry_person, fk RESTRICT)
ALTER TABLE `entries`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP KEY `uq_entry`,
    DROP COLUMN `person_license`,
    ADD UNIQUE KEY `uq_entry` (`distance_combination_id`, `person_id`),
    ADD CONSTRAINT `fk_entry_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`);

-- heat_entries  (uq_he_heat_person, idx_he_person, fk RESTRICT)
ALTER TABLE `heat_entries`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP KEY `uq_he_heat_person`,
    DROP COLUMN `person_license`,
    ADD UNIQUE KEY `uq_he_heat_person` (`heat_id`, `person_id`),
    ADD CONSTRAINT `fk_he_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`);

-- transponders  (uq_transponder, fk RESTRICT)
ALTER TABLE `transponders`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP KEY `uq_transponder`,
    DROP COLUMN `person_license`,
    ADD UNIQUE KEY `uq_transponder` (`person_id`, `competition_id`, `slot`),
    ADD CONSTRAINT `fk_tp_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`);

-- competition_startnummers  (uq_csn_comp_person, fk_csn_person-index, fk RESTRICT)
ALTER TABLE `competition_startnummers`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP KEY `uq_csn_comp_person`,
    DROP COLUMN `person_license`,
    ADD UNIQUE KEY `uq_csn_comp_person` (`competition_id`, `person_id`),
    ADD CONSTRAINT `fk_csn_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`);

-- uitslag_afstand  (uq_ua_kern, idx_ua_person, fk RESTRICT)
ALTER TABLE `uitslag_afstand`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP KEY `uq_ua_kern`,
    DROP COLUMN `person_license`,
    ADD UNIQUE KEY `uq_ua_kern` (`competition_id`, `distance_combination_id`, `distance_id`, `split_group`, `person_id`),
    ADD CONSTRAINT `fk_ua_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`);

-- uitslag_klassement  (uq_uk_kern, idx_uk_person, fk RESTRICT)
ALTER TABLE `uitslag_klassement`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP KEY `uq_uk_kern`,
    DROP COLUMN `person_license`,
    ADD UNIQUE KEY `uq_uk_kern` (`competition_id`, `distance_combination_id`, `split_group`, `person_id`),
    ADD CONSTRAINT `fk_uk_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`);

-- coach_athletes  (PK = coach_account_id+person_license, idx_ca_person, fk CASCADE)
ALTER TABLE `coach_athletes`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP PRIMARY KEY,
    DROP COLUMN `person_license`,
    ADD PRIMARY KEY (`coach_account_id`, `person_id`),
    ADD CONSTRAINT `fk_ca_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`) ON DELETE CASCADE;

-- organisatie_transponders  (person_license NULLABLE, GEEN persons-FK → laten
--   we zo; alleen kolom + index weg. person_id blijft nullable = onbezette TP.)
ALTER TABLE `organisatie_transponders`
    DROP COLUMN `person_license`;

-- push_sub_licenses  (PK = subscription_id+person_license, GEEN persons-FK)
--   Stale subs (verouderde license, nooit op persons gematcht) hebben person_id
--   NULL → verwijderen; anders kan person_id niet in de PK. Dat zijn dode
--   push-abonnementen op onbekende licenties; verlies is bedoeld.
DELETE FROM `push_sub_licenses` WHERE `person_id` IS NULL;
ALTER TABLE `push_sub_licenses`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP PRIMARY KEY,
    DROP COLUMN `person_license`,
    ADD PRIMARY KEY (`subscription_id`, `person_id`);


-- ════════════════════════════════════════════════════════════════════════
--  STAP 5 — kindtabellen MET license_key: kolom weg, keys→person_id
-- ════════════════════════════════════════════════════════════════════════

-- klassement_posities  (license_key NULLABLE, GEEN persons-FK, idx_kp_license)
--   person_id blijft nullable (historische PDF-rijen zonder match mogen blijven).
ALTER TABLE `klassement_posities`
    DROP COLUMN `license_key`;

-- rijder_profiel  (PK = license_key, fk_rp_person CASCADE, idx_rp_person_id bestaat)
ALTER TABLE `rijder_profiel`
    MODIFY `person_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    DROP PRIMARY KEY,
    DROP COLUMN `license_key`,
    ADD PRIMARY KEY (`person_id`),
    ADD CONSTRAINT `fk_rp_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`) ON DELETE CASCADE;

-- rijder_profiel_aanvraag  (license_key NULLABLE SET NULL, idx_rpa_license)
--   person_id blijft nullable (pending-aanvraag heeft nog geen koppeling).
ALTER TABLE `rijder_profiel_aanvraag`
    DROP COLUMN `license_key`,
    ADD CONSTRAINT `fk_rpa_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`person_id`) ON DELETE SET NULL;


-- ════════════════════════════════════════════════════════════════════════
--  STAP 6 — CONTROLE NA AFLOOP (verwacht: kolommen weg, FK's op person_id)
-- ════════════════════════════════════════════════════════════════════════
--   SHOW COLUMNS FROM persons LIKE 'license_key';        -- leeg
--   SHOW COLUMNS FROM entries LIKE 'person_license';     -- leeg
--   SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
--     FROM information_schema.KEY_COLUMN_USAGE
--    WHERE REFERENCED_TABLE_NAME='persons' AND TABLE_SCHEMA=DATABASE();
--     -- alle REFERENCED_COLUMN_NAME = person_id


-- ════════════════════════════════════════════════════════════════════════
--  BIJBEHORENDE CODE-WIJZIGINGEN — MOETEN VÓÓR/BIJ DEZE MIGRATIE LIVE ZIJN
-- ════════════════════════════════════════════════════════════════════════
--  Zolang de app-code hieronder nog op license_key/person_license leunt, breekt
--  'ie zodra de kolommen weg zijn. Herwerk deze naar person_id-native:
--
--  1) inc/person_id.php — de license↔person_id-brug:
--       • personIdVoorLicentie() leest persons.license_key → moet via
--         person_external_ids(extern_id=?) resolven.
--       • licentieVoorPersonId() leest persons.license_key → moet de KNSB-
--         licentie uit person_external_ids(systeem='knsb') halen (of NULL).
--       • resolveNaarPersonId() bouwt hierop voort — controleren.
--     Overweeg deze brug daarna grotendeels overbodig; endpoints krijgen
--     alleen nog person_id binnen.
--
--  2) D-WRITE-subqueries "(SELECT person_id FROM persons WHERE license_key=?)"
--     en "(SELECT license_key FROM persons WHERE person_id=?)" — schrijven/lezen
--     de weggevallen kolom. Weghalen; alleen nog person_id binden:
--       api/import.php (org-transponder toewijzen/vrijgeven, entries/tp insert),
--       api/csv_import.php (entries/tp insert), api/cluster_check.php (eUpdate/
--       heUpdate), jury/index.php (heat-swap), api/live.php + api/startlijst_laden.php
--       (heat_entries insert), api/helpers.php (historie-import insert).
--
--  3) api/helpers.php — koppel/samenvoegen/verwijderen (pending_link,
--     pending_pending_link, merge, wees-delete) draaien nog license-native op
--     persons.license_key en person_license (SELECT/UPDATE/DELETE). Herbouwen op
--     person_id; de "nieuwe" identiteit is een person_id, niet een license.
--     Let op de data-verplaatsende UPDATE ... SET person_license=?,person_id=?
--     WHERE person_license=? → wordt SET person_id=? WHERE person_id=?.
--
--  4) Prefix-filters op license_key (kolom weg) → via person_external_ids.systeem:
--       api/csv_import.php: "WHERE license_key NOT LIKE 'demo-%'" (volgende
--         startnr) → exclude person_id's met een ic-demo-mapping.
--       api/demo_fixture.php: "WHERE license_key LIKE 'demo-%'" → join op
--         person_external_ids(systeem='ic-demo').
--
--  5) push_subscriptions.licenses / push_outbox.licenses (JSON-lijsten van
--     license_keys) — bij push-flush al via person_external_ids geresolved
--     (fase 3, lib_push). Controleren dat er geen rauwe license_key-vergelijking
--     meer in zit; eventueel de JSON migreren naar person_id-lijsten.
--
--  6) api/persoon_anonimiseer.php logt nog 'license_key'=>token in het logboek
--     (cosmetisch; token is nu person_id). Optioneel bijwerken.
--
--  Pas ná deploy van 1–5 is deze migratie veilig.
-- ============================================================
