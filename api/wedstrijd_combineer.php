<?php
// ============================================================
//  InlineComp – wedstrijden combineren (bron-koppelingen beheren)
//
//  Koppelt 1..N bron-KNSB-wedstrijden (zelfde org + locatie) aan één
//  doel-wedstrijd. De multi-bron import/vergelijk (import.php/vergelijk.php)
//  schrijft daarna alle DC's/rijders onder het doel-competition_id.
//
//  GET  ?action=lijst&competition_id=UUID
//       → { bronnen: [ {bron_competition_id, naam, starts, venue}... ] }
//  GET  ?action=combineerbaar&competition_id=UUID
//       → { kandidaten: [ {id, naam, starts, venue, org}... ] }   (zelfde org+locatie)
//  POST action=koppel     { doel_competition_id, bron_competition_ids: [UUID,...] }
//  POST action=ontkoppel  { doel_competition_id, bron_competition_id }
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/lib_combineren.php';
$_authUser = requireAuth($pdo);

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$body   = $isPost ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$action = trim($_GET['action'] ?? $body['action'] ?? '');

const KNSB_BASE = 'https://inschrijven.schaatsen.nl/api';

function cmbApiGet(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => 'Accept: application/json', 'timeout' => 15,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : json_decode($raw, true);
}

// Identiteit (org + locatie) van een KNSB-wedstrijd uit het detail-endpoint.
// org = e-mail (betrouwbaarst) of anders organisatienaam; venue = venue-naam.
function cmbIdentiteit(?array $detail): array {
    $contact = $detail['settings']['contact'] ?? [];
    return [
        'org_email' => strtolower(trim($contact['email'] ?? '')),
        'org_naam'  => strtolower(trim($contact['organizationName'] ?? '')),
        'venue'     => strtolower(trim($detail['venue']['name'] ?? '')),
        'stad'      => strtolower(trim($detail['venue']['address']['city'] ?? '')),
        'naam'      => trim($detail['name'] ?? ''),
        'starts'    => substr($detail['starts'] ?? '', 0, 10),
    ];
}

// Zelfde organisatie én zelfde locatie? Org matcht op e-mail (indien beide
// aanwezig) of anders op naam; locatie matcht op venue-naam (of anders stad).
function cmbZelfdeOrgLocatie(array $a, array $b): bool {
    $orgOk = ($a['org_email'] !== '' && $a['org_email'] === $b['org_email'])
          || ($a['org_naam']  !== '' && $a['org_naam']  === $b['org_naam']);
    $locOk = ($a['venue'] !== '' && $a['venue'] === $b['venue'])
          || ($a['stad']  !== '' && $a['stad']  === $b['stad']);
    return $orgOk && $locOk;
}

try {
    // ── READ: álle bron→doel-koppelingen (voor de wedstrijd-lijst-UI) ────────
    // Bron-wedstrijden worden in de lijst disabled getoond; alleen het doel
    // blijft selecteerbaar. { bronnen: { bronId: doelId, ... } }
    if ($action === 'alle') {
        $map = [];
        try {
            foreach ($pdo->query('SELECT bron_competition_id, doel_competition_id FROM competition_bronnen')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[$r['bron_competition_id']] = $r['doel_competition_id'];
            }
        } catch (\Throwable $e) { /* tabel bestaat nog niet → leeg */ }
        echo json_encode(['bronnen' => (object) $map], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── READ: huidige bronnen van een doelwedstrijd ─────────────────────────
    if ($action === 'lijst') {
        $doel = trim($_GET['competition_id'] ?? '');
        if ($doel === '') { http_response_code(400); echo json_encode(['error' => 'competition_id verplicht']); exit; }
        $bronIds = bronCompetitionIds($pdo, $doel);
        $bronnen = [];
        foreach ($bronIds as $bid) {
            $d = cmbApiGet(KNSB_BASE . '/competitions/' . rawurlencode($bid));
            $bronnen[] = [
                'bron_competition_id' => $bid,
                'naam'   => $d['name'] ?? '(onbekend / niet meer in feed)',
                'starts' => substr($d['starts'] ?? '', 0, 10),
                'venue'  => $d['venue']['name'] ?? '',
            ];
        }
        echo json_encode(['bronnen' => $bronnen], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── READ: combineerbare wedstrijden (zelfde org + locatie) ──────────────
    // Snel: de KNSB-lijst-feed bevat per wedstrijd al `venue` én
    // `settings.contact` (org), dus de hele org+locatie-guard komt uit ÉÉN
    // lijst-call — geen detail-fetch per kandidaat. Lijst-item en detail-item
    // hebben dezelfde shape, dus cmbIdentiteit() werkt op beide.
    if ($action === 'combineerbaar') {
        $doel = trim($_GET['competition_id'] ?? '');
        if ($doel === '') { http_response_code(400); echo json_encode(['error' => 'competition_id verplicht']); exit; }

        $lijst = cmbApiGet(KNSB_BASE . '/competitions') ?? [];
        $byId  = [];
        foreach ($lijst as $it) { if (!empty($it['id'])) $byId[$it['id']] = $it; }

        // Doel-identiteit uit de lijst (of anders het detail, als 'ie niet in de lijst staat).
        $doelItem = $byId[$doel] ?? cmbApiGet(KNSB_BASE . '/competitions/' . rawurlencode($doel));
        if (!$doelItem) { http_response_code(502); echo json_encode(['error' => 'Kon doelwedstrijd niet ophalen uit de KNSB-feed.']); exit; }
        $doelId    = cmbIdentiteit($doelItem);
        $doelStart = strtotime($doelId['starts'] ?: 'now');

        $alBron = [];  // bron-id → doel-id (voor "al gekoppeld"-uitsluiting)
        try {
            foreach ($pdo->query('SELECT bron_competition_id, doel_competition_id FROM competition_bronnen')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $alBron[$r['bron_competition_id']] = $r['doel_competition_id'];
            }
        } catch (\Throwable $e) { /* tabel bestaat nog niet */ }

        $kandidaten = [];
        foreach ($lijst as $item) {
            $id = $item['id'] ?? '';
            if ($id === '' || $id === $doel) continue;
            if (stripos($item['discipline'] ?? '', 'SpeedSkating.Inline') === false) continue;
            $ident = cmbIdentiteit($item);
            if (!$ident['starts']) continue;
            if (abs(strtotime($ident['starts']) - $doelStart) > 4 * 86400) continue;   // datum-nabijheid
            if (isset($alBron[$id]) && $alBron[$id] !== $doel) continue;                // aan ander doel gekoppeld
            if (!cmbZelfdeOrgLocatie($doelId, $ident)) continue;                        // guard: org + locatie

            $kandidaten[] = [
                'id'           => $id,
                'naam'         => $item['name'] ?? '',
                'starts'       => $ident['starts'],
                'venue'        => $item['venue']['name'] ?? '',
                'org'          => $item['settings']['contact']['organizationName'] ?? '',
                'al_gekoppeld' => isset($alBron[$id]) && $alBron[$id] === $doel,
            ];
        }
        usort($kandidaten, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        echo json_encode([
            'kandidaten'    => $kandidaten,
            'bronnen_count' => count(bronCompetitionIds($pdo, $doel)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Vanaf hier: schrijf-acties (beheer-recht) ───────────────────────────
    if (!$isPost) { http_response_code(405); echo json_encode(['error' => 'Gebruik POST voor koppel/ontkoppel']); exit; }
    if (!kanSchrijven($_authUser, 'beheer')) { http_response_code(403); echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']); exit; }

    $doel = trim($body['doel_competition_id'] ?? '');
    if ($doel === '') { http_response_code(400); echo json_encode(['error' => 'doel_competition_id verplicht']); exit; }

    // ── WRITE: koppel ───────────────────────────────────────────────────────
    if ($action === 'koppel') {
        $bronnen = $body['bron_competition_ids'] ?? [];
        if (!is_array($bronnen) || !$bronnen) { http_response_code(400); echo json_encode(['error' => 'bron_competition_ids (array) verplicht']); exit; }

        // Guard: het doel mag zelf geen bron van iets anders zijn (geen ketens).
        $doelIsBronVan = bronAlGekoppeldAan($pdo, $doel);
        if ($doelIsBronVan !== '') { http_response_code(409); echo json_encode(['error' => 'Deze wedstrijd is al als bron aan een andere gecombineerde wedstrijd gekoppeld.']); exit; }

        // Lijst-feed één keer ophalen; identiteiten komen hieruit (detail-fallback
        // per id als 'ie onverhoopt niet in de lijst staat).
        $byId = [];
        foreach (cmbApiGet(KNSB_BASE . '/competitions') ?? [] as $it) { if (!empty($it['id'])) $byId[$it['id']] = $it; }
        $cmbItem = function (string $id) use (&$byId): ?array {
            return $byId[$id] ?? cmbApiGet(KNSB_BASE . '/competitions/' . rawurlencode($id));
        };

        $doelItem = $cmbItem($doel);
        if (!$doelItem) { http_response_code(502); echo json_encode(['error' => 'Kon doelwedstrijd niet ophalen.']); exit; }
        $doelId = cmbIdentiteit($doelItem);

        $ins = $pdo->prepare(
            'INSERT INTO competition_bronnen (doel_competition_id, bron_competition_id)
             VALUES (?, ?) ON DUPLICATE KEY UPDATE toegevoegd_op = toegevoegd_op'
        );
        $gekoppeld = []; $geweigerd = [];
        foreach ($bronnen as $bron) {
            $bron = trim((string)$bron);
            if ($bron === '' || $bron === $doel) { $geweigerd[] = $bron; continue; }
            // Al aan een ander doel gekoppeld?
            $aan = bronAlGekoppeldAan($pdo, $bron);
            if ($aan !== '' && $aan !== $doel) { $geweigerd[] = $bron; continue; }
            // Bron mag zelf geen doel zijn (met eigen bronnen).
            if (isCombinatie($pdo, $bron)) { $geweigerd[] = $bron; continue; }
            // Org + locatie moeten kloppen.
            $det = $cmbItem($bron);
            if (!$det || !cmbZelfdeOrgLocatie($doelId, cmbIdentiteit($det))) { $geweigerd[] = $bron; continue; }
            $ins->execute([$doel, $bron]);
            $gekoppeld[] = $bron;
        }
        echo json_encode(['ok' => true, 'gekoppeld' => $gekoppeld, 'geweigerd' => $geweigerd], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── WRITE: ontkoppel ────────────────────────────────────────────────────
    if ($action === 'ontkoppel') {
        $bron = trim($body['bron_competition_id'] ?? '');
        if ($bron === '') { http_response_code(400); echo json_encode(['error' => 'bron_competition_id verplicht']); exit; }
        $del = $pdo->prepare('DELETE FROM competition_bronnen WHERE doel_competition_id = ? AND bron_competition_id = ?');
        $del->execute([$doel, $bron]);
        // NB: de al-geïmporteerde DC's van deze bron blijven onder het doel
        // staan tot een her-import; opruimen = Fase 3 (zie plan-doc).
        echo json_encode(['ok' => true, 'verwijderd' => $del->rowCount()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => "Onbekende actie: '$action'"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
