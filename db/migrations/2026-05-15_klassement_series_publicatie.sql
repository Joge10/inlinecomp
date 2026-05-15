-- 2026-05-15 — Publicatie-status voor serie-klassementen
--
-- Zelfde patroon als klassement_config.gepubliceerd_at — operator publiceert
-- expliciet vanuit Beheer → Klassementen, zodat test-/probeer-series niet
-- onbedoeld in /public en /coach getoond worden.
--
-- NULL  = niet gepubliceerd → onzichtbaar in /public + /coach
-- NOT NULL = gepubliceerd op die datum/tijd → zichtbaar.
--
-- Bestaande series blijven default NULL (= niet gepubliceerd) — operator moet
-- dus actief publiceren. Veilig default: liever expliciet publiceren dan per
-- ongeluk publieke zichtbaarheid.

ALTER TABLE klassement_series
    ADD COLUMN gepubliceerd_at DATETIME DEFAULT NULL AFTER herberekend_op;

-- Optioneel: oude series die WEL al gewenst publiek zijn handmatig
-- publiceren. Voorbeeld voor één specifieke serie:
--   UPDATE klassement_series SET gepubliceerd_at = NOW() WHERE id = '<UUID>';
