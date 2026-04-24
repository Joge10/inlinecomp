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

// ── Wedstrijd ophalen (optioneel) ────────────────────────────────────────
$comp = null;
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
}

// ── Absolute paden voor logo's ──────────────────────────────────────────
$webroot = realpath(__DIR__ . '/..');
function absPad(?string $rel) {
    global $webroot;
    if (!$rel) return '';
    $p = $webroot . DIRECTORY_SEPARATOR . ltrim($rel, '/');
    return is_file($p) ? $p : '';
}

$orgLogo      = absPad($org['logo_path']);
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
$baseUrl = 'https://inlineresults.devriesen.com/public/';
$qrUrl   = $comp ? ($baseUrl . '?comp=' . $comp['id']) : $baseUrl;

// ── Datum-string ───────────────────────────────────────────────────────
$compDatum = '';
if ($comp && !empty($comp['starts'])) {
    $maanden = ['01'=>'januari','02'=>'februari','03'=>'maart','04'=>'april',
                '05'=>'mei','06'=>'juni','07'=>'juli','08'=>'augustus',
                '09'=>'september','10'=>'oktober','11'=>'november','12'=>'december'];
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
if ($comp)        { $parts[] = '--comp-naam';      $parts[] = escapeshellarg($comp['name']); }
if ($compDatum)   { $parts[] = '--comp-datum';     $parts[] = escapeshellarg($compDatum); }
if ($compLocatie) { $parts[] = '--comp-locatie';   $parts[] = escapeshellarg($compLocatie); }
if ($sponsorsArg) { $parts[] = '--sponsors';       $parts[] = escapeshellarg($sponsorsArg); }
if (!empty($org['sportity_kanaal'])) {
    $parts[] = '--sportity-kanaal'; $parts[] = escapeshellarg($org['sportity_kanaal']);
}

$cmd    = implode(' ', $parts) . ' 2>&1';
$output = shell_exec($cmd);

if (!is_file($tmpPdf) || filesize($tmpPdf) < 500) {
    @unlink($tmpPdf);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'  => 'Poster genereren mislukt.',
        'detail' => $output ?? '(geen output)',
    ]);
    exit;
}

// ── PDF streamen als attachment ─────────────────────────────────────────
$bestandsnaam = 'poster-' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $org['naam']);
if ($comp)  $bestandsnaam .= '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $comp['name']);
$bestandsnaam .= '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $bestandsnaam . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: private, no-cache');
readfile($tmpPdf);
@unlink($tmpPdf);
