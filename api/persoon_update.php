<?php
// ============================================================
//  InlineComp – persoon updaten (club + sponsor handmatig corrigeren)
//
//  POST  { license_key, club_full?, club_short?, sponsor? }
//        → werkt opgegeven velden bij op persons-record. Velden die niet
//          in de body staan blijven ongewijzigd. Lege string ('') = expliciet
//          wissen (= NULL). Velden die in de body ontbreken = niet aanraken.
//
//  Gebruikt door: Systeem → Rijders → detail-paneel inline edit.
//
//  Achtergrond: KNSB-feed levert soms verkeerde of onvolledige club-/sponsor-
//  data. Operator kan dit hier corrigeren. KNSB-sync (api/import.php) wordt
//  zo aangepast dat lege KNSB-waardes deze correcties niet wegblazen.
//  Volle KNSB-update mét waarde overschrijft nog wel — voor 100% bescherming
//  is een per-veld manual-override-flag nodig (toekomstige iteratie).
//
//  Alleen owner/admin: andermans persoonsgegevens bewerken.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

if (!in_array($_authUser['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Alleen beheerders mogen rijdergegevens wijzigen.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Methode niet toegestaan']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$lk   = trim($body['license_key'] ?? '');
if (!$lk) {
    http_response_code(400);
    echo json_encode(['error' => 'license_key ontbreekt']);
    exit;
}

// Toegestane velden — strict whitelist
$toegestaan = ['club_full', 'club_short', 'sponsor'];
$updates    = [];
$params     = [];
foreach ($toegestaan as $veld) {
    if (!array_key_exists($veld, $body)) continue; // niet meegestuurd = niet aanraken
    $raw = $body[$veld];
    if ($raw === null) {
        $val = null; // expliciet wissen
    } else {
        $val = trim((string)$raw);
        if ($val === '') $val = null; // lege string = wissen
        // Sanity-limits (DB-kolom-grenzen ruim genomen)
        if ($val !== null && mb_strlen($val) > 255) {
            http_response_code(400);
            echo json_encode(['error' => "Veld '$veld' te lang (max 255 tekens)."]);
            exit;
        }
    }
    $updates[] = "$veld = :$veld";
    $params[":$veld"] = $val;
}

if (!$updates) {
    http_response_code(400);
    echo json_encode(['error' => 'Geen velden om te wijzigen']);
    exit;
}

try {
    // Bestaat de rijder?
    $check = $pdo->prepare("SELECT license_key, full_name, club_full, club_short, sponsor
                            FROM persons WHERE license_key = ?");
    $check->execute([$lk]);
    $voor = $check->fetch(PDO::FETCH_ASSOC);
    if (!$voor) {
        http_response_code(404);
        echo json_encode(['error' => 'Rijder niet gevonden']);
        exit;
    }

    $params[':lk'] = $lk;
    $sql = "UPDATE persons SET " . implode(', ', $updates)
         . ", updated_at = CURRENT_TIMESTAMP WHERE license_key = :lk";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Lees de nieuwe waarden terug zodat de UI direct kan refreshen
    $check->execute([$lk]);
    $na = $check->fetch(PDO::FETCH_ASSOC);

    // Audit-log: wie heeft wat gewijzigd. Geen volledige naam in log, alleen
    // license_key + de oude/nieuwe waarden.
    if (function_exists('logboekSchrijf')) {
        $diff = [];
        foreach ($toegestaan as $veld) {
            if (!array_key_exists($veld, $body)) continue;
            if (($voor[$veld] ?? null) !== ($na[$veld] ?? null)) {
                $diff[$veld] = ['van' => $voor[$veld], 'naar' => $na[$veld]];
            }
        }
        if ($diff) {
            logboekSchrijf($pdo, $_authUser['id'] ?? null,
                'persoon_update', [
                    'license_key' => $lk,
                    'wijzigingen' => $diff,
                ]);
        }
    }

    echo json_encode([
        'ok'      => true,
        'persoon' => $na,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
