<?php
// ============================================================
//  InlineComp – laad opgeslagen startlijst (ronde 1)
//
//  GET /api/startlijst_laden.php
//    competition_id   – UUID wedstrijd
//    dc_ids           – kommagescheiden dc_ids (primary first)
//    distance_id      – UUID afstand (leeg voor afstandsloze DC)
//    category_filter  – optioneel, kommagescheiden (voor splits)
//
//  Geeft { exists: false } of:
//  {
//    exists: true,
//    methode: "startnummer",
//    gegenereerd_op: "2026-03-31 14:00:00",
//    aantalHeats: 4,
//    totaalRijders: 24,
//    heats: [{ nummer, heat_naam, rijders: [{...}] }]
//  }
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$compId       = trim($_GET['competition_id'] ?? '');
$dcIdsRaw     = trim($_GET['dc_ids'] ?? $_GET['dc_id'] ?? '');
$dcIds        = array_values(array_filter(array_map('trim', explode(',', $dcIdsRaw))));
$distId       = trim($_GET['distance_id']    ?? '');
$catFilterRaw = trim($_GET['category_filter'] ?? '');
$splitGroup   = $catFilterRaw ?: null;

if (!$compId || !$dcIds) {
    echo json_encode(['exists' => false]);
    exit;
}

$primaryDcId = $dcIds[0];

try {
    // Zoek heats voor deze categorie/afstand (ronde 1), gesorteerd op tijdschema-volgorde
    // JOIN op tijdschema_ritten om ronde_type te achterhalen (bijv. 'finale_a' bij geen series)
    $stmt = $pdo->prepare("
        SELECT h.id, h.heat_nr, h.heat_naam, h.methode, h.gegenereerd_op,
               COALESCE(h.rit_volgorde, h.heat_nr) AS rit_volgorde,
               tsr.ronde_type AS werkelijk_ronde_type
        FROM heats h
        LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
        WHERE h.competition_id          = ?
          AND h.distance_combination_id = ?
          AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? = ''))
          AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
          AND h.ronde = 1
        ORDER BY COALESCE(h.rit_volgorde, h.heat_nr)
    ");
    $stmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup]);
    $heatRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$heatRows) {
        echo json_encode(['exists' => false]);
        exit;
    }

    $methode      = $heatRows[0]['methode'] ?? 'startnummer';
    $gegenereerd  = $heatRows[0]['gegenereerd_op'] ?? null;
    $heatIds      = array_column($heatRows, 'id');

    // Rijders per heat ophalen
    $ph   = implode(',', array_fill(0, count($heatIds), '?'));
    $stmt = $pdo->prepare("
        SELECT he.heat_id, he.startpositie, he.startnummer, he.categorie,
               p.license_key, p.full_name, p.short_name, p.club_short, p.city,
               p.start_number
        FROM heat_entries he
        JOIN persons p ON p.license_key = he.person_license
        WHERE he.heat_id IN ($ph)
        ORDER BY he.heat_id, he.startpositie
    ");
    $stmt->execute($heatIds);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Transponders ophalen
    $licenseKeys = array_unique(array_column($entries, 'license_key'));
    $tpMap = [];
    if ($licenseKeys) {
        $ph2   = implode(',', array_fill(0, count($licenseKeys), '?'));
        $tpStmt = $pdo->prepare("
            SELECT person_license, slot, code
            FROM transponders
            WHERE competition_id = ? AND person_license IN ($ph2)
            ORDER BY slot
        ");
        $tpStmt->execute(array_merge([$compId], $licenseKeys));
        foreach ($tpStmt->fetchAll() as $tp) {
            $tpMap[$tp['person_license']][$tp['slot']] = $tp['code'];
        }
    }

    // Org-transponder betaald-status ophalen
    $tpBetaaldMap = [];  // person_license => true/false
    $orgIdStmt2 = $pdo->prepare("SELECT organisatie_id FROM competitions WHERE id = ?");
    $orgIdStmt2->execute([$compId]);
    $orgId2 = $orgIdStmt2->fetchColumn() ?: null;
    if ($orgId2) {
        $otStmt2 = $pdo->prepare("
            SELECT transponder_code, betaald FROM organisatie_transponders WHERE organisatie_id = ?
        ");
        $otStmt2->execute([$orgId2]);
        $otBetaaldMap = [];
        foreach ($otStmt2->fetchAll(PDO::FETCH_ASSOC) as $ot) {
            $otBetaaldMap[$ot['transponder_code']] = (int)$ot['betaald'];
        }
        // Koppel: rijder → actieve transponder → betaald?
        foreach ($licenseKeys as $lk) {
            $actief = $tpMap[$lk][0] ?? null;
            if ($actief && isset($otBetaaldMap[$actief])) {
                $tpBetaaldMap[$lk] = $otBetaaldMap[$actief];
            }
        }
    }

    // Groepeer entries per heat
    $heatMap = [];
    foreach ($heatRows as $h) {
        $heatMap[$h['id']] = [
            'nummer'       => (int)$h['heat_nr'],
            'rit_volgorde' => (int)$h['rit_volgorde'],
            'heat_naam'    => $h['heat_naam'],
            'rijders'      => [],
        ];
    }
    foreach ($entries as $e) {
        $lk = $e['license_key'];
        $e['transponder_actief'] = $tpMap[$lk][0] ?? null;
        $e['transponder1']       = $tpMap[$lk][1] ?? null;
        $e['transponder2']       = $tpMap[$lk][2] ?? null;
        $e['tp_betaald']         = $tpBetaaldMap[$lk] ?? null; // null=geen org-tp, 0=niet betaald, 1=betaald
        $e['transponders_extra'] = [];
        foreach ($tpMap[$lk] ?? [] as $slot => $code) {
            if ($slot >= 3) $e['transponders_extra'][] = $code;
        }
        $heatMap[$e['heat_id']]['rijders'][] = $e;
    }

    $heats        = array_values($heatMap);
    $totaalRijders = array_sum(array_map(fn($h) => count($h['rijders']), $heats));

    // ── Ronde 1 compleet? (alle rijders hebben resultaat) ──────────────────
    $r1Stmt = $pdo->prepare("
        SELECT COUNT(he.id)                                              AS totaal,
               SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END)      AS met_resultaat
        FROM heats h
        JOIN heat_entries he ON he.heat_id = h.id
        LEFT JOIN results res ON res.heat_entry_id = he.id
        WHERE h.competition_id          = ?
          AND h.distance_combination_id = ?
          AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? = ''))
          AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
          AND h.ronde = 1
    ");
    $r1Stmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup]);
    $r1Stat         = $r1Stmt->fetch(PDO::FETCH_ASSOC);
    $ronde1Compleet = $r1Stat && (int)$r1Stat['totaal'] > 0
                      && (int)$r1Stat['totaal'] === (int)$r1Stat['met_resultaat'];

    // ── Volgende rondes ophalen (ronde > 1) ────────────────────────────────
    $volgStmt = $pdo->prepare("
        SELECT h.id, h.ronde, h.heat_nr, h.heat_naam, h.methode,
               COALESCE(h.rit_volgorde, h.heat_nr) AS rit_volgorde,
               ts_r.ronde_type
        FROM heats h
        LEFT JOIN tijdschema_ritten ts_r ON ts_r.id = h.tijdschema_rit_id
        WHERE h.competition_id          = ?
          AND h.distance_combination_id = ?
          AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? = ''))
          AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
          AND h.ronde > 1
        ORDER BY h.ronde, COALESCE(h.rit_volgorde, h.heat_nr)
    ");
    $volgStmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup]);
    $volgHeatRows = $volgStmt->fetchAll(PDO::FETCH_ASSOC);

    $volgRondes = [];
    if ($volgHeatRows) {
        // Groepeer heats per ronde-nummer + ronde_type (belangrijk voor full-final
        // waarbij finale_a en finale_b allebei ronde=4 hebben maar aparte types zijn).
        $rondeGroepen = [];
        foreach ($volgHeatRows as $h) {
            $rn = (int)$h['ronde'];
            $rt = $h['ronde_type'] ?? "ronde_{$rn}";
            $gKey = "{$rn}_{$rt}";
            if (!isset($rondeGroepen[$gKey])) {
                $rondeGroepen[$gKey] = [
                    'ronde_nr'   => $rn,
                    'ronde_type' => $rt,
                    'methode'    => $h['methode'] ?? 'kwalificatie',
                    'heatRows'   => [],
                ];
            }
            $rondeGroepen[$gKey]['heatRows'][] = $h;
        }

        foreach ($rondeGroepen as $gKey => $rondeData) {
            $rn = $rondeData['ronde_nr'];
            $vHeatIds = array_column($rondeData['heatRows'], 'id');
            $phV      = implode(',', array_fill(0, count($vHeatIds), '?'));

            // Rijders ophalen + alle sancties uit ALLE vorige rondes (ronde < huidige)
            // GROUP_CONCAT geeft bijv. "Series: FS · KF: W1"
            $veStmt = $pdo->prepare("
                SELECT he.heat_id, he.startpositie, he.startnummer, he.categorie,
                       p.license_key, p.full_name, p.short_name,
                       p.start_number, p.club_short,
                       (SELECT GROUP_CONCAT(
                                   CONCAT(
                                       CASE h_v.ronde
                                           WHEN 1 THEN 'S'
                                           WHEN 2 THEN 'KF'
                                           WHEN 3 THEN 'HF'
                                           WHEN 4 THEN 'F'
                                           ELSE CONCAT('R', h_v.ronde)
                                       END,
                                       ':',
                                       res_v.sanctie
                                   )
                                   ORDER BY h_v.ronde
                                   SEPARATOR ' '
                               )
                        FROM heat_entries he_v
                        JOIN heats h_v ON h_v.id = he_v.heat_id
                        JOIN results res_v ON res_v.heat_entry_id = he_v.id
                        WHERE he_v.person_license         = he.person_license
                          AND h_v.competition_id          = ?
                          AND h_v.distance_combination_id = ?
                          AND (h_v.distance_id = ? OR (h_v.distance_id IS NULL AND ? = ''))
                          AND h_v.ronde < ?
                          AND res_v.sanctie IS NOT NULL
                       ) AS vorige_sancties
                FROM heat_entries he
                JOIN persons p ON p.license_key = he.person_license
                WHERE he.heat_id IN ($phV)
                ORDER BY he.heat_id, he.startpositie
            ");
            $veStmt->execute(array_merge(
                [$compId, $primaryDcId, $distId, $distId, $rn],
                $vHeatIds
            ));
            $vEntries = $veStmt->fetchAll(PDO::FETCH_ASSOC);

            // Transponders voor deze rijders
            $vLicenses = array_unique(array_column($vEntries, 'license_key'));
            $vTpMap    = [];
            if ($vLicenses) {
                $phLic  = implode(',', array_fill(0, count($vLicenses), '?'));
                $vtpStmt = $pdo->prepare("
                    SELECT person_license, slot, code
                    FROM transponders
                    WHERE competition_id = ? AND person_license IN ($phLic)
                    ORDER BY slot
                ");
                $vtpStmt->execute(array_merge([$compId], $vLicenses));
                foreach ($vtpStmt->fetchAll() as $tp) {
                    $vTpMap[$tp['person_license']][$tp['slot']] = $tp['code'];
                }
            }

            // Vorige ronde compleet?
            // Gebruik de daadwerkelijke hoogste ronde < $rn die entries heeft,
            // zodat categorieën zonder series (KF op ronde=1, HF op ronde=3)
            // ook correct werken (ronde 2 bestaat dan niet).
            $vrRondeStmt = $pdo->prepare("
                SELECT MAX(h.ronde)
                FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = ?
                  AND h.distance_combination_id = ?
                  AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? = ''))
                  AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
                  AND h.ronde < ?
            ");
            $vrRondeStmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup, $rn]);
            $vorigeRonde = (int)($vrRondeStmt->fetchColumn() ?: ($rn - 1));

            $vrStmt = $pdo->prepare("
                SELECT COUNT(he.id)                                          AS totaal,
                       SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END)  AS met_resultaat
                FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                LEFT JOIN results res ON res.heat_entry_id = he.id
                WHERE h.competition_id          = ?
                  AND h.distance_combination_id = ?
                  AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? = ''))
                  AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
                  AND h.ronde = ?
            ");
            $vrStmt->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup, $vorigeRonde]);
            $vrStat              = $vrStmt->fetch(PDO::FETCH_ASSOC);
            $vorigeRondeCompleet = $vrStat && (int)$vrStat['totaal'] > 0
                                   && (int)$vrStat['totaal'] === (int)$vrStat['met_resultaat'];

            // Heats opbouwen
            $vHeatMap = [];
            foreach ($rondeData['heatRows'] as $h) {
                $vHeatMap[$h['id']] = [
                    'nummer'       => (int)$h['heat_nr'],
                    'rit_volgorde' => (int)$h['rit_volgorde'],
                    'heat_naam'    => $h['heat_naam'],
                    'rijders'      => [],
                ];
            }
            foreach ($vEntries as $e) {
                $lk = $e['license_key'];
                $e['transponder_actief'] = $vTpMap[$lk][0] ?? null;
                $e['transponder1']       = $vTpMap[$lk][1] ?? null;
                $e['transponder2']       = $vTpMap[$lk][2] ?? null;
                if (isset($vHeatMap[$e['heat_id']])) {
                    $vHeatMap[$e['heat_id']]['rijders'][] = $e;
                }
            }

            $vHeats  = array_values($vHeatMap);
            $vTotaal = array_sum(array_map(fn($h) => count($h['rijders']), $vHeats));

            $volgRondes[] = [
                'ronde_nr'              => $rn,
                'ronde_type'            => $rondeData['ronde_type'],
                'methode'               => $rondeData['methode'],
                'vorige_ronde_compleet' => $vorigeRondeCompleet,
                'aantalHeats'           => count($vHeats),
                'totaalRijders'         => $vTotaal,
                'heats'                 => $vHeats,
            ];
        }
    }

    // Werkelijk ronde_type van ronde-1 heats (bijv. 'finale_a' als er geen series zijn)
    $werkelijkRondeType = $heatRows ? ($heatRows[0]['werkelijk_ronde_type'] ?? 'heats') : 'heats';

    echo json_encode([
        'exists'              => true,
        'methode'             => $methode,
        'gegenereerd_op'      => $gegenereerd,
        'aantalHeats'         => count($heats),
        'totaalRijders'       => $totaalRijders,
        'heats'               => $heats,
        'ronde_1_ronde_type'  => $werkelijkRondeType,
        'ronde_1_compleet'    => $ronde1Compleet,
        'volgende_rondes'     => $volgRondes,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
