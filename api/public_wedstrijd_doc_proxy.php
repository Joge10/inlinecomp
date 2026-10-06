<?php
// api/public_wedstrijd_doc_proxy.php
// Proxy voor externe wedstrijd-documenten (fase 5b-content).
//
// Haalt de door een beheerder opgegeven externe URL op en streamt 'em terug
// met zelfde-origin headers, zodat PDF.js inline kan renderen zonder CORS-
// errors. De URL komt uit de DB (admin-ingesteld in de 📎-dialog), NIET
// uit de query-string → geen SSRF-risico.
//
// Input (GET):
//   comp_id = UUID van de wedstrijd (publiek zichtbaar/aangekondigd)
//   kind    = 'infobulletin' (voor nu de enige externe URL)
//
// Output: raw PDF-bytes met Content-Type: application/pdf en
//   Cache-Control: public, max-age=1800 (30 min browser-cache).
//
// Fout-paden:
//   400 ongeldige input, 404 geen URL, 502 upstream-fout — frontend valt
//   bij PDF.js-render-fout automatisch terug op de fallback-tegel.
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'wedstrijd_doc_proxy') {
    try {
        $compId = trim($_GET['comp_id'] ?? '');
        $kind   = trim($_GET['kind']    ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $compId)) {
            http_response_code(400);
            exit;
        }
        if ($kind !== 'infobulletin') {
            http_response_code(400);
            exit;
        }
        // Zelfde zichtbaarheids-regel als public_wedstrijd_docs.php: ook
        // "verborgen" wedstrijden mogen hun infobulletin-URL proxyen.
        $stmt = $pdo->prepare("
            SELECT infobulletin_url
            FROM competitions
            WHERE id = ?
              AND is_demo = 0
            LIMIT 1
        ");
        $stmt->execute([$compId]);
        $url = $stmt->fetchColumn();
        if (!$url || !preg_match('#^https?://#i', $url)) {
            http_response_code(404);
            exit;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'InlineComp-ProxyBot/1.0 (+https://inlineresults.devriesen.com)',
            CURLOPT_HTTPHEADER     => ['Accept: application/pdf'],
        ]);
        $body  = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code !== 200 || $body === false || $body === '') {
            http_response_code(502);
            exit;
        }
        // Sanity-check: upstream moet een PDF teruggeven, geen HTML-foutpagina
        // die als PDF zou lekken. Combi header + magic-bytes-check.
        $isPdfHeader = stripos($ctype, 'application/pdf') !== false
                    || stripos($ctype, 'application/octet-stream') !== false;
        $isPdfMagic  = substr($body, 0, 4) === '%PDF';
        if (!$isPdfHeader && !$isPdfMagic) {
            http_response_code(502);
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="infobulletin.pdf"');
        header('Cache-Control: public, max-age=1800');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($body));
        echo $body;
    } catch (Throwable $e) {
        http_response_code(500);
    }
    exit;
}
