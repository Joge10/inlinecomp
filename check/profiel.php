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
require_once __DIR__ . '/../inc/maintenance.php'; maintenanceGate($pdo);   // onderhoudsmodus
require_once __DIR__ . '/../inc/versie.php';
require_once __DIR__ . '/../inc/anoniem.php';   // volg-token + volger-teller
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
$prefillUser = '';   // vooringevulde gebruikersnaam bij een fout
$claimRaw = trim($_GET['claim'] ?? '');   // raw claim-token uit de link

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

// ── Actie: eigen publieke anonimiteit aan/uit (self-service, variant B) ──────
// Alleen in een echte rijder-sessie (niet in admin-preview). Zet/wist de
// omkeerbare persons.publiek_anoniem-vlag; data blijft behouden.
if ($actie === 'pubanon' && !empty($_SESSION['rijder_lic'])) {
    $pid = $_SESSION['rijder_lic'];
    $aan = !empty($body['aan']);
    if ($aan) {
        $pdo->prepare("UPDATE persons SET publiek_anoniem = COALESCE(publiek_anoniem, NOW()) WHERE person_id = ?")
            ->execute([$pid]);
    } else {
        $pdo->prepare("UPDATE persons SET publiek_anoniem = NULL WHERE person_id = ?")
            ->execute([$pid]);
    }
    // AJAX (vinkje in de instellingen-modal) → JSON terug, geen herlaad; anders
    // de klassieke redirect (no-JS fallback).
    if (!empty($body['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'anoniem' => $aan]);
        exit;
    }
    header('Location: profiel.php'); exit;
}

// ── Actie: volg-ID vernieuwen (rotate) — snijdt ALLE huidige volgers af ──────
if ($actie === 'volg_vernieuw' && !empty($_SESSION['rijder_lic'])) {
    $nieuw = nieuwVolgToken($pdo, $_SESSION['rijder_lic']);
    if (!empty($body['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => (bool)$nieuw, 'volg_token' => $nieuw]);
        exit;
    }
    header('Location: profiel.php'); exit;
}

// ── Actie: PIN aanmaken via claim-link ──────────────────────────────────────
if ($actie === 'set_pin') {
    $tok  = trim($body['token'] ?? '');
    $gbn  = trim($body['username'] ?? '');
    $pin  = trim($body['pin'] ?? '');
    $pin2 = trim($body['pin2'] ?? '');
    $claimRaw = $tok;   // blijf op de claim-view bij een fout
    $prefillUser = $gbn;
    $st = $pdo->prepare("SELECT rp.person_id AS license_key, rp.username, p.full_name
        FROM rijder_profiel rp JOIN persons p ON p.person_id = rp.person_id
        WHERE rp.claim_token_hash = ? AND rp.claim_expires > NOW() LIMIT 1");
    $st->execute([hash('sha256', $tok)]);
    $rij = $st->fetch(PDO::FETCH_ASSOC);
    $vasteUser = $rij ? trim((string)$rij['username']) : '';   // door beheerder ingesteld (uit de aanvraag)
    if (!$rij) {
        $fout = 'Deze claim-link is ongeldig of verlopen. Vraag een nieuwe aan.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $gbn)) {
        $fout = 'Vul een gebruikersnaam van 3–30 tekens in (letters, cijfers, . _ of -).';
    } elseif (!preg_match('/^\d{5,6}$/', $pin)) {
        $fout = 'Kies een PIN van 5 of 6 cijfers.';
    } elseif ($pin !== $pin2) {
        $fout = 'De twee PINs zijn niet gelijk.';
    } elseif ($vasteUser !== '' && strcasecmp($gbn, $vasteUser) !== 0) {
        // Beheerder zette de aanvraag-gebruikersnaam → moet matchen (zelfde-persoon-check).
        $fout = 'De gebruikersnaam komt niet overeen met je aanvraag — kijk in de e-mail van de organisatie.';
    } else {
        // Zelf-gekozen gebruikersnaam (beheerder liet 'm leeg) → uniek-check.
        if ($vasteUser === '') {
            $uq = $pdo->prepare("SELECT 1 FROM rijder_profiel WHERE username = ? AND person_id <> ? LIMIT 1");
            $uq->execute([$gbn, $rij['license_key']]);
            if ($uq->fetchColumn()) $fout = 'Die gebruikersnaam is al in gebruik — kies een andere.';
        }
        if ($fout === '') {
            $pdo->prepare("UPDATE rijder_profiel
                SET username = ?, pin_hash = ?, claim_token_hash = NULL, claim_expires = NULL,
                    claimed_at = NOW(), pin_pogingen = 0, lockout_tot = NULL
                WHERE person_id = ?")
                ->execute([($vasteUser !== '' ? $vasteUser : $gbn),
                           password_hash($pin, PASSWORD_DEFAULT), $rij['license_key']]);
            $_SESSION['rijder_lic'] = $rij['license_key'];   // meteen ingelogd
            unset($_SESSION['rp_fails'], $_SESSION['rp_lock_tot']);
            header('Location: profiel.php'); exit;
        }
    }
}

// ── Actie: inloggen (gebruikersnaam + PIN) ──────────────────────────────────
if ($actie === 'login') {
    $gbn = trim($body['username'] ?? '');
    $pin = trim($body['pin'] ?? '');
    $prefillUser = $gbn;
    $wacht = _rpGelockt();
    if ($wacht > 0) {
        $fout = 'Te veel pogingen. Probeer over ' . ceil($wacht / 60) . ' min opnieuw.';
    } elseif ($gbn === '' || $pin === '') {
        $fout = 'Vul je gebruikersnaam en PIN in.';
    } else {
        // Uniek op gebruikersnaam (CI-collation) → geen naam-giswerk meer.
        $st = $pdo->prepare("SELECT rp.person_id AS license_key, rp.pin_hash
            FROM rijder_profiel rp JOIN persons p ON p.person_id = rp.person_id
            WHERE rp.username = ? AND rp.pin_hash IS NOT NULL AND p.anonymized_at IS NULL LIMIT 1");
        $st->execute([$gbn]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c && password_verify($pin, $c['pin_hash'])) {
            $_SESSION['rijder_lic'] = $c['license_key'];
            unset($_SESSION['rp_fails'], $_SESSION['rp_lock_tot']);
            $pdo->prepare("UPDATE rijder_profiel SET laatste_login = NOW() WHERE person_id = ?")
                ->execute([$c['license_key']]);
            header('Location: profiel.php'); exit;
        }
        // Mislukt → tel op; lockout na 5 pogingen voor 15 min.
        $_SESSION['rp_fails'] = ($_SESSION['rp_fails'] ?? 0) + 1;
        if ($_SESSION['rp_fails'] >= 5) { $_SESSION['rp_lock_tot'] = time() + 900; $_SESSION['rp_fails'] = 0; }
        $fout = 'Gebruikersnaam of PIN klopt niet.';
    }
}

// ── Actie: profiel aanvragen (formulier → mail naar de beheerder) ───────────
// Netter + minder spam-gevoelig dan een mailto: het e-mailadres staat niet in
// de pagina. De app slaat NIETS op — het bericht gaat naar de inbox; de
// beheerder stuurt de claim-link terug (Systeem → Rijders → Profiel-link).
if ($actie === 'aanvraag') {
    // Nieuwe aanvraag → als 'pending' in rijder_profiel_aanvraag; de rijder
    // krijgt een "in afwachting"-mail (organisatie in Cc). De beheerder koppelt
    // later de juiste rijder + gebruikersnaam en keurt goed (Systeem → Rijders),
    // waarna de claim-link automatisch gemaild wordt. Het e-mailadres staat
    // alleen tussen aanvraag en verwerking in de DB (daarna gewist).
    $aNaam  = trim($body['a_naam']  ?? '');
    $aSnr   = trim($body['a_snr']   ?? '');
    $aEmail = trim($body['a_email'] ?? '');
    $aUser  = trim($body['a_user']  ?? '');
    $aOpm   = trim($body['a_opm']   ?? '');
    $honey  = trim($body['website'] ?? '');   // honeypot: bots vullen 'm, mensen zien 'm niet
    // Lengtes begrenzen (defensief + past op de kolommen)
    $aNaam = mb_substr($aNaam, 0, 120);
    $aSnr  = mb_substr($aSnr, 0, 20);
    $aUser = mb_substr($aUser, 0, 30);
    $aOpm  = mb_substr($aOpm, 0, 500);
    if ($honey !== '') {
        $okmsg = 'Bedankt! Je aanvraag is verstuurd.';   // bot: stil doen alsof
    } elseif ($aNaam === '' || !filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
        $fout = 'Vul je naam en een geldig e-mailadres in.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $aUser)) {
        // Zelfde tekenset als de beheer-kant (geen spaties), zodat een aanvraag
        // niet later op de gebruikersnaam blijft hangen bij het goedkeuren.
        $fout = 'Kies een gebruikersnaam van 3–30 tekens: letters, cijfers, punt, - of _ (geen spaties).';
        $prefillUser = $aUser;
    } elseif (!empty($_SESSION['rp_aanvr_tot']) && $_SESSION['rp_aanvr_tot'] > time()) {
        $okmsg = 'Je aanvraag is al verstuurd — de organisatie neemt contact op.';
    } else {
        // Dubbele openstaande aanvraag met hetzelfde e-mailadres voorkomen.
        $dup = $pdo->prepare("SELECT 1 FROM rijder_profiel_aanvraag WHERE status='pending' AND email = ? LIMIT 1");
        $dup->execute([$aEmail]);
        if ($dup->fetchColumn()) {
            $_SESSION['rp_aanvr_tot'] = time() + 60;
            $okmsg = 'Je hebt al een aanvraag lopen — de organisatie behandelt hem. Controleer ook je spam-map.';
        } else {
            $ins = $pdo->prepare("
                INSERT INTO rijder_profiel_aanvraag (naam, startnummer, email, gewenste_username, opmerking)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $aNaam,
                $aSnr !== '' ? $aSnr : null,
                $aEmail,
                $aUser !== '' ? $aUser : null,
                $aOpm !== '' ? $aOpm : null,
            ]);
            require_once __DIR__ . '/../inc/profiel_mail.php';
            $m = profielMailInAfwachting($aNaam, $aSnr, $aUser, $aOpm);
            $ok = profielMail($aEmail, $m['subject'], $m['body'], PROFIEL_MAIL_CC);
            $_SESSION['rp_aanvr_tot'] = time() + 60;   // simpele rate-limit
            if ($ok) {
                $okmsg = 'Bedankt! Je aanvraag is verstuurd. Je krijgt zo een bevestiging per e-mail, en na '
                       . 'goedkeuring een link om zelf een pincode aan te maken. Controleer ook je spam-map — '
                       . 'onze mail belandt daar soms.';
            } else {
                // Aanvraag staat wél in de DB; alleen de bevestigingsmail faalde.
                $okmsg = 'Bedankt! Je aanvraag is ontvangen. Het versturen van de bevestigingsmail lukte niet '
                       . 'meteen, maar de organisatie ziet je aanvraag en neemt contact op.';
            }
        }
    }
}

// ── Bepaal de weer te geven toestand ────────────────────────────────────────
$ingelogd  = !empty($_SESSION['rijder_lic']);
// Sessie direct ongeldig maken als het profiel intussen is verwijderd door de
// beheerder (de weergave leest uit persons/uitslagen, dus zonder deze check zou
// een al-open sessie blijven werken tot uitloggen).
if ($ingelogd) {
    $chk = $pdo->prepare("SELECT 1 FROM rijder_profiel WHERE person_id = ? AND pin_hash IS NOT NULL LIMIT 1");
    $chk->execute([$_SESSION['rijder_lic']]);
    if (!$chk->fetchColumn()) { unset($_SESSION['rijder_lic']); $ingelogd = false; }
}
$claimView = ($claimRaw !== '' && !$ingelogd);
$claimNaam = ''; $claimUser = '';
if ($claimView) {
    // Toon voor wie de claim is (naam) + evt. de door de beheerder ingestelde
    // gebruikersnaam, als de link geldig is.
    $st = $pdo->prepare("SELECT p.full_name, rp.username
        FROM rijder_profiel rp JOIN persons p ON p.person_id = rp.person_id
        WHERE rp.claim_token_hash = ? AND rp.claim_expires > NOW() LIMIT 1");
    $st->execute([hash('sha256', $claimRaw)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) { $claimNaam = (string)$row['full_name']; $claimUser = (string)($row['username'] ?? ''); }
    if ($claimNaam === '' && $fout === '') $fout = 'Deze claim-link is ongeldig of verlopen.';
}

$profiel = null;
if ($ingelogd) {
    $profiel = rijderProfielData($pdo, $_SESSION['rijder_lic']);
    if (!$profiel['persoon']) { unset($_SESSION['rijder_lic']); $ingelogd = false; }
}

// ── Admin-testweergave ──────────────────────────────────────────────────────
// Een ingelogde beheerder (owner/admin) kan via ?preview=<license_key> het
// profiel van een rijder bekijken zoals de rijder het ziet — zonder PIN.
// Alleen-lezen; handig om te controleren of alles goed in "Mijn InlineComp"
// landt (bv. bij een nieuwe aanvraag). Leest de admin-sessie (cookie
// ic_session, zelfde origin) los van de rijder-sessie.
$adminPreview = false;
$previewLic = trim($_GET['preview'] ?? '');
if ($previewLic !== '' && !$ingelogd) {
    require_once __DIR__ . '/../auth/session.php';
    $admin = getSession($pdo);
    if ($admin && in_array($admin['role'] ?? '', ['owner', 'admin'], true)) {
        $p = rijderProfielData($pdo, $previewLic);
        if (!empty($p['persoon'])) {
            $adminPreview = true; $claimView = false; $profiel = $p;
        } else {
            $fout = 'Testweergave: deze rijder is niet gevonden.';
        }
    } else {
        $fout = 'Testweergave is alleen beschikbaar voor ingelogde beheerders.';
    }
}

// Demo-modus: laat (verzonnen) voorbeelddata zien zodat een bezoeker weet wat
// een profiel is vóór hij er een aanvraagt. Alleen als niet ingelogd/geen preview.
$demo = (isset($_GET['demo']) && !$ingelogd && !$adminPreview);
if ($demo) { $claimView = false; $profiel = rijderProfielDemo(); }
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
.hero-top{display:flex;align-items:center;justify-content:space-between;gap:12px;position:relative;z-index:1}
.hero-gear{display:inline-flex;align-items:center;gap:6px;flex:none;
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.32);color:#fff;
  border-radius:999px;padding:6px 13px 6px 10px;font-size:.8rem;font-weight:600;cursor:pointer;line-height:1;white-space:nowrap}
.hero-gear:hover{background:rgba(255,255,255,.28)}
.hero-gear svg{display:block;color:var(--oranje)}   /* subtiele InlineComp-oranje merk-accent op de cog */
.chip.chip-anon{background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.3)}
dialog.settings-modal{border:0;border-radius:16px;padding:0;max-width:440px;width:calc(100% - 32px);
  box-shadow:var(--shadow);color:var(--ink);background:var(--surface)}
dialog.settings-modal::backdrop{background:rgba(18,58,94,.45)}
.sm-head{display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:15px 18px;border-bottom:1px solid var(--line)}
.sm-head h2{margin:0;font-size:1.12rem;font-weight:700}
.sm-x{margin:0}
.sm-x button{background:transparent;border:0;font-size:1.5rem;line-height:1;color:var(--muted);cursor:pointer;padding:0 2px}
.sm-body{padding:16px 18px}
.sm-body h3{margin:0 0 4px;font-size:1rem;font-weight:700}
.sm-body p{margin:0 0 10px;color:var(--muted);font-size:.9rem}
.sm-status{color:var(--ink);font-size:.95rem;margin:0 0 12px}
.volg-id{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.volg-id code{background:var(--surface-2);border:1px solid var(--line);padding:5px 9px;border-radius:6px;
  font-size:.85rem;user-select:all;word-break:break-all;flex:1;min-width:120px}
.toggle-row{display:flex;align-items:center;gap:10px;font-weight:600;cursor:pointer;margin:0 0 10px;font-size:1rem}
.toggle-row input{width:20px;height:20px;cursor:pointer;accent-color:var(--brand);flex:none}
/* Eigen mini-bevestiging (geen native confirm/alert). */
dialog.mini-modal{border:0;border-radius:14px;padding:18px;max-width:360px;width:calc(100% - 32px);
  box-shadow:var(--shadow);color:var(--ink);background:var(--surface)}
dialog.mini-modal::backdrop{background:rgba(18,58,94,.5)}
dialog.mini-modal p{margin:0 0 16px;font-size:.95rem;line-height:1.5}
.mini-modal-acties{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}

/* ── Login / claim kaart ── */
.authcard{background:var(--surface);border:1px solid var(--line);border-radius:16px;
  box-shadow:var(--shadow);max-width:440px;margin:6vh auto 0;padding:26px 24px}
.authcard h1{margin:0 0 4px;font-size:1.5rem}
.authcard .sub{color:var(--muted);font-size:.92rem;margin-bottom:18px}
.veld{margin-bottom:12px}
.veld label{display:block;font-size:.82rem;color:#555;font-weight:600;margin-bottom:4px}
.veld-hint{font-weight:400;color:var(--faint);font-size:.8rem}
.veld input{width:100%;font:inherit;padding:10px 11px;border:1px solid #c0c8d0;border-radius:8px}
.veld input:focus{outline:2px solid var(--accent);outline-offset:-1px}
.veld input[readonly]{background:var(--surface-2);color:var(--muted)}
.authcard .btn{width:100%;margin-top:6px;padding:11px}
.melding{padding:9px 12px;border-radius:8px;font-size:.9rem;margin-bottom:12px}
.melding.fout{background:#fce4e4;color:#b71c1c;border:1px solid #f3b6b6}
.melding.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}
.demo-banner{background:#fff4e6;border:1px solid #ffd9a3;color:#8a5a1a;border-radius:10px;padding:10px 14px;margin-top:12px;margin-bottom:18px;font-size:.9rem}
.demo-banner a{color:var(--oranje);font-weight:600;white-space:nowrap}
.admin-banner{background:#eef3fb;border:1px solid #bcd2ee;color:#1a3a5c;border-radius:10px;padding:10px 14px;margin-top:12px;margin-bottom:18px;font-size:.9rem}
.ap-tag{display:inline-block;background:#1a3a5c;color:#fff;border-radius:8px;padding:6px 12px;font-size:.85rem;font-weight:600}
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
.chartwrap{position:relative;margin-top:14px;overflow-x:auto;-webkit-overflow-scrolling:touch}
svg{width:100%;height:auto;display:block;overflow:visible}
#chart{min-width:680px}   /* breder bij meer seizoenen (JS); anders zijwaarts scrollen */
.scroll-hint{display:none;text-align:center;color:var(--faint);font-size:.78rem;margin-top:6px}
.scroll-hint.show{display:block}
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
  border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:9px 11px;min-width:150px;
  max-width:min(240px,78vw);overflow-wrap:anywhere;transform:translate(-50%,-112%)}
.tip .t-af{font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:7px}
.tip .t-af .sw{width:10px;height:10px;border-radius:3px}
.tip .t-rang{font-weight:700;font-size:1.35rem;line-height:1.1;margin:3px 0 1px;font-variant-numeric:tabular-nums}
.tip .t-sub{color:var(--muted);font-size:.82rem}
.tip .t-comp{color:var(--ink);font-size:.86rem;margin-top:3px}
.crosshair{stroke:var(--faint);stroke-width:1;stroke-dasharray:3 3;opacity:.7}
.leeg-chart{color:var(--faint);text-align:center;padding:30px 10px}
/* PR-rij → detail-popup (vooral mobiel) */
.pr-row{cursor:pointer}
.pr-more{display:none;color:var(--accent);font-size:.78rem;font-weight:600;margin-top:3px}
.prpop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:50;align-items:center;justify-content:center;padding:20px}
.prpop-box{background:var(--surface);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:18px 20px;max-width:340px;width:100%}
.prpop-af{font-weight:700;display:flex;align-items:center;gap:8px;font-size:1.05rem}
.prpop-af .sw{width:12px;height:12px;border-radius:3px}
.prp-blok{margin-top:12px}
.prp-lbl{text-transform:uppercase;letter-spacing:.06em;font-size:.68rem;color:var(--muted);font-weight:600}
.prp-big{font-weight:700;font-size:1.3rem;font-variant-numeric:tabular-nums;line-height:1.1;margin-top:1px}
.prp-sub{color:var(--faint);font-size:.82rem;margin-top:1px}
.prpop-sluit{margin-top:16px;width:100%;background:var(--brand);color:#fff;border:0;border-radius:8px;padding:10px;font:inherit;font-weight:600;cursor:pointer}
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
  .pr-ctx{display:none}         /* context uit de tabel; te zien via de tik-box */
  .pr-more{display:block}
  table.pr td{padding:9px 8px}
}
</style>
</head>
<body>
<div class="wrap">
<?php if ($ingelogd || $demo || $adminPreview): $pr = $profiel['persoon']; $stat = $profiel['stats'];
      $catTxt = $pr['category'] ?: '';
      $isEigen = (!$demo && !$adminPreview);                       // echte ingelogde rijder
      $pubAnon = $isEigen && !empty($pr['publiek_anoniem']);
      // Geheim volg-token (lazy gemint) — voor de instellingen-modal.
      $volgToken = $isEigen ? (zorgVoorVolgToken($pdo, (string)$pr['license_key']) ?? '') : ''; ?>
  <div class="topbar">
    <a class="home" href="<?= $demo ? 'profiel.php' : './' ?>"><?= $demo ? '← Terug' : '← InlineComp Check' ?></a>
    <?php if ($demo): ?>
      <a class="btn" href="profiel.php">Vraag je eigen profiel aan</a>
    <?php elseif ($adminPreview): ?>
      <span class="ap-tag">🔒 Testweergave (beheer)</span>
    <?php else: ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
        <button class="btn btn-sec" name="actie" value="logout">Uitloggen</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($demo): ?>
    <div class="demo-banner">👀 <b>Voorbeeld</b> — zo ziet je persoonlijke profiel eruit. Met een eigen profiel zie je je <b>échte</b> resultaten, records en progressie. <a href="profiel.php">Vraag er een aan →</a></div>
  <?php elseif ($adminPreview): ?>
    <div class="admin-banner">🔒 <b>Testweergave (beheer)</b> — je bekijkt het profiel van <b><?= esc($pr['full_name']) ?></b> zoals de rijder het straks ziet. Alleen-lezen; je bent niet ingelogd als deze rijder. Sluit dit tabblad om terug te gaan.</div>
  <?php endif; ?>

  <header class="hero">
    <div class="hero-top">
      <div class="eyebrow">Mijn InlineComp</div>
      <?php if ($isEigen): ?>
      <button type="button" class="hero-gear" id="btn-settings" title="Instellingen">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.03 7.03 0 0 0-1.62-.94l-.36-2.54A.5.5 0 0 0 13.9 2h-3.8a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96a.5.5 0 0 0-.6.22L2.31 8.48a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.24.41.34.66.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.25.42.5.42h3.8c.25 0 .46-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.25.12.53.02.66-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.05-1.58zM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z"/></svg>
        <span>Instellingen</span>
      </button>
      <?php endif; ?>
    </div>
    <h1><?= esc($pr['full_name']) ?></h1>
    <div class="meta">
      <?php if ($catTxt): ?><span class="chip"><?= esc($catTxt) ?></span><?php endif; ?>
      <?php if ($pr['start_number'] !== null): ?><span class="chip">Startnr <?= (int)$pr['start_number'] ?></span><?php endif; ?>
      <?php if ($pr['club']): ?><span><?= esc($pr['club']) ?></span><?php endif; ?>
      <?php if ($pubAnon): ?><span class="chip chip-anon" id="hero-anon-chip" title="Je bent publiek anoniem — je naam is buiten de wedstrijddagen afgeschermd">🕶 anoniem</span><?php endif; ?>
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
    <div class="scroll-hint" id="scroll-hint">↔ Sleep de grafiek zijwaarts om alle seizoenen te zien</div>
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

  <?php if ($isEigen): ?>
  <dialog id="settings-modal" class="settings-modal">
    <div class="sm-head">
      <h2>⚙ Instellingen</h2>
      <form method="dialog" class="sm-x"><button aria-label="Sluiten" title="Sluiten">&times;</button></form>
    </div>
    <div class="sm-body">
      <h3>Privacy — publiek anoniem</h3>
      <p>
        Ben je publiek anoniem, dan wordt je naam (en club/woonplaats) op de publieke
        pagina's vervangen door <b>“Anoniem”</b> — je startnummer blijft staan en je
        gegevens blijven volledig behouden. Rond de wedstrijddag (van de dag ervoor tot
        en met de dag erna) wordt je naam wél getoond; daarbuiten en in het serie-klassement
        blijf je anoniem.
      </p>
      <label class="toggle-row">
        <input type="checkbox" id="chk-anon" <?= $pubAnon ? 'checked' : '' ?>>
        <span>Publiek anoniem</span>
      </label>
      <p class="sm-status" id="anon-status" style="margin:0 0 18px">
        <?= $pubAnon ? '🕶 Je bent <b>publiek anoniem</b>.' : 'Je bent normaal met naam zichtbaar.' ?>
      </p>
      <h3>Jouw volg-ID</h3>
      <p>
        Wil je dat iemand (bv. je ouder of coach) je tóch kan volgen terwijl je anoniem
        bent? Geef ze dan jouw persoonlijke volg-ID — daarmee zien zij wél je naam.
        Deel het alleen met wie je vertrouwt.
      </p>
      <div class="volg-id">
        <code id="volg-id-code"><?= esc($volgToken) ?></code>
        <button type="button" class="btn btn-sec" id="btn-copy-id" title="Kopieer">📋 Kopieer</button>
      </div>
      <p style="margin:12px 0 0">
        <button type="button" class="btn btn-sec" id="btn-volg-vernieuw">🔄 Volg-ID vernieuwen</button>
      </p>
      <p style="margin:6px 0 0;color:var(--muted);font-size:.82rem">
        Vernieuwen maakt je oude volg-ID ongeldig en snijdt <b>iedereen</b> die je nu volgt af —
        ook mensen aan wie je het eerder gaf. Deel daarna het nieuwe volg-ID opnieuw.
      </p>
    </div>
  </dialog>

  <dialog id="bevestig-modal" class="mini-modal">
    <p id="bevestig-tekst"></p>
    <div class="mini-modal-acties">
      <button type="button" id="bevestig-annuleer" class="btn btn-sec">Annuleren</button>
      <button type="button" id="bevestig-ok" class="btn">OK</button>
    </div>
  </dialog>

  <script>
  (function () {
    // Eigen bevestiging/melding (géén native confirm/alert). alleenOk = melding.
    function _bevestig(tekst, opts) {
      opts = opts || {};
      return new Promise(resolve => {
        const d = document.getElementById('bevestig-modal');
        const p = document.getElementById('bevestig-tekst');
        const okB = document.getElementById('bevestig-ok');
        const anB = document.getElementById('bevestig-annuleer');
        if (!d || !p || !okB || !anB) { resolve(true); return; }
        p.textContent = tekst;
        okB.textContent = opts.okLabel || 'OK';
        anB.textContent = opts.cancelLabel || 'Annuleren';
        anB.style.display = opts.alleenOk ? 'none' : '';
        let klaar = false;
        const eind = (v) => { if (klaar) return; klaar = true; okB.onclick = anB.onclick = null; try { d.close(); } catch (e) {} resolve(v); };
        okB.onclick = () => eind(true);
        anB.onclick = () => eind(false);
        d.addEventListener('close', () => eind(opts.alleenOk ? true : false), { once: true });
        d.showModal();
      });
    }

    const dlg = document.getElementById('settings-modal');
    const openBtn = document.getElementById('btn-settings');
    if (dlg && openBtn) {
      openBtn.addEventListener('click', () => dlg.showModal());
      // Klik op de achtergrond (buiten de inhoud) sluit de modal.
      dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    }
    const copyBtn = document.getElementById('btn-copy-id');
    if (copyBtn) {
      copyBtn.addEventListener('click', async () => {
        const code = document.getElementById('volg-id-code');
        const txt = (code.textContent || '').trim();
        try {
          await navigator.clipboard.writeText(txt);
          const orig = copyBtn.textContent;
          copyBtn.textContent = '✓ Gekopieerd';
          setTimeout(() => { copyBtn.textContent = orig; }, 1500);
        } catch (e) {
          // Fallback: selecteer de tekst zodat handmatig kopiëren makkelijk is.
          const r = document.createRange(); r.selectNode(code);
          const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
        }
      });
    }

    // Vinkje: direct opslaan (auto-save). Je vinkt aan/uit en sluit de modal —
    // de wijziging is meteen doorgevoerd. Hero-chip + status live bijwerken.
    const CSRF = <?= json_encode($CSRF) ?>;
    const chk = document.getElementById('chk-anon');
    function updateAnonUI(anon) {
      const st = document.getElementById('anon-status');
      if (st) st.innerHTML = anon ? '🕶 Je bent <b>publiek anoniem</b>.' : 'Je bent normaal met naam zichtbaar.';
      let chip = document.getElementById('hero-anon-chip');
      const meta = document.querySelector('.hero .meta');
      if (anon && !chip && meta) {
        chip = document.createElement('span');
        chip.id = 'hero-anon-chip';
        chip.className = 'chip chip-anon';
        chip.title = 'Je bent publiek anoniem — je naam is buiten de wedstrijddagen afgeschermd';
        chip.textContent = '🕶 anoniem';
        meta.appendChild(chip);
      } else if (!anon && chip) {
        chip.remove();
      }
    }
    if (chk) {
      chk.addEventListener('change', async () => {
        chk.disabled = true;
        try {
          const res = await fetch('profiel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf: CSRF, actie: 'pubanon', aan: chk.checked ? '1' : '0', ajax: '1' }),
          });
          const data = await res.json();
          if (!data || !data.ok) throw new Error('opslaan mislukt');
          updateAnonUI(!!data.anoniem);
        } catch (e) {
          chk.checked = !chk.checked;   // terugdraaien bij fout
          await _bevestig('Kon de instelling niet opslaan. Probeer het opnieuw.', { alleenOk: true });
        } finally {
          chk.disabled = false;
        }
      });
    }

    // Volg-ID vernieuwen: rotate het geheime token → snijdt alle huidige volgers
    // af. Bevestigen, dan live het getoonde ID + de teller bijwerken.
    const vBtn = document.getElementById('btn-volg-vernieuw');
    if (vBtn) {
      vBtn.addEventListener('click', async () => {
        const akkoord = await _bevestig(
          'Volg-ID vernieuwen? Iedereen die je nu volgt wordt afgesneden — ook mensen aan wie je het eerder gaf. Deel daarna het nieuwe volg-ID opnieuw.',
          { okLabel: 'Vernieuwen', cancelLabel: 'Annuleren' });
        if (!akkoord) return;
        vBtn.disabled = true;
        try {
          const res = await fetch('profiel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf: CSRF, actie: 'volg_vernieuw', ajax: '1' }),
          });
          const data = await res.json();
          if (!data || !data.ok || !data.volg_token) throw new Error('mislukt');
          const code = document.getElementById('volg-id-code');
          if (code) code.textContent = data.volg_token;
          const orig = vBtn.textContent;
          vBtn.textContent = '✓ Vernieuwd';
          setTimeout(() => { vBtn.textContent = orig; }, 1500);
        } catch (e) {
          await _bevestig('Kon het volg-ID niet vernieuwen. Probeer het opnieuw.', { alleenOk: true });
        } finally {
          vBtn.disabled = false;
        }
      });
    }
  })();
  </script>
  <?php endif; ?>

  <section class="soon">
    <h3>Binnenkort</h3>
    <ul>
      <li>Ontbrekende of foutieve <strong>eigen gegevens</strong> (bv. naam, club of woonplaats) melden per wedstrijd — de organisatie past ze dan aan. Officiële uitslagen en tijden blijven ongewijzigd. <span class="tag">binnenkort</span></li>
      <li>Je profiel (deels) publiek deelbaar maken — link, embed of API <span class="tag">binnenkort</span></li>
      <li>Kiezen welke coaches je profiel mogen zien <span class="tag">binnenkort</span></li>
    </ul>
  </section>

  <script>
  const DATA = { sprint: <?= json_encode($profiel['sprint'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                 lang:   <?= json_encode($profiel['lang'],   JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?> };
  <?php readfile(__DIR__ . '/profiel_chart.js'); ?>
  </script>

<?php elseif ($claimView && $claimNaam !== ''): ?>
  <div class="authcard">
    <h1>Profiel activeren</h1>
    <div class="sub">Welkom <b><?= esc($claimNaam) ?></b> — <?= $claimUser !== '' ? 'je gebruikersnaam staat al ingevuld; kies alleen nog een PIN.' : 'vul je gebruikersnaam in (zoals in de e-mail van de organisatie) en kies een PIN.' ?></div>
    <?php if ($fout): ?><div class="melding fout"><?= esc($fout) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
      <input type="hidden" name="actie" value="set_pin">
      <input type="hidden" name="token" value="<?= esc($claimRaw) ?>">
      <div class="veld">
        <label for="username">Gebruikersnaam</label>
        <?php if ($claimUser !== ''): ?>
          <input type="text" id="username" name="username" value="<?= esc($claimUser) ?>" readonly>
        <?php else: ?>
          <input type="text" id="username" name="username" value="<?= esc($prefillUser) ?>" autocomplete="off" required>
        <?php endif; ?>
      </div>
      <div class="veld">
        <label for="pin">Kies een PIN (5 of 6 cijfers)</label>
        <input type="password" id="pin" name="pin" inputmode="numeric" pattern="\d{5,6}" maxlength="6" autocomplete="new-password" required>
      </div>
      <div class="veld">
        <label for="pin2">Herhaal je PIN</label>
        <input type="password" id="pin2" name="pin2" inputmode="numeric" pattern="\d{5,6}" maxlength="6" autocomplete="new-password" required>
      </div>
      <button class="btn" type="submit">Profiel activeren &amp; inloggen</button>
    </form>
    <div class="uitleg">Onthoud je <b>gebruikersnaam + PIN</b> — daarmee log je voortaan in. Kwijt? Vraag een nieuwe link aan bij de organisatie.</div>
  </div>

<?php else: ?>
  <div class="authcard">
    <h1>Mijn InlineComp</h1>
    <div class="sub">Log in met je gebruikersnaam en PIN om je persoonlijke profiel te zien — je resultaten, records en progressie.</div>
    <?php if ($fout): ?><div class="melding fout"><?= esc($fout) ?></div><?php endif; ?>
    <?php if ($claimView && $claimNaam === ''): ?><div class="melding fout">De claim-link is ongeldig of verlopen.</div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= esc($CSRF) ?>">
      <input type="hidden" name="actie" value="login">
      <div class="veld">
        <label for="luser">Gebruikersnaam</label>
        <input type="text" id="luser" name="username" value="<?= esc($prefillUser) ?>" autocomplete="off" required>
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
          <div class="veld"><label for="a_user">Gewenste gebruikersnaam <span class="veld-hint">— hiermee log je straks in</span></label>
            <input type="text" id="a_user" name="a_user" pattern="[A-Za-z0-9._-]{3,30}" minlength="3" maxlength="30" placeholder="bv. voornaam.achternaam" required
                   title="3–30 tekens: letters, cijfers, punt, - of _ — geen spaties"
                   value="<?= esc($prefillUser) ?>">
            <span class="veld-hint">3–30 tekens: letters, cijfers, punt, - of _ (geen spaties)</span></div>
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
