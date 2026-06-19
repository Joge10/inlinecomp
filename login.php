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
            padding: 2.2rem 2rem 2rem;
            width: min(460px, 94vw);
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
            margin-bottom: 1.5rem;
        }
        /* ── App-tegels (Check / Public / Coach) ─────────────────────────
           Eén rij van drie aanklikbare tegels. Niet-ingelogde bezoekers
           kunnen direct naar de app-die-ze-zochten. Jury bewust NIET hier
           — die blijft alleen via directe URL bereikbaar. */
        .ic-tiles {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 1.2rem;
        }
        .ic-tile {
            display: flex; flex-direction: column; align-items: center;
            gap: 6px; padding: 12px 6px 10px; text-decoration: none;
            color: var(--blauw); border: 1px solid #e3e7ec; border-radius: 8px;
            background: #fff; transition: background .12s, border-color .12s, transform .08s;
        }
        @media (hover: hover) {
            .ic-tile:hover {
                background: #f4f8fc; border-color: var(--middenblauw);
                transform: translateY(-1px);
            }
        }
        .ic-tile:active { transform: translateY(0); }
        /* IC-logo blok met letter-badge in oranje rechtsonder. Identiek
           patroon als de live-app icoontjes (zie favicon.svg + apple-touch). */
        .ic-icon {
            position: relative;
            width: 56px; height: 56px;
            background: var(--blauw); border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 1.1rem;
            box-shadow: 0 2px 6px rgba(31,78,121,.25);
        }
        .ic-icon::after {
            /* Oranje balkje onderaan, zelfde stijl als de favicon. */
            content: ''; position: absolute; left: 14%; right: 14%; bottom: 22%;
            height: 4px; background: var(--oranje); border-radius: 2px;
        }
        .ic-icon .ic-badge {
            position: absolute; right: -6px; bottom: -6px;
            width: 22px; height: 22px;
            background: var(--oranje); color: #fff;
            border: 2px solid #fff; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .72rem; font-weight: 700;
            box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .ic-badge-check { font-size: .85rem; line-height: 1; }
        .ic-label { font-size: .85rem; font-weight: 700; margin-top: 2px; }
        .ic-tagline { font-size: .67rem; color: #888; text-align: center;
                      line-height: 1.2; min-height: 1.7em; }
        /* Divider met "of" tussen tegels en login-formulier. */
        .ic-divider {
            position: relative; text-align: center;
            margin: .4rem 0 1.2rem;
            font-size: .72rem; color: #aaa; letter-spacing: 1px;
        }
        .ic-divider::before, .ic-divider::after {
            content: ''; position: absolute; top: 50%;
            width: 38%; height: 1px; background: #e3e7ec;
        }
        .ic-divider::before { left: 0; }
        .ic-divider::after  { right: 0; }
        .ic-divider span { background: #fff; padding: 0 8px; }
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

    <!-- Drie app-tegels voor publiek/coach/check.
         Jury bewust niet hier — die blijft alleen via directe URL. -->
    <div class="ic-tiles">
        <a class="ic-tile" href="check/" title="Controleer je inschrijving">
            <div class="ic-icon">IC<span class="ic-badge ic-badge-check">✓</span></div>
            <div class="ic-label">Check</div>
            <div class="ic-tagline">Controleer<br>je inschrijving</div>
        </a>
        <a class="ic-tile" href="public/" title="Live wedstrijdinfo voor rijders en publiek">
            <div class="ic-icon">IC<span class="ic-badge">P</span></div>
            <div class="ic-label">Public</div>
            <div class="ic-tagline">Voor rijders<br>en publiek</div>
        </a>
        <a class="ic-tile" href="coach/" title="Voor coaches">
            <div class="ic-icon">IC<span class="ic-badge">C</span></div>
            <div class="ic-label">Coach</div>
            <div class="ic-tagline">Voor coaches</div>
        </a>
    </div>
    <div class="ic-divider"><span>of inloggen</span></div>

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
