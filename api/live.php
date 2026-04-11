<?php
// ============================================================
//  InlineComp – Live verwerking
//
//  GET  ?competition_id=X          → ritten + rijders + resultaten + catConfigs
//  POST action=save_rit_results    → tijden + sancties opslaan, finishposities berekenen
//  POST action=genereer_volgende_ronde → kwalificeerders doorsturen naar volgende ronde
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// ── Full-Final B-finale verdeling ─────────────────────────────────────────────
// Berekent de groottes van de B-finale heats op basis van het werkelijke aantal
// B-rijders, de maximale grootte per B-finale en de richting-vlag.
//
// Regels:
//  · Alle heats krijgen maximaal $bFinaleHg rijders.
//  · Als de laatste heat te klein zou worden (< $bFinaleHg), wordt die samengevoegd
//    met de aangrenzende heat.
//  · $bLaatstGrootst = true  → de LAATSTE B-finale is het grootst (standaard).
//  · $bLaatstGrootst = false → de EERSTE B-finale is het grootst.
//
// Voorbeeld: 15 rijders, bFinaleHg=4
//   ceil(15/4)=4, laatste rest=3 < 4 → samenvoegen → 3 heats
//   bLaatstGrootst=true  → [4, 4, 7]
//   bLaatstGrootst=false → [7, 4, 4]
function verdeelBFinales(int $n, int $bFinaleHg, bool $bLaatstGrootst): array {
    if ($n <= 0 || $bFinaleHg <= 0) return [];

    $nHeats = (int)ceil($n / $bFinaleHg);

    // Controleer of de laatste heat te klein is; zo ja: samenvoegen
    if ($nHeats > 1) {
        $remainder = $n - ($nHeats - 1) * $bFinaleHg;
        if ($remainder < $bFinaleHg) {
            $nHeats--;
        }
    }

    if ($nHeats <= 1) return [$n];

    // (nHeats-1) heats krijgen precies $bFinaleHg rijders; één heat krijgt de rest
    $special = $n - ($nHeats - 1) * $bFinaleHg;
    $result  = [];

    if ($bLaatstGrootst) {
        // Eerste heats: regulier; LAATSTE heat: groter
        for ($i = 0; $i < $nHeats - 1; $i++) $result[] = $bFinaleHg;
        $result[] = $special;
    } else {
        // EERSTE heat: groter; rest: regulier
        $result[] = $special;
        for ($i = 1; $i < $nHeats; $i++) $result[] = $bFinaleHg;
    }

    return $result;
}

// POST: schrijfrechten vereist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !kanSchrijven($_authUser, 'live')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor live verwerking.']);
    exit;
}

// ── GET: alle ritten + rijders + resultaten ophalen ───────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id is verplicht']);
        exit;
    }

    try {
        // Tijdschema ophalen
        $tsStmt = $pdo->prepare("SELECT id, systeem FROM competition_tijdschema WHERE competition_id = ?");
        $tsStmt->execute([$compId]);
        $ts = $tsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ts) {
            echo json_encode(['ritten' => [], 'catConfigs' => [], 'systeem' => null]);
            exit;
        }
        $tsId    = (int)$ts['id'];
        $systeem = $ts['systeem'] ?? null;

        // Alle ritten ophalen (met afstand/cat namen)
        // Subquery voor heat_id: voorkomt vermenigvuldiging als er meerdere heats
        // aan dezelfde tijdschema_rit gekoppeld zijn (ronde 1+2, split_groups, etc.)
        $rStmt = $pdo->prepare("
            SELECT
                r.id            AS rit_id,
                r.volgorde,
                r.rit_naam,
                r.ronde_type,
                r.heat_nr,
                r.dc_id,
                r.distance_id,
                r.dc_naam,
                r.tijdstip_override,
                r.afstand_naam,
                d.value_meters,
                (SELECT h.id FROM heats h
                 WHERE h.tijdschema_rit_id = r.id
                   AND h.competition_id = ?
                 ORDER BY h.ronde
                 LIMIT 1)       AS heat_id,
                (SELECT h.race_type FROM heats h
                 WHERE h.tijdschema_rit_id = r.id
                   AND h.competition_id = ?
                 ORDER BY h.ronde
                 LIMIT 1)       AS heat_race_type
            FROM tijdschema_ritten r
            LEFT JOIN distances d ON d.id = r.distance_id
                                 AND d.distance_combination_id = r.dc_id
            WHERE r.tijdschema_id = ?
            ORDER BY r.volgorde, r.id
        ");
        $rStmt->execute([$compId, $compId, $tsId]);
        $rittenRaw = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Per rit: rijders + resultaten + actieve transponder (slot 0) ophalen
        $rijderStmt = $pdo->prepare("
            SELECT
                he.id           AS entry_id,
                he.startpositie,
                he.startnummer,
                p.full_name,
                tp.code         AS transponder_actief,
                res.finishpositie,
                res.tijd_ms,
                res.rondes,
                res.punten,
                res.sanctie,
                res.notitie
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN transponders tp ON tp.person_license = he.person_license
                AND tp.competition_id = ?
                AND tp.slot = 0
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");

        $ritten = [];
        foreach ($rittenRaw as $rit) {
            $ritId = (int)$rit['rit_id'];
            $heatId = $rit['heat_id'] !== null ? (int)$rit['heat_id'] : null;

            $rijders = [];
            if ($heatId !== null) {
                $rijderStmt->execute([$compId, $heatId]);
                foreach ($rijderStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rijders[] = [
                        'entry_id'          => (int)$r['entry_id'],
                        'startpositie'      => (int)$r['startpositie'],
                        'startnummer'       => $r['startnummer'] !== null ? (int)$r['startnummer'] : null,
                        'full_name'         => $r['full_name'],
                        'transponder_actief'=> $r['transponder_actief'],
                        'finishpositie'     => $r['finishpositie'] !== null ? (int)$r['finishpositie'] : null,
                        'tijd_ms'           => $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                        'rondes'            => $r['rondes'] !== null ? (int)$r['rondes'] : null,
                        'punten'            => $r['punten'] !== null ? (float)$r['punten'] : null,
                        'sanctie'           => $r['sanctie'],
                        'notitie'           => $r['notitie'] ?? '',
                    ];
                }
            }

            $ritten[] = [
                'rit_id'           => $ritId,
                'volgorde'         => (int)$rit['volgorde'],
                'rit_naam'         => $rit['rit_naam'],
                'ronde_type'       => $rit['ronde_type'],
                'heat_nr'          => $rit['heat_nr'] !== null ? (int)$rit['heat_nr'] : null,
                'dc_id'            => $rit['dc_id'],
                'distance_id'      => $rit['distance_id'],
                'afstand_naam'     => $rit['afstand_naam'],
                'distance_meters'  => $rit['value_meters'] !== null ? (int)$rit['value_meters'] : null,
                'dc_naam'          => $rit['dc_naam'],
                'tijdstip'         => $rit['tijdstip_override'],
                'heat_id'          => $heatId,
                'race_type'        => $rit['heat_race_type'] ?? 'inline',
                'rijders'          => $rijders,
            ];
        }

        // catConfigs ophalen
        $ccStmt = $pdo->prepare("SELECT * FROM tijdschema_cat_config WHERE tijdschema_id = ?");
        $ccStmt->execute([$tsId]);
        $catConfigs = [];
        foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
            $key = ($cc['dc_id'] ?? '') . '|' . ($cc['distance_id'] ?? '');
            $catConfigs[$key] = [
                'heats_q'           => (int)($cc['heats_q']           ?? 0),
                'heats_aantal'      => (int)($cc['heats_aantal']       ?? 0),
                'half_heats'        => (int)($cc['half_heats']         ?? 0),
                'half_door'         => (int)($cc['half_door']          ?? 0),
                'kwart_heats'       => (int)($cc['kwart_heats']        ?? 0),
                'kwart_door'        => (int)($cc['kwart_door']         ?? 0),
                'heeft_heats'       => (bool)($cc['heeft_heats']       ?? false),
                'heeft_kwartfinale' => (bool)($cc['heeft_kwartfinale'] ?? false),
                'heeft_halve_finale'=> (bool)($cc['heeft_halve_finale']?? false),
                'heats_q_heat'      => (int)($cc['heats_q_heat']       ?? 0),
                'kwart_q_heat'      => (int)($cc['kwart_q_heat']       ?? 1),
                'half_q_heat'       => (int)($cc['half_q_heat']        ?? 1),
            ];
        }

        echo json_encode([
            'ritten'     => $ritten,
            'catConfigs' => $catConfigs,
            'systeem'    => $systeem,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldige JSON body']);
    exit;
}

$action = $body['action'] ?? '';

// ── save_rit_results ──────────────────────────────────────────────────────────

if ($action === 'save_rit_results') {
    $compId  = trim($body['competition_id'] ?? '');
    $ritId   = (int)($body['rit_id'] ?? 0);
    $results = $body['results'] ?? [];

    if (!$compId || !$ritId) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id en rit_id zijn verplicht']);
        exit;
    }

    try {
        // Geldige sancties (UI → DB mapping)
        $sanctieMap = [
            'DNS'   => 'DNS',
            'DNF'   => 'DNF',
            'DQ-SF' => 'DSQ-SF',
            'DQ-DF' => 'DSQ-TF',
            'FS'    => 'FS1',
            // Directe DB-waarden ook accepteren
            'DSQ-SF'=> 'DSQ-SF',
            'DSQ-TF'=> 'DSQ-TF',
            'FS1'   => 'FS1',
            'W1'    => 'W1',
            'W2'    => 'W2',
            'DC'    => 'DC',
            'RR'    => 'RR',
        ];

        // Finishpositie berekenen
        //   FS1       → normale positie op basis van tijd (tijd wordt BEWAARD)
        //   DNF/DSQ-SF → ex-aequo gedeeld laatste = N + 1
        //   DNS/DSQ-TF → géén positie (niet in uitslag)
        //   Overige sancties → géén positie
        $GEDEELD_LAATSTE = ['DNF', 'DSQ-SF'];
        $GEEN_UITSLAG    = ['DNS', 'DSQ-TF'];

        $metTijd    = [];   // normale finishers + FS rijders (positie op tijd)
        $gedeeldArr = [];   // DNF / DQ-SF (gedeeld laatste)
        $zonderTijd = [];   // DNS / DQ-DF / leeg (geen positie)

        foreach ($results as $r) {
            $tijdMs    = isset($r['tijd_ms']) && $r['tijd_ms'] !== null && $r['tijd_ms'] !== ''
                         ? (int)$r['tijd_ms'] : null;
            $sanctieUi = trim($r['sanctie'] ?? '');
            $dbSanctie = isset($sanctieMap[$sanctieUi]) ? $sanctieMap[$sanctieUi] : null;

            $rondes = isset($r['rondes']) && $r['rondes'] !== '' && $r['rondes'] !== null
                      ? (int)$r['rondes'] : null;

            if ($dbSanctie && in_array($dbSanctie, $GEDEELD_LAATSTE, true)) {
                // DNF / DQ-SF: ex-aequo laatste, tijd wissen
                $gedeeldArr[] = ['entry_id' => (int)$r['entry_id'], 'tijd_ms' => null,   'rondes' => $rondes, 'sanctie' => $dbSanctie, 'notitie' => $r['notitie'] ?? ''];
            } elseif ($dbSanctie && in_array($dbSanctie, $GEEN_UITSLAG, true)) {
                // DNS / DQ-DF: geen positie, geen tijd
                $zonderTijd[] = ['entry_id' => (int)$r['entry_id'], 'tijd_ms' => null,   'rondes' => $rondes, 'sanctie' => $dbSanctie, 'notitie' => $r['notitie'] ?? ''];
            } elseif ($dbSanctie === 'FS1') {
                // FS: waarschuwing; tijd bewaren en normale positie toekennen
                if ($tijdMs !== null && $tijdMs > 0) {
                    $metTijd[]    = ['entry_id' => (int)$r['entry_id'], 'tijd_ms' => $tijdMs, 'rondes' => $rondes, 'sanctie' => 'FS1',      'notitie' => $r['notitie'] ?? ''];
                } else {
                    $zonderTijd[] = ['entry_id' => (int)$r['entry_id'], 'tijd_ms' => null,   'rondes' => $rondes, 'sanctie' => 'FS1',      'notitie' => $r['notitie'] ?? ''];
                }
            } elseif ($tijdMs !== null && $tijdMs > 0) {
                // Normale finisher
                $metTijd[]    = ['entry_id' => (int)$r['entry_id'], 'tijd_ms' => $tijdMs, 'rondes' => $rondes, 'sanctie' => $dbSanctie, 'notitie' => $r['notitie'] ?? ''];
            } else {
                // Geen tijd, geen geldige sanctie: wis resultaat
                $zonderTijd[] = ['entry_id' => (int)$r['entry_id'], 'tijd_ms' => null,   'rondes' => $rondes, 'sanctie' => null,       'notitie' => $r['notitie'] ?? ''];
            }
        }

        // Detecteer race_type (puntenkoers?) en laad punten indien nodig
        $heatRaceType  = '';
        $heatPuntenMap = [];
        $heatLookupStmt = $pdo->prepare("
            SELECT h.race_type, h.id AS heat_id
            FROM tijdschema_ritten r
            JOIN heats h ON h.tijdschema_rit_id = r.id
            WHERE r.id = ?
            LIMIT 1
        ");
        $heatLookupStmt->execute([$ritId]);
        $heatLookupRow = $heatLookupStmt->fetch(PDO::FETCH_ASSOC);
        if ($heatLookupRow) {
            $heatRaceType = $heatLookupRow['race_type'] ?? '';
            if ($heatRaceType === 'puntenkoers') {
                // Laad bestaande punten (opgeslagen via save_punten_koers)
                $puntenLookupStmt = $pdo->prepare("
                    SELECT he.id AS entry_id, COALESCE(res.punten, 0) AS punten
                    FROM heat_entries he
                    LEFT JOIN results res ON res.heat_entry_id = he.id
                    WHERE he.heat_id = ?
                ");
                $puntenLookupStmt->execute([$heatLookupRow['heat_id']]);
                foreach ($puntenLookupStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $heatPuntenMap[(int)$p['entry_id']] = (float)$p['punten'];
                }
            }
        }

        // Sorteer finishers: PK → punten DESC → rondes DESC → tijd ASC; anders → rondes DESC → tijd ASC
        // null rondes = PHP_INT_MAX: rijder zonder geregistreerde rondes staat boven rijder met weinig rondes
        if ($heatRaceType === 'puntenkoers') {
            usort($metTijd, function ($a, $b) use ($heatPuntenMap) {
                $pA = $heatPuntenMap[$a['entry_id']] ?? 0;
                $pB = $heatPuntenMap[$b['entry_id']] ?? 0;
                if ($pA != $pB) return $pB <=> $pA;           // 1. punten DESC
                $rA = $a['rondes'] !== null ? (int)$a['rondes'] : PHP_INT_MAX;
                $rB = $b['rondes'] !== null ? (int)$b['rondes'] : PHP_INT_MAX;
                if ($rA !== $rB) return $rA > $rB ? -1 : 1;   // 2. rondes DESC (null = best)
                return $a['tijd_ms'] <=> $b['tijd_ms'];        // 3. tijd ASC
            });
        } else {
            usort($metTijd, function ($a, $b) {
                if ($a['rondes'] !== null || $b['rondes'] !== null) {
                    $rA = $a['rondes'] !== null ? (int)$a['rondes'] : PHP_INT_MAX;
                    $rB = $b['rondes'] !== null ? (int)$b['rondes'] : PHP_INT_MAX;
                    if ($rA !== $rB) return $rA > $rB ? -1 : 1; // rondes DESC (null = best)
                }
                return $a['tijd_ms'] - $b['tijd_ms'];
            });
        }

        // Finishposities toekennen
        $alleResultaten = [];
        $n = count($metTijd);
        foreach ($metTijd as $pos => $r) {
            $r['finishpositie'] = $pos + 1;
            $alleResultaten[] = $r;
        }
        // DNF / DQ-SF → gedeeld laatste = N + 1
        $gedeeldPos = $n + 1;
        foreach ($gedeeldArr as $r) {
            $r['finishpositie'] = count($gedeeldArr) > 0 ? $gedeeldPos : null;
            $alleResultaten[] = $r;
        }
        // DNS / DQ-DF / leeg → geen positie
        foreach ($zonderTijd as $r) {
            $r['finishpositie'] = null;
            $alleResultaten[] = $r;
        }

        // Opslaan in DB
        $pdo->beginTransaction();

        $upsert = $pdo->prepare("
            INSERT INTO results (heat_entry_id, finishpositie, tijd_ms, rondes, sanctie, notitie)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                finishpositie = VALUES(finishpositie),
                tijd_ms       = VALUES(tijd_ms),
                rondes        = VALUES(rondes),
                sanctie       = VALUES(sanctie),
                notitie       = VALUES(notitie)
        ");

        foreach ($alleResultaten as $r) {
            $upsert->execute([
                $r['entry_id'],
                $r['finishpositie'],
                $r['tijd_ms'],
                $r['rondes'],
                $r['sanctie'],
                $r['notitie'],
            ]);
        }

        $pdo->commit();

        // Ronde-status teruggeven: hoeveel ritten zijn compleet voor elke dc+distance in deze ronde?
        // Haal de rit-info op voor dc_id en ronde_type
        $ritInfoStmt = $pdo->prepare("
            SELECT dc_id, distance_id, ronde_type
            FROM tijdschema_ritten
            WHERE id = ?
        ");
        $ritInfoStmt->execute([$ritId]);
        $ritInfo = $ritInfoStmt->fetch(PDO::FETCH_ASSOC);

        $rondeStatus = [];
        if ($ritInfo) {
            $tsStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ?");
            $tsStmt->execute([$compId]);
            $ts = $tsStmt->fetch(PDO::FETCH_ASSOC);

            if ($ts) {
                // Haal alle ritten op voor deze dc+distance+ronde_type
                $rondeRittenStmt = $pdo->prepare("
                    SELECT r.id AS rit_id
                    FROM tijdschema_ritten r
                    WHERE r.tijdschema_id = ?
                      AND r.dc_id = ?
                      AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? IS NULL))
                      AND r.ronde_type = ?
                ");
                $rondeRittenStmt->execute([
                    (int)$ts['id'],
                    $ritInfo['dc_id'],
                    $ritInfo['distance_id'],
                    $ritInfo['distance_id'],
                    $ritInfo['ronde_type'],
                ]);
                $rondeRitten = $rondeRittenStmt->fetchAll(PDO::FETCH_COLUMN);

                $totaal   = count($rondeRitten);
                $compleet = 0;
                foreach ($rondeRitten as $rId) {
                    // Een rit is compleet als alle rijders in de bijbehorende heat een resultaat hebben
                    $compleetStmt = $pdo->prepare("
                        SELECT
                            COUNT(he.id) AS totaal_rijders,
                            SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS met_resultaat
                        FROM heats h
                        JOIN heat_entries he ON he.heat_id = h.id
                        LEFT JOIN results res ON res.heat_entry_id = he.id
                        WHERE h.tijdschema_rit_id = ?
                          AND h.competition_id = ?
                    ");
                    $compleetStmt->execute([$rId, $compId]);
                    $stat = $compleetStmt->fetch(PDO::FETCH_ASSOC);
                    if ($stat && (int)$stat['totaal_rijders'] > 0 && (int)$stat['totaal_rijders'] === (int)$stat['met_resultaat']) {
                        $compleet++;
                    }
                }

                $key = ($ritInfo['dc_id'] ?? '') . '|' . ($ritInfo['distance_id'] ?? '');
                $rondeStatus[$key] = [
                    'ronde_type' => $ritInfo['ronde_type'],
                    'totaal'     => $totaal,
                    'compleet'   => $compleet,
                ];
            }
        }

        // Geef berekende finishposities terug zodat JS de lokale state correct kan bijwerken
        $finishPosMap = [];
        foreach ($alleResultaten as $r) {
            if ($r['finishpositie'] !== null) {
                $finishPosMap[$r['entry_id']] = $r['finishpositie'];
            }
        }

        echo json_encode(['ok' => true, 'ronde_status' => $rondeStatus, 'finishposities' => $finishPosMap], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── genereer_volgende_ronde ───────────────────────────────────────────────────

if ($action === 'genereer_volgende_ronde') {
    $compId       = trim($body['competition_id'] ?? '');
    $dcId         = trim($body['dc_id']          ?? '');
    $distanceId   = trim($body['distance_id']    ?? '');
    $vanRondeType = trim($body['van_ronde_type'] ?? '');
    $naarRondeType= trim($body['naar_ronde_type']?? '');

    if (!$compId || !$dcId || !$vanRondeType || !$naarRondeType) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id, dc_id, van_ronde_type en naar_ronde_type zijn verplicht']);
        exit;
    }

    try {
        // Tijdschema ophalen
        $tsStmt = $pdo->prepare("SELECT id, systeem FROM competition_tijdschema WHERE competition_id = ?");
        $tsStmt->execute([$compId]);
        $ts = $tsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ts) {
            http_response_code(404);
            echo json_encode(['error' => 'Geen tijdschema gevonden voor deze wedstrijd']);
            exit;
        }
        $tsId    = (int)$ts['id'];
        $systeem = $ts['systeem'] ?? 'internationaal-nieuw';

        // Full-final vlag: als systeem=full-final en we gaan naar finale_a, moeten
        // ook de B-finales worden aangemaakt op basis van finale_heat_grootte.
        $isFullFinal = ($systeem === 'full-final') && ($naarRondeType === 'finale_a');

        // Haal afstand-config op (nodig voor finaleHg, B-verdeling en seeding-methode)
        $finaleHg       = 6;
        $bFinaleHg      = 6;
        $bLaatstGrootst = true;
        $finaleSeeding  = 'slang';
        $bSlots         = [];

        // Zoek afstand_naam via een bestaande tijdschema_rit voor deze cat
        $afNaamStmt = $pdo->prepare("
            SELECT afstand_naam FROM tijdschema_ritten
            WHERE tijdschema_id = ? AND dc_id = ?
              AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
            LIMIT 1
        ");
        $afNaamStmt->execute([$tsId, $dcId, $distanceId, $distanceId]);
        $afstandNaam = $afNaamStmt->fetchColumn();

        if ($afstandNaam) {
            $afCfgStmt = $pdo->prepare("
                SELECT finale_heat_grootte, finale_b_grootte, laatste_b_grootste, finale_seeding
                FROM tijdschema_afstand_config
                WHERE tijdschema_id = ? AND afstand_naam = ?
                LIMIT 1
            ");
            $afCfgStmt->execute([$tsId, $afstandNaam]);
            $afCfg = $afCfgStmt->fetch(PDO::FETCH_ASSOC);
            if ($afCfg) {
                $finaleHg       = max(1, (int)($afCfg['finale_heat_grootte'] ?? 6));
                $bFinaleHgRaw   = max(1, (int)($afCfg['finale_b_grootte']    ?? 6));
                $bFinaleHg      = max($finaleHg, $bFinaleHgRaw);
                $bLaatstGrootst = !empty($afCfg['laatste_b_grootste']);
                $finaleSeeding  = $afCfg['finale_seeding'] ?? 'slang';
            }
        }

        // catConfig ophalen
        $ccStmt = $pdo->prepare("
            SELECT * FROM tijdschema_cat_config
            WHERE tijdschema_id = ?
              AND dc_id = ?
              AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
            LIMIT 1
        ");
        $ccStmt->execute([$tsId, $dcId, $distanceId, $distanceId]);
        $cc = $ccStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cc) {
            http_response_code(404);
            echo json_encode(['error' => 'Geen cat-config gevonden']);
            exit;
        }

        // Bepaal hoeveel rijders door mogen
        $aantalDoor = 0;
        if ($vanRondeType === 'heats') {
            $aantalDoor = (int)($cc['heats_q'] ?? 0);
        } elseif ($vanRondeType === 'kwartfinale') {
            $aantalDoor = (int)($cc['kwart_door'] ?? 0);
        } elseif ($vanRondeType === 'halve_finale') {
            $aantalDoor = (int)($cc['half_door'] ?? 0);
        }

        // Voor full-final: A-finale krijgt max $finaleHg rijders; de rest gaat naar B-finales.
        // heats_q is voor full-final gelijk aan cat.n (iedereen), maar dat klopt niet voor A-finale.
        if ($isFullFinal) {
            $aantalDoor = $finaleHg;
        }

        if ($aantalDoor <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Aantal door is 0 of niet ingesteld in cat-config']);
            exit;
        }

        // ── Bepaal Q/q seeding parameters ────────────────────────────────────────
        // qPerHeat = aantal positie-qualifiers per bron-heat
        //   heats       → volgende : 0  (puur tijdsortering)
        //   kwartfinale → volgende : kwart_q_heat (default 1)
        //   halve_finale→ volgende : half_q_heat  (default 1)
        $qPerHeat = 0;
        if ($vanRondeType === 'kwartfinale') {
            $qPerHeat = (int)($cc['kwart_q_heat'] ?? 1);
        } elseif ($vanRondeType === 'halve_finale') {
            $qPerHeat = (int)($cc['half_q_heat'] ?? 1);
        }

        // Haal geconfigureerd aantal bron-heats op (niet alleen al gespeelde)
        $bronRittenStmt = $pdo->prepare("
            SELECT heat_nr
            FROM tijdschema_ritten
            WHERE tijdschema_id = ?
              AND dc_id = ?
              AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
              AND ronde_type = ?
            ORDER BY heat_nr
        ");
        $bronRittenStmt->execute([$tsId, $dcId, $distanceId, $distanceId, $vanRondeType]);
        $bronHeatNrs = $bronRittenStmt->fetchAll(PDO::FETCH_COLUMN);
        $nBronHeats  = max(1, count($bronHeatNrs));

        // ── Alle resultaten van de van_ronde_type ophalen (incl. heat_nr) ───────
        // Alleen rijders die daadwerkelijk een resultaat hebben (INNER JOIN):
        // rijders uit nog niet gespeelde heats hebben geen results-rij en vallen weg.
        $resStmt = $pdo->prepare("
            SELECT
                he.id           AS entry_id,
                he.person_license,
                he.categorie,
                he.startnummer,
                h.heat_nr,
                p.full_name,
                p.club_short,
                res.tijd_ms,
                res.finishpositie,
                res.sanctie
            FROM tijdschema_ritten r
            JOIN heats h ON h.tijdschema_rit_id = r.id
              AND h.competition_id = ?
            JOIN heat_entries he ON he.heat_id = h.id
            JOIN persons p ON p.license_key = he.person_license
            JOIN results res ON res.heat_entry_id = he.id
            WHERE r.tijdschema_id = ?
              AND r.dc_id = ?
              AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? = ''))
              AND r.ronde_type = ?
        ");
        $resStmt->execute([
            $compId, $tsId, $dcId,
            $distanceId, $distanceId,
            $vanRondeType
        ]);
        $alleRijders = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter uitgevallen rijders
        $sanctiesUit = ['DNS', 'DNF', 'DSQ-SF', 'DSQ-TF', 'DC', 'RR'];
        $beschikbaar = [];
        foreach ($alleRijders as $r) {
            if (in_array($r['sanctie'] ?? '', $sanctiesUit, true)) continue;
            $beschikbaar[] = $r;
        }

        // ── Bouw slot-lijst op (Q-slots + q-slots) ───────────────────────────
        // Sommige slots kunnen null zijn (heat nog niet gespeeld → positie gereserveerd).
        // $overflowRijders: extra rijders door ex-aequo op de kwalificatiegrens.
        // Ze worden NIET snake-geseed maar toegevoegd aan heat 1, 2, … in volgorde.
        $overflowRijders = [];

        if ($qPerHeat > 0) {
            // Groepeer per heat_nr, sorteer op finishpositie
            $byHeat = [];
            foreach ($beschikbaar as $r) {
                $byHeat[(int)$r['heat_nr']][] = $r;
            }
            foreach ($byHeat as $hn => &$riders) {
                usort($riders, fn($a, $b) =>
                    ($a['finishpositie'] === null ? PHP_INT_MAX : (int)$a['finishpositie'])
                    - ($b['finishpositie'] === null ? PHP_INT_MAX : (int)$b['finishpositie'])
                );
            }
            unset($riders);

            // Q-slots: rank1-heat1, rank1-heat2, …, rank2-heat1, rank2-heat2, …
            // Gebruikt $nBronHeats zodat posities gereserveerd blijven voor nog niet gespeelde heats
            $qSlots = [];
            for ($rank = 1; $rank <= $qPerHeat; $rank++) {
                foreach ($bronHeatNrs as $hn) {
                    $qSlots[] = $byHeat[(int)$hn][$rank - 1] ?? null;
                }
            }

            // Ex-aequo Q: check of de laatste Q-rijder van elke heat dezelfde tijd heeft
            // als de eerstvolgende niet-Q rijder → die gaat ook door (overflow)
            foreach ($bronHeatNrs as $hn) {
                $hRijders = $byHeat[(int)$hn] ?? [];
                $grensRijder = $hRijders[$qPerHeat - 1] ?? null;
                if ($grensRijder === null || $grensRijder['tijd_ms'] === null) continue;
                $grenstijd = (int)$grensRijder['tijd_ms'];
                for ($i = $qPerHeat; $i < count($hRijders); $i++) {
                    if ($hRijders[$i]['tijd_ms'] !== null
                            && (int)$hRijders[$i]['tijd_ms'] === $grenstijd) {
                        $overflowRijders[] = $hRijders[$i];
                    } else {
                        break; // gesorteerd, dus zodra tijd afwijkt: stoppen
                    }
                }
            }

            // q-pool: resterende rijders (na Q-slots), gesorteerd op tijd
            $qPool = [];
            foreach ($byHeat as $hn => $riders) {
                foreach (array_slice($riders, $qPerHeat) as $rider) {
                    if ($rider['tijd_ms'] !== null) $qPool[] = $rider;
                }
            }
            // Verwijder overflow-rijders die al in $overflowRijders zitten uit $qPool
            $overflowIds = array_column($overflowRijders, 'entry_id');
            $qPool = array_values(array_filter($qPool,
                fn($r) => !in_array($r['entry_id'], $overflowIds, true)
            ));
            usort($qPool, fn($a, $b) => (int)$a['tijd_ms'] - (int)$b['tijd_ms']);

            // Aantal q-slots (tijdkwalificaties) = totaal_door - Q-slots
            $nQSlots = $qPerHeat * $nBronHeats;
            $nqSlots = max(0, $aantalDoor - $nQSlots);

            // Ex-aequo q: check grens tijdpool
            if ($nqSlots > 0 && isset($qPool[$nqSlots - 1], $qPool[$nqSlots])) {
                $grenstijd = (int)$qPool[$nqSlots - 1]['tijd_ms'];
                for ($i = $nqSlots; $i < count($qPool); $i++) {
                    if ((int)$qPool[$i]['tijd_ms'] === $grenstijd) {
                        $overflowRijders[] = $qPool[$i];
                    } else {
                        break;
                    }
                }
            }

            // Volledige slot-lijst (zonder overflow – die komen er apart achteraan)
            $allSlots = $qSlots;
            for ($i = 0; $i < $nqSlots; $i++) {
                $allSlots[] = $qPool[$i] ?? null;
            }
        } else {
            // Puur tijdsortering (heats → kwartfinale / runner_up)
            $metTijd    = array_values(array_filter($beschikbaar, fn($r) => $r['tijd_ms'] !== null));
            $zonderTijd = array_values(array_filter($beschikbaar, fn($r) => $r['tijd_ms'] === null));
            usort($metTijd, fn($a, $b) => (int)$a['tijd_ms'] - (int)$b['tijd_ms']);

            // Ex-aequo: check grens bij $aantalDoor
            if ($aantalDoor > 0 && isset($metTijd[$aantalDoor - 1], $metTijd[$aantalDoor])) {
                $grenstijd = (int)$metTijd[$aantalDoor - 1]['tijd_ms'];
                for ($i = $aantalDoor; $i < count($metTijd); $i++) {
                    if ((int)$metTijd[$i]['tijd_ms'] === $grenstijd) {
                        $overflowRijders[] = $metTijd[$i];
                    } else {
                        break;
                    }
                }
            }

            $alleGesorteerd = array_merge($metTijd, $zonderTijd);
            $allSlots = array_slice($alleGesorteerd, 0, $aantalDoor);

            // Full-final: riders die niet in A-finale passen → B-finales
            if ($isFullFinal) {
                $bSlots = array_slice($alleGesorteerd, $aantalDoor);

                // Ex-aequo aan A-finale grens: als de laatste A-finalist dezelfde tijd
                // heeft als de eerste B-rijder(s), schuiven die mee naar de A-finale.
                if (!empty($allSlots) && !empty($bSlots)) {
                    $lastATime = $allSlots[count($allSlots) - 1]['tijd_ms'];
                    if ($lastATime !== null) {
                        $extraA = 0;
                        foreach ($bSlots as $bRijder) {
                            if ($bRijder['tijd_ms'] !== null && (int)$bRijder['tijd_ms'] === (int)$lastATime) {
                                $allSlots[] = $bRijder;
                                $extraA++;
                            } else {
                                break;
                            }
                        }
                        if ($extraA > 0) {
                            $bSlots = array_slice($bSlots, $extraA);
                        }
                    }
                }
            }
        }

        // ── Volgende-ronde ritten ophalen ─────────────────────────────────────
        $volgendeRittenStmt = $pdo->prepare("
            SELECT r.id, r.heat_nr, r.volgorde, r.rit_naam, r.dc_naam, r.distance_id
            FROM tijdschema_ritten r
            WHERE r.tijdschema_id = ?
              AND r.dc_id = ?
              AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? = ''))
              AND r.ronde_type = ?
            ORDER BY r.heat_nr, r.volgorde
        ");
        $volgendeRittenStmt->execute([$tsId, $dcId, $distanceId, $distanceId, $naarRondeType]);
        $volgendeRitten = $volgendeRittenStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($volgendeRitten)) {
            // Geen volgende ronde geconfigureerd in tijdschema – geen fout, gewoon overslaan
            echo json_encode(['ok' => false, 'geen_ritten' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Bepaal ronde-nummer
        $rondeNrMap = [
            'heats'        => 1,
            'kwartfinale'  => 2,
            'halve_finale' => 3,
            'finale_a'     => 4,
            'finale_b'     => 4,
            'finale'       => 4,   // fallback voor oude data
            'runner_up'    => 4,
        ];
        $rondeNr = $rondeNrMap[$naarRondeType] ?? 2;

        // ── Verwijder bestaande heats voor deze volgende ronde ──────────────
        // Directe delete op competition + dc + distance + ronde zodat ook
        // restanten van eerdere pogingen worden opgeruimd, ook als de
        // tijdschema_rit_id verwijst naar inmiddels verwijderde ritten
        // (bijv. na regeneratie van het tijdschema).
        // ON DELETE CASCADE verwijdert heat_entries en results automatisch.
        $pdo->beginTransaction();

        // Delete via tijdschema_rit koppeling (vangt ook heats met ronde=1
        // die direct als finale zijn gegenereerd via startlijst_genereer.php).
        $delTypes = ['finale_a', 'finale_b'];
        foreach ($delTypes as $delType) {
            $delIds = $pdo->prepare("
                SELECT h.id, h.tijdschema_rit_id FROM heats h
                JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                WHERE h.competition_id          = ?
                  AND h.distance_combination_id = ?
                  AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? = ''))
                  AND r.ronde_type = ?
            ");
            $delIds->execute([$compId, $dcId, $distanceId, $distanceId, $delType]);
            $rows = $delIds->fetchAll(PDO::FETCH_ASSOC);
            $ids    = array_column($rows, 'id');
            $ritIds = array_filter(array_column($rows, 'tijdschema_rit_id'));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM heats WHERE id IN ($ph)")->execute($ids);
            }
            // Verwijder ook extra tijdschema_ritten die door ex-aequo overflow zijn aangemaakt
            if ($ritIds) {
                $ph2 = implode(',', array_fill(0, count($ritIds), '?'));
                $pdo->prepare("
                    DELETE FROM tijdschema_ritten
                    WHERE id IN ($ph2) AND rit_naam LIKE '%ex-aequo%'
                ")->execute($ritIds);
            }
        }

        // Brede cleanup: verwijder ALLE verweesd ex-aequo heats en ritten voor deze DC
        $pdo->prepare("
            DELETE FROM heats
            WHERE competition_id = ? AND distance_combination_id = ?
              AND (heat_naam LIKE '%ex-aequo%' OR heat_naam LIKE '%extra%' OR heat_nr <= 0)
        ")->execute([$compId, $dcId]);

        $tsIdStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ?");
        $tsIdStmt->execute([$compId]);
        $cleanTsId = $tsIdStmt->fetchColumn();
        if ($cleanTsId) {
            $pdo->prepare("
                DELETE FROM tijdschema_ritten
                WHERE tijdschema_id = ? AND dc_id = ?
                  AND (rit_naam LIKE '%ex-aequo%' OR rit_naam LIKE '%extra%' OR heat_nr <= 0)
            ")->execute([$cleanTsId, $dcId]);
        }
        // Fallback: ook heats zonder rit-koppeling op ronde=N opruimen
        $pdo->prepare("
            DELETE FROM heats
            WHERE competition_id          = ?
              AND distance_combination_id = ?
              AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
              AND ronde = ?
              AND tijdschema_rit_id IS NULL
        ")->execute([$compId, $dcId, $distanceId, $distanceId, $rondeNr]);

        // ── Maak nieuwe heats aan ─────────────────────────────────────────────
        $insHeat = $pdo->prepare("
            INSERT INTO heats
                (competition_id, distance_combination_id, distance_id,
                 ronde, tijdschema_rit_id, rit_volgorde,
                 heat_naam, heat_nr, methode, dc_ids)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'kwalificatie', ?)
        ");
        $insEntry = $pdo->prepare("
            INSERT INTO heat_entries (heat_id, person_license, categorie, startpositie, startnummer)
            VALUES (?, ?, ?, ?, ?)
        ");

        $dcIdsJson = json_encode([$dcId]);
        $heatIds   = [];
        foreach ($volgendeRitten as $rit) {
            $insHeat->execute([
                $compId,
                $dcId,
                $distanceId ?: null,
                $rondeNr,
                (int)$rit['id'],
                (int)$rit['volgorde'],
                $rit['rit_naam'],
                (int)$rit['heat_nr'],
                $dcIdsJson,
            ]);
            $heatIds[(int)$rit['heat_nr']] = [
                'id'       => (int)$pdo->lastInsertId(),
                'rit_naam' => $rit['rit_naam'],
                'rijders'  => [],
            ];
        }

        // ── Multi-finale overflow: merge + herverdeel VÓÓR de normale seeding ──
        $isMultiFinale = !$isFullFinal && ($naarRondeType === 'finale_a') && (count($heatIds) > 1) && !empty($overflowRijders);
        if ($isMultiFinale) {
            $allSlots = array_merge($allSlots, $overflowRijders);
            $overflowRijders = []; // verwerkt

            $aantalSlots = count($allSlots);
            $heatNummers = array_keys($heatIds);
            sort($heatNummers);
            $origPerHeat = max(1, (int)round(($aantalSlots - count($overflowRijders)) / max(1, count($heatNummers))));
            $neededHeats = (int)ceil($aantalSlots / max(1, $origPerHeat ?: 2));

            // Extra heats aanmaken als nodig
            while (count($heatIds) < $neededHeats) {
                // Bij tijdkoppeling: extra heat vóór de rest (laag heat_nr → langzaamsten)
                if ($finaleSeeding === 'tijdkoppeling') {
                    $extraHeatNr = min(array_keys($heatIds)) - 1;
                } else {
                    $extraHeatNr = max(array_keys($heatIds)) + 1;
                }
                $afNaam = $refRit['afstand_naam'] ?? ($volgendeRitten[0]['afstand_naam'] ?? '');
                $dcNaamExtra = $volgendeRitten[0]['dc_naam'] ?? '';
                $extraNaam = "A-finale heat ex-aequo (extra) {$afNaam} – {$dcNaamExtra}";
                $extraVerwacht = max(1, (int)ceil(($aantalSlots - $origPerHeat * count($heatIds)) / max(1, $neededHeats - count($heatIds))));

                $refRitStmt = $pdo->prepare("
                    SELECT r.id, r.blok_id, r.volgorde, r.tijdschema_id, r.afstand_naam
                    FROM tijdschema_ritten r
                    JOIN competition_tijdschema ct ON ct.id = r.tijdschema_id
                    WHERE ct.competition_id = ? AND r.dc_id = ? AND r.ronde_type = 'finale_a'
                    ORDER BY r.volgorde ASC LIMIT 1
                ");
                $refRitStmt->execute([$compId, $dcId]);
                $refRit = $refRitStmt->fetch(PDO::FETCH_ASSOC);
                $ritVolgorde = $refRit ? (int)$refRit['volgorde'] : 0;

                // Schuif ALLE ritten in het tijdschema op die op of na de insert-positie komen
                if ($refRit) {
                    $pdo->prepare("
                        UPDATE tijdschema_ritten
                        SET volgorde = volgorde + 1
                        WHERE tijdschema_id = ?
                          AND volgorde >= ?
                    ")->execute([$refRit['tijdschema_id'], $ritVolgorde]);

                    // Sync heats.rit_volgorde met de nieuwe tijdschema_ritten.volgorde
                    $pdo->prepare("
                        UPDATE heats h
                        JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                        SET h.rit_volgorde = r.volgorde
                        WHERE h.competition_id = ?
                    ")->execute([$compId]);
                }

                $extraRitId = null;
                if ($refRit) {
                    $pdo->prepare("
                        INSERT INTO tijdschema_ritten
                            (tijdschema_id, blok_id, volgorde, dc_id, distance_id,
                             afstand_naam, ronde_type, heat_nr, rit_naam, dc_naam, verwacht)
                        VALUES (?, ?, ?, ?, ?, ?, 'finale_a', ?, ?, ?, ?)
                    ")->execute([
                        $refRit['tijdschema_id'], $refRit['blok_id'], $ritVolgorde,
                        $dcId, $distanceId ?: null,
                        $afNaam, $extraHeatNr, $extraNaam,
                        $dcNaamExtra, $extraVerwacht,
                    ]);
                    $extraRitId = (int)$pdo->lastInsertId();
                }
                $insHeat->execute([
                    $compId, $dcId, $distanceId ?: null,
                    $rondeNr, $extraRitId, $ritVolgorde,
                    $extraNaam, $extraHeatNr, $dcIdsJson,
                ]);
                $heatIds[$extraHeatNr] = [
                    'id' => (int)$pdo->lastInsertId(), 'rit_naam' => $extraNaam, 'rijders' => [],
                ];
            }
        }

        // ── Seed alle slots naar dest-heats ──────────────────────────────────
        $heatNummers     = array_keys($heatIds);
        sort($heatNummers);
        $nDest           = count($heatNummers);
        $aantalSlots     = count($allSlots);

        $seq = [];
        if ($finaleSeeding === 'tijdkoppeling') {
            // Tijdkoppeling: langzaamsten eerst, snelsten in laatste heat.
            // allSlots is gesorteerd snelste eerst (index 0 = snelste).
            // Bouw paren van achteren: (N-1,N-2), (N-3,N-4), ..., (1,0)
            // Binnen elk paar: snelste op startpositie 1 (mag startkant kiezen).
            $paired = [];
            for ($i = $aantalSlots - 1; $i >= 0; $i -= 2) {
                $pair = [];
                if ($i - 1 >= 0) $pair[] = $allSlots[$i - 1]; // snelste van het paar
                $pair[] = $allSlots[$i];                        // langzaamste van het paar
                $paired[] = $pair;
            }
            // paired[0] = langzaamste paar → heat 1, paired[last] = snelste paar → laatste heat
            $allSlots = [];
            foreach ($paired as $hi => $pair) {
                $hNr = $heatNummers[$hi] ?? end($heatNummers);
                foreach ($pair as $rijder) {
                    $allSlots[] = $rijder;
                    $seq[] = $hNr;
                }
            }
        } else {
            // Slangenpatroon (standaard): gelijke sterkte per heat
            $si = 0;
            while ($si < $aantalSlots) {
                for ($h = 0; $h < $nDest && $si < $aantalSlots; $h++, $si++) {
                    $seq[] = $heatNummers[$h];
                }
                if ($si >= $aantalSlots) break;
                for ($h = $nDest - 1; $h >= 0 && $si < $aantalSlots; $h--, $si++) {
                    $seq[] = $heatNummers[$h];
                }
            }
        }

        // Wijs toe: positie altijd ophogen, INSERT alleen als rijder bekend
        $startposPerHeat = array_fill_keys($heatNummers, 0);
        foreach ($allSlots as $idx => $rijder) {
            $heatNr = $seq[$idx];
            $startposPerHeat[$heatNr]++;
            $startpos = $startposPerHeat[$heatNr];

            if ($rijder !== null) {
                $heatInfo = &$heatIds[$heatNr];
                $insEntry->execute([
                    $heatInfo['id'],
                    $rijder['person_license'],
                    $rijder['categorie'],
                    $startpos,
                    $rijder['startnummer'],
                ]);
                $heatInfo['rijders'][] = [
                    'startpositie' => $startpos,
                    'full_name'    => $rijder['full_name'],
                    'club_short'   => $rijder['club_short'],
                ];
                unset($heatInfo);
            }
        }

        // ── Overflow-rijders (ex-aequo) ─────────────────────────────────────────
        // Full-final: ex-aequo is al verwerkt in $allSlots/$bSlots, skip hier.
        if (!$isFullFinal && !empty($overflowRijders)) {
            // Meerdere finale-heats (bijv. DTT): maak extra heat(s) aan
            // KF/HF of 1 finale-heat: voeg toe aan bestaande heats (heat 1, 2, ...)
            {
                // KF/HF/enkele finale: overflow toevoegen aan bestaande heats (heat 1, 2, ...)
                $overflowIdx = 0;
                foreach ($overflowRijders as $rijder) {
                    $heatNr = $heatNummers[$overflowIdx % $nDest];
                    $startposPerHeat[$heatNr]++;
                    $startpos = $startposPerHeat[$heatNr];
                    $heatInfo = &$heatIds[$heatNr];
                    $insEntry->execute([
                        $heatInfo['id'],
                        $rijder['person_license'],
                        $rijder['categorie'],
                        $startpos,
                        $rijder['startnummer'],
                    ]);
                    $heatInfo['rijders'][] = [
                        'startpositie' => $startpos,
                        'full_name'    => $rijder['full_name'],
                        'club_short'   => $rijder['club_short'],
                    ];
                    unset($heatInfo);
                    $overflowIdx++;
                }
            }
        }

        // ── Full-Final: genereer B-finale heats ──────────────────────────────────
        $bHeatIds = [];
        if ($isFullFinal && !empty($bSlots)) {
            // Haal finale_b ritten op uit tijdschema
            $bRittenStmt = $pdo->prepare("
                SELECT r.id, r.heat_nr, r.volgorde, r.rit_naam, r.dc_naam, r.distance_id,
                       COALESCE(r.verwacht, 0) AS verwacht
                FROM tijdschema_ritten r
                WHERE r.tijdschema_id = ?
                  AND r.dc_id = ?
                  AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? = ''))
                  AND r.ronde_type = 'finale_b'
                ORDER BY r.heat_nr ASC
            ");
            $bRittenStmt->execute([$tsId, $dcId, $distanceId, $distanceId]);
            $bRitten = $bRittenStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($bRitten)) {
                // B-finale ritten zijn niet geconfigureerd in het tijdschema terwijl er
                // wel B-riders zijn. Dit wijst op een onvolledig tijdschema.
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'error' => 'Full-Final: er zijn ' . count($bSlots) . ' rijders voor de B-finale(s), '
                             . 'maar er zijn geen B-finale heats in het tijdschema geconfigureerd. '
                             . 'Genereer het tijdschema opnieuw om de B-finale heats aan te maken.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Full-final B-finale: dynamische verdeling op basis van werkelijk aantal B-rijders.
            // B1 krijgt de snelste B-rijders, B2 de volgende groep, Bn de traagste.
            // De aantallen per heat worden berekend via verdeelBFinales():
            //   · bFinaleHg = max rijders per B-heat (uit afstand config)
            //   · bLaatstGrootst = richting (laatste of eerste B-heat is de grootste)
            // $bSlots is gesorteerd van snel naar traag.
            $bTotaal    = count($bSlots);
            $bAantallen = verdeelBFinales($bTotaal, $bFinaleHg, $bLaatstGrootst);
            $nBNodig    = count($bAantallen);

            // Zijn er minder B-ritten in het tijdschema dan we nodig hebben?
            // Dan herbereken we voor het beschikbare aantal ritten.
            if ($nBNodig > count($bRitten)) {
                $nBNodig = count($bRitten);
                if ($nBNodig === 1) {
                    $bAantallen = [$bTotaal];
                } else {
                    $specialBig = $bTotaal - ($nBNodig - 1) * $bFinaleHg;
                    if ($bLaatstGrootst) {
                        $bAantallen = array_fill(0, $nBNodig - 1, $bFinaleHg);
                        $bAantallen[] = $specialBig;
                    } else {
                        $bAantallen = [$specialBig];
                        foreach (range(1, $nBNodig - 1) as $_) $bAantallen[] = $bFinaleHg;
                    }
                }
            }

            // Gebruik alleen de benodigde B-ritten (gesorteerd op heat_nr)
            $bRittenGebruik = array_values(array_slice($bRitten, 0, $nBNodig));

            $bOffset = 0;
            foreach ($bRittenGebruik as $bIdx => $rit) {
                $heatNr   = (int)$rit['heat_nr'];
                $verwacht = max(1, $bAantallen[$bIdx] ?? (int)$rit['verwacht']);

                $insHeat->execute([
                    $compId, $dcId, $distanceId ?: null,
                    $rondeNr, (int)$rit['id'], (int)$rit['volgorde'],
                    $rit['rit_naam'], $heatNr, $dcIdsJson,
                ]);
                $bHeatId = (int)$pdo->lastInsertId();
                $bHeatIds[$heatNr] = [
                    'id'       => $bHeatId,
                    'rit_naam' => $rit['rit_naam'],
                    'rijders'  => [],
                ];

                // Neem het volgende blok van $verwacht rijders uit $bSlots
                $block    = array_slice($bSlots, $bOffset, $verwacht);

                // Ex-aequo aan B-finale grens: als de laatste rijder in het blok
                // dezelfde tijd heeft als de eerstvolgende rijder, schuift die mee
                // naar deze (hogere) B-finale.
                if (!empty($block)) {
                    $laasteTijd = $block[count($block) - 1]['tijd_ms'];
                    $nextIdx    = $bOffset + count($block);
                    while (
                        $laasteTijd !== null &&
                        isset($bSlots[$nextIdx]) &&
                        $bSlots[$nextIdx]['tijd_ms'] !== null &&
                        (int)$bSlots[$nextIdx]['tijd_ms'] === (int)$laasteTijd
                    ) {
                        $block[] = $bSlots[$nextIdx];
                        $nextIdx++;
                    }
                }

                $startpos = 0;
                foreach ($block as $rijder) {
                    $startpos++;
                    $insEntry->execute([
                        $bHeatId,
                        $rijder['person_license'],
                        $rijder['categorie'],
                        $startpos,
                        $rijder['startnummer'],
                    ]);
                    $bHeatIds[$heatNr]['rijders'][] = [
                        'startpositie' => $startpos,
                        'full_name'    => $rijder['full_name'],
                        'club_short'   => $rijder['club_short'],
                    ];
                }
                $bOffset += count($block);
            }
        }

        $pdo->commit();

        // Response samenstellen
        $heatsOut = [];
        foreach ($heatIds as $hNr => $hInfo) {
            $heatsOut[] = [
                'heat_nr'   => $hNr,
                'heat_naam' => $hInfo['rit_naam'],
                'rijders'   => $hInfo['rijders'],
            ];
        }
        foreach ($bHeatIds as $hNr => $hInfo) {
            $heatsOut[] = [
                'heat_nr'   => $hNr,
                'heat_naam' => $hInfo['rit_naam'],
                'rijders'   => $hInfo['rijders'],
            ];
        }

        echo json_encode([
            'ok'    => true,
            'heats' => $heatsOut,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Basismap voor timing-exports van de tijdregistratie-software
// Ligt in de webroot: inlineresults.devriesen.com/uploader/
define('TIMING_BASE_DIR', __DIR__ . '/../uploader/');

// ── lijst_mappen ──────────────────────────────────────────────────────────────
// Geeft alle submappen van TIMING_BASE_DIR terug, gesorteerd op naam.
if ($action === 'lijst_mappen') {
    $mappen = [];
    if (is_dir(TIMING_BASE_DIR)) {
        foreach (scandir(TIMING_BASE_DIR) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir(TIMING_BASE_DIR . $entry)) $mappen[] = $entry;
        }
        natcasesort($mappen);
        $mappen = array_values($mappen);
    }
    echo json_encode(['mappen' => $mappen], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── lijst_uploads ─────────────────────────────────────────────────────────────
// Geeft alle *.csv-bestanden in TIMING_BASE_DIR/{map}/ terug.
// Pre-selectie: eerste bestand waarvan de naam een '1' bevat (heat 1).
if ($action === 'lijst_uploads') {
    $map = trim($body['map'] ?? '');
    // Beveilig: alleen mapnaam, geen slashes of traversal
    if (!$map || $map !== basename($map) || str_contains($map, '..')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige mapnaam']);
        exit;
    }
    $dir = TIMING_BASE_DIR . $map . '/';
    $files = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '*.csv') as $path) {
            $files[] = basename($path);
        }
        natcasesort($files);
        $files = array_values($files);
    }
    $preselect = '';
    foreach ($files as $f) {
        if (strpos($f, '1') !== false) { $preselect = $f; break; }
    }
    if (!$preselect && count($files)) $preselect = $files[0];

    echo json_encode(['files' => $files, 'preselect' => $preselect], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── lees_csv ──────────────────────────────────────────────────────────────────
// Leest één CSV uit TIMING_BASE_DIR/{map}/{filename}.
// Verwacht: {"map": "Heerenveen2025", "filename": "Heerenveen - 1 500.csv"}
// Geeft terug: {rows: [{pos, nr, naam, tijd_ms}]}
if ($action === 'lees_csv') {
    $map      = trim($body['map']      ?? '');
    $filename = trim($body['filename'] ?? '');
    // Beveilig tegen path-traversal
    if (!$map      || $map      !== basename($map)      || str_contains($map, '..') ||
        !$filename || $filename !== basename($filename)  || str_contains($filename, '..')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige map- of bestandsnaam']);
        exit;
    }
    $fullPath = TIMING_BASE_DIR . $map . '/' . $filename;
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Bestand niet gevonden: ' . htmlspecialchars($filename)]);
        exit;
    }

    // Lees bestand, detecteer encoding en zet om naar UTF-8 indien nodig.
    // Timing-software exporteert soms Windows-1252 (bijv. namen met ø, ü, é).
    $inhoud = file_get_contents($fullPath);
    if ($inhoud === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Bestand kon niet gelezen worden']);
        exit;
    }
    if (!mb_check_encoding($inhoud, 'UTF-8')) {
        $inhoud = mb_convert_encoding($inhoud, 'UTF-8', 'Windows-1252');
    }

    // Helper: string naar veilige UTF-8 (vangnet voor resterende ongeldige bytes)
    $toUtf8 = fn(string $s): string =>
        mb_convert_encoding($s, 'UTF-8', 'UTF-8');  // vervangt ongeldige sequences

    $rows = [];
    // Parse CSV vanuit de omgezette string via een tijdelijke stream
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $inhoud);
    rewind($fh);

    $firstLine = fgets($fh);
    rewind($fh);
    $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $header = null;
    while (($cols = fgetcsv($fh, 0, $sep)) !== false) {
        if ($header === null) {
            $header = array_map(fn($h) => strtolower(trim(trim($h), '"')), $cols);
            continue;
        }
        if (count($cols) < count($header)) continue;
        $row = array_combine($header, array_map(fn($v) => $toUtf8(trim(trim($v), '"')), $cols));

        $pos     = (int)($row['pos']      ?? $row['#']          ?? 0);
        $nr      = (int)($row['nr.']      ?? $row['nr']         ?? $row['startnummer'] ?? 0);
        $naam    =       $row['naam']      ?? $row['name']       ?? '';
        $tijdStr =       $row['tot. tijd'] ?? $row['tot.tijd']   ?? $row['tijd'] ?? $row['time'] ?? '';

        $tijdMs = null;
        if ($tijdStr !== '') {
            if (strpos($tijdStr, ':') !== false) {
                [$minStr, $rest] = explode(':', $tijdStr, 2);
                [$secStr, $msStr] = array_pad(explode('.', $rest, 2), 2, '0');
                $tijdMs = ((int)$minStr * 60 + (int)$secStr) * 1000
                        + (int)str_pad(substr($msStr, 0, 3), 3, '0');
            } else {
                [$secStr, $msStr] = array_pad(explode('.', $tijdStr, 2), 2, '0');
                $tijdMs = (int)$secStr * 1000
                        + (int)str_pad(substr($msStr, 0, 3), 3, '0');
            }
        }

        $rondenStr = $row['ronden'] ?? $row['rondes'] ?? $row['laps'] ?? '';
        $ronden    = ($rondenStr !== '' && $rondenStr !== null) ? (int)$rondenStr : null;

        if ($nr > 0) {
            $rows[] = ['pos' => $pos, 'nr' => $nr, 'naam' => $naam, 'tijd_ms' => $tijdMs, 'ronden' => $ronden];
        }
    }
    fclose($fh);

    $json = json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // Laatste vangnet: vervang resterende ongeldige UTF-8 door '?'
        $rows = array_map(function($r) {
            $r['naam'] = mb_convert_encoding($r['naam'], 'UTF-8', 'UTF-8');
            return $r;
        }, $rows);
        $json = json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json;
    exit;
}

// ── sla_punten_op ─────────────────────────────────────────────────────────────
// Slaat handmatig ingevoerde punten op per rijder (Puntenkoers).
// POST { action, heat_id, aanpassingen: [{entry_id, punten}] }
if ($action === 'sla_punten_op') {
    $heatId       = (int)($body['heat_id']      ?? 0);
    $aanpassingen = $body['aanpassingen']        ?? [];

    if (!$heatId) {
        http_response_code(400);
        echo json_encode(['error' => 'heat_id verplicht']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE results r
            INNER JOIN heat_entries he ON he.id = r.heat_entry_id
            SET r.punten = ?
            WHERE he.id = ? AND he.heat_id = ?
        ");
        foreach ($aanpassingen as $a) {
            $pnt = isset($a['punten']) && $a['punten'] !== '' && $a['punten'] !== null
                   ? (float)$a['punten'] : null;
            $stmt->execute([$pnt, (int)($a['entry_id'] ?? 0), $heatId]);
        }
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── set_race_type ─────────────────────────────────────────────────────────────
// Slaat het race-type op voor een heat (inline / puntenkoers / afvalkoers).
// POST { action, heat_id, race_type }
if ($action === 'set_race_type') {
    $heatId   = (int)(trim($body['heat_id']   ?? '0'));
    $raceType = trim($body['race_type'] ?? 'inline');
    if (!in_array($raceType, ['inline', 'puntenkoers', 'afvalkoers'], true)) $raceType = 'inline';
    if (!$heatId) {
        http_response_code(400);
        echo json_encode(['error' => 'heat_id verplicht']);
        exit;
    }
    try {
        $pdo->prepare("UPDATE heats SET race_type = ? WHERE id = ?")->execute([$raceType, $heatId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── wissel_posities ───────────────────────────────────────────────────────────
if ($action === 'wissel_posities') {
    $compId   = trim($body['competition_id']   ?? '');
    $entryId1 = (int)($body['heat_entry_id_1'] ?? 0);
    $entryId2 = (int)($body['heat_entry_id_2'] ?? 0);

    if (!$compId || !$entryId1 || !$entryId2 || $entryId1 === $entryId2) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige parameters voor wissel_posities']);
        exit;
    }

    try {
        // Haal beide results op, inclusief security-check op competition_id
        $stmt = $pdo->prepare("
            SELECT r.id, r.heat_entry_id, r.tijd_ms, r.finishpositie, r.rondes
            FROM results r
            JOIN heat_entries he ON he.id = r.heat_entry_id
            JOIN heats h ON h.id = he.heat_id
            WHERE r.heat_entry_id IN (?, ?)
              AND h.competition_id = ?
        ");
        $stmt->execute([$entryId1, $entryId2, $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 2) {
            http_response_code(404);
            echo json_encode(['error' => 'Resultaten niet gevonden of geen toegang']);
            exit;
        }

        // Zoek welke row bij welk entry_id hoort
        $r1 = $rows[0]['heat_entry_id'] == $entryId1 ? $rows[0] : $rows[1];
        $r2 = $rows[0]['heat_entry_id'] == $entryId2 ? $rows[0] : $rows[1];

        // Detecteer of dit een puntenkoers is
        $pkCheckStmt = $pdo->prepare("
            SELECT h.race_type
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            WHERE he.id = ?
            LIMIT 1
        ");
        $pkCheckStmt->execute([$entryId1]);
        $pkRow = $pkCheckStmt->fetch(PDO::FETCH_ASSOC);
        $wIsselIsPuntenkoers = ($pkRow['race_type'] ?? '') === 'puntenkoers';

        $ron1 = $r1['rondes'];
        $ron2 = $r2['rondes'];

        // Wissel finishpositie + tijd_ms (+ rondes bij ongelijke rondes, ook voor PK).
        // PK sorteert op punten → rondes → tijd, dus rondes-aanpassing is ook nodig voor PK.
        // Bij ongelijke rondes: verliezer krijgt winnaar's rondes + tijd -10ms zodat
        // _berekenPosities (en PK-sort) na rebuild de juiste volgorde geeft.
        $upd = $pdo->prepare("
            UPDATE results SET rondes = ?, tijd_ms = ?, finishpositie = ? WHERE id = ?
        ");

        if ($ron1 !== null && $ron2 !== null && $ron1 != $ron2) {
            // Ongelijke rondes (ook PK): winnaar behoudt eigen data, verliezer
            // krijgt winnaar's rondes + winnaar's tijd + 10ms.
            // Zo klopt de data (verliezer krijgt gecorrigeerde rondes) én blijft de
            // volgorde na rebuild correct (beide zelfde rondes → tijd beslist).
            $promotingR1 = $r2['finishpositie'] < $r1['finishpositie'];
            if ($promotingR1) {
                // r1 = winnaar: eigen rondes + tijd, betere finpos
                $upd->execute([$ron1, $r1['tijd_ms'], $r2['finishpositie'], $r1['id']]);
                // r2 = verliezer: krijgt r1's rondes + r1's tijd + 10ms, slechtere finpos
                $upd->execute([$ron1, $r1['tijd_ms'] + 10, $r1['finishpositie'], $r2['id']]);
            } else {
                // r1 = verliezer: krijgt r2's rondes + r2's tijd + 10ms, slechtere finpos
                $upd->execute([$ron2, $r2['tijd_ms'] + 10, $r2['finishpositie'], $r1['id']]);
                // r2 = winnaar: eigen rondes + tijd, betere finpos
                $upd->execute([$ron2, $r2['tijd_ms'], $r1['finishpositie'], $r2['id']]);
            }
        } else {
            // Gelijke rondes (of geen): wissel alleen finishpositie + tijd
            $upd->execute([$ron1, $r2['tijd_ms'], $r2['finishpositie'], $r1['id']]);
            $upd->execute([$ron2, $r1['tijd_ms'], $r1['finishpositie'], $r2['id']]);
        }

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Onbekende actie
http_response_code(400);
echo json_encode(['error' => 'Onbekende action: ' . htmlspecialchars($action)]);
