<?php
// api/public_mijn_wedstrijden.php
// Endpoint: per wedstrijd welke rijders uit de persoonlijke volglijst van de
// user meededen. Lightweight tegenhanger van public_org_wedstrijden.php's
// query-2 maar zonder org-filter — bedoeld voor de hub Wedstrijden-tab om
// voornaam-pillen te tonen op de kaarten.
//
// Input (GET): person_ids = comma-separated UUIDs, en/of
//              license_keys = comma-separated license-keys (legacy support
//              voor pre-GUID-migratie volglijst-items).
// Output: JSON object {
//   "wedstrijden": { "<comp_id>": "pid1,pid2,...", ... },
//   "licenses":    { "<license_key>": "<person_id>", ... }  // alleen als er legacy keys werden geresolved
// }
//
// Fase 5a-UX-flat (2026-10-03): voornaam-pillen verhuizen van org-view-agenda
// naar hub Wedstrijden-tab op verzoak van Geert.
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'mijn_wedstrijden') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=30');
    try {
        $raw = trim($_GET['person_ids'] ?? '');
        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn($x) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $x)
        )));

        // Geen KNSB-licentienummers meer via de URL — zie api/public_volglijst_migreer.php.
        // 'licenses' blijft als leeg object in de response (vorm voor oude app-versies).
        $licenseMap = [];

        if (!$ids) {
            echo json_encode(['wedstrijden' => new stdClass(), 'licenses' => (object)$licenseMap]);
            exit;
        }
        if (count($ids) > 20) $ids = array_slice($ids, 0, 20);

        $ph = implode(',', array_fill(0, count($ids), '?'));
        // UNION over 3 bronnen (heat_entries, competition_startnummers,
        // uitslag_afstand) zodat ook historische geimporteerde wedstrijden
        // meekomen (alleen uitslag, geen heats/startnrs). Alleen niet-demo
        // wedstrijden; publieke én nog-niet-publieke (user-eigen context
        // wordt in de hub lijst getoond zoals de rest — pil is puur hint).
        $sql = "
            SELECT comp_id, GROUP_CONCAT(DISTINCT pid ORDER BY pid SEPARATOR ',') AS pids
            FROM (
                SELECT h.competition_id AS comp_id, he.person_id AS pid
                FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                JOIN competitions c  ON c.id = h.competition_id
                WHERE c.is_demo = 0 AND he.person_id IN ($ph)
                UNION
                SELECT cs.competition_id, cs.person_id
                FROM competition_startnummers cs
                JOIN competitions c ON c.id = cs.competition_id
                WHERE c.is_demo = 0 AND cs.person_id IN ($ph)
                UNION
                SELECT ua.competition_id, ua.person_id
                FROM uitslag_afstand ua
                JOIN competitions c ON c.id = ua.competition_id
                WHERE c.is_demo = 0 AND ua.person_id IN ($ph)
            ) t
            GROUP BY comp_id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($ids, $ids, $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['comp_id']] = $r['pids'];
        }
        echo json_encode([
            'wedstrijden' => $out ?: new stdClass(),
            'licenses'    => (object)$licenseMap,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
