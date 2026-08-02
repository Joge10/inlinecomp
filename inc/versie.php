<?php
// ════════════════════════════════════════════════════════════════════════
//  InlineComp — ÉÉN gedeeld versienummer voor het hele systeem
//  (wedstrijdbeheer/admin, public, coach, check, jury). Eén product,
//  één tijdlijn, één roadmap.
//
//  Formaat: H<uren>.<DD>.<MM>   (uren sinds InlineComp v0 op OH850, 2026-06-20 00:00)
//  Rollover bij H9999+ → Y<jaren>.<DD>.<MM>  (1 Y = 1 jaar ≈ 8760 uur);
//  M (maanden) slaan we bewust over — anders komen we nooit bij Y ;)
//
//  Bumpen gaat NIET meer hier: de versie wordt afgeleid van de BOVENSTE
//  entry in inc/changelog.php. Zo kan de versie niet vooruit zonder dat er
//  een changelog-regel bijkomt — vergeten is structureel onmogelijk.
//  Nieuwe versie maken = de opdracht "commit nieuwe versie" (zie
//  .claude/commands/commit-nieuwe-versie.md): entries bovenaan changelog.php,
//  hier gebeurt verder niets.
// ════════════════════════════════════════════════════════════════════════

if (!defined('INLINECOMP_VERSIE')) {
    // Huidige versie = de bovenste ECHTE release-entry. Patch-entries
    // (soort='patch': security/bugfix) staan bovenaan maar schuiven het
    // versienummer bewust NIET vooruit — ze horen onder de lopende versie.
    // Defensieve fallback als changelog.php onverhoopt leeg/onbereikbaar is.
    $__clHead = @require __DIR__ . '/changelog.php';
    $__head   = null;
    if (is_array($__clHead)) {
        foreach ($__clHead as $__e) {
            if (($__e['soort'] ?? 'functie') !== 'patch') { $__head = $__e; break; }
        }
        // Alleen patches (nog geen release)? Val terug op de allerbovenste.
        if ($__head === null && isset($__clHead[0])) $__head = $__clHead[0];
    }
    define('INLINECOMP_VERSIE',       $__head['versie'] ?? 'H?.??.??');
    define('INLINECOMP_VERSIE_DATUM', $__head['datum']  ?? '');
    unset($__clHead, $__head, $__e);
}
