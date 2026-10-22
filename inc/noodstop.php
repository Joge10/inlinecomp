<?php
// ============================================================
//  inc/noodstop.php — harde offline-schakelaar ("noodstop")
//
//  Verschil met de onderhoudsmodus (inc/maintenance.php): die draait IN PHP,
//  dus elk verzoek gaat eerst door PHP. Bij een lek in PHP zelf (bv. PHP2Shell,
//  okt 2026) beschermt dat niet. De noodstop schrijft daarom een blok in de
//  root-.htaccess: Apache weigert elk verzoek met een 503 + offline.html
//  vóórdat PHP het ziet.
//
//  - Bron van waarheid = het blok in .htaccess (tussen de markers hieronder).
//    Reden/sinds/IP staan daarnaast in app_instellingen voor de UI.
//  - offline.html wordt gegenereerd uit inc/noodstop_offline.tpl.html
//    (staat in .gitignore — het is een server-artefact).
//  - Elke schrijfactie: eerst backup (.htaccess.noodstop-backup, dotfile =
//    niet via HTTP op te vragen), dan atomair wegschrijven (temp + rename),
//    dan een loopback-check. Geeft de site daarna een 500 → backup terug.
//  - LET OP: een .htaccess in een submap met een eigen 'RewriteEngine on'
//    erft dit blok NIET (mod_rewrite per-dir). Nu heeft geen enkele submap dat.
// ============================================================

const NOODSTOP_START = '# >>> INLINECOMP NOODSTOP';
const NOODSTOP_EIND  = '# <<< INLINECOMP NOODSTOP <<<';

if (!function_exists('noodstopPaden')) {
    function noodstopPaden(): array {
        $root = realpath(__DIR__ . '/..');
        return [
            'htaccess' => $root . '/.htaccess',
            'backup'   => $root . '/.htaccess.noodstop-backup',
            'offline'  => $root . '/offline.html',
            'tpl'      => __DIR__ . '/noodstop_offline.tpl.html',
        ];
    }
}

if (!function_exists('noodstopIsAan')) {
    /** Staat het noodstop-blok in .htaccess? */
    function noodstopIsAan(): bool {
        $p = noodstopPaden();
        $inh = @file_get_contents($p['htaccess']);
        return $inh !== false && strpos($inh, NOODSTOP_START) !== false;
    }
}

if (!function_exists('_noodstopZonderBlok')) {
    /**
     * Haalt een bestaand noodstop-blok weg, inclusief de lege regel ervóór en de
     * regelovergang erna — precies wat noodstopAan() toevoegde, zodat uitzetten
     * de oorspronkelijke .htaccess byte-voor-byte herstelt.
     */
    function _noodstopZonderBlok(string $inh): string {
        $re = '/\R?' . preg_quote(NOODSTOP_START, '/') . '.*?' . preg_quote(NOODSTOP_EIND, '/') . '\R?/s';
        return preg_replace($re, '', $inh, 1);
    }
}

if (!function_exists('_noodstopBlok')) {
    function _noodstopBlok(?string $ip, string $door, string $tijd): string {
        $r   = [];
        $r[] = NOODSTOP_START . ' — aangezet ' . $tijd . ' door ' . preg_replace('/[\r\n]+/', ' ', $door) . ' >>>';
        $r[] = '# Automatisch geschreven door Beheer → Systeem → Onderhoud. Niet met de hand bewerken.';
        $r[] = '# Weer online zonder Beheer: verwijder dit HELE blok (t/m de <<<-regel) via cPanel → Bestandsbeheer.';
        $r[] = 'ErrorDocument 503 /offline.html';
        $r[] = 'RewriteCond %{ENV:REDIRECT_STATUS} ^$';
        if ($ip !== null && $ip !== '') {
            $r[] = 'RewriteCond %{REMOTE_ADDR} !^' . preg_quote($ip, '/') . '$';
        }
        $r[] = 'RewriteCond %{REQUEST_URI} !^/offline\.html$';
        $r[] = 'RewriteRule ^ - [R=503,L]';
        $r[] = '<IfModule mod_headers.c>';
        $r[] = '    Header always set Retry-After "3600"';
        $r[] = '</IfModule>';
        $r[] = NOODSTOP_EIND;
        return implode("\n", $r) . "\n";
    }
}

if (!function_exists('_noodstopSchrijfAtomair')) {
    function _noodstopSchrijfAtomair(string $pad, string $inhoud): void {
        $tmp = $pad . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $inhoud, LOCK_EX) === false) {
            throw new RuntimeException('Kon tijdelijk bestand niet schrijven naast ' . basename($pad) . '.');
        }
        if (!@rename($tmp, $pad)) {
            @unlink($tmp);
            throw new RuntimeException('Kon ' . basename($pad) . ' niet vervangen.');
        }
    }
}

if (!function_exists('_noodstopLoopback')) {
    /**
     * Vraagt de eigen homepage op en geeft de HTTP-status terug (0 = niet te
     * bereiken / geen curl). De server zelf staat nooit op de IP-uitzondering,
     * dus bij 'aan' hoort hier 503 uit te komen.
     */
    function _noodstopLoopback(): int {
        if (!function_exists('curl_init')) return 0;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) return 0;
        $ch = curl_init('https://' . $host . '/?noodstop_check=' . time());
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }
}

if (!function_exists('_noodstopSchrijfMetCheck')) {
    /**
     * Schrijft nieuwe .htaccess-inhoud met backup + loopback-check.
     * Geeft ['check' => http-code] terug; bij een 500 na schrijven wordt de
     * backup teruggezet en volgt een exception.
     */
    function _noodstopSchrijfMetCheck(string $nieuw): array {
        $p   = noodstopPaden();
        $oud = @file_get_contents($p['htaccess']);
        if ($oud === false) throw new RuntimeException('.htaccess niet leesbaar.');
        if (@file_put_contents($p['backup'], $oud, LOCK_EX) === false) {
            throw new RuntimeException('Kon geen backup van .htaccess maken — niets gewijzigd.');
        }
        _noodstopSchrijfAtomair($p['htaccess'], $nieuw);
        $code = _noodstopLoopback();
        if ($code === 500) {
            _noodstopSchrijfAtomair($p['htaccess'], $oud);
            throw new RuntimeException('De site gaf na het wijzigen een serverfout (500); de oude .htaccess is teruggezet.');
        }
        return ['check' => $code];
    }
}

if (!function_exists('noodstopAan')) {
    /**
     * Zet de noodstop aan. $ip = uit te zonderen IP (of null), $reden = tekst
     * voor offline.html, $door = naam van de owner (voor het commentaar).
     */
    function noodstopAan(string $reden, ?string $ip, string $door): array {
        $p = noodstopPaden();

        // 1. offline.html genereren (vóór de .htaccess, zodat de 503 meteen een pagina heeft)
        $tpl = @file_get_contents($p['tpl']);
        if ($tpl === false) throw new RuntimeException('Sjabloon voor offline.html ontbreekt.');
        $tijd = date('d-m-Y H:i');
        $html = strtr($tpl, [
            '{{REDEN}}' => nl2br(htmlspecialchars($reden, ENT_QUOTES, 'UTF-8')),
            '{{SINDS}}' => htmlspecialchars($tijd, ENT_QUOTES, 'UTF-8'),
            '{{HOST}}'  => htmlspecialchars($_SERVER['HTTP_HOST'] ?? '', ENT_QUOTES, 'UTF-8'),
        ]);
        _noodstopSchrijfAtomair($p['offline'], $html);

        // 2. blok in .htaccess (bestaand blok eerst weg → opnieuw aanzetten = bijwerken)
        $inh  = (string) @file_get_contents($p['htaccess']);
        $inh  = _noodstopZonderBlok($inh);
        $blok = _noodstopBlok($ip, $door, $tijd);
        // Direct onder de eerste 'RewriteEngine on' (moet vóór de overige regels);
        // ontbreekt die, dan bovenaan mét een eigen RewriteEngine-regel.
        // Invoegen als "\n" + blok direct ná de regelovergang van die regel; de rest
        // blijft ongewijzigd (zie _noodstopZonderBlok voor het spiegelbeeld).
        if (preg_match('/^[ \t]*RewriteEngine[ \t]+on[ \t]*\R/mi', $inh, $m, PREG_OFFSET_CAPTURE)) {
            $na    = $m[0][1] + strlen($m[0][0]);
            $nieuw = substr($inh, 0, $na) . "\n" . $blok . substr($inh, $na);
        } else {
            $nieuw = "RewriteEngine on\n\n" . $blok . $inh;
        }
        return _noodstopSchrijfMetCheck($nieuw);
    }
}

if (!function_exists('noodstopUit')) {
    function noodstopUit(): array {
        $p   = noodstopPaden();
        $inh = (string) @file_get_contents($p['htaccess']);
        if (strpos($inh, NOODSTOP_START) === false) return ['check' => null];
        return _noodstopSchrijfMetCheck(_noodstopZonderBlok($inh));
    }
}
