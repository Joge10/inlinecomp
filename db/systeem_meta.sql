-- InlineComp – systeem_meta
--
-- Kleine, generieke key/value-store voor systeembrede metadata die niet in
-- een eigen tabel thuishoort. Eerste gebruiker: de "laatst gemailde versie"
-- van de beheerders-update-mail (Info → Versie → "Beheerders informeren"),
-- zodat we niet dubbel mailen en de knop z'n status kan tonen.
--
-- Waarde is vrije tekst (vaak JSON). Lees/schrijf via kleine helpers in de
-- betreffende API (zie api/update_mail.php).

CREATE TABLE IF NOT EXISTS `systeem_meta` (
    `sleutel`   VARCHAR(64)  NOT NULL,
    `waarde`    TEXT         DEFAULT NULL,
    `gewijzigd` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`sleutel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
