<?php
// InlineComp – Statistieken over de check-pagina (/check/)
// Alleen beschikbaar voor ingelogde gebruikers met rol owner of admin.
//
// GET /api/check_stats.php
// Response: zie de sleutels onderaan (actief/echt/hits/peak/hourly).
//
// Filter tegen bots / previews werkt op user_agent (kolom in check_visits).
// Rijen zonder user_agent (van vóór de migratie) vallen terug op de oude
// duration/hits-heuristiek zodat historische data zichtbaar blijft.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$user = requireAuth($pdo);

if (!in_array($user['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen toegang']);
    exit;
}

$botRegex =
    'bot|crawl|spider|'
    . 'whatsapp|telegram|snap url|facebookexternal|applebot|'
    . 'linkedin|slackbot|discordbot|twitterbot|skypeuripreview|'
    . 'yandex|duckduckbot|baiduspider|'
    . 'googleassociationservice|networkingextension|'
    . 'python-|go-http|java/|curl/|wget';

$echtCond = "("
    . "(user_agent IS NOT NULL AND user_agent NOT REGEXP :botRegex)"
    . " OR (user_agent IS NULL AND (TIMESTAMPDIFF(SECOND, first_seen, last_seen) >= 10 OR hits >= 3))"
    . ")";

try {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN last_seen  > NOW() - INTERVAL 5 MINUTE THEN 1 ELSE 0 END) AS actief,
            SUM(CASE WHEN first_seen >= CURDATE()                THEN 1 ELSE 0 END) AS actief_vandaag,
            SUM(CASE WHEN first_seen >= CURDATE() AND $echtCond  THEN 1 ELSE 0 END) AS actief_vandaag_echt,
            COUNT(*)                                                                AS totaal_uniek,
            SUM(CASE WHEN $echtCond                              THEN 1 ELSE 0 END) AS totaal_uniek_echt,
            COALESCE(SUM(hits), 0)                                                  AS totaal_hits
        FROM check_visits
    ");
    $stmt->execute([':botRegex' => $botRegex]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // peak_today live berekenen met bot-filter. Zie public_stats.php.
    $peak = $pdo->prepare("SELECT peak_today_date, peak_all_time, peak_all_time_at
                           FROM peak_stats WHERE scope = 'check'");
    $peak->execute();
    $pk = $peak->fetch(PDO::FETCH_ASSOC) ?: [];

    $peakStmt = $pdo->prepare("
        SELECT UNIX_TIMESTAMP(first_seen) AS a, UNIX_TIMESTAMP(last_seen) + 300 AS b
        FROM check_visits
        WHERE last_seen >= CURDATE() AND $echtCond
    ");
    $peakStmt->execute([':botRegex' => $botRegex]);
    $events = [];
    foreach ($peakStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [(int)$r['a'], +1];
        $events[] = [(int)$r['b'], -1];
    }
    usort($events, fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
    $peakToday = 0; $huidig = 0;
    foreach ($events as [$t, $d]) {
        $huidig += $d;
        if ($huidig > $peakToday) $peakToday = $huidig;
    }

    // Uur-verdeling: gelijktijdig actief per uur. Zie public_stats.php voor
    // rationale (overlap-telling, PHP-side tz-conversie).
    $hourStmt = $pdo->prepare("
        SELECT first_seen, last_seen
        FROM check_visits
        WHERE last_seen >= CURDATE() AND $echtCond
    ");
    $hourStmt->execute([':botRegex' => $botRegex]);
    // "Vandaag" = server-CURDATE (matcht de andere tegels). Zie public_stats.php.
    $tzNL   = new DateTimeZone('Europe/Amsterdam');
    $tzSrv  = new DateTimeZone(date_default_timezone_get());
    $hourly = array_fill(0, 24, 0);
    $startVanDag = new DateTime('today', $tzSrv);
    foreach ($hourStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fs = new DateTime($r['first_seen'], $tzSrv);
        $ls = new DateTime($r['last_seen'],  $tzSrv);
        if ($ls < $startVanDag) continue;
        $begin = max($fs, $startVanDag);
        $begin->setTimezone($tzNL);
        $ls->setTimezone($tzNL);
        $hBegin = (int)$begin->format('G');
        $hEind  = (int)$ls->format('G');
        if ($hEind < $hBegin) $hEind += 24;
        for ($h = $hBegin; $h <= $hEind; $h++) $hourly[$h % 24]++;
    }

    // Weekverdeling laatste 52 weken (jaartrend). Zie public_stats.php.
    $wStmt = $pdo->prepare("
        SELECT YEARWEEK(first_seen, 1) AS yw, COUNT(*) AS n
        FROM check_visits
        WHERE first_seen >= DATE_SUB(CURDATE(), INTERVAL 52 WEEK) AND $echtCond
        GROUP BY yw
    ");
    $wStmt->execute([':botRegex' => $botRegex]);
    $weekly = [];
    $weekIdx = [];
    $nu = new DateTime('now', $tzSrv);
    for ($i = 51; $i >= 0; $i--) {
        $d = clone $nu; $d->modify("-{$i} weeks");
        $yw = (int)$d->format('oW');
        $weekly[] = ['label' => 'W' . $d->format('W'), 'n' => 0];
        $weekIdx[$yw] = count($weekly) - 1;
    }
    foreach ($wStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $yw = (int)$r['yw'];
        if (isset($weekIdx[$yw])) $weekly[$weekIdx[$yw]]['n'] = (int)$r['n'];
    }

    echo json_encode([
        'actief'              => (int)($row['actief']              ?? 0),
        'actief_vandaag'      => (int)($row['actief_vandaag']      ?? 0),
        'actief_vandaag_echt' => (int)($row['actief_vandaag_echt'] ?? 0),
        'totaal_uniek'        => (int)($row['totaal_uniek']        ?? 0),
        'totaal_uniek_echt'   => (int)($row['totaal_uniek_echt']   ?? 0),
        'totaal_hits'         => (int)($row['totaal_hits']         ?? 0),
        'peak_today'          => $peakToday,
        'peak_all_time'       => (int)($pk['peak_all_time']        ?? 0),
        'peak_at'             => $pk['peak_all_time_at']           ?? null,
        'hourly'              => $hourly,
        'weekly'              => $weekly,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
