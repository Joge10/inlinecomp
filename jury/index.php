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
// No-cache: zie coach/index.php voor uitleg — voor jury extra belangrijk,
// stale jury.js of stale heat-data kan tot foute beslissingen leiden.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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

// ── API: list_pdfs — lijst PDF's uit /wedstrijdData/ ───────────────────────
// V1: één platte map, geen wedstrijd-filter. Toekomst (zie MEMORY): per-comp
// submappen + doelgroep-filter (jury/coach/public) via beheer-upload.
// Auth: alleen ingelogde jury-sessie ziet de lijst — voorkomt dat random
// bezoekers de map indexen via dit endpoint (de bestanden zelf zijn wel
// public via HTTP omdat /wedstrijdData/ in webroot staat — een gerichte
// directory-listing-protectie hoort op webserver-niveau, dit endpoint
// voegt alleen een extra UI-gate toe).
if ($action === 'list_pdfs') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['jury_comp_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'niet ingelogd']);
        exit;
    }
    $dir = realpath(__DIR__ . '/../wedstrijdData');
    if (!$dir || !is_dir($dir)) {
        echo json_encode(['pdfs' => [], 'map_aanwezig' => false]);
        exit;
    }
    $pdfs = [];
    foreach (glob($dir . '/*.pdf') ?: [] as $pad) {
        // Hidden files (._foo, .DS_Store) overslaan — komt voor bij Mac-uploads
        $naam = basename($pad);
        if ($naam === '' || $naam[0] === '.') continue;
        $pdfs[] = [
            'naam'      => $naam,
            'url'       => '../wedstrijdData/' . rawurlencode($naam),
            'size_kb'   => (int) round(filesize($pad) / 1024),
            'gewijzigd' => date('Y-m-d H:i', filemtime($pad)),
        ];
    }
    // Naam-sortering, case-insensitive, NL-locale (à/é etc. natuurlijk)
    usort($pdfs, fn($a, $b) => strnatcasecmp($a['naam'], $b['naam']));
    echo json_encode(['pdfs' => $pdfs, 'map_aanwezig' => true]);
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

// ════════════════════════════════════════════════════════════════════════════
//   SPEAKER-ROL (omroepen vanuit speakers-cabine)
// ════════════════════════════════════════════════════════════════════════════
//
// Helper: zelfde structuur als _aocRequire, maar checkt op 'speaker'-rol.
// Eerder probeerde ik _aocRequire te hergebruiken — die eist specifiek de
// area_of_call-rol, dus speaker-requests kregen 403 met 'Verkeerde rol'.
function _speakerRequire(): string {
    if (empty($_SESSION['jury_comp_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'Niet ingelogd als jury']);
        exit;
    }
    if (($_SESSION['jury_role'] ?? '') !== 'speaker') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['error' => 'Verkeerde rol — kies Speaker']);
        exit;
    }
    return (string)$_SESSION['jury_comp_id'];
}

// ── API: cats + DCs structuur voor de tab-balk ─────────────────────────────
// Niveau 1 = persons.category (DSA, HSA, DKA, etc.) uit entries van deze
// wedstrijd. Niveau 2 = lijst van DCs waar rijders in die cat in zitten.
// Aantal-veld per DC = aantal "meedoende" entries (status IN 1, 5; geen
// reserves). Speaker kan dan in één oogopslag zien welke cats/DCs leeg
// zijn en welke vol zitten.
if ($action === 'speaker_struktuur') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _speakerRequire();
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.category                       AS cat,
                dc.id                            AS dc_id,
                dc.name                          AS dc_naam,
                dc.number                        AS dc_number,
                COUNT(*)                         AS aantal
            FROM entries e
            JOIN persons p              ON p.license_key = e.person_license
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE dc.competition_id = ?
              AND e.status IN (1, 5)
              AND e.reserve IS NULL
              AND p.category IS NOT NULL
              AND p.category <> ''
            GROUP BY p.category, dc.id, dc.name, dc.number
            ORDER BY p.category, dc.number, dc.name
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Groepeer naar { cats: [ {cat, dcs: [{dc_id, dc_naam, aantal}]} ] }
        $catMap = [];
        foreach ($rows as $r) {
            $c = $r['cat'];
            if (!isset($catMap[$c])) $catMap[$c] = [];
            $catMap[$c][] = [
                'dc_id'   => $r['dc_id'],
                'dc_naam' => $r['dc_naam'],
                'aantal'  => (int)$r['aantal'],
            ];
        }
        $cats = [];
        foreach ($catMap as $c => $dcs) {
            $cats[] = ['cat' => $c, 'dcs' => $dcs];
        }

        // Sorteren op KNSB-categorie-volgorde (jongste → oudste), niet alfabetisch.
        // Volgorde: per leeftijd eerst Dames, dan Heren, dan volgende leeftijd.
        // Bv: DP4, HP4, DP3, HP3 … DKA, HKA, DJB, HJB, DJA, HJA, DSJ, HSJ, DSA,
        // HSA, DSB, HSB, DM40, HM40, DM45, HM45 … HM95.
        // Sort-key: age_rank * 10 + gender_rank → leeftijd is primary, geslacht
        // alleen tiebreaker (D=0 zodat dames vóór heren binnen elke leeftijd).
        //
        // Master-cats (M40 t/m M95+) komen na DSA/HSA/DSB/HSB. De KNSB-feed
        // schrijft soms 'HM40', soms 'M40' — beide tolerant gepakt via regex.
        // 'M40' zonder prefix wordt als heren behandeld (in praktijk zijn
        // masters meestal heren; expliciete DM40 voor dames-masters werkt
        // gewoon en wordt vóór HM40 gesorteerd zoals bij alle andere cats).
        $catSortKey = function(string $cat): int {
            $cat = strtoupper(trim($cat));

            // Master-cat patroon: optioneel H/D-prefix + M + 2-3 cijfers
            // (M40..M99, ook M100+ tolereren mocht 't ooit voorkomen).
            if (preg_match('/^([HD]?)M(\d{2,3})$/', $cat, $m)) {
                $genderRank = match($m[1]) {
                    'D' => 0, 'H' => 1, default => 1,  // geen prefix = heren-default
                };
                $leeftijd = (int)$m[2];
                if ($leeftijd >= 40) {
                    // M40 → age_rank 10, M45 → 11, M50 → 12 … M95 → 21
                    $ageRank = 10 + intdiv($leeftijd - 40, 5);
                    return $ageRank * 10 + $genderRank;
                }
                // M-leeftijd < 40: ongeldig, val terug op default-positie achteraan
            }

            // Standaard cats met H/D-prefix
            $genderRank = match(substr($cat, 0, 1)) {
                'D' => 0, 'H' => 1, default => 9,
            };
            $sub = substr($cat, 1);  // 'P4', 'KA', 'JB', 'SA', 'SB', etc.
            $ageRank = match($sub) {
                'P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3,
                'KA' => 4, 'JB' => 5, 'JA' => 6,
                'SJ' => 7, 'SA' => 8, 'SB' => 9,
                default => 99,
            };
            return $ageRank * 10 + $genderRank;
        };
        usort($cats, fn($a, $b) => $catSortKey($a['cat']) <=> $catSortKey($b['cat']));

        echo json_encode(['cats' => $cats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: deelnemers voor gekozen (DC, cat) ─────────────────────────────────
// Stuurt alle "meedoende" entries (status 1 of 5, geen reserve) voor de
// gegeven DC, gefilterd op rijders met persons.category = ?cat. Alle persons-
// velden meesturen zodat het detail-popup geen extra request hoeft te doen.
// Sortering: startnummer ASC zodat tegels in de gangbare omroep-volgorde
// staan; rijders zonder startnummer achteraan op naam.
if ($action === 'speaker_deelnemers') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _speakerRequire();
    $dcId   = trim($_GET['dc_id'] ?? '');
    $cat    = trim($_GET['cat']   ?? '');
    if ($dcId === '' || $cat === '') {
        http_response_code(400);
        echo json_encode(['error' => 'dc_id en cat zijn verplicht']);
        exit;
    }
    try {
        // Bevestig dat DC bij deze wedstrijd hoort (anders kan iemand
        // via brute force DC-IDs van andere wedstrijden uitlezen).
        $check = $pdo->prepare("SELECT 1 FROM distance_combinations WHERE id = ? AND competition_id = ?");
        $check->execute([$dcId, $compId]);
        if (!$check->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'DC hoort niet bij deze wedstrijd']);
            exit;
        }

        // Startnummer kan lokale override hebben (competition_startnummers).
        // COALESCE prefereert de override boven persons.start_number.
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(csn.startnummer, p.start_number) AS startnummer,
                p.license_key,
                p.full_name,
                p.short_name,
                p.category,
                p.birth_year,
                p.gender,
                p.nationality,
                p.club_full,
                p.club_short,
                p.sponsor,
                p.city,
                e.status AS entry_status
            FROM entries e
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers csn
                   ON csn.competition_id = ? AND csn.person_license = p.license_key
            WHERE e.distance_combination_id = ?
              AND e.status IN (1, 5)
              AND e.reserve IS NULL
              AND p.category = ?
            ORDER BY
                CASE WHEN COALESCE(csn.startnummer, p.start_number) IS NULL THEN 1 ELSE 0 END,
                COALESCE(csn.startnummer, p.start_number),
                p.full_name
        ");
        $stmt->execute([$compId, $dcId, $cat]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Type-cleanup
        foreach ($rows as &$r) {
            if ($r['startnummer'] !== null) $r['startnummer'] = (int)$r['startnummer'];
            if ($r['birth_year']  !== null) $r['birth_year']  = (int)$r['birth_year'];
            if ($r['gender']      !== null) $r['gender']      = (int)$r['gender'];
            $r['entry_status']                  = (int)$r['entry_status'];
        }
        unset($r);

        echo json_encode(['deelnemers' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: speaker kans-score (1-10) per rijder in DC ─────────────────────
// Speaker wil per rijder zien hoe waarschijnlijk podium is, gebaseerd op
// historische prestaties op vergelijkbare afstand-groep.
//
// Afstand-groep van huidige DC:
//   - 'ultra_sprint': value_meters < 500 EN race_type='sprint' (200m, 300m)
//   - 'sprint':       race_type='sprint' EN (value_meters>=500 of NULL) (500m, 1000m)
//   - 'lang':         race_type IN inline/afvalkoers/puntenkoers
//
// Algoritme:
//   1. Verzamel uitslag_afstand-rijen per deelnemer voor dezelfde groep
//      (NIET klassement — vervuilt, mengt korte+lange afstanden)
//   2. Per rij: rang_punten × recentheid_gewicht
//        rang_punten: 1→10, 2→7, 3→5, 4-6→3, 7-10→1, else→0
//        gewicht: 0-6m→1.0, 6-12m→0.5, 1-2j→0.4, 2-3j→0.3, 3-4j→0.2, >4j→0.1
//   3. Sommeer per rijder = ruwe_score
//   4. Normaliseer RELATIEF in DC: maxRuw→10, minRuw→1, lineair daartussen
//   5. Rijders zonder historie krijgen score=null (= onbekend, badge ❔)
//
// Categorie-context: ALLE cats meegerekend (inline-historie is veelzeggend
// ook over cat-grenzen — kwaliteit komt naar boven). Geen cat-penalty in V1.
//
// Multi-distance DC (KA/JB combi 200m+500m): groep wordt bepaald op
// kleinste value_meters distance — meest specifiek. Voor multi-distance
// fine-tuning komt later. V1: één score per rijder per DC.
if ($action === 'speaker_kans') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _speakerRequire();
    $dcId = trim($_GET['dc_id'] ?? '');
    $cat  = trim($_GET['cat']   ?? '');
    if ($dcId === '' || $cat === '') {
        http_response_code(400);
        echo json_encode(['error' => 'dc_id en cat zijn verplicht']);
        exit;
    }
    try {
        // DC-veiligheidscheck (zelfde als speaker_deelnemers)
        $check = $pdo->prepare("SELECT 1 FROM distance_combinations WHERE id = ? AND competition_id = ?");
        $check->execute([$dcId, $compId]);
        if (!$check->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'DC hoort niet bij deze wedstrijd']);
            exit;
        }

        // Bepaal organisatie van huidige wedstrijd. Filter straks historie
        // op zelfde organisatie zodat regio-wedstrijden niet vervuilen bij
        // landelijke wedstrijden (NK krijgt alleen KNSB-historie, geen
        // club-uitslagen tussendoor). Als comp geen organisatie heeft (NULL):
        // geen filter — meet alle historie mee (geen onderscheid mogelijk).
        $orgStmt = $pdo->prepare("SELECT organisatie_id FROM competitions WHERE id = ?");
        $orgStmt->execute([$compId]);
        $orgId = $orgStmt->fetchColumn();
        $orgId = ($orgId !== false && $orgId !== null && $orgId !== '') ? $orgId : null;

        // 1. Bepaal afstand-groepen voor ALLE distances in DC (sinds 2026-05-27).
        // Combo-DCs (KA/JB landelijke wedstrijd met 200m+500m) hebben beide
        // afstanden — vroeger pakten we alleen de eerste, waardoor de tweede
        // groep niet meetelde in historie-lookup. Nu nemen we alle unieke
        // groepen mee zodat alle relevante prestaties bijdragen aan de score.
        $ds = $pdo->prepare("
            SELECT value_meters, race_type
            FROM distances
            WHERE distance_combination_id = ?
        ");
        $ds->execute([$dcId]);
        $dcDists = $ds->fetchAll(PDO::FETCH_ASSOC);
        $groepenSet = [];   // set van unieke groep-strings
        foreach ($dcDists as $d) {
            $vm = ($d['value_meters'] !== null) ? (int)$d['value_meters'] : null;
            $rt = $d['race_type'] ?? '';
            $g = null;
            if ($rt === 'sprint' && $vm !== null && $vm < 500) {
                $g = 'ultra_sprint';
            } elseif ($rt === 'sprint') {
                $g = 'sprint';
            } elseif (in_array($rt, ['inline', 'afvalkoers', 'puntenkoers'], true)) {
                $g = 'lang';
            }
            if ($g) $groepenSet[$g] = true;
        }
        $groepen = array_keys($groepenSet);
        if (empty($groepen)) {
            echo json_encode(['rijders' => [], 'groepen' => [], 'reden' => 'onbekende afstand-groep']);
            exit;
        }

        // 2. Get deelnemers in DC+cat
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.license_key
            FROM entries e
            JOIN persons p ON p.license_key = e.person_license
            WHERE e.distance_combination_id = ?
              AND p.category = ?
              AND e.status IN (1, 5)
              AND e.reserve IS NULL
        ");
        $stmt->execute([$dcId, $cat]);
        $deelnemers = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'license_key');
        if (empty($deelnemers)) {
            echo json_encode(['rijders' => [], 'groep' => $groep]);
            exit;
        }

        // 3. Get historische uitslag_afstand voor deze rijders binnen alle
        // relevante groepen (OR-conditie over alle DC's groepen). JOIN
        // distances om race_type/value_meters per (DC, distance_id) op te halen.
        $condities = [];
        foreach ($groepen as $g) {
            $condities[] = match ($g) {
                'ultra_sprint' => "(d.race_type = 'sprint' AND d.value_meters IS NOT NULL AND d.value_meters < 500)",
                'sprint'       => "(d.race_type = 'sprint' AND (d.value_meters IS NULL OR d.value_meters >= 500))",
                'lang'         => "(d.race_type IN ('inline', 'afvalkoers', 'puntenkoers'))",
            };
        }
        $groepConditie = '(' . implode(' OR ', $condities) . ')';
        $ph = implode(',', array_fill(0, count($deelnemers), '?'));
        // Organisatie-filter: JOIN met competitions, beperk tot dezelfde org
        // als huidige wedstrijd. Voorkomt vervuiling door regio/club-uitslagen
        // bij landelijke wedstrijden (NK telt alleen KNSB-historie mee).
        // Bij comp zonder organisatie (NULL): geen filter — alles meenemen.
        $orgJoin   = $orgId !== null ? 'JOIN competitions c ON c.id = ua.competition_id' : '';
        $orgFilter = $orgId !== null ? 'AND c.organisatie_id = ?' : '';
        $stmt = $pdo->prepare("
            SELECT ua.person_license, ua.competition_datum, ua.rang
            FROM uitslag_afstand ua
            JOIN distances d
                 ON d.id = ua.distance_id
                AND d.distance_combination_id = ua.distance_combination_id
            {$orgJoin}
            WHERE ua.person_license IN ($ph)
              AND ua.rang IS NOT NULL
              AND ({$groepConditie})
              {$orgFilter}
        ");
        $params = $deelnemers;
        if ($orgId !== null) $params[] = $orgId;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Bereken ruwe_score per rijder + aantal historie-rijen
        $nu = new DateTimeImmutable();
        $ruwScores   = array_fill_keys($deelnemers, 0.0);
        $aantalRijen = array_fill_keys($deelnemers, 0);
        foreach ($rows as $r) {
            $rang = (int)$r['rang'];
            $rangPunten = match (true) {
                $rang === 1 => 10,
                $rang === 2 => 7,
                $rang === 3 => 5,
                $rang <= 6  => 3,
                $rang <= 10 => 1,
                default     => 0,
            };
            $maanden = null;
            if (!empty($r['competition_datum'])) {
                try {
                    $dt = new DateTimeImmutable($r['competition_datum']);
                    $diff = $nu->diff($dt);
                    $maanden = $diff->y * 12 + $diff->m;
                } catch (Throwable $e) {}
            }
            // Recentheid-gewicht: steile afval na 6 maanden. Recente uitslagen
            // zeggen veel meer over actuele vorm dan oude — vooral bij junioren
            // die snel in cat-niveau stijgen en in vorm fluctueren. Curve:
            //   <6m    → 1.0  (huidig seizoen, volle weging)
            //   6-12m  → 0.5  (vorig seizoen, halveert)
            //   1-2j   → 0.4
            //   2-3j   → 0.3
            //   3-4j   → 0.2
            //   >4j    → 0.1  (historisch, marginale invloed)
            //   null   → 0.1  (datum onbekend → conservatief als 'oud')
            $gewicht = match (true) {
                $maanden === null => 0.1,
                $maanden < 6      => 1.0,
                $maanden < 12     => 0.5,
                $maanden < 24     => 0.4,
                $maanden < 36     => 0.3,
                $maanden < 48     => 0.2,
                default           => 0.1,
            };
            $lk = $r['person_license'];
            $ruwScores[$lk]   += $rangPunten * $gewicht;
            $aantalRijen[$lk] += 1;
        }

        // 5. Normaliseer RELATIEF in DC. Alleen rijders MET historie krijgen
        // een score; rijders zonder historie blijven null (badge ❔).
        $metHist = array_filter($deelnemers, fn($lk) => $aantalRijen[$lk] > 0);
        $maxRuw = !empty($metHist)
            ? max(array_map(fn($lk) => $ruwScores[$lk], $metHist))
            : 0;
        $minRuw = !empty($metHist)
            ? min(array_map(fn($lk) => $ruwScores[$lk], $metHist))
            : 0;
        $range = $maxRuw - $minRuw;

        $result = [];
        foreach ($deelnemers as $lk) {
            $ruw = $ruwScores[$lk];
            $aantal = $aantalRijen[$lk];
            $score = null;
            $reden = '';
            if ($aantal === 0) {
                $reden = 'geen historie op deze afstand-groep';
            } elseif ($range == 0) {
                // Alle scores gelijk → iedereen middenmoot
                $score = 5;
                $reden = sprintf('%d wedstrijden, allen gelijke ruwe score %.1f', $aantal, $ruw);
            } else {
                $score = (int)round((($ruw - $minRuw) / $range) * 9) + 1;
                $reden = sprintf('%d wedstrijden · ruwe score %.1f', $aantal, $ruw);
            }
            $result[] = [
                'license_key' => $lk,
                'score'       => $score,
                'reden'       => $reden,
            ];
        }

        echo json_encode([
            'rijders'        => $result,
            'groepen'        => $groepen,
            'organisatie_id' => $orgId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijder-historie voor speaker-detail-modal ─────────────────────────
// Levert alle uitslag_klassement-rijen voor een rijder (alle DCs / alle
// wedstrijden), gesorteerd nieuw → oud. Speaker toont daarmee podium-
// finishes (rang 1-3) als hoogtepunt, daarna de rest voor context.
//
// Vereist alleen een geldige speaker-sessie + license_key. De rijder hoeft
// niet per se in de huidige wedstrijd te zitten (speaker mag elke rijder
// die ooit gereden heeft opzoeken — uitslagen zijn publieke historie).
if ($action === 'speaker_historie') {
    header('Content-Type: application/json; charset=utf-8');
    _speakerRequire();  // alleen toegang voor ingelogde jury
    $lk = trim($_GET['license_key'] ?? '');
    if ($lk === '') {
        http_response_code(400);
        echo json_encode(['error' => 'license_key is verplicht']);
        exit;
    }
    try {
        // Historie = uitsluitend per-afstand uitslagen (uitslag_afstand).
        // Speaker vergelijkt met de afstand die nu wordt gereden, dus
        // dag-eindklassement is irrelevant (kan niet vergeleken worden met
        // één-afstand-prestatie) en alleen verwarrende noise. Sinds
        // 2026-05-27 dus klassement-fallback verwijderd — als er voor een
        // wedstrijd alleen klassement-data bestaat zonder per-afstand
        // uitslagen, dan komt die wedstrijd niet voor in de speaker-modal.
        // Punten_totaal kolom blijft in de output (altijd NULL) zodat de
        // frontend-render-code ongewijzigd kan blijven.
        $stmt = $pdo->prepare("
            SELECT
                ua.competition_naam,
                ua.competition_datum,
                ua.dc_naam,
                ua.distance_naam,
                ua.categorie,
                ua.split_group,
                ua.rang,
                ua.tijd_ms,
                NULL          AS punten_totaal
            FROM uitslag_afstand ua
            WHERE ua.person_license = ?
            -- Sorteren op uitslag-positie (laag = beter). Binnen podium-sectie:
            -- eerst alle goud, dan zilver, dan brons. Binnen Overige-sectie:
            -- rang 4, 5, 6, ... oplopend. NULL-rangen (DQ/DNS zonder positie)
            -- achteraan. Bij gelijke rang: chronologisch oud → recent.
            ORDER BY rang IS NULL, rang ASC, competition_datum ASC, competition_naam, dc_naam, distance_naam
        ");
        $stmt->execute([$lk]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Type-cleanup + leesbare datum
        foreach ($rows as &$r) {
            $r['rang']          = $r['rang']          !== null ? (int)$r['rang']           : null;
            $r['punten_totaal'] = $r['punten_totaal'] !== null ? (float)$r['punten_totaal'] : null;
        }
        unset($r);

        echo json_encode(['historie' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: volledig overzicht van alle eerdere wedstrijden ──────────────────
// Voor de bottom-bar cascade-dropdowns. Eén call levert per eerdere
// wedstrijd alle (DC × distance) combinaties met de cats die erin
// voorkomen. Niet gefilterd op huidige cat — speaker wil juist kunnen
// vergelijken tussen cats (bv. NK-junioren-uitslag boven senioren-tegels).
//
// Excludeert de huidige wedstrijd (= speaker's sessie). Bron =
// uitslag_afstand zodat speaker per specifieke afstand binnen een DC
// kan kiezen (multi-distance DCs).
if ($action === 'speaker_eerdere_overzicht') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _speakerRequire();
    try {
        // De HUIDIGE wedstrijd wordt NIET meer uitgesloten: een afstand die al
        // verreden (en vastgelegd/geïmporteerd) is binnen deze wedstrijd is voor
        // de speaker waardevolle context (bv. 200m-uitslag bij het omroepen van
        // de 500m). De huidige wedstrijd komt bovenaan en wordt gemarkeerd
        // (is_huidige) zodat de frontend 'm kan labelen.
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                c.id                       AS comp_id,
                c.name                     AS comp_naam,
                c.starts                   AS comp_starts,
                ua.distance_combination_id AS dc_id,
                ua.dc_naam,
                ua.distance_id,
                ua.distance_naam,
                ua.categorie               AS cat
            FROM uitslag_afstand ua
            JOIN competitions c ON c.id = ua.competition_id
            WHERE ua.categorie IS NOT NULL
              AND ua.categorie <> ''
            ORDER BY (c.id = ?) DESC, c.starts DESC, c.name, ua.dc_naam, ua.distance_naam, ua.categorie
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Groepeer naar { wedstrijden: [ {comp, afstanden: [ {dc/dist, cats:[..]} ]} ] }
        $wMap = []; // comp_id → { comp..., afstanden: [dc_id|distance_id → {dc/dist, cats}] }
        foreach ($rows as $r) {
            $cid = $r['comp_id'];
            if (!isset($wMap[$cid])) {
                $wMap[$cid] = [
                    'comp_id'     => $cid,
                    'comp_naam'   => $r['comp_naam'],
                    'comp_starts' => $r['comp_starts'],
                    'is_huidige'  => ($cid === $compId),
                    '_afst'       => [],
                ];
            }
            // Afstand-key: dc_id + distance_id zodat we per fysieke afstand
            // binnen een DC kunnen onderscheiden (multi-distance DCs).
            $aKey = $r['dc_id'] . '|' . ($r['distance_id'] ?? '');
            if (!isset($wMap[$cid]['_afst'][$aKey])) {
                $wMap[$cid]['_afst'][$aKey] = [
                    'dc_id'         => $r['dc_id'],
                    'dc_naam'       => $r['dc_naam'],
                    'distance_id'   => $r['distance_id'],
                    'distance_naam' => $r['distance_naam'],
                    'cats'          => [],
                ];
            }
            if (!in_array($r['cat'], $wMap[$cid]['_afst'][$aKey]['cats'], true)) {
                $wMap[$cid]['_afst'][$aKey]['cats'][] = $r['cat'];
            }
        }
        // Flatten — _afst → afstanden (numeric array)
        $wedstrijden = [];
        foreach ($wMap as $w) {
            $w['afstanden'] = array_values($w['_afst']);
            unset($w['_afst']);
            $wedstrijden[] = $w;
        }
        echo json_encode(['wedstrijden' => $wedstrijden], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: top-3 uitslag voor (wedstrijd × DC × distance) ────────────────────
// Voor de bottom-bar onderste regel: podium-finishers van de gekozen
// historische afstand. Bron = uitslag_afstand voor per-afstand-rang.
// GEEN cat-filter: rang is per-DC (= per race), dus top-3 is de daad-
// werkelijke race-podium ongeacht welke cats meereden. Als DSA+DSJ samen
// in één DC racen, geeft rang=1 de echte winnaar (welke cat dan ook).
// De cat-dropdown in de frontend is alleen UI-filter om relevante
// afstanden te tonen, niet om uitslag te filteren.
if ($action === 'speaker_eerdere_top3') {
    header('Content-Type: application/json; charset=utf-8');
    _speakerRequire();
    $compId = trim($_GET['comp_id']     ?? '');
    $dcId   = trim($_GET['dc_id']       ?? '');
    $distId = trim($_GET['distance_id'] ?? '');
    if ($compId === '' || $dcId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'comp_id en dc_id zijn verplicht']);
        exit;
    }
    try {
        // distance_id kan leeg zijn (single-distance DC met distance_id='').
        // Startnummer = competition-specifieke override (csn) → fallback op
        // persons.start_number. Voor speaker is dit het nummer dat de
        // rijder destijds droeg in die wedstrijd.
        $sql = "
            SELECT
                ua.rang,
                COALESCE(csn.startnummer, p.start_number) AS startnummer,
                COALESCE(p.full_name, ua.person_license) AS naam,
                ua.person_license,
                ua.categorie,
                ua.tijd_ms,
                ua.sanctie,
                -- pending_source: 'historie' bij placeholder uit PDF-import die
                -- nog niet aan een echte KNSB-account gekoppeld is. NULL = echte
                -- KNSB-rijder. Frontend toont ⚡ badge bij pending.
                p.pending_source
            FROM uitslag_afstand ua
            LEFT JOIN persons p ON p.license_key = ua.person_license
            LEFT JOIN competition_startnummers csn
                   ON csn.competition_id = ua.competition_id
                  AND csn.person_license = ua.person_license
            WHERE ua.competition_id          = ?
              AND ua.distance_combination_id = ?
              AND ua.distance_id             = ?
              AND ua.rang BETWEEN 1 AND 3
            ORDER BY ua.rang, p.full_name
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$compId, $dcId, $distId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['rang']        = (int)$r['rang'];
            $r['startnummer'] = $r['startnummer'] !== null ? (int)$r['startnummer'] : null;
            $r['tijd_ms']     = $r['tijd_ms']     !== null ? (int)$r['tijd_ms']     : null;
        }
        unset($r);
        echo json_encode(['top3' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: één rijder ophalen op license_key (voor speaker-detail-modal) ──────
// Gebruikt door klik op podium-pill in bottom-bar. Rijder kan in een
// eerdere wedstrijd hebben gezeten en niet in de huidige meedoen — dus
// alle data komt uit persons. Startnummer-override probeert ÉÉRST de
// huidige speaker-comp (= actionable info: "welk nummer rijdt hij vandaag"),
// valt anders terug op persons.start_number.
if ($action === 'speaker_persoon') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _speakerRequire();
    $lk     = trim($_GET['license_key'] ?? '');
    if ($lk === '') {
        http_response_code(400);
        echo json_encode(['error' => 'license_key is verplicht']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(csn.startnummer, p.start_number) AS startnummer,
                p.license_key,
                p.full_name,
                p.short_name,
                p.category,
                p.birth_year,
                p.gender,
                p.nationality,
                p.club_full,
                p.club_short,
                p.sponsor,
                p.city,
                -- pending_source: 'historie' bij PDF-placeholder. Detail-modal
                -- toont dan een label 'nog niet gekoppeld' en mist club/jaar/etc.
                p.pending_source
            FROM persons p
            LEFT JOIN competition_startnummers csn
                   ON csn.competition_id = ? AND csn.person_license = p.license_key
            WHERE p.license_key = ?
            LIMIT 1
        ");
        $stmt->execute([$compId, $lk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Rijder niet gevonden']);
            exit;
        }
        if ($row['startnummer'] !== null) $row['startnummer'] = (int)$row['startnummer'];
        if ($row['birth_year']  !== null) $row['birth_year']  = (int)$row['birth_year'];
        if ($row['gender']      !== null) $row['gender']      = (int)$row['gender'];
        echo json_encode(['rijder' => $row], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: nationaal record(s) voor cat + afstand ──────────────────────────
// Returns 1-of-4 records uit nationale_records tabel:
//   - cat_groep: 'junioren' (Pupillen t/m JA) | 'senioren' (vanaf SJ/SA)
//   - gender:    0 (heren) | 1 (dames)
//   - afstand_key: '200m','500m','1000m','marathon', etc. (zelfde format als
//                  _spkAfstandKey in jury.js)
//   - mode='matching' (default) → alleen exacte cat-groep+gender match
//     mode='all'                → alle 4 (jun/sen × M/V) varianten, klikbaar
//                                 in speaker-banner voor expanded view
//
// Type (baan/weg) wordt gefilterd via optionele URL-param `&type=baan|weg`.
// Frontend speaker UI heeft een toggle-pill rechts in de banner waarmee de
// gebruiker per afstand kiest welk type record relevant is; keuze blijft in
// localStorage per afstand_key. Zonder param: beide types (backwards-compat).
if ($action === 'speaker_record') {
    header('Content-Type: application/json; charset=utf-8');
    _speakerRequire();
    $afstandKey = trim($_GET['afstand_key'] ?? '');
    $catGroep   = trim($_GET['cat_groep']   ?? '');
    $gender     = $_GET['gender'] ?? '';
    $mode       = trim($_GET['mode'] ?? 'matching');
    $typeParam  = trim($_GET['type']    ?? '');
    $typeFilter = in_array($typeParam, ['baan', 'weg'], true) ? $typeParam : null;
    // afstand_key is verplicht voor 'matching' (single-cat tijd-record),
    // maar OPTIONEEL voor 'mode=all': zonder key krijg je ALLE records
    // (alle afstanden) — handig om te zien of een recordhouder ook nog
    // op andere afstanden bovenaan staat.
    if ($mode !== 'all' && $afstandKey === '') {
        http_response_code(400);
        echo json_encode(['error' => 'afstand_key verplicht']);
        exit;
    }
    try {
        if ($mode === 'all') {
            // mode=all: alle records (gefilterd op afstand_key indien gegeven,
            // anders ALLE afstanden). Type-filter (baan/weg) wordt altijd
            // gerespecteerd zodat banner-keuze doorwerkt in modal.
            $sql = "
                SELECT cat_groep, gender, type, tijd_ms,
                       afstand_key, afstand_naam,
                       rijder_naam, locatie, record_datum, wedstrijd, extra_info
                FROM nationale_records
                WHERE 1=1";
            $params = [];
            if ($afstandKey !== '') { $sql .= " AND afstand_key = ?"; $params[] = $afstandKey; }
            if ($typeFilter !== null) { $sql .= " AND type = ?"; $params[] = $typeFilter; }
            $sql .= "
                ORDER BY
                    CASE cat_groep WHEN 'junioren' THEN 0 ELSE 1 END,
                    gender,
                    afstand_key,
                    type";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            // Matching: alleen voor gegeven cat_groep + gender
            $sql = "
                SELECT cat_groep, gender, type, tijd_ms, afstand_naam,
                       rijder_naam, locatie, record_datum, wedstrijd, extra_info
                FROM nationale_records
                WHERE afstand_key = ?
                  AND cat_groep   = ?
                  AND gender      = ?";
            $params = [$afstandKey, $catGroep, (int)$gender];
            if ($typeFilter !== null) { $sql .= " AND type = ?"; $params[] = $typeFilter; }
            $sql .= " ORDER BY type";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['gender']  = (int)$r['gender'];
            $r['tijd_ms'] = $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null;
        }
        unset($r);
        echo json_encode(['records' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//   SCHEIDSRECHTER-ROL — reserve-inzet + afmeld-beheer
// ════════════════════════════════════════════════════════════════════════════
//
// De scheidsrechter beheert aan de baan de loting: reserves inzetten als er
// plek vrijkomt, en deelnemers op 'afgemeld bij org.' (status 3) of 'niet
// getekend' (status 4) zetten. Beide tellen niet mee (status IN (1,5) = mee).
//
// Verschil met admin reserve_inzet.php: geen KNSB-feed, puur op entries.
// Dezelfde cap-logica (max_slots vs in_loting), aangevuld met de optionele
// distance_combinations.max_in_loting override.
function _scheidsRequire(): string {
    if (empty($_SESSION['jury_comp_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'Niet ingelogd als jury']);
        exit;
    }
    if (($_SESSION['jury_role'] ?? '') !== 'scheidsrechter') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['error' => 'Verkeerde rol — kies Scheidsrechter']);
        exit;
    }
    return (string)$_SESSION['jury_comp_id'];
}

// Bevestig dat een DC bij de ingelogde wedstrijd hoort (anti-injectie).
function _scheidsCheckDc(PDO $pdo, string $dcId, string $compId): bool {
    $st = $pdo->prepare("SELECT 1 FROM distance_combinations WHERE id = ? AND competition_id = ?");
    $st->execute([$dcId, $compId]);
    return (bool)$st->fetchColumn();
}

// KNSB-categorie-sorteersleutel (jongste → oudste, dames vóór heren per
// leeftijd). Identiek aan de closure in speaker_struktuur — hier als
// herbruikbare functie zodat de scheidsrechter-tabs dezelfde volgorde tonen.
function _juryCatSortKey(string $cat): int {
    $cat = strtoupper(trim($cat));
    if (preg_match('/^([HD]?)M(\d{2,3})$/', $cat, $m)) {
        $genderRank = match($m[1]) { 'D' => 0, 'H' => 1, default => 1 };
        $leeftijd = (int)$m[2];
        if ($leeftijd >= 40) {
            $ageRank = 10 + intdiv($leeftijd - 40, 5);
            return $ageRank * 10 + $genderRank;
        }
    }
    $genderRank = match(substr($cat, 0, 1)) { 'D' => 0, 'H' => 1, default => 9 };
    $sub = substr($cat, 1);
    $ageRank = match($sub) {
        'P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3,
        'KA' => 4, 'JB' => 5, 'JA' => 6,
        'SJ' => 7, 'SA' => 8, 'SB' => 9,
        default => 99,
    };
    return $ageRank * 10 + $genderRank;
}

// Bereken teller {geloot, max, vrij} voor één DC. max = override (max_in_loting)
// of auto (aantal niet-reserves). geloot = entries in loting (status 1/5, geen
// reserve). Identiek aan reserve_inzet.php-cap + max_in_loting-override.
function _scheidsTeller(PDO $pdo, string $dcId): array {
    $st = $pdo->prepare("
        SELECT
            dc.max_in_loting AS override_max,
            SUM(CASE WHEN e.reserve IS NULL AND e.reserve_handmatig_ingezet = 0
                     THEN 1 ELSE 0 END) AS auto_max,
            SUM(CASE WHEN e.reserve IS NULL AND e.status IN (1, 5)
                     THEN 1 ELSE 0 END) AS in_loting
        FROM distance_combinations dc
        LEFT JOIN entries e ON e.distance_combination_id = dc.id
        WHERE dc.id = ?
        GROUP BY dc.id, dc.max_in_loting
    ");
    $st->execute([$dcId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $autoMax  = (int)($r['auto_max']  ?? 0);
    $override = $r['override_max'] !== null ? (int)$r['override_max'] : null;
    $max      = $override !== null ? $override : $autoMax;
    $inLoting = (int)($r['in_loting'] ?? 0);
    return [
        'geloot' => $inLoting,
        'max'    => $max,
        'vrij'   => max(0, $max - $inLoting),
    ];
}

// ── API: cat→DC-structuur voor de scheidsrechter (zoals speaker) ────────────
// Niveau 1 = persons.category, niveau 2 = DCs waar die cat in zit, met
// deelnemer- én reserve-tellingen per (cat, dc).
if ($action === 'scheids_struktuur') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _scheidsRequire();
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.category AS cat,
                dc.id      AS dc_id,
                dc.name    AS dc_naam,
                dc.number  AS dc_number,
                SUM(CASE WHEN e.reserve IS NULL AND e.status IN (1, 5)
                         THEN 1 ELSE 0 END) AS aantal_deelnemers,
                SUM(CASE WHEN e.reserve IS NOT NULL
                         THEN 1 ELSE 0 END) AS aantal_reserves
            FROM entries e
            JOIN persons p                ON p.license_key = e.person_license
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE dc.competition_id = ?
              AND p.category IS NOT NULL AND p.category <> ''
            GROUP BY p.category, dc.id, dc.name, dc.number
            HAVING aantal_deelnemers > 0 OR aantal_reserves > 0
            ORDER BY p.category, dc.number, dc.name
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Groepeer → { cats: [ {cat, dcs:[{dc_id, dc_naam, aantal_deelnemers, aantal_reserves}]} ] }
        $catMap = [];
        foreach ($rows as $r) {
            $c = $r['cat'];
            if (!isset($catMap[$c])) $catMap[$c] = [];
            $catMap[$c][] = [
                'dc_id'             => $r['dc_id'],
                'dc_naam'           => $r['dc_naam'],
                'aantal_deelnemers' => (int)$r['aantal_deelnemers'],
                'aantal_reserves'   => (int)$r['aantal_reserves'],
            ];
        }
        $cats = [];
        foreach ($catMap as $c => $dcs) {
            $cats[] = ['cat' => $c, 'dcs' => $dcs];
        }
        usort($cats, fn($a, $b) => _juryCatSortKey($a['cat']) <=> _juryCatSortKey($b['cat']));

        echo json_encode(['cats' => $cats], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: detail voor één DC (+ optioneel cat-filter) ────────────────────────
// teller = altijd per-DC (capaciteit hoort bij de DC, niet bij de cat).
// reserves + deelnemers = gefilterd op cat indien meegegeven (combo-DC's
// zoals DSA+DSJ tonen dan alleen de gekozen cat).
if ($action === 'scheids_dc') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _scheidsRequire();
    $dcId   = trim($_GET['dc_id'] ?? '');
    $cat    = trim($_GET['cat']   ?? '');
    if ($dcId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'dc_id verplicht']);
        exit;
    }
    try {
        if (!_scheidsCheckDc($pdo, $dcId, $compId)) {
            http_response_code(403);
            echo json_encode(['error' => 'DC hoort niet bij deze wedstrijd']);
            exit;
        }
        $catFilterSql = $cat !== '' ? ' AND p.category = ?' : '';
        $params = [$compId, $dcId];
        if ($cat !== '') $params[] = $cat;
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(csn.startnummer, p.start_number) AS startnummer,
                p.license_key,
                p.full_name,
                p.short_name,
                p.category,
                p.club_short,
                e.status                    AS entry_status,
                e.reserve                   AS reserve_nr,
                e.reserve_handmatig_ingezet AS ingezet
            FROM entries e
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers csn
                   ON csn.competition_id = ? AND csn.person_license = p.license_key
            WHERE e.distance_combination_id = ?
              {$catFilterSql}
            ORDER BY
                CASE WHEN COALESCE(csn.startnummer, p.start_number) IS NULL THEN 1 ELSE 0 END,
                COALESCE(csn.startnummer, p.start_number),
                p.full_name
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Heat-lidmaatschap per rijder ophalen (voor "reserve op plek") ──
        // Per persoon: in welke heat(s) van deze DC zit hij, en is die heat al
        // gereden (heeft results)? Een reserve kan alleen op de plek van een
        // afgemelde rijder in een NIET-gereden heat invallen.
        $heatStmt = $pdo->prepare("
            SELECT
                he.person_license,
                h.id        AS heat_id,
                h.heat_naam,
                h.heat_nr,
                he.startpositie,
                (SELECT COUNT(*) FROM results r WHERE r.heat_entry_id = he.id) AS heeft_result
            FROM heats h
            JOIN heat_entries he ON he.heat_id = h.id
            WHERE h.distance_combination_id = ?
        ");
        $heatStmt->execute([$dcId]);
        $heatMap = [];   // license → ['heats' => [{heat_id, label, startpositie}], 'locked' => bool]
        foreach ($heatStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $lic = $h['person_license'];
            if (!isset($heatMap[$lic])) $heatMap[$lic] = ['heats' => [], 'locked' => false];
            $label = $h['heat_naam'] !== '' && $h['heat_naam'] !== null
                ? $h['heat_naam'] : ('Heat ' . (int)$h['heat_nr']);
            $heatMap[$lic]['heats'][] = [
                'heat_id'      => (int)$h['heat_id'],
                'label'        => $label,
                'startpositie' => (int)$h['startpositie'],
            ];
            if ((int)$h['heeft_result'] > 0) $heatMap[$lic]['locked'] = true;
        }
        $heatsBestaan = !empty($heatMap) || (function() use ($pdo, $dcId) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM heats WHERE distance_combination_id = ?");
            $c->execute([$dcId]);
            return (int)$c->fetchColumn() > 0;
        })();

        $reserves   = [];
        $deelnemers = [];
        foreach ($rows as $r) {
            $lic  = $r['license_key'];
            $hm   = $heatMap[$lic] ?? null;
            $rij = [
                'license_key' => $lic,
                'naam'        => $r['full_name'] ?? $r['short_name'] ?? '(onbekend)',
                'startnummer' => $r['startnummer'] !== null ? (int)$r['startnummer'] : null,
                'categorie'   => $r['category'] ?? '',
                'club'        => $r['club_short'] ?? '',
                'entry_status'=> (int)$r['entry_status'],
                'reserve_nr'  => $r['reserve_nr'] !== null ? (int)$r['reserve_nr'] : null,
                'ingezet'     => (int)$r['ingezet'] === 1,
                'in_heat'     => $hm !== null && !empty($hm['heats']),
                'heat_locked' => $hm !== null && $hm['locked'],
                'heat_label'  => $hm !== null
                    ? implode(', ', array_map(fn($x) => $x['label'], $hm['heats']))
                    : '',
            ];
            if ($rij['reserve_nr'] !== null) {
                $reserves[] = $rij;
            } else {
                $deelnemers[] = $rij;
            }
        }
        // Reserves sorteren op reserve-volgnummer (1 eerst = eerste in lijn)
        usort($reserves, fn($a, $b) => $a['reserve_nr'] <=> $b['reserve_nr']);

        echo json_encode([
            'teller'        => _scheidsTeller($pdo, $dcId),
            'heats_bestaan' => $heatsBestaan,
            'reserves'      => $reserves,
            'deelnemers'    => $deelnemers,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: reserve inzetten ───────────────────────────────────────────────────
// POST { dc_id, person_license }. Spiegelt api/reserve_inzet.php (actie=inzet):
// cap-check, status moet 1 zijn, daarna reserve=NULL + handmatig=1 + status=5.
if ($action === 'scheids_inzet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _scheidsRequire();
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $dcId   = trim($body['dc_id'] ?? '');
    $lic    = trim($body['person_license'] ?? '');
    if ($dcId === '' || $lic === '') {
        http_response_code(400);
        echo json_encode(['error' => 'dc_id en person_license verplicht']);
        exit;
    }
    try {
        if (!_scheidsCheckDc($pdo, $dcId, $compId)) {
            http_response_code(403);
            echo json_encode(['error' => 'DC hoort niet bij deze wedstrijd']);
            exit;
        }
        // Huidige entry-status ophalen
        $eStmt = $pdo->prepare("
            SELECT status, reserve FROM entries
            WHERE distance_combination_id = ? AND person_license = ?
        ");
        $eStmt->execute([$dcId, $lic]);
        $ent = $eStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ent) {
            http_response_code(404);
            echo json_encode(['error' => 'Entry niet gevonden']);
            exit;
        }
        if ($ent['reserve'] === null) {
            http_response_code(409);
            echo json_encode(['error' => 'Deze rijder is geen reserve', 'reden' => 'geen_reserve']);
            exit;
        }
        // Alleen een GETEKENDE reserve (status 1) mag worden ingezet. Reserves
        // die zich niet bevestigd hebben in de KNSB-feed (status 0) of afgemeld
        // zijn (2/3/4) zijn niet inzetbaar.
        if ((int)$ent['status'] !== 1) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Reserve moet status "getekend" (bevestigd) hebben om in te zetten',
                'reden' => 'status_niet_getekend',
            ]);
            exit;
        }
        // Capaciteit-cap (identiek aan reserve_inzet.php + max_in_loting-override)
        $teller = _scheidsTeller($pdo, $dcId);
        if ($teller['vrij'] <= 0) {
            http_response_code(409);
            echo json_encode([
                'error'  => "Geen vrije plekken meer — alle {$teller['max']} slots zijn gevuld",
                'reden'  => 'geen_vrije_slots',
                'teller' => $teller,
            ]);
            exit;
        }
        $pdo->prepare("
            UPDATE entries
               SET reserve                   = NULL,
                   reserve_handmatig_ingezet = 1,
                   status                    = 5
             WHERE distance_combination_id = ? AND person_license = ?
        ")->execute([$dcId, $lic]);

        // Geen audit-log meer: scheids-* acties zonder leesbare context (wie,
        // welke DC) zijn niet zinvol; IP/tijdstip via jury-login/-rol blijft.
        echo json_encode(['ok' => true, 'teller' => _scheidsTeller($pdo, $dcId)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: status zetten (afmelden / niet getekend / terug naar actief) ───────
// POST { dc_id, person_license, status }  status ∈ {1, 3, 4}
//   3 = afgemeld bij org., 4 = niet getekend, 1 = terug naar actief.
// KNSB-afmelding (status 2) is geblokkeerd. Bij 'terug' (1) wordt een eerder
// ingezette reserve (reserve_handmatig_ingezet=1) hersteld naar status 5 zodat
// de 'bevestigd bij org.'-semantiek behouden blijft.
if ($action === 'scheids_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _scheidsRequire();
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $dcId   = trim($body['dc_id'] ?? '');
    $lic    = trim($body['person_license'] ?? '');
    $target = (int)($body['status'] ?? -1);
    if ($dcId === '' || $lic === '' || !in_array($target, [1, 3, 4], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'dc_id, person_license en status (1/3/4) verplicht']);
        exit;
    }
    try {
        if (!_scheidsCheckDc($pdo, $dcId, $compId)) {
            http_response_code(403);
            echo json_encode(['error' => 'DC hoort niet bij deze wedstrijd']);
            exit;
        }
        $eStmt = $pdo->prepare("
            SELECT status, reserve, reserve_handmatig_ingezet FROM entries
            WHERE distance_combination_id = ? AND person_license = ?
        ");
        $eStmt->execute([$dcId, $lic]);
        $ent = $eStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ent) {
            http_response_code(404);
            echo json_encode(['error' => 'Entry niet gevonden']);
            exit;
        }
        if ((int)$ent['status'] === 2) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Deze rijder is door de KNSB afgemeld — niet te wijzigen',
                'reden' => 'knsb_afgemeld',
            ]);
            exit;
        }
        // 'terug naar actief': ingezette reserve → 5, anders → 1
        $nieuweStatus = $target;
        if ($target === 1 && (int)$ent['reserve_handmatig_ingezet'] === 1) {
            $nieuweStatus = 5;
        }
        $pdo->prepare("
            UPDATE entries SET status = ?
             WHERE distance_combination_id = ? AND person_license = ?
        ")->execute([$nieuweStatus, $dcId, $lic]);

        // scheids-* audit-log verwijderd — zonder leesbare context niet zinvol.
        echo json_encode([
            'ok'     => true,
            'status' => $nieuweStatus,
            'teller' => _scheidsTeller($pdo, $dcId),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: reserve vervangt afgemelde op diens HEAT-plek ──────────────────────
// POST { dc_id, uit_license (afgemelde), in_license (reserve) }
// Scenario: loting is al gedaan (heats bestaan), iemand meldt zich vlak vóór
// de start af. De reserve neemt LETTERLIJK de startpositie van de afgemelde
// over in diens heat(s) — geen herloting. Eén transactie:
//   1. heat_entries: person_license afgemelde → reserve (startpositie blijft),
//      startnummer + categorie meegeüpdatet. Alleen in NIET-gereden heats.
//   2. entries: afgemelde → status 3 (afgem. bij org., tenzij al 3/4),
//      reserve → status 5 + reserve_handmatig_ingezet=1 + reserve=NULL.
// Harde grens: een heat met resultaten (al gereden) wordt nooit gewijzigd.
if ($action === 'scheids_vervang_in_heat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = _scheidsRequire();
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $dcId   = trim($body['dc_id']       ?? '');
    $uitLic = trim($body['uit_license'] ?? '');
    $inLic  = trim($body['in_license']  ?? '');
    if ($dcId === '' || $uitLic === '' || $inLic === '' || $uitLic === $inLic) {
        http_response_code(400);
        echo json_encode(['error' => 'dc_id, uit_license en in_license (verschillend) verplicht']);
        exit;
    }
    try {
        if (!_scheidsCheckDc($pdo, $dcId, $compId)) {
            http_response_code(403);
            echo json_encode(['error' => 'DC hoort niet bij deze wedstrijd']);
            exit;
        }
        // Reserve-entry valideren: moet entry in deze DC zijn, reserve én getekend.
        $resStmt = $pdo->prepare("
            SELECT status, reserve FROM entries
            WHERE distance_combination_id = ? AND person_license = ?
        ");
        $resStmt->execute([$dcId, $inLic]);
        $resEnt = $resStmt->fetch(PDO::FETCH_ASSOC);
        if (!$resEnt) {
            http_response_code(404);
            echo json_encode(['error' => 'Reserve-entry niet gevonden in deze DC']);
            exit;
        }
        if ($resEnt['reserve'] === null) {
            http_response_code(409);
            echo json_encode(['error' => 'Invaller is geen reserve', 'reden' => 'geen_reserve']);
            exit;
        }
        // Alleen een GETEKENDE reserve (status 1) mag invallen. Niet-bevestigde
        // (0) of afgemelde (2/3/4) reserves zijn niet inzetbaar.
        if ((int)$resEnt['status'] !== 1) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Reserve moet status "getekend" (bevestigd) zijn om in te vallen',
                'reden' => 'status_niet_getekend',
            ]);
            exit;
        }

        // Heats van de afgemelde rijder in deze DC ophalen, met result-check.
        $hStmt = $pdo->prepare("
            SELECT he.id AS he_id, he.heat_id, he.startpositie,
                   (SELECT COUNT(*) FROM results r WHERE r.heat_entry_id = he.id) AS heeft_result
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            WHERE h.distance_combination_id = ?
              AND he.person_license = ?
        ");
        $hStmt->execute([$dcId, $uitLic]);
        $heats = $hStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$heats) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Afgemelde rijder zit niet in een heat — gebruik gewone reserve-inzet',
                'reden' => 'geen_heat',
            ]);
            exit;
        }
        // Als één van de heats al gereden is → weigeren (niet meer te wijzigen).
        foreach ($heats as $h) {
            if ((int)$h['heeft_result'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Een heat van deze rijder is al gereden — vervangen kan niet meer',
                    'reden' => 'heat_gereden',
                ]);
                exit;
            }
        }
        // Voorkom dubbele rijder: reserve mag nog niet in dezelfde heat staan.
        $heatIds = array_map(fn($h) => (int)$h['heat_id'], $heats);
        $hidPh   = implode(',', array_fill(0, count($heatIds), '?'));
        $dupStmt = $pdo->prepare("
            SELECT COUNT(*) FROM heat_entries
            WHERE person_license = ? AND heat_id IN ($hidPh)
        ");
        $dupStmt->execute(array_merge([$inLic], $heatIds));
        if ((int)$dupStmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Reserve staat al in (één van) deze heat(s)',
                'reden' => 'reserve_al_in_heat',
            ]);
            exit;
        }

        // Reserve-persoonsvelden (startnummer-override + categorie) ophalen voor
        // de gedenormaliseerde snapshot in heat_entries.
        $pStmt = $pdo->prepare("
            SELECT COALESCE(csn.startnummer, p.start_number) AS startnummer, p.category
            FROM persons p
            LEFT JOIN competition_startnummers csn
                   ON csn.competition_id = ? AND csn.person_license = p.license_key
            WHERE p.license_key = ?
        ");
        $pStmt->execute([$compId, $inLic]);
        $pInfo = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $inSnr = $pInfo['startnummer'] !== null ? (int)$pInfo['startnummer'] : null;
        $inCat = $pInfo['category'] ?? null;

        $pdo->beginTransaction();
        // 1. Heat-slot(s) overzetten — startpositie blijft ongemoeid.
        $swap = $pdo->prepare("
            UPDATE heat_entries
               SET person_license = ?, startnummer = ?, categorie = ?
             WHERE heat_id = ? AND person_license = ?
        ");
        foreach ($heats as $h) {
            $swap->execute([$inLic, $inSnr, $inCat, (int)$h['heat_id'], $uitLic]);
        }
        // 2a. Afgemelde → status 3 (afgem. bij org.), tenzij al 3/4 gezet.
        $uitCur = $pdo->prepare("
            SELECT status FROM entries WHERE distance_combination_id = ? AND person_license = ?
        ");
        $uitCur->execute([$dcId, $uitLic]);
        $uitStatus = (int)($uitCur->fetchColumn() ?: 1);
        if (!in_array($uitStatus, [3, 4], true)) {
            $pdo->prepare("
                UPDATE entries SET status = 3
                 WHERE distance_combination_id = ? AND person_license = ?
            ")->execute([$dcId, $uitLic]);
        }
        // 2b. Reserve → in de loting (status 5, geen reserve-nr, handmatig ingezet).
        $pdo->prepare("
            UPDATE entries
               SET reserve = NULL, reserve_handmatig_ingezet = 1, status = 5
             WHERE distance_combination_id = ? AND person_license = ?
        ")->execute([$dcId, $inLic]);
        $pdo->commit();

        // scheids-* audit-log verwijderd — zonder leesbare context niet zinvol.
        echo json_encode([
            'ok'           => true,
            'heats_gewijzigd' => count($heats),
            'teller'       => _scheidsTeller($pdo, $dcId),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="InlineComp J">
    <title>InlineComp — Jury</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="apple-touch-icon" href="icon-192-v2.svg">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="jury.css?v=<?= filemtime(__DIR__ . '/jury.css') ?>">
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

<!-- PWA install banner — verschijnt alleen als browser beforeinstallprompt
     vuurt (Chrome/Edge desktop+Android). iOS toont het niet, gebruiker moet
     daar via "Voeg toe aan beginscherm" in Safari handmatig installeren. -->
<div id="pwa-banner" class="jury-pwa-banner" hidden>
    <div class="jury-pwa-tekst">
        <b>Installeer InlineComp Jury</b>
        <span>Voeg toe aan je startscherm voor snelle toegang aan de baan</span>
    </div>
    <button class="jury-btn jury-btn-primary" id="pwa-install">Installeer</button>
    <button class="jury-pwa-sluit" id="pwa-sluit" title="Sluiten" aria-label="Sluiten">&times;</button>
</div>

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

<!-- ?v=filemtime → auto cache-bust bij elke upload zonder handmatige versie-bump.
     filemtime() leest mtime van het bestand op disk; bij SFTP-upload krijgt 'ie
     een nieuwe timestamp → nieuwe URL → browser haalt vers ipv uit cache. -->
<script src="jury.js?v=<?= filemtime(__DIR__ . '/jury.js') ?>"></script>

<!-- ── PWA: service worker + install prompt ──────────────────────────────── -->
<script>
// Update-flow sinds 2026-05-27: zie coach/index.php voor uitleg.
// GEEN auto-reload — wiste input tijdens typen. Vertrouw op no-cache
// headers + cache cleanup bij SW-activate; updates komen bij next nav.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').then(reg => {
        const checkUpdate = () => { try { reg.update(); } catch {} };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') checkUpdate();
        });
        setInterval(checkUpdate, 5 * 60 * 1000);
    }).catch(() => {});
}

let _juryDeferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _juryDeferredPrompt = e;
    // Niet meer tonen als gebruiker eerder weggeklikt heeft
    if (!localStorage.getItem('pwa-jury-dismissed')) {
        document.getElementById('pwa-banner').hidden = false;
    }
});

document.getElementById('pwa-install')?.addEventListener('click', async () => {
    if (!_juryDeferredPrompt) return;
    _juryDeferredPrompt.prompt();
    const result = await _juryDeferredPrompt.userChoice;
    if (result.outcome === 'accepted') {
        document.getElementById('pwa-banner').hidden = true;
    }
    _juryDeferredPrompt = null;
});

document.getElementById('pwa-sluit')?.addEventListener('click', () => {
    document.getElementById('pwa-banner').hidden = true;
    localStorage.setItem('pwa-jury-dismissed', '1');
});

window.addEventListener('appinstalled', () => {
    document.getElementById('pwa-banner').hidden = true;
});
</script>
</body>
</html>
