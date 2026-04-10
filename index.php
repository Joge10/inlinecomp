<?php
require_once __DIR__ . '/../config_inlinecomp.php';
require_once __DIR__ . '/auth/session.php';

$gebruiker = getSession($pdo);
if (!$gebruiker) {
    header('Location: login.php');
    exit;
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
    <div class="header-user">
        <button class="header-handleiding-btn" id="btn-handleiding" title="Handleiding openen" onclick="openHandleiding()">&#128366; Handleiding</button>
        <span class="header-user-naam"><?= htmlspecialchars($gebruiker['naam']) ?></span>
        <span class="header-user-rol"><?= htmlspecialchars($gebruiker['role']) ?></span>
        <button class="header-uitlog-btn" id="btn-uitloggen" title="Uitloggen">&#10148;</button>
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
            <li class="nav-item nav-item-gebruikers" data-page="gebruikers" style="display:none">
                <span class="nav-icon">&#128100;</span>
                <span class="nav-label">Gebruikers</span>
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
                            </div>
                            <div class="detail-knoppen">
                                <button id="btn-print-tekenlijst" class="btn-print-tekenlijst" disabled title="Tekenlijsten afdrukken">
                                    &#128438; Tekenlijsten
                                </button>
                                <button id="btn-print-deelnemers" class="btn-print-tekenlijst" disabled title="Definitieve deelnemerslijst afdrukken">
                                    &#128203; Deelnemerslijst
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
        </div>

        <!-- Pagina: Startlijsten -->
        <div id="page-startlijsten" class="page">
            <div class="pagina-inhoud">
                <div id="sl-page-header"></div>
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
                        <div class="org-tab-content" id="org-tab-wedstrijden" style="display:none">
                            <div id="org-wedstrijden-list">
                                <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                            </div>
                        </div>

                        <!-- Tab 3: Klassementen -->
                        <div class="org-tab-content" id="org-tab-klassementen" style="display:none">
                            <div id="ranking-container"></div>
                        </div>

                    </div><!-- /org-tabs-wrap -->
                </div><!-- /inst-right -->
            </div><!-- /inst-layout -->
        </div><!-- /page-instellingen -->

        <!-- Pagina: Info -->
        <!-- Pagina: Gebruikers -->
        <div id="page-gebruikers" class="page">
            <div class="pagina-inhoud">
                <div id="gb-container">
                    <div class="status-msg loading"><span class="spinner"></span>Laden…</div>
                </div>
            </div>
        </div>

        <div id="page-info" class="page">
            <div class="section-title">Info</div>
            <p style="color:#666;">Nog geen inhoud.</p>
        </div>

    </main>
</div>

<script>
// Huidige gebruiker (server-side ingespoten)
const currentUser = <?= json_encode([
    'id'       => (int)$gebruiker['id'],
    'username' => $gebruiker['username'],
    'naam'     => $gebruiker['naam'],
    'role'     => $gebruiker['role'],
]) ?>;

// Schrijfrechten per module
const SCHRIJF_ROLLEN = {
    importeer:    ['owner','admin','importer'],
    tijdschema:   ['owner','admin','planner'],
    startlijsten: ['owner','admin','planner'],
    live:         ['owner','admin','timer'],
    uitslag:      ['owner','admin','timer'],
    instellingen: ['owner','admin'],
    gebruikers:   ['owner','admin'],
};
function magSchrijven(module) {
    return (SCHRIJF_ROLLEN[module] ?? ['owner']).includes(currentUser.role);
}
</script>
<script src="js/app.js"></script>
<script src="js/import.js"></script>
<script src="js/startlist.js"></script>
<script src="js/tijdschema.js"></script>
<script src="js/live.js"></script>
<script src="js/uitslag.js"></script>
<script src="js/ranking.js"></script>
<script src="js/instellingen.js"></script>
<script src="js/gebruikers.js"></script>
<script src="js/handleiding.js"></script>
</body>
</html>
