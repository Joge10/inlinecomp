<?php
// ============================================================
//  InlineComp – Coach-app wachtwoord-check + admin-setter
//
//  Drempel-wachtwoord voor de coach-app: voorkomt dat publiek massaal
//  coach gebruikt om hele clubs te monitoren (DB-load). Niet bedoeld
//  als security — de data is openbaar — maar als instap-barrière.
//
//  Bewust GEEN hash: data is openbaar, wachtwoord staat op de poster,
//  en owner moet 'm kunnen lezen om af te drukken / aan coaches door
//  te geven.
//
//  Endpoints:
//    GET  ?action=status   → { has_password: bool }     (publiek)
//    POST ?action=verify   body { password }            (publiek)
//                                  → { ok: true } | 401 { error }
//    POST ?action=set      body { password } | { password: null }
//                                  → { ok: true }     (owner-only)
//    GET  ?action=get      → { password, set_at, set_by_naam } (owner-only)
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';

$action = $_GET['action'] ?? '';

function _coachAppPwd(PDO $pdo): ?string {
    $stmt = $pdo->prepare("SELECT password FROM coach_app_settings WHERE id = 1 LIMIT 1");
    $stmt->execute();
    $p = $stmt->fetchColumn();
    return ($p && is_string($p) && $p !== '') ? $p : null;
}

// ── status (publiek) ──────────────────────────────────────────────────────
// Coach-frontend checkt of er überhaupt een wachtwoord is ingesteld.
// Bij nee → open toegang (backward-compat). Bij ja → toon prompt.
if ($action === 'status') {
    $pw = _coachAppPwd($pdo);
    echo json_encode(['has_password' => $pw !== null]);
    exit;
}

// ── verify (publiek) ──────────────────────────────────────────────────────
// Coach-frontend valideert een ingevoerd wachtwoord. Bij OK slaat 'm de
// frontend op in localStorage en stuurt mee als X-Coach-PW header.
if ($action === 'verify') {
    $body = json_decode(file_get_contents('php://input'), true);
    $pw   = is_array($body) ? (string)($body['password'] ?? '') : '';
    $stored = _coachAppPwd($pdo);
    if ($stored === null) {
        echo json_encode(['ok' => true, 'open' => true]);
        exit;
    }
    // Strikte string-compare. Geen hash → geen timing-attack zorg.
    if ($pw !== '' && hash_equals($stored, $pw)) {
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(401);
    echo json_encode(['error' => 'Onjuist wachtwoord']);
    exit;
}

// ── set (owner-only) ──────────────────────────────────────────────────────
// Wachtwoord zetten of wissen via Systeem-UI. Owner-rol vereist — admin
// niet, want het is een platform-wide instelling (cross-organisatie).
if ($action === 'set') {
    require_once __DIR__ . '/../auth/session.php';
    $_authUser = requireAuth($pdo);
    if (($_authUser['role'] ?? '') !== 'owner') {
        http_response_code(403);
        echo json_encode(['error' => 'Alleen owner mag het coach-wachtwoord wijzigen']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $pw   = is_array($body) ? ($body['password'] ?? null) : null;

    if ($pw === null || trim((string)$pw) === '') {
        // Wissen
        $stmt = $pdo->prepare("
            UPDATE coach_app_settings
            SET password = NULL, password_set_at = NULL, password_set_by = NULL
            WHERE id = 1
        ");
        $stmt->execute();
        echo json_encode(['ok' => true, 'cleared' => true]);
        exit;
    }

    $pw = trim((string)$pw);
    if (strlen($pw) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Wachtwoord mag max 100 tekens zijn']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE coach_app_settings
        SET password = ?, password_set_at = NOW(), password_set_by = ?
        WHERE id = 1
    ");
    $stmt->execute([$pw, (int)$_authUser['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── get (owner-only) — readbaar voor poster-druk + Systeem-UI weergave ────
if ($action === 'get') {
    require_once __DIR__ . '/../auth/session.php';
    $_authUser = requireAuth($pdo);
    if (($_authUser['role'] ?? '') !== 'owner') {
        http_response_code(403);
        echo json_encode(['error' => 'Alleen owner mag het coach-wachtwoord lezen']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT cas.password, cas.password_set_at, u.naam AS set_by_naam
        FROM coach_app_settings cas
        LEFT JOIN users u ON u.id = cas.password_set_by
        WHERE cas.id = 1 LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode([
        'password'   => $row['password']        ?? null,
        'set_at'     => $row['password_set_at'] ?? null,
        'set_by_naam'=> $row['set_by_naam']     ?? null,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende action']);
