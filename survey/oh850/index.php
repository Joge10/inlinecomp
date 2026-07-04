<?php
// ============================================================
//  InlineComp – Anonieme survey OH850
//
//  Bewust géén login, géén KNSB-koppeling. Twee tabellen:
//    - survey_oh850          (anonieme antwoorden)
//    - survey_oh850_vragen   (follow-up-vragen met email)
//  De twee inserts gebeuren los van elkaar, met een random sleep-jitter
//  ertussen, zodat de timestamps niet aan elkaar te koppelen zijn.
//
//  GEEN IP-loggen, GEEN session-cookies. Spam-risico is acceptabel
//  voor één-week-actie met 150-500 verwachte respondenten.
//
//  Endpoints (POST action=...):
//    submit  → valideer + insert + return JSON
// ============================================================

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../../config_inlinecomp.php';

// ── Submit-endpoint (AJAX) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    header('Content-Type: application/json; charset=utf-8');

    // Helpers
    $score = function($k) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v === '') return null;
        $n = (int)$v;
        return ($n >= 1 && $n <= 5) ? $n : null;
    };
    $bool = function($k) {
        $v = $_POST[$k] ?? '';
        return ($v === '1' || $v === 'on' || $v === 'true') ? 1 : 0;
    };
    $text = function($k, $max = 2000) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v === '') return null;
        return mb_substr($v, 0, $max);
    };
    // Comma-gescheiden UUID's: valideer elk item strikt op UUID-formaat
    // en dedup. Alles wat niet aan format voldoet wordt genegeerd, geen
    // fout — we willen liever een halfvolle lijst dan een submit-fail.
    $uuidList = function($k) {
        $raw = $_POST[$k] ?? '';
        if (is_array($raw)) $items = $raw;
        else                $items = explode(',', (string)$raw);
        $ok = [];
        foreach ($items as $it) {
            $it = trim((string)$it);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $it)) {
                $ok[strtolower($it)] = true;
            }
        }
        return $ok ? implode(',', array_keys($ok)) : null;
    };
    $lang = preg_match('/^(nl|en)$/i', $_POST['lang'] ?? '') ? strtolower($_POST['lang']) : 'nl';

    try {
        // Schrijf in willekeurige volgorde, met jitter, zodat survey-rij en
        // vraag-rij niet via timestamp gekoppeld kunnen worden.
        $vraagEmail = $text('vraag_email', 255);
        $vraagTekst = $text('vraag_tekst', 5000);
        $heeftVraag = ($vraagEmail !== null && $vraagTekst !== null
                       && filter_var($vraagEmail, FILTER_VALIDATE_EMAIL));

        // Volgorde-coin-flip + 50-500ms sleep tussen inserts
        $vraagEerst = $heeftVraag && (mt_rand(0, 1) === 0);

        $insertSurvey = function() use ($pdo, $score, $bool, $text, $uuidList, $lang) {
            // Als "eerste keer"-checkbox aan staat, is score_ontwikkeling niet
            // zinvol — forceer NULL zodat we ontwikkelings-trends niet vervuilen
            // met arbitraire waarden van eerste-keer-gebruikers.
            $eersteKeer = $bool('ontwikkeling_eerste_keer');
            $scoreOntw  = $eersteKeer ? null : $score('score_ontwikkeling');
            $stmt = $pdo->prepare("
                INSERT INTO survey_oh850 (
                    lang,
                    used_public, used_coach, used_check, used_geen, used_unaware,
                    competition_ids,
                    score_algemeen, score_nps,
                    score_public_snelheid, score_public_mobiel,
                    score_public_uitslagen, score_public_programma,
                    score_coach_snelheid, score_coach_mobiel,
                    score_coach_uitslagen, score_coach_volgen,
                    score_check_snelheid, score_check_mobiel, score_check_duidelijk,
                    kent_sportity, kent_skateresults, kent_combinatie,
                    kent_anders, kent_geen, kent_anders_naam,
                    score_vergelijking,
                    score_ontwikkeling, ontwikkeling_eerste_keer,
                    tip_open, miste_open
                ) VALUES (
                    ?,
                    ?, ?, ?, ?, ?,
                    ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?,
                    ?, ?,
                    ?, ?
                )
            ");
            $stmt->execute([
                $lang,
                $bool('used_public'), $bool('used_coach'), $bool('used_check'), $bool('used_geen'), $bool('used_unaware'),
                $uuidList('competition_ids'),
                $score('score_algemeen'), $score('score_nps'),
                $score('score_public_snelheid'), $score('score_public_mobiel'),
                $score('score_public_uitslagen'), $score('score_public_programma'),
                $score('score_coach_snelheid'), $score('score_coach_mobiel'),
                $score('score_coach_uitslagen'), $score('score_coach_volgen'),
                $score('score_check_snelheid'), $score('score_check_mobiel'), $score('score_check_duidelijk'),
                $bool('kent_sportity'), $bool('kent_skateresults'), $bool('kent_combinatie'),
                $bool('kent_anders'), $bool('kent_geen'), $text('kent_anders_naam', 80),
                $score('score_vergelijking'),
                $scoreOntw, $eersteKeer,
                $text('tip_open'), $text('miste_open'),
            ]);
        };
        $insertVraag = function() use ($pdo, $vraagEmail, $vraagTekst) {
            $stmt = $pdo->prepare("
                INSERT INTO survey_oh850_vragen (email, vraag)
                VALUES (?, ?)
            ");
            $stmt->execute([$vraagEmail, $vraagTekst]);
        };

        if ($vraagEerst) {
            $insertVraag();
            usleep(mt_rand(50000, 500000));
            $insertSurvey();
        } else {
            $insertSurvey();
            if ($heeftVraag) {
                usleep(mt_rand(50000, 500000));
                $insertVraag();
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'submit failed']);
        // Niet de exception-message terugsturen — minder info-disclosure
        error_log('survey_oh850 submit failed: ' . $e->getMessage());
        exit;
    }
}

// ── Wedstrijden-lijst voor multi-select ────────────────────────────────────
// Toont alle publiek-zichtbare wedstrijden van het huidige + afgelopen
// seizoen (kalenderjaar-basis: in 2026 zie je 2025 + 2026). Gesorteerd op
// datum aflopend (recentste bovenaan) — de meest waarschijnlijke aanleiding
// voor het invullen van de survey staat als eerste.
try {
    $stmtWed = $pdo->prepare("
        SELECT id, name, starts, venue_city
        FROM competitions
        WHERE public_zichtbaar = 1
          AND starts IS NOT NULL
          AND YEAR(starts) >= (YEAR(CURDATE()) - 1)
        ORDER BY starts DESC
    ");
    $stmtWed->execute();
    $wedstrijdenLijst = $stmtWed->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $wedstrijdenLijst = [];
    error_log('survey wedstrijden ophalen: ' . $e->getMessage());
}
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<link rel="icon" type="image/svg+xml" href="../../favicon.svg">
<title data-i18n="title">InlineComp — jouw feedback</title>
<style>
:root {
    --blauw: #1F4E79;
    --middenblauw: #2E75B6;
    --lichtblauw: #D6E4F0;
    --oranje: #E8630A;
    --tekst: #222;
    --grijs: #f5f5f5;
    --border: #d3dbe3;
    --groen: #2e7d32;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Roboto, Arial, sans-serif;
    color: var(--tekst);
    background: #f7f9fc;
    line-height: 1.4;
}
.wrap {
    max-width: 720px;
    margin: 0 auto;
    padding: 16px 14px 80px;
}
header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
}
header h1 {
    margin: 0; font-size: 1.25rem;
    color: var(--blauw); letter-spacing: -.3px;
}
header .sub {
    font-size: .82rem; color: #666; margin-top: 2px;
}
.lang-toggle {
    display: flex; gap: 4px;
    background: #fff; border: 1px solid var(--border);
    border-radius: 999px; padding: 3px;
}
.lang-toggle button {
    border: 0; background: transparent;
    padding: 4px 12px; border-radius: 999px;
    font-size: .82rem; font-weight: 600;
    cursor: pointer; color: #555;
}
.lang-toggle button.actief {
    background: var(--blauw); color: #fff;
}
.intro {
    background: #fff; border: 1px solid var(--border); border-radius: 8px;
    padding: 12px 14px; margin-bottom: 16px;
    font-size: .92rem;
}
.intro p { margin: 0 0 6px; }
.intro p:last-child { margin-bottom: 0; }
.intro .anon {
    display: inline-flex; align-items: center; gap: 5px;
    background: #e8f5e9; color: var(--groen);
    padding: 2px 8px; border-radius: 999px;
    font-size: .76rem; font-weight: 600; margin-top: 4px;
}
section.q {
    background: #fff; border: 1px solid var(--border); border-radius: 8px;
    padding: 12px 14px; margin-bottom: 12px;
}
section.q.subtle { background: #fafbfc; }
section.q.sub {
    background: #fff; border: 1px solid #e8eef4; border-radius: 6px;
    padding: 10px 12px; margin-bottom: 8px;
}
section.q.sub:last-child { margin-bottom: 0; }
section.q-group {
    background: linear-gradient(180deg, #eaf2fa 0%, #f7f9fc 100%);
    border: 1px solid var(--middenblauw); border-radius: 8px;
    padding: 10px 12px 12px; margin-bottom: 12px;
}
.q-group-titel {
    font-weight: 700; color: var(--blauw);
    font-size: 1.02rem; margin-bottom: 8px;
    padding: 2px 4px;
}
.q-lbl { font-weight: 600; font-size: .96rem; margin-bottom: 8px; }
.q-hint { font-size: .8rem; color: #888; margin-bottom: 8px; }

/* ── Checkbox-grid (multi-select) ───────────────────────────────────── */
.chk-grid {
    display: grid; grid-template-columns: 1fr; gap: 6px;
}
@media (min-width: 480px) {
    .chk-grid.cols-2 { grid-template-columns: 1fr 1fr; }
}
.chk-lbl {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px; border: 1px solid var(--border);
    border-radius: 6px; cursor: pointer;
    transition: background .12s, border-color .12s;
    font-size: .92rem; background: #fff;
}
.chk-lbl:hover { background: #f5f8fc; }
.chk-lbl input { margin: 0; width: 18px; height: 18px; accent-color: var(--blauw); }
.chk-lbl.checked { background: #eaf2fa; border-color: var(--middenblauw); }

/* ── 1-5 bolletjes-rij ──────────────────────────────────────────────── */
.scale-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    margin: 6px 0 4px;
}
.scale-row label {
    position: relative;
    display: flex; flex-direction: column;
    align-items: center;
    cursor: pointer;
    padding: 10px 0 6px;
    border-radius: 8px;
    background: #fff;
    border: 1px solid var(--border);
    user-select: none;
    transition: background .12s, border-color .12s, transform .08s;
    min-height: 56px;
}
.scale-row label:hover { background: #f0f6fc; border-color: var(--middenblauw); }
.scale-row label input { position: absolute; opacity: 0; pointer-events: none; }
.scale-row label .dot {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #e3e8ee;
    margin-bottom: 5px;
    transition: background .12s;
}
.scale-row label .num {
    font-size: .9rem; color: #555; font-weight: 600;
}
.scale-row label.sel { background: var(--blauw); border-color: var(--blauw); }
.scale-row label.sel .dot { background: #fff; }
.scale-row label.sel .num { color: #fff; }
.scale-row label.sel:hover { background: var(--blauw); }
.scale-extremes {
    display: flex; justify-content: space-between;
    font-size: .74rem; color: #888;
    margin-top: 2px;
}

/* ── Open-text vragen ───────────────────────────────────────────────── */
textarea, input[type=email], input[type=text] {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-family: inherit; font-size: .94rem;
    background: #fff; color: var(--tekst);
    resize: vertical;
}
textarea { min-height: 70px; }
textarea:focus, input:focus {
    outline: none; border-color: var(--middenblauw);
    box-shadow: 0 0 0 3px rgba(46,117,182,.15);
}

/* ── Submit-knop ────────────────────────────────────────────────────── */
.submit-row {
    margin-top: 18px; text-align: center;
}
.btn-submit {
    background: var(--oranje); color: #fff;
    border: 0; border-radius: 8px;
    padding: 12px 28px;
    font-size: 1rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 2px 8px rgba(232,99,10,.25);
    transition: background .15s;
}
.btn-submit:hover { background: #cf5409; }
.btn-submit:disabled { background: #bbb; box-shadow: none; cursor: not-allowed; }

/* ── Bedankt-scherm ─────────────────────────────────────────────────── */
.thanks {
    background: #fff; border: 2px solid var(--groen); border-radius: 12px;
    padding: 30px 22px; text-align: center;
    margin-top: 20px;
}
.thanks .icon { font-size: 3rem; line-height: 1; margin-bottom: 8px; }
.thanks h2 { margin: 0 0 8px; color: var(--groen); }
.thanks p { margin: 0; color: #555; }

/* ── Conditioneel zichtbaar ─────────────────────────────────────────── */
.hidden { display: none !important; }

/* ── Submit-fout ────────────────────────────────────────────────────── */
.submit-err {
    background: #fce4e4; border: 1px solid #f5b5b5; color: #b71c1c;
    padding: 8px 12px; border-radius: 6px;
    margin-top: 10px; font-size: .9rem;
    display: none;
}

footer {
    text-align: center; font-size: .72rem; color: #999;
    margin-top: 24px;
}
</style>
</head>
<body>
<div class="wrap" id="wrap">
    <header>
        <div>
            <h1 data-i18n="h1">InlineComp — jouw feedback</h1>
            <div class="sub" data-i18n="sub">Korte enquête — duurt ca. 2 minuten</div>
        </div>
        <div class="lang-toggle" role="group" aria-label="taal">
            <button type="button" data-lang="nl" class="actief">🇳🇱 NL</button>
            <button type="button" data-lang="en">🇬🇧 EN</button>
        </div>
    </header>

    <div class="intro">
        <p data-i18n="intro1">
            Bedankt voor het gebruiken van InlineComp! We willen 'm graag verbeteren —
            laat je weten wat je vond? Anoniem, geen account nodig.
        </p>
        <span class="anon">🔒 <span data-i18n="intro_anon">100% anoniem</span></span>
    </div>

    <form id="survey-form">

    <!-- 0. Bij welke wedstrijd(en) heb je InlineComp gebruikt? -->
    <?php if (!empty($wedstrijdenLijst)): ?>
    <section class="q">
        <div class="q-lbl" data-i18n="q_comps">1. Bij welke wedstrijd(en) heb je InlineComp gebruikt?</div>
        <div class="q-hint" data-i18n="q_comps_hint">Meerdere antwoorden mogelijk — laat leeg als je 't niet meer weet</div>
        <div class="chk-grid">
            <?php foreach ($wedstrijdenLijst as $w):
                $datumLabel = '';
                if (!empty($w['starts'])) {
                    $ts = strtotime($w['starts']);
                    if ($ts) $datumLabel = date('j M Y', $ts);
                }
                $stad = trim((string)($w['venue_city'] ?? ''));
                $meta = trim($datumLabel . ($stad !== '' ? ' · ' . $stad : ''));
            ?>
            <label class="chk-lbl">
                <input type="checkbox" name="competition_ids[]" value="<?= htmlspecialchars($w['id'], ENT_QUOTES) ?>">
                <span>
                    <?= htmlspecialchars($w['name']) ?>
                    <?php if ($meta !== ''): ?>
                    <span style="color:#888;font-size:.82rem;display:block;margin-top:1px"><?= htmlspecialchars($meta) ?></span>
                    <?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- 1. Welke app(s) gebruikt? -->
    <section class="q">
        <div class="q-lbl" data-i18n="q_used">2. Welke onderdelen heb je gebruikt?</div>
        <div class="q-hint" data-i18n="q_used_hint">Meerdere antwoorden mogelijk</div>
        <div class="chk-grid cols-2">
            <label class="chk-lbl"><input type="checkbox" name="used_public" data-show="#sec-public"> <span data-i18n="opt_public">Public — live-uitslag voor publiek</span></label>
            <label class="chk-lbl"><input type="checkbox" name="used_coach" data-show="#sec-coach"> <span data-i18n="opt_coach">Coach — overzicht voor coaches</span></label>
            <label class="chk-lbl"><input type="checkbox" name="used_check" data-show="#sec-check"> <span data-i18n="opt_check">Check — inschrijving vooraf controleren</span></label>
            <label class="chk-lbl"><input type="checkbox" name="used_geen"> <span data-i18n="opt_geen">Geen</span></label>
            <label class="chk-lbl"><input type="checkbox" name="used_unaware"> <span data-i18n="opt_unaware">Wist niet dat InlineComp bestond</span></label>
        </div>
    </section>

    <!-- 2. Algemene ervaring -->
    <section class="q">
        <div class="q-lbl" data-i18n="q_algemeen">2. Algemene ervaring met InlineComp</div>
        <div class="scale-row" data-name="score_algemeen"></div>
        <div class="scale-extremes"><span data-i18n="scale_low">1 = heel slecht</span><span data-i18n="scale_high">5 = uitmuntend</span></div>
    </section>

    <!-- 3. PUBLIC-blok (alleen als used_public) -->
    <section class="q-group hidden" id="sec-public">
        <div class="q-group-titel" data-i18n="grp_public">📺 Public — live-uitslag</div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_public_snelheid">Snelheid</div>
            <div class="scale-row" data-name="score_public_snelheid"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_slow">1 = traag</span><span data-i18n="scale_high_fast">5 = snel</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_public_mobiel">Werken op mobiel</div>
            <div class="scale-row" data-name="score_public_mobiel"></div>
            <div class="scale-extremes"><span data-i18n="scale_low">1 = heel slecht</span><span data-i18n="scale_high">5 = uitmuntend</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_public_uitslagen">Helderheid uitslagen</div>
            <div class="scale-row" data-name="score_public_uitslagen"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_unclear">1 = onduidelijk</span><span data-i18n="scale_high_clear">5 = glashelder</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_public_programma">Helderheid programma / startlijsten</div>
            <div class="scale-row" data-name="score_public_programma"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_unclear">1 = onduidelijk</span><span data-i18n="scale_high_clear">5 = glashelder</span></div>
        </div>
    </section>

    <!-- 4. COACH-blok (alleen als used_coach) -->
    <section class="q-group hidden" id="sec-coach">
        <div class="q-group-titel" data-i18n="grp_coach">🧑‍🏫 Coach — overzicht voor coaches</div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_coach_snelheid">Snelheid</div>
            <div class="scale-row" data-name="score_coach_snelheid"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_slow">1 = traag</span><span data-i18n="scale_high_fast">5 = snel</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_coach_mobiel">Werken op mobiel</div>
            <div class="scale-row" data-name="score_coach_mobiel"></div>
            <div class="scale-extremes"><span data-i18n="scale_low">1 = heel slecht</span><span data-i18n="scale_high">5 = uitmuntend</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_coach_uitslagen">Helderheid uitslagen</div>
            <div class="scale-row" data-name="score_coach_uitslagen"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_unclear">1 = onduidelijk</span><span data-i18n="scale_high_clear">5 = glashelder</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_coach_volgen">Hoe makkelijk kon je <b>meerdere rijders tegelijk</b> in de gaten houden (programma + startlijsten + uitslagen op één scherm)?</div>
            <div class="scale-row" data-name="score_coach_volgen"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_hard">1 = lastig</span><span data-i18n="scale_high_easy">5 = heel makkelijk</span></div>
        </div>
    </section>

    <!-- 5. CHECK-blok (alleen als used_check) -->
    <section class="q-group hidden" id="sec-check">
        <div class="q-group-titel" data-i18n="grp_check">✅ Check — inschrijving controleren</div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_check_snelheid">Snelheid</div>
            <div class="scale-row" data-name="score_check_snelheid"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_slow">1 = traag</span><span data-i18n="scale_high_fast">5 = snel</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_check_mobiel">Werken op mobiel</div>
            <div class="scale-row" data-name="score_check_mobiel"></div>
            <div class="scale-extremes"><span data-i18n="scale_low">1 = heel slecht</span><span data-i18n="scale_high">5 = uitmuntend</span></div>
        </div>
        <div class="q sub">
            <div class="q-lbl" data-i18n="q_check_duidelijk">Duidelijkheid van je geregistreerde gegevens</div>
            <div class="scale-row" data-name="score_check_duidelijk"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_unclear">1 = onduidelijk</span><span data-i18n="scale_high_clear">5 = glashelder</span></div>
        </div>
    </section>

    <!-- 6. NPS (algemeen) -->
    <section class="q">
        <div class="q-lbl" data-i18n="q_nps">Zou je InlineComp aanraden aan een andere skater?</div>
        <div class="scale-row" data-name="score_nps"></div>
        <div class="scale-extremes"><span data-i18n="scale_low_no">1 = zeker niet</span><span data-i18n="scale_high_yes">5 = absoluut</span></div>
    </section>

    <!-- 4. Vergelijking met andere tools -->
    <section class="q">
        <div class="q-lbl" data-i18n="q_kent">8. Welke andere tools ken je?</div>
        <div class="q-hint" data-i18n="q_kent_hint">Meerdere antwoorden mogelijk</div>
        <div class="chk-grid cols-2">
            <label class="chk-lbl"><input type="checkbox" name="kent_sportity" data-tools="1"> Sportity</label>
            <label class="chk-lbl"><input type="checkbox" name="kent_skateresults" data-tools="1"> SkateResults.app</label>
            <label class="chk-lbl"><input type="checkbox" name="kent_combinatie" data-tools="1"> <span data-i18n="opt_combinatie">Combinatie van bovenstaande</span></label>
            <label class="chk-lbl"><input type="checkbox" name="kent_anders" data-tools="1" data-show="#sec-kent-anders"> <span data-i18n="opt_anders">Iets anders</span></label>
            <label class="chk-lbl"><input type="checkbox" name="kent_geen"> <span data-i18n="opt_geen_tool">Nooit iets anders gebruikt</span></label>
        </div>
        <div id="sec-kent-anders" class="hidden" style="margin-top:8px">
            <input type="text" name="kent_anders_naam" data-i18n-ph="ph_anders_naam" placeholder="Welke tool? (optioneel)">
        </div>
    </section>

    <section class="q hidden" id="sec-vergelijking">
        <div class="q-lbl" data-i18n="q_vergelijking">9. Hoe vond je InlineComp t.o.v. de andere tool(s)?</div>
        <div class="scale-row" data-name="score_vergelijking"></div>
        <div class="scale-extremes"><span data-i18n="scale_low_worse">1 = veel slechter</span><span data-i18n="scale_high_better">5 = veel beter</span></div>
    </section>

    <!-- Ontwikkelingsrichting -->
    <section class="q">
        <div class="q-lbl" data-i18n="q_ontwikkeling">10. Hoe vind je dat InlineComp zich ontwikkelt sinds vorige keer?</div>
        <label class="chk-lbl" style="margin-bottom:8px" id="lbl-eerste-keer">
            <input type="checkbox" name="ontwikkeling_eerste_keer" id="cb-eerste-keer" data-hide="#sec-ontw-schaal">
            <span data-i18n="opt_eerste_keer">Ik gebruik InlineComp voor het eerst</span>
        </label>
        <div id="sec-ontw-schaal">
            <div class="scale-row" data-name="score_ontwikkeling"></div>
            <div class="scale-extremes"><span data-i18n="scale_low_worse">1 = veel slechter</span><span data-i18n="scale_high_better">5 = veel beter</span></div>
        </div>
    </section>

    <!-- Open vragen -->
    <section class="q subtle">
        <div class="q-lbl" data-i18n="q_miste">11. Wat miste je / wat had je beter gewild?</div>
        <textarea name="miste_open" data-i18n-ph="ph_miste" placeholder="Optioneel — laat leeg als je niets specifieks hebt"></textarea>
    </section>

    <section class="q subtle">
        <div class="q-lbl" data-i18n="q_tip">12. Tips / ideeën voor verbetering?</div>
        <textarea name="tip_open" data-i18n-ph="ph_tip" placeholder="Optioneel"></textarea>
    </section>

    <!-- Vraag voor Geert (optioneel + email) -->
    <section class="q subtle">
        <div class="q-lbl" data-i18n="q_vraag">13. Heb je een vraag voor mij?</div>
        <div class="q-hint" data-i18n="q_vraag_hint">
            Volledig optioneel. Als je iets invult: laat ook je e-mailadres achter zodat ik kan reageren.
            Je e-mail wordt los van je antwoorden opgeslagen — er is geen koppeling.
        </div>
        <textarea name="vraag_tekst" data-i18n-ph="ph_vraag" placeholder="Je vraag (optioneel)"></textarea>
        <div style="margin-top:8px">
            <input type="email" name="vraag_email" data-i18n-ph="ph_email" placeholder="Je e-mail (alleen als je een vraag hebt)" autocomplete="email">
        </div>
    </section>

    <div class="submit-row">
        <button type="submit" class="btn-submit" id="btn-submit" data-i18n="btn_submit">Verstuur</button>
        <div class="submit-err" id="submit-err"></div>
    </div>

    </form>

    <div class="thanks hidden" id="thanks">
        <div class="icon">🙏</div>
        <h2 data-i18n="thanks_h">Bedankt voor je feedback!</h2>
        <p data-i18n="thanks_p">Je antwoorden helpen InlineComp beter te maken.</p>
    </div>

    <footer>
        InlineComp &copy; <?= date('Y') ?> Geert de Vries
    </footer>
</div>

<script>
// ── Translations (NL / EN) ──────────────────────────────────────────────────
const T = {
    nl: {
        title: 'InlineComp — jouw feedback',
        h1: 'InlineComp — jouw feedback',
        sub: 'Korte enquête — duurt ca. 2 minuten',
        intro1: 'Bedankt voor het gebruiken van InlineComp! We willen \'m graag verbeteren — laat je weten wat je vond? Anoniem, geen account nodig.',
        intro_anon: '100% anoniem',
        q_comps: '1. Bij welke wedstrijd(en) heb je InlineComp gebruikt?',
        q_comps_hint: 'Meerdere antwoorden mogelijk — laat leeg als je \'t niet meer weet',
        q_used: '2. Welke onderdelen heb je gebruikt?',
        q_used_hint: 'Meerdere antwoorden mogelijk',
        opt_public: 'Public — live-uitslag voor publiek',
        opt_coach: 'Coach — overzicht voor coaches',
        opt_check: 'Check — inschrijving vooraf controleren',
        opt_geen: 'Geen',
        opt_unaware: 'Wist niet dat InlineComp bestond',
        q_algemeen: '3. Algemene ervaring met InlineComp',
        grp_public: '📺 Public — live-uitslag',
        q_public_snelheid: 'Snelheid',
        q_public_mobiel: 'Werken op mobiel',
        q_public_uitslagen: 'Helderheid uitslagen',
        q_public_programma: 'Helderheid programma / startlijsten',
        grp_coach: '🧑‍🏫 Coach — overzicht voor coaches',
        q_coach_snelheid: 'Snelheid',
        q_coach_mobiel: 'Werken op mobiel',
        q_coach_uitslagen: 'Helderheid uitslagen',
        q_coach_volgen: 'Hoe makkelijk kon je <b>meerdere rijders tegelijk</b> in de gaten houden (programma + startlijsten + uitslagen op één scherm)?',
        grp_check: '✅ Check — inschrijving controleren',
        q_check_snelheid: 'Snelheid',
        q_check_mobiel: 'Werken op mobiel',
        q_check_duidelijk: 'Duidelijkheid van je geregistreerde gegevens',
        q_nps: 'Zou je InlineComp aanraden aan een andere skater?',
        q_kent: '8. Welke andere tools ken je?',
        q_kent_hint: 'Meerdere antwoorden mogelijk',
        opt_combinatie: 'Combinatie van bovenstaande',
        opt_anders: 'Iets anders',
        opt_geen_tool: 'Nooit iets anders gebruikt',
        ph_anders_naam: 'Welke tool? (optioneel)',
        q_vergelijking: '9. Hoe vond je InlineComp t.o.v. de andere tool(s)?',
        q_ontwikkeling: '10. Hoe vind je dat InlineComp zich ontwikkelt sinds vorige keer?',
        opt_eerste_keer: 'Ik gebruik InlineComp voor het eerst',
        q_miste: '11. Wat miste je / wat had je beter gewild?',
        ph_miste: 'Optioneel — laat leeg als je niets specifieks hebt',
        q_tip: '12. Tips / ideeën voor verbetering?',
        ph_tip: 'Optioneel',
        q_vraag: '13. Heb je een vraag voor mij?',
        q_vraag_hint: 'Volledig optioneel. Als je iets invult: laat ook je e-mailadres achter zodat ik kan reageren. Je e-mail wordt los van je antwoorden opgeslagen — er is geen koppeling.',
        ph_vraag: 'Je vraag (optioneel)',
        ph_email: 'Je e-mail (alleen als je een vraag hebt)',
        btn_submit: 'Verstuur',
        thanks_h: 'Bedankt voor je feedback!',
        thanks_p: 'Je antwoorden helpen InlineComp beter te maken.',
        scale_low: '1 = heel slecht',
        scale_high: '5 = uitmuntend',
        scale_low_slow: '1 = traag',
        scale_high_fast: '5 = snel',
        scale_low_unclear: '1 = onduidelijk',
        scale_high_clear: '5 = glashelder',
        scale_low_no: '1 = zeker niet',
        scale_high_yes: '5 = absoluut',
        scale_low_hard: '1 = lastig',
        scale_high_easy: '5 = heel makkelijk',
        scale_low_worse: '1 = veel slechter',
        scale_high_better: '5 = veel beter',
        err_submit: 'Versturen mislukt. Probeer het opnieuw.',
        err_vraag_email: 'Heb je een vraag ingevuld? Vul dan ook een geldig e-mailadres in.',
    },
    en: {
        title: 'InlineComp — your feedback',
        h1: 'InlineComp — your feedback',
        sub: 'Short survey — takes about 2 minutes',
        intro1: 'Thanks for using InlineComp! We\'d like to improve it — would you let us know what you thought? Anonymous, no account needed.',
        intro_anon: '100% anonymous',
        q_comps: '1. Which race(s) did you use InlineComp at?',
        q_comps_hint: 'Multiple answers possible — leave blank if you don\'t remember',
        q_used: '2. Which parts did you use?',
        q_used_hint: 'Multiple answers possible',
        opt_public: 'Public — live results for the audience',
        opt_coach: 'Coach — overview for coaches',
        opt_check: 'Check — verify your registration beforehand',
        opt_geen: 'None',
        opt_unaware: 'Didn\'t know InlineComp existed',
        q_algemeen: '3. Overall experience with InlineComp',
        grp_public: '📺 Public — live results',
        q_public_snelheid: 'Speed',
        q_public_mobiel: 'Mobile experience',
        q_public_uitslagen: 'Clarity of results',
        q_public_programma: 'Clarity of programme / start lists',
        grp_coach: '🧑‍🏫 Coach — overview for coaches',
        q_coach_snelheid: 'Speed',
        q_coach_mobiel: 'Mobile experience',
        q_coach_uitslagen: 'Clarity of results',
        q_coach_volgen: 'How easy was it to follow <b>multiple skaters at once</b> (programme + start lists + results on one screen)?',
        grp_check: '✅ Check — verify registration',
        q_check_snelheid: 'Speed',
        q_check_mobiel: 'Mobile experience',
        q_check_duidelijk: 'Clarity of your registered details',
        q_nps: 'Would you recommend InlineComp to another skater?',
        q_kent: '8. Which other tools do you know?',
        q_kent_hint: 'Multiple answers possible',
        opt_combinatie: 'Combination of the above',
        opt_anders: 'Something else',
        opt_geen_tool: 'Never used anything else',
        ph_anders_naam: 'Which tool? (optional)',
        q_vergelijking: '9. How was InlineComp compared to the other tool(s)?',
        q_ontwikkeling: '10. How is InlineComp developing since last time?',
        opt_eerste_keer: 'I\'m using InlineComp for the first time',
        q_miste: '11. What was missing / what would you have wanted differently?',
        ph_miste: 'Optional — leave blank if nothing specific',
        q_tip: '12. Tips / ideas for improvement?',
        ph_tip: 'Optional',
        q_vraag: '13. Do you have a question for me?',
        q_vraag_hint: 'Entirely optional. If you fill something in: also leave your email so I can reply. Your email is stored separately from your answers — there is no link.',
        ph_vraag: 'Your question (optional)',
        ph_email: 'Your email (only if you have a question)',
        btn_submit: 'Submit',
        thanks_h: 'Thanks for your feedback!',
        thanks_p: 'Your answers help make InlineComp better.',
        scale_low: '1 = very poor',
        scale_high: '5 = excellent',
        scale_low_slow: '1 = slow',
        scale_high_fast: '5 = fast',
        scale_low_unclear: '1 = unclear',
        scale_high_clear: '5 = crystal clear',
        scale_low_no: '1 = definitely not',
        scale_high_yes: '5 = absolutely',
        scale_low_hard: '1 = hard',
        scale_high_easy: '5 = very easy',
        scale_low_worse: '1 = much worse',
        scale_high_better: '5 = much better',
        err_submit: 'Submit failed. Please try again.',
        err_vraag_email: 'You entered a question? Please also enter a valid email address.',
    },
};

let curLang = 'nl';
function t(k) { return (T[curLang] && T[curLang][k]) ?? T.nl[k] ?? k; }
function applyLang() {
    document.documentElement.lang = curLang;
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const k = el.getAttribute('data-i18n');
        el.innerHTML = t(k);
    });
    document.querySelectorAll('[data-i18n-ph]').forEach(el => {
        const k = el.getAttribute('data-i18n-ph');
        el.setAttribute('placeholder', t(k));
    });
    document.querySelectorAll('.lang-toggle button').forEach(b => {
        b.classList.toggle('actief', b.getAttribute('data-lang') === curLang);
    });
}
document.querySelectorAll('.lang-toggle button').forEach(b => {
    b.addEventListener('click', () => {
        curLang = b.getAttribute('data-lang');
        applyLang();
    });
});
// Eerste auto-detect: browser-taal
(function autoLang() {
    const langs = navigator.languages || [navigator.language || 'nl'];
    for (const l of langs) {
        const code = (l || '').toLowerCase().slice(0, 2);
        if (code === 'nl') { curLang = 'nl'; return; }
        if (code === 'en') { curLang = 'en'; return; }
    }
    curLang = 'nl';
})();
applyLang();

// ── Build 1-5 scale rows ───────────────────────────────────────────────────
// Mobile-first: bredere touch-targets dan een 1-10 grid, vertrouwde Likert-
// schaal. Granulariteit-verlies is in praktijk verwaarloosbaar voor een
// kwalitatieve feedback-survey.
document.querySelectorAll('.scale-row').forEach(row => {
    const name = row.getAttribute('data-name');
    for (let i = 1; i <= 5; i++) {
        const lbl = document.createElement('label');
        lbl.innerHTML = `<input type="radio" name="${name}" value="${i}"><span class="dot"></span><span class="num">${i}</span>`;
        lbl.querySelector('input').addEventListener('change', () => {
            row.querySelectorAll('label').forEach(l => l.classList.remove('sel'));
            lbl.classList.add('sel');
        });
        row.appendChild(lbl);
    }
});

// ── Conditioneel tonen (data-show op een checkbox toont/verbergt een section) ──
function refreshConditionals() {
    document.querySelectorAll('input[type=checkbox][data-show]').forEach(cb => {
        const target = document.querySelector(cb.getAttribute('data-show'));
        if (!target) return;
        target.classList.toggle('hidden', !cb.checked);
    });
    // Omgekeerde variant: data-hide → verberg wanneer aangevinkt.
    // Gebruikt voor "eerste keer"-checkbox die de ontwikkelings-schaal verbergt.
    document.querySelectorAll('input[type=checkbox][data-hide]').forEach(cb => {
        const target = document.querySelector(cb.getAttribute('data-hide'));
        if (!target) return;
        target.classList.toggle('hidden', cb.checked);
        // Wis de score ook zodat er geen restwaarde meegaat bij submit
        if (cb.checked) {
            target.querySelectorAll('input[type=radio]').forEach(r => { r.checked = false; });
            target.querySelectorAll('.scale-row label.sel').forEach(l => l.classList.remove('sel'));
        }
    });
    // Vergelijking-vraag: alleen als minstens 1 tool-checkbox aan staat (geen_tool uitgezonderd)
    const anyTool = document.querySelectorAll('input[type=checkbox][data-tools]:not([name=kent_geen]):checked').length > 0;
    document.getElementById('sec-vergelijking').classList.toggle('hidden', !anyTool);
}
document.querySelectorAll('.chk-lbl input[type=checkbox]').forEach(cb => {
    cb.addEventListener('change', () => {
        cb.closest('.chk-lbl').classList.toggle('checked', cb.checked);
        refreshConditionals();
    });
});

// ── Submit ────────────────────────────────────────────────────────────────
const form = document.getElementById('survey-form');
const btn  = document.getElementById('btn-submit');
const err  = document.getElementById('submit-err');
form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.style.display = 'none';

    // Front-end: als vraag-tekst gevuld → email vereist
    const fd = new FormData(form);
    const vt = (fd.get('vraag_tekst') || '').toString().trim();
    const ve = (fd.get('vraag_email') || '').toString().trim();
    if (vt && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ve)) {
        err.textContent = t('err_vraag_email');
        err.style.display = 'block';
        return;
    }
    fd.append('action', 'submit');
    fd.append('lang', curLang);

    btn.disabled = true;
    try {
        const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error('submit failed');
        // Toon bedank-scherm
        form.classList.add('hidden');
        document.getElementById('thanks').classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        err.textContent = t('err_submit');
        err.style.display = 'block';
        btn.disabled = false;
    }
});
</script>
</body>
</html>
