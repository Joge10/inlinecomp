<?php
// ============================================================
//  InlineComp – bulk-sync reserve-info naar entries-tabel
//
//  POST /api/reserves_sync.php
//  Body: {
//    "competition_id": "<UUID>",
//    "dc_id":          "<UUID>",
//    "reserves": [
//      { "person_license": "...", "reserve_nr": 1 },
//      ...
//    ],
//    "niet_reserves": [ "license1", "license2", ... ]
//  }
//
//  Voor elke rijder in `reserves`:
//    UPDATE entries SET reserve = :nr
//     WHERE dc + license matcht EN reserve_handmatig_ingezet = 0
//
//  Voor elke rijder in `niet_reserves`:
//    UPDATE entries SET reserve = NULL
//     WHERE dc + license matcht EN reserve_handmatig_ingezet = 0
//
//  reserve_handmatig_ingezet = 1 is beschermd — die rijden zijn door operator
//  ingezet en blijven NULL ongeacht KNSB-state.
//
//  Doel: entries.reserve synchroon houden met de KNSB-feed zonder dat de
//  operator een Importeer/Opslaan-actie hoeft te doen. Frontend roept aan
//  bij elke render van het reserve-paneel.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'startlijsten')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor startlijsten.']);
    exit;
}

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$compId       = trim($body['competition_id'] ?? '');
$dcId         = trim($body['dc_id']          ?? '');
$reserves     = is_array($body['reserves']      ?? null) ? $body['reserves']      : [];
$nietReserves = is_array($body['niet_reserves'] ?? null) ? $body['niet_reserves'] : [];

if (!$compId || !$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_id zijn verplicht']);
    exit;
}

try {
    // Verifieer DC ↔ competition (voorkomt cross-comp injectie)
    $dcCheck = $pdo->prepare(
        "SELECT 1 FROM distance_combinations
          WHERE id = ? AND competition_id = ? LIMIT 1"
    );
    $dcCheck->execute([$dcId, $compId]);
    if (!$dcCheck->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'DC niet gevonden voor deze wedstrijd']);
        exit;
    }

    $pdo->beginTransaction();

    // Reserves → entries.reserve = nr (skip handmatig-ingezet)
    $stmtSetRes = $pdo->prepare("
        UPDATE entries
           SET reserve = ?
         WHERE distance_combination_id = ?
           AND person_license          = ?
           AND reserve_handmatig_ingezet = 0
    ");
    $nGezet = 0;
    foreach ($reserves as $r) {
        $lk = trim($r['person_license'] ?? '');
        $nr = isset($r['reserve_nr']) ? (int)$r['reserve_nr'] : 0;
        if (!$lk || $nr <= 0) continue;
        $stmtSetRes->execute([$nr, $dcId, $lk]);
        $nGezet += $stmtSetRes->rowCount();
    }

    // Niet-reserves → entries.reserve = NULL.
    // Twee gevallen:
    //  1) reserve_handmatig_ingezet = 0 + reserve IS NOT NULL → wis naar NULL
    //     (normale sync, KNSB-feed leidend)
    //  2) reserve_handmatig_ingezet = 1 + reserve IS NULL     → reset óók
    //     reserve_handmatig naar 0. Reden: operator had ingezet, maar KNSB
    //     heeft de rijder ondertussen ook doorgeschoven (R-flag eraf). De
    //     "alleen NULL beschermd"-vlag is dan overbodig en zou alleen leiden
    //     tot een stale "Reeds ingezet"-rij in de UI. KNSB-leidend = vlag op.
    $stmtClrRes = $pdo->prepare("
        UPDATE entries
           SET reserve                   = NULL,
               reserve_handmatig_ingezet = 0
         WHERE distance_combination_id = ?
           AND person_license          = ?
           AND (
                 (reserve_handmatig_ingezet = 0 AND reserve IS NOT NULL)
              OR (reserve_handmatig_ingezet = 1 AND reserve IS NULL)
               )
    ");
    $nGewist = 0;
    foreach ($nietReserves as $lk) {
        $lk = trim((string)$lk);
        if (!$lk) continue;
        $stmtClrRes->execute([$dcId, $lk]);
        $nGewist += $stmtClrRes->rowCount();
    }

    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'gezet'   => $nGezet,
        'gewist'  => $nGewist,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
