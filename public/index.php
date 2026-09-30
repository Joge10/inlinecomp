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

// ── Dispatcher: elke JSON-endpoint zit in eigen api/public_<naam>.php ─────
// Fase 4 refactor (2026-09-30): 11 action-blokken uit deze file naar
// aparte api-files. Session/$pdo/$action al gezet in preamble hierboven;
// de include ziet dezelfde scope. Onbekende action valt door naar de
// HTML-shell (bestaand gedrag - onbekende action = gewone pagina-render).
$actionRoutes = [
    'competitions'     => 'public_competitions.php',
    'programma'        => 'public_programma.php',
    'rit_detail'       => 'public_rit_detail.php',
    'search_person'    => 'public_search_person.php',
    'lookup'           => 'public_lookup.php',
    'categorieen'      => 'public_categorieen.php',
    'uitslagen'        => 'public_uitslagen.php',
    'rondes_cats'      => 'public_rondes_cats.php',
    'ronde_uitslagen'  => 'public_ronde_uitslagen.php',
    'series_voor_comp' => 'public_series_voor_comp.php',
    'serie_klassement' => 'public_serie_klassement.php',
];
if (isset($actionRoutes[$action])) {
    define('INLINECOMP_PUBLIC_BOOTED', true);
    require __DIR__ . '/../api/' . $actionRoutes[$action];
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
<?php
// Publieke JS-modules — klassieke script-tags in dep-volgorde.
// i18n eerst (APP_VERSIE/CHANGELOG/T), dan utils (globals+safeFetch),
// dan features. `runtime` laatste (registreert service-worker +
// install-prompt bij load). Klassieke tags -> alles deelt global
// scope, HTML onclick="..."-handlers blijven werken.
foreach (['i18n','utils','programma','rijder','uitslag','modals','meldingen','runtime'] as $f) {
    echo '<script src="js/public-' . $f . '.js?v='
       . @filemtime(__DIR__ . "/js/public-$f.js") . '"></script>' . "\n";
}
?>
</body>
</html>
