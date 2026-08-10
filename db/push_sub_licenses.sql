-- InlineComp – push_sub_licenses (gevolgde rijders per PUBLIC-abonnement, Fase 3)
--
-- Public volgt anoniem (client-side, localStorage). Bij subscribe spiegelt de
-- app de gevolgde license_keys hierheen, zodat de push-flush met een simpele
-- JOIN (net als coach_athletes voor coach) de volger-abonnementen vindt én per
-- ontvanger wéét welke van díens rijders in het event zitten (personalisatie).
--
-- Coach-abonnementen gebruiken deze tabel NIET (die targeten via coach_athletes).
-- ON DELETE CASCADE: verdwijnt het abonnement, dan ook z'n licentie-rijen.

CREATE TABLE IF NOT EXISTS `push_sub_licenses` (
    `subscription_id` INT UNSIGNED NOT NULL,
    `person_license`  VARCHAR(32)  NOT NULL,
    PRIMARY KEY (`subscription_id`, `person_license`),
    KEY `idx_psl_license` (`person_license`),
    CONSTRAINT `fk_psl_sub` FOREIGN KEY (`subscription_id`)
        REFERENCES `push_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
