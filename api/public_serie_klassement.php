<?php
// api/public_serie_klassement.php
// Endpoint: volledig serie-klassement (met helpers tabelIsOplopendPub + sorteerKlassementPosities).
//
// Gerequire'd door de public/index.php-shell-dispatcher.
// Session, $pdo, $action zijn al gezet in de shell-preamble.
// Geextraheerd uit public/index.php op 2026-09-30 (fase 4 van refactor-plan).
if (!defined('INLINECOMP_PUBLIC_BOOTED')) { http_response_code(404); exit; }


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
    if (!$klId) { echo json_encode(['error' => 'klassement_id required']); exit; }
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
        if (!$k) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
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
