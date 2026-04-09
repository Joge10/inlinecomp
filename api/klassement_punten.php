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

    $upsert = $pdo->prepare("
        INSERT INTO uitslag_afstand
            (competition_id, competition_naam, competition_datum,
             distance_combination_id, dc_naam,
             distance_id, distance_naam,
             person_license, categorie,
             punten)
        VALUES (?,?,?,?,?,?,?,?,
                (SELECT category FROM persons WHERE license_key = ? LIMIT 1),
                ?)
        ON DUPLICATE KEY UPDATE
            punten          = VALUES(punten),
            competition_naam = VALUES(competition_naam),
            dc_naam         = VALUES(dc_naam),
            distance_naam   = VALUES(distance_naam)
    ");

    $pdo->beginTransaction();
    $opgeslagen = 0;

    foreach ($items as $item) {
        $lic      = trim($item['person_license'] ?? '');
        $distId   = trim($item['distance_id']    ?? '');
        $distNaam = trim($item['distance_naam']  ?? '');
        $punten   = isset($item['punten']) ? (float)$item['punten'] : null;

        if (!$lic || !$distId || $punten === null) continue;

        $upsert->execute([
            $compId, $compNaam, $compDatum,
            $dcId, $dcNaam,
            $distId, $distNaam,
            $lic,
            $lic,   // voor subquery categorie
            $punten,
        ]);
        $opgeslagen++;
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'opgeslagen' => $opgeslagen], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
