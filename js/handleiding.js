/* InlineComp – Handleiding */

// ── Modal openen / sluiten ─────────────────────────────────────────────────────

function openHandleiding() {
    let modal = document.getElementById('modal-handleiding');
    if (!modal) {
        modal = maakHandleidingModal();
        document.body.appendChild(modal);
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function sluitHandleiding() {
    const modal = document.getElementById('modal-handleiding');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

// ── PDF via nieuw venster + print ──────────────────────────────────────────────

function printHandleiding() {
    const inhoud = document.getElementById('handleiding-inhoud');
    if (!inhoud) return;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(`<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>InlineComp Handleiding</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt;
           color: #111; margin: 0; padding: 16mm 18mm; line-height: 1.55; }
    h1 { font-size: 20pt; color: #1a3a5c; margin: 0 0 4pt; border-bottom: 2px solid #e86c1b; padding-bottom: 4pt; }
    h2 { font-size: 14pt; color: #1a3a5c; margin: 18pt 0 4pt; border-left: 4px solid #e86c1b; padding-left: 8pt; }
    h3 { font-size: 11pt; color: #333; margin: 10pt 0 2pt; }
    p  { margin: 0 0 6pt; }
    ul, ol { margin: 2pt 0 6pt 16pt; padding: 0; }
    li { margin-bottom: 2pt; }
    table { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; font-size: 10pt; }
    th { background: #1a3a5c; color: #fff; padding: 4pt 7pt; text-align: left; }
    td { padding: 3pt 7pt; border: 1px solid #ccc; }
    tr:nth-child(even) td { background: #f5f7fa; }
    .hlg-tip  { background: #fff8e1; border-left: 3px solid #f59e0b; padding: 5pt 9pt; margin: 6pt 0; border-radius: 3pt; }
    .hlg-warn { background: #fff0f0; border-left: 3px solid #e53e3e; padding: 5pt 9pt; margin: 6pt 0; border-radius: 3pt; }
    .hlg-sectie { page-break-inside: avoid; margin-bottom: 14pt; }
    .cover { text-align: center; padding: 30mm 0 20mm; page-break-after: always; }
    .cover h1 { font-size: 28pt; border: none; }
    .cover .sub { font-size: 13pt; color: #555; margin-top: 4pt; }
    .cover .versie { font-size: 10pt; color: #999; margin-top: 12pt; }
    .hlg-mock { border: 1px solid #d0d7e2; border-radius: 6pt; overflow: hidden;
                margin: 8pt 0 3pt; background: #f8f9fb;
                font-family: 'Segoe UI', Arial, sans-serif; font-size: 9pt;
                page-break-inside: avoid; }
    .hlg-mock-caption { text-align: center; font-size: 8pt; color: #999;
                        margin: 0 0 10pt; font-style: italic; }
    @page { margin: 16mm 18mm; }
    @media print { body { padding: 0; } }
  </style>
</head>
<body>
${inhoud.innerHTML}
</body>
</html>`);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}

// ── Modal HTML ─────────────────────────────────────────────────────────────────

function maakHandleidingModal() {
    const div = document.createElement('div');
    div.id        = 'modal-handleiding';
    div.className = 'modal-overlay';
    div.style.display = 'none';
    div.innerHTML = `
<div class="modal-box handleiding-box">
  <div class="handleiding-kop">
    <h2>&#128366; InlineComp Handleiding</h2>
    <div class="handleiding-kop-acties">
      <button class="btn-secondary hlg-pdf-btn" id="hlg-btn-pdf">&#128438; Opslaan als PDF</button>
      <button class="btn-del" id="hlg-btn-sluit" title="Sluiten">&#10005;</button>
    </div>
  </div>
  <div class="handleiding-nav" id="hlg-nav"></div>
  <div class="handleiding-inhoud" id="handleiding-inhoud">
    ${hlgContent()}
  </div>
</div>`;

    div.querySelector('#hlg-btn-sluit').addEventListener('click', sluitHandleiding);
    div.querySelector('#hlg-btn-pdf').addEventListener('click', printHandleiding);
    div.addEventListener('click', e => { if (e.target === div) sluitHandleiding(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && document.getElementById('modal-handleiding')?.style.display !== 'none')
            sluitHandleiding();
    });

    // Inhoudsopgave genereren vanuit de h2-headers
    setTimeout(() => {
        const nav   = div.querySelector('#hlg-nav');
        const items = div.querySelectorAll('#handleiding-inhoud h2[id]');
        if (!nav || !items.length) return;
        nav.innerHTML = '<strong>Inhoud:</strong> '
            + Array.from(items).map(h =>
                `<a class="hlg-nav-link" href="#${h.id}">${h.textContent}</a>`
            ).join('');
    }, 0);

    return div;
}

// ── Herbruikbare mock-up bouwblokken ──────────────────────────────────────────

function mockHeader(titel) {
    return `<div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700;display:flex;align-items:center;gap:8px">
        <span>${titel}</span>
        <span style="background:rgba(255,255,255,.18);border-radius:10px;padding:1px 7px;font-size:.68rem">KNSB Inline</span>
      </div>`;
}

function mockInput(label, waarde, highlight) {
    const border = highlight ? 'border:1px solid #e86c1b' : 'border:1px solid #ccc';
    return `<div style="margin-bottom:6px">
        <div style="font-size:.69rem;color:#666;margin-bottom:2px">${label}</div>
        <div style="${border};border-radius:4px;padding:4px 7px;background:#fff;font-size:.77rem">${waarde}</div>
      </div>`;
}

function mockBadge(tekst, kleur) {
    const kleuren = {
        groen:  'background:#d4edda;color:#155724',
        blauw:  'background:#cce5ff;color:#004085',
        grijs:  'background:#e2e3e5;color:#383d41',
        rood:   'background:#f8d7da;color:#721c24',
        oranje: 'background:#ffe5cc;color:#7c3500',
        paars:  'background:#ede0ff;color:#4a148c',
    };
    return `<span style="${kleuren[kleur] ?? kleuren.grijs};border-radius:10px;padding:1px 7px;font-size:.67rem;font-weight:600;white-space:nowrap">${tekst}</span>`;
}

// ── Handleiding inhoud ─────────────────────────────────────────────────────────

function hlgContent() { return `

<div class="cover">
  <h1>InlineComp</h1>
  <div class="sub">Handleiding voor wedstrijdbeheer – KNSB Inline</div>
  <div class="versie">Versie 2025 &nbsp;·&nbsp; Laatste update: ${new Date().toLocaleDateString('nl-NL',{day:'2-digit',month:'long',year:'numeric'})}</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ 1 INLOGGEN -->
<div class="hlg-sectie">
<h2 id="hlg-inloggen">1. Inloggen</h2>
<p>InlineComp is beveiligd met een gebruikerssysteem. U hebt een geldige gebruikersnaam en wachtwoord nodig om toegang te krijgen.</p>

<h3>Eerste gebruik (installatie)</h3>
<p>Bij de allereerste keer dat de applicatie wordt geopend verschijnt een speciaal formulier om een <strong>Owner</strong>-account aan te maken. Dit is het hoofdbeheerder-account en kan daarna via <em>Gebruikers</em> aanvullende gebruikers aanmaken.</p>

<h3>Normaal inloggen</h3>
<ol>
  <li>Navigeer naar de login-pagina.</li>
  <li>Voer uw gebruikersnaam en wachtwoord in.</li>
  <li>Klik op <strong>Inloggen</strong>.</li>
</ol>
<p>Na een succesvolle login wordt u direct doorgestuurd naar het hoofdscherm. Uw naam en rol zijn zichtbaar rechts bovenin de navigatiebalk.</p>

<!-- MOCKUP: inlogscherm -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:6px 12px;font-size:.8rem;font-weight:700;display:flex;align-items:center;gap:8px">
    InlineComp <span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px;font-size:.7rem">KNSB Inline</span>
  </div>
  <div style="padding:20px 24px;max-width:260px;margin:0 auto">
    <div style="text-align:center;font-weight:700;color:#1a3a5c;margin-bottom:14px;font-size:.9rem">Inloggen</div>
    <div style="margin-bottom:8px">
      <div style="font-size:.69rem;color:#555;margin-bottom:3px">Gebruikersnaam</div>
      <div style="border:1px solid #ccc;border-radius:5px;padding:5px 8px;background:#fff;color:#333;font-size:.8rem">gebruiker</div>
    </div>
    <div style="margin-bottom:14px">
      <div style="font-size:.69rem;color:#555;margin-bottom:3px">Wachtwoord</div>
      <div style="border:1px solid #ccc;border-radius:5px;padding:5px 8px;background:#fff;color:#333;font-size:.8rem">••••••••</div>
    </div>
    <div style="background:#e86c1b;color:#fff;text-align:center;border-radius:5px;padding:7px;font-size:.82rem;font-weight:600">Inloggen</div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Het inlogscherm — vul gebruikersnaam en wachtwoord in en klik op Inloggen</p>

<h3>Uitloggen</h3>
<p>Klik op de pijlknop (&#10148;) rechtsboven naast uw naam om uit te loggen. De sessie wordt direct beëindigd.</p>

<div class="hlg-tip">&#128161; Sessies verlopen automatisch na 24 uur inactiviteit.</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ 2 ROLLEN -->
<div class="hlg-sectie">
<h2 id="hlg-rollen">2. Rollen en rechten</h2>
<p>Elke gebruiker heeft één van de volgende rollen. De rol bepaalt welke modules de gebruiker mag <em>bewerken</em>. Alle modules zijn voor iedereen <em>leesbaar</em>.</p>

<table>
  <thead><tr>
    <th>Rol</th><th>Omschrijving</th>
    <th>Importeer</th><th>Tijdschema</th><th>Startlijsten</th>
    <th>Live / Uitslag</th><th>Beheer</th><th>Gebruikers</th>
  </tr></thead>
  <tbody>
    <tr><td><strong>Owner</strong></td><td>Volledige toegang</td>
        <td>✏️</td><td>✏️</td><td>✏️</td><td>✏️</td><td>✏️</td><td>✏️</td></tr>
    <tr><td><strong>Admin</strong></td><td>Beheerder</td>
        <td>✏️</td><td>✏️</td><td>✏️</td><td>✏️</td><td>✏️</td><td>✏️*</td></tr>
    <tr><td><strong>Importer</strong></td><td>KNSB-gegevens importeren</td>
        <td>✏️</td><td>👁</td><td>👁</td><td>👁</td><td>👁</td><td>—</td></tr>
    <tr><td><strong>Planner</strong></td><td>Tijdschema en startlijsten</td>
        <td>👁</td><td>✏️</td><td>✏️</td><td>👁</td><td>👁</td><td>—</td></tr>
    <tr><td><strong>Timer</strong></td><td>Live verwerking en uitslag</td>
        <td>👁</td><td>👁</td><td>👁</td><td>✏️</td><td>👁</td><td>—</td></tr>
    <tr><td><strong>Viewer</strong></td><td>Alleen lezen</td>
        <td>👁</td><td>👁</td><td>👁</td><td>👁</td><td>👁</td><td>—</td></tr>
  </tbody>
</table>
<p style="font-size:9pt;color:#666">* Admin kan geen andere admins of de owner bewerken.</p>
<p>Modules waarvoor u geen schrijfrechten heeft worden weergegeven met een blauw <em>"Lees-alleen"</em> banner bovenin. Knoppen en invoervelden zijn dan uitgeschakeld.</p>

<!-- MOCKUP: hoofdscherm met lees-alleen banner -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:6px 12px;font-size:.78rem;display:flex;align-items:center;justify-content:space-between">
    <div style="display:flex;align-items:center;gap:8px">
      <strong>InlineComp</strong>
      <span style="background:rgba(255,255,255,.18);border-radius:10px;padding:1px 7px;font-size:.68rem">KNSB Inline</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;font-size:.72rem">
      <span style="opacity:.8">&#128366; Handleiding</span>
      <span>Jan de Vries</span>
      <span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px">importer</span>
      <span style="border:1px solid rgba(255,255,255,.35);border-radius:4px;padding:1px 7px">&#10148;</span>
    </div>
  </div>
  <div style="display:flex;min-height:130px">
    <div style="background:#162d4a;width:115px;flex-shrink:0;padding:8px 0">
      <div style="padding:5px 12px;font-size:.73rem;color:rgba(255,255,255,.7);display:flex;align-items:center;gap:6px">⬇ Importeer</div>
      <div style="padding:5px 12px;font-size:.73rem;color:rgba(255,255,255,.9);display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.13)">📅 Tijdschema</div>
      <div style="padding:5px 12px;font-size:.73rem;color:rgba(255,255,255,.7);display:flex;align-items:center;gap:6px">📋 Startlijsten</div>
      <div style="padding:5px 12px;font-size:.73rem;color:rgba(255,255,255,.7);display:flex;align-items:center;gap:6px">▶ Live</div>
    </div>
    <div style="flex:1;padding:10px 14px">
      <div style="background:#e8f0fb;border-left:3px solid #2E75B6;color:#1a3a5c;padding:5px 10px;border-radius:4px;font-size:.74rem;margin-bottom:8px">
        👁 Lees-alleen — uw rol heeft geen schrijfrechten voor deze module.
      </div>
      <div style="font-size:.82rem;font-weight:700;color:#1a3a5c;margin-bottom:4px">Tijdschema</div>
      <div style="display:flex;gap:6px">
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 9px;font-size:.73rem;opacity:.4;cursor:not-allowed;background:#f5f5f5">Aanmaken</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 9px;font-size:.73rem;opacity:.4;cursor:not-allowed;background:#f5f5f5">Genereer</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 9px;font-size:.73rem;opacity:.4;cursor:not-allowed;background:#f5f5f5">Publiceer</div>
      </div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Hoofdscherm: een Importer ziet het Tijdschema in lees-alleen modus — knoppen zijn uitgeschakeld</p>
</div>

<!-- ═══════════════════════════════════════════════════════════ 3 IMPORTEER -->
<div class="hlg-sectie">
<h2 id="hlg-importeer">3. Importeer</h2>
<p>De module <strong>Importeer</strong> is het startpunt voor elke wedstrijd. Hier worden deelnemers vanuit de KNSB-API gesynchroniseerd en beheerd.</p>

<h3>3.1 Wedstrijd selecteren</h3>
<ol>
  <li>Klik links in de lijst op een wedstrijd om deze te selecteren.</li>
  <li>Gebruik de datumfilters en dropdowns bovenaan om de lijst te verfijnen.</li>
  <li>Na selectie worden de deelnemers automatisch opgehaald vanuit de KNSB-API en vergeleken met de lokale database.</li>
</ol>

<h3>3.2 De vergelijktabel</h3>
<p>Na het ophalen verschijnt per categorie een tabel met alle ingeschreven deelnemers. Elke deelnemer heeft een <strong>status</strong>:</p>
<table>
  <thead><tr><th>Status</th><th>Betekenis</th><th>Kleur</th></tr></thead>
  <tbody>
    <tr><td>Bevestigd</td><td>KNSB heeft deelname bevestigd</td><td>Groen</td></tr>
    <tr><td>Bevestigd bij org.</td><td>Organisatie heeft deelname bevestigd (niet via KNSB)</td><td>Blauw</td></tr>
    <tr><td>Niet bevestigd</td><td>Ingeschreven maar nog niet bevestigd door KNSB</td><td>Grijs</td></tr>
    <tr><td>Afgemeld (KNSB)</td><td>Via KNSB afgemeld — kan niet worden gewijzigd</td><td>Rood</td></tr>
    <tr><td>Afgemeld bij org.</td><td>Door de organisatie afgemeld</td><td>Oranje</td></tr>
    <tr><td>Niet getekend</td><td>Deelnemer heeft niet getekend bij aankomst</td><td>Paars</td></tr>
  </tbody>
</table>

<!-- MOCKUP: vergelijktabel met statusbadges -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">InlineComp</div>
  <div style="display:flex;min-height:160px">
    <div style="width:175px;flex-shrink:0;border-right:1px solid #dde;background:#f5f7fa;padding:6px 8px">
      <div style="font-size:.7rem;font-weight:700;color:#1a3a5c;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px">Wedstrijden</div>
      <div style="border-radius:5px;padding:5px 7px;background:#fff;margin-bottom:4px;font-size:.72rem;border-left:3px solid #e86c1b;box-shadow:0 1px 3px rgba(0,0,0,.07)">
        <strong>NK Inline 2025</strong><br>
        <span style="color:#888">12 apr · Zoetermeer</span>
      </div>
      <div style="border-radius:5px;padding:5px 7px;background:#fff;margin-bottom:4px;font-size:.72rem;border:1px solid #dde">
        Limburg Cup<br>
        <span style="color:#888">19 apr · Heerlen</span>
      </div>
      <div style="border-radius:5px;padding:5px 7px;background:#fff;font-size:.72rem;border:1px solid #dde">
        Kampioenschap Noord<br>
        <span style="color:#888">3 mei · Groningen</span>
      </div>
    </div>
    <div style="flex:1;padding:7px 10px;overflow:auto">
      <div style="font-size:.84rem;font-weight:700;color:#1a3a5c;margin-bottom:1px">NK Inline 2025</div>
      <div style="font-size:.71rem;color:#888;margin-bottom:7px">12 april 2025 · Zoetermeer · 48 deelnemers</div>
      <div style="display:flex;gap:4px;margin-bottom:7px;flex-wrap:wrap">
        <div style="background:#e86c1b;color:#fff;padding:2px 9px;border-radius:12px;font-size:.7rem;font-weight:600">Junioren A</div>
        <div style="background:#f0f0f0;color:#666;padding:2px 9px;border-radius:12px;font-size:.7rem">Junioren B</div>
        <div style="background:#f0f0f0;color:#666;padding:2px 9px;border-radius:12px;font-size:.7rem">Senioren</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:.7rem">
        <thead>
          <tr style="background:#f0f4f8">
            <th style="padding:3px 5px;text-align:left;color:#444;border-bottom:1px solid #dde;font-weight:600">#</th>
            <th style="padding:3px 5px;text-align:left;color:#444;border-bottom:1px solid #dde;font-weight:600">Naam</th>
            <th style="padding:3px 5px;text-align:left;color:#444;border-bottom:1px solid #dde;font-weight:600">Status</th>
            <th style="padding:3px 5px;text-align:left;color:#444;border-bottom:1px solid #dde;font-weight:600">Transponder</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:3px 5px;color:#999">1</td>
            <td style="padding:3px 5px">Anna Bakker</td>
            <td style="padding:3px 5px"><span style="background:#d4edda;color:#155724;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:600">Bevestigd</span></td>
            <td style="padding:3px 5px;font-family:monospace">A1B2C3</td>
          </tr>
          <tr style="background:#f8f9fb">
            <td style="padding:3px 5px;color:#999">2</td>
            <td style="padding:3px 5px">Tom Jansen</td>
            <td style="padding:3px 5px"><span style="background:#cce5ff;color:#004085;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:600">Bev. bij org.</span></td>
            <td style="padding:3px 5px;font-family:monospace">D4E5F6</td>
          </tr>
          <tr>
            <td style="padding:3px 5px;color:#999">3</td>
            <td style="padding:3px 5px">Lena Visser</td>
            <td style="padding:3px 5px"><span style="background:#f8d7da;color:#721c24;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:600">Afgemeld (KNSB)</span></td>
            <td style="padding:3px 5px;color:#bbb">—</td>
          </tr>
          <tr style="background:#f8f9fb">
            <td style="padding:3px 5px;color:#999">4</td>
            <td style="padding:3px 5px">Mark de Wit</td>
            <td style="padding:3px 5px"><span style="background:#e2e3e5;color:#383d41;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:600">Niet bevestigd</span></td>
            <td style="padding:3px 5px;font-family:monospace">G7H8I9</td>
          </tr>
          <tr>
            <td style="padding:3px 5px;color:#999">5</td>
            <td style="padding:3px 5px">Sara Berg</td>
            <td style="padding:3px 5px"><span style="background:#ffe5cc;color:#7c3500;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:600">Afgemeld bij org.</span></td>
            <td style="padding:3px 5px;color:#bbb">—</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Importeer-module: wedstrijdselectie (links) en vergelijktabel met gekleurde statusbadges per deelnemer (rechts)</p>

<h3>3.3 Status wijzigen</h3>
<p>Klik op de statusbadge van een deelnemer om de status te wijzigen. De cyclus is afhankelijk van de KNSB-status:</p>
<ul>
  <li><strong>KNSB-bevestigd:</strong> Bevestigd → Afgemeld bij org. → Niet getekend → Bevestigd</li>
  <li><strong>Niet bevestigd / org. toegevoegd:</strong> Niet bevestigd / Bev. bij org. → Afgemeld bij org. → Niet getekend → Bevestigd bij org.</li>
  <li><strong>KNSB-afgemeld:</strong> Kan niet worden gewijzigd.</li>
</ul>

<h3>3.4 Deelnemer handmatig toevoegen</h3>
<p>Onderaan elke categorie staat de knop <strong>+ Deelnemer toevoegen</strong>. In het venster dat verschijnt:</p>
<ol>
  <li>Zoek op <em>relatienummer</em> of <em>startnummer + categorie</em> om gegevens automatisch in te vullen.</li>
  <li>Vul ontbrekende velden in (naam, categorie, geslacht zijn verplicht).</li>
  <li><strong>Transponder:</strong> voer het transpondernummer in. Het systeem vergelijkt dit met bekende transponders (T1, T2, extras) van deze rijder:
    <ul>
      <li>Match gevonden → transponder wordt direct als actief ingesteld.</li>
      <li>Geen match → u wordt gevraagd te bevestigen of dit de juiste transponder is. Bij <em>Ja</em> wordt hij als extra opgeslagen; bij <em>Nee</em> kunt u corrigeren.</li>
    </ul>
  </li>
  <li>Klik <strong>Toevoegen</strong>. De deelnemer verschijnt in de tabel met status <em>Bevestigd bij org.</em></li>
</ol>

<!-- MOCKUP: deelnemer toevoegen modal met transponder-waarschuwing -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:6px 14px;font-size:.8rem;font-weight:700;display:flex;align-items:center;justify-content:space-between">
    <span>Deelnemer toevoegen</span>
    <span style="opacity:.6;font-size:.85rem">✕</span>
  </div>
  <div style="padding:10px 14px">
    <div style="display:flex;gap:4px;margin-bottom:10px">
      <div style="background:#1a3a5c;color:#fff;padding:2px 9px;border-radius:12px;font-size:.7rem">Op relatienr.</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 9px;border-radius:12px;font-size:.7rem">Op startnr./cat.</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:8px">
      <div>
        <div style="font-size:.68rem;color:#666;margin-bottom:2px">Naam *</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 7px;background:#fff;font-size:.77rem">Anna Bakker</div>
      </div>
      <div>
        <div style="font-size:.68rem;color:#666;margin-bottom:2px">Relatienummer</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 7px;background:#fff;font-size:.77rem">12345</div>
      </div>
      <div>
        <div style="font-size:.68rem;color:#666;margin-bottom:2px">Categorie *</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 7px;background:#fff;font-size:.77rem;color:#444">Junioren A ▾</div>
      </div>
      <div>
        <div style="font-size:.68rem;color:#666;margin-bottom:2px">Transponder</div>
        <div style="border:1px solid #e86c1b;border-radius:4px;padding:4px 7px;background:#fff;font-size:.77rem;font-family:monospace">X9Y8Z7</div>
      </div>
    </div>
    <div style="background:#fff8e1;border-left:3px solid #f59e0b;padding:5px 9px;border-radius:3px;font-size:.73rem;margin-bottom:9px">
      ⚠ Is transponder "X9Y8Z7" de juiste voor deze rijder? (Bekende transponders: A1B2C3, D4E5F6)
      <div style="margin-top:5px;display:flex;gap:6px">
        <div style="background:#1a3a5c;color:#fff;border-radius:4px;padding:2px 8px;font-size:.7rem;font-weight:600">Ja, klopt</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;font-size:.7rem;color:#555">Nee, aanpassen</div>
      </div>
    </div>
    <div style="display:flex;gap:6px;justify-content:flex-end">
      <div style="border:1px solid #ccc;border-radius:5px;padding:4px 10px;font-size:.77rem;color:#555">Annuleren</div>
      <div style="background:#e86c1b;color:#fff;border-radius:5px;padding:4px 10px;font-size:.77rem;font-weight:600">Toevoegen</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Modal voor handmatig toevoegen: onbekende transponder triggert een bevestigingsvraag met Ja/Nee keuze</p>

<div class="hlg-tip">&#128161; Als de ingevoerde categorie niet overeenkomt met de verwachte categorieën voor dit onderdeel, verschijnt een vergelijkbare waarschuwing. Klik nogmaals op <em>Toch toevoegen</em> om door te gaan.</div>

<h3>3.5 Transponders beheren</h3>
<p>In de kolom <em>Transponder</em> van de tabel kunt u per deelnemer de actieve transponder selecteren via een dropdown. De dropdown toont:</p>
<ul>
  <li><strong>T1</strong> – officiële KNSB transponder slot 1</li>
  <li><strong>T2</strong> – officiële KNSB transponder slot 2</li>
  <li><strong>Extra</strong> – lokaal toegevoegde transponders (per wedstrijd)</li>
</ul>
<p>Via de <strong>+</strong> knop naast de dropdown kunt u een nieuwe extra transponder toevoegen.</p>

<h3>3.6 Importeren</h3>
<p>Als alle aanpassingen klaar zijn klikt u op de oranje knop <strong>Importeer</strong>. Dit slaat alle deelnemers, statussen en transponders op in de lokale database. Na import zijn de gegevens beschikbaar voor tijdschema en startlijsten.</p>
<div class="hlg-warn">&#9888; Bij gelijktijdige bewerking door meerdere gebruikers detecteert het systeem een conflict. Er verschijnt een melding met de knop <em>Herlaad</em> om de meest recente versie op te halen.</div>

<h3>3.7 Afdrukken</h3>
<p>Na import zijn twee afdrukopties beschikbaar:</p>
<ul>
  <li><strong>Tekenlijst</strong> – lijst per categorie voor handtekeningen bij aankomst</li>
  <li><strong>Deelnemerslijst</strong> – overzicht van alle bevestigde deelnemers per categorie</li>
</ul>
</div>

<!-- ═══════════════════════════════════════════════════════════ 4 TIJDSCHEMA -->
<div class="hlg-sectie">
<h2 id="hlg-tijdschema">4. Tijdschema</h2>
<p>In de module <strong>Tijdschema</strong> wordt het programma van de wedstrijd opgebouwd: welke afstanden worden gereden, in welke volgorde, en hoe lang duurt elk blok.</p>

<h3>4.1 Tijdschema aanmaken</h3>
<p>Als er nog geen tijdschema bestaat, klikt u op <strong>Tijdschema aanmaken</strong>. Daarna kiest u het <strong>competitiesysteem</strong>:</p>
<ul>
  <li><strong>Punten</strong> – klassiek inline-systeem met heats en punten</li>
  <li><strong>Tijdrit</strong> – iedereen rijdt individueel op tijd</li>
  <li><strong>Knock-out</strong> – directe uitschakeling per rit</li>
</ul>
<div class="hlg-warn">&#9888; Wisselen van systeem verwijdert alle afstandsinstellingen en het gegenereerde programma.</div>

<h3>4.2 Afstanden configureren</h3>
<p>Per afstand (onderdeel) stelt u in:</p>
<ul>
  <li>Starttijd van het eerste blok</li>
  <li>Duur per rit (in m:ss)</li>
  <li>Aantal ritten per heat</li>
  <li>Pauze tussen heats</li>
</ul>

<h3>4.3 Programmaopbouw</h3>
<p>Via de knoppen onderaan voegt u blokken toe aan het programma:</p>
<ul>
  <li><strong>Pauze</strong> – vrije pauze met instelbare duur</li>
  <li><strong>Inrijden</strong> – inrijdblok gekoppeld aan één of meer categorieën</li>
  <li><strong>Wedstrijdstart</strong> – officieel startmoment van de wedstrijd</li>
  <li><strong>Ceremonie</strong> – huldigingsblok</li>
</ul>
<p>Blokken kunt u via de pijlknoppen omhoog of omlaag verplaatsen, en verwijderen met de prullenbak-knop.</p>

<!-- MOCKUP: tijdschema blokken -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Tijdschema</div>
  <div style="padding:10px 14px">
    <div style="font-size:.84rem;font-weight:700;color:#1a3a5c;margin-bottom:1px">NK Inline 2025</div>
    <div style="font-size:.71rem;color:#888;margin-bottom:10px">Systeem: Punten &nbsp;·&nbsp; Zoetermeer</div>
    <div style="display:flex;flex-direction:column;gap:5px">
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:42px;flex-shrink:0;text-align:right;font-size:.68rem;color:#999">09:00</div>
        <div style="flex:1;background:#e8f0fb;border-left:3px solid #2E75B6;border-radius:4px;padding:4px 8px;font-size:.74rem;color:#1a3a5c;display:flex;align-items:center;justify-content:space-between">
          <span><strong>Inrijden</strong> &nbsp;·&nbsp; Junioren A + B (15 min)</span>
          <span style="color:#aaa;font-size:.8rem">↑ ↓ 🗑</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:42px;flex-shrink:0;text-align:right;font-size:.68rem;color:#999">09:15</div>
        <div style="flex:1;background:#fff3cd;border-left:3px solid #e86c1b;border-radius:4px;padding:4px 8px;font-size:.74rem;color:#7c4000;display:flex;align-items:center;justify-content:space-between">
          <span><strong>Wedstrijdstart</strong></span>
          <span style="color:#aaa;font-size:.8rem">↑ ↓ 🗑</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:42px;flex-shrink:0;text-align:right;font-size:.68rem;color:#999">09:15</div>
        <div style="flex:1;background:#d4edda;border-left:3px solid #28a745;border-radius:4px;padding:4px 8px;font-size:.74rem;color:#155724;display:flex;align-items:center;justify-content:space-between">
          <span><strong>Heat 1</strong> &nbsp;·&nbsp; Junioren A &nbsp;·&nbsp; 4 rijders &nbsp;·&nbsp; 8 min</span>
          <span style="color:#aaa;font-size:.8rem">↑ ↓ 🗑</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:42px;flex-shrink:0;text-align:right;font-size:.68rem;color:#999">09:23</div>
        <div style="flex:1;background:#d4edda;border-left:3px solid #28a745;border-radius:4px;padding:4px 8px;font-size:.74rem;color:#155724;display:flex;align-items:center;justify-content:space-between">
          <span><strong>Heat 2</strong> &nbsp;·&nbsp; Junioren A &nbsp;·&nbsp; 4 rijders &nbsp;·&nbsp; 8 min</span>
          <span style="color:#aaa;font-size:.8rem">↑ ↓ 🗑</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:42px;flex-shrink:0;text-align:right;font-size:.68rem;color:#999">09:35</div>
        <div style="flex:1;background:#ede0ff;border-left:3px solid #9C27B0;border-radius:4px;padding:4px 8px;font-size:.74rem;color:#4a148c;display:flex;align-items:center;justify-content:space-between">
          <span><strong>Pauze</strong> &nbsp;·&nbsp; 15 min</span>
          <span style="color:#aaa;font-size:.8rem">↑ ↓ 🗑</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:42px;flex-shrink:0;text-align:right;font-size:.68rem;color:#999">09:50</div>
        <div style="flex:1;background:#d4edda;border-left:3px solid #28a745;border-radius:4px;padding:4px 8px;font-size:.74rem;color:#155724;display:flex;align-items:center;justify-content:space-between">
          <span><strong>Heat 1</strong> &nbsp;·&nbsp; Junioren B &nbsp;·&nbsp; 5 rijders &nbsp;·&nbsp; 8 min</span>
          <span style="color:#aaa;font-size:.8rem">↑ ↓ 🗑</span>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:6px;margin-top:10px">
      <div style="border:1px dashed #ccc;border-radius:4px;padding:3px 9px;font-size:.71rem;color:#666">+ Pauze</div>
      <div style="border:1px dashed #ccc;border-radius:4px;padding:3px 9px;font-size:.71rem;color:#666">+ Inrijden</div>
      <div style="border:1px dashed #ccc;border-radius:4px;padding:3px 9px;font-size:.71rem;color:#666">+ Ceremonie</div>
      <div style="background:#e86c1b;color:#fff;border-radius:4px;padding:3px 9px;font-size:.71rem;font-weight:600;margin-left:auto">Genereer programma</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Tijdschema: blokken met kleurcodering — blauw=inrijden, geel=wedstrijdstart, groen=heat, paars=pauze. Pijlen verplaatsen blokken.</p>

<h3>4.4 Ritten genereren</h3>
<p>Klik op <strong>Genereer programma</strong> om alle ritten automatisch te plannen op basis van de ingestelde parameters. Het gegenereerde programma toont alle ritten met starttijden.</p>
<div class="hlg-tip">&#128161; Het tijdschema wordt automatisch bijgewerkt als andere gebruikers wijzigingen opslaan (elke 30 seconden).</div>

<h3>4.5 Schema publiceren</h3>
<p>Via <strong>&#128196; Publiceer schema</strong> wordt het tijdschema beschikbaar gesteld voor de live-verwerking module.</p>
</div>

<!-- ═══════════════════════════════════════════════════════════ 5 STARTLIJSTEN -->
<div class="hlg-sectie">
<h2 id="hlg-startlijsten">5. Startlijsten</h2>
<p>De module <strong>Startlijsten</strong> genereert de rijdersverdeling over heats per afstand.</p>

<h3>5.1 Categorieën en afstanden</h3>
<p>Bovenaan staan de categorietabs. Per categorie kunnen meerdere afstanden beschikbaar zijn (via de afstandstabs).</p>

<h3>5.2 Rondes instellen</h3>
<p>Per afstand stelt u het aantal rondes in (1 t/m 4). Elke ronde heeft een:</p>
<ul>
  <li><strong>Naam</strong> – bijv. "Heats", "Halve finales", "Finale"</li>
  <li><strong>Max. rijders per heat</strong></li>
  <li><strong>Loting-methode</strong> (ronde 1): willekeurig, op startnummer, of op klassement</li>
  <li><strong>Doorstroom naar volgende ronde</strong>: X tijdsnelsten of X winnaars per heat</li>
</ul>

<h3>5.3 Startlijst genereren</h3>
<p>Klik op <strong>Genereer startlijst</strong>. Het systeem verdeelt de bevestigde deelnemers over de heats. Bij loting op klassement wordt het geselecteerde KNSB-klassement gebruikt voor de volgorde.</p>

<!-- MOCKUP: startlijsten heats -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Startlijsten</div>
  <div style="padding:8px 12px">
    <div style="display:flex;gap:4px;margin-bottom:5px;flex-wrap:wrap">
      <div style="background:#e86c1b;color:#fff;padding:2px 10px;border-radius:12px;font-size:.7rem;font-weight:600">Junioren A</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 10px;border-radius:12px;font-size:.7rem">Junioren B</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 10px;border-radius:12px;font-size:.7rem">Senioren</div>
    </div>
    <div style="display:flex;gap:4px;margin-bottom:10px">
      <div style="background:#1a3a5c;color:#fff;padding:2px 8px;border-radius:8px;font-size:.68rem">500m</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 8px;border-radius:8px;font-size:.68rem">1000m</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 8px;border-radius:8px;font-size:.68rem">1500m</div>
    </div>
    <div style="font-size:.72rem;color:#555;margin-bottom:6px">Ronde 1 – Heats &nbsp;·&nbsp; Max 4 per heat &nbsp;·&nbsp; Loting: willekeurig</div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:7px">
      <div style="border:1px solid #dde;border-radius:6px;overflow:hidden">
        <div style="background:#f0f4f8;padding:3px 8px;font-size:.71rem;font-weight:700;color:#1a3a5c;border-bottom:1px solid #dde">Heat 1 &nbsp;<span style="font-weight:400;color:#888">09:15</span></div>
        <div style="padding:4px 8px">
          <div style="font-size:.7rem;padding:2px 0;border-bottom:1px solid #f0f0f0;display:flex;gap:5px"><span style="color:#bbb;width:14px">1</span>Anna Bakker</div>
          <div style="font-size:.7rem;padding:2px 0;border-bottom:1px solid #f0f0f0;display:flex;gap:5px"><span style="color:#bbb;width:14px">2</span>Mark de Wit</div>
          <div style="font-size:.7rem;padding:2px 0;display:flex;gap:5px"><span style="color:#bbb;width:14px">3</span>Lena Visser</div>
        </div>
      </div>
      <div style="border:1px solid #dde;border-radius:6px;overflow:hidden">
        <div style="background:#f0f4f8;padding:3px 8px;font-size:.71rem;font-weight:700;color:#1a3a5c;border-bottom:1px solid #dde">Heat 2 &nbsp;<span style="font-weight:400;color:#888">09:23</span></div>
        <div style="padding:4px 8px">
          <div style="font-size:.7rem;padding:2px 0;border-bottom:1px solid #f0f0f0;display:flex;gap:5px"><span style="color:#bbb;width:14px">1</span>Tom Jansen</div>
          <div style="font-size:.7rem;padding:2px 0;border-bottom:1px solid #f0f0f0;display:flex;gap:5px"><span style="color:#bbb;width:14px">2</span>Piet Smits</div>
          <div style="font-size:.7rem;padding:2px 0;display:flex;gap:5px"><span style="color:#bbb;width:14px">3</span>Sara Berg</div>
        </div>
      </div>
      <div style="border:1px solid #dde;border-radius:6px;overflow:hidden">
        <div style="background:#f0f4f8;padding:3px 8px;font-size:.71rem;font-weight:700;color:#1a3a5c;border-bottom:1px solid #dde">Finale &nbsp;<span style="font-weight:400;color:#888">10:30</span></div>
        <div style="padding:4px 8px">
          <div style="font-size:.7rem;padding:2px 0;border-bottom:1px solid #f0f0f0;color:#bbb;font-style:italic">↳ winnaar H1</div>
          <div style="font-size:.7rem;padding:2px 0;border-bottom:1px solid #f0f0f0;color:#bbb;font-style:italic">↳ winnaar H2</div>
          <div style="font-size:.7rem;padding:2px 0;color:#bbb;font-style:italic">↳ snelste verliezer</div>
        </div>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <div style="background:#e86c1b;color:#fff;border-radius:4px;padding:4px 10px;font-size:.72rem;font-weight:600">Genereer startlijst</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Startlijsten: categorietabs + afstandstabs bovenaan, heats naast elkaar. Ronde 2 (finale) toont doorstroomplaatsen als placeholder.</p>

<div class="hlg-tip">&#128161; B- en C-finales kunnen optioneel worden ingeschakeld voor niet-geplaatste rijders.</div>

<h3>5.4 Categorieën samenvoegen en splitsen</h3>
<p>Via de <strong>Importeer</strong>-module kunnen categorieën worden samengevoegd (meerdere categorieën in dezelfde heat) of gesplitst (één categorie verdeeld over meerdere groepen).</p>
</div>

<!-- ═══════════════════════════════════════════════════════════ 6 LIVE -->
<div class="hlg-sectie">
<h2 id="hlg-live">6. Live verwerking</h2>
<p>De module <strong>Live</strong> wordt gebruikt tijdens de wedstrijd voor real-time tijdregistratie en verwerking van resultaten per rit.</p>
<ul>
  <li>Selecteer de huidige rit vanuit het gepubliceerde tijdschema.</li>
  <li>Voer finishvolgorde en tijden in per rijder.</li>
  <li>Resultaten worden direct zichtbaar in de Uitslag-module.</li>
</ul>
<div class="hlg-tip">&#128161; Alleen gebruikers met de rol <em>Timer</em>, <em>Admin</em> of <em>Owner</em> kunnen resultaten invoeren.</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ 7 UITSLAG -->
<div class="hlg-sectie">
<h2 id="hlg-uitslag">7. Uitslag</h2>
<p>De module <strong>Uitslag</strong> toont een overzicht van alle resultaten van de lopende of afgelopen wedstrijd, gegroepeerd per categorie en afstand.</p>
<ul>
  <li>Resultaten worden automatisch bijgewerkt vanuit de Live-module.</li>
  <li>De eindstand wordt berekend op basis van het ingestelde competitiesysteem (punten, tijd, knock-out).</li>
  <li>Via de afdrukfunctie kunt u de uitslag als PDF exporteren.</li>
</ul>
</div>

<!-- ═══════════════════════════════════════════════════════════ 8 BEHEER -->
<div class="hlg-sectie">
<h2 id="hlg-beheer">8. Beheer</h2>
<p>De <strong>Beheer</strong>-module (toegankelijk voor Admin en Owner) bevat drie tabbladen per organisatie: Gegevens, Wedstrijden en Klassementen.</p>

<h3>8.1 Organisaties</h3>
<p>Klik op een organisatie in de lijst om deze te bewerken, of klik op <strong>+ Nieuwe organisatie</strong>.</p>
<ul>
  <li><strong>Naam en e-mail</strong> – worden gebruikt om wedstrijden van de KNSB-API te koppelen aan deze organisatie.</li>
  <li><strong>Aliassen</strong> – alternatieve namen waarmee de organisatie ook bekend is (voor automatische koppeling).</li>
  <li><strong>Logo</strong> – upload een logo (PNG/JPG) dat wordt getoond op afdrukken.</li>
  <li><strong>Sponsors</strong> – sponsorlogos met optionele URL, getoond op afdrukken.</li>
</ul>

<!-- MOCKUP: beheer organisaties + tabs -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Beheer</div>
  <div style="display:flex;min-height:170px">
    <div style="width:165px;flex-shrink:0;border-right:1px solid #dde;background:#f5f7fa;padding:6px 8px">
      <div style="font-size:.69rem;font-weight:700;color:#1a3a5c;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px">Organisaties</div>
      <div style="border-radius:5px;padding:5px 8px;background:#1a3a5c;color:#fff;margin-bottom:4px;font-size:.72rem;font-weight:600">KNSB Noord</div>
      <div style="border-radius:5px;padding:5px 8px;background:#fff;margin-bottom:4px;font-size:.72rem;border:1px solid #dde;color:#444">ISV Zoetermeer</div>
      <div style="border-radius:5px;padding:5px 8px;background:#fff;font-size:.72rem;border:1px solid #dde;color:#444">IC Limburg</div>
      <div style="background:#e86c1b;color:#fff;text-align:center;border-radius:4px;padding:3px 7px;font-size:.69rem;margin-top:8px;font-weight:600">+ Nieuwe organisatie</div>
    </div>
    <div style="flex:1;padding:7px 11px">
      <div style="font-size:.84rem;font-weight:700;color:#1a3a5c;margin-bottom:8px">KNSB Noord</div>
      <div style="display:flex;gap:0;margin-bottom:9px;border-bottom:2px solid #dde">
        <div style="padding:3px 11px;font-size:.74rem;font-weight:700;color:#1a3a5c;border-bottom:2px solid #e86c1b;margin-bottom:-2px">Gegevens</div>
        <div style="padding:3px 11px;font-size:.74rem;color:#888">Wedstrijden</div>
        <div style="padding:3px 11px;font-size:.74rem;color:#888">Klassementen</div>
      </div>
      <div style="display:grid;gap:5px">
        <div>
          <div style="font-size:.68rem;color:#666;margin-bottom:2px">Naam</div>
          <div style="border:1px solid #ccc;border-radius:4px;padding:4px 7px;background:#fff;font-size:.77rem">KNSB Noord</div>
        </div>
        <div>
          <div style="font-size:.68rem;color:#666;margin-bottom:2px">E-mail</div>
          <div style="border:1px solid #ccc;border-radius:4px;padding:4px 7px;background:#fff;font-size:.77rem">noord@knsb.nl</div>
        </div>
        <div>
          <div style="font-size:.68rem;color:#666;margin-bottom:2px">Aliassen</div>
          <div style="display:flex;gap:4px;flex-wrap:wrap">
            <span style="background:#e8f0fb;color:#1a3a5c;border-radius:10px;padding:1px 8px;font-size:.68rem">KNSB N.</span>
            <span style="background:#e8f0fb;color:#1a3a5c;border-radius:10px;padding:1px 8px;font-size:.68rem">Noord</span>
            <span style="border:1px dashed #ccc;border-radius:10px;padding:1px 8px;font-size:.68rem;color:#888">+ alias</span>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:6px;margin-top:9px;justify-content:flex-end">
        <div style="background:#e86c1b;color:#fff;border-radius:5px;padding:4px 10px;font-size:.74rem;font-weight:600">Opslaan</div>
        <div style="border:1px solid #e53e3e;color:#c00;border-radius:5px;padding:4px 10px;font-size:.74rem">Verwijderen</div>
      </div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Beheer-module: organisatielijst links (actieve organisatie gemarkeerd), tabbladen Gegevens / Wedstrijden / Klassementen rechts</p>

<h3>8.2 Wedstrijden</h3>
<p>Het tabblad <em>Wedstrijden</em> toont alle KNSB-wedstrijden die overeenkomen met de organisatienaam of e-mailadres. Wedstrijden die al in de lokale database staan worden gemarkeerd als <em>"In database"</em>.</p>
<p>Via de <strong>Verwijderen</strong>-knop kunt u een wedstrijd compleet uit de database verwijderen. <strong>Let op:</strong> dit verwijdert ook alle deelnemers, het tijdschema en de startlijsten van die wedstrijd.</p>
<div class="hlg-warn">&#9888; Verwijderen kan niet ongedaan worden gemaakt.</div>

<h3>8.3 Klassementen</h3>
<p>Sleep een KNSB-klassement PDF naar het uploadvak, of klik om een bestand te kiezen. Het systeem leest het klassement automatisch uit en slaat het op. Klassementen worden gebruikt bij de loting op klassement in de startlijsten-module.</p>
<ul>
  <li>Kies een <strong>naam</strong> voor het klassement (bijv. "Nationaal 2024–2025").</li>
  <li>Koppel het optioneel aan een <strong>organisatie</strong>.</li>
  <li>Klik op <strong>Verwerk klassement</strong>.</li>
</ul>
</div>

<!-- ═══════════════════════════════════════════════════════════ 9 GEBRUIKERS -->
<div class="hlg-sectie">
<h2 id="hlg-gebruikers">9. Gebruikersbeheer</h2>
<p>Het tabblad <strong>Gebruikers</strong> is alleen zichtbaar voor gebruikers met de rol <em>Owner</em> of <em>Admin</em>.</p>

<h3>9.1 Overzicht</h3>
<p>De gebruikerstabel toont alle gebruikers met naam, gebruikersnaam, rol en e-mailadres. Inactieve gebruikers worden grijs weergegeven.</p>

<!-- MOCKUP: gebruikerstabel -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700;display:flex;align-items:center;justify-content:space-between">
    <span>Gebruikersbeheer</span>
    <div style="background:#e86c1b;color:#fff;border-radius:4px;padding:2px 8px;font-size:.69rem;font-weight:600">+ Nieuwe gebruiker</div>
  </div>
  <div style="padding:8px 12px">
    <table style="width:100%;border-collapse:collapse;font-size:.71rem">
      <thead>
        <tr style="background:#f0f4f8">
          <th style="padding:3px 6px;text-align:left;color:#444;border-bottom:1px solid #dde;font-weight:600">Naam</th>
          <th style="padding:3px 6px;text-align:left;color:#444;border-bottom:1px solid #dde;font-weight:600">Rol</th>
          <th style="padding:3px 6px;text-align:left;color:#444;border-bottom:1px solid #dde;font-weight:600">E-mail</th>
          <th style="padding:3px 6px;text-align:right;color:#444;border-bottom:1px solid #dde;font-weight:600">Acties</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="padding:3px 6px"><strong>Geert de Vries</strong> <span style="color:#aaa;font-size:.67rem">geert</span></td>
          <td style="padding:3px 6px"><span style="background:#1F4E79;color:#fff;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:700">owner</span></td>
          <td style="padding:3px 6px;color:#666">geert@knsb.nl</td>
          <td style="padding:3px 6px;text-align:right;color:#bbb;font-size:.8rem">— — —</td>
        </tr>
        <tr style="background:#f8f9fb">
          <td style="padding:3px 6px"><strong>Anna Bakker</strong> <span style="color:#aaa;font-size:.67rem">anna</span></td>
          <td style="padding:3px 6px"><span style="background:#E8A838;color:#fff;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:700">importer</span></td>
          <td style="padding:3px 6px;color:#666">anna@vc.nl</td>
          <td style="padding:3px 6px;text-align:right">✏ 🔑 🟢 🗑</td>
        </tr>
        <tr>
          <td style="padding:3px 6px"><strong>Tom Jansen</strong> <span style="color:#aaa;font-size:.67rem">tom</span></td>
          <td style="padding:3px 6px"><span style="background:#4CAF50;color:#fff;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:700">planner</span></td>
          <td style="padding:3px 6px;color:#666">tom@isv.nl</td>
          <td style="padding:3px 6px;text-align:right">✏ 🔑 🟢 🗑</td>
        </tr>
        <tr style="background:#f8f9fb;opacity:.45">
          <td style="padding:3px 6px"><strong>Lisa Smits</strong> <span style="color:#aaa;font-size:.67rem">lisa</span></td>
          <td style="padding:3px 6px"><span style="background:#9C27B0;color:#fff;border-radius:10px;padding:1px 7px;font-size:.66rem;font-weight:700">timer</span></td>
          <td style="padding:3px 6px;color:#666">lisa@ic.nl</td>
          <td style="padding:3px 6px;text-align:right">✏ 🔑 🔴 🗑</td>
        </tr>
      </tbody>
    </table>
    <div style="font-size:.68rem;color:#aaa;margin-top:4px">Grijs = inactieve gebruiker &nbsp;·&nbsp; ✏ bewerken &nbsp;·&nbsp; 🔑 wachtwoord &nbsp;·&nbsp; 🟢/🔴 activeren/deactiveren &nbsp;·&nbsp; 🗑 verwijderen</div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Gebruikerstabel met rolbadges en actiepictogrammen. Inactieve gebruikers (Lisa) worden grijs weergegeven.</p>

<h3>9.2 Gebruiker aanmaken</h3>
<ol>
  <li>Klik op <strong>+ Nieuwe gebruiker</strong>.</li>
  <li>Vul naam, gebruikersnaam, e-mail en rol in.</li>
  <li>Stel een wachtwoord in (minimaal 8 tekens).</li>
  <li>Klik op <strong>Opslaan</strong>.</li>
</ol>

<h3>9.3 Gebruiker bewerken</h3>
<p>Klik op het potlood-icoon (&#9998;) naast een gebruiker. Naam, gebruikersnaam, e-mail en rol zijn aanpasbaar.</p>

<h3>9.4 Wachtwoord wijzigen</h3>
<p>Klik op het sleutel-icoon (&#128273;) naast een gebruiker. U kunt uw eigen wachtwoord altijd wijzigen; voor andere gebruikers heeft u schrijfrechten nodig.</p>

<h3>9.5 Activeren / deactiveren</h3>
<p>Via de groene/rode bol (&#9679;) naast een gebruiker kunt u een account deactiveren (de gebruiker kan dan niet meer inloggen) of weer activeren.</p>

<h3>9.6 Verwijderen</h3>
<p>Via de prullenbak-knop (&#128465;) wordt een gebruiker definitief verwijderd. De owner-account kan niet worden verwijderd.</p>

<h3>Rechtenbeperkingen</h3>
<ul>
  <li>Een <em>Admin</em> kan geen andere admins of de owner beheren.</li>
  <li>De owner-account kan niet worden gedeactiveerd of verwijderd.</li>
  <li>U kunt uw eigen account niet verwijderen of deactiveren.</li>
</ul>
</div>

<!-- ═══════════════════════════════════════════════════════════ 10 TIPS -->
<div class="hlg-sectie">
<h2 id="hlg-tips">10. Algemene tips</h2>
<ul>
  <li><strong>Gelijktijdige bewerking:</strong> meerdere gebruikers kunnen tegelijk ingelogd zijn. Het systeem detecteert conflicten automatisch en toont een melding.</li>
  <li><strong>Transponders:</strong> het systeem houdt per wedstrijd bij welk transpondernummer actief is. Dit kan afwijken van de officiële KNSB-transponders T1 en T2.</li>
  <li><strong>Handmatig toegevoegde deelnemers</strong> blijven zichtbaar na een hersynch met de KNSB-API, zolang hun status niet "Afgemeld (KNSB)" is.</li>
  <li><strong>Wedstrijd kwijt na herlaad?</strong> Klik linksboven op het pijltje om de wedstrijdenlijst te verversen.</li>
  <li><strong>PDF afdrukken:</strong> gebruik de afdrukfunctie in de Importeer-module voor tekenlijsten en deelnemerslijsten. Uw browser zet dit automatisch om naar PDF.</li>
  <li><strong>Handleiding als PDF:</strong> klik op <em>Opslaan als PDF</em> rechtsboven in dit venster om de volledige handleiding af te drukken.</li>
</ul>
</div>

`; }
