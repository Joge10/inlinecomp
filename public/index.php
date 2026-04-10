<?php
// ============================================================
//  InlineComp – Publieke rijder-lookup
//  Geen login vereist. Drie tabs: Programma / Heats / Resultaten
// ============================================================
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config_inlinecomp.php';

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
            SELECT c.id, c.name, c.starts
            FROM competitions c
            JOIN competition_tijdschema ct ON ct.competition_id = c.id
            ORDER BY c.starts DESC
        ");
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
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
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   tsr.rit_naam
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

        // Rijders ophalen
        $rStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.full_name, p.category,
                   res.finishpositie, res.tijd_ms, res.sanctie
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs ON cs.person_license = he.person_license AND cs.competition_id = ?
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

// ── API: lookup rijder ───────────────────────────────────────────────────────
if ($action === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $compId = trim($_GET['competition_id'] ?? '');
    $snr    = trim($_GET['startnummer'] ?? '');
    if (!$compId || !$snr) { echo json_encode(['error' => 'competition_id en startnummer zijn verplicht']); exit; }

    try {
        // Zoek persoon
        // Zoek alle personen met dit startnummer die ingeschreven zijn voor deze wedstrijd
        $persStmt = $pdo->prepare("
            SELECT p.license_key, p.full_name, p.category, p.start_number,
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
        $personen = $persStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$personen) { echo json_encode(['error' => 'Geen rijder gevonden met startnummer ' . $snr]); exit; }

        // Heats + alle rijders per heat
        $heatStmt = $pdo->prepare("
            SELECT DISTINCT h.id AS heat_id, h.heat_naam, h.ronde,
                   he.startpositie,
                   COALESCE(tsr.ronde_type, 'heats') AS ronde_type,
                   tsr.rit_naam,
                   res.finishpositie, res.tijd_ms, res.sanctie,
                   tsr.volgorde AS rit_volgorde
            FROM heat_entries he
            JOIN heats h ON h.id = he.heat_id
            LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.person_license = ? AND h.competition_id = ?
            ORDER BY COALESCE(tsr.volgorde, h.ronde * 100 + h.heat_nr)
        ");

        $rijdersStmt = $pdo->prepare("
            SELECT he.startpositie,
                   COALESCE(cs.startnummer, p.start_number) AS snr,
                   p.full_name, p.category,
                   res.finishpositie, res.tijd_ms, res.sanctie
            FROM heat_entries he
            JOIN persons p ON p.license_key = he.person_license
            LEFT JOIN competition_startnummers cs ON cs.person_license = he.person_license AND cs.competition_id = ?
            LEFT JOIN results res ON res.heat_entry_id = he.id
            WHERE he.heat_id = ?
            ORDER BY he.startpositie
        ");

        $uitslagStmt = $pdo->prepare("
            SELECT ua.distance_naam, ua.rang, ua.punten, ua.sanctie, ua.finale_naam
            FROM uitslag_afstand ua WHERE ua.person_license = ? AND ua.competition_id = ?
            GROUP BY ua.distance_id
            ORDER BY ua.distance_naam
        ");
        $klasStmt = $pdo->prepare("
            SELECT uk.rang, uk.punten_totaal, uk.dc_naam, uk.punten_detail
            FROM uitslag_klassement uk WHERE uk.person_license = ? AND uk.competition_id = ?
            GROUP BY uk.distance_combination_id
            ORDER BY uk.rang
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

                $rijdersStmt->execute([$compId, $h['heat_id']]);
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
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>InlineComp – Mijn wedstrijd</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
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
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 17px;
    color: var(--tekst);
    background: var(--grijs);
    min-height: 100vh;
}
header {
    background: var(--blauw);
    color: var(--wit);
    padding: 14px 16px;
    text-align: center;
}
header h1 { font-size: 1.3rem; font-weight: 700; }
header .sub { font-size: .85rem; opacity: .8; margin-top: 2px; }

.container { max-width: 640px; margin: 0 auto; padding: 16px; }

/* ── Stappen ── */
.stap { margin-bottom: 16px; }
.stap-label {
    font-size: .9rem; font-weight: 700; color: var(--blauw);
    margin-bottom: 6px; display: flex; align-items: center; gap: 6px;
}
.stap-nr {
    background: var(--blauw); color: var(--wit);
    width: 24px; height: 24px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; flex-shrink: 0;
}
select, input[type=number] {
    width: 100%; padding: 12px 14px; font-size: 1.05rem;
    border: 2px solid #cdd8e3; border-radius: 8px;
    background: var(--wit); appearance: none; -webkit-appearance: none;
}
select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%23666'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px;
}
select:focus, input:focus { border-color: var(--middenblauw); outline: none; }

.btn-zoek {
    width: 100%; padding: 14px; font-size: 1.1rem; font-weight: 700;
    color: var(--wit); background: var(--oranje);
    border: none; border-radius: 8px; cursor: pointer; margin-top: 8px;
}
.btn-zoek:disabled { opacity: .4; cursor: not-allowed; }
.btn-zoek:active { transform: scale(.98); }

/* ── Comp info ── */
.comp-info {
    background: var(--lichtblauw); border-radius: 8px;
    padding: 10px 14px; margin-bottom: 16px;
    font-size: .9rem; color: var(--blauw);
}
.comp-info strong { font-size: 1rem; }

/* ── Persoon header ── */
.persoon-header {
    background: var(--blauw); color: var(--wit);
    padding: 14px 16px; border-radius: 10px 10px 0 0;
    display: flex; justify-content: space-between; align-items: center;
}
.persoon-naam { font-size: 1.2rem; font-weight: 700; }
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
.tab-btn {
    flex: 1; padding: 12px 8px; font-size: .95rem; font-weight: 600;
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
.heat-card-tabel .col-sanctie { color: #c00; font-weight: 600; font-size: .85rem; }
.heat-card-mijn-result {
    background: var(--lichtblauw); padding: 6px 12px; font-size: .9rem;
    display: flex; justify-content: space-between; align-items: center;
}

/* ── Uitslag rij ── */
.uitslag-rij {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0; border-bottom: 1px solid #f5f7fa; font-size: .95rem;
}
.uitslag-rij:last-child { border-bottom: none; }
.uitslag-rang { font-size: 1.2rem; font-weight: 700; color: var(--blauw); min-width: 32px; }
.uitslag-afstand { flex: 1; }
.uitslag-punten { font-weight: 600; color: #555; }
.heat-sanctie { color: #c00; font-weight: 600; font-size: .85rem; }

/* ── Klassement ── */
.klas-rang { font-size: 1.5rem; font-weight: 700; color: var(--oranje); }
.klas-totaal { font-size: .95rem; color: #666; }

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
    <h1>InlineComp</h1>
    <div class="sub">Zoek je heats, starttijden en resultaten</div>
</header>

<div class="container">
    <div class="stap">
        <div class="stap-label"><span class="stap-nr">1</span> Kies je wedstrijd</div>
        <select id="sel-comp"><option value="">Laden…</option></select>
    </div>
    <div id="comp-info" class="comp-info" style="display:none"></div>
    <div class="stap">
        <div class="stap-label"><span class="stap-nr">2</span> Jouw startnummer</div>
        <input type="number" id="inp-snr" placeholder="Bijv. 86" min="1" inputmode="numeric">
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

const SL = { 'DSQ-TF':'DQ-DF','DSQ-SF':'DQ-SF','FS1':'FS','DNS':'DNS','DNF':'DNF','DC':'DC' };
const STATUS_LABEL = ['Niet bevestigd','Bevestigd','Afgemeld','Afgem. bij org.','Niet getekend','Bev. bij org.'];
const STATUS_KLEUR = ['#e65100','#2e7d32','#b71c1c','#6a1b9a','#283593','#006064'];
const STATUS_BG    = ['#fff3e0','#e8f5e9','#fce4e4','#f3e5f5','#e8eaf6','#e0f7fa'];
const BADGE = { heats:'badge-serie', kwartfinale:'badge-kf', halve_finale:'badge-hf',
                finale_a:'badge-finale', finale_b:'badge-finale', runner_up:'badge-ru' };
const RLABEL = { heats:'Serie', kwartfinale:'KF', halve_finale:'HF',
                 finale_a:'Finale', finale_b:'B-Finale', runner_up:'Runner-up' };

function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Fetch met automatische retry bij 429 (rate limit)
async function safeFetch(url, maxRetries = 3) {
    for (let i = 0; i < maxRetries; i++) {
        const res = await fetch(url);
        if (res.status !== 429) return res;
        await new Promise(r => setTimeout(r, 3000 * (i + 1))); // 3s, 6s, 9s
    }
    return fetch(url); // laatste poging
}
function msTijd(ms) {
    if (ms==null) return '';
    const h=Math.floor((ms%1000)/10), s=Math.floor(ms/1000)%60, m=Math.floor(ms/60000);
    return m>0?`${m}:${String(s).padStart(2,'0')}.${String(h).padStart(2,'0')}`:`${s}.${String(h).padStart(2,'0')}`;
}
function sl(s) { return s ? (SL[s]??s) : ''; }

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
        let rows = '';
        for (const r of h.rijders) {
            const isIk = String(r.snr) === snr;
            const rTijd = r.tijd_ms != null ? msTijd(r.tijd_ms) : '';
            const rFin = r.finishpositie != null ? r.finishpositie : '';
            const rSanctie = sl(r.sanctie);
            rows += `<tr class="${isIk ? 'rij-ik' : ''}">
                <td class="col-pos">${r.startpositie}</td>
                <td class="col-snr">${esc(r.snr)}</td>
                <td class="col-naam">${esc(r.full_name)}${rSanctie ? ` <span class="col-sanctie">${esc(rSanctie)}</span>` : ''}</td>
                <td class="col-tijd">${esc(rTijd)}</td>
                <td class="col-fin">${esc(rFin)}</td>
            </tr>`;
        }

        overlay.querySelector('.overlay-box').innerHTML = `
            <div class="heat-card" style="border:none;border-radius:12px">
                <div class="heat-card-titel" style="border-radius:12px 12px 0 0">
                    <button class="overlay-sluit" onclick="this.closest('.overlay').remove()">&times;</button>
                    <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(RLABEL[rt]??rt)}</span>
                    ${esc(h.rit_naam ?? h.heat_naam)}
                </div>
                <table class="heat-card-tabel">
                <thead><tr><th class="col-pos">#</th><th class="col-snr">Snr</th><th class="col-naam">Naam</th><th class="col-tijd">Tijd</th><th class="col-fin">Fin</th></tr></thead>
                <tbody>${rows}</tbody>
                </table>
            </div>`;
    } catch (e) {
        overlay.querySelector('.overlay-box').innerHTML = `<div style="padding:20px;color:#c00">Fout: ${esc(e.message)}</div>`;
    }
}

// Wedstrijden laden
safeFetch('?action=competitions').then(r=>r.json()).then(comps => {
    selComp.innerHTML = '<option value="">— Kies een wedstrijd —</option>';
    for (const c of comps) {
        const d = c.starts ? new Date(c.starts).toLocaleDateString('nl-NL',{day:'numeric',month:'long',year:'numeric'}) : '';
        const o = document.createElement('option');
        o.value = c.id; o.textContent = `${c.name} — ${d}`;
        o.dataset.datum = d; o.dataset.naam = c.name;
        selComp.appendChild(o);
    }
}).catch(() => { selComp.innerHTML = '<option value="">Fout bij laden</option>'; });

selComp.addEventListener('change', () => {
    const o = selComp.selectedOptions[0];
    if (o?.value) { divInfo.innerHTML = `<strong>${esc(o.dataset.naam)}</strong><div style="color:#555;margin-top:2px">${esc(o.dataset.datum)}</div>`; divInfo.style.display=''; }
    else divInfo.style.display='none';
    btnZoek.disabled = !(selComp.value && inpSnr.value.trim());
    divResult.innerHTML = '';
});
inpSnr.addEventListener('input', () => { btnZoek.disabled = !(selComp.value && inpSnr.value.trim()); });
inpSnr.addEventListener('keydown', e => { if (e.key==='Enter' && !btnZoek.disabled) btnZoek.click(); });

// Zoeken
btnZoek.addEventListener('click', async () => {
    const compId = selComp.value, snr = inpSnr.value.trim();
    if (!compId || !snr) return;
    divResult.innerHTML = '<div class="melding"><span class="spinner"></span> Zoeken…</div>';
    btnZoek.disabled = true;

    try {
        // Parallel: lookup + programma
        const [lookupRes, progRes] = await Promise.all([
            safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&startnummer=${encodeURIComponent(snr)}`),
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}`)
        ]);
        const data = await lookupRes.json();
        const prog = await progRes.json();

        if (data.error) { divResult.innerHTML = `<div class="melding melding-fout">${esc(data.error)}</div>`; return; }
        if (!data.length) { divResult.innerHTML = '<div class="melding">Geen resultaten gevonden.</div>'; return; }

        // Meerdere personen met zelfde startnummer? Laat kiezen
        if (data.length > 1) {
            let keuzeHtml = '<div class="kaart-sectie"><div class="kaart-sectie-titel">Meerdere rijders gevonden met startnummer ' + esc(snr) + '</div>';
            for (let i = 0; i < data.length; i++) {
                const p = data[i].persoon;
                keuzeHtml += `<div class="prog-rij" style="cursor:pointer;padding:10px 0" onclick="toonRijder(${i})">
                    <span style="font-weight:700;font-size:1.1rem;color:var(--blauw)">${esc(p.full_name)}</span>
                    <span class="persoon-cat">${esc(p.category)}</span>
                </div>`;
            }
            keuzeHtml += '</div>';
            divResult.innerHTML = `<div class="kaart" style="border-radius:10px;margin-top:16px">${keuzeHtml}</div>`;
            window._lookupData = data;
            window._lookupSnr = snr;
            window._lookupProg = prog;
            return;
        }

        toonRijderData(data, 0, snr, prog);

    } catch (e) {
        divResult.innerHTML = `<div class="melding melding-fout">Fout: ${esc(e.message)}</div>`;
    } finally { btnZoek.disabled = false; }
});

async function refreshRijder() {
    const compId = selComp.value, snr = inpSnr.value.trim();
    if (!compId || !snr) return;
    const gekozenIdx = window._gekozenIdx ?? 0;

    divResult.innerHTML = '<div class="melding"><span class="spinner"></span> Verversen…</div>';
    try {
        const [lookupRes, progRes] = await Promise.all([
            safeFetch(`?action=lookup&competition_id=${encodeURIComponent(compId)}&startnummer=${encodeURIComponent(snr)}&_t=${Date.now()}`),
            safeFetch(`?action=programma&competition_id=${encodeURIComponent(compId)}&_t=${Date.now()}`)
        ]);
        const data = await lookupRes.json();
        const prog = await progRes.json();
        if (data.error || !data.length) { divResult.innerHTML = `<div class="melding melding-fout">${data.error ?? 'Geen data'}</div>`; return; }

        window._lookupData = data;
        window._lookupSnr = snr;
        window._lookupProg = prog;

        // Bij meerdere rijders: direct de eerder gekozen tonen
        const idx = Math.min(gekozenIdx, data.length - 1);
        window._gekozenIdx = idx;
        toonRijderData(data, idx, snr, prog);
    } catch (e) {
        divResult.innerHTML = `<div class="melding melding-fout">Fout: ${e.message}</div>`;
    }
}

function toonRijder(idx) {
    window._gekozenIdx = idx;
    toonRijderData(window._lookupData, idx, window._lookupSnr, window._lookupProg);
}

function toonRijderData(data, startIdx, snr, prog) {
    const subset = [data[startIdx]];
    renderResultaat(subset, snr, prog);
}

function renderResultaat(data, snr, prog) {
        let html = '';
        for (const r of data) {
            const p = r.persoon;
            const st = parseInt(p.entry_status ?? 1);
            const stLabel = STATUS_LABEL[st] ?? '?';
            const stKleur = STATUS_KLEUR[st] ?? '#555';
            const stBg    = STATUS_BG[st] ?? '#eee';

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
                    <button class="tab-btn" data-tab="heats">🏁 Heats</button>
                    <button class="tab-btn" data-tab="resultaten">🏆 Resultaten</button>
                </div>
                <div class="kaart">`;

            // ── TAB: Programma ────────────────────────────────────────
            html += '<div class="tab-content active" data-tab="programma"><div class="kaart-sectie">';
            html += '<div class="kaart-sectie-titel">Wedstrijdprogramma</div>';
            if (prog.ritten?.length) {
                let nr = 0;
                for (const rit of prog.ritten) {
                    nr++;
                    // Highlight als deze rijder in deze rit zit
                    const isInRit = r.heats.some(h => h.rit_naam === rit.rit_naam);
                    const rt = rit.ronde_type ?? 'heats';
                    // Status icoon: ✅ gefinisht, 📋 geloot, ⬜ nog niks
                    const statusIcon = rit.resultaten_count > 0  ? '🏁'
                                     : rit.definitief          ? '🚩'
                                     :                           '';
                    html += `<div class="prog-rij" style="${isInRit ? 'background:#fffbe6;font-weight:600;margin:0 -16px;padding:6px 16px;border-radius:4px' : ''};cursor:pointer"
                                 data-rit-naam="${esc(rit.rit_naam)}" data-dc-naam="${esc(rit.dc_naam)}" onclick="toonRitDetail(this)">
                        <span class="prog-nr">${statusIcon} ${nr}</span>
                        <span class="prog-naam">${esc(rit.rit_naam)}</span>
                        <span class="prog-type heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(RLABEL[rt]??rt)}</span>
                    </div>`;
                }
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

                    html += `<div class="heat-card">
                        <div class="heat-card-titel">
                            <span class="heat-card-badge ${BADGE[rt]??'badge-serie'}">${esc(RLABEL[rt]??rt)}</span>
                            ${esc(naam)}
                        </div>
                        <table class="heat-card-tabel">
                        <thead><tr><th class="col-pos">#</th><th class="col-snr">Snr</th><th class="col-naam">Naam</th><th class="col-tijd">Tijd</th><th class="col-fin">Fin</th></tr></thead>
                        <tbody>`;

                    for (const rr of (h.rijders ?? [])) {
                        const isIk = String(rr.snr) === snr;
                        const rTijd = rr.tijd_ms != null ? msTijd(rr.tijd_ms) : '';
                        const rFin = rr.finishpositie != null ? rr.finishpositie : '';
                        const rSanctie = sl(rr.sanctie);
                        html += `<tr class="${isIk ? 'rij-ik' : ''}">
                            <td class="col-pos">${rr.startpositie}</td>
                            <td class="col-snr">${esc(rr.snr)}</td>
                            <td class="col-naam">${esc(rr.full_name)}${rSanctie ? ` <span class="col-sanctie">${esc(rSanctie)}</span>` : ''}</td>
                            <td class="col-tijd">${esc(rTijd)}</td>
                            <td class="col-fin">${esc(rFin)}</td>
                        </tr>`;
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
                        ${u.punten != null ? `<span class="uitslag-punten">${u.punten} pt</span>` : ''}
                        ${sanctie ? `<span class="heat-sanctie">${esc(sanctie)}</span>` : ''}
                    </div>`;
                }
            }

            if (r.klassementen.length) {
                for (const k of r.klassementen) {
                    html += `<div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-top:1px solid #eee;margin-top:8px">
                        <div><div class="kaart-sectie-titel" style="margin:0">Klassement ${esc(k.dc_naam)}</div>
                             <span class="klas-rang">#${k.rang}</span></div>
                        <div class="klas-totaal">${k.punten_totaal} punten</div>
                    </div>`;
                }
            }

            if (!r.uitslagen.length && !r.klassementen.length) {
                html += '<div class="melding">Nog geen resultaten beschikbaar.</div>';
            }

            html += '</div></div>';
            html += '</div></div>'; // kaart + wrapper
        }

        divResult.innerHTML = html;

        // Tab-switching
        divResult.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const kaart = btn.closest('.tabs').nextElementSibling;
                if (!kaart) return;
                btn.closest('.tabs').querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                kaart.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
                kaart.querySelector(`.tab-content[data-tab="${btn.dataset.tab}"]`)?.classList.add('active');
            });
        });

}
</script>
</body>
</html>
