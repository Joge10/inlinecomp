<?php
// ============================================================
//  InlineComp – Logo / protokol-foto upload
//
//  POST multipart/form-data
//    type  = 'org' | 'sponsor' | 'baan' | 'baan_sponsor'
//          | 'protokol_voorblad' | 'protokol_nawoord'
//    id    = UUID van organisatie / sponsor / baan / wedstrijd
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
// Logo's en foto's: alleen images. Melding-bijlages: ook PDF + Office-docs.
$allowedImages = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
                  'image/svg+xml' => 'svg', 'image/webp' => 'webp'];
$allowedDocs   = ['application/pdf' => 'pdf',
                  'application/msword' => 'doc',
                  'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                  'application/vnd.ms-excel' => 'xls',
                  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                  'text/plain' => 'txt'];
$allowed = ($type === 'melding')
    ? array_merge($allowedImages, $allowedDocs)
    : $allowedImages;

if (!isset($allowed[$mime])) {
    http_response_code(400);
    $msg = ($type === 'melding')
        ? 'Alleen PDF, Word, Excel, txt of afbeelding (PNG/JPG/GIF/SVG/WebP) toegestaan'
        : 'Alleen PNG, JPG, GIF, SVG of WebP toegestaan';
    echo json_encode(['error' => $msg]);
    exit;
}

$ext     = $allowed[$mime];
$safeId  = preg_replace('/[^a-z0-9\-]/', '', strtolower($id));

// Protokol-foto's krijgen een eigen submap per wedstrijd zodat een
// wedstrijd-verwijderaar makkelijk al z'n assets kan opruimen.
if ($type === 'protokol_voorblad' || $type === 'protokol_nawoord') {
    $uploadDir = __DIR__ . '/../uploads/protokol/' . $safeId . '/';
    $field     = $type === 'protokol_voorblad' ? 'voorblad' : 'nawoord';
    // Cache-buster in filename voorkomt dat browser de oude foto laat zien
    // na vervangen. We schrijven nieuwe file + verwijderen de oude.
    $stamp    = time();
    $filename = $field . '_' . $stamp . '.' . $ext;
    $relPath  = 'uploads/protokol/' . $safeId . '/' . $filename;
} elseif ($type === 'melding') {
    // Melding-bijlage: eigen submap per melding zodat verwijderen van een
    // melding meteen het bestand mee opruimt. Originele filename wordt
    // apart bewaard (zie meldingen.php) — hier schrijven we een veilige
    // naam met timestamp om cache-issues en path-traversal te voorkomen.
    $uploadDir = __DIR__ . '/../uploads/meldingen/' . $safeId . '/';
    $stamp     = time();
    $filename  = 'bijlage_' . $stamp . '.' . $ext;
    $relPath   = 'uploads/meldingen/' . $safeId . '/' . $filename;
} else {
    $uploadDir = __DIR__ . '/../uploads/logos/';
    $filename  = $type . '_' . $safeId . '.' . $ext;
    $relPath   = 'uploads/logos/' . $filename;
}
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
$fullPath = $uploadDir . $filename;

if (!move_uploaded_file($tmpPath, $fullPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Opslaan op server mislukt']);
    exit;
}

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
    } elseif ($type === 'melding') {
        // Melding-bijlage: nieuwe bijlage vervangt oude. Originele filename
        // bewaren voor mooie download-naam ("programma.pdf" ipv
        // "bijlage_1718453200.pdf").
        $origNaam = (string)($_FILES['logo']['name'] ?? 'bijlage');
        $origNaam = mb_substr(basename($origNaam), 0, 255);
        $oudStmt = $pdo->prepare("SELECT bijlage_path FROM public_meldingen WHERE id = ?");
        $oudStmt->execute([$id]);
        $oudPad = $oudStmt->fetchColumn();
        $pdo->prepare("
            UPDATE public_meldingen
               SET bijlage_path = ?, bijlage_naam = ?, bijlage_mime = ?
             WHERE id = ?
        ")->execute([$relPath, $origNaam, $mime, $id]);
        if ($oudPad && $oudPad !== $relPath) {
            $oudFs = __DIR__ . '/../' . $oudPad;
            if (is_file($oudFs)) @unlink($oudFs);
        }
    } elseif ($type === 'protokol_voorblad' || $type === 'protokol_nawoord') {
        $kolom = $type === 'protokol_voorblad'
            ? 'protokol_voorblad_foto'
            : 'protokol_nawoord_foto';
        // Oude foto eerst opzoeken zodat we 'm na de update kunnen wissen
        // (anders blijven obsolete bestanden eindeloos rondhangen).
        $oudStmt = $pdo->prepare("SELECT $kolom FROM competitions WHERE id = ?");
        $oudStmt->execute([$id]);
        $oudPad = $oudStmt->fetchColumn();
        $pdo->prepare("UPDATE competitions SET $kolom = ? WHERE id = ?")
            ->execute([$relPath, $id]);
        if ($oudPad && $oudPad !== $relPath) {
            $oudFs = __DIR__ . '/../' . $oudPad;
            if (is_file($oudFs)) @unlink($oudFs);
        }
    }
    echo json_encode(['path' => $relPath]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
