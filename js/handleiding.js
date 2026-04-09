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
  <div class="versie">Versie 2026 &nbsp;·&nbsp; Laatste update: ${new Date().toLocaleDateString('nl-NL',{day:'2-digit',month:'long',year:'numeric'})}</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ INTRO -->
<div class="hlg-sectie">
<h2 id="hlg-intro">Introductie</h2>
<p><strong>InlineComp</strong> is een webapplicatie voor het beheren van inline skeelerwedstrijden. Het vervangt de handmatige Excel-workflow door een geïntegreerd systeem dat draait op een laptop in het lokale netwerk tijdens de wedstrijddag.</p>

<h3>Wat doet InlineComp?</h3>
<ul>
  <li>Deelnemers importeren vanuit de KNSB-inschrijvingssite</li>
  <li>Tijdschema opstellen met starttijden en programma-volgorde</li>
  <li>Startlijsten genereren en afdrukken (inclusief last-minute wijzigingen)</li>
  <li>Resultaten invoeren tijdens de wedstrijd</li>
  <li>Uitslagen en klassementen berekenen en afdrukken</li>
</ul>

<h3>Wedstrijddag workflow (overzicht)</h3>
<p>Een typische wedstrijddag verloopt in deze volgorde:</p>
<ol>
  <li><strong>Voorbereiding</strong> (thuis/vooraf): importeer deelnemers, stel het tijdschema in, genereer startlijsten.</li>
  <li><strong>Op locatie — voor aanvang:</strong> druk tekenlijsten en startlijsten af. Verwerk afmeldingen en last-minute aanmeldingen.</li>
  <li><strong>Tijdens de wedstrijd:</strong> voer resultaten in per rit via de Live-module. Genereer de volgende ronde wanneer alle heats van de huidige ronde gereden zijn.</li>
  <li><strong>Na de wedstrijd:</strong> bevestig de uitslag per afstand, pas eventueel punten aan, leg het klassement vast en druk de officiële uitslag af.</li>
</ol>
<div class="hlg-tip">&#128161; De modules in het menu (Importeer → Tijdschema → Startlijsten → Live → Uitslag) volgen precies deze workflow van links naar rechts.</div>

<h3>Begrippen en afkortingen</h3>
<table>
  <thead><tr><th>Term</th><th>Betekenis</th></tr></thead>
  <tbody>
    <tr><td><strong>KNSB</strong></td><td>Koninklijke Nederlandsche Schaatsenrijders Bond — de sportbond die inline skeelerwedstrijden organiseert in Nederland.</td></tr>
    <tr><td><strong>Categorie</strong></td><td>Leeftijds-/geslachtsgroep (bijv. "Dames Junioren A", afgekort "DJA"). Bepaalt in welke groep een rijder start.</td></tr>
    <tr><td><strong>Afstand / Onderdeel</strong></td><td>De te rijden afstand (bijv. 500m, Sprint, Tijdrit). Elk onderdeel heeft eigen heats en een eigen uitslag. Deze termen zijn inwisselbaar.</td></tr>
    <tr><td><strong>Heat / Rit</strong></td><td>Eén race met een groep rijders. Meerdere heats vormen samen een ronde. <em>Heat</em> wordt gebruikt bij de startindeling, <em>rit</em> bij het tijdschema.</td></tr>
    <tr><td><strong>Ronde</strong></td><td>Een fase in de wedstrijd: series (voorronde), kwartfinale, halve finale, A-finale, B-finales.</td></tr>
    <tr><td><strong>Transponder</strong></td><td>Elektronische tijdmeting-chip die aan de schoen van de rijder bevestigd is. Wordt uitgelezen door het MyLaps/Orbits systeem.</td></tr>
    <tr><td><strong>Startnummer (Snr)</strong></td><td>Het rugnummer van de rijder, zichtbaar op het wedstrijdpak. Wordt toegekend door de KNSB of de organisatie.</td></tr>
    <tr><td><strong>Relatienummer</strong></td><td>Het unieke KNSB-lidmaatschapsnummer van een rijder.</td></tr>
    <tr><td><strong>Loting</strong></td><td>De verdeling van rijders over heats. Kan willekeurig, op startnummer of op basis van een extern klassement.</td></tr>
    <tr><td><strong>Doorstroom</strong></td><td>De regel die bepaalt welke rijders doorstromen naar de volgende ronde (bijv. "top 8 op tijd" of "2 winnaars per heat + tijdsnelsten").</td></tr>
    <tr><td><strong>Slangenpatroon</strong></td><td>Verdeelmethode waarbij rijders zigzag over heats worden verdeeld: rijder 1→heat 1, 2→heat 2, 3→heat 3, 4→heat 3, 5→heat 2, 6→heat 1, enz. Dit zorgt voor gelijke sterkte per heat.</td></tr>
    <tr><td><strong>Full-Final systeem</strong></td><td>Wedstrijdformat waarbij alle rijders een finale rijden: de snelsten in de A-finale, de rest in B-finales (B1, B2, enz.). Niemand valt af.</td></tr>
    <tr><td><strong>Punten</strong></td><td>Positie-gebaseerd: 1e plaats = 1 punt, 2e = 2 punten, enz. <strong>Lager totaal = beter.</strong></td></tr>
    <tr><td><strong>Sanctie</strong></td><td>Straf voor een overtreding: FS (valse start/waarschuwing), DQ-SF, DQ-DF (diskwalificatie), DNS (niet gestart), DNF (niet gefinisht).</td></tr>
    <tr><td><strong>Tekenlijst</strong></td><td>Afdruk waarop rijders bij aankomst fysiek tekenen om hun aanwezigheid te bevestigen.</td></tr>
  </tbody>
</table>
</div>

<!-- ═══════════════════════════════════════════════════════════ 1 INLOGGEN -->
<div class="hlg-sectie">
<h2 id="hlg-inloggen">1. Inloggen</h2>
<p>InlineComp is beveiligd met een gebruikerssysteem. Je hebt een gebruikersnaam en wachtwoord nodig om toegang te krijgen. Deze worden aangemaakt door de beheerder (Owner of Admin) — er is geen zelfregistratie.</p>

<h3>Inloggen</h3>
<ol>
  <li>Open de webapplicatie in je browser (het adres krijg je van de beheerder).</li>
  <li>Voer je gebruikersnaam en wachtwoord in.</li>
  <li>Klik op <strong>Inloggen</strong>.</li>
</ol>
<p>Na het inloggen kom je op het hoofdscherm. Je naam en rol zijn zichtbaar rechts bovenin de navigatiebalk.</p>

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
<p>Klik op de pijlknop <strong>➤</strong> rechtsboven naast je naam om uit te loggen.</p>

<h3>Sessie verlopen</h3>
<p>Sessies verlopen automatisch na 24 uur. Als je sessie verloopt terwijl je aan het werk bent, verschijnt automatisch een login-venster. Na opnieuw inloggen kun je direct verder waar je was — er gaat geen werk verloren.</p>

<!-- MOCKUP: sessie verlopen modal -->
<div class="hlg-mock">
  <div style="background:rgba(0,0,0,.3);padding:20px;display:flex;justify-content:center">
    <div style="background:#fff;border-radius:10px;width:300px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.2)">
      <div style="background:#1a3a5c;color:#fff;padding:10px 16px;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:6px">
        &#128274; Sessie verlopen
      </div>
      <div style="padding:14px 16px">
        <p style="font-size:.76rem;color:#333;margin-bottom:10px">Je sessie is verlopen. Log opnieuw in om verder te gaan.</p>
        ${mockInput('Gebruikersnaam', 'geert')}
        ${mockInput('Wachtwoord', '••••••••')}
      </div>
      <div style="padding:8px 16px;border-top:1px solid #dde;background:#f4f6f8;text-align:right">
        <div style="display:inline-block;background:#e86c1b;color:#fff;border-radius:5px;padding:6px 16px;font-size:.78rem;font-weight:600">Inloggen</div>
      </div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Sessie-verlopen modal — verschijnt automatisch, gebruikersnaam is vooringevuld</p>

<div class="hlg-tip">&#128161; Alle bevestigingen en foutmeldingen in InlineComp verschijnen als nette modale vensters in de huisstijl — nooit als browser pop-ups.</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ 2 ROLLEN -->
<div class="hlg-sectie">
<h2 id="hlg-rollen">2. Rollen en rechten</h2>
<p>Elke gebruiker heeft één rol. De rol bepaalt welke modules je mag <em>bewerken</em>. Alle modules zijn voor iedereen <em>leesbaar</em>, behalve Gebruikersbeheer (alleen voor Owner/Admin). Een gebruiker kan maar één rol hebben.</p>
<p>De <strong>Owner</strong> is de hoofdbeheerder (wordt bij installatie aangemaakt). De <strong>Admin</strong> heeft dezelfde rechten, maar kan geen andere Admins of de Owner bewerken. Gebruik Admin voor medewerkers die ook volledige toegang nodig hebben.</p>

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
<p style="font-size:9pt;color:#666">* Admin kan geen andere Admins of de Owner bewerken.</p>
<p>Modules waarvoor je geen schrijfrechten hebt worden weergegeven met een blauw <em>"Lees-alleen"</em> banner bovenin. Knoppen en invoervelden zijn dan uitgeschakeld.</p>

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
        👁 Lees-alleen — je rol heeft geen schrijfrechten voor deze module.
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
<p>De module <strong>Importeer</strong> is het startpunt voor elke wedstrijd. Hier importeer je deelnemers vanuit de KNSB-inschrijvingssite (via een internetverbinding) en beheer je hun status.</p>

<h3>3.1 Wedstrijd selecteren</h3>
<ol>
  <li>Klik links in de lijst op een wedstrijd om deze te selecteren. Gebruik de datumfilters bovenaan om de lijst te verfijnen.</li>
  <li>Na selectie worden de deelnemers automatisch opgehaald vanuit de KNSB-API en vergeleken met de lokale database.</li>
</ol>
<div class="hlg-tip">&#128161; Geen internetverbinding? Eerder geïmporteerde deelnemers blijven beschikbaar in de lokale database. Alleen het ophalen van nieuwe KNSB-data vereist internet.</div>

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
<p>Onderaan elke categorie staat de knop <strong>+ Deelnemer toevoegen</strong>. Dit is ideaal voor last-minute aanmeldingen op de wedstrijddag. In het venster dat verschijnt:</p>
<ol>
  <li>Zoek op <em>relatienummer</em> of <em>startnummer + categorie</em> om gegevens automatisch in te vullen.</li>
  <li>Vul de velden in: <strong>Voornaam</strong> en <strong>Achternaam</strong> (apart), startnummer, categorie en geslacht zijn verplicht. De volledige naam wordt automatisch samengesteld.</li>
  <li><strong>Transponder:</strong> voer het transpondernummer in. Het systeem vergelijkt dit met bekende transponders (T1, T2, extras) van deze rijder:
    <ul>
      <li>Match gevonden → transponder wordt direct als actief ingesteld.</li>
      <li>Geen match → je wordt gevraagd te bevestigen of dit de juiste transponder is. Bij <em>Ja</em> wordt hij als extra opgeslagen; bij <em>Nee</em> kun je corrigeren.</li>
    </ul>
  </li>
  <li>Klik <strong>Toevoegen</strong>. De deelnemer verschijnt in de tabel met status <em>Bevestigd bij org.</em></li>
</ol>

<!-- MOCKUP: deelnemer toevoegen modal -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:6px 14px;font-size:.8rem;font-weight:700;display:flex;align-items:center;justify-content:space-between">
    <span>Deelnemer toevoegen</span>
    <span style="opacity:.6;font-size:.95rem;cursor:pointer">&times;</span>
  </div>
  <div style="padding:10px 14px">
    <div style="display:flex;gap:4px;margin-bottom:10px">
      <div style="background:#1a3a5c;color:#fff;padding:2px 9px;border-radius:12px;font-size:.7rem">Op relatienr.</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 9px;border-radius:12px;font-size:.7rem">Op startnr./cat.</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:8px">
      ${mockInput('Startnummer *', '86')}
      <div></div>
      ${mockInput('Voornaam *', 'Anna')}
      ${mockInput('Achternaam *', 'Bakker')}
      ${mockInput('Categorie *', 'DJA')}
      ${mockInput('Geslacht *', 'Vrouw')}
      ${mockInput('Nationaliteit', 'NED')}
      ${mockInput('Transponder', 'A1B2C3', true)}
    </div>
    <div style="display:flex;gap:6px;justify-content:flex-end">
      <div style="border:1px solid #ccc;border-radius:5px;padding:4px 10px;font-size:.77rem;color:#555">Annuleren</div>
      <div style="background:#e86c1b;color:#fff;border-radius:5px;padding:4px 10px;font-size:.77rem;font-weight:600">Toevoegen</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Modal voor handmatig toevoegen: voornaam en achternaam apart invoeren, transponder met oranje rand als aandachtspunt</p>

<div class="hlg-tip">&#128161; Als de ingevoerde categorie niet overeenkomt met de verwachte categorieën voor dit onderdeel, verschijnt een vergelijkbare waarschuwing. Klik nogmaals op <em>Toch toevoegen</em> om door te gaan.</div>

<h3>3.5 Transponders beheren</h3>
<p>In de kolom <em>Transponder</em> van de tabel kun je per deelnemer de actieve transponder selecteren via een dropdown. De dropdown toont:</p>
<ul>
  <li><strong>T1</strong> – officiële KNSB transponder slot 1</li>
  <li><strong>T2</strong> – officiële KNSB transponder slot 2</li>
  <li><strong>Extra</strong> – lokaal toegevoegde transponders (per wedstrijd)</li>
</ul>
<p>Via de <strong>+</strong> knop naast de dropdown kun je een nieuwe extra transponder toevoegen.</p>

<h3>3.6 Categorieën samenvoegen en splitsen</h3>
<p>Soms wil je meerdere categorieën samen laten rijden (bijv. Dames Kadetten + Heren Kadetten in één startgroep), of juist een grote categorie opsplitsen. Dit doe je in de Importeer-module:</p>
<ul>
  <li><strong>Samenvoegen:</strong> klik op het koppel-icoon naast een categorie en selecteer de categorieën die samen moeten rijden. Ze verschijnen daarna als één gecombineerde tab met een badge die het aantal samengevoegde groepen toont.</li>
  <li><strong>Splitsen:</strong> bij een categorie met meerdere subgroepen kun je deze opsplitsen zodat elke subgroep een eigen startlijst krijgt. De split verschijnt als aparte tab met een schaar-label.</li>
</ul>
<div class="hlg-tip">&#128161; Samenvoegen en splitsen kan ook na het genereren van startlijsten. De startlijst-configuratie werkt per groep.</div>

<h3>3.7 Importeren (opslaan)</h3>
<p>Als alle aanpassingen klaar zijn klik je op de oranje knop <strong>Importeer</strong>. Dit slaat alle deelnemers, statussen en transponders op in de lokale database. Na import zijn de gegevens beschikbaar voor de volgende modules.</p>
<p>Je kunt opnieuw importeren om wijzigingen van de KNSB-site op te halen. Bestaande lokale aanpassingen (statussen, transponders) blijven behouden.</p>
<div class="hlg-warn">&#9888; Bij gelijktijdige bewerking door meerdere gebruikers detecteert het systeem een conflict. Er verschijnt een melding met de keuze om te herladen.</div>

<h3>3.8 Afdrukken</h3>
<p>Na import zijn twee afdrukopties beschikbaar:</p>
<ul>
  <li><strong>Tekenlijst</strong> – per categorie een lijst met handtekeningvakjes. Rijders tekenen hier bij aankomst op de wedstrijddag om hun aanwezigheid te bevestigen.</li>
  <li><strong>Deelnemerslijst</strong> – overzicht van alle bevestigde deelnemers per categorie, met transponder- en afstandsinformatie. Bedoeld als intern document voor de jury.</li>
</ul>
<p>Beide afdrukken bevatten automatisch het organisatielogo (bovenaan) en sponsorlogos (onderaan). Configureer deze in de <em>Beheer</em>-module.</p>
</div>

<!-- ═══════════════════════════════════════════════════════════ 4 TIJDSCHEMA -->
<div class="hlg-sectie">
<h2 id="hlg-tijdschema">4. Tijdschema</h2>
<p>De module <strong>Tijdschema</strong> bepaalt de <em>structuur</em> van de wedstrijd: welk competitiesysteem, welke rondes per afstand, hoe lang elke heat duurt, en in welke volgorde alles gereden wordt. Het resultaat is een compleet programma met berekende starttijden.</p>
<div class="hlg-tip">&#128161; <strong>Tijdschema vs Startlijsten:</strong> het Tijdschema bepaalt <em>wat</em> er wanneer gereden wordt (het programma). De Startlijsten bepalen <em>wie</em> in welke heat zit (de indeling). Eerst het tijdschema opstellen, dan de startlijsten genereren.</div>

<h3>4.1 Tijdschema aanmaken</h3>
<p>Klik op <strong>Tijdschema aanmaken</strong>. Vereiste: er moet al een geïmporteerde wedstrijd zijn (zie module <em>Importeer</em>). Zonder import verschijnt een melding.</p>

<h3>4.2 Competitiesysteem kiezen</h3>
<p>Bovenaan staat de <strong>Competitiesysteem</strong>-balk. Er zijn drie opties:</p>
<table>
  <thead><tr><th>Systeem</th><th>Omschrijving</th><th>Typisch gebruik</th></tr></thead>
  <tbody>
    <tr>
      <td><strong>Full-Final</strong></td>
      <td>Iedereen rijdt series; indeling in A‑finale + B1‑, B2‑…Bn‑finales op basis van tijd. Geen uitval — iedereen start een finale.</td>
      <td>Regionale wedstrijden</td>
    </tr>
    <tr>
      <td><strong>Internationaal oud</strong></td>
      <td>Knock-out per ronde. Optioneel: kwartfinale en halve finale. B-finale voor verliezers halve finale. Runner-up voor uitvallers in de series.</td>
      <td>Grotere nationale wedstrijden (klassiek KNSB-format)</td>
    </tr>
    <tr>
      <td><strong>Internationaal nieuw</strong></td>
      <td>Knock-out per ronde. Optioneel: kwartfinale en halve finale. Geen B-finale; wel optionele runner-up. Modern KNSB-format.</td>
      <td>NK, kampioenschappen</td>
    </tr>
  </tbody>
</table>
<p>Kies het systeem, klik <strong>Opslaan</strong>. Onder de selector verschijnt een toelichting met de rondevolgorde van het gekozen systeem.</p>
<div class="hlg-warn">&#9888; Wisselen van systeem verwijdert alle afstandsinstellingen, programma-blokken en het gegenereerde programma. Er verschijnt een bevestigingsvraag voordat dit definitief wordt uitgevoerd.</div>

<!-- MOCKUP: systeem kiezen -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Tijdschema – NK Inline 2025</div>
  <div style="padding:8px 12px">
    <div style="background:#f5f7fa;border:1px solid #dde;border-radius:6px;padding:8px 12px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:.74rem;color:#555;font-weight:600">Competitiesysteem</span>
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 10px;background:#fff;font-size:.77rem;color:#1a3a5c">Internationaal nieuw ▾</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:4px 8px;font-size:.73rem;color:#aaa;background:#f8f8f8">Opslaan</div>
        <span style="background:#d4edda;color:#155724;border-radius:10px;padding:2px 9px;font-size:.69rem;font-weight:600">✔ Actief: Internationaal nieuw</span>
      </div>
      <div style="margin-top:8px;font-size:.72rem;color:#444;border-left:3px solid #1a3a5c;padding-left:8px;line-height:1.5">
        <strong>Modern knock-outsysteem</strong> — uitval per ronde, geen B-finales maar wel een runner-up optie.<br>
        <span style="color:#777">1. Series &nbsp;→&nbsp; 2. Kwartfinale (opt.) &nbsp;→&nbsp; 3. Halve finale (opt.) &nbsp;→&nbsp; 4. A-finale &nbsp;·&nbsp; 5. Runner-up (opt.)</span><br>
        <span style="color:#888;font-style:italic">&#128161; KNSB-format voor de landelijke wedstrijden (met runner-up) en nationale kampioenschappen (zonder runner-up).</span>
      </div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Competitiesysteem-balk: dropdown om systeem te kiezen, Opslaan-knop, actieve badge en automatische uitleg eronder</p>

<h3>4.3 Afstandsinstellingen</h3>
<p>Onder het competitiesysteem staat de sectie <strong>Afstandsinstellingen</strong>. Per afstand (onderdeel) verschijnt een kaart met een korte samenvatting van de rondevolgorde. Klik op <strong>✏ Bewerken</strong> om de configuratie uit te klappen.</p>

<p><strong>Full-Final instellingen (gedeeld, voor alle categorieën binnen de afstand):</strong></p>
<ul>
  <li><strong>A-finale:</strong> max. rijders per A-finale</li>
  <li><strong>B-finales:</strong> max. rijders per B-finale (Bn-finales voor de rest)</li>
  <li>Checkbox: <em>"Laatste B-finale (Bn) is de grootste"</em></li>
</ul>
<p><strong>Internationaal oud/nieuw instellingen:</strong></p>
<ul>
  <li><strong>Runner-up:</strong> aan/uit, max. per heat, min. per heat</li>
  <li><strong>Per categorie:</strong> welke rondes er zijn (series, kwartfinale, halve finale) en de duur per heat (in m:ss)</li>
</ul>
<p>Klik <strong>💾 Opslaan</strong> om de instellingen op te slaan. De samenvatting op de kaart wordt direct bijgewerkt (bijv. <em>"Series → Halve finale → Finale"</em>).</p>

<!-- MOCKUP: afstandskaarten -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Tijdschema – Afstandsinstellingen</div>
  <div style="padding:8px 12px;display:flex;flex-direction:column;gap:6px">
    <!-- Gesloten kaart -->
    <div style="border:1px solid #dde;border-radius:6px;overflow:hidden">
      <div style="display:flex;align-items:center;gap:10px;padding:6px 10px;background:#f8f9fb">
        <span style="font-weight:700;font-size:.8rem;color:#1a3a5c;min-width:36px">500m</span>
        <span style="font-size:.71rem;color:#888">Junioren A &nbsp;·&nbsp; Junioren B</span>
        <span style="font-size:.71rem;color:#333;flex:1;font-style:italic">Series → Halve finale → A-finale</span>
        <div style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;font-size:.7rem;color:#444;background:#fff;white-space:nowrap">✏ Bewerken</div>
      </div>
    </div>
    <!-- Open kaart (configuratiepaneel) -->
    <div style="border:2px solid #1a3a5c;border-radius:6px;overflow:hidden">
      <div style="display:flex;align-items:center;gap:10px;padding:6px 10px;background:#eef2f8">
        <span style="font-weight:700;font-size:.8rem;color:#1a3a5c;min-width:36px">1000m</span>
        <span style="font-size:.71rem;color:#888">Senioren</span>
        <span style="font-size:.71rem;color:#333;flex:1;font-style:italic">Series · Runner-up → A-finale</span>
        <div style="border:1px solid #1a3a5c;border-radius:4px;padding:2px 8px;font-size:.7rem;color:#1a3a5c;background:#fff;font-weight:600;white-space:nowrap">▲ Sluiten</div>
      </div>
      <div style="padding:8px 12px;background:#fff;border-top:1px solid #dde">
        <div style="font-size:.7rem;font-weight:700;color:#666;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px">Gedeeld – 1000m</div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:.72rem">
          <span style="width:88px;color:#555;flex-shrink:0">Runner-up</span>
          <span style="display:flex;align-items:center;gap:4px"><input type="checkbox" checked disabled> Niet-gekwalificeerden rijden runner-up</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:.72rem">
          <span style="width:88px;color:#555;flex-shrink:0">Max/heat</span>
          <div style="border:1px solid #ccc;border-radius:3px;padding:2px 6px;background:#fff;font-size:.74rem;width:34px;text-align:center">6</div>
          <span style="color:#888">rijders per runner-up heat</span>
        </div>
        <div style="margin-top:8px;font-size:.7rem;font-weight:700;color:#666;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px">Per categorie</div>
        <table style="width:100%;border-collapse:collapse;font-size:.69rem">
          <thead><tr style="background:#f0f4f8">
            <th style="padding:2px 5px;text-align:left;border-bottom:1px solid #dde;font-weight:600">Categorie</th>
            <th style="padding:2px 5px;border-bottom:1px solid #dde;font-weight:600;text-align:center">Series</th>
            <th style="padding:2px 5px;border-bottom:1px solid #dde;font-weight:600;text-align:center">Kwart-finale</th>
            <th style="padding:2px 5px;border-bottom:1px solid #dde;font-weight:600;text-align:center">Halve finale</th>
            <th style="padding:2px 5px;border-bottom:1px solid #dde;font-weight:600">Duur/heat</th>
          </tr></thead>
          <tbody>
            <tr><td style="padding:2px 5px">Senioren heren</td>
                <td style="padding:2px 5px;text-align:center">✔</td>
                <td style="padding:2px 5px;text-align:center;color:#bbb">—</td>
                <td style="padding:2px 5px;text-align:center">✔</td>
                <td style="padding:2px 5px"><div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff;width:38px">1:30</div></td>
            </tr>
            <tr style="background:#f8f9fb"><td style="padding:2px 5px">Senioren dames</td>
                <td style="padding:2px 5px;text-align:center">✔</td>
                <td style="padding:2px 5px;text-align:center;color:#bbb">—</td>
                <td style="padding:2px 5px;text-align:center;color:#bbb">—</td>
                <td style="padding:2px 5px"><div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff;width:38px">1:30</div></td>
            </tr>
          </tbody>
        </table>
        <div style="display:flex;justify-content:flex-end;margin-top:8px">
          <div style="background:#e86c1b;color:#fff;border-radius:4px;padding:4px 10px;font-size:.72rem;font-weight:600">💾 Opslaan</div>
        </div>
      </div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Afstandskaarten: 500m is gesloten (samenvatting zichtbaar), 1000m is open. Per categorie selecteer je rondes en duur per heat.</p>

<h3>4.4 Programma-volgorde (blokken)</h3>
<p>Zodra de afstandsinstellingen zijn opgeslagen, verschijnt de sectie <strong>Programma-volgorde</strong>. De ronde-blokken (Series, Halve finale, Finale, enz.) worden <em>automatisch</em> aangemaakt per afstand en categorie. Je voegt daar extra blokken aan toe via de knoppen onderaan:</p>
<ul>
  <li><strong>+ Pauze toevoegen</strong> – vrije pauze, duur instelbaar in minuten</li>
  <li><strong>+ Inrijden toevoegen</strong> – inrijdblok, duur instelbaar</li>
  <li><strong>+ Ceremonie toevoegen</strong> – huldigingsblok, duur instelbaar</li>
  <li><strong>+ Wedstrijd start</strong> – officieel startmoment (max. één per schema). Ronde-blokken mogen <em>niet</em> vóór de wedstrijdstart worden geplaatst.</li>
</ul>
<p>De volgorde past je aan via de <strong>↑ ↓ pijlknoppen</strong> of via <strong>drag-and-drop</strong>. Elk blok heeft een instelbare duur. Klik <strong>💾 Volgorde opslaan</strong> om de volgorde te bewaren.</p>

<!-- MOCKUP: programma-volgorde -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Tijdschema – Programma-volgorde</div>
  <div style="padding:8px 12px">
    <div style="font-size:.7rem;color:#888;margin-bottom:7px;font-style:italic">Gebruik ↑↓ of sleep blokken om de volgorde aan te passen. Ronde-blokken kunnen niet vóór de wedstrijdstart geplaatst worden.</div>
    <div style="display:flex;flex-direction:column;gap:4px">
      <!-- Inrijden -->
      <div style="display:flex;align-items:center;gap:6px;background:#e0ecff;border:1px solid #b3cfef;border-radius:5px;padding:5px 8px">
        <span style="background:#2E75B6;color:#fff;border-radius:3px;padding:1px 7px;font-size:.67rem;font-weight:700;white-space:nowrap">── INRIJDEN ──</span>
        <span style="font-size:.72rem;color:#1a3a5c;flex:1">Alle categorieën</span>
        <span style="display:flex;align-items:center;gap:3px;font-size:.68rem;color:#555">
          <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff">20</div> min &nbsp;↑ ↓
          <span style="color:#c00;border:1px solid #fcc;border-radius:3px;padding:1px 5px;background:#fff5f5;margin-left:3px">🗑</span>
        </span>
      </div>
      <!-- Wedstrijdstart -->
      <div style="display:flex;align-items:center;gap:6px;background:#fff3cd;border:1px solid #f59e0b;border-radius:5px;padding:5px 8px">
        <span style="background:#e86c1b;color:#fff;border-radius:3px;padding:1px 7px;font-size:.67rem;font-weight:700;white-space:nowrap">── WEDSTRIJD START ──</span>
        <span style="font-size:.72rem;color:#7c4000;flex:1">09:30</span>
        <span style="display:flex;align-items:center;gap:3px;font-size:.68rem;color:#555">↑ ↓
          <span style="color:#c00;border:1px solid #fcc;border-radius:3px;padding:1px 5px;background:#fff5f5;margin-left:3px">🗑</span>
        </span>
      </div>
      <!-- Series ronde-blok -->
      <div style="display:flex;align-items:center;gap:6px;background:#cce5ff;border:1px solid #86c1f9;border-radius:5px;padding:5px 8px">
        <span style="background:#0d6efd;color:#fff;border-radius:3px;padding:1px 7px;font-size:.67rem;font-weight:700">Series</span>
        <span style="font-size:.72rem;color:#004085;flex:1">500m · Junioren A</span>
        <span style="display:flex;align-items:center;gap:3px;font-size:.68rem;color:#555">
          Duur/heat: <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff">1:30</div> &nbsp;↑ ↓
          <span style="color:#c00;border:1px solid #fcc;border-radius:3px;padding:1px 5px;background:#fff5f5;margin-left:3px">🗑</span>
        </span>
      </div>
      <!-- Series ronde-blok 2 -->
      <div style="display:flex;align-items:center;gap:6px;background:#cce5ff;border:1px solid #86c1f9;border-radius:5px;padding:5px 8px">
        <span style="background:#0d6efd;color:#fff;border-radius:3px;padding:1px 7px;font-size:.67rem;font-weight:700">Series</span>
        <span style="font-size:.72rem;color:#004085;flex:1">500m · Junioren B</span>
        <span style="display:flex;align-items:center;gap:3px;font-size:.68rem;color:#555">
          Duur/heat: <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff">1:30</div> &nbsp;↑ ↓
          <span style="color:#c00;border:1px solid #fcc;border-radius:3px;padding:1px 5px;background:#fff5f5;margin-left:3px">🗑</span>
        </span>
      </div>
      <!-- Pauze -->
      <div style="display:flex;align-items:center;gap:6px;background:#e9ecef;border:1px solid #ced4da;border-radius:5px;padding:5px 8px">
        <span style="background:#6c757d;color:#fff;border-radius:3px;padding:1px 7px;font-size:.67rem;font-weight:700;white-space:nowrap">── PAUZE ──</span>
        <span style="flex:1"></span>
        <span style="display:flex;align-items:center;gap:3px;font-size:.68rem;color:#555">
          <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff">15</div> min &nbsp;↑ ↓
          <span style="color:#c00;border:1px solid #fcc;border-radius:3px;padding:1px 5px;background:#fff5f5;margin-left:3px">🗑</span>
        </span>
      </div>
      <!-- Halve finale -->
      <div style="display:flex;align-items:center;gap:6px;background:#ffe5cc;border:1px solid #fd7e14;border-radius:5px;padding:5px 8px">
        <span style="background:#fd7e14;color:#fff;border-radius:3px;padding:1px 7px;font-size:.67rem;font-weight:700;white-space:nowrap">Halve finale</span>
        <span style="font-size:.72rem;color:#7c4000;flex:1">500m · Junioren A</span>
        <span style="display:flex;align-items:center;gap:3px;font-size:.68rem;color:#555">
          Duur/heat: <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff">2:00</div> &nbsp;↑ ↓
          <span style="color:#c00;border:1px solid #fcc;border-radius:3px;padding:1px 5px;background:#fff5f5;margin-left:3px">🗑</span>
        </span>
      </div>
      <!-- A-finale -->
      <div style="display:flex;align-items:center;gap:6px;background:#d1e7dd;border:1px solid #a3cfbb;border-radius:5px;padding:5px 8px">
        <span style="background:#198754;color:#fff;border-radius:3px;padding:1px 7px;font-size:.67rem;font-weight:700">A-finale</span>
        <span style="font-size:.72rem;color:#155724;flex:1">500m · Junioren A</span>
        <span style="display:flex;align-items:center;gap:3px;font-size:.68rem;color:#555">
          Duur/heat: <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff">2:30</div> &nbsp;↑ ↓
          <span style="color:#c00;border:1px solid #fcc;border-radius:3px;padding:1px 5px;background:#fff5f5;margin-left:3px">🗑</span>
        </span>
      </div>
    </div>
    <!-- Actie-knoppen -->
    <div style="display:flex;gap:5px;margin-top:9px;flex-wrap:wrap;align-items:center">
      <div style="border:1px solid #ccc;border-radius:4px;padding:3px 8px;font-size:.69rem;color:#555;background:#fff">+ Pauze toevoegen</div>
      <div style="border:1px solid #ccc;border-radius:4px;padding:3px 8px;font-size:.69rem;color:#555;background:#fff">+ Inrijden toevoegen</div>
      <div style="border:1px solid #ccc;border-radius:4px;padding:3px 8px;font-size:.69rem;color:#555;background:#fff">+ Ceremonie toevoegen</div>
      <div style="border:1px solid #aaa;border-radius:4px;padding:3px 8px;font-size:.69rem;color:#aaa;background:#f5f5f5">+ Wedstrijd start (al aanwezig)</div>
      <div style="flex:1"></div>
      <div style="border:1px solid #ccc;border-radius:4px;padding:3px 8px;font-size:.69rem;color:#444;background:#fff">💾 Volgorde opslaan</div>
      <div style="background:#e86c1b;color:#fff;border-radius:4px;padding:3px 9px;font-size:.69rem;font-weight:600">▶ Genereer programma</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Programma-volgorde: ronde-blokken automatisch aangemaakt (blauw=series, oranje=halve finale, groen=A-finale); pauze en inrijden handmatig toegevoegd. De duur per heat is per blok instelbaar.</p>

<h3>4.5 Programma genereren en publiceren</h3>
<p>Klik op <strong>▶ Genereer programma</strong>. Het systeem berekent voor elke rit een starttijd op basis van de blokken-volgorde en duraties. Het resultaat verschijnt als <em>Gegenereerd programma</em> met alle ritten op tijdstip. Het tijdstip van de laatste generatie wordt getoond; bij gewijzigde afstandsinstellingen verschijnt de waarschuwing <em>"mogelijk verouderd"</em>.</p>
<p>Via de knop <strong>&#128196; Publiceer schema</strong> (rechts in de titel van het gegenereerd programma) stel je het schema beschikbaar voor de <em>Live verwerking</em>-module.</p>
<div class="hlg-tip">&#128161; Het tijdschema wordt elke 30 seconden automatisch bijgewerkt als andere gebruikers wijzigingen opslaan. Bij gelijktijdig opslaan door meerdere gebruikers verschijnt een conflictmelding met de knop <em>Herlaad</em>.</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ 5 STARTLIJSTEN -->
<div class="hlg-sectie">
<h2 id="hlg-startlijsten">5. Startlijsten</h2>
<p>De module <strong>Startlijsten</strong> verdeelt de geïmporteerde deelnemers over heats per afstand, configureerbaar in 1 t/m 4 rondes met instelbare doorstroom.</p>

<h3>5.1 Categorietabs en afstandstabs</h3>
<p>Bovenaan de pagina staan de <strong>categorietabs</strong> (één per rijdersgroep). Samengevoegde groepen worden aangegeven met een badge (bijv. <em>"Junioren A + B ²"</em>); gesplitste groepen met een schaar-label. Onder de categorietabs staan de <strong>afstandstabs</strong> (500m, 1000m, enz.) voor de geselecteerde categorie.</p>

<h3>5.2 Rondes configureren</h3>
<p>Per afstand stel je het aantal rondes in door op de nummers <strong>1 · 2 · 3 · 4</strong> te klikken. Per ronde configureer je:</p>
<ul>
  <li><strong>Naam</strong> – vrij in te vullen (standaard: "Heats", "Halve finales", "Finale")</li>
  <li><strong>Max. per heat</strong> – maximum aantal rijders per heat in deze ronde</li>
  <li><strong>Loting-methode</strong> (alleen ronde 1): <em>Willekeurig</em>, <em>Op startnummer</em>, of <em>Op klassement</em> (kies dan ook het klassement en de sectie)</li>
  <li><strong>Doorstroom</strong> (voor alle rondes behalve de laatste):
    <ul>
      <li><em>X tijdsnelsten</em> – het opgegeven aantal snelste rijders over alle heats stroomt door</li>
      <li><em>X winnaars per heat + totaal doorstromers</em> – per heat de X beste, aangevuld met tijdsnelsten tot het totaal</li>
    </ul>
  </li>
</ul>

<!-- MOCKUP: startlijsten configuratie -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Startlijsten</div>
  <div style="padding:8px 12px">
    <!-- Categorie tabs -->
    <div style="display:flex;gap:4px;margin-bottom:5px;flex-wrap:wrap">
      <div style="background:#e86c1b;color:#fff;padding:2px 10px;border-radius:12px;font-size:.69rem;font-weight:600">Junioren A (12)</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 10px;border-radius:12px;font-size:.69rem">Junioren B + C <span style="background:#888;color:#fff;border-radius:8px;padding:0 5px;font-size:.63rem;font-weight:700">2</span></div>
      <div style="background:#f0f0f0;color:#666;padding:2px 10px;border-radius:12px;font-size:.69rem">Senioren (8)</div>
    </div>
    <!-- Afstand tabs -->
    <div style="display:flex;gap:4px;margin-bottom:9px">
      <div style="background:#1a3a5c;color:#fff;padding:2px 9px;border-radius:8px;font-size:.67rem">500m</div>
      <div style="background:#f0f0f0;color:#666;padding:2px 9px;border-radius:8px;font-size:.67rem">1000m</div>
    </div>
    <!-- Rondes kiezer -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px">
      <span style="font-size:.72rem;color:#555;font-weight:600">Aantal rondes:</span>
      <div style="display:flex;gap:3px">
        <div style="border:1px solid #ccc;border-radius:4px;padding:2px 9px;font-size:.72rem;background:#f5f5f5;color:#888">1</div>
        <div style="border:2px solid #e86c1b;border-radius:4px;padding:2px 9px;font-size:.72rem;background:#fff3ec;color:#e86c1b;font-weight:700">2</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:2px 9px;font-size:.72rem;background:#f5f5f5;color:#888">3</div>
        <div style="border:1px solid #ccc;border-radius:4px;padding:2px 9px;font-size:.72rem;background:#f5f5f5;color:#888">4</div>
      </div>
    </div>
    <!-- Ronde 1 config -->
    <div style="border:1px solid #dde;border-radius:6px;padding:7px 10px;margin-bottom:7px;background:#f8f9fb">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap">
        <span style="font-size:.7rem;font-weight:700;color:#1a3a5c;min-width:54px">Ronde 1</span>
        <div style="border:1px solid #ccc;border-radius:3px;padding:2px 7px;background:#fff;font-size:.72rem;width:80px">Heats</div>
        <span style="font-size:.7rem;color:#555">Max/heat:</span>
        <div style="border:1px solid #ccc;border-radius:3px;padding:2px 6px;background:#fff;font-size:.72rem;width:32px;text-align:center">6</div>
      </div>
      <div style="font-size:.7rem;color:#555;margin-bottom:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <strong>Loting:</strong>
        <label style="display:flex;align-items:center;gap:3px"><input type="radio" checked disabled> Willekeurig</label>
        <label style="display:flex;align-items:center;gap:3px"><input type="radio" disabled> Op startnummer</label>
        <label style="display:flex;align-items:center;gap:3px"><input type="radio" disabled> Op klassement</label>
      </div>
      <!-- Doorstroom -->
      <div style="background:#eef2f8;border-radius:4px;padding:5px 8px;font-size:.7rem;margin-top:3px">
        <strong style="color:#1a3a5c">Doorstroom naar Ronde 2:</strong>
        <div style="margin-top:4px;display:flex;flex-direction:column;gap:3px">
          <label style="display:flex;align-items:center;gap:5px"><input type="radio" disabled> X tijdsnelsten &nbsp; Aantal: <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff;width:28px">8</div></label>
          <label style="display:flex;align-items:center;gap:5px"><input type="radio" checked disabled> X winnaars/heat &nbsp; Winnaars/heat: <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff;width:24px">2</div> &nbsp; Totaal: <div style="border:1px solid #ccc;border-radius:3px;padding:1px 5px;background:#fff;width:28px">6</div></label>
        </div>
      </div>
    </div>
    <!-- Ronde 2 config (finale) -->
    <div style="border:1px solid #dde;border-radius:6px;padding:7px 10px;margin-bottom:8px;background:#f8f9fb">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-size:.7rem;font-weight:700;color:#1a3a5c;min-width:54px">Ronde 2</span>
        <div style="border:1px solid #ccc;border-radius:3px;padding:2px 7px;background:#fff;font-size:.72rem;width:80px">Finale</div>
        <span style="font-size:.7rem;color:#555">Max/heat:</span>
        <div style="border:1px solid #ccc;border-radius:3px;padding:2px 6px;background:#fff;font-size:.72rem;width:32px;text-align:center">6</div>
        <span style="font-size:.69rem;color:#aaa;font-style:italic">(Geen doorstroom — laatste ronde)</span>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end">
      <div style="background:#e86c1b;color:#fff;border-radius:4px;padding:5px 12px;font-size:.73rem;font-weight:600">&#9654; Genereer startlijst</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Startlijsten: categorie- en afstandstabs, rondes-kiezer (hier 2 gekozen), ronde-configuratie met loting en doorstroomregel, en Genereer-knop</p>

<h3>5.3 Startlijst genereren</h3>
<p>Klik op <strong>&#9654; Genereer</strong>. Het systeem verdeelt de bevestigde deelnemers over de heats via een <em>slangenpatroon</em> (gelijke verdeling). Voor vervolgrondes verschijnen placeholders (<em>"Winnaar Heat 1"</em>, enz.) die na de wedstrijd worden ingevuld.</p>
<div class="hlg-tip">&#128161; Bij loting op klassement wordt het geselecteerde KNSB-klassement gebruikt. De startlijst wordt gesorteerd op klassementspositie voordat het slangenpatroon wordt toegepast.</div>

<h3>5.4 Deelnemerspaneel (rijders toevoegen/verwijderen)</h3>
<p>Na het genereren verschijnt links het <strong>deelnemerspaneel</strong>. Dit toont alle geregistreerde deelnemers met hun heat-toewijzing per ronde. Dit is essentieel voor last-minute wijzigingen:</p>
<ul>
  <li><strong>Rijder toevoegen aan heat:</strong> typ het heat-nummer in het invoerveld naast de naam → druk Enter. De rijder verschijnt in de startlijst.</li>
  <li><strong>Rijder verwijderen uit heat:</strong> maak het heat-nummer leeg → druk Enter. De rijder verdwijnt en de startposities schuiven automatisch op.</li>
  <li><strong>Oranje markering:</strong> rijders zonder heat-nummer worden gemarkeerd met een oranje achtergrond als waarschuwing.</li>
</ul>

<!-- MOCKUP: deelnemerspaneel -->
<div class="hlg-mock">
  ${mockHeader('Startlijsten – Deelnemerspaneel')}
  <div style="display:flex;min-height:130px">
    <div style="width:200px;flex-shrink:0;border-right:1px solid #dde;padding:6px 8px;background:#f8f9fb">
      <div style="font-size:.7rem;font-weight:700;color:#1a3a5c;margin-bottom:5px">Alle deelnemers</div>
      <table style="width:100%;border-collapse:collapse;font-size:.68rem">
        <thead><tr style="background:#f0f4f8">
          <th style="padding:2px 4px;font-weight:600">Snr</th><th style="padding:2px 4px;font-weight:600">Naam</th><th style="padding:2px 4px;font-weight:600">S</th>
        </tr></thead>
        <tbody>
          <tr><td style="padding:2px 4px;color:#1a3a5c;font-weight:600">10</td><td style="padding:2px 4px">Tycho Hanemaaijer</td>
              <td style="padding:2px 4px"><div style="border:1px solid #ccc;border-radius:3px;padding:1px 4px;background:#fff;width:22px;text-align:center;font-size:.7rem">1</div></td></tr>
          <tr style="background:#fff3cd"><td style="padding:2px 4px;color:#1a3a5c;font-weight:600">24</td><td style="padding:2px 4px">Izaak Stenneke</td>
              <td style="padding:2px 4px"><div style="border:1px solid #e0a800;border-radius:3px;padding:1px 4px;background:#fff;width:22px;text-align:center;font-size:.7rem"></div></td></tr>
          <tr><td style="padding:2px 4px;color:#1a3a5c;font-weight:600">86</td><td style="padding:2px 4px">Daan Borst</td>
              <td style="padding:2px 4px"><div style="border:1px solid #ccc;border-radius:3px;padding:1px 4px;background:#fff;width:22px;text-align:center;font-size:.7rem">1</div></td></tr>
        </tbody>
      </table>
    </div>
    <div style="flex:1;padding:6px 8px">
      <div style="font-size:.72rem;color:#1a3a5c;font-weight:700;margin-bottom:4px">Heat 1 — A-Finale Sprint</div>
      <div style="font-size:.68rem;color:#666">1. Tycho Hanemaaijer (10)<br>2. Daan Borst (86)</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Deelnemerspaneel links: Izaak (oranje) is nog niet ingedeeld. Typ "1" en druk Enter om hem toe te voegen aan heat 1.</p>

<h3>5.5 Alleen A-finale (geen series)</h3>
<p>Bij categorieën die direct een A-finale rijden (zonder voorrondes), werkt het systeem identiek: de loting wordt gegenereerd en het deelnemerspaneel is beschikbaar voor last-minute wijzigingen. Dit is ideaal voor <strong>regionale wedstrijden</strong> waar flexibiliteit gewenst is.</p>

<h3>5.6 Startlijst afdrukken</h3>
<p>Gebruik de afdrukoptions bovenaan (categorie → afstand → ronde) om de startlijst af te drukken. De printout is geoptimaliseerd voor zwart-wit laserprinters: heat-titels zijn goed leesbaar en deelnemersnamen zijn voldoende groot.</p>

<h3>5.7 Categorieën samenvoegen en splitsen</h3>
<p>Samenvoegen en splitsen worden ingesteld in de <strong>Importeer</strong>-module. Na samenvoegen verschijnen de categorieën als één gecombineerde tab (met badge). Na splitsen verschijnt elke subgroep als aparte tab met schaar-label.</p>

<h3>5.8 Loting wissen</h3>
<p>De knop <strong>&#128465; Wis loting</strong> verwijdert alle heats voor de geselecteerde afstand. Na het wissen kun je opnieuw loten met andere instellingen. Er verschijnt een bevestigingsvraag voordat de actie wordt uitgevoerd.</p>
</div>

<!-- ═══════════════════════════════════════════════════════════ 6 LIVE -->
<div class="hlg-sectie">
<h2 id="hlg-live">6. Live verwerking</h2>
<p>De module <strong>Live</strong> wordt gebruikt tijdens de wedstrijd om resultaten per rit in te voeren. Ritten worden weergegeven in een carrousel die overeenkomt met het gepubliceerde tijdschema.</p>

<h3>6.1 Rit selecteren</h3>
<p>De carrousel toont alle ritten uit het tijdschema in volgorde. Navigeer met de pijlknoppen of klik direct op een rit. De actieve rit toont de deelnemers met hun startnummers en transponders.</p>

<h3>6.2 Resultaten invoeren</h3>
<p>Per deelnemer kun je invoeren:</p>
<ul>
  <li><strong>Tijd:</strong> in formaat mm:ss.hh (bijv. 1:23.45) — wordt omgerekend naar milliseconden.</li>
  <li><strong>Rondes:</strong> optioneel, voor afstanden waarbij rondes geteld worden.</li>
  <li><strong>Sanctie:</strong> selecteer een sanctiecode als de rijder een overtreding heeft begaan.</li>
</ul>

<h3>6.3 Sanctiecodes</h3>
<p>De volgende sanctiecodes zijn beschikbaar:</p>
<table>
  <thead><tr><th>Code</th><th>Betekenis</th><th>Effect op uitslag</th></tr></thead>
  <tbody>
    <tr><td><strong>FS</strong></td><td>False Start (valse start)</td><td>Waarschuwing — rijder behoudt positie en tijd. Wordt vermeld op de uitslag.</td></tr>
    <tr><td><strong>DQ-SF</strong></td><td>Diskwalificatie Start Finish</td><td>Geen positie, wel tijd — rijder krijgt standaard-punten (laatste positie).</td></tr>
    <tr><td><strong>DQ-DF</strong></td><td>Diskwalificatie Definitief</td><td>Geen positie, geen tijd — rijder wordt uitgesloten van de uitslag.</td></tr>
    <tr><td><strong>DNS</strong></td><td>Did Not Start</td><td>Rijder is niet gestart — wordt uitgesloten.</td></tr>
    <tr><td><strong>DNF</strong></td><td>Did Not Finish</td><td>Rijder is niet gefinished — krijgt standaard-punten.</td></tr>
    <tr><td><strong>DC</strong></td><td>Diskwalificatie (algemeen)</td><td>Geen positie — wordt uitgesloten.</td></tr>
  </tbody>
</table>

<!-- MOCKUP: live verwerking -->
<div class="hlg-mock">
  <div style="background:#1a3a5c;color:#fff;padding:5px 12px;font-size:.78rem;font-weight:700">Live verwerking</div>
  <div style="padding:8px 12px">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px">
      <div style="border:1px solid #ccc;border-radius:4px;padding:3px 8px;font-size:.73rem;color:#888">◀</div>
      <div style="flex:1;text-align:center;font-size:.82rem;font-weight:700;color:#1a3a5c">Rit 5 — A-Finale Sprint · Pup 4, 3 &amp; 2</div>
      <div style="border:1px solid #ccc;border-radius:4px;padding:3px 8px;font-size:.73rem;color:#888">▶</div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.71rem">
      <thead><tr style="background:#f0f4f8">
        <th style="padding:3px 5px;border-bottom:1px solid #dde;font-weight:600">Snr</th>
        <th style="padding:3px 5px;border-bottom:1px solid #dde;font-weight:600">Naam</th>
        <th style="padding:3px 5px;border-bottom:1px solid #dde;font-weight:600">Tijd</th>
        <th style="padding:3px 5px;border-bottom:1px solid #dde;font-weight:600">Sanctie</th>
      </tr></thead>
      <tbody>
        <tr><td style="padding:3px 5px;font-weight:600;color:#1a3a5c">10</td><td style="padding:3px 5px">Tycho Hanemaaijer</td>
            <td style="padding:3px 5px"><div style="border:1px solid #ccc;border-radius:3px;padding:2px 6px;background:#fff;font-family:monospace;font-size:.74rem">35.40</div></td>
            <td style="padding:3px 5px;color:#aaa">—</td></tr>
        <tr style="background:#f8f9fb"><td style="padding:3px 5px;font-weight:600;color:#1a3a5c">86</td><td style="padding:3px 5px">Daan Borst</td>
            <td style="padding:3px 5px"><div style="border:1px solid #ccc;border-radius:3px;padding:2px 6px;background:#fff;font-family:monospace;font-size:.74rem">53.40</div></td>
            <td style="padding:3px 5px">${mockBadge('FS', 'oranje')}</td></tr>
        <tr><td style="padding:3px 5px;font-weight:600;color:#1a3a5c">605</td><td style="padding:3px 5px">Evie Vijverberg</td>
            <td style="padding:3px 5px"><div style="border:1px solid #ccc;border-radius:3px;padding:2px 6px;background:#fff;font-family:monospace;font-size:.74rem">37.80</div></td>
            <td style="padding:3px 5px;color:#aaa">—</td></tr>
      </tbody>
    </table>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <div style="background:#e86c1b;color:#fff;border-radius:4px;padding:5px 12px;font-size:.73rem;font-weight:600">&#128190; Opslaan</div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Live verwerking: tijden invoeren per rijder, sanctie (FS) bij Daan Borst. Alle sancties worden doorgetrokken naar de uitslag.</p>

<h3>6.3a Resultaten opslaan en corrigeren</h3>
<p>Klik op <strong>&#128190; Opslaan</strong> om de ingevoerde tijden en sancties op te slaan. Je kunt resultaten altijd opnieuw opslaan om correcties door te voeren — eerdere waarden worden overschreven.</p>
<div class="hlg-tip">&#128161; Resultaten worden <strong>niet automatisch opgeslagen</strong>. Vergeet niet op Opslaan te klikken voordat je naar een andere rit navigeert!</div>

<h3>6.4 Volgende ronde genereren</h3>
<p>Als alle heats van de huidige ronde resultaten hebben, verschijnt de knop om de volgende ronde te genereren. Het systeem:</p>
<ol>
  <li>Bepaalt welke rijders doorstromen op basis van de doorstroomregels (ingesteld in het Tijdschema).</li>
  <li>Verdeelt de doorstromers over de heats van de volgende ronde via het slangenpatroon.</li>
  <li>Bij een <strong>full-final systeem</strong>: de snelste rijders gaan naar de A-finale, de rest wordt verdeeld over B-finales (B1, B2, enz.).</li>
</ol>
<p>De gegenereerde volgende ronde verschijnt direct in de startlijsten-module en kan daar nog handmatig aangepast worden.</p>

<h3>6.5 Veelvoorkomende situaties</h3>
<ul>
  <li><strong>Verkeerde tijd ingevoerd?</strong> Ga terug naar de rit, corrigeer de tijd, klik opnieuw op Opslaan.</li>
  <li><strong>Rijder geswapped?</strong> Pas de startlijst aan via het deelnemerspaneel in de Startlijsten-module.</li>
  <li><strong>Race herstart?</strong> Overschrijf de resultaten met de nieuwe tijden en sla opnieuw op.</li>
</ul>
<div class="hlg-warn">&#9888; Alleen gebruikers met de rol <em>Timer</em>, <em>Admin</em> of <em>Owner</em> kunnen resultaten invoeren en rondes genereren.</div>
</div>

<!-- ═══════════════════════════════════════════════════════════ 7 UITSLAG -->
<div class="hlg-sectie">
<h2 id="hlg-uitslag">7. Uitslag</h2>
<p>De module <strong>Uitslag</strong> toont de resultaten per categorie en afstand, berekent het klassement, en biedt afdrukfuncties voor de officiële wedstrijddocumenten.</p>

<h3>7.1 Navigatie</h3>
<p>Bovenaan de pagina staan <strong>categorietabs</strong> en daaronder <strong>afstandstabs</strong> + een <strong>Klassement</strong>-tab. De tabs hebben kleuren die de status aangeven:</p>
<table>
  <thead><tr><th>Kleur</th><th>Betekenis</th></tr></thead>
  <tbody>
    <tr><td style="background:#f5f5f5">Standaard (grijs)</td><td>Nog geen resultaten beschikbaar voor deze afstand.</td></tr>
    <tr><td style="background:#fef9e7;color:#92700a">Geel</td><td>Resultaten beschikbaar maar nog niet bevestigd (afstand) / tussenklassement (klassement).</td></tr>
    <tr><td style="background:#eaf6ea;color:#2e7d32">Groen</td><td>Uitslag bevestigd (afstand) / klassement vastgelegd.</td></tr>
  </tbody>
</table>

<!-- MOCKUP: uitslag tabs -->
<div class="hlg-mock">
  ${mockHeader('Uitslag – Pup 4, 3 &amp; 2 (M/V)')}
  <div style="padding:8px 12px">
    <div style="display:flex;gap:3px;margin-bottom:4px;flex-wrap:wrap">
      <div style="background:#eaf6ea;color:#2e7d32;padding:3px 10px;border-radius:6px 6px 0 0;font-size:.72rem;font-weight:600;border-bottom:3px solid #4caf50">Tijdrit</div>
      <div style="background:#eaf6ea;color:#2e7d32;padding:3px 10px;border-radius:6px 6px 0 0;font-size:.72rem;font-weight:600;border-bottom:3px solid #4caf50">Sprint</div>
      <div style="background:#fef9e7;color:#92700a;padding:3px 10px;border-radius:6px 6px 0 0;font-size:.72rem;font-weight:600;border-bottom:3px solid #e0a800">Lange afstand</div>
      <div style="background:#fef9e7;color:#92700a;padding:3px 10px;border-radius:6px 6px 0 0;font-size:.72rem;font-weight:600;border-bottom:3px solid #e0a800;margin-left:auto">&#127942; Klassement</div>
    </div>
    <div style="font-size:.72rem;color:#888;font-style:italic;padding:6px 0">↑ Tijdrit en Sprint zijn bevestigd (groen), Lange afstand beschikbaar maar nog niet bevestigd (geel), Klassement is tussenstand (geel)</div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Afstandstabs met statuskleur: groen = bevestigd, geel = beschikbaar</p>

<h3>7.2 Uitslag per afstand</h3>
<p>Per afstand zie je een tabel met alle finales samengevoegd (A-finale, B1, B2, enz.) met positie, naam, tijd en eventuele sancties. Bij een <strong>gecombineerd systeem</strong> (serie + A-finale) worden de serie- en finalepunten apart getoond.</p>
<p>De knop <strong>✓ Uitslag bevestigen</strong> slaat de officiële uitslag op voor deze afstand. Na het bevestigen kleurt de afstandstab groen.</p>

<!-- MOCKUP: uitslag per afstand -->
<div class="hlg-mock">
  ${mockHeader('Sprint – Uitslag')}
  <div style="padding:8px 12px">
    <div style="background:#e8f5e9;border:1px solid #4caf50;border-radius:5px;padding:6px 10px;margin-bottom:8px;display:flex;align-items:center;gap:8px">
      <div style="background:#198754;color:#fff;border-radius:5px;padding:4px 10px;font-size:.75rem;font-weight:600">✓ Uitslag bevestigen</div>
      <span style="font-size:.7rem;color:#666;font-style:italic">Sla de officiële uitslag van deze afstand op</span>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.71rem">
      <thead><tr style="background:#dce6f0">
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">#</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">Naam</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">Snr</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">Finale</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">Tijd</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">Sanctie</th>
      </tr></thead>
      <tbody>
        <tr><td style="padding:3px 6px">1</td><td style="padding:3px 6px"><strong>Tycho Hanemaaijer</strong></td><td style="padding:3px 6px;color:#1a3a5c;font-weight:600">10</td><td style="padding:3px 6px">A-Finale</td><td style="padding:3px 6px;font-family:monospace">35.40</td><td style="padding:3px 6px"></td></tr>
        <tr style="background:#f8fafc"><td style="padding:3px 6px">2</td><td style="padding:3px 6px"><strong>Evie Vijverberg</strong></td><td style="padding:3px 6px;color:#1a3a5c;font-weight:600">605</td><td style="padding:3px 6px">A-Finale</td><td style="padding:3px 6px;font-family:monospace">37.80</td><td style="padding:3px 6px"></td></tr>
        <tr style="color:#888"><td style="padding:3px 6px">5</td><td style="padding:3px 6px">Daan Borst</td><td style="padding:3px 6px;font-weight:600">86</td><td style="padding:3px 6px">B1-Finale</td><td style="padding:3px 6px;font-family:monospace">53.40</td><td style="padding:3px 6px;color:#b00">Serie:FS</td></tr>
      </tbody>
    </table>
  </div>
</div>
<p class="hlg-mock-caption">↑ Uitslag per afstand: sanctie-kolom toont alle sancties van die afstand (incl. serie). Knop "Uitslag bevestigen" bovenaan.</p>

<h3>7.3 Klassement en puntensysteem</h3>
<p>Het <strong>Klassement</strong>-tab toont de totaalstand over alle afstanden.</p>

<h3>Hoe werken de punten?</h3>
<p>Punten zijn gebaseerd op finishpositie: <strong>1e = 1 punt, 2e = 2 punten, 3e = 3 punten</strong>, enz. <strong>Lager totaal = beter.</strong> Dit is het tegenovergestelde van veel andere sporten!</p>
<p>Bij een gelijk puntentotaal geldt deze tiebreaker-volgorde:</p>
<ol>
  <li>Beste individuele resultaat (laagste punten op een enkele afstand)</li>
  <li>Resultaat op de laatst gereden afstand</li>
  <li>Bij volledig ex-aequo: gedeelde positie</li>
</ol>
<p><strong>Sanctie-rijders</strong> krijgen standaard het aantal punten van de laatste positie in hun heat. Dit kun je handmatig aanpassen. <strong>0 punten</strong> invoeren = de rijder wordt volledig uitgesloten uit het klassement.</p>

<h3>Punten aanpassen (sanctie-rijders)</h3>
<p>Rijders met een sanctie op een afstand krijgen bewerkbare puntenvelden. Je kunt de punten handmatig aanpassen:</p>
<ul>
  <li>Typ het gewenste aantal punten in het gele invoerveld.</li>
  <li>Het totaal wordt <strong>live</strong> herberekend terwijl je typt.</li>
  <li>Klik <strong>&#128190; Correcties opslaan</strong> om de aanpassingen op te slaan.</li>
  <li><strong>0 punten</strong> invoeren = rijder wordt uitgesloten uit het klassement en verschijnt onderaan bij "Uitgesloten".</li>
</ul>

<h3>Klassement vastleggen</h3>
<p>De knop <strong>&#127942; Klassement vastleggen</strong> legt het definitieve klassement vast. Deze knop is pas beschikbaar als:</p>
<ul>
  <li>Alle afstanden zijn bevestigd (groen in de tabs).</li>
  <li>Er geen onopgeslagen puntencorrecties zijn.</li>
</ul>

<!-- MOCKUP: klassement -->
<div class="hlg-mock">
  ${mockHeader('Klassement – Pup 4, 3 &amp; 2 (M/V)')}
  <div style="padding:8px 12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
      <div>
        <div style="border:1px solid #ccc;border-radius:5px;padding:4px 10px;font-size:.73rem;color:#555;background:#fff">&#128190; Correcties opslaan</div>
        <div style="font-size:.65rem;color:#888;font-style:italic;margin-top:2px">Sla aangepaste punten op voor gesanctioneerde rijders</div>
      </div>
      <div>
        <div style="background:#198754;color:#fff;border-radius:5px;padding:4px 10px;font-size:.73rem;font-weight:600">&#127942; Klassement vastleggen</div>
        <div style="font-size:.65rem;color:#888;font-style:italic;margin-top:2px">Alle afstanden zijn bevestigd — klaar om vast te leggen</div>
      </div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.71rem">
      <thead><tr style="background:#dce6f0">
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">#</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">Naam</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem">Snr</th>
        <th style="padding:3px 6px;color:#198754;font-size:.68rem;font-weight:700">Tijdrit</th>
        <th style="padding:3px 6px;color:#198754;font-size:.68rem;font-weight:700">Sprint</th>
        <th style="padding:3px 6px;color:#198754;font-size:.68rem;font-weight:700">Lange afs.</th>
        <th style="padding:3px 6px;color:#1a3a5c;font-size:.68rem;font-weight:700">Totaal</th>
      </tr></thead>
      <tbody>
        <tr><td style="padding:3px 6px">1</td><td style="padding:3px 6px"><strong>Evie Vijverberg</strong></td><td style="padding:3px 6px;color:#1a3a5c;font-weight:600">605</td>
            <td style="padding:3px 6px;text-align:center">1</td><td style="padding:3px 6px;text-align:center">2</td><td style="padding:3px 6px;text-align:center">2</td><td style="padding:3px 6px;text-align:center;font-weight:700">5</td></tr>
        <tr style="background:#f8fafc"><td style="padding:3px 6px">2</td><td style="padding:3px 6px"><strong>Anouschka Belt Buitenhuis</strong></td><td style="padding:3px 6px;color:#1a3a5c;font-weight:600">587</td>
            <td style="padding:3px 6px;text-align:center">2</td><td style="padding:3px 6px;text-align:center">3</td><td style="padding:3px 6px;text-align:center">1</td><td style="padding:3px 6px;text-align:center;font-weight:700">6</td></tr>
      </tbody>
      <tbody>
        <tr style="border-top:2px solid #ddd"><td colspan="7" style="padding:5px 6px;color:#888;font-style:italic;font-size:.68rem;background:#f8f0f0">Uitgesloten (sanctie / 0 punten)</td></tr>
        <tr style="color:#999"><td style="padding:3px 6px">—</td><td style="padding:3px 6px">Daan Borst</td><td style="padding:3px 6px">86</td>
            <td style="padding:3px 6px;text-align:center">3</td><td style="padding:3px 6px;text-align:center">5 <span style="color:#b00;font-size:.63rem">FS</span></td><td style="padding:3px 6px;text-align:center">—</td><td style="padding:3px 6px;text-align:center">—</td></tr>
      </tbody>
    </table>
  </div>
</div>
<p class="hlg-mock-caption">↑ Klassement: afstandskolommen groen = bevestigd. Daan Borst is uitgesloten (0 punten op Lange afstand, met sancties FS en DQ-DF). Punten bewerkbaar bij sanctie-rijders.</p>

<h3>7.4 Afdrukken</h3>
<p>Bovenaan de uitslag-pagina staan afdrukopties. Selecteer een categorie en kies wat je wilt afdrukken:</p>
<ul>
  <li><strong>Per afstand</strong> (bijv. "Sprint"): de totaaluitslag van die afstand, met alle finales.
    <ul>
      <li>Checkbox <strong>Ronde</strong>: toon in welke finale elke rijder zat.</li>
      <li>Checkbox <strong>Tijd</strong>: toon de finishtijd.</li>
    </ul>
  </li>
  <li><strong>Tussenklassement</strong>: de tussenstand als nog niet alle afstanden gereden zijn.</li>
  <li><strong>Eindklassement</strong>: de definitieve stand over alle afstanden.</li>
</ul>

<p>Alle afdrukken bevatten:</p>
<ul>
  <li>Wedstrijdnaam, datum en locatie in de kop.</li>
  <li>Organisatielogo (rechtsboven).</li>
  <li>Sponsorlogo's als voettekst.</li>
  <li><strong>Sanctie-voetnoten:</strong> per rijder met sancties een genummerde voetnoot, bijv. <em>"(1) Daan Borst: Sprint Serie: FS, Lange afstand Finale: DQ-DF"</em></li>
</ul>

<!-- MOCKUP: printvoorbeeld klassement -->
<div class="hlg-mock">
  <div style="padding:10px 14px;font-size:.72rem;font-family:Arial,sans-serif">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1a3a5c;padding-bottom:5px;margin-bottom:6px">
      <div>
        <div style="font-size:.92rem;font-weight:700">JSC 1</div>
        <div style="font-size:.68rem;color:#555">19 april 2026 · Leiderdorp</div>
        <div style="font-size:.78rem;font-weight:700;color:#1a3a5c;margin-top:3px">Pup 4, 3 &amp; 2 (M/V) – Eindklassement</div>
      </div>
      <div style="width:50px;height:25px;background:#e8ecf0;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:#999">LOGO</div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.68rem">
      <thead><tr style="background:#dce6f0">
        <th style="padding:2px 4px;color:#1a3a5c;font-size:.63rem">#</th><th style="padding:2px 4px;color:#1a3a5c;font-size:.63rem">Naam</th>
        <th style="padding:2px 4px;color:#1a3a5c;font-size:.63rem">Snr</th><th style="padding:2px 4px;color:#1a3a5c;font-size:.63rem">Tijdr.</th>
        <th style="padding:2px 4px;color:#1a3a5c;font-size:.63rem">Sprint</th><th style="padding:2px 4px;color:#1a3a5c;font-size:.63rem">Lange</th>
        <th style="padding:2px 4px;color:#1a3a5c;font-size:.63rem;font-weight:700">Tot.</th>
      </tr></thead>
      <tbody>
        <tr><td style="padding:2px 4px">1</td><td style="padding:2px 4px">Evie Vijverberg</td><td style="padding:2px 4px">605</td><td style="padding:2px 4px;text-align:center">1</td><td style="padding:2px 4px;text-align:center">2</td><td style="padding:2px 4px;text-align:center">2</td><td style="padding:2px 4px;text-align:center;font-weight:700">5</td></tr>
        <tr style="background:#f8fafc"><td style="padding:2px 4px">2</td><td style="padding:2px 4px">Anouschka Belt B.</td><td style="padding:2px 4px">587</td><td style="padding:2px 4px;text-align:center">2</td><td style="padding:2px 4px;text-align:center">3</td><td style="padding:2px 4px;text-align:center">1</td><td style="padding:2px 4px;text-align:center;font-weight:700">6</td></tr>
        <tr style="color:#888"><td style="padding:2px 4px">—</td><td style="padding:2px 4px">Daan Borst <sup style="color:#b00;font-weight:700">(1)</sup></td><td style="padding:2px 4px">86</td><td style="padding:2px 4px;text-align:center">3</td><td style="padding:2px 4px;text-align:center">5</td><td style="padding:2px 4px;text-align:center">—</td><td style="padding:2px 4px;text-align:center">—</td></tr>
      </tbody>
    </table>
    <div style="font-size:.62rem;color:#555;margin-top:5px;border-top:1px solid #ddd;padding-top:3px">
      <strong>(1)</strong> Daan Borst: Sprint Serie: FS, Lange afstand Finale: DQ-DF
    </div>
    <div style="margin-top:5px;border-top:1px solid #ddd;padding-top:3px;display:flex;align-items:center;justify-content:center;gap:8px">
      <div style="width:40px;height:12px;background:#e8ecf0;border-radius:2px"></div>
      <div style="width:40px;height:12px;background:#e8ecf0;border-radius:2px"></div>
    </div>
  </div>
</div>
<p class="hlg-mock-caption">↑ Printvoorbeeld eindklassement: header met logo, voetnoten voor sancties, sponsorlogos onderaan</p>

<h3>7.5 Workflow samengevat</h3>
<p>De aanbevolen workflow voor de uitslag:</p>
<ol>
  <li>Per afstand: bekijk de resultaten → klik <strong>✓ Uitslag bevestigen</strong> → tab kleurt groen.</li>
  <li>In het Klassement: controleer de stand → pas eventueel punten aan voor sanctie-rijders → klik <strong>&#128190; Correcties opslaan</strong>.</li>
  <li>Als alle afstanden groen zijn: klik <strong>&#127942; Klassement vastleggen</strong> → klassement-tab kleurt groen.</li>
  <li>Druk af: kies de gewenste afdruk (per afstand of klassement) via de print-selector bovenaan.</li>
</ol>
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
<p>Via de <strong>Verwijderen</strong>-knop kun je een wedstrijd compleet uit de database verwijderen. <strong>Let op:</strong> dit verwijdert ook alle deelnemers, het tijdschema en de startlijsten van die wedstrijd.</p>
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
<p>Klik op het sleutel-icoon (&#128273;) naast een gebruiker. Je kunt je eigen wachtwoord altijd wijzigen; voor andere gebruikers heb je schrijfrechten nodig.</p>

<h3>9.5 Activeren / deactiveren</h3>
<p>Via de groene/rode bol (&#9679;) naast een gebruiker kun je een account deactiveren (de gebruiker kan dan niet meer inloggen) of weer activeren.</p>

<h3>9.6 Verwijderen</h3>
<p>Via de prullenbak-knop (&#128465;) wordt een gebruiker definitief verwijderd. De owner-account kan niet worden verwijderd.</p>

<h3>Rechtenbeperkingen</h3>
<ul>
  <li>Een <em>Admin</em> kan geen andere admins of de owner beheren.</li>
  <li>De owner-account kan niet worden gedeactiveerd of verwijderd.</li>
  <li>U kun jew eigen account niet verwijderen of deactiveren.</li>
</ul>
</div>

<!-- ═══════════════════════════════════════════════════════════ 10 TIPS -->
<div class="hlg-sectie">
<h2 id="hlg-tips">10. Algemene tips</h2>

<h3>Wedstrijddag workflow</h3>
<ol>
  <li><strong>Voorbereiding:</strong> importeer deelnemers, configureer tijdschema, genereer startlijsten, druk tekenlijsten en startlijsten af.</li>
  <li><strong>Bij aankomst rijders:</strong> controleer tekenstatus, voeg last-minute deelnemers toe via het deelnemerspaneel of het invoerformulier.</li>
  <li><strong>Tijdens de wedstrijd:</strong> voer resultaten in via Live verwerking. Genereer volgende rondes wanneer nodig.</li>
  <li><strong>Na de wedstrijd:</strong> bevestig uitslagen per afstand, pas eventueel punten aan, leg het klassement vast, druk de officiële uitslag af.</li>
</ol>

<h3>Handige weetjes</h3>
<ul>
  <li><strong>Gelijktijdige bewerking:</strong> meerdere gebruikers kunnen tegelijk ingelogd zijn. Het systeem detecteert conflicten automatisch.</li>
  <li><strong>Transponders:</strong> het systeem houdt per wedstrijd bij welk transpondernummer actief is. Dit kan afwijken van de officiële KNSB-transponders T1 en T2.</li>
  <li><strong>Handmatig toegevoegde deelnemers</strong> blijven zichtbaar na een hersynch met de KNSB-API, zolang hun status niet "Afgemeld (KNSB)" is.</li>
  <li><strong>Tab-kleuren:</strong> geel = beschikbaar/tussenstatus, groen = gereed/vastgelegd. Dit geldt in zowel Startlijsten als Uitslag.</li>
  <li><strong>Sessie verlopen?</strong> Er verschijnt automatisch een login-venster. Na inloggen kun je direct verder — geen werk gaat verloren.</li>
  <li><strong>Alle afdrukken</strong> (startlijsten, tijdschema, uitslagen, tekenlijsten) bevatten automatisch het organisatielogo en sponsorlogos. Configureer deze in de Beheer-module.</li>
  <li><strong>0 punten = uitgesloten:</strong> als je 0 punten invoert voor een gesanctioneerde rijder, wordt deze uitgesloten uit het klassement en verschijnt onderaan bij "Uitgesloten".</li>
  <li><strong>Sancties op de printout:</strong> alle sancties (inclusief waarschuwingen uit eerdere rondes) worden als genummerde voetnoten op de officiële uitslag vermeld.</li>
  <li><strong>Handleiding als PDF:</strong> klik op <em>Opslaan als PDF</em> rechtsboven in dit venster om de volledige handleiding af te drukken.</li>
</ul>
</div>

`; }
