<?php
// ============================================================
//  InlineComp – Tussenklassement voor startlijst-seeding
//
//  GET ?competition_id=X&dc_id=Y[&distance_id=Z]
//
//  Berekent de tussenstand op basis van al afgesloten afstanden
//  (uitslag_afstand) voor deze competition + DC.
//  distance_id (optioneel): wordt UITGESLOTEN van de berekening
//  (de afstand die nu geloot wordt is nog niet klaar).
//
//  Respons:
//  {
//    "ranking": [
//      { "person_license": "...", "full_name": "...", "rang": 1,
//        "totaal_punten": 2.0, "afstanden": 2 },
//      ...
//    ],
//    "afstanden": ["500 meter", "1500 meter"],   // afstanden die meegeteld zijn
//    "heeft_data": true
//  }
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
requireAuth($pdo);

$compId  = trim($_GET['competition_id'] ?? '');
$dcId    = trim($_GET['dc_id']          ?? '');
$distId  = trim($_GET['distance_id']    ?? '');

if (!$compId || !$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_id zijn verplicht']);
    exit;
}

try {
    // ── Welke afstanden zijn al afgesloten? ───────────────────────────────────
    $afstandSql    = $distId ? 'AND distance_id <> ?' : '';
    $afstandParams = $distId ? [$compId, $dcId, $distId] : [$compId, $dcId];

    $afStmt = $pdo->prepare("
        SELECT DISTINCT distance_naam
        FROM   uitslag_afstand
        WHERE  competition_id          = ?
          AND  distance_combination_id = ?
          {$afstandSql}
        ORDER BY distance_naam
    ");
    $afStmt->execute($afstandParams);
    $afstanden = array_column($afStmt->fetchAll(PDO::FETCH_ASSOC), 'distance_naam');

    if (empty($afstanden)) {
        echo json_encode(['ranking' => [], 'afstanden' => [], 'heeft_data' => false]);
        exit;
    }

    // ── Tussenklassement berekenen ────────────────────────────────────────────
    $rkSql = "
        SELECT   ua.person_license,
                 p.full_name,
                 p.short_name,
                 p.start_number,
                 SUM(COALESCE(ua.punten, 9999)) AS totaal_punten,
                 MIN(COALESCE(ua.rang,   9999)) AS beste_rang,
                 COUNT(*)                        AS afstanden
        FROM     uitslag_afstand ua
        JOIN     persons p ON p.license_key = ua.person_license
        WHERE    ua.competition_id          = ?
          AND    ua.distance_combination_id = ?
          {$afstandSql}
        GROUP BY ua.person_license, p.full_name, p.short_name, p.start_number
        ORDER BY totaal_punten ASC, beste_rang ASC
    ";
    $rkParams = $distId ? [$compId, $dcId, $distId] : [$compId, $dcId];
    $rkStmt   = $pdo->prepare($rkSql);
    $rkStmt->execute($rkParams);
    $rows = $rkStmt->fetchAll(PDO::FETCH_ASSOC);

    $ranking = [];
    foreach ($rows as $i => $row) {
        $ranking[] = [
            'person_license' => $row['person_license'],
            'full_name'      => $row['full_name'],
            'short_name'     => $row['short_name'],
            'start_number'   => $row['start_number'],
            'rang'           => $i + 1,
            'totaal_punten'  => (float)$row['totaal_punten'],
            'beste_rang'     => (int)$row['beste_rang'],
            'afstanden'      => (int)$row['afstanden'],
        ];
    }

    echo json_encode([
        'ranking'    => $ranking,
        'afstanden'  => $afstanden,
        'heeft_data' => true,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
