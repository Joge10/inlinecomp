<?php
// ============================================================
//  InlineComp – inlogpagina
//
//  Veiligheidsnoot: er is GEEN web-bootstrap meer voor het aanmaken
//  van het eerste owner-account. Een lege users-tabel zou eerder
//  betekenen dat een aanvaller via login.php gratis een owner kon
//  registreren — laaghangend fruit voor misbruik na een ongelukkige
//  backup-restore of DB-reset.
//
//  Eerste account aanmaken (eenmalig na installatie) gaat via
//  phpMyAdmin met een handmatige INSERT en een bcrypt-hash van het
//  gewenste wachtwoord. Voorbeeld:
//      INSERT INTO users (username, password_hash, naam, role, actief)
//      VALUES ('owner1', '<bcrypt-hash>', 'Volledige naam', 'owner', 1);
//  De hash kun je genereren via PHP CLI:
//      php -r "echo password_hash('jouw-wachtwoord', PASSWORD_BCRYPT);"
// ============================================================

require_once __DIR__ . '/../config_inlinecomp.php';

// Al ingelogd? → direct door
$token = $_COOKIE['ic_session'] ?? '';
if ($token) {
    require_once __DIR__ . '/auth/session.php';
    if (getSession($pdo)) {
        header('Location: index.php');
        exit;
    }
}

// Als er toch geen gebruikers in de DB staan: geen formulier maar een
// duidelijke melding. Beheerder moet zelf via phpMyAdmin een account
// aanmaken (zie comment bovenaan dit bestand).
$aantalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$geenUsers   = ($aantalUsers === 0);
?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>InlineComp – Inloggen</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; margin: 0;
            background: linear-gradient(135deg, #1F4E79 0%, #2E75B6 100%);
        }
        .login-kaart {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,.25);
            padding: 2.5rem 2rem;
            width: min(360px, 92vw);
        }
        .login-logo {
            text-align: center;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--blauw);
            margin-bottom: .3rem;
            letter-spacing: -.5px;
        }
        .login-subtitel {
            text-align: center;
            font-size: .82rem;
            color: #888;
            margin-bottom: 1.8rem;
        }
        .login-veld {
            display: flex; flex-direction: column; gap: 4px;
            margin-bottom: 1rem;
        }
        .login-veld label { font-size: .8rem; color: #555; font-weight: 600; }
        .login-veld input {
            padding: 8px 10px; border: 1px solid var(--border);
            border-radius: 6px; font-size: .95rem;
            transition: border-color .15s;
        }
        .login-veld input:focus {
            outline: none; border-color: var(--middenblauw);
            box-shadow: 0 0 0 3px rgba(46,117,182,.15);
        }
        .login-btn {
            width: 100%; padding: 9px;
            background: var(--middenblauw); color: #fff;
            border: none; border-radius: 6px;
            font-size: .95rem; font-weight: 600; cursor: pointer;
            transition: background .15s;
            margin-top: .5rem;
        }
        .login-btn:hover { background: var(--blauw); }
        .login-btn:disabled { opacity: .6; cursor: not-allowed; }
        .login-fout {
            background: #fff0f0; border: 1px solid #f5c6c6;
            color: #c00; border-radius: 6px;
            padding: 8px 12px; font-size: .83rem;
            margin-bottom: 1rem; display: none;
        }
        .login-info {
            background: #fff8e1; border: 1px solid #ffe082;
            color: #7a5800; border-radius: 6px;
            padding: 8px 12px; font-size: .82rem;
            margin-bottom: 1rem;
        }
        .login-versie {
            text-align: center; font-size: .72rem;
            color: #bbb; margin-top: 1.5rem;
        }
    </style>
</head>
<body>
<div class="login-kaart">
    <div class="login-logo">InlineComp</div>
    <div class="login-subtitel">Wedstrijdbeheer inline-skaten</div>

    <?php if ($geenUsers): ?>
    <div class="login-info">
        Er zijn op dit moment geen gebruikersaccounts in de database.<br><br>
        Een beheerder moet handmatig een account aanmaken via de hosting-DB.
        Zie de instructies bovenaan <code>login.php</code>.
    </div>
    <?php else: ?>
    <form id="login-form">
        <div class="login-veld">
            <label for="username">Gebruikersnaam</label>
            <input type="text" id="username" name="username" required autocomplete="username" autofocus>
        </div>
        <div class="login-veld">
            <label for="password">Wachtwoord</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <div class="login-fout" id="login-fout"></div>
        <button type="submit" class="login-btn" id="login-btn">Inloggen</button>
    </form>
    <?php endif; ?>

    <div class="login-versie">
        InlineComp &copy; <?= date('Y') ?>
        &middot; <a href="privacyverklaring.php">Privacyverklaring</a>
    </div>
</div>

<script>
// Login-formulier alleen actief als het in de DOM staat (bij lege users-
// tabel rendert PHP géén formulier, alleen een instructie-melding).
const formEl = document.getElementById('login-form');
if (formEl) formEl.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = document.getElementById('login-btn');
    const fout = document.getElementById('login-fout');
    fout.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Inloggen…';

    try {
        const user = document.getElementById('username').value.trim();
        const pw   = document.getElementById('password').value;
        await loginRequest(user, pw);
    } catch(e) {
        toonFout('Verbindingsfout: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Inloggen';
    }
});

async function loginRequest(username, password) {
    const res  = await fetch('api/auth.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'login', username, password }),
    });
    const data = await res.json();
    if (!res.ok) { toonFout(data.error ?? 'Inloggen mislukt.'); return; }
    window.location.href = 'index.php';
}

function toonFout(tekst) {
    const fout = document.getElementById('login-fout');
    fout.textContent = tekst;
    fout.style.display = 'block';
}
</script>
</body>
</html>
