<?php
// ============================================================
//  InlineComp – Web Push send-helper (minishlink/web-push)
//
//  Vereisten (door de aanroeper geregeld):
//    - config_inlinecomp.php is al ge-require'd → $VAPID_PUBLIC /
//      $VAPID_PRIVATE / $VAPID_SUBJECT in $GLOBALS (buiten webroot).
//    - vendor/ met minishlink/web-push staat in de projectroot
//      (handmatig via SFTP geüpload: `composer require minishlink/web-push`
//      lokaal draaien, dan de vendor/-map uploaden).
//
//  Alles is defensief: ontbreekt vendor/ of VAPID, dan is pushBeschikbaar()
//  gewoon false en gebeurt er niets (geen fatale fout in de aanroeper).
// ============================================================

$__push_autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__push_autoload)) require_once $__push_autoload;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/** VAPID-config uit de globals (config_inlinecomp.php). null = niet geconfigureerd. */
function pushVapid(): ?array {
    $pub  = $GLOBALS['VAPID_PUBLIC']  ?? '';
    $priv = $GLOBALS['VAPID_PRIVATE'] ?? '';
    $subj = $GLOBALS['VAPID_SUBJECT'] ?? 'mailto:info@example.com';
    if ($pub === '' || $priv === '') return null;
    return ['subject' => $subj, 'publicKey' => $pub, 'privateKey' => $priv];
}

/** Is versturen mogelijk? (lib aanwezig + VAPID geconfigureerd) */
function pushBeschikbaar(): bool {
    return class_exists(WebPush::class) && pushVapid() !== null;
}

/**
 * Stuur $payload (array → JSON) naar de opgegeven subscription-rijen.
 *   $subs: [ ['id'=>.., 'endpoint'=>.., 'p256dh'=>.., 'auth'=>..], ... ]
 * Verlopen abonnementen (404/410) worden uit de DB verwijderd.
 * Retour: ['verstuurd'=>n, 'verlopen'=>n, 'mislukt'=>n].
 */
function pushVerstuur(PDO $pdo, array $subs, array $payload): array {
    $stat = ['verstuurd' => 0, 'verlopen' => 0, 'mislukt' => 0];
    if (!$subs || !pushBeschikbaar()) return $stat;

    $webPush = new WebPush(['VAPID' => pushVapid()]);
    $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $idByEndpoint = [];
    foreach ($subs as $s) {
        if (empty($s['endpoint']) || empty($s['p256dh']) || empty($s['auth'])) continue;
        $idByEndpoint[$s['endpoint']] = (int) $s['id'];
        $webPush->queueNotification(
            Subscription::create([
                'endpoint' => $s['endpoint'],
                'keys'     => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
            ]),
            $json
        );
    }

    $delIds = [];
    foreach ($webPush->flush() as $report) {
        $ep = method_exists($report, 'getEndpoint') ? $report->getEndpoint() : '';
        if ($report->isSuccess()) { $stat['verstuurd']++; continue; }
        if ($report->isSubscriptionExpired()) {
            $stat['verlopen']++;
            if ($ep !== '' && isset($idByEndpoint[$ep])) $delIds[] = $idByEndpoint[$ep];
        } else {
            $stat['mislukt']++;
        }
    }

    if ($delIds) {
        $ph = implode(',', array_fill(0, count($delIds), '?'));
        $pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($ph)")->execute($delIds);
    }
    return $stat;
}
