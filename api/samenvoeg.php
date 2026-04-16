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
    // ── Check of er structurele wijzigingen zijn (merge_group veranderd) ──
    // Label-only wijzigingen zijn altijd toegestaan, ook met bestaand programma.
    $dcIds = array_filter(array_map(fn($m) => trim($m['dc_id'] ?? ''), $merges));
    $isStructureel = false;
    if ($dcIds) {
        $dcPh = implode(',', array_fill(0, count($dcIds), '?'));
        // Huidige merge_groups ophalen
        $curStmt = $pdo->prepare("
            SELECT id, merge_group FROM distance_combinations
            WHERE id IN ($dcPh) AND competition_id = ?
        ");
        $curStmt->execute(array_merge($dcIds, [$compId]));
        $curGroups = [];
        foreach ($curStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $curGroups[$row['id']] = $row['merge_group'];
        }
        foreach ($merges as $m) {
            $dcId = trim($m['dc_id'] ?? '');
            $nieuwGroep = ($m['merge_group'] ?? null) ? trim($m['merge_group']) : null;
            $huidigGroep = $curGroups[$dcId] ?? null;
            if ($nieuwGroep !== $huidigGroep) { $isStructureel = true; break; }
        }
    }

    // Blokkeer alleen structurele wijzigingen als er heats of tijdschema-config bestaan
    if ($isStructureel && $dcIds) {
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

    // ── Update dc_naam in bestaande tijdschema_ritten + heats ──────────────
    // Zodat programma, startlijsten, live en uitslag direct de nieuwe labels tonen.
    $getCurNaam = $pdo->prepare("
        SELECT DISTINCT r.dc_naam FROM tijdschema_ritten r
        JOIN competition_tijdschema ct ON ct.id = r.tijdschema_id
        WHERE ct.competition_id = ? AND r.dc_id = ?
        LIMIT 1
    ");
    $updRitNaam = $pdo->prepare("
        UPDATE tijdschema_ritten r
        JOIN competition_tijdschema ct ON ct.id = r.tijdschema_id
        SET r.dc_naam = ?,
            r.rit_naam = REPLACE(r.rit_naam, ?, ?)
        WHERE ct.competition_id = ? AND r.dc_id = ?
    ");
    $updHeatNaam = $pdo->prepare("
        UPDATE heats
        SET heat_naam = REPLACE(heat_naam, ?, ?)
        WHERE competition_id = ? AND distance_combination_id = ?
    ");
    foreach ($merges as $m) {
        $dcId  = trim($m['dc_id'] ?? '');
        $label = trim($m['merge_label'] ?? '');
        if (!$dcId || !$label) continue;

        // Huidige naam ophalen voor REPLACE
        $getCurNaam->execute([$compId, $dcId]);
        $oudeNaam = $getCurNaam->fetchColumn();
        if (!$oudeNaam || $oudeNaam === $label) continue;

        $updRitNaam->execute([$label, $oudeNaam, $label, $compId, $dcId]);
        $updHeatNaam->execute([$oudeNaam, $label, $compId, $dcId]);
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
