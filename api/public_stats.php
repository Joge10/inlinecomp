<?php
// InlineComp – Statistieken over de publieke pagina (/public/)
// Alleen beschikbaar voor ingelogde gebruikers met rol owner of admin.
//
// GET /api/public_stats.php
// Response: zie de sleutels onderaan (actief/echt/hits/peak/hourly).
//
// Filter tegen bots / previews werkt op user_agent (kolom in public_visits).
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

// Bot/preview-patronen (case-insensitive). Bekende bronnen die alleen
// link-previews of crawls doen — GEEN echt leesgedrag.
$botRegex =
    'bot|crawl|spider|'
    . 'whatsapp|telegram|snap url|facebookexternal|applebot|'
    . 'linkedin|slackbot|discordbot|twitterbot|skypeuripreview|'
    . 'yandex|duckduckbot|baiduspider|'
    . 'googleassociationservice|networkingextension|'
    . 'python-|go-http|java/|curl/|wget';

// Echt = (a) user_agent bekend en geen bot, OF (b) user_agent NULL maar
// duration/hits-heuristiek slaagt (backward-compat voor pre-migratie rijen).
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
        FROM public_visits
    ");
    $stmt->execute([':botRegex' => $botRegex]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Piek-data. peak_all_time komt uit peak_stats. peak_today berekenen we
    // live met bot-filter zodat 'ie matcht met de hourly-grafiek (de
    // opgeslagen peak_today is raw — inclusief WhatsApp/bot previews die
    // tegelijk in het 5-min-window landden).
    $peak = $pdo->prepare("SELECT peak_today_date, peak_all_time, peak_all_time_at
                           FROM peak_stats WHERE scope = 'public'");
    $peak->execute();
    $pk = $peak->fetch(PDO::FETCH_ASSOC) ?: [];

    // Peak vandaag via sweep-line: elke sessie leeft in [first_seen, last_seen+5min].
    // Sort events, count enter (+1) vs exit (-1). Max count = piek concurrent.
    $peakStmt = $pdo->prepare("
        SELECT UNIX_TIMESTAMP(first_seen) AS a, UNIX_TIMESTAMP(last_seen) + 300 AS b
        FROM public_visits
        WHERE last_seen >= CURDATE() AND $echtCond
    ");
    $peakStmt->execute([':botRegex' => $botRegex]);
    $events = [];
    foreach ($peakStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [(int)$r['a'], +1];
        $events[] = [(int)$r['b'], -1];
    }
    // Sort ASC op timestamp; bij gelijk: exit voor enter zodat een sessie
    // die net eindigt op moment X niet met een nieuwe overlapt.
    usort($events, fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
    $peakToday = 0; $huidig = 0;
    foreach ($events as [$t, $d]) {
        $huidig += $d;
        if ($huidig > $peakToday) $peakToday = $huidig;
    }

    // Uur-verdeling: hoeveel sessies waren gelijktijdig actief in dat uur.
    // "Actief in uur X" = sessie overlapt met interval [X:00, X+1:00). Dus
    // een sessie van 09:15–10:20 telt in uur 9 én 10. Sluit aan bij de
    // "Piek gelijktijdig"-tegel (max concurrent per moment). Berekening
    // in PHP zodat we onafhankelijk zijn van CONVERT_TZ + tz-tabellen op
    // de MySQL-server: we halen ruwe timestamps op en zetten ze om naar
    // Europe/Amsterdam via DateTime.
    $hourStmt = $pdo->prepare("
        SELECT first_seen, last_seen
        FROM public_visits
        WHERE last_seen >= CURDATE() AND $echtCond
    ");
    $hourStmt->execute([':botRegex' => $botRegex]);
    // "Vandaag" = server-CURDATE (matcht de andere tegels). Uur-labels tonen
    // we wél in NL-tijd; wat "vandaag" is blijft server-gestuurd zodat
    // grafiek en tegels dezelfde bezoekers dekken (ook bij tz-mismatch).
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
        // Sessie kan over middernacht NL heenlopen (bv. 23:xx → 01:xx)
        if ($hEind < $hBegin) $hEind += 24;
        for ($h = $hBegin; $h <= $hEind; $h++) $hourly[$h % 24]++;
    }

    // Weekverdeling laatste 52 weken (voor jaartrend-grafiek).
    // Unieke echte bezoekers per ISO-week. yearweek key = YYYYWW zoals
    // MySQL YEARWEEK(x, 1) teruggeeft; matcht PHP DateTime->format('oW').
    $wStmt = $pdo->prepare("
        SELECT YEARWEEK(first_seen, 1) AS yw, COUNT(*) AS n
        FROM public_visits
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
