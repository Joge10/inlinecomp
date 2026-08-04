<?php
// ============================================================
//  InlineComp – gedeelde categorie-sorteerhelper
//
//  catSorteerSleutel(): standaard categorie-volgorde (jong→oud, dames vóór
//  heren) op basis van KNSB category_filter-codes, met fallback op de naam.
//  Gebruikt door api/tijdschema.php (rit-generatie) én api/wizard_dc.php
//  (categorie-volgorde in de bak). Eén bron → consistente volgorde overal.
// ============================================================

if (!function_exists('catSorteerSleutel')) {
    /**
     * Sorteersleutel voor categorieën / distance combinations.
     *
     * Primair: KNSB category_filter-codes (bijv. "DJA*,DS*")
     *   - Geslacht: D/V = dames (0), H = heren (1)
     *   - Leeftijd: P4..M (0..8), gecombineerd → oudste bepaalt positie
     * Fallback op $naam als $catFilter leeg/onparsebaar is.
     */
    function catSorteerSleutel(string $naam, string $catFilter = ''): string {

        // KNSB leeftijdscode → rang (0 = jongste, 8 = oudste)
        $ageCodeRank = [
            'P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3, 'P' => 3,   // 'P' = pupillen-wildcard (DP*/HP*)
            'K'  => 4,                                              // 'K' dekt DK*/HK* én DKA/HKA
            'JB' => 5, 'JA' => 6, 'J' => 6,   // 'J' = junior-wildcard (DJ*/HJ*)
            'S'  => 7,
            'M'  => 8,
        ];

        $maxAge = -1;
        $gk     = '1'; // mannen standaard

        $filter = trim($catFilter);
        if ($filter !== '') {
            // Splits op komma; strip wildcards/spaties; bijv. ["DJA*","DS*","HJB*"]
            $codes = preg_split('/[\s,]+/', strtoupper($filter));
            foreach ($codes as $raw) {
                $code = trim($raw, '* ');
                if (strlen($code) < 2) continue;

                // Geslacht: eerste letter bepaalt (D/V = dames, H = heren)
                if ($code[0] === 'D' || $code[0] === 'V') {
                    $gk = '0';
                }

                // Leeftijdscode: alles na de geslachtsletter (bijv. 'JA', 'P4', 'S').
                // Progressieve afkapping voor rauwe KNSB-codes zoals 'DJAA' → 'JAA' → 'JA'.
                $ageStr = substr($code, 1);
                while ($ageStr !== '' && !isset($ageCodeRank[$ageStr])) {
                    $ageStr = substr($ageStr, 0, -1);
                }
                if ($ageStr !== '') {
                    $maxAge = max($maxAge, $ageCodeRank[$ageStr]);
                }
            }
        }

        // ── Fallback: parse naam als category_filter ontbreekt ───────────────
        if ($maxAge < 0) {
            $n = mb_strtolower(trim($naam));
            $leeftijdPatronen = [
                0 => ['pupillen 4', 'pup 4', 'pup4', 'p4'],
                1 => ['pupillen 3', 'pup 3', 'pup3', 'p3'],
                2 => ['pupillen 2', 'pup 2', 'pup2', 'p2'],
                3 => ['pupillen 1', 'pup 1', 'pup1', 'p1'],
                4 => ['kadetten', 'kad'],
                5 => ['junioren b', 'jun b', 'jun. b', 'jb'],
                6 => ['junioren a', 'jun a', 'jun. a', 'ja'],
                7 => ['senioren', 'sen'],
                8 => ['masters', 'mast', 'mas'],
            ];
            foreach ($leeftijdPatronen as $idx => $patronen) {
                foreach ($patronen as $p) {
                    if (str_contains($n, $p)) {
                        $maxAge = max($maxAge, $idx);
                        break;
                    }
                }
            }
            // Dames vóór heren (via naam)
            $vrouwPatronen = ['vrouwen', 'dames', 'meisjes', 'girls', 'women', 'ladies'];
            foreach ($vrouwPatronen as $p) {
                if (str_contains($n, $p)) { $gk = '0'; break; }
            }
            if ($gk === '1' && preg_match('/^[dv][jkpsm]/', $n)) {
                $gk = '0';
            }
        }

        $lk = $maxAge >= 0
            ? str_pad((string)$maxAge, 2, '0', STR_PAD_LEFT)
            : '99';

        return $lk . $gk . mb_strtolower(trim($naam));
    }
}
