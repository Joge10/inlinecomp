<?php
// ============================================================
//  InlineComp – publieke meldingen-beheer
//
//  GET ?comp_id=X                       → actieve meldingen voor wedstrijd
//                                         INCLUSIEF actieve globale meldingen
//                                         (geldig_van <= NOW <= geldig_tot)
//  GET ?global=1                        → alleen actieve globale meldingen
//                                         (voor landing-page van public/coach
//                                         zonder geselecteerde wedstrijd)
//  GET ?action=lijst&comp_id=X          → ALLE meldingen voor wedstrijd
//                                         (incl. verlopen + toekomstig — voor admin)
//  GET ?action=lijst&global=1           → ALLE globale meldingen (admin)
//  POST action=save                     → aanmaken of bijwerken
//                                         { id?, comp_id|global, titel, bericht,
//                                           prio, geldig_van?, geldig_tot? }
//  POST action=delete                   → verwijderen { id }
//
//  GET zonder login is publiek (public + coach apps lezen mee).
//  POST/DELETE alleen voor owner/admin/timer/planner.
//  Globale meldingen aanmaken: alleen owner/admin (impact op alle bezoekers).
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// Default: no-cache (admin-acties + POST/DELETE moeten altijd door).
// De publieke GET-poll (regel ~67) krijgt EXPLICIET een korte cache + server-
// side mini-cache om EP-bursts op te vangen wanneer veel apps tegelijk open
// gaan op iFastNet shared hosting.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config_inlinecomp.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET is publiek; POST vereist login + rol
if ($method !== 'GET') {
    require_once __DIR__ . '/../auth/session.php';
    $_authUser = requireAuth($pdo);
    $magSchrijven = in_array($_authUser['role'] ?? '',
        ['owner', 'admin', 'timer', 'planner'], true);
    if (!$magSchrijven) {
        http_response_code(403);
        echo json_encode(['error' => 'Geen schrijfrechten voor meldingen.']);
        exit;
    }
}

function uuid4_m(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// Wist alle 30s-cache-files na save/delete zodat operator-wijzigingen
// direct doorkomen ipv tot 30s wachten. Globale meldingen raken alle
// caches (we weten niet welke wedstrijden de melding ziet zonder query),
// wedstrijd-specifieke wist alleen die wedstrijds eigen cache + globaal.
// Best-effort: faalt stil als de cache-dir restrict is.
function _wisMeldingCache(?string $compId = null): void {
    $dir = sys_get_temp_dir();
    if ($compId === null) {
        // Onbekende impact → alle melding-caches weg (prefix m_)
        foreach (glob($dir . '/m_*') ?: [] as $f) @unlink($f);
        return;
    }
    @unlink($dir . '/m_' . md5('G'));         // globale-only cache
    @unlink($dir . '/m_' . md5('C:' . $compId)); // wedstrijd-specifieke
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    // ── GET: actieve meldingen (default — voor publieke poll) ──────────────
    // Sortering: chronologisch — oudste melding eerst. Zo bouwt een laat-
    // komer het historische verhaal in de juiste volgorde op (bv. eerst
    // "uitstel tot 13:00", dan "uitstel verlengd tot 14:00", dan "drogen
    // ging voorspoediger, herstart 13:45"). De laatste pop-up is dan
    // altijd de meest actuele stand van zaken.
    if ($method === 'GET' && $action === '') {
        $compId   = trim($_GET['comp_id'] ?? '');
        $alleenGlobal = !empty($_GET['global']);

        // ── Server-cache 30s ──────────────────────────────────────────────
        // Bij parallel app-opens (5+ telefoons binnen 1 seconde) krijgt elke
        // poll dezelfde data — geen reden om 5× de DB te raken. Kleine
        // file-cache met 30s TTL deduplikeert dat. Meldingen mogen 30s oud
        // zijn (niet realtime kritisch). EP-burst gemitigeerd zonder hash-
        // berekeningen of complexe locking.
        $cacheTtl  = 30;
        $cacheKey  = 'm_' . md5(($alleenGlobal ? 'G' : 'C:' . $compId));
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                // Override no-store met korte public-cache (browser + CDN).
                header_remove('Cache-Control');
                header_remove('Pragma');
                header_remove('Expires');
                header('Cache-Control: public, max-age=' . $cacheTtl);
                echo $cached;
                exit;
            }
        }

        if ($alleenGlobal) {
            // Landing-page van public/coach — alleen globale meldingen.
            $stmt = $pdo->prepare("
                SELECT id, titel, bericht, titel_en, bericht_en, titel_de, bericht_de, titel_fr, bericht_fr, prio, geldig_van, geldig_tot,
                       NULL AS competition_id
                FROM public_meldingen
                WHERE competition_id IS NULL
                  AND geldig_van <= NOW()
                  AND (geldig_tot IS NULL OR geldig_tot >= NOW())
                ORDER BY geldig_van ASC
            ");
            $stmt->execute();
        } elseif ($compId !== '') {
            // Wedstrijd-pagina — wedstrijd-specifiek + globaal samen.
            $stmt = $pdo->prepare("
                SELECT id, titel, bericht, titel_en, bericht_en, titel_de, bericht_de, titel_fr, bericht_fr, prio, geldig_van, geldig_tot,
                       competition_id
                FROM public_meldingen
                WHERE (competition_id = ? OR competition_id IS NULL)
                  AND geldig_van <= NOW()
                  AND (geldig_tot IS NULL OR geldig_tot >= NOW())
                ORDER BY geldig_van ASC
            ");
            $stmt->execute([$compId]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'comp_id of global=1 verplicht']);
            exit;
        }
        $json = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        // Cache schrijven (best-effort, faalt stil bij rechten-issues).
        @file_put_contents($cacheFile, $json, LOCK_EX);
        // Override no-store voor de poll — opvolgende polls binnen 30s
        // mogen vanuit browser-cache (geen server-roundtrip).
        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: public, max-age=' . $cacheTtl);
        echo $json;
        exit;
    }

    // ── GET: volledige lijst voor admin (incl. verlopen + toekomst) ────────
    if ($method === 'GET' && $action === 'lijst') {
        $compId   = trim($_GET['comp_id'] ?? '');
        $isGlobal = !empty($_GET['global']);
        if ($isGlobal) {
            $stmt = $pdo->prepare("
                SELECT id, titel, bericht, titel_en, bericht_en, titel_de, bericht_de, titel_fr, bericht_fr, prio, geldig_van, geldig_tot,
                       aangemaakt_door, aangemaakt_op,
                       NULL AS competition_id
                FROM public_meldingen
                WHERE competition_id IS NULL
                ORDER BY geldig_van DESC
            ");
            $stmt->execute();
        } elseif ($compId !== '') {
            // Wedstrijd-modal: tonen wedstrijd-specifieke + globale samen,
            // zodat een admin globale meldingen vanuit elke wedstrijd-context
            // kan zien én snel verwijderen als ze tegenstrijdig zijn.
            $stmt = $pdo->prepare("
                SELECT id, titel, bericht, titel_en, bericht_en, titel_de, bericht_de, titel_fr, bericht_fr, prio, geldig_van, geldig_tot,
                       aangemaakt_door, aangemaakt_op, competition_id
                FROM public_meldingen
                WHERE competition_id = ? OR competition_id IS NULL
                ORDER BY (competition_id IS NULL) DESC, geldig_van DESC
            ");
            $stmt->execute([$compId]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'comp_id of global=1 verplicht']);
            exit;
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── POST acties ─────────────────────────────────────────────────────────
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Methode niet toegestaan']);
        exit;
    }

    if ($action === 'save') {
        $mid      = trim($_POST['id']         ?? '');
        $compId   = trim($_POST['comp_id']    ?? '');
        $isGlobal = !empty($_POST['global']);
        $titel     = trim($_POST['titel']      ?? '');
        $bericht   = trim($_POST['bericht']    ?? '');
        // Vertaalde velden zijn optioneel — leeg = fallback naar EN→NL bij
        // publieke render. Public-app is 4-talig (NL/EN/DE/FR); meldingen
        // worden bij save via Claude AI auto-vertaald in alle 3 doeltalen.
        $titelEn   = trim($_POST['titel_en']   ?? '');
        $berichtEn = trim($_POST['bericht_en'] ?? '');
        $titelDe   = trim($_POST['titel_de']   ?? '');
        $berichtDe = trim($_POST['bericht_de'] ?? '');
        $titelFr   = trim($_POST['titel_fr']   ?? '');
        $berichtFr = trim($_POST['bericht_fr'] ?? '');
        $titelEn   = $titelEn   === '' ? null : $titelEn;
        $berichtEn = $berichtEn === '' ? null : $berichtEn;
        $titelDe   = $titelDe   === '' ? null : $titelDe;
        $berichtDe = $berichtDe === '' ? null : $berichtDe;
        $titelFr   = $titelFr   === '' ? null : $titelFr;
        $berichtFr = $berichtFr === '' ? null : $berichtFr;
        $prio      = trim($_POST['prio']       ?? 'info');
        $vanRaw    = trim($_POST['geldig_van'] ?? '');
        $totRaw    = trim($_POST['geldig_tot'] ?? '') ?: null;

        // Globale meldingen alleen voor owner/admin (niet voor timer/planner —
        // die kunnen impact op alle organisaties hebben).
        if ($isGlobal && !in_array($_authUser['role'] ?? '', ['owner','admin'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Alleen owner/admin mag globale meldingen maken.']);
            exit;
        }
        if (!$isGlobal && $compId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'comp_id of global=1 verplicht']);
            exit;
        }
        if ($titel === '' || $bericht === '') {
            http_response_code(400);
            echo json_encode(['error' => 'titel en bericht zijn verplicht']);
            exit;
        }
        if (!in_array($prio, ['info', 'warn', 'urgent'], true)) $prio = 'info';

        // Normaliseer datetime-velden — accepteer 'YYYY-MM-DD HH:MM' (lokaal)
        // en zet om naar MySQL-formaat. Default: nu (geldig_van) en NULL (geldig_tot).
        $van = $vanRaw ? date('Y-m-d H:i:s', strtotime($vanRaw)) : date('Y-m-d H:i:s');
        $tot = $totRaw ? date('Y-m-d H:i:s', strtotime($totRaw)) : null;

        $compIdDb = $isGlobal ? null : $compId;

        if ($mid === '') {
            $mid = uuid4_m();
            $pdo->prepare("
                INSERT INTO public_meldingen
                       (id, competition_id, titel, bericht,
                        titel_en, bericht_en, titel_de, bericht_de, titel_fr, bericht_fr,
                        prio, geldig_van, geldig_tot, aangemaakt_door)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $mid, $compIdDb, $titel, $bericht,
                $titelEn, $berichtEn, $titelDe, $berichtDe, $titelFr, $berichtFr,
                $prio, $van, $tot, $_authUser['id'] ?? null,
            ]);
        } else {
            // UPDATE met scope-check: globale melding alleen via global=1,
            // wedstrijd-specifieke alleen via overeenkomstige comp_id.
            if ($isGlobal) {
                $pdo->prepare("
                    UPDATE public_meldingen
                    SET titel = ?, bericht = ?,
                        titel_en = ?, bericht_en = ?, titel_de = ?, bericht_de = ?,
                        titel_fr = ?, bericht_fr = ?,
                        prio = ?, geldig_van = ?, geldig_tot = ?
                    WHERE id = ? AND competition_id IS NULL
                ")->execute([$titel, $bericht,
                    $titelEn, $berichtEn, $titelDe, $berichtDe, $titelFr, $berichtFr,
                    $prio, $van, $tot, $mid]);
            } else {
                $pdo->prepare("
                    UPDATE public_meldingen
                    SET titel = ?, bericht = ?,
                        titel_en = ?, bericht_en = ?, titel_de = ?, bericht_de = ?,
                        titel_fr = ?, bericht_fr = ?,
                        prio = ?, geldig_van = ?, geldig_tot = ?
                    WHERE id = ? AND competition_id = ?
                ")->execute([$titel, $bericht,
                    $titelEn, $berichtEn, $titelDe, $berichtDe, $titelFr, $berichtFr,
                    $prio, $van, $tot, $mid, $compId]);
            }
        }
        // Cache-wissen na save: alle melding-caches (we weten niet zeker
        // welke comp_id het was bij update zonder extra query — simpeler om
        // ALLE 30s-caches te verversen, kostprijs is 1 DB-call extra bij
        // de volgende poll van elke wedstrijd).
        _wisMeldingCache(null);
        echo json_encode(['ok' => true, 'id' => $mid]);
        exit;
    }

    if ($action === 'delete') {
        $mid = trim($_POST['id'] ?? '');
        if ($mid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'id ontbreekt']);
            exit;
        }
        $pdo->prepare("DELETE FROM public_meldingen WHERE id = ?")->execute([$mid]);
        _wisMeldingCache(null);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Onbekende actie']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
