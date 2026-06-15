<?php
// ============================================================
//  InlineComp – jury_leden CRUD (officials voor Wedstrijdprotokol)
//
//  GET  ?competition_id=X
//    → { leden: [{id, functie, naam, volgorde}, ...] }
//
//  POST  { action: 'bulk', competition_id: X, leden: [{functie, naam}, ...] }
//    → Wist alle bestaande rijen voor deze wedstrijd en herinsert in
//      één keer. volgorde komt uit de array-index (0, 1, 2, …). Eenvoudiger
//      dan diff-update: operator typt de hele lijst in één textarea / form
//      en het rapport hangt af van de complete set.
//    → { ok: true, aantal: N }
//
//  POST { action: 'nawoord', competition_id: X, tekst: '...' }
//    → Updatet competitions.protokol_nawoord (leeg = SET NULL).
//    → { ok: true }
//
//  Schrijf-acties vereisen owner/admin (ROL_SCHRIJF['beheer']).
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $compId = trim($_GET['competition_id'] ?? '');
    if ($compId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id ontbreekt']);
        exit;
    }
    try {
        // Sortering per categorie zodat OC vóór jury vóór vrijwilliger komt.
        // FIELD() forceert de gewenste volgorde ipv alfabetisch.
        $stmt = $pdo->prepare("
            SELECT id, categorie, functie, naam, volgorde
            FROM jury_leden
            WHERE competition_id = ?
            ORDER BY FIELD(categorie, 'OC', 'jury', 'vrijwilliger'), volgorde, naam
        ");
        $stmt->execute([$compId]);
        $leden = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Cast volgorde naar int voor consistente frontend-types
        foreach ($leden as &$l) { $l['volgorde'] = (int)$l['volgorde']; }
        unset($l);
        // Nawoord-tekst + protokol-foto's meeleveren — operator bewerkt
        // alles in dezelfde Protokol-data-modal.
        $nawStmt = $pdo->prepare("
            SELECT protokol_nawoord,
                   protokol_voorblad_foto,
                   protokol_nawoord_foto,
                   protokol_nawoord_foto_caption
              FROM competitions
             WHERE id = ?
        ");
        $nawStmt->execute([$compId]);
        $nawRow = $nawStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
            'leden'                => $leden,
            'nawoord'              => $nawRow['protokol_nawoord'] ?? '',
            'voorblad_foto'        => $nawRow['protokol_voorblad_foto'] ?? null,
            'nawoord_foto'         => $nawRow['protokol_nawoord_foto'] ?? null,
            'nawoord_foto_caption' => $nawRow['protokol_nawoord_foto_caption'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Owner/admin-check voor schrijf-acties (ROL_SCHRIJF wordt al gedefinieerd
// in auth/session.php dat hierboven al via requireAuth() is geladen).
if (!in_array($_authUser['role'] ?? '', ROL_SCHRIJF['beheer'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$compId = trim($body['competition_id'] ?? '');
if ($compId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}

try {
    if ($action === 'bulk') {
        $leden = is_array($body['leden'] ?? null) ? $body['leden'] : [];
        $alleCats = ['OC', 'jury', 'vrijwilliger'];
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM jury_leden WHERE competition_id = ?")->execute([$compId]);
        $ins = $pdo->prepare("
            INSERT INTO jury_leden (id, competition_id, categorie, functie, naam, volgorde)
            VALUES (UUID(), ?, ?, ?, ?, ?)
        ");
        $aantal = 0;
        // Volgorde per categorie zodat ze niet door elkaar geraken
        $catVolgorde = ['OC' => 0, 'jury' => 0, 'vrijwilliger' => 0];
        foreach ($leden as $l) {
            $cat     = (string)($l['categorie'] ?? 'jury');
            if (!in_array($cat, $alleCats, true)) continue;
            $functie = trim((string)($l['functie'] ?? ''));
            $naam    = trim((string)($l['naam']    ?? ''));
            if ($naam === '') continue;  // skip rijen zonder naam
            // OC + vrijwilliger hebben geen functie; opslaan als NULL
            $functieDb = ($cat === 'jury' && $functie !== '') ? mb_substr($functie, 0, 100) : null;
            $naam      = mb_substr($naam, 0, 150);
            $ins->execute([$compId, $cat, $functieDb, $naam, $catVolgorde[$cat]++]);
            $aantal++;
        }
        $pdo->commit();
        echo json_encode(['ok' => true, 'aantal' => $aantal]);
        exit;
    }

    if ($action === 'nawoord') {
        $tekst   = trim((string)($body['tekst'] ?? ''));
        $caption = trim((string)($body['nawoord_foto_caption'] ?? ''));
        // Bij wijzigen van NL-tekst de EN-cache wissen — anders blijft de
        // oude vertaling hangen.
        $upd = $pdo->prepare("
            UPDATE competitions
            SET protokol_nawoord              = ?,
                protokol_nawoord_en           = NULL,
                protokol_nawoord_foto_caption = ?
            WHERE id = ?
        ");
        $upd->execute([
            $tekst   === '' ? null : $tekst,
            $caption === '' ? null : $caption,
            $compId,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'verwijder_foto') {
        // Verwijdert protokol_voorblad_foto of protokol_nawoord_foto.
        // Field-naam wordt expliciet gecheckt tegen een witte lijst om
        // SQL-injectie via dynamische kolomnaam te voorkomen.
        $field = (string)($body['field'] ?? '');
        $kolomMap = [
            'voorblad' => 'protokol_voorblad_foto',
            'nawoord'  => 'protokol_nawoord_foto',
        ];
        if (!isset($kolomMap[$field])) {
            http_response_code(400);
            echo json_encode(['error' => 'Onbekend foto-veld']);
            exit;
        }
        $kolom = $kolomMap[$field];
        $oudStmt = $pdo->prepare("SELECT $kolom FROM competitions WHERE id = ?");
        $oudStmt->execute([$compId]);
        $oudPad = $oudStmt->fetchColumn();
        $pdo->prepare("UPDATE competitions SET $kolom = NULL WHERE id = ?")
            ->execute([$compId]);
        if ($oudPad) {
            $oudFs = __DIR__ . '/../' . $oudPad;
            if (is_file($oudFs)) @unlink($oudFs);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'vertaal_nawoord') {
        // Lazy on-demand: haal NL-tekst op, vertaal via Claude, cache in EN-kolom.
        // Wordt aangeroepen vanuit printWedstrijdrapport bij EN-keuze als de
        // cache leeg is. Idempotent — als _en al gevuld is, geef die meteen terug.
        $row = $pdo->prepare("SELECT protokol_nawoord, protokol_nawoord_en FROM competitions WHERE id = ?");
        $row->execute([$compId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            http_response_code(404);
            echo json_encode(['error' => 'Wedstrijd niet gevonden']);
            exit;
        }
        if (!empty($r['protokol_nawoord_en'])) {
            // Cache hit
            echo json_encode(['ok' => true, 'tekst' => $r['protokol_nawoord_en'], 'cached' => true]);
            exit;
        }
        $nl = trim((string)$r['protokol_nawoord']);
        if ($nl === '') {
            // Geen NL-tekst → niets te vertalen
            echo json_encode(['ok' => true, 'tekst' => '']);
            exit;
        }
        if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
            http_response_code(503);
            echo json_encode([
                'error' => 'Vertaal-API niet geconfigureerd (ANTHROPIC_API_KEY ontbreekt). '
                         . 'NL-nawoord wordt zonder vertaling in het EN-rapport getoond.',
            ]);
            exit;
        }
        // Vraag Claude om alleen de vertaling terug te geven, zonder kopjes
        // of commentaar — zelfde stijl als vertaal_melding.php.
        $prompt = "You are translating a short closing remark (\"nawoord\") from a Dutch "
                . "inline-skating competition protocol to English. Keep it natural, warm, "
                . "and professional. Use natural skating-event terminology where applicable "
                . "(heat, semifinal, final, time trial, runner-up). Preserve any proper names, "
                . "numbers, and dates exactly. Do NOT add any explanations or commentary — "
                . "respond with the translation only, plain text, preserving paragraph breaks.\n\n"
                . "Input (Dutch):\n" . $nl;
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => 'claude-haiku-4-5',
                'max_tokens' => 2048,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);
        $raw    = curl_exec($ch);
        $httpRc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $httpRc >= 400) {
            http_response_code(502);
            echo json_encode(['error' => 'Claude API-fout (HTTP ' . $httpRc . ')' . ($err ? ' — ' . $err : '')]);
            exit;
        }
        $resp = json_decode($raw, true);
        $en   = trim((string)($resp['content'][0]['text'] ?? ''));
        if ($en === '') {
            http_response_code(502);
            echo json_encode(['error' => 'Lege vertaling van Claude API ontvangen']);
            exit;
        }
        // Cache opslaan
        $cache = $pdo->prepare("UPDATE competitions SET protokol_nawoord_en = ? WHERE id = ?");
        $cache->execute([$en, $compId]);
        echo json_encode(['ok' => true, 'tekst' => $en, 'cached' => false]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende action: ' . $action]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
