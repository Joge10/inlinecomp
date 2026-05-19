-- ============================================================
--  NK Baan 2026 — Seeding-klassementen Junioren A (DJA + HJA)
--
--  Voortbouwend op:
--    nk_baan_2026_kadetten_seeding.sql      (verplicht eerst)
--    nk_baan_2026_junioren_b_seeding.sql    (verplicht eerst)
--
--  Wijzigingen:
--   1) UPDATE categorieen-JSON van bestaande klassementen → ook DJA/HJA
--   2) INSERT klassement_posities voor DJA (19 in tijdritten, 18 in
--      afval/punten)
--   3) INSERT klassement_posities voor HJA (19 per afstand)
--
--  Bron: KNSB Deelnemerslijsten Junioren A NK Baan 2026.
--
--  Bijzonderheden (bevestigd door operator):
--   - DJA Afvalkoers/Puntenkoers: 89 Faye Wierdsma niet ge-seeded
--     (heeft nooit landelijke baanwedstrijd op lange afstand gereden;
--     KNSB heeft haar terecht niet in deze lijsten).
--   - Geen R-rijders in JuA-lijsten (kleiner veld dan Kadetten/JuB,
--     KNSB heeft geen reserves aangewezen).
--   - Geen (AW)-wildcards in JuA.
--   - Veld is groter dan ge-seede aantal (PDF zegt "…20 X" of "…30 X"):
--     niet-ge-seede deelnemers krijgen automatisch hun plek achteraan
--     op startnummer via startlijst_genereer.php.
-- ============================================================

-- ── 1) Categorieen-metadata uitbreiden + totaal_rijders bijwerken ──────────
UPDATE klassementen
   SET categorieen   = '["DKA","HKA","DJB","HJB","DJA","HJA"]',
       totaal_rijders = totaal_rijders + CASE id
           WHEN 'NK26-200m'  THEN 38   -- 19 DJA + 19 HJA
           WHEN 'NK26-500m'  THEN 38
           WHEN 'NK26-1000m' THEN 38
           WHEN 'NK26-AFV'   THEN 37   -- 18 DJA (geen Faye) + 19 HJA
           WHEN 'NK26-PNT'   THEN 37
           ELSE 0
       END
 WHERE id IN ('NK26-200m','NK26-500m','NK26-1000m','NK26-AFV','NK26-PNT');

-- ── 2a) 200m DJA — posities 1..19 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-DJA01', 'NK26-200m',  1, '204', 'Pauline Tas',                  'DJA'),
  ('NK26-200m-DJA02', 'NK26-200m',  2, '403', 'Rosa-Lynn Compagner',          'DJA'),
  ('NK26-200m-DJA03', 'NK26-200m',  3,  '50', 'Daphne van Kooten',            'DJA'),
  ('NK26-200m-DJA04', 'NK26-200m',  4, '247', 'Anouk Aalders',                'DJA'),
  ('NK26-200m-DJA05', 'NK26-200m',  5, '241', 'Mirte Kingma',                 'DJA'),
  ('NK26-200m-DJA06', 'NK26-200m',  6, '244', 'Bo Hoogvorst',                 'DJA'),
  ('NK26-200m-DJA07', 'NK26-200m',  7, '233', 'Monique de Groot',             'DJA'),
  ('NK26-200m-DJA08', 'NK26-200m',  8, '112', 'Maud de Jong',                 'DJA'),
  ('NK26-200m-DJA09', 'NK26-200m',  9, '140', 'Vita van Deuren',              'DJA'),
  ('NK26-200m-DJA10', 'NK26-200m', 10, '285', 'Rosan Kuip',                   'DJA'),
  ('NK26-200m-DJA11', 'NK26-200m', 11, '142', 'Janna Wietske van der Ende',   'DJA'),
  ('NK26-200m-DJA12', 'NK26-200m', 12,  '12', 'Jasmijn Nieuwenhuis',          'DJA'),
  ('NK26-200m-DJA13', 'NK26-200m', 13, '154', 'Tessa Bosman',                 'DJA'),
  ('NK26-200m-DJA14', 'NK26-200m', 14, '180', 'Maaike Helleman',              'DJA'),
  ('NK26-200m-DJA15', 'NK26-200m', 15, '113', 'Britt Spenkelink',             'DJA'),
  ('NK26-200m-DJA16', 'NK26-200m', 16,  '67', 'Yfke Raadsveld',               'DJA'),
  ('NK26-200m-DJA17', 'NK26-200m', 17, '283', 'Lisa Otten',                   'DJA'),
  ('NK26-200m-DJA18', 'NK26-200m', 18,  '89', 'Faye Wierdsma',                'DJA'),
  ('NK26-200m-DJA19', 'NK26-200m', 19, '162', 'Eline Kettelarij',             'DJA');

-- ── 2b) 200m HJA — posities 1..19 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-HJA01', 'NK26-200m',  1,  '87', 'Roan Vos',                     'HJA'),
  ('NK26-200m-HJA02', 'NK26-200m',  2, '133', 'Dave van der Born',            'HJA'),
  ('NK26-200m-HJA03', 'NK26-200m',  3, '188', 'Senn Koeman',                  'HJA'),
  ('NK26-200m-HJA04', 'NK26-200m',  4,  '97', 'Lucas Huisman',                'HJA'),
  ('NK26-200m-HJA05', 'NK26-200m',  5,  '42', 'Eli Jansen',                   'HJA'),
  ('NK26-200m-HJA06', 'NK26-200m',  6,  '26', 'Lars Dijck',                   'HJA'),
  ('NK26-200m-HJA07', 'NK26-200m',  7,  '44', 'Thijs Breugem',                'HJA'),
  ('NK26-200m-HJA08', 'NK26-200m',  8, '139', 'Floris Verploeg',              'HJA'),
  ('NK26-200m-HJA09', 'NK26-200m',  9, '412', 'Teije Hekkema',                'HJA'),
  ('NK26-200m-HJA10', 'NK26-200m', 10, '344', 'Sem Spruit',                   'HJA'),
  ('NK26-200m-HJA11', 'NK26-200m', 11, '164', 'Wester van der Heide',         'HJA'),
  ('NK26-200m-HJA12', 'NK26-200m', 12,  '90', 'Mart Leroy',                   'HJA'),
  ('NK26-200m-HJA13', 'NK26-200m', 13,  '58', 'Nick Hendriksen',              'HJA'),
  ('NK26-200m-HJA14', 'NK26-200m', 14, '587', 'Bas Ellenbroek',               'HJA'),
  ('NK26-200m-HJA15', 'NK26-200m', 15,  '25', 'Lars van de Griend',           'HJA'),
  ('NK26-200m-HJA16', 'NK26-200m', 16, '432', 'Simon Wiegman',                'HJA'),
  ('NK26-200m-HJA17', 'NK26-200m', 17,  '56', 'Stijn de Jong',                'HJA'),
  ('NK26-200m-HJA18', 'NK26-200m', 18, '331', 'Jelte Wensveen',               'HJA'),
  ('NK26-200m-HJA19', 'NK26-200m', 19, '196', 'Harmen Last',                  'HJA');

-- ── 3a) 500m DJA — posities 1..19 (gelijk aan 200m) ──────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-DJA01', 'NK26-500m',  1, '204', 'Pauline Tas',                  'DJA'),
  ('NK26-500m-DJA02', 'NK26-500m',  2, '403', 'Rosa-Lynn Compagner',          'DJA'),
  ('NK26-500m-DJA03', 'NK26-500m',  3,  '50', 'Daphne van Kooten',            'DJA'),
  ('NK26-500m-DJA04', 'NK26-500m',  4, '247', 'Anouk Aalders',                'DJA'),
  ('NK26-500m-DJA05', 'NK26-500m',  5, '241', 'Mirte Kingma',                 'DJA'),
  ('NK26-500m-DJA06', 'NK26-500m',  6, '244', 'Bo Hoogvorst',                 'DJA'),
  ('NK26-500m-DJA07', 'NK26-500m',  7, '233', 'Monique de Groot',             'DJA'),
  ('NK26-500m-DJA08', 'NK26-500m',  8, '112', 'Maud de Jong',                 'DJA'),
  ('NK26-500m-DJA09', 'NK26-500m',  9, '140', 'Vita van Deuren',              'DJA'),
  ('NK26-500m-DJA10', 'NK26-500m', 10, '285', 'Rosan Kuip',                   'DJA'),
  ('NK26-500m-DJA11', 'NK26-500m', 11, '142', 'Janna Wietske van der Ende',   'DJA'),
  ('NK26-500m-DJA12', 'NK26-500m', 12,  '12', 'Jasmijn Nieuwenhuis',          'DJA'),
  ('NK26-500m-DJA13', 'NK26-500m', 13, '154', 'Tessa Bosman',                 'DJA'),
  ('NK26-500m-DJA14', 'NK26-500m', 14, '180', 'Maaike Helleman',              'DJA'),
  ('NK26-500m-DJA15', 'NK26-500m', 15, '113', 'Britt Spenkelink',             'DJA'),
  ('NK26-500m-DJA16', 'NK26-500m', 16,  '67', 'Yfke Raadsveld',               'DJA'),
  ('NK26-500m-DJA17', 'NK26-500m', 17, '283', 'Lisa Otten',                   'DJA'),
  ('NK26-500m-DJA18', 'NK26-500m', 18,  '89', 'Faye Wierdsma',                'DJA'),
  ('NK26-500m-DJA19', 'NK26-500m', 19, '162', 'Eline Kettelarij',             'DJA');

-- ── 3b) 500m HJA — posities 1..19 ────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-HJA01', 'NK26-500m',  1,  '87', 'Roan Vos',                     'HJA'),
  ('NK26-500m-HJA02', 'NK26-500m',  2, '133', 'Dave van der Born',            'HJA'),
  ('NK26-500m-HJA03', 'NK26-500m',  3, '188', 'Senn Koeman',                  'HJA'),
  ('NK26-500m-HJA04', 'NK26-500m',  4,  '97', 'Lucas Huisman',                'HJA'),
  ('NK26-500m-HJA05', 'NK26-500m',  5,  '42', 'Eli Jansen',                   'HJA'),
  ('NK26-500m-HJA06', 'NK26-500m',  6,  '26', 'Lars Dijck',                   'HJA'),
  ('NK26-500m-HJA07', 'NK26-500m',  7,  '44', 'Thijs Breugem',                'HJA'),
  ('NK26-500m-HJA08', 'NK26-500m',  8, '139', 'Floris Verploeg',              'HJA'),
  ('NK26-500m-HJA09', 'NK26-500m',  9, '412', 'Teije Hekkema',                'HJA'),
  ('NK26-500m-HJA10', 'NK26-500m', 10, '344', 'Sem Spruit',                   'HJA'),
  ('NK26-500m-HJA11', 'NK26-500m', 11, '164', 'Wester van der Heide',         'HJA'),
  ('NK26-500m-HJA12', 'NK26-500m', 12,  '90', 'Mart Leroy',                   'HJA'),
  ('NK26-500m-HJA13', 'NK26-500m', 13,  '58', 'Nick Hendriksen',              'HJA'),
  ('NK26-500m-HJA14', 'NK26-500m', 14, '587', 'Bas Ellenbroek',               'HJA'),
  ('NK26-500m-HJA15', 'NK26-500m', 15,  '25', 'Lars van de Griend',           'HJA'),
  ('NK26-500m-HJA16', 'NK26-500m', 16, '432', 'Simon Wiegman',                'HJA'),
  ('NK26-500m-HJA17', 'NK26-500m', 17,  '56', 'Stijn de Jong',                'HJA'),
  ('NK26-500m-HJA18', 'NK26-500m', 18, '331', 'Jelte Wensveen',               'HJA'),
  ('NK26-500m-HJA19', 'NK26-500m', 19, '196', 'Harmen Last',                  'HJA');

-- ── 4a) 1000m DJA — posities 1..19 (gelijk aan 200m) ─────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-DJA01', 'NK26-1000m',  1, '204', 'Pauline Tas',                'DJA'),
  ('NK26-1000m-DJA02', 'NK26-1000m',  2, '403', 'Rosa-Lynn Compagner',        'DJA'),
  ('NK26-1000m-DJA03', 'NK26-1000m',  3,  '50', 'Daphne van Kooten',          'DJA'),
  ('NK26-1000m-DJA04', 'NK26-1000m',  4, '247', 'Anouk Aalders',              'DJA'),
  ('NK26-1000m-DJA05', 'NK26-1000m',  5, '241', 'Mirte Kingma',               'DJA'),
  ('NK26-1000m-DJA06', 'NK26-1000m',  6, '244', 'Bo Hoogvorst',               'DJA'),
  ('NK26-1000m-DJA07', 'NK26-1000m',  7, '233', 'Monique de Groot',           'DJA'),
  ('NK26-1000m-DJA08', 'NK26-1000m',  8, '112', 'Maud de Jong',               'DJA'),
  ('NK26-1000m-DJA09', 'NK26-1000m',  9, '140', 'Vita van Deuren',            'DJA'),
  ('NK26-1000m-DJA10', 'NK26-1000m', 10, '285', 'Rosan Kuip',                 'DJA'),
  ('NK26-1000m-DJA11', 'NK26-1000m', 11, '142', 'Janna Wietske van der Ende', 'DJA'),
  ('NK26-1000m-DJA12', 'NK26-1000m', 12,  '12', 'Jasmijn Nieuwenhuis',        'DJA'),
  ('NK26-1000m-DJA13', 'NK26-1000m', 13, '154', 'Tessa Bosman',               'DJA'),
  ('NK26-1000m-DJA14', 'NK26-1000m', 14, '180', 'Maaike Helleman',            'DJA'),
  ('NK26-1000m-DJA15', 'NK26-1000m', 15, '113', 'Britt Spenkelink',           'DJA'),
  ('NK26-1000m-DJA16', 'NK26-1000m', 16,  '67', 'Yfke Raadsveld',             'DJA'),
  ('NK26-1000m-DJA17', 'NK26-1000m', 17, '283', 'Lisa Otten',                 'DJA'),
  ('NK26-1000m-DJA18', 'NK26-1000m', 18,  '89', 'Faye Wierdsma',              'DJA'),
  ('NK26-1000m-DJA19', 'NK26-1000m', 19, '162', 'Eline Kettelarij',           'DJA');

-- ── 4b) 1000m HJA — posities 1..19 ───────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-HJA01', 'NK26-1000m',  1,  '87', 'Roan Vos',                   'HJA'),
  ('NK26-1000m-HJA02', 'NK26-1000m',  2, '133', 'Dave van der Born',          'HJA'),
  ('NK26-1000m-HJA03', 'NK26-1000m',  3, '188', 'Senn Koeman',                'HJA'),
  ('NK26-1000m-HJA04', 'NK26-1000m',  4,  '97', 'Lucas Huisman',              'HJA'),
  ('NK26-1000m-HJA05', 'NK26-1000m',  5,  '42', 'Eli Jansen',                 'HJA'),
  ('NK26-1000m-HJA06', 'NK26-1000m',  6,  '26', 'Lars Dijck',                 'HJA'),
  ('NK26-1000m-HJA07', 'NK26-1000m',  7,  '44', 'Thijs Breugem',              'HJA'),
  ('NK26-1000m-HJA08', 'NK26-1000m',  8, '139', 'Floris Verploeg',            'HJA'),
  ('NK26-1000m-HJA09', 'NK26-1000m',  9, '412', 'Teije Hekkema',              'HJA'),
  ('NK26-1000m-HJA10', 'NK26-1000m', 10, '344', 'Sem Spruit',                 'HJA'),
  ('NK26-1000m-HJA11', 'NK26-1000m', 11, '164', 'Wester van der Heide',       'HJA'),
  ('NK26-1000m-HJA12', 'NK26-1000m', 12,  '90', 'Mart Leroy',                 'HJA'),
  ('NK26-1000m-HJA13', 'NK26-1000m', 13,  '58', 'Nick Hendriksen',            'HJA'),
  ('NK26-1000m-HJA14', 'NK26-1000m', 14, '587', 'Bas Ellenbroek',             'HJA'),
  ('NK26-1000m-HJA15', 'NK26-1000m', 15,  '25', 'Lars van de Griend',         'HJA'),
  ('NK26-1000m-HJA16', 'NK26-1000m', 16, '432', 'Simon Wiegman',              'HJA'),
  ('NK26-1000m-HJA17', 'NK26-1000m', 17,  '56', 'Stijn de Jong',              'HJA'),
  ('NK26-1000m-HJA18', 'NK26-1000m', 18, '331', 'Jelte Wensveen',             'HJA'),
  ('NK26-1000m-HJA19', 'NK26-1000m', 19, '196', 'Harmen Last',                'HJA');

-- ── 5a) Afvalkoers DJA — posities 1..18 (zonder Faye Wierdsma) ───────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-DJA01', 'NK26-AFV',  1, '244', 'Bo Hoogvorst',                 'DJA'),
  ('NK26-AFV-DJA02', 'NK26-AFV',  2, '403', 'Rosa-Lynn Compagner',          'DJA'),
  ('NK26-AFV-DJA03', 'NK26-AFV',  3, '241', 'Mirte Kingma',                 'DJA'),
  ('NK26-AFV-DJA04', 'NK26-AFV',  4, '204', 'Pauline Tas',                  'DJA'),
  ('NK26-AFV-DJA05', 'NK26-AFV',  5, '247', 'Anouk Aalders',                'DJA'),
  ('NK26-AFV-DJA06', 'NK26-AFV',  6, '112', 'Maud de Jong',                 'DJA'),
  ('NK26-AFV-DJA07', 'NK26-AFV',  7, '285', 'Rosan Kuip',                   'DJA'),
  ('NK26-AFV-DJA08', 'NK26-AFV',  8, '142', 'Janna Wietske van der Ende',   'DJA'),
  ('NK26-AFV-DJA09', 'NK26-AFV',  9, '233', 'Monique de Groot',             'DJA'),
  ('NK26-AFV-DJA10', 'NK26-AFV', 10,  '50', 'Daphne van Kooten',            'DJA'),
  ('NK26-AFV-DJA11', 'NK26-AFV', 11,  '12', 'Jasmijn Nieuwenhuis',          'DJA'),
  ('NK26-AFV-DJA12', 'NK26-AFV', 12, '154', 'Tessa Bosman',                 'DJA'),
  ('NK26-AFV-DJA13', 'NK26-AFV', 13, '180', 'Maaike Helleman',              'DJA'),
  ('NK26-AFV-DJA14', 'NK26-AFV', 14, '140', 'Vita van Deuren',              'DJA'),
  ('NK26-AFV-DJA15', 'NK26-AFV', 15, '283', 'Lisa Otten',                   'DJA'),
  ('NK26-AFV-DJA16', 'NK26-AFV', 16,  '67', 'Yfke Raadsveld',               'DJA'),
  ('NK26-AFV-DJA17', 'NK26-AFV', 17, '113', 'Britt Spenkelink',             'DJA'),
  ('NK26-AFV-DJA18', 'NK26-AFV', 18, '162', 'Eline Kettelarij',             'DJA');

-- ── 5b) Afvalkoers HJA — posities 1..19 ──────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-HJA01', 'NK26-AFV',  1,  '87', 'Roan Vos',                     'HJA'),
  ('NK26-AFV-HJA02', 'NK26-AFV',  2, '139', 'Floris Verploeg',              'HJA'),
  ('NK26-AFV-HJA03', 'NK26-AFV',  3, '188', 'Senn Koeman',                  'HJA'),
  ('NK26-AFV-HJA04', 'NK26-AFV',  4,  '26', 'Lars Dijck',                   'HJA'),
  ('NK26-AFV-HJA05', 'NK26-AFV',  5, '133', 'Dave van der Born',            'HJA'),
  ('NK26-AFV-HJA06', 'NK26-AFV',  6,  '97', 'Lucas Huisman',                'HJA'),
  ('NK26-AFV-HJA07', 'NK26-AFV',  7, '432', 'Simon Wiegman',                'HJA'),
  ('NK26-AFV-HJA08', 'NK26-AFV',  8,  '42', 'Eli Jansen',                   'HJA'),
  ('NK26-AFV-HJA09', 'NK26-AFV',  9,  '44', 'Thijs Breugem',                'HJA'),
  ('NK26-AFV-HJA10', 'NK26-AFV', 10,  '58', 'Nick Hendriksen',              'HJA'),
  ('NK26-AFV-HJA11', 'NK26-AFV', 11, '344', 'Sem Spruit',                   'HJA'),
  ('NK26-AFV-HJA12', 'NK26-AFV', 12,  '25', 'Lars van de Griend',           'HJA'),
  ('NK26-AFV-HJA13', 'NK26-AFV', 13,  '90', 'Mart Leroy',                   'HJA'),
  ('NK26-AFV-HJA14', 'NK26-AFV', 14, '412', 'Teije Hekkema',                'HJA'),
  ('NK26-AFV-HJA15', 'NK26-AFV', 15, '587', 'Bas Ellenbroek',               'HJA'),
  ('NK26-AFV-HJA16', 'NK26-AFV', 16, '164', 'Wester van der Heide',         'HJA'),
  ('NK26-AFV-HJA17', 'NK26-AFV', 17,  '56', 'Stijn de Jong',                'HJA'),
  ('NK26-AFV-HJA18', 'NK26-AFV', 18, '196', 'Harmen Last',                  'HJA'),
  ('NK26-AFV-HJA19', 'NK26-AFV', 19, '331', 'Jelte Wensveen',               'HJA');

-- ── 6a) Puntenkoers DJA — posities 1..18 (identiek aan Afval) ─────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-DJA01', 'NK26-PNT',  1, '244', 'Bo Hoogvorst',                 'DJA'),
  ('NK26-PNT-DJA02', 'NK26-PNT',  2, '403', 'Rosa-Lynn Compagner',          'DJA'),
  ('NK26-PNT-DJA03', 'NK26-PNT',  3, '241', 'Mirte Kingma',                 'DJA'),
  ('NK26-PNT-DJA04', 'NK26-PNT',  4, '204', 'Pauline Tas',                  'DJA'),
  ('NK26-PNT-DJA05', 'NK26-PNT',  5, '247', 'Anouk Aalders',                'DJA'),
  ('NK26-PNT-DJA06', 'NK26-PNT',  6, '112', 'Maud de Jong',                 'DJA'),
  ('NK26-PNT-DJA07', 'NK26-PNT',  7, '285', 'Rosan Kuip',                   'DJA'),
  ('NK26-PNT-DJA08', 'NK26-PNT',  8, '142', 'Janna Wietske van der Ende',   'DJA'),
  ('NK26-PNT-DJA09', 'NK26-PNT',  9, '233', 'Monique de Groot',             'DJA'),
  ('NK26-PNT-DJA10', 'NK26-PNT', 10,  '50', 'Daphne van Kooten',            'DJA'),
  ('NK26-PNT-DJA11', 'NK26-PNT', 11,  '12', 'Jasmijn Nieuwenhuis',          'DJA'),
  ('NK26-PNT-DJA12', 'NK26-PNT', 12, '154', 'Tessa Bosman',                 'DJA'),
  ('NK26-PNT-DJA13', 'NK26-PNT', 13, '180', 'Maaike Helleman',              'DJA'),
  ('NK26-PNT-DJA14', 'NK26-PNT', 14, '140', 'Vita van Deuren',              'DJA'),
  ('NK26-PNT-DJA15', 'NK26-PNT', 15, '283', 'Lisa Otten',                   'DJA'),
  ('NK26-PNT-DJA16', 'NK26-PNT', 16,  '67', 'Yfke Raadsveld',               'DJA'),
  ('NK26-PNT-DJA17', 'NK26-PNT', 17, '113', 'Britt Spenkelink',             'DJA'),
  ('NK26-PNT-DJA18', 'NK26-PNT', 18, '162', 'Eline Kettelarij',             'DJA');

-- ── 6b) Puntenkoers HJA — posities 1..19 (identiek aan Afval) ─────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-HJA01', 'NK26-PNT',  1,  '87', 'Roan Vos',                     'HJA'),
  ('NK26-PNT-HJA02', 'NK26-PNT',  2, '139', 'Floris Verploeg',              'HJA'),
  ('NK26-PNT-HJA03', 'NK26-PNT',  3, '188', 'Senn Koeman',                  'HJA'),
  ('NK26-PNT-HJA04', 'NK26-PNT',  4,  '26', 'Lars Dijck',                   'HJA'),
  ('NK26-PNT-HJA05', 'NK26-PNT',  5, '133', 'Dave van der Born',            'HJA'),
  ('NK26-PNT-HJA06', 'NK26-PNT',  6,  '97', 'Lucas Huisman',                'HJA'),
  ('NK26-PNT-HJA07', 'NK26-PNT',  7, '432', 'Simon Wiegman',                'HJA'),
  ('NK26-PNT-HJA08', 'NK26-PNT',  8,  '42', 'Eli Jansen',                   'HJA'),
  ('NK26-PNT-HJA09', 'NK26-PNT',  9,  '44', 'Thijs Breugem',                'HJA'),
  ('NK26-PNT-HJA10', 'NK26-PNT', 10,  '58', 'Nick Hendriksen',              'HJA'),
  ('NK26-PNT-HJA11', 'NK26-PNT', 11, '344', 'Sem Spruit',                   'HJA'),
  ('NK26-PNT-HJA12', 'NK26-PNT', 12,  '25', 'Lars van de Griend',           'HJA'),
  ('NK26-PNT-HJA13', 'NK26-PNT', 13,  '90', 'Mart Leroy',                   'HJA'),
  ('NK26-PNT-HJA14', 'NK26-PNT', 14, '412', 'Teije Hekkema',                'HJA'),
  ('NK26-PNT-HJA15', 'NK26-PNT', 15, '587', 'Bas Ellenbroek',               'HJA'),
  ('NK26-PNT-HJA16', 'NK26-PNT', 16, '164', 'Wester van der Heide',         'HJA'),
  ('NK26-PNT-HJA17', 'NK26-PNT', 17,  '56', 'Stijn de Jong',                'HJA'),
  ('NK26-PNT-HJA18', 'NK26-PNT', 18, '196', 'Harmen Last',                  'HJA'),
  ('NK26-PNT-HJA19', 'NK26-PNT', 19, '331', 'Jelte Wensveen',               'HJA');
