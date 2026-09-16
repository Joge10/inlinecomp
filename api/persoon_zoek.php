<?php
// ============================================================
//  InlineComp – persoon opzoeken in lokale database
//
//  GET ?license_key=X          → persoon op relatienummer
//  GET ?start_number=X         → persoon op startnummer (optioneel &category=Y)
//  GET ?action=clubs            → lijst van clubs uit persons-tabel
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../inc/person_id.php';   // person_id-resolutie (fase 3d-iii)
$_authUser = requireAuth($pdo);

// Verrijkt een array van persons-rijen met hun meest recente transponders
// Geeft transponder1, transponder2 (KNSB, slot 1/2) en transponders_extra (slot ≥ 3) terug.
// Gekoppeld op person_id (fase 3d-iii) i.p.v. person_license.
function voegTranspondersToe(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;
    $pids = array_unique(array_column($rows, 'person_id'));
    $ph   = implode(',', array_fill(0, count($pids), '?'));

    // Meest recente code per (person_id, slot) over alle wedstrijden — slot 1 en 2
    $stmtKnsb = $pdo->prepare("
        SELECT t1.person_id, t1.slot, t1.code
        FROM transponders t1
        INNER JOIN (
            SELECT person_id, slot, MAX(updated_at) AS max_at
            FROM transponders
            WHERE person_id IN ($ph)
              AND slot IN (1, 2)
              AND code IS NOT NULL AND code != ''
            GROUP BY person_id, slot
        ) t2 ON t1.person_id = t2.person_id
             AND t1.slot      = t2.slot
             AND t1.updated_at = t2.max_at
    ");
    $stmtKnsb->execute($pids);

    $tps = [];
    foreach ($stmtKnsb->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tps[$t['person_id']][$t['slot']] = $t['code'];
    }

    // Extra (lokale) transponders — slot ≥ 3, alle unieke codes per rijder
    $stmtExtra = $pdo->prepare("
        SELECT DISTINCT person_id, code
        FROM transponders
        WHERE person_id IN ($ph)
          AND slot >= 3
          AND code IS NOT NULL AND code != ''
    ");
    $stmtExtra->execute($pids);

    $extras = [];
    foreach ($stmtExtra->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $extras[$t['person_id']][] = $t['code'];
    }

    return array_map(function($row) use ($tps, $extras) {
        $pid = $row['person_id'];
        $row['transponder1']       = $tps[$pid][1] ?? null;
        $row['transponder2']       = $tps[$pid][2] ?? null;
        $row['transponders_extra'] = $extras[$pid] ?? [];
        return $row;
    }, $rows);
}

// Verrijkt rijen met het KNSB-relatienummer als 'license_key'. Fase 4: persons
// heeft geen license_key-kolom meer; het relatienummer leeft in
// person_external_ids(systeem='knsb'). Leeg (null) voor niet-KNSB-leden
// (extern/demo/manual/pending) — dan hoort het Relatienummer-veld leeg te zijn.
function voegKnsbLicentieToe(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;
    $pids = array_unique(array_column($rows, 'person_id'));
    $ph   = implode(',', array_fill(0, count($pids), '?'));
    $stmt = $pdo->prepare(
        "SELECT person_id, extern_id FROM person_external_ids
         WHERE systeem = 'knsb' AND person_id IN ($ph)"
    );
    $stmt->execute(array_values($pids));
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['person_id']] = $r['extern_id'];
    return array_map(function($row) use ($map) {
        $row['license_key'] = $map[$row['person_id']] ?? null;
        return $row;
    }, $rows);
}

try {
    $action = trim($_GET['action'] ?? '');

    // Alle zoek-endpoints hieronder zijn 'actieve' context (jury-scanner,
    // coach toevoegen, club-dropdowns voor entries). Pending-rijders
    // (pending_source='historie') zijn placeholders uit historische PDF-imports
    // en moeten daar NOOIT verschijnen — anders kan iemand een placeholder
    // selecteren voor een actieve wedstrijd. Filter overal expliciet.

    // ── Clubs lijst ──────────────────────────────────────────────────────────
    if ($action === 'clubs') {
        $rows = $pdo->query(
            "SELECT DISTINCT club_full, club_short
             FROM persons
             WHERE club_full IS NOT NULL AND club_full != ''
               AND pending_source IS NULL
             ORDER BY club_full"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Zoek op person_id (fase 3d-iii) of legacy relatienummer ───────────────
    // person_id heeft voorrang; een meegegeven license_key wordt naar person_id
    // geresolved. De persons-query draait op person_id → fase-4-proof.
    $token = trim($_GET['person_id'] ?? '') ?: trim($_GET['license_key'] ?? '');
    $pid   = (string)(resolveNaarPersonId($pdo, $token) ?? '');
    if ($pid !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM persons
             WHERE person_id = :pid
               AND pending_source IS NULL
             LIMIT 1"
        );
        $stmt->execute([':pid' => $pid]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row = voegKnsbLicentieToe($pdo, voegTranspondersToe($pdo, [$row]))[0];
        }
        echo json_encode($row ?: null, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Zoek op startnummer (+ optioneel categorie) ──────────────────────────
    if (isset($_GET['start_number']) && $_GET['start_number'] !== '') {
        $sn  = (int)$_GET['start_number'];
        $cat = trim($_GET['category'] ?? '');

        if ($cat !== '') {
            $stmt = $pdo->prepare(
                "SELECT * FROM persons
                 WHERE start_number = :sn AND category = :cat
                   AND pending_source IS NULL
                 ORDER BY updated_at DESC LIMIT 10"
            );
            $stmt->execute([':sn' => $sn, ':cat' => $cat]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT * FROM persons
                 WHERE start_number = :sn
                   AND pending_source IS NULL
                 ORDER BY updated_at DESC LIMIT 10"
            );
            $stmt->execute([':sn' => $sn]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(voegKnsbLicentieToe($pdo, voegTranspondersToe($pdo, $rows)), JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Geen geldige zoekparameter opgegeven']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
