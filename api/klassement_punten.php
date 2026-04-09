<?php
// ============================================================
//  InlineComp – Klassement puntenoverschrijving opslaan
//
//  POST JSON body:
//  {
//    "competition_id": "...",
//    "dc_id":          "...",
//    "dc_naam":        "...",
//    "aanpassingen": [
//      {
//        "person_license": "...",
//        "distance_id":    "...",
//        "distance_naam":  "...",
//        "punten":         12.0
//      }
//    ]
//  }
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
requireAuth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Alleen POST toegestaan']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$compId   = trim($body['competition_id'] ?? '');
$dcId     = trim($body['dc_id']         ?? '');
$dcNaam   = trim($body['dc_naam']       ?? '');
$items    = $body['aanpassingen']        ?? [];

if (!$compId || !$dcId || !is_array($items) || empty($items)) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id, dc_id en aanpassingen zijn verplicht']);
    exit;
}

try {
    // Haal wedstrijdnaam en -datum op voor gedenormaliseerde opslag
    $compStmt = $pdo->prepare("SELECT name, starts FROM competitions WHERE id = ?");
    $compStmt->execute([$compId]);
    $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
    $compNaam  = $comp['name']   ?? '';
    $compDatum = $comp['starts'] ? date('Y-m-d', strtotime($comp['starts'])) : null;

    // Update alleen het punten-veld van bestaande uitslag_afstand records.
    // Geen INSERT — correcties zijn alleen zinvol als er al een uitslag vastgelegd is.
    $updateStmt = $pdo->prepare("
        UPDATE uitslag_afstand
        SET punten = ?, vastgelegd_at = CURRENT_TIMESTAMP
        WHERE competition_id             = ?
          AND distance_combination_id    = ?
          AND distance_id                = ?
          AND person_license             = ?
    ");

    $pdo->beginTransaction();
    $opgeslagen = 0;

    foreach ($items as $item) {
        $lic      = trim($item['person_license'] ?? '');
        $distId   = trim($item['distance_id']    ?? '');
        $punten   = isset($item['punten']) ? (float)$item['punten'] : null;

        if (!$lic || !$distId || $punten === null) continue;

        $updateStmt->execute([$punten, $compId, $dcId, $distId, $lic]);
        if ($updateStmt->rowCount() > 0) $opgeslagen++;
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'opgeslagen' => $opgeslagen], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
