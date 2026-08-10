<?php
// InlineComp – Push-abonnement-statistieken (Systeem → Bezoekers).
// Alleen beschikbaar voor ingelogde gebruikers met rol owner of admin.
//
// GET /api/push_stats.php
// Response: { totaal, coach, public, wil_loting, wil_uitslag, coach_accounts }
//
// Telt push_subscriptions per scope + per opt-in-type, zodat je in de beheer-
// app kunt zien of push wordt gebruikt (adoptie over tijd). Eén rij = één
// apparaat/browser; dode abonnementen worden bij versturen (404/410) al
// opgeruimd, dus dit is een reële momentopname.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$user = requireAuth($pdo);

if (!in_array($user['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Geen toegang']);
    exit;
}

// Defensief: op een omgeving zonder de push-migratie bestaat de tabel (of de
// notif_*-kolommen) nog niet → geef gewoon nullen terug i.p.v. een 500.
$rij = [];
try {
    $rij = $pdo->query("
        SELECT
            COUNT(*)                                                         AS totaal,
            SUM(scope = 'coach')                                             AS coach,
            SUM(scope = 'public')                                            AS public,
            SUM(notif_loting  = 1)                                           AS wil_loting,
            SUM(notif_uitslag = 1)                                           AS wil_uitslag,
            COUNT(DISTINCT CASE WHEN scope = 'coach' THEN coach_account_id END) AS coach_accounts
        FROM push_subscriptions
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $rij = [];
}

echo json_encode([
    'totaal'         => (int) ($rij['totaal']         ?? 0),
    'coach'          => (int) ($rij['coach']          ?? 0),
    'public'         => (int) ($rij['public']         ?? 0),
    'wil_loting'     => (int) ($rij['wil_loting']     ?? 0),
    'wil_uitslag'    => (int) ($rij['wil_uitslag']    ?? 0),
    'coach_accounts' => (int) ($rij['coach_accounts'] ?? 0),
]);
