#!/usr/bin/env python3
"""
InlineComp – Klassement PDF parser
Gebruik: python pdf_klassement.py <pad/naar/klassement.pdf>
Output : JSON naar stdout

Ondersteunde formaten
  - KNSB       : "pl nr naam Cat Woonplaats Club …"  (elke pagina = één sectie)
  - JSC        : "Plaats Beennr Naam Vereniging kl cat punten …"  (meerdere cat. per pagina)
  - REGIO      : "categorie: pupillen 4 meisjes" headers; "pl nr naam vereniging scores…"
  - NK_TUSSEN  : "KNSB Baancompetitie / Tussenstand NK deelname" met sectie-
                 koppen als "12 Mannen Senioren Sprint"; rijen zonder pl-kolom
                 ("nr Naam Cat Woonplaats Club Sponsor A1..A5 tot af tot").
                 De volgorde in de PDF = de ranking; de laatste numerieke kolom
                 is de eind-score (som na weggestreepte slechtste).
  - NK_SELECTIE: "Tussenstand selectie NK Weg" — MET losse Lic.Nr.-kolom en
                 meerdere secties per pagina ("1Vrouwen Kadetten Sprint").
                 Rij: "pl Lic.Nr startnr(geplakt)Naam Cat Woonplaats Club …".
                 De pl-kolom is de ranking per (discipline-)sectie.
"""

import sys, re, json

try:
    import pdfplumber
except ImportError:
    print(json.dumps({"error": "pdfplumber niet geinstalleerd (pip install pdfplumber)"}))
    sys.exit(1)

# ── Cat-code → leesbaar label ─────────────────────────────────────────────────
CAT_LABELS = {
    'HJA':'Heren Junior A',  'HJB':'Heren Junior B',
    'HKA':'Heren Kadetten A','HKB':'Heren Kadetten B',
    'HP1':'Heren Pupil 1',   'HP2':'Heren Pupil 2',
    'HP3':'Heren Pupil 3',   'HP4':'Heren Pupil 4',
    'HSA':'Heren Senior A',  'HSJ':'Heren Senior B',
    'DJA':'Dames Junior A',  'DJB':'Dames Junior B',
    'DKA':'Dames Kadetten A','DKB':'Dames Kadetten B',
    'DP1':'Dames Pupil 1',   'DP2':'Dames Pupil 2',
    'DP3':'Dames Pupil 3',   'DP4':'Dames Pupil 4',
    'DSA':'Dames Senior A',  'DSJ':'Dames Senior B',
}

def cat_label(code):
    return CAT_LABELS.get(code, code)

# ═════════════════════════════════════════════════════════════════════════════
# KNSB-formaat parser
# ─────────────────────────────────────────────────────────────────────────────
# Regelpatroon: positie + startnummer (geplakt aan naam) + naam + cat-code
KNSB_REGEL_RE = re.compile(
    r'^(\d{1,3})\s+'
    r'(\d+)'
    r'([A-Z\xc0-\xd6\xd8-\xf6\xf8-\xff]'
    r'[A-Za-z\xc0-\xff\s\'\-\.]+?)'
    r'\s+([HD][A-Z]{1,2}\d?)'    # bv. HSA, HJB, HP1, DP2, HKA
    r'\s'
)
# Sectie-kopregel: verplicht ≥1 cijfer vooraan, bv. "3Dames Junior B", "9Heren Pupillen 1 ."
# Cijfers en koppeltekens in sectienaam (bv. "Pupillen 1", "Junioren A - Senioren") ook toegestaan
# \d+ voorkomt dat document-titels zoals "Klassement" vals matchen
KNSB_SECTIE_RE = re.compile(r'^\d+([A-Z][A-Za-z\s\d\-]{5,})\s*\.?\s*$')

def parse_knsb(pdf):
    secties = {}
    header_info = {}
    sectie_naam = None

    for pagina in pdf.pages:
        txt = pagina.extract_text()
        if not txt:
            continue
        regels = txt.splitlines()

        # Header (eerste pagina)
        if not header_info:
            for regel in regels[:6]:
                r = regel.strip()
                if not r:
                    continue
                if not header_info.get('titel') and len(r) > 5 and not re.match(r'^\d', r):
                    header_info['titel'] = r
                elif not header_info.get('datum') and re.search(r'\d{1,2}\s+\w+\s+\d{4}', r):
                    header_info['datum'] = r

        # Sectie-naam detecteren (bv. "3Dames Junior B", "7Heren Lange afstand")
        # Eis: moet minstens één kleine letter bevatten (sluit "KLASSEMENT" uit)
        pagina_sectie = None
        for regel in regels:
            r = regel.strip()
            m = KNSB_SECTIE_RE.match(r)
            if m:
                kandidaat = m.group(1).strip()
                if re.search(r'[a-z]', kandidaat):   # echte sectienaam, geen header-caps
                    pagina_sectie = kandidaat
                    break
        if pagina_sectie:
            sectie_naam = pagina_sectie

        # Rijders
        for regel in regels:
            m = KNSB_REGEL_RE.match(regel.strip())
            if not m:
                continue
            positie = int(m.group(1))
            nr      = m.group(2).strip()
            naam    = m.group(3).strip()
            cat     = m.group(4).strip()
            if positie > 999 or not nr:
                continue

            sleutel = sectie_naam or cat
            if sleutel not in secties:
                secties[sleutel] = {'sectie': sleutel, 'cat_codes': set(), 'rijders': []}
            secties[sleutel]['cat_codes'].add(cat)
            secties[sleutel]['rijders'].append(
                {'positie': positie, 'nr': nr, 'naam': naam, 'cat_code': cat})

    return secties, header_info


# ═════════════════════════════════════════════════════════════════════════════
# JSC-formaat parser  (Plaats / Beennr / Naam / Vereniging / kl / cat / punten)
# ─────────────────────────────────────────────────────────────────────────────
# Kolom-anker: whitespace + kleine_int + whitespace + cat-code + whitespace + decimaal-getal
JSC_ANKER_RE = re.compile(
    r'\s+(\d{1,2})\s+([HD][A-Z]{1,2}\d?)\s+(\d+[,\.]\d+)'
)
# Koptekst-regels overslaan
JSC_SKIP_RE = re.compile(
    r'Plaats.*Beennr|Algemeen klassement|Eind Klassement|'
    r'Bij gelijk|Deelname bij|Totaal\s+\w|^\s*$',
    re.IGNORECASE
)

def _parse_nr_prefix(prefix):
    """
    Extraheer positie (optioneel) en startnummer uit het prefix-gedeelte van een JSC-regel.
    Start-nummers kunnen zijn: 523, W24, G 4, W80, G20 …
    Geeft (pos_or_None, nr_string, resterende_tokens).
    """
    tokens = prefix.split()
    if not tokens:
        return None, None, []

    # Geval A: los letter + getal als start-nummer (bv. "G 4")
    if re.match(r'^[A-Z]$', tokens[0]) and len(tokens) > 1 and re.match(r'^\d+$', tokens[1]):
        return None, tokens[0] + ' ' + tokens[1], tokens[2:]

    # Geval B: eerste token is een klein getal (positie, ≤ 50) EN
    #          tweede token is het startnummer (numeriek of letter+cijfer zoals W6, G25)
    #          Onderscheider: tokens[2] moet beginnen met een hoofdletter (naam),
    #          zodat bv. "9 Aiden van der Mark" (alleen nr+naam) niet verward wordt
    #          met "5 9 Aiden..." (pos + nr + naam)
    if (re.match(r'^\d{1,2}$', tokens[0]) and int(tokens[0]) <= 50
            and len(tokens) > 2
            and (re.match(r'^[A-Z]\d', tokens[1]) or    # W24, G4, W6
                 (tokens[1].isdigit()                    # puur numeriek nr (elke grootte)
                  and re.match(r'^[A-Z]', tokens[2])))):  # gevolgd door naamwoord
        return int(tokens[0]), tokens[1], tokens[2:]

    # Geval C: eerste token is het start-nummer (groot getal of alphanum)
    return None, tokens[0], tokens[1:]


def parse_jsc_lijn(lijn):
    """Parseer één JSC-regel. Geeft dict of None."""
    m = JSC_ANKER_RE.search(lijn)
    if not m:
        return None

    kl  = int(m.group(1))
    cat = m.group(2)
    prefix = lijn[:m.start()].strip()
    if not prefix:
        return None          # sectie-einde-marker ("1 HJA 0,0")

    pos, nr, naam_tokens = _parse_nr_prefix(prefix)
    if not nr or not naam_tokens:
        return None

    naam = ' '.join(naam_tokens)
    return {'pos': pos, 'nr': nr, 'naam': naam, 'cat': cat, 'kl': kl}


def parse_jsc(pdf):
    secties    = {}   # cat_code → dict
    ongerankt  = {}   # cat_code → teller voor positie achteraan (1001, 1002 …)
    header_info = {}

    # Header-info uit eerste pagina
    eerste_txt = pdf.pages[0].extract_text() or ''
    regels_0 = eerste_txt.splitlines()
    for regel in regels_0[:4]:
        r = regel.strip()
        if r and not header_info.get('titel') and not re.match(r'^\d', r) and len(r) > 5:
            header_info['titel'] = r
            break

    for pagina in pdf.pages:
        txt = pagina.extract_text()
        if not txt:
            continue
        for lijn in txt.splitlines():
            if JSC_SKIP_RE.search(lijn):
                continue

            r = parse_jsc_lijn(lijn.strip())
            if not r:
                continue

            cat = r['cat']
            if cat not in secties:
                secties[cat]   = {'sectie': cat, 'cat_codes': {cat}, 'rijders': []}
                ongerankt[cat] = 0

            if r['pos'] is not None:
                positie = r['pos']
            else:
                # Niet officieel gerangschikt → achteraan (> 1000)
                ongerankt[cat] += 1
                positie = 1000 + ongerankt[cat]

            secties[cat]['rijders'].append(
                {'positie': positie, 'nr': r['nr'], 'naam': r['naam'], 'cat_code': cat})

    return secties, header_info


# ═════════════════════════════════════════════════════════════════════════════
# REGIO-formaat parser  (Regio Competitie stijl)
# Sectie-header : "categorie: pupillen 4 meisjes"
# Rijregel       : pos  nr  naam  vereniging  score1  score2 …
# ─────────────────────────────────────────────────────────────────────────────
REGIO_CAT_RE  = re.compile(r'^categorie\s*:\s*(.+)$', re.IGNORECASE)
# Score-staart: minstens 3 opeenvolgende getallen (incl. 0 en 40,1-stijl) aan het einde
REGIO_SCORES_RE = re.compile(r'(?:\s+\d+[,\.]?\d*){3,}\s*$')
# Prefix: positie + startnummer aan het begin van de regel
REGIO_PREFIX_RE = re.compile(r'^(\d{1,3})\s+(\d+)\s+(.*)')

# Mapping Dutch categorienamen → leesbaar label (ook voor KNSB cat-codes)
REGIO_LABEL_MAP = {
    'pupillen 4 meisjes': 'Dames Pupil 4',    'pupillen 4 jongens': 'Heren Pupil 4',
    'pupillen 3 meisjes': 'Dames Pupil 3',    'pupillen 3 jongens': 'Heren Pupil 3',
    'pupillen 2 meisjes': 'Dames Pupil 2',    'pupillen 2 jongens': 'Heren Pupil 2',
    'pupillen 1 meisjes': 'Dames Pupil 1',    'pupillen 1 jongens': 'Heren Pupil 1',
    'kadetten meisjes':   'Dames Kadetten A', 'kadetten jongens':   'Heren Kadetten A',
    'junioren meisjes':   'Dames Junior A',   'junioren jongens':   'Heren Junior A',
    'junioren b meisjes': 'Dames Junior B',   'junioren b jongens': 'Heren Junior B',
    'senioren meisjes':   'Dames Senior A',   'senioren jongens':   'Heren Senior A',
}

def regio_label(naam):
    return REGIO_LABEL_MAP.get(naam.lower().strip(), naam.title())

def parse_regio(pdf):
    secties    = {}
    header_info = {}
    sectie_naam = None

    for pagina in pdf.pages:
        txt = pagina.extract_text()
        if not txt:
            continue
        regels = txt.splitlines()

        # Header uit eerste pagina
        if not header_info:
            for r in regels[:3]:
                r = r.strip()
                if r and not re.match(r'^\d', r) and len(r) > 5:
                    header_info['titel'] = r
                    break

        for regel in regels:
            r = regel.strip()
            if not r:
                continue

            # Sectie-header: "categorie: pupillen 4 meisjes"
            mc = REGIO_CAT_RE.match(r)
            if mc:
                sectie_naam = mc.group(1).strip()
                continue

            # Sla koptekst-regels en placeholders over
            if re.match(r'^(pl|nr)\s+', r, re.I) or '######' in r:
                continue

            # Rijder: verwijder score-staart, parseer pos + nr + naam+club
            if '######' in r or not sectie_naam:
                continue
            tekst = REGIO_SCORES_RE.sub('', r).strip()
            mp = REGIO_PREFIX_RE.match(tekst)
            if not mp:
                continue

            positie = int(mp.group(1))
            nr      = mp.group(2).strip()
            naam    = mp.group(3).strip()   # naam + eventueel club (voor display goed genoeg)

            if int(nr) == 0:   # placeholder-rij
                continue

            label = regio_label(sectie_naam)
            if sectie_naam not in secties:
                secties[sectie_naam] = {
                    'sectie':    sectie_naam,
                    'label':     label,
                    'cat_codes': set(),
                    'rijders':   [],
                }
            secties[sectie_naam]['rijders'].append(
                {'positie': positie, 'nr': nr, 'naam': naam, 'cat_code': sectie_naam})

    return secties, header_info


# ═════════════════════════════════════════════════════════════════════════════
# NK-tussenstand parser  (KNSB Baancompetitie - Tussenstand NK deelname)
# ─────────────────────────────────────────────────────────────────────────────
# Sectie-kop: "12 Mannen Senioren Sprint" / "7 Dames Junior A" etc. De PDF-
# titel "Tussenstand NK deelname" geldt voor het hele document.
# Rij-patroon zonder "pl"-kolom:
#     557 Kai-Arne Ottenhoff HSA HEERENVEEN Hardrijders Club Heerenveen ...
# Gevolgd door een variabel aantal scores (A1..A5) en tenslotte tot/af/eindtot.
# De volgorde in de PDF IS al de ranking; we gebruiken positie = rij-index.
NK_SECTIE_RE = re.compile(
    r'^\d+\s+(Mannen|Dames|Heren)\s+[A-Za-z][A-Za-z\s]+$'
)
NK_REGEL_RE = re.compile(
    r'^(\d{1,4})\s+'                                    # startnummer
    r'([A-Z\xc0-\xd6\xd8-\xde][A-Za-z\xc0-\xff\s\'\-\.]+?)\s+'  # naam
    r'([HD][A-Z]{1,2}\d?)\s+'                           # cat-code HSA/HJB/HP1/...
    r'(.+?)\s+'                                         # woonplaats + club + sponsor
    r'((?:\d+\s+){0,8}\d+)\s*$'                         # 1-9 numerieke velden op einde
)

def parse_nk_tussenstand(pdf):
    """Parse 'KNSB Baancompetitie - Tussenstand NK deelname'-PDF.

    We gebruiken de `Cat`-kolom (HSA, HJB, ...) als sectie-sleutel,
    consistent met de KNSB-parser. De beschrijvende kop ("12 Mannen
    Senioren Sprint") is puur tekst en triggert alleen de positie-reset.
    Binnen elke cat-sectie geldt: volgorde in PDF = ranking.
    """
    secties = {}
    header_info = {}
    in_sectie = False   # True na het zien van een sectie-kop

    for pagina in pdf.pages:
        txt = pagina.extract_text()
        if not txt:
            continue
        regels = txt.splitlines()

        # Header-info (eerste pagina)
        if not header_info:
            for regel in regels[:6]:
                r = regel.strip()
                if 'Tussenstand NK deelname' in r:
                    header_info['titel'] = r
                elif re.match(r'^\d{1,2}/\d{1,2}/\d{4}', r):
                    header_info['datum'] = r

        # Positie-teller per cat binnen deze pagina.
        # Bij een sectie-kop resetten we alle tellers — de volgende rijder
        # is positie 1 van z'n cat.
        cat_teller = {}

        for regel in regels:
            r = regel.strip()
            if not r:
                continue

            # Sectie-kop? ("12 Mannen Senioren Sprint") -> reset tellers
            if NK_SECTIE_RE.match(r):
                cat_teller = {}
                in_sectie = True
                continue

            # Kolom-header overslaan
            if re.match(r'^nr\s+Naam\s+Cat\b', r, re.I):
                continue

            # Rijder-regel
            mr = NK_REGEL_RE.match(r)
            if not mr or not in_sectie:
                continue

            nr    = mr.group(1).strip()
            naam  = mr.group(2).strip()
            cat   = mr.group(3).strip()
            tail  = mr.group(5).strip()

            # Laatste getal = eindtot (lagere = beter geseed)
            cijfers = [int(x) for x in tail.split()]
            eindtot = cijfers[-1] if cijfers else None

            # Cat wordt de sleutel (HSA, HJB, DP1, ...). Dispatcher zet
            # het label later om naar "Heren Senior A" via cat_label().
            if cat not in secties:
                secties[cat] = {
                    'sectie':    cat,
                    'cat_codes': set(),
                    'rijders':   [],
                }
                cat_teller[cat] = 0
            cat_teller[cat] = cat_teller.get(cat, 0) + 1

            secties[cat]['cat_codes'].add(cat)
            secties[cat]['rijders'].append({
                'positie':  cat_teller[cat],
                'nr':       nr,
                'naam':     naam,
                'cat_code': cat,
                'punten':   eindtot,   # som na strepen (kleinste is beste)
            })

    return secties, header_info


# ═════════════════════════════════════════════════════════════════════════════
# NK-selectie parser  ("Tussenstand selectie NK Weg")
# ─────────────────────────────────────────────────────────────────────────────
# Sectie-kop (nummer geplakt aan geslacht): "1Vrouwen Kadetten Sprint".
# MEERDERE secties per pagina → inline verwerken (niet één-per-pagina zoals KNSB).
# Rij MET losse Lic.Nr.-kolom (dat mist de gewone KNSB-parser):
#     pl  Lic.Nr  startnr(geplakt)Naam  Cat  Woonplaats  Club  Sponsor  A1..A6 tot af tot
#     "1 10219545 53Eline van Leijenhorst DKA Lelystad Radboud Inline-skating 2 1 …"
# De `pl`-kolom is de ranking (per sectie); sectienaam (incl. discipline) = sleutel.
NKSEL_SECTIE_RE = re.compile(
    r'^\d+\s*((?:Vrouwen|Mannen|Dames|Heren)[A-Za-z\s]+?)\s*$'
)
NKSEL_REGEL_RE = re.compile(
    r'^(\d{1,3})\s+'          # pl (positie in de sectie)
    r'\d{6,}\s+'             # KNSB-licentie (Lic.Nr.-kolom) — overslaan
    r'(\d+)'                 # startnummer (geplakt aan de naam)
    r'([A-Z\xc0-\xd6\xd8-\xf6\xf8-\xff]'
    r'[A-Za-z\xc0-\xff\s\'\-\.]+?)'  # naam
    r'\s+([HD][A-Z]{1,2}\d?)\s'      # cat-code (DKA/HKA/DJB/HJB/...)
)

def parse_nk_selectie(pdf):
    secties = {}
    header_info = {}
    sectie_naam = None

    for pagina in pdf.pages:
        txt = pagina.extract_text()
        if not txt:
            continue
        for regel in txt.splitlines():
            r = regel.strip()
            if not r:
                continue

            if not header_info.get('titel') and 'selectie NK' in r:
                header_info['titel'] = r

            # Sectie-kop? (moet een kleine letter bevatten → geen caps-header)
            ms = NKSEL_SECTIE_RE.match(r)
            if ms and re.search(r'[a-z]', ms.group(1)):
                sectie_naam = ms.group(1).strip()
                continue

            # Rijder-regel
            mr = NKSEL_REGEL_RE.match(r)
            if not mr or not sectie_naam:
                continue

            positie = int(mr.group(1))
            nr      = mr.group(2).strip()
            naam    = mr.group(3).strip()
            cat     = mr.group(4).strip()

            if sectie_naam not in secties:
                secties[sectie_naam] = {'sectie': sectie_naam, 'cat_codes': set(), 'rijders': []}
            secties[sectie_naam]['cat_codes'].add(cat)
            secties[sectie_naam]['rijders'].append(
                {'positie': positie, 'nr': nr, 'naam': naam, 'cat_code': cat})

    return secties, header_info


# ═════════════════════════════════════════════════════════════════════════════
# Formaat-detectie
# ─────────────────────────────────────────────────────────────────────────────
def detect_format(pdf):
    tekst = ' '.join(
        (pdf.pages[i].extract_text() or '') for i in range(min(2, len(pdf.pages)))
    )
    if 'Tussenstand NK deelname' in tekst or 'NK deelname' in tekst:
        return 'nk_tussen'
    if 'selectie NK' in tekst or 'Tussenstand selectie' in tekst:
        return 'nk_selectie'
    if 'Beennr' in tekst or 'Algemeen klassement' in tekst:
        return 'jsc'
    if re.search(r'categorie\s*:', tekst, re.I):
        return 'regio'
    if 'KLASSEMENT' in tekst or re.search(r'^pl\s+nr\s+naam', tekst, re.M | re.I):
        return 'knsb'
    if re.search(r'^\d+\s+\d+[A-Z]', tekst, re.M):
        return 'knsb'
    return 'knsb'


# ═════════════════════════════════════════════════════════════════════════════
# Hoofd
# ─────────────────────────────────────────────────────────────────────────────
def parse_pdf(pad):
    with pdfplumber.open(pad) as pdf:
        fmt = detect_format(pdf)
        if fmt == 'jsc':
            secties_raw, header_info = parse_jsc(pdf)
        elif fmt == 'regio':
            secties_raw, header_info = parse_regio(pdf)
        elif fmt == 'nk_tussen':
            secties_raw, header_info = parse_nk_tussenstand(pdf)
        elif fmt == 'nk_selectie':
            secties_raw, header_info = parse_nk_selectie(pdf)
        else:
            secties_raw, header_info = parse_knsb(pdf)

    if not secties_raw:
        return {'error': f'Geen rijders herkend (formaat: {fmt})'}

    secties_uit = []
    for sleutel, info in secties_raw.items():
        rijders = sorted(info['rijders'], key=lambda r: r['positie'])
        # Label: cat_label als het een pure cat-code is, anders de sectienaam zelf
        label = cat_label(sleutel) if sleutel in CAT_LABELS else sleutel
        secties_uit.append({
            'label':     label,
            'sectie':    sleutel,
            'cat_codes': sorted(info['cat_codes']),
            'totaal':    len(rijders),
            'rijders':   rijders,
        })

    # Sorteer secties op label
    secties_uit.sort(key=lambda s: s['label'])

    return {
        'formaat': fmt,
        'header':  header_info,
        'secties': secties_uit,
        'totaal':  sum(s['totaal'] for s in secties_uit),
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'Geen PDF-pad opgegeven'}))
        sys.exit(1)
    pad = sys.argv[1]
    try:
        result = parse_pdf(pad)
        print(json.dumps(result, ensure_ascii=False))
    except FileNotFoundError:
        print(json.dumps({'error': f'Bestand niet gevonden: {pad}'}))
        sys.exit(1)
    except Exception as e:
        import traceback
        print(json.dumps({'error': str(e), 'traceback': traceback.format_exc()}))
        sys.exit(1)
