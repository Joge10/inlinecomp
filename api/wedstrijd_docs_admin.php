<?php
// ============================================================
//  InlineComp – Wedstrijd-documenten beheer (admin)
//
//  Infobulletin + flyer per wedstrijd (zie wedstrijd-info-view).
//  Bestanden-upload loopt via api/upload.php (types
//  'wedstrijd_doc_info' en 'wedstrijd_doc_flyer'); deze endpoint
//  beheert alleen de URL en het verwijderen van bestanden.
//
//  GET  ?competition_id=X
//      → { infobulletin_url, infobulletin_file, flyer_file }
//
//  POST JSON body:
//      { competition_id,
//        infobulletin_url?:         string|null,   // afwezig = ongewijzigd
//        delete_infobulletin_file?: bool,
//        delete_flyer_file?:        bool }
//      → vernieuwde state (zelfde shape als GET)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo, ROL_SCHRIJF['beheer_basic']);

function _valideerCompId(string $id): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $compId = trim($_GET['competition_id'] ?? '');
    if (!_valideerCompId($compId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldig competition_id']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT infobulletin_url, infobulletin_file, flyer_file
            FROM competitions
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Wedstrijd niet gevonden']);
            exit;
        }
        echo json_encode([
            'infobulletin_url'  => $row['infobulletin_url'] ?: null,
            'infobulletin_file' => $row['infobulletin_file'] ?: null,
            'flyer_file'        => $row['flyer_file']        ?: null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Alleen GET of POST toegestaan']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$compId = trim($body['competition_id'] ?? '');
if (!_valideerCompId($compId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldig competition_id']);
    exit;
}

try {
    $cur = $pdo->prepare("
        SELECT infobulletin_url, infobulletin_file, flyer_file
        FROM competitions
        WHERE id = ?
        LIMIT 1
    ");
    $cur->execute([$compId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Wedstrijd niet gevonden']);
        exit;
    }

    // URL: aanwezig → instellen of wissen; afwezig → ongewijzigd.
    if (array_key_exists('infobulletin_url', $body)) {
        $url = $body['infobulletin_url'];
        $url = is_string($url) ? trim($url) : '';
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            http_response_code(400);
            echo json_encode(['error' => 'URL moet beginnen met http:// of https://']);
            exit;
        }
        $pdo->prepare("UPDATE competitions SET infobulletin_url = ? WHERE id = ?")
            ->execute([$url !== '' ? $url : null, $compId]);
    }

    // Bestand(en) verwijderen (vlag per kolom).
    foreach (['infobulletin_file' => 'delete_infobulletin_file',
              'flyer_file'        => 'delete_flyer_file'] as $kolom => $vlag) {
        if (!empty($body[$vlag])) {
            $oud = $row[$kolom];
            if ($oud) {
                $fs = __DIR__ . '/../' . ltrim($oud, '/');
                if (is_file($fs)) @unlink($fs);
            }
            $pdo->prepare("UPDATE competitions SET $kolom = NULL WHERE id = ?")
                ->execute([$compId]);
        }
    }

    $cur->execute([$compId]);
    $new = $cur->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'infobulletin_url'  => $new['infobulletin_url'] ?: null,
        'infobulletin_file' => $new['infobulletin_file'] ?: null,
        'flyer_file'        => $new['flyer_file']        ?: null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
