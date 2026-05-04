<?php
// ============================================================
//  InlineComp – publieke meldingen-beheer
//
//  GET ?comp_id=X                       → actieve meldingen voor wedstrijd
//                                         (geldig_van <= NOW <= geldig_tot)
//  GET ?action=lijst&comp_id=X          → ALLE meldingen voor wedstrijd
//                                         (incl. verlopen + toekomstig — voor admin)
//  POST action=save                     → aanmaken of bijwerken
//                                         { id?, comp_id, titel, bericht, prio,
//                                           geldig_van?, geldig_tot? }
//  POST action=delete                   → verwijderen { id }
//
//  GET zonder login is publiek (public + coach apps lezen mee).
//  POST/DELETE alleen voor owner/admin/timer/planner.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET is publiek; POST vereist login + rol
if ($method !== 'GET') {
    require_once __DIR__ . '/../auth/session.php';
    $_authUser = requireAuth($pdo);
    $magSchrijven = in_array($_authUser['role'] ?? '',
        ['owner', 'admin', 'timer', 'planner'], true);
    if (!$magSchrijven) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen schrijfrechten voor meldingen.']);
        exit;
    }
}

function uuid4_m(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    // ── GET: actieve meldingen (default — voor publieke poll) ──────────────
    if ($method === 'GET' && $action === '') {
        $compId = trim($_GET['comp_id'] ?? '');
        if ($compId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'comp_id ontbreekt']);
            exit;
        }
        // Sortering: chronologisch — oudste melding eerst. Zo bouwt een laat-
        // komer het historische verhaal in de juiste volgorde op (bv. eerst
        // "uitstel tot 13:00", dan "uitstel verlengd tot 14:00", dan "drogen
        // ging voorspoediger, herstart 13:45"). De laatste pop-up is dan
        // altijd de meest actuele stand van zaken.
        $stmt = $pdo->prepare("
            SELECT id, titel, bericht, prio, geldig_van, geldig_tot
            FROM public_meldingen
            WHERE competition_id = ?
              AND geldig_van <= NOW()
              AND (geldig_tot IS NULL OR geldig_tot >= NOW())
            ORDER BY geldig_van ASC
        ");
        $stmt->execute([$compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── GET: volledige lijst voor admin (incl. verlopen + toekomst) ────────
    if ($method === 'GET' && $action === 'lijst') {
        $compId = trim($_GET['comp_id'] ?? '');
        if ($compId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'comp_id ontbreekt']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT id, titel, bericht, prio, geldig_van, geldig_tot,
                   aangemaakt_door, aangemaakt_op
            FROM public_meldingen
            WHERE competition_id = ?
            ORDER BY geldig_van DESC
        ");
        $stmt->execute([$compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST acties ─────────────────────────────────────────────────────────
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Methode niet toegestaan']);
        exit;
    }

    if ($action === 'save') {
        $mid     = trim($_POST['id']         ?? '');
        $compId  = trim($_POST['comp_id']    ?? '');
        $titel   = trim($_POST['titel']      ?? '');
        $bericht = trim($_POST['bericht']    ?? '');
        $prio    = trim($_POST['prio']       ?? 'info');
        $vanRaw  = trim($_POST['geldig_van'] ?? '');
        $totRaw  = trim($_POST['geldig_tot'] ?? '') ?: null;

        if ($compId === '' || $titel === '' || $bericht === '') {
            http_response_code(400);
            echo json_encode(['error' => 'comp_id, titel en bericht zijn verplicht']);
            exit;
        }
        if (!in_array($prio, ['info', 'warn', 'urgent'], true)) $prio = 'info';

        // Normaliseer datetime-velden — accepteer 'YYYY-MM-DD HH:MM' (lokaal)
        // en zet om naar MySQL-formaat. Default: nu (geldig_van) en NULL (geldig_tot).
        $van = $vanRaw ? date('Y-m-d H:i:s', strtotime($vanRaw)) : date('Y-m-d H:i:s');
        $tot = $totRaw ? date('Y-m-d H:i:s', strtotime($totRaw)) : null;

        if ($mid === '') {
            $mid = uuid4_m();
            $pdo->prepare("
                INSERT INTO public_meldingen
                       (id, competition_id, titel, bericht, prio,
                        geldig_van, geldig_tot, aangemaakt_door)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $mid, $compId, $titel, $bericht, $prio, $van, $tot,
                $_authUser['id'] ?? null,
            ]);
        } else {
            $pdo->prepare("
                UPDATE public_meldingen
                SET titel = ?, bericht = ?, prio = ?,
                    geldig_van = ?, geldig_tot = ?
                WHERE id = ? AND competition_id = ?
            ")->execute([$titel, $bericht, $prio, $van, $tot, $mid, $compId]);
        }
        echo json_encode(['ok' => true, 'id' => $mid]);
        exit;
    }

    if ($action === 'delete') {
        $mid = trim($_POST['id'] ?? '');
        if ($mid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'id ontbreekt']);
            exit;
        }
        $pdo->prepare("DELETE FROM public_meldingen WHERE id = ?")->execute([$mid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
