<?php
// InlineComp — master-changelog. Eén bron voor alle onderdelen.
// Elke entry is getagd met de onderdelen waarvoor 'ie relevant is:
//   'admin' | 'public' | 'coach' | 'check'   (jury toont geen changelog)
// Elk front-end filtert op z'n eigen onderdeel; admin toont ALLES.
// Nieuwste bovenaan. Bij een nieuwe release: entry(s) bovenaan toevoegen.
//
// Twee soorten entries:
//   • release  (soort ontbreekt / 'functie') — bepaalt het versienummer.
//   • patch    (soort='patch')                — security/bugfix ONDER de
//     lopende versie. Schuift het versienummer NIET vooruit (zie versie.php),
//     staat altijd getagd op ['patch'] (eigen tag + filterknop; alleen
//     zichtbaar in Beheer want public/coach filteren op hún eigen onderdeel)
//     en wordt in de changelog genest + gedempt getoond onder de versie.
//     Triviale copy/typo-fixes komen NERGENS in de changelog — los committen.
return [
    // ── Patches onder H997.31.07 (onderhoud & beveiliging, alleen Beheer) ──
    [
        'versie' => 'H997.31.07', 'datum' => '02-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Coach — kleine fixes</b> — het programma toont de rijders nu direct na het kiezen van een wedstrijd, en de deelnemers-badge op een ingeklapte afstand telt alle categorieën samen.',
            'en' => '🔧 <b>Coach — small fixes</b> — the programme now shows riders right after picking a race, and the participant badge on a collapsed distance counts all categories together.',
            'de' => '🔧 <b>Coach — kleine Fixes</b> — das Programm zeigt die Fahrer jetzt direkt nach der Wettkampfauswahl, und das Teilnehmer-Badge einer eingeklappten Distanz zählt alle Kategorien zusammen.',
            'fr' => '🔧 <b>Coach — petites corrections</b> — le programme affiche les patineurs dès le choix d\'une course, et le badge participants d\'une distance repliée additionne toutes les catégories.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '01-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔒 <b>Beveiliging</b> — extra escaping van speciale tekens in de weergave (naar aanleiding van code-scanning). Geen zichtbare wijziging bij normaal gebruik.',
            'en' => '🔒 <b>Security</b> — extra escaping of special characters in the UI (following code scanning). No visible change in normal use.',
            'de' => '🔒 <b>Sicherheit</b> — zusätzliches Escaping von Sonderzeichen in der Anzeige (nach Code-Scanning). Keine sichtbare Änderung im Normalbetrieb.',
            'fr' => '🔒 <b>Sécurité</b> — échappement supplémentaire des caractères spéciaux dans l\'affichage (suite à l\'analyse de code). Aucun changement visible en usage normal.',
        ],
    ],

    // ══ H997.31.07 — grote update: coach-accounts, beheer, public multi-kind ══
    // ── Beheer (admin) ──
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Coach-accounts</b> — nieuw tabblad <i>Coach</i> in Systeem: coaches vragen zelf een account aan, jij keurt goed en beheert ze.',
            'en' => '<b>Coach accounts</b> — new <i>Coach</i> tab in System: coaches request an account themselves, you approve and manage them.',
            'de' => '<b>Coach-Konten</b> — neuer Tab <i>Coach</i> im System: Coaches beantragen selbst ein Konto, du genehmigst und verwaltest sie.',
            'fr' => '<b>Comptes coach</b> — nouvel onglet <i>Coach</i> dans Système : les coachs demandent eux-mêmes un compte, tu approuves et les gères.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Logboek verbeterd</b> — filters opgeschoond (jury / staf / coach, geen overlap meer), coach-acties met duidelijke naam en gekleurde badges, plus locatie en browser/OS voor betere audit.',
            'en' => '<b>Logbook improved</b> — cleaner filters (jury / staff / coach, no more overlap), coach actions with clear names and coloured badges, plus location and browser/OS for better audit.',
            'de' => '<b>Logbuch verbessert</b> — aufgeräumte Filter (Jury / Staff / Coach, keine Überschneidung), Coach-Aktionen mit klaren Namen und farbigen Badges, plus Standort und Browser/OS für bessere Nachvollziehbarkeit.',
            'fr' => '<b>Journal amélioré</b> — filtres nettoyés (jury / staff / coach, plus de chevauchement), actions coach avec noms clairs et badges colorés, plus localisation et navigateur/OS pour un meilleur audit.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Info-pagina vernieuwd</b> — Info, Versie en Changelog nu in aparte tabbladen.',
            'en' => '<b>Info page revamped</b> — Info, Version and Changelog now in separate tabs.',
            'de' => '<b>Info-Seite überarbeitet</b> — Info, Version und Changelog jetzt in separaten Tabs.',
            'fr' => '<b>Page Info repensée</b> — Info, Version et Changelog désormais en onglets séparés.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Volledige changelog</b> — alle wijzigingen voor Beheer, Public, Coach en Check, met filter per onderdeel en inklapbare versies.',
            'en' => '<b>Full changelog</b> — all changes for Admin, Public, Coach and Check, with per-component filter and collapsible versions.',
            'de' => '<b>Vollständiges Changelog</b> — alle Änderungen für Verwaltung, Public, Coach und Check, mit Filter pro Bereich und einklappbaren Versionen.',
            'fr' => '<b>Changelog complet</b> — tous les changements pour Gestion, Public, Coach et Check, avec filtre par composant et versions repliables.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Eén gedeeld versienummer</b> — Beheer, Public, Coach, Check en Jury delen nu één versie en één tijdlijn.',
            'en' => '<b>One shared version number</b> — Admin, Public, Coach, Check and Jury now share one version and one timeline.',
            'de' => '<b>Eine gemeinsame Versionsnummer</b> — Verwaltung, Public, Coach, Check und Jury teilen jetzt eine Version und eine Zeitachse.',
            'fr' => '<b>Un numéro de version partagé</b> — Gestion, Public, Coach, Check et Jury partagent désormais une version et une chronologie.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Beheerders informeren per mail</b> — nieuwe knop (Info → Versie) om na een update alle beheerders te mailen wat er is veranderd.',
            'en' => '<b>Inform admins by e-mail</b> — new button (Info → Version) to e-mail all admins what changed after an update.',
            'de' => '<b>Administratoren per E-Mail informieren</b> — neuer Button (Info → Version), um nach einem Update alle Administratoren über die Änderungen zu mailen.',
            'fr' => '<b>Informer les administrateurs par e-mail</b> — nouveau bouton (Info → Version) pour envoyer à tous les administrateurs les nouveautés après une mise à jour.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Banen aan de eigen organisatie</b> — een wedstrijd koppelt nu altijd aan de baan van de eigen organisatie; ontbreekt die, dan wordt \'ie automatisch aangemaakt met stad, vereniging, logo en aliassen van een andere organisatie (zonder de sponsors). Foutieve koppelingen naar een andere organisatie worden hersteld.',
            'en' => '<b>Venues tied to your own organisation</b> — a race now always links to the venue of its own organisation; if missing, it is created automatically with city, club, logo and aliases from another organisation (without the sponsors). Wrong links to another organisation are repaired.',
            'de' => '<b>Bahnen an die eigene Organisation gebunden</b> — ein Rennen verknüpft jetzt immer mit der Bahn der eigenen Organisation; fehlt sie, wird sie automatisch mit Stadt, Verein, Logo und Aliassen einer anderen Organisation angelegt (ohne die Sponsoren). Falsche Verknüpfungen werden repariert.',
            'fr' => '<b>Pistes liées à ta propre organisation</b> — une course se lie désormais toujours à la piste de sa propre organisation ; si elle manque, elle est créée automatiquement avec ville, club, logo et alias d\'une autre organisation (sans les sponsors). Les liens erronés sont réparés.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Diagnose serie-klassement</b> — leest nu de juiste bron voor klassementen per naam/afstand, zodat de melding klopt.',
            'en' => '<b>Series ranking diagnosis</b> — now reads the correct source for per-name/distance rankings, so the message is accurate.',
            'de' => '<b>Diagnose Serien-Klassement</b> — liest jetzt die richtige Quelle für Klassements pro Name/Distanz, damit die Meldung stimmt.',
            'fr' => '<b>Diagnostic classement de série</b> — lit maintenant la bonne source pour les classements par nom/distance, pour un message correct.',
        ],
    ],
    // ── Coach ──
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['coach'],
        'tekst' => [
            'nl' => 'Nieuw: een <b>persoonlijk coach-account</b>. Stel je atleten één keer in — ze verschijnen automatisch bij elke wedstrijd, en ingelogd hoef je alleen nog de wedstrijd te kiezen. Beheer je lijst via de 👤-knop rechtsboven.',
            'en' => 'New: a <b>personal coach account</b>. Set up your skaters once — they appear automatically at every race, and once logged in you only pick the race. Manage your list via the 👤 button top right.',
            'de' => 'Neu: ein <b>persönliches Coach-Konto</b>. Stelle deine Skater einmal ein — sie erscheinen automatisch bei jedem Rennen, und eingeloggt wählst du nur noch das Rennen. Verwalte deine Liste über den 👤-Button oben rechts.',
            'fr' => 'Nouveau : un <b>compte coach personnel</b>. Configure tes skateurs une seule fois — ils apparaissent automatiquement à chaque course, et une fois connecté tu ne choisis que la course. Gère ta liste via le bouton 👤 en haut à droite.',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['coach'],
        'tekst' => [
            'nl' => '<b>Account beheren</b> — je kunt je coach-account nu zelf verwijderen en je hele atletenlijst in één keer wissen (👤-menu). Je lijst toont je atleten als pills met startnummer, en je zoekt op naam of startnummer.',
            'en' => '<b>Manage your account</b> — you can now delete your coach account yourself and clear your whole list at once (👤 menu). Your list shows skaters as pills with start number, and you search by name or start number.',
            'de' => '<b>Konto verwalten</b> — du kannst dein Coach-Konto jetzt selbst löschen und deine ganze Läuferliste auf einmal leeren (👤-Menü). Deine Liste zeigt die Skater als Pills mit Startnummer, und du suchst nach Name oder Startnummer.',
            'fr' => '<b>Gérer le compte</b> — tu peux maintenant supprimer ton compte coach toi-même et vider toute ta liste d\'un coup (menu 👤). Ta liste affiche les skateurs en pastilles avec le numéro de dossard, et tu recherches par nom ou numéro de dossard.',
        ],
    ],
    // ── Public ──
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['public'],
        'tekst' => [
            'nl' => '<b>Schakelen tussen kinderen</b> — de geselecteerde rijder-tab is breed met de voornaam, de andere tabs compact met alleen het startnummer. Rijders toevoegen en verwijderen doe je via de <b>+</b> ("Je gevolgde rijders").',
            'en' => '<b>Switching between children</b> — the selected skater tab is wide with the first name, the others compact with just the start number. Add and remove skaters via the <b>+</b> ("Skaters you follow").',
            'de' => '<b>Zwischen Kindern wechseln</b> — der ausgewählte Läufer-Tab ist breit mit dem Vornamen, die anderen kompakt mit nur der Startnummer. Läufer fügst du über das <b>+</b> hinzu und entfernst sie dort ("Deine verfolgten Läufer").',
            'fr' => '<b>Basculer entre enfants</b> — l\'onglet du skateur sélectionné est large avec le prénom, les autres compacts avec seulement le numéro de dossard. Ajoute et supprime des skateurs via le <b>+</b> ("Skateurs que tu suis").',
        ],
    ],
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['public'],
        'tekst' => [
            'nl' => '<b>Ritten van je andere kinderen</b> — in het Programma zie je nu ook de ritten van je andere gevolgde kinderen, in een andere kleur dan de geselecteerde rijder (ook bij een ingeklapte groep, en als ze samen in één heat zitten).',
            'en' => '<b>Races of your other children</b> — the program now also highlights the races of your other followed children, in a different colour than the selected skater (visible even on a collapsed group, and when they share a heat).',
            'de' => '<b>Rennen deiner anderen Kinder</b> — das Programm markiert jetzt auch die Rennen deiner anderen verfolgten Kinder in einer anderen Farbe als der ausgewählte Läufer (auch bei eingeklappter Gruppe und wenn sie zusammen in einem Heat sind).',
            'fr' => '<b>Courses de tes autres enfants</b> — le programme met aussi en évidence les courses de tes autres enfants suivis, dans une couleur différente du skateur sélectionné (visible même sur un groupe replié, et quand ils sont dans la même série).',
        ],
    ],
    // ── Check ──
    [
        'versie' => 'H997.31.07', 'datum' => '31-07-2026', 'onderdelen' => ['check'],
        'tekst' => [
            'nl' => '<b>Versienummer zichtbaar</b> — onderaan het info-scherm zie je nu welke versie draait.',
            'en' => '<b>Version number visible</b> — the info screen now shows which version is running.',
            'de' => '<b>Versionsnummer sichtbar</b> — im Info-Bildschirm siehst du jetzt, welche Version läuft.',
            'fr' => '<b>Numéro de version visible</b> — l\'écran d\'info montre désormais quelle version tourne.',
        ],
    ],
    // ── H360.06.07 — coach (rijder-lijst-perspectief) ──
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['coach'],
        'tekst'      => [
            'nl' => '<b>Filter op afstand + inklap-balk</b> in het programma — kies één afstand (bv. 500m) en gebruik de segment-knoppen <i>Inklappen / Uitklappen / Mijn</i> om binnen die afstand groepen dicht te klappen, allemaal open te zetten, of alleen de ritten van de rijders op je lijst te tonen.',
            'en' => '<b>Filter by distance + collapse bar</b> in the program — pick a single distance (e.g. 500m) and use the segment buttons <i>Collapse / Expand / Mine</i> to close groups within that distance, open them all, or show only the races of the skaters on your list.',
            'de' => '<b>Distanz-Filter + Ein-/Ausklapp-Leiste</b> im Programm — wähle eine Distanz (z.B. 500m) und benutze die Segment-Buttons <i>Einklappen / Ausklappen / Meine</i>, um Gruppen innerhalb dieser Distanz zu schließen, alle zu öffnen oder nur die Rennen der Skater deiner Liste zu zeigen.',
            'fr' => '<b>Filtre par distance + barre pliage</b> dans le programme — choisis une distance (par ex. 500m) et utilise les boutons de segment <i>Réduire / Développer / Les miens</i> pour fermer les groupes dans cette distance, tous les ouvrir, ou n\'afficher que les courses des skateurs de ta liste.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['coach'],
        'tekst'      => [
            'nl' => '<b>Rondes-tab</b> — nieuw tabblad met per-ronde uitslagen van alle DC\'s waarvoor je rijders volgt (serie, kwart, halve finale, A-finale, kleine finale). Zichtbaar is welke plek per ronde is behaald en of doorstroom naar de volgende ronde heeft plaatsgevonden.',
            'en' => '<b>Rounds tab</b> — new tab with per-round results across all DCs you follow skaters in (heats, quarter, semi, A-final, small final). Shows the position achieved in each round and whether progression to the next round has occurred.',
            'de' => '<b>Runden-Tab</b> — neuer Tab mit Ergebnissen pro Runde für alle DCs, in denen du Läufer verfolgst (Vorläufe, Viertel, Halbfinale, A-Finale, kleines Finale). Zeigt die in jeder Runde erreichte Platzierung und ob ein Weiterkommen in die nächste Runde erfolgt ist.',
            'fr' => '<b>Onglet Rondes</b> — nouvel onglet avec les résultats par tour pour toutes les DCs dont tu suis des skateurs (séries, quart, demi, finale A, petite finale). Montre la place obtenue à chaque tour et si un passage au tour suivant a eu lieu.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['coach'],
        'tekst'      => [
            'nl' => '<b>Snelle wedstrijd-selectie</b> in een nieuw <b>openings-venster</b> met filter-knoppen <i>Eerder / Vandaag / Later</i>. Verschijnt automatisch bij het openen van de app en sluit zodra een wedstrijd is geselecteerd — directe focus op de keuze, daarna de volledige ruimte voor het overzicht.',
            'en' => '<b>Quick race selection</b> in a new <b>opening window</b> with filter buttons <i>Earlier / Today / Later</i>. Appears automatically when the app opens and closes as soon as a race is selected — direct focus on the choice, then the full space for the overview.',
            'de' => '<b>Schnelle Rennauswahl</b> in einem neuen <b>Startfenster</b> mit Filter-Buttons <i>Früher / Heute / Später</i>. Erscheint automatisch beim Öffnen der App und schließt, sobald ein Rennen ausgewählt wurde — direkter Fokus auf die Auswahl, danach der volle Platz für die Übersicht.',
            'fr' => '<b>Sélection rapide de course</b> dans une nouvelle <b>fenêtre d\'ouverture</b> avec les boutons de filtre <i>Antérieur / Aujourd\'hui / Plus tard</i>. Apparaît automatiquement à l\'ouverture de l\'appli et se ferme dès qu\'une course est sélectionnée — focus direct sur le choix, puis tout l\'espace pour l\'aperçu.', // FR overgenomen van de identieke public-bullet (coach-i18n miste 'm)
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['coach'],
        'tekst'      => [
            'nl' => '<b>Bruto-tijd</b> zichtbaar naast de netto-tijd — herkenbaar aan ✋ (handmatige correctie) of 📷 (foto-finish correctie). Zo is in de heat-tabellen zichtbaar wanneer een correctie op de klokwaarde is toegepast.',
            'en' => '<b>Raw time</b> visible next to the net time — marked with ✋ (manual correction) or 📷 (photo-finish correction). This way, the heat tables show exactly when a correction was applied to the clock value.',
            'de' => '<b>Bruttozeit</b> sichtbar neben der Nettozeit — kenntlich an ✋ (Handkorrektur) oder 📷 (Fotofinish-Korrektur). So ist in den Heat-Tabellen sichtbar, wann eine Korrektur der Uhrzeit erfolgt ist.',
            'fr' => '<b>Temps brut</b> visible à côté du temps net — marqué ✋ (correction manuelle) ou 📷 (correction photo-finish). Ainsi, les tableaux de séries montrent exactement quand une correction a été appliquée au temps de l\'horloge.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['coach'],
        'tekst'      => [
            'nl' => '<b>Klassering per categorie</b> in de Uitslagen-tab — bij gecombineerde races (bv. HJA + HSA samen) verschijnt naast de overall rang een aparte kolom per categorie, zodat in één oogopslag zichtbaar is welke plek de rijder binnen de eigen categorie heeft behaald.',
            'en' => '<b>Ranking per category</b> in the Results tab — for combined races (e.g. HJA + HSA together) a separate column per category appears next to the overall rank, so the position achieved within the own category is visible at a glance.',
            'de' => '<b>Platzierung pro Kategorie</b> im Ergebnisse-Tab — bei kombinierten Rennen (z.B. HJA + HSA zusammen) erscheint neben dem Gesamtrang eine separate Spalte pro Kategorie, sodass die innerhalb der eigenen Kategorie erreichte Platzierung auf einen Blick sichtbar ist.',
            'fr' => '<b>Classement par catégorie</b> dans l\'onglet Résultats — pour les courses combinées (par ex. HJA + HSA ensemble) une colonne distincte par catégorie apparaît à côté du rang général, ce qui rend la place obtenue dans la propre catégorie visible d\'un coup d\'œil.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['coach'],
        'tekst'      => [
            'nl' => '<b>Kleine verbeteringen</b> voor de weergave op smalle schermen en de navigatie — waaronder filter-knoppen die weer binnen het openings-venster passen.',
            'en' => '<b>Small improvements</b> to the display on narrow screens and to navigation — including filter buttons that now fit within the opening window.',
            'de' => '<b>Kleine Verbesserungen</b> an der Darstellung auf schmalen Bildschirmen und der Navigation — u.a. Filter-Buttons, die wieder in das Startfenster passen.',
            'fr' => '<b>Petites améliorations</b> pour l\'affichage sur écrans étroits et pour la navigation — dont des boutons de filtre qui tiennent à nouveau dans la fenêtre d\'ouverture.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['coach'],
        'tekst'      => [
            'nl' => '<b>Kleine verbeteringen en bug-fixes</b> in de weergave van het programma.',
            'en' => '<b>Small improvements and bug fixes</b> in the program view.',
            'de' => '<b>Kleine Verbesserungen und Fehlerbehebungen</b> in der Programm-Ansicht.',
            'fr' => '<b>Petites améliorations et corrections</b> dans l\'affichage du programme.',
        ],
    ],
    // ── H360.06.07 — public (rijder-perspectief) ──
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Filter op afstand + inklap-balk</b> in het programma — kies één afstand (bv. 500m) en gebruik de segment-knoppen <i>Inklappen / Uitklappen / Mijn</i> om binnen die afstand groepen dicht te klappen, allemaal open te zetten, of alleen je eigen ritten te tonen.',
            'en' => '<b>Filter by distance + collapse bar</b> in the program — pick a single distance (e.g. 500m) and use the segment buttons <i>Collapse / Expand / Mine</i> to close groups within that distance, open them all, or show only your own races.',
            'de' => '<b>Distanz-Filter + Ein-/Ausklapp-Leiste</b> im Programm — wähle eine Distanz (z.B. 500m) und benutze die Segment-Buttons <i>Einklappen / Ausklappen / Meine</i>, um Gruppen innerhalb dieser Distanz zu schließen, alle zu öffnen oder nur deine eigenen Rennen zu zeigen.',
            'fr' => '<b>Filtre par distance + barre pliage</b> dans le programme — choisis une distance (par ex. 500m) et utilise les boutons de segment <i>Réduire / Développer / Les miens</i> pour fermer les groupes dans cette distance, tous les ouvrir, ou n\'afficher que tes propres courses.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Heats op finish-volgorde</b> — na de finish worden de rijders in de Heats-tab weergegeven in de volgorde waarin ze zijn gefinisht, zodat de eindstand van een heat in één oogopslag zichtbaar is.',
            'en' => '<b>Heats in finish order</b> — after the finish, skaters in the Heats tab are shown in the order in which they finished, so the outcome of a heat is visible at a glance.',
            'de' => '<b>Heats in Zieleinlaufreihenfolge</b> — nach dem Zieleinlauf werden die Läufer im Heats-Tab in der Reihenfolge des Zieleinlaufs angezeigt, sodass das Ergebnis eines Heats auf einen Blick sichtbar ist.',
            'fr' => '<b>Séries dans l\'ordre d\'arrivée</b> — après l\'arrivée, les skateurs dans l\'onglet Séries sont affichés dans l\'ordre d\'arrivée, ce qui rend le résultat d\'une série visible d\'un coup d\'œil.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Rondes-tab</b> — nieuw tabblad met jouw uitslag per ronde: welke plek je hebt gehaald in de serie, kwart of halve finale, of je bent doorgestroomd naar de A-finale of kleine finale, en waar je uiteindelijk bent geëindigd. Vervangt de vorige "Resultaten"-tab.',
            'en' => '<b>Rounds tab</b> — new tab with your result per round: what place you took in the heat, quarter or semi-final, whether you progressed to the A-final or small final, and where you eventually finished. Replaces the previous "Results" tab.',
            'de' => '<b>Runden-Tab</b> — neuer Tab mit deinem Ergebnis pro Runde: welchen Platz du im Vorlauf, Viertel- oder Halbfinale belegt hast, ob du ins A-Finale oder kleine Finale weitergekommen bist, und wo du am Ende gelandet bist. Ersetzt den bisherigen "Resultate"-Tab.',
            'fr' => '<b>Onglet Rondes</b> — nouvel onglet avec ton résultat par tour : quelle place tu as prise en série, quart ou demi-finale, si tu es passé en finale A ou petite finale, et où tu as terminé. Remplace l\'ancien onglet "Résultats".',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Snelle wedstrijd-selectie</b> in een nieuw <b>openings-venster</b> met filter-knoppen <i>Eerder / Vandaag / Later</i>. Verschijnt automatisch bij het openen van de app en sluit zodra een wedstrijd is geselecteerd — directe focus op de keuze, daarna de volledige ruimte voor het overzicht.',
            'en' => '<b>Quick race selection</b> in a new <b>opening window</b> with filter buttons <i>Earlier / Today / Later</i>. Appears automatically when the app opens and closes as soon as a race is selected — direct focus on the choice, then the full space for the overview.',
            'de' => '<b>Schnelle Rennauswahl</b> in einem neuen <b>Startfenster</b> mit Filter-Buttons <i>Früher / Heute / Später</i>. Erscheint automatisch beim Öffnen der App und schließt, sobald ein Rennen ausgewählt wurde — direkter Fokus auf die Auswahl, danach der volle Platz für die Übersicht.',
            'fr' => '<b>Sélection rapide de course</b> dans une nouvelle <b>fenêtre d\'ouverture</b> avec les boutons de filtre <i>Antérieur / Aujourd\'hui / Plus tard</i>. Apparaît automatiquement à l\'ouverture de l\'appli et se ferme dès qu\'une course est sélectionnée — focus direct sur le choix, puis tout l\'espace pour l\'aperçu.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Bruto-tijd</b> zichtbaar naast de netto-tijd — herkenbaar aan ✋ (handmatige correctie) of 📷 (foto-finish correctie). Zo zie je in "Jouw resultaat" en in de heat-tabellen precies wanneer een correctie op de klokwaarde is toegepast.',
            'en' => '<b>Raw time</b> visible next to the net time — marked with ✋ (manual correction) or 📷 (photo-finish correction). This way you see in "Your result" and the heat tables exactly when a correction was applied to the clock value.',
            'de' => '<b>Bruttozeit</b> sichtbar neben der Nettozeit — kenntlich an ✋ (Handkorrektur) oder 📷 (Fotofinish-Korrektur). So siehst du in "Dein Ergebnis" und in den Heat-Tabellen genau, wann eine Korrektur der Uhrzeit erfolgt ist.',
            'fr' => '<b>Temps brut</b> visible à côté du temps net — marqué ✋ (correction manuelle) ou 📷 (correction photo-finish). Ainsi tu vois dans "Ton résultat" et les tableaux de séries exactement quand une correction a été appliquée au temps de l\'horloge.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Klassering per categorie</b> in de Uitslagen-tab — bij gecombineerde races (bv. HJA + HSA samen) verschijnt naast de overall rang een aparte kolom per categorie, zodat in één oogopslag zichtbaar is welke plek binnen de eigen categorie is behaald.',
            'en' => '<b>Ranking per category</b> in the Results tab — for combined races (e.g. HJA + HSA together) a separate column per category appears next to the overall rank, so the position achieved within the own category is visible at a glance.',
            'de' => '<b>Platzierung pro Kategorie</b> im Ergebnisse-Tab — bei kombinierten Rennen (z.B. HJA + HSA zusammen) erscheint neben dem Gesamtrang eine separate Spalte pro Kategorie, sodass die innerhalb der eigenen Kategorie erreichte Platzierung auf einen Blick sichtbar ist.',
            'fr' => '<b>Classement par catégorie</b> dans l\'onglet Résultats — pour les courses combinées (par ex. HJA + HSA ensemble) une colonne distincte par catégorie apparaît à côté du rang général, ce qui rend la place obtenue dans la propre catégorie visible d\'un coup d\'œil.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Kleine verbeteringen</b> voor de weergave op smalle schermen en de navigatie — waaronder filter-knoppen die weer binnen het openings-venster passen.',
            'en' => '<b>Small improvements</b> to the display on narrow screens and to navigation — including filter buttons that now fit within the opening window.',
            'de' => '<b>Kleine Verbesserungen</b> an der Darstellung auf schmalen Bildschirmen und der Navigation — u.a. Filter-Buttons, die wieder in das Startfenster passen.',
            'fr' => '<b>Petites améliorations</b> pour l\'affichage sur écrans étroits et pour la navigation — dont des boutons de filtre qui tiennent à nouveau dans la fenêtre d\'ouverture.',
        ],
    ],
    [
        'versie'     => 'H360.06.07',
        'datum'      => '06-07-2026',
        'onderdelen' => ['public'],
        'tekst'      => [
            'nl' => '<b>Kleine verbeteringen en bug-fixes</b> in de weergave van het programma.',
            'en' => '<b>Small improvements and bug fixes</b> in the program view.',
            'de' => '<b>Kleine Verbesserungen und Fehlerbehebungen</b> in der Programm-Ansicht.',
            'fr' => '<b>Petites améliorations et corrections</b> dans l\'affichage du programme.',
        ],
    ],
];
