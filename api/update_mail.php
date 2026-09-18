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

// ── Onderdelen in weergave-volgorde ─────────────────────────────────────────
// De mail is per onderdeel gegroepeerd: Beheer → alle beheer-punten, Public →
// alle public-punten, enz. Een wijziging die meerdere onderdelen raakt, komt
// bewust onder elk van die onderdelen te staan (liever dubbel dan door elkaar).
const UPDATE_ONDERDELEN = ['admin' => 'Beheer', 'public' => 'Public', 'coach' => 'Coach', 'check' => 'Check'];

function entriesVoorOnderdeel(array $entries, string $onderdeel): array {
    return array_values(array_filter(
        $entries, fn($e) => in_array($onderdeel, $e['onderdelen'] ?? [], true)
    ));
}

// ── Platte-tekst mail-body (fallback in de multipart/alternative) ────────────
// $reden (optioneel) wordt getoond wanneer de mail bewust opnieuw verstuurd is.
function bouwMailTekst(array $entries, string $naam, string $reden = ''): string {
    $r  = 'Hoi ' . ($naam !== '' ? $naam : 'beheerder') . ",\n\n";
    if ($reden !== '') {
        $r .= 'Let op: deze mail is opnieuw verstuurd. Reden: ' . $reden . "\n\n";
    }
    $r .= 'Er staat een nieuwe versie van InlineComp klaar: '
        . INLINECOMP_VERSIE . ' (' . INLINECOMP_VERSIE_DATUM . ").\n\n";
    $r .= "Wat is er nieuw:\n";
    foreach (UPDATE_ONDERDELEN as $key => $titel) {
        $rijen = entriesVoorOnderdeel($entries, $key);
        if (!$rijen) continue;
        $r .= "\n" . $titel . "\n" . str_repeat('-', mb_strlen($titel)) . "\n";
        foreach ($rijen as $e) {
            $tekst = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $e['tekst']['nl'] ?? '')));
            if ($tekst === '') continue;
            $r .= '  - ' . $tekst . "\n";
        }
    }
    $r .= "\nDe volledige changelog (alle onderdelen) staat altijd in InlineComp onder Info → Changelog.\n\n";
    $r .= 'Open InlineComp: ' . UPDATE_APP_URL . "\n\n";
    $r .= "— InlineComp\n";
    return $r;
}

// ── HTML mail-body — per onderdeel gegroepeerd; behoudt de <b>/<i>-opmaak uit
// de changelog, huiskleuren van 'Mijn InlineComp' (#123a5e / #1c5c93 / #1c7fd6).
// Inline styles, want veel mailclients strippen <style>-blokken.
function bouwMailHtml(array $entries, string $naam, string $reden = ''): string {
    $esc      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $veilNaam = $esc($naam !== '' ? $naam : 'beheerder');
    $versie   = $esc(INLINECOMP_VERSIE);
    $datum    = $esc(INLINECOMP_VERSIE_DATUM);
    $appUrl   = $esc(UPDATE_APP_URL);

    // Melding bovenaan wanneer de mail bewust opnieuw verstuurd is (met reden).
    $redenHtml = $reden !== ''
        ? '<div style="margin:0 0 16px;padding:11px 14px;background:#fff8e1;'
          . 'border:1px solid #ffe08a;border-radius:6px;font-size:13px;color:#7a5b00;">'
          . '<b>Deze mail is opnieuw verstuurd.</b> Reden: ' . $esc($reden) . '</div>'
        : '';

    $secties = '';
    foreach (UPDATE_ONDERDELEN as $key => $titel) {
        $rijen = entriesVoorOnderdeel($entries, $key);
        if (!$rijen) continue;
        $blokken = '';
        foreach ($rijen as $e) {
            $tekst = trim($e['tekst']['nl'] ?? '');       // MET opmaak (<b>/<i>)
            if ($tekst === '') continue;
            $blokken .= '<div style="margin:0 0 12px;padding:13px 16px;background:#f6f8fb;'
                      . 'border-left:3px solid #1c7fd6;border-radius:6px;font-size:14px;'
                      . 'line-height:1.55;color:#1f2937;">' . $tekst . '</div>';
        }
        if ($blokken === '') continue;
        $secties .= '<h2 style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;'
                  . 'color:#123a5e;border-bottom:2px solid #dbe4ef;padding-bottom:6px;'
                  . 'margin:24px 0 14px;">' . $esc($titel) . '</h2>' . $blokken;
    }

    return '<!doctype html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef2f7;">'
        . '<div style="max-width:640px;margin:0 auto;padding:20px 12px;'
        .   'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
        .   '<div style="background:#123a5e;color:#eaf2fa;padding:18px 22px;border-radius:10px 10px 0 0;">'
        .     '<div style="font-size:20px;font-weight:700;letter-spacing:.02em;">InlineComp</div>'
        .     '<div style="font-size:13px;color:#9fc0e0;margin-top:3px;">Nieuwe versie ' . $versie . ' · ' . $datum . '</div>'
        .   '</div>'
        .   '<div style="padding:22px 22px 24px;background:#ffffff;border:1px solid #e5e9f0;'
        .     'border-top:0;border-radius:0 0 10px 10px;">'
        .     '<p style="margin:0 0 14px;font-size:15px;color:#1f2937;">Hoi ' . $veilNaam . ',</p>'
        .     $redenHtml
        .     '<p style="margin:0 0 4px;font-size:15px;color:#1f2937;">'
        .       'Er staat een nieuwe versie van InlineComp klaar. Wat is er nieuw, per onderdeel:</p>'
        .     $secties
        .     '<p style="margin:22px 0 18px;font-size:13px;color:#6b7280;">'
        .       'De volledige changelog (alle onderdelen) staat altijd in InlineComp onder <b>Info &rarr; Changelog</b>.</p>'
        .     '<a href="' . $appUrl . '" style="display:inline-block;background:#1c7fd6;color:#ffffff;'
        .       'text-decoration:none;font-size:14px;font-weight:600;padding:10px 20px;border-radius:8px;">Open InlineComp</a>'
        .     '<p style="margin:22px 0 0;font-size:13px;color:#9aa3af;">&mdash; InlineComp</p>'
        .   '</div>'
        . '</div></body></html>';
}

function verstuurUpdateMail(string $to, string $subject, string $text, string $html): bool {
    $boundary = 'ic_' . bin2hex(random_bytes(12));
    $headers = implode("\r\n", [
        'From: ' . UPDATE_MAIL_FROM,
        'Reply-To: ' . UPDATE_MAIL_ENVELOPE,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: InlineComp Update',
    ]);
    $body  = '--' . $boundary . "\r\n"
           . "Content-Type: text/plain; charset=utf-8\r\n"
           . "Content-Transfer-Encoding: 8bit\r\n\r\n"
           . $text . "\r\n"
           . '--' . $boundary . "\r\n"
           . "Content-Type: text/html; charset=utf-8\r\n"
           . "Content-Transfer-Encoding: 8bit\r\n\r\n"
           . $html . "\r\n"
           . '--' . $boundary . "--\r\n";
    return @mail($to, $subject, $body, $headers, '-f' . UPDATE_MAIL_ENVELOPE);
}

function logUpdateMail(PDO $pdo, array $ik, int $aantal, string $reden = ''): void {
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
            'update-mail ' . INLINECOMP_VERSIE . ' (' . $aantal . ' ontvangers)'
                . ($reden !== '' ? ' — reden: ' . $reden : ''),
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
        // Reden is alleen zinvol bij bewust opnieuw versturen; max 500 tekens.
        $reden = trim((string)($body['reden'] ?? ''));
        if (mb_strlen($reden) > 500) $reden = mb_substr($reden, 0, 500);
        if (!$force) $reden = '';
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
            $naam = (string)($o['naam'] ?? '');
            if (verstuurUpdateMail(
                $o['email'], $subject,
                bouwMailTekst($entries, $naam, $reden),
                bouwMailHtml($entries, $naam, $reden)
            )) {
                $gelukt++;
            }
        }

        $nieuw = [
            'versie'   => INLINECOMP_VERSIE,
            'tijdstip' => date('Y-m-d H:i:s'),
            'aantal'   => $gelukt,
            'door'     => $ik['naam'] ?? ($ik['username'] ?? ''),
            'reden'    => $reden,
        ];
        metaSchrijf($pdo, UPDATE_MAIL_METAKEY, json_encode($nieuw, JSON_UNESCAPED_UNICODE));
        logUpdateMail($pdo, $ik, $gelukt, $reden);

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
