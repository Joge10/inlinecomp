<?php
// ============================================================
//  InlineComp – push-abonnement beheren (coach- én public-scope)
//
//  POST ?action=subscribe    { scope?, endpoint, keys:{p256dh,auth},
//                              notif_loting?, notif_uitslag?, licenses?[] }
//  POST ?action=unsubscribe  { scope?, endpoint }
//  POST ?action=test         { scope?, endpoint? }
//
//  scope = 'coach' (default) → ingelogd coach-account vereist; targeting via
//          coach_athletes-roster.
//  scope = 'public'          → anoniem; gevolgde licenties (uit localStorage)
//          worden gespiegeld naar push_sub_licenses. Geen auth: loting/uitslag
//          zijn openbare gegevens, dus abonneren op willekeurige rijders lekt
//          niets. Wel begrensd (max licenties/lengte) tegen misbruik.
//
//  notif_loting / notif_uitslag : per-type aan/uit (default 1).
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/lib_coach_auth.php';
require_once __DIR__ . '/lib_push.php';

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$scope = ($body['scope'] ?? 'coach') === 'public' ? 'public' : 'coach';

// Coach-scope vereist login; public-scope is anoniem.
$coachId = null;
if ($scope === 'coach') {
    $c = getCoachSession($pdo);
    if (!$c) { http_response_code(401); echo json_encode(['error' => 'Niet ingelogd']); exit; }
    $coachId = (int) $c['id'];
}

/** 0/1 uit een (bool/int/string) body-waarde, default 1. */
function _pushBoolIn($v): int { return (isset($v) && ($v === false || $v === 0 || $v === '0' || $v === 'false')) ? 0 : 1; }

/** Gevolgde licenties normaliseren: uniek, niet-leeg, begrensd (public). */
function _pushLicsIn($v): array {
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $l) {
        $l = substr(trim((string) $l), 0, 32);
        if ($l !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $l)) $out[$l] = true;
        if (count($out) >= 12) break;   // cap tegen misbruik
    }
    return array_keys($out);
}

if ($action === 'subscribe') {
    $endpoint = trim($body['endpoint'] ?? '');
    $p256dh   = trim($body['keys']['p256dh'] ?? '');
    $auth     = trim($body['keys']['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ongeldige subscription']);
        exit;
    }
    $hash = hash('sha256', $endpoint);
    $ua   = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $nl   = _pushBoolIn($body['notif_loting']  ?? 1);
    $nu   = _pushBoolIn($body['notif_uitslag'] ?? 1);
    $lics = $scope === 'public' ? _pushLicsIn($body['licenses'] ?? []) : [];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO push_subscriptions
                   (scope, coach_account_id, endpoint, endpoint_hash, p256dh, auth,
                    notif_loting, notif_uitslag, licenses, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                   scope = VALUES(scope), coach_account_id = VALUES(coach_account_id),
                   p256dh = VALUES(p256dh), auth = VALUES(auth),
                   notif_loting = VALUES(notif_loting), notif_uitslag = VALUES(notif_uitslag),
                   licenses = VALUES(licenses), user_agent = VALUES(user_agent), last_seen = NOW()
        ")->execute([
            $scope, $coachId, $endpoint, $hash, $p256dh, $auth,
            $nl, $nu, ($scope === 'public' ? json_encode($lics) : null), $ua,
        ]);

        // Public: gevolgde rijders spiegelen naar de junction (targeting-bron).
        if ($scope === 'public') {
            $sid = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash = ?");
            $sid->execute([$hash]);
            $subId = (int) $sid->fetchColumn();
            if ($subId) {
                $pdo->prepare("DELETE FROM push_sub_licenses WHERE subscription_id = ?")->execute([$subId]);
                if ($lics) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO push_sub_licenses (subscription_id, person_license) VALUES (?, ?)");
                    foreach ($lics as $l) $ins->execute([$subId, $l]);
                }
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'opslaan mislukt']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'unsubscribe') {
    $endpoint = trim($body['endpoint'] ?? '');
    if ($endpoint !== '') {
        $hash = hash('sha256', $endpoint);
        if ($scope === 'coach') {
            $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND coach_account_id = ?")
                ->execute([$hash, $coachId]);
        } else {
            // Public: alleen public-abonnementen op dit endpoint (cascade ruimt junction).
            $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND scope = 'public'")
                ->execute([$hash]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'test') {
    if ($scope === 'coach') {
        $st = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE coach_account_id = ?");
        $st->execute([$coachId]);
    } else {
        $endpoint = trim($body['endpoint'] ?? '');
        if ($endpoint === '') { echo json_encode(['ok' => false, 'reden' => 'geen endpoint']); exit; }
        $st = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE endpoint_hash = ? AND scope = 'public'");
        $st->execute([hash('sha256', $endpoint)]);
    }
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$subs)             { echo json_encode(['ok' => false, 'reden' => 'geen abonnement']); exit; }
    if (!pushBeschikbaar()) { echo json_encode(['ok' => false, 'reden' => 'push-lib/VAPID niet geconfigureerd']); exit; }
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
