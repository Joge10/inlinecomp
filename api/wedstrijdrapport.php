<?php
// ============================================================
//  InlineComp – wedstrijdrapport (printbare uitslag per wedstrijd)
//
//  GET /api/wedstrijdrapport.php?id=<competition_id>
//
//  Response:
//    {
//      "competition": {
//        id, name, starts, ends, location, venue_city, discipline,
//        organisatie_naam, organisatie_logo
//      },
//      "dcs": [
//        {
//          id, name, number, category_filter,
//          klassement: [                  // alleen bij multi-distance DCs
//            { rang, license_key, full_name, categorie, club_full,
//              sponsor, punten_totaal }
//          ],
//          distances: [
//            {
//              id, name, value_meters, race_type, starts,
//              uitslag: [
//                { rang, license_key, full_name, categorie, split_group,
//                  club_full, sponsor, tijd_ms, punten, sanctie }
//              ]
//            }
//          ]
//        }
//      ]
//    }
//
//  Gebruikt door js/instellingen.js → printWedstrijdrapport() —
//  vanuit Beheer → Organisaties → tab Wedstrijden (🖨 knop per rij).
//
//  Belangrijk over multi-distance DCs (bv. KNSB-feed wedstrijden waar
//  één DC zowel een 1000m DTT als 5000m afvalkoers bevat):
//    - `distances` bevat per afstand een eigen `uitslag`-array
//      (gesorteerd op rang binnen die afstand), dus rijders verschijnen
//      één keer per afstand — niet duplicaat in één tabel.
//    - `klassement` is het totaal-eindklassement over alle afstanden
//      (uit uitslag_klassement met punten_totaal). Alleen gevuld als
//      de DC meerdere distances heeft, anders identiek aan de uitslag
//      van de enige afstand en dus overbodig.
//    - Voor split-group DCs (HSA+HJA in 1 DC) bevat elke uitslag-rij
//      ook `split_group`, zodat de frontend per split kan groeperen.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

$compId = trim($_GET['id'] ?? '');
if ($compId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'id ontbreekt']);
    exit;
}

try {
    // ── 1) Wedstrijd-header (+ organisatie-naam en logo) ────────
    $compStmt = $pdo->prepare("
        SELECT c.id, c.name, c.starts, c.ends, c.location,
               c.venue_name, c.venue_city, c.discipline,
               c.organisatie_id, c.baan_id,
               c.protokol_nawoord, c.protokol_nawoord_en,
               c.protokol_voorblad_foto,
               c.protokol_nawoord_foto,
               c.protokol_nawoord_foto_caption,
               o.naam      AS organisatie_naam,
               o.logo_path AS organisatie_logo,
               b.naam            AS baan_naam,
               b.vereniging_naam AS baan_vereniging,
               b.logo_path       AS baan_logo
        FROM competitions c
        LEFT JOIN organisaties o ON o.id = c.organisatie_id
        LEFT JOIN banen        b ON b.id = c.baan_id
        WHERE c.id = ?
    ");
    $compStmt->execute([$compId]);
    $comp = $compStmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp) {
        http_response_code(404);
        echo json_encode(['error' => 'Wedstrijd niet gevonden']);
        exit;
    }

    // ── 2) Distance combinations ────────────────────────────────
    // DC's krijgen z'n programma-volgorde mee uit tijdschema_ritten (MIN
    // van alle ritten voor die DC). Frontend gebruikt dit als secundaire
    // sort-sleutel binnen één cat-groep — bij wedstrijden waar elke
    // afstand een eigen DC heeft (zoals het NK) staan de afstanden zo in
    // programma-volgorde ipv alfabetisch.
    $dcStmt = $pdo->prepare("
        SELECT dc.id, dc.name, dc.number, dc.category_filter,
               v.prog_volgorde
        FROM distance_combinations dc
        LEFT JOIN (
            SELECT tr.dc_id, MIN(tr.volgorde) AS prog_volgorde
            FROM tijdschema_ritten tr
            JOIN competition_tijdschema ct ON ct.id = tr.tijdschema_id
            WHERE ct.competition_id = ?
            GROUP BY tr.dc_id
        ) v ON v.dc_id = dc.id
        WHERE dc.competition_id = ?
        ORDER BY v.prog_volgorde IS NULL, v.prog_volgorde, dc.number, dc.name
    ");
    $dcStmt->execute([$compId, $compId]);
    $dcs = $dcStmt->fetchAll(PDO::FETCH_ASSOC);

    // Statements één keer prepareren — worden hieronder per DC hergebruikt
    // (PDO cached deze server-side; N+1 queries zijn voor een eenmalige
    // print-actie alle geen probleem en houden de PHP-kant leesbaar)
    // Volgorde van afstanden binnen een DC: programma-volgorde uit het
    // tijdschema (tijdschema_ritten.volgorde). Eerste twee parameters zijn
    // competition_id (voor de JOIN naar het juiste tijdschema), derde is
    // distance_combination_id. distances.starts en .number als fallbacks
    // voor afstanden die nog niet in het tijdschema zitten.
    $distStmt = $pdo->prepare("
        SELECT d.id, d.name, d.value_meters, d.race_type, d.starts, d.target_group,
               v.prog_volgorde
        FROM distances d
        LEFT JOIN (
            SELECT tr.dc_id, tr.distance_id, MIN(tr.volgorde) AS prog_volgorde
            FROM tijdschema_ritten tr
            JOIN competition_tijdschema ct ON ct.id = tr.tijdschema_id
            WHERE ct.competition_id = ?
            GROUP BY tr.dc_id, tr.distance_id
        ) v ON v.dc_id = d.distance_combination_id AND v.distance_id = d.id
        WHERE d.distance_combination_id = ?
        ORDER BY d.target_group,
                 v.prog_volgorde IS NULL, v.prog_volgorde,
                 d.starts IS NULL, d.starts,
                 d.number, d.name
    ");
    $uitslagStmt = $pdo->prepare("
        SELECT ua.rang, ua.categorie, ua.split_group,
               ua.person_license   AS license_key,
               p.full_name,
               p.club_full,
               p.sponsor,
               ua.tijd_ms, ua.punten, ua.sanctie
        FROM uitslag_afstand ua
        LEFT JOIN persons p ON p.license_key = ua.person_license
        WHERE ua.distance_combination_id = ?
          AND ua.distance_id             = ?
        ORDER BY ua.split_group,
                 -- NULL-rang (DQ/DNF/DNS) onderaan ipv MySQL-default bovenaan
                 ua.rang IS NULL,
                 ua.rang,
                 p.full_name
    ");
    $klassementStmt = $pdo->prepare("
        SELECT uk.rang, uk.categorie, uk.split_group,
               uk.person_license   AS license_key,
               p.full_name,
               p.club_full,
               p.sponsor,
               uk.punten_totaal
        FROM uitslag_klassement uk
        LEFT JOIN persons p ON p.license_key = uk.person_license
        WHERE uk.distance_combination_id = ?
        ORDER BY uk.split_group,
                 -- NULL-rang (DQ/DNF/DNS) onderaan ipv MySQL-default bovenaan
                 uk.rang IS NULL,
                 uk.rang,
                 p.full_name
    ");

    foreach ($dcs as &$dc) {
        // 2a) Distances voor deze DC ophalen
        $distStmt->execute([$compId, $dc['id']]);
        $distances = $distStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2b) Per distance: uitslag_afstand-rijen ophalen
        foreach ($distances as &$dist) {
            $uitslagStmt->execute([$dc['id'], $dist['id']]);
            $rows = $uitslagStmt->fetchAll(PDO::FETCH_ASSOC);
            $dist['uitslag'] = array_map(function($r) {
                return [
                    // BELANGRIJK: NULL behouden, niet casten naar (int) want
                    // dat geeft 0 — wat de UI vervolgens als positie '0'
                    // bovenaan rendert ipv onderaan met '—'.
                    'rang'        => $r['rang'] !== null ? (int)$r['rang'] : null,
                    'license_key' => $r['license_key'],
                    'full_name'   => $r['full_name'] ?? '(onbekend)',
                    'categorie'   => $r['categorie'],
                    'split_group' => $r['split_group'] ?? '',
                    'club_full'   => $r['club_full'],
                    'sponsor'     => $r['sponsor'],
                    'tijd_ms'     => $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                    'punten'      => $r['punten']  !== null ? (float)$r['punten']  : null,
                    'sanctie'     => $r['sanctie'],
                ];
            }, $rows);
            $dist['value_meters'] = $dist['value_meters'] !== null
                ? (int)$dist['value_meters'] : null;
            // target_group = split-label (bv. 'DP2', 'HP2') voor gesplitste
            // DCs. Bij niet-gesplitste DCs is dit NULL of leeg. Frontend
            // gebruikt 'm om de blok-titel te disambigueren — anders krijg
            // je 2x dezelfde sectie-naam onder elkaar voor split-DCs.
            $dist['target_group'] = $dist['target_group'] ?: null;
        }
        $dc['distances'] = $distances;

        // 2c) Eindklassement — alleen zinvol als DC meerdere distances
        // heeft. Bij single-distance DCs is uitslag_klassement.rang
        // identiek aan uitslag_afstand.rang en dus visueel dubbel.
        if (count($distances) > 1) {
            $klassementStmt->execute([$dc['id']]);
            $krows = $klassementStmt->fetchAll(PDO::FETCH_ASSOC);
            $dc['klassement'] = array_map(function($r) {
                return [
                    // NULL behouden — zie comment bij distance.uitslag.rang.
                    'rang'          => $r['rang'] !== null ? (int)$r['rang'] : null,
                    'license_key'   => $r['license_key'],
                    'full_name'     => $r['full_name'] ?? '(onbekend)',
                    'categorie'     => $r['categorie'],
                    'split_group'   => $r['split_group'] ?? '',
                    'club_full'     => $r['club_full'],
                    'sponsor'       => $r['sponsor'],
                    'punten_totaal' => $r['punten_totaal'] !== null
                        ? (float)$r['punten_totaal'] : null,
                ];
            }, $krows);
        } else {
            $dc['klassement'] = [];
        }

        $dc['number'] = (int)$dc['number'];
    }
    unset($dc);

    // ── Protokol-extras: jury_leden + sponsoren ─────────────────
    // Officials voor de Officials-pagina (lege array = pagina wordt
    // overgeslagen in de print-output).
    $juryStmt = $pdo->prepare("
        SELECT categorie, functie, naam
        FROM jury_leden
        WHERE competition_id = ?
        ORDER BY FIELD(categorie, 'OC', 'jury', 'vrijwilliger'), volgorde, naam
    ");
    $juryStmt->execute([$compId]);
    $jury = $juryStmt->fetchAll(PDO::FETCH_ASSOC);

    // Sponsoren: zowel organisatie- als baan-sponsoren mengen (zelfde
    // bron als de publieke footer-marquee). Voor het protokol tonen we
    // ze als grid op een eigen pagina. Lege lijst = pagina wordt
    // overgeslagen. `bron` kolom is voor debugging / eventueel filteren
    // in de frontend.
    $sponsors = [];
    if ($comp['organisatie_id']) {
        $spStmt = $pdo->prepare("
            SELECT naam, logo_path, url, 'organisatie' AS bron
            FROM organisatie_sponsors
            WHERE organisatie_id = ?
            ORDER BY volgorde, naam
        ");
        $spStmt->execute([$comp['organisatie_id']]);
        $sponsors = array_merge($sponsors, $spStmt->fetchAll(PDO::FETCH_ASSOC));
    }
    if ($comp['baan_id']) {
        $bsStmt = $pdo->prepare("
            SELECT naam, logo_path, url, 'baan' AS bron
            FROM baan_sponsors
            WHERE baan_id = ?
            ORDER BY volgorde, naam
        ");
        $bsStmt->execute([$comp['baan_id']]);
        $sponsors = array_merge($sponsors, $bsStmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Deelnemerslijst-data ─────────────────────────────────────
    // Afstanden gegroepeerd op NAAM (zoals "200m DTT", "500m+D") — niet
    // per DC. Een wedstrijd met DPA/HPA/DPB/... 200m-DTT heeft dus één
    // kolom "200m DTT" gedeeld door alle cats. Afvalkoers eruit gefilterd
    // omdat InlineComp die nog niet ondersteunt. Zelfde logica als de
    // Print Center deelnemerslijst.
    $afstStmt = $pdo->prepare("
        SELECT d.name AS naam,
               MIN(d.value_meters) AS meters,
               MIN(d.race_type) AS race_type
        FROM distances d
        JOIN distance_combinations dc ON dc.id = d.distance_combination_id
        WHERE dc.competition_id = ?
          AND (d.race_type IS NULL OR d.race_type <> 'afvalkoers')
        GROUP BY d.name
        ORDER BY meters, naam
    ");
    $afstStmt->execute([$compId]);
    $afstandenLijst = $afstStmt->fetchAll(PDO::FETCH_ASSOC);

    // Per deelnemer: persoonsdata + lijst van afstand-NAMEN. Twee bronnen
    // via UNION zodat de lijst ook werkt vóór de wedstrijd is gereden:
    //   1) uitslag_afstand → echte deelname (na de wedstrijd)
    //   2) entries → geplande deelname (vóór, of voor DNS-rijders die
    //      geen uitslag-rij hebben). Status NOT IN (3) sluit afgemelde
    //      inschrijvingen uit; status 1/2/5 = bevestigd/aangemeld/org-toegevoegd.
    // Dedup op license_key zodat iedereen 1x in de lijst staat.
    // Sortering: startnummer dan achternaam.
    $delnStmt = $pdo->prepare("
        SELECT p.license_key, p.full_name, p.short_name, p.category,
               p.nationality, p.start_number, p.club_full, p.sponsor,
               GROUP_CONCAT(DISTINCT src.distance_naam ORDER BY src.meters SEPARATOR '|||') AS gereden
        FROM (
            SELECT ua.person_license AS license_key,
                   d.name AS distance_naam,
                   d.value_meters AS meters
            FROM uitslag_afstand ua
            JOIN distances d              ON d.id = ua.distance_id
            JOIN distance_combinations dc ON dc.id = ua.distance_combination_id
            WHERE dc.competition_id = ?
              AND (d.race_type IS NULL OR d.race_type <> 'afvalkoers')
            UNION
            SELECT e.person_license AS license_key,
                   d.name AS distance_naam,
                   d.value_meters AS meters
            FROM entries e
            JOIN distances d              ON d.distance_combination_id = e.distance_combination_id
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE dc.competition_id = ?
              AND (d.race_type IS NULL OR d.race_type <> 'afvalkoers')
              AND (e.status IS NULL OR e.status <> 3)
        ) src
        JOIN persons p ON p.license_key = src.license_key
        GROUP BY p.license_key
        ORDER BY p.start_number IS NULL, p.start_number, p.short_name, p.full_name
    ");
    $delnStmt->execute([$compId, $compId]);
    $deelnemers = [];
    foreach ($delnStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $d['start_number'] = $d['start_number'] !== null ? (int)$d['start_number'] : null;
        $d['gereden']      = $d['gereden'] ? explode('|||', $d['gereden']) : [];
        $deelnemers[] = $d;
    }

    echo json_encode([
        'competition' => [
            'id'                => $comp['id'],
            'name'              => $comp['name'],
            'starts'            => $comp['starts'],
            'ends'              => $comp['ends'],
            'location'          => $comp['location'],
            'venue_name'        => $comp['venue_name'],
            'venue_city'        => $comp['venue_city'],
            'discipline'        => $comp['discipline'],
            'organisatie_naam'  => $comp['organisatie_naam'],
            'organisatie_logo'  => $comp['organisatie_logo'],
            'baan_naam'         => $comp['baan_naam'],
            'baan_vereniging'   => $comp['baan_vereniging'],
            'baan_logo'         => $comp['baan_logo'],
            'protokol_nawoord'              => $comp['protokol_nawoord'],
            'protokol_nawoord_en'           => $comp['protokol_nawoord_en'],
            'protokol_voorblad_foto'        => $comp['protokol_voorblad_foto'],
            'protokol_nawoord_foto'         => $comp['protokol_nawoord_foto'],
            'protokol_nawoord_foto_caption' => $comp['protokol_nawoord_foto_caption'],
        ],
        'jury'            => $jury,
        'sponsors'        => $sponsors,
        'afstanden_lijst' => $afstandenLijst,
        'deelnemers'      => $deelnemers,
        'dcs'             => $dcs,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
