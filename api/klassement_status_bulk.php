<?php
// ============================================================
//  InlineComp – Bulk klassement-status (lichte versie)
//
//  POST JSON body:
//  {
//    "competition_id": "uuid",
//    "groepen": [
//      { "key": "DKA",     "dc_ids": ["uuid1"],         "split_group": "" },
//      { "key": "DSA+DSJ", "dc_ids": ["uuid2"],         "split_group": "" },
//      { "key": "JA-S1",   "dc_ids": ["uuid3","uuid4"], "split_group": "S1" }
//    ]
//  }
//
//  Respons: { "<key>": { "afstanden": [{id, compleet, vastgelegd}],
//                        "klassement_vastgelegd": bool } }
//
//  Lichte versie van klassement_live.php — bedoeld voor uitslag.js
//  vulUitslagPrintSelect() die N×klassement_live.php deed (één per groep)
//  om uitsluitend de status-velden te bepalen. Bij grote wedstrijden
//  (NK met 48 groepen) trok dat 48 PHP-processen tegelijk → iFastNet
//  entry-process-limit. Deze endpoint doet hetzelfde in 3 bulk-queries.
//
//  Geen klassement-berekening, geen persons-join, geen punten — puur:
//    1) per afstand: bestaat er een uitslag_afstand-rij?
//    2) per groep:  bestaat er een uitslag_klassement-rij?
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
requireAuth($pdo);

// ── Input parsen ────────────────────────────────────────────────────────
$input   = json_decode(file_get_contents('php://input'), true);
$compId  = trim($input['competition_id'] ?? '');
$groepen = $input['groepen'] ?? [];

if ($compId === '' || !is_array($groepen) || empty($groepen)) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en groepen[] vereist']);
    exit;
}

// ── Verzamel alle unieke dc_ids over alle groepen ───────────────────────
$alleDcIds = [];
foreach ($groepen as $g) {
    foreach (($g['dc_ids'] ?? []) as $id) {
        if (is_string($id) && $id !== '') $alleDcIds[$id] = true;
    }
}
$alleDcIds = array_keys($alleDcIds);
if (empty($alleDcIds)) {
    echo json_encode([]);
    exit;
}

// ── Q1: alle distances per DC (1 query voor alle DCs samen) ─────────────
// We pakken alleen wat we nodig hebben voor het Print-Center menu:
// id (om te matchen met uitslag_afstand) en name (voor de optie-label).
$dcPh = implode(',', array_fill(0, count($alleDcIds), '?'));
$dStmt = $pdo->prepare("
    SELECT distance_combination_id, id, name, target_group
    FROM distances
    WHERE distance_combination_id IN ($dcPh)
    ORDER BY distance_combination_id, number, name
");
$dStmt->execute($alleDcIds);
$distPerDc = [];   // dcId => [{id, name, target_group}, ...]
foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $distPerDc[$d['distance_combination_id']][] = [
        'id'           => $d['id'],
        'name'         => $d['name'],
        'target_group' => $d['target_group'] ?? null,
    ];
}

// ── Q2: vastgelegd-status per (dc, dist, split_group) ───────────────────
// 1 GROUP BY query voor alle dc_ids + alle splits in 1 keer. De split_group
// wordt opgeslagen in uitslag_afstand bij vastleggen — dus we kunnen direct
// filteren bij het matchen per groep.
$uStmt = $pdo->prepare("
    SELECT distance_combination_id, distance_id, split_group, COUNT(*) AS n
    FROM uitslag_afstand
    WHERE competition_id             = ?
      AND distance_combination_id IN ($dcPh)
    GROUP BY distance_combination_id, distance_id, split_group
");
$uStmt->execute(array_merge([$compId], $alleDcIds));
$vastgelegdMap = []; // "dcId|distId|split" => true
foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    if ((int)$u['n'] <= 0) continue;
    $k = $u['distance_combination_id']
       . '|' . $u['distance_id']
       . '|' . ($u['split_group'] ?? '');
    $vastgelegdMap[$k] = true;
}

// ── Q3: klassement_vastgelegd per DC ────────────────────────────────────
// uitslag_klassement-rij bestaat → klassement is vastgelegd voor die DC.
// Voor het Print-Center is "bestaan er records" voldoende om de Eindklassement-
// print-optie aan te bieden. klassement_live.php heeft daarbij nog een extra
// check "alle afstanden vastgelegd EN records bestaan" — voor dit lichte
// endpoint laten we die strenge check weg (operator ziet de optie iets
// vroeger, doet niets verkeerd).
$kStmt = $pdo->prepare("
    SELECT distance_combination_id, COUNT(*) AS n
    FROM uitslag_klassement
    WHERE competition_id             = ?
      AND distance_combination_id IN ($dcPh)
    GROUP BY distance_combination_id
");
$kStmt->execute(array_merge([$compId], $alleDcIds));
$klasMap = [];   // dcId => true
foreach ($kStmt->fetchAll(PDO::FETCH_ASSOC) as $k) {
    if ((int)$k['n'] > 0) $klasMap[$k['distance_combination_id']] = true;
}

// ── Bouw per-groep response ─────────────────────────────────────────────
$result = [];
foreach ($groepen as $g) {
    $key        = $g['key'] ?? '';
    $dcIds      = $g['dc_ids'] ?? [];
    $splitGroup = trim($g['split_group'] ?? '');
    if ($key === '' || empty($dcIds)) continue;

    // Distances: de eerste DC bepaalt de structuur (gecombineerde DCs zoals
    // DSA+DSJ delen dezelfde afstanden-set). Bij split filteren op
    // target_group=splitname zodat alleen de splitse afstanden meegaan.
    $alleAfst = $distPerDc[$dcIds[0]] ?? [];
    if ($splitGroup !== '') {
        $alleAfst = array_values(array_filter(
            $alleAfst,
            fn($a) => ($a['target_group'] ?? null) === $splitGroup
        ));
    } else {
        // Non-split: filter splits-specifieke afstanden eruit (target_group != null)
        $alleAfst = array_values(array_filter(
            $alleAfst,
            fn($a) => empty($a['target_group'])
        ));
    }

    $afstStatus = [];
    foreach ($alleAfst as $a) {
        // Een afstand is "vastgelegd" als minstens 1 DC in de groep een
        // uitslag_afstand-rij heeft voor deze (dist, split) combinatie.
        // Voor gecombineerde DCs (DSA+DSJ) telt elke DC apart mee.
        $isVast = false;
        foreach ($dcIds as $dcId) {
            $matchKey = $dcId . '|' . $a['id'] . '|' . $splitGroup;
            if (!empty($vastgelegdMap[$matchKey])) { $isVast = true; break; }
        }
        $afstStatus[] = [
            'id'         => $a['id'],
            'compleet'   => $isVast,
            'vastgelegd' => $isVast,
        ];
    }

    // Klassement: minstens 1 DC in de groep heeft uitslag_klassement-records
    $heeftKlas = false;
    foreach ($dcIds as $dcId) {
        if (!empty($klasMap[$dcId])) { $heeftKlas = true; break; }
    }

    $result[$key] = [
        'afstanden'             => $afstStatus,
        'klassement_vastgelegd' => $heeftKlas,
    ];
}

echo json_encode($result);
