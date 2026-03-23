<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InlineComp</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header>
    <h1>InlineComp</h1>
    <span class="badge">KNSB Inline</span>
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
                <span class="nav-label">Klassementen</span>
            </li>
        </ul>

        <ul class="nav-menu nav-bottom">
            <li class="nav-item" data-page="instellingen">
                <span class="nav-icon">&#9881;</span>
                <span class="nav-label">Instellingen</span>
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
                        <button id="filter-reset">Wis</button>
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
            <div class="voorb-layout">
                <div class="voorb-right" style="flex:1; min-width:0;">
                    <div id="sl-page-header"></div>
                    <div class="tab-bar" id="sl-cat-tabs"></div>
                    <div id="sl-cat-content">
                        <div class="status-msg info">Selecteer en importeer eerst een wedstrijd via <strong>Importeer</strong>.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagina: Live verwerking -->
        <div id="page-live" class="page">
            <div class="section-title">Live verwerking</div>
            <p style="color:#666;">Nog niet geïmplementeerd — MyLaps RMonitor koppeling.</p>
        </div>

        <!-- Pagina: Klassementen -->
        <div id="page-klassementen" class="page">
            <div class="section-title">Klassementen</div>
            <p style="color:#666;">Nog geen inhoud.</p>
        </div>

        <!-- Pagina: Instellingen -->
        <div id="page-instellingen" class="page">
            <div class="inst-layout">
                <!-- Links: organisatielijst -->
                <div class="inst-left">
                    <div class="section-title">Organisaties</div>
                    <div id="org-list"><div class="status-msg loading"><span class="spinner"></span>Laden…</div></div>
                    <button class="btn-nieuw-org" id="btn-nieuw-org">+ Nieuwe organisatie</button>
                </div>
                <!-- Rechts: bewerkformulier -->
                <div class="inst-right">
                    <div id="org-form-panel" style="display:none">
                        <h2 id="org-form-titel">Organisatie</h2>

                        <div class="inst-veld">
                            <label for="org-naam">Naam</label>
                            <input type="text" id="org-naam" placeholder="Naam organisatie">
                        </div>
                        <div class="inst-veld">
                            <label for="org-website">Website</label>
                            <input type="url" id="org-website" placeholder="https://…">
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

                        <div class="inst-subtitel">Sponsoren</div>
                        <div id="org-sponsors-list"></div>
                        <button class="btn-sponsor-add" id="btn-sponsor-add">+ Sponsor toevoegen</button>

                        <div class="inst-acties">
                            <button class="btn-primary" id="btn-org-opslaan">Opslaan</button>
                            <button class="btn-danger"  id="btn-org-verwijderen" style="display:none">Verwijderen</button>
                        </div>
                        <div id="org-status"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagina: Info -->
        <div id="page-info" class="page">
            <div class="section-title">Info</div>
            <p style="color:#666;">Nog geen inhoud.</p>
        </div>

    </main>
</div>

<script src="js/app.js"></script>
<script src="js/import.js"></script>
<script src="js/startlist.js"></script>
<script src="js/live.js"></script>
<script src="js/ranking.js"></script>
<script src="js/instellingen.js"></script>
</body>
</html>
