<?php
// ============================================================
//  InlineComp – importeer wedstrijd + beoordeelde deelnemers
//
//  POST /api/import.php
//  Body: {
//    "competition_id": "<UUID>",
//    "categories": [
//      {
//        "dc_id": "<UUID>",
//        "competitors": [
//          {
//            "license_key":   "10219545",
//            "knsb_entry_id": "<UUID>",
//            "entry_status":  1,        // 0=niet bevestigd  1=bevestigd  2=afgemeld  3=afgem.bij org.  4=niet getekend  5=bevestigd bij org.
//            "reserve":       null,     // null of volgnummer 1, 2 …
//            "start_number":  53,
//            "full_name":     "Eline van Leijenhorst",
//            "short_name":    "van Leijenhorst",
//            "gender":        1,        // 0=man  1=vrouw
//            "category":      "DKA",
//            "nationality":   "NED",
//            "club_code":     6821,
//            "club_short":    "RADBOUD",
//            "club_full":     "Radboud Inline-skating",
//            "city":          "Lelystad",
//            "transponder1":  "KS-44038",
//            "transponder2":  null,
//            "tp1_manual":    false,    // true als handmatig gewijzigd
//            "tp2_manual":    false
//          }
//        ]
//      }
//    ]
//  }
//
//  Workflow:
//   1. Wedstrijd-metadata + DC + afstanden ophalen van KNSB (altijd actueel)
//   2. Deelnemers komen uit de beoordeelde POST-body (niet opnieuw van KNSB)
//      → voorbereider heeft al namen, startnummers, transponders gecontroleerd
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── DELETE: verwijder wedstrijd volledig uit de database ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_once __DIR__ . '/../../config_inlinecomp.php';
    $delId = trim($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9\-]{36}$/i', $delId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldig competition ID']);
        exit;
    }
    try {
        // CASCADE op distance_combinations, competitors, transponders etc.
        $pdo->prepare("DELETE FROM competitions WHERE id = ?")->execute([$delId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST of DELETE']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';

$body       = json_decode(file_get_contents('php://input'), true);
$compId     = trim($body['competition_id'] ?? '');
$categories = $body['categories']          ?? null;

if (!$compId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}
if (!is_array($categories)) {
    http_response_code(400);
    echo json_encode(['error' => 'categories ontbreekt — open eerst de vergelijkweergave']);
    exit;
}

$base = 'https://inschrijven.schaatsen.nl/api';

function apiGet(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'Accept: application/json',
        'timeout' => 15,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : json_decode($raw, true);
}

function dt(?string $s): ?string {
    return $s ? substr($s, 0, 19) : null;
}

try {
    $log = [];

    // --------------------------------------------------------
    // 1. Wedstrijd-metadata ophalen en opslaan
    // --------------------------------------------------------
    $comp = apiGet("$base/competitions/$compId");
    if (!$comp) throw new RuntimeException('Kan wedstrijd niet ophalen van KNSB API');

    $venue     = $comp['venue']            ?? null;
    $venueName = $venue['name']            ?? null;
    $venueCity = $venue['address']['city'] ?? null;
    $locatie   = $venueCity
        ? trim(implode(' – ', array_filter([$venueCity, $venueName])))
        : trim(explode("\n", $comp['location'] ?? '')[0]);

    $pdo->prepare("
        INSERT INTO competitions
               (id, name, starts, ends, location, venue_name, venue_city, discipline)
        VALUES (:id, :name, :starts, :ends, :location, :venue_name, :venue_city, :discipline)
        ON DUPLICATE KEY UPDATE
               name       = VALUES(name),       starts     = VALUES(starts),
               ends       = VALUES(ends),        location   = VALUES(location),
               venue_name = VALUES(venue_name),  venue_city = VALUES(venue_city),
               discipline = VALUES(discipline),  updated_at = CURRENT_TIMESTAMP
    ")->execute([
        ':id'         => $compId,
        ':name'       => $comp['name']      ?? '',
        ':starts'     => dt($comp['starts'] ?? null),
        ':ends'       => dt($comp['ends']   ?? null),
        ':location'   => $locatie,
        ':venue_name' => $venueName,
        ':venue_city' => $venueCity,
        ':discipline' => $comp['discipline'] ?? null,
    ]);
    $log[] = "Wedstrijd: {$comp['name']}";

    // --------------------------------------------------------
    // 2. Afstandscombinaties + afstanden van KNSB ophalen
    // --------------------------------------------------------
    $dcs = apiGet("$base/competitions/$compId/distancecombinations");
    if (!$dcs) throw new RuntimeException('Kan categorieën niet ophalen van KNSB');

    $stmtDC = $pdo->prepare("
        INSERT INTO distance_combinations
               (id, competition_id, number, name, category_filter)
        VALUES (:id, :comp_id, :number, :name, :cat_filter)
        ON DUPLICATE KEY UPDATE
               number = VALUES(number), name = VALUES(name),
               category_filter = VALUES(category_filter)
    ");
    $stmtDist = $pdo->prepare("
        INSERT INTO distances
               (id, distance_combination_id, number, name, value_meters, discipline, starts)
        VALUES (:id, :dc_id, :number, :name, :value_meters, :discipline, :starts)
        ON DUPLICATE KEY UPDATE
               number = VALUES(number), name = VALUES(name),
               value_meters = VALUES(value_meters), discipline = VALUES(discipline),
               starts = VALUES(starts)
    ");
    foreach ($dcs as $dc) {
        $stmtDC->execute([
            ':id'         => $dc['id'],
            ':comp_id'    => $compId,
            ':number'     => $dc['number']         ?? null,
            ':name'       => $dc['name']           ?? '',
            ':cat_filter' => $dc['categoryFilter'] ?? null,
        ]);
        foreach ($dc['distances'] ?? [] as $dist) {
            $stmtDist->execute([
                ':id'           => $dist['id'],
                ':dc_id'        => $dc['id'],
                ':number'       => $dist['number']     ?? null,
                ':name'         => $dist['name']       ?? '',
                ':value_meters' => $dist['value']      ?? null,
                ':discipline'   => $dist['discipline'] ?? null,
                ':starts'       => dt($dist['starts']  ?? null),
            ]);
        }
    }
    $log[] = count($dcs) . ' categorieën opgeslagen';

    // --------------------------------------------------------
    // 3. Deelnemers verwerken vanuit beoordeelde POST-data
    // --------------------------------------------------------
    $stmtPers = $pdo->prepare("
        INSERT INTO persons
               (license_key, full_name, short_name, gender, category,
                nationality, start_number, club_code, club_short, club_full, city)
        VALUES (:license_key, :full_name, :short_name, :gender, :category,
                :nationality, :start_number, :club_code, :club_short, :club_full, :city)
        ON DUPLICATE KEY UPDATE
               full_name    = VALUES(full_name),
               short_name   = VALUES(short_name),
               gender       = VALUES(gender),
               category     = VALUES(category),
               nationality  = VALUES(nationality),
               start_number = COALESCE(VALUES(start_number), start_number),
               club_code    = VALUES(club_code),
               club_short   = VALUES(club_short),
               club_full    = VALUES(club_full),
               city         = VALUES(city),
               updated_at   = CURRENT_TIMESTAMP
    ");

    $stmtEntry = $pdo->prepare("
        INSERT INTO entries
               (distance_combination_id, person_license, knsb_entry_id, status)
        VALUES (:dc_id, :person_license, :knsb_entry_id, :status)
        ON DUPLICATE KEY UPDATE
               knsb_entry_id = VALUES(knsb_entry_id),
               status        = VALUES(status)
    ");

    // Transponder: altijd overschrijven met de goedgekeurde waarde
    // source='manual' als de voorbereider de waarde heeft gewijzigd
    $stmtTp = $pdo->prepare("
        INSERT INTO transponders
               (person_license, competition_id, slot, code, source)
        VALUES (:person_license, :comp_id, :slot, :code, :source)
        ON DUPLICATE KEY UPDATE
               code       = VALUES(code),
               source     = VALUES(source),
               updated_at = CURRENT_TIMESTAMP
    ");

    // Optimistic locking: controleer entries_version
    $clientVersion = isset($body['entries_version']) ? (int)$body['entries_version'] : null;
    if ($clientVersion !== null) {
        $vStmt = $pdo->prepare("SELECT entries_version FROM competitions WHERE id = ?");
        $vStmt->execute([$compId]);
        $dbVersion = (int)($vStmt->fetchColumn() ?? 0);
        if ($dbVersion !== $clientVersion) {
            http_response_code(409);
            echo json_encode([
                'error'      => 'conflict',
                'message'    => 'De inschrijvingen zijn ondertussen gewijzigd door iemand anders. Herlaad de pagina om de actuele stand te zien.',
                'db_version' => $dbVersion,
            ]);
            exit;
        }
    }

    $aantalDeelnemers = 0;
    $overgeslagen     = 0;

    foreach ($categories as $cat) {
        $dcId = $cat['dc_id'] ?? null;
        if (!$dcId) continue;

        foreach ($cat['competitors'] ?? [] as $c) {
            $lk = $c['license_key'] ?? null;
            if (!$lk) { $overgeslagen++; continue; }

            // Persoon aanmaken of bijwerken
            $stmtPers->execute([
                ':license_key'  => $lk,
                ':full_name'    => $c['full_name']    ?? '',
                ':short_name'   => $c['short_name']   ?? null,
                ':gender'       => $c['gender']       ?? null,
                ':category'     => $c['category']     ?? null,
                ':nationality'  => $c['nationality']  ?? 'NED',
                ':start_number' => $c['start_number'] ?? null,
                ':club_code'    => $c['club_code']    ?? null,
                ':club_short'   => $c['club_short']   ?? null,
                ':club_full'    => $c['club_full']    ?? null,
                ':city'         => $c['city']         ?? null,
            ]);

            // Inschrijving aanmaken of bijwerken
            $stmtEntry->execute([
                ':dc_id'          => $dcId,
                ':person_license' => $lk,
                ':knsb_entry_id'  => $c['knsb_entry_id'] ?? null,
                ':status'         => $c['entry_status']  ?? 1,
            ]);

            // Transponders slot 1 + 2 (KNSB — read-only in UI, opslaan zoals ontvangen)
            foreach ([1 => 'transponder1', 2 => 'transponder2'] as $slot => $veld) {
                $code = $c[$veld] ?? null;
                if ($code !== null && $code !== '') {
                    $stmtTp->execute([
                        ':person_license' => $lk,
                        ':comp_id'        => $compId,
                        ':slot'           => $slot,
                        ':code'           => $code,
                        ':source'         => 'knsb',
                    ]);
                }
            }

            // Extra transponders (slot >= 3) — organisatie-toegewezen
            // Verwijder eerst alle bestaande extras voor deze rijder+competitie,
            // dan opnieuw invoegen wat de voorbereider heeft opgegeven.
            $pdo->prepare("
                DELETE FROM transponders
                WHERE person_license = ? AND competition_id = ? AND slot >= 3
            ")->execute([$lk, $compId]);

            foreach ($c['transponders_extra'] ?? [] as $i => $code) {
                $code = trim($code ?? '');
                if ($code !== '') {
                    $stmtTp->execute([
                        ':person_license' => $lk,
                        ':comp_id'        => $compId,
                        ':slot'           => $i + 3,
                        ':code'           => $code,
                        ':source'         => 'manual',
                    ]);
                }
            }

            // Actieve transponder (slot 0) — de door de voorbereider geselecteerde code.
            // Altijd opslaan (ook bij "geen"), zodat de bewuste keuze bewaard blijft:
            //   code = 'KS-44038'  → geselecteerde transponder
            //   code = NULL        → expliciet "geen" transponder
            if (array_key_exists('transponder_actief', $c)) {
                $tpActief = ($c['transponder_actief'] !== null && $c['transponder_actief'] !== '')
                    ? trim($c['transponder_actief'])
                    : null;
                $stmtTp->execute([
                    ':person_license' => $lk,
                    ':comp_id'        => $compId,
                    ':slot'           => 0,
                    ':code'           => $tpActief,
                    ':source'         => 'manual',
                ]);
            }

            $aantalDeelnemers++;
        }
    }

    $log[] = "$aantalDeelnemers deelnemers verwerkt"
           . ($overgeslagen ? " ($overgeslagen overgeslagen: geen licentienummer)" : '');

    // Bump entries_version
    $pdo->prepare("UPDATE competitions SET entries_version = entries_version + 1 WHERE id = ?")
        ->execute([$compId]);
    $newVersion = (int)$pdo->query("SELECT entries_version FROM competitions WHERE id = " . $pdo->quote($compId))->fetchColumn();

    echo json_encode([
        'ok'              => true,
        'competition_id'  => $compId,
        'log'             => $log,
        'entries_version' => $newVersion,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
