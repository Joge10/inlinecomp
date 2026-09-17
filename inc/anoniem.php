<?php
// ============================================================
//  inc/anoniem.php — publieke anonimiteit (variant B)
//
//  Eén bron van waarheid voor het maskeren van een publiek-anonieme rijder.
//  Zie docs_internal/plan-anonimiteit.md. De vlag is persons.publiek_anoniem
//  (DATETIME, NULL = niet anoniem) — omkeerbaar, data blijft behouden. Los van
//  de onomkeerbare persons.anonymized_at.
//
//  Lagen (context waarin een rij getoond wordt):
//    'operationeel' — jury/speaker, print-center, live-verwerking, coach MÉT
//                     account, beheer → ALTIJD echte naam (identificatie/eerlijkheid).
//    'public'       — /public per wedstrijd + coach ZONDER account (gedeeld ww):
//                     naam zichtbaar binnen [wedstrijddag −1 … +1], daarbuiten gemaskeerd.
//    'altijd'       — permanent doorzoekbaar record: publieke rijder-zoek,
//                     serie-klassement, cross-seizoen-historie → ALTIJD maskeren.
//
//  'entitled' = de kijker heeft het onraadbare person_id (GUID) → volger/eigenaar
//  ziet de echte naam, ongeacht laag/venster (kern van variant B).
// ============================================================

if (!function_exists('binnenAnoniemVenster')) {
    /**
     * Valt 'nu' binnen [wedstrijddag −1 … wedstrijddag +1]? Meerdaags: [starts−1 … ends+1].
     * Zonder datum → geen venster → false (veilig: dan maskeren op de public-laag).
     */
    function binnenAnoniemVenster(?string $starts, ?string $ends, ?int $now = null): bool {
        $now = $now ?? time();
        $s = $starts ? strtotime($starts) : null;
        $e = $ends   ? strtotime($ends)   : null;
        if (!$s && !$e) return false;
        $s = $s ?: $e;
        $e = $e ?: $s;
        // Van = 00:00 van de dag vóór de startdag; Tot = 23:59:59 van de dag ná de einddag.
        $van = strtotime(date('Y-m-d 00:00:00', $s) . ' -1 day');
        $tot = strtotime(date('Y-m-d 23:59:59', $e) . ' +1 day');
        return $now >= $van && $now <= $tot;
    }
}

if (!function_exists('moetAnoniemMaskeren')) {
    /**
     * Bepaalt of een rij gemaskeerd moet worden.
     *   $publiekAnoniem : persons.publiek_anoniem (DATETIME of null)
     *   $laag           : 'operationeel' | 'public' | 'altijd'
     *   $starts/$ends   : wedstrijd-datums (alleen relevant voor 'public')
     *   $entitled       : kijker heeft het GUID → nooit maskeren
     */
    function moetAnoniemMaskeren(?string $publiekAnoniem, string $laag,
                                 ?string $starts = null, ?string $ends = null,
                                 bool $entitled = false): bool {
        if ($publiekAnoniem === null || $publiekAnoniem === '') return false; // niet anoniem
        if ($entitled) return false;
        switch ($laag) {
            case 'operationeel': return false;
            case 'altijd':       return true;
            case 'public':       return !binnenAnoniemVenster($starts, $ends);
        }
        return false; // onbekende laag → veilig niet-maskeren (operationeel-default)
    }
}

if (!function_exists('maskeerAnoniemeRij')) {
    /**
     * Vervangt de naam-velden door "Anoniem" en wist club/woonplaats/sponsor.
     * Startnummer blijft staan. Zet 'is_anoniem' = true als hint voor de frontend.
     * $velden overschrijft de default veldnamen (naam-set / te-wissen-set) per endpoint.
     */
    function maskeerAnoniemeRij(array $row, array $velden = []): array {
        $naam = $velden['naam'] ?? ['full_name', 'short_name', 'naam', 'voornaam', 'achternaam'];
        $wis  = $velden['wis']  ?? ['club_full', 'club_short', 'club_code', 'sponsor', 'city', 'club', 'woonplaats', 'nationality'];
        foreach ($naam as $f) if (array_key_exists($f, $row)) $row[$f] = 'Anoniem';
        foreach ($wis  as $f) if (array_key_exists($f, $row)) $row[$f] = null;
        $row['is_anoniem'] = true;
        return $row;
    }
}

if (!function_exists('pasAnonimiteitToe')) {
    /**
     * Gemaksfunctie: maskeer $row als dat nodig is, anders geef 'm ongewijzigd terug
     * (met 'is_anoniem' = false). Verwacht dat $row een 'publiek_anoniem'-veld heeft.
     */
    function pasAnonimiteitToe(array $row, string $laag,
                              ?string $starts = null, ?string $ends = null,
                              bool $entitled = false, array $velden = []): array {
        if (moetAnoniemMaskeren($row['publiek_anoniem'] ?? null, $laag, $starts, $ends, $entitled)) {
            return maskeerAnoniemeRij($row, $velden);
        }
        $row['is_anoniem'] = false;
        return $row;
    }
}
