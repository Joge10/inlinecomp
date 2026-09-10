<?php
// ============================================================
//  inc/profiel_mail.php — mail-infra voor het persoonlijke
//  "Mijn InlineComp"-profiel (aanvraag → goedkeuring → link).
//  Zelfde stijl als inc/coach_mail.php: plain-text, NL met EN
//  eronder, verzonden via mail() met uitgelijnde headers.
//
//  Verschil met coach: de organisatie krijgt een Cc van de aan
//  de rijder verstuurde mails (zodat ze weten dat er een aanvraag
//  is én dat alles correct is verzonden).
//  Guards zodat dubbel-includen veilig is.
// ============================================================

if (!defined('PROFIEL_MAIL_FROM')) {
    define('PROFIEL_MAIL_FROM',     'InlineComp <inlinecomp@devriesen.com>');
    define('PROFIEL_MAIL_ENVELOPE', 'inlinecomp@devriesen.com');   // -f envelope (SPF-alignment)
    define('PROFIEL_MAIL_CC',       'inlinecomp@devriesen.com');   // organisatie krijgt Cc
    define('PROFIEL_LOGIN_URL',     'https://inlineresults.devriesen.com/check/profiel.php');
    define('PROFIEL_PRIVACY_URL',   'https://inlineresults.devriesen.com/privacyverklaring.php');
}

if (!function_exists('profielMail')) {
    /** Verstuurt een profiel-mail via mail() met de app-standaard headers + -f envelope.
     *  Optioneel Cc (bv. de organisatie). Message-ID/Date op devriesen.com i.v.m. spam-score. */
    function profielMail(string $to, string $subject, string $body, ?string $cc = null): bool {
        $msgId = sprintf('<%s.%s@devriesen.com>', date('YmdHis'), bin2hex(random_bytes(8)));
        $h = [
            'From: ' . PROFIEL_MAIL_FROM,
            'Reply-To: ' . PROFIEL_MAIL_ENVELOPE,
        ];
        if ($cc !== null && $cc !== '') $h[] = 'Cc: ' . $cc;
        $h = array_merge($h, [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: ' . $msgId,
            'X-Mailer: InlineComp Profiel',
        ]);
        return @mail($to, $subject, $body, implode("\r\n", $h), '-f' . PROFIEL_MAIL_ENVELOPE);
    }
}

if (!function_exists('profielMailScheiding')) {
    /** Scheidingslijn tussen het NL- en EN-blok. */
    function profielMailScheiding(): string {
        return "\n\n———————————————————————— (English below) ————————————————————————\n\n";
    }
}

if (!function_exists('profielMailInAfwachting')) {
    /** (1) Direct na de aanvraag → naar de rijder (organisatie in Cc).
     *  Bevestigt ontvangst + legt uit dat goedkeuring volgt. */
    function profielMailInAfwachting(string $naam): array {
        $nl = "Hoi $naam,\n\n"
            . "Bedankt voor je aanvraag voor een persoonlijk InlineComp-profiel (\"Mijn InlineComp\"). "
            . "We hebben 'm ontvangen — de organisatie bekijkt 'm en keurt 'm goed. Zodra dat gebeurd is, "
            . "krijg je van ons een e-mail met een link waarmee je zelf een pincode instelt, plus je "
            . "gebruikersnaam.\n\n"
            . "Je hoeft nu even niets te doen. Dit kan een dag duren.\n\n"
            . "Groet,\nInlineComp";
        $en = "Hi $naam,\n\n"
            . "Thanks for requesting a personal InlineComp profile (\"My InlineComp\"). We've received it — "
            . "the organisation will review and approve it. Once that's done you'll get an e-mail from us with "
            . "a link to set your own PIN, plus your username.\n\n"
            . "Nothing to do for now. This may take up to a day.\n\n"
            . "Regards,\nInlineComp";
        return [
            'subject' => 'InlineComp — profiel-aanvraag ontvangen / profile request received',
            'body'    => $nl . profielMailScheiding() . $en,
        ];
    }
}

if (!function_exists('profielMailGoedgekeurd')) {
    /** (2) Goedkeuring → naar de rijder (organisatie in Cc). Bevat de claim-link,
     *  de gebruikersnaam en de vaste inlog-link voor later. */
    function profielMailGoedgekeurd(string $naam, string $username, string $claimUrl): array {
        $login = PROFIEL_LOGIN_URL;
        $nl = "Hoi $naam,\n\n"
            . "Leuk dat je een persoonlijk profiel wilt bij InlineComp! Je aanvraag is goedgekeurd — "
            . "hierbij je link om het in orde te maken.\n\n"
            . "Met \"Mijn InlineComp\" zie je op één plek je eigen resultaten, je persoonlijke records en een "
            . "grafiekje van je vooruitgang per afstand. Het is helemaal privé: alleen jij ziet het, na "
            . "inloggen — het is niet openbaar en niet te vinden via Google.\n\n"
            . "ZO ACTIVEER JE HET (eenmalig)\n"
            . "1. Open deze link (7 dagen geldig):\n   $claimUrl\n"
            . "2. Kies een pincode. Die heb je samen met je gebruikersnaam nodig om in te loggen.\n"
            . "3. Klaar!\n\n"
            . "VOORTAAN INLOGGEN doe je hier:\n   $login\n"
            . "   • Gebruikersnaam: $username\n"
            . "   • je zelfgekozen pincode\n\n"
            . "Tip: sla die inlog-link op als favoriet, dan vind je je profiel altijd snel terug. Bewaar ook "
            . "even je gebruikersnaam — daarmee (plus je pincode) kom je er weer in. Ben je je pincode kwijt? "
            . "Laat het ons weten, dan sturen we een nieuwe activatielink.\n\n"
            . "We bewaren geen e-mailadres bij je profiel; dit adres gebruiken we alleen om je deze link te "
            . "sturen en verwijderen we daarna. Zie de privacyverklaring: " . PROFIEL_PRIVACY_URL . "\n\n"
            . "Sportieve groet,\nInlineComp";
        $en = "Hi $naam,\n\n"
            . "Great that you want a personal InlineComp profile! Your request has been approved — here's your "
            . "link to set it up.\n\n"
            . "With \"My InlineComp\" you see your own results, your personal records and a progress chart per "
            . "distance in one place. It's fully private: only you can see it, after logging in — it's not "
            . "public and not findable via Google.\n\n"
            . "HOW TO ACTIVATE IT (one-off)\n"
            . "1. Open this link (valid for 7 days):\n   $claimUrl\n"
            . "2. Choose a PIN. You need it together with your username to log in.\n"
            . "3. Done!\n\n"
            . "TO LOG IN LATER, go here:\n   $login\n"
            . "   • Username: $username\n"
            . "   • your chosen PIN\n\n"
            . "Tip: save that login link as a favourite so you can always find your profile. Also keep your "
            . "username — with it (plus your PIN) you get back in. Lost your PIN? Let us know and we'll send a "
            . "new activation link.\n\n"
            . "We don't store an e-mail address with your profile; we use this address only to send you this "
            . "link and delete it afterwards. See the privacy statement: " . PROFIEL_PRIVACY_URL . "\n\n"
            . "Regards,\nInlineComp";
        return [
            'subject' => 'InlineComp — je profiel-link (Mijn InlineComp) / your profile link',
            'body'    => $nl . profielMailScheiding() . $en,
        ];
    }
}

if (!function_exists('profielMailAfgewezen')) {
    /** (3) Afwijzing → naar de rijder (organisatie in Cc). Kort en netjes. */
    function profielMailAfgewezen(string $naam): array {
        $nl = "Hoi $naam,\n\n"
            . "Je aanvraag voor een persoonlijk InlineComp-profiel is helaas niet goedgekeurd.\n\n"
            . "Denk je dat dit een vergissing is, of klopt er iets niet? Antwoord dan gerust op deze "
            . "e-mail, dan kijken we er samen naar.\n\n"
            . "Groet,\nInlineComp";
        $en = "Hi $naam,\n\n"
            . "Unfortunately your request for a personal InlineComp profile was not approved.\n\n"
            . "Think this is a mistake, or something's off? Just reply to this e-mail and we'll look into "
            . "it together.\n\n"
            . "Regards,\nInlineComp";
        return [
            'subject' => 'InlineComp — profiel-aanvraag / profile request',
            'body'    => $nl . profielMailScheiding() . $en,
        ];
    }
}
