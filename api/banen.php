<?php
// ============================================================
//  InlineComp – banen-beheer (per organisatie)
//
//  GET ?org_id=X                → lijst banen voor één organisatie
//  GET ?id=X                    → één baan + aliassen + gekoppelde wedstrijden
//  POST action=save             → aanmaken/bijwerken { id?, org_id, naam, ... }
//  POST action=delete           → verwijderen (ON DELETE SET NULL op competitions)
//  POST action=alias_toevoegen  → alias toevoegen { id, naam }
//  POST action=alias_verwijderen→ alias verwijderen { id (alias-id) }
//
//  Alleen owner/admin (zelfde gate als organisaties).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && !kanSchrijven($_authUser, 'beheer')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']);
    exit;
}

function uuid4_b(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

$id     = trim($_GET['id']     ?? '');
$orgId  = trim($_GET['org_id'] ?? '');
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    // ── GET: één baan met aliassen + gekoppelde wedstrijden ────────────────
    if ($method === 'GET' && $id !== '') {
        $stmt = $pdo->prepare("SELECT * FROM banen WHERE id = ?");
        $stmt->execute([$id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) { http_response_code(404); echo json_encode(['error' => 'Niet gevonden']); exit; }

        $aStmt = $pdo->prepare(
            "SELECT id, naam FROM baan_aliassen WHERE baan_id = ? ORDER BY naam"
        );
        $aStmt->execute([$id]);
        $b['aliassen'] = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        $cStmt = $pdo->prepare(
            "SELECT id, name, starts FROM competitions WHERE baan_id = ? ORDER BY starts DESC LIMIT 50"
        );
        $cStmt->execute([$id]);
        $b['wedstrijden'] = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($b, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── GET: alle unieke banen (cross-org) — voor handmatige toewijzing ────
    // Deduplicatie op naam: één rij per fysieke baan, met gegevens van de
    // 'meest complete' rij (logo + vereniging-naam + stad). Bedoeld voor de
    // dropdown bij wedstrijd-baan-koppeling, ook over org-grenzen heen.
    if ($method === 'GET' && $action === 'alle') {
        $stmt = $pdo->query("
            SELECT
                MIN(id) AS id,
                naam,
                MAX(stad)             AS stad,
                MAX(vereniging_naam)  AS vereniging_naam,
                MAX(logo_path)        AS logo_path
            FROM banen
            GROUP BY naam
            ORDER BY naam
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── GET: lijst banen voor één organisatie ──────────────────────────────
    if ($method === 'GET') {
        if ($orgId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'org_id ontbreekt']);
            exit;
        }
        // Cross-org fallback: levert ook `gedeeld_logo_path` / `gedeeld_vereniging_naam`
        // mee als een andere org een baan met dezelfde naam heeft die wél een logo
        // of vereniging-naam heeft ingevuld. Zo kan de UI een visuele hint tonen
        // ("logo gedeeld met andere organisatie") zodat de beheerder snapt waarom
        // er bij de print toch een logo verschijnt.
        $stmt = $pdo->prepare("
            SELECT b.id, b.organisatie_id, b.naam, b.stad, b.vereniging_naam,
                   b.logo_path, b.logo_updated_at, b.updated_at,
                   (SELECT b2.logo_path FROM banen b2
                    WHERE b2.naam = b.naam AND b2.id != b.id
                      AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                    LIMIT 1) AS gedeeld_logo_path,
                   (SELECT b2.vereniging_naam FROM banen b2
                    WHERE b2.naam = b.naam AND b2.id != b.id
                      AND b2.vereniging_naam IS NOT NULL AND b2.vereniging_naam != ''
                    LIMIT 1) AS gedeeld_vereniging_naam,
                   (SELECT COUNT(*) FROM baan_aliassen a WHERE a.baan_id = b.id) AS aliassen_aantal,
                   (SELECT COUNT(*) FROM competitions c WHERE c.baan_id = b.id) AS comp_aantal
            FROM banen b
            WHERE b.organisatie_id = ?
            ORDER BY b.naam
        ");
        $stmt->execute([$orgId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST acties ─────────────────────────────────────────────────────────
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Methode niet toegestaan']);
        exit;
    }

    // Koppel een baan aan een wedstrijd (handmatig — voor wedstrijden die
    // bij import géén venue_name meekregen of onbekende baan hadden).
    // baan_id leeg = ontkoppelen.
    if ($action === 'koppel_wedstrijd') {
        $compId = trim($_POST['competition_id'] ?? '');
        $bid    = trim($_POST['baan_id']        ?? '');
        if ($compId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id ontbreekt']);
            exit;
        }
        if ($bid === '') {
            $pdo->prepare("UPDATE competitions SET baan_id = NULL WHERE id = ?")
                ->execute([$compId]);
            echo json_encode(['ok' => true, 'baan_id' => null]);
            exit;
        }
        $chk = $pdo->prepare("SELECT 1 FROM banen WHERE id = ?");
        $chk->execute([$bid]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Baan niet gevonden']);
            exit;
        }
        $pdo->prepare("UPDATE competitions SET baan_id = ? WHERE id = ?")
            ->execute([$bid, $compId]);
        echo json_encode(['ok' => true, 'baan_id' => $bid]);
        exit;
    }

    // Save (aanmaken of bijwerken)
    if ($action === 'save') {
        $bid     = trim($_POST['id']               ?? '');
        $orgId   = trim($_POST['org_id']           ?? '');
        $naam    = trim($_POST['naam']             ?? '');
        $stad    = trim($_POST['stad']             ?? '') ?: null;
        $verNaam = trim($_POST['vereniging_naam']   ?? '') ?: null;

        if ($naam === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Naam is verplicht']);
            exit;
        }

        if ($bid === '') {
            // Nieuw — uniek-check op (org, naam)
            if ($orgId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'org_id is verplicht voor nieuwe baan']);
                exit;
            }
            $check = $pdo->prepare("SELECT 1 FROM banen WHERE organisatie_id = ? AND naam = ?");
            $check->execute([$orgId, $naam]);
            if ($check->fetchColumn()) {
                http_response_code(409);
                echo json_encode(['error' => 'Een baan met deze naam bestaat al voor deze organisatie']);
                exit;
            }
            $bid = uuid4_b();
            $pdo->prepare("
                INSERT INTO banen (id, organisatie_id, naam, stad, vereniging_naam)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$bid, $orgId, $naam, $stad, $verNaam]);
        } else {
            // Bestaand — naam-conflict check binnen dezelfde org
            $cur = $pdo->prepare("SELECT organisatie_id FROM banen WHERE id = ?");
            $cur->execute([$bid]);
            $curOrg = $cur->fetchColumn();
            if (!$curOrg) {
                http_response_code(404);
                echo json_encode(['error' => 'Baan niet gevonden']);
                exit;
            }
            $check = $pdo->prepare("
                SELECT 1 FROM banen WHERE organisatie_id = ? AND naam = ? AND id != ?
            ");
            $check->execute([$curOrg, $naam, $bid]);
            if ($check->fetchColumn()) {
                http_response_code(409);
                echo json_encode(['error' => 'Een andere baan in deze organisatie heeft deze naam al']);
                exit;
            }
            $pdo->prepare("
                UPDATE banen SET naam = ?, stad = ?, vereniging_naam = ?
                WHERE id = ?
            ")->execute([$naam, $stad, $verNaam, $bid]);
        }

        echo json_encode(['ok' => true, 'id' => $bid]);
        exit;
    }

    // Delete
    if ($action === 'delete') {
        $bid = trim($_POST['id'] ?? '');
        if ($bid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'id ontbreekt']);
            exit;
        }
        // Logo-bestand opruimen (best-effort)
        $logoStmt = $pdo->prepare("SELECT logo_path FROM banen WHERE id = ?");
        $logoStmt->execute([$bid]);
        $logoPath = $logoStmt->fetchColumn();
        if ($logoPath) {
            $full = __DIR__ . '/../' . ltrim($logoPath, '/');
            if (is_file($full)) @unlink($full);
        }
        $pdo->prepare("DELETE FROM banen WHERE id = ?")->execute([$bid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Alias toevoegen — uniek BINNEN deze baan
    if ($action === 'alias_toevoegen') {
        $bid  = trim($_POST['id']   ?? '');
        $naam = trim($_POST['naam'] ?? '');
        if ($bid === '' || $naam === '') {
            http_response_code(400);
            echo json_encode(['error' => 'id en naam zijn vereist']);
            exit;
        }
        $chk = $pdo->prepare("SELECT 1 FROM baan_aliassen WHERE baan_id = ? AND naam = ?");
        $chk->execute([$bid, $naam]);
        if (!$chk->fetchColumn()) {
            $pdo->prepare(
                "INSERT INTO baan_aliassen (id, baan_id, naam) VALUES (?, ?, ?)"
            )->execute([uuid4_b(), $bid, $naam]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Alias verwijderen
    if ($action === 'alias_verwijderen') {
        $aId = trim($_POST['id'] ?? '');
        if ($aId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'id ontbreekt']);
            exit;
        }
        $pdo->prepare("DELETE FROM baan_aliassen WHERE id = ?")->execute([$aId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
