<?php
// ============================================================
//  InlineComp – loting-status per dc/afstand combinatie
//
//  GET /api/startlijst_status.php?competition_id=X
//
//  Geeft per distance_combination_id + distance_id + split_group
//  of er een loting (ronde 1) bestaat, plus de hoogste ronde.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
requireAuth($pdo);

$compId = trim($_GET['competition_id'] ?? '');
if (!$compId) { echo '[]'; exit; }

try {
    $stmt = $pdo->prepare("
        SELECT distance_combination_id,
               COALESCE(distance_id, '')  AS distance_id,
               COALESCE(split_group, '')  AS split_group,
               MAX(ronde)                AS max_ronde
        FROM heats
        WHERE competition_id = ?
        GROUP BY distance_combination_id, distance_id, split_group
        HAVING MIN(ronde) = 1
    ");
    $stmt->execute([$compId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo '[]';
}
