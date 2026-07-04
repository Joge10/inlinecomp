<?php
// ============================================================
//  InlineComp – Importeer-tab: filter-opties
//
//  Geeft ALLE unieke locaties + organisaties uit de competitions-tabel
//  terug (ongeacht datum), zodat de "Locatie" en "Organisatie" dropdowns
//  in Importeer óók waarden tonen van historische wedstrijden waarvan
//  de wedstrijd-cards zelf uit de 7-daagse cutoff gevallen zijn.
//
//  Response-shape matcht de KNSB-feed-format zodat frontend dezelfde
//  getLocatie() / getOrganisatieEmail() / getOrganisatieNaam() helpers
//  kan hergebruiken. Alleen de velden die die helpers uitlezen worden
//  gevuld — geen ballast.
//
//  Scope-aware: gescopte admins zien alleen hun eigen orgs. Owners
//  zien alles inclusief competities zonder organisatie_id.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

try {
    $scope = gebruikerOrgScope($pdo, $_authUser);

    $sql = "
        SELECT DISTINCT
            c.venue_name,
            c.venue_city,
            c.location,
            o.naam  AS org_naam,
            o.email AS org_email
        FROM competitions c
        LEFT JOIN organisaties o ON o.id = c.organisatie_id
    ";
    $params = [];
    if ($scope !== null) {
        if (empty($scope)) {
            echo json_encode([]); exit;
        }
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $sql   .= " WHERE c.organisatie_id IN ($ph)";
        $params = $scope;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rijen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Vorm om naar KNSB-feed-compatibele pseudo-wedstrijden. Alleen velden
    // die getLocatie() / getOrganisatieEmail() / getOrganisatieNaam() lezen.
    $out = array_map(function($r) {
        return [
            'venue' => [
                'name'    => $r['venue_name'] ?? null,
                'address' => ['city' => $r['venue_city'] ?? null],
            ],
            'location' => $r['location'] ?? null,
            'organizer' => ['name' => $r['org_naam'] ?? ''],
            'settings'  => ['contact' => [
                'email'            => $r['org_email'] ?? '',
                'organizationName' => $r['org_naam']  ?? '',
            ]],
        ];
    }, $rijen);

    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'filters ophalen mislukt']);
}
