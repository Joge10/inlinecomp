<?php
// ============================================================
//  InlineComp – helper voor "wedstrijden combineren"
//
//  Eén doel-wedstrijd kan 1..N bron-KNSB-wedstrijden bevatten
//  (tabel competition_bronnen). vergelijk.php + import.php loopen
//  over [doel + bronnen] en schrijven alles onder het doel-id.
// ============================================================

if (!function_exists('bronCompetitionIds')) {
    /**
     * De bron-competition-UUID's die onder deze doelwedstrijd vallen.
     * Lege array als er geen bronnen zijn (= gewone wedstrijd) of als de
     * tabel nog niet bestaat (migratie niet gedraaid) — defensief.
     */
    function bronCompetitionIds(PDO $pdo, string $doelId): array {
        if ($doelId === '') return [];
        try {
            $s = $pdo->prepare(
                'SELECT bron_competition_id FROM competition_bronnen
                  WHERE doel_competition_id = ? ORDER BY toegevoegd_op'
            );
            $s->execute([$doelId]);
            return $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Alle competition-id's die geïmporteerd moeten worden voor deze
     * (mogelijk gecombineerde) wedstrijd: het doel zelf + z'n bronnen.
     * Volgorde: doel eerst (levert de metadata/venue), dan de bronnen.
     */
    function importBronnenVoorDoel(PDO $pdo, string $doelId): array {
        return array_merge([$doelId], bronCompetitionIds($pdo, $doelId));
    }

    /** Is deze wedstrijd een combinatie (heeft ze bronnen)? */
    function isCombinatie(PDO $pdo, string $doelId): bool {
        return count(bronCompetitionIds($pdo, $doelId)) > 0;
    }

    /**
     * Is deze competition-UUID al ergens als bron gekoppeld (aan welk doel dan
     * ook)? Voorkomt dat één bron aan twee doelen hangt. Retourneert het
     * doel-id of ''.
     */
    function bronAlGekoppeldAan(PDO $pdo, string $bronId): string {
        if ($bronId === '') return '';
        try {
            $s = $pdo->prepare(
                'SELECT doel_competition_id FROM competition_bronnen
                  WHERE bron_competition_id = ? LIMIT 1'
            );
            $s->execute([$bronId]);
            return (string)($s->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
