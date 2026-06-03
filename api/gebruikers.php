<?php
// ============================================================
//  InlineComp – gebruikersbeheer
//
//  GET                          → lijst (owner/admin)
//  POST action=eerste_owner     → eerste owner aanmaken (alleen als users-tabel leeg)
//  POST action=save             → aanmaken / bijwerken
//  POST action=set_password     → wachtwoord wijzigen
//  POST action=toggle_actief    → activeren / deactiveren
//  POST action=delete           → verwijderen
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

try {

    // ── Eerste-owner-bootstrap is uitgeschakeld ─────────────────────────────
    // Voorheen kon je via action=eerste_owner zonder auth een owner-account
    // aanmaken zolang de users-tabel leeg was. Risico: bij een per ongeluk
    // lege tabel (backup-restore, foute migratie, SQL-injectie elders) kon
    // de eerstvolgende bezoeker zonder verificatie owner worden.
    //
    // Nieuw account aanmaken zonder bestaande owner gaat nu alleen via
    // direct SQL-insert in phpMyAdmin (zie comment bovenaan login.php).
    if ($action === 'eerste_owner') {
        http_response_code(410);   // Gone — bewust verwijderd
        echo json_encode(['error' => 'Bootstrap is uitgeschakeld. Maak een account aan via de DB.']);
        exit;
    }

    // Alle overige acties vereisen inlog (owner of admin)
    $ik = requireAuth($pdo, ['owner', 'admin']);

    // Helper: scoped admin mag alleen users aanraken met overlap of zonder
    // scope. Stuurt 403 en eindigt indien niet toegestaan. Self-edit altijd OK.
    $scopeCheckOrExit = function(int $targetId) use ($pdo, $ik): void {
        if ($targetId === (int)$ik['id']) return;       // eigen account
        $scope = gebruikerOrgScope($pdo, $ik);
        if ($scope === null) return;                    // owner/unscoped admin
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $st = $pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM user_organisaties WHERE user_id = ?) AS n_total,
                    (SELECT COUNT(*) FROM user_organisaties WHERE user_id = ? AND organisatie_id IN ($ph)) AS n_overlap"
        );
        $st->execute(array_merge([$targetId, $targetId], $scope));
        $cnt = $st->fetch(PDO::FETCH_ASSOC);
        // n_total = 0 → target is "alle" (unscoped) → toegestaan.
        // n_total > 0 EN n_overlap = 0 → expliciet scoped buiten admin's scope → 403.
        if ((int)$cnt['n_total'] > 0 && (int)$cnt['n_overlap'] === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Geen overlap met deze gebruiker — buiten jouw scope']);
            exit;
        }
    };

    // ── GET: lijst ──────────────────────────────────────────────────────────
    if ($method === 'GET') {
        // Optionele sub-action via ?action=orgs_list → retourneert alle
        // organisaties die de huidige user mag tellen. Owner ziet alle;
        // scoped admin ziet alleen zijn eigen scope-orgs.
        if (($_GET['action'] ?? '') === 'orgs_list') {
            $scope = gebruikerOrgScope($pdo, $ik);
            if ($scope === null) {
                $orgs = $pdo->query(
                    "SELECT id, naam FROM organisaties ORDER BY naam"
                )->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $ph = implode(',', array_fill(0, count($scope), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id, naam FROM organisaties WHERE id IN ($ph) ORDER BY naam"
                );
                $stmt->execute($scope);
                $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($orgs, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Standaard: users-lijst + per user de gekoppelde org-ids (array).
        // Scoped admin: alleen users met ≥1 overlap zien, of users zonder
        // scope ("alle"). Owners blijven altijd zichtbaar (read-only context).
        $eigenScope = gebruikerOrgScope($pdo, $ik);
        if ($eigenScope === null) {
            $rows = $pdo->query(
                "SELECT id, username, naam, email, role, actief, created_at, updated_at
                 FROM users ORDER BY role, naam"
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $ph = implode(',', array_fill(0, count($eigenScope), '?'));
            $stmt = $pdo->prepare(
                "SELECT u.id, u.username, u.naam, u.email, u.role, u.actief, u.created_at, u.updated_at
                 FROM   users u
                 WHERE  u.role = 'owner'
                    OR  NOT EXISTS (
                            SELECT 1 FROM user_organisaties uo
                            WHERE  uo.user_id = u.id
                        )
                    OR  EXISTS (
                            SELECT 1 FROM user_organisaties uo
                            WHERE  uo.user_id = u.id AND uo.organisatie_id IN ($ph)
                        )
                 ORDER BY u.role, u.naam"
            );
            $stmt->execute($eigenScope);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Org-koppelingen in één query ipv N+1.
        $orgKopStmt = $pdo->query(
            "SELECT user_id, organisatie_id FROM user_organisaties"
        );
        $orgPerUser = [];
        foreach ($orgKopStmt->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $orgPerUser[(int)$k['user_id']][] = $k['organisatie_id'];
        }
        foreach ($rows as &$r) {
            $r['organisatie_ids'] = $orgPerUser[(int)$r['id']] ?? [];
        }
        unset($r);

        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST: acties ─────────────────────────────────────────────────────────
    if ($method === 'POST') {

        // Opslaan (nieuw of bijwerken)
        if ($action === 'save') {
            $id       = isset($body['id']) ? (int)$body['id'] : 0;
            $username = trim($body['username'] ?? '');
            $naam     = trim($body['naam']     ?? '');
            $email    = trim($body['email']    ?? '') ?: null;
            $role     = $body['role']          ?? 'viewer';
            $password = $body['password']      ?? '';

            $geldigeRollen = ['owner','admin','importer','planner','timer','viewer'];
            if (!$username || !$naam || !in_array($role, $geldigeRollen, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Ongeldige invoer']);
                exit;
            }

            // Admin mag geen owner aanmaken of andere admin bijwerken
            if ($ik['role'] === 'admin') {
                if ($role === 'owner') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Admin mag geen owner aanmaken']);
                    exit;
                }
                if ($id) {
                    $bestaand = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $bestaand->execute([$id]);
                    $bestaandRole = $bestaand->fetchColumn();
                    if (in_array($bestaandRole, ['owner','admin'], true) && $id !== (int)$ik['id']) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Admin mag owner/admin niet wijzigen']);
                        exit;
                    }
                }
            }

            // Org-koppelingen uit de body (array UUIDs). Owner mag alles
            // toewijzen; scoped admin alleen binnen z'n eigen scope.
            $orgIds = is_array($body['organisatie_ids'] ?? null)
                    ? array_values(array_filter($body['organisatie_ids'], 'is_string'))
                    : null;
            $eigenScope = gebruikerOrgScope($pdo, $ik);

            // Server-side guard: scope-check via helper.
            if ($id !== 0) $scopeCheckOrExit($id);

            if ($orgIds !== null && !empty($orgIds)) {
                // Valideer dat alle opgegeven UUIDs bestaan (geldt voor zowel
                // owner als scoped admin — laatste mag alleen z'n eigen scope
                // sturen maar de validatie filtert dat sowieso af in de merge).
                $ph = implode(',', array_fill(0, count($orgIds), '?'));
                $cv = $pdo->prepare("SELECT id FROM organisaties WHERE id IN ($ph)");
                $cv->execute($orgIds);
                $bestaande = $cv->fetchAll(PDO::FETCH_COLUMN);
                if (count($bestaande) !== count($orgIds)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Eén of meer organisaties bestaan niet']);
                    exit;
                }
            }

            if ($eigenScope !== null) {
                // Scoped admin. Twee gevallen:
                if ($id === 0) {
                    // Nieuwe gebruiker → auto-inherit eigen scope volledig.
                    // Bewuste keuze: scoped admin kan niet voor anderen een
                    // bredere scope geven dan hij zelf heeft, en niet smaller
                    // (anders is de nieuwe user geïsoleerd binnen admin's silo).
                    $orgIds = $eigenScope;
                } else {
                    // Bestaande gebruiker → merge. Bewaar orgs die NIET in
                    // admin's scope vallen (admin mag die niet aanraken);
                    // overschrijf orgs die WEL in admin's scope vallen op
                    // basis van admin's selectie.
                    $huidig = $pdo->prepare("SELECT organisatie_id FROM user_organisaties WHERE user_id = ?");
                    $huidig->execute([$id]);
                    $huidigeOrgs = $huidig->fetchAll(PDO::FETCH_COLUMN);

                    $targetIsAlle = empty($huidigeOrgs);
                    if ($targetIsAlle) {
                        // Target had geen scope ("alle"). Als admin alles uit
                        // zijn checkboxes laat staan → niks doet → blijft "alle".
                        // Als admin een org UITvinkt → transitie naar expliciet:
                        // target krijgt ALLE orgs minus de uitgevinkte (= "alle
                        // behalve admin's deselectie"). Dat is de enige manier
                        // om scope te beperken zonder admin's andere orgs te kennen.
                        $adminSelectie    = $orgIds !== null ? array_intersect($orgIds, $eigenScope) : $eigenScope;
                        $adminGedeselect  = array_diff($eigenScope, $adminSelectie);
                        if (empty($adminGedeselect)) {
                            // Niets veranderd → target blijft "alle" (geen rows).
                            $orgIds = null;   // signaal: niet syncen
                        } else {
                            $alleOrgs = $pdo->query("SELECT id FROM organisaties")->fetchAll(PDO::FETCH_COLUMN);
                            $orgIds = array_values(array_diff($alleOrgs, $adminGedeselect));
                        }
                    } else {
                        // Target had expliciete scope. Merge:
                        //   bewaard = huidige orgs BUITEN admin's scope
                        //   nieuw   = admin's selectie BINNEN admin's scope
                        $bewaard = array_values(array_diff($huidigeOrgs, $eigenScope));
                        $nieuw   = $orgIds !== null
                                 ? array_values(array_intersect($orgIds, $eigenScope))
                                 : array_values(array_intersect($huidigeOrgs, $eigenScope));
                        $orgIds  = array_values(array_unique(array_merge($bewaard, $nieuw)));
                    }
                }
            }

            $pdo->beginTransaction();
            try {
                if ($id === 0) {
                    // Nieuw
                    if (strlen($password) < 8) {
                        $pdo->rollBack();
                        http_response_code(400);
                        echo json_encode(['error' => 'Wachtwoord min. 8 tekens']);
                        exit;
                    }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (username, naam, email, role, password_hash)
                                   VALUES (?,?,?,?,?)")
                        ->execute([$username, $naam, $email, $role, $hash]);
                    $newId = (int)$pdo->lastInsertId();
                    $resp = ['ok' => true, 'id' => $newId];
                    $targetId = $newId;
                } else {
                    // Bijwerken
                    $pdo->prepare("UPDATE users SET username=?, naam=?, email=?, role=?, updated_at=NOW()
                                   WHERE id=?")
                        ->execute([$username, $naam, $email, $role, $id]);
                    $resp = ['ok' => true];
                    $targetId = $id;
                }

                // Org-koppelingen synchroniseren. Pattern: delete-all-then-insert.
                // - Owner kan elke wijziging doen (insert + delete vrij).
                // - Scoped admin geeft altijd $eigenScope mee, dus nieuwe
                //   users krijgen automatisch zijn scope; bestaande users
                //   die hij wijzigt krijgen ook zijn scope toegewezen
                //   (overschrijft eventuele bredere scope — bewuste keuze).
                if ($orgIds !== null) {
                    $pdo->prepare("DELETE FROM user_organisaties WHERE user_id = ?")
                        ->execute([$targetId]);
                    if (!empty($orgIds)) {
                        $ins = $pdo->prepare(
                            "INSERT INTO user_organisaties (user_id, organisatie_id, toegevoegd_door)
                             VALUES (?, ?, ?)"
                        );
                        foreach ($orgIds as $oid) {
                            $ins->execute([$targetId, $oid, (int)$ik['id']]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode($resp);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            exit;
        }

        // Wachtwoord wijzigen
        if ($action === 'set_password') {
            $id       = (int)($body['id']       ?? 0);
            $password = $body['password']        ?? '';
            if (!$id || strlen($password) < 8) {
                http_response_code(400);
                echo json_encode(['error' => 'id en wachtwoord (min. 8 tekens) verplicht']);
                exit;
            }
            // Admin mag wachtwoord owner/andere admin niet wijzigen
            if ($ik['role'] === 'admin' && $id !== (int)$ik['id']) {
                $r = $pdo->prepare("SELECT role FROM users WHERE id=?");
                $r->execute([$id]);
                if (in_array($r->fetchColumn(), ['owner','admin'], true)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Onvoldoende rechten']);
                    exit;
                }
            }
            // Scope-check via helper.
            $scopeCheckOrExit($id);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?")
                ->execute([$hash, $id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Activeren / deactiveren
        if ($action === 'toggle_actief') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id ontbreekt']); exit; }
            // Eigen account niet deactiveren; owner niet deactiveren
            $r = $pdo->prepare("SELECT role FROM users WHERE id=?");
            $r->execute([$id]);
            $targetRole = $r->fetchColumn();
            if ($id === (int)$ik['id'] || $targetRole === 'owner') {
                http_response_code(403);
                echo json_encode(['error' => 'Dit account kan niet worden gedeactiveerd']);
                exit;
            }
            if ($ik['role'] === 'admin' && in_array($targetRole, ['owner','admin'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Onvoldoende rechten']);
                exit;
            }
            $scopeCheckOrExit($id);
            $pdo->prepare("UPDATE users SET actief = 1 - actief, updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Verwijderen
        if ($action === 'delete') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id ontbreekt']); exit; }
            if ($id === (int)$ik['id']) {
                http_response_code(403);
                echo json_encode(['error' => 'Je kunt je eigen account niet verwijderen']);
                exit;
            }
            $r = $pdo->prepare("SELECT role FROM users WHERE id=?");
            $r->execute([$id]);
            $targetRole = $r->fetchColumn();
            if ($targetRole === 'owner') {
                http_response_code(403);
                echo json_encode(['error' => 'Owner-account kan niet worden verwijderd']);
                exit;
            }
            if ($ik['role'] === 'admin' && $targetRole === 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Admin mag andere admin niet verwijderen']);
                exit;
            }
            $scopeCheckOrExit($id);
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
