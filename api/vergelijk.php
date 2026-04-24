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
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

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

function newUuid(): string {
    $b    = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

try {

    $base = 'https://inschrijven.schaatsen.nl/api';

    // 1a. KNSB competitie-detail ophalen (voor organisatie + sponsor info)
    $compDetail   = apiGet("$base/competitions/$compId") ?? [];
    $contact      = $compDetail['settings']['contact'] ?? [];
    $orgNaam      = trim($contact['organizationName'] ?? '');
    $orgEmail     = trim($contact['email']            ?? '') ?: null;
    $knsb_sponsor = trim($compDetail['sponsor']       ?? '') ?: null;

    // 1b. Organisatie opzoeken of aanmaken
    //     Volgorde: 1) e-mail (meest betrouwbaar, vangt naamswijzigingen op)
    //               2) exacte naam
    //               3) alias
    //               Nieuw aanmaken als niets gevonden.
    //     Elke nieuwe naamvariant wordt automatisch als alias opgeslagen.
    $organisatie = null;
    if ($orgNaam || $orgEmail) {

        // 1. Match op e-mailadres (case-insensitive)
        if ($orgEmail) {
            $stmt = $pdo->prepare("SELECT * FROM organisaties WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$orgEmail]);
            $organisatie = $stmt->fetch() ?: null;
        }

        // 2. Match op exacte naam
        if (!$organisatie && $orgNaam) {
            $stmt = $pdo->prepare("SELECT * FROM organisaties WHERE naam = ?");
            $stmt->execute([$orgNaam]);
            $organisatie = $stmt->fetch() ?: null;
        }

        // 3. Match via alias
        if (!$organisatie && $orgNaam) {
            $stmt = $pdo->prepare(
                "SELECT o.* FROM organisaties o
                 JOIN organisatie_aliassen a ON a.organisatie_id = o.id
                 WHERE a.naam = ?"
            );
            $stmt->execute([$orgNaam]);
            $organisatie = $stmt->fetch() ?: null;
        }

        if ($organisatie) {
            // Email aanvullen als die nog ontbrak
            if (empty($organisatie['email']) && $orgEmail) {
                $pdo->prepare("UPDATE organisaties SET email = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$orgEmail, $organisatie['id']]);
                $organisatie['email'] = $orgEmail;
            }

            // Nieuwe naamvariant als alias bewaren (zodat we hem later herkennen)
            if ($orgNaam && $orgNaam !== $organisatie['naam']) {
                $bestaatAl = $pdo->prepare(
                    "SELECT 1 FROM organisatie_aliassen WHERE naam = ?"
                );
                $bestaatAl->execute([$orgNaam]);
                if (!$bestaatAl->fetchColumn()) {
                    $pdo->prepare(
                        "INSERT INTO organisatie_aliassen (id, organisatie_id, naam) VALUES (?,?,?)"
                    )->execute([newUuid(), $organisatie['id'], $orgNaam]);
                }
            }

            // Sponsors ophalen
            $stmt = $pdo->prepare(
                "SELECT * FROM organisatie_sponsors WHERE organisatie_id = ? ORDER BY volgorde, naam"
            );
            $stmt->execute([$organisatie['id']]);
            $organisatie['sponsors'] = $stmt->fetchAll();

            // Hoofdsponsor aanvullen als er nog geen sponsors zijn
            if (empty($organisatie['sponsors']) && $knsb_sponsor) {
                $sId = newUuid();
                $pdo->prepare(
                    "INSERT INTO organisatie_sponsors (id, organisatie_id, naam, volgorde) VALUES (?,?,?,0)"
                )->execute([$sId, $organisatie['id'], $knsb_sponsor]);
                $organisatie['sponsors'] = [['id' => $sId, 'naam' => $knsb_sponsor,
                                              'logo_path' => null, 'url' => null, 'volgorde' => 0]];
            }
        } else {
            // Nieuw aanmaken (alleen als we een naam hebben)
            if ($orgNaam) {
                $orgId = newUuid();
                $pdo->prepare("INSERT INTO organisaties (id, naam, email) VALUES (?, ?, ?)")
                    ->execute([$orgId, $orgNaam, $orgEmail]);
                $organisatie = ['id' => $orgId, 'naam' => $orgNaam,
                                'email' => $orgEmail, 'logo_path' => null, 'sponsors' => []];

                // Hoofdsponsor uit KNSB meteen aanmaken
                if ($knsb_sponsor) {
                    $sId = newUuid();
                    $pdo->prepare(
                        "INSERT INTO organisatie_sponsors (id, organisatie_id, naam, volgorde) VALUES (?,?,?,0)"
                    )->execute([$sId, $orgId, $knsb_sponsor]);
                    $organisatie['sponsors'][] = ['id' => $sId, 'naam' => $knsb_sponsor,
                                                   'logo_path' => null, 'url' => null, 'volgorde' => 0];
                }
            }
        }

        // Competitie koppelen aan organisatie (als nog niet gekoppeld)
        if ($organisatie) {
            $pdo->prepare(
                "UPDATE competitions SET organisatie_id = ? WHERE id = ? AND (organisatie_id IS NULL OR organisatie_id = '')"
            )->execute([$organisatie['id'], $compId]);
        }
    }

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

    // 5b. Merge-groepen + category_filter voor déze competitie
    $mergeGroups  = [];
    $mergeLabels  = [];
    $catFilters   = [];
    $stmt = $pdo->prepare("SELECT id, merge_group, merge_label, category_filter FROM distance_combinations WHERE competition_id = ?");
    $stmt->execute([$compId]);
    foreach ($stmt->fetchAll() as $row) {
        $mergeGroups[$row['id']] = $row['merge_group'];
        $mergeLabels[$row['id']] = $row['merge_label'];
        $catFilters[$row['id']]  = $row['category_filter'] ?? null;
    }

    // 5d. Extra license-keys laden: org-toegevoegde rijders (in DB maar niet in KNSB API)
    $allDbLks = [];
    foreach ($dbEntries as $entries) {
        foreach (array_keys($entries) as $lk) {
            if (!in_array($lk, $licenseKeys, true)) $allDbLks[] = $lk;
        }
    }
    $allDbLks = array_values(array_unique($allDbLks));
    if ($allDbLks) {
        $ph   = implode(',', array_fill(0, count($allDbLks), '?'));
        $stmt = $pdo->prepare("SELECT * FROM persons WHERE license_key IN ($ph)");
        $stmt->execute($allDbLks);
        foreach ($stmt->fetchAll() as $p) $dbPersons[$p['license_key']] = $p;
    }

    // 5c. Split-configuratie voor déze competitie
    $splitConfig = [];   // {dc_id: {category: split_group}}
    $stmt = $pdo->prepare("SELECT dc_id, category, split_group FROM dc_splits WHERE competition_id = ?");
    $stmt->execute([$compId]);
    foreach ($stmt->fetchAll() as $row) {
        $splitConfig[$row['dc_id']][$row['category']] = $row['split_group'];
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
                // knsb_status = altijd de status zoals de KNSB API hem geeft (0/1/2); nooit overschreven.
                // entry_status = effectieve status: eigen org-statussen (3/4/5) bewaren bij resync;
                //   KNSB-afgemeld (2) heeft altijd voorrang.
                //   Status 5 (Bevestigd bij org.) vervalt als KNSB de rijder alsnog bevestigt (→ status 1).
                'knsb_status'   => (int)($item['status'] ?? 1),
                'entry_status'  => (function() use ($item, $dbEntry) {
                    $knsbSt = (int)($item['status'] ?? 1);
                    $dbSt   = $dbEntry ? (int)$dbEntry['status'] : null;
                    if ($dbSt !== null && $dbSt >= 3 && $knsbSt !== 2) {
                        // Status 5 (bevestigd bij org.) vervalt als KNSB nu bevestigt
                        if ($dbSt === 5 && $knsbSt !== 0) return $knsbSt;
                        return $dbSt;
                    }
                    return $knsbSt;
                })(),
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
                    'sponsor'      => $c['sponsor']         ?? null,
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
        // Status 5 (Bevestigd bij org.) telt als actief (niet onderaan)
        usort($rows, function($a, $b) {
            $wA = ($a['entry_status'] >= 2 && $a['entry_status'] !== 5) ? 1 : 0;
            $wB = ($b['entry_status'] >= 2 && $b['entry_status'] !== 5) ? 1 : 0;
            if ($wA !== $wB) return $wA - $wB;
            $snA = $a['db_person']['start_number'] ?? $a['knsb']['start_number'] ?? 9999;
            $snB = $b['db_person']['start_number'] ?? $b['knsb']['start_number'] ?? 9999;
            return $snA - $snB;
        });

        // Org-toegevoegde rijders: in DB maar niet in KNSB API (status >= 3)
        $knsbLkSet = array_flip(array_column($rows, 'license_key'));
        foreach ($dbEntries[$dcId] ?? [] as $lk => $entry) {
            if (isset($knsbLkSet[$lk])) continue;          // al verwerkt vanuit KNSB
            if ((int)$entry['status'] < 3) continue;       // alleen org-statussen bewaren
            $dbPerson = $dbPersons[$lk] ?? null;
            $tp1      = $dbTp[$lk][1]  ?? null;
            $tp2      = $dbTp[$lk][2]  ?? null;
            $tpActiefIsset = isset($dbTp[$lk][0]);
            $tpActief = $tpActiefIsset ? $dbTp[$lk][0]['code'] : null;
            $tpExtra  = [];
            foreach ($dbTp[$lk] ?? [] as $slot => $tp) {
                if ($slot >= 3) $tpExtra[] = $tp['code'];
            }
            $rows[] = [
                'license_key'        => $lk,
                'is_anoniem'         => false,
                'knsb_entry_id'      => $entry['knsb_entry_id'] ?? null,
                'knsb_status'        => (int)$entry['status'],
                'entry_status'       => (int)$entry['status'],
                'reserve'            => null,
                'is_new'             => false,
                'diffs'              => [],
                'knsb' => [
                    'start_number' => $dbPerson['start_number'] ?? null,
                    'full_name'    => $dbPerson['full_name']    ?? '',
                    'short_name'   => $dbPerson['short_name']   ?? null,
                    'gender'       => $dbPerson['gender']       ?? null,
                    'category'     => $dbPerson['category']     ?? null,
                    'nationality'  => $dbPerson['nationality']  ?? 'NED',
                    'club_code'    => $dbPerson['club_code']    ?? null,
                    'club_short'   => $dbPerson['club_short']   ?? null,
                    'club_full'    => $dbPerson['club_full']    ?? null,
                    'city'         => $dbPerson['city']         ?? null,
                    'transponder1' => $tp1 ? $tp1['code'] : null,
                    'transponder2' => $tp2 ? $tp2['code'] : null,
                ],
                'db_person'          => $dbPerson,
                'db_entry'           => $entry,
                'db_tp1'             => $tp1,
                'db_tp2'             => $tp2,
                'db_tp_extra'        => $tpExtra,
                'db_tp_actief'       => $tpActief,
                'db_tp_actief_isset' => $tpActiefIsset,
            ];
        }

        $result[] = [
            'dc_id'           => $dcId,
            'dc_name'         => $groep['name']             ?? '',
            'dc_number'       => $groep['number']           ?? 0,
            'merge_group'     => $mergeGroups[$dcId]        ?? null,
            'merge_label'     => $mergeLabels[$dcId]        ?? null,
            'splits'          => $splitConfig[$dcId]        ?? [],
            'category_filter' => $catFilters[$dcId]         ?? null,
            'has_distances'   => !empty($groep['distances']),
            'knsb_distances'  => array_map(fn($d) => [
                'id'           => null,
                'name'         => $d['name']        ?? '',
                'number'       => $d['number']       ?? 0,
                'value_meters' => $d['valueMeters']  ?? null,
            ], $groep['distances'] ?? []),
            'competitors' => $rows,
        ];
    }

    usort($result, fn($a, $b) => $a['dc_number'] - $b['dc_number']);

    // Stand-datums:
    // knsb_stand: null → JS genereert lokale browsertijd zodat tijdzone correct is
    // db_stand:   ruwe UTC datetime string → JS parseert met 'Z'-suffix naar lokale tijd
    $knsb_stand = null;
    $db_stand   = null;
    $dbStandRow = $pdo->prepare(
        "SELECT DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i') FROM competitions WHERE id = ?"
    );
    $dbStandRow->execute([$compId]);
    $db_stand = $dbStandRow->fetchColumn() ?: null;

    // Haal versienummers op voor optimistic locking
    $vStmt = $pdo->prepare("SELECT entries_version, tijdschema_version FROM competitions WHERE id = ?");
    $vStmt->execute([$compId]);
    $vers = $vStmt->fetch(PDO::FETCH_ASSOC);
    $entriesVersion = (int)($vers['entries_version'] ?? 0);

    // Check of er al een tijdschema/programma is (blokkeert DC-beheer wijzigingen)
    $progStmt = $pdo->prepare("
        SELECT COUNT(*) FROM tijdschema_cat_config tcc
        JOIN competition_tijdschema ct ON ct.id = tcc.tijdschema_id
        WHERE ct.competition_id = ?
    ");
    $progStmt->execute([$compId]);
    $heeftProgramma = (int)$progStmt->fetchColumn() > 0;

    // Org-transponders laden (voor opzoek in import-module)
    $orgTransponders = [];
    if ($organisatie && !empty($organisatie['id'])) {
        $otStmt = $pdo->prepare("
            SELECT intern_nummer, transponder_code, toegewezen_snr, toegewezen_naam, person_license, categorie, betaald
            FROM organisatie_transponders
            WHERE organisatie_id = ?
            ORDER BY CAST(intern_nummer AS UNSIGNED), intern_nummer
        ");
        $otStmt->execute([$organisatie['id']]);
        $orgTransponders = $otStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'groepen'            => $result,
        'organisatie'        => $organisatie,
        'knsb_stand'         => $knsb_stand,
        'db_stand'           => $db_stand,
        'entries_version'    => $entriesVersion,
        'heeft_programma'    => $heeftProgramma,
        'org_transponders'   => $orgTransponders,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
