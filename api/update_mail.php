<?php
// ============================================================
//  InlineComp – beheerders-update-mail
//
//  GET  action=status    → { versie, datum, aantal_wijzigingen, ontvangers,
//                            laatst: {versie,tijdstip,aantal,door}|null,
//                            al_gemaild: bool }
//  POST action=verstuur  → mailt de changelog van de HUIDIGE versie naar alle
//                          owners/admins (systeembreed) met een e-mailadres.
//                          Idempotent: al gemaild voor deze versie → 'al_gemaild'
//                          tenzij body.force === true (bewust opnieuw sturen).
//
//  Alleen owner/admin. Hergebruikt de app-mail-infra (DKIM + -f envelope).
//  Handmatig getriggerd na een SFTP-deploy — zie .claude/commands/commit-nieuwe-versie.md.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../inc/versie.php';

const UPDATE_MAIL_FROM     = 'InlineComp <inlinecomp@devriesen.com>';
const UPDATE_MAIL_ENVELOPE = 'inlinecomp@devriesen.com';
const UPDATE_APP_URL       = 'https://inlineresults.devriesen.com/';
const UPDATE_MAIL_METAKEY  = 'update_mail_laatst';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
$action = $body['action'] ?? $_GET['action'] ?? '';

$ik = requireAuth($pdo, ['owner', 'admin']);

function jsonUit(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── systeem_meta key/value-helpers ──────────────────────────────────────────
function metaLees(PDO $pdo, string $sleutel): ?string {
    $st = $pdo->prepare("SELECT waarde FROM systeem_meta WHERE sleutel = ?");
    $st->execute([$sleutel]);
    $w = $st->fetchColumn();
    return $w === false ? null : (string)$w;
}
function metaSchrijf(PDO $pdo, string $sleutel, string $waarde): void {
    $pdo->prepare("
        INSERT INTO systeem_meta (sleutel, waarde) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)
    ")->execute([$sleutel, $waarde]);
}

// ── Ontvangers: alle actieve owners/admins met e-mailadres, ontdubbeld ──────
function haalOntvangers(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT email, MAX(naam) AS naam
        FROM users
        WHERE role IN ('owner','admin')
          AND email IS NOT NULL AND email <> ''
          AND actief = 1
        GROUP BY email
        ORDER BY email
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [];
}

// ── De changelog-entries van de HUIDIGE versie (voor de mail-body) ──────────
function huidigeVersieEntries(): array {
    $cl = require __DIR__ . '/../inc/changelog.php';
    return array_values(array_filter($cl, fn($e) => ($e['versie'] ?? '') === INLINECOMP_VERSIE));
}

// ── Bouwt de platte-tekst mail-body (per ontvanger gepersonaliseerd) ────────
function bouwMailBody(array $entries, string $naam): string {
    $ondLabel = ['admin' => 'Beheer', 'public' => 'Public', 'coach' => 'Coach', 'check' => 'Check'];
    // Groepeer per onderdeel in vaste volgorde.
    $perOnd = [];
    foreach ($entries as $e) {
        foreach ($e['onderdelen'] as $o) {
            $perOnd[$o][] = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $e['tekst']['nl'] ?? '')));
        }
    }

    $r  = 'Hoi ' . ($naam !== '' ? $naam : 'beheerder') . ",\n\n";
    $r .= 'Er staat een nieuwe versie van InlineComp klaar: '
        . INLINECOMP_VERSIE . ' (' . INLINECOMP_VERSIE_DATUM . ").\n\n";
    $r .= "Wat is er nieuw:\n";
    foreach (['admin', 'public', 'coach', 'check'] as $o) {
        if (empty($perOnd[$o])) continue;
        $r .= "\n" . $ondLabel[$o] . ":\n";
        foreach ($perOnd[$o] as $regel) $r .= '  - ' . $regel . "\n";
    }
    $r .= "\nDe volledige changelog (alle onderdelen) staat altijd in InlineComp onder Info → Changelog.\n\n";
    $r .= 'Open InlineComp: ' . UPDATE_APP_URL . "\n\n";
    $r .= "— InlineComp\n";
    return $r;
}

function verstuurUpdateMail(string $to, string $subject, string $body): bool {
    $headers = implode("\r\n", [
        'From: ' . UPDATE_MAIL_FROM,
        'Reply-To: ' . UPDATE_MAIL_ENVELOPE,
        'Content-Type: text/plain; charset=utf-8',
        'X-Mailer: InlineComp Update',
    ]);
    return @mail($to, $subject, $body, $headers, '-f' . UPDATE_MAIL_ENVELOPE);
}

function logUpdateMail(PDO $pdo, array $ik, int $aantal): void {
    try {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 65535);
        $pdo->prepare("
            INSERT INTO login_logs (user_id, naam, username, actie, ip_adres, bron, user_agent)
            VALUES (?, ?, ?, ?, ?, 'staff', ?)
        ")->execute([
            $ik['id'] ?? null,
            $ik['naam'] ?? '',
            $ik['username'] ?? '',
            'update-mail ' . INLINECOMP_VERSIE . ' (' . $aantal . ' ontvangers)',
            $ip, $ua,
        ]);
    } catch (Throwable) { /* logging mag de flow nooit breken */ }
}

try {
    $laatstRaw = metaLees($pdo, UPDATE_MAIL_METAKEY);
    $laatst    = $laatstRaw ? json_decode($laatstRaw, true) : null;
    if (!is_array($laatst)) $laatst = null;
    $alGemaild = $laatst && ($laatst['versie'] ?? null) === INLINECOMP_VERSIE;

    // ── GET status ──────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'status') {
        jsonUit([
            'versie'            => INLINECOMP_VERSIE,
            'datum'             => INLINECOMP_VERSIE_DATUM,
            'aantal_wijzigingen'=> count(huidigeVersieEntries()),
            'ontvangers'        => count(haalOntvangers($pdo)),
            'laatst'            => $laatst,
            'al_gemaild'        => $alGemaild,
        ]);
    }

    // ── POST verstuur ───────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'verstuur') {
        $force = !empty($body['force']);
        if ($alGemaild && !$force) {
            jsonUit(['al_gemaild' => true, 'laatst' => $laatst], 409);
        }

        $ontvangers = haalOntvangers($pdo);
        if (!$ontvangers) {
            jsonUit(['error' => 'Geen beheerders met een e-mailadres gevonden.'], 422);
        }

        $entries = huidigeVersieEntries();
        $subject = 'InlineComp update — ' . INLINECOMP_VERSIE;
        $gelukt  = 0;
        foreach ($ontvangers as $o) {
            if (verstuurUpdateMail($o['email'], $subject, bouwMailBody($entries, (string)($o['naam'] ?? '')))) {
                $gelukt++;
            }
        }

        $nieuw = [
            'versie'   => INLINECOMP_VERSIE,
            'tijdstip' => date('Y-m-d H:i:s'),
            'aantal'   => $gelukt,
            'door'     => $ik['naam'] ?? ($ik['username'] ?? ''),
        ];
        metaSchrijf($pdo, UPDATE_MAIL_METAKEY, json_encode($nieuw, JSON_UNESCAPED_UNICODE));
        logUpdateMail($pdo, $ik, $gelukt);

        jsonUit([
            'ok'         => true,
            'aantal'     => $gelukt,
            'ontvangers' => count($ontvangers),
            'laatst'     => $nieuw,
        ]);
    }

    jsonUit(['error' => 'Onbekende actie'], 400);

} catch (Throwable $e) {
    jsonUit(['error' => 'Serverfout: ' . $e->getMessage()], 500);
}
