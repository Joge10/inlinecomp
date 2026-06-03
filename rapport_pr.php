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
),
best_per_rider AS (
    SELECT * FROM current_results WHERE rider_rn = 1
),
pr_source_results AS (
    -- Bron 1: results-tabel. MIN over alle rondes per wedstrijd ipv alleen
    -- de officiële uitslag-tijd uit uitslag_afstand. Reden: finale-tijd is
    -- vaak tactisch (langzamer dan serie-PR). bruto_tijd_ms heeft voorrang
    -- voor accuratesse. Exclusief huidige comp.
    SELECT
        he.person_license,
        d.name                                   AS distance_naam,
        COALESCE(res.bruto_tijd_ms, res.tijd_ms) AS tijd_ms,
        c.name                                   AS comp_naam,
        c.starts                                 AS comp_datum
    FROM results res
    JOIN heat_entries he  ON he.id = res.heat_entry_id
    JOIN heats        h   ON h.id  = he.heat_id
    JOIN distances    d   ON d.id  = h.distance_id
                         AND d.distance_combination_id = h.distance_combination_id
    JOIN competitions c   ON c.id  = h.competition_id
    WHERE COALESCE(res.bruto_tijd_ms, res.tijd_ms) > 0
      AND res.sanctie IS NULL
      AND h.competition_id != ?
),
pr_source_uitslag AS (
    -- Bron 2: uitslag_afstand. Voor historie-import-wedstrijden zonder
    -- heat-data (PDF-imports). Tijd hier kan tactisch zijn maar 't is wat
    -- we hebben. UNION-ALL combineert beide; MIN/ROW_NUMBER pakt de
    -- werkelijk snelste over alle bronnen.
    SELECT
        ua.person_license,
        ua.distance_naam,
        ua.tijd_ms,
        ua.competition_naam                      AS comp_naam,
        ua.competition_datum                     AS comp_datum
    FROM uitslag_afstand ua
    WHERE ua.competition_id != ?
      AND ua.tijd_ms IS NOT NULL
      AND ua.tijd_ms > 0
      AND ua.sanctie IS NULL
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
        comp_datum
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
        ROW_NUMBER() OVER (
            PARTITION BY person_license, afstand_key
            ORDER BY tijd_ms ASC, comp_datum ASC
        )                                        AS pr_rn
    FROM pr_combined
),
pr_best AS (
    SELECT person_license, afstand_key, pr_ms, pr_wedstrijd, pr_datum
    FROM pr_history
    WHERE pr_rn = 1
),
ranked AS (
    SELECT
        b.*,
        pr.pr_ms,
        pr.pr_wedstrijd,
        pr.pr_datum,
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

// Drie placeholders nu: current_results + pr_source_results + pr_source_uitslag
// (alle drie excluderen de huidige wedstrijd op verschillende manieren).
$stmt = $pdo->prepare($sql);
$stmt->execute([$compId, $compId, $compId]);
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

// Groeperen per (afstand × cat) voor render
$groepen = [];
foreach ($rows as $r) {
    $k = $r['afstand'] . '|' . $r['kat'];
    if (!isset($groepen[$k])) {
        $groepen[$k] = ['afstand' => $r['afstand'], 'kat' => $r['kat'], 'rijen' => []];
    }
    $groepen[$k]['rijen'][] = $r;
}

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
                border-left:4px solid #1a3a5c;break-after:avoid}
table{width:100%;border-collapse:collapse;font-size:8.5pt;table-layout:auto;margin-bottom:.2cm}
thead{display:table-header-group}
th{background:#dce6f0;color:#1a3a5c;padding:4px 6px;font-size:7.5pt;
   text-align:left;font-weight:600;border-bottom:1px solid #bbb;white-space:nowrap;
   vertical-align:bottom}
th small{display:block;font-size:6.5pt;font-weight:400;color:#5a7491;margin-top:1px;text-transform:none}
td{padding:3px 6px;border-bottom:1px solid #eee;white-space:nowrap;vertical-align:top}
tr:nth-child(even) td{background:#f8fafc}
.c-naam{font-weight:500}
.c-heat{font-size:7.5pt;color:#444}
.c-tijd{text-align:right;font-family:monospace;font-size:8.5pt}
.c-pr-bron{font-size:7.5pt;color:#666;font-style:italic;white-space:normal}
.c-delta{text-align:right;font-family:monospace;font-size:8.5pt;color:#b00}
.c-delta-pr{text-align:right;font-family:monospace;font-size:8.5pt;color:#0a7d2a;font-weight:700}
.c-geen-historie{font-size:7.5pt;color:#888;font-style:italic;text-align:right}
.pr-leeg{font-size:8.5pt;color:#888;font-style:italic;padding:.3cm}
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
  <b>PR-bron</b>: snelste rondetijd over <b>alle eerdere wedstrijden</b> — uit
  <code>results</code> (heat-data: serie + KF + HF + finale) plus
  <code>uitslag_afstand</code> (historie-import PDF-tijden). De serie-tijd is
  vaak sneller dan de finale-tijd (finales zijn tactisch), dus we pakken
  letterlijk de snelste rondetijd uit de hele historie.
</div>

<?php if (empty($groepen)): ?>
    <div class="pr-leeg">Geen resultaten gevonden in deze wedstrijd.</div>
<?php else: foreach ($groepen as $g): ?>
    <div class="pr-groep-titel">
        <?= esc($g['afstand']) ?> — <?= esc($g['kat']) ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>#<small>rang binnen<br>afstand+cat</small></th>
                <th>Rijder</th>
                <th>Ronde / heat<small>waar geklokt</small></th>
                <th style="text-align:right">Tijd<small>in deze wedstrijd</small></th>
                <th style="text-align:right">PR-tijd<small>snelste rondetijd<br>over alle eerdere<br>wedstrijden</small></th>
                <th>PR-bron<small>wedstrijd · datum</small></th>
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
                $prBron = $r['pr_wedstrijd'] . ' · ' . $datStr;
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
<?php endforeach; endif; ?>

<div class="pr-footer">
    InlineComp · gegenereerd <?= date('j-m-Y H:i') ?> ·
    PR-bron: snelste rondetijd uit alle eerdere wedstrijden (results + uitslag_afstand)
</div>

</body>
</html>
