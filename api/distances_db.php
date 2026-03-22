<?php
// GET /api/distances_db.php?dc_id={uuid}
// Geeft de afstanden voor een distance_combination terug vanuit de DB
// (niet via KNSB-proxy, enkel voor geïmporteerde wedstrijden)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dcId = trim($_GET['dc_id'] ?? '');
if (!$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'dc_id ontbreekt']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';

try {
    $stmt = $pdo->prepare("
        SELECT id, number, name, value_meters, discipline
        FROM distances
        WHERE distance_combination_id = ?
        ORDER BY number
    ");
    $stmt->execute([$dcId]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
