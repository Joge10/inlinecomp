<?php
// ============================================================
//  InlineComp – System helpers (admin-only opruim/diagnostiek-tools)
//
//  POST /api/helpers.php
//    {action: "scan_wees_uitslagen"}           → diagnostiek (geen schrijfacties)
//    {action: "cleanup_wees_uitslagen", scope: "all" | "<comp_id>"}
//
//  "Wees-uitslagen" = rijen in uitslag_afstand of uitslag_klassement waar
//  geen heats meer onder zitten — typisch gevolg van wis-loting zonder de
//  archief-uitslag mee te verwijderen (komt voor bij oudere wedstrijden
//  vóór de nieuwe wis-dialog).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo, ['owner', 'admin']);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── Scan: rapport per wedstrijd ─────────────────────────────────────────────
if ($action === 'scan_wees_uitslagen') {
    try {
        // BELANGRIJK: alleen wees-rijen waar de wedstrijd nog bestaat. Als de
        // wedstrijd zelf is verwijderd (uit competitions-tabel), is de
        // uitslag-rij sport-archief en MOET die blijven staan — daarom
        // bewust GEEN cascade op competition_id in uitslag_afstand/_klassement.
        // De INNER JOIN op competitions filtert die archief-rijen weg.
        //
        // Twee soorten 'wees' worden gevangen:
        //   (1) Geen heats meer voor deze cat+afstand+split → loting compleet
        //       weg, uitslag is een orphan.
        //   (2) Heats bestaan wél, maar deze rijder zit in geen enkel
        //       heat_entry → fantoom uit een vorige loting (rijder die uit
        //       de nieuwe indeling is gevallen, met stale rang/tijd uit de
        //       oude run). Detectie: NOT EXISTS op person-niveau.
        $uaStmt = $pdo->query("
            SELECT
                ua.id,
                ua.competition_id,
                ua.competition_naam,
                ua.competition_datum,
                ua.dc_naam,
                ua.distance_naam,
                ua.split_group,
                ua.person_license,
                ua.rang,
                ua.tijd_ms,
                ua.sanctie,
                ua.vastgelegd_at,
                COALESCE(p.full_name, ua.person_license) AS naam
            FROM uitslag_afstand ua
            JOIN competitions c ON c.id = ua.competition_id
            LEFT JOIN persons p ON p.license_key = ua.person_license
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = ua.competition_id
                  AND h.distance_combination_id = ua.distance_combination_id
                  AND (h.distance_id = ua.distance_id OR (h.distance_id IS NULL AND ua.distance_id = ''))
                  AND (h.split_group = ua.split_group OR (h.split_group IS NULL AND ua.split_group = ''))
                  AND he.person_license = ua.person_license
            )
            ORDER BY ua.competition_datum DESC, ua.competition_naam,
                     ua.dc_naam, ua.distance_naam, ua.rang
        ");
        $weesUitslag = $uaStmt->fetchAll(PDO::FETCH_ASSOC);

        // Wees uitslag_klassement: zelfde person-niveau-detectie
        $ukStmt = $pdo->query("
            SELECT
                uk.id,
                uk.competition_id,
                uk.competition_naam,
                uk.competition_datum,
                uk.dc_naam,
                uk.split_group,
                uk.person_license,
                uk.rang,
                uk.punten_totaal,
                uk.vastgelegd_at,
                COALESCE(p.full_name, uk.person_license) AS naam
            FROM uitslag_klassement uk
            JOIN competitions c ON c.id = uk.competition_id
            LEFT JOIN persons p ON p.license_key = uk.person_license
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = uk.competition_id
                  AND h.distance_combination_id = uk.distance_combination_id
                  AND (h.split_group = uk.split_group OR (h.split_group IS NULL AND uk.split_group = ''))
                  AND he.person_license = uk.person_license
            )
            ORDER BY uk.competition_datum DESC, uk.competition_naam, uk.dc_naam, uk.rang
        ");
        $weesKlassement = $ukStmt->fetchAll(PDO::FETCH_ASSOC);

        // Totalen + uniek aantal wedstrijden geraakt
        $compsRaakt = [];
        foreach ($weesUitslag    as $r) $compsRaakt[$r['competition_id']] = true;
        foreach ($weesKlassement as $r) $compsRaakt[$r['competition_id']] = true;

        echo json_encode([
            'ok'                  => true,
            'wees_uitslag'        => $weesUitslag,
            'wees_klassement'     => $weesKlassement,
            'totaal_uitslag_rij'  => count($weesUitslag),
            'totaal_klas_rij'     => count($weesKlassement),
            'unieke_wedstrijden'  => count($compsRaakt),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ── Cleanup: daadwerkelijk verwijderen ──────────────────────────────────────
if ($action === 'cleanup_wees_uitslagen') {
    $scope = trim($body['scope'] ?? 'all');
    // scope = 'all' → alle wedstrijden, of een specifiek competition_id (UUID)
    if ($scope !== 'all' && !preg_match('/^[a-f0-9\-]{36}$/i', $scope)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige scope (verwacht "all" of een geldig UUID)']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // uitslag_afstand — match person-niveau zodat zowel "geen heats meer"
        // als "fantoom-rijder uit vorige loting" worden opgeruimd. INNER JOIN
        // op competitions beschermt sport-archief (gewiste wedstrijden).
        $sql = "
            DELETE ua FROM uitslag_afstand ua
            JOIN competitions c ON c.id = ua.competition_id
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = ua.competition_id
                  AND h.distance_combination_id = ua.distance_combination_id
                  AND (h.distance_id = ua.distance_id OR (h.distance_id IS NULL AND ua.distance_id = ''))
                  AND (h.split_group = ua.split_group OR (h.split_group IS NULL AND ua.split_group = ''))
                  AND he.person_license = ua.person_license
            )
        ";
        if ($scope !== 'all') $sql .= " AND ua.competition_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope === 'all' ? [] : [$scope]);
        $uaWeg = $stmt->rowCount();

        // uitslag_klassement — zelfde person-niveau-detectie
        $sql = "
            DELETE uk FROM uitslag_klassement uk
            JOIN competitions c ON c.id = uk.competition_id
            WHERE NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = uk.competition_id
                  AND h.distance_combination_id = uk.distance_combination_id
                  AND (h.split_group = uk.split_group OR (h.split_group IS NULL AND uk.split_group = ''))
                  AND he.person_license = uk.person_license
            )
        ";
        if ($scope !== 'all') $sql .= " AND uk.competition_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope === 'all' ? [] : [$scope]);
        $ukWeg = $stmt->rowCount();

        $pdo->commit();
        echo json_encode([
            'ok'                  => true,
            'uitslag_verwijderd'  => $uaWeg,
            'klas_verwijderd'     => $ukWeg,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende action']);
