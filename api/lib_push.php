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

/** Is versturen mogelijk? (lib + PSR-18 HTTP-client aanwezig + VAPID geconfigureerd) */
function pushBeschikbaar(): bool {
    // minishlink v11 discovery't een PSR-18 client (Guzzle). Ontbreekt die, dan
    // zou new WebPush() fatalen — deze guard voorkomt dat we events enqueuen/
    // claimen die we tóch niet kunnen versturen (geen stil verlies).
    return class_exists(WebPush::class)
        && class_exists('GuzzleHttp\\Client')
        && pushVapid() !== null;
}

/**
 * Kern-verzender: stuur een lijst items, elk met een EIGEN payload.
 *   $items: [ ['id'=>.., 'endpoint'=>.., 'p256dh'=>.., 'auth'=>.., 'payload'=>[...]], ... ]
 * Verlopen abonnementen (404/410) worden uit de DB verwijderd.
 * Retour: ['verstuurd'=>n, 'verlopen'=>n, 'mislukt'=>n].
 */
function _pushSendItems(PDO $pdo, array $items): array {
    $stat = ['verstuurd' => 0, 'verlopen' => 0, 'mislukt' => 0];
    if (!$items || !pushBeschikbaar()) return $stat;

    $webPush = new WebPush(['VAPID' => pushVapid()]);
    $idByEndpoint = [];
    foreach ($items as $it) {
        if (empty($it['endpoint']) || empty($it['p256dh']) || empty($it['auth'])) continue;
        $idByEndpoint[$it['endpoint']] = (int) $it['id'];
        $webPush->queueNotification(
            Subscription::create([
                'endpoint' => $it['endpoint'],
                'keys'     => ['p256dh' => $it['p256dh'], 'auth' => $it['auth']],
            ]),
            json_encode($it['payload'] ?? [], JSON_UNESCAPED_UNICODE)
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

/**
 * Stuur ÉÉN vaste $payload naar de opgegeven subscription-rijen (voor de
 * test-melding). Voor gerichte events zie pushEventNaarVolgers().
 */
function pushVerstuur(PDO $pdo, array $subs, array $payload): array {
    $items = [];
    foreach ($subs as $s) {
        $items[] = [
            'id' => $s['id'] ?? 0, 'endpoint' => $s['endpoint'] ?? '',
            'p256dh' => $s['p256dh'] ?? '', 'auth' => $s['auth'] ?? '',
            'payload' => $payload,
        ];
    }
    return _pushSendItems($pdo, $items);
}

// ── Fase 2/3: outbox + gerichte, gepersonaliseerde verzending ───────────────

/** Geldige event-typen ↔ de opt-in-kolom op push_subscriptions (whitelist). */
function _pushTypeKolom(string $type): string {
    if ($type === 'uitslag') return 'notif_uitslag';
    if ($type === 'bericht') return 'notif_bericht';
    return 'notif_loting';
}

/**
 * Zet één gebeurtenis in de outbox (snelle INSERT, géén HTTPS). $type bepaalt
 * welke opt-in telt ('loting'|'uitslag'|'bericht'). $licenses = de person_licenses
 * van de betrokken rijders; de flush zoekt daar de volger-abonnementen bij.
 * $scope='global' = broadcast (mededeling app-breed) → géén licenties nodig,
 * gaat naar álle abonnementen met dit type aan. Roep aan NÁ de commit.
 */
function pushEnqueue(PDO $pdo, string $type, array $licenses, array $payload, string $scope = 'all'): void {
    if (!pushBeschikbaar()) return;   // push niet geconfigureerd → niets in de outbox zetten
    $type  = in_array($type, ['loting', 'uitslag', 'bericht'], true) ? $type : 'loting';
    $scope = ($scope === 'global') ? 'global' : 'all';
    $licenses = array_values(array_unique(array_filter(array_map('strval', $licenses), 'strlen')));
    if ($scope !== 'global' && !$licenses) return;   // gericht event zonder licenties = niets te doen
    $pdo->prepare("INSERT INTO push_outbox (scope, type, licenses, payload) VALUES (?, ?, ?, ?)")
        ->execute([$scope, $type, json_encode($licenses), json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

/** Kies de taal-variant uit een {nl,en,de,fr}-array (fallback → en → nl). */
function _pushKies(array $vals, string $lang): string {
    return $vals[$lang] ?? $vals['en'] ?? $vals['nl'] ?? '';
}

/** Meldingtitel per type in 4 talen. $globaal = app-brede mededeling. */
function _pushTitel(string $type, bool $globaal = false): array {
    if ($type === 'uitslag') return ['nl'=>'🏁 Uitslag binnen','en'=>'🏁 Result in','de'=>'🏁 Ergebnis da','fr'=>'🏁 Résultat reçu'];
    if ($type === 'bericht') return $globaal
        ? ['nl'=>'📢 Algemene mededeling','en'=>'📢 General announcement','de'=>'📢 Allgemeine Mitteilung','fr'=>'📢 Annonce générale']
        : ['nl'=>'📢 Mededeling','en'=>'📢 Announcement','de'=>'📢 Mitteilung','fr'=>'📢 Annonce'];
    return ['nl'=>'🚩 Loting gereed','en'=>'🚩 Draw ready','de'=>'🚩 Auslosung fertig','fr'=>'🚩 Tirage prêt'];
}

/** Wie-tekst per taal: 1 rijder = naam; meer = telwoord ("12 rijders"). Zo staat
 *  bij grote trainingsgroepen een eerlijk aantal i.p.v. willekeurige namen; de
 *  categorie staat toch al in de context. */
function _pushWieTekst(int $aantal, array $namen, string $lang): string {
    if ($aantal <= 0) return '';
    if ($aantal === 1) {
        if (isset($namen[0])) return $namen[0];
        return ['nl'=>'1 rijder','en'=>'1 rider','de'=>'1 Fahrer','fr'=>'1 patineur'][$lang] ?? '1 rijder';
    }
    $tpl = ['nl'=>'%d rijders','en'=>'%d riders','de'=>'%d Fahrer','fr'=>'%d patineurs'];
    return sprintf($tpl[$lang] ?? $tpl['nl'], $aantal);
}

/** Geldige abonnee-taal (fallback nl). */
function _pushLang($v): string {
    $v = (string) $v;
    return in_array($v, ['nl', 'en', 'de', 'fr'], true) ? $v : 'nl';
}

/**
 * Stuur ÉÉN gepersonaliseerde push per volger-abonnement (coach én public) dat
 * minstens één van $licenses volgt en dit meldingtype aan heeft staan.
 *   - GROUP BY ps.id = dedup: één push per abonnement, ook bij meerdere rijders.
 *   - de tekst noemt per ontvanger díens eigen gevolgde rijder(s) uit dit event.
 */
function pushEventNaarVolgers(PDO $pdo, string $type, array $licenses, array $payload): array {
    $stat = ['verstuurd' => 0, 'verlopen' => 0, 'mislukt' => 0];
    $licenses = array_values(array_unique(array_filter(array_map('strval', $licenses), 'strlen')));
    if (!$licenses || !pushBeschikbaar()) return $stat;

    $kol = _pushTypeKolom($type);
    $ph  = implode(',', array_fill(0, count($licenses), '?'));
    $personaliseer = ($type !== 'bericht');   // mededeling = wedstrijd-breed, geen naam ervoor

    // Namen van de betrokken rijders (alleen nodig voor de gepersonaliseerde tekst).
    $naamMap = [];
    if ($personaliseer) {
        $nm = $pdo->prepare("
            SELECT license_key, COALESCE(NULLIF(short_name,''), full_name, license_key) AS naam
            FROM   persons WHERE license_key IN ($ph)
        ");
        $nm->execute($licenses);
        foreach ($nm->fetchAll(PDO::FETCH_ASSOC) as $r) $naamMap[$r['license_key']] = $r['naam'];
    }

    // Per abonnement-rij een verzend-item in de táal van dat abonnement: titel +
    // context uit de {nl,en,de,fr}-payload, met bij loting/uitslag de wie-prefix.
    $bouw = function (array $sub) use ($personaliseer, $payload, $naamMap): array {
        $lang    = _pushLang($sub['lang'] ?? 'nl');
        $context = _pushKies($payload['context'] ?? [], $lang);
        $body    = $context;
        if ($personaliseer) {
            $matched = array_values(array_filter(array_unique(explode(',', (string) ($sub['matched'] ?? '')))));
            $namen = [];
            foreach ($matched as $lic) if (isset($naamMap[$lic])) $namen[] = $naamMap[$lic];
            $wie = _pushWieTekst(count($matched), $namen, $lang);
            if ($wie !== '') $body = $context !== '' ? $wie . ' — ' . $context : $wie;
        }
        return [
            'id' => $sub['id'], 'endpoint' => $sub['endpoint'],
            'p256dh' => $sub['p256dh'], 'auth' => $sub['auth'],
            'payload' => [
                'title' => _pushKies($payload['title'] ?? [], $lang),
                'body'  => $body,
                'url'   => $payload['url'] ?? './',
                'tag'   => $payload['tag'] ?? null,
            ],
        ];
    };

    $items = [];

    // Coach-volgers (roster = coach_athletes). 'matched' = de rijders van díe
    // coach die in dit event zitten (voor de gepersonaliseerde tekst).
    $cs = $pdo->prepare("
        SELECT ps.id, ps.endpoint, ps.p256dh, ps.auth, ps.lang,
               GROUP_CONCAT(ca.person_license) AS matched
        FROM   push_subscriptions ps
        JOIN   coach_athletes ca
               ON ca.coach_account_id = ps.coach_account_id
              AND ca.person_license IN ($ph)
        WHERE  ps.scope = 'coach' AND ps.`$kol` = 1
        GROUP  BY ps.id
    ");
    $cs->execute($licenses);
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $s) $items[] = $bouw($s);

    // Public-volgers (junction push_sub_licenses, gespiegeld uit localStorage).
    $psx = $pdo->prepare("
        SELECT ps.id, ps.endpoint, ps.p256dh, ps.auth, ps.lang,
               GROUP_CONCAT(psl.person_license) AS matched
        FROM   push_subscriptions ps
        JOIN   push_sub_licenses psl
               ON psl.subscription_id = ps.id
              AND psl.person_license IN ($ph)
        WHERE  ps.scope = 'public' AND ps.`$kol` = 1
        GROUP  BY ps.id
    ");
    $psx->execute($licenses);
    foreach ($psx->fetchAll(PDO::FETCH_ASSOC) as $s) $items[] = $bouw($s);

    return $items ? _pushSendItems($pdo, $items) : $stat;
}

/**
 * Broadcast: stuur $payload naar ÁLLE abonnementen met dit type aan — voor een
 * globale, app-brede mededeling die niet aan rijders gebonden is.
 */
function pushBroadcast(PDO $pdo, string $type, array $payload): array {
    $stat = ['verstuurd' => 0, 'verlopen' => 0, 'mislukt' => 0];
    if (!pushBeschikbaar()) return $stat;
    $kol   = _pushTypeKolom($type);
    $subs  = $pdo->query("SELECT id, endpoint, p256dh, auth, lang FROM push_subscriptions WHERE `$kol` = 1")
                 ->fetchAll(PDO::FETCH_ASSOC);
    $items = [];
    foreach ($subs as $s) {
        $lang = _pushLang($s['lang'] ?? 'nl');
        $items[] = [
            'id' => $s['id'], 'endpoint' => $s['endpoint'],
            'p256dh' => $s['p256dh'], 'auth' => $s['auth'],
            'payload' => [
                'title' => _pushKies($payload['title'] ?? [], $lang),
                'body'  => _pushKies($payload['context'] ?? [], $lang),
                'url'   => $payload['url'] ?? './',
                'tag'   => $payload['tag'] ?? null,
            ],
        ];
    }
    return $items ? _pushSendItems($pdo, $items) : $stat;
}

/**
 * Throttled piggyback-flush van de outbox (géén cron). Draait max ~1×/8s over
 * alle requests heen (tmp-file-throttle, zoals jury-cleanup). Claimt een kleine
 * batch (lock+delete in transactie) en verstuurt die daarna. Volledig defensief.
 * Aan te roepen vanuit een vaak-gehit endpoint (api/meldingen.php).
 */
function pushFlushOutbox(PDO $pdo, int $max = 15, bool $force = false): int {
    // $force: operator-triggers (loting/heat) versturen hun event meteen. Alleen
    // de hoog-frequente meldingen-poll wordt gethrottled (~1x/8s) tegen flush-storms.
    $flag = sys_get_temp_dir() . '/ic_push_flush.flag';
    if (!$force && is_file($flag) && (time() - (int) @filemtime($flag)) < 8) return 0;
    @touch($flag);
    // Vangnet: verouderde events opruimen (push tijdelijk uit geweest, of lang
    // niemand gepolld). Een loting/uitslag van >1u terug is geen zinvolle melding
    // meer — zo kan de tabel sowieso nooit oplopen.
    try { $pdo->exec("DELETE FROM push_outbox WHERE created_at < NOW() - INTERVAL 1 HOUR"); } catch (\Throwable $e) {}
    if (!pushBeschikbaar()) return 0;

    $rows = [];
    try {
        $pdo->beginTransaction();
        $st = $pdo->query("SELECT id, scope, type, licenses, payload FROM push_outbox ORDER BY id LIMIT " . (int) $max . " FOR UPDATE");
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
        $type    = $r['type']  ?: 'loting';
        $scope   = $r['scope'] ?: 'all';
        $payload = json_decode($r['payload'] ?? '{}', true) ?: [];
        if (!$payload) continue;
        try {
            if ($scope === 'global') {
                pushBroadcast($pdo, $type, $payload); $n++;   // globale mededeling → iedereen
            } else {
                $licenses = json_decode($r['licenses'] ?? '[]', true) ?: [];
                if ($licenses) { pushEventNaarVolgers($pdo, $type, $licenses, $payload); $n++; }
            }
        } catch (\Throwable $e) { /* nooit de flush laten crashen */ }
    }
    return $n;
}
