<?php
// api/public_rit_detail.php
// Endpoint: rit-detail-payload voor overlay.
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'rit_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $ritNaam = trim($_GET['rit_naam'] ?? '');
    $dcNaam = trim($_GET['dc_naam'] ?? '');
    if (!$compId || !$ritNaam) { echo json_encode(['error' => 'Verplichte velden ontbreken']); exit; }

    try {
        // Zoek de heat via rit_naam koppeling
        $stmt = $pdo->prepare("
            SELECT h.id, h.heat_naam, h.ronde,
                   h.distance_combination_id, COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   COALESCE(tsr.ronde_type,
                       CASE WHEN h.heat_naam LIKE '%finale%' OR h.heat_naam LIKE '%ex-aequo%' THEN 'finale_a'
                            ELSE 'heats' END
                   ) AS ronde_type,
                   COALESCE(tsr.rit_naam, h.heat_naam) AS rit_naam
            FROM heats h
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            WHERE h.competition_id = ?
              AND (tsr.rit_naam = ? OR h.heat_naam = ?)
            LIMIT 1
        ");
        $stmt->execute([$compId, $ritNaam, $ritNaam]);
        $heat = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$heat) { echo json_encode(['heat' => null]); exit; }

        // Heats pas tonen als vorige ronde compleet is.
        // Runner-up: bron-ronde is de EERSTE deelnemende ronde (heats / KF /
        // HF), dus MIN() ipv MAX() — andere vervolgrondes (KF/HF/F): hoogste
        // ronde < huidige.
        if ((int)$heat['ronde'] > 1) {
            $dcId = $heat['distance_combination_id'] ?? null;
            $distId = $heat['distance_id'] ?? null;
            if ($dcId) {
                $distCond = ($distId !== '' && $distId !== null)
                    ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
                $vrParams = ($distId !== '' && $distId !== null)
                    ? [$compId, $dcId, $distId, (int)$heat['ronde']]
                    : [$compId, $dcId, (int)$heat['ronde']];
                $rondeType = $heat['ronde_type'] ?? '';
                if ($rondeType === 'runner_up') {
                    $vrStmt = $pdo->prepare("
                        SELECT MIN(h.ronde) FROM heats h
                        JOIN heat_entries he ON he.heat_id = h.id
                        LEFT JOIN tijdschema_ritten r ON r.id = h.tijdschema_rit_id
                        WHERE h.competition_id = ? AND h.distance_combination_id = ?
                          $distCond
                          AND (r.ronde_type IS NULL OR r.ronde_type <> 'runner_up')
                          AND h.ronde < ?
                    ");
                } else {
                    $vrStmt = $pdo->prepare("
                        SELECT MAX(h.ronde) FROM heats h
                        JOIN heat_entries he ON he.heat_id = h.id
                        WHERE h.competition_id = ? AND h.distance_combination_id = ?
                          $distCond
                          AND h.ronde < ?
                    ");
                }
                $vrStmt->execute($vrParams);
                $vr = $vrStmt->fetchColumn();
                if ($vr) {
                    $cParams = ($distId !== '' && $distId !== null)
                        ? [$compId, $dcId, $distId, (int)$vr]
                        : [$compId, $dcId, (int)$vr];
                    $cStmt = $pdo->prepare("
                        SELECT COUNT(he.id) AS totaal,
                               SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS met_resultaat
                        FROM heats h JOIN heat_entries he ON he.heat_id = h.id
                        LEFT JOIN results res ON res.heat_entry_id = he.id
                        WHERE h.competition_id = ? AND h.distance_combination_id = ?
                          $distCond
                          AND h.ronde = ?
                    ");
                    $cStmt->execute($cParams);
                    $r = $cStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$r || (int)$r['totaal'] === 0 || (int)$r['totaal'] !== (int)$r['met_resultaat']) {
                        echo json_encode(['heat' => null, 'reden' => 'Vorige ronde nog niet compleet']);
                        exit;
                    }
                }
            }
        }

        // Rijders ophalen (incl. vastgelegde rang uit uitslag_afstand als die bestaat)
        $dcId = $heat['distance_combination_id'] ?? null;
        $distId = $heat['distance_id'] ?? null;
        $rStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.person_id AS license_key, p.person_id,
                   p.full_name, p.category, p.publiek_anoniem,
                   res.finishpositie, res.tijd_ms, res.sanctie,
                   res.rondes, res.punten AS pk_punten,
                   ua.rang AS uitslag_rang
            FROM heat_entries he
            JOIN persons p ON p.person_id = he.person_id
            LEFT JOIN competition_startnummers cs ON cs.person_id = he.person_id AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            LEFT JOIN uitslag_afstand ua ON ua.person_id = he.person_id
                AND ua.competition_id = ? AND ua.distance_combination_id = ? AND ua.distance_id = ?
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");
        $rStmt->execute([$compId, $compId, $dcId, $distId, $heat['id']]);
        // Anonimiteit (variant B): publieke heat-weergave → public-venster maskering.
        [$cStarts, $cEnds] = anoniemCompVenster($pdo, $compId);
        $heat['rijders'] = array_map(function ($r) use ($cStarts, $cEnds) {
            return pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
        }, $rStmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['heat' => $heat], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: zoek personen op (deel van de) naam ─────────────────────────────────
// Retourneert een lichtgewicht lijst (snr, naam, categorie, club_short,
// license_key) — bedoeld voor een multi-pick chooser. Zwaar detail (heats)
// wordt pas per geselecteerde persoon opgehaald via `lookup`.
