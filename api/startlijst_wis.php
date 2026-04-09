<?php
// ============================================================
//  InlineComp – verwijder opgeslagen startlijst (ronde 1)
//
//  POST /api/startlijst_wis.php
//    competition_id, dc_ids, distance_id, category_filter
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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
$dcIdsRaw     = trim($body['dc_ids']         ?? '');
$dcIds        = array_values(array_filter(array_map('trim', explode(',', $dcIdsRaw))));
$distId       = trim($body['distance_id']    ?? '');
$catFilterRaw = trim($body['category_filter'] ?? '');
$splitGroup   = $catFilterRaw ?: null;

if (!$compId || !$dcIds) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_ids zijn verplicht']);
    exit;
}

$primaryDcId = $dcIds[0];

try {
    // Verwijder alle heats voor deze categorie/afstand (ronde 1 + eventuele ghost heats)
    $stmt = $pdo->prepare("
        DELETE FROM heats
        WHERE competition_id          = ?
          AND distance_combination_id = ?
          AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
          AND (split_group = ? OR (split_group IS NULL AND ? IS NULL))
    ");
    $stmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup]);

    echo json_encode(['ok' => true, 'verwijderd' => $stmt->rowCount()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
