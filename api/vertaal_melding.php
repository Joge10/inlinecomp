<?php
// ============================================================
//  InlineComp – auto-vertaal voor mededelingen via Anthropic Claude
//
//  POST /api/vertaal_melding.php
//  Body: {
//    "titel":    "Programma loopt 15 min uit",
//    "bericht":  "De 200m start om 14:15 ipv 14:00.",
//    "from":     "nl",          // brontaal: 'nl' of 'en'
//    "to":       "en"            // doeltaal: 'en','nl','de','fr'
//  }
//  Of bulk-mode voor alle 4 talen in 1 call:
//    "to": ["en","de","fr"]      // doeltalen-array; returnt {translations: {en:{...}, de:{...}, fr:{...}}}
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
$toRaw   = $body['to']           ?? 'en';

if ($titel === '' && $bericht === '') {
    http_response_code(400);
    echo json_encode(['error' => 'titel of bericht is verplicht']);
    exit;
}

// from is altijd 1 taal; to mag string OF array zijn (bulk-mode).
$geldigeTalen = ['nl', 'en', 'de', 'fr'];
if (!in_array($from, $geldigeTalen, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'from moet nl/en/de/fr zijn']);
    exit;
}
// Normaliseer to → altijd array.
$toLijst = is_array($toRaw) ? $toRaw : [$toRaw];
$toLijst = array_filter($toLijst, fn($x) => in_array($x, $geldigeTalen, true) && $x !== $from);
$toLijst = array_values(array_unique($toLijst));
if (empty($toLijst)) {
    http_response_code(400);
    echo json_encode(['error' => 'to moet 1+ geldige doeltaal hebben, anders dan from']);
    exit;
}
$bulkMode = is_array($toRaw);

$taalNamen = ['nl' => 'Dutch', 'en' => 'English', 'de' => 'German', 'fr' => 'French'];
$fromNaam = $taalNamen[$from];

// Prompt-engineering: vraag JSON-output. Bij meerdere doeltalen:
// {translations:{en:{titel,bericht}, de:{...}, fr:{...}}}.
// Bij 1 doeltaal (back-compat): {titel, bericht}.
if (count($toLijst) === 1) {
    $to     = $toLijst[0];
    $toNaam = $taalNamen[$to];
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
} else {
    // Bulk: vraag alle doeltalen in 1 JSON-blok zodat we 1 API-call doen
    // ipv N. Scheelt latency en kosten.
    $taalKeys = array_map(fn($l) => "\"{$l}\": {\"titel\":\"...\",\"bericht\":\"...\"}", $toLijst);
    $taalNamenStr = implode(', ', array_map(fn($l) => $taalNamen[$l], $toLijst));
    $prompt = "You are translating a short race announcement for an inline-skating "
            . "competition app from {$fromNaam} to multiple target languages: {$taalNamenStr}.\n\n"
            . "Keep each translation concise and clear. Use natural skating-event terminology "
            . "(heat, series, quarterfinal, semifinal, final, runner-up, time trial). "
            . "Preserve any numbers, times, and proper names exactly. Do not add "
            . "explanations or commentary.\n\n"
            . "Respond ONLY with this JSON, no other text. Use the ISO language codes as keys:\n"
            . '{"translations": {' . implode(', ', $taalKeys) . '}}'
            . "\n\nInput:\n"
            . "Title: " . ($titel ?: '(empty)') . "\n"
            . "Body: "  . ($bericht ?: '(empty)');
}

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
        'model'      => 'claude-haiku-4-5',
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

if (!is_array($parsed)) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Vertaal-response niet parseerbaar als JSON',
        'raw'   => $content,
    ]);
    exit;
}

if ($bulkMode) {
    // Bulk: {translations:{en:{...}, de:{...}, fr:{...}}}
    if (!isset($parsed['translations']) || !is_array($parsed['translations'])) {
        http_response_code(502);
        echo json_encode([
            'error' => 'Bulk vertaal-response mist translations-object',
            'raw'   => $content,
        ]);
        exit;
    }
    $out = [];
    foreach ($toLijst as $l) {
        $tr = $parsed['translations'][$l] ?? null;
        $out[$l] = [
            'titel'   => is_array($tr) ? (string)($tr['titel']   ?? '') : '',
            'bericht' => is_array($tr) ? (string)($tr['bericht'] ?? '') : '',
        ];
    }
    echo json_encode([
        'ok'           => true,
        'translations' => $out,
        'from'         => $from,
        'to'           => $toLijst,
    ], JSON_UNESCAPED_UNICODE);
} else {
    if (!isset($parsed['titel']) && !isset($parsed['bericht'])) {
        http_response_code(502);
        echo json_encode([
            'error' => 'Vertaal-response mist titel/bericht',
            'raw'   => $content,
        ]);
        exit;
    }
    echo json_encode([
        'ok'      => true,
        'titel'   => (string)($parsed['titel']   ?? ''),
        'bericht' => (string)($parsed['bericht'] ?? ''),
        'from'    => $from,
        'to'      => $toLijst[0],
    ], JSON_UNESCAPED_UNICODE);
}
