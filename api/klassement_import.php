<?php
// ============================================================
//  InlineComp – KNSB klassement PDF importeren
//
//  POST multipart/form-data
//    pdf  = PDF-bestand (klassement)
//
//  GET ?action=list                   → alle opgeslagen klassementen
//  GET ?action=get&id=UUID            → één klassement met posities
//  POST ?action=delete&id=UUID        → verwijder klassement
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim($_GET['action'] ?? 'upload');
$id     = trim($_GET['id']     ?? '');

// ── GET: lijst of enkel klassement ──────────────────────────────────────────
if ($method === 'GET') {
    if ($action === 'diagnose') {
        $info = [
            'php_version'    => PHP_VERSION,
            'shell_exec_ok'  => function_exists('shell_exec') && !ini_get('safe_mode'),
            'script_path'    => realpath(__DIR__ . '/../tools/pdf_klassement.py'),
            'python311'      => trim(@shell_exec('/opt/alt/python311/bin/python3.11 --version 2>&1') ?? ''),
            'pdfplumber311'  => trim(@shell_exec('/opt/alt/python311/bin/python3.11 -c "import pdfplumber; print(pdfplumber.__version__)" 2>&1') ?? ''),
        ];
        echo json_encode($info, JSON_PRETTY_PRINT);
        exit;
    }

    if ($action === 'list') {
        $orgFilter = trim($_GET['org_id'] ?? '');
        if ($orgFilter) {
            $stmt = $pdo->prepare(
                "SELECT id, naam, seizoen, bron_bestand, categorieen, totaal_rijders,
                        org_id, aangemaakt_op
                 FROM klassementen WHERE org_id = ?
                 ORDER BY aangemaakt_op DESC"
            );
            $stmt->execute([$orgFilter]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $pdo->query(
                "SELECT id, naam, seizoen, bron_bestand, categorieen, totaal_rijders,
                        org_id, aangemaakt_op
                 FROM klassementen
                 ORDER BY aangemaakt_op DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as &$r) {
            $r['categorieen'] = json_decode($r['categorieen'] ?? '[]', true) ?? [];
        }
        echo json_encode($rows);
    } elseif ($action === 'get' && $id) {
        $kl = $pdo->prepare(
            "SELECT id, naam, seizoen, bron_bestand, categorieen, totaal_rijders,
                    org_id, aangemaakt_op
             FROM klassementen WHERE id = ?"
        );
        $kl->execute([$id]);
        $k = $kl->fetch(PDO::FETCH_ASSOC);
        if (!$k) { http_response_code(404); echo json_encode(['error' => 'Niet gevonden']); exit; }
        $k['categorieen'] = json_decode($k['categorieen'] ?? '[]');

        $pos = $pdo->prepare(
            "SELECT positie, start_number, naam, categorie
             FROM klassement_posities WHERE klassement_id = ?
             ORDER BY positie ASC"
        );
        $pos->execute([$id]);
        $k['posities'] = $pos->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($k);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Onbekende actie']);
    }
    exit;
}

// ── POST: upload of delete ───────────────────────────────────────────────────
if ($method === 'POST') {

    // Verwijderen
    if ($action === 'delete' && $id) {
        $pdo->prepare("DELETE FROM klassement_posities WHERE klassement_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM klassementen WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Upload + parse
    if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Geen PDF-bestand of upload-fout (code ' . ($_FILES['pdf']['error'] ?? '?') . ')']);
        exit;
    }

    $tmpPath = $_FILES['pdf']['tmp_name'];
    $mime    = mime_content_type($tmpPath);
    if ($mime !== 'application/pdf' && !str_starts_with($mime, 'application/x-pdf')) {
        http_response_code(400);
        echo json_encode(['error' => 'Alleen PDF-bestanden zijn toegestaan (ontvangen: ' . $mime . ')']);
        exit;
    }

    // Python parser uitvoeren
    // Zoek werkende Python-executable
    $script = realpath(__DIR__ . '/../tools/pdf_klassement.py');
    if (!$script) {
        http_response_code(500);
        echo json_encode(['error' => 'Parser-script niet gevonden op server (tools/pdf_klassement.py)']);
        exit;
    }

    // shell_exec beschikbaar?
    if (!function_exists('shell_exec') || ini_get('safe_mode')) {
        http_response_code(500);
        echo json_encode(['error' => 'shell_exec is uitgeschakeld op deze server. Vraag de hoster om dit in te schakelen voor deze map.']);
        exit;
    }

    // Probeer bekende Python-locaties (CloudLinux shared hosting heeft afwijkend pad)
    $python = null;
    $kandidaten = [
        'python3', 'python',
        '/opt/alt/python311/bin/python3.11',
        '/opt/alt/python39/bin/python3.9',
        '/opt/alt/python38/bin/python3.8',
        '/opt/alt/python37/bin/python3.7',
        '/usr/bin/python3', '/usr/local/bin/python3',
    ];
    foreach ($kandidaten as $cmd) {
        $test = @shell_exec($cmd . ' --version 2>&1');
        if ($test && preg_match('/Python\s+3/', $test)) { $python = $cmd; break; }
    }
    if (!$python) {
        http_response_code(500);
        echo json_encode(['error' => 'Python 3 niet gevonden op de server. Neem contact op met de hoster om Python in te schakelen.']);
        exit;
    }

    $tmpQuote = escapeshellarg($tmpPath);
    $cmd      = $python . ' ' . escapeshellarg($script) . ' ' . $tmpQuote . ' 2>&1';
    $output   = shell_exec($cmd);

    if (!$output) {
        http_response_code(500);
        echo json_encode(['error' => "Parser ($python) geeft geen output. Controleer of pdfplumber geïnstalleerd is: pip install pdfplumber"]);
        exit;
    }

    $data = json_decode($output, true);
    if (!$data || isset($data['error'])) {
        http_response_code(500);
        echo json_encode(['error' => $data['error'] ?? 'Onbekende parser-fout', 'raw' => substr($output, 0, 500)]);
        exit;
    }

    // Parser geeft 'secties' terug (nieuw formaat) of 'rijders' (oud formaat, fallback)
    $secties = $data['secties'] ?? null;

    // Oud formaat (enkele PDF zonder secties) → wrap in één sectie
    if (!$secties && !empty($data['rijders'])) {
        $secties = [[
            'label'    => 'onbekend',
            'sectie'   => 'onbekend',
            'cat_code' => 'onbekend',
            'totaal'   => count($data['rijders']),
            'rijders'  => $data['rijders'],
        ]];
    }

    if (empty($secties)) {
        http_response_code(422);
        echo json_encode(['error' => 'Geen rijders herkend in het PDF-bestand. Controleer of het een KNSB-klassement is.']);
        exit;
    }

    // Rijders plat maken; categorie-veld = sectie-label (uniek per Lange afstand/Sprint)
    $rijdersPlat = [];
    foreach ($secties as $s) {
        foreach ($s['rijders'] as $r) {
            $rijdersPlat[] = [
                'positie'       => $r['positie'],
                'nr'            => $r['nr'],
                'naam'          => $r['naam'],
                'categorie'     => $s['label'],   // bv. "DSA – Lange afstand" of gewoon "DJB"
                'cat_code'      => $s['cat_code'],
                'sectie_naam'   => $s['sectie'],
            ];
        }
    }

    // Secties-samenvatting voor UI
    $catsSummary = array_map(fn($s) => [
        'label'    => $s['label'],
        'cat_code' => $s['cat_code'],
        'sectie'   => $s['sectie'],
        'totaal'   => $s['totaal'],
    ], $secties);

    // Naam ophalen uit POST of genereer op basis van header
    $naam      = trim($_POST['naam']    ?? ($data['header']['titel'] ?? ''));
    $seizoen   = trim($_POST['seizoen'] ?? '');
    $orgId     = trim($_POST['org_id']  ?? '') ?: null;
    $bronNaam  = basename($_FILES['pdf']['name'] ?? 'onbekend.pdf');

    if (!$naam) $naam = 'Klassement ' . date('Y-m-d');

    // UUID genereren
    $klId = bin2hex(random_bytes(16));
    $klId = sprintf('%s-%s-%s-%s-%s',
        substr($klId, 0, 8), substr($klId, 8, 4),
        substr($klId, 12, 4), substr($klId, 16, 4),
        substr($klId, 20));

    $pdo->prepare(
        "INSERT INTO klassementen (id, naam, seizoen, bron_bestand, categorieen, totaal_rijders, org_id, aangemaakt_op)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([$klId, $naam, $seizoen, $bronNaam, json_encode($catsSummary), count($rijdersPlat), $orgId]);

    $ins = $pdo->prepare(
        "INSERT INTO klassement_posities (id, klassement_id, positie, start_number, naam, categorie)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($rijdersPlat as $r) {
        $pid = bin2hex(random_bytes(8));
        $ins->execute([$pid, $klId, $r['positie'], $r['nr'], $r['naam'], $r['categorie']]);
    }

    echo json_encode([
        'ok'      => true,
        'id'      => $klId,
        'naam'    => $naam,
        'org_id'  => $orgId,
        'totaal'  => count($rijdersPlat),
        'secties' => $catsSummary,
        'header'  => $data['header'],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Methode niet toegestaan']);
