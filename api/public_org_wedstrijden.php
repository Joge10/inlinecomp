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
            // GROUP_CONCAT geeft de startnummers (oplopend, DISTINCT) van alle
            // rijders uit de volglijst die aan deze wedstrijd meededen. NULL
            // (geen pil) als niemand uit de volglijst meedeed.
            $sql = "
                SELECT c.id, c.name, c.starts, c.ends,
                       c.organisatie_id, c.baan_id, c.public_zichtbaar, c.public_aankondigen,
                       b.logo_path AS baan_logo,
                       b.vereniging_naam AS baan_vereniging,
                       (SELECT GROUP_CONCAT(DISTINCT he.startnummer
                                            ORDER BY he.startnummer SEPARATOR ',')
                          FROM heats h2
                          JOIN heat_entries he ON he.heat_id = h2.id
                         WHERE h2.competition_id = c.id
                           AND he.person_id IN ($ph)
                           AND he.startnummer IS NOT NULL
                       ) AS eigen_startnummers
                FROM competitions c
                LEFT JOIN banen b ON b.id = c.baan_id
                WHERE c.organisatie_id = ?
                  AND c.is_demo = 0
                ORDER BY c.starts DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($pids, [$orgId]));
        } else {
            $sql = "
                SELECT c.id, c.name, c.starts, c.ends,
                       c.organisatie_id, c.baan_id, c.public_zichtbaar, c.public_aankondigen,
                       b.logo_path AS baan_logo,
                       b.vereniging_naam AS baan_vereniging,
                       NULL AS eigen_startnummers
                FROM competitions c
                LEFT JOIN banen b ON b.id = c.baan_id
                WHERE c.organisatie_id = ?
                  AND c.is_demo = 0
                ORDER BY c.starts DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$orgId]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['public_zichtbaar']   = (int)$r['public_zichtbaar'];
            $r['public_aankondigen'] = (int)$r['public_aankondigen'];
            // eigen_startnummers blijft string (comma-sep) of null; client splitst.
        }
        unset($r);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
