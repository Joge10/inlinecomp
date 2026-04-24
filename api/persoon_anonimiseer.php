<?php
// ============================================================
//  InlineComp – persoon anonimiseren (AVG / recht op vergetelheid)
//
//  POST action=anonimiseer  { license_key }
//      → vervangt naam/geboortejaar/woonplaats/sponsor/short_name
//        door 'Verwijderd'/NULL en zet anonymized_at = NOW().
//        De license_key blijft staan als pseudonieme FK — alle
//        wedstrijdgeschiedenis (heats/results/klassement) blijft
//        intact, maar toont voortaan "Verwijderd" i.p.v. naam.
//
//  POST action=undo         { license_key }
//      → alleen beschikbaar zolang we de oorspronkelijke gegevens
//        kunnen herstellen via een nieuwe KNSB-import. Deze endpoint
//        zet anonymized_at weer op NULL en laat de velden zoals ze
//        zijn; een herimport van de rijder vult ze dan opnieuw.
//
//  GET  action=lijst        → rijders die anoniem zijn (voor audit)
//
//  Alleen voor admins (mag andermans persoonsgegevens verwijderen).
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
$_authUser = requireAuth($pdo);

// Alleen owner/admin mogen anonimiseren — dit is een onomkeerbare actie
// met impact op persoonsgegevens.
if (!in_array($_authUser['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Alleen beheerders kunnen rijders anonimiseren.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'lijst') {
        $stmt = $pdo->query("
            SELECT license_key, anonymized_at, updated_at
            FROM persons
            WHERE anonymized_at IS NOT NULL
            ORDER BY anonymized_at DESC
        ");
        echo json_encode(['rijders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Methode niet toegestaan']);
        exit;
    }

    $lk = trim($body['license_key'] ?? '');
    if (!$lk) {
        http_response_code(400);
        echo json_encode(['error' => 'license_key ontbreekt']);
        exit;
    }

    // Bestaat de rijder wel?
    $check = $pdo->prepare("SELECT license_key, full_name, anonymized_at
                            FROM persons WHERE license_key = ?");
    $check->execute([$lk]);
    $huidig = $check->fetch(PDO::FETCH_ASSOC);
    if (!$huidig) {
        http_response_code(404);
        echo json_encode(['error' => 'Rijder niet gevonden']);
        exit;
    }

    if ($action === 'anonimiseer') {
        // Pseudonimiseer: vervang alles wat direct herleidbaar is.
        // - full_name → 'Verwijderd'
        // - short_name, birth_year, city, sponsor → NULL
        // - gender, category, club_* blijven staan; dat is statistiek,
        //   zonder naam niet-herleidbaar.
        // - start_number → NULL (kan aan één wedstrijd gekoppeld zijn maar
        //   is combineerbaar met andere bronnen)
        $stmt = $pdo->prepare("
            UPDATE persons
            SET full_name     = 'Verwijderd',
                short_name    = NULL,
                birth_year    = NULL,
                city          = NULL,
                sponsor       = NULL,
                start_number  = NULL,
                anonymized_at = NOW()
            WHERE license_key = ?
        ");
        $stmt->execute([$lk]);

        // Óók: alle toegewezen_naam-referenties in organisatie_transponders
        // en de transponder-toewijzing zelf leegmaken. Wedstrijd-entries en
        // results blijven staan (alleen de license_key is de link; daar is
        // geen naam opgeslagen).
        $pdo->prepare("
            UPDATE organisatie_transponders
            SET toegewezen_naam = NULL,
                toegewezen_snr  = NULL,
                person_license  = NULL,
                categorie       = NULL,
                betaald         = 0,
                betaald_op      = NULL
            WHERE person_license = ?
        ")->execute([$lk]);

        // Log het ter verantwoording (welke admin, wanneer, welke rijder).
        // Geen naam in de log — die is nu juist weg. Alleen license_key + admin-id.
        if (function_exists('logboekSchrijf')) {
            logboekSchrijf($pdo, $_authUser['id'] ?? null,
                'persoon_anonimiseer', [
                    'license_key' => $lk,
                    'was_anoniem' => $huidig['anonymized_at'] !== null,
                ]);
        }

        echo json_encode([
            'ok'      => true,
            'message' => 'Rijder geanonimiseerd. Wedstrijdgeschiedenis is behouden, persoonsgegevens zijn gewist.',
        ]);
        exit;
    }

    if ($action === 'undo') {
        // Hef de anonimisatie op. De gegevens zijn wèl weg — een nieuwe
        // KNSB-import (of handmatige invoer) moet de rijder opnieuw vullen.
        $stmt = $pdo->prepare("
            UPDATE persons SET anonymized_at = NULL WHERE license_key = ?
        ");
        $stmt->execute([$lk]);

        if (function_exists('logboekSchrijf')) {
            logboekSchrijf($pdo, $_authUser['id'] ?? null,
                'persoon_anonimiseer_undo', ['license_key' => $lk]);
        }

        echo json_encode([
            'ok'      => true,
            'message' => 'Anonimisatie opgeheven. Herimporteer de rijder om de gegevens aan te vullen.',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
