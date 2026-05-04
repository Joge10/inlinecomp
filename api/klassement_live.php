<?php
// ============================================================
//  InlineComp – (Tussen)klassement op basis van vastgelegde uitslagen
//
//  GET ?competition_id=X&dc_ids=A,B[&dc_id=Y]
//
//  Het klassement wordt puur afgeleid van `uitslag_afstand` — de tabel
//  waarin elke "Uitslag bevestigen"-actie de cascading rang + punten +
//  sanctie per rijder per afstand schrijft. Er gebeurt geen live
//  herberekening in deze endpoint: een wijziging werkt pas door in het
//  klassement nadat de operator de bijbehorende afstand opnieuw heeft
//  bevestigd.
//
//  Bewerkbaarheid:
//    - full-final + sanctie: operator kan de punten lokaal aanpassen
//      (verschillende competities hanteren eigen regels voor sancties)
//    - internationaal: sanctie volgt strict het reglement → niet bewerkbaar
//    - niet-sanctie rijders: nooit bewerkbaar, punten = rang
//
//  Respons:
//  {
//    "afstanden":  [{ "id", "name", "compleet", "vastgelegd" }],
//    "klassement": [{ "rang", "person_license", "full_name", ...,
//                     "totaal_punten",
//                     "afstanden": { dist_id: { rang, punten,
//                       sanctie, bewerkbaar, override } } }],
//    "has_results": true
//  }
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
requireAuth($pdo);

$compId      = trim($_GET['competition_id'] ?? '');
$dcIdsRaw    = trim($_GET['dc_ids'] ?? $_GET['dc_id'] ?? '');
$dcIds       = array_values(array_filter(array_map('trim', explode(',', $dcIdsRaw))));
$primaryDcId = $dcIds[0] ?? '';

if (!$compId || !$primaryDcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_id zijn verplicht']);
    exit;
}

try {
    // ── Systeem ───────────────────────────────────────────────────────────────
    $sysStmt = $pdo->prepare("SELECT systeem FROM competition_tijdschema WHERE competition_id = ?");
    $sysStmt->execute([$compId]);
    $systeem = $sysStmt->fetchColumn() ?: 'internationaal-nieuw';

    // ── Afstanden voor deze DC ────────────────────────────────────────────────
    $distStmt = $pdo->prepare("
        SELECT id, name, number, race_type
        FROM distances
        WHERE distance_combination_id = ?
          AND (target_group IS NULL OR target_group = '')
        ORDER BY number
    ");
    $distStmt->execute([$primaryDcId]);
    $distances = $distStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Ranking methods per afstand (alleen internationaal) ──────────────────
    $rankingConfigs = []; // afstand_naam => {heats_ranking, kwart_ranking, ...}
    $tsId = null;
    if ($systeem !== 'full-final') {
        $tsIdStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ?");
        $tsIdStmt->execute([$compId]);
        $tsId = $tsIdStmt->fetchColumn();
        if ($tsId) {
            $rcStmt = $pdo->prepare("
                SELECT afstand_naam,
                       heats_ranking, kwart_ranking, half_ranking, finale_ranking
                FROM tijdschema_afstand_config WHERE tijdschema_id = ?
            ");
            $rcStmt->execute([$tsId]);
            foreach ($rcStmt->fetchAll(PDO::FETCH_ASSOC) as $rc) {
                $rankingConfigs[$rc['afstand_naam']] = $rc;
            }
        }

        // Beschikbare rondes per afstand ophalen (welke ronde_types bestaan er)
        if ($tsId) {
            $rondeStmt = $pdo->prepare("
                SELECT DISTINCT r.ronde_type
                FROM tijdschema_ritten r
                WHERE r.tijdschema_id = ? AND r.dc_id = ?
                  AND (r.distance_id = ? OR r.distance_id IS NULL)
                ORDER BY FIELD(r.ronde_type, 'heats','kwartfinale','halve_finale','finale_a')
            ");
        }

        // Cat-config per afstand: heeft_heats/kwart/half om eerste actieve
        // ronde te bepalen voor de race-type-aware ranking-defaults (sprint).
        $catRondesMap = []; // distance_id => ['heeft_heats', 'heeft_kwartfinale', 'heeft_halve_finale']
        if ($tsId) {
            $crStmt = $pdo->prepare("
                SELECT distance_id, heeft_heats, heeft_kwartfinale, heeft_halve_finale
                FROM tijdschema_cat_config
                WHERE tijdschema_id = ? AND dc_id = ?
            ");
            $crStmt->execute([$tsId, $primaryDcId]);
            foreach ($crStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $catRondesMap[$row['distance_id']] = $row;
            }
        }
    }

    if (empty($distances)) {
        echo json_encode(['systeem' => $systeem, 'afstanden' => [], 'klassement' => [],
                          'has_results' => false]);
        exit;
    }

    // ── Wedstrijd-startnummers ────────────────────────────────────────────────
    $snStmt = $pdo->prepare("SELECT person_license, startnummer FROM competition_startnummers WHERE competition_id = ?");
    $snStmt->execute([$compId]);
    $snMap = [];
    foreach ($snStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snMap[$row['person_license']] = $row['startnummer'];
    }

    // ── Overrides uit uitslag_afstand ─────────────────────────────────────────
    // Haal per rijder/afstand de vastgelegde uitslag op. Deze tabel bevat
    // de cascading rang zoals berekend door uitslag_vastleggen (incl. KF/HF
    // via berekenInternationaalResultaat). We gebruiken hieruit:
    //   - `rang` als de "vastgelegde" rang voor rijders die niet in de live
    //     finale_a/b heats zitten (KF/HF-losers bij internationaal)
    //   - `punten` als override (alleen relevant voor sanctie-rijders, waar
    //     de operator lokaal afwijkt van de default)
    //   - `sanctie` zodat we weten of de vastgelegde rij een sanctie-rijder
    //     betrof
    $dcPh = implode(',', array_fill(0, count($dcIds), '?'));
    $ovStmt = $pdo->prepare("
        SELECT person_license, distance_id, rang, punten, sanctie
        FROM uitslag_afstand
        WHERE competition_id             = ?
          AND distance_combination_id IN ($dcPh)
    ");
    $ovStmt->execute(array_merge([$compId], $dcIds));
    $vastgelegdeRijen = []; // [lic][distId] => { rang, punten, sanctie }
    foreach ($ovStmt->fetchAll(PDO::FETCH_ASSOC) as $ov) {
        $vastgelegdeRijen[$ov['person_license']][$ov['distance_id']] = [
            'rang'    => $ov['rang'] !== null ? (int)$ov['rang'] : null,
            'punten'  => $ov['punten'] !== null ? (float)$ov['punten'] : null,
            'sanctie' => $ov['sanctie'],
        ];
    }

    // ── Vastgelegd-status per afstand ophalen ────────────────────────────────
    $vastStmt = $pdo->prepare("
        SELECT distance_id, COUNT(*) AS n
        FROM uitslag_afstand
        WHERE competition_id             = ?
          AND distance_combination_id IN ($dcPh)
        GROUP BY distance_id
    ");
    $vastStmt->execute(array_merge([$compId], $dcIds));
    $vastgelegdMap = []; // distance_id → true
    foreach ($vastStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $vastgelegdMap[$v['distance_id']] = (int)$v['n'] > 0;
    }

    // ── Persons-info ophalen voor alle rijders met vastgelegde uitslag ──────
    // Klassement werkt puur op basis van vastgelegde uitslagen — we hoeven
    // dus niet meer door heat_entries/results te lopen om rijder-info op
    // te halen. Alleen wie een uitslag_afstand rij heeft, verschijnt in
    // het klassement.
    $personCache = [];
    $alleKlasLics = array_keys($vastgelegdeRijen);
    if ($alleKlasLics) {
        $licPh = implode(',', array_fill(0, count($alleKlasLics), '?'));
        $pStmt = $pdo->prepare("
            SELECT license_key, full_name, short_name, start_number,
                   category AS categorie, club_short, club_full, sponsor
            FROM persons
            WHERE license_key IN ($licPh)
        ");
        $pStmt->execute($alleKlasLics);
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $personCache[$p['license_key']] = [
                'full_name'    => $p['full_name'],
                'short_name'   => $p['short_name'],
                'start_number' => $snMap[$p['license_key']] ?? $p['start_number'],
                'categorie'    => $p['categorie'],
                'club_short'   => $p['club_short'] ?? null,
                'club_full'    => $p['club_full']  ?? null,
                'sponsor'      => $p['sponsor']    ?? null,
            ];
        }
    }

    // ── puntenMap vullen uit vastgelegde uitslagen ──────────────────────────
    $puntenMap     = [];  // person_license → [ dist_id → {...} ]
    $afstandenInfo = [];
    $isFullFinal   = ($systeem === 'full-final');

    foreach ($distances as $dist) {
        $distId       = $dist['id'];
        $isVastgelegd = !empty($vastgelegdMap[$distId]);

        $afInfo = [
            'id'         => $distId,
            'name'       => $dist['name'],
            'compleet'   => $isVastgelegd,
            'vastgelegd' => $isVastgelegd,
        ];
        // Ranking-methods per ronde (alleen internationaal) voor UI-info.
        // race_type (sprint/long_distance) wordt afgeleid uit distances.race_type —
        // canonieke bron, ongeacht systeem.
        if (!$isFullFinal && isset($rankingConfigs[$dist['name']])) {
            $rc = $rankingConfigs[$dist['name']];
            $distRt = $dist['race_type'] ?? 'sprint';
            $afInfo['race_type'] = ($distRt && $distRt !== 'sprint') ? 'long_distance' : 'sprint';

            // Race-type-aware defaults. Opgeslagen voorkeur via ?? heeft voorrang.
            //  Sprint: eerste actieve ronde = time (ongeacht of dat heats/KF/HF is),
            //          tussenrondes = position_time, A-finale = time.
            //  Long distance: voorronden = position_time, A-finale = time (UI verbergt).
            $isSprint = ($afInfo['race_type'] === 'sprint');
            // Eerste actieve ronde detecteren via cat-config (zelfde keten als runner-up)
            $eersteRonde = null;
            $cr = $catRondesMap[$dist['id']] ?? null;
            if ($cr) {
                if (!empty($cr['heeft_heats']))            $eersteRonde = 'heats';
                elseif (!empty($cr['heeft_kwartfinale']))  $eersteRonde = 'kwartfinale';
                elseif (!empty($cr['heeft_halve_finale'])) $eersteRonde = 'halve_finale';
            }
            if ($isSprint) {
                $defH = $eersteRonde === 'heats'        ? 'time' : 'position_time';
                $defK = $eersteRonde === 'kwartfinale'  ? 'time' : 'position_time';
                $defL = $eersteRonde === 'halve_finale' ? 'time' : 'position_time';
            } else {
                $defH = 'position_time';
                $defK = 'position_time';
                $defL = 'position_time';
            }
            $defF = 'time';
            $afInfo['ranking'] = [
                'heats'  => $rc['heats_ranking']  ?? $defH,
                'kwart'  => $rc['kwart_ranking']  ?? $defK,
                'half'   => $rc['half_ranking']   ?? $defL,
                'finale' => $rc['finale_ranking'] ?? $defF,
            ];
            if (isset($rondeStmt)) {
                $rondeStmt->execute([$tsId, $primaryDcId, $distId]);
                $afInfo['rondes'] = array_column($rondeStmt->fetchAll(PDO::FETCH_ASSOC), 'ronde_type');
            }
        }
        $afstandenInfo[] = $afInfo;

        // Voor elke rijder die een vastgelegde rij heeft voor deze afstand,
        // vul de klassement-entry:
        //   punten  = uitslag_afstand.punten (valt terug op rang als leeg)
        //   sanctie = uitslag_afstand.sanctie
        //   bewerkbaar: alleen bij full-final + sanctie (operator kan dan
        //     afwijken van de default per lokale competitie-regel). Bij
        //     internationaal volgt de sanctie het reglement strict, dus
        //     niet bewerkbaar.
        foreach ($vastgelegdeRijen as $lic => $perDist) {
            if (!isset($perDist[$distId])) continue;
            $row       = $perDist[$distId];
            $isSanctie = !empty($row['sanctie']);
            $rang      = $row['rang'];
            $punten    = $row['punten'];
            if ($punten === null && $rang !== null) $punten = (float)$rang;
            if ($punten === null) $punten = 0.0;
            $bewerkbaar = $isFullFinal && $isSanctie;
            $heeftOverride = $isFullFinal
                && $rang !== null
                && (float)$rang !== (float)$punten;

            if (!isset($puntenMap[$lic])) $puntenMap[$lic] = [];
            $puntenMap[$lic][$distId] = [
                'rang'       => $rang,
                'punten'     => $punten,
                'sanctie'    => $row['sanctie'],
                'bewerkbaar' => $bewerkbaar,
                'override'   => $heeftOverride,
            ];
        }
    }

    // ── Alle sancties per rijder over alle rondes + afstanden ───────────────
    $alleLics = array_keys($puntenMap);
    $alleSancties = []; // person_license => [{afstand, ronde, sanctie}]
    if ($alleLics) {
        $licPh = implode(',', array_fill(0, count($alleLics), '?'));
        $sanctieStmt = $pdo->prepare("
            SELECT DISTINCT he.person_license,
                   d.name AS afstand_naam,
                   CASE COALESCE(ts_r.ronde_type, CONCAT('ronde_', h.ronde))
                       WHEN 'heats'        THEN 'Serie'
                       WHEN 'kwartfinale'   THEN 'KF'
                       WHEN 'halve_finale'  THEN 'HF'
                       WHEN 'finale_a'      THEN 'Finale'
                       WHEN 'finale_b'      THEN CONCAT('B', h.heat_nr, '-Finale')
                       ELSE CONCAT('R', h.ronde)
                   END AS ronde_label,
                   res.sanctie
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            LEFT JOIN tijdschema_ritten ts_r ON ts_r.id = h.tijdschema_rit_id
            LEFT JOIN distances d ON d.id = COALESCE(h.distance_id, ts_r.distance_id)
                                 AND d.distance_combination_id = h.distance_combination_id
            JOIN results res ON res.heat_entry_id = he.id
            WHERE he.person_license IN ($licPh)
              AND h.competition_id = ?
              AND h.distance_combination_id IN ($dcPh)
              AND res.sanctie IS NOT NULL
            ORDER BY d.number, h.ronde, h.heat_nr
        ");
        $sanctieStmt->execute(array_merge($alleLics, [$compId], $dcIds));
        foreach ($sanctieStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $alleSancties[$s['person_license']][] = [
                'afstand' => $s['afstand_naam'] ?? '',
                'ronde'   => $s['ronde_label'],
                'sanctie' => $s['sanctie'],
            ];
        }
    }

    // ── Klassement samenstellen ───────────────────────────────────────────────
    // Rijders met een afstand op 0 punten (sanctie/uitgesloten) worden niet
    // opgenomen in het klassement en apart onderaan getoond.
    $klassement  = [];
    $uitgesloten = [];
    foreach ($puntenMap as $lic => $distPunten) {
        $totaal = 0.0;
        $heeftNulAfstand = false;
        foreach ($distPunten as $dp) {
            $totaal += $dp['punten'];
            if ($dp['punten'] == 0) $heeftNulAfstand = true;
        }
        $info = $personCache[$lic] ?? [];
        $entry = [
            'person_license' => $lic,
            'full_name'      => $info['full_name']    ?? '',
            'short_name'     => $info['short_name']   ?? '',
            'start_number'   => $info['start_number'] ?? null,
            'categorie'      => $info['categorie']    ?? '',
            'club_short'     => $info['club_short']   ?? null,
            'club_full'      => $info['club_full']    ?? null,
            'sponsor'        => $info['sponsor']      ?? null,
            'totaal_punten'  => $totaal,
            'alle_sancties'  => $alleSancties[$lic] ?? [],
            'afstanden'      => $distPunten,
        ];
        if (!$heeftNulAfstand) {
            $klassement[] = $entry;
        } else {
            $entry['uitgesloten'] = true;
            $uitgesloten[] = $entry;
        }
    }

    // ── Vergelijkfunctie: totaal → beste resultaat → laatste afstand ──────────
    // Retourneert 0 bij echte ex-aequo, anders negatief/positief.
    $lastDistId = !empty($distances) ? end($distances)['id'] : null;

    $vergelijkKlassement = function (array $a, array $b) use ($lastDistId): int {
        // 1. Totaal punten ASC
        $diff = $a['totaal_punten'] <=> $b['totaal_punten'];
        if ($diff !== 0) return $diff;

        // 2. Gesorteerde individuele punten vergelijken (beste resultaat eerst)
        $pA = array_column($a['afstanden'], 'punten');
        $pB = array_column($b['afstanden'], 'punten');
        sort($pA); sort($pB);
        $len = max(count($pA), count($pB));
        for ($i = 0; $i < $len; $i++) {
            $vA = $pA[$i] ?? PHP_INT_MAX;
            $vB = $pB[$i] ?? PHP_INT_MAX;
            if ($vA != $vB) return $vA <=> $vB;
        }

        // 3. Laatste afstand ASC
        if ($lastDistId !== null) {
            $lA = $a['afstanden'][$lastDistId]['punten'] ?? PHP_INT_MAX;
            $lB = $b['afstanden'][$lastDistId]['punten'] ?? PHP_INT_MAX;
            if ($lA != $lB) return $lA <=> $lB;
        }

        return 0; // Echte ex-aequo
    };

    usort($klassement, function ($a, $b) use ($vergelijkKlassement) {
        $cmp = $vergelijkKlassement($a, $b);
        return $cmp !== 0 ? $cmp : strcmp($a['full_name'], $b['full_name']);
    });

    // Klassement-rang toekennen (ex-aequo pas als vergelijk === 0)
    for ($i = 0; $i < count($klassement); $i++) {
        if ($i === 0) {
            $klassement[$i]['rang'] = 1;
        } else {
            $gelijk = $vergelijkKlassement($klassement[$i], $klassement[$i - 1]) === 0;
            $klassement[$i]['rang'] = $gelijk ? $klassement[$i - 1]['rang'] : ($i + 1);
        }
    }

    // Uitgesloten rijders (0 punten / sanctie) onderaan toevoegen zonder rang
    foreach ($uitgesloten as &$u) { $u['rang'] = null; }
    unset($u);
    $klassement = array_merge($klassement, $uitgesloten);

    $hasResults = !empty(array_filter($afstandenInfo, fn($a) => $a['compleet']));

    // Is het klassement al vastgelegd in uitslag_klassement?
    // Alleen als ALLE afstanden bevestigd zijn EN er klassement-records bestaan
    $alleVastgelegd = !empty($afstandenInfo) && empty(array_filter($afstandenInfo, fn($a) => !$a['vastgelegd']));
    $klasVastStmt = $pdo->prepare("
        SELECT COUNT(*) FROM uitslag_klassement
        WHERE competition_id = ? AND distance_combination_id IN ($dcPh)
    ");
    $klasVastStmt->execute(array_merge([$compId], $dcIds));
    $klassementVastgelegd = $alleVastgelegd && (int)$klasVastStmt->fetchColumn() > 0;

    echo json_encode([
        'systeem'               => $systeem,
        'afstanden'             => $afstandenInfo,
        'klassement'            => $klassement,
        'has_results'           => $hasResults,
        'klassement_vastgelegd' => $klassementVastgelegd,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
