<?php
// ============================================================
//  InlineComp – beschikbare seeding-bronnen (uitslag_afstand)
//
//  GET ?competition_id=UUID
//  Geeft de afstand-uitslagen terug die als seeding-bron gebruikt
//  kunnen worden voor de methode "Op afstand-uitslag" — bv. een via
//  de helper geïmporteerde 200m-uitslag om de 500m op te seeden.
//
//  Respons: { bronnen: [ { dc_id, dc_naam, distance_id, distance_naam,
//                          aantal, met_rang } ] }
//  Alleen afstanden met minstens één rang-rij zijn bruikbaar.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$compId = trim($_GET['competition_id'] ?? '');
if ($compId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id verplicht']);
    exit;
}

try {
    // ── Vastgelegde uitslagen (klassieke bron) ─────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            ua.distance_combination_id AS dc_id,
            ua.dc_naam,
            ua.distance_id,
            ua.distance_naam,
            COUNT(*)                 AS aantal,
            SUM(ua.rang IS NOT NULL) AS met_rang,
            GROUP_CONCAT(DISTINCT ua.categorie
                         ORDER BY ua.categorie SEPARATOR ',') AS cats_csv
        FROM uitslag_afstand ua
        WHERE ua.competition_id = ?
        GROUP BY ua.distance_combination_id, ua.dc_naam, ua.distance_id, ua.distance_naam
        HAVING met_rang > 0
        ORDER BY ua.dc_naam, ua.distance_naam
    ");
    $stmt->execute([$compId]);
    $bronnen = [];
    $vastgelegdeKeys = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cats = array_values(array_filter(
            array_map('trim', explode(',', $r['cats_csv'] ?? '')),
            fn($c) => $c !== ''
        ));
        $bronnen[] = [
            'dc_id'         => $r['dc_id'],
            'dc_naam'       => $r['dc_naam'],
            'distance_id'   => $r['distance_id'],
            'distance_naam' => $r['distance_naam'],
            'aantal'        => (int)$r['aantal'],
            'met_rang'      => (int)$r['met_rang'],
            'cats'          => $cats,
            'is_live'       => false,
        ];
        $vastgelegdeKeys[$r['dc_id'] . '|' . $r['distance_id']] = true;
    }

    // ── LIVE bronnen: DC × distance waar minstens 1 rit results heeft
    // maar nog geen uitslag_afstand. Voor in-event seeden tussen afstanden
    // (bv. 1000m HF seeden op 200m series-tijden zonder dat 200m al
    // vastgelegd hoeft te zijn). Best-tijd per rijder telt.
    $liveStmt = $pdo->prepare("
        SELECT
            h.distance_combination_id AS dc_id,
            dc.name                   AS dc_naam,
            h.distance_id,
            d.name                    AS distance_naam,
            COUNT(DISTINCT he.person_license) AS aantal,
            GROUP_CONCAT(DISTINCT p.category
                         ORDER BY p.category SEPARATOR ',') AS cats_csv
        FROM results              res
        JOIN heat_entries         he  ON he.id = res.heat_entry_id
        JOIN heats                h   ON h.id  = he.heat_id
        JOIN distance_combinations dc ON dc.id = h.distance_combination_id
        JOIN distances            d   ON d.id  = h.distance_id
        JOIN persons              p   ON p.license_key = he.person_license
        WHERE h.competition_id = ?
          AND COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
          AND (res.sanctie IS NULL OR res.sanctie NOT IN ('DQ-SF','DQ-DF'))
        GROUP BY h.distance_combination_id, dc.name, h.distance_id, d.name
    ");
    $liveStmt->execute([$compId]);
    foreach ($liveStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $r['dc_id'] . '|' . $r['distance_id'];
        // Skip als al een vastgelegde uitslag bestaat — die heeft voorrang.
        if (isset($vastgelegdeKeys[$k])) continue;
        $cats = array_values(array_filter(
            array_map('trim', explode(',', $r['cats_csv'] ?? '')),
            fn($c) => $c !== ''
        ));
        $bronnen[] = [
            'dc_id'         => $r['dc_id'],
            'dc_naam'       => $r['dc_naam'],
            'distance_id'   => $r['distance_id'],
            'distance_naam' => $r['distance_naam'] . ' [LIVE]',
            'aantal'        => (int)$r['aantal'],
            'met_rang'      => (int)$r['aantal'],
            'cats'          => $cats,
            'is_live'       => true,
        ];
    }
    echo json_encode(['bronnen' => $bronnen], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
