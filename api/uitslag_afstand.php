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

// ── POST: serie-alleen-startvolgorde toggle vanuit uitslag ───────────────────
// Actie: set_sas {competition_id, dc_ids:[...], distance_id, value:0|1}
// Schrijft rechtstreeks in tijdschema_cat_config.series_alleen_startvolgorde
// zodat er één bron van waarheid is. Voor samengevoegde DC-groepen (merge)
// worden alle betrokken dc_ids meegenomen zodat de instelling consistent blijft.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $cId    = trim($body['competition_id'] ?? '');

    // dc_ids kan komen als array (merged combo) of als enkele dc_id (legacy)
    $dcIdsPost = [];
    if (!empty($body['dc_ids']) && is_array($body['dc_ids'])) {
        $dcIdsPost = array_values(array_filter(array_map('trim', $body['dc_ids'])));
    } elseif (!empty($body['dc_id'])) {
        $dcIdsPost = [trim($body['dc_id'])];
    }
    $dId    = trim($body['distance_id']    ?? '');
    $dNaam  = trim($body['distance_naam']  ?? '');

    if (!$cId || empty($dcIdsPost)) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id en dc_ids zijn verplicht']);
        exit;
    }

    try {
        if ($action === 'set_sas') {
            $val = !empty($body['value']) ? 1 : 0;

            // Vind het tijdschema voor deze competitie
            $tsStmt = $pdo->prepare(
                "SELECT id FROM competition_tijdschema WHERE competition_id = ?"
            );
            $tsStmt->execute([$cId]);
            $tsId = $tsStmt->fetchColumn();
            if (!$tsId) {
                http_response_code(404);
                echo json_encode(['error' => 'Geen tijdschema gevonden']);
                exit;
            }

            $totaalBijgewerkt = 0;
            $problemen = [];
            foreach ($dcIdsPost as $dcId) {
                // Bepaal WELKE cat-config rij(en) de toggle moeten krijgen via
                // een fallback-ladder. SELECT eerst (niet UPDATE + rowCount)
                // omdat MySQL/PDO rowCount=0 geeft als de nieuwe waarde gelijk
                // is aan de bestaande — dan zouden we ten onrechte denken dat
                // de rij niet bestaat.
                $matchIds = [];

                // Stap 1: exacte distance_id
                $s1 = $pdo->prepare("
                    SELECT id FROM tijdschema_cat_config
                    WHERE tijdschema_id = ? AND dc_id = ?
                      AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
                ");
                $s1->execute([$tsId, $dcId, $dId, $dId]);
                $matchIds = $s1->fetchAll(PDO::FETCH_COLUMN);

                // Stap 2a: distance_id via distances-tabel (naam → id)
                if (empty($matchIds) && $dNaam !== '') {
                    $s2 = $pdo->prepare("
                        SELECT cc.id FROM tijdschema_cat_config cc
                        WHERE cc.tijdschema_id = ? AND cc.dc_id = ?
                          AND cc.distance_id IN (
                              SELECT d.id FROM distances d
                              WHERE d.distance_combination_id = ? AND d.name = ?
                          )
                    ");
                    $s2->execute([$tsId, $dcId, $dcId, $dNaam]);
                    $matchIds = $s2->fetchAll(PDO::FETCH_COLUMN);
                }

                // Stap 2b: distance_id via tijdschema_ritten
                if (empty($matchIds) && $dNaam !== '') {
                    $s3 = $pdo->prepare("
                        SELECT cc.id FROM tijdschema_cat_config cc
                        WHERE cc.tijdschema_id = ? AND cc.dc_id = ?
                          AND cc.distance_id IN (
                              SELECT DISTINCT r.distance_id
                              FROM tijdschema_ritten r
                              WHERE r.tijdschema_id = ? AND r.dc_id = ?
                                AND r.afstand_naam = ?
                                AND r.distance_id IS NOT NULL
                          )
                    ");
                    $s3->execute([$tsId, $dcId, $tsId, $dcId, $dNaam]);
                    $matchIds = $s3->fetchAll(PDO::FETCH_COLUMN);
                }

                // Stap 3: als er precies 1 cat-config rij is voor dit dc_id
                if (empty($matchIds)) {
                    $s4 = $pdo->prepare("
                        SELECT id FROM tijdschema_cat_config
                        WHERE tijdschema_id = ? AND dc_id = ?
                    ");
                    $s4->execute([$tsId, $dcId]);
                    $alle = $s4->fetchAll(PDO::FETCH_COLUMN);
                    if (count($alle) === 1) {
                        $matchIds = $alle;
                    } elseif (!empty($alle)) {
                        // Echte mismatch — diagnostics verzamelen
                        $distStmt = $pdo->prepare("
                            SELECT distance_id FROM tijdschema_cat_config
                            WHERE tijdschema_id = ? AND dc_id = ?
                        ");
                        $distStmt->execute([$tsId, $dcId]);
                        $aanwezig = array_map(
                            fn($v) => $v === '' ? '""' : $v,
                            $distStmt->fetchAll(PDO::FETCH_COLUMN)
                        );
                        $problemen[] = "dc_id={$dcId}: " . count($alle)
                                     . " rijen [" . implode(',', $aanwezig) . "]; "
                                     . "zoekt='{$dId}' naam='{$dNaam}' — geen match";
                    }
                    // lege $alle (secondary DC): geen probleem, stilletjes overslaan
                }

                // UPDATE de gevonden rijen via primaire-key (altijd betrouwbaar)
                if (!empty($matchIds)) {
                    $ph = implode(',', array_fill(0, count($matchIds), '?'));
                    $upd = $pdo->prepare("
                        UPDATE tijdschema_cat_config
                        SET series_alleen_startvolgorde = ?
                        WHERE id IN ($ph)
                    ");
                    $upd->execute(array_merge([$val], $matchIds));
                    $totaalBijgewerkt += count($matchIds);
                }
            }

            if ($totaalBijgewerkt === 0) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Geen enkele cat-config bijgewerkt. '
                             . implode('; ', $problemen),
                ]);
                exit;
            }

            echo json_encode([
                'ok' => true, 'value' => $val,
                'rows' => $totaalBijgewerkt,
                'problemen' => $problemen,  // deel-fouten bij merged combos
            ]);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Onbekende action']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

$compId    = trim($_GET['competition_id'] ?? '');
$dcIdsRaw  = trim($_GET['dc_ids'] ?? $_GET['dc_id'] ?? '');
$dcIds     = array_values(array_filter(array_map('trim', explode(',', $dcIdsRaw))));
$primaryDcId = $dcIds[0] ?? '';
$distId    = trim($_GET['distance_id'] ?? '');
$distNaam  = trim($_GET['distance_naam'] ?? '');

if (!$compId || !$primaryDcId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_id zijn verplicht']);
    exit;
}

try {
    // ── Systeem + tijdschema-id bepalen ───────────────────────────────────────
    // tsId wordt zowel in de internationaal- als de full-final-flow gebruikt,
    // dus we halen 'm eenmaal bovenaan op.
    $sysStmt = $pdo->prepare("
        SELECT id, systeem FROM competition_tijdschema WHERE competition_id = ?
    ");
    $sysStmt->execute([$compId]);
    $tsRow   = $sysStmt->fetch(PDO::FETCH_ASSOC);
    $tsId    = $tsRow['id']      ?? null;
    $systeem = $tsRow['systeem'] ?? 'internationaal-nieuw';

    // ── Vastlegging-status van deze (afstand × DC) ─────────────────────────
    // Bedoeld om de "Uitslag bevestigen"-knop te kunnen renderen als
    // "↻ Opnieuw bevestigen" wanneer er al rijen voor deze afstand in
    // uitslag_afstand staan. Eén EXISTS-check is voldoende — hoeveel rijders
    // er precies vastliggen maakt niet uit voor de UI.
    $vlSql = "
        SELECT 1 FROM uitslag_afstand
        WHERE competition_id          = ?
          AND distance_combination_id = ?
    ";
    $vlParams = [$compId, $primaryDcId];
    if ($distId) {
        $vlSql .= " AND distance_id = ?";
        $vlParams[] = $distId;
    }
    $vlSql .= " LIMIT 1";
    $vlStmt = $pdo->prepare($vlSql);
    $vlStmt->execute($vlParams);
    $afstandVastgelegd = (bool)$vlStmt->fetchColumn();

    // Mag de operator nu vastleggen? (alle heats compleet + alle verwachte
    // rondes geloot). Frontend gebruikt dit om de "Uitslag bevestigen"-
    // knop disabled te tonen met heldere reden — backend (uitslag_vastleggen)
    // weigert hetzelfde, dit is alleen voor UX.
    // Split-aware: bij split-DC stuurt frontend dc_naam (= splitnaam) mee
    // zodat alleen DEZE split's ronden meetellen voor de check. Anders zou
    // een andere onafgeronde split de knop disabled houden.
    $splitDcNaam = trim($_GET['dc_naam'] ?? '');
    if ($splitDcNaam !== '') {
        // Alleen activeren als deze naam ook werkelijk als split bestaat
        // (target_group op één van de distances). Voorkomt false-filter bij
        // niet-split DCs die toevallig dezelfde dc_naam meekrijgen.
        $splitChk = $pdo->prepare(
            "SELECT COUNT(*) FROM distances
              WHERE distance_combination_id = ? AND target_group = ?"
        );
        $splitChk->execute([$dcIds[0], $splitDcNaam]);
        if ((int)$splitChk->fetchColumn() === 0) {
            $splitDcNaam = '';  // niet daadwerkelijk een split
        }
    }
    $rondesCheck = alleRondesCompleet(
        $pdo, $compId, $dcIds,
        $distId ?: null,
        $splitDcNaam !== '' ? $splitDcNaam : null
    );
    $rondesCompleet = $rondesCheck['compleet'];
    $rondesReden    = $rondesCheck['reden'];

    if ($systeem !== 'full-final') {
        // ── Internationaal systeem: cascading elimination ranking ─────────

        // race_type (sprint/long_distance) afleiden uit distances.race_type —
        // canonieke bron. distances.race_type = 'sprint' → sprint-sanctie-
        // gedrag; alles anders → long_distance-gedrag (W1/W2 beschikbaar,
        // DNF = reverse withdrawal).
        // Wordt ook gebruikt voor de race-type-aware ranking-defaults hieronder.
        $raceType = 'sprint';
        $raceSubType = 'sprint';
        $afstandMeters = null;
        if ($distId) {
            $drt = $pdo->prepare("
                SELECT race_type, value_meters FROM distances
                WHERE distance_combination_id = ? AND id = ?
                LIMIT 1
            ");
            $drt->execute([$primaryDcId, $distId]);
            $distRow = $drt->fetch(PDO::FETCH_ASSOC);
            $distRt  = $distRow['race_type'] ?? null;
            $afstandMeters = isset($distRow['value_meters']) ? (int)$distRow['value_meters'] : null;
            if ($distRt && $distRt !== 'sprint') $raceType = 'long_distance';
            // Specifiekere subcategorie voor frontend (bv. ranking-keuze
            // verbergen bij afvalkoers — die kent geen tijd-fallback).
            $raceSubType = $distRt ?: 'sprint';
        }

        // Eerste actieve ronde bepalen voor deze categorie — nodig om de
        // sprint-default "eerste ronde = Op tijd" correct te leggen, ongeacht
        // of de cat met series, kwartfinale of halve finale begint. Zelfde
        // detectie-keten als runner-up in tijdschema.php.
        $eersteRonde = null;
        if ($tsId && $primaryDcId) {
            $ccStmt = $pdo->prepare("
                SELECT heeft_heats, heeft_kwartfinale, heeft_halve_finale
                FROM tijdschema_cat_config
                WHERE tijdschema_id = ? AND dc_id = ?
                LIMIT 1
            ");
            $ccStmt->execute([$tsId, $primaryDcId]);
            $cc = $ccStmt->fetch(PDO::FETCH_ASSOC);
            if ($cc) {
                if (!empty($cc['heeft_heats']))            $eersteRonde = 'heats';
                elseif (!empty($cc['heeft_kwartfinale']))  $eersteRonde = 'kwartfinale';
                elseif (!empty($cc['heeft_halve_finale'])) $eersteRonde = 'halve_finale';
            }
        }

        // Race-type-aware ranking-defaults (gebruikt wanneer er nog geen
        // expliciete keuze in tijdschema_afstand_config staat). Opgeslagen
        // voorkeuren krijgen altijd voorrang via de `?? $default`-fallback.
        //
        //  Sprint: eerste actieve ronde (heats/KF/HF afhankelijk van cat)
        //          op tijd, tussenrondes op positie+tijd, A-finale op tijd.
        //  Long distance: voorronden op positie+tijd. De A-finale wordt
        //          sowieso door race_type-regels gesorteerd (rondes/tijd
        //          voor inline+afvalkoers, punten/rondes/tijd voor
        //          puntenkoers); UI verbergt die dropdown — server-side
        //          maakt 'time' vs 'position_time' niet uit, we kiezen
        //          'time' als technische default.
        $isSprint = ($raceType === 'sprint');
        // Sprint-afstanden splitsen op meters:
        //  - 100m     : alle rondes 'time' — WorldSkate Rulebook Art. 113.3
        //               "During the first round, only best times are qualified"
        //               en Art. 114.4 "During all the qualifying round, only
        //               best times are advanced to the following round".
        //  - < 600m   : klassieke sprint-flow (eerste ronde 'time',
        //               tussenrondes 'position_time', finale 'time') — voor
        //               200m DTT / 500m+D waar de bracket-pattern uit posities
        //               volgt.
        //  - ≥ 600m   : alle rondes 'time' (langere sprint waar tijd-meting
        //               leidend is, niet positie + tijd in tussenrondes)
        //  - long_distance : voorronden 'position_time', finale 'time'
        //                    (technisch; A-finale wordt sowieso door
        //                    race_type-regels gesorteerd)
        if ($isSprint && $afstandMeters !== null && ($afstandMeters >= 600 || $afstandMeters === 100)) {
            $rankingMethods = [
                'heats'        => 'time',
                'kwartfinale'  => 'time',
                'halve_finale' => 'time',
                'finale_a'     => 'time',
            ];
        } elseif ($isSprint) {
            $rankingMethods = [
                'heats'        => $eersteRonde === 'heats'        ? 'time' : 'position_time',
                'kwartfinale'  => $eersteRonde === 'kwartfinale'  ? 'time' : 'position_time',
                'halve_finale' => $eersteRonde === 'halve_finale' ? 'time' : 'position_time',
                'finale_a'     => 'time',
            ];
        } else {
            $rankingMethods = [
                'heats'        => 'position_time',
                'kwartfinale'  => 'position_time',
                'halve_finale' => 'position_time',
                'finale_a'     => 'time',
            ];
        }
        // Bewaar defaults apart zodat de frontend bij bevestigen kan
        // vergelijken of de operator een afwijkende keuze heeft gemaakt.
        $rankingDefaults = $rankingMethods;

        if ($tsId) {
            // Bepaal de afstandsnaam. Als de UI expliciet een `distance_naam`-
            // parameter meegeeft (tab-klik op een specifieke afstand), die is
            // leidend — anders pakken we de eerste rit van de DC (legacy).
            // Voorheen altijd de eerste rit: dan werden ranking-instellingen
            // van de tweede afstand binnen dezelfde DC nooit teruggelezen,
            // waardoor het leek alsof opslag niet werkte.
            if ($distNaam !== '') {
                $afNaam = $distNaam;
            } else {
                $afNaamStmt = $pdo->prepare("
                    SELECT afstand_naam FROM tijdschema_ritten
                    WHERE tijdschema_id = ? AND dc_id = ? LIMIT 1
                ");
                $afNaamStmt->execute([$tsId, $primaryDcId]);
                $afNaam = $afNaamStmt->fetchColumn();
            }
            if ($afNaam) {
                // Meters van deze afstand binnen deze DC — om de juiste
                // config-rij te kiezen wanneer één naam meerdere lengtes heeft
                // ("Sprint" 300m/500m). Eén meters-waarde per (dc, naam).
                $amStmt = $pdo->prepare(
                    "SELECT value_meters FROM distances
                     WHERE distance_combination_id = ? AND name = ? LIMIT 1"
                );
                $amStmt->execute([$primaryDcId, $afNaam]);
                $amRaw    = $amStmt->fetchColumn();
                $afMeters = ($amRaw !== false && $amRaw !== null) ? (int)$amRaw : null;

                // Ranking kan per categorie (dc_id) afwijken. Zoek eerst een
                // DC-specifieke rij voor primaryDcId; als die er niet is,
                // gebruik de globale rij (dc_id IS NULL) als fallback.
                // ORDER BY (dc_id IS NULL) ASC zet specifieke rij vóór NULL;
                // (value_meters IS NULL) ASC geeft de meters-exacte rij voorrang
                // boven een oude naam-only rij.
                $acStmt = $pdo->prepare("
                    SELECT heats_ranking, kwart_ranking, half_ranking, finale_ranking
                    FROM tijdschema_afstand_config
                    WHERE tijdschema_id = ? AND afstand_naam = ?
                      AND (dc_id = ? OR dc_id IS NULL)
                      AND (value_meters <=> ? OR value_meters IS NULL)
                    ORDER BY (dc_id IS NULL) ASC, (value_meters IS NULL) ASC
                    LIMIT 1
                ");
                $acStmt->execute([$tsId, $afNaam, $primaryDcId, $afMeters]);
                $ac = $acStmt->fetch(PDO::FETCH_ASSOC);
                if ($ac) {
                    // Opgeslagen voorkeur heeft voorrang; ontbrekend veld → race-type-aware default
                    $rankingMethods['heats']        = $ac['heats_ranking']  ?? $rankingMethods['heats'];
                    $rankingMethods['kwartfinale']   = $ac['kwart_ranking'] ?? $rankingMethods['kwartfinale'];
                    $rankingMethods['halve_finale']  = $ac['half_ranking']  ?? $rankingMethods['halve_finale'];
                    $rankingMethods['finale_a']      = $ac['finale_ranking'] ?? $rankingMethods['finale_a'];
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

        // Rijders per heat laden — bruto_tijd_ms + is_photofinish meenemen
        // zodat berekenInternationaalResultaat() ze kan propageren naar de
        // resultaat-output (basis voor de jury-aanpassings-footnote in print).
        $rijderStmt = $pdo->prepare("
            SELECT he.person_license, p.full_name, p.short_name, p.start_number,
                   p.category AS categorie, res.finishpositie, res.tijd_ms,
                   res.bruto_tijd_ms, res.is_photofinish, res.sanctie,
                   res.rondes, res.punten AS pk_punten, res.afval_rang
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");

        // Groepeer heats per ronde_type. Bij internationaal-nieuw: finale_b
        // = kleine finale (rijders uit voorgaande ronde die niet naar A gingen).
        $rondeGroepen = [];
        $rondeLabels = [
            'heats'        => 'Serie',
            'kwartfinale'  => 'Kwartfinale',
            'halve_finale' => 'Halve finale',
            'finale_a'     => 'A-Finale',
            'finale_b'     => ($systeem === 'internationaal-nieuw') ? 'Kleine finale' : 'B-Finale',
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
                // heat_nr meegeven zodat runner_up per heat geranked kan worden
                // (heat 1 = beste plekken-blok, heat N = slechtste).
                $r['heat_nr']      = (int)$heat['heat_nr'];
                $rondeGroepen[$rt]['rows'][] = $r;
            }
        }

        // Sorteer rondes: finale eerst → series laatst (cascade: beste bovenaan).
        // Runner-up moet ALTIJD vóór z'n bron-ronde komen (= eerste ronde van
        // de keten), zodat de RU-race-uitslag de bron-rang overschrijft.
        // Bron = laagste niet-runner_up ronde die rijen heeft.
        //   chain heats → … → finale + RU  → orde: finale, half, kwart, RU, heats
        //   chain kwart → finale + RU       → orde: finale, half, RU, kwart
        //   chain HF    → finale + RU       → orde: finale, RU, half
        // finale_b (= kleine finale bij internationaal-nieuw) komt direct na
        // A-Finale: rijders daar krijgen plek N+1..N+M (na de A-finalisten),
        // vóór de halve-finale-restant.
        $standaardVolgorde = ['finale_a', 'finale_b', 'halve_finale', 'kwartfinale', 'heats'];
        if (isset($rondeGroepen['runner_up'])) {
            $aanwezig = array_keys($rondeGroepen);
            $bron = null;
            foreach (['heats', 'kwartfinale', 'halve_finale'] as $r) {
                if (in_array($r, $aanwezig, true)) { $bron = $r; break; }
            }
            $nieuw = [];
            $ruIngevoegd = false;
            foreach ($standaardVolgorde as $r) {
                if ($bron && $r === $bron && !$ruIngevoegd) {
                    $nieuw[] = 'runner_up';
                    $ruIngevoegd = true;
                }
                $nieuw[] = $r;
            }
            if (!$ruIngevoegd) $nieuw[] = 'runner_up'; // vangnet
            $standaardVolgorde = $nieuw;
        }
        $rondeVolgorde = array_flip($standaardVolgorde);
        $rondeDataArr = array_values($rondeGroepen);
        usort($rondeDataArr, fn($a, $b) =>
            ($rondeVolgorde[$a['ronde_type']] ?? 5) <=> ($rondeVolgorde[$b['ronde_type']] ?? 5)
        );

        $resultaat = berekenInternationaalResultaat($rondeDataArr, $raceSubType);
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
                           WHEN 'heats'        THEN 'S'
                           WHEN 'kwartfinale'   THEN 'KF'
                           WHEN 'halve_finale'  THEN 'HF'
                           WHEN 'runner_up'     THEN 'RU'
                           WHEN 'finale_a'      THEN 'A-F'
                           WHEN 'finale_b'      THEN CONCAT('B', h.heat_nr, '-F')
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
        // pas na generatie, maar de rondes zijn al geconfigureerd in het tijdschema).
        // Filter óók op afstand_naam zodat ranking-dropdowns alleen verschijnen
        // voor ronden die werkelijk voor déze afstand zijn gepland (niet voor
        // de andere afstand(en) in dezelfde DC).
        $rondeKeys = ['heats' => 'heats', 'kwartfinale' => 'kwart',
                      'halve_finale' => 'half', 'finale_a' => 'finale'];
        $beschikbareRondes = [];
        if ($tsId) {
            $sql = "SELECT DISTINCT r.ronde_type FROM tijdschema_ritten r
                    WHERE r.tijdschema_id = ? AND r.dc_id IN ($dcPh)
                      AND r.ronde_type IN ('heats','kwartfinale','halve_finale','finale_a')";
            $args = array_merge([$tsId], $dcIds);
            if (!empty($afNaam)) {
                $sql .= " AND r.afstand_naam = ?";
                $args[] = $afNaam;
            }
            $sql .= " ORDER BY FIELD(r.ronde_type, 'heats','kwartfinale','halve_finale','finale_a')";
            $brStmt = $pdo->prepare($sql);
            $brStmt->execute($args);
            $beschikbareRondes = array_column($brStmt->fetchAll(PDO::FETCH_ASSOC), 'ronde_type');
        }
        if (empty($beschikbareRondes)) {
            // Fallback: gebruik rondes uit bestaande heats
            foreach (array_keys($rondeGroepen) as $rt) {
                if (isset($rondeKeys[$rt])) $beschikbareRondes[] = $rt;
            }
        }
        $rankingConfig  = [];
        $rankingDefault = [];
        foreach ($rondeKeys as $rt => $key) {
            $rankingConfig[$key]  = $rankingMethods[$rt]  ?? 'time';
            $rankingDefault[$key] = $rankingDefaults[$rt] ?? 'time';
        }

        // Detecteer of rondes/pk_punten relevant zijn
        $heeftRondes   = !empty(array_filter($resultaat, fn($r) => ($r['rondes'] ?? null) !== null));
        $heeftPkPunten = !empty(array_filter($resultaat, fn($r) => ($r['pk_punten'] ?? null) !== null));

        echo json_encode([
            'systeem'       => $systeem,
            'modus'         => 'internationaal',
            'resultaat'     => $resultaat,
            'has_results'   => $hasResults,
            'vastgelegd'    => $afstandVastgelegd,
            'rondes_compleet' => $rondesCompleet,
            'rondes_reden'  => $rondesReden,
            'afstand_naam'  => $afNaam ?? null,
            'rondes'        => $beschikbareRondes,
            'ranking'       => $rankingConfig,
            'ranking_default' => $rankingDefault,
            'afstand_meters' => $afstandMeters,
            'race_type'     => $raceType ?? 'sprint',
            'race_subtype'  => $raceSubType ?? 'sprint',
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
               res.bruto_tijd_ms,
               res.is_photofinish,
               res.sanctie,
               res.rondes,
               res.bruto_rondes,
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

        // Track welke rijders in "overigen" (sanctie/geen finish) vallen — zowel
        // in serie als finale. In gecombineerde modus horen alle rijders in de
        // A-finale; sanctie-rijders moeten als "laatste van de hele afstand"
        // worden gerankt (niet laatste van hun mini-heat als ze in een heat van
        // 1 rijder zitten door eerdere data-anomalie).
        $serieOverigenLics  = [];
        $finaleOverigenLics = [];

        // ── Serie ─────────────────────────────────────────────────────────────
        $serieOffset = 0;
        $serieCompleet = true;
        foreach ($serieHeats as $heat) {
            $rows = $laadRows($heat);
            if (!$rows) { $serieCompleet = false; continue; }
            $rows = sorteerRijdersOpTijd($rows);
            if (!isHeatCompleet($rows)) $serieCompleet = false;
            $nRijders  = count($rows);
            [$finishers, $overigen] = splitsFinishersOverigen($rows);
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
            // Sanctie-rijders: voorlopig heat-local, post-correctie hieronder
            foreach ($overigen as $r) {
                $lic = $r['person_license'];
                $serieRangs[$lic]  = $serieOffset + $nRijders;
                $serieTijden[$lic] = null;
                $serieOverigenLics[$lic] = true;
                // Serie-sanctie meenemen (finale-sanctie overschrijft later indien aanwezig)
                if (!empty($r['sanctie']) && !isset($sancties[$lic])) {
                    $sancties[$lic] = $r['sanctie'];
                }
                $rijderInfo[$lic]  = [
                    'full_name'    => $r['full_name'],
                    'short_name'   => $r['short_name'],
                    'start_number' => $r['start_number'],
                    'categorie'    => $r['categorie'],
                ];
            }
            $serieOffset += $nRijders;
        }
        // Post-correctie series: overigen = laatste van alle serie-rijders
        foreach (array_keys($serieOverigenLics) as $lic) {
            $serieRangs[$lic] = $serieOffset;
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
            [$finishers, $overigen] = splitsFinishersOverigen($rows);
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
            foreach ($overigen as $r) {
                $lic = $r['person_license'];
                $finaleRangs[$lic]  = $finaleOffset + $nRijders;
                $finaleTijden[$lic] = null;
                $finaleOverigenLics[$lic] = true;
                // Finale-sanctie wint van serie-sanctie, maar alleen als er echt
                // een finale-sanctie is (anders kan een pending rijder per ongeluk
                // z'n serie-sanctie kwijtraken).
                if (!empty($r['sanctie'])) {
                    $sancties[$lic] = $r['sanctie'];
                }
                $rijderInfo[$lic] ??= [
                    'full_name'    => $r['full_name'],
                    'short_name'   => $r['short_name'],
                    'start_number' => $r['start_number'],
                    'categorie'    => $r['categorie'],
                ];
            }
            $finaleOffset += $nRijders;
        }
        // Post-correctie finale: overigen = laatste van alle finale-rijders
        // (gecombineerde modus = alleen A-finale → geen heat-lokaal maar totaal)
        foreach (array_keys($finaleOverigenLics) as $lic) {
            $finaleRangs[$lic] = $finaleOffset;
        }

        // Per-cat vlag: serie telt alleen als startvolgorde (full-final variant).
        // Één bron van waarheid: tijdschema_cat_config.series_alleen_startvolgorde.
        // Fallback-ladder (zelfde logica als in de POST):
        //   1. exacte match op distance_id
        //   2. via afstand_naam → tijdschema_ritten → distance_id's (voor splits)
        //   3. als er precies 1 rij is voor dit dc_id, gebruik die
        $distNaamGet = trim($_GET['distance_naam'] ?? '');
        $sasStmt = $pdo->prepare("
            SELECT series_alleen_startvolgorde
            FROM tijdschema_cat_config
            WHERE tijdschema_id = ? AND dc_id = ?
              AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
            LIMIT 1
        ");
        $sasStmt->execute([$tsId, $primaryDcId, $distId, $distId]);
        $sasRaw = $sasStmt->fetchColumn();

        if ($sasRaw === false && $distNaamGet !== '') {
            // Match via distances-tabel (naam → id)
            $sasStmtA = $pdo->prepare("
                SELECT cc.series_alleen_startvolgorde
                FROM tijdschema_cat_config cc
                WHERE cc.tijdschema_id = ? AND cc.dc_id = ?
                  AND cc.distance_id IN (
                      SELECT d.id FROM distances d
                      WHERE d.distance_combination_id = ? AND d.name = ?
                  )
                LIMIT 1
            ");
            $sasStmtA->execute([$tsId, $primaryDcId, $primaryDcId, $distNaamGet]);
            $sasRaw = $sasStmtA->fetchColumn();
        }
        if ($sasRaw === false && $distNaamGet !== '') {
            // Of via tijdschema_ritten (als er ritten bestaan)
            $sasStmt2 = $pdo->prepare("
                SELECT cc.series_alleen_startvolgorde
                FROM tijdschema_cat_config cc
                WHERE cc.tijdschema_id = ? AND cc.dc_id = ?
                  AND cc.distance_id IN (
                      SELECT DISTINCT r.distance_id
                      FROM tijdschema_ritten r
                      WHERE r.tijdschema_id = ? AND r.dc_id = ?
                        AND r.afstand_naam = ?
                        AND r.distance_id IS NOT NULL
                  )
                LIMIT 1
            ");
            $sasStmt2->execute([$tsId, $primaryDcId, $tsId, $primaryDcId, $distNaamGet]);
            $sasRaw = $sasStmt2->fetchColumn();
        }
        if ($sasRaw === false) {
            $sasStmt3 = $pdo->prepare("
                SELECT series_alleen_startvolgorde
                FROM tijdschema_cat_config
                WHERE tijdschema_id = ? AND dc_id = ?
            ");
            $sasStmt3->execute([$tsId, $primaryDcId]);
            $rows = $sasStmt3->fetchAll(PDO::FETCH_COLUMN);
            if (count($rows) === 1) $sasRaw = $rows[0];
        }
        $sasFlag = (bool)(int)($sasRaw === false ? 0 : $sasRaw);

        $gecombineerd = berekenCombineerdResultaat(
            $serieRangs, $finaleRangs, $rijderInfo,
            $serieTijden, $finaleTijden, $sancties,
            $sasFlag
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
                           WHEN 'heats'        THEN 'S'
                           WHEN 'kwartfinale'   THEN 'KF'
                           WHEN 'halve_finale'  THEN 'HF'
                           WHEN 'runner_up'     THEN 'RU'
                           WHEN 'finale_a'      THEN 'A-F'
                           WHEN 'finale_b'      THEN CONCAT('B', h.heat_nr, '-F')
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
            'vastgelegd'   => $afstandVastgelegd,
            'rondes_compleet' => $rondesCompleet,
            'rondes_reden' => $rondesReden,
            'serie_alleen_startvolgorde' => $sasFlag,
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
        [$finishers, $overigen] = splitsFinishersOverigen($rows);

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
                'bruto_tijd_ms' => isset($r['bruto_tijd_ms']) && $r['bruto_tijd_ms'] !== null ? (int)$r['bruto_tijd_ms'] : null,
                'is_photofinish'=> !empty($r['is_photofinish']) ? 1 : 0,
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
                'bruto_tijd_ms' => isset($r['bruto_tijd_ms']) && $r['bruto_tijd_ms'] !== null ? (int)$r['bruto_tijd_ms'] : null,
                'is_photofinish'=> !empty($r['is_photofinish']) ? 1 : 0,
                'sanctie'       => $r['sanctie'],
                'rondes'        => isset($r['rondes']) && $r['rondes'] !== null ? (int)$r['rondes'] : null,
                'pk_punten'     => isset($r['pk_punten']) && $r['pk_punten'] !== null ? (float)$r['pk_punten'] : null,
            ];
        }

        // Nette label bepalen. Bij internationaal-nieuw + finale_b: dat is
        // de kleine finale (rijders uit voorgaande ronde die niet naar A
        // gingen, strijden om plek na A). Bij full-final: klassieke B-finale.
        $rondeType = $heat['ronde_type'];
        if ($rondeType === 'finale_a') {
            $label = 'A-Finale';
        } elseif ($systeem === 'internationaal-nieuw') {
            $label = 'Kleine finale';
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
                       WHEN 'runner_up'     THEN 'Runner-up'
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
        'vastgelegd'        => $afstandVastgelegd,
        'rondes_compleet'   => $rondesCompleet,
        'rondes_reden'      => $rondesReden,
        'heeft_rondes'      => $heeftRondes,
        'heeft_pk_punten'   => $heeftPkPunten,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
