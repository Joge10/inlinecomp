# -*- coding: utf-8 -*-
"""
InlineComp - survey-evaluatie rapport (proefseizoen 2026)

Bouwt een A4-rapport (HTML -> PDF) uit de survey-antwoorden: gemiddelden per
periode, deelscores per app, gebruik per app en de score-verdelingen.

Gebruik:
  1. Exporteer in phpMyAdmin als CSV:
       SELECT id, submitted_at, lang, used_public, used_coach, used_check,
              used_geen, used_unaware, competition_ids, score_algemeen, score_nps,
              score_public_snelheid, score_public_mobiel, score_public_uitslagen,
              score_public_programma, score_coach_snelheid, score_coach_mobiel,
              score_coach_uitslagen, score_coach_volgen, score_check_snelheid,
              score_check_mobiel, score_check_duidelijk, kent_sportity,
              kent_skateresults, kent_combinatie, kent_anders, kent_geen,
              score_vergelijking, score_ontwikkeling, ontwikkeling_eerste_keer
       FROM survey_oh850 ORDER BY submitted_at;
     -> tmp/survey_oh850.csv
  2. python tools/survey_stats.py   (of het inline-blok onderaan dit bestand)
     -> tmp/survey_stats.json
  3. python tools/survey_rapport.py -> tmp/survey-evaluatie.html
  4. HTML naar PDF (geen LaTeX nodig, Chrome doet het):
       chrome --headless --disable-gpu --no-pdf-header-footer          --print-to-pdf=docs/survey-evaluatie-2026.pdf tmp/survey-evaluatie.html

De golf-indeling (juni / juli-aug / sept) staat in survey_stats.json en is
hardcoded op datum; bij een volgend seizoen even aanpassen.

Kleuren komen uit een gevalideerd categorisch palet (blauw/oranje/aqua/geel):
onderling onderscheidbaar bij kleurenblindheid. Niet zomaar vervangen.
"""
import json, io

S = json.load(open('tmp/survey_stats.json', encoding='utf-8'))
C = {'blue': '#2a78d6', 'orange': '#eb6834', 'aqua': '#1baf7a', 'yellow': '#eda100'}
INK, INK2, MUTED, GRID = '#0b0b0b', '#52514e', '#7a7975', '#e4e3df'


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;')


def grouped(series, cats, ymax=5, w=620, h=210, dec=1):
    ml, mr, mt, mb = 34, 8, 12, 40
    pw, ph = w - ml - mr, h - mt - mb
    o = ['<svg viewBox="0 0 %d %d" width="100%%" role="img">' % (w, h)]
    steps = 5
    for i in range(steps + 1):
        y = mt + ph - (ph * i / steps)
        v = ymax * i / steps
        o.append('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-width="1"/>'
                 % (ml, y, w - mr, y, GRID))
        o.append('<text x="%d" y="%.1f" text-anchor="end" font-size="9" fill="%s">%g</text>'
                 % (ml - 6, y + 3.5, MUTED, round(v, 1)))
    gw = pw / len(cats)
    n = len(series)
    bw = min(30.0, (gw - 14) / n)
    for ci, cat in enumerate(cats):
        gx = ml + gw * ci
        for si, (naam, kleur, vals) in enumerate(series):
            v = vals[ci]
            if v is None:
                continue
            bh = ph * v / ymax
            x = gx + gw / 2 - (n * bw + 2 * (n - 1)) / 2 + si * (bw + 2)
            y = mt + ph - bh
            r = min(4.0, bh)
            o.append('<path d="M%.1f %.1f L%.1f %.1f Q%.1f %.1f %.1f %.1f L%.1f %.1f Q%.1f %.1f %.1f %.1f L%.1f %.1f Z" fill="%s"/>'
                     % (x, mt + ph, x, y + r, x, y, x + r, y,
                        x + bw - r, y, x + bw, y, x + bw, y + r,
                        x + bw, mt + ph, kleur))
            lbl = ('%.2f' % v).rstrip('0').rstrip('.') if dec else '%g' % v
            o.append('<text x="%.1f" y="%.1f" text-anchor="middle" font-size="9.5" font-weight="600" fill="%s">%s</text>'
                     % (x + bw / 2, y - 4, INK, lbl))
        o.append('<text x="%.1f" y="%d" text-anchor="middle" font-size="10" fill="%s">%s</text>'
                 % (gx + gw / 2, mt + ph + 15, INK2, esc(cat)))
    o.append('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>'
             % (ml, mt + ph, w - mr, mt + ph, MUTED))
    o.append('</svg>')
    return '\n'.join(o)


def legend(series):
    it = ''.join('<span class="lg"><i style="background:%s"></i>%s</span>' % (k, esc(n))
                 for n, k, _ in series)
    return '<div class="legend">%s</div>' % it


def blok(titel, series, cats, **kw):
    return '<h3>%s</h3>%s%s' % (esc(titel), legend(series), grouped(series, cats, **kw))


def g(key, w):
    return S[key][w][0]


W2 = ['juni', 'juli/aug', 'sept']
WL = ['juni  (n=13)', 'juli/aug  (n=14)', 'sept  (n=1)']

s1 = [('Algemeen oordeel', C['blue'], [g('score_algemeen', w) for w in W2]),
      ('Zou je het aanraden', C['orange'], [g('score_nps', w) for w in W2]),
      ('T.o.v. wat je kende', C['aqua'], [g('score_vergelijking', w) for w in W2])]

PA = ['score_public_snelheid', 'score_public_mobiel', 'score_public_uitslagen', 'score_public_programma']
s2 = [('juni (n=12)', C['blue'], [S[k]['juni'][0] for k in PA]),
      ('juli/aug (n=10)', C['orange'], [S[k]['juli/aug'][0] for k in PA])]

s3 = [('Publiek', C['blue'], [S['gebruik'][w]['public'] for w in W2]),
      ('Coach', C['orange'], [S['gebruik'][w]['coach'] for w in W2]),
      ('Check-in', C['aqua'], [S['gebruik'][w]['check'] for w in W2])]

av, ov = S['alg_verdeling'], S['ontw_verdeling']
s4 = [('aantal antwoorden', C['blue'], [av.get(str(i), 0) for i in range(1, 6)])]
s5 = [('aantal antwoorden', C['aqua'], [ov.get(str(i), 0) for i in range(1, 6)])]

D = dict(
    alg_j=g('score_algemeen', 'juni'), alg_ja=g('score_algemeen', 'juli/aug'), alg_s=g('score_algemeen', 'sept'),
    nps_j=g('score_nps', 'juni'), nps_ja=g('score_nps', 'juli/aug'), nps_s=g('score_nps', 'sept'),
    ver_j=g('score_vergelijking', 'juni'), ver_ja=g('score_vergelijking', 'juli/aug'), ver_s=g('score_vergelijking', 'sept'),
    ont_ja=g('score_ontwikkeling', 'juli/aug'), ont_s=g('score_ontwikkeling', 'sept'),
    ps_j=S['score_public_snelheid']['juni'][0], ps_ja=S['score_public_snelheid']['juli/aug'][0], ps_s=S['score_public_snelheid']['sept'][0],
    pm_j=S['score_public_mobiel']['juni'][0], pm_ja=S['score_public_mobiel']['juli/aug'][0], pm_s=S['score_public_mobiel']['sept'][0],
    pu_j=S['score_public_uitslagen']['juni'][0], pu_ja=S['score_public_uitslagen']['juli/aug'][0], pu_s=S['score_public_uitslagen']['sept'][0],
    pp_j=S['score_public_programma']['juni'][0], pp_ja=S['score_public_programma']['juli/aug'][0], pp_s=S['score_public_programma']['sept'][0],
    cs_j=S['score_coach_snelheid']['juni'][0], cs_ja=S['score_coach_snelheid']['juli/aug'][0], cs_s=S['score_coach_snelheid']['sept'][0],
    cv_j=S['score_coach_volgen']['juni'][0], cv_ja=S['score_coach_volgen']['juli/aug'][0], cv_s=S['score_coach_volgen']['sept'][0],
    ch1=blok('Kernvragen, gemiddelde per periode', s1, WL),
    ch2=blok('Publieke pagina, deelscores per periode', s2, ['snelheid', 'mobiel', 'uitslagen', 'programma']),
    ch3=blok('Aantal respondenten per app', s3, WL, ymax=15, dec=0),
    ch4=blok('Algemeen oordeel (n=27)', s4, ['1', '2', '3', '4', '5'], ymax=15, dec=0, w=300, h=190),
    ch5=blok('Ontwikkeling t.o.v. vorige keer (n=12)', s5, ['1', '2', '3', '4', '5'], ymax=10, dec=0, w=300, h=190),
    INK=INK, INK2=INK2, GEEL=C['yellow'],
)

HTML = """<title>InlineComp - survey-evaluatie proefseizoen 2026</title>
<style>
@page {{ size: A4; margin: 16mm 15mm; }}
body {{ font-family:"Segoe UI",Calibri,Arial,sans-serif; font-size:10.5pt; line-height:1.5;
        color:{INK}; background:#fcfcfb; }}
h1 {{ font-size:19pt; margin:0 0 2px; }}
.sub {{ color:{INK2}; font-size:10pt; margin-bottom:14px; }}
h2 {{ font-size:13pt; margin:22px 0 6px; border-bottom:1px solid #ccc; padding-bottom:3px;
      page-break-after:avoid; }}
h3 {{ font-size:10.5pt; margin:12px 0 2px; page-break-after:avoid; }}
p {{ margin:6px 0; }}
.legend {{ font-size:9pt; color:{INK2}; margin-bottom:2px; }}
.lg {{ margin-right:12px; white-space:nowrap; }}
.lg i {{ display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:4px;
         vertical-align:-1px; }}
.kaart {{ border:1px solid #e0dfdb; border-radius:6px; padding:8px 12px 4px; margin:10px 0;
          background:#fff; page-break-inside:avoid; }}
.rij {{ display:flex; gap:12px; }}
.rij > div {{ flex:1; }}
.let {{ background:#fdf6e9; border-left:3px solid {GEEL}; padding:8px 11px; font-size:9.5pt;
        margin:12px 0; }}
table {{ border-collapse:collapse; width:100%; font-size:9pt; margin-top:6px; }}
th,td {{ border:1px solid #ddd; padding:3px 6px; text-align:left; }}
th {{ background:#f2f1ee; }}
td.n {{ text-align:right; }}
.klein {{ font-size:9pt; color:{INK2}; }}
</style>

<h1>Survey-evaluatie proefseizoen 2026</h1>
<div class="sub">InlineComp &middot; 28 reacties tussen 22 juni en 6 september 2026 &middot; alle schalen 1-5</div>

<div class="let"><b>Lees dit eerst.</b> Met 28 reacties in totaal, verdeeld over drie momenten,
is dit geen meting maar een indruk. De september-golf bestaat uit &eacute;&eacute;n reactie en zegt
op zichzelf niets; die staat er alleen in om het beeld compleet te maken. Waar hieronder
"stijging" staat, lees "de paar mensen die reageerden waren iets positiever" - geen aangetoonde
trend.</div>

<h2>1. De grote lijn</h2>
<div class="kaart">{ch1}</div>
<p>De drie kernvragen bewegen alle drie licht omhoog tussen juni en juli/augustus. Het duidelijkst
is "zou je het aanraden": van {nps_j} naar {nps_ja}. Ook de vergelijking met wat mensen al kenden
(Sportity, SkateResults) gaat van {ver_j} naar {ver_ja}. Het algemene oordeel blijft het vlakst -
dat zat vanaf het begin al rond de 4.</p>

<h2>2. Waar de winst zit: de publieke pagina</h2>
<div class="kaart">{ch2}</div>
<p>Hier zit het interessantste patroon van de hele evaluatie. De twee onderdelen die in juni het
laagst scoorden - <b>uitslagen</b> ({pu_j}) en <b>programma</b> ({pp_j}) - zijn precies de twee die
het sterkst gestegen zijn, naar {pu_ja} en {pp_ja}. Snelheid en mobiel scoorden al goed en bleven
gelijk. Dat is het profiel dat je hoopt te zien: de zwakste plekken zijn opgepakt zonder dat de
sterke inleverden.</p>

<h2>3. Wie gebruikte wat</h2>
<div class="kaart">{ch3}</div>
<p>De publieke pagina is de constante factor. Opvallend is de verschuiving daaronder: in juni was
check-in het tweede kanaal (7 van de 13 respondenten), in juli/augustus is dat de coach-omgeving
(5 van de 14). Dat is ook logisch - check-in speelt vooral rond de balie op de wedstrijddag zelf,
en de coach-omgeving bestond in juni nog nauwelijks.</p>

<h2>4. Verdelingen, niet alleen gemiddelden</h2>
<div class="rij">
<div class="kaart">{ch4}</div>
<div class="kaart">{ch5}</div>
</div>
<p>Twee dingen vallen op. Bij het algemene oordeel is er <b>geen enkele 1 of 2</b> gegeven: de
laagste score in de hele set is een 3, en die is drie keer voorgekomen. En op de vraag of het beter
is geworden sinds de vorige keer antwoordt meer dan de helft met een 5, de hoogste score. Niemand
vond dat het achteruit ging.</p>

<h2>5. Cijfers op een rij</h2>
<table>
<tr><th>Vraag</th><th class="n">juni</th><th class="n">juli/aug</th><th class="n">sept</th></tr>
<tr><td>Algemeen oordeel</td><td class="n">{alg_j}</td><td class="n">{alg_ja}</td><td class="n">{alg_s}</td></tr>
<tr><td>Zou je het aanraden</td><td class="n">{nps_j}</td><td class="n">{nps_ja}</td><td class="n">{nps_s}</td></tr>
<tr><td>T.o.v. wat je kende</td><td class="n">{ver_j}</td><td class="n">{ver_ja}</td><td class="n">{ver_s}</td></tr>
<tr><td>Ontwikkeling sinds vorige keer</td><td class="n">-</td><td class="n">{ont_ja}</td><td class="n">{ont_s}</td></tr>
<tr><td>Publiek - snelheid</td><td class="n">{ps_j}</td><td class="n">{ps_ja}</td><td class="n">{ps_s}</td></tr>
<tr><td>Publiek - mobiel</td><td class="n">{pm_j}</td><td class="n">{pm_ja}</td><td class="n">{pm_s}</td></tr>
<tr><td>Publiek - uitslagen</td><td class="n">{pu_j}</td><td class="n">{pu_ja}</td><td class="n">{pu_s}</td></tr>
<tr><td>Publiek - programma</td><td class="n">{pp_j}</td><td class="n">{pp_ja}</td><td class="n">{pp_s}</td></tr>
<tr><td>Coach - snelheid</td><td class="n">{cs_j} <span class="klein">(n=1)</span></td><td class="n">{cs_ja}</td><td class="n">{cs_s}</td></tr>
<tr><td>Coach - rijders volgen</td><td class="n">{cv_j} <span class="klein">(n=1)</span></td><td class="n">{cv_ja}</td><td class="n">{cv_s}</td></tr>
</table>
<p class="klein">De coach-scores voor juni berusten op &eacute;&eacute;n respondent en zijn niet
vergelijkbaar. Check-in is uit de vergelijking gelaten: 7 respondenten in juni tegen 1 daarna.</p>

<h2>6. Wat ik eruit haal</h2>
<p><b>Het gebruik verschoof mee met wat er gebouwd werd.</b> Check-in domineerde in juni, de
coach-omgeving nam het in juli over. Dat is precies de volgorde waarin die onderdelen zijn
ontstaan.</p>
<p><b>De verbeteringen landden waar ze bedoeld waren.</b> Uitslagen en programma waren in juni de
zwakste onderdelen en zijn nu de sterkste. Dat is de enige beweging in dit document waar ik enig
gewicht aan durf te hangen, en zelfs die rust op tien tot twaalf mensen.</p>
<p><b>Er is geen enkel signaal van achteruitgang.</b> Geen 1 of 2 op het algemene oordeel, en op de
ontwikkelingsvraag niets onder de 3. Bij deze aantallen is dat geen bewijs van kwaliteit, maar het
is wel de afwezigheid van een waarschuwing.</p>
<p class="klein">Bron: tabel <code>survey_oh850</code>, 28 rijen, geexporteerd 6 september 2026.
De open antwoorden (<code>tip_open</code>, <code>miste_open</code>) zijn hier niet in meegenomen.</p>
"""

io.open('tmp/survey-evaluatie.html', 'w', encoding='utf-8').write(HTML.format(**D))
print('html ok')
