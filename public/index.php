<?php
// ============================================================
//  InlineComp – Publieke rijder-lookup
//  Geen login vereist. Drie tabs: Programma / Heats / Resultaten
// ============================================================
header('Content-Type: text/html; charset=utf-8');
// No-cache: zie coach/index.php voor uitleg — telefoon-browsers cachen
// HTML agressief, expliciet uit zodat app-updates direct doorkomen.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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

// ── Wedstrijd-zichtbaarheidsgate ─────────────────────────────────────────────
// /public toont alleen wedstrijden waarvoor public_zichtbaar=1. De
// competitions-list-action filtert zelf al; deze gate beschermt single-
// comp endpoints (programma, lookup, uitslagen, etc.) tegen URL-pluk
// van een wedstrijd in voorbereidingsfase.
function _publicWedstrijdZichtbaar(PDO $pdo, string $compId): bool {
    if (!$compId) return true;
    $s = $pdo->prepare("SELECT public_zichtbaar FROM competitions WHERE id = ? LIMIT 1");
    $s->execute([$compId]);
    return (bool)$s->fetchColumn();
}
$_zichtCompId = trim($_GET['competition_id'] ?? '');
if ($_zichtCompId && !_publicWedstrijdZichtbaar($pdo, $_zichtCompId)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['error' => 'Wedstrijd niet beschikbaar']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Page-render server-cache (15s) ──────────────────────────────────────────
// Alleen voor de pagina-laad zelf (action=''). Cached de hele HTML+JS-bundle
// die voor 200+ gebruikers tijdens een wedstrijd identiek is. Live data komt
// via aparte ?action=programma / ?action=lookup-calls die NIET cached worden.
// Sessie + bezoekstracking is hierboven al gebeurd, dus stats blijven kloppen.
//
// Cache-key: comp-id + taal. Per-comp/per-taal eigen cache zodat NL-FR
// switchers en verschillende wedstrijden niet door elkaar lopen.
//
// Bij wijzigingen (operator publiceert wedstrijd, nieuwe melding-tekst, etc.)
// duurt het max 15s voordat publiek het ziet. Live-data (heats, uitslagen)
// komt via aparte API-calls en wordt ongemoeid gelaten.
$_cacheable = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $action === '';
$_cacheFile = null;
if ($_cacheable) {
    $_compId    = trim($_GET['comp'] ?? '');
    $_lang      = trim($_COOKIE['ICLANG'] ?? 'nl');
    $_cacheFile = sys_get_temp_dir() . '/pub_' . md5($_compId . '|' . $_lang);
    if (is_file($_cacheFile) && (time() - filemtime($_cacheFile)) < 15) {
        $cached = @file_get_contents($_cacheFile);
        if ($cached !== false && $cached !== '') {
            // Override no-cache headers van regel 13
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            header('Cache-Control: public, max-age=15');
            header('Content-Type: text/html; charset=utf-8');
            echo $cached;
            exit;
        }
    }
    ob_start();
    // Browser krijgt zelfde 15s cache zodat herhaal-laden binnen die tijd
    // helemaal geen server-hit doet (304 / from-cache).
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: public, max-age=15');
    register_shutdown_function(function() {
        global $_cacheFile;
        $out = ob_get_contents();
        if ($out !== false && $out !== '' && $_cacheFile) {
            // Atomic rename ipv LOCK_EX — geen wachtende processen bij
            // concurrent writes (zou EP-cascade kunnen veroorzaken).
            $tmp = $_cacheFile . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, $out) !== false) {
                @rename($tmp, $_cacheFile);
            }
        }
        ob_end_flush();
    });
}

// ── Rate limiting: max 10 requests per 5 seconden per IP ────────────────────
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
    // Was 60s cache, maar publiek_zichtbaar kan tussentijds wijzigen
    // (operator publiceert wedstrijd kort voor start). 30s is veilig
    // genoeg en houdt server-belasting laag.
    header('Cache-Control: public, max-age=30');
    try {
        // Baan-velden gebruiken cross-org-fallback: als deze org's baan-rij
        // geen logo of geen vereniging-naam heeft, pakken we die uit een
        // andere org-rij met dezelfde baan-naam (zelfde fysieke locatie).
        // 3-state zichtbaarheid: wedstrijden waar zichtbaar=0 EN
        // aankondigen=0 worden volledig overgeslagen (= "stille
        // voorbereiding" status — operator wil dat publiek niet eens
        // ziet dat InlineComp eraan werkt). Bij zichtbaar=0 +
        // aankondigen=1 verschijnt 'ie wel als disabled "(binnenkort)".
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
            WHERE c.public_zichtbaar = 1 OR c.public_aankondigen = 1
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

        // Baan-sponsors ophalen (per baan-id) — extra sponsors die specifiek
        // bij deze locatie horen (vereniging/baan-niveau). Worden achter de
        // org-sponsors getoond in de footer.
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
                    'naam' => $sp['naam'],
                    'logo' => $sp['logo_path'],
                    'url'  => $sp['url'],
                ];
            }
        }

        // Sponsors toevoegen per wedstrijd: org-sponsors eerst, dan baan-sponsors
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
            SELECT r.volgorde AS rit_volgorde, r.rit_naam, r.ronde_type, r.heat_nr, r.dc_naam,
                   r.combi_group, r.blok_id,
                   r.opmerking AS rit_opmerking,
                   b.volgorde AS blok_volgorde,
                   b.blok_type, b.tijdstip, b.duur, b.heat_duur, b.opmerking,
                   h.id AS heat_id,
                   h.ronde AS heat_ronde,
                   h.distance_combination_id AS heat_dc_id,
                   COALESCE(h.distance_id, r.distance_id) AS heat_distance_id,
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
        // - Runner-up: bron-ronde is de EERSTE deelnemende ronde (heats / KF /
        //   HF), niet de hoogste lagere — runner-up draait parallel uit
        //   eerste-ronde-uitvallers.
        $rondeCheck = []; // "dc_id_dist_id_ronde_type" => bool
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
                // Reguliere vervolgronde: hoogste ronde < huidige
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
            if (!$vr) { $rondeCheck[$ck] = true; return true; } // geen vorige ronde = ok

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

            // Definitief = er zitten rijders in EN (ronde 1 OF vorige ronde compleet)
            $r['definitief'] = $heeftEntries && ($ronde <= 1 || $checkVorigeRonde($dcId, $distId, $ronde, $rondeType));

            $ritten[] = $r;
        }

        // Blokken (pauze, inrijden, etc.). inrijd_cats is JSON-array van
        // dc_id-strings; we resolven die naar leesbare dc-namen zodat de
        // frontend geen extra lookup hoeft te doen.
        // datum meegestuurd voor multi-day NK: wedstrijdstart-blokken hebben
        // een datum per dag, herstart-blokken kunnen ook een eigen datum hebben.
        // Frontend gebruikt 'm voor de "Dag N — Zaterdag 28 mei"-header.
        $blStmt = $pdo->prepare("
            SELECT id, volgorde, blok_type, duur, heat_duur, inrijd_cats,
                   tijdstip, datum, opmerking
            FROM tijdschema_blokken
            WHERE tijdschema_id = ? AND blok_type != 'ronde'
            ORDER BY volgorde
        ");
        $blStmt->execute([$tsId]);
        $blokken = $blStmt->fetchAll(PDO::FETCH_ASSOC);

        // Verzamel alle dc_ids uit inrijd_cats en resolve naar namen in 1 query
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

        // Heats pas tonen als vorige ronde compleet is.
        // Runner-up: bron-ronde is de EERSTE deelnemende ronde (heats / KF /
        // HF), dus MIN() ipv MAX() — andere vervolgrondes (KF/HF/F): hoogste
        // ronde < huidige.
        if ((int)$heat['ronde'] > 1) {
            $dcId = $heat['distance_combination_id'] ?? null;
            $distId = $heat['distance_id'] ?? null;
            if ($dcId) {
                $distCond = ($distId !== '' && $distId !== null)
                    ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
                $vrParams = ($distId !== '' && $distId !== null)
                    ? [$compId, $dcId, $distId, (int)$heat['ronde']]
                    : [$compId, $dcId, (int)$heat['ronde']];
                $rondeType = $heat['ronde_type'] ?? '';
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
        }

        // Rijders ophalen (incl. vastgelegde rang uit uitslag_afstand als die bestaat)
        $dcId = $heat['distance_combination_id'] ?? null;
        $distId = $heat['distance_id'] ?? null;
        $rStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.license_key,
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
    // Lookup-data verandert tijdens de wedstrijd voortdurend (loting,
    // resultaten, klassement-publicatie). Cache uitschakelen zodat
    // browser/proxy nooit een stale snapshot serveert — auto-refresh
    // elke 60 sec is dan altijd vers.
    header('Cache-Control: no-store, must-revalidate');
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
        // bruto_tijd_ms + is_photofinish meesturen zodat "Jouw resultaat" een
        // gemeten/officieel-paar kan tonen wanneer jury de tijd gewijzigd heeft.
        $heatStmt = $pdo->prepare("
            SELECT DISTINCT h.id AS heat_id, h.heat_naam, h.ronde,
                   h.distance_combination_id, COALESCE(h.distance_id, tsr.distance_id) AS distance_id,
                   he.startpositie,
                   COALESCE(tsr.ronde_type,
                       CASE WHEN h.heat_naam LIKE '%finale%' OR h.heat_naam LIKE '%ex-aequo%' THEN 'finale_a'
                            ELSE 'heats' END
                   ) AS ronde_type,
                   tsr.rit_naam,
                   res.finishpositie, res.tijd_ms,
                   res.bruto_tijd_ms, res.is_photofinish, res.sanctie,
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
                   p.license_key,
                   p.full_name, p.category,
                   res.finishpositie, res.tijd_ms,
                   res.bruto_tijd_ms, res.is_photofinish, res.sanctie,
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
        // Filter op gepubliceerde klassementen — admin publiceert
        // expliciet vanuit /Klassement na controle. Niet-gepubliceerde
        // klassementen blijven verborgen voor public.
        $klasStmt = $pdo->prepare("
            SELECT t.rang, t.punten_totaal, t.dc_naam, t.punten_detail
            FROM uitslag_klassement t
            INNER JOIN (
                SELECT distance_combination_id, MAX(id) AS max_id
                FROM uitslag_klassement
                WHERE person_license = ? AND competition_id = ?
                GROUP BY distance_combination_id
            ) latest ON latest.max_id = t.id
            INNER JOIN klassement_config kc
                    ON kc.competition_id = t.competition_id
                   AND kc.dc_id = t.distance_combination_id
                   AND kc.gepubliceerd_at IS NOT NULL
            ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang
        ");

        $resultaten = [];
        foreach ($personen as $p) {
            $lic = $p['license_key'];
            $heatStmt->execute([$lic, $compId]);
            $heatsRaw = $heatStmt->fetchAll(PDO::FETCH_ASSOC);

            // Check per ronde of de vorige ronde compleet is.
            // Voor "gewone" vervolgrondes (KF/HF/Finale): vorige = hoogste
            // ronde < huidige. Voor runner_up: vorige = de EERSTE ronde van
            // die cat (heats / KF / HF, afhankelijk van wat de eerste is) —
            // runner_up draait namelijk parallel uit de eerste-ronde-uitvallers,
            // niet uit een opvolgende ronde. Bij ronde > 1 zonder vorige
            // (= bv. cat zonder series, KF is dan de eerste): true.
            $rondeCompleetCache = []; // cache per ronde+dc+dist+rondeType
            $checkCompleet = function($ronde, $dcId, $distId, $rondeType) use ($pdo, $compId, &$rondeCompleetCache) {
                if ($ronde <= 1) return true;
                $ck = "{$ronde}_{$dcId}_{$distId}_{$rondeType}";
                if (isset($rondeCompleetCache[$ck])) return $rondeCompleetCache[$ck];

                // Filter ook op distance_id — anders kruist de check tussen
                // afstanden binnen dezelfde DC. NULL distance_id matchen we
                // ook (legacy heats voorafgaand aan per-distance-config).
                $distCond = ($distId !== '' && $distId !== null)
                    ? 'AND (h.distance_id = ? OR h.distance_id IS NULL)' : '';
                $vrParams = ($distId !== '' && $distId !== null)
                    ? [$compId, $dcId, $distId, $ronde]
                    : [$compId, $dcId, $ronde];

                if ($rondeType === 'runner_up') {
                    // Runner-up hangt aan de eerste deelnemende ronde
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
                    // Reguliere vervolgronde: hoogste ronde < huidige
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

            $heats = [];
            foreach ($heatsRaw as $h) {
                // Vervolgrondes: in lijst HOUDEN maar markeren met
                // vorige_niet_compleet=true zodat de UI een placeholder kan
                // tonen ("Vorige ronde nog niet compleet") in plaats van de
                // heat helemaal te verbergen. Operator/coach/rijder ziet zo
                // dat de heat bestaat maar nog niet door is.
                $h['vorige_niet_compleet'] = false;
                if ((int)$h['ronde'] > 1) {
                    if (!$checkCompleet(
                            (int)$h['ronde'],
                            $h['distance_combination_id'] ?? '',
                            $h['distance_id'] ?? '',
                            $h['ronde_type'] ?? '')) {
                        $h['vorige_niet_compleet'] = true;
                        $h['rijders'] = [];   // geen rijders meesturen
                        $heats[] = $h;
                        continue;
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
    // klassement_beschikbaar-vlag verandert bij publish/intrek; geen cache.
    header('Cache-Control: no-store, must-revalidate');
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

        // Klassement-check per DC — alleen gepubliceerde klassementen
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
                -- Uitgesloten rijders (rang IS NULL, bv. DQ-SF/DQ-DF) sorteren
                -- naar het einde, niet naar het begin (MySQL default = NULL eerst).
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang, t.punten_totaal
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

// ── API: volledig serie-klassement (zonder auth) ─────────────────────────────
if ($action === 'serie_klassement') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    $klId = trim($_GET['klassement_id'] ?? '');
    if (!$klId) { echo json_encode(['error' => 'klassement_id verplicht']); exit; }
    try {
        // Filter via klassement_series.gepubliceerd_at — alleen gepubliceerde
        // series mogen in /public worden opgehaald. Niet-gepubliceerd → 404.
        $kl = $pdo->prepare("
            SELECT k.id, k.naam, k.seizoen, k.bron_bestand, k.totaal_rijders,
                   k.categorieen, k.wedstrijden_meta, k.aangemaakt_op
            FROM   klassementen k
            JOIN   klassement_series s ON s.klassement_id = k.id
            WHERE  k.id = ?
              AND  k.bron_bestand = '(serie-berekening)'
              AND  s.gepubliceerd_at IS NOT NULL
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
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title data-i18n="page_title">InlineComp – Mijn wedstrijd</title>
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
html {
    /* Native pull-to-refresh van de browser uitschakelen — moet op html
       én body staan voor brede browser-compatibiliteit. Onze eigen
       PTR-handler vangt de gesture in plaats. */
    overscroll-behavior-y: contain;
}
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 1rem;
    color: var(--tekst);
    background: var(--grijs);
    overscroll-behavior-y: contain;
    min-height: 100vh;
}
header {
    background: var(--blauw);
    color: var(--wit);
    padding: 12px 12px 10px;
    display: flex; flex-direction: column;
}
/* Bovenste rij: 📢 links, titel midden, i + ? rechts. Onderste rij:
   subtitel breeduit centreren. */
.hdr-row-top { display: flex; align-items: center; gap: 8px; }
.hdr-btns       { display: flex; gap: 6px; flex-shrink: 0; align-items: center; }
.hdr-btns-right { justify-content: flex-end; }
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
header h1   { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
header .sub { font-size: .95rem; opacity: .8; margin-top: 6px; text-align: center; }
@media (max-width: 480px) {
    header { padding: 10px 8px 8px; }
    header h1  { font-size: 1.2rem; }
    header .sub { font-size: .78rem; margin-top: 4px; }
    .btn-help { width: 30px; height: 30px; font-size: 1rem; }
    .btn-meldingen { font-size: .95rem; }
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
/* Lege footer-cellen (bv. baan-logo bij wedstrijd zonder baan, of org-logo
   dat te breed was en naar de marquee verhuisd is) volledig inklappen,
   anders blijft de cell layout-ruimte innemen en eindigt de marquee net
   vóór de rand. */
.org-footer-inner > :empty { display: none !important; }
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
    flex-shrink: 0;          /* nooit ovaal worden in flex-container */
}
.btn-help:active { background: rgba(255,255,255,.35); }
/* Taal-dropdown: toont emoji-vlag van actieve taal. Klik = expand panel
   met 4 talen. Op Windows zonder emoji-flag-glyph valt 'ie terug op
   letterparen (NL/GB/DE/FR) — bewust geaccepteerd. Vorm blijft ronde
   knop, font-style normal voorkomt italic-erfgenaam van .btn-help. */
.btn-lang {
    padding: 0;
    font-style: normal;
    display: flex;
    align-items: center;
    justify-content: center;
    /* manipulation: schakel double-tap-to-zoom uit op touch → eerste tap
     * vuurt onmiddellijk (geen 300ms delay) → paneel opent direct. */
    touch-action: manipulation;
}
.btn-lang .i18n-flag {
    font-size: 1.05rem;
    line-height: 1;
}

/* Uitgevouwen taal-panel: compact horizontaal rijtje van 4 vlag-knoppen.
   Geen tekstnamen — vlag-emoji + title-tooltip is voldoende.
   Positionering wordt via JS gezet (top/right/left vanuit getBoundingClientRect). */
.i18n-dropdown-panel {
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-shadow: 0 4px 14px rgba(0,0,0,.2);
    padding: 4px;
    display: flex;
    flex-direction: row;
    gap: 2px;
}
.i18n-dropdown-opt {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6px 8px;
    background: none;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-family: inherit;
    touch-action: manipulation;
}
.i18n-dropdown-opt:hover { background: #f0f6ff; }
.i18n-dropdown-opt.is-active {
    background: #1F4E79;
}
.i18n-dropdown-opt .i18n-flag {
    font-size: 1.4rem;
    line-height: 1;
}
.btn-meldingen   { font-style: normal; font-size: 1.1rem; position: relative; }
.meld-badge      { position: absolute; top: -4px; right: -4px; background: #d22;
                   color: #fff; font-size: .65rem; font-weight: 700;
                   min-width: 17px; height: 17px; padding: 0 4px; border-radius: 9px;
                   display: flex; align-items: center; justify-content: center;
                   border: 2px solid #fff; line-height: 1; }
/* Allemaal gelezen → grijs ipv rood. Geeft passief signaal "ze zijn er,
   geen actie nodig" terwijl rood + uitroepteken = "kijk even". */
.meld-badge.gezien { background: #888; }

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
.auto-stempel {
    font-size: .7rem; color: #888; font-weight: normal; line-height: 1.4;
    white-space: nowrap;
}
/* In stap-1-label valt de stempel rechts op de regel (oude positie). */
.stap-label .auto-stempel { float: right; }
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
    display: flex; gap: 8px; margin-bottom: 8px;
}
.filter-rij input[type=checkbox] { display: none; }
.filter-rij label.filter-chip { flex: 1; }
.filter-chip {
    display: inline-flex; align-items: center; justify-content: center; gap: 5px;
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
/* Stempel in persoon-card-header: zelfde kleur + grootte als .persoon-snr
   (witte tekst met .8 opacity op blauwe achtergrond). Icoon boven de tijd
   gestapeld zodat het compact blijft naast de cat-badge. */
.persoon-header .auto-stempel {
    color: #fff; opacity: .8; font-size: .9rem;
    display: inline-flex; flex-direction: column; align-items: center;
    line-height: 1.05;
}
.persoon-header .auto-stempel .aut-icon { font-size: .85rem; }
.persoon-header .auto-stempel .aut-tijd { font-weight: 600; font-variant-numeric: tabular-nums; }

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
/* Compactere tabs bij 3+ kinderen — voornaam verbergen, kleinere padding,
   zodat snr-badge én × altijd zichtbaar blijven op telefoon-breedte. */
.kind-tabs[data-count="3"] .kind-tab,
.kind-tabs[data-count="4"] .kind-tab {
    padding:10px 6px; font-size:1.1rem; gap:4px;
}
.kind-tabs[data-count="3"] .kind-tab > span:nth-child(2),
.kind-tabs[data-count="4"] .kind-tab > span:nth-child(2) {
    display:none;                    /* voornaam wegklappen */
}
.kind-tabs[data-count="3"] .kind-tab .kind-tab-snr,
.kind-tabs[data-count="4"] .kind-tab .kind-tab-snr {
    font-size:.95rem; padding:1px 6px;
}
.kind-tabs[data-count="3"] .kind-tab-plus,
.kind-tabs[data-count="4"] .kind-tab-plus {
    padding:10px 10px;
}
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
    padding: 8px 44px 8px 12px; font-weight: 700; font-size: .95rem;
    display: flex; align-items: center; gap: 8px;
    position: relative;            /* anker voor de absolute close-knop */
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
/* Audit-icoon links van de tijd; tijd zelf blijft rechts-uitgelijnd */
.heat-card-tabel .col-tijd-audit { float: left; font-family: sans-serif; opacity: .85; cursor: help; }
/* Fin-kolom: header normale kleur (label), data-cijfers ROOD + bold zodat
 * de finishpositie meteen opvalt. Stond vroeger helemaal rechts, viel weg. */
.heat-card-tabel .col-fin    { text-align: center; width: 32px; }
.heat-card-tabel td.col-fin  { font-weight: 700; color: #d32f2f; }
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
    /* Heat-tabel compacter op telefoon zodat ook puntenkoers (extra Rnd + Pnt
       kolommen) binnen het scherm blijft staan. Bij sprints zonder die extra
       kolommen valt het ruimer uit. */
    .heat-card-tabel { font-size: .76rem; table-layout: fixed; width: 100%; }
    .heat-card-tabel th, .heat-card-tabel td { padding: 4px 3px;
        overflow: hidden; text-overflow: clip; }
    .heat-card-tabel th { font-size: .68rem; }   /* headers smaller — labels iets breder dan de cijfer-data */
    .heat-card-tabel .col-pos  { width: 22px; padding-left: 2px; padding-right: 2px; }
    .heat-card-tabel .col-snr  { width: 30px; padding-left: 2px; padding-right: 2px; }
    .heat-card-tabel .col-naam { word-break: break-word; }
    .heat-card-tabel .col-rnd  { width: 26px; padding-left: 2px; padding-right: 2px; }
    .heat-card-tabel .col-pk   { width: 26px; padding-left: 2px; padding-right: 2px; }
    .heat-card-tabel .col-fin  { width: 26px; padding-left: 4px; padding-right: 2px; }
    /* Iets ruimer (was 70px) zodat audit-icoon ✋/📷 + tijd op één regel passen
       op smalle telefoons. Voorkomt dat 1:00.000 onder het icoon springt. */
    .heat-card-tabel .col-tijd { font-size: .66rem; width: 88px; padding-left: 4px; padding-right: 4px; white-space: nowrap; }
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
.prog-rit-opm { font-size: .78rem; color: #856404; font-style: italic; margin-top: 2px; font-weight: 400; }
.prog-type { font-size: .75rem; }
.prog-blok {
    padding: 6px 0; font-size: .85rem; color: #888;
    border-bottom: 1px solid #f0f2f5; font-style: italic;
}
/* ── Programma-blokken (pauze, inrijden, ceremonie, herstart, start) ── */
/* Dag-filter-balk bovenaan bij multi-day. Sticky zodat hij meegaat als je
   scrollt — anders moet je bij dag-3 terugscrollen om opnieuw te kiezen. */
.prog-dag-filter {
    display: flex; flex-wrap: wrap; gap: 6px;
    margin: 0 0 10px 0; padding: 6px 0;
    position: sticky; top: 0; z-index: 5;
    background: #fff;
}
.prog-dag-btn {
    border: 1px solid #b3cae6; background: #fff; color: #1a3a5c;
    padding: 4px 9px; border-radius: 14px; font-size: .82rem;
    font-weight: 600; cursor: pointer; transition: background .12s;
    display: inline-flex; flex-direction: column; align-items: center;
    justify-content: center; line-height: 1.15; min-height: 38px;
}
.prog-dag-btn:hover { background: #eaf2fb; }
.prog-dag-btn.actief {
    background: #1a3a5c; color: #fff; border-color: #1a3a5c;
}
/* Korte datum onder "Dag N" — klein zodat 3+ dagen op 1 regel passen
   op een telefoon, ook in talen met langer woord ("Jour", "Day"). */
.prog-dag-btn-datum {
    display: block; font-size: .55rem; font-weight: 400;
    opacity: .8; margin-top: 1px; letter-spacing: 0;
    white-space: nowrap;
}
.prog-dag-btn.actief .prog-dag-btn-datum { opacity: .95; }
/* Verbergen-class voor de filter: data-dag-nr items krijgen .verborgen
   als de actieve dag-filter ze niet matcht. */
.verborgen { display: none !important; }

/* Programma-rit-filter: pills onder de dag-balk om "Alleen mijn ritten"
   en "Alleen nog te rijden" toggelen. Toggle gebeurt via data-attributen
   op de tab-content container; CSS hier verbergt non-matchende ritten. */
.prog-rit-filter {
    display: flex; flex-wrap: nowrap; gap: 6px;
    margin: 0 0 8px 0; padding: 4px 0;
}
.prog-rit-btn {
    flex: 1 1 0;            /* beide pills delen breedte 50/50 */
    min-width: 0;           /* laat krimpen toe op smalle schermen */
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    border: 1px solid #b3cae6; background: #fff; color: #1a3a5c;
    padding: 4px 10px; border-radius: 14px; font-size: .66rem;
    font-weight: 600; cursor: pointer; transition: background .12s;
}
/* Hover alleen op apparaten met echte hover (muis) — op touch blijft
   :hover anders plakken na een tap totdat je elders tikt. */
@media (hover: hover) {
    .prog-rit-btn:hover { background: #eaf2fb; }
}
.prog-rit-btn.actief {
    background: #1a3a5c; color: #fff; border-color: #1a3a5c;
}
/* "Alleen mijn ritten" actief → verberg alleen ritten zonder .prog-rij-mijn
   class, plus combi-boxen zonder mijn-rit erin. Blokken (pauze, inrijden,
   wedstrijdstart, ceremonie) blijven staan als context-anker. :has() heeft
   moderne browser support — voor edge-cases zonder :has() blijven combi-
   boxen wel zichtbaar (acceptabel). */
.tab-content[data-filter-mijn="1"] .prog-rij:not(.prog-rij-mijn),
.tab-content[data-filter-mijn="1"] .prog-combi-box:not(:has(.prog-rij-mijn)) {
    display: none !important;
}
/* "Alleen nog te rijden" actief → verberg ritten met uitslagen (🏁). */
.tab-content[data-filter-gereden-uit="1"] .prog-rij-gereden {
    display: none !important;
}
/* Dag-header bij meerdaags evenement: prominente scheiding tussen dagen */
.prog-dag-header {
    background: linear-gradient(to right, #1a3a5c, #2E75B6);
    color: #fff; padding: 10px 14px; border-radius: 6px;
    font-size: 1rem; font-weight: 700; margin: 14px 0 8px 0;
    text-transform: capitalize; letter-spacing: .02em;
    box-shadow: 0 2px 4px rgba(26,58,92,.15);
}
.prog-dag-header:first-child { margin-top: 4px; }
.prog-blok-rij { background:#e8eaf6; border-radius:6px; padding:6px 10px;
                 margin:6px 0; font-size:.85rem; color:#333; }
.prog-blok-top { display:flex; flex-wrap:wrap; align-items:baseline; gap:.5rem; }
.prog-blok-rij .prog-blok-tijd { color:#666; font-variant-numeric:tabular-nums; }
.prog-blok-rij .prog-blok-titel { font-weight:600; }
.prog-blok-rij .prog-blok-duur { color:#555; font-size:.8rem; }
.prog-blok-rij .prog-blok-opm { color:#555; font-style:italic; }
.prog-blok-rij .prog-blok-cats { margin-top:2px; padding-left:1.4rem; color:#555; font-size:.78rem; }
.prog-blok-rij.prog-blok-pauze { background:#fff3e0; }
.prog-blok-rij.prog-blok-inrijden { background:#e3f2fd; }
.prog-blok-rij.prog-blok-wedstrijdstart { background:#e8f5e9; }
.prog-blok-rij.prog-blok-ceremonie { background:#fff8e1; }
.prog-blok-rij.prog-blok-herstart { background:#ffebee; }
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
    position: absolute; top: 6px; right: 6px;
    border: none; background: #d22; color: #fff;
    width: 28px; height: 28px; border-radius: 50%;
    font-size: 1.1rem; font-weight: 700; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.25);
    transition: background .12s, transform .08s;
}
.overlay-sluit:hover  { background: #b71c1c; }
.overlay-sluit:active { transform: scale(.92); }

.melding { text-align: center; padding: 24px; color: #888; font-size: .95rem; }
.melding-fout { color: #c00; }
.spinner {
    display: inline-block; width: 20px; height: 20px;
    border: 2px solid #ddd; border-top-color: var(--oranje);
    border-radius: 50%; animation: spin .6s linear infinite;
    vertical-align: middle; margin-right: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Pull-to-refresh indicator (zelfde patroon als coach-app) */
#ptr {
    position: fixed; top: 0; left: 0; right: 0;
    background: var(--middenblauw); color: var(--wit);
    text-align: center; font-size: .85rem; padding: 6px 0;
    transform: translateY(-100%); transition: transform .15s ease-out;
    z-index: 900; pointer-events: none;
}
#ptr.zichtbaar { transform: translateY(0); }
#ptr.laadt     { background: var(--blauw); }
</style>
</head>
<body>

<div id="ptr" data-i18n="ptr_trek">↓ Trek verder om te vernieuwen</div>

<header>
    <div class="hdr-row-top">
        <div class="hdr-btns hdr-btns-left">
            <button class="btn-help btn-meldingen" id="btn-meldingen-overzicht" data-i18n-title="hdr_meldingen_title" title="Mededelingen voor deze wedstrijd">📢<span id="meldingen-badge" class="meld-badge" style="display:none">0</span></button>
            <button class="btn-help btn-lang" id="btn-lang" title="Language / Taal" aria-label="Switch language"></button>
        </div>
        <div class="hdr-center">
            <h1>InlineComp – Public</h1>
        </div>
        <div class="hdr-btns hdr-btns-right">
            <button class="btn-help" onclick="toonInfo()" data-i18n-title="hdr_info_title" title="Over InlineComp">i</button>
            <button class="btn-help" onclick="toonHelp()" data-i18n-title="hdr_help_title" title="Hoe werkt het?">?</button>
        </div>
    </div>
    <div class="sub" data-i18n="hdr_sub">Zoek je heats, starttijden en resultaten</div>
</header>

<div id="org-footer" class="org-footer">
    <div class="org-footer-inner">
        <span id="footer-org-logo"></span>
        <span id="footer-org-naam" class="org-footer-naam"></span>
        <div id="footer-sponsors" class="org-footer-sponsors"></div>
        <span id="footer-baan-logo"></span>
    </div>
</div>

<div class="container">
    <div id="pwa-banner" class="pwa-banner" style="display:none">
        <div class="pwa-banner-tekst">
            <b data-i18n="pwa_installeer_titel">Installeer InlineComp</b>
            <span data-i18n="pwa_installeer_uitleg">Voeg toe aan je startscherm voor snelle toegang</span>
        </div>
        <button class="btn-install" id="pwa-install" data-i18n="pwa_btn_install">Installeer</button>
        <button class="btn-sluit" id="pwa-sluit" data-i18n-title="pwa_btn_sluit" title="Sluiten">&times;</button>
    </div>
    <div class="stap">
        <div class="stap-label">
            <span class="stap-nr">1</span> <span data-i18n="stap1_label">Kies je wedstrijd</span>
            <span class="auto-stempel"></span>
        </div>
        <div class="filter-rij">
            <input type="checkbox" id="chk-oud"><label for="chk-oud" class="filter-chip" data-i18n="filter_eerder" data-i18n-title="filter_eerder_title" title="Eerdere wedstrijden">Eerder</label>
            <input type="checkbox" id="chk-vandaag" checked><label for="chk-vandaag" class="filter-chip" data-i18n="filter_vandaag">Vandaag</label>
            <input type="checkbox" id="chk-toekomst"><label for="chk-toekomst" class="filter-chip" data-i18n="filter_later" data-i18n-title="filter_later_title" title="Toekomstige wedstrijden">Later</label>
        </div>
        <select id="sel-comp"><option value="" data-i18n="opt_laden">Laden…</option></select>
    </div>
    <div id="comp-info" class="comp-info" style="display:none"></div>
    <div class="stap">
        <div class="stap-label"><span class="stap-nr">2</span> <span data-i18n="stap2_label">Startnummer, licentie of achternaam</span></div>
        <input type="text" id="inp-snr" data-i18n-placeholder="zoek_placeholder" placeholder="Startnummer, licentienr of achternaam…" autocomplete="off" inputmode="search">
    </div>
    <button class="btn-zoek" id="btn-zoek" data-i18n="btn_zoeken" disabled>Zoeken</button>
    <div id="resultaat"></div>
</div>

<script>
// ── i18n: NL / EN ─────────────────────────────────────────────────────────
// Shared i18n-helpers — herbruikt straks door coach-, jury- en admin-app.
// PHP-include (geen extra HTTP-request) zodat één bron van waarheid is.
<?php
$i18nPath = __DIR__ . '/../js/i18n.js';
if (is_readable($i18nPath)) {
    readfile($i18nPath);
} else {
    // Duidelijke melding in console + browser ipv silent fail (=
    // "initI18n is not defined" zonder context).
    echo "console.error('i18n.js niet gevonden op server (verwacht: ' + " . json_encode($i18nPath) . " + ') — upload het bestand via SFTP');\n";
    echo "alert('Taal-systeem niet geladen — i18n.js ontbreekt op de server. Upload js/i18n.js naar de juiste map.');\n";
}
?>

// ── App-specifiek vertaal-woordenboek (NL + EN) ──────────────────────────
// Toggle via vlag-knop in header. Persisteert in localStorage onder 'ic_lang'.
// Dynamische content (rendered via JS) gebruikt t('key'); statische HTML
// gebruikt data-i18n* attributen die applyI18n() bij init en bij toggle leest.
const T = {
    nl: {
        // ── Document ──
        page_title: 'InlineComp – Mijn wedstrijd',
        // ── Header / static ──
        ptr_trek: '↓ Trek verder om te vernieuwen',
        hdr_meldingen_title: 'Mededelingen voor deze wedstrijd',
        hdr_info_title: 'Over InlineComp',
        hdr_help_title: 'Hoe werkt het?',
        hdr_sub: 'Zoek je heats, starttijden en resultaten',
        pwa_installeer_titel: 'Installeer InlineComp',
        pwa_installeer_uitleg: 'Voeg toe aan je startscherm voor snelle toegang',
        pwa_btn_install: 'Installeer',
        pwa_btn_sluit: 'Sluiten',
        stap1_label: 'Kies je wedstrijd',
        stap2_label: 'Startnummer, licentie of achternaam',
        filter_eerder: 'Eerder',
        filter_eerder_title: 'Eerdere wedstrijden',
        filter_vandaag: 'Vandaag',
        filter_later: 'Later',
        filter_later_title: 'Toekomstige wedstrijden',
        opt_laden: 'Laden…',
        zoek_placeholder: 'Startnummer, licentienr of achternaam…',
        btn_zoeken: 'Zoeken',
        // ── Connection banner ──
        conn_geen_internet: '📡 Geen internet — ververst zodra de verbinding terug is',
        conn_server_down: '⚠ Server niet bereikbaar — opnieuw proberen…',
        conn_laatste_update: 'laatste update {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Kies tenminste één filter hierboven —',
        opt_kies_wedstrijd: '— Kies een wedstrijd —',
        opt_binnenkort: '(binnenkort)',
        opt_fout_laden: 'Fout bij laden',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Laden…',
        msg_zoeken: 'Zoeken…',
        msg_zoeken_op: 'Zoeken op "{term}"…',
        msg_rijders_ophalen: 'Rijders ophalen…',
        msg_je_rijders_ophalen: 'Je rijders ophalen…',
        msg_geen_resultaten: 'Geen resultaten gevonden.',
        msg_geen_rijders: 'Geen rijders gevonden.',
        msg_geen_startlijst: 'Geen startlijst beschikbaar voor deze rit.',
        msg_geen_klassement: 'Geen klassement beschikbaar.',
        msg_geen_uitslagen: 'Geen uitslagen beschikbaar.',
        msg_geen_posities: 'Geen posities in deze categorie.',
        msg_kies_categorie_klassement: 'Kies een categorie om het klassement te zien.',
        msg_programma_nb: 'Programma niet beschikbaar.',
        msg_nog_geen_heats: 'Nog geen heats beschikbaar.',
        msg_nog_geen_resultaten: 'Nog geen resultaten beschikbaar.',
        msg_vorige_ronde_nb: 'Vorige ronde nog niet compleet — startlijst verschijnt zodra alle resultaten daar binnen zijn.',
        chooser_titel: 'Zoekresultaten voor "{term}"',
        chooser_sluit: 'Sluiten',
        chooser_al_in_lijst: 'al in lijst',
        chooser_doet_niet_mee: 'doet niet mee in deze wedstrijd',
        chooser_max: 'Max {max} rijders · {vrij} plek(ken) vrij',
        chooser_toevoegen: 'Toevoegen',
        alert_max_bereikt: 'Maximum van {max} rijders bereikt. Verwijder eerst iemand om een nieuwe toe te voegen.',
        alert_max_select: 'Maximum {max} — er is nog plek voor {vrij}. Je hebt er {n} aangevinkt.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(rijder)',
        kind_tab_verwijder: 'Verwijder deze rijder',
        kind_plus_title: 'Voeg broertje/zusje toe',
        kind_plus_max: 'Maximum {max} rijders',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Niet ingeschreven',
        status_0: 'Niet bevestigd',
        status_1: 'Bevestigd',
        status_2: 'Afgemeld',
        status_3: 'Afgem. bij org.',
        status_4: 'Niet getekend',
        status_5: 'Bev. bij org.',
        status_onbekend: '?',
        snr_label: 'Snr',
        auto_stempel_title: 'Tijdstip laatste auto-refresh',
        // ── Tabs ──
        tab_programma: '📅 Programma',
        tab_heats: '🏃 Heats',
        tab_resultaten: '🏆 Resultaten',
        tab_uitslagen: '📊 Uitslagen',
        // ── Programma ──
        prog_titel: 'Wedstrijdprogramma',
        prog_combi_kop: '🔗 Gecombineerde rit — rijden tegelijk',
        prog_blok_pauze: 'Pauze',
        prog_blok_inrijden: 'Inrijden',
        prog_blok_wedstrijdstart: 'Wedstrijd start',
        prog_blok_ceremonie: 'Ceremonie',
        prog_blok_herstart: 'Herstart',
        prog_blok_min: 'min',
        // Multi-day filter
        prog_dag_alle: 'Alle',
        prog_dag: 'Dag',
        // Programma-filter pills (alleen-mijn / alleen-nog-te-rijden)
        prog_filter_mijn: '👤 Mijn ritten',
        prog_filter_te_rijden: '⏳ Nog te rijden',
        // ── Heats ──
        heat_wachten_vorige: 'Wachten op vorige ronde',
        heat_jouw_resultaat: 'Jij:',
        heat_bruto_gemeten: 'gemeten',
        heat_bruto_officieel: 'officieel',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Snr',
        col_naam: 'Naam',
        col_rnd: 'Rnd',
        col_pnt: 'Pnt',
        col_tijd: 'Tijd',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Cat',
        col_tot: 'Tot',
        // ── Rondes ──
        ronde_serie: 'Serie',
        ronde_kf: 'KF',
        ronde_hf: 'HF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Runner-up',
        // ── Resultaten ──
        res_uitslagen_titel: 'Uitslagen per afstand',
        res_pt: 'pt',
        res_klassement: 'Klassement {dc}',
        res_punten: '{n} punten',
        // ── Uitslagen tab ──
        uitsl_titel: 'Volledige uitslagen van deze wedstrijd',
        uitsl_opt_kies_cat: '— Kies categorie —',
        uitsl_opt_kies_afstand: '— Kies afstand —',
        uitsl_klassement_opt: '🏆 Klassement',
        // ── Serie-klassement ──
        serie_titel: '🏆 Serie-klassement',
        serie_opt_kies: '— Kies een serie-klassement —',
        serie_opt_alle_cats: '— Alle categorieën —',
        serie_aantal_rijders: '{n} rijders',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Fout: {msg}',
        err_zoeken: 'Fout bij zoeken: {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Laat los om te vernieuwen',
        ptr_vernieuwen: '⟳ Vernieuwen…',
        ptr_bijgewerkt: '✓ Bijgewerkt',
        ptr_fout: '⚠ Fout bij vernieuwen',
        ptr_wachten: '⏳ Even wachten ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Mededelingen',
        meld_tot: ' tot ',
        meld_begrepen: '✓ Begrepen',
        // ── Info modal ──
        info_titel: 'Over InlineComp',
        info_h1: 'Wat is InlineComp?',
        info_p1: 'InlineComp is een wedstrijdbeheersysteem voor inline skaten, ontwikkeld om wedstrijdorganisaties te ondersteunen bij het beheren van startlijsten, live tijdwaarneming en het publiceren van uitslagen.',
        info_p2_html: 'Deze publieke pagina is bedoeld voor <b>rijders en toeschouwers</b>: zoek je startnummer op en bekijk direct je heats, starttijden en resultaten.',
        info_h2: 'In ontwikkeling',
        info_p3: 'InlineComp wordt actief doorontwikkeld. Functies kunnen veranderen en er kunnen nog fouten in zitten. Feedback is zeer welkom!',
        info_h3_html: 'Contact &amp; feedback',
        info_p4: 'Heb je een vraag, suggestie of bug gevonden? Laat het weten:',
        info_h4: 'Anonieme bezoek-statistieken',
        info_p5_html: 'We tellen anoniem aantal bezoekers, actieve sessies en piek gelijktijdig online — puur om te zien hoe veel de app wordt gebruikt en om de hosting stabiel te houden. Er worden <b>geen IP-adressen of persoonsgegevens</b> opgeslagen en er zijn <b>geen derde partijen</b> betrokken.',
        info_h5_html: 'Privacy &amp; persoonsgegevens',
        info_p6: 'Deze app toont wedstrijdgegevens die door de KNSB of andere wedstrijdorganisaties aan ons worden geleverd (o.a. namen, startnummers, vereniging). In de privacyverklaring lees je welke gegevens wij verwerken, op welke grondslag en hoe je een verwijderverzoek kunt indienen.',
        info_btn_privacy: '📄 Bekijk privacyverklaring',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'Hoe werkt InlineComp?',
        help_h1: 'Aan de slag',
        help_stap1_html: 'Kies je <b>wedstrijd</b> uit de lijst. Met de drie filter-knoppen — <i>Eerder</i>, <i>Vandaag</i> en <i>Later</i> — bepaal je welke wedstrijden je ziet. Standaard staat alleen <i>Vandaag</i> aan; klik een knop aan/uit om het bereik aan te passen.',
        help_stap2_html: 'Vul je <b>startnummer</b> in en klik op <b>Zoeken</b> — je persoonlijke overzicht verschijnt.',
        help_stap3_html: 'Wil je meerdere rijders volgen (bv. broer, zus of een teamgenoot)? Klik op de <b>+</b>-knop bovenin. Je kunt tot <b>4 rijders</b> tegelijk volgen — switch via de tabs bovenaan met hun startnummers.',
        help_mock_kies_w: 'Kies je wedstrijd',
        help_mock_voorbeeld: 'Voorbeeldwedstrijd — 19 april 2026',
        help_mock_snr_lic: 'Startnummer, licentie of achternaam',
        help_mock_snr: 'Startnummer: 86',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'Na het zoeken zie je <b>4 tabs</b>:',
        help_p_prog_html: '<b>Programma</b> — alle ritten van de wedstrijd. Jouw ritten zijn gemarkeerd. Tik op een rit om de startlijst te bekijken.',
        help_p_heats_html: '<b>Heats</b> — jouw heats met alle rijders. Je eigen rij is gemarkeerd. Na de finish zie je tijden en posities.',
        help_p_res_html: '<b>Resultaten</b> — jouw persoonlijke uitslagen per afstand en je klassement.',
        help_p_uitsl_html: '<b>Uitslagen</b> — de volledige uitslag van alle rijders. Kies een categorie en afstand, of bekijk het klassement.',
        help_mock_jouw_naam: 'Jouw naam',
        help_h_auto: 'Automatisch bijgewerkt',
        help_p_auto_html: 'De pagina ververst zichzelf elke minuut zolang het tabblad zichtbaar is. Naast de wedstrijdnaam zie je <b>🔄 HH:MM</b> — dat is het tijdstip van de laatste verversing.',
        help_h_meld: 'Mededelingen',
        help_p_meld_html: 'Bovenaan staat een <b>📢-knop</b> (zichtbaar zodra er een mededeling actief is). Belangrijke aankondigingen van de organisatie verschijnen automatisch als pop-up en blijven daarna onder deze knop bereikbaar — bv. "Programma loopt 15 min uit".',
        help_h_tip: 'Tip',
        help_p_tip: 'Geen resultaten? De uitslag verschijnt zodra de jury de resultaten heeft bevestigd.',
    },
    en: {
        // ── Document ──
        page_title: 'InlineComp – My race',
        // ── Header / static ──
        ptr_trek: '↓ Pull further to refresh',
        hdr_meldingen_title: 'Announcements for this race',
        hdr_info_title: 'About InlineComp',
        hdr_help_title: 'How does it work?',
        hdr_sub: 'Find your heats, start times and results',
        pwa_installeer_titel: 'Install InlineComp',
        pwa_installeer_uitleg: 'Add to your home screen for quick access',
        pwa_btn_install: 'Install',
        pwa_btn_sluit: 'Close',
        stap1_label: 'Choose your race',
        stap2_label: 'Start number, license or last name',
        filter_eerder: 'Earlier',
        filter_eerder_title: 'Earlier races',
        filter_vandaag: 'Today',
        filter_later: 'Later',
        filter_later_title: 'Upcoming races',
        opt_laden: 'Loading…',
        zoek_placeholder: 'Start number, license nr or last name…',
        btn_zoeken: 'Search',
        // ── Connection banner ──
        conn_geen_internet: '📡 No internet — will refresh when the connection returns',
        conn_server_down: '⚠ Server unreachable — retrying…',
        conn_laatste_update: 'last update {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Select at least one filter above —',
        opt_kies_wedstrijd: '— Choose a race —',
        opt_binnenkort: '(coming soon)',
        opt_fout_laden: 'Loading failed',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Loading…',
        msg_zoeken: 'Searching…',
        msg_zoeken_op: 'Searching for "{term}"…',
        msg_rijders_ophalen: 'Fetching skaters…',
        msg_je_rijders_ophalen: 'Fetching your skaters…',
        msg_geen_resultaten: 'No results found.',
        msg_geen_rijders: 'No skaters found.',
        msg_geen_startlijst: 'No start list available for this race.',
        msg_geen_klassement: 'No standings available.',
        msg_geen_uitslagen: 'No results available.',
        msg_geen_posities: 'No positions in this category.',
        msg_kies_categorie_klassement: 'Choose a category to view the standings.',
        msg_programma_nb: 'Program not available.',
        msg_nog_geen_heats: 'No heats available yet.',
        msg_nog_geen_resultaten: 'No results available yet.',
        msg_vorige_ronde_nb: 'Previous round not complete yet — start list appears as soon as all results have been entered.',
        chooser_titel: 'Search results for "{term}"',
        chooser_sluit: 'Close',
        chooser_al_in_lijst: 'already in list',
        chooser_doet_niet_mee: 'not participating in this race',
        chooser_max: 'Max {max} skaters · {vrij} spot(s) free',
        chooser_toevoegen: 'Add',
        alert_max_bereikt: 'Maximum of {max} skaters reached. Remove someone first to add a new one.',
        alert_max_select: 'Maximum {max} — there is room for {vrij}. You selected {n}.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(skater)',
        kind_tab_verwijder: 'Remove this skater',
        kind_plus_title: 'Add brother/sister',
        kind_plus_max: 'Maximum {max} skaters',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Not registered',
        status_0: 'Not confirmed',
        status_1: 'Confirmed',
        status_2: 'Withdrawn',
        status_3: 'Withdrawn by org.',
        status_4: 'Not signed in',
        status_5: 'Confirmed by org.',
        status_onbekend: '?',
        snr_label: 'Nr',
        auto_stempel_title: 'Time of last auto-refresh',
        // ── Tabs ──
        tab_programma: '📅 Program',
        tab_heats: '🏃 Heats',
        tab_resultaten: '🏆 Results',
        tab_uitslagen: '📊 All results',
        // ── Programma ──
        prog_titel: 'Race program',
        prog_combi_kop: '🔗 Combined race — skating together',
        prog_blok_pauze: 'Break',
        prog_blok_inrijden: 'Warm-up',
        prog_blok_wedstrijdstart: 'Race start',
        prog_blok_ceremonie: 'Ceremony',
        prog_blok_herstart: 'Restart',
        prog_blok_min: 'min',
        // Programma-filter pills (alleen-mijn / alleen-nog-te-rijden)
        prog_filter_mijn: '👤 My races',
        prog_filter_te_rijden: '⏳ Upcoming',
        // Multi-day filter
        prog_dag_alle: 'All',
        prog_dag: 'Day',
        // ── Heats ──
        heat_wachten_vorige: 'Waiting for previous round',
        heat_jouw_resultaat: 'You:',
        heat_bruto_gemeten: 'measured',
        heat_bruto_officieel: 'official',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Nr',
        col_naam: 'Name',
        col_rnd: 'Lap',
        col_pnt: 'Pts',
        col_tijd: 'Time',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Cat',
        col_tot: 'Tot',
        // ── Rondes ──
        ronde_serie: 'Series',
        ronde_kf: 'QF',
        ronde_hf: 'SF',
        ronde_finale: 'Final',
        ronde_b_finale: 'B-Final',
        ronde_runner_up: 'Runner-up',
        // ── Resultaten ──
        res_uitslagen_titel: 'Results per distance',
        res_pt: 'pt',
        res_klassement: 'Standings {dc}',
        res_punten: '{n} points',
        // ── Uitslagen tab ──
        uitsl_titel: 'Full results of this race',
        uitsl_opt_kies_cat: '— Choose category —',
        uitsl_opt_kies_afstand: '— Choose distance —',
        uitsl_klassement_opt: '🏆 Standings',
        // ── Serie-klassement ──
        serie_titel: '🏆 Series standings',
        serie_opt_kies: '— Choose a series standings —',
        serie_opt_alle_cats: '— All categories —',
        serie_aantal_rijders: '{n} skaters',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Error: {msg}',
        err_zoeken: 'Search error: {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Release to refresh',
        ptr_vernieuwen: '⟳ Refreshing…',
        ptr_bijgewerkt: '✓ Updated',
        ptr_fout: '⚠ Refresh error',
        ptr_wachten: '⏳ Please wait ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Announcements',
        meld_tot: ' until ',
        meld_begrepen: '✓ Understood',
        // ── Info modal ──
        info_titel: 'About InlineComp',
        info_h1: 'What is InlineComp?',
        info_p1: 'InlineComp is a race management system for inline speed skating, developed to support race organizations in managing start lists, live timekeeping and publishing results.',
        info_p2_html: 'This public page is intended for <b>skaters and spectators</b>: look up your start number and view your heats, start times and results directly.',
        info_h2: 'In development',
        info_p3: 'InlineComp is actively being developed. Features may change and bugs may still occur. Feedback is most welcome!',
        info_h3_html: 'Contact &amp; feedback',
        info_p4: 'Have a question, suggestion or found a bug? Let us know:',
        info_h4: 'Anonymous visit statistics',
        info_p5_html: 'We anonymously count visitor numbers, active sessions and peak concurrent users — purely to see how much the app is used and to keep hosting stable. <b>No IP addresses or personal data</b> are stored and <b>no third parties</b> are involved.',
        info_h5_html: 'Privacy &amp; personal data',
        info_p6: 'This app shows race data provided by the KNSB or other race organisations (incl. names, start numbers, club). The privacy statement details which data we process, on what basis and how to submit a removal request.',
        info_btn_privacy: '📄 View privacy statement',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'How does InlineComp work?',
        help_h1: 'Getting started',
        help_stap1_html: 'Choose your <b>race</b> from the list. With the three filter buttons — <i>Earlier</i>, <i>Today</i> and <i>Later</i> — you decide which races you see. By default only <i>Today</i> is on; click a button on/off to adjust the range.',
        help_stap2_html: 'Enter your <b>start number</b> and click <b>Search</b> — your personal overview appears.',
        help_stap3_html: 'Want to follow multiple skaters (e.g. brother, sister or a teammate)? Click the <b>+</b> button at the top. You can follow up to <b>4 skaters</b> at once — switch via the tabs at the top with their start numbers.',
        help_mock_kies_w: 'Choose your race',
        help_mock_voorbeeld: 'Sample race — 19 April 2026',
        help_mock_snr_lic: 'Start number, license or last name',
        help_mock_snr: 'Start number: 86',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'After searching you see <b>4 tabs</b>:',
        help_p_prog_html: '<b>Program</b> — all races of the meet. Your races are highlighted. Tap a race to view the start list.',
        help_p_heats_html: '<b>Heats</b> — your heats with all skaters. Your own row is highlighted. After the finish you see times and positions.',
        help_p_res_html: '<b>Results</b> — your personal results per distance and your standings.',
        help_p_uitsl_html: '<b>All results</b> — the full results of all skaters. Choose a category and distance, or view the standings.',
        help_mock_jouw_naam: 'Your name',
        help_h_auto: 'Automatically updated',
        help_p_auto_html: 'The page refreshes itself every minute as long as the tab is visible. Next to the race name you see <b>🔄 HH:MM</b> — that is the time of the last refresh.',
        help_h_meld: 'Announcements',
        help_p_meld_html: 'At the top is a <b>📢 button</b> (visible as soon as there is an active announcement). Important announcements from the organization appear automatically as a pop-up and remain accessible under this button afterwards — e.g. "Program is running 15 min behind".',
        help_h_tip: 'Tip',
        help_p_tip: 'No results yet? The result appears as soon as the jury has confirmed it.',
    },
    de: {
        // ── Document ──
        page_title: 'InlineComp – Mein Rennen',
        // ── Header / static ──
        ptr_trek: '↓ Weiter ziehen zum Aktualisieren',
        hdr_meldingen_title: 'Bekanntmachungen zu diesem Rennen',
        hdr_info_title: 'Über InlineComp',
        hdr_help_title: 'Wie funktioniert es?',
        hdr_sub: 'Finde deine Heats, Startzeiten und Ergebnisse',
        pwa_installeer_titel: 'InlineComp installieren',
        pwa_installeer_uitleg: 'Zum Startbildschirm hinzufügen für schnellen Zugriff',
        pwa_btn_install: 'Installieren',
        pwa_btn_sluit: 'Schließen',
        stap1_label: 'Wähle dein Rennen',
        stap2_label: 'Startnummer, Lizenz oder Nachname',
        filter_eerder: 'Früher',
        filter_eerder_title: 'Frühere Rennen',
        filter_vandaag: 'Heute',
        filter_later: 'Später',
        filter_later_title: 'Kommende Rennen',
        opt_laden: 'Lädt…',
        zoek_placeholder: 'Startnummer, Lizenznr. oder Nachname…',
        btn_zoeken: 'Suchen',
        // ── Connection banner ──
        conn_geen_internet: '📡 Kein Internet — wird bei wiederhergestellter Verbindung aktualisiert',
        conn_server_down: '⚠ Server nicht erreichbar — Neuversuch…',
        conn_laatste_update: 'letzte Aktualisierung {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Wähle mindestens einen Filter oben —',
        opt_kies_wedstrijd: '— Rennen wählen —',
        opt_binnenkort: '(in Kürze)',
        opt_fout_laden: 'Laden fehlgeschlagen',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Lädt…',
        msg_zoeken: 'Suche…',
        msg_zoeken_op: 'Suche nach "{term}"…',
        msg_rijders_ophalen: 'Skater abrufen…',
        msg_je_rijders_ophalen: 'Deine Skater abrufen…',
        msg_geen_resultaten: 'Keine Ergebnisse gefunden.',
        msg_geen_rijders: 'Keine Skater gefunden.',
        msg_geen_startlijst: 'Keine Startliste für dieses Rennen verfügbar.',
        msg_geen_klassement: 'Keine Wertung verfügbar.',
        msg_geen_uitslagen: 'Keine Ergebnisse verfügbar.',
        msg_geen_posities: 'Keine Positionen in dieser Kategorie.',
        msg_kies_categorie_klassement: 'Wähle eine Kategorie, um die Wertung anzuzeigen.',
        msg_programma_nb: 'Programm nicht verfügbar.',
        msg_nog_geen_heats: 'Noch keine Heats verfügbar.',
        msg_nog_geen_resultaten: 'Noch keine Ergebnisse verfügbar.',
        msg_vorige_ronde_nb: 'Vorherige Runde noch nicht abgeschlossen — Startliste erscheint, sobald alle Ergebnisse eingetragen sind.',
        chooser_titel: 'Suchergebnisse für "{term}"',
        chooser_sluit: 'Schließen',
        chooser_al_in_lijst: 'bereits in Liste',
        chooser_doet_niet_mee: 'nimmt nicht an diesem Rennen teil',
        chooser_max: 'Max. {max} Skater · {vrij} Platz(e) frei',
        chooser_toevoegen: 'Hinzufügen',
        alert_max_bereikt: 'Maximum von {max} Skatern erreicht. Entferne zuerst jemanden, um einen neuen hinzuzufügen.',
        alert_max_select: 'Maximum {max} — es ist Platz für {vrij}. Du hast {n} ausgewählt.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(Skater)',
        kind_tab_verwijder: 'Diesen Skater entfernen',
        kind_plus_title: 'Bruder/Schwester hinzufügen',
        kind_plus_max: 'Maximum {max} Skater',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Nicht angemeldet',
        status_0: 'Nicht bestätigt',
        status_1: 'Bestätigt',
        status_2: 'Abgemeldet',
        status_3: 'Abgem. bei Org.',
        status_4: 'Nicht unterschrieben',
        status_5: 'Best. bei Org.',
        status_onbekend: '?',
        snr_label: 'Nr.',
        auto_stempel_title: 'Zeitpunkt der letzten automatischen Aktualisierung',
        // ── Tabs ──
        tab_programma: '📅 Programm',
        tab_heats: '🏃 Heats',
        tab_resultaten: '🏆 Resultate',
        tab_uitslagen: '📊 Ergebnisse',
        // ── Programma ──
        prog_titel: 'Rennprogramm',
        prog_combi_kop: '🔗 Kombiniertes Rennen — gleichzeitig laufen',
        prog_blok_pauze: 'Pause',
        prog_blok_inrijden: 'Einlaufen',
        prog_blok_wedstrijdstart: 'Rennbeginn',
        prog_blok_ceremonie: 'Zeremonie',
        prog_blok_herstart: 'Neustart',
        prog_blok_min: 'Min.',
        // Multi-day filter
        prog_dag_alle: 'Alle',
        prog_dag: 'Tag',
        // Programma-filter pills
        prog_filter_mijn: '👤 Meine Rennen',
        prog_filter_te_rijden: '⏳ Kommende',
        // ── Heats ──
        heat_wachten_vorige: 'Warte auf vorherige Runde',
        heat_jouw_resultaat: 'Du:',
        heat_bruto_gemeten: 'gemessen',
        heat_bruto_officieel: 'offiziell',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Nr.',
        col_naam: 'Name',
        col_rnd: 'Runde',
        col_pnt: 'Pkt.',
        col_tijd: 'Zeit',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Kat',
        col_tot: 'Ges.',
        // ── Rondes ──
        ronde_serie: 'Serie',
        ronde_kf: 'VF',
        ronde_hf: 'HF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Runner-up',
        // ── Resultaten ──
        res_uitslagen_titel: 'Ergebnisse pro Distanz',
        res_pt: 'Pkt',
        res_klassement: 'Wertung {dc}',
        res_punten: '{n} Punkte',
        // ── Uitslagen tab ──
        uitsl_titel: 'Vollständige Ergebnisse dieses Rennens',
        uitsl_opt_kies_cat: '— Kategorie wählen —',
        uitsl_opt_kies_afstand: '— Distanz wählen —',
        uitsl_klassement_opt: '🏆 Wertung',
        // ── Serie-klassement ──
        serie_titel: '🏆 Serien-Wertung',
        serie_opt_kies: '— Serien-Wertung wählen —',
        serie_opt_alle_cats: '— Alle Kategorien —',
        serie_aantal_rijders: '{n} Skater',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Fehler: {msg}',
        err_zoeken: 'Suchfehler: {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Loslassen zum Aktualisieren',
        ptr_vernieuwen: '⟳ Aktualisiere…',
        ptr_bijgewerkt: '✓ Aktualisiert',
        ptr_fout: '⚠ Aktualisierungsfehler',
        ptr_wachten: '⏳ Bitte warten ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Bekanntmachungen',
        meld_tot: ' bis ',
        meld_begrepen: '✓ Verstanden',
        // ── Info modal ──
        info_titel: 'Über InlineComp',
        info_h1: 'Was ist InlineComp?',
        info_p1: 'InlineComp ist ein Wettkampfverwaltungssystem für Inline-Speedskating, entwickelt um Rennorganisationen bei Startlisten, Live-Zeitmessung und Ergebnisveröffentlichung zu unterstützen.',
        info_p2_html: 'Diese öffentliche Seite ist für <b>Skater und Zuschauer</b> gedacht: suche deine Startnummer und sieh direkt deine Heats, Startzeiten und Ergebnisse.',
        info_h2: 'In Entwicklung',
        info_p3: 'InlineComp wird aktiv weiterentwickelt. Funktionen können sich ändern und es können noch Fehler vorkommen. Feedback ist sehr willkommen!',
        info_h3_html: 'Kontakt &amp; Feedback',
        info_p4: 'Hast du eine Frage, einen Vorschlag oder einen Bug gefunden? Lass es uns wissen:',
        info_h4: 'Anonyme Besuchsstatistiken',
        info_p5_html: 'Wir zählen anonym Besucherzahlen, aktive Sitzungen und Spitzenwerte gleichzeitiger Nutzer — nur um zu sehen wie viel die App genutzt wird und das Hosting stabil zu halten. Es werden <b>keine IP-Adressen oder persönlichen Daten</b> gespeichert und <b>keine Dritten</b> sind beteiligt.',
        info_h5_html: 'Privatsphäre &amp; persönliche Daten',
        info_p6: 'Diese App zeigt Wettkampfdaten, die uns vom KNSB oder anderen Wettkampforganisationen geliefert werden (u.a. Namen, Startnummern, Verein). In der Datenschutzerklärung steht welche Daten wir verarbeiten, auf welcher Grundlage und wie du einen Löschantrag einreichen kannst.',
        info_btn_privacy: '📄 Datenschutzerklärung ansehen',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'Wie funktioniert InlineComp?',
        help_h1: 'Loslegen',
        help_stap1_html: 'Wähle dein <b>Rennen</b> aus der Liste. Mit den drei Filter-Buttons — <i>Früher</i>, <i>Heute</i> und <i>Später</i> — entscheidest du welche Rennen du siehst. Standardmäßig ist nur <i>Heute</i> aktiv; klicke einen Button an/aus, um den Bereich anzupassen.',
        help_stap2_html: 'Gib deine <b>Startnummer</b> ein und klicke auf <b>Suchen</b> — deine persönliche Übersicht erscheint.',
        help_stap3_html: 'Möchtest du mehrere Skater verfolgen (z.B. Bruder, Schwester oder Teamkollege)? Klicke auf den <b>+</b>-Button oben. Du kannst bis zu <b>4 Skater</b> gleichzeitig verfolgen — wechsle über die Tabs oben mit ihren Startnummern.',
        help_mock_kies_w: 'Wähle dein Rennen',
        help_mock_voorbeeld: 'Beispielrennen — 19. April 2026',
        help_mock_snr_lic: 'Startnummer, Lizenz oder Nachname',
        help_mock_snr: 'Startnummer: 86',
        help_h_tabs: 'Tabs',
        help_p_tabs_html: 'Nach dem Suchen siehst du <b>4 Tabs</b>:',
        help_p_prog_html: '<b>Programm</b> — alle Rennen der Veranstaltung. Deine Rennen sind markiert. Tippe auf ein Rennen für die Startliste.',
        help_p_heats_html: '<b>Heats</b> — deine Heats mit allen Skatern. Deine eigene Zeile ist markiert. Nach dem Finish siehst du Zeiten und Positionen.',
        help_p_res_html: '<b>Resultate</b> — deine persönlichen Ergebnisse pro Distanz und deine Wertung.',
        help_p_uitsl_html: '<b>Ergebnisse</b> — die vollständigen Ergebnisse aller Skater. Wähle eine Kategorie und Distanz, oder sieh die Wertung.',
        help_mock_jouw_naam: 'Dein Name',
        help_h_auto: 'Automatisch aktualisiert',
        help_p_auto_html: 'Die Seite aktualisiert sich jede Minute solange der Tab sichtbar ist. Neben dem Rennnamen siehst du <b>🔄 HH:MM</b> — das ist der Zeitpunkt der letzten Aktualisierung.',
        help_h_meld: 'Bekanntmachungen',
        help_p_meld_html: 'Oben befindet sich ein <b>📢-Button</b> (sichtbar sobald eine aktive Bekanntmachung vorhanden ist). Wichtige Ankündigungen der Organisation erscheinen automatisch als Pop-up und bleiben danach unter diesem Button erreichbar — z.B. "Programm läuft 15 Min hinterher".',
        help_h_tip: 'Tipp',
        help_p_tip: 'Noch keine Ergebnisse? Das Ergebnis erscheint, sobald die Jury es bestätigt hat.',
    },
    fr: {
        // ── Document ──
        page_title: 'InlineComp – Ma course',
        // ── Header / static ──
        ptr_trek: '↓ Tirer plus loin pour actualiser',
        hdr_meldingen_title: 'Annonces pour cette course',
        hdr_info_title: 'À propos d\'InlineComp',
        hdr_help_title: 'Comment ça marche ?',
        hdr_sub: 'Trouve tes séries, horaires et résultats',
        pwa_installeer_titel: 'Installer InlineComp',
        pwa_installeer_uitleg: 'Ajoute à ton écran d\'accueil pour un accès rapide',
        pwa_btn_install: 'Installer',
        pwa_btn_sluit: 'Fermer',
        stap1_label: 'Choisis ta course',
        stap2_label: 'Numéro de dossard, licence ou nom de famille',
        filter_eerder: 'Avant',
        filter_eerder_title: 'Courses précédentes',
        filter_vandaag: 'Aujourd\'hui',
        filter_later: 'Plus tard',
        filter_later_title: 'Courses à venir',
        opt_laden: 'Chargement…',
        zoek_placeholder: 'Numéro de dossard, nº de licence ou nom…',
        btn_zoeken: 'Rechercher',
        // ── Connection banner ──
        conn_geen_internet: '📡 Pas d\'internet — actualisation dès le retour de la connexion',
        conn_server_down: '⚠ Serveur inaccessible — nouvel essai…',
        conn_laatste_update: 'dernière mise à jour {tijd}',
        // ── Comps select ──
        opt_kies_filter: '— Sélectionne au moins un filtre ci-dessus —',
        opt_kies_wedstrijd: '— Choisis une course —',
        opt_binnenkort: '(bientôt)',
        opt_fout_laden: 'Échec du chargement',
        // ── Disclaimer ──
        // ── Zoek / chooser ──
        msg_laden: 'Chargement…',
        msg_zoeken: 'Recherche…',
        msg_zoeken_op: 'Recherche pour "{term}"…',
        msg_rijders_ophalen: 'Récupération des skateurs…',
        msg_je_rijders_ophalen: 'Récupération de tes skateurs…',
        msg_geen_resultaten: 'Aucun résultat trouvé.',
        msg_geen_rijders: 'Aucun skateur trouvé.',
        msg_geen_startlijst: 'Aucune liste de départ disponible pour cette course.',
        msg_geen_klassement: 'Aucun classement disponible.',
        msg_geen_uitslagen: 'Aucun résultat disponible.',
        msg_geen_posities: 'Aucune position dans cette catégorie.',
        msg_kies_categorie_klassement: 'Choisis une catégorie pour voir le classement.',
        msg_programma_nb: 'Programme non disponible.',
        msg_nog_geen_heats: 'Aucune série disponible pour le moment.',
        msg_nog_geen_resultaten: 'Aucun résultat disponible pour le moment.',
        msg_vorige_ronde_nb: 'Tour précédent pas encore terminé — la liste de départ apparaît dès que tous les résultats sont entrés.',
        chooser_titel: 'Résultats de recherche pour "{term}"',
        chooser_sluit: 'Fermer',
        chooser_al_in_lijst: 'déjà dans la liste',
        chooser_doet_niet_mee: 'ne participe pas à cette course',
        chooser_max: 'Max. {max} skateurs · {vrij} place(s) libre(s)',
        chooser_toevoegen: 'Ajouter',
        alert_max_bereikt: 'Maximum de {max} skateurs atteint. Retire d\'abord quelqu\'un pour en ajouter un nouveau.',
        alert_max_select: 'Maximum {max} — il reste de la place pour {vrij}. Tu en as sélectionné {n}.',
        // ── Kind-tabs ──
        kind_rijder_placeholder: '(skateur)',
        kind_tab_verwijder: 'Retirer ce skateur',
        kind_plus_title: 'Ajouter frère/sœur',
        kind_plus_max: 'Maximum {max} skateurs',
        // ── Persoon / status ──
        status_niet_ingeschreven: 'Non inscrit',
        status_0: 'Non confirmé',
        status_1: 'Confirmé',
        status_2: 'Désinscrit',
        status_3: 'Désinsc. à l\'org.',
        status_4: 'Non signé',
        status_5: 'Conf. à l\'org.',
        status_onbekend: '?',
        snr_label: 'Nº',
        auto_stempel_title: 'Heure de la dernière actualisation automatique',
        // ── Tabs ──
        tab_programma: '📅 Programme',
        tab_heats: '🏃 Séries',
        tab_resultaten: '🏆 Résultats',
        tab_uitslagen: '📊 Tous résultats',
        // ── Programma ──
        prog_titel: 'Programme de course',
        prog_combi_kop: '🔗 Course combinée — patinage simultané',
        prog_blok_pauze: 'Pause',
        prog_blok_inrijden: 'Échauffement',
        prog_blok_wedstrijdstart: 'Départ de course',
        prog_blok_ceremonie: 'Cérémonie',
        prog_blok_herstart: 'Redémarrage',
        prog_blok_min: 'min',
        // Multi-day filter
        prog_dag_alle: 'Tous',
        prog_dag: 'Jour',
        // Programma-filter pills
        prog_filter_mijn: '👤 Mes courses',
        prog_filter_te_rijden: '⏳ À venir',
        // ── Heats ──
        heat_wachten_vorige: 'En attente du tour précédent',
        heat_jouw_resultaat: 'Toi :',
        heat_bruto_gemeten: 'mesuré',
        heat_bruto_officieel: 'officiel',
        // ── Heat tabel headers ──
        col_pos: '#',
        col_snr: 'Nº',
        col_naam: 'Nom',
        col_rnd: 'Tour',
        col_pnt: 'Pts',
        col_tijd: 'Temps',
        col_fin: 'Fin',
        col_rang: '#',
        col_cat: 'Cat',
        col_tot: 'Tot',
        // ── Rondes ──
        ronde_serie: 'Série',
        ronde_kf: 'QF',
        ronde_hf: 'DF',
        ronde_finale: 'Finale',
        ronde_b_finale: 'B-Finale',
        ronde_runner_up: 'Repêchage',
        // ── Resultaten ──
        res_uitslagen_titel: 'Résultats par distance',
        res_pt: 'pt',
        res_klassement: 'Classement {dc}',
        res_punten: '{n} points',
        // ── Uitslagen tab ──
        uitsl_titel: 'Résultats complets de cette course',
        uitsl_opt_kies_cat: '— Choisir catégorie —',
        uitsl_opt_kies_afstand: '— Choisir distance —',
        uitsl_klassement_opt: '🏆 Classement',
        // ── Serie-klassement ──
        serie_titel: '🏆 Classement de série',
        serie_opt_kies: '— Choisir un classement de série —',
        serie_opt_alle_cats: '— Toutes catégories —',
        serie_aantal_rijders: '{n} skateurs',
        serie_seizoen_sep: ' — ',
        // ── Errors ──
        err_prefix: 'Erreur : {msg}',
        err_zoeken: 'Erreur de recherche : {msg}',
        // ── PTR ──
        ptr_laat_los: '↑ Relâche pour actualiser',
        ptr_vernieuwen: '⟳ Actualisation…',
        ptr_bijgewerkt: '✓ Mis à jour',
        ptr_fout: '⚠ Erreur d\'actualisation',
        ptr_wachten: '⏳ Patiente ({s}s)',
        // ── Mededelingen ──
        meld_kop: '📢 Annonces',
        meld_tot: ' jusqu\'à ',
        meld_begrepen: '✓ Compris',
        // ── Info modal ──
        info_titel: 'À propos d\'InlineComp',
        info_h1: 'Qu\'est-ce qu\'InlineComp ?',
        info_p1: 'InlineComp est un système de gestion de course pour le patinage de vitesse en ligne, développé pour aider les organisations à gérer les listes de départ, le chronométrage en direct et la publication des résultats.',
        info_p2_html: 'Cette page publique est destinée aux <b>skateurs et spectateurs</b> : cherche ton numéro de dossard et consulte directement tes séries, horaires et résultats.',
        info_h2: 'En développement',
        info_p3: 'InlineComp est en développement actif. Les fonctions peuvent changer et des bugs peuvent encore exister. Les commentaires sont les bienvenus !',
        info_h3_html: 'Contact &amp; commentaires',
        info_p4: 'Une question, suggestion ou bug trouvé ? Fais-le nous savoir :',
        info_h4: 'Statistiques de visite anonymes',
        info_p5_html: 'Nous comptons anonymement le nombre de visiteurs, sessions actives et pics simultanés — uniquement pour voir l\'utilisation de l\'app et garder l\'hébergement stable. <b>Aucune adresse IP ni donnée personnelle</b> n\'est stockée et <b>aucun tiers</b> n\'est impliqué.',
        info_h5_html: 'Vie privée &amp; données personnelles',
        info_p6: 'Cette app affiche des données de course fournies par la KNSB ou d\'autres organisations de course (noms, dossards, club). La déclaration de confidentialité détaille quelles données nous traitons, sur quelle base et comment soumettre une demande de suppression.',
        info_btn_privacy: '📄 Voir la déclaration de confidentialité',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        // ── Help modal ──
        help_titel: 'Comment fonctionne InlineComp ?',
        help_h1: 'Démarrer',
        help_stap1_html: 'Choisis ta <b>course</b> dans la liste. Avec les trois boutons de filtre — <i>Avant</i>, <i>Aujourd\'hui</i> et <i>Plus tard</i> — tu décides quelles courses voir. Par défaut seul <i>Aujourd\'hui</i> est actif ; clique un bouton pour ajuster la plage.',
        help_stap2_html: 'Entre ton <b>numéro de dossard</b> et clique sur <b>Rechercher</b> — ton aperçu personnel apparaît.',
        help_stap3_html: 'Tu veux suivre plusieurs skateurs (par ex. frère, sœur ou coéquipier) ? Clique sur le bouton <b>+</b> en haut. Tu peux suivre jusqu\'à <b>4 skateurs</b> en même temps — change via les onglets en haut avec leurs dossards.',
        help_mock_kies_w: 'Choisis ta course',
        help_mock_voorbeeld: 'Course exemple — 19 avril 2026',
        help_mock_snr_lic: 'Numéro de dossard, licence ou nom',
        help_mock_snr: 'Dossard : 86',
        help_h_tabs: 'Onglets',
        help_p_tabs_html: 'Après la recherche tu vois <b>4 onglets</b> :',
        help_p_prog_html: '<b>Programme</b> — toutes les courses de la rencontre. Tes courses sont surlignées. Tape sur une course pour voir la liste de départ.',
        help_p_heats_html: '<b>Séries</b> — tes séries avec tous les skateurs. Ta propre ligne est surlignée. Après l\'arrivée tu vois les temps et positions.',
        help_p_res_html: '<b>Résultats</b> — tes résultats personnels par distance et ton classement.',
        help_p_uitsl_html: '<b>Tous résultats</b> — les résultats complets de tous les skateurs. Choisis une catégorie et distance, ou consulte le classement.',
        help_mock_jouw_naam: 'Ton nom',
        help_h_auto: 'Mis à jour automatiquement',
        help_p_auto_html: 'La page s\'actualise toutes les minutes tant que l\'onglet est visible. À côté du nom de la course tu vois <b>🔄 HH:MM</b> — c\'est l\'heure de la dernière actualisation.',
        help_h_meld: 'Annonces',
        help_p_meld_html: 'En haut se trouve un <b>bouton 📢</b> (visible dès qu\'une annonce est active). Les annonces importantes de l\'organisation apparaissent automatiquement en pop-up et restent accessibles sous ce bouton — par ex. "Programme avec 15 min de retard".',
        help_h_tip: 'Astuce',
        help_p_tip: 'Pas encore de résultats ? Le résultat apparaît dès que le jury l\'a confirmé.',
    }
};
// Shared i18n-helpers (t, applyI18n, toggleLang, getCurLang, getLocale)
// zijn hierboven al ingeladen via readfile(js/i18n.js). Hier alleen
// app-specifieke wrappers + init.
function getStatusLabel(i) { return t('status_' + i); }

// Helper voor meldingen: pak veld (titel/bericht) in huidige taal, met
// fallback-keten: huidige taal → EN → NL (= origineel). Vertaalde velden
// (titel_en, titel_de, titel_fr) worden door backend gevuld via Claude AI
// bij save. NL-veld (titel, bericht) is altijd verplicht; vertalingen
// optioneel. Voor talen waar de vertaling ontbreekt → val terug op EN
// (de meest universele) en uiteindelijk op NL.
function _meldingTekst(m, veld) {
    const lang = getCurLang();
    const sufLang = veld + '_' + lang;
    const sufEn   = veld + '_en';
    if (lang !== 'nl' && m[sufLang]) return m[sufLang];
    if (lang !== 'nl' && m[sufEn])   return m[sufEn];
    return m[veld] || '';
}

function _rerenderActiveTab() {
    // Comps-dropdown opnieuw vullen (textContent gebruikt vertaalde labels)
    if (typeof filterComps === 'function' && alleComps?.length) filterComps();
    // Multi-rijder view opnieuw renderen
    if (typeof renderKinderen === 'function' && _kinderen?.length) {
        _bewaarKindUistate?.();
        renderKinderen();
    }
    // Connection banner updaten
    if (typeof _connUpdateBanner === 'function') _connUpdateBanner();
    // Stempel-tekst opnieuw zetten (title-attribute)
    document.querySelectorAll('.auto-stempel').forEach(el => {
        el.title = t('auto_stempel_title');
    });
    // Meldingen-badge: alleen een getal, geen vertaling nodig.
    // Maar openstaande melding-overlays bevatten al-gerenderde titel/bericht
    // in de oude taal — die moeten we opnieuw bouwen, anders zie je na
    // NL→EN nog steeds de Nederlandse tekst staan (vooral merkbaar bij
    // globale meldingen die direct bij landing openstaan).
    const popup = document.querySelector('[data-meld-overlay="popup"]');
    if (popup && _huidigeMelding) {
        const m = _huidigeMelding;
        popup.remove();
        _meldingActief = false;
        _huidigeMelding = null;
        toonMelding(m, selComp?.value || '');
    }
    const overz = document.querySelector('[data-meld-overlay="overzicht"]');
    if (overz) {
        overz.remove();
        toonMeldingenOverzicht();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initI18n({ dict: T, onChange: _rerenderActiveTab });
});

const selComp = document.getElementById('sel-comp');
const inpSnr  = document.getElementById('inp-snr');
const btnZoek = document.getElementById('btn-zoek');
const divResult = document.getElementById('resultaat');
const divInfo   = document.getElementById('comp-info');
const chkOud     = document.getElementById('chk-oud');
const chkVandaag = document.getElementById('chk-vandaag');
const chkToekomst = document.getElementById('chk-toekomst');
let alleComps = [];

const STATUS_KLEUR = ['#e65100','#2e7d32','#b71c1c','#6a1b9a','#283593','#006064'];
const STATUS_BG    = ['#fff3e0','#e8f5e9','#fce4e4','#f3e5f5','#e8eaf6','#e0f7fa'];
const BADGE = { heats:'badge-serie', kwartfinale:'badge-kf', halve_finale:'badge-hf',
                finale_a:'badge-finale', finale_b:'badge-finale', runner_up:'badge-ru' };
// Ronde-labels worden runtime vertaald via getRondeLabel(rt).
function getRondeLabel(rt) {
    const map = {
        heats: 'ronde_serie',
        kwartfinale: 'ronde_kf',
        halve_finale: 'ronde_hf',
        finale_a: 'ronde_finale',
        finale_b: 'ronde_b_finale',
        runner_up: 'ronde_runner_up',
    };
    return map[rt] ? t(map[rt]) : (rt || '');
}

function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
// Safari kan geen "2026-04-19 10:00:00" parsen, wel "2026-04-19T10:00:00"
function safeDatum(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }

// ── Verbinding-status: detecteert offline / server-down en toont banner ────
// Wordt door safeFetch hieronder bijgewerkt: succes → groen/verborgen,
// fout → banner met passende tekst. window 'online'-event triggert direct
// een refresh; visibilitychange (visible) triggert ook een refresh.
const _conn = {
    online: navigator.onLine,         // browser-niveau
    serverOk: true,                   // laatste API-call succesvol?
    lastSuccess: null,                // Date van laatste OK-fetch
    consecutiveFails: 0,              // voor exponentiële backoff
    refreshHook: null,                // zetten door refresh-functie
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
        bericht = t('conn_geen_internet');
    } else if (!_conn.serverOk) {
        bericht = t('conn_server_down');
    }
    if (bericht) {
        const tijd = _conn.lastSuccess
            ? ` <small style="opacity:.85">(${t('conn_laatste_update', {tijd: _conn.lastSuccess.toLocaleTimeString(getLocale(), {hour:'2-digit', minute:'2-digit'})})})</small>`
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
    // Binnen grace-periode na een fout: server-vlag pas herstellen als de
    // grace voorbij is. navigator-online vlag (echte OS-status) volgt wél
    // direct het laatste signaal.
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
    // Plan een banner-recheck na de grace-periode zodat 'ie automatisch
    // verdwijnt als er ondertussen geen nieuwe fouten meer komen.
    setTimeout(() => {
        if (_conn.lastFailureMs && (Date.now() - _conn.lastFailureMs) >= _CONN_GRACE_MS) {
            // Geen recente fouten meer — verifieer met een laatste lookup
            _conn.serverOk = true;
            _connUpdateBanner();
        }
    }, _CONN_GRACE_MS + 100);
}

// Triggers voor automatisch herstel
window.addEventListener('online', () => {
    _conn.online = true;
    _connUpdateBanner();
    if (typeof _conn.refreshHook === 'function') _conn.refreshHook();
});
window.addEventListener('offline', () => {
    _conn.online = false;
    _connUpdateBanner();
});

// Fetch met retry bij 429 + verbinding-status-tracking. Bij netwerkfout
// (TypeError 'Failed to fetch') of 5xx: banner aan + niet retry'en
// (auto-refresh-tick probeert vanzelf opnieuw met exponentiële backoff).
// Retry-strategie: max 1× opnieuw bij 429 met random jitter (2-5 s) zodat
// honderden publieke bezoekers niet synchroon weer aankloppen en de
// rate-limit nog erger maken.
async function safeFetch(url, maxRetries = 1) {
    try {
        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            const res = await fetch(url);
            if (res.status === 429 && attempt < maxRetries) {
                const wait = 2000 + Math.random() * 3000;
                await new Promise(r => setTimeout(r, wait));
                continue;
            }
            if (res.status >= 500) {
                _connFail('server');
                return res;
            }
            if (res.status === 429) {
                _connFail('server');
                return res;
            }
            _connOk();
            return res;
        }
        return new Response(null, { status: 504 });
    } catch (e) {
        // TypeError = netwerkfout (geen verbinding, DNS, etc.)
        _connFail('network');
        throw e;
    }
}
// Detecteer extra kolommen voor heat-rijders
function heatExtraKolommen(rijders, rondeType) {
    const heeftRnd = rijders.some(r => r.rondes != null);
    const heeftPK  = rijders.some(r => r.pk_punten != null);
    return { heeftRnd, heeftPK, rondeType: rondeType ?? null };
}
function heatTabelHeader(extra) {
    // Volgorde: pos, snr, FIN (direct na snr, rood weergegeven in CSS),
    // naam, ... De finishpositie was vroeger helemaal rechts en viel
    // weg in het oog; door 'm naast snr te zetten + rood te kleuren is
    // het meteen duidelijk hoe een rijder eindigde in deze heat.
    return `<tr><th class="col-pos">${t('col_pos')}</th><th class="col-snr">${t('col_snr')}</th><th class="col-fin">${t('col_fin')}</th><th class="col-naam">${t('col_naam')}</th>`
        + (extra.heeftRnd ? `<th class="col-rnd">${t('col_rnd')}</th>` : '')
        + (extra.heeftPK  ? `<th class="col-pk">${t('col_pnt')}</th>` : '')
        + `<th class="col-tijd">${t('col_tijd')}</th></tr>`;
}
function heatTabelRij(r, isIk, extra) {
    const rTijd = r.tijd_ms != null ? msTijd(r.tijd_ms) : '';
    // Fin-kolom: ALTIJD heat-lokale finishpositie, voor élke ronde-type
    // (Series, KF, HF, Runner-up, A-finale, B-finale). Wat er IN deze heat
    // gebeurde, geen globale qualifying-rang. Voor cross-heat positionering
    // is er de Uitslag-tab.
    const rFin = r.finishpositie != null ? r.finishpositie : '';
    const rSanctie = sl(r.sanctie);
    // Bruto-audit-icoon (📷 fotofinish-wisseling, ✋ handmatige correctie):
    // toon vóór de tijd zodat de cijfers rechts-uitgelijnd blijven staan.
    // Tooltip bevat de gemeten tijd zodat coach/publiek 'm kan opvragen
    // zonder dat de tabel breder wordt.
    const heeftAudit = r.bruto_tijd_ms != null
                    && r.tijd_ms      != null
                    && r.bruto_tijd_ms !== r.tijd_ms;
    // == 1 noodzakelijk: PDO levert is_photofinish soms als string "0"/"1",
    // en "0" is truthy in JS → ternary zou altijd 📷 kiezen voor handmatige
    // RR-tijden. Loose-equality werkt cross-type ("1"==1 ✓, "0"==1 ✗).
    const auditIcon = heeftAudit
        ? `<span class="col-tijd-audit" title="${esc(t('heat_bruto_gemeten'))} ${esc(msTijd(r.bruto_tijd_ms))}">${r.is_photofinish == 1 ? '📷' : '✋'}</span>`
        : '';
    return `<tr class="${isIk ? 'rij-ik' : ''}">
        <td class="col-pos">${r.startpositie}</td>
        <td class="col-snr">${esc(r.snr)}</td>
        <td class="col-fin">${esc(rFin)}</td>
        <td class="col-naam">${esc(r.full_name)}${rSanctie ? ` <span class="col-sanctie">${esc(rSanctie)}</span>` : ''}</td>`
        + (extra.heeftRnd ? `<td class="col-rnd">${r.rondes ?? ''}</td>` : '')
        + (extra.heeftPK  ? `<td class="col-pk">${r.pk_punten != null ? parseFloat(r.pk_punten) : ''}</td>` : '')
        + `<td class="col-tijd">${auditIcon}${esc(rTijd)}</td>
    </tr>`;
}
function msTijd(ms) {
    // Inline-skeeleren: reglementair duizendsten op alle afstanden.
    if (ms==null) return '';
    const d=ms%1000, s=Math.floor(ms/1000)%60, m=Math.floor(ms/60000);
    return m>0?`${m}:${String(s).padStart(2,'0')}.${String(d).padStart(3,'0')}`:`${s}.${String(d).padStart(3,'0')}`;
}
function sl(s) { return s ?? ''; }

// ── Multi-day programma-filter (Alle / Dag 1 / Dag 2 / …) ─────────────────
// Elk programma-item heeft data-dag-nr; we togglen .verborgen op items met
// een andere dag dan de geselecteerde. CSS verbergt die. Bij "Alle" alle
// .verborgen weghalen. Geen re-render nodig.
// Programma-rit-filter: toggle "alleen mijn ritten" of "alleen nog te
// rijden" via data-attributen op de tab-content. Onafhankelijk van elkaar
// te combineren (beide aan = alleen mijn nog-te-rijden ritten).
function filterProgRit(btn, filter) {
    const tab = btn.closest('.tab-content');
    if (!tab) return;
    const attr = filter === 'mijn' ? 'data-filter-mijn' : 'data-filter-gereden-uit';
    const actief = tab.getAttribute(attr) !== '1';
    tab.setAttribute(attr, actief ? '1' : '0');
    btn.classList.toggle('actief', actief);
    // Touch-fix: na een tap blijft :hover op mobiel hangen tot je ergens
    // anders tikt. Blur direct zodat de knop in z'n juiste rust- of
    // actief-state komt zonder lingering lichtblauwe hover.
    btn.blur();
}

function filterDag(btn, dag) {
    const balk = btn.closest('.prog-dag-filter');
    if (!balk) return;
    balk.setAttribute('data-actieve-dag', String(dag));
    balk.querySelectorAll('.prog-dag-btn').forEach(b =>
        b.classList.toggle('actief', b.dataset.dag === String(dag))
    );
    // Filter alle items in dezelfde programma-tab (zelfde parent als de balk).
    const container = balk.parentElement;
    if (!container) return;
    container.querySelectorAll('[data-dag-nr]').forEach(el => {
        if (el === balk || el.classList.contains('prog-dag-filter')) return;
        if (dag === 'alle') {
            el.classList.remove('verborgen');
        } else {
            el.classList.toggle('verborgen', el.getAttribute('data-dag-nr') !== String(dag));
        }
    });
}

// ── Rit-detail overlay ────────────────────────────────────────────────────────
async function toonRitDetail(el) {
    const ritNaam = el.dataset.ritNaam;
    const dcNaam = el.dataset.dcNaam;
    const compId = selComp.value;
    const snr = inpSnr.value.trim();
    // License_key van het actieve kind/rijder voor row-highlight in heat-tabel.
    // snr alleen zou bij twee rijders met zelfde nr beide rijen highlighten.
    const actiefKind = (typeof _kinderen !== 'undefined') ? _kinderen[_activeKindIdx] : null;
    const actiefLic = actiefKind?.data?.[actiefKind?.kozen_idx ?? 0]?.persoon?.license_key || null;
    if (!ritNaam || !compId) return;

    // Overlay aanmaken
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = `<div class="overlay-box"><div style="padding:24px;text-align:center"><span class="spinner"></span> ${t('msg_laden')}</div></div>`;
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);

    try {
        const res = await safeFetch(`?action=rit_detail&competition_id=${encodeURIComponent(compId)}&rit_naam=${encodeURIComponent(ritNaam)}&dc_naam=${encodeURIComponent(dcNaam)}`);
        const data = await res.json();

        if (!data.heat || !data.heat.rijders?.length) {
            overlay.querySelector('.overlay-box').innerHTML = `
                <div class="heat-card-titel"><button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>${esc(ritNaam)}</div>
                <div style="padding:20px;text-align:center;color:#888">${t('msg_geen_startlijst')}</div>`;
            return;
        }

        const h = data.heat;
        const rt = h.ronde_type ?? 'heats';
        const extra = heatExtraKolommen(h.rijders ?? [], rt);
        let rows = '';
        for (const r of h.rijders) {
            // Match op license_key (uniek), fallback op snr voor backwards-
            // compat met oude cached payloads die nog geen license meegeven.
            const isHuidig = (actiefLic && r.license_key)
                ? r.license_key === actiefLic
                : String(r.snr) === snr;
            rows += heatTabelRij(r, isHuidig, extra);
        }

        overlay.querySelector('.overlay-box').innerHTML = `
            <div class="heat-card" style="border:none;border-radius:12px">
                <div class="heat-card-titel" style="border-radius:12px 12px 0 0">
                    <button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>
                    <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                    ${esc(h.rit_naam ?? h.heat_naam)}
                </div>
                <table class="heat-card-tabel">
                <thead>${heatTabelHeader(extra)}</thead>
                <tbody>${rows}</tbody>
                </table>
            </div>`;
    } catch (e) {
        overlay.querySelector('.overlay-box').innerHTML = `<div style="padding:20px;color:#c00">${esc(t('err_prefix', {msg: e.message}))}</div>`;
    }
}

// Wedstrijden laden + filteren
// Drie onafhankelijke filters: Oude · Vandaag · Toekomstige. Wedstrijd
// verschijnt als hij in tenminste één van de aangevinkte categorieën valt.
// Geen filter aangevinkt → lege lijst met helder bericht.
function filterComps() {
    const nu = new Date();
    const gisteren = new Date(nu); gisteren.setDate(gisteren.getDate() - 1); gisteren.setHours(0,0,0,0);
    const morgen   = new Date(nu); morgen.setDate(morgen.getDate() + 1);   morgen.setHours(23,59,59,999);

    const toonOud      = chkOud.checked;
    const toonVandaag  = chkVandaag.checked;
    const toonToekomst = chkToekomst.checked;
    const vorigeWaarde = selComp.value;

    if (!toonOud && !toonVandaag && !toonToekomst) {
        selComp.innerHTML = `<option value="">${esc(t('opt_kies_filter'))}</option>`;
        return;
    }

    selComp.innerHTML = `<option value="">${esc(t('opt_kies_wedstrijd'))}</option>`;
    for (const c of alleComps) {
        const startDag = safeDatum(c.starts);
        const eindDag  = safeDatum(c.ends) ?? startDag;

        // Categoriseer: een wedstrijd is óf vandaag (overlapt met gisteren-morgen),
        // óf oud (afgelopen vóór gisteren), óf toekomstig (begint ná morgen).
        const isVandaag  = startDag && startDag <= morgen && eindDag >= gisteren;
        const isOud      = !isVandaag && eindDag   && eindDag   < gisteren;
        const isToekomst = !isVandaag && startDag && startDag > morgen;

        // Tonen als bijbehorend filter aan staat
        if (isVandaag  && !toonVandaag)  continue;
        if (isOud      && !toonOud)      continue;
        if (isToekomst && !toonToekomst) continue;

        const d = startDag ? startDag.toLocaleDateString(getLocale(),{day:'numeric',month:'long',year:'numeric'}) : '';
        // Verborgen wedstrijden: tonen als disabled met "(binnenkort)"
        // suffix — bezoeker ziet dat de wedstrijd er aankomt zonder
        // erop te kunnen klikken. Operator publiceert via Beheer.
        const verborgen = !Number(c.public_zichtbaar);
        const o = document.createElement('option');
        o.value = c.id;
        o.textContent = `${c.name} — ${d}${verborgen ? '  ' + t('opt_binnenkort') : ''}`;
        if (verborgen) o.disabled = true;
        o.dataset.datum = d; o.dataset.naam = c.name;
        o.dataset.orgLogo = c.org_logo ?? '';
        o.dataset.orgNaam = c.org_naam ?? '';
        o.dataset.baanLogo = c.baan_logo ?? '';
        o.dataset.baanVereniging = c.baan_vereniging ?? '';
        o.dataset.sponsors = JSON.stringify(c.sponsors ?? []);
        selComp.appendChild(o);
    }

    // Herstel selectie als die nog in de lijst zit en niet (inmiddels) disabled.
    const vorigeOpt = vorigeWaarde
        ? selComp.querySelector(`option[value="${vorigeWaarde}"]`)
        : null;
    if (vorigeOpt && !vorigeOpt.disabled) {
        selComp.value = vorigeWaarde;
    } else {
        // Auto-selecteer als er maar 1 selecteerbare wedstrijd is —
        // disabled ('binnenkort') tellen niet mee, anders zou de
        // gebruiker bij stappen verder pas een 'niet beschikbaar'
        // foutmelding krijgen.
        const opties = selComp.querySelectorAll('option[value]:not([value=""]):not([disabled])');
        if (opties.length === 1) { selComp.value = opties[0].value; selComp.dispatchEvent(new Event('change')); }
    }
}

chkOud.addEventListener('change', filterComps);
chkVandaag.addEventListener('change', filterComps);
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
}).catch(() => { selComp.innerHTML = `<option value="">${esc(t('opt_fout_laden'))}</option>`; });

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
    divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_je_rijders_ophalen')}</div>`;
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

    divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_zoeken')}</div>`;
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
        if (!data.length) { divResult.innerHTML = `<div class="melding">${esc(t('msg_geen_resultaten'))}</div>`; return; }

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
        divResult.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
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
                <span>${esc(t('chooser_titel', {term}))}</span>
                <button class="naamzoek-sluit" title="${esc(t('chooser_sluit'))}">&times;</button>
            </div>
            <div class="naamzoek-body">
                ${rijen.length === 0
                    ? `<div class="naamzoek-leeg">${esc(t('msg_geen_rijders'))}</div>`
                    : rijen.map(r => {
                        const uit = al.has(r.license_key);
                        // search_person geeft `in_wedstrijd` (1/0); snr-pad niet,
                        // dan behandelen we als altijd-wel (undefined === wel).
                        const doetMee = r.in_wedstrijd === undefined ? true : !!parseInt(r.in_wedstrijd);
                        const meta = [
                            r.category || '',
                            r.club_short ? esc(r.club_short) : '',
                            uit ? `<span style="color:#999">${esc(t('chooser_al_in_lijst'))}</span>` : '',
                            !doetMee ? `<span style="color:#b71c1c">${esc(t('chooser_doet_niet_mee'))}</span>` : '',
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
                <span class="aantal">${esc(t('chooser_max', {max: MAX_KINDEREN, vrij: plaatsVrij}))}</span>
                <div>
                    <button class="btn-zoek" style="padding:8px 18px;margin:0" id="naamzoek-ok">${esc(t('chooser_toevoegen'))}</button>
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
            alert(t('alert_max_select', {max: MAX_KINDEREN, vrij: plaatsVrij, n: vinkjes.length}));
            return;
        }
        sluit();
        divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_rijders_ophalen')}</div>`;
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
    divResult.innerHTML = `<div class="melding"><span class="spinner"></span> ${esc(t('msg_zoeken_op', {term: esc(term)}))}</div>`;
    btnZoek.disabled = true;
    let rijen = [];
    try {
        const res = await safeFetch(`?action=search_person&competition_id=${encodeURIComponent(compId)}&q=${encodeURIComponent(term)}`);
        rijen = await res.json();
        if (!Array.isArray(rijen)) rijen = [];
    } catch (e) {
        divResult.innerHTML = `<div class="melding melding-fout">${esc(t('err_zoeken', {msg: e.message}))}</div>`;
        btnZoek.disabled = false;
        return;
    } finally { btnZoek.disabled = false; }
    toonChooserModal(rijen, term, compId);
}

// refreshRijder() is verwijderd — was gekoppeld aan het ↻-knopje dat door
// de auto-refresh + ↻-stempel-indicator overbodig is geworden.

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
            alert(t('alert_max_bereikt', {max: MAX_KINDEREN}));
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
    const tabsHtml = _kinderen.map((k, idx) => {
        const p = k.data[k.kozen_idx ?? 0]?.persoon;
        const naam = p?.full_name ? p.full_name.split(' ')[0] : ''; // alleen voornaam in tab — kort
        const actief = idx === _activeKindIdx ? ' active' : '';
        // ×-knop altijd beschikbaar — ook bij 1 kind handig (je hebt misschien
        // per ongeluk de verkeerde rijder geselecteerd). verwijderKind()
        // ruimt bij laatste-kind de view netjes op.
        const closeBtn = `<span class="kind-tab-close" data-kind-close="${idx}" title="${esc(t('kind_tab_verwijder'))}">×</span>`;
        return `<button class="kind-tab${actief}" data-kind-idx="${idx}">
            <span class="kind-tab-snr">${esc(k.snr)}</span>
            <span>${esc(naam || t('kind_rijder_placeholder'))}</span>
            ${closeBtn}
        </button>`;
    }).join('');
    // Bij 3+ kinderen wordt het tabblad krap op telefoon-breedte. CSS
    // gebruikt data-count om dan compactere stijl toe te passen (voornaam
    // weg, kleinere padding) — de × moet altijd zichtbaar blijven.
    const plusKnop = _kinderen.length < MAX_KINDEREN
        ? `<button class="kind-tab-plus" id="kind-tab-plus" title="${esc(t('kind_plus_title'))}">+</button>`
        : `<button class="kind-tab-plus" disabled title="${esc(t('kind_plus_max', {max: MAX_KINDEREN}))}">+</button>`;

    divResult.innerHTML = `
        <div class="kind-tabs" data-count="${_kinderen.length}">${tabsHtml}${plusKnop}</div>
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

// Bewaar UI-state (huidige sub-tab + dropdown-keuzes binnen Uitslagen) van
// het actief getoonde kind. Wordt aangeroepen vóór elk renderKinderen() zodat
// na her-render de juiste keuzes hersteld kunnen worden. Zonder dit verloor
// de gebruiker elke 60s (auto-refresh) zijn categorie/afstand-selectie in de
// Uitslagen-tab — _kaartwissel_ deed hetzelfde. Restore gebeurt in
// initUitslagenTab() / het serie-klassement-blok.
function _bewaarKindUistate() {
    const k = _kinderen[_activeKindIdx];
    if (!k) return;
    const kc = document.getElementById('kind-content');
    if (!kc) return;
    // Sub-tab (welke tab is op dit moment open?)
    const huidigeSub = kc.querySelector('.tab-btn.active')?.dataset.tab;
    if (huidigeSub) k.sub_tab = huidigeSub;
    // Dropdown-keuzes binnen Uitslagen-tab
    const uitslPane = kc.querySelector('.tab-content[data-tab="uitslagen"]');
    if (uitslPane) {
        k._uistate = {
            catVal:      uitslPane.querySelector('.uitsl-cat-sel')?.value      || '',
            distVal:     uitslPane.querySelector('.uitsl-dist-sel')?.value     || '',
            serieVal:    uitslPane.querySelector('.serie-sel')?.value          || '',
            serieCatVal: uitslPane.querySelector('.serie-cat-sel')?.value      || '',
        };
    }
}

function wisselKind(idx) {
    if (idx < 0 || idx >= _kinderen.length) return;
    // Huidige sub-tab + dropdown-keuzes onthouden voordat we wisselen
    _bewaarKindUistate();
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
            const stLabel = nietIngeschreven ? t('status_niet_ingeschreven') : (st >= 0 && st <= 5 ? getStatusLabel(st) : t('status_onbekend'));
            const stKleur = nietIngeschreven ? '#b71c1c' : (STATUS_KLEUR[st] ?? '#555');
            const stBg    = nietIngeschreven ? '#fce4e4' : (STATUS_BG[st] ?? '#eee');

            html += `
            <div style="margin-top:16px">
                <div class="persoon-header">
                    <div><div class="persoon-naam">${esc(p.full_name)}</div>
                         <span class="persoon-snr">${esc(t('snr_label'))} ${esc(p.wedstrijd_snr??p.start_number)}</span>
                         <span style="font-size:.75rem;background:${stBg};color:${stKleur};border-radius:10px;padding:1px 8px;margin-left:6px">${esc(stLabel)}</span></div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="persoon-cat">${esc(p.category)}</span>
                        <span class="auto-stempel" title="${esc(t('auto_stempel_title'))}">${_huidigStempel}</span>
                    </div>
                </div>
                <div class="tabs">
                    <button class="tab-btn active" data-tab="programma">${esc(t('tab_programma'))}</button>
                    <button class="tab-btn" data-tab="heats">${esc(t('tab_heats'))}</button>
                    <button class="tab-btn" data-tab="resultaten">${esc(t('tab_resultaten'))}</button>
                    <button class="tab-btn" data-tab="uitslagen">${esc(t('tab_uitslagen'))}</button>
                </div>
                <div class="kaart">`;

            // ── TAB: Programma ────────────────────────────────────────
            html += '<div class="tab-content active" data-tab="programma"><div class="kaart-sectie">';
            html += `<div class="kaart-sectie-titel">${esc(t('prog_titel'))}</div>`;
            if (prog.ritten?.length) {
                // Interleave ritten en niet-ronde blokken (pauze, inrijden,
                // wedstrijdstart, ceremonie, herstart).
                //
                // KRITIEKE FIX (was: lexicale sort-bug): PDO returnt SMALLINT
                // velden als JS-strings ("10", "100", "20"). Zonder parseInt
                // werd `"100" <= "20"` lexicaal vergeleken (true!) waardoor
                // wedstrijdstart-dag-2 (volgorde="100") op verkeerde plek
                // tussen dag-1-ritten verscheen. Admin (tijdschema.js) doet
                // overal parseInt — public/coach hadden die niet.
                const num       = v => parseInt(v) || 0;
                const items     = [];
                const sortedBlk = (prog.blokken || []).slice()
                    .sort((a, b) => num(a.volgorde) - num(b.volgorde));
                let blkIdx = 0;
                for (const r of (prog.ritten || [])) {
                    const rBV = num(r.blok_volgorde);
                    while (blkIdx < sortedBlk.length
                           && num(sortedBlk[blkIdx].volgorde) <= rBV) {
                        items.push({ type:'blok', data: sortedBlk[blkIdx++] });
                    }
                    items.push({ type:'rit', data: r });
                }
                while (blkIdx < sortedBlk.length) {
                    items.push({ type:'blok', data: sortedBlk[blkIdx++] });
                }

                // Multi-day setup: meerdere wedstrijdstart-blokken → toon één
                // header per dag (Dag N — Zaterdag 28 mei) op de plek waar de
                // dag-cluster begint, niet alleen vóór de wedstrijdstart zelf.
                // Reden: inrijden+pauze direct vóór een wedstrijdstart horen
                // BIJ die nieuwe dag (warm-up voor de dag), niet bij de vorige.
                const wsBlokken = (prog.blokken || [])
                    .filter(b => (b.blok_type || '').toLowerCase() === 'wedstrijdstart')
                    .sort((a, b) => num(a.volgorde) - num(b.volgorde));
                const isMultiDag = wsBlokken.length > 1;
                // dagInfoPerNr: dagNr → { datumLbl (lang, voor header), kortLbl (knop) }
                // Beide locale-aware via getLocale() — Engels op een EN-instelling
                // gebruikt "Fri 28/5", Duits "Fr. 28.5.", Frans "ven. 28/5", etc.
                const dagInfoPerNr = new Map();
                const _locale = (typeof getLocale === 'function') ? getLocale() : 'nl-NL';
                wsBlokken.forEach((ws, i) => {
                    let datumLbl = '', kortLbl = '';
                    if (ws.datum) {
                        const d = new Date(ws.datum + 'T00:00:00');
                        if (!isNaN(d)) {
                            datumLbl = d.toLocaleDateString(_locale,
                                {weekday:'long', day:'numeric', month:'long'});
                            kortLbl  = d.toLocaleDateString(_locale,
                                {weekday:'short', day:'numeric', month:'numeric'});
                        }
                    }
                    dagInfoPerNr.set(i + 1, { datumLbl, kortLbl });
                });

                // Dag-toewijzing per item via twee passes:
                //   1. FORWARD: wedstrijdstart-items zetten huidige dag; ritten
                //      erven die. Andere blokken krijgen tentative natural-dag.
                //   2. BACKWARD: inrijd/pauze/etc. die NA hun natural-dag een
                //      opvolgende wedstrijdstart/rit met hogere dag hebben,
                //      claimen die hogere dag (= warm-up voor volgende dag).
                const dagPerItem = new Array(items.length);
                let huidigeDag = 0;
                items.forEach((it, idx) => {
                    if (it.type === 'blok'
                        && (it.data.blok_type || '').toLowerCase() === 'wedstrijdstart') {
                        const wsIdx = wsBlokken.findIndex(w => String(w.id) === String(it.data.id));
                        if (wsIdx >= 0) huidigeDag = wsIdx + 1;
                    }
                    dagPerItem[idx] = huidigeDag || 1; // pre-dag-1 items → tentative 1
                });
                // Alleen inrijden + pauze "horen bij de volgende dag" als ze
                // vóór een wedstrijdstart liggen — warm-up + baan-voorbereiding
                // zijn altijd vóór de start. Ceremonie (= afsluiting) blijft bij
                // de vorige dag, en blokkeert dan ook de claim-keten zodat
                // eerder-gelegen pauzes niet langs de ceremonie heen claimen.
                let komendeDag = null;
                for (let idx = items.length - 1; idx >= 0; idx--) {
                    const it = items[idx];
                    const bt = it.type === 'blok'
                        ? (it.data.blok_type || '').toLowerCase() : '';
                    const isWs = bt === 'wedstrijdstart';
                    if (it.type === 'rit' || isWs) {
                        komendeDag = dagPerItem[idx];
                    } else if (it.type === 'blok') {
                        const isWarmUp = (bt === 'inrijden' || bt === 'pauze');
                        if (isWarmUp && komendeDag
                            && dagPerItem[idx] < komendeDag) {
                            dagPerItem[idx] = komendeDag;
                        } else if (!isWarmUp) {
                            // ceremonie / herstart: keten breken
                            komendeDag = dagPerItem[idx];
                        }
                    }
                }

                const hhmm = v => { if (!v) return ''; const m = String(v).match(/(\d{1,2}:\d{2})/); return m ? m[1] : ''; };
                const blokIcoon = bt => ({pauze:'⏸',inrijden:'🛼',wedstrijdstart:'🏁',ceremonie:'🏆',herstart:'🔄'}[bt] || '🕓');
                const blokLabel = bt => {
                    const keyMap = {pauze:'prog_blok_pauze', inrijden:'prog_blok_inrijden', wedstrijdstart:'prog_blok_wedstrijdstart', ceremonie:'prog_blok_ceremonie', herstart:'prog_blok_herstart'};
                    return keyMap[bt] ? t(keyMap[bt]) : (bt || '').toUpperCase();
                };

                // Filter-balk bovenaan bij multi-day: "Alle / Dag 1 / Dag 2 / …".
                // Bij dag-3+ wedstrijden wil je niet eindeloos scrollen om dag 3
                // te vinden. JS togglet .verborgen via data-dag-nr (zie onClick).
                if (isMultiDag) {
                    html += `<div class="prog-dag-filter" id="prog-dag-filter" data-actieve-dag="alle">
                        <button class="prog-dag-btn actief" data-dag="alle"
                                onclick="filterDag(this,'alle')">${esc(t('prog_dag_alle'))}</button>`;
                    for (let dn = 1; dn <= wsBlokken.length; dn++) {
                        // Knop: "Dag N" + korte datum onder (sub-label) zodat
                        // mobiel-gebruikers ook zonder hover de datum zien.
                        const info     = dagInfoPerNr.get(dn);
                        const subDatum = info?.kortLbl
                            ? `<span class="prog-dag-btn-datum">${esc(info.kortLbl)}</span>`
                            : '';
                        html += `<button class="prog-dag-btn" data-dag="${dn}"
                                         onclick="filterDag(this,'${dn}')"
                                         title="${esc(info?.datumLbl || '')}"
                            >${esc(t('prog_dag'))} ${dn}${subDatum}</button>`;
                    }
                    html += `</div>`;
                }

                // Filter-pills: "Alleen mijn ritten" + "Alleen nog te rijden".
                // Toggle data-filter-mijn / data-filter-gereden-uit op de
                // tab-content container; CSS verbergt non-matchende rij-en.
                html += `<div class="prog-rit-filter">
                    <button type="button" class="prog-rit-btn" data-filter="mijn"
                            onclick="filterProgRit(this,'mijn')">${esc(t('prog_filter_mijn'))}</button>
                    <button type="button" class="prog-rit-btn" data-filter="te-rijden"
                            onclick="filterProgRit(this,'te-rijden')">${esc(t('prog_filter_te_rijden'))}</button>
                </div>`;

                let nr = 0;
                let vorigeDag = null;
                // Combi-state: ritten met dezelfde combi_group worden samen
                // in één kader getoond. Bij wissel van groep sluiten we de
                // oude box af en openen eventueel een nieuwe.
                let vorigeCombi = null;
                items.forEach((it, idx) => {
                    const dag = dagPerItem[idx];
                    // Dag-header bij wisseling (multi-day). Sluit eerst open
                    // combi-box als die er was — een combi mag niet over een
                    // dag-grens lopen in de render.
                    if (isMultiDag && dag !== vorigeDag) {
                        if (vorigeCombi !== null) { html += `</div></div>`; vorigeCombi = null; }
                        const info = dagInfoPerNr.get(dag);
                        const lbl = info?.datumLbl
                            ? `Dag ${dag} — ${info.datumLbl}`
                            : `Dag ${dag}`;
                        html += `<div class="prog-dag-header" data-dag-nr="${dag}">${esc(lbl)}</div>`;
                        vorigeDag = dag;
                    }

                    if (it.type === 'blok') {
                        if (vorigeCombi !== null) { html += `</div></div>`; vorigeCombi = null; }
                        const b = it.data;
                        const bt = (b.blok_type || '').toLowerCase();
                        const tijd = hhmm(b.tijdstip);
                        const tijdHtml = tijd ? `<span class="prog-blok-tijd">🕓 ${esc(tijd)}</span>` : '';
                        const duurHtml = b.duur ? `<span class="prog-blok-duur">${b.duur} ${t('prog_blok_min')}</span>` : '';
                        const opmHtml  = b.opmerking ? `<span class="prog-blok-opm"> — ${esc(b.opmerking)}</span>` : '';
                        const catsHtml = b.inrijd_cat_namen ? `<div class="prog-blok-cats">${esc(b.inrijd_cat_namen)}</div>` : '';
                        html += `<div class="prog-blok-rij prog-blok-${esc(bt)}" data-dag-nr="${dag}">
                            <div class="prog-blok-top">
                                ${tijdHtml}
                                <span class="prog-blok-titel">${blokIcoon(bt)} ${esc(blokLabel(bt))}</span>
                                ${duurHtml}
                                ${opmHtml}
                            </div>
                            ${catsHtml}
                        </div>`;
                        return;
                    }
                    const rit = it.data;
                    nr++;
                    const combi = rit.combi_group ? parseInt(rit.combi_group) : null;
                    if (combi !== vorigeCombi) {
                        if (vorigeCombi !== null) html += `</div></div>`; // sluit vorige combi-box
                        if (combi !== null) {
                            html += `<div class="prog-combi-box" data-dag-nr="${dag}">
                                <div class="prog-combi-kop">${esc(t('prog_combi_kop'))}</div>
                                <div class="prog-combi-leden">`;
                        }
                    }
                    vorigeCombi = combi;

                    // Highlight als deze rijder in deze rit zit
                    const isInRit = r.heats.some(h => h.rit_naam === rit.rit_naam);
                    const rt = rit.ronde_type ?? 'heats';
                    const gereden = rit.resultaten_count > 0;
                    const statusIcon = gereden ? '🏁'
                                     : rit.definitief ? '🚩'
                                     : '';
                    const opmHtml = rit.rit_opmerking
                        ? `<div class="prog-rit-opm">📝 ${esc(rit.rit_opmerking)}</div>` : '';
                    // Classes voor filter-pills: prog-rij-mijn (= rijder zit
                    // in rit) en prog-rij-gereden (= rit heeft uitslagen).
                    const filterCls = (isInRit ? ' prog-rij-mijn' : '')
                                    + (gereden ? ' prog-rij-gereden' : '');
                    html += `<div class="prog-rij${combi !== null ? ' prog-rij-combi' : ''}${filterCls}" style="${isInRit ? 'background:#fffbe6;font-weight:600;margin:0 -16px;padding:6px 16px;border-radius:4px' : ''};cursor:pointer"
                                 data-rit-naam="${esc(rit.rit_naam)}" data-dc-naam="${esc(rit.dc_naam)}"
                                 data-dag-nr="${dag}" onclick="toonRitDetail(this)">
                        <span class="prog-nr">${statusIcon} ${nr}</span>
                        <span class="prog-naam">${esc(rit.rit_naam)}${opmHtml}</span>
                        <span class="prog-type heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                    </div>`;
                });
                // Sluit eventuele laatste open combi-box
                if (vorigeCombi !== null) html += `</div></div>`;
            } else {
                html += `<div class="melding">${esc(t('msg_programma_nb'))}</div>`;
            }
            html += '</div></div>';

            // ── TAB: Heats (heat-cards) ──────────────────────────────
            html += '<div class="tab-content" data-tab="heats">';
            if (r.heats.length) {
                for (const h of r.heats) {
                    const rt = h.ronde_type ?? 'heats';
                    const naam = h.rit_naam ?? h.heat_naam ?? '';

                    // Vorige ronde nog niet compleet → placeholder ipv heat-card.
                    // Backend zet deze flag voor KF/HF/Finale/Runner-up als de
                    // bron-ronde nog niet helemaal verwerkt is.
                    if (h.vorige_niet_compleet) {
                        html += `<div class="heat-card heat-card-pending">
                            <div class="heat-card-titel">
                                <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                                <span style="flex:1">${esc(naam)}</span>
                                <span style="font-size:1rem" title="${esc(t('heat_wachten_vorige'))}">⏳</span>
                            </div>
                            <div style="padding:.6rem .8rem;color:#666;font-style:italic;font-size:.85rem">
                                ${esc(t('msg_vorige_ronde_nb'))}
                            </div>
                        </div>`;
                        continue;
                    }

                    const mijnTijd = h.tijd_ms != null ? msTijd(h.tijd_ms) : '';
                    const mijnPos = h.finishpositie != null ? '#' + h.finishpositie : '';
                    const mijnSanctie = sl(h.sanctie);
                    // Audit-spoor: bruto verschilt van officieel → toon
                    // beide (gemeten + officieel) met 📷 (fotofinish-
                    // wisseling) of ✋ (handmatige correctie door jury).
                    const heeftBrutoAudit = h.bruto_tijd_ms != null
                                         && h.tijd_ms != null
                                         && h.bruto_tijd_ms !== h.tijd_ms;
                    // == 1 (niet truthy-check): PDO/JSON kan is_photofinish als
                    // string "0"/"1" sturen — in JS is "0" truthy, dus de oude
                    // r.is_photofinish ? ... gaf altijd 📷 ipv ✋ voor RR-tijden.
                    const brutoIcon  = h.is_photofinish == 1 ? '📷' : '✋';
                    const brutoTijd  = heeftBrutoAudit ? msTijd(h.bruto_tijd_ms) : '';

                    const extra = heatExtraKolommen(h.rijders ?? [], rt);
                    const rijders = h.rijders ?? [];
                    const heeftResultaten = rijders.some(r => r.finishpositie != null || r.tijd_ms != null);
                    const heeftRijders = rijders.length > 0;
                    const heatIcon = heeftResultaten ? '🏁' : heeftRijders ? '🚩' : '';
                    html += `<div class="heat-card">
                        <div class="heat-card-titel">
                            <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(getRondeLabel(rt))}</span>
                            <span style="flex:1">${esc(naam)}</span>
                            ${heatIcon ? `<span style="font-size:1rem">${heatIcon}</span>` : ''}
                        </div>
                        <table class="heat-card-tabel">
                        <thead>${heatTabelHeader(extra)}</thead>
                        <tbody>`;

                    for (const rr of (h.rijders ?? [])) {
                        // Match op license_key (uniek) — fallback op snr voor
                        // backwards-compat met oude payloads.
                        const isIk = (p.license_key && rr.license_key)
                            ? rr.license_key === p.license_key
                            : String(rr.snr) === snr;
                        html += heatTabelRij(rr, isIk, extra);
                    }

                    html += '</tbody></table>';
                    if (mijnTijd || mijnPos || mijnSanctie) {
                        // Format tijd-deel: bij audit-mismatch compact "✋ bruto → officieel",
                        // anders gewoon de officiële tijd. Pijl past op één regel.
                        const tijdHtml = heeftBrutoAudit
                            ? `${brutoIcon} ${esc(brutoTijd)} → ${esc(mijnTijd)}`
                            : (mijnTijd ? esc(mijnTijd) : '');
                        html += `<div class="heat-card-mijn-result">
                            <span>${esc(t('heat_jouw_resultaat'))}</span>
                            <span>${tijdHtml} ${mijnPos ? esc(mijnPos) : ''} ${mijnSanctie ? `<span class="heat-sanctie">${esc(mijnSanctie)}</span>` : ''}</span>
                        </div>`;
                    }
                    html += '</div>';
                }
            } else {
                html += `<div class="kaart-sectie"><div class="melding">${esc(t('msg_nog_geen_heats'))}</div></div>`;
            }
            html += '</div>';

            // ── TAB: Resultaten ───────────────────────────────────────
            html += '<div class="tab-content" data-tab="resultaten"><div class="kaart-sectie">';

            if (r.uitslagen.length) {
                html += `<div class="kaart-sectie-titel">${esc(t('res_uitslagen_titel'))}</div>`;
                for (const u of r.uitslagen) {
                    const sanctie = sl(u.sanctie);
                    html += `<div class="uitslag-rij">
                        <span class="uitslag-rang">${u.rang ?? '—'}</span>
                        <span class="uitslag-afstand">${esc(u.distance_naam)} ${u.finale_naam ? '('+esc(u.finale_naam)+')' : ''}</span>
                        ${u.punten != null ? `<span class="uitslag-punten">${parseFloat(u.punten)} ${esc(t('res_pt'))}</span>` : ''}
                        ${sanctie ? `<span class="heat-sanctie">${esc(sanctie)}</span>` : ''}
                    </div>`;
                }
            }

            if (r.klassementen.length) {
                for (const k of r.klassementen) {
                    html += `<div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-top:1px solid #eee;margin-top:8px">
                        <div><div class="kaart-sectie-titel" style="margin:0">${esc(t('res_klassement', {dc: k.dc_naam}))}</div>
                             <span class="klas-rang">#${k.rang}</span></div>
                        <div class="klas-totaal">${esc(t('res_punten', {n: parseFloat(k.punten_totaal)}))}</div>
                    </div>`;
                }
            }

            if (!r.uitslagen.length && !r.klassementen.length) {
                html += `<div class="melding">${esc(t('msg_nog_geen_resultaten'))}</div>`;
            }

            html += '</div></div>';

            // ── TAB: Uitslagen (volledig overzicht) ──────────────────
            html += `<div class="tab-content" data-tab="uitslagen">
                <div class="kaart-sectie">
                <div class="kaart-sectie-titel">${esc(t('uitsl_titel'))}</div>
                <div class="uitsl-selects">
                    <select class="uitsl-cat-sel"><option value="">${esc(t('msg_laden'))}</option></select>
                    <select class="uitsl-dist-sel" disabled><option value="">${esc(t('uitsl_opt_kies_afstand'))}</option></select>
                </div>
                <div class="uitsl-tabel-wrap"></div>
            </div>
            <div class="kaart-sectie" data-serie-lijst style="display:none">
                <div class="kaart-sectie-titel">${esc(t('serie_titel'))}</div>
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

    // Categorieën laden — cache wordt gewist door stilleRefresh / PTR
    // zodat publish/intrek-veranderingen vanzelf worden opgepikt.
    try {
        if (!_catCache || _catCache.compId !== compId) {
            const res = await safeFetch(`?action=categorieen&competition_id=${encodeURIComponent(compId)}&_t=${Date.now()}`);
            _catCache = { compId, data: await res.json() };
        }
        const cats = _catCache.data;
        if (cats.error) { wrap.innerHTML = `<div class="melding melding-fout">${esc(cats.error)}</div>`; return; }

        catSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_cat'))}</option>`;
        for (const c of cats) {
            const o = document.createElement('option');
            o.value = c.dc_id;
            o.textContent = c.dc_naam;
            o.dataset.json = JSON.stringify(c);
            catSel.appendChild(o);
        }
    } catch (e) {
        wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
    }

    // Categorie-change → vul afstand-dropdown (bind EERST, dan auto-select)
    catSel.addEventListener('change', () => {
        wrap.innerHTML = '';
        const opt = catSel.selectedOptions[0];
        if (!opt?.value) { distSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_afstand'))}</option>`; distSel.disabled = true; return; }
        const cat = JSON.parse(opt.dataset.json);
        distSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_afstand'))}</option>`;
        for (const a of cat.afstanden) {
            const o = document.createElement('option');
            o.value = a.distance_id; o.textContent = a.distance_naam;
            distSel.appendChild(o);
        }
        if (cat.klassement_beschikbaar) {
            const o = document.createElement('option');
            o.value = '__klassement__'; o.textContent = t('uitsl_klassement_opt');
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

        wrap.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_laden')}</div>`;

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
            wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
        }
    });

    // Restore vorige keuze (na auto-refresh of na kind-wissel) — voorkomt dat
    // de gebruiker zijn categorie + afstand opnieuw moet kiezen telkens als
    // stilleRefresh() de DOM herbouwt. _bewaarKindUistate() heeft de keuze
    // net daarvoor opgeslagen op het kind-object.
    const saved = _kinderen?.[_activeKindIdx]?._uistate;
    const cats  = _catCache?.data;
    if (saved?.catVal && catSel.querySelector(`option[value="${CSS.escape(saved.catVal)}"]`)) {
        catSel.value = saved.catVal;
        catSel.dispatchEvent(new Event('change'));
        // Daarna ook afstand/klassement herstellen als die nog bestaat
        if (saved.distVal) {
            const distOpt = distSel.querySelector(`option[value="${CSS.escape(saved.distVal)}"]`);
            if (distOpt) {
                distSel.value = saved.distVal;
                distSel.dispatchEvent(new Event('change'));
            }
        }
    } else if (cats?.length === 1) {
        // Auto-selecteer als er maar 1 categorie is (ná binden listeners)
        catSel.value = cats[0].dc_id;
        catSel.dispatchEvent(new Event('change'));
    }
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
                <option value="">${esc(t('serie_opt_kies'))}</option>
                ${series.map(s => `
                    <option value="${esc(s.klassement_id)}">
                        ${esc(s.naam)}${s.seizoen ? t('serie_seizoen_sep') + esc(s.seizoen) : ''}
                        (${esc(t('serie_aantal_rijders', {n: s.totaal_rijders}))})
                    </option>`).join('')}
            </select>
            <select class="serie-cat-sel" disabled><option value="">${esc(t('uitsl_opt_kies_cat'))}</option></select>`;

        const serieSel = selector.querySelector('.serie-sel');
        const catSel   = selector.querySelector('.serie-cat-sel');
        let huidig = null; // cached klassement-response

        serieSel.addEventListener('change', async () => {
            wrap.innerHTML = '';
            catSel.innerHTML = `<option value="">${esc(t('uitsl_opt_kies_cat'))}</option>`;
            catSel.disabled = true;
            if (!serieSel.value) return;
            wrap.innerHTML = `<div class="melding"><span class="spinner"></span> ${t('msg_laden')}</div>`;
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
                catSel.innerHTML = `<option value="">${esc(t('serie_opt_alle_cats'))}</option>` +
                    cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
                catSel.disabled = false;
                // Auto-eerste categorie: als er maar 1 is, selecteer die
                if (cats.length === 1) {
                    catSel.value = cats[0];
                    catSel.dispatchEvent(new Event('change'));
                } else {
                    wrap.innerHTML = `<div class="melding">${esc(t('msg_kies_categorie_klassement'))}</div>`;
                }
            } catch (e) {
                wrap.innerHTML = `<div class="melding melding-fout">${esc(t('err_prefix', {msg: e.message}))}</div>`;
            }
        });

        catSel.addEventListener('change', () => {
            if (!huidig) return;
            wrap.innerHTML = renderSerieKlassementTabel(huidig, catSel.value || null);
        });

        // Restore serie-klassement keuze na auto-refresh / kind-wissel
        // (dezelfde reden als bij Uitslagen — anders verspringt elke 60s).
        const saved = _kinderen?.[_activeKindIdx]?._uistate;
        if (saved?.serieVal && serieSel.querySelector(`option[value="${CSS.escape(saved.serieVal)}"]`)) {
            serieSel.value = saved.serieVal;
            serieSel.dispatchEvent(new Event('change'));
            // serieSel.change is async (fetch + cat-sel populate); restore
            // de cat-keuze pas als cat-sel bevolkt is. We polleren kort —
            // veel simpeler dan de promise-chain doorvlechten.
            if (saved.serieCatVal) {
                const t0 = Date.now();
                const probeer = () => {
                    if (catSel.querySelector(`option[value="${CSS.escape(saved.serieCatVal)}"]`)) {
                        catSel.value = saved.serieCatVal;
                        catSel.dispatchEvent(new Event('change'));
                    } else if (Date.now() - t0 < 4000) {
                        setTimeout(probeer, 80);
                    }
                };
                setTimeout(probeer, 80);
            }
        }
    } catch (e) {
        box.style.display = 'none';
    }
}

// Render de serie-klassement-tabel (vergelijkbaar met ranking-detail in Beheer,
// maar met highlight voor de eigen rijder uit inpSnr).
function renderSerieKlassementTabel(k, cat) {
    const alle  = k.posities ?? [];
    const rijen = cat ? alle.filter(p => p.categorie === cat) : alle;
    if (!rijen.length) return `<div class="melding">${esc(t('msg_geen_posities'))}</div>`;
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

    let hdr = `<tr><th class="col-rang">${t('col_rang')}</th><th class="col-snr">${t('col_snr')}</th><th>${t('col_naam')}</th>`;
    if (!cat) hdr += `<th class="col-cat">${t('col_cat')}</th>`;
    if (toonW) {
        hdr += wMeta.map((w, i) =>
            `<th class="col-w" title="${esc(w.naam)}${w.datum ? ' · ' + String(w.datum).substring(0,10) : ''}${w.is_finale ? ' · FINALE' : ''}">
                ${w.is_finale ? 'F' : '#' + (i + 1)}
            </th>`).join('');
        hdr += `<th class="col-tot">${t('col_tot')}</th>`;
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
    if (!data.rijders?.length) return `<div class="melding">${esc(t('msg_geen_uitslagen'))}</div>`;
    const heeftRnd = data.heeft_rondes;
    const heeftPK  = data.heeft_pk_punten;

    let hdr = `<th class="col-rang">${t('col_rang')}</th><th class="col-snr">${t('col_snr')}</th><th class="col-naam">${t('col_naam')}</th>`;
    if (heeftRnd) hdr += `<th class="col-rnd">${t('col_rnd')}</th>`;
    if (heeftPK)  hdr += `<th class="col-pk">${t('col_pnt')}</th>`;
    hdr += `<th class="col-tijd">${t('col_tijd')}</th>`;

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
    if (!data.rijders?.length) return `<div class="melding">${esc(t('msg_geen_klassement'))}</div>`;
    const afstanden = data.afstanden ?? [];

    let hdr = `<th class="col-rang">${t('col_rang')}</th><th class="col-snr">${t('col_snr')}</th><th class="col-naam">${t('col_naam')}</th>`;
    for (const a of afstanden) {
        // Afkorten voor mobiel: eerste 3 letters + eventueel getal
        const kort = a.length > 6 ? a.substring(0, 5) + '.' : a;
        hdr += `<th class="col-punten" title="${esc(a)}">${esc(kort)}</th>`;
    }
    hdr += `<th class="col-totaal">${t('col_tot')}</th>`;

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

// 🥚 Easter egg: 3× snel klikken op org-logo opent blobart.devriesen.com.
// Counter + timer op module-niveau zodat ze tussen renderings behouden blijven.
let _eggCount = 0;
let _eggTimer = null;
function _eggHandler() {
    _eggCount++;
    clearTimeout(_eggTimer);
    if (_eggCount >= 3) {
        _eggCount = 0;
        // Fire-and-forget naar de teller; geen await zodat de tab direct opent.
        fetch('../api/easter_egg.php', { method: 'POST' }).catch(() => {});
        window.open('https://blobart.devriesen.com', '_blank', 'noopener');
    } else {
        _eggTimer = setTimeout(() => { _eggCount = 0; }, 2000);
    }
}

// Logo dat — bij height 50px — breder zou worden dan deze ratio, past niet
// netjes in de vaste footer-positie en duwt de sponsor-marquee weg. We
// verplaatsen 'm dan naar de marquee zodat 'ie wel op volle hoogte leesbaar
// blijft (lichtkrant rolt 'm langs).
const _FOOTER_LOGO_MAX_RATIO = 150 / 50;   // = 3.0 : 1
                                           // (incl. Warmondse 250×71 ≈ 3.52
                                           // → naar marquee i.p.v. footer)

// Async helper — preload image, lever ratio terug. Bij fout: 1 (= niet te breed).
function _logoRatio(src) {
    return new Promise(resolve => {
        const img = new Image();
        img.onload  = () => resolve(img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1);
        img.onerror = () => resolve(1);
        img.src = src;
    });
}

async function updateHeaderLogos(opt) {
    const footer   = document.getElementById('org-footer');
    const logoEl   = document.getElementById('footer-org-logo');
    const naamEl   = document.getElementById('footer-org-naam');
    const sponsEl  = document.getElementById('footer-sponsors');
    const baanEl   = document.getElementById('footer-baan-logo');

    if (!opt?.value) {
        footer.style.display = 'none';
        return;
    }

    const orgLogo   = opt.dataset.orgLogo;
    const orgNaam   = opt.dataset.orgNaam ?? '';
    const baanLogo  = opt.dataset.baanLogo ?? '';
    const baanVer   = opt.dataset.baanVereniging ?? '';
    const sponsors  = JSON.parse(opt.dataset.sponsors || '[]');

    // Niets te tonen? Footer verbergen (incl. check op baan-logo).
    if (!orgLogo && !sponsors.length && !baanLogo && !baanVer) {
        footer.style.display = 'none';
        return;
    }

    // Cache-buster zodat een vers geüpload logo niet uit de browser-cache blijft.
    // Gebruikt het huidige uur als bust-waarde: stabiel genoeg voor normale navigatie
    // maar een upload is uiterlijk binnen het uur zichtbaar.
    const cb = `?v=${Math.floor(Date.now() / 3600000)}`;

    // Te brede logo's (bv. landscape-vereniging-logo) passen niet in de
    // vaste footer-positie. We preloaden ze, meten de aspect-ratio en
    // verhuizen ze naar de marquee als ze te breed zijn — dan hebben ze
    // wel ruimte om op volle hoogte langs te rollen.
    const orgRatio  = orgLogo  ? await _logoRatio(`../${esc(orgLogo)}${cb}`)  : 0;
    const baanRatio = baanLogo ? await _logoRatio(`../${esc(baanLogo)}${cb}`) : 0;
    const orgInFooter  = orgLogo  && orgRatio  <= _FOOTER_LOGO_MAX_RATIO;
    const baanInFooter = baanLogo && baanRatio <= _FOOTER_LOGO_MAX_RATIO;

    // Organisatie-logo links in footer. Bij te-breed logo: vaste positie
    // leeg laten — logo gaat naar marquee.
    logoEl.innerHTML = orgInFooter ? `<img class="org-footer-logo" src="../${esc(orgLogo)}${cb}" alt="">` : '';
    // Naam-fallback ALLEEN als er helemaal geen logo is (niet als 't te breed
    // is — dan rolt het logo zelf langs in de marquee, naam-tekst overbodig).
    naamEl.textContent = !orgLogo ? orgNaam : '';
    // Easter egg: 3× klikken op het org-logo binnen 2 sec opent blobart.
    // Geheim — cursor blijft default zodat het niet verraadt klikbaar te zijn.
    const _eggImg = logoEl.querySelector('img');
    if (_eggImg) _eggImg.addEventListener('click', _eggHandler);

    // Gastheer-vereniging rechts in footer. Bij te-breed logo: vaste
    // positie leeg, logo naar marquee. Naam-fallback alleen bij ECHT
    // geen logo (geen visuele verdubbeling met de marquee-versie).
    if (baanInFooter) {
        baanEl.innerHTML = `<img class="org-footer-logo" src="../${esc(baanLogo)}${cb}" alt="">`;
    } else if (baanVer && !baanLogo) {
        baanEl.innerHTML = `<span class="org-footer-naam">${esc(baanVer)}</span>`;
    } else {
        baanEl.innerHTML = '';
    }
    // Lege wrapper inklappen zodat de marquee de hele rechter-ruimte vult
    // (anders blijft 't <div> leeg layout-ruimte innemen ondanks empty html).
    baanEl.style.display = baanEl.innerHTML ? '' : 'none';

    // Sponsors (lichtkrant-ticker) + eventueel te-brede org/baan-logo's.
    // Altijd marquee, ook bij 1 item — anders 'hangt' de enige logo
    // statisch. Min-duur 8s zodat 1 logo niet onhandig snel langs schiet.
    let imgs = '';
    const _liggendImg = (src, naam) =>
        `<img src="../${esc(src)}${cb}" alt="${esc(naam)}" title="${esc(naam)}" style="height:50px;width:auto;object-fit:contain">`;
    if (orgLogo  && !orgInFooter)  imgs += _liggendImg(orgLogo,  orgNaam);
    if (baanLogo && !baanInFooter) imgs += _liggendImg(baanLogo, baanVer || '');
    for (const s of sponsors) {
        const img = _liggendImg(s.logo, s.naam);
        imgs += s.url ? `<a href="${esc(s.url)}" target="_blank" rel="noopener">${img}</a>` : img;
    }
    if (imgs) {
        const aantal = sponsors.length
                     + (orgLogo  && !orgInFooter  ? 1 : 0)
                     + (baanLogo && !baanInFooter ? 1 : 0);
        const duur = Math.max(8, aantal * 3);
        sponsEl.innerHTML = `<div class="sponsor-marquee"><div class="sponsor-marquee-inner" style="animation-duration:${duur}s">${imgs}${imgs}</div></div>`;
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
            <span>${esc(t('info_titel'))}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">
            <h3>${esc(t('info_h1'))}</h3>
            <p>${esc(t('info_p1'))}</p>
            <p>${t('info_p2_html')}</p>

            <h3>${esc(t('info_h2'))}</h3>
            <p>${esc(t('info_p3'))}</p>

            <h3>${t('info_h3_html')}</h3>
            <p>${esc(t('info_p4'))}</p>
            <p style="text-align:center;margin:12px 0">
                <a href="mailto:inlinecomp@devriesen.com" style="display:inline-block;background:var(--oranje);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">inlinecomp@devriesen.com</a>
            </p>

            <h3>${esc(t('info_h4'))}</h3>
            <p style="font-size:.85rem;color:#555">${t('info_p5_html')}</p>

            <h3>${t('info_h5_html')}</h3>
            <p>${esc(t('info_p6'))}</p>
            <p style="text-align:center;margin:12px 0">
                <a href="../privacyverklaring.php" style="display:inline-block;background:var(--blauw,#1a3a5c);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">${esc(t('info_btn_privacy'))}</a>
            </p>

            <p style="font-size:.8rem;color:#999;text-align:center;margin-top:16px">${t('info_copyright', {jaar: new Date().getFullYear()})}</p>
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
            <span>${esc(t('help_titel'))}</span>
            <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
        </div>
        <div class="help-body">

            <h3>${esc(t('help_h1'))}</h3>
            <div class="help-stap">
                <span class="help-stap-nr">1</span>
                <span>${t('help_stap1_html')}</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">2</span>
                <span>${t('help_stap2_html')}</span>
            </div>
            <div class="help-stap">
                <span class="help-stap-nr">3</span>
                <span>${t('help_stap3_html')}</span>
            </div>

            <!-- Mockup: zoekscherm — toont actuele filter-chips + 3-knoppen-rij -->
            <div class="mock">
                <div class="mock-hdr">InlineComp – Public</div>
                <div class="mock-body">
                    <div style="display:flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;color:var(--blauw);margin:0 0 4px">
                        <span style="background:var(--blauw);color:#fff;width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem">1</span>
                        ${esc(t('help_mock_kies_w'))}
                    </div>
                    <div style="display:flex;gap:4px;margin:0 0 6px">
                        <span style="flex:1;text-align:center;font-size:.7rem;font-weight:600;padding:4px 0;border-radius:12px;border:1.5px solid #cdd8e3;color:#888;background:#fff">${esc(t('filter_eerder'))}</span>
                        <span style="flex:1;text-align:center;font-size:.7rem;font-weight:600;padding:4px 0;border-radius:12px;border:1.5px solid var(--middenblauw);color:var(--blauw);background:var(--lichtblauw)">${esc(t('filter_vandaag'))}</span>
                        <span style="flex:1;text-align:center;font-size:.7rem;font-weight:600;padding:4px 0;border-radius:12px;border:1.5px solid #cdd8e3;color:#888;background:#fff">${esc(t('filter_later'))}</span>
                    </div>
                    <div class="mock-select">${esc(t('help_mock_voorbeeld'))}</div>
                    <div style="display:flex;align-items:center;gap:5px;font-size:.75rem;font-weight:700;color:var(--blauw);margin:8px 0 4px">
                        <span style="background:var(--blauw);color:#fff;width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem">2</span>
                        ${esc(t('help_mock_snr_lic'))}
                    </div>
                    <div class="mock-select">${esc(t('help_mock_snr'))}</div>
                    <div style="background:var(--oranje);color:#fff;text-align:center;padding:6px;border-radius:6px;font-weight:700;font-size:.75rem;margin-top:4px">${esc(t('btn_zoeken'))}</div>
                </div>
            </div>

            <h3>${esc(t('help_h_tabs'))}</h3>
            <p>${t('help_p_tabs_html')}</p>

            <p>${t('help_p_prog_html')}</p>

            <!-- Mockup: programma -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab active">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_resultaten').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-body" style="padding:4px 10px">
                    <div class="mock-row"><span style="color:#aaa">1</span> <span class="mock-naam">500m ${esc(t('ronde_serie'))} Heat 1</span> <span style="font-size:.6rem;background:#0d6efd;color:#fff;border-radius:3px;padding:0 4px">${esc(t('ronde_serie'))}</span></div>
                    <div class="mock-row mock-hl"><span style="color:#aaa">2</span> <span class="mock-naam">500m ${esc(t('ronde_serie'))} Heat 2</span> <span style="font-size:.6rem;background:#0d6efd;color:#fff;border-radius:3px;padding:0 4px">${esc(t('ronde_serie'))}</span></div>
                    <div class="mock-row"><span style="color:#aaa">3</span> <span class="mock-naam">500m A-${esc(t('ronde_finale'))}</span> <span style="font-size:.6rem;background:#198754;color:#fff;border-radius:3px;padding:0 4px">${esc(t('ronde_finale'))}</span></div>
                </div>
            </div>

            <p>${t('help_p_heats_html')}</p>

            <!-- Mockup: heat -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_resultaten').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div style="background:var(--blauw);color:#fff;padding:5px 10px;font-size:.7rem;font-weight:700">
                    <span style="background:#198754;border-radius:3px;padding:0 5px;font-size:.6rem">${esc(t('ronde_finale'))}</span> 500m A-${esc(t('ronde_finale'))}
                </div>
                <div class="mock-body" style="padding:4px 10px">
                    <div class="mock-row" style="font-size:.6rem;color:#888;font-weight:600"><span style="width:18px">${esc(t('col_pos'))}</span><span style="width:24px">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span class="mock-tijd">${esc(t('col_tijd'))}</span><span style="width:20px;text-align:center">${esc(t('col_fin'))}</span></div>
                    <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span class="mock-tijd">45.30</span><span style="width:20px;text-align:center;font-weight:600">2</span></div>
                    <div class="mock-row mock-hl"><span class="mock-rang">2</span><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span class="mock-tijd">45.12</span><span style="width:20px;text-align:center;font-weight:600;color:var(--blauw)">1</span></div>
                    <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span class="mock-tijd">46.01</span><span style="width:20px;text-align:center;font-weight:600">3</span></div>
                </div>
            </div>

            <p>${t('help_p_res_html')}</p>

            <p>${t('help_p_uitsl_html')}</p>

            <!-- Mockup: uitslagen -->
            <div class="mock">
                <div class="mock-tabs">
                    <div class="mock-tab">${esc(t('tab_programma').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_heats').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab">${esc(t('tab_resultaten').replace(/^[^\s]+\s*/, ''))}</div>
                    <div class="mock-tab active">${esc(t('tab_uitslagen').replace(/^[^\s]+\s*/, ''))}</div>
                </div>
                <div class="mock-body" style="padding:6px 10px">
                    <div class="mock-select">DJB/A + HJB/A</div>
                    <div class="mock-select">${esc(t('uitsl_klassement_opt').replace(/^[^\s]+\s*/, ''))}</div>
                    <div style="margin-top:6px">
                        <div class="mock-row" style="font-size:.6rem;color:#fff;background:var(--blauw);margin:0 -10px;padding:3px 10px;font-weight:600"><span style="width:18px">${esc(t('col_rang'))}</span><span style="width:24px">${esc(t('col_snr'))}</span><span class="mock-naam">${esc(t('col_naam'))}</span><span style="width:30px;text-align:center">Spr</span><span style="width:30px;text-align:center">L.A.</span><span style="width:30px;text-align:center;color:var(--oranje)">${esc(t('col_tot'))}</span></div>
                        <div class="mock-row"><span class="mock-rang">1</span><span class="mock-snr">86</span><span class="mock-naam">${esc(t('help_mock_jouw_naam'))}</span><span style="width:30px;text-align:center">4</span><span style="width:30px;text-align:center">1</span><span style="width:30px;text-align:center;font-weight:700;color:var(--oranje)">8</span></div>
                        <div class="mock-row"><span class="mock-rang">2</span><span class="mock-snr">12</span><span class="mock-naam">Emma V.</span><span style="width:30px;text-align:center">5</span><span style="width:30px;text-align:center">3</span><span style="width:30px;text-align:center;font-weight:700;color:var(--oranje)">11</span></div>
                        <div class="mock-row"><span class="mock-rang">3</span><span class="mock-snr">34</span><span class="mock-naam">Tim B.</span><span style="width:30px;text-align:center">5</span><span style="width:30px;text-align:center">6</span><span style="width:30px;text-align:center;font-weight:700;color:var(--oranje)">12</span></div>
                    </div>
                </div>
            </div>

            <h3>${esc(t('help_h_auto'))}</h3>
            <p>${t('help_p_auto_html')}</p>

            <h3>${esc(t('help_h_meld'))}</h3>
            <p>${t('help_p_meld_html')}</p>

            <h3>${esc(t('help_h_tip'))}</h3>
            <p>${esc(t('help_p_tip'))}</p>

        </div>
    </div>`;
    document.body.appendChild(overlay);
}

// ── Mededelingen (pop-ups bij belangrijke aankondigingen) ────────────────
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
// Cache van actieve meldingen-lijst (laatste API-response). Gebruikt om
// na het sluiten van één pop-up direct de volgende ongeziene te tonen,
// zonder op de volgende poll-tick te wachten.
let _meldingLijst = [];
let _meldingActief = false;     // staat er al een pop-up open?
// Welke melding staat op dit moment in de interrupt-popup? Nodig zodat we
// 'm opnieuw kunnen renderen (in de nieuwe taal) als de gebruiker
// midden-popup naar EN/NL switcht. null = geen popup open.
let _huidigeMelding = null;

async function checkMeldingen(compId) {
    // compId leeg → fetch alleen globale meldingen (landing-pagina).
    // compId gevuld → fetch wedstrijd-specifiek + globaal samen (één call).
    try {
        const url = compId
            ? '../api/meldingen.php?comp_id=' + encodeURIComponent(compId) + '&_t=' + Date.now()
            : '../api/meldingen.php?global=1&_t=' + Date.now();
        const res = await safeFetch(url);
        const lijst = await res.json();
        if (!Array.isArray(lijst)) return;
        // Defensieve client-side filter: alleen actieve meldingen tonen.
        // De API filtert al, maar bij clock-drift, caching of een service
        // worker-replay kan een net-verlopen melding doorglippen — die
        // willen we ook hier nog wegfilteren.
        const nu = Date.now();
        _meldingLijst = lijst.filter(m => {
            const van = m.geldig_van ? Date.parse(m.geldig_van.replace(' ', 'T')) : 0;
            const tot = m.geldig_tot ? Date.parse(m.geldig_tot.replace(' ', 'T')) : null;
            if (van && van > nu)        return false;       // nog niet begonnen
            if (tot !== null && tot < nu) return false;     // verlopen
            return true;
        });
        // Badge bijwerken (totaal aantal actieve meldingen, ongeacht gezien)
        updateMeldingenBadge();
        // Pop-up alleen als er nog geen open staat (avoid stacken)
        if (!_meldingActief) toonVolgendeMelding(compId);
    } catch { /* stil */ }
}

function updateMeldingenBadge() {
    const btn = document.getElementById('btn-meldingen-overzicht');
    const badge = document.getElementById('meldingen-badge');
    if (!btn || !badge) return;
    if (_meldingLijst.length === 0) {
        btn.style.display = 'none';
        badge.style.display = 'none';
        return;
    }
    // Badge toont ALTIJD het totaal aantal meldingen zodat je ziet dat ze er
    // zijn. Als er nog ONGELEZEN zijn: rood + uitroepteken (= "kijk even");
    // als alles is gezien: grijs zonder uitroepteken (= "alleen FYI"). Een
    // melding telt als gelezen zodra de fullscreen-pop-up met OK is wegge-
    // klikt (zie _markGezien in de OK-handler hieronder).
    btn.style.display = '';
    const aantalOngelezen = _meldingLijst.filter(m =>
        !_gezienSet(_meldingScope(m)).has(m.id)
    ).length;
    badge.textContent = aantalOngelezen > 0
        ? `${_meldingLijst.length}!`
        : String(_meldingLijst.length);
    badge.classList.toggle('gezien', aantalOngelezen === 0);
    badge.style.display = '';
}

// Klik op 📢-knop: toont een lijst van alle nu-actieve meldingen
// (chronologisch). Geen "begrepen"-knop — dit is een lookup-paneel,
// niet een interrupterende pop-up. Eerder geziene meldingen blijven
// hier zichtbaar zodat je ze terug kan vinden.
function toonMeldingenOverzicht() {
    if (!_meldingLijst.length) return;
    const overlay = document.createElement('div');
    // data-attribute zodat _rerenderActiveTab 'm kan vinden bij taalwissel
    overlay.dataset.meldOverlay = 'overzicht';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9400;display:flex;align-items:flex-start;justify-content:center;padding:4vh 1rem;overflow-y:auto;';
    const _loc = getLocale();
    const items = _meldingLijst.map(m => {
        const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
        const tijd = m.geldig_van
            ? new Date(m.geldig_van.replace(' ', 'T')).toLocaleString(_loc,
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        const tot = m.geldig_tot
            ? t('meld_tot') + new Date(m.geldig_tot.replace(' ', 'T')).toLocaleString(_loc,
                {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})
            : '';
        // Pak de vertaling voor de huidige taal als beschikbaar. Fallback-keten:
        // huidige taal → EN → NL (= origineel). Geldt voor alle ondersteunde
        // talen (NL/EN/DE/FR).
        const titelToon   = _meldingTekst(m, 'titel');
        const berichtToon = _meldingTekst(m, 'bericht');
        const bijlHtml = m.bijlage_path
            ? `<a href="../${esc(m.bijlage_path)}" target="_blank" rel="noopener"
                   download="${esc(m.bijlage_naam || 'bijlage')}"
                   style="display:inline-flex;align-items:center;gap:.3rem;
                          margin-top:.4rem;background:#fff;
                          border:1px solid ${stijl.kleur};color:${stijl.kleur};
                          text-decoration:none;padding:.3rem .55rem;
                          border-radius:4px;font-size:.8rem;font-weight:600;
                          max-width:100%;">
                   📎 <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(m.bijlage_naam || 'bijlage')}</span>
                </a>`
            : '';
        return `<div style="background:${stijl.bg};border-left:4px solid ${stijl.kleur};
                            padding:.7rem .9rem;margin-bottom:.6rem;border-radius:5px;">
            <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.3rem;">
                <span style="font-size:1.2rem">${stijl.icoon}</span>
                <strong style="color:${stijl.kleur};flex:1;">${esc(titelToon)}</strong>
            </div>
            <div style="color:#222;line-height:1.4;font-size:.9rem;white-space:pre-wrap;">${esc(berichtToon)}</div>
            ${bijlHtml}
            <div style="font-size:.75rem;color:#888;margin-top:.3rem;">${esc(tijd)}${esc(tot)}</div>
        </div>`;
    }).join('');
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:8px;max-width:480px;width:100%;
                    box-shadow:0 10px 30px rgba(0,0,0,.3);">
            <div style="display:flex;align-items:center;justify-content:space-between;
                        padding:.8rem 1rem;border-bottom:1px solid #e0e0e0;">
                <h3 style="margin:0;color:var(--blauw);font-size:1.05rem;">${esc(t('meld_kop'))}</h3>
                <button class="meld-overz-sluit" style="background:none;border:none;
                        font-size:1.6rem;cursor:pointer;color:#666;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:1rem;">${items}</div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-overz-sluit').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

// Knop wiring (één keer bij script-load)
document.getElementById('btn-meldingen-overzicht')?.addEventListener('click', toonMeldingenOverzicht);
// Pak de eerstvolgende niet-geziene melding uit de gecachte lijst en toon 'm.
// Wordt aangeroepen door checkMeldingen (na poll) en door de Begrepen-knop
// (na sluiten — direct doorrollen, geen wachttijd).
function toonVolgendeMelding(compId) {
    if (_meldingActief) return;
    // Per melding gezien-set ophalen (afhankelijk van scope = global of compId).
    for (const m of _meldingLijst) {
        const scope = _meldingScope(m);
        if (!_gezienSet(scope).has(m.id)) {
            toonMelding(m, compId);
            return;
        }
    }
}
function toonMelding(m, compId) {
    if (_meldingActief) return;     // double-click guard
    _meldingActief = true;
    _huidigeMelding = m;            // onthouden voor taalwissel-rerender
    const stijl = _MELDING_PRIO[m.prio] ?? _MELDING_PRIO.info;
    const overlay = document.createElement('div');
    // data-attribute zodat _rerenderActiveTab 'm kan vinden bij taalwissel
    overlay.dataset.meldOverlay = 'popup';
    // Overlay scrolt zelf óók (overflow-y:auto) als achterval voor heel kleine
    // schermen waar zelfs de inner-box met max-height: 90vh nog te hoog is.
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9500;display:flex;align-items:center;justify-content:center;padding:1rem;overflow-y:auto;';
    // Inner-box als flex-column: header + scrollable bericht + knop. Bericht-
    // div krijgt overflow-y:auto + min-height:0 (cruciaal voor flex-children),
    // knop heeft flex-shrink:0 zodat 'ie altijd onderaan zichtbaar blijft.
    const titelToon   = _meldingTekst(m, 'titel');
    const berichtToon = _meldingTekst(m, 'bericht');
    overlay.innerHTML = `
        <div style="background:${stijl.bg};border:3px solid ${stijl.kleur};border-radius:10px;
                    max-width:400px;width:100%;max-height:calc(100vh - 2rem);
                    display:flex;flex-direction:column;
                    box-shadow:0 10px 40px rgba(0,0,0,.4);animation:meldingPop .3s ease-out;">
            <div style="display:flex;align-items:center;gap:.6rem;padding:1.5rem 1.5rem 0;flex-shrink:0;">
                <span style="font-size:1.8rem">${stijl.icoon}</span>
                <h2 style="margin:0;color:${stijl.kleur};font-size:1.1rem;flex:1;">${esc(titelToon)}</h2>
            </div>
            <div style="color:#222;line-height:1.5;font-size:.95rem;
                        white-space:pre-wrap;padding:.6rem 1.5rem 1rem;
                        overflow-y:auto;flex:1 1 auto;min-height:0;">${esc(berichtToon)}</div>
            ${m.bijlage_path ? `
            <div style="padding:0 1.5rem .8rem;flex-shrink:0;">
                <a href="../${esc(m.bijlage_path)}" target="_blank" rel="noopener"
                   download="${esc(m.bijlage_naam || 'bijlage')}"
                   style="display:flex;align-items:center;gap:.5rem;
                          background:#fff;border:1.5px solid ${stijl.kleur};
                          color:${stijl.kleur};text-decoration:none;
                          padding:.5rem .8rem;border-radius:6px;font-size:.9rem;
                          font-weight:600;">
                    <span style="font-size:1.1rem">📎</span>
                    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(m.bijlage_naam || 'Download bijlage')}</span>
                    <span style="font-size:.8rem;opacity:.7">⬇</span>
                </a>
            </div>` : ''}
            <div style="padding:0 1.5rem 1.5rem;flex-shrink:0;">
                <button class="meld-ok" style="background:${stijl.kleur};color:#fff;border:none;
                                                padding:.6rem 1.4rem;border-radius:6px;font-size:1rem;
                                                font-weight:600;cursor:pointer;width:100%;">
                    ${esc(t('meld_begrepen'))}
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.meld-ok').addEventListener('click', () => {
        _markGezien(_meldingScope(m), m.id);
        updateMeldingenBadge();
        overlay.remove();
        _meldingActief = false;
        _huidigeMelding = null;
        // Direct doorrollen naar volgende ongeziene melding (geen poll-wait).
        // Werkt ook zonder geselecteerde wedstrijd — globale melding-keten
        // mag altijd doorscrollen.
        toonVolgendeMelding(selComp.value);
    });
}
// keyframe voor pop-up animatie
(() => {
    const style = document.createElement('style');
    style.textContent = '@keyframes meldingPop { from {opacity:0;transform:scale(.85)} to {opacity:1;transform:scale(1)} }';
    document.head.appendChild(style);
})();

// ── Auto-refresh ──────────────────────────────────────────────────────────
// Stille refresh van programma + lookup voor alle actieve kinderen, elke
// minuut. Stopt als het tabblad onzichtbaar wordt en hervat zodra het weer
// actief is. Toont een tijdstempel "🔄 HH:MM" naast de wedstrijd-keuze
// zodat duidelijk is wanneer de data voor het laatst is bijgewerkt.
//
// Patroon overgenomen uit coach/index.php (regel 2319-2353) — zelfde gedrag,
// zelfde tab-aware optimalisatie.
// Globale stempel-tekst — gebruikt door zowel de persoon-card-template
// (die bij elke render z'n eigen .auto-stempel-span maakt) als de bestaande
// stempel-spans in de DOM. Update via zetStempel().
let _huidigStempel = '';

(function() {
    // 3 minuten — frequente publish/loting-updates komen toch via meldingen-
    // push naar de gebruiker; de poll dient als vangnet voor "ik kijk al een
    // tijdje". Lagere frequentie scheelt aanzienlijk in serverbelasting bij
    // grote wedstrijden waar tientallen toestellen actief zijn.
    const AUTO_REFRESH_MS = 180_000;
    let autoTick = null;

    const zetStempel = () => {
        const d = new Date();
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        // Twee aparte spans: in stap-1-label staan ze inline naast elkaar,
        // in de persoon-card stapelen ze verticaal (icoon boven, tijd onder)
        // — verschil zit in de CSS-context.
        _huidigStempel = `<span class="aut-icon">🔄</span> <span class="aut-tijd">${hh}:${mm}</span>`;
        document.querySelectorAll('.auto-stempel').forEach(el => {
            el.innerHTML = _huidigStempel;
        });
    };

    const wisStempel = () => {
        _huidigStempel = '';
        document.querySelectorAll('.auto-stempel').forEach(el => { el.innerHTML = ''; });
    };

    // Stille refresh: alle kinderen + gedeeld programma in één parallel-batch
    // (geen loader-flash, geen UI-tussenstaten). Bij faal: stempel niet
    // bijwerken — gebruiker ziet dat de tijd "blijft staan".
    const stilleRefresh = async () => {
        const compId = selComp.value;
        // Categorieën-cache wissen: klassement_beschikbaar-vlag verandert
        // bij publish/intrek; zonder reset zou dropdown stale blijven tot
        // hard refresh.
        _catCache = null;

        // Mededelingen-check loopt onafhankelijk van of er een wedstrijd is
        // gekozen of kinderen zijn toegevoegd. Zonder compId krijgen we de
        // globale meldingen; mét compId per-wedstrijd + globaal samen.
        checkMeldingen(compId);

        if (!compId || !_kinderen.length) return;
        try {
            const progRes = await safeFetch(
                `?action=programma&competition_id=${encodeURIComponent(compId)}&_t=${Date.now()}`
            );
            const prog = await progRes.json();
            // Per kind een lookup; parallel uitvoeren voor lagere latency.
            // BELANGRIJK: lookup op license_key wanneer beschikbaar, NIET op
            // startnummer — anders kan bij dubbele snrs (bv. een HP1 en DP1
            // met hetzelfde nummer) de array-volgorde tussen calls wisselen
            // en springt het scherm naar de andere persoon na een refresh.
            // Fallback-keten:
            //   1. license_key       → 1 hit, geen verwarring mogelijk
            //   2. startnummer + re-locate op license   (rijder kreeg later licentie?)
            //   3. startnummer + re-locate op categorie  (geen licentie, maar
            //                                              snr+cat is uniek
            //                                              binnen een wedstrijd)
            //   4. clamp kozen_idx als allerlaatste vangnet
            const kindRefreshes = _kinderen.map(async k => {
                const eerderePersoon = k?.data?.[k.kozen_idx ?? 0]?.persoon;
                const eerderLic = eerderePersoon?.license_key;
                const eerderCat = eerderePersoon?.category;
                const param = eerderLic
                    ? `license_key=${encodeURIComponent(eerderLic)}`
                    : (k?.snr ? `startnummer=${encodeURIComponent(k.snr)}` : null);
                if (!param) return;
                try {
                    const r = await safeFetch(
                        `?action=lookup&competition_id=${encodeURIComponent(compId)}&${param}&_t=${Date.now()}`
                    );
                    const data = await r.json();
                    if (Array.isArray(data) && data.length) {
                        k.data = data;
                        k.prog = prog;
                        if (eerderLic) {
                            // License-lookup → altijd 1 hit, idx blijft 0
                            k.kozen_idx = 0;
                        } else if (data.length > 1) {
                            // Snr-fallback met meerdere hits: re-locate.
                            // Eerst op license (rijder kan tussentijds een
                            // license toegekend hebben gekregen), dan op cat.
                            let opnieuw = -1;
                            if (eerderLic) {
                                opnieuw = data.findIndex(d => d?.persoon?.license_key === eerderLic);
                            }
                            if (opnieuw < 0 && eerderCat) {
                                opnieuw = data.findIndex(d => d?.persoon?.category === eerderCat);
                            }
                            k.kozen_idx = opnieuw >= 0 ? opnieuw : Math.min(k.kozen_idx ?? 0, data.length - 1);
                        } else {
                            k.kozen_idx = 0;
                        }
                    }
                } catch { /* stil — volgende tick probeert opnieuw */ }
            });
            await Promise.all(kindRefreshes);
            // Globale state synchroniseren met actieve kind
            const actief = _kinderen[_activeKindIdx];
            if (actief) {
                window._lookupData = actief.data;
                window._lookupSnr  = actief.snr;
                window._lookupProg = actief.prog;
                window._gekozenIdx = actief.kozen_idx ?? 0;
            }
            // Bewaar dropdown-keuzes (Uitslagen-tab) van actieve kind vóór
            // re-render — initUitslagenTab() zet ze daarna terug zodat de
            // gebruiker zijn categorie/afstand niet kwijtraakt elke 60s.
            _bewaarKindUistate();
            renderKinderen();
            zetStempel();
        } catch { /* stil */ }
    };

    // Bereken interval op basis van consecutiveFails: bij fouten progressief
    // langer wachten zodat we de server niet hameren als hij eruit ligt.
    // 0 fouten → 60s, 1 → 60s, 2 → 90s, 3+ → 120s. Bij herstel meteen terug
    // naar 60s (gebeurt automatisch want consecutiveFails reset op succes).
    const _tickInterval = () => {
        const f = _conn.consecutiveFails;
        if (f >= 3) return Math.max(AUTO_REFRESH_MS, 120_000);
        if (f === 2) return Math.max(AUTO_REFRESH_MS, 90_000);
        return AUTO_REFRESH_MS;
    };

    const _scheduleTick = () => {
        stop();
        if (!selComp.value || document.hidden) return;
        autoTick = setTimeout(async () => {
            autoTick = null;
            if (document.hidden || !selComp.value) return _scheduleTick();
            await stilleRefresh();
            _scheduleTick();
        }, _tickInterval());
    };

    const start = () => _scheduleTick();

    const stop = () => {
        if (autoTick) { clearTimeout(autoTick); autoTick = null; }
    };

    // Hook voor _conn: bij online-event direct refresh + scheduling resetten.
    _conn.refreshHook = () => {
        if (selComp.value && !document.hidden) {
            stilleRefresh().finally(_scheduleTick);
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else {
            // Eén-shot check (ook globale meldingen) bij terugkeer naar tab.
            stilleRefresh();
            start();
        }
    });

    selComp.addEventListener('change', () => {
        if (selComp.value) {
            zetStempel();
            start();
            // Direct meldingen ophalen — niet wachten op eerste poll-tick.
            checkMeldingen(selComp.value);
        } else {
            stop();
            wisStempel();
            // Switch terug naar landing → één-malig globale meldingen ophalen.
            checkMeldingen('');
        }
    });

    // Initieel: stempel + auto-tick alleen als wedstrijd voorgeselecteerd.
    // Globale meldingen-check loopt sowieso bij page-open.
    if (selComp.value) { zetStempel(); start(); }
    checkMeldingen(selComp.value || '');

    // ── Pull-to-refresh ────────────────────────────────────────────────────
    // Patroon overgenomen uit coach/index.php: alleen actief boven aan de
    // pagina, met 70 px slepen-drempel. Wikkelt stilleRefresh + zetStempel
    // in een ptrEl-status-flow.
    const ptrEl = document.getElementById('ptr');
    const PTR_DREMPEL = 70;
    const PTR_COOLDOWN_MS = 30_000;  // min tijd tussen 2 PTR-acties
    let ptrStartY = null, ptrDragY = 0, ptrActief = false, ptrBezig = false;
    let ptrLaatste = 0;

    async function ptrHerlaad() {
        if (!selComp.value || ptrBezig) return;
        // Cooldown: bij PTR < 30s na vorige tonen we kort een melding ipv
        // de server opnieuw aanroepen. Voorkomt burst bij ongeduld of
        // per-ongeluk-twee-keer-pullen.
        const sindsLaatste = Date.now() - ptrLaatste;
        if (ptrLaatste && sindsLaatste < PTR_COOLDOWN_MS) {
            const wachten = Math.ceil((PTR_COOLDOWN_MS - sindsLaatste) / 1000);
            ptrEl.classList.add('laadt');
            ptrEl.textContent = t('ptr_wachten', {s: wachten});
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 1200);
            return;
        }
        ptrBezig = true;
        ptrEl.classList.add('laadt');
        ptrEl.textContent = t('ptr_vernieuwen');
        try {
            await stilleRefresh();
            zetStempel();
            ptrLaatste = Date.now();
            ptrEl.textContent = t('ptr_bijgewerkt');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 600);
        } catch {
            ptrEl.textContent = t('ptr_fout');
            setTimeout(() => { ptrEl.classList.remove('zichtbaar', 'laadt'); }, 1200);
        } finally {
            ptrBezig = false;
        }
    }

    document.addEventListener('touchstart', e => {
        if (window.scrollY > 0 || ptrBezig || !selComp.value) { ptrStartY = null; return; }
        if (e.touches.length !== 1) { ptrStartY = null; return; }
        ptrStartY = e.touches[0].clientY;
        ptrDragY = 0;
        ptrActief = false;
    }, { passive: true });

    document.addEventListener('touchmove', e => {
        if (ptrStartY === null) return;
        ptrDragY = e.touches[0].clientY - ptrStartY;
        if (ptrDragY <= 0) {
            if (ptrActief) { ptrEl.classList.remove('zichtbaar'); ptrActief = false; }
            return;
        }
        // Actief naar beneden trekken vanaf scroll-top: blokkeer native
        // browser-PTR door preventDefault() (vereist passive:false).
        if (e.cancelable) e.preventDefault();
        if (ptrDragY > 30 && !ptrActief) { ptrEl.classList.add('zichtbaar'); ptrActief = true; }
        ptrEl.textContent = ptrDragY >= PTR_DREMPEL
            ? t('ptr_laat_los') : t('ptr_trek');
    }, { passive: false });

    document.addEventListener('touchend', () => {
        if (ptrStartY === null) return;
        const was = ptrDragY;
        ptrStartY = null; ptrDragY = 0;
        if (ptrActief && was >= PTR_DREMPEL) {
            ptrHerlaad();
        } else if (ptrActief) {
            ptrEl.classList.remove('zichtbaar'); ptrActief = false;
        }
    });

    // Desktop-fallback: dubbelklik op de header refreshed ook
    document.querySelector('header')?.addEventListener('dblclick', ptrHerlaad);
})();

// ── PWA: service worker ───────────────────────────────────────────────────
// Update-flow: SW is network-only met cache-cleanup bij activate (zie sw.js).
// reg.update() bij visibility-change zorgt dat browsers nieuwe SW oppikken,
// maar GEEN automatische window.reload() — die wiste input-velden tijdens
// typen (regressie 2026-05-27: gebruikers konden niet meer op zoeken
// klikken doordat hun startnummer-input mid-typen werd gewist).
// Gevolg: nieuwe versie verschijnt pas bij volgende natuurlijke refresh
// of nav. Voor browser-users zorgen PHP no-cache headers dat dat altijd
// vers is. Voor PWA-users: tab sluiten/openen.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').then(reg => {
        const checkUpdate = () => { try { reg.update(); } catch {} };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') checkUpdate();
        });
        setInterval(checkUpdate, 5 * 60 * 1000);
    }).catch(() => {});
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
