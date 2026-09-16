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
        if (isset($cache[$license])) return $cache[$license];
        $st = $pdo->prepare("SELECT person_id FROM persons WHERE license_key = ? LIMIT 1");
        $st->execute([$license]);
        $pid = $st->fetchColumn();
        // Niet-gevonden NIET cachen: dezelfde licentie kan later in dezelfde
        // request geïnsert worden (bv. rijder in meerdere DC's / net geminte).
        if ($pid === false) return null;
        return $cache[$license] = (string)$pid;
    }
}

if (!function_exists('systeemVoorLicentie')) {
    /**
     * Bepaalt het person_external_ids.systeem-label uit de vorm van de license_key.
     * ÉÉN bron van waarheid — spiegelt de CASE in fase-1c van de migratie:
     *   'x-…' → ic-extern (CSV/buitenlands)      'p-…' → ic-pending (PDF-historie)
     *   'demo-…' → ic-demo                        'manual_…' → ic-manual (handmatig)
     *   '…_Anoniem' → ic-anoniem (KNSB-feed anoniem, geen licentie)
     *   anders (numeriek) → knsb (echt KNSB-relatienummer)
     */
    function systeemVoorLicentie(string $license): string {
        if (strncmp($license, 'x-', 2) === 0)       return 'ic-extern';
        if (strncmp($license, 'p-', 2) === 0)       return 'ic-pending';
        if (strncmp($license, 'demo-', 5) === 0)    return 'ic-demo';
        if (strncmp($license, 'manual_', 7) === 0)  return 'ic-manual';
        if (substr($license, -8) === '_Anoniem')    return 'ic-anoniem';
        return 'knsb';
    }
}

if (!function_exists('personIdVoorExtern')) {
    /** (systeem, extern_id) → person_id via person_external_ids (of null). Statische cache. */
    function personIdVoorExtern(PDO $pdo, string $systeem, ?string $externId): ?string {
        static $cache = [];
        if ($externId === null || $externId === '') return null;
        $k = $systeem . "\0" . $externId;
        if (isset($cache[$k])) return $cache[$k];
        $st = $pdo->prepare("SELECT person_id FROM person_external_ids WHERE systeem = ? AND extern_id = ? LIMIT 1");
        $st->execute([$systeem, $externId]);
        $pid = $st->fetchColumn();
        // Niet-gevonden NIET cachen: de mapping kan later in dezelfde request
        // ontstaan (net geminte rijder die in een tweede DC nog eens langskomt).
        if ($pid === false) return null;
        return $cache[$k] = (string)$pid;
    }
}

if (!function_exists('resolveNaarPersonId')) {
    /**
     * Universele identiteit-resolutie voor request-tokens (fase 3d-iii).
     * Een endpoint kan een token binnenkrijgen dat óf een externe id (KNSB-licentie
     * / ic-*) is, óf al een person_id. We resolven het naar person_id:
     *   - komt het voor als extern_id in person_external_ids → die person_id;
     *   - anders → het token zélf (het is al een person_id, of onbekend → geen match).
     * Zo accepteert elk endpoint zowel oude (licentie) als nieuwe (person_id) JS,
     * en kunnen bestanden onafhankelijk omgezet worden zonder de app te breken.
     */
    function resolveNaarPersonId(PDO $pdo, ?string $token): ?string {
        if ($token === null || $token === '') return null;
        return personIdVoorExtern($pdo, systeemVoorLicentie($token), $token) ?? $token;
    }
}

if (!function_exists('zorgVoorExternalId')) {
    /**
     * Zorgt dat er een person_external_ids-rij bestaat voor deze (net geminte of
     * bijgewerkte) rijder. Het systeem-label volgt uit de license_key-vorm.
     * Idempotent: ON DUPLICATE KEY (PK = systeem+extern_id) herstelt alleen de
     * person_id-koppeling als die ooit zou verschuiven. Roep dit aan ná elke mint.
     * Fase 4: hier verhuist de KNSB-licentie definitief naartoe (persons.license_key weg).
     */
    function zorgVoorExternalId(PDO $pdo, ?string $personId, ?string $license): void {
        if ($personId === null || $personId === '' || $license === null || $license === '') return;
        $st = $pdo->prepare("
            INSERT INTO person_external_ids (person_id, systeem, extern_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE person_id = VALUES(person_id)
        ");
        $st->execute([$personId, systeemVoorLicentie($license), $license]);
    }
}
