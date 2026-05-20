<?php
// ============================================================
//  InlineComp – genereer startlijst / heats
//
//  GET /api/startlijst_genereer.php
//  Parameters:
//    competition_id  – UUID van de wedstrijd
//    dc_id           – UUID van de afstandscombinatie (categorie)
//    max_per_heat    – max aantal rijders per heat  (default 6)
//    methode         – startnummer | alfabetisch | klassement | tussenklassement
//                      (klassement / vorige_distance: toekomstig)
//
//  Verdeling: zo gelijkmatig mogelijk, grotere heats eerst.
//  Volgorde:  slangenpatroon (snake).
//
//  Riders zonder bevestigde inschrijving (status != 1) worden
//  overgeslagen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'startlijsten')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor startlijsten.']);
    exit;
}

$compId     = trim($_GET['competition_id'] ?? '');
$distId     = trim($_GET['distance_id']    ?? '');  // voor labeling; optioneel bij no-distance DCs
$heatsAantal = max(1, intval($_GET['heats_aantal'] ?? 1));
$methode         = trim($_GET['methode']           ?? 'startnummer');
$klassementId    = trim($_GET['klassement_id']    ?? '');
$klassementSectie= trim($_GET['klassement_sectie']?? '');

// Welke ronde wordt gegenereerd (default: series)
$geldigeRondeTypes = ['heats','kwartfinale','halve_finale','finale','finale_a','finale_b','runner_up'];
$rondeType = trim($_GET['ronde_type'] ?? 'heats');
if (!in_array($rondeType, $geldigeRondeTypes, true)) $rondeType = 'heats';

// dc_ids: kommagescheiden lijst (ondersteunt ook samengevoegde categorieën)
$dcIdsRaw    = trim($_GET['dc_ids'] ?? $_GET['dc_id'] ?? '');
$dcIds       = array_values(array_filter(array_map('trim', explode(',', $dcIdsRaw))));
$primaryDcId = $dcIds[0] ?? '';

// category_filter: optioneel, voor gesplitste DCs (bijv. "DKA,DKB")
$catFilterRaw = trim($_GET['category_filter'] ?? '');
$catFilter    = $catFilterRaw
    ? array_values(array_filter(array_map('trim', explode(',', $catFilterRaw))))
    : [];

if (!$compId || !$dcIds) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id en dc_ids zijn verplicht']);
    exit;
}

try {
    // --------------------------------------------------------
    // 1. Bevestigde deelnemers voor deze categorie(ën) ophalen
    //    Ondersteunt meerdere dc_ids voor samengevoegde categorieën
    // --------------------------------------------------------
    $ph     = implode(',', array_fill(0, count($dcIds), '?'));
    $params = $dcIds;

    // Optioneel filteren op categorieën (voor gesplitste DCs)
    $catWhere = '';
    if ($catFilter) {
        $catPh    = implode(',', array_fill(0, count($catFilter), '?'));
        $catWhere = "AND p.category IN ($catPh)";
        $params   = array_merge($params, $catFilter);
    }

    // Reserves (e.reserve IS NOT NULL) NIET automatisch meenemen — alleen
    // expliciet ingezette reserves (operator zet reserve op NULL via het
    // Reserve-beheer-paneel in de import-module). Voorheen verschenen
    // KNSB-reserves automatisch in de startlijst als ze toevallig op
    // status getekend/bevestigd stonden — dat is fout: een reserve moet
    // pas in de loting na expliciete inzet.
    $stmt = $pdo->prepare("
        SELECT p.license_key, p.full_name, p.short_name,
               p.start_number, p.club_short, p.club_full, p.city, p.category
        FROM entries e
        JOIN persons p ON e.person_license = p.license_key
        WHERE e.distance_combination_id IN ($ph)
          AND e.status IN (1, 5)
          AND e.reserve IS NULL
          $catWhere
    ");
    $stmt->execute($params);
    $rijders = $stmt->fetchAll();

    // Vangnet: rijders die al een uitslag_afstand-rij hebben voor deze DC
    // (dus al feitelijk hebben gereden) worden automatisch meegenomen,
    // ongeacht hun entries.status. In de praktijk blijft `Niet getekend`
    // (status 4) soms per ongeluk staan terwijl de rijder gewoon aan de start
    // kwam — die moet bij de volgende-ronde-loting gewoon weer verschijnen.
    try {
        $extraPh     = implode(',', array_fill(0, count($dcIds), '?'));
        $extraParams = array_merge([$compId], $dcIds);
        $extraWhere  = '';
        if ($catFilter) {
            $catPhX       = implode(',', array_fill(0, count($catFilter), '?'));
            $extraWhere   = " AND p.category IN ($catPhX)";
            $extraParams  = array_merge($extraParams, $catFilter);
        }
        $alBekend = array_fill_keys(array_column($rijders, 'license_key'), true);
        // Reserves uitsluiten: ook in het vangnet — LEFT JOIN met entries
        // zodat we entries.reserve kunnen checken. e.reserve IS NULL = niet
        // reserve (of geen entry-rij = legacy/cross-comp rijder); reserve=N
        // betekent expliciet reserve en moet uitgesloten worden.
        $extraStmt = $pdo->prepare("
            SELECT DISTINCT p.license_key, p.full_name, p.short_name,
                            p.start_number, p.club_short, p.club_full, p.city, p.category
            FROM uitslag_afstand ua
            JOIN persons p ON p.license_key = ua.person_license
            LEFT JOIN entries e
                   ON e.person_license          = ua.person_license
                  AND e.distance_combination_id = ua.distance_combination_id
            WHERE ua.competition_id = ?
              AND ua.distance_combination_id IN ($extraPh)
              AND e.reserve IS NULL
              $extraWhere
        ");
        $extraStmt->execute($extraParams);
        foreach ($extraStmt->fetchAll() as $r) {
            if (isset($alBekend[$r['license_key']])) continue;
            $rijders[] = $r;
            $alBekend[$r['license_key']] = true;
        }
    } catch (Throwable $e) { /* vangnet mag niks stuk maken */ }

    if (empty($rijders)) {
        echo json_encode(['error' => 'Geen bevestigde deelnemers gevonden voor deze categorie']);
        exit;
    }

    // --------------------------------------------------------
    // 2. Transponders ophalen en koppelen
    // --------------------------------------------------------
    $licenseKeys = array_column($rijders, 'license_key');
    $ph          = implode(',', array_fill(0, count($licenseKeys), '?'));
    $params      = array_merge([$compId], $licenseKeys);

    $stmt = $pdo->prepare("
        SELECT person_license, slot, code
        FROM transponders
        WHERE competition_id = ? AND person_license IN ($ph)
        ORDER BY slot
    ");
    $stmt->execute($params);

    $tpMap = [];
    foreach ($stmt->fetchAll() as $tp) {
        $tpMap[$tp['person_license']][$tp['slot']] = $tp['code'];
    }

    foreach ($rijders as &$r) {
        $lk = $r['license_key'];
        $r['transponder_actief'] = $tpMap[$lk][0] ?? null;   // slot 0: voorbereider-keuze
        $r['transponder1']       = $tpMap[$lk][1] ?? null;
        $r['transponder2']       = $tpMap[$lk][2] ?? null;
        $r['transponders_extra'] = [];
        foreach ($tpMap[$lk] ?? [] as $slot => $code) {
            if ($slot >= 3) $r['transponders_extra'][] = $code;
        }
    }
    unset($r);

    // --------------------------------------------------------
    // 3. Sorteren op basis van methode
    //    Rijders zonder positie in sortering → einde, alfabetisch
    //    op achternaam (short_name of laatste woord van full_name)
    // --------------------------------------------------------
    $heeftPositie = [];
    $zonderPositie = [];

    switch ($methode) {
        case 'startnummer':
            foreach ($rijders as $r) {
                if ($r['start_number']) $heeftPositie[] = $r;
                else                   $zonderPositie[] = $r;
            }
            usort($heeftPositie, fn($a,$b) => $a['start_number'] - $b['start_number']);
            break;

        case 'klassement':
            if (!$klassementId || !$klassementSectie) {
                // Fallback naar startnummer als geen klassement gekozen
                $methode = 'startnummer';
                foreach ($rijders as $r) {
                    if ($r['start_number']) $heeftPositie[] = $r;
                    else                   $zonderPositie[] = $r;
                }
                usort($heeftPositie, fn($a,$b) => $a['start_number'] - $b['start_number']);
                break;
            }
            // Klassement-posities ophalen op startnummer
            $klStmt = $pdo->prepare("
                SELECT start_number, positie
                FROM klassement_posities
                WHERE klassement_id = ? AND categorie = ?
                ORDER BY positie ASC
            ");
            $klStmt->execute([$klassementId, $klassementSectie]);
            // Map: start_number → positie (als int voor sortering)
            $klMap = [];
            foreach ($klStmt->fetchAll() as $row) {
                $klMap[(string)$row['start_number']] = (int)$row['positie'];
            }
            // Rijders verdelen: in klassement vs niet
            foreach ($rijders as $r) {
                $sn = (string)($r['start_number'] ?? '');
                if ($sn && isset($klMap[$sn])) {
                    $heeftPositie[] = $r + ['_klPos' => $klMap[$sn]];
                } else {
                    $zonderPositie[] = $r;
                }
            }
            // Klassement-rijders: gesorteerd op rangpositie
            usort($heeftPositie, fn($a,$b) => $a['_klPos'] - $b['_klPos']);
            // Niet in klassement: op startnummer achteraan
            usort($zonderPositie, fn($a,$b) =>
                ($a['start_number'] ?: PHP_INT_MAX) - ($b['start_number'] ?: PHP_INT_MAX));
            break;

        case 'alfabetisch':
            // Sorteren op achternaam (short_name) of laatste woord van full_name
            $heeftPositie = $rijders;
            usort($heeftPositie, fn($a, $b) =>
                strcasecmp(
                    $a['short_name'] ?? (preg_match('/\S+$/', $a['full_name'], $m) ? $m[0] : $a['full_name']),
                    $b['short_name'] ?? (preg_match('/\S+$/', $b['full_name'], $m) ? $m[0] : $b['full_name'])
                )
            );
            break;

        case 'tussenklassement':
            // Bereken tussenklassement dynamisch uit al vastgelegde uitslag_afstand records
            // voor deze competition + DC, exclusief de huidige afstand (die nu geloot wordt).
            // Sortering: laagste puntentotaal eerst (beste); bij gelijke punten beste afzonderlijke rang.
            // Rijders zonder uitslag gaan achteraan, gesorteerd op startnummer.
            $tkDistWhere = $distId ? 'AND distance_id <> ?' : '';
            $tkParams    = $distId
                ? [$compId, $primaryDcId, $distId]
                : [$compId, $primaryDcId];
            // Rijders met uitsluitende sanctie (DQ-SF, DQ-DF, DNS met 0 punten)
            // krijgen geen klassementspositie → achteraan op startnummer
            $tkSql = "
                SELECT   person_license,
                         SUM(CASE WHEN sanctie IN ('DQ-SF','DQ-DF') OR (punten IS NOT NULL AND punten = 0)
                                  THEN 9999 ELSE COALESCE(punten, 9999) END) AS totaal_punten,
                         MIN(COALESCE(rang, 9999)) AS beste_rang,
                         MAX(CASE WHEN sanctie IN ('DQ-SF','DQ-DF') OR (punten IS NOT NULL AND punten = 0)
                                  THEN 1 ELSE 0 END) AS uitgesloten
                FROM     uitslag_afstand
                WHERE    competition_id          = ?
                  AND    distance_combination_id = ?
                  {$tkDistWhere}
                GROUP BY person_license
                ORDER BY uitgesloten ASC, totaal_punten ASC, beste_rang ASC
            ";
            $tkStmt = $pdo->prepare($tkSql);
            $tkStmt->execute($tkParams);
            $tkMap  = [];  // person_license => positie
            $tkUit  = [];  // person_license => true (uitgesloten)
            $tkRank = 1;
            foreach ($tkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int)$row['uitgesloten']) {
                    $tkUit[$row['person_license']] = true;
                } else {
                    $tkMap[$row['person_license']] = $tkRank++;
                }
            }
            foreach ($rijders as $r) {
                $lk = $r['license_key'];
                if (isset($tkMap[$lk])) {
                    $heeftPositie[] = $r + ['_tkPos' => $tkMap[$lk]];
                } else {
                    // Uitgesloten of geen uitslag → achteraan op startnummer
                    $zonderPositie[] = $r;
                }
            }
            usort($heeftPositie, fn($a, $b) => $a['_tkPos'] - $b['_tkPos']);
            usort($zonderPositie, fn($a, $b) =>
                ($a['start_number'] ?: PHP_INT_MAX) - ($b['start_number'] ?: PHP_INT_MAX));
            break;

        default:
            // Onbekende methode → val terug op startnummer (deterministisch, voorspelbaar)
            $methode = 'startnummer';
            foreach ($rijders as $r) {
                if ($r['start_number']) $heeftPositie[] = $r;
                else                    $zonderPositie[] = $r;
            }
            usort($heeftPositie, fn($a,$b) => $a['start_number'] - $b['start_number']);
            break;
    }

    // Rijders zonder positie:
    //   klassement/tussenklassement-methode → al gesorteerd, niet opnieuw sorteren
    //   overige methoden   → alfabetisch op achternaam (rijders zonder startnummer)
    if ($methode !== 'klassement' && $methode !== 'tussenklassement') {
        usort($zonderPositie, fn($a,$b) =>
            strcasecmp(
                $a['short_name'] ?? (preg_match('/\S+$/', $a['full_name'], $m) ? $m[0] : $a['full_name']),
                $b['short_name'] ?? (preg_match('/\S+$/', $b['full_name'], $m) ? $m[0] : $b['full_name'])
            )
        );
    }

    $gesorteerd = array_merge($heeftPositie, $zonderPositie);
    $n          = count($gesorteerd);

    // --------------------------------------------------------
    // 4. Bepaal verdelings-strategie + heat-capaciteiten
    //
    // Default = snake (slangenpatroon): gelijke sterkte per heat. De "extra"
    // rijders (rest na delen) gaan naar de eerste heats — heat 1 is dan de
    // grootste. Goed voor series (heats), waar verspreiding fair is.
    //
    // Tijdkoppeling: voor finale-rondes met meerdere finale-heats (bv. "200m
    // DT" met 3 A-finales). Werkt zoals bij heats→finale-overgang in live.php:
    // zwakste rijders in heat 1, beste in laatste heat. Binnen een heat
    // staat de hoger geklasseerde vooraan (mag baan kiezen). Niet-geklasseer-
    // den komen in heat 1, eerst genoemd op startnummer-volgorde, daarna
    // de zwakste geklasseerde rijders. **De laatste heat (snelste) moet
    // altijd vol zijn** — extras gaan dus naar de LAATSTE heats; alleen
    // heat 1 (zwakste) blijft eventueel onder-bezet.
    //
    // Conditie: ronde-type is finale-variant + methode is (tussen)klassement
    // + finale_seeding van de afstand staat op 'tijdkoppeling'.
    // --------------------------------------------------------
    $rondeIsFinale = in_array($rondeType, ['finale', 'finale_a', 'finale_b'], true);
    $rondeIsHeats  = ($rondeType === 'heats');
    $methodeOpKlassement = in_array($methode, ['klassement', 'tussenklassement'], true);

    // finale_seeding-config is ook van toepassing op de series-ronde (heats)
    // voor formats als 200m DTT (Dual Time-trial), waar zwak→sterk in de
    // series exact zo werkt als in de finale: laatste rit = snelste paar.
    // Default voor reguliere sprint blijft 'slang' = standaard snake.
    $finaleSeeding = 'slang';
    if (($rondeIsFinale || $rondeIsHeats) && $methodeOpKlassement) {
        $tsStmt = $pdo->prepare(
            "SELECT id FROM competition_tijdschema WHERE competition_id = ? LIMIT 1"
        );
        $tsStmt->execute([$compId]);
        $tsId = $tsStmt->fetchColumn();
        if ($tsId && $distId) {
            $afNaamStmt = $pdo->prepare(
                "SELECT name FROM distances WHERE id = ? LIMIT 1"
            );
            $afNaamStmt->execute([$distId]);
            $afstandNaam = $afNaamStmt->fetchColumn();
            if ($afstandNaam) {
                $cfgStmt = $pdo->prepare(
                    "SELECT finale_seeding FROM tijdschema_afstand_config
                     WHERE tijdschema_id = ? AND afstand_naam = ? LIMIT 1"
                );
                $cfgStmt->execute([$tsId, $afstandNaam]);
                $cfgVal = $cfgStmt->fetchColumn();
                if ($cfgVal) $finaleSeeding = $cfgVal;
            }
        }
    }

    $isTijdkoppeling = ($rondeIsFinale || $rondeIsHeats)
                       && $methodeOpKlassement
                       && $finaleSeeding === 'tijdkoppeling';

    $aantalHeats = min($heatsAantal, $n);  // nooit meer heats dan rijders
    $basis       = (int) floor($n / $aantalHeats);
    $extras      = $n % $aantalHeats;

    $heats = [];
    for ($i = 0; $i < $aantalHeats; $i++) {
        // Standaard: extras in de eerste heats (snake-conventie).
        // Tijdkoppeling: extras in de LAATSTE heats — de laatste finale moet
        // altijd vol zijn; alleen de eerste finale mag eventueel kleiner zijn.
        // Voorbeeld 8 finales × 15 rijders: caps = [1, 2, 2, 2, 2, 2, 2, 2].
        $cap = $isTijdkoppeling
            ? ($i < ($aantalHeats - $extras) ? $basis : $basis + 1)
            : ($i < $extras ? $basis + 1 : $basis);
        $heats[] = [
            'nummer'     => $i + 1,
            'capaciteit' => $cap,
            'rijders'    => [],
        ];
    }

    if ($isTijdkoppeling) {
        // ── Tijdkoppeling-blokverdeling ──────────────────────────────────
        // "Zwak naar sterk"-rij: niet-geklasseerden vooraan op startnr ASC,
        // daarna geklasseerden in rang DESC (zwakste rang eerst). Sequentieel
        // verdelen: heat 1 krijgt de eerste cap rijders (zwakste), de laatste
        // heat de laatste cap (sterkste).
        $zwakNaarSterk = array_merge(
            $zonderPositie,                  // al gesorteerd op startnr ASC
            array_reverse($heeftPositie)     // omgekeerd: rang DESC
        );
        $idx = 0;
        foreach ($heats as &$heat) {
            for ($k = 0; $k < $heat['capaciteit'] && $idx < $n; $k++) {
                $heat['rijders'][] = $zwakNaarSterk[$idx++];
            }
        }
        unset($heat);

        // Reorder binnen elke heat: niet-geklasseerden eerst (op startnr ASC),
        // daarna geklasseerden op rang ASC (= beste eerst → mag baan kiezen).
        $rangKey = ($methode === 'tussenklassement') ? '_tkPos' : '_klPos';
        foreach ($heats as &$heat) {
            $ngekl = [];
            $gekl  = [];
            foreach ($heat['rijders'] as $r) {
                if (isset($r[$rangKey])) $gekl[] = $r;
                else                     $ngekl[] = $r;
            }
            usort($ngekl, fn($a, $b) =>
                ($a['start_number'] ?: PHP_INT_MAX) - ($b['start_number'] ?: PHP_INT_MAX));
            usort($gekl, fn($a, $b) => $a[$rangKey] - $b[$rangKey]);
            $heat['rijders'] = array_merge($ngekl, $gekl);
        }
        unset($heat);
    } else {
        // ── Slangenpatroon (default) ─────────────────────────────────────
        // Vooruit H1→Hn, achteruit Hn→H1, herhalen. Volle heats overgeslagen.
        $ri = 0;
        while ($ri < $n) {
            for ($h = 0; $h < $aantalHeats && $ri < $n; $h++) {
                if (count($heats[$h]['rijders']) < $heats[$h]['capaciteit']) {
                    $heats[$h]['rijders'][] = $gesorteerd[$ri++];
                }
            }
            if ($ri >= $n) break;
            for ($h = $aantalHeats - 1; $h >= 0 && $ri < $n; $h--) {
                if (count($heats[$h]['rijders']) < $heats[$h]['capaciteit']) {
                    $heats[$h]['rijders'][] = $gesorteerd[$ri++];
                }
            }
        }
    }

    // --------------------------------------------------------
    // 6. Opslaan in database (heats + heat_entries)
    //    Eerst eventuele bestaande ronde-1 heats verwijderen
    //    (mag alleen als ze nog geen resultaten hebben).
    // --------------------------------------------------------
    $primaryDcId = $dcIds[0];
    $splitGroup  = $catFilter ? implode(',', $catFilter) : null;

    $pdo->prepare("
        DELETE FROM heats
        WHERE competition_id          = ?
          AND distance_combination_id = ?
          AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
          AND (split_group = ? OR (split_group IS NULL AND ? IS NULL))
          AND ronde = 1
    ")->execute([$compId, $primaryDcId, $distId, $distId, $splitGroup, $splitGroup]);

    // Tijdschema_ritten opzoeken voor correcte rit_naam, volgorde en heat_nr
    // Gebruik de ronde_type die via de URL is meegegeven (bijv. 'kwartfinale' voor
    // categorieën die niet met series beginnen).
    // Zoek tijdschema_ritten: probeer eerst op dc_id + distance_id, dan op dc_id alleen
    $dcPh = implode(',', array_fill(0, count($dcIds), '?'));
    $ritStmt = $pdo->prepare("
        SELECT r.id, r.heat_nr, r.volgorde, r.rit_naam
        FROM tijdschema_ritten r
        JOIN competition_tijdschema ts ON ts.id = r.tijdschema_id
        WHERE ts.competition_id = ?
          AND r.dc_id IN ($dcPh)
          AND (r.distance_id = ? OR r.distance_id IS NULL OR ? = '')
          AND r.ronde_type = ?
        ORDER BY r.heat_nr
    ");
    $ritStmt->execute(array_merge([$compId], $dcIds, [$distId, $distId, $rondeType]));
    $rittenMap = []; // heat_nr → { id, volgorde, rit_naam }
    foreach ($ritStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rittenMap[(int)$r['heat_nr']] = $r;
    }

    $insHeat = $pdo->prepare("
        INSERT INTO heats
            (competition_id, distance_combination_id, distance_id,
             split_group, ronde, tijdschema_rit_id, rit_volgorde,
             heat_naam, heat_nr, methode, dc_ids)
        VALUES (?,?,?,?,1,?,?,?,?,?,?)
    ");
    $insEntry = $pdo->prepare("
        INSERT INTO heat_entries (heat_id, person_license, categorie, startpositie, startnummer)
        VALUES (?,?,?,?,?)
    ");

    $dcIdsJson = json_encode($dcIds);
    foreach ($heats as $heat) {
        $hNr     = (int)$heat['nummer'];
        $rit     = $rittenMap[$hNr] ?? null;
        $ritId   = $rit ? (int)$rit['id']       : null;
        $ritVolg = $rit ? (int)$rit['volgorde']  : null;
        $heatNaam = $rit ? $rit['rit_naam'] : "Heat {$hNr}";
        $insHeat->execute([
            $compId, $primaryDcId,
            $distId ?: null,
            $splitGroup,
            $ritId, $ritVolg,
            $heatNaam, $hNr, $methode, $dcIdsJson,
        ]);
        $heatId = (int)$pdo->lastInsertId();
        foreach ($heat['rijders'] as $pos => $r) {
            $insEntry->execute([
                $heatId,
                $r['license_key'],
                $r['category']     ?? null,
                $pos + 1,
                $r['start_number'] ?? null,
            ]);
        }
    }

    // capaciteit is intern; stuur het mee voor info maar rijders is leidend
    echo json_encode([
        'methode'       => $methode,
        'aantalHeats'   => $aantalHeats,
        'totaalRijders' => $n,
        'heats'         => $heats,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
