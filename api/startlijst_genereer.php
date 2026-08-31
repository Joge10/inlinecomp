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
require_once __DIR__ . '/_uitslag_helper.php';   // alleRondesCompleet (tussenklassement-compleetheid)
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
// Voor methode 'afstand_uitslag': seed op de uitslag van een ANDERE afstand-DC
// binnen deze wedstrijd (bv. de puntenkoers op de 500m-uitslag).
$bronDcId        = trim($_GET['bron_dc_id']       ?? '');
$bronDistId      = trim($_GET['bron_distance_id'] ?? '');

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
                  AND positie > 0
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
            // Sorteren op short_name (= tussenvoegsel + achternaam, bv
            // "de Blois", "van Deursen", "Vacas"). Fallback: laatste woord
            // van full_name wanneer short_name ontbreekt.
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
            // Alleen COMPLETE afstanden meetellen (spiegelt api/tussenklassement.php):
            // een afstand met een verwijderde/ontbrekende tijd is niet meer
            // compleet → z'n oude uitslag mag de tussenstand niet vervuilen.
            $tkIncSql = ''; $tkIncParams = [];
            try {
                $tkAfStmt = $pdo->prepare(
                    "SELECT DISTINCT distance_id FROM uitslag_afstand
                      WHERE competition_id = ? AND distance_combination_id = ? {$tkDistWhere}");
                $tkAfStmt->execute($tkParams);
                $tkIncompleet = [];
                foreach ($tkAfStmt->fetchAll(PDO::FETCH_COLUMN) as $dId) {
                    $dId = (string)($dId ?? '');
                    if ($dId === '') continue;
                    $chk = alleRondesCompleet($pdo, $compId, [$primaryDcId], $dId);
                    if (empty($chk['compleet'])) $tkIncompleet[] = $dId;
                }
                if ($tkIncompleet) {
                    $ph          = implode(',', array_fill(0, count($tkIncompleet), '?'));
                    $tkIncSql    = "AND (distance_id IS NULL OR distance_id NOT IN ($ph))";
                    $tkIncParams = $tkIncompleet;
                }
            } catch (\Throwable $e) { /* bij twijfel: geen extra uitsluiting */ }
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
                  {$tkIncSql}
                GROUP BY person_license
                ORDER BY uitgesloten ASC, totaal_punten ASC, beste_rang ASC
            ";
            $tkStmt = $pdo->prepare($tkSql);
            $tkStmt->execute(array_merge($tkParams, $tkIncParams));
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

        case 'afstand_uitslag':
            // Seed op de uitslag van een ANDERE afstand-DC binnen dezelfde
            // wedstrijd — bv. de puntenkoers seeden op de 500m-uitslag.
            // Leest uitslag_afstand voor de gekozen bron (DC + optioneel
            // distance), rangschikt op rang (punten is bij PDF-import meestal
            // NULL), matcht op person_license. Rijders zonder uitslag (of met
            // uitsluitende sanctie) gaan achteraan op startnummer.
            if ($bronDcId === '') {
                $methode = 'startnummer';
                foreach ($rijders as $r) {
                    if ($r['start_number']) $heeftPositie[] = $r;
                    else                   $zonderPositie[] = $r;
                }
                usort($heeftPositie, fn($a,$b) => $a['start_number'] - $b['start_number']);
                break;
            }
            $auWhere  = $bronDistId !== '' ? 'AND distance_id = ?' : '';
            $auParams = $bronDistId !== ''
                ? [$compId, $bronDcId, $bronDistId]
                : [$compId, $bronDcId];
            $auSql = "
                SELECT   person_license,
                         MIN(COALESCE(rang, 9999)) AS beste_rang,
                         MAX(CASE WHEN sanctie IN ('DQ-SF','DQ-DF') THEN 1 ELSE 0 END) AS uitgesloten
                FROM     uitslag_afstand
                WHERE    competition_id          = ?
                  AND    distance_combination_id = ?
                  {$auWhere}
                GROUP BY person_license
                ORDER BY uitgesloten ASC, beste_rang ASC
            ";
            $auStmt = $pdo->prepare($auSql);
            $auStmt->execute($auParams);
            $auMap  = [];  // person_license => positie
            $auRank = 1;
            foreach ($auStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int)$row['uitgesloten']) continue;  // uitgesloten → achteraan
                $auMap[$row['person_license']] = $auRank++;
            }
            // ── LIVE fallback: bron-DC zonder vastgelegde uitslag ────────
            // Als geen uitslag_afstand-rijen → gebruik best-tijd-per-rijder
            // uit results (alle reeds gereden rondes van die afstand). Voor
            // in-event seeden tussen afstanden zonder dat de bron al officieel
            // vastgelegd hoeft te zijn.
            if (empty($auMap)) {
                $liveWhere = $bronDistId !== '' ? 'AND h.distance_id = ?' : '';
                $liveParams = $bronDistId !== ''
                    ? [$compId, $bronDcId, $bronDistId]
                    : [$compId, $bronDcId];
                $liveSql = "
                    SELECT he.person_license,
                           MIN(COALESCE(res.bruto_tijd_ms, res.tijd_ms)) AS beste_ms
                    FROM   results              res
                    JOIN   heat_entries         he  ON he.id = res.heat_entry_id
                    JOIN   heats                h   ON h.id  = he.heat_id
                    WHERE  h.competition_id          = ?
                      AND  h.distance_combination_id = ?
                      {$liveWhere}
                      AND  COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
                      AND  (res.sanctie IS NULL
                            OR res.sanctie NOT IN ('DQ-SF','DQ-DF'))
                    GROUP BY he.person_license
                    ORDER BY beste_ms ASC
                ";
                $liveStmt = $pdo->prepare($liveSql);
                $liveStmt->execute($liveParams);
                $auRank = 1;
                foreach ($liveStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $auMap[$row['person_license']] = $auRank++;
                }
            }
            foreach ($rijders as $r) {
                $lk = $r['license_key'];
                if (isset($auMap[$lk])) {
                    $heeftPositie[] = $r + ['_auPos' => $auMap[$lk]];
                } else {
                    $zonderPositie[] = $r;
                }
            }
            usort($heeftPositie, fn($a, $b) => $a['_auPos'] - $b['_auPos']);
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
    //   overige methoden   → alfabetisch op short_name (tussenvoegsel +
    //   achternaam), met fallback laatste woord van full_name.
    if (!in_array($methode, ['klassement', 'tussenklassement', 'afstand_uitslag'], true)) {
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
    $methodeOpKlassement = in_array($methode, ['klassement', 'tussenklassement', 'afstand_uitslag'], true);

    // finale_seeding-config is van toepassing op finale- én series-ronde voor
    // formats als 200m DTT (Dual Time-trial), waar de "laatste rit altijd
    // compleet"-regel ongeacht de seeding-methode geldt. Default voor
    // reguliere sprint blijft 'slang' = standaard snake.
    //
    // BELANGRIJK: deze config NIET koppelen aan $methodeOpKlassement — anders
    // valt 'em terug op 'slang' bij methode = startnummer/alfabet en krijg
    // je weer de oude snake-distributie met heat 1 vol en laatste heat krap.
    $finaleSeeding = 'slang';
    if ($rondeIsFinale || $rondeIsHeats) {
        $tsStmt = $pdo->prepare(
            "SELECT id FROM competition_tijdschema WHERE competition_id = ? LIMIT 1"
        );
        $tsStmt->execute([$compId]);
        $tsId = $tsStmt->fetchColumn();
        if ($tsId && $distId) {
            $afNaamStmt = $pdo->prepare(
                "SELECT name, value_meters FROM distances WHERE id = ? LIMIT 1"
            );
            $afNaamStmt->execute([$distId]);
            $afRow       = $afNaamStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $afstandNaam = $afRow['name'] ?? '';
            $afMeters    = isset($afRow['value_meters']) && $afRow['value_meters'] !== null
                         ? (int)$afRow['value_meters'] : null;
            if ($afstandNaam) {
                // Config-rij voor exact deze (afstand, meters); val terug op de
                // naam-only rij (value_meters IS NULL, oude wedstrijden) als er
                // geen meters-specifieke rij is. Exacte meters wint.
                $cfgStmt = $pdo->prepare(
                    "SELECT finale_seeding FROM tijdschema_afstand_config
                     WHERE tijdschema_id = ? AND afstand_naam = ?
                       AND (value_meters <=> ? OR value_meters IS NULL)
                     ORDER BY (value_meters IS NULL) ASC LIMIT 1"
                );
                $cfgStmt->execute([$tsId, $afstandNaam, $afMeters]);
                $cfgVal = $cfgStmt->fetchColumn();
                if ($cfgVal) $finaleSeeding = $cfgVal;
            }
        }
    }

    // Tijdkoppeling-cap-regel (laatste heat altijd compleet) geldt voor
    // ELKE seeding-methode zolang de afstand op 'tijdkoppeling' staat. De
    // ZWAK→STERK-orderering hangt af van de methode:
    //   • klassement / tussenklassement / afstand_uitslag → reverse op rang
    //     (zwakste rang eerst → heat 1, beste rang → laatste heat)
    //   • startnummer → bestaande volgorde (laagste startnr → heat 1,
    //     hoogste → laatste heat) zodat de cap-regel een duidelijk
    //     deterministisch resultaat geeft
    //   • alfabetisch → bestaande alfabetische volgorde, sequentieel
    //     verdeeld met cap-regel (geen zinvolle sterkte-ordening)
    $isTijdkoppeling = ($rondeIsFinale || $rondeIsHeats)
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
        // Distributie-orde hangt af van de seeding-methode:
        //   • klassement/tussenklassement/afstand_uitslag: niet-geklasseerd
        //     vooraan op startnr ASC + geklasseerd in rang DESC (zwakste
        //     rang eerst) — heat 1 krijgt de zwakste, laatste heat de
        //     beste rijders.
        //   • startnummer/alfabet/anders: gebruik gewoon de gesorteerde
        //     volgorde ($gesorteerd) — laagste startnr/A komt in heat 1,
        //     hoogste/Z in de laatste heat. Geen sterkte-omkering omdat
        //     er geen rang-informatie is.
        //
        // Caps zijn al berekend met "laatste heat altijd vol" (extras in de
        // LAATSTE heats) — die regel geldt voor ALLE methodes.
        if ($methodeOpKlassement) {
            $zwakNaarSterk = array_merge(
                $zonderPositie,                  // al gesorteerd op startnr ASC
                array_reverse($heeftPositie)     // omgekeerd: rang DESC
            );
        } else {
            // Bestaande sortering (startnr ASC, alfabetisch, …) sequentieel
            $zwakNaarSterk = $gesorteerd;
        }
        $idx = 0;
        foreach ($heats as &$heat) {
            for ($k = 0; $k < $heat['capaciteit'] && $idx < $n; $k++) {
                $heat['rijders'][] = $zwakNaarSterk[$idx++];
            }
        }
        unset($heat);

        // Reorder binnen elke heat — alleen relevant bij klassement-methodes,
        // dan zetten we niet-geklasseerden vooraan (op startnr ASC), daarna
        // geklasseerden op rang ASC (beste rang vooraan → mag baan kiezen).
        // Voor startnr/alfabet/anders heeft binnen-heat-reorder geen zin:
        // input is al de gewenste volgorde.
        if ($methodeOpKlassement) {
            $rangKey = ($methode === 'tussenklassement') ? '_tkPos'
                     : (($methode === 'afstand_uitslag') ? '_auPos' : '_klPos');
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
        }
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

        // ── Omgekeerde volgorde bij reverse_slang ────────────────────────
        // Pairs blijven klassiek snake (snelste↔langzaamste), alleen de
        // heat-nummering draait om zodat het snelste paar in de LAATSTE
        // heat komt. Voorschrift bij 100m sprint 2-lane: Art. 114.10-13
        // WorldSkate Rulebook 2026. Werkt op alle rondes die deze functie
        // beheert (heats/kwart/half/finale) via de bestaande
        // finale_seeding-config.
        if ($finaleSeeding === 'reverse_slang') {
            $heats = array_reverse($heats);
            foreach ($heats as $i => &$h) $h['nummer'] = $i + 1;
            unset($h);
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

    // Als er GEEN tijdschema-rit is voor de opgevraagde (dc's × ronde_type),
    // dan hoort deze cat niet in deze ronde te lotten. Zonder deze check
    // maakte de code een orphan heat aan met tijdschema_rit_id=NULL en
    // heat_naam='Heat 1', die de UI vervolgens onder een verkeerde header
    // toonde (bv. Mannen Kadetten 100m: alleen finale_a in tijdschema, maar
    // UI stuurde ronde_type=finale_b → rijder belandde onder 'Kleine finale').
    if (empty($rittenMap)) {
        http_response_code(400);
        echo json_encode([
            'error' => "Interne fout: geen tijdschema-rit voor '$rondeType' "
                     . "bij deze categorie. Ververs de pagina (Ctrl+F5); "
                     . "blijft dit terugkomen dan is het tijdschema gewijzigd "
                     . "sinds deze pagina laadde — 'Wis programma' en het "
                     . "tijdschema opnieuw genereren lost het op."
        ]);
        exit;
    }

    // ── Methode-label snapshot: mensleesbare beschrijving van de loting-
    // bron, opgeslagen per heat zodat het na refresh / vanuit andere browser
    // achterhaalbaar blijft (geen JOIN-lookups nodig in de leesweg). Wordt
    // hieronder bij elke INSERT meegegeven en door startlijst_laden mee-
    // gegeven naar de frontend voor info-balk + print-titel.
    $methodeLabel = null;
    if ($methode === 'startnummer') {
        $methodeLabel = 'Op startnummer';
    } elseif ($methode === 'alfabetisch') {
        $methodeLabel = 'Alfabetisch';
    } elseif ($methode === 'klassement') {
        if ($klassementId) {
            $klStmt = $pdo->prepare(
                "SELECT naam, seizoen FROM klassementen WHERE id = ? LIMIT 1"
            );
            $klStmt->execute([$klassementId]);
            $kl = $klStmt->fetch(PDO::FETCH_ASSOC);
            if ($kl) {
                $methodeLabel = 'Op klassement: ' . $kl['naam']
                    . ($kl['seizoen'] ? ' (' . $kl['seizoen'] . ')' : '')
                    . ($klassementSectie ? ' · sectie ' . $klassementSectie : '');
            } else {
                $methodeLabel = 'Op klassement (klassement niet gevonden)';
            }
        } else {
            $methodeLabel = 'Op klassement (geen klassement gekozen)';
        }
    } elseif ($methode === 'tussenklassement') {
        // Bouw label uit de afstanden die meetelden = uitslag_afstand-rijen
        // voor deze comp+DC, excl. de afstand die nu geloot wordt.
        $tkBasisSql = "
            SELECT DISTINCT distance_naam
              FROM uitslag_afstand
             WHERE competition_id          = ?
               AND distance_combination_id = ?
        " . ($distId ? " AND distance_id <> ?" : "") . "
             ORDER BY distance_naam
        ";
        $tkBasisStmt = $pdo->prepare($tkBasisSql);
        $tkBasisStmt->execute(
            $distId ? [$compId, $primaryDcId, $distId] : [$compId, $primaryDcId]
        );
        $tkBasis = $tkBasisStmt->fetchAll(PDO::FETCH_COLUMN);
        $methodeLabel = 'Tussenklassement deze wedstrijd'
            . ($tkBasis ? ' (basis: ' . implode(', ', $tkBasis) . ')'
                        : ' (nog geen eerdere afstanden vastgelegd)');
    } elseif ($methode === 'afstand_uitslag') {
        // Bron-naam ophalen uit uitslag_afstand zodat operator achteraf
        // optisch kan controleren waarop de loting gebaseerd is.
        // Format: "Op afstand-uitslag: <distance_naam> (<dc_naam>)"
        $auBronSql = "
            SELECT DISTINCT dc_naam, distance_naam
              FROM uitslag_afstand
             WHERE competition_id          = ?
               AND distance_combination_id = ?
        " . ($bronDistId ? " AND distance_id = ?" : "") . "
             LIMIT 1
        ";
        $auBronStmt = $pdo->prepare($auBronSql);
        $auBronStmt->execute(
            $bronDistId ? [$compId, $bronDcId, $bronDistId] : [$compId, $bronDcId]
        );
        $auBron = $auBronStmt->fetch(PDO::FETCH_ASSOC);
        if ($auBron) {
            $dn  = trim($auBron['distance_naam'] ?? '');
            $dcn = trim($auBron['dc_naam'] ?? '');
            $methodeLabel = 'Op afstand-uitslag'
                . ($dn  ? ': ' . $dn : '')
                . ($dcn ? ' (' . $dcn . ')' : '');
        } else {
            $methodeLabel = 'Op afstand-uitslag (bron niet gevonden)';
        }
    } else {
        $methodeLabel = $methode;
    }
    // Veiligheidsmaatregel: kap af op kolombreedte (varchar 255)
    if ($methodeLabel !== null && strlen($methodeLabel) > 255) {
        $methodeLabel = substr($methodeLabel, 0, 252) . '…';
    }

    $insHeat = $pdo->prepare("
        INSERT INTO heats
            (competition_id, distance_combination_id, distance_id,
             split_group, ronde, tijdschema_rit_id, rit_volgorde,
             heat_naam, heat_nr, methode, methode_label, dc_ids)
        VALUES (?,?,?,?,1,?,?,?,?,?,?,?)
    ");
    $insEntry = $pdo->prepare("
        INSERT INTO heat_entries (heat_id, person_license, categorie, startpositie, startnummer)
        VALUES (?,?,?,?,?)
    ");

    $dcIdsJson = json_encode($dcIds);
    // Koppel heats POSITIONEEL aan de resterende ritten (op heat_nr gesorteerd),
    // niet via $rittenMap[$hNr]. Reden: delete_rit laat GATEN in heat_nr achter
    // (verwijder je Serie 2, dan blijven Serie 1 en 3 over — geen hernummering),
    // terwijl deze functie heats contigu 1..N nummert. Een lookup op contigu
    // nummer miste dan de rit met heat_nr 3 → die heat kreeg tijdschema_rit_id =
    // NULL ("Heat 2", zonder categorie) en de resterende rit (heat_nr 3) bleef
    // heatloos. Gevolg: correct in Startlijsten (leest via dc_id) maar een lege
    // "heat 1" in Live-verwerking (leest via tijdschema_rit_id). Positioneel
    // koppelen is gat-immuun en in het normale geval (keys 1..N) identiek aan het
    // oude gedrag ($hNr - 1 == index bij contigue nummering).
    $sortedRitten = array_values($rittenMap); // al op heat_nr gevuld (ORDER BY)
    foreach ($heats as $heat) {
        $hNr     = (int)$heat['nummer'];
        $rit     = $sortedRitten[$hNr - 1] ?? null;
        $ritId   = $rit ? (int)$rit['id']       : null;
        $ritVolg = $rit ? (int)$rit['volgorde']  : null;
        // Sla de ECHTE heat_nr van de gekoppelde rit op zodat heat en rit
        // consistent blijven (en matcht met rit_naam "Serie N"). Zonder rit
        // (over-loting: meer heats dan ritten) → val terug op $hNr.
        $ritHeatNr = $rit ? (int)$rit['heat_nr'] : $hNr;
        $heatNaam = $rit ? $rit['rit_naam'] : "Heat {$hNr}";
        $insHeat->execute([
            $compId, $primaryDcId,
            $distId ?: null,
            $splitGroup,
            $ritId, $ritVolg,
            $heatNaam, $ritHeatNr, $methode, $methodeLabel, $dcIdsJson,
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

    // ── Push (Fase 2): loting van deze DC+ronde is klaar → 1 push per volger ──
    // De rijders zitten in-memory in $heats. DC-naam + afstand halen we op voor
    // een leesbare tekst. Volledig defensief: push mag loting nooit breken.
    try {
        require_once __DIR__ . '/lib_push.php';
        $_pushLics = [];
        foreach ($heats as $_h) {
            foreach (($_h['rijders'] ?? []) as $_r) {
                if (!empty($_r['license_key'])) $_pushLics[] = $_r['license_key'];
            }
        }
        if ($_pushLics) {
            $_q = $pdo->prepare("SELECT name FROM distance_combinations WHERE id = ?");
            $_q->execute([$primaryDcId]);
            $_dcNaam = (string) ($_q->fetchColumn() ?: '');
            $_afNaam = ''; $_afMeters = null;
            if ($distId) {
                $_q = $pdo->prepare("SELECT name, value_meters FROM distances WHERE id = ? AND distance_combination_id = ? LIMIT 1");
                $_q->execute([$distId, $primaryDcId]);
                $_d = $_q->fetch(PDO::FETCH_ASSOC) ?: [];
                $_afNaam = (string) ($_d['name'] ?? ''); $_afMeters = $_d['value_meters'] ?? null;
            }
            // Ronde-label in 4 talen; DC-naam + afstand zijn data (taal-neutraal).
            $_rondeLbls4 = [
                'heats'        => ['nl'=>'Series','en'=>'Heats','de'=>'Vorläufe','fr'=>'Séries'],
                'kwartfinale'  => ['nl'=>'Kwartfinale','en'=>'Quarterfinal','de'=>'Viertelfinale','fr'=>'Quart de finale'],
                'halve_finale' => ['nl'=>'Halve finale','en'=>'Semifinal','de'=>'Halbfinale','fr'=>'Demi-finale'],
                'finale'       => ['nl'=>'Finale','en'=>'Final','de'=>'Finale','fr'=>'Finale'],
                'finale_a'     => ['nl'=>'A-finale','en'=>'A final','de'=>'A-Finale','fr'=>'Finale A'],
                'finale_b'     => ['nl'=>'B-finale','en'=>'B final','de'=>'B-Finale','fr'=>'Finale B'],
                'runner_up'    => ['nl'=>'Runner-up','en'=>'Runner-up','de'=>'Runner-up','fr'=>'Runner-up'],
            ];
            $_afstand = trim($_afNaam . ($_afMeters ? ' ' . $_afMeters . 'm' : ''));
            $_dcAf    = trim($_dcNaam . ($_afstand !== '' ? ' · ' . $_afstand : ''), " ·");
            $_ctx = [];
            foreach (['nl', 'en', 'de', 'fr'] as $_L) {
                $_ronde = $_rondeLbls4[$rondeType][$_L] ?? ($_rondeLbls4[$rondeType]['nl'] ?? $rondeType);
                $_ctx[$_L] = trim($_dcAf . ' · ' . $_ronde, " ·");
            }
            // context (DC · afstand · ronde) per taal; de flush zet per abonnement
            // de naam/namen van díe volgers z'n rijders er met " — " vóór.
            pushEnqueue($pdo, 'loting', $_pushLics, [
                'title'   => _pushTitel('loting'),
                'context' => $_ctx,
                'url'     => './?comp=' . rawurlencode($compId),   // deep-link naar de wedstrijd (gevolgde rijders laden auto)
                'tag'     => 'loting-' . $primaryDcId . '-' . $rondeType,
            ]);
            pushFlushOutbox($pdo, 15, true);   // meteen versturen (force, defensief)
        }
    } catch (\Throwable $e) { /* push mag loting nooit breken */ }

    // capaciteit is intern; stuur het mee voor info maar rijders is leidend
    echo json_encode([
        'methode'       => $methode,
        'methode_label' => $methodeLabel,
        'aantalHeats'   => $aantalHeats,
        'totaalRijders' => $n,
        'heats'         => $heats,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
