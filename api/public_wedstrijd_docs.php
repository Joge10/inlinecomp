<?php
// api/public_wedstrijd_docs.php
// Endpoint: wedstrijd-documenten voor de wedstrijd-info-view (fase 5b-content).
//
// Input (GET): comp_id = UUID van de wedstrijd.
// Output: JSON { infobulletin_url, infobulletin_file_url, flyer_file_url }
//
// - infobulletin_url:      externe URL (primair in render).
// - infobulletin_file_url: pad naar zelf-gehoste PDF, OF null.
// - flyer_file_url:        pad naar geuploade flyer-afbeelding, OF null.
// `infobulletin_file` en `flyer_file` in de DB bevatten het volledige
// relpath t.o.v. de webroot (bv. `uploads/wedstrijd_docs/<id>/info_<ts>.pdf`),
// zoals ingevuld door api/upload.php; de endpoint zet er een leading `/` voor.
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'wedstrijd_docs') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    try {
        $compId = trim($_GET['comp_id'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $compId)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid comp_id']);
            exit;
        }
        // Documenten (infobulletin/flyer) worden ook voor "verborgen" wedstrijden
        // teruggegeven — "verborgen" betekent alleen dat de wedstrijd niet live
        // via InlineComp wordt gevolgd (geen programma/startlijsten/uitslagen),
        // niet dat de infobulletin of flyer afgeschermd is. is_demo blijft wel
        // uitgefilterd (demo-wedstrijd mag nooit docs lekken).
        $stmt = $pdo->prepare("
            SELECT infobulletin_url, infobulletin_file, flyer_file
            FROM competitions
            WHERE id = ?
              AND is_demo = 0
            LIMIT 1
        ");
        $stmt->execute([$compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode([
                'infobulletin_url'      => null,
                'infobulletin_file_url' => null,
                'flyer_file_url'        => null,
            ]);
            exit;
        }
        // DB bevat relpath zonder leading slash — zet er één voor zodat de
        // frontend 'em direct als absolute URL kan gebruiken.
        $infoFile  = $row['infobulletin_file'] ? '/' . ltrim($row['infobulletin_file'], '/') : null;
        $flyerFile = $row['flyer_file']        ? '/' . ltrim($row['flyer_file'], '/')        : null;
        echo json_encode([
            'infobulletin_url'      => $row['infobulletin_url'] ?: null,
            'infobulletin_file_url' => $infoFile,
            'flyer_file_url'        => $flyerFile,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
