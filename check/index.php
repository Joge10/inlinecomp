<?php
// ============================================================
//  InlineComp – Publieke self-check voor rijders
//  Geen login. Viertalig (NL/EN/DE/FR via shared i18n.js).
//
//  Filter wedstrijden: alleen public_zichtbaar=1 EN starts >= vandaag.
//  Operator besluit zelf wat zichtbaar is; oude wedstrijden komen niet
//  in de dropdown — daar valt niets meer aan te corrigeren.
//
//  Actions (?action=...):
//    competities                     → publieke aankomende wedstrijden
//    deelnemers?comp=ID              → alfabetische lijst rijders
//    rijder?comp=ID&lic=LICENSE_KEY  → detail (persoon + inschr. + transponder)
//
//  Privacy: alleen velden die ook op startlijsten/uitslagen al publiek zijn.
//  Geen geboortejaar, geen email/adres, geen license_key in UI.
// ============================================================
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../../config_inlinecomp.php';

$action = $_GET['action'] ?? '';

// ── Fuzzy voornaam-check ────────────────────────────────────────────────────
// Privacy-laag: de lijst toont alleen short_name (achternaam + tussenvoegsel).
// Detail-data wordt pas vrijgegeven als de vrager ook de voornaam correct
// opgeeft. Met fuzzy compare zodat Linn/Lynn/Lin/Lyn, José/Jose, Renée/Renee,
// Anne-Marie/Anne allemaal als match tellen.
//
// Strategie (oplopend van streng naar tolerant):
//   1. accent-strip + lowercase + spaties/hyphens weg
//   2. exact match
//   3. substring (Marie ⊂ Marie-Louise)
//   4. Soundex (Linn ≡ Lynn ≡ Lin ≡ Lyn → L500)
//   5. Levenshtein ≤ 2 voor namen ≥ 3 letters (typo-tolerantie)
function _checkVoornaamNormaliseer(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n','ç'=>'c','ß'=>'ss','ý'=>'y','ÿ'=>'y',
    ]);
    return preg_replace('/[\s\-\.\']/u', '', $s);
}
function _fuzzyVoornaamMatch(string $ingevoerd, string $opgeslagen): bool {
    return _voornaamScore($ingevoerd, $opgeslagen) < 99;
}
// Score: 0 = exacte match na normalisatie, hoger = minder zeker, 99 = geen match.
// Gebruikt door rijder-action om bij meerdere kandidaten (broers/zussen met
// zelfde short_name) de BESTE match te kiezen i.p.v. zomaar de eerste.
function _voornaamScore(string $ingevoerd, string $opgeslagen): int {
    $a = _checkVoornaamNormaliseer($ingevoerd);
    $b = _checkVoornaamNormaliseer($opgeslagen);
    if ($a === '' || $b === '') return 99;
    if ($a === $b) return 0;                              // exacte match
    if (strpos($b, $a) !== false) return 1;               // a is prefix/in b
    if (strpos($a, $b) !== false) return 2;               // b is prefix/in a
    if (soundex($a) === soundex($b)) return 3;            // klinkt-zoals
    if (strlen($a) >= 3 && strlen($b) >= 3) {
        $lev = levenshtein($a, $b);
        if ($lev <= 2) return 3 + $lev;                   // typo: 4 of 5
    }
    return 99;
}
function _voornaamUitFullNaam(string $full, string $short): string {
    $f = trim($full);
    $s = trim($short);
    if ($s === '' || $f === '') return $f;
    if (mb_strlen($f) <= mb_strlen($s)) return $f;
    // Verwijder short_name als suffix van full_name → rest = voornaam(en)
    $tail = mb_substr($f, -mb_strlen($s));
    if (mb_strtolower($tail) === mb_strtolower($s)) {
        return trim(mb_substr($f, 0, mb_strlen($f) - mb_strlen($s)));
    }
    return $f;
}

// ── API actions ─────────────────────────────────────────────────────────────
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($action === 'competities') {
            // Twee groepen uit de competitions-statusvelden:
            //   • 'binnenkort' = public_aankondigen=1 AND public_zichtbaar=0
            //                    — operator toont 'm vooraf voor inspectie,
            //                      ongeacht hoe ver weg (geen tijdvenster).
            //   • 'live'       = public_zichtbaar=1 EN starts binnen
            //                    [gisteren, +10 dagen] — wedstrijd staat
            //                    actief, tijdvenster voorkomt dat oude
            //                    wedstrijden waar zichtbaar=1 nog steeds in
            //                    de check-dropdown blijven hangen.
            // EXISTS-clause: lege wedstrijden zonder entries hebben niks te
            // checken, dus weg.
            $stmt = $pdo->prepare("
                SELECT
                    c.id, c.name, c.starts,
                    c.organisatie_id, c.baan_id,
                    o.logo_path AS org_logo, o.naam AS org_naam,
                    COALESCE(b.logo_path, (
                        SELECT b2.logo_path FROM banen b2
                        WHERE b2.naam = b.naam AND b2.id != b.id
                          AND b2.logo_path IS NOT NULL AND b2.logo_path != ''
                        LIMIT 1
                    )) AS baan_logo,
                    COALESCE(b.vereniging_naam, (
                        SELECT b2.vereniging_naam FROM banen b2
                        WHERE b2.naam = b.naam AND b2.id != b.id
                          AND b2.vereniging_naam IS NOT NULL AND b2.vereniging_naam != ''
                        LIMIT 1
                    )) AS baan_vereniging,
                    CASE
                        WHEN c.public_zichtbaar = 1 THEN 'live'
                        ELSE 'binnenkort'
                    END AS status
                FROM competitions c
                LEFT JOIN organisaties o ON o.id = c.organisatie_id
                LEFT JOIN banen b ON b.id = c.baan_id
                WHERE EXISTS (
                        SELECT 1 FROM entries e
                        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                        WHERE dc.competition_id = c.id
                      )
                  AND (
                        (c.public_aankondigen = 1 AND c.public_zichtbaar = 0)
                        OR (c.public_zichtbaar = 1
                            AND c.starts >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            AND c.starts <= DATE_ADD(CURDATE(), INTERVAL 10 DAY))
                      )
                ORDER BY c.starts ASC, c.name
            ");
            $stmt->execute();
            $comps = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Sponsors per organisatie + per baan ophalen (zelfde pad als
            // /public — operator kan org-sponsors EN baan-sponsors instellen,
            // die mergen tot één lichtkrant onderaan de pagina).
            $orgIds = array_unique(array_filter(array_column($comps, 'organisatie_id')));
            $baanIds = array_unique(array_filter(array_column($comps, 'baan_id')));
            $sponsorMap = [];
            $baanSponsorMap = [];
            if ($orgIds) {
                $ph = implode(',', array_fill(0, count($orgIds), '?'));
                $spStmt = $pdo->prepare("
                    SELECT organisatie_id, naam, logo_path, url
                    FROM organisatie_sponsors
                    WHERE organisatie_id IN ($ph)
                      AND logo_path IS NOT NULL AND logo_path != ''
                    ORDER BY volgorde, naam
                ");
                $spStmt->execute(array_values($orgIds));
                foreach ($spStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                    $sponsorMap[$sp['organisatie_id']][] = [
                        'naam' => $sp['naam'],
                        'logo' => $sp['logo_path'],
                        'url'  => $sp['url'],
                    ];
                }
            }
            if ($baanIds) {
                $ph = implode(',', array_fill(0, count($baanIds), '?'));
                $bsStmt = $pdo->prepare("
                    SELECT baan_id, naam, logo_path, url
                    FROM baan_sponsors
                    WHERE baan_id IN ($ph)
                      AND logo_path IS NOT NULL AND logo_path != ''
                    ORDER BY volgorde, naam
                ");
                $bsStmt->execute(array_values($baanIds));
                foreach ($bsStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                    $baanSponsorMap[$sp['baan_id']][] = [
                        'naam' => $sp['naam'],
                        'logo' => $sp['logo_path'],
                        'url'  => $sp['url'],
                    ];
                }
            }
            foreach ($comps as &$c) {
                $org  = $sponsorMap[$c['organisatie_id']      ?? ''] ?? [];
                $baan = $baanSponsorMap[$c['baan_id']         ?? ''] ?? [];
                $c['sponsors'] = array_merge($org, $baan);
            }
            unset($c);
            echo json_encode($comps, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'deelnemers') {
            $compId = trim($_GET['comp'] ?? '');
            if (!$compId) {
                http_response_code(400);
                echo json_encode(['error' => 'comp verplicht']); exit;
            }
            // Gate: comp moet public-zichtbaar zijn (anti-URL-pluk)
            // Gate: zelfde voorwaarden als de competities-dropdown — alleen
            // wedstrijden die ofwel 'binnenkort' (public_aankondigen=1) zijn
            // ofwel 'live' (public_zichtbaar=1 binnen [-1, +10] dagen) mogen
            // worden gecheckt. Anti-URL-pluk van interne wedstrijden.
            $z = $pdo->prepare("
                SELECT 1 FROM competitions c
                WHERE c.id = ?
                  AND EXISTS (
                        SELECT 1 FROM entries e
                        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                        WHERE dc.competition_id = c.id
                      )
                  AND (
                        (c.public_aankondigen = 1 AND c.public_zichtbaar = 0)
                        OR (c.public_zichtbaar = 1
                            AND c.starts >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            AND c.starts <= DATE_ADD(CURDATE(), INTERVAL 10 DAY))
                      )
                LIMIT 1
            ");
            $z->execute([$compId]);
            if (!$z->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['error' => 'Wedstrijd niet beschikbaar']); exit;
            }
            // Unieke personen (over alle DCs) alfabetisch op short_name
            // (= "tussenvoegsel + achternaam" → "de Vries" sorteert onder D).
            // GROUP BY short_name — broers/zussen of toevallig dezelfde
            // achternaam verschijnen als één rij. Voornaam wordt later (bij
            // detail) gebruikt om de juiste persoon binnen de groep te vinden.
            // Geen cat/club hier: die kunnen verschillen binnen een groep en
            // zouden verraden hoeveel familieleden meedoen.
            $stmt = $pdo->prepare("
                SELECT COALESCE(NULLIF(p.short_name, ''), p.full_name) AS short_name
                FROM entries e
                JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                JOIN persons p ON p.license_key = e.person_license
                WHERE dc.competition_id = ?
                  AND p.anonymized_at IS NULL
                GROUP BY short_name
                ORDER BY short_name
            ");
            $stmt->execute([$compId]);
            $uit = array_map(fn($r) => ['short_name' => $r['short_name']],
                             $stmt->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode($uit, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'rijder') {
            $compId    = trim($_GET['comp']     ?? '');
            $shortIn   = trim($_GET['short']    ?? '');
            $voornaamIn= trim($_GET['voornaam'] ?? '');
            if (!$compId || !$shortIn) {
                http_response_code(400);
                echo json_encode(['error' => 'comp en short verplicht']); exit;
            }
            // Gate: zelfde voorwaarden als de competities-dropdown — alleen
            // wedstrijden die ofwel 'binnenkort' (public_aankondigen=1) zijn
            // ofwel 'live' (public_zichtbaar=1 binnen [-1, +10] dagen) mogen
            // worden gecheckt. Anti-URL-pluk van interne wedstrijden.
            $z = $pdo->prepare("
                SELECT 1 FROM competitions c
                WHERE c.id = ?
                  AND EXISTS (
                        SELECT 1 FROM entries e
                        JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                        WHERE dc.competition_id = c.id
                      )
                  AND (
                        (c.public_aankondigen = 1 AND c.public_zichtbaar = 0)
                        OR (c.public_zichtbaar = 1
                            AND c.starts >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            AND c.starts <= DATE_ADD(CURDATE(), INTERVAL 10 DAY))
                      )
                LIMIT 1
            ");
            $z->execute([$compId]);
            if (!$z->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['error' => 'Wedstrijd niet beschikbaar']); exit;
            }
            if ($voornaamIn === '') {
                http_response_code(401);
                echo json_encode(['error' => 'voornaam_vereist']); exit;
            }
            // Pak alle ingeschreven personen met dezelfde short_name —
            // broers/zussen of toevallig dezelfde achternaam zitten samen.
            // De juiste persoon kiezen we via beste-fuzzy-match op voornaam.
            $kStmt = $pdo->prepare("
                SELECT DISTINCT p.license_key, p.full_name, p.short_name, p.gender,
                                p.category, p.start_number, p.club_short, p.club_full,
                                p.nationality, p.sponsor
                FROM entries e
                JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                JOIN persons p ON p.license_key = e.person_license
                WHERE dc.competition_id = ?
                  AND COALESCE(NULLIF(p.short_name, ''), p.full_name) = ?
                  AND p.anonymized_at IS NULL
            ");
            $kStmt->execute([$compId, $shortIn]);
            $kandidaten = $kStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($kandidaten)) {
                http_response_code(404);
                echo json_encode(['error' => 'Niet gevonden']); exit;
            }
            // Beste match op voornaam (laagste score = exactste). Bij gelijke
            // score (twee zussen Anne/Anneke met dezelfde Soundex) is dat
            // toch onhandig — return ambigu-error zodat rijder voller intypt.
            $besteIdx = -1;
            $besteScore = 99;
            $aantalMetBesteScore = 0;
            foreach ($kandidaten as $i => $k) {
                $vn = _voornaamUitFullNaam($k['full_name'], $k['short_name'] ?? '');
                $sc = _voornaamScore($voornaamIn, $vn);
                if ($sc < $besteScore) {
                    $besteScore = $sc;
                    $besteIdx = $i;
                    $aantalMetBesteScore = 1;
                } elseif ($sc === $besteScore) {
                    $aantalMetBesteScore++;
                }
            }
            if ($besteScore >= 99) {
                http_response_code(403);
                echo json_encode(['error' => 'voornaam_mismatch']); exit;
            }
            if ($aantalMetBesteScore > 1 && $besteScore > 0) {
                // Meerdere even-goede non-exacte matches → vraag specifieker
                http_response_code(409);
                echo json_encode(['error' => 'voornaam_ambigu']); exit;
            }
            $persoon = $kandidaten[$besteIdx];
            $lic = $persoon['license_key'];
            $cStmt = $pdo->prepare("SELECT name, starts FROM competitions WHERE id = ?");
            $cStmt->execute([$compId]);
            $comp = $cStmt->fetch(PDO::FETCH_ASSOC);
            $eStmt = $pdo->prepare("
                SELECT dc.name AS dc_naam, e.status
                FROM entries e
                JOIN distance_combinations dc ON dc.id = e.distance_combination_id
                WHERE e.person_license = ? AND dc.competition_id = ?
                ORDER BY dc.name
            ");
            $eStmt->execute([$lic, $compId]);
            $entries = $eStmt->fetchAll(PDO::FETCH_ASSOC);
            $tStmt = $pdo->prepare("
                SELECT slot, code, source FROM transponders
                WHERE person_license = ? AND competition_id = ?
                ORDER BY slot
            ");
            $tStmt->execute([$lic, $compId]);
            $transponders = $tStmt->fetchAll(PDO::FETCH_ASSOC);

            $gTxt = ($persoon['gender'] == 0 || $persoon['gender'] === '0') ? 'M'
                  : (($persoon['gender'] == 1 || $persoon['gender'] === '1') ? 'V' : '?');
            echo json_encode([
                'persoon' => [
                    'full_name'    => $persoon['full_name'],
                    'gender'       => $gTxt,
                    'category'     => $persoon['category'],
                    'start_number' => $persoon['start_number'] !== null ? (int)$persoon['start_number'] : null,
                    'club'         => $persoon['club_short'] ?: $persoon['club_full'] ?: '',
                    'nationality'  => $persoon['nationality'],
                    'sponsor'      => $persoon['sponsor'],
                ],
                'wedstrijd'      => $comp ?: null,
                'inschrijvingen' => $entries,
                'transponders'   => $transponders,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'submit') {
            // Form-submit voor beide meld-flows (foute gegevens / niet
            // gevonden). Stuurt mail via PHP mail() — geen client-side
            // mailto meer, want die opent het mail-programma en faalt
            // vaak op mobiel (geen mail-app geconfigureerd, etc.).
            // Aangepaste velden worden gemarkeerd in de mail-body.
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $type        = $body['type']            ?? '';
            $wedstrijd   = trim($body['wedstrijd']  ?? '');
            $compId      = trim($body['comp_id']    ?? '');
            $email       = trim($body['email']      ?? '');
            $opmerking   = trim($body['opmerking']  ?? '');
            $velden      = $body['velden']          ?? [];

            if (!in_array($type, ['fout', 'onbekend'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_type']); exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_email']); exit;
            }
            if ($wedstrijd === '') {
                http_response_code(400);
                echo json_encode(['error' => 'wedstrijd_verplicht']); exit;
            }
            if (!is_array($velden)) $velden = [];

            // NL-labels per veld-key. De client stuurt het label in de taal
            // van de rijder mee (Engels/Duits/Frans), maar de operator-mail
            // moet altijd Nederlandstalig zijn. Mapt op `key`; valt terug op
            // het meegestuurde label als de key onbekend is.
            $nlLabels = [
                'naam'        => 'Volledige naam',
                'gender'      => 'Gender (M/V)',
                'cat'         => 'Categorie',
                'snr'         => 'Startnummer',
                'club'        => 'Club',
                'sponsor'     => 'Sponsor / team',
                'transponder' => 'Transponder-nummer',
                'gebjaar'     => 'Geboortejaar',
                'knsb'        => 'KNSB-nummer',
            ];

            // Mail-body opbouwen — plain text. Aangepaste velden krijgen
            // duidelijke ← AANGEPAST-tag zodat operator in één blik ziet
            // wat de rijder heeft gewijzigd t.o.v. wat al in InlineComp stond.
            $r = [];
            $r[] = 'InlineComp – melding via /check';
            $r[] = str_repeat('─', 50);
            $r[] = 'Type:       ' . ($type === 'fout'
                                    ? 'Foutieve gegevens in InlineComp'
                                    : 'Rijder niet gevonden in deelnemerslijst');
            $r[] = 'Wedstrijd:  ' . $wedstrijd;
            $r[] = 'Afzender:   ' . $email;
            $r[] = 'Verstuurd:  ' . date('Y-m-d H:i:s');
            $r[] = '';
            $r[] = 'Gegevens:';
            foreach ($velden as $v) {
                if (!is_array($v)) continue;
                $key   = trim((string)($v['key']   ?? ''));
                $lbl   = $nlLabels[$key] ?? trim((string)($v['label'] ?? ''));
                $oud   = trim((string)($v['oud']   ?? ''));
                $nieuw = trim((string)($v['nieuw'] ?? ''));
                if ($lbl === '') continue;
                if ($nieuw === '' && $oud === '') continue;
                $lblPad = str_pad($lbl . ':', 18);
                if ($nieuw !== '' && $oud !== '' && $nieuw !== $oud) {
                    $r[] = '  ' . $lblPad . $nieuw
                         . '   ← AANGEPAST (was: ' . $oud . ')';
                } elseif ($nieuw !== '' && $oud === '') {
                    // Voor 'onbekend' flow: alles is nieuw, geen "was" tag
                    $r[] = '  ' . $lblPad . $nieuw
                         . ($type === 'onbekend' ? '' : '   ← AANGEPAST (was: leeg)');
                } else {
                    $r[] = '  ' . $lblPad . ($nieuw !== '' ? $nieuw : $oud);
                }
            }
            if ($opmerking !== '') {
                $r[] = '';
                $r[] = 'Opmerking:';
                foreach (explode("\n", $opmerking) as $regel) {
                    $r[] = '  ' . $regel;
                }
            }
            $r[] = '';
            $r[] = str_repeat('─', 50);
            $r[] = 'Beantwoord deze mail om met de afzender te corresponderen.';
            $bodyTxt = implode("\n", $r);

            $subject = ($type === 'fout' ? '[InlineComp] Foutieve gegevens' : '[InlineComp] Niet gevonden')
                     . ' — ' . $wedstrijd;
            $to = 'inlinecomp@devriesen.com';

            // Cc naar wedstrijd-organisatie-email indien bekend. Zo krijgt de
            // organisator de melding direct mee zonder dat InlineComp moet
            // doorsturen. Lookup via competitions → organisaties.email.
            $ccEmail = null;
            if ($compId !== '') {
                try {
                    $ccStmt = $pdo->prepare("
                        SELECT o.email FROM competitions c
                        LEFT JOIN organisaties o ON o.id = c.organisatie_id
                        WHERE c.id = ?
                    ");
                    $ccStmt->execute([$compId]);
                    $cand = trim((string)$ccStmt->fetchColumn());
                    if ($cand !== '' && filter_var($cand, FILTER_VALIDATE_EMAIL)) {
                        $ccEmail = $cand;
                    }
                } catch (Throwable $e) { /* CC-lookup mag de mail niet blokkeren */ }
            }

            // From moet match'en met server-domein om SPF/DMARC te voldoen
            // bij byethost. Reply-To zet de daadwerkelijke afzender — een
            // antwoord komt zo direct bij de rijder terecht.
            //
            // BELANGRIJK over Cc bij PHP mail(): de `Cc:` header alléén
            // garandeert GEEN aflevering. PHP gebruikt alleen $to als
            // envelope-recipient (RCPT TO). Het Cc-adres moet daarom ÓÓK in
            // $to (komma-gescheiden) — dan ontvangen beide écht een kopie,
            // en de Cc-header zorgt dat de ontvanger visueel ziet wie er
            // mee in CC stond.
            $hdrLijst = [
                'From: InlineComp Check <inlinecomp@devriesen.com>',
                'Reply-To: ' . $email,
            ];
            $rcpts = $to;
            if ($ccEmail) {
                $hdrLijst[] = 'Cc: ' . $ccEmail;
                $rcpts     .= ', ' . $ccEmail;
            }
            $hdrLijst[] = 'Content-Type: text/plain; charset=utf-8';
            $hdrLijst[] = 'X-Mailer: InlineComp Check';
            $headers = implode("\r\n", $hdrLijst);
            $ok = @mail($rcpts, $subject, $bodyTxt, $headers);
            if (!$ok) {
                http_response_code(500);
                echo json_encode(['error' => 'mail_send_failed']); exit;
            }
            echo json_encode([
                'ok'      => true,
                'cc_sent' => $ccEmail !== null,
            ]);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Onbekende actie']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Serverfout']);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title data-i18n="page_title">InlineComp – Check</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<style>
:root {
    --blauw: #1F4E79;
    --middenblauw: #2E75B6;
    --lichtblauw: #D6E4F0;
    --oranje: #E8630A;
    --wit: #fff;
    --tekst: #1a1a1a;
    --grijs: #f4f6f8;
}
/* Status-badges: identiek aan /coach (coach/index.php:1791-1797) voor
   consistente terminologie + visuele taal tussen publieke check-pagina
   en coach-portal. */
.status-badge { font-size:.75rem; padding:2px 8px; border-radius:10px; font-weight:600; }
.status-0 { background:#fff3e0; color:#e65100; }
.status-1 { background:#e8f5e9; color:#2e7d32; }
.status-2 { background:#fce4e4; color:#b71c1c; }
.status-3 { background:#f3e5f5; color:#6a1b9a; }
.status-4 { background:#ffcdd2; color:#b71c1c; border:2px solid #b71c1c; }
.status-5 { background:#e0f7fa; color:#006064; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 20px; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 1rem; color: var(--tekst); background: var(--grijs);
    min-height: 100vh;
}
header {
    background: var(--blauw); color: var(--wit);
    padding: 12px 12px 10px;
    display: flex; flex-direction: column;
}
.hdr-row-top { display: flex; align-items: center; gap: 8px; }
.hdr-btns { display: flex; gap: 6px; flex-shrink: 0; align-items: center; }
.hdr-btns-right { justify-content: flex-end; }
.hdr-center { flex: 1; min-width: 0; text-align: center; }
header h1 { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
.sub { font-size: .95rem; opacity: .8; margin-top: 6px; text-align: center; }
.btn-help {
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(255,255,255,.15); color: var(--wit);
    border: 1px solid rgba(255,255,255,.25);
    font-size: 1rem; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.btn-help:hover { background: rgba(255,255,255,.25); }
.btn-lang .i18n-flag { font-size: 1.2rem; line-height: 1; }
.i18n-dropdown-panel {
    position: absolute; background: var(--wit); border: 1px solid #ccc;
    border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,.15);
    padding: 4px; display: flex; gap: 4px; z-index: 1000;
}
.i18n-dropdown-opt {
    background: none; border: none; cursor: pointer; padding: 6px 10px;
    border-radius: 4px; font-size: 1.2rem; line-height: 1;
}
.i18n-dropdown-opt:hover { background: #f0f6ff; }

@media (max-width: 480px) {
    header { padding: 10px 8px 8px; }
    header h1 { font-size: 1.2rem; }
    .sub { font-size: .78rem; margin-top: 4px; }
    .btn-help { width: 30px; height: 30px; }
}

.container { max-width: 720px; margin: 0 auto; padding: 14px; }
.kaart {
    background: var(--wit); border: 1px solid #dde3ea;
    border-radius: 10px; padding: 14px; margin-bottom: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
label.veld-lbl {
    display: block; font-size: .82rem; color: #555;
    margin-bottom: 4px; font-weight: 600;
}
select, input[type=text] {
    font: inherit; padding: 9px 10px; width: 100%;
    border: 1px solid #c0c8d0; border-radius: 6px; background: var(--wit);
}
select:focus, input:focus { outline: 2px solid var(--middenblauw); outline-offset: -1px; }
.veld-rij { margin-bottom: 10px; }
.deelnemers {
    max-height: 60vh; overflow-y: auto; -webkit-overflow-scrolling: touch;
}
.dl-rij {
    padding: 10px 12px; border-bottom: 1px solid #eef1f4;
    cursor: pointer; transition: background .1s;
}
.dl-rij:hover, .dl-rij:focus { background: var(--lichtblauw); outline: none; }
.dl-rij b { color: var(--tekst); }
.dl-rij .meta { color: #666; font-size: .85em; }
.terug {
    background: none; border: none; color: var(--blauw);
    cursor: pointer; font: inherit; padding: 6px 0;
    margin-bottom: 6px; font-size: .95rem;
}
.terug:hover { text-decoration: underline; }
.detail-rij {
    display: flex; padding: 8px 0; border-bottom: 1px solid #eef1f4;
    font-size: .98em;
}
.detail-rij:last-child { border-bottom: none; }
.detail-rij .lbl { width: 150px; color: #555; font-weight: 600; flex-shrink: 0; }
.detail-rij .val { flex: 1; color: var(--tekst); }
.mail-blok {
    margin-top: 14px; padding: 12px;
    background: #fff4e6; border: 1px solid #ffd9a3;
    border-radius: 8px; font-size: .95rem;
}
.mail-blok a {
    color: var(--oranje); font-weight: 600;
    word-break: break-all;
}
.status { color: #666; font-size: .9rem; padding: 8px 0; text-align: center; }
.leeg { color: #888; font-style: italic; padding: 16px; text-align: center; }
.fout { color: #b71c1c; padding: 8px 0; }
@media (max-width: 520px) {
    .detail-rij { flex-direction: column; padding: 6px 0; }
    .detail-rij .lbl { width: auto; margin-bottom: 2px; font-size: .82rem; }
}

/* Org-footer + sponsor-marquee — gekopieerd van /public voor consistentie */
.org-footer {
    display: none; /* verborgen tot wedstrijd geselecteerd */
    background: var(--wit); border-top: 1px solid #dde3ea;
    padding: 12px 16px;
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 100;
    box-shadow: 0 -2px 8px rgba(0,0,0,.08);
}
.org-footer-inner {
    max-width: 720px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.org-footer-logo { height: 50px; width: auto; object-fit: contain; flex-shrink: 0; }
.org-footer-naam { font-size: .85rem; color: var(--blauw); font-weight: 600; flex-shrink: 0; }
.org-footer-sponsors {
    flex: 1; overflow: hidden; display: flex; align-items: center; justify-content: flex-end;
}
.sponsor-marquee {
    display: flex; overflow: hidden; height: 50px; align-items: center;
}
.sponsor-marquee-inner {
    display: flex; align-items: center; gap: 40px; flex-shrink: 0;
    animation: marquee linear infinite;
}
.sponsor-marquee-inner img {
    height: 40px; width: auto; object-fit: contain; flex-shrink: 0;
}
@keyframes marquee {
    0%   { transform: translateX(0); }
    100% { transform: translateX(calc(-50% - 20px)); }
}
/* Onderaan ruimte voor de footer zodat detail-paneel content niet onder de
   fixed footer schuift. 90px = 12+50+12 padding/logo + marge. */
body.met-footer { padding-bottom: 90px; }

/* Info/Help-overlay — gekopieerd van /public voor consistentie */
.help-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    z-index: 2000; display: flex; align-items: flex-start; justify-content: center;
    padding: 24px 12px; overflow-y: auto;
}
.help-box {
    background: var(--wit); border-radius: 14px; width: 100%; max-width: 520px;
    box-shadow: 0 12px 40px rgba(0,0,0,.3); overflow: hidden;
}
.help-header {
    background: var(--blauw); color: var(--wit); padding: 14px 16px;
    display: flex; justify-content: space-between; align-items: center;
    font-size: 1.1rem; font-weight: 700;
}
.help-sluit {
    background: none; border: none; color: rgba(255,255,255,.7);
    font-size: 1.5rem; cursor: pointer; line-height: 1;
}
.help-body { padding: 16px; font-size: .9rem; line-height: 1.5; color: var(--tekst); }
.help-body h3 { font-size: .95rem; color: var(--blauw); margin: 16px 0 6px; }
.help-body h3:first-child { margin-top: 0; }
.help-body p { margin: 4px 0 8px; }
.help-body ul { margin: 4px 0 8px 20px; padding: 0; }
.help-body li { margin-bottom: 4px; }
.help-body .help-stap { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; }
.help-body .help-stap-nr {
    background: var(--oranje); color: #fff; min-width: 24px; height: 24px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; flex-shrink: 0;
}
</style>
</head>
<body>

<header>
    <div class="hdr-row-top">
        <div class="hdr-btns">
            <button class="btn-help btn-lang" id="btn-lang" title="Language / Taal" aria-label="Switch language"></button>
        </div>
        <div class="hdr-center">
            <h1 data-i18n="page_h1">InlineComp – Check</h1>
        </div>
        <div class="hdr-btns hdr-btns-right">
            <button class="btn-help" onclick="toonInfo()" data-i18n-title="hdr_info_title" title="Over InlineComp">i</button>
            <button class="btn-help" onclick="toonHelp()" data-i18n-title="hdr_help_title" title="Hoe werkt het?">?</button>
        </div>
    </div>
    <div class="sub" data-i18n="hdr_sub">Controleer hoe je in InlineComp staat geregistreerd</div>
</header>

<div class="container">
    <div class="kaart">
        <p style="font-size:.92rem;color:#555;margin-bottom:10px" data-i18n="intro">
            Selecteer de wedstrijd, zoek je naam in de lijst en kijk of categorie,
            startnummer en transponder kloppen. Onjuist? Mail het ons via de link onderaan.
        </p>
        <div class="veld-rij">
            <label class="veld-lbl" for="comp-sel" data-i18n="lbl_kies_wedstrijd">1. Wedstrijd</label>
            <select id="comp-sel">
                <option value="" data-i18n="opt_laden">Laden…</option>
            </select>
        </div>
        <div class="veld-rij" id="zoek-rij" style="display:none">
            <label class="veld-lbl" for="zoek" data-i18n="lbl_zoek">2. Zoek je naam</label>
            <input type="text" id="zoek" autocomplete="off" inputmode="search">
        </div>
    </div>

    <div id="lijst-wrap"></div>
    <div id="detail-wrap"></div>
</div>

<div id="org-footer" class="org-footer">
    <div class="org-footer-inner">
        <span id="footer-org-logo"></span>
        <span id="footer-org-naam" class="org-footer-naam"></span>
        <div id="footer-sponsors" class="org-footer-sponsors"></div>
        <span id="footer-baan-logo"></span>
    </div>
</div>

<script>
// Shared i18n-helpers via PHP include
<?php
$i18nPath = __DIR__ . '/../js/i18n.js';
if (is_readable($i18nPath)) {
    readfile($i18nPath);
} else {
    echo "console.error('i18n.js niet gevonden — upload js/i18n.js naar de server');\n";
    echo "alert('Taal-systeem niet geladen — i18n.js ontbreekt op de server.');\n";
}
?>

// ── Translations (NL / EN / DE / FR) ─────────────────────────────────────
const T = {
    nl: {
        page_title: 'InlineComp – Check',
        page_h1: 'InlineComp – Check',
        hdr_sub: 'Controleer hoe je in InlineComp staat geregistreerd',
        hdr_info_title: 'Over deze pagina',
        hdr_help_title: 'Hoe werkt het?',
        info_titel: 'Over /check',
        info_h1: 'Wat is dit?',
        info_p1: 'Deze pagina laat zien hoe je geregistreerd staat in InlineComp voor een aankomende wedstrijd: categorie, startnummer, club en transponder.',
        info_p2_html: 'Klopt iets niet — typo in je naam, verkeerde categorie, ander startnummer? Klik in het detailscherm op <b>"Meld foutieve gegevens"</b> en pas de velden aan. Of als je je niet in de lijst vindt: <b>"Ik vind mezelf niet in de lijst"</b>. Beide formulieren komen direct binnen bij de wedstrijdorganisatie.',
        info_h2: 'Privacy',
        info_p3_html: 'In de lijst staan alleen achternamen — geen voornamen, startnummers of categorieën. Pas wanneer je op een naam klikt en je voornaam invult krijg je de details te zien. Anonieme bezoekers kunnen dus niet zomaar de hele deelnemerslijst doorbladeren.',
        info_p4: 'Spelling van je voornaam hoeft niet exact — gangbare schrijfvarianten en kleine typo\'s worden herkend.',
        info_h_data: 'Welke gegevens?',
        info_p_data: 'Deze pagina toont wedstrijdgegevens die door de KNSB of andere wedstrijdorganisaties aan ons worden geleverd (o.a. namen, startnummers, vereniging). In de privacyverklaring lees je welke gegevens wij verwerken, op welke grondslag en hoe je een verwijderverzoek kunt indienen.',
        info_btn_privacy: '📄 Bekijk privacyverklaring',
        info_h3_html: 'Contact &amp; feedback',
        info_p5: 'Vragen of bugs?',
        info_p6: 'Geen mail-app op je telefoon? De formulieren versturen rechtstreeks via de pagina, geen mail-programma vereist.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        help_titel: 'Hoe werkt het?',
        help_h1: 'In 3 stappen',
        help_stap1_html: 'Kies je <b>wedstrijd</b> uit de dropdown. Wedstrijden met label "🔴 live" of "📅 binnenkort" verschijnen — wedstrijden van organisaties die niet met InlineComp werken zie je niet.',
        help_stap2_html: 'Zoek je <b>achternaam</b> in de alfabetische lijst (gesorteerd op tussenvoegsel + achternaam, bv. "de Vries" onder D).',
        help_stap3_html: 'Klik op je naam → vul je <b>voornaam</b> in als privacy-check → bekijk je gegevens. Klopt iets niet? Gebruik de meld-knop onderaan.',
        help_h_meld: 'Wat als iets niet klopt?',
        help_p_meld_html: 'In het detailscherm staat een oranje knop <b>"Meld foutieve gegevens"</b>. Pas de velden aan die fout staan, vul je e-mail in en klik <b>Verzenden</b>. Wij zien direct in de mail wat je gewijzigd hebt (met "AANGEPAST"-markering) en corrigeren in InlineComp.',
        help_h_onbekend: 'Sta je niet in de lijst?',
        help_p_onbekend_html: 'Klik op <b>"❓ Ik vind mezelf niet in de lijst"</b> onder de namen-lijst. Vul je gegevens in (naam, gender, geboortejaar, club, KNSB-nummer indien bekend, categorie) en wij kijken of je in het systeem staat.',
        intro: 'Selecteer de wedstrijd, zoek je naam in de lijst en kijk of categorie, startnummer en transponder kloppen. Onjuist? Mail het ons via de link onderaan.',
        lbl_kies_wedstrijd: '1. Wedstrijd',
        lbl_zoek: '2. Zoek je naam',
        opt_laden: 'Laden…',
        opt_kies: '— Kies wedstrijd —',
        opt_geen: '— geen aankomende wedstrijden —',
        status_live: 'live',
        status_binnenkort: 'binnenkort',
        zoek_placeholder: 'bv. Vries of Vos…',
        msg_deelnemers_laden: '⏳ Deelnemers laden…',
        msg_geen_deelnemers: 'Geen deelnemers gevonden.',
        msg_geen_match: 'Geen rijder gevonden voor "{q}".',
        msg_detail_laden: '⏳ Laden…',
        terug: '← Terug naar lijst',
        lbl_wedstrijd: 'Wedstrijd',
        lbl_gender: 'Gender',
        lbl_categorie: 'Categorie',
        lbl_startnummer: 'Startnummer',
        lbl_club: 'Club',
        lbl_nationaliteit: 'Nationaliteit',
        lbl_sponsor: 'Sponsor / team',
        lbl_inschrijvingen: 'Inschrijvingen',
        lbl_transponder: 'Transponder',
        geen_inschr: 'geen inschrijvingen',
        // Status-labels: identiek aan /coach voor consistente terminologie.
        status_0: 'Niet bevestigd',
        status_1: 'Bevestigd',
        status_2: 'Afgemeld',
        status_3: 'Afgem. bij org.',
        status_4: 'Niet getekend',
        status_5: 'Bev. bij org.',
        geen_tp: 'geen transponder geregistreerd',
        tp_handmatig: '(handmatig)',
        mail_titel: 'Klopt iets niet?',
        mail_uitleg_form: 'Pas de gegevens aan, vul je e-mail in en klik op verzenden — wij krijgen je melding direct binnen.',
        mail_btn_open: '✏ Meld foutieve gegevens',
        mf_titel_fout: '✏ Foutieve gegevens melden',
        mf_uitleg_fout: 'Pas de velden aan die niet kloppen. Wij zien in de mail wat je hebt gewijzigd.',
        mf_titel_onbekend: '❓ Ik vind mezelf niet',
        mf_uitleg_onbekend: 'Vul je gegevens in zodat wij kunnen kijken of je in het systeem staat.',
        mf_wedstrijd: 'Wedstrijd',
        mf_lbl_naam: 'Volledige naam',
        mf_lbl_gender: 'Gender (M/V)',
        mf_lbl_cat: 'Categorie (DKA, HJA, …)',
        mf_lbl_snr: 'Startnummer',
        mf_lbl_club: 'Club',
        mf_lbl_sponsor: 'Sponsor / team',
        mf_lbl_transponder: 'Transponder-nummer',
        mf_lbl_gebjaar: 'Geboortejaar',
        mf_lbl_knsb: 'KNSB-nummer (indien bekend)',
        mf_lbl_opmerking: 'Opmerking (optioneel)',
        mf_opm_placeholder: 'Aanvullende informatie…',
        mf_lbl_email: 'Jouw e-mail (zodat we kunnen antwoorden)',
        mf_email_placeholder: 'jij@example.com',
        mf_annul: 'Annuleren',
        mf_send: 'Verzenden',
        mf_bezig: '⏳ Bezig…',
        mf_sluit: 'Sluiten',
        mf_succes: '✓ Melding verstuurd! Bedankt — wij nemen contact op via je opgegeven e-mail.',
        mf_succes_cc: '(ook in Cc naar de wedstrijdorganisator)',
        mf_err_email: 'Vul een geldig e-mailadres in.',
        mf_err_leeg: 'Vul minstens één veld in of voeg een opmerking toe.',
        mf_err_send: '⚠ Versturen mislukt. Probeer het later opnieuw.',
        prompt_titel: 'Korte privacy-check',
        prompt_uitleg: 'Vul je voornaam in om de gegevens van "{naam}" te zien. Spelling hoeft niet exact — gangbare varianten en kleine typo\'s worden herkend.',
        prompt_placeholder: 'Voornaam',
        prompt_ok: 'Tonen',
        prompt_annul: 'Annuleren',
        err_voornaam_leeg: 'Vul een voornaam in.',
        err_voornaam_fout: 'Voornaam komt niet overeen. Controleer de spelling of vraag de operator.',
        err_voornaam_ambigu: 'Er zijn meerdere personen met dezelfde achternaam en op-elkaar-lijkende voornamen. Vul je voornaam voller in.',
        btn_niet_gevonden: '❓ Ik vind mezelf niet in de lijst',
        niet_gev_subject: 'Niet gevonden in InlineComp — {wedstrijd}',
        niet_gev_intro: 'Ik vind mezelf niet in de deelnemerslijst van InlineComp voor de wedstrijd "{wedstrijd}". Kunt u kijken of mijn gegevens correct in het systeem staan?',
        niet_gev_velden_titel: 'Mijn gegevens:',
        niet_gev_naam: 'Volledige naam',
        niet_gev_geslacht: 'Geslacht (M/V)',
        niet_gev_geb_jaar: 'Geboortejaar',
        niet_gev_club: 'Club',
        niet_gev_knsb: 'KNSB-nummer (indien bekend)',
        niet_gev_categorie: 'Categorie (DKA, HJA, ...)',
        niet_gev_extra: 'Eventuele opmerkingen:',
    },
    en: {
        page_title: 'InlineComp – Check',
        page_h1: 'InlineComp – Check',
        hdr_sub: 'Verify how you are registered in InlineComp',
        hdr_info_title: 'About this page',
        hdr_help_title: 'How does it work?',
        info_titel: 'About /check',
        info_h1: 'What is this?',
        info_p1: 'This page shows how you are registered in InlineComp for an upcoming race: category, bib number, club and transponder.',
        info_p2_html: 'Something incorrect — typo in your name, wrong category, different bib number? In the detail screen click <b>"Report incorrect data"</b> and adjust the fields. Or if you can\'t find yourself: <b>"I can\'t find myself in the list"</b>. Both forms reach the race organisation directly.',
        info_h2: 'Privacy',
        info_p3_html: 'The list shows only surnames — no first names, bib numbers or categories. Only after clicking a name and entering your first name do you see the details. Anonymous visitors can\'t browse the full participants list.',
        info_p4: 'Spelling of your first name doesn\'t need to be exact — common spelling variations and small typos are recognised.',
        info_h_data: 'Which data?',
        info_p_data: 'This page shows race data provided by the KNSB or other race organisations (incl. names, start numbers, club). The privacy statement details which data we process, on what basis and how to submit a removal request.',
        info_btn_privacy: '📄 View privacy statement',
        info_h3_html: 'Contact &amp; feedback',
        info_p5: 'Questions or bugs?',
        info_p6: 'No mail app on your phone? Forms submit directly through the page, no mail program required.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        help_titel: 'How does it work?',
        help_h1: 'In 3 steps',
        help_stap1_html: 'Choose your <b>race</b> from the dropdown. Races marked "🔴 live" or "📅 upcoming" appear — races from organisations that don\'t use InlineComp aren\'t shown.',
        help_stap2_html: 'Find your <b>surname</b> in the alphabetical list (sorted by infix + surname, e.g. "de Vries" under D).',
        help_stap3_html: 'Click your name → enter your <b>first name</b> as privacy check → view your details. Something incorrect? Use the report button at the bottom.',
        help_h_meld: 'What if something is wrong?',
        help_p_meld_html: 'In the detail screen there\'s an orange <b>"Report incorrect data"</b> button. Adjust the wrong fields, enter your email and click <b>Send</b>. We see directly in the email what you changed (with "CHANGED" marker) and correct it in InlineComp.',
        help_h_onbekend: 'Not in the list?',
        help_p_onbekend_html: 'Click <b>"❓ I can\'t find myself in the list"</b> below the names list. Fill in your details (name, gender, year of birth, club, KNSB number if known, category) and we\'ll check whether you are in the system.',
        intro: 'Select the race, find your name in the list and check whether category, bib number and transponder are correct. Incorrect? Email us using the link below.',
        lbl_kies_wedstrijd: '1. Race',
        lbl_zoek: '2. Find your name',
        opt_laden: 'Loading…',
        opt_kies: '— Choose race —',
        opt_geen: '— no upcoming races —',
        status_live: 'live',
        status_binnenkort: 'upcoming',
        zoek_placeholder: 'e.g. Smith or Vos…',
        msg_deelnemers_laden: '⏳ Loading participants…',
        msg_geen_deelnemers: 'No participants found.',
        msg_geen_match: 'No skater found for "{q}".',
        msg_detail_laden: '⏳ Loading…',
        terug: '← Back to list',
        lbl_wedstrijd: 'Race',
        lbl_gender: 'Gender',
        lbl_categorie: 'Category',
        lbl_startnummer: 'Bib number',
        lbl_club: 'Club',
        lbl_nationaliteit: 'Nationality',
        lbl_sponsor: 'Sponsor / team',
        lbl_inschrijvingen: 'Entries',
        lbl_transponder: 'Transponder',
        geen_inschr: 'no entries',
        status_0: 'Not confirmed',
        status_1: 'Confirmed',
        status_2: 'Withdrawn',
        status_3: 'Withdrawn by org.',
        status_4: 'Not signed in',
        status_5: 'Confirmed by org.',
        geen_tp: 'no transponder registered',
        tp_handmatig: '(manual)',
        mail_titel: 'Something incorrect?',
        mail_uitleg_form: 'Adjust the fields, enter your email and click send — your report reaches us directly.',
        mail_btn_open: '✏ Report incorrect data',
        mf_titel_fout: '✏ Report incorrect data',
        mf_uitleg_fout: 'Adjust the fields that are wrong. The email will show what you changed.',
        mf_titel_onbekend: '❓ I can\'t find myself',
        mf_uitleg_onbekend: 'Enter your details so we can check whether you are in the system.',
        mf_wedstrijd: 'Race',
        mf_lbl_naam: 'Full name',
        mf_lbl_gender: 'Gender (M/F)',
        mf_lbl_cat: 'Category (DKA, HJA, …)',
        mf_lbl_snr: 'Bib number',
        mf_lbl_club: 'Club',
        mf_lbl_sponsor: 'Sponsor / team',
        mf_lbl_transponder: 'Transponder number',
        mf_lbl_gebjaar: 'Year of birth',
        mf_lbl_knsb: 'KNSB number (if known)',
        mf_lbl_opmerking: 'Comment (optional)',
        mf_opm_placeholder: 'Additional information…',
        mf_lbl_email: 'Your email (so we can reply)',
        mf_email_placeholder: 'you@example.com',
        mf_annul: 'Cancel',
        mf_send: 'Send',
        mf_bezig: '⏳ Sending…',
        mf_sluit: 'Close',
        mf_succes: '✓ Report sent! Thanks — we\'ll contact you via your email.',
        mf_succes_cc: '(Cc\'d to the race organiser)',
        mf_err_email: 'Please enter a valid email address.',
        mf_err_leeg: 'Please fill at least one field or add a comment.',
        mf_err_send: '⚠ Sending failed. Please try again later.',
        prompt_titel: 'Quick privacy check',
        prompt_uitleg: 'Enter your first name to view "{naam}"\'s details. Spelling doesn\'t need to match exactly — common variations and small typos are recognised.',
        prompt_placeholder: 'First name',
        prompt_ok: 'Show',
        prompt_annul: 'Cancel',
        err_voornaam_leeg: 'Please enter a first name.',
        err_voornaam_fout: 'First name doesn\'t match. Check the spelling or ask the operator.',
        err_voornaam_ambigu: 'Multiple people share this last name with similar first names. Please type your first name more fully.',
        btn_niet_gevonden: '❓ I can\'t find myself in the list',
        niet_gev_subject: 'Not found in InlineComp — {wedstrijd}',
        niet_gev_intro: 'I can\'t find myself in the participants list of InlineComp for race "{wedstrijd}". Could you check whether my details are correctly in the system?',
        niet_gev_velden_titel: 'My details:',
        niet_gev_naam: 'Full name',
        niet_gev_geslacht: 'Gender (M/F)',
        niet_gev_geb_jaar: 'Year of birth',
        niet_gev_club: 'Club',
        niet_gev_knsb: 'KNSB number (if known)',
        niet_gev_categorie: 'Category (DKA, HJA, ...)',
        niet_gev_extra: 'Any remarks:',
    },
    de: {
        page_title: 'InlineComp – Check',
        page_h1: 'InlineComp – Check',
        hdr_sub: 'Überprüfe, wie du in InlineComp registriert bist',
        hdr_info_title: 'Über diese Seite',
        hdr_help_title: 'Wie funktioniert es?',
        info_titel: 'Über /check',
        info_h1: 'Was ist das?',
        info_p1: 'Diese Seite zeigt, wie du in InlineComp für ein bevorstehendes Rennen registriert bist: Kategorie, Startnummer, Verein und Transponder.',
        info_p2_html: 'Etwas falsch — Tippfehler im Namen, falsche Kategorie, andere Startnummer? Klicke im Detailbildschirm auf <b>"Falsche Daten melden"</b> und passe die Felder an. Oder wenn du dich nicht findest: <b>"Ich finde mich nicht in der Liste"</b>. Beide Formulare erreichen die Rennorganisation direkt.',
        info_h2: 'Datenschutz',
        info_p3_html: 'Die Liste zeigt nur Nachnamen — keine Vornamen, Startnummern oder Kategorien. Erst nach Klick auf einen Namen und Eingabe deines Vornamens siehst du die Details. Anonyme Besucher können die Teilnehmerliste nicht einfach durchblättern.',
        info_p4: 'Schreibweise deines Vornamens muss nicht exakt sein — gängige Varianten und kleine Tippfehler werden erkannt.',
        info_h_data: 'Welche Daten?',
        info_p_data: 'Diese Seite zeigt Wettkampfdaten, die uns vom KNSB oder anderen Wettkampforganisationen geliefert werden (u.a. Namen, Startnummern, Verein). In der Datenschutzerklärung steht welche Daten wir verarbeiten, auf welcher Grundlage und wie du einen Löschantrag einreichen kannst.',
        info_btn_privacy: '📄 Datenschutzerklärung ansehen',
        info_h3_html: 'Kontakt &amp; Feedback',
        info_p5: 'Fragen oder Bugs?',
        info_p6: 'Keine Mail-App auf dem Handy? Formulare werden direkt über die Seite gesendet, kein Mail-Programm erforderlich.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        help_titel: 'Wie funktioniert es?',
        help_h1: 'In 3 Schritten',
        help_stap1_html: 'Wähle dein <b>Rennen</b> aus dem Dropdown. Rennen mit "🔴 live" oder "📅 demnächst" erscheinen — Rennen von Organisationen, die nicht mit InlineComp arbeiten, siehst du nicht.',
        help_stap2_html: 'Finde deinen <b>Nachnamen</b> in der alphabetischen Liste (sortiert nach Präfix + Nachname, z.B. "de Vries" unter D).',
        help_stap3_html: 'Klicke auf deinen Namen → gib deinen <b>Vornamen</b> als Datenschutz-Check ein → sieh deine Daten. Etwas falsch? Nutze den Meld-Button unten.',
        help_h_meld: 'Was wenn etwas falsch ist?',
        help_p_meld_html: 'Im Detailbildschirm gibt es einen orangefarbenen <b>"Falsche Daten melden"</b>-Button. Passe die falschen Felder an, gib deine E-Mail ein und klicke <b>Senden</b>. Wir sehen direkt in der Mail, was du geändert hast (mit "GEÄNDERT"-Markierung) und korrigieren in InlineComp.',
        help_h_onbekend: 'Nicht in der Liste?',
        help_p_onbekend_html: 'Klicke auf <b>"❓ Ich finde mich nicht in der Liste"</b> unter der Namensliste. Trage deine Daten ein (Name, Geschlecht, Geburtsjahr, Verein, KNSB-Nummer falls bekannt, Kategorie) und wir prüfen, ob du im System bist.',
        intro: 'Wähle das Rennen, suche deinen Namen in der Liste und prüfe, ob Kategorie, Startnummer und Transponder stimmen. Falsch? Schick uns eine Mail über den Link unten.',
        lbl_kies_wedstrijd: '1. Rennen',
        lbl_zoek: '2. Finde deinen Namen',
        opt_laden: 'Lädt…',
        opt_kies: '— Rennen wählen —',
        opt_geen: '— keine bevorstehenden Rennen —',
        status_live: 'live',
        status_binnenkort: 'demnächst',
        zoek_placeholder: 'z.B. Schmidt oder Vos…',
        msg_deelnemers_laden: '⏳ Teilnehmer werden geladen…',
        msg_geen_deelnemers: 'Keine Teilnehmer gefunden.',
        msg_geen_match: 'Kein Sportler gefunden für "{q}".',
        msg_detail_laden: '⏳ Lädt…',
        terug: '← Zurück zur Liste',
        lbl_wedstrijd: 'Rennen',
        lbl_gender: 'Geschlecht',
        lbl_categorie: 'Kategorie',
        lbl_startnummer: 'Startnummer',
        lbl_club: 'Verein',
        lbl_nationaliteit: 'Nationalität',
        lbl_sponsor: 'Sponsor / Team',
        lbl_inschrijvingen: 'Anmeldungen',
        lbl_transponder: 'Transponder',
        geen_inschr: 'keine Anmeldungen',
        status_0: 'Nicht bestätigt',
        status_1: 'Bestätigt',
        status_2: 'Zurückgezogen',
        status_3: 'Abgem. bei Org.',
        status_4: 'Nicht angemeldet',
        status_5: 'Best. bei Org.',
        geen_tp: 'kein Transponder registriert',
        tp_handmatig: '(manuell)',
        mail_titel: 'Stimmt etwas nicht?',
        mail_uitleg_form: 'Felder anpassen, E-Mail eingeben, abschicken — deine Meldung erreicht uns direkt.',
        mail_btn_open: '✏ Falsche Daten melden',
        mf_titel_fout: '✏ Falsche Daten melden',
        mf_uitleg_fout: 'Passe die Felder an, die nicht stimmen. In der Mail sehen wir, was du geändert hast.',
        mf_titel_onbekend: '❓ Ich finde mich nicht',
        mf_uitleg_onbekend: 'Gib deine Daten ein, damit wir prüfen können, ob du im System bist.',
        mf_wedstrijd: 'Rennen',
        mf_lbl_naam: 'Vollständiger Name',
        mf_lbl_gender: 'Geschlecht (M/W)',
        mf_lbl_cat: 'Kategorie (DKA, HJA, …)',
        mf_lbl_snr: 'Startnummer',
        mf_lbl_club: 'Verein',
        mf_lbl_sponsor: 'Sponsor / Team',
        mf_lbl_transponder: 'Transpondernummer',
        mf_lbl_gebjaar: 'Geburtsjahr',
        mf_lbl_knsb: 'KNSB-Nummer (falls bekannt)',
        mf_lbl_opmerking: 'Anmerkung (optional)',
        mf_opm_placeholder: 'Zusätzliche Informationen…',
        mf_lbl_email: 'Deine E-Mail (für Antwort)',
        mf_email_placeholder: 'du@example.com',
        mf_annul: 'Abbrechen',
        mf_send: 'Absenden',
        mf_bezig: '⏳ Wird gesendet…',
        mf_sluit: 'Schließen',
        mf_succes: '✓ Meldung gesendet! Danke — wir melden uns per E-Mail.',
        mf_succes_cc: '(auch in Cc an den Veranstalter)',
        mf_err_email: 'Bitte eine gültige E-Mail-Adresse eingeben.',
        mf_err_leeg: 'Bitte mindestens ein Feld ausfüllen oder eine Anmerkung hinzufügen.',
        mf_err_send: '⚠ Senden fehlgeschlagen. Bitte später erneut versuchen.',
        prompt_titel: 'Kurze Datenschutz-Prüfung',
        prompt_uitleg: 'Gib deinen Vornamen ein, um die Daten von "{naam}" zu sehen. Die Schreibweise muss nicht exakt stimmen — gängige Varianten und kleine Tippfehler werden erkannt.',
        prompt_placeholder: 'Vorname',
        prompt_ok: 'Anzeigen',
        prompt_annul: 'Abbrechen',
        err_voornaam_leeg: 'Bitte einen Vornamen eingeben.',
        err_voornaam_fout: 'Vorname stimmt nicht überein. Schreibweise prüfen oder Veranstalter fragen.',
        err_voornaam_ambigu: 'Mehrere Personen haben den gleichen Nachnamen mit ähnlichen Vornamen. Bitte gib deinen Vornamen vollständiger ein.',
        btn_niet_gevonden: '❓ Ich finde mich nicht in der Liste',
        niet_gev_subject: 'Nicht gefunden in InlineComp — {wedstrijd}',
        niet_gev_intro: 'Ich finde mich nicht in der Teilnehmerliste von InlineComp für das Rennen "{wedstrijd}". Können Sie prüfen, ob meine Daten korrekt im System stehen?',
        niet_gev_velden_titel: 'Meine Daten:',
        niet_gev_naam: 'Vollständiger Name',
        niet_gev_geslacht: 'Geschlecht (M/W)',
        niet_gev_geb_jaar: 'Geburtsjahr',
        niet_gev_club: 'Verein',
        niet_gev_knsb: 'KNSB-Nummer (falls bekannt)',
        niet_gev_categorie: 'Kategorie (DKA, HJA, ...)',
        niet_gev_extra: 'Anmerkungen:',
    },
    fr: {
        page_title: 'InlineComp – Check',
        page_h1: 'InlineComp – Check',
        hdr_sub: 'Vérifiez votre inscription dans InlineComp',
        hdr_info_title: 'À propos de cette page',
        hdr_help_title: 'Comment ça marche ?',
        info_titel: 'À propos de /check',
        info_h1: 'Qu\'est-ce que c\'est ?',
        info_p1: 'Cette page montre comment vous êtes enregistré(e) dans InlineComp pour une course à venir : catégorie, dossard, club et transpondeur.',
        info_p2_html: 'Quelque chose est incorrect — faute dans le nom, mauvaise catégorie, dossard différent ? Dans l\'écran de détail cliquez sur <b>"Signaler des données incorrectes"</b> et ajustez les champs. Ou si vous ne vous trouvez pas : <b>"Je ne me trouve pas dans la liste"</b>. Les deux formulaires arrivent directement chez l\'organisation de la course.',
        info_h2: 'Confidentialité',
        info_p3_html: 'La liste ne montre que les noms de famille — pas de prénoms, dossards ou catégories. Ce n\'est qu\'après avoir cliqué sur un nom et entré votre prénom que vous voyez les détails. Les visiteurs anonymes ne peuvent pas parcourir librement la liste des participants.',
        info_p4: 'L\'orthographe de votre prénom n\'a pas besoin d\'être exacte — les variantes d\'orthographe courantes et petites fautes de frappe sont reconnues.',
        info_h_data: 'Quelles données ?',
        info_p_data: 'Cette page affiche des données de course fournies par la KNSB ou d\'autres organisations de course (noms, dossards, club). La déclaration de confidentialité détaille quelles données nous traitons, sur quelle base et comment soumettre une demande de suppression.',
        info_btn_privacy: '📄 Voir la déclaration de confidentialité',
        info_h3_html: 'Contact &amp; retours',
        info_p5: 'Questions ou bugs ?',
        info_p6: 'Pas d\'app mail sur votre téléphone ? Les formulaires s\'envoient directement via la page, aucun programme mail requis.',
        info_copyright: 'InlineComp &copy; {jaar} Geert de Vries',
        help_titel: 'Comment ça marche ?',
        help_h1: 'En 3 étapes',
        help_stap1_html: 'Choisissez votre <b>course</b> dans le menu déroulant. Les courses avec "🔴 en cours" ou "📅 bientôt" apparaissent — les courses d\'organisations qui n\'utilisent pas InlineComp ne sont pas affichées.',
        help_stap2_html: 'Trouvez votre <b>nom de famille</b> dans la liste alphabétique (triée par particule + nom, p.ex. "de Vries" sous D).',
        help_stap3_html: 'Cliquez sur votre nom → entrez votre <b>prénom</b> comme contrôle de confidentialité → voyez vos données. Quelque chose ne va pas ? Utilisez le bouton de signalement en bas.',
        help_h_meld: 'Et si quelque chose est incorrect ?',
        help_p_meld_html: 'Dans l\'écran de détail il y a un bouton orange <b>"Signaler des données incorrectes"</b>. Ajustez les champs incorrects, entrez votre e-mail et cliquez sur <b>Envoyer</b>. Nous voyons directement dans l\'e-mail ce que vous avez changé (avec marqueur "MODIFIÉ") et corrigeons dans InlineComp.',
        help_h_onbekend: 'Pas dans la liste ?',
        help_p_onbekend_html: 'Cliquez sur <b>"❓ Je ne me trouve pas dans la liste"</b> sous la liste des noms. Remplissez vos données (nom, sexe, année de naissance, club, numéro KNSB si connu, catégorie) et nous vérifions si vous êtes dans le système.',
        intro: 'Sélectionnez la course, trouvez votre nom dans la liste et vérifiez si la catégorie, le dossard et le transpondeur sont corrects. Incorrect ? Envoyez-nous un e-mail via le lien ci-dessous.',
        lbl_kies_wedstrijd: '1. Course',
        lbl_zoek: '2. Trouvez votre nom',
        opt_laden: 'Chargement…',
        opt_kies: '— Choisir la course —',
        opt_geen: '— aucune course à venir —',
        status_live: 'en cours',
        status_binnenkort: 'bientôt',
        zoek_placeholder: 'p.ex. Dupont ou Vos…',
        msg_deelnemers_laden: '⏳ Chargement des participants…',
        msg_geen_deelnemers: 'Aucun participant trouvé.',
        msg_geen_match: 'Aucun patineur trouvé pour « {q} ».',
        msg_detail_laden: '⏳ Chargement…',
        terug: '← Retour à la liste',
        lbl_wedstrijd: 'Course',
        lbl_gender: 'Sexe',
        lbl_categorie: 'Catégorie',
        lbl_startnummer: 'Dossard',
        lbl_club: 'Club',
        lbl_nationaliteit: 'Nationalité',
        lbl_sponsor: 'Sponsor / équipe',
        lbl_inschrijvingen: 'Inscriptions',
        lbl_transponder: 'Transpondeur',
        geen_inschr: 'aucune inscription',
        status_0: 'Non confirmé',
        status_1: 'Confirmé',
        status_2: 'Retiré',
        status_3: 'Désinsc. à l\'org.',
        status_4: 'Non enregistré',
        status_5: 'Conf. à l\'org.',
        geen_tp: 'aucun transpondeur enregistré',
        tp_handmatig: '(manuel)',
        mail_titel: 'Une erreur ?',
        mail_uitleg_form: 'Ajustez les champs, entrez votre e-mail et envoyez — votre signalement nous parvient directement.',
        mail_btn_open: '✏ Signaler des données incorrectes',
        mf_titel_fout: '✏ Signaler des données incorrectes',
        mf_uitleg_fout: 'Ajustez les champs incorrects. L\'e-mail montrera ce que vous avez changé.',
        mf_titel_onbekend: '❓ Je ne me trouve pas',
        mf_uitleg_onbekend: 'Entrez vos données pour que nous puissions vérifier si vous êtes dans le système.',
        mf_wedstrijd: 'Course',
        mf_lbl_naam: 'Nom complet',
        mf_lbl_gender: 'Sexe (H/F)',
        mf_lbl_cat: 'Catégorie (DKA, HJA, …)',
        mf_lbl_snr: 'Dossard',
        mf_lbl_club: 'Club',
        mf_lbl_sponsor: 'Sponsor / équipe',
        mf_lbl_transponder: 'Numéro de transpondeur',
        mf_lbl_gebjaar: 'Année de naissance',
        mf_lbl_knsb: 'Numéro KNSB (si connu)',
        mf_lbl_opmerking: 'Remarque (optionnel)',
        mf_opm_placeholder: 'Informations supplémentaires…',
        mf_lbl_email: 'Votre e-mail (pour la réponse)',
        mf_email_placeholder: 'vous@example.com',
        mf_annul: 'Annuler',
        mf_send: 'Envoyer',
        mf_bezig: '⏳ Envoi…',
        mf_sluit: 'Fermer',
        mf_succes: '✓ Signalement envoyé ! Merci — nous vous contacterons par e-mail.',
        mf_succes_cc: '(également en Cc à l\'organisateur de la course)',
        mf_err_email: 'Veuillez saisir une adresse e-mail valide.',
        mf_err_leeg: 'Veuillez remplir au moins un champ ou ajouter une remarque.',
        mf_err_send: '⚠ Échec de l\'envoi. Veuillez réessayer plus tard.',
        prompt_titel: 'Vérification de confidentialité',
        prompt_uitleg: 'Entrez votre prénom pour voir les données de « {naam} ». L\'orthographe n\'a pas besoin d\'être exacte — les variantes courantes et petites fautes de frappe sont reconnues.',
        prompt_placeholder: 'Prénom',
        prompt_ok: 'Afficher',
        prompt_annul: 'Annuler',
        err_voornaam_leeg: 'Veuillez entrer un prénom.',
        err_voornaam_fout: 'Le prénom ne correspond pas. Vérifiez l\'orthographe ou demandez à l\'organisateur.',
        err_voornaam_ambigu: 'Plusieurs personnes ont le même nom de famille avec des prénoms similaires. Veuillez saisir votre prénom plus complètement.',
        btn_niet_gevonden: '❓ Je ne me trouve pas dans la liste',
        niet_gev_subject: 'Non trouvé dans InlineComp — {wedstrijd}',
        niet_gev_intro: 'Je ne me trouve pas dans la liste des participants d\'InlineComp pour la course « {wedstrijd} ». Pouvez-vous vérifier si mes données sont correctes dans le système ?',
        niet_gev_velden_titel: 'Mes données :',
        niet_gev_naam: 'Nom complet',
        niet_gev_geslacht: 'Sexe (H/F)',
        niet_gev_geb_jaar: 'Année de naissance',
        niet_gev_club: 'Club',
        niet_gev_knsb: 'Numéro KNSB (si connu)',
        niet_gev_categorie: 'Catégorie (DKA, HJA, ...)',
        niet_gev_extra: 'Remarques éventuelles :',
    },
};

// ── Helpers ──────────────────────────────────────────────────────────────
function $(id) { return document.getElementById(id); }
function esc(s) {
    return String(s ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}
function datumNl(s) {
    if (!s) return '';
    try { return new Date(String(s).replace(' ', 'T')).toLocaleDateString(getLocale(),
        {weekday:'short', day:'2-digit', month:'short', year:'numeric'}); }
    catch { return s; }
}

let _alleComps = [];
let _deelnemers = [];

// ── Init i18n + content load ─────────────────────────────────────────────
function _renderAll() {
    // Wedstrijd-dropdown opnieuw vullen (datum-format hangt van locale af)
    if (_alleComps.length) vulCompDropdown();
    // Zoek-input placeholder
    $('zoek').placeholder = t('zoek_placeholder');
    // Als detail open is: opnieuw renderen voor nieuwe taal — short_name +
    // voornaam zitten al in dataset, dus hergebruik die i.p.v. opnieuw vragen.
    const sn = $('detail-wrap').dataset.short;
    const voor = $('detail-wrap').dataset.voornaam;
    if (sn && voor) toonDetail(sn, voor);
}
document.addEventListener('DOMContentLoaded', () => {
    initI18n({ dict: T, onChange: _renderAll });
    laadCompetities();
    $('zoek').addEventListener('input', () => rendDeelnemers($('zoek').value));
});

async function laadCompetities() {
    try {
        const res = await fetch('?action=competities');
        const lijst = await res.json();
        if (lijst.error) throw new Error(lijst.error);
        _alleComps = Array.isArray(lijst) ? lijst : [];
        vulCompDropdown();
        $('comp-sel').addEventListener('change', onCompChange);
    } catch (e) {
        $('comp-sel').innerHTML = `<option>⚠ ${esc(e.message)}</option>`;
    }
}

function vulCompDropdown() {
    const sel = $('comp-sel');
    if (!_alleComps.length) {
        sel.innerHTML = `<option value="">${esc(t('opt_geen'))}</option>`;
        return;
    }
    const huidig = sel.value;
    sel.innerHTML = `<option value="">${esc(t('opt_kies'))}</option>`;
    for (const c of _alleComps) {
        const tag = c.status === 'live'
            ? '🔴 ' + t('status_live')
            : '📅 ' + t('status_binnenkort');
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name} — ${datumNl(c.starts)} [${tag}]`;
        if (c.id === huidig) opt.selected = true;
        // Footer-data per optie meedragen — zelfde patroon als /public
        opt.dataset.orgLogo        = c.org_logo        ?? '';
        opt.dataset.orgNaam        = c.org_naam        ?? '';
        opt.dataset.baanLogo       = c.baan_logo       ?? '';
        opt.dataset.baanVereniging = c.baan_vereniging ?? '';
        opt.dataset.sponsors       = JSON.stringify(c.sponsors ?? []);
        sel.appendChild(opt);
    }
}

// ── Org/sponsor-footer (zelfde patroon als /public) ──────────────────────
function updateFooter(opt) {
    const footer  = $('org-footer');
    const logoEl  = $('footer-org-logo');
    const naamEl  = $('footer-org-naam');
    const sponsEl = $('footer-sponsors');
    const baanEl  = $('footer-baan-logo');
    if (!opt?.value) {
        footer.style.display = 'none';
        document.body.classList.remove('met-footer');
        return;
    }
    const orgLogo  = opt.dataset.orgLogo;
    const orgNaam  = opt.dataset.orgNaam ?? '';
    const baanLogo = opt.dataset.baanLogo ?? '';
    const baanVer  = opt.dataset.baanVereniging ?? '';
    let sponsors = [];
    try { sponsors = JSON.parse(opt.dataset.sponsors || '[]'); } catch {}

    if (!orgLogo && !sponsors.length && !baanLogo && !baanVer) {
        footer.style.display = 'none';
        document.body.classList.remove('met-footer');
        return;
    }
    // Cache-buster per uur — vers upload na max 1 uur zichtbaar
    const cb = `?v=${Math.floor(Date.now() / 3600000)}`;

    logoEl.innerHTML = orgLogo
        ? `<img class="org-footer-logo" src="../${esc(orgLogo)}${cb}" alt="">`
        : '';
    naamEl.textContent = orgLogo ? '' : orgNaam;

    if (baanLogo) {
        baanEl.innerHTML = `<img class="org-footer-logo" src="../${esc(baanLogo)}${cb}" alt="">`;
    } else if (baanVer) {
        baanEl.innerHTML = `<span class="org-footer-naam">${esc(baanVer)}</span>`;
    } else {
        baanEl.innerHTML = '';
    }

    if (sponsors.length) {
        let imgs = '';
        for (const s of sponsors) {
            const img = `<img src="../${esc(s.logo)}${cb}" alt="${esc(s.naam)}" title="${esc(s.naam)}">`;
            imgs += s.url
                ? `<a href="${esc(s.url)}" target="_blank" rel="noopener">${img}</a>`
                : img;
        }
        // Verdubbel imgs voor seamless loop. Duur schaalt met aantal sponsors,
        // minimum 8s zodat één logo niet onhandig snel langs schiet.
        const duur = Math.max(8, sponsors.length * 3);
        sponsEl.innerHTML = `<div class="sponsor-marquee"><div class="sponsor-marquee-inner" style="animation-duration:${duur}s">${imgs}${imgs}</div></div>`;
    } else {
        sponsEl.innerHTML = '';
    }
    footer.style.display = 'block';
    document.body.classList.add('met-footer');
}

async function onCompChange() {
    const sel = $('comp-sel');
    const compId = sel.value;
    $('detail-wrap').innerHTML = '';
    delete $('detail-wrap').dataset.lic;
    $('zoek').value = '';
    updateFooter(sel.selectedOptions[0]);
    if (!compId) {
        $('zoek-rij').style.display = 'none';
        $('lijst-wrap').innerHTML = '';
        return;
    }
    $('lijst-wrap').innerHTML = `<div class="status">${esc(t('msg_deelnemers_laden'))}</div>`;
    try {
        const res = await fetch('?action=deelnemers&comp=' + encodeURIComponent(compId));
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        _deelnemers = data;
        if (!data.length) {
            $('lijst-wrap').innerHTML = `<div class="leeg">${esc(t('msg_geen_deelnemers'))}</div>`;
            $('zoek-rij').style.display = 'none';
            return;
        }
        $('zoek-rij').style.display = '';
        rendDeelnemers('');
    } catch (e) {
        $('lijst-wrap').innerHTML = `<div class="fout">⚠ ${esc(e.message)}</div>`;
    }
}

function rendDeelnemers(filter) {
    const f = (filter || '').toLowerCase().trim();
    const lijst = !f ? _deelnemers : _deelnemers.filter(d =>
        (d.short_name || '').toLowerCase().includes(f));
    if (!lijst.length) {
        $('lijst-wrap').innerHTML = `<div class="leeg">${esc(t('msg_geen_match').replace('{q}', filter))}</div>`;
        return;
    }
    $('lijst-wrap').innerHTML = `
        <div class="kaart" style="padding:0">
            <div class="deelnemers">
                ${lijst.map(d => `
                    <div class="dl-rij" tabindex="0"
                         data-short="${esc(d.short_name)}">
                        <b>${esc(d.short_name)}</b>
                    </div>`).join('')}
            </div>
        </div>
        <div style="margin-top:.6rem;text-align:center">
            <button id="niet-gevonden-btn" type="button"
                    style="background:none;border:1px solid #c0c8d0;padding:8px 14px;
                           border-radius:6px;cursor:pointer;font:inherit;color:#555;font-size:.92rem">
                ${esc(t('btn_niet_gevonden'))}
            </button>
        </div>`;
    document.querySelectorAll('.dl-rij').forEach(r => {
        r.addEventListener('click', () => vraagVoornaamEnToon(r.dataset.short));
        r.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                vraagVoornaamEnToon(r.dataset.short);
            }
        });
    });
    $('niet-gevonden-btn')?.addEventListener('click', openNietGevondenMail);
}

// ── Meld-formulier (shared voor "fout" en "onbekend") ────────────────────
// Vervangt de mailto-aanpak: alles in-page, geen mail-app vereist.
// Aangepaste velden worden door backend vergeleken met 'oud' om de
// AANGEPAST-tag in de verstuurde mail te tonen.
//
// type='fout'     → velden zijn voor-ingevuld vanuit huidige detail-data
// type='onbekend' → velden zijn leeg, rijder vult zelf in
function openMeldFormulier(type, voorinvulling) {
    const wedstrijdNaam = $('comp-sel').selectedOptions[0]?.textContent || '';
    // Configuratie per type — welke velden, hun labels
    const veldenConfig = type === 'fout' ? [
        {key: 'naam',       label: t('mf_lbl_naam'),       val: voorinvulling.full_name    || ''},
        {key: 'gender',     label: t('mf_lbl_gender'),     val: voorinvulling.gender       || '', opts: ['M','V']},
        {key: 'cat',        label: t('mf_lbl_cat'),        val: voorinvulling.category     || ''},
        {key: 'snr',        label: t('mf_lbl_snr'),        val: voorinvulling.start_number ?? ''},
        {key: 'club',       label: t('mf_lbl_club'),       val: voorinvulling.club         || ''},
        {key: 'sponsor',    label: t('mf_lbl_sponsor'),    val: voorinvulling.sponsor      || ''},
        {key: 'transponder',label: t('mf_lbl_transponder'),val: voorinvulling.transponder  || ''},
    ] : [
        {key: 'naam',       label: t('mf_lbl_naam'),       val: ''},
        {key: 'gender',     label: t('mf_lbl_gender'),     val: '', opts: ['M','V']},
        {key: 'gebjaar',    label: t('mf_lbl_gebjaar'),    val: ''},
        {key: 'cat',        label: t('mf_lbl_cat'),        val: ''},
        {key: 'club',       label: t('mf_lbl_club'),       val: ''},
        {key: 'knsb',       label: t('mf_lbl_knsb'),       val: ''},
        {key: 'transponder',label: t('mf_lbl_transponder'),val: ''},
    ];

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100;'
        + 'display:flex;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto';
    const veldHtml = veldenConfig.map(v => {
        const id = 'mf-v-' + v.key;
        const input = v.opts
            ? `<select id="${id}" data-key="${esc(v.key)}" data-orig="${esc(v.val)}" data-label="${esc(v.label)}"
                       style="width:100%;padding:8px;border:1px solid #c0c8d0;border-radius:5px;font:inherit">
                   <option value=""></option>
                   ${v.opts.map(o => `<option value="${esc(o)}"${o === v.val ? ' selected' : ''}>${esc(o)}</option>`).join('')}
               </select>`
            : `<input type="text" id="${id}" value="${esc(v.val)}" data-key="${esc(v.key)}"
                      data-orig="${esc(v.val)}" data-label="${esc(v.label)}"
                      style="width:100%;padding:8px;border:1px solid #c0c8d0;border-radius:5px;font:inherit">`;
        return `
            <div style="margin-bottom:.5rem">
                <label for="${id}" style="display:block;font-size:.82rem;color:#555;font-weight:600;margin-bottom:.2rem">
                    ${esc(v.label)}
                </label>
                ${input}
            </div>`;
    }).join('');

    overlay.innerHTML = `
        <div style="background:#fff;border-radius:10px;padding:1rem 1.2rem;max-width:480px;width:100%;
                    box-shadow:0 8px 32px rgba(0,0,0,.25);margin:auto">
            <div style="font-weight:700;color:var(--blauw);margin-bottom:.4rem;font-size:1.05rem">
                ${esc(t(type === 'fout' ? 'mf_titel_fout' : 'mf_titel_onbekend'))}
            </div>
            <div style="font-size:.85rem;color:#555;margin-bottom:.7rem">
                ${esc(t(type === 'fout' ? 'mf_uitleg_fout' : 'mf_uitleg_onbekend'))}
            </div>
            <div style="font-size:.82rem;color:#888;margin-bottom:.7rem;
                        padding:.3rem .6rem;background:#f4f6f8;border-radius:4px">
                ${esc(t('mf_wedstrijd'))}: <b>${esc(wedstrijdNaam)}</b>
            </div>
            ${veldHtml}
            <div style="margin-top:.4rem">
                <label for="mf-opm" style="display:block;font-size:.82rem;color:#555;font-weight:600;margin-bottom:.2rem">
                    ${esc(t('mf_lbl_opmerking'))}
                </label>
                <textarea id="mf-opm" rows="3" placeholder="${esc(t('mf_opm_placeholder'))}"
                          style="width:100%;padding:8px;border:1px solid #c0c8d0;border-radius:5px;
                                 font:inherit;resize:vertical"></textarea>
            </div>
            <div style="margin-top:.5rem">
                <label for="mf-email" style="display:block;font-size:.82rem;color:#555;font-weight:600;margin-bottom:.2rem">
                    ${esc(t('mf_lbl_email'))} *
                </label>
                <input type="email" id="mf-email" autocomplete="email"
                       placeholder="${esc(t('mf_email_placeholder'))}"
                       style="width:100%;padding:8px;border:1px solid #c0c8d0;border-radius:5px;font:inherit">
            </div>
            <div id="mf-fout" style="color:#b71c1c;font-size:.88rem;margin-top:.4rem;display:none"></div>
            <div id="mf-ok" style="color:#0a7a3a;font-size:.92rem;margin-top:.4rem;display:none;font-weight:600"></div>
            <div style="margin-top:.9rem;display:flex;gap:.5rem;justify-content:flex-end">
                <button id="mf-annul" style="padding:8px 14px;border:1px solid #c0c8d0;
                        background:#fff;border-radius:6px;cursor:pointer;font:inherit">
                    ${esc(t('mf_annul'))}
                </button>
                <button id="mf-send" style="padding:8px 16px;background:var(--blauw);color:#fff;
                        border:none;border-radius:6px;cursor:pointer;font:inherit;font-weight:600">
                    ${esc(t('mf_send'))}
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    const sluit = () => overlay.remove();
    overlay.querySelector('#mf-annul').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });

    overlay.querySelector('#mf-send').addEventListener('click', async () => {
        const foutEl = overlay.querySelector('#mf-fout');
        const okEl   = overlay.querySelector('#mf-ok');
        const email  = overlay.querySelector('#mf-email').value.trim();
        const opm    = overlay.querySelector('#mf-opm').value.trim();
        foutEl.style.display = 'none';
        okEl.style.display   = 'none';

        if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
            foutEl.textContent = t('mf_err_email'); foutEl.style.display = '';
            return;
        }
        // Verzamel velden + check op aanpassingen
        const velden = [];
        overlay.querySelectorAll('[data-key]').forEach(el => {
            velden.push({
                key:   el.dataset.key,
                label: el.dataset.label,    // fallback bij onbekende key
                oud:   el.dataset.orig,
                nieuw: el.value.trim(),
            });
        });
        // Validatie: ten minste 1 veld ingevuld of opmerking
        const heeftInhoud = opm !== '' || velden.some(v => v.nieuw !== '');
        if (!heeftInhoud) {
            foutEl.textContent = t('mf_err_leeg'); foutEl.style.display = '';
            return;
        }

        const btn = overlay.querySelector('#mf-send');
        btn.disabled = true;
        btn.textContent = t('mf_bezig');
        try {
            const res = await fetch('?action=submit', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    type, email, opmerking: opm,
                    wedstrijd: wedstrijdNaam,
                    comp_id: $('comp-sel').value,
                    velden,
                }),
            });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'send_failed');
            okEl.textContent = t('mf_succes') + (data.cc_sent ? ' ' + t('mf_succes_cc') : '');
            okEl.style.display = '';
            btn.style.display = 'none';
            overlay.querySelector('#mf-annul').textContent = t('mf_sluit');
            setTimeout(sluit, 3000);
        } catch (e) {
            foutEl.textContent = t('mf_err_send') + ' (' + (e.message || '') + ')';
            foutEl.style.display = '';
            btn.disabled = false;
            btn.textContent = t('mf_send');
        }
    });
}
function openNietGevondenMail() { openMeldFormulier('onbekend', {}); }

// ── Info / Help modals (zelfde patroon als /public) ──────────────────────
function _bouwOverlay(titel, bodyHtml) {
    const overlay = document.createElement('div');
    overlay.className = 'help-overlay';
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    overlay.innerHTML = `
        <div class="help-box">
            <div class="help-header">
                <span>${esc(titel)}</span>
                <button class="help-sluit" onclick="this.closest('.help-overlay').remove()">&times;</button>
            </div>
            <div class="help-body">${bodyHtml}</div>
        </div>`;
    document.body.appendChild(overlay);
}
function toonInfo() {
    const jaar = new Date().getFullYear();
    const copyright = t('info_copyright').replace('{jaar}', jaar);
    _bouwOverlay(t('info_titel'), `
        <h3>${esc(t('info_h1'))}</h3>
        <p>${esc(t('info_p1'))}</p>
        <p>${t('info_p2_html')}</p>

        <h3>${esc(t('info_h2'))}</h3>
        <p>${t('info_p3_html')}</p>
        <p>${esc(t('info_p4'))}</p>

        <h3>${esc(t('info_h_data'))}</h3>
        <p>${esc(t('info_p_data'))}</p>
        <p style="text-align:center;margin:12px 0">
            <a href="../privacyverklaring.php" target="_blank" rel="noopener"
               style="display:inline-block;background:var(--blauw);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">
                ${esc(t('info_btn_privacy'))}
            </a>
        </p>

        <h3>${t('info_h3_html')}</h3>
        <p>${esc(t('info_p5'))}</p>
        <p style="text-align:center;margin:12px 0">
            <a href="mailto:inlinecomp@devriesen.com"
               style="display:inline-block;background:var(--oranje);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.95rem">
                inlinecomp@devriesen.com
            </a>
        </p>
        <p style="font-size:.85rem;color:#555">${esc(t('info_p6'))}</p>

        <p style="font-size:.8rem;color:#999;text-align:center;margin-top:16px">${copyright}</p>
    `);
}
function toonHelp() {
    _bouwOverlay(t('help_titel'), `
        <h3>${esc(t('help_h1'))}</h3>
        <div class="help-stap">
            <span class="help-stap-nr">1</span>
            <span>${t('help_stap1_html')}</span>
        </div>
        <div class="help-stap">
            <span class="help-stap-nr">2</span>
            <span>${t('help_stap2_html')}</span>
        </div>
        <div class="help-stap">
            <span class="help-stap-nr">3</span>
            <span>${t('help_stap3_html')}</span>
        </div>

        <h3>${esc(t('help_h_meld'))}</h3>
        <p>${t('help_p_meld_html')}</p>

        <h3>${esc(t('help_h_onbekend'))}</h3>
        <p>${t('help_p_onbekend_html')}</p>
    `);
}

// ── Voornaam-prompt (privacy-laag) ───────────────────────────────────────
function vraagVoornaamEnToon(shortName) {
    // Mini-modal overlay. Reuse-friendly: vervangt huidige content.
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;'
        + 'display:flex;align-items:center;justify-content:center;padding:1rem';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:10px;padding:1rem 1.2rem;max-width:380px;width:100%;
                    box-shadow:0 8px 32px rgba(0,0,0,.25)">
            <div style="font-weight:700;color:var(--blauw);margin-bottom:.4rem">
                🔒 ${esc(t('prompt_titel'))}
            </div>
            <div style="font-size:.88rem;color:#555;margin-bottom:.7rem">
                ${esc(t('prompt_uitleg').replace('{naam}', shortName))}
            </div>
            <input type="text" id="prompt-voornaam" autocomplete="given-name"
                   placeholder="${esc(t('prompt_placeholder'))}"
                   style="width:100%;padding:9px 10px;border:1px solid #c0c8d0;border-radius:6px;font:inherit">
            <div id="prompt-fout" style="color:#b71c1c;font-size:.88rem;margin-top:.4rem;display:none"></div>
            <div style="margin-top:.8rem;display:flex;gap:.5rem;justify-content:flex-end">
                <button id="prompt-annul" style="padding:8px 14px;border:1px solid #c0c8d0;
                        background:#fff;border-radius:6px;cursor:pointer;font:inherit">
                    ${esc(t('prompt_annul'))}
                </button>
                <button id="prompt-ok" style="padding:8px 14px;background:var(--blauw);color:#fff;
                        border:none;border-radius:6px;cursor:pointer;font:inherit;font-weight:600">
                    ${esc(t('prompt_ok'))}
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    const inp = overlay.querySelector('#prompt-voornaam');
    const fout = overlay.querySelector('#prompt-fout');
    inp.focus();
    const sluit = () => overlay.remove();
    overlay.querySelector('#prompt-annul').addEventListener('click', sluit);
    overlay.addEventListener('click', e => { if (e.target === overlay) sluit(); });
    document.addEventListener('keydown', function escSluit(e) {
        if (e.key === 'Escape' && document.body.contains(overlay)) {
            sluit();
            document.removeEventListener('keydown', escSluit);
        }
    });
    const probeer = async () => {
        const v = inp.value.trim();
        if (!v) {
            fout.textContent = t('err_voornaam_leeg');
            fout.style.display = '';
            return;
        }
        fout.style.display = 'none';
        const res = await toonDetail(shortName, v);
        if (res === 'ok') {
            sluit();
        } else {
            fout.textContent = t(res === 'ambigu' ? 'err_voornaam_ambigu' : 'err_voornaam_fout');
            fout.style.display = '';
            inp.select();
        }
    };
    overlay.querySelector('#prompt-ok').addEventListener('click', probeer);
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') probeer(); });
}

async function toonDetail(shortName, voornaam) {
    const compId = $('comp-sel').value;
    if (!compId || !shortName) return 'fout';
    $('detail-wrap').dataset.short = shortName;
    $('detail-wrap').dataset.voornaam = voornaam || '';
    try {
        const url = '?action=rijder&comp=' + encodeURIComponent(compId)
                  + '&short=' + encodeURIComponent(shortName)
                  + (voornaam ? '&voornaam=' + encodeURIComponent(voornaam) : '');
        const res = await fetch(url);
        if (res.status === 403) return 'mismatch';   // voornaam matcht niet
        if (res.status === 409) return 'ambigu';     // meerdere kandidaten
        const data = await res.json();
        if (data.error) {
            $('detail-wrap').innerHTML = `<div class="kaart"><div class="fout">⚠ ${esc(data.error)}</div></div>`;
            return 'ok';
        }
        const p = data.persoon;
        const wedstrijdNaam = data.wedstrijd?.name || '';

        // Status-badge per inschrijving — zelfde stijl + icons als /coach
        // (coach/index.php:3163 STATUS_ICON + .status-badge.status-N CSS).
        // Defensieve fallback op status=1 als de waarde ontbreekt (oude data).
        const STATUS_ICON = ['⚠','✓','✗','✗','🚨','✓'];
        const inschr = (data.inschrijvingen || []).length
            ? (data.inschrijvingen || []).map(e => {
                const st  = parseInt(e.status ?? 1);
                const lbl = t('status_' + st) || '';
                const ico = STATUS_ICON[st] ?? '';
                return `<div style="margin-bottom:.3rem">${esc(e.dc_naam)}` +
                       ` <span class="status-badge status-${st}" style="margin-left:.3rem">${ico} ${esc(lbl)}</span></div>`;
            }).join('')
            : `<i style="color:#888">${esc(t('geen_inschr'))}</i>`;
        const tp = (data.transponders || []);
        const tpHtml = tp.length === 0
            ? `<i style="color:#888">${esc(t('geen_tp'))}</i>`
            : tp.map(t2 => `<div>slot ${t2.slot}: <code>${esc(t2.code || '—')}</code>${
                t2.source === 'manual' ? ` <span style="font-size:.85em;color:#888">${esc(t('tp_handmatig'))}</span>` : ''
              }</div>`).join('');

        const genderLeesb = p.gender === 'M' ? `M (${esc(t('lbl_gender'))[0] === 'G' ? 'm' : 'm'})` : (p.gender === 'V' ? 'V' : p.gender);

        // Snapshot van huidige persons-data + eerste transponder voor de
        // meld-form (om "aangepast" te kunnen detecteren). Stash op detail-
        // wrap zodat openMeldFormulier dat kan ophalen.
        $('detail-wrap')._persoon = {
            ...p,
            transponder: (data.transponders || [])[0]?.code || '',
        };

        $('detail-wrap').innerHTML = `
            <button class="terug" id="terug-btn">${esc(t('terug'))}</button>
            <div class="kaart">
                <h2 style="margin:.1rem 0 .8rem;font-size:1.15rem">${esc(p.full_name)}</h2>
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_wedstrijd'))}</span>
                    <span class="val">${esc(wedstrijdNaam)}</span>
                </div>
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_gender'))}</span>
                    <span class="val">${esc(p.gender)}</span>
                </div>
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_categorie'))}</span>
                    <span class="val">${esc(p.category || '—')}</span>
                </div>
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_startnummer'))}</span>
                    <span class="val">${p.start_number ?? '—'}</span>
                </div>
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_club'))}</span>
                    <span class="val">${esc(p.club || '—')}</span>
                </div>
                ${p.nationality ? `
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_nationaliteit'))}</span>
                    <span class="val">${esc(p.nationality)}</span>
                </div>` : ''}
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_sponsor'))}</span>
                    <span class="val">${esc(p.sponsor || '—')}</span>
                </div>
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_inschrijvingen'))}</span>
                    <span class="val">${inschr}</span>
                </div>
                <div class="detail-rij">
                    <span class="lbl">${esc(t('lbl_transponder'))}</span>
                    <span class="val">${tpHtml}</span>
                </div>
                <div class="mail-blok">
                    📧 <b>${esc(t('mail_titel'))}</b><br>
                    ${esc(t('mail_uitleg_form'))}
                    <div style="margin-top:.6rem">
                        <button id="meld-fout-btn"
                                style="background:var(--oranje);color:#fff;border:none;
                                       padding:8px 16px;border-radius:6px;cursor:pointer;
                                       font:inherit;font-weight:600">
                            ${esc(t('mail_btn_open'))}
                        </button>
                    </div>
                </div>
            </div>`;
        $('meld-fout-btn')?.addEventListener('click', () =>
            openMeldFormulier('fout', $('detail-wrap')._persoon || {}));
        $('terug-btn').addEventListener('click', () => {
            $('detail-wrap').innerHTML = '';
            delete $('detail-wrap').dataset.short;
            delete $('detail-wrap').dataset.voornaam;
            $('zoek').focus();
        });
        $('detail-wrap').scrollIntoView({behavior:'smooth', block:'start'});
        return 'ok';
    } catch (e) {
        $('detail-wrap').innerHTML = `<div class="kaart"><div class="fout">⚠ ${esc(e.message)}</div></div>`;
        return 'ok';
    }
}
</script>
</body>
</html>
