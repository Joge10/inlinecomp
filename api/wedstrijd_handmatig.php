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
    $sql .= " ORDER BY c.starts DESC";

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
