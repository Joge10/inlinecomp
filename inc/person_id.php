<?php
// ============================================================
//  inc/person_id.php — gedeelde person_id ↔ license_key resolutie
//
//  Fase 4 van de person_id-migratie: person_id is de canonieke identiteit.
//  persons.license_key en de <child>.person_license/license_key-kolommen zijn
//  gedropt; de KNSB-licentie (en ic-extern/pending/demo/manual/anoniem) leeft
//  alleen nog in person_external_ids(person_id, systeem, extern_id). Alle
//  license↔person_id-resolutie loopt via die koppeltabel.
//  Zie docs_internal/plan-guid-migratie.md.
//
//  Gebruik:
//    require_once __DIR__ . '/../inc/person_id.php';
//    $pid = resolveNaarPersonId($pdo, $token);   // token = person_id OF licentie
//    INSERT INTO kind (..., person_id) VALUES (..., ?)
//  Na een mint (nieuwe persons-rij) de externe id borgen:
//    zorgVoorExternalId($pdo, $pid, $license);
// ============================================================

if (!function_exists('nieuwPersonId')) {
    /**
     * Genereert een nieuwe person_id (UUID v4) in PHP. Fase 4: persons heeft geen
     * license_key-PK meer om op terug te vinden en geen auto-increment, dus minten
     * we de GUID zelf en zetten die expliciet in de INSERT — zo kent de aanroeper
     * de person_id meteen voor child-inserts en zorgVoorExternalId().
     * (De DB-default UUID() blijft als vangnet voor kolommen die 'm niet meesturen.)
     */
    function nieuwPersonId(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // versie 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant
        $h = bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }
}

if (!function_exists('personIdVoorLicentie')) {
    /**
     * license_key/externe id → person_id (of null als onbekend).
     * Fase 4: persons.license_key bestaat niet meer — resolutie loopt via
     * person_external_ids (systeem afgeleid uit de vorm van de licentie).
     */
    function personIdVoorLicentie(PDO $pdo, ?string $license): ?string {
        if ($license === null || $license === '') return null;
        return personIdVoorExtern($pdo, systeemVoorLicentie($license), $license);
    }
}

if (!function_exists('licentieVoorPersonId')) {
    /**
     * person_id → licentie/externe id (of null). Omgekeerde van
     * personIdVoorLicentie. Fase 4: leest uit person_external_ids; de KNSB-
     * licentie ('knsb') heeft voorrang, anders de eerste beschikbare externe id
     * (ic-extern/ic-pending/…). Voor weergave/koppel; niet meer voor schaduw-
     * kolommen (die zijn in fase 4 gedropt). Statische cache per request.
     */
    function licentieVoorPersonId(PDO $pdo, ?string $personId): ?string {
        static $cache = [];
        if ($personId === null || $personId === '') return null;
        if (isset($cache[$personId])) return $cache[$personId];
        $st = $pdo->prepare("
            SELECT extern_id FROM person_external_ids
            WHERE person_id = ?
            ORDER BY (systeem = 'knsb') DESC
            LIMIT 1
        ");
        $st->execute([$personId]);
        $lk = $st->fetchColumn();
        if ($lk === false) return null;
        return $cache[$personId] = (string)$lk;
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
