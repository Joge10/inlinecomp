<?php
// ============================================================
//  InlineComp – wizard_dc.php
//  Lichte DB-only read voor de tijdschema-wizard (Deel 1).
//  GEEN KNSB-fetch — puur de huidige DB-toestand.
//
//  Bouwsteen = CATEGORIE (persons.category, bv. DKA/HKA/DJB), niet de DC.
//  Zo werkt zowel mergen (cats uit verschillende DC's samen) als splitsen
//  (cats uit één "bak"-DC uit elkaar).
//
//  GET ?competition_id=X →
//  {
//    wizard_dc_gedaan: bool,   // vlag: Deel 1 al voltooid?
//    heeft_cat_config: bool,   // Deel 2 gemaakt   → structurele grendel
//    heeft_programma:  bool,   // blokken/ritten   → structurele grendel
//    heeft_loting:     bool,   // heats            → volledige grendel
//    categorien: [{
//        code, dc_id, dc_name, dc_number, feed_combined,
//        merge_group, merge_label, split_group,
//        deelnemers, reserves, niet_actief, niet_bevestigd
//    }],
//    distances_per_dc: { "<dc_id>": [{ id, number, name, race_type, value_meters, target_group }] }
//  }
//
//  Teller. status (labels = app.js STATUS_LABELS):
//    0=Niet bevestigd · 1=Bevestigd · 2=Afgemeld · 3=Afgemeld bij org.
//    4=Niet getekend · 5=Bevestigd bij org.
//    deelnemers     = status IN (1,5) én reserve IS NULL   (het getal)
//    reserves       = status IN (1,5) én reserve IS NOT NULL (+N res)
//    niet_actief    = status IN (2,3,4)                    (afgemeld/afwezig)
//    niet_bevestigd = status 0
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/_cat_helper.php';   // catSorteerSleutel() (standaard categorie-volgorde)
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'beheer_basic')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen toegang tot de wizard.']);
    exit;
}

// ── POST: markeer Deel 1 (DC's samenstellen) als voltooid ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $cid  = trim($body['competition_id'] ?? '');
    if (($body['action'] ?? '') === 'mark_done' && $cid !== '') {
        $pdo->prepare("UPDATE competitions SET wizard_dc_gedaan = 1 WHERE id = ?")->execute([$cid]);
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
    exit;
}

$compId = trim($_GET['competition_id'] ?? '');
if ($compId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}

try {
    // ── DC's (merge/split-info + naam) ──────────────────────────────────────
    $s = $pdo->prepare("
        SELECT id, number, name, category_filter, merge_group, merge_label
        FROM distance_combinations
        WHERE competition_id = ?
        ORDER BY number, name
    ");
    $s->execute([$compId]);
    $dcRows = $s->fetchAll(PDO::FETCH_ASSOC);

    // ── Aantallen per (DC, categorie) ───────────────────────────────────────
    $s = $pdo->prepare("
        SELECT e.distance_combination_id AS dc_id, p.category AS code,
               SUM(CASE WHEN e.status IN (1,5) AND e.reserve IS NULL     THEN 1 ELSE 0 END) AS deelnemers,
               SUM(CASE WHEN e.status IN (1,5) AND e.reserve IS NOT NULL THEN 1 ELSE 0 END) AS reserves,
               SUM(CASE WHEN e.status IN (2,3,4)                         THEN 1 ELSE 0 END) AS niet_actief,
               SUM(CASE WHEN e.status = 0                                THEN 1 ELSE 0 END) AS niet_bevestigd
        FROM entries e
        JOIN persons p ON p.license_key = e.person_license
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        WHERE dc.competition_id = ?
          AND p.category IS NOT NULL AND p.category <> ''
        GROUP BY e.distance_combination_id, p.category
    ");
    $s->execute([$compId]);
    $tel = [];   // dc_id => code => counts
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tel[$r['dc_id']][$r['code']] = [
            'deelnemers'     => (int)$r['deelnemers'],
            'reserves'       => (int)$r['reserves'],
            'niet_actief'    => (int)$r['niet_actief'],
            'niet_bevestigd' => (int)$r['niet_bevestigd'],
        ];
    }

    // ── Splits per DC ───────────────────────────────────────────────────────
    $s = $pdo->prepare("SELECT dc_id, category, split_group FROM dc_splits WHERE competition_id = ?");
    $s->execute([$compId]);
    $splitsPerDc = [];   // dc_id => code => split_group
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $splitsPerDc[$r['dc_id']][$r['category']] = $r['split_group'];
    }

    // ── Afstanden per DC (voor 1b) ──────────────────────────────────────────
    $s = $pdo->prepare("
        SELECT d.distance_combination_id AS dc_id, d.id, d.number, d.name,
               d.race_type, d.value_meters, d.target_group
        FROM distances d
        JOIN distance_combinations dc ON dc.id = d.distance_combination_id
        WHERE dc.competition_id = ?
        ORDER BY d.number, d.name
    ");
    $s->execute([$compId]);
    $distPerDc = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $distPerDc[$d['dc_id']][] = [
            'id'           => $d['id'],
            'number'       => (int)$d['number'],
            'name'         => $d['name'],
            'race_type'    => $d['race_type'],
            'value_meters' => $d['value_meters'] !== null ? (int)$d['value_meters'] : null,
            'target_group' => $d['target_group'],
        ];
    }

    // ── Categorieën samenstellen ────────────────────────────────────────────
    // Per DC de categorie-lijst = codes uit inschrijvingen (met aantallen) +
    // codes uit category_filter (0-rijders die de operator toch wil kunnen
    // indelen). feed_combined = DC bevat >1 categorie (feed-combinatie).
    $categorien = [];
    foreach ($dcRows as $dc) {
        $id = $dc['id'];
        $codes = [];
        foreach (array_keys($tel[$id] ?? []) as $c) { $codes[$c] = true; }
        if (!empty($dc['category_filter'])) {
            foreach (explode(',', $dc['category_filter']) as $raw) {
                $c = trim($raw);
                if ($c !== '') $codes[$c] = true;
            }
        }
        $codeLijst   = array_keys($codes);
        $feedCombined = count($codeLijst) > 1;
        sort($codeLijst);
        foreach ($codeLijst as $code) {
            $cnt = $tel[$id][$code] ?? ['deelnemers' => 0, 'reserves' => 0, 'niet_actief' => 0, 'niet_bevestigd' => 0];
            $categorien[] = [
                'code'           => $code,
                'dc_id'          => $id,
                'dc_name'        => $dc['name'] ?? '',
                'dc_number'      => (int)($dc['number'] ?? 0),
                'feed_combined'  => $feedCombined,
                'merge_group'    => $dc['merge_group'],
                'merge_label'    => $dc['merge_label'],
                'split_group'    => $splitsPerDc[$id][$code] ?? null,
                'deelnemers'     => $cnt['deelnemers'],
                'reserves'       => $cnt['reserves'],
                'niet_actief'    => $cnt['niet_actief'],
                'niet_bevestigd' => $cnt['niet_bevestigd'],
            ];
        }
    }

    // Standaard categorie-volgorde (jong→oud, dames vóór heren) zodat de bak
    // en de groepen een logische volgorde hebben — dat maakt de programma-
    // volgorde later makkelijker. Sorteren op de KNSB-code (category_filter),
    // met de DC-naam als fallback.
    // Sorteren op de categorie-CODE (altijd uniek/parseerbaar) — niet op dc_name,
    // want bij een bak-DC ("Diverse afstanden") is die voor elke categorie gelijk.
    usort($categorien, fn($a, $b) =>
        catSorteerSleutel($a['code'] ?? '', $a['code'] ?? '')
        <=> catSorteerSleutel($b['code'] ?? '', $b['code'] ?? '')
    );

    // ── Vlag + downstream-watermerk ─────────────────────────────────────────
    $vlag = $pdo->prepare("SELECT wizard_dc_gedaan FROM competitions WHERE id = ?");
    $vlag->execute([$compId]);
    $wizardGedaan = (int)($vlag->fetchColumn() ?: 0) === 1;

    $catCfg = $pdo->prepare("
        SELECT COUNT(*) FROM tijdschema_cat_config cc
        JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
        WHERE ct.competition_id = ?
    ");
    $catCfg->execute([$compId]);
    $heeftCatConfig = (int)$catCfg->fetchColumn() > 0;

    $prog = $pdo->prepare("
        SELECT
          (SELECT COUNT(*) FROM tijdschema_blokken b
             JOIN competition_tijdschema ct ON ct.id = b.tijdschema_id
             WHERE ct.competition_id = ?)
        + (SELECT COUNT(*) FROM tijdschema_ritten r
             JOIN competition_tijdschema ct ON ct.id = r.tijdschema_id
             WHERE ct.competition_id = ?)
    ");
    $prog->execute([$compId, $compId]);
    $heeftProgramma = (int)$prog->fetchColumn() > 0;

    $lot = $pdo->prepare("SELECT COUNT(*) FROM heats WHERE competition_id = ?");
    $lot->execute([$compId]);
    $heeftLoting = (int)$lot->fetchColumn() > 0;

    echo json_encode([
        'wizard_dc_gedaan' => $wizardGedaan,
        'heeft_cat_config' => $heeftCatConfig,
        'heeft_programma'  => $heeftProgramma,
        'heeft_loting'     => $heeftLoting,
        'categorien'       => $categorien,
        'distances_per_dc' => (object)$distPerDc,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'wizard_dc read faalde']);
    error_log('wizard_dc.php: ' . $e->getMessage());
}
