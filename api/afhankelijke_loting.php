<?php
// ============================================================
//  InlineComp – afhankelijke lotingen (CRUD + trigger-lookup)
//
//  Beheert de tabel `afhankelijke_loting`: welke DOEL-DC wordt automatisch
//  geloot op de uitslag van welke BRON-DC. De daadwerkelijke generatie doet
//  api/startlijst_genereer.php (methode=afstand_uitslag); dit endpoint legt
//  alleen de koppeling vast en levert 'm op.
//
//  Acties:
//    GET  ?action=lijst&competition_id=UUID
//         → { rijen: [ {id, doel_dc_id, doel_dc_naam, bron_dc_id,
//                       bron_dc_naam, methode, ...}, ... ] }
//    GET  ?action=voor_bron&competition_id=UUID&bron_dc_id=UUID
//         → { doelen: [ {id, doel_dc_id, doel_dc_naam, methode,
//                        bron_dc_id, bron_distance_id, max_per_heat}, ... ] }
//         Gebruikt door de uitslag-bevestiging om te weten of er een
//         afhankelijke loting op deze bron wacht.
//    POST action=set      { competition_id, doel_dc_id, doel_distance_id?,
//                           methode?, bron_dc_id, bron_distance_id?, max_per_heat? }
//    POST action=verwijder{ competition_id, doel_dc_id, doel_distance_id? }  (of {id})
//
//  Schrijf-acties vereisen startlijsten-schrijfrecht.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// Alleen 'afstand_uitslag' in de MVP. De kolom kan later meer aan
// (bv. 'tussenklassement'); breid deze whitelist dan uit én de trigger.
$GELDIGE_METHODES = ['afstand_uitslag'];

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$body   = $isPost ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$action = trim($_GET['action'] ?? $body['action'] ?? '');

// ── Helper: hoort DC bij deze wedstrijd? ────────────────────────────────────
function _alDcHoortBij(PDO $pdo, string $dcId, string $compId): bool {
    $s = $pdo->prepare(
        "SELECT 1 FROM distance_combinations WHERE id = ? AND competition_id = ? LIMIT 1"
    );
    $s->execute([$dcId, $compId]);
    return (bool)$s->fetchColumn();
}

// ── Helper: leidt de generatie-parameters voor de EERSTE ronde van een
// doel-DC af uit het tijdschema (tijdschema_ritten). Levert exact wat
// api/startlijst_genereer.php nodig heeft, zodat de auto-trigger geen
// startlist.js-interne cache hoeft na te bouwen. NULL als er (nog) geen
// tijdschema-ritten voor deze DC zijn (schema niet compleet).
function _alGenParams(PDO $pdo, string $compId, string $doelDc): ?array {
    $s = $pdo->prepare("
        SELECT tr.ronde_type, tr.distance_id, tr.heat_nr, tr.rit_naam, tr.volgorde
        FROM tijdschema_ritten tr
        JOIN competition_tijdschema ct ON ct.id = tr.tijdschema_id
        WHERE ct.competition_id = ? AND tr.dc_id = ?
        ORDER BY tr.volgorde, tr.heat_nr
    ");
    $s->execute([$compId, $doelDc]);
    $ritten = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$ritten) return null;
    // Eerste ronde = de ronde met de laagste volgorde (bovenaan na ORDER BY).
    $eersteRonde = $ritten[0]['ronde_type'];
    $rondeRitten = array_values(array_filter(
        $ritten, fn($r) => $r['ronde_type'] === $eersteRonde
    ));
    $heatNamen = [];
    foreach ($rondeRitten as $r) $heatNamen[(string)(int)$r['heat_nr']] = $r['rit_naam'];
    $cs = $pdo->prepare("SELECT category_filter FROM distance_combinations WHERE id = ?");
    $cs->execute([$doelDc]);
    return [
        'dc_ids'          => $doelDc,
        'ronde_type'      => $eersteRonde,
        'distance_id'     => (string)($rondeRitten[0]['distance_id'] ?? ''),
        'heats_aantal'    => count($rondeRitten),
        'category_filter' => (string)($cs->fetchColumn() ?: ''),
        'heat_namen'      => $heatNamen,
    ];
}

// ── Helper: heeft een doel-DC al gereden resultaten? Zo ja, mag de
// auto-trigger de loting NIET opnieuw genereren (zou geraceerde heats
// overschrijven — bv. als de operator de bron-uitslag achteraf corrigeert).
function _alDoelHeeftResultaten(PDO $pdo, string $compId, string $doelDc): bool {
    $s = $pdo->prepare("
        SELECT 1
        FROM results       res
        JOIN heat_entries  he ON he.id = res.heat_entry_id
        JOIN heats         h  ON h.id  = he.heat_id
        WHERE h.competition_id = ? AND h.distance_combination_id = ?
        LIMIT 1
    ");
    $s->execute([$compId, $doelDc]);
    return (bool)$s->fetchColumn();
}

// ── Helper: heeft een doel-DC al een loting (heats)? Zo ja, dan wordt die
// bij het (her)genereren OVERSCHREVEN — nuttig om de operator op te wijzen
// dat er evt. nieuwe startlijsten geprint moeten worden.
function _alDoelHeeftLoting(PDO $pdo, string $compId, string $doelDc): bool {
    $s = $pdo->prepare("
        SELECT 1 FROM heats
        WHERE competition_id = ? AND distance_combination_id = ? LIMIT 1
    ");
    $s->execute([$compId, $doelDc]);
    return (bool)$s->fetchColumn();
}

try {
    // ── READ: lijst ─────────────────────────────────────────────────────────
    if ($action === 'lijst') {
        $compId = trim($_GET['competition_id'] ?? $body['competition_id'] ?? '');
        if ($compId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id verplicht']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT al.id, al.doel_dc_id, al.doel_distance_id, al.methode,
                   al.bron_dc_id, al.bron_distance_id, al.max_per_heat,
                   dcd.name AS doel_dc_naam,
                   dcb.name AS bron_dc_naam
            FROM afhankelijke_loting al
            LEFT JOIN distance_combinations dcd ON dcd.id = al.doel_dc_id
            LEFT JOIN distance_combinations dcb ON dcb.id = al.bron_dc_id
            WHERE al.competition_id = ?
            ORDER BY dcd.number, dcd.name
        ");
        $stmt->execute([$compId]);
        echo json_encode(['rijen' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── READ: dcs (alle DC's van de wedstrijd, voor de bron-keuze) ──────────
    // Los van uitslag_bronnen.php (die alleen result-dragende afstanden toont):
    // een afhankelijkheid stel je vooraf in, vóór de bron gereden is.
    if ($action === 'dcs') {
        $compId = trim($_GET['competition_id'] ?? $body['competition_id'] ?? '');
        if ($compId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id verplicht']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT id, name, number
            FROM distance_combinations
            WHERE competition_id = ?
            ORDER BY number, name
        ");
        $stmt->execute([$compId]);
        echo json_encode(['dcs' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── READ: bron_ranking (namen in seeding-volgorde, voor de preview) ─────
    // Alleen voor de BEHEER-preview op het loting-scherm. Wordt nergens
    // opgeslagen of gepubliceerd — puur om de operator te tonen hoe de heats
    // komen te liggen. Zelfde volgorde-logica als methode 'afstand_uitslag'
    // in startlijst_genereer.php (rang asc, uitsluitende sancties achteraan).
    if ($action === 'bron_ranking') {
        $compId = trim($_GET['competition_id'] ?? '');
        $dcId   = trim($_GET['dc_id']          ?? '');
        $distId = trim($_GET['distance_id']    ?? '');
        if ($compId === '' || $dcId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id en dc_id verplicht']);
            exit;
        }
        $distWhere = $distId !== '' ? 'AND ua.distance_id = ?' : '';
        $params    = $distId !== '' ? [$compId, $dcId, $distId] : [$compId, $dcId];
        $stmt = $pdo->prepare("
            SELECT p.full_name,
                   MIN(COALESCE(ua.rang, 9999)) AS beste_rang,
                   MAX(CASE WHEN ua.sanctie IN ('DQ-SF','DQ-DF') THEN 1 ELSE 0 END) AS uitgesloten
            FROM uitslag_afstand ua
            LEFT JOIN persons p ON p.license_key = ua.person_license
            WHERE ua.competition_id = ? AND ua.distance_combination_id = ? {$distWhere}
            GROUP BY ua.person_license, p.full_name
            ORDER BY uitgesloten ASC, beste_rang ASC
        ");
        $stmt->execute($params);
        $namen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['uitgesloten']) continue;   // uitgesloten → niet in seeding
            $namen[] = $r['full_name'] ?? '(onbekend)';
        }
        echo json_encode(['namen' => $namen], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── READ: voor_bron (trigger-lookup) ────────────────────────────────────
    if ($action === 'voor_bron') {
        $compId  = trim($_GET['competition_id'] ?? '');
        $bronDc  = trim($_GET['bron_dc_id']     ?? '');
        if ($compId === '' || $bronDc === '') {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id en bron_dc_id verplicht']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT al.id, al.doel_dc_id, al.doel_distance_id, al.methode,
                   al.bron_dc_id, al.bron_distance_id, al.max_per_heat,
                   dcd.name AS doel_dc_naam
            FROM afhankelijke_loting al
            LEFT JOIN distance_combinations dcd ON dcd.id = al.doel_dc_id
            WHERE al.competition_id = ? AND al.bron_dc_id = ?
            ORDER BY dcd.number, dcd.name
        ");
        $stmt->execute([$compId, $bronDc]);
        $doelen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Verrijk elk doel met de afgeleide generatie-params + veiligheidsvlag.
        foreach ($doelen as &$d) {
            $d['gen']              = _alGenParams($pdo, $compId, $d['doel_dc_id']);
            $d['heeft_resultaten'] = _alDoelHeeftResultaten($pdo, $compId, $d['doel_dc_id']);
            $d['heeft_loting']     = _alDoelHeeftLoting($pdo, $compId, $d['doel_dc_id']);
        }
        unset($d);
        echo json_encode(['doelen' => $doelen], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Vanaf hier: schrijf-acties.
    if (!$isPost) {
        http_response_code(405);
        echo json_encode(['error' => 'Gebruik POST voor set/verwijder']);
        exit;
    }
    if (!kanSchrijven($_authUser, 'startlijsten')) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen schrijfrechten voor startlijsten.']);
        exit;
    }

    $compId = trim($body['competition_id'] ?? '');
    if ($compId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id verplicht']);
        exit;
    }

    // ── WRITE: verwijder ────────────────────────────────────────────────────
    if ($action === 'verwijder') {
        $id = isset($body['id']) ? (int)$body['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare(
                "DELETE FROM afhankelijke_loting WHERE id = ? AND competition_id = ?"
            );
            $stmt->execute([$id, $compId]);
        } else {
            $doelDc   = trim($body['doel_dc_id']       ?? '');
            $doelDist = trim($body['doel_distance_id'] ?? '');
            if ($doelDc === '') {
                http_response_code(400);
                echo json_encode(['error' => 'id of doel_dc_id verplicht']);
                exit;
            }
            $stmt = $pdo->prepare(
                "DELETE FROM afhankelijke_loting
                  WHERE competition_id = ? AND doel_dc_id = ? AND doel_distance_id = ?"
            );
            $stmt->execute([$compId, $doelDc, $doelDist]);
        }
        echo json_encode(['ok' => true, 'verwijderd' => $stmt->rowCount()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── WRITE: set (upsert) ─────────────────────────────────────────────────
    if ($action === 'set') {
        $doelDc   = trim($body['doel_dc_id']       ?? '');
        $doelDist = trim($body['doel_distance_id'] ?? '');
        $bronDc   = trim($body['bron_dc_id']       ?? '');
        $bronDist = trim($body['bron_distance_id'] ?? '');
        $methode  = trim($body['methode']          ?? 'afstand_uitslag');
        $maxPer   = isset($body['max_per_heat']) && $body['max_per_heat'] !== ''
            ? (int)$body['max_per_heat'] : null;

        if ($doelDc === '' || $bronDc === '') {
            http_response_code(400);
            echo json_encode(['error' => 'doel_dc_id en bron_dc_id verplicht']);
            exit;
        }
        if (!in_array($methode, $GELDIGE_METHODES, true)) {
            http_response_code(400);
            echo json_encode(['error' => "Methode '$methode' wordt (nog) niet ondersteund"]);
            exit;
        }
        if ($doelDc === $bronDc) {
            http_response_code(409);
            echo json_encode(['error' => 'Doel-DC en bron-DC mogen niet dezelfde zijn']);
            exit;
        }
        // Beide DC's moeten bij deze wedstrijd horen (voorkomt cross-comp injectie).
        if (!_alDcHoortBij($pdo, $doelDc, $compId) || !_alDcHoortBij($pdo, $bronDc, $compId)) {
            http_response_code(404);
            echo json_encode(['error' => 'Doel- of bron-DC hoort niet bij deze wedstrijd']);
            exit;
        }

        // ── Cyclus-check ────────────────────────────────────────────────────
        // Volg de bron-keten vanaf de nieuwe bron terug; als we het doel weer
        // tegenkomen zou er een kring ontstaan (A→B→…→A). Ketens zijn kort,
        // dus een simpele walk met visited-set volstaat.
        $keten = $pdo->prepare(
            "SELECT bron_dc_id FROM afhankelijke_loting
              WHERE competition_id = ? AND doel_dc_id = ? LIMIT 1"
        );
        $huidige = $bronDc;
        $bezocht = [$doelDc => true];
        $stappen = 0;
        while ($huidige !== '' && $stappen++ < 50) {
            if (isset($bezocht[$huidige])) {
                http_response_code(409);
                echo json_encode(['error' => 'Dit maakt een kringverwijzing tussen de lotingen — niet toegestaan']);
                exit;
            }
            $bezocht[$huidige] = true;
            $keten->execute([$compId, $huidige]);
            $huidige = (string)($keten->fetchColumn() ?: '');
        }

        // Upsert op de UNIQUE (competition_id, doel_dc_id, doel_distance_id).
        $stmt = $pdo->prepare("
            INSERT INTO afhankelijke_loting
                   (competition_id, doel_dc_id, doel_distance_id, methode,
                    bron_dc_id, bron_distance_id, max_per_heat)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                   methode          = VALUES(methode),
                   bron_dc_id       = VALUES(bron_dc_id),
                   bron_distance_id = VALUES(bron_distance_id),
                   max_per_heat     = VALUES(max_per_heat)
        ");
        $stmt->execute([$compId, $doelDc, $doelDist, $methode, $bronDc, $bronDist, $maxPer]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => "Onbekende actie: '$action'"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
