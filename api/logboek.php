<?php
// ============================================================
//  InlineComp – login-logboek
//
//  GET                          → laatste 500 vermeldingen (owner/admin)
//  GET ?user_id=N               → gefilterd op gebruiker
//  POST action=opschonen        → verwijder ouder dan N dagen (default 30)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';

$ik = requireAuth($pdo, ['owner', 'admin']);

// ── Auto-opschonen: vermeldingen ouder dan 30 dagen ──────────────────────────
$pdo->exec("DELETE FROM login_logs WHERE tijdstip < DATE_SUB(NOW(), INTERVAL 30 DAY)");

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

try {

    // ── GET: lijst ───────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $userId = isset($_GET['user_id']) && $_GET['user_id'] !== ''
            ? (int)$_GET['user_id'] : 0;
        // Speciale filter-types:
        //   'jury'        = alle jury-app activiteit. De jury-app logt onder
        //                   twee prefixen: 'jury-' (login/logout/rol-keuze) en
        //                   'scheids-' (scheidsrechter-acties zoals
        //                   status-wissels en reserve-inzet).
        //   'organisator' = alle non-jury entries (reguliere staf-logins).
        //   'coach'       = coach-account-activiteit (bron='coach', user_id NULL).
        //   'staff'       = alle staf-entries (bron='staff').
        $type   = trim((string)($_GET['type'] ?? ''));

        if ($type === 'jury') {
            $stmt = $pdo->prepare("
                SELECT id, user_id, naam, username, actie, bron, ip_adres, land, stad, browser, os, tijdstip
                FROM login_logs
                WHERE actie LIKE 'jury-%' OR actie LIKE 'scheids-%'
                ORDER BY tijdstip DESC LIMIT 500
            ");
            $stmt->execute();
        } elseif ($type === 'organisator') {
            $stmt = $pdo->prepare("
                SELECT id, user_id, naam, username, actie, bron, ip_adres, land, stad, browser, os, tijdstip
                FROM login_logs
                WHERE actie NOT LIKE 'jury-%' AND actie NOT LIKE 'scheids-%'
                ORDER BY tijdstip DESC LIMIT 500
            ");
            $stmt->execute();
        } elseif ($type === 'coach') {
            $stmt = $pdo->query("
                SELECT id, user_id, naam, username, actie, bron, ip_adres, land, stad, browser, os, tijdstip
                FROM login_logs WHERE bron = 'coach'
                ORDER BY tijdstip DESC LIMIT 500
            ");
        } elseif ($type === 'staff') {
            $stmt = $pdo->query("
                SELECT id, user_id, naam, username, actie, bron, ip_adres, land, stad, browser, os, tijdstip
                FROM login_logs WHERE bron = 'staff'
                ORDER BY tijdstip DESC LIMIT 500
            ");
        } elseif ($userId) {
            $stmt = $pdo->prepare("
                SELECT id, user_id, naam, username, actie, bron, ip_adres, land, stad, browser, os, tijdstip
                FROM login_logs WHERE user_id = ?
                ORDER BY tijdstip DESC LIMIT 500
            ");
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->query("
                SELECT id, user_id, naam, username, actie, bron, ip_adres, land, stad, browser, os, tijdstip
                FROM login_logs
                ORDER BY tijdstip DESC LIMIT 500
            ");
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST opschonen ───────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'opschonen') {
        $dagen = max(1, (int)($body['dagen'] ?? 30));
        $stmt  = $pdo->prepare(
            "DELETE FROM login_logs WHERE tijdstip < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$dagen]);
        echo json_encode(['ok' => true, 'verwijderd' => $stmt->rowCount()]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
