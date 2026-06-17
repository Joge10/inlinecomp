<?php
// ============================================================
//  InlineComp – authenticatie
//
//  POST action=login              { username, password } → zet cookie
//  POST action=logout                                    → wis cookie
//  GET  action=me                                        → ingelogde user info
//  POST action=update_profiel     { naam, username, huidig_wachtwoord }
//  POST action=change_password    { huidig_wachtwoord, nieuw_wachtwoord }
//  POST action=logout                           → wist cookie
//  GET  action=me                               → huidige gebruiker
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

// ── Login-log helpers ─────────────────────────────────────────────────────────

function landVlag(string $code): string {
    if (strlen($code) !== 2) return '';
    $o = 0x1F1E6 - ord('A');
    return mb_chr(ord($code[0]) + $o, 'UTF-8') . mb_chr(ord($code[1]) + $o, 'UTF-8');
}

function geoloceer(string $ip): array {
    // Privé / lokale IP-adressen niet opzoeken
    if (!$ip || filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['land' => 'Lokaal', 'stad' => ''];
    }
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=country,countryCode,city&lang=nl';
    $geo = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 2]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) $geo = json_decode($resp, true) ?? [];
    } elseif (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http' => ['timeout' => 2]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp) $geo = json_decode($resp, true) ?? [];
    }
    if (!$geo || ($geo['status'] ?? '') === 'fail') return ['land' => '', 'stad' => ''];
    $vlag = landVlag($geo['countryCode'] ?? '');
    return [
        'land' => ($vlag ? $vlag . ' ' : '') . ($geo['country'] ?? ''),
        'stad' => $geo['city'] ?? '',
    ];
}

function parseerBrowser(string $ua): string {
    if (!$ua) return 'Onbekend';
    if (str_contains($ua, 'Edg/') || str_contains($ua, 'Edge/'))   return 'Edge';
    if (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera/'))  return 'Opera';
    if (str_contains($ua, 'Chrome/'))  return 'Chrome';
    if (str_contains($ua, 'Firefox/')) return 'Firefox';
    if (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/')) return 'Safari';
    if (str_contains($ua, 'MSIE') || str_contains($ua, 'Trident/')) return 'IE';
    return 'Onbekend';
}

function parseerOS(string $ua): string {
    if (!$ua) return '';
    if (str_contains($ua, 'Windows NT')) return 'Windows';
    if (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) return 'macOS';
    if (str_contains($ua, 'Android'))    return 'Android';
    if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'iOS';
    if (str_contains($ua, 'Linux'))      return 'Linux';
    return '';
}

// Echte client-IP — accepteert X-Forwarded-For (door reverse proxy gezet)
// of X-Real-IP, valt terug op REMOTE_ADDR. Komma-lijst: eerste waarde is
// de oorspronkelijke client. Filter op valid IP om gespoofde headers af
// te vangen (al biedt dat geen bescherming als er géén trusted proxy is —
// op shared hosting is dit het beste wat we hebben zonder server-config).
function clientIp(): string {
    $kandidaten = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP']       ?? '',
        $_SERVER['REMOTE_ADDR']          ?? '',
    ];
    foreach ($kandidaten as $raw) {
        if (!$raw) continue;
        $first = trim(explode(',', $raw)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return '0.0.0.0';
}

// Rate-limit-instellingen voor login-pogingen per IP én per username.
// Bij ≥MAX mislukten binnen het venster wordt de bron geweigerd tot het
// venster verstreken is. Drempels conservatief gekozen: 10 mislukten in
// 15 min komt bij menselijk falend gedrag zelden voor (normaal: 1-3).
const LOGIN_RATE_VENSTER_MIN     = 15;
const LOGIN_RATE_MAX_FAILED      = 10;

// Email-alerting bij verdachte patronen. Cooldown voorkomt mail-spam als
// een bot de drempel blijft raken — max één mail per uur per (reden, bron).
// From-adres moet match'en met het server-domein voor SPF/DMARC bij byethost
// — zelfde patroon als /check (zie check/index.php:544). Daarom gebruiken
// we hetzelfde inlinecomp@devriesen.com-adres, met "Security" in de
// display-name zodat 't in de mailbox onderscheidbaar is van /check-mails.
const LOGIN_ALERT_COOLDOWN_MIN   = 60;
const LOGIN_ALERT_MAIL_TO        = 'inlinecomp@devriesen.com';
const LOGIN_ALERT_MAIL_FROM      = 'InlineComp Security <inlinecomp@devriesen.com>';

// Verstuurt 1 email-alert + logt 'm in login_alerts. Cooldown-check
// voorkomt herhaalde meldingen voor dezelfde bron in korte tijd. Fail-silent:
// fouten in mail()/INSERT mogen de login-flow nooit blokkeren.
//
// $username  = primaire/eerste username (voor DB-record + cooldown-key bij
//              user_burst). Bij ip_burst typisch leeg.
// $details   = optionele uitgebreide toelichting voor in de mail body —
//              bv. bij ip_burst de lijst geprobeerde usernames met counts,
//              zodat een ontvanger ziet of 't brute-force (1 user) of
//              credential stuffing (veel users) is. Niet in DB opgeslagen.
function stuurLoginAlert(PDO $pdo, string $reden, string $ip, string $username, int $aantal, string $details = ''): void {
    try {
        // Cooldown-check op de relevante combinatie (ip voor ip_burst,
        // username voor user_burst). Andere kolom is NULL in WHERE.
        $cdSql = ($reden === 'user_burst')
            ? "reden = ? AND username = ? AND ts > NOW() - INTERVAL " . LOGIN_ALERT_COOLDOWN_MIN . " MINUTE"
            : "reden = ? AND ip = ?       AND ts > NOW() - INTERVAL " . LOGIN_ALERT_COOLDOWN_MIN . " MINUTE";
        $cdKey = ($reden === 'user_burst') ? $username : $ip;
        $cdStmt = $pdo->prepare("SELECT COUNT(*) FROM login_alerts WHERE $cdSql");
        $cdStmt->execute([$reden, $cdKey]);
        if ((int)$cdStmt->fetchColumn() > 0) return;

        // Vastleggen (audit-spoor + cooldown-bron)
        $pdo->prepare("INSERT INTO login_alerts (reden, ip, username, aantal) VALUES (?,?,?,?)")
            ->execute([$reden, $ip ?: null, $username ?: null, $aantal]);

        $tijd     = date('Y-m-d H:i:s');
        $host     = $_SERVER['HTTP_HOST'] ?? 'inlinecomp';
        $redenLbl = $reden === 'user_burst'
            ? 'Te veel mislukte pogingen op één username (mogelijk credential stuffing vanaf meerdere IPs)'
            : 'Te veel mislukte pogingen vanaf één IP (mogelijk brute-force of credential stuffing)';
        // Bij ip_burst: tonen welke usernames concreet zijn geprobeerd (details).
        // Bij user_burst: tonen welke username het betreft (username).
        // Bij ip_burst zonder details (legacy fallback): "(onbekend)".
        $userRegel = $details !== ''
            ? "Pogingen op : $details\n"
            : "Username    : " . ($username ?: '(onbekend)') . "\n";
        $onderwerp = "[$host] InlineComp — verdachte login-pogingen ($reden)";
        $body =
            "InlineComp heeft een verdacht login-patroon gedetecteerd en de bron tijdelijk geweigerd.\n\n"
          . "Tijdstip    : $tijd\n"
          . "Host        : $host\n"
          . "Reden       : $redenLbl\n"
          . "IP          : " . ($ip ?: '(onbekend)') . "\n"
          . $userRegel
          . "Aantal      : $aantal mislukte pogingen in laatste " . LOGIN_RATE_VENSTER_MIN . " minuten\n\n"
          . "De bron blijft geweigerd tot het venster verstrijkt. Vervolg-meldingen voor "
          . "dezelfde bron worden " . LOGIN_ALERT_COOLDOWN_MIN . " min onderdrukt om mail-spam te voorkomen.\n\n"
          . "Voor context: bekijk login_pogingen en login_logs in de InlineComp-database.\n";
        $headers = "From: " . LOGIN_ALERT_MAIL_FROM . "\r\n"
                 . "Content-Type: text/plain; charset=utf-8\r\n";
        @mail(LOGIN_ALERT_MAIL_TO, $onderwerp, $body, $headers);
    } catch (Throwable) { /* fail silent — logging/mail mag nooit login breken */ }
}

function schrijfLog(PDO $pdo, ?int $userId, string $naam, string $username, string $actie): void {
    try {
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip   = $_SERVER['HTTP_X_FORWARDED_FOR']
             ?? $_SERVER['HTTP_X_REAL_IP']
             ?? $_SERVER['REMOTE_ADDR']
             ?? '';
        $ip   = trim(explode(',', $ip)[0]);
        $geo  = geoloceer($ip);
        $pdo->prepare("
            INSERT INTO login_logs
                (user_id, naam, username, actie, ip_adres, land, stad, browser, os, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$userId, $naam, $username, $actie, $ip,
                     $geo['land'], $geo['stad'],
                     parseerBrowser($ua), parseerOS($ua), $ua]);
    } catch (Throwable) { /* logging mag nooit de hoofd-flow blokkeren */ }
}

try {

    // ── GET me ───────────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'me') {
        $user = getSession($pdo);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Niet ingelogd']);
        } else {
            echo json_encode($user);
        }
        exit;
    }

    // ── POST login ───────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'login') {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $ip       = clientIp();

        if (!$username || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Gebruikersnaam en wachtwoord zijn verplicht']);
            exit;
        }

        // Verwijder verlopen sessies (huishouding) + oude login-poging-rijen
        // (rate-limit-tabel) ouder dan 1 dag — kort huis sneller cleanup-fail.
        $pdo->prepare("DELETE FROM sessions WHERE expires_at < NOW()")->execute();
        $pdo->prepare("DELETE FROM login_pogingen WHERE ts < NOW() - INTERVAL 1 DAY")->execute();

        // ── Rate-limit per IP ────────────────────────────────────────────────
        // ≥MAX mislukten in het venster → IP geweigerd tot venster verstrijkt.
        // Beschermt tegen brute-force vanaf één machine.
        $rlIpStmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_pogingen
             WHERE ip = ? AND success = 0
               AND ts > NOW() - INTERVAL " . LOGIN_RATE_VENSTER_MIN . " MINUTE
        ");
        $rlIpStmt->execute([$ip]);
        $nIp = (int)$rlIpStmt->fetchColumn();
        if ($nIp >= LOGIN_RATE_MAX_FAILED) {
            // Verzamel welke usernames concreet vanaf dit IP geprobeerd zijn.
            // Verschil tussen brute-force (alles op 1 user) en credential
            // stuffing (veel users) zit hier — direct zichtbaar in de mail.
            $uStmt = $pdo->prepare("
                SELECT username, COUNT(*) AS aantal
                  FROM login_pogingen
                 WHERE ip = ? AND success = 0
                   AND username IS NOT NULL AND username <> ''
                   AND ts > NOW() - INTERVAL " . LOGIN_RATE_VENSTER_MIN . " MINUTE
                 GROUP BY username
                 ORDER BY aantal DESC, MAX(ts) DESC
                 LIMIT 10
            ");
            $uStmt->execute([$ip]);
            $rows = $uStmt->fetchAll(PDO::FETCH_ASSOC);
            $details = '';
            $primary = '';
            if ($rows) {
                $primary = $rows[0]['username'];
                $details = implode(', ', array_map(
                    fn($r) => $r['username'] . ' (' . (int)$r['aantal'] . '×)',
                    $rows
                ));
            }
            stuurLoginAlert($pdo, 'ip_burst', $ip, $primary, $nIp, $details);
            header('Retry-After: ' . (LOGIN_RATE_VENSTER_MIN * 60));
            http_response_code(429);
            echo json_encode([
                'error' => 'Te veel mislukte inlogpogingen. Wacht ongeveer '
                         . LOGIN_RATE_VENSTER_MIN . ' minuten en probeer opnieuw.',
            ]);
            exit;
        }

        // ── Rate-limit per username ──────────────────────────────────────────
        // ≥MAX mislukten in het venster → username geweigerd vanaf elk IP.
        // Beschermt tegen distributed credential stuffing (botnet) waar
        // elk IP maar 1-2 pogingen doet maar de combinatie wel honderden
        // pogingen op één account doet. Symmetrische drempel.
        $rlUserStmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_pogingen
             WHERE username = ? AND success = 0
               AND ts > NOW() - INTERVAL " . LOGIN_RATE_VENSTER_MIN . " MINUTE
        ");
        $rlUserStmt->execute([$username]);
        $nUser = (int)$rlUserStmt->fetchColumn();
        if ($nUser >= LOGIN_RATE_MAX_FAILED) {
            stuurLoginAlert($pdo, 'user_burst', $ip, $username, $nUser);
            header('Retry-After: ' . (LOGIN_RATE_VENSTER_MIN * 60));
            http_response_code(429);
            echo json_encode([
                'error' => 'Dit account is tijdelijk geblokkeerd door te veel mislukte '
                         . 'inlogpogingen. Wacht ongeveer ' . LOGIN_RATE_VENSTER_MIN
                         . ' minuten en probeer opnieuw.',
            ]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND actief = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Mislukte poging vastleggen voor rate-limit én audit-log
            $pdo->prepare("INSERT INTO login_pogingen (ip, username, success) VALUES (?, ?, 0)")
                ->execute([$ip, $username]);
            schrijfLog($pdo, $user ? (int)$user['id'] : null,
                       $user['naam'] ?? '', $username, 'login_mislukt');

            // Anti-timing + anti-burst: random sleep 200-600ms vóór 401.
            // Verbergt of de username bestond (zelfde antwoordtijd ongeacht
            // user found/not found) en vertraagt brute-force flink.
            usleep(random_int(200000, 600000));

            http_response_code(401);
            echo json_encode(['error' => 'Gebruikersnaam of wachtwoord onjuist']);
            exit;
        }

        // Geslukte login → ook vastleggen voor audit-trail en "vorige login"-info
        $pdo->prepare("INSERT INTO login_pogingen (ip, username, success) VALUES (?, ?, 1)")
            ->execute([$ip, $username]);

        // Genereer sessie-token (64 hex tekens = 32 bytes)
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $pdo->prepare("INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)")
            ->execute([$token, $user['id'], $expiresAt]);

        // Stel cookie in (HttpOnly, SameSite=Strict)
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('ic_session', $token, [
            'expires'  => strtotime('+24 hours'),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        schrijfLog($pdo, (int)$user['id'], $user['naam'], $user['username'], 'login');

        echo json_encode([
            'ok'   => true,
            'user' => [
                'id'       => $user['id'],
                'username' => $user['username'],
                'naam'     => $user['naam'],
                'role'     => $user['role'],
            ],
        ]);
        exit;
    }

    // ── POST logout ──────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'logout') {
        $token = $_COOKIE['ic_session'] ?? '';
        if ($token) {
            // Zoek gebruikersinfo op vóór verwijderen sessie
            $s = $pdo->prepare("SELECT u.id, u.naam, u.username FROM sessions s
                                 JOIN users u ON u.id = s.user_id WHERE s.token = ?");
            $s->execute([$token]);
            $su = $s->fetch(PDO::FETCH_ASSOC);
            if ($su) schrijfLog($pdo, (int)$su['id'], $su['naam'], $su['username'], 'logout');
            $pdo->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
        }
        setcookie('ic_session', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── POST update_profiel ──────────────────────────────────────────────────
    // Self-service: ingelogde gebruiker werkt EIGEN naam + gebruikersnaam bij.
    // Vereist huidig wachtwoord ter bevestiging — voorkomt dat een gestolen
    // sessie de username vervangt waardoor de echte eigenaar niet meer kan
    // inloggen. Username moet uniek blijven binnen users-tabel.
    if ($method === 'POST' && $action === 'update_profiel') {
        $user = getSession($pdo);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Niet ingelogd']);
            exit;
        }
        $naam       = trim($body['naam']     ?? '');
        $username   = trim($body['username'] ?? '');
        $huidigPw   = $body['huidig_wachtwoord'] ?? '';
        if ($naam === '' || $username === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Naam en gebruikersnaam zijn verplicht']);
            exit;
        }
        if ($huidigPw === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Huidig wachtwoord is verplicht ter bevestiging']);
            exit;
        }
        // Hash op DB ophalen — getSession() geeft die niet noodzakelijk terug
        $vStmt = $pdo->prepare("SELECT id, password_hash, naam, username FROM users WHERE id = ? LIMIT 1");
        $vStmt->execute([$user['id']]);
        $vRow  = $vStmt->fetch(PDO::FETCH_ASSOC);
        if (!$vRow || !password_verify($huidigPw, $vRow['password_hash'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Huidig wachtwoord onjuist']);
            exit;
        }
        // Uniek-check op username (alleen als hij veranderd is)
        if (strcasecmp($vRow['username'], $username) !== 0) {
            $cStmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $cStmt->execute([$username, $user['id']]);
            if ($cStmt->fetchColumn()) {
                http_response_code(409);
                echo json_encode(['error' => 'Gebruikersnaam is al in gebruik']);
                exit;
            }
        }
        $pdo->prepare("UPDATE users SET naam = ?, username = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$naam, $username, $user['id']]);
        schrijfLog($pdo, (int)$user['id'], $naam, $username, 'profiel_aangepast');
        echo json_encode(['ok' => true, 'naam' => $naam, 'username' => $username]);
        exit;
    }

    // ── POST change_password ─────────────────────────────────────────────────
    // Self-service: ingelogde gebruiker wijzigt EIGEN wachtwoord. Vereist
    // huidig wachtwoord ter bevestiging (anti-misbruik bij gestolen sessie).
    if ($method === 'POST' && $action === 'change_password') {
        $user = getSession($pdo);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Niet ingelogd']);
            exit;
        }
        $huidigPw = $body['huidig_wachtwoord'] ?? '';
        $nieuwPw  = $body['nieuw_wachtwoord']  ?? '';
        if ($huidigPw === '' || $nieuwPw === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Huidig en nieuw wachtwoord zijn verplicht']);
            exit;
        }
        if (strlen($nieuwPw) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Nieuw wachtwoord moet minimaal 8 tekens zijn']);
            exit;
        }
        $vStmt = $pdo->prepare("SELECT id, password_hash, naam, username FROM users WHERE id = ? LIMIT 1");
        $vStmt->execute([$user['id']]);
        $vRow  = $vStmt->fetch(PDO::FETCH_ASSOC);
        if (!$vRow || !password_verify($huidigPw, $vRow['password_hash'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Huidig wachtwoord onjuist']);
            exit;
        }
        $hash = password_hash($nieuwPw, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$hash, $user['id']]);
        schrijfLog($pdo, (int)$user['id'], $vRow['naam'], $vRow['username'], 'wachtwoord_aangepast');
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
