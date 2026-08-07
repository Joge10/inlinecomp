<?php
// InlineComp – geeft de publieke VAPID-key aan de client (voor pushManager.subscribe).
// De public key is niet geheim; hij hoort juist in de client.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
require_once __DIR__ . '/../../config_inlinecomp.php';
echo json_encode(['publicKey' => $VAPID_PUBLIC ?? '']);
