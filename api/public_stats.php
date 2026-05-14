<?php
// InlineComp – Statistieken over de publieke pagina (/public/)
// Alleen beschikbaar voor ingelogde gebruikers met rol owner of admin.
//
// GET /api/public_stats.php
// Response:
//   {
//     "actief":              aantal sessies met last_seen > NOW() - 5 min
//     "totaal_uniek":        aantal unieke sessies ooit (RUW — incl. bots/previews)
//     "totaal_uniek_echt":   idem, gefilterd op "echte" sessies (zie hieronder)
//     "totaal_hits":         totaal aantal page views
//     "actief_vandaag":      unieke sessies met first_seen vandaag (RUW)
//     "actief_vandaag_echt": idem, gefilterd
//   }
//
// Definitie "echte" sessie (filter tegen bots / WhatsApp-/Telegram-/
// Discord-/preview-fetches):
//     hits >= 2  AND  TIMESTAMPDIFF(SECOND, first_seen, last_seen) >= 10
//
// Vereist BEIDE: bots doen vaak 2 hits in < 1 sec (preview-fetch met
// redirect). Echte bezoeken hebben minstens 10 sec tussen pageloads.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$user = requireAuth($pdo);

// Alleen owner/admin mogen de publieke statistieken zien
if (!in_array($user['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen toegang']);
    exit;
}

try {
    // "ECHT" = hits>=2 EN sessie duurde >=10 sec. Bots doen vaak 2 hits
    // in <1 sec (preview-fetch met redirect) — die filtert AND-criterium
    // er nog uit. Echte bezoeken hebben minstens 10 sec tussen pageloads.
    $echtCond = "(hits >= 2 AND TIMESTAMPDIFF(SECOND, first_seen, last_seen) >= 10)";

    $stmt = $pdo->query("
        SELECT
            SUM(CASE WHEN last_seen  > NOW() - INTERVAL 5 MINUTE THEN 1 ELSE 0 END) AS actief,
            SUM(CASE WHEN first_seen >= CURDATE()                THEN 1 ELSE 0 END) AS actief_vandaag,
            SUM(CASE WHEN first_seen >= CURDATE() AND $echtCond  THEN 1 ELSE 0 END) AS actief_vandaag_echt,
            COUNT(*)                                                                AS totaal_uniek,
            SUM(CASE WHEN $echtCond                              THEN 1 ELSE 0 END) AS totaal_uniek_echt,
            COALESCE(SUM(hits), 0)                                                  AS totaal_hits
        FROM public_visits
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Piek-data (vandaag + all-time) uit peak_stats
    $peak = $pdo->prepare("SELECT peak_today, peak_today_date, peak_all_time, peak_all_time_at
                           FROM peak_stats WHERE scope = 'public'");
    $peak->execute();
    $pk = $peak->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'actief'              => (int)($row['actief']              ?? 0),
        'actief_vandaag'      => (int)($row['actief_vandaag']      ?? 0),
        'actief_vandaag_echt' => (int)($row['actief_vandaag_echt'] ?? 0),
        'totaal_uniek'        => (int)($row['totaal_uniek']        ?? 0),
        'totaal_uniek_echt'   => (int)($row['totaal_uniek_echt']   ?? 0),
        'totaal_hits'         => (int)($row['totaal_hits']         ?? 0),
        'peak_today'          => (int)($pk['peak_today']           ?? 0),
        'peak_all_time'       => (int)($pk['peak_all_time']        ?? 0),
        'peak_at'             => $pk['peak_all_time_at']           ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
