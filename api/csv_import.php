<?php
// ============================================================
//  InlineComp – CSV-import voor handmatige wedstrijden
//
//  Wizard-style endpoint met meerdere acties:
//
//  POST ?action=parse
//     Body: multipart/form-data met 'csv' file
//     Returns: { headers: [...], preview: [[...], ...], total: N,
//                delimiter: string, encoding: string }
//     Geen DB-actie — alleen file parsen + structuur teruggeven.
//
//  POST ?action=match_preview      (komt later)
//     Body: JSON { competition_id, mapping, rows }
//     Returns: per rij voorgestelde match (link bestaand / nieuw)
//
//  POST ?action=commit              (komt later)
//     Body: JSON { competition_id, mapping, rows, dc_assignments, choices }
//     Inserter: persons + entries.
//
//  Alleen bedoeld voor handmatige wedstrijden (geen KNSB-feed-koppeling).
//  Frontend (import.js + csv_import.js) toont een 4-staps wizard die deze
//  endpoint-acties achter elkaar aanroept.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
// Read-only acties zijn GET (dcs); schrijvende acties zijn POST
// (parse / match_preview / commit). Method-validatie gebeurt verderop
// per actie zelf.
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST of GET']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'beheer')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Strip UTF-8 BOM (EF BB BF) van het begin van een string als die er staat.
 * Excel "Save as CSV UTF-8" zet vaak een BOM die anders in de eerste header
 * blijft hangen ("\xef\xbb\xbfVoornaam" i.p.v. "Voornaam").
 */
function _csvStripBom(string $s): string {
    if (strlen($s) >= 3 && substr($s, 0, 3) === "\xEF\xBB\xBF") {
        return substr($s, 3);
    }
    return $s;
}

/**
 * Probeer de bytes te converteren naar UTF-8. Excel op Windows exporteert
 * vaak in Windows-1252 (ANSI) zonder declaratie, waardoor é/ë/ï etc. kapot
 * gaan als we ze als UTF-8 lezen. We detecteren dat door te checken of de
 * bytes geldige UTF-8 vormen — zo niet → behandel als Windows-1252.
 * Returns: [string $utf8, string $sourceEncoding]
 */
function _csvNaarUtf8(string $bytes): array {
    if (function_exists('mb_check_encoding') && mb_check_encoding($bytes, 'UTF-8')) {
        return [$bytes, 'UTF-8'];
    }
    // Niet-UTF-8 → aanname Windows-1252 (Excel-default op NL Windows)
    if (function_exists('mb_convert_encoding')) {
        $utf8 = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        return [$utf8, 'Windows-1252'];
    }
    // Fallback: ruwe bytes laten staan en hopen
    return [$bytes, 'unknown'];
}

/**
 * Detecteer de delimiter (komma, puntkomma of tab) op basis van de eerste
 * regel: kies de delimiter die de meeste kolommen oplevert. Excel NL
 * gebruikt vaak ';' bij "Opslaan als CSV", US/EN-systemen ','.
 */
function _csvDetecteerDelimiter(string $eersteRegel): string {
    $kandidaten = [',', ';', "\t"];
    $best       = ',';
    $besteAantal = 0;
    foreach ($kandidaten as $d) {
        // str_getcsv telt fields ook bij quoted-content correct
        $aantal = count(str_getcsv($eersteRegel, $d));
        if ($aantal > $besteAantal) {
            $besteAantal = $aantal;
            $best        = $d;
        }
    }
    return $best;
}

/**
 * Parse een UTF-8 CSV-string naar rows-array. Eerste rij = headers.
 * Lege rijen aan het einde worden gesnoeid. Returns:
 *   [ ['header1','header2',...], ['val1','val2',...], ... ]
 */
function _csvParseRows(string $csvUtf8, string $delimiter): array {
    // Newlines normaliseren (\r\n → \n)
    $csvUtf8 = str_replace(["\r\n", "\r"], "\n", $csvUtf8);
    $regels  = explode("\n", $csvUtf8);
    // Trailing lege regels weg
    while (count($regels) && trim(end($regels)) === '') array_pop($regels);
    $rows = [];
    foreach ($regels as $regel) {
        if ($regel === '') continue;
        $rows[] = str_getcsv($regel, $delimiter);
    }
    return $rows;
}

// ── Actie: parse ────────────────────────────────────────────────────────────
//   Ontvang multipart upload met 'csv' file, detecteer encoding + delimiter,
//   parse en stuur structuur + preview terug. Geen DB-bewerkingen.
if ($action === 'parse') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'parse vereist POST']);
        exit;
    }
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        $errCode = $_FILES['csv']['error'] ?? -1;
        echo json_encode(['error' => "Upload mislukt (code $errCode)"]);
        exit;
    }
    $tmpPath = $_FILES['csv']['tmp_name'] ?? '';
    $size    = (int)($_FILES['csv']['size'] ?? 0);
    if ($size <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Leeg bestand']);
        exit;
    }
    if ($size > 5 * 1024 * 1024) {  // 5 MB hard limiet
        http_response_code(413);
        echo json_encode(['error' => 'Bestand te groot (max 5 MB)']);
        exit;
    }

    $bytes = file_get_contents($tmpPath);
    if ($bytes === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Bestand niet leesbaar']);
        exit;
    }
    $bytes = _csvStripBom($bytes);
    [$utf8, $sourceEncoding] = _csvNaarUtf8($bytes);

    // Delimiter detecteren op basis van eerste niet-lege regel
    $eersteNL = strpos($utf8, "\n");
    $eersteRegel = $eersteNL !== false ? substr($utf8, 0, $eersteNL) : $utf8;
    $delimiter = _csvDetecteerDelimiter(str_replace("\r", '', $eersteRegel));

    $rows = _csvParseRows($utf8, $delimiter);
    if (count($rows) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Te weinig rijen (min. 1 header + 1 data-rij)']);
        exit;
    }

    $headers = array_map(fn($h) => trim((string)$h), array_shift($rows));
    // Lege achterste kolommen wegfilteren (Excel laat soms trailing ; staan)
    while (count($headers) && $headers[count($headers) - 1] === '') array_pop($headers);
    $nKol = count($headers);

    // Data-rijen naar zelfde lengte als headers (pad met '' bij korter)
    $dataRijen = array_map(function($r) use ($nKol) {
        $r = array_slice($r, 0, $nKol);
        while (count($r) < $nKol) $r[] = '';
        return array_map(fn($v) => trim((string)$v), $r);
    }, $rows);

    // Preview = eerste 5 data-rijen voor de UI; alle rijen worden ook
    // teruggegeven zodat de frontend ze in state kan houden voor stap 2-4
    // (mapping → DC → match) zonder telkens opnieuw te parsen.
    $preview = array_slice($dataRijen, 0, 5);

    echo json_encode([
        'ok'        => true,
        'headers'   => $headers,
        'preview'   => $preview,
        'rows'      => $dataRijen,
        'total'     => count($dataRijen),
        'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
        'encoding'  => $sourceEncoding,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Actie: dcs ─────────────────────────────────────────────────────────────
//   Geeft alle DCs + hun afstanden van de geselecteerde wedstrijd terug
//   zodat de wizard in stap 3 (DC-toewijzing) de dropdowns kan vullen.
//   GET parameter: competition_id
if ($action === 'dcs') {
    $compId = trim($_GET['competition_id'] ?? '');
    if ($compId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id ontbreekt']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $compId);

    // DCs ophalen (inclusief merge_label voor leesbaarheid)
    $stmt = $pdo->prepare("
        SELECT id, name, number, merge_label
        FROM distance_combinations
        WHERE competition_id = ?
        ORDER BY number, name
    ");
    $stmt->execute([$compId]);
    $dcs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$dcs) {
        echo json_encode(['ok' => true, 'dcs' => [], 'unieke_afstanden' => []]);
        exit;
    }

    // Afstanden per DC
    $dcIds = array_column($dcs, 'id');
    $ph    = implode(',', array_fill(0, count($dcIds), '?'));
    $stmt  = $pdo->prepare("
        SELECT distance_combination_id AS dc_id, id, name, value_meters, race_type
        FROM distances
        WHERE distance_combination_id IN ($ph)
        ORDER BY number, name
    ");
    $stmt->execute($dcIds);
    $distancesPerDc = [];
    $unieke         = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $distancesPerDc[$d['dc_id']][] = [
            'id'           => $d['id'],
            'name'         => $d['name'],
            'value_meters' => $d['value_meters'] !== null ? (int)$d['value_meters'] : null,
            'race_type'    => $d['race_type'],
        ];
        $key = $d['name'];
        if (!isset($unieke[$key])) {
            $unieke[$key] = [
                'name'         => $d['name'],
                'value_meters' => $d['value_meters'] !== null ? (int)$d['value_meters'] : null,
                'race_type'    => $d['race_type'],
            ];
        }
    }

    $result = array_map(function($dc) use ($distancesPerDc) {
        return [
            'id'           => $dc['id'],
            'name'         => $dc['name'],
            'merge_label'  => $dc['merge_label'],
            'display_name' => $dc['merge_label'] ?: $dc['name'],
            'distances'    => $distancesPerDc[$dc['id']] ?? [],
        ];
    }, $dcs);

    echo json_encode([
        'ok'               => true,
        'dcs'              => $result,
        'unieke_afstanden' => array_values($unieke),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// match_preview en commit komen in volgende stappen.

http_response_code(400);
echo json_encode(['error' => "Onbekende action: " . htmlspecialchars($action)]);
