-- InlineComp – survey_oh850_vragen (follow-up-vragen met email)
--
-- Volledig losgekoppeld van survey_oh850. Wordt alleen ingevuld als de
-- respondent op het einde van de survey een vraag stelt en daarbij z'n
-- email opgeeft. De backend INSERT'er voert deze rij in een aparte
-- statement in, in willekeurige volgorde t.o.v. de survey_oh850-rij,
-- met een random sleep van 50..500ms ertussen, zodat correlatie op
-- timestamp niet mogelijk is.
--
-- afgehandeld_at: zet Geert handmatig na het beantwoorden, zodat 'ie
-- een werkqueue heeft van openstaande vragen (NULL = nog te beantwoorden).

CREATE TABLE IF NOT EXISTS `survey_oh850_vragen` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submitted_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `email`           VARCHAR(255)  NOT NULL,
    `vraag`           TEXT          NOT NULL,
    `afgehandeld_at`  DATETIME      DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_submitted_at` (`submitted_at`),
    KEY `idx_afgehandeld`  (`afgehandeld_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
