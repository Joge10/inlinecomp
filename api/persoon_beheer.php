<?php
// ============================================================
//  InlineComp – rijderbeheer (admin)
//
//  GET  action=zoek  &q=...  [&type=naam|snr|license]
//        → lijst van matches (license_key, full_name, short_name, club, ...)
//
//  GET  action=detail  &license_key=X
//        → volledig persons-record + transponders + wedstrijd-historie
//
//  Alleen owner/admin; dit endpoint toont andermans persoonsgegevens.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

if (!in_array($_authUser['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Alleen beheerders mogen rijdergegevens inzien.']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'zoek') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['rijders' => []]);
            exit;
        }

        // Eén gecombineerde zoekopdracht over alle velden:
        //   - start_number  (exacte match op getal, alleen bij cijfer-input)
        //   - license_key   (substring-match — vindt ook middendeel of
        //                    laatste-4-cijfers van een licentie; pas actief
        //                    bij ≥ 4 tekens, anders match je te veel ruis
        //                    omdat veel licenties beginnen met 102/104/…)
        //   - short_name    (bevat)
        //   - full_name     (bevat)
        $isNum      = ctype_digit($q);
        $likeLic    = '%' . $q . '%';
        $likeNaam   = '%' . $q . '%';
        $zoekLic    = strlen($q) >= 4 ? 1 : 0;
        $stmt  = $pdo->prepare("
            SELECT license_key, full_name, short_name, start_number,
                   category, club_short, club_full, anonymized_at
            FROM persons
            WHERE (? = 1 AND start_number = ?)
               OR (? = 1 AND license_key LIKE ?)
               OR short_name  LIKE ?
               OR full_name   LIKE ?
            ORDER BY
                /* exacte start_number-matches bovenaan */
                CASE WHEN ? = 1 AND start_number = ? THEN 0 ELSE 1 END,
                short_name, full_name
            LIMIT 100
        ");
        $stmt->execute([
            $isNum ? 1 : 0, $isNum ? (int)$q : 0,
            $zoekLic, $likeLic,
            $likeNaam,
            $likeNaam,
            $isNum ? 1 : 0, $isNum ? (int)$q : 0,
        ]);

        echo json_encode(['rijders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'zoek_profielen') {
        // Toon rijders met een "Mijn InlineComp"-profiel (of openstaande
        // aanvraag). Zelfde cap van 100 als de gewone zoek. Optioneel filter
        // via ?q= (zelfde matching als 'zoek'), zodat je ook bij >100 profielen
        // kunt filteren. Levert dezelfde velden als 'zoek' + de profielstatus.
        $q       = trim($_GET['q'] ?? '');
        $isNum   = ctype_digit($q);
        $like    = '%' . $q . '%';
        $zoekLic = strlen($q) >= 4 ? 1 : 0;
        $filter  = strlen($q) >= 2;   // korter dan 2 tekens = geen filter (alles)
        $stmt = $pdo->prepare("
            SELECT p.license_key, p.full_name, p.short_name, p.start_number,
                   p.category, p.club_short, p.club_full, p.anonymized_at,
                   (rp.pin_hash IS NOT NULL) AS prof_geclaimd,
                   (rp.claim_token_hash IS NOT NULL AND rp.claim_expires > NOW()) AS prof_claim_open
            FROM rijder_profiel rp
            JOIN persons p ON p.license_key = rp.license_key
            WHERE ? = 0
               OR (? = 1 AND p.start_number = ?)
               OR (? = 1 AND p.license_key LIKE ?)
               OR p.short_name LIKE ?
               OR p.full_name  LIKE ?
            ORDER BY prof_geclaimd DESC, prof_claim_open DESC, p.short_name, p.full_name
            LIMIT 100
        ");
        $stmt->execute([
            $filter ? 1 : 0,
            $isNum ? 1 : 0, $isNum ? (int)$q : 0,
            $zoekLic, $like,
            $like,
            $like,
        ]);
        echo json_encode(['rijders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'profiel_claim') {
        // Genereer een eenmalige claim-link voor het persoonlijke rijder-profiel
        // ("Mijn InlineComp"). De operator mailt/geeft deze link aan de rijder;
        // die stelt er zelf een PIN mee in (zie check/profiel.php). Geen e-mail
        // wordt opgeslagen — de identiteitscheck is de Geert-gate (in-persoon +
        // e-mail). Token 7 dagen geldig; alleen de sha256-hash wordt bewaard.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); echo json_encode(['error' => 'POST vereist']); exit;
        }
        $lk = trim($_POST['license_key'] ?? '');
        if ($lk === '') { http_response_code(400); echo json_encode(['error' => 'license_key vereist']); exit; }
        $ps = $pdo->prepare("SELECT full_name FROM persons WHERE license_key = ? AND anonymized_at IS NULL");
        $ps->execute([$lk]);
        $naam = $ps->fetchColumn();
        if ($naam === false) { http_response_code(404); echo json_encode(['error' => 'Rijder niet gevonden']); exit; }
        // Gewenste gebruikersnaam (uit de aanvraag) — optioneel. Wordt op het
        // profiel gezet; de rijder moet die bij het activeren invullen (check).
        $gbn = trim($_POST['username'] ?? '');
        if ($gbn !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $gbn)) {
                http_response_code(400);
                echo json_encode(['error' => 'Ongeldige gebruikersnaam (3–30 tekens: letters, cijfers, . _ of -).']); exit;
            }
            $uq = $pdo->prepare("SELECT 1 FROM rijder_profiel WHERE username = ? AND license_key <> ? LIMIT 1");
            $uq->execute([$gbn, $lk]);
            if ($uq->fetchColumn()) {
                http_response_code(409);
                echo json_encode(['error' => 'Die gebruikersnaam is al in gebruik — kies een andere.']); exit;
            }
        }
        // Al een PIN? Dan is deze nieuwe link een reset — meld dat aan de operator.
        $al = $pdo->prepare("SELECT pin_hash IS NOT NULL FROM rijder_profiel WHERE license_key = ?");
        $al->execute([$lk]);
        $reset = (bool)$al->fetchColumn();
        $rawTok = bin2hex(random_bytes(16));
        if ($gbn !== '') {
            $pdo->prepare("
                INSERT INTO rijder_profiel (license_key, username, claim_token_hash, claim_expires)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                ON DUPLICATE KEY UPDATE username = VALUES(username),
                                        claim_token_hash = VALUES(claim_token_hash),
                                        claim_expires    = VALUES(claim_expires)
            ")->execute([$lk, $gbn, hash('sha256', $rawTok)]);
        } else {
            $pdo->prepare("
                INSERT INTO rijder_profiel (license_key, claim_token_hash, claim_expires)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                ON DUPLICATE KEY UPDATE claim_token_hash = VALUES(claim_token_hash),
                                        claim_expires    = VALUES(claim_expires)
            ")->execute([$lk, hash('sha256', $rawTok)]);
        }
        $cur = $pdo->prepare("SELECT username FROM rijder_profiel WHERE license_key = ?");
        $cur->execute([$lk]);
        $unStored = (string)($cur->fetchColumn() ?: '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'inlineresults.devriesen.com';
        echo json_encode([
            'ok'       => true,
            'naam'     => $naam,
            'username' => $unStored,
            'url'      => $scheme . '://' . $host . '/check/profiel.php?claim=' . $rawTok,
            'verloopt' => date('d-m-Y H:i', time() + 7 * 86400),
            'reset'    => $reset,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'aanvragen_lijst') {
        // Lijst met profiel-aanvragen (standaard alleen 'pending'). Voedt de
        // goedkeur-UI in Systeem → Rijders. E-mail zelf geven we NIET terug
        // (staat er alleen zolang pending; niet nodig in de UI).
        $status = trim($_GET['status'] ?? 'pending');
        $where = ''; $params = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where = 'WHERE a.status = ?'; $params[] = $status;
        }
        $st = $pdo->prepare("
            SELECT a.id, a.naam, a.startnummer, a.gewenste_username, a.opmerking,
                   a.status, a.license_key, a.created_at, a.behandeld_at,
                   (a.email IS NOT NULL) AS heeft_email,
                   p.full_name AS gekoppeld_naam
            FROM rijder_profiel_aanvraag a
            LEFT JOIN persons p ON p.license_key = a.license_key
            $where
            ORDER BY (a.status = 'pending') DESC, a.created_at DESC
            LIMIT 200
        ");
        $st->execute($params);
        echo json_encode(['aanvragen' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'aanvraag_goedkeuren') {
        // Koppel de aanvraag aan een rijder + gebruikersnaam, maak een claim-link
        // (zoals profiel_claim) en mail die naar de aanvrager (organisatie in Cc).
        // Daarna: e-mail wissen + status 'approved'.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); echo json_encode(['error' => 'POST vereist']); exit;
        }
        $aid = (int)($_POST['id'] ?? 0);
        $lk  = trim($_POST['license_key'] ?? '');
        $gbn = trim($_POST['username'] ?? '');
        $aq = $pdo->prepare("SELECT naam, email, status FROM rijder_profiel_aanvraag WHERE id = ?");
        $aq->execute([$aid]);
        $aanvr = $aq->fetch(PDO::FETCH_ASSOC);
        if (!$aanvr) { http_response_code(404); echo json_encode(['error' => 'Aanvraag niet gevonden']); exit; }
        if ($aanvr['status'] !== 'pending') { http_response_code(409); echo json_encode(['error' => 'Deze aanvraag is al verwerkt.']); exit; }
        if ($lk === '') { http_response_code(400); echo json_encode(['error' => 'Koppel eerst de juiste rijder.']); exit; }
        $ps = $pdo->prepare("SELECT full_name FROM persons WHERE license_key = ? AND anonymized_at IS NULL");
        $ps->execute([$lk]);
        $pnaam = $ps->fetchColumn();
        if ($pnaam === false) { http_response_code(404); echo json_encode(['error' => 'Gekoppelde rijder niet gevonden']); exit; }
        if ($gbn === '' || !preg_match('/^[A-Za-z0-9._-]{3,30}$/', $gbn)) {
            http_response_code(400);
            echo json_encode(['error' => 'Vul een geldige gebruikersnaam in (3–30 tekens: letters, cijfers, . _ of -).']); exit;
        }
        $uq = $pdo->prepare("SELECT 1 FROM rijder_profiel WHERE username = ? AND license_key <> ? LIMIT 1");
        $uq->execute([$gbn, $lk]);
        if ($uq->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error' => 'Die gebruikersnaam is al in gebruik — kies een andere.']); exit;
        }
        // Claim-link maken (7 dagen), zoals profiel_claim.
        $rawTok = bin2hex(random_bytes(16));
        $pdo->prepare("
            INSERT INTO rijder_profiel (license_key, username, claim_token_hash, claim_expires)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ON DUPLICATE KEY UPDATE username = VALUES(username),
                                    claim_token_hash = VALUES(claim_token_hash),
                                    claim_expires    = VALUES(claim_expires)
        ")->execute([$lk, $gbn, hash('sha256', $rawTok)]);

        require_once __DIR__ . '/../inc/profiel_mail.php';
        $claimUrl = PROFIEL_LOGIN_URL . '?claim=' . $rawTok;
        $mailOk = false;
        $email  = (string)($aanvr['email'] ?? '');
        if ($email !== '') {
            $m = profielMailGoedgekeurd((string)$aanvr['naam'], $gbn, $claimUrl);
            $mailOk = profielMail($email, $m['subject'], $m['body'], PROFIEL_MAIL_CC);
        }
        // E-mail wissen + status bijwerken (AVG: adres niet langer bewaren).
        $pdo->prepare("
            UPDATE rijder_profiel_aanvraag
            SET status = 'approved', license_key = ?, email = NULL,
                behandeld_door = ?, behandeld_at = NOW()
            WHERE id = ?
        ")->execute([$lk, $_authUser['id'] ?? null, $aid]);

        echo json_encode([
            'ok'       => true,
            'mail_ok'  => $mailOk,
            'username' => $gbn,
            'url'      => $claimUrl,
            'verloopt' => date('d-m-Y H:i', time() + 7 * 86400),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'aanvraag_afwijzen') {
        // Aanvraag afwijzen: nette mail naar de aanvrager (organisatie in Cc),
        // e-mail wissen, status 'rejected'.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); echo json_encode(['error' => 'POST vereist']); exit;
        }
        $aid = (int)($_POST['id'] ?? 0);
        $aq = $pdo->prepare("SELECT naam, email, status FROM rijder_profiel_aanvraag WHERE id = ?");
        $aq->execute([$aid]);
        $aanvr = $aq->fetch(PDO::FETCH_ASSOC);
        if (!$aanvr) { http_response_code(404); echo json_encode(['error' => 'Aanvraag niet gevonden']); exit; }
        if ($aanvr['status'] !== 'pending') { http_response_code(409); echo json_encode(['error' => 'Deze aanvraag is al verwerkt.']); exit; }
        require_once __DIR__ . '/../inc/profiel_mail.php';
        $mailOk = false;
        $email  = (string)($aanvr['email'] ?? '');
        if ($email !== '') {
            $m = profielMailAfgewezen((string)$aanvr['naam']);
            $mailOk = profielMail($email, $m['subject'], $m['body'], PROFIEL_MAIL_CC);
        }
        $pdo->prepare("
            UPDATE rijder_profiel_aanvraag
            SET status = 'rejected', email = NULL, behandeld_door = ?, behandeld_at = NOW()
            WHERE id = ?
        ")->execute([$_authUser['id'] ?? null, $aid]);
        echo json_encode(['ok' => true, 'mail_ok' => $mailOk], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'username_vrij') {
        // Live beschikbaarheids-check voor de gebruikersnaam bij het genereren
        // van een profiel-link. Alleen-beheer (dit endpoint is al owner/admin) →
        // geen publieke enumeratie van gebruikersnamen.
        $u  = trim($_GET['u'] ?? '');
        $lk = trim($_GET['license_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $u)) {
            echo json_encode(['ongeldig' => true, 'vrij' => false]); exit;
        }
        $q = $pdo->prepare("SELECT 1 FROM rijder_profiel WHERE username = ? AND license_key <> ? LIMIT 1");
        $q->execute([$u, $lk]);
        echo json_encode(['vrij' => !$q->fetchColumn()]);
        exit;
    }

    if ($action === 'detail') {
        $lk = trim($_GET['license_key'] ?? '');
        if (!$lk) {
            http_response_code(400);
            echo json_encode(['error' => 'license_key ontbreekt']);
            exit;
        }

        // 1. Alle persons-velden
        $stmt = $pdo->prepare("SELECT * FROM persons WHERE license_key = ?");
        $stmt->execute([$lk]);
        $rijder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rijder) {
            http_response_code(404);
            echo json_encode(['error' => 'Rijder niet gevonden']);
            exit;
        }

        // 2. Transponder-toewijzingen (per organisatie)
        // Match-strategie:
        //   primair: ot.person_license = license_key (sinds migratie)
        //   fallback: oude rijen waar person_license = NULL maar wel
        //             (toegewezen_naam + toegewezen_snr) matcht met de rijder
        // Zo zien we ook transponders van vóór de license-koppeling-migratie.
        $tpStmt = $pdo->prepare("
            SELECT ot.intern_nummer, ot.transponder_code, ot.categorie,
                   ot.betaald, ot.betaald_op, ot.eigendom,
                   ot.person_license, ot.toegewezen_naam, ot.toegewezen_snr,
                   o.naam AS organisatie_naam
            FROM organisatie_transponders ot
            JOIN organisaties o ON o.id = ot.organisatie_id
            JOIN persons p     ON p.license_key = ?
            WHERE ot.person_license = ?
               OR (ot.person_license IS NULL
                   AND ot.toegewezen_naam = p.full_name
                   AND ot.toegewezen_snr  = p.start_number)
            ORDER BY o.naam, CAST(ot.intern_nummer AS UNSIGNED)
        ");
        $tpStmt->execute([$lk, $lk]);
        $transponders = $tpStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Wedstrijd-deelnames + per-DC einduitslag
        // Alle benodigde kolommen zijn gedenormaliseerd in uitslag_klassement
        // (competition_naam/datum, dc_naam) — geen JOIN nodig, én de uitslag
        // blijft beschikbaar ook als de competitions-rij ooit verwijderd wordt.
        $wedStmt = $pdo->prepare("
            SELECT uk.competition_id       AS comp_id,
                   uk.competition_naam     AS comp_naam,
                   uk.competition_datum    AS comp_datum,
                   uk.distance_combination_id AS dc_id,
                   uk.dc_naam              AS dc_naam,
                   uk.categorie,
                   uk.rang                 AS positie,
                   uk.punten_totaal        AS punten,
                   -- Split-DC (bv. HP1+DP1 in één DC): het vastleggen schrijft
                   -- één GECOMBINEERDE rang weg (uitslag_vastleggen.php geeft
                   -- split_group hardcoded '' mee), dus de rang binnen de eigen
                   -- categorie staat nergens opgeslagen. /public leidt die af uit
                   -- de gecombineerde volgorde; hier doen we hetzelfde: tel hoeveel
                   -- rijders uit dezelfde categorie een betere rang hebben.
                   -- Ex-aequo klopt daarmee vanzelf (< i.p.v. <=).
                   CASE WHEN uk.rang IS NULL THEN NULL ELSE (
                       SELECT COUNT(*) + 1
                       FROM uitslag_klassement uk2
                       WHERE uk2.competition_id          = uk.competition_id
                         AND uk2.distance_combination_id = uk.distance_combination_id
                         AND uk2.categorie               = uk.categorie
                         AND uk2.rang IS NOT NULL
                         AND uk2.rang < uk.rang
                   ) END AS positie_cat,
                   -- Welke categoriéén zitten er in deze DC? Meer dan één = split.
                   (SELECT GROUP_CONCAT(DISTINCT uk3.categorie
                                        ORDER BY uk3.categorie SEPARATOR ' + ')
                      FROM uitslag_klassement uk3
                     WHERE uk3.competition_id          = uk.competition_id
                       AND uk3.distance_combination_id = uk.distance_combination_id
                       AND uk3.categorie IS NOT NULL) AS cats_in_dc
            FROM uitslag_klassement uk
            WHERE uk.person_license = ?
            ORDER BY uk.competition_datum DESC, uk.competition_naam, uk.dc_naam
        ");
        $wedStmt->execute([$lk]);
        $wedstrijden = $wedStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Per-afstand uitslagen (detail-overzicht)
        $afStmt = $pdo->prepare("
            SELECT ua.competition_id       AS comp_id,
                   ua.competition_naam     AS comp_naam,
                   ua.competition_datum    AS comp_datum,
                   ua.dc_naam,
                   ua.distance_naam,
                   ua.tijd_ms,
                   ua.rang                 AS positie,
                   ua.finale_naam,
                   ua.punten,
                   ua.sanctie
            FROM uitslag_afstand ua
            WHERE ua.person_license = ?
            ORDER BY ua.competition_datum DESC, ua.competition_naam, ua.dc_naam, ua.distance_naam
        ");
        $afStmt->execute([$lk]);
        $afstandenRaw = $afStmt->fetchAll(PDO::FETCH_ASSOC);

        // Format tijd_ms naar leesbare mm:ss.hhh
        $afstanden = array_map(function($a) {
            if (!empty($a['tijd_ms'])) {
                $ms = (int)$a['tijd_ms'];
                $min = intdiv($ms, 60000);
                $sec = ($ms % 60000) / 1000;
                $a['tijd'] = $min > 0
                    ? sprintf('%d:%06.3f', $min, $sec)
                    : sprintf('%.3f', $sec);
            } else {
                $a['tijd'] = null;
            }
            return $a;
        }, $afstandenRaw);

        // 4b. PDF-klassementen waar deze rijder in voorkomt.
        // klassement_posities heeft geen license_key — gematched op naam
        // (case-insensitive, getrimmed). Pakt zowel full_name als short_name
        // van de rijder zodat naamvariaties (achternaam-only PDFs vs
        // volledige naam) beide gevonden worden.
        // Bron = geïmporteerde PDF-klassementen + handmatig ingevoerde
        // serie-klassementen — alles wat operator ooit via Beheer →
        // Klassementen heeft toegevoegd.
        $namen = array_values(array_unique(array_filter([
            $rijder['full_name'] ?? null,
            $rijder['short_name'] ?? null,
        ], fn($n) => $n !== null && trim($n) !== '')));
        $pdfKlassementen = [];
        if ($namen) {
            $naamPh = implode(',', array_fill(0, count($namen), 'LOWER(TRIM(?))'));
            $pkStmt = $pdo->prepare("
                SELECT k.id          AS klassement_id,
                       k.naam        AS klassement_naam,
                       k.seizoen,
                       k.bron_bestand,
                       kp.positie,
                       kp.start_number,
                       kp.naam       AS rijder_naam,
                       kp.categorie
                FROM klassement_posities kp
                JOIN klassementen k ON k.id = kp.klassement_id
                WHERE LOWER(TRIM(kp.naam)) IN ($naamPh)
                ORDER BY k.seizoen DESC, k.naam, kp.positie
            ");
            $pkStmt->execute($namen);
            $pdfKlassementen = $pkStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 5. Bekende transponders voor deze rijder — alle codes die ooit
        // ergens voor de rijder geregistreerd zijn (KNSB-feed of handmatig
        // toegewezen aan de balie). Per code: in welke slots gebruikt
        // (0=actief, 1=T1, 2=T2, 3+=extra), bron, hoeveel wedstrijden,
        // wanneer voor het laatst gezien.
        $bktStmt = $pdo->prepare("
            SELECT code,
                   GROUP_CONCAT(DISTINCT slot ORDER BY slot) AS slots,
                   GROUP_CONCAT(DISTINCT source)             AS sources,
                   COUNT(DISTINCT competition_id)            AS aantal_wedstrijden,
                   MAX(updated_at)                           AS laatst_gezien
            FROM transponders
            WHERE person_license = ?
              AND code IS NOT NULL
              AND code != ''
            GROUP BY code
            ORDER BY MAX(updated_at) DESC
        ");
        $bktStmt->execute([$lk]);
        $bekendeTransponders = $bktStmt->fetchAll(PDO::FETCH_ASSOC);

        // Profiel-status ("Mijn InlineComp"): geclaimd / claim openstaand / geen.
        $prStmt = $pdo->prepare("
            SELECT username, (pin_hash IS NOT NULL) AS geclaimd, claimed_at, laatste_login,
                   (claim_token_hash IS NOT NULL AND claim_expires > NOW()) AS claim_open, claim_expires
            FROM rijder_profiel WHERE license_key = ?");
        $prStmt->execute([$lk]);
        $prof = $prStmt->fetch(PDO::FETCH_ASSOC);
        $profiel = $prof ? [
            'username'      => $prof['username'],
            'geclaimd'      => (bool)$prof['geclaimd'],
            'claimed_at'    => $prof['claimed_at'],
            'laatste_login' => $prof['laatste_login'],
            'claim_open'    => (bool)$prof['claim_open'],
            'claim_expires' => $prof['claim_expires'],
        ] : null;

        echo json_encode([
            'rijder'               => $rijder,
            'transponders'         => $transponders,
            'bekende_transponders' => $bekendeTransponders,
            'wedstrijden'          => $wedstrijden,
            'afstanden'            => $afstanden,
            'pdf_klassementen'     => $pdfKlassementen,
            'profiel'              => $profiel,
        ]);
        exit;
    }

    if ($action === 'profiel_verwijderen') {
        // Verwijder het persoonlijke profiel (rijder_profiel-rij). Uitslagen/
        // persons blijven ongemoeid. Daarna kan opnieuw een claim-link.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405); echo json_encode(['error' => 'POST vereist']); exit;
        }
        $lk = trim($_POST['license_key'] ?? '');
        if ($lk === '') { http_response_code(400); echo json_encode(['error' => 'license_key vereist']); exit; }
        $del = $pdo->prepare("DELETE FROM rijder_profiel WHERE license_key = ?");
        $del->execute([$lk]);
        echo json_encode(['ok' => true, 'verwijderd' => $del->rowCount()]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
