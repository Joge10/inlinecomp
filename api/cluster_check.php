<?php
// ============================================================
//  InlineComp – Koppel-controle (cluster-check)
//
//  Detecteert heat_entries waar de gekoppelde persoon NIET in het juiste
//  KNSB-cluster zit voor de bedoelde categorie. Symptoom van de oude
//  CSV-import-bug die op startnummer alleen koppelde (Sophie DKA #26 ↔
//  Lars HJB #26 — verschillende personen, zelfde nummer).
//
//  GET  ?action=scan&competition_id=X            → lijst problemen
//  POST action=zoek_kandidaten {entry_id}        → personen die DE bedoelde
//                                                   categorie + startnummer
//                                                   delen (mogelijke fix)
//  POST action=vervang {entry_id, nieuwe_license}→ wissel persons-koppeling
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// Schrijver-rollen voor `vervang` — read-only acties (scan/zoek) open voor
// elke ingelogde gebruiker.
$_schrijfRollen = ['owner', 'admin', 'importer'];
$_isSchrijver   = in_array($_authUser['role'] ?? '', $_schrijfRollen, true);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $body['action'] ?? '';

// ── Cluster-helpers (identiek aan csv_import.php — KNSB-codes zijn de
// canonieke bron, niet de DC- of CSV-naam). ──────────────────────────────────
$gender = function($v): ?string {
    // Schema: persons.gender is binary (0=man, 1=vrouw) — zo opgeslagen door
    // csv_import.php (zie ':gd' => $gender === 'M' ? 0 : 1). Daarnaast
    // ondersteunen we de string-vorm voor robuustheid.
    if ($v === 0 || $v === '0') return 'M';
    if ($v === 1 || $v === '1') return 'V';
    $s = strtoupper(trim((string)$v));
    if ($s === 'M' || $s === 'H') return 'M';
    if ($s === 'W' || $s === 'V' || $s === 'F') return 'V';
    return null;
};
// TRUE = "t/m JB" (jong cluster), FALSE = "vanaf JA" (ouder cluster — JA is
// nog junior maar zit qua nummer-uitgifte bij de senioren omdat ze vaak
// samen rijden).
$isJong = function($cat): ?bool {
    $c = strtoupper(trim((string)$cat));
    if ($c === '') return null;
    if (preg_match('/(P[1-4]|KA|JB)$/', $c)) return true;
    if (preg_match('/(JA|S[JAB]|\d{2})$/', $c)) return false;
    return null;
};
// Cluster-string voor weergave/vergelijking. 'V_J' = vrouw t/m JB, etc.
$cluster = function($g, $j): ?string {
    if ($g === null || $j === null) return null;
    return $g . '_' . ($j ? 'J' : 'O');   // J=jong, O=ouder
};

// Meerderheid-vote helper: bepaal de dominante categorie en cluster onder
// een set van persons. Returns null als geen duidelijke meerderheid (DC echt
// gemengd of leeg). Drempel 60% — anders kunnen 51%/49% verdelingen tot
// false positives leiden.
$bepaalDominant = function(array $rijen) use ($gender, $isJong): ?array {
    $catCount = [];
    $clCount  = [];   // cluster (V_J, V_O, M_J, M_O)
    $totaal   = 0;
    foreach ($rijen as $r) {
        // Externe en pending tellen niet mee — anders kunnen die het
        // beeld vertekenen.
        if ((int)($r['extern'] ?? 0) === 1) continue;
        if (($r['pending_source'] ?? null) !== null) continue;
        $g = $gender($r['person_gender']);
        $j = $isJong($r['person_cat']);
        if ($g === null || $j === null) continue;
        $cl = $g . '_' . ($j ? 'J' : 'O');
        $clCount[$cl] = ($clCount[$cl] ?? 0) + 1;
        $cat = $r['person_cat'];
        if ($cat) $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
        $totaal++;
    }
    if ($totaal === 0) return null;
    arsort($clCount);
    arsort($catCount);
    $topCl    = array_key_first($clCount);
    $topClN   = $clCount[$topCl];
    $topCat   = array_key_first($catCount);
    // Drempel: dominante cluster moet ≥60% zijn. Anders DC is "echt gemengd"
    // en kunnen we geen mismatch detecteren zonder false positives.
    if ($topClN / $totaal < 0.6) return null;
    return [
        'cluster'  => $topCl,
        'category' => $topCat,
        'aantal'   => $totaal,
        'top_pct'  => round($topClN / $totaal * 100),
    ];
};

try {

if ($action === 'competities') {
    // Alle wedstrijden uit scope tonen — vroeger filterden we op
    // "alleen wedstrijden met heat_entries", maar dat liet bv. een wedstrijd
    // waarvoor alleen entries (inschrijvingen) bestaan en nog geen loting
    // gemist worden. Voor een net-aangemaakte wedstrijd of eentje die nog
    // niet geseed is wil je 'm wél kunnen kiezen — de scan meldt zelf "geen
    // entries" als er niets te onderzoeken valt.
    $scope = gebruikerCompScopeWhere($pdo, $_authUser, 'c');
    $stmt = $pdo->prepare("
        SELECT c.id AS competition_id, c.name AS naam, c.starts AS datum
        FROM competitions c
        WHERE 1=1 " . $scope['where'] . "
        ORDER BY c.starts DESC, c.name
    ");
    $stmt->execute($scope['params']);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'scan') {
    $compId = trim($_GET['competition_id'] ?? '');
    if ($compId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id verplicht']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $compId);

    // Alle ENTRIES (inschrijvingen) van deze wedstrijd ophalen met gekoppelde
    // persoon. CSV-import zet de fout-gekoppelde Lars in entries, dus daar
    // detecteren we. heat_entries (loting) is afgeleid — die kun je opnieuw
    // genereren maar zolang entries fout staat, blijft de loting fout.
    $stmt = $pdo->prepare("
        SELECT
            e.id              AS entry_id,
            e.person_license,
            e.distance_combination_id AS dc_id,
            dc.name           AS dc_naam,
            p.full_name, p.short_name, p.gender   AS person_gender,
            p.category    AS person_cat,
            p.start_number AS person_snr,
            p.club_short, p.club_full,
            p.extern, p.pending_source
        FROM entries e
        JOIN distance_combinations dc   ON dc.id = e.distance_combination_id
        JOIN persons p                  ON p.license_key = e.person_license
        WHERE dc.competition_id = ?
        ORDER BY p.full_name
    ");
    $stmt->execute([$compId]);
    $rijen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Geen entries = geen inschrijvingen = niets te scannen
    if (empty($rijen)) {
        echo json_encode([
            'problemen' => [],
            'totaal'    => 0,
            'leeg'      => true,
            'leeg_reden'=> 'Deze wedstrijd heeft nog geen inschrijvingen — niets te scannen.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Groepeer per (persoon, dc) — als één persoon in meerdere heats van
    // DEZELFDE DC zit (series → finale), één rapport-regel volstaat.
    // Verschillende DCs (bv. fout-gekoppeld in twee verschillende
    // categorie-DCs) worden APART gerapporteerd want de "bedoelde
    // categorie" verschilt per DC.
    // ── Pass 1: per DC dominante cluster + categorie berekenen ──────────────
    // Meerderheid-vote op persons.category van alle entries in de DC. Robuust
    // tegen DC-naam-variaties (Youth/Junior/Senior in elke taal) én tegen
    // corrupte dc.category_filter. Aanname: bug raakt minder dan 40% van een
    // DC — als 60%+ DKA is, zijn de 2 HJA-entries de fout.
    $byDc = [];
    foreach ($rijen as $r) {
        $byDc[$r['dc_id']]['naam'] = $r['dc_naam'];
        $byDc[$r['dc_id']]['rijen'][] = $r;
    }
    $dcDominant = [];   // dc_id → ['cluster','category','aantal','top_pct']
    foreach ($byDc as $dcId => $info) {
        $d = $bepaalDominant($info['rijen']);
        if ($d !== null) $dcDominant[$dcId] = $d;
    }

    // ── Pass 2: per entry mismatch detecteren ─────────────────────────────
    $perPersoonDc = [];
    foreach ($rijen as $r) {
        $dom = $dcDominant[$r['dc_id']] ?? null;
        if ($dom === null) continue;   // DC ambigu — geen detectie mogelijk
        // Dominante cluster ontleden voor labels
        $bedoeldG = substr($dom['cluster'], 0, 1) === 'V' ? 'V' : 'M';
        $bedoeldJ = substr($dom['cluster'], -1) === 'J';

        $persG = $gender($r['person_gender']);
        $persJ = $isJong($r['person_cat']);

        // Externe en pending rijders overslaan — bug zit alleen in tier-1
        // KNSB-startnummer-match voor échte KNSB-rijders.
        if ((int)$r['extern'] === 1 || $r['pending_source'] !== null) continue;

        $genderMis  = ($bedoeldG !== null && $persG !== null && $bedoeldG !== $persG);
        $clusterMis = ($bedoeldJ !== null && $persJ !== null && $bedoeldJ !== $persJ);
        if (!$genderMis && !$clusterMis) continue;

        $key = $r['person_license'] . '|' . $r['dc_id'];
        if (!isset($perPersoonDc[$key])) {
            $perPersoonDc[$key] = [
                'persoon' => [
                    'license_key' => $r['person_license'],
                    'full_name'   => $r['full_name'],
                    'short_name'  => $r['short_name'],
                    'gender'      => $r['person_gender'],
                    'category'    => $r['person_cat'],
                    'start_number'=> $r['person_snr'] !== null ? (int)$r['person_snr'] : null,
                    'club'        => $r['club_short'] ?: $r['club_full'] ?: '',
                ],
                'dc_naam'        => $r['dc_naam'],
                'verwacht_label' => sprintf('%s %s · dominant %s (%d van %d)',
                    $bedoeldG === 'V' ? 'Dames' : 'Heren',
                    $bedoeldJ ? 't/m JB' : 'vanaf JA',
                    $dom['category'],
                    (int)($dom['aantal'] * $dom['top_pct'] / 100),
                    $dom['aantal']),
                'verwacht_cat'   => $dom['category'],
                'entry_snr'      => $r['person_snr'] !== null ? (int)$r['person_snr'] : null,
                'mismatch_gender'  => $genderMis,
                'mismatch_cluster' => $clusterMis,
                'verwacht_gender'  => $bedoeldG,
                'verwacht_jong'    => $bedoeldJ,
                'entries' => [],
            ];
        }
        $perPersoonDc[$key]['entries'][] = [
            'entry_id'    => (int)$r['entry_id'],
            'dc_id'       => $r['dc_id'],
            'dc_naam'     => $r['dc_naam'],
        ];
    }
    $perPersoon = $perPersoonDc;

    // Debug-info: per DC tellen wat we precies zien aan gender/cat/cluster.
    // Geeft direct inzicht waarom een DC ambigu was of geen mismatches gaf
    // ("waarom toont de scan 0 fouten terwijl ik er 6 zie?"). Frontend toont
    // dit alleen als ?debug=1 in de URL stond.
    $debug = !empty($_GET['debug']);
    $debugInfo = [];
    $debugMeta = null;
    if ($debug) {
        // Hoeveel DC's heeft de wedstrijd totaal? Hoeveel met heat_entries?
        // Verschil = nog niet geseed (geen heats = niets te controleren).
        $totDcStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM distance_combinations WHERE competition_id = ?"
        );
        $totDcStmt->execute([$compId]);
        $totDcs = (int)$totDcStmt->fetchColumn();
        $debugMeta = [
            'totaal_dcs_in_wedstrijd' => $totDcs,
            'dcs_met_heat_entries'    => count($byDc),
            'verschil_uitleg'         => $totDcs > count($byDc)
                ? sprintf('%d DC(s) hebben nog geen heats — daar is nog geen startlijst voor gegenereerd, dus niets te scannen.',
                          $totDcs - count($byDc))
                : 'Alle DC\'s met categorieën hebben heats.',
        ];
        foreach ($byDc as $dcId => $info) {
            $genderCount = [];
            $catCount    = [];
            $clCount     = [];
            $totaal      = 0;
            $extEnPending = 0;
            foreach ($info['rijen'] as $r) {
                if ((int)($r['extern'] ?? 0) === 1 || ($r['pending_source'] ?? null) !== null) {
                    $extEnPending++;
                    continue;
                }
                $rawG = $r['person_gender'] ?? '';
                $rawC = $r['person_cat'] ?? '';
                $genderCount[$rawG === '' ? '(leeg)' : $rawG] = ($genderCount[$rawG === '' ? '(leeg)' : $rawG] ?? 0) + 1;
                $catCount[$rawC === '' ? '(leeg)' : $rawC] = ($catCount[$rawC === '' ? '(leeg)' : $rawC] ?? 0) + 1;
                $g = $gender($r['person_gender']);
                $j = $isJong($r['person_cat']);
                $cl = ($g !== null && $j !== null) ? ($g . '_' . ($j ? 'J' : 'O')) : '(?)';
                $clCount[$cl] = ($clCount[$cl] ?? 0) + 1;
                $totaal++;
            }
            arsort($genderCount);
            arsort($catCount);
            arsort($clCount);
            $debugInfo[] = [
                'dc_naam'  => $info['naam'],
                'totaal'   => $totaal,
                'extern_pending_geskipt' => $extEnPending,
                'gender_telling'   => $genderCount,
                'cat_telling'      => $catCount,
                'cluster_telling'  => $clCount,
                'dominant'         => $dcDominant[$dcId] ?? '(geen — onder 60% drempel of leeg)',
            ];
        }
    }

    echo json_encode([
        'problemen' => array_values($perPersoon),
        'totaal'    => count($perPersoon),
        'debug'     => $debug ? $debugInfo : null,
        'debug_meta'=> $debug ? $debugMeta : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'zoek_kandidaten') {
    // Zoek personen met dezelfde KNSB-categorie + startnummer als de entry's
    // BEDOELDE categorie. Die combinatie is uniek volgens KNSB-belofte, dus
    // levert idealiter precies 1 kandidaat op.
    $entryId = (int)($body['entry_id'] ?? 0);
    if (!$entryId) {
        http_response_code(400);
        echo json_encode(['error' => 'entry_id verplicht']);
        exit;
    }
    // entry_id verwijst nu naar entries.id (inschrijving), niet heat_entries.
    $stmt = $pdo->prepare("
        SELECT e.person_license, e.distance_combination_id,
               dc.competition_id, dc.name AS dc_naam,
               p.start_number AS person_snr
        FROM entries e
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        JOIN persons p ON p.license_key = e.person_license
        WHERE e.id = ?
    ");
    $stmt->execute([$entryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Entry niet gevonden']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $row['competition_id']);

    // Dominante categorie binnen deze DC bepalen — meerderheid-vote op
    // entries (inschrijvingen). Werkt onafhankelijk van DC-naam en taal.
    $dcStmt = $pdo->prepare("
        SELECT p.gender AS person_gender, p.category AS person_cat,
               p.extern, p.pending_source
        FROM entries e
        JOIN persons p ON p.license_key = e.person_license
        WHERE e.distance_combination_id = ?
    ");
    $dcStmt->execute([$row['distance_combination_id']]);
    $dom = $bepaalDominant($dcStmt->fetchAll(PDO::FETCH_ASSOC));
    // Startnummer komt uit persons (entries heeft geen eigen startnummer-kolom).
    // Voor de bug-context maakt dit niet uit: Lars en Sophie hebben per definitie
    // hetzelfde nummer in hun eigen cluster, anders was de miskoppeling nooit
    // ontstaan.
    $snr = $row['person_snr'];
    if ($dom === null || !$snr) {
        echo json_encode([
            'kandidaten'  => [],
            'reden'       => 'DC is te gemengd om dominante categorie te bepalen, of persoon mist startnummer.',
        ]);
        exit;
    }

    // EXACT match op dominante KNSB-cat + startnummer. KNSB-belofte: precies 1.
    $kStmt = $pdo->prepare("
        SELECT license_key, full_name, short_name, gender, category,
               start_number, club_short, club_full, birth_year
        FROM persons
        WHERE category = ? AND start_number = ?
          AND extern = 0
          AND pending_source IS NULL
          AND anonymized_at IS NULL
    ");
    $kStmt->execute([$dom['category'], (int)$snr]);
    $kand = $kStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($kand as &$k) {
        $k['start_number'] = $k['start_number'] !== null ? (int)$k['start_number'] : null;
        $k['birth_year']   = $k['birth_year']   !== null ? (int)$k['birth_year']   : null;
        $k['club']         = $k['club_short'] ?: $k['club_full'] ?: '';
    }
    // ── Doel-DC kandidaten: voor Roan-scenario (persoon correct, fout DC).
    // Pak persoon's eigen cluster, scan alle ANDERE DCs in deze comp, vind
    // die waarvan dominantie matcht met persoon's cluster. Exacte cat-match
    // boven (HJA-persoon naar HJA-DC > naar HJB-DC binnen zelfde cluster).
    $persStmt = $pdo->prepare("SELECT gender, category FROM persons WHERE license_key = ?");
    $persStmt->execute([$row['person_license']]);
    $pers = $persStmt->fetch(PDO::FETCH_ASSOC);
    $doelDcs = [];
    if ($pers) {
        $pG = $gender($pers['gender']);
        $pJ = $isJong($pers['category']);
        if ($pG !== null && $pJ !== null) {
            $persCluster = $pG . '_' . ($pJ ? 'J' : 'O');
            $persCat     = strtoupper(trim($pers['category']));
            $aStmt = $pdo->prepare("
                SELECT dc.id AS dc_id, dc.name AS dc_naam,
                       p.gender AS person_gender, p.category AS person_cat,
                       p.extern, p.pending_source
                FROM distance_combinations dc
                LEFT JOIN entries e ON e.distance_combination_id = dc.id
                LEFT JOIN persons p ON p.license_key = e.person_license
                WHERE dc.competition_id = ? AND dc.id != ?
            ");
            $aStmt->execute([$row['competition_id'], $row['distance_combination_id']]);
            $byDc = [];
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $r2) {
                if (!isset($byDc[$r2['dc_id']])) {
                    $byDc[$r2['dc_id']] = ['naam' => $r2['dc_naam'], 'rijen' => []];
                }
                if ($r2['person_gender'] !== null) {
                    $byDc[$r2['dc_id']]['rijen'][] = $r2;
                }
            }
            foreach ($byDc as $dcId => $info) {
                $dom2 = $bepaalDominant($info['rijen']);
                if ($dom2 === null) continue;
                if ($dom2['cluster'] !== $persCluster) continue;
                $doelDcs[] = [
                    'dc_id'        => $dcId,
                    'dc_naam'      => $info['naam'],
                    'dominant_cat' => $dom2['category'],
                    'aantal'       => $dom2['aantal'],
                    'exact_match'  => $dom2['category'] === $persCat,
                ];
            }
            // Exacte cat-match boven, dan grootste DC eerst
            usort($doelDcs, function($a, $b) {
                if ($a['exact_match'] !== $b['exact_match']) {
                    return $b['exact_match'] <=> $a['exact_match'];
                }
                return $b['aantal'] <=> $a['aantal'];
            });
        }
    }

    echo json_encode([
        'kandidaten'  => $kand,
        'gezocht_cat' => $dom['category'],
        'gezocht_snr' => (int)$snr,
        'dc_dominant_pct' => $dom['top_pct'],
        'dc_dominant_n'   => $dom['aantal'],
        'doel_dcs'        => $doelDcs,
        'persoon_cat'     => $pers['category'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'vervang') {
    if (!$_isSchrijver) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen rechten om koppelingen te wijzigen']);
        exit;
    }
    // Frontend stuurt de lijst van entries.id die vervangen moeten worden —
    // alleen waar de scan een cluster-mismatch detecteerde. Een persoon kan
    // in dezelfde comp zowel correct (eigen DC) als incorrect (verkeerde DC
    // door de tier-1 bug) ingeschreven staan; alleen de incorrect-gekoppelde
    // mogen worden aangeraakt.
    $entryIds = $body['entry_ids'] ?? [];
    $nieuwLic = trim($body['nieuwe_license'] ?? '');
    if (!is_array($entryIds) || !count($entryIds) || $nieuwLic === '') {
        http_response_code(400);
        echo json_encode(['error' => 'entry_ids (array) en nieuwe_license verplicht']);
        exit;
    }
    $entryIds = array_values(array_filter(array_map('intval', $entryIds)));

    $pStmt = $pdo->prepare("SELECT license_key, full_name FROM persons WHERE license_key = ?");
    $pStmt->execute([$nieuwLic]);
    $nieuw = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$nieuw) {
        http_response_code(404);
        echo json_encode(['error' => 'Nieuwe persoon niet gevonden']);
        exit;
    }

    // entries-info ophalen — distance_combination_id voor heat_entries-sync.
    $ph = implode(',', array_fill(0, count($entryIds), '?'));
    $eStmt = $pdo->prepare("
        SELECT e.id, e.person_license, e.distance_combination_id AS dc_id,
               dc.competition_id
        FROM entries e
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        WHERE e.id IN ($ph)
    ");
    $eStmt->execute($entryIds);
    $entries = $eStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($entries) !== count($entryIds)) {
        http_response_code(404);
        echo json_encode(['error' => 'Niet alle entries gevonden']);
        exit;
    }
    $comps = array_unique(array_column($entries, 'competition_id'));
    if (count($comps) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Entries spannen meerdere wedstrijden']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $comps[0]);
    $compId = $comps[0];

    // UNIQUE (distance_combination_id, person_license) op entries: als de
    // juiste persoon al ZELF in deze DC staat ingeschreven (= correct),
    // dan willen we de foute entry verwijderen i.p.v. updaten — anders
    // duplicate-key fout.
    $eConflict = $pdo->prepare(
        "SELECT 1 FROM entries WHERE distance_combination_id = ? AND person_license = ? AND id <> ? LIMIT 1"
    );
    $eUpdate = $pdo->prepare("UPDATE entries SET person_license = ? WHERE id = ?");
    $eDelete = $pdo->prepare("DELETE FROM entries WHERE id = ?");

    // heat_entries voor zelfde DC + (oude OF nieuwe) license — daar zit de
    // loting, die moet meebewegen als er al een startlijst gegenereerd is.
    // UNIQUE (heat_id, person_license): nieuwe persoon mag niet al in
    // dezelfde heat staan.
    $heConflict = $pdo->prepare(
        "SELECT 1 FROM heat_entries WHERE heat_id = ? AND person_license = ? LIMIT 1"
    );
    $heUpdate = $pdo->prepare("UPDATE heat_entries SET person_license = ? WHERE id = ?");
    $heDelete = $pdo->prepare("DELETE FROM heat_entries WHERE id = ?");
    $heLookup = $pdo->prepare("
        SELECT he.id, he.heat_id
        FROM heat_entries he
        JOIN heats h ON h.id = he.heat_id
        WHERE h.competition_id = ?
          AND h.distance_combination_id = ?
          AND he.person_license = ?
    ");

    $bijgewerkt = 0;
    $verwijderd = 0;
    $heBijgewerkt = 0;
    $heVerwijderd = 0;
    $geskipped  = [];

    foreach ($entries as $e) {
        $oudLic = $e['person_license'];
        if ($oudLic === $nieuwLic) {
            $geskipped[] = (int)$e['id'];
            continue;
        }
        // entries-laag: update of delete bij conflict
        $eConflict->execute([$e['dc_id'], $nieuwLic, $e['id']]);
        if ($eConflict->fetchColumn()) {
            // Juiste persoon zat al ingeschreven — foute entry weggooien
            $eDelete->execute([$e['id']]);
            $verwijderd++;
        } else {
            $eUpdate->execute([$nieuwLic, $e['id']]);
            $bijgewerkt++;
        }
        // heat_entries-laag: alle heats van deze DC waar de OUDE license in
        // staat → vervangen of verwijderen
        $heLookup->execute([$compId, $e['dc_id'], $oudLic]);
        foreach ($heLookup->fetchAll(PDO::FETCH_ASSOC) as $he) {
            $heConflict->execute([$he['heat_id'], $nieuwLic]);
            if ($heConflict->fetchColumn()) {
                $heDelete->execute([$he['id']]);
                $heVerwijderd++;
            } else {
                $heUpdate->execute([$nieuwLic, $he['id']]);
                $heBijgewerkt++;
            }
        }
    }

    echo json_encode([
        'ok'           => true,
        'bijgewerkt'   => $bijgewerkt,
        'verwijderd'   => $verwijderd,
        'he_bijgewerkt'=> $heBijgewerkt,
        'he_verwijderd'=> $heVerwijderd,
        'geskipped'    => $geskipped,
        'nieuw_naam'   => $nieuw['full_name'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── HANDMATIGE RIJDER-CORRECTIE ─────────────────────────────────────────────
// Voor het scenario waarbij een rijder zich op de wedstrijddag meldt en zegt
// "ik sta verkeerd ingedeeld". Combineert in één flow:
//  - persons-data (gender / category / start_number) corrigeren
//  - bestaande DC-inschrijvingen verplaatsen naar de juiste DC
//
// Auto-scan (cluster-check) ziet deze cases NIET, want persons-cluster en
// DC-cluster zijn "consistent fout" — beide zeggen Youth maar moet Junior.

if ($action === 'zoek_persoon') {
    // Autocomplete-zoek voor de rijder-correctie helper. Naam (vol of short)
    // OR exact startnummer. Top 20.
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . $q . '%';
    $isNr = ctype_digit($q);
    $sql = "
        SELECT license_key, full_name, short_name, gender, category,
               start_number, club_short, club_full,
               extern, pending_source
        FROM persons
        WHERE (LOWER(full_name) LIKE LOWER(?)
            OR LOWER(short_name) LIKE LOWER(?)" .
            ($isNr ? " OR start_number = ?" : "") . ")
          AND anonymized_at IS NULL
        ORDER BY full_name
        LIMIT 20
    ";
    $params = [$like, $like];
    if ($isNr) $params[] = (int)$q;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    // PDO retourneert TINYINT als string ("0"/"1"). In JS is "0" truthy →
    // dat liet álle KNSB-rijders verkeerd als 🌍 extern tonen. Cast naar bool.
    $rijen = array_map(function($r) {
        $r['extern']   = (int)($r['extern'] ?? 0) === 1;
        $r['start_number'] = $r['start_number'] !== null ? (int)$r['start_number'] : null;
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($rijen, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'persoon_detail') {
    // Volledige persoon-data + huidige DC-inschrijvingen in een specifieke
    // wedstrijd + alle DCs van die wedstrijd (voor verplaats-dropdown).
    $lic    = trim($_GET['license_key']    ?? '');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$lic) {
        http_response_code(400);
        echo json_encode(['error' => 'license_key verplicht']);
        exit;
    }
    $pStmt = $pdo->prepare("
        SELECT license_key, full_name, short_name, gender, category,
               start_number, club_short, club_full, birth_year,
               extern, pending_source
        FROM persons WHERE license_key = ?
    ");
    $pStmt->execute([$lic]);
    $persoon = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$persoon) {
        http_response_code(404);
        echo json_encode(['error' => 'Persoon niet gevonden']);
        exit;
    }
    // PDO retourneert TINYINT als string — cast naar bool/int voor consistente
    // truthy-check in frontend. Anders krijgen alle KNSB-rijders een 🌍 extern
    // badge omdat "0" truthy is in JS.
    $persoon['extern']       = (int)($persoon['extern'] ?? 0) === 1;
    $persoon['start_number'] = $persoon['start_number'] !== null ? (int)$persoon['start_number'] : null;
    $persoon['birth_year']   = $persoon['birth_year']   !== null ? (int)$persoon['birth_year']   : null;
    $entries = [];
    $alleDcs = [];
    $wedstrijdCats = [];
    if ($compId !== '') {
        checkCompetitieToegang($pdo, $_authUser, $compId);
        $eStmt = $pdo->prepare("
            SELECT e.id AS entry_id, e.distance_combination_id AS dc_id,
                   dc.name AS dc_naam
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE e.person_license = ? AND dc.competition_id = ?
            ORDER BY dc.name
        ");
        $eStmt->execute([$lic, $compId]);
        $entries = $eStmt->fetchAll(PDO::FETCH_ASSOC);
        $dcStmt = $pdo->prepare("
            SELECT id AS dc_id, name AS dc_naam, category_filter
            FROM distance_combinations
            WHERE competition_id = ?
            ORDER BY name
        ");
        $dcStmt->execute([$compId]);
        $alleDcs = $dcStmt->fetchAll(PDO::FETCH_ASSOC);

        // Unieke categorieën die in DEZE wedstrijd voorkomen — twee bronnen:
        //   1. distance_combinations.category_filter (wat operator opgaf bij
        //      DC-setup, kan komma-lijst zijn voor merged DCs)
        //   2. persons.category van iedereen die ingeschreven staat
        //      (vangnet voor het geval category_filter leeg/onbetrouwbaar is)
        // Set-union zodat we niets missen.
        $cats = [];
        foreach ($alleDcs as $dc) {
            $cf = $dc['category_filter'] ?? '';
            foreach (explode(',', $cf) as $c) {
                $c = strtoupper(trim($c));
                if ($c !== '') $cats[$c] = true;
            }
        }
        $pCatStmt = $pdo->prepare("
            SELECT DISTINCT p.category
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            WHERE dc.competition_id = ?
              AND p.category IS NOT NULL AND p.category <> ''
        ");
        $pCatStmt->execute([$compId]);
        foreach ($pCatStmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $c = strtoupper(trim($c));
            if ($c !== '') $cats[$c] = true;
        }
        $wedstrijdCats = array_keys($cats);
        sort($wedstrijdCats);
    }
    echo json_encode([
        'persoon'         => $persoon,
        'entries'         => $entries,
        'alle_dcs'        => $alleDcs,
        'wedstrijd_cats'  => $wedstrijdCats,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'corrigeer_persoon') {
    // Eén-call combinatie: persons-velden updaten + entries verhuizen
    // tussen DCs in dezelfde wedstrijd. heat_entries van bron-DC worden
    // opgeschoond.
    if (!$_isSchrijver) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen rechten']);
        exit;
    }
    $lic    = trim($body['license_key']    ?? '');
    $compId = trim($body['competition_id'] ?? '');
    if (!$lic) {
        http_response_code(400);
        echo json_encode(['error' => 'license_key verplicht']);
        exit;
    }
    // ── persons-update
    $updates = [];
    $upParams = [];
    if (isset($body['nieuwe_gender']) && $body['nieuwe_gender'] !== '' && $body['nieuwe_gender'] !== null) {
        $g  = $body['nieuwe_gender'];
        $gi = null;
        if ($g === 'M' || $g === '0' || $g === 0) $gi = 0;
        elseif ($g === 'V' || $g === 'W' || $g === '1' || $g === 1) $gi = 1;
        if ($gi !== null) {
            $updates[]  = 'gender = ?';
            $upParams[] = $gi;
        }
    }
    if (isset($body['nieuwe_category']) && trim($body['nieuwe_category']) !== '') {
        $updates[]  = 'category = ?';
        $upParams[] = strtoupper(trim($body['nieuwe_category']));
    }
    if (isset($body['nieuwe_start_number']) && $body['nieuwe_start_number'] !== '' && $body['nieuwe_start_number'] !== null) {
        $updates[]  = 'start_number = ?';
        $upParams[] = (int)$body['nieuwe_start_number'];
    }
    $personsUpdated = false;
    if ($updates) {
        $upParams[] = $lic;
        $upStmt = $pdo->prepare("UPDATE persons SET " . implode(', ', $updates) . " WHERE license_key = ?");
        $upStmt->execute($upParams);
        $personsUpdated = $upStmt->rowCount() > 0;
    }
    // ── entries verplaatsen: [{entry_id, doel_dc_id}]
    $verplaatst = 0;
    $heWeg      = 0;
    $verplaatsingen = $body['verplaatsingen'] ?? [];
    if ($compId !== '' && is_array($verplaatsingen) && count($verplaatsingen)) {
        checkCompetitieToegang($pdo, $_authUser, $compId);
        $insEntry = $pdo->prepare(
            "INSERT IGNORE INTO entries (distance_combination_id, person_license, status) VALUES (?, ?, 1)"
        );
        $delEntry = $pdo->prepare("DELETE FROM entries WHERE id = ?");
        $delHe    = $pdo->prepare("
            DELETE he FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            WHERE h.competition_id = ?
              AND h.distance_combination_id = ?
              AND he.person_license = ?
        ");
        $getEntry = $pdo->prepare("
            SELECT e.id, e.person_license, e.distance_combination_id AS dc_id
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE e.id = ? AND dc.competition_id = ? AND e.person_license = ?
        ");
        foreach ($verplaatsingen as $v) {
            $eId      = (int)($v['entry_id'] ?? 0);
            $doelDcId = trim($v['doel_dc_id'] ?? '');
            if (!$eId || $doelDcId === '') continue;
            $getEntry->execute([$eId, $compId, $lic]);
            $eRow = $getEntry->fetch(PDO::FETCH_ASSOC);
            if (!$eRow) continue;
            if ($eRow['dc_id'] === $doelDcId) continue;
            // Verify doel-DC in dezelfde comp
            $vChk = $pdo->prepare("SELECT 1 FROM distance_combinations WHERE id = ? AND competition_id = ?");
            $vChk->execute([$doelDcId, $compId]);
            if (!$vChk->fetchColumn()) continue;
            $insEntry->execute([$doelDcId, $lic]);
            $delHe->execute([$compId, $eRow['dc_id'], $lic]);
            $heWeg += $delHe->rowCount();
            $delEntry->execute([$eId]);
            $verplaatst++;
        }
    }
    echo json_encode([
        'ok' => true,
        'persons_bijgewerkt' => $personsUpdated,
        'verplaatst'         => $verplaatst,
        'he_verwijderd'      => $heWeg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'verplaats') {
    // Verplaats inschrijving(en) van DC A naar DC B binnen dezelfde wedstrijd.
    // Voor Roan-scenario: persoon staat correct in DB maar zit in fout DC
    // (CSV had 'm verkeerd geslacht gemarkeerd). Veel sneller dan handmatig
    // verwijderen + opnieuw inschrijven via beheer-panel.
    if (!$_isSchrijver) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen rechten om koppelingen te wijzigen']);
        exit;
    }
    $entryIds = $body['entry_ids'] ?? [];
    $doelDcId = trim($body['doel_dc_id'] ?? '');
    if (!is_array($entryIds) || !count($entryIds) || $doelDcId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'entry_ids (array) en doel_dc_id verplicht']);
        exit;
    }
    $entryIds = array_values(array_filter(array_map('intval', $entryIds)));

    $ph = implode(',', array_fill(0, count($entryIds), '?'));
    $eStmt = $pdo->prepare("
        SELECT e.id, e.person_license, e.distance_combination_id AS dc_id,
               dc.competition_id
        FROM entries e
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        WHERE e.id IN ($ph)
    ");
    $eStmt->execute($entryIds);
    $entries = $eStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($entries) !== count($entryIds)) {
        http_response_code(404);
        echo json_encode(['error' => 'Niet alle entries gevonden']);
        exit;
    }
    $comps = array_unique(array_column($entries, 'competition_id'));
    if (count($comps) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Entries spannen meerdere wedstrijden']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $comps[0]);
    $compId = $comps[0];

    // Doel-DC moet in dezelfde wedstrijd zitten (anti-cross-comp).
    $dcStmt = $pdo->prepare("SELECT competition_id, name FROM distance_combinations WHERE id = ?");
    $dcStmt->execute([$doelDcId]);
    $doelDc = $dcStmt->fetch(PDO::FETCH_ASSOC);
    if (!$doelDc || $doelDc['competition_id'] !== $compId) {
        http_response_code(400);
        echo json_encode(['error' => 'Doel-DC buiten deze wedstrijd']);
        exit;
    }

    // INSERT IGNORE: als persoon al ingeschreven in doel-DC, niets nieuws
    // (zou kunnen als operator handmatig al wat veranderd had).
    $insEntry = $pdo->prepare("
        INSERT IGNORE INTO entries (distance_combination_id, person_license, status)
        VALUES (?, ?, 1)
    ");
    $delEntry = $pdo->prepare("DELETE FROM entries WHERE id = ?");
    // heat_entries van OUDE DC verwijderen — die kunnen niet meeverhuizen
    // (andere heats). Operator genereert startlijst voor doel-DC opnieuw.
    $delHe = $pdo->prepare("
        DELETE he FROM heat_entries he
        JOIN heats h ON h.id = he.heat_id
        WHERE h.competition_id = ?
          AND h.distance_combination_id = ?
          AND he.person_license = ?
    ");

    $verplaatst = 0;
    $alAanwezig = 0;
    $heWeg      = 0;
    foreach ($entries as $e) {
        $insEntry->execute([$doelDcId, $e['person_license']]);
        if ($insEntry->rowCount() === 0) $alAanwezig++;
        else                              $verplaatst++;
        $delHe->execute([$compId, $e['dc_id'], $e['person_license']]);
        $heWeg += $delHe->rowCount();
        $delEntry->execute([$e['id']]);
    }

    echo json_encode([
        'ok'           => true,
        'verplaatst'   => $verplaatst,
        'al_aanwezig'  => $alAanwezig,
        'he_verwijderd'=> $heWeg,
        'doel_dc_naam' => $doelDc['name'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'verwijder') {
    // Voor het geval de PERSOON correct is maar in de verkeerde DC zit
    // (Roan Vos HJA #87 staat in CSV als vrouw → ingeschreven in DJA-DC).
    // Geen kandidaat om mee te vervangen — gewoon weghalen uit deze DC.
    // Operator schrijft 'm zelf in de juiste DC via beheer-panel.
    if (!$_isSchrijver) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen rechten om koppelingen te wijzigen']);
        exit;
    }
    $entryIds = $body['entry_ids'] ?? [];
    if (!is_array($entryIds) || !count($entryIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'entry_ids (array) verplicht']);
        exit;
    }
    $entryIds = array_values(array_filter(array_map('intval', $entryIds)));

    // Comp-id + dc-id + license per entry voor consistentie-check en cleanup
    // van bijbehorende heat_entries.
    $ph = implode(',', array_fill(0, count($entryIds), '?'));
    $eStmt = $pdo->prepare("
        SELECT e.id, e.person_license, e.distance_combination_id AS dc_id,
               dc.competition_id
        FROM entries e
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        WHERE e.id IN ($ph)
    ");
    $eStmt->execute($entryIds);
    $entries = $eStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($entries) !== count($entryIds)) {
        http_response_code(404);
        echo json_encode(['error' => 'Niet alle entries gevonden']);
        exit;
    }
    $comps = array_unique(array_column($entries, 'competition_id'));
    if (count($comps) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Entries spannen meerdere wedstrijden']);
        exit;
    }
    checkCompetitieToegang($pdo, $_authUser, $comps[0]);
    $compId = $comps[0];

    $delEntry = $pdo->prepare("DELETE FROM entries WHERE id = ?");
    $delHe    = $pdo->prepare("
        DELETE he FROM heat_entries he
        JOIN heats h ON h.id = he.heat_id
        WHERE h.competition_id = ?
          AND h.distance_combination_id = ?
          AND he.person_license = ?
    ");
    $entriesWeg = 0;
    $heWeg      = 0;
    foreach ($entries as $e) {
        // heat_entries eerst (zelfde DC, zelfde persoon)
        $delHe->execute([$compId, $e['dc_id'], $e['person_license']]);
        $heWeg += $delHe->rowCount();
        $delEntry->execute([$e['id']]);
        $entriesWeg++;
    }
    echo json_encode([
        'ok'          => true,
        'verwijderd'  => $entriesWeg,
        'he_verwijderd' => $heWeg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende actie']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
