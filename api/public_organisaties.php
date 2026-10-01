<?php
// api/public_organisaties.php
// Endpoint: lijst van organisaties waar rijders uit de persoonlijke
// volglijst van de user ooit een wedstrijd hebben gereden. AVG-correct:
// alleen org'en waarmee de user al bekend is via zijn eigen volglijst.
//
// Input (GET): person_ids = comma-separated lijst van person_id-UUIDs.
// Output: JSON array van {id, naam, logo_path, aantal_wedstrijden}.
//
// Geextraheerd uit public/index.php op 2026-10-02 (fase 5a-content).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'organisaties') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    try {
        $raw = trim($_GET['person_ids'] ?? '');
        if ($raw === '') { echo json_encode([]); exit; }

        // UUID-validatie en de-duplicate. Max 20 ids om misbruik te voorkomen
        // (MAX_KINDEREN=4, met wat buffer voor historische volglijst-items).
        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn($x) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $x)
        )));
        if (!$ids) { echo json_encode([]); exit; }
        if (count($ids) > 20) {
            http_response_code(400);
            echo json_encode(['error' => 'Too many person_ids']);
            exit;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Na de GUID-migratie (fase 4, 2026-09-16) zit person_id direct in
        // heat_entries — persons.license_key en heat_entries.person_license
        // zijn verdwenen. Geen persons-JOIN meer nodig.
        //
        // Twee stukken: WHERE/JOIN kiest de org's waar user-rijders hebben
        // gereden (AVG-filter). De subquery toont per org het *totale* aantal
        // (echte) wedstrijden in de DB — ook de nog-niet-publieke; de
        // organisatie-detail-view toont ze later wel/niet (disabled voor
        // niet-publieke of niet-gevolgd-door-user). Alleen demo-wedstrijden
        // worden niet meegeteld (zijn geen echte wedstrijden).
        $sql = "
            SELECT
                o.id, o.naam, o.logo_path,
                (SELECT COUNT(*)
                   FROM competitions c2
                  WHERE c2.organisatie_id = o.id
                    AND c2.is_demo = 0
                ) AS aantal_wedstrijden
            FROM organisaties o
            JOIN competitions  c  ON c.organisatie_id = o.id
            JOIN heats         h  ON h.competition_id = c.id
            JOIN heat_entries  he ON he.heat_id       = h.id
            WHERE he.person_id IN ($ph)
              AND c.is_demo = 0
              AND (c.public_zichtbaar = 1 OR c.public_aankondigen = 1)
            GROUP BY o.id, o.naam, o.logo_path
            ORDER BY o.naam ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // aantal_wedstrijden als int ipv string meegeven.
        foreach ($rows as &$r) { $r['aantal_wedstrijden'] = (int)$r['aantal_wedstrijden']; }
        unset($r);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
