-- InlineComp – survey_oh850_throttle (burst-throttle voor de survey)
--
-- Slaat GEEN ruwe IP op: alleen sha256(ip + SURVEY_IP_PEPPER) plus het
-- laatste-submit-tijdstip. Los van de antwoord-tabellen; puur om te
-- voorkomen dat iemand in korte tijd meerdere keren submit. Per IP-hash
-- max 1 submit per SURVEY_THROTTLE_HOURS (zie index.php).
--
-- LET OP: de app-DB-user heeft geen DDL-rechten, dus deze tabel wordt NIET
-- door de PHP aangemaakt. Draai dit bestand één keer met een account dat
-- CREATE mag. Zolang de tabel niet bestaat faalt de throttle-SELECT stil
-- (catch) en gaat de survey gewoon door zonder throttle (fail-open).

CREATE TABLE IF NOT EXISTS `survey_oh850_throttle` (
    `ip_hash`      CHAR(64)  NOT NULL,
    `last_submit`  DATETIME  NOT NULL,
    PRIMARY KEY (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
