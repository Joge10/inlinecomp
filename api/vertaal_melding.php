<?php
// ============================================================
//  InlineComp – auto-vertaal voor mededelingen via Anthropic Claude
//
//  POST /api/vertaal_melding.php
//  Body: {
//    "titel":    "Programma loopt 15 min uit",
//    "bericht":  "De 200m start om 14:15 ipv 14:00.",
//    "from":     "nl",   // brontaal: 'nl' of 'en'
//    "to":       "en"    // doeltaal: 'en' of 'nl'
//  }
//
//  Response:
//    { ok: true, titel: "...", bericht: "..." }
//  Bij fout:
//    { error: "..." }
//
//  Vereist:
//    - Login + schrijfrechten voor meldingen (zelfde rol als meldingen.php).
//    - Constant ANTHROPIC_API_KEY gedefinieerd in config_inlinecomp.php.
//      Get key via console.anthropic.com → API Keys.
//
//  Model-keuze: claude-3-5-haiku — goedkoop + snel (~€0,005 per vertaling),
//  meer dan voldoende voor korte mededelingen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// Zelfde rol-check als meldingen.php (opslag-API)
$magSchrijven = in_array($_authUser['role'] ?? '',
    ['owner', 'admin', 'timer', 'planner'], true);
if (!$magSchrijven) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor meldingen.']);
    exit;
}

if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Vertaal-API niet geconfigureerd. Voeg '
                 . "define('ANTHROPIC_API_KEY', 'sk-ant-...') toe aan "
                 . 'config_inlinecomp.php (key via console.anthropic.com).',
    ]);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$titel   = trim($body['titel']   ?? '');
$bericht = trim($body['bericht'] ?? '');
$from    = trim($body['from']    ?? 'nl');
$to      = trim($body['to']      ?? 'en');

if ($titel === '' && $bericht === '') {
    http_response_code(400);
    echo json_encode(['error' => 'titel of bericht is verplicht']);
    exit;
}
if (!in_array($from, ['nl', 'en'], true) || !in_array($to, ['nl', 'en'], true) || $from === $to) {
    http_response_code(400);
    echo json_encode(['error' => 'from/to moet "nl" of "en" zijn, en verschillend']);
    exit;
}

$fromNaam = $from === 'nl' ? 'Dutch'  : 'English';
$toNaam   = $to   === 'en' ? 'English' : 'Dutch';

// Prompt-engineering: vraag JSON-output zodat we titel + bericht
// betrouwbaar uit elkaar kunnen halen. Geen narratieve tekst eromheen.
// Context "inline skating race announcement" helpt Claude juiste
// terminologie kiezen (heat, final, semifinal, time trial, etc.).
$prompt = "You are translating a short race announcement for an inline-skating "
        . "competition app from {$fromNaam} to {$toNaam}.\n\n"
        . "Keep it concise and clear. Use natural skating-event terminology "
        . "(heat, series, quarterfinal, semifinal, final, runner-up, time trial). "
        . "Preserve any numbers, times, and proper names exactly. Do not add "
        . "explanations or commentary.\n\n"
        . "Respond ONLY with this JSON, no other text:\n"
        . '{"titel": "...translated title...", "bericht": "...translated body..."}'
        . "\n\nInput:\n"
        . "Title: " . ($titel ?: '(empty)') . "\n"
        . "Body: "  . ($bericht ?: '(empty)');

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'      => 'claude-3-5-haiku-20241022',
        'max_tokens' => 1024,
        'messages'   => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ]),
]);
$raw     = curl_exec($ch);
$httpRc  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($raw === false || $httpRc >= 400) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Claude API-fout (HTTP ' . $httpRc . ')'
                 . ($curlErr ? ' — ' . $curlErr : '')
                 . ($raw ? ' — ' . substr($raw, 0, 300) : ''),
    ]);
    exit;
}

$apiData = json_decode($raw, true);
$content = $apiData['content'][0]['text'] ?? '';
if (!$content) {
    http_response_code(502);
    echo json_encode(['error' => 'Geen content in Claude-response', 'raw' => $raw]);
    exit;
}

// Soms wrapt Claude de JSON in code-fences ```json ... ```. Strip dat eraf.
$content = trim($content);
$content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
$parsed  = json_decode($content, true);

if (!is_array($parsed) || (!isset($parsed['titel']) && !isset($parsed['bericht']))) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Vertaal-response niet parseerbaar als JSON',
        'raw'   => $content,
    ]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'titel'   => (string)($parsed['titel']   ?? ''),
    'bericht' => (string)($parsed['bericht'] ?? ''),
    'from'    => $from,
    'to'      => $to,
], JSON_UNESCAPED_UNICODE);
