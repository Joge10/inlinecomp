<?php
// ============================================================
//  inc/app_instellingen.php — generieke systeembrede instellingen
//
//  Sleutel/waarde uit de tabel app_instellingen. Los van
//  competition_instellingen (per wedstrijd). Faalt stil naar de default als de
//  tabel nog niet bestaat (backward-compatible vóór de migratie).
// ============================================================

if (!function_exists('getInstelling')) {
    /** Lees een instelling (met kleine per-request cache). */
    function getInstelling(PDO $pdo, string $sleutel, ?string $default = null): ?string {
        static $cache = [];
        if (array_key_exists($sleutel, $cache)) return $cache[$sleutel];
        try {
            $st = $pdo->prepare("SELECT waarde FROM app_instellingen WHERE sleutel = ? LIMIT 1");
            $st->execute([$sleutel]);
            $v = $st->fetchColumn();
            $cache[$sleutel] = ($v === false) ? $default : $v;
        } catch (Throwable $e) {
            $cache[$sleutel] = $default;   // tabel bestaat nog niet → default
        }
        return $cache[$sleutel];
    }
}

if (!function_exists('setInstelling')) {
    /** Schrijf een instelling (upsert). Wist de per-request cache-waarde. */
    function setInstelling(PDO $pdo, string $sleutel, ?string $waarde): void {
        $st = $pdo->prepare(
            "INSERT INTO app_instellingen (sleutel, waarde) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)"
        );
        $st->execute([$sleutel, $waarde]);
    }
}
