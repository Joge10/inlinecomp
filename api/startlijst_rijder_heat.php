<?php
// ============================================================
//  InlineComp – verplaats of voeg toe rijder aan heat
//
//  POST /api/startlijst_rijder_heat.php
//  Body (JSON):
//    competition_id  – UUID wedstrijd
//    person_license  – licentienummer rijder
//    dc_id           – primary distance_combination_id
//    distance_id     – UUID afstand ('' voor afstandsloos)
//    split_group     – split-groepnaam ('' als geen split)
//    ronde           – rondenummer (1 = series)
//    heat_nr         – doelstelling heat-nummer (null = verwijderen uit heat)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$user = requireAuth($pdo);
if (!kanSchrijven($user, 'startlijsten')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor startlijsten.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$compId     = trim($body['competition_id'] ?? '');
$license    = trim($body['person_license'] ?? '');
$dcId       = trim($body['dc_id']         ?? '');
$distId     = trim($body['distance_id']   ?? '') ?: null;
$splitGroup = trim($body['split_group']   ?? '') ?: null;
$ronde      = max(1, (int)($body['ronde'] ?? 1));
$rondeType  = trim($body['ronde_type']    ?? '') ?: null;  // optioneel: voor A/B-finale onderscheid
$heatNr     = (isset($body['heat_nr']) && $body['heat_nr'] !== '' && $body['heat_nr'] !== null)
    ? (int)$body['heat_nr'] : null;

if (!$compId || !$license || !$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'Verplichte velden ontbreken']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Verwijder huidige heat-entry voor deze rijder in deze ronde
    $pdo->prepare("
        DELETE he FROM heat_entries he
        JOIN heats h ON h.id = he.heat_id
        WHERE h.competition_id          = ?
          AND h.distance_combination_id = ?
          AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? IS NULL))
          AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
          AND h.ronde         = ?
          AND he.person_license = ?
    ")->execute([$compId, $dcId, $distId, $distId, $splitGroup, $splitGroup, $ronde, $license]);

    if ($heatNr !== null) {
        // Zoek de doelstelling heat op.
        // Als ronde_type meegegeven is (bijv. 'finale_a' vs 'finale_b'), gebruik dan de JOIN
        // op tijdschema_ritten om heats met hetzelfde ronde-nummer maar ander type te onderscheiden.
        if ($rondeType) {
            $heatStmt = $pdo->prepare("
                SELECT h.id, h.heat_naam
                FROM heats h
                JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
                WHERE h.competition_id          = ?
                  AND h.distance_combination_id = ?
                  AND (h.distance_id = ? OR (h.distance_id IS NULL AND ? IS NULL))
                  AND (h.split_group = ? OR (h.split_group IS NULL AND ? IS NULL))
                  AND h.ronde    = ?
                  AND h.heat_nr  = ?
                  AND tsr.ronde_type = ?
            ");
            $heatStmt->execute([$compId, $dcId, $distId, $distId, $splitGroup, $splitGroup, $ronde, $heatNr, $rondeType]);
        } else {
            $heatStmt = $pdo->prepare("
                SELECT id, heat_naam
                FROM heats
                WHERE competition_id          = ?
                  AND distance_combination_id = ?
                  AND (distance_id = ? OR (distance_id IS NULL AND ? IS NULL))
                  AND (split_group = ? OR (split_group IS NULL AND ? IS NULL))
                  AND ronde   = ?
                  AND heat_nr = ?
            ");
            $heatStmt->execute([$compId, $dcId, $distId, $distId, $splitGroup, $splitGroup, $ronde, $heatNr]);
        }
        $heat = $heatStmt->fetch(PDO::FETCH_ASSOC);

        if (!$heat) {
            $pdo->rollBack();
            echo json_encode(['error' => "Heat {$heatNr} bestaat niet voor deze categorie/afstand"]);
            exit;
        }

        // Volgende startpositie in de doelstelling heat
        $posStmt = $pdo->prepare("
            SELECT COALESCE(MAX(startpositie), 0) + 1 FROM heat_entries WHERE heat_id = ?
        ");
        $posStmt->execute([$heat['id']]);
        $startPos = (int)$posStmt->fetchColumn();

        // Persoonsinformatie
        $pStmt = $pdo->prepare("SELECT start_number, category FROM persons WHERE license_key = ?");
        $pStmt->execute([$license]);
        $persoon = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdo->prepare("
            INSERT INTO heat_entries (heat_id, person_license, categorie, startpositie, startnummer)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $heat['id'],
            $license,
            $persoon['category']     ?? null,
            $startPos,
            $persoon['start_number'] ?? null,
        ]);

        $pdo->commit();
        echo json_encode([
            'ok'           => true,
            'heat_naam'    => $heat['heat_naam'],
            'startpositie' => $startPos,
        ]);
    } else {
        // Alleen verwijderen (heat_nr = null)
        $pdo->commit();
        echo json_encode(['ok' => true, 'verwijderd' => true]);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
