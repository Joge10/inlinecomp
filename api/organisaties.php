<?php
// ============================================================
//  InlineComp – Organisaties CRUD
//
//  GET               → alle organisaties (met sponsor_count)
//  GET ?id=xxx       → één organisatie met sponsors
//  POST action=save  → aanmaken/bijwerken (JSON body)
//  POST action=delete         → verwijderen
//  POST action=delete_sponsor → sponsor verwijderen
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';

function newUuid(): string {
    $b    = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function fetchOrg(PDO $pdo, string $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM organisaties WHERE id = ?");
    $stmt->execute([$id]);
    $org = $stmt->fetch();
    if (!$org) return null;

    $stmt = $pdo->prepare(
        "SELECT * FROM organisatie_sponsors WHERE organisatie_id = ? ORDER BY volgorde, naam"
    );
    $stmt->execute([$id]);
    $org['sponsors'] = $stmt->fetchAll();
    return $org;
}

try {
    // ── GET ──────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['id'])) {
            echo json_encode(fetchOrg($pdo, $_GET['id']));
        } else {
            $stmt = $pdo->query("
                SELECT o.*,
                    (SELECT COUNT(*) FROM organisatie_sponsors s
                     WHERE s.organisatie_id = o.id) AS sponsor_count
                FROM organisaties o
                ORDER BY o.naam
            ");
            echo json_encode($stmt->fetchAll());
        }
        exit;
    }

    // ── POST ─────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? 'save';

        // Sponsor verwijderen
        if ($action === 'delete_sponsor') {
            $pdo->prepare("DELETE FROM organisatie_sponsors WHERE id = ?")
                ->execute([$body['id'] ?? '']);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Organisatie verwijderen
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM organisaties WHERE id = ?")
                ->execute([$body['id'] ?? '']);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Opslaan (aanmaken of bijwerken)
        if ($action === 'save') {
            $id      = !empty($body['id']) ? $body['id'] : null;
            $naam    = trim($body['naam']    ?? '');
            $website = trim($body['website'] ?? '') ?: null;

            if (!$naam) {
                http_response_code(400);
                echo json_encode(['error' => 'Naam vereist']);
                exit;
            }

            if ($id) {
                $pdo->prepare(
                    "UPDATE organisaties SET naam = ?, website = ?, updated_at = NOW() WHERE id = ?"
                )->execute([$naam, $website, $id]);
            } else {
                $id = newUuid();
                $pdo->prepare(
                    "INSERT INTO organisaties (id, naam, website) VALUES (?, ?, ?)"
                )->execute([$id, $naam, $website]);
            }

            // Sponsors opslaan
            foreach ($body['sponsors'] ?? [] as $i => $s) {
                $sId  = !empty($s['id']) ? $s['id'] : null;
                $sNam = trim($s['naam'] ?? '');
                if (!$sNam) continue;
                $sUrl = trim($s['url'] ?? '') ?: null;

                if ($sId) {
                    $pdo->prepare(
                        "UPDATE organisatie_sponsors SET naam = ?, url = ?, volgorde = ? WHERE id = ?"
                    )->execute([$sNam, $sUrl, $i, $sId]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO organisatie_sponsors (id, organisatie_id, naam, url, volgorde)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([newUuid(), $id, $sNam, $sUrl, $i]);
                }
            }

            echo json_encode(fetchOrg($pdo, $id));
            exit;
        }
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method niet toegestaan']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
