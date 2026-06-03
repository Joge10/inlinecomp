<?php
// ============================================================
//  InlineComp – Wedstrijd-keuze helper voor records-rapport
//
//  Toont een lijst van wedstrijden (recent eerst). Per wedstrijd:
//  - auto-detectie of het een baan- of weg-wedstrijd is (via
//    distances.discipline veld dat 'Track' / 'Road' bevat)
//  - twee buttons "🏁 Baan-rapport" / "🛣 Weg-rapport"
//    → openen rapport_records.php met de juiste filter
//
//  Als baan/weg ondubbelzinnig is uit de data wordt die button
//  als primary getoond; de andere als secundair (voor handmatige
//  override door operator).
// ============================================================

require_once __DIR__ . '/../config_inlinecomp.php';
require_once __DIR__ . '/auth/session.php';
$_authUser = requireAuth($pdo);

// Lijst van wedstrijden met auto-detectie baan/weg via distance.discipline.
// Discipline-strings van de KNSB-feed: 'SpeedSkating.Inline.Track.…' (baan)
// of 'SpeedSkating.Inline.Road.…' (weg). Een wedstrijd kan in theorie
// beide hebben (mixed weekend), dan tonen we beide buttons gelijkwaardig.
// Alleen wedstrijden tonen waarvan minimaal één afstand is "vastgelegd"
// (rij in uitslag_afstand). Anders heeft de Eind-kolom in 't rapport altijd
// "—" en is de vergelijking snelste-vs-winnaar niet zinvol.
// Multi-tenant: scoped admin ziet alleen zijn org-wedstrijden.
$scope = gebruikerCompScopeWhere($pdo, $_authUser, 'c');
$stmt  = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.starts,
        c.location,
        MAX(CASE WHEN d.discipline LIKE '%Track%' THEN 1 ELSE 0 END) AS heeft_baan,
        MAX(CASE WHEN d.discipline LIKE '%Road%'  THEN 1 ELSE 0 END) AS heeft_weg,
        COUNT(DISTINCT h.id) AS aantal_heats,
        COUNT(DISTINCT res.id) AS aantal_results
    FROM competitions c
    INNER JOIN uitslag_afstand ua ON ua.competition_id = c.id
    LEFT JOIN heats h     ON h.competition_id = c.id
    LEFT JOIN distances d ON d.id = h.distance_id
                         AND d.distance_combination_id = h.distance_combination_id
    LEFT JOIN heat_entries he ON he.heat_id = h.id
    LEFT JOIN results res ON res.heat_entry_id = he.id
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
<title>Records-rapport — wedstrijd kiezen</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;
     margin:0;padding:0;background:#f4f7fa;color:#111;line-height:1.4}
.wrap{max-width:1100px;margin:0 auto;padding:1.5rem 2rem}
.kop{border-bottom:2px solid #1a3a5c;padding-bottom:.8rem;margin-bottom:1.2rem}
.kop h1{font-size:18pt;color:#1a3a5c;margin:0 0 .3rem 0}
.kop p{font-size:9pt;color:#555;margin:.2rem 0}
.zoek{margin-bottom:1rem}
.zoek input{width:100%;max-width:400px;padding:8px 12px;font-size:11pt;
            border:1px solid #b3cae6;border-radius:4px;background:#fff}
.opties{margin:0 0 .8rem 0;padding:.5rem .8rem;background:#fff;
        border:1px solid #d8e1eb;border-radius:6px}
.opties-label{font-size:8.5pt;color:#555;margin-right:.6rem;font-weight:600}
.modus-keuze{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;
             font-size:9pt;color:#1a3a5c}
.modus-keuze label{display:inline-flex;align-items:center;gap:5px;cursor:pointer}
.modus-keuze input[type=radio]{margin:0}
.modus-keuze b{font-weight:600}
.kaarten{display:flex;flex-direction:column;gap:8px}
.kaart{background:#fff;border:1px solid #d8e1eb;border-radius:6px;
       padding:12px 16px;display:flex;align-items:center;gap:1rem;
       transition:box-shadow .15s}
.kaart:hover{box-shadow:0 2px 6px rgba(26,58,92,.12)}
.kaart-info{flex:1;min-width:0}
.kaart-naam{font-size:11pt;font-weight:600;color:#1a3a5c}
.kaart-meta{font-size:8.5pt;color:#666;margin-top:2px}
.kaart-tags{display:flex;gap:6px;margin-top:4px;flex-wrap:wrap}
.tag{font-size:7.5pt;padding:1px 6px;border-radius:3px;
     background:#dce6f0;color:#1a3a5c}
.tag-info{background:#e8f0e6;color:#3a5a1f}
.kaart-acties{display:flex;gap:6px;flex-shrink:0}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;
     font-size:9pt;font-weight:600;text-decoration:none;border-radius:4px;
     border:1px solid transparent;cursor:pointer;white-space:nowrap}
.btn-primary{background:#1a3a5c;color:#fff;border-color:#1a3a5c}
.btn-primary:hover{background:#264e75}
.btn-secondary{background:#fff;color:#1a3a5c;border-color:#b3cae6}
.btn-secondary:hover{background:#f4f7fa;border-color:#1a3a5c}
.btn-disabled{background:#f0f0f0;color:#999;border-color:#ddd;
              cursor:not-allowed;pointer-events:none}
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
    <h1>📊 Records-rapport — wedstrijd kiezen</h1>
    <p>Selecteer een wedstrijd uit de lijst om een records-vergelijkingsrapport te genereren.</p>
  </div>

  <div class="toelichting">
    <b>Auto-detectie</b>: per wedstrijd wordt op basis van de gebruikte afstanden
    (track / road in <code>distances.discipline</code>) bepaald of het een baan- of
    weg-wedstrijd is. De juiste button is als primary gemarkeerd; je kunt altijd
    handmatig de andere kiezen als de data onduidelijk is. Wedstrijden zonder
    resultaten worden niet getoond.
  </div>

  <div class="opties">
    <div class="modus-keuze">
        <span class="opties-label">Detail-niveau:</span>
        <label><input type="radio" name="modus" value="top1" checked>
            <b>Compact</b> — snelste rijder per categorie</label>
        <label><input type="radio" name="modus" value="alle">
            <b>Uitgebreid</b> — alle rijders per categorie, op tijd gesorteerd</label>
    </div>
  </div>

  <div class="zoek">
    <input type="search" id="zoek" placeholder="🔍 Filter op wedstrijd-naam of locatie…"
           autocomplete="off">
  </div>

  <div class="kaarten" id="kaarten">

<?php if (empty($wedstrijden)): ?>
    <div class="leeg">Geen wedstrijden met resultaten gevonden in de database.</div>
<?php else: ?>
    <?php foreach ($wedstrijden as $w):
        $heeftBaan = (int)$w['heeft_baan'] === 1;
        $heeftWeg  = (int)$w['heeft_weg']  === 1;
        // Bepaal welke "primary" button is. Bij ondubbelzinnige data: die.
        // Bij mixed (beide aanwezig): beide secundair tonen. Bij niets (onbekend):
        // beide tonen, geen primary — operator beslist zelf.
        $primaryBaan = $heeftBaan && !$heeftWeg;
        $primaryWeg  = $heeftWeg  && !$heeftBaan;

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
            <div class="kaart-tags">
                <?php if ($heeftBaan): ?><span class="tag tag-info">🏁 baan-disciplines aanwezig</span><?php endif; ?>
                <?php if ($heeftWeg):  ?><span class="tag tag-info">🛣 weg-disciplines aanwezig</span><?php endif; ?>
                <?php if (!$heeftBaan && !$heeftWeg): ?><span class="tag">type onbekend</span><?php endif; ?>
            </div>
        </div>
        <div class="kaart-acties">
            <a class="btn rapport-link <?= $primaryBaan ? 'btn-primary' : 'btn-secondary' ?>"
               data-base="rapport_records.php?competition_id=<?= urlencode($w['id']) ?>&type=baan"
               href="rapport_records.php?competition_id=<?= urlencode($w['id']) ?>&type=baan&modus=top1"
               target="_blank" rel="noopener">
                🏁 Baan
            </a>
            <a class="btn rapport-link <?= $primaryWeg ? 'btn-primary' : 'btn-secondary' ?>"
               data-base="rapport_records.php?competition_id=<?= urlencode($w['id']) ?>&type=weg"
               href="rapport_records.php?competition_id=<?= urlencode($w['id']) ?>&type=weg&modus=top1"
               target="_blank" rel="noopener">
                🛣 Weg
            </a>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

  </div>

  <div class="footer">
    Tip: kies eerst hierboven het detail-niveau, daarna een wedstrijd. Het rapport opent
    in een nieuw tabblad — daar kun je met Ctrl+P opslaan als PDF.
  </div>

</div>

<script>
// Live filter op de kaarten — typ in 't zoekveld, kaarten zonder match
// worden verborgen. Maakt scrollen door 100 wedstrijden hanteerbaar.
document.getElementById('zoek').addEventListener('input', e => {
    const q = e.target.value.toLowerCase().trim();
    document.querySelectorAll('.kaart').forEach(k => {
        k.style.display = (!q || k.dataset.zoek.includes(q)) ? '' : 'none';
    });
});

// Detail-niveau radio's: pas alle rapport-link href's aan met de gekozen
// modus. Server-side default is 'top1', maar JS overschrijft direct zodat
// klikken op een knop meteen de actuele keuze gebruikt.
function _updateRapportLinks() {
    const modus = document.querySelector('input[name=modus]:checked')?.value || 'top1';
    document.querySelectorAll('a.rapport-link').forEach(a => {
        const base = a.dataset.base || '';
        a.href = base + '&modus=' + modus;
    });
}
document.querySelectorAll('input[name=modus]').forEach(r =>
    r.addEventListener('change', _updateRapportLinks)
);
_updateRapportLinks();   // init
</script>

</body>
</html>
