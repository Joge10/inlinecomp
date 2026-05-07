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
                   uk.punten_totaal        AS punten
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

        echo json_encode([
            'rijder'               => $rijder,
            'transponders'         => $transponders,
            'bekende_transponders' => $bekendeTransponders,
            'wedstrijden'          => $wedstrijden,
            'afstanden'            => $afstanden,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
