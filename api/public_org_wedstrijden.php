<?php
// api/public_org_wedstrijden.php
// Endpoint: alle (echte, niet-demo) wedstrijden van één organisatie.
// Inclusief niet-publieke, zodat client-side labels gezet kunnen worden
// (public / binnenkort / verborgen) en niet-publieke als disabled getoond.
//
// Input (GET): org_id = UUID van de organisatie.
// Output: JSON array van wedstrijd-objecten (zelfde shape als competitions-
//         endpoint, zonder sponsors — die laden pas bij wedstrijd-opening).
//
// Fase 5a-content (2026-10-02): voor de agenda-tab in de org-detail-view.
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'org_wedstrijden') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=30');
    try {
        $orgId = trim($_GET['org_id'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $orgId)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid org_id']);
            exit;
        }

        // Optioneel: person_ids van de user — bepaalt per wedstrijd of een
        // eigen gevolgde rijder meedeed. AVG-grondslag: alleen wedstrijden
        // waar de user zelf context mee heeft (eigen rijder) zijn klikbaar
        // in de org-agenda. De andere wedstrijden worden wel getoond (zodat
        // de lijst klopt), maar als disabled.
        $raw = trim($_GET['person_ids'] ?? '');
        $pids = [];
        if ($raw !== '') {
            $pids = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $raw)),
                fn($x) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $x)
            )));
            if (count($pids) > 20) $pids = array_slice($pids, 0, 20);
        }

        if ($pids) {
            $ph  = implode(',', array_fill(0, count($pids), '?'));

            // ── Twee queries + PHP-merge, want MariaDB kan `c.id` niet
            //    correleren door een UNION-subquery heen (SQLSTATE 42S22).
            // ── Query 1: alle wedstrijden van de org (zonder person-join).
            $sql1 = "
                SELECT c.id, c.name, c.starts, c.ends,
                       c.organisatie_id, c.baan_id, c.public_zichtbaar, c.public_aankondigen,
                       b.logo_path AS baan_logo,
                       b.vereniging_naam AS baan_vereniging
                FROM competitions c
                LEFT JOIN banen b ON b.id = c.baan_id
                WHERE c.organisatie_id = ?
                  AND c.is_demo = 0
                ORDER BY c.starts DESC
            ";
            $stmt = $pdo->prepare($sql1);
            $stmt->execute([$orgId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── Query 2: per wedstrijd welke rijders uit de volglijst
            //    meededen, via UNION over 3 bronnen (heat_entries,
            //    competition_startnummers, uitslag_afstand) — die laatste
            //    vangt historische geimporteerde wedstrijden (alleen
            //    uitslagen, geen heats of startnr-toekenningen).
            //    GROUP_CONCAT dedupeert; comp_id-sleutel voor merge.
            $sql2 = "
                SELECT comp_id, GROUP_CONCAT(DISTINCT pid ORDER BY pid SEPARATOR ',') AS pids
                FROM (
                    SELECT h.competition_id AS comp_id, he.person_id AS pid
                    FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    JOIN competitions c  ON c.id = h.competition_id
                    WHERE c.organisatie_id = ? AND he.person_id IN ($ph)
                    UNION
                    SELECT cs.competition_id, cs.person_id
                    FROM competition_startnummers cs
                    JOIN competitions c ON c.id = cs.competition_id
                    WHERE c.organisatie_id = ? AND cs.person_id IN ($ph)
                    UNION
                    SELECT ua.competition_id, ua.person_id
                    FROM uitslag_afstand ua
                    JOIN competitions c ON c.id = ua.competition_id
                    WHERE c.organisatie_id = ? AND ua.person_id IN ($ph)
                ) t
                GROUP BY comp_id
            ";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute(array_merge(
                [$orgId], $pids,
                [$orgId], $pids,
                [$orgId], $pids
            ));
            $pidsByComp = [];
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r2) {
                $pidsByComp[$r2['comp_id']] = $r2['pids'];
            }
            // Merge query-2-resultaat per wedstrijd.
            foreach ($rows as &$r) {
                $r['eigen_person_ids'] = $pidsByComp[$r['id']] ?? null;
            }
            unset($r);
        } else {
            $sql = "
                SELECT c.id, c.name, c.starts, c.ends,
                       c.organisatie_id, c.baan_id, c.public_zichtbaar, c.public_aankondigen,
                       b.logo_path AS baan_logo,
                       b.vereniging_naam AS baan_vereniging,
                       NULL AS eigen_person_ids
                FROM competitions c
                LEFT JOIN banen b ON b.id = c.baan_id
                WHERE c.organisatie_id = ?
                  AND c.is_demo = 0
                ORDER BY c.starts DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$orgId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as &$r) {
            $r['public_zichtbaar']   = (int)$r['public_zichtbaar'];
            $r['public_aankondigen'] = (int)$r['public_aankondigen'];
            // eigen_person_ids blijft string (comma-sep) of null; client splitst.
        }
        unset($r);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
