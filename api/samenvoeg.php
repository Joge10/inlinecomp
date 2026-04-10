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
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'startlijsten')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor startlijsten.']);
    exit;
}

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
    // ── Blokkeer als er al heats of tijdschema-config bestaan ────────────
    $dcIds = array_filter(array_map(fn($m) => trim($m['dc_id'] ?? ''), $merges));
    if ($dcIds) {
        $dcPh = implode(',', array_fill(0, count($dcIds), '?'));

        // Check heats
        $heatCheck = $pdo->prepare("
            SELECT COUNT(*) FROM heats
            WHERE competition_id = ? AND distance_combination_id IN ($dcPh)
        ");
        $heatCheck->execute(array_merge([$compId], $dcIds));
        if ((int)$heatCheck->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Samenvoegen/ontkoppelen niet mogelijk: er bestaan al startlijsten voor deze categorie. Wis eerst de loting in de Startlijsten-module.']);
            exit;
        }

        // Check tijdschema cat_config
        $tsCheck = $pdo->prepare("
            SELECT COUNT(*) FROM tijdschema_cat_config tcc
            JOIN competition_tijdschema ct ON ct.id = tcc.tijdschema_id
            WHERE ct.competition_id = ? AND tcc.dc_id IN ($dcPh)
        ");
        $tsCheck->execute(array_merge([$compId], $dcIds));
        if ((int)$tsCheck->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Samenvoegen/ontkoppelen niet mogelijk: deze categorie is al geconfigureerd in het tijdschema. Verwijder het tijdschema eerst.']);
            exit;
        }
    }

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
