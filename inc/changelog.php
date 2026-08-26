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
    // ── Patches onder H1243.10.08 (onderhoud & beveiliging, alleen Beheer) ──
    [
        'versie' => 'H1243.10.08', 'datum' => '26-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Wedstrijdprotocol — afvalkoers in deelnemerslijst</b> — de deelnemerslijst in het protocol liet afvalkoersen ten onrechte weg. Afvalkoersen horen er nu gewoon in; relays (aflossing/estafette, nog niet ondersteund) worden wél uitgesloten.',
            'en' => '🔧 <b>Competition protocol — elimination race in participant list</b> — the protocol\'s participant list wrongly omitted elimination races. Elimination races now appear as they should; relays (not yet supported) are excluded instead.',
            'de' => '🔧 <b>Wettkampfprotokoll — Ausscheidungsrennen in Teilnehmerliste</b> — die Teilnehmerliste im Protokoll ließ Ausscheidungsrennen fälschlich weg. Ausscheidungsrennen erscheinen jetzt wie vorgesehen; Staffeln (noch nicht unterstützt) werden stattdessen ausgeschlossen.',
            'fr' => '🔧 <b>Protocole de compétition — course à élimination dans la liste des participants</b> — la liste des participants du protocole omettait à tort les courses à élimination. Elles apparaissent désormais correctement ; les relais (pas encore pris en charge) sont exclus à la place.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '26-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Demo-rijders correct gemarkeerd</b> — demo-rijders verschijnen niet langer in de "Wacht-op-KNSB rijders koppelen"-lijst (Systeem → Helpers), en een demo-import markeert nieuwe demo-rijders nu consistent als demo.',
            'en' => '🔧 <b>Demo riders correctly marked</b> — demo riders no longer appear in the "Link pending-KNSB riders" list (System → Helpers), and a demo import now consistently marks new demo riders as demo.',
            'de' => '🔧 <b>Demo-Fahrer korrekt markiert</b> — Demo-Fahrer erscheinen nicht mehr in der "Auf-KNSB-wartende Fahrer verknüpfen"-Liste (System → Helfer), und ein Demo-Import markiert neue Demo-Fahrer jetzt konsistent als Demo.',
            'fr' => '🔧 <b>Patineurs démo correctement marqués</b> — les patineurs démo n\'apparaissent plus dans la liste « Lier les patineurs en attente KNSB » (Système → Assistants), et un import démo marque désormais les nouveaux patineurs démo de façon cohérente comme démo.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '24-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Klassement-import — extra PDF-formaat</b> — de tussenstand-selectielijst "Tussenstand selectie NK Weg" (met aparte licentie-kolom en meerdere secties per pagina) wordt nu herkend. Voorheen gaf de import "geen rijders herkend".',
            'en' => '🔧 <b>Standings import — extra PDF format</b> — the interim selection list "Tussenstand selectie NK Weg" (with a separate licence column and multiple sections per page) is now recognised. Previously the import returned "no riders recognised".',
            'de' => '🔧 <b>Wertungs-Import — zusätzliches PDF-Format</b> — die Zwischenstands-Auswahlliste "Tussenstand selectie NK Weg" (mit separater Lizenznummer-Spalte und mehreren Abschnitten pro Seite) wird jetzt erkannt. Zuvor meldete der Import "keine Fahrer erkannt".',
            'fr' => '🔧 <b>Import de classement — format PDF supplémentaire</b> — la liste de sélection intermédiaire « Tussenstand selectie NK Weg » (avec une colonne licence distincte et plusieurs sections par page) est désormais reconnue. Auparavant, l\'import renvoyait « aucun patineur reconnu ».',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '24-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔒 <b>Public — zoeken alleen binnen de wedstrijd</b> — bij het toevoegen van een te volgen rijder verschijnen nu alleen deelnemers van de gekozen wedstrijd (dataminimalisatie). Al gevolgde rijders uit andere wedstrijden blijven gewoon in je lijst staan.',
            'en' => '🔒 <b>Public — search limited to the competition</b> — when adding a rider to follow, only participants of the chosen competition now appear (data minimisation). Riders you already follow from other competitions stay in your list.',
            'de' => '🔒 <b>Public — Suche nur innerhalb des Wettkampfs</b> — beim Hinzufügen eines zu verfolgenden Fahrers erscheinen jetzt nur Teilnehmer des gewählten Wettkampfs (Datenminimierung). Bereits verfolgte Fahrer aus anderen Wettkämpfen bleiben in deiner Liste.',
            'fr' => '🔒 <b>Public — recherche limitée à la compétition</b> — lors de l\'ajout d\'un patineur à suivre, seuls les participants de la compétition choisie apparaissent désormais (minimisation des données). Les patineurs déjà suivis d\'autres compétitions restent dans votre liste.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '24-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Diverse weergave- en scroll-fixes</b> — scrollen in keuze-/pop-upvensters activeert niet langer per ongeluk "trek om te vernieuwen" (public + coach); de rijder-tabs tonen nummer en naam nu op twee regels zodat lange startnummers netjes passen (public); de startnummer-kolom in Import is iets breder; en de bezoekers-grafiek toont geen dubbele "1" meer op de as (Beheer).',
            'en' => '🔧 <b>Various display and scroll fixes</b> — scrolling inside selection/pop-up windows no longer accidentally triggers "pull to refresh" (public + coach); the rider tabs now show number and name on two lines so long start numbers fit neatly (public); the start-number column in Import is a bit wider; and the visitors chart no longer shows a duplicate "1" on the axis (Admin).',
            'de' => '🔧 <b>Diverse Anzeige- und Scroll-Fixes</b> — das Scrollen in Auswahl-/Pop-up-Fenstern löst nicht mehr versehentlich "zum Aktualisieren ziehen" aus (Public + Coach); die Fahrer-Tabs zeigen Nummer und Name jetzt auf zwei Zeilen, sodass lange Startnummern gut passen (Public); die Startnummer-Spalte im Import ist etwas breiter; und das Besucher-Diagramm zeigt keine doppelte "1" mehr auf der Achse (Verwaltung).',
            'fr' => '🔧 <b>Diverses corrections d\'affichage et de défilement</b> — le défilement dans les fenêtres de sélection/pop-up ne déclenche plus accidentellement « tirer pour actualiser » (public + coach) ; les onglets patineur affichent désormais le numéro et le nom sur deux lignes pour que les longs numéros de dossard tiennent bien (public) ; la colonne du numéro de départ dans l\'Import est un peu plus large ; et le graphique des visiteurs n\'affiche plus de « 1 » en double sur l\'axe (Gestion).',
        ],
    ],

    [
        'versie' => 'H1243.10.08', 'datum' => '24-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Jury/uitslag — kleine fixes</b> — een gecombineerde eindsanctie (bv. DQ-TF,FS) kleurt de rij nu rood en telt mee als afgerond in de compleet-check; en een handmatig gezette runner-up wordt niet meer onvoorwaardelijk gewist bij een upstream-wijziging.',
            'en' => '🔧 <b>Jury/results — small fixes</b> — a combined final sanction (e.g. DQ-TF,FS) now turns the row red and counts as completed in the completeness check; and a manually set runner-up is no longer unconditionally cleared on an upstream change.',
            'de' => '🔧 <b>Jury/Ergebnisse — kleine Fixes</b> — eine kombinierte Endsanktion (z. B. DQ-TF,FS) färbt die Zeile jetzt rot und zählt als abgeschlossen in der Vollständigkeitsprüfung; und ein manuell gesetzter Runner-up wird bei einer Upstream-Änderung nicht mehr bedingungslos gelöscht.',
            'fr' => '🔧 <b>Jury/résultats — petites corrections</b> — une sanction finale combinée (p. ex. DQ-TF,FS) colore désormais la ligne en rouge et compte comme terminée dans le contrôle de complétude ; et un runner-up défini manuellement n\'est plus effacé sans condition lors d\'une modification en amont.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '24-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Import — kleine UI-verbeteringen</b> — de categorie-tab toont nu het aantal actieve (loting-)rijders in plaats van het totaal, en de status-badges hebben een gelijke grootte.',
            'en' => '🔧 <b>Import — small UI improvements</b> — the category tab now shows the number of active (draw-eligible) riders instead of the total, and the status badges are equal in size.',
            'de' => '🔧 <b>Import — kleine UI-Verbesserungen</b> — der Kategorie-Tab zeigt jetzt die Zahl der aktiven (auslosbaren) Fahrer statt der Gesamtzahl, und die Status-Badges haben eine einheitliche Größe.',
            'fr' => '🔧 <b>Import — petites améliorations d\'interface</b> — l\'onglet de catégorie affiche désormais le nombre de patineurs actifs (éligibles au tirage) au lieu du total, et les badges de statut ont une taille uniforme.',
        ],
    ],

    // ══ H1243.10.08 — tijdschema-wizard, push-meldingen (bèta), demo, sticky filters ══
    // ── Beheer (admin) ──
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Tijdschema-wizard</b> — nieuwe stap-voor-stap-wizard om een wedstrijd op te bouwen: de uit de KNSB geïmporteerde afstandscombinaties bijstellen, de afstand-instellingen per groep vastleggen, het programma met blokken en ritten opzetten, en A-finales combineren.',
            'en' => '<b>Schedule wizard</b> — new step-by-step wizard to build a competition: fine-tune the distance combinations imported from the KNSB, set the distance options per group, lay out the programme with blocks and heats, and combine A-finals.',
            'de' => '<b>Zeitplan-Assistent</b> — neuer Schritt-für-Schritt-Assistent zum Aufbau eines Wettkampfs: die aus dem KNSB importierten Distanzkombinationen anpassen, die Distanzeinstellungen pro Gruppe festlegen, das Programm mit Blöcken und Läufen aufsetzen, und A-Finals kombinieren.',
            'fr' => '<b>Assistant de programme</b> — nouvel assistant pas à pas pour construire une compétition : ajuster les combinaisons de distances importées de la KNSB, définir les réglages de distance par groupe, mettre en place le programme avec blocs et séries, et combiner les finales A.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Demo-wedstrijd</b> — een oefen-wedstrijd met fictieve deelnemers die je via Import binnenhaalt (zichtbaar met <code>?demo</code>), om zonder echte data te testen of te demonstreren.',
            'en' => '<b>Demo competition</b> — a practice competition with fictional participants that you import (visible with <code>?demo</code>), to test or demonstrate without real data.',
            'de' => '<b>Demo-Wettkampf</b> — ein Übungswettkampf mit fiktiven Teilnehmern, den du über den Import lädst (sichtbar mit <code>?demo</code>), zum Testen oder Vorführen ohne echte Daten.',
            'fr' => '<b>Compétition démo</b> — une compétition d\'entraînement avec des participants fictifs, importée (visible avec <code>?demo</code>), pour tester ou faire une démo sans données réelles.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Import: vereniging/sponsor/woonplaats overschrijven</b> — een eerste import vult deze velden; een handmatige correctie blijft bij een herimport bewaard.',
            'en' => '<b>Import: override club/sponsor/town</b> — a first import fills these fields; a manual correction is kept on re-import.',
            'de' => '<b>Import: Verein/Sponsor/Wohnort überschreiben</b> — ein erster Import füllt diese Felder; eine manuelle Korrektur bleibt beim erneuten Import erhalten.',
            'fr' => '<b>Import : remplacer club/sponsor/ville</b> — un premier import remplit ces champs ; une correction manuelle est conservée lors d\'un réimport.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Coach-beheer</b> — uitgebreid: e-mails bij registratie-aanvraag, goedkeuring en afwijzing, een Verversen-knop en een altijd-verse Coaches-lijst.',
            'en' => '<b>Coach management</b> — expanded: e-mails on registration request, approval and rejection, a Refresh button and an always-fresh Coaches list.',
            'de' => '<b>Coach-Verwaltung</b> — erweitert: E-Mails bei Registrierungsanfrage, Genehmigung und Ablehnung, ein Aktualisieren-Button und eine stets frische Coaches-Liste.',
            'fr' => '<b>Gestion des coachs</b> — enrichie : e-mails lors d\'une demande d\'inscription, approbation et refus, un bouton Actualiser et une liste des coachs toujours à jour.',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '🔔 <b>Push-meldingen (bèta)</b> — een nieuwe mededeling gaat nu ook als pushbericht naar abonnees die mededelingen aan hebben staan: bij een wedstrijd-mededeling naar wie een rijder uit díe wedstrijd volgt, bij een algemene mededeling naar allen. In Systeem → Bezoekers zie je het aantal push-abonnees. <i>Nieuw en nog in bèta.</i>',
            'en' => '🔔 <b>Push notifications (beta)</b> — a new announcement is now also sent as a push to subscribers who have announcements enabled: for a competition announcement to those who follow a skater in that competition, for a general announcement to all of them. In System → Visitors you can see the number of push subscribers. <i>New and still in beta.</i>',
            'de' => '🔔 <b>Push-Benachrichtigungen (Beta)</b> — eine neue Mitteilung geht jetzt auch als Push an Abonnenten, die Mitteilungen aktiviert haben: bei einer Wettkampf-Mitteilung an alle, die einen Fahrer aus diesem Wettkampf verfolgen, bei einer allgemeinen Mitteilung an alle. Unter System → Besucher siehst du die Zahl der Push-Abonnenten. <i>Neu und noch in Beta.</i>',
            'fr' => '🔔 <b>Notifications push (bêta)</b> — une nouvelle annonce est désormais aussi envoyée en push aux abonnés qui ont activé les annonces : pour une annonce de compétition, à ceux qui suivent un patineur de cette compétition ; pour une annonce générale, à tous. Dans Système → Visiteurs, vous voyez le nombre d\'abonnés push. <i>Nouveau et encore en bêta.</i>',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['admin'],
        'tekst' => [
            'nl' => '<b>Diverse beheer-verbeteringen</b> — onderhoud- en beveiligingsfixes worden nu gegroepeerd onder de lopende versie (met een eigen filter), en de Systeem-tabvolgorde is logischer ("Coach" → "Coaches").',
            'en' => '<b>Various admin improvements</b> — maintenance and security fixes are now grouped under the current version (with their own filter), and the System tab order is more logical ("Coach" → "Coaches").',
            'de' => '<b>Diverse Verwaltungs-Verbesserungen</b> — Wartungs- und Sicherheitsfixes werden jetzt unter der laufenden Version gruppiert (mit eigenem Filter), und die System-Tab-Reihenfolge ist logischer ("Coach" → "Coaches").',
            'fr' => '<b>Diverses améliorations d\'administration</b> — les correctifs de maintenance et de sécurité sont désormais regroupés sous la version courante (avec leur propre filtre), et l\'ordre des onglets Système est plus logique ("Coach" → "Coaches").',
        ],
    ],
    // ── Public ──
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['public'],
        'tekst' => [
            'nl' => '🔔 <b>Pushmeldingen op je telefoon (bèta)</b> — volg je een rijder, dan kun je een seintje krijgen zodra er is geloot 🚩, een uitslag 🏁 binnen is of de organisatie een mededeling 📢 plaatst — óók als de app dicht is. Je zet het zelf aan via het 🔔-blok en kiest daar per type (loting/uitslag/mededelingen) wat je wilt ontvangen. Goed getest, maar meld gerust als er iets niet klopt (vooral op de iPhone).',
            'en' => '🔔 <b>Push notifications on your phone (beta)</b> — when you follow a skater you can get an alert as soon as a draw is made 🚩, a result 🏁 comes in or the organisation posts an announcement 📢 — even when the app is closed. You turn it on yourself via the 🔔 block and choose there per type (draw/result/announcements) what you want to receive. Well tested, but do let us know if something is off (especially on iPhone).',
            'de' => '🔔 <b>Push-Benachrichtigungen auf deinem Handy (Beta)</b> — wenn du einen Fahrer verfolgst, bekommst du eine Meldung, sobald ausgelost wurde 🚩, ein Ergebnis 🏁 vorliegt oder die Organisation eine Mitteilung 📢 veröffentlicht — auch wenn die App geschlossen ist. Du schaltest es selbst über den 🔔-Block ein und wählst dort pro Typ (Auslosung/Ergebnis/Mitteilungen), was du erhalten möchtest. Gut getestet, aber melde dich, wenn etwas nicht stimmt (besonders auf dem iPhone).',
            'fr' => '🔔 <b>Notifications push sur votre téléphone (bêta)</b> — lorsque vous suivez un patineur, vous pouvez être alerté dès qu\'un tirage est fait 🚩, qu\'un résultat 🏁 arrive ou que l\'organisation publie une annonce 📢 — même quand l\'app est fermée. Vous l\'activez vous-même via le bloc 🔔 et choisissez par type (tirage/résultat/annonces) ce que vous voulez recevoir. Bien testé, mais signalez tout souci (surtout sur iPhone).',
        ],
    ],
    // ── Coach ──
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['coach'],
        'tekst' => [
            'nl' => '🔔 <b>Pushmeldingen op je telefoon (bèta)</b> — met een coach-account krijg je een seintje zodra er voor je atleten is geloot 🚩, een uitslag 🏁 binnen is of de organisatie een mededeling 📢 plaatst — óók als de app dicht is. Je zet het zelf aan in je coach-account en kiest per type (loting/uitslag/mededelingen) wat je wilt ontvangen. Goed getest, maar meld gerust als er iets niet klopt (vooral op de iPhone).',
            'en' => '🔔 <b>Push notifications on your phone (beta)</b> — with a coach account you get an alert as soon as your athletes are drawn 🚩, a result 🏁 comes in or the organisation posts an announcement 📢 — even when the app is closed. You turn it on yourself in your coach account and choose per type (draw/result/announcements) what you want to receive. Well tested, but do let us know if something is off (especially on iPhone).',
            'de' => '🔔 <b>Push-Benachrichtigungen auf deinem Handy (Beta)</b> — mit einem Coach-Konto bekommst du eine Meldung, sobald deine Athleten ausgelost wurden 🚩, ein Ergebnis 🏁 vorliegt oder die Organisation eine Mitteilung 📢 veröffentlicht — auch wenn die App geschlossen ist. Du schaltest es selbst in deinem Coach-Konto ein und wählst pro Typ (Auslosung/Ergebnis/Mitteilungen), was du erhalten möchtest. Gut getestet, aber melde dich, wenn etwas nicht stimmt (besonders auf dem iPhone).',
            'fr' => '🔔 <b>Notifications push sur votre téléphone (bêta)</b> — avec un compte coach, vous êtes alerté dès que vos athlètes sont tirés au sort 🚩, qu\'un résultat 🏁 arrive ou que l\'organisation publie une annonce 📢 — même quand l\'app est fermée. Vous l\'activez vous-même dans votre compte coach et choisissez par type (tirage/résultat/annonces) ce que vous voulez recevoir. Bien testé, mais signalez tout souci (surtout sur iPhone).',
        ],
    ],
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['coach'],
        'tekst' => [
            'nl' => '<b>Coach-account e-mails</b> — je krijgt nu een bevestiging per e-mail bij een registratie-aanvraag, goedkeuring/afwijzing en wachtwoord-reset.',
            'en' => '<b>Coach account e-mails</b> — you now get an e-mail confirmation on a registration request, approval/rejection and password reset.',
            'de' => '<b>Coach-Konto-E-Mails</b> — du erhältst jetzt eine E-Mail-Bestätigung bei Registrierungsanfrage, Genehmigung/Ablehnung und Passwort-Reset.',
            'fr' => '<b>E-mails du compte coach</b> — vous recevez désormais une confirmation par e-mail lors d\'une demande d\'inscription, d\'une approbation/refus et d\'une réinitialisation de mot de passe.',
        ],
    ],
    // ── Public + Coach ──
    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['public', 'coach'],
        'tekst' => [
            'nl' => '<b>Filters blijven staan</b> — in het Programma blijven het afstand-filter en de inklappen/uitklappen/mijn-balk nu boven in beeld terwijl je scrollt.',
            'en' => '<b>Filters stay put</b> — in the Programme, the distance filter and the collapse/expand/mine bar now stay at the top while you scroll.',
            'de' => '<b>Filter bleiben sichtbar</b> — im Programm bleiben der Distanzfilter und die Einklappen/Ausklappen/Meine-Leiste jetzt oben, während du scrollst.',
            'fr' => '<b>Les filtres restent en place</b> — dans le Programme, le filtre de distance et la barre replier/déplier/les miens restent en haut pendant le défilement.',
        ],
    ],

    [
        'versie' => 'H1243.10.08', 'datum' => '10-08-2026', 'onderdelen' => ['public', 'coach'],
        'tekst' => [
            'nl' => '<b>Privacyverklaring bijgewerkt</b> — een nieuwe paragraaf legt uit welke gegevens de pushmeldingen per apparaat verwerken (push-abonnement, gevolgde licentienummers, taal) en dat de bezorging via de push-dienst van je browser (Google/Mozilla/Apple) verloopt.',
            'en' => '<b>Privacy statement updated</b> — a new section explains which data push notifications process per device (push subscription, followed licence numbers, language) and that delivery goes via your browser\'s push service (Google/Mozilla/Apple).',
            'de' => '<b>Datenschutzerklärung aktualisiert</b> — ein neuer Abschnitt erklärt, welche Daten die Push-Benachrichtigungen pro Gerät verarbeiten (Push-Abonnement, verfolgte Lizenznummern, Sprache) und dass die Zustellung über den Push-Dienst deines Browsers (Google/Mozilla/Apple) erfolgt.',
            'fr' => '<b>Déclaration de confidentialité mise à jour</b> — une nouvelle section explique quelles données les notifications push traitent par appareil (abonnement push, numéros de licence suivis, langue) et que la distribution passe par le service push de votre navigateur (Google/Mozilla/Apple).',
        ],
    ],

    // ── Patches onder H997.31.07 (onderhoud & beveiliging, alleen Beheer) ──
    [
        'versie' => 'H997.31.07', 'datum' => '05-08-2026',
        'soort'  => 'patch', 'onderdelen' => ['patch'],
        'tekst' => [
            'nl' => '🔧 <b>Tijdschema — gelijk-genoemde afstanden</b> — afstanden met dezelfde naam maar verschillende lengte (bv. "Sprint" 300 m en 500 m) worden nu overal als aparte afstanden behandeld: afstandsinstellingen, programma, startlijsten, uitslag en klassement. Voorheen vielen ze per ongeluk samen.',
            'en' => '🔧 <b>Schedule — same-named distances</b> — distances that share a name but have different lengths (e.g. "Sprint" 300 m and 500 m) are now treated as separate distances everywhere: distance settings, programme, start lists, results and standings. Previously they were merged by mistake.',
            'de' => '🔧 <b>Zeitplan — gleichnamige Distanzen</b> — Distanzen mit gleichem Namen aber unterschiedlicher Länge (z. B. „Sprint" 300 m und 500 m) werden jetzt überall als getrennte Distanzen behandelt: Distanzeinstellungen, Programm, Startlisten, Ergebnisse und Wertung. Zuvor wurden sie versehentlich zusammengeführt.',
            'fr' => '🔧 <b>Programme — distances de même nom</b> — les distances portant le même nom mais de longueur différente (p. ex. « Sprint » 300 m et 500 m) sont désormais traitées comme des distances distinctes partout : réglages de distance, programme, listes de départ, résultats et classement. Auparavant, elles étaient fusionnées par erreur.',
        ],
    ],
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
