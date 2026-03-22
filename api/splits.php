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
