<?php
// ============================================================
//  InlineComp – sla split-groepen op voor één DC
//
//  POST /api/splits.php
//  Body: {
//    "competition_id": "<UUID>",
//    "dc_id":          "<UUID>",
//    "splits": [
//      { "category": "DKA", "split_group": "Dames Kadetten" },
//      { "category": "DKB", "split_group": "Dames Kadetten" },
//      { "category": "HKA", "split_group": "Heren Kadetten" }
//    ]
//  }
//
//  Lege split_group of ontbrekende rijen → DELETE (geen split voor die categorie)
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

$body   = json_decode(file_get_contents('php://input'), true);
$compId = trim($body['competition_id'] ?? '');
$dcId   = trim($body['dc_id']          ?? '');
$splits = $body['splits']              ?? null;

if (!$compId || !$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_id zijn verplicht']);
    exit;
}
if (!is_array($splits)) {
    http_response_code(400);
    echo json_encode(['error' => 'splits ontbreekt']);
    exit;
}

try {
    // ── Blokkeer als er al heats of tijdschema-config bestaan ────────────
    $heatCheck = $pdo->prepare("
        SELECT COUNT(*) FROM heats
        WHERE competition_id = ? AND distance_combination_id = ?
    ");
    $heatCheck->execute([$compId, $dcId]);
    if ((int)$heatCheck->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Splitsen niet mogelijk: er bestaan al startlijsten voor deze categorie. Wis eerst de loting in de Startlijsten-module.']);
        exit;
    }

    $tsCheck = $pdo->prepare("
        SELECT COUNT(*) FROM tijdschema_cat_config tcc
        JOIN competition_tijdschema ct ON ct.id = tcc.tijdschema_id
        WHERE ct.competition_id = ? AND tcc.dc_id = ?
    ");
    $tsCheck->execute([$compId, $dcId]);
    if ((int)$tsCheck->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Splitsen niet mogelijk: deze categorie is al geconfigureerd in het tijdschema. Verwijder het tijdschema eerst.']);
        exit;
    }

    // Verwijder eerst alle bestaande splits voor déze DC
    $pdo->prepare("DELETE FROM dc_splits WHERE dc_id = ? AND competition_id = ?")
        ->execute([$dcId, $compId]);

    // Voeg nieuwe splits in (alleen niet-lege groepen)
    $stmt = $pdo->prepare("
        INSERT INTO dc_splits (competition_id, dc_id, category, split_group)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($splits as $s) {
        $cat   = trim($s['category']   ?? '');
        $groep = trim($s['split_group'] ?? '');
        if (!$cat || !$groep) continue;
        $stmt->execute([$compId, $dcId, $cat, $groep]);
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
