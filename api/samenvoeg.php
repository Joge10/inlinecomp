<?php
// ============================================================
//  InlineComp – sla merge-groepen op voor categorieën
//
//  POST /api/samenvoeg.php
//  Body: {
//    "competition_id": "<UUID>",
//    "merges": [
//      { "dc_id": "<UUID>", "merge_group": "Junior" },
//      { "dc_id": "<UUID>", "merge_group": "Junior" },
//      { "dc_id": "<UUID>", "merge_group": null }    ← eigen groep
//    ]
//  }
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
$merges = $body['merges'] ?? null;

if (!$compId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}
if (!is_array($merges)) {
    http_response_code(400);
    echo json_encode(['error' => 'merges ontbreekt']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE distance_combinations
        SET merge_group = ?, merge_label = ?
        WHERE id = ? AND competition_id = ?
    ");

    foreach ($merges as $m) {
        $dcId  = $m['dc_id']       ?? null;
        $groep = $m['merge_group'] ?? null;
        $label = $m['merge_label'] ?? null;
        if (!$dcId) continue;

        // Lege string → NULL (eigen groep, geen samenvoeging)
        $groepVal = ($groep !== null && trim($groep) !== '') ? trim($groep) : null;
        $labelVal = ($label !== null && trim($label) !== '') ? trim($label) : null;

        $stmt->execute([$groepVal, $labelVal, $dcId, $compId]);
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
