<?php
// ============================================================
//  InlineComp – reserve inzetten / terugplaatsen
//
//  POST /api/reserve_inzet.php
//  Body: {
//    "competition_id": "<UUID>",
//    "dc_id":          "<UUID>",
//    "person_license": "<licentie>",
//    "actie":          "inzet" | "terug",
//    "reserve_nr":     1               // alleen voor "terug" — KNSB-volgnummer
//                                      // om terug te zetten (frontend stuurt
//                                      // de waarde uit vergelijkData)
//  }
//
//  "inzet": entries.reserve = NULL, reserve_handmatig_ingezet = 1, status = 5
//           (Bevestigd bij organisatie — anders zou de rijder via status 2
//           "aangemeld" alsnog uit de startlijst-filter vallen).
//  "terug": entries.reserve = reserve_nr, reserve_handmatig_ingezet = 0,
//           status onaangeroerd. Bij volgende KNSB-sync mag KNSB de waarde
//           weer overschrijven.
//
//  Vereiste rol: startlijsten-schrijfrecht (operator).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'startlijsten')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor startlijsten.']);
    exit;
}

$body          = json_decode(file_get_contents('php://input'), true) ?? [];
$compId        = trim($body['competition_id'] ?? '');
$dcId          = trim($body['dc_id']          ?? '');
$personLicense = trim($body['person_license'] ?? '');
$actie         = trim($body['actie']          ?? '');
$reserveNr     = isset($body['reserve_nr']) ? (int)$body['reserve_nr'] : null;

if (!$compId || !$dcId || !$personLicense) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id, dc_id en person_license zijn verplicht']);
    exit;
}
if ($actie !== 'inzet' && $actie !== 'terug') {
    http_response_code(400);
    echo json_encode(['error' => "actie moet 'inzet' of 'terug' zijn"]);
    exit;
}

try {
    // Verifieer dat de DC bij déze competition hoort — voorkomt cross-comp
    // injectie via geknutselde body.
    $dcCheck = $pdo->prepare(
        "SELECT 1 FROM distance_combinations
          WHERE id = ? AND competition_id = ? LIMIT 1"
    );
    $dcCheck->execute([$dcId, $compId]);
    if (!$dcCheck->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'DC niet gevonden voor deze wedstrijd']);
        exit;
    }

    // Huidige entry-rij ophalen (nodig voor status-check + return-state)
    $entryStmt = $pdo->prepare("
        SELECT status, reserve, reserve_handmatig_ingezet
        FROM entries
        WHERE distance_combination_id = ? AND person_license = ?
        LIMIT 1
    ");
    $entryStmt->execute([$dcId, $personLicense]);
    $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        http_response_code(404);
        echo json_encode(['error' => 'Geen inschrijving gevonden voor deze rijder']);
        exit;
    }

    if ($actie === 'inzet') {
        // Status-check: alleen 'getekend' (1) mag worden ingezet. Andere
        // statussen (afgemeld, niet getekend, etc.) verbieden inzet — een
        // afwezige rijder kan geen reserve invullen.
        if ((int)$entry['status'] !== 1) {
            http_response_code(409);
            echo json_encode([
                'error'  => 'Reserve kan alleen ingezet worden als status '
                          . 'getekend is. Huidige status: ' . (int)$entry['status'],
                'reden'  => 'status_niet_getekend',
                'status' => (int)$entry['status'],
            ]);
            exit;
        }
        // Geen strikte reserve-check: de UI toont reserves uit de KNSB-feed,
        // maar entries.reserve in DB kan nog NULL zijn (bv. direct na de
        // schema-migratie zonder fresh KNSB-sync). De inzet-actie is
        // idempotent: na deze UPDATE staat reserve=NULL en
        // reserve_handmatig_ingezet=1 — dat is wat we willen, ongeacht of
        // de DB de reserve-waarde al kende.

        // ── Capaciteit-cap ───────────────────────────────────────────────
        // Reserves mogen alleen ingezet worden ter vervanging van iemand die
        // afgemeld of niet getekend staat. Het totaal in de startlijst-loting
        // mag het oorspronkelijke aantal niet-reserves niet overstijgen.
        //
        //   Max     = aantal entries waar dit NOOIT een reserve was
        //             (reserve IS NULL AND reserve_handmatig_ingezet = 0)
        //   Huidig  = aantal entries dat momenteel in de loting valt
        //             (reserve IS NULL AND status IN (1, 5))
        //   Vrij    = Max - Huidig
        //
        // Als Vrij <= 0 → weigeren. Een ingezette reserve zit in Huidig
        // (reserve=NULL, status=5) maar NIET in Max (reserve_handmatig=1) →
        // dat is de "vervangings"-eigenschap die we hier afdwingen.
        $capStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN reserve IS NULL AND reserve_handmatig_ingezet = 0
                         THEN 1 ELSE 0 END) AS max_slots,
                SUM(CASE WHEN reserve IS NULL AND status IN (1, 5)
                         THEN 1 ELSE 0 END) AS in_loting
            FROM entries
            WHERE distance_combination_id = ?
        ");
        $capStmt->execute([$dcId]);
        $cap = $capStmt->fetch(PDO::FETCH_ASSOC) ?: ['max_slots' => 0, 'in_loting' => 0];
        $maxSlots = (int)($cap['max_slots'] ?? 0);
        $inLoting = (int)($cap['in_loting'] ?? 0);
        if ($inLoting >= $maxSlots) {
            http_response_code(409);
            echo json_encode([
                'error'    => "Geen vrije plekken meer — alle {$maxSlots} slots zijn al gevuld",
                'reden'    => 'geen_vrije_slots',
                'max'      => $maxSlots,
                'in_loting'=> $inLoting,
            ]);
            exit;
        }

        $pdo->prepare("
            UPDATE entries
               SET reserve                   = NULL,
                   reserve_handmatig_ingezet = 1,
                   status                    = 5
             WHERE distance_combination_id = ? AND person_license = ?
        ")->execute([$dcId, $personLicense]);

        echo json_encode([
            'ok'       => true,
            'actie'    => 'inzet',
            'status'   => 5,
            'reserve'  => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // actie === 'terug'
    if ($reserveNr === null || $reserveNr <= 0) {
        http_response_code(400);
        echo json_encode([
            'error' => "Voor 'terug' is reserve_nr (>=1) verplicht",
        ]);
        exit;
    }
    $pdo->prepare("
        UPDATE entries
           SET reserve                   = ?,
               reserve_handmatig_ingezet = 0
         WHERE distance_combination_id = ? AND person_license = ?
    ")->execute([$reserveNr, $dcId, $personLicense]);

    echo json_encode([
        'ok'      => true,
        'actie'   => 'terug',
        'reserve' => $reserveNr,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
