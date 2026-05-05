<?php
// ============================================================
//  InlineComp – Uitslag vastleggen
//
//  Schrijft de officiële uitslag naar uitslag_afstand en
//  herberekent uitslag_klassement voor de volledige DC.
//
//  POST JSON body:
//  {
//    "competition_id": "...",
//    "dc_ids":         ["...", "..."],   // all DCs in a merge
//    "dc_naam":        "...",
//    "distance_id":    "..."             // optioneel: alleen deze afstand
//  }
//
//  Als distance_id ontbreekt worden alle afstanden vastgelegd.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/_uitslag_helper.php';
requireAuth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Alleen POST toegestaan']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$compId      = trim($body['competition_id'] ?? '');
$dcIdsRaw    = $body['dc_ids'] ?? [];
$dcIds       = is_array($dcIdsRaw)
    ? array_values(array_filter(array_map('trim', $dcIdsRaw)))
    : array_values(array_filter(array_map('trim', explode(',', (string)$dcIdsRaw))));
$primaryDcId = $dcIds[0] ?? '';
$dcNaam      = trim($body['dc_naam'] ?? '');
$filterDistId = trim($body['distance_id'] ?? '');

if (!$compId || !$primaryDcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_ids zijn verplicht']);
    exit;
}

try {
    // ── Wedstrijd-info ────────────────────────────────────────────────────────
    $compStmt = $pdo->prepare("SELECT name, starts FROM competitions WHERE id = ?");
    $compStmt->execute([$compId]);
    $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp) { http_response_code(404); echo json_encode(['error' => 'Wedstrijd niet gevonden']); exit; }
    $compNaam  = $comp['name'];
    $compDatum = $comp['starts'] ? date('Y-m-d', strtotime($comp['starts'])) : null;

    // ── Systeem ───────────────────────────────────────────────────────────────
    $sysStmt = $pdo->prepare("SELECT systeem FROM competition_tijdschema WHERE competition_id = ?");
    $sysStmt->execute([$compId]);
    $systeem = $sysStmt->fetchColumn() ?: 'internationaal-nieuw';

    // ── Afstanden ─────────────────────────────────────────────────────────────
    $distWhere = $filterDistId ? 'AND id = ?' : '';
    $distStmt  = $pdo->prepare("
        SELECT id, name, value_meters
        FROM distances
        WHERE distance_combination_id = ?
          AND (target_group IS NULL OR target_group = '')
          $distWhere
        ORDER BY number
    ");
    $distParams = $filterDistId ? [$primaryDcId, $filterDistId] : [$primaryDcId];
    $distStmt->execute($distParams);
    $distances = $distStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($distances)) {
        echo json_encode(['ok' => false, 'melding' => 'Geen afstanden gevonden.']);
        exit;
    }

    // ── Startnummers ──────────────────────────────────────────────────────────
    $snStmt = $pdo->prepare("SELECT person_license, startnummer FROM competition_startnummers WHERE competition_id = ?");
    $snStmt->execute([$compId]);
    $snMap = [];
    foreach ($snStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snMap[$row['person_license']] = $row['startnummer'];
    }

    // ── dc_ids placeholder ────────────────────────────────────────────────────
    $dcPh = implode(',', array_fill(0, count($dcIds), '?'));

    // ── Bestaande puntencorrecties ophalen (handmatig aangepast via klassement) ──
    // Alleen rijen met een sanctie: dat zijn de enige records die via het klassement
    // bewerkbaar zijn (full-final + sanctie). Zonder deze filter zou een oude
    // finisher-score (bv. rang 7) blijven staan nadat de rijder naar DNF is
    // gecorrigeerd, omdat we dan "per ongeluk" de vorige auto-berekende punten
    // als override behandelden.
    $overrideStmt = $pdo->prepare("
        SELECT person_license, distance_id, punten
        FROM uitslag_afstand
        WHERE competition_id             = ?
          AND distance_combination_id IN ($dcPh)
          AND punten IS NOT NULL
          AND sanctie IS NOT NULL
    ");
    $overrideStmt->execute(array_merge([$compId], $dcIds));
    $bestaandeOverrides = []; // [person_license][distance_id] => punten
    foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $ov) {
        $bestaandeOverrides[$ov['person_license']][$ov['distance_id']] = (float)$ov['punten'];
    }

    // ── Rijder-query ──────────────────────────────────────────────────────────
    $rijderStmt = $pdo->prepare("
        SELECT he.person_license,
               p.full_name, p.short_name, p.start_number,
               p.category AS categorie,
               res.finishpositie, res.tijd_ms, res.sanctie,
               res.rondes, res.punten AS pk_punten, res.afval_rang
        FROM heat_entries he
        JOIN persons p ON p.license_key = he.person_license
        LEFT JOIN results res ON res.heat_entry_id = he.id
        WHERE he.heat_id = ?
        ORDER BY he.startpositie
    ");

    // ── UPSERT statements ─────────────────────────────────────────────────────
    $upsertAfstand = $pdo->prepare("
        INSERT INTO uitslag_afstand
            (competition_id, competition_naam, competition_datum,
             distance_combination_id, dc_naam, split_group,
             distance_id, distance_naam, distance_meters,
             person_license, categorie,
             rang, finale_positie, finale_naam,
             tijd_ms, punten, sanctie)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            rang            = VALUES(rang),
            finale_positie  = VALUES(finale_positie),
            finale_naam     = VALUES(finale_naam),
            tijd_ms         = VALUES(tijd_ms),
            punten          = VALUES(punten),
            sanctie         = VALUES(sanctie),
            competition_naam= VALUES(competition_naam),
            dc_naam         = VALUES(dc_naam),
            distance_naam   = VALUES(distance_naam),
            vastgelegd_at   = CURRENT_TIMESTAMP
    ");

    $personCache = [];
    $puntenMap   = []; // person_license => [dist_id => punten]
    $vastgelegdAfstanden = 0;

    $pdo->beginTransaction();

    // race_subtype lookup voor ranking-logica (sprint/inline/puntenkoers/afvalkoers).
    $raceSubTypeStmt = $pdo->prepare("
        SELECT race_type FROM distances
        WHERE distance_combination_id = ? AND id = ? LIMIT 1
    ");

    foreach ($distances as $dist) {
        $distId     = $dist['id'];
        $distNaam   = $dist['name'];
        $distMeters = $dist['value_meters'] ?? null;
        $raceSubTypeStmt->execute([$primaryDcId, $distId]);
        $raceSubType = $raceSubTypeStmt->fetchColumn() ?: 'sprint';

        // Heats ophalen: alle ronde-types voor internationaal, serie+finales voor full-final
        if ($systeem !== 'full-final') {
            $heatStmt = $pdo->prepare("
                SELECT h.id, h.heat_nr, h.heat_naam, h.ronde,
                       COALESCE(ts_r.ronde_type, 'finale_a') AS ronde_type
                FROM heats h
                LEFT JOIN tijdschema_ritten ts_r ON ts_r.id = h.tijdschema_rit_id
                WHERE h.competition_id = ?
                  AND h.distance_combination_id IN ($dcPh)
                  AND COALESCE(h.distance_id, ts_r.distance_id) = ?
                ORDER BY h.ronde ASC, h.heat_nr ASC
            ");
        } else {
            $heatStmt = $pdo->prepare("
                SELECT h.id, h.heat_nr, h.heat_naam,
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
        }
        $heatStmt->execute(array_merge([$compId], $dcIds, [$distId]));
        $heats = $heatStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($heats)) continue;

        // Helper: rows laden voor één heat
        $laadRows = function(array $heat) use ($rijderStmt, $snMap, &$personCache): array {
            $rijderStmt->execute([(int)$heat['id']]);
            $rows = $rijderStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['start_number'] = $snMap[$r['person_license']] ?? $r['start_number'];
                $personCache[$r['person_license']] ??= [
                    'full_name'  => $r['full_name'],
                    'categorie'  => $r['categorie'],
                ];
            }
            unset($r);
            return $rows;
        };

        // ── Internationaal systeem: cascading elimination ranking ─────────────
        if ($systeem !== 'full-final') {
            // Ranking methods ophalen
            $rankingMethods = ['heats' => 'time', 'kwartfinale' => 'time',
                               'halve_finale' => 'time', 'finale_a' => 'time'];
            $tsIdStmt2 = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ?");
            $tsIdStmt2->execute([$compId]);
            $tsId2 = $tsIdStmt2->fetchColumn();
            if ($tsId2) {
                $acStmt2 = $pdo->prepare("
                    SELECT heats_ranking, kwart_ranking, half_ranking, finale_ranking
                    FROM tijdschema_afstand_config WHERE tijdschema_id = ? AND afstand_naam = ?
                ");
                $acStmt2->execute([$tsId2, $distNaam]);
                $ac2 = $acStmt2->fetch(PDO::FETCH_ASSOC);
                if ($ac2) {
                    $rankingMethods['heats']        = $ac2['heats_ranking']  ?? 'time';
                    $rankingMethods['kwartfinale']   = $ac2['kwart_ranking'] ?? 'time';
                    $rankingMethods['halve_finale']  = $ac2['half_ranking']  ?? 'time';
                    $rankingMethods['finale_a']      = $ac2['finale_ranking'] ?? 'time';
                }
            }

            // Groepeer heats per ronde_type
            $rondeLabels = ['heats' => 'Serie', 'kwartfinale' => 'Kwartfinale',
                            'halve_finale' => 'Halve finale', 'finale_a' => 'A-Finale',
                            'runner_up' => 'Runner-up'];
            $rondeGroepen = [];
            foreach ($heats as $heat) {
                $rt = $heat['ronde_type'];
                if (!isset($rondeGroepen[$rt])) {
                    $rondeGroepen[$rt] = [
                        'ronde_type' => $rt,
                        'label'      => $rondeLabels[$rt] ?? $rt,
                        'ranking'    => $rankingMethods[$rt] ?? 'time',
                        'rows'       => [],
                    ];
                }
                $rows = $laadRows($heat);
                foreach ($rows as $r) {
                    $rondeGroepen[$rt]['rows'][] = $r;
                }
            }

            // Sorteer: finale eerst → series laatst
            $rondeVolgorde = ['finale_a' => 0, 'runner_up' => 1, 'halve_finale' => 2,
                              'kwartfinale' => 3, 'heats' => 4];
            $rondeDataArr = array_values($rondeGroepen);
            usort($rondeDataArr, fn($a, $b) =>
                ($rondeVolgorde[$a['ronde_type']] ?? 5) <=> ($rondeVolgorde[$b['ronde_type']] ?? 5)
            );

            $resultaat = berekenInternationaalResultaat($rondeDataArr, $raceSubType);

            $geldigeSancties = ['W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF'];
            foreach ($resultaat as $r) {
                $lic     = $r['person_license'];
                $punten  = $r['rang'] !== null ? (float)$r['rang'] : 0;
                $sanctie = $r['sanctie'] ?? null;
                $sanctieDb = ($sanctie && in_array($sanctie, $geldigeSancties, true))
                    ? $sanctie : null;

                // Bestaande handmatige override alleen respecteren bij
                // full-final én als deze rijder nog steeds een sanctie heeft.
                // Internationaal volgt strikt het reglement (geen overrides).
                // Full-final zonder sanctie: oude override wordt vervangen
                // door de nieuwe rang.
                if ($systeem === 'full-final'
                    && $sanctieDb !== null
                    && isset($bestaandeOverrides[$lic][$distId])) {
                    $punten = $bestaandeOverrides[$lic][$distId];
                }

                $upsertAfstand->execute([
                    $compId, $compNaam, $compDatum,
                    $primaryDcId, $dcNaam, '',
                    $distId, $distNaam, $distMeters,
                    $lic, $personCache[$lic]['categorie'] ?? $r['categorie'] ?? null,
                    $r['rang'], null, $r['ronde_label'] ?? 'Finale',
                    $r['tijd_ms'], $punten, $sanctieDb,
                ]);
                $puntenMap[$lic][$distId] = $punten;
            }

            $vastgelegdAfstanden++;
            continue; // volgende afstand
        }

        // ── Gecombineerde modus: 1 serie + alleen A-finale ────────────────────
        if (isCombineerdModus($heats)) {
            $serieHeats  = array_values(array_filter($heats, fn($h) => $h['ronde_type'] === 'heats'));
            $finaleHeats = array_values(array_filter($heats, fn($h) => $h['ronde_type'] !== 'heats'));

            $serieRangs  = []; $finaleRangs  = [];
            $serieTijden = []; $finaleTijden = [];
            $rijderInfo  = []; $sancties     = [];
            $serieOverigenLics  = [];
            $finaleOverigenLics = [];

            $serieOffset = 0;
            foreach ($serieHeats as $heat) {
                $rows = $laadRows($heat);
                if (!$rows) continue;
                $rows = sorteerRijdersOpTijd($rows);
                $nRijders  = count($rows);
                [$finishers, $overigen] = splitsFinishersOverigen($rows);
                $rangs     = berekenExAequoRangs($finishers, $serieOffset);
                foreach ($finishers as $i => $r) {
                    $lic = $r['person_license'];
                    $serieRangs[$lic]  = $rangs[$i];
                    $serieTijden[$lic] = $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null;
                    $rijderInfo[$lic]  = ['full_name' => $r['full_name'], 'short_name' => $r['short_name'],
                                          'start_number' => $r['start_number'], 'categorie' => $r['categorie']];
                }
                foreach ($overigen as $r) {
                    $lic = $r['person_license'];
                    $serieRangs[$lic]  = $serieOffset + $nRijders;
                    $serieTijden[$lic] = null;
                    $serieOverigenLics[$lic] = true;
                    if (!empty($r['sanctie']) && !isset($sancties[$lic])) {
                        $sancties[$lic] = $r['sanctie'];
                    }
                    $rijderInfo[$lic]  = ['full_name' => $r['full_name'], 'short_name' => $r['short_name'],
                                          'start_number' => $r['start_number'], 'categorie' => $r['categorie']];
                }
                $serieOffset += $nRijders;
            }
            // Post-correctie: overigen = laatste van totale serie-groep
            foreach (array_keys($serieOverigenLics) as $lic) {
                $serieRangs[$lic] = $serieOffset;
            }

            $serieNaam    = count($serieHeats) ? ($serieHeats[0]['heat_naam'] ?: 'Serie') : 'Serie';
            $finaleOffset = 0;
            foreach ($finaleHeats as $heat) {
                $rows = $laadRows($heat);
                if (!$rows) continue;
                $rows = sorteerRijdersOpTijd($rows);
                $nRijders  = count($rows);
                [$finishers, $overigen] = splitsFinishersOverigen($rows);
                $rangs     = berekenExAequoRangs($finishers, $finaleOffset);
                foreach ($finishers as $i => $r) {
                    $lic = $r['person_license'];
                    $finaleRangs[$lic]  = $rangs[$i];
                    $finaleTijden[$lic] = $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null;
                    $rijderInfo[$lic] ??= ['full_name' => $r['full_name'], 'short_name' => $r['short_name'],
                                           'start_number' => $r['start_number'], 'categorie' => $r['categorie']];
                }
                foreach ($overigen as $r) {
                    $lic = $r['person_license'];
                    $finaleRangs[$lic]  = $finaleOffset + $nRijders;
                    $finaleTijden[$lic] = null;
                    $finaleOverigenLics[$lic] = true;
                    if (!empty($r['sanctie'])) {
                        $sancties[$lic] = $r['sanctie'];
                    }
                    $rijderInfo[$lic] ??= ['full_name' => $r['full_name'], 'short_name' => $r['short_name'],
                                           'start_number' => $r['start_number'], 'categorie' => $r['categorie']];
                }
                $finaleOffset += $nRijders;
            }
            // Post-correctie: overigen = laatste van totale finale-groep
            foreach (array_keys($finaleOverigenLics) as $lic) {
                $finaleRangs[$lic] = $finaleOffset;
            }

            // Per-cat vlag: serie telt alleen als startvolgorde-bepaling.
            // Fallback-ladder (zelfde logica als in uitslag_afstand.php):
            //   1. exacte match op distance_id
            //   2. via afstand_naam → tijdschema_ritten (splits dekken)
            //   3. als er precies 1 rij is voor dit dc_id, gebruik die
            $sasStmt = $pdo->prepare("
                SELECT cc.series_alleen_startvolgorde
                FROM tijdschema_cat_config cc
                JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
                WHERE ct.competition_id = ? AND cc.dc_id = ?
                  AND (cc.distance_id = ? OR (cc.distance_id IS NULL AND ? = ''))
                LIMIT 1
            ");
            $sasStmt->execute([$compId, $primaryDcId, $distId, $distId]);
            $sasRaw = $sasStmt->fetchColumn();

            if ($sasRaw === false && !empty($distNaam)) {
                // Via distances-tabel (naam → id)
                $sasStmtA = $pdo->prepare("
                    SELECT cc.series_alleen_startvolgorde
                    FROM tijdschema_cat_config cc
                    JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
                    WHERE ct.competition_id = ? AND cc.dc_id = ?
                      AND cc.distance_id IN (
                          SELECT d.id FROM distances d
                          WHERE d.distance_combination_id = ? AND d.name = ?
                      )
                    LIMIT 1
                ");
                $sasStmtA->execute([$compId, $primaryDcId, $primaryDcId, $distNaam]);
                $sasRaw = $sasStmtA->fetchColumn();
            }
            if ($sasRaw === false && !empty($distNaam)) {
                // Via tijdschema_ritten naam-match
                $sasStmt2 = $pdo->prepare("
                    SELECT cc.series_alleen_startvolgorde
                    FROM tijdschema_cat_config cc
                    JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
                    WHERE ct.competition_id = ? AND cc.dc_id = ?
                      AND cc.distance_id IN (
                          SELECT DISTINCT r.distance_id
                          FROM tijdschema_ritten r
                          JOIN competition_tijdschema ct2 ON ct2.id = r.tijdschema_id
                          WHERE ct2.competition_id = ? AND r.dc_id = ?
                            AND r.afstand_naam = ?
                            AND r.distance_id IS NOT NULL
                      )
                    LIMIT 1
                ");
                $sasStmt2->execute([$compId, $primaryDcId, $compId, $primaryDcId, $distNaam]);
                $sasRaw = $sasStmt2->fetchColumn();
            }
            if ($sasRaw === false) {
                $sasStmt3 = $pdo->prepare("
                    SELECT cc.series_alleen_startvolgorde
                    FROM tijdschema_cat_config cc
                    JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
                    WHERE ct.competition_id = ? AND cc.dc_id = ?
                ");
                $sasStmt3->execute([$compId, $primaryDcId]);
                $rows = $sasStmt3->fetchAll(PDO::FETCH_COLUMN);
                if (count($rows) === 1) $sasRaw = $rows[0];
            }
            $sasFlag = (bool)(int)($sasRaw === false ? 0 : $sasRaw);

            $gecombineerd = berekenCombineerdResultaat(
                $serieRangs, $finaleRangs, $rijderInfo,
                $serieTijden, $finaleTijden, $sancties,
                $sasFlag
            );

            foreach ($gecombineerd as $gc) {
                $lic    = $gc['person_license'];
                $punten = (float)$gc['rang'];
                $sanctieDb = null;
                if ($gc['sanctie'] !== null) {
                    // Respecteer bestaande handmatige correctie
                    $punten = $bestaandeOverrides[$lic][$distId] ?? $punten;
                    $geldigeSancties = ['W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF'];
                    $sanctieDb = in_array($gc['sanctie'], $geldigeSancties, true)
                        ? $gc['sanctie'] : null;
                }
                $upsertAfstand->execute([
                    $compId, $compNaam, $compDatum,
                    $primaryDcId, $dcNaam, '',
                    $distId, $distNaam, $distMeters,
                    $lic, $personCache[$lic]['categorie'] ?? null,
                    $gc['rang'], null, 'Serie + A-finale',
                    $gc['finale_tijd_ms'], $punten, $sanctieDb,
                ]);
                $puntenMap[$lic][$distId] = $punten;
            }

            $vastgelegdAfstanden++;
            continue;
        }

        // ── Normaal: alleen finales ───────────────────────────────────────────
        $finaleHeatsOnly = array_values(array_filter($heats, fn($h) => $h['ronde_type'] !== 'heats'));
        $rangOffset = 0;

        foreach ($finaleHeatsOnly as $heat) {
            $rows     = $laadRows($heat);
            $nRijders = count($rows);
            if (!$nRijders) continue;

            $rows = sorteerRijdersOpTijd($rows);

            [$finishers, $overigen] = splitsFinishersOverigen($rows);

            $rangs = berekenExAequoRangs($finishers, $rangOffset);

            // Label van de finale
            $rondeType  = $heat['ronde_type'];
            $finaleNaam = $rondeType === 'finale_a' ? 'A-finale' : 'B' . (int)$heat['heat_nr'] . '-finale';

            // Finishers wegschrijven
            foreach ($finishers as $i => $r) {
                $lic    = $r['person_license'];
                $rang   = $rangs[$i];
                $punten = (float)$rang;
                $upsertAfstand->execute([
                    $compId, $compNaam, $compDatum,
                    $primaryDcId, $dcNaam, '',
                    $distId, $distNaam, $distMeters,
                    $lic, $personCache[$lic]['categorie'] ?? null,
                    $rang, (int)$r['finishpositie'], $finaleNaam,
                    $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                    $punten, null,
                ]);
                $puntenMap[$lic][$distId] = $punten;
            }

            // Sanctie-rijders (respecteer bestaande handmatige correcties)
            $defaultPunten = (float)($rangOffset + $nRijders);
            foreach ($overigen as $r) {
                $s = $r['sanctie'] ?? null;
                if (!$s) continue;
                $lic    = $r['person_license'];
                $punten = $bestaandeOverrides[$lic][$distId] ?? $defaultPunten;
                // DB = UI codes, directe opslag
                $geldigeSancties2 = ['W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF'];
                $sanctieDb = in_array($s, $geldigeSancties2, true) ? $s : null;
                $upsertAfstand->execute([
                    $compId, $compNaam, $compDatum,
                    $primaryDcId, $dcNaam, '',
                    $distId, $distNaam, $distMeters,
                    $lic, $personCache[$lic]['categorie'] ?? null,
                    null, null, $finaleNaam,
                    null, $punten, $sanctieDb,
                ]);
                $puntenMap[$lic][$distId] = $punten;
            }

            $rangOffset += $nRijders;
        }
        $vastgelegdAfstanden++;
    }

    // ── uitslag_klassement herberekenen ───────────────────────────────────────
    // Lees alle afstand-data voor deze DC opnieuw uit uitslag_afstand
    // (bevat ook afstanden die eerder zijn vastgelegd maar nu niet opnieuw berekend).
    $allPuntenStmt = $pdo->prepare("
        SELECT person_license, distance_id, punten
        FROM uitslag_afstand
        WHERE competition_id          = ?
          AND distance_combination_id = ?
    ");
    $allPuntenStmt->execute([$compId, $primaryDcId]);
    $allPunten = []; // person_license => [dist_id => punten]
    foreach ($allPuntenStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $allPunten[$row['person_license']][$row['distance_id']] = (float)$row['punten'];
    }

    // Laatste afstand van deze DC (voor tiebreaker 3)
    $lastDistStmt = $pdo->prepare("
        SELECT id FROM distances
        WHERE distance_combination_id = ?
          AND (target_group IS NULL OR target_group = '')
        ORDER BY number DESC LIMIT 1
    ");
    $lastDistStmt->execute([$primaryDcId]);
    $lastDistId = $lastDistStmt->fetchColumn() ?: null;

    // Naam-lookup voor alle bekende afstanden (incl. eerder vastgelegde)
    $allDistNaamStmt = $pdo->prepare("
        SELECT id, name FROM distances WHERE distance_combination_id = ?
    ");
    $allDistNaamStmt->execute([$primaryDcId]);
    $distNaamMap = $allDistNaamStmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => name

    // Totaal per rijder + punten_detail
    // Rijders met een afstand op 0 punten worden uitgesloten uit het klassement
    $klasRows = [];
    foreach ($allPunten as $lic => $distPunten) {
        $totaal = array_sum($distPunten);
        $heeftNulAfstand = false;
        foreach ($distPunten as $p) { if ($p == 0) { $heeftNulAfstand = true; break; } }
        if ($heeftNulAfstand) continue; // 0 punten op een afstand = uitgesloten
        $detail = [];
        foreach ($distPunten as $dId => $p) {
            $detail[$distNaamMap[$dId] ?? $dId] = $p;
        }
        $klasRows[] = [
            'lic'         => $lic,
            'totaal'      => $totaal,
            'detail'      => $detail,
            'dist_punten' => $distPunten,
        ];
    }

    // ── Vergelijkfunctie: totaal → beste resultaat → laatste afstand ──────────
    $vergelijkKlas = function (array $a, array $b) use ($lastDistId): int {
        // 1. Totaal punten ASC
        $diff = $a['totaal'] <=> $b['totaal'];
        if ($diff !== 0) return $diff;

        // 2. Gesorteerde individuele punten vergelijken (beste resultaat eerst)
        $pA = array_values($a['dist_punten']);
        $pB = array_values($b['dist_punten']);
        sort($pA); sort($pB);
        $len = max(count($pA), count($pB));
        for ($i = 0; $i < $len; $i++) {
            $vA = $pA[$i] ?? PHP_INT_MAX;
            $vB = $pB[$i] ?? PHP_INT_MAX;
            if ($vA != $vB) return $vA <=> $vB;
        }

        // 3. Laatste afstand ASC
        if ($lastDistId !== null) {
            $lA = $a['dist_punten'][$lastDistId] ?? PHP_INT_MAX;
            $lB = $b['dist_punten'][$lastDistId] ?? PHP_INT_MAX;
            if ($lA != $lB) return $lA <=> $lB;
        }

        return 0; // Echte ex-aequo
    };

    usort($klasRows, function ($a, $b) use ($vergelijkKlas, $personCache) {
        $cmp = $vergelijkKlas($a, $b);
        return $cmp !== 0 ? $cmp
            : strcmp($personCache[$a['lic']]['full_name'] ?? '', $personCache[$b['lic']]['full_name'] ?? '');
    });

    $upsertKlas = $pdo->prepare("
        INSERT INTO uitslag_klassement
            (competition_id, competition_naam, competition_datum,
             distance_combination_id, dc_naam, split_group,
             person_license, categorie,
             rang, punten_totaal, punten_detail)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            rang            = VALUES(rang),
            punten_totaal   = VALUES(punten_totaal),
            punten_detail   = VALUES(punten_detail),
            competition_naam= VALUES(competition_naam),
            dc_naam         = VALUES(dc_naam),
            vastgelegd_at   = CURRENT_TIMESTAMP
    ");

    $prevRang = 0;
    foreach ($klasRows as $i => $kr) {
        if ($i === 0) {
            $rang = 1;
        } else {
            $gelijk = $vergelijkKlas($kr, $klasRows[$i - 1]) === 0;
            $rang   = $gelijk ? $prevRang : ($i + 1);
        }
        $prevRang = $rang;
        $cat = $personCache[$kr['lic']]['categorie'] ?? null;
        $upsertKlas->execute([
            $compId, $compNaam, $compDatum,
            $primaryDcId, $dcNaam, '',
            $kr['lic'], $cat,
            $rang, $kr['totaal'],
            json_encode($kr['detail'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'                  => true,
        'afstanden_vastgelegd'=> $vastgelegdAfstanden,
        'klassement_bijgewerkt' => count($klasRows),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
