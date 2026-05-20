<?php
// ============================================================
//  InlineComp – Jury-sessie helpers
//
//  Jury heeft een eigen PHP-sessie (cookie ICJURY) los van de
//  organisator-app (ICAUTH) en coach (ICCOACH). De sessie is per
//  wedstrijd: $_SESSION['jury_comp_id'] + $_SESSION['jury_role'].
//
//  Gebruik:
//      juryStartSession();        // veilige session_start met ICJURY
//      $s = juryHuidigeSessie();  // [comp_id, role] of null
//      juryRequireSession();      // halt of redirect bij geen sessie
// ============================================================

function juryStartSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_name('ICJURY');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
    @session_start();
}

function juryHuidigeSessie(): ?array {
    juryStartSession();
    $compId = $_SESSION['jury_comp_id'] ?? null;
    if (!$compId) return null;
    return [
        'comp_id'   => $compId,
        'role'      => $_SESSION['jury_role']    ?? null,
        'auth_at'   => $_SESSION['jury_auth_at'] ?? null,
    ];
}

// Voor toekomstige jury-API endpoints (Area of Call, fin-volgordes, etc.).
// Geeft 401 als geen sessie, anders array met comp_id + role.
function juryRequireSession(): array {
    $s = juryHuidigeSessie();
    if (!$s) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'Niet ingelogd als jury']);
        exit;
    }
    return $s;
}

// Vereist een specifieke rol (of array van geldige rollen). Gebruik:
//   juryRequireRole(['scheidsrechter', 'starter']);
function juryRequireRole(array $geldigeRollen): array {
    $s = juryRequireSession();
    if (!$s['role'] || !in_array($s['role'], $geldigeRollen, true)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['error' => 'Geen toegang met deze jury-rol']);
        exit;
    }
    return $s;
}
