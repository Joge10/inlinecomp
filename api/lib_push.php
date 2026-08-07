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

// ── Fase 2: outbox + gerichte verzending per gebeurtenis ────────────────────

/**
 * Zet één gebeurtenis in de outbox (snelle INSERT, géén HTTPS). $licenses = de
 * person_licenses van de rijders in de DC/heat; de flush zoekt daar de volger-
 * abonnementen bij. Roep dit aan vanuit een trigger NÁ de commit.
 */
function pushEnqueue(PDO $pdo, array $licenses, array $payload, string $scope = 'coach'): void {
    if (!pushBeschikbaar()) return;   // push niet geconfigureerd → niets in de outbox zetten
    $licenses = array_values(array_unique(array_filter(array_map('strval', $licenses), 'strlen')));
    if (!$licenses) return;
    $pdo->prepare("INSERT INTO push_outbox (scope, licenses, payload) VALUES (?, ?, ?)")
        ->execute([$scope, json_encode($licenses), json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

/**
 * Stuur ÉÉN push per volger-abonnement dat minstens één van $licenses volgt.
 * DISTINCT op abonnement = dedup: een coach met 20 rijders in de DC krijgt 1 push.
 * (Public-scope volgt in Fase 3; nu alleen coach.)
 */
function pushNaarVolgers(PDO $pdo, array $licenses, array $payload, string $scope = 'coach'): array {
    $stat = ['verstuurd' => 0, 'verlopen' => 0, 'mislukt' => 0];
    $licenses = array_values(array_unique(array_filter(array_map('strval', $licenses), 'strlen')));
    if (!$licenses || !pushBeschikbaar() || $scope !== 'coach') return $stat;

    $ph = implode(',', array_fill(0, count($licenses), '?'));
    $st = $pdo->prepare("
        SELECT DISTINCT ps.id, ps.endpoint, ps.p256dh, ps.auth
        FROM   push_subscriptions ps
        JOIN   coach_athletes ca ON ca.coach_account_id = ps.coach_account_id
        WHERE  ps.scope = 'coach' AND ca.person_license IN ($ph)
    ");
    $st->execute($licenses);
    return pushVerstuur($pdo, $st->fetchAll(PDO::FETCH_ASSOC), $payload);
}

/**
 * Throttled piggyback-flush van de outbox (géén cron). Draait max ~1×/8s over
 * alle requests heen (tmp-file-throttle, zoals jury-cleanup). Claimt een kleine
 * batch (lock+delete in transactie) en verstuurt die daarna. Volledig defensief.
 * Aan te roepen vanuit een vaak-gehit endpoint (api/meldingen.php).
 */
function pushFlushOutbox(PDO $pdo, int $max = 15): int {
    $flag = sys_get_temp_dir() . '/ic_push_flush.flag';
    if (is_file($flag) && (time() - (int) @filemtime($flag)) < 8) return 0;
    @touch($flag);
    // Vangnet: verouderde events opruimen (push tijdelijk uit geweest, of lang
    // niemand gepolld). Een loting/uitslag van >1u terug is geen zinvolle melding
    // meer — zo kan de tabel sowieso nooit oplopen.
    try { $pdo->exec("DELETE FROM push_outbox WHERE created_at < NOW() - INTERVAL 1 HOUR"); } catch (\Throwable $e) {}
    if (!pushBeschikbaar()) return 0;

    $rows = [];
    try {
        $pdo->beginTransaction();
        $st = $pdo->query("SELECT id, scope, licenses, payload FROM push_outbox ORDER BY id LIMIT " . (int) $max . " FOR UPDATE");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $ids = array_column($rows, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM push_outbox WHERE id IN ($ph)")->execute($ids);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 0;
    }

    $n = 0;
    foreach ($rows as $r) {
        $licenses = json_decode($r['licenses'] ?? '[]', true) ?: [];
        $payload  = json_decode($r['payload']  ?? '{}', true) ?: [];
        if ($licenses && $payload) {
            try { pushNaarVolgers($pdo, $licenses, $payload, $r['scope'] ?: 'coach'); $n++; }
            catch (\Throwable $e) { /* nooit de flush laten crashen */ }
        }
    }
    return $n;
}
