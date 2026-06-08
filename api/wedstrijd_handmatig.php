<?php
// ============================================================
//  InlineComp – Handmatige wedstrijd-aanmaak
//
//  Voor organisaties die InlineComp gebruiken maar niet de KNSB-feed
//  (Vantage). Operator typt zelf wedstrijd-info + categorieën in en
//  krijgt een lege wedstrijd terug. Afstanden voegt hij daarna toe via
//  het bestaande Afstanden-beheer (geen automatische generatie hier
//  — flexibiliteit > convenience voor deze use-case).
//
//  Scope-aware: een gescopte admin mag alleen een wedstrijd aanmaken
//  voor een organisatie waar hij rechten op heeft. De UI filtert al,
//  maar deze backend dwingt het hard af (anti-back-door).
//
//  Genereert geen heat_entries, geen tijdschema_blokken/ritten — dat
//  doet de operator daarna in de bestaande flow.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// Owner / admin / importer — zelfde rollen als SCHRIJF_ROLLEN['importeer']
// in index.php (consistente gate met de Import-knop).
$rol = $_authUser['role'] ?? '';
if (!in_array($rol, ['owner', 'admin', 'importer'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen rechten om wedstrijden aan te maken']);
    exit;
}

function uuid4_w(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$action = $_GET['action'] ?? '';

// ── ACTION: detail ─────────────────────────────────────────────────────────
// Vergelijk.php-compatibele response voor handmatige wedstrijden — vergelijk.php
// zelf fetched KNSB-data en faalt voor handmatige (geen feed-data). Hier
// bouwen we de response uit eigen DB: DCs als 'groepen' met lege competitors,
// organisatie + sponsors, baan, en de meta-velden die de frontend verwacht.
//
// Rijders komen pas in Fase 2 (Excel-import + handmatige + extern=true). Voor
// nu: lege competitors-arrays. Het beheer-panel werkt wel: DC's tonen,
// splitsen/combineren, afstanden toevoegen — alles uit DB.
if ($action === 'detail') {
    $compId = trim($_GET['id'] ?? '');
    if ($compId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'id ontbreekt']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $compId);

    // Wedstrijd ophalen + scope-check via wedstrijd-detail
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.starts, c.ends, c.bron, c.organisatie_id, c.baan_id,
               c.entries_version, c.tijdschema_version
        FROM competitions c
        WHERE c.id = ? AND c.bron = 'handmatig'
        LIMIT 1
    ");
    $stmt->execute([$compId]);
    $comp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp) {
        http_response_code(404);
        echo json_encode(['error' => 'Handmatige wedstrijd niet gevonden']);
        exit;
    }

    // Organisatie + sponsors (kopie van vergelijk.php-shape)
    $organisatie = null;
    if (!empty($comp['organisatie_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM organisaties WHERE id = ?");
        $stmt->execute([$comp['organisatie_id']]);
        $organisatie = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($organisatie) {
            $stmt = $pdo->prepare(
                "SELECT * FROM organisatie_sponsors WHERE organisatie_id = ? ORDER BY volgorde, naam"
            );
            $stmt->execute([$organisatie['id']]);
            $organisatie['sponsors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Baan (optional)
    $baan = null;
    if (!empty($comp['baan_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM banen WHERE id = ?");
        $stmt->execute([$comp['baan_id']]);
        $baan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // DC's (vergelijk-shape: dc_id / dc_name / dc_number / knsb_distances / competitors)
    $stmt = $pdo->prepare("
        SELECT id, name, number, category_filter, merge_group, merge_label
        FROM distance_combinations
        WHERE competition_id = ?
        ORDER BY number, name
    ");
    $stmt->execute([$compId]);
    $dcs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Entries + persons + transponders per DC ophalen
    $dcIds = array_column($dcs, 'id');
    $entriesPerDc = [];
    $personRijen  = [];
    if ($dcIds) {
        $ph = implode(',', array_fill(0, count($dcIds), '?'));
        $stmt = $pdo->prepare("
            SELECT e.distance_combination_id AS dc_id,
                   e.person_license, e.status, e.reserve, e.knsb_entry_id,
                   p.full_name, p.short_name, p.birth_year, p.gender,
                   p.category, p.nationality, p.start_number,
                   p.club_code, p.club_short, p.club_full, p.sponsor, p.city,
                   p.extern, p.extern_federatie
            FROM entries e
            JOIN persons p ON p.license_key = e.person_license
            WHERE e.distance_combination_id IN ($ph)
              AND p.anonymized_at IS NULL
            ORDER BY p.start_number, p.full_name
        ");
        $stmt->execute($dcIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entriesPerDc[$row['dc_id']][] = $row;
            $personRijen[$row['person_license']] = $row;
        }
    }

    // Transponders (slot 0 = actief, 1-2 = KNSB, 3+ = extra)
    $dbTp = [];
    if ($personRijen) {
        $ph = implode(',', array_fill(0, count($personRijen), '?'));
        $stmt = $pdo->prepare("
            SELECT * FROM transponders
            WHERE competition_id = ? AND person_license IN ($ph)
        ");
        $stmt->execute(array_merge([$compId], array_keys($personRijen)));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $dbTp[$t['person_license']][(int)$t['slot']] = $t;
        }
    }

    // Fallback voor handmatige wedstrijden: rijders die NIET (of slechts
    // gedeeltelijk) een transponder voor deze wedstrijd hebben, krijgen hun
    // laatste-bekende transponders uit eerdere wedstrijden ingeladen. Zo zien
    // operator + speakerlijst meteen de transponders die de rijder gewoonlijk
    // gebruikt — operator kan ze in Beheer overschrijven indien nodig.
    // Geen DB-write hier: puur read-only fallback. De CSV-import-commit
    // (api/csv_import.php) zou ze idealiter direct kopiëren voor persistentie;
    // deze fallback dekt bestaande imports + de tussentijd.
    if ($personRijen) {
        $ph = implode(',', array_fill(0, count($personRijen), '?'));
        $stmt = $pdo->prepare("
            SELECT t1.person_license, t1.slot, t1.code, t1.source, t1.updated_at
            FROM transponders t1
            WHERE t1.person_license IN ($ph)
              AND t1.id = (
                  SELECT MAX(t2.id) FROM transponders t2
                  WHERE t2.person_license = t1.person_license
                    AND t2.slot           = t1.slot
              )
        ");
        $stmt->execute(array_keys($personRijen));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $lk   = $t['person_license'];
            $slot = (int)$t['slot'];
            // Alleen toevoegen als deze (person, slot) NIET al voor deze
            // wedstrijd specifiek is gezet — eigen TPs winnen altijd.
            if (!isset($dbTp[$lk][$slot])) {
                $dbTp[$lk][$slot] = $t;
            }
        }
    }

    // Bouw competitors per DC in vergelijk.php-compatibele shape (org-
    // toegevoegde-stijl: alle data uit eigen DB, geen KNSB-feed-diff).
    $bouwCompetitor = function(array $row) use ($dbTp) {
        $lk  = $row['person_license'];
        $tps = $dbTp[$lk] ?? [];
        $tp0 = $tps[0] ?? null;
        $tp1 = $tps[1] ?? null;
        $tp2 = $tps[2] ?? null;
        $tpExtra = [];
        foreach ($tps as $slot => $tp) {
            if ($slot >= 3) $tpExtra[] = $tp['code'];
        }
        // db_person = volledige rij voor frontend (gebruikt o.a. start_number)
        $dbPerson = [
            'license_key'  => $lk,
            'full_name'    => $row['full_name'],
            'short_name'   => $row['short_name'],
            'birth_year'   => $row['birth_year'] !== null ? (int)$row['birth_year'] : null,
            'gender'       => $row['gender'] !== null ? (int)$row['gender'] : null,
            'category'     => $row['category'],
            'nationality'  => $row['nationality'] ?: 'NED',
            'start_number' => $row['start_number'] !== null ? (int)$row['start_number'] : null,
            'club_code'    => $row['club_code'],
            'club_short'   => $row['club_short'],
            'club_full'    => $row['club_full'],
            'sponsor'      => $row['sponsor'],
            'city'         => $row['city'],
            'extern'       => (int)($row['extern'] ?? 0) === 1,
        ];
        return [
            'license_key'        => $lk,
            'is_anoniem'         => false,
            'knsb_entry_id'      => $row['knsb_entry_id'] ?? null,
            'knsb_status'        => (int)$row['status'],
            'entry_status'       => (int)$row['status'],
            'reserve'            => $row['reserve'] !== null ? (int)$row['reserve'] : null,
            'is_new'             => false,
            'diffs'              => [],
            // 'knsb'-blok bevat voor handmatig dezelfde data als db_person
            // (geen aparte KNSB-bron om mee te vergelijken).
            'knsb' => [
                'start_number' => $dbPerson['start_number'],
                'full_name'    => $dbPerson['full_name'],
                'short_name'   => $dbPerson['short_name'],
                'gender'       => $dbPerson['gender'],
                'category'     => $dbPerson['category'],
                'nationality'  => $dbPerson['nationality'],
                'club_code'    => $dbPerson['club_code'],
                'club_short'   => $dbPerson['club_short'],
                'club_full'    => $dbPerson['club_full'],
                'city'         => $dbPerson['city'],
                'transponder1' => $tp1 ? $tp1['code'] : null,
                'transponder2' => $tp2 ? $tp2['code'] : null,
            ],
            'db_person'          => $dbPerson,
            'db_entry'           => [
                'status'  => (int)$row['status'],
                'reserve' => $row['reserve'],
            ],
            'db_tp1'             => $tp1,
            'db_tp2'             => $tp2,
            'db_tp_extra'        => $tpExtra,
            'db_tp_actief'       => $tp0 ? $tp0['code'] : null,
            'db_tp_actief_isset' => $tp0 !== null,
        ];
    };

    $groepen = array_map(function($dc) use ($entriesPerDc, $bouwCompetitor) {
        $competitors = array_map($bouwCompetitor, $entriesPerDc[$dc['id']] ?? []);
        return [
            'dc_id'           => $dc['id'],
            'dc_name'         => $dc['name'],
            'dc_number'       => (int)($dc['number'] ?? 0),
            'category_filter' => $dc['category_filter'],
            'merge_group'     => $dc['merge_group'],
            'merge_label'     => $dc['merge_label'],
            'knsb_distances'  => [],   // geen KNSB-bron, afstanden komen uit afstanden-beheer
            'competitors'     => $competitors,
        ];
    }, $dcs);

    // Heeft de wedstrijd al een tijdschema (= programma gegenereerd)?
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM heats WHERE competition_id = ?");
    $stmt->execute([$compId]);
    $heeftProgramma = ((int)$stmt->fetchColumn() > 0);

    echo json_encode([
        'groepen'           => $groepen,
        'organisatie'       => $organisatie,
        'baan'              => $baan,
        'imported'          => true,            // handmatige wedstrijd staat in DB → beheer-panel werkt
        'is_handmatig'      => true,
        'heeft_programma'   => $heeftProgramma,
        'org_transponders'  => [],              // pas relevant als er rijders zijn
        'entries_version'   => (int)$comp['entries_version'],
        'knsb_stand'        => null,            // geen KNSB-feed
        'db_stand'          => null,
    ]);
    exit;
}

// ── ACTION: lijst ──────────────────────────────────────────────────────────
// Geeft scope-gefilterde handmatige wedstrijden terug in een formaat dat
// compatibel is met de KNSB-feed (api/competitions.php). De frontend kan ze
// daardoor 1:1 mergen in allWedstrijden zonder aparte renderer. Het `bron`
// veld + `is_handmatig` markering laat de frontend wel een badge tonen.
//
// Filter: alleen bron='handmatig' (KNSB-imports zitten al in de KNSB-feed-
// proxy, geen dubbeling). Scope: alleen orgs waar deze user rechten op heeft.
if ($action === 'lijst') {
    $scope = gebruikerOrgScope($pdo, $_authUser);

    $sql = "
        SELECT c.id, c.name, c.starts, c.ends, c.location, c.venue_name, c.venue_city,
               c.discipline, c.bron, c.organisatie_id,
               o.naam AS org_naam
        FROM competitions c
        LEFT JOIN organisaties o ON o.id = c.organisatie_id
        WHERE c.bron = 'handmatig'
    ";
    $params = [];
    if ($scope !== null) {
        if (empty($scope)) {
            echo json_encode([]); exit;
        }
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $sql   .= " AND c.organisatie_id IN ($ph)";
        $params = $scope;
    }
    $sql .= " ORDER BY c.starts ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rijen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Vorm om naar KNSB-feed-compatibel formaat: organizer.name voor
    // getOrganisatieNaam(), venue.address.city voor getLocatie(),
    // discipline-string die op 'SpeedSkating.Inline' matcht.
    $out = array_map(function($r) {
        return [
            'id'         => $r['id'],
            'name'       => $r['name'],
            'starts'     => $r['starts'],
            'ends'       => $r['ends'],
            'discipline' => 'SpeedSkating.Inline',
            'venue'      => [
                'name'    => $r['venue_name'] ?? null,
                'address' => ['city' => $r['venue_city'] ?? null],
            ],
            'organizer'    => ['name' => $r['org_naam'] ?? ''],
            'is_handmatig' => true,
            'bron'         => $r['bron'],
        ];
    }, $rijen);

    echo json_encode($out);
    exit;
}

// ── ACTION: orgs_voor_create ───────────────────────────────────────────────
// Lijst van organisaties waar deze user een nieuwe wedstrijd voor mag
// aanmaken. Owners zien alles, gescopte admins alleen hun eigen orgs.
// Wordt in de modal-dropdown getoond als verplicht eerste keuze.
if ($action === 'orgs_voor_create') {
    $scope = gebruikerOrgScope($pdo, $_authUser);
    if ($scope === null) {
        // owner of unscoped admin → alle orgs
        $stmt = $pdo->prepare("SELECT id, naam FROM organisaties ORDER BY naam");
        $stmt->execute();
    } elseif (empty($scope)) {
        // scoped admin met 0 orgs → niets
        echo json_encode([]);
        exit;
    } else {
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $stmt = $pdo->prepare("SELECT id, naam FROM organisaties WHERE id IN ($ph) ORDER BY naam");
        $stmt->execute($scope);
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── ACTION: create ─────────────────────────────────────────────────────────
// Maak nieuwe wedstrijd + categorieën aan. Geen afstanden — die voegt de
// operator daarna toe via Afstanden-beheer.
if ($action === 'create') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige JSON-body']);
        exit;
    }

    // ── Validatie velden ──────────────────────────────────────────────────
    $orgId  = trim($body['organisatie_id'] ?? '');
    $naam   = trim($body['naam'] ?? '');
    $starts = trim($body['starts'] ?? '');   // YYYY-MM-DD
    $ends   = trim($body['ends']   ?? '');   // YYYY-MM-DD (optional)
    $location   = trim($body['location']   ?? '');
    $venueName  = trim($body['venue_name']  ?? '');
    $venueCity  = trim($body['venue_city']  ?? '');
    $dcs        = is_array($body['dcs'] ?? null) ? $body['dcs'] : [];

    if ($orgId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Organisatie is verplicht']);
        exit;
    }
    if ($naam === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Wedstrijd-naam is verplicht']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts)) {
        http_response_code(400);
        echo json_encode(['error' => 'Start-datum is verplicht (YYYY-MM-DD)']);
        exit;
    }
    if ($ends !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends)) {
        http_response_code(400);
        echo json_encode(['error' => 'Eind-datum ongeldig (YYYY-MM-DD)']);
        exit;
    }
    if (empty($dcs)) {
        http_response_code(400);
        echo json_encode(['error' => 'Minimaal 1 categorie verplicht']);
        exit;
    }

    // ── Scope-check: heeft user rechten op de gekozen organisatie? ────────
    $scope = gebruikerOrgScope($pdo, $_authUser);
    if ($scope !== null && !in_array($orgId, $scope, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen rechten op deze organisatie']);
        exit;
    }

    // ── Organisatie bestaat? ──────────────────────────────────────────────
    $orgChk = $pdo->prepare("SELECT id FROM organisaties WHERE id = ?");
    $orgChk->execute([$orgId]);
    if (!$orgChk->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'Organisatie niet gevonden']);
        exit;
    }

    // ── DCs valideren ─────────────────────────────────────────────────────
    // Per DC: naam (vrij, verplicht) + category_filter (CSV van KNSB-codes,
    // optional — leeg betekent "alle categorieën", consistent met KNSB-feed).
    foreach ($dcs as $i => $dc) {
        if (!is_array($dc) || trim($dc['name'] ?? '') === '') {
            http_response_code(400);
            echo json_encode(['error' => "Categorie #" . ($i+1) . " mist een naam"]);
            exit;
        }
    }

    // ── INSERTs in transactie ─────────────────────────────────────────────
    // starts/ends als DATETIME: standaard 09:00 - 18:00, kan operator later
    // verfijnen via Beheer-tab (die heeft al tijd-velden).
    $compId      = uuid4_w();
    $startsDt    = $starts . ' 09:00:00';
    $endsDt      = ($ends !== '' ? $ends : $starts) . ' 18:00:00';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO competitions
                   (id, name, starts, ends, location, venue_name, venue_city,
                    discipline, bron, organisatie_id,
                    public_zichtbaar, public_aankondigen)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'inline-skating', 'handmatig', ?, 0, 0)
        ");
        $stmt->execute([
            $compId, $naam, $startsDt, $endsDt,
            $location !== '' ? $location : null,
            $venueName !== '' ? $venueName : null,
            $venueCity !== '' ? $venueCity : null,
            $orgId,
        ]);

        // DCs in volgorde van invoer. number = 1-based volgorde.
        $insDc = $pdo->prepare("
            INSERT INTO distance_combinations
                   (id, competition_id, number, name, category_filter)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($dcs as $i => $dc) {
            $catFilter = trim($dc['category_filter'] ?? '');
            $insDc->execute([
                uuid4_w(),
                $compId,
                $i + 1,
                trim($dc['name']),
                $catFilter !== '' ? $catFilter : null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'DB-fout: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success'        => true,
        'competition_id' => $compId,
        'aantal_dcs'     => count($dcs),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende action']);
