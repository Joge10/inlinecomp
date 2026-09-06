# -*- coding: utf-8 -*-
"""
InlineComp - survey-antwoorden samenvatten tot tmp/survey_stats.json

Leest tmp/survey_oh850.csv (phpMyAdmin-export, zie tools/survey_rapport.py voor
de query) en schrijft de aggregaten die het rapport nodig heeft.

Golf-indeling is op datum en bewust hardcoded: juni = OH850-ronde, juli/aug =
midden-seizoen, sept = JSC-finale. Bij een volgend seizoen aanpassen in wave().
"""
import csv
import collections
import json
import statistics as st

BRON = 'tmp/survey_oh850.csv'
DOEL = 'tmp/survey_stats.json'

SCORES = [
    'score_algemeen', 'score_nps', 'score_vergelijking', 'score_ontwikkeling',
    'score_public_snelheid', 'score_public_mobiel', 'score_public_uitslagen',
    'score_public_programma', 'score_coach_snelheid', 'score_coach_mobiel',
    'score_coach_uitslagen', 'score_coach_volgen',
]


def num(v):
    """Lege cel of MySQL-NULL -> None (vraag overgeslagen, telt niet mee)."""
    return None if v in (None, '', 'NULL') else int(v)


def wave(d):
    if d < '2026-07-01':
        return 'juni'
    if d < '2026-09-01':
        return 'juli/aug'
    return 'sept'


def gemiddelde(rijen, kolom):
    v = [num(r[kolom]) for r in rijen]
    v = [x for x in v if x is not None]
    return (round(st.mean(v), 2), len(v)) if v else (None, 0)


def main():
    rows = list(csv.DictReader(open(BRON, encoding='utf-8')))
    W = collections.OrderedDict((k, []) for k in ['juni', 'juli/aug', 'sept'])
    for r in rows:
        W[wave(r['submitted_at'])].append(r)

    uit = {'waves': list(W), 'n': {k: len(v) for k, v in W.items()}}
    for c in SCORES:
        uit[c] = {k: gemiddelde(v, c) for k, v in W.items()}
    uit['gebruik'] = {k: {a: sum(r['used_' + a] == '1' for r in v)
                          for a in ['public', 'coach', 'check']}
                      for k, v in W.items()}

    for kolom, naam in [('score_ontwikkeling', 'ontw_verdeling'),
                        ('score_algemeen', 'alg_verdeling')]:
        t = collections.Counter()
        for r in rows:
            x = num(r[kolom])
            if x:
                t[x] += 1
        uit[naam] = dict(sorted(t.items()))

    uit['eerste_keer'] = sum(r['ontwikkeling_eerste_keer'] == '1' for r in rows)

    with open(DOEL, 'w', encoding='utf-8') as f:
        json.dump(uit, f, ensure_ascii=False, indent=1)
    print('%s geschreven (%d reacties)' % (DOEL, len(rows)))


if __name__ == '__main__':
    main()
