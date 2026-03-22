<?php
// ============================================================
//  InlineComp – genereer startlijst / heats
//
//  GET /api/startlijst_genereer.php
//  Parameters:
//    competition_id  – UUID van de wedstrijd
//    dc_id           – UUID van de afstandscombinatie (categorie)
//    max_per_heat    – max aantal rijders per heat  (default 6)
//    methode         – willekeurig | startnummer
//                      (klassement / vorige_distance: toekomstig)
//
//  Verdeling: zo gelijkmatig mogelijk, grotere heats eerst.
//  Volgorde:  slangenpatroon (snake).
//
//  Riders zonder bevestigde inschrijving (status != 1) worden
//  overgeslagen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';

$compId     = trim($_GET['competition_id'] ?? '');
$distId     = trim($_GET['distance_id']    ?? '');  // voor labeling; optioneel bij no-distance DCs
$maxPerHeat = max(2, intval($_GET['max_per_heat'] ?? 6));
$methode    = trim($_GET['methode']        ?? 'willekeurig');

// dc_ids: kommagescheiden lijst (ondersteunt ook samengevoegde categorieën)
$dcIdsRaw = trim($_GET['dc_ids'] ?? $_GET['dc_id'] ?? '');
$dcIds    = array_values(array_filter(array_map('trim', explode(',', $dcIdsRaw))));

// category_filter: optioneel, voor gesplitste DCs (bijv. "DKA,DKB")
$catFilterRaw = trim($_GET['category_filter'] ?? '');
$catFilter    = $catFilterRaw
    ? array_values(array_filter(array_map('trim', explode(',', $catFilterRaw))))
    : [];

if (!$compId || !$dcIds) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_ids zijn verplicht']);
    exit;
}

try {
    // --------------------------------------------------------
    // 1. Bevestigde deelnemers voor deze categorie(ën) ophalen
    //    Ondersteunt meerdere dc_ids voor samengevoegde categorieën
    // --------------------------------------------------------
    $ph     = implode(',', array_fill(0, count($dcIds), '?'));
    $params = $dcIds;

    // Optioneel filteren op categorieën (voor gesplitste DCs)
    $catWhere = '';
    if ($catFilter) {
        $catPh    = implode(',', array_fill(0, count($catFilter), '?'));
        $catWhere = "AND p.category IN ($catPh)";
        $params   = array_merge($params, $catFilter);
    }

    $stmt = $pdo->prepare("
        SELECT p.license_key, p.full_name, p.short_name,
               p.start_number, p.club_short, p.club_full, p.city, p.category
        FROM entries e
        JOIN persons p ON e.person_license = p.license_key
        WHERE e.distance_combination_id IN ($ph)
          AND e.status = 1
          $catWhere
    ");
    $stmt->execute($params);
    $rijders = $stmt->fetchAll();

    if (empty($rijders)) {
        echo json_encode(['error' => 'Geen bevestigde deelnemers gevonden voor deze categorie']);
        exit;
    }

    // --------------------------------------------------------
    // 2. Transponders ophalen en koppelen
    // --------------------------------------------------------
    $licenseKeys = array_column($rijders, 'license_key');
    $ph          = implode(',', array_fill(0, count($licenseKeys), '?'));
    $params      = array_merge([$compId], $licenseKeys);

    $stmt = $pdo->prepare("
        SELECT person_license, slot, code
        FROM transponders
        WHERE competition_id = ? AND person_license IN ($ph)
        ORDER BY slot
    ");
    $stmt->execute($params);

    $tpMap = [];
    foreach ($stmt->fetchAll() as $tp) {
        $tpMap[$tp['person_license']][$tp['slot']] = $tp['code'];
    }

    foreach ($rijders as &$r) {
        $lk = $r['license_key'];
        $r['transponder_actief'] = $tpMap[$lk][0] ?? null;   // slot 0: voorbereider-keuze
        $r['transponder1']       = $tpMap[$lk][1] ?? null;
        $r['transponder2']       = $tpMap[$lk][2] ?? null;
        $r['transponders_extra'] = [];
        foreach ($tpMap[$lk] ?? [] as $slot => $code) {
            if ($slot >= 3) $r['transponders_extra'][] = $code;
        }
    }
    unset($r);

    // --------------------------------------------------------
    // 3. Sorteren op basis van methode
    //    Rijders zonder positie in sortering → einde, alfabetisch
    //    op achternaam (short_name of laatste woord van full_name)
    // --------------------------------------------------------
    $heeftPositie = [];
    $zonderPositie = [];

    switch ($methode) {
        case 'startnummer':
            foreach ($rijders as $r) {
                if ($r['start_number']) $heeftPositie[] = $r;
                else                   $zonderPositie[] = $r;
            }
            usort($heeftPositie, fn($a,$b) => $a['start_number'] - $b['start_number']);
            break;

        case 'willekeurig':
        default:
            $methode     = 'willekeurig';
            $heeftPositie = $rijders;
            shuffle($heeftPositie);
            break;
    }

    // Rijders zonder positie: alfabetisch op achternaam
    usort($zonderPositie, fn($a,$b) =>
        strcasecmp(
            $a['short_name'] ?? (preg_match('/\S+$/', $a['full_name'], $m) ? $m[0] : $a['full_name']),
            $b['short_name'] ?? (preg_match('/\S+$/', $b['full_name'], $m) ? $m[0] : $b['full_name'])
        )
    );

    $gesorteerd = array_merge($heeftPositie, $zonderPositie);
    $n          = count($gesorteerd);

    // --------------------------------------------------------
    // 4. Heatverdeling: zo gelijkmatig mogelijk
    //    Grotere heats komen eerst (heat 1, 2, ...)
    // --------------------------------------------------------
    $aantalHeats = (int) ceil($n / $maxPerHeat);
    $basis       = (int) floor($n / $aantalHeats);
    $extras      = $n % $aantalHeats;

    $heats = [];
    for ($i = 0; $i < $aantalHeats; $i++) {
        $heats[] = [
            'nummer'     => $i + 1,
            'capaciteit' => $i < $extras ? $basis + 1 : $basis,
            'rijders'    => [],
        ];
    }

    // --------------------------------------------------------
    // 5. Slangenpatroon (snake)
    //    Vooruit H1→Hn, achteruit Hn→H1, herhalen
    //    Volle heats worden overgeslagen
    // --------------------------------------------------------
    $ri = 0;
    while ($ri < $n) {
        // Vooruit
        for ($h = 0; $h < $aantalHeats && $ri < $n; $h++) {
            if (count($heats[$h]['rijders']) < $heats[$h]['capaciteit']) {
                $heats[$h]['rijders'][] = $gesorteerd[$ri++];
            }
        }
        if ($ri >= $n) break;
        // Achteruit
        for ($h = $aantalHeats - 1; $h >= 0 && $ri < $n; $h--) {
            if (count($heats[$h]['rijders']) < $heats[$h]['capaciteit']) {
                $heats[$h]['rijders'][] = $gesorteerd[$ri++];
            }
        }
    }

    // capaciteit is intern; stuur het mee voor info maar rijders is leidend
    echo json_encode([
        'methode'       => $methode,
        'aantalHeats'   => $aantalHeats,
        'totaalRijders' => $n,
        'heats'         => $heats,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
