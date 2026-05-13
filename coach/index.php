<?php
// ============================================================
//  InlineComp – Coach-view
//  Geen login vereist. Coach selecteert rijders op club / sponsor /
//  startnummer, en ziet per heat welke van zijn rijders erin zitten.
// ============================================================
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config_inlinecomp.php';

// ── Bezoektracking: upsert session-hit in coach_visits ──────────────────────
// Alleen op de echte HTML pageload (geen action=...) om AJAX-calls niet
// dubbel te tellen. Aparte session-cookie (ICCOACH) zodat /coach- en
// /public-sessies los getracked worden.
if (empty($_GET['action'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('ICCOACH');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
        @session_start();
    }
    $sid = session_id();
    if ($sid) {
        try {
            $pdo->prepare(
                "INSERT INTO coach_visits (session_id) VALUES (?)
                 ON DUPLICATE KEY UPDATE last_seen = NOW(), hits = hits + 1"
            )->execute([$sid]);
            // Piek-tracking (vandaag + all-time) — zelfde patroon als /public
            $pdo->prepare("
                UPDATE peak_stats SET
                    peak_today = CASE
                        WHEN peak_today_date = CURDATE()
                            THEN GREATEST(peak_today, (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE))
                        ELSE (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE)
                    END,
                    peak_today_date = CURDATE(),
                    peak_all_time_at = IF(
                        (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE) > peak_all_time,
                        NOW(), peak_all_time_at),
                    peak_all_time = GREATEST(peak_all_time,
                        (SELECT COUNT(*) FROM coach_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE))
                WHERE scope = 'coach'
            ")->execute();
        } catch (Throwable $e) { /* tracking mag nooit de pagina breken */ }
    }
}

// ── Wedstrijd-zichtbaarheidsgate ─────────────────────────────────────────────
// Coach toont alleen wedstrijden waarvoor public_zichtbaar=1. De
// competitions-list-action filtert zelf al; deze gate beschermt single-
// comp endpoints (programma, lookup, uitslagen, etc.) tegen URL-pluk
// van een wedstrijd in voorbereidingsfase.
function _coachWedstrijdZichtbaar(PDO $pdo, string $compId): bool {
    if (!$compId) return true;
    $s = $pdo->prepare("SELECT public_zichtbaar FROM competitions WHERE id = ? LIMIT 1");
    $s->execute([$compId]);
    return (bool)$s->fetchColumn();
}
// Cache POST body: coach_info-action gebruikt 'm óók (file_get_contents
// op php://input kan maar één keer gelezen worden).
$_POST_BODY = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST_BODY = json_decode(file_get_contents('php://input'), true) ?: [];
}
$_zichtCompId = trim($_GET['competition_id'] ?? ($_POST_BODY['competition_id'] ?? ''));
if ($_zichtCompId && !_coachWedstrijdZichtbaar($pdo, $_zichtCompId)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['error' => 'Wedstrijd niet beschikbaar']);
    exit;
}

// ── Rate limiting: max 10 requests per 5 seconden per IP ─────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlFile = sys_get_temp_dir() . '/rlcoach_' . md5($ip);
    $now = time();
    $hits = @json_decode(@file_get_contents($rlFile), true);
    if (!is_array($hits)) $hits = [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 5));
    if (count($hits) >= 10) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(429);
        echo json_encode(['error' => 'Te veel verzoeken — wacht even']);
        exit;
    }
    $hits[] = $now;
    @file_put_contents($rlFile, json_encode($hits));
}

// ── API: wedstrijden ─────────────────────────────────────────────────────────
if ($action === 'competitions') {
    header('Content-Type: application/json; charset=utf-8');
    // Was 60s cache, maar publiek_zichtbaar kan tussentijds wijzigen
    // (operator publiceert wedstrijd kort voor start). 30s is veilig
    // genoeg en houdt server-belasting laag.
    header('Cache-Control: public, max-age=30');
    try {
        // Baan-velden gebruiken cross-org-fallback: als deze org's baan-rij
        // geen logo of geen vereniging-naam heeft, pakken we die uit een
        // andere org-rij met dezelfde baan-naam (zelfde fysieke locatie).
        // Identiek aan /public.
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.starts, c.ends,
                   c.organisatie_id, o.logo_path AS org_logo, o.naam AS org_naam,
                   c.baan_id, c.public_zichtbaar,
                   COALESCE(b.logo_path, (
                       SELECT b2.logo_path FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                       LIMIT 1
                   )) AS baan_logo,
                   COALESCE(b.vereniging_naam, (
                       SELECT b2.vereniging_naam FROM banen b2
                       WHERE b2.naam = b.naam AND b2.id != b.id
                         AND b2.vereniging_naam IS NOT NULL AND b2.vereniging_naam != ''
                       LIMIT 1
                   )) AS baan_vereniging
            FROM competitions c
            JOIN competition_tijdschema ct ON ct.competition_id = c.id
            LEFT JOIN organisaties o ON o.id = c.organisatie_id
            LEFT JOIN banen b ON b.id = c.baan_id
            ORDER BY c.starts DESC
        ");
        $stmt->execute();
        $comps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Sponsors per organisatie (zelfde aanpak als /public) — voor footer
        $orgIds = array_unique(array_filter(array_column($comps, 'organisatie_id')));
        $sponsorMap = [];
        if ($orgIds) {
            $spStmt = $pdo->prepare("
                SELECT organisatie_id, naam, logo_path, url
                FROM organisatie_sponsors
                WHERE logo_path IS NOT NULL AND logo_path != ''
                ORDER BY volgorde, naam
            ");
            $spStmt->execute();
            foreach ($spStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                $sponsorMap[$sp['organisatie_id']][] = [
                    'naam' => $sp['naam'], 'logo' => $sp['logo_path'], 'url' => $sp['url'],
                ];
            }
        }
        // Baan-sponsors (per baan_id) — verschijnen achter de org-sponsors
        $baanIds = array_unique(array_filter(array_column($comps, 'baan_id')));
        $baanSponsorMap = [];
        if ($baanIds) {
            $ph = implode(',', array_fill(0, count($baanIds), '?'));
            $bsStmt = $pdo->prepare("
                SELECT baan_id, naam, logo_path, url
                FROM baan_sponsors
                WHERE baan_id IN ($ph)
                  AND logo_path IS NOT NULL AND logo_path != ''
                ORDER BY volgorde, naam
            ");
            $bsStmt->execute(array_values($baanIds));
            foreach ($bsStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                $baanSponsorMap[$sp['baan_id']][] = [
                    'naam' => $sp['naam'], 'logo' => $sp['logo_path'], 'url' => $sp['url'],
                ];
            }
        }
        foreach ($comps as &$c) {
            $org  = $sponsorMap[$c['organisatie_id'] ?? ''] ?? [];
            $baan = $baanSponsorMap[$c['baan_id'] ?? ''] ?? [];
            $c['sponsors'] = array_merge($org, $baan);
        }
        unset($c);
        echo json_encode($comps, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: clubs in deze wedstrijd ─────────────────────────────────────────────
if ($action === 'clubs') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode([]); exit; }
    try {
        // Per club_full ook een club_short meenemen (bv. "ASV") voor de
        // weergave in de dropdown: "ASV - Almeerse SchaatsVereniging".
        // Als er meerdere short-varianten zijn voor hetzelfde full-label,
        // nemen we MIN() (stabiel) — komt in de praktijk bijna niet voor.
        //
        // Sortering: eerst op club_short (alfabetisch op afkorting), met
        // club_full als fallback wanneer er geen short is. Dat past beter
        // bij hoe coaches scannen — ze zoeken meestal op afkorting.
        $stmt = $pdo->prepare("
            SELECT p.club_full AS full,
                   MIN(NULLIF(p.club_short, '')) AS short
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            WHERE dc.competition_id = ?
              AND p.club_full IS NOT NULL AND p.club_full != ''
            GROUP BY p.club_full
            ORDER BY COALESCE(NULLIF(MIN(p.club_short), ''), p.club_full)
        ");
        $stmt->execute([$compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: sponsors in deze wedstrijd ──────────────────────────────────────────
if ($action === 'sponsors') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode([]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            WHERE dc.competition_id = ?
              AND p.sponsor IS NOT NULL AND p.sponsor != ''
            ORDER BY p.sponsor
        ");
        $stmt->execute([$compId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijders op club ─────────────────────────────────────────────────────
if ($action === 'personen_by_club') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $club   = trim($_GET['club'] ?? '');
    if (!$compId || !$club) { echo json_encode([]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ? AND p.club_full = ?
            ORDER BY snr
        ");
        $stmt->execute([$compId, $club]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijders op sponsor ──────────────────────────────────────────────────
if ($action === 'personen_by_sponsor') {
    header('Content-Type: application/json; charset=utf-8');
    $compId  = trim($_GET['competition_id'] ?? '');
    $sponsor = trim($_GET['sponsor'] ?? '');
    if (!$compId || !$sponsor) { echo json_encode([]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ? AND p.sponsor = ?
            ORDER BY snr
        ");
        $stmt->execute([$compId, $sponsor]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rijder op startnummer ───────────────────────────────────────────────
if ($action === 'person_by_startnummer') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $snr    = (int)($_GET['snr'] ?? 0);
    if (!$compId || !$snr) { echo json_encode(null); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            JOIN persons p ON p.license_key = e.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = e.person_license
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ?
              AND COALESCE(cs.startnummer, p.start_number) = ?
            LIMIT 1
        ");
        $stmt->execute([$compId, $snr]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: null, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: programma (heats + blokken) — zoals /public maar lichter ─────────────
if ($action === 'programma') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=30');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        $tsStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ? LIMIT 1");
        $tsStmt->execute([$compId]);
        $tsId = $tsStmt->fetchColumn();
        if (!$tsId) { echo json_encode(['ritten' => [], 'blokken' => []]); exit; }

        // Ritten + heat_id + rijder-startnummers per heat (zodat JS kan
        // kruisen met de coach-lijst zonder extra roundtrips).
        // Sorteer via de volgorde van het bovenliggende blok (master) en
        // daarbinnen op rit-volgorde. Tijdstip is onbetrouwbaar (niet elke
        // wedstrijd vult dat in).
        $stmt = $pdo->prepare("
            SELECT r.volgorde AS rit_volgorde,
                   b.volgorde AS blok_volgorde,
                   r.blok_id, r.rit_naam, r.ronde_type, r.heat_nr, r.dc_naam,
                   r.combi_group,
                   r.opmerking AS rit_opmerking,
                   r.distance_id AS rit_distance_id, r.afstand_naam AS rit_afstand_naam,
                   b.blok_type, b.tijdstip, b.duur, b.heat_duur, b.opmerking,
                   h.id AS heat_id,
                   h.ronde AS heat_ronde,
                   h.distance_combination_id AS heat_dc_id,
                   COALESCE(h.distance_id, r.distance_id) AS heat_distance_id,
                   (SELECT COUNT(*) FROM heat_entries he2
                    WHERE he2.heat_id = h.id) AS entries_count,
                   (SELECT COUNT(*) FROM results res
                    JOIN heat_entries he ON he.id = res.heat_entry_id
                    WHERE he.heat_id = h.id AND res.finishpositie IS NOT NULL
                   ) AS resultaten_count
            FROM tijdschema_ritten r
            LEFT JOIN tijdschema_blokken b ON b.id = r.blok_id
            LEFT JOIN heats h ON h.tijdschema_rit_id = r.id AND h.competition_id = ?
            WHERE r.tijdschema_id = ?
            ORDER BY b.volgorde, r.volgorde
        ");
        $stmt->execute([$compId, $tsId]);
        $rittenRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Definitief-logica (uit /public):
        //   ronde 1 → definitief zodra er rijders in de heat zitten
        //   ronde > 1 → definitief als er rijders in zitten EN de vorige
        //   ronde compleet is. Runner-up: bron-ronde is de EERSTE deelnemende
        //   ronde (heats / KF / HF), niet de hoogste lagere.
        $rondeCheck = []; // cache per dc + dist + ronde + ronde_type
        $checkVorigeRonde = function($dcId, $distId, $ronde, $rondeType) use ($pdo, $compId, &$rondeCheck) {
            if ($ronde <= 1) return true;
            $ck = "{$dcId}_{$distId}_{$ronde}_{$rondeType}";
            if (isset($rondeCheck[$ck])) return $rondeCheck[$ck];
            $distCond = ($distId !== '' && $distId !== null)
                ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
            $vrParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, $ronde] : [$compId, $dcId, $ronde];
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
            if (!$vr) { $rondeCheck[$ck] = true; return true; }
            $cParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, (int)$vr] : [$compId, $dcId, (int)$vr];
            $s = $pdo->prepare("
                SELECT COUNT(he.id) AS totaal,
                       SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS klaar
                FROM heats h JOIN heat_entries he ON he.heat_id = h.id
                LEFT JOIN results res ON res.heat_entry_id = he.id
                WHERE h.competition_id = ? AND h.distance_combination_id = ?
                  $distCond
                  AND h.ronde = ?
            ");
            $s->execute($cParams);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $ok = $r && (int)$r['totaal'] > 0 && (int)$r['totaal'] === (int)$r['klaar'];
            $rondeCheck[$ck] = $ok;
            return $ok;
        };
        $ritten = [];
        foreach ($rittenRaw as $r) {
            $ronde = (int)($r['heat_ronde'] ?? 0);
            $dcId  = $r['heat_dc_id'] ?? '';
            $distId = $r['heat_distance_id'] ?? '';
            $rondeType = $r['ronde_type'] ?? '';
            $heeftEntries = (int)($r['entries_count'] ?? 0) > 0;
            $r['definitief'] = $heeftEntries && ($ronde <= 1 || $checkVorigeRonde($dcId, $distId, $ronde, $rondeType));
            $ritten[] = $r;
        }

        // Startnummers per heat in één query voor alle heats van deze wedstrijd
        $snrStmt = $pdo->prepare("
            SELECT he.heat_id,
                   COALESCE(cs.startnummer, p.start_number) AS snr
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = he.person_license
                  AND cs.competition_id = h.competition_id
            WHERE h.competition_id = ?
        ");
        $snrStmt->execute([$compId]);
        $snrPerHeat = [];
        foreach ($snrStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hid = (int)$row['heat_id'];
            $snrPerHeat[$hid][] = (int)$row['snr'];
        }
        foreach ($ritten as &$r) {
            $hid = $r['heat_id'] !== null ? (int)$r['heat_id'] : null;
            $r['heat_snrs'] = $hid !== null ? ($snrPerHeat[$hid] ?? []) : [];
        }
        unset($r);

        // Niet-ronde blokken (pauze / ceremonie / inrijden / etc.) —
        // inclusief id en volgorde zodat de frontend ze op hun
        // blok_volgorde-positie kan tussenvoegen tussen de ritten.
        // inrijd_cats is JSON-array van dc_id-strings; we resolven die
        // server-side naar dc-namen.
        $blStmt = $pdo->prepare("
            SELECT id, volgorde, blok_type, duur, heat_duur, inrijd_cats, tijdstip, opmerking
            FROM tijdschema_blokken
            WHERE tijdschema_id = ? AND blok_type != 'ronde'
            ORDER BY volgorde
        ");
        $blStmt->execute([$tsId]);
        $blokken = $blStmt->fetchAll(PDO::FETCH_ASSOC);

        $dcIds = [];
        foreach ($blokken as $b) {
            if (!empty($b['inrijd_cats'])) {
                $arr = json_decode($b['inrijd_cats'], true);
                if (is_array($arr)) foreach ($arr as $id) $dcIds[(string)$id] = true;
            }
        }
        $dcNamen = [];
        if ($dcIds) {
            $ph = implode(',', array_fill(0, count($dcIds), '?'));
            $dn = $pdo->prepare("SELECT id, name FROM distance_combinations WHERE id IN ($ph)");
            $dn->execute(array_keys($dcIds));
            foreach ($dn->fetchAll(PDO::FETCH_ASSOC) as $r) $dcNamen[(string)$r['id']] = $r['name'];
        }
        foreach ($blokken as &$b) {
            $b['inrijd_cat_namen'] = '';
            if (!empty($b['inrijd_cats'])) {
                $arr = json_decode($b['inrijd_cats'], true);
                if (is_array($arr)) {
                    $namen = array_map(fn($id) => $dcNamen[(string)$id] ?? (string)$id, $arr);
                    $b['inrijd_cat_namen'] = implode(', ', $namen);
                }
            }
        }
        unset($b);

        echo json_encode(['ritten' => $ritten, 'blokken' => $blokken], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rit detail (startlijst van één heat) ────────────────────────────────
// ── API: categorieen met uitslagen (1-op-1 uit /public) ─────────────────────
if ($action === 'categorieen') {
    header('Content-Type: application/json; charset=utf-8');
    // klassement_beschikbaar-vlag verandert bij publish/intrek; geen cache.
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT ua.distance_combination_id AS dc_id, ua.dc_naam,
                   ua.distance_id, ua.distance_naam
            FROM uitslag_afstand ua
            WHERE ua.competition_id = ?
            GROUP BY ua.distance_combination_id, ua.distance_id
            ORDER BY ua.dc_naam, ua.distance_naam
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Filter op gepubliceerde klassementen — operator publiceert
        // expliciet via /Klassement na controle. Niet-gepubliceerde
        // klassementen zijn alleen zichtbaar in admin.
        $klasStmt = $pdo->prepare("
            SELECT DISTINCT uk.distance_combination_id
            FROM uitslag_klassement uk
            INNER JOIN klassement_config kc
                    ON kc.competition_id = uk.competition_id
                   AND kc.dc_id = uk.distance_combination_id
                   AND kc.gepubliceerd_at IS NOT NULL
            WHERE uk.competition_id = ?
        ");
        $klasStmt->execute([$compId]);
        $klasDcIds = $klasStmt->fetchAll(PDO::FETCH_COLUMN);
        $result = [];
        foreach ($rows as $r) {
            $dcId = $r['dc_id'];
            if (!isset($result[$dcId])) {
                $result[$dcId] = [
                    'dc_id' => $dcId, 'dc_naam' => $r['dc_naam'],
                    'afstanden' => [],
                    'klassement_beschikbaar' => in_array($dcId, $klasDcIds),
                ];
            }
            $result[$dcId]['afstanden'][] = [
                'distance_id' => $r['distance_id'],
                'distance_naam' => $r['distance_naam'],
            ];
        }
        echo json_encode(array_values($result), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: volledige uitslag per afstand of klassement (1-op-1 uit /public) ────
if ($action === 'uitslagen') {
    header('Content-Type: application/json; charset=utf-8');
    // Uitslag/klassement-publicatie kan per minuut wijzigen; geen cache.
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    $dcId   = trim($_GET['dc_id'] ?? '');
    $type   = trim($_GET['type'] ?? 'afstand');
    $distId = trim($_GET['distance_id'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }
    try {
        if ($type === 'klassement') {
            // Pre-check: alleen gepubliceerde klassementen tonen
            $pubStmt = $pdo->prepare("
                SELECT 1 FROM klassement_config
                WHERE competition_id = ? AND dc_id = ? AND gepubliceerd_at IS NOT NULL
                LIMIT 1
            ");
            $pubStmt->execute([$compId, $dcId]);
            if (!$pubStmt->fetchColumn()) {
                echo json_encode(['rijders' => [], 'afstanden' => [], 'niet_gepubliceerd' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT t.rang, t.punten_totaal, t.dc_naam, t.punten_detail,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr
                FROM uitslag_klassement t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_license
                    FROM uitslag_klassement
                    WHERE competition_id = ? AND distance_combination_id = ?
                    GROUP BY person_license
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.license_key = t.person_license
                LEFT JOIN competition_startnummers cs ON cs.person_license = t.person_license AND cs.competition_id = ?
                -- Uitgesloten rijders (rang IS NULL) sorteren naar het eind.
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang, t.punten_totaal
            ");
            $stmt->execute([$compId, $dcId, $compId]);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $afstanden = [];
            foreach ($rijders as &$r) {
                $r['punten_totaal'] = $r['punten_totaal'] !== null ? (float)$r['punten_totaal'] : null;
                $detail = json_decode($r['punten_detail'], true) ?? [];
                $r['punten_detail'] = $detail;
                foreach (array_keys($detail) as $dn) {
                    if (!in_array($dn, $afstanden)) $afstanden[] = $dn;
                }
            }
            unset($r);
            echo json_encode(['rijders' => $rijders, 'afstanden' => $afstanden], JSON_UNESCAPED_UNICODE);
        } else {
            if (!$distId) { echo json_encode(['error' => 'distance_id verplicht']); exit; }
            $stmt = $pdo->prepare("
                SELECT t.rang, t.finale_naam, t.tijd_ms, t.sanctie, t.distance_naam,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr,
                       res_agg.rondes, res_agg.pk_punten
                FROM uitslag_afstand t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_license
                    FROM uitslag_afstand
                    WHERE competition_id = ? AND distance_combination_id = ? AND distance_id = ?
                    GROUP BY person_license
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.license_key = t.person_license
                LEFT JOIN competition_startnummers cs ON cs.person_license = t.person_license AND cs.competition_id = ?
                LEFT JOIN (
                    SELECT he.person_license, res.rondes, res.punten AS pk_punten
                    FROM heat_entries he
                    JOIN heats h ON h.id = he.heat_id
                    JOIN results res ON res.heat_entry_id = he.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      AND COALESCE(h.distance_id, '') = ?
                      AND (res.rondes IS NOT NULL OR res.punten IS NOT NULL)
                    ORDER BY res.id DESC
                ) res_agg ON res_agg.person_license = t.person_license
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang
            ");
            $stmt->execute([$compId, $dcId, $distId, $compId, $compId, $dcId, $distId]);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $seen = []; $unique = [];
            foreach ($rijders as $r) {
                $lic = $r['full_name'] . $r['snr'];
                if (isset($seen[$lic])) continue;
                $seen[$lic] = true; $unique[] = $r;
            }
            $heeftRnd = !empty(array_filter($unique, fn($r) => $r['rondes'] !== null));
            $heeftPK  = !empty(array_filter($unique, fn($r) => $r['pk_punten'] !== null));
            echo json_encode([
                'rijders' => $unique, 'heeft_rondes' => $heeftRnd, 'heeft_pk_punten' => $heeftPK,
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: coach_info — status + sancties voor een set licenties ──────────────
//    POST {competition_id, licenses:[...]}  (POST vanwege mogelijke lengte)
if ($action === 'coach_info') {
    header('Content-Type: application/json; charset=utf-8');
    // POST body is al gelezen door de zichtbaarheidsgate bovenaan
    $body    = $_POST_BODY ?? [];
    $compId  = trim($body['competition_id'] ?? '');
    $licenses = is_array($body['licenses'] ?? null) ? $body['licenses'] : [];
    $licenses = array_values(array_filter(array_map('strval', $licenses)));
    if (!$compId || !$licenses) { echo json_encode(['personen' => []]); exit; }
    try {
        $ph = implode(',', array_fill(0, count($licenses), '?'));
        // Per rijder: worst-case status (hoogste entry.status → "niet getekend" (4) is belangrijkst).
        // We nemen MAX(status); 4=niet getekend valt altijd op.
        $stStmt = $pdo->prepare("
            SELECT p.license_key,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.full_name, p.category, p.club_full, p.sponsor,
                   MAX(e.status) AS entry_status
            FROM persons p
            JOIN entries e ON e.person_license = p.license_key
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = p.license_key
                  AND cs.competition_id = dc.competition_id
            WHERE dc.competition_id = ? AND p.license_key IN ($ph)
            GROUP BY p.license_key
        ");
        $stStmt->execute(array_merge([$compId], $licenses));
        $personen = [];
        foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['sancties'] = [];
            $row['heats']    = [];
            $personen[$row['license_key']] = $row;
        }

        // Heats per licentie — voor de "Heats"-tab.
        // Per rijder tonen we in welke heats hij is ingedeeld, inclusief
        // ronde-type, rit-naam en afstand. De query geeft voor rijders die
        // nog nergens in zitten geen rijen terug (frontend toont dan "nog niet
        // ingedeeld"). We nemen ook de bestaande rondes van zijn DC's mee
        // zodat we in JS kunnen zien welke rondes ontbreken.
        // Sorteer in dezelfde volgorde als de programma-tab:
        //   blok.volgorde (master) → rit.volgorde (tiebreaker). Zo verschijnen
        //   ritten per rijder in de chronologische wedstrijd-volgorde.
        $heatStmt = $pdo->prepare("
            SELECT he.person_license,
                   h.id AS heat_id, h.ronde, h.heat_naam,
                   h.distance_combination_id AS dc_id,
                   COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   COALESCE(tsr.rit_naam, h.heat_naam) AS rit_naam,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   COALESCE(tsr.dc_naam, '') AS dc_naam,
                   d.name AS afstand_naam,
                   he.startpositie,
                   b.volgorde AS blok_volgorde,
                   tsr.volgorde AS rit_volgorde
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            LEFT JOIN tijdschema_blokken b ON b.id = tsr.blok_id
            LEFT JOIN distances d ON d.id = COALESCE(h.distance_id, tsr.distance_id)
                                 AND d.distance_combination_id = h.distance_combination_id
            WHERE h.competition_id = ?
              AND he.person_license IN ($ph)
            ORDER BY b.volgorde, tsr.volgorde, h.id
        ");
        $heatStmt->execute(array_merge([$compId], $licenses));

        // Zelfde "vorige-ronde-compleet"-check als public/index.php's lookup —
        // anders zou een coach KF/HF/Finale-loting al zien voordat de
        // voorgaande ronde verwerkt is. Heats worden NIET verborgen maar
        // gemarkeerd met vorige_niet_compleet=true zodat de frontend een
        // "Vorige ronde nog niet compleet"-placeholder kan tonen.
        // Runner-up: hangt aan de eerste deelnemende ronde, niet aan de
        // hoogste lagere — daarom een aparte tak met MIN(ronde).
        $rondeCompleetCache = [];
        $checkCompleet = function($ronde, $dcId, $distId, $rondeType) use ($pdo, $compId, &$rondeCompleetCache) {
            if ($ronde <= 1) return true;
            $ck = "{$ronde}_{$dcId}_{$distId}_{$rondeType}";
            if (isset($rondeCompleetCache[$ck])) return $rondeCompleetCache[$ck];

            // Filter ook op distance_id — anders kruist de check tussen
            // afstanden binnen dezelfde DC (bv. 1000m HF kan ten onrechte
            // de afvalkoers-finale blokkeren). NULL distance_id matchen we
            // ook (legacy heats voorafgaand aan per-distance-config).
            $distCond = ($distId !== '' && $distId !== null)
                ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
            $vrParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, $ronde]
                : [$compId, $dcId, $ronde];

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
            $vorigeRonde = $vrStmt->fetchColumn();
            if (!$vorigeRonde) { $rondeCompleetCache[$ck] = true; return true; }

            $cParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, (int)$vorigeRonde]
                : [$compId, $dcId, (int)$vorigeRonde];
            $stmt = $pdo->prepare("
                SELECT COUNT(he.id) AS totaal,
                       SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS met_resultaat
                FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                LEFT JOIN results res ON res.heat_entry_id = he.id
                WHERE h.competition_id = ?
                  AND h.distance_combination_id = ?
                  $distCond
                  AND h.ronde = ?
            ");
            $stmt->execute($cParams);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $compleet = $r && (int)$r['totaal'] > 0 && (int)$r['totaal'] === (int)$r['met_resultaat'];
            $rondeCompleetCache[$ck] = $compleet;
            return $compleet;
        };

        foreach ($heatStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $lic = $h['person_license'];
            if (!isset($personen[$lic])) continue;
            $h['vorige_niet_compleet'] = false;
            if ((int)$h['ronde'] > 1
                && !$checkCompleet(
                    (int)$h['ronde'],
                    $h['dc_id'] ?? '',
                    $h['distance_id'] ?? '',
                    $h['ronde_type'] ?? '')) {
                $h['vorige_niet_compleet'] = true;
            }
            $personen[$lic]['heats'][] = $h;
        }

        // Ingeschreven afstanden per rijder (via entries.distance_combination_id).
        // Een afstand is ontbrekend als hij in "ingeschreven" zit maar nog niet
        // in $personen[...]['heats'] → dat toont de frontend als "nog niet ingedeeld".
        // ORDER BY dc.name zodat de DC-volgorde voor elke rijder identiek is —
        // de coach kan dan in één oogopslag zien dat badge-1 altijd dezelfde
        // categorie betreft, badge-2 dezelfde, etc.
        $entStmt = $pdo->prepare("
            SELECT e.person_license,
                   dc.id AS dc_id, dc.name AS dc_naam,
                   e.status AS entry_status
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE dc.competition_id = ?
              AND e.person_license IN ($ph)
            ORDER BY dc.name
        ");
        $entStmt->execute(array_merge([$compId], $licenses));
        foreach ($entStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $lic = $e['person_license'];
            if (!isset($personen[$lic])) continue;
            if (!isset($personen[$lic]['entries'])) $personen[$lic]['entries'] = [];
            $e['afstanden'] = [];
            $personen[$lic]['entries'][] = $e;
        }

        // Alle afstanden per DC ophalen zodat we ook afstanden tonen waarvoor
        // nog géén programma-ritten bestaan (bv. lange afstand waar nog niet
        // voor gelot is). We nemen alle rijen en laten het unique-maken aan
        // PHP over (één distance_id kan meerdere keren voorkomen per DC door
        // target_group-splits).
        $dcIds = [];
        foreach ($personen as $p) {
            foreach (($p['entries'] ?? []) as $e) $dcIds[$e['dc_id']] = true;
        }
        if ($dcIds) {
            $dcList = array_keys($dcIds);
            $dcPhQ  = implode(',', array_fill(0, count($dcList), '?'));
            $dStmt = $pdo->prepare("
                SELECT distance_combination_id AS dc_id,
                       id AS distance_id, name AS distance_naam, number
                FROM distances
                WHERE distance_combination_id IN ($dcPhQ)
                ORDER BY number
            ");
            $dStmt->execute($dcList);
            $afstandenPerDc = [];
            foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $afstandenPerDc[$d['dc_id']][$d['distance_id']] = [
                    'distance_id'   => $d['distance_id'],
                    'distance_naam' => $d['distance_naam'],
                    'number'        => $d['number'],
                ];
            }
            // Verwachte rondes per (dc_id, distance_id) uit tijdschema_cat_config.
            // Hiermee weten we welke rondes "zouden moeten bestaan" zelfs als
            // er nog geen heats zijn geloot — handig voor de coach om vooraf
            // te zien hoeveel rondes een rijder op z'n programma heeft.
            $rondesPerDcDist = []; // [dc_id][distance_id] = ['heats','finale_a',...]
            $ccStmt = $pdo->prepare("
                SELECT cc.dc_id, cc.distance_id,
                       cc.heeft_heats, cc.heeft_kwartfinale, cc.heeft_halve_finale,
                       cc.heeft_runner_up,
                       cc.finale_heats, cc.finale_b_heats
                FROM tijdschema_cat_config cc
                JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
                WHERE ct.competition_id = ?
                  AND cc.dc_id IN ($dcPhQ)
            ");
            $ccStmt->execute(array_merge([$compId], $dcList));
            foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
                $list = [];
                $heeftEersteRonde = false;
                if ((int)$cc['heeft_heats'])        { $list[] = 'heats';        $heeftEersteRonde = true; }
                if ((int)$cc['heeft_kwartfinale'])  { $list[] = 'kwartfinale';  $heeftEersteRonde = true; }
                if ((int)$cc['heeft_halve_finale']) { $list[] = 'halve_finale'; $heeftEersteRonde = true; }
                // Runner-up draait parallel uit eerste-ronde-uitvallers — voor
                // cats die direct in een A-finale starten (geen series/KF/HF)
                // is runner-up zinloos en zou anders ten onrechte een
                // "Vorige ronde nog niet compleet"-placeholder oproepen in
                // de Heats-tab. Matcht de fix in startlist.js bouwSlFlow().
                if ((int)$cc['heeft_runner_up'] && $heeftEersteRonde) $list[] = 'runner_up';
                if ((int)($cc['finale_b_heats'] ?? 0) > 0) $list[] = 'finale_b';
                if ((int)($cc['finale_heats']   ?? 0) > 0) $list[] = 'finale_a';
                $rondesPerDcDist[$cc['dc_id']][$cc['distance_id']] = $list;
            }

            // Let op: PHP-references door geneste arrays zijn foutgevoelig.
            // Hier muteren we expliciet via index op $personen zodat de
            // wijziging gegarandeerd persisteert.
            foreach ($personen as $lic => $persoon) {
                if (empty($persoon['entries'])) continue;
                foreach ($persoon['entries'] as $i => $entry) {
                    $lijst = array_values($afstandenPerDc[$entry['dc_id']] ?? []);
                    foreach ($lijst as $j => $af) {
                        $lijst[$j]['expected_rondes'] =
                            $rondesPerDcDist[$entry['dc_id']][$af['distance_id']] ?? [];
                    }
                    $personen[$lic]['entries'][$i]['afstanden'] = $lijst;
                }
            }
        }

        // Sancties: alle results met sanctie != NULL voor deze licenties in deze wedstrijd
        $saStmt = $pdo->prepare("
            SELECT he.person_license,
                   res.sanctie, res.tijd_ms, res.finishpositie,
                   COALESCE(tsr.rit_naam, h.heat_naam) AS rit_naam,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   COALESCE(tsr.dc_naam, '') AS dc_naam,
                   d.name AS afstand_naam
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            JOIN results res ON res.heat_entry_id = he.id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            LEFT JOIN distances d ON d.id = COALESCE(h.distance_id, tsr.distance_id)
                                 AND d.distance_combination_id = h.distance_combination_id
            WHERE h.competition_id = ?
              AND he.person_license IN ($ph)
              AND res.sanctie IS NOT NULL AND res.sanctie != ''
            ORDER BY h.ronde, tsr.volgorde, h.id
        ");
        $saStmt->execute(array_merge([$compId], $licenses));
        foreach ($saStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $lic = $s['person_license'];
            if (isset($personen[$lic])) $personen[$lic]['sancties'][] = $s;
        }

        echo json_encode(['personen' => array_values($personen)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'rit_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $compId  = trim($_GET['competition_id'] ?? '');
    $ritNaam = trim($_GET['rit_naam'] ?? '');
    if (!$compId || !$ritNaam) { echo json_encode(['heat' => null]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT h.id, h.heat_naam, h.ronde,
                   h.distance_combination_id, COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
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

        // Vorige-ronde-compleet check (zelfde als public/index.php).
        // Vervolgrondes (KF/HF/F/Runner-up) mogen nog niet getoond worden
        // als hun bron-ronde nog niet compleet is. Voor runner-up is de
        // bron-ronde de EERSTE deelnemende ronde (heats / KF / HF), niet
        // gewoon "hoogste lager".
        if ((int)$heat['ronde'] > 1) {
            $rondeType = $heat['ronde_type'] ?? '';
            $dcId = $heat['distance_combination_id'] ?? '';
            $distId = $heat['distance_id'] ?? '';
            $distCond = ($distId !== '' && $distId !== null)
                ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
            $vrParams = ($distId !== '' && $distId !== null)
                ? [$compId, $dcId, $distId, (int)$heat['ronde']]
                : [$compId, $dcId, (int)$heat['ronde']];
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

        $rStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key, p.full_name, p.category, p.club_full, p.sponsor,
                   res.finishpositie, res.tijd_ms, res.sanctie,
                   res.rondes, res.punten AS pk_punten
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = he.person_license
                  AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");
        $rStmt->execute([$compId, $heat['id']]);
        $heat['rijders'] = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['heat' => $heat], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1F4E79">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>InlineComp – Coach</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="manifest.json">
<style>
:root { --blauw:#1F4E79; --middenblauw:#2E75B6; --lichtblauw:#D6E4F0;
        --oranje:#E8630A; --wit:#fff; --tekst:#1a1a1a; --grijs:#f4f6f8;
        --accent:#fff3cd; --accent-bd:#ffc107; }
* { box-sizing:border-box; margin:0; padding:0; }
/* Root op 20px zodat alle rem-maten ±25% groter worden — consistent met /public
   en veel leesbaarder op een telefoon aan de rand van de baan. */
html { font-size:20px; }
body { font-family:'Segoe UI',Arial,sans-serif; color:var(--tekst);
       background:var(--grijs); min-height:100vh; font-size:1rem; }
/* ── Header (1-op-1 uit /public) ── */
header {
    background: var(--blauw);
    color: var(--wit);
    padding: 12px 12px 10px;
    display: flex; flex-direction: column;
}
/* Bovenste rij: 📢 links, titel midden, i + ? rechts. Onderste rij:
   subtitel breeduit gecentreerd. */
.hdr-row-top    { display: flex; align-items: center; gap: 8px; }
.hdr-btns       { display: flex; gap: 6px; flex-shrink: 0; align-items: center; }
.hdr-btns-right { justify-content: flex-end; }
.hdr-spacer     { width: 36px; visibility: hidden; flex-shrink: 0; }
/* Verbinding-banner: rood strookje boven aan zodra netwerk of server eruit ligt */
.conn-banner {
    background: linear-gradient(135deg, #c62828, #b71c1c);
    color: #fff; text-align: center;
    padding: 8px 12px; font-size: .9rem; font-weight: 600;
    box-shadow: 0 2px 4px rgba(0,0,0,.2);
    position: sticky; top: 0; z-index: 500;
    animation: conn-pulse 2s ease-in-out infinite;
}
@keyframes conn-pulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: .82; }
}
.conn-banner small { font-weight: 400; font-size: .78rem; opacity: .85; }
header .hdr-center { flex: 1; min-width: 0; text-align: center; }
header h1 { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
header .sub { font-size: .95rem; opacity: .8; margin-top: 6px; text-align: center; }
@media (max-width: 480px) {
    header { padding: 10px 8px 8px; }
    .hdr-spacer { width: 30px; }
    header h1  { font-size: 1.2rem; }
    header .sub { font-size: .78rem; margin-top: 4px; }
    .btn-help { width: 30px; height: 30px; font-size: 1rem; }
    .btn-meldingen { font-size: .95rem; }
}
.btn-help {
    background: rgba(255,255,255,.2); border: 2px solid rgba(255,255,255,.5);
    color: #fff; width: 36px; height: 36px; border-radius: 50%;
    font-size: 1.2rem; font-weight: 700; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center; font-style: italic;
    flex-shrink: 0;          /* nooit ovaal worden in flex-container */
}
.btn-help:active { background: rgba(255,255,255,.35); }
.btn-meldingen   { font-style: normal; font-size: 1.1rem; position: relative; }
.meld-badge      { position: absolute; top: -4px; right: -4px; background: #d22;
                   color: #fff; font-size: .65rem; font-weight: 700;
                   min-width: 17px; height: 17px; padding: 0 4px; border-radius: 9px;
                   display: flex; align-items: center; justify-content: center;
                   border: 2px solid #fff; line-height: 1; }

/* ── Org footer (1-op-1 uit /public) ── */
.org-footer {
    display: none;
    background: var(--wit); border-top: 1px solid #dde3ea;
    padding: 12px 16px;
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
    box-shadow: 0 -2px 8px rgba(0,0,0,.08);
}
.org-footer-inner {
    max-width: 720px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.org-footer-logo { height: 50px; width: auto; object-fit: contain; flex-shrink: 0; }
.org-footer-naam { font-size: .85rem; color: var(--blauw); font-weight: 600; flex-shrink: 0; }
.org-footer-sponsors { flex: 1; overflow: hidden; display: flex; align-items: center; justify-content: flex-end; }
.sponsor-marquee { display: flex; overflow: hidden; height: 50px; align-items: center; }
.sponsor-marquee-inner {
    display: flex; align-items: center; gap: 40px; flex-shrink: 0;
    animation: marquee linear infinite;
}
.sponsor-marquee-inner img { height: 40px; width: auto; object-fit: contain; flex-shrink: 0; }
@keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(calc(-50% - 20px)); } }
/* Body krijgt bottom-padding zodra de footer zichtbaar is, zodat de inhoud
   niet onder de fixed footer wegvalt. */
body.heeft-footer .container { padding-bottom: 90px; }

/* ── Help overlay (1-op-1 uit /public) ── */
.help-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    z-index: 2000; display: flex; align-items: flex-start; justify-content: center;
    padding: 24px 12px; overflow-y: auto;
}
.help-box {
    background: var(--wit); border-radius: 14px; width: 100%; max-width: 520px;
    box-shadow: 0 12px 40px rgba(0,0,0,.3); overflow: hidden;
}
.help-header {
    background: var(--blauw); color: var(--wit); padding: 14px 16px;
    display: flex; justify-content: space-between; align-items: center;
    font-size: 1.1rem; font-weight: 700;
}
.help-sluit { background: none; border: none; color: rgba(255,255,255,.7);
              font-size: 1.5rem; cursor: pointer; line-height: 1; }
.help-body { padding: 16px; font-size: .9rem; line-height: 1.5; color: var(--tekst); }
.help-body h3 { font-size: .95rem; color: var(--blauw); margin: 16px 0 6px; }
.help-body h3:first-child { margin-top: 0; }
.help-body p { margin: 4px 0 8px; }
.help-body .help-stap { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; }
.help-body .help-stap-nr {
    flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%;
    background: var(--oranje); color: var(--wit); font-weight: 700;
    display: flex; align-items: center; justify-content: center; font-size: .8rem;
}

/* In-app bevestigings-dialoog (vervangt native confirm()) */
.bev-knoppen {
    display:flex; gap:10px; justify-content:flex-end;
    padding:12px 16px; border-top:1px solid #eee; background:#f9fafb;
}
.bev-btn {
    padding:8px 18px; font-size:.9rem; font-weight:600;
    border:none; border-radius:6px; cursor:pointer;
}
.bev-btn-annuleer { background:#e5e7eb; color:#333; }
.bev-btn-annuleer:hover { background:#d1d5db; }
.bev-btn-bevestig { background:#b71c1c; color:#fff; }
.bev-btn-bevestig:hover { background:#7a0000; }

.container { max-width:900px; margin:0 auto; padding:12px; }
/* Geen witte card-backgrounds meer; elementen staan direct op de body-gray.
   We behouden de .card-classe als "visueel groepje" zonder achtergrond, zodat
   bestaande HTML-structuur blijft werken en er alleen ruimte onderin komt. */
.card { margin-bottom:18px; }
.card h2 { font-size:1rem; color:var(--blauw); margin-bottom:8px; }

/* Stap-label met blauw cijfer (1-op-1 uit /public) */
.stap-label {
    font-size: 1.05rem; font-weight: 700; color: var(--blauw);
    margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
}
.stap-nr {
    background: var(--blauw); color: var(--wit);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem; font-weight: 700; flex-shrink: 0;
}
/* Secundaire sub-kop binnen een stap (bv. "Op club" / "Op sponsor"). */
.stap-sub { font-size:.85rem; font-weight:600; color:#666;
            margin:10px 0 4px; }

/* Form-elementen — 1-op-1 uit /public voor consistente look & feel.
   Gebruik je eigen selector in plaats van bare `select`/`input` om te
   voorkomen dat dit doorlekt naar andere selects (filter-chips etc.). */
.sel, .inp {
    width: 100%; padding: 14px 14px; font-size: 1rem;
    border: 2px solid #cdd8e3; border-radius: 8px;
    background: var(--wit); appearance: none; -webkit-appearance: none;
}
select.sel {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%23666'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px;
}
.sel:focus, .inp:focus { border-color: var(--middenblauw); outline: none; }
.sel:disabled { background-color:#f5f7fa; color:#999; cursor:not-allowed; }

/* Multi-select dropdown voor sponsors. Knop ziet eruit als .sel, paneel
   klapt eronder uit met checkbox-lijst + zoekveld + alle/niets-knoppen.
   Past goed in beide layouts (desktop + mobiel). */
.sponsor-multi-wrap { position: relative; }
.sponsor-multi-knop {
    display: flex; align-items: center; justify-content: space-between;
    text-align: left; cursor: pointer; padding-right: 14px;
}
.sponsor-multi-knop:not(:disabled):hover { border-color: var(--middenblauw); }
.sponsor-multi-knop .sponsor-multi-pijl { font-size: .8rem; color: #666; margin-left: 8px; }
.sponsor-multi-paneel {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--wit); border: 2px solid var(--middenblauw); border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
    z-index: 50; max-height: 70vh; display: flex; flex-direction: column;
}
/* hidden-attribuut moet display:none afdwingen — anders overrult de
   display:flex hierboven en blijft het paneel zichtbaar. */
.sponsor-multi-paneel[hidden] { display: none !important; }
/* Visueel accent op de knop zodra er iets is geselecteerd: groene rand +
   gevulde achtergrond zodat duidelijk is dat er een keuze openstaat die
   nog naar Toevoegen moet. */
.sponsor-multi-knop.heeft-selectie {
    border-color: #2e7d32 !important;
    background: #f1f8f3;
}
.sponsor-multi-knop.heeft-selectie::before {
    content: '✓ '; color: #2e7d32; font-weight: 700;
}
/* Chips onder de knop: meteen zichtbaar wat gekozen is, zonder paneel
   weer te hoeven openen. Klik op een chip verwijdert die sponsor uit
   de selectie. */
.sponsor-chips {
    display: flex; flex-wrap: wrap; gap: 4px;
    margin-top: 6px;
    min-height: 0;
}
.sponsor-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; font-size: .82rem;
    background: #eef4fa; color: var(--blauw);
    border: 1px solid #c5d8f0; border-radius: 12px;
    cursor: pointer;
}
.sponsor-chip:hover { background: #ffe8e8; border-color: #d77; color: #a33; }
.sponsor-chip::after { content: '×'; font-weight: 700; margin-left: 2px; }
.sponsor-multi-zoek { padding: 10px; border-bottom: 1px solid #eee; }
.sponsor-multi-zoek .inp { padding: 8px 12px; font-size: .9rem; }
.sponsor-multi-acties {
    display: flex; gap: 8px; align-items: center;
    padding: 8px 10px; border-bottom: 1px solid #eee;
    font-size: .85rem;
}
.sponsor-multi-acties .btn-klein {
    padding: 4px 10px; font-size: .8rem;
    background: #eef4fa; color: var(--blauw); border: 1px solid #c5d8f0;
    border-radius: 4px; cursor: pointer;
}
.sponsor-multi-acties .btn-klein:hover { background: #dde8f5; }
.sponsor-multi-teller { margin-left: auto; color: #666; }
.sponsor-multi-lijst { flex: 1; overflow-y: auto; padding: 4px 0; }
.sponsor-multi-lijst label {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 12px; cursor: pointer; font-size: .92rem;
}
.sponsor-multi-lijst label:hover { background: #f0f5fa; }
.sponsor-multi-lijst input[type="checkbox"] { margin: 0; transform: scale(1.1); }
.sponsor-multi-lijst .leeg { padding: 10px 12px; color: #999; font-style: italic; }
.sponsor-multi-footer {
    padding: 10px; border-top: 1px solid #eee; text-align: right;
}
.sponsor-multi-footer .btn-primair {
    padding: 8px 18px; font-size: .9rem;
    background: var(--blauw); color: var(--wit); border: none; border-radius: 4px;
    cursor: pointer;
}
.sponsor-multi-footer .btn-primair:hover { background: var(--middenblauw); }

/* Wedstrijd-info-kader (1-op-1 uit /public) */
.comp-info {
    background: var(--lichtblauw); border-radius: 8px;
    padding: 12px 14px; margin-top: 10px;
    font-size: 1rem; color: var(--blauw);
}
.comp-info strong { font-size: 1.1rem; display:block; }
.comp-info small  { color:#5580a8; }

/* Filter-chips onder de wedstrijd-select (1-op-1 uit /public) */
.filter-rij { display:flex; gap:8px; margin-bottom:8px; }
.filter-rij input[type=checkbox] { display:none; }
.filter-rij label.filter-chip { flex:1; }
.filter-chip {
    display:inline-flex; align-items:center; justify-content:center; gap:5px;
    padding:9px 16px; border-radius:20px; font-size:.95rem; font-weight:600;
    border:2px solid #cdd8e3; background:var(--wit); color:#888;
    cursor:pointer; user-select:none; transition:all .15s;
}
.filter-chip:active { transform:scale(.96); }
.filter-rij input:checked + .filter-chip {
    background:var(--lichtblauw); border-color:var(--middenblauw); color:var(--blauw);
}
.rij { display:flex; gap:8px; margin-bottom:10px; }
.rij > * { flex:1; }
.btn { background:var(--middenblauw); color:var(--wit); border:none;
       padding:8px 14px; font-size:.9rem; border-radius:5px; cursor:pointer; }
.btn:hover { background:var(--blauw); }
.btn:disabled { opacity:.5; cursor:not-allowed; }
.btn-klein { padding:4px 8px; font-size:.8rem; }
.btn-wis { background:#b71c1c; }

/* Primaire actie-knop (1-op-1 uit /public btn-zoek): oranje, volle breedte. */
.btn-primair {
    width:100%; padding:16px; font-size:1.15rem; font-weight:700;
    color:var(--wit); background:var(--oranje);
    border:none; border-radius:8px; cursor:pointer; margin-top:10px;
}
.btn-primair:disabled { opacity:.4; cursor:not-allowed; }
.btn-primair:active { transform:scale(.98); }

/* Coach-lijst chips */
.coach-hdr { display:flex; justify-content:space-between; align-items:center;
             margin-bottom:8px; font-size:.9rem; color:#555; }
.chips { display:flex; flex-wrap:wrap; gap:4px; min-height:28px; }
.chip { display:inline-flex; align-items:center; gap:4px;
        background:var(--lichtblauw); border:1px solid #b3cae6;
        border-radius:14px; padding:2px 8px; font-size:.85rem; }
.chip .x { cursor:pointer; color:#b71c1c; font-weight:700; padding:0 2px; }
.chip .x:hover { color:#7a0000; }
.chip-snr { font-weight:700; color:var(--blauw); }

/* Programma-lijst */
/* Twee-rij-layout: bovenrij = status-icoon + naam/sub (flex), onderrij =
   eigen-rijder-pills (volledige breedte met indent). Pills op een aparte
   regel zetten voorkomt dat coaches met veel rijders het rit-naam-blok
   ingedrukt zien worden of horizontaal moeten scrollen. */
.heat-rij { background:var(--wit); border:1px solid #dde3ea;
            border-radius:6px; padding:10px 12px; margin-bottom:6px;
            cursor:pointer; display:flex; flex-direction:column; gap:6px; }
.heat-rij:hover { background:#f0f5fa; }
.heat-rij.mijn { border-left:4px solid var(--accent-bd); background:var(--accent); }
.heat-rij.leeg { cursor:default; opacity:.75; background:#fafafa; }
.heat-rij-top { display:flex; align-items:center; gap:10px; }
/* Vaste breedte voor het status-icoon zodat naam-kolom niet schuift tussen
   regels met/zonder icoon. Emoji-glyphs zijn breder dan ○, daarom krijgt
   de hele kolom een deterministische breedte. */
.heat-status { width:28px; text-align:center; font-size:1rem; flex-shrink:0; }
.heat-status-leeg { color:#bbb; font-size:.9rem; }
.heat-info { flex:1; min-width:0; }
.heat-naam { font-weight:600; }
.heat-sub { font-size:.8rem; color:#666; margin-top:2px; }
.heat-rit-opm { font-size:.78rem; color:#856404; font-style:italic; margin-top:2px; }
/* Pills uitlijnen onder .heat-info (28px icon + 10px gap) zodat ze visueel
   bij het rit-naam-blok horen. flex-wrap zorgt dat veel pills netjes
   doorlopen op meerdere regels. */
.heat-mijn-snrs { display:flex; flex-wrap:wrap; gap:4px;
                  padding-left:38px; }
.heat-mijn-snrs .m-snr {
    background:var(--accent-bd); color:#000; font-weight:700;
    font-size:.8rem; border-radius:10px; padding:2px 7px;
}
.badge { display:inline-block; padding:1px 6px; font-size:.75rem;
         border-radius:3px; color:#fff; margin-right:4px; }
.badge-serie   { background:#607d8b; }
.badge-kf      { background:#8e24aa; }
.badge-hf      { background:#5e35b1; }
.badge-finale  { background:#d32f2f; }
.badge-ru      { background:#00897b; }
.blok-rij { background:#e8eaf6; border-radius:6px; padding:6px 10px;
            margin-bottom:6px; font-size:.85rem; color:#333; }
.blok-rij-top { display:flex; flex-wrap:wrap; align-items:baseline; gap:.5rem; }
.blok-rij .blok-tijd { color:#666; font-variant-numeric:tabular-nums; }
.blok-rij .blok-titel { font-weight:600; }
.blok-rij .blok-duur { color:#555; font-size:.8rem; }
.blok-rij .blok-opm { color:#555; font-style:italic; }
.blok-rij .blok-cats { margin-top:2px; padding-left:1.4rem; color:#555; font-size:.78rem; }
.blok-rij.blok-pauze { background:#fff3e0; }
.blok-rij.blok-inrijden { background:#e3f2fd; }
.blok-rij.blok-wedstrijdstart { background:#e8f5e9; }
.blok-rij.blok-ceremonie { background:#fff8e1; }
.blok-rij.blok-herstart { background:#ffebee; }

/* Tabs (1-op-1 uit /public) */
.tabs {
    display:flex; background:var(--wit);
    border-bottom:2px solid #dde3ea;
    margin-bottom:10px;
}
.tab-btn {
    flex:1; padding:12px 4px; font-size:.85rem; font-weight:600;
    text-align:center; border:none; background:none; cursor:pointer;
    color:#888; border-bottom:3px solid transparent; margin-bottom:-2px;
}
.tab-btn.active { color:var(--blauw); border-bottom-color:var(--oranje); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* Sancties-tab */
.sanc-persoon { background:var(--wit); border:1px solid #dde3ea;
                border-radius:6px; padding:10px 12px; margin-bottom:8px; }
.sanc-persoon-kop { display:flex; align-items:center; gap:8px;
                    font-weight:600; margin-bottom:6px; flex-wrap:wrap; }
.sanc-persoon-cat { font-size:.8rem; color:#888; margin:-4px 0 6px 34px; }
.sanc-samenvat { display:flex; flex-direction:column; gap:3px;
                 margin:0 0 8px 0; }
.sanc-samenvat-rij { display:flex; align-items:center; gap:8px; font-size:.85rem; }
.sanc-samenvat-naam { flex:1; color:#444; }
.sanc-persoon-snr { color:var(--blauw); font-weight:700; }
.status-badge { font-size:.75rem; padding:2px 8px; border-radius:10px; font-weight:600; }
.status-0 { background:#fff3e0; color:#e65100; }  /* Niet bevestigd */
.status-1 { background:#e8f5e9; color:#2e7d32; }  /* Bevestigd */
.status-2 { background:#fce4e4; color:#b71c1c; }  /* Afgemeld */
.status-3 { background:#f3e5f5; color:#6a1b9a; }  /* Afgem. bij org. */
.status-4 { background:#ffcdd2; color:#b71c1c; border:2px solid #b71c1c; } /* Niet getekend — opvallend! */
.status-5 { background:#e0f7fa; color:#006064; }  /* Bev. bij org. */
.sanc-lijst { display:flex; flex-direction:column; gap:3px; }
.sanc-rij { font-size:.85rem; padding:3px 6px; background:#fff8e1;
            border-left:3px solid #f9a825; border-radius:3px; }
.sanc-rij-code { font-weight:700; color:#b71c1c; }
.sanc-leeg { color:#888; font-style:italic; font-size:.85rem; }

/* Heats-tab: DC/afstand-blokje per rijder met rondes eronder */
.heat-toon-dc { margin:6px 0; padding:6px 8px; background:#f7fbff;
                border-left:3px solid var(--middenblauw); border-radius:4px; }
.heat-toon-dc-kop { font-size:.85rem; font-weight:600; color:var(--blauw); margin-bottom:3px;
                    display:flex; align-items:center; gap:6px; }
.heat-toon-rij { display:flex; align-items:center; gap:6px;
                 font-size:.85rem; padding:2px 0; flex-wrap:wrap; }
.heat-toon-wachten { background:#fff8e1; border-left-color:#f9a825; }
.heat-toon-wacht-rij { color:#8a5a00; font-style:italic; }
.heat-toon-niet-geplaatst { color:#b71c1c; font-style:italic; }
.chip-waarschuw { background:#ffcdd2 !important; border-color:#b71c1c !important; }

/* Uitslagen-tabel */
table.uitsl-tabel { width:100%; border-collapse:collapse; margin-top:10px; font-size:.85rem; }
table.uitsl-tabel th { background:var(--lichtblauw); color:var(--blauw);
                       padding:6px 4px; text-align:left; border-bottom:2px solid #b3cae6; }
table.uitsl-tabel td { padding:6px 4px; border-bottom:1px solid #eee; }
table.uitsl-tabel tr.mijn td { background:var(--accent); font-weight:600; }
.col-rang { width:32px; text-align:center; font-weight:700; }
.col-rnd, .col-pk { width:40px; text-align:right; }
.col-tijd { width:80px; text-align:right; font-variant-numeric:tabular-nums; }
.col-punten { width:44px; text-align:right; }
.col-totaal { width:50px; text-align:right; font-weight:700; color:var(--blauw); }
.col-sanctie { color:#b71c1c; font-weight:700; font-size:.8em; }

/* Gecombineerde rit: ritten die tegelijk rijden in één kader */
.prog-combi-box {
    border:2px dashed var(--middenblauw);
    border-radius:8px;
    padding:6px 8px 2px;
    margin-bottom:8px;
    background:#f7fbff;
}
.prog-combi-kop {
    font-size:.8rem; font-weight:700; color:var(--blauw);
    padding:2px 4px 6px; letter-spacing:.3px;
}
.prog-combi-leden .heat-rij { margin-bottom:4px; }

.overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000;
           display:flex; align-items:flex-start; justify-content:center;
           padding:20px; overflow-y:auto; }
.overlay-box { background:var(--wit); border-radius:8px; max-width:500px;
               width:100%; position:relative; overflow:hidden; }
/* Heat-overlay: blauwe kop met titel + ronde rode close-knop rechtsboven —
   zelfde stijl als de publieke app voor visuele consistentie. */
.heat-card-titel {
    background: var(--blauw); color: var(--wit);
    padding: 10px 50px 10px 14px; font-weight: 700; font-size: .95rem;
    display: flex; align-items: center; gap: 8px;
    position: relative;
    border-radius: 8px 8px 0 0;
}
.overlay-sluit {
    position: absolute; top: 8px; right: 8px;
    border: none; background: #d22; color: #fff;
    width: 28px; height: 28px; border-radius: 50%;
    font-size: 1.1rem; font-weight: 700; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.25);
    transition: background .12s, transform .08s;
}
.overlay-sluit:hover  { background: #b71c1c; }
.overlay-sluit:active { transform: scale(.92); }
.overlay-body { padding: 14px; }
/* Oude .sluit-stijl voor andere overlays (info/help/leeg-melding fallback) */
.overlay .sluit { position:absolute; top:8px; right:12px; cursor:pointer;
                  font-size:1.4rem; color:#666; }
.overlay .sluit:hover { color:#000; }

table.heat-tabel { width:100%; border-collapse:collapse; margin-top:10px; font-size:.85rem; }
table.heat-tabel th { background:var(--lichtblauw); color:var(--blauw);
                      padding:6px 4px; text-align:left; border-bottom:2px solid #b3cae6; }
table.heat-tabel td { padding:6px 4px; border-bottom:1px solid #eee; }
table.heat-tabel tr.mijn td { background:var(--accent); font-weight:600; }
.col-pos { width:30px; text-align:center; }
.col-snr { width:44px; text-align:right; font-weight:700; color:var(--blauw); }
.col-fin { width:40px; text-align:center; }

.spinner { display:inline-block; width:16px; height:16px;
           border:2px solid #ccc; border-top-color:var(--blauw);
           border-radius:50%; animation:spin .8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.leeg-melding { text-align:center; color:#888; padding:20px; font-style:italic; }

/* Pull-to-refresh indicator */
#ptr {
    position:fixed; top:0; left:0; right:0;
    background:var(--middenblauw); color:var(--wit);
    text-align:center; font-size:.85rem; padding:6px 0;
    transform:translateY(-100%); transition:transform .15s ease-out;
    z-index:900; pointer-events:none;
}
#ptr.zichtbaar { transform:translateY(0); }
#ptr.laadt { background:var(--blauw); }

/* Stempeltje rechtsonder dat laat zien wanneer de laatste auto-refresh was.
   Discreet; de user weet zo dat de pagina leeft zonder dat het opvalt.
   Verdwijnt niet onder de sponsor-footer omdat hij absolute daarboven staat. */
.auto-refresh-stempel {
    position:fixed; right:8px; bottom:8px; z-index:110;
    background:rgba(255,255,255,.9); color:#666;
    font-size:.7rem; padding:3px 8px; border-radius:10px;
    border:1px solid #dde3ea; pointer-events:none;
}
body.heeft-footer .auto-refresh-stempel { bottom:84px; }

/* ── PWA install banner (1-op-1 uit /public) ── */
.pwa-banner {
    background: linear-gradient(135deg, var(--blauw), var(--middenblauw));
    color: var(--wit); padding: 10px 16px; display: flex; align-items: center;
    gap: 10px; font-size: .85rem; border-radius: 10px; margin: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
}
.pwa-banner-tekst { flex: 1; }
.pwa-banner-tekst b { display: block; font-size: .95rem; }
.pwa-banner .btn-install {
    background: var(--oranje); color: #fff; border: none; border-radius: 8px;
    padding: 8px 14px; font-weight: 700; font-size: .85rem; cursor: pointer;
    white-space: nowrap;
}
.pwa-banner .btn-install:active { transform: scale(.96); }
.pwa-banner .btn-sluit {
    background: none; border: none; color: rgba(255,255,255,.6);
    font-size: 1.2rem; cursor: pointer; padding: 0 4px; line-height: 1;
}
</style>
</head>
<body>
<div id="ptr">↓ Trek verder om te vernieuwen</div>

<header>
    <div class="hdr-row-top">
        <div class="hdr-btns hdr-btns-left">
            <button class="btn-help btn-meldingen" id="btn-meldingen-overzicht" title="Mededelingen voor deze wedstrijd">📢<span id="meldingen-badge" class="meld-badge" style="display:none">0</span></button>
            <span class="hdr-spacer" aria-hidden="true"></span>
        </div>
        <div class="hdr-center">
            <h1>InlineComp – Coach</h1>
        </div>
        <div class="hdr-btns hdr-btns-right">
            <button class="btn-help" onclick="toonInfo()" title="Over InlineComp">i</button>
            <button class="btn-help" onclick="toonHelp()" title="Hoe werkt het?">?</button>
        </div>
    </div>
    <div class="sub">Volg jouw rijders: programma, sancties en uitslagen</div>
</header>

<div id="pwa-banner" class="pwa-banner" style="display:none">
    <div class="pwa-banner-tekst">
        <b>Installeer InlineComp Coach</b>
        Voeg toe aan je startscherm voor snelle toegang
    </div>
    <button class="btn-install" id="pwa-install">Installeer</button>
    <button class="btn-sluit" id="pwa-sluit" title="Sluiten">&times;</button>
</div>

<div id="org-footer" class="org-footer">
    <div class="org-footer-inner">
        <span id="footer-org-logo"></span>
        <span id="footer-org-naam" class="org-footer-naam"></span>
        <div id="footer-sponsors" class="org-footer-sponsors"></div>
        <span id="footer-baan-logo"></span>
    </div>
</div>

<div class="container">

<div class="card">
    <div class="stap-label"><span class="stap-nr">1</span> Kies je wedstrijd</div>
    <div class="filter-rij">
        <input type="checkbox" id="chk-oud"><label for="chk-oud" class="filter-chip" title="Eerdere wedstrijden">Eerder</label>
        <input type="checkbox" id="chk-vandaag" checked><label for="chk-vandaag" class="filter-chip">Vandaag</label>
        <input type="checkbox" id="chk-toekomst"><label for="chk-toekomst" class="filter-chip" title="Toekomstige wedstrijden">Later</label>
    </div>
    <select id="sel-comp" class="sel"><option value="">— kies een wedstrijd —</option></select>
    <div id="comp-info" class="comp-info" style="display:none"></div>
</div>

<div id="sectie-selectie" class="card">
    <div class="stap-label"><span class="stap-nr">2</span> Voeg rijders toe aan je coach-lijst</div>
    <div class="stap-sub">Op club <small style="font-weight:400;color:#666">— meerdere tegelijk mogelijk</small></div>
    <div class="rij">
        <div class="sponsor-multi-wrap">
            <button type="button" id="btn-club-open" class="sel sponsor-multi-knop" disabled>
                <span id="club-multi-label">— kies eerst een wedstrijd —</span>
                <span class="sponsor-multi-pijl">▾</span>
            </button>
            <div id="club-chips" class="sponsor-chips"></div>
            <div id="club-multi-paneel" class="sponsor-multi-paneel" hidden>
                <div class="sponsor-multi-zoek">
                    <input type="search" id="club-multi-zoek" placeholder="🔍 Zoeken…" class="inp">
                </div>
                <div class="sponsor-multi-acties">
                    <button type="button" class="btn-klein" id="club-multi-alles">Alle aanvinken</button>
                    <button type="button" class="btn-klein" id="club-multi-niets">Niets aanvinken</button>
                    <span id="club-multi-teller" class="sponsor-multi-teller">0 geselecteerd</span>
                </div>
                <div id="club-multi-lijst" class="sponsor-multi-lijst"></div>
                <div class="sponsor-multi-footer">
                    <button type="button" id="club-multi-klaar" class="btn-primair">Klaar</button>
                </div>
            </div>
        </div>
    </div>
    <div class="stap-sub">Op sponsor <small style="font-weight:400;color:#666">— meerdere tegelijk mogelijk</small></div>
    <div class="rij">
        <div class="sponsor-multi-wrap">
            <button type="button" id="btn-sponsor-open" class="sel sponsor-multi-knop" disabled>
                <span id="sponsor-multi-label">— kies eerst een wedstrijd —</span>
                <span class="sponsor-multi-pijl">▾</span>
            </button>
            <div id="sponsor-chips" class="sponsor-chips"></div>
            <div id="sponsor-multi-paneel" class="sponsor-multi-paneel" hidden>
                <div class="sponsor-multi-zoek">
                    <input type="search" id="sponsor-multi-zoek" placeholder="🔍 Zoeken…" class="inp">
                </div>
                <div class="sponsor-multi-acties">
                    <button type="button" class="btn-klein" id="sponsor-multi-alles">Alle aanvinken</button>
                    <button type="button" class="btn-klein" id="sponsor-multi-niets">Niets aanvinken</button>
                    <span id="sponsor-multi-teller" class="sponsor-multi-teller">0 geselecteerd</span>
                </div>
                <div id="sponsor-multi-lijst" class="sponsor-multi-lijst"></div>
                <div class="sponsor-multi-footer">
                    <button type="button" id="sponsor-multi-klaar" class="btn-primair">Klaar</button>
                </div>
            </div>
        </div>
    </div>
    <div class="stap-sub">Op startnummer</div>
    <div class="rij">
        <input id="inp-snr" class="inp" type="number" min="1" placeholder="Startnummer…" inputmode="numeric" disabled>
    </div>
    <button id="btn-toevoegen" class="btn-primair" disabled>Toevoegen</button>
    <div id="snr-feedback" style="font-size:.85rem;color:#b71c1c;min-height:18px;margin-top:6px"></div>
</div>

<div id="sectie-lijst" class="card" style="display:none">
    <div class="coach-hdr">
        <span id="coach-aantal">0 rijders</span>
        <button id="btn-wis-alles" class="btn btn-klein btn-wis">Wis alles</button>
    </div>
    <div id="coach-chips" class="chips"></div>
</div>

<div id="sectie-programma" class="card" style="display:none">
    <div class="tabs">
        <button class="tab-btn active" data-tab="programma">📋 Programma</button>
        <button class="tab-btn" data-tab="heats">🏃 Heats</button>
        <button class="tab-btn" data-tab="sancties">⚠️ Sancties</button>
        <button class="tab-btn" data-tab="uitslagen">📊 Uitslagen</button>
    </div>
    <div id="tab-programma" class="tab-pane active">
        <div id="programma"></div>
    </div>
    <div id="tab-heats" class="tab-pane">
        <div id="heats"></div>
    </div>
    <div id="tab-sancties" class="tab-pane">
        <div id="sancties"></div>
    </div>
    <div id="tab-uitslagen" class="tab-pane">
        <div class="rij">
            <select id="u-sel-cat" class="sel"><option value="">— kies categorie —</option></select>
        </div>
        <div class="rij" id="u-afstand-rij" style="display:none">
            <select id="u-sel-afstand" class="sel"><option value="">— kies afstand —</option></select>
        </div>
        <div id="uitslagen"></div>
    </div>
</div>

</div>
<script>
const $ = id => document.getElementById(id);
const selComp = $('sel-comp');
// Multi-select state voor zowel sponsors als clubs.
// _sponsorAlle  = Array<string>      met alle sponsor-namen
// _sponsorSel   = Set<string>        met geselecteerde sponsor-namen
// _clubAlle     = Array<{full,short}> met alle clubs
// _clubSel      = Set<string>        met geselecteerde club_full's (voor backend)
let _sponsorAlle = [];
const _sponsorSel = new Set();
let _clubAlle = [];
const _clubSel = new Set();
const inpSnr = $('inp-snr'), btnToevoegen = $('btn-toevoegen');
const secSel = $('sectie-selectie'), secLijst = $('sectie-lijst'), secProg = $('sectie-programma');
const chipsEl = $('coach-chips'), aantalEl = $('coach-aantal');
const progEl = $('programma'), snrFb = $('snr-feedback');

let coachLijst = []; // [{snr, license_key, full_name, category, club_full, sponsor}]
let programmaCache = null; // {ritten, blokken}
let coachInfoCache = {}; // license_key → {entry_status, sancties:[]}
let alleComps = []; // ruwe lijst uit /?action=competitions — gebruikt door filterComps()

const STATUS_LABEL = ['Niet bevestigd','Bevestigd','Afgemeld','Afgem. bij org.','Niet getekend','Bev. bij org.'];
const STATUS_ICON  = ['⚠',          '✓',        '✗',       '✗',              '🚨',         '✓'];
// Status die voor een coach direct actie vereist (rood-alarm in de UI):
const STATUS_ALARM = new Set([0, 4]); // niet bevestigd + niet getekend
const SANCTIE_UITLEG = {
    'W1':'1e waarschuwing','W2':'2e waarschuwing','FS':'Valse start','RR':'Rank reduction',
    'DQ-TF':'Diskwalificatie technische fout','DQ-SF':'Diskwalificatie sport fout',
    'DQ-DF':'Diskwalificatie disciplinaire fout','DNS':'Niet gestart','DNF':'Niet gefinisht',
};

const BADGE = { heats:'badge-serie', kwartfinale:'badge-kf', halve_finale:'badge-hf',
                finale_a:'badge-finale', finale_b:'badge-finale', runner_up:'badge-ru' };
const RLABEL = { heats:'Serie', kwartfinale:'KF', halve_finale:'HF',
                 finale_a:'Finale', finale_b:'B-Finale', runner_up:'Runner-up' };

function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function safeDatum(s) { return s ? new Date(String(s).replace(' ','T')) : null; }
function msTijd(ms) {
    if (ms==null) return '';
    const d=ms%1000, s=Math.floor(ms/1000)%60, m=Math.floor(ms/60000);
    return m>0?`${m}:${String(s).padStart(2,'0')}.${String(d).padStart(3,'0')}`
              :`${s}.${String(d).padStart(3,'0')}`;
}
// ── In-app bevestiging (vervangt native confirm) ─────────────────────────────
// Gebruik: const ok = await bevestig({ titel, tekst, bevestigLabel, annuleerLabel });
function bevestig({ titel = 'Bevestigen', tekst = '', bevestigLabel = 'OK', annuleerLabel = 'Annuleren' } = {}) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'help-overlay';
        overlay.innerHTML = `
            <div class="help-box" style="max-width:420px">
                <div class="help-header">
                    <span>${esc(titel)}</span>
                    <button class="help-sluit" data-bev-actie="annuleer">&times;</button>
                </div>
                <div class="help-body" style="padding:18px 16px">${tekst}</div>
                <div class="bev-knoppen">
                    <button class="bev-btn bev-btn-annuleer" data-bev-actie="annuleer">${esc(annuleerLabel)}</button>
                    <button class="bev-btn bev-btn-bevestig" data-bev-actie="bevestig">${esc(bevestigLabel)}</button>
                </div>
            </div>`;
        const sluit = (resultaat) => { overlay.remove(); resolve(resultaat); };
        overlay.addEventListener('click', e => {
            if (e.target === overlay) return sluit(false);
            const actie = e.target.closest('[data-bev-actie]')?.dataset.bevActie;
            if (actie === 'bevestig') sluit(true);
            else if (actie === 'annuleer') sluit(false);
        });
        document.body.appendChild(overlay);
        // Focus standaard op de annuleer-knop (veiliger) — bevestigen kost
        // bewust een extra tap.
        overlay.querySelector('.bev-btn-annuleer')?.focus();
    });
}

// ── Verbinding-status: detecteert offline / server-down en toont banner ────
// Wordt door safeFetch hieronder bijgewerkt: succes → groen/verborgen,
// fout → banner met passende tekst. window 'online'-event triggert direct
// een refresh; visibilitychange (visible) triggert ook een refresh.
const _conn = {
    online: navigator.onLine,
    serverOk: true,
    lastSuccess: null,
    consecutiveFails: 0,
    refreshHook: null,
};

function _connBannerEl() {
    let el = document.getElementById('conn-banner');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'conn-banner';
    el.className = 'conn-banner';
    el.style.display = 'none';
    document.body.insertBefore(el, document.body.firstChild);
    return el;
}

function _connUpdateBanner() {
    const el = _connBannerEl();
    let bericht = '';
    if (!_conn.online) {
        bericht = '📡 Geen internet — ververst zodra de verbinding terug is';
    } else if (!_conn.serverOk) {
        bericht = '⚠ Server niet bereikbaar — opnieuw proberen…';
    }
    if (bericht) {
        const tijd = _conn.lastSuccess
            ? ` <small>(laatste update ${_conn.lastSuccess.toLocaleTimeString('nl-NL', {hour:'2-digit', minute:'2-digit'})})</small>`
            : '';
        el.innerHTML = bericht + tijd;
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

// Grace-periode: na een fout blijft de banner ten minste deze tijd staan,
// ook als andere fetches in de tussentijd slagen. Voorkomt geflikker bij
// gemengde fouten (één endpoint down, ander werkt).
const _CONN_GRACE_MS = 10_000;

function _connOk() {
    const wasFout = !_conn.serverOk || !_conn.online;
    _conn.lastSuccess = new Date();
    _conn.consecutiveFails = 0;
    const inGrace = _conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) < _CONN_GRACE_MS;
    _conn.online = true;
    if (!inGrace) _conn.serverOk = true;
    if (wasFout && !inGrace) _connUpdateBanner();
}

function _connFail(reden) {
    if (reden === 'network') _conn.online = false;
    else                     _conn.serverOk = false;
    _conn.lastFailureMs = Date.now();
    _conn.consecutiveFails++;
    _connUpdateBanner();
    setTimeout(() => {
        if (_conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) >= _CONN_GRACE_MS) {
            _conn.serverOk = true;
            _connUpdateBanner();
        }
    }, _CONN_GRACE_MS + 100);
}

window.addEventListener('online', () => {
    _conn.online = true;
    _connUpdateBanner();
    if (typeof _conn.refreshHook === 'function') _conn.refreshHook();
});
window.addEventListener('offline', () => {
    _conn.online = false;
    _connUpdateBanner();
});

async function safeFetch(url, maxRetries = 3) {
    try {
        for (let i=0; i<maxRetries; i++) {
            const res = await fetch(url);
            if (res.status === 429) {
                await new Promise(r => setTimeout(r, 2000 * (i+1)));
                continue;
            }
            if (res.status >= 500) {
                _connFail('server');
                return res;
            }
            _connOk();
            return res;
        }
        const res = await fetch(url);
        if (res.status >= 500) _connFail('server'); else _connOk();
        return res;
    } catch (e) {
        _connFail('network');
        throw e;
    }
}

// ── Coach-lijst persistentie (localStorage per wedstrijd) ────────────────────
function lsKey() { return `coach_lijst_${selComp.value || 'geen'}`; }
function saveCoachLijst() {
    if (!selComp.value) return;
    localStorage.setItem(lsKey(), JSON.stringify(coachLijst));
}
function loadCoachLijst() {
    if (!selComp.value) { coachLijst = []; return; }
    try { coachLijst = JSON.parse(localStorage.getItem(lsKey()) || '[]'); }
    catch { coachLijst = []; }
    if (!Array.isArray(coachLijst)) coachLijst = [];
}

function voegToeAanLijst(persoon) {
    if (!persoon || !persoon.snr) return false;
    const snr = parseInt(persoon.snr);
    if (coachLijst.some(p => parseInt(p.snr) === snr)) return false; // al aanwezig
    coachLijst.push({
        snr: snr,
        license_key: persoon.license_key,
        full_name: persoon.full_name,
        category: persoon.category || '',
        club_full: persoon.club_full || '',
        sponsor: persoon.sponsor || '',
    });
    return true;
}

function verwijderUitLijst(snr) {
    snr = parseInt(snr);
    coachLijst = coachLijst.filter(p => parseInt(p.snr) !== snr);
}

// ── UI-render ────────────────────────────────────────────────────────────────
function renderChips() {
    aantalEl.textContent = `${coachLijst.length} ${coachLijst.length === 1 ? 'rijder' : 'rijders'} geselecteerd`;
    if (coachLijst.length === 0) {
        chipsEl.innerHTML = '<span style="color:#888;font-size:.85rem">Nog niemand geselecteerd — gebruik de selectors hierboven.</span>';
        secLijst.style.display = 'block';
        return;
    }
    secLijst.style.display = 'block';
    const gesorteerd = [...coachLijst].sort((a,b) => parseInt(a.snr) - parseInt(b.snr));
    chipsEl.innerHTML = gesorteerd.map(p => {
        const info = coachInfoCache[p.license_key];
        const st = info ? parseInt(info.entry_status) : 1;
        const alarm = STATUS_ALARM.has(st);
        const icon = alarm ? (STATUS_ICON[st] + ' ') : '';
        const stLabel = STATUS_LABEL[st] ?? '';
        return `<span class="chip${alarm ? ' chip-waarschuw' : ''}" title="${esc(p.full_name)} — ${esc(p.club_full)}${p.sponsor ? ' / ' + esc(p.sponsor) : ''}\nStatus: ${esc(stLabel)}">
            <span class="chip-snr">${esc(p.snr)}</span>
            <span>${icon}${esc(p.full_name)}</span>
            <span class="x" data-snr="${esc(p.snr)}">×</span>
         </span>`;
    }).join('');
    chipsEl.querySelectorAll('.x').forEach(x => {
        x.onclick = async () => {
            const snr = parseInt(x.dataset.snr);
            const persoon = coachLijst.find(p => parseInt(p.snr) === snr);
            const naam = persoon?.full_name || `Startnr ${snr}`;
            const ok = await bevestig({
                titel: 'Rijder verwijderen?',
                tekst: `Wil je <b>${esc(naam)}</b> (${esc(snr)}) uit je coach-lijst verwijderen?`,
                bevestigLabel: 'Ja, verwijder',
                annuleerLabel: 'Annuleren',
            });
            if (!ok) return;
            verwijderUitLijst(snr);
            saveCoachLijst();
            renderChips();
            renderProgramma();
            await laadCoachInfo();
            renderChips();
            renderSancties();
            renderHeats();
        };
    });
}

async function verversCoachLijstUI() {
    renderChips();
    renderProgramma();
    await laadCoachInfo();
    renderChips();
    renderSancties();
    renderHeats();
}

function renderProgramma() {
    if (!programmaCache) { progEl.innerHTML = '<div class="leeg-melding">Programma wordt geladen…</div>'; return; }
    const mijnSnrs = new Set(coachLijst.map(p => parseInt(p.snr)));
    const { ritten, blokken } = programmaCache;

    // De canonieke volgorde in het programma is:
    //   blok_volgorde (master) → binnen een ronde-blok rit_volgorde.
    // Niet-ronde blokken (pauze/ceremonie/etc.) krijgen HUN eigen blok_volgorde.
    // Ritten krijgen de blok_volgorde van hun parent-blok + hun eigen
    // rit_volgorde als tie-breaker. Blokken die op dezelfde volgorde staan
    // als een reeks ritten tonen we NA de ritten (tiebreak=999 — pauze komt
    // meestal ná een rondeblok).
    const allesGesorteerd = [];
    (ritten || []).forEach(r => allesGesorteerd.push({
        type:'rit', data:r,
        bv: r.blok_volgorde ?? 9999,
        rv: r.rit_volgorde  ?? 0,
    }));
    (blokken || []).forEach(b => allesGesorteerd.push({
        type:'blok', data:b,
        bv: b.volgorde ?? 9999,
        rv: 9999, // blok staat ná de ritten met dezelfde blok_volgorde
    }));
    allesGesorteerd.sort((a,b) => {
        if (a.bv !== b.bv) return a.bv - b.bv;
        return a.rv - b.rv;
    });

    if (!allesGesorteerd.length) {
        progEl.innerHTML = '<div class="leeg-melding">Nog geen programma bekend.</div>';
        return;
    }

    // Haal HH:MM uit "HH:MM:SS" (TIME) óf "YYYY-MM-DD HH:MM:SS" (DATETIME).
    const hhmm = v => {
        if (!v) return '';
        const s = String(v);
        const m = s.match(/(\d{1,2}:\d{2})/);
        return m ? m[1] : '';
    };
    // Render één tijdschema-blok (pauze / inrijden / wedstrijdstart /
    // ceremonie / herstart). Toont icoon, tijdstip, duur en (voor inrijden)
    // de cats + (voor pauze/herstart) eventuele opmerking. Match in stijl
    // de admin-tijdschema rendering zodat coach hetzelfde ziet als wat in
    // het programma is geconfigureerd.
    const blokHtml = b => {
        const t = (b.blok_type || '').toLowerCase();
        const tijd = hhmm(b.tijdstip);
        const tijdPrefix = tijd ? `<span class="blok-tijd">🕓 ${esc(tijd)}</span>` : '';
        const duur = b.duur ? `<span class="blok-duur">${b.duur} min</span>` : '';
        const opm  = b.opmerking ? `<span class="blok-opm"> — ${esc(b.opmerking)}</span>` : '';
        const cats = b.inrijd_cat_namen ? `<div class="blok-cats">${esc(b.inrijd_cat_namen)}</div>` : '';
        let icoon, lbl;
        if      (t === 'pauze')          { icoon = '⏸'; lbl = 'Pauze'; }
        else if (t === 'inrijden')       { icoon = '🛼'; lbl = 'Inrijden'; }
        else if (t === 'wedstrijdstart') { icoon = '🏁'; lbl = 'Wedstrijd start'; }
        else if (t === 'ceremonie')      { icoon = '🏆'; lbl = 'Ceremonie'; }
        else if (t === 'herstart')       { icoon = '🔄'; lbl = 'Herstart'; }
        else                              { icoon = '🕓'; lbl = (b.blok_type || '').toUpperCase(); }
        return `<div class="blok-rij blok-${esc(t)}">
            <div class="blok-rij-top">
                ${tijdPrefix}
                <span class="blok-titel">${icoon} ${esc(lbl)}</span>
                ${duur}
                ${opm}
            </div>
            ${cats}
        </div>`;
    };

    const ritHtml = r => {
        const heatSnrs = (r.heat_snrs || []).map(n => parseInt(n));
        const mijnInHeat = heatSnrs.filter(n => mijnSnrs.has(n)).sort((a,b) => a-b);
        const leeg = !r.heat_id || (r.entries_count ?? 0) === 0;
        const rondeBadge = r.ronde_type && BADGE[r.ronde_type]
            ? `<span class="badge ${BADGE[r.ronde_type]}">${RLABEL[r.ronde_type] || r.ronde_type}</span>` : '';
        const mijnStrip = mijnInHeat.length
            ? `<div class="heat-mijn-snrs">${mijnInHeat.map(n => `<span class="m-snr">${n}</span>`).join('')}</div>`
            : '';
        // Status-icoon (vaste breedte zodat de layout niet schuift):
        //   🏁 = resultaten aanwezig · 🚩 = startlijst definitief · ○ = nog niks
        const statusIcon = (r.resultaten_count ?? 0) > 0 ? '🏁'
                        : r.definitief                    ? '🚩'
                        :                                   '<span class="heat-status-leeg">○</span>';
        const klasse = 'heat-rij' + (mijnInHeat.length ? ' mijn' : '') + (leeg ? ' leeg' : '');
        const klik = leeg ? '' :
            ` data-rit-naam="${esc(r.rit_naam)}" data-dc-naam="${esc(r.dc_naam ?? '')}" onclick="toonRitDetail(this)"`;
        // Pills komen op een aparte regel onder de naam, zodat ze bij coaches
        // met veel rijders niet in het rit-naam-blok worden geperst.
        const opmHtml = r.rit_opmerking
            ? `<div class="heat-rit-opm">📝 ${esc(r.rit_opmerking)}</div>` : '';
        return `<div class="${klasse}"${klik}>
            <div class="heat-rij-top">
                <div class="heat-status">${statusIcon}</div>
                <div class="heat-info">
                    <div class="heat-naam">${rondeBadge}${esc(r.rit_naam)}</div>
                    <div class="heat-sub">${esc(r.dc_naam ?? '')}${leeg ? ' · nog geen startlijst' : ''}</div>
                    ${opmHtml}
                </div>
            </div>
            ${mijnStrip}
        </div>`;
    };

    // Render items. Consecutieve ritten met dezelfde combi_group worden in
    // één kader gegroepeerd (gecombineerde rit — categorieën rijden tegelijk).
    let html = '';
    let vorigeCombi = null; // combi_group van het vorige item (of null)
    for (const item of allesGesorteerd) {
        if (item.type === 'blok') {
            if (vorigeCombi !== null) { html += `</div></div>`; vorigeCombi = null; }
            html += blokHtml(item.data);
            continue;
        }
        const r = item.data;
        const combi = r.combi_group ? parseInt(r.combi_group) : null;
        if (combi !== vorigeCombi) {
            if (vorigeCombi !== null) html += `</div></div>`; // sluit vorige combi-box
            if (combi !== null) {
                html += `<div class="prog-combi-box">
                    <div class="prog-combi-kop">🔗 Gecombineerde rit — rijden tegelijk</div>
                    <div class="prog-combi-leden">`;
            }
            vorigeCombi = combi;
        }
        html += ritHtml(r);
    }
    if (vorigeCombi !== null) html += `</div></div>`; // sluit laatste open combi-box
    progEl.innerHTML = html;
}

// ── Coach-info (status + sancties) ───────────────────────────────────────────
async function laadCoachInfo() {
    if (!selComp.value || !coachLijst.length) { coachInfoCache = {}; return; }
    const licenses = coachLijst.map(p => p.license_key).filter(Boolean);
    if (!licenses.length) { coachInfoCache = {}; return; }
    try {
        const res = await fetch(`?action=coach_info`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ competition_id: selComp.value, licenses }),
        });
        const data = await res.json();
        const map = {};
        (data.personen || []).forEach(p => { map[p.license_key] = p; });
        coachInfoCache = map;
    } catch { /* stil falen — UI blijft werken zonder status */ }
}

const RONDE_VOLGORDE = ['heats','kwartfinale','halve_finale','runner_up','finale_b','finale_a'];

function renderHeats() {
    const el = $('heats');
    if (!coachLijst.length) {
        el.innerHTML = '<div class="leeg-melding">Nog geen rijders in je lijst.</div>';
        return;
    }
    // Groepeer alle programma-ritten per DC om per DC te weten welke rondes er
    // bestaan (en per ronde de status van de heats).
    const rittenPerDc = {};
    for (const r of (programmaCache?.ritten || [])) {
        const dc = r.heat_dc_id;
        if (!dc) continue;
        if (!rittenPerDc[dc]) rittenPerDc[dc] = [];
        rittenPerDc[dc].push(r);
    }

    const gesorteerd = [...coachLijst].sort((a,b) => parseInt(a.snr) - parseInt(b.snr));
    el.innerHTML = gesorteerd.map(p => {
        const info    = coachInfoCache[p.license_key];
        const entries = info?.entries || [];
        const mijnHeats = info?.heats || [];

        if (!info) return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.8rem">${esc(p.category || '')}</span>
            </div>
            <div class="sanc-leeg">Laden…</div>
        </div>`;

        if (!entries.length) return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.8rem">${esc(p.category || '')}</span>
            </div>
            <div class="sanc-leeg">Rijder heeft geen inschrijvingen in deze wedstrijd.</div>
        </div>`;

        // Bouw een lijst met één blok per (DC × afstand). De afstanden komen
        // uit `entry.afstanden` (afgeleid van de `distances`-tabel per DC),
        // zodat óók afstanden zonder gegenereerd programma verschijnen
        // (bv. een lange afstand die nog geloot moet worden).
        const afstandBlokken = [];
        for (const e of entries) {
            const dcRitten = rittenPerDc[e.dc_id] || [];
            const afstanden = e.afstanden || [];
            const dcStatus  = parseInt(e.entry_status ?? 1);
            if (!afstanden.length) {
                // Fallback: DC zonder distances — toon 1 wachtrij-blok
                afstandBlokken.push({
                    dc_id: e.dc_id, dc_naam: e.dc_naam, dc_status: dcStatus,
                    distance_id: null, distance_naam: null, ritten: [],
                });
                continue;
            }
            for (const a of afstanden) {
                const ritten = dcRitten.filter(r =>
                    String(r.rit_distance_id || r.heat_distance_id || '') === String(a.distance_id));
                afstandBlokken.push({
                    dc_id: e.dc_id,
                    dc_naam: e.dc_naam,
                    dc_status: dcStatus,
                    distance_id: a.distance_id,
                    distance_naam: a.distance_naam,
                    ritten,
                    expected_rondes: a.expected_rondes || [],
                });
            }
        }

        // Sorteer alle afstand-blokken op programma-volgorde: de vroegste rit
        // (blok_volgorde, rit_volgorde) bepaalt de positie. Blokken zonder
        // geloot programma gaan achteraan maar onderling op dc_naam +
        // distance_naam zodat de volgorde stabiel en leesbaar is.
        const sortKey = b => {
            if (!b.ritten.length) return [Infinity, Infinity];
            let bvMin = Infinity, rvMin = Infinity;
            for (const r of b.ritten) {
                const bv = r.blok_volgorde ?? 9999;
                const rv = r.rit_volgorde  ?? 9999;
                if (bv < bvMin || (bv === bvMin && rv < rvMin)) { bvMin = bv; rvMin = rv; }
            }
            return [bvMin, rvMin];
        };
        afstandBlokken.sort((x, y) => {
            const [xb, xr] = sortKey(x), [yb, yr] = sortKey(y);
            if (xb !== yb) return xb - yb;
            if (xr !== yr) return xr - yr;
            // fallback voor blokken zonder ritten: alfabetisch stabiel
            const a = `${x.dc_naam} ${x.distance_naam || ''}`;
            const b = `${y.dc_naam} ${y.distance_naam || ''}`;
            return a.localeCompare(b);
        });

        const dcBlokken = afstandBlokken.map(blok => {
            // Kop-tekst: "DJB — 500m" als er een afstand-naam is, anders alleen DC-naam
            const kop = blok.distance_naam
                ? `${esc(blok.dc_naam || '(categorie)')} — ${esc(blok.distance_naam)}`
                : esc(blok.dc_naam || '(categorie)');

            // Groepeer ritten per ronde_type binnen deze afstand
            const rondes = {};
            for (const r of blok.ritten) {
                const rt = r.ronde_type || 'heats';
                if (!rondes[rt]) rondes[rt] = [];
                rondes[rt].push(r);
            }
            // Vul aan met verwachte rondes uit cat_config (lege lijst als er
            // nog geen ritten zijn geloot); frontend toont die dan als
            // "⏳ Startlijst nog niet definitief".
            for (const rt of (blok.expected_rondes || [])) {
                if (!rondes[rt]) rondes[rt] = [];
            }
            const sortedRt = Object.keys(rondes).sort((a,b) => {
                const ia = RONDE_VOLGORDE.indexOf(a); const ib = RONDE_VOLGORDE.indexOf(b);
                return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
            });
            if (!sortedRt.length) {
                // Afstand bestaat wel maar cat_config heeft géén rondes én er zijn
                // geen ritten — dan tonen we één wacht-regel.
                const tekst = blok.distance_naam
                    ? '⏳ Nog niet geloot — geen heats beschikbaar'
                    : '⏳ Nog geen programma voor deze categorie';
                return `<div class="heat-toon-dc heat-toon-wachten">
                    <div class="heat-toon-dc-kop">${kop}</div>
                    <div class="heat-toon-rij heat-toon-wacht-rij">${tekst}</div>
                </div>`;
            }
            const rijen = sortedRt.map(rt => {
                const rittenVanRonde = rondes[rt];
                const badge = BADGE[rt]
                    ? `<span class="badge ${BADGE[rt]}">${RLABEL[rt] || rt}</span>`
                    : '';
                // 1) Zit de rijder in een heat van deze ronde ÉN deze afstand?
                //    We matchen op dc_id (elke rit van deze ronde-groep heeft
                //    dezelfde dc_id), op distance_id, én op ronde_type.
                const dcIdVanRonde = rittenVanRonde[0]?.heat_dc_id;
                const mijn = mijnHeats.find(h =>
                    h.ronde_type === rt &&
                    h.dc_id === dcIdVanRonde &&
                    String(h.distance_id || '') === String(blok.distance_id || ''));
                if (mijn) {
                    // Vorige-ronde-compleet check: als de bron-ronde nog niet
                    // verwerkt is mag deze heat (KF/HF/Finale/Runner-up) nog
                    // niet als "ingedeeld" getoond worden — anders zou een
                    // coach al kunnen denken "mijn rijder zit in heat 2 KF"
                    // terwijl de series nog niet definitief klaar zijn.
                    if (mijn.vorige_niet_compleet) {
                        return `<div class="heat-toon-rij heat-toon-wacht-rij">${badge}
                            <span>⏳ Vorige ronde nog niet compleet</span>
                        </div>`;
                    }
                    const rit = rittenVanRonde.find(r => String(r.heat_id) === String(mijn.heat_id));
                    const heatNr = rit?.heat_nr ?? mijn.ronde;
                    return `<div class="heat-toon-rij">${badge}
                        <span><b>Heat ${esc(heatNr ?? '?')}</b></span>
                        <small style="color:#666">startpos ${esc(mijn.startpositie)}</small>
                    </div>`;
                }
                // 2) Niet geplaatst: drie sub-scenarios
                const definitief = rittenVanRonde.some(r => r.definitief);
                const heeftHeats = rittenVanRonde.some(r => r.heat_id);
                if (definitief) {
                    return `<div class="heat-toon-rij heat-toon-niet-geplaatst">${badge}
                        <span>niet geplaatst</span>
                    </div>`;
                }
                if (heeftHeats) {
                    // Heats bestaan maar zijn niet definitief → vorige ronde
                    // is er wel maar nog niet kompleet ingevoerd.
                    return `<div class="heat-toon-rij heat-toon-wacht-rij">${badge}
                        <span>⏳ Vorige ronde nog niet compleet</span>
                    </div>`;
                }
                // Geen heats voor deze ronde → loting moet nog plaatsvinden.
                return `<div class="heat-toon-rij heat-toon-wacht-rij">${badge}
                    <span>⏳ Nog niet geloot</span>
                </div>`;
            }).join('');
            return `<div class="heat-toon-dc">
                <div class="heat-toon-dc-kop">${kop}</div>
                ${rijen}
            </div>`;
        }).join('');

        // Samenvatting per DC: één rij per ingeschreven DC. De afmelding/
        // bevestiging gebeurt op DC-niveau, dus we tonen ook 1 badge per DC.
        // Heeft een DC meerdere afstanden (bv. "200m · 500m" combi) dan
        // staan die in dezelfde rij gescheiden door · zodat de coach ziet
        // wat erbij hoort, zonder dat de badge dubbel lijkt.
        const samenvatRijen = entries.map(e => {
            const st      = parseInt(e.entry_status ?? 1);
            const stLabel = STATUS_LABEL[st] ?? '?';
            const stIco   = STATUS_ICON[st] ?? '';
            const afstanden = e.afstanden || [];
            const naam = afstanden.length
                ? afstanden.map(a => a.distance_naam || '(afstand)').join(' · ')
                : (e.dc_naam || '(categorie)');
            return `
                <div class="sanc-samenvat-rij">
                    <span class="sanc-samenvat-naam">${esc(naam)}</span>
                    <span class="status-badge status-${st}">${stIco} ${esc(stLabel)}</span>
                </div>`;
        }).join('');

        return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.85rem">${esc(p.category || '')}</span>
            </div>
            ${samenvatRijen ? `<div class="sanc-samenvat">${samenvatRijen}</div>` : ''}
            ${dcBlokken}
        </div>`;
    }).join('');
}

function renderSancties() {
    const el = $('sancties');
    if (!coachLijst.length) {
        el.innerHTML = '<div class="leeg-melding">Nog geen rijders in je lijst.</div>';
        return;
    }
    const gesorteerd = [...coachLijst].sort((a,b) => parseInt(a.snr) - parseInt(b.snr));
    el.innerHTML = gesorteerd.map(p => {
        const info = coachInfoCache[p.license_key];
        const sanctieRijen = (info?.sancties || []).map(s => {
            const uitleg = SANCTIE_UITLEG[s.sanctie] ?? '';
            return `<div class="sanc-rij">
                <span class="sanc-rij-code">${esc(s.sanctie)}</span>
                ${uitleg ? `<small>— ${esc(uitleg)}</small>` : ''}
                · ${esc(s.rit_naam ?? '')}
                ${s.afstand_naam ? ` · ${esc(s.afstand_naam)}` : ''}
            </div>`;
        }).join('');
        return `<div class="sanc-persoon">
            <div class="sanc-persoon-kop">
                <span class="sanc-persoon-snr">${esc(p.snr)}</span>
                <span style="flex:1">${esc(p.full_name)}</span>
                <span style="color:#888;font-size:.8rem">${esc(p.category || '')}</span>
            </div>
            ${sanctieRijen || '<div class="sanc-leeg">Geen sancties.</div>'}
        </div>`;
    }).join('');
}

// ── Uitslagen-tab ────────────────────────────────────────────────────────────
let uitslagenCats = []; // [{dc_id, dc_naam, afstanden:[{distance_id, distance_naam}], klassement_beschikbaar}]

async function laadUitslagenCategorieen() {
    const sel = $('u-sel-cat');
    sel.innerHTML = '<option value="">Laden…</option>';
    try {
        const res = await safeFetch(`?action=categorieen&competition_id=${encodeURIComponent(selComp.value)}&_t=${Date.now()}`);
        const cats = await res.json();
        uitslagenCats = Array.isArray(cats) ? cats : [];
        if (!uitslagenCats.length) {
            sel.innerHTML = '<option value="">(nog geen uitslagen beschikbaar)</option>';
            $('uitslagen').innerHTML = '<div class="leeg-melding">Er zijn nog geen uitslagen bevestigd voor deze wedstrijd.</div>';
            $('u-afstand-rij').style.display = 'none';
            return;
        }
        sel.innerHTML = '<option value="">— kies categorie —</option>' +
            uitslagenCats.map(c => `<option value="${esc(c.dc_id)}">${esc(c.dc_naam)}</option>`).join('');
    } catch (e) {
        sel.innerHTML = `<option value="">Fout: ${esc(e.message)}</option>`;
    }
}

function opCatChange() {
    const dcId = $('u-sel-cat').value;
    const afstRij = $('u-afstand-rij');
    const selAf = $('u-sel-afstand');
    const uit = $('uitslagen');
    uit.innerHTML = '';
    if (!dcId) { afstRij.style.display = 'none'; return; }
    const cat = uitslagenCats.find(c => c.dc_id === dcId);
    if (!cat) return;
    const opts = [
        `<option value="">— kies afstand of klassement —</option>`,
        ...cat.afstanden.map(a => `<option value="afstand|${esc(a.distance_id)}">${esc(a.distance_naam || 'Afstand')}</option>`),
        cat.klassement_beschikbaar ? `<option value="klassement|">🏆 Klassement</option>` : ''
    ].filter(Boolean);
    selAf.innerHTML = opts.join('');
    afstRij.style.display = 'flex';
}

async function opAfstandChange() {
    const dcId = $('u-sel-cat').value;
    const afVal = $('u-sel-afstand').value;
    const uit = $('uitslagen');
    if (!dcId || !afVal) { uit.innerHTML = ''; return; }
    const [type, distId] = afVal.split('|');
    uit.innerHTML = '<div class="leeg-melding"><span class="spinner"></span> Laden…</div>';
    try {
        const url = `?action=uitslagen&competition_id=${encodeURIComponent(selComp.value)}&dc_id=${encodeURIComponent(dcId)}&type=${encodeURIComponent(type)}${distId ? '&distance_id=' + encodeURIComponent(distId) : ''}&_t=${Date.now()}`;
        const res = await safeFetch(url);
        const data = await res.json();
        if (data.error) { uit.innerHTML = `<div class="leeg-melding">⚠ ${esc(data.error)}</div>`; return; }
        uit.innerHTML = (type === 'klassement') ? renderKlassementTabel(data) : renderAfstandTabel(data);
    } catch (e) {
        uit.innerHTML = `<div class="leeg-melding">Fout: ${esc(e.message)}</div>`;
    }
}

function sl(s) { return s ?? ''; }

function renderAfstandTabel(data) {
    if (!data.rijders?.length) return '<div class="leeg-melding">Geen uitslagen beschikbaar.</div>';
    const mijn = new Set(coachLijst.map(p => parseInt(p.snr)));
    const heeftRnd = data.heeft_rondes, heeftPK = data.heeft_pk_punten;
    let hdr = '<th class="col-rang">#</th><th class="col-snr">Snr</th><th>Naam</th>';
    if (heeftRnd) hdr += '<th class="col-rnd">Rnd</th>';
    if (heeftPK)  hdr += '<th class="col-pk">Pnt</th>';
    hdr += '<th class="col-tijd">Tijd</th>';
    let rows = '';
    for (const r of data.rijders) {
        const isMij = mijn.has(parseInt(r.snr));
        const sanctie = sl(r.sanctie);
        rows += `<tr class="${isMij ? 'mijn' : ''}">
            <td class="col-rang">${r.rang ?? '—'}</td>
            <td class="col-snr">${esc(r.snr)}</td>
            <td>${esc(r.full_name)}${sanctie ? ` <span class="col-sanctie">${esc(sanctie)}</span>` : ''}</td>`;
        if (heeftRnd) rows += `<td class="col-rnd">${r.rondes ?? ''}</td>`;
        if (heeftPK)  rows += `<td class="col-pk">${r.pk_punten != null ? parseFloat(r.pk_punten) : ''}</td>`;
        rows += `<td class="col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : ''}</td>`;
        rows += '</tr>';
    }
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderKlassementTabel(data) {
    if (!data.rijders?.length) return '<div class="leeg-melding">Geen klassement beschikbaar.</div>';
    const mijn = new Set(coachLijst.map(p => parseInt(p.snr)));
    const afstanden = data.afstanden ?? [];
    let hdr = '<th class="col-rang">#</th><th class="col-snr">Snr</th><th>Naam</th>';
    for (const a of afstanden) {
        const kort = a.length > 6 ? a.substring(0,5) + '.' : a;
        hdr += `<th class="col-punten" title="${esc(a)}">${esc(kort)}</th>`;
    }
    hdr += '<th class="col-totaal">Tot</th>';
    let rows = '';
    for (const r of data.rijders) {
        const isMij = mijn.has(parseInt(r.snr));
        const detail = r.punten_detail ?? {};
        rows += `<tr class="${isMij ? 'mijn' : ''}">
            <td class="col-rang">${r.rang ?? '—'}</td>
            <td class="col-snr">${esc(r.snr)}</td>
            <td>${esc(r.full_name)}</td>`;
        for (const a of afstanden) {
            const p = detail[a];
            rows += `<td class="col-punten">${p != null ? parseFloat(p) : '—'}</td>`;
        }
        rows += `<td class="col-totaal">${r.punten_totaal != null ? parseFloat(r.punten_totaal) : '—'}</td>`;
        rows += '</tr>';
    }
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

async function toonRitDetail(el) {
    const ritNaam = el.dataset.ritNaam;
    const dcNaam  = el.dataset.dcNaam;
    const compId  = selComp.value;
    if (!ritNaam || !compId) return;

    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = '<div class="overlay-box"><div style="text-align:center;padding:24px"><span class="spinner"></span> Laden…</div></div>';
    overlay.onclick = e => { if (e.target === overlay) overlay.remove(); };
    document.body.appendChild(overlay);

    try {
        const res = await safeFetch(`?action=rit_detail&competition_id=${encodeURIComponent(compId)}&rit_naam=${encodeURIComponent(ritNaam)}&dc_naam=${encodeURIComponent(dcNaam)}`);
        const data = await res.json();
        const heat = data.heat;
        if (!heat || !heat.rijders?.length) {
            overlay.querySelector('.overlay-box').innerHTML =
                `<div class="heat-card-titel">
                    <button class="overlay-sluit" onclick="this.closest('.overlay').remove()" title="Sluiten">&times;</button>
                    ${esc(ritNaam)}
                 </div>
                 <div class="leeg-melding" style="padding:24px;text-align:center;color:#888">Startlijst is nog niet beschikbaar.</div>`;
            return;
        }
        const mijnSnrs = new Set(coachLijst.map(p => parseInt(p.snr)));
        const heeftRnd = heat.rijders.some(r => r.rondes != null);
        const heeftPK  = heat.rijders.some(r => r.pk_punten != null);
        const rijen = heat.rijders.map(r => {
            const isMij = mijnSnrs.has(parseInt(r.snr));
            const sanctie = r.sanctie ? ` <span style="color:#c00;font-weight:600;font-size:.85rem">${esc(r.sanctie)}</span>` : '';
            return `<tr class="${isMij ? 'mijn' : ''}">
                <td class="col-pos">${esc(r.startpositie)}</td>
                <td class="col-snr">${esc(r.snr)}</td>
                <td>${esc(r.full_name)}${sanctie}</td>
                ${heeftRnd ? `<td class="col-rnd">${r.rondes ?? ''}</td>` : ''}
                ${heeftPK  ? `<td class="col-pk">${r.pk_punten != null ? parseFloat(r.pk_punten) : ''}</td>` : ''}
                <td class="col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : ''}</td>
                <td class="col-fin">${esc(r.finishpositie ?? '')}</td>
            </tr>`;
        }).join('');
        const hdr = `<tr><th class="col-pos">#</th><th class="col-snr">Snr</th><th>Naam</th>
                    ${heeftRnd ? '<th class="col-rnd">Rnd</th>' : ''}
                    ${heeftPK  ? '<th class="col-pk">Pnt</th>' : ''}
                    <th class="col-tijd">Tijd</th><th class="col-fin">Fin</th></tr>`;
        overlay.querySelector('.overlay-box').innerHTML =
            `<div class="heat-card-titel">
                <button class="overlay-sluit" onclick="this.closest('.overlay').remove()" title="Sluiten">&times;</button>
                ${esc(ritNaam)}
             </div>
             <div class="overlay-body">
                <table class="heat-tabel">${hdr}${rijen}</table>
             </div>`;
    } catch (e) {
        overlay.querySelector('.overlay-box').innerHTML =
            `<button class="overlay-sluit" onclick="this.closest('.overlay').remove()" title="Sluiten">&times;</button>
             <div class="leeg-melding" style="padding:24px">Fout bij laden: ${esc(e.message)}</div>`;
    }
}

// ── Footer: org logo + sponsor-marquee (afgeleid van /public) ────────────────
function updateHeaderLogos(opt) {
    const footer  = $('org-footer');
    const logoEl  = $('footer-org-logo');
    const naamEl  = $('footer-org-naam');
    const sponsEl = $('footer-sponsors');
    const baanEl  = $('footer-baan-logo');
    if (!opt?.value) {
        footer.style.display = 'none';
        document.body.classList.remove('heeft-footer');
        return;
    }
    const orgLogo = opt.dataset.orgLogo || '';
    const orgNaam = opt.dataset.orgNaam || '';
    const baanLogo = opt.dataset.baanLogo || '';
    const baanVer  = opt.dataset.baanVereniging || '';
    let sponsors = [];
    try { sponsors = JSON.parse(opt.dataset.sponsors || '[]'); } catch { sponsors = []; }
    if (!orgLogo && !sponsors.length && !baanLogo && !baanVer) {
        footer.style.display = 'none';
        document.body.classList.remove('heeft-footer');
        return;
    }
    const cb = `?v=${Math.floor(Date.now() / 3600000)}`;
    logoEl.innerHTML = orgLogo ? `<img class="org-footer-logo" src="../${esc(orgLogo)}${cb}" alt="">` : '';
    naamEl.textContent = orgLogo ? '' : orgNaam;

    // Gastheer-vereniging-logo (rechts in footer). Heeft de baan geen logo
    // maar wel een vereniging-naam? Dan tonen we die als compacte tekst.
    if (baanLogo) {
        baanEl.innerHTML = `<img class="org-footer-logo" src="../${esc(baanLogo)}${cb}" alt="">`;
    } else if (baanVer) {
        baanEl.innerHTML = `<span class="org-footer-naam">${esc(baanVer)}</span>`;
    } else {
        baanEl.innerHTML = '';
    }
    // Altijd marquee, ook bij 1 sponsor — anders 'hangt' de enige logo
    // statisch. Min-duur 8s zodat 1 logo niet onhandig snel langs schiet.
    if (sponsors.length) {
        let imgs = '';
        for (const s of sponsors) {
            const img = `<img src="../${esc(s.logo)}${cb}" alt="${esc(s.naam)}" title="${esc(s.naam)}">`;
            imgs += s.url ? `<a href="${esc(s.url)}" target="_blank" rel="noopener">${img}</a>` : img;
        }
        const duur = Math.max(8, sponsors.length * 3);
        sponsEl.innerHTML = `<div class="sponsor-marquee"><div class="sponsor-marquee-inner" style="animation-duration:${duur}s">${imgs}${imgs}</div></div>`;
    } else {
        sponsEl.innerHTML = '';
    }
    footer.style.display = 'block';
    document.body.classList.add('heeft-footer');
}

// ── Info- en Help-overlays (coach-versie) ────────────────────────────────────
function toonInfo() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>Over InlineComp Coach</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">
            <h3>Wat is dit?</h3>
            <p>De <b>Coach-view</b> is een dashboard voor coaches: je bouwt per wedstrijd een
               eigen lijst met rijders en ziet vervolgens hun programma, status, sancties en
               uitslagen in één oogopslag.</p>
            <p>Je kunt een heel clubteam in één keer toevoegen, rijders op sponsor selecteren,
               of losse startnummers toevoegen. Je lijst wordt lokaal op je telefoon bewaard
               (per wedstrijd) — dus een refresh of een terugkeer naar de pagina laat 'm intact.</p>

            <h3>Geen login nodig</h3>
            <p>Alle data die je hier ziet is publiek (zelfde als op <a href="../public/">/public</a>).
               Je gebruikt gewoon je browser — niets te installeren.</p>

            <h3>Tip: toevoegen aan startscherm</h3>
            <p>Op je telefoon: open deze pagina in Safari/Chrome → menu → "Zet op startscherm".
               Dan opent-ie als een app en heb je 'm direct bij de hand aan de rand van de baan.</p>

            <h3>In ontwikkeling</h3>
            <p>Deze coach-view wordt actief doorontwikkeld. Feedback is zeer welkom!</p>

            <h3>Contact &amp; feedback</h3>
            <p>Heb je een vraag, suggestie of bug gevonden? Laat het weten:</p>
            <p style="text-align:center;margin:12px 0">
                <a href="mailto:inlinecomp@devriesen.com" style="display:inline-block;background:var(--oranje);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">inlinecomp@devriesen.com</a>
            </p>

            <h3>Anonieme bezoek-statistieken</h3>
            <p style="font-size:.85rem;color:#555">We tellen anoniem aantal bezoekers, actieve sessies en piek gelijktijdig online — puur om te zien hoe veel de app wordt gebruikt en om de hosting stabiel te houden. Er worden <b>geen IP-adressen of persoonsgegevens</b> opgeslagen en er zijn <b>geen derde partijen</b> betrokken.</p>

            <p style="font-size:.8rem;color:#999;text-align:center;margin-top:16px">InlineComp &copy; ${new Date().getFullYear()} Geert de Vries</p>
        </div>
    </div>`;
    document.body.appendChild(overlay);
}

function toonHelp() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>Hoe werkt de Coach-view?</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">
            <h3>Aan de slag</h3>
            <div class="help-stap"><span class="help-stap-nr">1</span>
                <span>Kies je <b>wedstrijd</b> bovenaan.</span></div>
            <div class="help-stap"><span class="help-stap-nr">2</span>
                <span>Voeg rijders toe aan je coach-lijst op drie manieren:
                <ul style="margin:4px 0 0 18px">
                    <li><b>Op club</b> — selecteer een club en alle rijders daarvan komen in je lijst.</li>
                    <li><b>Op sponsor</b> — idem op sponsor-naam.</li>
                    <li><b>Op startnummer</b> — typ een getal en druk op Toevoegen (of Enter).</li>
                </ul></span></div>
            <div class="help-stap"><span class="help-stap-nr">3</span>
                <span>Bekijk de tabs: <b>📋 Programma</b>, <b>🏃 Heats</b>, <b>⚠️ Sancties</b>, <b>📊 Uitslagen</b>.</span></div>

            <h3>Programma</h3>
            <p>Toont alle ritten van de wedstrijd. Ritten waar minstens één van jouw rijders in zit zijn
               <b>geel gemarkeerd</b> met een strip van hun startnummers aan de rechterkant.
               Tik een rit aan om de volledige startlijst te zien — jouw rijders zijn opnieuw geel gemarkeerd.</p>

            <h3>Sancties</h3>
            <p>Per rijder uit jouw lijst een kaartje met:</p>
            <ul style="margin:4px 0 8px 18px">
                <li><b>Status-badge</b> (Bevestigd / Niet getekend / Afgemeld / …)</li>
                <li>Alle <b>sancties</b> die in heats zijn geregistreerd (W1, W2, FS, DQ-SF, DNF, …)</li>
            </ul>
            <p>Let op <b>🚨 Niet getekend</b> — dan moet de rijder zélf snel even naar de jury-tafel
               om te tekenen (niet jij als coach, niet de ouders, alleen de rijder zelf).</p>

            <h3>Uitslagen</h3>
            <p>Kies een categorie + afstand om de volledige uitslag te zien, of bekijk het klassement.
               Ook hier worden jouw eigen rijders geel gemarkeerd.</p>

            <h3>Automatisch bijgewerkt</h3>
            <p>De pagina ververst zichzelf elke minuut zolang het tabblad zichtbaar is.
               Het tijdstip van de laatste verversing zie je rechtsboven (<b>🔄 HH:MM</b>).
               Direct verversen kan ook: trek de pagina <b>naar beneden</b> (pull-to-refresh)
               of dubbelklik op de blauwe kop.</p>

            <h3>Mededelingen</h3>
            <p>Bovenaan verschijnt een <b>📢-knop</b> zodra er een mededeling van de organisatie
               actief is. Belangrijke aankondigingen verschijnen automatisch als pop-up en blijven
               daarna onder die knop bereikbaar.</p>

            <h3>Privacy</h3>
            <p>Je coach-lijst wordt alleen lokaal op je telefoon bewaard (localStorage). Niemand
               anders ziet wie je op je lijst hebt staan.</p>
        </div>
    </div>`;
    document.body.appendChild(overlay);
}

// ── Init-flow ────────────────────────────────────────────────────────────────
// Filter-regel (1-op-1 uit /public):
// Drie onafhankelijke filters: Eerder · Vandaag · Later. Wedstrijd verschijnt
// als hij in tenminste één aangevinkte categorie valt. Alle drie uit → lege
// lijst met helder bericht.
function filterComps() {
    const nu = new Date();
    const gisteren = new Date(nu); gisteren.setDate(gisteren.getDate() - 1); gisteren.setHours(0,0,0,0);
    const morgen   = new Date(nu); morgen.setDate(morgen.getDate() + 1);   morgen.setHours(23,59,59,999);

    const toonOud      = $('chk-oud').checked;
    const toonVandaag  = $('chk-vandaag').checked;
    const toonToekomst = $('chk-toekomst').checked;
    const vorigeWaarde = selComp.value;

    if (!toonOud && !toonVandaag && !toonToekomst) {
        selComp.innerHTML = '<option value="">— Kies tenminste één filter hierboven —</option>';
        return;
    }

    selComp.innerHTML = '<option value="">— kies een wedstrijd —</option>';
    for (const c of alleComps) {
        const startDag = safeDatum(c.starts);
        const eindDag  = safeDatum(c.ends) ?? startDag;
        const isVandaag  = startDag && startDag <= morgen && eindDag >= gisteren;
        const isOud      = !isVandaag && eindDag   && eindDag   < gisteren;
        const isToekomst = !isVandaag && startDag && startDag > morgen;
        if (isVandaag  && !toonVandaag)  continue;
        if (isOud      && !toonOud)      continue;
        if (isToekomst && !toonToekomst) continue;

        const dtStr = startDag
            ? startDag.toLocaleDateString('nl-NL',{day:'numeric',month:'long',year:'numeric'})
            : '';
        // Verborgen wedstrijden: tonen als disabled met "(binnenkort)"
        // suffix — gebruiker ziet dat de wedstrijd er aankomt zonder
        // erop te kunnen klikken. Operator publiceert via Beheer.
        const verborgen = !Number(c.public_zichtbaar);
        const o = document.createElement('option');
        o.value = c.id;
        o.textContent = `${c.name}${dtStr ? ' — ' + dtStr : ''}${verborgen ? '  (binnenkort)' : ''}`;
        if (verborgen) o.disabled = true;
        o.dataset.orgLogo        = c.org_logo ?? '';
        o.dataset.orgNaam        = c.org_naam ?? '';
        o.dataset.baanLogo       = c.baan_logo ?? '';
        o.dataset.baanVereniging = c.baan_vereniging ?? '';
        o.dataset.sponsors       = JSON.stringify(c.sponsors ?? []);
        selComp.appendChild(o);
    }

    // Herstel selectie als die nog in de lijst staat en niet (inmiddels) disabled.
    const vorigeOpt = vorigeWaarde
        ? selComp.querySelector(`option[value="${vorigeWaarde}"]`)
        : null;
    if (vorigeOpt && !vorigeOpt.disabled) {
        selComp.value = vorigeWaarde;
    } else {
        // Auto-selecteer als er maar 1 selecteerbare wedstrijd over is —
        // disabled ('binnenkort') tellen niet mee, anders kreeg de
        // gebruiker bij vervolgstappen pas een 'niet beschikbaar'-fout.
        const opties = selComp.querySelectorAll('option[value]:not([value=""]):not([disabled])');
        if (opties.length === 1) {
            selComp.value = opties[0].value;
            selComp.dispatchEvent(new Event('change'));
        }
    }
}

async function laadCompetitions() {
    try {
        const res = await safeFetch('?action=competitions');
        const lijst = await res.json();
        if (!Array.isArray(lijst)) return;
        alleComps = lijst;

        // Directe-link-support: ?comp=<uuid> in de URL selecteert die wedstrijd.
        // Gebruikt door de QR-code op de coach-poster. Als de wedstrijd buiten
        // het "Vandaag"-venster valt (oud of toekomstig) vinken we automatisch
        // het juiste filter aan zodat de optie zichtbaar is.
        const urlParams = new URLSearchParams(window.location.search);
        const wantedComp = urlParams.get('comp');
        if (wantedComp) {
            const comp = alleComps.find(c => c.id === wantedComp);
            if (comp) {
                const nu = new Date();
                const startDag = comp.starts ? new Date(comp.starts) : null;
                const eindDag  = comp.ends   ? new Date(comp.ends)   : startDag;
                if (eindDag && eindDag < nu)   $('chk-oud').checked      = true;
                if (startDag && startDag > nu) $('chk-toekomst').checked = true;
            }
        }

        filterComps();

        // Na filteren: select 'm als de optie nu beschikbaar is, dan triggert
        // het bestaande change-event de auto-refresh + meldingen-check.
        if (wantedComp && selComp.querySelector(`option[value="${wantedComp}"]`)) {
            selComp.value = wantedComp;
            selComp.dispatchEvent(new Event('change'));
        }
    } catch (e) {
        selComp.innerHTML = '<option value="">Fout bij laden</option>';
    }
}

function zetStap2Enabled(enabled) {
    // Sectie 2 blijft altijd zichtbaar; de inputs schakelen we enable/disable
    // op basis van of er een wedstrijd is gekozen. Zo ziet de user meteen
    // wat er na stap 1 mogelijk is.
    $('btn-club-open').disabled    = !enabled;
    $('btn-sponsor-open').disabled = !enabled;
    inpSnr.disabled      = !enabled;
    btnToevoegen.disabled = !enabled;
    if (!enabled) {
        _clubAlle = [];
        _clubSel.clear();
        _sponsorAlle = [];
        _sponsorSel.clear();
        $('club-multi-label').textContent    = '— kies eerst een wedstrijd —';
        $('sponsor-multi-label').textContent = '— kies eerst een wedstrijd —';
        $('club-multi-paneel').hidden    = true;
        $('sponsor-multi-paneel').hidden = true;
        $('club-chips').innerHTML    = '';
        $('sponsor-chips').innerHTML = '';
    }
    updateToevoegenKnop();
}

// De Toevoegen-knop is alleen "echt klikbaar" (oranje volle kracht) als er
// iets gekozen/ingevuld is in één van de drie bronnen. Anders dimmen we 'm
// iets zodat de user ziet dat er nog niks te doen valt.
function updateToevoegenKnop() {
    if (!selComp.value) { btnToevoegen.disabled = true; return; }
    const heeftInvoer = !!(_clubSel.size > 0 || _sponsorSel.size > 0 || inpSnr.value.trim());
    btnToevoegen.disabled = !heeftInvoer;
}

async function opCompetitionChange() {
    const compId = selComp.value;
    const opt = selComp.options[selComp.selectedIndex];
    updateHeaderLogos(opt);
    const compInfoEl = $('comp-info');
    if (!compId) {
        zetStap2Enabled(false);
        secLijst.style.display = 'none';
        secProg.style.display = 'none';
        compInfoEl.style.display = 'none';
        compInfoEl.innerHTML = '';
        return;
    }
    // Comp-info kaartje vullen met wedstrijd-naam + datum (1-op-1 uit /public)
    const c = alleComps.find(x => x.id === compId);
    if (c) {
        const dt = safeDatum(c.starts);
        const dtStr = dt ? dt.toLocaleDateString('nl-NL', {weekday:'long', day:'numeric', month:'long', year:'numeric'}) : '';
        compInfoEl.innerHTML = `<strong>${esc(c.name)}</strong>${dtStr ? `<small>${esc(dtStr)}</small>` : ''}`;
        compInfoEl.style.display = 'block';
    }
    zetStap2Enabled(true);
    secProg.style.display = 'block';
    loadCoachLijst();
    coachInfoCache = {};
    uitslagenCats = [];
    $('u-sel-cat').innerHTML = '<option value="">— kies categorie —</option>';
    $('u-afstand-rij').style.display = 'none';
    $('uitslagen').innerHTML = '';
    renderChips();
    renderSancties();
    renderHeats();
    // Clubs + sponsors + programma parallel laden
    progEl.innerHTML = '<div class="leeg-melding"><span class="spinner"></span> Laden…</div>';
    programmaCache = null;
    try {
        const [clubsRes, sponsorsRes, progRes] = await Promise.all([
            safeFetch(`?action=clubs&competition_id=${encodeURIComponent(compId)}`),
            safeFetch(`?action=sponsors&competition_id=${encodeURIComponent(compId)}`),
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`),
        ]);
        const clubs    = await clubsRes.json();
        const sponsors = await sponsorsRes.json();
        programmaCache = await progRes.json();

        _clubAlle = Array.isArray(clubs) ? clubs : [];
        _clubSel.clear();
        renderClubMultiSelect();
        updateClubLabel();
        _sponsorAlle = Array.isArray(sponsors) ? sponsors : [];
        _sponsorSel.clear();
        renderSponsorMultiSelect();
        updateSponsorLabel();
        renderProgramma();
        // Status + sancties ophalen voor de al bestaande coach-lijst (uit localStorage)
        await laadCoachInfo();
        renderChips();
        renderSancties();
        renderHeats();
    } catch (e) {
        progEl.innerHTML = `<div class="leeg-melding">Fout: ${esc(e.message)}</div>`;
    }
}

// Eén gedeelde toevoeg-handler: pakt alle niet-lege bronnen tegelijk op
// (club, sponsor, startnummer) en voegt wat erin zit toe aan de coach-lijst.
async function voegAllesToe() {
    if (!selComp.value) return;
    const snr     = parseInt(inpSnr.value);
    if (_clubSel.size === 0 && _sponsorSel.size === 0 && !snr) {
        snrFb.textContent = 'Kies een club, sponsor of vul een startnummer in.';
        snrFb.style.color = '#b71c1c';
        return;
    }

    const meldingen = [];
    const foutMeldingen = [];

    // Multi-club: per geselecteerde club een API call.
    if (_clubSel.size > 0) {
        const clubList = [..._clubSel];
        let aantalTotaal = 0;
        for (const cl of clubList) {
            const res = await safeFetch(`?action=personen_by_club&competition_id=${encodeURIComponent(selComp.value)}&club=${encodeURIComponent(cl)}`);
            const lijst = await res.json();
            (Array.isArray(lijst) ? lijst : []).forEach(p => { if (voegToeAanLijst(p)) aantalTotaal++; });
        }
        meldingen.push(aantalTotaal
            ? `${aantalTotaal} rijder(s) van ${clubList.length} club${clubList.length>1?'s':''}`
            : `Geselecteerde clubs: geen nieuwe rijders`);
        _clubSel.clear();
        renderClubMultiSelect();
        updateClubLabel();
    }

    // Multi-sponsor: doe per geselecteerde sponsor een API call. Bij 5
    // sponsors zijn dat 5 calls — voor de coach-setup-fase prima.
    if (_sponsorSel.size > 0) {
        const sponsorList = [..._sponsorSel];
        let aantalTotaal = 0;
        for (const sp of sponsorList) {
            const res = await safeFetch(`?action=personen_by_sponsor&competition_id=${encodeURIComponent(selComp.value)}&sponsor=${encodeURIComponent(sp)}`);
            const lijst = await res.json();
            (Array.isArray(lijst) ? lijst : []).forEach(p => { if (voegToeAanLijst(p)) aantalTotaal++; });
        }
        meldingen.push(aantalTotaal
            ? `${aantalTotaal} rijder(s) van ${sponsorList.length} sponsor${sponsorList.length>1?'s':''}`
            : `Geselecteerde sponsors: geen nieuwe rijders`);
        _sponsorSel.clear();
        renderSponsorMultiSelect();
        updateSponsorLabel();
    }

    if (snr && snr >= 1) {
        const res = await safeFetch(`?action=person_by_startnummer&competition_id=${encodeURIComponent(selComp.value)}&snr=${snr}`);
        const p = await res.json();
        if (!p) {
            foutMeldingen.push(`Startnummer ${snr} niet gevonden`);
        } else if (voegToeAanLijst(p)) {
            meldingen.push(`${p.full_name} (snr ${snr})`);
        } else {
            meldingen.push(`Startnr ${snr}: stond al in lijst`);
        }
        inpSnr.value = '';
    }

    saveCoachLijst();
    await verversCoachLijstUI();
    updateToevoegenKnop();

    if (foutMeldingen.length) {
        snrFb.textContent = foutMeldingen.join(' · ');
        snrFb.style.color = '#b71c1c';
    } else if (meldingen.length) {
        snrFb.textContent = 'Toegevoegd: ' + meldingen.join(' · ');
        snrFb.style.color = '#2e7d32';
    }
}

// ── Listeners ────────────────────────────────────────────────────────────────
$('chk-oud').addEventListener('change', filterComps);
$('chk-vandaag').addEventListener('change', filterComps);
$('chk-toekomst').addEventListener('change', filterComps);
selComp.addEventListener('change', opCompetitionChange);
// Club/sponsor/startnr: niet direct toevoegen — wacht op de Toevoegen-knop.
// De knop wordt pas actief zodra er iets gekozen/ingevuld is.
inpSnr.addEventListener('input', updateToevoegenKnop);
btnToevoegen.addEventListener('click', voegAllesToe);
inpSnr.addEventListener('keydown', e => { if (e.key === 'Enter') voegAllesToe(); });

// ── Sponsor multi-select widget ──────────────────────────────────────────────
// Knop opent/sluit het paneel met checkboxes. Search filter, alle/niets
// helpers, klaar-knop sluit het paneel. State zit in _sponsorSel (Set).
function renderSponsorMultiSelect(filterTerm) {
    const lijst = $('sponsor-multi-lijst');
    if (!lijst) return;
    const f = (filterTerm || '').trim().toLowerCase();
    const gefilterd = f
        ? _sponsorAlle.filter(s => s.toLowerCase().includes(f))
        : _sponsorAlle;
    if (!gefilterd.length) {
        lijst.innerHTML = f
            ? '<div class="leeg">Geen sponsors gevonden.</div>'
            : '<div class="leeg">Geen sponsors in deze wedstrijd.</div>';
    } else {
        lijst.innerHTML = gefilterd.map(s => {
            const checked = _sponsorSel.has(s) ? 'checked' : '';
            return `<label><input type="checkbox" data-sponsor="${esc(s)}" ${checked}> <span>${esc(s)}</span></label>`;
        }).join('');
    }
    $('sponsor-multi-teller').textContent = `${_sponsorSel.size} geselecteerd`;
}
function updateSponsorLabel() {
    const lbl = $('sponsor-multi-label');
    const knop = $('btn-sponsor-open');
    const chipsWrap = $('sponsor-chips');
    if (!lbl || !knop || !chipsWrap) return;

    if (_sponsorSel.size === 0) {
        lbl.textContent = _sponsorAlle.length
            ? '— kies sponsor(s) —'
            : '— geen sponsors in deze wedstrijd —';
        knop.classList.remove('heeft-selectie');
        chipsWrap.innerHTML = '';
    } else {
        lbl.textContent = `${_sponsorSel.size} sponsor${_sponsorSel.size === 1 ? '' : 's'} gekozen — klik op Toevoegen`;
        knop.classList.add('heeft-selectie');
        // Chips eronder zodat operator direct ziet wat hij gekozen heeft
        // (en eentje kan weghalen zonder paneel weer te openen).
        chipsWrap.innerHTML = [..._sponsorSel]
            .map(s => `<span class="sponsor-chip" data-sponsor="${esc(s)}" title="Klik om te verwijderen">${esc(s)}</span>`)
            .join('');
    }
}

$('btn-sponsor-open').addEventListener('click', () => {
    const paneel = $('sponsor-multi-paneel');
    const open = !paneel.hidden;
    if (open) { paneel.hidden = true; return; }
    if (!_sponsorAlle.length) return;
    paneel.hidden = false;
    $('sponsor-multi-zoek').value = '';
    renderSponsorMultiSelect();
    setTimeout(() => $('sponsor-multi-zoek').focus(), 50);
});

// Klik buiten paneel = sluiten
document.addEventListener('click', (ev) => {
    const paneel = $('sponsor-multi-paneel');
    if (!paneel || paneel.hidden) return;
    const wrap = paneel.parentElement; // .sponsor-multi-wrap
    if (wrap && !wrap.contains(ev.target)) paneel.hidden = true;
});

$('sponsor-multi-zoek').addEventListener('input', (ev) => {
    renderSponsorMultiSelect(ev.target.value);
});

$('sponsor-multi-lijst').addEventListener('change', (ev) => {
    const inp = ev.target;
    if (!inp.matches('input[type="checkbox"]')) return;
    const sp = inp.dataset.sponsor;
    if (inp.checked) _sponsorSel.add(sp); else _sponsorSel.delete(sp);
    $('sponsor-multi-teller').textContent = `${_sponsorSel.size} geselecteerd`;
    updateSponsorLabel();
    updateToevoegenKnop();
});

$('sponsor-multi-alles').addEventListener('click', () => {
    // Alleen de momenteel zichtbare (gefilterde) sponsors aanvinken — anders
    // verbergt een filter een hele groep die de operator misschien niet
    // wilde meenemen.
    const zoek = $('sponsor-multi-zoek').value || '';
    const f = zoek.trim().toLowerCase();
    const gefilterd = f
        ? _sponsorAlle.filter(s => s.toLowerCase().includes(f))
        : _sponsorAlle;
    gefilterd.forEach(s => _sponsorSel.add(s));
    renderSponsorMultiSelect(zoek);
    updateSponsorLabel();
    updateToevoegenKnop();
});
$('sponsor-multi-niets').addEventListener('click', () => {
    _sponsorSel.clear();
    renderSponsorMultiSelect($('sponsor-multi-zoek').value || '');
    updateSponsorLabel();
    updateToevoegenKnop();
});
$('sponsor-multi-klaar').addEventListener('click', () => {
    $('sponsor-multi-paneel').hidden = true;
});

// Chip-klik: sponsor uit de selectie halen, zonder paneel te openen.
$('sponsor-chips').addEventListener('click', (ev) => {
    const chip = ev.target.closest('.sponsor-chip');
    if (!chip) return;
    const sp = chip.dataset.sponsor;
    if (sp) {
        _sponsorSel.delete(sp);
        updateSponsorLabel();
        updateToevoegenKnop();
    }
});

// ── Club multi-select widget (zelfde patroon als sponsor) ────────────────────
// Verschil: clubs zijn objects {full, short}, search matcht op beide;
// label toont "SHORT - Full" indien short bekend, anders alleen full.
function _clubLabel(c) { return c.short ? `${c.short} - ${c.full}` : c.full; }

function renderClubMultiSelect(filterTerm) {
    const lijst = $('club-multi-lijst');
    if (!lijst) return;
    const f = (filterTerm || '').trim().toLowerCase();
    const gefilterd = f
        ? _clubAlle.filter(c =>
            c.full.toLowerCase().includes(f) ||
            (c.short && c.short.toLowerCase().includes(f)))
        : _clubAlle;
    if (!gefilterd.length) {
        lijst.innerHTML = f
            ? '<div class="leeg">Geen clubs gevonden.</div>'
            : '<div class="leeg">Geen clubs in deze wedstrijd.</div>';
    } else {
        lijst.innerHTML = gefilterd.map(c => {
            const checked = _clubSel.has(c.full) ? 'checked' : '';
            return `<label><input type="checkbox" data-club="${esc(c.full)}" ${checked}> <span>${esc(_clubLabel(c))}</span></label>`;
        }).join('');
    }
    $('club-multi-teller').textContent = `${_clubSel.size} geselecteerd`;
}

function updateClubLabel() {
    const lbl = $('club-multi-label');
    const knop = $('btn-club-open');
    const chipsWrap = $('club-chips');
    if (!lbl || !knop || !chipsWrap) return;

    if (_clubSel.size === 0) {
        lbl.textContent = _clubAlle.length
            ? '— kies club(s) —'
            : '— geen clubs in deze wedstrijd —';
        knop.classList.remove('heeft-selectie');
        chipsWrap.innerHTML = '';
    } else {
        lbl.textContent = `${_clubSel.size} club${_clubSel.size === 1 ? '' : 's'} gekozen — klik op Toevoegen`;
        knop.classList.add('heeft-selectie');
        // Toon korte label voor chip (SHORT als bekend, anders FULL)
        chipsWrap.innerHTML = [..._clubSel].map(full => {
            const c = _clubAlle.find(x => x.full === full) || {full};
            const labelText = c.short || c.full;
            return `<span class="sponsor-chip" data-club="${esc(full)}" title="Klik om te verwijderen">${esc(labelText)}</span>`;
        }).join('');
    }
}

$('btn-club-open').addEventListener('click', () => {
    const paneel = $('club-multi-paneel');
    const open = !paneel.hidden;
    if (open) { paneel.hidden = true; return; }
    if (!_clubAlle.length) return;
    paneel.hidden = false;
    $('club-multi-zoek').value = '';
    renderClubMultiSelect();
    setTimeout(() => $('club-multi-zoek').focus(), 50);
});
document.addEventListener('click', (ev) => {
    const paneel = $('club-multi-paneel');
    if (!paneel || paneel.hidden) return;
    const wrap = paneel.parentElement;
    if (wrap && !wrap.contains(ev.target)) paneel.hidden = true;
});
$('club-multi-zoek').addEventListener('input', (ev) => {
    renderClubMultiSelect(ev.target.value);
});
$('club-multi-lijst').addEventListener('change', (ev) => {
    const inp = ev.target;
    if (!inp.matches('input[type="checkbox"]')) return;
    const cl = inp.dataset.club;
    if (inp.checked) _clubSel.add(cl); else _clubSel.delete(cl);
    $('club-multi-teller').textContent = `${_clubSel.size} geselecteerd`;
    updateClubLabel();
    updateToevoegenKnop();
});
$('club-multi-alles').addEventListener('click', () => {
    const zoek = $('club-multi-zoek').value || '';
    const f = zoek.trim().toLowerCase();
    const gefilterd = f
        ? _clubAlle.filter(c =>
            c.full.toLowerCase().includes(f) ||
            (c.short && c.short.toLowerCase().includes(f)))
        : _clubAlle;
    gefilterd.forEach(c => _clubSel.add(c.full));
    renderClubMultiSelect(zoek);
    updateClubLabel();
    updateToevoegenKnop();
});
$('club-multi-niets').addEventListener('click', () => {
    _clubSel.clear();
    renderClubMultiSelect($('club-multi-zoek').value || '');
    updateClubLabel();
    updateToevoegenKnop();
});
$('club-multi-klaar').addEventListener('click', () => {
    $('club-multi-paneel').hidden = true;
});
$('club-chips').addEventListener('click', (ev) => {
    const chip = ev.target.closest('.sponsor-chip');
    if (!chip) return;
    const cl = chip.dataset.club;
    if (cl) {
        _clubSel.delete(cl);
        updateClubLabel();
        updateToevoegenKnop();
    }
});
$('btn-wis-alles').addEventListener('click', async () => {
    if (!coachLijst.length) return;
    const ok = await bevestig({
        titel: 'Coach-lijst wissen?',
        tekst: `Je staat op het punt <b>alle ${coachLijst.length} rijder${coachLijst.length === 1 ? '' : 's'}</b> uit je coach-lijst te verwijderen.<br><br>Dit kan niet ongedaan gemaakt worden.`,
        bevestigLabel: 'Ja, wis alles',
        annuleerLabel: 'Annuleren',
    });
    if (!ok) return;
    coachLijst = [];
    coachInfoCache = {};
    saveCoachLijst();
    await verversCoachLijstUI();
});

// Tab-switch Programma ↔ Sancties ↔ Uitslagen
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === btn));
        const tab = btn.dataset.tab;
        document.querySelectorAll('.tab-pane').forEach(p =>
            p.classList.toggle('active', p.id === 'tab-' + tab));
        // Categorieën altijd opnieuw laden bij switch naar uitslagen-tab —
        // klassement_beschikbaar (publish/intrek-vlag) kan tussentijds
        // gewijzigd zijn. Cache-busting via _t in laadUitslagenCategorieen.
        if (tab === 'uitslagen' && selComp.value) {
            laadUitslagenCategorieen();
        }
    });
});

$('u-sel-cat').addEventListener('change', opCatChange);
$('u-sel-afstand').addEventListener('change', opAfstandChange);

// ── Mededelingen (pop-ups bij belangrijke aankondigingen) ──────────────
const _MELDING_PRIO = {
    info:   { kleur: '#1a3a5c', bg: '#e8f0f7', icoon: 'ℹ️' },
    warn:   { kleur: '#7a5800', bg: '#fff8d6', icoon: '⚠️' },
    urgent: { kleur: '#a00',    bg: '#ffe5e5', icoon: '🚨' },
};
// Sleutel per melding-scope: globaal (geen competition_id) krijgt eigen
// localStorage-bucket zodat 'gezien' niet wisselt als je van wedstrijd switcht.
const _meldingScope = (m) => m?.competition_id ? m.competition_id : 'global';
const _meldingenLsKey = (scope) => `meldingen_gezien_${scope}`;
const _gezienSet = (scope) => {
    try { return new Set(JSON.parse(localStorage.getItem(_meldingenLsKey(scope)) || '[]')); }
    catch { return new Set(); }
};
const _markGezien = (scope, id) => {
    const set = _gezienSet(scope);
    set.add(id);
    localStorage.setItem(_meldingenLsKey(scope), JSON.stringify([...set]));
};
let _meldingLijst = [];
let _meldingActief = false;

async function checkMeldingen(compId) {
    // compId leeg → alleen globale meldingen ophalen (landing-pagina).
    // compId gevuld → wedstrijd-specifiek + globaal samen (één call).
    try {
        const url = compId
            ? '../api/meldingen.php?comp_id=' + encodeURIComponent(compId) + '&_t=' + Date.now()
            : '../api/meldingen.php?global=1&_t=' + Date.now();
        const res = await safeFetch(url);
        const lijst = await res.json();
        if (!Array.isArray(lijst)) return;
        const nu = Date.now();
        _meldingLijst = lijst.filter(m => {
            const van = m.geldig_van ? Date.parse(m.geldig_van.replace(' ', 'T')) : 0;
            const tot = m.geldig_tot ? Date.parse(m.geldig_tot.replace(' ', 'T')) : null;
            if (van && van > nu)        return false;
            if (tot !== null && tot < nu) return false;
            return true;
        });
        updateMeldingenBadge();
        if (!_meldingActief) toonVolgendeMelding(compId);
    } catch { /* stil */ }
}

function updateMeldingenBadge() {
    const btn = document.getElementById('btn-meldingen-overzicht');
    const badge = document.getElementById('meldingen-badge');
    if (!btn || !badge) return;
    if (_meldingLijst.length > 0) {
        btn.style.display = '';
        badge.textContent = _meldingLijst.length;
        badge.style.display = '';
    } else {
        btn.style.display = 'none';
        badge.style.display = 'none';
    }
}

function toonMeldingenOverzicht() {
    if (!_meldingLijst.length) return;
    const escFn = (typeof esc === 'function') ? esc : (s => String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])));
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9400;display:flex;align-items:flex-start;justify-content:center;padding:4vh 1rem;overflow-y:auto;';
    const items = _meldingLijst.map(m => {
        const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
        const tijd = m.geldig_van
            ? new Date(m.geldig_van.replace(' ', 'T')).toLocaleString('nl-NL',
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        const tot = m.geldig_tot
            ? ' tot ' + new Date(m.geldig_tot.replace(' ', 'T')).toLocaleString('nl-NL',
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        return `<div style="background:${stijl.bg};border-left:4px solid ${stijl.kleur};
                            padding:.7rem .9rem;margin-bottom:.6rem;border-radius:5px;">
            <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.3rem;">
                <span style="font-size:1.2rem">${stijl.icoon}</span>
                <strong style="color:${stijl.kleur};flex:1;">${escFn(m.titel)}</strong>
            </div>
            <div style="color:#222;line-height:1.4;font-size:.9rem;white-space:pre-wrap;">${escFn(m.bericht)}</div>
            <div style="font-size:.75rem;color:#888;margin-top:.3rem;">${escFn(tijd)}${escFn(tot)}</div>
        </div>`;
    }).join('');
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:8px;max-width:480px;width:100%;
                    box-shadow:0 10px 30px rgba(0,0,0,.3);">
            <div style="display:flex;align-items:center;justify-content:space-between;
                        padding:.8rem 1rem;border-bottom:1px solid #e0e0e0;">
                <h3 style="margin:0;color:var(--blauw);font-size:1.05rem;">📢 Mededelingen</h3>
                <button class="meld-overz-sluit" style="background:none;border:none;
                        font-size:1.6rem;cursor:pointer;color:#666;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:1rem;">${items}</div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-overz-sluit').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

document.getElementById('btn-meldingen-overzicht')?.addEventListener('click', toonMeldingenOverzicht);
function toonVolgendeMelding(compId) {
    if (_meldingActief) return;
    for (const m of _meldingLijst) {
        const scope = _meldingScope(m);
        if (!_gezienSet(scope).has(m.id)) { toonMelding(m, compId); return; }
    }
}
function toonMelding(m, compId) {
    if (_meldingActief) return;
    _meldingActief = true;
    const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
    const overlay = document.createElement('div');
    // Overlay scrolt zelf óók (overflow-y:auto) als achterval voor heel kleine
    // schermen waar zelfs de inner-box met max-height: 90vh nog te hoog is.
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9500;display:flex;align-items:center;justify-content:center;padding:1rem;overflow-y:auto;';
    const escFn = (typeof esc === 'function') ? esc : (s => String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])));
    // Inner-box als flex-column: header + scrollable bericht + knop. Bericht-
    // div krijgt overflow-y:auto + min-height:0 (cruciaal voor flex-children),
    // knop heeft flex-shrink:0 zodat 'ie altijd onderaan zichtbaar blijft.
    overlay.innerHTML = `
        <div style="background:${stijl.bg};border:3px solid ${stijl.kleur};border-radius:10px;
                    max-width:400px;width:100%;max-height:calc(100vh - 2rem);
                    display:flex;flex-direction:column;
                    box-shadow:0 10px 40px rgba(0,0,0,.4);animation:meldingPop .3s ease-out;">
            <div style="display:flex;align-items:center;gap:.6rem;padding:1.5rem 1.5rem 0;flex-shrink:0;">
                <span style="font-size:1.8rem">${stijl.icoon}</span>
                <h2 style="margin:0;color:${stijl.kleur};font-size:1.1rem;flex:1;">${escFn(m.titel)}</h2>
            </div>
            <div style="color:#222;line-height:1.5;font-size:.95rem;
                        white-space:pre-wrap;padding:.6rem 1.5rem 1rem;
                        overflow-y:auto;flex:1 1 auto;min-height:0;">${escFn(m.bericht)}</div>
            <div style="padding:0 1.5rem 1.5rem;flex-shrink:0;">
                <button class="meld-ok" style="background:${stijl.kleur};color:#fff;border:none;
                                                padding:.6rem 1.4rem;border-radius:6px;font-size:1rem;
                                                font-weight:600;cursor:pointer;width:100%;">
                    ✓ Begrepen
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-ok').addEventListener('click', () => {
        _markGezien(_meldingScope(m), m.id);
        overlay.remove();
        _meldingActief = false;
        // Direct doorrollen — werkt ook zonder geselecteerde wedstrijd
        // (globale meldingen mogen altijd aaneengeschakeld scrollen).
        toonVolgendeMelding(selComp.value);
    });
}
(() => {
    const style = document.createElement('style');
    style.textContent = '@keyframes meldingPop { from {opacity:0;transform:scale(.85)} to {opacity:1;transform:scale(1)} }';
    document.head.appendChild(style);
})();

// ── Pull-to-refresh ──────────────────────────────────────────────────────────
// Alleen actief als we boven aan de pagina zijn én er een wedstrijd gekozen is.
// Trekt het programma opnieuw op (zelfde endpoint, cache is 30s).
(() => {
    const ptrEl = $('ptr');
    const THRESHOLD = 70;   // px slepen voor trigger
    const PTR_COOLDOWN_MS = 30_000;  // min tijd tussen 2 PTR-acties
    let startY = null, dragY = 0, actief = false, bezigLaden = false;
    let ptrLaatste = 0;

    async function herlaadProgramma() {
        if (!selComp.value || bezigLaden) return;
        // Cooldown: bij PTR < 30s na vorige tonen we kort een melding ipv
        // de server opnieuw aanroepen. Voorkomt burst bij ongeduld of
        // per-ongeluk-twee-keer-pullen.
        const sindsLaatste = Date.now() - ptrLaatste;
        if (ptrLaatste && sindsLaatste < PTR_COOLDOWN_MS) {
            const wachten = Math.ceil((PTR_COOLDOWN_MS - sindsLaatste) / 1000);
            ptrEl.classList.add('laadt');
            ptrEl.textContent = `⏳ Even wachten (${wachten}s)`;
            setTimeout(() => { ptrEl.classList.remove('zichtbaar','laadt'); }, 1200);
            return;
        }
        bezigLaden = true;
        ptrEl.classList.add('laadt');
        ptrEl.textContent = '⟳ Vernieuwen…';
        // Mededelingen-check parallel — pop-up zodra een nieuwe binnenkomt
        if (typeof checkMeldingen === 'function') checkMeldingen(selComp.value);
        try {
            const res = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(selComp.value)}&_ts=${Date.now()}`);
            programmaCache = await res.json();
            renderProgramma();
            // Bij elke refresh ook status + sancties opnieuw: kan veranderen tijdens de dag
            await laadCoachInfo();
            renderChips();
            renderSancties();
            renderHeats();
            // Uitslagen-tab: categorieën + actieve uitslag herladen als er een gekozen is
            uitslagenCats = [];
            if (document.querySelector('.tab-btn[data-tab="uitslagen"]').classList.contains('active')) {
                const huidigDc = $('u-sel-cat').value;
                const huidigAf = $('u-sel-afstand').value;
                await laadUitslagenCategorieen();
                if (huidigDc) {
                    $('u-sel-cat').value = huidigDc; opCatChange();
                    if (huidigAf) { $('u-sel-afstand').value = huidigAf; opAfstandChange(); }
                }
            }
            ptrLaatste = Date.now();
            ptrEl.textContent = '✓ Bijgewerkt';
            setTimeout(() => { ptrEl.classList.remove('zichtbaar','laadt'); }, 600);
        } catch (e) {
            ptrEl.textContent = '⚠ Fout bij vernieuwen';
            setTimeout(() => { ptrEl.classList.remove('zichtbaar','laadt'); }, 1200);
        } finally {
            bezigLaden = false;
        }
    }

    document.addEventListener('touchstart', e => {
        if (window.scrollY > 0 || bezigLaden || !selComp.value) { startY = null; return; }
        if (e.touches.length !== 1) { startY = null; return; }
        startY = e.touches[0].clientY;
        dragY = 0;
        actief = false;
    }, { passive:true });

    document.addEventListener('touchmove', e => {
        if (startY === null) return;
        dragY = e.touches[0].clientY - startY;
        if (dragY <= 0) { if (actief) { ptrEl.classList.remove('zichtbaar'); actief = false; } return; }
        if (dragY > 30 && !actief) { ptrEl.classList.add('zichtbaar'); actief = true; }
        ptrEl.textContent = dragY >= THRESHOLD
            ? '↑ Laat los om te vernieuwen' : '↓ Trek verder om te vernieuwen';
    }, { passive:true });

    document.addEventListener('touchend', () => {
        if (startY === null) return;
        const was = dragY;
        startY = null; dragY = 0;
        if (actief && was >= THRESHOLD) {
            herlaadProgramma();
        } else if (actief) {
            ptrEl.classList.remove('zichtbaar'); actief = false;
        }
    });

    // Desktop-fallback: dubbelklik op de header refreshed ook het programma
    document.querySelector('header').addEventListener('dblclick', herlaadProgramma);

    // ── Auto-refresh elke 3 minuten ─────────────────────────────────────────
    // Alleen actief als er een wedstrijd gekozen is én de tab zichtbaar is.
    // Pauzeren bij verborgen tab scheelt verkeer én batterij. Na terugkeer
    // pakt-ie meteen weer op om de user een verse staat te geven.
    // 3 min — frequente updates komen via meldingen-push; deze poll is
    // alleen vangnet voor passieve weergave. Lagere frequentie scheelt
    // serverbelasting bij grote wedstrijden.
    const AUTO_REFRESH_MS = 180_000;
    let autoTick = null;
    const lastEl = document.createElement('div');
    lastEl.className = 'auto-refresh-stempel';
    lastEl.title = 'Laatste automatische verversing';
    document.body.appendChild(lastEl);
    const zetStempel = () => {
        const d = new Date();
        const hh = String(d.getHours()).padStart(2,'0');
        const mm = String(d.getMinutes()).padStart(2,'0');
        lastEl.textContent = `🔄 ${hh}:${mm}`;
    };
    // Bereken interval op basis van consecutiveFails: bij fouten progressief
    // langer wachten zodat we de server niet hameren als hij eruit ligt.
    // 0-1 fouten → 60s, 2 → 90s, 3+ → 120s.
    const _tickInterval = () => {
        const f = _conn.consecutiveFails;
        if (f >= 3) return Math.max(AUTO_REFRESH_MS, 120_000);
        if (f === 2) return Math.max(AUTO_REFRESH_MS, 90_000);
        return AUTO_REFRESH_MS;
    };
    const _scheduleTick = () => {
        stopAutoRefresh();
        if (!selComp.value || document.hidden) return;
        autoTick = setTimeout(async () => {
            autoTick = null;
            if (document.hidden || !selComp.value) return _scheduleTick();
            await herlaadProgramma();
            zetStempel();
            _scheduleTick();
        }, _tickInterval());
    };
    const startAutoRefresh = () => _scheduleTick();
    const stopAutoRefresh = () => {
        if (autoTick) { clearTimeout(autoTick); autoTick = null; }
    };
    // Hook voor _conn: bij online-event direct refresh + scheduling resetten.
    _conn.refreshHook = () => {
        if (selComp.value && !document.hidden) {
            herlaadProgramma().then(zetStempel).finally(_scheduleTick);
        }
    };
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopAutoRefresh();
        else { herlaadProgramma().then(zetStempel); startAutoRefresh(); }
    });
    selComp.addEventListener('change', () => {
        if (selComp.value) {
            zetStempel();
            startAutoRefresh();
            // Direct meldingen-check — niet wachten op eerste 60s tick
            if (typeof checkMeldingen === 'function') checkMeldingen(selComp.value);
        } else {
            stopAutoRefresh();
            lastEl.textContent = '';
            // Switch terug naar landing → één-malig globale meldingen ophalen.
            if (typeof checkMeldingen === 'function') checkMeldingen('');
        }
    });
    // Initieel: als er al een wedstrijd voorgeselecteerd is, meteen tick starten.
    // Globale meldingen-check loopt sowieso bij page-open (compId mag leeg zijn).
    if (selComp.value) { zetStempel(); startAutoRefresh(); }
    if (typeof checkMeldingen === 'function') checkMeldingen(selComp.value || '');
})();

laadCompetitions();

// ── PWA: service worker + install prompt ─────────────────────────────────
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

let _deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredPrompt = e;
    if (!localStorage.getItem('pwa-coach-dismissed')) {
        document.getElementById('pwa-banner').style.display = '';
    }
});

document.getElementById('pwa-install')?.addEventListener('click', async () => {
    if (!_deferredPrompt) return;
    _deferredPrompt.prompt();
    const result = await _deferredPrompt.userChoice;
    if (result.outcome === 'accepted') {
        document.getElementById('pwa-banner').style.display = 'none';
    }
    _deferredPrompt = null;
});

document.getElementById('pwa-sluit')?.addEventListener('click', () => {
    document.getElementById('pwa-banner').style.display = 'none';
    localStorage.setItem('pwa-coach-dismissed', '1');
});

window.addEventListener('appinstalled', () => {
    document.getElementById('pwa-banner').style.display = 'none';
});
</script>
</body>
</html>
