<?php
// api/public_lookup.php
// Endpoint: rijder-lookup: heats, uitslagen, rondes voor 1 rijder.
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }

if ($action === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    // Lookup-data verandert tijdens de wedstrijd voortdurend (loting,
    // resultaten, klassement-publicatie). Cache uitschakelen zodat
    // browser/proxy nooit een stale snapshot serveert — auto-refresh
    // elke 60 sec is dan altijd vers.
    header('Cache-Control: no-store, must-revalidate');
    // Startnummer leest uit POST-body (plan URL/log-reductie 2026-10-02):
    // startnummers zijn meerjarig vast en dus herleidbaar aan een persoon,
    // ook voor rijders die publiek anoniem zijn — die horen daarom niet in
    // de access-logs. GET-fallback blijft tijdens de deprecation-fase.
    // Lookups op person_id / license_key / volg-token blijven GET (die zijn
    // ofwel al in §1g van de privacyverklaring beschreven, ofwel bij een
    // volgende stap van het plan apart afgedekt).
    $body = (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') === 0)
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : [];
    $compId  = trim($body['competition_id'] ?? $_GET['competition_id'] ?? '');
    $snr     = trim($body['startnummer']    ?? $_GET['startnummer']    ?? '');
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
    // Volg-token leest uit POST-body (plan URL/log-reductie 2026-10-02):
    // het volg-token is een geheim entitlement — met dit token zie je de
    // naam van een publiek-anonieme rijder — dus mag het nooit in de web-
    // server-access-logs komen (wie het log leest zou de rijder kunnen
    // volgen). GET-fallback blijft tijdens de deprecation-fase.
    $volgTok = trim($body['volg'] ?? $_GET['volg'] ?? '');
    // Parameter-naam `license_key` is misleidend sinds de GUID-migratie: de
    // waarde is al een person_id-UUID (behalve bij legacy-volglijst-items die
    // nog een KNSB-licentienummer bevatten; resolveNaarPersonId() herkent
    // beide). De client gebruikt sinds plan URL/log-reductie 2026-10-02
    // overal `person_id=`; `license_key=` blijft als fallback voor oude PWA-
    // JS tot de deprecation-fase afloopt.
    $token   = trim($_GET['person_id'] ?? '') ?: trim($_GET['license_key'] ?? '');
    $pid      = '';
    $entitled = false;
    if ($volgTok !== '') {
        $pid = (string)(personIdVoorVolgToken($pdo, $volgTok) ?? '');
        $entitled = ($pid !== '');
    } elseif ($token !== '') {
        $pid = (string)(resolveNaarPersonId($pdo, $token) ?? '');
    }
    if (($volgTok !== '' || $token !== '') && $pid === '') {
        echo json_encode(['error' => 'Skater not found in this race']);
        exit;
    }

    if (!$compId || (!$snr && $pid === '')) {
        echo json_encode(['error' => 'competition_id and startnummer or license_key are required']);
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
            echo json_encode(['error' => "Skater not found for $omschr in this race"]);
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
