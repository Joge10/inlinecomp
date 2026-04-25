<?php
// ============================================================
//  InlineComp – gebruikersbeheer
//
//  GET                          → lijst (owner/admin)
//  POST action=eerste_owner     → eerste owner aanmaken (alleen als users-tabel leeg)
//  POST action=save             → aanmaken / bijwerken
//  POST action=set_password     → wachtwoord wijzigen
//  POST action=toggle_actief    → activeren / deactiveren
//  POST action=delete           → verwijderen
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

try {

    // ── Eerste-owner-bootstrap is uitgeschakeld ─────────────────────────────
    // Voorheen kon je via action=eerste_owner zonder auth een owner-account
    // aanmaken zolang de users-tabel leeg was. Risico: bij een per ongeluk
    // lege tabel (backup-restore, foute migratie, SQL-injectie elders) kon
    // de eerstvolgende bezoeker zonder verificatie owner worden.
    //
    // Nieuw account aanmaken zonder bestaande owner gaat nu alleen via
    // direct SQL-insert in phpMyAdmin (zie comment bovenaan login.php).
    if ($action === 'eerste_owner') {
        http_response_code(410);   // Gone — bewust verwijderd
        echo json_encode(['error' => 'Bootstrap is uitgeschakeld. Maak een account aan via de DB.']);
        exit;
    }

    // Alle overige acties vereisen inlog (owner of admin)
    $ik = requireAuth($pdo, ['owner', 'admin']);

    // ── GET: lijst ──────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $rows = $pdo->query(
            "SELECT id, username, naam, email, role, actief, created_at, updated_at
             FROM users ORDER BY role, naam"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST: acties ─────────────────────────────────────────────────────────
    if ($method === 'POST') {

        // Opslaan (nieuw of bijwerken)
        if ($action === 'save') {
            $id       = isset($body['id']) ? (int)$body['id'] : 0;
            $username = trim($body['username'] ?? '');
            $naam     = trim($body['naam']     ?? '');
            $email    = trim($body['email']    ?? '') ?: null;
            $role     = $body['role']          ?? 'viewer';
            $password = $body['password']      ?? '';

            $geldigeRollen = ['owner','admin','importer','planner','timer','viewer'];
            if (!$username || !$naam || !in_array($role, $geldigeRollen, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Ongeldige invoer']);
                exit;
            }

            // Admin mag geen owner aanmaken of andere admin bijwerken
            if ($ik['role'] === 'admin') {
                if ($role === 'owner') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Admin mag geen owner aanmaken']);
                    exit;
                }
                if ($id) {
                    $bestaand = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $bestaand->execute([$id]);
                    $bestaandRole = $bestaand->fetchColumn();
                    if (in_array($bestaandRole, ['owner','admin'], true) && $id !== (int)$ik['id']) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Admin mag owner/admin niet wijzigen']);
                        exit;
                    }
                }
            }

            if ($id === 0) {
                // Nieuw
                if (strlen($password) < 8) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Wachtwoord min. 8 tekens']);
                    exit;
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (username, naam, email, role, password_hash)
                               VALUES (?,?,?,?,?)")
                    ->execute([$username, $naam, $email, $role, $hash]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            } else {
                // Bijwerken
                $pdo->prepare("UPDATE users SET username=?, naam=?, email=?, role=?, updated_at=NOW()
                               WHERE id=?")
                    ->execute([$username, $naam, $email, $role, $id]);
                echo json_encode(['ok' => true]);
            }
            exit;
        }

        // Wachtwoord wijzigen
        if ($action === 'set_password') {
            $id       = (int)($body['id']       ?? 0);
            $password = $body['password']        ?? '';
            if (!$id || strlen($password) < 8) {
                http_response_code(400);
                echo json_encode(['error' => 'id en wachtwoord (min. 8 tekens) verplicht']);
                exit;
            }
            // Admin mag wachtwoord owner/andere admin niet wijzigen
            if ($ik['role'] === 'admin' && $id !== (int)$ik['id']) {
                $r = $pdo->prepare("SELECT role FROM users WHERE id=?");
                $r->execute([$id]);
                if (in_array($r->fetchColumn(), ['owner','admin'], true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Onvoldoende rechten']);
                    exit;
                }
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?")
                ->execute([$hash, $id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Activeren / deactiveren
        if ($action === 'toggle_actief') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id ontbreekt']); exit; }
            // Eigen account niet deactiveren; owner niet deactiveren
            $r = $pdo->prepare("SELECT role FROM users WHERE id=?");
            $r->execute([$id]);
            $targetRole = $r->fetchColumn();
            if ($id === (int)$ik['id'] || $targetRole === 'owner') {
                http_response_code(403);
                echo json_encode(['error' => 'Dit account kan niet worden gedeactiveerd']);
                exit;
            }
            if ($ik['role'] === 'admin' && in_array($targetRole, ['owner','admin'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Onvoldoende rechten']);
                exit;
            }
            $pdo->prepare("UPDATE users SET actief = 1 - actief, updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Verwijderen
        if ($action === 'delete') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id ontbreekt']); exit; }
            if ($id === (int)$ik['id']) {
                http_response_code(403);
                echo json_encode(['error' => 'Je kunt je eigen account niet verwijderen']);
                exit;
            }
            $r = $pdo->prepare("SELECT role FROM users WHERE id=?");
            $r->execute([$id]);
            $targetRole = $r->fetchColumn();
            if ($targetRole === 'owner') {
                http_response_code(403);
                echo json_encode(['error' => 'Owner-account kan niet worden verwijderd']);
                exit;
            }
            if ($ik['role'] === 'admin' && $targetRole === 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Admin mag andere admin niet verwijderen']);
                exit;
            }
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
