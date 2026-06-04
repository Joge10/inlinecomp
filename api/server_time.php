<?php
// ============================================================
//  InlineComp – Server-tijd diagnose endpoint
//
//  GET /api/server_time.php
//
//  Geeft de huidige server-tijd in verschillende formaten + tijdzone
//  zodat we cPanel-rapportages aan log-timestamps kunnen koppelen.
//  Tijdelijk diagnose-tool — kan na onderzoek weer weg.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$nowUtc = new DateTime('now', new DateTimeZone('UTC'));

echo json_encode([
    'server_php_time'      => date('Y-m-d H:i:s'),
    'server_php_timezone'  => date_default_timezone_get(),
    'server_php_offset'    => date('P'),  // bv. "+02:00" of "-04:00"
    'utc_time'             => $nowUtc->format('Y-m-d H:i:s'),
    'unix_timestamp'       => time(),
    'cest_equivalent'      => (new DateTime('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d H:i:s'),
    'edt_equivalent'       => (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s'),
    'apache_request_time'  => isset($_SERVER['REQUEST_TIME']) ? date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']) : null,
], JSON_PRETTY_PRINT);
