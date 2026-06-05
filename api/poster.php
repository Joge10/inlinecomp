<?php
// ============================================================
//  InlineComp – promotie-poster generator endpoint
//
//  GET  ?org_id=<uuid>                       → poster voor deze organisatie
//  GET  ?org_id=<uuid>&competition_id=<uuid> → poster voor deze wedstrijd
//                                              (inclusief wedstrijd-naam/datum/
//                                              locatie, QR naar specifieke comp)
//
//  Response: PDF als attachment (application/pdf).
//  Vereist: login (owner/admin/planner — iedereen die in Beheer komt).
//
//  Roept tools/poster_gen.py aan via shell_exec. Zelfde Python-fallback-lijst
//  als api/klassement_import.php, want iFastNet gebruikt
//  /opt/alt/python311/bin/python3.11.
// ============================================================

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

if (!in_array($_authUser['role'] ?? '', ['owner', 'admin', 'planner'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Geen rechten om posters te genereren.']);
    exit;
}

$orgId  = trim($_GET['org_id'] ?? '');
$compId = trim($_GET['competition_id'] ?? '');
$appType = ($_GET['app'] ?? 'public') === 'coach' ? 'coach' : 'public';
// Taal van de poster-teksten. nl=Nederlands (default), en=English. Datum-
// strings worden hieronder ook locale-aware geformatteerd.
$lang   = in_array($_GET['lang'] ?? 'nl', ['nl', 'en'], true) ? $_GET['lang'] : 'nl';

if (!$orgId) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'org_id ontbreekt.']);
    exit;
}

// ── Organisatie + sponsors ophalen ───────────────────────────────────────
$orgStmt = $pdo->prepare("SELECT id, naam, logo_path, sportity_kanaal FROM organisaties WHERE id = ?");
$orgStmt->execute([$orgId]);
$org = $orgStmt->fetch(PDO::FETCH_ASSOC);
if (!$org) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Organisatie niet gevonden.']);
    exit;
}

$spStmt = $pdo->prepare("
    SELECT naam, logo_path FROM organisatie_sponsors
    WHERE organisatie_id = ? ORDER BY volgorde, naam
");
$spStmt->execute([$orgId]);
$sponsors = $spStmt->fetchAll(PDO::FETCH_ASSOC);

// Baan-sponsors worden later geladen op basis van $comp['baan_id'] (zie hieronder).
// Ze worden achteraan toegevoegd aan de sponsor-array, zodat ze achter
// de org-sponsors verschijnen in de poster-footer.
$baanSponsors = [];

// ── Wedstrijd ophalen (optioneel) ────────────────────────────────────────
$comp = null;
$baan = null;
if ($compId) {
    $compStmt = $pdo->prepare("
        SELECT id, name, starts, venue_name, venue_city, location
        FROM competitions WHERE id = ? AND organisatie_id = ?
    ");
    $compStmt->execute([$compId, $orgId]);
    $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Wedstrijd niet gevonden bij deze organisatie.']);
        exit;
    }

    // Baan-logo zoeken (zelfde cross-org fallback als in vergelijk.php) —
    // dezelfde fysieke baan kan onder meerdere orgs bestaan; we pakken een
    // logo van een andere org-rij voor dezelfde baan-naam als deze leeg is.
    $baanStmt = $pdo->prepare("
        SELECT COALESCE(b.logo_path, (
                   SELECT b2.logo_path FROM banen b2
                   WHERE b2.naam = b.naam AND b2.id != b.id
                     AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                   LIMIT 1
               )) AS logo_path,
               COALESCE(b.vereniging_naam, (
                   SELECT b2.vereniging_naam FROM banen b2
                   WHERE b2.naam = b.naam AND b2.id != b.id
                     AND b2.vereniging_naam IS NOT NULL AND b2.vereniging_naam != ''
                   LIMIT 1
               )) AS vereniging_naam
        FROM banen b
        JOIN competitions c ON c.baan_id = b.id
        WHERE c.id = ?
    ");
    $baanStmt->execute([$compId]);
    $baan = $baanStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Baan-sponsors ophalen (alleen als deze wedstrijd een baan_id heeft).
    // We pakken de sponsors van DEZE baan-rij — geen cross-org-fallback,
    // omdat sponsors expliciet per (org × baan)-combinatie ingesteld worden.
    $bidStmt = $pdo->prepare("SELECT baan_id FROM competitions WHERE id = ?");
    $bidStmt->execute([$compId]);
    $bid = $bidStmt->fetchColumn();
    if ($bid) {
        $bsStmt = $pdo->prepare("
            SELECT naam, logo_path FROM baan_sponsors
            WHERE baan_id = ? AND logo_path IS NOT NULL AND logo_path != ''
            ORDER BY volgorde, naam
        ");
        $bsStmt->execute([$bid]);
        $baanSponsors = $bsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Org-sponsors + baan-sponsors samenvoegen — baan achteraan zodat ze
// "verbonden aan deze locatie"-mind blijven na de organisatie-sponsors.
$sponsors = array_merge($sponsors, $baanSponsors);

// ── Absolute paden voor logo's ──────────────────────────────────────────
$webroot = realpath(__DIR__ . '/..');
function absPad(?string $rel) {
    global $webroot;
    if (!$rel) return '';
    $p = $webroot . DIRECTORY_SEPARATOR . ltrim($rel, '/');
    return is_file($p) ? $p : '';
}

$orgLogo      = absPad($org['logo_path']);
$baanLogo     = $baan ? absPad($baan['logo_path']) : '';
$sponsorsArg  = '';
$sponsorItems = [];
$debugPaden   = [];   // voor ?debug=1
foreach ($sponsors as $s) {
    $p = absPad($s['logo_path']);
    $n = str_replace(['|', ':'], [' ', ' '], $s['naam']);
    $sponsorItems[] = $n . ':' . $p;

    // Debug-info: wat staat er in de DB, waar zoekt PHP, vindt-ie het?
    $gezocht = $s['logo_path'] ? $webroot . DIRECTORY_SEPARATOR . ltrim($s['logo_path'], '/') : '';
    $debugPaden[] = [
        'naam'      => $s['naam'],
        'db_path'   => $s['logo_path'],
        'abs_path'  => $gezocht,
        'bestaat'   => $gezocht ? is_file($gezocht) : false,
        'leesbaar'  => $gezocht ? is_readable($gezocht) : false,
    ];
}
if ($sponsorItems) $sponsorsArg = implode('|', $sponsorItems);

// Debug-modus: geef JSON terug i.p.v. PDF
if (!empty($_GET['debug'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'webroot'       => $webroot,
        'org'           => [
            'naam'     => $org['naam'],
            'logo_db'  => $org['logo_path'],
            'logo_abs' => $orgLogo,
            'logo_ok'  => $orgLogo !== '',
        ],
        'sponsors' => $debugPaden,
        'comp'     => $comp ? [
            'naam'     => $comp['name'],
            'starts'   => $comp['starts'],
            'locatie'  => $compLocatie,
        ] : null,
        'qr_url'   => '(wordt pas later bepaald, zie code)',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── QR-url: specifiek per comp als die er is, anders generiek ────────────
$baseUrl = $appType === 'coach'
    ? 'https://inlineresults.devriesen.com/coach/'
    : 'https://inlineresults.devriesen.com/public/';
$qrUrl   = $comp ? ($baseUrl . '?comp=' . $comp['id']) : $baseUrl;

// ── Datum-string (locale-aware) ────────────────────────────────────────
$compDatum = '';
if ($comp && !empty($comp['starts'])) {
    $maandenPerLang = [
        'nl' => ['01'=>'januari','02'=>'februari','03'=>'maart','04'=>'april',
                 '05'=>'mei','06'=>'juni','07'=>'juli','08'=>'augustus',
                 '09'=>'september','10'=>'oktober','11'=>'november','12'=>'december'],
        'en' => ['01'=>'January','02'=>'February','03'=>'March','04'=>'April',
                 '05'=>'May','06'=>'June','07'=>'July','08'=>'August',
                 '09'=>'September','10'=>'October','11'=>'November','12'=>'December'],
    ];
    $maanden = $maandenPerLang[$lang] ?? $maandenPerLang['nl'];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $comp['starts'], $m)) {
        $compDatum = ltrim($m[3], '0') . ' ' . ($maanden[$m[2]] ?? $m[2]) . ' ' . $m[1];
    }
}
// Venue_name bevat vaak al de stad ("Skeelerbaan Lisserbroek"). Alleen
// venue_city erachter plakken als die niet al in de naam voorkomt.
$compLocatie = '';
if ($comp) {
    $vn = trim($comp['venue_name'] ?? '');
    $vc = trim($comp['venue_city'] ?? '');
    if ($vn && $vc && stripos($vn, $vc) === false) {
        $compLocatie = $vn . ', ' . $vc;
    } else {
        $compLocatie = $vn ?: $vc;
    }
}

// ── Python-binary zoeken (zelfde patroon als klassement_import.php) ────
if (!function_exists('shell_exec') || ini_get('safe_mode')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'shell_exec is uitgeschakeld op deze server.']);
    exit;
}

$python = null;
foreach ([
    '/opt/alt/python311/bin/python3.11',
    '/opt/alt/python39/bin/python3.9',
    '/opt/alt/python38/bin/python3.8',
    'python3', 'python',
    '/usr/bin/python3', '/usr/local/bin/python3',
] as $cmd) {
    $test = @shell_exec($cmd . ' --version 2>&1');
    if ($test && preg_match('/Python\s+3/', $test)) { $python = $cmd; break; }
}
if (!$python) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Python 3 niet gevonden op server.']);
    exit;
}

// ── Poster genereren naar tijdelijk pad ────────────────────────────────
$script = realpath(__DIR__ . '/../tools/poster_gen.py');
if (!$script) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'poster_gen.py niet gevonden.']);
    exit;
}

$tmpPdf = tempnam(sys_get_temp_dir(), 'poster_') . '.pdf';

$parts = [
    escapeshellarg($python),
    escapeshellarg($script),
    '--output',       escapeshellarg($tmpPdf),
    '--qr-url',       escapeshellarg($qrUrl),
    '--org-naam',     escapeshellarg($org['naam']),
];
if ($orgLogo)     { $parts[] = '--org-logo';       $parts[] = escapeshellarg($orgLogo); }
if ($baanLogo)    { $parts[] = '--baan-logo';      $parts[] = escapeshellarg($baanLogo); }
if ($comp)        { $parts[] = '--comp-naam';      $parts[] = escapeshellarg($comp['name']); }
if ($compDatum)   { $parts[] = '--comp-datum';     $parts[] = escapeshellarg($compDatum); }
if ($compLocatie) { $parts[] = '--comp-locatie';   $parts[] = escapeshellarg($compLocatie); }
if ($sponsorsArg) { $parts[] = '--sponsors';       $parts[] = escapeshellarg($sponsorsArg); }
if (!empty($org['sportity_kanaal'])) {
    $parts[] = '--sportity-kanaal'; $parts[] = escapeshellarg($org['sportity_kanaal']);
}
$parts[] = '--app-type'; $parts[] = escapeshellarg($appType);
$parts[] = '--lang';     $parts[] = escapeshellarg($lang);

// Coach-poster bevat het coach-app wachtwoord (drempel-mechanisme). Geen
// security want het staat geprint op de poster én iedereen die met de
// coach werkt heeft het toch nodig. Public-poster krijgt 'm niet.
//
// Bonus: het wachtwoord wordt ook in de QR-data verstopt als ?pw=...,
// zodat coaches die met de telefoon scannen direct ingelogd zijn en niet
// hoeven te typen. De zichtbare URL-label op de poster blijft schoon
// (poster_gen.py strippt alles vanaf '?'). Coach-frontend detecteert
// ?pw= bij eerste laad → verify call → opslaan in localStorage.
if ($appType === 'coach') {
    $caStmt = $pdo->prepare("SELECT password FROM coach_app_settings WHERE id = 1 LIMIT 1");
    $caStmt->execute();
    $caPw = (string)($caStmt->fetchColumn() ?: '');
    if ($caPw !== '') {
        $parts[] = '--coach-password'; $parts[] = escapeshellarg($caPw);
        // Append &pw=... aan QR-url. Vervang in de $parts-array de eerder
        // bepaalde --qr-url-waarde door de uitgebreide versie.
        $sep = (strpos($qrUrl, '?') !== false) ? '&' : '?';
        $qrUrlMetPw = $qrUrl . $sep . 'pw=' . rawurlencode($caPw);
        // Zoek terug welke index in $parts de --qr-url-waarde is en
        // overschrijf. Veiliger dan rebuild — we weten dat --qr-url
        // direct na de --output-paar staat.
        for ($i = 0; $i < count($parts); $i++) {
            if ($parts[$i] === '--qr-url' && isset($parts[$i + 1])) {
                $parts[$i + 1] = escapeshellarg($qrUrlMetPw);
                break;
            }
        }
    }
}

// `timeout 25` (Linux coreutils): kill het Python-proces na 25 seconden
// als het hangt. Zonder dit kan een crashende Python (slow stderr, hangende
// import) een PHP-proces ENORM lang bezetten — telt mee voor de iFastNet
// EP-limit en kan bij snelle herhaal-klikken een cascade veroorzaken
// (vermoedelijke oorzaak van de spike op 2026-06-04 17:26).
// 25s ruim onder PHP's max_execution_time van 30s. Bij timeout exit-code 124.
$cmd    = 'timeout 25 ' . implode(' ', $parts) . ' 2>&1';
$output = shell_exec($cmd);

if (!is_file($tmpPdf) || filesize($tmpPdf) < 500) {
    @unlink($tmpPdf);
    // Log naar PHP error_log zodat we bij volgende 500's de Python-error
    // kunnen terugvinden zonder dat de gebruiker hoeft te debuggen.
    @error_log('[poster.php] gen failed for ' . $appType . '/' . $lang
        . ' org=' . $orgId . ' comp=' . $compId
        . ' output=' . substr((string)$output, 0, 500));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'  => 'Poster genereren mislukt.',
        'detail' => $output ?? '(geen output)',
    ]);
    exit;
}

// ── PDF streamen als attachment ─────────────────────────────────────────
$prefix = $appType === 'coach' ? 'coach-poster' : 'poster';
$bestandsnaam = $prefix . '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $org['naam']);
if ($comp)  $bestandsnaam .= '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $comp['name']);
$bestandsnaam .= '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $bestandsnaam . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: private, no-cache');
readfile($tmpPdf);
@unlink($tmpPdf);
