#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JSC-klassement-vergelijking: InlineComp-PDF  vs  organisatie/Sportity-PDF.

Leest beide tussenstand-PDF's in, koppelt rijders op NAAM (want startnummers
wijken juist af) binnen dezelfde categorie, koppelt wedstrijden op DATUM, en
rapporteert alle verschillen: punten per wedstrijd, totaal, startnummer, en
rijders die maar in één bron voorkomen.

Gebruik:
    python jsc_vergelijk.py "<InlineComp.pdf>" "<Sportity.pdf>"

Vereist: pdfplumber  (pip install pdfplumber)
"""
import sys, re, unicodedata, difflib
import pdfplumber

# ── Datum → wedstrijd-label (koppelt de twee bronnen aan elkaar) ──────────────
DATUM_LABEL = {
    '2026-04-19': 'JSC1 Leiderdorp (19-4)',
    '2026-05-09': 'JSC2 Lisserbroek (9-5)',
    '2026-06-07': 'JSC3 Ammerstol (7-6, bonus)',
    '2026-06-14': 'JSC4 Warmond (14-6)',
    '2026-07-12': 'JSC5 Alphen (12-7)',
    '2026-09-05': 'Finale Zoeterwoude (5-9)',
}
def dmy_to_iso(s):   # "19-4-2026" -> "2026-04-19"
    d, m, y = s.split('-')
    return f'{int(y):04d}-{int(m):02d}-{int(d):02d}'

CATS = {'DJA','DJB','DKA','DP1','DP2','DP3','HJB','HKA','HP1','HP2','HP3','HP4','DP4'}

def norm_naam(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii','ignore').decode()
    s = s.lower()
    s = re.sub(r'\b(v\.?d\.?|vd)\b', 'van der', s)
    s = re.sub(r'[^a-z0-9 ]', ' ', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s

def getal(tok):
    """ '10.1' / '10,1' / '–' / '0,0'  -> float ; niet-gescoord = 0.0 """
    tok = tok.strip()
    if tok in ('–','-','—',''):
        return 0.0
    return float(tok.replace(',', '.'))

# ── InlineComp-PDF ───────────────────────────────────────────────────────────
# Per categorie: "DP3 (5 rijders)" ; rijen: Pos Start# Naam #1..#5 F Totaal
# (rechtste 7 tokens = 5 wedstrijden + finale + totaal). Datum-legend per blok.
def parse_inlinecomp(path):
    riders = {}   # (cat, norm_naam) -> {startnr, naam, punten:{iso:pt}, totaal}
    with pdfplumber.open(path) as pdf:
        tekst = '\n'.join(p.extract_text() or '' for p in pdf.pages)
    # kolom-index (#1..#5,F) -> iso-datum, uit de legend-regels "#1 — JSC 1 (2026-04-19)"
    kol_datum = {}
    for m in re.finditer(r'#(\d)\s*—\s*JSC[^()]*\((\d{4}-\d{2}-\d{2})\)', tekst):
        kol_datum[int(m.group(1))-1] = m.group(2)
    for m in re.finditer(r'\bF\s*—\s*JSC[^()]*\((\d{4}-\d{2}-\d{2})\)', tekst):
        kol_datum[5] = m.group(1)
    cat = None
    for regel in tekst.splitlines():
        mcat = re.match(r'^([DH][JKP]\w?)\s*\(\d+\s*rijders\)', regel)
        if mcat:
            cat = mcat.group(1); continue
        if not cat:
            continue
        toks = regel.split()
        if len(toks) < 9 or not re.match(r'^\d+$', toks[0]):
            continue
        # rechtste 7 = #1..#5, F, Totaal ; toks[1]=start#, tussenin = naam
        scores = toks[-7:]
        naam = ' '.join(toks[2:-7]).strip()
        if not naam:
            continue
        punten = {}
        for i in range(6):                       # 5 wedstrijden + finale
            iso = kol_datum.get(i)
            if iso:
                punten[iso] = getal(scores[i])
        riders[(cat, norm_naam(naam))] = {
            'startnr': toks[1], 'naam': naam, 'cat': cat,
            'punten': punten, 'totaal': getal(scores[6]),
        }
    return riders

# ── Sportity/organisatie-PDF ─────────────────────────────────────────────────
# Kop: "... Leiderdorp Lisserbroek ...", dan datumrij "19-4-2026 9-5-2026 ...".
# Rijen: Plaats Start# <Naam Vereniging> cat punten d1..d6
# (rechtste 7 = punten + 6 datumkolommen ; cat staat er direct vóór).
def parse_sportity(path):
    with pdfplumber.open(path) as pdf:
        tekst = '\n'.join(p.extract_text() or '' for p in pdf.pages)
    # datum-volgorde uit de kolomkop
    datums = [dmy_to_iso(x) for x in re.findall(r'\b(\d{1,2}-\d{1,2}-\d{4})\b', tekst)]
    # ontdubbel met behoud van volgorde
    seen, koldatums = set(), []
    for d in datums:
        if d not in seen:
            seen.add(d); koldatums.append(d)
    riders = {}
    for regel in tekst.splitlines():
        toks = regel.split()
        if len(toks) < 9 or not re.match(r'^\d+$', toks[0]):
            continue
        # zoek de cat-token (bekende categoriecode) van rechts
        cat_idx = None
        for i in range(len(toks)-1, 0, -1):
            if toks[i] in CATS:
                cat_idx = i; break
        if cat_idx is None or cat_idx < 2:
            continue
        cat = toks[cat_idx]
        waarden = toks[cat_idx+1:]            # punten + datumkolommen
        if len(waarden) < 2:
            continue
        totaal = getal(waarden[0])
        dagscores = [getal(x) for x in waarden[1:]]
        naamblob = ' '.join(toks[2:cat_idx]).strip()   # Naam + Vereniging
        if not naamblob:
            continue
        punten = {}
        for i, iso in enumerate(koldatums):
            if i < len(dagscores):
                punten[iso] = dagscores[i]
        riders[(cat, toks[1], naamblob)] = {
            'startnr': toks[1], 'naamblob': naamblob, 'cat': cat,
            'punten': punten, 'totaal': totaal,
        }
    return riders

# ── Koppelen op naam binnen categorie (startnr wijkt bewust af) ───────────────
def match_sportity(ic_naam_norm, cat, sp_riders):
    kandidaten = [(k, v) for k, v in sp_riders.items() if v['cat'] == cat]
    # 1) exacte prefix-match: IC-naam is begin van "Naam Vereniging"
    for k, v in kandidaten:
        if norm_naam(v['naamblob']).startswith(ic_naam_norm):
            return k, v
    # 2) fuzzy op de eerste woorden van de blob (tegen spelvarianten)
    n = len(ic_naam_norm.split())
    best, beststr = None, 0.0
    for k, v in kandidaten:
        blobwoorden = norm_naam(v['naamblob']).split()
        kop = ' '.join(blobwoorden[:n])
        r = difflib.SequenceMatcher(None, ic_naam_norm, kop).ratio()
        if r > beststr:
            best, beststr = (k, v), r
    if best and beststr >= 0.82:
        return best
    return None, None

def fmt(x):
    return ('%g' % x)

def main():
    if len(sys.argv) < 3:
        print('Gebruik: python jsc_vergelijk.py "<InlineComp.pdf>" "<Sportity.pdf>"')
        sys.exit(1)
    ic = parse_inlinecomp(sys.argv[1])
    sp = parse_sportity(sys.argv[2])
    print(f'InlineComp: {len(ic)} rijders   |   Sportity: {len(sp)} rijders\n')

    gebruikt = set()
    verschillen = []      # (cat, naam, tekst)
    alleen_ic = []
    for (cat, nn), r in sorted(ic.items()):
        k, m = match_sportity(nn, cat, sp)
        if not m:
            alleen_ic.append((cat, r['naam']))
            continue
        gebruikt.add(k)
        regels = []
        # startnummer — categoriseer, zodat echte verwisselingen niet ondersneeuwen
        if r['startnr'] != m['startnr']:
            ic_s, sp_s = r['startnr'], m['startnr']
            if re.match(r'^[GWgw]', sp_s):
                soort = 'gast/wildcard-notatie (waarschijnlijk geen fout)'
            elif not re.fullmatch(r'\d+', sp_s):
                soort = 'Sportity-startnr onleesbaar (mogelijk datafout)'
            elif re.fullmatch(r'\d+', ic_s):
                soort = '⚠ startnr verwisseld (nakijken)'
            else:
                soort = 'startnr wijkt af'
            regels.append(f"    startnr:  InlineComp {ic_s}  ≠  Sportity {sp_s}   [{soort}]")
        # punten per wedstrijd (op datum)
        for iso in sorted(set(r['punten']) | set(m['punten'])):
            a = r['punten'].get(iso, 0.0)
            b = m['punten'].get(iso, 0.0)
            if abs(a - b) > 1e-9:
                lbl = DATUM_LABEL.get(iso, iso)
                regels.append(f"    {lbl}:  InlineComp {fmt(a)}  ≠  Sportity {fmt(b)}")
        # totaal
        if abs(r['totaal'] - m['totaal']) > 1e-9:
            regels.append(f"    TOTAAL:   InlineComp {fmt(r['totaal'])}  ≠  Sportity {fmt(m['totaal'])}")
        if regels:
            verschillen.append((cat, r['naam'], '\n'.join(regels)))

    alleen_sp = [(v['cat'], v['naamblob']) for k, v in sp.items() if k not in gebruikt]

    # ── Rapport ──────────────────────────────────────────────────────────────
    print('=' * 70)
    print('VERSCHILLEN (gekoppelde rijders)')
    print('=' * 70)
    if not verschillen:
        print('  Geen verschillen gevonden. 🎉')
    for cat, naam, tekst in sorted(verschillen):
        print(f'\n[{cat}] {naam}')
        print(tekst)

    if alleen_ic:
        print('\n' + '=' * 70)
        print('ALLEEN in InlineComp (niet gevonden in Sportity)')
        print('=' * 70)
        for cat, naam in sorted(alleen_ic):
            print(f'  [{cat}] {naam}')
    if alleen_sp:
        print('\n' + '=' * 70)
        print('ALLEEN in Sportity (niet gevonden in InlineComp)')
        print('=' * 70)
        for cat, naam in sorted(alleen_sp):
            print(f'  [{cat}] {naam}')

    print(f'\nSamenvatting: {len(verschillen)} rijders met verschil, '
          f'{len(alleen_ic)} alleen-IC, {len(alleen_sp)} alleen-Sportity.')

if __name__ == '__main__':
    main()
