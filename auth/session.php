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

// ── Multi-tenant scoping ─────────────────────────────────────────────────────
// Bepaalt welke organisaties deze gebruiker mag zien. Drie uitkomsten:
//
//   - NULL  → unscoped: gebruiker ziet ALLE wedstrijden (geen filter nodig).
//             Geldt voor: rol='owner', óf andere rol zonder junction-entries
//             (backward-compat met huidige users die geen org-koppeling hebben).
//   - []    → scoped maar leeg: gebruiker had wél junction-rijen maar die zijn
//             intussen verdwenen (org verwijderd). API moet "geen wedstrijden"
//             retourneren. Onwaarschijnlijke edge-case.
//   - [...] → scoped op deze UUIDs: API moet WHERE organisatie_id IN (…) doen.
//
// Gebruik in een endpoint:
//
//   $scope = gebruikerOrgScope($pdo, $user);
//   if ($scope === null) {
//       // geen filter
//   } else {
//       $ph = implode(',', array_fill(0, count($scope), '?'));
//       $sql .= " AND c.organisatie_id IN ($ph)";
//       array_push($params, ...$scope);
//   }
//
// Owner-check is de eerste, daarna pas de junction-query — scheelt 1 query
// voor de meest-gebruikte rol.
function gebruikerOrgScope(PDO $pdo, array $user): ?array {
    if (($user['role'] ?? '') === 'owner') return null;
    $stmt = $pdo->prepare("
        SELECT organisatie_id FROM user_organisaties WHERE user_id = ?
    ");
    $stmt->execute([(int)$user['id']]);
    $orgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($orgs)) return null;   // 0 junction = unscoped (backward-compat)
    return $orgs;
}

// Geeft een WHERE-fragment + PDO-params terug om wedstrijden te filteren op
// de scope van de huidige gebruiker. Gebruik bij elke query die wedstrijden
// listet:
//
//   $scope = gebruikerCompScopeWhere($pdo, $user, 'c');     // tabel-alias
//   $sql   = "SELECT ... FROM competitions c WHERE 1=1" . $scope['where'];
//   $stmt  = $pdo->prepare($sql);
//   $stmt->execute($scope['params']);
//
// where = '' wanneer user unscoped is. Anders 'AND c.organisatie_id IN (?,?,…)'.
// $tabelAlias kan worden weggelaten voor ongealiaste queries.
function gebruikerCompScopeWhere(PDO $pdo, array $user, string $tabelAlias = ''): array {
    $scope = gebruikerOrgScope($pdo, $user);
    if ($scope === null) return ['where' => '', 'params' => []];
    $kolom = ($tabelAlias !== '' ? $tabelAlias . '.' : '') . 'organisatie_id';
    // Edge-case: junction-rijen verwezen naar verwijderde orgs (kan niet door
    // FK ON DELETE CASCADE — maar defensief). 0 orgs = niets zien.
    if (empty($scope)) return ['where' => " AND 1=0", 'params' => []];
    $ph = implode(',', array_fill(0, count($scope), '?'));
    return ['where' => " AND $kolom IN ($ph)", 'params' => $scope];
}

// Guard voor detail/action endpoints: controleert of de huidige gebruiker
// toegang heeft tot de opgegeven competition_id op basis van zijn scope.
// Eindigt met 403/404 indien niet toegestaan. Gebruik direct na requireAuth
// en na het uitlezen van $compId uit de body/query:
//
//     $compId = trim($_GET['competition_id'] ?? $body['competition_id'] ?? '');
//     checkCompetitieToegang($pdo, $_authUser, $compId);
//
// Owner en unscoped users (scope === null) passeren altijd.
function checkCompetitieToegang(PDO $pdo, array $user, string $compId): void {
    if ($compId === '') return;  // geen comp-id meegegeven → andere code-paden
                                  // (lijsten, statische endpoints) gaan door
    $scope = gebruikerOrgScope($pdo, $user);
    if ($scope === null) return;  // owner/unscoped admin: vrij baan
    $stmt = $pdo->prepare("SELECT organisatie_id FROM competitions WHERE id = ?");
    $stmt->execute([$compId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Wedstrijd niet gevonden']);
        exit;
    }
    $orgId = $row['organisatie_id'];
    if ($orgId === null || !in_array($orgId, $scope, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $orgId === null
                ? 'Deze wedstrijd heeft geen organisatie — vraag owner om koppeling'
                : 'Deze wedstrijd valt buiten jouw scope',
        ]);
        exit;
    }
}
