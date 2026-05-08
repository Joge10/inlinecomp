<?php
// ============================================================
//  InlineComp – Logo upload
//
//  POST multipart/form-data
//    type  = 'org' | 'sponsor' | 'baan'
//    id    = UUID van organisatie / sponsor / baan
//    logo  = bestandsveld (image/*)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'beheer')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']);
    exit;
}

$type = trim($_POST['type'] ?? '');
$id   = trim($_POST['id']   ?? '');

if (!$type || !$id) {
    http_response_code(400);
    echo json_encode(['error' => 'type en id zijn vereist']);
    exit;
}

if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Geen bestand of upload-fout (code ' . ($_FILES['logo']['error'] ?? '?') . ')']);
    exit;
}

$tmpPath = $_FILES['logo']['tmp_name'];
$mime    = mime_content_type($tmpPath);
$allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
            'image/svg+xml' => 'svg', 'image/webp' => 'webp'];

if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Alleen PNG, JPG, GIF, SVG of WebP toegestaan']);
    exit;
}

$ext       = $allowed[$mime];
$uploadDir = __DIR__ . '/../uploads/logos/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$safeId   = preg_replace('/[^a-z0-9\-]/', '', strtolower($id));
$filename = $type . '_' . $safeId . '.' . $ext;
$fullPath = $uploadDir . $filename;

if (!move_uploaded_file($tmpPath, $fullPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Opslaan op server mislukt']);
    exit;
}

$relPath = 'uploads/logos/' . $filename;

try {
    if ($type === 'org') {
        $pdo->prepare("UPDATE organisaties SET logo_path = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$relPath, $id]);
    } elseif ($type === 'sponsor') {
        $pdo->prepare("UPDATE organisatie_sponsors SET logo_path = ? WHERE id = ?")
            ->execute([$relPath, $id]);
    } elseif ($type === 'baan') {
        $pdo->prepare("UPDATE banen SET logo_path = ?, logo_updated_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$relPath, $id]);
    } elseif ($type === 'baan_sponsor') {
        $pdo->prepare("UPDATE baan_sponsors SET logo_path = ? WHERE id = ?")
            ->execute([$relPath, $id]);
    }
    echo json_encode(['path' => $relPath]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
