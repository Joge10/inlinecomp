<?php
// ============================================================
//  InlineComp – PR-helper
//
//  Gedeelde PR-detectie-logica voor zowel het uitgebreide PR-rapport
//  (helpers → rapport_pr.php) als het wedstrijdrapport (protokol → PR-
//  sectie). Levert alleen de ECHTE PR-verbeteringen: rijders die in deze
//  wedstrijd hun persoonlijke record op een afstand hebben verbeterd.
//
//  Tijd-bron-prioriteit: bruto_tijd_ms (rauw gemeten) heeft voorrang op
//  tijd_ms (gecorrigeerd) — sluit aan op rapport_pr.php waar bruto-tijd
//  ook accurater is voor PR-detectie (foto-finish-correcties veranderen
//  niet de werkelijke gereden tijd).
//
//  Sprint-filter: alleen afstanden ≤ 1000m en geen puntenkoers/afval-
//  koers — tijden op die race-types zijn niet vergelijkbaar (tactisch).
//
//  Returns: array van rows met velden:
//      person_license, full_name, categorie, afstand_naam, afstand_meters,
//      gereden_ms, ronde_label, heat_nr,
//      pr_ms, pr_wedstrijd, pr_datum, pr_ronde, delta_ms
//  Gesorteerd op afstand_meters ASC, cat (alfabetisch) ASC, delta ASC
//  (grootste verbetering eerst binnen cat × afstand). De render-laag
//  hersorteert eventueel naar KNSB-cat-volgorde.
// ============================================================

function getNieuwePRs(PDO $pdo, string $compId, string $compStarts): array {
    $sql = "
WITH current_results AS (
    SELECT
        d.name                                   AS afstand_naam,
        d.value_meters                           AS afstand_meters,
        p.category                               AS categorie,
        p.license_key                            AS person_license,
        p.full_name                              AS full_name,
        p.start_number                           AS start_number,
        COALESCE(tsr.ronde_type,
            CASE WHEN h.heat_naam LIKE '%finale%' THEN 'finale_a' ELSE 'heats' END
        )                                        AS ronde_type,
        h.heat_nr                                AS heat_nr,
        COALESCE(res.bruto_tijd_ms, res.tijd_ms) AS gereden_ms,
        CASE
            WHEN LOWER(d.name) LIKE '%marathon%' THEN 'marathon'
            WHEN LOWER(d.name) LIKE '%relay%' OR LOWER(d.name) LIKE '%estafette%'
                THEN LOWER(CONCAT(REGEXP_SUBSTR(d.name, '[0-9]+'), 'm-relay'))
            ELSE LOWER(CONCAT(REGEXP_SUBSTR(d.name, '[0-9]+'), 'm'))
        END                                      AS afstand_key,
        ROW_NUMBER() OVER (
            PARTITION BY p.license_key, d.name, p.category
            ORDER BY COALESCE(res.bruto_tijd_ms, res.tijd_ms)
        )                                        AS rider_rn
    FROM results res
    JOIN heat_entries           he  ON he.id = res.heat_entry_id
    JOIN heats                  h   ON h.id  = he.heat_id
    JOIN persons                p   ON p.license_key = he.person_license
    LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
    JOIN distances              d   ON d.id  = h.distance_id
                                   AND d.distance_combination_id = h.distance_combination_id
    WHERE COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
      AND res.sanctie IS NULL
      AND h.competition_id = ?
      AND d.value_meters <= 1000
      AND COALESCE(d.race_type, 'sprint') NOT IN ('puntenkoers', 'afvalkoers')
),
best_per_rider AS (
    SELECT * FROM current_results WHERE rider_rn = 1
),
pr_source_results AS (
    SELECT
        he.person_license,
        d.name                                   AS distance_naam,
        COALESCE(res.bruto_tijd_ms, res.tijd_ms) AS tijd_ms,
        c.name                                   AS comp_naam,
        c.starts                                 AS comp_datum,
        CASE COALESCE(tsr.ronde_type,
                      CASE WHEN h.heat_naam LIKE '%finale%' THEN 'finale_a'
                           ELSE 'heats' END)
            WHEN 'heats'        THEN CONCAT('Serie heat ',     COALESCE(h.heat_nr, 1))
            WHEN 'kwartfinale'  THEN CONCAT('KF heat ',        COALESCE(h.heat_nr, 1))
            WHEN 'halve_finale' THEN CONCAT('HF heat ',        COALESCE(h.heat_nr, 1))
            WHEN 'finale_a'     THEN CONCAT('A-finale heat ',  COALESCE(h.heat_nr, 1))
            WHEN 'finale_b'     THEN CONCAT('B-finale heat ',  COALESCE(h.heat_nr, 1))
            WHEN 'runner_up'    THEN CONCAT('Runner-up heat ', COALESCE(h.heat_nr, 1))
            ELSE CONCAT('R', h.ronde, ' heat ', COALESCE(h.heat_nr, 1))
        END                                      AS ronde_label
    FROM results res
    JOIN heat_entries he  ON he.id = res.heat_entry_id
    JOIN heats        h   ON h.id  = he.heat_id
    LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
    JOIN distances    d   ON d.id  = h.distance_id
                         AND d.distance_combination_id = h.distance_combination_id
    JOIN competitions c   ON c.id  = h.competition_id
    WHERE COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
      AND res.sanctie IS NULL
      AND h.competition_id != ?
      AND c.starts < ?
      AND d.value_meters <= 1000
      AND COALESCE(d.race_type, 'sprint') NOT IN ('puntenkoers', 'afvalkoers')
),
pr_source_uitslag AS (
    SELECT
        ua.person_license,
        ua.distance_naam,
        ua.tijd_ms,
        ua.competition_naam                      AS comp_naam,
        ua.competition_datum                     AS comp_datum,
        COALESCE(NULLIF(ua.finale_naam, ''), '') AS ronde_label
    FROM uitslag_afstand ua
    WHERE ua.competition_id != ?
      AND ua.competition_datum < ?
      AND ua.tijd_ms IS NOT NULL
      AND ua.tijd_ms > 0
      AND ua.sanctie IS NULL
      AND ua.distance_meters IS NOT NULL
      AND ua.distance_meters <= 1000
      AND LOWER(ua.distance_naam) NOT LIKE '%punten%'
      AND LOWER(ua.distance_naam) NOT LIKE '%points%'
      AND LOWER(ua.distance_naam) NOT LIKE '%afval%'
      AND LOWER(ua.distance_naam) NOT LIKE '%elimination%'
      AND LOWER(ua.distance_naam) NOT LIKE '%eliminatie%'
),
pr_combined AS (
    SELECT
        person_license,
        CASE
            WHEN LOWER(distance_naam) LIKE '%marathon%' THEN 'marathon'
            WHEN LOWER(distance_naam) LIKE '%relay%' OR LOWER(distance_naam) LIKE '%estafette%'
                THEN LOWER(CONCAT(REGEXP_SUBSTR(distance_naam, '[0-9]+'), 'm-relay'))
            ELSE LOWER(CONCAT(REGEXP_SUBSTR(distance_naam, '[0-9]+'), 'm'))
        END                                      AS afstand_key,
        tijd_ms,
        comp_naam,
        comp_datum,
        ronde_label
    FROM (
        SELECT * FROM pr_source_results
        UNION ALL
        SELECT * FROM pr_source_uitslag
    ) x
),
pr_history AS (
    SELECT
        person_license,
        afstand_key,
        tijd_ms                                  AS pr_ms,
        comp_naam                                AS pr_wedstrijd,
        comp_datum                               AS pr_datum,
        ronde_label                              AS pr_ronde,
        ROW_NUMBER() OVER (
            PARTITION BY person_license, afstand_key
            ORDER BY tijd_ms ASC, comp_datum ASC
        )                                        AS pr_rn
    FROM pr_combined
),
pr_best AS (
    SELECT person_license, afstand_key, pr_ms, pr_wedstrijd, pr_datum, pr_ronde
    FROM pr_history
    WHERE pr_rn = 1
)
SELECT
    b.person_license,
    b.full_name,
    b.categorie,
    b.start_number,
    b.afstand_naam,
    b.afstand_meters,
    b.gereden_ms,
    b.ronde_type,
    b.heat_nr,
    pr.pr_ms,
    pr.pr_wedstrijd,
    pr.pr_datum,
    pr.pr_ronde,
    -- BIGINT UNSIGNED kan geen negatieve waarde dragen — cast eerst naar
    -- SIGNED zodat (gereden_ms - pr_ms) een geldig negatief getal wordt
    -- bij een PR-verbetering. Anders gooit MariaDB error 1690.
    (CAST(b.gereden_ms AS SIGNED) - CAST(pr.pr_ms AS SIGNED)) AS delta_ms
FROM best_per_rider b
JOIN pr_best pr
       ON pr.person_license = b.person_license
      AND pr.afstand_key    = b.afstand_key
WHERE b.gereden_ms < pr.pr_ms
ORDER BY b.afstand_meters ASC, b.categorie ASC,
         (CAST(b.gereden_ms AS SIGNED) - CAST(pr.pr_ms AS SIGNED)) ASC
";

    $stmt = $pdo->prepare($sql);
    // Vijf placeholders, in CTE-volgorde:
    //   1. current_results       — h.competition_id = ?    ($compId)
    //   2. pr_source_results     — h.competition_id != ?   ($compId)
    //   3. pr_source_results     — c.starts < ?            ($compStarts)
    //   4. pr_source_uitslag     — ua.competition_id != ?  ($compId)
    //   5. pr_source_uitslag     — ua.competition_datum < ? ($compStarts)
    $stmt->execute([$compId, $compId, $compStarts, $compId, $compStarts]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normaliseer ronde-label voor de uit-PR-rapport-bron (heat_nr + ronde_type).
    // Voor de huidige wedstrijd's tijd hebben we ronde_type + heat_nr direct in
    // de query — render-laag bouwt het label.
    foreach ($rows as &$r) {
        $r['gereden_ms']     = $r['gereden_ms']     !== null ? (int)$r['gereden_ms']     : null;
        $r['pr_ms']          = $r['pr_ms']          !== null ? (int)$r['pr_ms']          : null;
        $r['delta_ms']       = $r['delta_ms']       !== null ? (int)$r['delta_ms']       : null;
        $r['afstand_meters'] = $r['afstand_meters'] !== null ? (int)$r['afstand_meters'] : null;
        $r['heat_nr']        = $r['heat_nr']        !== null ? (int)$r['heat_nr']        : null;
        $r['start_number']   = $r['start_number']   !== null ? (int)$r['start_number']   : null;
    }
    unset($r);
    return $rows;
}
