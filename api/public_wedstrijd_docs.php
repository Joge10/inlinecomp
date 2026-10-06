<?php
// api/public_wedstrijd_docs.php
// Endpoint: wedstrijd-documenten voor de wedstrijd-info-view (fase 5b-content).
//
// Input (GET): comp_id = UUID van de wedstrijd.
// Output: JSON {
//   infobulletin_url, infobulletin_file_url, flyer_file_url,
//   baan: { naam, stad, vereniging_naam, logo_url, layout_data } | null
// }
//
// Een call bedient alle drie de tabs van de wedstrijd-info-view
// (Infobulletin / Flyer / Vereniging):
// - infobulletin_url:      externe URL (primair in render).
// - infobulletin_file_url: pad naar zelf-gehoste PDF, OF null.
// - flyer_file_url:        pad naar geuploade flyer-afbeelding, OF null.
// - baan:                  info voor de Vereniging-tab (naam, stad, vereniging-
//                          naam, logo, baan-layout als parseable object), OF
//                          null als de wedstrijd geen baan heeft gekoppeld.
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
        // Cross-org fallback voor logo + layout_data: zelfde fysieke baan kan
        // onder meerdere organisaties bestaan (dedupliceert op baan-naam).
        // Als DEZE org z'n eigen rij nog niet heeft gevuld, pakken we een
        // waarde van een andere org met dezelfde baan-naam. Zelfde patroon
        // als public_competitions.php voor logo/vereniging — nu uitgebreid
        // met layout_data (fase A-blok 3): 1× tekenen volstaat voor alle orgs.
        $stmt = $pdo->prepare("
            SELECT c.infobulletin_url, c.infobulletin_file, c.flyer_file,
                   c.baan_id,
                   b.naam            AS baan_naam,
                   b.stad            AS baan_stad,
                   COALESCE(b.vereniging_naam, (
                       SELECT b2.vereniging_naam FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.vereniging_naam IS NOT NULL AND b2.vereniging_naam != ''
                       LIMIT 1
                   )) AS baan_vereniging,
                   COALESCE(b.logo_path, (
                       SELECT b2.logo_path FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                       LIMIT 1
                   )) AS baan_logo,
                   COALESCE(b.layout_data, (
                       SELECT b2.layout_data FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.layout_data IS NOT NULL
                       LIMIT 1
                   )) AS baan_layout_data,
                   COALESCE(b.adres, (
                       SELECT b2.adres FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.adres IS NOT NULL AND b2.adres != ''
                       LIMIT 1
                   )) AS baan_adres,
                   COALESCE(b.over_tekst, (
                       SELECT b2.over_tekst FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.over_tekst IS NOT NULL AND b2.over_tekst != ''
                       LIMIT 1
                   )) AS baan_over_tekst,
                   COALESCE(b.over_foto, (
                       SELECT b2.over_foto FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.over_foto IS NOT NULL AND b2.over_foto != ''
                       LIMIT 1
                   )) AS baan_over_foto,
                   COALESCE(b.website_url, (
                       SELECT b2.website_url FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.website_url IS NOT NULL AND b2.website_url != ''
                       LIMIT 1
                   )) AS baan_website_url
            FROM competitions c
            LEFT JOIN banen b ON b.id = c.baan_id
            WHERE c.id = ?
              AND c.is_demo = 0
            LIMIT 1
        ");
        $stmt->execute([$compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode([
                'infobulletin_url'      => null,
                'infobulletin_file_url' => null,
                'flyer_file_url'        => null,
                'baan'                  => null,
            ]);
            exit;
        }
        // DB bevat relpath zonder leading slash — zet er één voor zodat de
        // frontend 'em direct als absolute URL kan gebruiken.
        $infoFile  = $row['infobulletin_file'] ? '/' . ltrim($row['infobulletin_file'], '/') : null;
        $flyerFile = $row['flyer_file']        ? '/' . ltrim($row['flyer_file'], '/')        : null;
        $baanLogo  = $row['baan_logo']         ? '/' . ltrim($row['baan_logo'],       '/') : null;
        // layout_data staat als JSON-string in MariaDB → decoderen zodat de
        // client er direct mee kan werken. Null = geen layout getekend.
        $layout = null;
        if (!empty($row['baan_layout_data'])) {
            $decoded = json_decode($row['baan_layout_data'], true);
            if (is_array($decoded)) $layout = $decoded;
        }
        $overFoto = $row['baan_over_foto'] ? '/' . ltrim($row['baan_over_foto'], '/') : null;
        $baan = $row['baan_id'] ? [
            'naam'            => $row['baan_naam']        ?: null,
            'stad'            => $row['baan_stad']        ?: null,
            'vereniging_naam' => $row['baan_vereniging']  ?: null,
            'logo_url'        => $baanLogo,
            'layout_data'     => $layout,
            'adres'           => $row['baan_adres']       ?: null,
            'over_tekst'      => $row['baan_over_tekst']  ?: null,
            'over_foto_url'   => $overFoto,
            'website_url'     => $row['baan_website_url'] ?: null,
        ] : null;
        echo json_encode([
            'infobulletin_url'      => $row['infobulletin_url'] ?: null,
            'infobulletin_file_url' => $infoFile,
            'flyer_file_url'        => $flyerFile,
            'baan'                  => $baan,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
