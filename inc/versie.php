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
    // Head van de master-changelog = de huidige versie. Defensieve fallback
    // als changelog.php onverhoopt leeg/onbereikbaar is.
    $__clHead = @require __DIR__ . '/changelog.php';
    $__head   = (is_array($__clHead) && isset($__clHead[0])) ? $__clHead[0] : null;
    define('INLINECOMP_VERSIE',       $__head['versie'] ?? 'H?.??.??');
    define('INLINECOMP_VERSIE_DATUM', $__head['datum']  ?? '');
    unset($__clHead, $__head);
}
