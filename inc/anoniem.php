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

if (!function_exists('anoniemCompVenster')) {
    /**
     * Haalt [starts, ends] van een wedstrijd op voor het publieke venster.
     * Cachet per competition_id binnen één request (meerdere leesplekken lezen
     * dezelfde wedstrijd). Faalt stil naar [null, null] → dan maskeert de
     * public-laag (veilig).
     */
    function anoniemCompVenster(PDO $pdo, string $compId): array {
        static $cache = [];
        if ($compId === '') return [null, null];
        if (!array_key_exists($compId, $cache)) {
            try {
                $st = $pdo->prepare("SELECT starts, ends FROM competitions WHERE id = ?");
                $st->execute([$compId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $cache[$compId] = [$row['starts'] ?? null, $row['ends'] ?? null];
            } catch (Throwable $e) {
                $cache[$compId] = [null, null];
            }
        }
        return $cache[$compId];
    }
}

if (!function_exists('zorgVoorVolgToken')) {
    /**
     * Geheim volg-token voor een rijder — de ENIGE sleutel die de naam van een
     * anonieme rijder ontsluit (entitlement). Los van person_id (dat is publiek).
     * Lazy: mint een token als er nog geen is en geeft het terug. Idempotent en
     * concurrency-veilig (UPDATE ... WHERE volg_token IS NULL + re-read).
     */
    function zorgVoorVolgToken(PDO $pdo, string $pid): ?string {
        if ($pid === '') return null;
        $sel = $pdo->prepare("SELECT volg_token FROM persons WHERE person_id = ?");
        $sel->execute([$pid]);
        $tok = $sel->fetchColumn();
        if ($tok) return (string)$tok;
        for ($i = 0; $i < 3; $i++) {
            try {
                $new = bin2hex(random_bytes(16));   // 32 hex
                $upd = $pdo->prepare("UPDATE persons SET volg_token = ? WHERE person_id = ? AND volg_token IS NULL");
                $upd->execute([$new, $pid]);
            } catch (Throwable $e) { /* unieke botsing → opnieuw */ }
            $sel->execute([$pid]);
            $tok = $sel->fetchColumn();
            if ($tok) return (string)$tok;
        }
        return null;
    }
}

if (!function_exists('nieuwVolgToken')) {
    /**
     * Vernieuw (rotate) het volg-token → snijdt in één klap ALLE huidige volgers
     * af (ook mensen aan wie het token eerder bewust gegeven is). Geeft het nieuwe
     * token terug.
     */
    function nieuwVolgToken(PDO $pdo, string $pid): ?string {
        if ($pid === '') return null;
        for ($i = 0; $i < 3; $i++) {
            try {
                $new = bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE persons SET volg_token = ? WHERE person_id = ?")->execute([$new, $pid]);
                return $new;
            } catch (Throwable $e) { /* unieke botsing → opnieuw */ }
        }
        return null;
    }
}

if (!function_exists('personIdVoorVolgToken')) {
    /** Resolve een volg-token → person_id (of null). */
    function personIdVoorVolgToken(PDO $pdo, string $token): ?string {
        $token = trim($token);
        if ($token === '') return null;
        $st = $pdo->prepare("SELECT person_id FROM persons WHERE volg_token = ? LIMIT 1");
        $st->execute([$token]);
        $pid = $st->fetchColumn();
        return $pid !== false ? (string)$pid : null;
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

if (!function_exists('verbergAnoniemId')) {
    /**
     * Strip ALLEEN de identiteits-tokens (person_id + aliassen) uit een rij.
     *
     * CRUCIAAL (variant B): person_id is de onraadbare entitlement-sleutel — wie
     * 'm heeft, ziet de echte naam. De publieke/gedeelde API emit person_id voor
     * élke rijder; voor een anonieme rijder mag die dus NOOIT publiek mee, ook
     * niet binnen het venster waarin de naam wél zichtbaar is. Anders plukt iemand
     * de GUID op de wedstrijddag uit de JSON en houdt daarmee PERMANENT toegang.
     * De echte volger heeft de GUID al via Mijn InlineComp / de organisatie.
     */
    function verbergAnoniemId(array $row, array $velden = []): array {
        $ids = $velden['ids'] ?? ['person_id', 'license_key', 'person_license', 'lic', 'db_person'];
        foreach ($ids as $f) if (array_key_exists($f, $row)) $row[$f] = null;
        return $row;
    }
}

if (!function_exists('maskeerAnoniemeRij')) {
    /**
     * Volledige maskering: naam → "Anoniem", club/woonplaats/sponsor gewist, en
     * de identiteits-tokens gestript (zie verbergAnoniemId). Startnummer blijft
     * staan. Zet 'is_anoniem' = true als hint voor de frontend. $velden overschrijft
     * de default veldnamen (naam-set / te-wissen-set / ids-set) per endpoint.
     */
    function maskeerAnoniemeRij(array $row, array $velden = []): array {
        $naam = $velden['naam'] ?? ['full_name', 'short_name', 'naam', 'voornaam', 'achternaam'];
        $wis  = $velden['wis']  ?? ['club_full', 'club_short', 'club_code', 'sponsor', 'city', 'club', 'woonplaats', 'nationality'];
        foreach ($naam as $f) if (array_key_exists($f, $row)) $row[$f] = 'Anoniem';
        foreach ($wis  as $f) if (array_key_exists($f, $row)) $row[$f] = null;
        $row = verbergAnoniemId($row, $velden);
        $row['is_anoniem'] = true;
        return $row;
    }
}

if (!function_exists('pasAnonimiteitToe')) {
    /**
     * Past anonimiteit toe op één rij. Verwacht een 'publiek_anoniem'-veld.
     *
     *   - Niet anoniem / entitled (heeft GUID) / operationele laag → volledige data
     *     (is_anoniem = false).
     *   - Anoniem, publiek/permanent, geen entitlement:
     *       • GUID (person_id + aliassen) wordt ALTIJD verborgen (ook in-venster);
     *       • naam/club worden gemaskeerd bij laag 'altijd', of bij laag 'public'
     *         zodra we búíten het venster [wedstrijddag −1 … +1] zitten.
     *     In beide gevallen is_anoniem = true (frontend-hint).
     */
    function pasAnonimiteitToe(array $row, string $laag,
                              ?string $starts = null, ?string $ends = null,
                              bool $entitled = false, array $velden = []): array {
        $anon = $row['publiek_anoniem'] ?? null;
        if ($anon === null || $anon === '' || $entitled || $laag === 'operationeel') {
            $row['is_anoniem'] = false;
            return $row;
        }
        $naamMaskeren = ($laag === 'altijd') || !binnenAnoniemVenster($starts, $ends);
        if ($naamMaskeren) {
            return maskeerAnoniemeRij($row, $velden);   // naam + club + GUID weg
        }
        // Binnen het venster: naam blijft zichtbaar (operationeel), GUID tóch weg.
        $row = verbergAnoniemId($row, $velden);
        $row['is_anoniem'] = true;
        return $row;
    }
}
