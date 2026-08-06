<?php
// ============================================================
//  InlineComp – wizard_deel3.php
//  Opslaan van de tijdschema-wizard Deel 3 (programma-volgorde).
//
//  Schrijft de programma-blokken (volgorde, type, duren, startmoment) naar
//  tijdschema_blokken. De wizard bezit de volledige volgorde → we wissen eerst
//  alle blokken (+ ritten) voor dit tijdschema en schrijven daarna de rij.
//
//  Genereert (nog) GÉÉN ritten — dat is increment 4b / de tijdschema-genereer
//  (die is merge/split-bewust via de catVanJS-payload van de tijdschema-pagina).
//
//  POST body:
//  {
//    competition_id: "<uuid>",
//    datum: "YYYY-MM-DD" | null,   // startdatum (wedstrijdstart-anker)
//    tijd:  "HH:MM"      | null,   // starttijd
//    blokken: [                    // in gewenste volgorde
//      { blok_type:'ronde', afstand_naam, value_meters, ronde_type, heat_duur } |
//      { blok_type:'pauze'|'inrijden'|'ceremonie', duur, opmerking }            |
//      { blok_type:'wedstrijdstart', tijdstip, datum }
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

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$compId     = trim($body['competition_id'] ?? '');
$blokken    = $body['blokken'] ?? [];
$startTijd  = trim((string)($body['tijd']  ?? '')) ?: null;
$startDatum = trim((string)($body['datum'] ?? '')) ?: null;

if ($compId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}
if (!is_array($blokken)) {
    http_response_code(400);
    echo json_encode(['error' => 'blokken ontbreken']);
    exit;
}

// Normaliseer TIME (HH:MM[:SS]) en DATE (YYYY-MM-DD); ongeldig → NULL.
function d3NormTijd($t): ?string {
    $t = trim((string)$t);
    if ($t !== '' && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) {
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)($m[3] ?? 0));
    }
    return null;
}
function d3NormDatum($d): ?string {
    $d = trim((string)$d);
    return ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
}

$geldigType  = ['ronde', 'pauze', 'inrijden', 'wedstrijdstart', 'ceremonie', 'herstart'];
$geldigRonde = ['heats', 'kwartfinale', 'halve_finale', 'runner_up', 'finale'];

try {
    $pdo->beginTransaction();

    $tsId = (int)($pdo->query(
        "SELECT id FROM competition_tijdschema WHERE competition_id = " . $pdo->quote($compId)
    )->fetchColumn());
    if (!$tsId) throw new RuntimeException('Geen tijdschema — sla eerst Deel 2 op.');

    // Defensieve gate: zodra er heats/loting bestaan zou het wissen van blokken
    // via de CASCADE op tijdschema_ritten alle startlijsten + resultaten
    // meeslopen. De frontend blokkeert al, maar de backend is bron-van-waarheid.
    $h = $pdo->prepare("SELECT COUNT(*) FROM heats WHERE competition_id = ?");
    $h->execute([$compId]);
    if ((int)$h->fetchColumn() > 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'error'   => 'heeft_loting',
            'message' => 'Er zijn al startlijsten geloot — het programma kan niet meer '
                       . 'worden overschreven. Wis eerst het programma in het Tijdschema.',
        ]);
        exit;
    }

    // Volledige herschrijving: ritten expliciet eerst (belt-and-suspenders naast
    // de CASCADE), daarna de blokken.
    $pdo->prepare("DELETE FROM tijdschema_ritten  WHERE tijdschema_id = ?")->execute([$tsId]);
    $pdo->prepare("DELETE FROM tijdschema_blokken WHERE tijdschema_id = ?")->execute([$tsId]);

    $ins = $pdo->prepare("
        INSERT INTO tijdschema_blokken
            (tijdschema_id, volgorde, blok_type, afstand_naam, value_meters,
             ronde_type, duur, inrijd_cats, tijdstip, datum, opmerking, heat_duur)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $volgorde = 0;
    foreach ($blokken as $b) {
        $type = in_array($b['blok_type'] ?? '', $geldigType, true) ? $b['blok_type'] : null;
        if ($type === null) continue;

        $afNaam = null; $vm = null; $ronde = null; $duur = null;
        $inrijdCats = null; $tijdstip = null; $datum = null; $opm = null; $heatDuur = null;

        if ($type === 'ronde') {
            $afNaam = trim((string)($b['afstand_naam'] ?? '')) ?: null;
            if ($afNaam === null) continue;   // ronde zonder afstand → overslaan
            $vm = (array_key_exists('value_meters', $b) && $b['value_meters'] !== null && $b['value_meters'] !== '')
                ? (int)$b['value_meters'] : null;
            $ronde = in_array($b['ronde_type'] ?? '', $geldigRonde, true) ? $b['ronde_type'] : null;
            $heatDuur = (isset($b['heat_duur']) && $b['heat_duur'] !== '') ? max(0, (int)$b['heat_duur']) : null;
        } elseif ($type === 'wedstrijdstart') {
            $tijdstip = d3NormTijd($b['tijdstip'] ?? $startTijd);
            $datum    = d3NormDatum($b['datum']   ?? $startDatum);
        } else {
            // pauze | inrijden | ceremonie | herstart — duur (min). Géén auto-
            // opmerking (die vult de operator desgewenst zelf in de main in).
            $duur = (isset($b['duur']) && $b['duur'] !== '') ? max(0, (int)$b['duur']) : null;
            // inrijden: dc-id's die inrijden → JSON (zoals de tijdschema-pagina leest).
            if ($type === 'inrijden' && !empty($b['inrijd_cats']) && is_array($b['inrijd_cats'])) {
                $dcs = array_values(array_unique(array_filter(array_map(
                    fn($x) => trim((string)$x), $b['inrijd_cats']
                ), fn($x) => $x !== '')));
                if ($dcs) $inrijdCats = json_encode($dcs);
            }
        }

        $ins->execute([$tsId, $volgorde++, $type, $afNaam, $vm, $ronde, $duur, $inrijdCats, $tijdstip, $datum, $opm, $heatDuur]);
    }

    $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
        ->execute([$compId]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'tijdschema_id' => $tsId, 'aantal_blokken' => $volgorde]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Opslaan Deel 3 mislukt']);
    error_log('wizard_deel3.php: ' . $e->getMessage());
}
