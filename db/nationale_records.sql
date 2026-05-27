-- InlineComp – Nationale records inline skaten
-- Bron: KNSB-document "Nederlandse records inline skaten januari 2024"
-- Eén rij per (cat_groep, gender, afstand_key, type). UNIQUE-key garandeert
-- dat re-import overschrijft ipv duplicaten maakt.
--
-- Cat-groepen: 'junioren' (Pupillen t/m JA) | 'senioren' (vanaf SJ/SA en master).
-- Per cat-groep: heren (gender=0) en dames (gender=1).
-- Type: 'baan' (piste) | 'weg' (road).
-- Afstand-keys: zelfde format als _spkAfstandKey in jury.js — '200m','300m',
-- '500m','1000m','3000m-relay','100m','5000m-relay','marathon'.
--
-- Sommige disciplines (puntenkoers, afvalkoers) hebben GEEN nationaal record;
-- die rijden niet als NR-discipline. Voor zulke DCs toont speaker "geen NR".

CREATE TABLE IF NOT EXISTS `nationale_records` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cat_groep`    ENUM('junioren','senioren') NOT NULL,
    `gender`       TINYINT UNSIGNED NOT NULL,         -- 0=heren, 1=dames
    `afstand_key`  VARCHAR(30) NOT NULL,              -- '200m','marathon', etc.
    `type`         ENUM('baan','weg') NOT NULL,
    `tijd_ms`      INT UNSIGNED DEFAULT NULL,         -- record-tijd in milliseconden
    `afstand_naam` VARCHAR(60) DEFAULT NULL,          -- '200m tijdrit duo', 'Marathon 42.195m'
    `rijder_naam`  VARCHAR(255) DEFAULT NULL,         -- bij relay: meerdere namen, comma-gescheiden
    `locatie`      VARCHAR(120) DEFAULT NULL,
    `record_datum` DATE DEFAULT NULL,
    `wedstrijd`    VARCHAR(50) DEFAULT NULL,          -- 'EK', 'WK', 'NK', 'Finale', etc.
    `extra_info`   VARCHAR(120) DEFAULT NULL,         -- 'HF1', '1ste halve finale', 'Was junior'
    `bijgewerkt_op` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_record`  (`cat_groep`, `gender`, `afstand_key`, `type`),
    KEY        `idx_lookup` (`cat_groep`, `gender`, `afstand_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────────────
-- Records uit bron-document januari 2024 (eenmalige seed; re-import via
-- ON DUPLICATE KEY UPDATE indien KNSB-document later vernieuwd)
-- ──────────────────────────────────────────────────────────────────────

INSERT INTO `nationale_records`
    (cat_groep, gender, afstand_key, type, tijd_ms, afstand_naam,
     rijder_naam, locatie, record_datum, wedstrijd, extra_info)
VALUES
-- ── Junioren heren BAAN ───────────────────────────────────────────────
('junioren', 0, '200m',        'baan',    18208, '200 m tijdrit duo',  'Jenning de Boo',                                                 'Canelas (P)',  '2021-07-10', 'EK',     NULL),
('junioren', 0, '300m',        'baan',    24911, '300 m tijdrit',      'Jarno Botman',                                                   'Lagos (P)',    '2017-07-02', 'EK',     NULL),
('junioren', 0, '500m',        'baan',    40913, '500 m sprint',       'Jarno Botman',                                                   'Heerenveen',   '2017-06-02', 'NK',     NULL),
('junioren', 0, '1000m',       'baan',    80635, '1000 m in-line',     'Jarno Botman',                                                   'Lagos (P)',    '2017-07-04', 'EK',     NULL),
('junioren', 0, '3000m-relay', 'baan',   239723, '3000 m relay',       'Jarno Botman, Merijn Scheperkamp, Jordy van Workum',             'Lagos (P)',    '2017-07-04', 'EK',     NULL),
-- ── Junioren heren WEG ────────────────────────────────────────────────
('junioren', 0, '100m',        'weg',     10373, '100 m sprint',       'Jeroen de Kroon',                                                'Barcelona',    '2019-07-13', 'WK',     NULL),
('junioren', 0, '200m',        'weg',     17152, '200 m tijdrit',      'Merijn Scheperkamp',                                             'Oostende',     '2018-08-21', 'EK',     NULL),
('junioren', 0, '5000m-relay', 'weg',    395997, '5000 m relay',       'Gerrie van Lingen, Mark Middelkoop, Louis Hollaar, Chris Huizinga', 'Heerde',    '2016-07-29', 'EK',     NULL),
('junioren', 0, 'marathon',    'weg',   3797216, 'Marathon 42.195 m',  'Jordy van Workum',                                               'Arnhem',       '2018-07-08', 'WK',     NULL),

-- ── Junioren dames BAAN ───────────────────────────────────────────────
('junioren', 1, '200m',        'baan',    19586, '200 m tijdrit duo',  'Angel Daleman',                                                  'L''Aquila (I)','2022-09-04', 'EK',     NULL),
('junioren', 1, '300m',        'baan',    27228, '300 m tijdrit',      'Marijke Groenewoud',                                             'Heerenveen',   '2017-06-02', 'NK',     NULL),
('junioren', 1, '500m',        'baan',    44486, '500 m sprint',       'Marijke Groenewoud',                                             'Lagos (P)',    '2017-07-03', 'EK',     'HF1'),
('junioren', 1, '1000m',       'baan',    89305, '1000 m in-line',     'Bente Kerkhoff',                                                 'Pamplona',     '2019-08-28', 'EK',     'Q'),
('junioren', 1, '3000m-relay', 'baan',   262005, '3000 m relay',       'Anna van den Bos, Marijke Groenewoud, Maya de Jong',             'Heerde',       '2016-07-26', 'EK',     NULL),
-- ── Junioren dames WEG ────────────────────────────────────────────────
('junioren', 1, '100m',        'weg',     10514, '100 m sprint',       'Marit van Beijnum',                                              'Barcelona',    '2019-07-13', 'WK',     NULL),
('junioren', 1, '200m',        'weg',     18708, '200 m tijdrit',      'Marit van Beijnum',                                              'Oostende',     '2018-08-21', 'EK',     NULL),
('junioren', 1, '5000m-relay', 'weg',    449816, '5000 m relay',       'Anna van den Bos, Marijke Groenewoud, Maya de Jong, Fleur Veen', 'Heerde',       '2016-07-29', 'EK',     NULL),
('junioren', 1, 'marathon',    'weg',   4204006, 'Marathon 42.195 m',  'Patricia Koot',                                                  'L''Aquila (I)','2022-09-11', 'EK',     NULL),

-- ── Senioren heren BAAN ───────────────────────────────────────────────
('senioren', 0, '200m',        'baan',    17899, '200 m tijdrit duo',  'Jelmar Hempenius',                                               'Heerde',       '2023-06-16', 'NK',     NULL),
('senioren', 0, '300m',        'baan',    23704, '300 m tijdrit',      'Ronald Mulder',                                                  'Worgl',        '2015-07-20', 'Finale', NULL),
('senioren', 0, '500m',        'baan',    39453, '500 m sprint',       'Michel Mulder',                                                  'Worgl',        '2015-07-21', 'Finale', NULL),
('senioren', 0, '1000m',       'baan',    77761, '1000 m in-line',     'Mark Horsten',                                                   'Worgl',        '2015-07-22', NULL,     '1ste halve finale'),
('senioren', 0, '3000m-relay', 'baan',   233888, '3000 m relay',       'Mark Horsten, Ronald Mulder, Michel Mulder, Luc ter Haar',       'Geisingen',    '2014-07-30', NULL,     'Uitslag aanwezig'),
-- ── Senioren heren WEG ────────────────────────────────────────────────
('senioren', 0, '100m',        'weg',      9882, '100 m sprint',       'Jelmar Hempenius',                                               'Valence d''Agence (Fr)', '2023-07-21', 'EK', NULL),
('senioren', 0, '200m',        'weg',     16151, '200 m tijdrit',      'Michel Mulder',                                                  'San Benedetto','2012-08-12', NULL,     NULL),
('senioren', 0, '500m',        'weg',     38097, '500 m sprint',       'Mark Horsten',                                                   'Cali (Col) WG','2013-08-04', NULL,     NULL),
('senioren', 0, '5000m-relay', 'weg',    383536, '5000 m relay',       'Kay Schipper, Luc ter Haar, Crispijn Ariens, Jordy Harink',      'Heerde',       '2016-07-29', 'EK',     NULL),
('senioren', 0, 'marathon',    'weg',   3636359, 'Marathon 42.195 m',  'Ingmar Berga',                                                   'San Benedetto','2010-08-07', NULL,     NULL),

-- ── Senioren dames BAAN ───────────────────────────────────────────────
('senioren', 1, '200m',        'baan',    19586, '200 m tijdrit duo',  'Angel Daleman',                                                  'L''Aquila (I)','2022-09-04', 'EK',     'Was junior'),
('senioren', 1, '300m',        'baan',    26594, '300 m tijdrit',      'Bianca Roosenboom',                                              'Geisingen',    '2014-07-28', NULL,     'Uitslag aanwezig'),
('senioren', 1, '500m',        'baan',    42955, '500 m sprint',       'Bianca Roosenboom',                                              'Geisingen',    '2014-07-29', NULL,     '1ste Q'),
('senioren', 1, '1000m',       'baan',    85303, '1000 m in-line',     'Manon Kamminga',                                                 'Geisingen',    '2014-07-30', NULL,     '1ste Q'),
('senioren', 1, '3000m-relay', 'baan',   250321, '3000 m relay',       'Manon Kamminga, Irene Schouten, Bianca Roosenboom',              'Geisingen',    '2014-07-30', NULL,     'Uitslag aanwezig'),
-- ── Senioren dames WEG ────────────────────────────────────────────────
('senioren', 1, '100m',        'weg',     10514, '100 m sprint',       'Marit van Beijnum',                                              'Barcelona',    '2019-07-13', 'WK',     'Is/was junior'),
('senioren', 1, '200m',        'weg',     18530, '200 m tijdrit',      'Bianca Roosenboom',                                              'Biddinghuizen','2008-08-08', NULL,     NULL),
('senioren', 1, '500m',        'weg',     43623, '500 m sprint',       'Lisanne Buurman',                                                'Almere EK',    '2013-07-05', NULL,     NULL),
('senioren', 1, '5000m-relay', 'weg',    434193, '5000 m relay',       'Irene Schouten, Manon Kamminga, Bianca Roosenboom, Elma de Vries','Heerde',      '2016-07-29', 'EK',     NULL),
('senioren', 1, 'marathon',    'weg',   4200339, 'Marathon 42.195 m',  'Lianne van Loon',                                                'L''Aquila (I)','2022-09-11', 'EK',     NULL)
ON DUPLICATE KEY UPDATE
    tijd_ms       = VALUES(tijd_ms),
    afstand_naam  = VALUES(afstand_naam),
    rijder_naam   = VALUES(rijder_naam),
    locatie       = VALUES(locatie),
    record_datum  = VALUES(record_datum),
    wedstrijd     = VALUES(wedstrijd),
    extra_info    = VALUES(extra_info);
