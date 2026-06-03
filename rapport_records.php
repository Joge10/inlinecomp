<?php
// ============================================================
//  InlineComp – Records-check rapport
//
//  Per (afstand × categorie) de SNELSTE gereden baan-tijd vs.
//  het Nederlands record. Optioneel ?competition_id=X om te
//  filteren op één wedstrijd; default = alle wedstrijden in DB.
//
//  Layout: gesplitst in "Junioren records" + "Senioren records".
//  Binnen elke sectie: groeperen per (afstand × gender) zodat
//  recordhouder + record-tijd maar één keer per groep wordt
//  getoond, met daaronder per categorie (HJA/HJB/HKA enz.) de
//  snelste rijder.
//
//  Gebruik: open in browser, ctrl+P → "Opslaan als PDF".
// ============================================================

require_once __DIR__ . '/../config_inlinecomp.php';
require_once __DIR__ . '/auth/session.php';
$_authUser = requireAuth($pdo);

$compId = trim($_GET['competition_id'] ?? '');
// Multi-tenant: blokkeer direct-URL-toegang tot wedstrijd buiten scope.
checkCompetitieToegang($pdo, $_authUser, $compId);
// type: 'baan' of 'weg' — bepaalt welke records-categorie gematcht wordt.
// Default 'baan' wanneer niet meegegeven (achterwaarts-compatibel met oude links).
$recordType = strtolower(trim($_GET['type'] ?? 'baan'));
if (!in_array($recordType, ['baan', 'weg'], true)) $recordType = 'baan';
// modus: 'top1' = alleen snelste rijder per (afstand × cat) (default, compact)
//        'alle' = ALLE rijders per (afstand × cat), gesorteerd op tijd ASC
$modus = strtolower(trim($_GET['modus'] ?? 'top1'));
if (!in_array($modus, ['top1', 'alle'], true)) $modus = 'top1';

// Optionele wedstrijd-meta voor de header.
$compMeta = null;
if ($compId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.starts, c.location
        FROM competitions c
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$compId]);
    $compMeta = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Hoofdquery — windowfunctie pakt per (afstand × cat) de snelste rij +
// bijbehorende rijder/heat. Filtert op nr.type='baan' (weg-records weg).
// bruto_tijd_ms is leidend wanneer aanwezig (= gemeten transponder-tijd).
// Extra meta: cat_groep, gender, value_meters voor sectie-grouping + sort.
$sql = "
WITH base AS (
    -- Alle (rijder × ronde × afstand × cat)-result-rijen die matchen met
    -- een nationaal record. Eén rijder kan meerdere rijen hebben (serie + HF
    -- + finale = 3 rijen per rijder per afstand).
    SELECT
        d.name                                       AS afstand,
        d.value_meters                               AS afstand_meters,
        p.category                                   AS kat,
        p.license_key                                AS p_lic,
        p.full_name                                  AS rijder,
        h.heat_naam                                  AS heat,
        h.competition_id                             AS comp_id,
        h.distance_combination_id                    AS dc_id,
        COALESCE(h.distance_id, '')                  AS dist_id,
        COALESCE(tsr.ronde_type,
            CASE WHEN h.heat_naam LIKE '%finale%' THEN 'finale_a'
                 ELSE 'heats' END)                   AS ronde_type,
        h.heat_nr                                    AS heat_nr,
        COALESCE(res.bruto_tijd_ms, res.tijd_ms)     AS gereden_ms,
        res.tijd_ms                                  AS officiele_ms,
        res.bruto_tijd_ms                            AS bruto_ms,
        res.is_photofinish                           AS is_photofinish,
        nr.tijd_ms                                   AS record_ms,
        nr.rijder_naam                               AS huidig_recordhouder,
        nr.locatie                                   AS record_locatie,
        nr.record_datum                              AS record_datum,
        nr.wedstrijd                                 AS record_wedstrijd,
        nr.extra_info                                AS record_extra,
        nr.cat_groep                                 AS cat_groep,
        nr.gender                                    AS gender,
        -- Per rijder de snelste rij eruit pikken: ROW_NUMBER over (rijder,
        -- afstand, cat) sorteert op tijd ASC; rider_rn = 1 = persoonlijk best.
        ROW_NUMBER() OVER (
            PARTITION BY p.license_key, d.name, p.category
            ORDER BY COALESCE(res.bruto_tijd_ms, res.tijd_ms) ASC
        )                                            AS rider_rn
    FROM results res
    JOIN heat_entries           he  ON he.id = res.heat_entry_id
    JOIN heats                  h   ON h.id  = he.heat_id
    JOIN persons                p   ON p.license_key = he.person_license
    LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
    JOIN distances              d   ON d.id  = h.distance_id
                                   AND d.distance_combination_id = h.distance_combination_id
    JOIN nationale_records      nr  ON
            nr.afstand_key = CASE
                WHEN LOWER(d.name) LIKE '%marathon%' THEN 'marathon'
                WHEN LOWER(d.name) LIKE '%relay%' OR LOWER(d.name) LIKE '%estafette%'
                    THEN LOWER(CONCAT(REGEXP_SUBSTR(d.name, '[0-9]+'), 'm-relay'))
                ELSE LOWER(CONCAT(REGEXP_SUBSTR(d.name, '[0-9]+'), 'm'))
            END
        AND nr.cat_groep = CASE
                WHEN UPPER(SUBSTRING(p.category, 2)) IN ('P4','P3','P2','P1','KA','JB','JA') THEN 'junioren'
                WHEN UPPER(SUBSTRING(p.category, 2)) IN ('SJ','SA','SB') THEN 'senioren'
                WHEN UPPER(p.category) REGEXP '^[HD]?M[0-9]+$' THEN 'senioren'
                ELSE NULL
            END
        AND nr.gender = CASE
                WHEN UPPER(LEFT(p.category, 1)) IN ('H','M') THEN 0
                WHEN UPPER(LEFT(p.category, 1)) = 'D' THEN 1
                ELSE NULL
            END
    WHERE COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
      AND res.sanctie IS NULL
      AND nr.type = '" . $recordType . "'
      " . ($compId ? "AND h.competition_id = ?" : "") . "
),
best_per_rider AS (
    -- Alleen de snelste rondetijd per (rijder × afstand × cat). Zo verschijnt
    -- elke rijder maar één keer in het rapport, met diens persoonlijk best.
    SELECT * FROM base WHERE rider_rn = 1
),
uitslag_latest AS (
    -- Officiële einduitslag — laatste 'Uitslag bevestigen'-snapshot per
    -- (rijder × comp × dc × afstand). split_group expliciet WEGGELATEN uit
    -- GROUP BY zodat een rijder met split-keys (bv. multi-cat DC) toch maar
    -- één rang krijgt in dit rapport (de meest recente snapshot).
    SELECT ua1.person_license, ua1.competition_id,
           ua1.distance_combination_id, ua1.distance_id, ua1.rang
    FROM uitslag_afstand ua1
    INNER JOIN (
        SELECT MAX(id) AS max_id
        FROM uitslag_afstand
        GROUP BY person_license, competition_id, distance_combination_id, distance_id
    ) lt ON lt.max_id = ua1.id
),
ranked AS (
    -- Cat-rang (rn) + uniek-rijder-rang (lic_rank) toegepast op de
    -- gededupliceerde set. Plus LEFT JOIN naar de officiële uitslag-rang.
    -- snelste_in_cat_ms = MIN gereden tijd binnen elke (afstand × cat) —
    -- nodig voor de Δ-tot-cat-leider-kolom in alle-modus.
    SELECT
        b.*,
        ROW_NUMBER() OVER (
            PARTITION BY afstand, kat ORDER BY gereden_ms
        ) AS rn,
        DENSE_RANK() OVER (
            PARTITION BY afstand, kat ORDER BY p_lic
        ) AS lic_rank,
        MIN(gereden_ms) OVER (
            PARTITION BY afstand, kat
        ) AS snelste_in_cat_ms,
        ul.rang AS uitslag_rang
    FROM best_per_rider b
    LEFT JOIN uitslag_latest ul ON
            ul.person_license          = b.p_lic
        AND ul.competition_id          = b.comp_id
        AND ul.distance_combination_id = b.dc_id
        AND ul.distance_id             = b.dist_id
),
counts AS (
    SELECT afstand, kat, MAX(lic_rank) AS aantal_rijders
    FROM ranked
    GROUP BY afstand, kat
)
SELECT r.*, c.aantal_rijders AS aantal_gereden
FROM ranked r
JOIN counts c ON c.afstand = r.afstand AND c.kat = r.kat
" . ($modus === 'top1' ? "WHERE r.rn = 1" : "") . "
ORDER BY
    cat_groep ASC,
    gender ASC,
    afstand_meters ASC,
    kat ASC,
    r.rn ASC          -- 'alle'-modus: binnen elke (afstand × cat) op tijd ASC
";

$stmt = $pdo->prepare($sql);
if ($compId) {
    $stmt->execute([$compId]);
} else {
    $stmt->execute();
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Groeperen voor de presentatie: rijen splitsen in junioren/senioren-secties.
$secties = ['junioren' => [], 'senioren' => []];
foreach ($rows as $r) {
    $grp = $r['cat_groep'] ?: 'overig';
    if (!isset($secties[$grp])) $secties[$grp] = [];
    $secties[$grp][] = $r;
}

// ── Helpers ───────────────────────────────────────────────────────────────
function fmtTijd(?int $ms): string {
    if ($ms === null) return '—';
    $min = intdiv($ms, 60000);
    $sec = intdiv($ms % 60000, 1000);
    $mil = $ms % 1000;
    if ($min > 0) {
        return sprintf('%d:%02d.%03d', $min, $sec, $mil);
    }
    return sprintf('0:%02d.%03d', $sec, $mil);
}

function fmtDelta(int $deltaMs): string {
    $sec = $deltaMs / 1000;
    return sprintf('+%.3f s', $sec);
}

function fmtRondeHeat(string $rondeType, ?int $heatNr, string $fallback): string {
    $heat = $heatNr !== null ? (int)$heatNr : null;
    switch ($rondeType) {
        case 'heats':        return 'Serie heat '       . $heat;
        case 'kwartfinale':  return 'KF heat '          . $heat;
        case 'halve_finale': return 'HF heat '          . $heat;
        case 'finale_a':     return 'A-finale heat '    . $heat;
        case 'finale_b':     return 'B-finale heat '    . $heat;
        case 'runner_up':    return 'Runner-up heat '   . $heat;
        default:             return $fallback ?: ($rondeType . ' heat ' . $heat);
    }
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Bouwt een leesbare meta-tekst voor een record-row: wedstrijd, locatie, datum,
// extra_info. NULL-velden worden netjes overgeslagen. Resultaat bv.:
//   "EK Lagos (P) · 04-07-2017"
//   "NK Heerenveen · 02-06-2017 — HF1"
// Lege string als geen enkel veld iets bevat.
function fmtRecordMeta(?string $wedstrijd, ?string $locatie, ?string $datum, ?string $extra): string {
    $hoofd = [];
    if ($wedstrijd !== null && $wedstrijd !== '') $hoofd[] = $wedstrijd;
    if ($locatie   !== null && $locatie   !== '') $hoofd[] = $locatie;
    if ($datum     !== null && $datum     !== '') {
        $t = strtotime($datum);
        $hoofd[] = $t ? date('j-n-Y', $t) : $datum;
    }
    $tekst = implode(' · ', $hoofd);
    if ($extra !== null && $extra !== '') {
        $tekst = $tekst === '' ? $extra : ($tekst . ' — ' . $extra);
    }
    return $tekst;
}

$titel = $compMeta
    ? 'Records-check: ' . $compMeta['name']
    : 'Records-check (alle wedstrijden)';
$metaTxt = $compMeta
    ? trim(($compMeta['starts'] ? date('j F Y', strtotime($compMeta['starts'])) : '')
         . ($compMeta['location'] ? ' · ' . $compMeta['location'] : ''))
    : 'Per afstand × categorie de snelste gereden tijd vergeleken met het huidige Nederlands ' . $recordType . '-record';

// Helper voor één sectie renderen (junioren of senioren).
// modus 'top1' = 1 rij per (afstand × cat), modus 'alle' = N rijen per (afstand
// × cat) op tijd ASC binnen die groep.
function renderSectie(string $sectieTitel, array $sectieRows, string $recordType, string $modus): void {
    if (empty($sectieRows)) {
        echo '<div class="pr-sectie-titel">' . esc($sectieTitel) . '</div>';
        echo '<div class="pr-leeg">Geen resultaten in deze sectie.</div>';
        return;
    }

    $isAlleModus = $modus === 'alle';
    $kolomLaatste = $isAlleModus
        ? '<th style="text-align:right">#<small>rang binnen<br>afstand+cat</small></th>'
        : '<th style="text-align:right">#<small>aantal rijders<br>op afstand+cat</small></th>';
    $rijderLabel = $isAlleModus ? 'Rijder' : 'Snelste rijder';

    echo '<div class="pr-sectie-titel">' . esc($sectieTitel) . '</div>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Afstand</th>';
    echo '<th>Recordhouder<small>Huidig nationaal ' . esc($recordType) . '-record</small></th>';
    echo '<th style="text-align:right">Record<small>tijd</small></th>';
    echo '<th>Cat<small>KNSB-categorie</small></th>';
    echo '<th>' . $rijderLabel . '<small>In deze wedstrijd</small></th>';
    echo '<th>Ronde / heat<small>Waar geklokt</small></th>';
    echo '<th style="text-align:right">Tijd<small>geklokt</small></th>';
    echo '<th style="text-align:right">Δ-record<small>+ = langzamer<br>− = sneller (record!)</small></th>';
    if ($isAlleModus) {
        echo '<th style="text-align:right" title="Verschil met de snelste rijder in dezelfde (afstand × categorie). De snelste rijder zelf heeft +0.000 s.">Δ-cat<small>tov. snelste<br>in eigen cat</small></th>';
    }
    echo '<th style="text-align:right" title="Officiële uitslag-positie. Toont \'—\' als de uitslag voor deze afstand nog niet is vastgelegd OF als de rijder geen klassering kreeg (DNF/DQ/DNS in finale).">Eind<small>off. uitslag-<br>positie</small></th>';
    echo $kolomLaatste;
    echo '</tr></thead>';

    // Twee group-niveaus:
    //   groupKey (afstand × gender) → bepaalt welk record geldt → record-cellen
    //   catKey   (afstand × gender × kat) → bepaalt cat-label → cat-cel
    // Cel wordt alleen op de eerste rij van een nieuwe (sub)groep getoond,
    // daaronder blanco — leesbaarder bij veel rijen.
    //
    // Print-layout: elke cat-groep in een eigen <tbody class="pr-cat-tbody">
    // zodat 'break-inside:avoid' de hele cat bij elkaar houdt op één pagina
    // wanneer mogelijk. Bij grote cats die niet passen valt de browser terug
    // op een normaal break.
    $prevGroupKey = null;
    $prevCatKey   = null;
    $tbodyOpen    = false;
    $footnotes = [];
    foreach ($sectieRows as $r) {
        $groupKey   = $r['afstand'] . '|' . $r['gender'];
        $catKey     = $groupKey . '|' . $r['kat'];
        $isNewGroup = $groupKey !== $prevGroupKey;
        $isNewCat   = $catKey   !== $prevCatKey;
        $prevGroupKey = $groupKey;
        $prevCatKey   = $catKey;

        $gereden    = (int)$r['gereden_ms'];
        $record     = (int)$r['record_ms'];
        $officieel  = $r['officiele_ms'] !== null ? (int)$r['officiele_ms'] : null;
        $bruto      = $r['bruto_ms']     !== null ? (int)$r['bruto_ms']     : null;
        $isPhoto    = (int)$r['is_photofinish'] === 1;
        $delta      = $gereden - $record;
        $isRecord   = $delta < 0;
        $deltaCls   = $isRecord ? 'c-delta-record' : 'c-delta';
        $deltaTxt   = $isRecord
            ? sprintf('%.3f s 🏆', $delta / 1000)
            : fmtDelta($delta);

        // Audit-mismatch detectie: bruto-tijd is bekend én ≠ officiële tijd.
        $heeftAudit = $bruto !== null && $officieel !== null && $bruto !== $officieel;
        $fnSup      = '';
        if ($heeftAudit) {
            $icon = $isPhoto ? '📷' : '✋';
            $footnotes[] = [
                'icon'      => $icon,
                'isPhoto'   => $isPhoto,
                'rijder'    => $r['rijder'],
                'kat'       => $r['kat'],
                'afstand'   => $r['afstand'],
                'gemeten'   => fmtTijd($bruto),
                'officieel' => fmtTijd($officieel),
            ];
            $fnSup = ' <sup class="pr-fn-sup">' . $icon . count($footnotes) . '</sup>';
        }

        // Bij nieuwe cat: vorige tbody sluiten + nieuwe tbody openen.
        // Maakt elke cat een eigen break-resistent blok.
        if ($isNewCat) {
            if ($tbodyOpen) echo '</tbody>';
            echo '<tbody class="pr-cat-tbody">';
            $tbodyOpen = true;
        }

        $trCls = $isNewGroup ? ' class="pr-rij-groep-start"'
               : ($isNewCat ? ' class="pr-rij-cat-start"' : '');

        // Laatste-kolom: in top1-modus 't aantal unieke rijders op (afstand,cat),
        // in alle-modus de rang van deze rijder binnen (afstand,cat) — 1 = snelste.
        $laatsteVal = $isAlleModus ? (int)$r['rn'] : (int)$r['aantal_gereden'];

        echo "<tr$trCls>";
        echo '<td class="c-afstand">'      . ($isNewGroup ? esc($r['afstand'])              : '') . '</td>';
        // Recordhouder + meta-regel eronder (wedstrijd/locatie/datum/extra).
        // Alleen op groep-start; daaronder blanco zoals andere record-cellen.
        if ($isNewGroup) {
            $meta = fmtRecordMeta($r['record_wedstrijd'] ?? null,
                                  $r['record_locatie']   ?? null,
                                  $r['record_datum']     ?? null,
                                  $r['record_extra']     ?? null);
            echo '<td class="c-recordhouder">'
               . esc($r['huidig_recordhouder'])
               . ($meta !== '' ? '<div class="c-record-meta">' . esc($meta) . '</div>' : '')
               . '</td>';
        } else {
            echo '<td class="c-recordhouder"></td>';
        }
        echo '<td class="c-tijd-rec">'     . ($isNewGroup ? esc(fmtTijd($record))          : '') . '</td>';
        echo '<td class="c-kat">'          . ($isNewCat   ? esc($r['kat'])                  : '') . '</td>';
        echo '<td class="c-naam">' . esc($r['rijder']) . '</td>';
        echo '<td class="c-heat">' . esc(fmtRondeHeat($r['ronde_type'],
            $r['heat_nr'] !== null ? (int)$r['heat_nr'] : null,
            (string)($r['heat'] ?? ''))) . '</td>';
        echo '<td class="c-tijd">' . esc(fmtTijd($gereden)) . $fnSup . '</td>';
        echo '<td class="' . $deltaCls . '">' . $deltaTxt . '</td>';

        // Δ-cat: alleen in alle-modus tonen. Verschil tussen deze rijder en de
        // snelste in z'n eigen (afstand × cat). Cat-leider zelf krijgt +0.000.
        if ($isAlleModus) {
            $snelste = (int)$r['snelste_in_cat_ms'];
            $deltaCat = $gereden - $snelste;
            $catCls   = $deltaCat === 0 ? 'c-delta-cat-eerste' : 'c-delta';
            $catTxt   = sprintf('+%.3f s', $deltaCat / 1000);
            echo '<td class="' . $catCls . '">' . $catTxt . '</td>';
        }

        // Officiële uitslag-positie. NULL = nog niet vastgelegd → toon "—".
        // Highlight wanneer cat-snelste tijd EN uitslag-#1 dezelfde rijder is.
        $uitRang = $r['uitslag_rang'] !== null ? (int)$r['uitslag_rang'] : null;
        $eindCls = 'c-aantal';
        if ($uitRang !== null && (int)$r['rn'] === 1 && $uitRang === 1) {
            $eindCls .= ' c-eind-match';
        }
        echo '<td class="' . $eindCls . '">' . ($uitRang !== null ? $uitRang : '—') . '</td>';
        echo '<td class="c-aantal">' . $laatsteVal . '</td>';
        echo '</tr>';
    }
    if ($tbodyOpen) echo '</tbody>';
    echo '</table>';

    // Voetnoten-blok onder de sectie-tabel (alleen renderen als er audit-rijen waren).
    if (!empty($footnotes)) {
        echo '<div class="pr-fn-row">';
        echo '<div class="pr-fn-titel">Aanpassingen door jury</div>';
        foreach ($footnotes as $i => $f) {
            $nr      = $i + 1;
            $aktie   = $f['isPhoto'] ? 'fotofinish-wisseling' : 'handmatige correctie';
            echo '<div class="pr-fn">';
            echo '<sup class="pr-fn-sup">' . $f['icon'] . $nr . '</sup> '
               . esc($aktie) . ' — '
               . '<b>' . esc($f['rijder']) . '</b> (' . esc($f['kat']) . ', '
               . esc($f['afstand']) . '): gemeten '
               . '<span class="c-tijd">' . esc($f['gemeten']) . '</span>, '
               . 'officieel '
               . '<span class="c-tijd">' . esc($f['officieel']) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
}
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title><?= esc($titel) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;margin:.6cm 1cm;color:#111;line-height:1.35}
.pr-header{display:flex;justify-content:space-between;align-items:stretch;
           border-bottom:2px solid #1a3a5c;padding-bottom:.3cm;margin-bottom:.4cm}
.pr-comp{font-size:13pt;font-weight:700}
.pr-meta{font-size:8.5pt;color:#555;margin-top:1mm}
.pr-type{font-size:10pt;font-weight:700;color:#1a3a5c;margin-top:2mm}
.pr-toelichting{font-size:8pt;color:#444;margin:0 0 .4cm 0;line-height:1.5;
                background:#f4f7fa;border-left:3px solid #1a3a5c;padding:.2cm .3cm}
.pr-toelichting b{color:#1a3a5c}
.pr-sectie-titel{font-size:11pt;font-weight:700;color:#1a3a5c;
                 background:linear-gradient(to right,#dce6f0,transparent);
                 padding:.15cm .25cm;margin:.5cm 0 .15cm 0;
                 border-left:4px solid #1a3a5c;break-after:avoid}
.pr-leeg{font-size:8.5pt;color:#888;font-style:italic;padding:.3cm}
table{width:100%;border-collapse:collapse;font-size:8.5pt;table-layout:auto;margin-bottom:.2cm}
thead{display:table-header-group}
th{background:#dce6f0;color:#1a3a5c;padding:4px 6px;font-size:7.5pt;
   text-align:left;font-weight:600;border-bottom:1px solid #bbb;white-space:nowrap;
   vertical-align:bottom}
th small{display:block;font-size:6.5pt;font-weight:400;color:#5a7491;margin-top:1px;text-transform:none}
td{padding:3px 6px;border-bottom:1px solid #eee;white-space:nowrap;vertical-align:top}
tr.pr-rij-groep-start td{border-top:1.5px solid #bbb}
tr.pr-rij-cat-start td{border-top:1px dotted #d4dce6}
tr:nth-child(even) td{background:#f8fafc}
.c-afstand{font-weight:600;color:#1a3a5c}
.c-kat{text-align:center;font-weight:600;color:#1a3a5c}
.c-tijd{text-align:right;font-family:monospace;font-size:8.5pt}
.c-tijd-rec{text-align:right;font-family:monospace;font-size:8.5pt;color:#1a3a5c;font-weight:600}
.c-delta{text-align:right;font-family:monospace;font-size:8.5pt;color:#b00}
.c-delta-record{text-align:right;font-family:monospace;font-size:8.5pt;color:#0a7d2a;font-weight:700}
/* Cat-leider in Δ-cat-kolom: +0.000 in onopvallend grijs zodat de echte
   verschillen (rood) er duidelijk uitspringen. */
.c-delta-cat-eerste{text-align:right;font-family:monospace;font-size:8.5pt;color:#888}
.c-aantal{text-align:right;font-size:7.5pt;color:#666}
/* Snelste in cat ÉN nr. 1 in officiële uitslag → groene match-indicator. */
.c-eind-match{color:#0a7d2a;font-weight:700;font-size:8.5pt}
.c-naam{font-weight:500}
.c-recordhouder{color:#555}
/* Meta-regel onder recordhouder-naam: wedstrijd · locatie · datum [— extra].
   In compact-modus krap maar nog leesbaar; in alle-modus is er zat ruimte
   omdat meerdere rijen onder hetzelfde record-blok hangen. */
.c-record-meta{font-size:6.5pt;color:#888;margin-top:1px;line-height:1.25;
               font-style:italic;white-space:normal}
.c-heat{font-size:7.5pt;color:#444}
.pr-fn-row{background:#f4f7fa;border-left:3px solid #1a3a5c;
           padding:.15cm .3cm;margin:0 0 .35cm 0;font-size:7.5pt;line-height:1.5}
.pr-fn-titel{font-weight:700;color:#1a3a5c;font-size:8pt;margin-bottom:1mm}
.pr-fn{color:#333;margin:1px 0}
.pr-fn b{color:#111}
.pr-fn-sup{color:#1a3a5c;font-weight:700;margin-left:1px;font-size:6.5pt;
           letter-spacing:.5px}
.pr-fn .c-tijd{font-family:monospace;font-size:7.5pt;color:#111}
.pr-footer{margin-top:.5cm;font-size:7pt;color:#888;text-align:right;
           border-top:1px solid #ddd;padding-top:2mm}
@page{size:A4 landscape;margin:.8cm 1cm}
@media print{
    body{margin:.5cm .8cm}
    /* Header + sectie-titel hechten aan wat volgt — geen orphans. */
    .pr-header{break-after:avoid;page-break-after:avoid}
    .pr-sectie-titel{break-after:avoid;page-break-after:avoid;break-before:auto}
    /* Elke cat-groep in z'n eigen tbody bij elkaar houden indien mogelijk.
       Browsers respecteren dit op tbody-niveau; bij cats die te groot zijn
       voor één pagina valt de browser terug op een normaal break. */
    tbody.pr-cat-tbody{break-inside:avoid;page-break-inside:avoid}
    /* Voetnoten-blok ook bij elkaar houden. */
    .pr-fn-row{break-inside:avoid;page-break-inside:avoid}
}
</style>
</head>
<body>

<div class="pr-header">
  <div style="flex:1;min-width:0;">
    <div class="pr-comp"><?= esc($titel) ?></div>
    <div class="pr-meta"><?= esc($metaTxt) ?></div>
    <div class="pr-type">
        <?php if ($modus === 'alle'): ?>
            Alle rijders vs. Nederlands <?= $recordType ?>-record (uitgebreid)
        <?php else: ?>
            Snelste tijden vs. Nederlands <?= $recordType ?>-record
        <?php endif; ?>
    </div>
  </div>
</div>

<div class="pr-toelichting">
  <?php if ($modus === 'alle'): ?>
    <b>Wat staat hierin?</b> Per combinatie van <b>afstand</b> en <b>categorie</b>
    <b>alle rijders</b> uit de geselecteerde wedstrijd, één rij per rijder met diens
    <b>snelste rondetijd</b> over de hele wedstrijd, gesorteerd op tijd. Vergelijking met
    het huidige Nederlands <?= $recordType ?>-record. <b>Δ-tijd</b> toont hoeveel langzamer
    (rood) of sneller (groen + 🏆) de prestatie is dan het record. De <b>Eind</b>-kolom
    geeft de officiële uitslag-positie (na alle rondes) — handig om te zien of de snelste
    op de klok ook werkelijk gewonnen heeft, of dat een tactische rijder met langzamere
    klok-tijd toch eerste werd in de finale ("—" = geen klassering, bv. door DNF/DQ of nog
    niet vastgelegd). De # in de laatste kolom is de rang op tijd binnen (afstand × cat).
    Bron records: KNSB-document januari 2024.
  <?php else: ?>
    <b>Wat staat hierin?</b> Per combinatie van <b>afstand</b> en <b>categorie</b> (HSA, DJB, etc.)
    de snelst gereden tijd binnen de geselecteerde wedstrijd(en), vergeleken met het huidige
    Nederlands <?= $recordType ?>-record. <b>Δ-tijd</b> toont hoeveel langzamer (rood) of sneller (groen + 🏆)
    de prestatie is dan het record. De <b>ronde/heat</b>-kolom geeft aan waar de tijd is geklokt.
    <b>Eind</b> = officiële uitslag-positie ("—" = geen klassering of nog niet vastgelegd).
    Recordhouder + record-tijd verschijnen één keer per (afstand × heren/dames); daaronder
    per categorie de snelste rijder. Bron records: KNSB-document januari 2024.
  <?php endif; ?>
</div>

<?php
renderSectie('Junioren records', $secties['junioren'] ?? [], $recordType, $modus);
renderSectie('Senioren records', $secties['senioren'] ?? [], $recordType, $modus);
?>

<div class="pr-footer">
  InlineComp · gegenereerd <?= date('j-m-Y H:i') ?> ·
  Bruto-tijden (gemeten transponder) hebben voorrang boven officiële tijden waar beschikbaar
</div>

</body>
</html>
