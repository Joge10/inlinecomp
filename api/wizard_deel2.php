<?php
// ============================================================
//  InlineComp – wizard_deel2.php
//  Opslaan van de tijdschema-wizard Deel 2 (afstand-instellingen).
//
//  Schrijft ALLEEN config (geen programma-blokken — dat is Deel 3):
//    - tijdschema_afstand_config  (per dc_id + afstand_naam)
//    - tijdschema_cat_config      (per dc_id + distance_id)
//  en maakt competition_tijdschema aan als die nog niet bestaat.
//
//  De wizard bezit de volledige Deel-2-stand → we wissen eerst alle
//  config voor dit tijdschema en schrijven daarna de aangeleverde rijen.
//
//  POST body:
//  {
//    competition_id: "<uuid>",
//    systeem: "full-final",
//    afstand_configs: [
//      { dc_id, afstand_naam, finale_heat_grootte, finale_b_grootte,
//        laatste_b_grootste, seeding, race_type }   // race_type = distances-type
//    ],
//    cat_configs: [
//      { dc_id, distance_id, heeft_heats, heats_aantal, heats_q_heat,
//        finale_a_grootte, finale_b_heats, laatste_b_grootste,
//        series_alleen_startvolgorde }
//    ]
//  }
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'beheer_basic')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor de wizard.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$compId  = trim($body['competition_id'] ?? '');
$systeem = in_array($body['systeem'] ?? '', ['full-final', 'internationaal-nieuw'], true)
         ? $body['systeem'] : 'full-final';
$afCfgs  = $body['afstand_configs'] ?? [];
$catCfgs = $body['cat_configs']     ?? [];

if ($compId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}
if (!is_array($afCfgs) || !is_array($catCfgs)) {
    http_response_code(400);
    echo json_encode(['error' => 'afstand_configs/cat_configs ontbreken']);
    exit;
}

// distances race_type → afstand_config race_type ('sprint' | 'long_distance')
// + heats_ranking-default (pack rijdt op positie+tijd, sprint/tijdrit op tijd).
function afRaceType(?string $rt): string {
    return in_array($rt, ['inline', 'afvalkoers', 'puntenkoers'], true) ? 'long_distance' : 'sprint';
}
function heatsRankingVoor(?string $rt): string {
    return in_array($rt, ['inline', 'afvalkoers', 'puntenkoers'], true) ? 'position_time' : 'time';
}

try {
    $pdo->beginTransaction();

    // competition_tijdschema (één per wedstrijd) — aanmaken of systeem bijwerken.
    $pdo->prepare("
        INSERT INTO competition_tijdschema (competition_id, systeem)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE systeem = VALUES(systeem)
    ")->execute([$compId, $systeem]);
    $tsId = (int)$pdo->query(
        "SELECT id FROM competition_tijdschema WHERE competition_id = " . $pdo->quote($compId)
    )->fetchColumn();
    if (!$tsId) throw new RuntimeException('tijdschema-id niet gevonden');

    // Volledige herschrijving: wis bestaande config voor dit tijdschema.
    $pdo->prepare("DELETE FROM tijdschema_cat_config     WHERE tijdschema_id = ?")->execute([$tsId]);
    $pdo->prepare("DELETE FROM tijdschema_afstand_config WHERE tijdschema_id = ?")->execute([$tsId]);

    // ── afstand-config (per dc_id + afstand_naam) ───────────────────────────
    $insAf = $pdo->prepare("
        INSERT INTO tijdschema_afstand_config
            (tijdschema_id, dc_id, afstand_naam, finale_heat_grootte, finale_b_grootte,
             laatste_b_grootste, finale_seeding, race_type, heats_ranking, finale_ranking)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    foreach ($afCfgs as $a) {
        $dcId = trim($a['dc_id'] ?? '');
        $naam = trim($a['afstand_naam'] ?? '');
        if ($dcId === '' || $naam === '') continue;
        $hg = max(1, (int)($a['finale_heat_grootte'] ?? 6));
        $bg = max($hg, (int)($a['finale_b_grootte'] ?? $hg));
        $lb = !empty($a['laatste_b_grootste']) ? 1 : 0;
        $sd = in_array($a['seeding'] ?? '', ['slang', 'tijdkoppeling', 'reverse_slang'], true) ? $a['seeding'] : 'slang';
        $rt = $a['race_type'] ?? 'sprint';
        $insAf->execute([$tsId, $dcId, $naam, $hg, $bg, $lb, $sd, afRaceType($rt), heatsRankingVoor($rt), 'time']);
    }

    // ── cat-config (per dc_id + distance_id) ────────────────────────────────
    $insCC = $pdo->prepare("
        INSERT INTO tijdschema_cat_config
            (tijdschema_id, dc_id, distance_id, heeft_heats, heats_aantal, heats_q_heat,
             finale_heats, finale_a_grootte, finale_b_heats, laatste_b_grootste,
             series_alleen_startvolgorde)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            heeft_heats = VALUES(heeft_heats), heats_aantal = VALUES(heats_aantal),
            heats_q_heat = VALUES(heats_q_heat), finale_a_grootte = VALUES(finale_a_grootte),
            finale_b_heats = VALUES(finale_b_heats), laatste_b_grootste = VALUES(laatste_b_grootste),
            series_alleen_startvolgorde = VALUES(series_alleen_startvolgorde)
    ");
    foreach ($catCfgs as $c) {
        $dcId   = trim($c['dc_id'] ?? '');
        $distId = trim($c['distance_id'] ?? '');
        if ($dcId === '' || $distId === '') continue;
        $heeftH  = !empty($c['heeft_heats']) ? 1 : 0;
        $aantal  = $heeftH ? max(1, (int)($c['heats_aantal'] ?? 1)) : null;
        $qHeat   = $heeftH ? max(0, (int)($c['heats_q_heat'] ?? 0)) : 0;
        $aGroot  = isset($c['finale_a_grootte']) && $c['finale_a_grootte'] !== '' ? max(1, (int)$c['finale_a_grootte']) : null;
        $bHeats  = isset($c['finale_b_heats']) && $c['finale_b_heats'] !== '' ? max(0, (int)$c['finale_b_heats']) : null;
        $lbg     = array_key_exists('laatste_b_grootste', $c) ? (!empty($c['laatste_b_grootste']) ? 1 : 0) : null;
        // series_alleen_startvolgorde alleen zinvol bij precies 1 serie.
        $sas     = (!empty($c['series_alleen_startvolgorde']) && $heeftH && $aantal === 1) ? 1 : 0;
        $insCC->execute([$tsId, $dcId, $distId, $heeftH, $aantal, $qHeat, 1, $aGroot, $bHeats, $lbg, $sas]);
    }

    $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
        ->execute([$compId]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'tijdschema_id' => $tsId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Opslaan Deel 2 mislukt']);
    error_log('wizard_deel2.php: ' . $e->getMessage());
}
