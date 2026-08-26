<?php
// ============================================================
//  InlineComp – importeer wedstrijd + beoordeelde deelnemers
//
//  POST /api/import.php
//  Body: {
//    "competition_id": "<UUID>",
//    "categories": [
//      {
//        "dc_id": "<UUID>",
//        "competitors": [
//          {
//            "license_key":   "10219545",
//            "knsb_entry_id": "<UUID>",
//            "entry_status":  1,        // 0=niet bevestigd  1=bevestigd  2=afgemeld  3=afgem.bij org.  4=niet getekend  5=bevestigd bij org.
//            "reserve":       null,     // null of volgnummer 1, 2 …
//            "start_number":  53,
//            "full_name":     "Eline van Leijenhorst",
//            "short_name":    "van Leijenhorst",
//            "gender":        1,        // 0=man  1=vrouw
//            "category":      "DKA",
//            "nationality":   "NED",
//            "club_code":     6821,
//            "club_short":    "RADBOUD",
//            "club_full":     "Radboud Inline-skating",
//            "sponsor":       "Team mijnten.nl",  // of null als geen persoonlijke sponsor
//            "city":          "Lelystad",
//            "transponder1":  "KS-44038",
//            "transponder2":  null,
//            "tp1_manual":    false,    // true als handmatig gewijzigd
//            "tp2_manual":    false
//          }
//        ]
//      }
//    ]
//  }
//
//  Workflow:
//   1. Wedstrijd-metadata + DC + afstanden ophalen van KNSB (altijd actueel)
//   2. Deelnemers komen uit de beoordeelde POST-body (niet opnieuw van KNSB)
//      → voorbereider heeft al namen, startnummers, transponders gecontroleerd
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── DELETE: verwijder wedstrijd volledig uit de database ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_once __DIR__ . '/../../config_inlinecomp.php';
    require_once __DIR__ . '/../auth/session.php';
    $_authUser = requireAuth($pdo, ['owner', 'admin']);
    $delId = trim($_GET['id'] ?? '');
    // 8-36 chars, alfanumeriek + dashes. Range ipv exact 36 zodat handmatig-
    // geseede IDs (bv. historie-import 'hist-2024-nk-baan-aabbcc' = 24 chars)
    // ook delete toelaten.
    if (!preg_match('/^[a-z0-9\-]{8,36}$/i', $delId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldig competition ID']);
        exit;
    }
    $ookUitslag = !empty($_GET['uitslag']);
    try {
        // uitslag_afstand en uitslag_klassement hebben geen ON DELETE CASCADE:
        // ze worden standaard bewaard voor historische inzage en competitieklassement.
        // Alleen als &uitslag=1 meegegeven wordt (bv. testwedstrijden) worden ze ook verwijderd.
        if ($ookUitslag) {
            $pdo->prepare("DELETE FROM uitslag_afstand    WHERE competition_id = ?")->execute([$delId]);
            $pdo->prepare("DELETE FROM uitslag_klassement WHERE competition_id = ?")->execute([$delId]);
        }
        // De overige tabellen (distance_combinations, competitors, heats, etc.) cascaden automatisch.
        $pdo->prepare("DELETE FROM competitions WHERE id = ?")->execute([$delId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── GET: exporteer wedstrijd-deelnemers als KNSB-CSV ────────────────────────
// Doel: heropbouw van het KNSB-CSV-formaat op basis van de actuele DB-state
// (lokale wijzigingen in startnummer/transponder zitten in persons resp.
// organisatie_transponders nadat de gebruiker op Importeer heeft geklikt).
// De export-knop is in de UI geblokkeerd zolang er onopgeslagen wijzigingen
// zijn — zo is de DB altijd source-of-truth voor wat geëxporteerd wordt.
//
// Alleen aanwezige rijders (status 1=getekend, 2=aangemeld nog niet getekend,
// 5=bevestigd bij organisatie) — niet 3=afgemeld of 4=niet getekend.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'export_knsb_csv') {
    require_once __DIR__ . '/../../config_inlinecomp.php';
    require_once __DIR__ . '/../auth/session.php';
    $_authUser = requireAuth($pdo);

    $expCompId = trim($_GET['competition_id'] ?? '');
    // 8-36 chars, alfanumeriek + dashes — versoepeld voor handmatig-geseede IDs.
    if (!preg_match('/^[a-z0-9\-]{8,36}$/i', $expCompId)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Ongeldig of ontbrekend competition_id';
        exit;
    }

    // Wedstrijdnaam + export-tijdstempel voor bestandsnaam (zo geen
    // discussie over wanneer de export gedraaid is). De client stuurt z'n
    // browser-lokale tijd mee als ?t=YYYY-MM-DD_HHhMM zodat de filename
    // consistent is voor de gebruiker, ongeacht de server-timezone.
    $cnStmt = $pdo->prepare("SELECT name FROM competitions WHERE id = ?");
    $cnStmt->execute([$expCompId]);
    $compNaam = $cnStmt->fetchColumn() ?: 'wedstrijd';
    $safeName = preg_replace('/[^A-Za-z0-9_\- ]/', '_', $compNaam);
    $tParam   = trim($_GET['t'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}h\d{2}$/', $tParam)) {
        $tijdstempel = $tParam; // client-lokale tijd, geverifieerd op formaat
    } else {
        // Fallback: server-tijd in Europe/Amsterdam zodat we niet UTC krijgen
        $prevTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');
        $tijdstempel = date('Y-m-d_H\hi');
        date_default_timezone_set($prevTz);
    }
    $safeName .= '_' . $tijdstempel;

    // Aanwezige deelnemers: status NOT IN (3=afgemeld, 4=niet getekend).
    // GROUP BY license_key omdat een rijder in meerdere DC's kan ingeschreven zijn
    // maar in de export 1 regel per rijder hoort. Effectief startnummer komt uit
    // competition_startnummers (lokale override) of valt terug op
    // persons.start_number — dat laatste is wat de import-module updatet
    // wanneer je een ander startnummer toewijst.
    $rowStmt = $pdo->prepare("
        SELECT
            p.license_key,
            p.full_name,
            p.short_name,
            p.gender,
            p.category,
            p.nationality,
            p.start_number,
            p.club_code,
            p.club_short,
            p.club_full,
            p.sponsor,
            p.city,
            COALESCE(csn.startnummer, p.start_number) AS effective_startnumber
        FROM entries e
        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
        JOIN persons p ON p.license_key = e.person_license
        LEFT JOIN competition_startnummers csn
            ON csn.competition_id = dc.competition_id
           AND csn.person_license = e.person_license
        WHERE dc.competition_id = ?
          AND e.status NOT IN (3, 4)
        GROUP BY p.license_key
        ORDER BY p.category, effective_startnumber
    ");
    $rowStmt->execute([$expCompId]);
    $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

    // Transponders per rijder uit de `transponders`-tabel (per persoon, per
    // wedstrijd, per slot). Slot 0 = actieve transponder (= waarmee de rijder
    // in deze wedstrijd start), slot 1 = backup/secondary. Dit is exact wat
    // Orbits/MyLaps nodig heeft. Bron kan 'knsb' (uit feed) of 'manual'
    // (via balie/Beheer) zijn — beide tellen mee.
    $tpStmt = $pdo->prepare("
        SELECT person_license, slot, code
        FROM transponders
        WHERE competition_id = ?
          AND code IS NOT NULL AND code <> ''
        ORDER BY person_license, slot
    ");
    $tpStmt->execute([$expCompId]);
    $tpMap = []; // license_key => [slot0, slot1, ...]
    foreach ($tpStmt->fetchAll(PDO::FETCH_ASSOC) as $tp) {
        $tpMap[$tp['person_license']][(int)$tp['slot']] = $tp['code'];
    }

    // MyLaps Orbits is een ouderwets Windows-tijdperk-tool dat verwacht:
    //  - Windows-1252 (CP1252) encoding — geen UTF-8 BOM
    //  - Geen RFC 4180 enclosure-handling: Orbits ziet komma's áltijd als
    //    veld-scheider, óók binnen "..."-quotes; dubbele quotes worden
    //    gewoon mee geprint i.p.v. herkend als enclosure.
    // Daarom strippen we ALLE potentiële delimiter-/quote-tekens uit elk
    // tekst-veld vóór de CSV-write. Veiliger om een zeldzame komma in een
    // clubnaam kwijt te raken dan een corrupte regel in Orbits.
    $orbits = function ($v) {
        if ($v === null || $v === '') return '';
        $s = (string)$v;
        // Strip alle CSV-stoorzenders: dubbele quote, komma, en newlines
        $s = str_replace(['"', ',', "\r", "\n"], ['', '', '', ''], $s);
        // UTF-8 → CP1252 met TRANSLIT-fallback voor exotische tekens
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $conv !== false ? $conv : $s;
    };

    // Custom CSV-row-writer: GEEN fputcsv omdat die in PHP 8.x élk veld
    // met een spatie tussen quotes zet, en Orbits handelt die quotes niet
    // af als enclosure. Doordat $orbits() al alle komma's/quotes/newlines
    // strippt, is een simpele "implode + CRLF" voldoende: enclosen is nooit
    // meer nodig.
    $orbitsRow = function (array $velden) use (&$out) {
        // CRLF — Windows-tools (Orbits draait op Windows) verwachten dat.
        fwrite($out, implode(',', $velden) . "\r\n");
    };

    header('Content-Type: text/csv; charset=windows-1252');
    header('Content-Disposition: attachment; filename="' . $safeName . '.csv"');
    $out = fopen('php://output', 'w');

    // GEEN UTF-8 BOM — Orbits ziet dat als 3 vreemde tekens vooraan
    $orbitsRow([
        'Category','StartNumber','LicenseKey','Initials','FirstName','PrefixedSurname',
        'FullName','ShortName','BirthDate','Gender','City','NationalityCode',
        'ClubCode','ClubFullName','Sponsor','Transponder1','Transponder2','VenueCode','ClubShortName',
    ]);

    foreach ($rows as $r) {
        // Slot 0 = actieve transponder (Transponder1), slot 1 = backup (Transponder2).
        $slots = $tpMap[$r['license_key']] ?? [];
        $t1    = $slots[0] ?? '';
        $t2    = $slots[1] ?? '';

        // Naam-splitsing: full_name = "Marije de Haan", short_name = "de Haan"
        // → FirstName = "Marije". short_name kan ook leeg zijn (legacy data) →
        // dan beschouwen we full_name als FirstName en houden PrefixedSurname leeg.
        $fn = trim($r['full_name'] ?? '');
        $sn = trim($r['short_name'] ?? '');
        if ($sn !== '' && str_ends_with($fn, $sn)) {
            $firstName = trim(substr($fn, 0, strlen($fn) - strlen($sn)));
        } else {
            $firstName = $fn;
        }
        $initials = $firstName !== '' ? mb_substr($firstName, 0, 1) . '.' : '';

        // Gender: DB 0=man, 1=vrouw → KNSB M / F
        $gn = $r['gender'];
        $genderChar = ($gn === null || $gn === '') ? '' : (((int)$gn === 1) ? 'F' : 'M');

        $orbitsRow([
            $orbits($r['category']),
            $orbits($r['effective_startnumber']),
            $orbits($r['license_key']),
            $orbits($initials),
            $orbits($firstName),
            $orbits($sn),
            $orbits($fn),
            $orbits($sn),
            '',                         // BirthDate: leeg (DB heeft alleen jaar)
            $orbits($genderChar),
            $orbits($r['city']),
            $orbits($r['nationality']),
            $orbits($r['club_code']),
            $orbits($r['club_full']),
            $orbits($r['sponsor']),
            $orbits($t1),
            $orbits($t2),
            '',                         // VenueCode: leeg in KNSB-bron
            $orbits($r['club_short']),
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST of DELETE']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/demo_fixture.php';
$_authUser = requireAuth($pdo);
if (!kanSchrijven($_authUser, 'importeer')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor importeer.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$compId     = trim($body['competition_id'] ?? '');
$categories = $body['categories']          ?? null;

if (!$compId) {
    http_response_code(400);
    echo json_encode(['error' => 'competition_id ontbreekt']);
    exit;
}
if (!is_array($categories)) {
    http_response_code(400);
    echo json_encode(['error' => 'categories ontbreekt — open eerst de vergelijkweergave']);
    exit;
}

$base = 'https://inschrijven.schaatsen.nl/api';

function apiGet(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'Accept: application/json',
        'timeout' => 15,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : json_decode($raw, true);
}

function dt(?string $s): ?string {
    if (!$s) return null;
    // De KNSB-feed levert tijdstempels in UTC met een 'Z' (bv.
    // "2026-07-10T22:00:00Z") en vermeldt de bedoelde zone ("W. Europe Standard
    // Time" = Europe/Amsterdam). Reken die om naar Nederlandse tijd vóór opslaan,
    // anders valt een middernacht-start (00:00 NL = 22:00 UTC) een dag terug.
    // new DateTime() respecteert de 'Z'/offset in de string zelf; de setTimezone
    // zet 'm om naar NL (incl. zomer-/wintertijd). Fallback = het oude gedrag.
    try {
        $d = new DateTime($s);
        $d->setTimezone(new DateTimeZone('Europe/Amsterdam'));
        return $d->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        return substr($s, 0, 19);
    }
}

try {
    $log = [];

    // --------------------------------------------------------
    // 0. Bron-detectie: handmatige wedstrijden skippen de KNSB-API
    // --------------------------------------------------------
    // Handmatige wedstrijden (bron='handmatig') zijn via wedstrijd_handmatig.php
    // aangemaakt en hebben geen KNSB-feed-koppeling. Metadata + DC's zijn al
    // in de DB gezet bij creatie; we hoeven hier alleen de deelnemers-stap te
    // draaien. Voor KNSB-wedstrijden blijft de flow ongewijzigd.
    $bronStmt = $pdo->prepare("SELECT bron FROM competitions WHERE id = ?");
    $bronStmt->execute([$compId]);
    $isHandmatig = ($bronStmt->fetchColumn() === 'handmatig');
    // Demo-fixture: schrijf metadata (org + wedstrijd + DC's + afstanden) uit het
    // lokale fixture-bestand i.p.v. de KNSB-API. Deelnemers komen daarna via de
    // normale deelnemers-loop uit de POST-body — precies als bij een echte import.
    $isDemo = is_demo_fixture_id($compId);

    // $comp wordt bij KNSB in stap 1 gevuld; bij handmatig/demo blijft 'em leeg.
    // De enige downstream-referentie (orgId-fallback rond regel 511) wordt
    // alleen geraakt als organisatie_id nog NULL is — voor handmatige/demo
    // wedstrijden is die altijd gevuld (wedstrijd_handmatig.php resp. de fixture).
    $comp = [];

    if ($isDemo) {
        // Demo-id wint van een eventueel achtergebleven bron (bv. oude SQL-seed
        // met bron='handmatig'); write_meta herstelt bron='demo'.
        demo_fixture_write_meta($pdo, $compId);
        $log[] = 'Demo-wedstrijd — metadata uit fixture geschreven';
    } elseif ($isHandmatig) {
        $log[] = 'Handmatige wedstrijd — KNSB-sync overgeslagen';
    } else {
    // --------------------------------------------------------
    // 1. Wedstrijd-metadata ophalen en opslaan
    // --------------------------------------------------------
    $comp = apiGet("$base/competitions/$compId");
    if (!$comp) throw new RuntimeException('Kan wedstrijd niet ophalen van KNSB API');

    $venue     = $comp['venue']            ?? null;
    $venueName = $venue['name']            ?? null;
    $venueCity = $venue['address']['city'] ?? null;
    $locatie   = $venueCity
        ? trim(implode(' – ', array_filter([$venueCity, $venueName])))
        : trim(explode("\n", $comp['location'] ?? '')[0]);

    $pdo->prepare("
        INSERT INTO competitions
               (id, name, starts, ends, location, venue_name, venue_city, discipline)
        VALUES (:id, :name, :starts, :ends, :location, :venue_name, :venue_city, :discipline)
        ON DUPLICATE KEY UPDATE
               name       = VALUES(name),       starts     = VALUES(starts),
               ends       = VALUES(ends),        location   = VALUES(location),
               venue_name = VALUES(venue_name),  venue_city = VALUES(venue_city),
               discipline = VALUES(discipline),  updated_at = CURRENT_TIMESTAMP
    ")->execute([
        ':id'         => $compId,
        ':name'       => $comp['name']      ?? '',
        ':starts'     => dt($comp['starts'] ?? null),
        ':ends'       => dt($comp['ends']   ?? null),
        ':location'   => $locatie,
        ':venue_name' => $venueName,
        ':venue_city' => $venueCity,
        ':discipline' => $comp['discipline'] ?? null,
    ]);
    $log[] = "Wedstrijd: {$comp['name']}";
    // Auto-baan-koppeling gebeurt later in vergelijk.php — daar is de
    // organisatie-context al bekend. Hier hebben we alleen venue_name +
    // venue_city, maar nog geen organisatie_id.

    // --------------------------------------------------------
    // 2. Afstandscombinaties + afstanden van KNSB ophalen
    // --------------------------------------------------------
    $dcs = apiGet("$base/competitions/$compId/distancecombinations");
    if (!$dcs) throw new RuntimeException('Kan categorieën niet ophalen van KNSB');

    $stmtDC = $pdo->prepare("
        INSERT INTO distance_combinations
               (id, competition_id, number, name, category_filter)
        VALUES (:id, :comp_id, :number, :name, :cat_filter)
        ON DUPLICATE KEY UPDATE
               number = VALUES(number), name = VALUES(name),
               category_filter = VALUES(category_filter)
    ");
    // Bij nieuwe rijen worden alle velden uit de KNSB-data gevuld; bestaande
    // rijen behouden hun handmatig aangepaste waarden voor velden die de
    // user via de afstanden-beheer UI kan wijzigen:
    //   · name         — hernoemen (bv. "200 meter dual" → "one lap")
    //   · value_meters — aangepaste afstand (bv. baan korter ingericht)
    //   · race_type    — sprint/inline/puntenkoers/afvalkoers per afstand
    // Een re-import voor een late inschrijving mag die niet ongedaan maken,
    // anders raken tijdschema_afstand_config en tijdschema_ritten verweesd
    // onder de oude waarden terwijl de instellingen aan de nieuwe hangen.
    // KNSB-only velden (number, discipline, starts) blijven we wel syncen.
    $stmtDist = $pdo->prepare("
        INSERT INTO distances
               (id, distance_combination_id, number, name, value_meters,
                discipline, starts, race_type)
        VALUES (:id, :dc_id, :number, :name, :value_meters,
                :discipline, :starts, :race_type)
        ON DUPLICATE KEY UPDATE
               number = VALUES(number),
               discipline = VALUES(discipline),
               starts = VALUES(starts)
               -- name, value_meters en race_type NIET overschrijven:
               -- user-instellingen blijven behouden
    ");
    // Heuristiek voor verse rijen — user kan achteraf per afstand bijstellen.
    $bepaalRaceType = function(?string $name, $meters): string {
        $n = mb_strtolower($name ?? '');
        if (str_contains($n, 'puntenkoers') || str_contains($n, 'punten koers')) return 'puntenkoers';
        if (str_contains($n, 'afvalkoers')  || str_contains($n, 'afval koers'))  return 'afvalkoers';
        if (str_contains($n, 'lange afstand')) return 'inline';
        if (is_numeric($meters) && (int)$meters > 1000)                           return 'inline';
        return 'sprint';
    };
    foreach ($dcs as $dc) {
        $stmtDC->execute([
            ':id'         => $dc['id'],
            ':comp_id'    => $compId,
            ':number'     => $dc['number']         ?? null,
            ':name'       => $dc['name']           ?? '',
            ':cat_filter' => $dc['categoryFilter'] ?? null,
        ]);
        foreach ($dc['distances'] ?? [] as $dist) {
            $stmtDist->execute([
                ':id'           => $dist['id'],
                ':dc_id'        => $dc['id'],
                ':number'       => $dist['number']     ?? null,
                ':name'         => $dist['name']       ?? '',
                ':value_meters' => $dist['value']      ?? null,
                ':discipline'   => $dist['discipline'] ?? null,
                ':starts'       => dt($dist['starts']  ?? null),
                ':race_type'    => $bepaalRaceType($dist['name'] ?? null, $dist['value'] ?? null),
            ]);
        }
    }
    $log[] = count($dcs) . ' categorieën opgeslagen';
    } // einde if (!$isHandmatig) — stappen 1+2 KNSB-only

    // --------------------------------------------------------
    // 3. Deelnemers verwerken vanuit beoordeelde POST-data
    // --------------------------------------------------------
    // Demo-wedstrijd: markeer de persons als demo (extern=1, extern_federatie='DEMO')
    // zodat ÓÓK later aan de fixture toegevoegde demo-rijders die vlag krijgen — en
    // overal (bv. de pending-koppel-lijst) als demo herkend worden. Bij een her-
    // import worden bestaande demo-persons zonder vlag hierdoor alsnog gemarkeerd
    // (self-heal). Bij KNSB/handmatig blijft extern/extern_federatie ongemoeid.
    $demoCols = $isDemo ? ', extern, extern_federatie'          : '';
    $demoVals = $isDemo ? ", 1, 'DEMO'"                         : '';
    $demoUpd  = $isDemo ? "extern = 1, extern_federatie = 'DEMO'," : '';
    $stmtPers = $pdo->prepare("
        INSERT INTO persons
               (license_key, full_name, short_name, gender, category,
                nationality, start_number, club_code, club_short, club_full, sponsor, city{$demoCols})
        VALUES (:license_key, :full_name, :short_name, :gender, :category,
                :nationality, :start_number, :club_code, :club_short, :club_full, :sponsor, :city{$demoVals})
        ON DUPLICATE KEY UPDATE
               -- Behoud bestaande waarde als de nieuwe leeg/null is, zodat een
               -- per ongeluk leeg ingestuurde naam geen goede naam wist.
               full_name    = COALESCE(NULLIF(VALUES(full_name), ''), full_name),
               short_name   = COALESCE(NULLIF(VALUES(short_name), ''), short_name),
               gender       = VALUES(gender),
               -- Categorie wél overschrijven met KNSB-waarde (mits niet leeg)
               -- zodat de jaarlijkse age-up cyclus automatisch doorkomt.
               category     = COALESCE(NULLIF(VALUES(category), ''), category),
               nationality  = VALUES(nationality),
               start_number = COALESCE(VALUES(start_number), start_number),
               -- club_code/short/full + sponsor: behoud bestaande als KNSB leeg
               -- stuurt. Operator kan deze via Systeem → Rijders corrigeren als
               -- KNSB-feed verkeerde of incomplete data heeft. KNSB-update mét
               -- ingevulde waarde overschrijft de correctie nog wel (= per-veld
               -- manual-override-tracking is mogelijke uitbreiding).
               club_code    = COALESCE(NULLIF(VALUES(club_code),  ''), club_code),
               club_short   = COALESCE(NULLIF(VALUES(club_short), ''), club_short),
               club_full    = COALESCE(NULLIF(VALUES(club_full),  ''), club_full),
               sponsor      = COALESCE(NULLIF(VALUES(sponsor),    ''), sponsor),
               city         = COALESCE(NULLIF(VALUES(city),       ''), city),
               {$demoUpd}
               updated_at   = CURRENT_TIMESTAMP
    ");

    // Reserve-persist:
    //  - Nieuwe entry: reserve uit KNSB-feed direct opslaan.
    //  - Bestaande entry: reserve_handmatig_ingezet=1 → KNSB mag de reserve-
    //    waarde NIET overschrijven (operator heeft ingezet, NULL blijft NULL).
    //    Anders: KNSB-waarde overschrijft (resync is leidend).
    $stmtEntry = $pdo->prepare("
        INSERT INTO entries
               (distance_combination_id, person_license, knsb_entry_id, status, reserve)
        VALUES (:dc_id, :person_license, :knsb_entry_id, :status, :reserve)
        ON DUPLICATE KEY UPDATE
               knsb_entry_id = VALUES(knsb_entry_id),
               status        = VALUES(status),
               reserve       = CASE WHEN reserve_handmatig_ingezet = 1
                                    THEN reserve
                                    ELSE VALUES(reserve)
                               END
    ");

    // Transponder: altijd overschrijven met de goedgekeurde waarde
    // source='manual' als de voorbereider de waarde heeft gewijzigd
    $stmtTp = $pdo->prepare("
        INSERT INTO transponders
               (person_license, competition_id, slot, code, source)
        VALUES (:person_license, :comp_id, :slot, :code, :source)
        ON DUPLICATE KEY UPDATE
               code       = VALUES(code),
               source     = VALUES(source),
               updated_at = CURRENT_TIMESTAMP
    ");

    // Optimistic locking: controleer entries_version
    $clientVersion = isset($body['entries_version']) ? (int)$body['entries_version'] : null;
    if ($clientVersion !== null) {
        $vStmt = $pdo->prepare("SELECT entries_version FROM competitions WHERE id = ?");
        $vStmt->execute([$compId]);
        $dbVersion = (int)($vStmt->fetchColumn() ?? 0);
        if ($dbVersion !== $clientVersion) {
            http_response_code(409);
            echo json_encode([
                'error'      => 'conflict',
                'message'    => 'De inschrijvingen zijn ondertussen gewijzigd door iemand anders. Herlaad de pagina om de actuele stand te zien.',
                'db_version' => $dbVersion,
            ]);
            exit;
        }
    }

    // Org-id ophalen voor transponder-terugschrijving
    $orgIdStmt = $pdo->prepare("SELECT organisatie_id FROM competitions WHERE id = ?");
    $orgIdStmt->execute([$compId]);
    $orgId = $orgIdStmt->fetchColumn() ?: null;

    // Nog geen koppeling? Probeer 'm nu te leggen via KNSB-contactgegevens,
    // dezelfde match-volgorde als vergelijk.php (email → naam → alias).
    // Zo staat de koppeling vast vóór de transponder-sync en verschijnt de
    // waarschuwing alleen als er echt geen organisatie te vinden is.
    if (!$orgId) {
        $contact  = $comp['settings']['contact'] ?? [];
        $orgEmail = strtolower(trim($contact['email']            ?? '')) ?: null;
        $orgNaam  = trim($contact['organizationName'] ?? '');
        $gevonden = null;
        if ($orgEmail) {
            $s = $pdo->prepare("SELECT id FROM organisaties WHERE LOWER(email) = ?");
            $s->execute([$orgEmail]);
            $gevonden = $s->fetchColumn() ?: null;
        }
        if (!$gevonden && $orgNaam) {
            $s = $pdo->prepare("SELECT id FROM organisaties WHERE naam = ?");
            $s->execute([$orgNaam]);
            $gevonden = $s->fetchColumn() ?: null;
        }
        if (!$gevonden && $orgNaam) {
            $s = $pdo->prepare("
                SELECT o.id FROM organisaties o
                JOIN organisatie_aliassen a ON a.organisatie_id = o.id
                WHERE a.naam = ?
            ");
            $s->execute([$orgNaam]);
            $gevonden = $s->fetchColumn() ?: null;
        }
        if ($gevonden) {
            $pdo->prepare("UPDATE competitions SET organisatie_id = ? WHERE id = ?")
                ->execute([$gevonden, $compId]);
            $orgId = $gevonden;
            $log[] = "Organisatie automatisch gekoppeld: $orgNaam";
        }
    }

    // Diagnostiek: laat operator zien of de transponder-sync überhaupt draait
    if (!$orgId) {
        $log[] = '⚠ Deze wedstrijd heeft geen organisatie-koppeling — '
               . 'org-transponders worden NIET bijgewerkt. '
               . 'Zet competitions.organisatie_id om dit te fixen.';
    }
    $orgTpUpdates = 0;    // hoeveel toewijzingen zijn doorgeschreven
    $orgTpSkipped = 0;    // hoeveel overgeslagen (geen match op code)

    $aantalDeelnemers = 0;
    $overgeslagen     = 0;

    foreach ($categories as $cat) {
        $dcId = $cat['dc_id'] ?? null;
        if (!$dcId) continue;

        foreach ($cat['competitors'] ?? [] as $c) {
            $lk = $c['license_key'] ?? null;
            if (!$lk) { $overgeslagen++; continue; }

            // Persoon aanmaken of bijwerken
            $stmtPers->execute([
                ':license_key'  => $lk,
                ':full_name'    => $c['full_name']    ?? '',
                ':short_name'   => $c['short_name']   ?? null,
                ':gender'       => $c['gender']       ?? null,
                ':category'     => $c['category']     ?? null,
                ':nationality'  => $c['nationality']  ?? 'NED',
                ':start_number' => $c['start_number'] ?? null,
                ':club_code'    => $c['club_code']    ?? null,
                ':club_short'   => $c['club_short']   ?? null,
                ':club_full'    => $c['club_full']    ?? null,
                ':sponsor'      => $c['sponsor']      ?? null,
                ':city'         => $c['city']         ?? null,
            ]);

            // Inschrijving aanmaken of bijwerken
            // reserve uit KNSB-feed; NULL als geen reserve (1, 2, ... voor R1, R2, ...)
            $reserveNr = $c['reserve'] ?? null;
            if ($reserveNr !== null && !is_int($reserveNr)) {
                $reserveNr = (int)$reserveNr;
                if ($reserveNr <= 0) $reserveNr = null;
            }
            $stmtEntry->execute([
                ':dc_id'          => $dcId,
                ':person_license' => $lk,
                ':knsb_entry_id'  => $c['knsb_entry_id'] ?? null,
                ':status'         => $c['entry_status']  ?? 1,
                ':reserve'        => $reserveNr,
            ]);

            // Transponders slot 1 + 2 (KNSB — read-only in UI, opslaan zoals ontvangen)
            foreach ([1 => 'transponder1', 2 => 'transponder2'] as $slot => $veld) {
                $code = $c[$veld] ?? null;
                if ($code !== null && $code !== '') {
                    $stmtTp->execute([
                        ':person_license' => $lk,
                        ':comp_id'        => $compId,
                        ':slot'           => $slot,
                        ':code'           => $code,
                        ':source'         => 'knsb',
                    ]);
                }
            }

            // Extra transponders (slot >= 3) — organisatie-toegewezen
            // Verwijder eerst alle bestaande extras voor deze rijder+competitie,
            // dan opnieuw invoegen wat de voorbereider heeft opgegeven.
            $pdo->prepare("
                DELETE FROM transponders
                WHERE person_license = ? AND competition_id = ? AND slot >= 3
            ")->execute([$lk, $compId]);

            foreach ($c['transponders_extra'] ?? [] as $i => $code) {
                $code = trim($code ?? '');
                if ($code !== '') {
                    $stmtTp->execute([
                        ':person_license' => $lk,
                        ':comp_id'        => $compId,
                        ':slot'           => $i + 3,
                        ':code'           => $code,
                        ':source'         => 'manual',
                    ]);
                }
            }

            // Actieve transponder (slot 0) — de door de voorbereider geselecteerde code.
            // Altijd opslaan (ook bij "geen"), zodat de bewuste keuze bewaard blijft:
            //   code = 'KS-44038'  → geselecteerde transponder
            //   code = NULL        → expliciet "geen" transponder
            if (array_key_exists('transponder_actief', $c)) {
                $tpActief = ($c['transponder_actief'] !== null && $c['transponder_actief'] !== '')
                    ? trim($c['transponder_actief'])
                    : null;
                $stmtTp->execute([
                    ':person_license' => $lk,
                    ':comp_id'        => $compId,
                    ':slot'           => 0,
                    ':code'           => $tpActief,
                    ':source'         => 'manual',
                ]);

                // Terugschrijven naar org-transponder tabel (twee-weg sync)
                if ($orgId) {
                    $startnr  = $c['start_number'] ?? null;

                    // UPDATE met expliciete betaald/betaald_op (gebruiken als client 0 of 1 meestuurt)
                    if (!isset($stmtOrgTpUpdateMetBetaald)) {
                        $stmtOrgTpUpdateMetBetaald = $pdo->prepare("
                            UPDATE organisatie_transponders
                            SET person_license = ?, toegewezen_snr = ?, toegewezen_naam = ?, categorie = ?,
                                betaald = ?, betaald_op = ?
                            WHERE organisatie_id = ? AND transponder_code = ?
                        ");
                    }
                    // UPDATE zonder betaald/betaald_op aan te raken (behoudt bestaande waardes
                    // als de client geen tp_betaald meestuurt, bv. bij re-import zonder
                    // dat de dropdown is aangeraakt). Voorkomt dat betaald=1 per ongeluk
                    // op 0 wordt gezet bij elke import.
                    if (!isset($stmtOrgTpUpdateBehoudBetaald)) {
                        $stmtOrgTpUpdateBehoudBetaald = $pdo->prepare("
                            UPDATE organisatie_transponders
                            SET person_license = ?, toegewezen_snr = ?, toegewezen_naam = ?, categorie = ?
                            WHERE organisatie_id = ? AND transponder_code = ?
                        ");
                    }
                    // Vrijgeven: transponders die eerder aan DEZE rijder waren toegewezen,
                    // behalve de huidige tpActief. Match primair op license_key (stabiel
                    // over naamswijzigingen heen), met fallback op naam+snr voor oude
                    // data waar person_license nog niet is ingevuld.
                    if (!isset($stmtOrgTpVrijgeven)) {
                        $stmtOrgTpVrijgeven = $pdo->prepare("
                            UPDATE organisatie_transponders
                            SET person_license = NULL, toegewezen_snr = NULL, toegewezen_naam = NULL,
                                categorie = NULL, betaald = 0, betaald_op = NULL
                            WHERE organisatie_id = ?
                              AND transponder_code != ?
                              AND (
                                  person_license = ?
                                  OR (person_license IS NULL
                                      AND toegewezen_snr  = ?
                                      AND toegewezen_naam = ?)
                              )
                        ");
                    }

                    // Eerst: oude toewijzing voor deze rijder vrijgeven (behalve huidige).
                    // BELANGRIJK: alleen vrijgeven als de rijder in deze import
                    // daadwerkelijk een ANDERE org-transponder krijgt toegewezen.
                    $naamVrijgeven = trim((string)($c['full_name'] ?? ''));
                    $tpIsOrgTp = false;
                    if ($tpActief && $orgId) {
                        if (!isset($stmtCheckOrgTp)) {
                            $stmtCheckOrgTp = $pdo->prepare("
                                SELECT 1 FROM organisatie_transponders
                                WHERE organisatie_id = ? AND transponder_code = ?
                                LIMIT 1
                            ");
                        }
                        $stmtCheckOrgTp->execute([$orgId, $tpActief]);
                        $tpIsOrgTp = (bool)$stmtCheckOrgTp->fetchColumn();
                    }
                    if ($tpIsOrgTp && $lk) {
                        $stmtOrgTpVrijgeven->execute([
                            $orgId, $tpActief, $lk, $startnr, $naamVrijgeven
                        ]);
                    }

                    // Dan: als de nieuwe transponder een org-transponder is, toewijzen
                    if ($tpActief) {
                        $fullNaam  = trim((string)($c['full_name'] ?? ''));
                        $cat       = trim((string)($c['category']  ?? ''));

                        // Vangnet: als de client geen naam meestuurt, haal 'm uit de
                        // persons-tabel op basis van license_key.
                        if ($fullNaam === '' && $lk) {
                            if (!isset($stmtHaalNaam)) {
                                $stmtHaalNaam = $pdo->prepare(
                                    "SELECT full_name FROM persons WHERE license_key = ?"
                                );
                            }
                            $stmtHaalNaam->execute([$lk]);
                            $row = $stmtHaalNaam->fetch(PDO::FETCH_ASSOC);
                            if ($row && !empty($row['full_name'])) {
                                $fullNaam = $row['full_name'];
                            }
                        }

                        // tp_betaald: null/ontbrekend = behoud bestaande waarde,
                        //             0 = expliciet 'niet betaald',
                        //             1 = expliciet 'betaald' (met datum vandaag)
                        $betaaldProvided = array_key_exists('tp_betaald', $c)
                                         && $c['tp_betaald'] !== null;

                        if ($betaaldProvided) {
                            $betaald   = ((int)$c['tp_betaald']) === 1 ? 1 : 0;
                            $betaaldOp = $betaald ? date('Y-m-d') : null;
                            $stmtOrgTpUpdateMetBetaald->execute([
                                $lk, $startnr, $fullNaam, $cat, $betaald, $betaaldOp,
                                $orgId, $tpActief
                            ]);
                            $raakte = $stmtOrgTpUpdateMetBetaald->rowCount();
                        } else {
                            $stmtOrgTpUpdateBehoudBetaald->execute([
                                $lk, $startnr, $fullNaam, $cat, $orgId, $tpActief
                            ]);
                            $raakte = $stmtOrgTpUpdateBehoudBetaald->rowCount();
                        }
                        // Diagnostiek: detecteer wanneer UPDATE niet bestaande
                        // org-transponder raakt (code hoort niet tot deze org).
                        // rowCount kan 0 zijn als de waarden gelijk waren, maar
                        // dan bestond de rij wel — we checken dus via SELECT.
                        if ($raakte === 0) {
                            $exStmt = $pdo->prepare(
                                "SELECT COUNT(*) FROM organisatie_transponders
                                 WHERE organisatie_id = ? AND transponder_code = ?"
                            );
                            $exStmt->execute([$orgId, $tpActief]);
                            if ((int)$exStmt->fetchColumn() === 0) {
                                $orgTpSkipped++;
                            } else {
                                $orgTpUpdates++;  // bestond maar waarde identiek
                            }
                        } else {
                            $orgTpUpdates++;
                        }
                    }
                }
            }

            $aantalDeelnemers++;
        }
    }

    if ($orgId) {
        $log[] = "Org-transponder sync: {$orgTpUpdates} toewijzingen doorgeschreven"
               . ($orgTpSkipped > 0 ? ", {$orgTpSkipped} codes niet gevonden in org-inventaris" : '');

        // Info-regels: rijders die wél een club-transponder hebben toegewezen,
        // maar in deze wedstrijd een andere code gebruiken (bv. eigen T1).
        // Zo heeft de voorbereider een overzicht waar "afwijkingen" zitten —
        // handig bij de balie of bij het uitleveren van transponders.
        try {
            // Primaire match op license_key (stabiel), fallback op naam+snr
            // voor oude data die nog geen person_license heeft.
            $afwijkStmt = $pdo->prepare("
                SELECT p.full_name, ot.intern_nummer,
                       ot.transponder_code AS org_code,
                       t.code AS gebruikt_code
                FROM organisatie_transponders ot
                JOIN persons p
                  ON (ot.person_license IS NOT NULL AND p.license_key = ot.person_license)
                  OR (ot.person_license IS NULL
                      AND p.full_name     = ot.toegewezen_naam
                      AND p.start_number  = ot.toegewezen_snr)
                JOIN transponders t ON t.person_license = p.license_key
                                    AND t.competition_id = ?
                                    AND t.slot = 0
                                    AND t.code IS NOT NULL
                                    AND t.code != ot.transponder_code
                WHERE ot.organisatie_id = ?
                  AND (ot.person_license IS NOT NULL OR ot.toegewezen_snr IS NOT NULL)
                ORDER BY CAST(ot.intern_nummer AS UNSIGNED)
            ");
            $afwijkStmt->execute([$compId, $orgId]);
            foreach ($afwijkStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $log[] = "ℹ {$r['full_name']}: club-transponder #{$r['intern_nummer']} ({$r['org_code']}) "
                       . "toegewezen, gebruikt in deze wedstrijd {$r['gebruikt_code']}";
            }
        } catch (Throwable $e) { /* info-regel mag niks breken */ }
    }
    $log[] = "$aantalDeelnemers deelnemers verwerkt"
           . ($overgeslagen ? " ($overgeslagen overgeslagen: geen licentienummer)" : '');

    // Bump entries_version
    $pdo->prepare("UPDATE competitions SET entries_version = entries_version + 1 WHERE id = ?")
        ->execute([$compId]);
    $newVersion = (int)$pdo->query("SELECT entries_version FROM competitions WHERE id = " . $pdo->quote($compId))->fetchColumn();

    echo json_encode([
        'ok'              => true,
        'competition_id'  => $compId,
        'log'             => $log,
        'entries_version' => $newVersion,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
