<?php
// ============================================================
//  InlineComp – persoon anonimiseren (AVG / recht op vergetelheid)
//
//  POST action=anonimiseer  { license_key }
//      → vervangt naam/roepnaam/woonplaats/nationaliteit/
//        sponsor(team)/club/startnummer/volg-ID door 'Verwijderd'/NULL en zet
//        anonymized_at = NOW().
//        Alleen geslacht + categorie + de (naamloze) wedstrijdgeschiedenis blijven, via
//        het interne person_id — alle externe koppelingen én transponder-
//        registraties (person_external_ids, organisatie_transponders,
//        transponders) worden gewist. De uitslagen tonen "Verwijderd" i.p.v. naam.
//
//  (Bewust GEEN 'undo': anonimiseren is onomkeerbaar — de licentie- en overige
//   koppelingen zijn gewist, dus er is geen sleutel om een her-import aan dit
//   record te matchen. De bevestiging vooraf is de beveiliging.)
//
//  GET  action=lijst        → rijders die anoniem zijn (voor audit)
//
//  POST action=publiek_anoniem_aan / _uit  { license_key }
//      → OMKEERBARE publieke anonimiteit (variant B), los van bovenstaande
//        onomkeerbare AVG-wis. Zet/wist persons.publiek_anoniem. Data blijft
//        volledig behouden; alleen de publieke weergave wordt gemaskeerd
//        (zie inc/anoniem.php). Dit is de organisatie-kant van de vlag
//        (rijder mailt → beheerder zet aan/uit).
//
//  Alleen voor admins (mag andermans persoonsgegevens verwijderen).
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config_inlinecomp.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../inc/person_id.php';   // person_id-migratie fase 3 (identiteit)
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
            SELECT person_id AS license_key, anonymized_at, updated_at
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
    // Opaque token: mag license_key OF person_id zijn → resolve naar person_id.
    $pid = resolveNaarPersonId($pdo, $lk);
    if (!$pid) {
        http_response_code(404);
        echo json_encode(['error' => 'Rijder niet gevonden']);
        exit;
    }

    // Bestaat de rijder wel?
    $check = $pdo->prepare("SELECT person_id, full_name, anonymized_at
                            FROM persons WHERE person_id = ?");
    $check->execute([$pid]);
    $huidig = $check->fetch(PDO::FETCH_ASSOC);
    if (!$huidig) {
        http_response_code(404);
        echo json_encode(['error' => 'Rijder niet gevonden']);
        exit;
    }

    if ($action === 'anonimiseer') {
        // Pseudonimiseer: vervang alles wat direct herleidbaar is.
        // - full_name → 'Verwijderd'
        // - short_name, city, sponsor → NULL
        // - club_code/short/full → NULL. De club is verreweg het meest
        //   identificerende restveld: in combinatie met categorie + een
        //   specifieke tijd kan een insider een rijder alsnog herleiden. Wissen
        //   brengt de uitslag dichter bij echte anonimisering. (De uitslag
        //   verliest daarmee wel de club-context; bewust geaccepteerd.)
        // - gender, category blijven staan; categorie zonder club/naam is
        //   statistiek en op zichzelf niet naar een persoon te herleiden.
        // - start_number → NULL (kan aan één wedstrijd gekoppeld zijn maar
        //   is combineerbaar met andere bronnen)
        // - nationality → NULL (op zichzelf grof, maar combineerbaar met
        //   andere bronnen → hoort bij een volledige wis)
        // - publiek_anoniem / volg_token → NULL (een gewiste rijder is niet
        //   meer 'publiek anoniem' of volgbaar; die vlaggen horen niet te blijven)
        $stmt = $pdo->prepare("
            UPDATE persons
            SET full_name       = 'Verwijderd',
                short_name      = NULL,
                city            = NULL,
                sponsor         = NULL,
                club_code       = NULL,
                club_short      = NULL,
                club_full       = NULL,
                start_number    = NULL,
                nationality     = NULL,
                publiek_anoniem = NULL,
                volg_token      = NULL,
                anonymized_at   = NOW()
            WHERE person_id = ?
        ");
        $stmt->execute([$pid]);

        // Recht op vergetelheid: óók ALLE externe-id-koppelingen wissen. De
        // DELETE filtert bewust NIET op systeem, dus dit dekt de KNSB-licentie
        // én elke toekomstige koppeling (bv. skateresults.app, World Skate, of
        // andere systemen waarmee we ID's uitwisselen). Zo blijft ná anonimisering
        // alléén het interne, niet-herleidbare person_id over als sleutel voor de
        // (naamloze) historische uitslagen; met een externe ledendatabase is er
        // dan niets meer naar de rijder te herleiden.
        $pdo->prepare("DELETE FROM person_external_ids WHERE person_id = ?")->execute([$pid]);

        // Óók: alle toegewezen_naam-referenties in organisatie_transponders
        // en de transponder-toewijzing zelf leegmaken. Wedstrijd-entries en
        // results blijven staan (gekoppeld via het interne person_id; daar is
        // geen naam opgeslagen).
        $pdo->prepare("
            UPDATE organisatie_transponders
            SET toegewezen_naam = NULL,
                toegewezen_snr  = NULL,
                person_id       = NULL,
                categorie       = NULL,
                betaald         = 0,
                betaald_op      = NULL
            WHERE person_id = ?
        ")->execute([$pid]);

        // Óók: alle per-wedstrijd transponder-registraties van deze rijder wissen
        // (`transponders`-tabel). Een transpondercode zelf is geen persoonsgegeven,
        // maar via de MyLaps-historie is een code alsnog aan een naam te herleiden —
        // dus de code↔person_id-koppeling verwijderen we. person_id is daar NOT NULL
        // (deel van de unique+FK), dus nullen kan niet → rijen verwijderen. Raakt
        // geen uitslagen (die hangen aan person_id, niet aan de transponder).
        $pdo->prepare("DELETE FROM transponders WHERE person_id = ?")->execute([$pid]);

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

    // NB: er is bewust GEEN 'undo'-actie. Anonimiseren is onomkeerbaar (recht op
    // vergetelheid): naam/club/licentie/transponders zijn gewist en er is geen
    // sleutel meer om de rijder aan een her-import te koppelen. De beveiliging
    // tegen 'verkeerde rijder' is de bevestigingsdialoog vóóraf, niet een undo.

    if ($action === 'publiek_anoniem_aan') {
        // Omkeerbare publieke anonimiteit AAN. COALESCE bewaart een reeds
        // gezette (audit-)datum bij een herhaalde klik.
        $pdo->prepare("UPDATE persons SET publiek_anoniem = COALESCE(publiek_anoniem, NOW()) WHERE person_id = ?")
            ->execute([$pid]);
        if (function_exists('logboekSchrijf')) {
            logboekSchrijf($pdo, $_authUser['id'] ?? null,
                'publiek_anoniem_aan', ['license_key' => $lk]);
        }
        echo json_encode([
            'ok'      => true,
            'message' => 'Rijder is nu publiek anoniem. Naam en club worden buiten de wedstrijddagen gemaskeerd; de gegevens blijven behouden.',
        ]);
        exit;
    }

    if ($action === 'publiek_anoniem_uit') {
        // Omkeerbare publieke anonimiteit UIT (data was nooit weg). Het volg-ID
        // heeft alleen betekenis bij anonimiteit → mee wissen; weer aanzetten
        // levert bewust een vers ID op (oude volgers zijn dan afgesneden).
        $pdo->prepare("UPDATE persons SET publiek_anoniem = NULL, volg_token = NULL WHERE person_id = ?")
            ->execute([$pid]);
        if (function_exists('logboekSchrijf')) {
            logboekSchrijf($pdo, $_authUser['id'] ?? null,
                'publiek_anoniem_uit', ['license_key' => $lk]);
        }
        echo json_encode([
            'ok'      => true,
            'message' => 'Publieke anonimiteit opgeheven. De rijder is weer met naam zichtbaar.',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
