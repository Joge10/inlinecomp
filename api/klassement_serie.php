<?php
// ============================================================
//  InlineComp – klassement-series: klassement berekenen uit
//  eigen wedstrijden (i.p.v. PDF-import). Gebruikt dezelfde
//  `klassementen` + `klassement_posities` tabellen zodat de
//  bestaande seeding-integratie automatisch werkt.
//
//  GET  ?action=list&org_id=UUID       → alle series
//  GET  ?action=get&id=UUID            → serie + regels + wedstrijden
//  POST ?action=create                 → nieuwe serie + eerste berekening
//       {naam, seizoen, org_id, regels, wedstrijden:[{competition_id, telt_mee, volgorde}]}
//  POST ?action=update&id=UUID         → regels/wedstrijden bijwerken + herberekenen
//  POST ?action=berekenen&id=UUID      → alleen herberekenen
//  POST ?action=delete&id=UUID         → verwijder (cascade: ook klassement + posities)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && !kanSchrijven($_authUser, 'beheer')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']);
    exit;
}

$action = trim($_GET['action'] ?? '');
$id     = trim($_GET['id']     ?? '');

function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// Geen stub-functie meer: wedstrijden die nog niet in competitions zitten
// worden met hun naam/datum op de koppelrij bewaard. We maken geen shadow-
// rij meer aan in `competitions` — dat voorkomt dat toekomstige wedstrijden
// per ongeluk verschijnen in Beheer → Wedstrijden (waar ze niet horen).

// ── Validatie: regels-JSON ─────────────────────────────────────────────────
function normaliseerRegels(array $in): array {
    $types = ['gecombineerd', 'sprint', 'lang', 'custom'];
    $filters = ['alle', 'sprint', 'lang', 'per_naam'];
    $type = in_array($in['type'] ?? '', $types, true) ? $in['type'] : 'gecombineerd';
    $filter = in_array($in['afstand_filter'] ?? '', $filters, true) ? $in['afstand_filter'] : 'alle';
    // Afgeleid: type forceert afstand_filter tenzij custom
    if ($type === 'sprint') $filter = 'sprint';
    elseif ($type === 'lang') $filter = 'lang';
    elseif ($type === 'gecombineerd') $filter = 'alle';
    $namen = ($filter === 'per_naam' && is_array($in['afstand_namen'] ?? null))
        ? array_values(array_filter(array_map('strval', $in['afstand_namen'])))
        : [];
    $tabel = is_array($in['punten_tabel'] ?? null) ? array_values(array_map('floatval', $in['punten_tabel'])) : [];
    if (empty($tabel)) $tabel = [50.1,47,45,43,41,39,37,35,33,31,30,29,28,27,26,25,24,23,22,21,20,19,18,17,16,15,14,13,12,11,10,9,8,7,6,5,4,3,2,1];
    $tieBreaks = ['geen', 'laatste', 'beste_resultaten', 'beste_resultaten_dan_laatste'];
    $tieBreak  = in_array($in['tie_break'] ?? '', $tieBreaks, true)
                   ? $in['tie_break'] : 'beste_resultaten_dan_laatste';
    // Categorie-filter: array van KNSB-cat-codes (HSA, DJB, HKA, …) die WEL
    // meedoen in dit klassement. Lege array = geen filter = alle cats.
    // Bedoeld voor: combi-klassement alleen voor Kadetten+Junioren B, of
    // sprint/lang alleen voor senioren — zonder per wedstrijd handmatig
    // rijders te moeten uitsluiten. Cats worden hoofdletter-genormaliseerd
    // en getrimd om matching tegen persons.category robust te maken.
    $catFilter = [];
    if (is_array($in['categorie_filter'] ?? null)) {
        foreach ($in['categorie_filter'] as $c) {
            $s = strtoupper(trim((string)$c));
            if ($s !== '') $catFilter[] = $s;
        }
        $catFilter = array_values(array_unique($catFilter));
    }
    return [
        'type'                    => $type,
        'afstand_filter'          => $filter,
        'afstand_namen'           => $namen,
        'categorie_filter'        => $catFilter,
        'punten_tabel'            => $tabel,
        'min_punten_bij_deelname' => (float)($in['min_punten_bij_deelname'] ?? 1),
        'streepresultaten'        => max(0, (int)($in['streepresultaten'] ?? 0)),
        'min_deelnames'           => max(0, (int)($in['min_deelnames'] ?? 0)),
        'tie_break'               => $tieBreak,
        'vereist_finale'          => !empty($in['vereist_finale']),
        // Optioneel: rijders die in een wedstrijd uit de serie NIET meededen
        // (of punten=0 kregen) maar elders in de serie wel scoren, krijgen
        // voor die gemiste wedstrijd de "rang laatste + 1" — d.w.z. de punten
        // die in de tabel op die positie staan. Bij meerdere afwezigen krijgen
        // ze allemaal dezelfde rang ("laatste + 1"), niet oplopend.
        'non_deelname_punten'     => !empty($in['non_deelname_punten']),
        // Optioneel: streepresultaten direct toepassen, ook tijdens de
        // tussenstand (vóór de finale gereden is). Default false →
        // streep wordt pas actief zodra de finale gereden is, zoals
        // klassieke series-regels.
        'streep_direct'           => !empty($in['streep_direct']),
    ];
}

// Bepaalt of de punten-tabel oplopend is (rang 1 → laagste punten, dus
// laagste totaal wint). Bij default-tabel [50.1, 47, 45, …] is dit false.
// Bij masker [1, 2, 3, …] is dit true. Eén lookup is genoeg — een mengvorm
// (bv. [10, 5, 8]) komt in de praktijk niet voor.
function tabelIsOplopend(array $tabel): bool {
    if (count($tabel) < 2) return false;
    return $tabel[0] < $tabel[1];
}

// ──────────────────────────────────────────────────────────────────────────
//  Kernlogica: berekenen
// ──────────────────────────────────────────────────────────────────────────
//
// Architectuur: een wedstrijd heeft meerdere distance_combinations (DCs).
// Per DC is er één EINDKLASSEMENT (uit `uitslag_klassement`) — daar leest dit
// stuk code uit, NIET per individuele afstand.
//
// DC-type classificatie (hoe valt een DC onder sprint/lang/gecombineerd?):
//   * gecombineerd = DC bevat >1 afstand (= standaard KNSB-wedstrijd per
//                    categorie met bv. 500m + 1000m samen)
//   * sprint       = DC bevat 1 afstand met race_type = 'sprint'
//   * lang         = DC bevat 1 afstand met race_type ≠ 'sprint'
// Het serie-type filtert welke DCs we meenemen.
//
// Rangschikking binnen DC: we rangschikken BINNEN persoons-categorie zodat
// gecombineerde DCs met meerdere cats (DP1+DP2+DP3 samen) automatisch
// splitsen in aparte klassementen per cat.
function berekenSerie(PDO $pdo, string $serieId): array {
    $sStmt = $pdo->prepare("SELECT * FROM klassement_series WHERE id = ?");
    $sStmt->execute([$serieId]);
    $serie = $sStmt->fetch(PDO::FETCH_ASSOC);
    if (!$serie) throw new RuntimeException('Serie niet gevonden');
    $regels = json_decode($serie['regels'] ?? '{}', true) ?: [];
    $regels = normaliseerRegels($regels);

    // Meetellende wedstrijden ophalen, plus is_finale en start-datum om de
    // finale-wedstrijd te kunnen bepalen. De finale wordt gebruikt voor:
    //   * tie-break bij mode 'laatste'
    //   * regel 'vereist_finale'
    //   * gating voor 'streepresultaten' (pas toepassen als finale is gereden)
    $wStmt = $pdo->prepare("
        SELECT w.competition_id,
               COALESCE(c.starts, w.comp_datum) AS starts,
               w.volgorde, w.is_finale, w.bonus_modus, w.bonus_punten
        FROM klassement_serie_wedstrijden w
        LEFT JOIN competitions c ON c.id = w.competition_id
        WHERE w.serie_id = ? AND w.telt_mee = 1
        ORDER BY w.volgorde, starts
    ");
    $wStmt->execute([$serieId]);
    $wedstrijden = $wStmt->fetchAll(PDO::FETCH_ASSOC);
    $compIds = array_column($wedstrijden, 'competition_id');
    // Bonus-wedstrijden (afgelast → vast aantal punten per aanwezige): map
    // competition_id → bonus_punten. Deze worden NIET via het rang→punten-pad
    // verwerkt maar in een aparte pass verderop (aanwezigen uit `entries`).
    $bonusWedstrijden = [];
    foreach ($wedstrijden as $w) {
        if (!empty($w['bonus_modus'])) {
            $bonusWedstrijden[$w['competition_id']] = (float)$w['bonus_punten'];
        }
    }
    // Bepaal finale-wedstrijd: expliciet aangevinkt wint; fallback = chronologisch laatste.
    $laatsteComp = null;
    if ($wedstrijden) {
        foreach ($wedstrijden as $w) {
            if (!empty($w['is_finale'])) { $laatsteComp = $w['competition_id']; break; }
        }
        if (!$laatsteComp) {
            $sorted = $wedstrijden;
            usort($sorted, fn($a,$b) =>
                strcmp($b['starts'] ?? '', $a['starts'] ?? '')
                ?: ($b['volgorde'] <=> $a['volgorde']));
            $laatsteComp = $sorted[0]['competition_id'];
        }
    }
    // Chronologische volgorde, nieuwste eerst — voor tie-break met fallback
    // (laatste wedstrijd → bij gelijk: voorlaatste → daarvoor → …). De finale
    // staat als eerste, daarna alle andere in omgekeerde startdatum-volgorde.
    $compChrono = $wedstrijden;
    usort($compChrono, fn($a,$b) =>
        strcmp($b['starts'] ?? '', $a['starts'] ?? '')
        ?: ($b['volgorde'] <=> $a['volgorde']));
    $compChronoIds = array_column($compChrono, 'competition_id');
    // Finale eerst als die expliciet is aangevinkt en niet al vooraan staat
    if ($laatsteComp && ($compChronoIds[0] ?? null) !== $laatsteComp) {
        $compChronoIds = array_values(array_filter($compChronoIds, fn($c) => $c !== $laatsteComp));
        array_unshift($compChronoIds, $laatsteComp);
    }
    // Heeft de finale-wedstrijd al resultaten? Dan pas mag streepresultaten
    // worden toegepast. Zolang finale nog niet is gereden: geen streep.
    // Tegelijk halen we alle licenties op die in de finale voorkwamen —
    // inclusief rijders met punten=0 (DNS/blessure). "Aanwezig in finale"
    // is namelijk een aparte regel (vereist_finale), los van of hun finale-
    // score uiteindelijk punten oplevert of weggestreept wordt.
    $finaleGereden = false;
    $aanwezigInFinale = []; // set van license_keys
    if ($laatsteComp) {
        // Eindklassement van de finale-wedstrijd (alle DC's, ongeacht type).
        // vereist_finale = "stond op de startlijst" — dus we checken of de
        // rijder überhaupt voorkomt in het eindklassement van de finale,
        // ongeacht punten/rang.
        $chk = $pdo->prepare("
            SELECT DISTINCT person_license
            FROM uitslag_klassement
            WHERE competition_id = ?
        ");
        $chk->execute([$laatsteComp]);
        foreach ($chk->fetchAll(PDO::FETCH_COLUMN) as $lic) {
            $aanwezigInFinale[$lic] = true;
        }
        $finaleGereden = !empty($aanwezigInFinale);
    }

    // Sort-richting wordt afgeleid uit de punten-tabel: oplopend (1, 2, 3, …)
    // → laagste totaal wint; aflopend (50, 47, 45, …) → hoogste wint.
    $oplopend = tabelIsOplopend($regels['punten_tabel']);

    // Accumulator per (license_key, categorie)
    //   [lic][cat] = ['naam'=>…, 'startnr'=>…, 'totaal'=>0.0, 'detail'=>[wedstrijd_naam=>punten], 'deelnames'=>N]
    $acc = [];

    // Hulpmaps voor non-deelname-regel:
    //   $aantalPerCompCat[$compId][$cat] = aantal deelnemers in die (comp, cat)
    //   $compNaamMap[$compId]            = naam van de wedstrijd (voor detail-label)
    //   $catNaamPerComp[$compId][$cat]   = dc_naam waarin deze cat voorkwam
    $aantalPerCompCat = [];
    $compNaamMap = [];
    $catNaamPerComp = [];

    if ($compIds) {
        $ph = implode(',', array_fill(0, count($compIds), '?'));

        // ── Stap 1: classificeer elke DC in de meetellende wedstrijden ──
        //   aantal_afstanden = N; als N=1 pak ook race_type van die afstand
        $dcSql = "
            SELECT dc.id AS dc_id, dc.competition_id,
                   COUNT(d.id) AS n_afstanden,
                   MIN(d.race_type) AS min_rt,
                   MAX(d.race_type) AS max_rt
            FROM distance_combinations dc
            LEFT JOIN distances d ON d.distance_combination_id = dc.id
            WHERE dc.competition_id IN ($ph)
            GROUP BY dc.id, dc.competition_id
        ";
        $dcStmt = $pdo->prepare($dcSql);
        $dcStmt->execute($compIds);
        $dcTypes = [];
        foreach ($dcStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $n = (int)$d['n_afstanden'];
            $rt = '';
            if ($n === 0) {
                $type = 'leeg';
            } elseif ($n > 1) {
                $type = 'gecombineerd';
            } else {
                // N == 1 → classificatie op basis van die ene afstand
                $rt   = $d['min_rt'] ?: '';
                $type = ($rt === 'sprint') ? 'sprint' : 'lang';
            }
            $dcTypes[$d['dc_id']] = $type;
        }

        // ── Bron-keuze afhankelijk van afstand-filter ─────────────────────
        //
        // Bij filter='alle' (gecombineerd type): we lezen uit `uitslag_klassement`
        // — daar staat de DC-combined ranking (over alle afstanden binnen een DC
        // samen). Eén ranking per (comp, DC, split, cat).
        //
        // Bij filter='sprint' / 'lang' / 'per_naam': we lezen uit `uitslag_afstand`
        // per matchende afstand. Zo kunnen we ook gecombineerde DCs (die zowel
        // een sprint als een lange afstand bevatten) meenemen — dan tellen
        // alleen de afstanden die bij het filter horen. Een DC met 500m + 5000m
        // levert in een sprint-serie alleen het 500m-resultaat op.
        $isAfstandLevel = ($regels['afstand_filter'] !== 'alle');

        if ($isAfstandLevel) {
            // ── uitslag_afstand pad ──────────────────────────────────────
            // Filter-condities op race_type / distance.name; uitgesloten-rijders
            // (punten=0) worden in dezelfde query weggegooid.
            $extraWhere = '';
            $extraParams = [];
            if ($regels['afstand_filter'] === 'sprint') {
                $extraWhere = " AND d.race_type = 'sprint'";
            } elseif ($regels['afstand_filter'] === 'lang') {
                $extraWhere = " AND d.race_type <> 'sprint'";
            } elseif ($regels['afstand_filter'] === 'per_naam'
                      && !empty($regels['afstand_namen'])) {
                $namenPh = implode(',', array_fill(0, count($regels['afstand_namen']), '?'));
                $extraWhere = " AND d.name IN ($namenPh)";
                $extraParams = $regels['afstand_namen'];
            } elseif ($regels['afstand_filter'] === 'per_naam') {
                // Geen namen geselecteerd → niets matcht → lege resultaten
                $extraWhere = " AND 1=0";
            }

            $kSql = "
                SELECT ua.competition_id,
                       ua.distance_combination_id AS dc_id, ua.dc_naam,
                       ua.distance_id, ua.distance_naam,
                       ua.split_group, ua.person_license, ua.categorie,
                       ua.rang, ua.punten AS punten_totaal,
                       c.name AS comp_naam,
                       p.full_name, p.short_name, p.category AS persoon_cat,
                       COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr
                FROM uitslag_afstand ua
                JOIN distances d
                    ON d.distance_combination_id = ua.distance_combination_id
                   AND d.id = ua.distance_id
                JOIN competitions c ON c.id = ua.competition_id
                JOIN persons p ON p.license_key = ua.person_license
                LEFT JOIN competition_startnummers cs
                       ON cs.person_license = ua.person_license
                      AND cs.competition_id = ua.competition_id
                WHERE ua.competition_id IN ($ph)
                  AND ua.punten IS NOT NULL
                  AND ua.punten > 0
                  AND ua.rang IS NOT NULL
                  $extraWhere
            ";
            $kStmt = $pdo->prepare($kSql);
            $kStmt->execute(array_merge($compIds, $extraParams));
            $rijen = $kStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // ── uitslag_klassement pad (DC-combined) ─────────────────────
            // DC-filter alleen relevant voor 'alle': elke DC mag mee, behalve
            // 'leeg'-DCs.
            $dcOk = function($dcId) use ($dcTypes) {
                $t = $dcTypes[$dcId] ?? null;
                return $t && $t !== 'leeg';
            };

            // Welke rijders zijn UITGESLOTEN per (comp, dc)? Bedoeld als
            // safety-net: oude rijen in `uitslag_klassement` kunnen rang+
            // punten houden voor rijders die admin later via punten=0 heeft
            // weggeklikt (DQ-SF/DF, jury-correctie).
            //
            // BELANGRIJK: een rijder met punten=0 op ÉÉN afstand maar wél
            // punten op een andere (typisch DNS in eerste ronde van afstand A
            // maar gewoon gereden op afstand B) is NIET uitgesloten — die
            // moet z'n punten-totaal gewoon mee naar de serie krijgen. Daarom
            // checken we hier op SUM(punten) = 0 over alle afstanden van
            // de rijder binnen deze comp+dc, in plaats van op een enkele
            // afstand met punten=0.
            $uitgeslotenSql = "
                SELECT competition_id, distance_combination_id, person_license
                FROM uitslag_afstand
                WHERE competition_id IN ($ph)
                GROUP BY competition_id, distance_combination_id, person_license
                HAVING SUM(COALESCE(punten, 0)) = 0
            ";
            $uStmt = $pdo->prepare($uitgeslotenSql);
            $uStmt->execute($compIds);
            $uitgeslotenSet = [];
            foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $k = $row['competition_id'] . '|' . $row['distance_combination_id'] . '|' . $row['person_license'];
                $uitgeslotenSet[$k] = true;
            }

            $kSql = "
                SELECT uk.competition_id, uk.distance_combination_id AS dc_id, uk.dc_naam,
                       NULL AS distance_id, NULL AS distance_naam,
                       uk.split_group, uk.person_license, uk.categorie,
                       uk.rang, uk.punten_totaal,
                       c.name AS comp_naam,
                       p.full_name, p.short_name, p.category AS persoon_cat,
                       COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr
                FROM uitslag_klassement uk
                JOIN competitions c ON c.id = uk.competition_id
                JOIN persons p ON p.license_key = uk.person_license
                LEFT JOIN competition_startnummers cs
                       ON cs.person_license = uk.person_license
                      AND cs.competition_id = uk.competition_id
                WHERE uk.competition_id IN ($ph)
            ";
            $kStmt = $pdo->prepare($kSql);
            $kStmt->execute($compIds);
            $rijen = $kStmt->fetchAll(PDO::FETCH_ASSOC);

            // Filter op DC-type + rang-geldigheid + uitgesloten-set.
            $rijen = array_values(array_filter($rijen, function($r) use ($dcOk, $uitgeslotenSet) {
                if (!$dcOk($r['dc_id'])) return false;
                if ((float)($r['punten_totaal'] ?? 0) <= 0) return false;
                if ($r['rang'] === null) return false;
                $k = $r['competition_id'] . '|' . $r['dc_id'] . '|' . $r['person_license'];
                if (isset($uitgeslotenSet[$k])) return false;
                return true;
            }));
        }

        // ── Categorie-filter (whitelist) ──────────────────────────────────────
        // Beide paden (uitslag_afstand + uitslag_klassement) hebben $rijen
        // gevuld. Als de operator een specifieke set categorieën heeft gekozen
        // (bv. alleen Kadetten + Junioren B voor een combi-klassement, of
        // alleen Senioren voor sprint/lang) filteren we hier de rest weg.
        // Vergelijking: hoofdletter-genormaliseerd op persoon_cat (kolom
        // persons.category) zodat HSA / hsa / Hsa allemaal matchen.
        if (!empty($regels['categorie_filter'])) {
            $catSet = array_flip($regels['categorie_filter']);
            $rijen = array_values(array_filter($rijen, function($r) use ($catSet) {
                $cat = strtoupper(trim((string)($r['persoon_cat'] ?? '')));
                return $cat !== '' && isset($catSet[$cat]);
            }));
        }

        // Groeperen per (comp, dc, [distance,] split, persoons-categorie) om
        // binnen-cat-rang toe te kennen. Bij afstand-level (sprint/lang/per_naam)
        // wordt distance_id mee in de sleutel genomen — een DC met meerdere
        // matchende afstanden levert dan per afstand een aparte ranking.
        $groepen = [];
        // Én: ruimere groepering voor cluster-klassement (= zelfde DC met
        // meerdere cats die samen rijden). Cluster wordt alleen opgebouwd in
        // het uitslag_klassement-pad; bij afstand-level laten we cluster
        // achterwege omdat een per-afstand-cluster qua interpretatie minder
        // duidelijk is en het use-case minder relevant.
        $dcGroepen = [];
        foreach ($rijen as $r) {
            $keyParts = [
                $r['competition_id'],
                $r['dc_id'],
                $r['split_group'] ?? '',
                $r['persoon_cat'] ?? '',
            ];
            if ($isAfstandLevel) {
                // Voeg distance_id toe — anders zou een DC met 500m + 1000m
                // sprint één gemerged groepje vormen i.p.v. twee aparte rankings.
                $keyParts[] = $r['distance_id'] ?? '';
            }
            $key = implode('|', $keyParts);
            $groepen[$key][] = $r;
            // Cluster-klassement (gecombineerde cats binnen dezelfde DC) ook
            // voor afstand-level — dan inclusief distance_id zodat 500m en
            // 1000m van een gecombineerde DC elk hun eigen cluster krijgen.
            $dcKeyParts = [
                $r['competition_id'],
                $r['dc_id'],
                $r['split_group'] ?? '',
            ];
            if ($isAfstandLevel) {
                $dcKeyParts[] = $r['distance_id'] ?? '';
            }
            $dcKey = implode('|', $dcKeyParts);
            $dcGroepen[$dcKey][] = $r;
        }

        // pwKey = "per-wedstrijd-key" — bij afstand-level apart per (comp,
        // distance) zodat een wedstrijd met meerdere geselecteerde afstanden
        // per afstand een eigen kolom krijgt in de uitslag (niet opgeteld
        // tot één wedstrijd-totaal).
        $mkPwKey = function($r) use ($isAfstandLevel) {
            return $isAfstandLevel
                ? $r['competition_id'] . '|' . ($r['distance_id'] ?? '0')
                : $r['competition_id'];
        };
        // Bewaar per pwKey de meta-info die de UI nodig heeft (comp_id +
        // distance_naam) — gebruikt straks om wedstrijden_meta op te bouwen.
        $pwKeyMeta = []; // pwKey => ['comp_id', 'distance_id', 'distance_naam']
        foreach ($rijen as $r) {
            $k = $mkPwKey($r);
            if (!isset($pwKeyMeta[$k])) {
                $pwKeyMeta[$k] = [
                    'comp_id'       => $r['competition_id'],
                    'distance_id'   => $r['distance_id'] ?? null,
                    'distance_naam' => $r['distance_naam'] ?? null,
                ];
            }
        }

        foreach ($groepen as $groep) {
            // Sorteer binnen de (comp, DC, splitgroep, cat) op absolute rang ASC
            usort($groep, function($a, $b) {
                $ra = $a['rang'] !== null ? (int)$a['rang'] : PHP_INT_MAX;
                $rb = $b['rang'] !== null ? (int)$b['rang'] : PHP_INT_MAX;
                return $ra <=> $rb;
            });
            // Tellingen voor non-deelname-regel: aantal rijders in deze
            // (pwKey, cat). Bij afstand-level is pwKey = comp+distance, dus
            // de telling is per (comp, distance, cat) — anders per (comp, cat).
            if ($groep) {
                $compId = $groep[0]['competition_id'];
                $catKey = $groep[0]['persoon_cat'] ?? $groep[0]['categorie'] ?? '';
                $pwKey  = $mkPwKey($groep[0]);
                $aantalPerCompCat[$pwKey][$catKey] =
                    ($aantalPerCompCat[$pwKey][$catKey] ?? 0) + count($groep);
                $compNaamMap[$compId] = $groep[0]['comp_naam'];
                $catNaamPerComp[$compId][$catKey] = $groep[0]['dc_naam'] ?? '';
            }
            foreach ($groep as $i => $r) {
                $catRang = $i + 1;
                $punten  = $regels['punten_tabel'][$catRang - 1]
                    ?? $regels['min_punten_bij_deelname'];
                $lic = $r['person_license'];
                $cat = $r['persoon_cat'] ?? $r['categorie'] ?? '';
                if (!isset($acc[$lic][$cat])) {
                    $acc[$lic][$cat] = [
                        'naam'        => $r['full_name'],
                        'short'       => $r['short_name'] ?? '',
                        'startnr'     => $r['wedstrijd_snr'],
                        'license'     => $lic,
                        'cat'         => $cat,
                        'totaal'      => 0.0,
                        'detail'      => [],
                        'deelnames'   => 0,
                        'per_wedstrijd'=> [],
                        'in_laatste'  => false,
                    ];
                }
                $acc[$lic][$cat]['totaal']   += (float)$punten;
                $acc[$lic][$cat]['deelnames']++;
                // Detail-label: bij afstand-level voegen we de afstand-naam toe
                // zodat een DC met meerdere matchende afstanden in de
                // detail-tooltip per afstand een eigen regel krijgt.
                $wNaam = $r['comp_naam'] . ' · ' . ($r['dc_naam'] ?? '?');
                if ($isAfstandLevel && !empty($r['distance_naam'])) {
                    $wNaam .= ' · ' . $r['distance_naam'];
                }
                $acc[$lic][$cat]['detail'][$wNaam] =
                    ($acc[$lic][$cat]['detail'][$wNaam] ?? 0) + $punten;
                $pwKey = $mkPwKey($r);
                $acc[$lic][$cat]['per_wedstrijd'][$pwKey] =
                    ($acc[$lic][$cat]['per_wedstrijd'][$pwKey] ?? 0) + $punten;
                if (!empty($r['wedstrijd_snr'])) $acc[$lic][$cat]['startnr'] = $r['wedstrijd_snr'];
            }
        }

        // ── Cluster-klassement voor gemengde DCs ────────────────────────
        // Als in dezelfde DC meerdere categorieën samen rijden, maken we
        // óók een gecombineerde stand. Cat-label = gesorteerde cat-codes
        // met "/" (bv. "DP1/DP2/DP3"). Binnen de cluster wordt puur op
        // absolute rang gerangschikt — niet opnieuw per cat opgesplitst.
        foreach ($dcGroepen as $groep) {
            // Unieke cats in deze DC-groep
            $cats = array_values(array_unique(array_filter(array_map(
                fn($g) => $g['persoon_cat'] ?? '', $groep
            ))));
            if (count($cats) < 2) continue;  // alleen voor gemengde DCs
            sort($cats);
            $clusterLabel = implode('/', $cats);

            usort($groep, function($a, $b) {
                $ra = $a['rang'] !== null ? (int)$a['rang'] : PHP_INT_MAX;
                $rb = $b['rang'] !== null ? (int)$b['rang'] : PHP_INT_MAX;
                return $ra <=> $rb;
            });
            foreach ($groep as $i => $r) {
                $rang = $i + 1;
                $punten = $regels['punten_tabel'][$rang - 1] ?? $regels['min_punten_bij_deelname'];
                $lic = $r['person_license'];
                if (!isset($acc[$lic][$clusterLabel])) {
                    $acc[$lic][$clusterLabel] = [
                        'naam'         => $r['full_name'],
                        'short'        => $r['short_name'] ?? '',
                        'startnr'      => $r['wedstrijd_snr'],
                        'license'      => $lic,
                        'cat'          => $clusterLabel,
                        'totaal'       => 0.0,
                        'detail'       => [],
                        'deelnames'    => 0,
                        'per_wedstrijd'=> [],
                        'in_laatste'   => false,
                    ];
                }
                $acc[$lic][$clusterLabel]['totaal']   += (float)$punten;
                $acc[$lic][$clusterLabel]['deelnames']++;
                $wNaam = $r['comp_naam'] . ' · ' . ($r['dc_naam'] ?? '?');
                if ($isAfstandLevel && !empty($r['distance_naam'])) {
                    $wNaam .= ' · ' . $r['distance_naam'];
                }
                $acc[$lic][$clusterLabel]['detail'][$wNaam] =
                    ($acc[$lic][$clusterLabel]['detail'][$wNaam] ?? 0) + $punten;
                $pwKey = $mkPwKey($r);
                $acc[$lic][$clusterLabel]['per_wedstrijd'][$pwKey] =
                    ($acc[$lic][$clusterLabel]['per_wedstrijd'][$pwKey] ?? 0) + $punten;
                if (!empty($r['wedstrijd_snr'])) $acc[$lic][$clusterLabel]['startnr'] = $r['wedstrijd_snr'];
            }
        }

        // ── Bonus-wedstrijden: EXTRA punten bovenop de uitslag ──────────────
        // Een bonus-wedstrijd geeft elke AANWEZIGE rijder (entries.status IN
        // (1,5) = getekend/aanwezig óf bevestigd door de organisatie) een vast
        // aantal EXTRA punten — additief, bovenop wat de uitslag oplevert:
        //   • afgelaste wedstrijd (geen uitslag) → wie er was krijgt 0 + bonus
        //   • zwaardere wedstrijd (finale, lange afstand) → uitslag-punten + bonus
        // De wedstrijd blijft dus gewoon in het rang→punten-pad (mag zelfs de
        // finale zijn). Afwezigen krijgen géén bonus. De punten landen — net als
        // een echte wedstrijd — in de categorie-stand én (gemengde DC's) cluster.
        if (!empty($bonusWedstrijden)) {
            // Comps die al een rang-uitslag hebben: daar telde het rang-pad de
            // deelname + aantalPerCompCat al; de bonus telt dan NIET nog eens als
            // aparte deelname (hij wordt bij het bestaande resultaat opgeteld).
            $compsMetUitslag = array_flip(array_column($rijen, 'competition_id'));

            $bonusCompIds = array_keys($bonusWedstrijden);
            $bPh = implode(',', array_fill(0, count($bonusCompIds), '?'));
            $bStmt = $pdo->prepare("
                SELECT e.person_license, dc.competition_id,
                       dc.id AS dc_id, dc.name AS dc_naam,
                       p.full_name, p.short_name, p.category AS persoon_cat,
                       c.name AS comp_naam,
                       COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr
                FROM entries e
                JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                JOIN persons p ON p.license_key = e.person_license
                JOIN competitions c ON c.id = dc.competition_id
                LEFT JOIN competition_startnummers cs
                       ON cs.person_license = e.person_license
                      AND cs.competition_id = dc.competition_id
                WHERE dc.competition_id IN ($bPh)
                  AND e.status IN (1, 5)
            ");
            $bStmt->execute($bonusCompIds);
            $bonusRijen = $bStmt->fetchAll(PDO::FETCH_ASSOC);

            // Categorie-filter (whitelist) ook op bonus toepassen.
            if (!empty($regels['categorie_filter'])) {
                $catSet = array_flip($regels['categorie_filter']);
                $bonusRijen = array_values(array_filter($bonusRijen, function($r) use ($catSet) {
                    $cat = strtoupper(trim((string)($r['persoon_cat'] ?? '')));
                    return $cat !== '' && isset($catSet[$cat]);
                }));
            }

            // Per (comp, dc) de unieke cats verzamelen voor cluster-detectie
            // (gemengde DC = meerdere cats samen → ook een gecombineerde stand).
            $bonusCatsPerDc = [];
            foreach ($bonusRijen as $r) {
                $dk = $r['competition_id'] . '|' . $r['dc_id'];
                $bonusCatsPerDc[$dk][(string)($r['persoon_cat'] ?? '')] = true;
            }

            // Telt de bonus OP bij één (lic, cat)-rij. Retourneert true als deze
            // comp nog niet in die rij zat (= nieuwe deelname; bij een comp mét
            // uitslag zat 'ie er al in via het rang-pad → false, geen dubbele
            // deelname). Hergebruikt voor categorie- én clusterstand.
            $addBonus = function(string $lic, string $catKey, array $r, float $bonus, string $cId)
                        use (&$acc): bool {
                if (!isset($acc[$lic][$catKey])) {
                    $acc[$lic][$catKey] = [
                        'naam'          => $r['full_name'],
                        'short'         => $r['short_name'] ?? '',
                        'startnr'       => $r['wedstrijd_snr'],
                        'license'       => $lic,
                        'cat'           => $catKey,
                        'totaal'        => 0.0,
                        'detail'        => [],
                        'deelnames'     => 0,
                        'per_wedstrijd' => [],
                        'in_laatste'    => false,
                    ];
                }
                $nieuweDeelname = !isset($acc[$lic][$catKey]['per_wedstrijd'][$cId]);
                $acc[$lic][$catKey]['totaal'] += $bonus;
                $wNaam = $r['comp_naam'] . ' · ' . ($r['dc_naam'] ?? '?') . ' (bonus)';
                $acc[$lic][$catKey]['detail'][$wNaam] =
                    ($acc[$lic][$catKey]['detail'][$wNaam] ?? 0) + $bonus;
                $acc[$lic][$catKey]['per_wedstrijd'][$cId] =
                    ($acc[$lic][$catKey]['per_wedstrijd'][$cId] ?? 0) + $bonus;
                if ($nieuweDeelname) $acc[$lic][$catKey]['deelnames']++;
                if (!empty($r['wedstrijd_snr'])) $acc[$lic][$catKey]['startnr'] = $r['wedstrijd_snr'];
                return $nieuweDeelname;
            };

            $bonusGezien = []; // "cId|lic|catKey" → per rijder één keer optellen
            foreach ($bonusRijen as $r) {
                $cId   = $r['competition_id'];
                $bonus = (float)($bonusWedstrijden[$cId] ?? 0);
                if ($bonus == 0.0) continue;
                $lic   = $r['person_license'];
                $cat   = (string)($r['persoon_cat'] ?? '');
                if ($cat === '') continue;

                $compNaamMap[$cId]           = $r['comp_naam'];
                $catNaamPerComp[$cId][$cat]  = $r['dc_naam'] ?? '';

                // Categorie-stand (één keer per rijder per cat)
                $seen = $cId . '|' . $lic . '|' . $cat;
                if (!isset($bonusGezien[$seen])) {
                    $bonusGezien[$seen] = true;
                    $nieuw = $addBonus($lic, $cat, $r, $bonus, $cId);
                    // aantalPerCompCat alleen ophogen voor een afgelaste comp
                    // (geen uitslag). Bij een comp mét uitslag telde het rang-pad
                    // dat al — niet perturben (zou de non-deelname-rang scheeftrekken).
                    if ($nieuw && !isset($compsMetUitslag[$cId])) {
                        $aantalPerCompCat[$cId][$cat] = ($aantalPerCompCat[$cId][$cat] ?? 0) + 1;
                    }
                }

                // Clusterstand (alleen gemengde DC's, ≥2 cats samen)
                $dk     = $cId . '|' . $r['dc_id'];
                $dcCats = array_values(array_filter(array_keys($bonusCatsPerDc[$dk] ?? [])));
                if (count($dcCats) >= 2) {
                    sort($dcCats);
                    $clusterLabel = implode('/', $dcCats);
                    $seenC = $cId . '|' . $lic . '|' . $clusterLabel;
                    if (!isset($bonusGezien[$seenC])) {
                        $bonusGezien[$seenC] = true;
                        $addBonus($lic, $clusterLabel, $r, $bonus, $cId);
                    }
                }
            }
        }

        // ── Non-deelname-regel: rijders die elders in de serie wél scoren
        //    maar in een specifieke wedstrijd ontbreken (of punten=0 hadden),
        //    krijgen voor die wedstrijd de punten op rang "laatste + 1" uit
        //    de tabel. Bij meerdere afwezigen krijgen ze allemaal dezelfde
        //    rang. Cluster-klassementen worden niet aangepast (te complex en
        //    minder relevant — een cluster bestaat alleen waar cats samen
        //    rijden, dus "afwezig in deze cluster" is sowieso onduidelijk).
        if (!empty($regels['non_deelname_punten'])) {
            foreach ($acc as $lic => &$perCatMap) {
                foreach ($perCatMap as $cat => &$row) {
                    // Skip cluster-rijen (cat-label bevat '/')
                    if (strpos($cat, '/') !== false) continue;
                    // Loop over álle pwKeys (= unique (comp, [distance])-paren).
                    // pwKeyMeta is gebouwd uit de rijen — dus alleen de
                    // (comp, distance) combinaties die echt in deze serie
                    // voorkomen worden gepenaliseerd bij non-deelname.
                    foreach ($pwKeyMeta as $pwKey => $pwInfo) {
                        if (isset($row['per_wedstrijd'][$pwKey])) continue;
                        $aantal = $aantalPerCompCat[$pwKey][$cat] ?? 0;
                        if ($aantal === 0) continue;
                        $rang   = $aantal + 1;
                        $punten = $regels['punten_tabel'][$rang - 1]
                                  ?? $regels['min_punten_bij_deelname'];
                        $row['totaal'] += (float)$punten;
                        $row['per_wedstrijd'][$pwKey] = (float)$punten;
                        $cId = $pwInfo['comp_id'];
                        $compNaam = $compNaamMap[$cId] ?? $cId;
                        $dcNaam   = $catNaamPerComp[$cId][$cat] ?? '?';
                        $distSuffix = $pwInfo['distance_naam']
                            ? ' · ' . $pwInfo['distance_naam'] : '';
                        $wLabel   = $compNaam . ' · ' . $dcNaam . $distSuffix . ' (afwezig)';
                        $row['detail'][$wLabel] = (float)$punten;
                        // Geen $row['deelnames']++ — afwezigheid telt niet als deelname,
                        // anders zou je min_deelnames-drempel kunnen omzeilen.
                    }
                }
                unset($row);
            }
            unset($perCatMap);
        }
    }

    // Markeer "aanwezig in finale" per rijder (ongeacht punten) op basis van
    // de eerder opgehaalde set. Een rijder die in de finale DNS had maar
    // wel op de startlijst stond telt dus als aanwezig — voor de regel
    // vereist_finale is dat voldoende.
    foreach ($acc as $lic => &$perCatMap) {
        foreach ($perCatMap as &$row) {
            $row['in_laatste'] = isset($aanwezigInFinale[$lic]);
        }
        unset($row);
    }
    unset($perCatMap);

    // Plat maken + filteren op min_deelnames + vereist_finale + streepresultaten.
    // Alle drie de regels worden PAS toegepast NADAT de finale is gereden —
    // anders zou een tussenstand rijders onterecht uit de tussenstand
    // strepen (bv. vereist_finale op finale-die-nog-niet-gereden-is = iedereen weg).
    $perCat = [];
    // Streep wordt pas na finale toegepast, tenzij `streep_direct` aan staat —
    // dan al tijdens de tussenstand (slechtste resultaten uit de tot nu toe
    // gereden wedstrijden worden weggestreept).
    $streepDirect = !empty($regels['streep_direct']);
    $streep = ($finaleGereden || $streepDirect) ? (int)$regels['streepresultaten'] : 0;
    $minD   = $finaleGereden ? (int)$regels['min_deelnames']    : 0;
    $vereistFin = $finaleGereden ? !empty($regels['vereist_finale']) : false;
    foreach ($acc as $lic => $perCatMap) {
        foreach ($perCatMap as $cat => $row) {
            // Niet-geklasseerd = voldoet niet aan een klassering-eis (te weinig
            // deelnames of finale-plicht niet gehaald). Vroeger vloog zo iemand
            // er via `continue` uit; nu markeren we 'm en houden we 'm — hij komt
            // in het onderblok (positie 0), mét dezelfde punten-/streepberekening
            // zover toepasbaar (streep wordt hieronder gewoon meegenomen).
            $row['buiten_klassement'] =
                   ($minD > 0 && $row['deelnames'] < $minD)
                || ($vereistFin && !$row['in_laatste']);

            // Sorteer (comp_id, punten)-paren in tabel-richting: bij aflopende
            // tabel staat hoog vooraan (DESC), bij oplopende tabel laag
            // vooraan (ASC). De eerste N elementen zijn dan altijd de
            // "beste" resultaten — gebruikt voor streepresultaten én
            // tie-break. Werken op paren (i.p.v. losse waarden) is nodig
            // omdat we ook willen vastleggen WELKE wedstrijden zijn
            // weggestreept (voor weergave in de UI).
            $paren = [];
            foreach ($row['per_wedstrijd'] as $cid => $punten) {
                $paren[] = ['comp_id' => $cid, 'punten' => (float)$punten];
            }
            usort($paren, function($a, $b) use ($oplopend) {
                return $oplopend ? ($a['punten'] <=> $b['punten'])
                                 : ($b['punten'] <=> $a['punten']);
            });

            // Streepresultaten: drop de N slechtste wedstrijden (= laatste
            // N paren na bovenstaande sort). Ook als de finale-wedstrijd
            // toevallig de slechtste is, wordt die gewoon weggestreept —
            // aanwezigheid bij finale (vereist_finale) is een separate regel.
            //
            // Gemiste wedstrijden (rijder niet aanwezig terwijl cat wél
            // gereden heeft) vullen de streep-quota EERST — een rijder die
            // 1 wedstrijd miste op een serie met streep=1 hoeft daardoor géén
            // echte uitslag weg te strepen.
            //
            // Voor cluster-rijen (cat-label = "DSA/JDSA") splitsen we op '/'
            // en is "wedstrijd gereden voor cluster" = minstens één constituent-
            // cat had deelnemers — anders is een cluster-rijer onzichtbaar
            // voor deze correctie en wordt z'n echte uitslag onterecht gestreept.
            $effectStreep = $streep;
            if ($streep > 0) {
                $constituents = explode('/', $cat);
                $nGereden = 0;
                // Bij afstand-level loopt aantalPerCompCat op pwKey ipv comp_id
                // — gebruik dan de pwKeys, anders de compIds. Zo blijft de
                // streep-quota berekening kloppen in beide modes.
                $keysToCheck = $isAfstandLevel ? array_keys($pwKeyMeta) : $compIds;
                foreach ($keysToCheck as $kk) {
                    foreach ($constituents as $c) {
                        if (($aantalPerCompCat[$kk][$c] ?? 0) > 0) {
                            $nGereden++;
                            break;
                        }
                    }
                }
                $rijderMissed = max(0, $nGereden - count($paren));
                $effectStreep = max(0, $streep - $rijderMissed);
            }

            $gestreeptIds = [];
            if ($effectStreep > 0 && count($paren) > $effectStreep) {
                $teHouden     = count($paren) - $effectStreep;
                $gekozen      = array_slice($paren, 0, $teHouden);
                $weggestreept = array_slice($paren, $teHouden);
                $row['totaal'] = array_sum(array_column($gekozen, 'punten'));
                $row['_beste_resultaten'] = array_column($gekozen, 'punten');
                $gestreeptIds = array_column($weggestreept, 'comp_id');
            } else {
                $row['_beste_resultaten'] = array_column($paren, 'punten');
            }
            $row['_gestreept'] = $gestreeptIds;
            // _chrono_punten = array van punten per wedstrijd, in chronologische
            // volgorde nieuwste→oudste. Tie-break "laatste wedstrijd" loopt door
            // deze array tot een verschil gevonden wordt (laatste → bij gelijk
            // voorlaatste → daarvoor → enz.). Bij afstand-level tellen we per
            // comp alle distances op zodat 1 comp = 1 vergelijkings-waarde.
            $chronoPunten = [];
            foreach ($compChronoIds as $cid) {
                if ($isAfstandLevel) {
                    $prefix = $cid . '|';
                    $p = 0;
                    foreach ($row['per_wedstrijd'] as $kkey => $pp) {
                        if (strpos($kkey, $prefix) === 0) $p += (float)$pp;
                    }
                    $chronoPunten[] = $p;
                } else {
                    $chronoPunten[] = (float)($row['per_wedstrijd'][$cid] ?? 0);
                }
            }
            $row['_chrono_punten']  = $chronoPunten;
            $row['_laatste_punten'] = $chronoPunten[0] ?? 0;
            $perCat[$cat][] = $row;
        }
    }

    // Vergelijkfunctie: totaal in de tabel-richting (oplopend = ASC, aflopend
    // = DESC), daarna tie-break.
    $cmpTB = function($a, $b) use ($regels, $oplopend) {
        $tb = $regels['tie_break'];
        $cmpBeste = function($a, $b) use ($oplopend) {
            // Vergelijk beste-resultaten-vector lexicografisch in tabel-richting.
            $n = max(count($a['_beste_resultaten']), count($b['_beste_resultaten']));
            for ($i = 0; $i < $n; $i++) {
                $av = $a['_beste_resultaten'][$i] ?? 0;
                $bv = $b['_beste_resultaten'][$i] ?? 0;
                if ($av != $bv) return $oplopend ? ($av <=> $bv) : ($bv <=> $av);
            }
            return 0;
        };
        $cmpLaatste = function($a, $b) use ($oplopend) {
            // Loop door wedstrijden in chronologische volgorde (nieuwste eerst):
            // laatste wedstrijd; bij gelijk voorlaatste; daarvoor; enz. tot
            // verschil gevonden of array op is.
            $arrA = $a['_chrono_punten'] ?? [];
            $arrB = $b['_chrono_punten'] ?? [];
            $n = max(count($arrA), count($arrB));
            for ($i = 0; $i < $n; $i++) {
                $av = $arrA[$i] ?? 0;
                $bv = $arrB[$i] ?? 0;
                if ($av != $bv) return $oplopend ? ($av <=> $bv) : ($bv <=> $av);
            }
            return 0;
        };
        switch ($tb) {
            case 'laatste':                       return $cmpLaatste($a, $b);
            case 'beste_resultaten':              return $cmpBeste($a, $b);
            case 'beste_resultaten_dan_laatste':  $c = $cmpBeste($a, $b); return $c !== 0 ? $c : $cmpLaatste($a, $b);
            case 'geen':
            default:                              return 0;
        }
    };

    // Zolang de finale nog niet gereden is, is een tie-break nog niet
    // betekenisvol — we sorteren puur op totaal en laten gelijke totalen
    // ex aequo. Zodra de finale binnen is: volledige sortering inclusief
    // tie-break voor unieke posities.
    foreach ($perCat as &$lijst) {
        usort($lijst, function($a, $b) use ($cmpTB, $finaleGereden, $oplopend) {
            // 1) geklasseerd (bovenblok) vóór niet-geklasseerd (onderblok)
            $ba = !empty($a['buiten_klassement']) ? 1 : 0;
            $bb = !empty($b['buiten_klassement']) ? 1 : 0;
            if ($ba !== $bb) return $ba <=> $bb;
            // 2) totaal in tabel-richting
            $c = $oplopend ? ($a['totaal'] <=> $b['totaal'])
                           : ($b['totaal'] <=> $a['totaal']);
            if ($c !== 0) return $c;
            // 3) tie-break (pas betekenisvol zodra de finale gereden is)
            if ($finaleGereden) {
                $t = $cmpTB($a, $b);
                if ($t !== 0) return $t;
            }
            // 4) stabiele leesvolgorde bij echte ex-aequo: startnummer, dan naam
            $sa = (int)($a['startnr'] ?? 0);
            $sb = (int)($b['startnr'] ?? 0);
            if ($sa !== $sb) return $sa <=> $sb;
            return strcmp((string)($a['naam'] ?? ''), (string)($b['naam'] ?? ''));
        });
        foreach ($lijst as &$r) {
            // Tie-break-signatuur bewaren voor de "echte ex-aequo"-detectie in
            // schrijfKlassement (daar zijn _chrono_punten/_beste_resultaten weg).
            // Zelfde totaal + zelfde signatuur = onscheidbaar → gedeelde rang.
            $tb  = $regels['tie_break'];
            $sig = [];
            if ($tb === 'laatste')                          $sig = $r['_chrono_punten']   ?? [];
            elseif ($tb === 'beste_resultaten')             $sig = $r['_beste_resultaten'] ?? [];
            elseif ($tb === 'beste_resultaten_dan_laatste') $sig = [$r['_beste_resultaten'] ?? [], $r['_chrono_punten'] ?? []];
            $r['_tb_sig'] = json_encode($sig);
            unset($r['_beste_resultaten'], $r['_laatste_punten'], $r['_chrono_punten']);
        }
    }
    unset($lijst);

    // Meta over de wedstrijden (voor UI-kolommen). Bij afstand-level krijgt
    // ELKE (comp, distance) een eigen kolom — anders één kolom per wedstrijd.
    // Naam-lookup eerst in competitions; als de wedstrijd nog niet is
    // geïmporteerd, gebruik de opgeslagen comp_naam uit de koppelrij als
    // fallback.
    $meta = [];
    $nStmt = $pdo->prepare("SELECT name FROM competitions WHERE id = ?");
    $fStmt = $pdo->prepare("SELECT comp_naam, comp_datum FROM klassement_serie_wedstrijden WHERE serie_id = ? AND competition_id = ?");
    // Cache: comp_id → ['naam', 'datum', 'geimporteerd'] zodat we per pwKey
    // niet steeds opnieuw de DB hoeven te raadplegen.
    $compInfoCache = [];
    foreach ($wedstrijden as $w) {
        $cid = $w['competition_id'];
        if (isset($compInfoCache[$cid])) continue;
        $nStmt->execute([$cid]);
        $naamDb = $nStmt->fetchColumn();
        $geimporteerd = (bool)$naamDb;
        $naam = $naamDb ?: null;
        if (!$naam) {
            $fStmt->execute([$serieId, $cid]);
            $fb = $fStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $naam = $fb['comp_naam'] ?? $cid;
        }
        $compInfoCache[$cid] = [
            'naam'         => $naam,
            'datum'        => $w['starts'] ?? null,
            'is_finale'    => !empty($w['is_finale']) || $cid === $laatsteComp,
            'bonus_modus'  => !empty($bonusWedstrijden[$cid]),
            'bonus_punten' => (float)($bonusWedstrijden[$cid] ?? 0),
            'volgorde'     => (int)($w['volgorde'] ?? 0),
            'geimporteerd' => $geimporteerd,
        ];
    }
    if ($isAfstandLevel && !empty($pwKeyMeta)) {
        // Eén meta-entry per (comp, distance) — sorteer op volgorde van de
        // wedstrijd, daarna op distance-meters (kortste eerst). pwKeyMeta is
        // gevuld in de berekening hierboven.
        $entries = [];
        foreach ($pwKeyMeta as $pwKey => $pwInfo) {
            $cid = $pwInfo['comp_id'];
            $ci  = $compInfoCache[$cid] ?? null;
            if (!$ci) continue;
            $entries[] = [
                'key'           => $pwKey,
                'comp_id'       => $cid,
                'distance_id'   => $pwInfo['distance_id'],
                'distance_naam' => $pwInfo['distance_naam'],
                'naam'          => $ci['naam'],
                'datum'         => $ci['datum'],
                'is_finale'     => $ci['is_finale'],
                'bonus_modus'   => $ci['bonus_modus'] ?? false,
                'bonus_punten'  => $ci['bonus_punten'] ?? 0,
                'volgorde'      => $ci['volgorde'],
                'geimporteerd'  => $ci['geimporteerd'],
            ];
        }
        usort($entries, function($a, $b) {
            if ($a['volgorde'] !== $b['volgorde']) return $a['volgorde'] <=> $b['volgorde'];
            // Binnen comp: alfabetisch op distance_naam (meters niet voor
            // handen hier; namen sorteren stabiel genoeg)
            return strcmp((string)$a['distance_naam'], (string)$b['distance_naam']);
        });
        $meta = $entries;
    } else {
        // Klassieke modus: één entry per wedstrijd, key = comp_id (back-compat).
        foreach ($wedstrijden as $i => $w) {
            $cid = $w['competition_id'];
            $ci  = $compInfoCache[$cid] ?? [
                'naam' => $cid, 'datum' => null,
                'is_finale' => false, 'bonus_modus' => false, 'bonus_punten' => 0,
                'volgorde' => $i, 'geimporteerd' => false,
            ];
            $meta[] = [
                'key'          => $cid,        // = comp_id (back-compat)
                'comp_id'      => $cid,
                'naam'         => $ci['naam'],
                'datum'        => $ci['datum'],
                'is_finale'    => $ci['is_finale'],
                'bonus_modus'  => $ci['bonus_modus'] ?? false,
                'bonus_punten' => $ci['bonus_punten'] ?? 0,
                'volgorde'     => (int)($w['volgorde'] ?? $i),
                'geimporteerd' => $ci['geimporteerd'],
            ];
        }
    }

    return [
        'regels'           => $regels,
        'per_categorie'    => $perCat,
        'wedstrijden_meta' => $meta,
        'finale_gereden'   => $finaleGereden,
    ];
}

// ── Schrijf berekende resultaten naar `klassementen` + `klassement_posities` ─
function schrijfKlassement(PDO $pdo, array $serie, array $berekend): void {
    $klId = $serie['klassement_id'];
    $perCat = $berekend['per_categorie'];
    $totaal = 0;
    $catsLabels = [];

    // Wis oude posities
    $pdo->prepare("DELETE FROM klassement_posities WHERE klassement_id = ?")->execute([$klId]);

    $ins = $pdo->prepare("
        INSERT INTO klassement_posities
               (id, klassement_id, positie, start_number, license_key, naam, categorie,
                punten_detail, punten_totaal)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // Positie-toekenning:
    //  * Bovenblok (geklasseerd): rang 1…N. "Echt gelijk" = zelfde totaal én —
    //    zodra de finale gereden is — identieke tie-break-signatuur → gedeelde
    //    rang (ex aequo). Klassiek: 1, 2, 2, 4 (positie 3 overgeslagen).
    //  * Onderblok (buiten_klassement): positie 0 → geen rang, en telt niet mee
    //    in de rang-teller zodat het bovenblok aaneengesloten 1…N blijft.
    $finaleGereden = !empty($berekend['finale_gereden']);
    foreach ($perCat as $cat => $lijst) {
        $catsLabels[] = $cat;
        $vorigTotaal  = null;
        $vorigSig     = null;
        $vorigePos    = 0;
        $rangTeller   = 0;   // aantal geklasseerde rijders tot nu toe
        foreach ($lijst as $r) {
            $curTot = (float)($r['totaal'] ?? 0);
            $curSig = $r['_tb_sig'] ?? null;
            if (!empty($r['buiten_klassement'])) {
                $pos = 0;                       // onderblok: geen rang
            } else {
                $rangTeller++;
                $echtGelijk = $vorigTotaal !== null
                    && $curTot === $vorigTotaal
                    && ($finaleGereden ? ($curSig === $vorigSig) : true);
                $pos = $echtGelijk ? $vorigePos : $rangTeller;
                $vorigTotaal = $curTot;
                $vorigSig    = $curSig;
                $vorigePos   = $pos;
            }

            $kpId = substr(bin2hex(random_bytes(8)), 0, 16);
            // JSON-detail: comp_id → punten (vlakke map, zoals voorheen) plus
            // een aparte `_gestreept`-sleutel met een array van comp_ids die
            // bij de streepresultaten zijn weggehaald. De `_`-prefix zorgt
            // dat oudere lezers (die alleen comp_id-keys verwachten) deze
            // sleutel negeren — de waarde matcht geen comp_id.
            $detailObj = $r['per_wedstrijd'] ?? [];
            if (!empty($r['_gestreept'])) {
                $detailObj['_gestreept'] = array_values($r['_gestreept']);
            }
            $detail = json_encode($detailObj ?: new stdClass(), JSON_UNESCAPED_UNICODE);
            $ins->execute([
                $kpId, $klId, $pos,
                (string)($r['startnr'] ?? ''),
                $r['license'],
                $r['naam'],
                $cat,
                $detail,
                (float)($r['totaal'] ?? 0),
            ]);
            $totaal++;
        }
    }

    // Klassement-meta bijwerken (incl. wedstrijden-meta voor UI-kolommen)
    $wmeta = $berekend['wedstrijden_meta'] ?? [];
    $pdo->prepare("
        UPDATE klassementen
        SET categorieen = ?, totaal_rijders = ?, wedstrijden_meta = ?
        WHERE id = ?
    ")->execute([
        json_encode(array_values(array_unique($catsLabels))),
        $totaal,
        json_encode($wmeta, JSON_UNESCAPED_UNICODE),
        $klId,
    ]);

    $pdo->prepare("UPDATE klassement_series SET herberekend_op = NOW() WHERE id = ?")
        ->execute([$serie['id']]);
}

// ──────────────────────────────────────────────────────────────────────────
//  GET: list / get
// ──────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        // ── Kandidaat-wedstrijden voor een serie ─────────────────────────
        // Combineert:
        //   (a) eigen DB-wedstrijden met `organisatie_id = X`
        //   (b) toekomstige KNSB-wedstrijden die matchen op email of
        //       exacte (alias-)naam van de organisatie.
        // Response: [{competition_id, name, starts, geimporteerd:bool}]
        if ($action === 'kandidaat_wedstrijden') {
            $orgId = trim($_GET['org_id'] ?? '');
            if (!$orgId) { echo json_encode([]); exit; }

            // Org-profiel: email + canonieke naam + aliassen
            $org = $pdo->prepare("SELECT email, naam FROM organisaties WHERE id = ?");
            $org->execute([$orgId]);
            $orgData = $org->fetch(PDO::FETCH_ASSOC) ?: [];
            $orgEmail = strtolower(trim($orgData['email'] ?? ''));
            $orgNamen = [];
            if (!empty($orgData['naam'])) $orgNamen[] = strtolower(trim($orgData['naam']));
            $al = $pdo->prepare("SELECT naam FROM organisatie_aliassen WHERE organisatie_id = ?");
            $al->execute([$orgId]);
            foreach ($al->fetchAll(PDO::FETCH_COLUMN) as $n) {
                $n = strtolower(trim($n));
                if ($n) $orgNamen[] = $n;
            }

            // (a) eigen DB
            $dbSt = $pdo->prepare("
                SELECT id AS competition_id, name, starts
                FROM competitions WHERE organisatie_id = ?
                ORDER BY starts
            ");
            $dbSt->execute([$orgId]);
            $lijst = [];
            $gezien = [];
            foreach ($dbSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['geimporteerd'] = true;
                $lijst[] = $r;
                $gezien[$r['competition_id']] = true;
            }

            // (b) KNSB-API (inline vanaf een week geleden)
            $url = 'https://inschrijven.schaatsen.nl/api/competitions';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            curl_close($ch);
            $knsb = json_decode($resp, true);
            if (is_array($knsb)) {
                foreach ($knsb as $c) {
                    $disc = strtolower($c['discipline'] ?? '');
                    if (strpos($disc, 'speedskating.inline') === false) continue;
                    $cid = $c['id'] ?? '';
                    if (!$cid || isset($gezien[$cid])) continue;
                    $cEmail = strtolower(trim($c['settings']['contact']['email'] ?? ''));
                    $cNaam  = strtolower(trim(
                        $c['settings']['contact']['organizationName']
                        ?? $c['organizer']['name']
                        ?? $c['organiser']['name']
                        ?? ''
                    ));
                    $match = ($orgEmail && $cEmail && $cEmail === $orgEmail)
                          || ($cNaam && in_array($cNaam, $orgNamen, true));
                    if (!$match) continue;
                    $lijst[] = [
                        'competition_id' => $cid,
                        'name'           => $c['name']   ?? '(onbekend)',
                        'starts'         => $c['starts'] ?? null,
                        'geimporteerd'   => false,
                    ];
                }
            }
            usort($lijst, fn($a,$b) => strcmp($a['starts'] ?? '', $b['starts'] ?? ''));
            echo json_encode($lijst, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Distinct KNSB-categorieën van rijders die in een set wedstrijden
        // zijn ingeschreven. Bedoeld voor de "Categorie-filter"-checkbox-
        // lijst in de serie-wizard, zodat de operator alleen aanvinkt uit
        // wat daadwerkelijk in deze serie voorkomt — geen typefouten meer.
        // Param: comp_ids (komma-gescheiden lijst van competition_ids).
        //
        // entries.competition_id bestaat niet — entries hangt aan
        // distance_combination_id, en dc heeft competition_id. Dus
        // joinen via distance_combinations om competition-scope te krijgen.
        // Distinct afstand-namen + race_type van een set wedstrijden. Bedoeld
        // voor de "Afstanden voor dit klassement"-checkbox-lijst in de serie-
        // wizard. Per afstand-naam pakken we min(race_type) zodat de UI per
        // afstand kan tonen of 't sprint of lange afstand is. Param: comp_ids.
        if ($action === 'afstanden_van_wedstrijden') {
            $idsRaw = trim($_GET['comp_ids'] ?? '');
            $compIds = array_values(array_filter(array_map('trim', explode(',', $idsRaw))));
            if (empty($compIds)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($compIds), '?'));
            $st = $pdo->prepare("
                SELECT d.name AS naam,
                       MIN(d.race_type) AS race_type,
                       MIN(d.value_meters) AS meters
                FROM distances d
                JOIN distance_combinations dc ON dc.id = d.distance_combination_id
                WHERE dc.competition_id IN ($ph)
                  AND d.name IS NOT NULL
                  AND TRIM(d.name) <> ''
                GROUP BY d.name
                ORDER BY meters, d.name
            ");
            $st->execute($compIds);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(array_values(array_filter($rows, fn($r) => !empty($r['naam']))),
                JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'categorieen_van_wedstrijden') {
            $idsRaw = trim($_GET['comp_ids'] ?? '');
            $compIds = array_values(array_filter(array_map('trim', explode(',', $idsRaw))));
            if (empty($compIds)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($compIds), '?'));
            $st = $pdo->prepare("
                SELECT DISTINCT UPPER(TRIM(p.category)) AS cat
                FROM entries e
                JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                JOIN persons p ON p.license_key = e.person_license
                WHERE dc.competition_id IN ($ph)
                  AND p.category IS NOT NULL
                  AND TRIM(p.category) <> ''
                ORDER BY cat
            ");
            $st->execute($compIds);
            $cats = array_values(array_filter(
                $st->fetchAll(PDO::FETCH_COLUMN),
                fn($c) => $c !== null && $c !== ''
            ));
            echo json_encode($cats, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'list') {
            $org = trim($_GET['org_id'] ?? '');
            $sql = "
                SELECT s.id, s.naam, s.seizoen, s.org_id, s.regels,
                       s.klassement_id, s.aangemaakt_op, s.herberekend_op,
                       s.gepubliceerd_at,
                       k.totaal_rijders, k.categorieen
                FROM klassement_series s
                JOIN klassementen k ON k.id = s.klassement_id
            ";
            $params = [];
            if ($org !== '') { $sql .= " WHERE s.org_id = ?"; $params[] = $org; }
            $sql .= " ORDER BY s.aangemaakt_op DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['regels']      = json_decode($r['regels'] ?? '{}', true);
                $r['categorieen'] = json_decode($r['categorieen'] ?? '[]', true);
            }
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            exit;
        }
        // ── Diagnose: per wedstrijd hoeveel rijen in uitslag_klassement ──
        //   Handig als een serie leeg blijft: zie je direct welke wedstrijd
        //   nog geen bevestigd eindklassement heeft.
        if ($action === 'diag' && $id) {
            // Per wedstrijd: basis-tellingen
            $stmt = $pdo->prepare("
                SELECT w.competition_id,
                       COALESCE(c.name, w.comp_naam) AS name,
                       COALESCE(c.starts, w.comp_datum) AS starts,
                       w.telt_mee, w.is_finale,
                       (SELECT COUNT(*) FROM uitslag_klassement uk
                        WHERE uk.competition_id = w.competition_id) AS uk_rijen,
                       (SELECT COUNT(*) FROM uitslag_afstand ua
                        WHERE ua.competition_id = w.competition_id) AS ua_rijen,
                       (c.id IS NOT NULL) AS geimporteerd
                FROM klassement_serie_wedstrijden w
                LEFT JOIN competitions c ON c.id = w.competition_id
                WHERE w.serie_id = ?
                ORDER BY w.volgorde, starts
            ");
            $stmt->execute([$id]);
            $wedstrijden = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Deep dive: pak de serie-regels en probeer de berekening; rapporteer
            // per stap hoeveel rijen er overblijven. Zo zien we waar ze sneuvelen.
            $sSt = $pdo->prepare("SELECT regels FROM klassement_series WHERE id = ?");
            $sSt->execute([$id]);
            $regels = normaliseerRegels(json_decode($sSt->fetchColumn() ?: '{}', true) ?: []);

            // Per meetellende wedstrijd: DC-types + filter-reden
            $diag = [];
            foreach ($wedstrijden as $w) {
                if (!$w['telt_mee']) { $diag[] = ['comp_id' => $w['competition_id'], 'skip' => 'niet-meetellend']; continue; }
                $cid = $w['competition_id'];
                // DC-types voor deze wedstrijd
                $dcs = $pdo->prepare("
                    SELECT dc.id AS dc_id, dc.name AS dc_naam,
                           COUNT(d.id) AS n_afstanden,
                           MIN(d.race_type) AS race_type
                    FROM distance_combinations dc
                    LEFT JOIN distances d ON d.distance_combination_id = dc.id
                    WHERE dc.competition_id = ?
                    GROUP BY dc.id, dc.name
                ");
                $dcs->execute([$cid]);
                $dcList = [];
                foreach ($dcs->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    $n = (int)$d['n_afstanden'];
                    $t = $n === 0 ? 'leeg' : ($n > 1 ? 'gecombineerd' : (($d['race_type'] === 'sprint') ? 'sprint' : 'lang'));
                    $passes = ($regels['afstand_filter'] === 'alle')
                           || ($regels['afstand_filter'] === 'sprint' && $t === 'sprint')
                           || ($regels['afstand_filter'] === 'lang'   && $t === 'lang');
                    // Hoeveel rijen uitslag_klassement voor deze DC?
                    $c2 = $pdo->prepare("SELECT COUNT(*) FROM uitslag_klassement WHERE competition_id = ? AND distance_combination_id = ?");
                    $c2->execute([$cid, $d['dc_id']]);
                    $dcList[] = [
                        'dc_id'        => $d['dc_id'],
                        'dc_naam'      => $d['dc_naam'],
                        'n_afstanden'  => $n,
                        'dc_type'      => $t,
                        'passes_filter'=> (bool)$passes,
                        'uk_rijen'     => (int)$c2->fetchColumn(),
                    ];
                }
                $diag[] = ['comp_id' => $cid, 'dcs' => $dcList];
            }

            // Pipeline-telling: hoeveel rijen komen er door elke filter-stap?
            $pipeline = [
                'uit_uk'           => 0,
                'na_dc_filter'     => 0,
                'na_punten_filter' => 0,
                'na_rang_filter'   => 0,
                'rijders_uniek'    => 0,
                'in_klassement'    => 0,  // na min_deelnames + vereist_finale
                'voorbeelden_weg'  => [],
            ];
            $compIds = array_values(array_filter(array_map(fn($w) => $w['telt_mee'] ? $w['competition_id'] : null, $wedstrijden)));
            if ($compIds) {
                $ph = implode(',', array_fill(0, count($compIds), '?'));
                $dcInfo = $pdo->prepare("
                    SELECT dc.id, COUNT(d.id) AS n_afst, MIN(d.race_type) AS rt
                    FROM distance_combinations dc
                    LEFT JOIN distances d ON d.distance_combination_id = dc.id
                    WHERE dc.competition_id IN ($ph)
                    GROUP BY dc.id
                ");
                $dcInfo->execute($compIds);
                $dcT = [];
                foreach ($dcInfo->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    $n = (int)$d['n_afst'];
                    $dcT[$d['id']] = $n===0 ? 'leeg' : ($n>1 ? 'gecombineerd' : ($d['rt']==='sprint'?'sprint':'lang'));
                }
                $dcPass = function($t) use ($regels) {
                    if (!$t || $t === 'leeg') return false;
                    return $regels['afstand_filter'] === 'alle'
                        || ($regels['afstand_filter'] === 'sprint' && $t === 'sprint')
                        || ($regels['afstand_filter'] === 'lang'   && $t === 'lang');
                };
                $uk = $pdo->prepare("
                    SELECT uk.person_license, uk.distance_combination_id AS dc_id,
                           uk.rang, uk.punten_totaal, uk.categorie,
                           p.full_name, p.category AS persoon_cat
                    FROM uitslag_klassement uk
                    JOIN persons p ON p.license_key = uk.person_license
                    WHERE uk.competition_id IN ($ph)
                ");
                $uk->execute($compIds);
                $rijders = [];
                foreach ($uk->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $pipeline['uit_uk']++;
                    $t = $dcT[$r['dc_id']] ?? null;
                    if (!$dcPass($t)) {
                        if (count($pipeline['voorbeelden_weg']) < 3)
                            $pipeline['voorbeelden_weg'][] = "DC-filter: {$r['full_name']} (dc-type={$t})";
                        continue;
                    }
                    $pipeline['na_dc_filter']++;
                    if ((float)($r['punten_totaal'] ?? 0) <= 0) {
                        if (count($pipeline['voorbeelden_weg']) < 3)
                            $pipeline['voorbeelden_weg'][] = "Punten<=0: {$r['full_name']} (punten={$r['punten_totaal']})";
                        continue;
                    }
                    $pipeline['na_punten_filter']++;
                    if ($r['rang'] === null) {
                        if (count($pipeline['voorbeelden_weg']) < 3)
                            $pipeline['voorbeelden_weg'][] = "Rang=NULL: {$r['full_name']}";
                        continue;
                    }
                    $pipeline['na_rang_filter']++;
                    $rijders[$r['person_license']] = ($r['persoon_cat'] ?? $r['categorie'] ?? '(leeg)');
                }
                $pipeline['rijders_uniek'] = count($rijders);
                // Simuleer min_deelnames niet: dat is een laatste stap. Voor nu lopen we tot hier.
                $pipeline['in_klassement'] = 'wordt na herberekenen pas zichtbaar';
            }

            // Extra check: finale-status. Als de finale nog niet gereden is,
            // worden min_deelnames en vereist_finale NIET toegepast (zie fix
            // in berekenSerie). Status komt uit uitslag_klassement van de
            // gekozen finale-wedstrijd.
            $finaleId = null;
            foreach ($wedstrijden as $w) {
                if (!empty($w['is_finale'])) { $finaleId = $w['competition_id']; break; }
            }
            if (!$finaleId && $wedstrijden) {
                $laatste = end($wedstrijden); $finaleId = $laatste['competition_id'];
            }
            $finaleGereden = false;
            if ($finaleId) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM uitslag_klassement WHERE competition_id = ?");
                $q->execute([$finaleId]);
                $finaleGereden = ((int)$q->fetchColumn()) > 0;
            }

            echo json_encode([
                'wedstrijden'    => $wedstrijden,
                'regels'         => $regels,
                'deep'           => $diag,
                'pipeline'       => $pipeline,
                'finale_id'      => $finaleId,
                'finale_gereden' => $finaleGereden,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'get' && $id) {
            $stmt = $pdo->prepare("
                SELECT s.*, k.totaal_rijders, k.categorieen
                FROM klassement_series s
                JOIN klassementen k ON k.id = s.klassement_id
                WHERE s.id = ?
            ");
            $stmt->execute([$id]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$s) { http_response_code(404); echo json_encode(['error' => 'Niet gevonden']); exit; }
            $s['regels']      = json_decode($s['regels'] ?? '{}', true);
            $s['categorieen'] = json_decode($s['categorieen'] ?? '[]', true);
            // Gekoppelde wedstrijden
            $wStmt = $pdo->prepare("
                SELECT w.competition_id, w.telt_mee, w.is_finale,
                       w.bonus_modus, w.bonus_punten, w.volgorde,
                       COALESCE(c.name,   w.comp_naam)  AS name,
                       COALESCE(c.starts, w.comp_datum) AS starts,
                       (c.id IS NOT NULL)              AS geimporteerd
                FROM klassement_serie_wedstrijden w
                LEFT JOIN competitions c ON c.id = w.competition_id
                WHERE w.serie_id = ?
                ORDER BY w.volgorde, starts
            ");
            $wStmt->execute([$id]);
            $s['wedstrijden'] = $wStmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($s, JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Onbekende actie']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ──────────────────────────────────────────────────────────────────────────
//  POST: create / update / berekenen / delete
// ──────────────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    // ── Publiceer / intrek publicatie ──────────────────────────────────────
    // Publicatie-status (gepubliceerd_at) bepaalt of /public en /coach het
    // serie-klassement zien. Niet-gepubliceerd = onzichtbaar voor publiek
    // (handig voor test-/probeer-series). Operator klikt expliciet.
    if (($action === 'publiceer' || $action === 'trek_in') && $id) {
        $check = $pdo->prepare("SELECT id FROM klassement_series WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Serie niet gevonden']);
            exit;
        }
        if ($action === 'publiceer') {
            $pdo->prepare("UPDATE klassement_series SET gepubliceerd_at = NOW() WHERE id = ?")
                ->execute([$id]);
        } else {
            $pdo->prepare("UPDATE klassement_series SET gepubliceerd_at = NULL WHERE id = ?")
                ->execute([$id]);
        }
        $st = $pdo->prepare("SELECT gepubliceerd_at FROM klassement_series WHERE id = ?");
        $st->execute([$id]);
        echo json_encode(['ok' => true, 'gepubliceerd_at' => $st->fetchColumn()],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete' && $id) {
        $stmt = $pdo->prepare("SELECT klassement_id FROM klassement_series WHERE id = ?");
        $stmt->execute([$id]);
        $klId = $stmt->fetchColumn();
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM klassement_series WHERE id = ?")->execute([$id]);
        if ($klId) {
            $pdo->prepare("DELETE FROM klassement_posities WHERE klassement_id = ?")->execute([$klId]);
            $pdo->prepare("DELETE FROM klassementen WHERE id = ?")->execute([$klId]);
        }
        $pdo->commit();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'berekenen' && $id) {
        $stmt = $pdo->prepare("SELECT * FROM klassement_series WHERE id = ?");
        $stmt->execute([$id]);
        $serie = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$serie) { http_response_code(404); echo json_encode(['error' => 'Niet gevonden']); exit; }
        $pdo->beginTransaction();
        $ber = berekenSerie($pdo, $id);
        schrijfKlassement($pdo, $serie, $ber);
        $pdo->commit();
        echo json_encode(['ok' => true, 'totaal_rijders' => array_sum(array_map('count', $ber['per_categorie']))]);
        exit;
    }

    if ($action === 'create') {
        $naam     = trim($body['naam']    ?? '');
        $seizoen  = trim($body['seizoen'] ?? '') ?: null;
        $orgId    = trim($body['org_id']  ?? '') ?: null;
        $regels   = normaliseerRegels(is_array($body['regels'] ?? null) ? $body['regels'] : []);
        $wedstr   = is_array($body['wedstrijden'] ?? null) ? $body['wedstrijden'] : [];
        if ($naam === '') { http_response_code(400); echo json_encode(['error' => 'Naam is verplicht']); exit; }

        $pdo->beginTransaction();
        // 1. Maak een 'klassementen'-rij (output)
        $klId = uuid4();
        $pdo->prepare("
            INSERT INTO klassementen (id, naam, seizoen, bron_bestand, categorieen, totaal_rijders, org_id)
            VALUES (?, ?, ?, ?, '[]', 0, ?)
        ")->execute([$klId, $naam, $seizoen, '(serie-berekening)', $orgId]);
        // 2. Maak de serie
        $serieId = uuid4();
        $pdo->prepare("
            INSERT INTO klassement_series (id, klassement_id, naam, seizoen, org_id, regels)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$serieId, $klId, $naam, $seizoen, $orgId, json_encode($regels, JSON_UNESCAPED_UNICODE)]);
        // 3. Koppel wedstrijden
        $wIns = $pdo->prepare("
            INSERT INTO klassement_serie_wedstrijden
                   (serie_id, competition_id, telt_mee, is_finale,
                    bonus_modus, bonus_punten, volgorde,
                    comp_naam, comp_datum)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($wedstr as $i => $w) {
            $cid = trim($w['competition_id'] ?? '');
            if ($cid === '') continue;
            $bonusModus = !empty($w['bonus_modus']) ? 1 : 0;
            $wIns->execute([
                $serieId, $cid,
                !empty($w['telt_mee'])  ? 1 : 0,
                !empty($w['is_finale']) ? 1 : 0,
                $bonusModus,
                $bonusModus ? (float)($w['bonus_punten'] ?? 1) : 1,
                (int)($w['volgorde'] ?? $i),
                trim($w['comp_naam'] ?? '') ?: null,
                trim($w['comp_datum'] ?? '') ?: null,
            ]);
        }
        // 4. Bereken
        $serie = [
            'id' => $serieId, 'klassement_id' => $klId, 'regels' => json_encode($regels),
        ];
        $ber = berekenSerie($pdo, $serieId);
        schrijfKlassement($pdo, $serie, $ber);
        $pdo->commit();

        echo json_encode(['ok' => true, 'id' => $serieId, 'klassement_id' => $klId]);
        exit;
    }

    if ($action === 'update' && $id) {
        $naam     = trim($body['naam']    ?? '');
        $seizoen  = trim($body['seizoen'] ?? '') ?: null;
        $orgId    = trim($body['org_id']  ?? '') ?: null;
        $regels   = normaliseerRegels(is_array($body['regels'] ?? null) ? $body['regels'] : []);
        $wedstr   = is_array($body['wedstrijden'] ?? null) ? $body['wedstrijden'] : [];
        if ($naam === '') { http_response_code(400); echo json_encode(['error' => 'Naam is verplicht']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM klassement_series WHERE id = ?");
        $stmt->execute([$id]);
        $serie = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$serie) { http_response_code(404); echo json_encode(['error' => 'Niet gevonden']); exit; }

        $pdo->beginTransaction();
        // Update serie-rij
        $pdo->prepare("
            UPDATE klassement_series
            SET naam = ?, seizoen = ?, org_id = ?, regels = ?
            WHERE id = ?
        ")->execute([$naam, $seizoen, $orgId, json_encode($regels, JSON_UNESCAPED_UNICODE), $id]);
        // Update klassementen-meta
        $pdo->prepare("UPDATE klassementen SET naam = ?, seizoen = ?, org_id = ? WHERE id = ?")
            ->execute([$naam, $seizoen, $orgId, $serie['klassement_id']]);
        // Wedstrijden: wissen en opnieuw invoegen (eenvoudig en veilig)
        $pdo->prepare("DELETE FROM klassement_serie_wedstrijden WHERE serie_id = ?")->execute([$id]);
        $wIns = $pdo->prepare("
            INSERT INTO klassement_serie_wedstrijden
                   (serie_id, competition_id, telt_mee, is_finale,
                    bonus_modus, bonus_punten, volgorde,
                    comp_naam, comp_datum)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($wedstr as $i => $w) {
            $cid = trim($w['competition_id'] ?? '');
            if ($cid === '') continue;
            $bonusModus = !empty($w['bonus_modus']) ? 1 : 0;
            $wIns->execute([
                $id, $cid,
                !empty($w['telt_mee'])  ? 1 : 0,
                !empty($w['is_finale']) ? 1 : 0,
                $bonusModus,
                $bonusModus ? (float)($w['bonus_punten'] ?? 1) : 1,
                (int)($w['volgorde'] ?? $i),
                trim($w['comp_naam'] ?? '') ?: null,
                trim($w['comp_datum'] ?? '') ?: null,
            ]);
        }
        // Herbereken
        $ber = berekenSerie($pdo, $id);
        schrijfKlassement($pdo, $serie, $ber);
        $pdo->commit();

        echo json_encode(['ok' => true, 'totaal_rijders' => array_sum(array_map('count', $ber['per_categorie']))]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
