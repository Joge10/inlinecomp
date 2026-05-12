<?php
// ============================================================
//  InlineComp – Wedstrijd zichtbaarheid voor /coach + /public
//
//  Operator zet wedstrijd op verborgen tijdens voorbereidingsfase
//  (geen onaffe info naar buiten) en op zichtbaar zodra alles klaar
//  is. Toggle staat in Beheer naast posters en meldingen.
//
//  POST JSON body:
//  {
//    "competition_id": "...",
//    "zichtbaar":      true | false
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

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$compId = trim($body['competition_id'] ?? '');
$zicht  = !empty($body['zichtbaar']) ? 1 : 0;

if (!preg_match('/^[a-f0-9\-]{36}$/i', $compId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldig competition_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE competitions SET public_zichtbaar = ? WHERE id = ?");
    $stmt->execute([$zicht, $compId]);
    if ($stmt->rowCount() === 0) {
        // Geen rij gewijzigd → óf wedstrijd bestaat niet, óf nieuwe waarde
        // is gelijk aan oude. Check welke van de twee.
        $chk = $pdo->prepare("SELECT 1 FROM competitions WHERE id = ?");
        $chk->execute([$compId]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Wedstrijd niet gevonden']);
            exit;
        }
    }
    echo json_encode(['ok' => true, 'zichtbaar' => (bool)$zicht]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
