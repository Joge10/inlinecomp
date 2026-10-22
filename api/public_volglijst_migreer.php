<?php
// api/public_volglijst_migreer.php
// Endpoint: eenmalige omzetting van een oude volglijst (van vóór de GUID-migratie
// van 17-09-2026) van KNSB-licentienummers naar person_id's.
//
// Waarom apart en alleen POST: licentienummers zijn persoonsgegevens en horen
// niet in een URL (die komt in de serverlogs van de hostingprovider, 30-60 dagen).
// De app stuurt ze hier één keer in de body; daarna bewaart de browser alleen nog
// person_id's en gaat er nergens meer een licentienummer mee.
//
// Input (POST, JSON): { "license_keys": ["10157468", ...] }   (max 20)
// Output: { "map": { "<licentie>": "<person_id>", ... } }
//   Ontbreekt een licentie in de map, dan is die niet (meer) om te zetten: onbekend,
//   verwijderd (anonimiseren wist de koppeling) of publiek anoniem. De app laat die
//   volglijst-items dan vallen.
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'volglijst_migreer') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }
    try {
        $body  = json_decode(file_get_contents('php://input'), true);
        $lkeys = is_array($body['license_keys'] ?? null) ? $body['license_keys'] : [];
        $lkeys = array_values(array_unique(array_filter(
            array_map(fn($x) => trim((string)$x), $lkeys),
            fn($x) => $x !== '' && strlen($x) <= 32
        )));
        if (!$lkeys) { echo json_encode(['map' => new stdClass()]); exit; }
        if (count($lkeys) > 20) {
            http_response_code(400);
            echo json_encode(['error' => 'Too many license_keys']);
            exit;
        }
        $ph = implode(',', array_fill(0, count($lkeys), '?'));
        // Alleen bestaande, niet-gewiste en niet-publiek-anonieme rijders. Een
        // anonieme rijder volg je alleen met het geheime volg-ID, niet via een
        // (openbaar) licentienummer.
        $stmt = $pdo->prepare("
            SELECT x.extern_id, x.person_id
              FROM person_external_ids x
              JOIN persons p ON p.person_id = x.person_id
             WHERE x.systeem = 'knsb'
               AND x.extern_id IN ($ph)
               AND p.anonymized_at IS NULL
               AND p.publiek_anoniem IS NULL
        ");
        $stmt->execute($lkeys);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string)$r['extern_id']] = (string)$r['person_id'];
        }
        echo json_encode(['map' => $map ?: new stdClass()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Migratie mislukt']);
    }
    exit;
}
