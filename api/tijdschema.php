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
//  POST action=add_wedstrijdstart  → wedstrijdstart-blok toevoegen (meerdere
//                                    toegestaan voor multi-day events; elke
//                                    extra wedstrijdstart = nieuwe dag,
//                                    auto-gelabeld 'Dag N' in de UI)
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

    // Check of er al startlijsten (heats) gegenereerd zijn voor deze
    // wedstrijd. Telt ALLE heats (niet alleen ronde=1), zodat een
    // afstand die direct met een A-finale begint ook als geloot telt —
    // anders zou genereer programma die finale-heats stilzwijgend
    // weggooien (CASCADE op tijdschema_rit).
    $hStmt = $pdo->prepare("SELECT COUNT(*) FROM heats WHERE competition_id = ?");
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
                              AND d.distance_combination_id = cc.dc_id
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

    // Min-check: merge laatste heat in vorige als die kleiner zou zijn dan ruMin.
    // Alleen actief als ruMin > 0; bij ruMin = 0 is een kleine laatste heat OK.
    if ($ruMin > 0) {
        while ($nHeats > 1) {
            $last = $uitv - $ruMax * ($nHeats - 1);
            if ($last < $ruMin) {
                $nHeats--;
            } else {
                break;
            }
        }
    }

    if ($nHeats === 1) return [$uitv];

    // Eerste (nHeats-1) heats krijgen elk PRECIES ruMax (= beste plekken,
    // direct ná de gekwalificeerden — die mogen niet "uitgesmeerd" worden).
    // Laatste heat krijgt de rest.
    $result = array_fill(0, $nHeats - 1, $ruMax);
    $result[] = $uitv - $ruMax * ($nHeats - 1);
    return $result;
}

// ── Genereer-algoritme ────────────────────────────────────────────────────────

function genereerRitten(PDO $pdo, int $tsId, string $compId, ?array $catVanJS = null): void {
    $pdo->prepare("DELETE FROM tijdschema_ritten WHERE tijdschema_id = ?")->execute([$tsId]);

    // Verweesde afstand-config rijen opruimen: instellingen onder een
    // afstand_naam die niet (meer) als distances.name in deze wedstrijd
    // bestaat. Voorkomt dat een hernoemde afstand later — bij een terug-
    // hernoemen — z'n oude (verborgen) instellingen weer activeert.
    // Alleen instellingen, geen ritten/resultaten — risicovrij.
    $pdo->prepare("
        DELETE tac FROM tijdschema_afstand_config tac
        WHERE tac.tijdschema_id = ?
          AND NOT EXISTS (
              SELECT 1 FROM distances d
              JOIN distance_combinations dc ON dc.id = d.distance_combination_id
              WHERE dc.competition_id = ?
                AND d.name = tac.afstand_naam
          )
    ")->execute([$tsId, $compId]);

    // Wedstrijd-breed systeem
    $s = $pdo->prepare("SELECT systeem FROM competition_tijdschema WHERE id = ?");
    $s->execute([$tsId]);
    $systeem = (string)$s->fetchColumn();

    // Afstand-configs (gedeelde Q/q + finale HG)
    // Meerdere rijen per afstand mogelijk: de globale (dc_id IS NULL) bevat
    // alle afstand-scope instellingen (heeft_kleine_finale, finale_heat_grootte,
    // finale_b_grootte etc.); per-dc rijen (dc_id gevuld) worden door
    // save_ranking aangemaakt en bevatten alleen zinvolle waardes in de
    // ranking-velden — de andere velden staan daar op DB-defaults (0/NULL).
    // Zonder voorkeur voor de globale rij wint zonder ORDER BY een toevallige
    // rij en gaat b.v. heeft_kleine_finale=0 uit de per-dc rij de globale
    // heeft_kleine_finale=1 overschrijven → geen B-finale meer.
    $s = $pdo->prepare("SELECT * FROM tijdschema_afstand_config WHERE tijdschema_id = ?");
    $s->execute([$tsId]);
    $configPerAfstand = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $cfg) {
        $key = $cfg['afstand_naam'];
        $isGlobal = $cfg['dc_id'] === null;
        // Globale rij wint altijd; anders eerste-inzet-blijft-staan (fallback
        // als er alleen per-dc rijen bestaan — theoretisch niet mogelijk maar
        // veilig als het toch voorkomt).
        if ($isGlobal || !isset($configPerAfstand[$key])) {
            $configPerAfstand[$key] = $cfg;
        }
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
            // n=0 toestaan: placeholder-categorie zonder vooraf-bevestigde
            // deelnemers (planner stelt heats/finales alvast in op verwacht
            // aantal). verdeel(0, k) geeft veilig [0,…,0] terug.
            if ($n < 0) continue;
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
            // Ook n=0 toestaan: placeholder-categorie zonder bevestigde
            // deelnemers (zie hierboven). Negatieve waardes zijn onmogelijk.
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
                    // n=0 toegelaten: proforma-programma (geen deelnemers nog).
                    // Heat wordt aangemaakt met verwacht=0, operator vult later.
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
                    // n=0 toegelaten: proforma-programma (zie kwartfinale-comment).
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
                    // Runner-up = rijders die afvallen na de eerste ronde van de
                    // afstand — ongeacht of die eerste ronde Series, Kwartfinale
                    // of Halve finale is. Detecteer welke ronde voorafgaat en
                    // gebruik bijbehorende doorstroom-waarde voor de uitvallers-
                    // berekening.
                    if (!empty($cc['heeft_heats'])) {
                        $eersteRondeQ = (int)($cc['heats_q']    ?? 0);
                    } elseif (!empty($cc['heeft_kwartfinale'])) {
                        $eersteRondeQ = (int)($cc['kwart_door'] ?? 0);
                    } elseif (!empty($cc['heeft_halve_finale'])) {
                        $eersteRondeQ = (int)($cc['half_door']  ?? 0);
                    } else {
                        // Geen voorafgaande ronde → geen afvallers → geen runner-up
                        continue;
                    }
                    $uitvallers = max(0, $cat['n'] - $eersteRondeQ);
                    if ($uitvallers === 0) continue;
                    $aantallen = verdeelRunnerUpHeats($uitvallers, $ruMax, $ruMin);
                    $nHeats    = count($aantallen);

                    // Bereken plaatsnummers per heat (1-geïndexeerd)
                    // Heat 1 → hoogste plaatsen (net na de gekwalificeerden)
                    // Heat nHeats → laagste plaatsen (grootste heat)
                    $plekStart = [];
                    $cumul     = $eersteRondeQ;
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
                    // rijders=0 toegelaten voor proforma-programma. Finale-code
                    // hieronder is veilig met rijders=0 (min/max-guards + lege
                    // B-heats-loop bij full-final, 1 placeholder-rit bij Inter).

                    if ($systeem === 'full-final') {
                        // Per-cat instellingen (wint) met fallback naar afstand-defaults.
                        $catARaw  = $cc['finale_a_grootte']   ?? null;
                        $catBHRaw = $cc['finale_b_heats']     ?? null;
                        $catLbg   = $cc['laatste_b_grootste'] ?? null;

                        $catA      = ($catARaw  !== null && $catARaw  !== '') ? max(1, (int)$catARaw)  : $finaleHg;
                        $nBHeatsCfg= ($catBHRaw !== null && $catBHRaw !== '') ? max(0, (int)$catBHRaw) : null;
                        $lbgLocal  = ($catLbg   !== null) ? !empty($catLbg) : $bLaatstGrootst;

                        // A-finale: top $catA rijders (gecapt op beschikbaar aantal)
                        $aRijders  = min($rijders, $catA);
                        $bRijders  = max(0, $rijders - $aRijders);
                        $catRitten = [];

                        // B-finales: resterende rijders verdeeld over N B-heats.
                        // Per-cat nBHeatsCfg wint; als null → afstand-default (ceil(rest/bFinaleHg)).
                        // 0 B-heats = geen B-finale (rest valt af).
                        if ($bRijders > 0) {
                            if ($nBHeatsCfg !== null) {
                                $nBHeats = min($nBHeatsCfg, $bRijders);
                            } else {
                                $nBHeats = max(1, (int)ceil($bRijders / $bFinaleHg));
                            }
                            $bAantallen = $nBHeats > 0
                                ? ($lbgLocal
                                    ? verdeelLaatstGrootst($bRijders, $nBHeats)
                                    : verdeel($bRijders, $nBHeats))
                                : [];
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
                        // ── Internationaal-nieuw ─────────────────────────────
                        // A-finale krijgt exact het aantal doorstromers uit de
                        // voorgaande ronde ($rijders — bepaald door heats_q /
                        // kwart_door / half_door). Bij heeft_kleine_finale:
                        // de REST van de voorgaande ronde (die niet doorstroomde
                        // naar A) rijdt de kleine finale (finale_b, 1 heat).
                        $heeftKleineFinale = !empty($afCfg['heeft_kleine_finale']);
                        $aRijders = $rijders;

                        // Totaal in voorgaande ronde bepalen om kleine-finale-
                        // grootte af te leiden: input van de laatste actieve
                        // ronde vóór de finale.
                        $totaalInVorige = $rijders;
                        if (!empty($cc['heeft_halve_finale'])) {
                            if (!empty($cc['heeft_kwartfinale'])) {
                                $totaalInVorige = max(0, (int)($cc['kwart_door'] ?? 0));
                            } elseif (!empty($cc['heeft_heats'])) {
                                $totaalInVorige = max(0, (int)($cc['heats_q'] ?? 0));
                            } else {
                                $totaalInVorige = $cat['n'];
                            }
                        } elseif (!empty($cc['heeft_kwartfinale'])) {
                            if (!empty($cc['heeft_heats'])) {
                                $totaalInVorige = max(0, (int)($cc['heats_q'] ?? 0));
                            } else {
                                $totaalInVorige = $cat['n'];
                            }
                        } elseif (!empty($cc['heeft_heats'])) {
                            $totaalInVorige = $cat['n'];
                        }
                        $bRijders = $heeftKleineFinale
                                    ? max(0, $totaalInVorige - $aRijders)
                                    : 0;

                        $catRitten = [];

                        // Kleine finale eerst (rijdt vóór A in het programma,
                        // net als bij full-final Bn eerst dan A).
                        if ($bRijders > 0) {
                            $catRitten[] = [
                                'blok_id'      => $blokId,
                                'volgorde'     => 0,
                                'dc_id'        => $cat['dc_id'],
                                'distance_id'  => $cat['distance_id'],
                                'afstand_naam' => $afstandNaam,
                                'ronde_type'   => 'finale_b',
                                'finale_label' => 'B',
                                'heat_nr'      => 1,
                                'rit_naam'     => "Kleine finale {$afstandNaam} – {$cat['dc_naam']}",
                                'dc_naam'      => $cat['dc_naam'],
                                'verwacht'     => $bRijders,
                            ];
                        }

                        // ── A-finale (1 of meer heats) ───────────────────────
                        $nFinaleHeats = max(1, (int)($cc['finale_heats'] ?? 1));
                        $verwachtPerHeat = (int)ceil($aRijders / max(1, $nFinaleHeats));
                        for ($fh = 1; $fh <= $nFinaleHeats; $fh++) {
                            $fhVerwacht = min($verwachtPerHeat, $aRijders - $verwachtPerHeat * ($fh - 1));
                            $fhNaam = $nFinaleHeats > 1
                                ? "A-finale heat {$fh} {$afstandNaam} – {$cat['dc_naam']}"
                                : "A-finale {$afstandNaam} – {$cat['dc_naam']}";
                            $catRitten[] = [
                                'blok_id'      => $blokId,
                                'volgorde'     => 0,
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

                        foreach ($catRitten as $r) {
                            $r['volgorde'] = $volgorde++;
                            $ritten[] = $r;
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
        $dcId        = trim($body['dc_id'] ?? '') ?: null;
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
        $setValues = [];
        foreach (['heats_ranking', 'kwart_ranking', 'half_ranking', 'finale_ranking'] as $col) {
            if (isset($body[$col]) && in_array($body[$col], $geldigeRanking, true)) {
                $setValues[$col] = $body[$col];
            }
        }

        if ($setValues) {
            // Per (tijdschema_id, dc_id, afstand_naam) een eigen rij — upsert.
            // Bestaande globale rij (dc_id IS NULL) blijft als fallback staan.
            // MySQL's unique key UNIQUE(ts, dc_id, afstand_naam) triggered
            // ON DUPLICATE KEY UPDATE zodra dezelfde combinatie terugkomt.
            $cols = ['tijdschema_id', 'dc_id', 'afstand_naam'];
            $vals = [$tsId, $dcId, $afstandNaam];
            foreach ($setValues as $col => $v) {
                $cols[] = $col;
                $vals[] = $v;
            }
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $updateClause = implode(', ',
                array_map(fn($c) => "$c = VALUES($c)", array_keys($setValues)));
            $sql = "INSERT INTO tijdschema_afstand_config (" . implode(', ', $cols) . ")
                    VALUES ($placeholders)
                    ON DUPLICATE KEY UPDATE $updateClause";
            $pdo->prepare($sql)->execute($vals);
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
        $finaleSeeding   = in_array($body['finale_seeding'] ?? '', ['slang', 'tijdkoppeling', 'reverse_slang'], true)
                         ? $body['finale_seeding'] : 'slang';
        // race_type wordt niet meer uit het tijdschema-formulier gelezen:
        // canonieke bron is distances.race_type (Beheer → afstand-dropdown én
        // live-view voor lange-afstand heats). We zetten hier voor backward-
        // compatibility een vaste waarde (de kolom blijft bestaan voor oude
        // queries, maar de readers afleiden voortaan uit distances.race_type).
        $raceType        = 'sprint';
        $geldigeRanking  = ['time', 'position_time'];

        // Race-type-aware ranking-defaults (gebruikt wanneer body geen
        // expliciete keuze heeft — typisch bij eerste save van de afstand-form).
        // Sprint: eerste actieve ronde = time, tussenrondes = position_time,
        //         A-finale = time.
        // Lange afstand: voorronden = position_time, A-finale = time (UI verbergt
        //         die sowieso — race_type bepaalt finale-sortering automatisch).
        // race_type + value_meters uit distances — canonieke bron.
        $rtStmt = $pdo->prepare(
            "SELECT race_type, value_meters FROM distances d
             JOIN distance_combinations dc ON dc.id = d.distance_combination_id
             JOIN competition_tijdschema ct ON ct.competition_id = dc.competition_id
             WHERE ct.id = ? AND d.name = ? LIMIT 1"
        );
        $rtStmt->execute([$tsId, $afstandNaam]);
        $distRow = $rtStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $distRt      = $distRow['race_type']    ?? 'sprint';
        $distMeters  = $distRow['value_meters'] !== null ? (int)$distRow['value_meters'] : null;
        $isSprint    = ($distRt === 'sprint');
        // 100m sprint of ≥600m sprint: alle rondes op tijd (Art. 113.3/114.4).
        // Overige sprints (200m DTT, 500m+D): tussenrondes op positie+tijd.
        $sprintAllesTime = $isSprint && $distMeters !== null
                         && ($distMeters === 100 || $distMeters >= 600);

        // Eerste actieve ronde uit body cat_configs bepalen — zelfde keten als
        // bij runner-up: heats > kwart > half. Default 'heats' als fallback.
        $eersteRonde = 'heats';
        foreach ($body['cat_configs'] ?? [] as $cc) {
            if (!empty($cc['heeft_heats']))            { $eersteRonde = 'heats';        break; }
            if (!empty($cc['heeft_kwartfinale']))      { $eersteRonde = 'kwartfinale';  break; }
            if (!empty($cc['heeft_halve_finale']))     { $eersteRonde = 'halve_finale'; break; }
        }

        $defH = $sprintAllesTime ? 'time'
              : ($isSprint ? ($eersteRonde === 'heats'        ? 'time' : 'position_time') : 'position_time');
        $defK = $sprintAllesTime ? 'time'
              : ($isSprint ? ($eersteRonde === 'kwartfinale'  ? 'time' : 'position_time') : 'position_time');
        $defL = $sprintAllesTime ? 'time'
              : ($isSprint ? ($eersteRonde === 'halve_finale' ? 'time' : 'position_time') : 'position_time');
        $defF = 'time';

        $heatsRanking    = in_array($body['heats_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['heats_ranking'] : $defH;
        $kwartRanking    = in_array($body['kwart_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['kwart_ranking'] : $defK;
        $halfRanking     = in_array($body['half_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['half_ranking'] : $defL;
        $finaleRanking   = in_array($body['finale_ranking'] ?? '', $geldigeRanking, true)
                         ? $body['finale_ranking'] : $defF;

        $heeftRU  = !empty($body['heeft_runner_up']) ? 1 : 0;
        $heeftKF  = !empty($body['heeft_kleine_finale']) ? 1 : 0;
        $ruMax    = max(2, min(30, (int)($body['runner_up_max'] ?? 6)));
        $ruMin    = max(0, min(30, (int)($body['runner_up_min'] ?? 0)));

        // BELANGRIJK: in MySQL behandelt UNIQUE KEY NULL als "distinct" van NULL,
        // dus ON DUPLICATE KEY UPDATE triggert NIET op de globale rij (dc_id IS NULL).
        // Zonder deze DELETE-stap zou elke save_afstand een NIEUWE rij maken
        // i.p.v. de bestaande te updaten — vindAfstandConfig() zou dan een
        // willekeurige (vaak de oudste) rij teruggeven en de zojuist
        // ingevoerde runner_up_max/min "verdwijnen" terug naar oude waarden.
        $pdo->prepare("
            DELETE FROM tijdschema_afstand_config
            WHERE tijdschema_id = ? AND dc_id IS NULL AND afstand_naam = ?
        ")->execute([$tsId, $afstandNaam]);

        $pdo->prepare("
            INSERT INTO tijdschema_afstand_config
                (tijdschema_id, afstand_naam, q_direct, q_tijd, finale_heat_grootte,
                 finale_b_grootte, laatste_b_grootste, finale_seeding,
                 race_type, heats_ranking, kwart_ranking, half_ranking, finale_ranking,
                 heeft_runner_up, heeft_kleine_finale, runner_up_max, runner_up_min)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$tsId, $afstandNaam, $qD, $qT, $finaleHg, $finaleBg, $bLaatstGrootst,
                     $finaleSeeding, $raceType, $heatsRanking, $kwartRanking, $halfRanking, $finaleRanking,
                     $heeftRU, $heeftKF, $ruMax, $ruMin]);

        // Per-categorie config opslaan
        $catConfigs = $body['cat_configs'] ?? [];
        $insCC = $pdo->prepare("
            INSERT INTO tijdschema_cat_config
                (tijdschema_id, dc_id, distance_id,
                 heeft_heats, heats_aantal, heats_q, heats_q_heat,
                 heeft_kwartfinale, kwart_heats, kwart_door, kwart_q_heat,
                 heeft_halve_finale, half_heats, half_door, half_q_heat,
                 heeft_runner_up, finale_heats,
                 finale_a_grootte, finale_b_heats, laatste_b_grootste,
                 series_alleen_startvolgorde)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
                finale_heats       = VALUES(finale_heats),
                finale_a_grootte   = VALUES(finale_a_grootte),
                finale_b_heats     = VALUES(finale_b_heats),
                laatste_b_grootste = VALUES(laatste_b_grootste),
                series_alleen_startvolgorde = VALUES(series_alleen_startvolgorde)
        ");
        foreach ($catConfigs as $cc) {
            $dcId   = trim($cc['dc_id']      ?? '');
            $distId = trim($cc['distance_id'] ?? '');
            if (!$dcId || !$distId) continue;
            $heeftH = !empty($cc['heeft_heats']) ? 1 : 0;
            $heeftK = !empty($cc['heeft_kwartfinale'])  ? 1 : 0;
            $heeftP = !empty($cc['heeft_halve_finale']) ? 1 : 0;
            $heeftR = !empty($cc['heeft_runner_up'])    ? 1 : 0;

            // Per-cat FF-velden: null = niet aangeleverd (gebruik afstand-defaults)
            $fAGrootteRaw = $cc['finale_a_grootte']   ?? null;
            $fBHeatsRaw   = $cc['finale_b_heats']     ?? null;
            $lbgRaw       = $cc['laatste_b_grootste'] ?? null;
            $fAGrootte = ($fAGrootteRaw === null || $fAGrootteRaw === '')
                ? null : max(1, (int)$fAGrootteRaw);
            $fBHeats   = ($fBHeatsRaw === null || $fBHeatsRaw === '')
                ? null : max(0, (int)$fBHeatsRaw);
            $lbg       = ($lbgRaw === null) ? null : (!empty($lbgRaw) ? 1 : 0);

            // Serie-alleen-startvolgorde: alleen 1 als echt aangevinkt én zinvol
            // (één serie-heat). Bij meer dan 1 heat of zonder series-ronde forceren
            // we 0 zodat de DB geen inconsistente toestand kan krijgen.
            $heatsAantal = $heeftH ? max(1, (int)($cc['heats_aantal'] ?? 1)) : 0;
            $sasRaw      = !empty($cc['series_alleen_startvolgorde']) ? 1 : 0;
            $sas         = ($heeftH && $heatsAantal === 1) ? $sasRaw : 0;

            $insCC->execute([
                $tsId, $dcId, $distId,
                $heeftH,
                $heeftH ? $heatsAantal : null,
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
                $fAGrootte,
                $fBHeats,
                $lbg,
                $sas,
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

    // ── Wedstrijd start toevoegen (meerdere toegestaan = multi-day) ──────────
    if ($action === 'add_wedstrijdstart') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id ontbreekt']);
            exit;
        }
        // Multi-day: max-1 beperking opgeheven. Elke extra wedstrijdstart =
        // nieuwe dag (auto-gelabeld 'Dag N' in de UI op basis van volgorde).
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
                // Opmerking-veld werd voorheen alleen voor herstart bewaard;
                // nu ook voor pauze/inrijden/ceremonie zodat de planner een
                // korte toelichting kan toevoegen die in het programma verschijnt.
                $opmerking = isset($body['opmerking']) && trim((string)$body['opmerking']) !== ''
                             ? substr(trim((string)$body['opmerking']), 0, 255)
                             : null;
                $pdo->prepare("UPDATE tijdschema_blokken SET duur = ?, inrijd_cats = ?, opmerking = ? WHERE id = ? AND tijdschema_id = ?")
                    ->execute([$duur, $inrijdCats, $opmerking, $blokId, $tsId]);
                break;
            case 'wedstrijdstart':
                $tijdstip = isset($body['tijdstip']) && $body['tijdstip'] !== ''
                            ? substr(preg_replace('/[^0-9:]/', '', $body['tijdstip']), 0, 5)
                            : null;
                // Datum voor multi-day: YYYY-MM-DD; leeg/ongeldig → NULL.
                $datumRaw = trim((string)($body['datum'] ?? ''));
                $datum    = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datumRaw))
                            ? $datumRaw : null;
                $pdo->prepare("UPDATE tijdschema_blokken SET tijdstip = ?, datum = ? WHERE id = ? AND tijdschema_id = ?")
                    ->execute([$tijdstip ?: null, $datum, $blokId, $tsId]);
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

    // ── Eén rit verwijderen uit het gegenereerd programma ─────────────────────
    // Use-case: wedstrijd loopt al, weer slaat tegen → operator skipt een
    // ronde voor één cat (bv. runner-up HKA). Heat + heat_entries + results
    // gaan via CASCADE mee; tijdschema_rit zelf gooien we ook weg zodat het
    // programma geen lege regel houdt. Als het de LAATSTE rit is van die
    // (dc, distance, ronde_type) wordt tijdschema_cat_config bijgewerkt
    // zodat alleRondesCompleet() niet blijft wachten op een ronde die niet
    // meer geloot wordt.
    if ($action === 'delete_rit') {
        $tsId  = (int)($body['tijdschema_id'] ?? 0);
        $ritId = (int)($body['rit_id']        ?? 0);
        if (!$tsId || !$ritId) {
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
                echo json_encode([
                    'error'      => 'conflict',
                    'message'    => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.',
                    'db_version' => $dbTsVer,
                ]);
                exit;
            }
        }

        // Lookup rit-context (dc, dist, ronde_type) vóór we 'm verwijderen.
        $rStmt = $pdo->prepare("
            SELECT dc_id, distance_id, ronde_type, rit_naam
            FROM tijdschema_ritten
            WHERE id = ? AND tijdschema_id = ?
        ");
        $rStmt->execute([$ritId, $tsId]);
        $rit = $rStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rit) {
            http_response_code(404);
            echo json_encode(['error' => 'Rit niet gevonden']);
            exit;
        }

        $pdo->beginTransaction();
        // Heats verwijderen (CASCADE: heat_entries + results). Eén rit ↔ één
        // heat (heats.tijdschema_rit_id), maar we doen DELETE op heats om
        // ook de zeldzame "rit zonder heat" en "heat zonder rit" netjes af
        // te handelen.
        $pdo->prepare(
            "DELETE FROM heats WHERE tijdschema_rit_id = ? AND competition_id = ?"
        )->execute([$ritId, $compId]);
        $pdo->prepare(
            "DELETE FROM tijdschema_ritten WHERE id = ? AND tijdschema_id = ?"
        )->execute([$ritId, $tsId]);

        // cat_config bijwerken zodat loting-UI + bouwSlFlow een correct
        // beeld hebben. Twee subscenarios:
        //   - Resterende ritten van dit type > 0 → aantal-veld bijwerken
        //   - Resterend = 0 → 'heeft_X' op 0 + aantal-veld op NULL/0
        //
        // BEWUST GEEN runner_up-update: de UI heeft één gedeeld vinkje
        // 'heeft_runner_up' per afstand (niet per cat). Save_afstand_config
        // schrijft die ENE waarde naar ALLE cat_configs van die afstand.
        // Wie hier heeft_runner_up=0 zou zetten verliest na een willekeurige
        // 'opslaan' in afstand-instellingen ook runner-up voor andere cats.
        if ($rit['distance_id']) {
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM tijdschema_ritten
                WHERE tijdschema_id = ? AND dc_id = ?
                  AND (distance_id <=> ?) AND ronde_type = ?
            ");
            $countStmt->execute([$tsId, $rit['dc_id'], $rit['distance_id'], $rit['ronde_type']]);
            $resterend = (int)$countStmt->fetchColumn();
            $rt = $rit['ronde_type'];

            if ($resterend === 0) {
                // Laatste rit van dit type weg → 'heeft_X' = 0
                $sql = null;
                if      ($rt === 'heats')        $sql = "UPDATE tijdschema_cat_config SET heeft_heats = 0,        heats_aantal = NULL, heats_q = NULL    WHERE tijdschema_id=? AND dc_id=? AND distance_id=?";
                elseif  ($rt === 'kwartfinale')  $sql = "UPDATE tijdschema_cat_config SET heeft_kwartfinale = 0,  kwart_heats  = NULL                    WHERE tijdschema_id=? AND dc_id=? AND distance_id=?";
                elseif  ($rt === 'halve_finale') $sql = "UPDATE tijdschema_cat_config SET heeft_halve_finale = 0, half_heats   = NULL                    WHERE tijdschema_id=? AND dc_id=? AND distance_id=?";
                elseif  ($rt === 'finale_a')     $sql = "UPDATE tijdschema_cat_config SET finale_heats = 0                                                WHERE tijdschema_id=? AND dc_id=? AND distance_id=?";
                elseif  ($rt === 'finale_b')     $sql = "UPDATE tijdschema_cat_config SET finale_b_heats = 0                                              WHERE tijdschema_id=? AND dc_id=? AND distance_id=?";
                if ($sql) $pdo->prepare($sql)->execute([$tsId, $rit['dc_id'], $rit['distance_id']]);
            } else {
                // Aantal-veld bijwerken naar werkelijk aantal resterende ritten
                $col = null;
                if      ($rt === 'heats')        $col = 'heats_aantal';
                elseif  ($rt === 'kwartfinale')  $col = 'kwart_heats';
                elseif  ($rt === 'halve_finale') $col = 'half_heats';
                elseif  ($rt === 'finale_a')     $col = 'finale_heats';
                elseif  ($rt === 'finale_b')     $col = 'finale_b_heats';
                if ($col) {
                    $pdo->prepare("UPDATE tijdschema_cat_config SET `$col` = ?
                                   WHERE tijdschema_id = ? AND dc_id = ? AND distance_id = ?")
                        ->execute([$resterend, $tsId, $rit['dc_id'], $rit['distance_id']]);
                }
            }
        }

        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        $pdo->commit();
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Extra heat toevoegen aan een bestaande ronde-groep ────────────────────
    // Use-case: vlak vóór loting blijkt het aantal rijders niet meer in het
    // bestaande heat-aantal te passen (bv. 25 → 24 deelnemers betekent geen
    // series meer maar 3 halve finales i.p.v. 2). Operator wist series via
    // de prullenbak en voegt vervolgens via deze actie een extra HF-heat
    // toe. cat_config wordt mee bijgewerkt zodat doorstroom-regels (Q per
    // heat + q-tijden) blijven kloppen.
    //
    // Werkt voor: kwartfinale, halve_finale, finale_a, finale_b, runner_up.
    // Series-heats (ronde_type='heats') zijn NIET ondersteund — die hangen
    // aan max_per_heat-logica en zijn fragieler. Use 'Wis programma' als je
    // het aantal series-heats wilt aanpassen.
    if ($action === 'add_rit_kopie') {
        $tsId  = (int)($body['tijdschema_id'] ?? 0);
        $ritId = (int)($body['rit_id']        ?? 0);
        if (!$tsId || !$ritId) {
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
                echo json_encode([
                    'error'      => 'conflict',
                    'message'    => 'Het tijdschema is ondertussen gewijzigd door iemand anders. De pagina wordt ververst.',
                    'db_version' => $dbTsVer,
                ]);
                exit;
            }
        }

        // Bron-rit ophalen
        $rStmt = $pdo->prepare("
            SELECT blok_id, dc_id, distance_id, afstand_naam,
                   ronde_type, dc_naam
            FROM tijdschema_ritten
            WHERE id = ? AND tijdschema_id = ?
        ");
        $rStmt->execute([$ritId, $tsId]);
        $bron = $rStmt->fetch(PDO::FETCH_ASSOC);
        if (!$bron) {
            http_response_code(404);
            echo json_encode(['error' => 'Rit niet gevonden']);
            exit;
        }

        // Series-heats zijn niet ondersteund (zie comment hierboven)
        if ($bron['ronde_type'] === 'heats') {
            http_response_code(400);
            echo json_encode([
                'error' => 'Series-heat toevoegen wordt niet ondersteund. '
                         . 'Gebruik "Wis programma" + opnieuw genereren als je het '
                         . 'aantal series-heats wilt aanpassen.',
            ]);
            exit;
        }

        $pdo->beginTransaction();

        // heat_nr = max bestaand + 1 voor (dc, dist, ronde_type)
        $maxStmt = $pdo->prepare("
            SELECT COALESCE(MAX(heat_nr), 0) FROM tijdschema_ritten
            WHERE tijdschema_id = ? AND dc_id = ?
              AND (distance_id <=> ?) AND ronde_type = ?
        ");
        $maxStmt->execute([$tsId, $bron['dc_id'], $bron['distance_id'], $bron['ronde_type']]);
        $nieuwHeatNr = ((int)$maxStmt->fetchColumn()) + 1;

        // Volgorde: direct ná de laatste rit van dezelfde (dc, dist, ronde_type).
        // Schuif alle ritten met hogere volgorde 1 op om plek te maken.
        $volStmt = $pdo->prepare("
            SELECT MAX(volgorde) FROM tijdschema_ritten
            WHERE tijdschema_id = ? AND dc_id = ?
              AND (distance_id <=> ?) AND ronde_type = ?
        ");
        $volStmt->execute([$tsId, $bron['dc_id'], $bron['distance_id'], $bron['ronde_type']]);
        $maxVolgorde = (int)$volStmt->fetchColumn();

        $pdo->prepare("
            UPDATE tijdschema_ritten SET volgorde = volgorde + 1
            WHERE tijdschema_id = ? AND volgorde > ?
        ")->execute([$tsId, $maxVolgorde]);

        $nieuweVolgorde = $maxVolgorde + 1;

        // Rit-naam genereren volgens conventie. Identiek aan genereerRitten.
        $rondeLabels = [
            'kwartfinale'  => 'Kwartfinale',
            'halve_finale' => 'Halve finale',
            'finale_a'     => 'Finale',
            'finale_b'     => 'B-finale',
            'runner_up'    => 'Runner-up',
        ];
        $rondeLabel    = $rondeLabels[$bron['ronde_type']] ?? $bron['ronde_type'];
        $nieuwRitNaam  = $rondeLabel
                       . ' ' . $bron['afstand_naam']
                       . ' Heat ' . $nieuwHeatNr
                       . ' – ' . $bron['dc_naam'];

        // INSERT nieuwe rit (verwacht: NULL — wordt door loting bepaald).
        // Geen combi_group meekopiëren — heeft alleen zin bij finales en
        // zou per ongeluk een combi kunnen activeren.
        $pdo->prepare("
            INSERT INTO tijdschema_ritten
                (tijdschema_id, blok_id, volgorde, dc_id, distance_id, afstand_naam,
                 ronde_type, heat_nr, rit_naam, dc_naam, verwacht, combi_group)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $tsId, $bron['blok_id'], $nieuweVolgorde,
            $bron['dc_id'], $bron['distance_id'], $bron['afstand_naam'],
            $bron['ronde_type'], $nieuwHeatNr, $nieuwRitNaam, $bron['dc_naam'],
            null, null,
        ]);

        // cat_config bijwerken: aantal-veld +1 zodat loting + alleRondesCompleet
        // weten dat er nu meer heats zijn. Doorstroom-regels (Q per heat,
        // q-tijden) blijven ongewijzigd.
        if ($bron['distance_id']) {
            $colMap = [
                'kwartfinale'  => 'kwart_heats',
                'halve_finale' => 'half_heats',
                'finale_a'     => 'finale_heats',
                'finale_b'     => 'finale_b_heats',
            ];
            $col = $colMap[$bron['ronde_type']] ?? null;
            if ($col) {
                $cur = $pdo->prepare("
                    SELECT `$col` FROM tijdschema_cat_config
                    WHERE tijdschema_id = ? AND dc_id = ? AND distance_id = ?
                ");
                $cur->execute([$tsId, $bron['dc_id'], $bron['distance_id']]);
                $oude = (int)($cur->fetchColumn() ?? 0);
                $pdo->prepare("
                    UPDATE tijdschema_cat_config SET `$col` = ?
                    WHERE tijdschema_id = ? AND dc_id = ? AND distance_id = ?
                ")->execute([$oude + 1, $tsId, $bron['dc_id'], $bron['distance_id']]);
            }
        }

        $pdo->prepare("UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?")
            ->execute([$compId]);
        $pdo->commit();
        echo json_encode(fetchSchema($pdo, $compId));
        exit;
    }

    // ── Programma wissen (behoudt afstand-instellingen + cat-koppeling) ──────
    // Wist: heats, ritten, blokken (wedstrijdstart/pauze/ceremonie/herstart).
    // Behoudt: tijdschema_afstand_config (heat-aantallen + duur per ronde)
    // EN tijdschema_cat_config (welke cats meedoen per afstand) — beide zijn
    // nodig: zonder cat-config toont de UI "Nog niet ingesteld" omdat de
    // koppeling tussen afstand en cat verloren is.
    // Operator klikt na wissen op Opslaan in Afstandinstellingen → blokken
    // worden opnieuw gegenereerd vanuit de behouden config.
    if ($action === 'wis_programma') {
        $tsId = (int)($body['tijdschema_id'] ?? 0);
        if (!$tsId) {
            http_response_code(400);
            echo json_encode(['error' => 'tijdschema_id is verplicht']);
            exit;
        }
        $compId = $getCompId($tsId);
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM heats WHERE competition_id = ?")->execute([$compId]);
        $pdo->prepare("DELETE FROM tijdschema_ritten WHERE tijdschema_id = ?")->execute([$tsId]);
        $pdo->prepare("DELETE FROM tijdschema_blokken WHERE tijdschema_id = ?")->execute([$tsId]);
        // tijdschema_cat_config + tijdschema_afstand_config BEWUST behouden.
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
        // Defensieve gate: zodra er heats bestaan zou genereerRitten via de
        // CASCADE op tijdschema_ritten ALLE startlijsten + resultaten meeslopen.
        // Frontend disabled de knop al, maar backend is bron-van-waarheid voor
        // het geval iemand de API direct aanroept of een stale UI gebruikt.
        $hCheck = $pdo->prepare("SELECT COUNT(*) FROM heats WHERE competition_id = ?");
        $hCheck->execute([$compId]);
        if ((int)$hCheck->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode([
                'error'   => 'heeft_loting',
                'message' => 'Programma kan niet opnieuw gegenereerd worden — '
                          . 'er zijn al startlijsten geloot. Gebruik per-rit '
                          . 'verwijderen voor mid-wedstrijd-skips, of "Wis '
                          . 'programma" als je echt opnieuw wilt beginnen.',
            ]);
            exit;
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

    // ── Ritten combineren (visueel in programma/live/print) ───────────────────
    // POST action=set_combi  body: {tijdschema_id, competition_id, rit_ids:[...]}
    // POST action=clear_combi body: {tijdschema_id, competition_id, rit_ids:[...]}
    if ($action === 'set_combi' || $action === 'clear_combi') {
        $tsId   = (int)($body['tijdschema_id'] ?? 0);
        $compId = trim($body['competition_id'] ?? '');
        $ritIdsRaw = $body['rit_ids'] ?? [];
        $ritIds = is_array($ritIdsRaw)
            ? array_values(array_filter(array_map('intval', $ritIdsRaw), fn($v) => $v > 0))
            : [];
        if (!$tsId || !$compId || empty($ritIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ongeldige invoer (tijdschema_id, competition_id, rit_ids verplicht)']);
            exit;
        }

        if ($action === 'clear_combi') {
            // Wis combi_group voor de opgegeven ritten
            $ph = implode(',', array_fill(0, count($ritIds), '?'));
            $pdo->prepare(
                "UPDATE tijdschema_ritten SET combi_group = NULL
                 WHERE tijdschema_id = ? AND id IN ($ph)"
            )->execute(array_merge([$tsId], $ritIds));
            $pdo->prepare(
                "UPDATE competitions SET tijdschema_version = tijdschema_version + 1 WHERE id = ?"
            )->execute([$compId]);
            echo json_encode(fetchSchema($pdo, $compId));
            exit;
        }

        // set_combi: valideren + nieuwe combi_group toewijzen

        // 0) Alleen voor systemen die losse finale_a-ritten genereren: full-final
        //    en internationaal-nieuw. Combineren is puur visueel, dus beide
        //    veilig (loting/uitslag/klassement blijven per categorie).
        $sysStmt = $pdo->prepare(
            "SELECT systeem FROM competition_tijdschema WHERE id = ?"
        );
        $sysStmt->execute([$tsId]);
        $systeem = $sysStmt->fetchColumn();
        if (!in_array($systeem, ['full-final', 'internationaal-nieuw'], true)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Ritten combineren is niet beschikbaar voor dit wedstrijdsysteem',
            ]);
            exit;
        }

        // 1) Max 4 ritten
        if (count($ritIds) < 2 || count($ritIds) > 4) {
            http_response_code(400);
            echo json_encode(['error' => 'Selecteer 2 tot 4 ritten om te combineren']);
            exit;
        }

        // 2) Haal ritten op en valideer ronde_type + volgorde + dc/afstand
        $ph = implode(',', array_fill(0, count($ritIds), '?'));
        $rStmt = $pdo->prepare(
            "SELECT id, volgorde, ronde_type, dc_id, distance_id, afstand_naam
             FROM tijdschema_ritten
             WHERE tijdschema_id = ? AND id IN ($ph)
             ORDER BY volgorde"
        );
        $rStmt->execute(array_merge([$tsId], $ritIds));
        $ritten = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($ritten) !== count($ritIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'Niet alle rit_ids gevonden in dit tijdschema']);
            exit;
        }

        // Alleen finale_a-ritten mogen gecombineerd worden
        foreach ($ritten as $r) {
            if ($r['ronde_type'] !== 'finale_a') {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Alleen A-finale ritten mogen gecombineerd worden ('
                             . $r['rit_naam'] ?? $r['id'] . ' is ' . $r['ronde_type'] . ')',
                ]);
                exit;
            }
        }

        // 2b) Elke geselecteerde rit moet een ándere categorie zijn. In het
        //     internationaal-systeem kan één categorie meerdere A-finale-heats
        //     hebben; die horen NIET samengevoegd te worden (dan zou je heat 1
        //     en heat 2 van dezelfde finale visueel als één rit tonen). Combineren
        //     is bedoeld om verschillende categorieën samen op de baan te zetten.
        $catSleutels = array_map(
            fn($r) => $r['dc_id'] . '|' . ($r['distance_id'] ?? ''),
            $ritten
        );
        if (count(array_unique($catSleutels)) !== count($catSleutels)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Combineer alleen ritten van verschillende categorieën — '
                         . 'meerdere heats van dezelfde categorie kunnen niet samengevoegd worden',
            ]);
            exit;
        }

        // 3) Opvolgende volgorde: check dat de volgorde-nummers aaneengesloten zijn
        //    in het programma (geen ritten ertussen).
        $firstVolgorde = (int)$ritten[0]['volgorde'];
        $lastVolgorde  = (int)$ritten[count($ritten) - 1]['volgorde'];
        $tussenStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM tijdschema_ritten
             WHERE tijdschema_id = ? AND volgorde >= ? AND volgorde <= ?"
        );
        $tussenStmt->execute([$tsId, $firstVolgorde, $lastVolgorde]);
        $aantalTussen = (int)$tussenStmt->fetchColumn();
        if ($aantalTussen !== count($ritten)) {
            http_response_code(400);
            echo json_encode(['error' => 'Alleen opeenvolgende ritten kunnen gecombineerd worden']);
            exit;
        }

        // 4) Elke rit moet tot een cat/afstand behoren die GEEN andere rondes
        //    heeft (geen series, kwart, half, runner-up, finale_b). Dat betekent:
        //    · cat_config: heeft_heats/kwart/half/runner_up allemaal 0
        //    · tijdschema_ritten: geen finale_b voor dezelfde dc+distance
        foreach ($ritten as $r) {
            $ccStmt = $pdo->prepare(
                "SELECT heeft_heats, heeft_kwartfinale, heeft_halve_finale, heeft_runner_up
                 FROM tijdschema_cat_config
                 WHERE tijdschema_id = ? AND dc_id = ?
                   AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
                 LIMIT 1"
            );
            $ccStmt->execute([$tsId, $r['dc_id'], $r['distance_id'] ?? '', $r['distance_id'] ?? '']);
            $cc = $ccStmt->fetch(PDO::FETCH_ASSOC);
            if ($cc && (
                !empty($cc['heeft_heats']) ||
                !empty($cc['heeft_kwartfinale']) ||
                !empty($cc['heeft_halve_finale']) ||
                !empty($cc['heeft_runner_up'])
            )) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Rit ' . $r['id'] . ' hoort bij een categorie met '
                             . 'series/KF/HF/runner-up — alleen finale_a-only ritten kunnen worden gecombineerd',
                ]);
                exit;
            }

            $bStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM tijdschema_ritten
                 WHERE tijdschema_id = ? AND dc_id = ?
                   AND (distance_id = ? OR (distance_id IS NULL AND ? = ''))
                   AND ronde_type = 'finale_b'"
            );
            $bStmt->execute([$tsId, $r['dc_id'], $r['distance_id'] ?? '', $r['distance_id'] ?? '']);
            if ((int)$bStmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Rit ' . $r['id'] . ' heeft een B-finale in het programma — '
                             . 'alleen enkel-A-finale ritten kunnen worden gecombineerd',
                ]);
                exit;
            }
        }

        // 5) Nieuwe combi_group ID: max + 1 voor dit tijdschema
        $maxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(combi_group), 0) FROM tijdschema_ritten WHERE tijdschema_id = ?"
        );
        $maxStmt->execute([$tsId]);
        $nieuweGroep = (int)$maxStmt->fetchColumn() + 1;

        // 6) Schrijven
        $updStmt = $pdo->prepare(
            "UPDATE tijdschema_ritten SET combi_group = ?
             WHERE tijdschema_id = ? AND id IN ($ph)"
        );
        $updStmt->execute(array_merge([$nieuweGroep, $tsId], $ritIds));

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
