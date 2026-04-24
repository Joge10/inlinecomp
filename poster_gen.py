import qrcode
import io
from PIL import Image, ImageDraw, ImageFont
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.colors import HexColor, white
from reportlab.pdfgen import canvas
from reportlab.lib.utils import ImageReader

BLAUW = HexColor('#1F4E79')
ORANJE = HexColor('#E8630A')
LICHTBLAUW = HexColor('#D6E4F0')
WIT = white

width, height = A4  # 210 x 297 mm
output_path = 'poster-inlinecomp.pdf'
c = canvas.Canvas(output_path, pagesize=A4)

# ── Layout van boven naar beneden (in mm vanaf bovenkant) ──
# 0-95mm     : blauwe header (titel, subtitle, instructie, pijl)
# 95-101mm   : oranje streep
# 101-185mm  : QR code (70mm) met padding
# 188-230mm  : 3 stappen
# 235-252mm  : disclaimer
# 260-297mm  : blauwe footer (URL + tip)

# ── Blauwe header ──
header_h = 80*mm
c.setFillColor(BLAUW)
c.rect(0, height - header_h, width, header_h, fill=True, stroke=False)

# Oranje streep
c.setFillColor(ORANJE)
c.rect(0, height - header_h - 4*mm, width, 4*mm, fill=True, stroke=False)

# Titel
c.setFillColor(WIT)
c.setFont('Helvetica-Bold', 48)
c.drawCentredString(width/2, height - 30*mm, 'InlineComp')

# Subtitle
c.setFont('Helvetica', 20)
c.setFillColor(LICHTBLAUW)
c.drawCentredString(width/2, height - 44*mm, 'Volg je wedstrijd live!')

# Oranje lijn onder subtitle
c.setStrokeColor(ORANJE)
c.setLineWidth(2.5)
c.line(width/2 - 60*mm, height - 49*mm, width/2 + 60*mm, height - 49*mm)

# Instructie
c.setFillColor(WIT)
c.setFont('Helvetica', 15)
c.drawCentredString(width/2, height - 61*mm, 'Scan de QR-code met je telefoon')

# Pijl
c.setFont('Helvetica-Bold', 24)
c.setFillColor(ORANJE)
c.drawCentredString(width/2, height - 70*mm, chr(9660))

# ── QR Code met IC logo ──
qr = qrcode.QRCode(version=1, error_correction=qrcode.constants.ERROR_CORRECT_H, box_size=10, border=2)
qr.add_data('https://inlineresults.devriesen.com/public/')
qr.make(fit=True)
qr_img = qr.make_image(fill_color='#1F4E79', back_color='white').convert('RGBA')

# IC logo maken
qr_w, qr_h = qr_img.size
logo_size = qr_w // 5
logo = Image.new('RGBA', (logo_size, logo_size), (255, 255, 255, 0))
draw = ImageDraw.Draw(logo)
r = logo_size // 6
draw.rounded_rectangle([0, 0, logo_size-1, logo_size-1], radius=r, fill=(31, 78, 121, 255))
try:
    font = ImageFont.truetype("arial.ttf", logo_size // 2)
except:
    font = ImageFont.load_default()
bbox = draw.textbbox((0, 0), "IC", font=font)
tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
draw.text(((logo_size - tw) // 2, (logo_size - th) // 2 - logo_size // 10), "IC", fill=(255, 255, 255, 255), font=font)
bar_h = logo_size // 8
bar_y = logo_size - bar_h - logo_size // 8
bar_x = logo_size // 5
draw.rounded_rectangle([bar_x, bar_y, logo_size - bar_x, bar_y + bar_h], radius=bar_h // 2, fill=(232, 99, 10, 255))

pad = logo_size // 6
padded = Image.new('RGBA', (logo_size + pad*2, logo_size + pad*2), (255, 255, 255, 255))
padded.paste(logo, (pad, pad), logo)
lx = (qr_w - padded.width) // 2
ly = (qr_h - padded.height) // 2
qr_img.paste(padded, (lx, ly), padded)

qr_final = qr_img.convert('RGB')
img_buffer = io.BytesIO()
qr_final.save(img_buffer, format='PNG')
img_buffer.seek(0)
qr_reader = ImageReader(img_buffer)

# QR plaatsen: 80mm groot, start op 88mm vanaf boven
qr_print_size = 80*mm
qr_top = height - 88*mm
qr_x = (width - qr_print_size) / 2
qr_y = qr_top - qr_print_size

# Witte achtergrond
c.setFillColor(WIT)
c.setStrokeColor(HexColor('#dde3ea'))
c.setLineWidth(1)
c.roundRect(qr_x - 5*mm, qr_y - 5*mm, qr_print_size + 10*mm, qr_print_size + 10*mm, 5*mm, fill=True, stroke=True)
c.drawImage(qr_reader, qr_x, qr_y, qr_print_size, qr_print_size)

# ── Stappen: start op 182mm vanaf boven ──
step_top = height - 182*mm
steps = [
    ('1', 'Kies je wedstrijd'),
    ('2', 'Vul je startnummer in'),
    ('3', 'Bekijk je heats, tijden en resultaten'),
]

for i, (nr, tekst) in enumerate(steps):
    y = step_top - i * 14*mm
    cx = 28*mm
    c.setFillColor(ORANJE)
    c.circle(cx, y + 3*mm, 6*mm, fill=True, stroke=False)
    c.setFillColor(WIT)
    c.setFont('Helvetica-Bold', 14)
    c.drawCentredString(cx, y + 0.5*mm, nr)
    c.setFillColor(BLAUW)
    c.setFont('Helvetica', 15)
    c.drawString(40*mm, y, tekst)

# ── Disclaimer: start op 230mm vanaf boven ──
disc_top = height - 230*mm
c.setFillColor(HexColor('#999999'))
c.setFont('Helvetica', 10.5)
c.drawCentredString(width/2, disc_top,
    'We testen InlineComp voor het eerst tijdens deze wedstrijd — feedback is welkom!')
c.drawCentredString(width/2, disc_top - 10,
    'De officiële startlijsten / uitslagen / klassementen en mededelingen vind je zoals altijd op Sportity (kanaal: ISKREGIO).')
c.drawCentredString(width/2, disc_top - 20,
    'Aan de informatie in InlineComp kunnen geen rechten worden ontleend.')

# ── Blauwe footer: onderste 32mm ──
footer_h = 32*mm
c.setFillColor(BLAUW)
c.rect(0, 0, width, footer_h, fill=True, stroke=False)
c.setFillColor(ORANJE)
c.rect(0, footer_h, width, 3*mm, fill=True, stroke=False)

c.setFillColor(WIT)
c.setFont('Helvetica-Bold', 12)
c.drawCentredString(width/2, 20*mm, 'inlineresults.devriesen.com/public')

c.setFillColor(LICHTBLAUW)
c.setFont('Helvetica', 8)
c.drawCentredString(width/2, 8*mm, 'Tip: voeg InlineComp toe aan je startscherm voor snelle toegang!')

c.save()
print(f'Poster opgeslagen: {output_path}')
