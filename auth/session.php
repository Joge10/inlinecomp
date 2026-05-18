<?php
// ============================================================
//  InlineComp – gedeelde sessie-controle
//
//  Gebruik in API-bestanden (nà require config_inlinecomp.php):
//
//      $gebruiker = requireAuth($pdo);                    // elke ingelogde gebruiker
//      $gebruiker = requireAuth($pdo, ['owner','admin']);  // specifieke rollen
//
//  Geeft gebruiker-array terug of beëindigt met 401/403 JSON.
//  $gebruiker = ['id', 'username', 'naam', 'role']
// ============================================================

function getSession(PDO $pdo): ?array {
    $token = $_COOKIE['ic_session'] ?? '';
    if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) return null;

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.naam, u.role
        FROM   sessions s
        JOIN   users    u ON u.id = s.user_id
        WHERE  s.token      = ?
          AND  s.expires_at > NOW()
          AND  u.actief     = 1
        LIMIT 1
    ");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function requireAuth(PDO $pdo, array $allowedRoles = []): array {
    $user = getSession($pdo);

    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Niet ingelogd', 'redirect' => '/login.php']);
        exit;
    }

    if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Onvoldoende rechten voor deze actie']);
        exit;
    }

    return $user;
}

// Rollen met schrijfrechten per module
const ROL_SCHRIJF = [
    'importeer'   => ['owner','admin','importer'],
    'tijdschema'  => ['owner','admin','planner'],
    'startlijsten'=> ['owner','admin','planner'],
    'live'        => ['owner','admin','timer'],
    'uitslag'     => ['owner','admin','timer'],
    // 'beheer'       = zware beheer-acties (jury-wachtwoord, delete, etc.)
    // 'beheer_basic' = lichte beheer-acties (zichtbaarheid, mededelingen,
    //                  posters) — planner mag dit ook
    'beheer'       => ['owner','admin'],
    'beheer_basic' => ['owner','admin','planner'],
    'gebruikers'   => ['owner','admin'],
];

function kanSchrijven(array $user, string $module): bool {
    $rollen = ROL_SCHRIJF[$module] ?? ['owner'];
    return in_array($user['role'], $rollen, true);
}
