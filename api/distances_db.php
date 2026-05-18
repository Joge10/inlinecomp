<?php
// GET /api/distances_db.php?dc_id={uuid}
// GET /api/distances_db.php?dc_ids=uuid1,uuid2,uuid3  (bulk-variant)
//
// Geeft de afstanden voor een distance_combination terug vanuit de DB
// (niet via KNSB-proxy, enkel voor geïmporteerde wedstrijden).
//
// Bulk-variant (dc_ids): retourneert { "dc_id1": [...], "dc_id2": [...] }
// — voorkomt N parallelle requests bij het laden van de beheer-tabel
// (iFastNet ziet die anders aan voor een loop en stuurt HTTP 508).

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// ── Bulk-variant: meerdere DCs in 1 call ──────────────────────────────────
$dcIdsRaw = trim($_GET['dc_ids'] ?? '');
if ($dcIdsRaw !== '') {
    $dcIds = array_values(array_unique(array_filter(
        array_map('trim', explode(',', $dcIdsRaw)), 'strlen'
    )));
    if (!$dcIds) { echo json_encode(new stdClass()); exit; }
    // Cap tegen runaway-input. 200 dekt elke realistische wedstrijd ruim.
    if (count($dcIds) > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'Te veel dc_ids (max 200)']);
        exit;
    }
    try {
        $placeholders = implode(',', array_fill(0, count($dcIds), '?'));
        $stmt = $pdo->prepare("
            SELECT distance_combination_id AS _dc,
                   id, number, name, target_group, value_meters, discipline, race_type
            FROM distances
            WHERE distance_combination_id IN ($placeholders)
            ORDER BY distance_combination_id, number
        ");
        $stmt->execute($dcIds);
        // Initialize empty arrays voor alle gevraagde DCs (zodat de client
        // altijd een entry per gevraagde DC krijgt, ook als die nul rijen heeft).
        $result = [];
        foreach ($dcIds as $id) $result[$id] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dcId = $row['_dc'];
            unset($row['_dc']);
            $result[$dcId][] = $row;
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Single-DC-variant (legacy) ─────────────────────────────────────────────
$dcId = trim($_GET['dc_id'] ?? '');
if (!$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'dc_id of dc_ids ontbreekt']);
    exit;
}

// Optioneel filteren op split-groep.
// Als split_group opgegeven is én er bestaan rijen met die target_group → geef alleen die.
// Als split_group opgegeven is maar er zijn géén split-specifieke rijen → val terug op basis (NULL).
// Zonder split_group: geef alle rijen terug (o.a. voor laden in beheer-tabel).
$splitGroup = isset($_GET['split_group']) && $_GET['split_group'] !== '' ? $_GET['split_group'] : null;

try {
    if ($splitGroup !== null) {
        // Check of er split-specifieke afstanden bestaan
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM distances
            WHERE distance_combination_id = ? AND target_group = ?
        ");
        $check->execute([$dcId, $splitGroup]);
        $heeftSplit = (int) $check->fetchColumn() > 0;

        if ($heeftSplit) {
            // Split-specifieke afstanden gevonden → alleen die
            $stmt = $pdo->prepare("
                SELECT id, number, name, target_group, value_meters, discipline, race_type
                FROM distances
                WHERE distance_combination_id = ? AND target_group = ?
                ORDER BY number
            ");
            $stmt->execute([$dcId, $splitGroup]);
        } else {
            // Geen split-specifiek → basis-afstanden (target_group IS NULL)
            $stmt = $pdo->prepare("
                SELECT id, number, name, target_group, value_meters, discipline, race_type
                FROM distances
                WHERE distance_combination_id = ?
                  AND (target_group IS NULL OR target_group = '')
                ORDER BY number
            ");
            $stmt->execute([$dcId]);
        }
    } else {
        // Geen filter → alle afstanden (voor laden in beheer-tabel)
        $stmt = $pdo->prepare("
            SELECT id, number, name, target_group, value_meters, discipline, race_type
            FROM distances
            WHERE distance_combination_id = ?
            ORDER BY number
        ");
        $stmt->execute([$dcId]);
    }
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
