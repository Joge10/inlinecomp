<?php
// ============================================================
//  inc/person_id.php — gedeelde person_id ↔ license_key resolutie
//
//  Fase 3 van de person_id-migratie (dual-column-transitie). Tijdens de
//  overgang schrijven INSERT's BEIDE kolommen (person_license + person_id) zodat
//  er geen drift ontstaat; reads schuiven gaandeweg naar person_id. Bij fase 4
//  (license_key weg uit persons) verhuist deze lookup naar person_external_ids.
//  Zie docs_internal/plan-guid-migratie.md.
//
//  Gebruik bij een dual-write INSERT:
//    require_once __DIR__ . '/../inc/person_id.php';
//    $pid = personIdVoorLicentie($pdo, $license);
//    INSERT INTO kind (..., person_license, person_id) VALUES (..., ?, ?)
//    ON DUPLICATE KEY UPDATE ..., person_id = VALUES(person_id)
//  (bij INSERT ... SELECT: JOIN persons p en selecteer p.person_id mee.)
// ============================================================

if (!function_exists('personIdVoorLicentie')) {
    /** license_key → person_id (of null als onbekend). Statische cache per request. */
    function personIdVoorLicentie(PDO $pdo, ?string $license): ?string {
        static $cache = [];
        if ($license === null || $license === '') return null;
        if (array_key_exists($license, $cache)) return $cache[$license];
        $st = $pdo->prepare("SELECT person_id FROM persons WHERE license_key = ? LIMIT 1");
        $st->execute([$license]);
        $pid = $st->fetchColumn();
        return $cache[$license] = ($pid !== false ? (string)$pid : null);
    }
}
