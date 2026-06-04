#!/usr/bin/env python3
"""
InlineComp – Promotie-poster generator

Bouwt een A4-PDF met titel, QR-code en instructies voor rijders/ouders om op
de wedstrijdlocatie op te hangen. Optioneel te personaliseren per organisatie
en per wedstrijd.

Gebruik (CLI)
    python poster_gen.py --output poster.pdf
        [--qr-url URL]
        [--org-naam NAAM] [--org-logo /abs/pad/naar/logo.png]
        [--comp-naam NAAM] [--comp-datum "15 mei 2026"] [--comp-locatie "Baan X"]
        [--sponsors "Sponsor A:/abs/pad/logoA.png|Sponsor B:/abs/pad/logoB.png"]

Zonder extra args genereert het script de generieke poster met QR-code naar
/public/. Bij meegegeven org/comp-info verschijnen die op de poster.
"""

import argparse
import io
import os
import sys

try:
    import qrcode
    from PIL import Image, ImageDraw, ImageFont
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.units import mm
    from reportlab.lib.colors import HexColor, white
    from reportlab.pdfgen import canvas
    from reportlab.lib.utils import ImageReader
except ImportError as e:
    print(f"ERROR: ontbrekende Python-library: {e}", file=sys.stderr)
    print("Installeer met: pip install qrcode pillow reportlab", file=sys.stderr)
    sys.exit(2)

# SVG-ondersteuning is optioneel — sommige sponsors uploaden een .svg
# (bijv. Oomssport). Zonder svglib skippen we die netjes.
# SVG's worden als *vector* op het canvas getekend via reportlab.renderPDF
# (geen cairo/renderPM nodig die op shared hosting vaak niet werkt).
try:
    from svglib.svglib import svg2rlg
    from reportlab.graphics import renderPDF
    HEEFT_SVG = True
except ImportError:
    HEEFT_SVG = False


BLAUW       = HexColor('#1F4E79')
ORANJE      = HexColor('#E8630A')
LICHTBLAUW  = HexColor('#D6E4F0')
LICHTORANJE = HexColor('#FFF1E0')   # warning-vak achtergrond
DONKERORANJE= HexColor('#7A3500')   # warning-tekst (donker, leesbaar op lichtoranje)
GRIJS       = HexColor('#999999')
WIT         = white


def parse_sponsors(raw):
    """sponsors-string 'Naam1:pad1|Naam2:pad2' -> list of (naam, pad)."""
    if not raw:
        return []
    resultaat = []
    for item in raw.split('|'):
        if ':' not in item:
            continue
        naam, pad = item.split(':', 1)
        naam = naam.strip()
        pad  = pad.strip()
        if not naam:
            continue
        if pad and not os.path.isfile(pad):
            print(f"WAARSCHUWING: sponsor-logo niet gevonden: {pad}", file=sys.stderr)
            pad = ''
        resultaat.append((naam, pad))
    return resultaat


def laad_logo(pad):
    """Laadt een logo-bestand.

    Retourneert een tuple (kind, data, breedte, hoogte):
      kind='pil'  : data is een PIL Image (PNG/JPG/WEBP)
      kind='svg'  : data is een reportlab Drawing (vector)
      kind=None   : logo kon niet geladen worden
    """
    if not pad or not os.path.isfile(pad):
        return (None, None, 0, 0)

    if pad.lower().endswith('.svg'):
        if not HEEFT_SVG:
            print(f"WAARSCHUWING: SVG gevonden ({pad}) maar svglib niet geinstalleerd.",
                  file=sys.stderr)
            return (None, None, 0, 0)
        try:
            drawing = svg2rlg(pad)
            if drawing is None or drawing.width == 0 or drawing.height == 0:
                return (None, None, 0, 0)
            return ('svg', drawing, drawing.width, drawing.height)
        except Exception as e:
            print(f"WAARSCHUWING: SVG parse mislukt ({pad}): {e}", file=sys.stderr)
            return (None, None, 0, 0)

    try:
        pil = Image.open(pad).convert('RGBA')
        return ('pil', pil, pil.width, pil.height)
    except Exception as e:
        print(f"WAARSCHUWING: Logo laden mislukt ({pad}): {e}", file=sys.stderr)
        return (None, None, 0, 0)


def teken_logo(c, kind, data, x, y, w, h):
    """Teken een logo op canvas `c` binnen het vak (x, y, w, h).

    SVG wordt als vector getekend (uniform schalen, origineel center-aligned).
    Raster wordt als PNG gedrukt met preserveAspectRatio.
    """
    if kind == 'svg':
        dw, dh = data.width, data.height
        if dw == 0 or dh == 0:
            return
        # Uniform schalen zodat het in (w, h) past
        s = min(w / dw, h / dh)
        data.scale(s, s)
        data.width  = dw * s
        data.height = dh * s
        # Center binnen het vak
        off_x = (w - data.width)  / 2
        off_y = (h - data.height) / 2
        renderPDF.draw(data, c, x + off_x, y + off_y)
    elif kind == 'pil':
        buf = io.BytesIO()
        data.save(buf, format='PNG')
        buf.seek(0)
        try:
            c.drawImage(ImageReader(buf), x, y, w, h,
                        preserveAspectRatio=True, mask='auto')
        except Exception as e:
            print(f"WAARSCHUWING: logo niet getekend: {e}", file=sys.stderr)


def bouw_qr(qr_url, kleur_hex='#1F4E79'):
    """Kale QR-code zonder middenlogo.

    Het IC-logo wordt later door reportlab op de canvas getekend (vector),
    dat geeft strakkere typografie dan het met PIL in een PIL-image rasteren.
    Wel error_correction=H aanhouden zodat het overlappende logo de scanbaarheid
    niet aantast.
    """
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_H,
        box_size=10,
        border=2,
    )
    qr.add_data(qr_url)
    qr.make(fit=True)
    return qr.make_image(fill_color=kleur_hex, back_color='white').convert('RGB')


def teken_ic_logo(c, cx, cy, size):
    """Teken het favicon-InlineComp-logo gecentreerd op (cx, cy) met given size.

    Zelfde layout als favicon.svg:
      - blauw afgerond vierkant (rx = 18.75% = 6/32)
      - wit 'IC' bold tekst
      - oranje streepje onderin (62% breed × 9% hoog, op 78% hoogte)
    """
    # Wit kadertje iets groter dan het logo zelf — maskeert QR-modules
    kader_size = size * 1.18
    c.setFillColor(WIT)
    c.roundRect(cx - kader_size / 2, cy - kader_size / 2,
                kader_size, kader_size,
                kader_size * 0.08, fill=True, stroke=False)

    # Blauw afgerond vierkant
    c.setFillColor(BLAUW)
    c.roundRect(cx - size / 2, cy - size / 2,
                size, size,
                size * 0.1875, fill=True, stroke=False)

    # 'IC' tekst: op 50%-hoogte, drawCentredString tekent vanaf de baseline
    # dus we schuiven iets omlaag zodat het visueel in de bovenste helft valt.
    c.setFillColor(WIT)
    c.setFont('Helvetica-Bold', size * 0.5)
    c.drawCentredString(cx, cy - size * 0.07, "IC")

    # Oranje streepje
    bar_w = size * 0.62
    bar_h = size * 0.09
    c.setFillColor(ORANJE)
    c.roundRect(cx - bar_w / 2,
                cy - size * 0.32,
                bar_w, bar_h,
                bar_h / 2, fill=True, stroke=False)


def genereer_poster(args):
    width, height = A4
    c = canvas.Canvas(args.output, pagesize=A4)

    heeft_org  = bool(args.org_naam)
    heeft_comp = bool(args.comp_naam)
    sponsors   = parse_sponsors(args.sponsors)
    is_coach   = args.app_type == 'coach'

    # i18n: alle tekst-strings per taal. Gebruik T(key, **fmt) hieronder om
    # de juiste taal te kiezen. Nieuwe taal toevoegen = nieuwe dict + cli-keuze.
    I18N = {
        'nl': {
            'sub_coach_org':       u'Voor coaches bij {org}',
            'sub_coach_geen':      u'Voor coaches: volg al je rijders live',
            'sub_public_org':      u'Wedstrijden bij {org}',
            'sub_public_geen':     u'Volg je wedstrijd live!',
            'scan':                u'Scan de QR-code met je telefoon',
            'stap1_kies_naam':     u'Kies "{naam}"',
            'stap1_kies_alg':      u'Kies je wedstrijd',
            'stap_coach_2':        u'Voeg rijders toe via club, sponsor of startnummer',
            'stap_coach_3':        u'Volg programma, heats, sancties en uitslagen',
            'stap_public_2':       u'Vul je startnummer in',
            'stap_public_3':       u'Bekijk je heats, tijden en resultaten',
            'sportity_kanaal':     u'Officiële einduitslagen + klassementen via Sportity (kanaal: {kanaal}).',
            'sportity_geen':       u'Officiële einduitslagen + klassementen via Sportity.',
            'disclaimer_label':    u'LET OP — TESTFASE',
            'disclaimer_tekst':    u'Aan de informatie in InlineComp kunnen geen rechten worden ontleend.',
            'sponsors_titel':      u'Mede mogelijk gemaakt door:',
            'tip_coach':           u'Tip: voeg InlineComp Coach toe aan je startscherm voor snelle toegang!',
            'tip_public':          u'Tip: voeg InlineComp toe aan je startscherm voor snelle toegang!',
            'coach_pw_label':      u'Coach-wachtwoord:',
        },
        'en': {
            'sub_coach_org':       u'For coaches at {org}',
            'sub_coach_geen':      u'For coaches: follow all your skaters live',
            'sub_public_org':      u'Races at {org}',
            'sub_public_geen':     u'Follow your race live!',
            'scan':                u'Scan the QR code with your phone',
            'stap1_kies_naam':     u'Choose "{naam}"',
            'stap1_kies_alg':      u'Choose your race',
            'stap_coach_2':        u'Add skaters by club, sponsor or bib number',
            'stap_coach_3':        u'Follow program, heats, penalties and results',
            'stap_public_2':       u'Enter your bib number',
            'stap_public_3':       u'View your heats, times and results',
            'sportity_kanaal':     u'Official final results + standings via Sportity (channel: {kanaal}).',
            'sportity_geen':       u'Official final results + standings via Sportity.',
            'disclaimer_label':    u'NOTE — TEST PHASE',
            'disclaimer_tekst':    u'No rights can be derived from the information in InlineComp.',
            'sponsors_titel':      u'Made possible by:',
            'tip_coach':           u'Tip: add InlineComp Coach to your home screen for quick access!',
            'tip_public':          u'Tip: add InlineComp to your home screen for quick access!',
            'coach_pw_label':      u'Coach password:',
        },
    }
    lang = args.lang if args.lang in I18N else 'nl'
    def T(key, **fmt):
        s = I18N[lang].get(key, I18N['nl'].get(key, key))
        return s.format(**fmt) if fmt else s

    # ── Blauwe header ─────────────────────────────────────────────────────
    header_h = 82 * mm if heeft_comp else 80 * mm
    c.setFillColor(BLAUW)
    c.rect(0, height - header_h, width, header_h, fill=True, stroke=False)

    c.setFillColor(ORANJE)
    c.rect(0, height - header_h - 4 * mm, width, 4 * mm, fill=True, stroke=False)

    c.setFillColor(WIT)
    c.setFont('Helvetica-Bold', 48)
    # Titel is altijd 'InlineComp' — past dan netjes naast het org-logo.
    # Coach-onderscheid maakt de subtitle ('Voor coaches…') al duidelijk.
    c.drawCentredString(width / 2, height - 30 * mm, 'InlineComp')

    if is_coach:
        sub = T('sub_coach_org', org=args.org_naam) if heeft_org else T('sub_coach_geen')
    else:
        sub = T('sub_public_org', org=args.org_naam) if heeft_org else T('sub_public_geen')
    c.setFont('Helvetica', 18)
    c.setFillColor(LICHTBLAUW)
    c.drawCentredString(width / 2, height - 44 * mm, sub)

    c.setStrokeColor(ORANJE)
    c.setLineWidth(2.5)
    c.line(width / 2 - 60 * mm, height - 49 * mm,
           width / 2 + 60 * mm, height - 49 * mm)

    if heeft_comp:
        # Vaste twee-regel-layout op 14pt:
        #   regel 1: comp_naam (\u2014 comp_datum)
        #   regel 2: comp_locatie
        # Als datum/locatie ontbreken vallen de bijbehorende delen weg.
        regel1_stukken = [args.comp_naam]
        if args.comp_datum:
            regel1_stukken.append(args.comp_datum)
        regel1 = ' \u2014 '.join(regel1_stukken)
        regel2 = args.comp_locatie or ''

        c.setFillColor(WIT)
        c.setFont('Helvetica-Bold', 14)
        if regel2:
            c.drawCentredString(width / 2, height - 56 * mm, regel1)
            c.drawCentredString(width / 2, height - 62 * mm, regel2)
        else:
            c.drawCentredString(width / 2, height - 58 * mm, regel1)

    c.setFillColor(WIT)
    c.setFont('Helvetica', 15)
    instr_y = height - (70 if heeft_comp else 61) * mm
    c.drawCentredString(width / 2, instr_y, T('scan'))

    c.setFont('Helvetica-Bold', 24)
    c.setFillColor(ORANJE)
    c.drawCentredString(width / 2, instr_y - 9 * mm, chr(9660))

    # Organisatie-logo in header (rechtsboven)
    # Witte afgeronde kaart met het logo erin. Werkt voor zowel raster
    # (PNG/JPG) als vector (SVG via reportlab.renderPDF).
    org_kind, org_data, org_w, org_h = laad_logo(args.org_logo) if args.org_logo else (None, None, 0, 0)
    if org_kind is not None:
        logo_max_h = 32 * mm
        logo_max_w = 40 * mm
        ratio = org_w / org_h if org_h else 1.0
        if ratio >= 1:
            draw_w = min(logo_max_w, logo_max_h * ratio)
            draw_h = draw_w / ratio
        else:
            draw_h = logo_max_h
            draw_w = draw_h * ratio
        marge = 8 * mm

        # Witte kaart als achtergrond (contrast met blauwe header)
        kaart_pad = 2 * mm
        card_x = width  - draw_w - marge - kaart_pad
        card_y = height - draw_h - marge - kaart_pad
        c.setFillColor(WIT)
        c.setStrokeColor(HexColor('#dde3ea'))
        c.setLineWidth(0.5)
        c.roundRect(card_x, card_y,
                    draw_w + kaart_pad * 2, draw_h + kaart_pad * 2,
                    1.5 * mm, fill=True, stroke=True)

        teken_logo(c, org_kind, org_data,
                   card_x + kaart_pad, card_y + kaart_pad,
                   draw_w, draw_h)

    # Baan/vereniging-logo in header (linksboven, gastheer-vereniging).
    # Spiegel van het org-logo rechts — zelfde grootte zodat beide visueel
    # gelijkwaardig staan. Wit kaartje als achtergrond; logo binnen het vak
    # op natuurlijke aspect-ratio (preserveAspectRatio in teken_logo).
    baan_kind, baan_data, baan_w, baan_h = laad_logo(args.baan_logo) if args.baan_logo else (None, None, 0, 0)
    if baan_kind is not None:
        logo_max_h = 32 * mm
        logo_max_w = 40 * mm
        ratio = baan_w / baan_h if baan_h else 1.0
        if ratio >= 1:
            draw_w = min(logo_max_w, logo_max_h * ratio)
            draw_h = draw_w / ratio
        else:
            draw_h = logo_max_h
            draw_w = draw_h * ratio
        marge = 8 * mm

        kaart_pad = 2 * mm
        card_x = marge
        card_y = height - draw_h - marge - kaart_pad
        c.setFillColor(WIT)
        c.setStrokeColor(HexColor('#dde3ea'))
        c.setLineWidth(0.5)
        c.roundRect(card_x, card_y,
                    draw_w + kaart_pad * 2, draw_h + kaart_pad * 2,
                    1.5 * mm, fill=True, stroke=True)

        teken_logo(c, baan_kind, baan_data,
                   card_x + kaart_pad, card_y + kaart_pad,
                   draw_w, draw_h)

    # ── QR Code ───────────────────────────────────────────────────────────
    qr_img = bouw_qr(args.qr_url)
    buf = io.BytesIO()
    qr_img.save(buf, format='PNG')
    buf.seek(0)

    qr_print_size = 80 * mm
    qr_top = height - (98 if heeft_comp else 88) * mm
    qr_x = (width - qr_print_size) / 2
    qr_y = qr_top - qr_print_size

    c.setFillColor(WIT)
    c.setStrokeColor(HexColor('#dde3ea'))
    c.setLineWidth(1)
    c.roundRect(qr_x - 5 * mm, qr_y - 5 * mm,
                qr_print_size + 10 * mm, qr_print_size + 10 * mm,
                5 * mm, fill=True, stroke=True)
    c.drawImage(ImageReader(buf), qr_x, qr_y, qr_print_size, qr_print_size)

    # InlineComp-logo in het midden (favicon-stijl, als vector op de canvas)
    ic_size = qr_print_size * 0.22
    teken_ic_logo(c,
                  qr_x + qr_print_size / 2,
                  qr_y + qr_print_size / 2,
                  ic_size)

    # ── 3 stappen ─────────────────────────────────────────────────────────
    step_top = qr_y - 12 * mm
    stap1 = T('stap1_kies_naam', naam=args.comp_naam) if heeft_comp else T('stap1_kies_alg')
    if is_coach:
        steps = [
            ('1', stap1),
            ('2', T('stap_coach_2')),
            ('3', T('stap_coach_3')),
        ]
    else:
        steps = [
            ('1', stap1),
            ('2', T('stap_public_2')),
            ('3', T('stap_public_3')),
        ]
    step_gap = 13 * mm     # tune: compacter = 11, ruimer = 15
    for i, (nr, tekst) in enumerate(steps):
        y = step_top - i * step_gap
        cx = 28 * mm
        c.setFillColor(ORANJE)
        c.circle(cx, y + 2 * mm, 5.5 * mm, fill=True, stroke=False)
        c.setFillColor(WIT)
        c.setFont('Helvetica-Bold', 14)
        c.drawCentredString(cx, y - 0.5 * mm, nr)
        c.setFillColor(BLAUW)
        c.setFont('Helvetica', 14)
        c.drawString(40 * mm, y, tekst)

    # ── Layout van onder naar boven — vaste posities zodat niets overlapt:
    #   0-24 mm    blauwe footer (compacter dan voorheen: 32mm)
    #   24-27 mm   oranje streep
    #   30-45 mm   disclaimer-warning-balk (testfase: opvallend)
    #   46-50 mm   Sportity-verwijzing (klein, grijs)
    #   55-80 mm   sponsors (titel + logo's, alleen als er sponsors zijn)
    #   (boven)    stappen (zelf-plaatsend vanaf qr_y - 12 mm)

    # ── Coach-wachtwoord-balk (alleen coach-poster, alleen als ingesteld) ─
    # Plaats: net boven de disclaimer-balk. Compacte regel met label + grote
    # mono-tekst voor het wachtwoord zodat coaches het direct kunnen typen.
    if is_coach and args.coach_password:
        cw_y      = 47 * mm
        cw_h      = 9 * mm
        cw_marge  = 12 * mm
        c.setFillColor(HexColor('#e8eaf6'))   # lichtblauwe achtergrond
        c.setStrokeColor(BLAUW)
        c.setLineWidth(1.0)
        c.roundRect(cw_marge, cw_y,
                    width - 2 * cw_marge, cw_h,
                    1.5 * mm, fill=True, stroke=True)
        c.setFillColor(BLAUW)
        c.setFont('Helvetica-Bold', 10)
        c.drawString(cw_marge + 4 * mm, cw_y + 3 * mm, T('coach_pw_label'))
        c.setFont('Courier-Bold', 13)
        c.setFillColor(DONKERORANJE)
        # Wachtwoord rechts uitgelijnd zodat lange wachtwoorden netjes blijven
        c.drawRightString(width - cw_marge - 4 * mm, cw_y + 3 * mm,
                          args.coach_password)

    # ── Disclaimer-warning-balk + Sportity (boven footer) ─────────────────
    # Sportity-kanaal komt uit de organisatie-instelling. Leeg = algemene
    # formulering zonder kanaal-naam.
    #
    # Disclaimer is in TESTFASE het belangrijkste: aan de info in InlineComp
    # kunnen nog geen rechten worden ontleend \u2014 daarom in een opvallende
    # warning-balk (lichtoranje vlak + oranje rand + bold tekst). Sportity-
    # regel daaronder klein/grijs, want daar staan alleen de offici\u00eble
    # einduitslagen + klassementen na de wedstrijd.
    if args.sportity_kanaal:
        sportity_tekst = T('sportity_kanaal', kanaal=args.sportity_kanaal)
    else:
        sportity_tekst = T('sportity_geen')

    # Warning-balk: lichtoranje vulling + oranje rand
    balk_y     = 30 * mm
    balk_h     = 15 * mm
    balk_marge = 12 * mm
    c.setFillColor(LICHTORANJE)
    c.setStrokeColor(ORANJE)
    c.setLineWidth(1.5)
    c.roundRect(balk_marge, balk_y,
                width - 2 * balk_marge, balk_h,
                2 * mm, fill=True, stroke=True)

    # Twee regels in de balk: opvallend label + de feitelijke disclaimer
    c.setFillColor(DONKERORANJE)
    c.setFont('Helvetica-Bold', 14)
    c.drawCentredString(width / 2, balk_y + balk_h - 6 * mm, T('disclaimer_label'))
    c.setFont('Helvetica-Bold', 11)
    c.drawCentredString(width / 2, balk_y + 3.5 * mm, T('disclaimer_tekst'))

    # Sportity-regel onder de warning-balk (klein, grijs, secundair)
    c.setFillColor(GRIJS)
    c.setFont('Helvetica', 9)
    c.drawCentredString(width / 2, balk_y + balk_h + 4 * mm, sportity_tekst)

    # ── Sponsors-strook (alleen sponsors mét een geldig logo) ─────────────
    # Ondersteunt zowel raster als SVG; SVG wordt als vector getekend via
    # reportlab.renderPDF (geen cairo/renderPM nodig op shared hosting).
    sponsor_renderables = []   # [(naam, kind, data, breedte_mm)]
    logo_h = 14 * mm
    gap    = 8 * mm
    totaal_breedte = 0
    for naam, pad in sponsors:
        kind, data, lw, lh = laad_logo(pad)
        if kind is None or not lh:
            continue
        ratio = lw / lh
        w = min(logo_h * ratio, 45 * mm)
        sponsor_renderables.append((naam, kind, data, w))
        totaal_breedte += w

    if sponsor_renderables:
        totaal_breedte += gap * max(0, len(sponsor_renderables) - 1)
        max_breedte = width - 30 * mm
        schaal = min(1.0, max_breedte / totaal_breedte) if totaal_breedte else 1.0

        sponsor_logo_y  = 58 * mm
        sponsor_title_y = sponsor_logo_y + logo_h * schaal + 3 * mm

        c.setFillColor(BLAUW)
        c.setFont('Helvetica-Bold', 10)
        c.drawCentredString(width / 2, sponsor_title_y, T('sponsors_titel'))

        cur_x = (width - totaal_breedte * schaal) / 2
        for naam, kind, data, w in sponsor_renderables:
            w_final = w * schaal
            h_final = logo_h * schaal
            teken_logo(c, kind, data, cur_x, sponsor_logo_y, w_final, h_final)
            cur_x += w_final + gap * schaal

    # ── Blauwe footer ─────────────────────────────────────────────────────
    # Compacter dan voorheen (was 32mm) — extra ruimte naar boven voor de
    # disclaimer-warning-balk.
    footer_h = 24 * mm
    c.setFillColor(BLAUW)
    c.rect(0, 0, width, footer_h, fill=True, stroke=False)
    c.setFillColor(ORANJE)
    c.rect(0, footer_h, width, 3 * mm, fill=True, stroke=False)

    # URL in footer: zonder query-string (de QR-code bevat de parameters al)
    c.setFillColor(WIT)
    c.setFont('Helvetica-Bold', 12)
    url_label = args.qr_url.replace('https://', '').replace('http://', '')
    # Strip everything from '?' onwards — zoveel mogelijk kort-op-de-bal
    if '?' in url_label:
        url_label = url_label.split('?', 1)[0]
    url_label = url_label.rstrip('/')
    c.drawCentredString(width / 2, 15 * mm, url_label)

    c.setFillColor(LICHTBLAUW)
    c.setFont('Helvetica', 8)
    tip = T('tip_coach') if is_coach else T('tip_public')
    c.drawCentredString(width / 2, 6 * mm, tip)

    c.save()


def main():
    p = argparse.ArgumentParser(description='InlineComp promotie-poster generator')
    p.add_argument('--output',       default='poster-inlinecomp.pdf')
    p.add_argument('--qr-url',       default='https://inlineresults.devriesen.com/public/')
    p.add_argument('--org-naam',     default='')
    p.add_argument('--org-logo',     default='')
    p.add_argument('--baan-logo',    default='',
                   help='Pad naar het baan/vereniging-logo (links in de header). '
                        'Leeg = geen logo links.')
    p.add_argument('--comp-naam',    default='')
    p.add_argument('--comp-datum',   default='')
    p.add_argument('--comp-locatie', default='')
    p.add_argument('--sponsors',     default='',
                   help='Formaat: "Naam1:/pad1|Naam2:/pad2"')
    p.add_argument('--sportity-kanaal', default='',
                   help='Naam van het Sportity-kanaal (bv. ISKREGIO); '
                        'leeg = algemene verwijzing naar Sportity')
    p.add_argument('--lang', default='nl', choices=['nl', 'en'],
                   help="Taal van de poster-teksten. nl=Nederlands (default), "
                        "en=English (voor internationale wedstrijden).")
    p.add_argument('--coach-password', default='',
                   help="Coach-app toegangswachtwoord. Alleen relevant voor "
                        "coach-poster. Verschijnt als prominente regel zodat "
                        "coaches het bij hand hebben bij eerste login.")
    p.add_argument('--app-type', default='public', choices=['public', 'coach'],
                   help="Doelgroep van de poster: 'public' (rijders/ouders, default) "
                        "of 'coach' (coaches die meerdere rijders tegelijk volgen)")
    args = p.parse_args()

    genereer_poster(args)
    print(f'OK: {args.output}')


if __name__ == '__main__':
    main()
