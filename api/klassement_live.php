<?php
// ============================================================
//  InlineComp – Live klassement
//
//  GET ?competition_id=X&dc_ids=A,B[&dc_id=Y]
//
//  Berekent het (tussen)klassement op basis van de huidige
//  finale-resultaten in heats/results.
//  Punten = rang per afstand (1e = 1 pt, 2e = 2 pt, …).
//  Sanctie-rijders krijgen standaard de laatste positie
//  van hun heat als punten (bewerkbaar via POST opslaan).
//
//  Respons:
//  {
//    "afstanden":  [{ "id", "name", "compleet" }],
//    "klassement": [{ "rang", "person_license", "full_name",
//                     "start_number", "categorie",
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
require_once __DIR__ . '/_uitslag_helper.php';
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
        SELECT id, name, number
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
                SELECT afstand_naam, race_type,
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
    $dcPh = implode(',', array_fill(0, count($dcIds), '?'));
    $ovStmt = $pdo->prepare("
        SELECT person_license, distance_id, punten
        FROM uitslag_afstand
        WHERE competition_id             = ?
          AND distance_combination_id IN ($dcPh)
          AND punten IS NOT NULL
    ");
    $ovStmt->execute(array_merge([$compId], $dcIds));
    $overrides = []; // [person_license][distance_id] => punten
    foreach ($ovStmt->fetchAll(PDO::FETCH_ASSOC) as $ov) {
        $overrides[$ov['person_license']][$ov['distance_id']] = (float)$ov['punten'];
    }

    // ── Rijder-info ophalen (alle finale deelnemers) ──────────────────────────
    $rijderStmt = $pdo->prepare("
        SELECT he.person_license,
               p.full_name, p.short_name, p.start_number,
               p.category  AS categorie,
               res.finishpositie, res.tijd_ms, res.sanctie,
               res.rondes, res.punten AS pk_punten
        FROM heat_entries he
        JOIN persons p ON p.license_key = he.person_license
        LEFT JOIN results res ON res.heat_entry_id = he.id
        WHERE he.heat_id = ?
        ORDER BY he.startpositie
    ");

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

    $personCache = [];  // person_license → info
    $puntenMap   = [];  // person_license → [ dist_id → {...} ]
    $afstandenInfo = [];

    foreach ($distances as $dist) {
        $distId = $dist['id'];

        // Heats voor deze afstand (serie + finales, voor gecombineerde detectie)
        $heatStmt = $pdo->prepare("
            SELECT h.id, h.heat_nr,
                   COALESCE(ts_r.ronde_type, 'finale_a') AS ronde_type
            FROM heats h
            LEFT JOIN tijdschema_ritten ts_r ON ts_r.id = h.tijdschema_rit_id
            WHERE h.competition_id          = ?
              AND h.distance_combination_id IN ($dcPh)
              AND (
                  ts_r.ronde_type IN ('heats', 'finale_a', 'finale_b')
                  OR (ts_r.ronde_type IS NULL AND h.ronde = 4)
              )
              AND COALESCE(h.distance_id, ts_r.distance_id) = ?
            ORDER BY
                CASE COALESCE(ts_r.ronde_type, 'finale_a')
                    WHEN 'heats'    THEN 0
                    WHEN 'finale_a' THEN 1
                    WHEN 'finale_b' THEN 2
                    ELSE 3
                END,
                h.heat_nr ASC
        ");
        $heatStmt->execute(array_merge([$compId], $dcIds, [$distId]));
        $heats = $heatStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($heats)) {
            $afstandenInfo[] = ['id' => $distId, 'name' => $dist['name'], 'compleet' => false,
                                 'vastgelegd' => !empty($vastgelegdMap[$distId])];
            continue;
        }

        // ── Helper: rows laden voor één heat ───────────────────────────────────
        $laadRows = function(array $heat) use ($rijderStmt, $snMap, &$personCache): array {
            $rijderStmt->execute([(int)$heat['id']]);
            $rows = $rijderStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['start_number'] = $snMap[$r['person_license']] ?? $r['start_number'];
                $personCache[$r['person_license']] ??= [
                    'full_name'    => $r['full_name'],
                    'short_name'   => $r['short_name'],
                    'start_number' => $r['start_number'],
                    'categorie'    => $r['categorie'],
                ];
            }
            unset($r);
            return $rows;
        };

        $distCompleet = false;

        // ── Gecombineerde modus: 1 serie + alleen A-finale ────────────────────
        if (isCombineerdModus($heats)) {
            $serieHeats  = array_values(array_filter($heats, fn($h) => $h['ronde_type'] === 'heats'));
            $finaleHeats = array_values(array_filter($heats, fn($h) => $h['ronde_type'] !== 'heats'));

            $serieRangs  = []; $finaleRangs  = [];
            $serieTijden = []; $finaleTijden = [];
            $rijderInfo  = []; $sancties     = [];
            $serieCompleet  = true;
            $finaleCompleet = true;

            $serieOffset = 0;
            foreach ($serieHeats as $heat) {
                $rows = $laadRows($heat);
                if (!$rows) { $serieCompleet = false; continue; }
                $rows = sorteerRijdersOpTijd($rows);
                if (!isHeatCompleet($rows)) $serieCompleet = false;
                $nRijders  = count($rows);
                $finishers = array_values(array_filter($rows, fn($r) => $r['finishpositie'] !== null));
                $overigen  = array_values(array_filter($rows, fn($r) => $r['finishpositie'] === null));
                $rangs     = berekenExAequoRangs($finishers, $serieOffset);
                foreach ($finishers as $i => $r) {
                    $lic = $r['person_license'];
                    $serieRangs[$lic]  = $rangs[$i];
                    $serieTijden[$lic] = $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null;
                    $rijderInfo[$lic]  = $personCache[$lic];
                }
                foreach ($overigen as $r) {
                    $lic = $r['person_license'];
                    $serieRangs[$lic]  = $serieOffset + $nRijders;
                    $serieTijden[$lic] = null;
                    $rijderInfo[$lic]  = $personCache[$lic];
                }
                $serieOffset += $nRijders;
            }

            $finaleOffset = 0;
            foreach ($finaleHeats as $heat) {
                $rows = $laadRows($heat);
                if (!$rows) { $finaleCompleet = false; continue; }
                $rows = sorteerRijdersOpTijd($rows);
                if (!isHeatCompleet($rows)) $finaleCompleet = false;
                $nRijders  = count($rows);
                $finishers = array_values(array_filter($rows, fn($r) => $r['finishpositie'] !== null));
                $overigen  = array_values(array_filter($rows, fn($r) => $r['finishpositie'] === null));
                $rangs     = berekenExAequoRangs($finishers, $finaleOffset);
                foreach ($finishers as $i => $r) {
                    $lic = $r['person_license'];
                    $finaleRangs[$lic]  = $rangs[$i];
                    $finaleTijden[$lic] = $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null;
                    $rijderInfo[$lic] ??= $personCache[$lic];
                }
                foreach ($overigen as $r) {
                    $lic = $r['person_license'];
                    $finaleRangs[$lic]  = $finaleOffset + $nRijders;
                    $finaleTijden[$lic] = null;
                    $sancties[$lic]     = $r['sanctie'];
                    $rijderInfo[$lic] ??= $personCache[$lic];
                }
                $finaleOffset += $nRijders;
            }

            $distCompleet = $serieCompleet && $finaleCompleet;
            $gecombineerd = berekenCombineerdResultaat(
                $serieRangs, $finaleRangs, $rijderInfo,
                $serieTijden, $finaleTijden, $sancties
            );

            foreach ($gecombineerd as $gc) {
                $lic      = $gc['person_license'];
                $override = $overrides[$lic][$distId] ?? null;
                $isSanctie = $gc['sanctie'] !== null;
                if (!isset($puntenMap[$lic])) $puntenMap[$lic] = [];
                $puntenMap[$lic][$distId] = [
                    'rang'       => $gc['rang'],
                    'punten'     => $override !== null ? $override : (float)$gc['rang'],
                    'sanctie'    => $gc['sanctie'],
                    'bewerkbaar' => $isSanctie,
                    'override'   => $override !== null,
                    'modus'      => 'gecombineerd',
                    'serie_rang' => $gc['serie_rang'],
                    'finale_rang'=> $gc['finale_rang'],
                ];
            }

            $afstandenInfo[] = ['id' => $distId, 'name' => $dist['name'], 'compleet' => $distCompleet,
                                 'modus' => 'gecombineerd', 'vastgelegd' => !empty($vastgelegdMap[$distId])];
            continue;
        }

        // ── Normaal: finales ──────────────────────────────────────────────────
        $finaleHeatsOnly = array_values(array_filter($heats, fn($h) => $h['ronde_type'] !== 'heats'));
        $rangOffset = 0;

        foreach ($finaleHeatsOnly as $heat) {
            $rows = $laadRows($heat);
            $nRijders = count($rows);
            if (!$nRijders) continue;

            if (isHeatCompleet($rows)) $distCompleet = true;

            $rows = sorteerRijdersOpTijd($rows);

            $finishers = array_values(array_filter($rows, fn($r) => $r['finishpositie'] !== null));
            $overigen  = array_values(array_filter($rows, fn($r) => $r['finishpositie'] === null));

            $rangs = berekenExAequoRangs($finishers, $rangOffset);

            // Finishers: punten = rang
            foreach ($finishers as $i => $r) {
                $lic = $r['person_license'];
                if (!isset($puntenMap[$lic])) $puntenMap[$lic] = [];
                $override = $overrides[$lic][$distId] ?? null;
                $puntenMap[$lic][$distId] = [
                    'rang'       => $rangs[$i],
                    'punten'     => $override !== null ? $override : (float)$rangs[$i],
                    'sanctie'    => null,
                    'bewerkbaar' => false,
                    'override'   => $override !== null,
                ];
            }

            // Sanctie-rijders: default punten = laatste positie van deze heat
            $defaultPunten = (float)($rangOffset + $nRijders);
            foreach ($overigen as $r) {
                $s = $r['sanctie'] ?? null;
                if (!$s) continue; // nog niet ingevuld
                $lic = $r['person_license'];
                if (!isset($puntenMap[$lic])) $puntenMap[$lic] = [];
                $override = $overrides[$lic][$distId] ?? null;
                $puntenMap[$lic][$distId] = [
                    'rang'       => null,
                    'punten'     => $override !== null ? $override : $defaultPunten,
                    'sanctie'    => $s,
                    'bewerkbaar' => true,
                    'override'   => $override !== null,
                ];
            }

            // Offset: totale heat-grootte (ook DNS/DNF bezetten een slot)
            $rangOffset += $nRijders;
        }

        $afInfo = ['id' => $distId, 'name' => $dist['name'], 'compleet' => $distCompleet,
                   'vastgelegd' => !empty($vastgelegdMap[$distId])];

        // Ranking methods per ronde (alleen internationaal)
        if ($systeem !== 'full-final' && isset($rankingConfigs[$dist['name']])) {
            $rc = $rankingConfigs[$dist['name']];
            $afInfo['race_type'] = $rc['race_type'] ?? 'sprint';
            $afInfo['ranking'] = [
                'heats'   => $rc['heats_ranking']  ?? 'time',
                'kwart'   => $rc['kwart_ranking']   ?? 'time',
                'half'    => $rc['half_ranking']    ?? 'time',
                'finale'  => $rc['finale_ranking']  ?? 'time',
            ];
            // Welke rondes bestaan er voor deze afstand?
            if (isset($rondeStmt)) {
                $rondeStmt->execute([$tsId, $primaryDcId, $distId]);
                $afInfo['rondes'] = array_column($rondeStmt->fetchAll(PDO::FETCH_ASSOC), 'ronde_type');
            }
        }

        $afstandenInfo[] = $afInfo;
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
