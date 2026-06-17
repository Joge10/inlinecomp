<?php
require_once __DIR__ . '/../config_inlinecomp.php';
require_once __DIR__ . '/auth/session.php';

$gebruiker = getSession($pdo);
if (!$gebruiker) {
    header('Location: login.php');
    exit;
}

// Multi-tenant: scope-info ophalen voor (a) injectie in currentUser-object
// (zie verderop) en (b) badge in header. Lege array = unscoped (owner of
// geen koppeling) → badge wordt niet getoond.
$eigenScope = gebruikerOrgScope($pdo, $gebruiker);
$scopeNamen = [];
if (is_array($eigenScope) && !empty($eigenScope)) {
    $ph = implode(',', array_fill(0, count($eigenScope), '?'));
    $st = $pdo->prepare("SELECT naam FROM organisaties WHERE id IN ($ph) ORDER BY naam");
    $st->execute($eigenScope);
    $scopeNamen = $st->fetchAll(PDO::FETCH_COLUMN);
}
?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InlineComp</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js" defer></script>
</head>
<body>

<header>
    <h1>InlineComp</h1>
    <span class="badge">KNSB Inline</span>
    <span class="header-wedstrijd" id="header-wedstrijd" title="Huidige wedstrijd (selectie in Importeer)"></span>
    <div class="header-user">
        <button class="header-printcenter-btn" id="btn-printcenter" title="Print-Center openen" disabled>&#128424; Print-Center</button>
        <button class="header-handleiding-btn" id="btn-handleiding" title="Handleiding openen" onclick="window.open('docs/handleiding.html', '_blank', 'noopener')">&#128366; Handleiding</button>
        <span class="header-user-naam"><?= htmlspecialchars($gebruiker['naam']) ?></span>
        <span class="header-user-rol"><?= htmlspecialchars($gebruiker['role']) ?></span>
        <?php if (!empty($scopeNamen)): ?>
            <?php
                // Korte label: bij 1 org de naam, bij meerdere "N org's".
                // Tooltip toont altijd de volledige lijst.
                $korteLabel = count($scopeNamen) === 1
                    ? $scopeNamen[0]
                    : (count($scopeNamen) . " org's");
                $tooltip = "Scope: " . implode(' · ', $scopeNamen);
            ?>
            <span class="header-user-scope" title="<?= htmlspecialchars($tooltip) ?>">
                🔒 <?= htmlspecialchars($korteLabel) ?>
            </span>
        <?php endif; ?>
        <div class="header-menu-wrap">
            <button class="header-menu-btn" id="btn-header-menu" type="button"
                    title="Account-menu" aria-haspopup="true" aria-expanded="false">
                &#9776;
            </button>
            <ul class="header-menu-dropdown" id="header-menu-dropdown" role="menu">
                <li role="none">
                    <button type="button" role="menuitem" id="menu-mijn-account">
                        &#9998; Mijn account
                    </button>
                </li>
                <li role="none">
                    <button type="button" role="menuitem" id="menu-uitloggen">
                        &#10138; Uitloggen
                    </button>
                </li>
            </ul>
        </div>
    </div>
</header>

<div class="layout">

    <!-- ── Sidebar ── -->
    <nav id="sidebar">
        <button id="sidebar-toggle" title="Menu in-/uitklappen">&#10094;</button>
        <ul class="nav-menu">
            <li class="nav-item active" data-page="importeer">
                <span class="nav-icon">&#8659;</span>
                <span class="nav-label">Importeer</span>
            </li>
            <li class="nav-item" data-page="tijdschema">
                <span class="nav-icon">&#128197;</span>
                <span class="nav-label">Tijdschema</span>
            </li>
            <li class="nav-item" data-page="startlijsten">
                <span class="nav-icon">&#128203;</span>
                <span class="nav-label">Startlijsten</span>
            </li>
            <li class="nav-item" data-page="live">
                <span class="nav-icon">&#9654;</span>
                <span class="nav-label">Live verwerking</span>
            </li>
            <li class="nav-item" data-page="klassementen">
                <span class="nav-icon">&#127942;</span>
                <span class="nav-label">Uitslag</span>
            </li>
        </ul>

        <ul class="nav-menu nav-bottom">
            <li class="nav-item" data-page="instellingen">
                <span class="nav-icon">&#9881;</span>
                <span class="nav-label">Beheer</span>
            </li>
            <li class="nav-item nav-item-systeem" data-page="systeem" style="display:none">
                <span class="nav-icon">&#128736;</span>
                <span class="nav-label">Systeem</span>
            </li>
            <li class="nav-item" data-page="info">
                <span class="nav-icon">&#8505;</span>
                <span class="nav-label">Info</span>
            </li>
        </ul>
    </nav>

    <!-- ── Content ── -->
    <main id="main-content">

        <!-- Pagina: Importeer -->
        <div id="page-importeer" class="page active">
            <div class="voorb-layout">

                <!-- Linkerkolom: wedstrijdenlijst -->
                <div class="voorb-left">
                    <div class="section-title">Wedstrijden</div>

                    <!-- Handmatige wedstrijd-aanmaak: voor organisaties die NIET
                         via de KNSB-feed werken (intern toernooi, internationaal
                         event, etc.). Knop alleen zichtbaar voor admin/owner. -->
                    <div class="wh-knop-wrap" id="wh-knop-wrap" style="display:none;">
                        <button id="wh-btn-open" class="wh-btn-open"
                                title="Maak handmatig een wedstrijd aan (zonder KNSB-import)">
                            + Wedstrijd handmatig toevoegen
                        </button>
                    </div>

                    <div class="date-filter">
                        <label>Van <input type="date" id="filter-van"></label>
                        <label>Tot <input type="date" id="filter-tot"></label>
                        <label>Locatie
                            <select id="filter-locatie">
                                <option value="">— alle —</option>
                            </select>
                        </label>
                        <label>Organisatie
                            <select id="filter-organisatie">
                                <option value="">— alle —</option>
                            </select>
                        </label>
                        <div class="date-filter-knoppen">
                            <button id="filter-reset" title="Filter wissen">Wis</button>
                            <button id="btn-ververs-wedstrijden" title="Lijst verversen"><span id="ververs-icon">&#8635;</span></button>
                        </div>
                    </div>

                    <div id="comp-list">
                        <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                    </div>
                </div>

                <!-- Rechterkolom: deelnemers importeren -->
                <div class="voorb-right">
                    <div id="detail-panel" style="display:none;">
                        <div class="detail-header">
                            <div>
                                <h2 id="detail-title"></h2>
                                <div class="meta-rij">
                                    <div class="meta" id="detail-meta"></div>
                                    <div id="knsb-sync-info"></div>
                                </div>
                                <div class="meta-rij" id="detail-baan-rij" style="display:none;">
                                    <div class="meta" id="detail-baan"></div>
                                </div>
                            </div>
                            <div class="detail-knoppen">
                                <button id="btn-export" class="btn-export" title="Deelnemers exporteren als KNSB-CSV">
                                    &#128228; Exporteer
                                </button>
                                <!-- CSV-import: alleen zichtbaar bij handmatige wedstrijden.
                                     Opent de 4-staps wizard (upload → mapping → DC →
                                     match) voor het invoeren van deelnemers vanuit een
                                     CSV-bestand (club-wedstrijden, geen KNSB-feed). -->
                                <button id="btn-csv-import" class="btn-export" title="Deelnemers importeren uit CSV-bestand" style="display:none;">
                                    &#128229; CSV Importeren
                                </button>
                                <button id="btn-import" class="btn-import" title="Wedstrijd importeren in database">
                                    &#8659; Importeer
                                </button>
                            </div>
                        </div>
                        <div id="import-result"></div>
                        <div id="beheer-panel"></div>
                        <div class="tab-bar" id="imp-cat-tabs"></div>
                        <div id="imp-cat-content"></div>
                    </div>
                </div>

            </div>

            <!-- Modal: handmatige wedstrijd-aanmaak. Volgt het standaard modal-
                 pattern (.modal-overlay > .modal-dialog > .modal-header / .modal-body
                 / .modal-knoppen). Wordt geopend door js/wedstrijd_handmatig.js. -->
            <div class="modal-overlay" id="wh-modal-overlay" style="display:none;">
                <div class="modal-dialog wh-modal">
                    <div class="modal-header">
                        <span>Nieuwe wedstrijd handmatig toevoegen</span>
                    </div>
                    <div class="modal-body">
                        <p class="wh-uitleg">
                            Voor wedstrijden die <b>niet</b> via de KNSB-feed (Vantage)
                            binnenkomen. Vul de basisgegevens in. Afstanden per categorie
                            voeg je daarna toe via <b>Beheer → Afstanden</b>.
                        </p>

                        <label class="wh-label" for="wh-org">Organisatie *</label>
                        <select id="wh-org" class="modal-input">
                            <option value="">— kies organisatie —</option>
                        </select>

                        <label class="wh-label" for="wh-naam">Wedstrijdnaam *</label>
                        <input type="text" id="wh-naam" class="modal-input"
                               placeholder="bv. Holland Cup Heerde 2026" maxlength="200">

                        <div class="wh-2col">
                            <div>
                                <label class="wh-label" for="wh-start">Startdatum *</label>
                                <input type="date" id="wh-start" class="modal-input">
                            </div>
                            <div>
                                <label class="wh-label" for="wh-eind">Einddatum <small>(optioneel)</small></label>
                                <input type="date" id="wh-eind" class="modal-input">
                            </div>
                        </div>

                        <!-- Locatie + Baan/venue zijn weggelaten: die stel je
                             daarna in via Beheer, net als bij KNSB-imports. -->

                        <label class="wh-label">Categorieën (DC's) *</label>
                        <p class="wh-uitleg-klein">
                            Per categorie: vrij in te vullen naam (bv. "Senioren Mannen")
                            + welke KNSB-categorie-codes erin uitkomen
                            (bv. <code>HSA, HSJ</code> = mannen senioren + senioren-jongeren).
                        </p>
                        <div class="wh-dc-lijst" id="wh-dc-lijst"></div>
                        <button class="wh-btn-add-dc" id="wh-btn-add-dc">
                            + Categorie toevoegen
                        </button>

                        <div class="wh-fout" id="wh-fout" style="display:none;"></div>
                    </div>
                    <div class="modal-knoppen">
                        <button class="modal-btn modal-annuleer" id="wh-btn-annuleer">Annuleer</button>
                        <button class="modal-btn modal-doorgaan" id="wh-btn-create">Wedstrijd aanmaken</button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Pagina: Startlijsten -->
        <div id="page-startlijsten" class="page">
            <div class="pagina-inhoud">
                <div id="sl-page-header"></div>
                <div id="sl-afstand-filter" class="afstand-filter" style="display:none"></div>
                <nav class="org-tabs-nav sl-cat-tabs-nav" id="sl-cat-tabs"></nav>
                <nav class="org-tabs-nav sl-dist-tabs-nav" id="sl-dist-tabs" style="display:none;"></nav>
                <div id="sl-cat-content">
                    <div class="status-msg info">Selecteer en importeer eerst een wedstrijd via <strong>Importeer</strong>.</div>
                </div>
            </div>
        </div>

        <!-- Pagina: Tijdschema -->
        <div id="page-tijdschema" class="page">
            <div class="pagina-inhoud" id="ts-container">
                <div class="status-msg info">Selecteer eerst een wedstrijd via <strong>Importeer</strong>.</div>
            </div>
        </div>

        <!-- Pagina: Live verwerking -->
        <div id="page-live" class="page">
            <div class="pagina-inhoud" id="live-container">
                <div class="ts-comp-naam" id="live-comp-naam"></div>
                <div class="ts-comp-meta" id="live-comp-meta"></div>
                <div id="live-inhoud"></div>
            </div>
        </div>

        <!-- Pagina: Uitslag -->
        <div id="page-klassementen" class="page">
            <div class="pagina-inhoud">
                <div id="u-page-header"></div>
                <div id="u-afstand-filter" class="afstand-filter" style="display:none"></div>
                <nav class="org-tabs-nav sl-cat-tabs-nav" id="u-cat-tabs"></nav>
                <nav class="org-tabs-nav sl-dist-tabs-nav u-dist-tabs-nav" id="u-dist-tabs" style="display:none;"></nav>
                <div id="u-cat-content">
                    <div class="status-msg info">Selecteer en importeer eerst een wedstrijd via <strong>Importeer</strong>.</div>
                </div>
            </div>
        </div>

        <!-- Pagina: Beheer -->
        <div id="page-instellingen" class="page">
            <div class="inst-layout">
                <!-- Links: organisatielijst -->
                <div class="inst-left">
                    <div class="section-title">Organisaties</div>
                    <div id="org-list"><div class="status-msg loading"><span class="spinner"></span>Laden…</div></div>
                    <button class="btn-nieuw-org" id="btn-nieuw-org">+ Nieuwe organisatie</button>
                </div>
                <!-- Rechts: tabbladen -->
                <div class="inst-right">
                    <!-- Geen org geselecteerd -->
                    <div id="org-geen-selectie" class="org-geen-selectie">
                        Selecteer een organisatie of maak een nieuwe aan.
                    </div>

                    <!-- Tabbladen (zichtbaar zodra org geselecteerd) -->
                    <div id="org-tabs-wrap" style="display:none">
                        <div class="org-tabs-header">
                            <h2 id="org-form-titel">Organisatie</h2>
                            <nav class="org-tabs-nav" id="org-tabs-nav">
                                <button class="org-tab-btn active" data-tab="gegevens">Gegevens</button>
                                <button class="org-tab-btn" data-tab="banen">Banen</button>
                                <button class="org-tab-btn" data-tab="transponders">Transponders</button>
                                <button class="org-tab-btn" data-tab="wedstrijden">Wedstrijden</button>
                                <button class="org-tab-btn" data-tab="klassementen">Klassementen</button>
                            </nav>
                        </div>

                        <!-- Tab 1: Gegevens -->
                        <div class="org-tab-content" id="org-tab-gegevens">
                            <div class="inst-veld">
                                <label for="org-naam">Naam</label>
                                <input type="text" id="org-naam" placeholder="Naam organisatie">
                            </div>
                            <div class="inst-veld">
                                <label for="org-email">E-mail</label>
                                <input type="email" id="org-email" placeholder="info@organisatie.nl">
                            </div>
                            <div class="inst-veld">
                                <label for="org-sportity">Sportity-kanaal <span class="label-hint">(voor disclaimer op promotie-poster)</span></label>
                                <input type="text" id="org-sportity" placeholder="bv. ISKREGIO" maxlength="50">
                            </div>
                            <div class="inst-veld">
                                <label>Logo</label>
                                <div class="logo-preview-wrap">
                                    <img id="org-logo-preview" src="" alt="" style="display:none">
                                    <span id="org-logo-geen" class="logo-geen">Geen logo</span>
                                </div>
                                <label class="btn-upload" for="org-logo-file">&#128247; Logo uploaden</label>
                                <input type="file" id="org-logo-file" accept="image/*" style="display:none">
                            </div>

                            <div class="inst-subtitel">Naam-varianten <span class="inst-subtitel-hint">(aliassen)</span></div>
                            <div id="org-aliassen-list" class="org-aliassen-list"></div>
                            <div class="alias-toevoeg-rij" id="alias-toevoeg-rij" style="display:none">
                                <input type="text" id="alias-nieuw-naam" class="inp alias-inp"
                                       placeholder="Alternatieve naam…">
                                <button class="btn-alias-ok"  id="btn-alias-ok">&#10003; Toevoegen</button>
                                <button class="btn-alias-ann" id="btn-alias-ann">&#10005;</button>
                            </div>
                            <button class="btn-alias-add" id="btn-alias-add" style="display:none">
                                + Alias toevoegen
                            </button>

                            <div class="inst-subtitel">Sponsoren</div>
                            <div id="org-sponsors-list"></div>
                            <button class="btn-sponsor-add" id="btn-sponsor-add">+ Sponsor toevoegen</button>

                            <div class="inst-acties">
                                <button class="btn-primary" id="btn-org-opslaan">Opslaan</button>
                                <button class="btn-samenvoeg" id="btn-samenvoeg" style="display:none">
                                    &#8596; Samenvoegen…
                                </button>
                                <button class="btn-secondary" id="btn-org-poster" title="Download een A4-promotie-poster voor deze organisatie">📄 Promotie-poster</button>
                                <button class="btn-danger" id="btn-org-verwijderen" style="display:none">Verwijderen</button>
                            </div>
                            <div id="org-status"></div>

                            <!-- Samenvoeg-modal -->
                            <div id="samenvoeg-panel" class="samenvoeg-panel" style="display:none">
                                <div class="samenvoeg-titel">Samenvoegen met…</div>
                                <p class="samenvoeg-uitleg">
                                    De geselecteerde organisatie <strong>verdwijnt</strong> en haar naam
                                    wordt als alias toegevoegd aan <em id="samenvoeg-naar-naam"></em>.
                                    Wedstrijden en sponsors worden overgenomen.
                                </p>
                                <select id="samenvoeg-kies" class="inp">
                                    <option value="">— kies organisatie —</option>
                                </select>
                                <div class="samenvoeg-acties">
                                    <button class="btn-primary"  id="btn-samenvoeg-ok">Samenvoegen</button>
                                    <button class="btn-secondary" id="btn-samenvoeg-ann">Annuleren</button>
                                </div>
                            </div>
                        </div><!-- /tab gegevens -->

                        <!-- Tab 2: Wedstrijden -->
                        <!-- Tab 2: Transponders -->
                        <div class="org-tab-content" id="org-tab-transponders" style="display:none">
                            <div class="org-tp-acties">
                                <button class="btn-tp-csv btn-secondary" id="btn-tp-csv" title="Importeer transponders uit CSV">📥 CSV import</button>
                                <input type="file" id="tp-csv-file" accept=".csv,.txt" style="display:none">
                                <button class="btn-primary" id="btn-tp-opslaan">💾 Opslaan</button>
                                <button class="btn-secondary" id="btn-tp-print" title="Druk de uitgeleverde transponders af">🖨 Print uitgeleverd</button>
                                <span id="tp-status"></span>
                            </div>
                            <div class="org-tp-tabel-wrap">
                                <table class="org-tp-tabel" id="org-tp-tabel">
                                    <thead><tr>
                                        <th class="tp-col-nr tp-sortable" data-sort="nr">Nr<span class="tp-sort-ico" aria-hidden="true">⇅</span></th>
                                        <th class="tp-col-code">Transponder</th>
                                        <th class="tp-col-eigendom">Eigendom</th>
                                        <th class="tp-col-snr tp-sortable" data-sort="snr">Snr<span class="tp-sort-ico" aria-hidden="true">⇅</span></th>
                                        <th class="tp-col-naam">Naam</th>
                                        <th class="tp-col-license">Licentie</th>
                                        <th class="tp-col-cat">Cat</th>
                                        <th class="tp-col-betaald">
    Betaald<button type="button" class="tp-filter-btn" id="tp-filter-btn" title="Filter" aria-haspopup="true" aria-expanded="false" aria-label="Filter">
        <svg class="tp-filter-ico" viewBox="0 0 16 16" width="13" height="13" aria-hidden="true">
            <path d="M1 2h14l-5.5 7v5l-3-1.5V9L1 2z" fill="currentColor"/>
        </svg>
    </button>
    <div class="tp-filter-menu" id="tp-filter-menu" role="menu" hidden>
        <button type="button" class="tp-filter-opt" data-val="alle"        role="menuitemradio">Alle</button>
        <button type="button" class="tp-filter-opt" data-val="uitgegeven"  role="menuitemradio">Uitgegeven</button>
        <button type="button" class="tp-filter-opt" data-val="betaald"     role="menuitemradio">Betaald</button>
        <button type="button" class="tp-filter-opt" data-val="niet_betaald" role="menuitemradio">Niet betaald</button>
    </div>
</th>
                                        <th class="tp-col-datum">Betaald op</th>
                                        <th class="tp-col-del"></th>
                                    </tr></thead>
                                    <tbody id="org-tp-body"></tbody>
                                </table>
                            </div>
                            <div class="org-tp-footer">
                                <button class="btn-secondary btn-tp-add" id="btn-tp-add">+ Transponder toevoegen</button>
                                <div class="tp-paginering" id="tp-paginering">
                                    <button class="tp-pag-btn" id="tp-pag-eerste" title="Eerste pagina">&laquo;</button>
                                    <button class="tp-pag-btn" id="tp-pag-vorige" title="Vorige pagina">&lsaquo;</button>
                                    <span class="tp-pag-info" id="tp-pag-info"></span>
                                    <button class="tp-pag-btn" id="tp-pag-volgende" title="Volgende pagina">&rsaquo;</button>
                                    <button class="tp-pag-btn" id="tp-pag-laatste" title="Laatste pagina">&raquo;</button>
                                </div>
                            </div>
                        </div>

                        <!-- Tab 3: Wedstrijden -->
                        <div class="org-tab-content" id="org-tab-wedstrijden" style="display:none">
                            <div id="org-wedstrijden-list">
                                <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                            </div>
                        </div>

                        <!-- Tab 3: Klassementen -->
                        <div class="org-tab-content" id="org-tab-klassementen" style="display:none">
                            <div id="ranking-container"></div>
                        </div>

                        <!-- Tab: Banen -->
                        <div class="org-tab-content" id="org-tab-banen" style="display:none">
                            <div class="label-hint" style="margin-bottom:.6rem">
                                Banen waar deze organisatie wedstrijden houdt — gastheer-vereniging
                                + logo voor de print-headers. Bij import wordt automatisch een
                                rij aangemaakt op basis van de KNSB-venue als die nog niet bestaat.
                            </div>
                            <div id="banen-container">
                                <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                            </div>
                            <button class="btn-nieuw-org" id="btn-nieuwe-baan">+ Nieuwe baan</button>
                        </div>

                    </div><!-- /org-tabs-wrap -->
                </div><!-- /inst-right -->
            </div><!-- /inst-layout -->
        </div><!-- /page-instellingen -->

        <!-- Pagina: Systeem (gegroepeerde admin-functies in tabs) -->
        <div id="page-systeem" class="page">
            <div class="pagina-inhoud">
                <div class="org-tabs-header">
                    <h2>Systeem</h2>
                    <nav class="org-tabs-nav" id="sys-tabs-nav">
                        <button class="org-tab-btn active" data-tab="gebruikers">Gebruikers</button>
                        <button class="org-tab-btn" data-tab="bezoekers">Bezoekers</button>
                        <button class="org-tab-btn" data-tab="logboek">Logboek</button>
                        <button class="org-tab-btn" data-tab="rijders">Rijders</button>
                        <button class="org-tab-btn" data-tab="uploads">Uploads</button>
                        <button class="org-tab-btn" data-tab="helpers">Helpers</button>
                    </nav>
                </div>

                <!-- Tab: Gebruikers -->
                <div class="org-tab-content" id="sys-tab-gebruikers">
                    <div id="gb-container">
                        <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                    </div>
                </div>

                <!-- Tab: Bezoekers (publiek + coach statistieken) -->
                <div class="org-tab-content" id="sys-tab-bezoekers" style="display:none">
                    <div id="gb-bezoekers-container">
                        <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                    </div>
                </div>

                <!-- Tab: Logboek (login-history) -->
                <div class="org-tab-content" id="sys-tab-logboek" style="display:none">
                    <div id="gb-logboek-container">
                        <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                    </div>
                </div>

                <!-- Tab: Rijders (AVG-beheer) -->
                <div class="org-tab-content" id="sys-tab-rijders" style="display:none">
                    <div class="section-title">Rijderbeheer — persoonsgegevens &amp; wedstrijdhistorie</div>
                    <div class="rij-avg-info">
                        <strong>AVG-beheer.</strong> Hier kun je van rijders hun gegevens inzien, hun wedstrijdhistorie bekijken en — op verzoek — hun persoonsgegevens anonimiseren.
                        Na anonimisatie blijft het <em>licentienummer</em> aan de wedstrijduitslagen gekoppeld, maar naam en overige gegevens zijn onomkeerbaar vervangen door <em>"Verwijderd"</em>/leeg.
                    </div>
                    <div class="rij-layout">
                        <div class="rij-left">
                            <div class="rij-zoek-rij">
                                <input type="text" id="rij-zoek-inp" class="inp" placeholder="Zoek op achternaam, startnummer of licentienummer…" autocomplete="off">
                                <button class="btn-secondary" id="rij-zoek-btn">Zoek</button>
                            </div>
                            <div class="rij-zoek-hint">Zoekt gelijktijdig op startnummer, achternaam en naam. Licentienummer wordt meegenomen vanaf 4 tekens (overal in de licentie — ook de laatste 4 cijfers werken).</div>
                            <div id="rij-zoek-resultaat"></div>
                        </div>
                        <div class="rij-right">
                            <div id="rij-detail">
                                <div class="status-msg" style="color:#666">Selecteer links een rijder voor de details.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Helpers (admin-onderhoudstools) -->
                <div class="org-tab-content" id="sys-tab-helpers" style="display:none">
                    <div class="section-title">Helpers — onderhoud &amp; opschonen</div>
                    <div class="hp-info">
                        Verzameling van administratieve tools om de database consistent te houden:
                        wees-uitslagen detecteren en opruimen, en (in de toekomst) andere
                        onderhoudstaken die niet bij één wedstrijd horen.
                    </div>
                    <div id="hp-container">
                        <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                    </div>
                </div>

                <!-- Tab: Uploads (Orbits/MyLaps CSV-archief beheer) -->
                <div class="org-tab-content" id="sys-tab-uploads" style="display:none">
                    <div class="section-title">Uploads — Orbits/MyLaps CSV-archief</div>
                    <div class="up-info">
                        Per wedstrijd worden CSV-exports vanuit Orbits/MyLaps in een submap onder
                        <code>uploader/</code> opgeslagen. Hier kun je oude mappen handmatig verwijderen
                        om ruimte vrij te maken. <strong>Dit is onomkeerbaar.</strong>
                    </div>
                    <div class="up-toolbar">
                        <label>Toon alleen ouder dan:
                            <select id="up-filter-age">
                                <option value="0">— alle mappen —</option>
                                <option value="30">30 dagen</option>
                                <option value="90">3 maanden</option>
                                <option value="180">6 maanden</option>
                                <option value="365">1 jaar</option>
                            </select>
                        </label>
                        <button class="btn-secondary" id="up-btn-refresh">&#8634; Vernieuw</button>
                    </div>
                    <div id="up-container">
                        <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                    </div>
                </div>

            </div>
        </div>

        <div id="page-info" class="page">
            <div class="pagina-inhoud">
                <div class="section-title">Info</div>

                <div class="info-blok">
                    <h3>InlineComp</h3>
                    <p>Webapplicatie voor het beheren van inline-skatewedstrijden:
                       deelnemers importeren, tijdschema opstellen, startlijsten loten,
                       resultaten verwerken, uitslagen en klassementen berekenen en afdrukken.</p>
                    <p>Ingelogd als <strong id="info-user">…</strong>
                       (rol: <span id="info-rol">…</span>).</p>
                </div>

                <div class="info-blok">
                    <h3>Documentatie</h3>
                    <ul class="info-lijst">
                        <li>📖 <a href="docs/handleiding.html" target="_blank" rel="noopener">
                            Handleiding</a> — stap-voor-stap uitleg per module en een
                            appendix voor techneuten.</li>
                        <li>🔒 <a href="privacyverklaring.php" target="_blank" rel="noopener">
                            Privacyverklaring (AVG)</a> — welke persoonsgegevens InlineComp
                            verwerkt, waarom, en hoe lang ze bewaard blijven.</li>
                    </ul>
                </div>

                <div class="info-blok">
                    <h3>Versie en techniek</h3>
                    <ul class="info-lijst">
                        <li>InlineComp draait in jouw browser; alle gegevens worden
                            opgeslagen op de server van de organisatie.</li>
                        <li>Browser: <code id="info-browser">…</code></li>
                        <li>Verbinding: <code id="info-online">…</code></li>
                    </ul>
                </div>

                <div class="info-blok">
                    <h3>Hulp en feedback</h3>
                    <p>Loop je tegen iets aan, of mis je een functie?
                       Open een issue of mail naar <a href="mailto:inlinecomp@devriesen.com">
                       inlinecomp@devriesen.com</a>. Dat helpt om InlineComp beter te maken
                       voor iedereen.</p>
                </div>

                <div class="info-blok info-blok-laatst">
                    <h3>Met dank aan</h3>
                    <p>Iedereen die feedback gaf tijdens de ontwikkeling — speakers,
                       juryleden, tijdwaarnemers, coaches, rijders (m/v), publiek
                       en de KNSB.</p>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
// Huidige gebruiker (server-side ingespoten)
<?php
    // $eigenScope is al eerder opgehaald voor de badge in de header. Hier
    // alleen de KNSB-naam-set bouwen voor de Import-tab-filter (lowercased
    // canonieke + alias-namen). Leeg = unscoped → frontend slaat filter over.
    $eigenScopeNamen = [];
    if (is_array($eigenScope) && !empty($eigenScope)) {
        $ph = implode(',', array_fill(0, count($eigenScope), '?'));
        $st = $pdo->prepare(
            "SELECT naam FROM organisaties WHERE id IN ($ph)
             UNION
             SELECT naam FROM organisatie_aliassen WHERE organisatie_id IN ($ph)"
        );
        $st->execute(array_merge($eigenScope, $eigenScope));
        $eigenScopeNamen = array_map(
            fn($n) => mb_strtolower(trim($n)),
            $st->fetchAll(PDO::FETCH_COLUMN)
        );
    }
?>
const currentUser = <?= json_encode([
    'id'                => (int)$gebruiker['id'],
    'username'          => $gebruiker['username'],
    'naam'              => $gebruiker['naam'],
    'role'              => $gebruiker['role'],
    // Array van org-UUIDs die deze user mag zien (leeg = unscoped = alle).
    'organisatie_ids'   => $eigenScope ?? [],
    // Lowercased canonieke + alias-namen voor naam-matching op de KNSB-feed
    // in de Import-tab. Leeg = unscoped (frontend gebruikt deze niet als
    // de array leeg is).
    'organisatie_namen' => $eigenScopeNamen,
]) ?>;

// Schrijfrechten per module
const SCHRIJF_ROLLEN = {
    importeer:    ['owner','admin','importer'],
    tijdschema:   ['owner','admin','planner'],
    startlijsten: ['owner','admin','planner'],
    live:         ['owner','admin','timer'],
    uitslag:      ['owner','admin','timer'],
    instellingen: ['owner','admin'],
    // 'beheer'       = zware beheer-acties (jury-wachtwoord, wedstrijd-
    //                  verwijderen, rijder-data wijzigen)
    // 'beheer_basic' = lichte beheer-acties (zichtbaarheid, mededelingen,
    //                  posters) — planner mag dit ook doen
    beheer:       ['owner','admin'],
    beheer_basic: ['owner','admin','planner'],
    gebruikers:   ['owner','admin'],
};
function magSchrijven(module) {
    return (SCHRIJF_ROLLEN[module] ?? ['owner']).includes(currentUser.role);
}
</script>
<script src="js/app.js"></script>
<script src="js/import.js"></script>
<script src="js/wedstrijd_handmatig.js"></script>
<script src="js/csv_import.js"></script>
<script src="js/startlist.js"></script>
<script src="js/tijdschema.js"></script>
<script src="js/live.js"></script>
<script src="js/uitslag.js"></script>
<script src="js/ranking.js"></script>
<script src="js/klassement_serie_ui.js"></script>
<script src="js/instellingen.js"></script>
<script src="js/banen.js"></script>
<script src="js/meldingen.js"></script>
<script src="js/gebruikers.js"></script>
<script src="js/rijders.js"></script>
<script src="js/uploads.js"></script>
<script src="js/helpers.js"></script>
<script src="js/print_module.js"></script>
</body>
</html>
