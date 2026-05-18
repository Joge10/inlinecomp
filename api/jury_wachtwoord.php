<?php
// ============================================================
//  InlineComp – Jury-wachtwoord per wedstrijd
//
//  POST { competition_id, password }
//      password leeg/null   → wis wachtwoord (jury-app krijgt geen toegang)
//      password gevuld     → password_hash() + opslaan
//
//  Response:
//      { ok: true, jury_password_set: bool }
//
//  Alleen owner/admin: jury-wachtwoord is een toegangs-instelling die
//  niet door reguliere operators ongedaan gemaakt mag worden.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

if (!in_array($_authUser['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Alleen beheerders kunnen het jury-wachtwoord wijzigen.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Methode niet toegestaan']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$compId = trim($body['competition_id'] ?? '');
$pwdRaw = $body['password'] ?? null;
if (!$compId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}

// Bestaat de wedstrijd?
$check = $pdo->prepare("SELECT id, name FROM competitions WHERE id = ?");
$check->execute([$compId]);
$comp = $check->fetch(PDO::FETCH_ASSOC);
if (!$comp) {
    http_response_code(404);
    echo json_encode(['error' => 'Wedstrijd niet gevonden']);
    exit;
}

try {
    $pwd = is_string($pwdRaw) ? trim($pwdRaw) : '';
    if ($pwd === '') {
        // Wissen — jury-app verliest toegang tot deze wedstrijd
        $stmt = $pdo->prepare("UPDATE competitions SET jury_password = NULL WHERE id = ?");
        $stmt->execute([$compId]);
        $set = false;
    } else {
        // Minimumlengte voor enige weerstand tegen brute-force. Niet streng
        // (jury-wachtwoord is gedeeld en eenvoudig te onthouden), maar 6 is
        // het absolute minimum dat zinvol is bij een per-IP rate-limited
        // omgeving zoals iFastNet.
        if (mb_strlen($pwd) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Wachtwoord moet minstens 6 tekens zijn.']);
            exit;
        }
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE competitions SET jury_password = ? WHERE id = ?");
        $stmt->execute([$hash, $compId]);
        $set = true;
    }

    // Audit-log: wie heeft op welke wedstrijd het jury-wachtwoord gezet/gewist.
    // Geen wachtwoord in de log — alleen of het is gezet of gewist.
    if (function_exists('logboekSchrijf')) {
        logboekSchrijf($pdo, $_authUser['id'] ?? null,
            'jury_wachtwoord_' . ($set ? 'set' : 'wis'),
            ['competition_id' => $compId, 'name' => $comp['name']]);
    }

    echo json_encode(['ok' => true, 'jury_password_set' => $set]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
