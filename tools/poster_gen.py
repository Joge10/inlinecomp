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
try:
    from svglib.svglib import svg2rlg
    from reportlab.graphics import renderPM
    HEEFT_SVG = True
except ImportError:
    HEEFT_SVG = False


BLAUW       = HexColor('#1F4E79')
ORANJE      = HexColor('#E8630A')
LICHTBLAUW  = HexColor('#D6E4F0')
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


def laad_logo_als_pil(pad):
    """Laadt een logo-bestand in als PIL-image.

    Ondersteunt PNG / JPG / WEBP direct via PIL. Voor SVG gebruiken we svglib
    (als beschikbaar) om het als rasterbeeld te renderen. Retourneert None bij
    falen, zodat de aanroeper de sponsor kan skippen.
    """
    if not pad or not os.path.isfile(pad):
        return None
    is_svg = pad.lower().endswith('.svg')

    if is_svg:
        if not HEEFT_SVG:
            print(f"WAARSCHUWING: SVG gevonden ({pad}) maar svglib niet geinstalleerd. "
                  "Installeer met: pip install --user svglib", file=sys.stderr)
            return None
        try:
            drawing = svg2rlg(pad)
            if drawing is None:
                return None
            # Render naar hoge resolutie PNG-bytes, dan terug naar PIL
            png_bytes = renderPM.drawToString(drawing, fmt='PNG', dpi=300)
            return Image.open(io.BytesIO(png_bytes)).convert('RGBA')
        except Exception as e:
            print(f"WAARSCHUWING: SVG-render mislukt ({pad}): {e}", file=sys.stderr)
            return None

    try:
        return Image.open(pad).convert('RGBA')
    except Exception as e:
        print(f"WAARSCHUWING: Logo laden mislukt ({pad}): {e}", file=sys.stderr)
        return None


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

    # ── Blauwe header ─────────────────────────────────────────────────────
    header_h = 82 * mm if heeft_comp else 80 * mm
    c.setFillColor(BLAUW)
    c.rect(0, height - header_h, width, header_h, fill=True, stroke=False)

    c.setFillColor(ORANJE)
    c.rect(0, height - header_h - 4 * mm, width, 4 * mm, fill=True, stroke=False)

    c.setFillColor(WIT)
    c.setFont('Helvetica-Bold', 48)
    c.drawCentredString(width / 2, height - 30 * mm, 'InlineComp')

    sub = f'Wedstrijden bij {args.org_naam}' if heeft_org else 'Volg je wedstrijd live!'
    c.setFont('Helvetica', 18)
    c.setFillColor(LICHTBLAUW)
    c.drawCentredString(width / 2, height - 44 * mm, sub)

    c.setStrokeColor(ORANJE)
    c.setLineWidth(2.5)
    c.line(width / 2 - 60 * mm, height - 49 * mm,
           width / 2 + 60 * mm, height - 49 * mm)

    if heeft_comp:
        stukken = [args.comp_naam]
        if args.comp_datum:   stukken.append(args.comp_datum)
        if args.comp_locatie: stukken.append(args.comp_locatie)
        tekst = ' \u2014 '.join(stukken)
        c.setFillColor(WIT)
        c.setFont('Helvetica-Bold', 14)
        c.drawCentredString(width / 2, height - 58 * mm, tekst)

    c.setFillColor(WIT)
    c.setFont('Helvetica', 15)
    instr_y = height - (70 if heeft_comp else 61) * mm
    c.drawCentredString(width / 2, instr_y, 'Scan de QR-code met je telefoon')

    c.setFont('Helvetica-Bold', 24)
    c.setFillColor(ORANJE)
    c.drawCentredString(width / 2, instr_y - 9 * mm, chr(9660))

    # Organisatie-logo in header (rechtsboven)
    # Strakke witte kaart rondom het logo met minimale padding (5% rondom)
    # zodat het logo prominent in z'n vlak staat, niet "verdwaald" in wit.
    # Container is rechthoekig met behoud van aspect-ratio.
    lo_org = laad_logo_als_pil(args.org_logo) if args.org_logo else None
    if lo_org is not None:
        try:
            lo = lo_org

            # Schaal naar redelijke werk-resolutie
            lo.thumbnail((800, 800), Image.LANCZOS)

            # Strakke padding: 6% van de langste zijde
            pad = int(max(lo.width, lo.height) * 0.06)
            bg_w = lo.width  + pad * 2
            bg_h = lo.height + pad * 2
            bg = Image.new('RGBA', (bg_w, bg_h), (255, 255, 255, 255))
            if lo.mode == 'RGBA':
                bg.paste(lo, (pad, pad), lo)
            else:
                bg.paste(lo, (pad, pad))
            buf = io.BytesIO()
            bg.save(buf, format='PNG')
            buf.seek(0)

            # Plaatsen: 32mm hoog max, breedte volgt aspect-ratio
            logo_max_h = 32 * mm
            logo_max_w = 40 * mm
            ratio = bg.width / bg.height
            if ratio >= 1:
                draw_w = min(logo_max_w, logo_max_h * ratio)
                draw_h = draw_w / ratio
            else:
                draw_h = logo_max_h
                draw_w = draw_h * ratio

            marge = 8 * mm
            c.drawImage(ImageReader(buf),
                        width - draw_w - marge,
                        height - draw_h - marge,
                        draw_w, draw_h,
                        preserveAspectRatio=True, mask='auto')
        except Exception as e:
            print(f"WAARSCHUWING: org-logo niet getekend: {e}", file=sys.stderr)

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
    stap1 = f'Kies "{args.comp_naam}"' if heeft_comp else 'Kies je wedstrijd'
    steps = [
        ('1', stap1),
        ('2', 'Vul je startnummer in'),
        ('3', 'Bekijk je heats, tijden en resultaten'),
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
    #   0-32 mm    blauwe footer
    #   32-35 mm   oranje streep
    #   40-48 mm   disclaimer (2 regels)
    #   55-80 mm   sponsors (titel + logo's, alleen als er sponsors zijn)
    #   (boven)    stappen (zelf-plaatsend vanaf qr_y - 12 mm)

    # ── Disclaimer (boven footer) ─────────────────────────────────────────
    disc_top = 48 * mm
    c.setFillColor(GRIJS)
    c.setFont('Helvetica', 9.5)
    c.drawCentredString(width / 2, disc_top,
        'Aan de informatie in InlineComp kunnen geen rechten worden ontleend.')
    c.drawCentredString(width / 2, disc_top - 4 * mm,
        'Offici\u00eble startlijsten, uitslagen en mededelingen via Sportity (kanaal: ISKREGIO).')

    # ── Sponsors-strook (alleen sponsors mét een geldig logo) ─────────────
    # Laadt elk sponsor-logo in PIL (ook SVG via svglib), beweist daarmee dat
    # het renderbaar is, en rendert vervolgens via reportlab. Sponsors waarvoor
    # geen of een onleesbaar logo geconfigureerd is worden genegeerd.
    sponsor_renderables = []   # [(naam, pil_image, breedte_mm)]
    logo_h = 14 * mm
    gap    = 8 * mm
    totaal_breedte = 0
    for naam, pad in sponsors:
        pil = laad_logo_als_pil(pad)
        if pil is None:
            continue
        ratio = pil.width / pil.height
        w = min(logo_h * ratio, 45 * mm)
        sponsor_renderables.append((naam, pil, w))
        totaal_breedte += w

    if sponsor_renderables:
        totaal_breedte += gap * max(0, len(sponsor_renderables) - 1)
        max_breedte = width - 30 * mm
        schaal = min(1.0, max_breedte / totaal_breedte) if totaal_breedte else 1.0

        sponsor_logo_y  = 58 * mm
        sponsor_title_y = sponsor_logo_y + logo_h * schaal + 3 * mm

        c.setFillColor(BLAUW)
        c.setFont('Helvetica-Bold', 10)
        c.drawCentredString(width / 2, sponsor_title_y, 'Mede mogelijk gemaakt door:')

        cur_x = (width - totaal_breedte * schaal) / 2
        for naam, pil, w in sponsor_renderables:
            w_final = w * schaal
            h_final = logo_h * schaal
            buf = io.BytesIO()
            pil.save(buf, format='PNG')
            buf.seek(0)
            try:
                c.drawImage(ImageReader(buf), cur_x, sponsor_logo_y,
                            w_final, h_final,
                            preserveAspectRatio=True, mask='auto')
            except Exception as e:
                print(f"WAARSCHUWING: sponsor-logo {naam} niet getekend: {e}",
                      file=sys.stderr)
            cur_x += w_final + gap * schaal

    # ── Blauwe footer ─────────────────────────────────────────────────────
    footer_h = 32 * mm
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
    c.drawCentredString(width / 2, 20 * mm, url_label)

    c.setFillColor(LICHTBLAUW)
    c.setFont('Helvetica', 8)
    c.drawCentredString(width / 2, 8 * mm,
        'Tip: voeg InlineComp toe aan je startscherm voor snelle toegang!')

    c.save()


def main():
    p = argparse.ArgumentParser(description='InlineComp promotie-poster generator')
    p.add_argument('--output',       default='poster-inlinecomp.pdf')
    p.add_argument('--qr-url',       default='https://inlineresults.devriesen.com/public/')
    p.add_argument('--org-naam',     default='')
    p.add_argument('--org-logo',     default='')
    p.add_argument('--comp-naam',    default='')
    p.add_argument('--comp-datum',   default='')
    p.add_argument('--comp-locatie', default='')
    p.add_argument('--sponsors',     default='',
                   help='Formaat: "Naam1:/pad1|Naam2:/pad2"')
    args = p.parse_args()

    genereer_poster(args)
    print(f'OK: {args.output}')


if __name__ == '__main__':
    main()
