-- ============================================================
--  NK Baan 2026 — Seeding-klassementen Kadetten (DKA + HKA)
--
--  Bron: KNSB Deelnemerslijsten NK Baan 2026 (Kadetten meisjes/jongens)
--  Gebruik: kies in Loting/Startlijsten als seeding-methode 'klassement'
--           en selecteer per afstand het juiste klassement.
--
--  Voorbeeld matching:
--      DKA 500m  → 'NK Baan 2026 — 500m Seeding', categorie 'DKA'
--      HKA 1000m → 'NK Baan 2026 — 1000m Seeding', categorie 'HKA'
--
--  R1, R2, … reserves zijn doorgenummerd vanaf de laatste reguliere positie
--  (zoals door operator gevraagd). Bv. R1 in 500m DKA → positie 25.
--
--  HKA: alleen top 12 is door KNSB geranked. Rijders 13+ (X in PDF) staan
--  hier NIET in — startlijst_genereer.php zet die automatisch achteraan op
--  startnummer.
--
--  VOOR JE DIT DRAAIT:
--      1) Vul hieronder @org_id in met jouw organisatie-UUID. Te vinden via
--         SELECT id, naam FROM organisaties; — anders verschijnt het
--         klassement NIET in de seeding-dropdown (filtert op org).
--      2) Draai in phpMyAdmin op productie-DB. Geen migratie nodig — zijn
--         INSERTs in bestaande klassementen + klassement_posities tabellen.
--      3) Bij vergissing: DELETE statements onderaan kun je uncommenten om
--         alles weer weg te halen.
-- ============================================================

SET @org_id = '7d9d105c-687d-4828-9ef7-704f609d7cc4';

-- ── 1) Klassementen aanmaken ───────────────────────────────────────────────
INSERT INTO klassementen (id, naam, seizoen, bron_bestand, categorieen, totaal_rijders, org_id) VALUES
  ('NK26-200m',  'NK Baan 2026 — 200m Seeding',       '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NK26-500m',  'NK Baan 2026 — 500m Seeding',       '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NK26-1000m', 'NK Baan 2026 — 1000m Seeding',      '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NK26-AFV',   'NK Baan 2026 — Afvalkoers Seeding', '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NK26-PNT',   'NK Baan 2026 — Puntenkoers Seeding','2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id);

-- ── 2a) 200m DKA — posities 1..32 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-DKA01', 'NK26-200m',  1,  '53', 'Eline van Leijenhorst',  'DKA'),
  ('NK26-200m-DKA02', 'NK26-200m',  2,  '88', 'Lisa Oldenhof',          'DKA'),
  ('NK26-200m-DKA03', 'NK26-200m',  3, '404', 'Dionne Verkleij',        'DKA'),
  ('NK26-200m-DKA04', 'NK26-200m',  4, '334', 'Miriam van Es',          'DKA'),
  ('NK26-200m-DKA05', 'NK26-200m',  5,  '63', 'Ilana Hokse',            'DKA'),
  ('NK26-200m-DKA06', 'NK26-200m',  6,  '47', 'Lexie Ruiter',           'DKA'),
  ('NK26-200m-DKA07', 'NK26-200m',  7, '118', 'Myrthe Kooiker',         'DKA'),
  ('NK26-200m-DKA08', 'NK26-200m',  8, '196', 'Heleen Drost',           'DKA'),
  ('NK26-200m-DKA09', 'NK26-200m',  9, '189', 'Saar Nieuwenhuis',       'DKA'),
  ('NK26-200m-DKA10', 'NK26-200m', 10,  '34', 'Liene Peenstra',         'DKA'),
  ('NK26-200m-DKA11', 'NK26-200m', 11,  '45', 'Julia Bakker',           'DKA'),
  ('NK26-200m-DKA12', 'NK26-200m', 12,  '58', 'Nienke Bakker',          'DKA'),
  ('NK26-200m-DKA13', 'NK26-200m', 13, '398', 'Doutzen Meijerhof',      'DKA'),
  ('NK26-200m-DKA14', 'NK26-200m', 14,  '24', 'Jasmijn Smit',           'DKA'),
  ('NK26-200m-DKA15', 'NK26-200m', 15, '620', 'Lynn Hoekert',           'DKA'),
  ('NK26-200m-DKA16', 'NK26-200m', 16,  '79', 'Julia Kettelarij',       'DKA'),
  ('NK26-200m-DKA17', 'NK26-200m', 17, '257', 'Felien Kuin',            'DKA'),
  ('NK26-200m-DKA18', 'NK26-200m', 18, '140', 'Elin Zwep',              'DKA'),
  ('NK26-200m-DKA19', 'NK26-200m', 19,  '57', 'Fenna Mozes',            'DKA'),
  ('NK26-200m-DKA20', 'NK26-200m', 20, '164', 'Tirza Stenneke (AW)',    'DKA'),
  ('NK26-200m-DKA21', 'NK26-200m', 21,  '26', 'Sophie Kooiker',         'DKA'),
  ('NK26-200m-DKA22', 'NK26-200m', 22, '246', 'Fleur Gielink',          'DKA'),
  ('NK26-200m-DKA23', 'NK26-200m', 23, '127', 'Tirza Stojanovski',      'DKA'),
  ('NK26-200m-DKA24', 'NK26-200m', 24, '564', 'Naomi Oord',             'DKA'),
  ('NK26-200m-DKA25', 'NK26-200m', 25, '329', 'Dominique van der Aa',   'DKA'),
  ('NK26-200m-DKA26', 'NK26-200m', 26, '475', 'Nora Venema',            'DKA'),
  ('NK26-200m-DKA27', 'NK26-200m', 27, '578', 'Lieke Nijboer',          'DKA'),
  ('NK26-200m-DKA28', 'NK26-200m', 28, '129', 'Ashley de Boer',         'DKA'),
  ('NK26-200m-DKA29', 'NK26-200m', 29, '383', 'Ellen Govaert',          'DKA'),
  ('NK26-200m-DKA30', 'NK26-200m', 30,   '2', 'Floor van Schoonhoven',  'DKA'),
  ('NK26-200m-DKA31', 'NK26-200m', 31,  '62', 'Nina Schoemaker',        'DKA'),
  ('NK26-200m-DKA32', 'NK26-200m', 32, '220', 'Ranomi Schaap',          'DKA');

-- ── 2b) 200m HKA — posities 1..12 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-HKA01', 'NK26-200m',  1, '195', 'Jort van Vondel',        'HKA'),
  ('NK26-200m-HKA02', 'NK26-200m',  2,  '51', 'Jurre Valkenburg',       'HKA'),
  ('NK26-200m-HKA03', 'NK26-200m',  3, '163', 'Jilmar Vogelzang',       'HKA'),
  ('NK26-200m-HKA04', 'NK26-200m',  4,  '71', 'Aiden Chris Brandsma',   'HKA'),
  ('NK26-200m-HKA05', 'NK26-200m',  5,  '85', 'Mats Bakker',            'HKA'),
  ('NK26-200m-HKA06', 'NK26-200m',  6,  '12', 'Marvin Kiekebos',        'HKA'),
  ('NK26-200m-HKA07', 'NK26-200m',  7, '288', 'Kees de Groote',         'HKA'),
  ('NK26-200m-HKA08', 'NK26-200m',  8, '311', 'Bas van den Brink',      'HKA'),
  ('NK26-200m-HKA09', 'NK26-200m',  9, '155', 'Hessel Veldhuizen',      'HKA'),
  ('NK26-200m-HKA10', 'NK26-200m', 10,  '91', 'Thure Louwers',          'HKA'),
  ('NK26-200m-HKA11', 'NK26-200m', 11,  '90', 'Jaap Wiering',           'HKA'),
  ('NK26-200m-HKA12', 'NK26-200m', 12,  '64', 'Thijmen Menger',         'HKA');

-- ── 3a) 500m DKA — posities 1..32 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-DKA01', 'NK26-500m',  1,  '53', 'Eline van Leijenhorst',  'DKA'),
  ('NK26-500m-DKA02', 'NK26-500m',  2,  '88', 'Lisa Oldenhof',          'DKA'),
  ('NK26-500m-DKA03', 'NK26-500m',  3, '404', 'Dionne Verkleij',        'DKA'),
  ('NK26-500m-DKA04', 'NK26-500m',  4, '334', 'Miriam van Es',          'DKA'),
  ('NK26-500m-DKA05', 'NK26-500m',  5,  '63', 'Ilana Hokse',            'DKA'),
  ('NK26-500m-DKA06', 'NK26-500m',  6,  '47', 'Lexie Ruiter',           'DKA'),
  ('NK26-500m-DKA07', 'NK26-500m',  7, '118', 'Myrthe Kooiker',         'DKA'),
  ('NK26-500m-DKA08', 'NK26-500m',  8, '196', 'Heleen Drost',           'DKA'),
  ('NK26-500m-DKA09', 'NK26-500m',  9, '189', 'Saar Nieuwenhuis',       'DKA'),
  ('NK26-500m-DKA10', 'NK26-500m', 10,  '34', 'Liene Peenstra',         'DKA'),
  ('NK26-500m-DKA11', 'NK26-500m', 11,  '45', 'Julia Bakker',           'DKA'),
  ('NK26-500m-DKA12', 'NK26-500m', 12,  '58', 'Nienke Bakker',          'DKA'),
  ('NK26-500m-DKA13', 'NK26-500m', 13, '398', 'Doutzen Meijerhof',      'DKA'),
  ('NK26-500m-DKA14', 'NK26-500m', 14,  '24', 'Jasmijn Smit',           'DKA'),
  ('NK26-500m-DKA15', 'NK26-500m', 15, '620', 'Lynn Hoekert',           'DKA'),
  ('NK26-500m-DKA16', 'NK26-500m', 16,  '79', 'Julia Kettelarij',       'DKA'),
  ('NK26-500m-DKA17', 'NK26-500m', 17, '257', 'Felien Kuin',            'DKA'),
  ('NK26-500m-DKA18', 'NK26-500m', 18, '140', 'Elin Zwep',              'DKA'),
  ('NK26-500m-DKA19', 'NK26-500m', 19,  '57', 'Fenna Mozes',            'DKA'),
  ('NK26-500m-DKA20', 'NK26-500m', 20,  '26', 'Sophie Kooiker',         'DKA'),
  ('NK26-500m-DKA21', 'NK26-500m', 21, '246', 'Fleur Gielink',          'DKA'),
  ('NK26-500m-DKA22', 'NK26-500m', 22, '127', 'Tirza Stojanovski',      'DKA'),
  ('NK26-500m-DKA23', 'NK26-500m', 23, '564', 'Naomi Oord',             'DKA'),
  ('NK26-500m-DKA24', 'NK26-500m', 24, '164', 'Tirza Stenneke (AW)',    'DKA'),
  ('NK26-500m-DKA25', 'NK26-500m', 25, '329', 'Dominique van der Aa',   'DKA'),
  ('NK26-500m-DKA26', 'NK26-500m', 26, '475', 'Nora Venema',            'DKA'),
  ('NK26-500m-DKA27', 'NK26-500m', 27, '578', 'Lieke Nijboer',          'DKA'),
  ('NK26-500m-DKA28', 'NK26-500m', 28, '129', 'Ashley de Boer',         'DKA'),
  ('NK26-500m-DKA29', 'NK26-500m', 29, '383', 'Ellen Govaert',          'DKA'),
  ('NK26-500m-DKA30', 'NK26-500m', 30,   '2', 'Floor van Schoonhoven',  'DKA'),
  ('NK26-500m-DKA31', 'NK26-500m', 31,  '62', 'Nina Schoemaker',        'DKA'),
  ('NK26-500m-DKA32', 'NK26-500m', 32, '220', 'Ranomi Schaap',          'DKA');

-- ── 3b) 500m HKA — posities 1..12 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-HKA01', 'NK26-500m',  1, '195', 'Jort van Vondel',        'HKA'),
  ('NK26-500m-HKA02', 'NK26-500m',  2,  '51', 'Jurre Valkenburg',       'HKA'),
  ('NK26-500m-HKA03', 'NK26-500m',  3, '163', 'Jilmar Vogelzang',       'HKA'),
  ('NK26-500m-HKA04', 'NK26-500m',  4,  '71', 'Aiden Chris Brandsma',   'HKA'),
  ('NK26-500m-HKA05', 'NK26-500m',  5,  '85', 'Mats Bakker',            'HKA'),
  ('NK26-500m-HKA06', 'NK26-500m',  6,  '12', 'Marvin Kiekebos',        'HKA'),
  ('NK26-500m-HKA07', 'NK26-500m',  7, '288', 'Kees de Groote',         'HKA'),
  ('NK26-500m-HKA08', 'NK26-500m',  8, '311', 'Bas van den Brink',      'HKA'),
  ('NK26-500m-HKA09', 'NK26-500m',  9, '155', 'Hessel Veldhuizen',      'HKA'),
  ('NK26-500m-HKA10', 'NK26-500m', 10,  '91', 'Thure Louwers',          'HKA'),
  ('NK26-500m-HKA11', 'NK26-500m', 11,  '90', 'Jaap Wiering',           'HKA'),
  ('NK26-500m-HKA12', 'NK26-500m', 12,  '64', 'Thijmen Menger',         'HKA');

-- ── 4a) 1000m DKA — posities 1..32 ─────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-DKA01', 'NK26-1000m',  1,  '53', 'Eline van Leijenhorst', 'DKA'),
  ('NK26-1000m-DKA02', 'NK26-1000m',  2,  '88', 'Lisa Oldenhof',         'DKA'),
  ('NK26-1000m-DKA03', 'NK26-1000m',  3, '404', 'Dionne Verkleij',       'DKA'),
  ('NK26-1000m-DKA04', 'NK26-1000m',  4, '334', 'Miriam van Es',         'DKA'),
  ('NK26-1000m-DKA05', 'NK26-1000m',  5,  '63', 'Ilana Hokse',           'DKA'),
  ('NK26-1000m-DKA06', 'NK26-1000m',  6,  '47', 'Lexie Ruiter',          'DKA'),
  ('NK26-1000m-DKA07', 'NK26-1000m',  7, '118', 'Myrthe Kooiker',        'DKA'),
  ('NK26-1000m-DKA08', 'NK26-1000m',  8, '196', 'Heleen Drost',          'DKA'),
  ('NK26-1000m-DKA09', 'NK26-1000m',  9, '189', 'Saar Nieuwenhuis',      'DKA'),
  ('NK26-1000m-DKA10', 'NK26-1000m', 10,  '34', 'Liene Peenstra',        'DKA'),
  ('NK26-1000m-DKA11', 'NK26-1000m', 11,  '45', 'Julia Bakker',          'DKA'),
  ('NK26-1000m-DKA12', 'NK26-1000m', 12,  '58', 'Nienke Bakker',         'DKA'),
  ('NK26-1000m-DKA13', 'NK26-1000m', 13, '398', 'Doutzen Meijerhof',     'DKA'),
  ('NK26-1000m-DKA14', 'NK26-1000m', 14,  '24', 'Jasmijn Smit',          'DKA'),
  ('NK26-1000m-DKA15', 'NK26-1000m', 15, '620', 'Lynn Hoekert',          'DKA'),
  ('NK26-1000m-DKA16', 'NK26-1000m', 16,  '79', 'Julia Kettelarij',      'DKA'),
  ('NK26-1000m-DKA17', 'NK26-1000m', 17, '257', 'Felien Kuin',           'DKA'),
  ('NK26-1000m-DKA18', 'NK26-1000m', 18, '140', 'Elin Zwep',             'DKA'),
  ('NK26-1000m-DKA19', 'NK26-1000m', 19,  '57', 'Fenna Mozes',           'DKA'),
  ('NK26-1000m-DKA20', 'NK26-1000m', 20,  '26', 'Sophie Kooiker',        'DKA'),
  ('NK26-1000m-DKA21', 'NK26-1000m', 21, '246', 'Fleur Gielink',         'DKA'),
  ('NK26-1000m-DKA22', 'NK26-1000m', 22, '127', 'Tirza Stojanovski',     'DKA'),
  ('NK26-1000m-DKA23', 'NK26-1000m', 23, '564', 'Naomi Oord',            'DKA'),
  ('NK26-1000m-DKA24', 'NK26-1000m', 24, '329', 'Dominique van der Aa',  'DKA'),
  ('NK26-1000m-DKA25', 'NK26-1000m', 25, '578', 'Lieke Nijboer',         'DKA'),
  ('NK26-1000m-DKA26', 'NK26-1000m', 26, '383', 'Ellen Govaert',         'DKA'),
  ('NK26-1000m-DKA27', 'NK26-1000m', 27, '164', 'Tirza Stenneke (AW)',   'DKA'),
  ('NK26-1000m-DKA28', 'NK26-1000m', 28, '475', 'Nora Venema',           'DKA'),
  ('NK26-1000m-DKA29', 'NK26-1000m', 29, '129', 'Ashley de Boer',        'DKA'),
  ('NK26-1000m-DKA30', 'NK26-1000m', 30,   '2', 'Floor van Schoonhoven', 'DKA'),
  ('NK26-1000m-DKA31', 'NK26-1000m', 31,  '62', 'Nina Schoemaker',       'DKA'),
  ('NK26-1000m-DKA32', 'NK26-1000m', 32, '220', 'Ranomi Schaap',         'DKA');

-- ── 4b) 1000m HKA — posities 1..12 ─────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-HKA01', 'NK26-1000m',  1, '195', 'Jort van Vondel',       'HKA'),
  ('NK26-1000m-HKA02', 'NK26-1000m',  2,  '51', 'Jurre Valkenburg',      'HKA'),
  ('NK26-1000m-HKA03', 'NK26-1000m',  3, '163', 'Jilmar Vogelzang',      'HKA'),
  ('NK26-1000m-HKA04', 'NK26-1000m',  4,  '71', 'Aiden Chris Brandsma',  'HKA'),
  ('NK26-1000m-HKA05', 'NK26-1000m',  5,  '85', 'Mats Bakker',           'HKA'),
  ('NK26-1000m-HKA06', 'NK26-1000m',  6,  '12', 'Marvin Kiekebos',       'HKA'),
  ('NK26-1000m-HKA07', 'NK26-1000m',  7, '288', 'Kees de Groote',        'HKA'),
  ('NK26-1000m-HKA08', 'NK26-1000m',  8, '311', 'Bas van den Brink',     'HKA'),
  ('NK26-1000m-HKA09', 'NK26-1000m',  9, '155', 'Hessel Veldhuizen',     'HKA'),
  ('NK26-1000m-HKA10', 'NK26-1000m', 10,  '91', 'Thure Louwers',         'HKA'),
  ('NK26-1000m-HKA11', 'NK26-1000m', 11,  '90', 'Jaap Wiering',          'HKA'),
  ('NK26-1000m-HKA12', 'NK26-1000m', 12,  '64', 'Thijmen Menger',        'HKA');

-- ── 5a) Afvalkoers DKA — posities 1..32 ────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-DKA01', 'NK26-AFV',  1, '404', 'Dionne Verkleij',          'DKA'),
  ('NK26-AFV-DKA02', 'NK26-AFV',  2,  '53', 'Eline van Leijenhorst',    'DKA'),
  ('NK26-AFV-DKA03', 'NK26-AFV',  3,  '63', 'Ilana Hokse',              'DKA'),
  ('NK26-AFV-DKA04', 'NK26-AFV',  4, '334', 'Miriam van Es',            'DKA'),
  ('NK26-AFV-DKA05', 'NK26-AFV',  5,  '47', 'Lexie Ruiter',             'DKA'),
  ('NK26-AFV-DKA06', 'NK26-AFV',  6, '189', 'Saar Nieuwenhuis',         'DKA'),
  ('NK26-AFV-DKA07', 'NK26-AFV',  7,  '58', 'Nienke Bakker',            'DKA'),
  ('NK26-AFV-DKA08', 'NK26-AFV',  8,  '34', 'Liene Peenstra',           'DKA'),
  ('NK26-AFV-DKA09', 'NK26-AFV',  9,  '45', 'Julia Bakker',             'DKA'),
  ('NK26-AFV-DKA10', 'NK26-AFV', 10, '196', 'Heleen Drost',             'DKA'),
  ('NK26-AFV-DKA11', 'NK26-AFV', 11,  '24', 'Jasmijn Smit',             'DKA'),
  ('NK26-AFV-DKA12', 'NK26-AFV', 12,  '88', 'Lisa Oldenhof',            'DKA'),
  ('NK26-AFV-DKA13', 'NK26-AFV', 13, '620', 'Lynn Hoekert',             'DKA'),
  ('NK26-AFV-DKA14', 'NK26-AFV', 14, '118', 'Myrthe Kooiker',           'DKA'),
  ('NK26-AFV-DKA15', 'NK26-AFV', 15, '564', 'Naomi Oord',               'DKA'),
  ('NK26-AFV-DKA16', 'NK26-AFV', 16, '140', 'Elin Zwep',                'DKA'),
  ('NK26-AFV-DKA17', 'NK26-AFV', 17, '329', 'Dominique van der Aa',     'DKA'),
  ('NK26-AFV-DKA18', 'NK26-AFV', 18, '398', 'Doutzen Meijerhof',        'DKA'),
  ('NK26-AFV-DKA19', 'NK26-AFV', 19, '257', 'Felien Kuin',              'DKA'),
  ('NK26-AFV-DKA20', 'NK26-AFV', 20, '164', 'Tirza Stenneke (AW)',      'DKA'),
  ('NK26-AFV-DKA21', 'NK26-AFV', 21, '578', 'Lieke Nijboer',            'DKA'),
  ('NK26-AFV-DKA22', 'NK26-AFV', 22, '246', 'Fleur Gielink',            'DKA'),
  ('NK26-AFV-DKA23', 'NK26-AFV', 23,  '79', 'Julia Kettelarij',         'DKA'),
  ('NK26-AFV-DKA24', 'NK26-AFV', 24,  '26', 'Sophie Kooiker',           'DKA'),
  ('NK26-AFV-DKA25', 'NK26-AFV', 25, '383', 'Ellen Govaert',            'DKA'),
  ('NK26-AFV-DKA26', 'NK26-AFV', 26,  '57', 'Fenna Mozes',              'DKA'),
  ('NK26-AFV-DKA27', 'NK26-AFV', 27, '475', 'Nora Venema',              'DKA'),
  ('NK26-AFV-DKA28', 'NK26-AFV', 28,  '62', 'Nina Schoemaker',          'DKA'),
  ('NK26-AFV-DKA29', 'NK26-AFV', 29,   '2', 'Floor van Schoonhoven',    'DKA'),
  ('NK26-AFV-DKA30', 'NK26-AFV', 30, '127', 'Tirza Stojanovski',        'DKA'),
  ('NK26-AFV-DKA31', 'NK26-AFV', 31, '129', 'Ashley de Boer',           'DKA'),
  ('NK26-AFV-DKA32', 'NK26-AFV', 32, '220', 'Ranomi Schaap',            'DKA');

-- ── 5b) Afvalkoers HKA — posities 1..12 (andere volgorde dan tijdritten) ───
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-HKA01', 'NK26-AFV',  1, '195', 'Jort van Vondel',          'HKA'),
  ('NK26-AFV-HKA02', 'NK26-AFV',  2, '163', 'Jilmar Vogelzang',         'HKA'),
  ('NK26-AFV-HKA03', 'NK26-AFV',  3,  '51', 'Jurre Valkenburg',         'HKA'),
  ('NK26-AFV-HKA04', 'NK26-AFV',  4,  '85', 'Mats Bakker',              'HKA'),
  ('NK26-AFV-HKA05', 'NK26-AFV',  5,  '12', 'Marvin Kiekebos',          'HKA'),
  ('NK26-AFV-HKA06', 'NK26-AFV',  6,  '71', 'Aiden Chris Brandsma',     'HKA'),
  ('NK26-AFV-HKA07', 'NK26-AFV',  7, '288', 'Kees de Groote',           'HKA'),
  ('NK26-AFV-HKA08', 'NK26-AFV',  8, '155', 'Hessel Veldhuizen',        'HKA'),
  ('NK26-AFV-HKA09', 'NK26-AFV',  9, '311', 'Bas van den Brink',        'HKA'),
  ('NK26-AFV-HKA10', 'NK26-AFV', 10,  '90', 'Jaap Wiering',             'HKA'),
  ('NK26-AFV-HKA11', 'NK26-AFV', 11,  '91', 'Thure Louwers',            'HKA'),
  ('NK26-AFV-HKA12', 'NK26-AFV', 12,  '64', 'Thijmen Menger',           'HKA');

-- ── 6a) Puntenkoers DKA — posities 1..32 ───────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-DKA01', 'NK26-PNT',  1, '404', 'Dionne Verkleij',          'DKA'),
  ('NK26-PNT-DKA02', 'NK26-PNT',  2,  '53', 'Eline van Leijenhorst',    'DKA'),
  ('NK26-PNT-DKA03', 'NK26-PNT',  3,  '63', 'Ilana Hokse',              'DKA'),
  ('NK26-PNT-DKA04', 'NK26-PNT',  4, '334', 'Miriam van Es',            'DKA'),
  ('NK26-PNT-DKA05', 'NK26-PNT',  5,  '47', 'Lexie Ruiter',             'DKA'),
  ('NK26-PNT-DKA06', 'NK26-PNT',  6, '189', 'Saar Nieuwenhuis',         'DKA'),
  ('NK26-PNT-DKA07', 'NK26-PNT',  7,  '58', 'Nienke Bakker',            'DKA'),
  ('NK26-PNT-DKA08', 'NK26-PNT',  8,  '34', 'Liene Peenstra',           'DKA'),
  ('NK26-PNT-DKA09', 'NK26-PNT',  9,  '45', 'Julia Bakker',             'DKA'),
  ('NK26-PNT-DKA10', 'NK26-PNT', 10, '196', 'Heleen Drost',             'DKA'),
  ('NK26-PNT-DKA11', 'NK26-PNT', 11,  '24', 'Jasmijn Smit',             'DKA'),
  ('NK26-PNT-DKA12', 'NK26-PNT', 12,  '88', 'Lisa Oldenhof',            'DKA'),
  ('NK26-PNT-DKA13', 'NK26-PNT', 13, '620', 'Lynn Hoekert',             'DKA'),
  ('NK26-PNT-DKA14', 'NK26-PNT', 14, '118', 'Myrthe Kooiker',           'DKA'),
  ('NK26-PNT-DKA15', 'NK26-PNT', 15, '564', 'Naomi Oord',               'DKA'),
  ('NK26-PNT-DKA16', 'NK26-PNT', 16, '140', 'Elin Zwep',                'DKA'),
  ('NK26-PNT-DKA17', 'NK26-PNT', 17, '329', 'Dominique van der Aa',     'DKA'),
  ('NK26-PNT-DKA18', 'NK26-PNT', 18, '398', 'Doutzen Meijerhof',        'DKA'),
  ('NK26-PNT-DKA19', 'NK26-PNT', 19, '257', 'Felien Kuin',              'DKA'),
  ('NK26-PNT-DKA20', 'NK26-PNT', 20, '578', 'Lieke Nijboer',            'DKA'),
  ('NK26-PNT-DKA21', 'NK26-PNT', 21, '246', 'Fleur Gielink',            'DKA'),
  ('NK26-PNT-DKA22', 'NK26-PNT', 22,  '79', 'Julia Kettelarij',         'DKA'),
  ('NK26-PNT-DKA23', 'NK26-PNT', 23,  '26', 'Sophie Kooiker',           'DKA'),
  ('NK26-PNT-DKA24', 'NK26-PNT', 24, '164', 'Tirza Stenneke (AW)',      'DKA'),
  ('NK26-PNT-DKA25', 'NK26-PNT', 25, '383', 'Ellen Govaert',            'DKA'),
  ('NK26-PNT-DKA26', 'NK26-PNT', 26,  '57', 'Fenna Mozes',              'DKA'),
  ('NK26-PNT-DKA27', 'NK26-PNT', 27, '475', 'Nora Venema',              'DKA'),
  ('NK26-PNT-DKA28', 'NK26-PNT', 28,  '62', 'Nina Schoemaker',          'DKA'),
  ('NK26-PNT-DKA29', 'NK26-PNT', 29,   '2', 'Floor van Schoonhoven',    'DKA'),
  ('NK26-PNT-DKA30', 'NK26-PNT', 30, '127', 'Tirza Stojanovski',        'DKA'),
  ('NK26-PNT-DKA31', 'NK26-PNT', 31, '129', 'Ashley de Boer',           'DKA'),
  ('NK26-PNT-DKA32', 'NK26-PNT', 32, '220', 'Ranomi Schaap',            'DKA');

-- ── 6b) Puntenkoers HKA — posities 1..12 ───────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-HKA01', 'NK26-PNT',  1, '195', 'Jort van Vondel',          'HKA'),
  ('NK26-PNT-HKA02', 'NK26-PNT',  2, '163', 'Jilmar Vogelzang',         'HKA'),
  ('NK26-PNT-HKA03', 'NK26-PNT',  3,  '51', 'Jurre Valkenburg',         'HKA'),
  ('NK26-PNT-HKA04', 'NK26-PNT',  4,  '85', 'Mats Bakker',              'HKA'),
  ('NK26-PNT-HKA05', 'NK26-PNT',  5,  '12', 'Marvin Kiekebos',          'HKA'),
  ('NK26-PNT-HKA06', 'NK26-PNT',  6,  '71', 'Aiden Chris Brandsma',     'HKA'),
  ('NK26-PNT-HKA07', 'NK26-PNT',  7, '288', 'Kees de Groote',           'HKA'),
  ('NK26-PNT-HKA08', 'NK26-PNT',  8, '155', 'Hessel Veldhuizen',        'HKA'),
  ('NK26-PNT-HKA09', 'NK26-PNT',  9, '311', 'Bas van den Brink',        'HKA'),
  ('NK26-PNT-HKA10', 'NK26-PNT', 10,  '90', 'Jaap Wiering',             'HKA'),
  ('NK26-PNT-HKA11', 'NK26-PNT', 11,  '91', 'Thure Louwers',            'HKA'),
  ('NK26-PNT-HKA12', 'NK26-PNT', 12,  '64', 'Thijmen Menger',           'HKA');

-- ── Optioneel: terugdraaien ────────────────────────────────────────────────
-- Cascading delete: het verwijderen van een klassement-rij wist automatisch
-- alle posities (FK ON DELETE CASCADE). Uncomment om alles weg te halen.
--
-- DELETE FROM klassementen WHERE id IN
--   ('NK26-200m','NK26-500m','NK26-1000m','NK26-AFV','NK26-PNT');
