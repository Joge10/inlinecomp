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
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
// 'beheer_basic' (owner/admin/planner) i.p.v. 'beheer': afstanden vormen samen
// met mergen/splitsen (die al op 'startlijsten'/planner staan) de tijdschema-
// wizard Deel 1 — een planner moet die end-to-end kunnen afmaken.
if (!kanSchrijven($_authUser, 'beheer_basic')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$dcId       = trim($body['dc_id']    ?? '');
$dists      = $body['distances']     ?? null;
// null = basis-afstanden (target_group IS NULL), string = specifieke splitgroep
$splitGroup = array_key_exists('split_group', $body) ? ($body['split_group'] ?: null) : null;

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

    // Vóór INSERT/DELETE: lees huidige (oude) namen op per id zodat we ná
    // het schrijven een gerichte UPDATE op tijdschema_ritten.rit_naam en
    // heats.heat_naam kunnen doen voor afstanden die hernoemd zijn. Zonder
    // deze propagatie blijft het gegenereerde programma 'Lange afstand'
    // tonen ook nadat de operator de afstand-naam heeft gewijzigd.
    $oudeNamen = [];   // id => oude naam
    $idsInPayload = array_values(array_filter(array_column($dists, 'id')));
    if ($idsInPayload) {
        $ph = implode(',', array_fill(0, count($idsInPayload), '?'));
        $q = $pdo->prepare("SELECT id, name FROM distances WHERE distance_combination_id = ? AND id IN ($ph)");
        $q->execute(array_merge([$dcId], $idsInPayload));
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $oudeNamen[$r['id']] = $r['name'];
        }
    }

    // Verwijder afstanden die niet meer in de lijst staan, gescoopt op target_group
    $nieuweIds = array_values(array_filter(array_column($dists, 'id')));

    if ($splitGroup !== null) {
        // Splitgroep-afstanden: scope op target_group = $splitGroup
        if ($nieuweIds) {
            $ph = implode(',', array_fill(0, count($nieuweIds), '?'));
            $pdo->prepare("
                DELETE FROM distances
                WHERE distance_combination_id = ? AND target_group = ? AND id NOT IN ($ph)
            ")->execute(array_merge([$dcId, $splitGroup], $nieuweIds));
        } else {
            $pdo->prepare("DELETE FROM distances WHERE distance_combination_id = ? AND target_group = ?")
                ->execute([$dcId, $splitGroup]);
        }
    } else {
        // Basis-afstanden: scope op target_group IS NULL
        if ($nieuweIds) {
            $ph = implode(',', array_fill(0, count($nieuweIds), '?'));
            $pdo->prepare("
                DELETE FROM distances
                WHERE distance_combination_id = ? AND (target_group IS NULL OR target_group = '') AND id NOT IN ($ph)
            ")->execute(array_merge([$dcId], $nieuweIds));
        } else {
            $pdo->prepare("DELETE FROM distances WHERE distance_combination_id = ? AND (target_group IS NULL OR target_group = '')")
                ->execute([$dcId]);
        }
    }

    // race_type: user kan expliciet meesturen; anders default op basis van naam/meters.
    $stmt = $pdo->prepare("
        INSERT INTO distances
               (id, distance_combination_id, number, name, target_group,
                value_meters, discipline, starts, race_type)
        VALUES (:id, :dc_id, :number, :name, :target_group,
                :value_meters, NULL, NULL, :race_type)
        ON DUPLICATE KEY UPDATE
               number       = VALUES(number),
               name         = VALUES(name),
               target_group = VALUES(target_group),
               value_meters = VALUES(value_meters),
               race_type    = VALUES(race_type)
    ");
    $bepaalRaceType = function(?string $name, $meters): string {
        $n = mb_strtolower($name ?? '');
        if (str_contains($n, 'puntenkoers') || str_contains($n, 'punten koers')) return 'puntenkoers';
        if (str_contains($n, 'afvalkoers')  || str_contains($n, 'afval koers'))  return 'afvalkoers';
        if (str_contains($n, 'lange afstand')) return 'inline';
        if (is_numeric($meters) && (int)$meters > 1000)                           return 'inline';
        return 'sprint';
    };
    $geldigeRaceTypes = ['sprint','inline','puntenkoers','afvalkoers'];

    $resultaat = [];
    foreach ($dists as $i => $d) {
        $naam        = trim($d['name'] ?? '');
        $meters      = isset($d['value_meters']) && $d['value_meters'] !== ''
                         ? (int) $d['value_meters'] : null;
        if (!$naam) continue;

        $id  = (isset($d['id']) && $d['id'] !== '') ? $d['id'] : uuid4();
        $num = (int) ($d['number'] ?? ($i + 1));

        $raceType = (isset($d['race_type']) && in_array($d['race_type'], $geldigeRaceTypes, true))
                      ? $d['race_type']
                      : $bepaalRaceType($naam, $meters);

        $stmt->execute([
            ':id'           => $id,
            ':dc_id'        => $dcId,
            ':number'       => $num,
            ':name'         => $naam,
            ':target_group' => $splitGroup,  // null = basis, string = splitgroep
            ':value_meters' => $meters,
            ':race_type'    => $raceType,
        ]);

        $resultaat[] = [
            'id'           => $id,
            'number'       => $num,
            'name'         => $naam,
            'target_group' => $splitGroup,
            'value_meters' => $meters,
            'race_type'    => $raceType,
        ];
    }

    // ── Propageer basis-afstanden naar andere DC's in dezelfde merge_group ─
    // Als deze DC in een merge zit, moeten alle mergende DC's dezelfde
    // afstand-namen/meters delen. Zonder deze sync heeft één DC nog
    // "Lange afstand" en de ander al "Marathon" — dat vervuilt overzichten
    // die per-DC-naam dedup en zorgt voor dubbele kolommen.
    //
    // Alleen basis-afstanden (target_group NULL) propageren: splits kunnen
    // per DC verschillen en zijn niet symmetrisch tussen mergende DC's.
    // Bij demerge blijft de renamed naam staan bij elke ex-merge-DC —
    // dat is bewust: de aanpassing was gewenst, niet terug naar KNSB.
    if ($splitGroup === null) {
        $mgStmt = $pdo->prepare("SELECT merge_group FROM distance_combinations WHERE id = ?");
        $mgStmt->execute([$dcId]);
        $mergeGroup = $mgStmt->fetchColumn();
        if ($mergeGroup) {
            $otherStmt = $pdo->prepare(
                "SELECT id FROM distance_combinations
                 WHERE merge_group = ? AND id != ?"
            );
            $otherStmt->execute([$mergeGroup, $dcId]);
            $otherDcIds = $otherStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($otherDcIds as $otherId) {
                // Delete basis-rijen die niet in de payload staan (zelfde
                // logica als hoofd-DC, maar gescoopt op $otherId).
                if ($nieuweIds) {
                    $ph = implode(',', array_fill(0, count($nieuweIds), '?'));
                    $pdo->prepare("
                        DELETE FROM distances
                        WHERE distance_combination_id = ?
                          AND (target_group IS NULL OR target_group = '')
                          AND id NOT IN ($ph)
                    ")->execute(array_merge([$otherId], $nieuweIds));
                } else {
                    $pdo->prepare(
                        "DELETE FROM distances WHERE distance_combination_id = ?
                         AND (target_group IS NULL OR target_group = '')"
                    )->execute([$otherId]);
                }
                // INSERT/UPDATE met dezelfde id's en waardes; distances-PK is
                // (dc_id, id), dus 't insert per DC een eigen rij (of update).
                foreach ($resultaat as $r) {
                    $stmt->execute([
                        ':id'           => $r['id'],
                        ':dc_id'        => $otherId,
                        ':number'       => $r['number'],
                        ':name'         => $r['name'],
                        ':target_group' => null,
                        ':value_meters' => $r['value_meters'],
                        ':race_type'    => $r['race_type'],
                    ]);
                }
            }
        }
    }

    // ── Propageer naam-wijzigingen naar tijdschema_ritten + heats ──────────
    // Zelfde patroon als samenvoeg.php voor dc_naam: REPLACE op de oude
    // naam binnen rit_naam/heat_naam, gescoopt op distance_id zodat we
    // alleen ritten van déze afstand raken. Hiermee zien programma-volgorde,
    // gegenereerd programma, startlijsten en uitslagen direct de nieuwe
    // afstand-naam, zonder dat het programma opnieuw gegenereerd hoeft.
    $updRit = $pdo->prepare("
        UPDATE tijdschema_ritten
        SET rit_naam = REPLACE(rit_naam, ?, ?)
        WHERE distance_id = ?
    ");
    $updHeat = $pdo->prepare("
        UPDATE heats
        SET heat_naam = REPLACE(heat_naam, ?, ?)
        WHERE distance_id = ?
    ");
    foreach ($dists as $d) {
        $id      = trim($d['id'] ?? '');
        $newName = trim($d['name'] ?? '');
        if ($id === '' || $newName === '') continue;
        if (!isset($oudeNamen[$id])) continue;        // nieuwe rij — niets te REPLACEN
        $oldName = $oudeNamen[$id];
        if ($oldName === '' || $oldName === $newName) continue;
        $updRit ->execute([$oldName, $newName, $id]);
        $updHeat->execute([$oldName, $newName, $id]);
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
