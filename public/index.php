<?php
// ============================================================
//  InlineComp – Publieke rijder-lookup
//  Geen login vereist. Drie tabs: Programma / Heats / Resultaten
// ============================================================
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config_inlinecomp.php';

// ── Bezoektracking: upsert session-hit in public_visits ─────────────────────
// Alleen op de echte HTML pageload (geen action=...) om AJAX-calls niet
// dubbel te tellen. Cookie wordt lightweight: alleen session-id voor tracking.
if (empty($_GET['action'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('ICPUB');  // aparte cookie-naam om admin-sessies niet te raken
        session_set_cookie_params([
            'lifetime' => 0,         // browser-sessie
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }
    $sid = session_id();
    if ($sid) {
        try {
            $pdo->prepare(
                "INSERT INTO public_visits (session_id) VALUES (?)
                 ON DUPLICATE KEY UPDATE last_seen = NOW(), hits = hits + 1"
            )->execute([$sid]);
            // Piek bijwerken (vandaag + all-time). Eén UPDATE met subquery:
            // goedkoop genoeg om op elke pageload te draaien.
            $pdo->prepare("
                UPDATE peak_stats SET
                    peak_today = CASE
                        WHEN peak_today_date = CURDATE()
                            THEN GREATEST(peak_today, (SELECT COUNT(*) FROM public_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE))
                        ELSE (SELECT COUNT(*) FROM public_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE)
                    END,
                    peak_today_date = CURDATE(),
                    peak_all_time_at = IF(
                        (SELECT COUNT(*) FROM public_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE) > peak_all_time,
                        NOW(), peak_all_time_at),
                    peak_all_time = GREATEST(peak_all_time,
                        (SELECT COUNT(*) FROM public_visits WHERE last_seen > NOW() - INTERVAL 5 MINUTE))
                WHERE scope = 'public'
            ")->execute();
        } catch (Throwable $e) { /* tracking mag nooit de pagina breken */ }
    }
}

// ── Rate limiting: max 10 requests per 5 seconden per IP ────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlFile = sys_get_temp_dir() . '/rl_' . md5($ip);
    $now = time();
    $hits = @json_decode(@file_get_contents($rlFile), true);
    if (!is_array($hits)) $hits = [];
    // Verwijder hits ouder dan 5 seconden
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
    header('Cache-Control: public, max-age=60'); // 60 sec browser cache
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.starts, c.ends,
                   c.organisatie_id, o.logo_path AS org_logo, o.naam AS org_naam
            FROM competitions c
            JOIN competition_tijdschema ct ON ct.competition_id = c.id
            LEFT JOIN organisaties o ON o.id = c.organisatie_id
            ORDER BY c.starts DESC
        ");
        $stmt->execute();
        $comps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sponsors per organisatie ophalen
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
                    'naam' => $sp['naam'],
                    'logo' => $sp['logo_path'],
                    'url'  => $sp['url'],
                ];
            }
        }

        // Sponsors toevoegen per wedstrijd
        foreach ($comps as &$c) {
            $c['sponsors'] = $sponsorMap[$c['organisatie_id'] ?? ''] ?? [];
        }
        unset($c);

        echo json_encode($comps, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: programma (extern tijdschema) ───────────────────────────────────────
if ($action === 'programma') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=30'); // 30 sec browser cache
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        $tsStmt = $pdo->prepare("SELECT id FROM competition_tijdschema WHERE competition_id = ? LIMIT 1");
        $tsStmt->execute([$compId]);
        $tsId = $tsStmt->fetchColumn();
        if (!$tsId) { echo json_encode([]); exit; }

        $stmt = $pdo->prepare("
            SELECT r.volgorde, r.rit_naam, r.ronde_type, r.heat_nr, r.dc_naam,
                   r.combi_group,
                   b.blok_type, b.tijdstip, b.duur, b.heat_duur, b.opmerking,
                   h.id AS heat_id,
                   h.ronde AS heat_ronde,
                   h.distance_combination_id AS heat_dc_id,
                   (SELECT COUNT(*) FROM heat_entries he2
                    WHERE he2.heat_id = h.id
                   ) AS entries_count,
                   (SELECT COUNT(*) FROM results res
                    JOIN heat_entries he ON he.id = res.heat_entry_id
                    WHERE he.heat_id = h.id AND res.finishpositie IS NOT NULL
                   ) AS resultaten_count
            FROM tijdschema_ritten r
            LEFT JOIN tijdschema_blokken b ON b.id = r.blok_id
            LEFT JOIN heats h ON h.tijdschema_rit_id = r.id AND h.competition_id = ?
            WHERE r.tijdschema_id = ?
            ORDER BY r.volgorde
        ");
        $stmt->execute([$compId, $tsId]);
        $rittenRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Bepaal per rit of de startlijst definitief is:
        // - Ronde 1 (series): definitief als er rijders in de heat zitten
        // - Ronde > 1: definitief als er rijders in zitten EN de vorige ronde compleet is
        $rondeCheck = []; // "dc_id_ronde" => bool
        $checkVorigeRonde = function($dcId, $ronde) use ($pdo, $compId, &$rondeCheck) {
            if ($ronde <= 1) return true;
            $ck = "{$dcId}_{$ronde}";
            if (isset($rondeCheck[$ck])) return $rondeCheck[$ck];

            // Zoek de werkelijk vorige ronde (hoogste ronde < huidige die heats heeft)
            $vrStmt = $pdo->prepare("
                SELECT MAX(h.ronde) FROM heats h
                JOIN heat_entries he ON he.heat_id = h.id
                WHERE h.competition_id = ? AND h.distance_combination_id = ? AND h.ronde < ?
            ");
            $vrStmt->execute([$compId, $dcId, $ronde]);
            $vr = $vrStmt->fetchColumn();
            if (!$vr) { $rondeCheck[$ck] = true; return true; } // geen vorige ronde = ok

            $s = $pdo->prepare("
                SELECT COUNT(he.id) AS totaal,
                       SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS klaar
                FROM heats h JOIN heat_entries he ON he.heat_id = h.id
                LEFT JOIN results res ON res.heat_entry_id = he.id
                WHERE h.competition_id = ? AND h.distance_combination_id = ? AND h.ronde = ?
            ");
            $s->execute([$compId, $dcId, (int)$vr]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $ok = $r && (int)$r['totaal'] > 0 && (int)$r['totaal'] === (int)$r['klaar'];
            $rondeCheck[$ck] = $ok;
            return $ok;
        };

        $ritten = [];
        foreach ($rittenRaw as $r) {
            $ronde = (int)($r['heat_ronde'] ?? 0);
            $dcId  = $r['heat_dc_id'] ?? '';
            $heeftEntries = (int)($r['entries_count'] ?? 0) > 0;

            // Definitief = er zitten rijders in EN (ronde 1 OF vorige ronde compleet)
            $r['definitief'] = $heeftEntries && ($ronde <= 1 || $checkVorigeRonde($dcId, $ronde));

            $ritten[] = $r;
        }

        // Blokken (pauze, inrijden, etc.)
        $blStmt = $pdo->prepare("
            SELECT volgorde, blok_type, duur, tijdstip, opmerking
            FROM tijdschema_blokken
            WHERE tijdschema_id = ? AND blok_type != 'ronde'
            ORDER BY volgorde
        ");
        $blStmt->execute([$tsId]);
        $blokken = $blStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ritten' => $ritten, 'blokken' => $blokken], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: rit detail (heat-card voor één rit) ─────────────────────────────────
if ($action === 'rit_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $ritNaam = trim($_GET['rit_naam'] ?? '');
    $dcNaam = trim($_GET['dc_naam'] ?? '');
    if (!$compId || !$ritNaam) { echo json_encode(['error' => 'Verplichte velden ontbreken']); exit; }

    try {
        // Zoek de heat via rit_naam koppeling
        $stmt = $pdo->prepare("
            SELECT h.id, h.heat_naam, h.ronde,
                   h.distance_combination_id, COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   COALESCE(tsr.ronde_type,
                       CASE WHEN h.heat_naam LIKE '%finale%' OR h.heat_naam LIKE '%ex-aequo%' THEN 'finale_a'
                            ELSE 'heats' END
                   ) AS ronde_type,
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

        // Heats pas tonen als vorige ronde compleet is
        if ((int)$heat['ronde'] > 1) {
            $dcStmt = $pdo->prepare("SELECT distance_combination_id FROM heats WHERE id = ?");
            $dcStmt->execute([$heat['id']]);
            $dcId = $dcStmt->fetchColumn();
            if ($dcId) {
                // Zoek werkelijk vorige ronde
                $vrStmt = $pdo->prepare("
                    SELECT MAX(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ? AND h.ronde < ?
                ");
                $vrStmt->execute([$compId, $dcId, (int)$heat['ronde']]);
                $vr = $vrStmt->fetchColumn();
                if ($vr) {
                    $cStmt = $pdo->prepare("
                        SELECT COUNT(he.id) AS totaal,
                               SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS met_resultaat
                        FROM heats h JOIN heat_entries he ON he.heat_id = h.id
                        LEFT JOIN results res ON res.heat_entry_id = he.id
                        WHERE h.competition_id = ? AND h.distance_combination_id = ? AND h.ronde = ?
                    ");
                    $cStmt->execute([$compId, $dcId, (int)$vr]);
                    $r = $cStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$r || (int)$r['totaal'] === 0 || (int)$r['totaal'] !== (int)$r['met_resultaat']) {
                        echo json_encode(['heat' => null, 'reden' => 'Vorige ronde nog niet compleet']);
                        exit;
                    }
                }
            }
        }

        // Rijders ophalen (incl. vastgelegde rang uit uitslag_afstand als die bestaat)
        $dcId = $heat['distance_combination_id'] ?? null;
        $distId = $heat['distance_id'] ?? null;
        $rStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.full_name, p.category,
                   res.finishpositie, res.tijd_ms, res.sanctie,
                   res.rondes, res.punten AS pk_punten,
                   ua.rang AS uitslag_rang
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs ON cs.person_license = he.person_license AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            LEFT JOIN uitslag_afstand ua ON ua.person_license = he.person_license
                AND ua.competition_id = ? AND ua.distance_combination_id = ? AND ua.distance_id = ?
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");
        $rStmt->execute([$compId, $compId, $dcId, $distId, $heat['id']]);
        $heat['rijders'] = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['heat' => $heat], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: zoek personen op (deel van de) naam ─────────────────────────────────
// Retourneert een lichtgewicht lijst (snr, naam, categorie, club_short,
// license_key) — bedoeld voor een multi-pick chooser. Zwaar detail (heats)
// wordt pas per geselecteerde persoon opgehaald via `lookup`.
if ($action === 'search_person') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $term   = trim($_GET['q'] ?? '');
    if (!$compId || mb_strlen($term) < 2) { echo json_encode([]); exit; }
    try {
        // Zoek uitsluitend op short_name (= achternaam). Niet op full_name,
        // om te voorkomen dat bv. "Jorn" matcht in voornamen van andere
        // rijders. Zoek in de hele persons-tabel (niet beperkt tot deelnemers
        // van deze wedstrijd) — `in_wedstrijd`-vlag laat de UI zien of ze
        // deze keer meedoen.
        $stmt = $pdo->prepare("
            SELECT p.license_key, p.full_name, p.short_name,
                   p.category, p.club_short,
                   COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr,
                   EXISTS (
                       SELECT 1 FROM entries e
                       JOIN distance_combinations dc
                         ON dc.id = e.distance_combination_id
                       WHERE e.person_license = p.license_key
                         AND dc.competition_id = ?
                   ) AS in_wedstrijd
            FROM persons p
            LEFT JOIN competition_startnummers cs
                   ON cs.person_license = p.license_key AND cs.competition_id = ?
            WHERE p.short_name LIKE ?
            ORDER BY p.short_name, p.full_name
            LIMIT 30
        ");
        $stmt->execute([$compId, $compId, '%' . $term . '%']);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: lookup rijder ───────────────────────────────────────────────────────
if ($action === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $compId  = trim($_GET['competition_id'] ?? '');
    $snr     = trim($_GET['startnummer'] ?? '');
    // Optioneel: lookup direct op license_key (stabiel over wedstrijden heen).
    // Gebruikt door multi-rijder (public-view onthoudt kinderen via license_key
    // zodat ze ook in een volgende wedstrijd automatisch verschijnen, ongeacht
    // of ze een ander startnummer hebben).
    $license = trim($_GET['license_key'] ?? '');

    if (!$compId || (!$snr && !$license)) {
        echo json_encode(['error' => 'competition_id en startnummer of license_key zijn verplicht']);
        exit;
    }

    try {
        if ($license) {
            // License-zoek is niet gebonden aan deelname in deze wedstrijd —
            // zo kunnen ouders kinderen alvast toevoegen die nog niet
            // ingeschreven zijn (of deze wedstrijd overslaan). entry_status
            // wordt NULL als er geen inschrijving is voor deze comp; de
            // frontend toont dan een "niet ingeschreven"-placeholder.
            $persStmt = $pdo->prepare("
                SELECT p.license_key, p.full_name, p.category, p.start_number,
                       p.club_short,
                       COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr,
                       (SELECT MAX(e.status)
                          FROM entries e
                          JOIN distance_combinations dc
                            ON dc.id = e.distance_combination_id
                         WHERE e.person_license = p.license_key
                           AND dc.competition_id = ?) AS entry_status
                FROM persons p
                LEFT JOIN competition_startnummers cs
                       ON cs.person_license = p.license_key AND cs.competition_id = ?
                WHERE p.license_key = ?
            ");
            $persStmt->execute([$compId, $compId, $license]);
        } else {
            $persStmt = $pdo->prepare("
                SELECT p.license_key, p.full_name, p.category, p.start_number,
                       p.club_short,
                       COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr,
                       e.status AS entry_status
                FROM persons p
                LEFT JOIN competition_startnummers cs ON cs.person_license = p.license_key AND cs.competition_id = ?
                JOIN entries e ON e.person_license = p.license_key
                JOIN distance_combinations dc ON dc.id = e.distance_combination_id AND dc.competition_id = ?
                WHERE (p.start_number = ? OR cs.startnummer = ?)
                GROUP BY p.license_key
            ");
            $persStmt->execute([$compId, $compId, $snr, $snr]);
        }
        $personen = $persStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$personen) {
            $omschr = $license ? 'deze rijder' : "startnummer $snr";
            echo json_encode(['error' => "Geen rijder gevonden voor $omschr in deze wedstrijd"]);
            exit;
        }

        // Heats + alle rijders per heat
        $heatStmt = $pdo->prepare("
            SELECT DISTINCT h.id AS heat_id, h.heat_naam, h.ronde,
                   h.distance_combination_id, COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   he.startpositie,
                   COALESCE(tsr.ronde_type,
                       CASE WHEN h.heat_naam LIKE '%finale%' OR h.heat_naam LIKE '%ex-aequo%' THEN 'finale_a'
                            ELSE 'heats' END
                   ) AS ronde_type,
                   tsr.rit_naam,
                   res.finishpositie, res.tijd_ms, res.sanctie,
                   res.rondes, res.punten AS pk_punten,
                   tsr.volgorde AS rit_volgorde
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.person_license = ? AND h.competition_id = ?
            ORDER BY COALESCE(tsr.volgorde, h.ronde * 100 + h.heat_nr)
        ");

        // Belangrijk: uitslag_afstand kan meerdere rijen per rijder bevatten
        // (elke "Uitslag bevestigen" voegt een nieuwe rij toe). We joinen
        // daarom via een sub-select die alleen de laatste rij (MAX(id)) per
        // rijder meeneemt — anders vermenigvuldigt het JOIN elke rijder met
        // het aantal bevestigings-runs.
        $rijdersStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.full_name, p.category,
                   res.finishpositie, res.tijd_ms, res.sanctie,
                   res.rondes, res.punten AS pk_punten,
                   ua.rang AS uitslag_rang
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs ON cs.person_license = he.person_license AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            LEFT JOIN (
                SELECT ua1.person_license, ua1.rang
                FROM uitslag_afstand ua1
                INNER JOIN (
                    SELECT MAX(id) AS max_id
                    FROM uitslag_afstand
                    WHERE competition_id = ?
                      AND distance_combination_id = ?
                      AND distance_id = ?
                    GROUP BY person_license
                ) latest ON latest.max_id = ua1.id
            ) ua ON ua.person_license = he.person_license
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");

        $uitslagStmt = $pdo->prepare("
            SELECT t.distance_naam, t.rang, t.punten, t.sanctie, t.finale_naam
            FROM uitslag_afstand t
            INNER JOIN (
                SELECT distance_id, MAX(id) AS max_id
                FROM uitslag_afstand
                WHERE person_license = ? AND competition_id = ?
                GROUP BY distance_id
            ) latest ON latest.max_id = t.id
            ORDER BY t.distance_naam
        ");
        $klasStmt = $pdo->prepare("
            SELECT t.rang, t.punten_totaal, t.dc_naam, t.punten_detail
            FROM uitslag_klassement t
            INNER JOIN (
                SELECT distance_combination_id, MAX(id) AS max_id
                FROM uitslag_klassement
                WHERE person_license = ? AND competition_id = ?
                GROUP BY distance_combination_id
            ) latest ON latest.max_id = t.id
            ORDER BY t.rang
        ");

        $resultaten = [];
        foreach ($personen as $p) {
            $lic = $p['license_key'];
            $heatStmt->execute([$lic, $compId]);
            $heatsRaw = $heatStmt->fetchAll(PDO::FETCH_ASSOC);

            // Check per ronde of de vorige ronde compleet is
            // Finale-heats (ronde > 1) alleen tonen als alle heats van de vorige ronde resultaten hebben
            $rondeCompleetCache = []; // ronde_nr => bool
            $checkCompleet = function($ronde, $dcId, $distId) use ($pdo, $compId, &$rondeCompleetCache) {
                if ($ronde <= 1) return true;
                $ck = "{$ronde}_{$dcId}_{$distId}";
                if (isset($rondeCompleetCache[$ck])) return $rondeCompleetCache[$ck];

                // Zoek werkelijk vorige ronde (hoogste ronde < huidige)
                $vrStmt = $pdo->prepare("
                    SELECT MAX(h.ronde) FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ? AND h.ronde < ?
                ");
                $vrStmt->execute([$compId, $dcId, $ronde]);
                $vorigeRonde = $vrStmt->fetchColumn();
                if (!$vorigeRonde) { $rondeCompleetCache[$ck] = true; return true; }

                $stmt = $pdo->prepare("
                    SELECT COUNT(he.id) AS totaal,
                           SUM(CASE WHEN res.id IS NOT NULL THEN 1 ELSE 0 END) AS met_resultaat
                    FROM heats h
                    JOIN heat_entries he ON he.heat_id = h.id
                    LEFT JOIN results res ON res.heat_entry_id = he.id
                    WHERE h.competition_id = ?
                      AND h.distance_combination_id = ?
                      AND h.ronde = ?
                ");
                $stmt->execute([$compId, $dcId, (int)$vorigeRonde]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                $compleet = $r && (int)$r['totaal'] > 0 && (int)$r['totaal'] === (int)$r['met_resultaat'];
                $rondeCompleetCache[$ck] = $compleet;
                return $compleet;
            };

            $heats = [];
            foreach ($heatsRaw as $h) {
                // Finale-heats pas tonen als vorige ronde compleet is
                if ((int)$h['ronde'] > 1) {
                    // Zoek dc_id van deze heat
                    $dcStmt = $pdo->prepare("SELECT distance_combination_id, distance_id FROM heats WHERE id = ?");
                    $dcStmt->execute([$h['heat_id']]);
                    $heatInfo = $dcStmt->fetch(PDO::FETCH_ASSOC);
                    if ($heatInfo && !$checkCompleet((int)$h['ronde'], $heatInfo['distance_combination_id'], $heatInfo['distance_id'])) {
                        continue; // Vorige ronde niet compleet → verberg deze heat
                    }
                }

                $rijdersStmt->execute([$compId, $compId, $h['distance_combination_id'] ?? '', $h['distance_id'] ?? '', $h['heat_id']]);
                $h['rijders'] = $rijdersStmt->fetchAll(PDO::FETCH_ASSOC);
                $heats[] = $h;
            }

            $uitslagStmt->execute([$lic, $compId]);
            $uitslagen = $uitslagStmt->fetchAll(PDO::FETCH_ASSOC);
            $klasStmt->execute([$lic, $compId]);
            $klassementen = $klasStmt->fetchAll(PDO::FETCH_ASSOC);

            $resultaten[] = [
                'persoon'      => $p,
                'heats'        => $heats,
                'uitslagen'    => $uitslagen,
                'klassementen' => $klassementen,
            ];
        }
        echo json_encode($resultaten, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: categorieën met uitslagen ──────────────────────────────────────────
if ($action === 'categorieen') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        // DC's die uitslagen hebben
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

        // Klassement-check per DC
        $klasStmt = $pdo->prepare("
            SELECT DISTINCT distance_combination_id FROM uitslag_klassement WHERE competition_id = ?
        ");
        $klasStmt->execute([$compId]);
        $klasDcIds = $klasStmt->fetchAll(PDO::FETCH_COLUMN);

        $result = [];
        foreach ($rows as $r) {
            $dcId = $r['dc_id'];
            if (!isset($result[$dcId])) {
                $result[$dcId] = [
                    'dc_id' => $dcId,
                    'dc_naam' => $r['dc_naam'],
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

// ── API: volledige uitslag per afstand of klassement ────────────────────────
if ($action === 'uitslagen') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $dcId   = trim($_GET['dc_id'] ?? '');
    $type   = trim($_GET['type'] ?? 'afstand');
    $distId = trim($_GET['distance_id'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }

    try {
        if ($type === 'klassement') {
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
                ORDER BY t.rang, t.punten_totaal
            ");
            $stmt->execute([$compId, $dcId, $compId]);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Afstandnamen uit punten_detail
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
            if (!$distId) { echo json_encode(['error' => 'distance_id verplicht voor type=afstand']); exit; }
            $stmt = $pdo->prepare("
                SELECT t.rang, t.finale_naam, t.tijd_ms, t.sanctie,
                       t.distance_naam,
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

            // Dedup (res_agg kan meerdere rijen geven)
            $seen = [];
            $unique = [];
            foreach ($rijders as $r) {
                $lic = $r['full_name'] . $r['snr'];
                if (isset($seen[$lic])) continue;
                $seen[$lic] = true;
                $unique[] = $r;
            }

            $heeftRnd = !empty(array_filter($unique, fn($r) => $r['rondes'] !== null));
            $heeftPK  = !empty(array_filter($unique, fn($r) => $r['pk_punten'] !== null));

            echo json_encode([
                'rijders' => $unique,
                'heeft_rondes' => $heeftRnd,
                'heeft_pk_punten' => $heeftPK,
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── API: serie-klassementen waar deze wedstrijd aan meedoet ─────────────────
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
        $stmt = $pdo->prepare("
            SELECT s.id AS serie_id, s.naam, s.seizoen, s.klassement_id,
                   s.herberekend_op,
                   k.totaal_rijders
            FROM klassement_series s
            JOIN klassement_serie_wedstrijden w ON w.serie_id = s.id
            JOIN klassementen k ON k.id = s.klassement_id
            WHERE w.competition_id = ? AND w.telt_mee = 1 AND k.totaal_rijders > 0
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

// ── API: volledig serie-klassement (zonder auth) ─────────────────────────────
if ($action === 'serie_klassement') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    $klId = trim($_GET['klassement_id'] ?? '');
    if (!$klId) { echo json_encode(['error' => 'klassement_id verplicht']); exit; }
    try {
        $kl = $pdo->prepare("
            SELECT id, naam, seizoen, bron_bestand, totaal_rijders,
                   categorieen, wedstrijden_meta, aangemaakt_op
            FROM klassementen
            WHERE id = ? AND bron_bestand = '(serie-berekening)'
        ");
        $kl->execute([$klId]);
        $k = $kl->fetch(PDO::FETCH_ASSOC);
        if (!$k) { http_response_code(404); echo json_encode(['error' => 'Niet gevonden']); exit; }
        $k['categorieen']      = json_decode($k['categorieen']      ?? '[]', true);
        $k['wedstrijden_meta'] = json_decode($k['wedstrijden_meta'] ?? 'null', true);

        $pos = $pdo->prepare("
            SELECT positie, start_number, license_key, naam, categorie,
                   punten_detail, punten_totaal
            FROM klassement_posities
            WHERE klassement_id = ?
            ORDER BY positie ASC
        ");
        $pos->execute([$klId]);
        $rows = $pos->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['punten_detail'] = $r['punten_detail'] !== null
                ? json_decode($r['punten_detail'], true) : null;
            $r['punten_totaal'] = $r['punten_totaal'] !== null
                ? (float)$r['punten_totaal'] : null;
        }
        unset($r);
        $k['posities'] = $rows;
        echo json_encode($k, JSON_UNESCAPED_UNICODE);
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
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>InlineComp – Mijn wedstrijd</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icon-192.svg">
<style>
:root {
    --blauw: #1F4E79;
    --middenblauw: #2E75B6;
    --lichtblauw: #D6E4F0;
    --oranje: #E8630A;
    --wit: #fff;
    --tekst: #1a1a1a;
    --grijs: #f4f6f8;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 20px; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 1rem;
    color: var(--tekst);
    background: var(--grijs);
    min-height: 100vh;
}
header {
    background: var(--blauw);
    color: var(--wit);
    padding: 14px 16px;
    display: flex; align-items: center; justify-content: center; position: relative;
}
header .hdr-center { text-align: center; }
header h1 { font-size: 1.5rem; font-weight: 700; }
header .sub { font-size: .95rem; opacity: .8; margin-top: 2px; }
.hdr-btns {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    display: flex; gap: 6px;
}

/* ── Org footer ── */
.org-footer {
    display: none; /* verborgen tot wedstrijd geselecteerd */
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
.org-footer-sponsors {
    flex: 1; overflow: hidden; display: flex; align-items: center; justify-content: flex-end;
}
.sponsor-marquee {
    display: flex; overflow: hidden; height: 50px; align-items: center;
}
.sponsor-marquee-inner {
    display: flex; align-items: center; gap: 40px; flex-shrink: 0;
    animation: marquee linear infinite;
}
.sponsor-marquee-inner img {
    height: 40px; width: auto; object-fit: contain; flex-shrink: 0;
}
@keyframes marquee {
    0%   { transform: translateX(0); }
    100% { transform: translateX(calc(-50% - 20px)); }
}
.btn-help {
    background: rgba(255,255,255,.2); border: 2px solid rgba(255,255,255,.5);
    color: #fff; width: 36px; height: 36px; border-radius: 50%;
    font-size: 1.2rem; font-weight: 700; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    font-style: italic;
}
.btn-help:active { background: rgba(255,255,255,.35); }

/* ── Help overlay ── */
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
.help-sluit { background: none; border: none; color: rgba(255,255,255,.7); font-size: 1.5rem; cursor: pointer; line-height: 1; }

/* ── Disclaimer-popup bij eerste bezoek ── */
.disc-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.55);
    z-index: 3000; display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.disc-box {
    background: var(--wit); border-radius: 14px; width: 100%; max-width: 460px;
    box-shadow: 0 12px 40px rgba(0,0,0,.35); overflow: hidden;
    animation: disc-in .2s ease-out;
}
@keyframes disc-in { from { transform: translateY(10px); opacity: 0; } to { transform: none; opacity: 1; } }
.disc-header {
    background: var(--blauw); color: var(--wit); padding: 14px 16px;
    font-size: 1.05rem; font-weight: 700;
}
.disc-body {
    padding: 18px 18px 10px; font-size: .92rem; line-height: 1.55; color: var(--tekst);
}
.disc-body p { margin: 0 0 12px; }
.disc-body p:last-child { margin-bottom: 0; font-style: italic; color: #666; font-size: .85rem; }
.disc-footer { padding: 10px 14px 14px; text-align: right; }

.disc-btn {
    background: var(--blauw); color: var(--wit); border: none; border-radius: 6px;
    padding: 9px 22px; font-size: .92rem; font-weight: 600; cursor: pointer;
    transition: background .15s;
}
.disc-btn:hover { background: #153658; }
.help-body { padding: 16px; font-size: .9rem; line-height: 1.5; color: var(--tekst); }
.help-body h3 { font-size: .95rem; color: var(--blauw); margin: 16px 0 6px; }
.help-body h3:first-child { margin-top: 0; }
.help-body p { margin: 4px 0 8px; }
.help-body .help-stap { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; }
.help-body .help-stap-nr {
    background: var(--oranje); color: #fff; min-width: 24px; height: 24px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; flex-shrink: 0;
}
/* ── Mockups ── */
.mock {
    border: 2px solid #dde3ea; border-radius: 10px; overflow: hidden;
    margin: 10px 0 14px; font-size: .78rem;
}
.mock-hdr {
    background: var(--blauw); color: #fff; padding: 6px 10px;
    font-weight: 700; font-size: .75rem; text-align: center;
}
.mock-body { padding: 8px 10px; background: #fafbfc; }
.mock-select {
    background: #fff; border: 1.5px solid #cdd8e3; border-radius: 6px;
    padding: 6px 8px; width: 100%; font-size: .75rem; color: #555; margin-bottom: 6px;
}
.mock-tabs {
    display: flex; border-bottom: 2px solid #dde3ea; margin-bottom: 6px;
}
.mock-tab {
    flex: 1; text-align: center; padding: 5px 4px; font-size: .65rem; font-weight: 600;
    color: #aaa; border-bottom: 2px solid transparent; margin-bottom: -2px;
}
.mock-tab.active { color: var(--blauw); border-bottom-color: var(--oranje); }
.mock-row {
    display: flex; gap: 6px; padding: 3px 0; border-bottom: 1px solid #f0f2f5;
    font-size: .7rem; align-items: center;
}
.mock-row:last-child { border-bottom: none; }
.mock-hl { background: #fffbe6; font-weight: 600; margin: 0 -10px; padding: 3px 10px; border-radius: 3px; }
.mock-rang { width: 18px; text-align: center; font-weight: 700; color: var(--blauw); }
.mock-snr { width: 24px; font-weight: 600; color: var(--blauw); }
.mock-naam { flex: 1; }
.mock-tijd { font-family: monospace; color: #666; font-size: .65rem; }

.container { max-width: 720px; margin: 0 auto; padding: 6px; padding-bottom: 80px; }

/* ── Stappen ── */
.stap { margin-bottom: 16px; }
.stap-label {
    font-size: 1.05rem; font-weight: 700; color: var(--blauw);
    margin-bottom: 6px; display: flex; align-items: center; gap: 6px;
}
.stap-nr {
    background: var(--blauw); color: var(--wit);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem; font-weight: 700; flex-shrink: 0;
}
select, input[type=number], input[type=text], input[type=search] {
    width: 100%; padding: 14px 14px; font-size: 1rem;
    border: 2px solid #cdd8e3; border-radius: 8px;
    background: var(--wit); appearance: none; -webkit-appearance: none;
}
select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%23666'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px;
}
select:focus, input:focus { border-color: var(--middenblauw); outline: none; }
.filter-rij {
    display: flex; gap: 8px; margin-top: 8px;
}
.filter-rij input[type=checkbox] { display: none; }
.filter-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 9px 16px; border-radius: 20px; font-size: .95rem; font-weight: 600;
    border: 2px solid #cdd8e3; background: var(--wit); color: #888;
    cursor: pointer; user-select: none; transition: all .15s;
}
.filter-chip:active { transform: scale(.96); }
.filter-rij input:checked + .filter-chip {
    background: var(--lichtblauw); border-color: var(--middenblauw); color: var(--blauw);
}

.btn-zoek {
    width: 100%; padding: 16px; font-size: 1.15rem; font-weight: 700;
    color: var(--wit); background: var(--oranje);
    border: none; border-radius: 8px; cursor: pointer; margin-top: 10px;
}
.btn-zoek:disabled { opacity: .4; cursor: not-allowed; }
.btn-zoek:active { transform: scale(.98); }

/* ── Comp info ── */
.comp-info {
    background: var(--lichtblauw); border-radius: 8px;
    padding: 12px 14px; margin-bottom: 16px;
    font-size: 1rem; color: var(--blauw);
}
.comp-info strong { font-size: 1.1rem; }

/* ── Persoon header ── */
.persoon-header {
    background: var(--blauw); color: var(--wit);
    padding: 14px 16px; border-radius: 10px 10px 0 0;
    display: flex; justify-content: space-between; align-items: center;
}
.persoon-naam { font-size: 1.35rem; font-weight: 700; }
.persoon-snr { font-size: .9rem; opacity: .8; }
.persoon-cat { font-size: .85rem; background: rgba(255,255,255,.2); border-radius: 10px; padding: 2px 10px; }
.btn-refresh {
    background: none; border: none; color: rgba(255,255,255,.7);
    font-size: 3rem; cursor: pointer; padding: 0; line-height: 1;
    transition: transform .3s, color .2s;
}
.btn-refresh:hover { color: #fff; }
.btn-refresh:active { transform: rotate(360deg); }

/* ── Tabs ── */
.tabs {
    display: flex; background: var(--wit);
    border-bottom: 2px solid #dde3ea;
}

/* Naamzoek-chooser (multi-select modal) */
.naamzoek-modal {
    position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2500;
    display:flex; align-items:center; justify-content:center; padding:16px;
}
.naamzoek-box {
    background:var(--wit); border-radius:12px; max-width:480px; width:100%;
    max-height:80vh; display:flex; flex-direction:column;
    box-shadow:0 12px 40px rgba(0,0,0,.3); overflow:hidden;
}
.naamzoek-hdr {
    background:var(--blauw); color:var(--wit); padding:12px 16px;
    font-weight:700; display:flex; justify-content:space-between; align-items:center;
}
.naamzoek-body { overflow-y:auto; padding:8px 0; }
.naamzoek-rij {
    display:flex; align-items:center; gap:10px; padding:10px 14px;
    border-bottom:1px solid #eee; cursor:pointer; user-select:none;
}
.naamzoek-rij:hover { background:#f0f5fa; }
.naamzoek-rij input[type=checkbox] { width:18px; height:18px; flex-shrink:0; }
.naamzoek-rij-snr { font-weight:700; color:var(--blauw); min-width:34px; text-align:right; }
.naamzoek-rij-naam { flex:1; }
.naamzoek-rij-meta { font-size:.75rem; color:#888; }
.naamzoek-voet {
    border-top:1px solid #eee; padding:12px 14px;
    display:flex; gap:10px; justify-content:space-between; align-items:center;
}
.naamzoek-voet .aantal { font-size:.85rem; color:#666; }
.naamzoek-leeg { padding:30px 16px; text-align:center; color:#888; font-style:italic; }
.naamzoek-sluit { background:none; border:none; color:rgba(255,255,255,.85);
                  font-size:1.4rem; cursor:pointer; line-height:1; }

/* Kind-tabs (meerdere rijders in één weergave, bv. gezinnen met broertjes/zusjes).
   Laat deze bovenop de persoon-header staan — klik op een tab toont de
   bijbehorende rijder met zijn eigen sub-tabs (programma/heats/…). */
.kind-tabs {
    display:flex; align-items:stretch; gap:0;
    margin-top:16px; border-bottom:2px solid #dde3ea;
    overflow:hidden;                 /* nooit scrollen, gewoon afkappen */
    white-space:nowrap;
}
.kind-tab {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 14px; font-size:1.35rem; font-weight:600;
    color:#666; background:var(--wit); border:none; cursor:pointer;
    border-bottom:12px solid transparent; margin-bottom:-2px;
    min-width:0; flex:1 1 auto;      /* laat tabs krimpen bij weinig ruimte */
    overflow:hidden;
}
/* De naam in de kind-tab mag afbreken met … als hij te lang is; de snr-badge
   en × worden nooit afgebroken. */
.kind-tab > span:nth-child(2) {
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    min-width:0; max-width:100%;
}
.kind-tab.active {
    color:var(--blauw); border-bottom-color:var(--oranje);
}
.kind-tab .kind-tab-snr {
    background:var(--lichtblauw); color:var(--blauw);
    border-radius:8px; padding:1px 8px; font-weight:700;
    font-size:1rem; flex-shrink:0;
}
.kind-tab .kind-tab-close {
    color:#999; font-size:1.3rem; margin-left:2px; cursor:pointer;
    flex-shrink:0;
}
.kind-tab .kind-tab-close:hover { color:#b71c1c; }
.kind-tab-plus {
    display:inline-flex; align-items:center; justify-content:center;
    padding:10px 14px; font-size:1.35rem; font-weight:700;
    color:var(--oranje); background:var(--wit); border:none; cursor:pointer;
    border-bottom:12px solid transparent; margin-bottom:-2px;
    flex-shrink:0;                   /* + knop blijft altijd volledig zichtbaar */
}
.kind-tab-plus:disabled { color:#bbb; cursor:not-allowed; }
.tab-btn {
    flex: 1; padding: 12px 4px; font-size: .85rem; font-weight: 600;
    text-align: center; border: none; background: none; cursor: pointer;
    color: #888; border-bottom: 3px solid transparent; margin-bottom: -2px;
}
.tab-btn.active { color: var(--blauw); border-bottom-color: var(--oranje); }
.tab-content { display: none; }
.tab-content.active { display: block; }

/* ── Kaart ── */
.kaart {
    background: var(--wit); border: 1px solid #dde3ea;
    border-radius: 0 0 10px 10px; overflow: hidden; margin-bottom: 16px;
}
.kaart-sectie { padding: 12px 16px; border-bottom: 1px solid #eef2f6; }
.kaart-sectie:last-child { border-bottom: none; }
.kaart-sectie-titel {
    font-size: .8rem; font-weight: 700; color: var(--middenblauw);
    text-transform: uppercase; letter-spacing: .5px; margin-bottom: 8px;
}

/* ── Heat-card (zoals print) ── */
.heat-card {
    border: 1px solid #ccc; border-radius: 8px; overflow: hidden;
    margin-bottom: 12px;
}
.heat-card-titel {
    background: var(--blauw); color: var(--wit);
    padding: 8px 12px; font-weight: 700; font-size: .95rem;
    display: flex; align-items: center; gap: 8px;
}
.heat-card-badge {
    font-size: .75rem; border-radius: 4px; padding: 1px 6px;
    font-weight: 700; flex-shrink: 0;
}
.badge-serie { background: #0d6efd; color: #fff; }
.badge-kf { background: #6610f2; color: #fff; }
.badge-hf { background: #fd7e14; color: #fff; }
.badge-finale { background: #198754; color: #fff; }
.badge-ru { background: #6c757d; color: #fff; }

.heat-card-tabel {
    width: 100%; border-collapse: collapse; font-size: .95rem;
}
.heat-card-tabel th {
    background: #eef2f8; color: var(--blauw); padding: 4px 8px;
    font-size: .8rem; font-weight: 600; text-align: left;
}
.heat-card-tabel td { padding: 6px 8px; border-bottom: 1px solid #f0f2f5; }
.heat-card-tabel tr:last-child td { border-bottom: none; }
.heat-card-tabel .rij-ik { background: #fffbe6; font-weight: 700; }
.heat-card-tabel .col-pos { width: 28px; text-align: center; color: #aaa; }
.heat-card-tabel .col-snr { width: 40px; font-weight: 600; color: var(--blauw); }
.heat-card-tabel .col-naam { }
.heat-card-tabel .col-tijd { font-family: monospace; color: #555; text-align: right; }
.heat-card-tabel .col-fin { font-weight: 600; color: var(--blauw); text-align: center; width: 32px; }
.heat-card-tabel .col-rnd { text-align: center; width: 32px; color: #666; }
.heat-card-tabel .col-pk { text-align: center; width: 32px; font-weight: 600; color: var(--oranje); }

/* ── PWA install banner ── */
.pwa-banner {
    background: linear-gradient(135deg, var(--blauw), var(--middenblauw));
    color: var(--wit); padding: 10px 16px; display: flex; align-items: center;
    gap: 10px; font-size: .85rem; border-radius: 10px; margin-bottom: 12px;
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

/* ── Uitslagen tab ── */
.uitsl-selects { display: flex; gap: 8px; margin-bottom: 12px; }
.uitsl-selects select { flex: 1; padding: 10px 12px; font-size: .95rem; border: 2px solid #cdd8e3; border-radius: 8px; background: var(--wit); }
.uitsl-tabel-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.uitsl-tabel {
    width: 100%; border-collapse: collapse; font-size: .85rem; white-space: nowrap;
}
.uitsl-tabel th {
    background: var(--blauw); color: var(--wit); padding: 6px 8px;
    font-size: .75rem; font-weight: 600; text-align: left; position: sticky; top: 0;
}
.uitsl-tabel td { padding: 5px 8px; border-bottom: 1px solid #f0f2f5; }
.uitsl-tabel tr:nth-child(even) { background: #fafbfc; }
.uitsl-tabel .col-rang { width: 28px; text-align: center; font-weight: 700; color: var(--blauw); }
.uitsl-tabel .col-snr { width: 36px; font-weight: 600; color: var(--blauw); }
.uitsl-tabel .col-naam { white-space: normal; word-break: break-word; min-width: 100px; }
.uitsl-tabel .col-cat { font-size: .75rem; color: #888; }
.uitsl-tabel .col-tijd { font-family: monospace; text-align: right; }
.uitsl-tabel .col-rnd, .uitsl-tabel .col-pk { text-align: center; }
.uitsl-tabel .col-pk { font-weight: 600; color: var(--oranje); }
.uitsl-tabel .col-sanctie { color: #c00; font-weight: 600; font-size: .8rem; }
.uitsl-tabel .col-punten { text-align: center; font-weight: 600; }
.uitsl-tabel .col-totaal { text-align: center; font-weight: 700; color: var(--oranje); }
/* Serie-klassement (nieuw) */
.serie-klas-tabel-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.serie-klas-tabel .col-w {
    width: 36px; text-align: center; font-size: .85rem;
}
.serie-klas-tabel .col-tot {
    width: 50px; text-align: right; font-weight: 700;
    color: var(--blauw); background: #eef5fb;
}
.serie-klas-tabel .col-nng { color: #bbb; }
.serie-klas-tabel tr.rij-ik td {
    background: #fff3e0 !important; font-weight: 700;
}
@media (max-width: 480px) {
    .uitsl-selects { flex-direction: column; }
    .uitsl-tabel { font-size: .78rem; }
    .uitsl-tabel th, .uitsl-tabel td { padding: 4px 5px; }
}
.heat-card-tabel .col-sanctie { color: #c00; font-weight: 600; font-size: .85rem; }
.heat-card-mijn-result {
    background: var(--lichtblauw); padding: 6px 12px; font-size: .9rem;
    display: flex; justify-content: space-between; align-items: center;
}

/* ── Uitslag rij ── */
.uitslag-rij {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0; border-bottom: 1px solid #f5f7fa; font-size: 1rem;
}
.uitslag-rij:last-child { border-bottom: none; }
.uitslag-rang { font-size: 1.2rem; font-weight: 700; color: var(--blauw); min-width: 32px; }
.uitslag-afstand { flex: 1; }
.uitslag-punten { font-weight: 600; color: #555; }
.heat-sanctie { color: #c00; font-weight: 600; font-size: .85rem; }

/* ── Klassement ── */
.klas-rang { font-size: 1.5rem; font-weight: 700; color: var(--oranje); }
.klas-totaal { font-size: 1rem; color: #666; }

/* ── Programma ── */
.prog-rij {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 0; border-bottom: 1px solid #f0f2f5; font-size: .9rem;
}
.prog-rij:last-child { border-bottom: none; }
.prog-nr { color: #aaa; font-size: .8rem; min-width: 24px; text-align: center; }
.prog-naam { flex: 1; }
.prog-type { font-size: .75rem; }
.prog-blok {
    padding: 6px 0; font-size: .85rem; color: #888;
    border-bottom: 1px solid #f0f2f5; font-style: italic;
}
/* ── Gecombineerde ritten: kader rondom de groep met uitleg-kopje ── */
.prog-combi-box {
    border: 2px solid #2E75B6;
    border-radius: 6px;
    background: #eef4fb;
    margin: 8px -4px;
    overflow: hidden;
}
.prog-combi-kop {
    background: #2E75B6;
    color: #fff;
    font-size: .78rem;
    font-weight: 600;
    padding: 4px 10px;
    letter-spacing: .02em;
}
.prog-combi-leden {
    padding: 0 10px;
}
.prog-combi-leden .prog-rij {
    border-bottom: 1px dashed rgba(46,117,182,.25);
}
.prog-combi-leden .prog-rij:last-child {
    border-bottom: none;
}

/* ── Overlay ── */
.overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5);
    display: flex; align-items: center; justify-content: center;
    z-index: 1000; padding: 16px;
}
.overlay-box {
    background: var(--wit); border-radius: 12px; width: 100%; max-width: 500px;
    max-height: 90vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,.25);
}
.overlay-sluit {
    float: right; border: none; background: none; font-size: 1.4rem;
    cursor: pointer; color: #fff; padding: 4px 8px; line-height: 1;
}

.melding { text-align: center; padding: 24px; color: #888; font-size: .95rem; }
.melding-fout { color: #c00; }
.spinner {
    display: inline-block; width: 20px; height: 20px;
    border: 2px solid #ddd; border-top-color: var(--oranje);
    border-radius: 50%; animation: spin .6s linear infinite;
    vertical-align: middle; margin-right: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<header>
    <div class="hdr-center">
        <h1>InlineComp</h1>
        <div class="sub">Zoek je heats, starttijden en resultaten</div>
    </div>
    <div class="hdr-btns">
        <button class="btn-help" onclick="toonInfo()" title="Over InlineComp">i</button>
        <button class="btn-help" onclick="toonHelp()" title="Hoe werkt het?">?</button>
    </div>
</header>

<div id="org-footer" class="org-footer">
    <div class="org-footer-inner">
        <span id="footer-org-logo"></span>
        <span id="footer-org-naam" class="org-footer-naam"></span>
        <div id="footer-sponsors" class="org-footer-sponsors"></div>
    </div>
</div>

<div class="container">
    <div id="pwa-banner" class="pwa-banner" style="display:none">
        <div class="pwa-banner-tekst">
            <b>Installeer InlineComp</b>
            Voeg toe aan je startscherm voor snelle toegang
        </div>
        <button class="btn-install" id="pwa-install">Installeer</button>
        <button class="btn-sluit" id="pwa-sluit" title="Sluiten">&times;</button>
    </div>
    <div class="stap">
        <div class="stap-label"><span class="stap-nr">1</span> Kies je wedstrijd</div>
        <select id="sel-comp"><option value="">Laden…</option></select>
        <div class="filter-rij">
            <input type="checkbox" id="chk-oud"><label for="chk-oud" class="filter-chip">Oude wedstrijden</label>
            <input type="checkbox" id="chk-toekomst"><label for="chk-toekomst" class="filter-chip">Toekomstige</label>
        </div>
    </div>
    <div id="comp-info" class="comp-info" style="display:none"></div>
    <div class="stap">
        <div class="stap-label"><span class="stap-nr">2</span> Startnummer, licentie of achternaam</div>
        <input type="text" id="inp-snr" placeholder="Startnummer, licentienr of achternaam…" autocomplete="off" inputmode="search">
    </div>
    <button class="btn-zoek" id="btn-zoek" disabled>Zoeken</button>
    <div id="resultaat"></div>
</div>

<script>
const selComp = document.getElementById('sel-comp');
const inpSnr  = document.getElementById('inp-snr');
const btnZoek = document.getElementById('btn-zoek');
const divResult = document.getElementById('resultaat');
const divInfo   = document.getElementById('comp-info');
const chkOud     = document.getElementById('chk-oud');
const chkToekomst = document.getElementById('chk-toekomst');
let alleComps = [];

const STATUS_LABEL = ['Niet bevestigd','Bevestigd','Afgemeld','Afgem. bij org.','Niet getekend','Bev. bij org.'];
const STATUS_KLEUR = ['#e65100','#2e7d32','#b71c1c','#6a1b9a','#283593','#006064'];
const STATUS_BG    = ['#fff3e0','#e8f5e9','#fce4e4','#f3e5f5','#e8eaf6','#e0f7fa'];
const BADGE = { heats:'badge-serie', kwartfinale:'badge-kf', halve_finale:'badge-hf',
                finale_a:'badge-finale', finale_b:'badge-finale', runner_up:'badge-ru' };
const RLABEL = { heats:'Serie', kwartfinale:'KF', halve_finale:'HF',
                 finale_a:'Finale', finale_b:'B-Finale', runner_up:'Runner-up' };

function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
// Safari kan geen "2026-04-19 10:00:00" parsen, wel "2026-04-19T10:00:00"
function safeDatum(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }

// Fetch met automatische retry bij 429 (rate limit)
async function safeFetch(url, maxRetries = 3) {
    for (let i = 0; i < maxRetries; i++) {
        const res = await fetch(url);
        if (res.status !== 429) return res;
        await new Promise(r => setTimeout(r, 3000 * (i + 1))); // 3s, 6s, 9s
    }
    return fetch(url); // laatste poging
}
// Detecteer extra kolommen voor heat-rijders
function heatExtraKolommen(rijders, rondeType) {
    const heeftRnd = rijders.some(r => r.rondes != null);
    const heeftPK  = rijders.some(r => r.pk_punten != null);
    return { heeftRnd, heeftPK, rondeType: rondeType ?? null };
}
function heatTabelHeader(extra) {
    return `<tr><th class="col-pos">#</th><th class="col-snr">Snr</th><th class="col-naam">Naam</th>`
        + (extra.heeftRnd ? '<th class="col-rnd">Rnd</th>' : '')
        + (extra.heeftPK  ? '<th class="col-pk">Pnt</th>' : '')
        + '<th class="col-tijd">Tijd</th><th class="col-fin">Fin</th></tr>';
}
function heatTabelRij(r, isIk, extra) {
    const rTijd = r.tijd_ms != null ? msTijd(r.tijd_ms) : '';
    // uitslag_rang alleen tonen bij finales, bij series de ruwe finishpositie
    const isFinale = extra.rondeType && extra.rondeType !== 'heats';
    const rFin = (isFinale && r.uitslag_rang != null) ? r.uitslag_rang : (r.finishpositie != null ? r.finishpositie : '');
    const rSanctie = sl(r.sanctie);
    return `<tr class="${isIk ? 'rij-ik' : ''}">
        <td class="col-pos">${r.startpositie}</td>
        <td class="col-snr">${esc(r.snr)}</td>
        <td class="col-naam">${esc(r.full_name)}${rSanctie ? ` <span class="col-sanctie">${esc(rSanctie)}</span>` : ''}</td>`
        + (extra.heeftRnd ? `<td class="col-rnd">${r.rondes ?? ''}</td>` : '')
        + (extra.heeftPK  ? `<td class="col-pk">${r.pk_punten != null ? parseFloat(r.pk_punten) : ''}</td>` : '')
        + `<td class="col-tijd">${esc(rTijd)}</td>
        <td class="col-fin">${esc(rFin)}</td>
    </tr>`;
}
function msTijd(ms) {
    // Inline-skeeleren: reglementair duizendsten op alle afstanden.
    if (ms==null) return '';
    const d=ms%1000, s=Math.floor(ms/1000)%60, m=Math.floor(ms/60000);
    return m>0?`${m}:${String(s).padStart(2,'0')}.${String(d).padStart(3,'0')}`:`${s}.${String(d).padStart(3,'0')}`;
}
function sl(s) { return s ?? ''; }

// ── Rit-detail overlay ────────────────────────────────────────────────────────
async function toonRitDetail(el) {
    const ritNaam = el.dataset.ritNaam;
    const dcNaam = el.dataset.dcNaam;
    const compId = selComp.value;
    const snr = inpSnr.value.trim();
    if (!ritNaam || !compId) return;

    // Overlay aanmaken
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = '<div class="overlay-box"><div style="padding:24px;text-align:center"><span class="spinner"></span> Laden…</div></div>';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);

    try {
        const res = await safeFetch(`?action=rit_detail&competition_id=${encodeURIComponent(compId)}&rit_naam=${encodeURIComponent(ritNaam)}&dc_naam=${encodeURIComponent(dcNaam)}`);
        const data = await res.json();

        if (!data.heat || !data.heat.rijders?.length) {
            overlay.querySelector('.overlay-box').innerHTML = `
                <div class="heat-card-titel"><button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>${esc(ritNaam)}</div>
                <div style="padding:20px;text-align:center;color:#888">Geen startlijst beschikbaar voor deze rit.</div>`;
            return;
        }

        const h = data.heat;
        const rt = h.ronde_type ?? 'heats';
        const extra = heatExtraKolommen(h.rijders ?? [], rt);
        let rows = '';
        for (const r of h.rijders) {
            rows += heatTabelRij(r, String(r.snr) === snr, extra);
        }

        overlay.querySelector('.overlay-box').innerHTML = `
            <div class="heat-card" style="border:none;border-radius:12px">
                <div class="heat-card-titel" style="border-radius:12px 12px 0 0">
                    <button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>
                    <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(RLABEL[rt]??rt)}</span>
                    ${esc(h.rit_naam ?? h.heat_naam)}
                </div>
                <table class="heat-card-tabel">
                <thead>${heatTabelHeader(extra)}</thead>
                <tbody>${rows}</tbody>
                </table>
            </div>`;
    } catch (e) {
        overlay.querySelector('.overlay-box').innerHTML = `<div style="padding:20px;color:#c00">Fout: ${esc(e.message)}</div>`;
    }
}

// Wedstrijden laden + filteren
function filterComps() {
    const nu = new Date();
    const gisteren = new Date(nu); gisteren.setDate(gisteren.getDate() - 1); gisteren.setHours(0,0,0,0);
    const morgen   = new Date(nu); morgen.setDate(morgen.getDate() + 1);   morgen.setHours(23,59,59,999);

    const toonOud     = chkOud.checked;
    const toonToekomst = chkToekomst.checked;
    const vorigeWaarde = selComp.value;

    selComp.innerHTML = '<option value="">— Kies een wedstrijd —</option>';
    for (const c of alleComps) {
        const startDag = safeDatum(c.starts);
        const eindDag  = safeDatum(c.ends) ?? startDag;

        // Standaard: alleen "actieve" wedstrijden (overlap met gisteren-morgen)
        const isActief = startDag && startDag <= morgen && eindDag >= gisteren;
        const isOud    = eindDag && eindDag < gisteren;
        const isToekomst = startDag && startDag > morgen;

        if (!isActief && !toonOud && isOud) continue;
        if (!isActief && !toonToekomst && isToekomst) continue;

        const d = startDag ? startDag.toLocaleDateString('nl-NL',{day:'numeric',month:'long',year:'numeric'}) : '';
        const o = document.createElement('option');
        o.value = c.id; o.textContent = `${c.name} — ${d}`;
        o.dataset.datum = d; o.dataset.naam = c.name;
        o.dataset.orgLogo = c.org_logo ?? '';
        o.dataset.orgNaam = c.org_naam ?? '';
        o.dataset.sponsors = JSON.stringify(c.sponsors ?? []);
        selComp.appendChild(o);
    }

    // Herstel selectie als die nog in de lijst zit
    if (vorigeWaarde && selComp.querySelector(`option[value="${vorigeWaarde}"]`)) {
        selComp.value = vorigeWaarde;
    } else {
        // Auto-selecteer als er maar 1 wedstrijd is
        const opties = selComp.querySelectorAll('option[value]:not([value=""])');
        if (opties.length === 1) { selComp.value = opties[0].value; selComp.dispatchEvent(new Event('change')); }
    }
}

chkOud.addEventListener('change', filterComps);
chkToekomst.addEventListener('change', filterComps);

safeFetch('?action=competitions').then(r=>r.json()).then(comps => {
    alleComps = comps;

    // Directe-link-support: ?comp=<uuid> in de URL selecteert direct die
    // wedstrijd. Gebruikt door de QR-code op de promotie-poster per wedstrijd.
    // Als de wedstrijd buiten het "actieve" venster valt (oud of toekomstig)
    // vinken we automatisch het juiste filter aan zodat de optie zichtbaar is.
    const urlParams = new URLSearchParams(window.location.search);
    const wantedComp = urlParams.get('comp');
    if (wantedComp) {
        const comp = alleComps.find(c => c.id === wantedComp);
        if (comp) {
            const nu = new Date();
            const startDag = comp.starts ? new Date(comp.starts) : null;
            const eindDag  = comp.ends   ? new Date(comp.ends)   : startDag;
            if (eindDag && eindDag < nu)       chkOud.checked = true;
            if (startDag && startDag > nu)     chkToekomst.checked = true;
        }
    }

    filterComps();

    // Na filterComps: selecteer 'm als de optie nu beschikbaar is
    if (wantedComp && selComp.querySelector(`option[value="${wantedComp}"]`)) {
        selComp.value = wantedComp;
        selComp.dispatchEvent(new Event('change'));
    }
}).catch(() => { selComp.innerHTML = '<option value="">Fout bij laden</option>'; });

// ── Disclaimer bij eerste bezoek (zelfde tekst als op de poster) ─────────
function toonDisclaimerEenmalig() {
    try {
        if (localStorage.getItem('ic_disclaimer_seen') === '1') return;
    } catch { /* storage geblokkeerd: toon toch een keer per sessie */ }

    const overlay = document.createElement('div');
    overlay.className = 'disc-overlay';
    overlay.innerHTML = `
        <div class="disc-box">
            <div class="disc-header">Welkom bij InlineComp!</div>
            <div class="disc-body">
                <p>We testen InlineComp voor het eerst tijdens deze wedstrijd — feedback is welkom!</p>
                <p>De officiële startlijsten, uitslagen, klassementen en mededelingen vind je
                   zoals altijd op <strong>Sportity</strong> (kanaal: <em>ISKREGIO</em>).</p>
                <p>Aan de informatie in InlineComp kunnen geen rechten worden ontleend.</p>
            </div>
            <div class="disc-footer">
                <button class="disc-btn" id="disc-ok">OK, begrepen</button>
            </div>
        </div>`;
    overlay.addEventListener('click', e => {
        if (e.target === overlay) sluit();  // klik buiten box → sluiten
    });
    const sluit = () => {
        try { localStorage.setItem('ic_disclaimer_seen', '1'); }
        catch { /* negeer */ }
        overlay.remove();
    };
    document.body.appendChild(overlay);
    document.getElementById('disc-ok').addEventListener('click', sluit);
    // ESC sluit ook
    const esc = ev => {
        if (ev.key === 'Escape') { sluit(); document.removeEventListener('keydown', esc); }
    };
    document.addEventListener('keydown', esc);
}
toonDisclaimerEenmalig();

selComp.addEventListener('change', async () => {
    const o = selComp.selectedOptions[0];
    if (o?.value) { divInfo.innerHTML = `<strong>${esc(o.dataset.naam)}</strong><div style="color:#555;margin-top:2px">${esc(o.dataset.datum)}</div>`; divInfo.style.display=''; }
    else divInfo.style.display='none';
    btnZoek.disabled = !(selComp.value && inpSnr.value.trim());
    divResult.innerHTML = '';
    updateHeaderLogos(o);

    // Multi-rijder-state resetten en vorige kinderen herladen uit globale
    // store (op license_key). Kinderen die niet in deze wedstrijd meedoen
    // worden stil overgeslagen — geen foutmelding.
    _kinderen = [];
    _activeKindIdx = 0;
    if (!selComp.value) return;
    const opgeslagen = _loadKidsUitStorage();
    if (!opgeslagen.length) return;
    divResult.innerHTML = '<div class="melding"><span class="spinner"></span> Je rijders ophalen…</div>';
    let gedeeldeProg = null;
    try {
        const pr = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(selComp.value)}`);
        gedeeldeProg = await pr.json();
    } catch {}
    for (const item of opgeslagen) {
        const k = await _fetchKind({ license_key: item.license_key }, selComp.value, gedeeldeProg);
        if (k) _kinderen.push(k);
        // k == null → kind doet niet mee aan deze wedstrijd, we slaan 'm stil over.
    }
    if (_kinderen.length) {
        _activeKindIdx = 0;
        renderKinderen();
        divResult.scrollIntoView({ behavior:'smooth', block:'start' });
    } else {
        divResult.innerHTML = '';
    }
});
inpSnr.addEventListener('input', () => { btnZoek.disabled = !(selComp.value && inpSnr.value.trim()); });
inpSnr.addEventListener('keydown', e => { if (e.key==='Enter' && !btnZoek.disabled) btnZoek.click(); });

// ── Zoek-input: detecteer of de user een startnummer, licentienummer of
//    een achternaam typt. Regel:
//      - alleen cijfers, 1-4 tekens → startnummer
//      - alleen cijfers, 5+ tekens  → licentienummer (KNSB relatienr)
//      - bevat letters              → achternaam-zoek
function _zoekModus(tekst) {
    const t = tekst.trim();
    if (/^\d+$/.test(t)) return t.length <= 4 ? 'snr' : 'license';
    return 'naam';
}

btnZoek.addEventListener('click', async () => {
    const compId = selComp.value, tekst = inpSnr.value.trim();
    if (!compId || !tekst) return;
    const modus = _zoekModus(tekst);

    if (modus === 'naam') {
        await zoekOpNaam(compId, tekst);
        return;
    }

    divResult.innerHTML = '<div class="melding"><span class="spinner"></span> Zoeken…</div>';
    btnZoek.disabled = true;
    try {
        const param = modus === 'license'
            ? `license_key=${encodeURIComponent(tekst)}`
            : `startnummer=${encodeURIComponent(tekst)}`;
        const [lookupRes, progRes] = await Promise.all([
            safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}`),
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`)
        ]);
        const data = await lookupRes.json();
        const prog = await progRes.json();

        if (data.error) { divResult.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }
        if (!data.length) { divResult.innerHTML = '<div class="melding">Geen resultaten gevonden.</div>'; return; }

        // Meerdere personen met zelfde startnummer (of license) → chooser-modal
        // met checkboxes zodat de user er meerdere tegelijk kan toevoegen.
        if (data.length > 1) {
            const rijen = data.map(d => ({
                license_key:  d.persoon.license_key,
                full_name:    d.persoon.full_name,
                wedstrijd_snr: d.persoon.wedstrijd_snr ?? d.persoon.start_number,
                category:     d.persoon.category,
                club_short:   d.persoon.club_short ?? '',
            }));
            toonChooserModal(rijen, tekst, compId);
            return;
        }

        // Voor license/snr gebruiken we het startnr uit de response (kan per
        // wedstrijd verschillen); toonRijderData deduped op license_key.
        const huidigSnr = data[0].persoon.wedstrijd_snr ?? data[0].persoon.start_number ?? tekst;
        toonRijderData(data, 0, huidigSnr, prog);
        inpSnr.value = '';
        btnZoek.disabled = true;
    } catch (e) {
        divResult.innerHTML = `<div class="melding melding-fout">Fout: ${esc(e.message)}</div>`;
    } finally { btnZoek.disabled = false; }
});

// ── Herbruikbare multi-select chooser-modal ─────────────────────────────────
// Gebruikt voor zowel naam-zoek als startnummer-match met meerdere hits.
// `rijen` moet items hebben met: {license_key, full_name, wedstrijd_snr,
// category, club_short}. Na "Toevoegen" wordt per gekozen license_key een
// volledige lookup gedaan en aan _kinderen toegevoegd.
function toonChooserModal(rijen, term, compId) {
    // Reeds in de lijst → uitschakelen
    const al = new Set(_kinderen.map(k => k.data[k.kozen_idx ?? 0]?.persoon?.license_key).filter(Boolean));
    const plaatsVrij = MAX_KINDEREN - _kinderen.length;

    const modal = document.createElement('div');
    modal.className = 'naamzoek-modal';
    modal.innerHTML = `
        <div class="naamzoek-box">
            <div class="naamzoek-hdr">
                <span>Zoekresultaten voor "${esc(term)}"</span>
                <button class="naamzoek-sluit" title="Sluiten">&times;</button>
            </div>
            <div class="naamzoek-body">
                ${rijen.length === 0
                    ? '<div class="naamzoek-leeg">Geen rijders gevonden.</div>'
                    : rijen.map(r => {
                        const uit = al.has(r.license_key);
                        // search_person geeft `in_wedstrijd` (1/0); snr-pad niet,
                        // dan behandelen we als altijd-wel (undefined === wel).
                        const doetMee = r.in_wedstrijd === undefined ? true : !!parseInt(r.in_wedstrijd);
                        const meta = [
                            r.category || '',
                            r.club_short ? esc(r.club_short) : '',
                            uit ? '<span style="color:#999">al in lijst</span>' : '',
                            !doetMee ? '<span style="color:#b71c1c">doet niet mee in deze wedstrijd</span>' : '',
                        ].filter(Boolean).join(' · ');
                        return `<label class="naamzoek-rij" style="${uit ? 'opacity:.55' : ''}">
                            <input type="checkbox" data-lic="${esc(r.license_key)}" ${uit ? 'checked disabled' : ''}>
                            <span class="naamzoek-rij-snr">${esc(r.wedstrijd_snr ?? '—')}</span>
                            <div class="naamzoek-rij-naam">
                                ${esc(r.full_name)}
                                <div class="naamzoek-rij-meta">${meta}</div>
                            </div>
                        </label>`;
                    }).join('')}
            </div>
            <div class="naamzoek-voet">
                <span class="aantal">Max ${MAX_KINDEREN} rijders · ${plaatsVrij} plek(ken) vrij</span>
                <div>
                    <button class="btn-zoek" style="padding:8px 18px;margin:0" id="naamzoek-ok">Toevoegen</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(modal);
    divResult.innerHTML = '';

    const sluit = () => modal.remove();
    modal.querySelector('.naamzoek-sluit').addEventListener('click', sluit);
    modal.addEventListener('click', e => { if (e.target === modal) sluit(); });

    modal.querySelector('#naamzoek-ok').addEventListener('click', async () => {
        const vinkjes = [...modal.querySelectorAll('input[type=checkbox]:checked:not(:disabled)')];
        if (!vinkjes.length) { sluit(); return; }
        if (vinkjes.length > plaatsVrij) {
            alert(`Maximum ${MAX_KINDEREN} — er is nog plek voor ${plaatsVrij}. Je hebt er ${vinkjes.length} aangevinkt.`);
            return;
        }
        sluit();
        divResult.innerHTML = '<div class="melding"><span class="spinner"></span> Rijders ophalen…</div>';
        let prog = null;
        try {
            const pr = await safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`);
            prog = await pr.json();
        } catch {}
        for (const cb of vinkjes) {
            const lic = cb.dataset.lic;
            if (!lic) continue;
            try {
                const r = await safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&license_key=${encodeURIComponent(lic)}`);
                const d = await r.json();
                if (d && !d.error && d.length) {
                    const huidigSnr = d[0].persoon.wedstrijd_snr ?? d[0].persoon.start_number ?? '';
                    toonRijderData(d, 0, huidigSnr, prog);
                }
            } catch {}
        }
        inpSnr.value = '';
        btnZoek.disabled = true;
    });
}

// ── Naam-zoek: zoek via backend, toon chooser ────────────────────────────────
async function zoekOpNaam(compId, term) {
    divResult.innerHTML = '<div class="melding"><span class="spinner"></span> Zoeken op "' + esc(term) + '"…</div>';
    btnZoek.disabled = true;
    let rijen = [];
    try {
        const res = await safeFetch(`?action=search_person&competition_id=${encodeURIComponent(compId)}&q=${encodeURIComponent(term)}`);
        rijen = await res.json();
        if (!Array.isArray(rijen)) rijen = [];
    } catch (e) {
        divResult.innerHTML = `<div class="melding melding-fout">Fout bij zoeken: ${esc(e.message)}</div>`;
        btnZoek.disabled = false;
        return;
    } finally { btnZoek.disabled = false; }
    toonChooserModal(rijen, term, compId);
}

async function refreshRijder() {
    const compId = selComp.value;
    if (!compId) return;
    // In multi-rijder-modus ververst het ↻-knopje alleen het actieve kind.
    // Het startnummer halen we uit _kinderen, niet uit de input (die kan
    // leeg zijn als de user al wat rijders heeft toegevoegd).
    const k = _kinderen[_activeKindIdx];
    const snr = k?.snr ?? inpSnr.value.trim();
    if (!snr) return;
    const gekozenIdx = k?.kozen_idx ?? 0;
    const actieveTab = document.querySelector('#kind-content .tab-btn.active')?.dataset.tab ?? 'programma';

    // Tijdens verversen: loader in kind-content (niet de hele divResult) om
    // de kind-tabs zichtbaar te houden.
    const target = document.getElementById('kind-content') || divResult;
    target.innerHTML = '<div class="melding"><span class="spinner"></span> Verversen…</div>';

    try {
        const [lookupRes, progRes] = await Promise.all([
            safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&startnummer=${encodeURIComponent(snr)}&_t=${Date.now()}`),
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}&_t=${Date.now()}`)
        ]);
        const data = await lookupRes.json();
        const prog = await progRes.json();
        if (data.error || !data.length) {
            target.innerHTML = `<div class="melding melding-fout">${data.error ?? 'Geen data'}</div>`;
            return;
        }

        window._lookupData = data;
        window._lookupSnr = snr;
        window._lookupProg = prog;

        const idx = Math.min(gekozenIdx, data.length - 1);
        window._gekozenIdx = idx;
        if (k) { k.data = data; k.prog = prog; k.kozen_idx = idx; k.sub_tab = actieveTab; }
        renderKinderen();
    } catch (e) {
        target.innerHTML = `<div class="melding melding-fout">Fout: ${e.message}</div>`;
    }
}

function toonRijder(idx) {
    window._gekozenIdx = idx;
    toonRijderData(window._lookupData, idx, window._lookupSnr, window._lookupProg);
}

// ── Multi-rijder-state (ouders met meerdere kinderen) ────────────────────────
// _kinderen = [{snr, data, prog, sub_tab}] waarbij data = lookup-response
// (array met persoon+heats) en prog = programma-response van de wedstrijd.
// Max 4 kids om de top-tabs leesbaar te houden op een telefoon.
const MAX_KINDEREN = 4;
let _kinderen = [];
let _activeKindIdx = 0;

// ── Persistente kind-lijst (GLOBAAL, niet per wedstrijd) ─────────────────────
// We bewaren `license_key` i.p.v. startnummer, zodat een kind dat in een
// volgende wedstrijd een ander startnummer krijgt toch automatisch wordt
// gevonden. Ook kinderen die in een wedstrijd niet meedoen worden stil
// overgeslagen — zonder dat de ouder ze moet afvinken.
const KIDS_LS_KEY = 'public_kinderen_licenses';
function _saveKids() {
    const items = _kinderen
        .map(k => {
            const p = k.data[k.kozen_idx ?? 0]?.persoon;
            return p?.license_key ? { license_key: p.license_key, naam_hint: p.full_name } : null;
        })
        .filter(Boolean);
    localStorage.setItem(KIDS_LS_KEY, JSON.stringify(items));
}
function _loadKidsUitStorage() {
    try { return JSON.parse(localStorage.getItem(KIDS_LS_KEY) || '[]'); }
    catch { return []; }
}

// Haal lookup op voor een license_key of startnummer. Gebruikt de shared
// programma-respons als die al gefetcht is (scheelt netwerk-calls bij
// meerdere kinderen).
async function _fetchKind({ license_key = null, snr = null }, compId, gedeeldeProg = null) {
    if (!license_key && !snr) return null;
    const param = license_key
        ? `license_key=${encodeURIComponent(license_key)}`
        : `startnummer=${encodeURIComponent(snr)}`;
    const [lookupRes, progRes] = await Promise.all([
        safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}`),
        gedeeldeProg
            ? Promise.resolve({ json: async () => gedeeldeProg })
            : safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`),
    ]);
    const data = await lookupRes.json();
    const prog = await progRes.json();
    if (data.error || !data.length) return null;
    // Pak huidige startnr uit de response (kan in nieuwe wedstrijd anders zijn).
    const p = data[0].persoon;
    const huidigSnr = p.wedstrijd_snr ?? p.start_number ?? snr ?? '';
    return { snr: String(huidigSnr), data, prog, sub_tab: 'programma', kozen_idx: 0 };
}

function toonRijderData(data, startIdx, snr, prog) {
    // Dedupeer op license_key (stabiel over wedstrijden), niet op startnummer.
    const nieuweLic = data[startIdx]?.persoon?.license_key;
    const bestaande = nieuweLic
        ? _kinderen.findIndex(k => k.data[k.kozen_idx ?? 0]?.persoon?.license_key === nieuweLic)
        : -1;
    if (bestaande !== -1) {
        _activeKindIdx = bestaande;
        _kinderen[bestaande].data = data;
        _kinderen[bestaande].prog = prog;
        _kinderen[bestaande].kozen_idx = startIdx;
        _kinderen[bestaande].snr = String(snr);
    } else {
        if (_kinderen.length >= MAX_KINDEREN) {
            alert(`Maximum van ${MAX_KINDEREN} rijders bereikt. Verwijder eerst iemand om een nieuwe toe te voegen.`);
            return;
        }
        _kinderen.push({ snr: String(snr), data, prog, sub_tab: 'programma', kozen_idx: startIdx });
        _activeKindIdx = _kinderen.length - 1;
    }
    _saveKids();
    renderKinderen();
}

// Render de complete multi-rijder-weergave: kind-tabs bovenop, met daaronder
// de persoon-kaart van het actieve kind.
function renderKinderen() {
    if (!_kinderen.length) { divResult.innerHTML = ''; return; }

    // Top-tabs: één knop per kind + "+ voeg toe" rechts
    const meerdereKinderen = _kinderen.length > 1;
    const tabsHtml = _kinderen.map((k, idx) => {
        const p = k.data[k.kozen_idx ?? 0]?.persoon;
        const naam = p?.full_name ? p.full_name.split(' ')[0] : ''; // alleen voornaam in tab — kort
        const actief = idx === _activeKindIdx ? ' active' : '';
        // ×-knop alleen bij 2+ kinderen (voor 1 kind is er geen logica in wegklikken)
        const closeBtn = meerdereKinderen
            ? `<span class="kind-tab-close" data-kind-close="${idx}" title="Verwijder deze rijder">×</span>`
            : '';
        return `<button class="kind-tab${actief}" data-kind-idx="${idx}">
            <span class="kind-tab-snr">${esc(k.snr)}</span>
            <span>${esc(naam || '(rijder)')}</span>
            ${closeBtn}
        </button>`;
    }).join('');
    const plusKnop = _kinderen.length < MAX_KINDEREN
        ? `<button class="kind-tab-plus" id="kind-tab-plus" title="Voeg broertje/zusje toe">+ voeg toe</button>`
        : `<button class="kind-tab-plus" disabled title="Maximum ${MAX_KINDEREN} rijders">+ voeg toe</button>`;

    divResult.innerHTML = `
        <div class="kind-tabs">${tabsHtml}${plusKnop}</div>
        <div id="kind-content"></div>`;

    // Click-handlers op kind-tabs
    divResult.querySelectorAll('.kind-tab').forEach(btn => {
        btn.addEventListener('click', e => {
            if (e.target.classList.contains('kind-tab-close')) {
                const ci = parseInt(e.target.dataset.kindClose);
                verwijderKind(ci);
                e.stopPropagation();
                return;
            }
            const idx = parseInt(btn.dataset.kindIdx);
            wisselKind(idx);
        });
    });
    const plusEl = document.getElementById('kind-tab-plus');
    if (plusEl) plusEl.addEventListener('click', () => {
        // Scroll naar de zoekbalk en focus de startnummer-input
        inpSnr.value = '';
        btnZoek.disabled = true;
        inpSnr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        inpSnr.focus();
    });

    // Content van actieve kind renderen
    const k = _kinderen[_activeKindIdx];
    if (!k) return;
    const subset = [k.data[k.kozen_idx ?? 0]];
    renderResultaat(subset, k.snr, k.prog);

    // Onthouden sub-tab herstellen (als niet 'programma')
    if (k.sub_tab && k.sub_tab !== 'programma') {
        const subBtn = document.querySelector(`#kind-content .tab-btn[data-tab="${k.sub_tab}"]`);
        if (subBtn) subBtn.click();
    }
}

function wisselKind(idx) {
    if (idx < 0 || idx >= _kinderen.length) return;
    // Huidige sub-tab onthouden voordat we wisselen
    const huidigeSub = document.querySelector('#kind-content .tab-btn.active')?.dataset.tab;
    if (huidigeSub && _kinderen[_activeKindIdx]) _kinderen[_activeKindIdx].sub_tab = huidigeSub;
    _activeKindIdx = idx;
    renderKinderen();
}

function verwijderKind(idx) {
    if (idx < 0 || idx >= _kinderen.length) return;
    _kinderen.splice(idx, 1);
    if (_activeKindIdx >= _kinderen.length) _activeKindIdx = Math.max(0, _kinderen.length - 1);
    _saveKids();
    if (_kinderen.length === 0) {
        divResult.innerHTML = '';
    } else {
        renderKinderen();
    }
}

function renderResultaat(data, snr, prog) {
        let html = '';
        for (const r of data) {
            const p = r.persoon;
            // entry_status kan NULL zijn als de rijder wel bestaat maar niet
            // ingeschreven is voor deze wedstrijd (via naam/license toegevoegd).
            const nietIngeschreven = p.entry_status === null || p.entry_status === undefined;
            const st = nietIngeschreven ? -1 : parseInt(p.entry_status);
            const stLabel = nietIngeschreven ? 'Niet ingeschreven' : (STATUS_LABEL[st] ?? '?');
            const stKleur = nietIngeschreven ? '#b71c1c' : (STATUS_KLEUR[st] ?? '#555');
            const stBg    = nietIngeschreven ? '#fce4e4' : (STATUS_BG[st] ?? '#eee');

            html += `
            <div style="margin-top:16px">
                <div class="persoon-header">
                    <div><div class="persoon-naam">${esc(p.full_name)}</div>
                         <span class="persoon-snr">Snr ${esc(p.wedstrijd_snr??p.start_number)}</span>
                         <span style="font-size:.75rem;background:${stBg};color:${stKleur};border-radius:10px;padding:1px 8px;margin-left:6px">${esc(stLabel)}</span></div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="persoon-cat">${esc(p.category)}</span>
                        <button onclick="refreshRijder()" class="btn-refresh" title="Ververs data">↻</button>
                    </div>
                </div>
                <div class="tabs">
                    <button class="tab-btn active" data-tab="programma">📅 Programma</button>
                    <button class="tab-btn" data-tab="heats">🏃 Heats</button>
                    <button class="tab-btn" data-tab="resultaten">🏆 Resultaten</button>
                    <button class="tab-btn" data-tab="uitslagen">📊 Uitslagen</button>
                </div>
                <div class="kaart">`;

            // ── TAB: Programma ────────────────────────────────────────
            html += '<div class="tab-content active" data-tab="programma"><div class="kaart-sectie">';
            html += '<div class="kaart-sectie-titel">Wedstrijdprogramma</div>';
            if (prog.ritten?.length) {
                let nr = 0;
                // Combi-state: ritten met dezelfde combi_group worden samen
                // in één kader getoond. Bij wissel van groep sluiten we de
                // oude box af en openen eventueel een nieuwe.
                let vorigeCombi = null;
                for (const rit of prog.ritten) {
                    nr++;
                    const combi = rit.combi_group ? parseInt(rit.combi_group) : null;
                    if (combi !== vorigeCombi) {
                        if (vorigeCombi !== null) html += `</div></div>`; // sluit vorige combi-box
                        if (combi !== null) {
                            html += `<div class="prog-combi-box">
                                <div class="prog-combi-kop">🔗 Gecombineerde rit — rijden tegelijk</div>
                                <div class="prog-combi-leden">`;
                        }
                    }
                    vorigeCombi = combi;

                    // Highlight als deze rijder in deze rit zit
                    const isInRit = r.heats.some(h => h.rit_naam === rit.rit_naam);
                    const rt = rit.ronde_type ?? 'heats';
                    const statusIcon = rit.resultaten_count > 0  ? '🏁'
                                     : rit.definitief          ? '🚩'
                                     :                           '';
                    html += `<div class="prog-rij${combi !== null ? ' prog-rij-combi' : ''}" style="${isInRit ? 'background:#fffbe6;font-weight:600;margin:0 -16px;padding:6px 16px;border-radius:4px' : ''};cursor:pointer"
                                 data-rit-naam="${esc(rit.rit_naam)}" data-dc-naam="${esc(rit.dc_naam)}" onclick="toonRitDetail(this)">
                        <span class="prog-nr">${statusIcon} ${nr}</span>
                        <span class="prog-naam">${esc(rit.rit_naam)}</span>
                        <span class="prog-type heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(RLABEL[rt]??rt)}</span>
                    </div>`;
                }
                // Sluit eventuele laatste open combi-box
                if (vorigeCombi !== null) html += `</div></div>`;
            } else {
                html += '<div class="melding">Programma niet beschikbaar.</div>';
            }
            html += '</div></div>';

            // ── TAB: Heats (heat-cards) ──────────────────────────────
            html += '<div class="tab-content" data-tab="heats">';
            if (r.heats.length) {
                for (const h of r.heats) {
                    const rt = h.ronde_type ?? 'heats';
                    const naam = h.rit_naam ?? h.heat_naam ?? '';
                    const mijnTijd = h.tijd_ms != null ? msTijd(h.tijd_ms) : '';
                    const mijnPos = h.finishpositie != null ? '#' + h.finishpositie : '';
                    const mijnSanctie = sl(h.sanctie);

                    const extra = heatExtraKolommen(h.rijders ?? [], rt);
                    const rijders = h.rijders ?? [];
                    const heeftResultaten = rijders.some(r => r.finishpositie != null || r.tijd_ms != null);
                    const heeftRijders = rijders.length > 0;
                    const heatIcon = heeftResultaten ? '🏁' : heeftRijders ? '🚩' : '';
                    html += `<div class="heat-card">
                        <div class="heat-card-titel">
                            <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(RLABEL[rt]??rt)}</span>
                            <span style="flex:1">${esc(naam)}</span>
                            ${heatIcon ? `<span style="font-size:1rem">${heatIcon}</span>` : ''}
                        </div>
                        <table class="heat-card-tabel">
                        <thead>${heatTabelHeader(extra)}</thead>
                        <tbody>`;

                    for (const rr of (h.rijders ?? [])) {
                        html += heatTabelRij(rr, String(rr.snr) === snr, extra);
                    }

                    html += '</tbody></table>';
                    if (mijnTijd || mijnPos || mijnSanctie) {
                        html += `<div class="heat-card-mijn-result">
                            <span>Jouw resultaat:</span>
                            <span>${mijnTijd ? esc(mijnTijd) : ''} ${mijnPos ? esc(mijnPos) : ''} ${mijnSanctie ? `<span class="heat-sanctie">${esc(mijnSanctie)}</span>` : ''}</span>
                        </div>`;
                    }
                    html += '</div>';
                }
            } else {
                html += '<div class="kaart-sectie"><div class="melding">Nog geen heats beschikbaar.</div></div>';
            }
            html += '</div>';

            // ── TAB: Resultaten ───────────────────────────────────────
            html += '<div class="tab-content" data-tab="resultaten"><div class="kaart-sectie">';

            if (r.uitslagen.length) {
                html += '<div class="kaart-sectie-titel">Uitslagen per afstand</div>';
                for (const u of r.uitslagen) {
                    const sanctie = sl(u.sanctie);
                    html += `<div class="uitslag-rij">
                        <span class="uitslag-rang">${u.rang ?? '—'}</span>
                        <span class="uitslag-afstand">${esc(u.distance_naam)} ${u.finale_naam ? '('+esc(u.finale_naam)+')' : ''}</span>
                        ${u.punten != null ? `<span class="uitslag-punten">${parseFloat(u.punten)} pt</span>` : ''}
                        ${sanctie ? `<span class="heat-sanctie">${esc(sanctie)}</span>` : ''}
                    </div>`;
                }
            }

            if (r.klassementen.length) {
                for (const k of r.klassementen) {
                    html += `<div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-top:1px solid #eee;margin-top:8px">
                        <div><div class="kaart-sectie-titel" style="margin:0">Klassement ${esc(k.dc_naam)}</div>
                             <span class="klas-rang">#${k.rang}</span></div>
                        <div class="klas-totaal">${parseFloat(k.punten_totaal)} punten</div>
                    </div>`;
                }
            }

            if (!r.uitslagen.length && !r.klassementen.length) {
                html += '<div class="melding">Nog geen resultaten beschikbaar.</div>';
            }

            html += '</div></div>';

            // ── TAB: Uitslagen (volledig overzicht) ──────────────────
            html += `<div class="tab-content" data-tab="uitslagen">
                <div class="kaart-sectie">
                <div class="kaart-sectie-titel">Volledige uitslagen van deze wedstrijd</div>
                <div class="uitsl-selects">
                    <select class="uitsl-cat-sel"><option value="">Laden…</option></select>
                    <select class="uitsl-dist-sel" disabled><option value="">— Kies afstand —</option></select>
                </div>
                <div class="uitsl-tabel-wrap"></div>
            </div>
            <div class="kaart-sectie" data-serie-lijst style="display:none">
                <div class="kaart-sectie-titel">🏆 Serie-klassement</div>
                <div data-serie-selector class="uitsl-selects"></div>
                <div class="serie-klas-tabel-wrap"></div>
            </div></div>`;

            html += '</div></div>'; // kaart + wrapper
        }

        // Schrijf in de kind-content-container (multi-rijder-modus) als die
        // bestaat, anders valt terug op het oude gedrag (divResult direct).
        const target = document.getElementById('kind-content') || divResult;
        target.innerHTML = html;

        // Tab-switching
        target.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const kaart = btn.closest('.tabs').nextElementSibling;
                if (!kaart) return;
                btn.closest('.tabs').querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                kaart.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
                kaart.querySelector(`.tab-content[data-tab="${btn.dataset.tab}"]`)?.classList.add('active');

                // Sub-tab-keuze onthouden per kind (voor multi-rijder-modus)
                if (typeof _kinderen !== 'undefined' && _kinderen[_activeKindIdx]) {
                    _kinderen[_activeKindIdx].sub_tab = btn.dataset.tab;
                }

                // Uitslagen-tab: laad categorieën bij eerste klik
                if (btn.dataset.tab === 'uitslagen') initUitslagenTab(kaart);
            });
        });

}

// ── Uitslagen-tab logica ──────────────────────────────────────────────────
let _catCache = null; // cache categorieën per sessie

async function initUitslagenTab(kaart) {
    const catSel  = kaart.querySelector('.uitsl-cat-sel');
    const distSel = kaart.querySelector('.uitsl-dist-sel');
    const wrap    = kaart.querySelector('.uitsl-tabel-wrap');
    if (!catSel || catSel.dataset.loaded) return;
    catSel.dataset.loaded = '1';

    const compId = selComp.value;
    if (!compId) return;

    // ── Serie-klassementen waar deze wedstrijd aan meedoet ──
    initSerieKlassementen(kaart, compId);

    // Categorieën laden
    try {
        if (!_catCache || _catCache.compId !== compId) {
            const res = await safeFetch(`?action=categorieen&competition_id=${encodeURIComponent(compId)}`);
            _catCache = { compId, data: await res.json() };
        }
        const cats = _catCache.data;
        if (cats.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(cats.error)}</div>`; return; }

        catSel.innerHTML = '<option value="">— Kies categorie —</option>';
        for (const c of cats) {
            const o = document.createElement('option');
            o.value = c.dc_id;
            o.textContent = c.dc_naam;
            o.dataset.json = JSON.stringify(c);
            catSel.appendChild(o);
        }
    } catch (e) {
        wrap.innerHTML = `<div class="melding melding-fout">Fout: ${esc(e.message)}</div>`;
    }

    // Categorie-change → vul afstand-dropdown (bind EERST, dan auto-select)
    catSel.addEventListener('change', () => {
        wrap.innerHTML = '';
        const opt = catSel.selectedOptions[0];
        if (!opt?.value) { distSel.innerHTML = '<option value="">— Kies afstand —</option>'; distSel.disabled = true; return; }
        const cat = JSON.parse(opt.dataset.json);
        distSel.innerHTML = '<option value="">— Kies afstand —</option>';
        for (const a of cat.afstanden) {
            const o = document.createElement('option');
            o.value = a.distance_id; o.textContent = a.distance_naam;
            distSel.appendChild(o);
        }
        if (cat.klassement_beschikbaar) {
            const o = document.createElement('option');
            o.value = '__klassement__'; o.textContent = '🏆 Klassement';
            distSel.appendChild(o);
        }
        distSel.disabled = false;
        // Auto-selecteer als er maar 1 optie is (excl. klassement)
        if (cat.afstanden.length === 1 && !cat.klassement_beschikbaar) {
            distSel.value = cat.afstanden[0].distance_id;
            distSel.dispatchEvent(new Event('change'));
        }
    });

    // Afstand/klassement-change → fetch + render
    distSel.addEventListener('change', async () => {
        const dcId = catSel.value;
        const distVal = distSel.value;
        if (!dcId || !distVal) { wrap.innerHTML = ''; return; }

        wrap.innerHTML = '<div class="melding"><span class="spinner"></span> Laden…</div>';

        try {
            let url;
            if (distVal === '__klassement__') {
                url = `?action=uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&type=klassement`;
            } else {
                url = `?action=uitslagen&competition_id=${encodeURIComponent(compId)}&dc_id=${encodeURIComponent(dcId)}&type=afstand&distance_id=${encodeURIComponent(distVal)}`;
            }
            const res = await safeFetch(url);
            const data = await res.json();
            if (data.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }

            if (distVal === '__klassement__') {
                wrap.innerHTML = renderKlassementTabel(data);
            } else {
                wrap.innerHTML = renderAfstandTabel(data);
            }
        } catch (e) {
            wrap.innerHTML = `<div class="melding melding-fout">Fout: ${esc(e.message)}</div>`;
        }
    });

    // Auto-selecteer als er maar 1 categorie is (ná binden listeners)
    const cats = _catCache?.data;
    if (cats?.length === 1) { catSel.value = cats[0].dc_id; catSel.dispatchEvent(new Event('change')); }
}

// ── Serie-klassementen voor een wedstrijd ──────────────────────────────────
async function initSerieKlassementen(kaart, compId) {
    const box      = kaart.querySelector('[data-serie-lijst]');
    const selector = kaart.querySelector('[data-serie-selector]');
    const wrap     = kaart.querySelector('.serie-klas-tabel-wrap');
    if (!box) return;

    try {
        const res = await safeFetch(`?action=series_voor_comp&competition_id=${encodeURIComponent(compId)}`);
        const series = await res.json();
        if (!Array.isArray(series) || !series.length) { box.style.display = 'none'; return; }

        box.style.display = '';
        // Eén select met alle series + categorieën combineert netjes
        selector.innerHTML = `
            <select class="serie-sel">
                <option value="">— Kies een serie-klassement —</option>
                ${series.map(s => `
                    <option value="${esc(s.klassement_id)}">
                        ${esc(s.naam)}${s.seizoen ? ' — ' + esc(s.seizoen) : ''}
                        (${s.totaal_rijders} rijders)
                    </option>`).join('')}
            </select>
            <select class="serie-cat-sel" disabled><option value="">— Kies categorie —</option></select>`;

        const serieSel = selector.querySelector('.serie-sel');
        const catSel   = selector.querySelector('.serie-cat-sel');
        let huidig = null; // cached klassement-response

        serieSel.addEventListener('change', async () => {
            wrap.innerHTML = '';
            catSel.innerHTML = '<option value="">— Kies categorie —</option>';
            catSel.disabled = true;
            if (!serieSel.value) return;
            wrap.innerHTML = '<div class="melding"><span class="spinner"></span> Laden…</div>';
            try {
                const r = await safeFetch(`?action=serie_klassement&klassement_id=${encodeURIComponent(serieSel.value)}`);
                const data = await r.json();
                if (data.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }
                huidig = data;
                const cats = (data.categorieen ?? []).filter(Boolean);
                if (!cats.length) {
                    wrap.innerHTML = renderSerieKlassementTabel(data, null);
                    return;
                }
                catSel.innerHTML = '<option value="">— Alle categorieën —</option>' +
                    cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
                catSel.disabled = false;
                // Auto-eerste categorie: als er maar 1 is, selecteer die
                if (cats.length === 1) {
                    catSel.value = cats[0];
                    catSel.dispatchEvent(new Event('change'));
                } else {
                    wrap.innerHTML = '<div class="melding">Kies een categorie om het klassement te zien.</div>';
                }
            } catch (e) {
                wrap.innerHTML = `<div class="melding melding-fout">Fout: ${esc(e.message)}</div>`;
            }
        });

        catSel.addEventListener('change', () => {
            if (!huidig) return;
            wrap.innerHTML = renderSerieKlassementTabel(huidig, catSel.value || null);
        });
    } catch (e) {
        box.style.display = 'none';
    }
}

// Render de serie-klassement-tabel (vergelijkbaar met ranking-detail in Beheer,
// maar met highlight voor de eigen rijder uit inpSnr).
function renderSerieKlassementTabel(k, cat) {
    const alle  = k.posities ?? [];
    const rijen = cat ? alle.filter(p => p.categorie === cat) : alle;
    if (!rijen.length) return '<div class="melding">Geen posities in deze categorie.</div>';
    const wMeta = Array.isArray(k.wedstrijden_meta) ? k.wedstrijden_meta : [];
    const toonW = wMeta.length > 0 && rijen.some(p => p.punten_detail && Object.keys(p.punten_detail).length);

    const fmtP = n => {
        if (n == null) return '–';
        const v = +n;
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    };

    // Startnummer van de actief-getoonde rijder. Na btnZoek wordt inpSnr
    // leeggemaakt, dus we lezen uit _kinderen (werkt voor 1 kind én voor de
    // multi-kind-tabs). Fallback op inpSnr voor de eerste render.
    const eigenSnr = String(
        (_kinderen?.[_activeKindIdx]?.snr) ?? inpSnr.value.trim() ?? ''
    ).trim();

    let hdr = '<tr><th class="col-rang">#</th><th class="col-snr">Snr</th><th>Naam</th>';
    if (!cat) hdr += '<th class="col-cat">Cat</th>';
    if (toonW) {
        hdr += wMeta.map((w, i) =>
            `<th class="col-w" title="${esc(w.naam)}${w.datum ? ' · ' + String(w.datum).substring(0,10) : ''}${w.is_finale ? ' · FINALE' : ''}">
                ${w.is_finale ? 'F' : '#' + (i + 1)}
            </th>`).join('');
        hdr += '<th class="col-tot">Tot</th>';
    }
    hdr += '</tr>';

    const rows = rijen.map(p => {
        const isIk = eigenSnr && String(p.start_number) === String(eigenSnr);
        const detail = p.punten_detail ?? {};
        const wedstrijdCellen = toonW
            ? wMeta.map(w => {
                const v = detail[w.comp_id];
                return `<td class="col-w">${v != null ? fmtP(v) : '<span class="col-nng">–</span>'}</td>`;
              }).join('')
            : '';
        const totaalCel = toonW ? `<td class="col-tot">${fmtP(p.punten_totaal)}</td>` : '';
        return `<tr class="${isIk ? 'rij-ik' : ''}">
            <td class="col-rang">${p.positie}</td>
            <td class="col-snr">${esc(p.start_number ?? '–')}</td>
            <td class="col-naam">${esc(p.naam)}</td>
            ${!cat ? `<td class="col-cat">${esc(p.categorie ?? '')}</td>` : ''}
            ${wedstrijdCellen}
            ${totaalCel}
        </tr>`;
    }).join('');

    return `<table class="uitsl-tabel serie-klas-tabel"><thead>${hdr}</thead><tbody>${rows}</tbody></table>`;
}

function renderAfstandTabel(data) {
    if (!data.rijders?.length) return '<div class="melding">Geen uitslagen beschikbaar.</div>';
    const heeftRnd = data.heeft_rondes;
    const heeftPK  = data.heeft_pk_punten;

    let hdr = '<th class="col-rang">#</th><th class="col-snr">Snr</th><th class="col-naam">Naam</th>';
    if (heeftRnd) hdr += '<th class="col-rnd">Rnd</th>';
    if (heeftPK)  hdr += '<th class="col-pk">Pnt</th>';
    hdr += '<th class="col-tijd">Tijd</th>';

    let rows = '';
    for (const r of data.rijders) {
        const sanctie = sl(r.sanctie);
        rows += `<tr>
            <td class="col-rang">${r.rang ?? '—'}</td>
            <td class="col-snr">${esc(r.snr)}</td>
            <td class="col-naam">${esc(r.full_name)}${sanctie ? ` <span class="col-sanctie">${esc(sanctie)}</span>` : ''}</td>`;
        if (heeftRnd) rows += `<td class="col-rnd">${r.rondes ?? ''}</td>`;
        if (heeftPK)  rows += `<td class="col-pk">${r.pk_punten != null ? parseFloat(r.pk_punten) : ''}</td>`;
        rows += `<td class="col-tijd">${r.tijd_ms != null ? msTijd(r.tijd_ms) : ''}</td>`;
        rows += '</tr>';
    }
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderKlassementTabel(data) {
    if (!data.rijders?.length) return '<div class="melding">Geen klassement beschikbaar.</div>';
    const afstanden = data.afstanden ?? [];

    let hdr = '<th class="col-rang">#</th><th class="col-snr">Snr</th><th class="col-naam">Naam</th>';
    for (const a of afstanden) {
        // Afkorten voor mobiel: eerste 3 letters + eventueel getal
        const kort = a.length > 6 ? a.substring(0, 5) + '.' : a;
        hdr += `<th class="col-punten" title="${esc(a)}">${esc(kort)}</th>`;
    }
    hdr += '<th class="col-totaal">Tot</th>';

    let rows = '';
    for (const r of data.rijders) {
        const detail = r.punten_detail ?? {};
        rows += `<tr>
            <td class="col-rang">${r.rang ?? '—'}</td>
            <td class="col-snr">${esc(r.snr)}</td>
            <td class="col-naam">${esc(r.full_name)}</td>`;
        for (const a of afstanden) {
            const p = detail[a];
            rows += `<td class="col-punten">${p != null ? parseFloat(p) : '—'}</td>`;
        }
        rows += `<td class="col-totaal">${r.punten_totaal != null ? parseFloat(r.punten_totaal) : '—'}</td>`;
        rows += '</tr>';
    }
    return `<table class="uitsl-tabel"><thead><tr>${hdr}</tr></thead><tbody>${rows}</tbody></table>`;
}

// ── Help overlay ──────────────────────────────────────────────────────────
// ── Footer: org logo + sponsor-ticker ─────────────────────────────────────
function updateHeaderLogos(opt) {
    const footer   = document.getElementById('org-footer');
    const logoEl   = document.getElementById('footer-org-logo');
    const naamEl   = document.getElementById('footer-org-naam');
    const sponsEl  = document.getElementById('footer-sponsors');

    if (!opt?.value) {
        footer.style.display = 'none';
        return;
    }

    const orgLogo  = opt.dataset.orgLogo;
    const orgNaam  = opt.dataset.orgNaam ?? '';
    const sponsors = JSON.parse(opt.dataset.sponsors || '[]');

    // Niets te tonen? Footer verbergen
    if (!orgLogo && !sponsors.length) {
        footer.style.display = 'none';
        return;
    }

    // Cache-buster zodat een vers geüpload logo niet uit de browser-cache blijft.
    // Gebruikt het huidige uur als bust-waarde: stabiel genoeg voor normale navigatie
    // maar een upload is uiterlijk binnen het uur zichtbaar.
    const cb = `?v=${Math.floor(Date.now() / 3600000)}`;

    // Organisatie-logo + naam
    logoEl.innerHTML = orgLogo ? `<img class="org-footer-logo" src="../${esc(orgLogo)}${cb}" alt="">` : '';
    naamEl.textContent = orgLogo ? '' : orgNaam; // naam alleen als fallback zonder logo

    // Sponsors (lichtkrant-ticker)
    if (sponsors.length) {
        let imgs = '';
        for (const s of sponsors) {
            const img = `<img src="../${esc(s.logo)}${cb}" alt="${esc(s.naam)}" title="${esc(s.naam)}" style="height:50px;width:auto;object-fit:contain">`;
            imgs += s.url ? `<a href="${esc(s.url)}" target="_blank" rel="noopener">${img}</a>` : img;
        }
        if (sponsors.length === 1) {
            sponsEl.innerHTML = `<div style="display:flex;align-items:center;justify-content:flex-end;height:100%">${imgs}</div>`;
        } else {
            const duur = sponsors.length * 3;
            sponsEl.innerHTML = `<div class="sponsor-marquee"><div class="sponsor-marquee-inner" style="animation-duration:${duur}s">${imgs}${imgs}</div></div>`;
        }
    } else {
        sponsEl.innerHTML = '';
    }

    footer.style.display = 'block';
}

function toonInfo() {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
    <div class="help-box">
        <div class="help-header">
            <span>Over InlineComp</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">
            <h3>Wat is InlineComp?</h3>
            <p>InlineComp is een wedstrijdbeheersysteem voor inline skaten, ontwikkeld om wedstrijdorganisaties te ondersteunen bij het beheren van startlijsten, live tijdwaarneming en het publiceren van uitslagen.</p>
            <p>Deze publieke pagina is bedoeld voor <b>rijders en toeschouwers</b>: zoek je startnummer op en bekijk direct je heats, starttijden en resultaten.</p>

            <h3>In ontwikkeling</h3>
            <p>InlineComp wordt actief doorontwikkeld. Functies kunnen veranderen en er kunnen nog fouten in zitten. Feedback is zeer welkom!</p>

            <h3>Contact &amp; feedback</h3>
            <p>Heb je een vraag, suggestie of bug gevonden? Laat het weten:</p>
            <p style="text-align:center;margin:12px 0">
                <a href="mailto:inlinecomp@devriesen.com" style="display:inline-block;background:var(--oranje);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">inlinecomp@devriesen.com</a>
            </p>

            <h3>Anonieme bezoek-statistieken</h3>
            <p style="font-size:.85rem;color:#555">We tellen anoniem aantal bezoekers, actieve sessies en piek gelijktijdig online — puur om te zien hoe veel de app wordt gebruikt en om de hosting stabiel te houden. Er worden <b>geen IP-adressen of persoonsgegevens</b> opgeslagen en er zijn <b>geen derde partijen</b> betrokken.</p>

            <h3>Privacy &amp; persoonsgegevens</h3>
            <p>Deze app toont wedstrijdgegevens die door de KNSB aan ons worden geleverd (o.a. namen, startnummers, vereniging). In de privacyverklaring lees je welke gegevens wij verwerken, op welke grondslag en hoe je een verwijderverzoek kunt indienen.</p>
            <p style="text-align:center;margin:12px 0">
                <a href="../privacyverklaring.php" style="display:inline-block;background:var(--blauw,#1a3a5c);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">📄 Bekijk privacyverklaring</a>
            </p>

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
            <span>Hoe werkt InlineComp?</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">

            <h3>Aan de slag</h3>
            <div class="help-stap">
                <span class="help-stap-nr">1</span>
                <span>Kies je <b>wedstrijd</b> uit de lijst. Standaard zie je alleen wedstrijden van vandaag. Vink <i>Oude wedstrijden</i> of <i>Toekomstige</i> aan om meer te zien.</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">2</span>
                <span>Vul je <b>startnummer</b> in</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">3</span>
                <span>Klik op <b>Zoeken</b> — je persoonlijke overzicht verschijnt</span>
            </div>

            <!-- Mockup: zoekscherm -->
            <div class="mock">
                <div class="mock-hdr">InlineComp</div>
                <div class="mock-body">
                    <div class="mock-select">Voorbeeldwedstrijd — 19 april 2026</div>
                    <div style="display:flex;gap:10px;font-size:.65rem;color:#888;margin:3px 0 6px">
                        <label style="display:flex;align-items:center;gap:3px"><span style="display:inline-block;width:10px;height:10px;border:1.5px solid #aaa;border-radius:2px"></span> Oude wedstrijden</label>
                        <label style="display:flex;align-items:center;gap:3px"><span style="display:inline-block;width:10px;height:10px;border:1.5px solid #aaa;border-radius:2px"></span> Toekomstige</label>
                    </div>
                    <div class="mock-select">Startnummer: 86</div>
                    <div style="background:var(--oranje);color:#fff;text-align:center;padding:6px;border-radius:6px;font-weight:700;font-size:.75rem;margin-top:4px">Zoeken</div>
                </div>
            </div>

            <h3>Tabs</h3>
            <p>Na het zoeken zie je <b>4 tabs</b>:</p>

            <p><b>Programma</b> — alle ritten van de wedstrijd. Jouw ritten zijn gemarkeerd. Tik op een rit om de startlijst te bekijken.</p>

            <!-- Mockup: programma -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab active">Programma</div>
                    <div class="mock-tab">Heats</div>
                    <div class="mock-tab">Resultaten</div>
                    <div class="mock-tab">Uitslagen</div>
                </div>
                <div class="mock-body" style="padding:4px 10px">
                    <div class="mock-row"><span style="color:#aaa">1</span> <span class="mock-naam">500m Serie Heat 1</span> <span style="font-size:.6rem;background:#0d6efd;color:#fff;border-radius:3px;padding:0 4px">Serie</span></div>
                    <div class="mock-row mock-hl"><span style="color:#aaa">2</span> <span class="mock-naam">500m Serie Heat 2</span> <span style="font-size:.6rem;background:#0d6efd;color:#fff;border-radius:3px;padding:0 4px">Serie</span></div>
                    <div class="mock-row"><span style="color:#aaa">3</span> <span class="mock-naam">500m A-Finale</span> <span style="font-size:.6rem;background:#198754;color:#fff;border-radius:3px;padding:0 4px">Finale</span></div>
                </div>
            </div>

            <p><b>Heats</b> — jouw heats met alle rijders. Je eigen rij is gemarkeerd. Na de finish zie je tijden en posities.</p>

            <!-- Mockup: heat -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">Programma</div>
                    <div class="mock-tab active">Heats</div>
                    <div class="mock-tab">Resultaten</div>
                    <div class="mock-tab">Uitslagen</div>
                </div>
                <div style="background:var(--blauw);color:#fff;padding:5px 10px;font-size:.7rem;font-weight:700">
                    <span style="background:#198754;border-radius:3px;padding:0 5px;font-size:.6rem">Finale</span> 500m A-Finale
                </div>
                <div class="mock-body" style="padding:4px 10px">
                    <div class="mock-row" style="font-size:.6rem;color:#888;font-weight:600"><span style="width:18px">#</span><span style="width:24px">Snr</span><span class="mock-naam">Naam</span><span class="mock-tijd">Tijd</span><span style="width:20px;text-align:center">Fin</span></div>
                    <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-tijd">45.30</span><span style="width:20px;text-align:center;font-weight:600">2</span></div>
                    <div class="mock-row mock-hl"><span class="mock-rang">2</span><span class="mock-snr">86</span><span class="mock-naam">Jouw naam</span><span class="mock-tijd">45.12</span><span style="width:20px;text-align:center;font-weight:600;color:var(--blauw)">1</span></div>
                    <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-tijd">46.01</span><span style="width:20px;text-align:center;font-weight:600">3</span></div>
                </div>
            </div>

            <p><b>Resultaten</b> — jouw persoonlijke uitslagen per afstand en je klassement.</p>

            <p><b>Uitslagen</b> — de volledige uitslag van alle rijders. Kies een categorie en afstand, of bekijk het klassement.</p>

            <!-- Mockup: uitslagen -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">Programma</div>
                    <div class="mock-tab">Heats</div>
                    <div class="mock-tab">Resultaten</div>
                    <div class="mock-tab active">Uitslagen</div>
                </div>
                <div class="mock-body" style="padding:6px 10px">
                    <div class="mock-select">DJB/A + HJB/A</div>
                    <div class="mock-select">Klassement</div>
                    <div style="margin-top:6px">
                        <div class="mock-row" style="font-size:.6rem;color:#fff;background:var(--blauw);margin:0 -10px;padding:3px 10px;font-weight:600"><span style="width:18px">#</span><span style="width:24px">Snr</span><span class="mock-naam">Naam</span><span style="width:30px;text-align:center">Spr</span><span style="width:30px;text-align:center">L.A.</span><span style="width:30px;text-align:center;color:var(--oranje)">Tot</span></div>
                        <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">86</span><span class="mock-naam">Jouw naam</span><span style="width:30px;text-align:center">4</span><span style="width:30px;text-align:center">1</span><span style="width:30px;text-align:center;font-weight:700;color:var(--oranje)">8</span></div>
                        <div class="mock-row"><span class="mock-rang">2</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span style="width:30px;text-align:center">5</span><span style="width:30px;text-align:center">3</span><span style="width:30px;text-align:center;font-weight:700;color:var(--oranje)">11</span></div>
                        <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span style="width:30px;text-align:center">5</span><span style="width:30px;text-align:center">6</span><span style="width:30px;text-align:center;font-weight:700;color:var(--oranje)">12</span></div>
                    </div>
                </div>
            </div>

            <h3>Tips</h3>
            <p>&#x21bb; Gebruik de <b>ververs-knop</b> naast je naam om live updates te zien tijdens de wedstrijd.</p>
            <p>Geen resultaten? De uitslag verschijnt zodra de jury de resultaten heeft bevestigd.</p>

        </div>
    </div>`;
    document.body.appendChild(overlay);
}

// ── PWA: service worker + install prompt ─────────────────────────────────
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

let _deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredPrompt = e;
    // Toon banner alleen als gebruiker het niet eerder heeft weggeklikt
    if (!localStorage.getItem('pwa-dismissed')) {
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
    localStorage.setItem('pwa-dismissed', '1');
});

// Verberg banner als app al geinstalleerd is
window.addEventListener('appinstalled', () => {
    document.getElementById('pwa-banner').style.display = 'none';
    _deferredPrompt = null;
});
</script>
</body>
</html>
