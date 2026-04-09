<?php
// ============================================================
//  InlineComp – Gedeelde uitslag-hulpfuncties
//  Wordt geïnclude door uitslag_afstand.php, klassement_live.php
//  en uitslag_vastleggen.php.
// ============================================================

// ── Sorteert een set heat-rijen op tijd (ex-aequo-klaar) ─────────────────────
function sorteerRijdersOpTijd(array $rows): array {
    usort($rows, function ($a, $b) {
        $hasA = $a['finishpositie'] !== null;
        $hasB = $b['finishpositie'] !== null;
        if (!$hasA && !$hasB) return 0;
        if (!$hasA) return 1;
        if (!$hasB) return -1;
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

// ── Compleetheid van een heat ─────────────────────────────────────────────────
function isHeatCompleet(array $rows): bool {
    if (empty($rows)) return false;
    foreach ($rows as $r) {
        $s = $r['sanctie'] ?? null;
        if ($r['finishpositie'] === null &&
            !in_array($s, ['DNS', 'DNF', 'DSQ-SF', 'DSQ-TF', 'DC'], true)) {
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
function berekenCombineerdResultaat(
    array $serieRangs,
    array $finaleRangs,
    array $rijderInfo,
    array $serieTijden,
    array $finaleTijden,
    array $sancties
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
        $totaal = ($seriePunten ?? 0) + ($finalePunten ?? 0);

        $info = $rijderInfo[$lic] ?? [];
        $rijen[] = [
            'person_license' => $lic,
            'full_name'      => $info['full_name']    ?? '',
            'short_name'     => $info['short_name']   ?? '',
            'start_number'   => $info['start_number'] ?? null,
            'categorie'      => $info['categorie']    ?? '',
            'serie_rang'     => $serieRang,
            'serie_punten'   => $seriePunten,
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
