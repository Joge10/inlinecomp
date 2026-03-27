<?php
// GET /api/distances_db.php?dc_id={uuid}
// Geeft de afstanden voor een distance_combination terug vanuit de DB
// (niet via KNSB-proxy, enkel voor geïmporteerde wedstrijden)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dcId = trim($_GET['dc_id'] ?? '');
if (!$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'dc_id ontbreekt']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

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
                SELECT id, number, name, target_group, value_meters, discipline
                FROM distances
                WHERE distance_combination_id = ? AND target_group = ?
                ORDER BY number
            ");
            $stmt->execute([$dcId, $splitGroup]);
        } else {
            // Geen split-specifiek → basis-afstanden (target_group IS NULL)
            $stmt = $pdo->prepare("
                SELECT id, number, name, target_group, value_meters, discipline
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
            SELECT id, number, name, target_group, value_meters, discipline
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
