<?php
// ============================================================
//  InlineComp – "Mijn InlineComp": persoonlijk rijder-profiel
//
//  Onder /check (van-mij, met login-laag). Drie toestanden:
//    1. ?claim=<token>            → PIN aanmaken (claim-link uit e-mail/balie)
//    2. niet ingelogd             → login (naam + PIN) + uitleg/aanvraag
//    3. ingelogd (sessie)         → profielweergave (grafiek + PR's)
//
//  Toegang = naam + PIN (geen e-mail opgeslagen). Rate-limit/lockout op de
//  PIN-invoer. De opties (delen/coach/anoniem) + eigen-data-corrigeren komen
//  later — hier als "binnenkort" getoond.
//
//  v1 is NL-only (achter login, Nederlandse rijders). 4-talig = latere uitbreiding.
// ============================================================
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../inc/versie.php';
require_once __DIR__ . '/../api/_rijderprofiel_data.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('ICRIDER');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    @session_start();
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$fout = '';          // foutmelding onder een formulier
$okmsg = '';         // succesmelding
$claimRaw = trim($_GET['claim'] ?? '');   // raw claim-token uit de link

// ── Naam-normalisatie voor de login-lookup (accenten/streepjes weg) ─────────
function _rpNaamNorm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ï'=>'i','ó'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','û'=>'u','ç'=>'c','ñ'=>'n']);
    return preg_replace('/\s+/u', ' ', trim($s));
}

$POST = ($_SERVER['REQUEST_METHOD'] === 'POST');
$body = [];
if ($POST) {
    $body = $_POST;
    if (($body['csrf'] ?? '') !== $CSRF) { $POST = false; $fout = 'Sessie verlopen — probeer opnieuw.'; }
}
$actie = $POST ? ($body['actie'] ?? '') : '';

// ── Lockout (sessie-gebaseerd) ──────────────────────────────────────────────
function _rpGelockt(): int {   // resterende seconden lockout, of 0
    if (!empty($_SESSION['rp_lock_tot']) && $_SESSION['rp_lock_tot'] > time()) {
        return $_SESSION['rp_lock_tot'] - time();
    }
    return 0;
}

// ── Actie: uitloggen ────────────────────────────────────────────────────────
if ($actie === 'logout') {
    unset($_SESSION['rijder_lic']);
    header('Location: profiel.php'); exit;
}

// ── Actie: PIN aanmaken via claim-link ──────────────────────────────────────
if ($actie === 'set_pin') {
    $tok  = trim($body['token'] ?? '');
    $pin  = trim($body['pin'] ?? '');
    $pin2 = trim($body['pin2'] ?? '');
    $claimRaw = $tok;   // blijf op de claim-view bij een fout
    $th = hash('sha256', $tok);
    $st = $pdo->prepare("SELECT rp.license_key, p.full_name
        FROM rijder_profiel rp JOIN persons p ON p.license_key = rp.license_key
        WHERE rp.claim_token_hash = ? AND rp.claim_expires > NOW() LIMIT 1");
    $st->execute([$th]);
    $rij = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rij) {
        $fout = 'Deze claim-link is ongeldig of verlopen. Vraag een nieuwe aan.';
    } elseif (!preg_match('/^\d{5,6}$/', $pin)) {
        $fout = 'Kies een PIN van 5 of 6 cijfers.';
    } elseif ($pin !== $pin2) {
        $fout = 'De twee PINs zijn niet gelijk.';
    } else {
        $pdo->prepare("UPDATE rijder_profiel
            SET pin_hash = ?, claim_token_hash = NULL, claim_expires = NULL,
                claimed_at = NOW(), pin_pogingen = 0, lockout_tot = NULL
            WHERE license_key = ?")
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $rij['license_key']]);
        $_SESSION['rijder_lic'] = $rij['license_key'];   // meteen ingelogd
        unset($_SESSION['rp_fails'], $_SESSION['rp_lock_tot']);
        header('Location: profiel.php'); exit;
    }
}

// ── Actie: inloggen (naam + PIN) ────────────────────────────────────────────
if ($actie === 'login') {
    $naam = trim($body['naam'] ?? '');
    $pin  = trim($body['pin'] ?? '');
    $wacht = _rpGelockt();
    if ($wacht > 0) {
        $fout = 'Te veel pogingen. Probeer over ' . ceil($wacht / 60) . ' min opnieuw.';
    } elseif ($naam === '' || $pin === '') {
        $fout = 'Vul je naam en PIN in.';
    } else {
        // Kandidaten: personen wier naam (genormaliseerd) matcht én die een
        // geclaimd profiel (pin_hash) hebben. PIN is de discriminator.
        $st = $pdo->prepare("SELECT rp.license_key, rp.pin_hash, p.full_name
            FROM rijder_profiel rp JOIN persons p ON p.license_key = rp.license_key
            WHERE rp.pin_hash IS NOT NULL AND p.anonymized_at IS NULL");
        $st->execute();
        $nn = _rpNaamNorm($naam);
        $match = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (_rpNaamNorm($c['full_name']) !== $nn) continue;
            if (password_verify($pin, $c['pin_hash'])) { $match = $c; break; }
        }
        if ($match) {
            $_SESSION['rijder_lic'] = $match['license_key'];
            unset($_SESSION['rp_fails'], $_SESSION['rp_lock_tot']);
            $pdo->prepare("UPDATE rijder_profiel SET laatste_login = NOW() WHERE license_key = ?")
                ->execute([$match['license_key']]);
            header('Location: profiel.php'); exit;
        }
        // Mislukt → tel op; lockout na 5 pogingen voor 15 min.
        $_SESSION['rp_fails'] = ($_SESSION['rp_fails'] ?? 0) + 1;
        if ($_SESSION['rp_fails'] >= 5) { $_SESSION['rp_lock_tot'] = time() + 900; $_SESSION['rp_fails'] = 0; }
        $fout = 'Naam of PIN klopt niet.';
    }
}

// ── Actie: profiel aanvragen (formulier → mail naar de beheerder) ───────────
// Netter + minder spam-gevoelig dan een mailto: het e-mailadres staat niet in
// de pagina. De app slaat NIETS op — het bericht gaat naar de inbox; de
// beheerder stuurt de claim-link terug (Systeem → Rijders → Profiel-link).
if ($actie === 'aanvraag') {
    $aNaam  = trim($body['a_naam']  ?? '');
    $aSnr   = trim($body['a_snr']   ?? '');
    $aEmail = trim($body['a_email'] ?? '');
    $aOpm   = trim($body['a_opm']   ?? '');
    $honey  = trim($body['website'] ?? '');   // honeypot: bots vullen 'm, mensen zien 'm niet
    if ($honey !== '') {
        $okmsg = 'Bedankt! Je aanvraag is verstuurd.';   // bot: stil doen alsof
    } elseif ($aNaam === '' || !filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
        $fout = 'Vul je naam en een geldig e-mailadres in.';
    } elseif (!empty($_SESSION['rp_aanvr_tot']) && $_SESSION['rp_aanvr_tot'] > time()) {
        $okmsg = 'Je aanvraag is al verstuurd — de organisatie neemt contact op.';
    } else {
        $r = [];
        $r[] = 'InlineComp – profiel-aanvraag via /check/profiel.php';
        $r[] = str_repeat('─', 50);
        $r[] = 'Naam:        ' . $aNaam;
        $r[] = 'Startnummer: ' . ($aSnr !== '' ? $aSnr : '—');
        $r[] = 'E-mail:      ' . $aEmail;
        if ($aOpm !== '') { $r[] = ''; $r[] = 'Opmerking:'; foreach (explode("\n", $aOpm) as $l) $r[] = '  ' . $l; }
        $r[] = '';
        $r[] = 'Verstuurd:   ' . date('Y-m-d H:i:s');
        $r[] = str_repeat('─', 50);
        $r[] = 'Beantwoord deze mail en stuur de claim-link (Systeem → Rijders → 🔑 Profiel-link).';
        $bodyTxt = implode("\n", $r);
        $headers = implode("\r\n", [
            'From: InlineComp <inlinecomp@devriesen.com>',
            'Reply-To: ' . $aEmail,
            'Content-Type: text/plain; charset=utf-8',
            'X-Mailer: InlineComp Profiel',
        ]);
        $ok = @mail('inlinecomp@devriesen.com', '[InlineComp] Profiel-aanvraag — ' . $aNaam, $bodyTxt, $headers);
        if ($ok) {
            $_SESSION['rp_aanvr_tot'] = time() + 60;   // simpele rate-limit
            $okmsg = 'Bedankt! Je aanvraag is verstuurd. Je krijgt van de organisatie een link om zelf een PIN aan te maken.';
        } else {
            $fout = 'Versturen is niet gelukt — probeer het later opnieuw.';
        }
    }
}

// ── Bepaal de weer te geven toestand ────────────────────────────────────────
$ingelogd  = !empty($_SESSION['rijder_lic']);
$claimView = ($claimRaw !== '' && !$ingelogd);
$claimNaam = '';
if ($claimView) {
    // Toon voor wie de claim is (naam) als de link geldig is.
    $st = $pdo->prepare("SELECT p.full_name
        FROM rijder_profiel rp JOIN persons p ON p.license_key = rp.license_key
        WHERE rp.claim_token_hash = ? AND rp.claim_expires > NOW() LIMIT 1");
    $st->execute([hash('sha256', $claimRaw)]);
    $claimNaam = (string)($st->fetchColumn() ?: '');
    if ($claimNaam === '' && $fout === '') $fout = 'Deze claim-link is ongeldig of verlopen.';
}

$profiel = null;
if ($ingelogd) {
    $profiel = rijderProfielData($pdo, $_SESSION['rijder_lic']);
    if (!$profiel['persoon']) { unset($_SESSION['rijder_lic']); $ingelogd = false; }
}
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Mijn InlineComp</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<style>
:root{
  --ground:#eef2f6; --surface:#fff; --surface-2:#f6f9fc;
  --ink:#15212e; --muted:#5b6b7b; --faint:#8b9aa8;
  --line:#dbe3ec; --grid:#e7edf3;
  --brand:#123a5e; --brand-2:#1c5c93; --accent:#1c7fd6;
  --blauw:#1F4E79; --oranje:#E8630A;
  --c-blue:#0072B2; --c-orange:#E69F00; --c-green:#009E73; --c-verm:#D55E00;
  --c-pink:#CC79A7; --c-sky:#56B4E9; --c-yellow:#B8A400; --c-grey:#777;
  --shadow:0 1px 2px rgba(18,58,94,.06), 0 10px 30px rgba(18,58,94,.08);
}
*{box-sizing:border-box}
body{margin:0;background:var(--ground);color:var(--ink);
  font-family:'Segoe UI',system-ui,-apple-system,Arial,sans-serif;font-size:16px;line-height:1.5}
.wrap{max-width:1000px;margin:0 auto;padding:22px 18px 60px}
a{color:var(--accent)}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}
.topbar .home{color:var(--brand);text-decoration:none;font-weight:600;font-size:.92rem}
.btn{background:var(--brand);color:#fff;border:0;border-radius:8px;padding:9px 16px;
  font:inherit;font-weight:600;cursor:pointer}
.btn:hover{background:var(--brand-2)}
.btn-sec{background:var(--surface);color:var(--brand);border:1px solid var(--line)}
.btn-sec:hover{background:var(--surface-2)}

/* ── Login / claim kaart ── */
.authcard{background:var(--surface);border:1px solid var(--line);border-radius:16px;
  box-shadow:var(--shadow);max-width:440px;margin:6vh auto 0;padding:26px 24px}
.authcard h1{margin:0 0 4px;font-size:1.5rem}
.authcard .sub{color:var(--muted);font-size:.92rem;margin-bottom:18px}
.veld{margin-bottom:12px}
.veld label{display:block;font-size:.82rem;color:#555;font-weight:600;margin-bottom:4px}
.veld input{width:100%;font:inherit;padding:10px 11px;border:1px solid #c0c8d0;border-radius:8px}
.veld input:focus{outline:2px solid var(--accent);outline-offset:-1px}
.authcard .btn{width:100%;margin-top:6px;padding:11px}
.melding{padding:9px 12px;border-radius:8px;font-size:.9rem;margin-bottom:12px}
.melding.fout{background:#fce4e4;color:#b71c1c;border:1px solid #f3b6b6}
.melding.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}
.uitleg{margin-top:18px;padding-top:16px;border-top:1px solid var(--line);font-size:.86rem;color:var(--muted)}
.uitleg b{color:var(--ink)}
.aanvraag-form{margin-top:12px}
.aanvraag-form .veld{margin-bottom:9px}
.aanvraag-form .btn{width:100%;padding:11px;margin-top:4px}
.hp{position:absolute!important;left:-9999px;width:1px;height:1px;overflow:hidden}
.mt12{margin-top:12px}

/* ── Profiel: hero + kaarten (uit de mockup, light-only) ── */
.hero{background:linear-gradient(150deg,var(--brand) 0%,var(--brand-2) 140%);color:#eaf2fa;
  border-radius:16px;padding:24px 26px;box-shadow:var(--shadow);position:relative;overflow:hidden}
.hero::after{content:"";position:absolute;right:-40px;top:-60px;width:240px;height:240px;
  background:radial-gradient(circle,rgba(255,255,255,.10),transparent 62%);pointer-events:none}
.eyebrow{font-weight:600;letter-spacing:.14em;text-transform:uppercase;font-size:.72rem;color:#9fc4e6}
.hero h1{font-weight:700;font-size:clamp(1.7rem,4.5vw,2.6rem);margin:.15rem 0 .35rem}
.hero .meta{display:flex;flex-wrap:wrap;gap:8px 10px;align-items:center;color:#cfe0f0;font-size:.95rem}
.chip{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.16);padding:2px 10px;
  border-radius:999px;font-weight:600;font-size:.82rem}
.statrow{display:flex;gap:26px;margin-top:18px;flex-wrap:wrap}
.stat .n{font-weight:700;font-size:1.5rem;line-height:1;font-variant-numeric:tabular-nums}
.stat .l{text-transform:uppercase;letter-spacing:.08em;font-size:.66rem;color:#9fc4e6;margin-top:3px}
.stat .n .sup{font-size:1rem}
.card{background:var(--surface);border:1px solid var(--line);border-radius:16px;
  box-shadow:var(--shadow);margin-top:18px;padding:20px 20px 8px}
.card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
.card-head h2{margin:0;font-size:1.18rem;font-weight:700}
.card-head p{margin:2px 0 0;color:var(--muted);font-size:.9rem;max-width:52ch}
.seg{display:inline-flex;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;padding:3px;gap:2px;flex-shrink:0}
.seg button{font-weight:600;font-size:.9rem;color:var(--muted);background:transparent;border:0;cursor:pointer;padding:7px 16px;border-radius:8px}
.seg button[aria-pressed="true"]{background:var(--accent);color:#fff}
.seg[hidden]{display:none}
.chartwrap{position:relative;margin-top:14px}
svg{width:100%;height:auto;display:block;overflow:visible}
.gridline{stroke:var(--grid);stroke-width:1}
.axis-tick{fill:var(--faint);font-size:12px;font-variant-numeric:tabular-nums}
.axis-tick.y{text-anchor:end}
.axis-tick.x{text-anchor:middle}
.axis-title{fill:var(--muted);font-size:11.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
.serie-line{fill:none;stroke-width:2.4;stroke-linejoin:round;stroke-linecap:round}
.serie-dot{stroke:var(--surface);stroke-width:2}
.serie-label{font-weight:700;font-size:12.5px}
.legend{display:flex;flex-wrap:wrap;gap:6px 8px;margin:12px 2px 4px}
.legend button{display:inline-flex;align-items:center;gap:7px;background:var(--surface-2);border:1px solid var(--line);
  border-radius:999px;padding:5px 12px 5px 10px;cursor:pointer;font-weight:600;font-size:.85rem;color:var(--ink)}
.legend button[aria-pressed="false"]{opacity:.4}
.legend .sw{width:11px;height:11px;border-radius:3px;flex-shrink:0}
.note{color:var(--faint);font-size:.82rem;margin:14px 2px 4px;line-height:1.5}
.note b{color:var(--muted);font-weight:600}
.metricrow{display:flex;align-items:center;gap:10px 14px;margin-top:12px;min-height:34px;flex-wrap:wrap}
.metric-hint{color:var(--faint);font-size:.85rem}
.seg.small button{padding:5px 13px;font-size:.85rem}
.metricrow .ml-auto{margin-left:auto}
.nl-toggle{display:inline-flex;align-items:center;gap:7px;font-size:.86rem;color:var(--muted);cursor:pointer;user-select:none;font-weight:600}
.nl-toggle input{width:15px;height:15px;accent-color:var(--accent);cursor:pointer;margin:0}
.t-nl{display:inline-block;margin-top:3px;color:var(--accent);font-weight:600;font-size:.78rem}
.tablewrap{overflow-x:auto;margin-top:6px}
table.pr{width:100%;border-collapse:collapse;font-size:.92rem}
table.pr th{text-align:left;text-transform:uppercase;letter-spacing:.06em;font-size:.72rem;color:var(--muted);
  font-weight:600;padding:6px 12px 8px;border-bottom:1px solid var(--line);white-space:nowrap}
table.pr td{padding:10px 12px;border-bottom:1px solid var(--grid);vertical-align:top}
table.pr tbody tr:last-child td{border-bottom:0}
.pr-af{font-weight:600;display:flex;align-items:center;gap:8px}
.pr-af .sw{width:10px;height:10px;border-radius:3px;flex-shrink:0}
.pr-big{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.pr-ctx{display:block;color:var(--faint);font-size:.8rem;margin-top:2px;line-height:1.35}
.pr-none{color:var(--faint)}
.tip{position:absolute;pointer-events:none;z-index:5;opacity:0;transition:opacity .1s;background:var(--surface);
  border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:9px 11px;min-width:170px;transform:translate(-50%,-112%)}
.tip .t-af{font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:7px}
.tip .t-af .sw{width:10px;height:10px;border-radius:3px}
.tip .t-rang{font-weight:700;font-size:1.35rem;line-height:1.1;margin:3px 0 1px;font-variant-numeric:tabular-nums}
.tip .t-sub{color:var(--muted);font-size:.82rem}
.tip .t-comp{color:var(--ink);font-size:.86rem;margin-top:3px}
.crosshair{stroke:var(--faint);stroke-width:1;stroke-dasharray:3 3;opacity:.7}
.leeg-chart{color:var(--faint);text-align:center;padding:30px 10px}
/* Binnenkort-blok */
.soon{margin-top:18px;padding:16px 18px;background:var(--surface-2);border:1px dashed var(--line);border-radius:14px}
.soon h3{margin:0 0 6px;font-size:1rem;color:var(--brand)}
.soon ul{margin:6px 0 0;padding-left:20px;color:var(--muted);font-size:.9rem}
.soon li{margin:3px 0}
.soon .tag{display:inline-block;background:#fff3e0;color:#e65100;font-size:.7rem;font-weight:700;
  padding:1px 8px;border-radius:10px;margin-left:6px;vertical-align:middle}
@media (max-width:560px){
  .wrap{padding:14px 12px 40px}
  .hero{padding:18px 16px}
  .statrow{gap:14px 22px}
  .chartwrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  #chart{min-width:520px}
}
</style>
</head>
<body>
<div class="wrap">
<?php if ($ingelogd): $pr = $profiel['persoon']; $stat = $profiel['stats'];
      $catTxt = $pr['category'] ?: ''; ?>
  <div class="topbar">
    <a class="home" href="./">← InlineComp Check</a>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
      <button class="btn btn-sec" name="actie" value="logout">Uitloggen</button>
    </form>
  </div>

  <header class="hero">
    <div class="eyebrow">Mijn InlineComp</div>
    <h1><?= esc($pr['full_name']) ?></h1>
    <div class="meta">
      <?php if ($catTxt): ?><span class="chip"><?= esc($catTxt) ?></span><?php endif; ?>
      <?php if ($pr['start_number'] !== null): ?><span class="chip">Startnr <?= (int)$pr['start_number'] ?></span><?php endif; ?>
      <?php if ($pr['club']): ?><span><?= esc($pr['club']) ?></span><?php endif; ?>
    </div>
    <div class="statrow">
      <div class="stat"><div class="n"><?= (int)$stat['wedstrijden'] ?></div><div class="l">Wedstrijden</div></div>
      <div class="stat"><div class="n"><?= (int)$stat['uitslagen'] ?></div><div class="l">Afstand-uitslagen</div></div>
      <div class="stat"><div class="n"><?= $stat['beste_klassering'] !== null ? (int)$stat['beste_klassering'] . '<span class="sup">e</span>' : '–' ?></div><div class="l">Beste klassering</div></div>
      <div class="stat"><div class="n"><?= (int)$stat['seizoenen'] ?></div><div class="l">Seizoenen</div></div>
    </div>
  </header>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>Klassering per afstand</h2>
        <p>Eindplek in je eigen categorie over de tijd. Een <b>dalende</b> lijn = vooruitgang (dichter bij plek 1).</p>
      </div>
      <div class="seg" role="group" aria-label="Afstandsgroep">
        <button id="btn-sprint" aria-pressed="true">Sprint</button>
        <button id="btn-lang" aria-pressed="false">Lang</button>
      </div>
    </div>
    <div class="metricrow" id="metricrow">
      <label class="nl-toggle" id="nl-toggle" title="Herbereken de klassering alsof alleen NL-rijders meededen">
        <input type="checkbox" id="nl-check"> 🇳🇱 Alleen NL-klassering
      </label>
      <span class="metric-hint" id="metric-hint"></span>
      <div class="seg small ml-auto" id="ymetric" role="group" aria-label="Y-as" hidden>
        <button id="btn-rang" aria-pressed="true">Klassering</button>
        <button id="btn-tijd" aria-pressed="false">Tijd</button>
      </div>
    </div>
    <div class="chartwrap">
      <svg id="chart" viewBox="0 0 920 460" role="img" aria-label="Progressie per afstand"></svg>
      <div class="tip" id="tip"></div>
      <div class="leeg-chart" id="leeg-chart" style="display:none">Nog geen uitslagen in deze groep.</div>
    </div>
    <div class="legend" id="legend" aria-label="Afstanden — klik om te tonen/verbergen"></div>
    <p class="note"><b>Sprint</b> = 200m · 500m · 1000m · One Lap. &nbsp; <b>Lang</b> = puntenkoers &amp; afvalkoers.
      Klassering is de eindplek in je eigen categorie; de veldgrootte verschilt per wedstrijd, dus lees de lijn als vórm. Bron: alle vastgelegde en geïmporteerde uitslagen in InlineComp.</p>
  </section>

  <section class="card">
    <h2 style="margin:0 0 3px;font-size:1.18rem;font-weight:700">Persoonlijke records</h2>
    <p style="margin:0 0 4px;color:var(--muted);font-size:.9rem">Snelste tijd (over álle ronden — een serie is vaak sneller dan de finale) en beste klassering per afstand.</p>
    <div class="tablewrap">
      <table class="pr" id="prtable">
        <thead><tr><th>Afstand</th><th>Beste tijd</th><th>Beste klassering</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <section class="soon">
    <h3>Binnenkort</h3>
    <ul>
      <li>Je eigen gegevens corrigeren per wedstrijd <span class="tag">binnenkort</span></li>
      <li>Je profiel (deels) publiek deelbaar maken — link, embed of API <span class="tag">binnenkort</span></li>
      <li>Kiezen welke coaches je profiel mogen zien <span class="tag">binnenkort</span></li>
      <li>Anoniem tonen op de publieke pagina's <span class="tag">binnenkort</span></li>
    </ul>
  </section>

  <script>
  const DATA = { sprint: <?= json_encode($profiel['sprint'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                 lang:   <?= json_encode($profiel['lang'],   JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?> };
  <?php readfile(__DIR__ . '/profiel_chart.js'); ?>
  </script>

<?php elseif ($claimView && $claimNaam !== ''): ?>
  <div class="authcard">
    <h1>PIN aanmaken</h1>
    <div class="sub">Welkom <b><?= esc($claimNaam) ?></b> — kies een PIN voor je persoonlijke InlineComp-profiel.</div>
    <?php if ($fout): ?><div class="melding fout"><?= esc($fout) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
      <input type="hidden" name="actie" value="set_pin">
      <input type="hidden" name="token" value="<?= esc($claimRaw) ?>">
      <div class="veld">
        <label for="pin">Kies een PIN (5 of 6 cijfers)</label>
        <input type="password" id="pin" name="pin" inputmode="numeric" pattern="\d{5,6}" maxlength="6" autocomplete="new-password" required>
      </div>
      <div class="veld">
        <label for="pin2">Herhaal je PIN</label>
        <input type="password" id="pin2" name="pin2" inputmode="numeric" pattern="\d{5,6}" maxlength="6" autocomplete="new-password" required>
      </div>
      <button class="btn" type="submit">PIN opslaan &amp; inloggen</button>
    </form>
    <div class="uitleg">Onthoud je PIN goed. Volgende keer log je in met <b>je naam + PIN</b>. PIN vergeten? Vraag een nieuwe claim-link aan.</div>
  </div>

<?php else: ?>
  <div class="authcard">
    <h1>Mijn InlineComp</h1>
    <div class="sub">Log in met je naam en PIN om je persoonlijke profiel te zien — je resultaten, records en progressie.</div>
    <?php if ($fout): ?><div class="melding fout"><?= esc($fout) ?></div><?php endif; ?>
    <?php if ($claimView && $claimNaam === ''): ?><div class="melding fout">De claim-link is ongeldig of verlopen.</div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
      <input type="hidden" name="actie" value="login">
      <div class="veld">
        <label for="naam">Je volledige naam</label>
        <input type="text" id="naam" name="naam" autocomplete="off" required>
      </div>
      <div class="veld">
        <label for="lpin">PIN</label>
        <input type="password" id="lpin" name="pin" inputmode="numeric" pattern="\d{5,6}" maxlength="6" autocomplete="off" required>
      </div>
      <button class="btn" type="submit">Inloggen</button>
    </form>
    <div class="uitleg">
      <b>Nog geen profiel? Vraag een profiel-account aan.</b> Je krijgt van de organisatie
      een link waarmee je zelf een PIN aanmaakt. Meld je ook even persoonlijk bij de
      organisatie op de wedstrijd.
      <?php if ($okmsg): ?>
        <div class="melding ok mt12"><?= esc($okmsg) ?></div>
      <?php else: ?>
        <form method="post" autocomplete="off" class="aanvraag-form">
          <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
          <input type="hidden" name="actie" value="aanvraag">
          <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <div class="veld"><label for="a_naam">Je volledige naam</label>
            <input type="text" id="a_naam" name="a_naam" required></div>
          <div class="veld"><label for="a_snr">Startnummer (indien bekend)</label>
            <input type="text" id="a_snr" name="a_snr" inputmode="numeric"></div>
          <div class="veld"><label for="a_email">Je e-mailadres</label>
            <input type="email" id="a_email" name="a_email" required></div>
          <div class="veld"><label for="a_opm">Opmerking (optioneel)</label>
            <input type="text" id="a_opm" name="a_opm"></div>
          <button class="btn" type="submit">Profiel-account aanvragen</button>
        </form>
      <?php endif; ?>
      <div class="mt12">Je profiel is <b>privé</b> — niet openbaar en niet vindbaar in zoekmachines.</div>
    </div>
  </div>
<?php endif; ?>
</div>
</body>
</html>
