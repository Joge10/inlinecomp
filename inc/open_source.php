<?php
// ============================================================
//  inc/open_source.php — lijst van gebruikte open-source software
//  (Info → Versie → "Gebruikte open-source software").
//
//  Server-pakketten komen automatisch uit vendor/composer/installed.json
//  (dat staat op de server; composer.lock niet). De browser-bibliotheken
//  staan hieronder met de hand — ze zijn zelf gehost in assets/vendor/;
//  bij een update van PDF.js, html2pdf.js of JSZip hier het versienummer
//  bijwerken.
// ============================================================

if (!function_exists('openSourceLijst')) {
    /** @return array{server: list<array>, browser: list<array>} */
    function openSourceLijst(): array {
        $server = [];
        $pad = __DIR__ . '/../vendor/composer/installed.json';
        $json = is_file($pad) ? json_decode((string) @file_get_contents($pad), true) : null;
        $pakketten = is_array($json) ? ($json['packages'] ?? $json) : [];
        foreach ($pakketten as $p) {
            if (!is_array($p) || empty($p['name'])) continue;
            $url = $p['homepage'] ?? ($p['source']['url'] ?? '');
            $url = preg_replace('/\.git$/', '', (string) $url);
            $server[] = [
                'naam'     => (string) $p['name'],
                'versie'   => ltrim((string) ($p['version'] ?? ''), 'v'),
                'licentie' => implode(' / ', (array) ($p['license'] ?? [])),
                'url'      => preg_match('#^https?://#', $url) ? $url : '',
            ];
        }
        usort($server, fn($a, $b) => strcmp($a['naam'], $b['naam']));

        $browser = [
            ['naam' => 'PDF.js',      'versie' => '3.11.174', 'licentie' => 'Apache-2.0',
             'url' => 'https://mozilla.github.io/pdf.js/', 'waar' => 'Infobulletin tonen (Publiek)'],
            ['naam' => 'html2pdf.js', 'versie' => '0.10.1',   'licentie' => 'MIT',
             'url' => 'https://github.com/eKoopmans/html2pdf.js',
             'waar' => 'PDF-export (Beheer) — bevat jsPDF en html2canvas'],
            ['naam' => 'JSZip',       'versie' => '3.10.1',   'licentie' => 'MIT / GPL-3.0',
             'url' => 'https://stuk.github.io/jszip/', 'waar' => 'ZIP-export (Beheer)'],
        ];
        return ['server' => $server, 'browser' => $browser];
    }
}
