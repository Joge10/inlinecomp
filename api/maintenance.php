<?php
// ============================================================
//  InlineComp – onderhoudsmodus beheren (Beheer)
//
//  GET   → huidige status (owner + admin mogen lezen)
//  POST  → aan/uit zetten + bypass-scope + bericht (alleen owner)
//          body: { mode:'0'|'1', bypass:'owner'|'owner_admin', bericht:'...' }
//
//  De gate zelf zit in inc/maintenance.php (aangeroepen door de publieke apps).
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../inc/app_instellingen.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Lezen mag owner + admin; schrijven (aan/uit) alleen owner — dit raakt de hele
// site, dus bewust de zwaarste rol.
$user = requireAuth($pdo, $method === 'GET' ? ['owner', 'admin'] : ['owner']);

try {
    if ($method === 'GET') {
        echo json_encode([
            'mode'    => getInstelling($pdo, 'maintenance_mode',    '0'),
            'bypass'  => getInstelling($pdo, 'maintenance_bypass',  'owner_admin'),
            'bericht' => getInstelling($pdo, 'maintenance_bericht', ''),
            'sinds'   => getInstelling($pdo, 'maintenance_sinds',   null),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body    = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode    = ($body['mode'] ?? '0') === '1' ? '1' : '0';
    $bypass  = in_array($body['bypass'] ?? '', ['owner', 'owner_admin'], true)
                 ? $body['bypass'] : 'owner_admin';
    $bericht = trim((string)($body['bericht'] ?? ''));
    if (mb_strlen($bericht) > 300) $bericht = mb_substr($bericht, 0, 300);

    $wasAan = getInstelling($pdo, 'maintenance_mode', '0') === '1';

    setInstelling($pdo, 'maintenance_mode',    $mode);
    setInstelling($pdo, 'maintenance_bypass',  $bypass);
    setInstelling($pdo, 'maintenance_bericht', $bericht);
    // 'sinds' alleen (her)zetten bij de overgang uit→aan; bij uit leegmaken.
    if ($mode === '1' && !$wasAan) {
        setInstelling($pdo, 'maintenance_sinds', date('Y-m-d H:i:s'));
    } elseif ($mode === '0') {
        setInstelling($pdo, 'maintenance_sinds', null);
    }

    if (function_exists('logboekSchrijf')) {
        logboekSchrijf($pdo, $user['id'] ?? null, 'maintenance_mode',
            ['mode' => $mode, 'bypass' => $bypass]);
    }

    echo json_encode([
        'ok'     => true,
        'mode'   => $mode,
        'bypass' => $bypass,
        'sinds'  => getInstelling($pdo, 'maintenance_sinds', null),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
