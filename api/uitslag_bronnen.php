<?php
// ============================================================
//  InlineComp – beschikbare seeding-bronnen (uitslag_afstand)
//
//  GET ?competition_id=UUID
//  Geeft de afstand-uitslagen terug die als seeding-bron gebruikt
//  kunnen worden voor de methode "Op afstand-uitslag" — bv. de 500m
//  van deze wedstrijd als seed voor de puntenkoers van dezelfde wedstrijd.
//  Bron = altijd een afstand van DEZE wedstrijd (geen cross-wedstrijd).
//
//  Respons: { bronnen: [ { dc_id, dc_naam, distance_id, distance_naam,
//                          aantal, met_rang, is_leeg } ] }
//  Afstanden met een rang-rij zijn direct bruikbaar; afstanden zonder
//  uitslag verschijnen ook (is_leeg=true) voor een afhankelijke loting die
//  pas genereert zodra die bron-uitslag bevestigd wordt.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/_uitslag_helper.php';
$_authUser = requireAuth($pdo);

// Is de afstand NU compleet (alle heats gereden)? Een bron mag alleen als
// bevestigde seeding-bron gelden als 'ie compleet is — anders zou je loten op
// een onvolledige of achteraf aangepaste ("niet meer bevestigde") uitslag.
function _bronCompleet(PDO $pdo, string $compId, string $dcId, string $distId): bool {
    try {
        $r = alleRondesCompleet($pdo, $compId, [$dcId], $distId !== '' ? $distId : null);
        return !empty($r['compleet']);
    } catch (Throwable $e) {
        return true;   // bij twijfel niet blokkeren
    }
}

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
        $key = $r['dc_id'] . '|' . $r['distance_id'];
        $vastgelegdeKeys[$key] = true;   // voorrang boven live/lege
        $cats = array_values(array_filter(
            array_map('trim', explode(',', $r['cats_csv'] ?? '')),
            fn($c) => $c !== ''
        ));
        if (_bronCompleet($pdo, $compId, (string)$r['dc_id'], (string)$r['distance_id'])) {
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
        } else {
            // Vastgelegd maar NIET meer compleet (bv. tijd verwijderd na
            // vastleggen) → niet bruikbaar als bevestigde bron. Wél tonen als
            // "nog niet compleet" zodat je 'm als afhankelijke loting kunt
            // instellen (genereert dan zodra 'ie compleet + bevestigd is).
            $bronnen[] = [
                'dc_id'         => $r['dc_id'],
                'dc_naam'       => $r['dc_naam'],
                'distance_id'   => $r['distance_id'],
                'distance_naam' => $r['distance_naam'],
                'aantal'        => 0,
                'met_rang'      => 0,
                'cats'          => $cats,
                'is_live'       => false,
                'is_leeg'       => true,
                'incompleet'    => true,
            ];
        }
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
        $vastgelegdeKeys[$k] = true;   // ook live-key markeren als "al opgenomen"
        $cats = array_values(array_filter(
            array_map('trim', explode(',', $r['cats_csv'] ?? '')),
            fn($c) => $c !== ''
        ));
        if (_bronCompleet($pdo, $compId, (string)$r['dc_id'], (string)$r['distance_id'])) {
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
        } else {
            // Nog niet alle heats gereden → niet bruikbaar als bevestigde bron.
            $bronnen[] = [
                'dc_id'         => $r['dc_id'],
                'dc_naam'       => $r['dc_naam'],
                'distance_id'   => $r['distance_id'],
                'distance_naam' => $r['distance_naam'],
                'aantal'        => 0,
                'met_rang'      => 0,
                'cats'          => $cats,
                'is_live'       => false,
                'is_leeg'       => true,
                'incompleet'    => true,
            ];
        }
    }

    // ── LEGE bronnen: afstanden die nog GEEN uitslag/live-data hebben ──────
    // Nodig om een afhankelijke loting vooruit in te stellen: je wilt een
    // bron-afstand kunnen kiezen die nog niet verreden is (bv. de 500m als
    // bron voor de puntenkoers, vóór de 500m gereden is). De loting/generatie
    // wacht dan tot die bron bevestigd wordt (zie js/uitslag.js). We lezen
    // alle afstanden uit de distances-tabel en voegen toe wat nog niet in de
    // lijst staat, met met_rang = 0.
    // Categorieën uit de daadwerkelijke inschrijvingen (persons.category),
    // met terugval op dc.category_filter — nodig voor het categorie-overlap-
    // filter in de UI (een bron is alleen zinvol als 'ie een cat deelt met de
    // te-loten afstand).
    $legeStmt = $pdo->prepare("
        SELECT d.distance_combination_id AS dc_id,
               dc.name                   AS dc_naam,
               d.id                      AS distance_id,
               d.name                    AS distance_naam,
               COALESCE(
                   GROUP_CONCAT(DISTINCT p.category ORDER BY p.category SEPARATOR ','),
                   dc.category_filter
               )                         AS cats_csv
        FROM distances d
        JOIN distance_combinations dc ON dc.id = d.distance_combination_id
        LEFT JOIN entries e ON e.distance_combination_id = dc.id
        LEFT JOIN persons p ON p.license_key = e.person_license
        WHERE dc.competition_id = ?
        GROUP BY d.distance_combination_id, dc.name, d.id, d.name, dc.category_filter
        ORDER BY dc.number, dc.name, d.number, d.name
    ");
    $legeStmt->execute([$compId]);
    foreach ($legeStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $r['dc_id'] . '|' . $r['distance_id'];
        if (isset($vastgelegdeKeys[$k])) continue;   // al met (live-)uitslag
        $cats = array_values(array_filter(
            array_map('trim', explode(',', $r['cats_csv'] ?? '')),
            fn($c) => $c !== ''
        ));
        $bronnen[] = [
            'dc_id'         => $r['dc_id'],
            'dc_naam'       => $r['dc_naam'],
            'distance_id'   => $r['distance_id'],
            'distance_naam' => $r['distance_naam'],
            'aantal'        => 0,
            'met_rang'      => 0,
            'cats'          => $cats,
            'is_live'       => false,
            'is_leeg'       => true,
        ];
    }

    echo json_encode(['bronnen' => $bronnen], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
