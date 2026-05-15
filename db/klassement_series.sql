-- InlineComp – klassement_series (meerjaren-/seizoensklassementen)
--
-- Een "serie" is een verzameling wedstrijden waarvan de uitslagen samen
-- één of meer klassementen vormen. Elke `klassement_series`-rij verwijst
-- naar een bestaande `klassementen`-rij (zelfde model als een PDF-import) —
-- zo hoeft de seeding-integratie in startlijst_genereer.php niet te
-- veranderen.
--
-- Regels (JSON):
--   type                    : 'gecombineerd' | 'sprint' | 'lang' | 'custom'
--   afstand_filter          : 'alle' | 'sprint' | 'lang' | 'per_naam'
--   afstand_namen           : [] (indien per_naam)
--   punten_tabel            : [50.1, 47, 45, ..., 1] (index 0 = 1e plek)
--   min_punten_bij_deelname : 1 (default) — rang buiten de tabel valt hierop terug
--   streepresultaten        : 0 (fase 2)
--   min_deelnames           : 0 (fase 2)

CREATE TABLE IF NOT EXISTS `klassement_series` (
    `id`             VARCHAR(36)  NOT NULL,
    `klassement_id`  VARCHAR(36)  NOT NULL,
    `naam`           VARCHAR(255) NOT NULL,
    `seizoen`        VARCHAR(20)  DEFAULT NULL,
    `org_id`         VARCHAR(36)  DEFAULT NULL,
    `regels`         LONGTEXT     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                                  CHECK (json_valid(`regels`)),
    `aangemaakt_op`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `herberekend_op` DATETIME     DEFAULT NULL,
    -- Publicatie-status: NULL = niet gepubliceerd (onzichtbaar in /public),
    -- NOT NULL = gepubliceerd op die datum/tijd. Operator publiceert
    -- expliciet vanuit Beheer → Klassementen.
    `gepubliceerd_at` DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_series_klassement` (`klassement_id`),
    KEY `idx_series_org` (`org_id`),
    CONSTRAINT `fk_series_klassement`
        FOREIGN KEY (`klassement_id`) REFERENCES `klassementen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
