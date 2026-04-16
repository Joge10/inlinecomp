<?php
// ============================================================
//  InlineComp – Uitslag per afstand
//
//  GET ?competition_id=X&dc_id=Y[&distance_id=Z][&dc_ids=A,B]
//
//  Geeft de uitslag terug voor één afstand van een wedstrijd.
//  Ondersteunt momenteel: full-final systeem.
//
//  Respons:
//  {
//    "systeem":  "full-final",
//    "finales":  [
//      {
//        "label":    "A-Finale",
//        "type":     "finale_a",
//        "heat_nr":  1,
//        "compleet": true,         // alle finishposities ingevuld
//        "rijders":  [
//          { "rang": 1, "full_name": "...", "start_number": 42,
//            "categorie": "DKA", "finishpositie": 1, "tijd_ms": 75230,
//            "sanctie": null }
//        ]
//      },
//      {
//        "label":   "B1-Finale",
//        "type":    "finale_b",
//        "heat_nr": 1,
//        "compleet": false,
//        "rijders": [ ... ]
//      }
//    ],
//    "has_results": true
//  }
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/_uitslag_helper.php';
requireAuth($pdo);

$compId    = trim($_GET['competition_id'] ?? '');
$dcIdsRaw  = trim($_GET['dc_ids'] ?? $_GET['dc_id'] ?? '');
$dcIds     = array_values(array_filter(array_map('trim', explode(',', $dcIdsRaw))));
$primaryDcId = $dcIds[0] ?? '';
$distId    = trim($_GET['distance_id'] ?? '');

if (!$compId || !$primaryDcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_id zijn verplicht']);
    exit;
}

try {
    // ── Systeem bepalen ───────────────────────────────────────────────────────
    $sysStmt = $pdo->prepare("
        SELECT systeem FROM competition_tijdschema WHERE competition_id = ?
    ");
    $sysStmt->execute([$compId]);
    $systeem = $sysStmt->fetchColumn() ?: 'internationaal-nieuw';

    if ($systeem !== 'full-final') {
        // ── Internationaal systeem: cascading elimination ranking ─────────
        // Haal tijdschema-id + afstandsnaam op
        $tsStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ?");
        $tsStmt->execute([$compId]);
        $tsId = $tsStmt->fetchColumn();

        // Ranking methods per ronde ophalen
        $rankingMethods = ['heats' => 'time', 'kwartfinale' => 'time',
                           'halve_finale' => 'time', 'finale_a' => 'time'];
        if ($tsId) {
            // Zoek afstand_naam via een rit voor deze DC
            $afNaamStmt = $pdo->prepare("
                SELECT afstand_naam FROM tijdschema_ritten
                WHERE tijdschema_id = ? AND dc_id = ? LIMIT 1
            ");
            $afNaamStmt->execute([$tsId, $primaryDcId]);
            $afNaam = $afNaamStmt->fetchColumn();
            if ($afNaam) {
                $acStmt = $pdo->prepare("
                    SELECT heats_ranking, kwart_ranking, half_ranking, finale_ranking, race_type
                    FROM tijdschema_afstand_config
                    WHERE tijdschema_id = ? AND afstand_naam = ?
                ");
                $acStmt->execute([$tsId, $afNaam]);
                $ac = $acStmt->fetch(PDO::FETCH_ASSOC);
                if ($ac) {
                    $rankingMethods['heats']        = $ac['heats_ranking']  ?? 'time';
                    $rankingMethods['kwartfinale']   = $ac['kwart_ranking'] ?? 'time';
                    $rankingMethods['halve_finale']  = $ac['half_ranking']  ?? 'time';
                    $rankingMethods['finale_a']      = $ac['finale_ranking'] ?? 'time';
                    $raceType = $ac['race_type'] ?? 'sprint';
                }
            }
        }

        // Alle heats voor deze afstand ophalen, per ronde
        $dcPh = implode(',', array_fill(0, count($dcIds), '?'));
        $distCond   = $distId ? 'AND COALESCE(h.distance_id, ts_r.distance_id) = ?' : '';
        $distParams = $distId ? [$distId] : [];

        $heatSql = "
            SELECT h.id, h.heat_nr, h.heat_naam, h.ronde,
                   COALESCE(ts_r.ronde_type, 'finale_a') AS ronde_type
            FROM heats h
            LEFT JOIN tijdschema_ritten ts_r ON ts_r.id = h.tijdschema_rit_id
            WHERE h.competition_id = ?
              AND h.distance_combination_id IN ($dcPh)
              {$distCond}
            ORDER BY h.ronde ASC, h.heat_nr ASC
        ";
        $heatStmt = $pdo->prepare($heatSql);
        $heatStmt->execute(array_merge([$compId], $dcIds, $distParams));
        $alleHeats = $heatStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($alleHeats)) {
            echo json_encode(['systeem' => $systeem, 'finales' => [], 'has_results' => false]);
            exit;
        }

        // Wedstrijd-startnummers
        $snStmt = $pdo->prepare("
            SELECT person_license, startnummer FROM competition_startnummers WHERE competition_id = ?
        ");
        $snStmt->execute([$compId]);
        $snMap = [];
        foreach ($snStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snMap[$row['person_license']] = $row['startnummer'];
        }

        // Rijders per heat laden
        $rijderStmt = $pdo->prepare("
            SELECT he.person_license, p.full_name, p.short_name, p.start_number,
                   p.category AS categorie, res.finishpositie, res.tijd_ms, res.sanctie,
                   res.rondes, res.punten AS pk_punten
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");

        // Groepeer heats per ronde_type
        $rondeGroepen = [];
        $rondeLabels = [
            'heats'        => 'Serie',
            'kwartfinale'  => 'Kwartfinale',
            'halve_finale' => 'Halve finale',
            'finale_a'     => 'A-Finale',
            'runner_up'    => 'Runner-up',
        ];

        foreach ($alleHeats as $heat) {
            $rt = $heat['ronde_type'];
            if (!isset($rondeGroepen[$rt])) {
                $rondeGroepen[$rt] = [
                    'ronde_type' => $rt,
                    'label'      => $rondeLabels[$rt] ?? $rt,
                    'ranking'    => $rankingMethods[$rt] ?? 'time',
                    'rows'       => [],
                ];
            }
            $rijderStmt->execute([(int)$heat['id']]);
            $rows = $rijderStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $r['start_number'] = $snMap[$r['person_license']] ?? $r['start_number'];
                $rondeGroepen[$rt]['rows'][] = $r;
            }
        }

        // Sorteer rondes: finale eerst → series laatst (cascade: beste bovenaan)
        $rondeVolgorde = ['finale_a' => 0, 'runner_up' => 1, 'halve_finale' => 2,
                          'kwartfinale' => 3, 'heats' => 4];
        $rondeDataArr = array_values($rondeGroepen);
        usort($rondeDataArr, fn($a, $b) =>
            ($rondeVolgorde[$a['ronde_type']] ?? 5) <=> ($rondeVolgorde[$b['ronde_type']] ?? 5)
        );

        $resultaat = berekenInternationaalResultaat($rondeDataArr);
        $hasResults = !empty($resultaat);

        // Alle sancties ophalen voor weergave
        $alleLics = array_column($resultaat, 'person_license');
        $alleLics = array_values(array_unique($alleLics));
        $sanctieMap = [];
        if ($alleLics) {
            $licPh = implode(',', array_fill(0, count($alleLics), '?'));
            $distSanctieFilter = $distId
                ? "AND (COALESCE(h.distance_id, ts_r.distance_id) = ? OR (h.distance_id IS NULL AND ts_r.distance_id IS NULL))"
                : "";
            $distSanctieParams = $distId ? [$distId] : [];
            $sanctieStmt = $pdo->prepare("
                SELECT DISTINCT he.person_license,
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
                JOIN results res ON res.heat_entry_id = he.id
                WHERE he.person_license IN ($licPh)
                  AND h.competition_id = ?
                  AND h.distance_combination_id IN ($dcPh)
                  {$distSanctieFilter}
                  AND res.sanctie IS NOT NULL
                ORDER BY h.ronde, h.heat_nr
            ");
            $sanctieStmt->execute(array_merge($alleLics, [$compId], $dcIds, $distSanctieParams));
            foreach ($sanctieStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $sanctieMap[$s['person_license']][] = [
                    'ronde'   => $s['ronde_label'],
                    'sanctie' => $s['sanctie'],
                ];
            }
        }
        foreach ($resultaat as &$r) {
            $r['alle_sancties'] = $sanctieMap[$r['person_license']] ?? [];
        }
        unset($r);

        // Beschikbare rondes uit tijdschema_ritten (niet uit heats — die bestaan
        // pas na generatie, maar de rondes zijn al geconfigureerd in het tijdschema)
        $rondeKeys = ['heats' => 'heats', 'kwartfinale' => 'kwart',
                      'halve_finale' => 'half', 'finale_a' => 'finale'];
        $beschikbareRondes = [];
        if ($tsId) {
            $brStmt = $pdo->prepare("
                SELECT DISTINCT r.ronde_type FROM tijdschema_ritten r
                WHERE r.tijdschema_id = ? AND r.dc_id IN ($dcPh)
                  AND r.ronde_type IN ('heats','kwartfinale','halve_finale','finale_a')
                ORDER BY FIELD(r.ronde_type, 'heats','kwartfinale','halve_finale','finale_a')
            ");
            $brStmt->execute(array_merge([$tsId], $dcIds));
            $beschikbareRondes = array_column($brStmt->fetchAll(PDO::FETCH_ASSOC), 'ronde_type');
        }
        if (empty($beschikbareRondes)) {
            // Fallback: gebruik rondes uit bestaande heats
            foreach (array_keys($rondeGroepen) as $rt) {
                if (isset($rondeKeys[$rt])) $beschikbareRondes[] = $rt;
            }
        }
        $rankingConfig = [];
        foreach ($rondeKeys as $rt => $key) {
            $rankingConfig[$key] = $rankingMethods[$rt] ?? 'time';
        }

        // Detecteer of rondes/pk_punten relevant zijn
        $heeftRondes   = !empty(array_filter($resultaat, fn($r) => ($r['rondes'] ?? null) !== null));
        $heeftPkPunten = !empty(array_filter($resultaat, fn($r) => ($r['pk_punten'] ?? null) !== null));

        echo json_encode([
            'systeem'       => $systeem,
            'modus'         => 'internationaal',
            'resultaat'     => $resultaat,
            'has_results'   => $hasResults,
            'afstand_naam'  => $afNaam ?? null,
            'rondes'        => $beschikbareRondes,
            'ranking'       => $rankingConfig,
            'race_type'     => $raceType ?? 'sprint',
            'heeft_rondes'  => $heeftRondes,
            'heeft_pk_punten' => $heeftPkPunten,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Finales ophalen (ronde=4, ronde_type finale_a / finale_b) ─────────────
    // Gebruik dc_ids (alle DC's in een samenvoeging) voor breed zoeken,
    // maar primaire DC voor de heats (heats slaan alleen primary dc_id op).
    $dcPh    = implode(',', array_fill(0, count($dcIds), '?'));
    // COALESCE: gebruik h.distance_id als die gevuld is, anders de distance_id
    // van de gekoppelde tijdschema_rit (sommige finale-heats hebben zelf NULL).
    $distCond   = $distId ? 'AND COALESCE(h.distance_id, ts_r.distance_id) = ?' : '';
    $distParams = $distId ? [$distId] : [];

    $heatSql = "
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
          {$distCond}
        ORDER BY
            CASE COALESCE(ts_r.ronde_type, 'finale_a')
                WHEN 'heats'    THEN 0
                WHEN 'finale_a' THEN 1
                WHEN 'finale_b' THEN 2
                ELSE 3
            END,
            h.heat_nr ASC
    ";
    $heatParams = array_merge([$compId], $dcIds, $distParams);
    $heatStmt   = $pdo->prepare($heatSql);
    $heatStmt->execute($heatParams);
    $heats = $heatStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($heats)) {
        echo json_encode(['systeem' => $systeem, 'finales' => [], 'has_results' => false]);
        exit;
    }

    // ── Wedstrijd-startnummers (override persoons-startnummer) ────────────────
    $snStmt = $pdo->prepare("
        SELECT person_license, startnummer FROM competition_startnummers WHERE competition_id = ?
    ");
    $snStmt->execute([$compId]);
    $snMap = [];
    foreach ($snStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snMap[$row['person_license']] = $row['startnummer'];
    }

    // ── Rijders per heat ophalen ──────────────────────────────────────────────
    $rijderStmt = $pdo->prepare("
        SELECT he.person_license,
               he.startpositie,
               p.full_name,
               p.short_name,
               p.start_number,
               p.category         AS categorie,
               res.finishpositie,
               res.tijd_ms,
               res.sanctie,
               res.rondes,
               res.punten AS pk_punten
        FROM heat_entries he
        JOIN persons p ON p.license_key = he.person_license
        LEFT JOIN results res ON res.heat_entry_id = he.id
        WHERE he.heat_id = ?
        ORDER BY he.startpositie
    ");

    // ── Helper: rows laden voor één heat ──────────────────────────────────────
    $laadRows = function(array $heat) use ($rijderStmt, $snMap): array {
        $rijderStmt->execute([(int)$heat['id']]);
        $rows = $rijderStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['start_number'] = $snMap[$r['person_license']] ?? $r['start_number'];
        }
        unset($r);
        return $rows;
    };

    // ── Gecombineerde modus? (1 serie + alleen A-finale) ─────────────────────
    if (isCombineerdModus($heats)) {
        // Splits series- en finale-heats
        $serieHeats  = array_values(array_filter($heats, fn($h) => $h['ronde_type'] === 'heats'));
        $finaleHeats = array_values(array_filter($heats, fn($h) => $h['ronde_type'] !== 'heats'));

        $serieRangs   = [];   // person_license => rang
        $finaleRangs  = [];
        $serieTijden  = [];   // person_license => tijd_ms|null
        $finaleTijden = [];
        $rijderInfo   = [];   // person_license => [full_name, short_name, start_number, categorie]
        $sancties     = [];   // person_license => sanctie|null (finale-sanctie)

        // ── Serie ─────────────────────────────────────────────────────────────
        $serieOffset = 0;
        $serieCompleet = true;
        foreach ($serieHeats as $heat) {
            $rows = $laadRows($heat);
            if (!$rows) { $serieCompleet = false; continue; }
            $rows = sorteerRijdersOpTijd($rows);
            if (!isHeatCompleet($rows)) $serieCompleet = false;
            $nRijders  = count($rows);
            $finishers = array_values(array_filter($rows, fn($r) => $r['finishpositie'] !== null));
            $rangs     = berekenExAequoRangs($finishers, $serieOffset);
            foreach ($finishers as $i => $r) {
                $lic = $r['person_license'];
                $serieRangs[$lic]  = $rangs[$i];
                $serieTijden[$lic] = $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null;
                $rijderInfo[$lic]  = [
                    'full_name'    => $r['full_name'],
                    'short_name'   => $r['short_name'],
                    'start_number' => $r['start_number'],
                    'categorie'    => $r['categorie'],
                ];
            }
            // Sanctie-rijders: rang = laatste plek in deze heat
            $overigen = array_values(array_filter($rows, fn($r) => $r['finishpositie'] === null));
            foreach ($overigen as $r) {
                $lic = $r['person_license'];
                $serieRangs[$lic]  = $serieOffset + $nRijders;
                $serieTijden[$lic] = null;
                $rijderInfo[$lic]  = [
                    'full_name'    => $r['full_name'],
                    'short_name'   => $r['short_name'],
                    'start_number' => $r['start_number'],
                    'categorie'    => $r['categorie'],
                ];
            }
            $serieOffset += $nRijders;
        }

        // ── Finale ────────────────────────────────────────────────────────────
        $finaleOffset  = 0;
        $finaleCompleet = true;
        foreach ($finaleHeats as $heat) {
            $rows = $laadRows($heat);
            if (!$rows) { $finaleCompleet = false; continue; }
            $rows = sorteerRijdersOpTijd($rows);
            if (!isHeatCompleet($rows)) $finaleCompleet = false;
            $nRijders  = count($rows);
            $finishers = array_values(array_filter($rows, fn($r) => $r['finishpositie'] !== null));
            $rangs     = berekenExAequoRangs($finishers, $finaleOffset);
            foreach ($finishers as $i => $r) {
                $lic = $r['person_license'];
                $finaleRangs[$lic]  = $rangs[$i];
                $finaleTijden[$lic] = $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null;
                $rijderInfo[$lic] ??= [
                    'full_name'    => $r['full_name'],
                    'short_name'   => $r['short_name'],
                    'start_number' => $r['start_number'],
                    'categorie'    => $r['categorie'],
                ];
            }
            $overigen = array_values(array_filter($rows, fn($r) => $r['finishpositie'] === null));
            foreach ($overigen as $r) {
                $lic = $r['person_license'];
                $finaleRangs[$lic]  = $finaleOffset + $nRijders;
                $finaleTijden[$lic] = null;
                $sancties[$lic]     = $r['sanctie'];
                $rijderInfo[$lic] ??= [
                    'full_name'    => $r['full_name'],
                    'short_name'   => $r['short_name'],
                    'start_number' => $r['start_number'],
                    'categorie'    => $r['categorie'],
                ];
            }
            $finaleOffset += $nRijders;
        }

        $gecombineerd = berekenCombineerdResultaat(
            $serieRangs, $finaleRangs, $rijderInfo,
            $serieTijden, $finaleTijden, $sancties
        );

        $hasResults = $serieCompleet && $finaleCompleet && !empty($gecombineerd);

        // Alle sancties over alle rondes per rijder
        $alleLics = array_column($gecombineerd, 'person_license');
        $sanctieMap = [];
        if ($alleLics) {
            $licPh = implode(',', array_fill(0, count($alleLics), '?'));
            $distSanctieFilter = $distId
                ? "AND (COALESCE(h.distance_id, ts_r.distance_id) = ? OR (h.distance_id IS NULL AND ts_r.distance_id IS NULL))"
                : "";
            $distSanctieParams = $distId ? [$distId] : [];
            $sanctieStmt = $pdo->prepare("
                SELECT DISTINCT he.person_license,
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
                JOIN results res ON res.heat_entry_id = he.id
                WHERE he.person_license IN ($licPh)
                  AND h.competition_id = ?
                  AND h.distance_combination_id IN ($dcPh)
                  {$distSanctieFilter}
                  AND res.sanctie IS NOT NULL
                ORDER BY h.ronde, h.heat_nr
            ");
            $sanctieStmt->execute(array_merge($alleLics, [$compId], $dcIds, $distSanctieParams));
            foreach ($sanctieStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $sanctieMap[$s['person_license']][] = [
                    'ronde'   => $s['ronde_label'],
                    'sanctie' => $s['sanctie'],
                ];
            }
        }
        foreach ($gecombineerd as &$gc) {
            $gc['alle_sancties'] = $sanctieMap[$gc['person_license']] ?? [];
        }
        unset($gc);

        echo json_encode([
            'systeem'      => $systeem,
            'modus'        => 'gecombineerd',
            'gecombineerd' => $gecombineerd,
            'has_results'  => $hasResults,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Normaal: alleen finales ───────────────────────────────────────────────
    // Filter series eruit mocht die er toch in zitten (normaal niet het geval)
    $finaleHeatsOnly = array_values(array_filter($heats, fn($h) => $h['ronde_type'] !== 'heats'));

    $finales    = [];
    $rangOffset = 0;         // lopende rang over alle finales heen

    foreach ($finaleHeatsOnly as $heat) {
        $rows = $laadRows($heat);

        // Sortering: snelste tijd eerst, dan finishpositie als tiebreaker,
        // rijders zonder finishpositie (DNS/DNF/nog niet ingevuld) achteraan.
        $rows = sorteerRijdersOpTijd($rows);

        // Compleetheid: alle rijders hebben een finishpositie of een eindsanctie
        $nRijders = count($rows);
        $compleet = isHeatCompleet($rows);

        // ── Ex-aequo rang toekennen ───────────────────────────────────────────
        $finishers = array_values(array_filter($rows, fn($r) => $r['finishpositie'] !== null));
        $overigen  = array_values(array_filter($rows, fn($r) => $r['finishpositie'] === null));

        $rangs = berekenExAequoRangs($finishers, $rangOffset);

        $rijders = [];
        foreach ($finishers as $i => $r) {
            $rijders[] = [
                'person_license'=> $r['person_license'],
                'rang'          => $rangs[$i],
                'full_name'     => $r['full_name'],
                'short_name'    => $r['short_name'],
                'start_number'  => $r['start_number'],
                'categorie'     => $r['categorie'],
                'finishpositie' => (int)$r['finishpositie'],
                'tijd_ms'       => $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                'sanctie'       => $r['sanctie'],
                'rondes'        => isset($r['rondes']) && $r['rondes'] !== null ? (int)$r['rondes'] : null,
                'pk_punten'     => isset($r['pk_punten']) && $r['pk_punten'] !== null ? (float)$r['pk_punten'] : null,
            ];
        }
        foreach ($overigen as $r) {
            $rijders[] = [
                'person_license'=> $r['person_license'],
                'rang'          => null,
                'full_name'     => $r['full_name'],
                'short_name'    => $r['short_name'],
                'start_number'  => $r['start_number'],
                'categorie'     => $r['categorie'],
                'finishpositie' => null,
                'tijd_ms'       => $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                'sanctie'       => $r['sanctie'],
                'rondes'        => isset($r['rondes']) && $r['rondes'] !== null ? (int)$r['rondes'] : null,
                'pk_punten'     => isset($r['pk_punten']) && $r['pk_punten'] !== null ? (float)$r['pk_punten'] : null,
            ];
        }

        // Nette label bepalen
        $rondeType = $heat['ronde_type'];
        if ($rondeType === 'finale_a') {
            $label = 'A-Finale';
        } else {
            $label = 'B' . (int)$heat['heat_nr'] . '-Finale';
        }

        $finales[] = [
            'label'    => $label,
            'type'     => $rondeType,
            'heat_nr'  => (int)$heat['heat_nr'],
            'heat_naam'=> $heat['heat_naam'],
            'compleet' => $compleet,
            'rijders'  => $rijders,
        ];

        // Rang-offset: totale heat-grootte (ook DNS/DNF bezetten een positie-slot).
        $rangOffset += $nRijders;
    }

    $hasResults = !empty(array_filter($finales, fn($f) => $f['compleet']));

    // ── Alle sancties over alle rondes per rijder ophalen ─────────────────
    // (inclusief serie-sancties zoals FS die niet in de finale-data zitten)
    $alleLics = [];
    foreach ($finales as $f) foreach ($f['rijders'] as $r) $alleLics[] = $r['person_license'];
    $alleLics = array_values(array_unique($alleLics));

    $sanctieMap = []; // person_license => [{ronde, sanctie}]
    if ($alleLics) {
        $licPh = implode(',', array_fill(0, count($alleLics), '?'));
        // Haal sancties op voor deze afstand: match op distance_id OF heats zonder distance_id
        $distSanctieFilter = $distId
            ? "AND (COALESCE(h.distance_id, ts_r.distance_id) = ? OR (h.distance_id IS NULL AND ts_r.distance_id IS NULL))"
            : "";
        $distSanctieParams = $distId ? [$distId] : [];
        $sanctieStmt = $pdo->prepare("
            SELECT DISTINCT he.person_license,
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
            JOIN results res ON res.heat_entry_id = he.id
            WHERE he.person_license IN ($licPh)
              AND h.competition_id = ?
              AND h.distance_combination_id IN ($dcPh)
              {$distSanctieFilter}
              AND res.sanctie IS NOT NULL
            ORDER BY h.ronde, h.heat_nr
        ");
        $sanctieStmt->execute(array_merge($alleLics, [$compId], $dcIds, $distSanctieParams));
        foreach ($sanctieStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sanctieMap[$s['person_license']][] = [
                'ronde'   => $s['ronde_label'],
                'sanctie' => $s['sanctie'],
            ];
        }
    }

    // Sancties toevoegen aan rijder-objecten
    foreach ($finales as &$finale) {
        foreach ($finale['rijders'] as &$r) {
            $r['alle_sancties'] = $sanctieMap[$r['person_license']] ?? [];
        }
        unset($r);
    }
    unset($finale);

    // Detecteer rondes/pk_punten in finale-data
    $alleRijders = [];
    foreach ($finales as $f) foreach ($f['rijders'] as $r) $alleRijders[] = $r;
    $heeftRondes   = !empty(array_filter($alleRijders, fn($r) => ($r['rondes'] ?? null) !== null));
    $heeftPkPunten = !empty(array_filter($alleRijders, fn($r) => ($r['pk_punten'] ?? null) !== null));

    echo json_encode([
        'systeem'           => $systeem,
        'finales'           => $finales,
        'has_results'       => $hasResults,
        'heeft_rondes'      => $heeftRondes,
        'heeft_pk_punten'   => $heeftPkPunten,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
