<?php
// ============================================================
//  InlineComp – gedeelde baan-helpers (include-only, GEEN side-effects)
//
//  Kernprincipe: een wedstrijd hoort ALTIJD gekoppeld te zijn aan de baan-rij
//  van zijn EIGEN organisatie. Dezelfde fysieke baan kan onder meerdere orgs
//  als aparte rij bestaan (elk met eigen logo, vereniging-naam en sponsors).
//
//  Vroeger kon een wedstrijd via de "Baan toewijzen"-dropdown (gededupliceerd
//  op naam met MIN(id)) aan een WILLEKEURIGE org-rij gekoppeld raken. Logo en
//  vereniging-naam kwamen dan alsnog goed door via de cross-org display-
//  fallback, maar de sponsors (die per org-rij worden bijgehouden, GEEN
//  fallback) verdwenen. Deze resolver zorgt dat we voor (org, baannaam) altijd
//  de eigen-org-rij teruggeven — en maakt hem aan (met gekopieerde data uit
//  een gelijknamige andere-org-rij, behalve sponsors) als hij nog niet bestaat.
//
//  Bewust GEEN require van config/session hier: dit bestand definieert alleen
//  functies en mag veilig meerdere keren geïnclude worden (function_exists-guard).
// ============================================================

if (!function_exists('baanGenUuid')) {
    function baanGenUuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

if (!function_exists('baanLogoKopieer')) {
    /**
     * Kopieert een baan-logobestand naar een nieuw pad voor $newBaanId, zodat de
     * nieuwe org-rij zelfstandig is (delete van de bron-rij mag dit logo niet
     * weghalen — de delete-actie doet een @unlink op logo_path). Best-effort:
     * bij ontbrekend bronbestand of kopieerfout → null (de display-fallback op
     * gelijke naam dekt het beeld dan alsnog).
     *
     * @return string|null nieuw relatief logo_path, of null.
     */
    function baanLogoKopieer(string $bronRelPath, string $newBaanId): ?string {
        $bronRelPath = ltrim(trim($bronRelPath), '/');
        if ($bronRelPath === '') return null;
        $bronAbs = __DIR__ . '/../' . $bronRelPath;
        if (!is_file($bronAbs)) return null;
        $ext = pathinfo($bronRelPath, PATHINFO_EXTENSION) ?: 'png';
        $nieuwRel = 'uploads/logos/baan_' . $newBaanId . '.' . $ext;
        $nieuwAbs = __DIR__ . '/../' . $nieuwRel;
        $dir = dirname($nieuwAbs);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return @copy($bronAbs, $nieuwAbs) ? $nieuwRel : null;
    }
}

if (!function_exists('baanVoorOrgResolven')) {
    /**
     * Geeft de baan-id BINNEN $orgId voor een baan met $naam.
     *   1) Match op exacte naam binnen de org.
     *   2) Match op alias binnen de org.
     *   3) Niet gevonden → nieuwe eigen-org-rij aanmaken, met gekopieerde data
     *      (stad, vereniging-naam, logo-bestand, aliassen) uit de meest-complete
     *      gelijknamige rij van een ANDERE org. Sponsors worden NOOIT gekopieerd.
     *
     * @return string|null baan-id (eigen org), of null als $naam/$orgId leeg is.
     */
    function baanVoorOrgResolven(PDO $pdo, string $orgId, string $naam, ?string $venueCity = null): ?string {
        $naam  = trim($naam);
        $orgId = trim($orgId);
        if ($naam === '' || $orgId === '') return null;

        // 1) Exacte naam binnen eigen org
        $st = $pdo->prepare("SELECT id FROM banen WHERE organisatie_id = ? AND naam = ? LIMIT 1");
        $st->execute([$orgId, $naam]);
        if ($id = $st->fetchColumn()) return $id;

        // 2) Alias binnen eigen org
        $st = $pdo->prepare("
            SELECT a.baan_id FROM baan_aliassen a
            JOIN banen b ON b.id = a.baan_id
            WHERE b.organisatie_id = ? AND a.naam = ?
            LIMIT 1
        ");
        $st->execute([$orgId, $naam]);
        if ($id = $st->fetchColumn()) return $id;

        // 3) Aanmaken — bron zoeken: meest-complete gelijknamige rij van andere org
        $src = $pdo->prepare("
            SELECT id, stad, vereniging_naam, logo_path
            FROM banen
            WHERE naam = ? AND organisatie_id <> ?
            ORDER BY (vereniging_naam IS NOT NULL AND vereniging_naam <> '') DESC,
                     (logo_path       IS NOT NULL AND logo_path       <> '') DESC
            LIMIT 1
        ");
        $src->execute([$naam, $orgId]);
        $bron = $src->fetch(PDO::FETCH_ASSOC) ?: null;

        $newId = baanGenUuid();
        $stad  = ($bron['stad'] ?? null) ?: ($venueCity !== '' ? $venueCity : null);
        $ver   = $bron['vereniging_naam'] ?? null;

        // Logo-bestand fysiek kopiëren (zelfstandige rij, delete-veilig).
        $logoPath = null;
        if (!empty($bron['logo_path'])) {
            $logoPath = baanLogoKopieer($bron['logo_path'], $newId);
        }

        $pdo->prepare("
            INSERT INTO banen (id, organisatie_id, naam, stad, vereniging_naam, logo_path, logo_updated_at)
            VALUES (?, ?, ?, ?, ?, ?, " . ($logoPath ? "NOW()" : "NULL") . ")
        ")->execute([$newId, $orgId, $naam, $stad, $ver, $logoPath]);

        // Aliassen kopiëren (helpt toekomstige feed-matching binnen deze org).
        if (!empty($bron['id'])) {
            $al = $pdo->prepare("SELECT naam FROM baan_aliassen WHERE baan_id = ?");
            $al->execute([$bron['id']]);
            $ins = $pdo->prepare("INSERT INTO baan_aliassen (id, baan_id, naam) VALUES (?, ?, ?)");
            foreach ($al->fetchAll(PDO::FETCH_COLUMN) as $aliasNaam) {
                $ins->execute([baanGenUuid(), $newId, $aliasNaam]);
            }
        }

        return $newId;
    }
}
