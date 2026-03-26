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

// Verrijkt een array van persons-rijen met hun meest recente transponders
function voegTranspondersToe(PDO $pdo, array $rows): array {
    if (!$rows) return $rows;
    $lks = array_unique(array_column($rows, 'license_key'));
    $ph  = implode(',', array_fill(0, count($lks), '?'));

    // Meest recente code per (person_license, slot) over alle wedstrijden
    $stmt = $pdo->prepare("
        SELECT t1.person_license, t1.slot, t1.code
        FROM transponders t1
        INNER JOIN (
            SELECT person_license, slot, MAX(updated_at) AS max_at
            FROM transponders
            WHERE person_license IN ($ph)
              AND slot IN (1, 2)
              AND code IS NOT NULL AND code != ''
            GROUP BY person_license, slot
        ) t2 ON t1.person_license = t2.person_license
             AND t1.slot          = t2.slot
             AND t1.updated_at    = t2.max_at
    ");
    $stmt->execute($lks);

    $tps = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tps[$t['person_license']][$t['slot']] = $t['code'];
    }

    return array_map(function($row) use ($tps) {
        $lk = $row['license_key'];
        $row['transponder1'] = $tps[$lk][1] ?? null;
        $row['transponder2'] = $tps[$lk][2] ?? null;
        return $row;
    }, $rows);
}

try {
    $action = trim($_GET['action'] ?? '');

    // ── Clubs lijst ──────────────────────────────────────────────────────────
    if ($action === 'clubs') {
        $rows = $pdo->query(
            "SELECT DISTINCT club_full, club_short
             FROM persons
             WHERE club_full IS NOT NULL AND club_full != ''
             ORDER BY club_full"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Zoek op relatienummer ────────────────────────────────────────────────
    if (isset($_GET['license_key']) && $_GET['license_key'] !== '') {
        $lk   = trim($_GET['license_key']);
        $stmt = $pdo->prepare("SELECT * FROM persons WHERE license_key = :lk LIMIT 1");
        $stmt->execute([':lk' => $lk]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row = voegTranspondersToe($pdo, [$row])[0];
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
                 ORDER BY updated_at DESC LIMIT 10"
            );
            $stmt->execute([':sn' => $sn, ':cat' => $cat]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT * FROM persons
                 WHERE start_number = :sn
                 ORDER BY updated_at DESC LIMIT 10"
            );
            $stmt->execute([':sn' => $sn]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(voegTranspondersToe($pdo, $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Geen geldige zoekparameter opgegeven']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
