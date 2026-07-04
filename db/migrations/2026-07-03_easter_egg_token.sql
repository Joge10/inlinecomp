-- Easter-egg dedup: token per browser (localStorage-uuid) zodat één persoon
-- niet 100x kan tellen. Bestaande rijen (pre-migratie) krijgen NULL token
-- en tellen als 1 hit; nieuwe hits met token zijn per token uniek.
ALTER TABLE `easter_egg_hits`
    ADD COLUMN `token` VARCHAR(36) NULL AFTER `ip`,
    ADD UNIQUE KEY `uniq_token` (`token`);
