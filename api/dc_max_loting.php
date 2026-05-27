<?php
// ============================================================
//  InlineComp – handmatige max-in-loting override per DC
//
//  POST /api/dc_max_loting.php
//  Body: {
//    "dc_id":          "...",   // distance_combinations.id
//    "max_in_loting":  20       // integer >= 0, of null om terug te zetten naar auto
//  }
//
//  Response: { ok: true, dc_id, max_in_loting }
//
//  Gebruikt door js/import.js → reserve-paneel ✏️-knop naast 'van max XX'.
//  NULL = terug naar auto-berekening (= aantal niet-reserves uit KNSB-feed).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// Schrijfrechten: zelfde groep die ook entries/transponders mag wijzigen
// (owner/admin/timer/planner). Reserve-paneel is een import-tab feature.
$magSchrijven = in_array($_authUser['role'] ?? '',
    ['owner', 'admin', 'timer', 'planner'], true);
if (!$magSchrijven) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor max-in-loting.']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$dcId  = trim($body['dc_id'] ?? '');
$max   = $body['max_in_loting'] ?? null;

if ($dcId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'dc_id ontbreekt']);
    exit;
}

// Normaliseer waarde: NULL = wissen / terug naar auto, anders int >= 0
if ($max === null || $max === '' || $max === false) {
    $maxNorm = null;
} else {
    if (!is_numeric($max) || (int)$max < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'max_in_loting moet null of een geheel getal >= 0 zijn']);
        exit;
    }
    $maxNorm = (int)$max;
    // Sanity cap — een redelijk veld zit ergens onder 200 rijders. Boven
    // dat aantal is het bijna zeker een typo (bv. iemand typt 220 ipv 22).
    if ($maxNorm > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'max_in_loting > 200 is vrijwel zeker een typo']);
        exit;
    }
}

try {
    $stmt = $pdo->prepare("
        UPDATE distance_combinations
        SET max_in_loting = ?
        WHERE id = ?
    ");
    $stmt->execute([$maxNorm, $dcId]);

    if ($stmt->rowCount() === 0) {
        // Geen rij geüpdatet — kan zijn omdat DC niet bestaat, of omdat
        // de waarde al gelijk was. Check expliciet welke van de twee.
        $check = $pdo->prepare("SELECT 1 FROM distance_combinations WHERE id = ?");
        $check->execute([$dcId]);
        if (!$check->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'DC niet gevonden']);
            exit;
        }
        // Waarde was al gelijk — ook OK, response is hetzelfde
    }

    echo json_encode([
        'ok'            => true,
        'dc_id'         => $dcId,
        'max_in_loting' => $maxNorm,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
