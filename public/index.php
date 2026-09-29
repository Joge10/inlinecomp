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
require_once __DIR__ . '/../inc/maintenance.php'; maintenanceGate($pdo);   // onderhoudsmodus
require_once __DIR__ . '/../inc/person_id.php';   // person_id-resolutie (fase 3d-iii)
require_once __DIR__ . '/../inc/anoniem.php';      // publieke anonimiteit (variant B)
require_once __DIR__ . '/../inc/versie.php';

// ── Bezoektracking: upsert session-hit in public_visits ─────────────────────
// HTML-pageload → full INSERT/UPDATE (hits+1, user_agent, peak-check).
// AJAX-call    → alleen last_seen bumpen, rate-limited op 30 sec zodat een
//                click-storm niet resulteert in 100 UPDATEs. Peak/hourly
//                reflecteren zo écht-actief-zijn ipv alleen page-refreshes.
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
        if (empty($_GET['action'])) {
            // HTML pageload: user_agent alleen bij eerste INSERT — verandert
            // niet binnen dezelfde sessie, en dubbele UPDATE zou legitieme
            // UA overschrijven als $_SERVER onverwacht leeg is.
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $pdo->prepare(
                "INSERT INTO public_visits (session_id, user_agent) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE last_seen = NOW(), hits = hits + 1"
            )->execute([$sid, $ua]);
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
        } else {
            // AJAX: last_seen bumpen, alleen als vorige update > 30s geleden.
            // Voorkomt UPDATE-storm bij snel-klikkende bezoekers, terwijl de
            // sessie wel als "actief" blijft gelden binnen het 5-min-window
            // dat peak/hourly gebruiken.
            $pdo->prepare(
                "UPDATE public_visits SET last_seen = NOW()
                 WHERE session_id = ? AND last_seen < NOW() - INTERVAL 30 SECOND"
            )->execute([$sid]);
        }
    } catch (Throwable $e) { /* tracking mag nooit de pagina breken */ }
}

// ── Wedstrijd-zichtbaarheidsgate ─────────────────────────────────────────────
// /public toont alleen wedstrijden waarvoor public_zichtbaar=1. De
// competitions-list-action filtert zelf al; deze gate beschermt single-
// comp endpoints (programma, lookup, uitslagen, etc.) tegen URL-pluk
// van een wedstrijd in voorbereidingsfase.
// Demo-URL (?demo): alleen demo-wedstrijden zijn toegankelijk; normaal alleen
// niet-demo én gepubliceerd. Zo lekt een demo nooit naar gewone bezoekers en
// zijn echte wedstrijden onzichtbaar in demo-modus.
function _publicWedstrijdZichtbaar(PDO $pdo, string $compId): bool {
    if (!$compId) return true;
    $demo = !empty($_GET['demo']);
    $s = $pdo->prepare("SELECT public_zichtbaar, is_demo FROM competitions WHERE id = ? LIMIT 1");
    $s->execute([$compId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    if ($demo) return (bool)$r['is_demo'];
    return !$r['is_demo'] && (bool)$r['public_zichtbaar'];
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
        // Demo-URL (?demo): toon ALLEEN demo-wedstrijden; anders alleen echte
        // (niet-demo) die gepubliceerd/aangekondigd zijn.
        $demo = !empty($_GET['demo']);
        $whereZicht = $demo
            ? "c.is_demo = 1"
            : "c.is_demo = 0 AND (c.public_zichtbaar = 1 OR c.public_aankondigen = 1)";
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
            WHERE $whereZicht
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
    // Cache-Control: public, no-cache.
    // "no-cache" is misleidend genoemd — het betekent NIET "niet cachen" maar
    // "cachen mag, maar valideer altijd eerst bij de server". Precies wat we
    // hier willen: elke poll checkt met If-None-Match → server antwoordt 304
    // als de fingerprint gelijk is (goedkope round-trip), 200 met nieuwe body
    // als er iets muteerde. Zonder no-cache zou Chrome binnen max-age blind
    // uit disk cache serveren, en dan zou een operator-mutatie (rit-swap,
    // combineren, tijden invoeren) tot max-age-verstrijken onzichtbaar zijn.
    // Voor een live wedstrijd-app is dat een correctness-issue.
    header('Cache-Control: public, no-cache');
    // session_start() eerder in deze file zet default 'nocache'-headers
    // (Pragma: no-cache + Expires: 1981). Die conflicteren met onze
    // Cache-Control en verwarren tussen-caches / proxies. Voor déze endpoint
    // (publieke read-only data, geen sessie-gebonden inhoud) willen we ze niet.
    header_remove('Pragma');
    header_remove('Expires');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        // Tijdschema-id + tijdschema_version in één lookup. tijdschema_version
        // (op competitions) is de canonical teller die door ELKE tijdschema-
        // mutatie wordt gebumpt (rit-swap, blok-schuif, combineren, ronde-
        // config wijzigen, etc. — zie api/tijdschema.php). Betrouwbaarder dan
        // competition_tijdschema.updated_at, dat sommige mutaties (bv.
        // ritten-combineren) niet vangt.
        $tsStmt = $pdo->prepare("
            SELECT ct.id, c.tijdschema_version
            FROM competition_tijdschema ct
            JOIN competitions c ON c.id = ct.competition_id
            WHERE ct.competition_id = ?
            LIMIT 1
        ");
        $tsStmt->execute([$compId]);
        $tsRow = $tsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tsRow) { echo json_encode([]); exit; }
        $tsId  = $tsRow['id'];
        $tsVer = (int)($tsRow['tijdschema_version'] ?? 0);

        // ── ETag / Last-Modified fingerprint ────────────────────────────────
        // Compact "state-getal" dat elke wijziging aan de programma-output vangt:
        //  - tsVer   : competitions.tijdschema_version (tijdschema-mutaties,
        //              incl. combineren, blokken, doorstroom-config)
        //  - res_ts  : max(results.updated_at) — tijden invoeren, sancties
        //  - h_cnt   : COUNT(heats) — heats hebben geen updated_at,
        //              count vangt insert/delete via ronde-generatie / wissen
        //  - he_cnt  : COUNT(heat_entries) — vangt loting/afmelding
        //  - r_cnt   : COUNT(results met finishpositie) — vangt uitslag-input
        // APP_VERSIE erin zodat een release automatisch alle caches invalideert.
        $fpStmt = $pdo->prepare("
            SELECT
                UNIX_TIMESTAMP(COALESCE((
                    SELECT MAX(res.updated_at)
                    FROM results res
                    JOIN heat_entries he ON he.id = res.heat_entry_id
                    JOIN heats h ON h.id = he.heat_id
                    WHERE h.competition_id = ?
                ), '1970-01-01 00:00:00')) AS res_ts,
                (SELECT COUNT(*) FROM heats WHERE competition_id = ?) AS h_cnt,
                (SELECT COUNT(*) FROM heat_entries he
                    JOIN heats h ON h.id = he.heat_id
                    WHERE h.competition_id = ?) AS he_cnt,
                (SELECT COUNT(*) FROM results res
                    JOIN heat_entries he ON he.id = res.heat_entry_id
                    JOIN heats h ON h.id = he.heat_id
                    WHERE h.competition_id = ? AND res.finishpositie IS NOT NULL) AS r_cnt
        ");
        $fpStmt->execute([$compId, $compId, $compId, $compId]);
        $fp = $fpStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $resTs = (int)($fp['res_ts'] ?? 0);
        $hCnt  = (int)($fp['h_cnt']  ?? 0);
        $heCnt = (int)($fp['he_cnt'] ?? 0);
        $rCnt  = (int)($fp['r_cnt']  ?? 0);
        // Last-Modified: alleen res_ts is een echt tijdstip. tsVer is een
        // integer-teller die niet naar een datum te mappen is; die vangen we
        // via de ETag zelf.
        $lastMod = $resTs;

        $appVer = defined('APP_VERSIE') ? APP_VERSIE : '0';
        // Weak ETag (W/) — response is functioneel identiek maar niet byte-
        // gegarandeerd (PHP-numerieke serialisatie, sortering blijft stabiel
        // door ORDER BY, maar json_encode kan bij lib-updates minimaal
        // verschillen — weak is de veilige keus voor JSON-payloads).
        $etag = sprintf(
            'W/"prg-%s-v%d-r%d-h%d-e%d-f%d"',
            $appVer, $tsVer, $resTs, $hCnt, $heCnt, $rCnt
        );
        $lastModHttp = $lastMod > 0 ? gmdate('D, d M Y H:i:s \G\M\T', $lastMod) : null;

        // Revalidatie-check: If-None-Match heeft voorrang op If-Modified-Since
        // (RFC 7232 §6). Bij match sturen we 304 zonder body — client hergebruikt
        // zijn cached versie.
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $ifModSince  = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        $notModified = false;
        if ($ifNoneMatch !== '') {
            // Client mag meerdere ETags sturen ("a", "b"); trim quotes en check
            // elk. Weak-prefix W/ mag genegeerd worden bij vergelijking (RFC 7232 §2.3.2).
            foreach (explode(',', $ifNoneMatch) as $tag) {
                $tag = trim($tag);
                if ($tag === '' ) continue;
                if ($tag === '*' || ltrim($tag, 'W/') === ltrim($etag, 'W/')) {
                    $notModified = true; break;
                }
            }
        } elseif ($ifModSince !== '' && $lastModHttp !== null) {
            $ims = strtotime($ifModSince);
            if ($ims !== false && $ims >= $lastMod) $notModified = true;
        }

        header('ETag: ' . $etag);
        if ($lastModHttp !== null) header('Last-Modified: ' . $lastModHttp);

        if ($notModified) {
            http_response_code(304);
            exit;
        }

        // ── Hoofd-query: pre-aggregate JOINs ipv correlated subqueries ─────
        // Was: twee (SELECT COUNT(*) ...) subqueries per row in de SELECT-list
        //      → veroorzaakte ~70% van de request-tijd (X-Ray: 143ms van 205ms).
        // Nu: derived tables die éénmalig per query aggregeren, gescoped op
        //     deze wedstrijd via h.competition_id. Zelfde resultaat, veel
        //     minder werk voor MariaDB.
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
                   d.name AS distance_naam,
                   COALESCE(he_agg.cnt, 0) AS entries_count,
                   COALESCE(res_agg.cnt, 0) AS resultaten_count
            FROM tijdschema_ritten r
            LEFT JOIN tijdschema_blokken b ON b.id = r.blok_id
            LEFT JOIN heats h ON h.tijdschema_rit_id = r.id AND h.competition_id = ?
            LEFT JOIN (
                SELECT he.heat_id, COUNT(*) AS cnt
                FROM heat_entries he
                JOIN heats h2 ON h2.id = he.heat_id
                WHERE h2.competition_id = ?
                GROUP BY he.heat_id
            ) he_agg ON he_agg.heat_id = h.id
            LEFT JOIN (
                SELECT he.heat_id, COUNT(*) AS cnt
                FROM results res
                JOIN heat_entries he ON he.id = res.heat_entry_id
                JOIN heats h3 ON h3.id = he.heat_id
                WHERE h3.competition_id = ? AND res.finishpositie IS NOT NULL
                GROUP BY he.heat_id
            ) res_agg ON res_agg.heat_id = h.id
            -- distances heeft samengestelde PK (dc_id, id) — join MOET beide
            -- kolommen meenemen, anders 1-op-N per DC met dezelfde afstand.
            -- Zie waarschuwing in db/distances.sql.
            LEFT JOIN distances d
                ON d.id = COALESCE(h.distance_id, r.distance_id)
               AND d.distance_combination_id = COALESCE(h.distance_combination_id, r.dc_id)
            WHERE r.tijdschema_id = ?
            ORDER BY r.volgorde
        ");
        $stmt->execute([$compId, $compId, $compId, $tsId]);
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
                   p.person_id AS license_key, p.person_id,
                   p.full_name, p.category, p.publiek_anoniem,
                   res.finishpositie, res.tijd_ms, res.sanctie,
                   res.rondes, res.punten AS pk_punten,
                   ua.rang AS uitslag_rang
            FROM heat_entries he
            JOIN persons p ON p.person_id = he.person_id
            LEFT JOIN competition_startnummers cs ON cs.person_id = he.person_id AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            LEFT JOIN uitslag_afstand ua ON ua.person_id = he.person_id
                AND ua.competition_id = ? AND ua.distance_combination_id = ? AND ua.distance_id = ?
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");
        $rStmt->execute([$compId, $compId, $dcId, $distId, $heat['id']]);
        // Anonimiteit (variant B): publieke heat-weergave → public-venster maskering.
        [$cStarts, $cEnds] = anoniemCompVenster($pdo, $compId);
        $heat['rijders'] = array_map(function ($r) use ($cStarts, $cEnds) {
            return pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
        }, $rStmt->fetchAll(PDO::FETCH_ASSOC));

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
        // om te voorkomen dat bv. "Jorn" matcht in voornamen van andere rijders.
        // Beperkt tot deelnemers van DEZE wedstrijd (AVG-dataminimalisatie: toon
        // niet de hele rijdersdatabase aan willekeurig publiek + functioneel
        // relevanter). Bestaande gevolgde rijders die deze keer niet meedoen
        // blijven in de persoonlijke lijst via de license-lookup hieronder.
        // `in_wedstrijd` blijft 1 voor frontend-compatibiliteit.
        $stmt = $pdo->prepare("
            SELECT p.person_id AS license_key, p.person_id, p.full_name, p.short_name,
                   p.category, p.club_short,
                   COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr,
                   1 AS in_wedstrijd
            FROM persons p
            LEFT JOIN competition_startnummers cs
                   ON cs.person_id = p.person_id AND cs.competition_id = ?
            WHERE p.short_name LIKE ?
              -- Publiek anonieme rijder is NOOIT op naam vindbaar (variant B,
              -- laag 'altijd'): weglaten i.p.v. maskeren, zodat de zoek ook niet
              -- bevestigt dát iemand meedoet/anoniem is. Toevoegen aan de
              -- volglijst kan alleen via het onraadbare person_id (GUID).
              AND p.publiek_anoniem IS NULL
              AND EXISTS (
                       SELECT 1 FROM entries e
                       JOIN distance_combinations dc
                         ON dc.id = e.distance_combination_id
                       WHERE e.person_id = p.person_id
                         AND dc.competition_id = ?
                  )
            ORDER BY p.short_name, p.full_name
            LIMIT 30
        ");
        $stmt->execute([$compId, '%' . $term . '%', $compId]);
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
    // Volglijst draait sinds fase 3c/3d op person_id. Het inkomende token
    // (license_key óf person_id) wordt universeel naar person_id geresolved; de
    // queries draaien op person_id → fase-4-proof.
    // Entitlement (variant B): de naam van een anonieme rijder wordt ALLEEN
    // ontsloten door het geheime volg-token (?volg=). person_id/licentie geven
    // GEEN entitlement meer — dat was het lek (person_id is publiek, dus wie je
    // ooit volgde had 'm en bleef de naam zien). Zie migratie …_volg_token.sql.
    $volgTok = trim($_GET['volg'] ?? '');
    $token   = trim($_GET['license_key'] ?? '') ?: trim($_GET['person_id'] ?? '');
    $pid      = '';
    $entitled = false;
    if ($volgTok !== '') {
        $pid = (string)(personIdVoorVolgToken($pdo, $volgTok) ?? '');
        $entitled = ($pid !== '');
    } elseif ($token !== '') {
        $pid = (string)(resolveNaarPersonId($pdo, $token) ?? '');
    }
    if (($volgTok !== '' || $token !== '') && $pid === '') {
        echo json_encode(['error' => 'Geen rijder gevonden voor deze rijder in deze wedstrijd']);
        exit;
    }

    if (!$compId || (!$snr && $pid === '')) {
        echo json_encode(['error' => 'competition_id en startnummer of license_key zijn verplicht']);
        exit;
    }

    try {
        if ($pid !== '') {
            // License-zoek is niet gebonden aan deelname in deze wedstrijd —
            // zo kunnen ouders kinderen alvast toevoegen die nog niet
            // ingeschreven zijn (of deze wedstrijd overslaan). entry_status
            // wordt NULL als er geen inschrijving is voor deze comp; de
            // frontend toont dan een "niet ingeschreven"-placeholder.
            $persStmt = $pdo->prepare("
                SELECT p.person_id AS license_key, p.person_id, p.full_name, p.category, p.start_number,
                       p.club_short, p.publiek_anoniem,
                       COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr,
                       (SELECT MAX(e.status)
                          FROM entries e
                          JOIN distance_combinations dc
                            ON dc.id = e.distance_combination_id
                         WHERE e.person_id = p.person_id
                           AND dc.competition_id = ?) AS entry_status
                FROM persons p
                LEFT JOIN competition_startnummers cs
                       ON cs.person_id = p.person_id AND cs.competition_id = ?
                WHERE p.person_id = ?
            ");
            $persStmt->execute([$compId, $compId, $pid]);
        } else {
            $persStmt = $pdo->prepare("
                SELECT p.person_id AS license_key, p.person_id, p.full_name, p.category, p.start_number,
                       p.club_short, p.publiek_anoniem,
                       COALESCE(cs.startnummer, p.start_number) AS wedstrijd_snr,
                       e.status AS entry_status
                FROM persons p
                LEFT JOIN competition_startnummers cs ON cs.person_id = p.person_id AND cs.competition_id = ?
                JOIN entries e ON e.person_id = p.person_id
                JOIN distance_combinations dc ON dc.id = e.distance_combination_id AND dc.competition_id = ?
                WHERE (p.start_number = ? OR cs.startnummer = ?)
                GROUP BY p.person_id
            ");
            $persStmt->execute([$compId, $compId, $snr, $snr]);
        }
        $personen = $persStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$personen) {
            $omschr = $pid !== '' ? 'deze rijder' : "startnummer $snr";
            echo json_encode(['error' => "Geen rijder gevonden voor $omschr in deze wedstrijd"]);
            exit;
        }

        // ── Anonimiteit (variant B) ──────────────────────────────────────────
        // Wedstrijd-datums voor het publieke venster [wedstrijddag −1 … +1].
        [$cStarts, $cEnds] = anoniemCompVenster($pdo, $compId);

        // Entitled (kijker heeft het GUID) → onthoud de person_id('s) zodat het
        // eigen kind óók in de heat-grid hieronder niet gemaskeerd wordt. De
        // subject-rij zelf wordt pas bij het bouwen van de output gemaskeerd
        // (hieronder leunen dc-status/heat-queries nog op $p['license_key']).
        $entitledPids = [];
        if ($entitled) {
            foreach ($personen as $pp) {
                if (!empty($pp['person_id'])) $entitledPids[$pp['person_id']] = true;
            }
        }

        // Status PER DC voor de rijder-header. Een rijder kan in meerdere DC's
        // (afstanden) staan met verschillende status — bv. voor één afstand
        // afgemeld. De frontend toont bij gelijke status één badge (zoals nu),
        // en bij verschil per DC (DC-naam + status).
        $dcStatusStmt = $pdo->prepare("
            SELECT dc.name AS dc_naam, e.status
            FROM entries e
            JOIN distance_combinations dc ON dc.id = e.distance_combination_id
            WHERE e.person_id = ? AND dc.competition_id = ?
            ORDER BY dc.number, dc.name
        ");
        foreach ($personen as &$pp) {
            $dcStatusStmt->execute([$pp['license_key'], $compId]);
            $pp['dc_statussen'] = $dcStatusStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($pp);

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
            WHERE he.person_id = ? AND h.competition_id = ?
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
                   p.person_id AS license_key, p.person_id,
                   p.full_name, p.category, p.publiek_anoniem,
                   res.finishpositie, res.tijd_ms,
                   res.bruto_tijd_ms, res.is_photofinish, res.sanctie,
                   res.rondes, res.punten AS pk_punten,
                   ua.rang AS uitslag_rang
            FROM heat_entries he
            JOIN persons p ON p.person_id = he.person_id
            LEFT JOIN competition_startnummers cs ON cs.person_id = he.person_id AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            LEFT JOIN (
                SELECT ua1.person_id, ua1.rang
                FROM uitslag_afstand ua1
                INNER JOIN (
                    SELECT MAX(id) AS max_id
                    FROM uitslag_afstand
                    WHERE competition_id = ?
                      AND distance_combination_id = ?
                      AND distance_id = ?
                    GROUP BY person_id
                ) latest ON latest.max_id = ua1.id
            ) ua ON ua.person_id = he.person_id
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");

        $uitslagStmt = $pdo->prepare("
            SELECT t.distance_naam, t.rang, t.punten, t.sanctie, t.finale_naam
            FROM uitslag_afstand t
            INNER JOIN (
                SELECT distance_id, MAX(id) AS max_id
                FROM uitslag_afstand
                WHERE person_id = ? AND competition_id = ?
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
                WHERE person_id = ? AND competition_id = ?
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
                $h['rijders'] = array_map(function ($r) use ($cStarts, $cEnds, $entitledPids) {
                    // Heat-genoten volgen de public-venster-regel. Alleen het eigen
                    // kind (entitled via GUID) blijft ook buiten het venster zichtbaar.
                    $ent = !empty($r['person_id']) && isset($entitledPids[$r['person_id']]);
                    return pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, $ent, ['naam' => ['full_name']]);
                }, $rijdersStmt->fetchAll(PDO::FETCH_ASSOC));
                $heats[] = $h;
            }

            $uitslagStmt->execute([$lic, $compId]);
            $uitslagen = $uitslagStmt->fetchAll(PDO::FETCH_ASSOC);
            $klasStmt->execute([$lic, $compId]);
            $klassementen = $klasStmt->fetchAll(PDO::FETCH_ASSOC);

            // Subject-rij nu pas maskeren (queries hierboven zijn klaar). Entitled
            // (geldig volg-token) → echte naam; anders public-venster.
            $persoonOut = pasAnonimiteitToe($p, 'public', $cStarts, $cEnds, $entitled,
                                            ['naam' => ['full_name'], 'wis' => ['club_short']]);
            // Bij een entitled volg-lookup het token terugleveren, zodat de
            // volglijst het kan bewaren en bij herladen weer via ?volg= ophaalt
            // (person_id zou immers géén naam meer ontsluiten).
            if ($entitled && $volgTok !== '') $persoonOut['volg_token'] = $volgTok;
            $resultaten[] = [
                'persoon'      => $persoonOut,
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
    // Optionele categorie-filter: bij gecombineerde DC (bv 'DSA+HSA') geven
    // (dc_id, distance_id) alleen niet genoeg om per-cat ranking te tonen —
    // uitslag_afstand bevat rijders van beide cats. Frontend geeft de
    // gekozen cat mee zodat we op p.category kunnen filteren.
    $catFilter = trim($_GET['categorie'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }

    // Anonimiteit (variant B): per-wedstrijd dag-uitslag/klassement volgt het
    // public-venster [wedstrijddag −1 … +1].
    [$cStarts, $cEnds] = anoniemCompVenster($pdo, $compId);

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
            $catWhere = $catFilter !== '' ? ' WHERE p.category = ?' : '';
            $stmt = $pdo->prepare("
                SELECT t.rang, t.punten_totaal, t.dc_naam, t.punten_detail,
                       p.person_id, p.publiek_anoniem,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr
                FROM uitslag_klassement t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_id
                    FROM uitslag_klassement
                    WHERE competition_id = ? AND distance_combination_id = ?
                    GROUP BY person_id
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.person_id = t.person_id
                LEFT JOIN competition_startnummers cs ON cs.person_id = t.person_id AND cs.competition_id = ?
                $catWhere
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang, t.punten_totaal
            ");
            $params = [$compId, $dcId, $compId];
            if ($catFilter !== '') $params[] = $catFilter;
            $stmt->execute($params);
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
                $r = pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
            }
            unset($r);

            echo json_encode(['rijders' => $rijders, 'afstanden' => $afstanden], JSON_UNESCAPED_UNICODE);
        } else {
            if (!$distId) { echo json_encode(['error' => 'distance_id verplicht voor type=afstand']); exit; }
            $catWhere = $catFilter !== '' ? ' WHERE p.category = ?' : '';
            $stmt = $pdo->prepare("
                SELECT t.rang, t.finale_naam, t.tijd_ms, t.sanctie,
                       t.distance_naam,
                       p.person_id, p.publiek_anoniem,
                       p.full_name, p.category AS categorie,
                       COALESCE(cs.startnummer, p.start_number) AS snr,
                       res_agg.rondes, res_agg.pk_punten
                FROM uitslag_afstand t
                INNER JOIN (
                    SELECT MAX(id) AS max_id, person_id
                    FROM uitslag_afstand
                    WHERE competition_id = ? AND distance_combination_id = ? AND distance_id = ?
                    GROUP BY person_id
                ) latest ON latest.max_id = t.id
                JOIN persons p ON p.person_id = t.person_id
                LEFT JOIN competition_startnummers cs ON cs.person_id = t.person_id AND cs.competition_id = ?
                LEFT JOIN (
                    SELECT he.person_id, res.rondes, res.punten AS pk_punten
                    FROM heat_entries he
                    JOIN heats h ON h.id = he.heat_id
                    JOIN results res ON res.heat_entry_id = he.id
                    WHERE h.competition_id = ? AND h.distance_combination_id = ?
                      AND COALESCE(h.distance_id, '') = ?
                      AND (res.rondes IS NOT NULL OR res.punten IS NOT NULL)
                    ORDER BY res.id DESC
                ) res_agg ON res_agg.person_id = t.person_id
                $catWhere
                ORDER BY CASE WHEN t.rang IS NULL THEN 1 ELSE 0 END, t.rang
            ");
            $params = [$compId, $dcId, $distId, $compId, $compId, $dcId, $distId];
            if ($catFilter !== '') $params[] = $catFilter;
            $stmt->execute($params);
            $rijders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Dedup (res_agg kan meerdere rijen geven). Dedup op de ECHTE naam+snr
            // vóór maskering — daarna pas anonimiteit toepassen (anders vallen
            // verschillende anonieme rijders samen als 'Anoniem'+snr).
            $seen = [];
            $unique = [];
            foreach ($rijders as $r) {
                $lic = $r['full_name'] . $r['snr'];
                if (isset($seen[$lic])) continue;
                $seen[$lic] = true;
                $unique[] = pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
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

// ── API: ronde-uitslagen (per afstand per ronde de complete uitslag) ────────
// Voor de publieke resultaten-tab: per afstand blok, per ronde sub-blok met
// Q/q-berekening en volledige rijder-lijst. Ook eind-uitslag uit
// uitslag_afstand voor de finale-klassering. Runner-up wordt gecombineerd
// (RU1 → RU2 → …) met globale eindposities (bv. plek 9-16).
// ── API: categorieën + afstanden voor Uitslagen-tab ─────────────────────────
// Anders dan /categorieen (die op DC-naam werkt, bv "DP4+DP3" bij combi):
// hier per persoons-categorie (DP4, DP3, DKA, DJB, …) een lijst afstanden
// met bijbehorende dc_id. Klassementen per unieke DC binnen elke categorie.
// Gesorteerd jongst → oudst, dames vóór heren — internationaal leesbaarder
// dan alfabetisch (DJB = Youth, DJA = Junior). Zelfde payload als /coach.
if ($action === 'rondes_cats') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');
    $compId = trim($_GET['competition_id'] ?? '');
    if (!$compId) { echo json_encode(['error' => 'competition_id verplicht']); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                   p.category           AS categorie,
                   d.id                 AS distance_id,
                   d.name               AS distance_naam,
                   d.value_meters,
                   d.number,
                   d.distance_combination_id AS dc_id,
                   dc.name              AS dc_naam
            FROM heats h
            JOIN heat_entries he ON he.heat_id = h.id
            JOIN persons p       ON p.person_id = he.person_id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            -- distances heeft compound PK (distance_combination_id, id).
            -- Dezelfde distance_id komt bewust in meerdere DCs voor voor
            -- cross-DC aggregatie. JOIN moet daarom ook op DC, anders
            -- claimt bv HSA per ongeluk de DP4-versie van de distance.
            JOIN distances d  ON d.id  = COALESCE(h.distance_id, tsr.distance_id)
                             AND d.distance_combination_id = h.distance_combination_id
            JOIN distance_combinations dc ON dc.id = d.distance_combination_id
            WHERE h.competition_id = ?
              AND p.category IS NOT NULL AND p.category <> ''
            ORDER BY p.category, d.number, d.name
        ");
        $stmt->execute([$compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gepubliceerde klassement-DC's — voor "🏆 Klassement"-optie.
        $klasStmt = $pdo->prepare("
            SELECT DISTINCT dc_id
            FROM klassement_config
            WHERE competition_id = ? AND gepubliceerd_at IS NOT NULL
        ");
        $klasStmt->execute([$compId]);
        $klasDcIds = $klasStmt->fetchAll(PDO::FETCH_COLUMN);
        $klasSet = array_flip($klasDcIds);

        // Sorteersleutel: jongste → oudste categorie, dames vóór heren per
        // leeftijd. Zelfde logica als jury (_juryCatSortKey) en coach.
        $catSortKey = function(string $cat): int {
            $cat = strtoupper(trim($cat));
            if (preg_match('/^([HD]?)M(\d{2,3})$/', $cat, $m)) {
                $genderRank = match($m[1]) { 'D' => 0, 'H' => 1, default => 1 };
                $leeftijd = (int)$m[2];
                if ($leeftijd >= 40) {
                    $ageRank = 10 + intdiv($leeftijd - 40, 5);
                    return $ageRank * 10 + $genderRank;
                }
            }
            $genderRank = match(substr($cat, 0, 1)) { 'D' => 0, 'H' => 1, default => 9 };
            $sub = substr($cat, 1);
            $ageRank = match($sub) {
                'P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3,
                'KA' => 4, 'JB' => 5, 'JA' => 6,
                'SJ' => 7, 'SA' => 8, 'SB' => 9,
                default => 99,
            };
            return $ageRank * 10 + $genderRank;
        };

        // Eerst per DC de cats verzamelen.
        $dcCats = []; $dcNaam = []; $dcAfstanden = [];
        foreach ($rows as $r) {
            $dcId = $r['dc_id'];
            $dcNaam[$dcId] = $r['dc_naam'];
            if (!isset($dcCats[$dcId])) $dcCats[$dcId] = [];
            if (!in_array($r['categorie'], $dcCats[$dcId], true)) $dcCats[$dcId][] = $r['categorie'];
            if (!isset($dcAfstanden[$dcId])) $dcAfstanden[$dcId] = [];
            $al = false;
            foreach ($dcAfstanden[$dcId] as $a) {
                if ($a['distance_id'] === $r['distance_id']) { $al = true; break; }
            }
            if (!$al) {
                $dcAfstanden[$dcId][] = [
                    'distance_id'   => $r['distance_id'],
                    'distance_naam' => $r['distance_naam'],
                ];
            }
        }
        // Groepeer per cat-signatuur (bv "HJA+HSA"): meerdere DC's met
        // dezelfde cat-samenstelling worden ÉÉN dropdown-optie. Afstanden
        // uit alle DC's samenvoegen, elk met eigen dc_id voor de fetch.
        $perSig = [];
        foreach ($dcCats as $dcId => $cats) {
            $sorted = $cats;
            usort($sorted, fn($a, $b) => $catSortKey($a) - $catSortKey($b));
            $sig = implode('+', $sorted);
            if (!isset($perSig[$sig])) {
                $perSig[$sig] = [
                    'sig' => $sig,
                    'label' => implode(' + ', $sorted),
                    'categorieen' => $sorted,
                    'afstanden' => [],
                    'klassementen' => [],
                    '_sortkey' => $catSortKey($sorted[0] ?? ''),
                ];
            }
            foreach ($dcAfstanden[$dcId] as $a) {
                $perSig[$sig]['afstanden'][] = [
                    'distance_id'   => $a['distance_id'],
                    'distance_naam' => $a['distance_naam'],
                    'dc_id'         => $dcId,
                ];
            }
            if (isset($klasSet[$dcId])) {
                $perSig[$sig]['klassementen'][] = [
                    'dc_id'   => $dcId,
                    'dc_naam' => $dcNaam[$dcId],
                ];
            }
        }
        foreach ($perSig as &$sig) {
            usort($sig['afstanden'], fn($a, $b) => strnatcmp($a['distance_naam'] ?? '', $b['distance_naam'] ?? ''));
        }
        unset($sig);
        $out = array_values($perSig);
        usort($out, fn($a, $b) => $a['_sortkey'] - $b['_sortkey']);
        foreach ($out as &$sig) unset($sig['_sortkey']);
        unset($sig);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'ronde_uitslagen') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');
    $compId    = trim($_GET['competition_id'] ?? '');
    $dcId      = trim($_GET['dc_id'] ?? '');
    // license_key: optionele filter. Als meegegeven → alleen rondes tonen
    // waar deze rijder in zit. Zonder license: alle rondes (admin-preview).
    $rijderLic = trim($_GET['license_key'] ?? '');
    if (!$compId || !$dcId) { echo json_encode(['error' => 'competition_id en dc_id verplicht']); exit; }

    try {
        // Anonimiteit (variant B): publiek rondes-overzicht → public-venster.
        [$cStarts, $cEnds] = anoniemCompVenster($pdo, $compId);

        // Wedstrijdsysteem ophalen (bepaalt label 'B-finale' vs 'Kleine finale').
        $sysStmt = $pdo->prepare("SELECT systeem FROM competition_tijdschema WHERE competition_id = ? LIMIT 1");
        $sysStmt->execute([$compId]);
        $systeem = $sysStmt->fetchColumn() ?: 'internationaal-nieuw';

        // 1) Afstanden van deze DC in programma-volgorde.
        $distStmt = $pdo->prepare("
            SELECT d.id, d.name, d.value_meters, d.race_type, d.number,
                   v.prog_volgorde
            FROM distances d
            LEFT JOIN (
                SELECT tr.dc_id, tr.distance_id, MIN(tr.volgorde) AS prog_volgorde
                FROM tijdschema_ritten tr
                JOIN competition_tijdschema ct ON ct.id = tr.tijdschema_id
                WHERE ct.competition_id = ?
                GROUP BY tr.dc_id, tr.distance_id
            ) v ON v.dc_id = d.distance_combination_id AND v.distance_id = d.id
            WHERE d.distance_combination_id = ?
            ORDER BY v.prog_volgorde IS NULL, v.prog_volgorde, d.number, d.name
        ");
        $distStmt->execute([$compId, $dcId]);
        $distances = $distStmt->fetchAll(PDO::FETCH_ASSOC);

        // 1b) finale_ranking per afstand ophalen. Bepaalt de A-finale
        // sortering in de rondes-tab: dezelfde instelling als de Uitslag-
        // module in admin gebruikt. 'time' = puur op tijd (correct bij
        // 200m DTT / tijdkoppeling); 'position_time' = op finishpositie
        // met tijd als tiebreak (standaard).
        // Fallback-regel: dc-specifiek → dc_id IS NULL → 'position_time'.
        $seedStmt = $pdo->prepare("
            SELECT afstand_naam, value_meters, dc_id, finale_ranking
            FROM tijdschema_afstand_config tac
            JOIN competition_tijdschema ct ON ct.id = tac.tijdschema_id
            WHERE ct.competition_id = ? AND (tac.dc_id = ? OR tac.dc_id IS NULL)
        ");
        $seedStmt->execute([$compId, $dcId]);
        // Keyed op "naam\x1fmeters" ("Sprint" 300m/500m los); $rankingMapNaam is
        // de naam-only fallback (oude config zonder value_meters). Dc-specifiek wint.
        $rankingMap     = [];
        $rankingMapNaam = [];
        foreach ($seedStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $an  = $s['afstand_naam'];
            $m   = $s['value_meters'] !== null ? (int)$s['value_meters'] : null;
            $key = $an . "\x1f" . ($m ?? '');
            // dc-specifiek overrulet null-fallback
            if (!isset($rankingMap[$key]) || $s['dc_id'] !== null) {
                $rankingMap[$key] = $s['finale_ranking'];
            }
            if (!isset($rankingMapNaam[$an]) || $s['dc_id'] !== null) {
                $rankingMapNaam[$an] = $s['finale_ranking'];
            }
        }

        // 2) catConfig ophalen (voor Q/q + finale-heat-grootte + runner-up).
        $ccStmt = $pdo->prepare("
            SELECT * FROM tijdschema_cat_config cc
            JOIN competition_tijdschema ct ON ct.id = cc.tijdschema_id
            WHERE ct.competition_id = ? AND cc.dc_id = ?
        ");
        $ccStmt->execute([$compId, $dcId]);
        $catConfigs = [];
        foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
            $catConfigs[$cc['distance_id']] = $cc;
        }

        // 3) Query voor rijders per heat (incl. bruto + is_photofinish).
        $heatRijStmt = $pdo->prepare("
            SELECT h.id AS heat_id, h.heat_nr,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   he.person_id AS person_license, he.startpositie,
                   p.person_id, p.publiek_anoniem,
                   p.full_name, p.category AS categorie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   res.tijd_ms, res.bruto_tijd_ms, res.is_photofinish,
                   res.sanctie, res.finishpositie,
                   res.rondes, res.punten AS pk_punten
            FROM heats h
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            JOIN heat_entries he ON he.heat_id = h.id
            JOIN persons p ON p.person_id = he.person_id
            LEFT JOIN competition_startnummers cs
                ON cs.person_id = he.person_id AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE h.competition_id = ?
              AND h.distance_combination_id = ?
              AND COALESCE(h.distance_id, tsr.distance_id) = ?
            ORDER BY h.heat_nr, he.startpositie
        ");

        // 4) Eind-uitslag per distance uit uitslag_afstand.
        $eindStmt = $pdo->prepare("
            SELECT ua.rang, ua.tijd_ms, ua.sanctie, ua.punten, ua.finale_naam,
                   ua.person_id AS person_license,
                   p.person_id, p.publiek_anoniem,
                   p.full_name, COALESCE(cs.startnummer, p.start_number) AS snr
            FROM uitslag_afstand ua
            JOIN persons p ON p.person_id = ua.person_id
            LEFT JOIN competition_startnummers cs
                ON cs.person_id = ua.person_id AND cs.competition_id = ?
            WHERE ua.competition_id = ?
              AND ua.distance_combination_id = ?
              AND ua.distance_id = ?
            ORDER BY ua.rang IS NULL, ua.rang
        ");

        $RONDE_VOLGORDE = ['heats' => 1, 'kwartfinale' => 2, 'halve_finale' => 3, 'runner_up' => 4, 'finale_a' => 5, 'finale_b' => 6];
        // finale_b heet 'Kleine finale' in het internationaal-nieuw systeem
        // (verliezers uit voorgaande ronde strijden om plek na A) en 'B-finale'
        // bij full-final (klassieke rest-finale op series-tijd).
        $finaleBLabel = ($systeem === 'internationaal-nieuw') ? 'Kleine finale' : 'B-finale';
        $RONDE_LABEL    = ['heats' => 'Serie', 'kwartfinale' => 'Kwartfinale', 'halve_finale' => 'Halve finale', 'runner_up' => 'Runner-up', 'finale_a' => 'A-finale', 'finale_b' => $finaleBLabel];

        // Doorstroom-detectie: per rijder bepalen in WELKE volgende ronde/heat
        // ze zitten. Bij full-final krijgt iedereen een Q of q maar sommigen
        // gaan naar B1/B2/… — dat willen we in de badge zichtbaar maken.
        // Bouwt een map [distance_id][ronde_type][person_license] => doelabel
        // (A, B1, B2, RU, …).
        $doorstrKortLabel = function(string $rondeType, ?int $heatNr): string {
            if ($rondeType === 'finale_a')  return 'A';
            if ($rondeType === 'finale_b')  return 'B' . ($heatNr ?? 1);
            if ($rondeType === 'runner_up') return 'RU' . ($heatNr ?? 1);
            if ($rondeType === 'kwartfinale')  return 'KF';
            if ($rondeType === 'halve_finale') return 'HF';
            return '';
        };

        $out = [];
        foreach ($distances as $dist) {
            $distId = $dist['id'];
            $cc     = $catConfigs[$distId] ?? [];

            // Rijders ophalen + groeperen per ronde_type. Anonimiteit wordt pas
            // in de normalisatie-loop hieronder toegepast — de Q/doorstroom-logica
            // leunt nog op person_license (= person_id), en maskeren strípt die.
            $heatRijStmt->execute([$compId, $compId, $dcId, $distId]);
            $rows = $heatRijStmt->fetchAll(PDO::FETCH_ASSOC);
            $perRonde = [];
            foreach ($rows as $r) {
                $rt = $r['ronde_type'];
                if (!isset($perRonde[$rt])) $perRonde[$rt] = [];
                $perRonde[$rt][] = $r;
            }

            // Sorteer ronde-types naar programma-volgorde
            $rondeTypes = array_keys($perRonde);
            usort($rondeTypes, fn($a, $b) => ($RONDE_VOLGORDE[$a] ?? 99) - ($RONDE_VOLGORDE[$b] ?? 99));

            // Doorstroom-map: voor elke ronde X → per persoon het label van
            // hun eerst-volgende ronde-heat (A / B1 / B2 / RU1 / …). Bouwt
            // O(N²/2) over rondes maar N is klein (< 6 rondes per distance).
            $doorstroomPerRondePersoon = [];  // [rondeType][person_license] => label
            foreach ($rondeTypes as $rtIdx => $rt) {
                $vol = $RONDE_VOLGORDE[$rt] ?? 99;
                $doorstroomPerRondePersoon[$rt] = [];
                foreach ($rondeTypes as $laterRt) {
                    if (($RONDE_VOLGORDE[$laterRt] ?? 99) <= $vol) continue;
                    foreach ($perRonde[$laterRt] as $laterR) {
                        $lic = $laterR['person_license'];
                        if (isset($doorstroomPerRondePersoon[$rt][$lic])) continue; // eerste vondst wint
                        $label = $doorstrKortLabel($laterRt, (int)$laterR['heat_nr']);
                        if ($label !== '') $doorstroomPerRondePersoon[$rt][$lic] = $label;
                    }
                }
            }

            $rondes = [];
            foreach ($rondeTypes as $rt) {
                $rondeRijders = $perRonde[$rt];
                if (!count($rondeRijders)) continue;

                // Filter: als een license is meegegeven, alleen rondes tonen
                // waar deze rijder zelf in een heat zit. Rijders vallen soms
                // vroeg uit (bv. na series alleen A-finale-doorstromers) en
                // dan zijn de latere rondes voor hun eigen overzicht ruis.
                if ($rijderLic !== '') {
                    $eigenHeatNr = null;
                    foreach ($rondeRijders as $r) {
                        if ($r['person_license'] === $rijderLic) {
                            $eigenHeatNr = $r['heat_nr']; break;
                        }
                    }
                    if ($eigenHeatNr === null) continue;
                    // Bij B-finale / Runner-up: er zijn meerdere heats (B1/B2,
                    // RU1/RU2) — toon alleen de heat waar de rijder zelf in
                    // zit. Alle andere B-/RU-heats zijn ruis voor deze rijder.
                    if ($rt === 'finale_b' || $rt === 'runner_up') {
                        $rondeRijders = array_values(array_filter(
                            $rondeRijders,
                            fn($r) => $r['heat_nr'] === $eigenHeatNr
                        ));
                    }
                }

                // Compleetheid: alle rijders hebben tijd of sanctie.
                $compleet = true;
                foreach ($rondeRijders as $r) {
                    if ($r['tijd_ms'] === null && !$r['sanctie']) { $compleet = false; break; }
                }

                // Bereken Q/q voor doorstroom-rondes (heats/KF/HF).
                $qPerHeat = 0; $totaalDoor = 0;
                if ($rt === 'heats')        { $qPerHeat = (int)($cc['heats_q_heat'] ?? 0); $totaalDoor = (int)($cc['heats_q'] ?? 0); }
                elseif ($rt === 'kwartfinale')  { $qPerHeat = (int)($cc['kwart_q_heat'] ?? 1); $totaalDoor = (int)($cc['kwart_door'] ?? 0); }
                elseif ($rt === 'halve_finale') { $qPerHeat = (int)($cc['half_q_heat'] ?? 1);  $totaalDoor = (int)($cc['half_door'] ?? 0); }

                // Rijders per heat groeperen voor Q-bepaling
                $UITVAL_SANC = ['DNS', 'DNF', 'DQ-TF', 'DQ-SF', 'DQ-DF'];
                $isUitval = function($s) use ($UITVAL_SANC) {
                    if (!$s) return false;
                    foreach (explode(',', $s) as $c) {
                        $c = strtoupper(trim($c));
                        if (in_array($c, $UITVAL_SANC, true)) return true;
                    }
                    return false;
                };
                $qRijders = [];
                $qTijdRijders = [];
                if ($compleet && $totaalDoor > 0) {
                    $perHeat = [];
                    foreach ($rondeRijders as $r) {
                        $hk = $r['heat_nr'];
                        if (!isset($perHeat[$hk])) $perHeat[$hk] = [];
                        $perHeat[$hk][] = $r;
                    }
                    foreach ($perHeat as &$hr) {
                        usort($hr, fn($a, $b) => ($a['finishpositie'] ?? 999) - ($b['finishpositie'] ?? 999));
                    }
                    unset($hr);
                    // Q per heat: eerste qPerHeat finishers (excl. uitval)
                    if ($qPerHeat > 0) {
                        foreach ($perHeat as $hr) {
                            $teller = 0;
                            foreach ($hr as $r) {
                                if ($teller >= $qPerHeat) break;
                                if ($r['finishpositie'] !== null && !$isUitval($r['sanctie'])) {
                                    $qRijders[$r['person_license']] = true;
                                    $teller++;
                                }
                            }
                        }
                        // Ex-aequo Q (per heat): als de laatste Q-rijder van een
                        // heat exact dezelfde tijd heeft als de eerstvolgende
                        // rijder(s), gaan die ook door (overflow) — spiegelt de
                        // backend (live.php genereer_volgende_ronde). Zonder dit
                        // toont het publieke overzicht zo'n ex-aequo-rijder niet
                        // als gekwalificeerd terwijl hij wél in de volgende ronde zit.
                        foreach ($perHeat as $hr) {
                            $grens = $hr[$qPerHeat - 1] ?? null;
                            if (!$grens || $grens['tijd_ms'] === null) continue;
                            for ($i = $qPerHeat; $i < count($hr); $i++) {
                                if ($hr[$i]['tijd_ms'] !== null
                                        && (int)$hr[$i]['tijd_ms'] === (int)$grens['tijd_ms']
                                        && !$isUitval($hr[$i]['sanctie']))
                                    $qRijders[$hr[$i]['person_license']] = true;
                                else break;
                            }
                        }
                    }
                    // q op tijd: snelste van de niet-Q, niet-uitval
                    $aantalQ = count($qRijders);
                    $aantalq = max(0, $totaalDoor - $aantalQ);
                    if ($aantalq > 0) {
                        $metTijd = array_filter($rondeRijders, fn($r) =>
                            $r['tijd_ms'] !== null
                            && !isset($qRijders[$r['person_license']])
                            && !$isUitval($r['sanctie'])
                        );
                        usort($metTijd, fn($a, $b) => $a['tijd_ms'] - $b['tijd_ms']);
                        $metTijd = array_values($metTijd);
                        for ($i = 0; $i < min($aantalq, count($metTijd)); $i++) {
                            $qTijdRijders[$metTijd[$i]['person_license']] = true;
                        }
                        // Ex-aequo op grenstijd meepakken
                        if ($aantalq < count($metTijd) && ($metTijd[$aantalq - 1] ?? null)) {
                            $grens = $metTijd[$aantalq - 1]['tijd_ms'];
                            for ($i = $aantalq; $i < count($metTijd); $i++) {
                                if ($metTijd[$i]['tijd_ms'] === $grens) $qTijdRijders[$metTijd[$i]['person_license']] = true;
                                else break;
                            }
                        }
                    }
                }

                // Runner-up start-positie = aantal rijders in de eerst-
                // VOLGENDE ronde na de EERSTE gereden ronde + 1. RU is voor
                // uitvallers na de eerste ronde; de eerste ronde is niet
                // altijd 'heats' (kleinere wedstrijden beginnen soms met HF).
                //   heats → KF → …    : RU-start = |KF| + 1  (bv 16+1=17)
                //   heats → A(+B)     : RU-start = |A|+|B| + 1
                //   HF → A(+B)        : RU-start = |A|+|B| + 1  (HF was eerste)
                // Meerdere RU-heats (RU-1, RU-2, …) tellen cumulatief door
                // op tijd — dat regelt de RU-sorteer-loop hieronder.
                $ruStartPos = null;
                if ($rt === 'runner_up') {
                    // Volgorde van rondes die daadwerkelijk plaatsen toekennen
                    // (RU zelf niet meegerekend; die krijgt zijn plaats HIER).
                    $plaatsVolgorde = ['heats', 'kwartfinale', 'halve_finale', 'finale_a', 'finale_b'];
                    $eerste = null;
                    foreach ($plaatsVolgorde as $r) {
                        if (isset($perRonde[$r])) { $eerste = $r; break; }
                    }
                    $volgend = null;
                    $naEerste = false;
                    foreach ($plaatsVolgorde as $r) {
                        if ($r === $eerste) { $naEerste = true; continue; }
                        if ($naEerste && isset($perRonde[$r])) { $volgend = $r; break; }
                    }
                    if ($volgend === 'finale_a') {
                        // A + B parallel: doorstromers verdelen over beide.
                        $nA = count($perRonde['finale_a']);
                        $nB = isset($perRonde['finale_b']) ? count($perRonde['finale_b']) : 0;
                        $ruStartPos = $nA + $nB + 1;
                    } elseif ($volgend !== null) {
                        // KF of HF (of edge case finale_b zonder A)
                        $ruStartPos = count($perRonde[$volgend]) + 1;
                    } else {
                        // Geen ronde na de eerste? Rare setup; fallback 1.
                        $ruStartPos = 1;
                    }
                }

                // Verrijk elke rijder met kwal + doorstroom + eind_positie
                $ds = $doorstroomPerRondePersoon[$rt] ?? [];
                foreach ($rondeRijders as &$r) {
                    $r['kwal'] = '';
                    if (isset($qRijders[$r['person_license']]))    $r['kwal'] = 'Q';
                    elseif (isset($qTijdRijders[$r['person_license']])) $r['kwal'] = 'q';
                    $r['doorstroom_label'] = $ds[$r['person_license']] ?? null;
                    $r['ru_positie'] = null;
                }
                unset($r);

                // Runner-up eind-positie berekenen: per heat sorteren, dan
                // cumulatief nummeren over meerdere RU-heats.
                if ($rt === 'runner_up' && $ruStartPos) {
                    $perHeat = [];
                    foreach ($rondeRijders as $r) {
                        $hk = $r['heat_nr'] ?? 1;
                        if (!isset($perHeat[$hk])) $perHeat[$hk] = [];
                        $perHeat[$hk][] = $r;
                    }
                    ksort($perHeat, SORT_NUMERIC);
                    // Binnen elke heat: op tijd (uitval onderaan)
                    $volgendePos = $ruStartPos;
                    foreach ($perHeat as $hk => &$hr) {
                        usort($hr, function($a, $b) use ($isUitval) {
                            $aOk = $a['tijd_ms'] !== null && !$isUitval($a['sanctie']);
                            $bOk = $b['tijd_ms'] !== null && !$isUitval($b['sanctie']);
                            if ($aOk !== $bOk) return $aOk ? -1 : 1;
                            if ($aOk) return $a['tijd_ms'] - $b['tijd_ms'];
                            return ($a['startpositie'] ?? 999) - ($b['startpositie'] ?? 999);
                        });
                        foreach ($hr as $r) {
                            // Update in de master-array
                            foreach ($rondeRijders as &$mr) {
                                if ($mr['person_license'] === $r['person_license']
                                    && $mr['heat_nr'] === $r['heat_nr']) {
                                    $mr['ru_positie'] = $volgendePos++;
                                    break;
                                }
                            }
                            unset($mr);
                        }
                    }
                    unset($hr);
                }

                // Type-casten voor JSON
                foreach ($rondeRijders as &$r) {
                    $r['tijd_ms']       = $r['tijd_ms']       !== null ? (int)$r['tijd_ms']       : null;
                    $r['bruto_tijd_ms'] = $r['bruto_tijd_ms'] !== null ? (int)$r['bruto_tijd_ms'] : null;
                    $r['finishpositie'] = $r['finishpositie'] !== null ? (int)$r['finishpositie'] : null;
                    $r['heat_nr']       = $r['heat_nr']       !== null ? (int)$r['heat_nr']       : null;
                    $r['snr']           = $r['snr']           !== null ? (string)$r['snr']        : null;
                    $r['is_photofinish']= (int)($r['is_photofinish'] ?? 0);
                    $r['rondes']        = $r['rondes']        !== null ? (int)$r['rondes']       : null;
                    $r['pk_punten']     = $r['pk_punten']     !== null ? (float)$r['pk_punten']  : null;
                    unset($r['startpositie']);
                    // Anonimiteit (public-venster) — ná de Q/doorstroom-logica,
                    // die op person_license leunde; maskeren strípt die token nu.
                    $r = pasAnonimiteitToe($r, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
                }
                unset($r);

                $rondes[] = [
                    'ronde_type'  => $rt,
                    'ronde_label' => $RONDE_LABEL[$rt] ?? $rt,
                    'compleet'    => $compleet,
                    'aantal'      => count($rondeRijders),
                    'rijders'     => $rondeRijders,
                ];
            }

            // Eind-uitslag uit uitslag_afstand — alleen als de rijder erin
            // zit (of geen license-filter). Zonder rijder in eind-uitslag:
            // lege array, maar afstand blijft behouden als er rondes zijn.
            $eindStmt->execute([$compId, $compId, $dcId, $distId]);
            $eind = $eindStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rijderLic !== '') {
                $rijderInEind = false;
                foreach ($eind as $e) {
                    if ($e['person_license'] === $rijderLic) { $rijderInEind = true; break; }
                }
                if (!$rijderInEind) $eind = [];
            }
            foreach ($eind as &$e) {
                $e['rang']    = $e['rang']    !== null ? (int)$e['rang']    : null;
                $e['tijd_ms'] = $e['tijd_ms'] !== null ? (int)$e['tijd_ms'] : null;
                $e['punten']  = $e['punten']  !== null ? (float)$e['punten'] : null;
                $e['snr']     = $e['snr']     !== null ? (string)$e['snr']  : null;
                $e = pasAnonimiteitToe($e, 'public', $cStarts, $cEnds, false, ['naam' => ['full_name']]);
            }
            unset($e);

            // Skip hele afstand als de rijder geen rondes én geen eind-uitslag
            // heeft (irrelevant voor deze rijder).
            if ($rijderLic !== '' && !count($rondes) && !count($eind)) continue;

            $out[] = [
                'distance_id'    => $dist['id'],
                'distance_naam'  => $dist['name'],
                'distance_meters'=> $dist['value_meters'] !== null ? (int)$dist['value_meters'] : null,
                'race_type'      => $dist['race_type'],
                'finale_ranking' => $rankingMap[$dist['name'] . "\x1f" . ($dist['value_meters'] !== null ? (int)$dist['value_meters'] : '')]
                                    ?? $rankingMapNaam[$dist['name']] ?? 'position_time',
                'rondes'         => $rondes,
                'eind_uitslag'   => $eind,
            ];
        }

        echo json_encode(['distances' => $out], JSON_UNESCAPED_UNICODE);
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

// Richting van de punten-tabel: oplopend ([1,2,3,…] → laagste totaal wint) vs
// aflopend ([10.1,9,8,…] → hoogste wint). Zelfde logica als tabelIsOplopend in
// klassement_serie.php.
function tabelIsOplopendPub($tabel): bool {
    return is_array($tabel) && count($tabel) >= 2 && (float)$tabel[0] < (float)$tabel[1];
}

// Sorteert klassement-posities voor weergave: geklasseerd (positie > 0) eerst op
// rang, daarna het onderblok (positie 0 = niet opgenomen) op puntentotaal in de
// richting van de punten-tabel ($oplopend). Richting komt uit de regels (punten-
// tabel), NIET uit de rijen — want in positie-volgorde lopen categorieën door
// elkaar (elke categorie heeft een positie 1). Zo klopt het onderblok bij op- én
// aflopende tabellen, óók als een categorie helemaal geen geklasseerden heeft.
function sorteerKlassementPosities(array &$rows, bool $oplopend): void {
    usort($rows, function($a, $b) use ($oplopend) {
        $ba = ((int)$a['positie'] <= 0) ? 1 : 0;
        $bb = ((int)$b['positie'] <= 0) ? 1 : 0;
        if ($ba !== $bb) return $ba <=> $bb;                         // geklasseerd eerst
        if ($ba === 0) return (int)$a['positie'] <=> (int)$b['positie']; // op rang
        $ta = (float)$a['punten_totaal']; $tb = (float)$b['punten_totaal'];
        if ($ta != $tb) return $oplopend ? ($ta <=> $tb) : ($tb <=> $ta);  // onderblok op punten
        return strcmp((string)($a['start_number'] ?? ''), (string)($b['start_number'] ?? ''));
    });
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
                   k.categorieen, k.wedstrijden_meta, k.aangemaakt_op,
                   s.regels AS serie_regels
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

        // LEFT JOIN persons voor de anonimiteits-vlag (klassement_posities heeft
        // een eigen naam-snapshot, geen vlag). person_id kan bij oude rijen leeg
        // zijn → dan geen match, geen maskering (die rij is toch niet koppelbaar).
        $pos = $pdo->prepare("
            SELECT kp.positie, kp.start_number, kp.person_id AS license_key, kp.naam, kp.categorie,
                   kp.punten_detail, kp.punten_totaal, p.publiek_anoniem
            FROM klassement_posities kp
            LEFT JOIN persons p ON p.person_id = kp.person_id
            WHERE kp.klassement_id = ?
            ORDER BY (kp.positie = 0), kp.positie ASC
        ");
        $pos->execute([$klId]);
        $rows = $pos->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['punten_detail'] = $r['punten_detail'] !== null
                ? json_decode($r['punten_detail'], true) : null;
            $r['punten_totaal'] = $r['punten_totaal'] !== null
                ? (float)$r['punten_totaal'] : null;
            // Permanent, cross-seizoen doorzoekbaar record → laag 'altijd': een
            // publiek anonieme rijder wordt hier ALTIJD gemaskeerd (geen venster).
            $r = pasAnonimiteitToe($r, 'altijd', null, null, false, ['naam' => ['naam']]);
        }
        unset($r);
        $serieRegels = json_decode($k['serie_regels'] ?? 'null', true);
        unset($k['serie_regels']);
        sorteerKlassementPosities($rows, tabelIsOplopendPub($serieRegels['punten_tabel'] ?? null));
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
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>

<div id="ptr" data-i18n="ptr_trek">↓ Trek verder om te vernieuwen</div>

<header>
    <div class="hdr-row-top">
        <div class="hdr-btns hdr-btns-left">
            <button class="btn-help btn-meldingen" id="btn-meldingen-overzicht" data-i18n-title="hdr_meldingen_title" title="Mededelingen voor deze wedstrijd">📢<span id="meldingen-badge" class="meld-badge" hidden>0</span></button>
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
    <div id="pwa-banner" class="pwa-banner" hidden>
        <div class="pwa-banner-tekst">
            <b data-i18n="pwa_installeer_titel">Installeer InlineComp</b>
            <span data-i18n="pwa_installeer_uitleg">Voeg toe aan je startscherm voor snelle toegang</span>
        </div>
        <button class="btn-install" id="pwa-install" data-i18n="pwa_btn_install">Installeer</button>
        <button class="btn-sluit" id="pwa-sluit" data-i18n-title="pwa_btn_sluit" title="Sluiten">&times;</button>
    </div>

    <div id="profiel-promo" class="pwa-banner">
        <div class="pwa-banner-tekst">
            <b data-i18n="profiel_promo_titel">Nieuw: Mijn InlineComp</b>
            <span data-i18n="profiel_promo_uitleg">Je persoonlijke profiel met records &amp; progressie</span>
            <label class="promo-niet"><input type="checkbox" id="profiel-promo-niet"> <span data-i18n="profiel_promo_niet">Niet meer tonen</span></label>
        </div>
        <a class="btn-install" href="../check/profiel.php?demo=1" data-i18n="profiel_promo_demo">Bekijk voorbeeld</a>
        <button class="btn-sluit" id="profiel-promo-sluit" type="button" data-i18n-title="pwa_btn_sluit" title="Sluiten">&times;</button>
    </div>

    <!-- Setup-strook: klikbaar → opent modal met wedstrijd-keuze + rijder-
         zoek. Vervangt de altijd-zichtbare stap 1 + 2 secties zodat er meer
         verticale ruimte over is voor het programma zelf. -->
    <div class="setup-strip" id="setup-strip" onclick="openSetupModal()" title="Wijzig wedstrijd of voeg rijder toe">
        <div class="setup-strip-tekst" id="setup-strip-tekst">
            <span class="setup-strip-empty" data-i18n="setup_strip_leeg">Kies je wedstrijd…</span>
        </div>
        <button class="setup-strip-edit" type="button" data-i18n-title="setup_strip_edit_title" title="Wijzigen">✎</button>
    </div>

    <div id="resultaat"></div>
</div>

<!-- Setup-modal — bevat stap 1 (wedstrijd) + stap 2 (rijder-zoek).
     Opent bij klik op setup-strip, bij "+"-rijder-tab-knop, én automatisch
     bij eerste bezoek van de dag (localStorage-check). -->
<div class="setup-modal-overlay" id="setup-modal" onclick="if(event.target===this)closeSetupModal()">
    <div class="setup-modal-box">
        <button class="setup-modal-close" type="button" onclick="closeSetupModal()"
                data-i18n-title="pwa_btn_sluit" title="Sluiten">&times;</button>
        <h2 class="setup-modal-titel" data-i18n="setup_modal_titel">Wedstrijd &amp; rijder</h2>
        <div id="setup-volglijst" class="setup-volglijst"></div>
        <!-- Push-meldingen (Fase 3). JS vult dit zodra je een rijder volgt. -->
        <div id="pub-push" class="pub-push"></div>
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
        <div id="comp-info" class="comp-info" hidden></div>
        <div class="stap" id="stap-rijder">
            <div class="stap-label"><span class="stap-nr">2</span> <span data-i18n="stap2_label">Startnummer, licentie of achternaam</span></div>
            <input type="text" id="inp-snr" data-i18n-placeholder="zoek_placeholder" placeholder="Startnummer, licentienr of achternaam…" autocomplete="off" inputmode="search">
        </div>
        <button class="btn-zoek" id="btn-zoek" data-i18n="btn_zoeken" disabled>Zoeken</button>
        <div id="setup-melding" class="setup-melding" aria-live="polite"></div>
        <div id="setup-max-hint" class="setup-max-hint" hidden></div>
        <button class="setup-modal-klaar" type="button" onclick="closeSetupModal()" data-i18n="pwa_btn_sluit">Sluiten</button>
    </div>
</div>

<?php
    // Changelog voor "Wat is nieuw" — filter master-changelog op het onderdeel
    // 'public' en injecteer verderop als window.APP_CONFIG.changelog.
    $__cl     = require __DIR__ . '/../inc/changelog.php';
    $__clMine = array_values(array_filter($__cl, fn($e) => in_array('public', $e['onderdelen'], true)));
?>
<script src="../js/i18n.js?v=<?= @filemtime(__DIR__ . '/../js/i18n.js') ?>"></script>
<script>
// Shell -> app.js: één bron van waarheid voor versie + changelog (uit PHP).
window.APP_CONFIG = {
    versie:    <?= json_encode(INLINECOMP_VERSIE) ?>,
    changelog: <?= json_encode($__clMine, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="app.js?v=<?= @filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
