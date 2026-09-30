<?php
// api/public_ronde_uitslagen.php
// Endpoint: per-ronde uitslag (series/kwartfinale/etc).
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'ronde_uitslagen') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');
    $compId    = trim($_GET['competition_id'] ?? '');
    $dcId      = trim($_GET['dc_id'] ?? '');
    // license_key: optionele filter. Als meegegeven → alleen rondes tonen
    // waar deze rijder in zit. Zonder license: alle rondes (admin-preview).
    $rijderLic = trim($_GET['license_key'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }

    try {
        // Anonimiteit (variant B): publiek rondes-overzicht → public-venster.
        [$cStarts, $cEnds] = anoniemCompVenster($pdo, $compId);

        // Wedstrijdsysteem ophalen (bepaalt label 'B-finale' vs 'Kleine finale').
        $sysStmt = $pdo->prepare("SELECT systeem FROM competition_tijdschema WHERE competition_id = ? LIMIT 1");
        $sysStmt->execute([$compId]);
        $systeem = $sysStmt->fetchColumn() ?: 'internationaal-nieuw';

        // 1) Afstanden van deze DC in programma-volgorde.
        $distStmt = $pdo->prepare("
            SELECT d.id, d.name, d.value_meters, d.race_type, d.number,
                   v.prog_volgorde
            FROM distances d
            LEFT JOIN (
                SELECT tr.dc_id, tr.distance_id, MIN(tr.volgorde) AS prog_volgorde
                FROM tijdschema_ritten tr
                JOIN competition_tijdschema ct ON ct.id = tr.tijdschema_id
                WHERE ct.competition_id = ?
                GROUP BY tr.dc_id, tr.distance_id
            ) v ON v.dc_id = d.distance_combination_id AND v.distance_id = d.id
            WHERE d.distance_combination_id = ?
            ORDER BY v.prog_volgorde IS NULL, v.prog_volgorde, d.number, d.name
        ");
        $distStmt->execute([$compId, $dcId]);
        $distances = $distStmt->fetchAll(PDO::FETCH_ASSOC);

        // 1b) finale_ranking per afstand ophalen. Bepaalt de A-finale
        // sortering in de rondes-tab: dezelfde instelling als de Uitslag-
        // module in admin gebruikt. 'time' = puur op tijd (correct bij
        // 200m DTT / tijdkoppeling); 'position_time' = op finishpositie
        // met tijd als tiebreak (standaard).
        // Fallback-regel: dc-specifiek → dc_id IS NULL → 'position_time'.
        $seedStmt = $pdo->prepare("
            SELECT afstand_naam, value_meters, dc_id, finale_ranking
            FROM tijdschema_afstand_config tac
            JOIN competition_tijdschema ct ON ct.id = tac.tijdschema_id
            WHERE ct.competition_id = ? AND (tac.dc_id = ? OR tac.dc_id IS NULL)
        ");
        $seedStmt->execute([$compId, $dcId]);
        // Keyed op "naam\x1fmeters" ("Sprint" 300m/500m los); $rankingMapNaam is
        // de naam-only fallback (oude config zonder value_meters). Dc-specifiek wint.
        $rankingMap     = [];
        $rankingMapNaam = [];
        foreach ($seedStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $an  = $s['afstand_naam'];
            $m   = $s['value_meters'] !== null ? (int)$s['value_meters'] : null;
            $key = $an . "\x1f" . ($m ?? '');
            // dc-specifiek overrulet null-fallback
            if (!isset($rankingMap[$key]) || $s['dc_id'] !== null) {
                $rankingMap[$key] = $s['finale_ranking'];
            }
            if (!isset($rankingMapNaam[$an]) || $s['dc_id'] !== null) {
                $rankingMapNaam[$an] = $s['finale_ranking'];
            }
        }

        // 2) catConfig ophalen (voor Q/q + finale-heat-grootte + runner-up).
        $ccStmt = $pdo->prepare("
            SELECT * FROM tijdschema_cat_config cc
            JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
            WHERE ct.competition_id = ? AND cc.dc_id = ?
        ");
        $ccStmt->execute([$compId, $dcId]);
        $catConfigs = [];
        foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
            $catConfigs[$cc['distance_id']] = $cc;
        }

        // 3) Query voor rijders per heat (incl. bruto + is_photofinish).
        $heatRijStmt = $pdo->prepare("
            SELECT h.id AS heat_id, h.heat_nr,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   he.person_id AS person_license, he.startpositie,
                   p.person_id, p.publiek_anoniem,
                   p.full_name, p.category AS categorie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   res.tijd_ms, res.bruto_tijd_ms, res.is_photofinish,
                   res.sanctie, res.finishpositie,
                   res.rondes, res.punten AS pk_punten
            FROM heats h
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            JOIN heat_entries he ON he.heat_id = h.id
            JOIN persons p ON p.person_id = he.person_id
            LEFT JOIN competition_startnummers cs
                ON cs.person_id = he.person_id AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE h.competition_id = ?
              AND h.distance_combination_id = ?
              AND COALESCE(h.distance_id, tsr.distance_id) = ?
            ORDER BY h.heat_nr, he.startpositie
        ");

        // 4) Eind-uitslag per distance uit uitslag_afstand.
        $eindStmt = $pdo->prepare("
            SELECT ua.rang, ua.tijd_ms, ua.sanctie, ua.punten, ua.finale_naam,
                   ua.person_id AS person_license,
                   p.person_id, p.publiek_anoniem,
                   p.full_name, COALESCE(cs.startnummer, p.start_number) AS snr
            FROM uitslag_afstand ua
            JOIN persons p ON p.person_id = ua.person_id
            LEFT JOIN competition_startnummers cs
                ON cs.person_id = ua.person_id AND cs.competition_id = ?
            WHERE ua.competition_id = ?
              AND ua.distance_combination_id = ?
              AND ua.distance_id = ?
            ORDER BY ua.rang IS NULL, ua.rang
        ");

        $RONDE_VOLGORDE = ['heats' => 1, 'kwartfinale' => 2, 'halve_finale' => 3, 'runner_up' => 4, 'finale_a' => 5, 'finale_b' => 6];
        // finale_b heet 'Kleine finale' in het internationaal-nieuw systeem
        // (verliezers uit voorgaande ronde strijden om plek na A) en 'B-finale'
        // bij full-final (klassieke rest-finale op series-tijd).
        $finaleBLabel = ($systeem === 'internationaal-nieuw') ? 'Kleine finale' : 'B-finale';
        $RONDE_LABEL    = ['heats' => 'Serie', 'kwartfinale' => 'Kwartfinale', 'halve_finale' => 'Halve finale', 'runner_up' => 'Runner-up', 'finale_a' => 'A-finale', 'finale_b' => $finaleBLabel];

        // Doorstroom-detectie: per rijder bepalen in WELKE volgende ronde/heat
        // ze zitten. Bij full-final krijgt iedereen een Q of q maar sommigen
        // gaan naar B1/B2/… — dat willen we in de badge zichtbaar maken.
        // Bouwt een map [distance_id][ronde_type][person_license] => doelabel
        // (A, B1, B2, RU, …).
        $doorstrKortLabel = function(string $rondeType, ?int $heatNr): string {
            if ($rondeType === 'finale_a')  return 'A';
            if ($rondeType === 'finale_b')  return 'B' . ($heatNr ?? 1);
            if ($rondeType === 'runner_up') return 'RU' . ($heatNr ?? 1);
            if ($rondeType === 'kwartfinale')  return 'KF';
            if ($rondeType === 'halve_finale') return 'HF';
            return '';
        };

        $out = [];
        foreach ($distances as $dist) {
            $distId = $dist['id'];
            $cc     = $catConfigs[$distId] ?? [];

            // Rijders ophalen + groeperen per ronde_type. Anonimiteit wordt pas
            // in de normalisatie-loop hieronder toegepast — de Q/doorstroom-logica
            // leunt nog op person_license (= person_id), en maskeren strípt die.
            $heatRijStmt->execute([$compId, $compId, $dcId, $distId]);
            $rows = $heatRijStmt->fetchAll(PDO::FETCH_ASSOC);
            $perRonde = [];
            foreach ($rows as $r) {
                $rt = $r['ronde_type'];
                if (!isset($perRonde[$rt])) $perRonde[$rt] = [];
                $perRonde[$rt][] = $r;
            }

            // Sorteer ronde-types naar programma-volgorde
            $rondeTypes = array_keys($perRonde);
            usort($rondeTypes, fn($a, $b) => ($RONDE_VOLGORDE[$a] ?? 99) - ($RONDE_VOLGORDE[$b] ?? 99));

            // Doorstroom-map: voor elke ronde X → per persoon het label van
            // hun eerst-volgende ronde-heat (A / B1 / B2 / RU1 / …). Bouwt
            // O(N²/2) over rondes maar N is klein (< 6 rondes per distance).
            $doorstroomPerRondePersoon = [];  // [rondeType][person_license] => label
            foreach ($rondeTypes as $rtIdx => $rt) {
                $vol = $RONDE_VOLGORDE[$rt] ?? 99;
                $doorstroomPerRondePersoon[$rt] = [];
                foreach ($rondeTypes as $laterRt) {
                    if (($RONDE_VOLGORDE[$laterRt] ?? 99) <= $vol) continue;
                    foreach ($perRonde[$laterRt] as $laterR) {
                        $lic = $laterR['person_license'];
                        if (isset($doorstroomPerRondePersoon[$rt][$lic])) continue; // eerste vondst wint
                        $label = $doorstrKortLabel($laterRt, (int)$laterR['heat_nr']);
                        if ($label !== '') $doorstroomPerRondePersoon[$rt][$lic] = $label;
                    }
                }
            }

            $rondes = [];
            foreach ($rondeTypes as $rt) {
                $rondeRijders = $perRonde[$rt];
                if (!count($rondeRijders)) continue;

                // Filter: als een license is meegegeven, alleen rondes tonen
                // waar deze rijder zelf in een heat zit. Rijders vallen soms
                // vroeg uit (bv. na series alleen A-finale-doorstromers) en
                // dan zijn de latere rondes voor hun eigen overzicht ruis.
                if ($rijderLic !== '') {
                    $eigenHeatNr = null;
                    foreach ($rondeRijders as $r) {
                        if ($r['person_license'] === $rijderLic) {
                            $eigenHeatNr = $r['heat_nr']; break;
                        }
                    }
                    if ($eigenHeatNr === null) continue;
                    // Bij B-finale / Runner-up: er zijn meerdere heats (B1/B2,
                    // RU1/RU2) — toon alleen de heat waar de rijder zelf in
                    // zit. Alle andere B-/RU-heats zijn ruis voor deze rijder.
                    if ($rt === 'finale_b' || $rt === 'runner_up') {
                        $rondeRijders = array_values(array_filter(
                            $rondeRijders,
                            fn($r) => $r['heat_nr'] === $eigenHeatNr
                        ));
                    }
                }

                // Compleetheid: alle rijders hebben tijd of sanctie.
                $compleet = true;
                foreach ($rondeRijders as $r) {
                    if ($r['tijd_ms'] === null && !$r['sanctie']) { $compleet = false; break; }
                }

                // Bereken Q/q voor doorstroom-rondes (heats/KF/HF).
                $qPerHeat = 0; $totaalDoor = 0;
                if ($rt === 'heats')        { $qPerHeat = (int)($cc['heats_q_heat'] ?? 0); $totaalDoor = (int)($cc['heats_q'] ?? 0); }
                elseif ($rt === 'kwartfinale')  { $qPerHeat = (int)($cc['kwart_q_heat'] ?? 1); $totaalDoor = (int)($cc['kwart_door'] ?? 0); }
                elseif ($rt === 'halve_finale') { $qPerHeat = (int)($cc['half_q_heat'] ?? 1);  $totaalDoor = (int)($cc['half_door'] ?? 0); }

                // Rijders per heat groeperen voor Q-bepaling
                $UITVAL_SANC = ['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF'];
                $isUitval = function($s) use ($UITVAL_SANC) {
                    if (!$s) return false;
                    foreach (explode(',', $s) as $c) {
                        $c = strtoupper(trim($c));
                        if (in_array($c, $UITVAL_SANC, true)) return true;
                    }
                    return false;
                };
                $qRijders = [];
                $qTijdRijders = [];
                if ($compleet && $totaalDoor > 0) {
                    $perHeat = [];
                    foreach ($rondeRijders as $r) {
                        $hk = $r['heat_nr'];
                        if (!isset($perHeat[$hk])) $perHeat[$hk] = [];
                        $perHeat[$hk][] = $r;
                    }
                    foreach ($perHeat as &$hr) {
                        usort($hr, fn($a, $b) => ($a['finishpositie'] ?? 999) - ($b['finishpositie'] ?? 999));
                    }
                    unset($hr);
                    // Q per heat: eerste qPerHeat finishers (excl. uitval)
                    if ($qPerHeat > 0) {
                        foreach ($perHeat as $hr) {
                            $teller = 0;
                            foreach ($hr as $r) {
                                if ($teller >= $qPerHeat) break;
                                if ($r['finishpositie'] !== null && !$isUitval($r['sanctie'])) {
                                    $qRijders[$r['person_license']] = true;
                                    $teller++;
                                }
                            }
                        }
                        // Ex-aequo Q (per heat): als de laatste Q-rijder van een
                        // heat exact dezelfde tijd heeft als de eerstvolgende
                        // rijder(s), gaan die ook door (overflow) — spiegelt de
                        // backend (live.php genereer_volgende_ronde). Zonder dit
                        // toont het publieke overzicht zo'n ex-aequo-rijder niet
                        // als gekwalificeerd terwijl hij wél in de volgende ronde zit.
                        foreach ($perHeat as $hr) {
                            $grens = $hr[$qPerHeat - 1] ?? null;
                            if (!$grens || $grens['tijd_ms'] === null) continue;
                            for ($i = $qPerHeat; $i < count($hr); $i++) {
                                if ($hr[$i]['tijd_ms'] !== null
                                        && (int)$hr[$i]['tijd_ms'] === (int)$grens['tijd_ms']
                                        && !$isUitval($hr[$i]['sanctie']))
                                    $qRijders[$hr[$i]['person_license']] = true;
                                else break;
                            }
                        }
                    }
                    // q op tijd: snelste van de niet-Q, niet-uitval
                    $aantalQ = count($qRijders);
                    $aantalq = max(0, $totaalDoor - $aantalQ);
                    if ($aantalq > 0) {
                        $metTijd = array_filter($rondeRijders, fn($r) =>
                            $r['tijd_ms'] !== null
                            && !isset($qRijders[$r['person_license']])
                            && !$isUitval($r['sanctie'])
                        );
                        usort($metTijd, fn($a, $b) => $a['tijd_ms'] - $b['tijd_ms']);
                        $metTijd = array_values($metTijd);
                        for ($i = 0; $i < min($aantalq, count($metTijd)); $i++) {
                            $qTijdRijders[$metTijd[$i]['person_license']] = true;
                        }
                        // Ex-aequo op grenstijd meepakken
                        if ($aantalq < count($metTijd) && ($metTijd[$aantalq - 1] ?? null)) {
                            $grens = $metTijd[$aantalq - 1]['tijd_ms'];
                            for ($i = $aantalq; $i < count($metTijd); $i++) {
                                if ($metTijd[$i]['tijd_ms'] === $grens) $qTijdRijders[$metTijd[$i]['person_license']] = true;
                                else break;
                            }
                        }
                    }
                }

                // Runner-up start-positie = aantal rijders in de eerst-
                // VOLGENDE ronde na de EERSTE gereden ronde + 1. RU is voor
                // uitvallers na de eerste ronde; de eerste ronde is niet
                // altijd 'heats' (kleinere wedstrijden beginnen soms met HF).
                //   heats → KF → …    : RU-start = |KF| + 1  (bv 16+1=17)
                //   heats → A(+B)     : RU-start = |A|+|B| + 1
                //   HF → A(+B)        : RU-start = |A|+|B| + 1  (HF was eerste)
                // Meerdere RU-heats (RU-1, RU-2, …) tellen cumulatief door
                // op tijd — dat regelt de RU-sorteer-loop hieronder.
                $ruStartPos = null;
                if ($rt === 'runner_up') {
                    // Volgorde van rondes die daadwerkelijk plaatsen toekennen
                    // (RU zelf niet meegerekend; die krijgt zijn plaats HIER).
                    $plaatsVolgorde = ['heats', 'kwartfinale', 'halve_finale', 'finale_a', 'finale_b'];
                    $eerste = null;
                    foreach ($plaatsVolgorde as $r) {
                        if (isset($perRonde[$r])) { $eerste = $r; break; }
                    }
                    $volgend = null;
                    $naEerste = false;
                    foreach ($plaatsVolgorde as $r) {
                        if ($r === $eerste) { $naEerste = true; continue; }
                        if ($naEerste && isset($perRonde[$r])) { $volgend = $r; break; }
                    }
                    if ($volgend === 'finale_a') {
                        // A + B parallel: doorstromers verdelen over beide.
                        $nA = count($perRonde['finale_a']);
                        $nB = isset($perRonde['finale_b']) ? count($perRonde['finale_b']) : 0;
                        $ruStartPos = $nA + $nB + 1;
                    } elseif ($volgend !== null) {
                        // KF of HF (of edge case finale_b zonder A)
                        $ruStartPos = count($perRonde[$volgend]) + 1;
                    } else {
                        // Geen ronde na de eerste? Rare setup; fallback 1.
                        $ruStartPos = 1;
                    }
                }

                // Verrijk elke rijder met kwal + doorstroom + eind_positie
                $ds = $doorstroomPerRondePersoon[$rt] ?? [];
                foreach ($rondeRijders as &$r) {
                    $r['kwal'] = '';
                    if (isset($qRijders[$r['person_license']]))    $r['kwal'] = 'Q';
                    elseif (isset($qTijdRijders[$r['person_license']])) $r['kwal'] = 'q';
                    $r['doorstroom_label'] = $ds[$r['person_license']] ?? null;
                    $r['ru_positie'] = null;
                }
                unset($r);

                // Runner-up eind-positie berekenen: per heat sorteren, dan
                // cumulatief nummeren over meerdere RU-heats.
                if ($rt === 'runner_up' && $ruStartPos) {
                    $perHeat = [];
                    foreach ($rondeRijders as $r) {
                        $hk = $r['heat_nr'] ?? 1;
                        if (!isset($perHeat[$hk])) $perHeat[$hk] = [];
                        $perHeat[$hk][] = $r;
                    }
                    ksort($perHeat, SORT_NUMERIC);
                    // Binnen elke heat: op tijd (uitval onderaan)
                    $volgendePos = $ruStartPos;
                    foreach ($perHeat as $hk => &$hr) {
                        usort($hr, function($a, $b) use ($isUitval) {
                            $aOk = $a['tijd_ms'] !== null && !$isUitval($a['sanctie']);
                            $bOk = $b['tijd_ms'] !== null && !$isUitval($b['sanctie']);
                            if ($aOk !== $bOk) return $aOk ? -1 : 1;
                            if ($aOk) return $a['tijd_ms'] - $b['tijd_ms'];
                            return ($a['startpositie'] ?? 999) - ($b['startpositie'] ?? 999);
                        });
                        foreach ($hr as $r) {
                            // Update in de master-array
                            foreach ($rondeRijders as &$mr) {
                                if ($mr['person_license'] === $r['person_license']
                                    && $mr['heat_nr'] === $r['heat_nr']) {
                                    $mr['ru_positie'] = $volgendePos++;
                                    break;
                                }
                            }
                            unset($mr);
                        }
                    }
                    unset($hr);
                }

                // Type-casten voor JSON
                foreach ($rondeRijders as &$r) {
                    $r['tijd_ms']       = $r['tijd_ms']       !== null ? (int)$r['tijd_ms']       : null;
                    $r['bruto_tijd_ms'] = $r['bruto_tijd_ms'] !== null ? (int)$r['bruto_tijd_ms'] : null;
                    $r['finishpositie'] = $r['finishpositie'] !== null ? (int)$r['finishpositie'] : null;
                    $r['heat_nr']       = $r['heat_nr']       !== null ? (int)$r['heat_nr']       : null;
                    $r['snr']           = $r['snr']           !== null ? (string)$r['snr']        : null;
                    $r['is_photofinish']= (int)($r['is_photofinish'] ?? 0);
                    $r['rondes']        = $r['rondes']        !== null ? (int)$r['rondes']       : null;
                    $r['pk_punten']     = $r['pk_punten']     !== null ? (float)$r['pk_punten']  : null;
                    unset($r['startpositie']);
                    // Anonimiteit (public-venster) — ná de Q/doorstroom-logica,
                    // die op person_license leunde; maskeren strípt die token nu.
                    $r = pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
                }
                unset($r);

                $rondes[] = [
                    'ronde_type'  => $rt,
                    'ronde_label' => $RONDE_LABEL[$rt] ?? $rt,
                    'compleet'    => $compleet,
                    'aantal'      => count($rondeRijders),
                    'rijders'     => $rondeRijders,
                ];
            }

            // Eind-uitslag uit uitslag_afstand — alleen als de rijder erin
            // zit (of geen license-filter). Zonder rijder in eind-uitslag:
            // lege array, maar afstand blijft behouden als er rondes zijn.
            $eindStmt->execute([$compId, $compId, $dcId, $distId]);
            $eind = $eindStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rijderLic !== '') {
                $rijderInEind = false;
                foreach ($eind as $e) {
                    if ($e['person_license'] === $rijderLic) { $rijderInEind = true; break; }
                }
                if (!$rijderInEind) $eind = [];
            }
            foreach ($eind as &$e) {
                $e['rang']    = $e['rang']    !== null ? (int)$e['rang']    : null;
                $e['tijd_ms'] = $e['tijd_ms'] !== null ? (int)$e['tijd_ms'] : null;
                $e['punten']  = $e['punten']  !== null ? (float)$e['punten'] : null;
                $e['snr']     = $e['snr']     !== null ? (string)$e['snr']  : null;
                $e = pasAnonimiteitToe($e, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
            }
            unset($e);

            // Skip hele afstand als de rijder geen rondes én geen eind-uitslag
            // heeft (irrelevant voor deze rijder).
            if ($rijderLic !== '' && !count($rondes) && !count($eind)) continue;

            $out[] = [
                'distance_id'    => $dist['id'],
                'distance_naam'  => $dist['name'],
                'distance_meters'=> $dist['value_meters'] !== null ? (int)$dist['value_meters'] : null,
                'race_type'      => $dist['race_type'],
                'finale_ranking' => $rankingMap[$dist['name'] . "\x1f" . ($dist['value_meters'] !== null ? (int)$dist['value_meters'] : '')]
                                    ?? $rankingMapNaam[$dist['name']] ?? 'position_time',
                'rondes'         => $rondes,
                'eind_uitslag'   => $eind,
            ];
        }

        echo json_encode(['distances' => $out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: serie-klassementen waar deze wedstrijd aan meedoet ─────────────────
