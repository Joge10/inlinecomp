<?php
// ============================================================
//  InlineComp – rijder-profiel data-laag (gedeeld)
//
//  rijderProfielData(PDO $pdo, string $lic): array
//    → { persoon, stats, sprint:{afstand:{color,p:[...]}}, lang:{...} }
//    De vorm matcht de mockup-DATA (docs_internal/mockup-rijderprofiel.html),
//    zodat de grafiek-/PR-JS die structuur direct kan tekenen.
//
//  Bron: eigen DB, parametrisch op licentienummer. Twee queries:
//    1. klassering per wedstrijd+afstand — categorie-eigen (window) + NL-only.
//    2. snelste tijd + ronde uit de RITTEN (results), niet de finaletijd.
//  Zie docs_internal/plan-rijderprofiel.md voor de volledige toelichting.
// ============================================================

if (!function_exists('rijderProfielData')) {

// Afstand-normalisatie → [groep, canonieke naam] of null (overslaan).
// Volgorde: eerst afval, dan punten/points, dan One Lap, dan meters.
function _rpAfstand(string $naam, $meters): ?array {
    $l = mb_strtolower(trim($naam));
    $m = ($meters !== null && $meters !== '') ? (int)$meters : null;
    if (strpos($l, 'afval') !== false)                       return ['lang', 'Afvalkoers'];
    if (strpos($l, 'punt') !== false || strpos($l, 'points') !== false)
                                                             return ['lang', 'Puntenkoers'];
    if (strpos($l, 'one lap') !== false || strpos($l, 'onelap') !== false
        || strpos($l, 'one-lap') !== false)                  return ['sprint', 'One Lap'];
    if ($m !== null && $m > 0)                               return ['sprint', $m . 'm'];
    return null;   // onbekend + geen meters → overslaan (relay is al gefilterd)
}

// Ronde-label uit heat_naam ("Serie 3" → "Serie") of ronde-nummer.
function _rpRonde(?string $heatNaam, $ronde): string {
    $hn = trim((string)$heatNaam);
    if ($hn !== '') {
        $stripped = preg_replace('/\s*\d+\s*$/u', '', $hn);
        return $stripped !== '' ? $stripped : $hn;
    }
    return $ronde !== null ? 'Ronde ' . (int)$ronde : '';
}

function rijderProfielData(PDO $pdo, string $lic): array {
    // ── 0. Persoon (kop) ────────────────────────────────────────────────
    $pStmt = $pdo->prepare("
        SELECT license_key, full_name, short_name, category, birth_year,
               nationality, club_full, club_short, start_number, gender
        FROM persons WHERE license_key = ? LIMIT 1
    ");
    $pStmt->execute([$lic]);
    $persoon = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // ── 1. Klassering per wedstrijd+afstand (categorie-eigen + NL-only) ──
    $kStmt = $pdo->prepare("
        WITH ranked AS (
          SELECT
              ua.competition_id, ua.competition_datum, ua.competition_naam,
              ua.distance_naam, ua.distance_meters, ua.categorie,
              ua.person_license, ua.rang, ua.tijd_ms, ua.sanctie,
              (p.nationality = 'NED') AS is_ned,
              ROW_NUMBER() OVER (
                  PARTITION BY ua.competition_id, ua.distance_naam,
                               COALESCE(ua.distance_meters, 0), ua.categorie
                  ORDER BY ua.rang, ua.person_license) AS klassering_cat,
              ROW_NUMBER() OVER (
                  PARTITION BY ua.competition_id, ua.distance_naam,
                               COALESCE(ua.distance_meters, 0), ua.categorie,
                               (p.nationality = 'NED')
                  ORDER BY ua.rang, ua.person_license) AS klassering_nl
          FROM uitslag_afstand ua
          JOIN persons p ON p.license_key = ua.person_license
          WHERE ua.rang IS NOT NULL
            AND ua.distance_naam NOT REGEXP 'stafette|flossing|elay'
        )
        SELECT competition_id, competition_datum AS datum, categorie,
               competition_naam AS wedstrijd, distance_naam AS afstand,
               distance_meters AS meters, klassering_cat AS klassering,
               CASE WHEN is_ned THEN klassering_nl END AS klassering_nl,
               tijd_ms AS beste_tijd_ms
        FROM ranked
        WHERE person_license = ?
        ORDER BY datum
    ");
    $kStmt->execute([$lic]);
    $klasRijen = $kStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 2. Snelste tijd + ronde uit de ritten ──────────────────────────
    $tStmt = $pdo->prepare("
        WITH ritten AS (
          SELECT
              h.competition_id,
              COALESCE(d.name, h.heat_naam) AS afstand, d.value_meters AS meters,
              h.ronde, h.heat_naam, r.tijd_ms,
              ROW_NUMBER() OVER (
                  PARTITION BY h.competition_id, COALESCE(d.name, h.heat_naam),
                               COALESCE(d.value_meters, 0)
                  ORDER BY r.tijd_ms ASC) AS snelste
          FROM results r
          JOIN heat_entries he ON he.id = r.heat_entry_id
          JOIN heats        h  ON h.id  = he.heat_id
          LEFT JOIN distances d ON d.id = h.distance_id
                                AND d.distance_combination_id = h.distance_combination_id
          WHERE he.person_license = ?
            AND r.tijd_ms IS NOT NULL AND r.tijd_ms > 0
            AND (r.sanctie IS NULL OR r.sanctie NOT IN ('DNS','DNF','DQ-TF','DQ-SF','DQ-DF'))
        )
        SELECT competition_id, afstand, meters, ronde, heat_naam, tijd_ms
        FROM ritten WHERE snelste = 1
    ");
    $tStmt->execute([$lic]);
    // Map (comp|canon) → snelste tijd + rondelabel.
    $tmap = [];
    foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $norm = _rpAfstand((string)$r['afstand'], $r['meters']);
        if (!$norm) continue;
        $key = $r['competition_id'] . '|' . $norm[1];
        // Meerdere ritten kunnen op dezelfde canon mappen → snelste houden.
        if (!isset($tmap[$key]) || (int)$r['tijd_ms'] < $tmap[$key]['t']) {
            $tmap[$key] = ['t' => (int)$r['tijd_ms'], 'tr' => _rpRonde($r['heat_naam'], $r['ronde'])];
        }
    }

    // ── Kleuren (Okabe-Ito, kleurenblind-veilig) per canon, stabiel op
    //    eerste-verschijning binnen de groep. CSS-vars staan in profiel.php.
    $palet = ['var(--c-blue)','var(--c-orange)','var(--c-green)','var(--c-verm)',
              'var(--c-pink)','var(--c-sky)','var(--c-yellow)','var(--c-grey)'];

    $groepen = ['sprint' => [], 'lang' => []];   // groep → canon → {color,p,_keys}
    $wedstrijden = [];        // set van competition_id
    $besteKlas = null;
    $seizoenen = [];
    $nUitslagen = 0;

    foreach ($klasRijen as $row) {
        $norm = _rpAfstand((string)$row['afstand'], $row['meters']);
        if (!$norm) continue;
        [$grp, $canon] = $norm;
        $datum = substr((string)$row['datum'], 0, 10);
        if ($datum === '') continue;
        $r  = (int)$row['klassering'];
        $rn = ($row['klassering_nl'] !== null) ? (int)$row['klassering_nl'] : null;
        $compId = $row['competition_id'];

        // Bucket aanmaken + kleur toewijzen (eerste keer).
        if (!isset($groepen[$grp][$canon])) {
            $idx = count($groepen[$grp]);
            $groepen[$grp][$canon] = ['color' => $palet[$idx % count($palet)], 'p' => [], '_keys' => []];
        }
        // Dedup per (comp, canon): beste (laagste) klassering houden.
        $dupKey = $compId;
        if (isset($groepen[$grp][$canon]['_keys'][$dupKey])) {
            $pos = $groepen[$grp][$canon]['_keys'][$dupKey];
            if ($r >= $groepen[$grp][$canon]['p'][$pos]['r']) continue;   // niet beter → skip
            // beter → vervang
        }
        $punt = [
            'd' => $datum,
            'c' => (string)$row['categorie'],
            'w' => (string)$row['wedstrijd'],
            'r' => $r,
        ];
        if ($rn !== null && $rn !== $r) $punt['rn'] = $rn;
        // Tijd alleen bij sprint (bij lang is de racetijd tactisch).
        if ($grp === 'sprint') {
            $tk = $compId . '|' . $canon;
            if (isset($tmap[$tk])) {
                $punt['t']  = $tmap[$tk]['t'];
                if ($tmap[$tk]['tr'] !== '') $punt['tr'] = $tmap[$tk]['tr'];
            } elseif ($row['beste_tijd_ms'] !== null) {
                $punt['t'] = (int)$row['beste_tijd_ms'];   // fallback: finaletijd (zonder ronde)
            }
        }

        if (isset($groepen[$grp][$canon]['_keys'][$dupKey])) {
            $groepen[$grp][$canon]['p'][$groepen[$grp][$canon]['_keys'][$dupKey]] = $punt;
        } else {
            $groepen[$grp][$canon]['_keys'][$dupKey] = count($groepen[$grp][$canon]['p']);
            $groepen[$grp][$canon]['p'][] = $punt;
            $nUitslagen++;
        }

        $wedstrijden[$compId] = true;
        if ($besteKlas === null || $r < $besteKlas) $besteKlas = $r;
        $seizoenen[substr($datum, 0, 4)] = true;
    }

    // Sorteer punten op datum + strip de interne _keys.
    foreach (['sprint', 'lang'] as $grp) {
        foreach ($groepen[$grp] as $canon => &$bucket) {
            usort($bucket['p'], fn($a, $b) => strcmp($a['d'], $b['d']));
            unset($bucket['_keys']);
        }
        unset($bucket);
    }

    return [
        'persoon' => $persoon ? [
            'license_key'  => $persoon['license_key'],
            'full_name'    => $persoon['full_name'],
            'category'     => $persoon['category'],
            'club'         => $persoon['club_full'] ?: $persoon['club_short'] ?: '',
            'nationality'  => $persoon['nationality'],
            'start_number' => $persoon['start_number'] !== null ? (int)$persoon['start_number'] : null,
        ] : null,
        'stats' => [
            'wedstrijden'      => count($wedstrijden),
            'uitslagen'        => $nUitslagen,
            'beste_klassering' => $besteKlas,
            'seizoenen'        => count($seizoenen),
        ],
        'sprint' => $groepen['sprint'],
        'lang'   => $groepen['lang'],
    ];
}

} // function_exists
