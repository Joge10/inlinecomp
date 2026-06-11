<?php
// ============================================================
//  InlineComp – Easter egg counter
//  POST: registreer een easter-egg-hit (3× klik op org-logo in /public),
//        return de huidige totale teller.
//  GET:  return alleen de huidige teller (geen insert).
//
//  Geen auth — bewust publiek zodat iedere bezoeker mee kan tellen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $pdo->prepare("INSERT INTO easter_egg_hits (ip) VALUES (?)")->execute([$ip]);
    }
    $count = (int)$pdo->query("SELECT COUNT(*) FROM easter_egg_hits")->fetchColumn();
    echo json_encode(['ok' => true, 'count' => $count]);
} catch (Throwable $e) {
    // Stilletjes falen — de easter egg mag de UI niet breken.
    http_response_code(500);
    echo json_encode(['error' => 'oops']);
}
