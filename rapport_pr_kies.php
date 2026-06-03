<?php
// ============================================================
//  InlineComp – Wedstrijd-keuze helper voor PR-check rapport
//
//  PR (Personal Record) wordt geput uit uitslag_afstand
//  exclusief de gekozen wedstrijd. Daarom alleen wedstrijden
//  tonen waar resultaten in zitten, en niet noodzakelijk
//  vastgelegd (de huidige wedstrijd hoeft niet bevestigd te
//  zijn — die levert wel de te-vergelijken tijden).
// ============================================================

require_once __DIR__ . '/../config_inlinecomp.php';
require_once __DIR__ . '/auth/session.php';
$_authUser = requireAuth($pdo);

// Multi-tenant: scoped admin ziet alleen eigen org-wedstrijden.
$scope = gebruikerCompScopeWhere($pdo, $_authUser, 'c');
$stmt  = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.starts,
        c.location,
        COUNT(DISTINCT h.id)   AS aantal_heats,
        COUNT(DISTINCT res.id) AS aantal_results
    FROM competitions c
    JOIN heats h         ON h.competition_id = c.id
    JOIN heat_entries he ON he.heat_id = h.id
    JOIN results res     ON res.heat_entry_id = he.id
    WHERE 1=1 " . $scope['where'] . "
    GROUP BY c.id, c.name, c.starts, c.location
    HAVING aantal_results > 0
    ORDER BY c.starts DESC
    LIMIT 100
");
$stmt->execute($scope['params']);
$wedstrijden = $stmt->fetchAll(PDO::FETCH_ASSOC);

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function fmtDatum(?string $iso): string {
    if (!$iso) return '—';
    $t = strtotime($iso);
    return $t ? date('j M Y', $t) : esc($iso);
}
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title>PR-check rapport — wedstrijd kiezen</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;
     margin:0;padding:0;background:#f4f7fa;color:#111;line-height:1.4}
.wrap{max-width:1100px;margin:0 auto;padding:1.5rem 2rem}
.kop{border-bottom:2px solid #1a3a5c;padding-bottom:.8rem;margin-bottom:1.2rem}
.kop h1{font-size:18pt;color:#1a3a5c;margin:0 0 .3rem 0}
.kop p{font-size:9pt;color:#555;margin:.2rem 0}
.opties{margin:0 0 .8rem 0;padding:.5rem .8rem;background:#fff;
        border:1px solid #d8e1eb;border-radius:6px}
.opties-label{font-size:8.5pt;color:#555;margin-right:.6rem;font-weight:600}
.modus-keuze{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;
             font-size:9pt;color:#1a3a5c}
.modus-keuze label{display:inline-flex;align-items:center;gap:5px;cursor:pointer}
.modus-keuze input[type=radio]{margin:0}
.zoek{margin-bottom:1rem}
.zoek input{width:100%;max-width:400px;padding:8px 12px;font-size:11pt;
            border:1px solid #b3cae6;border-radius:4px;background:#fff}
.kaarten{display:flex;flex-direction:column;gap:8px}
.kaart{background:#fff;border:1px solid #d8e1eb;border-radius:6px;
       padding:12px 16px;display:flex;align-items:center;gap:1rem}
.kaart:hover{box-shadow:0 2px 6px rgba(26,58,92,.12)}
.kaart-info{flex:1;min-width:0}
.kaart-naam{font-size:11pt;font-weight:600;color:#1a3a5c}
.kaart-meta{font-size:8.5pt;color:#666;margin-top:2px}
.kaart-acties{display:flex;gap:6px;flex-shrink:0}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;
     font-size:9pt;font-weight:600;text-decoration:none;border-radius:4px;
     border:1px solid transparent;cursor:pointer;white-space:nowrap}
.btn-primary{background:#1a3a5c;color:#fff;border-color:#1a3a5c}
.btn-primary:hover{background:#264e75}
.leeg{text-align:center;padding:2rem;color:#888;font-style:italic;
      background:#fff;border:1px dashed #ccc;border-radius:6px}
.toelichting{background:#fff;border-left:3px solid #1a3a5c;
             padding:.6rem .9rem;margin-bottom:1rem;font-size:9pt;color:#444}
.toelichting b{color:#1a3a5c}
.footer{margin-top:2rem;font-size:7.5pt;color:#888;text-align:right}
</style>
</head>
<body>
<div class="wrap">

  <div class="kop">
    <h1>🏃 PR-check rapport — wedstrijd kiezen</h1>
    <p>Selecteer een wedstrijd om rijders te vergelijken met hun persoonlijk record (PR).</p>
  </div>

  <div class="toelichting">
    <b>PR-bron</b>: alleen tijden uit <b>vastgelegde uitslagen</b> (eerdere wedstrijden
    waar "Uitslag bevestigen" is gedaan). De huidige wedstrijd zelf hoeft niet
    vastgelegd te zijn — de tijden komen uit de live-resultaten. Rijders zonder
    eerdere PR krijgen "geen historie" in de rapportage.
  </div>

  <div class="opties">
    <div class="modus-keuze">
        <span class="opties-label">Detail-niveau:</span>
        <label><input type="radio" name="modus" value="top1" checked>
            <b>Compact</b> — snelste rijder per (afstand × cat)</label>
        <label><input type="radio" name="modus" value="alle">
            <b>Uitgebreid</b> — alle rijders per (afstand × cat)</label>
    </div>
  </div>

  <div class="zoek">
    <input type="search" id="zoek" placeholder="🔍 Filter op wedstrijd-naam of locatie…"
           autocomplete="off">
  </div>

  <div class="kaarten" id="kaarten">

<?php if (empty($wedstrijden)): ?>
    <div class="leeg">Geen wedstrijden met resultaten gevonden in de database.</div>
<?php else: foreach ($wedstrijden as $w):
        $zoekString = strtolower($w['name'] . ' ' . ($w['location'] ?? ''));
?>
    <div class="kaart" data-zoek="<?= esc($zoekString) ?>">
        <div class="kaart-info">
            <div class="kaart-naam"><?= esc($w['name']) ?></div>
            <div class="kaart-meta">
                <?= esc(fmtDatum($w['starts'])) ?>
                <?php if (!empty($w['location'])): ?>
                    · <?= esc($w['location']) ?>
                <?php endif; ?>
                · <?= (int)$w['aantal_heats'] ?> heats, <?= (int)$w['aantal_results'] ?> resultaten
            </div>
        </div>
        <div class="kaart-acties">
            <a class="btn btn-primary pr-link"
               data-base="rapport_pr.php?competition_id=<?= urlencode($w['id']) ?>"
               href="rapport_pr.php?competition_id=<?= urlencode($w['id']) ?>&modus=top1"
               target="_blank" rel="noopener">
                🏃 PR-check
            </a>
        </div>
    </div>
<?php endforeach; endif; ?>

  </div>

  <div class="footer">
    Tip: kies eerst hierboven het detail-niveau, daarna een wedstrijd.
    Het rapport opent in een nieuw tabblad — daar kun je met Ctrl+P opslaan als PDF.
  </div>

</div>

<script>
document.getElementById('zoek').addEventListener('input', e => {
    const q = e.target.value.toLowerCase().trim();
    document.querySelectorAll('.kaart').forEach(k => {
        k.style.display = (!q || k.dataset.zoek.includes(q)) ? '' : 'none';
    });
});

function _updatePrLinks() {
    const modus = document.querySelector('input[name=modus]:checked')?.value || 'top1';
    document.querySelectorAll('a.pr-link').forEach(a => {
        a.href = (a.dataset.base || '') + '&modus=' + modus;
    });
}
document.querySelectorAll('input[name=modus]').forEach(r =>
    r.addEventListener('change', _updatePrLinks));
_updatePrLinks();
</script>

</body>
</html>
