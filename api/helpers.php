<?php
// ============================================================
//  InlineComp – System helpers (admin-only opruim/diagnostiek-tools)
//
//  POST /api/helpers.php
//    {action: "scan_wees_uitslagen"}           → diagnostiek (geen schrijfacties)
//    {action: "cleanup_wees_uitslagen", scope: "all" | "<comp_id>"}
//
//  "Wees-uitslagen" = rijen in uitslag_afstand of uitslag_klassement waar
//  geen heats meer onder zitten — typisch gevolg van wis-loting zonder de
//  archief-uitslag mee te verwijderen (komt voor bij oudere wedstrijden
//  vóór de nieuwe wis-dialog).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo, ['owner', 'admin']);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── Scan: rapport per wedstrijd ─────────────────────────────────────────────
if ($action === 'scan_wees_uitslagen') {
    try {
        // Wees uitslag_afstand-rijen: groepeer per wedstrijd + DC + afstand,
        // tel hoeveel rijders erin staan en check of er heats voor zijn.
        $uaStmt = $pdo->query("
            SELECT
                ua.competition_id,
                ua.competition_naam,
                ua.competition_datum,
                ua.dc_naam,
                ua.distance_naam,
                ua.split_group,
                COUNT(*) AS rijders
            FROM uitslag_afstand ua
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                WHERE h.competition_id          = ua.competition_id
                  AND h.distance_combination_id = ua.distance_combination_id
                  AND (h.distance_id = ua.distance_id OR (h.distance_id IS NULL AND ua.distance_id = ''))
                  AND (h.split_group = ua.split_group OR (h.split_group IS NULL AND ua.split_group = ''))
            )
            GROUP BY ua.competition_id, ua.distance_combination_id,
                     ua.distance_id, ua.split_group
            ORDER BY ua.competition_datum DESC, ua.competition_naam, ua.dc_naam, ua.distance_naam
        ");
        $weesUitslag = $uaStmt->fetchAll(PDO::FETCH_ASSOC);

        // Wees uitslag_klassement-rijen
        $ukStmt = $pdo->query("
            SELECT
                uk.competition_id,
                uk.competition_naam,
                uk.competition_datum,
                uk.dc_naam,
                uk.split_group,
                COUNT(*) AS rijders
            FROM uitslag_klassement uk
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                WHERE h.competition_id          = uk.competition_id
                  AND h.distance_combination_id = uk.distance_combination_id
                  AND (h.split_group = uk.split_group OR (h.split_group IS NULL AND uk.split_group = ''))
            )
            GROUP BY uk.competition_id, uk.distance_combination_id, uk.split_group
            ORDER BY uk.competition_datum DESC, uk.competition_naam, uk.dc_naam
        ");
        $weesKlassement = $ukStmt->fetchAll(PDO::FETCH_ASSOC);

        // Totalen + uniek aantal wedstrijden geraakt
        $compsRaakt = [];
        foreach ($weesUitslag    as $r) $compsRaakt[$r['competition_id']] = true;
        foreach ($weesKlassement as $r) $compsRaakt[$r['competition_id']] = true;

        echo json_encode([
            'ok'                  => true,
            'wees_uitslag'        => $weesUitslag,
            'wees_klassement'     => $weesKlassement,
            'totaal_uitslag_rij'  => array_sum(array_column($weesUitslag,    'rijders')),
            'totaal_klas_rij'     => array_sum(array_column($weesKlassement, 'rijders')),
            'unieke_wedstrijden'  => count($compsRaakt),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ── Cleanup: daadwerkelijk verwijderen ──────────────────────────────────────
if ($action === 'cleanup_wees_uitslagen') {
    $scope = trim($body['scope'] ?? 'all');
    // scope = 'all' → alle wedstrijden, of een specifiek competition_id (UUID)
    if ($scope !== 'all' && !preg_match('/^[a-f0-9\-]{36}$/i', $scope)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige scope (verwacht "all" of een geldig UUID)']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // uitslag_afstand
        $sql = "
            DELETE ua FROM uitslag_afstand ua
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                WHERE h.competition_id          = ua.competition_id
                  AND h.distance_combination_id = ua.distance_combination_id
                  AND (h.distance_id = ua.distance_id OR (h.distance_id IS NULL AND ua.distance_id = ''))
                  AND (h.split_group = ua.split_group OR (h.split_group IS NULL AND ua.split_group = ''))
            )
        ";
        if ($scope !== 'all') $sql .= " AND ua.competition_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope === 'all' ? [] : [$scope]);
        $uaWeg = $stmt->rowCount();

        // uitslag_klassement
        $sql = "
            DELETE uk FROM uitslag_klassement uk
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                WHERE h.competition_id          = uk.competition_id
                  AND h.distance_combination_id = uk.distance_combination_id
                  AND (h.split_group = uk.split_group OR (h.split_group IS NULL AND uk.split_group = ''))
            )
        ";
        if ($scope !== 'all') $sql .= " AND uk.competition_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope === 'all' ? [] : [$scope]);
        $ukWeg = $stmt->rowCount();

        $pdo->commit();
        echo json_encode([
            'ok'                  => true,
            'uitslag_verwijderd'  => $uaWeg,
            'klas_verwijderd'     => $ukWeg,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende action']);
