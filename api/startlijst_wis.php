<?php
// ============================================================
//  InlineComp – verwijder opgeslagen startlijst (ronde 1+volgende)
//
//  POST /api/startlijst_wis.php
//    Body:
//      competition_id   (verplicht)
//      dc_ids           (verplicht, comma-separated; eerste = primary)
//      distance_id      (verplicht voor multi-afstand DC, anders '')
//      category_filter  (optioneel: split_group)
//      mode             'check' | 'delete' (default 'delete')
//      wis_uitslag      (bool, alleen bij mode=delete) → ook
//                       uitslag_afstand verwijderen voor deze cat+afstand
//      wis_klassement   (bool, alleen bij mode=delete) → ook
//                       uitslag_klassement verwijderen voor deze DC
//
//  mode=check: returnt aantal results / uitslag_afstand-rijen /
//              uitslag_klassement-rijen die geraakt worden, zonder
//              iets te verwijderen. Frontend gebruikt dit om een
//              specifieke confirm-dialog te tonen met checkboxes.
//
//  mode=delete: voert de DELETE's uit. Het wissen van heats cascade't
//              automatisch naar heat_entries en results (tijden,
//              sancties, punten, afval-rang). uitslag_afstand en
//              uitslag_klassement zijn bewust NIET aan een FK gekoppeld
//              (ze zijn archief-tabellen die historie bewaren), dus
//              die worden alleen verwijderd op expliciet verzoek.
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
$mode         = ($body['mode'] ?? 'delete') === 'check' ? 'check' : 'delete';

if (!$compId || !$dcIds) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_ids zijn verplicht']);
    exit;
}

$primaryDcId = $dcIds[0];
// Voor de uitslag-tabellen die NOT NULL DEFAULT '' op split_group/distance_id
// gebruiken: leeg = '' (i.p.v. NULL) zodat de match werkt.
$splitForUitslag = $splitGroup ?? '';
$distForUitslag  = $distId !== '' ? $distId : '';

try {
    if ($mode === 'check') {
        // Aantal results dat verloren zou gaan (cascade vanuit heats)
        $resStmt = $pdo->prepare("
            SELECT COUNT(*) FROM results r
            JOIN heat_entries he ON he.id = r.heat_entry_id
            JOIN heats h         ON h.id = he.heat_id
            WHERE h.competition_id          = ?
              AND h.distance_combination_id = ?
              AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? = ''))
              AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
        ");
        $resStmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup]);
        $resultsCount = (int)$resStmt->fetchColumn();

        // Aantal vastgelegde uitslag-afstand-rijen voor deze cat+afstand
        $uaStmt = $pdo->prepare("
            SELECT COUNT(*) FROM uitslag_afstand
            WHERE competition_id          = ?
              AND distance_combination_id = ?
              AND distance_id             = ?
              AND split_group             = ?
        ");
        $uaStmt->execute([$compId, $primaryDcId, $distForUitslag, $splitForUitslag]);
        $uitslagCount = (int)$uaStmt->fetchColumn();

        // Aantal vastgelegde klassement-rijen voor deze DC (klassement is per
        // DC, niet per afstand — één klassement-rij telt voor alle afstanden
        // van die DC samen).
        $ukStmt = $pdo->prepare("
            SELECT COUNT(*) FROM uitslag_klassement
            WHERE competition_id          = ?
              AND distance_combination_id = ?
              AND split_group             = ?
        ");
        $ukStmt->execute([$compId, $primaryDcId, $splitForUitslag]);
        $klassementCount = (int)$ukStmt->fetchColumn();

        echo json_encode([
            'ok'                => true,
            'results_count'     => $resultsCount,
            'uitslag_count'     => $uitslagCount,
            'klassement_count'  => $klassementCount,
        ]);
        exit;
    }

    // ── mode=delete ─────────────────────────────────────────────────────
    $pdo->beginTransaction();

    // 1. Heats wegwerken (cascade naar heat_entries + results)
    $stmt = $pdo->prepare("
        DELETE FROM heats
        WHERE competition_id          = ?
          AND distance_combination_id = ?
          AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
          AND (split_group = ? OR (split_group IS NULL AND ? IS NULL))
    ");
    $stmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup]);
    $heatsVerwijderd = $stmt->rowCount();

    // 2. Optioneel: vastgelegde uitslag voor deze cat+afstand mee-verwijderen
    $uitslagVerwijderd = 0;
    if (!empty($body['wis_uitslag'])) {
        $uaDel = $pdo->prepare("
            DELETE FROM uitslag_afstand
            WHERE competition_id          = ?
              AND distance_combination_id = ?
              AND distance_id             = ?
              AND split_group             = ?
        ");
        $uaDel->execute([$compId, $primaryDcId, $distForUitslag, $splitForUitslag]);
        $uitslagVerwijderd = $uaDel->rowCount();
    }

    // 3. Optioneel: klassement voor deze DC mee-verwijderen
    $klassementVerwijderd = 0;
    if (!empty($body['wis_klassement'])) {
        $ukDel = $pdo->prepare("
            DELETE FROM uitslag_klassement
            WHERE competition_id          = ?
              AND distance_combination_id = ?
              AND split_group             = ?
        ");
        $ukDel->execute([$compId, $primaryDcId, $splitForUitslag]);
        $klassementVerwijderd = $ukDel->rowCount();
    }

    $pdo->commit();
    echo json_encode([
        'ok'                    => true,
        'heats_verwijderd'      => $heatsVerwijderd,
        'uitslag_verwijderd'    => $uitslagVerwijderd,
        'klassement_verwijderd' => $klassementVerwijderd,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
