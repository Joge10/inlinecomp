#!/usr/bin/env python3
"""
InlineComp — NK historie import (2022/2023/2024)
=================================================

Doel: PDF-uitslagen van oude NKs snel omzetten naar SQL INSERT-statements
      voor de `uitslag_afstand`-tabel.

WORKFLOW
--------
1) Maak per jaar een wedstrijd-row in `competitions` + de bijbehorende
   `distance_combinations` rows in de database (handmatig SQL, net zoals
   bij NK 2025). Onthou de UUIDs.

2) Kopieer config.example.json naar config.json en vul in:
     - DB credentials (voor persoon-matching op naam/licentie)
     - Per jaar: competition_id, datum, naam
     - Per categorie binnen dat jaar: dc_id + dc_naam

3) Open de PDF van een afstand in je browser, Ctrl+A, Ctrl+C, paste in
   een nieuw tekstbestand. Sla op als:
     scripts/nk_historie/inputs/<jaar>/<afstand>.txt

   Voorbeeld:
     inputs/2024/200m.txt
     inputs/2024/500m.txt
     inputs/2024/5000m_punten.txt
     inputs/2023/200m.txt
     ...

   Eén bestand mag meerdere categorieën bevatten (DSA+DSJ+HSA in één
   200m PDF is prima — Claude detecteert per rijder de juiste cat).

4) Zorg dat ANTHROPIC_API_KEY in je environment staat:
     Windows CMD : set ANTHROPIC_API_KEY=sk-ant-...
     PowerShell  : $env:ANTHROPIC_API_KEY = "sk-ant-..."
     macOS/Linux : export ANTHROPIC_API_KEY=sk-ant-...

5) Installeer dependencies (eenmalig):
     pip install -r requirements.txt

6) Draai:
     python nk_import.py

7) Bestand-per-jaar verschijnt in output/nk_<jaar>.sql.
   Open in phpMyAdmin → SQL-tab → paste → uitvoeren.

8) Onbekende rijders (niet in persons-tabel) komen als SQL-comments in
   het output-bestand: zoek op "-- TODO". Voeg die persons handmatig
   toe of laat de rij uit de SQL staan.

KEUZES & BEPERKINGEN
--------------------
- Gebruikt het Claude-model claude-haiku-4-5 (zelfde als de vertaling-
  feature). Override met --model claude-opus-4-7 als je betere extractie
  wilt op slechte PDF-layouts (factor 5 duurder).
- Tijd wordt door Claude als string in mm:ss.mmm formaat teruggegeven.
  Python parst die naar milliseconden — minder kans op rekenfouten.
- Sancties worden gemapt naar de ENUM-waarden uit uitslag_afstand:
  DNS, DNF, DQ-TF, DQ-SF, DQ-DF, FS, W1, W2, RR.
- Matching op licentie eerst (als PDF die geeft), anders op
  full_name (case-insensitive, trim, dubbele spaties weg). Bij meerdere
  matches met dezelfde naam: birth_year als tiebreaker indien Claude die
  uit de PDF haalde, anders log als ambigu en skip.
- INSERT IGNORE wordt gebruikt — herhaaldelijk runnen is veilig (UNIQUE
  key op (comp_id, dc_id, distance_id, split_group, person_license)
  voorkomt duplicaten).
"""

import argparse
import json
import os
import re
import sys
import time
from datetime import datetime
from pathlib import Path

# Externe deps — installeer met `pip install -r requirements.txt`
try:
    import anthropic
except ImportError:
    sys.exit("❌ Module 'anthropic' niet gevonden. Run: pip install -r requirements.txt")
try:
    import pymysql
except ImportError:
    sys.exit("❌ Module 'pymysql' niet gevonden. Run: pip install -r requirements.txt")


# ── Constants ─────────────────────────────────────────────────────────────

DEFAULT_MODEL = 'claude-haiku-4-5'

# Geldige ENUM-waarden uit uitslag_afstand.sanctie
GELDIGE_SANCTIES = {'W1', 'W2', 'FS', 'RR', 'DQ-TF', 'DQ-SF', 'DQ-DF', 'DNS', 'DNF'}

EXTRACT_PROMPT = """Je bent een data-extractie tool voor Nederlandse inlineskate-wedstrijden (NK).

Hieronder volgt de tekst van een PDF met de UITSLAG van één afstand. De PDF kan
meerdere CATEGORIEEN bevatten (bv. DSA+HSA+DJA samen in één 200m-PDF).

Categorie-codes — gebruik EXACT deze codes:
  DSA = Dames Senioren A      HSA = Heren Senioren A
  DSB = Dames Senioren B      HSB = Heren Senioren B
  DSJ = Dames Senioren Junior HSJ = Heren Senioren Junior
  DJA = Dames Junioren A      HJA = Heren Junioren A
  DJB = Dames Junioren B      HJB = Heren Junioren B
  DKA = Dames Kadetten        HKA = Heren Kadetten

Geef terug als JSON object (uitsluitend geldige JSON, géén markdown, géén
uitleg eromheen). Schema:

{
  "afstand_naam":     "200m" | "500m" | "1000m" | "5000m punten" | "10000m afval" | etc,
  "afstand_meters":   200,
  "race_type":        "sprint" | "inline" | "puntenkoers" | "afvalkoers",
  "rijders": [
    {
      "rang":         1,
      "naam":         "Volledige naam zoals in PDF",
      "licentie":     "12345678"  of  null,
      "tijd":         "0:18.920" of "1:23.456" of null  (formaat m:ss.mmm),
      "geboortejaar": 2005 of null  (alleen als PDF die expliciet noemt),
      "categorie":    "DSA",
      "sanctie":      null | "DNS" | "DNF" | "DQ-TF" | "DQ-SF" | "DQ-DF" | "FS" | "W1" | "W2" | "RR"
    }
  ]
}

REGELS:
- Tijd-formaat ALTIJD m:ss.mmm (minuten:seconden.milliseconden, milliseconden
  3 cijfers). Voorbeelden:
    PDF "0:18.92"    → "0:18.920"
    PDF "18.92"      → "0:18.920"
    PDF "1:23.4"     → "1:23.400"
    PDF "10:23.456"  → "10:23.456"
- Race_type bepalen:
    sprint      = korte afstand met enkel een eindtijd (200m, 300m, 500m,
                  1000m tijdrit)
    inline      = lange afstand zonder afval/punten (zeldzaam op NK baan)
    puntenkoers = "puntenkoers" of "points race" in titel
    afvalkoers  = "afvalkoers" of "elimination" in titel
- ALLE rijders meenemen, ook DNS/DNF/DQ. Bij DNS/DNF: rang=null, sanctie ingevuld.
- Bij ex-aequo (zelfde tijd, gedeelde plek): beide rijders krijgen dezelfde rang.
- Sanctie ALLEEN invullen als de PDF dat expliciet aangeeft (bv. kolom "Status"
  met "DSQ" of "DNF"). Bij gewone finishers: sanctie = null.
- DSQ in PDF → "DQ-TF" als default (track fouled). Specifiekere DQ-variant alleen
  als de PDF die noemt.
- Naam exact overnemen, inclusief tussenvoegsels ("van der", "ten", "'t").
- Als de PDF geen tijden bevat (bv. puntenkoers met punten-totaal in plaats van
  tijd), zet tijd=null voor alle rijders.

PDF TEKST:
---
{pdf_text}
---
"""


# ── Helpers ───────────────────────────────────────────────────────────────

def tijd_naar_ms(tijd_str):
    """
    Parse "m:ss.mmm" naar milliseconden int. Tolerant voor varianten:
      "18.92"      → 18920
      "0:18.920"   → 18920
      "1:23.456"   → 83456
      "10:23"      → 623000
      None of ""   → None
    """
    if not tijd_str:
        return None
    s = str(tijd_str).strip()
    if not s:
        return None
    # Normaliseer komma's naar punt (soms Europese decimaal-notatie)
    s = s.replace(',', '.')
    m = re.match(r'^(?:(\d+):)?(\d+)(?:\.(\d+))?$', s)
    if not m:
        return None
    minuten = int(m.group(1) or 0)
    seconden = int(m.group(2))
    frac = m.group(3) or ''
    # Decimalen zijn breukdelen van een seconde — schaal naar 3 cijfers
    if len(frac) == 1: ms_part = int(frac) * 100
    elif len(frac) == 2: ms_part = int(frac) * 10
    elif len(frac) == 3: ms_part = int(frac)
    elif len(frac) == 0: ms_part = 0
    else: ms_part = int(frac[:3])  # afkappen op 3 cijfers
    return (minuten * 60 + seconden) * 1000 + ms_part


def naam_normalize(naam):
    """Normaliseer naam voor matching: lowercase, dubbele spaties weg, trim."""
    if not naam: return ''
    n = naam.lower().strip()
    n = re.sub(r'\s+', ' ', n)
    # Verwijder leestekens behalve apostrof en streepje (voor namen als "'t" of "Huis-in")
    n = re.sub(r"[^\w\s'\-]", '', n, flags=re.UNICODE)
    return n


def sql_esc(s):
    """Escape string voor SQL — verdubbel single quotes."""
    if s is None: return 'NULL'
    return "'" + str(s).replace("'", "''") + "'"


def sql_int(n):
    return 'NULL' if n is None else str(int(n))


# ── Persoon-matching ──────────────────────────────────────────────────────

class PersonsCache:
    """Cache van persons.full_name → license_key voor snelle lookups."""

    def __init__(self, db):
        self.by_lic = {}
        self.by_naam = {}     # naam_normalize(full_name) → [persons]
        cur = db.cursor(pymysql.cursors.DictCursor)
        cur.execute(
            "SELECT license_key, full_name, birth_year "
            "FROM persons WHERE anonymized_at IS NULL"
        )
        for p in cur.fetchall():
            self.by_lic[p['license_key']] = p
            key = naam_normalize(p['full_name'])
            self.by_naam.setdefault(key, []).append(p)
        cur.close()
        print(f"  → {len(self.by_lic)} persons geladen uit DB "
              f"({len(self.by_naam)} unieke naam-keys).")

    def match(self, rijder):
        """
        Probeer rijder te matchen op licentie → naam (+ optioneel birth_year).
        Return: (license_key, reden) of (None, reden_waarom_niet).
        """
        lic = rijder.get('licentie')
        if lic and str(lic).strip() in self.by_lic:
            return str(lic).strip(), 'licentie'

        naam = rijder.get('naam') or ''
        kandidaten = self.by_naam.get(naam_normalize(naam), [])
        if not kandidaten:
            return None, 'naam onbekend'

        if len(kandidaten) == 1:
            return kandidaten[0]['license_key'], 'naam (uniek)'

        # Meerdere personen met dezelfde naam → tiebreaker op geboortejaar
        bj = rijder.get('geboortejaar')
        if bj:
            jaar_match = [k for k in kandidaten if k['birth_year'] == bj]
            if len(jaar_match) == 1:
                return jaar_match[0]['license_key'], 'naam+jaar'

        # Ambigu — laat operator beslissen
        return None, f'ambigu ({len(kandidaten)} matches)'


# ── Claude API ────────────────────────────────────────────────────────────

def extract_via_claude(client, model, pdf_text, max_retries=3):
    """Stuur PDF-tekst naar Claude, parse JSON-response."""
    prompt = EXTRACT_PROMPT.replace('{pdf_text}', pdf_text)
    last_err = None
    for poging in range(1, max_retries + 1):
        try:
            response = client.messages.create(
                model=model,
                max_tokens=8000,
                messages=[{"role": "user", "content": prompt}],
            )
            raw = response.content[0].text.strip()
            # Soms wrapped Claude tóch in ```json ... ``` ondanks de prompt
            raw = re.sub(r'^```(?:json)?\s*|\s*```$', '', raw, flags=re.MULTILINE)
            return json.loads(raw)
        except (json.JSONDecodeError, anthropic.APIError) as e:
            last_err = e
            if poging < max_retries:
                wacht = 2 ** poging
                print(f"  ⚠ Claude-call mislukt (poging {poging}): {e}. "
                      f"Wacht {wacht}s en probeer opnieuw...")
                time.sleep(wacht)
    raise RuntimeError(f"Claude API faalde {max_retries}× — laatste fout: {last_err}")


# ── SQL-generatie ─────────────────────────────────────────────────────────

def maak_insert_sql(year_cfg, dc_cfg, extracted, rijder, license_key):
    """Bouw één INSERT statement voor uitslag_afstand."""
    sanctie = rijder.get('sanctie')
    if sanctie and sanctie not in GELDIGE_SANCTIES:
        sanctie = None  # silently drop invalid enum values

    tijd_ms = tijd_naar_ms(rijder.get('tijd'))

    cols = [
        'competition_id', 'competition_naam', 'competition_datum',
        'distance_combination_id', 'dc_naam',
        'split_group', 'distance_id', 'distance_naam', 'distance_meters',
        'person_license', 'categorie', 'rang',
        'tijd_ms', 'sanctie',
    ]
    vals = [
        sql_esc(year_cfg['competition_id']),
        sql_esc(year_cfg['competition_naam']),
        sql_esc(year_cfg['competition_datum']),
        sql_esc(dc_cfg['dc_id']),
        sql_esc(dc_cfg['dc_naam']),
        sql_esc(''),                                            # split_group
        sql_esc(''),                                            # distance_id (leeg = single-dist DC)
        sql_esc(extracted.get('afstand_naam') or ''),
        sql_int(extracted.get('afstand_meters')),
        sql_esc(license_key),
        sql_esc(rijder.get('categorie')),
        sql_int(rijder.get('rang')),
        sql_int(tijd_ms),
        sql_esc(sanctie),
    ]
    return (
        f"INSERT IGNORE INTO uitslag_afstand ({', '.join(cols)}) "
        f"VALUES ({', '.join(vals)});"
    )


# ── Hoofdlogica per jaar ──────────────────────────────────────────────────

def verwerk_jaar(jaar, year_cfg, year_dir, client, model, persons, output_dir):
    """Loop door .txt-files van één jaar, schrijf één SQL-bestand."""
    print(f"\n═══ Jaar {jaar} ═══")
    txt_files = sorted(year_dir.glob('*.txt'))
    if not txt_files:
        print(f"  (geen .txt-bestanden in {year_dir})")
        return

    sql = [
        f"-- ============================================================",
        f"-- NK {jaar} uitslag-import",
        f"-- Gegenereerd door scripts/nk_historie/nk_import.py",
        f"-- Datum: {datetime.now():%Y-%m-%d %H:%M}",
        f"-- ============================================================",
        f"",
        f"START TRANSACTION;",
        f"",
    ]
    stats = {'totaal': 0, 'ok': 0, 'skip_geen_cat': 0,
             'skip_geen_persoon': 0, 'unmatched_namen': []}

    for txt_file in txt_files:
        print(f"\n• {txt_file.relative_to(year_dir.parent.parent)}")
        pdf_text = txt_file.read_text(encoding='utf-8', errors='replace')
        if not pdf_text.strip():
            print("  (leeg, skip)")
            continue

        try:
            extracted = extract_via_claude(client, model, pdf_text)
        except Exception as e:
            print(f"  ❌ Extractie mislukt: {e}")
            sql.append(f"-- ❌ FAIL: {txt_file.name} — {e}")
            continue

        n_rijders = len(extracted.get('rijders', []))
        print(f"  → {n_rijders} rijders gevonden in {extracted.get('afstand_naam', '?')}")

        sql.append(f"")
        sql.append(f"-- ── {txt_file.name}: {extracted.get('afstand_naam', '?')} "
                   f"({n_rijders} rijders) ─────────────")

        for r in extracted.get('rijders', []):
            stats['totaal'] += 1
            cat = r.get('categorie')
            if not cat or cat not in year_cfg['dcs']:
                stats['skip_geen_cat'] += 1
                sql.append(f"-- TODO geen DC voor cat '{cat}' "
                           f"({r.get('naam')}) — voeg toe aan config.json")
                continue

            dc_cfg = year_cfg['dcs'][cat]
            lic, reden = persons.match(r)
            if not lic:
                stats['skip_geen_persoon'] += 1
                stats['unmatched_namen'].append(f"{cat:4} {r.get('naam', '?')}")
                sql.append(f"-- TODO persoon niet gevonden ({reden}): "
                           f"{cat} {r.get('naam')} (lic={r.get('licentie')}, "
                           f"jr={r.get('geboortejaar')})")
                continue

            stats['ok'] += 1
            sql.append(maak_insert_sql(year_cfg, dc_cfg, extracted, r, lic))

    sql.append(f"")
    sql.append(f"COMMIT;")

    out = output_dir / f"nk_{jaar}.sql"
    out.write_text('\n'.join(sql), encoding='utf-8')

    print(f"\n  ✓ {jaar}: {stats['ok']}/{stats['totaal']} ingevoegd, "
          f"{stats['skip_geen_cat']} skip(geen cat), "
          f"{stats['skip_geen_persoon']} skip(geen persoon)")
    print(f"  → output: {out}")
    if stats['unmatched_namen']:
        print(f"\n  ⚠ Eerste 10 unmatched namen (zie SQL-comments voor compleet):")
        for n in stats['unmatched_namen'][:10]:
            print(f"      {n}")


# ── Entry-point ───────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(
        description="Importeer NK historie-uitslagen vanuit gepaste PDF-tekst."
    )
    ap.add_argument('--config', default='config.json',
                    help='Pad naar config.json (default: ./config.json)')
    ap.add_argument('--inputs', default='inputs',
                    help='Map met <jaar>/<afstand>.txt files (default: ./inputs)')
    ap.add_argument('--output', default='output',
                    help='Map waar nk_<jaar>.sql geschreven wordt (default: ./output)')
    ap.add_argument('--api-key', default=None,
                    help='Anthropic API key (default: env var ANTHROPIC_API_KEY)')
    ap.add_argument('--model', default=DEFAULT_MODEL,
                    help=f'Claude model (default: {DEFAULT_MODEL})')
    ap.add_argument('--jaar', default=None,
                    help='Alleen dit jaar verwerken (default: alle jaren in inputs/)')
    args = ap.parse_args()

    # Script-relatieve paden (werkt ook als je vanuit een andere cwd draait)
    base = Path(__file__).resolve().parent
    config_path = (base / args.config) if not Path(args.config).is_absolute() else Path(args.config)
    inputs_dir = (base / args.inputs) if not Path(args.inputs).is_absolute() else Path(args.inputs)
    output_dir = (base / args.output) if not Path(args.output).is_absolute() else Path(args.output)

    if not config_path.exists():
        sys.exit(f"❌ Config niet gevonden: {config_path}\n"
                 f"   Kopieer config.example.json naar config.json en vul in.")
    cfg = json.loads(config_path.read_text(encoding='utf-8'))

    api_key = args.api_key or os.environ.get('ANTHROPIC_API_KEY')
    if not api_key:
        sys.exit("❌ Geen Anthropic API key. Set ANTHROPIC_API_KEY of gebruik --api-key.")

    output_dir.mkdir(parents=True, exist_ok=True)

    print(f"▶ Config:  {config_path}")
    print(f"▶ Inputs:  {inputs_dir}")
    print(f"▶ Output:  {output_dir}")
    print(f"▶ Model:   {args.model}")

    # DB-connectie voor persoon-matching
    db_cfg = cfg['db']
    print(f"\n▶ Verbinden met MySQL ({db_cfg['user']}@{db_cfg['host']}:{db_cfg.get('port', 3306)})...")
    db = pymysql.connect(
        host=db_cfg['host'],
        port=int(db_cfg.get('port', 3306)),
        user=db_cfg['user'],
        password=db_cfg['password'],
        database=db_cfg['database'],
        charset='utf8mb4',
    )
    persons = PersonsCache(db)
    db.close()

    # Claude client
    client = anthropic.Anthropic(api_key=api_key)

    jaren = sorted(cfg.get('wedstrijden', {}).keys())
    if args.jaar:
        if args.jaar not in jaren:
            sys.exit(f"❌ Jaar '{args.jaar}' niet in config. Beschikbaar: {jaren}")
        jaren = [args.jaar]

    for jaar in jaren:
        year_dir = inputs_dir / jaar
        if not year_dir.is_dir():
            print(f"\n⚠ Geen inputs/{jaar}/ map — skip")
            continue
        verwerk_jaar(jaar, cfg['wedstrijden'][jaar], year_dir,
                     client, args.model, persons, output_dir)

    print(f"\n✓ Klaar. Output in: {output_dir}")
    print(f"  Open elke .sql in phpMyAdmin → SQL-tab → paste → uitvoeren.")
    print(f"  Zoek op '-- TODO' voor onbekende rijders die handmatig moeten.")


if __name__ == '__main__':
    main()
