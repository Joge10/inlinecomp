-- ============================================================
--  InlineComp – migratie: login-logboek
--  Uitvoeren via phpMyAdmin of hosting-beheerpaneel
-- ============================================================

CREATE TABLE login_logs (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NULL,
    naam       VARCHAR(100) NOT NULL DEFAULT '',
    username   VARCHAR(100) NOT NULL DEFAULT '',
    actie      VARCHAR(20)  NOT NULL DEFAULT 'login',
    ip_adres   VARCHAR(45)  NOT NULL DEFAULT '',
    land       VARCHAR(80)  NOT NULL DEFAULT '',
    stad       VARCHAR(80)  NOT NULL DEFAULT '',
    browser    VARCHAR(60)  NOT NULL DEFAULT '',
    os         VARCHAR(40)  NOT NULL DEFAULT '',
    user_agent TEXT,
    tijdstip   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tijdstip (tijdstip),
    INDEX idx_user     (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  InlineComp – migratie: locatie toevoegen aan login_logs
-- ============================================================

ALTER TABLE login_logs
    ADD COLUMN land VARCHAR(80) NOT NULL DEFAULT '' AFTER ip_adres,
    ADD COLUMN stad VARCHAR(80) NOT NULL DEFAULT '' AFTER land;

