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

SET @org_id = 'VUL-HIER-JE-ORG-ID-IN';

-- ── 1) Klassementen aanmaken ───────────────────────────────────────────────
INSERT INTO klassementen (id, naam, seizoen, bron_bestand, categorieen, totaal_rijders, org_id) VALUES
  ('NKB26-200m',  'NK Baan 2026 — 200m Seeding',       '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NKB26-500m',  'NK Baan 2026 — 500m Seeding',       '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NKB26-1000m', 'NK Baan 2026 — 1000m Seeding',      '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NKB26-AFV',   'NK Baan 2026 — Afvalkoers Seeding', '2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id),
  ('NKB26-PNT',   'NK Baan 2026 — Puntenkoers Seeding','2026', 'Deelnemerslijsten NK Baan 2026.pdf', '["DKA","HKA"]', 44, @org_id);

-- ── 2a) 200m DKA — posities 1..32 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-200m-D01', 'NKB26-200m',  1,  '53', 'Eline van Leijenhorst',  'DKA'),
  ('NKB26-200m-D02', 'NKB26-200m',  2,  '88', 'Lisa Oldenhof',          'DKA'),
  ('NKB26-200m-D03', 'NKB26-200m',  3, '404', 'Dionne Verkleij',        'DKA'),
  ('NKB26-200m-D04', 'NKB26-200m',  4, '334', 'Miriam van Es',          'DKA'),
  ('NKB26-200m-D05', 'NKB26-200m',  5,  '63', 'Ilana Hokse',            'DKA'),
  ('NKB26-200m-D06', 'NKB26-200m',  6,  '47', 'Lexie Ruiter',           'DKA'),
  ('NKB26-200m-D07', 'NKB26-200m',  7, '118', 'Myrthe Kooiker',         'DKA'),
  ('NKB26-200m-D08', 'NKB26-200m',  8, '196', 'Heleen Drost',           'DKA'),
  ('NKB26-200m-D09', 'NKB26-200m',  9, '189', 'Saar Nieuwenhuis',       'DKA'),
  ('NKB26-200m-D10', 'NKB26-200m', 10,  '34', 'Liene Peenstra',         'DKA'),
  ('NKB26-200m-D11', 'NKB26-200m', 11,  '45', 'Julia Bakker',           'DKA'),
  ('NKB26-200m-D12', 'NKB26-200m', 12,  '58', 'Nienke Bakker',          'DKA'),
  ('NKB26-200m-D13', 'NKB26-200m', 13, '398', 'Doutzen Meijerhof',      'DKA'),
  ('NKB26-200m-D14', 'NKB26-200m', 14,  '24', 'Jasmijn Smit',           'DKA'),
  ('NKB26-200m-D15', 'NKB26-200m', 15, '620', 'Lynn Hoekert',           'DKA'),
  ('NKB26-200m-D16', 'NKB26-200m', 16,  '79', 'Julia Kettelarij',       'DKA'),
  ('NKB26-200m-D17', 'NKB26-200m', 17, '257', 'Felien Kuin',            'DKA'),
  ('NKB26-200m-D18', 'NKB26-200m', 18, '140', 'Elin Zwep',              'DKA'),
  ('NKB26-200m-D19', 'NKB26-200m', 19,  '57', 'Fenna Mozes',            'DKA'),
  ('NKB26-200m-D20', 'NKB26-200m', 20, '164', 'Tirza Stenneke (AW)',    'DKA'),
  ('NKB26-200m-D21', 'NKB26-200m', 21,  '26', 'Sophie Kooiker',         'DKA'),
  ('NKB26-200m-D22', 'NKB26-200m', 22, '246', 'Fleur Gielink',          'DKA'),
  ('NKB26-200m-D23', 'NKB26-200m', 23, '127', 'Tirza Stojanovski',      'DKA'),
  ('NKB26-200m-D24', 'NKB26-200m', 24, '564', 'Naomi Oord',             'DKA'),
  ('NKB26-200m-D25', 'NKB26-200m', 25, '329', 'Dominique van der Aa',   'DKA'),
  ('NKB26-200m-D26', 'NKB26-200m', 26, '475', 'Nora Venema',            'DKA'),
  ('NKB26-200m-D27', 'NKB26-200m', 27, '578', 'Lieke Nijboer',          'DKA'),
  ('NKB26-200m-D28', 'NKB26-200m', 28, '129', 'Ashley de Boer',         'DKA'),
  ('NKB26-200m-D29', 'NKB26-200m', 29, '383', 'Ellen Govaert',          'DKA'),
  ('NKB26-200m-D30', 'NKB26-200m', 30,   '2', 'Floor van Schoonhoven',  'DKA'),
  ('NKB26-200m-D31', 'NKB26-200m', 31,  '62', 'Nina Schoemaker',        'DKA'),
  ('NKB26-200m-D32', 'NKB26-200m', 32, '220', 'Ranomi Schaap',          'DKA');

-- ── 2b) 200m HKA — posities 1..12 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-200m-H01', 'NKB26-200m',  1, '195', 'Jort van Vondel',        'HKA'),
  ('NKB26-200m-H02', 'NKB26-200m',  2,  '51', 'Jurre Valkenburg',       'HKA'),
  ('NKB26-200m-H03', 'NKB26-200m',  3, '163', 'Jilmar Vogelzang',       'HKA'),
  ('NKB26-200m-H04', 'NKB26-200m',  4,  '71', 'Aiden Chris Brandsma',   'HKA'),
  ('NKB26-200m-H05', 'NKB26-200m',  5,  '85', 'Mats Bakker',            'HKA'),
  ('NKB26-200m-H06', 'NKB26-200m',  6,  '12', 'Marvin Kiekebos',        'HKA'),
  ('NKB26-200m-H07', 'NKB26-200m',  7, '288', 'Kees de Groote',         'HKA'),
  ('NKB26-200m-H08', 'NKB26-200m',  8, '311', 'Bas van den Brink',      'HKA'),
  ('NKB26-200m-H09', 'NKB26-200m',  9, '155', 'Hessel Veldhuizen',      'HKA'),
  ('NKB26-200m-H10', 'NKB26-200m', 10,  '91', 'Thure Louwers',          'HKA'),
  ('NKB26-200m-H11', 'NKB26-200m', 11,  '90', 'Jaap Wiering',           'HKA'),
  ('NKB26-200m-H12', 'NKB26-200m', 12,  '64', 'Thijmen Menger',         'HKA');

-- ── 3a) 500m DKA — posities 1..32 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-500m-D01', 'NKB26-500m',  1,  '53', 'Eline van Leijenhorst',  'DKA'),
  ('NKB26-500m-D02', 'NKB26-500m',  2,  '88', 'Lisa Oldenhof',          'DKA'),
  ('NKB26-500m-D03', 'NKB26-500m',  3, '404', 'Dionne Verkleij',        'DKA'),
  ('NKB26-500m-D04', 'NKB26-500m',  4, '334', 'Miriam van Es',          'DKA'),
  ('NKB26-500m-D05', 'NKB26-500m',  5,  '63', 'Ilana Hokse',            'DKA'),
  ('NKB26-500m-D06', 'NKB26-500m',  6,  '47', 'Lexie Ruiter',           'DKA'),
  ('NKB26-500m-D07', 'NKB26-500m',  7, '118', 'Myrthe Kooiker',         'DKA'),
  ('NKB26-500m-D08', 'NKB26-500m',  8, '196', 'Heleen Drost',           'DKA'),
  ('NKB26-500m-D09', 'NKB26-500m',  9, '189', 'Saar Nieuwenhuis',       'DKA'),
  ('NKB26-500m-D10', 'NKB26-500m', 10,  '34', 'Liene Peenstra',         'DKA'),
  ('NKB26-500m-D11', 'NKB26-500m', 11,  '45', 'Julia Bakker',           'DKA'),
  ('NKB26-500m-D12', 'NKB26-500m', 12,  '58', 'Nienke Bakker',          'DKA'),
  ('NKB26-500m-D13', 'NKB26-500m', 13, '398', 'Doutzen Meijerhof',      'DKA'),
  ('NKB26-500m-D14', 'NKB26-500m', 14,  '24', 'Jasmijn Smit',           'DKA'),
  ('NKB26-500m-D15', 'NKB26-500m', 15, '620', 'Lynn Hoekert',           'DKA'),
  ('NKB26-500m-D16', 'NKB26-500m', 16,  '79', 'Julia Kettelarij',       'DKA'),
  ('NKB26-500m-D17', 'NKB26-500m', 17, '257', 'Felien Kuin',            'DKA'),
  ('NKB26-500m-D18', 'NKB26-500m', 18, '140', 'Elin Zwep',              'DKA'),
  ('NKB26-500m-D19', 'NKB26-500m', 19,  '57', 'Fenna Mozes',            'DKA'),
  ('NKB26-500m-D20', 'NKB26-500m', 20,  '26', 'Sophie Kooiker',         'DKA'),
  ('NKB26-500m-D21', 'NKB26-500m', 21, '246', 'Fleur Gielink',          'DKA'),
  ('NKB26-500m-D22', 'NKB26-500m', 22, '127', 'Tirza Stojanovski',      'DKA'),
  ('NKB26-500m-D23', 'NKB26-500m', 23, '564', 'Naomi Oord',             'DKA'),
  ('NKB26-500m-D24', 'NKB26-500m', 24, '164', 'Tirza Stenneke (AW)',    'DKA'),
  ('NKB26-500m-D25', 'NKB26-500m', 25, '329', 'Dominique van der Aa',   'DKA'),
  ('NKB26-500m-D26', 'NKB26-500m', 26, '475', 'Nora Venema',            'DKA'),
  ('NKB26-500m-D27', 'NKB26-500m', 27, '578', 'Lieke Nijboer',          'DKA'),
  ('NKB26-500m-D28', 'NKB26-500m', 28, '129', 'Ashley de Boer',         'DKA'),
  ('NKB26-500m-D29', 'NKB26-500m', 29, '383', 'Ellen Govaert',          'DKA'),
  ('NKB26-500m-D30', 'NKB26-500m', 30,   '2', 'Floor van Schoonhoven',  'DKA'),
  ('NKB26-500m-D31', 'NKB26-500m', 31,  '62', 'Nina Schoemaker',        'DKA'),
  ('NKB26-500m-D32', 'NKB26-500m', 32, '220', 'Ranomi Schaap',          'DKA');

-- ── 3b) 500m HKA — posities 1..12 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-500m-H01', 'NKB26-500m',  1, '195', 'Jort van Vondel',        'HKA'),
  ('NKB26-500m-H02', 'NKB26-500m',  2,  '51', 'Jurre Valkenburg',       'HKA'),
  ('NKB26-500m-H03', 'NKB26-500m',  3, '163', 'Jilmar Vogelzang',       'HKA'),
  ('NKB26-500m-H04', 'NKB26-500m',  4,  '71', 'Aiden Chris Brandsma',   'HKA'),
  ('NKB26-500m-H05', 'NKB26-500m',  5,  '85', 'Mats Bakker',            'HKA'),
  ('NKB26-500m-H06', 'NKB26-500m',  6,  '12', 'Marvin Kiekebos',        'HKA'),
  ('NKB26-500m-H07', 'NKB26-500m',  7, '288', 'Kees de Groote',         'HKA'),
  ('NKB26-500m-H08', 'NKB26-500m',  8, '311', 'Bas van den Brink',      'HKA'),
  ('NKB26-500m-H09', 'NKB26-500m',  9, '155', 'Hessel Veldhuizen',      'HKA'),
  ('NKB26-500m-H10', 'NKB26-500m', 10,  '91', 'Thure Louwers',          'HKA'),
  ('NKB26-500m-H11', 'NKB26-500m', 11,  '90', 'Jaap Wiering',           'HKA'),
  ('NKB26-500m-H12', 'NKB26-500m', 12,  '64', 'Thijmen Menger',         'HKA');

-- ── 4a) 1000m DKA — posities 1..32 ─────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-1000m-D01', 'NKB26-1000m',  1,  '53', 'Eline van Leijenhorst', 'DKA'),
  ('NKB26-1000m-D02', 'NKB26-1000m',  2,  '88', 'Lisa Oldenhof',         'DKA'),
  ('NKB26-1000m-D03', 'NKB26-1000m',  3, '404', 'Dionne Verkleij',       'DKA'),
  ('NKB26-1000m-D04', 'NKB26-1000m',  4, '334', 'Miriam van Es',         'DKA'),
  ('NKB26-1000m-D05', 'NKB26-1000m',  5,  '63', 'Ilana Hokse',           'DKA'),
  ('NKB26-1000m-D06', 'NKB26-1000m',  6,  '47', 'Lexie Ruiter',          'DKA'),
  ('NKB26-1000m-D07', 'NKB26-1000m',  7, '118', 'Myrthe Kooiker',        'DKA'),
  ('NKB26-1000m-D08', 'NKB26-1000m',  8, '196', 'Heleen Drost',          'DKA'),
  ('NKB26-1000m-D09', 'NKB26-1000m',  9, '189', 'Saar Nieuwenhuis',      'DKA'),
  ('NKB26-1000m-D10', 'NKB26-1000m', 10,  '34', 'Liene Peenstra',        'DKA'),
  ('NKB26-1000m-D11', 'NKB26-1000m', 11,  '45', 'Julia Bakker',          'DKA'),
  ('NKB26-1000m-D12', 'NKB26-1000m', 12,  '58', 'Nienke Bakker',         'DKA'),
  ('NKB26-1000m-D13', 'NKB26-1000m', 13, '398', 'Doutzen Meijerhof',     'DKA'),
  ('NKB26-1000m-D14', 'NKB26-1000m', 14,  '24', 'Jasmijn Smit',          'DKA'),
  ('NKB26-1000m-D15', 'NKB26-1000m', 15, '620', 'Lynn Hoekert',          'DKA'),
  ('NKB26-1000m-D16', 'NKB26-1000m', 16,  '79', 'Julia Kettelarij',      'DKA'),
  ('NKB26-1000m-D17', 'NKB26-1000m', 17, '257', 'Felien Kuin',           'DKA'),
  ('NKB26-1000m-D18', 'NKB26-1000m', 18, '140', 'Elin Zwep',             'DKA'),
  ('NKB26-1000m-D19', 'NKB26-1000m', 19,  '57', 'Fenna Mozes',           'DKA'),
  ('NKB26-1000m-D20', 'NKB26-1000m', 20,  '26', 'Sophie Kooiker',        'DKA'),
  ('NKB26-1000m-D21', 'NKB26-1000m', 21, '246', 'Fleur Gielink',         'DKA'),
  ('NKB26-1000m-D22', 'NKB26-1000m', 22, '127', 'Tirza Stojanovski',     'DKA'),
  ('NKB26-1000m-D23', 'NKB26-1000m', 23, '564', 'Naomi Oord',            'DKA'),
  ('NKB26-1000m-D24', 'NKB26-1000m', 24, '329', 'Dominique van der Aa',  'DKA'),
  ('NKB26-1000m-D25', 'NKB26-1000m', 25, '578', 'Lieke Nijboer',         'DKA'),
  ('NKB26-1000m-D26', 'NKB26-1000m', 26, '383', 'Ellen Govaert',         'DKA'),
  ('NKB26-1000m-D27', 'NKB26-1000m', 27, '164', 'Tirza Stenneke (AW)',   'DKA'),
  ('NKB26-1000m-D28', 'NKB26-1000m', 28, '475', 'Nora Venema',           'DKA'),
  ('NKB26-1000m-D29', 'NKB26-1000m', 29, '129', 'Ashley de Boer',        'DKA'),
  ('NKB26-1000m-D30', 'NKB26-1000m', 30,   '2', 'Floor van Schoonhoven', 'DKA'),
  ('NKB26-1000m-D31', 'NKB26-1000m', 31,  '62', 'Nina Schoemaker',       'DKA'),
  ('NKB26-1000m-D32', 'NKB26-1000m', 32, '220', 'Ranomi Schaap',         'DKA');

-- ── 4b) 1000m HKA — posities 1..12 ─────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-1000m-H01', 'NKB26-1000m',  1, '195', 'Jort van Vondel',       'HKA'),
  ('NKB26-1000m-H02', 'NKB26-1000m',  2,  '51', 'Jurre Valkenburg',      'HKA'),
  ('NKB26-1000m-H03', 'NKB26-1000m',  3, '163', 'Jilmar Vogelzang',      'HKA'),
  ('NKB26-1000m-H04', 'NKB26-1000m',  4,  '71', 'Aiden Chris Brandsma',  'HKA'),
  ('NKB26-1000m-H05', 'NKB26-1000m',  5,  '85', 'Mats Bakker',           'HKA'),
  ('NKB26-1000m-H06', 'NKB26-1000m',  6,  '12', 'Marvin Kiekebos',       'HKA'),
  ('NKB26-1000m-H07', 'NKB26-1000m',  7, '288', 'Kees de Groote',        'HKA'),
  ('NKB26-1000m-H08', 'NKB26-1000m',  8, '311', 'Bas van den Brink',     'HKA'),
  ('NKB26-1000m-H09', 'NKB26-1000m',  9, '155', 'Hessel Veldhuizen',     'HKA'),
  ('NKB26-1000m-H10', 'NKB26-1000m', 10,  '91', 'Thure Louwers',         'HKA'),
  ('NKB26-1000m-H11', 'NKB26-1000m', 11,  '90', 'Jaap Wiering',          'HKA'),
  ('NKB26-1000m-H12', 'NKB26-1000m', 12,  '64', 'Thijmen Menger',        'HKA');

-- ── 5a) Afvalkoers DKA — posities 1..32 ────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-AFV-D01', 'NKB26-AFV',  1, '404', 'Dionne Verkleij',          'DKA'),
  ('NKB26-AFV-D02', 'NKB26-AFV',  2,  '53', 'Eline van Leijenhorst',    'DKA'),
  ('NKB26-AFV-D03', 'NKB26-AFV',  3,  '63', 'Ilana Hokse',              'DKA'),
  ('NKB26-AFV-D04', 'NKB26-AFV',  4, '334', 'Miriam van Es',            'DKA'),
  ('NKB26-AFV-D05', 'NKB26-AFV',  5,  '47', 'Lexie Ruiter',             'DKA'),
  ('NKB26-AFV-D06', 'NKB26-AFV',  6, '189', 'Saar Nieuwenhuis',         'DKA'),
  ('NKB26-AFV-D07', 'NKB26-AFV',  7,  '58', 'Nienke Bakker',            'DKA'),
  ('NKB26-AFV-D08', 'NKB26-AFV',  8,  '34', 'Liene Peenstra',           'DKA'),
  ('NKB26-AFV-D09', 'NKB26-AFV',  9,  '45', 'Julia Bakker',             'DKA'),
  ('NKB26-AFV-D10', 'NKB26-AFV', 10, '196', 'Heleen Drost',             'DKA'),
  ('NKB26-AFV-D11', 'NKB26-AFV', 11,  '24', 'Jasmijn Smit',             'DKA'),
  ('NKB26-AFV-D12', 'NKB26-AFV', 12,  '88', 'Lisa Oldenhof',            'DKA'),
  ('NKB26-AFV-D13', 'NKB26-AFV', 13, '620', 'Lynn Hoekert',             'DKA'),
  ('NKB26-AFV-D14', 'NKB26-AFV', 14, '118', 'Myrthe Kooiker',           'DKA'),
  ('NKB26-AFV-D15', 'NKB26-AFV', 15, '564', 'Naomi Oord',               'DKA'),
  ('NKB26-AFV-D16', 'NKB26-AFV', 16, '140', 'Elin Zwep',                'DKA'),
  ('NKB26-AFV-D17', 'NKB26-AFV', 17, '329', 'Dominique van der Aa',     'DKA'),
  ('NKB26-AFV-D18', 'NKB26-AFV', 18, '398', 'Doutzen Meijerhof',        'DKA'),
  ('NKB26-AFV-D19', 'NKB26-AFV', 19, '257', 'Felien Kuin',              'DKA'),
  ('NKB26-AFV-D20', 'NKB26-AFV', 20, '164', 'Tirza Stenneke (AW)',      'DKA'),
  ('NKB26-AFV-D21', 'NKB26-AFV', 21, '578', 'Lieke Nijboer',            'DKA'),
  ('NKB26-AFV-D22', 'NKB26-AFV', 22, '246', 'Fleur Gielink',            'DKA'),
  ('NKB26-AFV-D23', 'NKB26-AFV', 23,  '79', 'Julia Kettelarij',         'DKA'),
  ('NKB26-AFV-D24', 'NKB26-AFV', 24,  '26', 'Sophie Kooiker',           'DKA'),
  ('NKB26-AFV-D25', 'NKB26-AFV', 25, '383', 'Ellen Govaert',            'DKA'),
  ('NKB26-AFV-D26', 'NKB26-AFV', 26,  '57', 'Fenna Mozes',              'DKA'),
  ('NKB26-AFV-D27', 'NKB26-AFV', 27, '475', 'Nora Venema',              'DKA'),
  ('NKB26-AFV-D28', 'NKB26-AFV', 28,  '62', 'Nina Schoemaker',          'DKA'),
  ('NKB26-AFV-D29', 'NKB26-AFV', 29,   '2', 'Floor van Schoonhoven',    'DKA'),
  ('NKB26-AFV-D30', 'NKB26-AFV', 30, '127', 'Tirza Stojanovski',        'DKA'),
  ('NKB26-AFV-D31', 'NKB26-AFV', 31, '129', 'Ashley de Boer',           'DKA'),
  ('NKB26-AFV-D32', 'NKB26-AFV', 32, '220', 'Ranomi Schaap',            'DKA');

-- ── 5b) Afvalkoers HKA — posities 1..12 (andere volgorde dan tijdritten) ───
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-AFV-H01', 'NKB26-AFV',  1, '195', 'Jort van Vondel',          'HKA'),
  ('NKB26-AFV-H02', 'NKB26-AFV',  2, '163', 'Jilmar Vogelzang',         'HKA'),
  ('NKB26-AFV-H03', 'NKB26-AFV',  3,  '51', 'Jurre Valkenburg',         'HKA'),
  ('NKB26-AFV-H04', 'NKB26-AFV',  4,  '85', 'Mats Bakker',              'HKA'),
  ('NKB26-AFV-H05', 'NKB26-AFV',  5,  '12', 'Marvin Kiekebos',          'HKA'),
  ('NKB26-AFV-H06', 'NKB26-AFV',  6,  '71', 'Aiden Chris Brandsma',     'HKA'),
  ('NKB26-AFV-H07', 'NKB26-AFV',  7, '288', 'Kees de Groote',           'HKA'),
  ('NKB26-AFV-H08', 'NKB26-AFV',  8, '155', 'Hessel Veldhuizen',        'HKA'),
  ('NKB26-AFV-H09', 'NKB26-AFV',  9, '311', 'Bas van den Brink',        'HKA'),
  ('NKB26-AFV-H10', 'NKB26-AFV', 10,  '90', 'Jaap Wiering',             'HKA'),
  ('NKB26-AFV-H11', 'NKB26-AFV', 11,  '91', 'Thure Louwers',            'HKA'),
  ('NKB26-AFV-H12', 'NKB26-AFV', 12,  '64', 'Thijmen Menger',           'HKA');

-- ── 6a) Puntenkoers DKA — posities 1..32 ───────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-PNT-D01', 'NKB26-PNT',  1, '404', 'Dionne Verkleij',          'DKA'),
  ('NKB26-PNT-D02', 'NKB26-PNT',  2,  '53', 'Eline van Leijenhorst',    'DKA'),
  ('NKB26-PNT-D03', 'NKB26-PNT',  3,  '63', 'Ilana Hokse',              'DKA'),
  ('NKB26-PNT-D04', 'NKB26-PNT',  4, '334', 'Miriam van Es',            'DKA'),
  ('NKB26-PNT-D05', 'NKB26-PNT',  5,  '47', 'Lexie Ruiter',             'DKA'),
  ('NKB26-PNT-D06', 'NKB26-PNT',  6, '189', 'Saar Nieuwenhuis',         'DKA'),
  ('NKB26-PNT-D07', 'NKB26-PNT',  7,  '58', 'Nienke Bakker',            'DKA'),
  ('NKB26-PNT-D08', 'NKB26-PNT',  8,  '34', 'Liene Peenstra',           'DKA'),
  ('NKB26-PNT-D09', 'NKB26-PNT',  9,  '45', 'Julia Bakker',             'DKA'),
  ('NKB26-PNT-D10', 'NKB26-PNT', 10, '196', 'Heleen Drost',             'DKA'),
  ('NKB26-PNT-D11', 'NKB26-PNT', 11,  '24', 'Jasmijn Smit',             'DKA'),
  ('NKB26-PNT-D12', 'NKB26-PNT', 12,  '88', 'Lisa Oldenhof',            'DKA'),
  ('NKB26-PNT-D13', 'NKB26-PNT', 13, '620', 'Lynn Hoekert',             'DKA'),
  ('NKB26-PNT-D14', 'NKB26-PNT', 14, '118', 'Myrthe Kooiker',           'DKA'),
  ('NKB26-PNT-D15', 'NKB26-PNT', 15, '564', 'Naomi Oord',               'DKA'),
  ('NKB26-PNT-D16', 'NKB26-PNT', 16, '140', 'Elin Zwep',                'DKA'),
  ('NKB26-PNT-D17', 'NKB26-PNT', 17, '329', 'Dominique van der Aa',     'DKA'),
  ('NKB26-PNT-D18', 'NKB26-PNT', 18, '398', 'Doutzen Meijerhof',        'DKA'),
  ('NKB26-PNT-D19', 'NKB26-PNT', 19, '257', 'Felien Kuin',              'DKA'),
  ('NKB26-PNT-D20', 'NKB26-PNT', 20, '578', 'Lieke Nijboer',            'DKA'),
  ('NKB26-PNT-D21', 'NKB26-PNT', 21, '246', 'Fleur Gielink',            'DKA'),
  ('NKB26-PNT-D22', 'NKB26-PNT', 22,  '79', 'Julia Kettelarij',         'DKA'),
  ('NKB26-PNT-D23', 'NKB26-PNT', 23,  '26', 'Sophie Kooiker',           'DKA'),
  ('NKB26-PNT-D24', 'NKB26-PNT', 24, '164', 'Tirza Stenneke (AW)',      'DKA'),
  ('NKB26-PNT-D25', 'NKB26-PNT', 25, '383', 'Ellen Govaert',            'DKA'),
  ('NKB26-PNT-D26', 'NKB26-PNT', 26,  '57', 'Fenna Mozes',              'DKA'),
  ('NKB26-PNT-D27', 'NKB26-PNT', 27, '475', 'Nora Venema',              'DKA'),
  ('NKB26-PNT-D28', 'NKB26-PNT', 28,  '62', 'Nina Schoemaker',          'DKA'),
  ('NKB26-PNT-D29', 'NKB26-PNT', 29,   '2', 'Floor van Schoonhoven',    'DKA'),
  ('NKB26-PNT-D30', 'NKB26-PNT', 30, '127', 'Tirza Stojanovski',        'DKA'),
  ('NKB26-PNT-D31', 'NKB26-PNT', 31, '129', 'Ashley de Boer',           'DKA'),
  ('NKB26-PNT-D32', 'NKB26-PNT', 32, '220', 'Ranomi Schaap',            'DKA');

-- ── 6b) Puntenkoers HKA — posities 1..12 ───────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NKB26-PNT-H01', 'NKB26-PNT',  1, '195', 'Jort van Vondel',          'HKA'),
  ('NKB26-PNT-H02', 'NKB26-PNT',  2, '163', 'Jilmar Vogelzang',         'HKA'),
  ('NKB26-PNT-H03', 'NKB26-PNT',  3,  '51', 'Jurre Valkenburg',         'HKA'),
  ('NKB26-PNT-H04', 'NKB26-PNT',  4,  '85', 'Mats Bakker',              'HKA'),
  ('NKB26-PNT-H05', 'NKB26-PNT',  5,  '12', 'Marvin Kiekebos',          'HKA'),
  ('NKB26-PNT-H06', 'NKB26-PNT',  6,  '71', 'Aiden Chris Brandsma',     'HKA'),
  ('NKB26-PNT-H07', 'NKB26-PNT',  7, '288', 'Kees de Groote',           'HKA'),
  ('NKB26-PNT-H08', 'NKB26-PNT',  8, '155', 'Hessel Veldhuizen',        'HKA'),
  ('NKB26-PNT-H09', 'NKB26-PNT',  9, '311', 'Bas van den Brink',        'HKA'),
  ('NKB26-PNT-H10', 'NKB26-PNT', 10,  '90', 'Jaap Wiering',             'HKA'),
  ('NKB26-PNT-H11', 'NKB26-PNT', 11,  '91', 'Thure Louwers',            'HKA'),
  ('NKB26-PNT-H12', 'NKB26-PNT', 12,  '64', 'Thijmen Menger',           'HKA');

-- ── Optioneel: terugdraaien ────────────────────────────────────────────────
-- Cascading delete: het verwijderen van een klassement-rij wist automatisch
-- alle posities (FK ON DELETE CASCADE). Uncomment om alles weg te halen.
--
-- DELETE FROM klassementen WHERE id IN
--   ('NKB26-200m','NKB26-500m','NKB26-1000m','NKB26-AFV','NKB26-PNT');
