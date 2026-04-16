from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.lib.colors import HexColor
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, HRFlowable

output = r"C:\Users\geert.devries\Documents\prive\inlinecomp\docs\InlineComp_Samenvatting.pdf"

doc = SimpleDocTemplate(output, pagesize=A4,
    topMargin=20*mm, bottomMargin=20*mm, leftMargin=20*mm, rightMargin=20*mm)

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name='DocTitle', fontName='Helvetica-Bold', fontSize=22,
    spaceAfter=4*mm, textColor=HexColor('#1a3a5c')))
styles.add(ParagraphStyle(name='DocSub', fontName='Helvetica', fontSize=12,
    spaceAfter=8*mm, textColor=HexColor('#666666')))
styles.add(ParagraphStyle(name='H1', fontName='Helvetica-Bold', fontSize=16,
    spaceBefore=8*mm, spaceAfter=3*mm, textColor=HexColor('#2e75b6')))
styles.add(ParagraphStyle(name='H2', fontName='Helvetica-Bold', fontSize=13,
    spaceBefore=5*mm, spaceAfter=2*mm, textColor=HexColor('#1a3a5c')))
styles.add(ParagraphStyle(name='H3', fontName='Helvetica-Bold', fontSize=11,
    spaceBefore=4*mm, spaceAfter=2*mm, textColor=HexColor('#333333')))
styles.add(ParagraphStyle(name='Bul', fontName='Helvetica', fontSize=9,
    leftIndent=12, spaceAfter=1.5*mm, leading=12))
styles.add(ParagraphStyle(name='Bod', fontName='Helvetica', fontSize=9,
    spaceAfter=2*mm, leading=12))
styles.add(ParagraphStyle(name='Cod', fontName='Courier', fontSize=8,
    leftIndent=12, spaceAfter=1*mm, leading=10, textColor=HexColor('#333333'),
    backColor=HexColor('#f5f5f5')))

story = []
dark = HexColor('#1a3a5c')
grey = HexColor('#f5f5f5')
white = HexColor('#ffffff')
W = 170*mm  # usable width

def hr():
    story.append(HRFlowable(width="100%", thickness=0.5, color=HexColor('#cccccc'),
        spaceBefore=3*mm, spaceAfter=3*mm))

def h1(t): story.append(Paragraph(t, styles['H1']))
def h2(t): story.append(Paragraph(t, styles['H2']))
def h3(t): story.append(Paragraph(t, styles['H3']))
def bu(t): story.append(Paragraph('\u2022 ' + t, styles['Bul']))
def bod(t): story.append(Paragraph(t, styles['Bod']))
def sp(n=2): story.append(Spacer(1, n*mm))
def cod(t): story.append(Paragraph(t, styles['Cod']))

def tbl(headers, rows, cw=None):
    data = [headers] + rows
    t = Table(data, colWidths=cw, repeatRows=1)
    t.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,0), dark),
        ('TEXTCOLOR', (0,0), (-1,0), white),
        ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
        ('FONTSIZE', (0,0), (-1,-1), 8),
        ('LEADING', (0,0), (-1,-1), 10),
        ('ALIGN', (0,0), (-1,-1), 'LEFT'),
        ('VALIGN', (0,0), (-1,-1), 'TOP'),
        ('GRID', (0,0), (-1,-1), 0.5, HexColor('#cccccc')),
        ('ROWBACKGROUNDS', (0,1), (-1,-1), [white, grey]),
        ('TOPPADDING', (0,0), (-1,-1), 3),
        ('BOTTOMPADDING', (0,0), (-1,-1), 3),
        ('LEFTPADDING', (0,0), (-1,-1), 4),
        ('RIGHTPADDING', (0,0), (-1,-1), 4),
    ]))
    story.append(t)
    sp(3)

# TITLE
story.append(Paragraph("InlineComp", styles['DocTitle']))
story.append(Paragraph("Technische Samenvatting \u2014 April 2026", styles['DocSub']))
hr()

h1("Wat is InlineComp?")
bod("Wedstrijdbeheersysteem voor inline skaten (KNSB niveau). Cloud-only PHP+MySQL architectuur op iFastNet hosting (inlineresults.devriesen.com). SPA frontend met vanilla JS, geen framework. Gebouwd door Geert de Vries.")
hr()

h1("Modules")

h2("1. Importeer")
bu("Haalt wedstrijden + deelnemers op van KNSB API (inschrijven.schaatsen.nl)")
bu("Distance Combinations beheer: samenvoegen, splitsen, afstanden configureren")
bu("Dirty-tracking met oranje opslaan-knop + waarschuwing bij wegnavigeren")
bu("Structurele wijzigingen geblokkeerd bij bestaand programma (labels altijd bewerkbaar)")
bu("Label-wijzigingen propageren naar tijdschema_ritten + heats via REPLACE")
bu("Datumfilter stuurt parameter naar API voor oudere wedstrijden")
bu("DC-beheer pas zichtbaar na eerste import")

h2("2. Tijdschema")
bu("Twee systemen: <b>full-final</b> en <b>internationaal-nieuw</b> (Q/q kwalificatie)")
bu("Blokken: ronde, pauze, inrijden, wedstrijdstart, ceremonie, herstart")
bu("Per-afstand: finale_seeding (slang/tijdkoppeling), race_type, ranking methods per ronde")
bu("Per-categorie: heats, kwartfinale, halve finale, runner-up, finale config")
bu("<b>Wis programma</b>: verwijdert alles (ritten, blokken, config, heats)")
bu("Polling met backoff (stopt na 3 fouten)")

h2("3. Startlijsten")
bu("Loting: startnummer, alfabetisch, tussenklassement (wedstrijd), klassement (serie)")
bu("Tussenklassement: genereer disabled zonder data, gesanctioneerden achteraan")
bu("Blokkade zonder gegenereerd programma")
bu("Slangenpatroon, tijdkoppeling voor 200m DTT")

h2("4. Live verwerking")
bu("Carousel heat-cards + linker panel (alle rijders in ronde)")
bu("CSV import (nr + tijd, optioneel rondes)")
bu("<b>Rondes input</b>: bewerkbaar in heat-card en panel, synct")
bu("9 sanctiecodes, automatische finishpositie berekening")
bu("Puntenkoers: punten DESC \u2192 rondes DESC \u2192 tijd ASC")
bu("Auto-genereer volgende ronde, cleanup per ronde-type")
bu("Ex-aequo overflow met extra heats")

h2("5. Uitslag")
bu("<b>Full-final</b>: gecombineerd (serie+finale) of normaal (finales gestapeld)")
bu("<b>Internationaal</b>: cascading elimination ranking")
bu("Ranking methods per ronde instelbaar in uitslag-tab")
bu("Rondes + PK-punten kolommen automatisch")
bu("Print voor alle modi, afdruk alleen beschikbare afstanden")

h2("6. Klassement")
bu("Punten per afstand, bewerkbaar voor gesanctioneerden")
bu("Vastleggen alleen als alle afstanden bevestigd")
bu("Tab-kleuren: groen/oranje/standaard")

h2("7. Public site")
bu("Programma, Heats, Resultaten tabs")
bu("Rate limiting, smart refresh")

hr()
h1("Sanctie-systeem (World Skate Rulebook 2026)")
bod("DB = UI codes. DNS eerste ronde = 0 punten (art. 144.4).")
sp()
tbl(['Code', 'Betekenis', 'Ranking-effect'],
    [['FS', 'False Start', 'Geen \u2014 tijd bewaard, normale positie'],
     ['W1, W2', 'Warning', 'Geen automatisch effect (jury)'],
     ['RR', 'Reduction in Rank', 'Geen automatisch effect, rijder gaat door'],
     ['DQ-TF', 'Technical Fault', 'Ranked last in round'],
     ['DNS', 'Did Not Start', 'Ranked last of 0 pnt (eerste ronde)'],
     ['DNF', 'Did Not Finish', 'Ranked last in round'],
     ['DQ-SF', 'Sports Fault', 'Not ranked, 0 punten'],
     ['DQ-DF', 'Disciplinary Fault', 'Not ranked, 0 punten']],
    cw=[40, 85, W-125])

h1("Sorteerlogica per race type")
tbl(['Race type', 'Volgorde', 'Ex-aequo als'],
    [['Puntenkoers', 'punten DESC \u2192 rondes DESC \u2192 tijd ASC', 'punten+rondes+tijd gelijk'],
     ['Lange afstand', 'rondes DESC \u2192 tijd ASC', 'rondes+tijd gelijk'],
     ['Sprint (pos+tijd)', 'positie ASC \u2192 tijd ASC', 'positie+tijd gelijk'],
     ['Sprint (tijd)', 'tijd ASC', 'tijd gelijk']],
    cw=[70, 110, W-180])

story.append(PageBreak())

h1("Database (24 tabellen)")
bod("Per-tabel SQL in /db/. Cascade: competitions \u2192 DC \u2192 entries/heats \u2192 results. Uitslag GEEN cascade (archief).")
sp()
tbl(['Groep', 'Tabellen'],
    [['Basis', 'persons, competitions, competition_instellingen, competition_startnummers, distance_combinations, distances, entries, transponders, dc_splits'],
     ['Heats', 'heats, heat_entries, results, point_systems'],
     ['Tijdschema', 'competition_tijdschema, tijdschema_afstand_config, tijdschema_cat_config, tijdschema_blokken, tijdschema_ritten'],
     ['Uitslag', 'uitslag_afstand, uitslag_klassement'],
     ['Organisaties', 'organisaties, organisatie_aliassen, organisatie_sponsors'],
     ['Overig', 'klassementen, klassement_posities, series, series_wedstrijden, users, sessions, login_logs']],
    cw=[60, W-60])

hr()
h1("Architectuur")
bu("Global fetch interceptor voor 401 \u2192 login modal")
bu("toonBevestigDialog() voor alle bevestigingen")
bu("html2pdf.js + JSZip voor PDF download")
bu("_heeftProgramma flag in app.js + import.js")
bu("CSV: TIMING_BASE_DIR, encoding/separator auto-detectie")

hr()
h1("Bestandsstructuur")
cod("api/ \u2014 PHP endpoints")
cod("api/_uitslag_helper.php \u2014 gedeelde ranking functies")
cod("js/ \u2014 vanilla JS modules")
cod("css/style.css \u2014 styling + CSS variables")
cod("db/ \u2014 per-tabel SQL bestanden")
cod("public/ \u2014 publieke rijder-lookup")
cod("auth/ \u2014 sessie/login systeem")

doc.build(story)
print("OK:", output)
