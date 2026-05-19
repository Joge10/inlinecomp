-- ============================================================
--  NK Baan 2026 — Seeding-klassementen Senioren (DSA + HSA)
--
--  Voortbouwend op:
--    nk_baan_2026_kadetten_seeding.sql      (verplicht eerst)
--    nk_baan_2026_junioren_b_seeding.sql    (verplicht eerst)
--    nk_baan_2026_junioren_a_seeding.sql    (verplicht eerst)
--
--  Wijzigingen:
--   1) UPDATE categorieen-JSON van bestaande klassementen → ook DSA/HSA
--   2) INSERT klassement_posities voor DSA (20/20/22/22/21 per afstand)
--   3) INSERT klassement_posities voor HSA (27/26/31/29/29 per afstand)
--
--  Bron: KNSB Deelnemerslijsten Senioren NK Baan 2026.
--
--  Bijzonderheden (bevestigd door operator):
--   - DSA notatie "…N R1" / "….N R1+2" verschilt per afstand. R-rijders
--     gewoon doorgenummerd vanaf laatste reguliere positie.
--   - DSA Esther Korenberg (802) alleen in 1000m/Afval/Punten (op pos 20).
--   - DSA Sofia Schilder (393) alleen in Afval/Punten (op pos 14).
--   - DSA Puntenkoers: alleen R1=Janne (Angel ontbreekt hier als reserve;
--     bewust, KNSB heeft Angel niet voor puntenkoers aangewezen).
--   - HSA Chris Berkhout (AW ITA): wildcard met (AW ITA)-suffix in
--     tijdritten; in Afval/Punten staat hij gewoon op pos 12 zonder
--     suffix (op eigen kracht ge-seleecteerd).
--   - HSA losse-afstand-rijders: Janno Botman (218) alleen 200m,
--     Kayo Vos (108) alleen 500m, Stefan Westenbroek (389) alleen 200m.
--   - HSA Afval/Punten: 29 reguliere + pos 30 = X, geen R-rijders.
-- ============================================================

-- ── 1) Categorieen-metadata uitbreiden + totaal_rijders bijwerken ──────────
UPDATE klassementen
   SET categorieen   = '["DKA","HKA","DJB","HJB","DJA","HJA","DSA","HSA"]',
       totaal_rijders = totaal_rijders + CASE id
           WHEN 'NK26-200m'  THEN 47   -- 20 DSA + 27 HSA
           WHEN 'NK26-500m'  THEN 46   -- 20 DSA + 26 HSA
           WHEN 'NK26-1000m' THEN 53   -- 22 DSA + 31 HSA
           WHEN 'NK26-AFV'   THEN 51   -- 22 DSA + 29 HSA
           WHEN 'NK26-PNT'   THEN 50   -- 21 DSA + 29 HSA
           ELSE 0
       END
 WHERE id IN ('NK26-200m','NK26-500m','NK26-1000m','NK26-AFV','NK26-PNT');

-- ── 2a) 200m DSA — posities 1..20 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-DSA01', 'NK26-200m',  1, '179', 'Lianne van Loon',              'DSA'),
  ('NK26-200m-DSA02', 'NK26-200m',  2, '152', 'Jet Fransen',                  'DSA'),
  ('NK26-200m-DSA03', 'NK26-200m',  3, '235', 'Nikki Noordergraaf',           'DSA'),
  ('NK26-200m-DSA04', 'NK26-200m',  4, '217', 'Fleur Huls',                   'DSA'),
  ('NK26-200m-DSA05', 'NK26-200m',  5, '150', 'Bianca van der Meer',          'DSA'),
  ('NK26-200m-DSA06', 'NK26-200m',  6, '269', 'Sanne Yvonne Oosterwijk',      'DSA'),
  ('NK26-200m-DSA07', 'NK26-200m',  7, '357', 'Elanne de Vries',              'DSA'),
  ('NK26-200m-DSA08', 'NK26-200m',  8, '391', 'Elbrich Nicolay',              'DSA'),
  ('NK26-200m-DSA09', 'NK26-200m',  9, '423', 'Maaike Koelewijn',             'DSA'),
  ('NK26-200m-DSA10', 'NK26-200m', 10,  '39', 'Denice Nieuwenhuis',           'DSA'),
  ('NK26-200m-DSA11', 'NK26-200m', 11, '232', 'Evy van Zoest',                'DSA'),
  ('NK26-200m-DSA12', 'NK26-200m', 12, '429', 'Asia Renda',                   'DSA'),
  ('NK26-200m-DSA13', 'NK26-200m', 13, '383', 'Jennifer Bollen',              'DSA'),
  ('NK26-200m-DSA14', 'NK26-200m', 14, '320', 'Lataesha Narain',              'DSA'),
  ('NK26-200m-DSA15', 'NK26-200m', 15, '316', 'Amber van der Meijden',        'DSA'),
  ('NK26-200m-DSA16', 'NK26-200m', 16, '255', 'Quirine Krommendijk',          'DSA'),
  ('NK26-200m-DSA17', 'NK26-200m', 17, '265', 'Mayke Vriesinga',              'DSA'),
  ('NK26-200m-DSA18', 'NK26-200m', 18, '124', 'Miriam Gossen',                'DSA'),
  ('NK26-200m-DSA19', 'NK26-200m', 19, '378', 'Bo Meijer',                    'DSA'),
  ('NK26-200m-DSA20', 'NK26-200m', 20, '390', 'Angel Daleman',                'DSA');

-- ── 2b) 500m DSA — posities 1..20 (gelijk aan 200m) ───────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-DSA01', 'NK26-500m',  1, '179', 'Lianne van Loon',              'DSA'),
  ('NK26-500m-DSA02', 'NK26-500m',  2, '152', 'Jet Fransen',                  'DSA'),
  ('NK26-500m-DSA03', 'NK26-500m',  3, '235', 'Nikki Noordergraaf',           'DSA'),
  ('NK26-500m-DSA04', 'NK26-500m',  4, '217', 'Fleur Huls',                   'DSA'),
  ('NK26-500m-DSA05', 'NK26-500m',  5, '150', 'Bianca van der Meer',          'DSA'),
  ('NK26-500m-DSA06', 'NK26-500m',  6, '269', 'Sanne Yvonne Oosterwijk',      'DSA'),
  ('NK26-500m-DSA07', 'NK26-500m',  7, '357', 'Elanne de Vries',              'DSA'),
  ('NK26-500m-DSA08', 'NK26-500m',  8, '391', 'Elbrich Nicolay',              'DSA'),
  ('NK26-500m-DSA09', 'NK26-500m',  9, '423', 'Maaike Koelewijn',             'DSA'),
  ('NK26-500m-DSA10', 'NK26-500m', 10,  '39', 'Denice Nieuwenhuis',           'DSA'),
  ('NK26-500m-DSA11', 'NK26-500m', 11, '232', 'Evy van Zoest',                'DSA'),
  ('NK26-500m-DSA12', 'NK26-500m', 12, '429', 'Asia Renda',                   'DSA'),
  ('NK26-500m-DSA13', 'NK26-500m', 13, '383', 'Jennifer Bollen',              'DSA'),
  ('NK26-500m-DSA14', 'NK26-500m', 14, '320', 'Lataesha Narain',              'DSA'),
  ('NK26-500m-DSA15', 'NK26-500m', 15, '316', 'Amber van der Meijden',        'DSA'),
  ('NK26-500m-DSA16', 'NK26-500m', 16, '255', 'Quirine Krommendijk',          'DSA'),
  ('NK26-500m-DSA17', 'NK26-500m', 17, '265', 'Mayke Vriesinga',              'DSA'),
  ('NK26-500m-DSA18', 'NK26-500m', 18, '124', 'Miriam Gossen',                'DSA'),
  ('NK26-500m-DSA19', 'NK26-500m', 19, '378', 'Bo Meijer',                    'DSA'),
  ('NK26-500m-DSA20', 'NK26-500m', 20, '390', 'Angel Daleman',                'DSA');

-- ── 2c) 1000m DSA — posities 1..22 ────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-DSA01', 'NK26-1000m',  1, '179', 'Lianne van Loon',            'DSA'),
  ('NK26-1000m-DSA02', 'NK26-1000m',  2, '152', 'Jet Fransen',                'DSA'),
  ('NK26-1000m-DSA03', 'NK26-1000m',  3, '235', 'Nikki Noordergraaf',         'DSA'),
  ('NK26-1000m-DSA04', 'NK26-1000m',  4, '217', 'Fleur Huls',                 'DSA'),
  ('NK26-1000m-DSA05', 'NK26-1000m',  5, '150', 'Bianca van der Meer',        'DSA'),
  ('NK26-1000m-DSA06', 'NK26-1000m',  6, '269', 'Sanne Yvonne Oosterwijk',    'DSA'),
  ('NK26-1000m-DSA07', 'NK26-1000m',  7, '357', 'Elanne de Vries',            'DSA'),
  ('NK26-1000m-DSA08', 'NK26-1000m',  8, '391', 'Elbrich Nicolay',            'DSA'),
  ('NK26-1000m-DSA09', 'NK26-1000m',  9, '423', 'Maaike Koelewijn',           'DSA'),
  ('NK26-1000m-DSA10', 'NK26-1000m', 10,  '39', 'Denice Nieuwenhuis',         'DSA'),
  ('NK26-1000m-DSA11', 'NK26-1000m', 11, '232', 'Evy van Zoest',              'DSA'),
  ('NK26-1000m-DSA12', 'NK26-1000m', 12, '429', 'Asia Renda',                 'DSA'),
  ('NK26-1000m-DSA13', 'NK26-1000m', 13, '383', 'Jennifer Bollen',            'DSA'),
  ('NK26-1000m-DSA14', 'NK26-1000m', 14, '320', 'Lataesha Narain',            'DSA'),
  ('NK26-1000m-DSA15', 'NK26-1000m', 15, '316', 'Amber van der Meijden',      'DSA'),
  ('NK26-1000m-DSA16', 'NK26-1000m', 16, '255', 'Quirine Krommendijk',        'DSA'),
  ('NK26-1000m-DSA17', 'NK26-1000m', 17, '265', 'Mayke Vriesinga',            'DSA'),
  ('NK26-1000m-DSA18', 'NK26-1000m', 18, '124', 'Miriam Gossen',              'DSA'),
  ('NK26-1000m-DSA19', 'NK26-1000m', 19, '378', 'Bo Meijer',                  'DSA'),
  ('NK26-1000m-DSA20', 'NK26-1000m', 20, '802', 'Esther Korenberg',           'DSA'),
  ('NK26-1000m-DSA21', 'NK26-1000m', 21, '352', 'Janne Berkhout',             'DSA'),
  ('NK26-1000m-DSA22', 'NK26-1000m', 22, '390', 'Angel Daleman',              'DSA');

-- ── 2d) Afvalkoers DSA — posities 1..22 ───────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-DSA01', 'NK26-AFV',  1, '152', 'Jet Fransen',                  'DSA'),
  ('NK26-AFV-DSA02', 'NK26-AFV',  2, '179', 'Lianne van Loon',              'DSA'),
  ('NK26-AFV-DSA03', 'NK26-AFV',  3, '235', 'Nikki Noordergraaf',           'DSA'),
  ('NK26-AFV-DSA04', 'NK26-AFV',  4, '150', 'Bianca van der Meer',          'DSA'),
  ('NK26-AFV-DSA05', 'NK26-AFV',  5, '391', 'Elbrich Nicolay',              'DSA'),
  ('NK26-AFV-DSA06', 'NK26-AFV',  6, '269', 'Sanne Yvonne Oosterwijk',      'DSA'),
  ('NK26-AFV-DSA07', 'NK26-AFV',  7, '423', 'Maaike Koelewijn',             'DSA'),
  ('NK26-AFV-DSA08', 'NK26-AFV',  8, '357', 'Elanne de Vries',              'DSA'),
  ('NK26-AFV-DSA09', 'NK26-AFV',  9,  '39', 'Denice Nieuwenhuis',           'DSA'),
  ('NK26-AFV-DSA10', 'NK26-AFV', 10, '265', 'Mayke Vriesinga',              'DSA'),
  ('NK26-AFV-DSA11', 'NK26-AFV', 11, '232', 'Evy van Zoest',                'DSA'),
  ('NK26-AFV-DSA12', 'NK26-AFV', 12, '217', 'Fleur Huls',                   'DSA'),
  ('NK26-AFV-DSA13', 'NK26-AFV', 13, '378', 'Bo Meijer',                    'DSA'),
  ('NK26-AFV-DSA14', 'NK26-AFV', 14, '393', 'Sofia Schilder',               'DSA'),
  ('NK26-AFV-DSA15', 'NK26-AFV', 15, '316', 'Amber van der Meijden',        'DSA'),
  ('NK26-AFV-DSA16', 'NK26-AFV', 16, '255', 'Quirine Krommendijk',          'DSA'),
  ('NK26-AFV-DSA17', 'NK26-AFV', 17, '429', 'Asia Renda',                   'DSA'),
  ('NK26-AFV-DSA18', 'NK26-AFV', 18, '124', 'Miriam Gossen',                'DSA'),
  ('NK26-AFV-DSA19', 'NK26-AFV', 19, '383', 'Jennifer Bollen',              'DSA'),
  ('NK26-AFV-DSA20', 'NK26-AFV', 20, '802', 'Esther Korenberg',             'DSA'),
  ('NK26-AFV-DSA21', 'NK26-AFV', 21, '352', 'Janne Berkhout',               'DSA'),
  ('NK26-AFV-DSA22', 'NK26-AFV', 22, '390', 'Angel Daleman',                'DSA');

-- ── 2e) Puntenkoers DSA — posities 1..21 (Angel ontbreekt als reserve) ───
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-DSA01', 'NK26-PNT',  1, '152', 'Jet Fransen',                  'DSA'),
  ('NK26-PNT-DSA02', 'NK26-PNT',  2, '179', 'Lianne van Loon',              'DSA'),
  ('NK26-PNT-DSA03', 'NK26-PNT',  3, '235', 'Nikki Noordergraaf',           'DSA'),
  ('NK26-PNT-DSA04', 'NK26-PNT',  4, '150', 'Bianca van der Meer',          'DSA'),
  ('NK26-PNT-DSA05', 'NK26-PNT',  5, '391', 'Elbrich Nicolay',              'DSA'),
  ('NK26-PNT-DSA06', 'NK26-PNT',  6, '269', 'Sanne Yvonne Oosterwijk',      'DSA'),
  ('NK26-PNT-DSA07', 'NK26-PNT',  7, '423', 'Maaike Koelewijn',             'DSA'),
  ('NK26-PNT-DSA08', 'NK26-PNT',  8, '357', 'Elanne de Vries',              'DSA'),
  ('NK26-PNT-DSA09', 'NK26-PNT',  9,  '39', 'Denice Nieuwenhuis',           'DSA'),
  ('NK26-PNT-DSA10', 'NK26-PNT', 10, '265', 'Mayke Vriesinga',              'DSA'),
  ('NK26-PNT-DSA11', 'NK26-PNT', 11, '232', 'Evy van Zoest',                'DSA'),
  ('NK26-PNT-DSA12', 'NK26-PNT', 12, '217', 'Fleur Huls',                   'DSA'),
  ('NK26-PNT-DSA13', 'NK26-PNT', 13, '378', 'Bo Meijer',                    'DSA'),
  ('NK26-PNT-DSA14', 'NK26-PNT', 14, '393', 'Sofia Schilder',               'DSA'),
  ('NK26-PNT-DSA15', 'NK26-PNT', 15, '316', 'Amber van der Meijden',        'DSA'),
  ('NK26-PNT-DSA16', 'NK26-PNT', 16, '255', 'Quirine Krommendijk',          'DSA'),
  ('NK26-PNT-DSA17', 'NK26-PNT', 17, '429', 'Asia Renda',                   'DSA'),
  ('NK26-PNT-DSA18', 'NK26-PNT', 18, '124', 'Miriam Gossen',                'DSA'),
  ('NK26-PNT-DSA19', 'NK26-PNT', 19, '383', 'Jennifer Bollen',              'DSA'),
  ('NK26-PNT-DSA20', 'NK26-PNT', 20, '802', 'Esther Korenberg',             'DSA'),
  ('NK26-PNT-DSA21', 'NK26-PNT', 21, '352', 'Janne Berkhout',               'DSA');

-- ── 3a) 200m HSA — posities 1..27 (20 regulier + R1-R7 doorgenummerd) ────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-HSA01', 'NK26-200m',  1, '557', 'Kai-Arne Ottenhoff',           'HSA'),
  ('NK26-200m-HSA02', 'NK26-200m',  2, '444', 'Menno van Eig',                'HSA'),
  ('NK26-200m-HSA03', 'NK26-200m',  3, '559', 'Junior de Blois',              'HSA'),
  ('NK26-200m-HSA04', 'NK26-200m',  4, '146', 'Jarno Haitjema',               'HSA'),
  ('NK26-200m-HSA05', 'NK26-200m',  5, '135', 'Joes van Deursen',             'HSA'),
  ('NK26-200m-HSA06', 'NK26-200m',  6, '556', 'Robin Metz',                   'HSA'),
  ('NK26-200m-HSA07', 'NK26-200m',  7, '257', 'Jelmar Hempenius',             'HSA'),
  ('NK26-200m-HSA08', 'NK26-200m',  8, '328', 'Jorn de Vries',                'HSA'),
  ('NK26-200m-HSA09', 'NK26-200m',  9, '192', 'Glenn Nijenhuis',              'HSA'),
  ('NK26-200m-HSA10', 'NK26-200m', 10, '220', 'Teun de Wit',                  'HSA'),
  ('NK26-200m-HSA11', 'NK26-200m', 11, '405', 'Seth Verbeek',                 'HSA'),
  ('NK26-200m-HSA12', 'NK26-200m', 12,  '59', 'Rick Schipper',                'HSA'),
  ('NK26-200m-HSA13', 'NK26-200m', 13,  '50', 'Teun Schouten',                'HSA'),
  ('NK26-200m-HSA14', 'NK26-200m', 14, '447', 'Bret Groot',                   'HSA'),
  ('NK26-200m-HSA15', 'NK26-200m', 15,   '4', 'Rens Nieuwenhuis',             'HSA'),
  ('NK26-200m-HSA16', 'NK26-200m', 16,  '78', 'Bas Noorloos',                 'HSA'),
  ('NK26-200m-HSA17', 'NK26-200m', 17, '286', 'Maarten Pennings',             'HSA'),
  ('NK26-200m-HSA18', 'NK26-200m', 18, '383', 'Auke-Tjeerd Hiemstra',         'HSA'),
  ('NK26-200m-HSA19', 'NK26-200m', 19, '558', 'Steyn Wagenaar',               'HSA'),
  ('NK26-200m-HSA20', 'NK26-200m', 20, '510', 'Chris Berkhout (AW ITA)',      'HSA'),
  ('NK26-200m-HSA21', 'NK26-200m', 21, '218', 'Janno Botman',                 'HSA'),
  ('NK26-200m-HSA22', 'NK26-200m', 22, '534', 'Niels Teunissen',              'HSA'),
  ('NK26-200m-HSA23', 'NK26-200m', 23, '207', 'Cas Van Deuren',               'HSA'),
  ('NK26-200m-HSA24', 'NK26-200m', 24,  '86', 'Twan Berlijn',                 'HSA'),
  ('NK26-200m-HSA25', 'NK26-200m', 25, '523', 'Niels Pennings',               'HSA'),
  ('NK26-200m-HSA26', 'NK26-200m', 26,  '38', 'Tiemen Haaring',               'HSA'),
  ('NK26-200m-HSA27', 'NK26-200m', 27, '389', 'Stefan Westenbroek',           'HSA');

-- ── 3b) 500m HSA — posities 1..26 ────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-HSA01', 'NK26-500m',  1, '557', 'Kai-Arne Ottenhoff',           'HSA'),
  ('NK26-500m-HSA02', 'NK26-500m',  2, '444', 'Menno van Eig',                'HSA'),
  ('NK26-500m-HSA03', 'NK26-500m',  3, '559', 'Junior de Blois',              'HSA'),
  ('NK26-500m-HSA04', 'NK26-500m',  4, '146', 'Jarno Haitjema',               'HSA'),
  ('NK26-500m-HSA05', 'NK26-500m',  5, '135', 'Joes van Deursen',             'HSA'),
  ('NK26-500m-HSA06', 'NK26-500m',  6, '556', 'Robin Metz',                   'HSA'),
  ('NK26-500m-HSA07', 'NK26-500m',  7, '257', 'Jelmar Hempenius',             'HSA'),
  ('NK26-500m-HSA08', 'NK26-500m',  8, '328', 'Jorn de Vries',                'HSA'),
  ('NK26-500m-HSA09', 'NK26-500m',  9, '192', 'Glenn Nijenhuis',              'HSA'),
  ('NK26-500m-HSA10', 'NK26-500m', 10, '220', 'Teun de Wit',                  'HSA'),
  ('NK26-500m-HSA11', 'NK26-500m', 11, '405', 'Seth Verbeek',                 'HSA'),
  ('NK26-500m-HSA12', 'NK26-500m', 12,  '59', 'Rick Schipper',                'HSA'),
  ('NK26-500m-HSA13', 'NK26-500m', 13,  '50', 'Teun Schouten',                'HSA'),
  ('NK26-500m-HSA14', 'NK26-500m', 14, '447', 'Bret Groot',                   'HSA'),
  ('NK26-500m-HSA15', 'NK26-500m', 15,   '4', 'Rens Nieuwenhuis',             'HSA'),
  ('NK26-500m-HSA16', 'NK26-500m', 16,  '78', 'Bas Noorloos',                 'HSA'),
  ('NK26-500m-HSA17', 'NK26-500m', 17, '286', 'Maarten Pennings',             'HSA'),
  ('NK26-500m-HSA18', 'NK26-500m', 18, '383', 'Auke-Tjeerd Hiemstra',         'HSA'),
  ('NK26-500m-HSA19', 'NK26-500m', 19, '558', 'Steyn Wagenaar',               'HSA'),
  ('NK26-500m-HSA20', 'NK26-500m', 20, '534', 'Niels Teunissen',              'HSA'),
  ('NK26-500m-HSA21', 'NK26-500m', 21, '207', 'Cas Van Deuren',               'HSA'),
  ('NK26-500m-HSA22', 'NK26-500m', 22,  '86', 'Twan Berlijn',                 'HSA'),
  ('NK26-500m-HSA23', 'NK26-500m', 23, '523', 'Niels Pennings',               'HSA'),
  ('NK26-500m-HSA24', 'NK26-500m', 24, '510', 'Chris Berkhout (AW ITA)',      'HSA'),
  ('NK26-500m-HSA25', 'NK26-500m', 25,  '38', 'Tiemen Haaring',               'HSA'),
  ('NK26-500m-HSA26', 'NK26-500m', 26, '108', 'Kayo Vos',                     'HSA');

-- ── 3c) 1000m HSA — posities 1..31 ───────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-HSA01', 'NK26-1000m',  1, '557', 'Kai-Arne Ottenhoff',         'HSA'),
  ('NK26-1000m-HSA02', 'NK26-1000m',  2, '444', 'Menno van Eig',              'HSA'),
  ('NK26-1000m-HSA03', 'NK26-1000m',  3, '559', 'Junior de Blois',            'HSA'),
  ('NK26-1000m-HSA04', 'NK26-1000m',  4, '146', 'Jarno Haitjema',             'HSA'),
  ('NK26-1000m-HSA05', 'NK26-1000m',  5, '135', 'Joes van Deursen',           'HSA'),
  ('NK26-1000m-HSA06', 'NK26-1000m',  6, '556', 'Robin Metz',                 'HSA'),
  ('NK26-1000m-HSA07', 'NK26-1000m',  7, '257', 'Jelmar Hempenius',           'HSA'),
  ('NK26-1000m-HSA08', 'NK26-1000m',  8, '328', 'Jorn de Vries',              'HSA'),
  ('NK26-1000m-HSA09', 'NK26-1000m',  9, '192', 'Glenn Nijenhuis',            'HSA'),
  ('NK26-1000m-HSA10', 'NK26-1000m', 10, '220', 'Teun de Wit',                'HSA'),
  ('NK26-1000m-HSA11', 'NK26-1000m', 11, '405', 'Seth Verbeek',               'HSA'),
  ('NK26-1000m-HSA12', 'NK26-1000m', 12,  '59', 'Rick Schipper',              'HSA'),
  ('NK26-1000m-HSA13', 'NK26-1000m', 13,  '50', 'Teun Schouten',              'HSA'),
  ('NK26-1000m-HSA14', 'NK26-1000m', 14, '447', 'Bret Groot',                 'HSA'),
  ('NK26-1000m-HSA15', 'NK26-1000m', 15,   '4', 'Rens Nieuwenhuis',           'HSA'),
  ('NK26-1000m-HSA16', 'NK26-1000m', 16,  '78', 'Bas Noorloos',               'HSA'),
  ('NK26-1000m-HSA17', 'NK26-1000m', 17, '286', 'Maarten Pennings',           'HSA'),
  ('NK26-1000m-HSA18', 'NK26-1000m', 18, '383', 'Auke-Tjeerd Hiemstra',       'HSA'),
  ('NK26-1000m-HSA19', 'NK26-1000m', 19, '558', 'Steyn Wagenaar',             'HSA'),
  ('NK26-1000m-HSA20', 'NK26-1000m', 20, '534', 'Niels Teunissen',            'HSA'),
  ('NK26-1000m-HSA21', 'NK26-1000m', 21, '207', 'Cas Van Deuren',             'HSA'),
  ('NK26-1000m-HSA22', 'NK26-1000m', 22,  '86', 'Twan Berlijn',               'HSA'),
  ('NK26-1000m-HSA23', 'NK26-1000m', 23, '523', 'Niels Pennings',             'HSA'),
  ('NK26-1000m-HSA24', 'NK26-1000m', 24, '510', 'Chris Berkhout (AW ITA)',    'HSA'),
  ('NK26-1000m-HSA25', 'NK26-1000m', 25,   '7', 'Luc ter Haar',               'HSA'),
  ('NK26-1000m-HSA26', 'NK26-1000m', 26,  '77', 'Ewout Beijeman',             'HSA'),
  ('NK26-1000m-HSA27', 'NK26-1000m', 27, '171', 'Christian Haasjes',          'HSA'),
  ('NK26-1000m-HSA28', 'NK26-1000m', 28, '538', 'Joël Haasjes',               'HSA'),
  ('NK26-1000m-HSA29', 'NK26-1000m', 29, '184', 'Henk van der Gugten',        'HSA'),
  ('NK26-1000m-HSA30', 'NK26-1000m', 30,  '33', 'Ronald Haasjes',             'HSA'),
  ('NK26-1000m-HSA31', 'NK26-1000m', 31,  '38', 'Tiemen Haaring',             'HSA');

-- ── 3d) Afvalkoers HSA — posities 1..29 ──────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-HSA01', 'NK26-AFV',  1, '557', 'Kai-Arne Ottenhoff',           'HSA'),
  ('NK26-AFV-HSA02', 'NK26-AFV',  2, '444', 'Menno van Eig',                'HSA'),
  ('NK26-AFV-HSA03', 'NK26-AFV',  3, '556', 'Robin Metz',                   'HSA'),
  ('NK26-AFV-HSA04', 'NK26-AFV',  4, '405', 'Seth Verbeek',                 'HSA'),
  ('NK26-AFV-HSA05', 'NK26-AFV',  5, '559', 'Junior de Blois',              'HSA'),
  ('NK26-AFV-HSA06', 'NK26-AFV',  6, '146', 'Jarno Haitjema',               'HSA'),
  ('NK26-AFV-HSA07', 'NK26-AFV',  7, '135', 'Joes van Deursen',             'HSA'),
  ('NK26-AFV-HSA08', 'NK26-AFV',  8, '328', 'Jorn de Vries',                'HSA'),
  ('NK26-AFV-HSA09', 'NK26-AFV',  9,  '59', 'Rick Schipper',                'HSA'),
  ('NK26-AFV-HSA10', 'NK26-AFV', 10,  '86', 'Twan Berlijn',                 'HSA'),
  ('NK26-AFV-HSA11', 'NK26-AFV', 11,   '7', 'Luc ter Haar',                 'HSA'),
  ('NK26-AFV-HSA12', 'NK26-AFV', 12, '510', 'Chris Berkhout',               'HSA'),
  ('NK26-AFV-HSA13', 'NK26-AFV', 13, '534', 'Niels Teunissen',              'HSA'),
  ('NK26-AFV-HSA14', 'NK26-AFV', 14, '220', 'Teun de Wit',                  'HSA'),
  ('NK26-AFV-HSA15', 'NK26-AFV', 15, '447', 'Bret Groot',                   'HSA'),
  ('NK26-AFV-HSA16', 'NK26-AFV', 16,  '77', 'Ewout Beijeman',               'HSA'),
  ('NK26-AFV-HSA17', 'NK26-AFV', 17, '286', 'Maarten Pennings',             'HSA'),
  ('NK26-AFV-HSA18', 'NK26-AFV', 18,  '78', 'Bas Noorloos',                 'HSA'),
  ('NK26-AFV-HSA19', 'NK26-AFV', 19, '171', 'Christian Haasjes',            'HSA'),
  ('NK26-AFV-HSA20', 'NK26-AFV', 20, '538', 'Joël Haasjes',                 'HSA'),
  ('NK26-AFV-HSA21', 'NK26-AFV', 21, '383', 'Auke-Tjeerd Hiemstra',         'HSA'),
  ('NK26-AFV-HSA22', 'NK26-AFV', 22, '184', 'Henk van der Gugten',          'HSA'),
  ('NK26-AFV-HSA23', 'NK26-AFV', 23, '523', 'Niels Pennings',               'HSA'),
  ('NK26-AFV-HSA24', 'NK26-AFV', 24,  '33', 'Ronald Haasjes',               'HSA'),
  ('NK26-AFV-HSA25', 'NK26-AFV', 25, '558', 'Steyn Wagenaar',               'HSA'),
  ('NK26-AFV-HSA26', 'NK26-AFV', 26,   '4', 'Rens Nieuwenhuis',             'HSA'),
  ('NK26-AFV-HSA27', 'NK26-AFV', 27,  '38', 'Tiemen Haaring',               'HSA'),
  ('NK26-AFV-HSA28', 'NK26-AFV', 28, '207', 'Cas Van Deuren',               'HSA'),
  ('NK26-AFV-HSA29', 'NK26-AFV', 29, '257', 'Jelmar Hempenius',             'HSA');

-- ── 3e) Puntenkoers HSA — posities 1..29 (identiek aan Afval) ────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-HSA01', 'NK26-PNT',  1, '557', 'Kai-Arne Ottenhoff',           'HSA'),
  ('NK26-PNT-HSA02', 'NK26-PNT',  2, '444', 'Menno van Eig',                'HSA'),
  ('NK26-PNT-HSA03', 'NK26-PNT',  3, '556', 'Robin Metz',                   'HSA'),
  ('NK26-PNT-HSA04', 'NK26-PNT',  4, '405', 'Seth Verbeek',                 'HSA'),
  ('NK26-PNT-HSA05', 'NK26-PNT',  5, '559', 'Junior de Blois',              'HSA'),
  ('NK26-PNT-HSA06', 'NK26-PNT',  6, '146', 'Jarno Haitjema',               'HSA'),
  ('NK26-PNT-HSA07', 'NK26-PNT',  7, '135', 'Joes van Deursen',             'HSA'),
  ('NK26-PNT-HSA08', 'NK26-PNT',  8, '328', 'Jorn de Vries',                'HSA'),
  ('NK26-PNT-HSA09', 'NK26-PNT',  9,  '59', 'Rick Schipper',                'HSA'),
  ('NK26-PNT-HSA10', 'NK26-PNT', 10,  '86', 'Twan Berlijn',                 'HSA'),
  ('NK26-PNT-HSA11', 'NK26-PNT', 11,   '7', 'Luc ter Haar',                 'HSA'),
  ('NK26-PNT-HSA12', 'NK26-PNT', 12, '510', 'Chris Berkhout',               'HSA'),
  ('NK26-PNT-HSA13', 'NK26-PNT', 13, '534', 'Niels Teunissen',              'HSA'),
  ('NK26-PNT-HSA14', 'NK26-PNT', 14, '220', 'Teun de Wit',                  'HSA'),
  ('NK26-PNT-HSA15', 'NK26-PNT', 15, '447', 'Bret Groot',                   'HSA'),
  ('NK26-PNT-HSA16', 'NK26-PNT', 16,  '77', 'Ewout Beijeman',               'HSA'),
  ('NK26-PNT-HSA17', 'NK26-PNT', 17, '286', 'Maarten Pennings',             'HSA'),
  ('NK26-PNT-HSA18', 'NK26-PNT', 18,  '78', 'Bas Noorloos',                 'HSA'),
  ('NK26-PNT-HSA19', 'NK26-PNT', 19, '171', 'Christian Haasjes',            'HSA'),
  ('NK26-PNT-HSA20', 'NK26-PNT', 20, '538', 'Joël Haasjes',                 'HSA'),
  ('NK26-PNT-HSA21', 'NK26-PNT', 21, '383', 'Auke-Tjeerd Hiemstra',         'HSA'),
  ('NK26-PNT-HSA22', 'NK26-PNT', 22, '184', 'Henk van der Gugten',          'HSA'),
  ('NK26-PNT-HSA23', 'NK26-PNT', 23, '523', 'Niels Pennings',               'HSA'),
  ('NK26-PNT-HSA24', 'NK26-PNT', 24,  '33', 'Ronald Haasjes',               'HSA'),
  ('NK26-PNT-HSA25', 'NK26-PNT', 25, '558', 'Steyn Wagenaar',               'HSA'),
  ('NK26-PNT-HSA26', 'NK26-PNT', 26,   '4', 'Rens Nieuwenhuis',             'HSA'),
  ('NK26-PNT-HSA27', 'NK26-PNT', 27,  '38', 'Tiemen Haaring',               'HSA'),
  ('NK26-PNT-HSA28', 'NK26-PNT', 28, '207', 'Cas Van Deuren',               'HSA'),
  ('NK26-PNT-HSA29', 'NK26-PNT', 29, '257', 'Jelmar Hempenius',             'HSA');
