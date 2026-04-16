<?php
// ============================================================
//  InlineComp – Gedeelde uitslag-hulpfuncties
//  Wordt geïnclude door uitslag_afstand.php, klassement_live.php
//  en uitslag_vastleggen.php.
// ============================================================

// ── Sorteert een set heat-rijen ──────────────────────────────────────────────
// Detecteert automatisch puntenkoers (pk_punten) en lange afstand (rondes).
function sorteerRijdersOpTijd(array $rows): array {
    $isPK     = !empty(array_filter($rows, fn($r) => isset($r['pk_punten']) && $r['pk_punten'] !== null));
    $heeftRnd = !empty(array_filter($rows, fn($r) => isset($r['rondes']) && $r['rondes'] !== null));

    usort($rows, function ($a, $b) use ($isPK, $heeftRnd) {
        $hasA = $a['finishpositie'] !== null;
        $hasB = $b['finishpositie'] !== null;
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

// ── Compleetheid van een heat ─────────────────────────────────────────────────
function isHeatCompleet(array $rows): bool {
    if (empty($rows)) return false;
    foreach ($rows as $r) {
        $s = $r['sanctie'] ?? null;
        if ($r['finishpositie'] === null &&
            !in_array($s, ['DNS', 'DNF', 'DQ-SF', 'DQ-TF', 'DQ-DF'], true)) {
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
function berekenInternationaalResultaat(array $rondeData): array {
    $NOT_RANKED  = ['DQ-SF', 'DQ-DF'];
    $RANKED_LAST = ['DNF', 'DQ-TF', 'DNS'];

    // Bepaal per rijder de EERSTE ronde (chronologisch) waarin ze voorkomen.
    // $rondeData is geordend finale→series, chronologisch is het omgekeerd.
    // DNS in de eerste ronde = 0 punten (art. 144.4: "DNS except the first round")
    $rondeNiveau = ['heats' => 1, 'kwartfinale' => 2, 'halve_finale' => 3,
                    'runner_up' => 4, 'finale_a' => 5];
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
            if ($sanctie === 'DNS' && $isEersteRonde) {
                $nietGerankt[] = [
                    'person_license' => $lic,
                    'full_name'      => $r['full_name'],
                    'short_name'     => $r['short_name'] ?? '',
                    'start_number'   => $r['start_number'],
                    'categorie'      => $r['categorie'] ?? '',
                    'finishpositie'  => null,
                    'tijd_ms'        => null,
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
                'sanctie'        => $sanctie,
                'ronde_label'    => $rondeLabel,
                '_ranked_last'   => $isRankedLast,
                'rondes'         => isset($r['rondes']) && $r['rondes'] !== null ? (int)$r['rondes'] : null,
                'pk_punten'      => isset($r['pk_punten']) && $r['pk_punten'] !== null ? (float)$r['pk_punten'] : null,
            ];
        }

        // Sorteer uitgevallen rijders per ranking method
        // Eerst finishers, dan ranked_last
        // Detecteer puntenkoers: als minstens 1 rijder pk_punten heeft
        $isPK = !empty(array_filter($uitgevallen, fn($r) => ($r['pk_punten'] ?? null) !== null));

        usort($uitgevallen, function ($a, $b) use ($rankingMethod, $isPK) {
            // ranked_last altijd onderaan
            if ($a['_ranked_last'] && !$b['_ranked_last']) return 1;
            if (!$a['_ranked_last'] && $b['_ranked_last']) return -1;
            if ($a['_ranked_last'] && $b['_ranked_last']) return 0; // ex-aequo

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

            // Lange afstand: rondes DESC → tijd ASC
            if (($a['rondes'] ?? null) !== null || ($b['rondes'] ?? null) !== null) {
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
        for ($i = 0; $i < $nUitgevallen; $i++) {
            $r = &$uitgevallen[$i];
            unset($r['_ranked_last']); // interne vlag verwijderen

            if ($r['sanctie'] && in_array($r['sanctie'], $RANKED_LAST, true)) {
                // Ranked last: gedeeld laatste in deze groep
                $r['rang'] = $rangOffset + $nUitgevallen;
            } elseif ($i === 0) {
                $r['rang'] = $rangOffset + 1;
            } else {
                $prev = $uitgevallen[$i - 1];
                if ($isPK) {
                    $exAequo = ($r['pk_punten'] ?? null) === ($prev['pk_punten'] ?? null)
                            && ($r['rondes'] ?? null) === ($prev['rondes'] ?? null)
                            && $r['tijd_ms'] === $prev['tijd_ms'];
                } elseif ($rankingMethod === 'position_time') {
                    $exAequo = $r['finishpositie'] === $prev['finishpositie']
                            && $r['tijd_ms'] === $prev['tijd_ms'];
                } else {
                    // Lange afstand: rondes + tijd
                    if (($r['rondes'] ?? null) !== null || ($prev['rondes'] ?? null) !== null) {
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
