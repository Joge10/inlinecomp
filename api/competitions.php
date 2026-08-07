<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
requireAuth($pdo);

$url = 'https://inschrijven.schaatsen.nl/api/competitions';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL fout: ' . $error]);
    exit;
}
if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'API gaf HTTP ' . $httpCode]);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['error' => 'Onverwacht antwoord van API']);
    exit;
}

// Filter: alleen SpeedSkating.Inline wedstrijden vanaf een week geleden
// Tenzij een eerdere datum is meegegeven via ?van=YYYY-MM-DD
$eenWeekGeleden = date('Y-m-d', strtotime('-7 days'));
$vanParam = trim($_GET['van'] ?? '');
if ($vanParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vanParam) && $vanParam < $eenWeekGeleden) {
    $eenWeekGeleden = $vanParam;
}
$inline = array_values(array_filter($data, function($item) use ($eenWeekGeleden) {
    if (!isset($item['discipline']) || stripos($item['discipline'], 'SpeedSkating.Inline') === false) {
        return false;
    }
    $starts = isset($item['starts']) ? substr($item['starts'], 0, 10) : '';
    return $starts >= $eenWeekGeleden;
}));

// Sorteer op startdatum oplopend
usort($inline, function($a, $b) {
    return strcmp($a['starts'] ?? '', $b['starts'] ?? '');
});

// Demo-wedstrijd(en) als fixture toevoegen — staan permanent in de importlijst,
// los van de DB, zodat ze na verwijderen in Beheer herbruikbaar blijven voor
// het doortesten van flows/scenario's. Zie api/demo_fixture.php.
require_once __DIR__ . '/demo_fixture.php';
foreach (demo_fixture_lijst_items() as $demo) $inline[] = $demo;

echo json_encode($inline);
