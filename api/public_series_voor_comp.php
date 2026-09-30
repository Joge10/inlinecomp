<?php
// api/public_series_voor_comp.php
// Endpoint: beschikbare serie-klassementen voor wedstrijd.
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'series_voor_comp') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode([]); exit; }
    try {
        // We willen alleen series tonen waar de wedstrijd meetelt en waarvan
        // het uit-klassement (klassementen-rij) ook echt posities heeft.
        $stmt = $pdo->prepare("
            SELECT s.id AS serie_id, s.naam, s.seizoen, s.klassement_id,
                   k.totaal_rijders, k.herberekend_op
            FROM klassement_series s
            JOIN klassement_serie_wedstrijden w ON w.serie_id = s.id
            JOIN klassementen k ON k.id = s.klassement_id
            LEFT JOIN klassement_series s_alias ON s_alias.id = s.id
            WHERE w.competition_id = ? AND w.telt_mee = 1 AND k.totaal_rijders > 0
            GROUP BY s.id
            ORDER BY s.naam
        ");
        // 'herberekend_op' kolomnaam alleen in klassement_series — kleine SQL-fix:
        // Filter op s.gepubliceerd_at IS NOT NULL — niet-gepubliceerde series
        // (test-/probeer-versies) blijven verborgen voor public/coach.
        $stmt = $pdo->prepare("
            SELECT s.id AS serie_id, s.naam, s.seizoen, s.klassement_id,
                   s.herberekend_op,
                   k.totaal_rijders
            FROM klassement_series s
            JOIN klassement_serie_wedstrijden w ON w.serie_id = s.id
            JOIN klassementen k ON k.id = s.klassement_id
            WHERE w.competition_id = ? AND w.telt_mee = 1 AND k.totaal_rijders > 0
              AND s.gepubliceerd_at IS NOT NULL
            GROUP BY s.id
            ORDER BY s.naam
        ");
        $stmt->execute([$compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
