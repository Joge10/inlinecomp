<?php
// ============================================================
//  InlineComp – vergelijk KNSB API met InlineComp database
//
//  GET /api/vergelijk.php?id={competition_id}
//
//  Geeft per categorie de deelnemers terug met:
//   knsb         : ruwe data uit KNSB API
//   db_person    : persoon uit InlineComp DB  (null = nog nooit gezien)
//   db_entry     : inschrijving voor déze comp (null = nog niet geïmporteerd)
//   db_tp1/tp2   : transponders in DB voor déze comp
//   entry_status : buitenste status  0=niet bevestigd  1=bevestigd  2=afgemeld
//   reserve      : null of volgnummer (1=1e reserve, 2=2e reserve …)
//   is_new       : persoon nog niet in DB (nog nooit een wedstrijd geïmporteerd)
//   diffs        : velden die afwijken tussen KNSB en DB
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$compId = trim($_GET['id'] ?? '');
if (!$compId) {
    http_response_code(400);
    echo json_encode(['error' => 'id ontbreekt']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';

function apiGet(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'Accept: application/json',
        'timeout' => 15,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : json_decode($raw, true);
}

// Bouw licentie-sleutel: voor anonieme rijders (leeg licenseKey) een synthetische sleutel
// op basis van startnummer + categorie, bv. "196_DKA_Anoniem"
function buildLicenseKey(?string $licenseKey, $startNumber, ?string $category): ?string {
    if ($licenseKey !== null && $licenseKey !== '') return $licenseKey;
    if ($startNumber !== null && $startNumber !== '' && $category) {
        return $startNumber . '_' . $category . '_Anoniem';
    }
    return null;
}

try {
    $base = 'https://inschrijven.schaatsen.nl/api';

    // 1. KNSB deelnemers ophalen
    $groepen = apiGet("$base/competitions/$compId/competitors");
    if (!$groepen) throw new RuntimeException('Kan deelnemers niet ophalen van KNSB');

    // 2. Alle license keys verzamelen (incl. synthetische sleutels voor anonieme rijders)
    $licenseKeys = [];
    foreach ($groepen as $groep) {
        foreach ($groep['competitors'] ?? [] as $item) {
            $c  = $item['competitor'] ?? [];
            $lk = buildLicenseKey(
                $c['licenseKey']  ?? null,
                $c['startNumber'] ?? null,
                $c['category']    ?? null
            );
            if ($lk) $licenseKeys[] = $lk;
        }
    }
    $licenseKeys = array_values(array_unique($licenseKeys));

    // 3. Personen uit DB
    $dbPersons = [];
    if ($licenseKeys) {
        $ph   = implode(',', array_fill(0, count($licenseKeys), '?'));
        $stmt = $pdo->prepare("SELECT * FROM persons WHERE license_key IN ($ph)");
        $stmt->execute($licenseKeys);
        foreach ($stmt->fetchAll() as $p) $dbPersons[$p['license_key']] = $p;
    }

    // 4. Entries voor déze competitie
    $dbEntries = [];
    $stmt = $pdo->prepare("
        SELECT e.person_license, e.distance_combination_id, e.knsb_entry_id, e.status
        FROM entries e
        JOIN distance_combinations dc ON e.distance_combination_id = dc.id
        WHERE dc.competition_id = ?
    ");
    $stmt->execute([$compId]);
    foreach ($stmt->fetchAll() as $e) {
        $dbEntries[$e['distance_combination_id']][$e['person_license']] = $e;
    }

    // 5. Transponders voor déze competitie
    $dbTp = [];
    $stmt = $pdo->prepare("SELECT * FROM transponders WHERE competition_id = ?");
    $stmt->execute([$compId]);
    foreach ($stmt->fetchAll() as $t) {
        $dbTp[$t['person_license']][$t['slot']] = $t;
    }

    // 6. Resultaat opbouwen
    $result = [];
    foreach ($groepen as $groep) {
        $dcId = $groep['id'];
        $rows = [];

        foreach ($groep['competitors'] ?? [] as $item) {
            $c  = $item['competitor'] ?? null;
            if (!$c) continue;

            $lk       = buildLicenseKey(
                $c['licenseKey']  ?? null,
                $c['startNumber'] ?? null,
                $c['category']    ?? null
            );
            $isAnoniem = ($c['licenseKey'] ?? null) === '' || $c['licenseKey'] === null;
            $dbPerson = $lk ? ($dbPersons[$lk] ?? null) : null;
            $dbEntry  = $lk ? ($dbEntries[$dcId][$lk] ?? null) : null;
            $tp1      = $lk ? ($dbTp[$lk][1] ?? null) : null;
            $tp2      = $lk ? ($dbTp[$lk][2] ?? null) : null;

            // Actieve transponder (slot 0) en extra transponders (slot >= 3)
            $tpActiefIsset = $lk && isset($dbTp[$lk][0]);          // slot 0 bestaat echt in DB
            $tpActief      = $tpActiefIsset ? $dbTp[$lk][0]['code'] : null;
            $tpExtra  = [];
            if ($lk && isset($dbTp[$lk])) {
                foreach ($dbTp[$lk] as $slot => $tp) {
                    if ($slot >= 3) $tpExtra[] = $tp['code'];
                }
            }

            // Diff-detectie: KNSB vs DB
            $diffs = [];
            if ($dbPerson) {
                if ((string)($c['startNumber'] ?? '') !== (string)($dbPerson['start_number'] ?? ''))
                    $diffs[] = 'start_number';
                if (($c['fullName'] ?? '') !== ($dbPerson['full_name'] ?? ''))
                    $diffs[] = 'full_name';
            }

            // reserve: null of volgnummer (1=1e reserve, 2=2e reserve …)
            $reserve = $item['reserve'];

            $rows[] = [
                'license_key'   => $lk,
                'is_anoniem'    => $isAnoniem,
                'knsb_entry_id' => $c['id'] ?? null,
                'entry_status'  => $item['status'],   // buitenste status
                'reserve'       => $reserve,
                'is_new'        => $dbPerson === null,
                'diffs'         => $diffs,
                'knsb' => [
                    'start_number' => $c['startNumber']     ?? null,
                    'full_name'    => $c['fullName']        ?? '',
                    'short_name'   => $c['shortName']       ?? null,
                    'gender'       => $c['gender']          ?? null,
                    'category'     => $c['category']        ?? null,
                    'nationality'  => $c['nationalityCode'] ?? 'NED',
                    'club_code'    => $c['clubCode']        ?? null,
                    'club_short'   => $c['clubShortName']   ?? null,
                    'club_full'    => $c['clubFullName']    ?? null,
                    'city'         => $c['from']            ?? null,
                    'transponder1' => $c['transponder1']    ?? null,
                    'transponder2' => $c['transponder2']    ?? null,
                ],
                'db_person'     => $dbPerson,
                'db_entry'      => $dbEntry,
                'db_tp1'        => $tp1,
                'db_tp2'        => $tp2,
                'db_tp_extra'        => $tpExtra,        // slot 3+ codes (organisatie)
                'db_tp_actief'       => $tpActief,       // slot 0: door voorbereider gekozen
                'db_tp_actief_isset' => $tpActiefIsset,  // true = bewust opgeslagen (ook null is dan geldig)
            ];
        }

        // Sortering: afgemelden onderaan, dan op effectief start_number
        usort($rows, function($a, $b) {
            $wA = ($a['entry_status'] === 2) ? 1 : 0;
            $wB = ($b['entry_status'] === 2) ? 1 : 0;
            if ($wA !== $wB) return $wA - $wB;
            $snA = $a['db_person']['start_number'] ?? $a['knsb']['start_number'] ?? 9999;
            $snB = $b['db_person']['start_number'] ?? $b['knsb']['start_number'] ?? 9999;
            return $snA - $snB;
        });

        $result[] = [
            'dc_id'       => $dcId,
            'dc_name'     => $groep['name']   ?? '',
            'dc_number'   => $groep['number'] ?? 0,
            'competitors' => $rows,
        ];
    }

    usort($result, fn($a, $b) => $a['dc_number'] - $b['dc_number']);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
