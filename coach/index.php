<?php
// ============================================================
//  InlineComp – Coach-view
//  Geen login vereist. Coach selecteert rijders op club / sponsor /
//  startnummer, en ziet per heat welke van zijn rijders erin zitten.
// ============================================================
header('Content-Type: text/html; charset=utf-8');
// No-cache: telefoon-browsers (Safari iOS, Chrome Android) cachen HTML
// agressief. Sinds 2026-05-27 expliciet uitschakelen zodat app-updates
// (nieuwe knoppen, fixes) direct doorkomen bij refresh ipv pas na 'oude
// versie weghalen + opnieuw installeren'. Service Worker (sw.js) doet
// hetzelfde voor PWA-geïnstalleerde apps.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../../config_inlinecomp.php';

// ── Bezoektracking: upsert session-hit in coach_visits ──────────────────────
// HTML → full INSERT/UPDATE + peak-check. AJAX → last_seen bumpen met 30s
// rate-limit zodat peak/hourly de echte activity reflecteren zonder een
// UPDATE-storm te veroorzaken. Zie public/index.php voor rationale.
if (session_status() === PHP_SESSION_NONE) {
    session_name('ICCOACH');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
    @session_start();
}
$sid = session_id();
if ($sid) {
    try {
        if (empty($_GET['action'])) {
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $pdo->prepare(
                "INSERT INTO coach_visits (session_id, user_agent) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE last_seen = NOW(), hits = hits + 1"
            )->execute([$sid, $ua]);
            $pdo->prepare("
                UPDATE peak_stats SET
                    peak_today = CASE
                        WHEN peak_today_date = CURDATE()
                            THEN GREATEST(peak_today, (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE))
                        ELSE (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE)
                    END,
                    peak_today_date = CURDATE(),
                    peak_all_time_at = IF(
                        (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE) > peak_all_time,
                        NOW(), peak_all_time_at),
                    peak_all_time = GREATEST(peak_all_time,
                        (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE))
                WHERE scope = 'coach'
            ")->execute();
        } else {
            $pdo->prepare(
                "UPDATE coach_visits SET last_seen = NOW()
                 WHERE session_id = ? AND last_seen < NOW() - INTERVAL 30 SECOND"
            )->execute([$sid]);
        }
    } catch (Throwable $e) { /* tracking mag nooit de pagina breken */ }
}

// ── Wedstrijd-zichtbaarheidsgate ─────────────────────────────────────────────
// Coach toont alleen wedstrijden waarvoor public_zichtbaar=1. De
// competitions-list-action filtert zelf al; deze gate beschermt single-
// comp endpoints (programma, lookup, uitslagen, etc.) tegen URL-pluk
// van een wedstrijd in voorbereidingsfase.
function _coachWedstrijdZichtbaar(PDO $pdo, string $compId): bool {
    if (!$compId) return true;
    $s = $pdo->prepare("SELECT public_zichtbaar FROM competitions WHERE id = ? LIMIT 1");
    $s->execute([$compId]);
    return (bool)$s->fetchColumn();
}
// Cache POST body: coach_info-action gebruikt 'm óók (file_get_contents
// op php://input kan maar één keer gelezen worden).
$_POST_BODY = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST_BODY = json_decode(file_get_contents('php://input'), true) ?: [];
}
$_zichtCompId = trim($_GET['competition_id'] ?? ($_POST_BODY['competition_id'] ?? ''));
if ($_zichtCompId && !_coachWedstrijdZichtbaar($pdo, $_zichtCompId)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['error' => 'Wedstrijd niet beschikbaar']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Page-render server-cache (15s) ──────────────────────────────────────────
// Identiek patroon als public/index.php: cached de HTML+JS-bundle voor
// gebruikers die /coach/ openen (action=''). Live data + auth-gate komen
// via aparte ?action= calls die NIET worden gecached.
//
// Coach-wachtwoord-gate (hieronder) zit OP acties, niet op page-load.
// Iedereen mag de HTML zien — pas bij eerste API-call wordt het wachtwoord
// gevraagd. Daarom is page-cache veilig zelfs met de auth-gate.
$_cacheable = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $action === '';
$_cacheFile = null;
if ($_cacheable) {
    $_compId    = trim($_GET['comp'] ?? '');
    $_lang      = trim($_COOKIE['ICCOACHLANG'] ?? $_COOKIE['ICLANG'] ?? 'nl');
    $_cacheFile = sys_get_temp_dir() . '/coa_' . md5($_compId . '|' . $_lang);
    if (is_file($_cacheFile) && (time() - filemtime($_cacheFile)) < 15) {
        $cached = @file_get_contents($_cacheFile);
        if ($cached !== false && $cached !== '') {
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            header('Cache-Control: public, max-age=15');
            header('Content-Type: text/html; charset=utf-8');
            echo $cached;
            exit;
        }
    }
    ob_start();
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: public, max-age=15');
    register_shutdown_function(function() {
        global $_cacheFile;
        $out = ob_get_contents();
        if ($out !== false && $out !== '' && $_cacheFile) {
            // Atomic rename ipv LOCK_EX (zie public/index.php-comment).
            $tmp = $_cacheFile . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, $out) !== false) {
                @rename($tmp, $_cacheFile);
            }
        }
        ob_end_flush();
    });
}

// ── Coach-app wachtwoord-gate ────────────────────────────────────────────────
// Voorkomt dat publiek massaal coach gebruikt om hele clubs te monitoren
// (DB-load op iFastNet). Drempel-mechanisme, geen security. Bewust voor
// elke API-action — alleen 'auth_status' (gebruikt door frontend om te
// checken of er überhaupt een wachtwoord IS) ontsnapt eraan.
// Bij leeg/onjuist wachtwoord: 401 → frontend prompt opnieuw.
function _coachAuthGate(PDO $pdo, string $action): void {
    if ($action === '' || $action === 'auth_status') return;
    $st = $pdo->prepare("SELECT password FROM coach_app_settings WHERE id = 1 LIMIT 1");
    $st->execute();
    $stored = $st->fetchColumn();
    if (!$stored || $stored === '') return;  // geen wachtwoord ingesteld = open
    // X-Coach-PW header (Apache strip dashes naar underscores in $_SERVER)
    $hdr = $_SERVER['HTTP_X_COACH_PW']
        ?? $_SERVER['HTTP_X_COACH-PW']
        ?? '';
    if (!hash_equals($stored, $hdr)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'coach_password_required']);
        exit;
    }
}
_coachAuthGate($pdo, $action);

// ── auth_status: returnt alleen of er een wachtwoord IS ────────────────────
// Vóór de rate-limit zodat frontend deze zonder 429-risico bij elke load
// kan aanroepen. Antwoord caching aan client-side.
if ($action === 'auth_status') {
    header('Content-Type: application/json; charset=utf-8');
    $st = $pdo->prepare("SELECT password FROM coach_app_settings WHERE id = 1 LIMIT 1");
    $st->execute();
    $stored = $st->fetchColumn();
    echo json_encode(['has_password' => !empty($stored)]);
    exit;
}

// ── Rate limiting: max 10 requests per 5 seconden per IP ─────────────────────
if ($action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlFile = sys_get_temp_dir() . '/rlcoach_' . md5($ip);
    $now = time();
    $hits = @json_decode(@file_get_contents($rlFile), true);
    if (!is_array($hits)) $hits = [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 5));
    if (count($hits) >= 10) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(429);
        echo json_encode(['error' => 'Te veel verzoeken — wacht even']);
        exit;
    }
    $hits[] = $now;
    @file_put_contents($rlFile, json_encode($hits));
}

// ── API: wedstrijden ─────────────────────────────────────────────────────────
if ($action === 'competitions') {
    header('Content-Type: application/json; charset=utf-8');
    // Was 60s cache, maar publiek_zichtbaar kan tussentijds wijzigen
    // (operator publiceert wedstrijd kort voor start). 30s is veilig
    // genoeg en houdt server-belasting laag.
    header('Cache-Control: public, max-age=30');
    try {
        // Baan-velden gebruiken cross-org-fallback: als deze org's baan-rij
        // geen logo of geen vereniging-naam heeft, pakken we die uit een
        // andere org-rij met dezelfde baan-naam (zelfde fysieke locatie).
        // Identiek aan /public.
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.starts, c.ends,
                   c.organisatie_id, o.logo_path AS org_logo, o.naam AS org_naam,
                   c.baan_id, c.public_zichtbaar,
                   COALESCE(b.logo_path, (
                       SELECT b2.logo_path FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                       LIMIT 1
                   )) AS baan_logo,
                   COALESCE(b.vereniging_naam, (
                       SELECT b2.vereniging_naam FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.vereniging_naam IS NOT NULL AND b2.vereniging_naam != ''
                       LIMIT 1
                   )) AS baan_vereniging
            FROM competitions c
            JOIN competition_tijdschema ct ON ct.competition_id = c.id
            LEFT JOIN organisaties o ON o.id = c.organisatie_id
            LEFT JOIN banen b ON b.id = c.baan_id
            WHERE c.public_zichtbaar = 1 OR c.public_aankondigen = 1
            ORDER BY c.starts DESC
        ");
        $stmt->execute();
        $comps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Sponsors per organisatie (zelfde aanpak als /public) — voor footer
        $orgIds = array_unique(array_filter(array_column($comps, 'organisatie_id')));
        $sponsorMap = [];
        if ($orgIds) {
            $spStmt = $pdo->prepare("
                SELECT organisatie_id, naam, logo_path, url
                FROM organisatie_sponsors
                WHERE logo_path IS NOT NULL AND logo_path != ''
                ORDER BY volgorde, naam
            ");
            $spStmt->execute();
            foreach ($spStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                $sponsorMap[$sp['organisatie_id']][] = [
                    'naam' => $sp['naam'], 'logo' => $sp['logo_path'], 'url' => $sp['url'],
                ];
            }
        }
        // Baan-sponsors (per baan_id) — verschijnen achter de org-sponsors
        $baanIds = array_unique(array_filter(array_column($comps, 'baan_id')));
        $baanSponsorMap = [];
        if ($baanIds) {
            $ph = implode(',', array_fill(0, count($baanIds), '?'));
            $bsStmt = $pdo->prepare("
                SELECT baan_id, naam, logo_path, url
                FROM baan_sponsors
                WHERE baan_id IN ($ph)
                  AND logo_path IS NOT NULL AND logo_path != ''
                ORDER BY volgorde, naam
            ");
            $bsStmt->execute(array_values($baanIds));
            foreach ($bsStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                $baanSponsorMap[$sp['baan_id']][] = [
                    'naam' => $sp['naam'], 'logo' => $sp['logo_path'], 'url' => $sp['url'],
                ];
            }
        }
        foreach ($comps as &$c) {
            $org  = $sponsorMap[$c['organisatie_id'] ?? ''] ?? [];
            $baan = $baanSponsorMap[$c['baan_id'] ?? ''] ?? [];
            $c['sponsors'] = array_merge($org, $baan);
        }
        unset($c);
        echo json_encode($comps, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: clubs in deze wedstrijd ─────────────────────────────────────────────
if ($action === 'clubs') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode([]); exit; }
    try {
        // Per club_full ook een club_short meenemen (bv. "ASV") voor de
        // weergave in de dropdown: "ASV - Almeerse SchaatsVereniging".
        // Als er meerdere short-varianten zijn voor hetzelfde full-label,
        // nemen we MIN() (stabiel) — komt in de praktijk bijna niet voor.
        //
        // Sortering: eerst op club_short (alfabetisch op afkorting), met
        // club_full als fallback wanneer er geen short is. Dat past beter
        // bij hoe coaches scannen — ze zoeken meestal op afkorting.
        $stmt = $pdo->prepare("
            SELECT p.club_full AS full,
                   MIN(NULLIF(p.club_short, '')) AS short
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            WHERE dc.competition_id = ?
              AND p.club_full IS NOT NULL AND p.club_full != ''
            GROUP BY p.club_full
            ORDER BY COALESCE(NULLIF(MIN(p.club_short), ''), p.club_full)
        ");
        $stmt->execute([$compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: sponsors in deze wedstrijd ──────────────────────────────────────────
if ($action === 'sponsors') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode([]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            WHERE dc.competition_id = ?
              AND p.sponsor IS NOT NULL AND p.sponsor != ''
            ORDER BY p.sponsor
        ");
        $stmt->execute([$compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijders op club ─────────────────────────────────────────────────────
if ($action === 'personen_by_club') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $club   = trim($_GET['club'] ?? '');
    if (!$compId || !$club) { echo json_encode([]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ? AND p.club_full = ?
            ORDER BY snr
        ");
        $stmt->execute([$compId, $club]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijders op meerdere clubs/sponsors tegelijk ─────────────────────────
// Bulk-variant: 1 call voor N clubs+sponsors. Voorkomt rate-limit als een
// coach veel clubs/sponsors tegelijk selecteert (anders N sequentiële calls).
// Body: { competition_id, clubs: [...], sponsors: [...] }
if ($action === 'personen_bulk') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $compId   = trim($body['competition_id'] ?? '');
    $clubs    = array_values(array_filter(array_map('trim', $body['clubs']    ?? []), 'strlen'));
    $sponsors = array_values(array_filter(array_map('trim', $body['sponsors'] ?? []), 'strlen'));
    if (!$compId || (!$clubs && !$sponsors)) { echo json_encode([]); exit; }
    try {
        $where  = ['dc.competition_id = ?'];
        $params = [$compId];
        $sub    = [];
        if ($clubs) {
            $sub[]  = 'p.club_full IN (' . implode(',', array_fill(0, count($clubs), '?')) . ')';
            $params = array_merge($params, $clubs);
        }
        if ($sponsors) {
            $sub[]  = 'p.sponsor IN (' . implode(',', array_fill(0, count($sponsors), '?')) . ')';
            $params = array_merge($params, $sponsors);
        }
        $where[] = '(' . implode(' OR ', $sub) . ')';
        $sql = "
            SELECT DISTINCT COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY snr
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijders op sponsor ──────────────────────────────────────────────────
if ($action === 'personen_by_sponsor') {
    header('Content-Type: application/json; charset=utf-8');
    $compId  = trim($_GET['competition_id'] ?? '');
    $sponsor = trim($_GET['sponsor'] ?? '');
    if (!$compId || !$sponsor) { echo json_encode([]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ? AND p.sponsor = ?
            ORDER BY snr
        ");
        $stmt->execute([$compId, $sponsor]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijder op startnummer ───────────────────────────────────────────────
// LEGACY — gaf 'LIMIT 1' op startnummer, bug bij dubbele nummers (pakte
// blind de eerste). Vervangen door person_lookup hieronder. Endpoint blijft
// werken voor oudere cached clients, maar nieuwe code gebruikt person_lookup.
if ($action === 'person_by_startnummer') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $snr    = (int)($_GET['snr'] ?? 0);
    if (!$compId || !$snr) { echo json_encode(null); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ?
              AND COALESCE(cs.startnummer, p.start_number) = ?
            LIMIT 1
        ");
        $stmt->execute([$compId, $snr]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: null, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijder zoeken op startnummer, naam OF licentie ──────────────────────
// Vervangt person_by_startnummer. Geeft ALTIJD een array terug (ook bij 1
// match) zodat de frontend uniform multi-match kan afhandelen. Bij dubbele
// startnummers (verschillende rijders met zelfde snr in dezelfde wedstrijd)
// krijgt de coach een keuze-modal, ipv blind de eerste te pakken.
//
// Parameters (competition_id + één van de drie):
//   snr         → exact startnummer (incl. csn-override)
//   license_key → exact licentie (uniek, geeft altijd max 1 hit)
//   naam        → LIKE %naam% op full_name, alleen ingeschreven rijders
if ($action === 'person_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $compId  = trim($_GET['competition_id'] ?? '');
    $snr     = trim($_GET['snr'] ?? '');
    $license = trim($_GET['license_key'] ?? '');
    $naam    = trim($_GET['naam'] ?? '');
    if (!$compId || (!$snr && !$license && !$naam)) {
        echo json_encode(['error' => 'competition_id + snr/license_key/naam verplicht']);
        exit;
    }
    try {
        // Base: alleen rijders die in deze wedstrijd zijn ingeschreven
        // (via een entry op een DC van deze comp). DISTINCT zodat rijders
        // met meerdere entries (verschillende DCs) niet 3× verschijnen.
        $base = "
            SELECT DISTINCT
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full,
                   p.club_short, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ?
        ";
        if ($license !== '') {
            $stmt = $pdo->prepare($base . " AND p.license_key = ? ORDER BY p.full_name");
            $stmt->execute([$compId, $license]);
        } elseif ($snr !== '') {
            $stmt = $pdo->prepare(
                $base . " AND COALESCE(cs.startnummer, p.start_number) = ? ORDER BY p.full_name"
            );
            $stmt->execute([$compId, (int)$snr]);
        } else {
            // Naam: case-insensitive LIKE, min 2 tekens om totaal-scan te voorkomen
            if (mb_strlen($naam) < 2) {
                echo json_encode(['error' => 'Naam-zoek vereist minimaal 2 tekens']);
                exit;
            }
            $stmt = $pdo->prepare(
                $base . " AND LOWER(p.full_name) LIKE LOWER(?) ORDER BY p.full_name LIMIT 50"
            );
            $stmt->execute([$compId, '%' . $naam . '%']);
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: programma (heats + blokken) — zoals /public maar lichter ─────────────
if ($action === 'programma') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=30');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        $tsStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ? LIMIT 1");
        $tsStmt->execute([$compId]);
        $tsId = $tsStmt->fetchColumn();
        if (!$tsId) { echo json_encode(['ritten' => [], 'blokken' => []]); exit; }

        // Ritten + heat_id + rijder-startnummers per heat (zodat JS kan
        // kruisen met de coach-lijst zonder extra roundtrips).
        // Sorteer via de volgorde van het bovenliggende blok (master) en
        // daarbinnen op rit-volgorde. Tijdstip is onbetrouwbaar (niet elke
        // wedstrijd vult dat in).
        $stmt = $pdo->prepare("
            SELECT r.volgorde AS rit_volgorde,
                   b.volgorde AS blok_volgorde,
                   r.blok_id, r.rit_naam, r.ronde_type, r.heat_nr, r.dc_naam,
                   r.combi_group,
                   r.opmerking AS rit_opmerking,
                   r.distance_id AS rit_distance_id, r.afstand_naam AS rit_afstand_naam,
                   b.blok_type, b.tijdstip, b.duur, b.heat_duur, b.opmerking,
                   h.id AS heat_id,
                   h.ronde AS heat_ronde,
                   h.distance_combination_id AS heat_dc_id,
                   COALESCE(h.distance_id, r.distance_id) AS heat_distance_id,
                   COALESCE(d.name, r.afstand_naam) AS distance_naam,
                   (SELECT COUNT(*) FROM heat_entries he2
                    WHERE he2.heat_id = h.id) AS entries_count,
                   (SELECT COUNT(*) FROM results res
                    JOIN heat_entries he ON he.id = res.heat_entry_id
                    WHERE he.heat_id = h.id AND res.finishpositie IS NOT NULL
                   ) AS resultaten_count
            FROM tijdschema_ritten r
            LEFT JOIN tijdschema_blokken b ON b.id = r.blok_id
            LEFT JOIN heats h ON h.tijdschema_rit_id = r.id AND h.competition_id = ?
            -- distances heeft samengestelde PK (dc_id, id) — join MOET beide
            -- kolommen meenemen, anders 1-op-N per DC met dezelfde afstand.
            -- Zie waarschuwing in db/distances.sql.
            LEFT JOIN distances d
                ON d.id = COALESCE(h.distance_id, r.distance_id)
               AND d.distance_combination_id = COALESCE(h.distance_combination_id, r.dc_id)
            WHERE r.tijdschema_id = ?
            ORDER BY r.volgorde
        ");
        $stmt->execute([$compId, $tsId]);
        $rittenRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Definitief-logica (uit /public):
        //   ronde 1 → definitief zodra er rijders in de heat zitten
        //   ronde > 1 → definitief als er rijders in zitten EN de vorige
        //   ronde compleet is. Runner-up: bron-ronde is de EERSTE deelnemende
        //   ronde (heats / KF / HF), niet de hoogste lagere.
        $rondeCheck = []; // cache per dc + dist + ronde + ronde_type
        $checkVorigeRonde = function($dcId, $distId, $ronde, $rondeType) use ($pdo, $compId, &$rondeCheck) {
            if ($ronde <= 1) return true;
            $ck = "{$dcId}_{$distId}_{$ronde}_{$rondeType}";
            if (isset($rondeCheck[$ck])) return $rondeCheck[$ck];
            $distCond = ($distId !== '' && $distId !== null)
                ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
            $vrParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, $ronde] : [$compId, $dcId, $ronde];
            if ($rondeType === 'runner_up') {
                $vrStmt = $pdo->prepare("
                    SELECT MIN(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    LEFT JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      $distCond
                      AND (r.ronde_type IS NULL OR r.ronde_type <> 'runner_up')
                      AND h.ronde < ?
                ");
            } else {
                $vrStmt = $pdo->prepare("
                    SELECT MAX(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      $distCond
                      AND h.ronde < ?
                ");
            }
            $vrStmt->execute($vrParams);
            $vr = $vrStmt->fetchColumn();
            if (!$vr) { $rondeCheck[$ck] = true; return true; }
            $cParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, (int)$vr] : [$compId, $dcId, (int)$vr];
            $s = $pdo->prepare("
                SELECT COUNT(he.id) AS totaal,
                       SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS klaar
                FROM heats h JOIN heat_entries he ON he.heat_id = h.id
                LEFT JOIN results res ON res.heat_entry_id = he.id
                WHERE h.competition_id = ? AND h.distance_combination_id = ?
                  $distCond
                  AND h.ronde = ?
            ");
            $s->execute($cParams);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $ok = $r && (int)$r['totaal'] > 0 && (int)$r['totaal'] === (int)$r['klaar'];
            $rondeCheck[$ck] = $ok;
            return $ok;
        };
        $ritten = [];
        foreach ($rittenRaw as $r) {
            $ronde = (int)($r['heat_ronde'] ?? 0);
            $dcId  = $r['heat_dc_id'] ?? '';
            $distId = $r['heat_distance_id'] ?? '';
            $rondeType = $r['ronde_type'] ?? '';
            $heeftEntries = (int)($r['entries_count'] ?? 0) > 0;
            $r['definitief'] = $heeftEntries && ($ronde <= 1 || $checkVorigeRonde($dcId, $distId, $ronde, $rondeType));
            $ritten[] = $r;
        }

        // Startnummers + license_keys per heat in één query voor alle heats van
        // deze wedstrijd. License_key meenemen is nodig voor de coach-lijst-
        // highlight: twee rijders kunnen hetzelfde startnummer hebben, dus
        // matchen op snr-alleen zou heats valselijk highlighten waar wel snr=N
        // in zit maar niet JOUW rijder met dat nummer.
        $snrStmt = $pdo->prepare("
            SELECT he.heat_id,
                   he.person_license AS lic,
                   COALESCE(cs.startnummer, p.start_number) AS snr
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = he.person_license
                  AND cs.competition_id = h.competition_id
            WHERE h.competition_id = ?
        ");
        $snrStmt->execute([$compId]);
        $rijdersPerHeat = [];
        foreach ($snrStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hid = (int)$row['heat_id'];
            $rijdersPerHeat[$hid][] = [
                'snr' => (int)$row['snr'],
                'lic' => $row['lic'],
            ];
        }
        foreach ($ritten as &$r) {
            $hid = $r['heat_id'] !== null ? (int)$r['heat_id'] : null;
            $r['heat_rijders'] = $hid !== null ? ($rijdersPerHeat[$hid] ?? []) : [];
            // Behoud heat_snrs voor backwards-compat (cached oude clients).
            // Nieuwe code gebruikt heat_rijders met snr+lic pair.
            $r['heat_snrs'] = array_column($r['heat_rijders'], 'snr');
        }
        unset($r);

        // Niet-ronde blokken (pauze / ceremonie / inrijden / etc.) —
        // inclusief id en volgorde zodat de frontend ze op hun
        // blok_volgorde-positie kan tussenvoegen tussen de ritten.
        // inrijd_cats is JSON-array van dc_id-strings; we resolven die
        // server-side naar dc-namen.
        // datum meegestuurd voor multi-day NK: wedstrijdstart-blokken hebben
        // een datum per dag. Frontend gebruikt 'm voor de "Dag N — Zaterdag
        // 28 mei"-header bij meerdaagse evenementen.
        $blStmt = $pdo->prepare("
            SELECT id, volgorde, blok_type, duur, heat_duur, inrijd_cats,
                   tijdstip, datum, opmerking
            FROM tijdschema_blokken
            WHERE tijdschema_id = ? AND blok_type != 'ronde'
            ORDER BY volgorde
        ");
        $blStmt->execute([$tsId]);
        $blokken = $blStmt->fetchAll(PDO::FETCH_ASSOC);

        $dcIds = [];
        foreach ($blokken as $b) {
            if (!empty($b['inrijd_cats'])) {
                $arr = json_decode($b['inrijd_cats'], true);
                if (is_array($arr)) foreach ($arr as $id) $dcIds[(string)$id] = true;
            }
        }
        $dcNamen = [];
        if ($dcIds) {
            $ph = implode(',', array_fill(0, count($dcIds), '?'));
            $dn = $pdo->prepare("SELECT id, name FROM distance_combinations WHERE id IN ($ph)");
            $dn->execute(array_keys($dcIds));
            foreach ($dn->fetchAll(PDO::FETCH_ASSOC) as $r) $dcNamen[(string)$r['id']] = $r['name'];
        }
        foreach ($blokken as &$b) {
            $b['inrijd_cat_namen'] = '';
            if (!empty($b['inrijd_cats'])) {
                $arr = json_decode($b['inrijd_cats'], true);
                if (is_array($arr)) {
                    $namen = array_map(fn($id) => $dcNamen[(string)$id] ?? (string)$id, $arr);
                    $b['inrijd_cat_namen'] = implode(', ', $namen);
                }
            }
        }
        unset($b);

        echo json_encode(['ritten' => $ritten, 'blokken' => $blokken], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rit detail (startlijst van één heat) ────────────────────────────────
// ── API: categorieen met uitslagen (1-op-1 uit /public) ─────────────────────
if ($action === 'categorieen') {
    header('Content-Type: application/json; charset=utf-8');
    // klassement_beschikbaar-vlag verandert bij publish/intrek; geen cache.
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT ua.distance_combination_id AS dc_id, ua.dc_naam,
                   ua.distance_id, ua.distance_naam
            FROM uitslag_afstand ua
            WHERE ua.competition_id = ?
            GROUP BY ua.distance_combination_id, ua.distance_id
            ORDER BY ua.dc_naam, ua.distance_naam
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Filter op gepubliceerde klassementen — operator publiceert
        // expliciet via /Klassement na controle. Niet-gepubliceerde
        // klassementen zijn alleen zichtbaar in admin.
        $klasStmt = $pdo->prepare("
            SELECT DISTINCT uk.distance_combination_id
            FROM uitslag_klassement uk
            INNER JOIN klassement_config kc
                    ON kc.competition_id = uk.competition_id
                   AND kc.dc_id = uk.distance_combination_id
                   AND kc.gepubliceerd_at IS NOT NULL
            WHERE uk.competition_id = ?
        ");
        $klasStmt->execute([$compId]);
        $klasDcIds = $klasStmt->fetchAll(PDO::FETCH_COLUMN);
        $result = [];
        foreach ($rows as $r) {
            $dcId = $r['dc_id'];
            if (!isset($result[$dcId])) {
                $result[$dcId] = [
                    'dc_id' => $dcId, 'dc_naam' => $r['dc_naam'],
                    'afstanden' => [],
                    'klassement_beschikbaar' => in_array($dcId, $klasDcIds),
                ];
            }
            $result[$dcId]['afstanden'][] = [
                'distance_id' => $r['distance_id'],
                'distance_naam' => $r['distance_naam'],
            ];
        }
        echo json_encode(array_values($result), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: volledige uitslag per afstand of klassement (1-op-1 uit /public) ────
if ($action === 'uitslagen') {
    header('Content-Type: application/json; charset=utf-8');
    // Uitslag/klassement-publicatie kan per minuut wijzigen; geen cache.
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    $dcId   = trim($_GET['dc_id'] ?? '');
    $type   = trim($_GET['type'] ?? 'afstand');
    $distId = trim($_GET['distance_id'] ?? '');
    // Optionele categorie-filter voor combi-DC's (bv 'DSA+HSA'): zonder
    // filter zou een cat-select 'HSA' óók DSA-rijders tonen omdat beide in
    // dezelfde DC zitten. Frontend geeft de gekozen cat mee.
    $catFilter = trim($_GET['categorie'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }
    try {
        if ($type === 'klassement') {
            // Pre-check: alleen gepubliceerde klassementen tonen
            $pubStmt = $pdo->prepare("
                SELECT 1 FROM klassement_config
                WHERE competition_id = ? AND dc_id = ? AND gepubliceerd_at IS NOT NULL
                LIMIT 1
            ");
            $pubStmt->execute([$compId, $dcId]);
            if (!$pubStmt->fetchColumn()) {
                echo json_encode(['rijders' => [], 'afstanden' => [], 'niet_gepubliceerd' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $catWhere = $catFilter !== '' ? ' WHERE p.category = ?' : '';
            $stmt = $pdo->prepare("
                SELECT t.rang, t.punten_totaal, t.dc_naam, t.punten_detail,
                       t.person_license AS lic,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr
                FROM uitslag_klassement t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_license
                    FROM uitslag_klassement
                    WHERE competition_id = ? AND distance_combination_id = ?
                    GROUP BY person_license
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.license_key = t.person_license
                LEFT JOIN competition_startnummers cs ON cs.person_license = t.person_license AND cs.competition_id = ?
                $catWhere
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang, t.punten_totaal
            ");
            $params = [$compId, $dcId, $compId];
            if ($catFilter !== '') $params[] = $catFilter;
            $stmt->execute($params);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $afstanden = [];
            foreach ($rijders as &$r) {
                $r['punten_totaal'] = $r['punten_totaal'] !== null ? (float)$r['punten_totaal'] : null;
                $detail = json_decode($r['punten_detail'], true) ?? [];
                $r['punten_detail'] = $detail;
                foreach (array_keys($detail) as $dn) {
                    if (!in_array($dn, $afstanden)) $afstanden[] = $dn;
                }
            }
            unset($r);
            echo json_encode(['rijders' => $rijders, 'afstanden' => $afstanden], JSON_UNESCAPED_UNICODE);
        } else {
            if (!$distId) { echo json_encode(['error' => 'distance_id verplicht']); exit; }
            $catWhere = $catFilter !== '' ? ' WHERE p.category = ?' : '';
            $stmt = $pdo->prepare("
                SELECT t.rang, t.finale_naam, t.tijd_ms, t.sanctie, t.distance_naam,
                       t.person_license AS lic,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr,
                       res_agg.rondes, res_agg.pk_punten
                FROM uitslag_afstand t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_license
                    FROM uitslag_afstand
                    WHERE competition_id = ? AND distance_combination_id = ? AND distance_id = ?
                    GROUP BY person_license
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.license_key = t.person_license
                LEFT JOIN competition_startnummers cs ON cs.person_license = t.person_license AND cs.competition_id = ?
                LEFT JOIN (
                    SELECT he.person_license, res.rondes, res.punten AS pk_punten
                    FROM heat_entries he
                    JOIN heats h ON h.id = he.heat_id
                    JOIN results res ON res.heat_entry_id = he.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      AND COALESCE(h.distance_id, '') = ?
                      AND (res.rondes IS NOT NULL OR res.punten IS NOT NULL)
                    ORDER BY res.id DESC
                ) res_agg ON res_agg.person_license = t.person_license
                $catWhere
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang
            ");
            $params = [$compId, $dcId, $distId, $compId, $compId, $dcId, $distId];
            if ($catFilter !== '') $params[] = $catFilter;
            $stmt->execute($params);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $seen = []; $unique = [];
            foreach ($rijders as $r) {
                $lic = $r['full_name'] . $r['snr'];
                if (isset($seen[$lic])) continue;
                $seen[$lic] = true; $unique[] = $r;
            }
            $heeftRnd = !empty(array_filter($unique, fn($r) => $r['rondes'] !== null));
            $heeftPK  = !empty(array_filter($unique, fn($r) => $r['pk_punten'] !== null));
            echo json_encode([
                'rijders' => $unique, 'heeft_rondes' => $heeftRnd, 'heeft_pk_punten' => $heeftPK,
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: categorieën + afstanden voor Rondes- én Uitslagen-tab ──────────────
// Anders dan /categorieen (die op DC-naam werkt): hier per persoons-categorie
// (DP4, DP3, DKA, …) een lijst van afstanden waar rijders in die categorie
// aan meedoen. Elke afstand krijgt de bijbehorende dc_id mee. Daarnaast per
// categorie de klassementen (unieke DC's met gepubliceerd klassement) — de
// Uitslagen-tab toont die als extra optie(s) in de afstand-dropdown.
// Bron: heat_entries → persons.category, want dat is de authentieke categorie
// per rijder (DC kan meerdere categorieën samen bevatten).
if ($action === 'rondes_cats') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        // Alle (categorie, afstand, dc) combinaties die daadwerkelijk in
        // heats zitten. COALESCE tussen h.distance_id en tsr.distance_id —
        // dezelfde regel als in ronde_uitslagen (heats kunnen soms de
        // distance uit tijdschema_ritten overnemen). dc.name meenemen voor
        // klassement-labeling bij gecombineerde DC's.
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                   p.category           AS categorie,
                   d.id                 AS distance_id,
                   d.name               AS distance_naam,
                   d.value_meters,
                   d.number,
                   d.distance_combination_id AS dc_id,
                   dc.name              AS dc_naam
            FROM heats h
            JOIN heat_entries he ON he.heat_id = h.id
            JOIN persons p       ON p.license_key = he.person_license
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            -- distances heeft compound PK (distance_combination_id, id).
            -- Dezelfde distance_id komt bewust in meerdere DCs voor voor
            -- cross-DC aggregatie. JOIN moet daarom ook op DC, anders
            -- claimt bv HSA per ongeluk de DP4-versie van de distance.
            JOIN distances d  ON d.id  = COALESCE(h.distance_id, tsr.distance_id)
                             AND d.distance_combination_id = h.distance_combination_id
            JOIN distance_combinations dc ON dc.id = d.distance_combination_id
            WHERE h.competition_id = ?
              AND p.category IS NOT NULL AND p.category <> ''
            ORDER BY p.category, d.number, d.name
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gepubliceerde klassement-DC's — voor "🏆 Klassement"-optie in
        // Uitslagen-afstand-dropdown.
        $klasStmt = $pdo->prepare("
            SELECT DISTINCT dc_id
            FROM klassement_config
            WHERE competition_id = ? AND gepubliceerd_at IS NOT NULL
        ");
        $klasStmt->execute([$compId]);
        $klasDcIds = $klasStmt->fetchAll(PDO::FETCH_COLUMN);
        $klasSet = array_flip($klasDcIds);

        // Sorteersleutel: jongste → oudste categorie, dames vóór heren per
        // leeftijd. Dezelfde volgorde als de jury-tabs (_juryCatSortKey), zodat
        // internationale rijders die "DJB = Youth, DJA = Junior" vertalen ook
        // logisch door de dropdown gaan zonder KNSB-codes te kennen.
        $catSortKey = function(string $cat): int {
            $cat = strtoupper(trim($cat));
            // Masters: HM40, DM45, M50, … — leeftijd is de sleutel.
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
        };

        // Eerst per DC de cats verzamelen (welke DC bevat welke cats?).
        $dcCats = [];        // dc_id => [cat, ...]
        $dcNaam = [];        // dc_id => dc_naam
        $dcAfstanden = [];   // dc_id => [afstand, ...]
        foreach ($rows as $r) {
            $dcId = $r['dc_id'];
            $dcNaam[$dcId] = $r['dc_naam'];
            if (!isset($dcCats[$dcId])) $dcCats[$dcId] = [];
            if (!in_array($r['categorie'], $dcCats[$dcId], true)) $dcCats[$dcId][] = $r['categorie'];
            if (!isset($dcAfstanden[$dcId])) $dcAfstanden[$dcId] = [];
            $al = false;
            foreach ($dcAfstanden[$dcId] as $a) {
                if ($a['distance_id'] === $r['distance_id']) { $al = true; break; }
            }
            if (!$al) {
                $dcAfstanden[$dcId][] = [
                    'distance_id'   => $r['distance_id'],
                    'distance_naam' => $r['distance_naam'],
                ];
            }
        }
        // Groepeer per cat-signatuur (bv "HJA+HSA"). Zo worden meerdere
        // DC's met dezelfde cat-samenstelling ÉÉN dropdown-optie. Afstanden
        // uit alle DC's worden samengevoegd, elk met eigen dc_id voor de fetch.
        $perSig = [];
        foreach ($dcCats as $dcId => $cats) {
            $sorted = $cats;
            usort($sorted, fn($a, $b) => $catSortKey($a) - $catSortKey($b));
            $sig = implode('+', $sorted);
            if (!isset($perSig[$sig])) {
                $perSig[$sig] = [
                    'sig' => $sig,
                    'label' => implode(' + ', $sorted),
                    'categorieen' => $sorted,
                    'afstanden' => [],
                    'klassementen' => [],
                    '_sortkey' => $catSortKey($sorted[0] ?? ''),
                ];
            }
            // Afstanden merge — elke afstand krijgt haar bijbehorende dc_id mee.
            foreach ($dcAfstanden[$dcId] as $a) {
                $perSig[$sig]['afstanden'][] = [
                    'distance_id'   => $a['distance_id'],
                    'distance_naam' => $a['distance_naam'],
                    'dc_id'         => $dcId,
                ];
            }
            // Klassement per DC (elke DC heeft z'n eigen klassement).
            if (isset($klasSet[$dcId])) {
                $perSig[$sig]['klassementen'][] = [
                    'dc_id'   => $dcId,
                    'dc_naam' => $dcNaam[$dcId],
                ];
            }
        }
        // Sorteer afstanden binnen een signatuur op distance-naam voor
        // consistente volgorde (500m, 1000m, ...).
        foreach ($perSig as &$sig) {
            usort($sig['afstanden'], fn($a, $b) => strnatcmp($a['distance_naam'] ?? '', $b['distance_naam'] ?? ''));
        }
        unset($sig);
        $out = array_values($perSig);
        usort($out, fn($a, $b) => $a['_sortkey'] - $b['_sortkey']);
        foreach ($out as &$sig) unset($sig['_sortkey']);
        unset($sig);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rondes-verloop per DC (1-op-1 uit /public) ─────────────────────────
// Verschil met /public: geen license_key-parameter — coach wil alle rondes +
// alle heats zien, ook waar geen eigen rijder in zit. De frontend markeert
// eigen rijders zelf via coachLijst (.mijn-highlight in tabel).
if ($action === 'ronde_uitslagen') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    $dcId   = trim($_GET['dc_id'] ?? '');
    // Optioneel: bij combi-DC (bv 'DSA+HSA') filter rijders per cat zodat
    // je bij "HSA" niet de DSA-rijders in dezelfde heat te zien krijgt.
    // Zonder deze filter zou de tabel visueel misleiden bij cat-select.
    $catFilter = trim($_GET['categorie'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }

    try {
        // Wedstrijdsysteem ophalen (bepaalt label 'B-finale' vs 'Kleine finale').
        $sysStmt = $pdo->prepare("SELECT systeem FROM competition_tijdschema WHERE competition_id = ? LIMIT 1");
        $sysStmt->execute([$compId]);
        $systeem = $sysStmt->fetchColumn() ?: 'internationaal-nieuw';

        // 1) Afstanden van deze DC in programma-volgorde.
        $distStmt = $pdo->prepare("
            SELECT d.id, d.name, d.value_meters, d.race_type, d.number,
                   v.prog_volgorde
            FROM distances d
            LEFT JOIN (
                SELECT tr.dc_id, tr.distance_id, MIN(tr.volgorde) AS prog_volgorde
                FROM tijdschema_ritten tr
                JOIN competition_tijdschema ct ON ct.id = tr.tijdschema_id
                WHERE ct.competition_id = ?
                GROUP BY tr.dc_id, tr.distance_id
            ) v ON v.dc_id = d.distance_combination_id AND v.distance_id = d.id
            WHERE d.distance_combination_id = ?
            ORDER BY v.prog_volgorde IS NULL, v.prog_volgorde, d.number, d.name
        ");
        $distStmt->execute([$compId, $dcId]);
        $distances = $distStmt->fetchAll(PDO::FETCH_ASSOC);

        // 1b) finale_ranking per afstand. Bepaalt A-finale sortering in de
        // rondes-tab: dezelfde instelling die de Uitslag-module in admin
        // gebruikt. 'time' = puur op tijd (correct bij 200m DTT);
        // 'position_time' = op finishpositie met tijd tiebreak (standaard).
        // Fallback-regel: dc-specifiek → dc_id IS NULL → 'position_time'.
        $seedStmt = $pdo->prepare("
            SELECT afstand_naam, dc_id, finale_ranking
            FROM tijdschema_afstand_config tac
            JOIN competition_tijdschema ct ON ct.id = tac.tijdschema_id
            WHERE ct.competition_id = ? AND (tac.dc_id = ? OR tac.dc_id IS NULL)
        ");
        $seedStmt->execute([$compId, $dcId]);
        $rankingMap = [];  // afstand_naam => finale_ranking (dc-specifiek wint)
        foreach ($seedStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $an = $s['afstand_naam'];
            if (!isset($rankingMap[$an]) || $s['dc_id'] !== null) {
                $rankingMap[$an] = $s['finale_ranking'];
            }
        }

        // 2) catConfig ophalen (voor Q/q + finale-heat-grootte + runner-up).
        $ccStmt = $pdo->prepare("
            SELECT * FROM tijdschema_cat_config cc
            JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
            WHERE ct.competition_id = ? AND cc.dc_id = ?
        ");
        $ccStmt->execute([$compId, $dcId]);
        $catConfigs = [];
        foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
            $catConfigs[$cc['distance_id']] = $cc;
        }

        // 3) Query voor rijders per heat. Categorie-filter alleen tijdens
        // het samenstellen van de rondeRijders — heat-config (Q/q) wordt
        // ná filter berekend zodat de kwal-badges kloppen voor de zichtbare
        // rijders. Bij combi-DC wil de coach uiteraard alleen zijn cat zien.
        $catAnd = $catFilter !== '' ? ' AND p.category = ?' : '';
        $heatRijStmt = $pdo->prepare("
            SELECT h.id AS heat_id, h.heat_nr,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   he.person_license, he.startpositie,
                   p.full_name, p.category AS categorie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   res.tijd_ms, res.bruto_tijd_ms, res.is_photofinish,
                   res.sanctie, res.finishpositie,
                   res.rondes, res.punten AS pk_punten
            FROM heats h
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            JOIN heat_entries he ON he.heat_id = h.id
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs
                ON cs.person_license = he.person_license AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE h.competition_id = ?
              AND h.distance_combination_id = ?
              AND COALESCE(h.distance_id, tsr.distance_id) = ?
              $catAnd
            ORDER BY h.heat_nr, he.startpositie
        ");

        $RONDE_VOLGORDE = ['heats' => 1, 'kwartfinale' => 2, 'halve_finale' => 3, 'runner_up' => 4, 'finale_a' => 5, 'finale_b' => 6];
        // finale_b heet 'Kleine finale' bij internationaal-nieuw systeem.
        $finaleBLabel = ($systeem === 'internationaal-nieuw') ? 'Kleine finale' : 'B-finale';
        $RONDE_LABEL    = ['heats' => 'Serie', 'kwartfinale' => 'Kwartfinale', 'halve_finale' => 'Halve finale', 'runner_up' => 'Runner-up', 'finale_a' => 'A-finale', 'finale_b' => $finaleBLabel];

        $doorstrKortLabel = function(string $rondeType, ?int $heatNr): string {
            if ($rondeType === 'finale_a')  return 'A';
            if ($rondeType === 'finale_b')  return 'B' . ($heatNr ?? 1);
            if ($rondeType === 'runner_up') return 'RU' . ($heatNr ?? 1);
            if ($rondeType === 'kwartfinale')  return 'KF';
            if ($rondeType === 'halve_finale') return 'HF';
            return '';
        };

        $out = [];
        foreach ($distances as $dist) {
            $distId = $dist['id'];
            $cc     = $catConfigs[$distId] ?? [];

            $heatParams = [$compId, $compId, $dcId, $distId];
            if ($catFilter !== '') $heatParams[] = $catFilter;
            $heatRijStmt->execute($heatParams);
            $rows = $heatRijStmt->fetchAll(PDO::FETCH_ASSOC);
            $perRonde = [];
            foreach ($rows as $r) {
                $rt = $r['ronde_type'];
                if (!isset($perRonde[$rt])) $perRonde[$rt] = [];
                $perRonde[$rt][] = $r;
            }

            $rondeTypes = array_keys($perRonde);
            usort($rondeTypes, fn($a, $b) => ($RONDE_VOLGORDE[$a] ?? 99) - ($RONDE_VOLGORDE[$b] ?? 99));

            // Doorstroom-map: voor elke ronde X → per persoon het label van
            // hun eerst-volgende ronde-heat (A / B1 / RU1 / …).
            $doorstroomPerRondePersoon = [];
            foreach ($rondeTypes as $rt) {
                $vol = $RONDE_VOLGORDE[$rt] ?? 99;
                $doorstroomPerRondePersoon[$rt] = [];
                foreach ($rondeTypes as $laterRt) {
                    if (($RONDE_VOLGORDE[$laterRt] ?? 99) <= $vol) continue;
                    foreach ($perRonde[$laterRt] as $laterR) {
                        $lic = $laterR['person_license'];
                        if (isset($doorstroomPerRondePersoon[$rt][$lic])) continue;
                        $label = $doorstrKortLabel($laterRt, (int)$laterR['heat_nr']);
                        if ($label !== '') $doorstroomPerRondePersoon[$rt][$lic] = $label;
                    }
                }
            }

            $rondes = [];
            foreach ($rondeTypes as $rt) {
                $rondeRijders = $perRonde[$rt];
                if (!count($rondeRijders)) continue;

                // Compleetheid: alle rijders hebben tijd of sanctie.
                $compleet = true;
                foreach ($rondeRijders as $r) {
                    if ($r['tijd_ms'] === null && !$r['sanctie']) { $compleet = false; break; }
                }

                // Bereken Q/q voor doorstroom-rondes.
                $qPerHeat = 0; $totaalDoor = 0;
                if ($rt === 'heats')        { $qPerHeat = (int)($cc['heats_q_heat'] ?? 0); $totaalDoor = (int)($cc['heats_q'] ?? 0); }
                elseif ($rt === 'kwartfinale')  { $qPerHeat = (int)($cc['kwart_q_heat'] ?? 1); $totaalDoor = (int)($cc['kwart_door'] ?? 0); }
                elseif ($rt === 'halve_finale') { $qPerHeat = (int)($cc['half_q_heat'] ?? 1);  $totaalDoor = (int)($cc['half_door'] ?? 0); }

                $UITVAL_SANC = ['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF'];
                $isUitval = function($s) use ($UITVAL_SANC) {
                    if (!$s) return false;
                    foreach (explode(',', $s) as $c) {
                        $c = strtoupper(trim($c));
                        if (in_array($c, $UITVAL_SANC, true)) return true;
                    }
                    return false;
                };
                $qRijders = [];
                $qTijdRijders = [];
                if ($compleet && $totaalDoor > 0) {
                    $perHeat = [];
                    foreach ($rondeRijders as $r) {
                        $hk = $r['heat_nr'];
                        if (!isset($perHeat[$hk])) $perHeat[$hk] = [];
                        $perHeat[$hk][] = $r;
                    }
                    foreach ($perHeat as &$hr) {
                        usort($hr, fn($a, $b) => ($a['finishpositie'] ?? 999) - ($b['finishpositie'] ?? 999));
                    }
                    unset($hr);
                    if ($qPerHeat > 0) {
                        foreach ($perHeat as $hr) {
                            $teller = 0;
                            foreach ($hr as $r) {
                                if ($teller >= $qPerHeat) break;
                                if ($r['finishpositie'] !== null && !$isUitval($r['sanctie'])) {
                                    $qRijders[$r['person_license']] = true;
                                    $teller++;
                                }
                            }
                        }
                    }
                    $aantalQ = count($qRijders);
                    $aantalq = max(0, $totaalDoor - $aantalQ);
                    if ($aantalq > 0) {
                        $metTijd = array_filter($rondeRijders, fn($r) =>
                            $r['tijd_ms'] !== null
                            && !isset($qRijders[$r['person_license']])
                            && !$isUitval($r['sanctie'])
                        );
                        usort($metTijd, fn($a, $b) => $a['tijd_ms'] - $b['tijd_ms']);
                        $metTijd = array_values($metTijd);
                        for ($i = 0; $i < min($aantalq, count($metTijd)); $i++) {
                            $qTijdRijders[$metTijd[$i]['person_license']] = true;
                        }
                        if ($aantalq < count($metTijd) && ($metTijd[$aantalq - 1] ?? null)) {
                            $grens = $metTijd[$aantalq - 1]['tijd_ms'];
                            for ($i = $aantalq; $i < count($metTijd); $i++) {
                                if ($metTijd[$i]['tijd_ms'] === $grens) $qTijdRijders[$metTijd[$i]['person_license']] = true;
                                else break;
                            }
                        }
                    }
                }

                // Runner-up start-positie = aantal rijders in de eerst-
                // VOLGENDE ronde na de EERSTE gereden ronde + 1. RU is voor
                // uitvallers na de eerste ronde; de eerste ronde is niet
                // altijd 'heats' (kleinere wedstrijden beginnen soms met HF).
                //   heats → KF → …    : RU-start = |KF| + 1  (bv 16+1=17)
                //   heats → A(+B)     : RU-start = |A|+|B| + 1
                //   HF → A(+B)        : RU-start = |A|+|B| + 1  (HF was eerste)
                // Meerdere RU-heats (RU-1, RU-2, …) tellen cumulatief door
                // op tijd — dat regelt de RU-sorteer-loop hieronder.
                $ruStartPos = null;
                if ($rt === 'runner_up') {
                    // Volgorde van rondes die daadwerkelijk plaatsen toekennen
                    // (RU zelf niet meegerekend; die krijgt zijn plaats HIER).
                    $plaatsVolgorde = ['heats', 'kwartfinale', 'halve_finale', 'finale_a', 'finale_b'];
                    $eerste = null;
                    foreach ($plaatsVolgorde as $r) {
                        if (isset($perRonde[$r])) { $eerste = $r; break; }
                    }
                    $volgend = null;
                    $naEerste = false;
                    foreach ($plaatsVolgorde as $r) {
                        if ($r === $eerste) { $naEerste = true; continue; }
                        if ($naEerste && isset($perRonde[$r])) { $volgend = $r; break; }
                    }
                    if ($volgend === 'finale_a') {
                        // A + B parallel: doorstromers verdelen over beide.
                        $nA = count($perRonde['finale_a']);
                        $nB = isset($perRonde['finale_b']) ? count($perRonde['finale_b']) : 0;
                        $ruStartPos = $nA + $nB + 1;
                    } elseif ($volgend !== null) {
                        // KF of HF (of edge case finale_b zonder A)
                        $ruStartPos = count($perRonde[$volgend]) + 1;
                    } else {
                        // Geen ronde na de eerste? Rare setup; fallback 1.
                        $ruStartPos = 1;
                    }
                }

                $ds = $doorstroomPerRondePersoon[$rt] ?? [];
                foreach ($rondeRijders as &$r) {
                    $r['kwal'] = '';
                    if (isset($qRijders[$r['person_license']]))    $r['kwal'] = 'Q';
                    elseif (isset($qTijdRijders[$r['person_license']])) $r['kwal'] = 'q';
                    $r['doorstroom_label'] = $ds[$r['person_license']] ?? null;
                    $r['ru_positie'] = null;
                }
                unset($r);

                if ($rt === 'runner_up' && $ruStartPos) {
                    $perHeat = [];
                    foreach ($rondeRijders as $r) {
                        $hk = $r['heat_nr'] ?? 1;
                        if (!isset($perHeat[$hk])) $perHeat[$hk] = [];
                        $perHeat[$hk][] = $r;
                    }
                    ksort($perHeat, SORT_NUMERIC);
                    $volgendePos = $ruStartPos;
                    foreach ($perHeat as $hk => &$hr) {
                        usort($hr, function($a, $b) use ($isUitval) {
                            $aOk = $a['tijd_ms'] !== null && !$isUitval($a['sanctie']);
                            $bOk = $b['tijd_ms'] !== null && !$isUitval($b['sanctie']);
                            if ($aOk !== $bOk) return $aOk ? -1 : 1;
                            if ($aOk) return $a['tijd_ms'] - $b['tijd_ms'];
                            return ($a['startpositie'] ?? 999) - ($b['startpositie'] ?? 999);
                        });
                        foreach ($hr as $r) {
                            foreach ($rondeRijders as &$mr) {
                                if ($mr['person_license'] === $r['person_license']
                                    && $mr['heat_nr'] === $r['heat_nr']) {
                                    $mr['ru_positie'] = $volgendePos++;
                                    break;
                                }
                            }
                            unset($mr);
                        }
                    }
                    unset($hr);
                }

                foreach ($rondeRijders as &$r) {
                    $r['tijd_ms']       = $r['tijd_ms']       !== null ? (int)$r['tijd_ms']       : null;
                    $r['bruto_tijd_ms'] = $r['bruto_tijd_ms'] !== null ? (int)$r['bruto_tijd_ms'] : null;
                    $r['finishpositie'] = $r['finishpositie'] !== null ? (int)$r['finishpositie'] : null;
                    $r['heat_nr']       = $r['heat_nr']       !== null ? (int)$r['heat_nr']       : null;
                    $r['snr']           = $r['snr']           !== null ? (string)$r['snr']        : null;
                    $r['is_photofinish']= (int)($r['is_photofinish'] ?? 0);
                    $r['rondes']        = $r['rondes']        !== null ? (int)$r['rondes']       : null;
                    $r['pk_punten']     = $r['pk_punten']     !== null ? (float)$r['pk_punten']  : null;
                    unset($r['startpositie']);
                }
                unset($r);

                $rondes[] = [
                    'ronde_type'  => $rt,
                    'ronde_label' => $RONDE_LABEL[$rt] ?? $rt,
                    'compleet'    => $compleet,
                    'aantal'      => count($rondeRijders),
                    'rijders'     => $rondeRijders,
                ];
            }

            $out[] = [
                'distance_id'    => $dist['id'],
                'distance_naam'  => $dist['name'],
                'distance_meters'=> $dist['value_meters'] !== null ? (int)$dist['value_meters'] : null,
                'race_type'      => $dist['race_type'],
                'finale_ranking' => $rankingMap[$dist['name']] ?? 'position_time',
                'rondes'         => $rondes,
                'eind_uitslag'   => [],
            ];
        }

        echo json_encode(['distances' => $out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: coach_info — status + sancties voor een set licenties ──────────────
//    POST {competition_id, licenses:[...]}  (POST vanwege mogelijke lengte)
if ($action === 'coach_info') {
    header('Content-Type: application/json; charset=utf-8');
    // POST body is al gelezen door de zichtbaarheidsgate bovenaan
    $body    = $_POST_BODY ?? [];
    $compId  = trim($body['competition_id'] ?? '');
    $licenses = is_array($body['licenses'] ?? null) ? $body['licenses'] : [];
    $licenses = array_values(array_filter(array_map('strval', $licenses)));
    if (!$compId || !$licenses) { echo json_encode(['personen' => []]); exit; }
    try {
        $ph = implode(',', array_fill(0, count($licenses), '?'));
        // Per rijder: worst-case status (hoogste entry.status → "niet getekend" (4) is belangrijkst).
        // We nemen MAX(status); 4=niet getekend valt altijd op.
        $stStmt = $pdo->prepare("
            SELECT p.license_key,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.full_name, p.category, p.club_full, p.sponsor,
                   MAX(e.status) AS entry_status
            FROM persons p
            JOIN entries e ON e.person_license = p.license_key
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = p.license_key
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ? AND p.license_key IN ($ph)
            GROUP BY p.license_key
        ");
        $stStmt->execute(array_merge([$compId], $licenses));
        $personen = [];
        foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['sancties'] = [];
            $row['heats']    = [];
            $personen[$row['license_key']] = $row;
        }

        // Heats per licentie — voor de "Heats"-tab.
        // Per rijder tonen we in welke heats hij is ingedeeld, inclusief
        // ronde-type, rit-naam en afstand. De query geeft voor rijders die
        // nog nergens in zitten geen rijen terug (frontend toont dan "nog niet
        // ingedeeld"). We nemen ook de bestaande rondes van zijn DC's mee
        // zodat we in JS kunnen zien welke rondes ontbreken.
        // Sorteer in dezelfde volgorde als de programma-tab:
        //   blok.volgorde (master) → rit.volgorde (tiebreaker). Zo verschijnen
        //   ritten per rijder in de chronologische wedstrijd-volgorde.
        $heatStmt = $pdo->prepare("
            SELECT he.person_license,
                   h.id AS heat_id, h.ronde, h.heat_naam,
                   h.distance_combination_id AS dc_id,
                   COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   COALESCE(tsr.rit_naam, h.heat_naam) AS rit_naam,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   COALESCE(tsr.dc_naam, '') AS dc_naam,
                   d.name AS afstand_naam,
                   he.startpositie,
                   b.volgorde AS blok_volgorde,
                   tsr.volgorde AS rit_volgorde
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            LEFT JOIN tijdschema_blokken b ON b.id = tsr.blok_id
            LEFT JOIN distances d ON d.id = COALESCE(h.distance_id, tsr.distance_id)
                                 AND d.distance_combination_id = h.distance_combination_id
            WHERE h.competition_id = ?
              AND he.person_license IN ($ph)
            ORDER BY b.volgorde, tsr.volgorde, h.id
        ");
        $heatStmt->execute(array_merge([$compId], $licenses));

        // Zelfde "vorige-ronde-compleet"-check als public/index.php's lookup —
        // anders zou een coach KF/HF/Finale-loting al zien voordat de
        // voorgaande ronde verwerkt is. Heats worden NIET verborgen maar
        // gemarkeerd met vorige_niet_compleet=true zodat de frontend een
        // "Vorige ronde nog niet compleet"-placeholder kan tonen.
        // Runner-up: hangt aan de eerste deelnemende ronde, niet aan de
        // hoogste lagere — daarom een aparte tak met MIN(ronde).
        $rondeCompleetCache = [];
        $checkCompleet = function($ronde, $dcId, $distId, $rondeType) use ($pdo, $compId, &$rondeCompleetCache) {
            if ($ronde <= 1) return true;
            $ck = "{$ronde}_{$dcId}_{$distId}_{$rondeType}";
            if (isset($rondeCompleetCache[$ck])) return $rondeCompleetCache[$ck];

            // Filter ook op distance_id — anders kruist de check tussen
            // afstanden binnen dezelfde DC (bv. 1000m HF kan ten onrechte
            // de afvalkoers-finale blokkeren). NULL distance_id matchen we
            // ook (legacy heats voorafgaand aan per-distance-config).
            $distCond = ($distId !== '' && $distId !== null)
                ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
            $vrParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, $ronde]
                : [$compId, $dcId, $ronde];

            if ($rondeType === 'runner_up') {
                $vrStmt = $pdo->prepare("
                    SELECT MIN(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    LEFT JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      $distCond
                      AND (r.ronde_type IS NULL OR r.ronde_type <> 'runner_up')
                      AND h.ronde < ?
                ");
            } else {
                $vrStmt = $pdo->prepare("
                    SELECT MAX(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      $distCond
                      AND h.ronde < ?
                ");
            }
            $vrStmt->execute($vrParams);
            $vorigeRonde = $vrStmt->fetchColumn();
            if (!$vorigeRonde) { $rondeCompleetCache[$ck] = true; return true; }

            $cParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, (int)$vorigeRonde]
                : [$compId, $dcId, (int)$vorigeRonde];
            $stmt = $pdo->prepare("
                SELECT COUNT(he.id) AS totaal,
                       SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS met_resultaat
                FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                LEFT JOIN results res ON res.heat_entry_id = he.id
                WHERE h.competition_id = ?
                  AND h.distance_combination_id = ?
                  $distCond
                  AND h.ronde = ?
            ");
            $stmt->execute($cParams);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $compleet = $r && (int)$r['totaal'] > 0 && (int)$r['totaal'] === (int)$r['met_resultaat'];
            $rondeCompleetCache[$ck] = $compleet;
            return $compleet;
        };

        foreach ($heatStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $lic = $h['person_license'];
            if (!isset($personen[$lic])) continue;
            $h['vorige_niet_compleet'] = false;
            if ((int)$h['ronde'] > 1
                && !$checkCompleet(
                    (int)$h['ronde'],
                    $h['dc_id'] ?? '',
                    $h['distance_id'] ?? '',
                    $h['ronde_type'] ?? '')) {
                $h['vorige_niet_compleet'] = true;
            }
            $personen[$lic]['heats'][] = $h;
        }

        // Ingeschreven afstanden per rijder (via entries.distance_combination_id).
        // Een afstand is ontbrekend als hij in "ingeschreven" zit maar nog niet
        // in $personen[...]['heats'] → dat toont de frontend als "nog niet ingedeeld".
        // ORDER BY dc.name zodat de DC-volgorde voor elke rijder identiek is —
        // de coach kan dan in één oogopslag zien dat badge-1 altijd dezelfde
        // categorie betreft, badge-2 dezelfde, etc.
        $entStmt = $pdo->prepare("
            SELECT e.person_license,
                   dc.id AS dc_id, dc.name AS dc_naam, dc.number AS dc_number,
                   e.status AS entry_status
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE dc.competition_id = ?
              AND e.person_license IN ($ph)
            ORDER BY dc.number, dc.name
        ");
        $entStmt->execute(array_merge([$compId], $licenses));
        foreach ($entStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $lic = $e['person_license'];
            if (!isset($personen[$lic])) continue;
            if (!isset($personen[$lic]['entries'])) $personen[$lic]['entries'] = [];
            $e['afstanden'] = [];
            $personen[$lic]['entries'][] = $e;
        }

        // Alle afstanden per DC ophalen zodat we ook afstanden tonen waarvoor
        // nog géén programma-ritten bestaan (bv. lange afstand waar nog niet
        // voor gelot is). We nemen alle rijen en laten het unique-maken aan
        // PHP over (één distance_id kan meerdere keren voorkomen per DC door
        // target_group-splits).
        $dcIds = [];
        foreach ($personen as $p) {
            foreach (($p['entries'] ?? []) as $e) $dcIds[$e['dc_id']] = true;
        }
        if ($dcIds) {
            $dcList = array_keys($dcIds);
            $dcPhQ  = implode(',', array_fill(0, count($dcList), '?'));
            $dStmt = $pdo->prepare("
                SELECT distance_combination_id AS dc_id,
                       id AS distance_id, name AS distance_naam, number
                FROM distances
                WHERE distance_combination_id IN ($dcPhQ)
                ORDER BY number
            ");
            $dStmt->execute($dcList);
            $afstandenPerDc = [];
            foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $afstandenPerDc[$d['dc_id']][$d['distance_id']] = [
                    'distance_id'   => $d['distance_id'],
                    'distance_naam' => $d['distance_naam'],
                    'number'        => $d['number'],
                ];
            }
            // Verwachte rondes per (dc_id, distance_id) uit tijdschema_cat_config.
            // Hiermee weten we welke rondes "zouden moeten bestaan" zelfs als
            // er nog geen heats zijn geloot — handig voor de coach om vooraf
            // te zien hoeveel rondes een rijder op z'n programma heeft.
            $rondesPerDcDist = []; // [dc_id][distance_id] = ['heats','finale_a',...]
            $ccStmt = $pdo->prepare("
                SELECT cc.dc_id, cc.distance_id,
                       cc.heeft_heats, cc.heeft_kwartfinale, cc.heeft_halve_finale,
                       cc.heeft_runner_up,
                       cc.finale_heats, cc.finale_b_heats
                FROM tijdschema_cat_config cc
                JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
                WHERE ct.competition_id = ?
                  AND cc.dc_id IN ($dcPhQ)
            ");
            $ccStmt->execute(array_merge([$compId], $dcList));
            foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
                $list = [];
                $heeftEersteRonde = false;
                if ((int)$cc['heeft_heats'])        { $list[] = 'heats';        $heeftEersteRonde = true; }
                if ((int)$cc['heeft_kwartfinale'])  { $list[] = 'kwartfinale';  $heeftEersteRonde = true; }
                if ((int)$cc['heeft_halve_finale']) { $list[] = 'halve_finale'; $heeftEersteRonde = true; }
                // Runner-up draait parallel uit eerste-ronde-uitvallers — voor
                // cats die direct in een A-finale starten (geen series/KF/HF)
                // is runner-up zinloos en zou anders ten onrechte een
                // "Vorige ronde nog niet compleet"-placeholder oproepen in
                // de Heats-tab. Matcht de fix in startlist.js bouwSlFlow().
                if ((int)$cc['heeft_runner_up'] && $heeftEersteRonde) $list[] = 'runner_up';
                if ((int)($cc['finale_b_heats'] ?? 0) > 0) $list[] = 'finale_b';
                if ((int)($cc['finale_heats']   ?? 0) > 0) $list[] = 'finale_a';
                $rondesPerDcDist[$cc['dc_id']][$cc['distance_id']] = $list;
            }

            // Let op: PHP-references door geneste arrays zijn foutgevoelig.
            // Hier muteren we expliciet via index op $personen zodat de
            // wijziging gegarandeerd persisteert.
            foreach ($personen as $lic => $persoon) {
                if (empty($persoon['entries'])) continue;
                foreach ($persoon['entries'] as $i => $entry) {
                    $lijst = array_values($afstandenPerDc[$entry['dc_id']] ?? []);
                    foreach ($lijst as $j => $af) {
                        $lijst[$j]['expected_rondes'] =
                            $rondesPerDcDist[$entry['dc_id']][$af['distance_id']] ?? [];
                    }
                    $personen[$lic]['entries'][$i]['afstanden'] = $lijst;
                }
            }
        }

        // Sancties: alle results met sanctie != NULL voor deze licenties in deze wedstrijd
        $saStmt = $pdo->prepare("
            SELECT he.person_license,
                   res.sanctie, res.tijd_ms, res.finishpositie,
                   COALESCE(tsr.rit_naam, h.heat_naam) AS rit_naam,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   COALESCE(tsr.dc_naam, '') AS dc_naam,
                   d.name AS afstand_naam
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            JOIN results res ON res.heat_entry_id = he.id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            LEFT JOIN distances d ON d.id = COALESCE(h.distance_id, tsr.distance_id)
                                 AND d.distance_combination_id = h.distance_combination_id
            WHERE h.competition_id = ?
              AND he.person_license IN ($ph)
              AND res.sanctie IS NOT NULL AND res.sanctie != ''
            ORDER BY h.ronde, tsr.volgorde, h.id
        ");
        $saStmt->execute(array_merge([$compId], $licenses));
        foreach ($saStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $lic = $s['person_license'];
            if (isset($personen[$lic])) $personen[$lic]['sancties'][] = $s;
        }

        echo json_encode(['personen' => array_values($personen)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rit_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $compId  = trim($_GET['competition_id'] ?? '');
    $ritNaam = trim($_GET['rit_naam'] ?? '');
    if (!$compId || !$ritNaam) { echo json_encode(['heat' => null]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT h.id, h.heat_naam, h.ronde,
                   h.distance_combination_id, COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   COALESCE(tsr.rit_naam, h.heat_naam) AS rit_naam
            FROM heats h
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            WHERE h.competition_id = ?
              AND (tsr.rit_naam = ? OR h.heat_naam = ?)
            LIMIT 1
        ");
        $stmt->execute([$compId, $ritNaam, $ritNaam]);
        $heat = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$heat) { echo json_encode(['heat' => null]); exit; }

        // Vorige-ronde-compleet check (zelfde als public/index.php).
        // Vervolgrondes (KF/HF/F/Runner-up) mogen nog niet getoond worden
        // als hun bron-ronde nog niet compleet is. Voor runner-up is de
        // bron-ronde de EERSTE deelnemende ronde (heats / KF / HF), niet
        // gewoon "hoogste lager".
        if ((int)$heat['ronde'] > 1) {
            $rondeType = $heat['ronde_type'] ?? '';
            $dcId = $heat['distance_combination_id'] ?? '';
            $distId = $heat['distance_id'] ?? '';
            $distCond = ($distId !== '' && $distId !== null)
                ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
            $vrParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, (int)$heat['ronde']]
                : [$compId, $dcId, (int)$heat['ronde']];
            if ($rondeType === 'runner_up') {
                $vrStmt = $pdo->prepare("
                    SELECT MIN(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    LEFT JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      $distCond
                      AND (r.ronde_type IS NULL OR r.ronde_type <> 'runner_up')
                      AND h.ronde < ?
                ");
            } else {
                $vrStmt = $pdo->prepare("
                    SELECT MAX(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      $distCond
                      AND h.ronde < ?
                ");
            }
            $vrStmt->execute($vrParams);
            $vr = $vrStmt->fetchColumn();
            if ($vr) {
                $cParams = ($distId !== '' && $distId !== null)
                    ? [$compId, $dcId, $distId, (int)$vr]
                    : [$compId, $dcId, (int)$vr];
                $cStmt = $pdo->prepare("
                    SELECT COUNT(he.id) AS totaal,
                           SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS met_resultaat
                    FROM heats h JOIN heat_entries he ON he.heat_id = h.id
                    LEFT JOIN results res ON res.heat_entry_id = he.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      $distCond
                      AND h.ronde = ?
                ");
                $cStmt->execute($cParams);
                $r = $cStmt->fetch(PDO::FETCH_ASSOC);
                if (!$r || (int)$r['totaal'] === 0 || (int)$r['totaal'] !== (int)$r['met_resultaat']) {
                    echo json_encode(['heat' => null, 'reden' => 'Vorige ronde nog niet compleet']);
                    exit;
                }
            }
        }

        $rStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor,
                   res.finishpositie, res.tijd_ms,
                   res.bruto_tijd_ms, res.is_photofinish, res.sanctie,
                   res.rondes, res.punten AS pk_punten
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = he.person_license
                  AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");
        $rStmt->execute([$compId, $heat['id']]);
        $heat['rijders'] = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['heat' => $heat], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1F4E79">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title data-i18n="page_title">InlineComp – Coach</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="manifest.json">
<style>
:root { --blauw:#1F4E79; --middenblauw:#2E75B6; --lichtblauw:#D6E4F0;
        --oranje:#E8630A; --wit:#fff; --tekst:#1a1a1a; --grijs:#f4f6f8;
        --accent:#fff3cd; --accent-bd:#ffc107; }
* { box-sizing:border-box; margin:0; padding:0; }
/* Root op 20px zodat alle rem-maten ±25% groter worden — consistent met /public
   en veel leesbaarder op een telefoon aan de rand van de baan. */
html { font-size:20px; overscroll-behavior-y: contain; }
/* Native pull-to-refresh van de browser uitschakelen — moet op html
   én body staan voor brede browser-compatibiliteit. Onze eigen PTR-handler
   vangt de gesture in plaats. Zonder dit deed Chrome Android een full
   page reload, waarbij de geselecteerde wedstrijd verloren ging. */
body { font-family:'Segoe UI',Arial,sans-serif; color:var(--tekst);
       background:var(--grijs); min-height:100vh; font-size:1rem;
       overscroll-behavior-y: contain; }
/* ── Header (1-op-1 uit /public) ── */
header {
    background: var(--blauw);
    color: var(--wit);
    padding: 12px 12px 10px;
    display: flex; flex-direction: column;
}
/* Bovenste rij: 📢 links, titel midden, i + ? rechts. Onderste rij:
   subtitel breeduit gecentreerd. */
.hdr-row-top    { display: flex; align-items: center; gap: 8px; }
.hdr-btns       { display: flex; gap: 6px; flex-shrink: 0; align-items: center; }
.hdr-btns-right { justify-content: flex-end; }
.hdr-spacer     { width: 36px; visibility: hidden; flex-shrink: 0; }
/* Verbinding-banner: rood strookje boven aan zodra netwerk of server eruit ligt */
.conn-banner {
    background: linear-gradient(135deg, #c62828, #b71c1c);
    color: #fff; text-align: center;
    padding: 8px 12px; font-size: .9rem; font-weight: 600;
    box-shadow: 0 2px 4px rgba(0,0,0,.2);
    position: sticky; top: 0; z-index: 500;
    animation: conn-pulse 2s ease-in-out infinite;
}
@keyframes conn-pulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: .82; }
}
.conn-banner small { font-weight: 400; font-size: .78rem; opacity: .85; }
header .hdr-center { flex: 1; min-width: 0; text-align: center; }
header h1 { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
header .sub { font-size: .95rem; opacity: .8; margin-top: 6px; text-align: center; }
@media (max-width: 480px) {
    header { padding: 10px 8px 8px; }
    .hdr-spacer { width: 30px; }
    header h1  { font-size: 1.2rem; }
    header .sub { font-size: .78rem; margin-top: 4px; }
    .btn-help { width: 30px; height: 30px; font-size: 1rem; }
    .btn-meldingen { font-size: .95rem; }
}
.btn-help {
    background: rgba(255,255,255,.2); border: 2px solid rgba(255,255,255,.5);
    color: #fff; width: 36px; height: 36px; border-radius: 50%;
    font-size: 1.2rem; font-weight: 700; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center; font-style: italic;
    flex-shrink: 0;          /* nooit ovaal worden in flex-container */
}
.btn-help:active { background: rgba(255,255,255,.35); }
/* Vlag-knop: toont emoji-vlag van actieve taal. Klik = expand panel met
   4 talen (gemount door js/i18n.js → _i18nMountDropdown). Op Windows zonder
   emoji-flag-glyph valt 'ie terug op letterparen (NL/GB/DE/FR). Vorm blijft
   ronde knop, font-style normal voorkomt italic-erfgenaam van .btn-help. */
.btn-lang {
    padding: 0;
    font-style: normal;
    display: flex;
    align-items: center;
    justify-content: center;
    /* manipulation: schakel double-tap-to-zoom uit op touch → eerste tap
     * vuurt onmiddellijk (geen 300ms delay) → paneel opent direct. */
    touch-action: manipulation;
}
.btn-lang .i18n-flag {
    font-size: 1.4rem;
    line-height: 1;
}
@media (max-width: 480px) {
    .btn-lang .i18n-flag { font-size: 1.2rem; }
}

/* Uitgevouwen taal-panel: compact horizontaal rijtje van 4 vlag-knoppen.
   Geen tekstnamen — vlag-emoji + title-tooltip is voldoende.
   Positionering wordt via JS gezet (top/right/left vanuit getBoundingClientRect). */
.i18n-dropdown-panel {
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-shadow: 0 4px 14px rgba(0,0,0,.2);
    padding: 4px;
    display: flex;
    flex-direction: row;
    gap: 2px;
}
.i18n-dropdown-opt {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6px 8px;
    background: none;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-family: inherit;
    touch-action: manipulation;
}
.i18n-dropdown-opt:hover { background: #f0f6ff; }
.i18n-dropdown-opt.is-active {
    background: #1F4E79;
}
.i18n-dropdown-opt .i18n-flag {
    font-size: 1.4rem;
    line-height: 1;
}
.btn-meldingen   { font-style: normal; font-size: 1.1rem; position: relative; }
/* Allemaal gelezen → grijs ipv rood. Geeft passief signaal "ze zijn er,
   geen actie nodig" terwijl rood + uitroepteken = "kijk even". */
.meld-badge.gezien { background: #888 !important; }
.meld-badge      { position: absolute; top: -4px; right: -4px; background: #d22;
                   color: #fff; font-size: .65rem; font-weight: 700;
                   min-width: 17px; height: 17px; padding: 0 4px; border-radius: 9px;
                   display: flex; align-items: center; justify-content: center;
                   border: 2px solid #fff; line-height: 1; }

/* ── Org footer (1-op-1 uit /public) ── */
.org-footer {
    display: none;
    background: var(--wit); border-top: 1px solid #dde3ea;
    padding: 12px 16px;
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
    box-shadow: 0 -2px 8px rgba(0,0,0,.08);
}
.org-footer-inner {
    max-width: 720px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.org-footer-logo { height: 50px; width: auto; object-fit: contain; flex-shrink: 0; }
.org-footer-naam { font-size: .85rem; color: var(--blauw); font-weight: 600; flex-shrink: 0; }
/* Lege footer-cellen inklappen zodat de marquee tot de rand kan doorlopen. */
.org-footer-inner > :empty { display: none !important; }
.org-footer-sponsors { flex: 1; overflow: hidden; display: flex; align-items: center; justify-content: flex-end; }
.sponsor-marquee { display: flex; overflow: hidden; height: 50px; align-items: center; }
.sponsor-marquee-inner {
    display: flex; align-items: center; gap: 40px; flex-shrink: 0;
    animation: marquee linear infinite;
}
.sponsor-marquee-inner img { height: 40px; width: auto; object-fit: contain; flex-shrink: 0; }
@keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(calc(-50% - 20px)); } }
/* Body krijgt bottom-padding zodra de footer zichtbaar is, zodat de inhoud
   niet onder de fixed footer wegvalt. */
body.heeft-footer .container { padding-bottom: 90px; }

/* ── Help overlay (1-op-1 uit /public) ── */
.help-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    z-index: 2000; display: flex; align-items: flex-start; justify-content: center;
    padding: 24px 12px; overflow-y: auto;
}
.help-box {
    background: var(--wit); border-radius: 14px; width: 100%; max-width: 520px;
    box-shadow: 0 12px 40px rgba(0,0,0,.3); overflow: hidden;
}
.help-header {
    background: var(--blauw); color: var(--wit); padding: 14px 16px;
    display: flex; justify-content: space-between; align-items: center;
    font-size: 1.1rem; font-weight: 700;
}
.help-sluit { background: none; border: none; color: rgba(255,255,255,.7);
              font-size: 1.5rem; cursor: pointer; line-height: 1; }
.help-body { padding: 16px; font-size: .9rem; line-height: 1.5; color: var(--tekst); }
.help-body h3 { font-size: .95rem; color: var(--blauw); margin: 16px 0 6px; }
.help-body h3:first-child { margin-top: 0; }
.help-body p { margin: 4px 0 8px; }
.help-body .help-stap { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; }
.help-body .help-stap-nr {
    flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%;
    background: var(--oranje); color: var(--wit); font-weight: 700;
    display: flex; align-items: center; justify-content: center; font-size: .8rem;
}
/* ── "Wat is nieuw"-jump knop bovenin help-modal ── */
.btn-nieuw-jump {
    display: block; width: 100%;
    background: linear-gradient(180deg, #eaf2fa 0%, #d6e4f0 100%);
    color: var(--blauw); border: 1.5px solid var(--middenblauw);
    border-radius: 8px; padding: 10px 12px;
    font-size: .92rem; font-weight: 700; cursor: pointer;
    margin: 0 0 14px; transition: transform .1s, background .15s;
}
.btn-nieuw-jump:hover  { background: linear-gradient(180deg, #d6e4f0 0%, #b9d0e6 100%); }
.btn-nieuw-jump:active { transform: scale(.98); }
/* ── Changelog / "Wat is nieuw" ── */
.changelog-versie {
    background: #f7faff; border-left: 3px solid var(--middenblauw);
    border-radius: 4px; padding: 10px 12px; margin: 10px 0;
}
.changelog-kop {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 6px;
}
.changelog-vnr    { font-weight: 700; color: var(--blauw); font-size: .95rem; }
.changelog-datum  { font-size: .78rem; color: #888; }
.changelog-lijst  { margin: 0; padding-left: 20px; font-size: .88rem; }
.changelog-lijst li { margin: 3px 0; }
/* ── Mockups in help ── */
.mock {
    border: 2px solid #dde3ea; border-radius: 10px; overflow: hidden;
    margin: 10px 0 14px; font-size: .78rem;
}
.mock-hdr { background: var(--blauw); color: #fff; padding: 6px 10px;
            font-weight: 700; font-size: .75rem; text-align: center; }
.mock-body { padding: 8px 10px; background: #fafbfc; }
.mock-select {
    background: #fff; border: 1.5px solid #cdd8e3; border-radius: 6px;
    padding: 6px 8px; width: 100%; font-size: .75rem; color: #555; margin-bottom: 6px;
}
/* Match echte coach-tabs: witte achtergrond, grijze tekst, emoji op regel 1
   (via \n in tab_-labels), tekst op regel 2, blauw + oranje underline actief. */
.mock-tabs {
    display: flex; background: #fff; border-bottom: 2px solid #dde3ea;
}
.mock-tab {
    flex: 1 1 0; min-width: 0; text-align: center; padding: 5px 2px;
    color: #888; font-size: .56rem; font-weight: 600;
    border-bottom: 2px solid transparent; margin-bottom: -2px;
    white-space: pre-line; line-height: 1.15; overflow: hidden;
}
.mock-tab::first-line { font-size: .8rem; }
.mock-tab.active { color: var(--blauw); border-bottom-color: var(--oranje); }
.mock-row {
    display: flex; align-items: center; gap: 4px;
    padding: 3px 0; border-bottom: 1px solid #eef2f6; font-size: .72rem;
}
.mock-row:last-child { border-bottom: none; }
.mock-row.mock-hl { background: #fffbe6; font-weight: 600; }
.mock-naam { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mock-tijd { width: 44px; text-align: right; font-family: monospace; }
.mock-snr  { display: inline-block; width: 24px; text-align: center; color: #666; }
.mock-rang { display: inline-block; width: 18px; text-align: center; color: var(--blauw); font-weight: 700; }

/* In-app bevestigings-dialoog (vervangt native confirm()) */
.bev-knoppen {
    display:flex; gap:10px; justify-content:flex-end;
    padding:12px 16px; border-top:1px solid #eee; background:#f9fafb;
}
.bev-btn {
    padding:8px 18px; font-size:.9rem; font-weight:600;
    border:none; border-radius:6px; cursor:pointer;
}
.bev-btn-annuleer { background:#e5e7eb; color:#333; }
.bev-btn-annuleer:hover { background:#d1d5db; }
.bev-btn-bevestig { background:#b71c1c; color:#fff; }
.bev-btn-bevestig:hover { background:#7a0000; }

.container { max-width:900px; margin:0 auto; padding:12px; }
/* Geen witte card-backgrounds meer; elementen staan direct op de body-gray.
   We behouden de .card-classe als "visueel groepje" zonder achtergrond, zodat
   bestaande HTML-structuur blijft werken en er alleen ruimte onderin komt. */
.card { margin-bottom:18px; }
.card h2 { font-size:1rem; color:var(--blauw); margin-bottom:8px; }

/* ── Setup-strook: klikbare compacte header met huidige wedstrijd + aantal
      rijders, opent de setup-modal voor wijzigen. Bespaart verticale ruimte
      op mobiel t.o.v. de oude altijd-zichtbare stap 1/2/chips-secties. ─── */
.setup-strip {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    background: #fff;
    border: 1px solid #d3dbe3;
    border-radius: 6px;
    cursor: pointer;
    margin: 8px 0 12px;
    transition: background .12s, border-color .12s;
}
.setup-strip:hover { background: #f5f8fc; border-color: #b3cae6; }
.setup-strip-tekst {
    flex: 1 1 auto; min-width: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    color: #1a3a5c; font-size: .9rem;
}
.setup-strip-tekst b { color: #1a3a5c; }
.setup-strip-tekst small {
    display: block; font-size: .74rem; color: #666; font-weight: normal;
    margin-top: 1px;
}
.setup-strip-empty { color: #888; font-style: italic; font-size: .88rem; }
.setup-strip-edit {
    background: none; border: 0;
    color: var(--blauw); font-size: 1.05rem;
    padding: 4px 8px; cursor: pointer; flex-shrink: 0;
}
/* Modal-overlay voor setup. Opent bij klik op setup-strip of automatisch
   bij eerste bezoek van de dag (localStorage-detectie). */
.setup-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.4);
    z-index: 200;
    display: none;
    align-items: flex-start; justify-content: center;
    padding: 20px 12px;
    overflow-y: auto;
}
.setup-modal-overlay.open { display: flex; }
.setup-modal-box {
    background: #fff;
    border-radius: 10px;
    max-width: 520px; width: 100%;
    padding: 18px 16px;
    position: relative;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    animation: setup-modal-in .18s ease-out;
}
@keyframes setup-modal-in {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.setup-modal-close {
    position: absolute; top: 8px; right: 8px;
    background: none; border: 0;
    font-size: 1.4rem; color: #666; cursor: pointer;
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    transition: background .12s;
}
.setup-modal-close:hover { background: #f0f0f0; color: #333; }
.setup-modal-titel {
    font-size: 1.05rem; font-weight: 700; color: var(--blauw);
    margin: 0 0 12px; padding-right: 32px;
}
/* Secties binnen modal: geen dubbele card-omranding — modal-box is al de
   container. Wél scheiding tussen stappen. */
.setup-modal-box .card { margin: 0; padding: 0; border: 0; box-shadow: none; background: none; }
.setup-modal-box > .card + .card { margin-top: 14px; padding-top: 14px; border-top: 1px solid #eef2f6; }
/* "Klaar"-knop onderaan de modal — primaire, sticky-bottom voelt vanzelfsprekender
   dan alleen het ×-kruisje bovenin. Full-width op smal scherm, rechts op ruimer. */
.setup-modal-klaar-rij {
    margin-top: 16px; padding-top: 12px;
    border-top: 1px solid #eef2f6;
    display: flex; justify-content: flex-end;
}
.setup-modal-klaar-rij .btn-primair { min-width: 120px; }
@media (max-width: 420px) {
    .setup-modal-klaar-rij .btn-primair { width: 100%; }
}

/* Stap-label met blauw cijfer (1-op-1 uit /public) */
.stap-label {
    font-size: 1.05rem; font-weight: 700; color: var(--blauw);
    margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
}
.stap-nr {
    background: var(--blauw); color: var(--wit);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem; font-weight: 700; flex-shrink: 0;
}
/* Secundaire sub-kop binnen een stap (bv. "Op club" / "Op sponsor"). */
.stap-sub { font-size:.85rem; font-weight:600; color:#666;
            margin:10px 0 4px; }

/* Form-elementen — 1-op-1 uit /public voor consistente look & feel.
   Gebruik je eigen selector in plaats van bare `select`/`input` om te
   voorkomen dat dit doorlekt naar andere selects (filter-chips etc.). */
.sel, .inp {
    width: 100%; padding: 14px 14px; font-size: 1rem;
    border: 2px solid #cdd8e3; border-radius: 8px;
    background: var(--wit); appearance: none; -webkit-appearance: none;
}
select.sel {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%23666'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px;
}
.sel:focus, .inp:focus { border-color: var(--middenblauw); outline: none; }
.sel:disabled { background-color:#f5f7fa; color:#999; cursor:not-allowed; }

/* Multi-select dropdown voor sponsors. Knop ziet eruit als .sel, paneel
   klapt eronder uit met checkbox-lijst + zoekveld + alle/niets-knoppen.
   Past goed in beide layouts (desktop + mobiel). */
.sponsor-multi-wrap { position: relative; }
.sponsor-multi-knop {
    display: flex; align-items: center; justify-content: space-between;
    text-align: left; cursor: pointer; padding-right: 14px;
}
.sponsor-multi-knop:not(:disabled):hover { border-color: var(--middenblauw); }
.sponsor-multi-knop .sponsor-multi-pijl { font-size: .8rem; color: #666; margin-left: 8px; }
.sponsor-multi-paneel {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--wit); border: 2px solid var(--middenblauw); border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
    z-index: 50; max-height: 70vh; display: flex; flex-direction: column;
}
/* hidden-attribuut moet display:none afdwingen — anders overrult de
   display:flex hierboven en blijft het paneel zichtbaar. */
.sponsor-multi-paneel[hidden] { display: none !important; }
/* Visueel accent op de knop zodra er iets is geselecteerd: groene rand +
   gevulde achtergrond zodat duidelijk is dat er een keuze openstaat die
   nog naar Toevoegen moet. */
.sponsor-multi-knop.heeft-selectie {
    border-color: #2e7d32 !important;
    background: #f1f8f3;
}
.sponsor-multi-knop.heeft-selectie::before {
    content: '✓ '; color: #2e7d32; font-weight: 700;
}
/* Chips onder de knop: meteen zichtbaar wat gekozen is, zonder paneel
   weer te hoeven openen. Klik op een chip verwijdert die sponsor uit
   de selectie. */
.sponsor-chips {
    display: flex; flex-wrap: wrap; gap: 4px;
    margin-top: 6px;
    min-height: 0;
}
.sponsor-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; font-size: .82rem;
    background: #eef4fa; color: var(--blauw);
    border: 1px solid #c5d8f0; border-radius: 12px;
    cursor: pointer;
}
.sponsor-chip:hover { background: #ffe8e8; border-color: #d77; color: #a33; }
.sponsor-chip::after { content: '×'; font-weight: 700; margin-left: 2px; }
.sponsor-multi-acties {
    display: flex; gap: 8px; align-items: center;
    padding: 8px 10px; border-bottom: 1px solid #eee;
    font-size: .85rem;
}
.sponsor-multi-acties .btn-klein {
    padding: 4px 10px; font-size: .8rem;
    background: #eef4fa; color: var(--blauw); border: 1px solid #c5d8f0;
    border-radius: 4px; cursor: pointer;
}
.sponsor-multi-acties .btn-klein:hover { background: #dde8f5; }
.sponsor-multi-teller { margin-left: auto; color: #666; }
.sponsor-multi-lijst { flex: 1; overflow-y: auto; padding: 4px 0; }
.sponsor-multi-lijst label {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 12px; cursor: pointer; font-size: .92rem;
}
.sponsor-multi-lijst label:hover { background: #f0f5fa; }
.sponsor-multi-lijst input[type="checkbox"] { margin: 0; transform: scale(1.1); }
.sponsor-multi-lijst .leeg { padding: 10px 12px; color: #999; font-style: italic; }
.sponsor-multi-footer {
    padding: 10px; border-top: 1px solid #eee; text-align: right;
}
.sponsor-multi-footer .btn-primair {
    padding: 8px 18px; font-size: .9rem;
    background: var(--blauw); color: var(--wit); border: none; border-radius: 4px;
    cursor: pointer;
}
.sponsor-multi-footer .btn-primair:hover { background: var(--middenblauw); }

/* Wedstrijd-info-kader (1-op-1 uit /public) */
.comp-info {
    background: var(--lichtblauw); border-radius: 8px;
    padding: 12px 14px; margin-top: 10px;
    font-size: 1rem; color: var(--blauw);
}
.comp-info strong { font-size: 1.1rem; display:block; }
.comp-info small  { color:#5580a8; }

/* Filter-chips onder de wedstrijd-select (1-op-1 uit /public) */
.filter-rij { display:flex; gap:8px; margin-bottom:8px; }
.filter-rij input[type=checkbox] { display:none; }
.filter-rij label.filter-chip { flex:1; }
.filter-chip {
    display:inline-flex; align-items:center; justify-content:center; gap:5px;
    padding:9px 10px; border-radius:20px; font-size:.95rem; font-weight:600;
    border:2px solid #cdd8e3; background:var(--wit); color:#888;
    cursor:pointer; user-select:none; transition:all .15s;
    min-width:0;                  /* laat flex 3-op-een-rij zonder overflow */
}
.filter-chip:active { transform:scale(.96); }
.filter-rij input:checked + .filter-chip {
    background:var(--lichtblauw); border-color:var(--middenblauw); color:var(--blauw);
}
/* Smalle schermen (~iPhone SE / ~360-400px): overlay/box/chip-padding
   verder verkleinen zodat 3 filter-chips netjes binnen de modal passen. */
@media (max-width: 400px) {
    .setup-modal-overlay { padding:14px 6px; }
    .setup-modal-box     { padding:16px 12px; }
    .filter-chip         { padding:8px 6px; font-size:.88rem; }
}
.rij { display:flex; gap:8px; margin-bottom:10px; }
.rij > * { flex:1; }
.btn { background:var(--middenblauw); color:var(--wit); border:none;
       padding:8px 14px; font-size:.9rem; border-radius:5px; cursor:pointer; }
.btn:hover { background:var(--blauw); }
.btn:disabled { opacity:.5; cursor:not-allowed; }
.btn-klein { padding:4px 8px; font-size:.8rem; }
.btn-wis { background:#b71c1c; }

/* Primaire actie-knop (1-op-1 uit /public btn-zoek): oranje, volle breedte. */
.btn-primair {
    width:100%; padding:16px; font-size:1.15rem; font-weight:700;
    color:var(--wit); background:var(--oranje);
    border:none; border-radius:8px; cursor:pointer; margin-top:10px;
}
.btn-primair:disabled { opacity:.4; cursor:not-allowed; }
.btn-primair:active { transform:scale(.98); }

/* Coach-lijst chips */
.coach-hdr { display:flex; justify-content:space-between; align-items:center;
             margin-bottom:8px; font-size:.9rem; color:#555; }
.chips { display:flex; flex-wrap:wrap; gap:4px; min-height:28px; }
.chip { display:inline-flex; align-items:center; gap:4px;
        background:var(--lichtblauw); border:1px solid #b3cae6;
        border-radius:14px; padding:2px 8px; font-size:.85rem; }
.chip .x { cursor:pointer; color:#b71c1c; font-weight:700; padding:0 2px; }
.chip .x:hover { color:#7a0000; }
.chip-snr { font-weight:700; color:var(--blauw); }

/* Programma-lijst */
/* Twee-rij-layout: bovenrij = status-icoon + naam/sub (flex), onderrij =
   eigen-rijder-pills (volledige breedte met indent). Pills op een aparte
   regel zetten voorkomt dat coaches met veel rijders het rit-naam-blok
   ingedrukt zien worden of horizontaal moeten scrollen. */
.heat-rij { background:var(--wit); border:1px solid #dde3ea;
            border-radius:6px; padding:10px 12px; margin-bottom:6px;
            cursor:pointer; display:flex; flex-direction:column; gap:6px; }
.heat-rij:hover { background:#f0f5fa; }
.heat-rij.mijn { border-left:4px solid var(--accent-bd); background:var(--accent); }
.heat-rij.leeg { cursor:default; opacity:.75; background:#fafafa; }
.heat-rij-top { display:flex; align-items:center; gap:10px; }
/* Vaste breedte voor het status-icoon zodat naam-kolom niet schuift tussen
   regels met/zonder icoon. Emoji-glyphs zijn breder dan ○, daarom krijgt
   de hele kolom een deterministische breedte. */
.heat-status { width:28px; text-align:center; font-size:1rem; flex-shrink:0; }
.heat-status-leeg { color:#bbb; font-size:.9rem; }
.heat-info { flex:1; min-width:0; }
.heat-naam { font-weight:600; }
.heat-sub { font-size:.8rem; color:#666; margin-top:2px; }
.heat-rit-opm { font-size:.78rem; color:#856404; font-style:italic; margin-top:2px; }
/* Pills uitlijnen onder .heat-info (28px icon + 10px gap) zodat ze visueel
   bij het rit-naam-blok horen. flex-wrap zorgt dat veel pills netjes
   doorlopen op meerdere regels. */
.heat-mijn-snrs { display:flex; flex-wrap:wrap; gap:4px;
                  padding-left:38px; }
.heat-mijn-snrs .m-snr {
    background:var(--accent-bd); color:#000; font-weight:700;
    font-size:.8rem; border-radius:10px; padding:2px 7px;
}
.badge { display:inline-block; padding:1px 6px; font-size:.75rem;
         border-radius:3px; color:#fff; margin-right:4px; }
.badge-serie   { background:#607d8b; }
.badge-kf      { background:#8e24aa; }
.badge-hf      { background:#5e35b1; }
.badge-finale  { background:#d32f2f; }
.badge-ru      { background:#00897b; }
/* Multi-day filter-balk bovenaan (Alle / Dag 1 / Dag 2 / …) */
.prog-dag-filter {
    display: flex; flex-wrap: wrap; gap: 6px;
    margin: 0 0 10px 0; padding: 6px 0;
    position: sticky; top: 0; z-index: 5;
    background: #fff;
}
.prog-dag-btn {
    border: 1px solid #b3cae6; background: #fff; color: #1a3a5c;
    padding: 4px 9px; border-radius: 14px; font-size: .82rem;
    font-weight: 600; cursor: pointer; transition: background .12s;
    display: inline-flex; flex-direction: column; align-items: center;
    justify-content: center; line-height: 1.15; min-height: 38px;
}
.prog-dag-btn:hover { background: #eaf2fb; }
.prog-dag-btn.actief {
    background: #1a3a5c; color: #fff; border-color: #1a3a5c;
}
/* Korte datum onder "Dag N" — klein zodat 3+ dagen op 1 regel passen */
.prog-dag-btn-datum {
    display: block; font-size: .55rem; font-weight: 400;
    opacity: .8; margin-top: 1px; letter-spacing: 0;
    white-space: nowrap;
}
.prog-dag-btn.actief .prog-dag-btn-datum { opacity: .95; }

/* ── Programma-filter-strook (dag + afstand) — identiek aan public ── */
/* Vierkante blokken die visueel matchen met .prog-klap-balk — geen
   border-radius, dunne scheidingslijnen, hover/actief-kleuren identiek
   aan .prog-klap-btn. Coach heeft geen kaart-sectie-padding, dus geen
   horizontal negative-margin. Klap-balk staat eronder; sibling-selector
   verwijdert dubbele scheidingslijn. */
.prog-filter-strook {
    margin: 0 0 0;
    position: sticky; top: 0; z-index: 5;
    background: #fff;
    border-top: 1px solid #b3cae6;
    border-bottom: 1px solid #b3cae6;
    display: flex; flex-direction: column;
}
.prog-filter-strook + .prog-klap-balk { border-top: 0; }
.prog-filter-trigger {
    display: flex; align-items: center; gap: 8px;
    background: #fff; color: #1a3a5c;
    border: 0;
    border-bottom: 1px solid #d5dee7;
    padding: 8px 12px;
    font-size: .78rem; font-weight: 600;
    line-height: 1.15;
    letter-spacing: -.02em;
    cursor: pointer;
    transition: background .12s, color .12s;
    width: 100%;
    text-align: left;
}
.prog-filter-trigger:last-of-type { border-bottom: 0; }
@media (hover: hover) {
    .prog-filter-trigger:not(.open):hover { background: #eaf2fb; }
}
.prog-filter-trigger.open {
    background: #1a3a5c; color: #fff;
    border-bottom-color: #1a3a5c;
}
.prog-filter-icon { font-size: 1rem; flex-shrink: 0; }
.prog-filter-lbl  { flex: 1; text-align: left; }
.prog-filter-caret { font-size: .7rem; transition: transform .15s; }
.prog-filter-trigger.open .prog-filter-caret { transform: rotate(180deg); }
.prog-filter-panel {
    display: flex; flex-wrap: wrap;
    background: #eef4fb;
    border-bottom: 1px solid #d5dee7;
    animation: prog-filter-in .12s ease-out;
}
.prog-filter-panel:last-child { border-bottom: 0; }
@keyframes prog-filter-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}
.prog-filter-pill {
    background: #eef4fb; color: #1a3a5c;
    border: 0;
    border-right: 1px solid #d5dee7;
    padding: 8px 12px;
    font-size: .78rem; font-weight: 600;
    line-height: 1.15;
    letter-spacing: -.02em;
    cursor: pointer;
    transition: background .12s, color .12s;
    display: inline-flex; flex-direction: column;
    align-items: center; justify-content: center;
    min-height: 34px;
    flex: 1 0 auto;
    white-space: nowrap;
}
@media (hover: hover) {
    .prog-filter-pill:not(.actief):hover { background: #dde8f5; }
}
.prog-filter-pill.actief { background: #1a3a5c; color: #fff; }
.prog-filter-pill-sub {
    display: block; font-size: .58rem; font-weight: 400;
    opacity: .8; margin-top: 1px; white-space: nowrap;
}
.prog-filter-pill.actief .prog-filter-pill-sub { opacity: .95; }

/* Samenvat-modus voor niet-geselecteerde afstanden — één compacte
   regel per (afstand × ronde-type), niet uitklapbaar. */
.prog-groep.samenvat { opacity: .72; background: #f6f8fb; }
.prog-groep.samenvat .prog-groep-body { display: none; }
.prog-groep.samenvat .prog-groep-chev { visibility: hidden; }
.prog-groep.samenvat .prog-groep-hdr  { cursor: default; }
.samenvat-teller {
    font-size: .78rem; color: #666; font-weight: 500;
    margin-left: 4px;
}
.verborgen { display: none !important; }

/* Programma-inklap-knoppen: segment-control boven de programma-lijst.
   Rechthoekige balk, 3 gelijke kolommen, actieve knop donkerblauw. */
.prog-klap-balk {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    background: #fff;
    border-top: 1px solid #b3cae6;
    border-bottom: 1px solid #b3cae6;
    margin: 0 0 8px;
}
.prog-klap-btn {
    background: #fff;
    color: #1a3a5c;
    border: 0;
    border-right: 1px solid #d5dee7;
    padding: 8px 2px;
    font-size: .78rem; font-weight: 600;
    line-height: 1.15;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -.02em;
    transition: background .12s, color .12s;
}
.prog-klap-btn:last-child { border-right: 0; }
@media (hover: hover) {
    .prog-klap-btn:not(.actief):hover { background: #eaf2fb; }
}
.prog-klap-btn.actief {
    background: #1a3a5c;
    color: #fff;
}

/* Cat-groep header — één inklapbare header per (dc_naam + ronde_type).
   Standaard ingeklapt in coach; alleen chevron + naam + telling zichtbaar.
   Coach-rijders indicator: oranje links-strip + subtiel badge rechts. */
.prog-groep {
    margin: 4px 0 6px;
    background: #fff;
    border: 1px solid #d5dee7;
    border-radius: 6px;
    overflow: hidden;
    transition: box-shadow .15s;
}
.prog-groep.mijn {
    border-left: 4px solid var(--oranje, #E8630A);
}
.prog-groep-hdr {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px;
    cursor: pointer;
    background: #f4f7fb;
    user-select: none;
    transition: background .12s;
}
@media (hover: hover) {
    .prog-groep-hdr:hover { background: #eaf2fb; }
}
.prog-groep-chev {
    display: inline-block; width: 12px; color: #1a3a5c;
    font-size: .78rem; flex-shrink: 0;
    transition: transform .15s;
}
.prog-groep-status {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px;
    font-size: .9rem; line-height: 1;
    flex-shrink: 0;
}
.prog-groep-titel {
    flex: 1 1 auto; min-width: 0;
    font-weight: 600; color: #1a3a5c;
    font-size: .9rem;
    text-overflow: ellipsis; overflow: hidden; white-space: nowrap;
}
.prog-groep-badge {
    display: inline-block;
    background: #eef3f9; color: #1a3a5c;
    padding: 1px 7px; border-radius: 8px;
    font-size: .72rem; font-weight: 600;
    flex-shrink: 0;
}
.prog-groep.mijn .prog-groep-mijn-badge {
    display: inline-flex;
    align-items: center;
    background: var(--oranje, #E8630A); color: #fff;
    padding: 1px 7px; border-radius: 8px;
    font-size: .72rem; font-weight: 700;
    flex-shrink: 0;
}
.prog-groep-mijn-badge { display: none; }
.prog-groep-body {
    padding: 4px 6px 6px;
    background: #fff;
}
.prog-groep.ingeklapt .prog-groep-body { display: none; }
.prog-groep.ingeklapt .prog-groep-chev { transform: rotate(-90deg); }
/* Dag-header bij meerdaags evenement: prominente scheiding tussen dagen */
.prog-dag-header {
    background: linear-gradient(to right, #1a3a5c, #2E75B6);
    color: #fff; padding: 10px 14px; border-radius: 6px;
    font-size: 1rem; font-weight: 700; margin: 14px 0 8px 0;
    text-transform: capitalize; letter-spacing: .02em;
    box-shadow: 0 2px 4px rgba(26,58,92,.15);
}
.prog-dag-header:first-child { margin-top: 4px; }

/* Coach-wachtwoord prompt — eenvoudige modal, geen styling-conflict met
   bestaande modals (.cw- prefix). Bedoeld als drempel: schermvullend
   blauw blok zodat coach niet per ongeluk wegklikt naar 'open' state. */
.cw-overlay {
    position: fixed; inset: 0; z-index: 99999;
    background: rgba(26,58,92,.92);
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.cw-dialog {
    background: #fff; border-radius: 10px; padding: 24px;
    max-width: 380px; width: 100%;
    box-shadow: 0 8px 32px rgba(0,0,0,.3);
}
.cw-dialog h2 { margin: 0 0 12px; color: #1a3a5c; font-size: 1.2rem; }
.cw-dialog p { margin: 0 0 16px; color: #444; font-size: .92rem; line-height: 1.45; }
.cw-input {
    width: 100%; padding: 12px 14px; font-size: 1.1rem;
    border: 2px solid #b3cae6; border-radius: 6px;
    box-sizing: border-box; font-family: inherit;
}
.cw-input:focus { outline: none; border-color: #2E75B6; }
.cw-knoppen { display: flex; gap: 8px; margin-top: 14px; }
.cw-btn {
    flex: 1; padding: 12px 16px;
    border: none; border-radius: 6px; font-size: 1rem; font-weight: 600;
    cursor: pointer;
}
.cw-btn-ok { background: #2E75B6; color: #fff; }
.cw-btn-ok:hover { background: #1F4E79; }
.cw-fout {
    margin-top: 12px; padding: 8px 12px;
    background: #fdecec; border: 1px solid #e6b9b9; border-radius: 4px;
    color: #b71c1c; font-size: .88rem;
}

.blok-rij { background:#e8eaf6; border-radius:6px; padding:6px 10px;
            margin-bottom:6px; font-size:.85rem; color:#333; }
.blok-rij-top { display:flex; flex-wrap:wrap; align-items:baseline; gap:.5rem; }
.blok-rij .blok-tijd { color:#666; font-variant-numeric:tabular-nums; }
.blok-rij .blok-titel { font-weight:600; }
.blok-rij .blok-duur { color:#555; font-size:.8rem; }
.blok-rij .blok-opm { color:#555; font-style:italic; }
.blok-rij .blok-cats { margin-top:2px; padding-left:1.4rem; color:#555; font-size:.78rem; }
.blok-rij.blok-pauze { background:#fff3e0; }
.blok-rij.blok-inrijden { background:#e3f2fd; }
.blok-rij.blok-wedstrijdstart { background:#e8f5e9; }
.blok-rij.blok-ceremonie { background:#fff8e1; }
.blok-rij.blok-herstart { background:#ffebee; }

/* Tabs (1-op-1 uit /public) */
.tabs {
    display:flex; background:var(--wit);
    border-bottom:2px solid #dde3ea;
    margin-bottom:10px;
}
.tab-btn {
    flex:1 1 0; min-width:0;
    padding:8px 2px; font-size:.68rem; font-weight:600;
    text-align:center; border:none; background:none; cursor:pointer;
    color:#888; border-bottom:3px solid transparent; margin-bottom:-2px;
    /* Emoji op regel 1, tekst op regel 2 (via \n in i18n-label).
       flex:1 1 0 + min-width:0 → alle 5 tabs delen container gelijk zonder
       horizontale scroll, tekst clipt binnen tab als 't niet past. */
    white-space:pre-line; line-height:1.2;
    overflow:hidden;
}
.tab-btn::first-line { font-size:.95rem; }
.tab-btn.active { color:var(--blauw); border-bottom-color:var(--oranje); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* Sancties-tab */
.sanc-persoon { background:var(--wit); border:1px solid #dde3ea;
                border-radius:6px; padding:10px 12px; margin-bottom:8px; }
.sanc-persoon-kop { display:flex; align-items:center; gap:8px;
                    font-weight:600; margin-bottom:6px; flex-wrap:wrap; }
.sanc-persoon-cat { font-size:.8rem; color:#888; margin:-4px 0 6px 34px; }
.sanc-samenvat { display:flex; flex-direction:column; gap:3px;
                 margin:0 0 8px 0; }
.sanc-samenvat-rij { display:flex; align-items:center; gap:8px; font-size:.85rem; }
.sanc-samenvat-naam { flex:1; color:#444; }
.sanc-persoon-snr { color:var(--blauw); font-weight:700; }
.status-badge { font-size:.75rem; padding:2px 8px; border-radius:10px; font-weight:600; }
.status-0 { background:#fff3e0; color:#e65100; }  /* Niet bevestigd */
.status-1 { background:#e8f5e9; color:#2e7d32; }  /* Bevestigd */
.status-2 { background:#fce4e4; color:#b71c1c; }  /* Afgemeld */
.status-3 { background:#f3e5f5; color:#6a1b9a; }  /* Afgem. bij org. */
.status-4 { background:#ffcdd2; color:#b71c1c; border:2px solid #b71c1c; } /* Niet getekend — opvallend! */
.status-5 { background:#e0f7fa; color:#006064; }  /* Bev. bij org. */
.sanc-lijst { display:flex; flex-direction:column; gap:3px; }
.sanc-rij { font-size:.85rem; padding:3px 6px; background:#fff8e1;
            border-left:3px solid #f9a825; border-radius:3px; }
.sanc-rij-code { font-weight:700; color:#b71c1c; }
.sanc-leeg { color:#888; font-style:italic; font-size:.85rem; }

/* Heats-tab: DC/afstand-blokje per rijder met rondes eronder */
.heat-toon-dc { margin:6px 0; padding:6px 8px; background:#f7fbff;
                border-left:3px solid var(--middenblauw); border-radius:4px; }
.heat-toon-dc-kop { font-size:.85rem; font-weight:600; color:var(--blauw); margin-bottom:3px;
                    display:flex; align-items:center; gap:6px; }
.heat-toon-rij { display:flex; align-items:center; gap:6px;
                 font-size:.85rem; padding:2px 0; flex-wrap:wrap; }
.heat-toon-wachten { background:#fff8e1; border-left-color:#f9a825; }
.heat-toon-wacht-rij { color:#8a5a00; font-style:italic; }
.heat-toon-niet-geplaatst { color:#b71c1c; font-style:italic; }
.chip-waarschuw { background:#ffcdd2 !important; border-color:#b71c1c !important; }

/* Uitslagen-tabel */
table.uitsl-tabel { width:100%; border-collapse:collapse; margin-top:10px; font-size:.85rem; }
table.uitsl-tabel th { background:var(--lichtblauw); color:var(--blauw);
                       padding:6px 4px; text-align:left; border-bottom:2px solid #b3cae6; }
table.uitsl-tabel td { padding:6px 4px; border-bottom:1px solid #eee; }
table.uitsl-tabel tr.mijn td { background:var(--accent); font-weight:600; }
.col-rang { width:32px; text-align:center; font-weight:700; }
table.uitsl-tabel td.col-cat-rank { width:40px; text-align:center; font-weight:700; color:var(--blauw); }
table.uitsl-tabel th.col-cat-rank { width:40px; text-align:center; }
.col-rnd, .col-pk { width:40px; text-align:right; }
.col-tijd { width:96px; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
/* Audit-icoon ✋/📷 links van de tijd; cijfers blijven rechts-uitgelijnd.
   nowrap voorkomt dat icoon + tijd op aparte regels eindigen op smal scherm. */
.col-tijd-audit { float:left; font-family:sans-serif; opacity:.85; cursor:help; }
.col-punten { width:44px; text-align:right; }
.col-totaal { width:50px; text-align:right; font-weight:700; color:var(--blauw); }
.col-sanctie { color:#b71c1c; font-weight:700; font-size:.8em; }

/* Rondes-tab: per-ronde uitslag (heats/KF/HF/RU/A/B).
   Analoog aan public rijder-pagina, maar zonder rijder-filter — toont
   alle heats van alle afstanden binnen de gekozen categorie. */
.rondeu-afstand { margin-bottom:16px; }
.rondeu-afstand-titel {
    font-weight:700; color:var(--blauw); font-size:1rem;
    margin:12px 0 6px; padding:6px 8px;
    background:var(--lichtblauw); border-radius:4px;
}
.rondeu-ronde { margin:8px 0 10px; padding:6px 4px 4px;
                border-left:3px solid var(--middenblauw);
                overflow-x:auto; -webkit-overflow-scrolling:touch; }
/* Uitslag- en rondes-tab containers scrollen horizontaal als een brede
   tabel (bv gecombineerde categorie met extra #cat-kolommen) niet past. */
#uitslagen, #rondes-inhoud { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.rondeu-ronde.pending { border-left-color:#f9a825; opacity:.85; }
.rondeu-ronde-titel { display:flex; align-items:center; gap:8px;
                      margin-bottom:4px; }
.rondeu-badge { display:inline-block; padding:2px 8px; border-radius:10px;
                font-size:.75rem; font-weight:700; color:#fff;
                background:var(--middenblauw); }
.rondeu-badge.badge-heats        { background:#546e7a; }
.rondeu-badge.badge-kwartfinale  { background:#00695c; }
.rondeu-badge.badge-halve_finale { background:#00838f; }
.rondeu-badge.badge-runner_up    { background:#8e24aa; }
.rondeu-badge.badge-finale_a     { background:#c62828; }
.rondeu-badge.badge-finale_b     { background:#ef6c00; }
.rondeu-pending { font-size:.75rem; color:#f9a825; font-style:italic; }
table.rondeu-tabel { width:100%; border-collapse:collapse; margin-top:4px;
                     font-size:.82rem; }
table.rondeu-tabel th { background:#eef4fb; color:var(--blauw);
                        padding:4px 3px; text-align:left;
                        border-bottom:1px solid #cfdcec; font-weight:600; }
table.rondeu-tabel td { padding:4px 3px; border-bottom:1px solid #f0f0f0; }
table.rondeu-tabel td.c, table.rondeu-tabel th.c { text-align:center; }
table.rondeu-tabel tr.mijn td { background:var(--accent); font-weight:600; }
table.rondeu-tabel tr.rondeu-heat-sub td {
    background:#f5f5f5; font-weight:700; color:#555;
    padding:3px 6px; font-size:.78rem;
}

/* Extra-smalle schermen: font iets omlaag zodat clipping niet actief wordt. */
@media (max-width:340px) { .tab-btn { font-size:.62rem; padding:8px 1px; } }

/* Gecombineerde rit: ritten die tegelijk rijden in één kader */
.prog-combi-box {
    border:2px dashed var(--middenblauw);
    border-radius:8px;
    padding:6px 8px 2px;
    margin-bottom:8px;
    background:#f7fbff;
}
.prog-combi-kop {
    font-size:.8rem; font-weight:700; color:var(--blauw);
    padding:2px 4px 6px; letter-spacing:.3px;
}
.prog-combi-leden .heat-rij { margin-bottom:4px; }

/* Combi-wrapper: omhult MEERDERE cat-groepen die in dezelfde combi_group
   zitten (categorieën die tegelijk rijden) — één blauw kader met kop-label
   rond de héle combinatie; elke cat behoudt z'n eigen inklap. Zelfde vorm
   als in /public zodat coaches het combi-verband in één oogopslag zien. */
.prog-combi-wrap {
    border:2px solid #2E75B6;
    border-radius:8px;
    background:#eef4fb;
    margin:8px 0;
    overflow:hidden;
}
.prog-combi-wrap > .prog-combi-kop {
    background:#2E75B6;
    color:#fff;
    font-size:.78rem;
    font-weight:600;
    padding:5px 10px;
    letter-spacing:.02em;
}
.prog-combi-body {
    padding:6px 8px 8px;
    background:#eef4fb;
}
.prog-combi-body .prog-groep { margin:4px 0; }

.overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000;
           display:flex; align-items:flex-start; justify-content:center;
           padding:20px; overflow-y:auto; }
.overlay-box { background:var(--wit); border-radius:8px; max-width:500px;
               width:100%; position:relative; overflow:hidden; }
/* Heat-overlay: blauwe kop met titel + ronde rode close-knop rechtsboven —
   zelfde stijl als de publieke app voor visuele consistentie. */
.heat-card-titel {
    background: var(--blauw); color: var(--wit);
    padding: 10px 50px 10px 14px; font-weight: 700; font-size: .95rem;
    display: flex; align-items: center; gap: 8px;
    position: relative;
    border-radius: 8px 8px 0 0;
}
.overlay-sluit {
    position: absolute; top: 8px; right: 8px;
    border: none; background: #d22; color: #fff;
    width: 28px; height: 28px; border-radius: 50%;
    font-size: 1.1rem; font-weight: 700; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.25);
    transition: background .12s, transform .08s;
}
.overlay-sluit:hover  { background: #b71c1c; }
.overlay-sluit:active { transform: scale(.92); }
.overlay-body { padding: 14px; }
/* Oude .sluit-stijl voor andere overlays (info/help/leeg-melding fallback) */
.overlay .sluit { position:absolute; top:8px; right:12px; cursor:pointer;
                  font-size:1.4rem; color:#666; }
.overlay .sluit:hover { color:#000; }

table.heat-tabel { width:100%; border-collapse:collapse; margin-top:10px; font-size:.85rem; }
table.heat-tabel th { background:var(--lichtblauw); color:var(--blauw);
                      padding:6px 4px; text-align:left; border-bottom:2px solid #b3cae6; }
table.heat-tabel td { padding:6px 4px; border-bottom:1px solid #eee; }
table.heat-tabel tr.mijn td { background:var(--accent); font-weight:600; }
.col-pos { width:30px; text-align:center; }
.col-snr { width:44px; text-align:right; font-weight:700; color:var(--blauw); }
.col-fin { width:40px; text-align:center; }
/* Fin-cijfers ROOD + bold (header-label blijft normale kleur). Staat
 * sinds 2026-05-26 direct na Snr ipv helemaal rechts — viel anders weg. */
table.heat-tabel td.col-fin { font-weight:700; color:#d32f2f; }

.spinner { display:inline-block; width:16px; height:16px;
           border:2px solid #ccc; border-top-color:var(--blauw);
           border-radius:50%; animation:spin .8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.leeg-melding { text-align:center; color:#888; padding:20px; font-style:italic; }

/* Pull-to-refresh indicator */
#ptr {
    position:fixed; top:0; left:0; right:0;
    background:var(--middenblauw); color:var(--wit);
    text-align:center; font-size:.85rem; padding:6px 0;
    transform:translateY(-100%); transition:transform .15s ease-out;
    z-index:900; pointer-events:none;
}
#ptr.zichtbaar { transform:translateY(0); }
#ptr.laadt { background:var(--blauw); }

/* Stempeltje rechtsonder dat laat zien wanneer de laatste auto-refresh was.
   Discreet; de user weet zo dat de pagina leeft zonder dat het opvalt.
   Verdwijnt niet onder de sponsor-footer omdat hij absolute daarboven staat. */
.auto-refresh-stempel {
    position:fixed; right:8px; bottom:8px; z-index:110;
    background:rgba(255,255,255,.9); color:#666;
    font-size:.7rem; padding:3px 8px; border-radius:10px;
    border:1px solid #dde3ea; pointer-events:none;
}
body.heeft-footer .auto-refresh-stempel { bottom:84px; }

/* ── Naamzoek-modal (1-op-1 uit /public) — multi-keuze bij dubbele
   startnummers of meerdere matches op naam-zoek. ── */
.naamzoek-modal {
    position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2500;
    display:flex; align-items:center; justify-content:center; padding:16px;
}
.naamzoek-box {
    background:var(--wit); border-radius:12px; max-width:480px; width:100%;
    max-height:80vh; display:flex; flex-direction:column;
    box-shadow:0 12px 40px rgba(0,0,0,.3); overflow:hidden;
}
.naamzoek-hdr {
    background:var(--blauw); color:var(--wit); padding:12px 16px;
    font-weight:700; display:flex; justify-content:space-between; align-items:center;
}
.naamzoek-body { overflow-y:auto; padding:8px 0; }
.naamzoek-rij {
    display:flex; align-items:center; gap:10px; padding:10px 14px;
    border-bottom:1px solid #eee; cursor:pointer; user-select:none;
}
.naamzoek-rij:hover { background:#f0f5fa; }
.naamzoek-rij input[type=checkbox] { width:18px; height:18px; flex-shrink:0; }
.naamzoek-rij-snr { font-weight:700; color:var(--blauw); min-width:34px; text-align:right; }
.naamzoek-rij-naam { flex:1; }
.naamzoek-rij-meta { font-size:.75rem; color:#888; }
.naamzoek-voet {
    border-top:1px solid #eee; padding:12px 14px;
    display:flex; gap:10px; justify-content:space-between; align-items:center;
}
.naamzoek-voet .aantal { font-size:.85rem; color:#666; }
.naamzoek-sluit { background:none; border:none; color:rgba(255,255,255,.85);
                  font-size:1.4rem; cursor:pointer; line-height:1; }

/* ── PWA install banner (1-op-1 uit /public) ── */
.pwa-banner {
    background: linear-gradient(135deg, var(--blauw), var(--middenblauw));
    color: var(--wit); padding: 10px 16px; display: flex; align-items: center;
    gap: 10px; font-size: .85rem; border-radius: 10px; margin: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
}
.pwa-banner-tekst { flex: 1; }
.pwa-banner-tekst b { display: block; font-size: .95rem; }
.pwa-banner .btn-install {
    background: var(--oranje); color: #fff; border: none; border-radius: 8px;
    padding: 8px 14px; font-weight: 700; font-size: .85rem; cursor: pointer;
    white-space: nowrap;
}
.pwa-banner .btn-install:active { transform: scale(.96); }
.pwa-banner .btn-sluit {
    background: none; border: none; color: rgba(255,255,255,.6);
    font-size: 1.2rem; cursor: pointer; padding: 0 4px; line-height: 1;
}
</style>
</head>
<body>
<div id="ptr" data-i18n="ptr_trek">↓ Trek verder om te vernieuwen</div>

<header>
    <div class="hdr-row-top">
        <div class="hdr-btns hdr-btns-left">
            <button class="btn-help btn-meldingen" id="btn-meldingen-overzicht" data-i18n-title="hdr_meldingen_title" title="Mededelingen voor deze wedstrijd">📢<span id="meldingen-badge" class="meld-badge" style="display:none">0</span></button>
            <button class="btn-help btn-lang" id="btn-lang" title="Language / Taal" aria-label="Switch language"></button>
        </div>
        <div class="hdr-center">
            <h1 data-i18n="hdr_titel">InlineComp – Coach</h1>
        </div>
        <div class="hdr-btns hdr-btns-right">
            <button class="btn-help" onclick="toonInfo()" data-i18n-title="hdr_info_title" title="Over InlineComp">i</button>
            <button class="btn-help" onclick="toonHelp()" data-i18n-title="hdr_help_title" title="Hoe werkt het?">?</button>
        </div>
    </div>
    <div class="sub" data-i18n="hdr_sub">Volg jouw rijders: programma, sancties en uitslagen</div>
</header>

<div id="pwa-banner" class="pwa-banner" style="display:none">
    <div class="pwa-banner-tekst">
        <b data-i18n="pwa_installeer_titel">Installeer InlineComp Coach</b>
        <span data-i18n="pwa_installeer_uitleg">Voeg toe aan je startscherm voor snelle toegang</span>
    </div>
    <button class="btn-install" id="pwa-install" data-i18n="pwa_btn_install">Installeer</button>
    <button class="btn-sluit" id="pwa-sluit" data-i18n-title="pwa_btn_sluit" title="Sluiten">&times;</button>
</div>

<div id="org-footer" class="org-footer">
    <div class="org-footer-inner">
        <span id="footer-org-logo"></span>
        <span id="footer-org-naam" class="org-footer-naam"></span>
        <div id="footer-sponsors" class="org-footer-sponsors"></div>
        <span id="footer-baan-logo"></span>
    </div>
</div>

<div class="container">

<!-- Setup-strook: klikbaar → opent modal met wedstrijd-keuze, rijder-
     selecties en huidige coach-lijst. Vervangt de altijd-zichtbare stap
     1/2/chips secties zodat er meer verticale ruimte over is voor het
     programma en de heat/uitslagen-tabs. -->
<div class="setup-strip" id="setup-strip" onclick="openSetupModal()"
     data-i18n-title="setup_strip_edit_title" title="Wijzig wedstrijd of rijders">
    <div class="setup-strip-tekst" id="setup-strip-tekst">
        <span class="setup-strip-empty" data-i18n="setup_strip_leeg">Kies je wedstrijd…</span>
    </div>
    <button class="setup-strip-edit" type="button"
            data-i18n-title="setup_strip_edit_title" title="Wijzigen">✎</button>
</div>

<!-- Setup-modal: stap 1 (wedstrijd) + stap 2 (rijder-select) + coach-lijst.
     Opent via de setup-strip bovenaan of automatisch bij eerste bezoek van
     de dag (localStorage-check op datum-key). -->
<div class="setup-modal-overlay" id="setup-modal" onclick="if(event.target===this)closeSetupModal()">
<div class="setup-modal-box">
<button class="setup-modal-close" type="button" onclick="closeSetupModal()"
        data-i18n-title="pwa_btn_sluit" title="Sluiten">&times;</button>
<h2 class="setup-modal-titel" data-i18n="setup_modal_titel">Wedstrijd &amp; rijders</h2>

<div class="card">
    <div class="stap-label"><span class="stap-nr">1</span> <span data-i18n="stap1_label">Kies je wedstrijd</span></div>
    <div class="filter-rij">
        <input type="checkbox" id="chk-oud"><label for="chk-oud" class="filter-chip" data-i18n="filter_eerder" data-i18n-title="filter_eerder_title" title="Eerdere wedstrijden">Eerder</label>
        <input type="checkbox" id="chk-vandaag" checked><label for="chk-vandaag" class="filter-chip" data-i18n="filter_vandaag">Vandaag</label>
        <input type="checkbox" id="chk-toekomst"><label for="chk-toekomst" class="filter-chip" data-i18n="filter_later" data-i18n-title="filter_later_title" title="Toekomstige wedstrijden">Later</label>
    </div>
    <select id="sel-comp" class="sel"><option value="" data-i18n="opt_kies_wedstrijd">— kies een wedstrijd —</option></select>
    <div id="comp-info" class="comp-info" style="display:none"></div>
</div>

<div id="sectie-selectie" class="card">
    <div class="stap-label"><span class="stap-nr">2</span> <span data-i18n="stap2_label">Voeg rijders toe aan je coach-lijst</span></div>
    <div class="stap-sub"><span data-i18n="sub_op_club">Op club</span> <small style="font-weight:400;color:#666" data-i18n="sub_meerdere">— meerdere tegelijk mogelijk</small></div>
    <div class="rij">
        <div class="sponsor-multi-wrap">
            <button type="button" id="btn-club-open" class="sel sponsor-multi-knop" disabled>
                <span id="club-multi-label" data-i18n="multi_kies_wedstrijd_eerst">— kies eerst een wedstrijd —</span>
                <span class="sponsor-multi-pijl">▾</span>
            </button>
            <div id="club-chips" class="sponsor-chips"></div>
            <div id="club-multi-paneel" class="sponsor-multi-paneel" hidden>
                <div class="sponsor-multi-acties">
                    <button type="button" class="btn-klein" id="club-multi-alles" data-i18n="btn_alle_aan">Alle aanvinken</button>
                    <button type="button" class="btn-klein" id="club-multi-niets" data-i18n="btn_niets_aan">Niets aanvinken</button>
                    <span id="club-multi-teller" class="sponsor-multi-teller">0 geselecteerd</span>
                </div>
                <div id="club-multi-lijst" class="sponsor-multi-lijst"></div>
                <div class="sponsor-multi-footer">
                    <button type="button" id="club-multi-klaar" class="btn-primair" data-i18n="btn_klaar">Klaar</button>
                </div>
            </div>
        </div>
    </div>
    <div class="stap-sub"><span data-i18n="sub_op_sponsor">Op sponsor</span> <small style="font-weight:400;color:#666" data-i18n="sub_meerdere">— meerdere tegelijk mogelijk</small></div>
    <div class="rij">
        <div class="sponsor-multi-wrap">
            <button type="button" id="btn-sponsor-open" class="sel sponsor-multi-knop" disabled>
                <span id="sponsor-multi-label" data-i18n="multi_kies_wedstrijd_eerst">— kies eerst een wedstrijd —</span>
                <span class="sponsor-multi-pijl">▾</span>
            </button>
            <div id="sponsor-chips" class="sponsor-chips"></div>
            <div id="sponsor-multi-paneel" class="sponsor-multi-paneel" hidden>
                <div class="sponsor-multi-acties">
                    <button type="button" class="btn-klein" id="sponsor-multi-alles" data-i18n="btn_alle_aan">Alle aanvinken</button>
                    <button type="button" class="btn-klein" id="sponsor-multi-niets" data-i18n="btn_niets_aan">Niets aanvinken</button>
                    <span id="sponsor-multi-teller" class="sponsor-multi-teller">0 geselecteerd</span>
                </div>
                <div id="sponsor-multi-lijst" class="sponsor-multi-lijst"></div>
                <div class="sponsor-multi-footer">
                    <button type="button" id="sponsor-multi-klaar" class="btn-primair" data-i18n="btn_klaar">Klaar</button>
                </div>
            </div>
        </div>
    </div>
    <div class="stap-sub" data-i18n="sub_op_snr_naam">Op startnummer, naam of licentie</div>
    <div class="rij">
        <input id="inp-snr" class="inp" type="text"
               data-i18n-placeholder="ph_snr_naam_lic"
               placeholder="Startnummer, naam (≥2 letters) of licentienr…"
               autocomplete="off" inputmode="text" disabled>
    </div>
    <button id="btn-toevoegen" class="btn-primair" disabled data-i18n="btn_toevoegen">Toevoegen</button>
    <div id="snr-feedback" style="font-size:.85rem;color:#b71c1c;min-height:18px;margin-top:6px"></div>
</div>

<div id="sectie-lijst" class="card" style="display:none">
    <div class="coach-hdr">
        <span id="coach-aantal">0 rijders</span>
        <button id="btn-wis-alles" class="btn btn-klein btn-wis" data-i18n="btn_wis_alles">Wis alles</button>
    </div>
    <div id="coach-chips" class="chips"></div>
</div>

<div class="setup-modal-klaar-rij">
    <button type="button" class="btn-primair" id="setup-modal-klaar"
            onclick="closeSetupModal()" data-i18n="btn_klaar">Klaar</button>
</div>

</div><!-- .setup-modal-box -->
</div><!-- .setup-modal-overlay -->

<div id="sectie-programma" class="card" style="display:none">
    <div class="tabs">
        <button class="tab-btn active" data-tab="programma" data-i18n="tab_programma">📋 Programma</button>
        <button class="tab-btn" data-tab="heats" data-i18n="tab_heats">🏃 Heats</button>
        <button class="tab-btn" data-tab="sancties" data-i18n="tab_sancties">⚠️ Sancties</button>
        <button class="tab-btn" data-tab="rondes" data-i18n="tab_rondes">📈 Rondes</button>
        <button class="tab-btn" data-tab="uitslagen" data-i18n="tab_uitslagen">📊 Uitslagen</button>
    </div>
    <div id="tab-programma" class="tab-pane active">
        <div id="programma"></div>
    </div>
    <div id="tab-heats" class="tab-pane">
        <div id="heats"></div>
    </div>
    <div id="tab-sancties" class="tab-pane">
        <div id="sancties"></div>
    </div>
    <div id="tab-rondes" class="tab-pane">
        <div class="rij">
            <select id="r-sel-cat" class="sel"><option value="" data-i18n="rondes_opt_kies_cat">— kies categorie —</option></select>
        </div>
        <div class="rij" id="r-afstand-rij" style="display:none">
            <select id="r-sel-afstand" class="sel"><option value="" data-i18n="rondes_opt_kies_afstand">— kies afstand —</option></select>
        </div>
        <div id="rondes-inhoud"></div>
    </div>
    <div id="tab-uitslagen" class="tab-pane">
        <div class="rij">
            <select id="u-sel-cat" class="sel"><option value="" data-i18n="uitsl_opt_kies_cat">— kies categorie —</option></select>
        </div>
        <div class="rij" id="u-afstand-rij" style="display:none">
            <select id="u-sel-afstand" class="sel"><option value="" data-i18n="uitsl_opt_kies_afstand">— kies afstand —</option></select>
        </div>
        <div id="uitslagen"></div>
    </div>
</div>

</div>
<script>
// ── i18n: NL / EN ─────────────────────────────────────────────────────────
// Shared i18n-helpers — herbruikt door public, coach (deze), jury, admin.
// PHP-include (geen extra HTTP-request) zodat één bron van waarheid is.
<?php
$i18nPath = __DIR__ . '/../js/i18n.js';
if (is_readable($i18nPath)) {
    readfile($i18nPath);
} else {
    echo "console.error('i18n.js niet gevonden op server (verwacht: ' + " . json_encode($i18nPath) . " + ') — upload het bestand via SFTP');\n";
    echo "alert('Taal-systeem niet geladen — i18n.js ontbreekt op de server. Upload js/i18n.js naar de juiste map.');\n";
}
?>

// ── App-versie (bijhouden bij elke user-visible wijziging) ─────────────────
// Formaat: H<uren>.<MM>.<DD>       (uren sinds InlineComp v0 op OH850, 2026-06-20 00:00)
// Rollover als de uren-teller onhandig lang wordt:
//   H9999+ → Y<jaren>.<MM>.<DD>    waar 1 Y = 1 jaar (~8760 uur)
// M (maanden) slaan we bewust over — anders komen we nooit bij Y ;)
// Bij bump: bereken nieuwe uren-count sinds 2026-06-20, update datum, en
// voeg een entry toe aan het "Wat is nieuw"-blok in toonHelp().
// Versie verschijnt onder de copyright in de i-modal.
const APP_VERSIE = 'H360.06.07';

// ── App-specifiek vertaal-woordenboek (NL + EN + DE + FR) ──────────────────
// Toggle via vlag-knop in header. Persisteert in localStorage onder 'ic_lang'
// (gedeeld met /public — als rijder NL kiest in public ziet hij coach óók in NL).
// Dynamische content (rendered via JS) gebruikt t('key'); statische HTML
// gebruikt data-i18n* attributen die applyI18n() bij init en bij toggle leest.
const T = {
    nl: {
        // ── Document ──
        page_title: 'InlineComp – Coach',
        // ── Header / static ──
        ptr_trek: '↓ Trek verder om te vernieuwen',
        hdr_meldingen_title: 'Mededelingen voor deze wedstrijd',
        hdr_info_title: 'Over InlineComp',
        hdr_help_title: 'Hoe werkt het?',
        hdr_titel: 'InlineComp – Coach',
        hdr_sub: 'Volg jouw rijders: programma, sancties en uitslagen',
        pwa_installeer_titel: 'Installeer InlineComp Coach',
        pwa_installeer_uitleg: 'Voeg toe aan je startscherm voor snelle toegang',
        pwa_btn_install: 'Installeer',
        pwa_btn_sluit: 'Sluiten',
        // ── Stap-labels en sub-koppen ──
        stap1_label: 'Kies je wedstrijd',
        stap2_label: 'Voeg rijders toe aan je coach-lijst',
        sub_op_club: 'Op club',
        sub_op_sponsor: 'Op sponsor',
        sub_op_snr_naam: 'Op startnummer, naam of licentie',
        sub_meerdere: '— meerdere tegelijk mogelijk',
        ph_snr_naam_lic: 'Startnummer, naam (≥2 letters) of licentienr…',
        // ── Filters ──
        filter_eerder: 'Eerder',
        filter_eerder_title: 'Eerdere wedstrijden',
        filter_vandaag: 'Vandaag',
        filter_later: 'Later',
        filter_later_title: 'Toekomstige wedstrijden',
        // ── Comps select ──
        opt_kies_filter: '— Kies tenminste één filter hierboven —',
        opt_kies_wedstrijd: '— kies een wedstrijd —',
        opt_binnenkort: '(binnenkort)',
        opt_fout_laden: 'Fout bij laden',
        // ── Multi-select (club + sponsor) ──
        multi_kies_wedstrijd_eerst: '— kies eerst een wedstrijd —',
        multi_kies_club: '— kies club(s) —',
        multi_kies_sponsor: '— kies sponsor(s) —',
        multi_geen_clubs: '— geen clubs in deze wedstrijd —',
        multi_geen_sponsors: '— geen sponsors in deze wedstrijd —',
        multi_geen_clubs_panel: 'Geen clubs in deze wedstrijd.',
        multi_geen_sponsors_panel: 'Geen sponsors in deze wedstrijd.',
        multi_club_gekozen_single: '{n} club gekozen — klik op Toevoegen',
        multi_club_gekozen_plural: '{n} clubs gekozen — klik op Toevoegen',
        multi_sponsor_gekozen_single: '{n} sponsor gekozen — klik op Toevoegen',
        multi_sponsor_gekozen_plural: '{n} sponsors gekozen — klik op Toevoegen',
        multi_geselecteerd: '{n} geselecteerd',
        multi_chip_klik_verwijder: 'Klik om te verwijderen',
        btn_alle_aan: 'Alle aanvinken',
        btn_niets_aan: 'Niets aanvinken',
        btn_klaar: 'Klaar',
        btn_toevoegen: 'Toevoegen',
        btn_wis_alles: 'Wis alles',
        // ── Coach-lijst ──
        coach_aantal_single: '{n} rijder geselecteerd',
        coach_aantal_plural: '{n} rijders geselecteerd',
        coach_leeg: 'Nog niemand geselecteerd — gebruik de selectors hierboven.',
        // ── Setup-strip + modal ──
        setup_strip_leeg: 'Kies je wedstrijd…',
        setup_strip_edit_title: 'Wijzig wedstrijd of rijders',
        setup_strip_rijders: 'rijders',
        setup_strip_1rijder: '1 rijder',
        setup_modal_titel: 'Wedstrijd & rijders',
        // ── Tabs ──
        tab_programma: '📋\nProgramma',
        tab_heats: '🏃\nHeats',
        tab_sancties: '⚠️\nSancties',
        tab_uitslagen: '📊\nUitslagen',
        tab_rondes: '📈\nRondes',
        // ── Uitslagen-tab dropdowns ──
        uitsl_opt_kies_cat: '— kies categorie —',
        uitsl_opt_kies_afstand: '— kies afstand —',
        uitsl_opt_kies_afstand_of_klassement: '— kies afstand of klassement —',
        uitsl_opt_geen_uitslagen: '(nog geen uitslagen beschikbaar)',
        uitsl_klassement_opt: '🏆 Klassement',
        uitsl_opt_laden: 'Laden…',
        uitsl_opt_afstand_fallback: 'Afstand',
        // ── Rondes-tab ──
        rondes_opt_kies_cat: '— kies categorie —',
        rondes_opt_kies_afstand: '— kies afstand —',
        rondes_kies_cat_hint: 'Kies eerst een categorie, dan een afstand.',
        rondes_kies_afstand_hint: 'Kies een afstand om de rondes te zien.',
        rondes_geen_rondes: 'Nog geen resultaten voor deze afstand.',
        rondes_pending: 'nog niet compleet',
        rondes_ronde_serie: 'Serie',
        rondes_ronde_kwartfinale: 'Kwartfinale',
        rondes_ronde_halve_finale: 'Halve finale',
        rondes_ronde_runner_up: 'Runner-up',
        rondes_ronde_finale_a: 'A-finale',
        rondes_ronde_finale_b: 'B-finale',
        rondes_col_pos: 'Pos',
        rondes_col_snr: 'Snr',
        rondes_col_naam: 'Naam',
        rondes_col_kwal: 'Q/q',
        rondes_col_rondes: 'Rnd',
        rondes_col_pkpt: 'Pnt',
        rondes_col_tijd: 'Tijd',
        rondes_col_sanctie: 'Sanctie',
        rondes_col_fin: 'Fin',
        // ── Status-labels (idx 0-5) ──
        status_0: 'Niet bevestigd',
        status_1: 'Bevestigd',
        status_2: 'Afgemeld',
        status_3: 'Afgem. bij org.',
        status_4: 'Niet getekend',
        status_5: 'Bev. bij org.',
        status_label: 'Status',
        // ── Sanctie-codes uitleg ──
        sanc_W1: '1e waarschuwing',
        sanc_W2: '2e waarschuwing',
        sanc_FS: 'Valse start',
        sanc_RR: 'Rank reduction',
        sanc_DQ_TF: 'Diskwalificatie technische fout',
        sanc_DQ_SF: 'Diskwalificatie sport fout',
        sanc_DQ_DF: 'Diskwalificatie disciplinaire fout',
        sanc_DNS: 'Niet gestart',
        sanc_DNF: 'Niet gefinisht',
        // ── Rondes (badges) ──
        ronde_serie: 'Serie',
        ronde_kf: 'KF',
        ronde_hf: 'HF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Runner-up',
        // ── Programma-blok labels ──
        prog_blok_pauze: 'Pauze',
        prog_blok_inrijden: 'Inrijden',
        prog_blok_wedstrijdstart: 'Wedstrijd start',
        prog_blok_ceremonie: 'Ceremonie',
        prog_blok_herstart: 'Herstart',
        prog_blok_min: 'min',
        prog_dag_alle: 'Alle',
        prog_dag: 'Dag',
        prog_afstand_alle: 'Alle',
        prog_filter_alle_dagen: 'Alle dagen',
        prog_filter_alle_afstanden: 'Alle afstanden',
        prog_samenvat_heat_1: '1 heat',
        prog_samenvat_heat_n: '{n} heats',
        prog_filter_mijn: '👥 Mijn rijders',
        prog_filter_te_rijden: '⏳ Nog te rijden',
        prog_klap_alles_uit:  'Inklappen',
        prog_klap_alles_in:   'Uitklappen',
        prog_klap_mijn:       'Mijn rijders',
        prog_klap_mijn_tooltip: 'Aantal van jouw rijders in deze groep',
        prog_groep_status_klaar:  'Alle ritten in deze groep zijn verreden',
        prog_groep_status_deels:  'Uitslagverwerking bezig — deels verreden',
        prog_groep_status_geloot: 'Loting bekend voor alle ritten',
        coach_pw_titel: 'Coach-toegang',
        coach_pw_uitleg: 'De Coach-app is afgeschermd. Vraag het wachtwoord bij de wedstrijdorganisator of kijk op de Coach-poster.',
        coach_pw_ok: 'OK',
        coach_pw_fout: 'Onjuist wachtwoord',
        coach_pw_neterr: 'Geen verbinding — probeer opnieuw',
        prog_combi_kop: '🔗 Gecombineerde rit — rijden tegelijk',
        prog_laden: 'Programma wordt geladen…',
        prog_geen: 'Nog geen programma bekend.',
        prog_geen_startlijst: 'nog geen startlijst',
        prog_startlijst_nb: 'Startlijst is nog niet beschikbaar.',
        // ── Heats-tab ──
        heats_geen_rijders: 'Nog geen rijders in je lijst.',
        heats_geen_inschrijvingen: 'Rijder heeft geen inschrijvingen in deze wedstrijd.',
        heats_cat_fallback: '(categorie)',
        heats_afstand_fallback: '(afstand)',
        heats_niet_geloot_geen_heats: '⏳ Nog niet geloot — geen heats beschikbaar',
        heats_geen_programma: '⏳ Nog geen programma voor deze categorie',
        heats_vorige_niet_compleet: '⏳ Vorige ronde nog niet compleet',
        heats_niet_geplaatst: 'niet geplaatst',
        heats_niet_geloot: '⏳ Nog niet geloot',
        heats_heat: 'Heat',
        heats_startpos: 'startpos {pos}',
        // ── Sancties-tab ──
        sanc_geen_rijders: 'Nog geen rijders in je lijst.',
        sanc_geen: 'Geen sancties.',
        // ── Uitslagen ──
        uit_leeg: 'Er zijn nog geen uitslagen bevestigd voor deze wedstrijd.',
        uit_geen_uitslagen: 'Geen uitslagen beschikbaar.',
        uit_geen_klassement: 'Geen klassement beschikbaar.',
        uit_laden: 'Laden…',
        uit_fout: 'Fout: {msg}',
        // ── Tabel-headers ──
        col_pos: '#',
        col_snr: 'Snr',
        col_naam: 'Naam',
        col_rnd: 'Rnd',
        col_pnt: 'Pnt',
        col_tijd: 'Tijd',
        col_fin: 'Fin',
        col_rang: '#',
        col_tot: 'Tot',
        heat_bruto_gemeten: 'gemeten',
        // ── Bevestig-modal ──
        bev_titel: 'Bevestigen',
        bev_ok: 'OK',
        bev_annuleer: 'Annuleren',
        // Rijder verwijderen
        bev_verwijder_titel: 'Rijder verwijderen?',
        bev_verwijder_tekst: 'Wil je <b>{naam}</b> ({snr}) uit je coach-lijst verwijderen?',
        bev_verwijder_ok: 'Ja, verwijder',
        bev_verwijder_snr_fallback: 'Startnr {snr}',
        // Wis alles
        bev_wis_titel: 'Coach-lijst wissen?',
        bev_wis_tekst_single: 'Je staat op het punt <b>alle {n} rijder</b> uit je coach-lijst te verwijderen.<br><br>Dit kan niet ongedaan gemaakt worden.',
        bev_wis_tekst_plural: 'Je staat op het punt <b>alle {n} rijders</b> uit je coach-lijst te verwijderen.<br><br>Dit kan niet ongedaan gemaakt worden.',
        bev_wis_ok: 'Ja, wis alles',
        // ── Naam-zoek modal ──
        nz_matches_voor: '{n} matches voor {label}',
        nz_sluit: 'Sluiten',
        nz_al_in_lijst: 'al in lijst',
        nz_vink_aan: 'Vink aan wie je wilt toevoegen',
        nz_toevoegen: 'Toevoegen',
        // ── Toevoeg-flow feedback ──
        fb_kies_iets: 'Kies een club, sponsor, of vul een startnummer / naam / licentie in.',
        fb_server_druk: 'Server tijdelijk druk — probeer over 5 seconden',
        fb_server_fout: 'Server-fout ({status})',
        fb_netwerk_fout: 'Netwerkfout bij ophalen rijders',
        fb_rijders_van_single: '{aantal} rijder(s) van {stukken}',
        fb_rijders_van_geen: '{stukken}: geen nieuwe rijders',
        fb_clubs_single: '{n} club',
        fb_clubs_plural: '{n} clubs',
        fb_sponsors_single: '{n} sponsor',
        fb_sponsors_plural: '{n} sponsors',
        fb_toegevoegd: 'Toegevoegd: {lijst}',
        fb_label_snr: 'Startnummer {term}',
        fb_label_licentie: 'Licentie {term}',
        fb_label_naam: 'Naam "{term}"',
        fb_lookup_mislukt: '{label}: lookup mislukt ({msg})',
        fb_label_error: '{label}: {error}',
        fb_label_niet_gevonden: '{label} niet gevonden in deze wedstrijd',
        fb_stond_al_in_lijst: '{naam}: stond al in lijst',
        fb_naam_snr: '{naam} (snr {snr})',
        // ── Connection banner ──
        conn_geen_internet: '📡 Geen internet — ververst zodra de verbinding terug is',
        conn_server_down: '⚠ Server niet bereikbaar — opnieuw proberen…',
        conn_laatste_update: 'laatste update {tijd}',
        // ── PTR ──
        ptr_laat_los: '↑ Laat los om te vernieuwen',
        ptr_vernieuwen: '⟳ Vernieuwen…',
        ptr_bijgewerkt: '✓ Bijgewerkt',
        ptr_fout: '⚠ Fout bij vernieuwen',
        ptr_wachten: '⏳ Even wachten ({s}s)',
        // ── Auto-refresh ──
        auto_refresh_title: 'Laatste automatische verversing',
        // ── Mededelingen ──
        meld_kop: '📢 Mededelingen',
        meld_tot: ' tot ',
        meld_begrepen: '✓ Begrepen',
        // ── Info modal ──
        info_titel: 'Over InlineComp Coach',
        info_h_wat: 'Wat is dit?',
        info_p_wat1_html: 'De <b>Coach-view</b> is een dashboard voor coaches: je bouwt per wedstrijd een eigen lijst met rijders en ziet vervolgens hun programma, status, sancties en uitslagen in één oogopslag.',
        info_p_wat2: 'Je kunt een heel clubteam in één keer toevoegen, rijders op sponsor selecteren, of losse startnummers toevoegen. Je lijst wordt lokaal op je telefoon bewaard (per wedstrijd) — dus een refresh of een terugkeer naar de pagina laat \'m intact.',
        info_h_login: 'Toegang met wachtwoord',
        info_p_login_html: 'Coach is afgeschermd met een gedeeld wachtwoord. Coach je <b>meer dan 3 rijders</b>? Vraag het wachtwoord bij de wedstrijdorganisator. Voor 1-3 rijders werkt de <a href="../public/">public app</a> vaak prima. Wedstrijddata is publiek (KNSB-feed); je persoonlijke rijderslijst blijft lokaal op je telefoon.',
        info_h_tip: 'Tip: toevoegen aan startscherm',
        info_p_tip: 'Op je telefoon: open deze pagina in Safari/Chrome → menu → "Zet op startscherm". Dan opent-ie als een app en heb je \'m direct bij de hand aan de rand van de baan.',
        info_h_dev: 'In ontwikkeling',
        info_p_dev: 'Deze coach-view wordt actief doorontwikkeld. Feedback is zeer welkom!',
        info_h_contact_html: 'Contact &amp; feedback',
        info_p_contact: 'Heb je een vraag, suggestie of bug gevonden? Laat het weten:',
        info_h_stats: 'Anonieme bezoek-statistieken',
        info_p_stats_html: 'We tellen anoniem aantal bezoekers, actieve sessies en piek gelijktijdig online — puur om te zien hoe veel de app wordt gebruikt en om de hosting stabiel te houden. Er worden <b>geen IP-adressen of persoonsgegevens</b> opgeslagen en er zijn <b>geen derde partijen</b> betrokken.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'Hoe werkt de Coach-view?',
        help_h_start: 'Aan de slag',
        help_stap1_html: 'Kies je <b>wedstrijd</b>.',
        help_stap2_html: 'Kies je <b>rijders</b> via club, sponsor of startnummer, en klik op <b>Toevoegen</b>.',
        help_stap3_html: 'Klik op <b>Klaar</b>.',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'Na het zoeken zie je <b>5 tabs</b>:',
        help_h_prog: 'Programma',
        help_p_prog_html: 'Toont alle ritten van de wedstrijd. Ritten waar minstens één van jouw rijders in zit zijn <b>geel gemarkeerd</b> met een strip van hun startnummers aan de rechterkant. Tik een rit aan om de volledige startlijst te zien — jouw rijders zijn opnieuw geel gemarkeerd. Bovenaan filter je op afstand; met de balk daaronder klap je binnen die afstand groepen in of uit.',
        help_h_sanc: 'Sancties',
        help_p_sanc1: 'Per rijder uit jouw lijst een kaartje met:',
        help_p_sanc_lijst_html: '<li><b>Status-badge</b> (Bevestigd / Niet getekend / Afgemeld / …)</li><li>Alle <b>sancties</b> die in heats zijn geregistreerd (W1, W2, FS, DQ-SF, DNF, …)</li>',
        help_p_sanc2_html: 'Let op <b>🚨 Niet getekend</b> — dan moet de rijder zélf snel even naar de jury-tafel om te tekenen (niet jij als coach, niet de ouders, alleen de rijder zelf).',
        help_h_uitsl: 'Uitslagen',
        help_p_uitsl: 'Kies een categorie + afstand om de volledige uitslag te zien, of bekijk het klassement. Ook hier worden jouw eigen rijders geel gemarkeerd.',
        help_h_auto: 'Automatisch bijgewerkt',
        help_p_auto_html: 'De pagina ververst zichzelf elke minuut zolang het tabblad zichtbaar is. Het tijdstip van de laatste verversing zie je rechtsboven (<b>🔄 HH:MM</b>). Direct verversen kan ook: trek de pagina <b>naar beneden</b> (pull-to-refresh) of dubbelklik op de blauwe kop.',
        help_h_meld: 'Mededelingen',
        help_p_meld_html: 'Bovenaan verschijnt een <b>📢-knop</b> zodra er een mededeling van de organisatie actief is. Belangrijke aankondigingen verschijnen automatisch als pop-up en blijven daarna onder die knop bereikbaar.',
        help_h_priv: 'Privacy',
        help_p_priv: 'Je coach-lijst wordt alleen lokaal op je telefoon bewaard (localStorage). Niemand anders ziet wie je op je lijst hebt staan.',
        // ── Nieuwe kop-secties: Heats en Rondes ──
        help_h_heats: 'Heats',
        help_p_heats_html: '<b>Heats</b> — per rijder op je coach-lijst een overzicht van al zijn heats: heatnummer + startpositie per ronde (serie, kwart, halve, A-finale, kleine finale) van elke afstand. Bovenaan het rijder-blok een statusregel per afstand (bijv. <b>✓ Bevestigd</b> of <b>✓ Bev. bij org.</b>). Bij nog niet gelote rondes staat "Nog niet geloot"; is de vorige ronde nog niet compleet, dan "wacht op vorige ronde". Rijders gesorteerd op startnummer.',
        // Coach heats-mockup labels
        mock_status_bev: '✓ Bevestigd',
        mock_status_bev_org: '✓ Bev. bij org.',
        mock_heat_lbl: 'Heat',
        mock_startpos_lbl: 'startpos',
        mock_ronde_halve: 'HF',
        mock_ronde_ru:    'Runner-up',
        mock_wacht_loting: '🕒 Nog niet geloot',
        mock_niet_geplaatst: 'Niet geplaatst',
        help_h_rondes: 'Rondes',
        help_p_rondes_html: '<b>Rondes</b> — per-ronde uitslagen van alle DC\'s waarvoor je rijders volgt. Zichtbaar is de plek per ronde en of doorstroom naar de volgende ronde heeft plaatsgevonden.',
        // ── Info-modal versienummer ──
        info_versie: 'Versie',
        // ── Wat is nieuw ──
        nieuw_jump: 'Direct naar Wat is nieuw ↓',
        nieuw_h: 'Wat is nieuw?',
        nieuw_intro: 'Kort overzicht van recente wijzigingen. Voor terugkerende gebruikers een compacte samenvatting van de aanpassingen.',
        nieuw_v100_7_html: '<b>Rondes-tab</b> — nieuw tabblad met per-ronde uitslagen van alle DC\'s waarvoor je rijders volgt (serie, kwart, halve finale, A-finale, kleine finale). Zichtbaar is welke plek per ronde is behaald en of doorstroom naar de volgende ronde heeft plaatsgevonden.',
        nieuw_v100_2_html: '<b>Snelle wedstrijd-selectie</b> in een nieuw <b>openings-venster</b> met filter-knoppen <i>Eerder / Vandaag / Later</i>. Verschijnt automatisch bij het openen van de app en sluit zodra een wedstrijd is geselecteerd — directe focus op de keuze, daarna de volledige ruimte voor het overzicht.',
        nieuw_v100_4_html: '<b>Bruto-tijd</b> zichtbaar naast de netto-tijd — herkenbaar aan ✋ (handmatige correctie) of 📷 (foto-finish correctie). Zo is in de heat-tabellen zichtbaar wanneer een correctie op de klokwaarde is toegepast.',
        nieuw_v100_11_html: '<b>Klassering per categorie</b> in de Uitslagen-tab — bij gecombineerde races (bv. HJA + HSA samen) verschijnt naast de overall rang een aparte kolom per categorie, zodat in één oogopslag zichtbaar is welke plek de rijder binnen de eigen categorie heeft behaald.',
        nieuw_v100_9_html: '<b>Kleine verbeteringen</b> voor de weergave op smalle schermen en de navigatie — waaronder filter-knoppen die weer binnen het openings-venster passen.',
        nieuw_v100_13_html: '<b>Filter op afstand + inklap-balk</b> in het programma — kies één afstand (bv. 500m) en gebruik de segment-knoppen <i>Inklappen / Uitklappen / Mijn</i> om binnen die afstand groepen dicht te klappen, allemaal open te zetten, of alleen de ritten van de rijders op je lijst te tonen.',
        nieuw_v100_14_html: '<b>Kleine verbeteringen en bug-fixes</b> in de weergave van het programma.',
        // ── Mockup-labels ──
        mock_venster_titel: 'Wedstrijd & rijders',
        mock_kies_w: 'Kies je wedstrijd',
        mock_kies_rijders: 'Voeg rijders toe aan je coach-lijst',
        mock_voorbeeld_w: 'Voorbeeldwedstrijd — 19 april 2026',
        mock_op_club:     'Op club',
        mock_kies_club:   '— kies club(s) —',
        mock_op_sponsor:  'Op sponsor',
        mock_kies_sponsor:'— kies sponsor(s) —',
        mock_op_snr:      'Op startnummer, naam of licentie',
        mock_snr_lic:     'Startnummer, naam (≥2 letters)',
        mock_btn_start:   'Toevoegen',
        mock_geselecteerd:'0 rijders geselecteerd',
        mock_btn_klaar:   'Klaar',
        mock_ronde_serie: 'Serie',
        mock_ronde_finale: 'Finale',
        mock_col_fin:  'Fin',
        mock_col_snr:  'St#',
        mock_col_naam: 'Naam',
        mock_col_tijd: 'Tijd',
        mock_col_rang: '#',
        mock_jouw_rijder: 'Jouw rijder',
    },
    en: {
        // ── Document ──
        page_title: 'InlineComp – Coach',
        // ── Header / static ──
        ptr_trek: '↓ Pull further to refresh',
        hdr_meldingen_title: 'Announcements for this race',
        hdr_info_title: 'About InlineComp',
        hdr_help_title: 'How does it work?',
        hdr_titel: 'InlineComp – Coach',
        hdr_sub: 'Track your skaters: program, sanctions and results',
        pwa_installeer_titel: 'Install InlineComp Coach',
        pwa_installeer_uitleg: 'Add to your home screen for quick access',
        pwa_btn_install: 'Install',
        pwa_btn_sluit: 'Close',
        // ── Stap-labels en sub-koppen ──
        stap1_label: 'Choose your race',
        stap2_label: 'Add skaters to your coach list',
        sub_op_club: 'By club',
        sub_op_sponsor: 'By sponsor',
        sub_op_snr_naam: 'By start number, name or license',
        sub_meerdere: '— multiple at once possible',
        ph_snr_naam_lic: 'Start number, name (≥2 letters) or license nr…',
        // ── Filters ──
        filter_eerder: 'Earlier',
        filter_eerder_title: 'Earlier races',
        filter_vandaag: 'Today',
        filter_later: 'Later',
        filter_later_title: 'Upcoming races',
        // ── Comps select ──
        opt_kies_filter: '— Select at least one filter above —',
        opt_kies_wedstrijd: '— choose a race —',
        opt_binnenkort: '(coming soon)',
        opt_fout_laden: 'Loading failed',
        // ── Multi-select (club + sponsor) ──
        multi_kies_wedstrijd_eerst: '— choose a race first —',
        multi_kies_club: '— choose club(s) —',
        multi_kies_sponsor: '— choose sponsor(s) —',
        multi_geen_clubs: '— no clubs in this race —',
        multi_geen_sponsors: '— no sponsors in this race —',
        multi_geen_clubs_panel: 'No clubs in this race.',
        multi_geen_sponsors_panel: 'No sponsors in this race.',
        multi_club_gekozen_single: '{n} club selected — click Add',
        multi_club_gekozen_plural: '{n} clubs selected — click Add',
        multi_sponsor_gekozen_single: '{n} sponsor selected — click Add',
        multi_sponsor_gekozen_plural: '{n} sponsors selected — click Add',
        multi_geselecteerd: '{n} selected',
        multi_chip_klik_verwijder: 'Click to remove',
        btn_alle_aan: 'Check all',
        btn_niets_aan: 'Uncheck all',
        btn_klaar: 'Done',
        btn_toevoegen: 'Add',
        btn_wis_alles: 'Clear all',
        // ── Coach-lijst ──
        coach_aantal_single: '{n} skater selected',
        coach_aantal_plural: '{n} skaters selected',
        coach_leeg: 'No one selected yet — use the selectors above.',
        // ── Setup-strip + modal ──
        setup_strip_leeg: 'Choose your race…',
        setup_strip_edit_title: 'Change race or skaters',
        setup_strip_rijders: 'skaters',
        setup_strip_1rijder: '1 skater',
        setup_modal_titel: 'Race & skaters',
        // ── Tabs ──
        tab_programma: '📋\nProgram',
        tab_heats: '🏃\nHeats',
        tab_sancties: '⚠️\nSanctions',
        tab_uitslagen: '📊\nResults',
        tab_rondes: '📈\nRounds',
        // ── Uitslagen-tab dropdowns ──
        uitsl_opt_kies_cat: '— choose category —',
        uitsl_opt_kies_afstand: '— choose distance —',
        uitsl_opt_kies_afstand_of_klassement: '— choose distance or standings —',
        uitsl_opt_geen_uitslagen: '(no results available yet)',
        uitsl_klassement_opt: '🏆 Standings',
        uitsl_opt_laden: 'Loading…',
        uitsl_opt_afstand_fallback: 'Distance',
        // ── Rondes-tab ──
        rondes_opt_kies_cat: '— choose category —',
        rondes_opt_kies_afstand: '— choose distance —',
        rondes_kies_cat_hint: 'Choose a category first, then a distance.',
        rondes_kies_afstand_hint: 'Choose a distance to see the rounds.',
        rondes_geen_rondes: 'No results yet for this distance.',
        rondes_pending: 'not yet complete',
        rondes_ronde_serie: 'Series',
        rondes_ronde_kwartfinale: 'Quarterfinal',
        rondes_ronde_halve_finale: 'Semifinal',
        rondes_ronde_runner_up: 'Runner-up',
        rondes_ronde_finale_a: 'A-final',
        rondes_ronde_finale_b: 'B-final',
        rondes_col_pos: 'Pos',
        rondes_col_snr: 'Bib',
        rondes_col_naam: 'Name',
        rondes_col_kwal: 'Q/q',
        rondes_col_rondes: 'Lap',
        rondes_col_pkpt: 'Pts',
        rondes_col_tijd: 'Time',
        rondes_col_sanctie: 'Sanction',
        rondes_col_fin: 'Fin',
        // ── Status-labels (idx 0-5) ──
        status_0: 'Not confirmed',
        status_1: 'Confirmed',
        status_2: 'Withdrawn',
        status_3: 'Withdrawn by org.',
        status_4: 'Not signed in',
        status_5: 'Confirmed by org.',
        status_label: 'Status',
        // ── Sanctie-codes uitleg ──
        sanc_W1: '1st warning',
        sanc_W2: '2nd warning',
        sanc_FS: 'False start',
        sanc_RR: 'Rank reduction',
        sanc_DQ_TF: 'Disqualification — technical fault',
        sanc_DQ_SF: 'Disqualification — sport fault',
        sanc_DQ_DF: 'Disqualification — disciplinary fault',
        sanc_DNS: 'Did not start',
        sanc_DNF: 'Did not finish',
        // ── Rondes (badges) ──
        ronde_serie: 'Series',
        ronde_kf: 'QF',
        ronde_hf: 'SF',
        ronde_finale: 'Final',
        ronde_b_finale: 'B-Final',
        ronde_runner_up: 'Runner-up',
        // ── Programma-blok labels ──
        prog_blok_pauze: 'Break',
        prog_blok_inrijden: 'Warm-up',
        prog_blok_wedstrijdstart: 'Race start',
        prog_blok_ceremonie: 'Ceremony',
        prog_blok_herstart: 'Restart',
        prog_blok_min: 'min',
        prog_dag_alle: 'All',
        prog_dag: 'Day',
        prog_afstand_alle: 'All',
        prog_filter_alle_dagen: 'All days',
        prog_filter_alle_afstanden: 'All distances',
        prog_samenvat_heat_1: '1 heat',
        prog_samenvat_heat_n: '{n} heats',
        prog_filter_mijn: '👥 My skaters',
        prog_filter_te_rijden: '⏳ Upcoming',
        prog_klap_alles_uit:  'Collapse',
        prog_klap_alles_in:   'Expand',
        prog_klap_mijn:       'My skaters',
        prog_klap_mijn_tooltip: 'Number of your skaters in this group',
        prog_groep_status_klaar:  'All races in this group have been raced',
        prog_groep_status_deels:  'Result processing ongoing — partially raced',
        prog_groep_status_geloot: 'Draw complete for all races',
        coach_pw_titel: 'Coach access',
        coach_pw_uitleg: 'The Coach app is restricted. Ask the race organiser for the password or check the Coach poster.',
        coach_pw_ok: 'OK',
        coach_pw_fout: 'Incorrect password',
        coach_pw_neterr: 'No connection — please try again',
        prog_combi_kop: '🔗 Combined race — skating together',
        prog_laden: 'Loading program…',
        prog_geen: 'No program known yet.',
        prog_geen_startlijst: 'no start list yet',
        prog_startlijst_nb: 'Start list is not available yet.',
        // ── Heats-tab ──
        heats_geen_rijders: 'No skaters in your list yet.',
        heats_geen_inschrijvingen: 'Skater has no entries in this race.',
        heats_cat_fallback: '(category)',
        heats_afstand_fallback: '(distance)',
        heats_niet_geloot_geen_heats: '⏳ Draw not done yet — no heats available',
        heats_geen_programma: '⏳ No program for this category yet',
        heats_vorige_niet_compleet: '⏳ Previous round not yet complete',
        heats_niet_geplaatst: 'not placed',
        heats_niet_geloot: '⏳ Draw not done yet',
        heats_heat: 'Heat',
        heats_startpos: 'start pos {pos}',
        // ── Sancties-tab ──
        sanc_geen_rijders: 'No skaters in your list yet.',
        sanc_geen: 'No sanctions.',
        // ── Uitslagen ──
        uit_leeg: 'No results have been confirmed for this race yet.',
        uit_geen_uitslagen: 'No results available.',
        uit_geen_klassement: 'No standings available.',
        uit_laden: 'Loading…',
        uit_fout: 'Error: {msg}',
        // ── Tabel-headers ──
        col_pos: '#',
        col_snr: 'Nr',
        col_naam: 'Name',
        col_rnd: 'Lap',
        col_pnt: 'Pts',
        col_tijd: 'Time',
        heat_bruto_gemeten: 'measured',
        col_fin: 'Fin',
        col_rang: '#',
        col_tot: 'Tot',
        // ── Bevestig-modal ──
        bev_titel: 'Confirm',
        bev_ok: 'OK',
        bev_annuleer: 'Cancel',
        // Rijder verwijderen
        bev_verwijder_titel: 'Remove skater?',
        bev_verwijder_tekst: 'Do you want to remove <b>{naam}</b> ({snr}) from your coach list?',
        bev_verwijder_ok: 'Yes, remove',
        bev_verwijder_snr_fallback: 'Start nr {snr}',
        // Wis alles
        bev_wis_titel: 'Clear coach list?',
        bev_wis_tekst_single: 'You are about to remove <b>all {n} skater</b> from your coach list.<br><br>This cannot be undone.',
        bev_wis_tekst_plural: 'You are about to remove <b>all {n} skaters</b> from your coach list.<br><br>This cannot be undone.',
        bev_wis_ok: 'Yes, clear all',
        // ── Naam-zoek modal ──
        nz_matches_voor: '{n} matches for {label}',
        nz_sluit: 'Close',
        nz_al_in_lijst: 'already in list',
        nz_vink_aan: 'Check who you want to add',
        nz_toevoegen: 'Add',
        // ── Toevoeg-flow feedback ──
        fb_kies_iets: 'Choose a club, sponsor, or enter a start number / name / license.',
        fb_server_druk: 'Server temporarily busy — try again in 5 seconds',
        fb_server_fout: 'Server error ({status})',
        fb_netwerk_fout: 'Network error fetching skaters',
        fb_rijders_van_single: '{aantal} skater(s) from {stukken}',
        fb_rijders_van_geen: '{stukken}: no new skaters',
        fb_clubs_single: '{n} club',
        fb_clubs_plural: '{n} clubs',
        fb_sponsors_single: '{n} sponsor',
        fb_sponsors_plural: '{n} sponsors',
        fb_toegevoegd: 'Added: {lijst}',
        fb_label_snr: 'Start number {term}',
        fb_label_licentie: 'License {term}',
        fb_label_naam: 'Name "{term}"',
        fb_lookup_mislukt: '{label}: lookup failed ({msg})',
        fb_label_error: '{label}: {error}',
        fb_label_niet_gevonden: '{label} not found in this race',
        fb_stond_al_in_lijst: '{naam}: already in list',
        fb_naam_snr: '{naam} (nr {snr})',
        // ── Connection banner ──
        conn_geen_internet: '📡 No internet — will refresh when the connection returns',
        conn_server_down: '⚠ Server unreachable — retrying…',
        conn_laatste_update: 'last update {tijd}',
        // ── PTR ──
        ptr_laat_los: '↑ Release to refresh',
        ptr_vernieuwen: '⟳ Refreshing…',
        ptr_bijgewerkt: '✓ Updated',
        ptr_fout: '⚠ Refresh error',
        ptr_wachten: '⏳ Please wait ({s}s)',
        // ── Auto-refresh ──
        auto_refresh_title: 'Time of last auto-refresh',
        // ── Mededelingen ──
        meld_kop: '📢 Announcements',
        meld_tot: ' until ',
        meld_begrepen: '✓ Understood',
        // ── Info modal ──
        info_titel: 'About InlineComp Coach',
        info_h_wat: 'What is this?',
        info_p_wat1_html: 'The <b>Coach view</b> is a dashboard for coaches: you build a personal list of skaters per race and then see their program, status, sanctions and results at a glance.',
        info_p_wat2: 'You can add a whole club team at once, select skaters by sponsor, or add individual start numbers. Your list is stored locally on your phone (per race) — so a refresh or returning to the page keeps it intact.',
        info_h_login: 'Password-protected access',
        info_p_login_html: 'Coach is protected by a shared password. Coaching <b>more than 3 skaters</b>? Ask the race organiser for the password. For 1-3 skaters the <a href="../public/">public app</a> often does the job. Race data is public (KNSB feed); your personal skater list stays local on your phone.',
        info_h_tip: 'Tip: add to home screen',
        info_p_tip: 'On your phone: open this page in Safari/Chrome → menu → "Add to Home Screen". It then opens like an app and you have it at hand right at the side of the track.',
        info_h_dev: 'In development',
        info_p_dev: 'This coach view is actively being developed. Feedback is most welcome!',
        info_h_contact_html: 'Contact &amp; feedback',
        info_p_contact: 'Have a question, suggestion or found a bug? Let us know:',
        info_h_stats: 'Anonymous visit statistics',
        info_p_stats_html: 'We anonymously count visitor numbers, active sessions and peak concurrent users — purely to see how much the app is used and to keep hosting stable. <b>No IP addresses or personal data</b> are stored and <b>no third parties</b> are involved.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'How does the Coach view work?',
        help_h_start: 'Getting started',
        help_stap1_html: 'Choose your <b>race</b>.',
        help_stap2_html: 'Choose your <b>skaters</b> by club, sponsor or start number, and click <b>Add</b>.',
        help_stap3_html: 'Click <b>Done</b>.',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'After searching you see <b>5 tabs</b>:',
        help_h_prog: 'Program',
        help_p_prog_html: 'Shows all races of the meet. Races containing at least one of your skaters are <b>highlighted in yellow</b> with a strip of their start numbers on the right. Tap a race to view the full start list — your skaters are again highlighted in yellow. Filter by distance at the top; use the bar below to collapse or expand groups within that distance.',
        help_h_sanc: 'Sanctions',
        help_p_sanc1: 'For each skater in your list a card with:',
        help_p_sanc_lijst_html: '<li><b>Status badge</b> (Confirmed / Not signed in / Withdrawn / …)</li><li>All <b>sanctions</b> registered in heats (W1, W2, FS, DQ-SF, DNF, …)</li>',
        help_p_sanc2_html: 'Watch out for <b>🚨 Not signed in</b> — then the skater has to quickly go to the jury desk themselves to sign in (not you as coach, not the parents, only the skater).',
        help_h_uitsl: 'Results',
        help_p_uitsl: 'Choose a category + distance to see the full result, or view the standings. Here too your own skaters are highlighted in yellow.',
        help_h_auto: 'Automatically updated',
        help_p_auto_html: 'The page refreshes itself every minute as long as the tab is visible. The time of the last refresh is shown in the top right (<b>🔄 HH:MM</b>). Refresh immediately also works: pull the page <b>downwards</b> (pull-to-refresh) or double-click the blue header.',
        help_h_meld: 'Announcements',
        help_p_meld_html: 'At the top a <b>📢 button</b> appears as soon as there is an active announcement from the organization. Important announcements pop up automatically and remain accessible afterwards via that button.',
        help_h_priv: 'Privacy',
        help_p_priv: 'Your coach list is only stored locally on your phone (localStorage). Nobody else sees who is on your list.',
        help_h_heats: 'Heats',
        help_p_heats_html: '<b>Heats</b> — for each skater on your coach list, an overview of all their heats: heat number + starting position per round (heat, quarter, semi, A-final, small final) of each distance. At the top of the skater block a status line per distance (e.g. <b>✓ Confirmed</b> or <b>✓ Conf. by org.</b>). Rounds not yet drawn show "Not drawn yet"; if the previous round is not complete, it shows "waiting for previous round". Skaters sorted by start number.',
        mock_status_bev: '✓ Confirmed',
        mock_status_bev_org: '✓ Conf. by org.',
        mock_heat_lbl: 'Heat',
        mock_startpos_lbl: 'startpos',
        mock_ronde_halve: 'SF',
        mock_ronde_ru:    'Runner-up',
        mock_wacht_loting: '🕒 Not drawn yet',
        mock_niet_geplaatst: 'Not placed',
        help_h_rondes: 'Rounds',
        help_p_rondes_html: '<b>Rounds</b> — per-round results across all DCs you follow skaters in. Shows the position achieved in each round and whether progression to the next round has occurred.',
        info_versie: 'Version',
        nieuw_jump: 'Jump to What\'s new ↓',
        nieuw_h: 'What\'s new?',
        nieuw_intro: 'Short overview of recent changes. A compact summary of what has been adjusted, aimed at returning users.',
        nieuw_v100_7_html: '<b>Rounds tab</b> — new tab with per-round results across all DCs you follow skaters in (heats, quarter, semi, A-final, small final). Shows the position achieved in each round and whether progression to the next round has occurred.',
        nieuw_v100_2_html: '<b>Quick race selection</b> in a new <b>opening window</b> with filter buttons <i>Earlier / Today / Later</i>. Appears automatically when the app opens and closes as soon as a race is selected — direct focus on the choice, then the full space for the overview.',
        nieuw_v100_4_html: '<b>Raw time</b> visible next to the net time — marked with ✋ (manual correction) or 📷 (photo-finish correction). This way, the heat tables show exactly when a correction was applied to the clock value.',
        nieuw_v100_11_html: '<b>Ranking per category</b> in the Results tab — for combined races (e.g. HJA + HSA together) a separate column per category appears next to the overall rank, so the position achieved within the own category is visible at a glance.',
        nieuw_v100_9_html: '<b>Small improvements</b> to the display on narrow screens and to navigation — including filter buttons that now fit within the opening window.',
        nieuw_v100_13_html: '<b>Filter by distance + collapse bar</b> in the program — pick a single distance (e.g. 500m) and use the segment buttons <i>Collapse / Expand / Mine</i> to close groups within that distance, open them all, or show only the races of the skaters on your list.',
        nieuw_v100_14_html: '<b>Small improvements and bug fixes</b> in the program view.',
        mock_venster_titel: 'Race & skaters',
        mock_kies_w: 'Choose your race',
        mock_kies_rijders: 'Add skaters to your coach list',
        mock_voorbeeld_w: 'Example race — 19 April 2026',
        mock_op_club:     'By club',
        mock_kies_club:   '— select club(s) —',
        mock_op_sponsor:  'By sponsor',
        mock_kies_sponsor:'— select sponsor(s) —',
        mock_op_snr:      'By start number, name or licence',
        mock_snr_lic:     'Start number, name (≥2 letters)',
        mock_btn_start:   'Add',
        mock_geselecteerd:'0 skaters selected',
        mock_btn_klaar:   'Done',
        mock_ronde_serie: 'Heat',
        mock_ronde_finale: 'Final',
        mock_col_fin:  'Fin',
        mock_col_snr:  'St#',
        mock_col_naam: 'Name',
        mock_col_tijd: 'Time',
        mock_col_rang: '#',
        mock_jouw_rijder: 'Your skater',
    },
    de: {
        // ── Document ──
        page_title: 'InlineComp – Coach',
        // ── Header / static ──
        ptr_trek: '↓ Weiter ziehen zum Aktualisieren',
        hdr_meldingen_title: 'Mitteilungen zu diesem Rennen',
        hdr_info_title: 'Über InlineComp',
        hdr_help_title: 'Wie funktioniert es?',
        hdr_titel: 'InlineComp – Coach',
        hdr_sub: 'Verfolge deine Skater: Programm, Strafen und Ergebnisse',
        pwa_installeer_titel: 'InlineComp Coach installieren',
        pwa_installeer_uitleg: 'Zum Startbildschirm hinzufügen für schnellen Zugriff',
        pwa_btn_install: 'Installieren',
        pwa_btn_sluit: 'Schließen',
        // ── Stap-labels en sub-koppen ──
        stap1_label: 'Wähle dein Rennen',
        stap2_label: 'Skater zur Coach-Liste hinzufügen',
        sub_op_club: 'Nach Verein',
        sub_op_sponsor: 'Nach Sponsor',
        sub_op_snr_naam: 'Nach Startnummer, Name oder Lizenz',
        sub_meerdere: '— mehrere gleichzeitig möglich',
        ph_snr_naam_lic: 'Startnummer, Name (≥2 Buchstaben) oder Lizenznr…',
        // ── Filters ──
        filter_eerder: 'Früher',
        filter_eerder_title: 'Frühere Rennen',
        filter_vandaag: 'Heute',
        filter_later: 'Später',
        filter_later_title: 'Kommende Rennen',
        // ── Comps select ──
        opt_kies_filter: '— Wähle oben mindestens einen Filter —',
        opt_kies_wedstrijd: '— Rennen wählen —',
        opt_binnenkort: '(demnächst)',
        opt_fout_laden: 'Laden fehlgeschlagen',
        // ── Multi-select (club + sponsor) ──
        multi_kies_wedstrijd_eerst: '— erst Rennen wählen —',
        multi_kies_club: '— Verein(e) wählen —',
        multi_kies_sponsor: '— Sponsor(en) wählen —',
        multi_geen_clubs: '— keine Vereine in diesem Rennen —',
        multi_geen_sponsors: '— keine Sponsoren in diesem Rennen —',
        multi_geen_clubs_panel: 'Keine Vereine in diesem Rennen.',
        multi_geen_sponsors_panel: 'Keine Sponsoren in diesem Rennen.',
        multi_club_gekozen_single: '{n} Verein ausgewählt — klick Hinzufügen',
        multi_club_gekozen_plural: '{n} Vereine ausgewählt — klick Hinzufügen',
        multi_sponsor_gekozen_single: '{n} Sponsor ausgewählt — klick Hinzufügen',
        multi_sponsor_gekozen_plural: '{n} Sponsoren ausgewählt — klick Hinzufügen',
        multi_geselecteerd: '{n} ausgewählt',
        multi_chip_klik_verwijder: 'Klick zum Entfernen',
        btn_alle_aan: 'Alle auswählen',
        btn_niets_aan: 'Auswahl aufheben',
        btn_klaar: 'Fertig',
        btn_toevoegen: 'Hinzufügen',
        btn_wis_alles: 'Alles löschen',
        // ── Coach-lijst ──
        coach_aantal_single: '{n} Skater ausgewählt',
        coach_aantal_plural: '{n} Skater ausgewählt',
        coach_leeg: 'Noch niemand ausgewählt — verwende die Auswahl oben.',
        // ── Setup-strip + modal ──
        setup_strip_leeg: 'Wähle dein Rennen…',
        setup_strip_edit_title: 'Rennen oder Skater ändern',
        setup_strip_rijders: 'Skater',
        setup_strip_1rijder: '1 Skater',
        setup_modal_titel: 'Rennen & Skater',
        // ── Tabs ──
        tab_programma: '📋\nProgramm',
        tab_heats: '🏃\nHeats',
        tab_sancties: '⚠️\nStrafen',
        tab_uitslagen: '📊\nErgebnisse',
        tab_rondes: '📈\nRunden',
        // ── Uitslagen-tab dropdowns ──
        uitsl_opt_kies_cat: '— Kategorie wählen —',
        uitsl_opt_kies_afstand: '— Distanz wählen —',
        uitsl_opt_kies_afstand_of_klassement: '— Distanz oder Klassement wählen —',
        uitsl_opt_geen_uitslagen: '(noch keine Ergebnisse verfügbar)',
        uitsl_klassement_opt: '🏆 Klassement',
        uitsl_opt_laden: 'Laden…',
        uitsl_opt_afstand_fallback: 'Distanz',
        // ── Rondes-tab ──
        rondes_opt_kies_cat: '— Kategorie wählen —',
        rondes_opt_kies_afstand: '— Distanz wählen —',
        rondes_kies_cat_hint: 'Erst Kategorie wählen, dann Distanz.',
        rondes_kies_afstand_hint: 'Wähle eine Distanz, um die Runden zu sehen.',
        rondes_geen_rondes: 'Noch keine Ergebnisse für diese Distanz.',
        rondes_pending: 'noch nicht vollständig',
        rondes_ronde_serie: 'Vorlauf',
        rondes_ronde_kwartfinale: 'Viertelfinale',
        rondes_ronde_halve_finale: 'Halbfinale',
        rondes_ronde_runner_up: 'Runner-up',
        rondes_ronde_finale_a: 'A-Finale',
        rondes_ronde_finale_b: 'B-Finale',
        rondes_col_pos: 'Pos',
        rondes_col_snr: 'Nr',
        rondes_col_naam: 'Name',
        rondes_col_kwal: 'Q/q',
        rondes_col_rondes: 'Rd',
        rondes_col_pkpt: 'Pkt',
        rondes_col_tijd: 'Zeit',
        rondes_col_sanctie: 'Strafe',
        rondes_col_fin: 'Fin',
        // ── Status-labels ──
        status_0: 'Nicht bestätigt',
        status_1: 'Bestätigt',
        status_2: 'Zurückgezogen',
        status_3: 'Abgem. bei Org.',
        status_4: 'Nicht angemeldet',
        status_5: 'Best. bei Org.',
        status_label: 'Status',
        // ── Sanctie-codes uitleg ──
        sanc_W1: '1. Verwarnung',
        sanc_W2: '2. Verwarnung',
        sanc_FS: 'Fehlstart',
        sanc_RR: 'Rangrückstufung',
        sanc_DQ_TF: 'Disqualifikation — technischer Fehler',
        sanc_DQ_SF: 'Disqualifikation — sportlicher Fehler',
        sanc_DQ_DF: 'Disqualifikation — disziplinarischer Fehler',
        sanc_DNS: 'Nicht gestartet',
        sanc_DNF: 'Nicht beendet',
        // ── Rondes (badges) ──
        ronde_serie: 'Serie',
        ronde_kf: 'VF',
        ronde_hf: 'HF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Hoffnungslauf',
        // ── Programma-blok labels ──
        prog_blok_pauze: 'Pause',
        prog_blok_inrijden: 'Aufwärmen',
        prog_blok_wedstrijdstart: 'Rennstart',
        prog_blok_ceremonie: 'Siegerehrung',
        prog_blok_herstart: 'Neustart',
        prog_blok_min: 'Min',
        prog_dag_alle: 'Alle',
        prog_dag: 'Tag',
        prog_afstand_alle: 'Alle',
        prog_filter_alle_dagen: 'Alle Tage',
        prog_filter_alle_afstanden: 'Alle Distanzen',
        prog_samenvat_heat_1: '1 Heat',
        prog_samenvat_heat_n: '{n} Heats',
        prog_filter_mijn: '👥 Meine Sportler',
        prog_filter_te_rijden: '⏳ Kommende',
        prog_klap_alles_uit:  'Einklappen',
        prog_klap_alles_in:   'Ausklappen',
        prog_klap_mijn:       'Meine Sportler',
        prog_klap_mijn_tooltip: 'Anzahl deiner Sportler in dieser Gruppe',
        prog_groep_status_klaar:  'Alle Rennen dieser Gruppe wurden gefahren',
        prog_groep_status_deels:  'Ergebnisverarbeitung läuft — teilweise gefahren',
        prog_groep_status_geloot: 'Auslosung für alle Rennen bekannt',
        coach_pw_titel: 'Coach-Zugang',
        coach_pw_uitleg: 'Die Coach-App ist geschützt. Frage den Wettkampf-Organisator nach dem Passwort oder schaue auf das Coach-Poster.',
        coach_pw_ok: 'OK',
        coach_pw_fout: 'Falsches Passwort',
        coach_pw_neterr: 'Keine Verbindung — bitte erneut versuchen',
        prog_combi_kop: '🔗 Kombiniertes Rennen — gemeinsam',
        prog_laden: 'Programm wird geladen…',
        prog_geen: 'Noch kein Programm bekannt.',
        prog_geen_startlijst: 'noch keine Startliste',
        prog_startlijst_nb: 'Startliste ist noch nicht verfügbar.',
        // ── Heats-tab ──
        heats_geen_rijders: 'Noch keine Skater in deiner Liste.',
        heats_geen_inschrijvingen: 'Skater hat keine Anmeldungen in diesem Rennen.',
        heats_cat_fallback: '(Kategorie)',
        heats_afstand_fallback: '(Distanz)',
        heats_niet_geloot_geen_heats: '⏳ Auslosung noch nicht erfolgt — keine Heats verfügbar',
        heats_geen_programma: '⏳ Noch kein Programm für diese Kategorie',
        heats_vorige_niet_compleet: '⏳ Vorherige Runde noch nicht abgeschlossen',
        heats_niet_geplaatst: 'nicht platziert',
        heats_niet_geloot: '⏳ Auslosung noch nicht erfolgt',
        heats_heat: 'Heat',
        heats_startpos: 'Startposition {pos}',
        // ── Sancties-tab ──
        sanc_geen_rijders: 'Noch keine Skater in deiner Liste.',
        sanc_geen: 'Keine Strafen.',
        // ── Uitslagen ──
        uit_leeg: 'Für dieses Rennen wurden noch keine Ergebnisse bestätigt.',
        uit_geen_uitslagen: 'Keine Ergebnisse verfügbar.',
        uit_geen_klassement: 'Kein Klassement verfügbar.',
        uit_laden: 'Laden…',
        uit_fout: 'Fehler: {msg}',
        // ── Tabel-headers ──
        col_pos: '#',
        col_snr: 'Nr',
        col_naam: 'Name',
        col_rnd: 'Rd',
        col_pnt: 'Pkt',
        col_tijd: 'Zeit',
        heat_bruto_gemeten: 'gemessen',
        col_fin: 'Fin',
        col_rang: '#',
        col_tot: 'Ges',
        // ── Bevestig-modal ──
        bev_titel: 'Bestätigen',
        bev_ok: 'OK',
        bev_annuleer: 'Abbrechen',
        // Rijder verwijderen
        bev_verwijder_titel: 'Skater entfernen?',
        bev_verwijder_tekst: 'Möchtest du <b>{naam}</b> ({snr}) aus deiner Coach-Liste entfernen?',
        bev_verwijder_ok: 'Ja, entfernen',
        bev_verwijder_snr_fallback: 'Startnr {snr}',
        // Wis alles
        bev_wis_titel: 'Coach-Liste löschen?',
        bev_wis_tekst_single: 'Du bist dabei, <b>alle {n} Skater</b> aus deiner Coach-Liste zu entfernen.<br><br>Dies kann nicht rückgängig gemacht werden.',
        bev_wis_tekst_plural: 'Du bist dabei, <b>alle {n} Skater</b> aus deiner Coach-Liste zu entfernen.<br><br>Dies kann nicht rückgängig gemacht werden.',
        bev_wis_ok: 'Ja, alles löschen',
        // ── Naam-zoek modal ──
        nz_matches_voor: '{n} Treffer für {label}',
        nz_sluit: 'Schließen',
        nz_al_in_lijst: 'bereits in Liste',
        nz_vink_aan: 'Wähle aus, wen du hinzufügen möchtest',
        nz_toevoegen: 'Hinzufügen',
        // ── Toevoeg-flow feedback ──
        fb_kies_iets: 'Wähle einen Verein, Sponsor, oder gib eine Startnummer / Name / Lizenz ein.',
        fb_server_druk: 'Server vorübergehend ausgelastet — bitte in 5 Sekunden erneut versuchen',
        fb_server_fout: 'Serverfehler ({status})',
        fb_netwerk_fout: 'Netzwerkfehler beim Laden der Skater',
        fb_rijders_van_single: '{aantal} Skater von {stukken}',
        fb_rijders_van_geen: '{stukken}: keine neuen Skater',
        fb_clubs_single: '{n} Verein',
        fb_clubs_plural: '{n} Vereine',
        fb_sponsors_single: '{n} Sponsor',
        fb_sponsors_plural: '{n} Sponsoren',
        fb_toegevoegd: 'Hinzugefügt: {lijst}',
        fb_label_snr: 'Startnummer {term}',
        fb_label_licentie: 'Lizenz {term}',
        fb_label_naam: 'Name „{term}"',
        fb_lookup_mislukt: '{label}: Suche fehlgeschlagen ({msg})',
        fb_label_error: '{label}: {error}',
        fb_label_niet_gevonden: '{label} nicht in diesem Rennen gefunden',
        fb_stond_al_in_lijst: '{naam}: bereits in Liste',
        fb_naam_snr: '{naam} (Nr {snr})',
        // ── Connection banner ──
        conn_geen_internet: '📡 Kein Internet — wird aktualisiert sobald wieder online',
        conn_server_down: '⚠ Server nicht erreichbar — neuer Versuch…',
        conn_laatste_update: 'letzte Aktualisierung {tijd}',
        // ── PTR ──
        ptr_laat_los: '↑ Loslassen zum Aktualisieren',
        ptr_vernieuwen: '⟳ Aktualisieren…',
        ptr_bijgewerkt: '✓ Aktualisiert',
        ptr_fout: '⚠ Aktualisierungsfehler',
        ptr_wachten: '⏳ Bitte warten ({s}s)',
        // ── Auto-refresh ──
        auto_refresh_title: 'Zeitpunkt der letzten automatischen Aktualisierung',
        // ── Mededelingen ──
        meld_kop: '📢 Mitteilungen',
        meld_tot: ' bis ',
        meld_begrepen: '✓ Verstanden',
        // ── Info modal ──
        info_titel: 'Über InlineComp Coach',
        info_h_wat: 'Was ist das?',
        info_p_wat1_html: 'Die <b>Coach-Ansicht</b> ist ein Dashboard für Trainer: du erstellst eine persönliche Liste von Skatern pro Rennen und siehst auf einen Blick deren Programm, Status, Strafen und Ergebnisse.',
        info_p_wat2: 'Du kannst ein ganzes Vereinsteam auf einmal hinzufügen, Skater nach Sponsor auswählen oder einzelne Startnummern hinzufügen. Deine Liste wird lokal auf deinem Telefon gespeichert (pro Rennen) — ein Refresh oder Rückkehr zur Seite behält sie bei.',
        info_h_login: 'Zugang mit Passwort',
        info_p_login_html: 'Coach ist mit einem gemeinsamen Passwort geschützt. Betreust du <b>mehr als 3 Läufer</b>? Frag den Wettkampforganisator nach dem Passwort. Für 1-3 Läufer reicht meist die <a href="../public/">Public-App</a>. Wettkampfdaten sind öffentlich (KNSB-Feed); deine persönliche Läuferliste bleibt lokal auf deinem Telefon.',
        info_h_tip: 'Tipp: zum Startbildschirm hinzufügen',
        info_p_tip: 'Auf deinem Telefon: öffne diese Seite in Safari/Chrome → Menü → „Zum Startbildschirm". Sie öffnet sich dann wie eine App und du hast sie direkt an der Strecke griffbereit.',
        info_h_dev: 'In Entwicklung',
        info_p_dev: 'Diese Coach-Ansicht wird aktiv weiterentwickelt. Feedback ist sehr willkommen!',
        info_h_contact_html: 'Kontakt &amp; Feedback',
        info_p_contact: 'Frage, Vorschlag oder einen Fehler entdeckt? Lass es uns wissen:',
        info_h_stats: 'Anonyme Besuchsstatistiken',
        info_p_stats_html: 'Wir zählen anonym Besucherzahlen, aktive Sessions und Peak-Nutzer — nur um zu sehen, wie viel die App genutzt wird und um das Hosting stabil zu halten. <b>Keine IP-Adressen oder persönliche Daten</b> werden gespeichert und <b>keine Drittanbieter</b> sind beteiligt.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'Wie funktioniert die Coach-Ansicht?',
        help_h_start: 'Erste Schritte',
        help_stap1_html: 'Wähle dein <b>Rennen</b>.',
        help_stap2_html: 'Wähle deine <b>Skater</b> per Verein, Sponsor oder Startnummer und klicke auf <b>Hinzufügen</b>.',
        help_stap3_html: 'Klicke auf <b>Fertig</b>.',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'Nach dem Suchen siehst du <b>5 Tabs</b>:',
        help_h_prog: 'Programm',
        help_p_prog_html: 'Zeigt alle Rennen der Veranstaltung. Rennen mit mindestens einem deiner Skater sind <b>gelb markiert</b> mit einem Streifen ihrer Startnummern rechts. Tippe auf ein Rennen für die vollständige Startliste — deine Skater sind dort wieder gelb markiert. Oben kannst du nach Distanz filtern; mit der Leiste darunter klappst du Gruppen innerhalb dieser Distanz ein oder aus.',
        help_h_sanc: 'Strafen',
        help_p_sanc1: 'Für jeden Skater in deiner Liste eine Karte mit:',
        help_p_sanc_lijst_html: '<li><b>Status-Badge</b> (Bestätigt / Nicht angemeldet / Zurückgezogen / …)</li><li>Alle <b>Strafen</b> registriert in Heats (W1, W2, FS, DQ-SF, DNF, …)</li>',
        help_p_sanc2_html: 'Achte auf <b>🚨 Nicht angemeldet</b> — dann muss der Skater schnell selbst zum Juryschalter (nicht du als Coach, nicht die Eltern, nur der Skater).',
        help_h_uitsl: 'Ergebnisse',
        help_p_uitsl: 'Wähle eine Kategorie + Distanz für das vollständige Ergebnis, oder schau dir das Klassement an. Auch hier sind deine eigenen Skater gelb markiert.',
        help_h_auto: 'Automatisch aktualisiert',
        help_p_auto_html: 'Die Seite aktualisiert sich jede Minute, solange der Tab sichtbar ist. Die Zeit der letzten Aktualisierung wird oben rechts angezeigt (<b>🔄 HH:MM</b>). Sofort aktualisieren funktioniert auch: ziehe die Seite <b>nach unten</b> (pull-to-refresh) oder doppelklicke auf den blauen Header.',
        help_h_meld: 'Mitteilungen',
        help_p_meld_html: 'Oben erscheint ein <b>📢-Button</b> sobald eine aktive Mitteilung der Organisation vorliegt. Wichtige Mitteilungen erscheinen automatisch und bleiben danach über diesen Button zugänglich.',
        help_h_priv: 'Datenschutz',
        help_p_priv: 'Deine Coach-Liste wird nur lokal auf deinem Telefon gespeichert (localStorage). Niemand sonst sieht, wer auf deiner Liste steht.',
        help_h_heats: 'Heats',
        help_p_heats_html: '<b>Heats</b> — für jeden Läufer auf deiner Coach-Liste eine Übersicht aller Heats: Heatnummer + Startposition pro Runde (Vorlauf, Viertel, Halbfinale, A-Finale, kleines Finale) jeder Distanz. Oben im Läufer-Block eine Statuszeile pro Distanz (z.B. <b>✓ Bestätigt</b> oder <b>✓ Best. b. Org.</b>). Bei noch nicht gelosten Runden erscheint "Noch nicht gelost"; ist die vorherige Runde nicht vollständig, dann "wartet auf vorherige Runde". Läufer nach Startnummer sortiert.',
        mock_status_bev: '✓ Bestätigt',
        mock_status_bev_org: '✓ Best. b. Org.',
        mock_heat_lbl: 'Heat',
        mock_startpos_lbl: 'Startpos',
        mock_ronde_halve: 'HF',
        mock_ronde_ru:    'Runner-up',
        mock_wacht_loting: '🕒 Noch nicht gelost',
        mock_niet_geplaatst: 'Nicht platziert',
        help_h_rondes: 'Runden',
        help_p_rondes_html: '<b>Runden</b> — Ergebnisse pro Runde für alle DCs, in denen du Läufer verfolgst. Zeigt die in jeder Runde erreichte Platzierung und ob ein Weiterkommen in die nächste Runde erfolgt ist.',
        info_versie: 'Version',
        nieuw_jump: 'Direkt zu Was ist neu ↓',
        nieuw_h: 'Was ist neu?',
        nieuw_intro: 'Kurze Übersicht der jüngsten Änderungen. Für wiederkehrende Nutzer eine kompakte Zusammenfassung der Anpassungen.',
        nieuw_v100_7_html: '<b>Runden-Tab</b> — neuer Tab mit Ergebnissen pro Runde für alle DCs, in denen du Läufer verfolgst (Vorläufe, Viertel, Halbfinale, A-Finale, kleines Finale). Zeigt die in jeder Runde erreichte Platzierung und ob ein Weiterkommen in die nächste Runde erfolgt ist.',
        nieuw_v100_2_html: '<b>Schnelle Rennauswahl</b> in einem neuen <b>Startfenster</b> mit Filter-Buttons <i>Früher / Heute / Später</i>. Erscheint automatisch beim Öffnen der App und schließt, sobald ein Rennen ausgewählt wurde — direkter Fokus auf die Auswahl, danach der volle Platz für die Übersicht.',
        nieuw_v100_4_html: '<b>Bruttozeit</b> sichtbar neben der Nettozeit — kenntlich an ✋ (Handkorrektur) oder 📷 (Fotofinish-Korrektur). So ist in den Heat-Tabellen sichtbar, wann eine Korrektur der Uhrzeit erfolgt ist.',
        nieuw_v100_11_html: '<b>Platzierung pro Kategorie</b> im Ergebnisse-Tab — bei kombinierten Rennen (z.B. HJA + HSA zusammen) erscheint neben dem Gesamtrang eine separate Spalte pro Kategorie, sodass die innerhalb der eigenen Kategorie erreichte Platzierung auf einen Blick sichtbar ist.',
        nieuw_v100_9_html: '<b>Kleine Verbesserungen</b> an der Darstellung auf schmalen Bildschirmen und der Navigation — u.a. Filter-Buttons, die wieder in das Startfenster passen.',
        nieuw_v100_13_html: '<b>Distanz-Filter + Ein-/Ausklapp-Leiste</b> im Programm — wähle eine Distanz (z.B. 500m) und benutze die Segment-Buttons <i>Einklappen / Ausklappen / Meine</i>, um Gruppen innerhalb dieser Distanz zu schließen, alle zu öffnen oder nur die Rennen der Skater deiner Liste zu zeigen.',
        nieuw_v100_14_html: '<b>Kleine Verbesserungen und Fehlerbehebungen</b> in der Programm-Ansicht.',
        mock_venster_titel: 'Rennen & Läufer',
        mock_kies_w: 'Wähle dein Rennen',
        mock_kies_rijders: 'Läufer zur Coach-Liste hinzufügen',
        mock_voorbeeld_w: 'Beispielrennen — 19. April 2026',
        mock_op_club:     'Nach Verein',
        mock_kies_club:   '— Verein(e) wählen —',
        mock_op_sponsor:  'Nach Sponsor',
        mock_kies_sponsor:'— Sponsor(en) wählen —',
        mock_op_snr:      'Nach Startnummer, Name oder Lizenz',
        mock_snr_lic:     'Startnummer, Name (≥2 Buchstaben)',
        mock_btn_start:   'Hinzufügen',
        mock_geselecteerd:'0 Läufer ausgewählt',
        mock_btn_klaar:   'Fertig',
        mock_ronde_serie: 'Vorlauf',
        mock_ronde_finale: 'Finale',
        mock_col_fin:  'Fin',
        mock_col_snr:  'St#',
        mock_col_naam: 'Name',
        mock_col_tijd: 'Zeit',
        mock_col_rang: '#',
        mock_jouw_rijder: 'Dein Läufer',
    },
    fr: {
        // ── Document ──
        page_title: 'InlineComp – Coach',
        // ── Header / static ──
        ptr_trek: '↓ Tirer plus pour actualiser',
        hdr_meldingen_title: 'Annonces pour cette course',
        hdr_info_title: 'À propos d\'InlineComp',
        hdr_help_title: 'Comment ça marche?',
        hdr_titel: 'InlineComp – Coach',
        hdr_sub: 'Suivez vos skateurs: programme, sanctions et résultats',
        pwa_installeer_titel: 'Installer InlineComp Coach',
        pwa_installeer_uitleg: 'Ajouter à l\'écran d\'accueil pour un accès rapide',
        pwa_btn_install: 'Installer',
        pwa_btn_sluit: 'Fermer',
        // ── Stap-labels en sub-koppen ──
        stap1_label: 'Choisissez votre course',
        stap2_label: 'Ajoutez des skateurs à votre liste de coach',
        sub_op_club: 'Par club',
        sub_op_sponsor: 'Par sponsor',
        sub_op_snr_naam: 'Par numéro de départ, nom ou licence',
        sub_meerdere: '— plusieurs à la fois possible',
        ph_snr_naam_lic: 'Numéro de départ, nom (≥2 lettres) ou n° licence…',
        // ── Filters ──
        filter_eerder: 'Avant',
        filter_eerder_title: 'Courses précédentes',
        filter_vandaag: 'Aujourd\'hui',
        filter_later: 'Plus tard',
        filter_later_title: 'Courses à venir',
        // ── Comps select ──
        opt_kies_filter: '— Sélectionnez au moins un filtre ci-dessus —',
        opt_kies_wedstrijd: '— choisir une course —',
        opt_binnenkort: '(bientôt)',
        opt_fout_laden: 'Échec du chargement',
        // ── Multi-select (club + sponsor) ──
        multi_kies_wedstrijd_eerst: '— choisir d\'abord une course —',
        multi_kies_club: '— choisir club(s) —',
        multi_kies_sponsor: '— choisir sponsor(s) —',
        multi_geen_clubs: '— pas de clubs dans cette course —',
        multi_geen_sponsors: '— pas de sponsors dans cette course —',
        multi_geen_clubs_panel: 'Pas de clubs dans cette course.',
        multi_geen_sponsors_panel: 'Pas de sponsors dans cette course.',
        multi_club_gekozen_single: '{n} club sélectionné — cliquez Ajouter',
        multi_club_gekozen_plural: '{n} clubs sélectionnés — cliquez Ajouter',
        multi_sponsor_gekozen_single: '{n} sponsor sélectionné — cliquez Ajouter',
        multi_sponsor_gekozen_plural: '{n} sponsors sélectionnés — cliquez Ajouter',
        multi_geselecteerd: '{n} sélectionné(s)',
        multi_chip_klik_verwijder: 'Cliquer pour supprimer',
        btn_alle_aan: 'Tout cocher',
        btn_niets_aan: 'Tout décocher',
        btn_klaar: 'Terminé',
        btn_toevoegen: 'Ajouter',
        btn_wis_alles: 'Tout effacer',
        // ── Coach-lijst ──
        coach_aantal_single: '{n} skateur sélectionné',
        coach_aantal_plural: '{n} skateurs sélectionnés',
        coach_leeg: 'Personne sélectionné pour l\'instant — utilisez les sélecteurs ci-dessus.',
        // ── Setup-strip + modal ──
        setup_strip_leeg: 'Choisissez votre course…',
        setup_strip_edit_title: 'Modifier la course ou les skateurs',
        setup_strip_rijders: 'skateurs',
        setup_strip_1rijder: '1 skateur',
        setup_modal_titel: 'Course & skateurs',
        // ── Tabs ──
        tab_programma: '📋\nProgramme',
        tab_heats: '🏃\nHeats',
        tab_sancties: '⚠️\nSanctions',
        tab_uitslagen: '📊\nRésultats',
        tab_rondes: '📈\nRondes',
        // ── Uitslagen-tab dropdowns ──
        uitsl_opt_kies_cat: '— choisir catégorie —',
        uitsl_opt_kies_afstand: '— choisir distance —',
        uitsl_opt_kies_afstand_of_klassement: '— choisir distance ou classement —',
        uitsl_opt_geen_uitslagen: '(pas encore de résultats)',
        uitsl_klassement_opt: '🏆 Classement',
        uitsl_opt_laden: 'Chargement…',
        uitsl_opt_afstand_fallback: 'Distance',
        // ── Rondes-tab ──
        rondes_opt_kies_cat: '— choisir catégorie —',
        rondes_opt_kies_afstand: '— choisir distance —',
        rondes_kies_cat_hint: 'Choisissez d\'abord une catégorie, puis une distance.',
        rondes_kies_afstand_hint: 'Choisissez une distance pour voir les manches.',
        rondes_geen_rondes: 'Aucun résultat pour cette distance.',
        rondes_pending: 'pas encore complet',
        rondes_ronde_serie: 'Série',
        rondes_ronde_kwartfinale: 'Quart de finale',
        rondes_ronde_halve_finale: 'Demi-finale',
        rondes_ronde_runner_up: 'Runner-up',
        rondes_ronde_finale_a: 'Finale A',
        rondes_ronde_finale_b: 'Finale B',
        rondes_col_pos: 'Pos',
        rondes_col_snr: 'Dos',
        rondes_col_naam: 'Nom',
        rondes_col_kwal: 'Q/q',
        rondes_col_rondes: 'Tr',
        rondes_col_pkpt: 'Pts',
        rondes_col_tijd: 'Temps',
        rondes_col_sanctie: 'Sanction',
        rondes_col_fin: 'Fin',
        // ── Status-labels ──
        status_0: 'Non confirmé',
        status_1: 'Confirmé',
        status_2: 'Retiré',
        status_3: 'Désinsc. à l\'org.',
        status_4: 'Non enregistré',
        status_5: 'Conf. à l\'org.',
        status_label: 'Statut',
        // ── Sanctie-codes uitleg ──
        sanc_W1: '1er avertissement',
        sanc_W2: '2e avertissement',
        sanc_FS: 'Faux départ',
        sanc_RR: 'Rétrogradation',
        sanc_DQ_TF: 'Disqualification — faute technique',
        sanc_DQ_SF: 'Disqualification — faute sportive',
        sanc_DQ_DF: 'Disqualification — faute disciplinaire',
        sanc_DNS: 'N\'a pas pris le départ',
        sanc_DNF: 'N\'a pas terminé',
        // ── Rondes (badges) ──
        ronde_serie: 'Série',
        ronde_kf: 'QF',
        ronde_hf: 'DF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'Finale B',
        ronde_runner_up: 'Repêchage',
        // ── Programma-blok labels ──
        prog_blok_pauze: 'Pause',
        prog_blok_inrijden: 'Échauffement',
        prog_blok_wedstrijdstart: 'Début de course',
        prog_blok_ceremonie: 'Cérémonie',
        prog_blok_herstart: 'Redémarrage',
        prog_blok_min: 'min',
        prog_dag_alle: 'Tous',
        prog_dag: 'Jour',
        prog_afstand_alle: 'Toutes',
        prog_filter_alle_dagen: 'Tous les jours',
        prog_filter_alle_afstanden: 'Toutes les distances',
        prog_samenvat_heat_1: '1 série',
        prog_samenvat_heat_n: '{n} séries',
        prog_filter_mijn: '👥 Mes coureurs',
        prog_klap_alles_uit:  'Réduire',
        prog_klap_alles_in:   'Développer',
        prog_klap_mijn:       'Mes coureurs',
        prog_klap_mijn_tooltip: 'Nombre de tes coureurs dans ce groupe',
        prog_groep_status_klaar:  'Toutes les courses de ce groupe sont terminées',
        prog_groep_status_deels:  'Traitement des résultats en cours — partiel',
        prog_groep_status_geloot: 'Tirage effectué pour toutes les courses',
        prog_filter_te_rijden: '⏳ À venir',
        coach_pw_titel: 'Accès Coach',
        coach_pw_uitleg: 'L\'application Coach est protégée. Demandez le mot de passe à l\'organisateur de la course ou consultez l\'affiche Coach.',
        coach_pw_ok: 'OK',
        coach_pw_fout: 'Mot de passe incorrect',
        coach_pw_neterr: 'Pas de connexion — réessayez',
        prog_combi_kop: '🔗 Course combinée — ensemble',
        prog_laden: 'Chargement du programme…',
        prog_geen: 'Pas encore de programme connu.',
        prog_geen_startlijst: 'pas encore de liste de départ',
        prog_startlijst_nb: 'La liste de départ n\'est pas encore disponible.',
        // ── Heats-tab ──
        heats_geen_rijders: 'Pas encore de skateurs dans votre liste.',
        heats_geen_inschrijvingen: 'Le skateur n\'a pas d\'inscriptions à cette course.',
        heats_cat_fallback: '(catégorie)',
        heats_afstand_fallback: '(distance)',
        heats_niet_geloot_geen_heats: '⏳ Tirage pas encore effectué — pas de heats disponibles',
        heats_geen_programma: '⏳ Pas encore de programme pour cette catégorie',
        heats_vorige_niet_compleet: '⏳ Tour précédent pas encore complet',
        heats_niet_geplaatst: 'non classé',
        heats_niet_geloot: '⏳ Tirage pas encore effectué',
        heats_heat: 'Heat',
        heats_startpos: 'pos départ {pos}',
        // ── Sancties-tab ──
        sanc_geen_rijders: 'Pas encore de skateurs dans votre liste.',
        sanc_geen: 'Aucune sanction.',
        // ── Uitslagen ──
        uit_leeg: 'Aucun résultat confirmé pour cette course.',
        uit_geen_uitslagen: 'Aucun résultat disponible.',
        uit_geen_klassement: 'Aucun classement disponible.',
        uit_laden: 'Chargement…',
        uit_fout: 'Erreur: {msg}',
        // ── Tabel-headers ──
        col_pos: '#',
        col_snr: 'N°',
        col_naam: 'Nom',
        col_rnd: 'T',
        col_pnt: 'Pts',
        col_tijd: 'Temps',
        heat_bruto_gemeten: 'mesuré',
        col_fin: 'Fin',
        col_rang: '#',
        col_tot: 'Tot',
        // ── Bevestig-modal ──
        bev_titel: 'Confirmer',
        bev_ok: 'OK',
        bev_annuleer: 'Annuler',
        // Rijder verwijderen
        bev_verwijder_titel: 'Supprimer le skateur?',
        bev_verwijder_tekst: 'Voulez-vous supprimer <b>{naam}</b> ({snr}) de votre liste de coach?',
        bev_verwijder_ok: 'Oui, supprimer',
        bev_verwijder_snr_fallback: 'N° départ {snr}',
        // Wis alles
        bev_wis_titel: 'Effacer la liste de coach?',
        bev_wis_tekst_single: 'Vous allez supprimer <b>tous les {n} skateurs</b> de votre liste de coach.<br><br>Ceci ne peut pas être annulé.',
        bev_wis_tekst_plural: 'Vous allez supprimer <b>tous les {n} skateurs</b> de votre liste de coach.<br><br>Ceci ne peut pas être annulé.',
        bev_wis_ok: 'Oui, tout effacer',
        // ── Naam-zoek modal ──
        nz_matches_voor: '{n} résultats pour {label}',
        nz_sluit: 'Fermer',
        nz_al_in_lijst: 'déjà dans la liste',
        nz_vink_aan: 'Cochez qui vous voulez ajouter',
        nz_toevoegen: 'Ajouter',
        // ── Toevoeg-flow feedback ──
        fb_kies_iets: 'Choisissez un club, sponsor, ou entrez un numéro de départ / nom / licence.',
        fb_server_druk: 'Serveur temporairement occupé — réessayez dans 5 secondes',
        fb_server_fout: 'Erreur serveur ({status})',
        fb_netwerk_fout: 'Erreur réseau lors du chargement des skateurs',
        fb_rijders_van_single: '{aantal} skateur(s) de {stukken}',
        fb_rijders_van_geen: '{stukken}: pas de nouveaux skateurs',
        fb_clubs_single: '{n} club',
        fb_clubs_plural: '{n} clubs',
        fb_sponsors_single: '{n} sponsor',
        fb_sponsors_plural: '{n} sponsors',
        fb_toegevoegd: 'Ajouté: {lijst}',
        fb_label_snr: 'Numéro de départ {term}',
        fb_label_licentie: 'Licence {term}',
        fb_label_naam: 'Nom « {term} »',
        fb_lookup_mislukt: '{label}: recherche échouée ({msg})',
        fb_label_error: '{label}: {error}',
        fb_label_niet_gevonden: '{label} non trouvé dans cette course',
        fb_stond_al_in_lijst: '{naam}: déjà dans la liste',
        fb_naam_snr: '{naam} (n° {snr})',
        // ── Connection banner ──
        conn_geen_internet: '📡 Pas d\'internet — rafraîchira au retour de la connexion',
        conn_server_down: '⚠ Serveur injoignable — nouvelle tentative…',
        conn_laatste_update: 'dernière mise à jour {tijd}',
        // ── PTR ──
        ptr_laat_los: '↑ Relâcher pour actualiser',
        ptr_vernieuwen: '⟳ Actualisation…',
        ptr_bijgewerkt: '✓ Actualisé',
        ptr_fout: '⚠ Erreur d\'actualisation',
        ptr_wachten: '⏳ Veuillez patienter ({s}s)',
        // ── Auto-refresh ──
        auto_refresh_title: 'Heure de la dernière actualisation automatique',
        // ── Mededelingen ──
        meld_kop: '📢 Annonces',
        meld_tot: ' jusqu\'à ',
        meld_begrepen: '✓ Compris',
        // ── Info modal ──
        info_titel: 'À propos d\'InlineComp Coach',
        info_h_wat: 'Qu\'est-ce que c\'est?',
        info_p_wat1_html: 'La <b>vue Coach</b> est un tableau de bord pour entraîneurs: vous créez une liste personnelle de skateurs par course et voyez d\'un coup d\'œil leur programme, statut, sanctions et résultats.',
        info_p_wat2: 'Vous pouvez ajouter toute une équipe de club à la fois, sélectionner des skateurs par sponsor, ou ajouter des numéros de départ individuels. Votre liste est stockée localement sur votre téléphone (par course) — un rafraîchissement ou retour à la page la conserve.',
        info_h_login: 'Accès avec mot de passe',
        info_p_login_html: 'Coach est protégé par un mot de passe partagé. Tu suis <b>plus de 3 skateurs</b> ? Demande le mot de passe à l\'organisateur de la course. Pour 1-3 skateurs, l\'<a href="../public/">app public</a> suffit souvent. Les données de course sont publiques (flux KNSB) ; ta liste de skateurs personnelle reste locale sur ton téléphone.',
        info_h_tip: 'Astuce: ajouter à l\'écran d\'accueil',
        info_p_tip: 'Sur votre téléphone: ouvrez cette page dans Safari/Chrome → menu → « Ajouter à l\'écran d\'accueil ». Elle s\'ouvre alors comme une app et vous l\'avez à portée de main au bord de la piste.',
        info_h_dev: 'En développement',
        info_p_dev: 'Cette vue Coach est en développement actif. Vos retours sont les bienvenus!',
        info_h_contact_html: 'Contact &amp; retour',
        info_p_contact: 'Une question, suggestion ou bug trouvé? Faites-le nous savoir:',
        info_h_stats: 'Statistiques de visite anonymes',
        info_p_stats_html: 'Nous comptons anonymement les visiteurs, sessions actives et pics d\'utilisateurs simultanés — uniquement pour voir combien l\'app est utilisée et pour maintenir l\'hébergement stable. <b>Aucune adresse IP ni donnée personnelle</b> n\'est stockée et <b>aucun tiers</b> n\'est impliqué.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'Comment fonctionne la vue Coach?',
        help_h_start: 'Pour commencer',
        help_stap1_html: 'Choisis ta <b>course</b>.',
        help_stap2_html: 'Choisis tes <b>skateurs</b> par club, sponsor ou numéro de dossard, et clique sur <b>Ajouter</b>.',
        help_stap3_html: 'Clique sur <b>Terminer</b>.',
        help_h_tabs: 'Onglets',
        help_p_tabs_html: 'Après la recherche tu vois <b>5 onglets</b> :',
        help_h_prog: 'Programme',
        help_p_prog_html: 'Affiche toutes les courses de l\'événement. Les courses contenant au moins un de vos skateurs sont <b>surlignées en jaune</b> avec une bande de leurs numéros de départ à droite. Appuyez sur une course pour voir la liste de départ complète — vos skateurs y sont à nouveau surlignés en jaune. Filtre par distance en haut; utilise la barre en dessous pour replier ou déplier les groupes dans cette distance.',
        help_h_sanc: 'Sanctions',
        help_p_sanc1: 'Pour chaque skateur de votre liste une carte avec:',
        help_p_sanc_lijst_html: '<li><b>Badge de statut</b> (Confirmé / Non enregistré / Retiré / …)</li><li>Toutes les <b>sanctions</b> enregistrées en heats (W1, W2, FS, DQ-SF, DNF, …)</li>',
        help_p_sanc2_html: 'Attention à <b>🚨 Non enregistré</b> — alors le skateur doit vite aller lui-même au bureau du jury (pas vous comme coach, pas les parents, seulement le skateur).',
        help_h_uitsl: 'Résultats',
        help_p_uitsl: 'Choisissez une catégorie + distance pour voir le résultat complet, ou consultez le classement. Là aussi vos propres skateurs sont surlignés en jaune.',
        help_h_auto: 'Mis à jour automatiquement',
        help_p_auto_html: 'La page se rafraîchit chaque minute tant que l\'onglet est visible. L\'heure de la dernière actualisation est affichée en haut à droite (<b>🔄 HH:MM</b>). Actualiser immédiatement fonctionne aussi: tirez la page <b>vers le bas</b> (pull-to-refresh) ou double-cliquez sur l\'en-tête bleu.',
        help_h_meld: 'Annonces',
        help_p_meld_html: 'En haut un <b>bouton 📢</b> apparaît dès qu\'il y a une annonce active de l\'organisation. Les annonces importantes apparaissent automatiquement et restent ensuite accessibles via ce bouton.',
        help_h_priv: 'Confidentialité',
        help_p_priv: 'Votre liste de coach est uniquement stockée localement sur votre téléphone (localStorage). Personne d\'autre ne voit qui est sur votre liste.',
        help_h_heats: 'Séries',
        help_p_heats_html: '<b>Séries</b> — pour chaque skateur de ta liste de coach, un aperçu de toutes ses séries : numéro de série + position de départ par tour (série, quart, demi, finale A, petite finale) de chaque distance. En haut du bloc skateur, une ligne de statut par distance (par ex. <b>✓ Confirmé</b> ou <b>✓ Conf. par org.</b>). Pour les tours non encore tirés au sort, "Pas encore tiré" apparaît ; si le tour précédent n\'est pas complet, "en attente du tour précédent". Skateurs triés par numéro de dossard.',
        mock_status_bev: '✓ Confirmé',
        mock_status_bev_org: '✓ Conf. par org.',
        mock_heat_lbl: 'Série',
        mock_startpos_lbl: 'pos. départ',
        mock_ronde_halve: 'Demi',
        mock_ronde_ru:    'Runner-up',
        mock_wacht_loting: '🕒 Pas encore tiré',
        mock_niet_geplaatst: 'Non placé',
        help_h_rondes: 'Rondes',
        help_p_rondes_html: '<b>Rondes</b> — résultats par tour pour toutes les DCs dont tu suis des skateurs. Montre la place obtenue à chaque tour et si un passage au tour suivant a eu lieu.',
        info_versie: 'Version',
        nieuw_jump: 'Aller à Quoi de neuf ↓',
        nieuw_h: 'Quoi de neuf ?',
        nieuw_intro: 'Bref aperçu des changements récents. Un résumé compact des ajustements, destiné aux utilisateurs habitués.',
        nieuw_v100_7_html: '<b>Onglet Rondes</b> — nouvel onglet avec les résultats par tour pour toutes les DCs dont tu suis des skateurs (séries, quart, demi, finale A, petite finale). Montre la place obtenue à chaque tour et si un passage au tour suivant a eu lieu.',
        nieuw_v100_4_html: '<b>Temps brut</b> visible à côté du temps net — marqué ✋ (correction manuelle) ou 📷 (correction photo-finish). Ainsi, les tableaux de séries montrent exactement quand une correction a été appliquée au temps de l\'horloge.',
        nieuw_v100_11_html: '<b>Classement par catégorie</b> dans l\'onglet Résultats — pour les courses combinées (par ex. HJA + HSA ensemble) une colonne distincte par catégorie apparaît à côté du rang général, ce qui rend la place obtenue dans la propre catégorie visible d\'un coup d\'œil.',
        nieuw_v100_9_html: '<b>Petites améliorations</b> pour l\'affichage sur écrans étroits et pour la navigation — dont des boutons de filtre qui tiennent à nouveau dans la fenêtre d\'ouverture.',
        nieuw_v100_13_html: '<b>Filtre par distance + barre pliage</b> dans le programme — choisis une distance (par ex. 500m) et utilise les boutons de segment <i>Réduire / Développer / Les miens</i> pour fermer les groupes dans cette distance, tous les ouvrir, ou n\'afficher que les courses des skateurs de ta liste.',
        nieuw_v100_14_html: '<b>Petites améliorations et corrections</b> dans l\'affichage du programme.',
        mock_venster_titel: 'Course & skateurs',
        mock_kies_w: 'Choisis ta course',
        mock_kies_rijders: 'Ajoute des skateurs à ta liste de coach',
        mock_voorbeeld_w: 'Course exemple — 19 avril 2026',
        mock_op_club:     'Par club',
        mock_kies_club:   '— choisir club(s) —',
        mock_op_sponsor:  'Par sponsor',
        mock_kies_sponsor:'— choisir sponsor(s) —',
        mock_op_snr:      'Par dossard, nom ou licence',
        mock_snr_lic:     'Numéro, nom (≥2 lettres)',
        mock_btn_start:   'Ajouter',
        mock_geselecteerd:'0 skateur sélectionné',
        mock_btn_klaar:   'Terminer',
        mock_ronde_serie: 'Série',
        mock_ronde_finale: 'Finale',
        mock_col_fin:  'Fin',
        mock_col_snr:  'St#',
        mock_col_naam: 'Nom',
        mock_col_tijd: 'Temps',
        mock_col_rang: '#',
        mock_jouw_rijder: 'Ton skateur',
    }
};

// Shared i18n-helpers (t, applyI18n, toggleLang, getCurLang, getLocale)
// zijn hierboven al ingeladen via readfile(js/i18n.js). Hier alleen
// app-specifieke init + rerender-hook.
function _rerenderCoach() {
    // Comp-dropdown opnieuw vullen (textContent met "(binnenkort)" suffix etc.)
    if (typeof filterComps === 'function' && alleComps?.length) filterComps();
    // Connection banner updaten met vertaalde tekst
    if (typeof _connUpdateBanner === 'function') _connUpdateBanner();
    // Multi-select labels en chips opnieuw renderen met vertaalde teksten
    if (typeof updateClubLabel === 'function')    updateClubLabel();
    if (typeof updateSponsorLabel === 'function') updateSponsorLabel();
    if (typeof renderSponsorMultiSelect === 'function') renderSponsorMultiSelect();
    if (typeof renderClubMultiSelect === 'function')    renderClubMultiSelect();
    // Coach-lijst chips + alle 3 tabs opnieuw
    if (typeof renderChips === 'function')      renderChips();
    if (typeof renderProgramma === 'function')  renderProgramma();
    if (typeof renderSancties === 'function')   renderSancties();
    if (typeof renderHeats === 'function')      renderHeats();
    // Uitslagen-tab: cats + actieve afstand
    if (typeof opCatChange === 'function' && document.getElementById('u-sel-cat')?.value) {
        opCatChange();
        if (document.getElementById('u-sel-afstand')?.value && typeof opAfstandChange === 'function') {
            opAfstandChange();
        }
    } else {
        // Lege cat-dropdown placeholder herstellen
        const c = document.getElementById('u-sel-cat');
        if (c && !c.value && c.options.length === 1) {
            c.options[0].textContent = t('uitsl_opt_kies_cat');
        }
    }
    // Auto-refresh stempel title
    document.querySelectorAll('.auto-refresh-stempel').forEach(el => {
        el.title = t('auto_refresh_title');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initI18n({ dict: T, onChange: _rerenderCoach });
});

const $ = id => document.getElementById(id);
const selComp = $('sel-comp');
// Multi-select state voor zowel sponsors als clubs.
// _sponsorAlle  = Array<string>      met alle sponsor-namen
// _sponsorSel   = Set<string>        met geselecteerde sponsor-namen
// _clubAlle     = Array<{full,short}> met alle clubs
// _clubSel      = Set<string>        met geselecteerde club_full's (voor backend)
let _sponsorAlle = [];
const _sponsorSel = new Set();
let _clubAlle = [];
const _clubSel = new Set();
const inpSnr = $('inp-snr'), btnToevoegen = $('btn-toevoegen');

// Bepaal welke search-modus we gebruiken op basis van de input-string.
// 1-op-1 overgenomen uit /public/_zoekModus zodat coach + public hetzelfde
// gedrag hebben:
//   - alleen cijfers, ≤4 tekens  → startnummer
//   - alleen cijfers, ≥5 tekens  → licentienummer (KNSB ~7-8 cijfers)
//   - bevat letters              → naam-zoek
function _coachZoekModus(tekst) {
    const t = tekst.trim();
    if (/^\d+$/.test(t)) return t.length <= 4 ? 'snr' : 'license';
    return 'naam';
}
const secSel = $('sectie-selectie'), secLijst = $('sectie-lijst'), secProg = $('sectie-programma');
const chipsEl = $('coach-chips'), aantalEl = $('coach-aantal');
const progEl = $('programma'), snrFb = $('snr-feedback');

let coachLijst = []; // [{snr, license_key, full_name, category, club_full, sponsor}]

// ── Programma-tab: inklap-state ──────────────────────────────────────────────
// _progIngeklapt bevat de groep-keys die momenteel INGEKLAPT zijn (default:
// alle bij eerste render). _progGroepAlleKeys = alle keys van de laatste
// render, nodig voor "Alles in/uit". _progGroepenMetMijn = keys waar een
// coach-rijder in zit ("Mijn ritten"-knop). _progEersteRender zorgt dat de
// default-collapsed alleen bij de eerste render van deze wedstrijd geldt.
const _progIngeklapt = new Set();
let _progGroepAlleKeys = [];
const _progGroepenMetMijn = new Set();
let _progEersteRender = true;

// Snapshot / restore van de programma-tab UI-state rond een re-render.
// renderProgramma() bouwt de HTML opnieuw en dat wist elke DOM-state:
// filter-strook data-attributen resetten naar 'alle', klap-balk naar 'in',
// en nieuwe .prog-groep elementen verliezen hun ingeklapt-class als de
// _progIngeklapt-set niet meer matcht (bv. bij nieuwe/gewijzigde keys).
// Rond herlaadProgramma() dus: eerst snapshot, na render restore.
function _snapshotProgUiState() {
    const tab = document.getElementById('programma');
    if (!tab) return null;
    const strook = tab.querySelector('.prog-filter-strook');
    const balk   = tab.querySelector('.prog-klap-balk');
    const open   = new Set();
    tab.querySelectorAll('.prog-groep').forEach(g => {
        if (g.classList.contains('samenvat')) return;
        if (!g.classList.contains('ingeklapt')) open.add(g.dataset.groepKey);
    });
    return {
        dag:     strook?.dataset.actieveDag     || 'alle',
        afstand: strook?.dataset.actieveAfstand || 'alle',
        klap:    balk?.dataset.actief           || '',
        open,
    };
}

function _restoreProgUiState(state) {
    if (!state) return;
    const tab = document.getElementById('programma');
    if (!tab) return;
    const strook = tab.querySelector('.prog-filter-strook');
    // Filter herstellen via de bestaande handler — die triggert
    // applyProgFilter (samenvat-modus, heat-tellers, verborgen items).
    if (strook) {
        if (state.dag && state.dag !== 'alle') {
            const p = strook.querySelector(
                `.prog-filter-panel[data-panel="dag"] .prog-filter-pill[data-value="${CSS.escape(state.dag)}"]`);
            if (p) kiesProgFilter('dag', state.dag, p);
        }
        if (state.afstand && state.afstand !== 'alle') {
            const p = strook.querySelector(
                `.prog-filter-panel[data-panel="afstand"] .prog-filter-pill[data-value="${CSS.escape(state.afstand)}"]`);
            if (p) kiesProgFilter('afstand', state.afstand, p);
        }
    }
    // Klap-balk: als user een preset actief had, klik die opnieuw. Anders
    // (handmatige mix van open/dicht) per-groep herstellen én _progIngeklapt
    // synchroniseren zodat een volgende preset-klik het juiste resultaat geeft.
    if (state.klap === 'uit' || state.klap === 'in' || state.klap === 'mijn') {
        const btn = tab.querySelector(`.prog-klap-balk .prog-klap-btn[data-actie="${state.klap}"]`);
        if (btn) btn.click();
    } else if (state.open) {
        tab.querySelectorAll('.prog-groep').forEach(g => {
            if (g.classList.contains('samenvat')) return;
            const key = g.dataset.groepKey;
            const moetOpen = state.open.has(key);
            g.classList.toggle('ingeklapt', !moetOpen);
            if (moetOpen) _progIngeklapt.delete(key);
            else          _progIngeklapt.add(key);
        });
        const balk = tab.querySelector('.prog-klap-balk');
        if (balk) {
            balk.dataset.actief = '';
            balk.querySelectorAll('.prog-klap-btn').forEach(b => b.classList.remove('actief'));
        }
    }
}

function klapGroep(hdrEl) {
    const groep = hdrEl.closest('.prog-groep');
    if (!groep) return;
    // Samenvat-modus: klikken doet niks.
    if (groep.classList.contains('samenvat')) return;
    const key = groep.dataset.groepKey;
    const nuIngeklapt = groep.classList.toggle('ingeklapt');
    if (nuIngeklapt) _progIngeklapt.add(key); else _progIngeklapt.delete(key);
    // Individuele klik → geen preset-actie past meer, wis de highlight.
    const balk = document.querySelector('.prog-klap-balk');
    if (balk) {
        balk.dataset.actief = '';
        balk.querySelectorAll('.prog-klap-btn').forEach(b => b.classList.remove('actief'));
    }
}

function klapProg(actie, btnEl) {
    const prog = document.getElementById('programma');
    if (!prog) return;
    _progIngeklapt.clear();
    if (actie === 'in') {
        _progGroepAlleKeys.forEach(k => _progIngeklapt.add(k));
    } else if (actie === 'mijn') {
        _progGroepAlleKeys.forEach(k => {
            if (!_progGroepenMetMijn.has(k)) _progIngeklapt.add(k);
        });
    }
    // 'uit' → _progIngeklapt blijft leeg = alles zichtbaar
    prog.querySelectorAll('.prog-groep').forEach(el => {
        el.classList.toggle('ingeklapt', _progIngeklapt.has(el.dataset.groepKey));
    });
    // Actieve knop-highlight bijwerken.
    const balk = (btnEl && btnEl.closest('.prog-klap-balk')) || document.querySelector('.prog-klap-balk');
    if (balk) {
        balk.dataset.actief = actie;
        balk.querySelectorAll('.prog-klap-btn').forEach(b =>
            b.classList.toggle('actief', b.dataset.actie === actie));
    }
}
let programmaCache = null; // {ritten, blokken}
let coachInfoCache = {}; // license_key → {entry_status, sancties:[]}
let alleComps = []; // ruwe lijst uit /?action=competitions — gebruikt door filterComps()

// Status-label: idx 0-5 → vertaalde tekst. Helper functie zodat de label
// dynamisch volgt op een taal-wissel zonder dat we de constanten herbouwen.
const STATUS_ICON  = ['⚠','✓','✗','✗','🚨','✓'];
// Status die voor een coach direct actie vereist (rood-alarm in de UI):
const STATUS_ALARM = new Set([0, 4]); // niet bevestigd + niet getekend
function getStatusLabel(i) {
    const idx = Number(i);
    return (idx >= 0 && idx <= 5) ? t('status_' + idx) : '';
}
// Sanctie-uitleg: codes met '-' worden in T met '_' opgeslagen (DQ-TF →
// sanc_DQ_TF) zodat JS-keys geldig blijven en consistent gegroepeerd zijn.
const SANCTIE_CODES = ['W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF'];
function getSanctieUitleg(code) {
    if (!SANCTIE_CODES.includes(code)) return '';
    return t('sanc_' + code.replace(/-/g, '_'));
}

const BADGE = { heats:'badge-serie', kwartfinale:'badge-kf', halve_finale:'badge-hf',
                finale_a:'badge-finale', finale_b:'badge-finale', runner_up:'badge-ru' };
// Ronde-labels worden runtime vertaald via getRondeLabel(rt) (idem als /public).
function getRondeLabel(rt) {
    const map = {
        heats: 'ronde_serie', kwartfinale: 'ronde_kf', halve_finale: 'ronde_hf',
        finale_a: 'ronde_finale', finale_b: 'ronde_b_finale', runner_up: 'ronde_runner_up',
    };
    return map[rt] ? t(map[rt]) : (rt || '');
}

function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function safeDatum(s) { return s ? new Date(String(s).replace(' ','T')) : null; }
function msTijd(ms) {
    if (ms==null) return '';
    const d=ms%1000, s=Math.floor(ms/1000)%60, m=Math.floor(ms/60000);
    return m>0?`${m}:${String(s).padStart(2,'0')}.${String(d).padStart(3,'0')}`
              :`${s}.${String(d).padStart(3,'0')}`;
}
// ── In-app bevestiging (vervangt native confirm) ─────────────────────────────
// Gebruik: const ok = await bevestig({ titel, tekst, bevestigLabel, annuleerLabel });
function bevestig({ titel, tekst = '', bevestigLabel, annuleerLabel } = {}) {
    titel         = titel         ?? t('bev_titel');
    bevestigLabel = bevestigLabel ?? t('bev_ok');
    annuleerLabel = annuleerLabel ?? t('bev_annuleer');
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'help-overlay';
        overlay.innerHTML = `
            <div class="help-box" style="max-width:420px">
                <div class="help-header">
                    <span>${esc(titel)}</span>
                    <button class="help-sluit" data-bev-actie="annuleer">&times;</button>
                </div>
                <div class="help-body" style="padding:18px 16px">${tekst}</div>
                <div class="bev-knoppen">
                    <button class="bev-btn bev-btn-annuleer" data-bev-actie="annuleer">${esc(annuleerLabel)}</button>
                    <button class="bev-btn bev-btn-bevestig" data-bev-actie="bevestig">${esc(bevestigLabel)}</button>
                </div>
            </div>`;
        const sluit = (resultaat) => { overlay.remove(); resolve(resultaat); };
        overlay.addEventListener('click', e => {
            if (e.target === overlay) return sluit(false);
            const actie = e.target.closest('[data-bev-actie]')?.dataset.bevActie;
            if (actie === 'bevestig') sluit(true);
            else if (actie === 'annuleer') sluit(false);
        });
        document.body.appendChild(overlay);
        // Focus standaard op de annuleer-knop (veiliger) — bevestigen kost
        // bewust een extra tap.
        overlay.querySelector('.bev-btn-annuleer')?.focus();
    });
}

// ── Verbinding-status: detecteert offline / server-down en toont banner ────
// Wordt door safeFetch hieronder bijgewerkt: succes → groen/verborgen,
// fout → banner met passende tekst. window 'online'-event triggert direct
// een refresh; visibilitychange (visible) triggert ook een refresh.
const _conn = {
    online: navigator.onLine,
    serverOk: true,
    lastSuccess: null,
    consecutiveFails: 0,
    refreshHook: null,
};

function _connBannerEl() {
    let el = document.getElementById('conn-banner');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'conn-banner';
    el.className = 'conn-banner';
    el.style.display = 'none';
    document.body.insertBefore(el, document.body.firstChild);
    return el;
}

function _connUpdateBanner() {
    const el = _connBannerEl();
    let bericht = '';
    if (!_conn.online) {
        bericht = t('conn_geen_internet');
    } else if (!_conn.serverOk) {
        bericht = t('conn_server_down');
    }
    if (bericht) {
        const tijdStr = _conn.lastSuccess
            ? _conn.lastSuccess.toLocaleTimeString(
                  (typeof getLocale === 'function' ? getLocale() : 'nl-NL'),
                  {hour:'2-digit', minute:'2-digit'})
            : '';
        const tijd = tijdStr ? ` <small>(${t('conn_laatste_update', {tijd: tijdStr})})</small>` : '';
        el.innerHTML = bericht + tijd;
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

// Grace-periode: na een fout blijft de banner ten minste deze tijd staan,
// ook als andere fetches in de tussentijd slagen. Voorkomt geflikker bij
// gemengde fouten (één endpoint down, ander werkt).
const _CONN_GRACE_MS = 10_000;

function _connOk() {
    const wasFout = !_conn.serverOk || !_conn.online;
    _conn.lastSuccess = new Date();
    _conn.consecutiveFails = 0;
    const inGrace = _conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) < _CONN_GRACE_MS;
    _conn.online = true;
    if (!inGrace) _conn.serverOk = true;
    if (wasFout && !inGrace) _connUpdateBanner();
}

function _connFail(reden) {
    if (reden === 'network') _conn.online = false;
    else                     _conn.serverOk = false;
    _conn.lastFailureMs = Date.now();
    _conn.consecutiveFails++;
    _connUpdateBanner();
    setTimeout(() => {
        if (_conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) >= _CONN_GRACE_MS) {
            _conn.serverOk = true;
            _connUpdateBanner();
        }
    }, _CONN_GRACE_MS + 100);
}

window.addEventListener('online', () => {
    _conn.online = true;
    _connUpdateBanner();
    if (typeof _conn.refreshHook === 'function') _conn.refreshHook();
});
window.addEventListener('offline', () => {
    _conn.online = false;
    _connUpdateBanner();
});

// Retry-strategie: bij 429 (rate-limit) max 1× opnieuw proberen met
// jitter (1.5-4.5 s) zodat 15 coaches niet synchroon weer aankloppen.
// Te agressief retryen verergert de rate-limit alleen maar — daarom geen
// retry-storm meer. Bij definitief 429 krijgt de UI de 429 gewoon terug.
// Auto-login via QR-scan: als ?pw=... in de URL staat (gestopt in de QR
// door api/poster.php voor coach-posters met wachtwoord), sla 'm direct
// op in localStorage en strip de query-param uit de URL — zo blijft het
// wachtwoord niet in browser-history/bookmarks hangen en is de coach na
// scannen meteen ingelogd zonder te hoeven typen. Bij fout wachtwoord
// volgt de normale 401-prompt-flow van coachFetch.
(function _coachAutoLogin() {
    try {
        const params = new URLSearchParams(window.location.search);
        const pw = params.get('pw');
        if (!pw) return;
        localStorage.setItem('coach_pw', pw);
        params.delete('pw');
        const newSearch = params.toString();
        const newUrl = window.location.pathname
                     + (newSearch ? '?' + newSearch : '')
                     + window.location.hash;
        history.replaceState(null, '', newUrl);
    } catch (e) { console.warn('[coach] auto-login fout:', e); }
})();

// Coach-app wachtwoord-gate: bij elke fetch sturen we localStorage 'coach_pw'
// als header X-Coach-PW. Bij 401 (= ongeldig of ontbrekend) prompt voor
// nieuw wachtwoord, retry. Geen wachtwoord ingesteld op server → backend
// returnt nooit 401, geen prompt nodig.
function _coachFetchOpts() {
    const pw = localStorage.getItem('coach_pw') || '';
    return pw ? { headers: { 'X-Coach-PW': pw } } : {};
}

// Wrapper voor POST/GET met X-Coach-PW header + 401-retry-prompt.
// safeFetch is GET-only — voor POST (toevoegen, coach_info, etc.) heb je
// deze helper nodig zodat de auth-gate niet wegvalt.
async function coachFetch(url, opts = {}) {
    const pw = localStorage.getItem('coach_pw') || '';
    const headers = { ...(opts.headers || {}) };
    if (pw) headers['X-Coach-PW'] = pw;
    const finalOpts = { ...opts, headers };
    let res = await fetch(url, finalOpts);
    if (res.status === 401) {
        const ok = await _vraagCoachWachtwoord();
        if (ok) {
            // Retry met nieuw ingevoerd wachtwoord
            const newPw = localStorage.getItem('coach_pw') || '';
            if (newPw) finalOpts.headers['X-Coach-PW'] = newPw;
            res = await fetch(url, finalOpts);
        }
    }
    return res;
}
async function _vraagCoachWachtwoord() {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'cw-overlay';
        overlay.innerHTML = `
            <div class="cw-dialog">
                <h2>🔐 ${esc(t('coach_pw_titel'))}</h2>
                <p>${esc(t('coach_pw_uitleg'))}</p>
                <input type="text" id="cw-input" class="cw-input" autocomplete="off" autocapitalize="none">
                <div class="cw-knoppen">
                    <button class="cw-btn cw-btn-ok" id="cw-ok">${esc(t('coach_pw_ok'))}</button>
                </div>
                <div class="cw-fout" id="cw-fout" style="display:none"></div>
            </div>`;
        document.body.appendChild(overlay);
        const input = overlay.querySelector('#cw-input');
        const ok    = overlay.querySelector('#cw-ok');
        const fout  = overlay.querySelector('#cw-fout');
        setTimeout(() => input.focus(), 50);
        const probeer = async () => {
            const pw = input.value.trim();
            if (!pw) return;
            ok.disabled = true; ok.textContent = '…';
            try {
                const r = await fetch('../api/coach_auth.php?action=verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: pw }),
                });
                const d = await r.json();
                if (r.ok && d.ok) {
                    localStorage.setItem('coach_pw', pw);
                    overlay.remove();
                    resolve(true);
                } else {
                    fout.textContent = d.error || t('coach_pw_fout');
                    fout.style.display = '';
                    input.select();
                }
            } catch {
                fout.textContent = t('coach_pw_neterr');
                fout.style.display = '';
            } finally {
                ok.disabled = false; ok.textContent = t('coach_pw_ok');
            }
        };
        ok.addEventListener('click', probeer);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') probeer(); });
    });
}

async function safeFetch(url, maxRetries = 1) {
    try {
        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            let res = await fetch(url, _coachFetchOpts());
            // Coach-wachtwoord-gate: 401 betekent geen of foutief X-Coach-PW.
            // Prompt opnieuw, retry zodra ingevuld. Niet retry-tellen — dit
            // is een interactieve user-action, geen "auto-storm".
            if (res.status === 401) {
                const ok = await _vraagCoachWachtwoord();
                if (!ok) return res;
                res = await fetch(url, _coachFetchOpts());
            }
            if (res.status === 429 && attempt < maxRetries) {
                // Random wait 1.5-4.5 s — voorkomt synchrone retries
                const wait = 1500 + Math.random() * 3000;
                await new Promise(r => setTimeout(r, wait));
                continue;
            }
            if (res.status >= 500) {
                _connFail('server');
                return res;
            }
            if (res.status === 429) {
                // Definitief opgegeven — server staat onder druk
                _connFail('server');
                return res;
            }
            _connOk();
            return res;
        }
        // Onbereikbaar, maar TypeScript-vrij defensief
        return new Response(null, { status: 504 });
    } catch (e) {
        _connFail('network');
        throw e;
    }
}

// ── Setup-modal (stap 1 + stap 2 + coach-lijst) ──────────────────────────────
// Vervangt de altijd-zichtbare secties bovenaan. Opent via de setup-strip,
// of automatisch bij eerste bezoek van de dag (datum-key in localStorage).
function openSetupModal() {
    const m = document.getElementById('setup-modal');
    if (m) m.classList.add('open');
    document.body.style.overflow = 'hidden'; // scroll-lock achtergrond
}
function closeSetupModal() {
    const m = document.getElementById('setup-modal');
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
}
// "Wedstrijd X · N rijders" — samenvat voor de strook. Bij 0 rijders alleen
// wedstrijd; bij 1 speciale label ("1 rijder") ipv aantal + meervoud.
function updateSetupStrip() {
    const el = document.getElementById('setup-strip-tekst');
    if (!el) return;
    const opt = selComp.selectedOptions[0];
    const compNaam  = opt?.textContent?.trim() || '';
    // De comp-info dataset heeft datum/plaats — wedstrijd-naam zit als
    // text-content. Datum uit `comp-info` innerHTML halen zou fragile zijn;
    // laten we het simpel: alleen naam + rijders-count.
    let rijderStr = '';
    if (coachLijst.length === 1) {
        rijderStr = `<small>${esc(t('setup_strip_1rijder'))}</small>`;
    } else if (coachLijst.length > 1) {
        rijderStr = `<small>${coachLijst.length} ${esc(t('setup_strip_rijders'))}</small>`;
    }
    if (selComp.value && compNaam) {
        el.innerHTML = `<b>${esc(compNaam)}</b>${rijderStr}`;
    } else {
        el.innerHTML = `<span class="setup-strip-empty">${esc(t('setup_strip_leeg'))}</span>`;
    }
}
// Escape sluit modal (accessibility + snelheid).
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const m = document.getElementById('setup-modal');
        if (m && m.classList.contains('open')) closeSetupModal();
    }
});
// Eerste-bezoek-per-dag: modal automatisch openen zodat de coach bij een
// nieuwe dag naar wedstrijd-keuze gestuurd wordt (bijna altijd een andere).
// Bij herhaald openen dezelfde dag: geen ruis.
(function autoOpenFirstOfDay() {
    const vandaag = new Date().toISOString().slice(0, 10);
    const laatstGezien = localStorage.getItem('ic_coach_setup_dag') || '';
    const nogNiksGekozen = !selComp || !selComp.value;
    if (laatstGezien !== vandaag || nogNiksGekozen) {
        // Kleine timeout zodat applyI18n() de modal-teksten in juiste taal
        // heeft gezet vóór openen (anders zie je even "Wedstrijd & rijders"
        // in verkeerde taal flashen).
        setTimeout(() => {
            openSetupModal();
            localStorage.setItem('ic_coach_setup_dag', vandaag);
        }, 100);
    }
})();

// ── Coach-lijst persistentie (localStorage per wedstrijd) ────────────────────
function lsKey() { return `coach_lijst_${selComp.value || 'geen'}`; }
function saveCoachLijst() {
    if (!selComp.value) return;
    localStorage.setItem(lsKey(), JSON.stringify(coachLijst));
}
function loadCoachLijst() {
    if (!selComp.value) { coachLijst = []; return; }
    try { coachLijst = JSON.parse(localStorage.getItem(lsKey()) || '[]'); }
    catch { coachLijst = []; }
    if (!Array.isArray(coachLijst)) coachLijst = [];
}

function voegToeAanLijst(persoon) {
    if (!persoon || !persoon.license_key) return false;
    // Dedup op license_key (uniek per persoon). Eerder op snr → bug bij
    // twee rijders met hetzelfde startnummer: de tweede werd geweigerd.
    if (coachLijst.some(p => p.license_key === persoon.license_key)) return false;
    coachLijst.push({
        snr: persoon.snr != null ? parseInt(persoon.snr) : null,
        license_key: persoon.license_key,
        full_name: persoon.full_name,
        category: persoon.category || '',
        club_full: persoon.club_full || '',
        sponsor: persoon.sponsor || '',
    });
    return true;
}

function verwijderUitLijst(licenseKey) {
    // Filter op license_key (uniek). Eerder op snr → bug bij twee
    // rijders met hetzelfde nummer (beiden tegelijk verwijderd).
    coachLijst = coachLijst.filter(p => p.license_key !== licenseKey);
}

// ── UI-render ────────────────────────────────────────────────────────────────
function renderChips() {
    // Setup-strook mee-updaten — die toont aantal rijders + wedstrijd, dus
    // hij moet vernieuwen bij elke coach-lijst-mutatie.
    if (typeof updateSetupStrip === 'function') updateSetupStrip();
    aantalEl.textContent = t(coachLijst.length === 1 ? 'coach_aantal_single' : 'coach_aantal_plural', {n: coachLijst.length});
    if (coachLijst.length === 0) {
        chipsEl.innerHTML = `<span style="color:#888;font-size:.85rem">${t('coach_leeg')}</span>`;
        secLijst.style.display = 'block';
        return;
    }
    secLijst.style.display = 'block';
    const gesorteerd = [...coachLijst].sort((a,b) => parseInt(a.snr) - parseInt(b.snr));
    chipsEl.innerHTML = gesorteerd.map(p => {
        const info = coachInfoCache[p.license_key];
        const st = info ? parseInt(info.entry_status) : 1;
        const alarm = STATUS_ALARM.has(st);
        const icon = alarm ? (STATUS_ICON[st] + ' ') : '';
        const stLabel = getStatusLabel(st);
        return `<span class="chip${alarm ? ' chip-waarschuw' : ''}" title="${esc(p.full_name)} — ${esc(p.club_full)}${p.sponsor ? ' / ' + esc(p.sponsor) : ''}\n${t('status_label')}: ${esc(stLabel)}">
            <span class="chip-snr">${esc(p.snr)}</span>
            <span>${icon}${esc(p.full_name)}</span>
            <span class="x" data-lic="${esc(p.license_key)}">×</span>
         </span>`;
    }).join('');
    chipsEl.querySelectorAll('.x').forEach(x => {
        x.onclick = async () => {
            // Verwijder per persoon (license_key), niet per snr — twee
            // rijders kunnen hetzelfde startnummer hebben.
            const lic = x.dataset.lic;
            const persoon = coachLijst.find(p => p.license_key === lic);
            if (!persoon) return;
            const naam = persoon.full_name || t('bev_verwijder_snr_fallback', {snr: persoon.snr});
            const ok = await bevestig({
                titel: t('bev_verwijder_titel'),
                tekst: t('bev_verwijder_tekst', { naam: esc(naam), snr: esc(persoon.snr) }),
                bevestigLabel: t('bev_verwijder_ok'),
                annuleerLabel: t('bev_annuleer'),
            });
            if (!ok) return;
            verwijderUitLijst(lic);
            saveCoachLijst();
            renderChips();
            renderProgramma();
            await laadCoachInfo();
            renderChips();
            renderSancties();
            renderHeats();
        };
    });
}

async function verversCoachLijstUI() {
    renderChips();
    renderProgramma();
    await laadCoachInfo();
    renderChips();
    renderSancties();
    renderHeats();
}

function renderProgramma() {
    if (!programmaCache) { progEl.innerHTML = `<div class="leeg-melding">${t('prog_laden')}</div>`; return; }
    // Match op license_key (uniek per persoon), niet op snr — twee rijders
    // kunnen hetzelfde startnummer hebben en dan zou snr-only beide heats
    // valselijk highlighten. mijnSnrs blijft beschikbaar voor wat al snr
    // gebruikt, maar primaire match is via lic.
    const mijnLics = new Set(coachLijst.map(p => p.license_key));
    const mijnSnrs = new Set(coachLijst.map(p => parseInt(p.snr)));
    const { ritten, blokken } = programmaCache;

    // De canonieke volgorde komt uit tijdschema_ritten.volgorde — die geeft
    // de admin via drag-drop aan. Non-ronde blokken (pauze/inrijden/etc)
    // worden tussen ritten geschoven op basis van hun blok_volgorde t.o.v.
    // de blok_volgorde van de ronde-blok van iedere rit. Match exact het
    // admin-render-algoritme in js/tijdschema.js (lines 1078-1095) zodat
    // cross-blok drag-drops correct doorkomen — vroeger werd primair op
    // r.blok_volgorde gesorteerd, en die verandert NIET bij een drag-drop.
    //
    // KRITIEKE FIX: parseInt op volgorde-vergelijkingen — PDO levert SMALLINT
    // als JS-string ("10", "100"), zonder coercion ging "100" <= "20"
    // lexicaal (true!) en kwam wedstrijdstart-dag-2 verkeerd terecht.
    const num = v => parseInt(v) || 0;
    const allesGesorteerd = [];
    const sortedBlk = (blokken || []).slice()
        .sort((a, b) => num(a.volgorde) - num(b.volgorde));
    let blkIdx = 0;
    for (const r of (ritten || [])) {
        const rBV = num(r.blok_volgorde);
        while (blkIdx < sortedBlk.length
               && num(sortedBlk[blkIdx].volgorde) <= rBV) {
            allesGesorteerd.push({ type:'blok', data: sortedBlk[blkIdx++] });
        }
        allesGesorteerd.push({ type:'rit', data: r });
    }
    while (blkIdx < sortedBlk.length) {
        allesGesorteerd.push({ type:'blok', data: sortedBlk[blkIdx++] });
    }

    if (!allesGesorteerd.length) {
        progEl.innerHTML = `<div class="leeg-melding">${t('prog_geen')}</div>`;
        return;
    }

    // Multi-day dag-header info: bij meerdere wedstrijdstart-blokken één
    // header per dag-cluster. Inrijden/pauze direct vóór een wedstrijdstart
    // horen BIJ die nieuwe dag (warm-up), niet bij de vorige — twee passes:
    //   1. FORWARD: wedstrijdstart-items zetten huidige dag; ritten erven die.
    //   2. BACKWARD: niet-ronde blokken vóór een latere dag claimen die dag.
    const wsBlokken = (blokken || [])
        .filter(b => (b.blok_type || '').toLowerCase() === 'wedstrijdstart')
        .sort((a, b) => num(a.volgorde) - num(b.volgorde));
    const isMultiDag = wsBlokken.length > 1;
    // datumLbl = lange vorm voor de header (vrijdag 28 mei),
    // kortLbl  = korte vorm voor de filter-knop (vr 28-5). Beide locale-aware.
    const dagInfoPerNr = new Map();
    const _locale = (typeof getLocale === 'function') ? getLocale() : 'nl-NL';
    wsBlokken.forEach((ws, i) => {
        let datumLbl = '', kortLbl = '';
        if (ws.datum) {
            const d = new Date(ws.datum + 'T00:00:00');
            if (!isNaN(d)) {
                datumLbl = d.toLocaleDateString(_locale,
                    {weekday:'long', day:'numeric', month:'long'});
                kortLbl  = d.toLocaleDateString(_locale,
                    {weekday:'short', day:'numeric', month:'numeric'});
            }
        }
        dagInfoPerNr.set(i + 1, { datumLbl, kortLbl });
    });
    // Dag-per-item berekenen
    const dagPerItem = new Array(allesGesorteerd.length);
    let _huidigeDag = 0;
    allesGesorteerd.forEach((it, idx) => {
        if (it.type === 'blok'
            && (it.data.blok_type || '').toLowerCase() === 'wedstrijdstart') {
            const wsIdx = wsBlokken.findIndex(w => String(w.id) === String(it.data.id));
            if (wsIdx >= 0) _huidigeDag = wsIdx + 1;
        }
        dagPerItem[idx] = _huidigeDag || 1;
    });
    // Alleen inrijden + pauze claimen de opvolgende dag — ceremonie blijft
    // bij de voorgaande dag (afsluiting/prijsuitreiking) en breekt de claim-
    // keten zodat eerder gelegen pauzes ook niet onterecht claimen.
    let _komendeDag = null;
    for (let idx = allesGesorteerd.length - 1; idx >= 0; idx--) {
        const it = allesGesorteerd[idx];
        const bt = it.type === 'blok'
            ? (it.data.blok_type || '').toLowerCase() : '';
        const isWs = bt === 'wedstrijdstart';
        if (it.type === 'rit' || isWs) {
            _komendeDag = dagPerItem[idx];
        } else if (it.type === 'blok') {
            const isWarmUp = (bt === 'inrijden' || bt === 'pauze');
            if (isWarmUp && _komendeDag && dagPerItem[idx] < _komendeDag) {
                dagPerItem[idx] = _komendeDag;
            } else if (!isWarmUp) {
                _komendeDag = dagPerItem[idx];
            }
        }
    }

    // Haal HH:MM uit "HH:MM:SS" (TIME) óf "YYYY-MM-DD HH:MM:SS" (DATETIME).
    const hhmm = v => {
        if (!v) return '';
        const s = String(v);
        const m = s.match(/(\d{1,2}:\d{2})/);
        return m ? m[1] : '';
    };
    // Render één tijdschema-blok (pauze / inrijden / wedstrijdstart /
    // ceremonie / herstart). Toont icoon, tijdstip, duur en (voor inrijden)
    // de cats + (voor pauze/herstart) eventuele opmerking. Match in stijl
    // de admin-tijdschema rendering zodat coach hetzelfde ziet als wat in
    // het programma is geconfigureerd.
    const blokHtml = b => {
        // Lokaal bt (block type) ipv t om global t() niet te shadowen.
        const bt = (b.blok_type || '').toLowerCase();
        const tijd = hhmm(b.tijdstip);
        const tijdPrefix = tijd ? `<span class="blok-tijd">🕓 ${esc(tijd)}</span>` : '';
        const duur = b.duur ? `<span class="blok-duur">${b.duur} ${t('prog_blok_min')}</span>` : '';
        const opm  = b.opmerking ? `<span class="blok-opm"> — ${esc(b.opmerking)}</span>` : '';
        const cats = b.inrijd_cat_namen ? `<div class="blok-cats">${esc(b.inrijd_cat_namen)}</div>` : '';
        let icoon, lbl;
        if      (bt === 'pauze')          { icoon = '⏸'; lbl = t('prog_blok_pauze'); }
        else if (bt === 'inrijden')       { icoon = '🛼'; lbl = t('prog_blok_inrijden'); }
        else if (bt === 'wedstrijdstart') { icoon = '🏁'; lbl = t('prog_blok_wedstrijdstart'); }
        else if (bt === 'ceremonie')      { icoon = '🏆'; lbl = t('prog_blok_ceremonie'); }
        else if (bt === 'herstart')       { icoon = '🔄'; lbl = t('prog_blok_herstart'); }
        else                               { icoon = '🕓'; lbl = (b.blok_type || '').toUpperCase(); }
        return `<div class="blok-rij blok-${esc(bt)}">
            <div class="blok-rij-top">
                ${tijdPrefix}
                <span class="blok-titel">${icoon} ${esc(lbl)}</span>
                ${duur}
                ${opm}
            </div>
            ${cats}
        </div>`;
    };

    const ritHtml = r => {
        // Per heat-rijder paar {snr, lic} — match op license zodat we niet
        // valselijk een heat met "snr=166 (andere persoon)" highlighten.
        // Fallback op heat_snrs-only voor oude cached payloads.
        const heatRijders = Array.isArray(r.heat_rijders) ? r.heat_rijders
                          : (r.heat_snrs || []).map(n => ({snr: parseInt(n), lic: null}));
        const mijnInHeat = heatRijders
            .filter(hr => hr.lic ? mijnLics.has(hr.lic) : mijnSnrs.has(hr.snr))
            .map(hr => hr.snr)
            .sort((a,b) => a-b);
        const leeg = !r.heat_id || (r.entries_count ?? 0) === 0;
        const rondeBadge = r.ronde_type && BADGE[r.ronde_type]
            ? `<span class="badge ${BADGE[r.ronde_type]}">${getRondeLabel(r.ronde_type)}</span>` : '';
        const mijnStrip = mijnInHeat.length
            ? `<div class="heat-mijn-snrs">${mijnInHeat.map(n => `<span class="m-snr">${n}</span>`).join('')}</div>`
            : '';
        // Status-icoon (vaste breedte zodat de layout niet schuift):
        //   🏁 = resultaten aanwezig · 🚩 = startlijst definitief · ○ = nog niks
        const statusIcon = (r.resultaten_count ?? 0) > 0 ? '🏁'
                        : r.definitief                    ? '🚩'
                        :                                   '<span class="heat-status-leeg">○</span>';
        const klasse = 'heat-rij'
            + (mijnInHeat.length ? ' mijn' : '')
            + (leeg ? ' leeg' : '')
            + ((r.resultaten_count ?? 0) > 0 ? ' gereden' : '');
        const klik = leeg ? '' :
            ` data-rit-naam="${esc(r.rit_naam)}" data-dc-naam="${esc(r.dc_naam ?? '')}" onclick="toonRitDetail(this)"`;
        // Pills komen op een aparte regel onder de naam, zodat ze bij coaches
        // met veel rijders niet in het rit-naam-blok worden geperst.
        const opmHtml = r.rit_opmerking
            ? `<div class="heat-rit-opm">📝 ${esc(r.rit_opmerking)}</div>` : '';
        return `<div class="${klasse}"${klik}>
            <div class="heat-rij-top">
                <div class="heat-status">${statusIcon}</div>
                <div class="heat-info">
                    <div class="heat-naam">${rondeBadge}${esc(r.rit_naam)}</div>
                    <div class="heat-sub">${esc(r.dc_naam ?? '')}${leeg ? ' · ' + t('prog_geen_startlijst') : ''}</div>
                    ${opmHtml}
                </div>
            </div>
            ${mijnStrip}
        </div>`;
    };

    // Render items. Consecutieve ritten met dezelfde combi_group worden in
    // één kader gegroepeerd (gecombineerde rit — categorieën rijden tegelijk).
    let html = '';

    // Nieuwe filter-strook: dag- + afstand-triggers, elk uitklapbaar.
    // Zelfde patroon als public.
    const _afsPerDagCoach = new Map();
    const _afsAlleCoach   = new Set();
    allesGesorteerd.forEach((item, idx) => {
        if (item.type !== 'rit' || !item.data.distance_naam) return;
        const dg = dagPerItem[idx];
        if (!_afsPerDagCoach.has(dg)) _afsPerDagCoach.set(dg, new Set());
        _afsPerDagCoach.get(dg).add(item.data.distance_naam);
        _afsAlleCoach.add(item.data.distance_naam);
    });
    const afsAlleArrCoach = [..._afsAlleCoach].sort((a,b) => a.localeCompare(b, 'nl', {numeric:true}));
    const heeftMeerdereAfsCoach = afsAlleArrCoach.length > 1;
    if (isMultiDag || heeftMeerdereAfsCoach) {
        const afsPerDagObjCoach = {};
        for (const [dg, set] of _afsPerDagCoach) afsPerDagObjCoach[dg] = [...set].sort((a,b) => a.localeCompare(b, 'nl', {numeric:true}));
        html += `<div class="prog-filter-strook" data-actieve-dag="alle" data-actieve-afstand="alle"
                      data-afs-per-dag='${esc(JSON.stringify(afsPerDagObjCoach))}'>`;
        if (isMultiDag) {
            html += `<button class="prog-filter-trigger" type="button" data-filter="dag" onclick="togglePanel(this)">
                <span class="prog-filter-icon">📅</span>
                <span class="prog-filter-lbl">${esc(t('prog_filter_alle_dagen'))}</span>
                <span class="prog-filter-caret">▼</span>
            </button>
            <div class="prog-filter-panel verborgen" data-panel="dag">
                <button class="prog-filter-pill actief" type="button" data-value="alle"
                        onclick="kiesProgFilter('dag','alle',this)">${esc(t('prog_dag_alle'))}</button>`;
            for (let dn = 1; dn <= wsBlokken.length; dn++) {
                const info = dagInfoPerNr.get(dn);
                const subDatum = info?.kortLbl
                    ? `<span class="prog-filter-pill-sub">${esc(info.kortLbl)}</span>`
                    : '';
                html += `<button class="prog-filter-pill" type="button" data-value="${dn}"
                                 onclick="kiesProgFilter('dag','${dn}',this)"
                                 title="${esc(info?.datumLbl || '')}"
                    >${esc(t('prog_dag'))} ${dn}${subDatum}</button>`;
            }
            html += `</div>`;
        }
        if (heeftMeerdereAfsCoach) {
            html += `<button class="prog-filter-trigger" type="button" data-filter="afstand" onclick="togglePanel(this)">
                <span class="prog-filter-icon">🏁</span>
                <span class="prog-filter-lbl">${esc(t('prog_filter_alle_afstanden'))}</span>
                <span class="prog-filter-caret">▼</span>
            </button>
            <div class="prog-filter-panel verborgen" data-panel="afstand">
                <button class="prog-filter-pill actief" type="button" data-value="alle"
                        onclick="kiesProgFilter('afstand','alle',this)">${esc(t('prog_afstand_alle'))}</button>`;
            for (const afs of afsAlleArrCoach) {
                html += `<button class="prog-filter-pill" type="button" data-value="${esc(afs)}"
                                 onclick="kiesProgFilter('afstand',this.dataset.value,this)"
                    >${esc(afs)}</button>`;
            }
            html += `</div>`;
        }
        html += `</div>`;
    }

    // Drie inklap-knoppen: Alles uit / Alles in / Mijn rijders.
    // Standaard-state = alles ingeklapt, dus "Alles in" is de actieve knop.
    html += `<div class="prog-klap-balk" data-actief="in">
        <button type="button" class="prog-klap-btn actief" data-actie="in" onclick="klapProg('in', this)">▶ ${esc(t('prog_klap_alles_uit'))}</button>
        <button type="button" class="prog-klap-btn" data-actie="uit" onclick="klapProg('uit', this)">▼ ${esc(t('prog_klap_alles_in'))}</button>
        <button type="button" class="prog-klap-btn" data-actie="mijn" onclick="klapProg('mijn', this)">👤 ${esc(t('prog_klap_mijn'))}</button>
    </div>`;

    // ── Renderloop met cat-groepering ──────────────────────────────────
    // Consecutieve ritten met dezelfde (dc_naam + ronde_type + dag) worden
    // in één inklapbare `.prog-groep` gestopt. Blokken (pauze/inrijden/…)
    // en dc-wisselingen breken de groep. Combi-boxen zitten BINNEN een
    // groep. Groep-key = "dcNaam|rondeType|dag".
    let vorigeCombi = null;
    let vorigeDag   = null;
    let vorigeGroepKey = null;  // sluit-open tussen consecutieve heat-clusters
    // Live-tellers voor de huidige groep. Header wordt met markers gebouwd
    // en post-render aangevuld met de accurate waardes (aantal mijn-rijders
    // + status-icoon 🏁/◑/🚩 op basis van heat-progressie).
    let huidigeGroepMijnCount = 0;
    let huidigeGroepAantalHeats = 0;
    let huidigeGroepMetRes = 0;      // heats met resultaten_count > 0
    let huidigeGroepDefinitief = 0;  // heats met definitief == true
    const groepHdrPlaceholders = []; // [{key, count, statusIcon}] voor post-fix

    // Combi-wrapper (outer): omhult meerdere cat-groepen die dezelfde
    // combi_group hebben (categorieën die tegelijk rijden). Groepen zitten
    // BINNEN de wrap — omgekeerde nesting t.o.v. vroeger (box binnen groep).
    let combiWrapOpen = false;
    const openCombiWrap = (dag, afstand) => {
        const afsAttr = afstand ? ` data-afstand-key="${esc(afstand)}"` : '';
        html += `<div class="prog-combi-wrap" data-dag-nr="${dag}"${afsAttr}>
            <div class="prog-combi-kop">${esc(t('prog_combi_kop'))}</div>
            <div class="prog-combi-body">`;
        combiWrapOpen = true;
    };
    const sluitCombiWrap = () => {
        if (combiWrapOpen) { html += `</div></div>`; combiWrapOpen = false; }
        vorigeCombi = null;
    };
    // Status-icoon voor de groep — spiegelt de heat-status-icoontjes.
    // Returnt {icon, i18nKey} zodat de post-fix een correcte tooltip-key
    // kan opzoeken (emoji's zijn geen geldige JS-identifiers voor object-
    // keys, dus i18n gebruikt semantische namen: klaar/deels/geloot).
    const bepaalStatus = () => {
        if (huidigeGroepAantalHeats === 0) return { icon: '', i18nKey: '' };
        if (huidigeGroepMetRes === huidigeGroepAantalHeats)  return { icon: '🏁', i18nKey: 'prog_groep_status_klaar' };
        if (huidigeGroepMetRes > 0)                          return { icon: '◑', i18nKey: 'prog_groep_status_deels' };
        if (huidigeGroepDefinitief === huidigeGroepAantalHeats) return { icon: '🚩', i18nKey: 'prog_groep_status_geloot' };
        return { icon: '', i18nKey: '' };
    };
    const sluitGroep = () => {
        if (vorigeGroepKey !== null) {
            html += `</div></div>`; // sluit .prog-groep-body en .prog-groep
            const st = bepaalStatus();
            groepHdrPlaceholders.push({
                key: vorigeGroepKey,
                count: huidigeGroepMijnCount,
                statusIcon: st.icon,
                statusKey: st.i18nKey,
            });
            vorigeGroepKey = null;
            huidigeGroepMijnCount = 0;
            huidigeGroepAantalHeats = 0;
            huidigeGroepMetRes = 0;
            huidigeGroepDefinitief = 0;
        }
    };
    const openGroep = (key, r, dag) => {
        // Bij eerste render van deze wedstrijd: altijd ingeklapt (default).
        // _progIngeklapt wordt pas post-render gevuld — dus bij render-tijd
        // is de Set nog leeg en zou alles uitgeklapt lijken.
        const ingeklapt = _progEersteRender || _progIngeklapt.has(key);
        const rondeLbl  = r.ronde_type && BADGE[r.ronde_type]
            ? `<span class="badge ${BADGE[r.ronde_type]}" style="margin-right:6px">${getRondeLabel(r.ronde_type)}</span>`
            : '';
        // းMarkers voor post-render: mijn-class (op de groep-div) + mijn-badge +
        // status-icoon met accurate waardes. De mijn-class gaat via een INDEX-
        // marker i.p.v. querySelector-op-key, zodat élke groep onafhankelijk de
        // juiste .mijn krijgt — ook als twee groepen dezelfde data-groep-key
        // delen (querySelector pakte dan alleen de eerste → badge onzichtbaar).
        const idx = groepHdrPlaceholders.length;
        const iconMarker      = `[[STATUS-ICON-${idx}]]`;
        const mijnMarker      = `[[MIJN-BADGE-${idx}]]`;
        const mijnClassMarker = `[[MIJN-CLASS-${idx}]]`;
        const afsAttr = r.distance_naam ? ` data-afstand-key="${esc(r.distance_naam)}"` : '';
        const rtAttr  = r.ronde_type ? ` data-ronde-type="${esc(r.ronde_type)}"` : '';
        html += `<div class="prog-groep${ingeklapt ? ' ingeklapt' : ''}${mijnClassMarker}" data-groep-key="${esc(key)}" data-dag-nr="${dag}"${afsAttr}${rtAttr}>
            <div class="prog-groep-hdr" onclick="klapGroep(this)">
                <span class="prog-groep-chev">▼</span>
                <span class="prog-groep-status">${iconMarker}</span>
                <span class="prog-groep-titel">${rondeLbl}${esc(r.dc_naam ?? '')}</span>
                ${mijnMarker}
            </div>
            <div class="prog-groep-body">`;
        vorigeGroepKey = key;
        huidigeGroepMijnCount = 0;
        huidigeGroepAantalHeats = 0;
        huidigeGroepMetRes = 0;
        huidigeGroepDefinitief = 0;
    };
    // Volledige sluit: eerst de groep, dan de combi-wrapper eromheen.
    const sluitAlles = () => { sluitGroep(); sluitCombiWrap(); };

    allesGesorteerd.forEach((item, idx) => {
        const dag = dagPerItem[idx];
        // Dag-header (multi-day): sluit alles, dan header.
        if (isMultiDag && dag !== vorigeDag) {
            sluitAlles();
            const info = dagInfoPerNr.get(dag);
            const lbl = info?.datumLbl ? `Dag ${dag} — ${info.datumLbl}` : `Dag ${dag}`;
            html += `<div class="prog-dag-header" data-dag-nr="${dag}">${esc(lbl)}</div>`;
            vorigeDag = dag;
        }
        // Blok = altijd los tussen groepen, sluit lopende groep.
        if (item.type === 'blok') {
            sluitAlles();
            const raw = blokHtml(item.data);
            html += raw.replace(/^<div /, `<div data-dag-nr="${dag}" `);
            return;
        }
        // Rit: eerst de combi-wrap (outer), dan de groep (binnen de wrap).
        const r = item.data;
        // 1) Combi-wrap: bij combi_group-wissel sluit lopende groep + wrap, en
        //    open een nieuwe wrap als deze rit een combi_group heeft. Zo komen
        //    álle cat-groepen die samen rijden in één blauw kader.
        const combi = r.combi_group ? parseInt(r.combi_group) : null;
        if (combi !== vorigeCombi) {
            sluitGroep();
            sluitCombiWrap();
            if (combi !== null) openCombiWrap(dag, r.distance_naam);
            vorigeCombi = combi;
        }
        // 2) Groep per (dc_naam + ronde_type + dag) — binnen de wrap.
        const grpKey = `${r.dc_naam || '?'}|${r.ronde_type || '?'}|${dag}`;
        if (grpKey !== vorigeGroepKey) {
            sluitGroep();
            openGroep(grpKey, r, dag);
        }
        // Coach-rijders + status-tellers voor de groep-badge en het icoon.
        const heatRijders = Array.isArray(r.heat_rijders) ? r.heat_rijders
                          : (r.heat_snrs || []).map(n => ({snr: parseInt(n), lic: null}));
        const mijnInHeat = heatRijders.filter(hr => hr.lic ? mijnLics.has(hr.lic) : mijnSnrs.has(hr.snr));
        huidigeGroepMijnCount += mijnInHeat.length;
        huidigeGroepAantalHeats += 1;
        if ((r.resultaten_count ?? 0) > 0) huidigeGroepMetRes += 1;
        if (r.definitief) huidigeGroepDefinitief += 1;

        const ritRaw = ritHtml(r);
        html += ritRaw.replace(/^<div /, `<div data-dag-nr="${dag}" `);
    });
    sluitAlles();

    // Post-fix: markers vervangen door status-icoon + mijn-badge met de
    // accurate waardes die we tijdens de loop hebben opgebouwd. Ook de
    // "mijn"-class op de groep-div bepalen op basis van p.count > 0.
    groepHdrPlaceholders.forEach((p, i) => {
        const iconMarker      = `[[STATUS-ICON-${i}]]`;
        const mijnMarker      = `[[MIJN-BADGE-${i}]]`;
        const mijnClassMarker = `[[MIJN-CLASS-${i}]]`;
        const iconHtml = p.statusIcon
            ? `<span title="${esc(t(p.statusKey))}">${p.statusIcon}</span>`
            : '';
        const mijnHtml = p.count > 0
            ? `<span class="prog-groep-mijn-badge" title="${esc(t('prog_klap_mijn_tooltip'))}">${p.count}</span>`
            : '';
        // .mijn (oranje strip links + zichtbare badge) per-groep via de index-
        // marker — betrouwbaar ook bij dubbele data-groep-key.
        html = html
            .replace(iconMarker, iconHtml)
            .replace(mijnMarker, mijnHtml)
            .replace(mijnClassMarker, p.count > 0 ? ' mijn' : '');
        if (p.count > 0) _progGroepenMetMijn.add(p.key);
    });
    progEl.innerHTML = html;
    // Bewaar alle groep-keys — bepaalt scope voor "Alles in/uit"-knoppen.
    _progGroepAlleKeys = groepHdrPlaceholders.map(p => p.key);
    // Bij eerste render: iedereen ingeklapt (default coach-UX).
    if (_progEersteRender) {
        _progGroepAlleKeys.forEach(k => _progIngeklapt.add(k));
        // Reflect DOM-state met _progIngeklapt.
        _progGroepAlleKeys.forEach(k => {
            const el = progEl.querySelector(`.prog-groep[data-groep-key="${CSS.escape(k)}"]`);
            if (el) el.classList.add('ingeklapt');
        });
        _progEersteRender = false;
    }
}

// Multi-day filter (Alle / Dag 1 / Dag 2 / …): toggle .verborgen op items
// met andere dag-nr. Geen re-render nodig — pure DOM-class toggle.
// Programma-rit-filter: toggle "alleen mijn rijders" of "alleen nog te
// rijden" via data-attributen op #programma. Onafhankelijk te combineren.
function filterProgRit(btn, filter) {
    const prog = document.getElementById('programma');
    if (!prog) return;
    const attr = filter === 'mijn' ? 'data-filter-mijn' : 'data-filter-gereden-uit';
    const actief = prog.getAttribute(attr) !== '1';
    prog.setAttribute(attr, actief ? '1' : '0');
    btn.classList.toggle('actief', actief);
    // Touch-fix: na een tap blijft :hover op mobiel hangen tot je ergens
    // anders tikt. Blur direct zodat de knop in z'n juiste rust- of
    // actief-state komt zonder lingering lichtblauwe hover.
    btn.blur();
}

// ── Programma filter-strook (dag + afstand) — identiek aan public ──────────
function togglePanel(triggerBtn) {
    const strook = triggerBtn.closest('.prog-filter-strook');
    if (!strook) return;
    const key = triggerBtn.dataset.filter;
    const panel = strook.querySelector(`.prog-filter-panel[data-panel="${key}"]`);
    if (!panel) return;
    const nuOpen = !panel.classList.contains('verborgen');
    strook.querySelectorAll('.prog-filter-panel').forEach(p => p.classList.add('verborgen'));
    strook.querySelectorAll('.prog-filter-trigger').forEach(t => t.classList.remove('open'));
    if (!nuOpen) {
        panel.classList.remove('verborgen');
        triggerBtn.classList.add('open');
    }
    triggerBtn.blur();
}
function kiesProgFilter(type, waarde, pillBtn) {
    const strook = pillBtn.closest('.prog-filter-strook');
    if (!strook) return;
    strook.setAttribute('data-actieve-' + type, String(waarde));
    const panel = strook.querySelector(`.prog-filter-panel[data-panel="${type}"]`);
    panel?.querySelectorAll('.prog-filter-pill').forEach(p =>
        p.classList.toggle('actief', p.dataset.value === String(waarde))
    );
    const trigger = strook.querySelector(`.prog-filter-trigger[data-filter="${type}"]`);
    const lblEl   = trigger?.querySelector('.prog-filter-lbl');
    if (lblEl) {
        if (waarde === 'alle') {
            lblEl.textContent = type === 'dag' ? t('prog_filter_alle_dagen') : t('prog_filter_alle_afstanden');
        } else if (type === 'dag') {
            const sub = pillBtn.querySelector('.prog-filter-pill-sub')?.textContent || '';
            lblEl.textContent = `${t('prog_dag')} ${waarde}${sub ? ' · ' + sub : ''}`;
        } else {
            lblEl.textContent = waarde;
        }
    }
    panel?.classList.add('verborgen');
    trigger?.classList.remove('open');
    if (type === 'dag') _refreshAfstandPanel(strook);
    applyProgFilter(strook);
    pillBtn.blur();
}
function _refreshAfstandPanel(strook) {
    const afsPanel = strook.querySelector('.prog-filter-panel[data-panel="afstand"]');
    if (!afsPanel) return;
    let perDag = {};
    try { perDag = JSON.parse(strook.dataset.afsPerDag || '{}'); } catch { perDag = {}; }
    const dag = strook.getAttribute('data-actieve-dag') || 'alle';
    const beschikbaar = new Set();
    if (dag === 'alle') {
        for (const arr of Object.values(perDag)) for (const a of arr) beschikbaar.add(a);
    } else {
        for (const a of (perDag[dag] || [])) beschikbaar.add(a);
    }
    let huidigeKeuzeNogGeldig = false;
    const huidigAfs = strook.getAttribute('data-actieve-afstand') || 'alle';
    afsPanel.querySelectorAll('.prog-filter-pill').forEach(p => {
        const v = p.dataset.value;
        if (v === 'alle') { p.classList.remove('verborgen'); return; }
        const zichtbaar = beschikbaar.has(v);
        p.classList.toggle('verborgen', !zichtbaar);
        if (v === huidigAfs && zichtbaar) huidigeKeuzeNogGeldig = true;
    });
    if (huidigAfs !== 'alle' && !huidigeKeuzeNogGeldig) {
        strook.setAttribute('data-actieve-afstand', 'alle');
        const trigger = strook.querySelector('.prog-filter-trigger[data-filter="afstand"]');
        const lblEl   = trigger?.querySelector('.prog-filter-lbl');
        if (lblEl) lblEl.textContent = t('prog_filter_alle_afstanden');
        afsPanel.querySelectorAll('.prog-filter-pill').forEach(p =>
            p.classList.toggle('actief', p.dataset.value === 'alle')
        );
    }
}
function applyProgFilter(strook) {
    const dag = strook.getAttribute('data-actieve-dag') || 'alle';
    const afs = strook.getAttribute('data-actieve-afstand') || 'alle';
    const container = strook.parentElement;
    if (!container) return;
    container.querySelectorAll('.prog-groep.samenvat').forEach(el => {
        el.classList.remove('samenvat');
        el.querySelector('.samenvat-teller')?.remove();
        const titel = el.querySelector('.prog-groep-titel');
        if (titel?.dataset.originalHtml) {
            titel.innerHTML = titel.dataset.originalHtml;
            delete titel.dataset.originalHtml;
        }
    });
    const eersteVanCombi = new Map();
    container.querySelectorAll('[data-dag-nr]').forEach(el => {
        if (el === strook || el.classList.contains('prog-filter-strook')) return;
        const elDag = el.getAttribute('data-dag-nr');
        const elAfs = el.getAttribute('data-afstand-key');
        const elRt  = el.getAttribute('data-ronde-type');
        const dagOk = (dag === 'alle') || (elDag === String(dag));
        if (!dagOk) { el.classList.add('verborgen'); return; }
        if (afs === 'alle' || !elAfs || elAfs === afs) {
            el.classList.remove('verborgen');
            return;
        }
        if (!el.classList.contains('prog-groep')) {
            el.classList.add('verborgen');
            return;
        }
        const combiKey = `${elAfs}|${elRt || ''}`;
        // Coach gebruikt .heat-rij voor de heat-rijen binnen de groep-body.
        const heats = el.querySelectorAll('.heat-rij').length;
        if (eersteVanCombi.has(combiKey)) {
            el.classList.add('verborgen');
            eersteVanCombi.get(combiKey).heats += heats;
        } else {
            el.classList.remove('verborgen');
            el.classList.add('samenvat');
            eersteVanCombi.set(combiKey, {el, heats});
        }
    });
    for (const {el, heats} of eersteVanCombi.values()) {
        const hdr = el.querySelector('.prog-groep-hdr');
        if (!hdr) continue;
        hdr.querySelector('.samenvat-teller')?.remove();
        // Titel vervangen: badge + afstand-naam (dc-naam is cat-specifiek,
        // samenvat is gecombineerd over alle cats van deze afstand+ronde).
        const titel = hdr.querySelector('.prog-groep-titel');
        const distNaam = el.getAttribute('data-afstand-key');
        if (titel && distNaam) {
            if (!titel.dataset.originalHtml) {
                titel.dataset.originalHtml = titel.innerHTML;
            }
            const badge = titel.querySelector('.heat-card-badge, .badge');
            titel.innerHTML = (badge ? badge.outerHTML : '') + ' ' + distNaam;
        }
        const span = document.createElement('span');
        span.className = 'samenvat-teller';
        const suffix = heats === 1 ? t('prog_samenvat_heat_1') : t('prog_samenvat_heat_n', {n: heats});
        span.textContent = ` · ${suffix}`;
        hdr.appendChild(span);
    }
}
// Legacy wrapper voor onclick="filterDag(...)"-plekken die er nog zijn.
function filterDag(btn, dag) {
    const strook = btn.closest('.prog-filter-strook, .prog-dag-filter');
    if (!strook) return;
    strook.setAttribute('data-actieve-dag', String(dag));
    applyProgFilter(strook);
}

// ── Coach-info (status + sancties) ───────────────────────────────────────────
async function laadCoachInfo() {
    if (!selComp.value || !coachLijst.length) { coachInfoCache = {}; return; }
    const licenses = coachLijst.map(p => p.license_key).filter(Boolean);
    if (!licenses.length) { coachInfoCache = {}; return; }
    try {
        const res = await coachFetch(`?action=coach_info`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ competition_id: selComp.value, licenses }),
        });
        const data = await res.json();
        const map = {};
        (data.personen || []).forEach(p => { map[p.license_key] = p; });
        coachInfoCache = map;
    } catch { /* stil falen — UI blijft werken zonder status */ }
}

const RONDE_VOLGORDE = ['heats','kwartfinale','halve_finale','runner_up','finale_b','finale_a'];

function renderHeats() {
    const el = $('heats');
    if (!coachLijst.length) {
        el.innerHTML = `<div class="leeg-melding">${t('heats_geen_rijders')}</div>`;
        return;
    }
    // Groepeer alle programma-ritten per DC om per DC te weten welke rondes er
    // bestaan (en per ronde de status van de heats).
    const rittenPerDc = {};
    for (const r of (programmaCache?.ritten || [])) {
        const dc = r.heat_dc_id;
        if (!dc) continue;
        if (!rittenPerDc[dc]) rittenPerDc[dc] = [];
        rittenPerDc[dc].push(r);
    }

    const gesorteerd = [...coachLijst].sort((a,b) => parseInt(a.snr) - parseInt(b.snr));
    el.innerHTML = gesorteerd.map(p => {
        const info    = coachInfoCache[p.license_key];
        const entries = info?.entries || [];
        const mijnHeats = info?.heats || [];

        if (!info) return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.8rem">${esc(p.category || '')}</span>
            </div>
            <div class="sanc-leeg">${t('uit_laden')}</div>
        </div>`;

        if (!entries.length) return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.8rem">${esc(p.category || '')}</span>
            </div>
            <div class="sanc-leeg">${t('heats_geen_inschrijvingen')}</div>
        </div>`;

        // Bouw een lijst met één blok per (DC × afstand). De afstanden komen
        // uit `entry.afstanden` (afgeleid van de `distances`-tabel per DC),
        // zodat óók afstanden zonder gegenereerd programma verschijnen
        // (bv. een lange afstand die nog geloot moet worden).
        const afstandBlokken = [];
        for (const e of entries) {
            const dcRitten = rittenPerDc[e.dc_id] || [];
            const afstanden = e.afstanden || [];
            const dcStatus  = parseInt(e.entry_status ?? 1);
            // Status 3 = afgemeld bij organisatie → rijder rijdt deze afstand
            // niet, heat-detail toont alleen ruis. De status-badge boven in
            // de samenvatting zegt al "Withdrawn by org.", dat is voldoende.
            if (dcStatus === 3) continue;
            if (!afstanden.length) {
                // Fallback: DC zonder distances — toon 1 wachtrij-blok
                afstandBlokken.push({
                    dc_id: e.dc_id, dc_naam: e.dc_naam, dc_status: dcStatus,
                    dc_number: e.dc_number ?? null,
                    distance_id: null, distance_naam: null, distance_number: null, ritten: [],
                });
                continue;
            }
            for (const a of afstanden) {
                const ritten = dcRitten.filter(r =>
                    String(r.rit_distance_id || r.heat_distance_id || '') === String(a.distance_id));
                afstandBlokken.push({
                    dc_id: e.dc_id,
                    dc_naam: e.dc_naam,
                    dc_status: dcStatus,
                    dc_number: e.dc_number ?? null,
                    distance_id: a.distance_id,
                    distance_naam: a.distance_naam,
                    distance_number: a.number ?? null,
                    ritten,
                    expected_rondes: a.expected_rondes || [],
                });
            }
        }

        // Sorteer alle afstand-blokken op programma-volgorde: de vroegste rit
        // (blok_volgorde, rit_volgorde) bepaalt de positie. Blokken zonder
        // geloot programma gaan achteraan maar onderling op dc_naam +
        // distance_naam zodat de volgorde stabiel en leesbaar is.
        // Sort op MIN rit_volgorde over de ritten in het afstand-blok —
        // pure rit_volgorde, geen blok_volgorde fallback want die verandert
        // niet bij cross-blok drag-drop in admin (zie public/index.php).
        const sortKey = b => {
            if (!b.ritten.length) return Infinity;
            let rvMin = Infinity;
            for (const r of b.ritten) {
                const rv = r.rit_volgorde ?? 9999;
                if (rv < rvMin) rvMin = rv;
            }
            return rvMin;
        };
        afstandBlokken.sort((x, y) => {
            const xr = sortKey(x), yr = sortKey(y);
            if (xr !== yr) return xr - yr;
            // Fallback voor blokken zonder ritten: gebruik DC- en distance-
            // number uit de KNSB-data — die volgorde benadert de typische
            // programma-volgorde (sprint vóór 1000m vóór puntenkoers, etc.)
            // veel beter dan alfabetisch sorteren op "1000m" / "200m DTT".
            const xdn = x.dc_number ?? 9999;
            const ydn = y.dc_number ?? 9999;
            if (xdn !== ydn) return xdn - ydn;
            const xa  = x.distance_number ?? 9999;
            const ya  = y.distance_number ?? 9999;
            if (xa !== ya) return xa - ya;
            // Allerlaatste fallback: alfabetisch op naam zodat sort stabiel is
            const a = `${x.dc_naam} ${x.distance_naam || ''}`;
            const b = `${y.dc_naam} ${y.distance_naam || ''}`;
            return a.localeCompare(b);
        });

        const dcBlokken = afstandBlokken.map(blok => {
            // Kop-tekst: "DJB — 500m" als er een afstand-naam is, anders alleen DC-naam
            const kop = blok.distance_naam
                ? `${esc(blok.dc_naam || t('heats_cat_fallback'))} — ${esc(blok.distance_naam)}`
                : esc(blok.dc_naam || t('heats_cat_fallback'));

            // Groepeer ritten per ronde_type binnen deze afstand
            const rondes = {};
            for (const r of blok.ritten) {
                const rt = r.ronde_type || 'heats';
                if (!rondes[rt]) rondes[rt] = [];
                rondes[rt].push(r);
            }
            // Vul aan met verwachte rondes uit cat_config (lege lijst als er
            // nog geen ritten zijn geloot); frontend toont die dan als
            // "⏳ Startlijst nog niet definitief".
            for (const rt of (blok.expected_rondes || [])) {
                if (!rondes[rt]) rondes[rt] = [];
            }
            const sortedRt = Object.keys(rondes).sort((a,b) => {
                const ia = RONDE_VOLGORDE.indexOf(a); const ib = RONDE_VOLGORDE.indexOf(b);
                return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
            });
            if (!sortedRt.length) {
                // Afstand bestaat wel maar cat_config heeft géén rondes én er zijn
                // geen ritten — dan tonen we één wacht-regel.
                const tekst = blok.distance_naam
                    ? t('heats_niet_geloot_geen_heats')
                    : t('heats_geen_programma');
                return `<div class="heat-toon-dc heat-toon-wachten">
                    <div class="heat-toon-dc-kop">${kop}</div>
                    <div class="heat-toon-rij heat-toon-wacht-rij">${tekst}</div>
                </div>`;
            }
            const rijen = sortedRt.map(rt => {
                const rittenVanRonde = rondes[rt];
                const badge = BADGE[rt]
                    ? `<span class="badge ${BADGE[rt]}">${getRondeLabel(rt)}</span>`
                    : '';
                // 1) Zit de rijder in een heat van deze ronde ÉN deze afstand?
                //    We matchen op dc_id (elke rit van deze ronde-groep heeft
                //    dezelfde dc_id), op distance_id, én op ronde_type.
                const dcIdVanRonde = rittenVanRonde[0]?.heat_dc_id;
                const mijn = mijnHeats.find(h =>
                    h.ronde_type === rt &&
                    h.dc_id === dcIdVanRonde &&
                    String(h.distance_id || '') === String(blok.distance_id || ''));
                if (mijn) {
                    // Vorige-ronde-compleet check: als de bron-ronde nog niet
                    // verwerkt is mag deze heat (KF/HF/Finale/Runner-up) nog
                    // niet als "ingedeeld" getoond worden — anders zou een
                    // coach al kunnen denken "mijn rijder zit in heat 2 KF"
                    // terwijl de series nog niet definitief klaar zijn.
                    if (mijn.vorige_niet_compleet) {
                        return `<div class="heat-toon-rij heat-toon-wacht-rij">${badge}
                            <span>${t('heats_vorige_niet_compleet')}</span>
                        </div>`;
                    }
                    const rit = rittenVanRonde.find(r => String(r.heat_id) === String(mijn.heat_id));
                    const heatNr = rit?.heat_nr ?? mijn.ronde;
                    return `<div class="heat-toon-rij">${badge}
                        <span><b>${t('heats_heat')} ${esc(heatNr ?? '?')}</b></span>
                        <small style="color:#666">${t('heats_startpos', {pos: esc(mijn.startpositie)})}</small>
                    </div>`;
                }
                // 2) Niet geplaatst: drie sub-scenarios
                const definitief = rittenVanRonde.some(r => r.definitief);
                const heeftHeats = rittenVanRonde.some(r => r.heat_id);
                if (definitief) {
                    return `<div class="heat-toon-rij heat-toon-niet-geplaatst">${badge}
                        <span>${t('heats_niet_geplaatst')}</span>
                    </div>`;
                }
                if (heeftHeats) {
                    // Heats bestaan maar zijn niet definitief → vorige ronde
                    // is er wel maar nog niet kompleet ingevoerd.
                    return `<div class="heat-toon-rij heat-toon-wacht-rij">${badge}
                        <span>${t('heats_vorige_niet_compleet')}</span>
                    </div>`;
                }
                // Geen heats voor deze ronde → loting moet nog plaatsvinden.
                return `<div class="heat-toon-rij heat-toon-wacht-rij">${badge}
                    <span>${t('heats_niet_geloot')}</span>
                </div>`;
            }).join('');
            return `<div class="heat-toon-dc">
                <div class="heat-toon-dc-kop">${kop}</div>
                ${rijen}
            </div>`;
        }).join('');

        // Samenvatting per DC: één rij per ingeschreven DC. De afmelding/
        // bevestiging gebeurt op DC-niveau, dus we tonen ook 1 badge per DC.
        // Heeft een DC meerdere afstanden (bv. "200m · 500m" combi) dan
        // staan die in dezelfde rij gescheiden door · zodat de coach ziet
        // wat erbij hoort, zonder dat de badge dubbel lijkt.
        const samenvatRijen = entries.map(e => {
            const st      = parseInt(e.entry_status ?? 1);
            const stLabel = getStatusLabel(st) || '?';
            const stIco   = STATUS_ICON[st] ?? '';
            const afstanden = e.afstanden || [];
            const naam = afstanden.length
                ? afstanden.map(a => a.distance_naam || t('heats_afstand_fallback')).join(' · ')
                : (e.dc_naam || t('heats_cat_fallback'));
            return `
                <div class="sanc-samenvat-rij">
                    <span class="sanc-samenvat-naam">${esc(naam)}</span>
                    <span class="status-badge status-${st}">${stIco} ${esc(stLabel)}</span>
                </div>`;
        }).join('');

        return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.85rem">${esc(p.category || '')}</span>
            </div>
            ${samenvatRijen ? `<div class="sanc-samenvat">${samenvatRijen}</div>` : ''}
            ${dcBlokken}
        </div>`;
    }).join('');
}

function renderSancties() {
    const el = $('sancties');
    if (!coachLijst.length) {
        el.innerHTML = `<div class="leeg-melding">${t('sanc_geen_rijders')}</div>`;
        return;
    }
    const gesorteerd = [...coachLijst].sort((a,b) => parseInt(a.snr) - parseInt(b.snr));
    el.innerHTML = gesorteerd.map(p => {
        const info = coachInfoCache[p.license_key];
        // Multi-sanctie: s.sanctie kan bv. 'W1,W2,DQ-SF' zijn. Toon elke code
        // als eigen badge met uitleg zodat coach precies ziet wat er speelde
        // in die rit (en niet één samengeplakte string zonder verklaring).
        const sanctieRijen = (info?.sancties || []).map(s => {
            const codes  = String(s.sanctie || '').split(',').map(c => c.trim()).filter(Boolean);
            const badges = codes.map(c => {
                const uitleg = getSanctieUitleg(c);
                return `<span class="sanc-rij-code" title="${esc(uitleg)}">${esc(c)}</span>`
                    + (uitleg ? ` <small>— ${esc(uitleg)}</small>` : '');
            }).join(' &nbsp; ');
            return `<div class="sanc-rij">
                ${badges}
                · ${esc(s.rit_naam ?? '')}
                ${s.afstand_naam ? ` · ${esc(s.afstand_naam)}` : ''}
            </div>`;
        }).join('');
        return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.8rem">${esc(p.category || '')}</span>
            </div>
            ${sanctieRijen || `<div class="sanc-leeg">${t('sanc_geen')}</div>`}
        </div>`;
    }).join('');
}

// ── Uitslagen-tab ────────────────────────────────────────────────────────────
// Twee dropdowns: eerst categorie (DP4/DP3/…), dan afstand + eventueel
// klassement. Zelfde bron als de Rondes-tab (_catsMetAfstanden via
// /rondes_cats endpoint), zodat de UX consistent is en gecombineerde DC's
// niet als "DP4+DP3" in de UI opduiken.

async function laadUitslagenCategorieen() {
    const sel = $('u-sel-cat');
    sel.innerHTML = `<option value="">${t('uitsl_opt_laden')}</option>`;
    if (!selComp.value) return;
    if (!_catsMetAfstanden.length) await _laadCatsMetAfstanden();
    if (!_catsMetAfstanden.length) {
        sel.innerHTML = `<option value="">${t('uitsl_opt_geen_uitslagen')}</option>`;
        $('uitslagen').innerHTML = `<div class="leeg-melding">${t('uit_leeg')}</div>`;
        $('u-afstand-rij').style.display = 'none';
        return;
    }
    // Cat-dropdown op cat-signatuur (bv "HJA+HSA"). Meerdere DC's met
    // dezelfde cats zijn samengevoegd tot 1 optie; afstanden weten hun
    // eigen dc_id voor de fetch.
    sel.innerHTML = `<option value="">${t('uitsl_opt_kies_cat')}</option>` +
        _catsMetAfstanden.map(c => `<option value="${esc(c.sig)}">${esc(c.label)}</option>`).join('');
}

function opCatChange() {
    const sig = $('u-sel-cat').value;
    const afstRij = $('u-afstand-rij');
    const selAf   = $('u-sel-afstand');
    const uit     = $('uitslagen');
    uit.innerHTML = '';
    if (!sig) { afstRij.style.display = 'none'; return; }
    const catObj = _catsMetAfstanden.find(c => c.sig === sig);
    if (!catObj) return;
    // Value-format (dc_id nu weer per afstand nodig, want signatuur kan
    // meerdere DC's overspannen):
    //   afstand: "afstand|<dc_id>|<distance_id>"
    //   klassement: "klassement|<dc_id>"
    const afstOpts = catObj.afstanden.map(a =>
        `<option value="afstand|${esc(a.dc_id)}|${esc(a.distance_id)}">${esc(a.distance_naam || t('uitsl_opt_afstand_fallback'))}</option>`
    );
    const klas = catObj.klassementen || [];
    const klasOpts = klas.map(k => {
        const suffix = klas.length > 1 ? ` (${esc(k.dc_naam)})` : '';
        return `<option value="klassement|${esc(k.dc_id)}">${t('uitsl_klassement_opt')}${suffix}</option>`;
    });
    selAf.innerHTML = [
        `<option value="">${t('uitsl_opt_kies_afstand_of_klassement')}</option>`,
        ...afstOpts,
        ...klasOpts,
    ].join('');
    afstRij.style.display = 'flex';
}

async function opAfstandChange() {
    const afVal = $('u-sel-afstand').value;
    const uit = $('uitslagen');
    if (!afVal) { uit.innerHTML = ''; return; }
    const parts = afVal.split('|');
    const type = parts[0];
    const dcId   = parts[1] || '';
    const distId = type === 'afstand' ? (parts[2] || '') : '';
    if (!dcId) { uit.innerHTML = ''; return; }
    uit.innerHTML = `<div class="leeg-melding"><span class="spinner"></span> ${t('uit_laden')}</div>`;
    try {
        const url = `?action=uitslagen&competition_id=${encodeURIComponent(selComp.value)}&dc_id=${encodeURIComponent(dcId)}&type=${encodeURIComponent(type)}${distId ? '&distance_id=' + encodeURIComponent(distId) : ''}&_t=${Date.now()}`;
        const res = await safeFetch(url);
        const data = await res.json();
        if (data.error) { uit.innerHTML = `<div class="leeg-melding">⚠ ${esc(data.error)}</div>`; return; }
        uit.innerHTML = (type === 'klassement') ? renderKlassementTabel(data) : renderAfstandTabel(data);
    } catch (e) {
        uit.innerHTML = `<div class="leeg-melding">${t('uit_fout', {msg: esc(e.message)})}</div>`;
    }
}

function sl(s) { return s ?? ''; }

// ── Rondes-tab ─────────────────────────────────────────────────────────────
// Twee dropdowns: eerst categorie (DP4/DP3/…), dan afstand. Anders dan de
// Uitslagen-tab die op DC-naam werkt ("DP4+DP3" bij gecombineerde ritten).
// Bron: /rondes_cats endpoint dat per persoons-categorie de afstanden geeft.
// Renderer haalt via ?action=ronde_uitslagen alle rondes van de gekozen DC
// op (ZONDER license_key — coach wil alle heats zien) en filtert client-side
// op distance_id zodat je precies één afstand ziet.
// Gedeelde state voor Rondes- en Uitslagen-tab: categorieën met daarbinnen
// de afstanden (met dc_id) en gepubliceerde klassementen. Één fetch, twee
// dropdowns die er hun opties uit halen.
let _catsMetAfstanden = []; // [{categorie, afstanden:[{distance_id, distance_naam, dc_id}], klassementen:[{dc_id, dc_naam}]}]

async function _laadCatsMetAfstanden() {
    if (!selComp.value) return;
    try {
        const res = await safeFetch(`?action=rondes_cats&competition_id=${encodeURIComponent(selComp.value)}&_t=${Date.now()}`);
        const data = await res.json();
        _catsMetAfstanden = Array.isArray(data) ? data : [];
    } catch (e) {
        _catsMetAfstanden = [];
    }
    initRondesCatDropdown();
}

function initRondesCatDropdown() {
    const sel = $('r-sel-cat');
    if (!sel) return;
    const huidig = sel.value;
    if (!_catsMetAfstanden.length) {
        sel.innerHTML = `<option value="">${t('uitsl_opt_geen_uitslagen')}</option>`;
        return;
    }
    sel.innerHTML = `<option value="">${t('rondes_opt_kies_cat')}</option>` +
        _catsMetAfstanden.map(c => `<option value="${esc(c.sig)}">${esc(c.label)}</option>`).join('');
    // Restore na auto-refresh
    if (huidig && sel.querySelector(`option[value="${CSS.escape(huidig)}"]`)) {
        sel.value = huidig;
    }
}

async function laadRondesCategorieen() {
    await _laadCatsMetAfstanden();
    const cat = $('r-sel-cat').value;
    const c = $('rondes-inhoud');
    if (!cat) {
        c.innerHTML = `<div class="leeg-melding">${t('rondes_kies_cat_hint')}</div>`;
        $('r-afstand-rij').style.display = 'none';
        return;
    }
    // Cat is nog geselecteerd (auto-refresh) → vul afstand + re-render
    opRondeCatChange();
    const afVal = $('r-sel-afstand').value;
    if (afVal) opRondeAfstandChange();
}

function opRondeCatChange() {
    const sig = $('r-sel-cat').value;
    const afstRij = $('r-afstand-rij');
    const selAf   = $('r-sel-afstand');
    const c       = $('rondes-inhoud');
    if (!sig) {
        afstRij.style.display = 'none';
        c.innerHTML = `<div class="leeg-melding">${t('rondes_kies_cat_hint')}</div>`;
        return;
    }
    const catObj = _catsMetAfstanden.find(x => x.sig === sig);
    if (!catObj) { afstRij.style.display = 'none'; c.innerHTML = ''; return; }
    const huidig = selAf.value;
    // Value = "dcId|distanceId" — afstand hoort bij een specifieke DC binnen
    // deze signatuur (bij samengevoegde DC's zijn afstanden verdeeld).
    selAf.innerHTML = `<option value="">${t('rondes_opt_kies_afstand')}</option>` +
        catObj.afstanden.map(a =>
            `<option value="${esc(a.dc_id)}|${esc(a.distance_id)}">${esc(a.distance_naam)}</option>`
        ).join('');
    afstRij.style.display = 'flex';
    if (catObj.afstanden.length === 1) {
        const a = catObj.afstanden[0];
        selAf.value = `${a.dc_id}|${a.distance_id}`;
        opRondeAfstandChange();
    } else if (huidig && selAf.querySelector(`option[value="${CSS.escape(huidig)}"]`)) {
        selAf.value = huidig;
        opRondeAfstandChange();
    } else {
        c.innerHTML = `<div class="leeg-melding">${t('rondes_kies_afstand_hint')}</div>`;
    }
}

async function opRondeAfstandChange() {
    const afVal = $('r-sel-afstand').value;
    const c = $('rondes-inhoud');
    if (!afVal || !selComp.value) { c.innerHTML = ''; return; }
    const [dcId, distId] = afVal.split('|');
    if (!dcId) { c.innerHTML = ''; return; }
    await renderRondesVoorDc(dcId, distId);
}

async function renderRondesVoorDc(dcId, distIdFilter) {
    const c = $('rondes-inhoud');
    if (!dcId || !selComp.value) { c.innerHTML = ''; return; }
    c.innerHTML = `<div class="leeg-melding"><span class="spinner"></span> ${t('uit_laden')}</div>`;
    // Geen cat-filter: gecombineerde heats (bv HJA+HSA samen op 500m) zijn
    // de wedstrijdrealiteit — Q/q en volgorde slaan op de gehele heat.
    try {
        const url = `?action=ronde_uitslagen&competition_id=${encodeURIComponent(selComp.value)}&dc_id=${encodeURIComponent(dcId)}&_t=${Date.now()}`;
        const res = await safeFetch(url);
        const data = await res.json();
        if (data.error) { c.innerHTML = `<div class="leeg-melding">⚠ ${esc(data.error)}</div>`; return; }
        const distances = Array.isArray(data.distances) ? data.distances : [];
        if (!distances.length) {
            c.innerHTML = `<div class="leeg-melding">${t('rondes_geen_rondes')}</div>`;
            return;
        }
        // Set voor .mijn-highlight — license_key is uniek per rijder.
        const mijnLics = new Set(coachLijst.map(p => p.license_key).filter(Boolean));
        // Labels per ronde_type via i18n zodat het meebeweegt met taalkeuze.
        const RONDE_LABEL = {
            heats:         t('rondes_ronde_serie'),
            kwartfinale:   t('rondes_ronde_kwartfinale'),
            halve_finale:  t('rondes_ronde_halve_finale'),
            runner_up:     t('rondes_ronde_runner_up'),
            finale_a:      t('rondes_ronde_finale_a'),
            finale_b:      t('rondes_ronde_finale_b'),
        };
        let html = '';
        // Filter op gekozen afstand — endpoint returnt alle distances van de DC,
        // maar user heeft één specifieke gekozen (cat → afstand).
        const zichtbaar = distIdFilter
            ? distances.filter(d => String(d.distance_id) === String(distIdFilter))
            : distances;
        if (!zichtbaar.length) {
            c.innerHTML = `<div class="leeg-melding">${t('rondes_geen_rondes')}</div>`;
            return;
        }
        for (const d of zichtbaar) {
            html += `<div class="rondeu-afstand">
                <div class="rondeu-afstand-titel">${esc(d.distance_naam)}</div>`;
            if (!d.rondes.length) {
                html += `<div class="leeg-melding">${t('rondes_geen_rondes')}</div>`;
            }
            for (const r of d.rondes) {
                // finale_b: backend-label wint (is systeem-aware — 'Kleine finale'
                // bij internationaal-nieuw, 'B-finale' bij full-final). Andere
                // rondes gebruiken de i18n-vertaling.
                const label = (r.ronde_type === 'finale_b' && r.ronde_label)
                    ? r.ronde_label
                    : (RONDE_LABEL[r.ronde_type] || r.ronde_label || r.ronde_type);
                html += `<div class="rondeu-ronde ${r.compleet ? '' : 'pending'}">
                    <div class="rondeu-ronde-titel">
                        <span class="rondeu-badge badge-${r.ronde_type}">${esc(label)}</span>
                        ${r.compleet ? '' : `<span class="rondeu-pending">${esc(t('rondes_pending'))}</span>`}
                    </div>`;
                if (r.compleet && r.rijders.length) {
                    // Kolom-decisies: bij puntenkoers/afvalkoers/inline weegt
                    // rondes + punten zwaarder dan tijd. Fin altijd als er data is.
                    const isLangeAfstand = ['puntenkoers','afvalkoers','inline'].includes(d.race_type);
                    const heeftFin      = r.rijders.some(x => x.finishpositie != null);
                    const heeftRondes   = isLangeAfstand && r.rijders.some(x => x.rondes != null);
                    const heeftPkPunten = d.race_type === 'puntenkoers' && r.rijders.some(x => x.pk_punten != null);
                    // Sorteer: Q eerst op tijd, dan q, dan rest. Runner-up op ru_positie.
                    // B-finale gescheiden per heat (B1 fin 1..n, dan B2, etc).
                    const rijders = [...r.rijders];
                    if (r.ronde_type === 'runner_up') {
                        rijders.sort((a, b) => (a.ru_positie ?? 999) - (b.ru_positie ?? 999));
                    } else if (r.ronde_type === 'finale_b') {
                        rijders.sort((a, b) => {
                            const ha = a.heat_nr ?? 999, hb = b.heat_nr ?? 999;
                            if (ha !== hb) return ha - hb;
                            const fa = a.finishpositie ?? 999;
                            const fb = b.finishpositie ?? 999;
                            if (fa !== fb) return fa - fb;
                            return (a.tijd_ms ?? 999999999) - (b.tijd_ms ?? 999999999);
                        });
                    } else {
                        // Volgorde binnen een ronde:
                        //   1. Q's op tijd (snelste eerst)
                        //   2. q's op tijd
                        //   3. Overige rijders — bij doorstroom-rondes
                        //      (heats/KF/HF) puur op tijd (fin uit verschillende
                        //      heats is niet vergelijkbaar); bij finale_a is fin
                        //      officiële ranking, tijd = tiebreaker.
                        //   4. Uitvallers (DNS/DNF/DQ-*) altijd onderaan.
                        const _ord = x => x.kwal === 'Q' ? 0 : x.kwal === 'q' ? 1 : 2;
                        const _uitvalCodes = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF'];
                        const _isUit = x => {
                            const s = String(x.sanctie || '').toUpperCase().split(/[,\s]+/);
                            return _uitvalCodes.some(c => s.includes(c));
                        };
                        // A-finale sortering volgt finale_ranking uit admin's
                        // Uitslag-module — alleen voor sprint-afstanden:
                        //   'time'          → puur op tijd (200m DTT)
                        //   'position_time' → finishpositie, tijd tiebreak
                        // Voor lange afstanden (puntenkoers/afvalkoers/inline)
                        // altijd finishpositie leidend — admin's finishpositie
                        // is daar al met punten en rondes berekend.
                        const _finaleFin = r.ronde_type === 'finale_a'
                                           && (isLangeAfstand || d.finale_ranking !== 'time');
                        rijders.sort((a, b) => {
                            const ua = _isUit(a), ub = _isUit(b);
                            if (ua !== ub) return ua ? 1 : -1;
                            const oa = _ord(a), ob = _ord(b);
                            if (oa !== ob) return oa - ob;
                            if (oa === 2 && _finaleFin) {
                                const fa = a.finishpositie ?? 999;
                                const fb = b.finishpositie ?? 999;
                                if (fa !== fb) return fa - fb;
                            }
                            return (a.tijd_ms ?? 999999999) - (b.tijd_ms ?? 999999999);
                        });
                    }
                    html += `<table class="rondeu-tabel">
                        <thead><tr>
                            ${r.ronde_type === 'runner_up' ? `<th class="c">${esc(t('rondes_col_pos'))}</th>` : ''}
                            <th class="c">${esc(t('rondes_col_snr'))}</th>
                            <th>${esc(t('rondes_col_naam'))}</th>
                            <th class="c">${esc(t('rondes_col_kwal'))}</th>
                            ${heeftRondes   ? `<th class="c">${esc(t('rondes_col_rondes'))}</th>` : ''}
                            ${heeftPkPunten ? `<th class="c">${esc(t('rondes_col_pkpt'))}</th>`   : ''}
                            <th class="c">${esc(t('rondes_col_tijd'))}</th>
                            <th class="c">${esc(t('rondes_col_sanctie'))}</th>
                            ${heeftFin      ? `<th class="c">${esc(t('rondes_col_fin'))}</th>`    : ''}
                        </tr></thead>
                        <tbody>`;
                    let vorigHeatNr = null;
                    for (const rr of rijders) {
                        // Sub-header bij B-finale / Runner-up bij heat-wissel.
                        if ((r.ronde_type === 'finale_b' || r.ronde_type === 'runner_up')
                            && rr.heat_nr !== vorigHeatNr) {
                            const prefix = r.ronde_type === 'finale_b' ? 'B' : 'RU';
                            const nr = rr.heat_nr ?? '?';
                            html += `<tr class="rondeu-heat-sub"><td colspan="99">${esc(prefix + nr)}</td></tr>`;
                            vorigHeatNr = rr.heat_nr;
                        }
                        const isMij = rr.person_license && mijnLics.has(rr.person_license);
                        // Q/q + doorstroom-label (A / B1 / RU1 / …).
                        const dsSuffix = rr.doorstroom_label
                            ? `<span style="color:#666;font-weight:600">→${esc(rr.doorstroom_label)}</span>` : '';
                        const kwalHtml = rr.kwal === 'Q'
                            ? `<b style="color:#198754">Q</b>${dsSuffix}`
                            : rr.kwal === 'q' ? `<b style="color:#0d6efd">q</b>${dsSuffix}`
                            : dsSuffix;
                        const tijdStr = rr.tijd_ms != null ? msTijd(rr.tijd_ms) : '—';
                        const sanctieStr = rr.sanctie || '';
                        // Non-finisher krijgt geen Fin-getal, ook al staat 'ie in DB.
                        const sanctieCodes = String(sanctieStr).toUpperCase().split(/[,\s]+/);
                        const isNonFin = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF'].some(c => sanctieCodes.includes(c));
                        const finVal = (isNonFin || rr.finishpositie == null) ? '' : rr.finishpositie;
                        html += `<tr${isMij ? ' class="mijn"' : ''}>
                            ${r.ronde_type === 'runner_up' ? `<td class="c" style="font-weight:700;color:#1a3a5c">${rr.ru_positie ?? '—'}</td>` : ''}
                            <td class="c" style="font-weight:600">${esc(rr.snr ?? '')}</td>
                            <td>${esc(rr.full_name)}</td>
                            <td class="c">${kwalHtml}</td>
                            ${heeftRondes   ? `<td class="c">${rr.rondes ?? '—'}</td>` : ''}
                            ${heeftPkPunten ? `<td class="c" style="font-weight:600">${rr.pk_punten ?? '—'}</td>` : ''}
                            <td class="col-tijd">${esc(tijdStr)}</td>
                            <td class="c" style="color:#c00;font-weight:600">${esc(sanctieStr)}</td>
                            ${heeftFin      ? `<td class="c" style="font-weight:700;color:#1a3a5c">${esc(String(finVal))}</td>` : ''}
                        </tr>`;
                    }
                    html += `</tbody></table>`;
                }
                html += `</div>`;
            }
            html += `</div>`;
        }
        c.innerHTML = html;
    } catch (e) {
        c.innerHTML = `<div class="leeg-melding">${t('uit_fout', {msg: esc(e.message)})}</div>`;
    }
}

// JS-equivalent van backend catSortKey — jong → oud, dames → heren.
function _catSortKey(cat) {
    const c = (cat || '').toUpperCase().trim();
    const mMasters = c.match(/^([HD]?)M(\d{2,3})$/);
    if (mMasters) {
        const g = mMasters[1] === 'D' ? 0 : 1;
        const lft = parseInt(mMasters[2], 10);
        if (lft >= 40) return (10 + Math.floor((lft - 40) / 5)) * 10 + g;
    }
    const g = c[0] === 'D' ? 0 : c[0] === 'H' ? 1 : 9;
    const sub = c.slice(1);
    const ageMap = { P4:0, P3:1, P2:2, P1:3, KA:4, JB:5, JA:6, SJ:7, SA:8, SB:9 };
    const a = ageMap[sub] ?? 99;
    return a * 10 + g;
}

// Per-cat rang berekenen — bij combi-DC (bv HJA+HSA) zie je zowel de
// gecombineerde positie als de plek binnen je eigen cat. Uitvallers
// (rang===null) krijgen geen cat-rang.
function _catRanksBerekenen(rijders) {
    const cats = [];
    const catRank = new Map();
    const teller = {};
    rijders.forEach((r, idx) => {
        const c = r.categorie || '';
        if (!c) { catRank.set(idx, null); return; }
        if (!cats.includes(c)) cats.push(c);
        if (r.rang == null) { catRank.set(idx, null); return; }
        teller[c] = (teller[c] || 0) + 1;
        catRank.set(idx, teller[c]);
    });
    cats.sort((a, b) => _catSortKey(a) - _catSortKey(b));
    return { cats, catRank };
}

function renderAfstandTabel(data) {
    if (!data.rijders?.length) return `<div class="leeg-melding">${t('uit_geen_uitslagen')}</div>`;
    // Match per persoon (license_key uniek) ipv per snr — twee rijders met
    // hetzelfde startnummer worden anders allebei gehighlight.
    const mijnLics = new Set(coachLijst.map(p => p.license_key));
    const mijnSnrs = new Set(coachLijst.map(p => parseInt(p.snr)));
    const heeftRnd = data.heeft_rondes, heeftPK = data.heeft_pk_punten;
    const { cats, catRank } = _catRanksBerekenen(data.rijders);
    const toonCatKol = cats.length > 1;
    let hdr = `<th class="col-rang">${t('col_rang')}</th>`;
    if (toonCatKol) for (const c of cats) hdr += `<th class="col-cat-rank" title="${esc(c)}">${esc(c)}</th>`;
    hdr += `<th class="col-snr">${t('col_snr')}</th><th>${t('col_naam')}</th>`;
    if (heeftRnd) hdr += `<th class="col-rnd">${t('col_rnd')}</th>`;
    if (heeftPK)  hdr += `<th class="col-pk">${t('col_pnt')}</th>`;
    hdr += `<th class="col-tijd">${t('col_tijd')}</th>`;
    let rows = '';
    data.rijders.forEach((r, idx) => {
        const isMij = r.lic ? mijnLics.has(r.lic) : mijnSnrs.has(parseInt(r.snr));
        const sanctie = sl(r.sanctie);
        rows += `<tr class="${isMij ? 'mijn' : ''}">
            <td class="col-rang">${r.rang ?? '—'}</td>`;
        if (toonCatKol) {
            const cr = catRank.get(idx);
            for (const c of cats) {
                rows += `<td class="col-cat-rank">${(c === r.categorie && cr != null) ? cr : ''}</td>`;
            }
        }
        rows += `<td class="col-snr">${esc(r.snr)}</td>
            <td>${esc(r.full_name)}${sanctie ? ` <span class="col-sanctie">${esc(sanctie)}</span>` : ''}</td>`;
        if (heeftRnd) rows += `<td class="col-rnd">${r.rondes ?? ''}</td>`;
        if (heeftPK)  rows += `<td class="col-pk">${r.pk_punten != null ? parseFloat(r.pk_punten) : ''}</td>`;
        rows += `<td class="col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : ''}</td>`;
        rows += '</tr>';
    });
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderKlassementTabel(data) {
    if (!data.rijders?.length) return `<div class="leeg-melding">${t('uit_geen_klassement')}</div>`;
    const mijnLics = new Set(coachLijst.map(p => p.license_key));
    const mijnSnrs = new Set(coachLijst.map(p => parseInt(p.snr)));
    const afstanden = data.afstanden ?? [];
    const { cats, catRank } = _catRanksBerekenen(data.rijders);
    const toonCatKol = cats.length > 1;
    let hdr = `<th class="col-rang">${t('col_rang')}</th>`;
    if (toonCatKol) for (const c of cats) hdr += `<th class="col-cat-rank" title="${esc(c)}">${esc(c)}</th>`;
    hdr += `<th class="col-snr">${t('col_snr')}</th><th>${t('col_naam')}</th>`;
    for (const a of afstanden) {
        const kort = a.length > 6 ? a.substring(0,5) + '.' : a;
        hdr += `<th class="col-punten" title="${esc(a)}">${esc(kort)}</th>`;
    }
    hdr += `<th class="col-totaal">${t('col_tot')}</th>`;
    let rows = '';
    data.rijders.forEach((r, idx) => {
        const isMij = r.lic ? mijnLics.has(r.lic) : mijnSnrs.has(parseInt(r.snr));
        const detail = r.punten_detail ?? {};
        rows += `<tr class="${isMij ? 'mijn' : ''}">
            <td class="col-rang">${r.rang ?? '—'}</td>`;
        if (toonCatKol) {
            const cr = catRank.get(idx);
            for (const c of cats) {
                rows += `<td class="col-cat-rank">${(c === r.categorie && cr != null) ? cr : ''}</td>`;
            }
        }
        rows += `<td class="col-snr">${esc(r.snr)}</td>
            <td>${esc(r.full_name)}</td>`;
        for (const a of afstanden) {
            const p = detail[a];
            rows += `<td class="col-punten">${p != null ? parseFloat(p) : '—'}</td>`;
        }
        rows += `<td class="col-totaal">${r.punten_totaal != null ? parseFloat(r.punten_totaal) : '—'}</td>`;
        rows += '</tr>';
    });
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

// Sorteer heat-rijders — startvolgorde vóór de rit (loting), finish erna.
// Bewust versimpeld voor de rit-modal: alle non-finishers (DNF/DNS/DQ-*) worden
// gelijk behandeld, onderaan op startnummer. Rit-rang (Fin-kolom) is al leeg
// voor niet-finishers. De KNSB-rang met ronde-context zit in de Uitslag-tab.
// Zie public/index.php voor volledige rationale.
function _sorteerHeatRijders(rijders) {
    if (!Array.isArray(rijders) || rijders.length < 2) return rijders;
    const heeftFinishData = rijders.some(r =>
        r.finishpositie != null || r.tijd_ms != null || (r.sanctie || '').trim() !== ''
    );
    if (!heeftFinishData) return rijders;
    const _isFinisher = (r) => {
        const s = String(r.sanctie || '').trim().toUpperCase();
        const heeft = code => s.split(/[,\s]+/).some(x => x === code);
        if (heeft('DNS') || heeft('DNF')
            || heeft('DQ-TF') || heeft('DQ-SF') || heeft('DQ-DF')) return false;
        return r.finishpositie != null || r.tijd_ms != null;
    };
    // Non-finishers oplopende ernst: DNF < DQ-TF < DNS < DQ-SF < DQ-DF.
    // DNS is bewust niet-starten (ernstiger dan pech-DNF); DQ-DF is de
    // zwaarste disciplinaire sanctie. Zie public/index.php voor rationale.
    const _ernst = (r) => {
        const s = String(r.sanctie || '').toUpperCase();
        const heeft = code => s.split(/[,\s]+/).some(x => x === code);
        if (heeft('DQ-DF')) return 5;
        if (heeft('DQ-SF')) return 4;
        if (heeft('DNS'))   return 3;
        if (heeft('DQ-TF')) return 2;
        if (heeft('DNF'))   return 1;
        return 0;
    };
    return [...rijders].sort((a, b) => {
        const fa = _isFinisher(a); const fb = _isFinisher(b);
        if (fa !== fb) return fa ? -1 : 1;
        if (fa) {
            const pa = a.finishpositie ?? Infinity;
            const pb = b.finishpositie ?? Infinity;
            if (pa !== pb) return pa - pb;
            const ta = a.tijd_ms ?? Infinity;
            const tb = b.tijd_ms ?? Infinity;
            if (ta !== tb) return ta - tb;
            return (a.startpositie ?? 999) - (b.startpositie ?? 999);
        }
        const ea = _ernst(a), eb = _ernst(b);
        if (ea !== eb) return ea - eb;
        const sa = parseInt(a.snr) || 99999;
        const sb = parseInt(b.snr) || 99999;
        return sa - sb;
    });
}

async function toonRitDetail(el) {
    const ritNaam = el.dataset.ritNaam;
    const dcNaam  = el.dataset.dcNaam;
    const compId  = selComp.value;
    if (!ritNaam || !compId) return;

    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = `<div class="overlay-box"><div style="text-align:center;padding:24px"><span class="spinner"></span> ${t('uit_laden')}</div></div>`;
    overlay.onclick = e => { if (e.target === overlay) overlay.remove(); };
    document.body.appendChild(overlay);

    try {
        const res = await safeFetch(`?action=rit_detail&competition_id=${encodeURIComponent(compId)}&rit_naam=${encodeURIComponent(ritNaam)}&dc_naam=${encodeURIComponent(dcNaam)}`);
        const data = await res.json();
        const heat = data.heat;
        if (!heat || !heat.rijders?.length) {
            overlay.querySelector('.overlay-box').innerHTML =
                `<div class="heat-card-titel">
                    <button class="overlay-sluit" onclick="this.closest('.overlay').remove()" title="${t('pwa_btn_sluit')}">&times;</button>
                    ${esc(ritNaam)}
                 </div>
                 <div class="leeg-melding" style="padding:24px;text-align:center;color:#888">${t('prog_startlijst_nb')}</div>`;
            return;
        }
        // Match op license_key (uniek), fallback op snr. Bij twee rijders
        // met zelfde nummer worden anders beide gehighlight.
        const mijnLics = new Set(coachLijst.map(p => p.license_key));
        const mijnSnrs = new Set(coachLijst.map(p => parseInt(p.snr)));
        // Sorteren: startvolgorde vóór de rit (loting), finishvolgorde erna.
        // Detect op "iemand heeft finishpositie/tijd/sanctie". Sancties:
        // DQ-TF/SF na finishers, DNF/DQ-DF daaronder, DNS onderaan.
        heat.rijders = _sorteerHeatRijders(heat.rijders);
        const heeftRnd = heat.rijders.some(r => r.rondes != null);
        const heeftPK  = heat.rijders.some(r => r.pk_punten != null);
        const rijen = heat.rijders.map(r => {
            const isMij = r.license_key
                ? mijnLics.has(r.license_key)
                : mijnSnrs.has(parseInt(r.snr));
            const sanctie = r.sanctie ? ` <span style="color:#c00;font-weight:600;font-size:.85rem">${esc(r.sanctie)}</span>` : '';
            // Bruto-audit-icoon vóór de tijd (📷 fotofinish / ✋ handmatig).
            // Tooltip toont de gemeten transponder-tijd; tabel-uitlijning blijft.
            const heeftAudit = r.bruto_tijd_ms != null
                            && r.tijd_ms      != null
                            && r.bruto_tijd_ms !== r.tijd_ms;
            // == 1: PDO levert is_photofinish soms als string "0"/"1", en
            // "0" is truthy in JS → ternary zou altijd 📷 kiezen voor
            // handmatige RR-tijden. Loose-equality werkt cross-type.
            const auditIcon = heeftAudit
                ? `<span class="col-tijd-audit" title="${esc(t('heat_bruto_gemeten'))} ${esc(msTijd(r.bruto_tijd_ms))}">${r.is_photofinish == 1 ? '📷' : '✋'}</span>`
                : '';
            // Fin-kolom leeg voor non-finishers, ook als de operator een
            // finishpositie heeft ingevuld — sanctie wint (consistent met
            // _sorteerHeatRijders en public/index.php).
            const _sanctieCodes = String(r.sanctie || '').toUpperCase().split(/[,\s]+/);
            const isNonFinisher = ['DNS','DNF','DQ-TF','DQ-SF','DQ-DF']
                .some(c => _sanctieCodes.includes(c));
            const finTxt = (isNonFinisher || r.finishpositie == null)
                ? '' : r.finishpositie;
            return `<tr class="${isMij ? 'mijn' : ''}">
                <td class="col-pos">${esc(r.startpositie)}</td>
                <td class="col-snr">${esc(r.snr)}</td>
                <td class="col-fin">${esc(finTxt)}</td>
                <td>${esc(r.full_name)}${sanctie}</td>
                ${heeftRnd ? `<td class="col-rnd">${r.rondes ?? ''}</td>` : ''}
                ${heeftPK  ? `<td class="col-pk">${r.pk_punten != null ? parseFloat(r.pk_punten) : ''}</td>` : ''}
                <td class="col-tijd">${auditIcon}${r.tijd_ms != null ? msTijd(r.tijd_ms) : ''}</td>
            </tr>`;
        }).join('');
        // Fin-kolom direct na Snr (was helemaal rechts, viel weg in oog).
        // CSS kleurt de cijfers rood — label "Fin" blijft normale kleur.
        const hdr = `<tr><th class="col-pos">${t('col_pos')}</th><th class="col-snr">${t('col_snr')}</th><th class="col-fin">${t('col_fin')}</th><th>${t('col_naam')}</th>
                    ${heeftRnd ? `<th class="col-rnd">${t('col_rnd')}</th>` : ''}
                    ${heeftPK  ? `<th class="col-pk">${t('col_pnt')}</th>` : ''}
                    <th class="col-tijd">${t('col_tijd')}</th></tr>`;
        overlay.querySelector('.overlay-box').innerHTML =
            `<div class="heat-card-titel">
                <button class="overlay-sluit" onclick="this.closest('.overlay').remove()" title="${t('pwa_btn_sluit')}">&times;</button>
                ${esc(ritNaam)}
             </div>
             <div class="overlay-body">
                <table class="heat-tabel">${hdr}${rijen}</table>
             </div>`;
    } catch (e) {
        overlay.querySelector('.overlay-box').innerHTML =
            `<button class="overlay-sluit" onclick="this.closest('.overlay').remove()" title="${t('pwa_btn_sluit')}">&times;</button>
             <div class="leeg-melding" style="padding:24px">${t('uit_fout', {msg: esc(e.message)})}</div>`;
    }
}

// ── Footer: org logo + sponsor-marquee (afgeleid van /public) ────────────────
// Te brede logos (ratio > 3.6:1) passen niet in de vaste footer-positie en
// gaan naar de sponsor-marquee zodat ze op volle hoogte leesbaar langs rollen.
const _FOOTER_LOGO_MAX_RATIO = 150 / 50;   // = 3.0 : 1
function _logoRatio(src) {
    return new Promise(resolve => {
        const img = new Image();
        img.onload  = () => resolve(img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1);
        img.onerror = () => resolve(1);
        img.src = src;
    });
}
async function updateHeaderLogos(opt) {
    const footer  = $('org-footer');
    const logoEl  = $('footer-org-logo');
    const naamEl  = $('footer-org-naam');
    const sponsEl = $('footer-sponsors');
    const baanEl  = $('footer-baan-logo');
    if (!opt?.value) {
        footer.style.display = 'none';
        document.body.classList.remove('heeft-footer');
        return;
    }
    const orgLogo = opt.dataset.orgLogo || '';
    const orgNaam = opt.dataset.orgNaam || '';
    const baanLogo = opt.dataset.baanLogo || '';
    const baanVer  = opt.dataset.baanVereniging || '';
    let sponsors = [];
    try { sponsors = JSON.parse(opt.dataset.sponsors || '[]'); } catch { sponsors = []; }
    if (!orgLogo && !sponsors.length && !baanLogo && !baanVer) {
        footer.style.display = 'none';
        document.body.classList.remove('heeft-footer');
        return;
    }
    const cb = `?v=${Math.floor(Date.now() / 3600000)}`;

    // Detecteer welke logos te breed zijn voor de vaste positie
    const orgRatio  = orgLogo  ? await _logoRatio(`../${esc(orgLogo)}${cb}`)  : 0;
    const baanRatio = baanLogo ? await _logoRatio(`../${esc(baanLogo)}${cb}`) : 0;
    const orgInFooter  = orgLogo  && orgRatio  <= _FOOTER_LOGO_MAX_RATIO;
    const baanInFooter = baanLogo && baanRatio <= _FOOTER_LOGO_MAX_RATIO;

    logoEl.innerHTML = orgInFooter ? `<img class="org-footer-logo" src="../${esc(orgLogo)}${cb}" alt="">` : '';
    // Naam-fallback alleen als er ECHT geen logo is.
    naamEl.textContent = !orgLogo ? orgNaam : '';

    if (baanInFooter) {
        baanEl.innerHTML = `<img class="org-footer-logo" src="../${esc(baanLogo)}${cb}" alt="">`;
    } else if (baanVer && !baanLogo) {
        baanEl.innerHTML = `<span class="org-footer-naam">${esc(baanVer)}</span>`;
    } else {
        baanEl.innerHTML = '';
    }
    baanEl.style.display = baanEl.innerHTML ? '' : 'none';

    // Marquee: te-brede logos + sponsors. Altijd marquee, min-duur 8s.
    let imgs = '';
    const _liggendImg = (src, naam) =>
        `<img src="../${esc(src)}${cb}" alt="${esc(naam)}" title="${esc(naam)}" style="height:50px;width:auto;object-fit:contain">`;
    if (orgLogo  && !orgInFooter)  imgs += _liggendImg(orgLogo,  orgNaam);
    if (baanLogo && !baanInFooter) imgs += _liggendImg(baanLogo, baanVer || '');
    for (const s of sponsors) {
        const img = _liggendImg(s.logo, s.naam);
        imgs += s.url ? `<a href="${esc(s.url)}" target="_blank" rel="noopener">${img}</a>` : img;
    }
    if (imgs) {
        const aantal = sponsors.length
                     + (orgLogo  && !orgInFooter  ? 1 : 0)
                     + (baanLogo && !baanInFooter ? 1 : 0);
        const duur = Math.max(8, aantal * 3);
        sponsEl.innerHTML = `<div class="sponsor-marquee"><div class="sponsor-marquee-inner" style="animation-duration:${duur}s">${imgs}${imgs}</div></div>`;
    } else {
        sponsEl.innerHTML = '';
    }
    footer.style.display = 'block';
    document.body.classList.add('heeft-footer');
}

// ── Info- en Help-overlays (coach-versie) ────────────────────────────────────
function toonInfo() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>${t('info_titel')}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">
            <h3>${t('info_h_wat')}</h3>
            <p>${t('info_p_wat1_html')}</p>
            <p>${t('info_p_wat2')}</p>

            <h3>${t('info_h_login')}</h3>
            <p>${t('info_p_login_html')}</p>

            <h3>${t('info_h_tip')}</h3>
            <p>${t('info_p_tip')}</p>

            <h3>${t('info_h_dev')}</h3>
            <p>${t('info_p_dev')}</p>

            <h3>${t('info_h_contact_html')}</h3>
            <p>${t('info_p_contact')}</p>
            <p style="text-align:center;margin:12px 0">
                <a href="mailto:inlinecomp@devriesen.com" style="display:inline-block;background:var(--oranje);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">inlinecomp@devriesen.com</a>
            </p>

            <h3>${t('info_h_stats')}</h3>
            <p style="font-size:.85rem;color:#555">${t('info_p_stats_html')}</p>

            <p style="font-size:.8rem;color:#999;text-align:center;margin-top:16px">${t('info_copyright', {jaar: new Date().getFullYear()})}</p>
            <p style="font-size:.75rem;color:#aaa;text-align:center;margin-top:4px">
                ${t('info_versie')} <strong>${APP_VERSIE}</strong>
            </p>
        </div>
    </div>`;
    document.body.appendChild(overlay);
}

function toonHelp() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>${t('help_titel')}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">

            <button type="button" class="btn-nieuw-jump"
                    onclick="this.closest('.help-body').querySelector('#wat-is-nieuw').scrollIntoView({behavior:'smooth',block:'start'})">
                ✨ ${t('nieuw_jump')}
            </button>

            <h3>${t('help_h_start')}</h3>
            <div class="help-stap"><span class="help-stap-nr">1</span>
                <span>${t('help_stap1_html')}</span></div>
            <div class="help-stap"><span class="help-stap-nr">2</span>
                <span>${t('help_stap2_html')}</span></div>

            <!-- Mockup: openings-venster (Wedstrijd & rijders) — hoort bij stap 1+2 -->
            <div class="mock">
                <div class="mock-hdr">${t('mock_venster_titel')}</div>
                <div class="mock-body">
                    <div style="display:flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;color:var(--blauw);margin:0 0 4px">
                        <span style="background:var(--blauw);color:#fff;width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem">1</span>
                        ${t('mock_kies_w')}
                    </div>
                    <div style="display:flex;gap:4px;margin:0 0 6px">
                        <span style="flex:1;text-align:center;font-size:.7rem;font-weight:600;padding:4px 0;border-radius:12px;border:1.5px solid #cdd8e3;color:#888;background:#fff">${t('filter_eerder')}</span>
                        <span style="flex:1;text-align:center;font-size:.7rem;font-weight:600;padding:4px 0;border-radius:12px;border:1.5px solid var(--middenblauw);color:var(--blauw);background:var(--lichtblauw)">${t('filter_vandaag')}</span>
                        <span style="flex:1;text-align:center;font-size:.7rem;font-weight:600;padding:4px 0;border-radius:12px;border:1.5px solid #cdd8e3;color:#888;background:#fff">${t('filter_later')}</span>
                    </div>
                    <div class="mock-select">${t('mock_voorbeeld_w')}</div>
                    <div style="display:flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;color:var(--blauw);margin:10px 0 4px">
                        <span style="background:var(--blauw);color:#fff;width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem">2</span>
                        ${t('mock_kies_rijders')}
                    </div>
                    <div style="font-size:.68rem;font-weight:600;color:#333;margin:4px 0 2px">${t('mock_op_club')}</div>
                    <div class="mock-select">${t('mock_kies_club')}</div>
                    <div style="font-size:.68rem;font-weight:600;color:#333;margin:4px 0 2px">${t('mock_op_sponsor')}</div>
                    <div class="mock-select">${t('mock_kies_sponsor')}</div>
                    <div style="font-size:.68rem;font-weight:600;color:#333;margin:4px 0 2px">${t('mock_op_snr')}</div>
                    <div class="mock-select">${t('mock_snr_lic')}</div>
                    <div style="background:#ffdcbc;color:#fff;text-align:center;padding:6px;border-radius:6px;font-weight:700;font-size:.72rem;margin-top:4px">${t('mock_btn_start')}</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;padding-top:6px;border-top:1px solid #eef2f6;font-size:.7rem;color:#555">
                        <span>${t('mock_geselecteerd')}</span>
                    </div>
                    <div style="background:var(--oranje);color:#fff;text-align:center;padding:8px;border-radius:6px;font-weight:700;font-size:.85rem;margin-top:6px">${t('mock_btn_klaar')}</div>
                </div>
            </div>

            <div class="help-stap"><span class="help-stap-nr">3</span>
                <span>${t('help_stap3_html')}</span></div>

            <h3>${t('help_h_tabs')}</h3>
            <p>${t('help_p_tabs_html')}</p>

            <h3>${t('help_h_prog')}</h3>
            <p>${t('help_p_prog_html')}</p>

            <!-- Mockup: programma met filter-strook + inklap-balk + heat-rijen -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab active">${t('tab_programma')}</div>
                    <div class="mock-tab">${t('tab_heats')}</div>
                    <div class="mock-tab">${t('tab_sancties')}</div>
                    <div class="mock-tab">${t('tab_rondes')}</div>
                    <div class="mock-tab">${t('tab_uitslagen')}</div>
                </div>
                <div style="background:#fff;border-top:1px solid #b3cae6;border-bottom:1px solid #b3cae6">
                    <div style="padding:5px 10px;font-size:.65rem;font-weight:600;color:#1a3a5c;border-bottom:1px solid #d5dee7;display:flex;align-items:center;gap:6px">
                        <span>🏁</span><span style="flex:1">${t('prog_filter_alle_afstanden')}</span><span style="font-size:.55rem">▼</span>
                    </div>
                </div>
                <div style="display:flex;gap:3px;padding:4px 6px;background:#eef2f6">
                    <span style="flex:1;text-align:center;font-size:.65rem;font-weight:700;padding:3px 0;border-radius:4px;border:1px solid var(--blauw);background:var(--blauw);color:#fff">▶ ${t('prog_klap_alles_uit')}</span>
                    <span style="flex:1;text-align:center;font-size:.65rem;font-weight:600;padding:3px 0;border-radius:4px;border:1px solid #cdd8e3;background:#fff;color:#555">▼ ${t('prog_klap_alles_in')}</span>
                    <span style="flex:1;text-align:center;font-size:.65rem;font-weight:600;padding:3px 0;border-radius:4px;border:1px solid #cdd8e3;background:#fff;color:#555">👤 ${t('prog_klap_mijn')}</span>
                </div>
                <div class="mock-body" style="padding:4px 10px">
                    <div class="mock-row"><span style="color:#aaa">1</span> <span class="mock-naam">500m ${t('mock_ronde_serie')} Heat 1</span> <span style="font-size:.6rem;background:#0d6efd;color:#fff;border-radius:3px;padding:0 4px">${t('mock_ronde_serie')}</span></div>
                    <div class="mock-row mock-hl"><span style="color:#aaa">2</span> <span class="mock-naam">500m ${t('mock_ronde_serie')} Heat 2</span> <span style="font-size:.6rem;background:#0d6efd;color:#fff;border-radius:3px;padding:0 4px">${t('mock_ronde_serie')}</span></div>
                    <div class="mock-row"><span style="color:#aaa">3</span> <span class="mock-naam">500m A-${t('mock_ronde_finale')}</span> <span style="font-size:.6rem;background:#198754;color:#fff;border-radius:3px;padding:0 4px">${t('mock_ronde_finale')}</span></div>
                </div>
            </div>

            <h3>${t('help_h_heats')}</h3>
            <p>${t('help_p_heats_html')}</p>

            <!-- Mockup: coach heats-tab (per rijder overzicht van alle rondes) -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${t('tab_programma')}</div>
                    <div class="mock-tab active">${t('tab_heats')}</div>
                    <div class="mock-tab">${t('tab_sancties')}</div>
                    <div class="mock-tab">${t('tab_rondes')}</div>
                    <div class="mock-tab">${t('tab_uitslagen')}</div>
                </div>
                <div class="mock-body" style="padding:6px 8px">
                    <!-- Rijder-blok 1: Emma (voorbeeld met meerdere DC's) -->
                    <div style="border:1px solid #dde3ea;border-radius:6px;padding:6px 8px;margin-bottom:6px;background:#fff">
                        <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;font-weight:700">
                            <span>86</span>
                            <span style="flex:1">Emma V.</span>
                            <span style="color:#888;font-size:.62rem">HP1</span>
                        </div>
                        <!-- Samenvat: één rij per afstand met status -->
                        <div style="display:flex;justify-content:space-between;font-size:.62rem;padding:2px 0"><span>200m DTT Series + A-Final</span><span style="background:#d4edda;color:#155724;padding:1px 5px;border-radius:3px;font-weight:600">${t('mock_status_bev')}</span></div>
                        <div style="display:flex;justify-content:space-between;font-size:.62rem;padding:2px 0"><span>1000m</span><span style="background:#d4edda;color:#155724;padding:1px 5px;border-radius:3px;font-weight:600">${t('mock_status_bev')}</span></div>
                        <div style="display:flex;justify-content:space-between;font-size:.62rem;padding:2px 0"><span>Pointsrace</span><span style="background:#d4edda;color:#155724;padding:1px 5px;border-radius:3px;font-weight:600">${t('mock_status_bev')}</span></div>
                        <!-- DC-blok 1: 200m DTT -->
                        <div style="background:#f4f8fc;border-left:2px solid var(--middenblauw);border-radius:3px;padding:4px 6px;margin-top:5px">
                            <div style="font-size:.62rem;font-weight:700;color:var(--blauw);margin-bottom:2px">Pupils Boys 200m DTT — 200m DTT Series + A-Final</div>
                            <div style="display:flex;align-items:center;gap:5px;font-size:.62rem;padding:1px 0">
                                <span style="background:#607d8b;color:#fff;padding:0 5px;border-radius:2px;font-size:.56rem;font-weight:700">${t('mock_ronde_serie')}</span>
                                <span><b>${t('mock_heat_lbl')} 2</b> ${t('mock_startpos_lbl')} 1</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:5px;font-size:.62rem;padding:1px 0">
                                <span style="background:#d32f2f;color:#fff;padding:0 5px;border-radius:2px;font-size:.56rem;font-weight:700">${t('mock_ronde_finale')}</span>
                                <span><b>${t('mock_heat_lbl')} 1</b> ${t('mock_startpos_lbl')} 1</span>
                            </div>
                        </div>
                        <!-- DC-blok 2: 1000m met Runner-up nog niet geloot -->
                        <div style="background:#f4f8fc;border-left:2px solid var(--middenblauw);border-radius:3px;padding:4px 6px;margin-top:4px">
                            <div style="font-size:.62rem;font-weight:700;color:var(--blauw);margin-bottom:2px">Pupils Boys 1000m — 1000m</div>
                            <div style="display:flex;align-items:center;gap:5px;font-size:.62rem;padding:1px 0">
                                <span style="background:#5e35b1;color:#fff;padding:0 5px;border-radius:2px;font-size:.56rem;font-weight:700">HF</span>
                                <span><b>${t('mock_heat_lbl')} 1</b> ${t('mock_startpos_lbl')} 3</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:5px;font-size:.62rem;padding:1px 0;color:#888">
                                <span style="background:#00897b;color:#fff;padding:0 5px;border-radius:2px;font-size:.56rem;font-weight:700">${t('mock_ronde_ru')}</span>
                                <span>${t('mock_wacht_loting')}</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:5px;font-size:.62rem;padding:1px 0">
                                <span style="background:#d32f2f;color:#fff;padding:0 5px;border-radius:2px;font-size:.56rem;font-weight:700">${t('mock_ronde_finale')}</span>
                                <span><b>${t('mock_heat_lbl')} 1</b> ${t('mock_startpos_lbl')} 3</span>
                            </div>
                        </div>
                    </div>
                    <!-- Rijder-blok 2: tweede rijder met "Bev. bij org." status -->
                    <div style="border:1px solid #dde3ea;border-radius:6px;padding:6px 8px;background:#fff">
                        <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;font-weight:700">
                            <span>34</span>
                            <span style="flex:1">Tim B.</span>
                            <span style="color:#888;font-size:.62rem">HJB</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.62rem;padding:2px 0"><span>200m DTT Series + A-Final</span><span style="background:#d1ecf1;color:#0c5460;padding:1px 5px;border-radius:3px;font-weight:600">${t('mock_status_bev_org')}</span></div>
                        <div style="display:flex;justify-content:space-between;font-size:.62rem;padding:2px 0"><span>1000m</span><span style="background:#d1ecf1;color:#0c5460;padding:1px 5px;border-radius:3px;font-weight:600">${t('mock_status_bev_org')}</span></div>
                    </div>
                </div>
            </div>

            <h3>${t('help_h_rondes')}</h3>
            <p>${t('help_p_rondes_html')}</p>

            <!-- Mockup: rondes-tab (per-ronde uitslag alle DC's, doorstroom Q→A) -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${t('tab_programma')}</div>
                    <div class="mock-tab">${t('tab_heats')}</div>
                    <div class="mock-tab">${t('tab_sancties')}</div>
                    <div class="mock-tab active">${t('tab_rondes')}</div>
                    <div class="mock-tab">${t('tab_uitslagen')}</div>
                </div>
                <div class="mock-body" style="padding:6px 10px">
                    <div style="font-weight:700;color:var(--blauw);font-size:.75rem;margin:2px 0 4px">DJB — 500 meter</div>
                    <div style="display:inline-block;background:#0d6efd;color:#fff;border-radius:3px;padding:1px 6px;font-size:.6rem;font-weight:700;margin-bottom:3px">${t('mock_ronde_serie')}</div>
                    <div class="mock-row" style="font-size:.6rem;color:#888;font-weight:600"><span style="width:24px">${t('mock_col_snr')}</span><span class="mock-naam">${t('mock_col_naam')}</span><span style="width:36px;text-align:center">Kwal</span><span class="mock-tijd">${t('mock_col_tijd')}</span></div>
                    <div class="mock-row mock-hl"><span class="mock-snr">12</span><span class="mock-naam">${t('mock_jouw_rijder')}</span><span style="width:36px;text-align:center;font-weight:700;color:#198754">Q→A</span><span class="mock-tijd">45.30</span></div>
                    <div class="mock-row"><span class="mock-snr">86</span><span class="mock-naam">Emma V.</span><span style="width:36px;text-align:center;font-weight:700;color:#198754">Q→A</span><span class="mock-tijd">45.12</span></div>
                </div>
            </div>

            <h3>${t('help_h_sanc')}</h3>
            <p>${t('help_p_sanc1')}</p>
            <ul style="margin:4px 0 8px 18px">${t('help_p_sanc_lijst_html')}</ul>
            <p>${t('help_p_sanc2_html')}</p>

            <h3>${t('help_h_uitsl')}</h3>
            <p>${t('help_p_uitsl')}</p>

            <!-- Mockup: uitslagen met combi-cat kolommen -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${t('tab_programma')}</div>
                    <div class="mock-tab">${t('tab_heats')}</div>
                    <div class="mock-tab">${t('tab_sancties')}</div>
                    <div class="mock-tab">${t('tab_rondes')}</div>
                    <div class="mock-tab active">${t('tab_uitslagen')}</div>
                </div>
                <div class="mock-body" style="padding:6px 10px">
                    <div class="mock-select">HJA + HSA</div>
                    <div class="mock-select">500 meter</div>
                    <div style="margin-top:6px">
                        <div class="mock-row" style="font-size:.6rem;color:#fff;background:var(--blauw);margin:0 -10px;padding:3px 10px;font-weight:600"><span style="width:18px">${t('mock_col_rang')}</span><span style="width:26px;text-align:center">HJA</span><span style="width:26px;text-align:center">HSA</span><span style="width:24px">${t('mock_col_snr')}</span><span class="mock-naam">${t('mock_col_naam')}</span><span class="mock-tijd">${t('mock_col_tijd')}</span></div>
                        <div class="mock-row"><span class="mock-rang">1</span><span style="width:26px;text-align:center;color:var(--blauw);font-weight:700">1</span><span style="width:26px;text-align:center;color:#aaa">·</span><span class="mock-snr">86</span><span class="mock-naam">Emma V.</span><span class="mock-tijd">45.12</span></div>
                        <div class="mock-row mock-hl"><span class="mock-rang">2</span><span style="width:26px;text-align:center;color:#aaa">·</span><span style="width:26px;text-align:center;color:var(--blauw);font-weight:700">1</span><span class="mock-snr">12</span><span class="mock-naam">${t('mock_jouw_rijder')}</span><span class="mock-tijd">45.30</span></div>
                        <div class="mock-row"><span class="mock-rang">3</span><span style="width:26px;text-align:center;color:var(--blauw);font-weight:700">2</span><span style="width:26px;text-align:center;color:#aaa">·</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-tijd">46.01</span></div>
                    </div>
                </div>
            </div>

            <h3>${t('help_h_auto')}</h3>
            <p>${t('help_p_auto_html')}</p>

            <h3>${t('help_h_meld')}</h3>
            <p>${t('help_p_meld_html')}</p>

            <h3>${t('help_h_priv')}</h3>
            <p>${t('help_p_priv')}</p>

            <!-- ── Wat is nieuw (changelog per versie) ── -->
            <h3 id="wat-is-nieuw" style="margin-top:24px;padding-top:12px;border-top:2px solid #eef2f6">
                ✨ ${t('nieuw_h')}
            </h3>
            <p style="font-size:.88rem;color:#555">${t('nieuw_intro')}</p>

            <div class="changelog-versie">
                <div class="changelog-kop">
                    <span class="changelog-vnr">${APP_VERSIE}</span>
                    <span class="changelog-datum">06-07-2026</span>
                </div>
                <ul class="changelog-lijst">
                    <li>${t('nieuw_v100_13_html')}</li>
                    <li>${t('nieuw_v100_7_html')}</li>
                    <li>${t('nieuw_v100_2_html')}</li>
                    <li>${t('nieuw_v100_4_html')}</li>
                    <li>${t('nieuw_v100_11_html')}</li>
                    <li>${t('nieuw_v100_9_html')}</li>
                    <li>${t('nieuw_v100_14_html')}</li>
                </ul>
            </div>
        </div>
    </div>`;
    document.body.appendChild(overlay);
}

// ── Init-flow ────────────────────────────────────────────────────────────────
// Filter-regel (1-op-1 uit /public):
// Drie onafhankelijke filters: Eerder · Vandaag · Later. Wedstrijd verschijnt
// als hij in tenminste één aangevinkte categorie valt. Alle drie uit → lege
// lijst met helder bericht.
function filterComps() {
    const nu = new Date();
    const gisteren = new Date(nu); gisteren.setDate(gisteren.getDate() - 1); gisteren.setHours(0,0,0,0);
    const morgen   = new Date(nu); morgen.setDate(morgen.getDate() + 1);   morgen.setHours(23,59,59,999);

    const toonOud      = $('chk-oud').checked;
    const toonVandaag  = $('chk-vandaag').checked;
    const toonToekomst = $('chk-toekomst').checked;
    const vorigeWaarde = selComp.value;

    if (!toonOud && !toonVandaag && !toonToekomst) {
        selComp.innerHTML = `<option value="">${t('opt_kies_filter')}</option>`;
        return;
    }

    selComp.innerHTML = `<option value="">${t('opt_kies_wedstrijd')}</option>`;
    for (const c of alleComps) {
        const startDag = safeDatum(c.starts);
        const eindDag  = safeDatum(c.ends) ?? startDag;
        const isVandaag  = startDag && startDag <= morgen && eindDag >= gisteren;
        const isOud      = !isVandaag && eindDag   && eindDag   < gisteren;
        const isToekomst = !isVandaag && startDag && startDag > morgen;
        if (isVandaag  && !toonVandaag)  continue;
        if (isOud      && !toonOud)      continue;
        if (isToekomst && !toonToekomst) continue;

        const loc = (typeof getLocale === 'function') ? getLocale() : 'nl-NL';
        const dtStr = startDag
            ? startDag.toLocaleDateString(loc,{day:'numeric',month:'long',year:'numeric'})
            : '';
        // Verborgen wedstrijden: tonen als disabled met "(binnenkort)"
        // suffix — gebruiker ziet dat de wedstrijd er aankomt zonder
        // erop te kunnen klikken. Operator publiceert via Beheer.
        const verborgen = !Number(c.public_zichtbaar);
        const o = document.createElement('option');
        o.value = c.id;
        o.textContent = `${c.name}${dtStr ? ' — ' + dtStr : ''}${verborgen ? '  ' + t('opt_binnenkort') : ''}`;
        if (verborgen) o.disabled = true;
        o.dataset.orgLogo        = c.org_logo ?? '';
        o.dataset.orgNaam        = c.org_naam ?? '';
        o.dataset.baanLogo       = c.baan_logo ?? '';
        o.dataset.baanVereniging = c.baan_vereniging ?? '';
        o.dataset.sponsors       = JSON.stringify(c.sponsors ?? []);
        selComp.appendChild(o);
    }

    // Herstel selectie als die nog in de lijst staat en niet (inmiddels) disabled.
    const vorigeOpt = vorigeWaarde
        ? selComp.querySelector(`option[value="${vorigeWaarde}"]`)
        : null;
    if (vorigeOpt && !vorigeOpt.disabled) {
        selComp.value = vorigeWaarde;
    } else {
        // Auto-selecteer als er maar 1 selecteerbare wedstrijd over is —
        // disabled ('binnenkort') tellen niet mee, anders kreeg de
        // gebruiker bij vervolgstappen pas een 'niet beschikbaar'-fout.
        const opties = selComp.querySelectorAll('option[value]:not([value=""]):not([disabled])');
        if (opties.length === 1) {
            selComp.value = opties[0].value;
            selComp.dispatchEvent(new Event('change'));
        }
    }
    // Setup-strook synchroniseren — bij vorigeWaarde-restore triggert
    // change() niet (dat gebeurt alleen bij auto-select), dus updateSetupStrip
    // handmatig aanroepen zodat de strip niet "Kies je wedstrijd…" blijft
    // tonen terwijl er wel een selectie is.
    if (typeof updateSetupStrip === 'function') updateSetupStrip();
}

async function laadCompetitions() {
    try {
        const res = await safeFetch('?action=competitions');
        const lijst = await res.json();
        if (!Array.isArray(lijst)) return;
        alleComps = lijst;

        // Directe-link-support: ?comp=<uuid> in de URL selecteert die wedstrijd.
        // Gebruikt door de QR-code op de coach-poster. Als de wedstrijd buiten
        // het "Vandaag"-venster valt (oud of toekomstig) vinken we automatisch
        // het juiste filter aan zodat de optie zichtbaar is.
        const urlParams = new URLSearchParams(window.location.search);
        const wantedComp = urlParams.get('comp');
        if (wantedComp) {
            const comp = alleComps.find(c => c.id === wantedComp);
            if (comp) {
                const nu = new Date();
                const startDag = comp.starts ? new Date(comp.starts) : null;
                const eindDag  = comp.ends   ? new Date(comp.ends)   : startDag;
                if (eindDag && eindDag < nu)   $('chk-oud').checked      = true;
                if (startDag && startDag > nu) $('chk-toekomst').checked = true;
            }
        }

        filterComps();

        // Na filteren: select 'm als de optie nu beschikbaar is, dan triggert
        // het bestaande change-event de auto-refresh + meldingen-check.
        if (wantedComp && selComp.querySelector(`option[value="${wantedComp}"]`)) {
            selComp.value = wantedComp;
            selComp.dispatchEvent(new Event('change'));
        }
    } catch (e) {
        selComp.innerHTML = `<option value="">${t('opt_fout_laden')}</option>`;
    }
}

function zetStap2Enabled(enabled) {
    // Sectie 2 blijft altijd zichtbaar; de inputs schakelen we enable/disable
    // op basis van of er een wedstrijd is gekozen. Zo ziet de user meteen
    // wat er na stap 1 mogelijk is.
    $('btn-club-open').disabled    = !enabled;
    $('btn-sponsor-open').disabled = !enabled;
    inpSnr.disabled      = !enabled;
    btnToevoegen.disabled = !enabled;
    if (!enabled) {
        _clubAlle = [];
        _clubSel.clear();
        _sponsorAlle = [];
        _sponsorSel.clear();
        $('club-multi-label').textContent    = t('multi_kies_wedstrijd_eerst');
        $('sponsor-multi-label').textContent = t('multi_kies_wedstrijd_eerst');
        $('club-multi-paneel').hidden    = true;
        $('sponsor-multi-paneel').hidden = true;
        $('club-chips').innerHTML    = '';
        $('sponsor-chips').innerHTML = '';
    }
    updateToevoegenKnop();
}

// De Toevoegen-knop is alleen "echt klikbaar" (oranje volle kracht) als er
// iets gekozen/ingevuld is in één van de drie bronnen. Anders dimmen we 'm
// iets zodat de user ziet dat er nog niks te doen valt.
function updateToevoegenKnop() {
    if (!selComp.value) { btnToevoegen.disabled = true; return; }
    const heeftInvoer = !!(_clubSel.size > 0 || _sponsorSel.size > 0
                        || inpSnr.value.trim());
    btnToevoegen.disabled = !heeftInvoer;
}

async function opCompetitionChange() {
    const compId = selComp.value;
    const opt = selComp.options[selComp.selectedIndex];
    updateHeaderLogos(opt);
    const compInfoEl = $('comp-info');
    if (!compId) {
        zetStap2Enabled(false);
        secLijst.style.display = 'none';
        secProg.style.display = 'none';
        compInfoEl.style.display = 'none';
        compInfoEl.innerHTML = '';
        return;
    }
    // Comp-info kaartje vullen met wedstrijd-naam + datum (1-op-1 uit /public)
    const c = alleComps.find(x => x.id === compId);
    if (c) {
        const dt = safeDatum(c.starts);
        const loc = (typeof getLocale === 'function') ? getLocale() : 'nl-NL';
        const dtStr = dt ? dt.toLocaleDateString(loc, {weekday:'long', day:'numeric', month:'long', year:'numeric'}) : '';
        compInfoEl.innerHTML = `<strong>${esc(c.name)}</strong>${dtStr ? `<small>${esc(dtStr)}</small>` : ''}`;
        compInfoEl.style.display = 'block';
    }
    zetStap2Enabled(true);
    secProg.style.display = 'block';
    loadCoachLijst();
    // Reset programma-inklap-state bij wisselen van wedstrijd zodat de
    // default-collapsed logica opnieuw geldt (anders zou je bij switch
    // een leeggemaakte "alles open" krijgen omdat _progIngeklapt niet meer
    // matcht met de nieuwe groep-keys).
    _progIngeklapt.clear();
    _progGroepenMetMijn.clear();
    _progGroepAlleKeys = [];
    _progEersteRender = true;
    coachInfoCache = {};
    _catsMetAfstanden = [];
    $('u-sel-cat').innerHTML = `<option value="">${t('uitsl_opt_kies_cat')}</option>`;
    $('u-afstand-rij').style.display = 'none';
    $('uitslagen').innerHTML = '';
    $('r-sel-cat').innerHTML = `<option value="">${t('rondes_opt_kies_cat')}</option>`;
    $('r-afstand-rij').style.display = 'none';
    $('rondes-inhoud').innerHTML = '';
    renderChips();
    renderSancties();
    renderHeats();
    // Clubs + sponsors + programma parallel laden
    progEl.innerHTML = `<div class="leeg-melding"><span class="spinner"></span> ${t('uit_laden')}</div>`;
    programmaCache = null;
    try {
        const [clubsRes, sponsorsRes, progRes] = await Promise.all([
            safeFetch(`?action=clubs&competition_id=${encodeURIComponent(compId)}`),
            safeFetch(`?action=sponsors&competition_id=${encodeURIComponent(compId)}`),
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`),
        ]);
        const clubs    = await clubsRes.json();
        const sponsors = await sponsorsRes.json();
        programmaCache = await progRes.json();

        _clubAlle = Array.isArray(clubs) ? clubs : [];
        _clubSel.clear();
        renderClubMultiSelect();
        updateClubLabel();
        _sponsorAlle = Array.isArray(sponsors) ? sponsors : [];
        _sponsorSel.clear();
        renderSponsorMultiSelect();
        updateSponsorLabel();
        renderProgramma();
        // Status + sancties ophalen voor de al bestaande coach-lijst (uit localStorage)
        await laadCoachInfo();
        renderChips();
        renderSancties();
        renderHeats();
    } catch (e) {
        progEl.innerHTML = `<div class="leeg-melding">${t('uit_fout', {msg: esc(e.message)})}</div>`;
    }
}

// Lookup-helper: roept person_lookup aan met snr/license_key/naam, en
// handelt resultaat-aantal af:
//   0 matches  → foutmelding
//   1 match    → direct toevoegen aan coach-lijst
//   ≥2 matches → keuze-modal zodat user beslist welke(n)
async function _coachLookupEnToevoegen(param, meldingen, foutMeldingen, label) {
    const qs = new URLSearchParams({ competition_id: selComp.value, ...param });
    let lijst;
    try {
        const res = await safeFetch('?action=person_lookup&' + qs.toString());
        lijst = await res.json();
    } catch (e) {
        foutMeldingen.push(t('fb_lookup_mislukt', {label, msg: e.message}));
        return;
    }
    if (lijst?.error) { foutMeldingen.push(t('fb_label_error', {label, error: lijst.error})); return; }
    if (!Array.isArray(lijst) || !lijst.length) {
        foutMeldingen.push(t('fb_label_niet_gevonden', {label}));
        return;
    }
    if (lijst.length === 1) {
        const p = lijst[0];
        if (voegToeAanLijst(p)) {
            meldingen.push(p.snr ? t('fb_naam_snr', {naam: p.full_name, snr: p.snr}) : p.full_name);
        } else {
            meldingen.push(t('fb_stond_al_in_lijst', {naam: p.full_name}));
        }
        return;
    }
    // ≥2 matches: laat user kiezen via modal. Modal voegt zelf toe + update
    // meldingen-array zodra user op OK klikt. Await Promise zodat de
    // omhullende voegAllesToe wacht voor save+render.
    await _coachKiesPersoonModal(lijst, label, meldingen);
}

// Multi-keuze modal: lijst van rijders met checkboxes. User kan er meer
// dan één tegelijk aanvinken (handig bij naam-zoek met meerdere
// naamgenoten). Rijders die al in coach-lijst staan worden voor-aangevinkt
// en disabled getoond ("al toegevoegd").
function _coachKiesPersoonModal(rijders, label, meldingen) {
    return new Promise(resolve => {
        const al = new Set(coachLijst.map(p => p.license_key));
        const modal = document.createElement('div');
        modal.className = 'naamzoek-modal';
        modal.innerHTML = `
            <div class="naamzoek-box">
                <div class="naamzoek-hdr">
                    <span>${t('nz_matches_voor', {n: esc(rijders.length), label: esc(label)})}</span>
                    <button class="naamzoek-sluit" title="${t('nz_sluit')}">&times;</button>
                </div>
                <div class="naamzoek-body">
                    ${rijders.map(r => {
                        const uit = al.has(r.license_key);
                        const meta = [r.category || '', r.club_short || r.club_full || '',
                                      uit ? `<span style="color:#999">${t('nz_al_in_lijst')}</span>` : '']
                                     .filter(Boolean).join(' · ');
                        return `<label class="naamzoek-rij" style="${uit ? 'opacity:.55' : ''}">
                            <input type="checkbox" data-lic="${esc(r.license_key)}" ${uit ? 'checked disabled' : ''}>
                            <span class="naamzoek-rij-snr">${esc(r.snr ?? '—')}</span>
                            <div class="naamzoek-rij-naam">
                                ${esc(r.full_name)}
                                <div class="naamzoek-rij-meta">${meta}</div>
                            </div>
                        </label>`;
                    }).join('')}
                </div>
                <div class="naamzoek-voet">
                    <span class="aantal">${t('nz_vink_aan')}</span>
                    <button class="btn-primair" id="coach-modal-ok" style="padding:8px 18px">${t('nz_toevoegen')}</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        const sluit = () => { modal.remove(); resolve(); };
        modal.querySelector('.naamzoek-sluit').addEventListener('click', sluit);
        modal.addEventListener('click', e => { if (e.target === modal) sluit(); });
        modal.querySelector('#coach-modal-ok').addEventListener('click', () => {
            const vinkjes = [...modal.querySelectorAll('input[type=checkbox]:checked:not(:disabled)')];
            for (const cb of vinkjes) {
                const r = rijders.find(x => x.license_key === cb.dataset.lic);
                if (r && voegToeAanLijst(r)) {
                    meldingen.push(r.snr ? t('fb_naam_snr', {naam: r.full_name, snr: r.snr}) : r.full_name);
                }
            }
            sluit();
        });
    });
}

// Eén gedeelde toevoeg-handler: pakt alle niet-lege bronnen tegelijk op
// (club, sponsor, startnummer, naam/licentie) en voegt wat erin zit toe
// aan de coach-lijst.
async function voegAllesToe() {
    if (!selComp.value) return;
    const zoekTerm = inpSnr.value.trim();
    if (_clubSel.size === 0 && _sponsorSel.size === 0 && !zoekTerm) {
        snrFb.textContent = t('fb_kies_iets');
        snrFb.style.color = '#b71c1c';
        return;
    }

    const meldingen = [];
    const foutMeldingen = [];

    // Bulk-call: alle geselecteerde clubs + sponsors in 1 server-request.
    // Voorkomt rate-limit als coach veel selecteert ("alle clubs" = 40+
    // sequentiële calls oude stijl, nu altijd 1 call).
    if (_clubSel.size > 0 || _sponsorSel.size > 0) {
        const clubList    = [..._clubSel];
        const sponsorList = [..._sponsorSel];
        let aantalTotaal = 0;
        try {
            const res = await coachFetch('?action=personen_bulk', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    competition_id: selComp.value,
                    clubs:    clubList,
                    sponsors: sponsorList,
                }),
            });
            if (res.status === 429) {
                foutMeldingen.push(t('fb_server_druk'));
            } else if (!res.ok) {
                foutMeldingen.push(t('fb_server_fout', {status: res.status}));
            } else {
                const lijst = await res.json();
                (Array.isArray(lijst) ? lijst : []).forEach(p => { if (voegToeAanLijst(p)) aantalTotaal++; });
                const stukken = [];
                if (clubList.length)    stukken.push(t(clubList.length === 1 ? 'fb_clubs_single' : 'fb_clubs_plural', {n: clubList.length}));
                if (sponsorList.length) stukken.push(t(sponsorList.length === 1 ? 'fb_sponsors_single' : 'fb_sponsors_plural', {n: sponsorList.length}));
                meldingen.push(aantalTotaal
                    ? t('fb_rijders_van_single', {aantal: aantalTotaal, stukken: stukken.join(' + ')})
                    : t('fb_rijders_van_geen', {stukken: stukken.join(' + ')}));
            }
        } catch (e) {
            foutMeldingen.push(t('fb_netwerk_fout'));
        }
        if (clubList.length) {
            _clubSel.clear();
            renderClubMultiSelect();
            updateClubLabel();
        }
        if (sponsorList.length) {
            _sponsorSel.clear();
            renderSponsorMultiSelect();
            updateSponsorLabel();
        }
    }

    // Eén gecombineerd zoek-veld. _coachZoekModus bepaalt of het een
    // startnummer / licentie / naam is op basis van de input:
    //   alleen cijfers ≤4 = startnummer (bv. "42")
    //   alleen cijfers ≥5 = licentienr  (KNSB ~7-8 cijfers)
    //   bevat letters     = naam-zoek   (LIKE, min 2 tekens)
    if (zoekTerm) {
        const modus = _coachZoekModus(zoekTerm);
        const param = modus === 'snr'     ? { snr: zoekTerm }
                    : modus === 'license' ? { license_key: zoekTerm }
                    :                       { naam: zoekTerm };
        const label = modus === 'snr'     ? t('fb_label_snr',      {term: zoekTerm})
                    : modus === 'license' ? t('fb_label_licentie', {term: zoekTerm})
                    :                       t('fb_label_naam',     {term: zoekTerm});
        await _coachLookupEnToevoegen(param, meldingen, foutMeldingen, label);
        inpSnr.value = '';
    }

    saveCoachLijst();
    await verversCoachLijstUI();
    updateToevoegenKnop();

    if (foutMeldingen.length) {
        snrFb.textContent = foutMeldingen.join(' · ');
        snrFb.style.color = '#b71c1c';
    } else if (meldingen.length) {
        snrFb.textContent = t('fb_toegevoegd', {lijst: meldingen.join(' · ')});
        snrFb.style.color = '#2e7d32';
    }
}

// ── Listeners ────────────────────────────────────────────────────────────────
$('chk-oud').addEventListener('change', filterComps);
$('chk-vandaag').addEventListener('change', filterComps);
$('chk-toekomst').addEventListener('change', filterComps);
selComp.addEventListener('change', opCompetitionChange);
// Club/sponsor/startnr: niet direct toevoegen — wacht op de Toevoegen-knop.
// De knop wordt pas actief zodra er iets gekozen/ingevuld is.
inpSnr.addEventListener('input', updateToevoegenKnop);
btnToevoegen.addEventListener('click', voegAllesToe);
inpSnr.addEventListener('keydown', e => { if (e.key === 'Enter') voegAllesToe(); });

// ── Sponsor multi-select widget ──────────────────────────────────────────────
// Knop opent/sluit het paneel met checkboxes. Search filter, alle/niets
// helpers, klaar-knop sluit het paneel. State zit in _sponsorSel (Set).
function renderSponsorMultiSelect() {
    const lijst = $('sponsor-multi-lijst');
    if (!lijst) return;
    if (!_sponsorAlle.length) {
        lijst.innerHTML = `<div class="leeg">${t('multi_geen_sponsors_panel')}</div>`;
    } else {
        lijst.innerHTML = _sponsorAlle.map(s => {
            const checked = _sponsorSel.has(s) ? 'checked' : '';
            return `<label><input type="checkbox" data-sponsor="${esc(s)}" ${checked}> <span>${esc(s)}</span></label>`;
        }).join('');
    }
    $('sponsor-multi-teller').textContent = t('multi_geselecteerd', {n: _sponsorSel.size});
}
function updateSponsorLabel() {
    const lbl = $('sponsor-multi-label');
    const knop = $('btn-sponsor-open');
    const chipsWrap = $('sponsor-chips');
    if (!lbl || !knop || !chipsWrap) return;

    if (_sponsorSel.size === 0) {
        lbl.textContent = _sponsorAlle.length
            ? t('multi_kies_sponsor')
            : t('multi_geen_sponsors');
        knop.classList.remove('heeft-selectie');
        chipsWrap.innerHTML = '';
    } else {
        lbl.textContent = t(_sponsorSel.size === 1 ? 'multi_sponsor_gekozen_single' : 'multi_sponsor_gekozen_plural', {n: _sponsorSel.size});
        knop.classList.add('heeft-selectie');
        // Chips eronder zodat operator direct ziet wat hij gekozen heeft
        // (en eentje kan weghalen zonder paneel weer te openen).
        chipsWrap.innerHTML = [..._sponsorSel]
            .map(s => `<span class="sponsor-chip" data-sponsor="${esc(s)}" title="${t('multi_chip_klik_verwijder')}">${esc(s)}</span>`)
            .join('');
    }
}

$('btn-sponsor-open').addEventListener('click', () => {
    const paneel = $('sponsor-multi-paneel');
    const open = !paneel.hidden;
    if (open) { paneel.hidden = true; return; }
    if (!_sponsorAlle.length) return;
    paneel.hidden = false;
    renderSponsorMultiSelect();
});

// Klik buiten paneel = sluiten
document.addEventListener('click', (ev) => {
    const paneel = $('sponsor-multi-paneel');
    if (!paneel || paneel.hidden) return;
    const wrap = paneel.parentElement; // .sponsor-multi-wrap
    if (wrap && !wrap.contains(ev.target)) paneel.hidden = true;
});

$('sponsor-multi-lijst').addEventListener('change', (ev) => {
    const inp = ev.target;
    if (!inp.matches('input[type="checkbox"]')) return;
    const sp = inp.dataset.sponsor;
    if (inp.checked) _sponsorSel.add(sp); else _sponsorSel.delete(sp);
    $('sponsor-multi-teller').textContent = t('multi_geselecteerd', {n: _sponsorSel.size});
    updateSponsorLabel();
    updateToevoegenKnop();
});

$('sponsor-multi-alles').addEventListener('click', () => {
    _sponsorAlle.forEach(s => _sponsorSel.add(s));
    renderSponsorMultiSelect();
    updateSponsorLabel();
    updateToevoegenKnop();
});
$('sponsor-multi-niets').addEventListener('click', () => {
    _sponsorSel.clear();
    renderSponsorMultiSelect();
    updateSponsorLabel();
    updateToevoegenKnop();
});
$('sponsor-multi-klaar').addEventListener('click', () => {
    $('sponsor-multi-paneel').hidden = true;
});

// Chip-klik: sponsor uit de selectie halen, zonder paneel te openen.
$('sponsor-chips').addEventListener('click', (ev) => {
    const chip = ev.target.closest('.sponsor-chip');
    if (!chip) return;
    const sp = chip.dataset.sponsor;
    if (sp) {
        _sponsorSel.delete(sp);
        updateSponsorLabel();
        updateToevoegenKnop();
    }
});

// ── Club multi-select widget (zelfde patroon als sponsor) ────────────────────
// Verschil: clubs zijn objects {full, short}, search matcht op beide;
// label toont "SHORT - Full" indien short bekend, anders alleen full.
function _clubLabel(c) { return c.short ? `${c.short} - ${c.full}` : c.full; }

function renderClubMultiSelect() {
    const lijst = $('club-multi-lijst');
    if (!lijst) return;
    if (!_clubAlle.length) {
        lijst.innerHTML = `<div class="leeg">${t('multi_geen_clubs_panel')}</div>`;
    } else {
        lijst.innerHTML = _clubAlle.map(c => {
            const checked = _clubSel.has(c.full) ? 'checked' : '';
            return `<label><input type="checkbox" data-club="${esc(c.full)}" ${checked}> <span>${esc(_clubLabel(c))}</span></label>`;
        }).join('');
    }
    $('club-multi-teller').textContent = t('multi_geselecteerd', {n: _clubSel.size});
}

function updateClubLabel() {
    const lbl = $('club-multi-label');
    const knop = $('btn-club-open');
    const chipsWrap = $('club-chips');
    if (!lbl || !knop || !chipsWrap) return;

    if (_clubSel.size === 0) {
        lbl.textContent = _clubAlle.length
            ? t('multi_kies_club')
            : t('multi_geen_clubs');
        knop.classList.remove('heeft-selectie');
        chipsWrap.innerHTML = '';
    } else {
        lbl.textContent = t(_clubSel.size === 1 ? 'multi_club_gekozen_single' : 'multi_club_gekozen_plural', {n: _clubSel.size});
        knop.classList.add('heeft-selectie');
        // Toon korte label voor chip (SHORT als bekend, anders FULL)
        chipsWrap.innerHTML = [..._clubSel].map(full => {
            const c = _clubAlle.find(x => x.full === full) || {full};
            const labelText = c.short || c.full;
            return `<span class="sponsor-chip" data-club="${esc(full)}" title="${t('multi_chip_klik_verwijder')}">${esc(labelText)}</span>`;
        }).join('');
    }
}

$('btn-club-open').addEventListener('click', () => {
    const paneel = $('club-multi-paneel');
    const open = !paneel.hidden;
    if (open) { paneel.hidden = true; return; }
    if (!_clubAlle.length) return;
    paneel.hidden = false;
    renderClubMultiSelect();
});
document.addEventListener('click', (ev) => {
    const paneel = $('club-multi-paneel');
    if (!paneel || paneel.hidden) return;
    const wrap = paneel.parentElement;
    if (wrap && !wrap.contains(ev.target)) paneel.hidden = true;
});
$('club-multi-lijst').addEventListener('change', (ev) => {
    const inp = ev.target;
    if (!inp.matches('input[type="checkbox"]')) return;
    const cl = inp.dataset.club;
    if (inp.checked) _clubSel.add(cl); else _clubSel.delete(cl);
    $('club-multi-teller').textContent = t('multi_geselecteerd', {n: _clubSel.size});
    updateClubLabel();
    updateToevoegenKnop();
});
$('club-multi-alles').addEventListener('click', () => {
    _clubAlle.forEach(c => _clubSel.add(c.full));
    renderClubMultiSelect();
    updateClubLabel();
    updateToevoegenKnop();
});
$('club-multi-niets').addEventListener('click', () => {
    _clubSel.clear();
    renderClubMultiSelect();
    updateClubLabel();
    updateToevoegenKnop();
});
$('club-multi-klaar').addEventListener('click', () => {
    $('club-multi-paneel').hidden = true;
});
$('club-chips').addEventListener('click', (ev) => {
    const chip = ev.target.closest('.sponsor-chip');
    if (!chip) return;
    const cl = chip.dataset.club;
    if (cl) {
        _clubSel.delete(cl);
        updateClubLabel();
        updateToevoegenKnop();
    }
});
$('btn-wis-alles').addEventListener('click', async () => {
    if (!coachLijst.length) return;
    const ok = await bevestig({
        titel: t('bev_wis_titel'),
        tekst: t(coachLijst.length === 1 ? 'bev_wis_tekst_single' : 'bev_wis_tekst_plural', {n: coachLijst.length}),
        bevestigLabel: t('bev_wis_ok'),
        annuleerLabel: t('bev_annuleer'),
    });
    if (!ok) return;
    coachLijst = [];
    coachInfoCache = {};
    saveCoachLijst();
    await verversCoachLijstUI();
});

// Tab-switch Programma ↔ Sancties ↔ Uitslagen
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === btn));
        const tab = btn.dataset.tab;
        document.querySelectorAll('.tab-pane').forEach(p =>
            p.classList.toggle('active', p.id === 'tab-' + tab));
        // Categorieën altijd opnieuw laden bij switch naar uitslagen-tab —
        // klassement-publish/intrek en nieuwe heats kunnen tussentijds
        // wijzigen. Cache-busting via _t in _laadCatsMetAfstanden.
        if (tab === 'uitslagen' && selComp.value) {
            _catsMetAfstanden = [];
            laadUitslagenCategorieen();
        }
        // Rondes-tab: idem, hergebruikt dezelfde cat-cache.
        if (tab === 'rondes' && selComp.value) {
            _catsMetAfstanden = [];
            laadRondesCategorieen();
        }
    });
});

$('u-sel-cat').addEventListener('change', opCatChange);
$('u-sel-afstand').addEventListener('change', opAfstandChange);
$('r-sel-cat').addEventListener('change', opRondeCatChange);
$('r-sel-afstand').addEventListener('change', opRondeAfstandChange);

// ── Mededelingen (pop-ups bij belangrijke aankondigingen) ──────────────
const _MELDING_PRIO = {
    info:   { kleur: '#1a3a5c', bg: '#e8f0f7', icoon: 'ℹ️' },
    warn:   { kleur: '#7a5800', bg: '#fff8d6', icoon: '⚠️' },
    urgent: { kleur: '#a00',    bg: '#ffe5e5', icoon: '🚨' },
};
// Multi-lang helper: kies de juiste vertaling met fallback-keten
// huidige taal → EN → NL. NL is altijd verplicht (DB-kolom NOT NULL);
// EN/DE/FR komen via Claude bulk-vertaling bij save in beheer.
// Zelfde patroon als in /public.
function _meldingTekst(m, veld) {
    const lang = getCurLang();
    if (lang !== 'nl' && m[veld + '_' + lang]) return m[veld + '_' + lang];
    if (lang !== 'nl' && m[veld + '_en'])      return m[veld + '_en'];
    return m[veld] || '';
}
// Sleutel per melding-scope: globaal (geen competition_id) krijgt eigen
// localStorage-bucket zodat 'gezien' niet wisselt als je van wedstrijd switcht.
const _meldingScope = (m) => m?.competition_id ? m.competition_id : 'global';
const _meldingenLsKey = (scope) => `meldingen_gezien_${scope}`;
const _gezienSet = (scope) => {
    try { return new Set(JSON.parse(localStorage.getItem(_meldingenLsKey(scope)) || '[]')); }
    catch { return new Set(); }
};
const _markGezien = (scope, id) => {
    const set = _gezienSet(scope);
    set.add(id);
    localStorage.setItem(_meldingenLsKey(scope), JSON.stringify([...set]));
};
let _meldingLijst = [];
let _meldingActief = false;

async function checkMeldingen(compId) {
    // compId leeg → alleen globale meldingen ophalen (landing-pagina).
    // compId gevuld → wedstrijd-specifiek + globaal samen (één call).
    try {
        const url = compId
            ? '../api/meldingen.php?comp_id=' + encodeURIComponent(compId) + '&_t=' + Date.now()
            : '../api/meldingen.php?global=1&_t=' + Date.now();
        const res = await safeFetch(url);
        const lijst = await res.json();
        if (!Array.isArray(lijst)) return;
        const nu = Date.now();
        _meldingLijst = lijst.filter(m => {
            const van = m.geldig_van ? Date.parse(m.geldig_van.replace(' ', 'T')) : 0;
            const tot = m.geldig_tot ? Date.parse(m.geldig_tot.replace(' ', 'T')) : null;
            if (van && van > nu)        return false;
            if (tot !== null && tot < nu) return false;
            return true;
        });
        updateMeldingenBadge();
        if (!_meldingActief) toonVolgendeMelding(compId);
    } catch { /* stil */ }
}

function updateMeldingenBadge() {
    const btn = document.getElementById('btn-meldingen-overzicht');
    const badge = document.getElementById('meldingen-badge');
    if (!btn || !badge) return;
    if (_meldingLijst.length === 0) {
        btn.style.display = 'none';
        badge.style.display = 'none';
        return;
    }
    // Badge toont ALTIJD het totaal aantal meldingen zodat je ziet dat ze er
    // zijn. Als er nog ONGELEZEN zijn: rood + uitroepteken (= "kijk even");
    // als alles is gezien: grijs zonder uitroepteken (= "alleen FYI"). Een
    // melding telt als gelezen zodra de fullscreen-pop-up met OK is wegge-
    // klikt (zie _markGezien in de OK-handler hieronder).
    btn.style.display = '';
    const aantalOngelezen = _meldingLijst.filter(m =>
        !_gezienSet(_meldingScope(m)).has(m.id)
    ).length;
    badge.textContent = aantalOngelezen > 0
        ? `${_meldingLijst.length}!`
        : String(_meldingLijst.length);
    badge.classList.toggle('gezien', aantalOngelezen === 0);
    badge.style.display = '';
}

function toonMeldingenOverzicht() {
    if (!_meldingLijst.length) return;
    const escFn = (typeof esc === 'function') ? esc : (s => String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])));
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9400;display:flex;align-items:flex-start;justify-content:center;padding:4vh 1rem;overflow-y:auto;';
    const loc = (typeof getLocale === 'function') ? getLocale() : 'nl-NL';
    const items = _meldingLijst.map(m => {
        const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
        const tijd = m.geldig_van
            ? new Date(m.geldig_van.replace(' ', 'T')).toLocaleString(loc,
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        const tot = m.geldig_tot
            ? t('meld_tot') + new Date(m.geldig_tot.replace(' ', 'T')).toLocaleString(loc,
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        // Twee-taal-meldingen: bij EN gekozen + EN-versie aanwezig (uit Claude
        // auto-vertaling) → toon EN. Anders NL als fallback. Zelfde patroon
        // als /public.
        const titelToon   = _meldingTekst(m, 'titel');
        const berichtToon = _meldingTekst(m, 'bericht');
        const bijlHtml = m.bijlage_path
            ? `<a href="../${escFn(m.bijlage_path)}" target="_blank" rel="noopener"
                   download="${escFn(m.bijlage_naam || 'bijlage')}"
                   style="display:inline-flex;align-items:center;gap:.3rem;
                          margin-top:.4rem;background:#fff;
                          border:1px solid ${stijl.kleur};color:${stijl.kleur};
                          text-decoration:none;padding:.3rem .55rem;
                          border-radius:4px;font-size:.8rem;font-weight:600;
                          max-width:100%;">
                   📎 <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escFn(m.bijlage_naam || 'bijlage')}</span>
                </a>`
            : '';
        return `<div style="background:${stijl.bg};border-left:4px solid ${stijl.kleur};
                            padding:.7rem .9rem;margin-bottom:.6rem;border-radius:5px;">
            <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.3rem;">
                <span style="font-size:1.2rem">${stijl.icoon}</span>
                <strong style="color:${stijl.kleur};flex:1;">${escFn(titelToon)}</strong>
            </div>
            <div style="color:#222;line-height:1.4;font-size:.9rem;white-space:pre-wrap;">${escFn(berichtToon)}</div>
            ${bijlHtml}
            <div style="font-size:.75rem;color:#888;margin-top:.3rem;">${escFn(tijd)}${escFn(tot)}</div>
        </div>`;
    }).join('');
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:8px;max-width:480px;width:100%;
                    box-shadow:0 10px 30px rgba(0,0,0,.3);">
            <div style="display:flex;align-items:center;justify-content:space-between;
                        padding:.8rem 1rem;border-bottom:1px solid #e0e0e0;">
                <h3 style="margin:0;color:var(--blauw);font-size:1.05rem;">${t('meld_kop')}</h3>
                <button class="meld-overz-sluit" style="background:none;border:none;
                        font-size:1.6rem;cursor:pointer;color:#666;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:1rem;">${items}</div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-overz-sluit').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

document.getElementById('btn-meldingen-overzicht')?.addEventListener('click', toonMeldingenOverzicht);
function toonVolgendeMelding(compId) {
    if (_meldingActief) return;
    for (const m of _meldingLijst) {
        const scope = _meldingScope(m);
        if (!_gezienSet(scope).has(m.id)) { toonMelding(m, compId); return; }
    }
}
function toonMelding(m, compId) {
    if (_meldingActief) return;
    _meldingActief = true;
    const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
    const overlay = document.createElement('div');
    // Overlay scrolt zelf óók (overflow-y:auto) als achterval voor heel kleine
    // schermen waar zelfs de inner-box met max-height: 90vh nog te hoog is.
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9500;display:flex;align-items:center;justify-content:center;padding:1rem;overflow-y:auto;';
    const escFn = (typeof esc === 'function') ? esc : (s => String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])));
    // Twee-taal: pak EN-versie als gekozen + beschikbaar, anders NL fallback.
    const titelToon   = _meldingTekst(m, 'titel');
    const berichtToon = _meldingTekst(m, 'bericht');
    // Inner-box als flex-column: header + scrollable bericht + knop. Bericht-
    // div krijgt overflow-y:auto + min-height:0 (cruciaal voor flex-children),
    // knop heeft flex-shrink:0 zodat 'ie altijd onderaan zichtbaar blijft.
    overlay.innerHTML = `
        <div style="background:${stijl.bg};border:3px solid ${stijl.kleur};border-radius:10px;
                    max-width:400px;width:100%;max-height:calc(100vh - 2rem);
                    display:flex;flex-direction:column;
                    box-shadow:0 10px 40px rgba(0,0,0,.4);animation:meldingPop .3s ease-out;">
            <div style="display:flex;align-items:center;gap:.6rem;padding:1.5rem 1.5rem 0;flex-shrink:0;">
                <span style="font-size:1.8rem">${stijl.icoon}</span>
                <h2 style="margin:0;color:${stijl.kleur};font-size:1.1rem;flex:1;">${escFn(titelToon)}</h2>
            </div>
            <div style="color:#222;line-height:1.5;font-size:.95rem;
                        white-space:pre-wrap;padding:.6rem 1.5rem 1rem;
                        overflow-y:auto;flex:1 1 auto;min-height:0;">${escFn(berichtToon)}</div>
            ${m.bijlage_path ? `
            <div style="padding:0 1.5rem .8rem;flex-shrink:0;">
                <a href="../${escFn(m.bijlage_path)}" target="_blank" rel="noopener"
                   download="${escFn(m.bijlage_naam || 'bijlage')}"
                   style="display:flex;align-items:center;gap:.5rem;
                          background:#fff;border:1.5px solid ${stijl.kleur};
                          color:${stijl.kleur};text-decoration:none;
                          padding:.5rem .8rem;border-radius:6px;font-size:.9rem;
                          font-weight:600;">
                    <span style="font-size:1.1rem">📎</span>
                    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escFn(m.bijlage_naam || 'Download bijlage')}</span>
                    <span style="font-size:.8rem;opacity:.7">⬇</span>
                </a>
            </div>` : ''}
            <div style="padding:0 1.5rem 1.5rem;flex-shrink:0;">
                <button class="meld-ok" style="background:${stijl.kleur};color:#fff;border:none;
                                                padding:.6rem 1.4rem;border-radius:6px;font-size:1rem;
                                                font-weight:600;cursor:pointer;width:100%;">
                    ${t('meld_begrepen')}
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-ok').addEventListener('click', () => {
        _markGezien(_meldingScope(m), m.id);
        updateMeldingenBadge();
        overlay.remove();
        _meldingActief = false;
        // Direct doorrollen — werkt ook zonder geselecteerde wedstrijd
        // (globale meldingen mogen altijd aaneengeschakeld scrollen).
        toonVolgendeMelding(selComp.value);
    });
}
(() => {
    const style = document.createElement('style');
    style.textContent = '@keyframes meldingPop { from {opacity:0;transform:scale(.85)} to {opacity:1;transform:scale(1)} }';
    document.head.appendChild(style);
})();

// ── Pull-to-refresh ──────────────────────────────────────────────────────────
// Alleen actief als we boven aan de pagina zijn én er een wedstrijd gekozen is.
// Trekt het programma opnieuw op (zelfde endpoint, cache is 30s).
(() => {
    const ptrEl = $('ptr');
    const THRESHOLD = 70;   // px slepen voor trigger
    const PTR_COOLDOWN_MS = 30_000;  // min tijd tussen 2 PTR-acties
    let startY = null, dragY = 0, actief = false, bezigLaden = false;
    let ptrLaatste = 0;

    async function herlaadProgramma() {
        if (!selComp.value || bezigLaden) return;
        // Cooldown: bij PTR < 30s na vorige tonen we kort een melding ipv
        // de server opnieuw aanroepen. Voorkomt burst bij ongeduld of
        // per-ongeluk-twee-keer-pullen.
        const sindsLaatste = Date.now() - ptrLaatste;
        if (ptrLaatste && sindsLaatste < PTR_COOLDOWN_MS) {
            const wachten = Math.ceil((PTR_COOLDOWN_MS - sindsLaatste) / 1000);
            ptrEl.classList.add('laadt');
            ptrEl.textContent = t('ptr_wachten', {s: wachten});
            setTimeout(() => { ptrEl.classList.remove('zichtbaar','laadt'); }, 1200);
            return;
        }
        bezigLaden = true;
        ptrEl.classList.add('laadt');
        ptrEl.textContent = t('ptr_vernieuwen');
        // Mededelingen-check parallel — pop-up zodra een nieuwe binnenkomt
        if (typeof checkMeldingen === 'function') checkMeldingen(selComp.value);
        try {
            const res = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(selComp.value)}&_ts=${Date.now()}`);
            programmaCache = await res.json();
            // Snapshot UI-state vóór re-render zodat filter, klap-balk en
            // handmatige groep-open/dicht behouden blijven over auto-refresh.
            const _uiState = _snapshotProgUiState();
            renderProgramma();
            _restoreProgUiState(_uiState);
            // Bij elke refresh ook status + sancties opnieuw: kan veranderen tijdens de dag
            await laadCoachInfo();
            renderChips();
            renderSancties();
            renderHeats();
            // Gedeelde cat-cache verversen — beide tabs lezen hieruit.
            // klassement_config publish/intrek + nieuwe heats kunnen tijdens
            // de dag wijzigen.
            _catsMetAfstanden = [];
            const uitsTabAct = document.querySelector('.tab-btn[data-tab="uitslagen"]').classList.contains('active');
            const rondTabAct = document.querySelector('.tab-btn[data-tab="rondes"]').classList.contains('active');
            if (uitsTabAct || rondTabAct) await _laadCatsMetAfstanden();

            // Uitslagen-tab: cat + afstand/klassement-keuze bewaren.
            if (uitsTabAct) {
                const huidigCatU = $('u-sel-cat').value;
                const huidigAfU  = $('u-sel-afstand').value;
                await laadUitslagenCategorieen();
                if (huidigCatU && $('u-sel-cat').querySelector(`option[value="${CSS.escape(huidigCatU)}"]`)) {
                    $('u-sel-cat').value = huidigCatU;
                    opCatChange();
                    if (huidigAfU && $('u-sel-afstand').querySelector(`option[value="${CSS.escape(huidigAfU)}"]`)) {
                        $('u-sel-afstand').value = huidigAfU;
                        await opAfstandChange();
                    }
                }
            }
            // Rondes-tab: idem — nieuwe heat-uitslagen verschijnen automatisch.
            if (rondTabAct) {
                const huidigCatR = $('r-sel-cat').value;
                const huidigAfR  = $('r-sel-afstand').value;
                initRondesCatDropdown();
                if (huidigCatR && $('r-sel-cat').querySelector(`option[value="${CSS.escape(huidigCatR)}"]`)) {
                    $('r-sel-cat').value = huidigCatR;
                    opRondeCatChange();
                    if (huidigAfR && $('r-sel-afstand').querySelector(`option[value="${CSS.escape(huidigAfR)}"]`)) {
                        $('r-sel-afstand').value = huidigAfR;
                        await opRondeAfstandChange();
                    }
                }
            }
            ptrLaatste = Date.now();
            ptrEl.textContent = t('ptr_bijgewerkt');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar','laadt'); }, 600);
        } catch (e) {
            ptrEl.textContent = t('ptr_fout');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar','laadt'); }, 1200);
        } finally {
            bezigLaden = false;
        }
    }

    document.addEventListener('touchstart', e => {
        if (window.scrollY > 0 || bezigLaden || !selComp.value) { startY = null; return; }
        if (e.touches.length !== 1) { startY = null; return; }
        startY = e.touches[0].clientY;
        dragY = 0;
        actief = false;
    }, { passive:true });

    document.addEventListener('touchmove', e => {
        if (startY === null) return;
        dragY = e.touches[0].clientY - startY;
        if (dragY <= 0) { if (actief) { ptrEl.classList.remove('zichtbaar'); actief = false; } return; }
        if (dragY > 30 && !actief) { ptrEl.classList.add('zichtbaar'); actief = true; }
        ptrEl.textContent = dragY >= THRESHOLD ? t('ptr_laat_los') : t('ptr_trek');
    }, { passive:true });

    document.addEventListener('touchend', () => {
        if (startY === null) return;
        const was = dragY;
        startY = null; dragY = 0;
        if (actief && was >= THRESHOLD) {
            herlaadProgramma();
        } else if (actief) {
            ptrEl.classList.remove('zichtbaar'); actief = false;
        }
    });

    // Desktop-fallback: dubbelklik op de header refreshed ook het programma
    document.querySelector('header').addEventListener('dblclick', herlaadProgramma);

    // ── Auto-refresh elke 3 minuten ─────────────────────────────────────────
    // Alleen actief als er een wedstrijd gekozen is én de tab zichtbaar is.
    // Pauzeren bij verborgen tab scheelt verkeer én batterij. Na terugkeer
    // pakt-ie meteen weer op om de user een verse staat te geven.
    // 3 min — frequente updates komen via meldingen-push; deze poll is
    // alleen vangnet voor passieve weergave. Lagere frequentie scheelt
    // serverbelasting bij grote wedstrijden.
    const AUTO_REFRESH_MS = 180_000;
    let autoTick = null;
    const lastEl = document.createElement('div');
    lastEl.className = 'auto-refresh-stempel';
    lastEl.title = t('auto_refresh_title');
    document.body.appendChild(lastEl);
    const zetStempel = () => {
        const d = new Date();
        const hh = String(d.getHours()).padStart(2,'0');
        const mm = String(d.getMinutes()).padStart(2,'0');
        lastEl.textContent = `🔄 ${hh}:${mm}`;
    };
    // Bereken interval op basis van consecutiveFails: bij fouten progressief
    // langer wachten zodat we de server niet hameren als hij eruit ligt.
    // 0-1 fouten → 60s, 2 → 90s, 3+ → 120s.
    const _tickInterval = () => {
        const f = _conn.consecutiveFails;
        if (f >= 3) return Math.max(AUTO_REFRESH_MS, 120_000);
        if (f === 2) return Math.max(AUTO_REFRESH_MS, 90_000);
        return AUTO_REFRESH_MS;
    };
    const _scheduleTick = () => {
        stopAutoRefresh();
        if (!selComp.value || document.hidden) return;
        autoTick = setTimeout(async () => {
            autoTick = null;
            if (document.hidden || !selComp.value) return _scheduleTick();
            await herlaadProgramma();
            zetStempel();
            _scheduleTick();
        }, _tickInterval());
    };
    const startAutoRefresh = () => _scheduleTick();
    const stopAutoRefresh = () => {
        if (autoTick) { clearTimeout(autoTick); autoTick = null; }
    };
    // Hook voor _conn: bij online-event direct refresh + scheduling resetten.
    _conn.refreshHook = () => {
        if (selComp.value && !document.hidden) {
            herlaadProgramma().then(zetStempel).finally(_scheduleTick);
        }
    };
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopAutoRefresh();
        else { herlaadProgramma().then(zetStempel); startAutoRefresh(); }
    });
    selComp.addEventListener('change', () => {
        if (selComp.value) {
            zetStempel();
            startAutoRefresh();
            // Direct meldingen-check — niet wachten op eerste 60s tick
            if (typeof checkMeldingen === 'function') checkMeldingen(selComp.value);
        } else {
            stopAutoRefresh();
            lastEl.textContent = '';
            // Switch terug naar landing → één-malig globale meldingen ophalen.
            if (typeof checkMeldingen === 'function') checkMeldingen('');
        }
    });
    // Initieel: als er al een wedstrijd voorgeselecteerd is, meteen tick starten.
    // Globale meldingen-check loopt sowieso bij page-open (compId mag leeg zijn).
    if (selComp.value) { zetStempel(); startAutoRefresh(); }
    if (typeof checkMeldingen === 'function') checkMeldingen(selComp.value || '');
})();

laadCompetitions();

// ── PWA: service worker + install prompt ─────────────────────────────────
// Update-flow sinds 2026-05-27:
//  - SW is network-only met cache-cleanup (zie sw.js)
//  - Periodieke + visibility-driven update-check zodat geinstalleerde
//    PWA's binnen seconden weten dat er een nieuwe versie is
//  - GEEN automatische window.reload() bij controllerchange — dat wiste
//    input-velden tijdens typen (regressie: zoeken-knop bleef disabled
//    nadat startnummer mid-typen was gewist door een reload). Nieuwe
//    versie verschijnt nu pas bij volgende natuurlijke navigatie/refresh.
//    Voor browser-users: PHP no-cache headers zorgen sowieso voor vers
//    HTML. Voor PWA-users: tab sluiten/openen of app herstart.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').then(reg => {
        const checkUpdate = () => { try { reg.update(); } catch {} };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') checkUpdate();
        });
        setInterval(checkUpdate, 5 * 60 * 1000);
    }).catch(() => {});
}

let _deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredPrompt = e;
    if (!localStorage.getItem('pwa-coach-dismissed')) {
        document.getElementById('pwa-banner').style.display = '';
    }
});

document.getElementById('pwa-install')?.addEventListener('click', async () => {
    if (!_deferredPrompt) return;
    _deferredPrompt.prompt();
    const result = await _deferredPrompt.userChoice;
    if (result.outcome === 'accepted') {
        document.getElementById('pwa-banner').style.display = 'none';
    }
    _deferredPrompt = null;
});

document.getElementById('pwa-sluit')?.addEventListener('click', () => {
    document.getElementById('pwa-banner').style.display = 'none';
    localStorage.setItem('pwa-coach-dismissed', '1');
});

window.addEventListener('appinstalled', () => {
    document.getElementById('pwa-banner').style.display = 'none';
});
</script>
</body>
</html>
