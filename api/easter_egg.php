<?php
// ============================================================
//  InlineComp – Easter egg counter
//  POST met `token` (36-char localStorage-uuid): registreer een hit én
//  return het volgnummer van deze specifieke browser (1e, 2e, 3e, …).
//  Duplicate tokens tellen NIET dubbel — dezelfde browser krijgt bij
//  elke klik hetzelfde volgnummer terug.
//
//  Geen auth — bewust publiek zodat iedere bezoeker mee kan tellen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';

try {
    $positie = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
        $token = trim($_POST['token'] ?? '');
        // Alleen 36-char tokens accepteren (UUID-v4 formaat, incl. 4× '-').
        if (strlen($token) === 36 && preg_match('/^[0-9a-f\-]{36}$/i', $token)) {
            // INSERT IGNORE: unique index op token blokkeert duplicaten stil.
            $pdo->prepare("INSERT IGNORE INTO easter_egg_hits (ip, token) VALUES (?, ?)")
                ->execute([$ip, $token]);
            // Volgnummer = hoeveelste rij deze token was, ongeacht huidige klik.
            $stmt = $pdo->prepare("SELECT id FROM easter_egg_hits WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
            $hitId = $stmt->fetchColumn();
            if ($hitId) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM easter_egg_hits WHERE id <= ?");
                $stmt->execute([$hitId]);
                $positie = (int)$stmt->fetchColumn();
            }
        } else {
            // Legacy fallback: geen token → tel wél mee, maar niet dedup-baar.
            $pdo->prepare("INSERT INTO easter_egg_hits (ip) VALUES (?)")->execute([$ip]);
        }
    }
    $count = (int)$pdo->query("SELECT COUNT(*) FROM easter_egg_hits")->fetchColumn();
    echo json_encode(['ok' => true, 'count' => $count, 'positie' => $positie]);
} catch (Throwable $e) {
    // Stilletjes falen — de easter egg mag de UI niet breken.
    http_response_code(500);
    echo json_encode(['error' => 'oops']);
}
