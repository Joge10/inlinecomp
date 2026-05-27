<?php
// ============================================================
//  InlineComp – Wedstrijd zichtbaarheid voor /coach + /public
//
//  3-state model:
//    'verborgen'   = (zichtbaar=0, aankondigen=0)
//                    NIET in dropdowns — stille voorbereiding
//    'binnenkort'  = (zichtbaar=0, aankondigen=1)
//                    Disabled "(binnenkort)" in dropdowns
//    'live'        = (zichtbaar=1)
//                    Selecteerbaar voor coach + public
//
//  POST JSON body — twee vormen:
//    A) Nieuw 3-state: { competition_id, status: 'verborgen'|'binnenkort'|'live' }
//    B) Oude 2-state (back-compat): { competition_id, zichtbaar: true|false }
//       → true = 'live', false = 'binnenkort' (= huidig oude gedrag)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
// Zichtbaarheid wijzigen valt onder de 'lichte' beheer-acties —
// planner mag dit ook doen (= owner+admin+planner via ROL_SCHRIJF).
$_authUser = requireAuth($pdo, ROL_SCHRIJF['beheer_basic']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Alleen POST toegestaan']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$compId = trim($body['competition_id'] ?? '');

// 3-state status leidend; bij ontbreken val terug op de oude 'zichtbaar'-bool
$status = trim($body['status'] ?? '');
if ($status === '') {
    $status = !empty($body['zichtbaar']) ? 'live' : 'binnenkort';
}
if (!in_array($status, ['verborgen', 'binnenkort', 'live'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldige status (verwacht: verborgen/binnenkort/live)']);
    exit;
}

// 8-36 chars, alfanumeriek + dashes — range voor handmatig-geseede IDs.
if (!preg_match('/^[a-z0-9\-]{8,36}$/i', $compId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldig competition_id']);
    exit;
}

// Vertaal status → (zichtbaar, aankondigen) tuple
$zicht       = $status === 'live'        ? 1 : 0;
$aankondigen = $status === 'binnenkort'  ? 1 : 0;

try {
    $stmt = $pdo->prepare("
        UPDATE competitions
        SET    public_zichtbaar   = ?,
               public_aankondigen = ?
        WHERE  id = ?
    ");
    $stmt->execute([$zicht, $aankondigen, $compId]);
    if ($stmt->rowCount() === 0) {
        $chk = $pdo->prepare("SELECT 1 FROM competitions WHERE id = ?");
        $chk->execute([$compId]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Wedstrijd niet gevonden']);
            exit;
        }
    }
    echo json_encode([
        'ok'          => true,
        'status'      => $status,
        'zichtbaar'   => (bool)$zicht,
        'aankondigen' => (bool)$aankondigen,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
