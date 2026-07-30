<?php
// ============================================================
//  InlineComp – gedeelde coach-auth-helpers (include-only, GEEN side-effects)
//
//  Coach-accounts staan los van de staf-`users`/`sessions` (zie coach_accounts.sql).
//  Deze lib levert het lezen/aanmaken/opruimen van coach-sessies, gedeeld door
//  coach/index.php (de auth-gate) en api/coach_account.php (login/logout).
//
//  Cookie: `ic_coach_session` (64 hex, HttpOnly, SameSite=Strict). Houdbaarheid
//  24u vanaf login. function_exists-guards zodat meervoudig includen veilig is.
// ============================================================

if (!defined('COACH_SESSION_COOKIE')) define('COACH_SESSION_COOKIE', 'ic_coach_session');
if (!defined('COACH_SESSION_UREN'))   define('COACH_SESSION_UREN', 24);

if (!function_exists('getCoachSession')) {
    /**
     * Leest de huidige coach-sessie uit de cookie. Geeft het gekoppelde,
     * actieve account terug (incl. status), of null als er geen geldige,
     * niet-verlopen sessie is.
     *
     * @return array|null ['id','email','naam','status','coacht_van_type','coacht_van']
     */
    function getCoachSession(PDO $pdo): ?array {
        $token = $_COOKIE[COACH_SESSION_COOKIE] ?? '';
        if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) return null;
        $stmt = $pdo->prepare("
            SELECT a.id, a.email, a.naam, a.status,
                   a.coacht_van_type, a.coacht_van
            FROM   coach_sessions s
            JOIN   coach_accounts a ON a.id = s.coach_account_id
            WHERE  s.token      = ?
              AND  s.expires_at > NOW()
              AND  a.actief     = 1
            LIMIT 1
        ");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('maakCoachSessie')) {
    /**
     * Maakt een nieuwe 24u-sessie voor $accountId, zet de cookie en geeft het
     * token terug. Roept ook de opportunistische cleanup aan.
     */
    function maakCoachSessie(PDO $pdo, int $accountId): string {
        $token   = bin2hex(random_bytes(32));            // 64 hex, zoals staf-sessies
        $expires = date('Y-m-d H:i:s', strtotime('+' . COACH_SESSION_UREN . ' hours'));
        $pdo->prepare("INSERT INTO coach_sessions (token, coach_account_id, expires_at) VALUES (?, ?, ?)")
            ->execute([$token, $accountId, $expires]);

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(COACH_SESSION_COOKIE, $token, [
            'expires'  => strtotime('+' . COACH_SESSION_UREN . ' hours'),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        coachAuthOpruimen($pdo);
        return $token;
    }
}

if (!function_exists('wisCoachSessieCookie')) {
    function wisCoachSessieCookie(): void {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(COACH_SESSION_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}

if (!function_exists('coachAuthOpruimen')) {
    /**
     * Opportunistische huishouding (bij login): verlopen sessies weg, verlopen
     * reset-tokens weg, en accounts die >= 1 jaar niet inlogden verwijderen
     * (CASCADE ruimt hun sessies/roster/resets mee op) — het 1-jaar-verval.
     */
    function coachAuthOpruimen(PDO $pdo): void {
        try {
            $pdo->prepare("DELETE FROM coach_sessions WHERE expires_at < NOW()")->execute();
            $pdo->prepare("DELETE FROM coach_password_resets WHERE expires_at < NOW()")->execute();
            $pdo->prepare("DELETE FROM coach_accounts
                           WHERE last_login_at IS NOT NULL
                             AND last_login_at < NOW() - INTERVAL 1 YEAR")->execute();
        } catch (Throwable) { /* huishouding mag nooit de flow breken */ }
    }
}
