<?php
// ============================================================
//  InlineComp – push-abonnement beheren (coach-scope, Fase 1)
//
//  POST ?action=subscribe    { endpoint, keys:{p256dh, auth} }  → opslaan
//  POST ?action=unsubscribe  { endpoint }                       → verwijderen
//  POST ?action=test                                            → testmelding
//
//  Auth: ingelogd coach-account (coach_sessions-cookie) via vereisCoachLogin().
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/lib_coach_auth.php';
require_once __DIR__ . '/lib_push.php';

$c = getCoachSession($pdo);
if (!$c) { http_response_code(401); echo json_encode(['error' => 'Niet ingelogd']); exit; }

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

if ($action === 'subscribe') {
    $endpoint = trim($body['endpoint'] ?? '');
    $p256dh   = trim($body['keys']['p256dh'] ?? '');
    $auth     = trim($body['keys']['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ongeldige subscription']);
        exit;
    }
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $pdo->prepare("
        INSERT INTO push_subscriptions
               (scope, coach_account_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
        VALUES ('coach', ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
               scope = 'coach', coach_account_id = VALUES(coach_account_id),
               p256dh = VALUES(p256dh), auth = VALUES(auth),
               user_agent = VALUES(user_agent), last_seen = NOW()
    ")->execute([$c['id'], $endpoint, hash('sha256', $endpoint), $p256dh, $auth, $ua]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'unsubscribe') {
    $endpoint = trim($body['endpoint'] ?? '');
    if ($endpoint !== '') {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND coach_account_id = ?")
            ->execute([hash('sha256', $endpoint), $c['id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'test') {
    $st = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE coach_account_id = ?");
    $st->execute([$c['id']]);
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$subs)              { echo json_encode(['ok' => false, 'reden' => 'geen abonnement']); exit; }
    if (!pushBeschikbaar())  { echo json_encode(['ok' => false, 'reden' => 'push-lib/VAPID niet geconfigureerd']); exit; }
    $res = pushVerstuur($pdo, $subs, [
        'title' => 'InlineComp — test 🔔',
        'body'  => 'Je meldingen werken!',
        'url'   => './',
        'tag'   => 'inlinecomp-test',
    ]);
    echo json_encode(['ok' => true, 'result' => $res]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'onbekende action']);
