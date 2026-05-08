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
// Gelijkmatige verdeling (eerste heats krijgen de rest) — dupliceert
// tijdschema.php:verdeel() zodat live.php standalone werkt.
function verdeel(int $n, int $k): array {
    if ($k <= 0) return [];
    $basis = (int)floor($n / $k);
    $extra = $n % $k;
    $result = [];
    for ($i = 0; $i < $k; $i++) {
        $result[] = $basis + ($i < $extra ? 1 : 0);
    }
    return $result;
}
// Zelfde logica maar laatste heats krijgen de rest (grootste achteraan).
function verdeelLaatstGrootst(int $n, int $k): array {
    if ($k <= 0) return [];
    $basis = (int)floor($n / $k);
    $extra = $n % $k;
    $result = [];
    for ($i = 0; $i < $k; $i++) {
        $result[] = $basis + ($i >= $k - $extra ? 1 : 0);
    }
    return $result;
}

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
                r.combi_group,
                d.value_meters,
                d.race_type    AS dist_race_type,
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
                res.afval_rang
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
                        'afval_rang'        => $r['afval_rang'] !== null ? (int)$r['afval_rang'] : null,
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
                // race_type: distances is de canonieke bron (user-instelbaar);
                // heats.race_type blijft als fallback voor oude data die nog
                // niet via de nieuwe afstand-editor is bijgewerkt.
                'race_type'        => $rit['dist_race_type'] ?? $rit['heat_race_type'] ?? 'inline',
                'combi_group'      => $rit['combi_group'] !== null ? (int)$rit['combi_group'] : null,
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
                'heeft_runner_up'   => (bool)($cc['heeft_runner_up']   ?? false),
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
        // ── Race-type van de bijbehorende afstand ophalen ────────────────
        // Dit is de canonieke bron: distances.race_type. Op basis hiervan
        // forceren we rondes/punten op NULL als de afstand ze niet kent.
        // Voorkomt vervuilde residuals (bv. achtergebleven rondes na een
        // getogglede DNS op een sprint).
        $rtStmt = $pdo->prepare("
            SELECT d.race_type
            FROM tijdschema_ritten r
            JOIN distances d ON d.id = r.distance_id
                            AND d.distance_combination_id = r.dc_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $rtStmt->execute([$ritId]);
        $distRaceType = $rtStmt->fetchColumn();
        if (!$distRaceType) $distRaceType = 'inline'; // veilige default als lookup faalt
        $accepteertRondes = in_array($distRaceType, ['inline','puntenkoers','afvalkoers'], true);
        $accepteertPunten = ($distRaceType === 'puntenkoers');

        // Geldige sancties (DB = UI codes, geen mapping meer nodig)
        $geldigeSancties = ['W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF'];

        // Finishpositie berekenen (internationaal systeem):
        //   FS        → normale positie op basis van tijd (tijd BEWAARD)
        //   W1/W2/RR  → geen automatisch effect (jury past manueel aan)
        //   DNF/DQ-TF/DNS → ranked last in round (ex-aequo gedeeld laatste)
        //   DQ-SF/DQ-DF   → not ranked (geen positie, geen punten)
        //
        // Speciaal voor afvalkoers (race_type='afvalkoers'):
        //   Rijders met afval_rang gevuld → finishpositie = afval_rang
        //     (tijd en rondes uit CSV worden bewaard, maar niet leidend voor positie).
        //     Sanctie DQ-TF op afgevallene = "by-fault" afvalling.
        //   Rijders zonder afval_rang en mét tijd → finish-groep, krijgen positie 1..X
        //     op rondes DESC + tijd ASC (zelfde als inline).
        $RANKED_LAST = ['DNF', 'DQ-TF', 'DNS'];
        $NOT_RANKED  = ['DQ-SF', 'DQ-DF'];

        $isAfvalkoers = ($distRaceType === 'afvalkoers');

        $metTijd    = [];   // normale finishers + FS rijders (positie op tijd)
        $gedeeldArr = [];   // DNF / DQ-SF (gedeeld laatste)
        $zonderTijd = [];   // DNS / DQ-DF / leeg (geen positie)
        $afgevallen = [];   // afvalkoers: afgevallen rijders (positie = afval_rang)

        foreach ($results as $r) {
            $tijdMs    = isset($r['tijd_ms']) && $r['tijd_ms'] !== null && $r['tijd_ms'] !== ''
                         ? (int)$r['tijd_ms'] : null;
            $sanctie = trim($r['sanctie'] ?? '');
            $sanctie = in_array($sanctie, $geldigeSancties, true) ? $sanctie : null;

            $rondes = isset($r['rondes']) && $r['rondes'] !== '' && $r['rondes'] !== null
                      ? (int)$r['rondes'] : null;
            // Punten worden alleen bij puntenkoersen meegestuurd; null is OK
            $punten = isset($r['punten']) && $r['punten'] !== '' && $r['punten'] !== null
                      ? (float)$r['punten'] : null;

            // afval_rang: alleen relevant voor afvalkoers; bij andere race-types negeren
            $afvalRang = null;
            if ($isAfvalkoers && isset($r['afval_rang']) && $r['afval_rang'] !== null && $r['afval_rang'] !== '') {
                $afvalRang = (int)$r['afval_rang'];
                if ($afvalRang < 1) $afvalRang = null;
            }

            // Forceer NULL als de afstand dit veld niet kent — ongeacht wat
            // de frontend per ongeluk stuurde (bv. residuals van toggelen
            // DNS/DNF in eenzelfde sessie).
            if (!$accepteertRondes) $rondes = null;
            if (!$accepteertPunten) $punten = null;

            $base = [
                'entry_id'   => (int)$r['entry_id'],
                'rondes'     => $rondes,
                'punten'     => $punten,
                'afval_rang' => $afvalRang,
            ];

            // Afvalkoers + afval_rang ingevuld → afgevallen rijder. Hier overslaan we de
            // normale tijd/sanctie-classificatie: positie wordt dwingend afval_rang.
            // Sanctie blijft bewaard (bv. DQ-TF voor by-fault). Tijd uit CSV mag blijven
            // (bewaard voor uitslag-archief; bepaalt niet de positie).
            if ($isAfvalkoers && $afvalRang !== null) {
                $afgevallen[] = $base + ['tijd_ms' => $tijdMs, 'sanctie' => $sanctie];
                continue;
            }

            if ($sanctie && in_array($sanctie, $RANKED_LAST, true)) {
                // DNF / DQ-TF / DNS: ranked last in round, tijd wissen
                $gedeeldArr[] = $base + ['tijd_ms' => null, 'sanctie' => $sanctie];
            } elseif ($sanctie && in_array($sanctie, $NOT_RANKED, true)) {
                // DQ-SF / DQ-DF: not ranked, geen positie, geen tijd
                $zonderTijd[] = $base + ['tijd_ms' => null, 'sanctie' => $sanctie];
            } elseif ($sanctie === 'FS') {
                // FS: waarschuwing; tijd bewaren en normale positie toekennen
                if ($tijdMs !== null && $tijdMs > 0) {
                    $metTijd[]    = $base + ['tijd_ms' => $tijdMs, 'sanctie' => 'FS'];
                } else {
                    $zonderTijd[] = $base + ['tijd_ms' => null,    'sanctie' => 'FS'];
                }
            } elseif ($tijdMs !== null && $tijdMs > 0) {
                // Normale finisher
                $metTijd[]    = $base + ['tijd_ms' => $tijdMs, 'sanctie' => $sanctie];
            } else {
                // Geen tijd, geen geldige sanctie: wis resultaat
                $zonderTijd[] = $base + ['tijd_ms' => null, 'sanctie' => null];
            }
        }

        // Voor sortering gebruiken we de punten uit deze binnenkomende request
        // (die zojuist in dezelfde call worden opgeslagen) — niet de DB-waarde
        // die nog stale kan zijn. race_type komt uit distances (canonieke bron).
        $heatRaceType  = $distRaceType;
        $heatPuntenMap = [];
        if ($heatRaceType === 'puntenkoers') {
            foreach ($results as $r) {
                $eid = (int)($r['entry_id'] ?? 0);
                if (!$eid) continue;
                $heatPuntenMap[$eid] = isset($r['punten']) && $r['punten'] !== '' && $r['punten'] !== null
                    ? (float)$r['punten'] : 0.0;
            }
        }

        // Sorteer finishers:
        //   puntenkoers → punten DESC → rondes DESC → tijd ASC
        //   inline/afvalkoers → rondes DESC → tijd ASC
        //   sprint → alleen tijd ASC (rondes is altijd null)
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
        // Afvalkoers: afgevallen rijders → finishpositie = afval_rang (al toegekend door operator)
        foreach ($afgevallen as $r) {
            $r['finishpositie'] = $r['afval_rang'];
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
            INSERT INTO results (heat_entry_id, finishpositie, tijd_ms, rondes, punten, sanctie, afval_rang)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                finishpositie = VALUES(finishpositie),
                tijd_ms       = VALUES(tijd_ms),
                rondes        = VALUES(rondes),
                punten        = VALUES(punten),
                sanctie       = VALUES(sanctie),
                afval_rang    = VALUES(afval_rang)
        ");

        foreach ($alleResultaten as $r) {
            $upsert->execute([
                $r['entry_id'],
                $r['finishpositie'],
                $r['tijd_ms'],
                $r['rondes'],
                $r['punten'] ?? null,
                $r['sanctie'],
                $r['afval_rang'] ?? null,
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

        // Per-cat FF-instellingen (wint) overschrijven de afstand-defaults.
        // Zo kun je per categoriegroep instellen hoeveel rijders in de A-finale
        // zitten, hoeveel B-finales er zijn en waar de "rest" terecht komt.
        if ($isFullFinal) {
            if (isset($cc['finale_a_grootte']) && $cc['finale_a_grootte'] !== null && $cc['finale_a_grootte'] !== '') {
                $finaleHg = max(1, (int)$cc['finale_a_grootte']);
            }
            if (isset($cc['laatste_b_grootste']) && $cc['laatste_b_grootste'] !== null) {
                $bLaatstGrootst = !empty($cc['laatste_b_grootste']);
            }
            // finale_b_heats is een aantal heats, niet een grootte-per-heat.
            // We converteren naar een "maximale grootte per B-heat" zodat de bestaande
            // verdeelBFinales-logica werkt. Met N rest-rijders verdeeld over K heats:
            // max per heat = ceil(N / K). Dit wordt later pas berekend omdat we
            // nog niet weten hoeveel B-rijders er uiteindelijk zullen zijn.
        }

        // ── Runner-up: speciale verdeling over geplande RU-ritten ────────────
        // Pakt de afvallers van de eerste ronde van de keten (heats / kwart /
        // half — afhankelijk van wat er voor deze cat is geconfigureerd) en
        // verdeelt ze sequentieel over de in het tijdschema geplande
        // runner-up ritten op basis van het `verwacht` veld per rit.
        // De rit_naam-labels (bv. "plek 17-22") blijven zoals gepland; de
        // werkelijke rijders worden bepaald door wie níet is doorgestroomd
        // (incl. eventuele ex-aequo overflow in de volgende ronde).
        if ($naarRondeType === 'runner_up') {
            if (empty($cc['heeft_runner_up'])) {
                echo json_encode(['ok' => false, 'geen_ritten' => true]);
                exit;
            }

            // Eerste ronde van de keten detecteren — zelfde keten als
            // tijdschema.php case 'runner_up'.
            $eersteRondeType = null;
            if (!empty($cc['heeft_heats'])) {
                $eersteRondeType = 'heats';
            } elseif (!empty($cc['heeft_kwartfinale'])) {
                $eersteRondeType = 'kwartfinale';
            } elseif (!empty($cc['heeft_halve_finale'])) {
                $eersteRondeType = 'halve_finale';
            } else {
                // Geen voorafgaande ronde → geen afvallers → geen runner-up
                echo json_encode(['ok' => false, 'geen_ritten' => true]);
                exit;
            }

            // Caller mag een afwijkende vanRondeType meegeven (bv. 'heats'
            // ook als deze cat met kwart begint). We forceren de eerste ronde.
            $vanRondeType = $eersteRondeType;

            // Geplande RU-ritten ophalen (gesorteerd op heat_nr ASC: heat 1 =
            // hoogste plek-nummers net boven de afvallers, heat-laatste = grootst)
            $ruRittenStmt = $pdo->prepare("
                SELECT id, heat_nr, volgorde, rit_naam,
                       COALESCE(verwacht, 0) AS verwacht
                FROM tijdschema_ritten
                WHERE tijdschema_id = ? AND dc_id = ?
                  AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
                  AND ronde_type = 'runner_up'
                ORDER BY heat_nr
            ");
            $ruRittenStmt->execute([$tsId, $dcId, $distanceId, $distanceId]);
            $ruRitten = $ruRittenStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($ruRitten)) {
                echo json_encode(['ok' => false, 'geen_ritten' => true]);
                exit;
            }

            // Resultaten van de eerste ronde ophalen
            $resStmt = $pdo->prepare("
                SELECT he.person_license, he.categorie, he.startnummer,
                       p.full_name, p.club_short,
                       res.tijd_ms, res.rondes, res.sanctie
                FROM tijdschema_ritten r
                JOIN heats h ON h.tijdschema_rit_id = r.id AND h.competition_id = ?
                JOIN heat_entries he ON he.heat_id = h.id
                JOIN persons p ON p.license_key = he.person_license
                JOIN results res ON res.heat_entry_id = he.id
                WHERE r.tijdschema_id = ? AND r.dc_id = ?
                  AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? = ''))
                  AND r.ronde_type = ?
            ");
            $resStmt->execute([$compId, $tsId, $dcId,
                               $distanceId, $distanceId, $vanRondeType]);
            $alleRijders = $resStmt->fetchAll(PDO::FETCH_ASSOC);

            // Filter uitvallers (DNS / DNF / DQ)
            $sanctiesUit = ['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF'];
            $beschikbaar = [];
            foreach ($alleRijders as $r) {
                if (in_array($r['sanctie'] ?? '', $sanctiesUit, true)) continue;
                $beschikbaar[] = $r;
            }

            // Sorteer op tijd (rondes DESC voor lange afstand / puntenkoers,
            // dan tijd ASC). Rijders zonder tijd achteraan.
            $metTijd    = array_values(array_filter($beschikbaar, fn($r) => $r['tijd_ms'] !== null));
            $zonderTijd = array_values(array_filter($beschikbaar, fn($r) => $r['tijd_ms'] === null));
            usort($metTijd, function($a, $b) {
                $rA = $a['rondes'] !== null ? (int)$a['rondes'] : PHP_INT_MIN;
                $rB = $b['rondes'] !== null ? (int)$b['rondes'] : PHP_INT_MIN;
                if ($rA !== $rB) return $rB - $rA;
                return (int)$a['tijd_ms'] - (int)$b['tijd_ms'];
            });
            $alleGesorteerd = array_merge($metTijd, $zonderTijd);

            // Wie is al ingedeeld in een vervolgronde (kwart/half/finale)?
            // Die rijders horen NIET in de runner-up — ook als ze door
            // ex-aequo overflow alsnog mochten doorstromen.
            // De rondes ná de eerste ronde — afhankelijk van wat dé eerste is.
            // Als HF de eerste ronde is, is HF zélf NIET een vervolgronde
            // (dan zouden alle HF-rijders worden uitgefilterd → 0 afvallers).
            $naRondes = match ($eersteRondeType) {
                'heats'        => ['kwartfinale','halve_finale','finale','finale_a','finale_b'],
                'kwartfinale'  => ['halve_finale','finale','finale_a','finale_b'],
                'halve_finale' => ['finale','finale_a','finale_b'],
                default        => ['finale','finale_a','finale_b'],
            };
            $naPh = implode(',', array_fill(0, count($naRondes), '?'));
            $alDoorStmt = $pdo->prepare("
                SELECT DISTINCT he.person_license
                FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                WHERE h.competition_id = ? AND h.distance_combination_id = ?
                  AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? = ''))
                  AND r.ronde_type IN ($naPh)
            ");
            $alDoorStmt->execute(array_merge(
                [$compId, $dcId, $distanceId, $distanceId],
                $naRondes
            ));
            $alDoor = array_fill_keys($alDoorStmt->fetchAll(PDO::FETCH_COLUMN), true);

            $afvallers = array_values(array_filter(
                $alleGesorteerd,
                fn($r) => !isset($alDoor[$r['person_license']])
            ));

            if (empty($afvallers)) {
                echo json_encode(['ok' => false, 'geen_ritten' => true]);
                exit;
            }

            // ── Bestaande RU-heats opruimen + nieuwe insert ──────────────
            $pdo->beginTransaction();

            $delIds = $pdo->prepare("
                SELECT h.id FROM heats h
                JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                WHERE h.competition_id = ? AND h.distance_combination_id = ?
                  AND (r.distance_id = ? OR (r.distance_id IS NULL AND ? = ''))
                  AND r.ronde_type = 'runner_up'
            ");
            $delIds->execute([$compId, $dcId, $distanceId, $distanceId]);
            $ids = $delIds->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM heats WHERE id IN ($ph)")->execute($ids);
            }

            $insHeat = $pdo->prepare("
                INSERT INTO heats
                    (competition_id, distance_combination_id, distance_id,
                     ronde, tijdschema_rit_id, rit_volgorde,
                     heat_naam, heat_nr, methode, dc_ids)
                VALUES (?, ?, ?, 4, ?, ?, ?, ?, 'kwalificatie', ?)
            ");
            $insEntry = $pdo->prepare("
                INSERT IGNORE INTO heat_entries
                    (heat_id, person_license, categorie, startpositie, startnummer)
                VALUES (?, ?, ?, ?, ?)
            ");
            $dcIdsJson = json_encode([$dcId]);

            // Plek-base = werkelijk aantal doorgestroomden (incl. eventuele
            // jury-toevoegingen / RR-extras). Loopt op met elke runner_up
            // heat zodat de label-ranges aansluiten op de actuele situatie.
            $plekBase = count($alDoor);
            $updRit   = $pdo->prepare(
                "UPDATE tijdschema_ritten SET rit_naam = ? WHERE id = ?"
            );

            $heatsOut = [];
            $offset   = 0;
            $totaal   = count($afvallers);
            foreach ($ruRitten as $rit) {
                // Aantal rijders voor deze heat = `verwacht`. Laatste heat
                // krijgt de rest (vangnet als afvallers > som van verwacht).
                $verwacht = max(0, (int)$rit['verwacht']);
                $isLaatste = ($rit === end($ruRitten));
                $aantal = $isLaatste ? max($verwacht, $totaal - $offset) : $verwacht;
                $blok = array_slice($afvallers, $offset, $aantal);
                $offset += count($blok);

                // Werkelijke plek-range voor deze heat → "(plek X)" of
                // "(plek X-Y)" in de rit-naam vervangen. Speakers en uitslag-
                // titels lopen zo gelijk met de actuele doorstroom-aantallen.
                $aantalReal = count($blok);
                $van = $plekBase + 1;
                $tot = $plekBase + $aantalReal;
                $plekBase += $aantalReal;
                $plekLbl = ($aantalReal === 0)
                    ? null
                    : ($van === $tot ? "plek {$van}" : "plek {$van}-{$tot}");
                $nieuweRitNaam = $plekLbl
                    ? preg_replace(
                        '/\(plek\s+\d+(?:-\d+)?\)/u',
                        "({$plekLbl})",
                        $rit['rit_naam']
                    )
                    : $rit['rit_naam'];
                if ($nieuweRitNaam !== $rit['rit_naam']) {
                    $updRit->execute([$nieuweRitNaam, (int)$rit['id']]);
                }

                $insHeat->execute([
                    $compId, $dcId, $distanceId ?: null,
                    (int)$rit['id'], (int)$rit['volgorde'],
                    $nieuweRitNaam, (int)$rit['heat_nr'], $dcIdsJson,
                ]);
                $heatId = (int)$pdo->lastInsertId();

                $rijdersUit = [];
                $startpos = 0;
                foreach ($blok as $rijder) {
                    $startpos++;
                    $insEntry->execute([
                        $heatId,
                        $rijder['person_license'],
                        $rijder['categorie'],
                        $startpos,
                        $rijder['startnummer'],
                    ]);
                    $rijdersUit[] = [
                        'startpositie' => $startpos,
                        'full_name'    => $rijder['full_name'],
                        'club_short'   => $rijder['club_short'],
                    ];
                }
                $heatsOut[] = [
                    'heat_nr'   => (int)$rit['heat_nr'],
                    'heat_naam' => $nieuweRitNaam,
                    'rijders'   => $rijdersUit,
                ];
            }

            $pdo->commit();

            echo json_encode(['ok' => true, 'heats' => $heatsOut],
                             JSON_UNESCAPED_UNICODE);
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
            // Check of er daadwerkelijk B-finale ritten in het tijdschema staan.
            // Als een rijder later is bijgekomen (nadat de planning al was gemaakt
            // voor bv. max 4 rijders), is er géén B-finale gepland. In dat geval
            // willen we géén fake B-finale aanmaken: iedereen gaat naar de A-finale,
            // ook als het er meer dan $finaleHg worden.
            $bCheckStmt = $pdo->prepare("
                SELECT COUNT(*) FROM tijdschema_ritten
                WHERE tijdschema_id = ?
                  AND dc_id = ?
                  AND ronde_type = 'finale_b'
                  AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
            ");
            $bCheckStmt->execute([$tsId, $dcId, $distanceId, $distanceId]);
            $bRittenGeconfigureerd = (int)$bCheckStmt->fetchColumn() > 0;

            if ($bRittenGeconfigureerd) {
                $aantalDoor = $finaleHg;
            } else {
                // Geen B-finale gepland → iedereen in de A-finale.
                // PHP_INT_MAX is veilig: array_slice() met een te groot lengte-
                // argument pakt gewoon alle beschikbare rijders. ($beschikbaar
                // bestaat op dit punt nog niet, dus count() kan hier niet.)
                $aantalDoor = PHP_INT_MAX;
            }
        }

        if ($aantalDoor <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Aantal door is 0 of niet ingesteld in cat-config']);
            exit;
        }

        // ── Bepaal Q/q seeding parameters ────────────────────────────────────────
        // qPerHeat = aantal positie-qualifiers per bron-heat
        //   heats       → volgende : full-final gebruikt heats_q_heat (default 0
        //                  = puur tijdsortering, klassiek). Bij ≥1: winnaar(s)
        //                  van elke serie direct naar A-finale, rest aangevuld
        //                  met tijdsnelsten. Internationaal heeft géén heats →
        //                  finale_a transitie, dus daar irrelevant.
        //   kwartfinale → volgende : kwart_q_heat (default 1)
        //   halve_finale→ volgende : half_q_heat  (default 1)
        $qPerHeat = 0;
        if ($vanRondeType === 'heats') {
            $qPerHeat = (int)($cc['heats_q_heat'] ?? 0);
        } elseif ($vanRondeType === 'kwartfinale') {
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
                res.rondes,
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
        $sanctiesUit = ['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF'];
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

        // Als er geen expliciete Q per heat is gezet maar wel meerdere bron-
        // heats bestaan, en de user kiest "Standaard" (slang), én het gaat om
        // een LANGE-afstand / puntenkoers (minstens één rijder heeft rondes
        // > 0), dan interpreteren we "totaal door = N" als "top-N/bronheats
        // per heat + alternerend" (rank-snake: rank1-H1, rank1-H2, rank2-H1,
        // rank2-H2, …).
        // Bij sprint (alle rijders hebben `rondes = NULL`) blijven we op
        // pure tijd-sortering — want daar is tijd het enige zinvolle
        // criterium en is snake juist niet gewenst.
        $hasRondes = false;
        foreach ($beschikbaar as $r) {
            if ($r['rondes'] !== null && (int)$r['rondes'] > 0) { $hasRondes = true; break; }
        }
        if ($qPerHeat === 0 && $naarRondeType === 'finale_a'
            && $nBronHeats > 1 && $finaleSeeding !== 'tijdkoppeling'
            && $hasRondes) {
            $qPerHeat = (int)ceil($aantalDoor / max(1, $nBronHeats));
        }

        if ($qPerHeat > 0) {
            // Groepeer per heat_nr, sorteer op finishpositie
            $byHeat = [];
            foreach ($beschikbaar as $r) {
                $byHeat[(int)$r['heat_nr']][] = $r;
            }
            // Sorteer per heat: rondes DESC (lange afstand / puntenkoers;
            // meer rondes = beter) en dan finishpositie ASC. Voor sprints
            // waar `rondes` altijd NULL is, valt het terug op puur finishpositie.
            foreach ($byHeat as $hn => &$riders) {
                usort($riders, function($a, $b) {
                    $rA = $a['rondes'] !== null ? (int)$a['rondes'] : PHP_INT_MIN;
                    $rB = $b['rondes'] !== null ? (int)$b['rondes'] : PHP_INT_MIN;
                    if ($rA !== $rB) return $rB - $rA;  // DESC
                    $fA = $a['finishpositie'] === null ? PHP_INT_MAX : (int)$a['finishpositie'];
                    $fB = $b['finishpositie'] === null ? PHP_INT_MAX : (int)$b['finishpositie'];
                    return $fA - $fB;
                });
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

            // Volledige slot-lijst (zonder overflow – die komen er apart achteraan).
            // Tier-based seeding: eerst álle nummer-1's onderling op tijd, dan
            // álle nummer-2's op tijd, enzovoort (klassieke WS/KNSB-conventie).
            // Daarna pas de q-slots (tijdsnelsten) op tijd uit $qPool.
            // Dit garandeert dat een heat-winnaar (zelfs een langzame) altijd
            // vóór een nummer-2 (zelfs een snelle) start.
            $sortByTijd = function($a, $b) {
                $rA = $a['rondes'] !== null ? (int)$a['rondes'] : PHP_INT_MIN;
                $rB = $b['rondes'] !== null ? (int)$b['rondes'] : PHP_INT_MIN;
                if ($rA !== $rB) return $rB - $rA; // rondes DESC voor lange afstand
                $tA = $a['tijd_ms'] === null ? PHP_INT_MAX : (int)$a['tijd_ms'];
                $tB = $b['tijd_ms'] === null ? PHP_INT_MAX : (int)$b['tijd_ms'];
                return $tA - $tB; // tijd ASC
            };
            $allSlots = [];
            for ($rank = 1; $rank <= $qPerHeat; $rank++) {
                // Pak alle rijders met deze rank uit elke heat (volgorde uit
                // $qSlots: rank-1-h1, rank-1-h2, ..., rank-2-h1, rank-2-h2, ...).
                $tier = array_slice($qSlots, ($rank - 1) * $nBronHeats, $nBronHeats);
                $tier = array_values(array_filter($tier, fn($r) => $r !== null));
                usort($tier, $sortByTijd);
                foreach ($tier as $r) $allSlots[] = $r;
            }
            // q-slots ($qPool was al op tijd gesorteerd)
            for ($i = 0; $i < $nqSlots; $i++) {
                $allSlots[] = $qPool[$i] ?? null;
            }

            // Full-final: overgebleven rijders (die niet in A passen) → B-finales.
            // Pak iedereen uit $beschikbaar minus de A-finalisten en sorteer op
            // rondes DESC dan tijd ASC (zelfde criterium als $byHeat-sortering).
            // Rijders zonder tijd (DNF/DNS) gaan achteraan zodat ze in de
            // langzaamste B-heat eindigen.
            if ($isFullFinal) {
                $aIds = array_filter(array_column($allSlots, 'entry_id'));
                $aIdsSet = array_flip($aIds);
                $bKandidaten = array_values(array_filter($beschikbaar,
                    fn($r) => !isset($aIdsSet[$r['entry_id']])
                ));
                $metTijdB    = array_values(array_filter($bKandidaten, fn($r) => $r['tijd_ms'] !== null));
                $zonderTijdB = array_values(array_filter($bKandidaten, fn($r) => $r['tijd_ms'] === null));
                usort($metTijdB, function($a, $b) {
                    $rA = $a['rondes'] !== null ? (int)$a['rondes'] : PHP_INT_MIN;
                    $rB = $b['rondes'] !== null ? (int)$b['rondes'] : PHP_INT_MIN;
                    if ($rA !== $rB) return $rB - $rA; // DESC
                    return (int)$a['tijd_ms'] - (int)$b['tijd_ms'];
                });
                $bSlots = array_merge($metTijdB, $zonderTijdB);
            }
        } else {
            // Puur tijdsortering (heats → kwartfinale / runner_up / finale).
            // Voor lange afstand / puntenkoers: eerst rondes DESC (meer rondes
            // = beter), dan tijd ASC. Rijders die niet de volledige afstand
            // hebben gereden (bv. DNF met 13 van 15 rondes) vallen zo onder
            // de rijders die wel de volle afstand uitreden.
            $metTijd    = array_values(array_filter($beschikbaar, fn($r) => $r['tijd_ms'] !== null));
            $zonderTijd = array_values(array_filter($beschikbaar, fn($r) => $r['tijd_ms'] === null));
            usort($metTijd, function($a, $b) {
                $rA = $a['rondes'] !== null ? (int)$a['rondes'] : PHP_INT_MIN;
                $rB = $b['rondes'] !== null ? (int)$b['rondes'] : PHP_INT_MIN;
                if ($rA !== $rB) return $rB - $rA; // DESC
                return (int)$a['tijd_ms'] - (int)$b['tijd_ms'];
            });

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

        // Delete bestaande heats voor het doel-rondetype + eventuele finale_b
        // Bevat ook heats met ronde=1 die via startlijst_genereer zijn aangemaakt.
        $delTypes = [$naarRondeType];
        if ($naarRondeType === 'finale_a') $delTypes[] = 'finale_b'; // B-finales mee opruimen
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

        // Fallback: verwijder ook heats op het juiste ronde-nummer zonder rit-koppeling.
        // BELANGRIJK: runner_up heats delen ronde=4 met finale_a/finale_b. Bij het
        // genereren van de finale moeten runner_up heats blijven staan — daarom
        // sluiten we runner_up expliciet uit via een LEFT JOIN op de rit.
        $pdo->prepare("
            DELETE h FROM heats h
            LEFT JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
            WHERE h.competition_id          = ?
              AND h.distance_combination_id = ?
              AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? = ''))
              AND h.ronde = ?
              AND (r.ronde_type IS NULL OR r.ronde_type <> 'runner_up')
        ")->execute([$compId, $dcId, $distanceId, $distanceId, $rondeNr]);

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
        // ── Hernummer volgorde: sluit gaten van verwijderde ex-aequo ritten ──
        // ORDER BY volgorde behoudt de bestaande relatieve volgorde exact.
        if ($cleanTsId) {
            $allRitIds = $pdo->prepare(
                "SELECT id FROM tijdschema_ritten WHERE tijdschema_id = ? ORDER BY volgorde"
            );
            $allRitIds->execute([$cleanTsId]);
            $ritRows = $allRitIds->fetchAll(PDO::FETCH_COLUMN);
            $updVolg = $pdo->prepare("UPDATE tijdschema_ritten SET volgorde = ? WHERE id = ?");
            foreach ($ritRows as $vi => $ritRowId) {
                $updVolg->execute([$vi + 1, $ritRowId]);
            }
        }

        // ── Volgende-ronde ritten ophalen (NA cleanup + hernummering) ────────
        // Moet NA de cleanup staan zodat ex-aequo ritten van de vorige run
        // niet meer worden meegeteld.
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
            $pdo->commit();
            echo json_encode(['ok' => false, 'geen_ritten' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ── Maak nieuwe heats aan ─────────────────────────────────────────────
        $insHeat = $pdo->prepare("
            INSERT INTO heats
                (competition_id, distance_combination_id, distance_id,
                 ronde, tijdschema_rit_id, rit_volgorde,
                 heat_naam, heat_nr, methode, dc_ids)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'kwalificatie', ?)
        ");
        $insEntry = $pdo->prepare("
            INSERT IGNORE INTO heat_entries (heat_id, person_license, categorie, startpositie, startnummer)
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
            $nOverflow = count($overflowRijders);
            $allSlots = array_merge($allSlots, $overflowRijders);
            $overflowRijders = []; // verwerkt

            $aantalSlots = count($allSlots);
            $heatNummers = array_keys($heatIds);
            sort($heatNummers);
            // origPerHeat: hoeveel rijders per heat ZONDER de overflow
            $origPerHeat = max(1, (int)round(($aantalSlots - $nOverflow) / max(1, count($heatNummers))));
            $neededHeats = (int)ceil($aantalSlots / max(1, $origPerHeat));

            // Extra heats aanmaken als nodig
            // Referentie-rit ophalen EENMALIG
            $refRitStmt = $pdo->prepare("
                SELECT r.id, r.blok_id, r.volgorde, r.tijdschema_id, r.afstand_naam
                FROM tijdschema_ritten r
                JOIN competition_tijdschema ct ON ct.id = r.tijdschema_id
                WHERE ct.competition_id = ? AND r.dc_id = ? AND r.ronde_type = 'finale_a'
                ORDER BY r.volgorde ASC LIMIT 1
            ");
            $refRitStmt->execute([$compId, $dcId]);
            $refRit = $refRitStmt->fetch(PDO::FETCH_ASSOC);

            $maxIteraties = max(0, $neededHeats - count($heatIds));
            for ($extraI = 0; $extraI < $maxIteraties; $extraI++) {
                if ($finaleSeeding === 'tijdkoppeling') {
                    $extraHeatNr = min(array_keys($heatIds)) - 1;
                } else {
                    $extraHeatNr = max(array_keys($heatIds)) + 1;
                }
                $afNaam = $refRit['afstand_naam'] ?? ($volgendeRitten[0]['afstand_naam'] ?? '');
                $dcNaamExtra = $volgendeRitten[0]['dc_naam'] ?? '';
                $extraNaam = "A-finale heat ex-aequo (extra) {$afNaam} – {$dcNaamExtra}";
                $extraVerwacht = max(1, (int)ceil(($aantalSlots - $origPerHeat * count($heatIds)) / max(1, $neededHeats - count($heatIds))));

                $extraRitId = null;
                if ($refRit) {
                    // Tijdkoppeling: extra heat VÓÓR de rest (langzaamsten eerst)
                    // Slang: extra heat NÁ de rest
                    if ($finaleSeeding === 'tijdkoppeling') {
                        // Vers ophalen: laagste volgorde van deze DC's finale ritten
                        $minVStmt = $pdo->prepare("
                            SELECT MIN(r.volgorde) FROM tijdschema_ritten r
                            WHERE r.tijdschema_id = ? AND r.dc_id = ? AND r.ronde_type = 'finale_a'
                        ");
                        $minVStmt->execute([$refRit['tijdschema_id'], $dcId]);
                        $insertVolgorde = (int)$minVStmt->fetchColumn();
                    } else {
                        $maxVStmt = $pdo->prepare("
                            SELECT MAX(r.volgorde) FROM tijdschema_ritten r
                            WHERE r.tijdschema_id = ? AND r.dc_id = ? AND r.ronde_type = 'finale_a'
                        ");
                        $maxVStmt->execute([$refRit['tijdschema_id'], $dcId]);
                        $insertVolgorde = (int)$maxVStmt->fetchColumn() + 1;
                    }

                    // Schuif ritten op om ruimte te maken (alleen als we ervoor invoegen)
                    if ($finaleSeeding === 'tijdkoppeling') {
                        $pdo->prepare("
                            UPDATE tijdschema_ritten
                            SET volgorde = volgorde + 1
                            WHERE tijdschema_id = ? AND volgorde >= ?
                        ")->execute([$refRit['tijdschema_id'], $insertVolgorde]);
                    }

                    $pdo->prepare("
                        INSERT INTO tijdschema_ritten
                            (tijdschema_id, blok_id, volgorde, dc_id, distance_id,
                             afstand_naam, ronde_type, heat_nr, rit_naam, dc_naam, verwacht)
                        VALUES (?, ?, ?, ?, ?, ?, 'finale_a', ?, ?, ?, ?)
                    ")->execute([
                        $refRit['tijdschema_id'], $refRit['blok_id'], $insertVolgorde,
                        $dcId, $distanceId ?: null,
                        $afNaam, $extraHeatNr, $extraNaam,
                        $dcNaamExtra, $extraVerwacht,
                    ]);
                    $extraRitId = (int)$pdo->lastInsertId();

                    $ritVolgorde = $insertVolgorde;
                } else {
                    $ritVolgorde = 0;
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

            // Sync ALLE heats.rit_volgorde met de (verschoven) tijdschema_ritten
            $pdo->prepare("
                UPDATE heats h
                JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                SET h.rit_volgorde = r.volgorde
                WHERE h.competition_id = ?
            ")->execute([$compId]);
        }

        // ── Seed alle slots naar dest-heats ──────────────────────────────────
        $heatNummers     = array_keys($heatIds);
        sort($heatNummers);
        $nDest           = count($heatNummers);
        $aantalSlots     = count($allSlots);

        // Helper: row-snake (forward, reverse, forward, ...) over $nSlots slots
        // distribueert ze gelijkmatig over $nDest heats.
        $snakeHeats = function (int $nSlots, int $nDest, array $heatNrs): array {
            $seq = [];
            $si = 0;
            while ($si < $nSlots) {
                for ($h = 0; $h < $nDest && $si < $nSlots; $h++, $si++) $seq[] = $heatNrs[$h];
                if ($si >= $nSlots) break;
                for ($h = $nDest - 1; $h >= 0 && $si < $nSlots; $h--, $si++) $seq[] = $heatNrs[$h];
            }
            return $seq;
        };

        // Case-detectie voor internationaal-systeem (full-final + tijdkoppeling
        // hebben hun eigen pad).
        $isInternationaal = ($systeem !== 'full-final');
        $nQTotaal         = $qPerHeat * $nBronHeats;            // 0 als qPerHeat = 0
        $nqTotaal         = max(0, $aantalSlots - $nQTotaal);
        $caseAlleenQ      = ($qPerHeat > 0 && $nqTotaal === 0); // bracket-pattern
        $caseQEnQ         = ($qPerHeat > 0 && $nqTotaal > 0);   // twee-pass snake

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
        } elseif ($isInternationaal && $caseAlleenQ && $nDest > 0 && $nBronHeats > 0) {
            // ── Bracket-pattern: alleen Q's, geen tijds-q ───────────────────
            // Bron-heats worden gepaard op heat-positie: index 0 ↔ index N-1,
            // index 1 ↔ index N-2, etc. (de "buitenste" met de "binnenste").
            // Binnen elke destination-heat: rank-1 van eerste-bron, rank-1 van
            // tweede-bron, daarna rank-2 van eerste-bron, rank-2 van tweede-bron, ...
            $bronGroups = [];
            $bronCount  = count($bronHeatNrs);
            for ($i = 0; $i < $bronCount; $i++) {
                $destIdx = min($i, $bronCount - 1 - $i);
                if (!isset($bronGroups[$destIdx])) $bronGroups[$destIdx] = [];
                $bronGroups[$destIdx][] = (int)$bronHeatNrs[$i];
            }
            ksort($bronGroups);
            // Sorteer bron-heats binnen elke groep op heat-nr ASC zodat de
            // "lagere" bron-heat als eerste in de slot-volgorde komt
            // (matched de One Lap-conventie: Winnaar KF1 vóór Winnaar KF4).
            foreach ($bronGroups as &$grp) sort($grp);
            unset($grp);

            $newAllSlots = [];
            $newSeq      = [];
            foreach ($bronGroups as $destIdx => $bronHeats) {
                if ($destIdx >= $nDest) break;
                $destHeatNr = $heatNummers[$destIdx];
                for ($rank = 1; $rank <= $qPerHeat; $rank++) {
                    foreach ($bronHeats as $bronHeatNr) {
                        $rider = $byHeat[$bronHeatNr][$rank - 1] ?? null;
                        $newAllSlots[] = $rider;
                        $newSeq[]      = $destHeatNr;
                    }
                }
            }
            $allSlots = $newAllSlots;
            $seq      = $newSeq;
        } elseif ($isInternationaal && $caseQEnQ && $nDest > 0) {
            // ── Q + q: twee-pass snake ──────────────────────────────────────
            // allSlots = [Q's tier+time, q's time]. Beide groepen krijgen elk
            // hun eigen snake-distributie — de q-pass start opnieuw bij heat 1
            // i.p.v. door te lopen vanuit de Q-pass-richting. Dit zorgt voor
            // een eerlijkere verdeling van de tijd-q's over de dest-heats.
            $seqQ = $snakeHeats($nQTotaal, $nDest, $heatNummers);
            $seqq = $snakeHeats($aantalSlots - $nQTotaal, $nDest, $heatNummers);
            $seq  = array_merge($seqQ, $seqq);
        } else {
            // ── Standaard snake ─────────────────────────────────────────────
            // Alleen q in internationaal (qPerHeat = 0), full-final (alle
            // varianten), of fallback wanneer geen $bronHeatNrs beschikbaar is.
            $seq = $snakeHeats($aantalSlots, $nDest, $heatNummers);
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
            // Als de planner per-cat 0 B-heats heeft ingesteld: alle B-rijders
            // toevoegen aan de A-finale. De planner is verantwoordelijk voor
            // het in de gaten houden of de A-finale dan niet te vol wordt.
            $catBHeatsCheck = $cc['finale_b_heats'] ?? null;
            if ($catBHeatsCheck !== null && $catBHeatsCheck !== '' && (int)$catBHeatsCheck === 0) {
                if (!empty($heatNummers)) {
                    $eersteAHeat = $heatNummers[0];
                    if (isset($heatIds[$eersteAHeat])) {
                        $heatInfoMerge = &$heatIds[$eersteAHeat];
                        foreach ($bSlots as $rijder) {
                            $startposPerHeat[$eersteAHeat]++;
                            $startposMerge = $startposPerHeat[$eersteAHeat];
                            $insEntry->execute([
                                $heatInfoMerge['id'],
                                $rijder['person_license'],
                                $rijder['categorie'],
                                $startposMerge,
                                $rijder['startnummer'],
                            ]);
                            $heatInfoMerge['rijders'][] = [
                                'startpositie' => $startposMerge,
                                'full_name'    => $rijder['full_name'],
                                'club_short'   => $rijder['club_short'],
                            ];
                        }
                        unset($heatInfoMerge);
                    }
                }
                $bSlots = [];  // verwerkt — geen B-finale nodig
            }
        }
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
                // Vangnet: geen B-finale ritten in het tijdschema (bv. door
                // laat-toegevoegde rijders nadat de planning al was gemaakt).
                // In plaats van een error te gooien en de transactie te rollbacken,
                // voegen we de B-rijders achteraan in de eerste A-finale heat.
                // Resultaat: één A-finale met alle rijders, geen fake B-finale.
                if (!empty($heatNummers)) {
                    $eersteAHeat = $heatNummers[0];
                    if (isset($heatIds[$eersteAHeat])) {
                        $heatInfoFallback = &$heatIds[$eersteAHeat];
                        foreach ($bSlots as $rijder) {
                            $startposPerHeat[$eersteAHeat]++;
                            $startposFb = $startposPerHeat[$eersteAHeat];
                            $insEntry->execute([
                                $heatInfoFallback['id'],
                                $rijder['person_license'],
                                $rijder['categorie'],
                                $startposFb,
                                $rijder['startnummer'],
                            ]);
                            $heatInfoFallback['rijders'][] = [
                                'startpositie' => $startposFb,
                                'full_name'    => $rijder['full_name'],
                                'club_short'   => $rijder['club_short'],
                            ];
                        }
                        unset($heatInfoFallback);
                    }
                }
                $bSlots = [];  // verwerkt — geen B-finale generatie meer nodig
            }

            // Full-final B-finale: dynamische verdeling op basis van werkelijk aantal B-rijders.
            // B1 krijgt de snelste B-rijders, B2 de volgende groep, Bn de traagste.
            // $bSlots is gesorteerd van snel naar traag.
            $bTotaal = count($bSlots);

            // Per-cat finale_b_heats (wint): gebruik dit om het exacte aantal B-heats
            // te bepalen in plaats van af te leiden uit bFinaleHg. Overige rijders
            // worden gelijk verdeeld; de "rest" schuift naar B1 of B-laatste afhankelijk
            // van laatste_b_grootste.
            $catBHeatsRaw = $cc['finale_b_heats'] ?? null;
            $catBHeats    = ($catBHeatsRaw !== null && $catBHeatsRaw !== '')
                ? max(0, (int)$catBHeatsRaw) : null;

            if ($catBHeats !== null) {
                // Expliciet aantal B-heats ingesteld per categorie
                $nBHeatsWanted = min($catBHeats, $bTotaal);
                if ($nBHeatsWanted <= 0) {
                    $bAantallen = [];
                } elseif ($nBHeatsWanted === 1) {
                    $bAantallen = [$bTotaal];
                } else {
                    $bAantallen = $bLaatstGrootst
                        ? verdeelLaatstGrootst($bTotaal, $nBHeatsWanted)
                        : verdeel($bTotaal, $nBHeatsWanted);
                }
            } else {
                // Legacy: afleiden uit max-per-heat (bFinaleHg uit afstand config)
                $bAantallen = verdeelBFinales($bTotaal, $bFinaleHg, $bLaatstGrootst);
            }
            $nBNodig = count($bAantallen);

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
// Geeft alle submappen van TIMING_BASE_DIR terug, gesorteerd op mtime DESC
// (nieuwste/recentst-gewijzigde map bovenaan). Handig tijdens wedstrijden:
// de relevante map staat steevast bovenaan. Backwards-compat: elke item
// is een object {name, mtime} i.p.v. alleen een string.
if ($action === 'lijst_mappen') {
    // Geblokkeerde mappen — by default verbergen we ze in de live-import
    // dropdown. Met body.toon_geblokkeerd: true levert de API ze toch op
    // (met geblokkeerd-flag), zodat de UI ze als "uitgegrijsd" kan tonen
    // wanneer de gebruiker expliciet "toon geblokkeerd" aanvinkt.
    $toonGeblokkeerd = !empty($body['toon_geblokkeerd']);
    $blokkades = [];
    try {
        $stmt = $pdo->query("SELECT naam FROM upload_map_blokkades");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $blokkades[$n] = true;
        }
    } catch (Throwable $e) { /* tabel bestaat niet — niets blokkeren */ }

    $mappen = [];
    if (is_dir(TIMING_BASE_DIR)) {
        foreach (scandir(TIMING_BASE_DIR) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $pad = TIMING_BASE_DIR . $entry;
            if (!is_dir($pad)) continue;
            $isBlok = isset($blokkades[$entry]);
            if ($isBlok && !$toonGeblokkeerd) continue;  // verbergen by default
            $mappen[] = [
                'name'        => $entry,
                'mtime'       => (int)@filemtime($pad),
                'geblokkeerd' => $isBlok,
            ];
        }
        usort($mappen, fn($a, $b) => $b['mtime'] <=> $a['mtime']); // nieuwste eerst
    }
    echo json_encode(['mappen' => $mappen], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── lijst_uploads ─────────────────────────────────────────────────────────────
// Geeft alle *.csv-bestanden in TIMING_BASE_DIR/{map}/ terug, inclusief mtime
// zodat de frontend zelf kan sorteren (naam / nieuwste). Voor backwards-compat
// blijft `files` een array van objects {name, mtime}.
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
            $files[] = [
                'name'  => basename($path),
                'mtime' => (int)@filemtime($path),
            ];
        }
        // Alfabetische volgorde als default (frontend kan hersorteren op mtime)
        usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    }
    $preselect = '';
    foreach ($files as $f) {
        if (strpos($f['name'], '1') !== false) { $preselect = $f['name']; break; }
    }
    if (!$preselect && count($files)) $preselect = $files[0]['name'];

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

        // Header-varianten ondersteunen (NL + EN timing-software):
        //   pos / #
        //   nr. / nr / no. / no / startnummer
        //   naam / name
        //   tot. tijd / tot.tijd / tijd / total tm / total time / time
        $pos     = (int)($row['pos']       ?? $row['#']           ?? 0);
        $nr      = (int)($row['nr.']       ?? $row['nr']          ?? $row['no.']
                      ?? $row['no']        ?? $row['startnummer'] ?? 0);
        $naam    =       $row['naam']      ?? $row['name']        ?? '';
        $tijdStr =       $row['tot. tijd'] ?? $row['tot.tijd']    ?? $row['tijd']
                      ?? $row['total tm']  ?? $row['total time']  ?? $row['time'] ?? '';

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
        $pdo->beginTransaction();
        // Update op de heat zelf (legacy; nog gebruikt in queries elders).
        $pdo->prepare("UPDATE heats SET race_type = ? WHERE id = ?")->execute([$raceType, $heatId]);
        // Belangrijker: update ook distances.race_type voor de afstand+DC
        // van deze heat. Distances is de canonieke bron en voedt de live-view,
        // uitslag-verwerking, klassement en print-center.
        $pdo->prepare("
            UPDATE distances d
            JOIN heats h ON h.distance_id = d.id
                        AND h.distance_combination_id = d.distance_combination_id
            SET d.race_type = ?
            WHERE h.id = ?
        ")->execute([$raceType, $heatId]);
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
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
