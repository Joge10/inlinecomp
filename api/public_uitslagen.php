<?php
// api/public_uitslagen.php
// Endpoint: uitslagen per categorie+afstand.
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'uitslagen') {
    header('Content-Type: application/json; charset=utf-8');
    // Uitslag/klassement-publicatie kan per minuut wijzigen; geen cache.
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    $dcId   = trim($_GET['dc_id'] ?? '');
    $type   = trim($_GET['type'] ?? 'afstand');
    $distId = trim($_GET['distance_id'] ?? '');
    // Optionele categorie-filter: bij gecombineerde DC (bv 'DSA+HSA') geven
    // (dc_id, distance_id) alleen niet genoeg om per-cat ranking te tonen —
    // uitslag_afstand bevat rijders van beide cats. Frontend geeft de
    // gekozen cat mee zodat we op p.category kunnen filteren.
    $catFilter = trim($_GET['categorie'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }

    // Anonimiteit (variant B): per-wedstrijd dag-uitslag/klassement volgt het
    // public-venster [wedstrijddag −1 … +1].
    [$cStarts, $cEnds] = anoniemCompVenster($pdo, $compId);

    try {
        if ($type === 'klassement') {
            // Pre-check: alleen gepubliceerde klassementen tonen
            $pubStmt = $pdo->prepare("
                SELECT 1 FROM klassement_config
                WHERE competition_id = ? AND dc_id = ? AND gepubliceerd_at IS NOT NULL
                LIMIT 1
            ");
            $pubStmt->execute([$compId, $dcId]);
            if (!$pubStmt->fetchColumn()) {
                echo json_encode(['rijders' => [], 'afstanden' => [], 'niet_gepubliceerd' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $catWhere = $catFilter !== '' ? ' WHERE p.category = ?' : '';
            $stmt = $pdo->prepare("
                SELECT t.rang, t.punten_totaal, t.dc_naam, t.punten_detail,
                       p.person_id, p.publiek_anoniem,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr
                FROM uitslag_klassement t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_id
                    FROM uitslag_klassement
                    WHERE competition_id = ? AND distance_combination_id = ?
                    GROUP BY person_id
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.person_id = t.person_id
                LEFT JOIN competition_startnummers cs ON cs.person_id = t.person_id AND cs.competition_id = ?
                $catWhere
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang, t.punten_totaal
            ");
            $params = [$compId, $dcId, $compId];
            if ($catFilter !== '') $params[] = $catFilter;
            $stmt->execute($params);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Afstandnamen uit punten_detail
            $afstanden = [];
            foreach ($rijders as &$r) {
                $r['punten_totaal'] = $r['punten_totaal'] !== null ? (float)$r['punten_totaal'] : null;
                $detail = json_decode($r['punten_detail'], true) ?? [];
                $r['punten_detail'] = $detail;
                foreach (array_keys($detail) as $dn) {
                    if (!in_array($dn, $afstanden)) $afstanden[] = $dn;
                }
                $r = pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
            }
            unset($r);

            echo json_encode(['rijders' => $rijders, 'afstanden' => $afstanden], JSON_UNESCAPED_UNICODE);
        } else {
            if (!$distId) { echo json_encode(['error' => 'distance_id verplicht voor type=afstand']); exit; }
            $catWhere = $catFilter !== '' ? ' WHERE p.category = ?' : '';
            $stmt = $pdo->prepare("
                SELECT t.rang, t.finale_naam, t.tijd_ms, t.sanctie,
                       t.distance_naam,
                       p.person_id, p.publiek_anoniem,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr,
                       res_agg.rondes, res_agg.pk_punten
                FROM uitslag_afstand t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_id
                    FROM uitslag_afstand
                    WHERE competition_id = ? AND distance_combination_id = ? AND distance_id = ?
                    GROUP BY person_id
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.person_id = t.person_id
                LEFT JOIN competition_startnummers cs ON cs.person_id = t.person_id AND cs.competition_id = ?
                LEFT JOIN (
                    SELECT he.person_id, res.rondes, res.punten AS pk_punten
                    FROM heat_entries he
                    JOIN heats h ON h.id = he.heat_id
                    JOIN results res ON res.heat_entry_id = he.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      AND COALESCE(h.distance_id, '') = ?
                      AND (res.rondes IS NOT NULL OR res.punten IS NOT NULL)
                    ORDER BY res.id DESC
                ) res_agg ON res_agg.person_id = t.person_id
                $catWhere
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang
            ");
            $params = [$compId, $dcId, $distId, $compId, $compId, $dcId, $distId];
            if ($catFilter !== '') $params[] = $catFilter;
            $stmt->execute($params);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Dedup (res_agg kan meerdere rijen geven). Dedup op de ECHTE naam+snr
            // vóór maskering — daarna pas anonimiteit toepassen (anders vallen
            // verschillende anonieme rijders samen als 'Anoniem'+snr).
            $seen = [];
            $unique = [];
            foreach ($rijders as $r) {
                $lic = $r['full_name'] . $r['snr'];
                if (isset($seen[$lic])) continue;
                $seen[$lic] = true;
                $unique[] = pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
            }

            $heeftRnd = !empty(array_filter($unique, fn($r) => $r['rondes'] !== null));
            $heeftPK  = !empty(array_filter($unique, fn($r) => $r['pk_punten'] !== null));

            echo json_encode([
                'rijders' => $unique,
                'heeft_rondes' => $heeftRnd,
                'heeft_pk_punten' => $heeftPK,
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: ronde-uitslagen (per afstand per ronde de complete uitslag) ────────
// Voor de publieke resultaten-tab: per afstand blok, per ronde sub-blok met
// Q/q-berekening en volledige rijder-lijst. Ook eind-uitslag uit
// uitslag_afstand voor de finale-klassering. Runner-up wordt gecombineerd
// (RU1 → RU2 → …) met globale eindposities (bv. plek 9-16).
// ── API: categorieën + afstanden voor Uitslagen-tab ─────────────────────────
// Anders dan /categorieen (die op DC-naam werkt, bv "DP4+DP3" bij combi):
// hier per persoons-categorie (DP4, DP3, DKA, DJB, …) een lijst afstanden
// met bijbehorende dc_id. Klassementen per unieke DC binnen elke categorie.
// Gesorteerd jongst → oudst, dames vóór heren — internationaal leesbaarder
// dan alfabetisch (DJB = Youth, DJA = Junior). Zelfde payload als /coach.
