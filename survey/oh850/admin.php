<?php
// ============================================================
//  InlineComp – Admin-aggregaat-view voor de OH850-survey.
//
//  Alleen owner/admin. Toont:
//    - Totaal aantal antwoorden + datum-range
//    - Multi-select counts (welke apps gebruikt, welke andere tools)
//    - Per schaal-vraag: gemiddelde + histogram 1..5
//    - Open antwoorden (tips, gemist, kent_anders_naam)
//    - Vragen-lijst met afhandel-knop
//
//  Bewust géén koppeling tussen survey_oh850 en survey_oh850_vragen —
//  ook in deze view worden ze los gepresenteerd zodat de anonimiteits-
//  garantie zichtbaar blijft.
// ============================================================

require_once __DIR__ . '/../../../config_inlinecomp.php';
require_once __DIR__ . '/../../auth/session.php';

// requireAuth() is voor JSON-endpoints — bij ontbrekende sessie zou die een
// 401-JSON teruggeven. Voor deze HTML-pagina willen we een echte 302-redirect
// naar de login-pagina (en daarna terug hier).
$gebruiker = getSession($pdo);
if (!$gebruiker) {
    $retour = '/survey/oh850/admin.php';
    header('Location: /login.php?next=' . urlencode($retour));
    exit;
}
if (!in_array($gebruiker['role'] ?? '', ['owner', 'admin'], true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:Arial;padding:40px;color:#c00">'
       . '<h2>Geen toegang</h2><p>Alleen <b>owner</b> of <b>admin</b> kan deze pagina openen.</p>'
       . '<p>Je bent ingelogd als rol <code>' . htmlspecialchars($gebruiker['role'] ?? '?') . '</code>.</p>'
       . '<p><a href="/index.php">← terug naar Beheer</a></p>'
       . '</body></html>';
    exit;
}

// ── POST: vraag als afgehandeld markeren ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'afgehandeld') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE survey_oh850_vragen
                       SET afgehandeld_at = NOW()
                       WHERE id = ? AND afgehandeld_at IS NULL")->execute([$id]);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'heropen') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE survey_oh850_vragen
                       SET afgehandeld_at = NULL
                       WHERE id = ?")->execute([$id]);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function fmtDate($s) {
    if (!$s) return '—';
    return date('d-m-Y H:i', strtotime($s));
}

// ── Totaal + datum-range ───────────────────────────────────────────────────
$totaal = $pdo->query("
    SELECT COUNT(*)            AS n,
           MIN(submitted_at)   AS eerste,
           MAX(submitted_at)   AS laatste
    FROM survey_oh850
")->fetch(PDO::FETCH_ASSOC);
$nTot = (int)($totaal['n'] ?? 0);

// ── Schaal-vragen (1..5): gemiddelde + histogram ───────────────────────────
$scoreVragen = [
    'Algemeen' => [
        'score_algemeen' => 'Algemene ervaring InlineComp',
        'score_nps'      => 'Aanraden aan andere skater',
    ],
    'Public' => [
        'score_public_snelheid'  => 'Snelheid',
        'score_public_mobiel'    => 'Werken op mobiel',
        'score_public_uitslagen' => 'Helderheid uitslagen',
        'score_public_programma' => 'Helderheid programma / startlijsten',
    ],
    'Coach' => [
        'score_coach_snelheid'  => 'Snelheid',
        'score_coach_mobiel'    => 'Werken op mobiel',
        'score_coach_uitslagen' => 'Helderheid uitslagen',
        'score_coach_volgen'    => 'Meerdere rijders tegelijk volgen',
    ],
    'Check' => [
        'score_check_snelheid'  => 'Snelheid',
        'score_check_mobiel'    => 'Werken op mobiel',
        'score_check_duidelijk' => 'Duidelijkheid geregistreerde gegevens',
    ],
    'Vergelijking' => [
        'score_vergelijking' => 'InlineComp t.o.v. andere tools',
    ],
    'Ontwikkeling' => [
        'score_ontwikkeling' => 'Ontwikkelingsrichting sinds vorige keer',
    ],
];

$scoreStats = [];
foreach ($scoreVragen as $groep => $vragen) {
    foreach ($vragen as $col => $label) {
        $r = $pdo->query("
            SELECT AVG($col)                            AS gem,
                   COUNT($col)                          AS n,
                   SUM(CASE WHEN $col=1 THEN 1 ELSE 0 END) AS c1,
                   SUM(CASE WHEN $col=2 THEN 1 ELSE 0 END) AS c2,
                   SUM(CASE WHEN $col=3 THEN 1 ELSE 0 END) AS c3,
                   SUM(CASE WHEN $col=4 THEN 1 ELSE 0 END) AS c4,
                   SUM(CASE WHEN $col=5 THEN 1 ELSE 0 END) AS c5
            FROM survey_oh850
        ")->fetch(PDO::FETCH_ASSOC);
        $scoreStats[$col] = $r;
    }
}

// ── Multi-select counts ────────────────────────────────────────────────────
$multiVragen = [
    'Welke apps gebruikt' => [
        'used_public'  => 'Public',
        'used_coach'   => 'Coach',
        'used_check'   => 'Check',
        'used_geen'    => 'Geen',
        'used_unaware' => 'Wist niet dat InlineComp bestond',
    ],
    'Welke andere tools gekend' => [
        'kent_sportity'     => 'Sportity',
        'kent_skateresults' => 'SkateResults.app',
        'kent_combinatie'   => 'Combinatie',
        'kent_anders'       => 'Iets anders',
        'kent_geen'         => 'Nooit iets anders',
    ],
];
$multiStats = [];
foreach ($multiVragen as $groep => $vragen) {
    foreach ($vragen as $col => $label) {
        $multiStats[$col] = (int)$pdo->query("SELECT COALESCE(SUM($col), 0) FROM survey_oh850")->fetchColumn();
    }
}

// Taal-spread
$langStats = $pdo->query("
    SELECT COALESCE(lang, 'onbekend') AS lang, COUNT(*) AS n
    FROM survey_oh850
    GROUP BY lang
    ORDER BY n DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Ontwikkeling — "eerste keer"-tel (aparte metric, want die respondenten
// hebben geen score_ontwikkeling en zouden anders het gemiddelde vertekenen)
$nEersteKeer = (int)$pdo->query(
    "SELECT COALESCE(SUM(ontwikkeling_eerste_keer), 0) FROM survey_oh850"
)->fetchColumn();

// Welke wedstrijden — expand comma-separated UUID's en tel per wedstrijd.
// Toon alleen wedstrijden waarvoor daadwerkelijk minstens 1 respondent iets
// aanvinkte, gesorteerd op count aflopend.
$compTelling = [];
$rows = $pdo->query("SELECT competition_ids FROM survey_oh850 WHERE competition_ids IS NOT NULL AND competition_ids != ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($rows as $csv) {
    foreach (explode(',', $csv) as $id) {
        $id = trim($id);
        if ($id === '') continue;
        $compTelling[$id] = ($compTelling[$id] ?? 0) + 1;
    }
}
if (!empty($compTelling)) {
    $ph  = implode(',', array_fill(0, count($compTelling), '?'));
    $stm = $pdo->prepare("SELECT id, name, starts FROM competitions WHERE id IN ($ph)");
    $stm->execute(array_keys($compTelling));
    $compMeta = [];
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) $compMeta[$r['id']] = $r;
    // Bouw finale lijst { name, starts, count } en sorteer op count desc
    $compLijst = [];
    foreach ($compTelling as $id => $n) {
        $m = $compMeta[$id] ?? ['name' => '(onbekend)', 'starts' => null];
        $compLijst[] = ['name' => $m['name'], 'starts' => $m['starts'], 'n' => $n];
    }
    usort($compLijst, fn($a, $b) => $b['n'] <=> $a['n']);
} else {
    $compLijst = [];
}

// ── Open antwoorden ────────────────────────────────────────────────────────
$opens = $pdo->query("
    SELECT id, submitted_at, lang, tip_open, miste_open, kent_anders_naam
    FROM   survey_oh850
    WHERE  COALESCE(tip_open, '')        != ''
       OR  COALESCE(miste_open, '')      != ''
       OR  COALESCE(kent_anders_naam,'') != ''
    ORDER BY submitted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Vragen + email (los van survey-antwoorden) ─────────────────────────────
$vragen = $pdo->query("
    SELECT id, submitted_at, email, vraag, afgehandeld_at
    FROM   survey_oh850_vragen
    ORDER BY afgehandeld_at IS NULL DESC, submitted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$nOpenVragen = 0;
foreach ($vragen as $v) if (!$v['afgehandeld_at']) $nOpenVragen++;
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>InlineComp – Survey OH850 (admin)</title>
<style>
:root {
    --blauw: #1F4E79;
    --middenblauw: #2E75B6;
    --lichtblauw: #D6E4F0;
    --oranje: #E8630A;
    --tekst: #222;
    --border: #d3dbe3;
    --groen: #2e7d32;
}
* { box-sizing: border-box; }
body {
    font-family: 'Segoe UI', Roboto, Arial, sans-serif;
    background: #f7f9fc; color: var(--tekst);
    margin: 0; padding: 0; line-height: 1.4;
}
.wrap { max-width: 1100px; margin: 0 auto; padding: 18px 16px 60px; }
h1 { color: var(--blauw); font-size: 1.4rem; margin: 0 0 4px; }
.sub { color: #666; font-size: .88rem; margin-bottom: 18px; }

.kpi-rij {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px; margin-bottom: 18px;
}
.kpi {
    background: #fff; border: 1px solid var(--border); border-radius: 8px;
    padding: 10px 14px;
}
.kpi-lbl { font-size: .78rem; color: #777; text-transform: uppercase; letter-spacing: .5px; }
.kpi-val { font-size: 1.6rem; font-weight: 700; color: var(--blauw); margin-top: 2px; }
.kpi-sub { font-size: .76rem; color: #999; margin-top: 2px; }

section {
    background: #fff; border: 1px solid var(--border); border-radius: 8px;
    padding: 14px 16px; margin-bottom: 14px;
}
section h2 {
    margin: 0 0 10px; color: var(--blauw);
    font-size: 1.05rem; padding-bottom: 6px;
    border-bottom: 2px solid var(--oranje);
}
.groep-titel {
    font-weight: 700; font-size: .92rem; color: var(--blauw);
    margin: 12px 0 6px; padding: 2px 0;
}
.groep-titel:first-child { margin-top: 0; }

/* Schaal-vraag rij: label | gem | histogram */
.score-rij {
    display: grid;
    grid-template-columns: 1fr 70px 100px 1fr;
    gap: 10px;
    align-items: center;
    padding: 6px 0; border-bottom: 1px solid #f0f0f0;
    font-size: .9rem;
}
.score-rij:last-child { border-bottom: 0; }
.score-lbl { color: #333; }
.score-gem {
    font-weight: 700; color: var(--blauw); font-size: 1.05rem;
    text-align: right;
}
.score-n { color: #888; font-size: .78rem; text-align: right; }
.histo {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 3px;
    align-items: end; height: 36px;
}
.histo-bar {
    background: var(--lichtblauw);
    border-radius: 2px 2px 0 0;
    position: relative;
    min-height: 2px;
    transition: background .12s;
}
.histo-bar.peak { background: var(--middenblauw); }
.histo-bar:hover { background: var(--blauw); }
.histo-bar .lbl {
    position: absolute; bottom: -16px; left: 0; right: 0;
    text-align: center; font-size: .7rem; color: #777;
}
.histo-bar .cnt {
    position: absolute; top: -14px; left: 0; right: 0;
    text-align: center; font-size: .68rem; color: #555; font-weight: 600;
}
.histo-wrap { padding: 14px 0 16px; }

/* Multi-select bars */
.multi-rij {
    display: grid; grid-template-columns: 220px 50px 1fr; gap: 10px;
    align-items: center; padding: 5px 0;
    font-size: .9rem;
}
.multi-lbl { color: #333; }
.multi-cnt { font-weight: 700; color: var(--blauw); text-align: right; }
.multi-bar {
    background: #e3e8ee; border-radius: 3px; height: 14px;
    position: relative; overflow: hidden;
}
.multi-bar > span {
    display: block; background: var(--middenblauw); height: 100%;
}

/* Open antwoorden */
.open-rij {
    border-left: 3px solid var(--lichtblauw);
    background: #fafbfc;
    padding: 8px 12px; margin-bottom: 8px;
    border-radius: 0 4px 4px 0;
    font-size: .9rem;
}
.open-rij .meta {
    font-size: .74rem; color: #888; margin-bottom: 4px;
}
.open-rij .veld-lbl {
    font-size: .76rem; color: var(--blauw); font-weight: 600;
    margin-top: 4px;
}
.open-rij .veld-val { color: #333; white-space: pre-wrap; }

/* Vragen-lijst */
.vraag-rij {
    background: #fff; border: 1px solid var(--border);
    border-left: 4px solid var(--oranje);
    border-radius: 0 6px 6px 0;
    padding: 10px 12px; margin-bottom: 8px;
}
.vraag-rij.afgehandeld {
    border-left-color: var(--groen);
    background: #f3faf3;
    opacity: .8;
}
.vraag-rij .meta {
    font-size: .76rem; color: #888; display: flex;
    justify-content: space-between; align-items: center; margin-bottom: 4px;
    flex-wrap: wrap; gap: 6px;
}
.vraag-rij .email {
    color: var(--blauw); font-weight: 600;
}
.vraag-rij .email a { color: inherit; text-decoration: none; }
.vraag-rij .email a:hover { text-decoration: underline; }
.vraag-rij .vraag-tekst {
    color: #333; font-size: .92rem; white-space: pre-wrap;
    margin-top: 4px;
}
.vraag-rij form { display: inline; }
.vraag-rij button {
    border: 0; border-radius: 4px;
    padding: 3px 10px; font-size: .76rem; font-weight: 600;
    cursor: pointer;
}
.vraag-rij .btn-handle {
    background: var(--groen); color: #fff;
}
.vraag-rij .btn-reopen {
    background: #f0f0f0; color: #555;
}
.badge-afgehandeld {
    background: #e0f5e0; color: var(--groen);
    padding: 1px 7px; border-radius: 999px;
    font-size: .72rem; font-weight: 600;
}

.empty { color: #999; font-style: italic; font-size: .9rem; padding: 8px 0; }
.terug { font-size: .82rem; color: var(--middenblauw); text-decoration: none; }
.terug:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="wrap">

    <a class="terug" href="../../index.php">← Beheer</a>
    <h1>Survey Open Heerde 850 — overzicht</h1>
    <div class="sub">Anonieme feedback van deelnemers · ingelogd als <b><?= esc($gebruiker['naam'] ?? $gebruiker['username'] ?? '?') ?></b></div>

    <!-- ── KPI's ── -->
    <div class="kpi-rij">
        <div class="kpi">
            <div class="kpi-lbl">Antwoorden</div>
            <div class="kpi-val"><?= $nTot ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-lbl">Eerste antwoord</div>
            <div class="kpi-val" style="font-size:1rem"><?= esc(fmtDate($totaal['eerste'] ?? null)) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-lbl">Laatste antwoord</div>
            <div class="kpi-val" style="font-size:1rem"><?= esc(fmtDate($totaal['laatste'] ?? null)) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-lbl">Openstaande vragen</div>
            <div class="kpi-val" style="color:<?= $nOpenVragen ? 'var(--oranje)' : 'var(--groen)' ?>"><?= $nOpenVragen ?></div>
            <div class="kpi-sub">van <?= count($vragen) ?> totaal</div>
        </div>
    </div>

    <?php if ($nTot === 0): ?>
        <section>
            <div class="empty">Nog geen antwoorden binnen.</div>
        </section>
    <?php else: ?>

    <!-- ── Welke wedstrijden gebruikt ── -->
    <?php if (!empty($compLijst)): ?>
    <section>
        <h2>Bij welke wedstrijden InlineComp gebruikt?</h2>
        <?php
            $maxW = 0;
            foreach ($compLijst as $w) $maxW = max($maxW, $w['n']);
            if ($maxW === 0) $maxW = 1;
        ?>
        <?php foreach ($compLijst as $w):
            $pct = round($w['n'] * 100 / $maxW);
            $datum = '';
            if (!empty($w['starts'])) {
                $ts = strtotime($w['starts']);
                if ($ts) $datum = date('j M Y', $ts);
            }
        ?>
        <div class="multi-rij">
            <div class="multi-lbl">
                <?= esc($w['name']) ?>
                <?php if ($datum !== ''): ?>
                <span style="color:#888;font-size:.82rem">· <?= esc($datum) ?></span>
                <?php endif; ?>
            </div>
            <div class="multi-cnt"><?= $w['n'] ?></div>
            <div class="multi-bar"><span style="width:<?= $pct ?>%"></span></div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <!-- ── Multi-select counts ── -->
    <section>
        <h2>Multiple-choice antwoorden</h2>
        <?php foreach ($multiVragen as $groep => $vragen): ?>
            <div class="groep-titel"><?= esc($groep) ?></div>
            <?php
                $maxC = 0;
                foreach ($vragen as $col => $_) $maxC = max($maxC, $multiStats[$col]);
                if ($maxC === 0) $maxC = 1;
            ?>
            <?php foreach ($vragen as $col => $label): ?>
                <?php $c = $multiStats[$col]; $pct = round($c * 100 / $maxC); ?>
                <div class="multi-rij">
                    <div class="multi-lbl"><?= esc($label) ?></div>
                    <div class="multi-cnt"><?= $c ?></div>
                    <div class="multi-bar"><span style="width:<?= $pct ?>%"></span></div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="groep-titel" style="margin-top:16px">Taal</div>
        <?php
            $maxL = 0;
            foreach ($langStats as $l) $maxL = max($maxL, (int)$l['n']);
            if ($maxL === 0) $maxL = 1;
        ?>
        <?php foreach ($langStats as $l): ?>
            <?php $pct = round((int)$l['n'] * 100 / $maxL); ?>
            <div class="multi-rij">
                <div class="multi-lbl"><?= esc(strtoupper($l['lang'])) ?></div>
                <div class="multi-cnt"><?= (int)$l['n'] ?></div>
                <div class="multi-bar"><span style="width:<?= $pct ?>%"></span></div>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- ── Schaal-vragen 1..5 met histogram ── -->
    <section>
        <h2>Scores (schaal 1–5)</h2>
        <?php foreach ($scoreVragen as $groep => $vragen): ?>
            <div class="groep-titel">
                <?= esc($groep) ?>
                <?php if ($groep === 'Ontwikkeling' && $nEersteKeer > 0): ?>
                <span style="font-size:.82rem;font-weight:400;color:#666;margin-left:8px">
                    (+<?= $nEersteKeer ?> respondenten gebruikten InlineComp voor het eerst)
                </span>
                <?php endif; ?>
            </div>
            <?php foreach ($vragen as $col => $label):
                $s = $scoreStats[$col];
                $gem = $s['gem'] !== null ? round((float)$s['gem'], 2) : null;
                $n   = (int)$s['n'];
                $counts = [
                    1 => (int)$s['c1'], 2 => (int)$s['c2'], 3 => (int)$s['c3'],
                    4 => (int)$s['c4'], 5 => (int)$s['c5'],
                ];
                $maxC = max($counts) ?: 1;
            ?>
                <div class="score-rij">
                    <div class="score-lbl"><?= esc($label) ?></div>
                    <div class="score-gem"><?= $gem !== null ? esc(number_format($gem, 2, ',', '')) : '—' ?></div>
                    <div class="score-n">n=<?= $n ?></div>
                    <div class="histo-wrap">
                        <div class="histo">
                            <?php for ($i = 1; $i <= 5; $i++):
                                $c = $counts[$i];
                                $h = round($c * 100 / $maxC);
                                $isPeak = ($c === $maxC && $c > 0);
                            ?>
                                <div class="histo-bar<?= $isPeak ? ' peak' : '' ?>" style="height:<?= max(2,$h) ?>%" title="<?= $c ?> × <?= $i ?>">
                                    <div class="cnt"><?= $c > 0 ? $c : '' ?></div>
                                    <div class="lbl"><?= $i ?></div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </section>

    <!-- ── Open antwoorden ── -->
    <section>
        <h2>Open antwoorden (<?= count($opens) ?>)</h2>
        <?php if (!count($opens)): ?>
            <div class="empty">Nog geen open antwoorden.</div>
        <?php else: ?>
            <?php foreach ($opens as $o): ?>
                <div class="open-rij">
                    <div class="meta">
                        #<?= (int)$o['id'] ?> · <?= esc(fmtDate($o['submitted_at'])) ?> · <?= esc(strtoupper($o['lang'] ?? '?')) ?>
                    </div>
                    <?php if (trim((string)$o['miste_open']) !== ''): ?>
                        <div class="veld-lbl">Wat miste je / wat had je beter gewild?</div>
                        <div class="veld-val"><?= esc($o['miste_open']) ?></div>
                    <?php endif; ?>
                    <?php if (trim((string)$o['tip_open']) !== ''): ?>
                        <div class="veld-lbl">Tips / ideeën?</div>
                        <div class="veld-val"><?= esc($o['tip_open']) ?></div>
                    <?php endif; ?>
                    <?php if (trim((string)$o['kent_anders_naam']) !== ''): ?>
                        <div class="veld-lbl">Andere tool genoemd</div>
                        <div class="veld-val"><?= esc($o['kent_anders_naam']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php endif; ?>

    <!-- ── Vragen (los van survey, eigen tabel) ── -->
    <section>
        <h2>Vragen voor jou (<?= count($vragen) ?> · <?= $nOpenVragen ?> open)</h2>
        <div class="sub" style="margin:-6px 0 12px">
            Deze vragen zijn opgeslagen in een aparte tabel zonder koppeling
            naar de survey-antwoorden. Mail-adres alleen zichtbaar bij de vraag.
        </div>
        <?php if (!count($vragen)): ?>
            <div class="empty">Nog geen vragen binnen.</div>
        <?php else: ?>
            <?php foreach ($vragen as $v): ?>
                <div class="vraag-rij<?= $v['afgehandeld_at'] ? ' afgehandeld' : '' ?>">
                    <div class="meta">
                        <span>
                            #<?= (int)$v['id'] ?> ·
                            <?= esc(fmtDate($v['submitted_at'])) ?> ·
                            <span class="email">
                                <a href="mailto:<?= esc($v['email']) ?>"><?= esc($v['email']) ?></a>
                            </span>
                        </span>
                        <span>
                            <?php if ($v['afgehandeld_at']): ?>
                                <span class="badge-afgehandeld">✓ afgehandeld <?= esc(fmtDate($v['afgehandeld_at'])) ?></span>
                                <form method="post" style="margin-left:6px">
                                    <input type="hidden" name="action" value="heropen">
                                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                    <button type="submit" class="btn-reopen">Heropen</button>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="afgehandeld">
                                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                    <button type="submit" class="btn-handle">Markeer afgehandeld</button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="vraag-tekst"><?= esc($v['vraag']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

</div>
</body>
</html>
