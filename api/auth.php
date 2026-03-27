<?php
// ============================================================
//  InlineComp – authenticatie
//
//  POST action=login   { username, password }  → zet cookie
//  POST action=logout                           → wist cookie
//  GET  action=me                               → huidige gebruiker
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

try {

    // ── GET me ───────────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'me') {
        $user = getSession($pdo);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Niet ingelogd']);
        } else {
            echo json_encode($user);
        }
        exit;
    }

    // ── POST login ───────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'login') {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if (!$username || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Gebruikersnaam en wachtwoord zijn verplicht']);
            exit;
        }

        // Verwijder verlopen sessies (huishouding)
        $pdo->prepare("DELETE FROM sessions WHERE expires_at < NOW()")->execute();

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND actief = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Gebruikersnaam of wachtwoord onjuist']);
            exit;
        }

        // Genereer sessie-token (64 hex tekens = 32 bytes)
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $pdo->prepare("INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)")
            ->execute([$token, $user['id'], $expiresAt]);

        // Stel cookie in (HttpOnly, SameSite=Strict)
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('ic_session', $token, [
            'expires'  => strtotime('+24 hours'),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        echo json_encode([
            'ok'   => true,
            'user' => [
                'id'       => $user['id'],
                'username' => $user['username'],
                'naam'     => $user['naam'],
                'role'     => $user['role'],
            ],
        ]);
        exit;
    }

    // ── POST logout ──────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'logout') {
        $token = $_COOKIE['ic_session'] ?? '';
        if ($token) {
            $pdo->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
        }
        setcookie('ic_session', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
