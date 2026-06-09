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
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // KNSB-categorie-codes (bv DKA, HP1) zodat de frontend een exacte
        // mismatch-check kan doen vóór de operator de loting genereert.
        // Filtert lege strings uit het GROUP_CONCAT-resultaat.
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
        ];
    }
    echo json_encode(['bronnen' => $bronnen], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
