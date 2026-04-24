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
    # Recreate de favicon.svg-layout exact — zelfde verhoudingen (32x32
    # SVG: rect rx=6, tekst op y=22 size 15, balk 20x3 op y=25 rx=1.5),
    # maar in PIL zodat het op grote QR's goed rastert.
    logo_size = qr_w // 4

    def make_favicon(size):
        s = size
        im = Image.new('RGBA', (s, s), (0, 0, 0, 0))
        d  = ImageDraw.Draw(im)
        # Blauw afgerond vierkant (SVG: rx=6 op 32px → 18.75%)
        rad = int(s * 0.1875)
        d.rounded_rectangle([0, 0, s - 1, s - 1], radius=rad,
                            fill=(31, 78, 121, 255))
        # 'IC' tekst (SVG: font 15 op 32 → 47%, y=22 → 69% baseline).
        # Met anchor='mm' willen we 'middle' op ~55% hoogte (tussen center en baseline).
        fs = int(s * 0.58)
        f = None
        for name in ('arialbd.ttf', 'Arial Bold.ttf', 'arial.ttf',
                     'DejaVuSans-Bold.ttf', 'LiberationSans-Bold.ttf'):
            try:
                f = ImageFont.truetype(name, fs)
                break
            except Exception:
                continue
        if f is None:
            f = ImageFont.load_default()
        try:
            d.text((s / 2, s * 0.46), "IC",
                   fill=(255, 255, 255, 255), font=f, anchor='mm')
        except TypeError:
            bbox = d.textbbox((0, 0), "IC", font=f)
            tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
            d.text(((s - tw) // 2, int(s * 0.46 - th / 2)), "IC",
                   fill=(255, 255, 255, 255), font=f)
        # Oranje streep (SVG: 20×3 op 32 → 62% breed × 9% hoog, start y=25=78%)
        bar_w = int(s * 0.62)
        bar_h = int(s * 0.09)
        bar_x = (s - bar_w) // 2
        bar_y = int(s * 0.78)
        d.rounded_rectangle([bar_x, bar_y, bar_x + bar_w, bar_y + bar_h],
                            radius=bar_h // 2, fill=(232, 99, 10, 255))
        return im

    logo = make_favicon(logo_size)

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

    # Organisatie-logo in header (rechtsboven)
    # Strakke witte kaart rondom het logo met minimale padding (5% rondom)
    # zodat het logo prominent in z'n vlak staat, niet "verdwaald" in wit.
    # Container is rechthoekig met behoud van aspect-ratio.
    if args.org_logo and os.path.isfile(args.org_logo):
        try:
            lo = Image.open(args.org_logo).convert('RGBA')

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

    # ── Sponsors-strook (alleen sponsors mét een logo) ────────────────────
    # Sponsors zonder beschikbaar logo worden overgeslagen: een zwevend
    # stukje tekst naast echte logo's ziet er rommelig uit.
    sponsors_met_logo = [(naam, pad) for naam, pad in sponsors if pad]
    if sponsors_met_logo:
        logo_h = 14 * mm
        gap    = 8 * mm
        logo_imgs = []
        totaal_breedte = 0
        for naam, pad in sponsors_met_logo:
            try:
                img = Image.open(pad).convert('RGBA')
                ratio = img.width / img.height
                w = min(logo_h * ratio, 45 * mm)
            except Exception:
                continue
            logo_imgs.append((naam, pad, w))
            totaal_breedte += w
        totaal_breedte += gap * max(0, len(logo_imgs) - 1)

        if logo_imgs:
            max_breedte = width - 30 * mm
            schaal = min(1.0, max_breedte / totaal_breedte) if totaal_breedte else 1.0

            sponsor_logo_y  = 58 * mm
            sponsor_title_y = sponsor_logo_y + logo_h * schaal + 3 * mm

            c.setFillColor(BLAUW)
            c.setFont('Helvetica-Bold', 10)
            c.drawCentredString(width / 2, sponsor_title_y, 'Mede mogelijk gemaakt door:')

            cur_x = (width - totaal_breedte * schaal) / 2
            for naam, pad, w in logo_imgs:
                w_final = w * schaal
                h_final = logo_h * schaal
                try:
                    c.drawImage(pad, cur_x, sponsor_logo_y, w_final, h_final,
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
