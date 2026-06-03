<?php
// ============================================================
//  InlineComp – PR-check rapport
//
//  Per rijder de tijd in deze wedstrijd vergeleken met zijn/haar
//  snelste tijd ooit in het systeem op dezelfde afstand. PR uit
//  uitslag_afstand (= vastgelegde wedstrijden), exclusief de
//  huidige wedstrijd. Δ < 0 = nieuwe PR.
// ============================================================

require_once __DIR__ . '/../config_inlinecomp.php';
require_once __DIR__ . '/auth/session.php';
$_authUser = requireAuth($pdo);

$compId = trim($_GET['competition_id'] ?? '');
checkCompetitieToegang($pdo, $_authUser, $compId);
$modus = strtolower(trim($_GET['modus'] ?? 'top1'));
if (!in_array($modus, ['top1', 'alle'], true)) $modus = 'top1';

if (!$compId) {
    http_response_code(400);
    echo 'competition_id is verplicht';
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, starts, location FROM competitions WHERE id = ?");
$stmt->execute([$compId]);
$compMeta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$compMeta) {
    http_response_code(404);
    echo 'Wedstrijd niet gevonden';
    exit;
}

// Hoofdquery — voor elke rijder × afstand in huidige wedstrijd zoek de
// snelste rondetijd (rider_rn=1) en de PR uit historie (uitslag_afstand,
// exclusief huidige comp). Match op afstand_key (eerste-getal + 'm').
$sql = "
WITH current_results AS (
    SELECT
        d.name                                   AS afstand,
        d.value_meters                           AS afstand_meters,
        p.category                               AS kat,
        p.license_key                            AS p_lic,
        p.full_name                              AS rijder,
        h.heat_naam                              AS heat,
        COALESCE(tsr.ronde_type,
            CASE WHEN h.heat_naam LIKE '%finale%' THEN 'finale_a' ELSE 'heats' END
        )                                        AS ronde_type,
        h.heat_nr                                AS heat_nr,
        COALESCE(res.bruto_tijd_ms, res.tijd_ms) AS gereden_ms,
        CASE
            WHEN LOWER(d.name) LIKE '%marathon%' THEN 'marathon'
            WHEN LOWER(d.name) LIKE '%relay%' OR LOWER(d.name) LIKE '%estafette%'
                THEN LOWER(CONCAT(REGEXP_SUBSTR(d.name, '[0-9]+'), 'm-relay'))
            ELSE LOWER(CONCAT(REGEXP_SUBSTR(d.name, '[0-9]+'), 'm'))
        END                                      AS afstand_key,
        ROW_NUMBER() OVER (
            PARTITION BY p.license_key, d.name, p.category
            ORDER BY COALESCE(res.bruto_tijd_ms, res.tijd_ms)
        )                                        AS rider_rn
    FROM results res
    JOIN heat_entries           he  ON he.id = res.heat_entry_id
    JOIN heats                  h   ON h.id  = he.heat_id
    JOIN persons                p   ON p.license_key = he.person_license
    LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
    JOIN distances              d   ON d.id  = h.distance_id
                                   AND d.distance_combination_id = h.distance_combination_id
    WHERE COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
      AND res.sanctie IS NULL
      AND h.competition_id = ?
      -- Alleen sprint-afstanden: 1000m en korter. Punten-/afvalkoersen
      -- (afgaande op race_type) sluiten we expliciet uit — die tijden zijn
      -- inhoudelijk niet vergelijkbaar (tactisch, vaak DNF, totaaltijd vs
      -- rondetijd-mix). distances.race_type ENUM is hier de bron-van-waarheid.
      AND d.value_meters <= 1000
      AND COALESCE(d.race_type, 'sprint') NOT IN ('puntenkoers', 'afvalkoers')
),
best_per_rider AS (
    SELECT * FROM current_results WHERE rider_rn = 1
),
pr_source_results AS (
    -- Bron 1: results-tabel. MIN over alle rondes per wedstrijd ipv alleen
    -- de officiële uitslag-tijd uit uitslag_afstand. Reden: finale-tijd is
    -- vaak tactisch (langzamer dan serie-PR). bruto_tijd_ms heeft voorrang
    -- voor accuratesse. Filters: (a) competition_id != huidige — sluit de
    -- wedstrijd zelf hard uit, ook bij meerdaagse events waar c.starts maar
    -- 1 DATETIME-waarde heeft. (b) c.starts < huidige starts — sluit ook
    -- LATERE wedstrijden uit (voor retro-PR-checks op oude wedstrijden).
    -- Ronde-label uit tijdschema_ritten.ronde_type + heat_nr (fallback bij
    -- ontbrekende tsr-link: heat_naam-pattern).
    SELECT
        he.person_license,
        d.name                                   AS distance_naam,
        COALESCE(res.bruto_tijd_ms, res.tijd_ms) AS tijd_ms,
        c.name                                   AS comp_naam,
        c.starts                                 AS comp_datum,
        CASE COALESCE(tsr.ronde_type,
                      CASE WHEN h.heat_naam LIKE '%finale%' THEN 'finale_a'
                           ELSE 'heats' END)
            WHEN 'heats'        THEN CONCAT('Serie heat ',     COALESCE(h.heat_nr, 1))
            WHEN 'kwartfinale'  THEN CONCAT('KF heat ',        COALESCE(h.heat_nr, 1))
            WHEN 'halve_finale' THEN CONCAT('HF heat ',        COALESCE(h.heat_nr, 1))
            WHEN 'finale_a'     THEN CONCAT('A-finale heat ',  COALESCE(h.heat_nr, 1))
            WHEN 'finale_b'     THEN CONCAT('B-finale heat ',  COALESCE(h.heat_nr, 1))
            WHEN 'runner_up'    THEN CONCAT('Runner-up heat ', COALESCE(h.heat_nr, 1))
            ELSE CONCAT('R', h.ronde, ' heat ', COALESCE(h.heat_nr, 1))
        END                                      AS ronde_label
    FROM results res
    JOIN heat_entries he  ON he.id = res.heat_entry_id
    JOIN heats        h   ON h.id  = he.heat_id
    LEFT JOIN tijdschema_ritten tsr ON tsr.id = h.tijdschema_rit_id
    JOIN distances    d   ON d.id  = h.distance_id
                         AND d.distance_combination_id = h.distance_combination_id
    JOIN competitions c   ON c.id  = h.competition_id
    WHERE COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
      AND res.sanctie IS NULL
      AND h.competition_id != ?
      AND c.starts < ?
      -- Sprint-filter spiegel: alleen 1000m en korter, geen punten/afval.
      AND d.value_meters <= 1000
      AND COALESCE(d.race_type, 'sprint') NOT IN ('puntenkoers', 'afvalkoers')
),
pr_source_uitslag AS (
    -- Bron 2: uitslag_afstand. Voor historie-import-wedstrijden zonder
    -- heat-data (PDF-imports). Ronde-label uit finale_naam (vaak 'A-finale'
    -- of leeg); heat_nr is hier niet bekend. Filters: (a) competition_id !=
    -- huidige (cruciaal — als de huidige wedstrijd al is vastgelegd staat
    -- die ook in uitslag_afstand en zou anders zichzelf als PR opleveren).
    -- (b) competition_datum < huidige c.starts — extra safety voor latere
    -- wedstrijden. LET OP: c.starts is DATETIME, competition_datum is DATE
    -- — MySQL cast DATE naar 00:00:00 dus same-day uitslag van een ander
    -- competition_id zou hier kunnen lekken; filter (a) dekt dat voor
    -- DEZELFDE wedstrijd, maar same-day verschillende wedstrijden zijn
    -- bewust toegestaan (zou een echte historische tijd kunnen zijn).
    SELECT
        ua.person_license,
        ua.distance_naam,
        ua.tijd_ms,
        ua.competition_naam                      AS comp_naam,
        ua.competition_datum                     AS comp_datum,
        COALESCE(NULLIF(ua.finale_naam, ''), '')  AS ronde_label
    FROM uitslag_afstand ua
    WHERE ua.competition_id != ?
      AND ua.competition_datum < ?
      AND ua.tijd_ms IS NOT NULL
      AND ua.tijd_ms > 0
      AND ua.sanctie IS NULL
      -- Sprint-filter (geen race_type in uitslag_afstand → meters + naam-pattern).
      -- Strict: distance_meters MOET bekend zijn (NULL = niet als sprint te valideren).
      AND ua.distance_meters IS NOT NULL
      AND ua.distance_meters <= 1000
      AND LOWER(ua.distance_naam) NOT LIKE '%punten%'
      AND LOWER(ua.distance_naam) NOT LIKE '%points%'
      AND LOWER(ua.distance_naam) NOT LIKE '%afval%'
      AND LOWER(ua.distance_naam) NOT LIKE '%elimination%'
      AND LOWER(ua.distance_naam) NOT LIKE '%eliminatie%'
),
pr_combined AS (
    SELECT
        person_license,
        CASE
            WHEN LOWER(distance_naam) LIKE '%marathon%' THEN 'marathon'
            WHEN LOWER(distance_naam) LIKE '%relay%' OR LOWER(distance_naam) LIKE '%estafette%'
                THEN LOWER(CONCAT(REGEXP_SUBSTR(distance_naam, '[0-9]+'), 'm-relay'))
            ELSE LOWER(CONCAT(REGEXP_SUBSTR(distance_naam, '[0-9]+'), 'm'))
        END                                      AS afstand_key,
        tijd_ms,
        comp_naam,
        comp_datum,
        ronde_label
    FROM (
        SELECT * FROM pr_source_results
        UNION ALL
        SELECT * FROM pr_source_uitslag
    ) x
),
pr_history AS (
    SELECT
        person_license,
        afstand_key,
        tijd_ms                                  AS pr_ms,
        comp_naam                                AS pr_wedstrijd,
        comp_datum                               AS pr_datum,
        ronde_label                              AS pr_ronde,
        ROW_NUMBER() OVER (
            PARTITION BY person_license, afstand_key
            ORDER BY tijd_ms ASC, comp_datum ASC
        )                                        AS pr_rn
    FROM pr_combined
),
pr_best AS (
    SELECT person_license, afstand_key, pr_ms, pr_wedstrijd, pr_datum, pr_ronde
    FROM pr_history
    WHERE pr_rn = 1
),
ranked AS (
    SELECT
        b.*,
        pr.pr_ms,
        pr.pr_wedstrijd,
        pr.pr_datum,
        pr.pr_ronde,
        ROW_NUMBER() OVER (PARTITION BY b.afstand, b.kat ORDER BY b.gereden_ms) AS rn
    FROM best_per_rider b
    LEFT JOIN pr_best pr
           ON pr.person_license = b.p_lic
          AND pr.afstand_key    = b.afstand_key
)
SELECT * FROM ranked
" . ($modus === 'top1' ? "WHERE rn = 1" : "") . "
ORDER BY afstand_meters ASC, kat ASC, rn ASC
";

// Vijf placeholders, in CTE-volgorde:
//   1. current_results       — h.competition_id = ?   ($compId)
//   2. pr_source_results     — h.competition_id != ?  ($compId)
//   3. pr_source_results     — c.starts < ?           ($compStarts)
//   4. pr_source_uitslag     — ua.competition_id != ? ($compId)
//   5. pr_source_uitslag     — ua.competition_datum < ? ($compStarts)
// Twee filters per bron: competition_id (sluit ZICHZELF hard uit, ook bij
// meerdaagse events / DATE-vs-DATETIME cast-issues) en datum (sluit LATERE
// wedstrijden uit, voor retro-PR-checks op oude wedstrijden). NULL-starts
// (zou niet mogen voorkomen) → NULL-vergelijking is false → geen rijen →
// alle rijders "geen historie" (veilige fail-state).
$compStarts = $compMeta['starts'];
$stmt = $pdo->prepare($sql);
$stmt->execute([$compId, $compId, $compStarts, $compId, $compStarts]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmtTijd(?int $ms): string {
    if ($ms === null) return '—';
    $min = intdiv($ms, 60000); $sec = intdiv($ms % 60000, 1000); $mil = $ms % 1000;
    return $min > 0
        ? sprintf('%d:%02d.%03d', $min, $sec, $mil)
        : sprintf('0:%02d.%03d', $sec, $mil);
}
function fmtRondeHeat(string $rt, ?int $hn, string $fb): string {
    $h = $hn !== null ? (int)$hn : null;
    switch ($rt) {
        case 'heats':        return 'Serie heat '       . $h;
        case 'kwartfinale':  return 'KF heat '          . $h;
        case 'halve_finale': return 'HF heat '          . $h;
        case 'finale_a':     return 'A-finale heat '    . $h;
        case 'finale_b':     return 'B-finale heat '    . $h;
        case 'runner_up':    return 'Runner-up heat '   . $h;
        default:             return $fb ?: ($rt . ' heat ' . $h);
    }
}

// KNSB-categorie-volgorde (jong → oud, per cat eerst Dames dan Heren).
// LET OP pupillen-nummering is OMGEKEERD: P4 = jongst (≤8 jaar), P1 = oudst.
// Vandaar de aflopende P-volgorde hieronder. Verder: Kadetten, dan Junioren
// B vóór A (B is jonger), dan Senioren-Jongeren, dan Senioren A.
// Onbekende cats (bv. Masters, legacy codes) vallen achteraan via PHP_INT_MAX.
const KNSB_CAT_VOLGORDE = [
    'DP4','HP4', 'DP3','HP3', 'DP2','HP2', 'DP1','HP1',
    'DKA','HKA',
    'DJB','HJB', 'DJA','HJA',
    'DSJ','HSJ', 'DSA','HSA',
];

// Groeperen per (afstand × cat) voor render. afstand_meters wordt in de
// groep opgenomen voor de sortering hieronder (was alleen in $r-rijen
// aanwezig; nu ook op groep-niveau zodat uasort werkt).
$groepen = [];
foreach ($rows as $r) {
    $k = $r['afstand'] . '|' . $r['kat'];
    if (!isset($groepen[$k])) {
        $groepen[$k] = [
            'afstand'        => $r['afstand'],
            'afstand_meters' => (int)$r['afstand_meters'],
            'kat'            => $r['kat'],
            'rijen'          => [],
        ];
    }
    $groepen[$k]['rijen'][] = $r;
}

// Sort: afstand_meters ASC, dan KNSB-cat-volgorde ASC (D vóór H per cat).
$catRank = array_flip(KNSB_CAT_VOLGORDE);
uasort($groepen, function($a, $b) use ($catRank) {
    if ($a['afstand_meters'] !== $b['afstand_meters']) {
        return $a['afstand_meters'] <=> $b['afstand_meters'];
    }
    $ra = $catRank[$a['kat']] ?? PHP_INT_MAX;
    $rb = $catRank[$b['kat']] ?? PHP_INT_MAX;
    if ($ra !== $rb) return $ra <=> $rb;
    return strcmp($a['kat'], $b['kat']);  // stabiele fallback voor onbekende cats
});

// Pre-bereken per groep + totaal: hoeveel nieuwe PRs, hoeveel met-pr-historie,
// hoeveel zonder historie. Wordt gebruikt in groep-titel + samenvatting onderaan.
$totaal = ['nieuwe_prs' => 0, 'met_pr' => 0, 'geen_historie' => 0];
foreach ($groepen as $k => &$g) {
    $g['stats'] = ['nieuwe_prs' => 0, 'met_pr' => 0, 'geen_historie' => 0];
    foreach ($g['rijen'] as $r) {
        if ($r['pr_ms'] === null) {
            $g['stats']['geen_historie']++;
        } else {
            $g['stats']['met_pr']++;
            if ((int)$r['gereden_ms'] < (int)$r['pr_ms']) {
                $g['stats']['nieuwe_prs']++;
            }
        }
    }
    $totaal['nieuwe_prs']    += $g['stats']['nieuwe_prs'];
    $totaal['met_pr']        += $g['stats']['met_pr'];
    $totaal['geen_historie'] += $g['stats']['geen_historie'];
}
unset($g);

$titel   = 'PR-check: ' . $compMeta['name'];
$metaTxt = trim(($compMeta['starts'] ? date('j F Y', strtotime($compMeta['starts'])) : '')
              . ($compMeta['location'] ? ' · ' . $compMeta['location'] : ''));
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
.pr-groep-titel{font-size:10pt;font-weight:700;color:#1a3a5c;
                background:linear-gradient(to right,#dce6f0,transparent);
                padding:.12cm .25cm;margin:.4cm 0 .12cm 0;
                border-left:4px solid #1a3a5c;break-after:avoid;
                display:flex;justify-content:space-between;align-items:baseline;
                gap:1rem;flex-wrap:wrap}
.pr-groep-context{font-size:7.5pt;font-weight:400;color:#5a7491;font-style:italic}
/* Vaste tabel-layout zodat de naam-kolom niet meer verspringt tussen
   groepen (auto-layout past breedte aan per inhoud → bij elke nieuwe
   tabel andere kolom-breedtes). Kolom-breedtes als percentages: # 4%,
   Rijder 20%, Ronde 13%, Tijd 8%, PR-tijd 8%, PR-bron 38%, Δ 9% = 100%. */
table{width:100%;border-collapse:collapse;font-size:8.5pt;
      table-layout:fixed;margin-bottom:.2cm}
table col.c-rang   {width:4%}
table col.c-rijder {width:20%}
table col.c-ronde  {width:13%}
table col.c-tijd-w {width:8%}
table col.c-pr-w   {width:8%}
table col.c-bron-w {width:38%}
table col.c-dlt-w  {width:9%}
thead{display:table-header-group}
th{background:#dce6f0;color:#1a3a5c;padding:4px 6px;font-size:7.5pt;
   text-align:left;font-weight:600;border-bottom:1px solid #bbb;white-space:nowrap;
   vertical-align:bottom}
th small{display:block;font-size:6.5pt;font-weight:400;color:#5a7491;margin-top:1px;text-transform:none}
td{padding:3px 6px;border-bottom:1px solid #eee;white-space:nowrap;vertical-align:top;
   overflow:hidden;text-overflow:ellipsis}
tr:nth-child(even) td{background:#f8fafc}
/* Naam-kolom mag wrappen bij lange namen (Janna Wietske van der Ende) ipv
   harde ellipsis — leesbaarheid > strakke regelhoogte. */
.c-naam{font-weight:500;white-space:normal}
.c-heat{font-size:7.5pt;color:#444}
.c-tijd{text-align:right;font-family:monospace;font-size:8.5pt}
.c-pr-bron{font-size:7.5pt;color:#666;font-style:italic;white-space:normal}
.c-delta{text-align:right;font-family:monospace;font-size:8.5pt;color:#b00}
.c-delta-pr{text-align:right;font-family:monospace;font-size:8.5pt;color:#0a7d2a;font-weight:700}
.c-geen-historie{font-size:7.5pt;color:#888;font-style:italic;text-align:right}
.pr-leeg{font-size:8.5pt;color:#888;font-style:italic;padding:.3cm}
/* Samenvatting-blok onderaan: grand-total + PR-percentage. */
.pr-samenvatting{margin:.6cm 0 .3cm 0;padding:.3cm .4cm;background:#f4f7fa;
                 border:1px solid #d8e1eb;border-left:4px solid #1a3a5c;
                 border-radius:4px;break-inside:avoid;page-break-inside:avoid}
.pr-samenvatting-titel{font-size:10pt;font-weight:700;color:#1a3a5c;margin-bottom:.2cm}
.pr-samenvatting-grid{display:flex;gap:.8cm;flex-wrap:wrap;justify-content:space-between}
.pr-stat{flex:1;min-width:80px;text-align:center;padding:.15cm 0}
.pr-stat-waarde{font-size:18pt;font-weight:700;color:#1a3a5c;line-height:1.1}
.pr-stat-pr{color:#0a7d2a}
.pr-stat-label{font-size:7.5pt;color:#555;margin-top:1mm}
.pr-samenvatting-percentage{margin-top:.25cm;font-size:8.5pt;color:#444;
                            font-style:italic;text-align:center;
                            border-top:1px dashed #c4d0db;padding-top:.2cm}
.pr-footer{margin-top:.5cm;font-size:7pt;color:#888;text-align:right;
           border-top:1px solid #ddd;padding-top:2mm}
@page{size:A4 landscape;margin:.8cm 1cm}
@media print{
    body{margin:.5cm .8cm}
    .pr-header{break-after:avoid;page-break-after:avoid}
    .pr-groep-titel{break-after:avoid;page-break-after:avoid}
    table{break-inside:avoid;page-break-inside:avoid}
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
            Alle rijders vs. persoonlijk record (uitgebreid)
        <?php else: ?>
            Snelste rijders vs. persoonlijk record
        <?php endif; ?>
    </div>
  </div>
</div>

<div class="pr-toelichting">
  <b>Wat staat hierin?</b>
  <?php if ($modus === 'alle'): ?>
      Per <b>afstand</b> en <b>categorie</b> alle rijders uit deze wedstrijd
      (één rij per rijder met diens snelste rondetijd), vergeleken met hun
      <b>persoonlijk record</b> (PR) uit de vastgelegde uitslagen van eerdere
      wedstrijden.
  <?php else: ?>
      Per <b>afstand</b> en <b>categorie</b> de snelste rijder uit deze
      wedstrijd, vergeleken met diens <b>persoonlijk record</b> (PR) uit de
      vastgelegde uitslagen van eerdere wedstrijden.
  <?php endif; ?>
  <b>Δ-PR</b>: positief = langzamer dan PR, negatief + 🏆 = nieuwe PR.
  "Geen historie" = rijder heeft nog geen tijd op deze afstand in het systeem.
  <br><br>
  <b>Alleen sprint-afstanden</b> (1000m en korter). Lange afstanden als
  punten- en afvalkoersen hebben geen PR-vermelding.
  <br><br>
  <b>PR-bron</b>: snelste heat-tijd uit alle wedstrijden vóór deze wedstrijd.
  Bronnen: <code>results</code> (heat-data) plus <code>uitslag_afstand</code>
  (historie-import PDF-tijden).
</div>

<?php if (empty($groepen)): ?>
    <div class="pr-leeg">Geen resultaten gevonden in deze wedstrijd.</div>
<?php else: ?>

<!-- Samenvatting bovenaan (boven de groepen) zodat de hoofdcijfers
     direct in beeld komen — gebruiker hoeft niet door alle tabellen
     te scrollen om totaal-aantal nieuwe PRs te zien. -->
<div class="pr-samenvatting">
    <div class="pr-samenvatting-titel">📊 Samenvatting</div>
    <div class="pr-samenvatting-grid">
        <div class="pr-stat">
            <div class="pr-stat-waarde pr-stat-pr"><?= (int)$totaal['nieuwe_prs'] ?></div>
            <div class="pr-stat-label">🏆 Nieuwe PR<?= $totaal['nieuwe_prs'] === 1 ? '' : 's' ?> totaal</div>
        </div>
        <div class="pr-stat">
            <div class="pr-stat-waarde"><?= (int)$totaal['met_pr'] ?></div>
            <div class="pr-stat-label">Rijders met PR-historie</div>
        </div>
        <div class="pr-stat">
            <div class="pr-stat-waarde"><?= (int)$totaal['geen_historie'] ?></div>
            <div class="pr-stat-label">Rijders zonder historie</div>
        </div>
        <div class="pr-stat">
            <div class="pr-stat-waarde"><?= count($groepen) ?></div>
            <div class="pr-stat-label">Afstand × cat-groepen</div>
        </div>
    </div>
    <?php if ($totaal['met_pr'] > 0): ?>
        <div class="pr-samenvatting-percentage">
            <?= number_format(100 * $totaal['nieuwe_prs'] / $totaal['met_pr'], 1) ?>%
            van de rijders met historie zette een nieuwe PR in deze wedstrijd.
        </div>
    <?php endif; ?>
</div>

<?php foreach ($groepen as $g): ?>
    <?php
        // Teller-snippet voor de groep-titel: aantal nieuwe PRs + context.
        $st = $g['stats'];
        $prTxt = '';
        if ($st['nieuwe_prs'] > 0) {
            $prTxt = sprintf(' — 🏆 %d nieuwe PR%s', $st['nieuwe_prs'], $st['nieuwe_prs'] === 1 ? '' : 's');
        }
        $context = sprintf(
            '%d rijder%s · %d met PR-historie · %d zonder',
            count($g['rijen']),
            count($g['rijen']) === 1 ? '' : 's',
            $st['met_pr'],
            $st['geen_historie']
        );
    ?>
    <div class="pr-groep-titel">
        <?= esc($g['afstand']) ?> — <?= esc($g['kat']) ?><?= esc($prTxt) ?>
        <span class="pr-groep-context"><?= esc($context) ?></span>
    </div>
    <table>
        <colgroup>
            <col class="c-rang">
            <col class="c-rijder">
            <col class="c-ronde">
            <col class="c-tijd-w">
            <col class="c-pr-w">
            <col class="c-bron-w">
            <col class="c-dlt-w">
        </colgroup>
        <thead>
            <tr>
                <th>#<small>rang binnen<br>afstand+cat</small></th>
                <th>Rijder</th>
                <th>Ronde / heat<small>waar geklokt</small></th>
                <th style="text-align:right">Tijd<small>in deze wedstrijd</small></th>
                <th style="text-align:right">PR-tijd<small>snelste rondetijd<br>over alle eerdere<br>wedstrijden</small></th>
                <th>PR-bron<small>wedstrijd · datum · ronde</small></th>
                <th style="text-align:right">Δ-PR<small>+ = langzamer<br>− = nieuwe PR 🏆</small></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($g['rijen'] as $r):
            $gereden = (int)$r['gereden_ms'];
            $prMs    = $r['pr_ms'] !== null ? (int)$r['pr_ms'] : null;
            $deltaCel = '';
            $deltaCls = 'c-delta';
            if ($prMs === null) {
                $deltaCel = '<span class="c-geen-historie">geen historie</span>';
            } else {
                $delta = $gereden - $prMs;
                if ($delta < 0) {
                    $deltaCls = 'c-delta-pr';
                    $deltaCel = sprintf('%.3f s 🏆', $delta / 1000);
                } elseif ($delta === 0) {
                    $deltaCel = '±0.000 s';
                } else {
                    $deltaCel = sprintf('+%.3f s', $delta / 1000);
                }
            }
            $prBron = '';
            if ($prMs !== null && $r['pr_wedstrijd']) {
                $datStr = $r['pr_datum']
                    ? date('j-n-Y', strtotime($r['pr_datum']))
                    : '?';
                $rondeStr = !empty($r['pr_ronde']) ? ' · ' . $r['pr_ronde'] : '';
                $prBron = $r['pr_wedstrijd'] . ' · ' . $datStr . $rondeStr;
            }
        ?>
            <tr>
                <td><?= (int)$r['rn'] ?></td>
                <td class="c-naam"><?= esc($r['rijder']) ?></td>
                <td class="c-heat"><?= esc(fmtRondeHeat($r['ronde_type'],
                    $r['heat_nr'] !== null ? (int)$r['heat_nr'] : null,
                    (string)($r['heat'] ?? ''))) ?></td>
                <td class="c-tijd"><?= esc(fmtTijd($gereden)) ?></td>
                <td class="c-tijd"><?= esc(fmtTijd($prMs)) ?></td>
                <td class="c-pr-bron"><?= esc($prBron) ?></td>
                <td class="<?= $deltaCls ?>"><?= $deltaCel ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>

<?php endif; ?>

<div class="pr-footer">
    InlineComp · gegenereerd <?= date('j-m-Y H:i') ?> ·
    PR-bron: snelste rondetijd uit alle eerdere wedstrijden (results + uitslag_afstand)
</div>

</body>
</html>
