<?php
// ============================================================
//  InlineComp – coach-account API (self-service coach-logins)
//
//  GET  ?action=me                 → huidig ingelogd coach-account (of 401)
//  GET  ?action=clubs_teams        → {clubs:[...], teams:[...]} voor registratie
//  POST ?action=register           → {naam,email,wachtwoord,coacht_van_type,coacht_van}
//  POST ?action=login              → {email,wachtwoord} → sessie-cookie
//  POST ?action=logout             → sessie beëindigen
//  POST ?action=wachtwoord_vergeten→ {email} → reset-mail (generieke respons)
//  POST ?action=wachtwoord_reset   → {token,wachtwoord} → nieuw wachtwoord
//
//  Coach-accounts staan LOS van de staf-`users` (zie coach_accounts.sql).
//  Registratie = pending tot een owner/admin goedkeurt; login mag al wél
//  (pending-coach bouwt intussen z'n roster op, perks komen bij goedkeuring).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/lib_coach_auth.php';
require_once __DIR__ . '/../inc/coach_mail.php';   // COACH_MAIL_* + coachMail() + mailteksten

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$body   = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = $_POST;
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function coachClientIp(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return trim(explode(',', $ip)[0]);
}

/** File-based sliding-window rate-limit per IP+actie. true = geblokkeerd. */
function coachRateLimited(string $actie, int $max, int $vensterSec): bool {
    $key  = sys_get_temp_dir() . '/rlcoachacc_' . md5(coachClientIp() . '|' . $actie);
    $now  = time();
    $hits = @json_decode(@file_get_contents($key), true);
    if (!is_array($hits)) $hits = [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $vensterSec));
    if (count($hits) >= $max) return true;
    $hits[] = $now;
    @file_put_contents($key, json_encode($hits), LOCK_EX);
    return false;
}

/** Vlag-emoji uit 2-letter landcode (zelfde als staff/jury). */
function coachLandVlag(string $code): string {
    if (strlen($code) !== 2) return '';
    $o = 0x1F1E6 - ord('A');
    return mb_chr(ord($code[0]) + $o, 'UTF-8') . mb_chr(ord($code[1]) + $o, 'UTF-8');
}

/** Geo-lookup uit IP (ip-api.com, korte timeout). Zelfstandig — net als jury/staff. */
function coachGeoloceer(string $ip): array {
    if (!$ip || filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['land' => 'Lokaal', 'stad' => ''];
    }
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,city&lang=nl';
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
    $vlag = coachLandVlag($geo['countryCode'] ?? '');
    return [
        'land' => ($vlag ? $vlag . ' ' : '') . ($geo['country'] ?? ''),
        'stad' => $geo['city'] ?? '',
    ];
}

/** Compacte browser/OS-extractie uit de user-agent. */
function coachBrowserOs(string $ua): array {
    $browser = 'Onbekend';
    if      (str_contains($ua, 'Edg/'))     $browser = 'Edge';
    elseif  (str_contains($ua, 'Chrome/'))  $browser = 'Chrome';
    elseif  (str_contains($ua, 'Firefox/')) $browser = 'Firefox';
    elseif  (str_contains($ua, 'Safari/'))  $browser = 'Safari';
    $os = '';
    if      (str_contains($ua, 'Android'))                          $os = 'Android';
    elseif  (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';
    elseif  (str_contains($ua, 'Windows NT'))                       $os = 'Windows';
    elseif  (str_contains($ua, 'Macintosh'))                        $os = 'macOS';
    elseif  (str_contains($ua, 'Linux'))                            $os = 'Linux';
    return ['browser' => $browser, 'os' => $os];
}

/** Logt een coach-event in login_logs (user_id=NULL, bron='coach') incl.
 *  locatie + browser/OS — belangrijk voor misbruik-detectie en support. */
function logCoachEvent(PDO $pdo, string $naam, string $email, string $actie): void {
    try {
        $ua  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 65535);
        $ip  = coachClientIp();
        $geo = coachGeoloceer($ip);
        $bo  = coachBrowserOs($ua);
        $pdo->prepare("
            INSERT INTO login_logs
                (user_id, naam, username, actie, ip_adres, land, stad, browser, os, bron, user_agent)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'coach', ?)
        ")->execute([
            $naam, $email, $actie, $ip,
            $geo['land'], $geo['stad'], $bo['browser'], $bo['os'], $ua,
        ]);
    } catch (Throwable) { /* logging mag nooit de flow breken */ }
}

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Vereist een ingelogde coach; eindigt met 401 zo niet. Geeft het account terug. */
function vereisCoachLogin(PDO $pdo): array {
    $c = getCoachSession($pdo);
    if (!$c) jsonOut(['error' => 'Niet ingelogd'], 401);
    return $c;
}

try {
    // ── GET me ──────────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'me') {
        // Bewust 200 met account=null bij geen sessie: dit is een "ben ik
        // ingelogd?"-check bij page-load; een 401 zou onnodig rood in de
        // console verschijnen. De frontend checkt gewoon op r.account.
        jsonOut(['account' => getCoachSession($pdo) ?: null]);
    }

    // ── GET clubs_teams (voor het registratieformulier) ──────────────────────
    if ($method === 'GET' && $action === 'clubs_teams') {
        if (coachRateLimited('clubs_teams', 30, 60)) jsonOut(['error' => 'Te veel verzoeken'], 429);
        $clubs = $pdo->query("
            SELECT DISTINCT club_full FROM persons
            WHERE club_full IS NOT NULL AND club_full <> '' AND anonymized_at IS NULL
              AND license_key NOT LIKE 'demo-%'   -- demo/test-clubs niet in autocomplete
            ORDER BY club_full
        ")->fetchAll(PDO::FETCH_COLUMN);
        $teams = $pdo->query("
            SELECT DISTINCT sponsor FROM persons
            WHERE sponsor IS NOT NULL AND sponsor <> '' AND anonymized_at IS NULL
              AND license_key NOT LIKE 'demo-%'   -- demo/test-teams niet in autocomplete
            ORDER BY sponsor
        ")->fetchAll(PDO::FETCH_COLUMN);
        jsonOut(['clubs' => $clubs, 'teams' => $teams]);
    }

    // ── POST register ────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'register') {
        if (coachRateLimited('register', 5, 3600)) jsonOut(['error' => 'Te veel registraties — probeer later'], 429);
        $naam  = trim($body['naam'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $pw    = (string)($body['wachtwoord'] ?? '');
        $type  = trim($body['coacht_van_type'] ?? '');
        $van   = trim($body['coacht_van'] ?? '');

        if ($naam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $van === '') {
            jsonOut(['error' => 'Vul naam, geldig e-mailadres, wachtwoord en "coach van" in.'], 400);
        }
        if (strlen($pw) < 8) jsonOut(['error' => 'Wachtwoord moet minstens 8 tekens zijn.'], 400);
        // "Coach van" vrij ingevuld → server bepaalt club/team/anders o.b.v. persons.
        if (!in_array($type, ['club', 'team', 'anders'], true)) {
            $q = $pdo->prepare("SELECT 1 FROM persons WHERE club_full = ? AND anonymized_at IS NULL LIMIT 1");
            $q->execute([$van]);
            if ($q->fetchColumn()) {
                $type = 'club';
            } else {
                $q = $pdo->prepare("SELECT 1 FROM persons WHERE sponsor = ? LIMIT 1");
                $q->execute([$van]);
                $type = $q->fetchColumn() ? 'team' : 'anders';
            }
        }

        $chk = $pdo->prepare("SELECT 1 FROM coach_accounts WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetchColumn()) jsonOut(['error' => 'Er bestaat al een account met dit e-mailadres.'], 409);

        $pdo->prepare("
            INSERT INTO coach_accounts (email, password_hash, naam, coacht_van_type, coacht_van)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$email, password_hash($pw, PASSWORD_DEFAULT), $naam, $type, $van]);

        // Owner op de hoogte brengen (same-domain adres → betrouwbare aflevering)
        $vanLabel = $type === 'club' ? 'Club' : ($type === 'team' ? 'Team' : 'Anders');
        coachMail(COACH_NOTIFY_MAIL_TO,
            'InlineComp — nieuwe coach-registratie',
            "Er is een nieuw coach-account aangevraagd en wacht op goedkeuring.\n\n"
          . "Naam        : $naam\n"
          . "E-mail      : $email\n"
          . "Coach van   : $vanLabel — $van\n\n"
          . "Keur goed of af in Beheer → Coach.\n");

        // Coach een bevestiging sturen dat de aanvraag is ontvangen.
        $mBev = coachMailBevestiging($naam);
        coachMail($email, $mBev['subject'], $mBev['body']);

        logCoachEvent($pdo, $naam, $email, 'coach-register');
        jsonOut(['ok' => true, 'status' => 'pending']);
    }

    // ── POST login ─────────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'login') {
        if (coachRateLimited('login', 10, 300)) jsonOut(['error' => 'Te veel pogingen — wacht even'], 429);
        $email = strtolower(trim($body['email'] ?? ''));
        $pw    = (string)($body['wachtwoord'] ?? '');
        if ($email === '' || $pw === '') jsonOut(['error' => 'E-mail en wachtwoord zijn verplicht.'], 400);

        $stmt = $pdo->prepare("SELECT * FROM coach_accounts WHERE email = ? AND actief = 1 LIMIT 1");
        $stmt->execute([$email]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || !password_verify($pw, $acc['password_hash'])) {
            logCoachEvent($pdo, $acc['naam'] ?? '', $email, 'coach-login-mislukt');
            usleep(random_int(200000, 600000));   // anti-timing (username-enumeratie)
            jsonOut(['error' => 'E-mailadres of wachtwoord onjuist.'], 401);
        }
        if ($acc['status'] === 'rejected') {
            jsonOut(['error' => 'Dit account is afgewezen. Neem contact op met de organisatie.'], 403);
        }

        maakCoachSessie($pdo, (int)$acc['id']);
        $pdo->prepare("UPDATE coach_accounts SET last_login_at = NOW() WHERE id = ?")->execute([$acc['id']]);
        logCoachEvent($pdo, $acc['naam'], $email, 'coach-login');

        jsonOut(['ok' => true, 'account' => [
            'id' => (int)$acc['id'], 'email' => $acc['email'], 'naam' => $acc['naam'],
            'status' => $acc['status'],
            'coacht_van_type' => $acc['coacht_van_type'], 'coacht_van' => $acc['coacht_van'],
        ]]);
    }

    // ── POST logout ────────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'logout') {
        $acc   = getCoachSession($pdo);   // vóór het wissen ophalen, voor de log
        $token = $_COOKIE[COACH_SESSION_COOKIE] ?? '';
        if ($token) $pdo->prepare("DELETE FROM coach_sessions WHERE token = ?")->execute([$token]);
        wisCoachSessieCookie();
        if ($acc) logCoachEvent($pdo, $acc['naam'] ?? '', $acc['email'] ?? '', 'coach-logout');
        jsonOut(['ok' => true]);
    }

    // ── POST account_verwijderen — coach wist z'n eigen account (AVG-recht) ─────
    // Cascade (coach_athletes/coach_sessions/coach_password_resets → ON DELETE
    // CASCADE) ruimt roster, sessies en reset-tokens automatisch mee op.
    if ($method === 'POST' && $action === 'account_verwijderen') {
        $c = vereisCoachLogin($pdo);
        // Naam/e-mail vóór de delete pakken — daarna is de rij weg.
        $naam = $c['naam'] ?? ''; $email = $c['email'] ?? '';
        $pdo->prepare("DELETE FROM coach_accounts WHERE id = ?")->execute([$c['id']]);
        wisCoachSessieCookie();
        logCoachEvent($pdo, $naam, $email, 'coach-account-verwijderd');
        // Bevestiging van de verwijdering naar de coach (AVG).
        if ($email) { $m = coachMailZelfVerwijderd($naam); coachMail($email, $m['subject'], $m['body']); }
        jsonOut(['ok' => true]);
    }

    // ── POST wachtwoord_vergeten ───────────────────────────────────────────────
    // Generieke respons ongeacht of het adres bestaat (geen e-mail-aftasten).
    if ($method === 'POST' && $action === 'wachtwoord_vergeten') {
        if (coachRateLimited('reset', 5, 3600)) jsonOut(['error' => 'Te veel aanvragen — probeer later'], 429);
        $email = strtolower(trim($body['email'] ?? ''));
        $generiek = ['ok' => true, 'message' => 'Als dit adres bij ons bekend is, sturen we een reset-link.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut($generiek);

        $stmt = $pdo->prepare("SELECT id, naam FROM coach_accounts WHERE email = ? AND actief = 1 LIMIT 1");
        $stmt->execute([$email]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($acc) {
            $raw  = bin2hex(random_bytes(32));                 // klare token → alleen in de mail
            $hash = hash('sha256', $raw);
            $exp  = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $pdo->prepare("INSERT INTO coach_password_resets (coach_account_id, token_hash, expires_at) VALUES (?, ?, ?)")
                ->execute([$acc['id'], $hash, $exp]);
            logCoachEvent($pdo, $acc['naam'] ?? '', $email, 'coach-reset-aangevraagd');
            coachMail($email,
                'InlineComp — wachtwoord opnieuw instellen',
                "Hoi {$acc['naam']},\n\n"
              . "Je hebt een nieuw wachtwoord aangevraagd voor je InlineComp coach-account.\n"
              . "Klik op de link hieronder om een nieuw wachtwoord in te stellen:\n\n"
              . COACH_RESET_URL . "?token=$raw\n\n"
              . "Deze link verloopt over 1 uur. Heb je dit niet aangevraagd? Negeer deze mail.\n");
        }
        jsonOut($generiek);
    }

    // ── POST wachtwoord_reset ──────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'wachtwoord_reset') {
        if (coachRateLimited('reset', 10, 3600)) jsonOut(['error' => 'Te veel pogingen — probeer later'], 429);
        $token = trim($body['token'] ?? '');
        $pw    = (string)($body['wachtwoord'] ?? '');
        if ($token === '' || strlen($pw) < 8) jsonOut(['error' => 'Ongeldige aanvraag of te kort wachtwoord (min. 8 tekens).'], 400);

        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare("
            SELECT id, coach_account_id FROM coach_password_resets
            WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1
        ");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonOut(['error' => 'Deze reset-link is ongeldig of verlopen. Vraag een nieuwe aan.'], 400);

        $pdo->prepare("UPDATE coach_accounts SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $row['coach_account_id']]);
        $pdo->prepare("UPDATE coach_password_resets SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);
        // Alle bestaande sessies van dit account intrekken (forceer opnieuw inloggen)
        $pdo->prepare("DELETE FROM coach_sessions WHERE coach_account_id = ?")->execute([$row['coach_account_id']]);
        $ai = $pdo->prepare("SELECT naam, email FROM coach_accounts WHERE id = ?");
        $ai->execute([$row['coach_account_id']]);
        $acc = $ai->fetch(PDO::FETCH_ASSOC) ?: ['naam' => '', 'email' => ''];
        logCoachEvent($pdo, $acc['naam'] ?? '', $acc['email'] ?? '', 'coach-reset');
        jsonOut(['ok' => true]);
    }

    // ── GET roster_list — de atleten van de ingelogde coach ────────────────────
    if ($method === 'GET' && $action === 'roster_list') {
        $c = vereisCoachLogin($pdo);
        $stmt = $pdo->prepare("
            SELECT p.license_key, p.full_name, p.club_full, p.category, p.birth_year, p.start_number, ca.added_at
            FROM   coach_athletes ca
            JOIN   persons p ON p.license_key = ca.person_license
            WHERE  ca.coach_account_id = ?
            ORDER  BY p.full_name
        ");
        $stmt->execute([$c['id']]);
        jsonOut(['roster' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── GET zoek_personen — kandidaten om aan de roster toe te voegen ──────────
    // Zoekt in de hele rijders-DB (elke persoon heeft geracet). in_roster geeft
    // aan of de rijder al in de roster van deze coach zit (voor de UI).
    if ($method === 'GET' && $action === 'zoek_personen') {
        $c = vereisCoachLogin($pdo);
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) jsonOut(['personen' => []]);   // te kort → geen zoekstorm
        $like = '%' . $q . '%';
        // Startnummer-zoek: NL-rijders hebben een vast startnummer (niet uniek —
        // kan per categorie hergebruikt worden), dus meerdere treffers mogelijk.
        $snr  = ctype_digit($q) ? (int)$q : -1;
        $stmt = $pdo->prepare("
            SELECT p.license_key, p.full_name, p.club_full, p.category, p.birth_year, p.start_number,
                   (ca.person_license IS NOT NULL) AS in_roster
            FROM   persons p
            LEFT JOIN coach_athletes ca
                   ON ca.person_license = p.license_key AND ca.coach_account_id = ?
            WHERE  p.anonymized_at IS NULL
              AND  p.license_key NOT LIKE 'demo-%'   -- demo/test-rijders nooit in coach-zoek
              AND  (p.full_name LIKE ? OR p.club_full LIKE ? OR p.start_number = ?)
            ORDER  BY p.full_name
            LIMIT  25
        ");
        $stmt->execute([$c['id'], $like, $like, $snr]);
        jsonOut(['personen' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── POST roster_add ────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'roster_add') {
        $c   = vereisCoachLogin($pdo);
        $lic = trim($body['person_license'] ?? '');
        if ($lic === '') jsonOut(['error' => 'person_license ontbreekt'], 400);
        $chk = $pdo->prepare("SELECT 1 FROM persons WHERE license_key = ? LIMIT 1");
        $chk->execute([$lic]);
        if (!$chk->fetchColumn()) jsonOut(['error' => 'Rijder niet gevonden'], 404);
        $pdo->prepare("INSERT IGNORE INTO coach_athletes (coach_account_id, person_license) VALUES (?, ?)")
            ->execute([$c['id'], $lic]);
        jsonOut(['ok' => true]);
    }

    // ── POST roster_remove ─────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'roster_remove') {
        $c   = vereisCoachLogin($pdo);
        $lic = trim($body['person_license'] ?? '');
        if ($lic === '') jsonOut(['error' => 'person_license ontbreekt'], 400);
        $pdo->prepare("DELETE FROM coach_athletes WHERE coach_account_id = ? AND person_license = ?")
            ->execute([$c['id'], $lic]);
        jsonOut(['ok' => true]);
    }

    // ── POST roster_leeg — hele atletenlijst in één keer wissen ────────────────
    if ($method === 'POST' && $action === 'roster_leeg') {
        $c = vereisCoachLogin($pdo);
        $pdo->prepare("DELETE FROM coach_athletes WHERE coach_account_id = ?")->execute([$c['id']]);
        jsonOut(['ok' => true]);
    }

    // ── POST roster_set — volledige vervanging (roster = deze licentie-lijst) ───
    // Gebruikt door de coach-app: ingelogd → de coach-lijst wordt hiermee naar
    // de DB gesynct (i.p.v. localStorage). Verwijdert wat weg is, voegt nieuw toe.
    if ($method === 'POST' && $action === 'roster_set') {
        $c    = vereisCoachLogin($pdo);
        $lics = $body['person_licenses'] ?? [];
        if (!is_array($lics)) $lics = [];
        $lics = array_values(array_unique(array_filter(array_map('trim', $lics), 'strlen')));
        $pdo->beginTransaction();
        try {
            if ($lics) {
                $ph = implode(',', array_fill(0, count($lics), '?'));
                $pdo->prepare("DELETE FROM coach_athletes WHERE coach_account_id = ? AND person_license NOT IN ($ph)")
                    ->execute(array_merge([$c['id']], $lics));
                $ins = $pdo->prepare("INSERT IGNORE INTO coach_athletes (coach_account_id, person_license)
                                      SELECT ?, license_key FROM persons WHERE license_key = ?");
                foreach ($lics as $lic) $ins->execute([$c['id'], $lic]);
            } else {
                $pdo->prepare("DELETE FROM coach_athletes WHERE coach_account_id = ?")->execute([$c['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOut(['ok' => true]);
    }

    // ── GET roster_hydrate — roster-rijders voor één wedstrijd ─────────────────
    // Per roster-rijder: startnummer + entry_status voor DEZE wedstrijd. NULL
    // entry_status = niet ingeschreven (zelfde signaal als /public).
    if ($method === 'GET' && $action === 'roster_hydrate') {
        $c = vereisCoachLogin($pdo);
        $compId = trim($_GET['competition_id'] ?? '');
        if ($compId === '') jsonOut(['riders' => []]);
        $stmt = $pdo->prepare("
            SELECT p.license_key, p.full_name, p.category, p.club_full, p.sponsor,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   (SELECT MAX(e.status) FROM entries e
                      JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                     WHERE e.person_license = p.license_key AND dc.competition_id = ?) AS entry_status
            FROM   coach_athletes ca
            JOIN   persons p ON p.license_key = ca.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = p.license_key AND cs.competition_id = ?
            WHERE  ca.coach_account_id = ?
            ORDER  BY p.full_name
        ");
        $stmt->execute([$compId, $compId, $c['id']]);
        jsonOut(['riders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── POST roster_add_groep — alle rijders van gekozen clubs/sponsors ────────
    if ($method === 'POST' && $action === 'roster_add_groep') {
        $c        = vereisCoachLogin($pdo);
        $clubs    = array_values(array_filter(array_map('trim', $body['clubs']    ?? []), 'strlen'));
        $sponsors = array_values(array_filter(array_map('trim', $body['sponsors'] ?? []), 'strlen'));
        if (!$clubs && !$sponsors) jsonOut(['ok' => true, 'toegevoegd' => 0]);
        $sub = []; $params = [$c['id']];
        if ($clubs) {
            $sub[]  = 'club_full IN (' . implode(',', array_fill(0, count($clubs), '?')) . ')';
            $params = array_merge($params, $clubs);
        }
        if ($sponsors) {
            $sub[]  = 'sponsor IN (' . implode(',', array_fill(0, count($sponsors), '?')) . ')';
            $params = array_merge($params, $sponsors);
        }
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO coach_athletes (coach_account_id, person_license)
            SELECT ?, license_key FROM persons
            WHERE anonymized_at IS NULL AND (" . implode(' OR ', $sub) . ")
        ");
        $stmt->execute($params);
        jsonOut(['ok' => true, 'toegevoegd' => $stmt->rowCount()]);
    }

    jsonOut(['error' => 'Onbekende actie'], 400);

} catch (Throwable $e) {
    jsonOut(['error' => 'Serverfout: ' . $e->getMessage()], 500);
}
