-- InlineComp – public_meldingen (mededelingen voor publieke + coach-app)
--
-- Per wedstrijd kan de organisator één of meer mededelingen aanmaken die
-- automatisch via de auto-refresh in een pop-up bij kijkers verschijnen.
-- Voorbeelden: "Programma loopt 15 min uit", "1500m DSA verplaatst naar 14:00",
-- "Pauze verlengd tot 13:45".
--
-- Geldigheid: tussen `geldig_van` en `geldig_tot` zichtbaar (NULL = onbeperkt).
-- Prio bepaalt visuele zwaarte: info=blauw, warn=geel, urgent=rood.
-- De client onthoudt per device welke melding-id's al gezien zijn (in
-- localStorage), zodat dezelfde melding maar één keer als pop-up verschijnt.

-- competition_id = NULL → globale melding, zichtbaar voor alle bezoekers van
-- public/coach (ook op landing-pagina, vóór een wedstrijd is gekozen).
CREATE TABLE IF NOT EXISTS `public_meldingen` (
    `id`               VARCHAR(36)  NOT NULL,
    `competition_id`   VARCHAR(36)  NULL DEFAULT NULL,
    `titel`            VARCHAR(255) NOT NULL,
    `bericht`          TEXT         NOT NULL,
    `prio`             ENUM('info','warn','urgent') NOT NULL DEFAULT 'info',
    `geldig_van`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `geldig_tot`       DATETIME     NULL DEFAULT NULL,
    `aangemaakt_door`  VARCHAR(36)  DEFAULT NULL,
    `aangemaakt_op`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_comp_geldig` (`competition_id`, `geldig_van`),
    CONSTRAINT `fk_meld_comp`
        FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
