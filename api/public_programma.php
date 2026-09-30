<?php
// api/public_programma.php
// Endpoint: programma/heats/rondes payload (ETag+304 flow uit patch 12d17b3 - intact houden!).
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

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
