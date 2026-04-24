<?php
// ============================================================
//  InlineComp – klassement-regels-presets per organisatie
//
//  GET  ?action=list[&org_id=UUID]     → alle presets (optioneel gefilterd)
//  POST ?action=create                 → {naam, org_id, regels}
//  POST ?action=update&id=UUID         → {naam, org_id, regels}
//  POST ?action=delete&id=UUID         → verwijder preset
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && !kanSchrijven($_authUser, 'beheer')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']);
    exit;
}

$action = trim($_GET['action'] ?? 'list');
$id     = trim($_GET['id']     ?? '');

function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

try {
    if ($method === 'GET' && $action === 'list') {
        $orgId = trim($_GET['org_id'] ?? '');
        if ($orgId !== '') {
            // Presets van die org + globale presets (org_id IS NULL)
            $stmt = $pdo->prepare("
                SELECT id, org_id, naam, regels, aangemaakt_op
                FROM klassement_presets
                WHERE org_id = ? OR org_id IS NULL
                ORDER BY (org_id IS NULL) ASC, naam
            ");
            $stmt->execute([$orgId]);
        } else {
            $stmt = $pdo->query("
                SELECT id, org_id, naam, regels, aangemaakt_op
                FROM klassement_presets
                ORDER BY naam
            ");
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['regels'] = json_decode($r['regels'] ?? '{}', true);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        if ($action === 'delete' && $id) {
            $pdo->prepare("DELETE FROM klassement_presets WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($action === 'create') {
            $naam = trim($body['naam'] ?? '');
            if ($naam === '') { http_response_code(400); echo json_encode(['error' => 'Naam is verplicht']); exit; }
            $regels = is_array($body['regels'] ?? null) ? $body['regels'] : [];
            $pid = uuid4();
            $pdo->prepare("
                INSERT INTO klassement_presets (id, org_id, naam, regels)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $pid,
                trim($body['org_id'] ?? '') ?: null,
                $naam,
                json_encode($regels, JSON_UNESCAPED_UNICODE),
            ]);
            echo json_encode(['ok' => true, 'id' => $pid]);
            exit;
        }
        if ($action === 'update' && $id) {
            $naam = trim($body['naam'] ?? '');
            if ($naam === '') { http_response_code(400); echo json_encode(['error' => 'Naam is verplicht']); exit; }
            $regels = is_array($body['regels'] ?? null) ? $body['regels'] : [];
            $pdo->prepare("
                UPDATE klassement_presets
                SET naam = ?, org_id = ?, regels = ?
                WHERE id = ?
            ")->execute([
                $naam,
                trim($body['org_id'] ?? '') ?: null,
                json_encode($regels, JSON_UNESCAPED_UNICODE),
                $id,
            ]);
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
