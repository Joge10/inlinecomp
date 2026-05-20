<?php
// ============================================================
//  InlineComp – Jury-app
//
//  Tablet-georiënteerd. Werkwijze:
//   1. Jury opent /jury → ziet lijst van wedstrijden waarvoor een
//      jury-wachtwoord is ingesteld (instelbaar via Beheer-module).
//   2. Klik op wedstrijd → modal met wachtwoord-veld.
//   3. Correct → sessie-cookie ICJURY, rolkeuze-scherm met 4 kaarten:
//        - Area of Call
//        - Aankomst-jury
//        - Scheidsrechter
//        - Starter
//   4. Klik op rol → placeholder-pagina (volgende ronde implementatie).
//
//  Sessie blijft staan tot browser sluit of expliciete logout.
//  Wachtwoord-check via password_verify tegen competitions.jury_password.
// ============================================================
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/jury_session.php';

$action = $_GET['action'] ?? '';

// ── Sessie-init (aparte cookie zodat coach/public/jury elkaar niet bijten) ──
if (session_status() === PHP_SESSION_NONE) {
    session_name('ICJURY');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
    @session_start();
}

// ── Audit-log: jury-activiteit naar login_logs ──────────────────────────────
// Schrijft naar dezelfde tabel als de organisator-login (api/auth.php),
// zodat owner/admin in Systeem → Logboek ook ziet wie zich als jury heeft
// aangemeld en op welke wedstrijd. user_id blijft NULL want jury heeft
// geen user-account; competition-naam staat in 'naam', comp_id in 'username'.

// Vlag-emoji uit 2-letter landcode (zelfde implementatie als api/auth.php).
function _juryLandVlag(string $code): string {
    if (strlen($code) !== 2) return '';
    $o = 0x1F1E6 - ord('A');
    return mb_chr(ord($code[0]) + $o, 'UTF-8') . mb_chr(ord($code[1]) + $o, 'UTF-8');
}

// Geo-lookup met dezelfde service (ip-api.com) en TTL als organisator-login.
// Korte timeout — als de service traag is, mag de jury-login daar niet op
// wachten; lege locatie is acceptabel.
function _juryGeoloceer(string $ip): array {
    if (!$ip || filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['land' => 'Lokaal', 'stad' => ''];
    }
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=country,countryCode,city&lang=nl';
    $geo = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) $geo = json_decode($resp, true) ?? [];
    } elseif (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http' => ['timeout' => 2]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp) $geo = json_decode($resp, true) ?? [];
    }
    if (!$geo || ($geo['status'] ?? '') === 'fail') return ['land' => '', 'stad' => ''];
    $vlag = _juryLandVlag($geo['countryCode'] ?? '');
    return [
        'land' => ($vlag ? $vlag . ' ' : '') . ($geo['country'] ?? ''),
        'stad' => $geo['city'] ?? '',
    ];
}

function _juryLog(PDO $pdo, string $actie, ?string $compNaam = null, ?string $compId = null): void {
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
        $ip = trim(explode(',', $ip)[0]);
        $geo = _juryGeoloceer($ip);
        // Compacte browser/OS-extractie — niet afhankelijk van api/auth.php
        $browser = 'Onbekend';
        if      (str_contains($ua, 'Edg/'))     $browser = 'Edge';
        elseif  (str_contains($ua, 'Chrome/'))  $browser = 'Chrome';
        elseif  (str_contains($ua, 'Firefox/')) $browser = 'Firefox';
        elseif  (str_contains($ua, 'Safari/'))  $browser = 'Safari';
        $os = '';
        if      (str_contains($ua, 'Android'))    $os = 'Android';
        elseif  (str_contains($ua, 'iPhone')
             || str_contains($ua, 'iPad'))        $os = 'iOS';
        elseif  (str_contains($ua, 'Windows NT')) $os = 'Windows';
        elseif  (str_contains($ua, 'Macintosh')) $os = 'macOS';
        elseif  (str_contains($ua, 'Linux'))      $os = 'Linux';

        $pdo->prepare("
            INSERT INTO login_logs
                (user_id, naam, username, actie, ip_adres, land, stad, browser, os, user_agent)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $compNaam ?? 'Jury',
            (string)($compId ?? ''),
            $actie,
            $ip,
            $geo['land'], $geo['stad'],
            $browser, $os, $ua,
        ]);
    } catch (Throwable) { /* logging mag nooit de hoofd-flow blokkeren */ }
}

// ── Rate-limit voor login (gedeeld wachtwoord = brute-force-vatbaar) ────────
function _juryRlCheck(string $bucket, int $max, int $perSec): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlFile = sys_get_temp_dir() . '/rljury_' . $bucket . '_' . md5($ip);
    $now    = time();
    $hits   = @json_decode(@file_get_contents($rlFile), true);
    if (!is_array($hits)) $hits = [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $perSec));
    if (count($hits) >= $max) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(429);
        echo json_encode(['error' => 'Te veel pogingen — wacht even']);
        exit;
    }
    $hits[] = $now;
    @file_put_contents($rlFile, json_encode($hits));
}

// ── Lazy cleanup: jury-wachtwoorden van oude wedstrijden wissen ─────────────
// Wedstrijden ouder dan JURY_PWD_RETENTIE_MAANDEN verliezen hun wachtwoord
// (kolom op NULL). Lazy = bij API-call die toch al gehit wordt door de jury-
// app, dus geen cron nodig op iFastNet. Throttle: max 1× per uur via een
// tmp-file flag, zodat we niet bij élke pageload een UPDATE doen.
const JURY_PWD_RETENTIE_MAANDEN = 3;
function _juryCleanupWachtwoorden(PDO $pdo): void {
    $flagFile = sys_get_temp_dir() . '/icjury_pwd_cleanup.flag';
    $lastRun  = @filemtime($flagFile) ?: 0;
    if (time() - $lastRun < 3600) return; // throttle: 1× per uur

    try {
        $maanden = JURY_PWD_RETENTIE_MAANDEN;
        $stmt = $pdo->prepare("
            UPDATE competitions
               SET jury_password = NULL
             WHERE jury_password IS NOT NULL
               AND starts < (NOW() - INTERVAL {$maanden} MONTH)
        ");
        $stmt->execute();
        $aantal = $stmt->rowCount();
        @touch($flagFile);
        if ($aantal > 0 && function_exists('logboekSchrijf')) {
            logboekSchrijf($pdo, null, 'jury_wachtwoord_auto_wis',
                ['aantal' => $aantal, 'retentie_maanden' => $maanden]);
        }
    } catch (Throwable $e) {
        // Cleanup mag nooit een pagina breken — silent fail + flag NIET touchen
        // zodat een volgende call opnieuw kan proberen.
        error_log('[jury-cleanup] ' . $e->getMessage());
    }
}

// ── API: lijst van wedstrijden met jury-wachtwoord ──────────────────────────
if ($action === 'competitions') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=30');
    try {
        // Eerst opruimen: oude wachtwoorden NULLen (throttled). Daarna ziet
        // de query meteen het juiste resultaat.
        _juryCleanupWachtwoorden($pdo);

        // Toon ALLE wedstrijden waarvan jury_password is ingesteld, ongeacht
        // public_zichtbaar/aankondigen. De jury mag verborgen wedstrijden zien:
        // het wachtwoord is op zichzelf de toegangsbeveiliging, en de jury
        // hoort soms al te kunnen voorbereiden vóór de wedstrijd publiekelijk
        // zichtbaar is. LIMIT 100 als safety-net tegen onbedoeld grote lijsten.
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.starts, c.ends,
                   c.organisatie_id AS org_id,
                   o.naam           AS org_naam,
                   o.logo_path      AS org_logo,
                   b.naam           AS baan_naam,
                   b.vereniging_naam AS baan_vereniging
            FROM competitions c
            LEFT JOIN organisaties o ON o.id = c.organisatie_id
            LEFT JOIN banen        b ON b.id = c.baan_id
            WHERE c.jury_password IS NOT NULL
              AND c.jury_password != ''
            ORDER BY c.starts DESC
            LIMIT 100
        ");
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: huidige sessie-status ──────────────────────────────────────────────
if ($action === 'session') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = $_SESSION['jury_comp_id'] ?? null;
    $role   = $_SESSION['jury_role']    ?? null;
    if (!$compId) { echo json_encode(['ingelogd' => false]); exit; }
    $stmt = $pdo->prepare("SELECT id, name, starts FROM competitions WHERE id = ? LIMIT 1");
    $stmt->execute([$compId]);
    $comp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp) {
        // Wedstrijd verwijderd terwijl sessie liep — clear en uitloggen
        $_SESSION = [];
        @session_destroy();
        echo json_encode(['ingelogd' => false]);
        exit;
    }
    echo json_encode([
        'ingelogd'    => true,
        'comp_id'     => $comp['id'],
        'comp_naam'   => $comp['name'],
        'comp_starts' => $comp['starts'],
        'role'        => $role,
    ]);
    exit;
}

// ── API: login ──────────────────────────────────────────────────────────────
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    _juryRlCheck('login', 5, 60);

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $compId = trim($body['competition_id'] ?? '');
    $pwd    = (string)($body['password'] ?? '');
    if ($compId === '' || $pwd === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Wedstrijd of wachtwoord ontbreekt']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT id, name, jury_password
        FROM competitions WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$compId]);
    $comp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp || empty($comp['jury_password'])) {
        _juryLog($pdo, 'jury-login-fail-noaccess', null, $compId);
        http_response_code(403);
        echo json_encode(['error' => 'Geen jury-toegang voor deze wedstrijd']);
        exit;
    }
    // Geen zichtbaarheids-gate: jury mag ook bij verborgen wedstrijden, mits
    // wachtwoord klopt. Het wachtwoord is de toegangsbeveiliging.
    if (!password_verify($pwd, $comp['jury_password'])) {
        _juryLog($pdo, 'jury-login-fail', $comp['name'], $compId);
        // Vertraging om timing-attacks + brute-force te ontmoedigen
        usleep(400000);
        http_response_code(401);
        echo json_encode(['error' => 'Onjuist wachtwoord']);
        exit;
    }
    $_SESSION['jury_comp_id'] = $comp['id'];
    $_SESSION['jury_role']    = null;
    $_SESSION['jury_auth_at'] = time();
    _juryLog($pdo, 'jury-login', $comp['name'], $comp['id']);
    echo json_encode(['ok' => true, 'comp_naam' => $comp['name']]);
    exit;
}

// ── API: rol kiezen ─────────────────────────────────────────────────────────
if ($action === 'set_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['jury_comp_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Niet ingelogd']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $role = trim($body['role'] ?? '');
    $geldig = ['area_of_call', 'aankomst', 'scheidsrechter', 'starter', 'speaker'];
    if (!in_array($role, $geldig, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige rol']);
        exit;
    }
    $_SESSION['jury_role'] = $role;
    // Comp-naam ophalen voor logregel — context bij audit-trail
    $cs = $pdo->prepare("SELECT name FROM competitions WHERE id = ? LIMIT 1");
    $cs->execute([$_SESSION['jury_comp_id']]);
    $compNaam = (string)$cs->fetchColumn();
    _juryLog($pdo, 'jury-rol-' . $role, $compNaam ?: null, $_SESSION['jury_comp_id']);
    echo json_encode(['ok' => true]);
    exit;
}

// ── API: logout ─────────────────────────────────────────────────────────────
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    // Comp-naam ophalen voor de logregel vóór sessie weg is
    $compId = $_SESSION['jury_comp_id'] ?? null;
    if ($compId) {
        $cs = $pdo->prepare("SELECT name FROM competitions WHERE id = ? LIMIT 1");
        $cs->execute([$compId]);
        $compNaam = (string)$cs->fetchColumn();
        _juryLog($pdo, 'jury-logout', $compNaam ?: null, $compId);
    }
    $_SESSION = [];
    @session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

// ── Area of Call helpers ────────────────────────────────────────────────────

// Gedeelde cache voor aoc_heats response. TTL kort (8s) — bij N parallelle
// jury-tablets vraagt er max één per 8s daadwerkelijk de DB op, de rest leest
// de cache. Schrijf-acties (toggle/baan-op/heropen) invalideren de cache zodat
// eigen wijzigingen meteen zichtbaar zijn. iFastNet rate-limit komt zo niet
// in de buurt, ook bij 10+ tablets.
const AOC_CACHE_TTL = 8;

function _aocCacheFile(string $compId): string {
    return sys_get_temp_dir() . '/icaoc_' . md5($compId) . '.json';
}
function _aocCacheLees(string $compId): ?string {
    $f = _aocCacheFile($compId);
    if (!is_file($f)) return null;
    if (time() - filemtime($f) >= AOC_CACHE_TTL) return null;
    $data = @file_get_contents($f);
    return is_string($data) ? $data : null;
}
function _aocCacheSchrijf(string $compId, string $json): void {
    @file_put_contents(_aocCacheFile($compId), $json);
}
function _aocCacheInvalideer(string $compId): void {
    @unlink(_aocCacheFile($compId));
}

// Vereist een ingelogde jury-sessie + rol area_of_call. Halt anders met JSON-fout.
function _aocRequire(): string {
    if (empty($_SESSION['jury_comp_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'Niet ingelogd als jury']);
        exit;
    }
    if (($_SESSION['jury_role'] ?? '') !== 'area_of_call') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['error' => 'Verkeerde rol — kies Area of Call']);
        exit;
    }
    return (string)$_SESSION['jury_comp_id'];
}

// Heat-lock: heeft één van de heat_entries al een finishpositie ingevuld?
// Dat betekent: aankomstjury is bezig — AoC mag alleen lezen.
function _aocHeatLocked(PDO $pdo, int $heatId): bool {
    $s = $pdo->prepare("
        SELECT 1 FROM results r
        JOIN heat_entries he ON he.id = r.heat_entry_id
        WHERE he.heat_id = ?
          AND r.finishpositie IS NOT NULL
        LIMIT 1
    ");
    $s->execute([$heatId]);
    return (bool)$s->fetchColumn();
}

// ── API: lijst van series-heats voor de actieve wedstrijd ──────────────────
if ($action === 'aoc_heats') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _aocRequire();

    // Cache-hit? Stuur meteen door — geen DB-werk nodig.
    $cached = _aocCacheLees($compId);
    if ($cached !== null) { echo $cached; exit; }

    try {
        // ALLE ronden krijgen AoC (series, kwartfinale, halve finale, finales).
        // race_type via JOIN met distances zodat frontend default-aantal-heats-
        // naast-elkaar kan bepalen (sprint=2, lange afstand=1).
        // BELANGRIJK: distances heeft een composite PK (id, distance_combination_id).
        // Joinen op alleen d.id geeft duplicates (één rij per DC die diezelfde
        // distance gebruikt). Daarom AND d.distance_combination_id = r.dc_id.
        $stmt = $pdo->prepare("
            SELECT h.id            AS heat_id,
                   h.aoc_sent_at,
                   r.id            AS rit_id,
                   r.rit_naam,
                   r.volgorde,
                   r.ronde_type,
                   r.dc_naam,
                   r.afstand_naam,
                   r.heat_nr,
                   r.verwacht      AS verwacht_aantal,
                   d.race_type     AS race_type
            FROM heats h
            JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
            LEFT JOIN distances     d ON d.id = r.distance_id
                                      AND d.distance_combination_id = r.dc_id
            WHERE h.competition_id = ?
            ORDER BY r.volgorde, r.heat_nr
        ");
        $stmt->execute([$compId]);
        $heats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$heats) { echo json_encode(['heats' => []]); exit; }

        // Per heat: rijders + AoC-status + lock-detectie
        $heatIds = array_column($heats, 'heat_id');
        $ph = implode(',', array_fill(0, count($heatIds), '?'));

        $rStmt = $pdo->prepare("
            SELECT he.id           AS heat_entry_id,
                   he.heat_id,
                   he.startpositie,
                   he.startnummer,
                   p.full_name,
                   COALESCE(aoc.status, 'onbekend') AS aoc_status,
                   res.finishpositie,
                   res.sanctie
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN area_of_call_aanwezigheid aoc ON aoc.heat_entry_id = he.id
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.heat_id IN ($ph)
            ORDER BY he.heat_id, he.startpositie
        ");
        $rStmt->execute($heatIds);
        $rijders = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Groepeer + lock per heat
        $rijdersPerHeat = [];
        $lockPerHeat    = [];
        foreach ($rijders as $r) {
            $hid = (int)$r['heat_id'];
            if (!isset($rijdersPerHeat[$hid])) {
                $rijdersPerHeat[$hid] = [];
                $lockPerHeat[$hid]    = false;
            }
            if ($r['finishpositie'] !== null) $lockPerHeat[$hid] = true;
            $rijdersPerHeat[$hid][] = [
                'heat_entry_id' => (int)$r['heat_entry_id'],
                'startpositie'  => (int)$r['startpositie'],
                'startnummer'   => $r['startnummer'],
                'naam'          => $r['full_name'],
                'aoc_status'    => $r['aoc_status'],
                'heeft_sanctie' => $r['sanctie'] !== null && $r['sanctie'] !== '',
                'sanctie'       => $r['sanctie'],
            ];
        }

        // Combineer
        foreach ($heats as &$h) {
            $hid = (int)$h['heat_id'];
            $h['heat_id']   = $hid;
            $h['rit_id']    = (int)$h['rit_id'];
            $h['volgorde']  = (int)$h['volgorde'];
            $h['heat_nr']   = $h['heat_nr'] !== null ? (int)$h['heat_nr'] : null;
            $h['verwacht_aantal'] = $h['verwacht_aantal'] !== null ? (int)$h['verwacht_aantal'] : null;
            $h['rijders']   = $rijdersPerHeat[$hid] ?? [];
            $h['locked']    = $lockPerHeat[$hid] ?? false;
        }
        unset($h);

        $json = json_encode(['heats' => $heats], JSON_UNESCAPED_UNICODE);
        _aocCacheSchrijf($compId, $json);
        echo $json;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: aanwezigheid van één rijder bijwerken ─────────────────────────────
if ($action === 'aoc_aanwezig' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _aocRequire();
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $hEntryId = (int)($body['heat_entry_id'] ?? 0);
    $status   = (string)($body['status'] ?? '');
    if (!$hEntryId || !in_array($status, ['onbekend','aanwezig','afwezig'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige aanvraag']);
        exit;
    }
    try {
        // Bescherming: heat_entry moet bij actieve wedstrijd horen + heat
        // mag niet locked zijn (= aankomstjury bezig).
        $s = $pdo->prepare("
            SELECT he.heat_id FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            WHERE he.id = ? AND h.competition_id = ? LIMIT 1
        ");
        $s->execute([$hEntryId, $compId]);
        $heatId = $s->fetchColumn();
        if (!$heatId) {
            http_response_code(403); echo json_encode(['error' => 'Heat-entry niet in deze wedstrijd']); exit;
        }
        if (_aocHeatLocked($pdo, (int)$heatId)) {
            http_response_code(409);
            echo json_encode(['error' => 'Heat is al verwerkt in live-module (locked)']);
            exit;
        }
        $u = $pdo->prepare("
            INSERT INTO area_of_call_aanwezigheid (heat_entry_id, status)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ");
        $u->execute([$hEntryId, $status]);
        _aocCacheInvalideer($compId);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: baan op gestuurd — heat afsluiten + DNS schrijven voor afwezigen ──
if ($action === 'aoc_baan_op' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _aocRequire();
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $heatId = (int)($body['heat_id'] ?? 0);
    if (!$heatId) {
        http_response_code(400); echo json_encode(['error' => 'heat_id ontbreekt']); exit;
    }
    try {
        // Heat moet bij actieve wedstrijd horen
        $s = $pdo->prepare("SELECT id FROM heats WHERE id = ? AND competition_id = ? LIMIT 1");
        $s->execute([$heatId, $compId]);
        if (!$s->fetchColumn()) {
            http_response_code(403); echo json_encode(['error' => 'Heat niet in deze wedstrijd']); exit;
        }
        if (_aocHeatLocked($pdo, $heatId)) {
            http_response_code(409); echo json_encode(['error' => 'Heat is al verwerkt in live-module']); exit;
        }

        $pdo->beginTransaction();

        // 1) Markeer heat als verzonden
        $pdo->prepare("UPDATE heats SET aoc_sent_at = NOW() WHERE id = ?")->execute([$heatId]);

        // 2) Voor elke afwezige rijder: schrijf DNS in results
        //    INSERT IGNORE met UPDATE-fallback omdat results al kan bestaan
        //    (bv. als coach al iets had voorbereid). Bij bestaande row: alleen
        //    sanctie aanvullen als die nog leeg is — niet overschrijven.
        $afwez = $pdo->prepare("
            SELECT he.id AS heat_entry_id
            FROM heat_entries he
            JOIN area_of_call_aanwezigheid aoc ON aoc.heat_entry_id = he.id
            WHERE he.heat_id = ? AND aoc.status = 'afwezig'
        ");
        $afwez->execute([$heatId]);
        $afwezIds = $afwez->fetchAll(PDO::FETCH_COLUMN);

        $dnsGezet = 0;
        if ($afwezIds) {
            // Per entry: voeg DNS toe als nog geen sanctie staat. Anders laat staan
            // (= ander oordeel van andere jury — niet overschrijven).
            $ins = $pdo->prepare("
                INSERT INTO results (heat_entry_id, sanctie)
                VALUES (?, 'DNS')
                ON DUPLICATE KEY UPDATE
                    sanctie = IF(sanctie IS NULL OR sanctie = '', 'DNS', sanctie)
            ");
            foreach ($afwezIds as $hid) {
                $ins->execute([$hid]);
                $dnsGezet++;
            }
        }

        $pdo->commit();
        _aocCacheInvalideer($compId);
        echo json_encode(['ok' => true, 'dns_gezet' => $dnsGezet]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: heat heropenen (zet aoc_sent_at terug op NULL) ────────────────────
// We laten reeds-gezette DNS-sancties staan — die kan de live-module zelf
// overrulen. Hertimmeren van DNS hier zou kunnen botsen met al-doorgevoerde
// jury-correcties.
if ($action === 'aoc_heropen' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _aocRequire();
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $heatId = (int)($body['heat_id'] ?? 0);
    if (!$heatId) {
        http_response_code(400); echo json_encode(['error' => 'heat_id ontbreekt']); exit;
    }
    try {
        $s = $pdo->prepare("SELECT id FROM heats WHERE id = ? AND competition_id = ? LIMIT 1");
        $s->execute([$heatId, $compId]);
        if (!$s->fetchColumn()) {
            http_response_code(403); echo json_encode(['error' => 'Heat niet in deze wedstrijd']); exit;
        }
        if (_aocHeatLocked($pdo, $heatId)) {
            http_response_code(409); echo json_encode(['error' => 'Heat is al verwerkt in live-module — heropenen niet meer mogelijk']); exit;
        }
        $pdo->prepare("UPDATE heats SET aoc_sent_at = NULL WHERE id = ?")->execute([$heatId]);
        _aocCacheInvalideer($compId);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Geen action → HTML pagina ───────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <meta name="theme-color" content="#1a3a5c">
    <title>InlineComp — Jury</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="stylesheet" href="jury.css">
</head>
<body>

<header class="jury-topbar">
    <div class="jury-topbar-inner">
        <div class="jury-brand">
            <span class="jury-brand-icon">⚖️</span>
            <span class="jury-brand-naam">Jury</span>
        </div>
        <div class="jury-comp-info" id="jury-comp-info" hidden>
            <div class="jury-comp-naam" id="jury-comp-naam"></div>
            <div class="jury-comp-meta" id="jury-comp-meta"></div>
        </div>
        <div class="jury-topbar-acties" id="jury-topbar-acties"></div>
    </div>
</header>

<main class="jury-main" id="jury-main">
    <div class="jury-laden">Laden…</div>
</main>

<!-- Login modal -->
<div class="jury-modal" id="jury-login-modal" hidden>
    <div class="jury-modal-inhoud" role="dialog" aria-labelledby="jury-login-titel">
        <h2 id="jury-login-titel">🔑 Jury-wachtwoord</h2>
        <div class="jury-login-comp" id="jury-login-comp"></div>
        <form id="jury-login-form" autocomplete="off">
            <label for="jury-login-pwd" class="jury-login-label">Wachtwoord</label>
            <input type="password" id="jury-login-pwd" class="jury-login-pwd"
                   inputmode="text" autocomplete="current-password" required>
            <div class="jury-login-fout" id="jury-login-fout" hidden></div>
            <div class="jury-login-acties">
                <button type="button" class="jury-btn jury-btn-secondary" id="jury-login-annuleer">Annuleren</button>
                <button type="submit"  class="jury-btn jury-btn-primary"   id="jury-login-ok">Inloggen</button>
            </div>
        </form>
    </div>
</div>

<script src="jury.js"></script>
</body>
</html>
