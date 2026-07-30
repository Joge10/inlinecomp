<?php
// ============================================================
//  InlineComp – coach-accounts beheer (owner/admin)
//
//  GET                     → lijst coach-accounts (pending bovenaan)
//  GET ?status=pending     → alleen die status
//  POST action=goedkeuren  { id }
//  POST action=afwijzen    { id }
//  POST action=deactiveren { id }   (actief = 0)
//  POST action=activeren   { id }   (actief = 1)
//  POST action=verwijderen { id }   (hard delete; CASCADE ruimt roster/sessies)
//
//  Eigen tab in Beheer, los van Gebruikers. Coach-accounts staan in
//  coach_accounts (zie die tabel). Goedkeuring ontgrendelt de account-perks;
//  tot die tijd werkt de coach met de anonieme lijst.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';

$ik = requireAuth($pdo, ['owner', 'admin']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

try {
    // ── GET: lijst ─────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $status = trim($_GET['status'] ?? '');
        $where  = '';
        $params = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where = 'WHERE a.status = ?';
            $params[] = $status;
        }
        $stmt = $pdo->prepare("
            SELECT a.id, a.email, a.naam, a.coacht_van_type, a.coacht_van, a.status,
                   a.actief, a.created_at, a.goedgekeurd_at, a.last_login_at,
                   g.naam AS goedgekeurd_door_naam,
                   (SELECT COUNT(*) FROM coach_athletes ca WHERE ca.coach_account_id = a.id) AS roster_count
            FROM   coach_accounts a
            LEFT JOIN users g ON g.id = a.goedgekeurd_door
            $where
            ORDER BY (a.status = 'pending') DESC, a.created_at DESC
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST: acties ───────────────────────────────────────────────────────────
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Methode niet toegestaan']);
        exit;
    }
    $id = (int)($body['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id ontbreekt']);
        exit;
    }

    if ($action === 'goedkeuren') {
        $pdo->prepare("UPDATE coach_accounts
                       SET status = 'approved', goedgekeurd_door = ?, goedgekeurd_at = NOW()
                       WHERE id = ?")->execute([$ik['id'], $id]);
        echo json_encode(['ok' => true, 'status' => 'approved']);
        exit;
    }
    if ($action === 'afwijzen') {
        $pdo->prepare("UPDATE coach_accounts
                       SET status = 'rejected', goedgekeurd_door = ?, goedgekeurd_at = NOW()
                       WHERE id = ?")->execute([$ik['id'], $id]);
        // Lopende sessies intrekken zodat een afgewezen coach direct uitvalt.
        $pdo->prepare("DELETE FROM coach_sessions WHERE coach_account_id = ?")->execute([$id]);
        echo json_encode(['ok' => true, 'status' => 'rejected']);
        exit;
    }
    if ($action === 'deactiveren' || $action === 'activeren') {
        $actief = $action === 'activeren' ? 1 : 0;
        $pdo->prepare("UPDATE coach_accounts SET actief = ? WHERE id = ?")->execute([$actief, $id]);
        if (!$actief) $pdo->prepare("DELETE FROM coach_sessions WHERE coach_account_id = ?")->execute([$id]);
        echo json_encode(['ok' => true, 'actief' => $actief]);
        exit;
    }
    if ($action === 'verwijderen') {
        $pdo->prepare("DELETE FROM coach_accounts WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
