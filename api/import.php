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
    if (!preg_match('/^[a-f0-9\-]{36}$/i', $delId)) {
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Gebruik POST of DELETE']);
    exit;
}

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
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
    return $s ? substr($s, 0, 19) : null;
}

try {
    $log = [];

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
    // race_type wordt bij nieuwe rijen bepaald op basis van naam/meters;
    // bestaande rijen behouden hun (mogelijk handmatig aangepaste) race_type
    // via COALESCE, zodat een re-import de user-keuze niet overschrijft.
    $stmtDist = $pdo->prepare("
        INSERT INTO distances
               (id, distance_combination_id, number, name, value_meters,
                discipline, starts, race_type)
        VALUES (:id, :dc_id, :number, :name, :value_meters,
                :discipline, :starts, :race_type)
        ON DUPLICATE KEY UPDATE
               number = VALUES(number), name = VALUES(name),
               value_meters = VALUES(value_meters), discipline = VALUES(discipline),
               starts = VALUES(starts)
               -- race_type NIET overschrijven: user-instelling blijft behouden
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

    // --------------------------------------------------------
    // 3. Deelnemers verwerken vanuit beoordeelde POST-data
    // --------------------------------------------------------
    $stmtPers = $pdo->prepare("
        INSERT INTO persons
               (license_key, full_name, short_name, gender, category,
                nationality, start_number, club_code, club_short, club_full, sponsor, city)
        VALUES (:license_key, :full_name, :short_name, :gender, :category,
                :nationality, :start_number, :club_code, :club_short, :club_full, :sponsor, :city)
        ON DUPLICATE KEY UPDATE
               -- Behoud bestaande waarde als de nieuwe leeg/null is,
               -- zodat een per ongeluk leeg ingestuurde naam geen goede naam wist.
               full_name    = COALESCE(NULLIF(VALUES(full_name), ''), full_name),
               short_name   = COALESCE(NULLIF(VALUES(short_name), ''), short_name),
               gender       = VALUES(gender),
               category     = COALESCE(NULLIF(VALUES(category), ''), category),
               nationality  = VALUES(nationality),
               start_number = COALESCE(VALUES(start_number), start_number),
               club_code    = VALUES(club_code),
               club_short   = VALUES(club_short),
               club_full    = VALUES(club_full),
               sponsor      = VALUES(sponsor),
               city         = VALUES(city),
               updated_at   = CURRENT_TIMESTAMP
    ");

    $stmtEntry = $pdo->prepare("
        INSERT INTO entries
               (distance_combination_id, person_license, knsb_entry_id, status)
        VALUES (:dc_id, :person_license, :knsb_entry_id, :status)
        ON DUPLICATE KEY UPDATE
               knsb_entry_id = VALUES(knsb_entry_id),
               status        = VALUES(status)
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
            $stmtEntry->execute([
                ':dc_id'          => $dcId,
                ':person_license' => $lk,
                ':knsb_entry_id'  => $c['knsb_entry_id'] ?? null,
                ':status'         => $c['entry_status']  ?? 1,
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
