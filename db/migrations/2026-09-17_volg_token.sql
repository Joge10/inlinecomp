-- ============================================================
--  Migratie 2026-09-17 — Geheim volg-token (variant B, lek-fix)
--
--  Waarom: entitlement (de echte naam van een anonieme rijder mogen zien)
--  hing tot nu toe aan het person_id (GUID). Maar person_id is GEEN geheim —
--  de publieke API deelt het voor elke niet-anonieme rijder mee. Iedereen die
--  een rijder volgde vóórdat die anoniem werd, heeft het person_id opgeslagen
--  en bleef daarmee de naam zien. Lek.
--
--  Fix: een APART geheim token dat losstaat van person_id en alleen zichtbaar
--  is in Mijn InlineComp / via de organisatie. Alleen dít token ontsluit de
--  naam van een anonieme rijder. person_id geeft geen toegang meer. De rijder
--  kan het token vernieuwen (nieuwVolgToken) om alle huidige volgers in één
--  keer af te snijden.
--
--  CHAR(32) = 16 random bytes als hex (bin2hex(random_bytes(16))). NULL tot
--  het lazy gemint wordt (bij eerste weergave in profiel/beheer). UNIQUE zodat
--  lookup-op-token eenduidig + snel is; meerdere NULLs zijn toegestaan.
--
--  DB: MariaDB 10.11. Additief + herhaalbaar.
-- ============================================================

ALTER TABLE `persons`
    ADD COLUMN IF NOT EXISTS `volg_token` CHAR(32) DEFAULT NULL AFTER `publiek_anoniem`;

ALTER TABLE `persons`
    ADD UNIQUE KEY IF NOT EXISTS `uq_persons_volg_token` (`volg_token`);
