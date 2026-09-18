<?php
// ============================================================
//  inc/maintenance.php — in-app onderhoudsmodus (maintenance mode)
//
//  Roep maintenanceGate($pdo) aan bovenaan de PUBLIEKE ingangen (public/,
//  coach/, check/). Staat de onderhoudsmodus aan, dan krijgt het publiek de
//  onderhoudspagina (503); staf met bypass werkt/test gewoon door op productie.
//
//  Bypass = een geldige STAF-sessie (ic_session-cookie, path=/) met voldoende
//  rol. Instelling 'maintenance_bypass':
//    'owner'       → alleen owner (voor het echt risicovolle werk)
//    'owner_admin' → owner + admin (standaard)
//  Zo werkt de bypass in élke app, ongeacht diens eigen sessie.
//
//  Login blijft altijd open (login.php + api/auth.php), zodat de beheerder zich
//  nooit buitensluit. De .htaccess-variant blijft bestaan als 'harde offline'
//  voor momenten dat er bestanden geüpload worden (PHP mag dan niet draaien).
// ============================================================

require_once __DIR__ . '/../auth/session.php';        // getSession()
require_once __DIR__ . '/app_instellingen.php';        // getInstelling()

if (!function_exists('maintenanceGate')) {
    function maintenanceGate(PDO $pdo): void {
        if (getInstelling($pdo, 'maintenance_mode', '0') !== '1') return;

        // Bypass: staf met voldoende rol → vrij baan (testen op productie).
        $scope  = getInstelling($pdo, 'maintenance_bypass', 'owner_admin');
        $rollen = ($scope === 'owner') ? ['owner'] : ['owner', 'admin'];
        $u = getSession($pdo);
        if ($u && in_array($u['role'] ?? '', $rollen, true)) return;

        // Allowlist: login/logout + de onderhoudspagina zelf moeten open blijven.
        $script = $_SERVER['SCRIPT_NAME']  ?? '';
        $uri    = $_SERVER['REQUEST_URI']  ?? '';
        foreach (['/login.php', '/api/auth.php', '/maintenance.html'] as $open) {
            if (strpos($script, $open) !== false || strpos($uri, $open) !== false) return;
        }

        http_response_code(503);
        header('Retry-After: 3600');

        $bericht = trim((string) getInstelling($pdo, 'maintenance_bericht', ''));

        // API-verzoek → JSON (anders slikt de frontend HTML als kapotte JSON).
        if (strpos($script, '/api/') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'       => 'onderhoud',
                'maintenance' => true,
                'message'     => $bericht !== '' ? $bericht : 'InlineComp is tijdelijk in onderhoud.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // HTML-verzoek → de onderhoudspagina, met optioneel bericht geïnjecteerd.
        $pagina = __DIR__ . '/../maintenance.html';
        if (is_file($pagina)) {
            $html = file_get_contents($pagina);
            if ($bericht !== '') {
                $veilig = htmlspecialchars($bericht, ENT_QUOTES, 'UTF-8');
                $html = str_replace('<!--MAINTENANCE_MESSAGE-->',
                    '<p style="margin:14px 0 0;font-weight:600;color:#1F4E79">' . $veilig . '</p>',
                    $html);
            }
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'InlineComp is tijdelijk in onderhoud.';
        }
        exit;
    }
}
