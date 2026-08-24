<?php
// ============================================================
//  InlineComp — demo-fixture helper
//
//  Laat een DEMO-wedstrijd zich gedragen als een KNSB-wedstrijd in de
//  Import-module, ZONDER echte feed. De demo staat permanent in de
//  importlijst (uit competitions.php), is importeerbaar (vergelijk.php →
//  import.php), en blijft in de lijst staan ook als de geïmporteerde DB-
//  inhoud in Beheer wordt weggegooid — want de lijst komt uit dit fixture-
//  bestand, niet uit de competitions-tabel. Zo kun je flows/scenario's
//  herhaald doortesten zonder SQL en met de normale import-rechten.
//
//  Data: api/demo/demo-1.json (verzonnen deelnemers, AVG). Datum wordt hier
//  dynamisch gezet (eerste zaterdag ≥ 7 dagen vooruit) zodat de demo altijd
//  in de nabije toekomst staat.
//
//  Toekomst: demo-2/3 = extra JSON-bestand + id toevoegen in DEMO_FIXTURE_FILES.
// ============================================================

const DEMO_FIXTURE_FILES = [
    'demo-comp-1' => __DIR__ . '/demo/demo-1.json',
    'demo-comp-2' => __DIR__ . '/demo/demo-2.json',
];

function is_demo_fixture_id(?string $id): bool {
    return $id !== null && isset(DEMO_FIXTURE_FILES[$id]);
}

// Laadt (en cachet) het fixture-bestand voor een id.
function demo_fixture_load(string $id): ?array {
    static $cache = [];
    if (!is_demo_fixture_id($id)) return null;
    if (!isset($cache[$id])) {
        $raw = @file_get_contents(DEMO_FIXTURE_FILES[$id]);
        $cache[$id] = $raw ? json_decode($raw, true) : null;
    }
    return $cache[$id];
}

// Dynamische datum: eerste zaterdag ≥ 7 dagen vooruit (altijd nabije toekomst).
function demo_fixture_datum(): array {
    $ts = strtotime('today +7 days');
    while ((int)date('N', $ts) !== 6) {   // 6 = zaterdag (ISO-8601)
        $ts = strtotime('+1 day', $ts);
    }
    $d = date('Y-m-d', $ts);
    return [
        'date'       => $d,
        'starts'     => $d . ' 09:00:00',
        'ends'       => $d . ' 17:00:00',
        'starts_iso' => $d . 'T09:00:00Z',
        'dist_starts'=> $d . ' 09:00:00',
    ];
}

// ── (a) Importlijst-items — shape zoals de frontend een KNSB-item leest ─────
function demo_fixture_lijst_items(): array {
    $items = [];
    foreach (array_keys(DEMO_FIXTURE_FILES) as $id) {
        $f = demo_fixture_load($id);
        if (!$f) continue;
        $dt = demo_fixture_datum();
        $items[] = [
            'id'         => $f['id'],
            'name'       => $f['name'],
            'starts'     => $dt['starts_iso'],
            'ends'       => $dt['starts_iso'],
            'discipline' => 'SpeedSkating.Inline',
            'location'   => $f['venue']['city'] ?? null,
            'venue'      => [
                'name'    => $f['venue']['name'] ?? null,
                'address' => ['city' => $f['venue']['city'] ?? null],
            ],
            'settings'   => ['contact' => [
                'organizationName' => $f['org']['naam']  ?? null,
                'email'            => $f['org']['email'] ?? null,
            ]],
            'is_demo'    => true,   // badge-hint voor de UI; NIET is_handmatig → routeert naar vergelijk.php
        ];
    }
    return $items;
}

// ── (b) Vergelijk-preview — exact de shape die js/import.js verwacht ────────
function demo_fixture_vergelijk_response(PDO $pdo, string $id): array {
    $f      = demo_fixture_load($id);
    $compId = $f['id'];

    // Bestaande DB-staat ophalen voor de diff (leeg bij verse/verwijderde demo).
    $imported = (bool)$pdo->query(
        "SELECT 1 FROM competitions WHERE id = " . $pdo->quote($compId) . " LIMIT 1"
    )->fetchColumn();

    $ev = 0; $heeftProgramma = false; $baan = null;
    if ($imported) {
        $r = $pdo->prepare("SELECT entries_version FROM competitions WHERE id = ?");
        $r->execute([$compId]);
        $ev = (int)$r->fetchColumn();
        $p = $pdo->prepare("SELECT 1 FROM competition_tijdschema WHERE competition_id = ? LIMIT 1");
        $p->execute([$compId]);
        $heeftProgramma = (bool)$p->fetchColumn();

        // Baan van deze wedstrijd (zelfde query/shape als vergelijk.php) — zodat
        // een via Beheer toegewezen baan ook na herladen zichtbaar blijft.
        $bStmt = $pdo->prepare("
            SELECT b.id, b.naam, b.stad,
                   COALESCE(b.vereniging_naam, (
                       SELECT b2.vereniging_naam FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.vereniging_naam IS NOT NULL AND b2.vereniging_naam != ''
                       LIMIT 1
                   )) AS vereniging_naam,
                   COALESCE(b.logo_path, (
                       SELECT b2.logo_path FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                       LIMIT 1
                   )) AS logo_path,
                   COALESCE(b.logo_updated_at, (
                       SELECT b2.logo_updated_at FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                       LIMIT 1
                   )) AS logo_updated_at
            FROM banen b
            JOIN competitions c ON c.baan_id = b.id
            WHERE c.id = ?
        ");
        $bStmt->execute([$compId]);
        $baan = $bStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($baan && !empty($baan['id'])) {
            $bs = $pdo->prepare(
                "SELECT id, naam, logo_path, url, volgorde
                 FROM baan_sponsors WHERE baan_id = ? ORDER BY volgorde, naam"
            );
            $bs->execute([$baan['id']]);
            $baan['sponsors'] = $bs->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // persons + entries van deze demo-wedstrijd in maps (voor is_new/diff).
    $persons = [];
    $ps = $pdo->query("SELECT * FROM persons WHERE license_key LIKE 'demo-%'");
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $row) $persons[$row['license_key']] = $row;

    $entries = [];
    $es = $pdo->prepare("
        SELECT e.* FROM entries e
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        WHERE dc.competition_id = ?
    ");
    $es->execute([$compId]);
    foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $entries[$row['distance_combination_id'] . '|' . $row['person_license']] = $row;
    }

    // Afstanden-preview per DC (zelfde 3 voor elke DC).
    $knsbDistances = array_map(fn($d) => [
        'id'           => null,
        'name'         => $d['name'],
        'number'       => $d['number'],
        'value_meters' => null,
    ], $f['distances']);

    // Competitors per DC groeperen.
    $perDc = [];
    foreach ($f['competitors'] as $c) $perDc[$c['dc_id']][] = $c;

    $groepen = [];
    foreach ($f['dcs'] as $dc) {
        $rows = [];
        foreach (($perDc[$dc['id']] ?? []) as $c) {
            $lk       = $c['license_key'];
            $dbPerson = $persons[$lk] ?? null;
            $dbEntry  = $entries[$dc['id'] . '|' . $lk] ?? null;
            $knsbSt   = (int)$c['status'];
            $effectief = $knsbSt;

            $diffs = [];
            if ($dbEntry && $effectief !== (int)$dbEntry['status']) $diffs[] = 'status';

            $rows[] = [
                'license_key'     => $lk,
                'is_anoniem'      => false,
                'knsb_entry_id'   => $c['knsb_entry_id'] ?? null,
                'knsb_status'     => $knsbSt,
                'entry_status'    => $effectief,
                'reserve'         => $c['reserve'],
                'knsb_reserve'    => $c['reserve'],
                'reserve_ingezet' => $dbEntry ? (int)($dbEntry['reserve_handmatig_ingezet'] ?? 0) : 0,
                'is_new'          => $dbPerson === null,
                'diffs'           => $diffs,
                'knsb' => [
                    'start_number' => $c['start_number'],
                    'full_name'    => $c['full_name'],
                    'short_name'   => $c['short_name'],
                    'gender'       => $c['gender'],
                    'category'     => $c['category'],
                    'nationality'  => $c['nationality'] ?? 'NED',
                    'club_code'    => $c['club_code'],
                    'club_short'   => $c['club_short'],
                    'club_full'    => $c['club_full'],
                    'sponsor'      => $c['sponsor'] ?? null,
                    'city'         => $c['city'],
                    'transponder1' => null,
                    'transponder2' => null,
                ],
                'db_person'          => $dbPerson,
                'db_entry'           => $dbEntry,
                'db_tp1'             => null,
                'db_tp2'             => null,
                'db_tp_extra'        => [],
                'db_tp_actief'       => null,
                'db_tp_actief_isset' => false,
            ];
        }
        $groepen[] = [
            'dc_id'           => $dc['id'],
            'dc_name'         => $dc['name'],
            'dc_number'       => $dc['number'],
            'merge_group'     => null,
            'merge_label'     => null,
            'splits'          => null,
            'category_filter' => $dc['category_filter'],
            'max_in_loting'   => null,
            'has_distances'   => true,
            'knsb_distances'  => $knsbDistances,
            'competitors'     => $rows,
        ];
    }

    // Organisatie uit DB (logo + sponsors die via Beheer zijn toegevoegd) —
    // val terug op de fixture-gegevens als de org nog niet bestaat.
    $oStmt = $pdo->prepare("SELECT id, naam, email, logo_path FROM organisaties WHERE id = ?");
    $oStmt->execute([$f['org']['id']]);
    $org = $oStmt->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $sp = $pdo->prepare("SELECT * FROM organisatie_sponsors WHERE organisatie_id = ? ORDER BY volgorde, naam");
        $sp->execute([$org['id']]);
        $org['sponsors'] = $sp->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $org = [
            'id'    => $f['org']['id'],             'naam'      => $f['org']['naam'],
            'email' => $f['org']['email'] ?? null,  'logo_path' => null, 'sponsors' => [],
        ];
    }

    return [
        'groepen'          => $groepen,
        'organisatie'      => $org,
        'baan'             => $baan,
        'imported'         => $imported,
        'knsb_stand'       => null,
        'db_stand'         => null,
        'entries_version'  => $ev,
        'heeft_programma'  => $heeftProgramma,
        'org_transponders' => [],
    ];
}

// ── (c) Import-metadata schrijven — org + wedstrijd + DC's + afstanden ──────
//  De deelnemers (persons + entries) schrijft import.php daarna via de
//  normale deelnemers-loop uit de POST-body (ongewijzigd).
function demo_fixture_write_meta(PDO $pdo, string $id): void {
    $f  = demo_fixture_load($id);
    $dt = demo_fixture_datum();

    // Organisatie (vast id demo-org-1)
    $pdo->prepare("
        INSERT INTO organisaties (id, naam, email) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE email = VALUES(email)
    ")->execute([$f['org']['id'], $f['org']['naam'], $f['org']['email'] ?? null]);

    // Wedstrijd — is_demo=1, bron='demo' (import.php slaat KNSB-sync over via de
    // is_demo-tak; niet 'handmatig' zodat de fixture-metadata wél geschreven wordt).
    $pdo->prepare("
        INSERT INTO competitions
               (id, name, starts, ends, location, venue_name, venue_city, discipline,
                bron, is_demo, organisatie_id, public_zichtbaar, public_aankondigen)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'inline-skating', 'demo', 1, ?, 0, 0)
        ON DUPLICATE KEY UPDATE
               name = VALUES(name), starts = VALUES(starts), ends = VALUES(ends),
               bron = 'demo', is_demo = 1, organisatie_id = VALUES(organisatie_id)
    ")->execute([
        $f['id'], $f['name'], $dt['starts'], $dt['ends'],
        $f['venue']['city'] ?? null, $f['venue']['name'] ?? null, $f['venue']['city'] ?? null,
        $f['org']['id'],
    ]);

    // Categorieën (distance_combinations)
    $insDc = $pdo->prepare("
        INSERT INTO distance_combinations (id, competition_id, number, name, category_filter)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE number = VALUES(number), name = VALUES(name),
               category_filter = VALUES(category_filter)
    ");
    // Afstanden (3 per DC, gedeelde id per afstand). race_type/value NIET
    // overschrijven bij re-import (operator-instellingen behouden), net als KNSB.
    $insDist = $pdo->prepare("
        INSERT INTO distances (id, distance_combination_id, number, name, value_meters, discipline, starts, race_type)
        VALUES (?, ?, ?, ?, NULL, ?, ?, ?)
        ON DUPLICATE KEY UPDATE number = VALUES(number), discipline = VALUES(discipline), starts = VALUES(starts)
    ");
    foreach ($f['dcs'] as $dc) {
        $insDc->execute([$dc['id'], $f['id'], $dc['number'], $dc['name'], $dc['category_filter']]);
        foreach ($f['distances'] as $d) {
            $insDist->execute([
                $d['id'], $dc['id'], $d['number'], $d['name'],
                $d['discipline'], $dt['dist_starts'], $d['race_type'],
            ]);
        }
    }
}
