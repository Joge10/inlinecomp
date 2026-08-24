<?php
// ============================================================
//  InlineComp – Gedeelde uitslag-hulpfuncties
//  Wordt geïnclude door uitslag_afstand.php, klassement_live.php
//  en uitslag_vastleggen.php.
// ============================================================

// ── Multi-sanctie helpers ───────────────────────────────────────────────────
// Een rijder kan in dezelfde heat meerdere sancties krijgen (bv. W1 + W2 +
// DQ-SF + FS allemaal in 1 rit). Opgeslagen als comma-separated string in
// results.sanctie / uitslag_afstand.sanctie. Helpers hieronder maken de
// rest van de code agnostisch voor 1 vs N sancties.

// Geldige codes — synchroon met UI live.js dropdown en startlijst_laden.php
// validatie. Volgorde = canonieke severity-ranking (gebruikt bij display-sort).
const SANCTIE_CODES = ['DQ-DF', 'DQ-SF', 'DQ-TF', 'DNF', 'DNS', 'FS', 'W2', 'W1', 'RR'];

// Splits "W1,W2,DQ-SF" → ['W1','W2','DQ-SF']. Lege/null input → []. Voor
// backwards compat: oude enkele waarden ('DQ-TF') worden 1-item array.
function sancties_split(?string $s): array {
    if ($s === null) return [];
    $s = trim($s);
    if ($s === '') return [];
    $parts = array_map('trim', explode(',', $s));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

// Bool: bevat de string ten minste 1 van de gegeven codes?
function sancties_heeft_any(?string $s, array $codes): bool {
    if ($s === null || $s === '') return false;
    $have = sancties_split($s);
    return (bool)array_intersect($have, $codes);
}

// Validatie + normalisatie: filter onbekende codes weg, dedupe, sort op
// canonieke severity, herform tot comma-separated string. Lege array → null.
// Gebruik bij ontvangst van user-input vóór persistentie.
function sancties_normaliseer($input): ?string {
    if (is_string($input)) {
        $arr = sancties_split($input);
    } elseif (is_array($input)) {
        $arr = array_values(array_filter(array_map(fn($v) => trim((string)$v), $input), 'strlen'));
    } else {
        return null;
    }
    // Filter geldigheid + dedupe
    $arr = array_values(array_unique(array_filter($arr,
        fn($c) => in_array($c, SANCTIE_CODES, true))));
    if (!$arr) return null;
    // Sort op severity-volgorde (positie in SANCTIE_CODES)
    usort($arr, fn($a, $b) => array_search($a, SANCTIE_CODES) - array_search($b, SANCTIE_CODES));
    return implode(',', $arr);
}

// Sancties die in volgende rondes (zelfde afstand) "doorwerken" en op de
// startlijst getoond moeten worden zodat de jury weet dat een rijder op
// scherp staat. Specifiek:
//   - FS: bij weer FS in opvolgende heat = direct DQ
//   - W1, W2: bij W2 staat rijder op scherp; 1 extra waarschuwing = DQ-SF
// RR (race rule reminder) en alle DQ-/DNF/DNS hoeven niet getoond te worden
// op vervolg-startlijsten (RR heeft geen consequenties; DQ/DNF/DNS = rijder
// rijdt niet meer mee).
const SANCTIE_DOORWERKEND_NAAR_VOLGENDE_HEAT = ['FS', 'W1', 'W2'];

// Filter een 'vorige_sancties' GROUP_CONCAT-string ("S1:W1,W2,DQ-SF H1:FS")
// naar alleen de doorwerkende codes. Output blijft hetzelfde formaat;
// rondes zonder doorwerkende codes vallen volledig weg.
// Voorbeeld: "S1:W1,W2,DQ-SF H1:FS,RR" → "S1:W1,W2 H1:FS"
function vorigeSanctiesFilterDisplay(?string $raw): string {
    if ($raw === null || trim($raw) === '') return '';
    $delen = preg_split('/\s+/', trim($raw));
    $clean = [];
    foreach ($delen as $deel) {
        if (!str_contains($deel, ':')) continue;
        [$ronde, $codes] = explode(':', $deel, 2);
        $codesArr = sancties_split($codes);
        $relevant = array_values(array_intersect($codesArr, SANCTIE_DOORWERKEND_NAAR_VOLGENDE_HEAT));
        if ($relevant) {
            $clean[] = $ronde . ':' . implode(',', $relevant);
        }
    }
    return implode(' ', $clean);
}

// ── Eindsanctie = rijder heeft geen geldige finish ──────────────────────────
// DNS/DNF/DQ-* betekenen: géén geregistreerde finish, ongeacht of finishpositie
// in de DB (per ongeluk of door eerdere invoer) gevuld is.
// FS/RR/W1/W2 zijn sancties die de finish-status NIET wegnemen.
// Werkt nu op multi-sanctie string: één eind-code in de lijst = eindsanctie.
function isEindSanctie(?string $s): bool {
    return sancties_heeft_any($s, ['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF']);
}

// ── Splits rijders in finishers + overigen ──────────────────────────────────
// Gebruik deze i.p.v. rechtstreeks filteren op finishpositie, zodat rijders
// met een eindsanctie ALTIJD in overigen belanden — ook als finishpositie per
// ongeluk gevuld is (bv. na een DQ-TF → DNS omzetting waarbij de positie niet
// gereset werd).
function splitsFinishersOverigen(array $rows): array {
    $finishers = [];
    $overigen  = [];
    foreach ($rows as $r) {
        if ($r['finishpositie'] !== null && !isEindSanctie($r['sanctie'] ?? null)) {
            $finishers[] = $r;
        } else {
            $overigen[] = $r;
        }
    }
    return [array_values($finishers), array_values($overigen)];
}

// ── Sorteert een set heat-rijen ──────────────────────────────────────────────
// Detecteert automatisch puntenkoers (pk_punten) en lange afstand (rondes).
// Rijders met eindsanctie (DNS/DNF/DQ-*) behandelen we als "geen finish"
// ongeacht of finishpositie gevuld is, zodat ze altijd achteraan sorteren.
function sorteerRijdersOpTijd(array $rows): array {
    $isPK     = !empty(array_filter($rows, fn($r) => isset($r['pk_punten']) && $r['pk_punten'] !== null));
    // Lange-afstand-detectie: alleen activeren als ALLE finishers rondes
    // hebben én er minstens één rijder > 0 rondes heeft. Voorkomt dat
    // losse rondes-restwaarden (bv. achtergebleven na een gewiste DNS op een
    // sprint) de sort-volgorde gaan bepalen.
    $finishers = array_filter($rows, fn($r) =>
        $r['finishpositie'] !== null && !isEindSanctie($r['sanctie'] ?? null));
    $heeftRnd = false;
    if (!empty($finishers)) {
        $allesGevuld = true; $maxRnd = 0;
        foreach ($finishers as $r) {
            $rd = $r['rondes'] ?? null;
            if ($rd === null) { $allesGevuld = false; break; }
            if ((int)$rd > $maxRnd) $maxRnd = (int)$rd;
        }
        $heeftRnd = $allesGevuld && $maxRnd > 0;
    }

    usort($rows, function ($a, $b) use ($isPK, $heeftRnd) {
        // Eindsanctie overschrijft finishpositie → behandel als "geen finish"
        $hasA = $a['finishpositie'] !== null && !isEindSanctie($a['sanctie'] ?? null);
        $hasB = $b['finishpositie'] !== null && !isEindSanctie($b['sanctie'] ?? null);
        if (!$hasA && !$hasB) return 0;
        if (!$hasA) return 1;
        if (!$hasB) return -1;

        // Puntenkoers: punten DESC → rondes DESC → tijd ASC
        if ($isPK) {
            $pA = $a['pk_punten'] ?? -PHP_INT_MAX;
            $pB = $b['pk_punten'] ?? -PHP_INT_MAX;
            if ($pA != $pB) return $pB <=> $pA;
        }
        // Lange afstand: rondes DESC → tijd ASC
        if ($heeftRnd) {
            $rA = $a['rondes'] ?? PHP_INT_MAX;
            $rB = $b['rondes'] ?? PHP_INT_MAX;
            if ($rA !== $rB) return $rB <=> $rA; // DESC
        }

        $tA = $a['tijd_ms'];
        $tB = $b['tijd_ms'];
        if ($tA !== null && $tB !== null && $tA !== $tB) return $tA - $tB;
        if ($tA !== null && $tB === null) return -1;
        if ($tA === null && $tB !== null) return 1;
        return (int)$a['finishpositie'] - (int)$b['finishpositie'];
    });
    return $rows;
}

// ── Ex-aequo rang voor een gesorteerde reeks finishers ────────────────────────
// Geeft een array terug met dezelfde lengte als $finishers.
// Regel 1,2,3,3,5: als twee rijders dezelfde tijd hebben, krijgen ze dezelfde
// rang; de volgende rang slaat de "gebruikte" posities over.
function berekenExAequoRangs(array $finishers, int $offset): array {
    $n    = count($finishers);
    $rangs = [];
    for ($i = 0; $i < $n; $i++) {
        if ($i === 0) {
            $rangs[$i] = $offset + 1;
        } else {
            $prev    = $finishers[$i - 1];
            $curr    = $finishers[$i];
            $exAequo = ($curr['tijd_ms'] !== null && $prev['tijd_ms'] !== null)
                ? ($curr['tijd_ms'] === $prev['tijd_ms'])
                : ($curr['tijd_ms'] === null && $prev['tijd_ms'] === null
                   && (int)$curr['finishpositie'] === (int)$prev['finishpositie']);
            $rangs[$i] = $exAequo ? $rangs[$i - 1] : ($offset + $i + 1);
        }
    }
    return $rangs;
}

// ── Mogen we de uitslag voor (DC, distance) vastleggen? ─────────────────────
// Twee checks, beide tegen tijdschema_ritten als bron-van-waarheid voor
// "welke rondes wil de operator daadwerkelijk rijden":
//   1. Iedere bestaande rit moet een heat hebben (= geloot). Niet-gelote
//      ritten geven "Nog niet geloot: ...".
//   2. Iedere bestaande heat-entry moet een resultaat hebben (finishpositie
//      OF eindsanctie DNS/DNF/DQ-*). Open entries geven "Nog open heats: ...".
//
// Bewust NIET via cat_config (heeft_runner_up etc.): die wordt onder andere
// door bouwEnabledRondes/syncBlokken gebruikt om ronde-blokken te
// onderhouden. Wanneer een operator mid-wedstrijd een rit verwijdert via
// het prullenbakje, blijft cat_config op 1 staan zodat genereer + sync
// blijven werken. Door hier naar de feitelijke ritten te kijken, blokkeert
// vastleggen niet langer op een "verwachte" runner-up die de operator
// bewust geskipt heeft.
//
// Returns: ['compleet' => bool, 'reden' => string, 'incomplete' => string[]]
function alleRondesCompleet(PDO $pdo, string $compId, array $dcIds, ?string $distId = null, ?string $splitDcNaam = null): array {
    if (empty($dcIds)) return ['compleet' => true, 'reden' => '', 'incomplete' => []];
    $dcPh = implode(',', array_fill(0, count($dcIds), '?'));

    // Split-filter: bij split-DC alleen ronden van DEZE split (dc_naam) checken.
    // Anders kijkt de check naar ALLE splits samen en blijft hij "nog incompleet"
    // zolang een andere split nog niet klaar is.
    $splitCond   = ($splitDcNaam !== null && $splitDcNaam !== '') ? 'AND r.dc_naam = ?'   : '';
    $splitParam  = ($splitDcNaam !== null && $splitDcNaam !== '') ? [$splitDcNaam]        : [];
    $splitCondH  = ($splitDcNaam !== null && $splitDcNaam !== '') ? 'AND tsr.dc_naam = ?' : '';

    // Pas 1: ritten zonder heat = nog niet geloot.
    $rtLabel = ['heats'=>'Series','kwartfinale'=>'Kwartfinale','halve_finale'=>'Halve finale',
                'runner_up'=>'Runner-up','finale_b'=>'B-finale','finale_a'=>'A-finale'];
    $rDistCond = $distId ? 'AND (r.distance_id = ? OR r.distance_id IS NULL)' : '';
    $rParams   = array_merge([$compId], $dcIds);
    if ($distId) $rParams[] = $distId;
    $rParams   = array_merge($rParams, $splitParam);

    $ritSql = "
        SELECT DISTINCT r.ronde_type
        FROM tijdschema_ritten r
        JOIN competition_tijdschema ct ON ct.id = r.tijdschema_id
        LEFT JOIN heats h ON h.tijdschema_rit_id = r.id AND h.competition_id = ct.competition_id
        WHERE ct.competition_id = ?
          AND r.dc_id IN ($dcPh)
          $rDistCond
          $splitCond
          AND h.id IS NULL
    ";
    $rs = $pdo->prepare($ritSql);
    $rs->execute($rParams);
    $missend = [];
    foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $rt) {
        $missend[$rtLabel[$rt] ?? $rt] = true;
    }
    if (!empty($missend)) {
        return [
            'compleet'   => false,
            'reden'      => 'Nog niet geloot: ' . implode(', ', array_keys($missend)),
            'incomplete' => array_keys($missend),
        ];
    }

    // Pas 2: open heat-entries (geen finish + geen eindsanctie).
    // Eind-sancties tellen als afgerond — ook in een GECOMBINEERDE sanctie
    // (bv. 'DQ-TF,FS'): FIND_IN_SET op de komma-lijst i.p.v. exacte match
    // (spaties gestript voor de zekerheid). Vaste codes → geen injectie.
    $rankedCodes    = ['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF'];
    $eindSanctieSql = implode(' OR ', array_map(
        fn($c) => "FIND_IN_SET('$c', REPLACE(res.sanctie, ' ', ''))", $rankedCodes
    ));
    $hDistCond = $distId ? 'AND COALESCE(h.distance_id, tsr.distance_id) = ?' : '';
    $hParams   = array_merge([$compId], $dcIds);
    if ($distId) $hParams[] = $distId;
    $hParams   = array_merge($hParams, $splitParam);

    $sql = "
        SELECT h.heat_naam,
               SUM(CASE WHEN res.id IS NULL
                          OR (res.finishpositie IS NULL
                              AND (res.sanctie IS NULL OR NOT ($eindSanctieSql)))
                        THEN 1 ELSE 0 END) AS open
        FROM heats h
        JOIN heat_entries he ON he.heat_id = h.id
        LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
        LEFT JOIN results res ON res.heat_entry_id = he.id
        WHERE h.competition_id = ?
          AND h.distance_combination_id IN ($dcPh)
          $hDistCond
          $splitCondH
        GROUP BY h.id
        HAVING open > 0
    ";
    $st = $pdo->prepare($sql);
    $st->execute($hParams);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $namen = array_values(array_unique(array_map(fn($r) => $r['heat_naam'] ?: '?', $rows)));
        return [
            'compleet'   => false,
            'reden'      => 'Nog open heats: ' . implode(', ', $namen),
            'incomplete' => $namen,
        ];
    }

    return ['compleet' => true, 'reden' => '', 'incomplete' => []];
}

// ── Compleetheid van een heat ─────────────────────────────────────────────────
function isHeatCompleet(array $rows): bool {
    if (empty($rows)) return false;
    foreach ($rows as $r) {
        $s = $r['sanctie'] ?? null;
        if ($r['finishpositie'] === null && !isEindSanctie($s)) {
            return false;
        }
    }
    return true;
}

// ── Detecteer gecombineerde modus ─────────────────────────────────────────────
// Retourneert true als er wél series zijn en uitsluitend een A-finale
// (geen B-finales) → gecombineerde punten (serie + finale).
function isCombineerdModus(array $heats): bool {
    $hebbeSerie   = false;
    $hebbeFinaleA = false;
    $hebbeFinaleB = false;
    foreach ($heats as $h) {
        $rt = $h['ronde_type'];
        if ($rt === 'heats')    $hebbeSerie   = true;
        if ($rt === 'finale_a') $hebbeFinaleA = true;
        if ($rt === 'finale_b') $hebbeFinaleB = true;
    }
    return $hebbeSerie && $hebbeFinaleA && !$hebbeFinaleB;
}

// ── Gecombineerd resultaat berekenen ─────────────────────────────────────────
// $serieRangs   : [ person_license => rang ]
// $finaleRangs  : [ person_license => rang ]
// $rijderInfo   : [ person_license => [full_name, short_name, start_number, categorie] ]
// $serieTijden  : [ person_license => tijd_ms|null ]
// $finaleTijden : [ person_license => tijd_ms|null ]
// $sancties     : [ person_license => sanctie|null ]  (finale-sanctie)
//
// Retourneert array gesorteerd op totaal_punten ASC, tiebreaker finale_rang ASC.
//
// $serieAlleenStartvolgorde: true = full-final variant waarin de serie alleen
//   de startvolgorde in de A-finale bepaalt; het eindresultaat komt dan
//   volledig uit de finale (serie-punten tellen niet mee voor totaal en
//   ook niet als tiebreaker).
function berekenCombineerdResultaat(
    array $serieRangs,
    array $finaleRangs,
    array $rijderInfo,
    array $serieTijden,
    array $finaleTijden,
    array $sancties,
    bool  $serieAlleenStartvolgorde = false
): array {
    // Alle bekende rijders (unie van serie- en finale-deelnemers)
    $licenties = array_unique(array_merge(
        array_keys($serieRangs),
        array_keys($finaleRangs)
    ));

    $rijen = [];
    foreach ($licenties as $lic) {
        $serieRang   = $serieRangs[$lic]   ?? null;
        $finaleRang  = $finaleRangs[$lic]  ?? null;
        // Alleen rijders die in BEIDE rondes een positie hebben tellen mee
        // (of we nemen ze mee maar geven sanctie default-punten)
        $seriePunten  = $serieRang  !== null ? (float)$serieRang  : null;
        $finalePunten = $finaleRang !== null ? (float)$finaleRang : null;
        // Als de serie alleen als startvolgorde telt: totaal = enkel finale-punten.
        // Rijders zonder finale-rang krijgen PHP_INT_MAX zodat ze onderaan landen.
        // Bij niet-SAS: als een rijder ontbreekt in serie OF finale → PHP_INT_MAX
        // (i.p.v. ?? 0 → anders krijgt sparse-data rijder kunstmatig laag totaal
        // en sorteert onterecht naar bovenaan met rang=1).
        $totaal = $serieAlleenStartvolgorde
            ? ($finalePunten ?? PHP_INT_MAX)
            : (
                $seriePunten === null || $finalePunten === null
                    ? PHP_INT_MAX
                    : $seriePunten + $finalePunten
              );

        $info = $rijderInfo[$lic] ?? [];
        $rijen[] = [
            'person_license' => $lic,
            'full_name'      => $info['full_name']    ?? '',
            'short_name'     => $info['short_name']   ?? '',
            'start_number'   => $info['start_number'] ?? null,
            'categorie'      => $info['categorie']    ?? '',
            'serie_rang'     => $serieRang,
            // Bij serie-alleen-startvolgorde telt de serie niet mee voor het totaal,
            // dus ook niet als aparte "punten" in de uitslag.
            'serie_punten'   => $serieAlleenStartvolgorde ? null : $seriePunten,
            'serie_tijd_ms'  => $serieTijden[$lic]  ?? null,
            'finale_rang'    => $finaleRang,
            'finale_punten'  => $finalePunten,
            'finale_tijd_ms' => $finaleTijden[$lic] ?? null,
            'totaal_punten'  => $totaal,
            'sanctie'        => $sancties[$lic] ?? null,
        ];
    }

    // Sorteren: totaal_punten ASC, tiebreaker finale_rang ASC
    usort($rijen, function ($a, $b) {
        $diff = $a['totaal_punten'] <=> $b['totaal_punten'];
        if ($diff !== 0) return $diff;
        $fA = $a['finale_rang'] ?? PHP_INT_MAX;
        $fB = $b['finale_rang'] ?? PHP_INT_MAX;
        return $fA <=> $fB;
    });

    // Rang toekennen (ex-aequo op totaal + finale_rang)
    $n = count($rijen);
    for ($i = 0; $i < $n; $i++) {
        if ($i === 0) {
            $rijen[$i]['rang'] = 1;
        } else {
            $prev = $rijen[$i - 1];
            $curr = $rijen[$i];
            $exAequo = $curr['totaal_punten'] == $prev['totaal_punten']
                    && ($curr['finale_rang'] ?? PHP_INT_MAX) === ($prev['finale_rang'] ?? PHP_INT_MAX);
            $rijen[$i]['rang'] = $exAequo ? $prev['rang'] : ($i + 1);
        }
    }

    return $rijen;
}

// ── Internationaal resultaat: cascading elimination ranking ──────────────────
// Bouwt een complete afstandsuitslag (plek 1 t/m laatste) op basis van
// ronde-voor-ronde eliminatie.
//
// $rondeData: array van rondes, geordend van LAATSTE (finale) naar EERSTE (series):
//   [
//     [ 'ronde_type' => 'finale_a', 'ranking' => 'time', 'rows' => [...] ],
//     [ 'ronde_type' => 'halve_finale', 'ranking' => 'time', 'rows' => [...] ],
//     ...
//   ]
// Elke 'rows' bevat: person_license, full_name, short_name, start_number,
//   categorie, finishpositie, tijd_ms, sanctie
//
// Retourneert: array gesorteerd van plek 1..N, met NOT_RANKED onderaan.
function berekenInternationaalResultaat(array $rondeData, string $raceSubType = 'sprint'): array {
    // $raceSubType: 'sprint' (default), 'inline', 'puntenkoers', of 'afvalkoers'.
    // Voor alle drie de lange-afstanden geldt: niet-doorgestroomde rijders uit
    // een serie/kwart/halve worden ex-aequo geklasseerd op heat-positie. Alleen
    // de finale wordt op tijd/rondes/punten gerankt.
    $isLangeAfstand = in_array($raceSubType, ['inline', 'puntenkoers', 'afvalkoers'], true);
    $NOT_RANKED  = ['DQ-SF', 'DQ-DF'];
    $RANKED_LAST = ['DNF', 'DQ-TF', 'DNS'];

    // Bepaal per rijder de EERSTE ronde (chronologisch) waarin ze voorkomen.
    // $rondeData is geordend finale→series, chronologisch is het omgekeerd.
    // DNS in de eerste ronde = 0 punten (art. 144.4: "DNS except the first round")
    // finale_b (kleine finale internationaal-nieuw) staat qua chronologie
    // gelijk aan runner_up: gereden ná de finale_a-doorstroom-scheiding.
    $rondeNiveau = ['heats' => 1, 'kwartfinale' => 2, 'halve_finale' => 3,
                    'runner_up' => 4, 'finale_b' => 4, 'finale_a' => 5];
    $eersteRonde = []; // person_license => laagste ronde_type niveau
    foreach ($rondeData as $ronde) {
        $niveau = $rondeNiveau[$ronde['ronde_type']] ?? 0;
        foreach ($ronde['rows'] as $r) {
            $lic = $r['person_license'];
            if (!isset($eersteRonde[$lic]) || $niveau < $eersteRonde[$lic]) {
                $eersteRonde[$lic] = $niveau;
            }
        }
    }

    // Track welke rijders al geplaatst zijn (in een latere/hogere ronde)
    $geplaatst = [];      // person_license => true
    $resultaat = [];      // gerankte rijders
    $nietGerankt = [];    // DQ-SF/DQ-DF rijders
    $rangOffset = 0;

    // Runner-up: elke heat strijdt om z'n eigen plek-blok. Heat 1 = beste
    // plekken (net na de gekwalificeerden), heat N = slechtste plekken.
    // Een rijder uit heat 2 kan NOOIT hoger eindigen dan rijders uit heat 1,
    // ook niet bij snellere tijd. We splitsen daarom de runner_up ronde-
    // entry op in één sub-ronde per heat — de bestaande per-ronde loop
    // hieronder geeft dan automatisch oplopende rangs per heat-blok.
    $expandedRondeData = [];
    foreach ($rondeData as $ronde) {
        if (($ronde['ronde_type'] ?? '') !== 'runner_up') {
            $expandedRondeData[] = $ronde;
            continue;
        }
        $byHeat = [];
        foreach ($ronde['rows'] as $r) {
            $hn = isset($r['heat_nr']) ? (int)$r['heat_nr'] : 1;
            $byHeat[$hn][] = $r;
        }
        ksort($byHeat); // heat_nr ASC = beste plekken eerst
        foreach ($byHeat as $rows) {
            $expandedRondeData[] = [
                'ronde_type' => 'runner_up',
                'label'      => $ronde['label'] ?? 'Runner-up',
                'ranking'    => $ronde['ranking'] ?? 'time',
                'rows'       => $rows,
            ];
        }
    }
    $rondeData = $expandedRondeData;

    foreach ($rondeData as $ronde) {
        $rankingMethod = $ronde['ranking'] ?? 'time';
        $rondeType     = $ronde['ronde_type'] ?? '';
        $rondeLabel    = $ronde['label'] ?? $rondeType;

        // Verzamel alle rijders in deze ronde die NIET al in een latere ronde zaten
        // Skip rijders zonder resultaat (wel ingedeeld maar nog niet gereden)
        $uitgevallen = [];
        foreach ($ronde['rows'] as $r) {
            $lic = $r['person_license'];
            if (isset($geplaatst[$lic])) continue; // al gerankt in latere ronde

            // Geen resultaat in deze ronde? Skip — laat eerdere ronde deze rijder pakken
            $heeftResultaat = $r['finishpositie'] !== null || !empty($r['sanctie']);
            if (!$heeftResultaat) continue;

            $geplaatst[$lic] = true;

            $sanctie = $r['sanctie'] ?? null;

            // DNS in eerste ronde = 0 punten (art. 144.4)
            $rondeNiv = $rondeNiveau[$rondeType] ?? 0;
            $isEersteRonde = ($eersteRonde[$lic] ?? 0) === $rondeNiv;
            // Bruto-audit-velden: optioneel meegestuurd door upstream-query.
            // Niet alle callers (klassement_live, uitslag_vastleggen) selecteren
            // ze nog; isset-guard houdt die paden achterwaarts-compatibel.
            $brutoTijdMs = isset($r['bruto_tijd_ms']) && $r['bruto_tijd_ms'] !== null
                ? (int)$r['bruto_tijd_ms'] : null;
            $isPhotofinish = !empty($r['is_photofinish']) ? 1 : 0;

            if ($sanctie === 'DNS' && $isEersteRonde) {
                $nietGerankt[] = [
                    'person_license' => $lic,
                    'full_name'      => $r['full_name'],
                    'short_name'     => $r['short_name'] ?? '',
                    'start_number'   => $r['start_number'],
                    'categorie'      => $r['categorie'] ?? '',
                    'finishpositie'  => null,
                    'tijd_ms'        => null,
                    'bruto_tijd_ms'  => $brutoTijdMs,
                    'is_photofinish' => $isPhotofinish,
                    'sanctie'        => 'DNS',
                    'ronde_label'    => $rondeLabel,
                    'rang'           => null,
                    'rondes'         => null,
                    'pk_punten'      => null,
                ];
                continue;
            }

            // NOT_RANKED: apart, onderaan (DQ-SF, DQ-DF)
            if ($sanctie && in_array($sanctie, $NOT_RANKED, true)) {
                $nietGerankt[] = [
                    'person_license' => $lic,
                    'full_name'      => $r['full_name'],
                    'short_name'     => $r['short_name'] ?? '',
                    'start_number'   => $r['start_number'],
                    'categorie'      => $r['categorie'] ?? '',
                    'finishpositie'  => null,
                    'tijd_ms'        => null,
                    'bruto_tijd_ms'  => $brutoTijdMs,
                    'is_photofinish' => $isPhotofinish,
                    'sanctie'        => $sanctie,
                    'ronde_label'    => $rondeLabel,
                    'rang'           => null,
                    'rondes'         => isset($r['rondes']) && $r['rondes'] !== null ? (int)$r['rondes'] : null,
                    'pk_punten'      => isset($r['pk_punten']) && $r['pk_punten'] !== null ? (float)$r['pk_punten'] : null,
                ];
                continue;
            }

            // RANKED_LAST: in de uitslag maar onderaan hun ronde-groep
            $isRankedLast = $sanctie && in_array($sanctie, $RANKED_LAST, true);
            $uitgevallen[] = [
                'person_license' => $lic,
                'full_name'      => $r['full_name'],
                'short_name'     => $r['short_name'] ?? '',
                'start_number'   => $r['start_number'],
                'categorie'      => $r['categorie'] ?? '',
                'finishpositie'  => $r['finishpositie'] !== null ? (int)$r['finishpositie'] : null,
                'tijd_ms'        => $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                'bruto_tijd_ms'  => $brutoTijdMs,
                'is_photofinish' => $isPhotofinish,
                'sanctie'        => $sanctie,
                'ronde_label'    => $rondeLabel,
                '_ranked_last'   => $isRankedLast,
                'rondes'         => isset($r['rondes']) && $r['rondes'] !== null ? (int)$r['rondes'] : null,
                'pk_punten'      => isset($r['pk_punten']) && $r['pk_punten'] !== null ? (float)$r['pk_punten'] : null,
                'afval_rang'     => isset($r['afval_rang']) && $r['afval_rang'] !== null ? (int)$r['afval_rang'] : null,
            ];
        }

        // Afvalkoers-detectie (legacy heuristiek + expliciete race_subtype)
        $isAfvalkoers = $raceSubType === 'afvalkoers'
                     || !empty(array_filter($uitgevallen, fn($r) => $r['afval_rang'] !== null));

        // Niet-doorgestroomde rijders bij lange afstanden: positie ex-aequo,
        // ongeacht race_subtype (inline/puntenkoers/afvalkoers). De finale
        // (finale_a / runner_up) blijft op tijd/rondes/punten gerankt.
        $isFinaleRonde = in_array($rondeType, ['finale_a', 'runner_up'], true);
        $useFiPosExAequo = ($isLangeAfstand && !$isFinaleRonde) || $isAfvalkoers;

        // Sorteer uitgevallen rijders per ranking method
        // Eerst finishers, dan ranked_last
        // Detecteer puntenkoers: als minstens 1 rijder pk_punten heeft
        $isPK = !empty(array_filter($uitgevallen, fn($r) => ($r['pk_punten'] ?? null) !== null));

        // Lange-afstand-detectie: alleen activeren als ALLE échte finishers
        // (niet-ranked_last) rondes hebben én er minstens één > 0 is.
        // Voorkomt dat per ongeluk achtergebleven rondes-waardes (bv. na een
        // teruggedraaide DNS op een sprint) de volgorde gaan bepalen.
        $heeftRnd = false;
        $echteFin = array_filter($uitgevallen, fn($r) => empty($r['_ranked_last']));
        if (!empty($echteFin)) {
            $allesGevuld = true; $maxRnd = 0;
            foreach ($echteFin as $r) {
                $rd = $r['rondes'] ?? null;
                if ($rd === null) { $allesGevuld = false; break; }
                if ((int)$rd > $maxRnd) $maxRnd = (int)$rd;
            }
            $heeftRnd = $allesGevuld && $maxRnd > 0;
        }

        usort($uitgevallen, function ($a, $b) use ($rankingMethod, $isPK, $heeftRnd, $useFiPosExAequo) {
            // ranked_last altijd onderaan
            if ($a['_ranked_last'] && !$b['_ranked_last']) return 1;
            if (!$a['_ranked_last'] && $b['_ranked_last']) return -1;
            if ($a['_ranked_last'] && $b['_ranked_last']) return 0; // ex-aequo

            // Lange afstand of afvalkoers, niet-finale ronde: finishpositie
            // is leidend en alle gelijke posities zijn ex-aequo (geen tijd-
            // tiebreak, want heats zijn niet onderling vergelijkbaar).
            if ($useFiPosExAequo) {
                $pA = $a['finishpositie'] ?? PHP_INT_MAX;
                $pB = $b['finishpositie'] ?? PHP_INT_MAX;
                return $pA <=> $pB;
            }

            // Puntenkoers: punten DESC → rondes DESC → tijd ASC
            if ($isPK) {
                $pA = $a['pk_punten'] ?? -PHP_INT_MAX;
                $pB = $b['pk_punten'] ?? -PHP_INT_MAX;
                if ($pA != $pB) return $pB <=> $pA; // DESC
                $rA = $a['rondes'] ?? -1;
                $rB = $b['rondes'] ?? -1;
                if ($rA !== $rB) return $rB <=> $rA; // DESC
                $tA = $a['tijd_ms'] ?? PHP_INT_MAX;
                $tB = $b['tijd_ms'] ?? PHP_INT_MAX;
                return $tA <=> $tB; // ASC
            }

            // Lange afstand: rondes DESC → tijd ASC (alleen als $heeftRnd)
            if ($heeftRnd) {
                $rA = $a['rondes'] ?? PHP_INT_MAX;
                $rB = $b['rondes'] ?? PHP_INT_MAX;
                if ($rA !== $rB) return $rB <=> $rA; // DESC (meer rondes = beter)
            }

            if ($rankingMethod === 'position_time') {
                // Eerst op finishpositie, dan op tijd
                $pA = $a['finishpositie'] ?? PHP_INT_MAX;
                $pB = $b['finishpositie'] ?? PHP_INT_MAX;
                if ($pA !== $pB) return $pA <=> $pB;
            }
            // time (default): op tijd
            $tA = $a['tijd_ms'] ?? PHP_INT_MAX;
            $tB = $b['tijd_ms'] ?? PHP_INT_MAX;
            return $tA <=> $tB;
        });

        // Ken rang toe
        $nUitgevallen = count($uitgevallen);
        // Tel "echte" finishers (alles behalve ranked_last) zodat DNS/DNF/DQ-TF
        // de gedeelde rang krijgen die direct ná de laatste finisher komt
        // (standard competition ranking, niet "modified"). Bv. 12 finishers
        // + 2 DNS → DNS gedeeld 13e, niet gedeeld 14e.
        $nRanked = 0;
        foreach ($uitgevallen as $u) {
            if (!($u['sanctie'] && in_array($u['sanctie'], $RANKED_LAST, true))) {
                $nRanked++;
            }
        }
        for ($i = 0; $i < $nUitgevallen; $i++) {
            $r = &$uitgevallen[$i];
            unset($r['_ranked_last']); // interne vlag verwijderen

            if ($r['sanctie'] && in_array($r['sanctie'], $RANKED_LAST, true)) {
                // Ranked last: gedeeld op rang direct ná de laatste finisher.
                $r['rang'] = $rangOffset + $nRanked + 1;
            } elseif ($i === 0) {
                $r['rang'] = $rangOffset + 1;
            } else {
                $prev = $uitgevallen[$i - 1];
                if ($useFiPosExAequo) {
                    // Ex-aequo bij positie-gebaseerde rangschikking: gelijke
                    // finishpositie deelt rang. Geldt voor afvalkoers (alle
                    // rondes) en voor lange-afstand series/kwart/halve.
                    $exAequo = $r['finishpositie'] !== null
                            && $r['finishpositie'] === $prev['finishpositie'];
                } elseif ($isPK) {
                    $exAequo = ($r['pk_punten'] ?? null) === ($prev['pk_punten'] ?? null)
                            && ($r['rondes'] ?? null) === ($prev['rondes'] ?? null)
                            && $r['tijd_ms'] === $prev['tijd_ms'];
                } elseif ($rankingMethod === 'position_time') {
                    $exAequo = $r['finishpositie'] === $prev['finishpositie']
                            && $r['tijd_ms'] === $prev['tijd_ms'];
                } else {
                    // Lange afstand: rondes + tijd — alleen als $heeftRnd
                    if ($heeftRnd) {
                        $exAequo = ($r['rondes'] ?? null) === ($prev['rondes'] ?? null)
                                && $r['tijd_ms'] !== null && $prev['tijd_ms'] !== null
                                && $r['tijd_ms'] === $prev['tijd_ms'];
                    } else {
                        $exAequo = $r['tijd_ms'] !== null && $prev['tijd_ms'] !== null
                                && $r['tijd_ms'] === $prev['tijd_ms'];
                    }
                }
                $r['rang'] = $exAequo ? $prev['rang'] : ($rangOffset + $i + 1);
            }
            unset($r);
        }

        $resultaat = array_merge($resultaat, $uitgevallen);
        $rangOffset += $nUitgevallen;
    }

    // NOT_RANKED onderaan (geen rang)
    return array_merge($resultaat, $nietGerankt);
}
