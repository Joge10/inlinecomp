<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if (!preg_match('/^[a-f0-9\-]{36}$/i', $id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldig of ontbrekend competition ID']);
    exit;
}

$url = 'https://inschrijven.schaatsen.nl/api/competitions/' . urlencode($id) . '/competitors';

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
    http_response_code($httpCode);
    echo json_encode(['error' => 'API gaf HTTP ' . $httpCode]);
    exit;
}

$data = json_decode($response, true);
echo json_encode($data);
