<?php
// api/public_search_person.php
// Endpoint: rijder-zoek op naam/licentie/snr.
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'search_person') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $term   = trim($_GET['q'] ?? '');
    if (!$compId || mb_strlen($term) < 2) { echo json_encode([]); exit; }
    try {
        // Zoek uitsluitend op short_name (= achternaam). Niet op full_name,
        // om te voorkomen dat bv. "Jorn" matcht in voornamen van andere rijders.
        // Beperkt tot deelnemers van DEZE wedstrijd (AVG-dataminimalisatie: toon
        // niet de hele rijdersdatabase aan willekeurig publiek + functioneel
        // relevanter). Bestaande gevolgde rijders die deze keer niet meedoen
        // blijven in de persoonlijke lijst via de license-lookup hieronder.
        // `in_wedstrijd` blijft 1 voor frontend-compatibiliteit.
        $stmt = $pdo->prepare("
            SELECT p.person_id AS license_key, p.person_id, p.full_name, p.short_name,
                   p.category, p.club_short,
                   COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr,
                   1 AS in_wedstrijd
            FROM persons p
            LEFT JOIN competition_startnummers cs
                   ON cs.person_id = p.person_id AND cs.competition_id = ?
            WHERE p.short_name LIKE ?
              -- Publiek anonieme rijder is NOOIT op naam vindbaar (variant B,
              -- laag 'altijd'): weglaten i.p.v. maskeren, zodat de zoek ook niet
              -- bevestigt dát iemand meedoet/anoniem is. Toevoegen aan de
              -- volglijst kan alleen via het onraadbare person_id (GUID).
              AND p.publiek_anoniem IS NULL
              AND EXISTS (
                       SELECT 1 FROM entries e
                       JOIN distance_combinations dc
                         ON dc.id = e.distance_combination_id
                       WHERE e.person_id = p.person_id
                         AND dc.competition_id = ?
                  )
            ORDER BY p.short_name, p.full_name
            LIMIT 30
        ");
        $stmt->execute([$compId, '%' . $term . '%', $compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: lookup rijder ───────────────────────────────────────────────────────
