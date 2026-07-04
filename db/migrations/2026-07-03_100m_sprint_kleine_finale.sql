-- Migratie 2026-07-03 — 100m sprint (internationaal systeem)
--
-- Twee kleine wijzigingen aan tijdschema_afstand_config:
--
-- 1. finale_seeding enum uitbreiden met 'reverse_slang'.
--    Nieuwe seeding-modus voor de 2-lane variant van 100m sprint:
--    snelste-paar zit in de LAATSTE heat (niet in H1). De pairs blijven
--    klassiek snake (snelste met langzaamste, 2e met op-1-na-langzaamste,
--    …), alleen de heat-nummering is omgekeerd. Reglement: WorldSkate
--    Speed Rulebook 2026 Art. 114.10-114.13.
--
--    Bewust NIET in gebruik voor 3-lane variant of andere sprint-afstanden
--    (500m+D, One Lap, 1000m) — daar blijft 'slang' (klassiek snake) de
--    standaard, matcht reglement Art. 115 (impliciet: geen omgekeerde
--    volgorde voorgeschreven).
--
-- 2. heeft_kleine_finale kolom toevoegen op afstand-niveau.
--    Bij 100m sprint (internationaal) rijden de verliezers uit de laatste
--    ronde vóór de A-finale een aparte race om de plek na de A-finale
--    (bv. plek 3-4 bij 2-lane 100m). Ronde-type in tijdschema_ritten
--    blijft 'finale_b' — de betekenis verschilt per wedstrijdsysteem:
--       - internationaal-nieuw: kleine finale (rijders uit voorgaande ronde
--                               die niet naar A gaan)
--       - full-final:            klassieke B-finale (rest op series-tijd)
--
--    Kolom staat bewust naast heeft_runner_up: beide zijn per-afstand
--    keuzes die alle categorieën van die afstand raken.

ALTER TABLE `tijdschema_afstand_config`
    MODIFY `finale_seeding` ENUM('slang','tijdkoppeling','reverse_slang')
        NOT NULL DEFAULT 'slang';

ALTER TABLE `tijdschema_afstand_config`
    ADD COLUMN `heeft_kleine_finale` TINYINT(1) NOT NULL DEFAULT 0
        AFTER `heeft_runner_up`;
