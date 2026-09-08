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
require_once __DIR__ . '/_uitslag_helper.php';   // alleRondesCompleet
requireAuth($pdo);

$compId  = trim($_GET['competition_id'] ?? '');
$dcId    = trim($_GET['dc_id']          ?? '');
$distId  = trim($_GET['distance_id']    ?? '');
// Gesplitste DC: alleen de rijders van deze split meetellen (bijv. "DP2").
// Zonder dit filter kreeg een split de ranking van de HELE oorspronkelijke DC
// (beide splits). Spiegelt startlijst_genereer.php (category_filter → p.category).
$catFilterRaw = trim($_GET['category_filter'] ?? '');
$catFilter    = $catFilterRaw
    ? array_values(array_filter(array_map('trim', explode(',', $catFilterRaw))))
    : [];

if (!$compId || !$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_id zijn verplicht']);
    exit;
}

try {
    // ── Split-filter ──────────────────────────────────────────────────────────
    // Bij een gesplitste DC krijgt elke split-groep een EIGEN kopie van elke
    // afstand (distances.target_group = split-groep, bv. "HP2") en de uitslag
    // wordt op die eigen kopie vastgelegd. Zonder filter telt het tussen-
    // klassement álle kopieën van dezelfde DC mee (ook de andere splits) →
    // dubbele afstanden ("tijdrit ×2") en dubbele/verkeerde rijders.
    //
    // Voorkeur: filter op de EIGEN distances van deze split, afgeleid uit de
    // target_group van de gekozen afstand — de bron van waarheid, ook als
    // categorie-codes rommelig zijn. Zonder target_group (oudere data) valt 't
    // terug op category_filter (rijders van deze categorie). Subqueries i.p.v.
    // joins zodat er geen alias-conflict met de bestaande persons-join ontstaat.
    $splitTg = '';
    if ($distId !== '') {
        $tgStmt = $pdo->prepare("SELECT target_group FROM distances WHERE id = ? LIMIT 1");
        $tgStmt->execute([$distId]);
        $tg = $tgStmt->fetchColumn();
        if (is_string($tg) && $tg !== '') $splitTg = $tg;
    }
    $filtSql = ''; $filtParams = [];
    if ($splitTg !== '') {
        $filtSql    = "AND ua.distance_id IN (
                          SELECT id FROM distances
                          WHERE distance_combination_id = ? AND target_group = ?)";
        $filtParams = [$dcId, $splitTg];
    } elseif ($catFilter) {
        $catPh      = implode(',', array_fill(0, count($catFilter), '?'));
        $filtSql    = "AND ua.person_license IN (
                          SELECT license_key FROM persons WHERE category IN ($catPh))";
        $filtParams = $catFilter;
    }

    // ── Welke afstanden zijn al afgesloten? ───────────────────────────────────
    $afstandSql    = $distId ? 'AND ua.distance_id <> ?' : '';
    $afstandParams = $distId ? [$compId, $dcId, $distId] : [$compId, $dcId];

    $afStmt = $pdo->prepare("
        SELECT DISTINCT ua.distance_id, ua.distance_naam
        FROM   uitslag_afstand ua
        WHERE  ua.competition_id          = ?
          AND  ua.distance_combination_id = ?
          {$afstandSql}
          {$filtSql}
        ORDER BY ua.distance_naam
    ");
    $afStmt->execute(array_merge($afstandParams, $filtParams));
    $alleAfstanden = $afStmt->fetchAll(PDO::FETCH_ASSOC);

    // Alleen COMPLETE afstanden meetellen: een afstand waarvan (bijv.) een tijd
    // is verwijderd is niet meer compleet → de oude, ongeldig geworden uitslag
    // mag het tussenklassement niet vervuilen. distance_id-loos (single-afstand)
    // kunnen we niet gericht uitsluiten → die telt mee.
    $incompleet = [];   // distance_id's die NIET meer compleet zijn
    $afstanden  = [];   // namen van de wél-complete afstanden
    foreach ($alleAfstanden as $a) {
        $dId = (string)($a['distance_id'] ?? '');
        $compleet = true;
        if ($dId !== '') {
            try {
                $chk = alleRondesCompleet($pdo, $compId, [$dcId], $dId);
                $compleet = !empty($chk['compleet']);
            } catch (Throwable $e) { $compleet = true; }   // bij twijfel meetellen
        }
        if ($compleet) $afstanden[] = $a['distance_naam'];
        else           $incompleet[] = $dId;
    }

    if (empty($afstanden)) {
        echo json_encode(['ranking' => [], 'afstanden' => [], 'heeft_data' => false]);
        exit;
    }

    // Uitsluit-clausule voor de incomplete afstanden (NULL blijft meetellen).
    $incSql    = '';
    $incParams = [];
    if ($incompleet) {
        $ph        = implode(',', array_fill(0, count($incompleet), '?'));
        $incSql    = "AND (distance_id IS NULL OR distance_id NOT IN ($ph))";
        $incParams = $incompleet;
    }

    // ── Tussenklassement berekenen (alleen op complete afstanden) ─────────────
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
          {$incSql}
          {$filtSql}
        GROUP BY ua.person_license, p.full_name, p.short_name, p.start_number
        ORDER BY totaal_punten ASC, beste_rang ASC
    ";
    $rkParams = array_merge($afstandParams, $incParams, $filtParams);
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
