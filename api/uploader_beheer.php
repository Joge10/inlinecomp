<?php
// ============================================================
//  InlineComp – Uploader-folder beheer (alleen owner/admin)
//
//  GET  ?action=list                  → mappen + grootte/aantal/leeftijd
//  POST ?action=delete  body {name}   → verwijder één map (incl. inhoud)
//
//  De uploader-folder bevat per wedstrijd een submap met CSV-exports
//  vanuit Orbits/MyLaps. Na verloop van tijd kunnen oude mappen weg.
//  Deze endpoint geeft een admin de tools om dat handmatig te doen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo, ['owner', 'admin']);

define('UPLOAD_BASE', __DIR__ . '/../uploader/');

$action = $_GET['action'] ?? '';
if ($action === '') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
}

// ── list ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    // Geblokkeerde mappen ophalen — match wordt op naam gedaan, ook al staat
    // de map niet meer op disk dan blijft de blokkade-row onschadelijk staan
    // (cleanup zou kunnen, maar is geen probleem zolang de tabel klein blijft).
    // Join met users om te tonen wie er heeft geblokkeerd.
    $blokkades = [];
    try {
        $stmt = $pdo->query("
            SELECT b.naam, b.geblokkeerd_op,
                   COALESCE(u.naam, u.username, '?') AS door_naam
            FROM upload_map_blokkades b
            LEFT JOIN users u ON u.id = b.geblokkeerd_door
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $blokkades[$r['naam']] = [
                'op'   => $r['geblokkeerd_op'],
                'door' => $r['door_naam'],
            ];
        }
    } catch (Throwable $e) {
        // Tabel bestaat nog niet (migratie niet gedraaid) — gewoon doorgaan
        // alsof er geen blokkades zijn, zodat oude installaties niet breken.
    }

    $mappen = [];
    if (is_dir(UPLOAD_BASE)) {
        foreach (scandir(UPLOAD_BASE) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $pad = UPLOAD_BASE . $entry;
            if (!is_dir($pad)) continue;
            $files       = glob($pad . '/*');
            $totalSize   = 0;
            $bestandsCnt = 0;
            $latest      = (int)@filemtime($pad);
            foreach ($files as $f) {
                if (!is_file($f)) continue;
                $bestandsCnt++;
                $totalSize += @filesize($f);
                $m = (int)@filemtime($f);
                if ($m > $latest) $latest = $m;
            }
            $blok = $blokkades[$entry] ?? null;
            $mappen[] = [
                'name'              => $entry,
                'file_count'        => $bestandsCnt,
                'total_size'        => $totalSize,
                'latest_mtime'      => $latest,
                'age_days'          => $latest > 0
                                       ? (int)floor((time() - $latest) / 86400)
                                       : null,
                'geblokkeerd'       => $blok !== null,
                'geblokkeerd_op'    => $blok['op']   ?? null,
                'geblokkeerd_door'  => $blok['door'] ?? null,
            ];
        }
        usort($mappen, fn($a, $b) => $b['latest_mtime'] <=> $a['latest_mtime']);
    }
    echo json_encode(['mappen' => $mappen], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── blokkeer ─────────────────────────────────────────────────────────────────
// POST {action: 'blokkeer', name: 'Rotterdam_2_5_NK'} → INSERT IGNORE
if ($action === 'blokkeer' || $action === 'deblokkeer') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($body['name'] ?? '');
    if (!$name || $name !== basename($name) || str_contains($name, '..')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige mapnaam']);
        exit;
    }

    try {
        if ($action === 'blokkeer') {
            $userId = $_authUser['id'] ?? null;
            $stmt = $pdo->prepare(
                "INSERT INTO upload_map_blokkades (naam, geblokkeerd_door)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE geblokkeerd_op = CURRENT_TIMESTAMP,
                                         geblokkeerd_door = VALUES(geblokkeerd_door)"
            );
            $stmt->execute([$name, $userId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM upload_map_blokkades WHERE naam = ?");
            $stmt->execute([$name]);
        }
        echo json_encode(['ok' => true, 'naam' => $name, 'geblokkeerd' => $action === 'blokkeer']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB-fout: ' . $e->getMessage()]);
    }
    exit;
}

// ── delete ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($body['name'] ?? '');

    // Path-traversal preventie: alleen pure mapnaam, geen slashes/dots
    if (!$name || $name !== basename($name) || str_contains($name, '..')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige mapnaam']);
        exit;
    }
    $pad = UPLOAD_BASE . $name;
    if (!is_dir($pad)) {
        http_response_code(404);
        echo json_encode(['error' => 'Map bestaat niet']);
        exit;
    }

    // Verwijder alle bestanden in de map (recursief, alleen onder UPLOAD_BASE).
    // Veiligheidscheck: realpath() moet binnen UPLOAD_BASE liggen.
    $real = realpath($pad);
    $base = realpath(UPLOAD_BASE);
    if ($real === false || $base === false || !str_starts_with($real, $base)) {
        http_response_code(400);
        echo json_encode(['error' => 'Pad ligt buiten upload-folder']);
        exit;
    }

    $verwijderd = 0;
    $fout       = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $ok = $item->isDir() ? @rmdir($item->getPathname())
                             : @unlink($item->getPathname());
        if ($ok) $verwijderd++;
        else     $fout++;
    }
    @rmdir($real);

    if (is_dir($real)) {
        http_response_code(500);
        echo json_encode([
            'error'      => 'Map kon niet (volledig) verwijderd worden',
            'verwijderd' => $verwijderd,
            'fout'       => $fout,
        ]);
        exit;
    }
    echo json_encode(['ok' => true, 'verwijderd' => $verwijderd]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende actie']);
