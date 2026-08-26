<?php
// ============================================================
//  InlineComp – System helpers (admin-only opruim/diagnostiek-tools)
//
//  POST /api/helpers.php
//    {action: "scan_wees_uitslagen"}           → diagnostiek (geen schrijfacties)
//    {action: "cleanup_wees_uitslagen", scope: "all" | "<comp_id>"}
//
//  "Wees-uitslagen" = rijen in uitslag_afstand of uitslag_klassement waar
//  geen heats meer onder zitten — typisch gevolg van wis-loting zonder de
//  archief-uitslag mee te verwijderen (komt voor bij oudere wedstrijden
//  vóór de nieuwe wis-dialog).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo, ['owner', 'admin']);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
// Action mag uit POST-body (originele schrijf-endpoints) óf uit GET-param
// (read-only endpoints zoals pending_lijst, pending_zoek_echte). Dit is
// tolerant voor beide patronen — geen breekrisico voor bestaande fetches.
$action = $body['action'] ?? $_GET['action'] ?? '';

// ── Scan: rapport per wedstrijd ─────────────────────────────────────────────
if ($action === 'scan_wees_uitslagen') {
    try {
        // BELANGRIJK: alleen wees-rijen waar de wedstrijd nog bestaat. Als de
        // wedstrijd zelf is verwijderd (uit competitions-tabel), is de
        // uitslag-rij sport-archief en MOET die blijven staan — daarom
        // bewust GEEN cascade op competition_id in uitslag_afstand/_klassement.
        // De INNER JOIN op competitions filtert die archief-rijen weg.
        //
        // Twee soorten 'wees' worden gevangen:
        //   (1) Geen heats meer voor deze cat+afstand+split → loting compleet
        //       weg, uitslag is een orphan.
        //   (2) Heats bestaan wél, maar deze rijder zit in geen enkel
        //       heat_entry → fantoom uit een vorige loting (rijder die uit
        //       de nieuwe indeling is gevallen, met stale rang/tijd uit de
        //       oude run). Detectie: NOT EXISTS op person-niveau.
        //
        // BELANGRIJK: handmatige imports (uitslag direct in DB ingevoegd zonder
        // ooit een tijdschema te hebben — bv. historische NK-PDF's) zouden
        // anders ALLE rijen als wees laten lijken. Een "heats bestaan"-check
        // was niet robuust (kon false-negative geven na een complete loting-
        // wis). Daarom de stabielere check: EXISTS competition_tijdschema
        // voor de wedstrijd. Dat record wordt aangemaakt zodra operator een
        // tijdschema-systeem kiest in de Tijdschema-tab; handmatige imports
        // missen 'm. Effecten:
        //   - Handmatige imports zonder tijdschema → genegeerd
        //   - Echte loting-wedstrijden (tijdschema bestaat, ook al zijn alle
        //     heats inmiddels gewist) → wees-check werkt normaal
        $uaStmt = $pdo->query("
            SELECT
                ua.id,
                ua.competition_id,
                ua.competition_naam,
                ua.competition_datum,
                ua.dc_naam,
                ua.distance_naam,
                ua.split_group,
                ua.person_license,
                ua.rang,
                ua.tijd_ms,
                ua.sanctie,
                ua.vastgelegd_at,
                COALESCE(p.full_name, ua.person_license) AS naam
            FROM uitslag_afstand ua
            JOIN competitions c ON c.id = ua.competition_id
            LEFT JOIN persons p ON p.license_key = ua.person_license
            WHERE EXISTS (
                SELECT 1 FROM competition_tijdschema ct
                WHERE ct.competition_id = ua.competition_id
            )
            AND NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = ua.competition_id
                  AND h.distance_combination_id = ua.distance_combination_id
                  AND (h.distance_id = ua.distance_id OR (h.distance_id IS NULL AND ua.distance_id = ''))
                  AND (h.split_group = ua.split_group OR (h.split_group IS NULL AND ua.split_group = ''))
                  AND he.person_license = ua.person_license
            )
            ORDER BY ua.competition_datum DESC, ua.competition_naam,
                     ua.dc_naam, ua.distance_naam, ua.rang
        ");
        $weesUitslag = $uaStmt->fetchAll(PDO::FETCH_ASSOC);

        // Wees uitslag_klassement: zelfde person-niveau-detectie + zelfde
        // EXISTS-gate als hierboven om handmatige imports te beschermen.
        // Klassement is per DC (niet per afstand), dus geen distance_id-check.
        $ukStmt = $pdo->query("
            SELECT
                uk.id,
                uk.competition_id,
                uk.competition_naam,
                uk.competition_datum,
                uk.dc_naam,
                uk.split_group,
                uk.person_license,
                uk.rang,
                uk.punten_totaal,
                uk.vastgelegd_at,
                COALESCE(p.full_name, uk.person_license) AS naam
            FROM uitslag_klassement uk
            JOIN competitions c ON c.id = uk.competition_id
            LEFT JOIN persons p ON p.license_key = uk.person_license
            WHERE EXISTS (
                SELECT 1 FROM competition_tijdschema ct
                WHERE ct.competition_id = uk.competition_id
            )
            AND NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = uk.competition_id
                  AND h.distance_combination_id = uk.distance_combination_id
                  AND (h.split_group = uk.split_group OR (h.split_group IS NULL AND uk.split_group = ''))
                  AND he.person_license = uk.person_license
            )
            ORDER BY uk.competition_datum DESC, uk.competition_naam, uk.dc_naam, uk.rang
        ");
        $weesKlassement = $ukStmt->fetchAll(PDO::FETCH_ASSOC);

        // Totalen + uniek aantal wedstrijden geraakt
        $compsRaakt = [];
        foreach ($weesUitslag    as $r) $compsRaakt[$r['competition_id']] = true;
        foreach ($weesKlassement as $r) $compsRaakt[$r['competition_id']] = true;

        echo json_encode([
            'ok'                  => true,
            'wees_uitslag'        => $weesUitslag,
            'wees_klassement'     => $weesKlassement,
            'totaal_uitslag_rij'  => count($weesUitslag),
            'totaal_klas_rij'     => count($weesKlassement),
            'unieke_wedstrijden'  => count($compsRaakt),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ── Cleanup: daadwerkelijk verwijderen ──────────────────────────────────────
if ($action === 'cleanup_wees_uitslagen') {
    $scope = trim($body['scope'] ?? 'all');
    // scope = 'all' → alle wedstrijden, of een specifiek competition_id.
    // 8-36 chars, alfanumeriek + dashes — range voor handmatig-geseede IDs.
    if ($scope !== 'all' && !preg_match('/^[a-z0-9\-]{8,36}$/i', $scope)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige scope (verwacht "all" of een geldig UUID)']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // uitslag_afstand — match person-niveau zodat zowel "geen heats meer"
        // als "fantoom-rijder uit vorige loting" worden opgeruimd. INNER JOIN
        // op competitions beschermt sport-archief (gewiste wedstrijden).
        // EXISTS-gate (heats voor DC+distance+split): zelfde bescherming als
        // in scan_wees_uitslagen — handmatig geïmporteerde uitslagen (geen
        // heats) worden niet meegerekend als wees.
        $sql = "
            DELETE ua FROM uitslag_afstand ua
            JOIN competitions c ON c.id = ua.competition_id
            WHERE EXISTS (
                SELECT 1 FROM competition_tijdschema ct
                WHERE ct.competition_id = ua.competition_id
            )
            AND NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = ua.competition_id
                  AND h.distance_combination_id = ua.distance_combination_id
                  AND (h.distance_id = ua.distance_id OR (h.distance_id IS NULL AND ua.distance_id = ''))
                  AND (h.split_group = ua.split_group OR (h.split_group IS NULL AND ua.split_group = ''))
                  AND he.person_license = ua.person_license
            )
        ";
        if ($scope !== 'all') $sql .= " AND ua.competition_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope === 'all' ? [] : [$scope]);
        $uaWeg = $stmt->rowCount();

        // uitslag_klassement — zelfde person-niveau-detectie + EXISTS-gate.
        $sql = "
            DELETE uk FROM uitslag_klassement uk
            JOIN competitions c ON c.id = uk.competition_id
            WHERE EXISTS (
                SELECT 1 FROM competition_tijdschema ct
                WHERE ct.competition_id = uk.competition_id
            )
            AND NOT EXISTS (
                SELECT 1 FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id          = uk.competition_id
                  AND h.distance_combination_id = uk.distance_combination_id
                  AND (h.split_group = uk.split_group OR (h.split_group IS NULL AND uk.split_group = ''))
                  AND he.person_license = uk.person_license
            )
        ";
        if ($scope !== 'all') $sql .= " AND uk.competition_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope === 'all' ? [] : [$scope]);
        $ukWeg = $stmt->rowCount();

        $pdo->commit();
        echo json_encode([
            'ok'                  => true,
            'uitslag_verwijderd'  => $uaWeg,
            'klas_verwijderd'     => $ukWeg,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── CSV-export: lijst wedstrijden met vastgelegd klassement ─────────────────
// Bedoeld voor de Helpers-CSV-export-tool: alleen wedstrijden die
// daadwerkelijk klassement-data hebben tonen we (anders heeft de operator
// niets om te exporteren).
if ($action === 'csv_export_competitions') {
    try {
        // Multi-tenant scoping: gebruiker zonder org-scope ziet alle
        // wedstrijden (default). Met scope: alleen wedstrijden van diens
        // org(s). Geforceerd JOIN naar competitions zodat we organisatie_id
        // kunnen filteren (uitslag_klassement heeft 'm niet).
        $scope = gebruikerCompScopeWhere($pdo, $_authUser, 'c');
        $stmt  = $pdo->prepare("
            SELECT DISTINCT uk.competition_id,
                            uk.competition_naam AS naam,
                            uk.competition_datum AS datum
            FROM   uitslag_klassement uk
            JOIN   competitions c ON c.id = uk.competition_id
            WHERE  1=1 " . $scope['where'] . "
            ORDER  BY uk.competition_datum DESC, uk.competition_naam
        ");
        $stmt->execute($scope['params']);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── CSV-export: alle klassement-data voor één wedstrijd ─────────────────────
// Levert per DC: dc_id, dc_naam, afstanden (lijst), rijders (met punten per
// afstand + totaal + meta). Frontend bouwt hier de CSV uit, met
// kolom-selectie per gebruiker.
if ($action === 'csv_export_data') {
    $compId = trim($body['competition_id'] ?? '');
    if (!$compId) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id verplicht']);
        exit;
    }
    try {
        // DCs + afstanden voor deze wedstrijd
        $dcStmt = $pdo->prepare("
            SELECT DISTINCT uk.distance_combination_id AS dc_id,
                            uk.dc_naam
            FROM   uitslag_klassement uk
            WHERE  uk.competition_id = ?
            ORDER  BY uk.dc_naam
        ");
        $dcStmt->execute([$compId]);
        $dcs = $dcStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($dcs)) { echo json_encode(['dcs' => []]); exit; }

        // Afstand-namen per DC ophalen uit distances-tabel (canonieke bron).
        $distStmt = $pdo->prepare("
            SELECT d.name AS afstand_naam, d.value_meters
            FROM   distances d
            WHERE  d.distance_combination_id = ?
            ORDER  BY d.value_meters, d.name
        ");

        // Klassement-rijen per DC (alleen voor déze wedstrijd) — uitslag_
        // klassement bevat al per (comp, dc, persoon) één rij met
        // punten_detail (JSON: afstandnaam → punten) en punten_totaal.
        $rijStmt = $pdo->prepare("
            SELECT uk.rang,
                   uk.punten_totaal,
                   uk.punten_detail,
                   uk.person_license,
                   uk.categorie                                          AS persoon_cat,
                   uk.split_group,
                   p.full_name                                           AS naam,
                   COALESCE(NULLIF(p.club_short,''), p.club_full, '')    AS club,
                   p.sponsor                                             AS sponsor,
                   p.category                                            AS knsb_cat,
                   COALESCE(cs.startnummer, p.start_number)              AS startnummer
            FROM   uitslag_klassement uk
            JOIN   persons p ON p.license_key = uk.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.competition_id = uk.competition_id
                  AND cs.person_license = uk.person_license
            WHERE  uk.competition_id          = ?
              AND  uk.distance_combination_id = ?
            ORDER  BY (uk.rang IS NULL), uk.rang
        ");

        $payloadDcs = [];
        foreach ($dcs as $dc) {
            $distStmt->execute([$dc['dc_id']]);
            $afstanden = $distStmt->fetchAll(PDO::FETCH_ASSOC);

            $rijStmt->execute([$compId, $dc['dc_id']]);
            $rijen = [];
            foreach ($rijStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // punten_detail = JSON-object: { distance_naam: punten, ... }
                $det = $r['punten_detail']
                    ? (json_decode($r['punten_detail'], true) ?: [])
                    : [];
                $rijen[] = [
                    'rang'           => $r['rang'] !== null ? (int)$r['rang'] : null,
                    'startnummer'    => $r['startnummer'],
                    'naam'           => $r['naam'],
                    'club'           => $r['club'],
                    'sponsor'        => $r['sponsor'] ?? '',
                    'punten_per_afstand' => $det,
                    'punten_totaal'  => $r['punten_totaal'] !== null
                        ? (float)$r['punten_totaal'] : null,
                    'persoon_cat'    => $r['persoon_cat'],
                    'knsb_cat'       => $r['knsb_cat'],
                    'split_group'    => $r['split_group'] ?? '',
                    'licentie'       => $r['person_license'],
                ];
            }
            $payloadDcs[] = [
                'dc_id'     => $dc['dc_id'],
                'dc_naam'   => $dc['dc_naam'],
                'afstanden' => array_map(fn($a) => $a['afstand_naam'], $afstanden),
                'rijders'   => $rijen,
            ];
        }

        echo json_encode(['dcs' => $payloadDcs], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//   HISTORIE-IMPORT (PDF-tekst → uitslag_afstand via Claude AI)
// ════════════════════════════════════════════════════════════════════════════
// Workflow:
//   1) historie_competitions    → lijst van wedstrijden + hun DCs (om te kiezen)
//   2) historie_extract         → PDF-tekst naar Claude, JSON terug met rijders
//                                  + auto-match elke rijder op person_license
//   3) historie_insert          → goedgekeurde rijen INSERT IGNORE in uitslag_afstand
//
// Voor 2024/2023/2022 NK's: operator maakt eerst handmatig de competition +
// DCs aan (zoals NK 2025), kiest die hier, plakt PDF-tekst, krijgt preview,
// keurt goed, klik importeren.

// ── 1) Lijst van wedstrijden + hun DCs (voor het kies-dropdown) ─────────────
if ($action === 'historie_competitions') {
    try {
        // Alleen via de historie-import aangemaakte wedstrijden (id 'hist-…') —
        // de tool is bedoeld voor historische uitslagen van wedstrijden die
        // BUITEN InlineComp zijn verreden, niet voor echte KNSB-feed-wedstrijden.
        // Een nieuwe historische wedstrijd maak je met de knop "➕ Nieuwe wedstrijd".
        // Multi-tenant: scoped admin ziet alleen zijn org-wedstrijden.
        $scope = gebruikerCompScopeWhere($pdo, $_authUser);
        $stmt  = $pdo->prepare("
            SELECT id, name, starts
            FROM   competitions
            WHERE  id LIKE 'hist-%' " . $scope['where'] . "
            ORDER  BY starts DESC, name
        ");
        $stmt->execute($scope['params']);
        $comps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Bulk-fetch DCs in één query, server-side groeperen — voorkomt N+1.
        $dcStmt = $pdo->query("
            SELECT dc.id, dc.competition_id, dc.name, dc.category_filter
            FROM   distance_combinations dc
            ORDER  BY dc.competition_id, dc.number, dc.name
        ");
        $dcsPerComp = [];
        foreach ($dcStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $dcsPerComp[$d['competition_id']][] = [
                'dc_id'    => $d['id'],
                'dc_naam'  => $d['name'],
                // category_filter is de NK-cat-code (DSA/HSA/DJA/...) — die
                // gebruiken we straks voor auto-matching cat→DC.
                'cat'      => $d['category_filter'],
            ];
        }

        $out = [];
        foreach ($comps as $c) {
            $out[] = [
                'competition_id' => $c['id'],
                'competition_naam' => $c['name'],
                'competition_datum' => $c['starts'],
                'dcs' => $dcsPerComp[$c['id']] ?? [],
            ];
        }
        echo json_encode(['wedstrijden' => $out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 1b) Geïmporteerde historie-wedstrijden (met uitslagen) — voor het bewerken ──
if ($action === 'historie_geimporteerd_lijst') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->query("
            SELECT ua.competition_id,
                   MAX(ua.competition_naam)  AS naam,
                   MAX(ua.competition_datum) AS datum,
                   COUNT(DISTINCT CONCAT(ua.distance_combination_id,'|',ua.distance_id)) AS aantal_afstanden,
                   COUNT(*) AS aantal_rijen
            FROM uitslag_afstand ua
            WHERE ua.competition_id LIKE 'hist-%'
            GROUP BY ua.competition_id
            ORDER BY datum DESC, naam
        ");
        echo json_encode(['wedstrijden' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 1c) Afstanden van één geïmporteerde wedstrijd — voor het bewerk-formulier ──
if ($action === 'historie_comp_afstanden') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($body['competition_id'] ?? ($_GET['competition_id'] ?? ''));
    if ($compId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id verplicht']);
        exit;
    }
    try {
        $naamStmt = $pdo->prepare("SELECT MAX(competition_naam) AS naam FROM uitslag_afstand WHERE competition_id = ?");
        $naamStmt->execute([$compId]);
        $naam = $naamStmt->fetchColumn() ?: '';
        $stmt = $pdo->prepare("
            SELECT distance_combination_id AS dc_id, distance_id,
                   MAX(dc_naam)       AS dc_naam,
                   MAX(distance_naam) AS distance_naam,
                   GROUP_CONCAT(DISTINCT categorie ORDER BY categorie SEPARATOR ',') AS cats,
                   COUNT(*) AS n
            FROM uitslag_afstand
            WHERE competition_id = ?
            GROUP BY distance_combination_id, distance_id
            ORDER BY dc_naam, distance_naam
        ");
        $stmt->execute([$compId]);
        echo json_encode([
            'competition_naam' => $naam,
            'afstanden'        => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 1d) Bewerk een geïmporteerde historie-wedstrijd (naam / afstanden / categorie) ──
// Werkt zowel de gedenormaliseerde uitslag_afstand-namen bij als de canonieke
// competitions/distance_combinations/distances. Alleen voor hist-%-wedstrijden
// (echte KNSB-wedstrijden komen uit de feed en worden niet hier hernoemd).
if ($action === 'historie_edit_comp') {
    header('Content-Type: application/json; charset=utf-8');
    $compId   = trim($body['competition_id'] ?? '');
    $compNaam = trim($body['competition_naam'] ?? '');
    $afstanden = $body['afstanden'] ?? [];
    if ($compId === '' || strpos($compId, 'hist-') !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Alleen historisch geïmporteerde wedstrijden (hist-…) kunnen hier bewerkt worden']);
        exit;
    }
    if ($compNaam === '') {
        http_response_code(400);
        echo json_encode(['error' => 'wedstrijdnaam mag niet leeg zijn']);
        exit;
    }
    if (!is_array($afstanden)) $afstanden = [];
    try {
        $pdo->beginTransaction();
        // Wedstrijdnaam — canoniek + gedenormaliseerd
        $pdo->prepare("UPDATE competitions SET name = ? WHERE id = ?")->execute([$compNaam, $compId]);
        $pdo->prepare("UPDATE uitslag_afstand SET competition_naam = ? WHERE competition_id = ?")->execute([$compNaam, $compId]);

        $uaStmt      = $pdo->prepare("
            UPDATE uitslag_afstand SET dc_naam = ?, distance_naam = ?, categorie = ?
            WHERE competition_id = ? AND distance_combination_id = ? AND distance_id = ?
        ");
        $uaStmtNoCat = $pdo->prepare("
            UPDATE uitslag_afstand SET dc_naam = ?, distance_naam = ?
            WHERE competition_id = ? AND distance_combination_id = ? AND distance_id = ?
        ");
        $dcStmt   = $pdo->prepare("UPDATE distance_combinations SET name = ? WHERE id = ?");
        $distStmt = $pdo->prepare("UPDATE distances SET name = ? WHERE id = ?");
        $nAfst = 0;
        foreach ($afstanden as $a) {
            $dcId   = trim($a['dc_id'] ?? '');
            $distId = trim($a['distance_id'] ?? '');
            $dcNaam = trim($a['dc_naam'] ?? '');
            $diNaam = trim($a['distance_naam'] ?? '');
            $cat    = trim($a['categorie'] ?? '');
            if ($dcId === '' && $distId === '') continue;
            if ($dcNaam === '' || $diNaam === '') continue;   // namen niet leeg maken
            // Leeg cat-veld = categorie ONGEMOEID laten (voorkomt per ongeluk
            // wissen van de cats van een gemengde afstand). Ingevuld = alle
            // rijders van deze afstand op die categorie zetten.
            if ($cat !== '') {
                $uaStmt->execute([$dcNaam, $diNaam, $cat, $compId, $dcId, $distId]);
            } else {
                $uaStmtNoCat->execute([$dcNaam, $diNaam, $compId, $dcId, $distId]);
            }
            if ($dcId   !== '') $dcStmt->execute([$dcNaam, $dcId]);
            if ($distId !== '') $distStmt->execute([$diNaam, $distId]);
            $nAfst++;
        }
        $pdo->commit();
        echo json_encode(['ok' => true, 'afstanden_bijgewerkt' => $nAfst]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 1e) Twee geïmporteerde wedstrijden samenvoegen (bron → doel) ────────────
// Voor als per ongeluk twee historie-wedstrijden zijn aangemaakt voor hetzelfde
// event (bv. baan + weg apart). De afstanden (DC's + uitslagen + klassementen)
// van de BRON worden omgehangen naar het DOEL; de lege bron verdwijnt. De naam
// van het doel blijft. Alleen hist-%-wedstrijden.
if ($action === 'historie_merge_comp') {
    header('Content-Type: application/json; charset=utf-8');
    $targetId = trim($body['target_id'] ?? '');
    $sourceId = trim($body['source_id'] ?? '');
    if ($targetId === '' || $sourceId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'target_id en source_id verplicht']);
        exit;
    }
    if ($targetId === $sourceId) {
        http_response_code(400);
        echo json_encode(['error' => 'doel en bron mogen niet dezelfde wedstrijd zijn']);
        exit;
    }
    if (strpos($targetId, 'hist-') !== 0 || strpos($sourceId, 'hist-') !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Alleen historisch geïmporteerde wedstrijden (hist-…) kunnen samengevoegd worden']);
        exit;
    }
    try {
        $chk = $pdo->prepare("SELECT id, name FROM competitions WHERE id IN (?, ?)");
        $chk->execute([$targetId, $sourceId]);
        $found = $chk->fetchAll(PDO::FETCH_KEY_PAIR);   // id => name
        if (!isset($found[$targetId]) || !isset($found[$sourceId])) {
            http_response_code(404);
            echo json_encode(['error' => 'Doel- of bron-wedstrijd niet gevonden']);
            exit;
        }
        $targetNaam = $found[$targetId];

        $pdo->beginTransaction();
        // 1) DC's van bron → doel (distances volgen automatisch via dc_id)
        $dc = $pdo->prepare("UPDATE distance_combinations SET competition_id = ? WHERE competition_id = ?");
        $dc->execute([$targetId, $sourceId]);
        $dcWeg = $dc->rowCount();
        // 2) uitslag_afstand → doel (+ gedenormaliseerde naam = doel-naam)
        $ua = $pdo->prepare("UPDATE uitslag_afstand SET competition_id = ?, competition_naam = ? WHERE competition_id = ?");
        $ua->execute([$targetId, $targetNaam, $sourceId]);
        $uaWeg = $ua->rowCount();
        // 3) uitslag_klassement → doel
        $uk = $pdo->prepare("UPDATE uitslag_klassement SET competition_id = ? WHERE competition_id = ?");
        $uk->execute([$targetId, $sourceId]);
        $ukWeg = $uk->rowCount();
        // 4) lege bron-wedstrijd verwijderen (heeft nu geen DC's meer)
        $pdo->prepare("DELETE FROM competitions WHERE id = ?")->execute([$sourceId]);
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'dcs_verplaatst'        => $dcWeg,
            'uitslagen_verplaatst'  => $uaWeg,
            'klassement_verplaatst' => $ukWeg,
            'target_naam'           => $targetNaam,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 2) PDF-tekst naar Claude AI + persons-matching ──────────────────────────
if ($action === 'historie_extract') {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        http_response_code(503);
        echo json_encode([
            'error' => 'AI-extractie niet geconfigureerd. Voeg ANTHROPIC_API_KEY '
                     . 'toe aan config_inlinecomp.php (zelfde key als voor vertaling).',
        ]);
        exit;
    }
    $pdfText = trim($body['pdf_text'] ?? '');
    // Optioneel: competition_id zodat we het wedstrijd-jaar weten — die jaartal
    // is nodig om uit (cat + jaar) een verwacht geboortejaar-bereik af te
    // leiden, en zo dubbele namen veiliger uit elkaar te kunnen halen.
    $compIdHint = trim($body['competition_id'] ?? '');
    $seizoen = null;
    if ($compIdHint !== '') {
        $cs = $pdo->prepare("SELECT YEAR(starts) FROM competitions WHERE id = ?");
        $cs->execute([$compIdHint]);
        $seizoen = (int)$cs->fetchColumn() ?: null;
    }
    if (strlen($pdfText) < 50) {
        http_response_code(400);
        echo json_encode(['error' => 'PDF-tekst te kort — plak de volledige PDF inhoud.']);
        exit;
    }
    // Hard limit op input — voorkomt per-ongeluk een hele jaarset te plakken
    if (strlen($pdfText) > 200000) {
        http_response_code(400);
        echo json_encode(['error' => 'PDF-tekst te lang (>200KB). Plak één afstand tegelijk.']);
        exit;
    }

    // Prompt — gestructureerde JSON-extractie. Identiek qua format aan het
    // Python-script in scripts/nk_historie/, zodat beide hetzelfde verwachten.
    $prompt = "Je bent een data-extractie tool voor Nederlandse inlineskate-wedstrijden (NK).\n\n"
            . "Hieronder volgt de tekst van een PDF met de UITSLAG van één afstand. De PDF kan\n"
            . "meerdere CATEGORIEEN bevatten (bv. DSA+HSA+DJA samen in één 200m-PDF).\n\n"
            . "Categorie-codes — gebruik EXACT deze codes (KNSB indeling):\n"
            . "  DSA = Dames Senioren A      HSA = Heren Senioren A\n"
            . "  DSJ = Dames Senioren-Jong.  HSJ = Heren Senioren-Jong.\n"
            . "  DJA = Dames Junioren A      HJA = Heren Junioren A\n"
            . "  DJB = Dames Junioren B      HJB = Heren Junioren B\n"
            . "  DKA = Dames Kadetten        HKA = Heren Kadetten\n"
            . "  DP1 = Dames Pupillen 1      HP1 = Heren Pupillen 1\n"
            . "  DP2 = Dames Pupillen 2      HP2 = Heren Pupillen 2\n"
            . "  DP3 = Dames Pupillen 3      HP3 = Heren Pupillen 3\n"
            . "  DP4 = Dames Pupillen 4      HP4 = Heren Pupillen 4\n"
            . "\n"
            . "BELANGRIJK over DSJ / HSJ: de 'S' is SENIOR, de 'J' is JONGEREN —\n"
            . "een sub-klassement binnen de senioren voor 1e en 2e jaars senioren\n"
            . "(rijders van ~19-20 jaar oud). NIET hetzelfde als Junioren A (HJA).\n"
            . "Vroeger gebruikt in NL-skating, inmiddels afgeschaft maar nog te zien\n"
            . "in oude uitslagen. Als de PDF letterlijk 'HSJ' of 'DSJ' schrijft →\n"
            . "neem die code over, vertaal NIET naar HJA/DJA.\n\n"
            . "Geef terug als JSON object (uitsluitend geldige JSON, géén markdown, géén\n"
            . "uitleg eromheen). Schema:\n\n"
            . "{\n"
            . "  \"afstand_naam\":     \"200m\" | \"500m\" | \"5000m punten\" | etc  of null als ONDUIDELIJK,\n"
            . "  \"afstand_meters\":   200  of null als ONDUIDELIJK,\n"
            . "  \"race_type\":        \"sprint\" | \"inline\" | \"puntenkoers\" | \"afvalkoers\"  of null als ONDUIDELIJK,\n"
            . "  \"rijders\": [\n"
            . "    {\n"
            . "      \"rang\":         1,\n"
            . "      \"naam\":         \"Volledige naam zoals in PDF\",\n"
            . "      \"licentie\":     \"12345678\" of null,\n"
            . "      \"startnummer\":  42 of null  (startnummer dat de rijder DEZE wedstrijd droeg),\n"
            . "      \"tijd\":         \"0:18.920\" of \"1:23.456\" of null  (formaat m:ss.mmm),\n"
            . "      \"geboortejaar\": 2005 of null,\n"
            . "      \"categorie\":    \"DSA\",\n"
            . "      \"club\":         \"DOST\" of \"Skeelervereniging Heerde\" of null  (zoals in PDF, vaak afkorting),\n"
            . "      \"sanctie\":      null | \"DNS\" | \"DNF\" | \"DQ-TF\" | \"DQ-SF\" | \"DQ-DF\" | \"FS\" | \"W1\" | \"W2\" | \"RR\"\n"
            . "    }\n"
            . "  ]\n"
            . "}\n\n"
            . "REGELS:\n"
            . "- NIET GOKKEN bij afstand_naam / afstand_meters / race_type: als die niet\n"
            . "  duidelijk uit de PDF blijken, geef null. Liever null dan een verkeerde\n"
            . "  aanname — operator wordt om bevestiging gevraagd.\n"
            . "- afstand_naam: normaliseer naar de KORTE notatie: \"200m\", \"500m\",\n"
            . "  \"1000m\", \"5000m punten\", \"10000m afval\". Niet \"1000 meter\" of \"1 km\".\n"
            . "- race_type bepalen O.B.V. AFSTAND, NIET PDF-format-tekst:\n"
            . "    sprint      = ALLE afstanden ≤ 1000m, ongeacht of het tijdrit of\n"
            . "                  head-to-head is. Dus 200m / 300m / 500m / 777m /\n"
            . "                  1000m → ALTIJD 'sprint'. Negeer omschrijvingen als\n"
            . "                  'Inline (head-to-head)' in de PDF-titel — dat is\n"
            . "                  format-info, geen race_type-categorie.\n"
            . "    puntenkoers = woord 'puntenkoers' / 'points' / 'punten' in titel\n"
            . "    afvalkoers  = woord 'afvalkoers' / 'elimination' / 'afval' in titel\n"
            . "    inline      = lange afstand (>1000m) ZONDER afval/punten in titel\n"
            . "- Startnummer = het BIB-nummer dat de rijder droeg in DEZE wedstrijd (vaak\n"
            . "  in een kolom 'Nr', '#', 'Bib' of als eerste cijferreeks per regel). Niet\n"
            . "  verwarren met de licentie (dat is een lang KNSB-nummer van 7-8 cijfers).\n"
            . "  Startnummers zijn meestal 1-4 cijfers.\n"
            . "- Tijd-formaat ALTIJD m:ss.mmm. Voorbeelden:\n"
            . "    \"0:18.92\"  → \"0:18.920\"  (decimaal = breukdelen van een seconde)\n"
            . "    \"18.92\"    → \"0:18.920\"\n"
            . "    \"1:23.4\"   → \"1:23.400\"\n"
            . "    \"10:23.456\" → \"10:23.456\"\n"
            . "- ALLE rijders meenemen, ook met sanctie. Neem rang ALTIJD over zoals\n"
            . "  in de officiële PDF-uitslag staat — sanctie is een label, geen\n"
            . "  reden om de positie weg te laten. Voorbeeld: rijder qualificeert\n"
            . "  voor kwartfinale (top-16) maar krijgt daar DNS of DQ-TF → eindigt\n"
            . "  als laatste in die ronde → eindpositie 16. Geef dan rang=16 en\n"
            . "  sanctie=DNS (of DQ-TF). Alleen als de PDF echt geen positie geeft\n"
            . "  (lege Pl-kolom, of '-'): rang=null.\n"
            . "- Bij ex-aequo: beide rijders krijgen dezelfde rang.\n"
            . "- Sanctie ALLEEN bij expliciete vermelding in PDF. Bij gewone finishers: null.\n"
            . "- DSQ in PDF → \"DQ-TF\" als default.\n"
            . "- Naam exact overnemen, inclusief tussenvoegsels.\n\n"
            . "PDF TEKST:\n---\n" . $pdfText . "\n---\n";

    // Retry-loop voor transient-fouten (429 rate-limit, 503/529 overloaded).
    // Exponential backoff: 2s → 4s → 8s. Andere fouten (4xx behalve 429)
    // direct teruggeven aan client — die zijn niet retry-baar.
    $raw = null; $httpRc = 0; $curlErr = '';
    $maxPogingen = 3;
    for ($poging = 1; $poging <= $maxPogingen; $poging++) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,                  // Lange timeout — PDF kan groot zijn
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => 'claude-haiku-4-5',
                'max_tokens' => 8000,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);
        $raw = curl_exec($ch);
        $httpRc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // Succes → loop uit
        if ($raw !== false && $httpRc < 400) break;

        // Transient → wacht en probeer opnieuw (alleen als nog pogingen over)
        $isTransient = in_array($httpRc, [429, 503, 529], true);
        if ($isTransient && $poging < $maxPogingen) {
            sleep(2 ** $poging);     // 2s, 4s, 8s
            continue;
        }
        // Niet-transient of laatste poging → eruit met fout
        break;
    }

    if ($raw === false || $httpRc >= 400) {
        // Vriendelijke melding voor overload-gevallen — operator weet dan
        // dat het aan Claude's kant ligt en niet aan z'n PDF of key.
        if (in_array($httpRc, [429, 503, 529], true)) {
            http_response_code(503);
            echo json_encode([
                'error' => 'Claude AI is even druk (HTTP ' . $httpRc . '). '
                         . 'We hebben ' . $maxPogingen . '× geprobeerd. '
                         . 'Probeer over een minuutje opnieuw — meestal weer beschikbaar.',
            ]);
            exit;
        }
        http_response_code(502);
        echo json_encode([
            'error' => "Claude API-fout (HTTP $httpRc)" . ($curlErr ? " — $curlErr" : '')
                     . ($raw ? ' — ' . substr($raw, 0, 300) : ''),
        ]);
        exit;
    }
    $api = json_decode($raw, true);
    $content = trim($api['content'][0]['text'] ?? '');
    // Soms wrapped Claude in code-fences ondanks de prompt
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || !isset($parsed['rijders']) || !is_array($parsed['rijders'])) {
        http_response_code(502);
        echo json_encode(['error' => 'Claude-response niet parseerbaar als JSON', 'raw' => $content]);
        exit;
    }

    // Token-verbruik + kosten berekenen voor deze call. Anthropic geeft per
    // request alleen `usage.input_tokens` / `usage.output_tokens` terug —
    // GEEN account-saldo. Voor totale balans → console.anthropic.com.
    // Prijzen claude-haiku-4-5: $1.00/M input, $5.00/M output (jan 2026).
    $inTok  = (int)($api['usage']['input_tokens']  ?? 0);
    $outTok = (int)($api['usage']['output_tokens'] ?? 0);
    $kosten = ($inTok * 1.00 + $outTok * 5.00) / 1_000_000;

    // Persons-matching: licentie eerst, naam tweedoel, naam+jaar tiebreaker
    // bij ambigu. Bulk-fetch persons om N+1 te voorkomen. Per match geven we
    // ook full_name + birth_year + category terug zodat de operator in de
    // preview kan VERIFIËREN of de juiste persoon gematcht is — cruciaal
    // bij dubbele namen op naam-only matches (PDFs zonder licentie).
    // Belangrijk: pending-rijders (pending_source='historie') uitsluiten uit
    // deze pool — anders zou een nieuwe historie-import een eerder aangemaakte
    // pending-rij als "match" suggereren, wat circulair is en de boel ver-
    // troebelt. Pending-rijders moeten ÉÉN keer gekoppeld worden via Helpers
    // → Pending koppelen aan een echte KNSB-account, niet hergebruikt worden
    // als auto-match-target.
    $personsByLic = [];
    $personsByNaam = [];
    $stmt = $pdo->query(
        "SELECT license_key, full_name, birth_year, category, club_short
         FROM persons
         WHERE anonymized_at IS NULL
           AND pending_source IS NULL"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $personsByLic[$p['license_key']] = $p;
        $key = _naamNormalize($p['full_name']);
        $personsByNaam[$key][] = $p;
    }

    // Helper: render één persoon als compacte payload voor de frontend.
    $persPayload = function($p) {
        return [
            'license_key' => $p['license_key'],
            'full_name'   => $p['full_name'],
            'birth_year'  => $p['birth_year'] !== null ? (int)$p['birth_year'] : null,
            'category'    => $p['category'],
            'club_short'  => $p['club_short'],
        ];
    };

    foreach ($parsed['rijders'] as &$r) {
        $r['tijd_ms']         = _tijdNaarMs($r['tijd'] ?? null);
        $r['person_license']  = null;
        $r['match_reden']     = null;
        $r['match_person']    = null;   // info over de gematchte persoon
        $r['match_warning']   = null;   // bv. cat-jaar mismatch
        $r['match_kandidaten'] = null;  // bij ambigu: alle opties voor handpick

        // Licentie-match eerst (sterkste signaal)
        $lic = trim((string)($r['licentie'] ?? ''));
        if ($lic !== '' && isset($personsByLic[$lic])) {
            $p = $personsByLic[$lic];
            $r['person_license'] = $lic;
            $r['match_reden']    = 'licentie';
            $r['match_person']   = $persPayload($p);
            continue;
        }

        // Naam-match
        $key = _naamNormalize($r['naam'] ?? '');
        $kandidaten = $personsByNaam[$key] ?? [];

        // ── Cat+jaar plausibiliteit-filter ───────────────────────────────────
        // Bereken het verwachte birth_year-bereik voor (PDF-cat × wedstrijd-
        // seizoen). Per kandidaat: leid HUN bereik af uit (huidige cat ×
        // huidig jaar) of birth_year als die toevallig wel gevuld is.
        // Overlap geen overlap = niet plausibel → kandidaat valt af.
        //
        // KNSB-feed levert vaak geen birth_year maar wel een category. Door
        // uit huidige cat een bereik te derive'n krijgen we tóch een sterk
        // filter ook zonder birth_years in de DB.
        //
        // Belangrijk: date('Y') schuift automatisch elk seizoen — volgend
        // jaar betekent JA = 2009-2010 ipv 2008-2009 zonder code-wijziging.
        $huidigJaar = (int)date('Y');
        $pdfBereik = _catNaarJaarBereik($r['categorie'] ?? '', $seizoen);
        $catJaarFallback = false;   // true = niemand was plausibel → toch tonen + warning
        if ($pdfBereik && count($kandidaten) > 0) {
            $plausibel = array_values(array_filter($kandidaten, function($k) use ($pdfBereik, $huidigJaar) {
                $kBereik = _persoonNaarJaarBereik($k, $huidigJaar);
                return _bereikenOverlappen($kBereik, $pdfBereik);
            }));
            if (count($plausibel) > 0) {
                $kandidaten = $plausibel;
            } else {
                // Geen plausibele kandidaten — niet alles weggooien, maar wel
                // de operator waarschuwen. Kan een legitieme edge case zijn
                // (cat-uitzondering, foute persons.category, of namesake-collision).
                $catJaarFallback = true;
            }
        }

        // ── Club-tiebreaker bij meerdere ─────────────────────────────────────
        // Als Claude een club uit de PDF haalde: kandidaten met matchende
        // club_short krijgen voorrang. Vergelijking via _clubNormalize zodat
        // "DOST 1925" matcht met persons.club_short = "DOST".
        $pdfClub = _clubNormalize($r['club'] ?? '');
        if ($pdfClub && count($kandidaten) > 1) {
            $clubMatch = array_values(array_filter($kandidaten, function($k) use ($pdfClub) {
                $ks = _clubNormalize($k['club_short'] ?? '');
                if (!$ks) return false;
                // Match als één een prefix is van de ander (DOST ⊂ DOST1925)
                return $ks === $pdfClub
                    || strpos($ks, $pdfClub) === 0
                    || strpos($pdfClub, $ks) === 0;
            }));
            if (count($clubMatch) >= 1) $kandidaten = $clubMatch;
        }

        if (count($kandidaten) === 1) {
            $p = $kandidaten[0];
            $r['person_license'] = $p['license_key'];
            $r['match_reden']    = ($r['categorie'] && $seizoen && $pdfBereik)
                                       ? 'naam+cat-jaar' : 'naam';
            $r['match_person']   = $persPayload($p);
            // Als de cat-jaar filter alles wegfilterde maar we tóch deze
            // persoon kozen (fallback) → warning voor operator.
            if ($catJaarFallback) {
                $huidigeCat = $p['category'] ?: '?';
                $r['match_warning'] =
                    "huidige cat {$huidigeCat} past niet bij {$r['categorie']} in {$seizoen}";
            }
            continue;
        }

        if (count($kandidaten) > 1) {
            // Echt ambigu — geef alle kandidaten terug zodat operator kan
            // kiezen welke de juiste is. Sortering op birth_year (jongste
            // eerst, typisch voor recente NK-deelnemers).
            usort($kandidaten, fn($a, $b) =>
                (int)($b['birth_year'] ?? 0) - (int)($a['birth_year'] ?? 0)
            );
            $r['match_reden'] = 'ambigu';
            $r['match_kandidaten'] = array_map($persPayload, $kandidaten);
            continue;
        }

        // ── Geen exacte naam-match → fuzzy suggesties op woord-overlap ───────
        // Splits PDF-naam in woorden (≥3 letters, zodat tussenvoegsels als
        // "de", "van" niet meetellen). Score elke persoon op aantal woorden
        // uit de PDF-naam die voorkomen in persons.full_name. Top-5 wordt
        // aangeboden in de picker.
        //
        // Voorbeeld: "Senn Yaniek Koeman" in PDF, "Senn Koeman" in DB.
        // Normalisatie + woord-split → ['senn', 'yaniek', 'koeman'].
        // "Senn Koeman" bevat 'senn' én 'koeman' → score 2 → top-suggestie.
        $woorden = array_filter(
            preg_split('/\s+/', _naamNormalize($r['naam'] ?? '')) ?: [],
            fn($w) => strlen($w) >= 3
        );
        if (count($woorden) > 0) {
            $fuzzy = [];
            foreach ($personsByLic as $p) {
                $persNorm = _naamNormalize($p['full_name']);
                if ($persNorm === '') continue;
                $hits = 0;
                foreach ($woorden as $w) {
                    if (strpos($persNorm, $w) !== false) $hits++;
                }
                if ($hits > 0) {
                    $fuzzy[] = ['score' => $hits, 'person' => $p];
                }
            }
            // Sorteer: meeste hits eerst, dan alfabetisch op naam
            usort($fuzzy, fn($a, $b) =>
                ($b['score'] - $a['score']) ?:
                strcmp($a['person']['full_name'], $b['person']['full_name'])
            );
            $top = array_slice($fuzzy, 0, 5);
            if (count($top) > 0) {
                $r['match_kandidaten'] = array_map(
                    fn($x) => $persPayload($x['person']), $top
                );
                $r['match_reden'] = 'fuzzy-suggesties';
            }
        }
        if ($r['match_reden'] === null) $r['match_reden'] = 'onbekend';
    }
    unset($r);

    // ── Cat-pre-fill uit eerdere imports van deze wedstrijd ───────────────
    // Als operator al eerder een PDF van deze wedstrijd heeft geïmporteerd,
    // heeft hij vermoedelijk per rijder de juiste cat ingesteld (bv DJA vs DSA
    // bij een combo-uitslag). Voor een volgende afstand-PDF van dezelfde
    // wedstrijd kunnen we die cat-keuzes hergebruiken zodat operator niet
    // alles opnieuw hoeft door te lopen.
    //
    // Lookup: voor elke license_key (gematched of pending) → meest-gebruikte
    // cat in uitslag_afstand voor deze wedstrijd. Plus naam-key voor rijden
    // die nog niet gematched zijn maar wel een eerdere PDF-rij deelden.
    if ($compIdHint !== '') {
        $eerderStmt = $pdo->prepare("
            SELECT ua.person_license, ua.categorie, p.full_name, COUNT(*) AS freq
            FROM uitslag_afstand ua
            LEFT JOIN persons p ON p.license_key = ua.person_license
            WHERE ua.competition_id = ?
              AND ua.categorie IS NOT NULL AND ua.categorie <> ''
            GROUP BY ua.person_license, ua.categorie
            ORDER BY ua.person_license, freq DESC
        ");
        $eerderStmt->execute([$compIdHint]);
        $catPerLic  = [];   // license → meest-voorkomende cat
        $catPerNaam = [];   // naam-key → meest-voorkomende cat (voor pending zonder license-match)
        foreach ($eerderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($catPerLic[$row['person_license']])) {
                $catPerLic[$row['person_license']] = $row['categorie'];
            }
            if (!empty($row['full_name'])) {
                $nKey = _naamNormalize($row['full_name']);
                if ($nKey !== '' && !isset($catPerNaam[$nKey])) {
                    $catPerNaam[$nKey] = $row['categorie'];
                }
            }
        }

        if (count($catPerLic) || count($catPerNaam)) {
            foreach ($parsed['rijders'] as &$r) {
                $eerderCat = null;
                $lic = $r['person_license'] ?? null;
                if ($lic && isset($catPerLic[$lic])) {
                    $eerderCat = $catPerLic[$lic];
                }
                if (!$eerderCat && !empty($r['naam'])) {
                    $nKey = _naamNormalize($r['naam']);
                    if ($nKey !== '' && isset($catPerNaam[$nKey])) {
                        $eerderCat = $catPerNaam[$nKey];
                    }
                }
                // Alleen overschrijven als AI-cat afwijkt en we wèl een eerdere
                // keuze hebben — anders niet noodzakelijk wijzigen.
                if ($eerderCat && $eerderCat !== ($r['categorie'] ?? null)) {
                    $r['categorie_origineel_ai'] = $r['categorie'] ?? null;
                    $r['categorie'] = $eerderCat;
                    $r['categorie_uit_eerdere_import'] = true;
                }
            }
            unset($r);
        }
    }

    echo json_encode([
        'afstand_naam'   => $parsed['afstand_naam'] ?? '',
        'afstand_meters' => $parsed['afstand_meters'] ?? null,
        'race_type'      => $parsed['race_type'] ?? null,
        'rijders'        => $parsed['rijders'],
        // Token-verbruik + kosten van DEZE call. Frontend kan dit tonen +
        // optioneel cumuleren in localStorage. Account-saldo zit NIET in
        // de API-response — voor balans → console.anthropic.com.
        'usage' => [
            'input_tokens'  => $inTok,
            'output_tokens' => $outTok,
            'kosten_usd'    => round($kosten, 4),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 3) Goedgekeurde rijen wegschrijven naar uitslag_afstand ─────────────────
if ($action === 'historie_insert') {
    $compId  = trim($body['competition_id'] ?? '');
    $afstandNaam   = trim($body['afstand_naam'] ?? '');
    $afstandMeters = $body['afstand_meters'] ?? null;
    $raceType      = trim($body['race_type'] ?? '') ?: 'sprint';   // default
    $rijen = $body['rijen'] ?? [];
    // Vervangmodus: standaard 'IGNORE' (bestaande rijen behouden, geen
    // duplicaten). Bij true → 'UPDATE' = ON DUPLICATE KEY UPDATE: overschrijft
    // rang/tijd/sanctie/categorie van bestaande rij. Handig om foute eerdere
    // imports te corrigeren met de juiste rangen.
    $vervang = !empty($body['vervang_bestaand']);

    if ($compId === '' || !is_array($rijen) || !count($rijen) || $afstandNaam === '') {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id, afstand_naam en rijen verplicht']);
        exit;
    }
    if (!in_array($raceType, ['sprint','inline','puntenkoers','afvalkoers'], true)) {
        $raceType = 'sprint';
    }
    // Defensief: alle afstanden ≤ 1000m zijn per definitie 'sprint' in onze
    // schema-conventie (= korte afstand met eindtijd, geen rondes/afval/punten).
    // Zelfs als een PDF "Inline (head-to-head)" schrijft → het BLIJFT een
    // sprint volgens onze categorisering. Punten/afval mogen wel doorzetten
    // — die zijn echt anders qua scoring, ook op korte afstand.
    if ($afstandMeters !== null && (int)$afstandMeters <= 1000
        && !in_array($raceType, ['puntenkoers', 'afvalkoers'], true)) {
        $raceType = 'sprint';
    }

    try {
        // Verifieer wedstrijd bestaat + haal naam/datum op
        $cStmt = $pdo->prepare(
            "SELECT id, name, starts FROM competitions WHERE id = ?"
        );
        $cStmt->execute([$compId]);
        $comp = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$comp) {
            http_response_code(404);
            echo json_encode(['error' => 'Wedstrijd niet gevonden']);
            exit;
        }

        // DCs van deze wedstrijd ophalen → snelle lookup op dc_id
        $dcStmt = $pdo->prepare(
            "SELECT id, name FROM distance_combinations WHERE competition_id = ?"
        );
        $dcStmt->execute([$compId]);
        $dcMap = [];
        foreach ($dcStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $dcMap[$d['id']] = $d['name'];
        }

        $geldigeSancties = ['W1','W2','FS','RR','DQ-TF','DQ-SF','DQ-DF','DNS','DNF'];

        // ── Distance-ID bepalen ──────────────────────────────────────────────
        // De print-rapport en speaker-tool lezen de afstanden-lijst per DC uit
        // de `distances`-tabel. Voor historie-import moeten we daar dus ook
        // rijen voor maken — anders krijg je 'Geen afstanden gedefinieerd' in
        // de print en speaker-overzicht.
        //
        // distance.id is een composite-PK met distance_combination_id, dus
        // hetzelfde slug-id mag in meerdere DCs voorkomen. We gebruiken een
        // deterministische slug op basis van METERS + race_type-suffix zodat:
        //   - Her-imports met andere naam-variant ("1000 meter" vs "1000m")
        //     dezelfde slug krijgen → geen dubbele distance-rijen
        //   - INSERT IGNORE idempotent blijft
        //
        // Format: "{meters}m" + optioneel "-pnt" / "-afv" voor puntenkoers
        // /afvalkoers (anders zou een 5000m sprint en 5000m punten samen-
        // vallen op dezelfde slug). Sprint en inline delen wel slug — beide
        // zijn "gewone" tijdrace, race_type-veld in distances onthoudt het
        // verschil.
        if ($afstandMeters !== null && (int)$afstandMeters > 0) {
            $distSlug = (int)$afstandMeters . 'm';
            if ($raceType === 'puntenkoers') $distSlug .= '-pnt';
            elseif ($raceType === 'afvalkoers') $distSlug .= '-afv';
        } else {
            // Fallback: meters onbekend → slug op naam (oude gedrag)
            $distSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $afstandNaam));
            $distSlug = trim(preg_replace('/-+/', '-', $distSlug), '-');
            $distSlug = substr($distSlug ?: 'afstand', 0, 32);
        }

        // Welke DCs raken we in deze batch?
        $dcsRaakvlak = [];
        foreach ($rijen as $r) {
            $dcId = $r['distance_combination_id'] ?? '';
            if ($dcId && isset($dcMap[$dcId])) $dcsRaakvlak[$dcId] = true;
        }

        // Prepared statements
        $insDist = $pdo->prepare("
            INSERT IGNORE INTO distances
                (id, distance_combination_id, name, value_meters, race_type)
            VALUES (?, ?, ?, ?, ?)
        ");
        // GEEN competition_startnummers — bewuste keuze: bij historische
        // import tonen we straks bij oude wedstrijden gewoon het HUIDIGE
        // startnummer van de rijder. De rijder is nu onder dat nieuwe nummer
        // bekend, en dat is voor speaker/coach veel handiger dan een nummer
        // uit 2022 dat niemand meer kent. Startnummer komt nog wel mee uit
        // de PDF (zie historie_extract) — als verificatie-hint in de preview-
        // tabel, niet als opgeslagen data.
        // Backfill: oude historie-rijen die nog met distance_id='' in de DB
        // staan (van vóór deze fix) krijgen retroactief het nieuwe slug-id.
        // Zo blijft de UNIQUE-key consistent en krijg je geen duplicaat-rijen
        // bij her-import van dezelfde PDF.
        $bfStmt = $pdo->prepare("
            UPDATE uitslag_afstand
            SET    distance_id = ?
            WHERE  distance_combination_id = ?
              AND  distance_id            = ''
              AND  distance_naam          = ?
        ");
        // INSERT-statement: IGNORE (skip duplicaten) of UPDATE (overschrijf)
        // op basis van de vervang_bestaand-vlag uit de payload.
        $onDup = $vervang
            ? "ON DUPLICATE KEY UPDATE
                   rang        = VALUES(rang),
                   tijd_ms     = VALUES(tijd_ms),
                   sanctie     = VALUES(sanctie),
                   categorie   = VALUES(categorie),
                   distance_naam   = VALUES(distance_naam),
                   distance_meters = VALUES(distance_meters)"
            : "";
        $verb = $vervang ? "INSERT" : "INSERT IGNORE";
        $ins = $pdo->prepare("
            $verb INTO uitslag_afstand
                (competition_id, competition_naam, competition_datum,
                 distance_combination_id, dc_naam,
                 split_group, distance_id, distance_naam, distance_meters,
                 person_license, categorie, rang, tijd_ms, sanctie)
            VALUES (?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?)
            $onDup
        ");

        $pdo->beginTransaction();

        // ── Stap 1: distance-rij per geraakte DC + backfill oude rijen ──────
        foreach (array_keys($dcsRaakvlak) as $dcId) {
            $insDist->execute([
                $distSlug, $dcId, $afstandNaam,
                $afstandMeters !== null ? (int)$afstandMeters : null,
                $raceType,
            ]);
            $bfStmt->execute([$distSlug, $dcId, $afstandNaam]);
        }

        // ── Pending-persoon helpers ─────────────────────────────────────────
        // Onbekend-rijders (geen license_key uit DB-match én niet handmatig
        // gekozen) worden NIET meer overgeslagen — ze krijgen een synthetische
        // license 'p-{12char}' en een persons-rij met pending_source='historie'.
        // Later kunnen ze via Helpers → Pending koppelen gemerged worden met
        // hun echte KNSB-account zodra die in de DB zit.
        //
        // Slimme dedupe (cross-jaar): exact-cat-match faalt voor rijders die
        // tussen import-jaren door een leeftijdscategorie zijn opgeschoven
        // (DJA 2022 → DSJ 2024 → DSA 2026, allemaal dezelfde Joes). Daarom
        // matchen we op naam + birth_year-bereik-OVERLAP:
        //
        //   - Nieuwe rij:    cat × COMP-jaar    → birth-range
        //   - Bestaand:      cat × OUDSTE-comp-jaar van die pending → birth-range
        //   - Overlap?      → hergebruik bestaande license (cat-evolutie compatibel)
        //   - Geen overlap? → verschillende personen (toevallig zelfde naam) → nieuwe rij
        //
        // Bij ontbrekende cat of jaar: terugval op naam-only match (consistent
        // gedrag met oud, geen regressie). Gender-check (H/D-prefix) als
        // extra veiligheid tegen "Joes" vs "Jose" verwarring.
        $compJaar = $comp['starts'] ? (int)substr($comp['starts'], 0, 4) : null;

        // Laad ALLE bestaande pendings één keer in een naam-genormaliseerde
        // map. Veel sneller dan N losse SELECTs (één per rij), en — belangrijker
        // — gebruikt _naamNormalize() die wèl tolerant is voor whitespace en
        // leestekens.
        //
        // Per pending bouwen we óók de BIRTH-SET: intersectie van geboortejaar-
        // bereiken uit alle (jaar × cat)-combinaties van haar bestaande
        // uitslagen. Voor een pending die DJB-2024 + DJA-2025 bevat (zelfde
        // persoon, een jaar ouder geworden) is dat intersectie {2008}.
        // De pending.category alleen kan misleidend zijn — die wijst alleen
        // naar de cat-string-van-eerste-aanmaak, niet naar alle uitslag-cats.
        $pendingPool = [];   // 'naam-sleutel' => [ {license_key, category, birth_set, …}, … ]
        $pendingAllStmt = $pdo->query("
            SELECT p.license_key, p.full_name, p.category
            FROM persons p
            WHERE p.pending_source = 'historie'
        ");
        $alleLicenses = [];
        $pendingRijen = [];
        foreach ($pendingAllStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $alleLicenses[] = $r['license_key'];
            $pendingRijen[] = $r;
        }
        // Eén query: alle (license × jaar × cat)-combinaties uit uitslagen
        $birthSetPerLic = [];
        if (!empty($alleLicenses)) {
            $phLic = implode(',', array_fill(0, count($alleLicenses), '?'));
            $combosStmt = $pdo->prepare("
                SELECT DISTINCT person_license, YEAR(competition_datum) AS jr, categorie
                FROM uitslag_afstand
                WHERE person_license IN ($phLic)
                  AND competition_datum IS NOT NULL
                  AND categorie IS NOT NULL
            ");
            $combosStmt->execute($alleLicenses);
            $combosPerLic = [];
            foreach ($combosStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $combosPerLic[$c['person_license']][] = ['jr' => (int)$c['jr'], 'cat' => $c['categorie']];
            }
            foreach ($alleLicenses as $lic) {
                $set = null;
                foreach ($combosPerLic[$lic] ?? [] as $c) {
                    $bereik = _catNaarJaarBereik($c['cat'], $c['jr']);
                    if (!$bereik) continue;
                    $jaren = range($bereik[0], $bereik[1]);
                    $set = ($set === null) ? $jaren : array_values(array_intersect($set, $jaren));
                    if (empty($set)) break;
                }
                $birthSetPerLic[$lic] = $set;
            }
        }
        // Pool opbouwen met birth_set per rij
        foreach ($pendingRijen as $r) {
            $key = _naamNormalize($r['full_name']);
            if ($key === '') continue;
            $r['birth_set'] = $birthSetPerLic[$r['license_key']] ?? null;
            if (!isset($pendingPool[$key])) $pendingPool[$key] = [];
            $pendingPool[$key][] = $r;
        }

        // ── HOGE-PRIO pool: pendings die al uitslag-rijen hebben in DEZE wedstrijd
        // Binnen één wedstrijd = dezelfde naam = dezelfde persoon (geen twee
        // zussen die exact zo heten en op zelfde event meedoen). Bij re-import
        // met aangepaste cat (HJA → HSA voor zelfde rijder) faalt de gewone
        // cat-evolutie check, want HJA/HSA in zelfde jaar overlappen niet qua
        // birth-range. Resultaat: nieuwe pending → dubbele uitslag-rij. Deze
        // pool overrulet de algemene match: naam-only, eerste wint.
        $pendingInDezeComp = [];   // 'naam-key' => license_key
        $inCompStmt = $pdo->prepare("
            SELECT DISTINCT p.license_key, p.full_name
            FROM persons p
            JOIN uitslag_afstand ua ON ua.person_license = p.license_key
            WHERE p.pending_source = 'historie'
              AND ua.competition_id = ?
        ");
        $inCompStmt->execute([$compId]);
        foreach ($inCompStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = _naamNormalize($r['full_name']);
            if ($key === '' || isset($pendingInDezeComp[$key])) continue;
            $pendingInDezeComp[$key] = $r['license_key'];
        }

        $pendingInsStmt = $pdo->prepare("
            INSERT INTO persons (license_key, full_name, category, birth_year, pending_source)
            VALUES (?, ?, ?, ?, 'historie')
        ");
        $pendingAangemaakt = 0;
        $pendingHergebruikt = 0;
        $genderUitCat = function($cat): string {
            $c = strtoupper(trim((string)$cat));
            $g = substr($c, 0, 1);
            return ($g === 'H' || $g === 'D') ? $g : '';
        };
        $maakPendingLic = function(string $naam, ?string $nieuweCat, ?int $birthYear)
            use (&$pendingPool, $pendingInsStmt,
                 &$pendingAangemaakt, &$pendingHergebruikt,
                 $compJaar, $genderUitCat, &$pendingInDezeComp): ?string
        {
            $naam = trim($naam);
            if ($naam === '') return null;
            $nameKey = _naamNormalize($naam);
            if ($nameKey === '') return null;

            // HOGE PRIO: bestaande pending in DEZE wedstrijd? → direct hergebruik.
            // Geen cat-evolutie check (binnen 1 wedstrijd = zelfde persoon, ook
            // bij cat-overschrijving zoals HJA → HSA voor combo-DC rijders).
            if (isset($pendingInDezeComp[$nameKey])) {
                $pendingHergebruikt++;
                return $pendingInDezeComp[$nameKey];
            }

            // Nieuwe rij: bereken birth-range uit (cat × comp_jaar)
            $nieuwBereik = ($nieuweCat && $compJaar)
                ? _catNaarJaarBereik($nieuweCat, $compJaar)
                : null;
            $nieuwGender = $genderUitCat($nieuweCat ?? '');

            // Kandidaten = bestaande pendings met zelfde naam-key.
            // Match-criterium: birth_set-INTERSECTION (gebaseerd op alle
            // (jaar × cat)-combinaties van elke kandidaat-pending) met het
            // birth-bereik van de nieuwe rij. Niet alleen pending.category
            // gebruiken — die is misleidend bij multi-jaar pendings.
            //
            // Conservatief: bij MEERDERE plausibele kandidaten → nieuwe pending,
            // operator beslist later via samenvoegen-banner in Helpers-tab.
            $nieuwJaren = $nieuwBereik
                ? range($nieuwBereik[0], $nieuwBereik[1])
                : null;
            $matches = [];
            foreach ($pendingPool[$nameKey] ?? [] as $k) {
                $kGender = $genderUitCat($k['category'] ?? '');
                if ($nieuwGender && $kGender && $nieuwGender !== $kGender) continue;

                $kSet = $k['birth_set'] ?? null;
                // Hard exclude: beide sets bekend & niet-leeg & geen overlap
                if ($nieuwJaren && $kSet && !empty($kSet)
                    && empty(array_intersect($nieuwJaren, $kSet))) {
                    continue;
                }
                $matches[] = $k;
            }

            if (count($matches) === 1) {
                $pendingHergebruikt++;
                return $matches[0]['license_key'];
            }
            // 0 matches → nieuwe pending (normaal pad). 2+ matches → nieuwe
            // pending want te onveilig om te raden welke; operator merget later.

            // Geen passende bestaande pending → nieuwe rij
            $catN = $nieuweCat !== null ? trim($nieuweCat) : '';
            $lic  = 'p-' . substr(bin2hex(random_bytes(8)), 0, 12);
            $pendingInsStmt->execute([$lic, $naam, $catN ?: null, $birthYear]);
            $pendingAangemaakt++;
            // Toevoegen aan pool zodat volgende rij in dezelfde request hem
            // ook kan hergebruiken (bv 200m + 500m van zelfde onbekende rijder).
            // birth_set = bereik van nieuwe rij (toekomstige uitslag-rijen in
            // dezelfde call zullen dit verder verfijnen via intersection).
            if (!isset($pendingPool[$nameKey])) $pendingPool[$nameKey] = [];
            $pendingPool[$nameKey][] = [
                'license_key' => $lic,
                'full_name'   => $naam,
                'category'    => $catN ?: null,
                'birth_set'   => $nieuwJaren,  // array of jaren, of null
            ];
            // OOK in pendingInDezeComp opnemen (by-ref via use): zelfde rijder
            // in volgende rijen van deze request (bv. 200m + 500m sprint zelfde
            // rijder) krijgt dezelfde license — anders zou rij 2 weer een
            // nieuwe pending aanmaken.
            $pendingInDezeComp[$nameKey] = $lic;
            return $lic;
        };

        // ── Stap 2: uitslag-rijen invoegen ──────────────────────────────────
        $ingevoegd   = 0;
        $bijgewerkt  = 0;  // alleen >0 bij vervang_bestaand=true
        $ongewijzigd = 0;  // bestaande rij waarde was al identiek (vervang-mode)
        $skipReasons = [];
        foreach ($rijen as $r) {
            $dcId = $r['distance_combination_id'] ?? '';
            $lic  = $r['person_license'] ?? '';
            if (!$dcId || !isset($dcMap[$dcId])) {
                $skipReasons[] = "DC niet bij deze wedstrijd: {$r['naam']}";
                continue;
            }
            // Geen license? → genereer pending-persoon ipv skippen.
            // Vereist wel een PDF-naam (anders kunnen we later niet matchen).
            if (!$lic) {
                $naam = trim($r['naam'] ?? '');
                if ($naam === '') {
                    $skipReasons[] = "Geen license en geen naam — niet te identificeren";
                    continue;
                }
                $birthHint = isset($r['birth_year_hint']) && (int)$r['birth_year_hint'] > 1900
                    ? (int)$r['birth_year_hint'] : null;
                $lic = $maakPendingLic($naam, $r['categorie'] ?? null, $birthHint);
                if (!$lic) {
                    $skipReasons[] = "Pending-aanmaak mislukt: $naam";
                    continue;
                }
            }
            $sanctie = $r['sanctie'] ?? null;
            if ($sanctie && !in_array($sanctie, $geldigeSancties, true)) {
                $sanctie = null;
            }

            // De officiële PDF-uitslag is leidend voor rang. Sancties (DNS/DNF/
            // DQ-*/FS) zijn een LABEL en geen reden om de positie weg te laten:
            // een rijder die zich kwalificeert voor de KF en daar als DNS of DQ
            // eindigt krijgt nog steeds bv. positie 16 in de eindrang. Enige
            // uitzondering: rang=0 is geen geldige positie → NULL.
            $rang = $r['rang'] !== null ? (int)$r['rang'] : null;
            if ($rang === 0) $rang = null;

            $ins->execute([
                $compId,
                $comp['name'],
                $comp['starts'] ? substr($comp['starts'], 0, 10) : null,
                $dcId,
                $dcMap[$dcId],
                $distSlug,
                $afstandNaam,
                $afstandMeters !== null ? (int)$afstandMeters : null,
                $lic,
                $r['categorie'] ?? null,
                $rang,
                $r['tijd_ms'] !== null ? (int)$r['tijd_ms'] : null,
                $sanctie,
            ]);
            // rowCount semantiek:
            //   - INSERT IGNORE: 1 = nieuw, 0 = duplicaat (UNIQUE-key match)
            //   - ON DUPLICATE KEY UPDATE: 1 = nieuw, 2 = bijgewerkt, 0 = ongewijzigd
            $rc = $ins->rowCount();
            if ($rc === 1)      $ingevoegd++;
            elseif ($rc === 2)  $bijgewerkt++;
            else                $ongewijzigd++;
        }
        $pdo->commit();

        echo json_encode([
            'ok'                 => true,
            'ingevoegd'          => $ingevoegd,
            'bijgewerkt'         => $bijgewerkt,
            'ongewijzigd'        => $ongewijzigd,
            'pending_aangemaakt' => $pendingAangemaakt,
            'pending_hergebruikt'=> $pendingHergebruikt,
            'aangeboden'         => count($rijen),
            // Voor backwards-compat: in INSERT IGNORE-mode is "duplicaten" =
            // alles wat niet ingevoegd is en niet geskipt. In vervang-mode is
            // dat semantisch "ongewijzigd" — frontend gebruikt dan dat veld.
            'duplicaten' => count($rijen) - $ingevoegd - count($skipReasons),
            'skip'       => $skipReasons,
            'distance_id'=> $distSlug,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 3b) Pending-persons lijst (te koppelen aan echte KNSB-accounts) ────────
// Toont alle persons met pending_source='historie' inclusief aantal
// uitslag_afstand-rijen die ervan afhangen, plus top-5 suggesties uit de
// echte persons-tabel zodat operator snel kan koppelen.
//
// Matching-strategie (belangrijk, niet vereenvoudigen!):
//
// Een rijder die in 2022 een DJA was kan vandaag (2026) onmogelijk nog
// een DJA zijn — die is gewoon 4 jaar ouder en zit nu in DSJ of DSA.
// Een rijder die in 2024 een DKA was is nu (2026) een DJB. Etc.
// Pure cat-string-match faalt dus categorisch voor oudere wedstrijden.
//
// Daarom: bepaal per pending-rij het GEBOORTEJAAR-BEREIK uit (PDF-cat ×
// PDF-jaar) — bv. DJA in NK 2022 → geboortejaar 2003-2004. Filter
// kandidaten alleen op:
//   1) Naam-overlap (woord-overlap, min 1 woord ≥3 chars)
//   2) Gender uit cat-prefix (H/D) moet matchen — zeer betrouwbaar
//   3) BIRTH_YEAR-overlap tussen pdf-bereik en kandidaat-bereik
//      (kandidaat-bereik = uit persons.birth_year of afgeleid uit
//      persons.category × HUIDIG jaar)
//
// Bij ontbrekende info (geen geboortejaar in persons, geen comp_datum bij
// pending) wordt de check tolerant: we excluderen alleen wanneer beide
// bereiken bekend zijn EN niet overlappen.
if ($action === 'pending_lijst') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // 1) Alle "wacht-op-KNSB"-rijders ophalen: pendings (uit uitslag-historie)
        // én externen (uit CSV-import). Voor de operator zijn beide functioneel
        // hetzelfde — een rijder waarvan we het echte KNSB-license nog niet
        // kennen. Ze worden samen in één lijst getoond zodat operator dubbelen
        // tussen pending↔extern kan opmerken en samenvoegen (typisch geval:
        // CSV-import maakte extern aan terwijl er al een pending bestond).
        //
        // Het PDF-jaar = jaar van de OUDSTE wedstrijd waar deze pending in
        // staat (de eerste historie-import). Latere imports horen meestal
        // bij dezelfde periode; voor cat→birth-bereik bepaling is het
        // oudste jaar het meest restrictief en daarmee veiligst.
        $stmt = $pdo->query("
            SELECT
                p.license_key,
                p.full_name,
                p.category,
                p.birth_year,
                p.club_short,
                p.pending_source,
                p.extern,
                p.created_at,
                (SELECT COUNT(*) FROM uitslag_afstand ua
                 WHERE ua.person_license = p.license_key) AS aantal_uitslagen,
                (SELECT YEAR(MIN(ua.competition_datum)) FROM uitslag_afstand ua
                 WHERE ua.person_license = p.license_key
                   AND ua.competition_datum IS NOT NULL) AS pdf_jaar,
                (SELECT COUNT(*) FROM entries e
                 WHERE e.person_license = p.license_key) AS aantal_entries,
                (SELECT COUNT(*) FROM transponders t
                 WHERE t.person_license = p.license_key) AS aantal_transponders
            FROM persons p
            WHERE (p.pending_source = 'historie' OR p.extern = 1)
              AND p.anonymized_at IS NULL
              AND p.license_key NOT LIKE 'demo-%'   -- demo/test-rijders nooit in de koppel-lijst
            ORDER BY p.full_name
        ");
        $pendings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Bereken per pending: birth-year-intersectie uit ALLE (jaar × cat)-
        // combinaties van haar uitslagen. Voorheen werd in de suggestie-loop
        // alleen ($p['category'] × $p['pdf_jaar']) gebruikt — dat ging fout
        // voor rijders die in meerdere jaren met VERSCHILLENDE cats stonden.
        // Voorbeeld: Noa Petitjean stond als DKA in 2022 (born 2008-09) én
        // als DJB in 2025 (born 2009-10). Huidige persons.category = DJB,
        // pdf_jaar = 2022 → fout bereik [2006-07]. Echte intersectie = [2009].
        // Suggestie voor matching een nieuwe DJA-2026 [2008-09] werd hierdoor
        // ten onrechte uitgesloten.
        $birthSets = [];     // license_key => intersection set of birth_years (or null)
        $perPersoon = [];    // license_key => array of {jr, cat} — hergebruikt voor cat-evol-label
        if (count($pendings)) {
            $licenses = array_column($pendings, 'license_key');
            $ph = implode(',', array_fill(0, count($licenses), '?'));
            $combosStmt = $pdo->prepare("
                SELECT DISTINCT person_license, YEAR(competition_datum) AS jr, categorie
                FROM uitslag_afstand
                WHERE person_license IN ($ph)
                  AND competition_datum IS NOT NULL
                  AND categorie IS NOT NULL
            ");
            $combosStmt->execute($licenses);
            foreach ($combosStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $perPersoon[$c['person_license']][] = ['jr' => (int)$c['jr'], 'cat' => $c['categorie']];
            }
            foreach ($licenses as $lic) {
                $combos = $perPersoon[$lic] ?? [];
                $set = null;
                foreach ($combos as $c) {
                    $bereik = _catNaarJaarBereik($c['cat'], $c['jr']);
                    if (!$bereik) continue;
                    $jaren = range($bereik[0], $bereik[1]);
                    $set = ($set === null) ? $jaren : array_values(array_intersect($set, $jaren));
                    if (empty($set)) break;
                }
                $birthSets[$lic] = $set;
            }
        }

        // 2) Persons-pool voor suggesties — ALLE rijen (KNSB + extern + pending)
        // zodat een pending óók een externe als suggestie kan krijgen (en
        // omgekeerd). Self-match wordt later in de loop voorkomen.
        $allRealStmt = $pdo->query("
            SELECT license_key, full_name, birth_year, category, club_short,
                   pending_source, extern
            FROM persons
            WHERE anonymized_at IS NULL
        ");
        $allReal = $allRealStmt->fetchAll(PDO::FETCH_ASSOC);

        $huidigJaar = (int)date('Y');

        // 3) Per pending: filter + rank kandidaten
        $normaliseer = function(string $s): array {
            $s = mb_strtolower($s);
            $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
            $woorden = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_filter($woorden, fn($w) => mb_strlen($w) >= 3));
        };
        // Gender-letter uit cat-string (eerste letter, H/D); '' bij anders.
        $genderUitCat = function($cat): string {
            $c = strtoupper(trim((string)$cat));
            $g = substr($c, 0, 1);
            return ($g === 'H' || $g === 'D') ? $g : '';
        };

        foreach ($pendings as &$p) {
            $p['aantal_uitslagen']     = (int)$p['aantal_uitslagen'];
            $p['aantal_entries']       = (int)($p['aantal_entries'] ?? 0);
            $p['aantal_transponders']  = (int)($p['aantal_transponders'] ?? 0);
            $p['pdf_jaar']             = $p['pdf_jaar'] !== null ? (int)$p['pdf_jaar'] : null;
            $p['is_pending']           = $p['pending_source'] !== null;
            $p['is_extern']            = ((int)($p['extern'] ?? 0)) === 1;

            // Geboortejaar-bereik uit ALLE uitslagen (intersectie van elke
            // jaar × cat-combinatie) — geeft de smalste set jaartallen die
            // past op de hele uitslag-historie van deze pending. Veel beter
            // dan ($p['category'] × $p['pdf_jaar']) want dat mixt huidige
            // persons-cat met OUDSTE wedstrijd-jaar → fout bij multi-cat-rijders.
            // Voor externen: meestal geen uitslagen → val terug op birth_year
            // of (category × huidigJaar).
            $birthSet = $birthSets[$p['license_key']] ?? null;
            if ($birthSet && count($birthSet) > 0) {
                $pdfBirthBereik = [min($birthSet), max($birthSet)];
            } elseif (!empty($p['birth_year'])) {
                $by = (int)$p['birth_year'];
                $pdfBirthBereik = [$by, $by];
            } elseif ($p['category'] && $p['pdf_jaar']) {
                $pdfBirthBereik = _catNaarJaarBereik($p['category'], $p['pdf_jaar']);
            } elseif ($p['category']) {
                // Externe zonder uitslagen — gebruik huidig jaar als referentie
                $pdfBirthBereik = _catNaarJaarBereik($p['category'], $huidigJaar);
            } else {
                $pdfBirthBereik = null;
            }
            $pdfGender = $genderUitCat($p['category'] ?? '');

            $pendingWoorden = $normaliseer($p['full_name']);
            $scores = [];

            foreach ($allReal as $r) {
                // Self-match voorkomen — incomplete rijen staan ook in $allReal
                // zodat pendings↔externen elkaar als suggestie kunnen krijgen,
                // maar een rij mag zichzelf niet voorstellen.
                if ($r['license_key'] === $p['license_key']) continue;

                // STAP A: naam-overlap — eerste hard filter
                $realWoorden = $normaliseer($r['full_name']);
                if (!$pendingWoorden || !$realWoorden) continue;
                $overlap = count(array_intersect($pendingWoorden, $realWoorden));
                if ($overlap < 1) continue;
                $naamScore = $overlap / max(count($pendingWoorden), count($realWoorden));
                if ($naamScore < 0.4) continue;  // te zwakke naam-match

                // STAP B: gender uit cat-prefix moet matchen (als beide bekend)
                // Een H… kan niet matchen met D… of omgekeerd.
                $realGender = $genderUitCat($r['category'] ?? '');
                if ($pdfGender && $realGender && $pdfGender !== $realGender) continue;

                // STAP C: leeftijd-/cat-evolutie check
                // realBirthBereik = persons.birth_year (1 jaar) of afgeleid
                // uit huidige cat × huidig jaar.
                //
                // BELANGRIJK: als de kandidaat ook een pending/extern is met
                // uitslag-historie, gebruik dan zijn birthSet-intersectie
                // (precies zoals voor de bron-rij). Anders zou de match
                // asymmetrisch zijn: A→B matcht wel maar B→A niet, omdat
                // B's persons.category het smalle bereik niet vangt.
                $realBirthSet = $birthSets[$r['license_key']] ?? null;
                if ($realBirthSet && count($realBirthSet) > 0) {
                    $realBirthBereik = [min($realBirthSet), max($realBirthSet)];
                } else {
                    $realBirthBereik = _persoonNaarJaarBereik($r, $huidigJaar);
                }
                $leeftijdReden = '';   // voor debug/uitleg in UI
                if ($pdfBirthBereik && $realBirthBereik) {
                    if (!_bereikenOverlappen($pdfBirthBereik, $realBirthBereik)) {
                        // Hard exclude: geboortejaar-bereiken kunnen niet
                        // overlappen. Voorbeeld: PDF DKA in 2022 = born 2008-09,
                        // huidige rijder DSA = born ≤2007. Onmogelijk dezelfde
                        // persoon.
                        continue;
                    }
                    // Beide bereiken bekend EN overlappen — sterk signaal
                    $leeftijdReden = "✓ leeftijd matcht (PDF " . $pdfBirthBereik[0]
                                   . '-' . $pdfBirthBereik[1] . ", DB "
                                   . $realBirthBereik[0] . '-' . $realBirthBereik[1] . ")";
                } elseif ($pdfBirthBereik && empty($r['birth_year']) && empty($r['category'])) {
                    $leeftijdReden = "? leeftijd onbekend in DB";
                } elseif (!$pdfBirthBereik) {
                    $leeftijdReden = "? PDF-jaar onbekend";
                }

                // STAP D: score-berekening
                $score = $naamScore;
                if ($leeftijdReden && strpos($leeftijdReden, '✓') === 0) {
                    // Beide bereiken bekend + overlap → flinke boost
                    $score += 0.25;
                    // Punt op de i: als beide birth_years exact bekend en
                    // identiek → extra boost (echte 1-op-1 match)
                    if ($pdfBirthBereik[0] === $pdfBirthBereik[1]
                        && $realBirthBereik[0] === $realBirthBereik[1]
                        && $pdfBirthBereik[0] === $realBirthBereik[0]) {
                        $score += 0.15;
                    }
                }
                // Club-match nog als tiebreaker (vaak een hint dat 't klopt)
                // Pending heeft geen club; we kunnen wel via uitslag_afstand
                // proberen maar dat is dure extra query — laat voorlopig zo.

                $scores[] = ['s' => $score, 'r' => $r, 'reden' => $leeftijdReden];
            }
            usort($scores, fn($a, $b) => $b['s'] <=> $a['s']);
            $p['suggesties'] = array_map(fn($x) => [
                'license_key' => $x['r']['license_key'],
                'full_name'   => $x['r']['full_name'],
                'birth_year'  => $x['r']['birth_year'] !== null ? (int)$x['r']['birth_year'] : null,
                'category'    => $x['r']['category'],
                'club_short'  => $x['r']['club_short'],
                'score'       => round($x['s'], 2),
                'reden'       => $x['reden'],
                // Type-flags zodat UI per suggestie kan tonen of doel een
                // bestaand KNSB-account, een externe rijder, of een andere
                // pending is — affecteert iconografie en bevestigingstekst.
                'is_pending'  => $x['r']['pending_source'] !== null,
                'is_extern'   => ((int)($x['r']['extern'] ?? 0)) === 1,
            ], array_slice($scores, 0, 5));
        }
        unset($p);

        // 4) Cross-pending duplicaten detecteren: pendings die mogelijk
        //    dezelfde persoon zijn als een andere pending.
        //
        //    Gebruikt $birthSets dat al hierboven (vóór de suggestie-loop)
        //    is berekend — intersectie van birth_year-bereiken over alle
        //    (jaar × cat)-combinaties per pending. Twee pendings zijn
        //    mogelijk dezelfde iff hun birth-sets overlappen.
        //
        //    Naam-vergelijking via _naamNormalize (UTF-8 safe, geen leestekens,
        //    collapsed whitespace) — anders missen we "Loes  van der Meer" vs
        //    "Loes van der Meer" of "Renée" vs "Renee".

        // Cat-evolutie en geboortejaar-label per pending. Helpt operator om
        // dezelfde-persoon-judgement te maken zonder de DB te hoeven openen:
        //   - cat_evolutie: "DJB-20 → DJB-21 → DJA-23" (oudste → recentste)
        //   - birth_label:  "2005" (exact) of "2005-2006" (range) of "?" (onbekend)
        // Defensief gedeclareerd: als de combos-pool leeg is ($perPersoon
        // bestaat niet of is leeg), blijven beide maps gewoon leeg.
        $catEvolPerLic = [];
        $birthLabelPerLic = [];
        $combosVoorLabels = $perPersoon ?? [];
        foreach ($combosVoorLabels as $lic => $combos) {
            // Sort op jaar oudst-eerst, dedup (jaar, cat) zodat we niet
            // hetzelfde par 2× tonen als rijder meerdere uitslag-rijen in
            // zelfde (jaar, cat) had.
            $uniqCombos = [];
            foreach ($combos as $c) {
                $k = $c['jr'] . '|' . $c['cat'];
                if (!isset($uniqCombos[$k])) $uniqCombos[$k] = $c;
            }
            $sorted = array_values($uniqCombos);
            usort($sorted, fn($a, $b) => $a['jr'] - $b['jr']);
            $evol = [];
            foreach ($sorted as $c) {
                $evol[] = $c['cat'] . '-' . substr((string)$c['jr'], -2);
            }
            $catEvolPerLic[$lic] = implode(' → ', $evol);
        }
        foreach ($birthSets as $lic => $set) {
            if (!$set || empty($set)) {
                $birthLabelPerLic[$lic] = '?';
            } elseif (count($set) === 1) {
                $birthLabelPerLic[$lic] = (string)$set[0];
            } else {
                $birthLabelPerLic[$lic] = min($set) . '-' . max($set);
            }
        }

        // Voor de "zelfde wedstrijd"-override: per pending welke comp_ids
        // er uitslag-rijen voor zijn. Twee pendings die overlappen in zelfde
        // comp_id zijn vrijwel zeker dezelfde persoon (binnen 1 wedstrijd
        // geen twee identieke namen) ongeacht cat/birth-bereik. Veelvoorkomende
        // oorzaak: re-import met cat-aanpassing creëerde een 2e pending vóór
        // de pending-dedup-fix.
        $compsPerLic = [];   // license => Set(comp_id)
        if (count($pendings)) {
            $licenses = array_column($pendings, 'license_key');
            $ph = implode(',', array_fill(0, count($licenses), '?'));
            $compsStmt = $pdo->prepare("
                SELECT DISTINCT person_license, competition_id
                FROM uitslag_afstand
                WHERE person_license IN ($ph)
            ");
            $compsStmt->execute($licenses);
            foreach ($compsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($compsPerLic[$r['person_license']])) {
                    $compsPerLic[$r['person_license']] = [];
                }
                $compsPerLic[$r['person_license']][$r['competition_id']] = true;
            }
        }

        // Helper: woord-overlap tussen twee namen. Voor naam-varianten zoals
        // "Nine Brecht" vs "Nine Brecht Oosterhof" — PDF's schrijven achter-
        // namen soms onvolledig. Strict naam-key-match zou ze missen.
        //
        // BELANGRIJK: minimum vereiste van 2 overlappende woorden (of alle
        // woorden als één van de namen er minder heeft). Voorkomt match op
        // alleen-achternaam ("Abel Meijer" vs "Stiebe Meijer" = 1/2 = 0.5
        // overlap, maar 1 woord dat alleen de achternaam is → onbetrouwbaar
        // omdat veel mensen dezelfde achternaam hebben).
        // Naam-overlap: simpele woord-vergelijking. Tussenvoegsels en voor-
        // naam-check ZIJN BEWUST eruit gehaald omdat de leeftijds-/birth-set
        // check de echte filter hoort te zijn — die moet 'Amber van der
        // Meijdenden DJB-2021' uitsluiten van 'Britt van der Linden DJB-2020'
        // op basis van geboortejaar-incompatibiliteit, niet op naam-frictie.
        // Min-2-woord regel blijft (voorkomt 'Abel Meijer' ≈ 'Bas Meijer'
        // op alleen-achternaam).
        $woordenLijst = function(string $naam): array {
            $naam = mb_strtolower($naam);
            $naam = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $naam);
            $woorden = preg_split('/\s+/', trim($naam), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_filter($woorden, fn($w) => mb_strlen($w) >= 3));
        };
        $naamOverlap = function(string $a, string $b) use ($woordenLijst): float {
            $wa = $woordenLijst($a);
            $wb = $woordenLijst($b);
            if (empty($wa) || empty($wb)) return 0.0;
            $intersect = count(array_intersect($wa, $wb));
            $minVereist = min(2, count($wa), count($wb));
            if ($intersect < $minVereist) return 0.0;
            return $intersect / max(count($wa), count($wb));
        };

        // Fallback-bereik helper: als birth_set uit uitslag-rijen ontbreekt
        // (null/leeg door incomplete data), gebruik dan pending.category ×
        // pending.pdf_jaar als ruwe schatting. Zo werkt de leeftijds-check
        // ook bij pendings waar de uitslag-rij-data lacunes heeft.
        $bereikUitPending = function(array $p) {
            if (empty($p['category']) || empty($p['pdf_jaar'])) return null;
            $b = _catNaarJaarBereik($p['category'], (int)$p['pdf_jaar']);
            if (!$b) return null;
            return range($b[0], $b[1]);
        };

        foreach ($pendings as &$p) {
            $p['dubbele_pendings'] = [];
            $eigenKey = _naamNormalize($p['full_name']);
            if ($eigenKey === '') continue;
            $eigenGender = $genderUitCat($p['category'] ?? '');
            $eigenSet = $birthSets[$p['license_key']] ?? null;
            // Fallback als uitslag-rij-data lacunes had
            if (!$eigenSet || empty($eigenSet)) {
                $eigenSet = $bereikUitPending($p);
            }
            $eigenComps = $compsPerLic[$p['license_key']] ?? [];
            foreach ($pendings as $ander) {
                if ($ander['license_key'] === $p['license_key']) continue;

                // Naam-match: exact OF voldoende woord-overlap (min 2 woorden
                // gedeeld). Voorkomt alleen-achternaam-matches.
                $isExact = (_naamNormalize($ander['full_name']) === $eigenKey);
                $overlapScore = $isExact
                    ? 1.0
                    : $naamOverlap($p['full_name'], $ander['full_name']);
                if ($overlapScore < 0.5) continue;

                $aGender = $genderUitCat($ander['category'] ?? '');
                if ($eigenGender && $aGender && $eigenGender !== $aGender) continue;

                // OVERRIDE: delen ze een wedstrijd? Skip birth-set check
                // ALLEEN bij EXACTE naam-match — anders gaat de check over de
                // re-import-dup-scenario (zelfde persoon, twee pendings in
                // dezelfde wedstrijd door cat-aanpassing-bug). Bij fuzzy
                // naam-match in dezelfde wedstrijd zijn het juist verschillende
                // mensen die toevallig samen reden (Amber + Britt in NK 2021).
                $aComps = $compsPerLic[$ander['license_key']] ?? [];
                $delenWedstrijd = $isExact && !empty(array_intersect_key($eigenComps, $aComps));

                if (!$delenWedstrijd) {
                    $aSet = $birthSets[$ander['license_key']] ?? null;
                    // Fallback ook hier voor de andere pending
                    if (!$aSet || empty($aSet)) {
                        $aSet = $bereikUitPending($ander);
                    }
                    // Birth-set check: alleen excluderen als BEIDE sets bekend
                    // en niet-leeg zijn én GEEN gemeenschappelijke birth_years
                    // hebben. Bij onbekend (null) of leeg → tolerant.
                    if ($eigenSet && $aSet
                        && !empty($eigenSet) && !empty($aSet)
                        && empty(array_intersect($eigenSet, $aSet))) {
                        continue;  // onmogelijk dezelfde persoon
                    }
                }
                $p['dubbele_pendings'][] = [
                    'license_key'      => $ander['license_key'],
                    'full_name'        => $ander['full_name'],
                    'category'         => $ander['category'],
                    'pdf_jaar'         => $ander['pdf_jaar'] !== null ? (int)$ander['pdf_jaar'] : null,
                    'aantal_uitslagen' => (int)$ander['aantal_uitslagen'],
                    'naam_match'       => $isExact ? 'exact' : 'fuzzy',
                    'naam_score'       => round($overlapScore, 2),
                    'cat_evolutie'     => $catEvolPerLic[$ander['license_key']] ?? '',
                    'birth_label'      => $birthLabelPerLic[$ander['license_key']] ?? '?',
                    // Type-flags zodat UI het juiste icoon (📜 vs 🌍) kan tonen
                    'is_pending'       => $ander['pending_source'] !== null,
                    'is_extern'        => ((int)($ander['extern'] ?? 0)) === 1,
                ];
            }
        }
        unset($p);

        // Cat-evolutie + geboortejaar-label toevoegen aan elke pending zelf,
        // zodat de UI-header info-rijker wordt dan alleen pending.category
        // (die misleidend kan zijn als persons.category door cat-pre-fill of
        // import-volgorde is overschreven met een latere cat).
        foreach ($pendings as &$p) {
            $p['cat_evolutie'] = $catEvolPerLic[$p['license_key']] ?? '';
            $p['birth_label']  = $birthLabelPerLic[$p['license_key']] ?? '?';
        }
        unset($p);

        // ── Zelfde-naam-cat detectie ──────────────────────────────────────
        // Personen die zichzelf geen historie/extern zijn maar wél een naam-
        // genoot in de DB hebben met exact dezelfde (genormaliseerde naam,
        // cat) — klassiek geval: rijder heeft echte KNSB-licentie én een of
        // meer dagvergunning-licenties (60xxxxxx). De feed importeert die als
        // reguliere personen; deze helper zag ze niet omdat pending_source
        // NULL is en extern=0. Nu wel: als er een echte KNSB in dezelfde
        // groep zit, verschijnt de dagvergunning-rij hier met die KNSB als
        // één-klik-koppel-suggestie.
        //
        // Match-regel per user 2026-07-06: exact match case-insensitive na
        // _naamNormalize (whitespace-collapse + leestekens strip). Geen fuzzy
        // om noise à la 'Henk van der Gugten' ≈ 'Udo van der Wier' te
        // vermijden. Cat idem case-insensitive.
        // Skip synthetische anoniem-keys ({snr}_{cat}_Anoniem uit vergelijk.php
        // buildLicenseKey()) — die matchen elkaar allemaal op naam '[Anoniem]'
        // + cat en zouden anders enorme foute groepen vormen zonder actie-waarde.
        $_allStmt = $pdo->query("
            SELECT license_key, full_name, category, birth_year, club_short,
                   pending_source, extern
            FROM persons
            WHERE anonymized_at IS NULL
              AND full_name IS NOT NULL AND full_name <> ''
              AND category  IS NOT NULL AND category  <> ''
              AND license_key NOT LIKE '%\\_Anoniem' ESCAPE '\\\\'
              AND full_name  NOT LIKE '[Anoniem]%'
        ");
        $_alle = $_allStmt->fetchAll(PDO::FETCH_ASSOC);
        $_groepen = [];
        foreach ($_alle as $_r) {
            $_key = _naamNormalize($_r['full_name']) . '|' . mb_strtolower(trim($_r['category']));
            $_groepen[$_key][] = $_r;
        }

        $_bestaandeLics = array_flip(array_column($pendings, 'license_key'));
        $_nieuweRijen   = [];
        $_detailStmt = $pdo->prepare("
            SELECT p.license_key, p.full_name, p.category, p.birth_year, p.club_short,
                   p.pending_source, p.extern, p.created_at,
                   (SELECT COUNT(*) FROM uitslag_afstand ua
                    WHERE ua.person_license = p.license_key) AS aantal_uitslagen,
                   (SELECT YEAR(MIN(ua.competition_datum)) FROM uitslag_afstand ua
                    WHERE ua.person_license = p.license_key
                      AND ua.competition_datum IS NOT NULL) AS pdf_jaar,
                   (SELECT COUNT(*) FROM entries e
                    WHERE e.person_license = p.license_key) AS aantal_entries,
                   (SELECT COUNT(*) FROM transponders t
                    WHERE t.person_license = p.license_key) AS aantal_transponders
            FROM persons p WHERE p.license_key = ?
        ");

        foreach ($_groepen as $_leden) {
            if (count($_leden) < 2) continue;
            // Echte KNSB in de groep = suggestion-target. Meerdere → 1e alfabetisch.
            $_knsb = array_values(array_filter($_leden, fn($x) =>
                $x['pending_source'] === null && ((int)($x['extern'] ?? 0)) !== 1
            ));
            if (empty($_knsb)) continue;
            usort($_knsb, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
            $_target = $_knsb[0];
            $_sugItem = [
                'license_key' => $_target['license_key'],
                'full_name'   => $_target['full_name'],
                'birth_year'  => $_target['birth_year'] !== null ? (int)$_target['birth_year'] : null,
                'category'    => $_target['category'],
                'club_short'  => $_target['club_short'],
                'score'       => 1.0,
                'reden'       => '✓ zelfde naam + cat',
                'is_pending'  => false,
                'is_extern'   => false,
            ];

            foreach ($_leden as $_lid) {
                if ($_lid['license_key'] === $_target['license_key']) continue;
                $_isHist = $_lid['pending_source'] === 'historie';
                $_isExt  = ((int)($_lid['extern'] ?? 0)) === 1;

                if (isset($_bestaandeLics[$_lid['license_key']])) {
                    // Bestaande pending/extern: KNSB toevoegen aan suggesties (dedup).
                    foreach ($pendings as &$__p) {
                        if ($__p['license_key'] !== $_lid['license_key']) continue;
                        if (!isset($__p['suggesties'])) $__p['suggesties'] = [];
                        $_al = false;
                        foreach ($__p['suggesties'] as $__s) {
                            if ($__s['license_key'] === $_target['license_key']) { $_al = true; break; }
                        }
                        if (!$_al) array_unshift($__p['suggesties'], $_sugItem);
                        break;
                    }
                    unset($__p);
                } elseif (!$_isHist && !$_isExt) {
                    // Nieuwe rij (niet-historie, niet-extern maar wel naamgenoot van KNSB).
                    $_detailStmt->execute([$_lid['license_key']]);
                    $_detail = $_detailStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$_detail) continue;
                    $_detail['birth_year']       = $_detail['birth_year'] !== null ? (int)$_detail['birth_year'] : null;
                    $_detail['pdf_jaar']         = $_detail['pdf_jaar']   !== null ? (int)$_detail['pdf_jaar']   : null;
                    $_detail['aantal_uitslagen'] = (int)$_detail['aantal_uitslagen'];
                    $_detail['aantal_entries']   = (int)$_detail['aantal_entries'];
                    $_detail['aantal_transponders'] = (int)$_detail['aantal_transponders'];
                    $_detail['match_reden']      = 'zelfde_naam_cat';
                    $_detail['dubbele_pendings'] = [];
                    $_detail['suggesties']       = [$_sugItem];
                    $_detail['cat_evolutie']     = '';
                    $_detail['birth_label']      = $_detail['birth_year'] !== null ? (string)$_detail['birth_year'] : '?';
                    $_nieuweRijen[] = $_detail;
                    $_bestaandeLics[$_detail['license_key']] = true;
                }
            }
        }

        if (count($_nieuweRijen)) {
            $pendings = array_merge($pendings, $_nieuweRijen);
            usort($pendings, fn($a, $b) => strcmp(mb_strtolower($a['full_name']), mb_strtolower($b['full_name'])));
        }

        echo json_encode(['pendings' => $pendings], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 3c) Persoon zoeken voor handmatig koppelen (autocomplete) ──────────────
// Geeft ALLE persons-rijen terug (KNSB + pending + extern) zodat operator
// vanuit een pending óók een externe als target kan vinden (en omgekeerd) —
// per result is_pending/is_extern erbij zodat UI het juiste icoon toont.
// Source-license wordt niet uitgesloten in DB: client filtert zichzelf.
if ($action === 'pending_zoek_echte') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT license_key, full_name, birth_year, category, club_short,
                   pending_source, extern
            FROM persons
            WHERE anonymized_at IS NULL
              AND license_key NOT LIKE 'demo-%'   -- demo/test-accounts nooit als koppel-doel
              AND (full_name LIKE ? OR license_key LIKE ?)
            ORDER BY full_name
            LIMIT 20
        ");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            if ($r['birth_year'] !== null) $r['birth_year'] = (int)$r['birth_year'];
            $r['is_pending'] = $r['pending_source'] !== null;
            $r['is_extern']  = ((int)($r['extern'] ?? 0)) === 1;
        }
        unset($r);
        echo json_encode(['results' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 3d) Incomplete persoon koppelen aan een ander account ──────────────────
// Verhuist uitslag_afstand + entries + transponders van source_license naar
// target_license, en verwijdert de source-rij uit persons. Doet dit transac-
// tioneel om geen ghost-rijen achter te laten bij een FK-violation.
//
// Source mag pending (p-…) of extern (extern=1) zijn — beide zijn "wacht-
// op-KNSB"-rijen. Target mag elk bestaand persons-record zijn (echt KNSB,
// pending of extern), zolang het niet de source zelf is. Zo kun je:
//   • pending → KNSB-account (oorspronkelijke flow)
//   • pending → extern (handmatige inschrijving herkennen als pending)
//   • extern  → pending (CSV-import maakte ten onrechte een nieuwe externe)
//   • extern  → KNSB-account (externe matched alsnog een KNSB-licentiehouder)
//
// Belangrijke check: als target_license al rijen heeft voor dezelfde
// (comp, dc, dist, split) — wat NIET zou moeten gebeuren maar kan als de
// rijder ondertussen al apart geïmporteerd is — dan zou de UNIQUE-key
// breken. We DELETEn dan de source-versie en houden de doel-versie.
// Hetzelfde patroon voor entries (per comp) en transponders (per comp+slot).
if ($action === 'pending_link') {
    header('Content-Type: application/json; charset=utf-8');
    $pendingLic = trim($body['pending_license'] ?? '');
    $targetLic  = trim($body['target_license']  ?? '');
    if ($pendingLic === '' || $targetLic === '') {
        http_response_code(400);
        echo json_encode(['error' => 'pending_license en target_license verplicht']);
        exit;
    }
    if ($pendingLic === $targetLic) {
        http_response_code(400);
        echo json_encode(['error' => 'Bron en doel zijn dezelfde rij']);
        exit;
    }
    try {
        // Verifieer beide bestaan + bepaal types
        $checkStmt = $pdo->prepare("SELECT license_key, pending_source, extern, full_name, category FROM persons WHERE license_key = ?");
        $checkStmt->execute([$pendingLic]);
        $pending = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$pending) {
            http_response_code(404);
            echo json_encode(['error' => "Bron-persoon $pendingLic niet gevonden"]);
            exit;
        }
        $checkStmt->execute([$targetLic]);
        $target = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            http_response_code(404);
            echo json_encode(['error' => "Doel-persoon $targetLic niet gevonden"]);
            exit;
        }
        // Source moet incompleet zijn (pending OR extern), of exact dezelfde
        // genormaliseerde naam+cat hebben als target (naamgenoot-flow — bv
        // rijder met echte licentie én dagvergunning). Een echte KNSB-rij
        // verwijderen via deze flow zonder duidelijke match zou onbedoeld
        // zijn — voor merging tussen echte accounts is een aparte admin-
        // flow nodig.
        $sourceIsPending = $pending['pending_source'] !== null;
        $sourceIsExtern  = ((int)($pending['extern'] ?? 0)) === 1;
        $srcNaam = _naamNormalize($pending['full_name'] ?? '');
        $tgtNaam = _naamNormalize($target['full_name']  ?? '');
        $srcCat  = mb_strtolower(trim($pending['category'] ?? ''), 'UTF-8');
        $tgtCat  = mb_strtolower(trim($target['category']  ?? ''), 'UTF-8');
        $sourceIsNaamgenoot = $srcNaam !== '' && $srcCat !== ''
                           && $srcNaam === $tgtNaam && $srcCat === $tgtCat;
        if (!$sourceIsPending && !$sourceIsExtern && !$sourceIsNaamgenoot) {
            http_response_code(400);
            echo json_encode(['error' => 'Bron-persoon is geen pending/externe rij en geen naamgenoot van doel']);
            exit;
        }

        $pdo->beginTransaction();

        // ── uitslag_afstand ──
        // Conflict-check: rijen die al bestaan voor het doel met dezelfde
        // (comp, dc, dist, split) → kunnen we niet zomaar verplaatsen
        // (UNIQUE violation). DELETE de source-versie, houd de doel-versie.
        $delConflict = $pdo->prepare("
            DELETE pend
            FROM uitslag_afstand pend
            JOIN uitslag_afstand tgt
              ON tgt.competition_id          = pend.competition_id
             AND tgt.distance_combination_id = pend.distance_combination_id
             AND tgt.distance_id             = pend.distance_id
             AND tgt.split_group             = pend.split_group
             AND tgt.person_license          = ?
            WHERE pend.person_license = ?
        ");
        $delConflict->execute([$targetLic, $pendingLic]);
        $conflictDeleted = $delConflict->rowCount();

        $moveStmt = $pdo->prepare("
            UPDATE uitslag_afstand
            SET    person_license = ?
            WHERE  person_license = ?
        ");
        $moveStmt->execute([$targetLic, $pendingLic]);
        $verhuisd = $moveStmt->rowCount();

        // ── entries (alleen relevant als source extern is — pendings hebben
        // ze nooit). UNIQUE-key is (distance_combination_id, person_license)
        // — entries heeft NIET direct een competition_id kolom, alleen via
        // de DC. Dus dedupe op distance_combination_id.
        $delEntriesConflict = $pdo->prepare("
            DELETE src
            FROM entries src
            JOIN entries tgt
              ON tgt.distance_combination_id = src.distance_combination_id
             AND tgt.person_license = ?
            WHERE src.person_license = ?
        ");
        $delEntriesConflict->execute([$targetLic, $pendingLic]);
        $entriesConflictDeleted = $delEntriesConflict->rowCount();

        $moveEntries = $pdo->prepare("
            UPDATE entries
            SET    person_license = ?
            WHERE  person_license = ?
        ");
        $moveEntries->execute([$targetLic, $pendingLic]);
        $entriesVerhuisd = $moveEntries->rowCount();

        // ── heat_entries. UNIQUE-key is (heat_id, person_license) —
        // dedupe voor UPDATE zoals bij entries hierboven.
        $delHeConflict = $pdo->prepare("
            DELETE src
            FROM heat_entries src
            JOIN heat_entries tgt
              ON tgt.heat_id        = src.heat_id
             AND tgt.person_license = ?
            WHERE src.person_license = ?
        ");
        $delHeConflict->execute([$targetLic, $pendingLic]);
        $heConflictDeleted = $delHeConflict->rowCount();

        $moveHe = $pdo->prepare("
            UPDATE heat_entries
            SET    person_license = ?
            WHERE  person_license = ?
        ");
        $moveHe->execute([$targetLic, $pendingLic]);
        $heVerhuisd = $moveHe->rowCount();

        // ── uitslag_klassement. UNIQUE-key:
        // (competition_id, distance_combination_id, split_group, person_license).
        $delUkConflict = $pdo->prepare("
            DELETE src
            FROM uitslag_klassement src
            JOIN uitslag_klassement tgt
              ON tgt.competition_id          = src.competition_id
             AND tgt.distance_combination_id = src.distance_combination_id
             AND tgt.split_group             = src.split_group
             AND tgt.person_license          = ?
            WHERE src.person_license = ?
        ");
        $delUkConflict->execute([$targetLic, $pendingLic]);
        $ukConflictDeleted = $delUkConflict->rowCount();

        $moveUk = $pdo->prepare("
            UPDATE uitslag_klassement
            SET    person_license = ?
            WHERE  person_license = ?
        ");
        $moveUk->execute([$targetLic, $pendingLic]);
        $ukVerhuisd = $moveUk->rowCount();

        // ── competition_startnummers. UNIQUE-key: (competition_id, person_license).
        $delCsnConflict = $pdo->prepare("
            DELETE src
            FROM competition_startnummers src
            JOIN competition_startnummers tgt
              ON tgt.competition_id  = src.competition_id
             AND tgt.person_license  = ?
            WHERE src.person_license = ?
        ");
        $delCsnConflict->execute([$targetLic, $pendingLic]);
        $csnConflictDeleted = $delCsnConflict->rowCount();

        $moveCsn = $pdo->prepare("
            UPDATE competition_startnummers
            SET    person_license = ?
            WHERE  person_license = ?
        ");
        $moveCsn->execute([$targetLic, $pendingLic]);
        $csnVerhuisd = $moveCsn->rowCount();

        // ── transponders. UNIQUE-key is (competition_id, person_license, slot).
        $delTpConflict = $pdo->prepare("
            DELETE src
            FROM transponders src
            JOIN transponders tgt
              ON tgt.competition_id = src.competition_id
             AND tgt.slot           = src.slot
             AND tgt.person_license = ?
            WHERE src.person_license = ?
        ");
        $delTpConflict->execute([$targetLic, $pendingLic]);
        $tpConflictDeleted = $delTpConflict->rowCount();

        $moveTp = $pdo->prepare("
            UPDATE transponders
            SET    person_license = ?
            WHERE  person_license = ?
        ");
        $moveTp->execute([$targetLic, $pendingLic]);
        $tpVerhuisd = $moveTp->rowCount();

        // ── Target's type blijft zoals 't is — geen "smart promotion".
        // Operator's intentie respecteren: als hij vanuit pending naar extern
        // klikt → eindresultaat is extern. Andersom: extern naar pending →
        // eindresultaat pending. Dat is de duidelijkste mental model — wat
        // je kiest, krijg je. Een KNSB-target blijft ook altijd KNSB (bron
        // moet pending OF extern zijn, dus dat is automatisch consistent).

        // ── Verwijder de source-rij. Safety-check op type is hierboven al
        // gedaan (pending/extern/naamgenoot); hier alleen op license_key
        // zodat de DELETE ook slaagt voor naamgenoot-flows (pending_source
        // NULL en extern=0).
        $delPending = $pdo->prepare("DELETE FROM persons WHERE license_key = ?");
        $delPending->execute([$pendingLic]);

        $pdo->commit();

        echo json_encode([
            'ok'                  => true,
            'verhuisd'            => $verhuisd,
            'conflict_skip'       => $conflictDeleted,
            'entries_verhuisd'    => $entriesVerhuisd,
            'entries_conflict'    => $entriesConflictDeleted,
            'he_verhuisd'         => $heVerhuisd,
            'he_conflict'         => $heConflictDeleted,
            'uk_verhuisd'         => $ukVerhuisd,
            'uk_conflict'         => $ukConflictDeleted,
            'csn_verhuisd'        => $csnVerhuisd,
            'csn_conflict'        => $csnConflictDeleted,
            'tp_verhuisd'         => $tpVerhuisd,
            'tp_conflict'         => $tpConflictDeleted,
            'pending_naam'        => $pending['full_name'],
            'target_naam'         => $target['full_name'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 3d-bis) Twee pending-rijders samenvoegen tot één ──────────────────────
// Voor het geval een rijder in meerdere historische imports zat met
// verschuivende cat (bv DJA 2022 + DSJ 2024) en daardoor twee aparte
// pending-rijen kreeg. Verhuist alle uitslag-rijen van source naar target,
// verwijdert source. Cat van target blijft staan (UI kiest meestal de
// oudste-jaar als target, maar dat is een operator-keuze).
//
// Vergelijkbaar met pending_link maar BEIDE kanten zijn pending — geen
// echte KNSB-account betrokken.
if ($action === 'pending_merge') {
    header('Content-Type: application/json; charset=utf-8');
    $srcLic = trim($body['source_license'] ?? '');
    $tgtLic = trim($body['target_license'] ?? '');
    if ($srcLic === '' || $tgtLic === '' || $srcLic === $tgtLic) {
        http_response_code(400);
        echo json_encode(['error' => 'source_license en target_license verplicht, niet identiek']);
        exit;
    }
    if (strpos($srcLic, 'p-') !== 0 || strpos($tgtLic, 'p-') !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Beide licenses moeten pending-keys zijn (p-…)']);
        exit;
    }
    try {
        $checkStmt = $pdo->prepare("SELECT license_key, pending_source, full_name FROM persons WHERE license_key = ?");
        foreach ([$srcLic, $tgtLic] as $lk) {
            $checkStmt->execute([$lk]);
            $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['pending_source'] === null) {
                http_response_code(404);
                echo json_encode(['error' => "Pending-rij $lk niet gevonden of geen pending"]);
                exit;
            }
        }

        $pdo->beginTransaction();

        // Conflict-rijen (target heeft al een rij voor zelfde comp/dc/dist/split)
        // → source-versie deleten zodat UNIQUE-key niet breekt
        $delConflict = $pdo->prepare("
            DELETE src
            FROM uitslag_afstand src
            JOIN uitslag_afstand tgt
              ON tgt.competition_id          = src.competition_id
             AND tgt.distance_combination_id = src.distance_combination_id
             AND tgt.distance_id             = src.distance_id
             AND tgt.split_group             = src.split_group
             AND tgt.person_license          = ?
            WHERE src.person_license = ?
        ");
        $delConflict->execute([$tgtLic, $srcLic]);
        $conflictDeleted = $delConflict->rowCount();

        $moveStmt = $pdo->prepare("
            UPDATE uitslag_afstand SET person_license = ? WHERE person_license = ?
        ");
        $moveStmt->execute([$tgtLic, $srcLic]);
        $verhuisd = $moveStmt->rowCount();

        // ── entries (UNIQUE: distance_combination_id, person_license)
        $delEntConflict = $pdo->prepare("
            DELETE src FROM entries src
            JOIN entries tgt
              ON tgt.distance_combination_id = src.distance_combination_id
             AND tgt.person_license = ?
            WHERE src.person_license = ?
        ");
        $delEntConflict->execute([$tgtLic, $srcLic]);
        $entriesConflictDeleted = $delEntConflict->rowCount();
        $moveEnt = $pdo->prepare("UPDATE entries SET person_license = ? WHERE person_license = ?");
        $moveEnt->execute([$tgtLic, $srcLic]);
        $entriesVerhuisd = $moveEnt->rowCount();

        // ── heat_entries (UNIQUE: heat_id, person_license)
        $delHeConflict = $pdo->prepare("
            DELETE src FROM heat_entries src
            JOIN heat_entries tgt
              ON tgt.heat_id = src.heat_id
             AND tgt.person_license = ?
            WHERE src.person_license = ?
        ");
        $delHeConflict->execute([$tgtLic, $srcLic]);
        $heConflictDeleted = $delHeConflict->rowCount();
        $moveHe = $pdo->prepare("UPDATE heat_entries SET person_license = ? WHERE person_license = ?");
        $moveHe->execute([$tgtLic, $srcLic]);
        $heVerhuisd = $moveHe->rowCount();

        // ── uitslag_klassement (UNIQUE: competition_id, dc, split_group, person_license)
        $delUkConflict = $pdo->prepare("
            DELETE src FROM uitslag_klassement src
            JOIN uitslag_klassement tgt
              ON tgt.competition_id          = src.competition_id
             AND tgt.distance_combination_id = src.distance_combination_id
             AND tgt.split_group             = src.split_group
             AND tgt.person_license          = ?
            WHERE src.person_license = ?
        ");
        $delUkConflict->execute([$tgtLic, $srcLic]);
        $ukConflictDeleted = $delUkConflict->rowCount();
        $moveUk = $pdo->prepare("UPDATE uitslag_klassement SET person_license = ? WHERE person_license = ?");
        $moveUk->execute([$tgtLic, $srcLic]);
        $ukVerhuisd = $moveUk->rowCount();

        // ── competition_startnummers (UNIQUE: competition_id, person_license)
        $delCsnConflict = $pdo->prepare("
            DELETE src FROM competition_startnummers src
            JOIN competition_startnummers tgt
              ON tgt.competition_id = src.competition_id
             AND tgt.person_license = ?
            WHERE src.person_license = ?
        ");
        $delCsnConflict->execute([$tgtLic, $srcLic]);
        $csnConflictDeleted = $delCsnConflict->rowCount();
        $moveCsn = $pdo->prepare("UPDATE competition_startnummers SET person_license = ? WHERE person_license = ?");
        $moveCsn->execute([$tgtLic, $srcLic]);
        $csnVerhuisd = $moveCsn->rowCount();

        // ── transponders (UNIQUE: competition_id, person_license, slot)
        $delTpConflict = $pdo->prepare("
            DELETE src FROM transponders src
            JOIN transponders tgt
              ON tgt.competition_id = src.competition_id
             AND tgt.slot = src.slot
             AND tgt.person_license = ?
            WHERE src.person_license = ?
        ");
        $delTpConflict->execute([$tgtLic, $srcLic]);
        $tpConflictDeleted = $delTpConflict->rowCount();
        $moveTp = $pdo->prepare("UPDATE transponders SET person_license = ? WHERE person_license = ?");
        $moveTp->execute([$tgtLic, $srcLic]);
        $tpVerhuisd = $moveTp->rowCount();

        $delSrc = $pdo->prepare("DELETE FROM persons WHERE license_key = ? AND pending_source IS NOT NULL");
        $delSrc->execute([$srcLic]);

        $pdo->commit();
        echo json_encode([
            'ok'                => true,
            'verhuisd'          => $verhuisd,
            'conflict_skip'     => $conflictDeleted,
            'entries_verhuisd'  => $entriesVerhuisd,
            'entries_conflict'  => $entriesConflictDeleted,
            'he_verhuisd'       => $heVerhuisd,
            'he_conflict'       => $heConflictDeleted,
            'uk_verhuisd'       => $ukVerhuisd,
            'uk_conflict'       => $ukConflictDeleted,
            'csn_verhuisd'      => $csnVerhuisd,
            'csn_conflict'      => $csnConflictDeleted,
            'tp_verhuisd'       => $tpVerhuisd,
            'tp_conflict'       => $tpConflictDeleted,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 3e) Pending- of externe rij verwijderen (zonder koppelen) ──────────────
// Voor het geval een wacht-op-KNSB-rij ten onrechte is aangemaakt en je 'em
// gewoon weg wilt. Verwijdert ook alle uitslag_afstand-, entries- en
// transponder-rijen die ervan afhangen. Source moet pending (pending_source
// IS NOT NULL) OF extern (extern = 1) zijn — echte KNSB-accounts kunnen
// niet via deze flow verwijderd worden (zou onbedoeld gevoelig zijn).
if ($action === 'pending_delete') {
    header('Content-Type: application/json; charset=utf-8');
    $lic = trim($body['license_key'] ?? '');
    if ($lic === '') {
        http_response_code(400);
        echo json_encode(['error' => 'license_key verplicht']);
        exit;
    }
    try {
        // Verifieer dat 't een pending of extern is — geen KNSB-account
        $chk = $pdo->prepare("SELECT pending_source, extern, full_name FROM persons WHERE license_key = ?");
        $chk->execute([$lic]);
        $persoon = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$persoon) {
            http_response_code(404);
            echo json_encode(['error' => "Persoon $lic niet gevonden"]);
            exit;
        }
        $isPending = $persoon['pending_source'] !== null;
        $isExtern  = ((int)($persoon['extern'] ?? 0)) === 1;
        if (!$isPending && !$isExtern) {
            http_response_code(400);
            echo json_encode(['error' => 'Alleen pending- of externe rijen kunnen via deze knop verwijderd worden']);
            exit;
        }

        $pdo->beginTransaction();
        // Volgorde: kindrijen → ouder. uitslag_afstand + entries + transponders
        // hangen allemaal aan person_license, niet aan elkaar — onafhankelijk.
        $delUa = $pdo->prepare("DELETE FROM uitslag_afstand WHERE person_license = ?");
        $delUa->execute([$lic]);
        $uaWeg = $delUa->rowCount();

        $delEnt = $pdo->prepare("DELETE FROM entries WHERE person_license = ?");
        $delEnt->execute([$lic]);
        $entriesWeg = $delEnt->rowCount();

        $delHe = $pdo->prepare("DELETE FROM heat_entries WHERE person_license = ?");
        $delHe->execute([$lic]);
        $heWeg = $delHe->rowCount();

        $delUk = $pdo->prepare("DELETE FROM uitslag_klassement WHERE person_license = ?");
        $delUk->execute([$lic]);
        $ukWeg = $delUk->rowCount();

        $delCsn = $pdo->prepare("DELETE FROM competition_startnummers WHERE person_license = ?");
        $delCsn->execute([$lic]);
        $csnWeg = $delCsn->rowCount();

        $delTp = $pdo->prepare("DELETE FROM transponders WHERE person_license = ?");
        $delTp->execute([$lic]);
        $tpWeg = $delTp->rowCount();

        $delP = $pdo->prepare("
            DELETE FROM persons
            WHERE license_key = ?
              AND (pending_source IS NOT NULL OR extern = 1)
        ");
        $delP->execute([$lic]);
        $personWeg = $delP->rowCount();

        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'uitslagen_verwijderd'    => $uaWeg,
            'entries_verwijderd'      => $entriesWeg,
            'heat_entries_verwijderd' => $heWeg,
            'klassement_verwijderd'   => $ukWeg,
            'startnrs_verwijderd'     => $csnWeg,
            'transponders_verwijderd' => $tpWeg,
            'person_verwijderd'       => $personWeg,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 3f) Bulk verwijderen van pending/externe rijen ─────────────────────────
// Verwijdert meerdere "wacht-op-KNSB"-rijen in één transactie. Per license_key
// geldt dezelfde guard als pending_delete: alleen pending (pending_source IS
// NOT NULL) of extern (extern = 1) — echte KNSB-accounts worden overgeslagen.
if ($action === 'pending_bulk_delete') {
    header('Content-Type: application/json; charset=utf-8');
    $lics = $body['license_keys'] ?? [];
    if (!is_array($lics)) $lics = [];
    $lics = array_values(array_unique(array_filter(array_map('trim', $lics), 'strlen')));
    if (!count($lics)) {
        http_response_code(400);
        echo json_encode(['error' => 'license_keys (niet-lege array) verplicht']);
        exit;
    }
    if (count($lics) > 1000) {
        http_response_code(400);
        echo json_encode(['error' => 'te veel rijders in één keer (max 1000)']);
        exit;
    }
    try {
        // Guard: welke van de gevraagde licenties zijn écht pending/extern?
        $ph  = implode(',', array_fill(0, count($lics), '?'));
        $chk = $pdo->prepare("
            SELECT license_key FROM persons
            WHERE license_key IN ($ph)
              AND (pending_source IS NOT NULL OR extern = 1)
        ");
        $chk->execute($lics);
        $teDoen       = array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'license_key');
        $overgeslagen = count($lics) - count($teDoen);
        if (!count($teDoen)) {
            echo json_encode(['ok' => true, 'personen_verwijderd' => 0, 'overgeslagen' => $overgeslagen]);
            exit;
        }

        $pdo->beginTransaction();
        $stmts = [
            'uitslagen'    => $pdo->prepare("DELETE FROM uitslag_afstand WHERE person_license = ?"),
            'entries'      => $pdo->prepare("DELETE FROM entries WHERE person_license = ?"),
            'heat_entries' => $pdo->prepare("DELETE FROM heat_entries WHERE person_license = ?"),
            'klassement'   => $pdo->prepare("DELETE FROM uitslag_klassement WHERE person_license = ?"),
            'startnrs'     => $pdo->prepare("DELETE FROM competition_startnummers WHERE person_license = ?"),
            'transponders' => $pdo->prepare("DELETE FROM transponders WHERE person_license = ?"),
        ];
        $delP = $pdo->prepare("
            DELETE FROM persons
            WHERE license_key = ?
              AND (pending_source IS NOT NULL OR extern = 1)
        ");
        $tot = ['uitslagen'=>0,'entries'=>0,'heat_entries'=>0,'klassement'=>0,'startnrs'=>0,'transponders'=>0,'personen'=>0];
        foreach ($teDoen as $lic) {
            foreach ($stmts as $key => $st) { $st->execute([$lic]); $tot[$key] += $st->rowCount(); }
            $delP->execute([$lic]);
            $tot['personen'] += $delP->rowCount();
        }
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'personen_verwijderd'     => $tot['personen'],
            'overgeslagen'            => $overgeslagen,
            'uitslagen_verwijderd'    => $tot['uitslagen'],
            'entries_verwijderd'      => $tot['entries'],
            'heat_entries_verwijderd' => $tot['heat_entries'],
            'klassement_verwijderd'   => $tot['klassement'],
            'startnrs_verwijderd'     => $tot['startnrs'],
            'transponders_verwijderd' => $tot['transponders'],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 4) Nieuwe wedstrijd aanmaken vanuit historie-tool ──────────────────────
// Minimale velden — operator hoeft geen org_id / venue te kiezen voor
// historische import-only wedstrijden (worden niet via KNSB-feed gevuld).
if ($action === 'historie_create_comp') {
    $naam   = trim($body['naam']   ?? '');
    $starts = trim($body['starts'] ?? '');          // YYYY-MM-DD
    $ends   = trim($body['ends']   ?? '');          // optioneel
    $venue  = trim($body['venue']  ?? '');          // optioneel
    $orgId  = trim($body['organisatie_id'] ?? ''); // optioneel — leeg = geen
    $baanId = trim($body['baan_id'] ?? '');         // optioneel — moet bij org horen
    if ($naam === '' || $starts === '') {
        http_response_code(400);
        echo json_encode(['error' => 'naam en startdatum zijn verplicht']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts)) {
        http_response_code(400);
        echo json_encode(['error' => 'startdatum moet YYYY-MM-DD zijn']);
        exit;
    }
    try {
        // Als organisatie meegegeven is: verifieer dat hij bestaat zodat
        // we geen rare FK-orphans schrijven. Lege string = NULL = geen org.
        if ($orgId !== '') {
            $chk = $pdo->prepare("SELECT 1 FROM organisaties WHERE id = ?");
            $chk->execute([$orgId]);
            if (!$chk->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'Organisatie niet gevonden']);
                exit;
            }
        }
        // Baan-validatie: alleen check dat de baan bestaat. CROSS-ORG IS OK:
        // voor historie-import komt het voor dat een wedstrijd op een baan
        // gereden is die fysiek bij een andere vereniging hoort dan de
        // organiserende org (bv. NK op een baan van een gastvereniging).
        // Het frontend toont alle banen met "eigen org eerst, andere
        // groepen daarna" zodat operator bewust kiest.
        if ($baanId !== '') {
            $chk = $pdo->prepare("SELECT 1 FROM banen WHERE id = ?");
            $chk->execute([$baanId]);
            if (!$chk->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'Baan niet gevonden']);
                exit;
            }
        }

        // Genereer een handmatige ID — kort + leesbaar + voorspelbaar
        // (bv. "hist-2024-nk-baan-a1b2c3"). UUID-versoepeling staat aan
        // dus alfanumeriek + dashes (max 36 chars) is OK.
        $jaar = substr($starts, 0, 4);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $naam));
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        $slug = substr($slug, 0, 18);
        $compId = "hist-{$jaar}-{$slug}-" . bin2hex(random_bytes(3));
        $compId = substr($compId, 0, 36);

        $startsDt = $starts . ' 09:00:00';
        $endsDt   = $ends !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends)
                    ? $ends . ' 18:00:00' : null;

        $stmt = $pdo->prepare("
            INSERT INTO competitions (id, name, starts, ends, venue_name, discipline,
                                       organisatie_id, baan_id,
                                       public_zichtbaar, public_aankondigen)
            VALUES (?, ?, ?, ?, ?, 'inline-skating', ?, ?, 0, 0)
        ");
        $stmt->execute([
            $compId, $naam, $startsDt, $endsDt,
            $venue !== '' ? $venue : null,
            $orgId !== ''  ? $orgId  : null,
            $baanId !== '' ? $baanId : null,
        ]);

        echo json_encode([
            'ok' => true,
            'competition_id' => $compId,
            'competition_naam' => $naam,
            'competition_datum' => $startsDt,
            'organisatie_id'   => $orgId !== ''  ? $orgId  : null,
            'baan_id'          => $baanId !== '' ? $baanId : null,
            'dcs' => [],
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 5) DCs aanmaken voor ontbrekende categorieën ───────────────────────────
// Frontend roept dit aan na AI-extract als de wedstrijd nog DCs mist voor
// detected categorieën. Bulk-insert + return de bijgewerkte DC-lijst.
if ($action === 'historie_create_dcs') {
    $compId = trim($body['competition_id'] ?? '');
    // Twee input-formaten geaccepteerd:
    //   - cats: ['DSA','HSA','DJA']           → één DC per cat (apart)
    //   - groepen: [['DSA','DSJ'], ['HSA']]   → een DC per groep (combo voor
    //     cats die SAMEN raceden, zoals DSA+DSJ in kleinere wedstrijden)
    // Back-compat: als alleen 'cats' is gegeven, behandelen we elk als een
    // 1-element groep.
    $groepen = $body['groepen'] ?? null;
    if (!is_array($groepen)) {
        $cats = $body['cats'] ?? [];
        if (!is_array($cats) || !count($cats)) {
            http_response_code(400);
            echo json_encode(['error' => 'competition_id + cats[] of groepen[][] verplicht']);
            exit;
        }
        $groepen = array_map(fn($c) => [$c], $cats);
    }
    if ($compId === '' || !count($groepen)) {
        http_response_code(400);
        echo json_encode(['error' => 'competition_id + cats[] of groepen[][] verplicht']);
        exit;
    }
    try {
        // Verifieer wedstrijd
        $cStmt = $pdo->prepare("SELECT id FROM competitions WHERE id = ?");
        $cStmt->execute([$compId]);
        if (!$cStmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Wedstrijd niet gevonden']);
            exit;
        }

        // Hoogste bestaande nummer ophalen voor consistent doortellen
        $maxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(number), 0) FROM distance_combinations WHERE competition_id = ?"
        );
        $maxStmt->execute([$compId]);
        $nextNumber = (int)$maxStmt->fetchColumn() + 1;

        // Voorkom duplicaten: een groep wordt geskipt als EXACT die
        // category_filter combinatie al bestaat (sorted comparison zodat
        // 'DSA,DSJ' en 'DSJ,DSA' als gelijk tellen). Per-cat-uniqueness
        // is BEWUST losgelaten: NK 2022 had bv. zowel een
        // 'DJA+DSA+DSJ'-DC ALS een 'DJA'-DC tegelijk (DJA-rijders kwamen
        // in beide klassementen voor — dubbele kampioen mogelijk).
        $bestStmt = $pdo->prepare(
            "SELECT category_filter FROM distance_combinations WHERE competition_id = ?"
        );
        $bestStmt->execute([$compId]);
        $bestaandeCombos = [];   // Set van sorted-cf-strings
        foreach ($bestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cf = trim((string)$row['category_filter']);
            if ($cf === '') continue;
            $cats = array_filter(array_map('trim', explode(',', $cf)));
            sort($cats);
            $bestaandeCombos[implode(',', $cats)] = true;
        }

        // Optionele namen-array: namen[i] overschrijft de auto-gegenereerde
        // naam voor groep i. Gebruikt door 'Handmatig DC...'-modal waar
        // operator zelf een naam wil opgeven (bv. "DJA + DSA combo 200m").
        // Voor reguliere auto-flows (knoppen 'apart'/'samen'/'aangepast') is
        // dit gewoon null en valt 't door op _catNaarDcNaam.
        $namen = $body['namen'] ?? null;
        if (!is_array($namen)) $namen = [];

        $ins = $pdo->prepare("
            INSERT INTO distance_combinations (id, competition_id, number, name, category_filter)
            VALUES (?, ?, ?, ?, ?)
        ");
        $pdo->beginTransaction();
        $aangemaakt = [];
        foreach ($groepen as $idx => $groep) {
            if (!is_array($groep)) continue;
            // Schoonmaken + dedup binnen groep
            $cleaned = [];
            foreach ($groep as $c) {
                $c = trim((string)$c);
                if ($c !== '' && !in_array($c, $cleaned, true)) $cleaned[] = $c;
            }
            if (!count($cleaned)) continue;
            // Skip als EXACT dezelfde combo (gesorteerd) al bestaat
            $sorted = $cleaned; sort($sorted);
            $comboKey = implode(',', $sorted);
            if (isset($bestaandeCombos[$comboKey])) continue;

            $catFilter = implode(',', $cleaned);
            // Naam: operator-input wint, anders auto via _catNaarDcNaam (1 cat)
            // of 'cat1 + cat2 + …' (meerdere cats).
            $eigenNaam = isset($namen[$idx]) ? trim((string)$namen[$idx]) : '';
            $dcNaam = $eigenNaam !== ''
                ? $eigenNaam
                : (count($cleaned) === 1
                    ? _catNaarDcNaam($cleaned[0])
                    : implode(' + ', $cleaned));     // bv. "DSA + DSJ"
            $slug   = strtolower(str_replace(',', '_', $catFilter));
            $dcId   = "hist-dc-" . $slug . "-" . bin2hex(random_bytes(4));
            $dcId   = substr($dcId, 0, 36);
            $ins->execute([$dcId, $compId, $nextNumber++, $dcNaam, $catFilter]);
            $aangemaakt[] = ['dc_id' => $dcId, 'dc_naam' => $dcNaam, 'cat' => $catFilter];
            // Markeer als nu-bestaand voor volgende loops
            $bestaandeCombos[$comboKey] = true;   // track combo voor volgende iteratie
        }
        $pdo->commit();
        echo json_encode(['ok' => true, 'aangemaakt' => $aangemaakt]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Helpers gebruikt door historie_extract ──────────────────────────────────

// Cat-code → leesbare DC-naam. Wordt gebruikt bij auto-create van DCs.
// Komt 1-op-1 overeen met de cat-mapping in de Claude-prompt.
function _catNaarDcNaam($cat) {
    static $map = [
        'DP4' => 'Dames Pupillen 4',       'HP4' => 'Heren Pupillen 4',
        'DP3' => 'Dames Pupillen 3',       'HP3' => 'Heren Pupillen 3',
        'DP2' => 'Dames Pupillen 2',       'HP2' => 'Heren Pupillen 2',
        'DP1' => 'Dames Pupillen 1',       'HP1' => 'Heren Pupillen 1',
        'DKA' => 'Dames Kadetten',         'HKA' => 'Heren Kadetten',
        'DJB' => 'Dames Junioren B',       'HJB' => 'Heren Junioren B',
        'DJA' => 'Dames Junioren A',       'HJA' => 'Heren Junioren A',
        // DSJ/HSJ = Senioren-Jongeren = sub-klassement binnen senior voor
        // 1e/2e jaars (~19-20 jaar). NL-only, vroeger gebruikt, afgeschaft.
        'DSJ' => 'Dames Senioren Jongeren','HSJ' => 'Heren Senioren Jongeren',
        'DSA' => 'Dames Senioren A',       'HSA' => 'Heren Senioren A',
    ];
    return $map[$cat] ?? $cat;
}

// Cat + seizoen → verwacht geboortejaar-bereik [van, tot] (inclusief).
// KNSB-indeling: elke jeugd-cat omvat 2 geboortejaren. Senioren = 19+.
// Voorbeeld: voor seizoen 2022 in cat DJB → geboortejaar 2006-2007.
function _catNaarJaarBereik($cat, $seizoen) {
    if (!$cat || !$seizoen) return null;
    // Strip H/D-prefix — leeftijdsregel is gender-onafhankelijk
    $sub = preg_replace('/^[HD]/', '', strtoupper($cat));
    static $offsets = [
        // sub → [oudste-leeftijd, jongste-leeftijd] in dat seizoen
        'P4' => [ 5,  6],     // born seizoen-6..seizoen-5
        'P3' => [ 7,  8],
        'P2' => [ 9, 10],
        'P1' => [11, 12],
        'KA' => [13, 14],
        'JB' => [15, 16],
        'JA' => [17, 18],
        // SJ = Senioren-Jongeren (1e/2e jaars senior, KNSB-theoretisch 19-20).
        // MAAR: in combo-PDF's (HSA+HSJ samen in één DC) wordt het label
        // 'HSJ' vaak voor ÁLLE senior-deelnemers in die uitslag gebruikt,
        // ook 22-jarigen die strikt HSA zijn. Daarom hier 19+ behandelen als
        // SA — anders mis je cross-jaar matches (Jenning de Boo HSJ-2023 +
        // HSJ-2025 = zelfde persoon, maar strikt 19-20-bereik [2003-04] vs
        // [2005-06] geeft geen overlap → split pending). Voor user-display
        // blijft 'HSJ' wel HSJ in persons.category, dit raakt alleen de
        // leeftijds-plausibiliteit voor pending-dedupe en KNSB-matching.
        'SJ' => [19, 130],
        'SA' => [19, 130],    // 19+ tot praktisch oneindig
    ];
    if (!isset($offsets[$sub])) return null;
    [$jong, $oud] = $offsets[$sub];
    return [$seizoen - $oud, $seizoen - $jong];
}

// Club-string normaliseren voor matching tussen PDF en persons.club_short.
// Lowercase, alleen letters/cijfers (geen spaties, leestekens, jaartallen).
// "DOST 1925" → "dost1925", "Skeelerclub Heerde" → "skeelerclubheerde"
function _clubNormalize($club) {
    $c = mb_strtolower(trim((string)$club), 'UTF-8');
    $c = preg_replace('/[^a-z0-9]/u', '', $c);
    return $c;
}

// Geboortejaar-bereik van een persoon-rij afleiden. Volgorde van voorkeur:
//   1) Expliciete persons.birth_year (1 jaar, range [by, by])
//   2) Afgeleid uit persons.category + HUIDIG jaar (date('Y') — verschuift
//      automatisch elk seizoen, zodat de check ook in 2027 nog klopt)
//   3) null = onbekend (caller behoudt persoon als kandidaat)
//
// Belangrijk: de KNSB-feed levert vaak GEEN birth_year, maar wél een
// category. Door uit de huidige cat een bereik af te leiden kunnen we toch
// kandidaten uitsluiten die qua leeftijd onmogelijk in de PDF-cat zaten.
function _persoonNaarJaarBereik($persoon, $huidigJaar) {
    if (!empty($persoon['birth_year'])) {
        $by = (int)$persoon['birth_year'];
        return [$by, $by];
    }
    if (!empty($persoon['category'])) {
        return _catNaarJaarBereik($persoon['category'], $huidigJaar);
    }
    return null;
}

// Twee gesloten intervallen overlappen iff a.van ≤ b.totMet EN b.van ≤ a.totMet.
// Onbekend bereik (null) = behandeld als "kan overlappen" = true (geen
// kandidaat uitsluiten op basis van ontbrekende info).
function _bereikenOverlappen($a, $b) {
    if (!$a || !$b) return true;
    return $a[0] <= $b[1] && $b[0] <= $a[1];
}

function _naamNormalize($naam) {
    $n = mb_strtolower(trim((string)$naam), 'UTF-8');
    $n = preg_replace('/\s+/u', ' ', $n);
    // Verwijder leestekens behalve apostrof en streepje (voor "'t Hooft", "Huis-in")
    $n = preg_replace("/[^\p{L}\p{N}\s'\-]/u", '', $n);
    return $n;
}
function _tijdNaarMs($tijd) {
    if (!$tijd) return null;
    $s = str_replace(',', '.', trim((string)$tijd));
    if ($s === '') return null;
    if (!preg_match('/^(?:(\d+):)?(\d+)(?:\.(\d+))?$/', $s, $m)) return null;
    $min = (int)($m[1] ?? 0);
    $sec = (int)$m[2];
    $frac = $m[3] ?? '';
    if (strlen($frac) === 1)      $ms = (int)$frac * 100;
    elseif (strlen($frac) === 2)  $ms = (int)$frac * 10;
    elseif (strlen($frac) === 3)  $ms = (int)$frac;
    elseif (strlen($frac) === 0)  $ms = 0;
    else                          $ms = (int)substr($frac, 0, 3);
    return ($min * 60 + $sec) * 1000 + $ms;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende action']);
