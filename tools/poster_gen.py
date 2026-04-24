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


def bouw_qr(qr_url, kleur_hex='#1F4E79'):
    """QR-code met ingebed 'IC'-logo in het midden."""
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_H,
        box_size=10,
        border=2,
    )
    qr.add_data(qr_url)
    qr.make(fit=True)
    img = qr.make_image(fill_color=kleur_hex, back_color='white').convert('RGBA')

    qr_w, qr_h = img.size
    logo_size = qr_w // 5
    logo = Image.new('RGBA', (logo_size, logo_size), (255, 255, 255, 0))
    draw = ImageDraw.Draw(logo)
    r = logo_size // 6
    draw.rounded_rectangle([0, 0, logo_size - 1, logo_size - 1],
                           radius=r, fill=(31, 78, 121, 255))
    try:
        font = ImageFont.truetype("arial.ttf", logo_size // 2)
    except Exception:
        font = ImageFont.load_default()
    bbox = draw.textbbox((0, 0), "IC", font=font)
    tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
    draw.text(((logo_size - tw) // 2, (logo_size - th) // 2 - logo_size // 10),
              "IC", fill=(255, 255, 255, 255), font=font)
    bar_h = logo_size // 8
    bar_y = logo_size - bar_h - logo_size // 8
    bar_x = logo_size // 5
    draw.rounded_rectangle([bar_x, bar_y, logo_size - bar_x, bar_y + bar_h],
                           radius=bar_h // 2, fill=(232, 99, 10, 255))

    pad = logo_size // 6
    padded = Image.new('RGBA',
                       (logo_size + pad * 2, logo_size + pad * 2),
                       (255, 255, 255, 255))
    padded.paste(logo, (pad, pad), logo)
    lx = (qr_w - padded.width) // 2
    ly = (qr_h - padded.height) // 2
    img.paste(padded, (lx, ly), padded)

    return img.convert('RGB')


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

    # Organisatie-logo in header (rechtsboven, circulair)
    if args.org_logo and os.path.isfile(args.org_logo):
        try:
            lo = Image.open(args.org_logo).convert('RGBA')
            sz = min(lo.size)
            lo = lo.crop((
                (lo.width - sz) // 2,
                (lo.height - sz) // 2,
                (lo.width + sz) // 2,
                (lo.height + sz) // 2,
            ))
            lo.thumbnail((400, 400))
            mask = Image.new('L', lo.size, 0)
            ImageDraw.Draw(mask).ellipse((0, 0, lo.size[0], lo.size[1]), fill=255)
            bg = Image.new('RGBA', lo.size, (255, 255, 255, 255))
            bg.paste(lo, (0, 0), mask)
            buf = io.BytesIO()
            bg.save(buf, format='PNG')
            buf.seek(0)
            c.drawImage(ImageReader(buf),
                        width - 33 * mm, height - 33 * mm,
                        22 * mm, 22 * mm, mask='auto')
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

    # ── 3 stappen ─────────────────────────────────────────────────────────
    step_top = qr_y - 12 * mm
    stap1 = f'Kies "{args.comp_naam}"' if heeft_comp else 'Kies je wedstrijd'
    steps = [
        ('1', stap1),
        ('2', 'Vul je startnummer in'),
        ('3', 'Bekijk je heats, tijden en resultaten'),
    ]
    for i, (nr, tekst) in enumerate(steps):
        y = step_top - i * 11 * mm
        cx = 28 * mm
        c.setFillColor(ORANJE)
        c.circle(cx, y + 2 * mm, 5.5 * mm, fill=True, stroke=False)
        c.setFillColor(WIT)
        c.setFont('Helvetica-Bold', 14)
        c.drawCentredString(cx, y - 0.5 * mm, nr)
        c.setFillColor(BLAUW)
        c.setFont('Helvetica', 14)
        c.drawString(40 * mm, y, tekst)

    # ── Sponsors-strook (optioneel) ───────────────────────────────────────
    sponsor_top = step_top - len(steps) * 11 * mm - 6 * mm
    if sponsors:
        c.setFillColor(BLAUW)
        c.setFont('Helvetica-Bold', 10)
        c.drawCentredString(width / 2, sponsor_top, 'Mede mogelijk gemaakt door:')
        sponsor_top -= 4 * mm

        logo_h = 14 * mm
        gap    = 6 * mm
        logo_imgs = []
        totaal_breedte = 0
        for naam, pad in sponsors:
            w = 30 * mm
            if pad:
                try:
                    img = Image.open(pad).convert('RGBA')
                    ratio = img.width / img.height
                    w = min(logo_h * ratio, 40 * mm)
                except Exception:
                    pad = ''
            logo_imgs.append((naam, pad, w))
            totaal_breedte += w
        totaal_breedte += gap * max(0, len(logo_imgs) - 1)

        max_breedte = width - 30 * mm
        schaal = min(1.0, max_breedte / totaal_breedte) if totaal_breedte else 1.0
        cur_x = (width - totaal_breedte * schaal) / 2
        sponsor_y = sponsor_top - logo_h * schaal - 2 * mm
        for naam, pad, w in logo_imgs:
            w_final = w * schaal
            h_final = logo_h * schaal
            if pad:
                try:
                    c.drawImage(pad, cur_x, sponsor_y, w_final, h_final,
                                preserveAspectRatio=True, mask='auto')
                except Exception:
                    c.setFillColor(BLAUW)
                    c.setFont('Helvetica', 9)
                    c.drawString(cur_x, sponsor_y + h_final / 2, naam)
            else:
                c.setFillColor(BLAUW)
                c.setFont('Helvetica', 9)
                c.drawString(cur_x, sponsor_y + h_final / 2, naam)
            cur_x += w_final + gap * schaal

    # ── Disclaimer ────────────────────────────────────────────────────────
    disc_top = 60 * mm
    c.setFillColor(GRIJS)
    c.setFont('Helvetica', 9.5)
    c.drawCentredString(width / 2, disc_top,
        'Aan de informatie in InlineComp kunnen geen rechten worden ontleend.')
    c.drawCentredString(width / 2, disc_top - 4 * mm,
        'Offici\u00eble startlijsten, uitslagen en mededelingen via Sportity (kanaal: ISKREGIO).')

    # ── Blauwe footer ─────────────────────────────────────────────────────
    footer_h = 32 * mm
    c.setFillColor(BLAUW)
    c.rect(0, 0, width, footer_h, fill=True, stroke=False)
    c.setFillColor(ORANJE)
    c.rect(0, footer_h, width, 3 * mm, fill=True, stroke=False)

    c.setFillColor(WIT)
    c.setFont('Helvetica-Bold', 12)
    url_label = args.qr_url.replace('https://', '').replace('http://', '')
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
