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

// ── Actie: match_preview ───────────────────────────────────────────────────
//   Per CSV-rij een match-cascade tegen persons-tabel uitvoeren en
//   kandidaten teruggeven. Geen DB-schrijven.
//
//   POST body (JSON): { competition_id, mapping, rows, cat_mapping }
//     - mapping: { kolIdx: targetType } uit stap 2
//     - rows:    array van data-rijen (string-arrays)
//     - cat_mapping: { 'Pupil|M': 'HP1', ... } uit stap 3
//
//   Returns: { ok, matches: [...] }
//     matches: per rij { row_idx, tier, candidates, recommended }
//       - tier: 1 (startnr exact), 2 (naam+club), 3 (alleen naam, meerdere),
//               4 (geen match → nieuw)
//       - candidates: [{ license_key, full_name, club, birth_year }]
//       - recommended: license_key of '__new__' (te accepteren op default)
if ($action === 'match_preview') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'match_preview vereist POST']);
        exit;
    }
    $body       = json_decode(file_get_contents('php://input'), true);
    $compId     = trim($body['competition_id'] ?? '');
    $mapping    = $body['mapping']    ?? [];
    $rows       = $body['rows']       ?? [];
    if (!$compId) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id ontbreekt']);
        exit;
    }
    if (!is_array($rows) || !$rows) {
        http_response_code(400);
        echo json_encode(['error' => 'rows ontbreekt']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $compId);

    // Helper: zoek de kolom-index voor een target-type uit de mapping
    $kolVoor = function(string $target) use ($mapping) {
        foreach ($mapping as $kol => $t) {
            if ($t === $target) return (int)$kol;
        }
        return null;
    };
    $kolNaamFull   = $kolVoor('name_full');
    $kolNaamFirst  = $kolVoor('name_first');
    $kolNaamTussen = $kolVoor('name_tussen');
    $kolNaamLast   = $kolVoor('name_last');
    $kolStartnr    = $kolVoor('start_number');
    $kolClub       = $kolVoor('club_short') ?? $kolVoor('club_full') ?? $kolVoor('club_of_sponsor');

    // Bouw per rij de gerealiseerde naam + club + startnr
    $rijData = [];
    foreach ($rows as $i => $r) {
        $naam = '';
        if ($kolNaamFull !== null && !empty($r[$kolNaamFull])) {
            $naam = trim($r[$kolNaamFull]);
        } elseif ($kolNaamFirst !== null && $kolNaamLast !== null) {
            $delen = [
                trim($r[$kolNaamFirst] ?? ''),
                $kolNaamTussen !== null ? trim($r[$kolNaamTussen] ?? '') : '',
                trim($r[$kolNaamLast] ?? ''),
            ];
            $naam = implode(' ', array_filter($delen, fn($x) => $x !== ''));
        }
        $club    = $kolClub    !== null ? trim($r[$kolClub]    ?? '') : '';
        $startnr = $kolStartnr !== null ? trim($r[$kolStartnr] ?? '') : '';
        $rijData[$i] = [
            'naam'    => $naam,
            'club'    => $club,
            'startnr' => $startnr,
        ];
    }

    // Verzamel alle unieke namen + startnummers + clubs voor bulk-queries.
    $alleNamen    = array_unique(array_filter(array_column($rijData, 'naam')));
    $alleStartnrs = array_filter(array_column($rijData, 'startnr'),
                                 fn($s) => $s !== '' && ctype_digit($s));

    // Bulk-fetch potentiële matches per categorie
    $byStartnr = [];   // start_number → [person row]
    if ($alleStartnrs) {
        $ph = implode(',', array_fill(0, count($alleStartnrs), '?'));
        $stmt = $pdo->prepare("
            SELECT license_key, full_name, short_name, birth_year, gender,
                   start_number, club_short, club_full, extern
            FROM persons
            WHERE start_number IN ($ph)
              AND anonymized_at IS NULL
              AND pending_source IS NULL
        ");
        $stmt->execute(array_values($alleStartnrs));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $byStartnr[(int)$p['start_number']][] = $p;
        }
    }

    $byNaam = [];      // genormaliseerde-naam → [person row]
    if ($alleNamen) {
        // Voor naam-match: case-insensitive vergelijking, met short_name als
        // tweede kans. Geen LIKE wildcards — alleen exact-match.
        $ph = implode(',', array_fill(0, count($alleNamen), '?'));
        $stmt = $pdo->prepare("
            SELECT license_key, full_name, short_name, birth_year, gender,
                   start_number, club_short, club_full, extern
            FROM persons
            WHERE (LOWER(full_name) IN ($ph) OR LOWER(short_name) IN ($ph))
              AND anonymized_at IS NULL
              AND pending_source IS NULL
        ");
        $lowerNamen = array_map('mb_strtolower', $alleNamen);
        $stmt->execute(array_merge($lowerNamen, $lowerNamen));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $sleutel = mb_strtolower($p['full_name']);
            $byNaam[$sleutel][] = $p;
            if ($p['short_name']) {
                $sleutel2 = mb_strtolower($p['short_name']);
                if ($sleutel2 !== $sleutel) $byNaam[$sleutel2][] = $p;
            }
        }
    }

    // Match-cascade per rij
    $matches = [];
    foreach ($rijData as $i => $rd) {
        $kandidaten = [];
        $tier       = 4;   // default: geen match

        // Tier 1: KNSB-startnummer exact
        if ($rd['startnr'] !== '' && isset($byStartnr[(int)$rd['startnr']])) {
            $kandidaten = $byStartnr[(int)$rd['startnr']];
            $tier       = 1;
        }
        // Tier 2/3: naam-match
        if (!$kandidaten && $rd['naam']) {
            $sleutel = mb_strtolower($rd['naam']);
            $potentieel = $byNaam[$sleutel] ?? [];
            if ($potentieel) {
                // Tier 2 als één van de matches dezelfde club heeft
                $clubLow = mb_strtolower($rd['club']);
                if ($clubLow !== '') {
                    foreach ($potentieel as $p) {
                        if (mb_strtolower($p['club_short'] ?? '') === $clubLow
                         || mb_strtolower($p['club_full'] ?? '')  === $clubLow) {
                            $kandidaten = [$p];
                            $tier       = 2;
                            break;
                        }
                    }
                }
                if (!$kandidaten) {
                    $kandidaten = $potentieel;
                    $tier       = count($potentieel) === 1 ? 2 : 3;
                }
            }
        }

        // Format kandidaten voor frontend
        $candFmt = array_map(fn($p) => [
            'license_key' => $p['license_key'],
            'full_name'   => $p['full_name'],
            'club'        => $p['club_short'] ?: $p['club_full'] ?: '',
            'birth_year'  => $p['birth_year'] !== null ? (int)$p['birth_year'] : null,
            'start_number'=> $p['start_number'] !== null ? (int)$p['start_number'] : null,
            'extern'      => (int)($p['extern'] ?? 0) === 1,
        ], $kandidaten);

        // Aanbevolen actie:
        //   tier 1 / 2 → link aan de eerste (sterke match)
        //   tier 3 → operator moet kiezen, default eerste
        //   tier 4 → '__new__' (nieuwe externe persoon)
        $aanbevolen = $tier <= 3 && $candFmt ? $candFmt[0]['license_key'] : '__new__';

        $matches[] = [
            'row_idx'      => $i,
            'naam'         => $rd['naam'],
            'club'         => $rd['club'],
            'startnr'      => $rd['startnr'],
            'tier'         => $tier,
            'candidates'   => $candFmt,
            'recommended'  => $aanbevolen,
        ];
    }

    echo json_encode([
        'ok'      => true,
        'matches' => $matches,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Actie: zoek_personen ───────────────────────────────────────────────────
//   Vrije zoektocht in persons-tabel voor handmatige koppeling bij twijfel-
//   gevallen. Operator gebruikt dit als de auto-match faalt (rood) of de
//   match-cascade niet de juiste persoon vindt.
//
//   GET parameter: q (zoekterm, min. 2 chars)
//   Returns: { results: [{ license_key, full_name, club, birth_year, ... }] }
if ($action === 'zoek_personen') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }
    // LIKE-match op full_name en short_name, met % aan beide kanten.
    // Geen scope-filter — operator mag zoeken in de hele rijders-pool (anders
    // mis je rijders die in andere wedstrijden zaten).
    $pat = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)) . '%';
    $stmt = $pdo->prepare("
        SELECT license_key, full_name, short_name, birth_year, gender,
               start_number, club_short, club_full, extern
        FROM persons
        WHERE (LOWER(full_name) LIKE ? OR LOWER(short_name) LIKE ?)
          AND anonymized_at IS NULL
          AND pending_source IS NULL
        ORDER BY full_name
        LIMIT 20
    ");
    $stmt->execute([$pat, $pat]);
    $results = array_map(fn($p) => [
        'license_key'  => $p['license_key'],
        'full_name'    => $p['full_name'],
        'club'         => $p['club_short'] ?: $p['club_full'] ?: '',
        'birth_year'   => $p['birth_year']   !== null ? (int)$p['birth_year']   : null,
        'start_number' => $p['start_number'] !== null ? (int)$p['start_number'] : null,
        'extern'       => (int)($p['extern'] ?? 0) === 1,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Actie: commit ──────────────────────────────────────────────────────────
//   Definitieve import: persons aanmaken/koppelen + entries inserten in
//   één atomaire transactie. ON DUPLICATE KEY UPDATE op entries maakt
//   herimport veilig (geen duplicaat-errors voor al bestaande inschrijvingen).
//
//   POST body (JSON):
//     competition_id, mapping, rows, cat_mapping, afstand_per_kol,
//     dc_toewijzing, match_acties
//
//   Returns: { ok, stats: { nieuw, gelinked, skipped, entries_nieuw,
//                          entries_upgedate, rijen_zonder_dc, errors } }
if ($action === 'commit') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'commit vereist POST']);
        exit;
    }
    $body         = json_decode(file_get_contents('php://input'), true);
    $compId       = trim($body['competition_id'] ?? '');
    $mapping      = $body['mapping']         ?? [];
    $rows         = $body['rows']            ?? [];
    $catMapping   = $body['cat_mapping']     ?? [];
    $afstandPerKol= $body['afstand_per_kol'] ?? [];
    $dcToewijzing = $body['dc_toewijzing']   ?? [];
    $matchActies  = $body['match_acties']    ?? [];

    if (!$compId || !$rows) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id of rows ontbreekt']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $compId);

    // Kolom-indices uit mapping
    $kolVoor = function(string $target) use ($mapping) {
        foreach ($mapping as $kol => $t) {
            if ($t === $target) return (int)$kol;
        }
        return null;
    };
    $kIs = [
        'name_full'    => $kolVoor('name_full'),
        'name_first'   => $kolVoor('name_first'),
        'name_tussen'  => $kolVoor('name_tussen'),
        'name_last'    => $kolVoor('name_last'),
        'gender'       => $kolVoor('gender'),
        'nationality'  => $kolVoor('nationality'),
        'birth_year'   => $kolVoor('birth_year'),
        'start_number' => $kolVoor('start_number'),
        'cat_groep'    => $kolVoor('cat_groep'),
        'club_short'   => $kolVoor('club_short'),
        'club_full'    => $kolVoor('club_full'),
        'club_sponsor' => $kolVoor('club_of_sponsor'),
        'sponsor'      => $kolVoor('sponsor'),
    ];

    // Helper: is een dc-marker waarde "true"? (x, X, 1, ja, yes)
    $isTrueMarker = function($v): bool {
        $s = trim((string)$v);
        if ($s === '') return false;
        $low = strtolower($s);
        return in_array($low, ['x', '1', 'ja', 'yes', 'true', 'y'], true);
    };

    // Helper: cat-groep normaliseren
    $normCat = function($v): ?string {
        $s = strtolower(trim((string)$v));
        if ($s === '') return null;
        if (str_starts_with($s, 'pupil'))  return 'Pupil';
        if (str_starts_with($s, 'cadet'))  return 'Cadet';
        if (str_starts_with($s, 'junior')) return 'Junior';
        if (str_starts_with($s, 'youth'))  return 'Youth';
        if (str_starts_with($s, 'senior')) return 'Senior';
        return null;
    };
    $normGender = function($v): ?string {
        $s = strtoupper(trim((string)$v));
        if ($s === 'M' || $s === 'H')                return 'M';
        if ($s === 'W' || $s === 'V' || $s === 'F') return 'W';
        return null;
    };

    // Helper: bouw naam-velden uit kolommen
    $bouwNamen = function(array $r) use ($kIs): array {
        $voornaam    = $kIs['name_first']  !== null ? trim($r[$kIs['name_first']]  ?? '') : '';
        $tussen      = $kIs['name_tussen'] !== null ? trim($r[$kIs['name_tussen']] ?? '') : '';
        $achternaam  = $kIs['name_last']   !== null ? trim($r[$kIs['name_last']]   ?? '') : '';
        $volledig    = $kIs['name_full']   !== null ? trim($r[$kIs['name_full']]   ?? '') : '';
        if ($volledig === '') {
            $delen = array_filter([$voornaam, $tussen, $achternaam], fn($x) => $x !== '');
            $volledig = implode(' ', $delen);
        }
        // short_name = voornaam + achternaam (zonder tussenvoegsel)
        $short = trim($voornaam . ' ' . $achternaam);
        if ($short === '' || $short === ' ') $short = $volledig;
        return ['full' => $volledig, 'short' => $short];
    };

    // Volgende auto-startnummer (1000+) bij MAX(start_number) ophalen
    $stmt = $pdo->prepare(
        "SELECT GREATEST(IFNULL(MAX(start_number), 999), 999) + 1 AS next_nr FROM persons"
    );
    $stmt->execute();
    $nextStartNr = (int)$stmt->fetchColumn();
    if ($nextStartNr < 1000) $nextStartNr = 1000;

    $stats = [
        'nieuw'           => 0,
        'gelinked'        => 0,
        'skipped'         => 0,
        'entries_nieuw'   => 0,
        'entries_upgedate'=> 0,
        'rijen_zonder_dc' => [],
        'errors'          => [],
    ];

    try {
        $pdo->beginTransaction();

        // Prepared statements (eenmalig opbouwen voor performance)
        $insPerson = $pdo->prepare("
            INSERT INTO persons
                (license_key, full_name, short_name, birth_year, gender,
                 category, nationality, start_number,
                 club_short, club_full, sponsor,
                 extern, extern_federatie)
            VALUES (:lk, :fn, :sn, :by, :gd,
                    :cat, :nat, :snr,
                    :cls, :clf, :spn,
                    1, :fed)
        ");
        $insEntry = $pdo->prepare("
            INSERT INTO entries (distance_combination_id, person_license, status)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE status = 1
        ");

        foreach ($rows as $i => $r) {
            $actie = $matchActies[$i] ?? '__skip__';
            if ($actie === '__skip__') {
                $stats['skipped']++;
                continue;
            }

            $cat    = $kIs['cat_groep'] !== null ? $normCat($r[$kIs['cat_groep']]) : null;
            $gender = $kIs['gender']    !== null ? $normGender($r[$kIs['gender']])  : null;
            if (!$cat || !$gender) {
                $stats['errors'][] = "Rij " . ($i + 1) . ": cat of geslacht onbekend";
                continue;
            }
            $catCode = $catMapping[$cat . '|' . $gender] ?? null;
            if (!$catCode) {
                $stats['errors'][] = "Rij " . ($i + 1) . ": geen cat-code voor $cat|$gender";
                continue;
            }

            // Persoon bepalen
            if ($actie === '__new__') {
                $namen   = $bouwNamen($r);
                $birthY  = $kIs['birth_year']   !== null ? (int)($r[$kIs['birth_year']] ?? 0) : 0;
                $nation  = $kIs['nationality']  !== null ? strtoupper(substr(trim($r[$kIs['nationality']] ?? ''), 0, 3)) : '';
                // Club: club_of_sponsor vult zowel club als sponsor met dezelfde waarde
                $club    = '';
                $sponsor = '';
                if ($kIs['club_sponsor'] !== null) {
                    $val     = trim($r[$kIs['club_sponsor']] ?? '');
                    $club    = $val;
                    $sponsor = $val;
                } else {
                    if ($kIs['club_short'] !== null) $club = trim($r[$kIs['club_short']] ?? '');
                    if ($kIs['sponsor']    !== null) $sponsor = trim($r[$kIs['sponsor']]    ?? '');
                }
                $clubFull = $kIs['club_full'] !== null ? trim($r[$kIs['club_full']] ?? '') : $club;

                // Startnummer: CSV-waarde indien aanwezig (en numeriek), anders auto
                $csvSnr = $kIs['start_number'] !== null ? trim($r[$kIs['start_number']] ?? '') : '';
                $snr = ctype_digit($csvSnr) ? (int)$csvSnr : $nextStartNr++;

                $licenseKey = 'x-' . bin2hex(random_bytes(6));
                $fed = ($nation && $nation !== 'NED' && $nation !== 'NLD') ? $nation : null;

                try {
                    $insPerson->execute([
                        ':lk'  => $licenseKey,
                        ':fn'  => $namen['full'],
                        ':sn'  => $namen['short'],
                        ':by'  => $birthY ?: null,
                        ':gd'  => $gender === 'M' ? 0 : 1,
                        ':cat' => $catCode,
                        ':nat' => $nation ?: 'NED',
                        ':snr' => $snr,
                        ':cls' => substr($club, 0, 20),
                        ':clf' => $clubFull ?: $club,
                        ':spn' => $sponsor ?: null,
                        ':fed' => $fed,
                    ]);
                    $stats['nieuw']++;
                } catch (Throwable $e) {
                    $stats['errors'][] = "Rij " . ($i + 1) . " (" . $namen['full'] . "): " . $e->getMessage();
                    continue;
                }
            } else {
                // Bestaande persoon: license_key is de actie-waarde
                $licenseKey = $actie;
                $stats['gelinked']++;
            }

            // Entries per dc_marker-kolom met "x"
            foreach ($afstandPerKol as $kol => $afstandNaam) {
                $kolIdx = (int)$kol;
                $val    = $r[$kolIdx] ?? '';
                if (!$isTrueMarker($val)) continue;

                $dcKey = $catCode . '|' . $afstandNaam;
                $dcId  = $dcToewijzing[$dcKey] ?? null;
                if (!$dcId) {
                    if (!in_array($i, $stats['rijen_zonder_dc'], true)) {
                        $stats['rijen_zonder_dc'][] = $i;
                    }
                    continue;
                }

                try {
                    $insEntry->execute([$dcId, $licenseKey]);
                    // rowCount = 1 bij INSERT, 2 bij UPDATE (MySQL ON DUPLICATE KEY UPDATE)
                    if ($insEntry->rowCount() === 1) $stats['entries_nieuw']++;
                    else                              $stats['entries_upgedate']++;
                } catch (Throwable $e) {
                    $stats['errors'][] = "Rij " . ($i + 1) . " DC $catCode|$afstandNaam: " . $e->getMessage();
                }
            }
        }

        $pdo->commit();

        echo json_encode([
            'ok'    => true,
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Import mislukt: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => "Onbekende action: " . htmlspecialchars($action)]);
