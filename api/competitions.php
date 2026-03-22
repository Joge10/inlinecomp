<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

// Filter: alleen SpeedSkating.Inline wedstrijden vanaf vandaag
$vandaag = date('Y-m-d');
$inline = array_values(array_filter($data, function($item) use ($vandaag) {
    if (!isset($item['discipline']) || stripos($item['discipline'], 'SpeedSkating.Inline') === false) {
        return false;
    }
    $starts = isset($item['starts']) ? substr($item['starts'], 0, 10) : '';
    return $starts >= $vandaag;
}));

// Sorteer op startdatum oplopend
usort($inline, function($a, $b) {
    return strcmp($a['starts'] ?? '', $b['starts'] ?? '');
});

echo json_encode($inline);
