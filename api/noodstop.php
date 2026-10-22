<?php
// ============================================================
//  InlineComp – noodstop (harde offline via .htaccess) — alleen owner
//
//  GET   → { aan, reden, sinds, ip_uitzondering, jouw_ip }
//  POST  → { actie:'aan', reden:'...', ip_uitzonderen:bool }  of  { actie:'uit' }
//
//  Het eigenlijke werk zit in inc/noodstop.php.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../inc/app_instellingen.php';
require_once __DIR__ . '/../inc/noodstop.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Raakt de hele site (ook jury/API) → uitsluitend owner, ook voor lezen.
$user = requireAuth($pdo, ['owner']);

$jouwIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

try {
    if ($method === 'GET') {
        echo json_encode([
            'aan'             => noodstopIsAan(),
            'reden'           => getInstelling($pdo, 'noodstop_reden', ''),
            'sinds'           => getInstelling($pdo, 'noodstop_sinds', null),
            'ip_uitzondering' => getInstelling($pdo, 'noodstop_ip', ''),
            'jouw_ip'         => $jouwIp,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $actie = $body['actie'] ?? '';

    if ($actie === 'aan') {
        $reden = trim((string) ($body['reden'] ?? ''));
        if ($reden === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Vul een reden in — die komt op de offline-pagina.']);
            exit;
        }
        if (mb_strlen($reden) > 500) $reden = mb_substr($reden, 0, 500);
        $ip = !empty($body['ip_uitzonderen']) && filter_var($jouwIp, FILTER_VALIDATE_IP) ? $jouwIp : null;

        $res = noodstopAan($reden, $ip, (string) ($user['naam'] ?? $user['username'] ?? 'owner'));

        setInstelling($pdo, 'noodstop_reden', $reden);
        setInstelling($pdo, 'noodstop_sinds', date('Y-m-d H:i:s'));
        setInstelling($pdo, 'noodstop_ip',    $ip ?? '');
        if (function_exists('logboekSchrijf')) {
            logboekSchrijf($pdo, $user['id'] ?? null, 'noodstop_aan', ['ip_uitzondering' => $ip !== null]);
        }
        echo json_encode(['ok' => true, 'aan' => true, 'check' => $res['check'], 'ip_uitzondering' => $ip ?? ''],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($actie === 'uit') {
        $res = noodstopUit();
        setInstelling($pdo, 'noodstop_sinds', null);
        setInstelling($pdo, 'noodstop_ip',    '');
        if (function_exists('logboekSchrijf')) {
            logboekSchrijf($pdo, $user['id'] ?? null, 'noodstop_uit', []);
        }
        echo json_encode(['ok' => true, 'aan' => false, 'check' => $res['check']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
