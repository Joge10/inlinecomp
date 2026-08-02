<?php
// ============================================================
//  inc/coach_mail.php — gedeelde coach-mail-infra
//  Gebruikt door api/coach_account.php (registratie/reset) en
//  api/coach_beheer.php (goedkeuren/afwijzen).
//
//  Afzender + domein: devriesen.com (DKIM/DMARC/SPF geregeld).
//  Mails zijn plain-text, tweetalig: Nederlands, met Engels eronder.
//  Guards (!defined / !function_exists) zodat dubbel-includen veilig is.
// ============================================================

if (!defined('COACH_MAIL_FROM')) {
    define('COACH_MAIL_FROM',      'InlineComp <inlinecomp@devriesen.com>');
    define('COACH_MAIL_ENVELOPE',  'inlinecomp@devriesen.com');   // -f envelope (SPF-alignment)
    define('COACH_NOTIFY_MAIL_TO', 'inlinecomp@devriesen.com');   // owner krijgt registratie-melding
    define('COACH_RESET_URL',      'https://inlineresults.devriesen.com/coach/reset.php');
    define('COACH_LOGIN_URL',      'https://inlineresults.devriesen.com/coach/');
    define('COACH_PRIVACY_URL',    'https://inlineresults.devriesen.com/privacyverklaring.php');
}

if (!function_exists('coachMail')) {
    /** Verstuurt een coach-mail via mail() met de app-standaard headers + -f envelope.
     *  Message-ID/Date op devriesen.com (uitgelijnd met de From-afzender) i.p.v. de
     *  byethost-default — scheelt een spam-minpunt bij strenge filters. */
    function coachMail(string $to, string $subject, string $body): bool {
        $msgId = sprintf('<%s.%s@devriesen.com>', date('YmdHis'), bin2hex(random_bytes(8)));
        $headers = implode("\r\n", [
            'From: ' . COACH_MAIL_FROM,
            'Reply-To: ' . COACH_MAIL_ENVELOPE,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: ' . $msgId,
            'X-Mailer: InlineComp Coach',
        ]);
        return @mail($to, $subject, $body, $headers, '-f' . COACH_MAIL_ENVELOPE);
    }
}

if (!function_exists('coachMailScheiding')) {
    /** Scheidingslijn tussen het NL- en EN-blok. */
    function coachMailScheiding(): string {
        return "\n\n———————————————————————— (English below) ————————————————————————\n\n";
    }
}

if (!function_exists('coachMailBevestiging')) {
    /** (3) Bevestiging direct na registratie-aanvraag → naar de coach zelf. */
    function coachMailBevestiging(string $naam): array {
        $login = COACH_LOGIN_URL;
        $nl = "Hoi $naam,\n\n"
            . "Bedankt voor je aanvraag voor een InlineComp coach-account. We hebben 'm ontvangen — "
            . "een beheerder bekijkt 'm en keurt 'm goed of af. Je krijgt van beide een e-mail.\n\n"
            . "Tot die tijd kun je al inloggen en met de (anonieme) deelnemerslijst werken:\n$login\n"
            . "Zodra je bent goedgekeurd, kun je je eigen rijders volgen met highlight.\n\n"
            . "Groet,\nInlineComp";
        $en = "Hi $naam,\n\n"
            . "Thanks for requesting an InlineComp coach account. We've received it — an administrator "
            . "will review it and approve or reject it. You'll get an e-mail either way.\n\n"
            . "Until then you can already log in and use the (anonymous) start list:\n$login\n"
            . "Once approved, you can follow your own riders with highlighting.\n\n"
            . "Regards,\nInlineComp";
        return [
            'subject' => 'InlineComp — aanvraag ontvangen / request received',
            'body'    => $nl . coachMailScheiding() . $en,
        ];
    }
}

if (!function_exists('coachMailGoedgekeurd')) {
    /** (1) Goedkeuring → naar de coach. Bevat uitleg gebruik + privacy + lijstbeheer. */
    function coachMailGoedgekeurd(string $naam): array {
        $login = COACH_LOGIN_URL;
        $nl = "Hoi $naam,\n\n"
            . "Goed nieuws: je InlineComp coach-account is goedgekeurd. Je kunt nu inloggen en je eigen "
            . "rijders volgen:\n$login\n\n"
            . "ZO WERKT HET\n"
            . "• Je lijst opbouwen: zoek rijders op naam, club of startnummer en voeg ze toe. Je kunt ook "
            . "in één keer alle rijders van een club of sponsor toevoegen.\n"
            . "• Let op — je lijst is een momentopname: nieuwe leden van een club komen NIET automatisch in "
            . "je lijst; voeg je die zelf toe (of voeg de club opnieuw toe — bestaande blijven staan). Een "
            . "rijder die van club wisselt of stopt, blijft in je lijst tot je 'm verwijdert.\n"
            . "• Uitbreiden of opschonen: voeg rijders toe of verwijder ze op elk moment. Je kunt ook je "
            . "hele lijst in één keer wissen.\n"
            . "• Tijdens een wedstrijd zie je van jouw rijders meteen hun heats, starttijden en uitslagen, "
            . "met highlight.\n\n"
            . "PRIVACY (jouw gegevens)\n"
            . "Met een account ben je niet meer anoniem: we bewaren je naam, e-mailadres en je atletenlijst "
            . "om je te herkennen en je rijders te tonen. Je wachtwoord staat versleuteld opgeslagen en je "
            . "logins worden vastgelegd voor de beveiliging. Je kunt je account altijd zelf verwijderen "
            . "(je lijst gaat mee) en het vervalt automatisch na een jaar zonder inloggen. Zie de "
            . "privacyverklaring: " . COACH_PRIVACY_URL . "\n"
            . "Voeg van rijders alleen mensen toe die je echt coacht (je ziet naam, club en categorie) en "
            . "verwijder ze — of wis je hele lijst — zodra je ze niet meer volgt.\n\n"
            . "Groet,\nInlineComp";
        $en = "Hi $naam,\n\n"
            . "Good news: your InlineComp coach account has been approved. You can now log in and follow "
            . "your own riders:\n$login\n\n"
            . "HOW IT WORKS\n"
            . "• Building your list: search riders by name, club or start number and add them. You can also "
            . "add all riders of a club or sponsor at once.\n"
            . "• Note — your list is a snapshot: new members of a club do NOT appear automatically; add them "
            . "yourself (or add the club again — existing entries stay). A rider who switches club or quits "
            . "stays in your list until you remove them.\n"
            . "• Extending or cleaning up: add or remove riders at any time. You can also clear your entire "
            . "list in one go.\n"
            . "• During a race you immediately see your riders' heats, start times and results, highlighted.\n\n"
            . "PRIVACY (your data)\n"
            . "With an account you are no longer anonymous: we store your name, e-mail address and athlete "
            . "list to recognise you and show your riders. Your password is stored encrypted and your logins "
            . "are recorded for security. You can delete your account yourself at any time (your list goes "
            . "with it) and it expires automatically after a year without logging in. See the privacy "
            . "statement: " . COACH_PRIVACY_URL . "\n"
            . "As for the riders you add, only add people you actually coach (you see name, club and "
            . "category) and remove them — or clear your whole list — once you no longer follow them.\n\n"
            . "Regards,\nInlineComp";
        return [
            'subject' => 'InlineComp — je coach-account is goedgekeurd / your coach account is approved',
            'body'    => $nl . coachMailScheiding() . $en,
        ];
    }
}

if (!function_exists('coachMailAfgewezen')) {
    /** (2) Afwijzing → naar de coach. Kort en netjes. */
    function coachMailAfgewezen(string $naam): array {
        $login = COACH_LOGIN_URL;
        $nl = "Hoi $naam,\n\n"
            . "Je aanvraag voor een InlineComp coach-account is helaas niet goedgekeurd. Je kunt InlineComp "
            . "gewoon blijven gebruiken met de (anonieme) deelnemerslijst:\n$login\n\n"
            . "Denk je dat dit een vergissing is? Antwoord dan gerust op deze e-mail.\n\n"
            . "Groet,\nInlineComp";
        $en = "Hi $naam,\n\n"
            . "Unfortunately your request for an InlineComp coach account was not approved. You can still "
            . "use InlineComp with the (anonymous) start list:\n$login\n\n"
            . "Think this is a mistake? Just reply to this e-mail.\n\n"
            . "Regards,\nInlineComp";
        return [
            'subject' => 'InlineComp — coach-account aanvraag / coach account request',
            'body'    => $nl . coachMailScheiding() . $en,
        ];
    }
}
