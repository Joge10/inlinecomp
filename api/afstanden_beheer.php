<?php
// ============================================================
//  InlineComp – handmatig afstanden opslaan / bijwerken
//
//  POST /api/afstanden_beheer.php
//  Body: {
//    "dc_id": "<UUID>",
//    "distances": [
//      { "id": "<UUID>|null", "number": 1, "name": "500m", "value_meters": 500 },
//      ...
//    ]
//  }
//
//  - Bestaande rijen (id niet null) worden bijgewerkt.
//  - Nieuwe rijen (id null of leeg) krijgen een server-side UUID.
//  - Rijen die ontbreken in de lijst worden verwijderd.
//  - Geeft de volledige opgeslagen lijst terug.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';

$body  = json_decode(file_get_contents('php://input'), true);
$dcId  = trim($body['dc_id']    ?? '');
$dists = $body['distances']     ?? null;

if (!$dcId) {
    http_response_code(400);
    echo json_encode(['error' => 'dc_id ontbreekt']);
    exit;
}
if (!is_array($dists)) {
    http_response_code(400);
    echo json_encode(['error' => 'distances ontbreekt']);
    exit;
}

// UUID v4 generator
function uuid4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

try {
    $pdo->beginTransaction();

    // Verwijder afstanden die niet meer in de lijst staan
    $nieuweIds = array_filter(array_column($dists, 'id'));
    if ($nieuweIds) {
        $ph   = implode(',', array_fill(0, count($nieuweIds), '?'));
        $pdo->prepare("
            DELETE FROM distances
            WHERE distance_combination_id = ? AND id NOT IN ($ph)
        ")->execute(array_merge([$dcId], array_values($nieuweIds)));
    } else {
        // Geen bestaande IDs opgegeven → alles verwijderen
        $pdo->prepare("DELETE FROM distances WHERE distance_combination_id = ?")
            ->execute([$dcId]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO distances
               (id, distance_combination_id, number, name, value_meters, discipline, starts)
        VALUES (:id, :dc_id, :number, :name, :value_meters, NULL, NULL)
        ON DUPLICATE KEY UPDATE
               number       = VALUES(number),
               name         = VALUES(name),
               value_meters = VALUES(value_meters)
    ");

    $resultaat = [];
    foreach ($dists as $i => $d) {
        $naam   = trim($d['name']         ?? '');
        $meters = isset($d['value_meters']) && $d['value_meters'] !== ''
                    ? (int) $d['value_meters'] : null;
        if (!$naam) continue;   // lege rijen overslaan

        $id  = (isset($d['id']) && $d['id'] !== '') ? $d['id'] : uuid4();
        $num = (int) ($d['number'] ?? ($i + 1));

        $stmt->execute([
            ':id'           => $id,
            ':dc_id'        => $dcId,
            ':number'       => $num,
            ':name'         => $naam,
            ':value_meters' => $meters,
        ]);

        $resultaat[] = ['id' => $id, 'number' => $num, 'name' => $naam, 'value_meters' => $meters];
    }

    $pdo->commit();

    // Sorteer op number voor consistente volgorde in response
    usort($resultaat, fn($a, $b) => $a['number'] - $b['number']);

    echo json_encode(['ok' => true, 'distances' => $resultaat], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
