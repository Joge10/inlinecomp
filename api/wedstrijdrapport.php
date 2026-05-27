<?php
// ============================================================
//  InlineComp – wedstrijdrapport (printbare uitslag per wedstrijd)
//
//  GET /api/wedstrijdrapport.php?id=<competition_id>
//
//  Response:
//    {
//      "competition": {
//        id, name, starts, ends, location, venue_city, discipline,
//        organisatie_naam, organisatie_logo
//      },
//      "dcs": [
//        {
//          id, name, number, category_filter,
//          klassement: [                  // alleen bij multi-distance DCs
//            { rang, license_key, full_name, categorie, club_full,
//              sponsor, punten_totaal }
//          ],
//          distances: [
//            {
//              id, name, value_meters, race_type, starts,
//              uitslag: [
//                { rang, license_key, full_name, categorie, split_group,
//                  club_full, sponsor, tijd_ms, punten, sanctie }
//              ]
//            }
//          ]
//        }
//      ]
//    }
//
//  Gebruikt door js/instellingen.js → printWedstrijdrapport() —
//  vanuit Beheer → Organisaties → tab Wedstrijden (🖨 knop per rij).
//
//  Belangrijk over multi-distance DCs (bv. KNSB-feed wedstrijden waar
//  één DC zowel een 1000m DTT als 5000m afvalkoers bevat):
//    - `distances` bevat per afstand een eigen `uitslag`-array
//      (gesorteerd op rang binnen die afstand), dus rijders verschijnen
//      één keer per afstand — niet duplicaat in één tabel.
//    - `klassement` is het totaal-eindklassement over alle afstanden
//      (uit uitslag_klassement met punten_totaal). Alleen gevuld als
//      de DC meerdere distances heeft, anders identiek aan de uitslag
//      van de enige afstand en dus overbodig.
//    - Voor split-group DCs (HSA+HJA in 1 DC) bevat elke uitslag-rij
//      ook `split_group`, zodat de frontend per split kan groeperen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$compId = trim($_GET['id'] ?? '');
if ($compId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'id ontbreekt']);
    exit;
}

try {
    // ── 1) Wedstrijd-header (+ organisatie-naam en logo) ────────
    $compStmt = $pdo->prepare("
        SELECT c.id, c.name, c.starts, c.ends, c.location,
               c.venue_name, c.venue_city, c.discipline,
               c.organisatie_id,
               o.naam      AS organisatie_naam,
               o.logo_path AS organisatie_logo
        FROM competitions c
        LEFT JOIN organisaties o ON o.id = c.organisatie_id
        WHERE c.id = ?
    ");
    $compStmt->execute([$compId]);
    $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp) {
        http_response_code(404);
        echo json_encode(['error' => 'Wedstrijd niet gevonden']);
        exit;
    }

    // ── 2) Distance combinations ────────────────────────────────
    $dcStmt = $pdo->prepare("
        SELECT id, name, number, category_filter
        FROM distance_combinations
        WHERE competition_id = ?
        ORDER BY number, name
    ");
    $dcStmt->execute([$compId]);
    $dcs = $dcStmt->fetchAll(PDO::FETCH_ASSOC);

    // Statements één keer prepareren — worden hieronder per DC hergebruikt
    // (PDO cached deze server-side; N+1 queries zijn voor een eenmalige
    // print-actie alle geen probleem en houden de PHP-kant leesbaar)
    $distStmt = $pdo->prepare("
        SELECT id, name, value_meters, race_type, starts, target_group
        FROM distances
        WHERE distance_combination_id = ?
        ORDER BY target_group, number, name
    ");
    $uitslagStmt = $pdo->prepare("
        SELECT ua.rang, ua.categorie, ua.split_group,
               ua.person_license   AS license_key,
               p.full_name,
               p.club_full,
               p.sponsor,
               ua.tijd_ms, ua.punten, ua.sanctie
        FROM uitslag_afstand ua
        LEFT JOIN persons p ON p.license_key = ua.person_license
        WHERE ua.distance_combination_id = ?
          AND ua.distance_id             = ?
        ORDER BY ua.split_group,
                 -- NULL-rang (DQ/DNF/DNS) onderaan ipv MySQL-default bovenaan
                 ua.rang IS NULL,
                 ua.rang,
                 p.full_name
    ");
    $klassementStmt = $pdo->prepare("
        SELECT uk.rang, uk.categorie, uk.split_group,
               uk.person_license   AS license_key,
               p.full_name,
               p.club_full,
               p.sponsor,
               uk.punten_totaal
        FROM uitslag_klassement uk
        LEFT JOIN persons p ON p.license_key = uk.person_license
        WHERE uk.distance_combination_id = ?
        ORDER BY uk.split_group,
                 -- NULL-rang (DQ/DNF/DNS) onderaan ipv MySQL-default bovenaan
                 uk.rang IS NULL,
                 uk.rang,
                 p.full_name
    ");

    foreach ($dcs as &$dc) {
        // 2a) Distances voor deze DC ophalen
        $distStmt->execute([$dc['id']]);
        $distances = $distStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2b) Per distance: uitslag_afstand-rijen ophalen
        foreach ($distances as &$dist) {
            $uitslagStmt->execute([$dc['id'], $dist['id']]);
            $rows = $uitslagStmt->fetchAll(PDO::FETCH_ASSOC);
            $dist['uitslag'] = array_map(function($r) {
                return [
                    // BELANGRIJK: NULL behouden, niet casten naar (int) want
                    // dat geeft 0 — wat de UI vervolgens als positie '0'
                    // bovenaan rendert ipv onderaan met '—'.
                    'rang'        => $r['rang'] !== null ? (int)$r['rang'] : null,
                    'license_key' => $r['license_key'],
                    'full_name'   => $r['full_name'] ?? '(onbekend)',
                    'categorie'   => $r['categorie'],
                    'split_group' => $r['split_group'] ?? '',
                    'club_full'   => $r['club_full'],
                    'sponsor'     => $r['sponsor'],
                    'tijd_ms'     => $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                    'punten'      => $r['punten']  !== null ? (float)$r['punten']  : null,
                    'sanctie'     => $r['sanctie'],
                ];
            }, $rows);
            $dist['value_meters'] = $dist['value_meters'] !== null
                ? (int)$dist['value_meters'] : null;
            // target_group = split-label (bv. 'DP2', 'HP2') voor gesplitste
            // DCs. Bij niet-gesplitste DCs is dit NULL of leeg. Frontend
            // gebruikt 'm om de blok-titel te disambigueren — anders krijg
            // je 2x dezelfde sectie-naam onder elkaar voor split-DCs.
            $dist['target_group'] = $dist['target_group'] ?: null;
        }
        $dc['distances'] = $distances;

        // 2c) Eindklassement — alleen zinvol als DC meerdere distances
        // heeft. Bij single-distance DCs is uitslag_klassement.rang
        // identiek aan uitslag_afstand.rang en dus visueel dubbel.
        if (count($distances) > 1) {
            $klassementStmt->execute([$dc['id']]);
            $krows = $klassementStmt->fetchAll(PDO::FETCH_ASSOC);
            $dc['klassement'] = array_map(function($r) {
                return [
                    // NULL behouden — zie comment bij distance.uitslag.rang.
                    'rang'          => $r['rang'] !== null ? (int)$r['rang'] : null,
                    'license_key'   => $r['license_key'],
                    'full_name'     => $r['full_name'] ?? '(onbekend)',
                    'categorie'     => $r['categorie'],
                    'split_group'   => $r['split_group'] ?? '',
                    'club_full'     => $r['club_full'],
                    'sponsor'       => $r['sponsor'],
                    'punten_totaal' => $r['punten_totaal'] !== null
                        ? (float)$r['punten_totaal'] : null,
                ];
            }, $krows);
        } else {
            $dc['klassement'] = [];
        }

        $dc['number'] = (int)$dc['number'];
    }
    unset($dc);

    echo json_encode([
        'competition' => [
            'id'                => $comp['id'],
            'name'              => $comp['name'],
            'starts'            => $comp['starts'],
            'ends'              => $comp['ends'],
            'location'          => $comp['location'],
            'venue_name'        => $comp['venue_name'],
            'venue_city'        => $comp['venue_city'],
            'discipline'        => $comp['discipline'],
            'organisatie_naam'  => $comp['organisatie_naam'],
            'organisatie_logo'  => $comp['organisatie_logo'],
        ],
        'dcs' => $dcs,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
