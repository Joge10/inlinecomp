-- 2026-05-17 — Derde zichtbaarheids-status voor wedstrijden
--
-- Tot nu toe had zichtbaarheid 2 standen:
--   public_zichtbaar = 0 → wedstrijd staat als disabled "(binnenkort)"
--                          in de /coach + /public dropdowns
--   public_zichtbaar = 1 → selecteerbaar
--
-- Voor "stille voorbereiding" (= operator bereidt voor zonder dat publiek
-- door heeft dat InlineComp gebruikt gaat worden) is "(binnenkort)" te
-- veel. We voegen een derde status toe via een extra kolom:
--
--   public_aankondigen = 1 (default) → toon als "(binnenkort)" bij
--                                       public_zichtbaar = 0
--   public_aankondigen = 0           → toon HELEMAAL NIET in dropdowns
--                                       bij public_zichtbaar = 0
--
-- Bij public_zichtbaar = 1 is public_aankondigen irrelevant (wedstrijd
-- is sowieso volledig zichtbaar).
--
-- Effectieve 3-state:
--   (zichtbaar=0, aankondigen=0) → 🔒 Verborgen (volledig stil)
--   (zichtbaar=0, aankondigen=1) → ⏳ Binnenkort (in dropdown, disabled)
--   (zichtbaar=1, *)             → 👁 Live
--
-- Default voor bestaande wedstrijden = 1 (zelfde gedrag als vóór deze
-- migratie). Voor nieuwe wedstrijden waar je écht stil wilt blijven
-- handmatig op 0 zetten via de Beheer-UI.

ALTER TABLE competitions
    ADD COLUMN `public_aankondigen` TINYINT(1) NOT NULL DEFAULT 1
        AFTER `public_zichtbaar`;
