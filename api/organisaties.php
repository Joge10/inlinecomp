<?php
// ============================================================
//  InlineComp – Organisaties CRUD + aliassen + samenvoegen
//
//  GET               → alle organisaties (met sponsor_count, comp_count, aliassen)
//  GET ?id=xxx       → één organisatie met sponsors en aliassen
//  POST action=save           → aanmaken/bijwerken
//  POST action=delete         → verwijderen
//  POST action=delete_sponsor → sponsor verwijderen
//  POST action=alias_toevoegen  → alias toevoegen    { org_id, naam }
//  POST action=alias_verwijderen → alias verwijderen { id, org_id }
//  POST action=samenvoegen    → merge van_id → naar_id (van verdwijnt)
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !kanSchrijven($_authUser, 'beheer')) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen schrijfrechten voor beheer.']);
    exit;
}

function newUuid(): string {
    $b    = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function fetchOrg(PDO $pdo, string $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM organisaties WHERE id = ?");
    $stmt->execute([$id]);
    $org = $stmt->fetch();
    if (!$org) return null;

    $stmt = $pdo->prepare(
        "SELECT * FROM organisatie_sponsors WHERE organisatie_id = ? ORDER BY volgorde, naam"
    );
    $stmt->execute([$id]);
    $org['sponsors'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT id, naam FROM organisatie_aliassen WHERE organisatie_id = ? ORDER BY naam"
    );
    $stmt->execute([$id]);
    $org['aliassen'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT * FROM organisatie_transponders WHERE organisatie_id = ? ORDER BY CAST(intern_nummer AS UNSIGNED), intern_nummer"
    );
    $stmt->execute([$id]);
    $org['transponders'] = $stmt->fetchAll();

    return $org;
}

try {
    // ── GET ──────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Multi-tenant: scope-helper voor scoped admins.
        $orgScope = gebruikerOrgScope($pdo, $_authUser);

        // Wedstrijden van een org ophalen
        if (!empty($_GET['action']) && $_GET['action'] === 'wedstrijden' && !empty($_GET['id'])) {
            // Scoped admin mag alleen wedstrijden van zijn eigen orgs zien.
            if ($orgScope !== null && !in_array($_GET['id'], $orgScope, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                exit;
            }
            $stmt = $pdo->prepare(
                "SELECT id, name, starts, ends, venue_city, venue_name, imported_at,
                        public_zichtbaar, public_aankondigen,
                        (jury_password IS NOT NULL) AS jury_password_set
                 FROM competitions WHERE organisatie_id = ?
                 ORDER BY starts DESC"
            );
            $stmt->execute([$_GET['id']]);
            echo json_encode($stmt->fetchAll());
            exit;
        }
        if (!empty($_GET['id'])) {
            // Scoped admin: alleen eigen orgs.
            if ($orgScope !== null && !in_array($_GET['id'], $orgScope, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                exit;
            }
            echo json_encode(fetchOrg($pdo, $_GET['id']));
        } else {
            // Multi-tenant: scoped admin ziet alleen eigen orgs.
            if ($orgScope === null) {
                $stmt = $pdo->query("
                    SELECT o.*,
                        (SELECT COUNT(*) FROM organisatie_sponsors s
                         WHERE s.organisatie_id = o.id) AS sponsor_count,
                        (SELECT COUNT(*) FROM competitions c
                         WHERE c.organisatie_id = o.id) AS comp_count
                    FROM organisaties o
                    ORDER BY o.naam
                ");
            } else {
                $ph = implode(',', array_fill(0, count($orgScope), '?'));
                $stmt = $pdo->prepare("
                    SELECT o.*,
                        (SELECT COUNT(*) FROM organisatie_sponsors s
                         WHERE s.organisatie_id = o.id) AS sponsor_count,
                        (SELECT COUNT(*) FROM competitions c
                         WHERE c.organisatie_id = o.id) AS comp_count
                    FROM organisaties o
                    WHERE o.id IN ($ph)
                    ORDER BY o.naam
                ");
                $stmt->execute($orgScope);
            }
            $orgs = $stmt->fetchAll();

            // Aliassen per org ophalen
            $aliasStmt = $pdo->query(
                "SELECT organisatie_id, naam FROM organisatie_aliassen ORDER BY naam"
            );
            $aliasMap = [];
            foreach ($aliasStmt->fetchAll() as $a) {
                $aliasMap[$a['organisatie_id']][] = $a['naam'];
            }
            foreach ($orgs as &$o) {
                $o['aliassen'] = $aliasMap[$o['id']] ?? [];
            }
            unset($o);

            echo json_encode($orgs);
        }
        exit;
    }

    // ── POST ─────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? 'save';

        // ── Sponsor verwijderen ──
        if ($action === 'delete_sponsor') {
            $spnId = $body['id'] ?? '';
            // Scope-check: scoped admin mag alleen sponsors van orgs in scope wissen.
            $scopeSpn = gebruikerOrgScope($pdo, $_authUser);
            if ($scopeSpn !== null) {
                $orgStmt = $pdo->prepare(
                    "SELECT organisatie_id FROM organisatie_sponsors WHERE id = ?"
                );
                $orgStmt->execute([$spnId]);
                $spnOrg = $orgStmt->fetchColumn();
                if ($spnOrg && !in_array($spnOrg, $scopeSpn, true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                    exit;
                }
            }
            $pdo->prepare("DELETE FROM organisatie_sponsors WHERE id = ?")
                ->execute([$spnId]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Organisatie verwijderen ──
        if ($action === 'delete') {
            $delId = $body['id'] ?? '';
            // Scope-check: scoped admin mag alleen orgs in eigen scope wissen.
            $scopeDel = gebruikerOrgScope($pdo, $_authUser);
            if ($scopeDel !== null && !in_array($delId, $scopeDel, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                exit;
            }
            $pdo->prepare("DELETE FROM organisaties WHERE id = ?")
                ->execute([$delId]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Alias toevoegen ──
        if ($action === 'alias_toevoegen') {
            $orgId = $body['org_id'] ?? '';
            $naam  = trim($body['naam'] ?? '');
            if (!$orgId || !$naam) {
                http_response_code(400);
                echo json_encode(['error' => 'org_id en naam zijn verplicht']);
                exit;
            }
            // Scope-check: scoped admin mag alleen aliassen aan orgs in scope toevoegen.
            $scopeAlias = gebruikerOrgScope($pdo, $_authUser);
            if ($scopeAlias !== null && !in_array($orgId, $scopeAlias, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                exit;
            }
            try {
                $pdo->prepare(
                    "INSERT INTO organisatie_aliassen (id, organisatie_id, naam) VALUES (?,?,?)"
                )->execute([newUuid(), $orgId, $naam]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    http_response_code(409);
                    echo json_encode(['error' => "Naam \"{$naam}\" bestaat al als alias of organisatienaam"]);
                    exit;
                }
                throw $e;
            }
            echo json_encode(fetchOrg($pdo, $orgId));
            exit;
        }

        // ── Alias verwijderen ──
        if ($action === 'alias_verwijderen') {
            $aliasId = $body['id']     ?? '';
            $orgId   = $body['org_id'] ?? '';
            if (!$aliasId) {
                http_response_code(400);
                echo json_encode(['error' => 'id is verplicht']);
                exit;
            }
            // Scope-check: alias verwijderen alleen voor orgs in scope.
            $scopeAlias = gebruikerOrgScope($pdo, $_authUser);
            if ($scopeAlias !== null) {
                $ownerStmt = $pdo->prepare(
                    "SELECT organisatie_id FROM organisatie_aliassen WHERE id = ?"
                );
                $ownerStmt->execute([$aliasId]);
                $aliasOrg = $ownerStmt->fetchColumn();
                if ($aliasOrg && !in_array($aliasOrg, $scopeAlias, true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                    exit;
                }
            }
            $pdo->prepare("DELETE FROM organisatie_aliassen WHERE id = ?")
                ->execute([$aliasId]);
            echo json_encode($orgId ? fetchOrg($pdo, $orgId) : ['ok' => true]);
            exit;
        }

        // ── Samenvoegen: van_id verdwijnt, naar_id blijft ──
        if ($action === 'samenvoegen') {
            $vanId  = $body['van_id']  ?? '';   // verdwijnt
            $naarId = $body['naar_id'] ?? '';   // blijft
            if (!$vanId || !$naarId || $vanId === $naarId) {
                http_response_code(400);
                echo json_encode(['error' => 'Ongeldige organisatie-ids']);
                exit;
            }
            // Scope-check: scoped admin mag alleen samenvoegen tussen orgs
            // die BEIDE in zijn scope zitten. Voorkomt cascade-effecten naar
            // wedstrijden van orgs waar hij niets mee te maken heeft.
            $scopeMerge = gebruikerOrgScope($pdo, $_authUser);
            if ($scopeMerge !== null) {
                if (!in_array($vanId, $scopeMerge, true) ||
                    !in_array($naarId, $scopeMerge, true)) {
                    http_response_code(403);
                    echo json_encode([
                        'error' => 'Beide organisaties moeten in jouw scope zitten om te kunnen samenvoegen',
                    ]);
                    exit;
                }
            }

            $pdo->beginTransaction();
            try {
                // Naam van "van" ophalen
                $vanNaam = $pdo->prepare("SELECT naam FROM organisaties WHERE id = ?");
                $vanNaam->execute([$vanId]);
                $vanNaamStr = $vanNaam->fetchColumn();

                $naarNaam = $pdo->prepare("SELECT naam FROM organisaties WHERE id = ?");
                $naarNaam->execute([$naarId]);
                $naarNaamStr = $naarNaam->fetchColumn();

                // Voeg naam van "van" als alias toe aan "naar" (als hij afwijkt)
                if ($vanNaamStr && $vanNaamStr !== $naarNaamStr) {
                    $bestaatAl = $pdo->prepare(
                        "SELECT 1 FROM organisatie_aliassen WHERE naam = ?"
                    );
                    $bestaatAl->execute([$vanNaamStr]);
                    if ($bestaatAl->fetchColumn()) {
                        // Alias bestaat al ergens → herwijzen naar naar_id
                        $pdo->prepare(
                            "UPDATE organisatie_aliassen SET organisatie_id = ? WHERE naam = ?"
                        )->execute([$naarId, $vanNaamStr]);
                    } else {
                        $pdo->prepare(
                            "INSERT INTO organisatie_aliassen (id, organisatie_id, naam) VALUES (?,?,?)"
                        )->execute([newUuid(), $naarId, $vanNaamStr]);
                    }
                }

                // Alle aliassen van "van" overzetten naar "naar"
                $pdo->prepare(
                    "UPDATE organisatie_aliassen SET organisatie_id = ? WHERE organisatie_id = ?"
                )->execute([$naarId, $vanId]);

                // Wedstrijden van "van" verplaatsen naar "naar"
                $pdo->prepare(
                    "UPDATE competitions SET organisatie_id = ? WHERE organisatie_id = ?"
                )->execute([$naarId, $vanId]);

                // Sponsors van "van" verplaatsen naar "naar"
                $pdo->prepare(
                    "UPDATE organisatie_sponsors SET organisatie_id = ? WHERE organisatie_id = ?"
                )->execute([$naarId, $vanId]);

                // "Van" verwijderen
                $pdo->prepare("DELETE FROM organisaties WHERE id = ?")
                    ->execute([$vanId]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(fetchOrg($pdo, $naarId));
            exit;
        }

        // ── Transponders apart opslaan ──
        if ($action === 'save_transponders') {
            $orgId = trim($body['organisatie_id'] ?? '');
            if (!$orgId) {
                http_response_code(400);
                echo json_encode(['error' => 'organisatie_id is verplicht']);
                exit;
            }
            // Scope-check: alleen transponders van orgs in scope wijzigen.
            $scopeTp = gebruikerOrgScope($pdo, $_authUser);
            if ($scopeTp !== null && !in_array($orgId, $scopeTp, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                exit;
            }
            $transponders = $body['transponders'] ?? [];

            // Bepaal welke codes verdwijnen → verwijder uit wedstrijd-transponders
            $oudeStmt = $pdo->prepare("SELECT transponder_code FROM organisatie_transponders WHERE organisatie_id = ?");
            $oudeStmt->execute([$orgId]);
            $oudeCodes = array_column($oudeStmt->fetchAll(PDO::FETCH_ASSOC), 'transponder_code');
            $nieuweCodes = array_filter(array_map(fn($t) => trim($t['transponder_code'] ?? ''), $transponders));
            $verwijderd = array_diff($oudeCodes, $nieuweCodes);

            if ($verwijderd) {
                // Verwijder uit transponders tabel (slot 0 + slot ≥3) voor alle wedstrijden van deze org
                $vPh = implode(',', array_fill(0, count($verwijderd), '?'));
                $pdo->prepare("
                    DELETE t FROM transponders t
                    JOIN competitions c ON c.id = t.competition_id
                    WHERE c.organisatie_id = ?
                      AND t.code IN ($vPh)
                      AND t.slot IN (0, 3, 4, 5, 6, 7, 8, 9)
                ")->execute(array_merge([$orgId], array_values($verwijderd)));
            }

            $pdo->prepare("DELETE FROM organisatie_transponders WHERE organisatie_id = ?")->execute([$orgId]);
            $insTp = $pdo->prepare("
                INSERT INTO organisatie_transponders
                    (organisatie_id, intern_nummer, transponder_code, eigendom,
                     toegewezen_snr, toegewezen_naam, person_license, categorie,
                     betaald, betaald_op, geblokkeerd)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($transponders as $t) {
                $nr   = trim($t['intern_nummer'] ?? '');
                $code = trim($t['transponder_code'] ?? '');
                if (!$nr || !$code) continue;
                $snr       = !empty($t['toegewezen_snr']) ? (int)$t['toegewezen_snr'] : null;
                // Niet-uitgegeven transponders kunnen niet betaald zijn — forceer 0.
                $betaald   = ($snr && ((int)($t['betaald'] ?? 0)) === 1) ? 1 : 0;
                $betaaldOp = $betaald ? ((!empty($t['betaald_op']) && $t['betaald_op'] !== '—') ? $t['betaald_op'] : date('Y-m-d')) : null;
                $geblokk   = ((int)($t['geblokkeerd'] ?? 0)) === 1 ? 1 : 0;
                $insTp->execute([
                    $orgId, $nr, $code,
                    trim($t['eigendom'] ?? '') ?: null,
                    $snr,
                    trim($t['toegewezen_naam'] ?? '') ?: null,
                    trim($t['person_license'] ?? '') ?: null,
                    trim($t['categorie'] ?? '') ?: null,
                    $betaald,
                    $betaaldOp,
                    $geblokk,
                ]);
            }
            // Cleanup: transponders die niet meer zijn toegewezen → verwijder uit wedstrijden
            $ontkoppeldStmt = $pdo->prepare("
                SELECT transponder_code FROM organisatie_transponders
                WHERE organisatie_id = ? AND toegewezen_snr IS NULL
            ");
            $ontkoppeldStmt->execute([$orgId]);
            $ontkoppeldCodes = array_column($ontkoppeldStmt->fetchAll(PDO::FETCH_ASSOC), 'transponder_code');
            if ($ontkoppeldCodes) {
                $oPh = implode(',', array_fill(0, count($ontkoppeldCodes), '?'));
                $pdo->prepare("
                    DELETE t FROM transponders t
                    JOIN competitions c ON c.id = t.competition_id
                    WHERE c.organisatie_id = ?
                      AND t.code IN ($oPh)
                      AND t.slot IN (0, 3, 4, 5, 6, 7, 8, 9)
                ")->execute(array_merge([$orgId], $ontkoppeldCodes));
            }

            echo json_encode(['ok' => true, 'transponders' => fetchOrg($pdo, $orgId)['transponders'] ?? []]);
            exit;
        }

        // ── Opslaan (aanmaken of bijwerken) ──
        if ($action === 'save') {
            $id       = !empty($body['id']) ? $body['id'] : null;
            $naam     = trim($body['naam']  ?? '');
            $email    = trim($body['email'] ?? '') ?: null;
            $sportity = trim($body['sportity_kanaal'] ?? '') ?: null;

            if (!$naam) {
                http_response_code(400);
                echo json_encode(['error' => 'Naam vereist']);
                exit;
            }

            // Multi-tenant scope-checks.
            $eigenScopeSave = gebruikerOrgScope($pdo, $_authUser);

            if ($id) {
                // Bewerken: scoped admin mag alleen orgs in eigen scope.
                if ($eigenScopeSave !== null && !in_array($id, $eigenScopeSave, true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Organisatie buiten jouw scope']);
                    exit;
                }
                $pdo->prepare(
                    "UPDATE organisaties SET naam = ?, email = ?, sportity_kanaal = ?, updated_at = NOW() WHERE id = ?"
                )->execute([$naam, $email, $sportity, $id]);
            } else {
                // Aanmaken: duplicaat-check (anti-back-door) op naam + alias.
                // Email is bewust NIET in de check — meerdere orgs kunnen
                // dezelfde Vantage-beheerder-email hebben en dat is legitiem.
                // Het volstaat om naam/alias uniek te houden: een nieuwe org
                // met dezelfde email is een NIEUWE rij, dus geen toegang tot
                // de bestaande org buiten scope.
                $dupStmt = $pdo->prepare("
                    SELECT id, naam, 'naam' AS bron FROM organisaties
                    WHERE LOWER(TRIM(naam)) = LOWER(TRIM(?))
                    UNION
                    SELECT organisatie_id AS id, naam, 'alias' AS bron
                    FROM organisatie_aliassen
                    WHERE LOWER(TRIM(naam)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $dupStmt->execute([$naam, $naam]);
                $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    http_response_code(409);
                    $melding = $dup['bron'] === 'alias'
                        ? "Naam '{$naam}' is al een alias van een bestaande organisatie. Vraag de owner om jouw account aan die organisatie te koppelen."
                        : "Organisatie '{$naam}' bestaat al. Vraag de owner om jouw account aan die organisatie te koppelen.";
                    echo json_encode(['error' => $melding]);
                    exit;
                }
                $id = newUuid();
                $pdo->prepare(
                    "INSERT INTO organisaties (id, naam, email, sportity_kanaal) VALUES (?, ?, ?, ?)"
                )->execute([$id, $naam, $email, $sportity]);
                // Scoped admin: voeg de nieuwe org automatisch toe aan zijn
                // junction-rijen zodat hij 'm meteen ziet in z'n lijst.
                if ($eigenScopeSave !== null) {
                    $pdo->prepare(
                        "INSERT INTO user_organisaties (user_id, organisatie_id, toegevoegd_door)
                         VALUES (?, ?, ?)"
                    )->execute([(int)$_authUser['id'], $id, (int)$_authUser['id']]);
                }
            }

            // Sponsors opslaan
            foreach ($body['sponsors'] ?? [] as $i => $s) {
                $sId  = !empty($s['id']) ? $s['id'] : null;
                $sNam = trim($s['naam'] ?? '');
                if (!$sNam) continue;
                $sUrl = trim($s['url'] ?? '') ?: null;

                if ($sId) {
                    $pdo->prepare(
                        "UPDATE organisatie_sponsors SET naam = ?, url = ?, volgorde = ? WHERE id = ?"
                    )->execute([$sNam, $sUrl, $i, $sId]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO organisatie_sponsors (id, organisatie_id, naam, url, volgorde)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([newUuid(), $id, $sNam, $sUrl, $i]);
                }
            }

            echo json_encode(fetchOrg($pdo, $id));
            exit;
        }
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method niet toegestaan']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
