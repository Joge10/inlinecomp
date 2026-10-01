<?php
// api/public_rondes_cats.php
// Endpoint: rondes+categorieen voor uitslagen-tab.
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'rondes_cats') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id required']); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                   p.category           AS categorie,
                   d.id                 AS distance_id,
                   d.name               AS distance_naam,
                   d.value_meters,
                   d.number,
                   d.distance_combination_id AS dc_id,
                   dc.name              AS dc_naam
            FROM heats h
            JOIN heat_entries he ON he.heat_id = h.id
            JOIN persons p       ON p.person_id = he.person_id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            -- distances heeft compound PK (distance_combination_id, id).
            -- Dezelfde distance_id komt bewust in meerdere DCs voor voor
            -- cross-DC aggregatie. JOIN moet daarom ook op DC, anders
            -- claimt bv HSA per ongeluk de DP4-versie van de distance.
            JOIN distances d  ON d.id  = COALESCE(h.distance_id, tsr.distance_id)
                             AND d.distance_combination_id = h.distance_combination_id
            JOIN distance_combinations dc ON dc.id = d.distance_combination_id
            WHERE h.competition_id = ?
              AND p.category IS NOT NULL AND p.category <> ''
            ORDER BY p.category, d.number, d.name
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gepubliceerde klassement-DC's — voor "🏆 Klassement"-optie.
        $klasStmt = $pdo->prepare("
            SELECT DISTINCT dc_id
            FROM klassement_config
            WHERE competition_id = ? AND gepubliceerd_at IS NOT NULL
        ");
        $klasStmt->execute([$compId]);
        $klasDcIds = $klasStmt->fetchAll(PDO::FETCH_COLUMN);
        $klasSet = array_flip($klasDcIds);

        // Sorteersleutel: jongste → oudste categorie, dames vóór heren per
        // leeftijd. Zelfde logica als jury (_juryCatSortKey) en coach.
        $catSortKey = function(string $cat): int {
            $cat = strtoupper(trim($cat));
            if (preg_match('/^([HD]?)M(\d{2,3})$/', $cat, $m)) {
                $genderRank = match($m[1]) { 'D' => 0, 'H' => 1, default => 1 };
                $leeftijd = (int)$m[2];
                if ($leeftijd >= 40) {
                    $ageRank = 10 + intdiv($leeftijd - 40, 5);
                    return $ageRank * 10 + $genderRank;
                }
            }
            $genderRank = match(substr($cat, 0, 1)) { 'D' => 0, 'H' => 1, default => 9 };
            $sub = substr($cat, 1);
            $ageRank = match($sub) {
                'P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3,
                'KA' => 4, 'JB' => 5, 'JA' => 6,
                'SJ' => 7, 'SA' => 8, 'SB' => 9,
                default => 99,
            };
            return $ageRank * 10 + $genderRank;
        };

        // Eerst per DC de cats verzamelen.
        $dcCats = []; $dcNaam = []; $dcAfstanden = [];
        foreach ($rows as $r) {
            $dcId = $r['dc_id'];
            $dcNaam[$dcId] = $r['dc_naam'];
            if (!isset($dcCats[$dcId])) $dcCats[$dcId] = [];
            if (!in_array($r['categorie'], $dcCats[$dcId], true)) $dcCats[$dcId][] = $r['categorie'];
            if (!isset($dcAfstanden[$dcId])) $dcAfstanden[$dcId] = [];
            $al = false;
            foreach ($dcAfstanden[$dcId] as $a) {
                if ($a['distance_id'] === $r['distance_id']) { $al = true; break; }
            }
            if (!$al) {
                $dcAfstanden[$dcId][] = [
                    'distance_id'   => $r['distance_id'],
                    'distance_naam' => $r['distance_naam'],
                ];
            }
        }
        // Groepeer per cat-signatuur (bv "HJA+HSA"): meerdere DC's met
        // dezelfde cat-samenstelling worden ÉÉN dropdown-optie. Afstanden
        // uit alle DC's samenvoegen, elk met eigen dc_id voor de fetch.
        $perSig = [];
        foreach ($dcCats as $dcId => $cats) {
            $sorted = $cats;
            usort($sorted, fn($a, $b) => $catSortKey($a) - $catSortKey($b));
            $sig = implode('+', $sorted);
            if (!isset($perSig[$sig])) {
                $perSig[$sig] = [
                    'sig' => $sig,
                    'label' => implode(' + ', $sorted),
                    'categorieen' => $sorted,
                    'afstanden' => [],
                    'klassementen' => [],
                    '_sortkey' => $catSortKey($sorted[0] ?? ''),
                ];
            }
            foreach ($dcAfstanden[$dcId] as $a) {
                $perSig[$sig]['afstanden'][] = [
                    'distance_id'   => $a['distance_id'],
                    'distance_naam' => $a['distance_naam'],
                    'dc_id'         => $dcId,
                ];
            }
            if (isset($klasSet[$dcId])) {
                $perSig[$sig]['klassementen'][] = [
                    'dc_id'   => $dcId,
                    'dc_naam' => $dcNaam[$dcId],
                ];
            }
        }
        foreach ($perSig as &$sig) {
            usort($sig['afstanden'], fn($a, $b) => strnatcmp($a['distance_naam'] ?? '', $b['distance_naam'] ?? ''));
        }
        unset($sig);
        $out = array_values($perSig);
        usort($out, fn($a, $b) => $a['_sortkey'] - $b['_sortkey']);
        foreach ($out as &$sig) unset($sig['_sortkey']);
        unset($sig);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

