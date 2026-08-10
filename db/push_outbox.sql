-- InlineComp – push_outbox (uitgestelde push-verzending)
--
-- Trigger-endpoints (loting, heat-verwerkt) doen GEEN HTTPS-sends inline — dat
-- zou de operator-actie vertragen. Ze schrijven één rij per GEBEURTENIS in deze
-- outbox (snelle INSERT). Een throttled piggyback-flush (pushFlushOutbox, gehaakt
-- op api/meldingen.php dat coach+public tóch al pollen) verstuurt daarna in
-- kleine batches — géén cron nodig op de shared host.
--
-- Eén rij = één gebeurtenis (bv. "Loting HSA 1000m series gereed"). `licenses`
-- bevat de person_licenses van de rijders in die DC/heat; de flush zoekt daar de
-- volger-abonnementen bij en stuurt 1 push per abonnement (dedup in lib_push).

CREATE TABLE IF NOT EXISTS `push_outbox` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope`      VARCHAR(10)  NOT NULL DEFAULT 'coach',   -- historisch; Fase 3 zet 'all' (event → coach+public)
    `type`       VARCHAR(10)  NOT NULL DEFAULT 'loting',  -- 'loting' | 'uitslag' (bepaalt welke opt-in telt)
    `licenses`   TEXT         NOT NULL,                    -- JSON: person_license-lijst (target = volgers hiervan)
    `payload`    TEXT         NOT NULL,                    -- JSON: {title, body, url, tag}
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
