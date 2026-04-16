<?php
// ============================================================
//  InlineComp – Tijdschema v2
//
//  GET  ?competition_id=X          → volledig schema ophalen
//  POST action=init                → schema aanmaken
//  POST action=save_systeem        → wedstrijd-breed systeem opslaan
//  POST action=save_afstand        → afstand-config + cat-heats opslaan
//  POST action=save_blokken        → programma-volgorde opslaan
//  POST action=add_pauze           → pauze toevoegen
//  POST action=add_inrijden        → inrijd-blok toevoegen
//  POST action=add_wedstrijdstart  → wedstrijdstart-blok toevoegen (max 1)
//  POST action=add_ceremonie       → ceremonie-blok toevoegen
//  POST action=save_blok           → duur / inrijd-cats opslaan
//  POST action=genereer            → ritten genereren
//  POST action=herorden_ritten     → volgorde ritten aanpassen
//  POST action=save_rit_override   → starttijd-override + opmerking per heat opslaan
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !kanSchrijven($_authUser, 'tijdschema')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor tijdschema.']);
    exit;
}

// ── Schema ophalen ────────────────────────────────────────────────────────────

function fetchSchema(PDO $pdo, string $compId): ?array {
    $s = $pdo->prepare("SELECT * FROM competition_tijdschema WHERE competition_id = ?");
    $s->execute([$compId]);
    $schema = $s->fetch(PDO::FETCH_ASSOC);
    if (!$schema) return null;
    $tsId = (int)$schema['id'];

    $s = $pdo->prepare(
        "SELECT * FROM tijdschema_afstand_config WHERE tijdschema_id = ? ORDER BY afstand_naam"
    );
    $s->execute([$tsId]);
    $schema['afstand_configs'] = $s->fetchAll(PDO::FETCH_ASSOC);

    $s = $pdo->prepare("SELECT * FROM tijdschema_cat_config WHERE tijdschema_id = ?");
    $s->execute([$tsId]);
    $schema['cat_configs'] = array_map(function ($c) {
        $c['heeft_heats']        = (bool)$c['heeft_heats'];
        $c['heeft_kwartfinale']  = (bool)$c['heeft_kwartfinale'];
        $c['heeft_halve_finale'] = (bool)$c['heeft_halve_finale'];
        $c['heeft_runner_up']    = (bool)$c['heeft_runner_up'];
        return $c;
    }, $s->fetchAll(PDO::FETCH_ASSOC));

    $s = $pdo->prepare(
        "SELECT * FROM tijdschema_blokken WHERE tijdschema_id = ? ORDER BY volgorde, id"
    );
    $s->execute([$tsId]);
    $schema['blokken'] = $s->fetchAll(PDO::FETCH_ASSOC);

    $s = $pdo->prepare(
        "SELECT * FROM tijdschema_ritten WHERE tijdschema_id = ? ORDER BY volgorde, id"
    );
    $s->execute([$tsId]);
    $schema['ritten'] = $s->fetchAll(PDO::FETCH_ASSOC);

    $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
    $vStmt->execute([$compId]);
    $schema['tijdschema_version'] = (int)($vStmt->fetchColumn() ?? 0);

    // Check of er al startlijsten (heats) gegenereerd zijn voor deze wedstrijd
    $hStmt = $pdo->prepare("SELECT COUNT(*) FROM heats WHERE competition_id = ? AND ronde = 1");
    $hStmt->execute([$compId]);
    $schema['heeft_loting'] = (int)$hStmt->fetchColumn() > 0;

    return $schema;
}

// ── Blokken synchroniseren na afstand-opslaan ─────────────────────────────────

// Bepaal welke ronde-blokken een afstand nodig heeft op basis van alle cat-configs
function enabledRondesVoorAfstand(PDO $pdo, int $tsId, string $afstandNaam, string $compId): array {
    // Haal alle cat-configs op voor deze afstand (via distance naam)
    $s = $pdo->prepare("
        SELECT cc.*
        FROM tijdschema_cat_config cc
        JOIN distances d      ON d.id  = cc.distance_id
        JOIN distance_combinations dc ON dc.id = cc.dc_id
        WHERE cc.tijdschema_id = ?
          AND d.name = ?
          AND dc.competition_id = ?
    ");
    $s->execute([$tsId, $afstandNaam, $compId]);
    $cats = $s->fetchAll(PDO::FETCH_ASSOC);

    $rondes = ['finale']; // finale altijd aanwezig
    foreach ($cats as $c) {
        if (!empty($c['heeft_heats'])        && !in_array('heats',        $rondes, true)) $rondes[] = 'heats';
        if (!empty($c['heeft_kwartfinale'])  && !in_array('kwartfinale',  $rondes, true)) $rondes[] = 'kwartfinale';
        if (!empty($c['heeft_halve_finale']) && !in_array('halve_finale', $rondes, true)) $rondes[] = 'halve_finale';
        if (!empty($c['heeft_runner_up'])    && !in_array('runner_up',    $rondes, true)) $rondes[] = 'runner_up';
    }

    // Canonieke volgorde
    $volgorde = ['heats', 'kwartfinale', 'halve_finale', 'runner_up', 'finale'];
    usort($rondes, fn($a, $b) => array_search($a, $volgorde) <=> array_search($b, $volgorde));
    return $rondes;
}

function syncBlokken(PDO $pdo, int $tsId, string $afstandNaam, array $enabledRondes): void {
    $s = $pdo->prepare(
        "SELECT * FROM tijdschema_blokken
         WHERE tijdschema_id = ? AND afstand_naam = ? AND blok_type = 'ronde'
         ORDER BY volgorde"
    );
    $s->execute([$tsId, $afstandNaam]);
    $bestaand      = $s->fetchAll(PDO::FETCH_ASSOC);
    $bestaandTypes = array_column($bestaand, 'ronde_type');

    // Verwijder blokken voor uitgeschakelde rondes
    foreach ($bestaand as $blok) {
        if (!in_array($blok['ronde_type'], $enabledRondes, true)) {
            $pdo->prepare("DELETE FROM tijdschema_blokken WHERE id = ?")->execute([$blok['id']]);
        }
    }

    // Voeg ontbrekende blokken toe aan het einde
    $s = $pdo->prepare("SELECT COALESCE(MAX(volgorde),0) FROM tijdschema_blokken WHERE tijdschema_id = ?");
    $s->execute([$tsId]);
    $maxVolgorde = (int)$s->fetchColumn();

    $ins = $pdo->prepare(
        "INSERT INTO tijdschema_blokken (tijdschema_id, volgorde, blok_type, afstand_naam, ronde_type)
         VALUES (?,?,?,?,?)"
    );
    foreach ($enabledRondes as $ronde) {
        if (!in_array($ronde, $bestaandTypes, true)) {
            $ins->execute([$tsId, ++$maxVolgorde, 'ronde', $afstandNaam, $ronde]);
        }
    }
}

// ── Categorie-sortering (jong→oud, vrouwen voor mannen) ───────────────────────

/**
 * Sorteersleutel voor distance combinations.
 *
 * Primair: gebruik KNSB category_filter codes (bijv. "DJA*,DS*")
 *   - Geslacht: D/V = vrouwen (0), H = heren (1)
 *   - Leeftijd: P4..M (0..8), gecombineerd → oudste bepaalt positie
 *
 * Fallback op dc_naam als category_filter leeg is.
 */
function catSorteerSleutel(string $naam, string $catFilter = ''): string {

    // KNSB leeftijdscode → rang (0 = jongste, 8 = oudste)
    $ageCodeRank = [
        'P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3,
        'K'  => 4,
        'JB' => 5, 'JA' => 6,
        'S'  => 7,
        'M'  => 8,
    ];

    $maxAge = -1;
    $gk     = '1'; // mannen standaard

    $filter = trim($catFilter);
    if ($filter !== '') {
        // Splits op komma; strip wildcards/spaties; bijv. ["DJA*","DS*","HJB*"]
        $codes = preg_split('/[\s,]+/', strtoupper($filter));
        foreach ($codes as $raw) {
            $code = trim($raw, '* ');
            if (strlen($code) < 2) continue;

            // Geslacht: eerste letter bepaalt (D/V = dames, H = heren)
            if ($code[0] === 'D' || $code[0] === 'V') {
                $gk = '0';
            }

            // Leeftijdscode: alles na de geslachtsletter (bijv. 'JA', 'P4', 'S').
            // Gebruik progressieve afkapping voor rauwe KNSB-codes zoals 'DJAA' → 'JAA' → 'JA'.
            $ageStr = substr($code, 1);
            while ($ageStr !== '' && !isset($ageCodeRank[$ageStr])) {
                $ageStr = substr($ageStr, 0, -1);
            }
            if ($ageStr !== '') {
                $maxAge = max($maxAge, $ageCodeRank[$ageStr]);
            }
        }
    }

    // ── Fallback: parse dc_naam als category_filter ontbreekt ────────────────
    if ($maxAge < 0) {
        $n = mb_strtolower(trim($naam));
        $leeftijdPatronen = [
            0 => ['pupillen 4', 'pup 4', 'pup4', 'p4'],
            1 => ['pupillen 3', 'pup 3', 'pup3', 'p3'],
            2 => ['pupillen 2', 'pup 2', 'pup2', 'p2'],
            3 => ['pupillen 1', 'pup 1', 'pup1', 'p1'],
            4 => ['kadetten', 'kad'],
            5 => ['junioren b', 'jun b', 'jun. b', 'jb'],
            6 => ['junioren a', 'jun a', 'jun. a', 'ja'],
            7 => ['senioren', 'sen'],
            8 => ['masters', 'mast', 'mas'],
        ];
        foreach ($leeftijdPatronen as $idx => $patronen) {
            foreach ($patronen as $p) {
                if (str_contains($n, $p)) {
                    $maxAge = max($maxAge, $idx);
                    break;
                }
            }
        }
        // Vrouwen vóór mannen (via naam)
        $vrouwPatronen = ['vrouwen', 'dames', 'meisjes', 'girls', 'women', 'ladies'];
        foreach ($vrouwPatronen as $p) {
            if (str_contains($n, $p)) { $gk = '0'; break; }
        }
        if ($gk === '1' && preg_match('/^[dv][jkpsm]/', $n)) {
            $gk = '0';
        }
    }

    $lk = $maxAge >= 0
        ? str_pad((string)$maxAge, 2, '0', STR_PAD_LEFT)
        : '99';

    return $lk . $gk . mb_strtolower(trim($naam));
}

// ── Even verdeling hulpfunctie ────────────────────────────────────────────────

// Gelijke verdeling – eerste heats iets groter bij oneven
function verdeel(int $n, int $k): array {
    if ($k <= 0) return [];
    $basis  = (int)floor($n / $k);
    $extra  = $n % $k;
    $result = [];
    for ($i = 0; $i < $k; $i++) {
        $result[] = $basis + ($i < $extra ? 1 : 0);
    }
    return $result;
}

// Verdeling voor runner-up: laatste heat is de grootste
function verdeelLaatstGrootst(int $n, int $k): array {
    if ($k <= 0) return [];
    $basis  = (int)floor($n / $k);
    $extra  = $n % $k;
    $result = [];
    for ($i = 0; $i < $k; $i++) {
        $result[] = $basis + ($i >= $k - $extra ? 1 : 0);
    }
    return $result;
}

// Verdeling runner-up heats met min. per heat:
//   - Zonder min (ruMin=0): gelijkmatige verdeling via verdeelLaatstGrootst
//   - Met min (ruMin>0):
//       · Eerste heats krijgen elk precies ruMax rijders
//       · Laatste heat krijgt het restant
//       · Als restant < ruMin: samenvoegen met vorige heat (nHeats--)
// Geeft array van heatgroottes terug [heat1, heat2, …, heatN]
function verdeelRunnerUpHeats(int $uitv, int $ruMax, int $ruMin): array {
    if ($uitv <= 0) return [];
    $nHeats = max(1, (int)ceil($uitv / $ruMax));

    if ($ruMin <= 0) {
        // Origineel gedrag: gelijkmatig, laatste is grootste
        return verdeelLaatstGrootst($uitv, $nHeats);
    }

    // Min-check: merge laatste heat als die te klein is
    while ($nHeats > 1) {
        $last = $uitv - $ruMax * ($nHeats - 1);
        if ($last < $ruMin) {
            $nHeats--;
        } else {
            break;
        }
    }

    if ($nHeats === 1) return [$uitv];

    // Eerste (nHeats-1) heats krijgen elk ruMax; laatste krijgt de rest
    $result = array_fill(0, $nHeats - 1, $ruMax);
    $result[] = $uitv - $ruMax * ($nHeats - 1);
    return $result;
}

// ── Genereer-algoritme ────────────────────────────────────────────────────────

function genereerRitten(PDO $pdo, int $tsId, string $compId, ?array $catVanJS = null): void {
    $pdo->prepare("DELETE FROM tijdschema_ritten WHERE tijdschema_id = ?")->execute([$tsId]);

    // Wedstrijd-breed systeem
    $s = $pdo->prepare("SELECT systeem FROM competition_tijdschema WHERE id = ?");
    $s->execute([$tsId]);
    $systeem = (string)$s->fetchColumn();

    // Afstand-configs (gedeelde Q/q + finale HG)
    $s = $pdo->prepare("SELECT * FROM tijdschema_afstand_config WHERE tijdschema_id = ?");
    $s->execute([$tsId]);
    $configPerAfstand = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $cfg) {
        $configPerAfstand[$cfg['afstand_naam']] = $cfg;
    }

    // Cat-configs (per categorie alle ronde-instellingen)
    $s = $pdo->prepare("SELECT * FROM tijdschema_cat_config WHERE tijdschema_id = ?");
    $s->execute([$tsId]);
    $catConfigMap = []; // dc_id|distance_id → config
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $catConfigMap[$c['dc_id'] . '|' . $c['distance_id']] = $c;
    }

    // Programma-blokken
    $s = $pdo->prepare("SELECT * FROM tijdschema_blokken WHERE tijdschema_id = ? ORDER BY volgorde, id");
    $s->execute([$tsId]);
    $blokken = $s->fetchAll(PDO::FETCH_ASSOC);

    // Deelnemersaantallen per dc_id
    $s = $pdo->prepare("
        SELECT e.distance_combination_id AS dc_id, COUNT(*) AS n
        FROM entries e
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        WHERE dc.competition_id = ? AND e.status IN (1, 5)
        GROUP BY e.distance_combination_id
    ");
    $s->execute([$compId]);
    $tellingen = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tellingen[$r['dc_id']] = (int)$r['n'];
    }

    // ── Categorieën per afstandsnaam ──────────────────────────────────────────
    // Voorkeur: gebruik de lijst die JS heeft doorgegeven (verwerkt merges + splits).
    // Fallback: eigen SQL-query (backward compat, geen merges/splits).
    $catsPerAfstand = [];

    if (!empty($catVanJS) && is_array($catVanJS)) {
        // category_filter ophalen voor sortering (niet in de JS-payload)
        $cfStmt = $pdo->prepare(
            "SELECT id, category_filter FROM distance_combinations WHERE competition_id = ?"
        );
        $cfStmt->execute([$compId]);
        $cfMap = [];
        foreach ($cfStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cfMap[$r['id']] = $r['category_filter'] ?? '';
        }

        foreach ($catVanJS as $ce) {
            $n = (int)($ce['n'] ?? 0);
            if ($n <= 0) continue;
            $afstandNaam = trim((string)($ce['afstand_naam'] ?? ''));
            if ($afstandNaam === '') continue;
            $dcId = (string)($ce['dc_id'] ?? '');
            // Gebruik category_filter van JS als die er is (bijv. afgeleid van KNSB-codes per split),
            // anders de DB-waarde (voor niet-gesplitste categorieën).
            $catFilter = (string)($ce['category_filter'] ?? '');
            if ($catFilter === '') $catFilter = $cfMap[$dcId] ?? '';
            $catsPerAfstand[$afstandNaam][] = [
                'dc_id'          => $dcId,
                'dc_naam'        => (string)($ce['dc_naam']      ?? ''),
                'category_filter'=> $catFilter,
                'distance_id'    => $ce['distance_id'] ?: null,
                'n'              => $n,
            ];
        }
    } else {
        // Fallback: originele SQL (geen merge/split bewustzijn)
        $s = $pdo->prepare("
            SELECT dc.id AS dc_id, dc.name AS dc_naam,
                   dc.category_filter,
                   d.id  AS distance_id, d.name AS distance_naam
            FROM distance_combinations dc
            JOIN distances d ON d.distance_combination_id = dc.id
            WHERE dc.competition_id = ?
              AND (d.target_group IS NULL OR d.target_group = '')
            ORDER BY d.name, dc.name
        ");
        $s->execute([$compId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = $tellingen[$r['dc_id']] ?? 0;
            if ($n === 0) continue;
            $catsPerAfstand[$r['distance_naam']][] = [
                'dc_id'          => $r['dc_id'],
                'dc_naam'        => $r['dc_naam'],
                'category_filter'=> $r['category_filter'] ?? '',
                'distance_id'    => $r['distance_id'],
                'n'              => $n,
            ];
        }
    }

    // ── Full-final: auto-aanmaken van ontbrekende finale-blokken ─────────────────
    // Als de gebruiker de afstandskaarten nog niet heeft opgeslagen, bestaan er
    // nog geen ronde-blokken. Voor full-final heeft de finale altijd een blok nodig.
    if ($systeem === 'full-final') {
        $afstandenMetFinaleBlok = [];
        foreach ($blokken as $b) {
            if ($b['blok_type'] === 'ronde' && $b['ronde_type'] === 'finale') {
                $afstandenMetFinaleBlok[] = $b['afstand_naam'];
            }
        }
        $s = $pdo->prepare(
            "SELECT COALESCE(MAX(volgorde),0) FROM tijdschema_blokken WHERE tijdschema_id = ?"
        );
        $s->execute([$tsId]);
        $maxVolgorde = (int)$s->fetchColumn();

        $ins = $pdo->prepare(
            "INSERT INTO tijdschema_blokken
                 (tijdschema_id, volgorde, blok_type, afstand_naam, ronde_type)
             VALUES (?,?,?,?,?)"
        );
        foreach (array_keys($catsPerAfstand) as $afNaam) {
            if (!in_array($afNaam, $afstandenMetFinaleBlok, true)) {
                $ins->execute([$tsId, ++$maxVolgorde, 'ronde', $afNaam, 'finale']);
                $blokken[] = [
                    'id'            => (int)$pdo->lastInsertId(),
                    'tijdschema_id' => $tsId,
                    'volgorde'      => $maxVolgorde,
                    'blok_type'     => 'ronde',
                    'afstand_naam'  => $afNaam,
                    'ronde_type'    => 'finale',
                    'heat_duur'     => null,
                    'duur'          => null,
                    'label'         => null,
                    'dc_ids'        => null,
                    'tijdstip'      => null,
                ];
            }
        }
    }

    $ritten   = [];
    $volgorde = 1;

    foreach ($blokken as $blok) {
        if ($blok['blok_type'] !== 'ronde') continue; // sla pauze en inrijden over

        $afstandNaam = $blok['afstand_naam'];
        $rondeType   = $blok['ronde_type'];
        $afCfg       = $configPerAfstand[$afstandNaam] ?? null;
        $alleCats    = $catsPerAfstand[$afstandNaam]   ?? [];
        if (empty($alleCats)) continue;
        // Vaste volgorde: jong→oud, vrouwen voor mannen
        usort($alleCats, fn($a, $b) =>
            catSorteerSleutel($a['dc_naam'], $a['category_filter'] ?? '')
            <=>
            catSorteerSleutel($b['dc_naam'], $b['category_filter'] ?? '')
        );

        // Gedeelde instellingen voor deze afstand
        $finaleHg       = max(2, (int)($afCfg['finale_heat_grootte'] ?? 6));
        $bFinaleHg      = max($finaleHg, max(2, (int)($afCfg['finale_b_grootte'] ?? 6)));
        $bLaatstGrootst = !empty($afCfg['laatste_b_grootste']);
        $qD             = (int)($afCfg['q_direct'] ?? 2);
        $qT             = (int)($afCfg['q_tijd']   ?? 0);
        $blokId         = (int)$blok['id'];

        switch ($rondeType) {

            case 'heats':
                // Gegroepeerd per categorie (jong→oud, vrouw voor man)
                foreach ($alleCats as $cat) {
                    $cc = $catConfigMap[$cat['dc_id'] . '|' . $cat['distance_id']] ?? null;
                    if (!$cc || empty($cc['heeft_heats'])) continue;
                    $nH        = max(1, (int)($cc['heats_aantal'] ?? 1));
                    $aantallen = verdeel($cat['n'], $nH);
                    for ($h = 1; $h <= $nH; $h++) {
                        $ritten[] = [
                            'blok_id'      => $blokId,
                            'volgorde'     => $volgorde++,
                            'dc_id'        => $cat['dc_id'],
                            'distance_id'  => $cat['distance_id'],
                            'afstand_naam' => $afstandNaam,
                            'ronde_type'   => 'heats',
                            'finale_label' => null,
                            'heat_nr'      => $h,
                            'rit_naam'     => "Series {$afstandNaam} Heat {$h} – {$cat['dc_naam']}",
                            'dc_naam'      => $cat['dc_naam'],
                            'verwacht'     => $aantallen[$h - 1] ?? 0,
                        ];
                    }
                }
                break;

            case 'kwartfinale':
                // Alleen categorieën met kwartfinale
                foreach ($alleCats as $cat) {
                    $cc = $catConfigMap[$cat['dc_id'] . '|' . $cat['distance_id']] ?? null;
                    if (!$cc || empty($cc['heeft_kwartfinale'])) continue;
                    // Input kwartfinale: als geen series → alle rijders, anders output series
                    $rijders = empty($cc['heeft_heats'])
                        ? $cat['n']
                        : max(0, (int)($cc['heats_q'] ?? 0));
                    $nHeats    = max(1, (int)($cc['kwart_heats'] ?? 1));
                    if ($rijders === 0) continue;
                    $aantallen = verdeel($rijders, $nHeats);
                    for ($h = 1; $h <= $nHeats; $h++) {
                        $ritten[] = [
                            'blok_id'      => $blokId,
                            'volgorde'     => $volgorde++,
                            'dc_id'        => $cat['dc_id'],
                            'distance_id'  => $cat['distance_id'],
                            'afstand_naam' => $afstandNaam,
                            'ronde_type'   => 'kwartfinale',
                            'finale_label' => null,
                            'heat_nr'      => $h,
                            'rit_naam'     => "Kwartfinale {$afstandNaam} Heat {$h} – {$cat['dc_naam']}",
                            'dc_naam'      => $cat['dc_naam'],
                            'verwacht'     => $aantallen[$h - 1] ?? 0,
                        ];
                    }
                }
                break;

            case 'halve_finale':
                foreach ($alleCats as $cat) {
                    $cc = $catConfigMap[$cat['dc_id'] . '|' . $cat['distance_id']] ?? null;
                    if (!$cc || empty($cc['heeft_halve_finale'])) continue;
                    // Input halve finale: output kwart → output series → alle rijders
                    if (!empty($cc['heeft_kwartfinale'])) {
                        $rijders = max(0, (int)($cc['kwart_door'] ?? 0));
                    } elseif (!empty($cc['heeft_heats'])) {
                        $rijders = max(0, (int)($cc['heats_q'] ?? 0));
                    } else {
                        $rijders = $cat['n']; // geen series, geen kwart → alle rijders
                    }
                    $nHeats = max(1, (int)($cc['half_heats'] ?? 1));
                    if ($rijders === 0) continue;
                    $aantallen = verdeel($rijders, $nHeats);
                    for ($h = 1; $h <= $nHeats; $h++) {
                        $ritten[] = [
                            'blok_id'      => $blokId,
                            'volgorde'     => $volgorde++,
                            'dc_id'        => $cat['dc_id'],
                            'distance_id'  => $cat['distance_id'],
                            'afstand_naam' => $afstandNaam,
                            'ronde_type'   => 'halve_finale',
                            'finale_label' => null,
                            'heat_nr'      => $h,
                            'rit_naam'     => "Halve finale {$afstandNaam} Heat {$h} – {$cat['dc_naam']}",
                            'dc_naam'      => $cat['dc_naam'],
                            'verwacht'     => $aantallen[$h - 1] ?? 0,
                        ];
                    }
                }
                break;

            case 'runner_up':
                if (empty($afCfg['heeft_runner_up'])) continue 2;
                $ruMax = max(2, (int)($afCfg['runner_up_max'] ?? 6));
                $ruMin = max(0, (int)($afCfg['runner_up_min'] ?? 0));
                foreach ($alleCats as $cat) {
                    $cc     = $catConfigMap[$cat['dc_id'] . '|' . $cat['distance_id']] ?? null;
                    // Runner-up = rijders die niet voorbij de series zijn gekomen.
                    // Als er geen series zijn, of iedereen gaat door, is er geen runner-up.
                    if (empty($cc['heeft_heats'])) continue;
                    $heatsQ     = (int)($cc['heats_q'] ?? 0);
                    $uitvallers = max(0, $cat['n'] - $heatsQ);
                    if ($uitvallers === 0) continue;
                    $aantallen = verdeelRunnerUpHeats($uitvallers, $ruMax, $ruMin);
                    $nHeats    = count($aantallen);

                    // Bereken plaatsnummers per heat (1-geïndexeerd)
                    // Heat 1 → hoogste plaatsen (net na de gekwalificeerden)
                    // Heat nHeats → laagste plaatsen (grootste heat)
                    $plekStart = [];
                    $cumul     = $heatsQ;
                    for ($i = 0; $i < $nHeats; $i++) {
                        $plekStart[$i] = $cumul + 1;
                        $cumul        += $aantallen[$i];
                    }

                    // Rijvolgorde omgekeerd: heat nHeats eerst, heat 1 als laatste
                    for ($h = $nHeats; $h >= 1; $h--) {
                        $idx    = $h - 1;
                        $van    = $plekStart[$idx];
                        $tot    = $plekStart[$idx] + $aantallen[$idx] - 1;
                        $plekLbl = $van === $tot ? "plek {$van}" : "plek {$van}-{$tot}";
                        $heatLbl = $nHeats > 1 ? " {$h}" : '';
                        $ritten[] = [
                            'blok_id'      => $blokId,
                            'volgorde'     => $volgorde++,
                            'dc_id'        => $cat['dc_id'],
                            'distance_id'  => $cat['distance_id'],
                            'afstand_naam' => $afstandNaam,
                            'ronde_type'   => 'runner_up',
                            'finale_label' => null,
                            'heat_nr'      => $h,
                            'rit_naam'     => "Runner-up{$heatLbl} {$afstandNaam} – {$cat['dc_naam']} ({$plekLbl})",
                            'dc_naam'      => $cat['dc_naam'],
                            'verwacht'     => $aantallen[$idx] ?? 0,
                        ];
                    }
                }
                break;

            case 'finale':
                foreach ($alleCats as $cat) {
                    $cc = $catConfigMap[$cat['dc_id'] . '|' . $cat['distance_id']] ?? null;

                    // Full-final: iedereen naar finale, op tijd ingedeeld in heats
                    if ($systeem === 'full-final') {
                        $rijders = $cat['n'];
                    } elseif (!$cc || empty($cc['heeft_heats'])) {
                        // Geen series: doorstroom via laatste actieve ronde
                        if (!empty($cc['heeft_halve_finale'])) {
                            $rijders = max(0, (int)($cc['half_door'] ?? 0));
                        } elseif (!empty($cc['heeft_kwartfinale'])) {
                            $rijders = max(0, (int)($cc['kwart_door'] ?? 0));
                        } else {
                            $rijders = $cat['n'];
                        }
                    } elseif (!empty($cc['heeft_halve_finale'])) {
                        $rijders = max(0, (int)($cc['half_door'] ?? 0));
                    } elseif (!empty($cc['heeft_kwartfinale'])) {
                        $rijders = max(0, (int)($cc['kwart_door'] ?? 0));
                    } else {
                        $rijders = max(0, (int)($cc['heats_q'] ?? $cat['n']));
                    }
                    if ($rijders <= 0) continue;

                    if ($systeem === 'full-final') {
                        // A-finale: top $finaleHg rijders
                        $aRijders  = min($rijders, $finaleHg);
                        $bRijders  = max(0, $rijders - $aRijders);
                        $catRitten = [];

                        // B-finales: resterende rijders verdeeld
                        if ($bRijders > 0) {
                            $nBHeats    = max(1, (int)ceil($bRijders / $bFinaleHg));
                            $bAantallen = $bLaatstGrootst
                                ? verdeelLaatstGrootst($bRijders, $nBHeats)
                                : verdeel($bRijders, $nBHeats);
                            for ($b = 1; $b <= $nBHeats; $b++) {
                                $catRitten[] = [
                                    'blok_id'      => $blokId,
                                    'volgorde'     => 0,
                                    'dc_id'        => $cat['dc_id'],
                                    'distance_id'  => $cat['distance_id'],
                                    'afstand_naam' => $afstandNaam,
                                    'ronde_type'   => 'finale_b',
                                    'finale_label' => 'B' . $b,
                                    'heat_nr'      => $b,
                                    'rit_naam'     => "B{$b}-finale {$afstandNaam} – {$cat['dc_naam']}",
                                    'dc_naam'      => $cat['dc_naam'],
                                    'verwacht'     => $bAantallen[$b - 1] ?? 0,
                                ];
                            }
                            // Volgorde omkeren: Bn eerst, B1 als laatste voor A-finale
                            $catRitten = array_reverse($catRitten);
                        }

                        // A-finale altijd als laatste
                        $catRitten[] = [
                            'blok_id'      => $blokId,
                            'volgorde'     => 0,
                            'dc_id'        => $cat['dc_id'],
                            'distance_id'  => $cat['distance_id'],
                            'afstand_naam' => $afstandNaam,
                            'ronde_type'   => 'finale_a',
                            'finale_label' => 'A',
                            'heat_nr'      => 1,
                            'rit_naam'     => "A-finale {$afstandNaam} – {$cat['dc_naam']}",
                            'dc_naam'      => $cat['dc_naam'],
                            'verwacht'     => $aRijders,
                        ];

                        foreach ($catRitten as $r) {
                            $r['volgorde'] = $volgorde++;
                            $ritten[] = $r;
                        }
                    } else {
                        $aRijders = $rijders;

                        // ── A-finale (1 of meer heats) ───────────────────────
                        $nFinaleHeats = max(1, (int)($cc['finale_heats'] ?? 1));
                        $verwachtPerHeat = (int)ceil($rijders / max(1, $nFinaleHeats));
                        for ($fh = 1; $fh <= $nFinaleHeats; $fh++) {
                            $fhVerwacht = min($verwachtPerHeat, $rijders - $verwachtPerHeat * ($fh - 1));
                            $fhNaam = $nFinaleHeats > 1
                                ? "A-finale heat {$fh} {$afstandNaam} – {$cat['dc_naam']}"
                                : "A-finale {$afstandNaam} – {$cat['dc_naam']}";
                            $ritten[] = [
                                'blok_id'      => $blokId,
                                'volgorde'     => $volgorde++,
                                'dc_id'        => $cat['dc_id'],
                                'distance_id'  => $cat['distance_id'],
                                'afstand_naam' => $afstandNaam,
                                'ronde_type'   => 'finale_a',
                                'finale_label' => 'A',
                                'heat_nr'      => $fh,
                                'rit_naam'     => $fhNaam,
                                'dc_naam'      => $cat['dc_naam'],
                                'verwacht'     => max(0, $fhVerwacht),
                            ];
                        }
                    }
                }
                break;
        }
    }

    $ins = $pdo->prepare("
        INSERT INTO tijdschema_ritten
            (tijdschema_id, blok_id, volgorde, dc_id, distance_id, afstand_naam,
             ronde_type, finale_label, heat_nr, rit_naam, dc_naam, verwacht)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    foreach ($ritten as $r) {
        $ins->execute([
            $tsId,
            $r['blok_id'], $r['volgorde'],
            $r['dc_id'], $r['distance_id'], $r['afstand_naam'],
            $r['ronde_type'], $r['finale_label'], $r['heat_nr'],
            $r['rit_naam'], $r['dc_naam'], $r['verwacht'],
        ]);
    }

    // Bewaar tijdstip van laatste generatie
    $pdo->prepare("UPDATE competition_tijdschema SET gegenereerd_op = NOW() WHERE id = ?")
        ->execute([$tsId]);
}

// ── Request-afhandeling ───────────────────────────────────────────────────────

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $compId = trim($_GET['competition_id'] ?? '');
        if (!$compId) {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id ontbreekt']);
            exit;
        }
        if (isset($_GET['check_version'])) {
            $stmt = $pdo->prepare("SELECT entries_version, tijdschema_version FROM competitions WHERE id = ?");
            $stmt->execute([$compId]);
            $v = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['entries_version' => 0, 'tijdschema_version' => 0];
            echo json_encode(['entries_version' => (int)$v['entries_version'], 'tijdschema_version' => (int)$v['tijdschema_version']]);
            exit;
        }
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Gebruik GET of POST']);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    // Helper: competition_id ophalen uit tijdschema_id
    $getCompId = function (int $tsId) use ($pdo): string {
        $s = $pdo->prepare("SELECT competition_id FROM competition_tijdschema WHERE id = ?");
        $s->execute([$tsId]);
        return (string)$s->fetchColumn();
    };

    // ── Schema aanmaken ───────────────────────────────────────────────────────
    if ($action === 'init') {
        $compId = trim($body['competition_id'] ?? '');
        if (!$compId) {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id ontbreekt']);
            exit;
        }
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $pdo->prepare("INSERT IGNORE INTO competition_tijdschema (competition_id) VALUES (?)")
            ->execute([$compId]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Systeem opslaan ───────────────────────────────────────────────────────
    if ($action === 'save_systeem') {
        $tsId    = (int)($body['tijdschema_id'] ?? 0);
        $systeem = $body['systeem'] ?? '';
        $reset   = !empty($body['reset']);
        $toegestaan = ['full-final', 'internationaal-nieuw'];
        if (!$tsId || !in_array($systeem, $toegestaan, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ongeldige invoer']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        if ($reset) {
            // Verwijder alles: heats (lotingen), ritten, blokken, configuratie
            $pdo->prepare("DELETE FROM heats WHERE competition_id = ?")->execute([$compId]);
            foreach (['tijdschema_ritten', 'tijdschema_blokken',
                      'tijdschema_cat_config', 'tijdschema_afstand_config'] as $tbl) {
                $pdo->prepare("DELETE FROM {$tbl} WHERE tijdschema_id = ?")->execute([$tsId]);
            }
        }
        $pdo->prepare("UPDATE competition_tijdschema SET systeem = ? WHERE id = ?")
            ->execute([$systeem, $tsId]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Ranking methods opslaan (vanuit klassement-tab) ─────────────────────
    if ($action === 'save_ranking') {
        $afstandNaam = trim($body['afstand_naam'] ?? '');
        if (!$afstandNaam) {
            http_response_code(400);
            echo json_encode(['error' => 'afstand_naam is verplicht']);
            exit;
        }
        // Zoek tijdschema_id via competition_id
        $compId = trim($body['competition_id'] ?? '');
        $tsIdStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ?");
        $tsIdStmt->execute([$compId]);
        $tsId = (int)$tsIdStmt->fetchColumn();
        if (!$tsId) {
            http_response_code(404);
            echo json_encode(['error' => 'Geen tijdschema gevonden']);
            exit;
        }

        $geldigeRanking = ['time', 'position_time'];
        $updates = [];
        $params  = [];
        foreach (['heats_ranking', 'kwart_ranking', 'half_ranking', 'finale_ranking'] as $col) {
            if (isset($body[$col]) && in_array($body[$col], $geldigeRanking, true)) {
                $updates[] = "$col = ?";
                $params[]  = $body[$col];
            }
        }
        if ($updates) {
            $params[] = $tsId;
            $params[] = $afstandNaam;
            $pdo->prepare("
                UPDATE tijdschema_afstand_config
                SET " . implode(', ', $updates) . "
                WHERE tijdschema_id = ? AND afstand_naam = ?
            ")->execute($params);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Afstand-config opslaan ────────────────────────────────────────────────
    if ($action === 'save_afstand') {
        $tsId        = (int)($body['tijdschema_id'] ?? 0);
        $afstandNaam = trim($body['afstand_naam'] ?? '');
        if (!$tsId || $afstandNaam === '') {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id of afstand_naam ontbreekt']);
            exit;
        }

        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }

        // Gedeelde afstand-instellingen (Q/q + finale HG)
        $qD              = max(0, (int)($body['q_direct'] ?? 2));
        $qT              = max(0, (int)($body['q_tijd']   ?? 0));
        $finaleHg        = max(1, (int)($body['finale_heat_grootte'] ?? 6));
        $finaleBg        = max($finaleHg, (int)($body['finale_b_grootte'] ?? 6));
        $bLaatstGrootst  = !empty($body['laatste_b_grootste']) ? 1 : 0;
        $finaleSeeding   = in_array($body['finale_seeding'] ?? '', ['slang', 'tijdkoppeling'], true)
                         ? $body['finale_seeding'] : 'slang';
        $raceType        = in_array($body['race_type'] ?? '', ['sprint', 'long_distance'], true)
                         ? $body['race_type'] : 'sprint';
        $geldigeRanking  = ['time', 'position_time'];
        $heatsRanking    = in_array($body['heats_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['heats_ranking'] : 'time';
        $kwartRanking    = in_array($body['kwart_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['kwart_ranking'] : 'time';
        $halfRanking     = in_array($body['half_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['half_ranking'] : 'time';
        $finaleRanking   = in_array($body['finale_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['finale_ranking'] : 'time';

        $heeftRU  = !empty($body['heeft_runner_up']) ? 1 : 0;
        $ruMax    = max(2, min(30, (int)($body['runner_up_max'] ?? 6)));
        $ruMin    = max(0, min(30, (int)($body['runner_up_min'] ?? 0)));

        $pdo->prepare("
            INSERT INTO tijdschema_afstand_config
                (tijdschema_id, afstand_naam, q_direct, q_tijd, finale_heat_grootte,
                 finale_b_grootte, laatste_b_grootste, finale_seeding,
                 race_type, heats_ranking, kwart_ranking, half_ranking, finale_ranking,
                 heeft_runner_up, runner_up_max, runner_up_min)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                q_direct            = VALUES(q_direct),
                q_tijd              = VALUES(q_tijd),
                finale_heat_grootte = VALUES(finale_heat_grootte),
                finale_b_grootte    = VALUES(finale_b_grootte),
                laatste_b_grootste  = VALUES(laatste_b_grootste),
                finale_seeding      = VALUES(finale_seeding),
                race_type           = VALUES(race_type),
                heats_ranking       = VALUES(heats_ranking),
                kwart_ranking       = VALUES(kwart_ranking),
                half_ranking        = VALUES(half_ranking),
                finale_ranking      = VALUES(finale_ranking),
                heeft_runner_up     = VALUES(heeft_runner_up),
                runner_up_max       = VALUES(runner_up_max),
                runner_up_min       = VALUES(runner_up_min)
        ")->execute([$tsId, $afstandNaam, $qD, $qT, $finaleHg, $finaleBg, $bLaatstGrootst,
                     $finaleSeeding, $raceType, $heatsRanking, $kwartRanking, $halfRanking, $finaleRanking,
                     $heeftRU, $ruMax, $ruMin]);

        // Per-categorie config opslaan
        $catConfigs = $body['cat_configs'] ?? [];
        $insCC = $pdo->prepare("
            INSERT INTO tijdschema_cat_config
                (tijdschema_id, dc_id, distance_id,
                 heeft_heats, heats_aantal, heats_q, heats_q_heat,
                 heeft_kwartfinale, kwart_heats, kwart_door, kwart_q_heat,
                 heeft_halve_finale, half_heats, half_door, half_q_heat,
                 heeft_runner_up, finale_heats)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                heeft_heats        = VALUES(heeft_heats),
                heats_aantal       = VALUES(heats_aantal),
                heats_q            = VALUES(heats_q),
                heats_q_heat       = VALUES(heats_q_heat),
                heeft_kwartfinale  = VALUES(heeft_kwartfinale),
                kwart_heats        = VALUES(kwart_heats),
                kwart_door         = VALUES(kwart_door),
                kwart_q_heat       = VALUES(kwart_q_heat),
                heeft_halve_finale = VALUES(heeft_halve_finale),
                half_heats         = VALUES(half_heats),
                half_door          = VALUES(half_door),
                half_q_heat        = VALUES(half_q_heat),
                heeft_runner_up    = VALUES(heeft_runner_up),
                finale_heats       = VALUES(finale_heats)
        ");
        foreach ($catConfigs as $cc) {
            $dcId   = trim($cc['dc_id']      ?? '');
            $distId = trim($cc['distance_id'] ?? '');
            if (!$dcId || !$distId) continue;
            $heeftH = !empty($cc['heeft_heats']) ? 1 : 0;
            $heeftK = !empty($cc['heeft_kwartfinale'])  ? 1 : 0;
            $heeftP = !empty($cc['heeft_halve_finale']) ? 1 : 0;
            $heeftR = !empty($cc['heeft_runner_up'])    ? 1 : 0;
            $insCC->execute([
                $tsId, $dcId, $distId,
                $heeftH,
                $heeftH ? max(1, (int)($cc['heats_aantal']  ?? 1)) : null,
                $heeftH ? max(0, (int)($cc['heats_q']      ?? 0)) : null,
                $heeftH ? max(0, (int)($cc['heats_q_heat'] ?? 0)) : 0,
                $heeftK,
                $heeftK ? max(1, (int)($cc['kwart_heats'] ?? 1)) : null,
                $heeftK ? max(1, (int)($cc['kwart_door']     ?? 4)) : 4,
                $heeftK ? max(0, (int)($cc['kwart_q_heat']   ?? 1)) : 0,
                $heeftP,
                $heeftP ? max(1, (int)($cc['half_heats']  ?? 1)) : null,
                $heeftP ? max(1, (int)($cc['half_door']      ?? 4)) : 4,
                $heeftP ? max(0, (int)($cc['half_q_heat']    ?? 1)) : 0,
                $heeftR,
                max(1, (int)($cc['finale_heats'] ?? 1)),
            ]);
        }

        // Blokken synchroniseren op basis van alle cat-configs voor deze afstand
        $enabledRondes = enabledRondesVoorAfstand($pdo, $tsId, $afstandNaam, $compId);
        syncBlokken($pdo, $tsId, $afstandNaam, $enabledRondes);

        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Programma-volgorde opslaan ────────────────────────────────────────────
    if ($action === 'save_blokken') {
        $tsId     = (int)($body['tijdschema_id'] ?? 0);
        $volgorde = $body['volgorde'] ?? [];
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id ontbreekt']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $upd = $pdo->prepare("UPDATE tijdschema_blokken SET volgorde = ? WHERE id = ? AND tijdschema_id = ?");
        foreach ($volgorde as $item) {
            $upd->execute([(int)$item['volgorde'], (int)$item['id'], $tsId]);
        }
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Pauze toevoegen ───────────────────────────────────────────────────────
    if ($action === 'add_pauze') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id ontbreekt']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $s = $pdo->prepare("SELECT COALESCE(MAX(volgorde),0) FROM tijdschema_blokken WHERE tijdschema_id = ?");
        $s->execute([$tsId]);
        $maxV = (int)$s->fetchColumn();
        $pdo->prepare(
            "INSERT INTO tijdschema_blokken (tijdschema_id, volgorde, blok_type) VALUES (?,?,'pauze')"
        )->execute([$tsId, $maxV + 1]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Ceremonie toevoegen ───────────────────────────────────────────────────
    if ($action === 'add_ceremonie') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id ontbreekt']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $s = $pdo->prepare("SELECT COALESCE(MAX(volgorde),0) FROM tijdschema_blokken WHERE tijdschema_id = ?");
        $s->execute([$tsId]);
        $maxV = (int)$s->fetchColumn();
        $pdo->prepare(
            "INSERT INTO tijdschema_blokken (tijdschema_id, volgorde, blok_type) VALUES (?,?,'ceremonie')"
        )->execute([$tsId, $maxV + 1]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Inrijden toevoegen ────────────────────────────────────────────────────
    if ($action === 'add_inrijden') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id ontbreekt']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $s = $pdo->prepare("SELECT COALESCE(MAX(volgorde),0) FROM tijdschema_blokken WHERE tijdschema_id = ?");
        $s->execute([$tsId]);
        $maxV = (int)$s->fetchColumn();
        $pdo->prepare(
            "INSERT INTO tijdschema_blokken (tijdschema_id, volgorde, blok_type) VALUES (?,?,'inrijden')"
        )->execute([$tsId, $maxV + 1]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Wedstrijd start toevoegen (max 1) ─────────────────────────────────────
    if ($action === 'add_wedstrijdstart') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id ontbreekt']);
            exit;
        }
        // Max 1 wedstrijdstart
        $check = $pdo->prepare("SELECT COUNT(*) FROM tijdschema_blokken WHERE tijdschema_id = ? AND blok_type = 'wedstrijdstart'");
        $check->execute([$tsId]);
        if ((int)$check->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Er is al een wedstrijdstart-blok']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $s = $pdo->prepare("SELECT COALESCE(MAX(volgorde),0) FROM tijdschema_blokken WHERE tijdschema_id = ?");
        $s->execute([$tsId]);
        $maxV = (int)$s->fetchColumn();
        $pdo->prepare(
            "INSERT INTO tijdschema_blokken (tijdschema_id, volgorde, blok_type) VALUES (?,?,'wedstrijdstart')"
        )->execute([$tsId, $maxV + 1]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Herstart toevoegen ────────────────────────────────────────────────────
    if ($action === 'add_herstart') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id ontbreekt']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $s = $pdo->prepare("SELECT COALESCE(MAX(volgorde),0) FROM tijdschema_blokken WHERE tijdschema_id = ?");
        $s->execute([$tsId]);
        $maxV = (int)$s->fetchColumn();
        $pdo->prepare(
            "INSERT INTO tijdschema_blokken (tijdschema_id, volgorde, blok_type) VALUES (?,?,'herstart')"
        )->execute([$tsId, $maxV + 1]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Blok opslaan (duur / inrijd-cats / tijdstip / heat-duur) ─────────────
    if ($action === 'save_blok') {
        $tsId   = (int)($body['tijdschema_id'] ?? 0);
        $blokId = (int)($body['blok_id']       ?? 0);
        if (!$tsId || !$blokId) {
            http_response_code(400);
            echo json_encode(['error' => 'Ongeldige invoer']);
            exit;
        }
        $blok = $pdo->prepare("SELECT blok_type FROM tijdschema_blokken WHERE id = ? AND tijdschema_id = ?");
        $blok->execute([$blokId, $tsId]);
        $blokRij = $blok->fetch(PDO::FETCH_ASSOC);
        if (!$blokRij) {
            http_response_code(404);
            echo json_encode(['error' => 'Blok niet gevonden']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        switch ($blokRij['blok_type']) {
            case 'pauze':
            case 'inrijden':
            case 'ceremonie':
                $duur       = isset($body['duur']) && $body['duur'] !== '' && $body['duur'] !== null
                              ? max(1, (int)$body['duur']) : null;
                $inrijdCats = isset($body['inrijd_cats'])
                              ? json_encode(array_values(array_filter((array)$body['inrijd_cats'])))
                              : null;
                $pdo->prepare("UPDATE tijdschema_blokken SET duur = ?, inrijd_cats = ? WHERE id = ? AND tijdschema_id = ?")
                    ->execute([$duur, $inrijdCats, $blokId, $tsId]);
                break;
            case 'wedstrijdstart':
                $tijdstip = isset($body['tijdstip']) && $body['tijdstip'] !== ''
                            ? substr(preg_replace('/[^0-9:]/', '', $body['tijdstip']), 0, 5)
                            : null;
                $pdo->prepare("UPDATE tijdschema_blokken SET tijdstip = ? WHERE id = ? AND tijdschema_id = ?")
                    ->execute([$tijdstip ?: null, $blokId, $tsId]);
                break;
            case 'herstart':
                $tijdstip = isset($body['tijdstip']) && $body['tijdstip'] !== ''
                            ? substr(preg_replace('/[^0-9:]/', '', $body['tijdstip']), 0, 5)
                            : null;
                $opmerking = isset($body['opmerking']) && $body['opmerking'] !== ''
                             ? substr(trim($body['opmerking']), 0, 255)
                             : null;
                $pdo->prepare("UPDATE tijdschema_blokken SET tijdstip = ?, opmerking = ? WHERE id = ? AND tijdschema_id = ?")
                    ->execute([$tijdstip ?: null, $opmerking, $blokId, $tsId]);
                break;
            case 'ronde':
                $heatDuurRaw = trim((string)($body['heat_duur'] ?? ''));
                if ($heatDuurRaw === '') {
                    $heatDuur = null;
                } elseif (str_contains($heatDuurRaw, ':')) {
                    // formaat "m:ss" → seconden
                    [$mm, $ss] = explode(':', $heatDuurRaw, 2);
                    $heatDuur  = max(1, (int)$mm * 60 + (int)$ss);
                } else {
                    // getal zonder dubbele punt → als seconden opslaan
                    $heatDuur = max(1, (int)$heatDuurRaw);
                }
                $pdo->prepare("UPDATE tijdschema_blokken SET heat_duur = ? WHERE id = ? AND tijdschema_id = ?")
                    ->execute([$heatDuur, $blokId, $tsId]);
                break;
        }
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Niet-ronde blokken verwijderen ────────────────────────────────────────
    if ($action === 'delete_blok') {
        $tsId   = (int)($body['tijdschema_id'] ?? 0);
        $blokId = (int)($body['blok_id']       ?? 0);
        if (!$tsId || !$blokId) {
            http_response_code(400);
            echo json_encode(['error' => 'Ongeldige invoer']);
            exit;
        }
        $compId = $getCompId($tsId);
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $pdo->prepare(
            "DELETE FROM tijdschema_blokken WHERE id = ? AND tijdschema_id = ? AND blok_type IN ('pauze','inrijden','wedstrijdstart','ceremonie','herstart')"
        )->execute([$blokId, $tsId]);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Programma wissen (ritten verwijderen, blokken+config behouden) ────────
    if ($action === 'wis_programma') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id is verplicht']);
            exit;
        }
        $compId = $getCompId($tsId);
        $pdo->beginTransaction();
        // Verwijder alles: heats, ritten, blokken, configuratie (volledig schone lei)
        $pdo->prepare("DELETE FROM heats WHERE competition_id = ?")->execute([$compId]);
        $pdo->prepare("DELETE FROM tijdschema_ritten WHERE tijdschema_id = ?")->execute([$tsId]);
        $pdo->prepare("DELETE FROM tijdschema_blokken WHERE tijdschema_id = ?")->execute([$tsId]);
        $pdo->prepare("DELETE FROM tijdschema_cat_config WHERE tijdschema_id = ?")->execute([$tsId]);
        $pdo->prepare("DELETE FROM tijdschema_afstand_config WHERE tijdschema_id = ?")->execute([$tsId]);
        $pdo->prepare("UPDATE competition_tijdschema SET gegenereerd_op = NULL WHERE id = ?")->execute([$tsId]);
        $pdo->commit();

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Ritten genereren ──────────────────────────────────────────────────────
    if ($action === 'genereer') {
        $tsId        = (int)($body['tijdschema_id'] ?? 0);
        $compId      = trim($body['competition_id'] ?? '');
        $categorieen = is_array($body['categorieen'] ?? null) ? $body['categorieen'] : null;
        if (!$tsId || !$compId) {
            http_response_code(400);
            echo json_encode(['error' => 'Ongeldige invoer']);
            exit;
        }
        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        genereerRitten($pdo, $tsId, $compId, $categorieen);
        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Volgorde ritten aanpassen ─────────────────────────────────────────────
    if ($action === 'herorden_ritten') {
        $tsId     = (int)($body['tijdschema_id'] ?? 0);
        $compId   = trim($body['competition_id'] ?? '');
        $volgorde = $body['volgorde'] ?? [];

        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null && $compId) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }
        $upd = $pdo->prepare(
            "UPDATE tijdschema_ritten SET volgorde = ? WHERE id = ? AND tijdschema_id = ?"
        );
        foreach ($volgorde as $item) {
            $upd->execute([(int)$item['volgorde'], (int)$item['id'], $tsId]);
        }
        // Volgorde-aanpassing telt als programma-wijziging → timestamp bijwerken
        $pdo->prepare("UPDATE competition_tijdschema SET gegenereerd_op = NOW() WHERE id = ?")
            ->execute([$tsId]);
        if ($compId) {
            $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
                ->execute([$compId]);
        }
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Override starttijd per heat opslaan ──────────────────────────────────
    if ($action === 'save_rit_override') {
        $tsId   = (int)($body['tijdschema_id'] ?? 0);
        $ritId  = (int)($body['rit_id']        ?? 0);
        $compId = trim($body['competition_id'] ?? '');
        if (!$tsId || !$ritId || !$compId) {
            http_response_code(400);
            echo json_encode(['error' => 'Ongeldige invoer']);
            exit;
        }

        // Optimistic locking
        $clientTsVer = isset($body['tijdschema_version']) ? (int)$body['tijdschema_version'] : null;
        if ($clientTsVer !== null) {
            $vStmt = $pdo->prepare("SELECT tijdschema_version FROM competitions WHERE id = ?");
            $vStmt->execute([$compId]);
            $dbTsVer = (int)($vStmt->fetchColumn() ?? 0);
            if ($dbTsVer !== $clientTsVer) {
                http_response_code(409);
                echo json_encode(['error' => 'conflict', 'message' => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.', 'db_version' => $dbTsVer]);
                exit;
            }
        }

        // Saniteer tijdstip_override: accepteer alleen HH:MM, anders null (= wis override)
        $tijdstipRaw = trim($body['tijdstip_override'] ?? '');
        $tijdstipOverride = ($tijdstipRaw && preg_match('/^\d{2}:\d{2}$/', $tijdstipRaw))
            ? $tijdstipRaw : null;

        // Saniteer opmerking
        $opmerking = isset($body['opmerking']) && trim($body['opmerking']) !== ''
            ? substr(trim($body['opmerking']), 0, 255) : null;

        $pdo->prepare(
            "UPDATE tijdschema_ritten SET tijdstip_override = ?, opmerking = ?
             WHERE id = ? AND tijdschema_id = ?"
        )->execute([$tijdstipOverride, $opmerking, $ritId, $tsId]);

        $pdo->prepare(
            "UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?"
        )->execute([$compId]);

        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie: ' . htmlspecialchars($action)]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
