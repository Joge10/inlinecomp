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
    return [
        'type'                    => $type,
        'afstand_filter'          => $filter,
        'afstand_namen'           => $namen,
        'punten_tabel'            => $tabel,
        'min_punten_bij_deelname' => (float)($in['min_punten_bij_deelname'] ?? 1),
        'streepresultaten'        => max(0, (int)($in['streepresultaten'] ?? 0)),
        'min_deelnames'           => max(0, (int)($in['min_deelnames'] ?? 0)),
        'tie_break'               => $tieBreak,
        'vereist_finale'          => !empty($in['vereist_finale']),
    ];
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
               w.volgorde, w.is_finale
        FROM klassement_serie_wedstrijden w
        LEFT JOIN competitions c ON c.id = w.competition_id
        WHERE w.serie_id = ? AND w.telt_mee = 1
        ORDER BY w.volgorde, starts
    ");
    $wStmt->execute([$serieId]);
    $wedstrijden = $wStmt->fetchAll(PDO::FETCH_ASSOC);
    $compIds = array_column($wedstrijden, 'competition_id');
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

    // Accumulator per (license_key, categorie)
    //   [lic][cat] = ['naam'=>…, 'startnr'=>…, 'totaal'=>0.0, 'detail'=>[wedstrijd_naam=>punten], 'deelnames'=>N]
    $acc = [];

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

        // DC-filter: welke DCs horen bij dit serie-type?
        $dcOk = function($dcId) use ($regels, $dcTypes) {
            $t = $dcTypes[$dcId] ?? null;
            if (!$t || $t === 'leeg') return false;
            switch ($regels['afstand_filter']) {
                case 'sprint':   return $t === 'sprint';
                case 'lang':     return $t === 'lang';
                case 'alle':
                default:         return true; // ook sprint/lang/gecombineerd allemaal OK
            }
        };

        // ── Stap 2a: welke rijders zijn UITGESLOTEN per (comp, dc)?
        //   De UI sluit rijders uit als admin `punten = 0` op een afstand heeft
        //   gezet (sanctie / DNS / bewust weggeklikt). In `uitslag_klassement`
        //   kunnen oude rijen met rang+punten nog blijven staan — daarom
        //   kruisen we hier tegen `uitslag_afstand`. Als er voor deze rijder
        //   in deze DC een `punten = 0`-rij is, slaan we 'm over.
        $uitgeslotenSql = "
            SELECT DISTINCT competition_id, distance_combination_id, person_license
            FROM uitslag_afstand
            WHERE competition_id IN ($ph) AND punten = 0
        ";
        $uStmt = $pdo->prepare($uitgeslotenSql);
        $uStmt->execute($compIds);
        $uitgeslotenSet = [];
        foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = $row['competition_id'] . '|' . $row['distance_combination_id'] . '|' . $row['person_license'];
            $uitgeslotenSet[$k] = true;
        }

        // ── Stap 2: lees eindklassement per (comp, dc, split_group) uit
        //   uitslag_klassement. Filter op het DC-type.
        $kSql = "
            SELECT uk.competition_id, uk.distance_combination_id AS dc_id, uk.dc_naam,
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
        $rijen = array_values(array_filter($rijen, function($r) use ($dcOk, $regels, $uitgeslotenSet) {
            if (!$dcOk($r['dc_id'])) return false;
            if ((float)($r['punten_totaal'] ?? 0) <= 0) return false;
            if ($r['rang'] === null) return false;
            // Kruis-check: staat deze rijder in uitslag_afstand met een
            // punten=0 (= uitgesloten door admin) voor deze DC? Dan
            // negeren — ook al heeft de uitslag_klassement-rij nog oude data.
            $k = $r['competition_id'] . '|' . $r['dc_id'] . '|' . $r['person_license'];
            if (isset($uitgeslotenSet[$k])) return false;
            return true;
        }));

        // Groeperen per (competition_id, dc_id, split_group, persoons-categorie)
        // om binnen-cat-rang toe te kennen.
        $groepen = [];
        // Én: ruimere groepering per (comp, dc, split) om voor gemengde DCs
        // óók een gecombineerd klassement ("cluster") op te bouwen. Labelt als
        // gesorteerde cat-codes met "/" ertussen (bv. "DP1/DP2/DP3"). Wordt
        // gebruikt voor seeding bij vervolgwedstrijden die dezelfde cluster
        // weer gecombineerd laten rijden.
        $dcGroepen = [];
        foreach ($rijen as $r) {
            $key = implode('|', [
                $r['competition_id'],
                $r['dc_id'],
                $r['split_group'] ?? '',
                $r['persoon_cat'] ?? '',
            ]);
            $groepen[$key][] = $r;
            $dcKey = implode('|', [
                $r['competition_id'],
                $r['dc_id'],
                $r['split_group'] ?? '',
            ]);
            $dcGroepen[$dcKey][] = $r;
        }

        foreach ($groepen as $groep) {
            // Sorteer binnen de (comp, DC, splitgroep, cat) op absolute rang ASC
            usort($groep, function($a, $b) {
                $ra = $a['rang'] !== null ? (int)$a['rang'] : PHP_INT_MAX;
                $rb = $b['rang'] !== null ? (int)$b['rang'] : PHP_INT_MAX;
                return $ra <=> $rb;
            });
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
                $wNaam = $r['comp_naam'] . ' · ' . ($r['dc_naam'] ?? '?');
                $acc[$lic][$cat]['detail'][$wNaam] =
                    ($acc[$lic][$cat]['detail'][$wNaam] ?? 0) + $punten;
                $acc[$lic][$cat]['per_wedstrijd'][$r['competition_id']] =
                    ($acc[$lic][$cat]['per_wedstrijd'][$r['competition_id']] ?? 0) + $punten;
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
                $acc[$lic][$clusterLabel]['detail'][$wNaam] =
                    ($acc[$lic][$clusterLabel]['detail'][$wNaam] ?? 0) + $punten;
                $acc[$lic][$clusterLabel]['per_wedstrijd'][$r['competition_id']] =
                    ($acc[$lic][$clusterLabel]['per_wedstrijd'][$r['competition_id']] ?? 0) + $punten;
                if (!empty($r['wedstrijd_snr'])) $acc[$lic][$clusterLabel]['startnr'] = $r['wedstrijd_snr'];
            }
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
    $streep = $finaleGereden ? (int)$regels['streepresultaten'] : 0;
    $minD   = $finaleGereden ? (int)$regels['min_deelnames']    : 0;
    $vereistFin = $finaleGereden ? !empty($regels['vereist_finale']) : false;
    foreach ($acc as $lic => $perCatMap) {
        foreach ($perCatMap as $cat => $row) {
            if ($minD > 0 && $row['deelnames'] < $minD) continue;
            if ($vereistFin && !$row['in_laatste']) continue;

            // Punten per wedstrijd DESC — gebruikt voor streepresultaten én tie-break.
            $rijdensPunten = array_values($row['per_wedstrijd']);
            rsort($rijdensPunten);

            // Streepresultaten: drop de N slechtste wedstrijden. Ook als de
            // finale-wedstrijd toevallig de slechtste is, wordt die gewoon
            // weggestreept — aanwezigheid bij finale (vereist_finale) is
            // een separate regel en heeft geen invloed op wélke scores blijven.
            if ($streep > 0 && count($rijdensPunten) > $streep) {
                $teHouden = count($rijdensPunten) - $streep;
                $gekozen = array_slice($rijdensPunten, 0, $teHouden);
                $row['totaal'] = array_sum($gekozen);
                $row['_beste_resultaten'] = $gekozen;
            } else {
                $row['_beste_resultaten'] = $rijdensPunten;
            }
            $row['_laatste_punten'] = $row['per_wedstrijd'][$laatsteComp] ?? 0;
            $perCat[$cat][] = $row;
        }
    }

    // Vergelijkfunctie: totaal DESC, daarna tie-break volgens regel.
    $cmpTB = function($a, $b) use ($regels) {
        $tb = $regels['tie_break'];
        $cmpBeste = function($a, $b) {
            // Vergelijk beste-resultaten-vector lexicografisch DESC.
            $n = max(count($a['_beste_resultaten']), count($b['_beste_resultaten']));
            for ($i = 0; $i < $n; $i++) {
                $av = $a['_beste_resultaten'][$i] ?? 0;
                $bv = $b['_beste_resultaten'][$i] ?? 0;
                if ($av != $bv) return $bv <=> $av;
            }
            return 0;
        };
        $cmpLaatste = fn($a, $b) => ($b['_laatste_punten'] ?? 0) <=> ($a['_laatste_punten'] ?? 0);
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
        usort($lijst, function($a, $b) use ($cmpTB, $finaleGereden) {
            $c = $b['totaal'] <=> $a['totaal'];
            if ($c !== 0) return $c;
            return $finaleGereden ? $cmpTB($a, $b) : 0;
        });
        foreach ($lijst as &$r) {
            unset($r['_beste_resultaten'], $r['_laatste_punten']);
        }
    }
    unset($lijst);

    // Meta over de wedstrijden (voor UI-kolommen). Naam-lookup eerst in
    // competitions; als de wedstrijd nog niet is geïmporteerd, gebruik de
    // opgeslagen comp_naam uit de koppelrij als fallback.
    $meta = [];
    $nStmt = $pdo->prepare("SELECT name FROM competitions WHERE id = ?");
    $fStmt = $pdo->prepare("SELECT comp_naam, comp_datum FROM klassement_serie_wedstrijden WHERE serie_id = ? AND competition_id = ?");
    foreach ($wedstrijden as $i => $w) {
        $nStmt->execute([$w['competition_id']]);
        $naamDb = $nStmt->fetchColumn();          // false → niet in competitions
        $geimporteerd = (bool)$naamDb;
        $naam = $naamDb ?: null;
        if (!$naam) {
            $fStmt->execute([$serieId, $w['competition_id']]);
            $fb = $fStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $naam = $fb['comp_naam'] ?? $w['competition_id'];
        }
        $meta[] = [
            'comp_id'       => $w['competition_id'],
            'naam'          => $naam,
            'datum'         => $w['starts'] ?? null,
            'is_finale'     => !empty($w['is_finale']) || $w['competition_id'] === $laatsteComp,
            'volgorde'      => (int)($w['volgorde'] ?? $i),
            'geimporteerd'  => $geimporteerd,
        ];
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
    // Positie-toekenning met ex-aequo bij tussenstand: als de finale nog niet
    // gereden is en twee rijders hebben dezelfde totaal-score krijgen ze
    // dezelfde positie. Klassiek: 1, 2, 2, 4 (positie 3 wordt overgeslagen).
    $finaleGereden = !empty($berekend['finale_gereden']);
    foreach ($perCat as $cat => $lijst) {
        $catsLabels[] = $cat;
        $vorigTotaal  = null;
        $vorigePos    = 0;
        foreach ($lijst as $i => $r) {
            $curTot = (float)($r['totaal'] ?? 0);
            if (!$finaleGereden && $vorigTotaal !== null && $curTot === $vorigTotaal) {
                $pos = $vorigePos;   // ex aequo met vorige
            } else {
                $pos = $i + 1;        // klassieke sprong (1,2,4 bij 2 ex aequo)
            }
            $vorigTotaal = $curTot;
            $vorigePos   = $pos;

            $kpId = substr(bin2hex(random_bytes(8)), 0, 16);
            $detail = json_encode($r['per_wedstrijd'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
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

        if ($action === 'list') {
            $org = trim($_GET['org_id'] ?? '');
            $sql = "
                SELECT s.id, s.naam, s.seizoen, s.org_id, s.regels,
                       s.klassement_id, s.aangemaakt_op, s.herberekend_op,
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
                SELECT w.competition_id, w.telt_mee, w.is_finale, w.volgorde,
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
                   (serie_id, competition_id, telt_mee, is_finale, volgorde,
                    comp_naam, comp_datum)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($wedstr as $i => $w) {
            $cid = trim($w['competition_id'] ?? '');
            if ($cid === '') continue;
            $wIns->execute([
                $serieId, $cid,
                !empty($w['telt_mee'])  ? 1 : 0,
                !empty($w['is_finale']) ? 1 : 0,
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
                   (serie_id, competition_id, telt_mee, is_finale, volgorde,
                    comp_naam, comp_datum)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($wedstr as $i => $w) {
            $cid = trim($w['competition_id'] ?? '');
            if ($cid === '') continue;
            $wIns->execute([
                $id, $cid,
                !empty($w['telt_mee'])  ? 1 : 0,
                !empty($w['is_finale']) ? 1 : 0,
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
