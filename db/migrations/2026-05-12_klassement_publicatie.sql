-- 2026-05-12 – Klassement publicatie naar coach + public
--
-- Tot nu toe: zodra een klassement vastgelegd was in uitslag_klassement,
-- was het direct zichtbaar in /coach en /public. Operator wil een tussen-
-- stap "publiceren" zodat hij eerst kan controleren voordat het echt naar
-- de externe apps wordt gepushed.
--
-- Implementatie: gepubliceerd_at TIMESTAMP NULL op klassement_config.
--   NULL  = vastgelegd maar niet gepubliceerd (alleen admin ziet het)
--   filled = gepubliceerd op die timestamp (coach + public tonen het)
--
-- Bestaande klassementen: zet gepubliceerd_at op uitslag_klassement.MAX(vastgelegd_at)
-- zodat ze niet ineens uit /coach + /public verdwijnen.

ALTER TABLE `klassement_config`
    ADD COLUMN `gepubliceerd_at` TIMESTAMP NULL DEFAULT NULL AFTER `tiebreaker_dist`;

-- Bestaande vastgelegde klassementen retroactief publiceren zodat lopende
-- wedstrijden/eerdere uitslagen blijven werken zoals voorheen.
INSERT INTO klassement_config (competition_id, dc_id, gepubliceerd_at)
SELECT uk.competition_id, uk.distance_combination_id, MAX(uk.vastgelegd_at)
FROM uitslag_klassement uk
GROUP BY uk.competition_id, uk.distance_combination_id
ON DUPLICATE KEY UPDATE gepubliceerd_at = VALUES(gepubliceerd_at);
