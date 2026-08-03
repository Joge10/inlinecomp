-- InlineComp – survey_oh850_vragen (follow-up-vragen met email)
--
-- Losgekoppeld van survey_oh850: geen gedeelde id/foreign-key. Wordt
-- alleen ingevuld als de respondent op het einde een vraag stelt en daarbij
-- z'n email opgeeft. De backend voert deze rij in een aparte statement in,
-- in willekeurige volgorde t.o.v. de survey_oh850-rij, met een random sleep
-- van 50..500ms ertussen. Dat bemoeilijkt koppeling, maar garandeert geen
-- anonimiteit: timestamps staan op de seconde en de id's lopen op, dus bij
-- weinig verkeer is correlatie in principe nog te maken.
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
