<?php
// ============================================================
//  InlineComp – Jury-sessie helpers
//
//  Jury heeft een eigen PHP-sessie (cookie ICJURY) los van de
//  organisator-app (ICAUTH) en coach (ICCOACH). De sessie is per
//  wedstrijd: $_SESSION['jury_comp_id'] + $_SESSION['jury_role'].
//
//  Gebruik:
//      juryStartSession();        // veilige session_start met ICJURY
//      $s = juryHuidigeSessie();  // [comp_id, role] of null (+ auto-invalidatie)
//      juryRequireSession();      // halt of redirect bij geen sessie
//      juryMarkeerLogin(compId);  // roep na succesvolle wachtwoord-verify
// ============================================================

// Sessie verloopt na deze periode zonder activiteit (sliding). Dekt normale
// wedstrijddag inclusief uitloop door weer/incident — zolang jury actief blijft
// polten, wordt de timer bij elke request bijgewerkt. Tablet die 4u ongebruikt
// blijft (bv. vergeten in kleedkamer) valt automatisch uit.
if (!defined('JURY_SESSIE_INACTIEF_MAX')) define('JURY_SESSIE_INACTIEF_MAX', 4 * 3600);

if (!function_exists('juryUaFamilie')) {
    /**
     * Distilleert een STABIELE browser+OS-familie uit de User-Agent, zodat
     * hijack-detectie werkt zonder dat een minor-versie-bump (Chrome auto-
     * update mid-wedstrijd, iOS-update) de jury uitlogt. Voorbeelden:
     *   Chrome op Android 14 → "chromium|android"
     *   Safari op iPad iOS 17 → "safari|ios"
     *   Firefox op Windows   → "firefox|windows"
     * Alle Chromium-based browsers (Chrome/Edge/Opera/Samsung/Brave/etc.)
     * vallen bewust onder één "chromium"-familie — een user die van Chrome
     * naar Edge switcht op hetzelfde device wordt niet uitgelogd.
     */
    function juryUaFamilie(string $ua): string {
        $ua = strtolower($ua);
        // Volgorde: eerst Firefox (want die bevat óók 'gecko' maar geen chromium),
        // dan Safari-only (WebKit zonder chromium-markers), dan alles-met-chromium.
        if (str_contains($ua, 'firefox/'))                 $browser = 'firefox';
        elseif (str_contains($ua, 'edg/')
             || str_contains($ua, 'edge/')
             || str_contains($ua, 'chrome/')
             || str_contains($ua, 'chromium/')
             || str_contains($ua, 'crios/')     // Chrome iOS
             || str_contains($ua, 'samsungbrowser/')
             || str_contains($ua, 'opr/')
             || str_contains($ua, 'yabrowser/')
             || str_contains($ua, 'brave/'))               $browser = 'chromium';
        elseif (str_contains($ua, 'safari/'))              $browser = 'safari';
        else                                                $browser = 'overig';

        // OS-detectie. iOS eerst (bevat óók 'mac' in sommige UA-strings van iPad
        // met "Request desktop site"), Android vóór Linux (Android bevat 'linux').
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')
            || str_contains($ua, 'ipod'))                  $os = 'ios';
        elseif (str_contains($ua, 'android'))              $os = 'android';
        elseif (str_contains($ua, 'windows'))              $os = 'windows';
        elseif (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) $os = 'macos';
        elseif (str_contains($ua, 'linux'))                $os = 'linux';
        else                                                $os = 'overig';

        return $browser . '|' . $os;
    }
}

if (!function_exists('juryStartSession')) {
    function juryStartSession(): void {
        if (session_status() !== PHP_SESSION_NONE) return;
        session_name('ICJURY');
        // secure=true: cookie mag alleen over HTTPS. Site draait alleen op HTTPS,
        // dus dit is defense-in-depth voor het geval iFastNet ooit HTTP-fallback
        // krijgt. httponly: cookie onbereikbaar voor JS (XSS-hardening).
        // samesite=Lax: voldoende voor CSRF-basis; jury-app doet geen cross-site
        // navigatie naar zichzelf waarbij Strict problemen zou geven.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }
}

if (!function_exists('juryMarkeerLogin')) {
    /**
     * Aanroepen NA een succesvolle jury-wachtwoord-verify. Regenereert het
     * sessie-ID (session-fixation-verdediging), legt browser+OS-familie vast
     * voor hijack-detectie, en zet de sliding-expire timer op nu.
     *
     * @param string $compId     Wedstrijd-id waarop de jury is ingelogd
     */
    function juryMarkeerLogin(string $compId): void {
        juryStartSession();
        // session_regenerate_id(true) — parameter true verwijdert de oude sessie
        // aan de server-kant, zodat een eventueel eerder buitgemaakt cookie
        // meteen dood is (voorkomt session fixation).
        @session_regenerate_id(true);
        $_SESSION['jury_comp_id']       = $compId;
        $_SESSION['jury_role']          = null;
        $_SESSION['jury_auth_at']       = time();
        $_SESSION['jury_last_activity'] = time();
        $_SESSION['jury_ua_familie']    = juryUaFamilie((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}

if (!function_exists('juryHuidigeSessie')) {
    /**
     * Haalt de huidige jury-sessie op, met drie automatische invalidaties:
     *  1. UA-familie mismatch → sessie destroy (mogelijke hijack vanaf ander device)
     *  2. Inactiviteit > JURY_SESSIE_INACTIEF_MAX → sessie destroy (verlaten tablet)
     *  3. Anders: sliding-timer bijwerken (last_activity = nu)
     * Retourneert null als er geen geldige sessie is.
     */
    function juryHuidigeSessie(): ?array {
        juryStartSession();
        $compId = $_SESSION['jury_comp_id'] ?? null;
        if (!$compId) return null;

        // UA-familie-check. Bij mismatch: sessie destroy en null retourneren.
        // Familie (Chromium/Safari/Firefox × Windows/macOS/Android/iOS/…) is
        // stabiel bij minor browser-updates, en verandert alleen bij écht ander
        // device of substantiële browser-switch. Fallback naar 'overig|overig'
        // wanneer familie-veld nog niet gezet is (upgrade-pad: oude sessies
        // gemaakt vóór deze patch missen jury_ua_familie).
        $huidigeFamilie = juryUaFamilie((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $sessieFamilie  = $_SESSION['jury_ua_familie'] ?? null;
        if ($sessieFamilie !== null && $sessieFamilie !== $huidigeFamilie) {
            $_SESSION = [];
            @session_destroy();
            return null;
        }

        // Sliding-expire: als er langer dan MAX niks in deze sessie gebeurd is,
        // sessie destroy. Anders: timer bijwerken op nu.
        $laatst = (int)($_SESSION['jury_last_activity'] ?? 0);
        if ($laatst > 0 && (time() - $laatst) > JURY_SESSIE_INACTIEF_MAX) {
            $_SESSION = [];
            @session_destroy();
            return null;
        }
        $_SESSION['jury_last_activity'] = time();

        return [
            'comp_id' => $compId,
            'role'    => $_SESSION['jury_role']    ?? null,
            'auth_at' => $_SESSION['jury_auth_at'] ?? null,
        ];
    }
}

if (!function_exists('juryRequireSession')) {
    // Voor jury-API endpoints (Area of Call, fin-volgordes, etc.).
    // Geeft 401 als geen sessie (of net verlopen), anders sessie-data.
    function juryRequireSession(): array {
        $s = juryHuidigeSessie();
        if (!$s) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'Niet ingelogd als jury']);
            exit;
        }
        return $s;
    }
}

if (!function_exists('juryRequireRole')) {
    // Vereist een specifieke rol (of array van geldige rollen). Gebruik:
    //   juryRequireRole(['scheidsrechter', 'starter']);
    function juryRequireRole(array $geldigeRollen): array {
        $s = juryRequireSession();
        if (!$s['role'] || !in_array($s['role'], $geldigeRollen, true)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['error' => 'Geen toegang met deze jury-rol']);
            exit;
        }
        return $s;
    }
}
