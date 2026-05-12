<?php
// ============================================================
//  InlineComp – Klassement publicatie
//
//  Aparte stap ná uitslag_vastleggen.php: operator legt eerst het
//  klassement vast (alleen zichtbaar in admin), controleert het, en
//  publiceert het dan expliciet naar /coach + /public.
//
//  POST JSON body:
//  {
//    "action":         "publiceer" | "trek_in",
//    "competition_id": "...",
//    "dc_ids":         ["...", "..."],   // alle DCs in een merge
//  }
//
//  publiceer  → set klassement_config.gepubliceerd_at = NOW()
//  trek_in    → set klassement_config.gepubliceerd_at = NULL
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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = trim($body['action'] ?? '');
$compId   = trim($body['competition_id'] ?? '');
$dcIdsRaw = $body['dc_ids'] ?? [];
$dcIds    = is_array($dcIdsRaw)
    ? array_values(array_filter(array_map('trim', $dcIdsRaw)))
    : array_values(array_filter(array_map('trim', explode(',', (string)$dcIdsRaw))));

if (!$compId || empty($dcIds) || !in_array($action, ['publiceer', 'trek_in'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id, dc_ids en geldige action zijn verplicht']);
    exit;
}

try {
    if ($action === 'publiceer') {
        // Defensief: alleen publiceren als er werkelijk een klassement
        // vastgelegd is voor deze (comp, dc). Voorkomt dat je een leeg
        // klassement "publiceert" en in /coach + /public een dode link krijgt.
        $dcPh = implode(',', array_fill(0, count($dcIds), '?'));
        $exStmt = $pdo->prepare("
            SELECT COUNT(*) FROM uitslag_klassement
            WHERE competition_id = ? AND distance_combination_id IN ($dcPh)
        ");
        $exStmt->execute(array_merge([$compId], $dcIds));
        if ((int)$exStmt->fetchColumn() === 0) {
            http_response_code(409);
            echo json_encode([
                'error'   => 'niet_vastgelegd',
                'message' => 'Klassement is nog niet vastgelegd — leg eerst vast voordat je publiceert.',
            ]);
            exit;
        }

        $upsert = $pdo->prepare("
            INSERT INTO klassement_config (competition_id, dc_id, gepubliceerd_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE gepubliceerd_at = NOW()
        ");
        foreach ($dcIds as $dcId) $upsert->execute([$compId, $dcId]);
        echo json_encode(['ok' => true, 'gepubliceerd_at' => date('Y-m-d H:i:s')]);
        exit;
    }

    if ($action === 'trek_in') {
        $dcPh = implode(',', array_fill(0, count($dcIds), '?'));
        $upd = $pdo->prepare("
            UPDATE klassement_config
            SET gepubliceerd_at = NULL
            WHERE competition_id = ? AND dc_id IN ($dcPh)
        ");
        $upd->execute(array_merge([$compId], $dcIds));
        echo json_encode(['ok' => true]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
