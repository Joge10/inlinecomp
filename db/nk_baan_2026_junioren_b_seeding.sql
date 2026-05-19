-- ============================================================
--  NK Baan 2026 — Seeding-klassementen Junioren B (DJB + HJB)
--
--  Voortbouwend op nk_baan_2026_kadetten_seeding.sql — die moet eerst
--  gedraaid zijn (anders missen de klassementen-rijen waar we hieronder
--  posities aan toevoegen).
--
--  Wijzigingen:
--   1) UPDATE categorieen-JSON van bestaande klassementen → ook DJB/HJB
--   2) INSERT klassement_posities voor DJB (32/31 per afstand)
--   3) INSERT klassement_posities voor HJB (23 per afstand)
--
--  Bron: KNSB Deelnemerslijsten Junioren B NK Baan 2026.
--
--  Bijzonderheden (alle bevestigd door operator):
--   - DJB 500m: 168 Liese van der Laan op positie 19 (ontbrak in PDF;
--     ofwel KNSB-omissie, ofwel afmelding — geseed voor de zekerheid).
--     Rest van pos 20+ schuift met 1 op.
--   - DJB Afval/Punten: 11 Jennifer Schipper ontbreekt (niet ingeschreven
--     voor de twee puntenkoersen).
--   - DJB Afvalkoers pos 20: 22 Kailey van der Veen (AW) — wildcard;
--     in puntenkoers staat ze op pos 22 zonder AW (op eigen kracht
--     ge-seleecteerd voor puntenkoers).
--   - HJB: 23 rijders ge-seeded (top van veld; KNSB-X-rijen na 23
--     krijgen automatisch hun plek achteraan op startnummer).
-- ============================================================

-- ── 1) Categorieen-metadata van bestaande klassementen uitbreiden ──────────
-- Niet-functioneel veld (alleen voor UI-weergave). Functionaliteit drijft
-- volledig op klassement_posities.categorie. Update voor compleetheid:
UPDATE klassementen
   SET categorieen   = '["DKA","HKA","DJB","HJB"]',
       totaal_rijders = totaal_rijders + 55
 WHERE id IN ('NK26-200m','NK26-500m','NK26-1000m','NK26-AFV','NK26-PNT');

-- ── 2a) 200m DJB — posities 1..32 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-DJB01', 'NK26-200m',  1, '166', 'Esmee Bonestroo',        'DJB'),
  ('NK26-200m-DJB02', 'NK26-200m',  2, '180', 'Asia Berga',             'DJB'),
  ('NK26-200m-DJB03', 'NK26-200m',  3, '161', 'Lisa Huis in ''t Veld',  'DJB'),
  ('NK26-200m-DJB04', 'NK26-200m',  4, '254', 'Jolien Hazeveld',        'DJB'),
  ('NK26-200m-DJB05', 'NK26-200m',  5,  '66', 'Nina Grootkarzijn',      'DJB'),
  ('NK26-200m-DJB06', 'NK26-200m',  6, '286', 'Sigrid van Es',          'DJB'),
  ('NK26-200m-DJB07', 'NK26-200m',  7, '290', 'Joanne Hendriksen',      'DJB'),
  ('NK26-200m-DJB08', 'NK26-200m',  8, '366', 'Yfke Vreeken',           'DJB'),
  ('NK26-200m-DJB09', 'NK26-200m',  9,  '89', 'Annemijn Joosten',       'DJB'),
  ('NK26-200m-DJB10', 'NK26-200m', 10,  '42', 'Maartje Berkhout',       'DJB'),
  ('NK26-200m-DJB11', 'NK26-200m', 11, '155', 'Lauren van den Brink',   'DJB'),
  ('NK26-200m-DJB12', 'NK26-200m', 12, '386', 'Feline Scholten',        'DJB'),
  ('NK26-200m-DJB13', 'NK26-200m', 13, '396', 'Kyra Kamstra',           'DJB'),
  ('NK26-200m-DJB14', 'NK26-200m', 14, '260', 'Beaudine Kielstra',      'DJB'),
  ('NK26-200m-DJB15', 'NK26-200m', 15, '214', 'Esmee Klouth',           'DJB'),
  ('NK26-200m-DJB16', 'NK26-200m', 16,  '96', 'Fleur Hartveld',         'DJB'),
  ('NK26-200m-DJB17', 'NK26-200m', 17,  '22', 'Kailey van der Veen',    'DJB'),
  ('NK26-200m-DJB18', 'NK26-200m', 18, '377', 'Dionne Geerdink',        'DJB'),
  ('NK26-200m-DJB19', 'NK26-200m', 19, '168', 'Liese van der Laan',     'DJB'),
  ('NK26-200m-DJB20', 'NK26-200m', 20,  '72', 'Fleur van de Beek',      'DJB'),
  ('NK26-200m-DJB21', 'NK26-200m', 21, '128', 'Aafke Altena',           'DJB'),
  ('NK26-200m-DJB22', 'NK26-200m', 22, '115', 'Sara van der Goes',      'DJB'),
  ('NK26-200m-DJB23', 'NK26-200m', 23, '109', 'Indy Vader',             'DJB'),
  ('NK26-200m-DJB24', 'NK26-200m', 24, '256', 'Famke Aukema',           'DJB'),
  ('NK26-200m-DJB25', 'NK26-200m', 25, '340', 'Feline van de Brandhof', 'DJB'),
  ('NK26-200m-DJB26', 'NK26-200m', 26, '125', 'Kim Kuiper',             'DJB'),
  ('NK26-200m-DJB27', 'NK26-200m', 27, '184', 'Veerle Zunnebeld',       'DJB'),
  ('NK26-200m-DJB28', 'NK26-200m', 28, '480', 'Maya de Jong',           'DJB'),
  ('NK26-200m-DJB29', 'NK26-200m', 29, '240', 'Merle Markvoort',        'DJB'),
  ('NK26-200m-DJB30', 'NK26-200m', 30,  '12', 'Marije de Haan',         'DJB'),
  ('NK26-200m-DJB31', 'NK26-200m', 31, '335', 'Mira van Gortel',        'DJB'),
  ('NK26-200m-DJB32', 'NK26-200m', 32,  '11', 'Jennifer Schipper',      'DJB');

-- ── 2b) 200m HJB — posities 1..23 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-200m-HJB01', 'NK26-200m',  1,  '76', 'Bernt Hut',                  'HJB'),
  ('NK26-200m-HJB02', 'NK26-200m',  2,  '87', 'Koen Roeloffzen',            'HJB'),
  ('NK26-200m-HJB03', 'NK26-200m',  3, '230', 'Floris-Jacob Toutenhoofd',   'HJB'),
  ('NK26-200m-HJB04', 'NK26-200m',  4, '114', 'Ryan Draaisma',              'HJB'),
  ('NK26-200m-HJB05', 'NK26-200m',  5, '272', 'Huib de Vries',              'HJB'),
  ('NK26-200m-HJB06', 'NK26-200m',  6, '303', 'Leander Maas',               'HJB'),
  ('NK26-200m-HJB07', 'NK26-200m',  7, '289', 'Ruud de Groote',             'HJB'),
  ('NK26-200m-HJB08', 'NK26-200m',  8, '314', 'Nils Wagenaar',              'HJB'),
  ('NK26-200m-HJB09', 'NK26-200m',  9,  '95', 'Thom Kettelarij',            'HJB'),
  ('NK26-200m-HJB10', 'NK26-200m', 10, '219', 'Ties van Liere',             'HJB'),
  ('NK26-200m-HJB11', 'NK26-200m', 11, '305', 'Joas van der Vaart',         'HJB'),
  ('NK26-200m-HJB12', 'NK26-200m', 12,  '29', 'Björn Bouwman',              'HJB'),
  ('NK26-200m-HJB13', 'NK26-200m', 13,  '40', 'Hidde van der Leij',         'HJB'),
  ('NK26-200m-HJB14', 'NK26-200m', 14, '140', 'Rick Van de Pol',            'HJB'),
  ('NK26-200m-HJB15', 'NK26-200m', 15,  '55', 'Bjarne den Besten',          'HJB'),
  ('NK26-200m-HJB16', 'NK26-200m', 16, '166', 'Jort van Diemen',            'HJB'),
  ('NK26-200m-HJB17', 'NK26-200m', 17,  '47', 'Tobias Pleij',               'HJB'),
  ('NK26-200m-HJB18', 'NK26-200m', 18, '161', 'Noah Zwarthoed',             'HJB'),
  ('NK26-200m-HJB19', 'NK26-200m', 19,  '22', 'Jan Æesge van der Meer',     'HJB'),
  ('NK26-200m-HJB20', 'NK26-200m', 20,  '89', 'Thijmen Baeten',             'HJB'),
  ('NK26-200m-HJB21', 'NK26-200m', 21,  '79', 'Luuk van de Wal',            'HJB'),
  ('NK26-200m-HJB22', 'NK26-200m', 22, '123', 'Mats Huisman',               'HJB'),
  ('NK26-200m-HJB23', 'NK26-200m', 23,  '58', 'Filippo Renda',              'HJB');

-- ── 3a) 500m DJB — posities 1..32 ──────────────────────────────────────────
-- Liese van der Laan (168) ingelast op pos 19 (ontbrak in KNSB-PDF, geseed
-- voor de zekerheid). Posities vanaf 20 schuiven 1 op t.o.v. de PDF.
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-DJB01', 'NK26-500m',  1, '166', 'Esmee Bonestroo',        'DJB'),
  ('NK26-500m-DJB02', 'NK26-500m',  2, '180', 'Asia Berga',             'DJB'),
  ('NK26-500m-DJB03', 'NK26-500m',  3, '161', 'Lisa Huis in ''t Veld',  'DJB'),
  ('NK26-500m-DJB04', 'NK26-500m',  4, '254', 'Jolien Hazeveld',        'DJB'),
  ('NK26-500m-DJB05', 'NK26-500m',  5,  '66', 'Nina Grootkarzijn',      'DJB'),
  ('NK26-500m-DJB06', 'NK26-500m',  6, '286', 'Sigrid van Es',          'DJB'),
  ('NK26-500m-DJB07', 'NK26-500m',  7, '290', 'Joanne Hendriksen',      'DJB'),
  ('NK26-500m-DJB08', 'NK26-500m',  8, '366', 'Yfke Vreeken',           'DJB'),
  ('NK26-500m-DJB09', 'NK26-500m',  9,  '89', 'Annemijn Joosten',       'DJB'),
  ('NK26-500m-DJB10', 'NK26-500m', 10,  '42', 'Maartje Berkhout',       'DJB'),
  ('NK26-500m-DJB11', 'NK26-500m', 11, '155', 'Lauren van den Brink',   'DJB'),
  ('NK26-500m-DJB12', 'NK26-500m', 12, '386', 'Feline Scholten',        'DJB'),
  ('NK26-500m-DJB13', 'NK26-500m', 13, '396', 'Kyra Kamstra',           'DJB'),
  ('NK26-500m-DJB14', 'NK26-500m', 14, '260', 'Beaudine Kielstra',      'DJB'),
  ('NK26-500m-DJB15', 'NK26-500m', 15, '214', 'Esmee Klouth',           'DJB'),
  ('NK26-500m-DJB16', 'NK26-500m', 16,  '96', 'Fleur Hartveld',         'DJB'),
  ('NK26-500m-DJB17', 'NK26-500m', 17,  '22', 'Kailey van der Veen',    'DJB'),
  ('NK26-500m-DJB18', 'NK26-500m', 18, '377', 'Dionne Geerdink',        'DJB'),
  ('NK26-500m-DJB19', 'NK26-500m', 19, '168', 'Liese van der Laan',     'DJB'),
  ('NK26-500m-DJB20', 'NK26-500m', 20,  '72', 'Fleur van de Beek',      'DJB'),
  ('NK26-500m-DJB21', 'NK26-500m', 21, '128', 'Aafke Altena',           'DJB'),
  ('NK26-500m-DJB22', 'NK26-500m', 22, '115', 'Sara van der Goes',      'DJB'),
  ('NK26-500m-DJB23', 'NK26-500m', 23, '109', 'Indy Vader',             'DJB'),
  ('NK26-500m-DJB24', 'NK26-500m', 24, '256', 'Famke Aukema',           'DJB'),
  ('NK26-500m-DJB25', 'NK26-500m', 25, '340', 'Feline van de Brandhof', 'DJB'),
  ('NK26-500m-DJB26', 'NK26-500m', 26, '125', 'Kim Kuiper',             'DJB'),
  ('NK26-500m-DJB27', 'NK26-500m', 27, '184', 'Veerle Zunnebeld',       'DJB'),
  ('NK26-500m-DJB28', 'NK26-500m', 28, '480', 'Maya de Jong',           'DJB'),
  ('NK26-500m-DJB29', 'NK26-500m', 29, '240', 'Merle Markvoort',        'DJB'),
  ('NK26-500m-DJB30', 'NK26-500m', 30,  '12', 'Marije de Haan',         'DJB'),
  ('NK26-500m-DJB31', 'NK26-500m', 31, '335', 'Mira van Gortel',        'DJB'),
  ('NK26-500m-DJB32', 'NK26-500m', 32,  '11', 'Jennifer Schipper',      'DJB');

-- ── 3b) 500m HJB — posities 1..23 ──────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-500m-HJB01', 'NK26-500m',  1,  '76', 'Bernt Hut',                  'HJB'),
  ('NK26-500m-HJB02', 'NK26-500m',  2,  '87', 'Koen Roeloffzen',            'HJB'),
  ('NK26-500m-HJB03', 'NK26-500m',  3, '230', 'Floris-Jacob Toutenhoofd',   'HJB'),
  ('NK26-500m-HJB04', 'NK26-500m',  4, '114', 'Ryan Draaisma',              'HJB'),
  ('NK26-500m-HJB05', 'NK26-500m',  5, '272', 'Huib de Vries',              'HJB'),
  ('NK26-500m-HJB06', 'NK26-500m',  6, '303', 'Leander Maas',               'HJB'),
  ('NK26-500m-HJB07', 'NK26-500m',  7, '289', 'Ruud de Groote',             'HJB'),
  ('NK26-500m-HJB08', 'NK26-500m',  8, '314', 'Nils Wagenaar',              'HJB'),
  ('NK26-500m-HJB09', 'NK26-500m',  9,  '95', 'Thom Kettelarij',            'HJB'),
  ('NK26-500m-HJB10', 'NK26-500m', 10, '219', 'Ties van Liere',             'HJB'),
  ('NK26-500m-HJB11', 'NK26-500m', 11, '305', 'Joas van der Vaart',         'HJB'),
  ('NK26-500m-HJB12', 'NK26-500m', 12,  '29', 'Björn Bouwman',              'HJB'),
  ('NK26-500m-HJB13', 'NK26-500m', 13,  '40', 'Hidde van der Leij',         'HJB'),
  ('NK26-500m-HJB14', 'NK26-500m', 14, '140', 'Rick Van de Pol',            'HJB'),
  ('NK26-500m-HJB15', 'NK26-500m', 15,  '55', 'Bjarne den Besten',          'HJB'),
  ('NK26-500m-HJB16', 'NK26-500m', 16, '166', 'Jort van Diemen',            'HJB'),
  ('NK26-500m-HJB17', 'NK26-500m', 17,  '47', 'Tobias Pleij',               'HJB'),
  ('NK26-500m-HJB18', 'NK26-500m', 18, '161', 'Noah Zwarthoed',             'HJB'),
  ('NK26-500m-HJB19', 'NK26-500m', 19,  '22', 'Jan Æesge van der Meer',     'HJB'),
  ('NK26-500m-HJB20', 'NK26-500m', 20,  '89', 'Thijmen Baeten',             'HJB'),
  ('NK26-500m-HJB21', 'NK26-500m', 21,  '79', 'Luuk van de Wal',            'HJB'),
  ('NK26-500m-HJB22', 'NK26-500m', 22, '123', 'Mats Huisman',               'HJB'),
  ('NK26-500m-HJB23', 'NK26-500m', 23,  '58', 'Filippo Renda',              'HJB');

-- ── 4a) 1000m DJB — posities 1..32 ─────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-DJB01', 'NK26-1000m',  1, '166', 'Esmee Bonestroo',        'DJB'),
  ('NK26-1000m-DJB02', 'NK26-1000m',  2, '180', 'Asia Berga',             'DJB'),
  ('NK26-1000m-DJB03', 'NK26-1000m',  3, '161', 'Lisa Huis in ''t Veld',  'DJB'),
  ('NK26-1000m-DJB04', 'NK26-1000m',  4, '254', 'Jolien Hazeveld',        'DJB'),
  ('NK26-1000m-DJB05', 'NK26-1000m',  5,  '66', 'Nina Grootkarzijn',      'DJB'),
  ('NK26-1000m-DJB06', 'NK26-1000m',  6, '286', 'Sigrid van Es',          'DJB'),
  ('NK26-1000m-DJB07', 'NK26-1000m',  7, '290', 'Joanne Hendriksen',      'DJB'),
  ('NK26-1000m-DJB08', 'NK26-1000m',  8, '366', 'Yfke Vreeken',           'DJB'),
  ('NK26-1000m-DJB09', 'NK26-1000m',  9,  '89', 'Annemijn Joosten',       'DJB'),
  ('NK26-1000m-DJB10', 'NK26-1000m', 10,  '42', 'Maartje Berkhout',       'DJB'),
  ('NK26-1000m-DJB11', 'NK26-1000m', 11, '155', 'Lauren van den Brink',   'DJB'),
  ('NK26-1000m-DJB12', 'NK26-1000m', 12, '386', 'Feline Scholten',        'DJB'),
  ('NK26-1000m-DJB13', 'NK26-1000m', 13, '396', 'Kyra Kamstra',           'DJB'),
  ('NK26-1000m-DJB14', 'NK26-1000m', 14, '260', 'Beaudine Kielstra',      'DJB'),
  ('NK26-1000m-DJB15', 'NK26-1000m', 15, '214', 'Esmee Klouth',           'DJB'),
  ('NK26-1000m-DJB16', 'NK26-1000m', 16,  '96', 'Fleur Hartveld',         'DJB'),
  ('NK26-1000m-DJB17', 'NK26-1000m', 17,  '22', 'Kailey van der Veen',    'DJB'),
  ('NK26-1000m-DJB18', 'NK26-1000m', 18, '377', 'Dionne Geerdink',        'DJB'),
  ('NK26-1000m-DJB19', 'NK26-1000m', 19, '168', 'Liese van der Laan',     'DJB'),
  ('NK26-1000m-DJB20', 'NK26-1000m', 20,  '72', 'Fleur van de Beek',      'DJB'),
  ('NK26-1000m-DJB21', 'NK26-1000m', 21, '128', 'Aafke Altena',           'DJB'),
  ('NK26-1000m-DJB22', 'NK26-1000m', 22, '115', 'Sara van der Goes',      'DJB'),
  ('NK26-1000m-DJB23', 'NK26-1000m', 23, '109', 'Indy Vader',             'DJB'),
  ('NK26-1000m-DJB24', 'NK26-1000m', 24, '256', 'Famke Aukema',           'DJB'),
  ('NK26-1000m-DJB25', 'NK26-1000m', 25, '340', 'Feline van de Brandhof', 'DJB'),
  ('NK26-1000m-DJB26', 'NK26-1000m', 26, '480', 'Maya de Jong',           'DJB'),
  ('NK26-1000m-DJB27', 'NK26-1000m', 27, '125', 'Kim Kuiper',             'DJB'),
  ('NK26-1000m-DJB28', 'NK26-1000m', 28, '184', 'Veerle Zunnebeld',       'DJB'),
  ('NK26-1000m-DJB29', 'NK26-1000m', 29, '240', 'Merle Markvoort',        'DJB'),
  ('NK26-1000m-DJB30', 'NK26-1000m', 30,  '12', 'Marije de Haan',         'DJB'),
  ('NK26-1000m-DJB31', 'NK26-1000m', 31, '335', 'Mira van Gortel',        'DJB'),
  ('NK26-1000m-DJB32', 'NK26-1000m', 32,  '11', 'Jennifer Schipper',      'DJB');

-- ── 4b) 1000m HJB — posities 1..23 ─────────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-1000m-HJB01', 'NK26-1000m',  1,  '76', 'Bernt Hut',                  'HJB'),
  ('NK26-1000m-HJB02', 'NK26-1000m',  2,  '87', 'Koen Roeloffzen',            'HJB'),
  ('NK26-1000m-HJB03', 'NK26-1000m',  3, '230', 'Floris-Jacob Toutenhoofd',   'HJB'),
  ('NK26-1000m-HJB04', 'NK26-1000m',  4, '114', 'Ryan Draaisma',              'HJB'),
  ('NK26-1000m-HJB05', 'NK26-1000m',  5, '272', 'Huib de Vries',              'HJB'),
  ('NK26-1000m-HJB06', 'NK26-1000m',  6, '303', 'Leander Maas',               'HJB'),
  ('NK26-1000m-HJB07', 'NK26-1000m',  7, '289', 'Ruud de Groote',             'HJB'),
  ('NK26-1000m-HJB08', 'NK26-1000m',  8, '314', 'Nils Wagenaar',              'HJB'),
  ('NK26-1000m-HJB09', 'NK26-1000m',  9,  '95', 'Thom Kettelarij',            'HJB'),
  ('NK26-1000m-HJB10', 'NK26-1000m', 10, '219', 'Ties van Liere',             'HJB'),
  ('NK26-1000m-HJB11', 'NK26-1000m', 11, '305', 'Joas van der Vaart',         'HJB'),
  ('NK26-1000m-HJB12', 'NK26-1000m', 12,  '29', 'Björn Bouwman',              'HJB'),
  ('NK26-1000m-HJB13', 'NK26-1000m', 13,  '40', 'Hidde van der Leij',         'HJB'),
  ('NK26-1000m-HJB14', 'NK26-1000m', 14, '140', 'Rick Van de Pol',            'HJB'),
  ('NK26-1000m-HJB15', 'NK26-1000m', 15,  '55', 'Bjarne den Besten',          'HJB'),
  ('NK26-1000m-HJB16', 'NK26-1000m', 16, '166', 'Jort van Diemen',            'HJB'),
  ('NK26-1000m-HJB17', 'NK26-1000m', 17,  '47', 'Tobias Pleij',               'HJB'),
  ('NK26-1000m-HJB18', 'NK26-1000m', 18, '161', 'Noah Zwarthoed',             'HJB'),
  ('NK26-1000m-HJB19', 'NK26-1000m', 19,  '22', 'Jan Æesge van der Meer',     'HJB'),
  ('NK26-1000m-HJB20', 'NK26-1000m', 20,  '89', 'Thijmen Baeten',             'HJB'),
  ('NK26-1000m-HJB21', 'NK26-1000m', 21,  '79', 'Luuk van de Wal',            'HJB'),
  ('NK26-1000m-HJB22', 'NK26-1000m', 22, '123', 'Mats Huisman',               'HJB'),
  ('NK26-1000m-HJB23', 'NK26-1000m', 23,  '58', 'Filippo Renda',              'HJB');

-- ── 5a) Afvalkoers DJB — posities 1..31 (Jennifer Schipper niet ingeschreven)
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-DJB01', 'NK26-AFV',  1, '396', 'Kyra Kamstra',           'DJB'),
  ('NK26-AFV-DJB02', 'NK26-AFV',  2, '166', 'Esmee Bonestroo',        'DJB'),
  ('NK26-AFV-DJB03', 'NK26-AFV',  3, '254', 'Jolien Hazeveld',        'DJB'),
  ('NK26-AFV-DJB04', 'NK26-AFV',  4,  '66', 'Nina Grootkarzijn',      'DJB'),
  ('NK26-AFV-DJB05', 'NK26-AFV',  5, '155', 'Lauren van den Brink',   'DJB'),
  ('NK26-AFV-DJB06', 'NK26-AFV',  6, '286', 'Sigrid van Es',          'DJB'),
  ('NK26-AFV-DJB07', 'NK26-AFV',  7, '290', 'Joanne Hendriksen',      'DJB'),
  ('NK26-AFV-DJB08', 'NK26-AFV',  8, '366', 'Yfke Vreeken',           'DJB'),
  ('NK26-AFV-DJB09', 'NK26-AFV',  9, '260', 'Beaudine Kielstra',      'DJB'),
  ('NK26-AFV-DJB10', 'NK26-AFV', 10,  '89', 'Annemijn Joosten',       'DJB'),
  ('NK26-AFV-DJB11', 'NK26-AFV', 11,  '42', 'Maartje Berkhout',       'DJB'),
  ('NK26-AFV-DJB12', 'NK26-AFV', 12, '161', 'Lisa Huis in ''t Veld',  'DJB'),
  ('NK26-AFV-DJB13', 'NK26-AFV', 13, '214', 'Esmee Klouth',           'DJB'),
  ('NK26-AFV-DJB14', 'NK26-AFV', 14,  '72', 'Fleur van de Beek',      'DJB'),
  ('NK26-AFV-DJB15', 'NK26-AFV', 15, '180', 'Asia Berga',             'DJB'),
  ('NK26-AFV-DJB16', 'NK26-AFV', 16, '386', 'Feline Scholten',        'DJB'),
  ('NK26-AFV-DJB17', 'NK26-AFV', 17, '115', 'Sara van der Goes',      'DJB'),
  ('NK26-AFV-DJB18', 'NK26-AFV', 18, '377', 'Dionne Geerdink',        'DJB'),
  ('NK26-AFV-DJB19', 'NK26-AFV', 19, '340', 'Feline van de Brandhof', 'DJB'),
  ('NK26-AFV-DJB20', 'NK26-AFV', 20,  '22', 'Kailey van der Veen (AW)', 'DJB'),
  ('NK26-AFV-DJB21', 'NK26-AFV', 21, '480', 'Maya de Jong',           'DJB'),
  ('NK26-AFV-DJB22', 'NK26-AFV', 22, '256', 'Famke Aukema',           'DJB'),
  ('NK26-AFV-DJB23', 'NK26-AFV', 23, '125', 'Kim Kuiper',             'DJB'),
  ('NK26-AFV-DJB24', 'NK26-AFV', 24,  '96', 'Fleur Hartveld',         'DJB'),
  ('NK26-AFV-DJB25', 'NK26-AFV', 25, '168', 'Liese van der Laan',     'DJB'),
  ('NK26-AFV-DJB26', 'NK26-AFV', 26, '240', 'Merle Markvoort',        'DJB'),
  ('NK26-AFV-DJB27', 'NK26-AFV', 27, '109', 'Indy Vader',             'DJB'),
  ('NK26-AFV-DJB28', 'NK26-AFV', 28, '184', 'Veerle Zunnebeld',       'DJB'),
  ('NK26-AFV-DJB29', 'NK26-AFV', 29, '335', 'Mira van Gortel',        'DJB'),
  ('NK26-AFV-DJB30', 'NK26-AFV', 30, '128', 'Aafke Altena',           'DJB'),
  ('NK26-AFV-DJB31', 'NK26-AFV', 31,  '12', 'Marije de Haan',         'DJB');

-- ── 5b) Afvalkoers HJB — posities 1..23 ────────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-AFV-HJB01', 'NK26-AFV',  1,  '76', 'Bernt Hut',                  'HJB'),
  ('NK26-AFV-HJB02', 'NK26-AFV',  2, '219', 'Ties van Liere',             'HJB'),
  ('NK26-AFV-HJB03', 'NK26-AFV',  3, '303', 'Leander Maas',               'HJB'),
  ('NK26-AFV-HJB04', 'NK26-AFV',  4, '272', 'Huib de Vries',              'HJB'),
  ('NK26-AFV-HJB05', 'NK26-AFV',  5,  '87', 'Koen Roeloffzen',            'HJB'),
  ('NK26-AFV-HJB06', 'NK26-AFV',  6, '230', 'Floris-Jacob Toutenhoofd',   'HJB'),
  ('NK26-AFV-HJB07', 'NK26-AFV',  7, '305', 'Joas van der Vaart',         'HJB'),
  ('NK26-AFV-HJB08', 'NK26-AFV',  8, '314', 'Nils Wagenaar',              'HJB'),
  ('NK26-AFV-HJB09', 'NK26-AFV',  9,  '40', 'Hidde van der Leij',         'HJB'),
  ('NK26-AFV-HJB10', 'NK26-AFV', 10, '289', 'Ruud de Groote',             'HJB'),
  ('NK26-AFV-HJB11', 'NK26-AFV', 11, '114', 'Ryan Draaisma',              'HJB'),
  ('NK26-AFV-HJB12', 'NK26-AFV', 12,  '95', 'Thom Kettelarij',            'HJB'),
  ('NK26-AFV-HJB13', 'NK26-AFV', 13,  '29', 'Björn Bouwman',              'HJB'),
  ('NK26-AFV-HJB14', 'NK26-AFV', 14, '140', 'Rick Van de Pol',            'HJB'),
  ('NK26-AFV-HJB15', 'NK26-AFV', 15, '166', 'Jort van Diemen',            'HJB'),
  ('NK26-AFV-HJB16', 'NK26-AFV', 16,  '55', 'Bjarne den Besten',          'HJB'),
  ('NK26-AFV-HJB17', 'NK26-AFV', 17, '161', 'Noah Zwarthoed',             'HJB'),
  ('NK26-AFV-HJB18', 'NK26-AFV', 18,  '47', 'Tobias Pleij',               'HJB'),
  ('NK26-AFV-HJB19', 'NK26-AFV', 19,  '58', 'Filippo Renda',              'HJB'),
  ('NK26-AFV-HJB20', 'NK26-AFV', 20,  '89', 'Thijmen Baeten',             'HJB'),
  ('NK26-AFV-HJB21', 'NK26-AFV', 21,  '22', 'Jan Æesge van der Meer',     'HJB'),
  ('NK26-AFV-HJB22', 'NK26-AFV', 22,  '79', 'Luuk van de Wal',            'HJB'),
  ('NK26-AFV-HJB23', 'NK26-AFV', 23, '123', 'Mats Huisman',               'HJB');

-- ── 6a) Puntenkoers DJB — posities 1..31 (Jennifer Schipper niet ingeschreven)
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-DJB01', 'NK26-PNT',  1, '396', 'Kyra Kamstra',           'DJB'),
  ('NK26-PNT-DJB02', 'NK26-PNT',  2, '166', 'Esmee Bonestroo',        'DJB'),
  ('NK26-PNT-DJB03', 'NK26-PNT',  3, '254', 'Jolien Hazeveld',        'DJB'),
  ('NK26-PNT-DJB04', 'NK26-PNT',  4,  '66', 'Nina Grootkarzijn',      'DJB'),
  ('NK26-PNT-DJB05', 'NK26-PNT',  5, '155', 'Lauren van den Brink',   'DJB'),
  ('NK26-PNT-DJB06', 'NK26-PNT',  6, '286', 'Sigrid van Es',          'DJB'),
  ('NK26-PNT-DJB07', 'NK26-PNT',  7, '290', 'Joanne Hendriksen',      'DJB'),
  ('NK26-PNT-DJB08', 'NK26-PNT',  8, '366', 'Yfke Vreeken',           'DJB'),
  ('NK26-PNT-DJB09', 'NK26-PNT',  9, '260', 'Beaudine Kielstra',      'DJB'),
  ('NK26-PNT-DJB10', 'NK26-PNT', 10,  '89', 'Annemijn Joosten',       'DJB'),
  ('NK26-PNT-DJB11', 'NK26-PNT', 11,  '42', 'Maartje Berkhout',       'DJB'),
  ('NK26-PNT-DJB12', 'NK26-PNT', 12, '161', 'Lisa Huis in ''t Veld',  'DJB'),
  ('NK26-PNT-DJB13', 'NK26-PNT', 13, '214', 'Esmee Klouth',           'DJB'),
  ('NK26-PNT-DJB14', 'NK26-PNT', 14,  '72', 'Fleur van de Beek',      'DJB'),
  ('NK26-PNT-DJB15', 'NK26-PNT', 15, '180', 'Asia Berga',             'DJB'),
  ('NK26-PNT-DJB16', 'NK26-PNT', 16, '386', 'Feline Scholten',        'DJB'),
  ('NK26-PNT-DJB17', 'NK26-PNT', 17, '115', 'Sara van der Goes',      'DJB'),
  ('NK26-PNT-DJB18', 'NK26-PNT', 18, '377', 'Dionne Geerdink',        'DJB'),
  ('NK26-PNT-DJB19', 'NK26-PNT', 19, '340', 'Feline van de Brandhof', 'DJB'),
  ('NK26-PNT-DJB20', 'NK26-PNT', 20, '480', 'Maya de Jong',           'DJB'),
  ('NK26-PNT-DJB21', 'NK26-PNT', 21, '256', 'Famke Aukema',           'DJB'),
  ('NK26-PNT-DJB22', 'NK26-PNT', 22,  '22', 'Kailey van der Veen',    'DJB'),
  ('NK26-PNT-DJB23', 'NK26-PNT', 23, '125', 'Kim Kuiper',             'DJB'),
  ('NK26-PNT-DJB24', 'NK26-PNT', 24,  '96', 'Fleur Hartveld',         'DJB'),
  ('NK26-PNT-DJB25', 'NK26-PNT', 25, '168', 'Liese van der Laan',     'DJB'),
  ('NK26-PNT-DJB26', 'NK26-PNT', 26, '240', 'Merle Markvoort',        'DJB'),
  ('NK26-PNT-DJB27', 'NK26-PNT', 27, '109', 'Indy Vader',             'DJB'),
  ('NK26-PNT-DJB28', 'NK26-PNT', 28, '184', 'Veerle Zunnebeld',       'DJB'),
  ('NK26-PNT-DJB29', 'NK26-PNT', 29, '335', 'Mira van Gortel',        'DJB'),
  ('NK26-PNT-DJB30', 'NK26-PNT', 30, '128', 'Aafke Altena',           'DJB'),
  ('NK26-PNT-DJB31', 'NK26-PNT', 31,  '12', 'Marije de Haan',         'DJB');

-- ── 6b) Puntenkoers HJB — posities 1..23 ───────────────────────────────────
INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie) VALUES
  ('NK26-PNT-HJB01', 'NK26-PNT',  1,  '76', 'Bernt Hut',                  'HJB'),
  ('NK26-PNT-HJB02', 'NK26-PNT',  2, '219', 'Ties van Liere',             'HJB'),
  ('NK26-PNT-HJB03', 'NK26-PNT',  3, '303', 'Leander Maas',               'HJB'),
  ('NK26-PNT-HJB04', 'NK26-PNT',  4, '272', 'Huib de Vries',              'HJB'),
  ('NK26-PNT-HJB05', 'NK26-PNT',  5,  '87', 'Koen Roeloffzen',            'HJB'),
  ('NK26-PNT-HJB06', 'NK26-PNT',  6, '230', 'Floris-Jacob Toutenhoofd',   'HJB'),
  ('NK26-PNT-HJB07', 'NK26-PNT',  7, '305', 'Joas van der Vaart',         'HJB'),
  ('NK26-PNT-HJB08', 'NK26-PNT',  8, '314', 'Nils Wagenaar',              'HJB'),
  ('NK26-PNT-HJB09', 'NK26-PNT',  9,  '40', 'Hidde van der Leij',         'HJB'),
  ('NK26-PNT-HJB10', 'NK26-PNT', 10, '289', 'Ruud de Groote',             'HJB'),
  ('NK26-PNT-HJB11', 'NK26-PNT', 11, '114', 'Ryan Draaisma',              'HJB'),
  ('NK26-PNT-HJB12', 'NK26-PNT', 12,  '95', 'Thom Kettelarij',            'HJB'),
  ('NK26-PNT-HJB13', 'NK26-PNT', 13,  '29', 'Björn Bouwman',              'HJB'),
  ('NK26-PNT-HJB14', 'NK26-PNT', 14, '140', 'Rick Van de Pol',            'HJB'),
  ('NK26-PNT-HJB15', 'NK26-PNT', 15, '166', 'Jort van Diemen',            'HJB'),
  ('NK26-PNT-HJB16', 'NK26-PNT', 16,  '55', 'Bjarne den Besten',          'HJB'),
  ('NK26-PNT-HJB17', 'NK26-PNT', 17, '161', 'Noah Zwarthoed',             'HJB'),
  ('NK26-PNT-HJB18', 'NK26-PNT', 18,  '47', 'Tobias Pleij',               'HJB'),
  ('NK26-PNT-HJB19', 'NK26-PNT', 19,  '58', 'Filippo Renda',              'HJB'),
  ('NK26-PNT-HJB20', 'NK26-PNT', 20,  '89', 'Thijmen Baeten',             'HJB'),
  ('NK26-PNT-HJB21', 'NK26-PNT', 21,  '22', 'Jan Æesge van der Meer',     'HJB'),
  ('NK26-PNT-HJB22', 'NK26-PNT', 22,  '79', 'Luuk van de Wal',            'HJB'),
  ('NK26-PNT-HJB23', 'NK26-PNT', 23, '123', 'Mats Huisman',               'HJB');
